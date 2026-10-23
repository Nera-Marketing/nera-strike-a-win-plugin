<?php
/**
 * The wallet, as far as Strike And Win has a say in it (client findings #43/#44;
 * ADR 0035).
 *
 * The wallet stays (Lewis, 2 October). Three things about it were not right for a
 * prize-competition site, and all three live here, in the one place that knows the
 * wallet, rather than being patched into the third-party plugin or the theme:
 *
 *  1. TOP-UP LIMITS. £100 per top-up and £250 per day, both settings
 *     (Strike And Win -> Settings -> Wallet top-up limits), 0 meaning no limit. Checked
 *     on every way a top-up can start: woo-wallet's form, the cart and checkout it
 *     leads to, and its REST route. Admin credits are not top-ups and are not
 *     touched. A day is the calendar day in the site's timezone, and counts top-ups
 *     that have been PAID.
 *  2. THE PAYMENT METHOD'S NAME. "Wallet payment ✓ Sufficient Balance" is the gateway's
 *     title with a balance badge the theme appends; it reached customers on orders and
 *     in emails. It is now "Wallet", everywhere, for orders already placed as well as
 *     new ones.
 *  3. THE "TEMPORARY PASSWORD" NOTICE. WooCommerce sets a flag when it makes an
 *     account with a generated password and only clears it on its own lost-password
 *     form, so it stayed for players who had changed their password some other way.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Wallet
 */
class Nera_SAW_Wallet {

	/**
	 * Settings keys (inside `Nera_SAW_Constants::OPTION_SETTINGS`).
	 */
	const SETTING_PER_TRANSACTION = 'wallet_topup_max_per_transaction';
	const SETTING_PER_DAY         = 'wallet_topup_max_per_day';

	/**
	 * Gateways whose title carries the theme's balance badge.
	 */
	const WALLET_GATEWAYS = array( 'wallet', 'nera_wallet_split' );

	/**
	 * Hooks.
	 */
	public static function init() {
		// 2. The name.
		add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'gateway_title' ), 99, 2 );
		add_filter( 'woocommerce_order_get_payment_method_title', array( __CLASS__, 'order_payment_method_title' ), 20, 2 );

		// 1. The limits: every way a top-up can start.
		add_filter( 'woo_wallet_is_valid_wallet_recharge_amount', array( __CLASS__, 'limit_form_amount' ), 20, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'limit_cart' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'limit_checkout' ), 20, 2 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'limit_rest' ), 10, 3 );

		// 3. The temporary-password notice.
		add_action( 'password_reset', array( __CLASS__, 'clear_password_nag' ), 10, 1 );
		add_action( 'woocommerce_customer_reset_password', array( __CLASS__, 'clear_password_nag' ), 10, 1 );
		add_action( 'woocommerce_save_account_details', array( __CLASS__, 'after_account_details_saved' ), 10, 1 );
		add_filter( 'get_user_option_default_password_nag', array( __CLASS__, 'nag_only_on_account_details' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * 2. The payment method's name
	 * ------------------------------------------------------------------ */

	/**
	 * The wallet gateway reads "Wallet" wherever it is named: the checkout list, and
	 * the title WooCommerce stores on the order when it is chosen.
	 *
	 * Priority 99, after the theme's balance badge (priority 10) has been appended,
	 * and the Russian title translation (priority 5) that would otherwise have
	 * matched its exact English wording.
	 *
	 * @param string $title      Title so far.
	 * @param string $gateway_id Gateway ID.
	 * @return string
	 */
	public static function gateway_title( $title, $gateway_id ) {
		if ( 'wallet' !== $gateway_id || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $title;
		}

		return __( 'Wallet', 'nera-strikeawin' );
	}

	/**
	 * The same for orders placed BEFORE this existed, whose stored title already
	 * carries the badge: it is taken off at display time, in emails too.
	 *
	 * Only the wallet gateways are touched. "Part wallet, part card" keeps its own
	 * name, minus any badge.
	 *
	 * @param string   $title Stored title.
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_payment_method_title( $title, $order ) {
		if ( ! $order instanceof WC_Order || ! in_array( $order->get_payment_method(), self::WALLET_GATEWAYS, true ) ) {
			return $title;
		}

		$plain = class_exists( 'Nera_SAW_Standalone_Basket' )
			? Nera_SAW_Standalone_Basket::plain_label( $title )
			: trim( wp_strip_all_tags( (string) $title ) );
		if ( '' === $plain ) {
			return $title;
		}

		// "Wallet payment" is woo-wallet's own default title (and its Russian).
		if ( 'wallet' === $order->get_payment_method() && in_array( $plain, array( 'Wallet payment', 'Оплата кошельком', 'Wallet', 'Кошелёк' ), true ) ) {
			return __( 'Wallet', 'nera-strikeawin' );
		}

		return $plain;
	}

	/* ---------------------------------------------------------------------
	 * 1. Top-up limits
	 * ------------------------------------------------------------------ */

	/**
	 * Most that may be topped up in one go (0 = no limit).
	 *
	 * @return float
	 */
	public static function max_per_transaction() {
		$s = Nera_SAW_Constants::settings();
		return isset( $s[ self::SETTING_PER_TRANSACTION ] ) ? max( 0.0, (float) $s[ self::SETTING_PER_TRANSACTION ] ) : 0.0;
	}

	/**
	 * Most that may be topped up in one day (0 = no limit).
	 *
	 * @return float
	 */
	public static function max_per_day() {
		$s = Nera_SAW_Constants::settings();
		return isset( $s[ self::SETTING_PER_DAY ] ) ? max( 0.0, (float) $s[ self::SETTING_PER_DAY ] ) : 0.0;
	}

	/**
	 * The top-up product's ID, or 0 without woo-wallet.
	 *
	 * @return int
	 */
	private static function product_id() {
		if ( ! function_exists( 'get_wallet_rechargeable_product' ) ) {
			return 0;
		}
		$product = get_wallet_rechargeable_product();
		return $product ? (int) $product->get_id() : 0;
	}

	/**
	 * What this player has topped up today: paid top-up orders since midnight in the
	 * site's timezone. An order still waiting for payment is not counted -- it is
	 * checked when it is made, and counts once it is paid.
	 *
	 * @param int $user_id Player.
	 * @return float
	 */
	public static function topped_up_today( $user_id ) {
		$product_id = self::product_id();
		if ( ! $user_id || ! $product_id || ! function_exists( 'wc_get_orders' ) ) {
			return 0.0;
		}

		$midnight = ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
		$orders   = wc_get_orders(
			array(
				'customer_id' => (int) $user_id,
				'status'      => array( 'processing', 'completed' ),
				'date_paid'   => '>=' . $midnight,
				'limit'       => -1,
				'return'      => 'objects',
			)
		);

		$sum = 0.0;
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( (int) $item->get_product_id() === $product_id ) {
					$sum += (float) $item->get_subtotal();
				}
			}
		}

		return round( $sum, 2 );
	}

	/**
	 * May this player top up this much now?
	 *
	 * @param int   $user_id Player.
	 * @param float $amount  Amount asked for.
	 * @return true|string True, or the message to show.
	 */
	public static function check( $user_id, $amount ) {
		$amount  = round( (float) $amount, 2 );
		$per_txn = self::max_per_transaction();
		$per_day = self::max_per_day();

		if ( $per_txn > 0 && $amount > $per_txn ) {
			return sprintf(
				/* translators: %s: the most a player may top up at once, formatted as a price */
				__( 'You can top up at most %s at a time.', 'nera-strikeawin' ),
				wc_price( $per_txn )
			);
		}

		if ( $per_day > 0 ) {
			$already   = self::topped_up_today( (int) $user_id );
			$remaining = round( $per_day - $already, 2 );

			if ( $remaining <= 0 ) {
				return sprintf(
					/* translators: %s: the daily top-up limit, formatted as a price */
					__( 'You have reached the daily top-up limit of %s. Please try again tomorrow.', 'nera-strikeawin' ),
					wc_price( $per_day )
				);
			}
			if ( $amount > $remaining ) {
				return sprintf(
					/* translators: 1: daily limit, 2: topped up today, 3: still allowed today (all prices) */
					__( 'The daily top-up limit is %1$s. You have topped up %2$s today, so you can add %3$s more.', 'nera-strikeawin' ),
					wc_price( $per_day ),
					wc_price( $already ),
					wc_price( $remaining )
				);
			}
		}

		return true;
	}

	/**
	 * woo-wallet's own top-up form.
	 *
	 * @param array $response { is_valid, message } as woo-wallet judged it.
	 * @param mixed $amount   Amount posted.
	 * @return array
	 */
	public static function limit_form_amount( $response, $amount ) {
		if ( empty( $response['is_valid'] ) || ! is_user_logged_in() ) {
			return $response;
		}

		$verdict = self::check( get_current_user_id(), (float) $amount );
		if ( true === $verdict ) {
			return $response;
		}

		return array(
			'is_valid' => false,
			'message'  => $verdict,
		);
	}

	/**
	 * The top-up amount sitting in the cart, 0 when the cart holds none.
	 *
	 * @return float
	 */
	private static function cart_topup_amount() {
		$product_id = self::product_id();
		if ( ! $product_id || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		$sum = 0.0;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( (int) $item['product_id'] !== $product_id ) {
				continue;
			}
			$sum += isset( $item['recharge_amount'] )
				? (float) $item['recharge_amount']
				: (float) $item['line_subtotal'];
		}

		return round( $sum, 2 );
	}

	/**
	 * The cart and checkout a top-up goes through: the backstop for anything that
	 * put a top-up in the cart without passing the form.
	 */
	public static function limit_cart() {
		$amount = self::cart_topup_amount();
		if ( $amount <= 0 || ! is_user_logged_in() ) {
			return;
		}

		$verdict = self::check( get_current_user_id(), $amount );
		if ( true !== $verdict ) {
			wc_add_notice( $verdict, 'error' );
		}
	}

	/**
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Validation errors.
	 */
	public static function limit_checkout( $data, $errors ) {
		unset( $data );
		$amount = self::cart_topup_amount();
		if ( $amount <= 0 ) {
			return;
		}

		$verdict = self::check( get_current_user_id(), $amount );
		if ( true !== $verdict ) {
			$errors->add( 'saw_wallet_limit', $verdict );
		}
	}

	/**
	 * woo-wallet's REST top-up, which makes the order itself and has no filter to hook.
	 *
	 * @param mixed           $response Response so far.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed|WP_Error
	 */
	public static function limit_rest( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method() ) {
			return $response;
		}
		if ( ! preg_match( '#/wallet[^/]*/.*me/topup/?$#i', $request->get_route() ) && ! preg_match( '#/me/topup/?$#', $request->get_route() ) ) {
			return $response;
		}
		if ( ! is_user_logged_in() ) {
			return $response;
		}

		$verdict = self::check( get_current_user_id(), (float) $request->get_param( 'amount' ) );
		if ( true === $verdict ) {
			return $response;
		}

		return new WP_Error( 'saw_wallet_limit', wp_strip_all_tags( $verdict ), array( 'status' => 400 ) );
	}

	/* ---------------------------------------------------------------------
	 * 3. The temporary-password notice
	 * ------------------------------------------------------------------ */

	/**
	 * The player has set a password of their own: stop saying they have a temporary one.
	 *
	 * @param WP_User|int $user User or ID.
	 */
	public static function clear_password_nag( $user ) {
		$user_id = $user instanceof WP_User ? (int) $user->ID : (int) $user;
		if ( $user_id > 0 ) {
			delete_user_option( $user_id, 'default_password_nag', true );
			delete_user_option( $user_id, 'default_password_nag' );
		}
	}

	/**
	 * Where the notice may show. The section's My account is one page that renders every
	 * panel, and WooCommerce asks "is this the dashboard?" of it, so the notice sat above
	 * the wallet, the orders, everything. It belongs with the thing it asks for: the
	 * Account details screen, where the password is changed. Everywhere else on the
	 * account page the flag reads as unset.
	 *
	 * @param mixed  $result Option value.
	 * @param string $option Option name.
	 * @param mixed  $user   User.
	 * @return mixed
	 */
	public static function nag_only_on_account_details( $result, $option, $user ) {
		unset( $option, $user );
		if ( ! $result || is_admin() || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return $result;
		}

		return is_wc_endpoint_url( 'edit-account' ) ? $result : false;
	}

	/**
	 * After My account -> Account details is saved: if a new password was part of it, the
	 * temporary one is gone.
	 *
	 * @param int $user_id User ID.
	 */
	public static function after_account_details_saved( $user_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce has verified the form before this action.
		if ( ! empty( $_POST['password_1'] ) ) {
			self::clear_password_nag( (int) $user_id );
		}
	}
}
