<?php
/**
 * The section's checkout, cut down to what a digital run needs (client findings
 * #17/#21/#29; ADR 0036).
 *
 * Lewis, 2 October: a run is a digital entry, so the checkout asks for as little as it can.
 *
 *  - BILLING: first name, last name, email and, when a setting asks for it, country (else
 *    the United Kingdom). No street, town, postcode, phone, company or order notes (the date
 *    of birth is already taken at registration).
 *  - COUNTRY: United Kingdom and Ireland only.
 *  - CONSENT: the one tick on Before you pay (age, terms and rules), nothing more at
 *    checkout. Since that is now the only record of the player's agreement, it is also
 *    CHECKED here, on the server, instead of trusting the browser: a checkout reached without the tick goes
 *    back to Before you pay, and a submitted one is refused.
 *  - PAYMENT METHODS: Wallet only, until a provider is agreed. Which gateways show is a
 *    setting (Strike And Win -> Settings -> Checkout), so adding one later is ticking a box.
 *    The card gateway reads "Card" when it returns.
 *  - SPLIT PAYMENT: "Part wallet, part card" is its own value, `nera_wallet_split`, not the
 *    card gateway's `cashflows_card`, so the server can tell the two apart. It is mapped
 *    back to the card gateway for WooCommerce, and the order records that it was a split.
 *
 * Everything here applies to the section's own checkout (a basket of competition
 * entries), never to a wallet top-up or the admin.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Checkout
 */
class Nera_SAW_Checkout {

	/**
	 * Settings keys.
	 */
	const SETTING_GATEWAYS  = 'checkout_gateways';
	const SETTING_COUPON    = 'checkout_show_coupon';
	const SETTING_COUNTRY   = 'checkout_show_country';
	const SETTING_SHOW_LAST = 'checkout_show_last_name';
	const SETTING_LAST      = 'checkout_last_name_required';
	const SETTING_FACTS     = 'checkout_show_summary_facts';

	/** The billing country when the checkout does not ask for one. */
	const DEFAULT_COUNTRY = 'GB';

	/**
	 * The split-payment pseudo-gateway: its radio's value, and the settings key that shows it.
	 */
	const SPLIT = 'nera_wallet_split';

	/**
	 * The card gateway behind the split, and the one that returns by ticking its box.
	 */
	const CARD = 'cashflows_card';

	/**
	 * How Pay in the sheet takes a method: posted from the sheet, or on the checkout page.
	 */
	const MODE_POST     = 'post';
	const MODE_CHECKOUT = 'checkout';

	/** Query arg the sheet sends to the checkout page: the method chosen there. */
	const ARG_METHOD = 'payment_method';

	/**
	 * Session key holding the consent given on Before you pay.
	 */
	const CONSENT_KEY = 'saw_checkout_consent';

	/**
	 * Query args Before you pay's form sends to the checkout.
	 */
	const ARG_AGE   = 'saw_confirm_age';
	/** The former second tick. No longer asked for; still stripped from old links. */
	const ARG_RULES = 'saw_confirm_rules';

	/** Present on every submission of the Before you pay form; tells a form post from a direct visit. */
	const ARG_CHECKED = 'saw_consent_check';

	/** Added to the address Before you pay is sent back to when a box was left unticked. */
	const ARG_MISSING = 'saw_consent_missing';

	/** Posted with the pay sheet's tick: the basket the sheet was drawn for. */
	const ARG_HASH = 'saw_basket_hash';

	/**
	 * Whether this request is a split payment (set while the posted data is read).
	 *
	 * @var bool
	 */
	private static $split_posted = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! Nera_SAW_Mode::is_standalone() ) {
			return;
		}

		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_fields' ), 99 );
		add_filter( 'woocommerce_enable_order_notes_field', array( __CLASS__, 'order_notes_off' ), 99 );
		add_filter( 'woocommerce_countries_allowed_countries', array( __CLASS__, 'countries' ), 99 );
		add_filter( 'woocommerce_countries_shipping_countries', array( __CLASS__, 'countries' ), 99 );
		add_filter( 'woocommerce_checkout_show_terms', array( __CLASS__, 'hide_terms_tick' ), 99 );

		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'gateways' ), 99 );
		add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'card_title' ), 99, 2 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'map_split_payment' ), 5 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'default_country' ), 10 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'record_split' ), 10, 1 );

		// PayPal Payments loads its SDK and renders button containers on the checkout whether or
		// not its gateway is offered; with it off here, none of that should reach the page.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'strip_provider_assets' ), 9999 );
		add_action( 'wp_print_scripts', array( __CLASS__, 'strip_provider_assets' ), 1 );
		add_action( 'wp_print_styles', array( __CLASS__, 'strip_provider_assets' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'hide_provider_containers' ), 99 );

		add_action( 'template_redirect', array( __CLASS__, 'remove_coupon_prompt' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'require_consent' ), 15 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_consent' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'forget_consent' ), 10, 0 );
	}

	/**
	 * Is this the section's checkout for a basket of entries (the page, or the AJAX that
	 * rebuilds it)?
	 *
	 * @return bool
	 */
	private static function is_entry_checkout() {
		return ! is_admin() && class_exists( 'Nera_SAW_Standalone_Basket' ) && Nera_SAW_Standalone_Basket::is_entry_context();
	}

	/**
	 * A wallet top-up is paid for through the ordinary gateways and is none of this.
	 *
	 * @return bool
	 */
	private static function is_topup_cart() {
		return function_exists( 'is_wallet_rechargeable_cart' ) && is_wallet_rechargeable_cart();
	}

	/* ---------------------------------------------------------------------
	 * Billing form and countries
	 * ------------------------------------------------------------------ */

	/**
	 * The competition of the basket's entry, for the per-competition checkout switches.
	 *
	 * @return int 0 when the basket holds none.
	 */
	private static function entry_competition_id() {
		$entry = Nera_SAW_Standalone_Basket::current_entry();
		return ( $entry && ! empty( $entry['spec']['id'] ) ) ? (int) $entry['spec']['id'] : 0;
	}

	/**
	 * Does the checkout ask for the country? Off: no field, the billing country is DEFAULT_COUNTRY.
	 *
	 * @param int $competition_id Competition product ID (0 = global setting).
	 * @return bool
	 */
	public static function show_country( $competition_id = 0 ) {
		return self::switch_setting( self::SETTING_COUNTRY, 'nera_saw_checkout_show_country', $competition_id );
	}

	/**
	 * Does the checkout have a last name field? Off: no field, the order's billing last name is empty.
	 *
	 * @param int $competition_id Competition product ID (0 = global setting).
	 * @return bool
	 */
	public static function show_last_name( $competition_id = 0 ) {
		return self::switch_setting( self::SETTING_SHOW_LAST, 'nera_saw_checkout_show_last_name', $competition_id );
	}

	/**
	 * Is the last name a required field? Ignored while show_last_name() is off.
	 *
	 * @param int $competition_id Competition product ID (0 = global setting).
	 * @return bool
	 */
	public static function last_name_required( $competition_id = 0 ) {
		return self::switch_setting( self::SETTING_LAST, 'nera_saw_checkout_last_name_required', $competition_id );
	}

	/**
	 * Does the pay sheet summary show its facts list (questions, timer, most this run, closes)?
	 *
	 * @param int $competition_id Competition product ID (0 = global setting).
	 * @return bool
	 */
	public static function show_summary_facts( $competition_id = 0 ) {
		return self::switch_setting( self::SETTING_FACTS, 'nera_saw_checkout_show_summary_facts', $competition_id );
	}

	/**
	 * An on/off setting: the global value, then the competition's override unless it inherits.
	 *
	 * @param string $key            Settings key, also the competition config key.
	 * @param string $filter         Filter name; args value, competition id, global value.
	 * @param int    $competition_id Competition product ID (0 = global setting).
	 * @return bool
	 */
	private static function switch_setting( $key, $filter, $competition_id ) {
		$s      = Nera_SAW_Constants::settings();
		$global = ! empty( $s[ $key ] );
		$value  = $global;

		$competition_id = (int) $competition_id;
		if ( $competition_id > 0 ) {
			$config = Nera_SAW_Competition_Config::get( $competition_id );
			$own    = Nera_SAW_Competition_Config::sanitize_switch_override( isset( $config[ $key ] ) ? $config[ $key ] : '' );
			if ( '' !== $own ) {
				$value = '1' === $own;
			}
		}

		return (bool) apply_filters( $filter, $value, $competition_id, $global );
	}

	/**
	 * First name, email and, when switched on, last name and country. Nothing else.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public static function checkout_fields( $fields ) {
		if ( ! self::is_entry_checkout() || self::is_topup_cart() || empty( $fields['billing'] ) ) {
			return $fields;
		}

		$competition_id = self::entry_competition_id();

		$keep = array(
			'billing_first_name' => array( 10, 'form-row-first' ),
			'billing_last_name'  => array( 20, 'form-row-last' ),
			'billing_email'      => array( 30, 'form-row-wide' ),
			'billing_country'    => array( 40, 'form-row-wide' ),
		);

		foreach ( array_keys( $fields['billing'] ) as $key ) {
			if ( ! isset( $keep[ $key ] ) ) {
				unset( $fields['billing'][ $key ] );
			}
		}
		foreach ( $keep as $key => $layout ) {
			if ( ! isset( $fields['billing'][ $key ] ) ) {
				continue;
			}
			$fields['billing'][ $key ]['priority'] = $layout[0];
			$fields['billing'][ $key ]['required'] = 'billing_last_name' === $key ? self::last_name_required( $competition_id ) : true;
			// The theme's half/full-width helper classes are replaced, not added to.
			$fields['billing'][ $key ]['class'] = array( $layout[1] );
		}

		if ( ! self::show_country( $competition_id ) ) {
			unset( $fields['billing']['billing_country'] );
		}
		// Undeclared, WooCommerce neither reads a posted last name nor writes it to the customer.
		if ( ! self::show_last_name( $competition_id ) ) {
			unset( $fields['billing']['billing_last_name'] );
		}

		unset( $fields['shipping'], $fields['order'] );

		return $fields;
	}

	/**
	 * With no country field, the order and the customer are billed to DEFAULT_COUNTRY.
	 *
	 * @param array $data Posted checkout data.
	 * @return array
	 */
	public static function default_country( $data ) {
		if ( self::is_entry_checkout() && ! self::is_topup_cart() && ! self::show_country( self::entry_competition_id() ) ) {
			$data['billing_country'] = self::DEFAULT_COUNTRY;
		}

		return $data;
	}

	/**
	 * No order notes.
	 *
	 * @param bool $show Whether to show them.
	 * @return bool
	 */
	public static function order_notes_off( $show ) {
		return ( self::is_entry_checkout() && ! self::is_topup_cart() ) ? false : $show;
	}

	/**
	 * United Kingdom and Ireland.
	 *
	 * WooCommerce checks a posted country against this list too, so a country that is not
	 * on it is refused by the server, not just left out of the dropdown.
	 *
	 * @param array $countries Code => name.
	 * @return array
	 */
	public static function countries( $countries ) {
		if ( is_admin() ) {
			return $countries;
		}

		$countries = array_intersect_key( (array) $countries, array_flip( array( 'GB', 'IE' ) ) );

		// WooCommerce builds its country names once, before the section's Russian
		// gettext map applies, so run the two names through it again here.
		if ( class_exists( 'Nera_SAW_Language' ) && 'ru' === Nera_SAW_Language::current() ) {
			foreach ( $countries as $code => $name ) {
				$countries[ $code ] = translate( $name, 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n
			}
		}

		return $countries;
	}

	/* ---------------------------------------------------------------------
	 * Consent
	 * ------------------------------------------------------------------ */

	/**
	 * No second tick at checkout: the one on Before you pay is the consent.
	 *
	 * @param bool $show Whether to show the terms tick.
	 * @return bool
	 */
	public static function hide_terms_tick( $show ) {
		return ( self::is_entry_checkout() && ! self::is_topup_cart() ) ? false : $show;
	}

	/**
	 * The coupon prompt ("Have a coupon? Click here") is off by default, with a setting
	 * to bring it back. It is also how a coupon prize from an offline draw is redeemed.
	 */
	public static function remove_coupon_prompt() {
		if ( ! self::coupon_prompt_enabled() ) {
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
		}
	}

	/**
	 * @return bool
	 */
	public static function coupon_prompt_enabled() {
		$s = Nera_SAW_Constants::settings();
		return ! empty( $s[ self::SETTING_COUPON ] );
	}

	/**
	 * The privacy sentence WooCommerce prints with the terms tick. With the tick gone it is
	 * still said, as plain text.
	 */
	public static function privacy_notice() {
		if ( ! self::is_entry_checkout() || self::is_topup_cart() || ! function_exists( 'wc_checkout_privacy_policy_text' ) ) {
			return;
		}

		echo '<div class="woocommerce-terms-and-conditions-wrapper">';
		wc_checkout_privacy_policy_text();
		echo '</div>';
	}

	/**
	 * A fingerprint of what is being bought, so consent given for one basket is not
	 * taken for another.
	 *
	 * @return string
	 */
	public static function basket_hash() {
		return ( function_exists( 'WC' ) && WC()->cart ) ? (string) WC()->cart->get_cart_hash() : '';
	}

	/**
	 * Has this basket been agreed to on Before you pay?
	 *
	 * @return bool
	 */
	public static function has_consent() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		$consent = WC()->session->get( self::CONSENT_KEY );
		return is_array( $consent ) && ! empty( $consent['hash'] ) && hash_equals( (string) $consent['hash'], self::basket_hash() );
	}

	/**
	 * The checkout page itself: take the tick from the Before you pay form and
	 * remember them against this basket, or send a visitor who has not given them back.
	 */
	public static function require_consent() {
		if ( is_admin() || ! Nera_SAW_Standalone_Basket::is_section_checkout() || self::is_topup_cart() ) {
			return;
		}
		// The confirmation and pay-for-order screens are after the fact.
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() || null === Nera_SAW_Standalone_Basket::current_entry() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the Before you pay form is a GET; the ticks are evidence, not an action.
		$ticked = isset( $_GET[ self::ARG_AGE ] ) && '1' === (string) $_GET[ self::ARG_AGE ];
		// phpcs:enable

		if ( $ticked ) {
			if ( WC()->session ) {
				WC()->session->set( self::CONSENT_KEY, array( 'hash' => self::basket_hash(), 'at' => time() ) );

				// The method chosen in the pay sheet is the one this page opens with.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$chosen = isset( $_GET[ self::ARG_METHOD ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG_METHOD ] ) ) : '';
				if ( '' !== $chosen && in_array( $chosen, self::enabled_gateways(), true ) ) {
					WC()->session->set( 'chosen_payment_method', $chosen );
				}
			}
			// Back to a clean address: the tick need not sit in the URL.
			wp_safe_redirect( remove_query_arg( array( self::ARG_AGE, self::ARG_RULES, self::ARG_CHECKED, self::ARG_METHOD ) ) );
			exit;
		}

		// The form was submitted without the tick: whatever was agreed on an earlier visit
		// does not carry over.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::ARG_CHECKED ] ) && WC()->session ) {
			WC()->session->set( self::CONSENT_KEY, null );
		}

		if ( ! self::has_consent() ) {
			$to = Nera_SAW_Standalone_Basket::pre_payment_url();
			if ( $to ) {
				// A form submitted with a box unticked says why; a direct visit does not.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( isset( $_GET[ self::ARG_CHECKED ] ) ) {
					$to = add_query_arg( self::ARG_MISSING, '1', $to );
				}
				wp_safe_redirect( $to );
				exit;
			}
		}
	}

	/**
	 * The consent was for this purchase: once the order exists it is spent, so the next
	 * purchase asks again even if the basket happens to be identical.
	 */
	public static function forget_consent() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::CONSENT_KEY, null );
		}
	}

	/**
	 * A checkout submitted without the consent is refused, whatever the page looked like.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Validation errors.
	 */
	public static function validate_consent( $data, $errors ) {
		unset( $data );
		if ( ! self::is_entry_checkout() || self::is_topup_cart() || null === Nera_SAW_Standalone_Basket::current_entry() ) {
			return;
		}
		if ( ! self::has_consent() && ! self::posted_consent() ) {
			$errors->add( 'saw_consent', __( 'Please confirm your age and the rules on the previous screen before you pay.', 'nera-strikeawin' ) );
		}
	}

	/**
	 * The tick posted from the pay sheet, for the basket the sheet showed. A basket
	 * changed since then (another tab, another add) is not the one agreed to.
	 *
	 * WooCommerce has checked the checkout nonce before validation runs.
	 *
	 * @return bool
	 */
	private static function posted_consent() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$ticked = isset( $_POST[ self::ARG_AGE ] ) && '1' === (string) wp_unslash( $_POST[ self::ARG_AGE ] );
		$hash   = isset( $_POST[ self::ARG_HASH ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::ARG_HASH ] ) ) : '';
		// phpcs:enable
		$now = self::basket_hash();

		return $ticked && '' !== $hash && '' !== $now && hash_equals( $now, $hash );
	}

	/* ---------------------------------------------------------------------
	 * Payment methods
	 * ------------------------------------------------------------------ */

	/**
	 * Gateway ids (plus the split pseudo-id) switched on at checkout. Wallet only
	 * until a provider is agreed.
	 *
	 * @return string[]
	 */
	public static function enabled_gateways() {
		$s   = Nera_SAW_Constants::settings();
		$ids = isset( $s[ self::SETTING_GATEWAYS ] ) && is_array( $s[ self::SETTING_GATEWAYS ] ) ? array_filter( array_map( 'sanitize_key', $s[ self::SETTING_GATEWAYS ] ) ) : array( 'wallet' );

		return $ids ? array_values( $ids ) : array( 'wallet' );
	}

	/**
	 * Is "Part wallet, part card" switched on? It needs the card gateway as well.
	 *
	 * @return bool
	 */
	public static function split_enabled() {
		$ids = self::enabled_gateways();
		return in_array( self::SPLIT, $ids, true ) && in_array( self::CARD, $ids, true );
	}

	/**
	 * How Pay in the sheet takes this method: `post` (the sheet posts the order and follows
	 * WooCommerce's redirect) or `checkout` (the player goes on to the checkout page with it).
	 *
	 * A gateway whose payment fields hold form controls needs them filled on the page; any
	 * other is posted. The split's wallet share is chosen on the checkout page, and an id
	 * WooCommerce does not know cannot be posted.
	 *
	 * @param string $gateway_id Gateway id, or the split pseudo-id.
	 * @return string self::MODE_POST or self::MODE_CHECKOUT.
	 */
	public static function pay_sheet_mode( $gateway_id ) {
		$gateway_id = sanitize_key( $gateway_id );
		$gateways   = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway    = isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ] : null;

		if ( self::SPLIT === $gateway_id || ! $gateway ) {
			$mode = self::MODE_CHECKOUT;
		} else {
			$mode = self::asks_for_fields( $gateway ) ? self::MODE_CHECKOUT : self::MODE_POST;
		}

		/**
		 * Filters how the pay sheet takes a payment method.
		 *
		 * @param string                  $mode       'post' or 'checkout'.
		 * @param string                  $gateway_id Gateway id.
		 * @param WC_Payment_Gateway|null $gateway    The gateway; null for the split.
		 */
		$mode = apply_filters( 'nera_saw_pay_sheet_method_mode', $mode, $gateway_id, $gateway );

		return self::MODE_CHECKOUT === $mode ? self::MODE_CHECKOUT : self::MODE_POST;
	}

	/**
	 * Does the gateway print form controls of its own (card number, bank pick, …)?
	 * `has_fields` is no guide: some gateways set it and print only a description.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway.
	 * @return bool
	 */
	private static function asks_for_fields( $gateway ) {
		ob_start();
		try {
			$gateway->payment_fields();
		} catch ( Throwable $e ) {
			ob_end_clean();
			return true;
		}
		$html = (string) ob_get_clean();

		return (bool) preg_match( '/<(input|select|textarea)\b/i', $html );
	}

	/**
	 * Only the ticked gateways, on the section's own checkout.
	 *
	 * Deliberately does NOT ask WooCommerce for the available gateways again: the theme's
	 * wallet code is wired to that call and re-enters itself without bound.
	 *
	 * @param WC_Payment_Gateway[] $gateways Available gateways.
	 * @return WC_Payment_Gateway[]
	 */
	public static function gateways( $gateways ) {
		if ( ! self::is_entry_checkout() || self::is_topup_cart() ) {
			return $gateways;
		}

		return array_intersect_key( (array) $gateways, array_flip( self::enabled_gateways() ) );
	}

	/**
	 * Is any PayPal Payments gateway (ids start `ppcp`) switched on at checkout?
	 *
	 * @return bool
	 */
	private static function paypal_offered() {
		foreach ( self::enabled_gateways() as $id ) {
			if ( 0 === strpos( $id, 'ppcp' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Take PayPal Payments' scripts and styles (its SDK from paypal.com included) off the
	 * section's checkout while none of its gateways is offered.
	 */
	public static function strip_provider_assets() {
		if ( ! self::is_entry_checkout() || self::is_topup_cart() || self::paypal_offered() ) {
			return;
		}

		foreach ( array( wp_scripts(), wp_styles() ) as $registry ) {
			foreach ( (array) $registry->registered as $handle => $dep ) {
				$src = isset( $dep->src ) ? (string) $dep->src : '';
				if ( false !== strpos( $src, '/woocommerce-paypal-payments/' ) || false !== strpos( $src, 'paypal.com/sdk' ) || 0 === strpos( (string) $handle, 'ppcp' ) ) {
					$registry->dequeue( $handle );
				}
			}
		}
	}

	/**
	 * The containers PayPal Payments prints for its buttons and "Pay later" messaging are
	 * empty without the SDK, but they still take up space.
	 */
	public static function hide_provider_containers() {
		if ( self::is_entry_checkout() && ! self::is_topup_cart() && ! self::paypal_offered() ) {
			echo '<style>.ppcp-messages,[id^="ppc-button-"],[class*="ppcp-button"]{display:none!important}</style>' . "\n";
		}
	}

	/**
	 * The card gateway says what it is.
	 *
	 * @param string $title      Title so far.
	 * @param string $gateway_id Gateway ID.
	 * @return string
	 */
	public static function card_title( $title, $gateway_id ) {
		if ( self::CARD !== $gateway_id || is_admin() ) {
			return $title;
		}

		return __( 'Card', 'nera-strikeawin' );
	}

	/**
	 * Print one of the theme's split-payment parts, or nothing while it is off. The option
	 * row's radio is given its own value so a posted split can be told from a plain card.
	 *
	 * @param string $slot 'option' (the radio row) or 'notice'.
	 */
	public static function split_part( $slot ) {
		// A top-up (or anything that is not the section's own checkout) keeps the theme's part as it is.
		if ( ! self::is_entry_checkout() || self::is_topup_cart() ) {
			Nera_SAW_Standalone_Chrome::theme_part( 'template-parts/checkout/wallet-partial-payment', array( 'slot' => $slot ) );
			return;
		}
		if ( ! self::split_enabled() ) {
			return;
		}

		ob_start();
		Nera_SAW_Standalone_Chrome::theme_part( 'template-parts/checkout/wallet-partial-payment', array( 'slot' => $slot ) );
		$html = (string) ob_get_clean();

		if ( 'option' === $slot ) {
			$html = (string) preg_replace(
				'/(<input\b[^>]*name="payment_method"[^>]*?\svalue=")[^"]*("[^>]*data-nera-wallet-radio)/s',
				'$1' . self::SPLIT . '$2',
				$html
			);

			// Chosen in the pay sheet: the split opens selected. It is printed after the gateways,
			// so its tick wins over the one WooCommerce gives the first of them.
			if ( WC()->session && self::SPLIT === WC()->session->get( 'chosen_payment_method' ) ) {
				$html = (string) preg_replace( '/(<input\b[^>]*data-nera-wallet-radio)\b(?![^>]*\schecked)/s', '$1 checked', $html, 1 );
			}
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the theme's own markup, with one attribute value replaced.
	}

	/**
	 * A posted `nera_wallet_split` is the card gateway as far as WooCommerce goes; what
	 * made it a split is remembered for the order.
	 *
	 * @param array $data Posted checkout data.
	 * @return array
	 */
	public static function map_split_payment( $data ) {
		if ( isset( $data['payment_method'] ) && self::SPLIT === $data['payment_method'] ) {
			self::$split_posted       = true;
			$data['payment_method']   = self::CARD;
			$_POST['payment_method']  = self::CARD; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$_REQUEST['payment_method'] = self::CARD;
		}

		return $data;
	}

	/**
	 * The order says it was a split, which the gateway id alone no longer does.
	 *
	 * @param WC_Order $order Order being created.
	 */
	public static function record_split( $order ) {
		if ( self::$split_posted && $order instanceof WC_Order ) {
			$order->update_meta_data( '_nera_split_payment', 'yes' );
			$order->set_payment_method_title( __( 'Part wallet, part card', 'nera-strikeawin' ) );
		}
	}
}
