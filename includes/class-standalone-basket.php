<?php
/**
 * The standalone section has no basket screen, and does not want one.
 *
 * The design goes competition → "Before you pay" → pay. There is no cart artboard
 * and no checkout artboard, which is not an omission: the section sells one entry
 * at a time, so a basket holding several would be a screen with nothing to decide
 * on it.
 *
 * WHAT THIS ENFORCES
 * ------------------
 *  - Adding an entry replaces whatever was in the basket. In this section the
 *    basket is a selection in progress, not something a player has saved — nobody
 *    "collects" entries here, they pick one and pay.
 *  - Add-to-cart lands on Before you pay, never on the cart page.
 *  - The cart page, if reached directly, redirects there too.
 *
 * ON CLEARING RATHER THAN ASKING
 * ------------------------------
 * ADR 0022 says a basket mixing entries with ordinary products should stop and ask
 * rather than clear. That still holds in mix mode. It does not hold here, because
 * in standalone the player cannot see the shop those products came from: a
 * confirmation naming a t-shirt from a site the section deliberately hides explains
 * nothing. What they get instead is a notice saying what was removed, on a screen
 * where they can act on it.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Standalone_Basket
 */
class Nera_SAW_Standalone_Basket {

	/**
	 * Route of the pre-payment screen.
	 */
	const ROUTE = 'before-you-pay';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! Nera_SAW_Mode::is_standalone() ) {
			return;
		}

		/*
		 * The two baskets. Priority 1 on wp_loaded, because WC_Cart_Session hydrates
		 * the cart at priority 5 and the swap has to happen while the session still
		 * holds raw data.
		 */
		add_action( 'wp_loaded', array( __CLASS__, 'load_basket_for_context' ), 1 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'enforce_empty_basket' ) );
		add_action( 'shutdown', array( __CLASS__, 'store_basket_for_context' ), 1 );

		// One entry at a time is still the rule, but now only against other entries:
		// nothing else can be in this basket to clear.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'one_entry_only' ), 30, 3 );

		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'redirect_after_add' ), 10, 1 );

		// The section's checkout page is a real page carrying the checkout shortcode,
		// so WooCommerce has to be told it counts as a checkout.
		add_filter( 'woocommerce_is_checkout', array( __CLASS__, 'declare_checkout' ) );
		add_filter( 'woocommerce_is_order_received_page', array( __CLASS__, 'declare_order_received' ) );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'plain_payment_row' ), 20, 1 );
		add_filter( 'woocommerce_get_checkout_url', array( __CLASS__, 'checkout_url_for_entries' ) );

		// The checkout form says which basket it is for, so the submission does not
		// have to be inferred from a referer that does not survive being re-rendered.
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'stamp_context' ) );

		// Cancelling a section order must not land the shopper on the main site.
		add_filter( 'woocommerce_get_cancel_order_url', array( __CLASS__, 'cancel_url_stays_here' ) );
		add_filter( 'woocommerce_get_cancel_order_url_raw', array( __CLASS__, 'cancel_url_stays_here_raw' ) );

		// A checkout submission must not be able to hang on the mail server. See
		// defer_mail_during_checkout() for why this is a real, observed failure and
		// not a defensive guess.
		add_filter( 'pre_wp_mail', array( __CLASS__, 'defer_mail_during_checkout' ), 10, 2 );
		add_action( 'nera_saw_send_deferred_mail', array( __CLASS__, 'send_deferred_mail' ) );

		// Belt to that filter's braces, for the one setup it cannot reach: a site
		// where mail actually goes out over SMTP (rather than through a local
		// `sendmail_path` binary) and that server itself is slow to answer.
		add_action( 'phpmailer_init', array( __CLASS__, 'cap_mail_timeout' ) );
	}

	/**
	 * Take the section's checkout off the hook for how long mail takes.
	 *
	 * `process_checkout()` sends the order and admin notification emails —
	 * synchronously, in the same request the shopper's browser is waiting on —
	 * before it answers the AJAX call with the redirect. On this machine that
	 * request never returns: PHP's `mail()` shells out to a local `sendmail_path`
	 * binary, which makes its own connection to the configured relay, and neither
	 * PHP nor PHPMailer controls how long that subprocess is allowed to run.
	 * `cap_mail_timeout()` below only reaches PHPMailer's own SMTP client, so it is
	 * blind to this — the request instead runs until PHP's execution-time limit
	 * kills it. That is a fatal, uncatchable in userland, so it never reaches the
	 * JSON response: the shopper is left on "Processing your order" forever,
	 * having in fact already paid. This happened on this exact checkout from a
	 * broken local relay, which is how it was found.
	 *
	 * The fix is to never let the checkout request be the one that sends the
	 * mail. `pre_wp_mail` runs before `wp_mail()` touches PHPMailer at all,
	 * so the actual send is handed to a scheduled event a moment later — a
	 * separate request, on its own execution-time budget, that nobody's browser
	 * is waiting on. If that request also hangs, the only cost is a delayed or
	 * missing confirmation email, not a checkout that never finishes.
	 *
	 * Scoped to the section's own checkout so a site with working mail sends
	 * exactly as before — this only changes what happens when it doesn't.
	 *
	 * @param mixed $return Null tells `wp_mail()` to proceed normally; anything else short-circuits it.
	 * @param array $atts   `wp_mail()`'s own arguments, unchanged.
	 * @return mixed
	 */
	public static function defer_mail_during_checkout( $return, $atts ) {
		if ( null !== $return || 'saw' !== self::context() ) {
			return $return;
		}

		wp_schedule_single_event( time(), 'nera_saw_send_deferred_mail', array( (array) $atts ) );

		// Scheduling alone would otherwise sit until the next unrelated visitor's
		// page load triggers WordPress's own pseudo-cron check. Nudging it here
		// fires the event within moments instead of leaving the shopper's
		// confirmation email waiting on someone else's traffic.
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		// wp_mail() itself reports success; the checkout was never able to learn
		// whether the mail itself does; scheduling it is the only part this
		// request can vouch for.
		return true;
	}

	/**
	 * Send a mail that defer_mail_during_checkout() took out of the checkout request.
	 *
	 * Runs as a scheduled event, not inside the section, so `context()` answers
	 * 'main' here and this does not re-defer itself.
	 *
	 * @param array $atts The original wp_mail() arguments.
	 */
	public static function send_deferred_mail( $atts ) {
		$atts = (array) $atts;
		wp_mail(
			isset( $atts['to'] ) ? $atts['to'] : '',
			isset( $atts['subject'] ) ? $atts['subject'] : '',
			isset( $atts['message'] ) ? $atts['message'] : '',
			isset( $atts['headers'] ) ? $atts['headers'] : '',
			isset( $atts['attachments'] ) ? $atts['attachments'] : array()
		);
	}

	/**
	 * Cap how long PHPMailer's own SMTP client will wait, for the site that has one.
	 *
	 * Has no effect when mail goes out through a local `sendmail_path` binary
	 * instead — see defer_mail_during_checkout(), which is what actually protects
	 * the checkout on a site set up that way.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer The instance WordPress is about to send with.
	 */
	public static function cap_mail_timeout( $phpmailer ) {
		if ( 'saw' !== self::context() ) {
			return;
		}

		$phpmailer->Timeout       = 8;
		$phpmailer->SMTPKeepAlive = false;
	}

	/**
	 * Keep a cancelled section order inside the section.
	 *
	 * WooCommerce builds this link on the cart page and sends the shopper to My
	 * Account afterwards, both of which belong to the main site. From inside the
	 * section that is a door out of it: the shopper cancels one competition entry
	 * and finds themselves in the shop, looking at a basket that is not the one they
	 * were using.
	 *
	 * Only the address is changed. `cancel_order`, `order`, `order_id` and the nonce
	 * are carried across untouched — the nonce is signed on the action name, not on
	 * the URL, so moving the query to another page leaves it valid, and
	 * `WC_Form_Handler::cancel_order()` runs on `wp_loaded` for any page.
	 *
	 * @param string $url The cancel URL WooCommerce assembled.
	 * @return string
	 */
	public static function cancel_url_stays_here( $url ) {
		return self::rewrite_cancel_url( $url, true );
	}

	/**
	 * The same, for the unescaped variant of the link.
	 *
	 * @param string $url The cancel URL WooCommerce assembled.
	 * @return string
	 */
	public static function cancel_url_stays_here_raw( $url ) {
		return self::rewrite_cancel_url( $url, false );
	}

	/**
	 * Move a cancel link onto the section's own page.
	 *
	 * WooCommerce hands this filter an HTML-escaped URL, so the separators arrive as
	 * `&amp;` and reading the query without decoding first yields keys named
	 * `amp;order` and `amp;redirect` — the real arguments go missing and a second
	 * `redirect` gets appended beside the original. Decode, rebuild, then escape the
	 * way the hook expects to be answered.
	 *
	 * @param string $url     The cancel URL WooCommerce assembled.
	 * @param bool   $escaped Whether this hook deals in escaped URLs.
	 * @return string
	 */
	private static function rewrite_cancel_url( $url, $escaped ) {
		if ( 'saw' !== self::context() ) {
			return $url;
		}

		$home = Nera_SAW_Standalone_Pages::url( 'competitions' );
		if ( ! $home ) {
			return $url;
		}

		$plain = $escaped ? wp_specialchars_decode( (string) $url, ENT_QUOTES ) : (string) $url;

		$args = array();
		parse_str( (string) wp_parse_url( $plain, PHP_URL_QUERY ), $args );
		if ( empty( $args['cancel_order'] ) ) {
			return $url;
		}

		$args['redirect'] = $home;
		$rebuilt          = add_query_arg( $args, $home );

		return $escaped ? esc_url( $rebuilt ) : esc_url_raw( $rebuilt );
	}

	/**
	 * Put the basket's identity inside the checkout form.
	 *
	 * The referer cannot be trusted here, and the way it fails is worth writing down
	 * because nothing about it looks wrong:
	 *
	 * `wp_nonce_field()` emits a `_wp_http_referer` alongside the nonce, holding the
	 * URL of whatever request rendered it. On the first page load that is
	 * `/strikeawin/checkout/` and everything works. But WooCommerce re-renders the
	 * order review over AJAX, and from then on the field reads
	 * `/?wc-ajax=update_order_review` — a path with nothing of the section in it.
	 * `wp_get_referer()` prefers that field over the browser's own header, so the
	 * context silently flipped to the main site, the main basket loaded, and
	 * WooCommerce answered the only way it could: "Sorry, your session has expired."
	 *
	 * The first few requests worked, which is what made it look intermittent.
	 *
	 * This field's value is a constant, not a URL, so re-rendering cannot decay it —
	 * and because `context()` reads the field before anything else, a re-render
	 * finds the section and stamps the section again.
	 */
	public static function stamp_context() {
		// Either this really is the section's checkout page, or a stamp is already in
		// flight and the order review is being rebuilt. Deliberately not `context()`:
		// that would also accept a referer, and stamp the main site's checkout form
		// for anyone who reached it from a section page.
		if ( ! self::is_section_checkout() && '1' !== self::incoming_stamp() ) {
			return;
		}
		echo '<input type="hidden" name="saw_basket" value="1">';
	}

	/* ---------------------------------------------------------------------
	 * Two baskets
	 * ------------------------------------------------------------------ */

	/**
	 * Session key holding one context's basket.
	 *
	 * @param string $context 'saw' or 'main'.
	 * @return string
	 */
	/**
	 * The basket as it was when this request loaded it, so that a request which
	 * changed nothing can be told apart from one that emptied the basket.
	 *
	 * @var string|null
	 */
	private static $loaded = null;

	/**
	 * Whether the slot already existed when this request loaded it.
	 *
	 * @var bool
	 */
	private static $slot_existed = false;

	private static function slot( $context ) {
		return 'saw' === $context ? 'nera_saw_basket_section' : 'nera_saw_basket_main';
	}

	/**
	 * Which basket this request is about.
	 *
	 * Decided from the URL, never from a conditional tag: this runs at wp_loaded
	 * priority 1, before the query is parsed, so `is_page()` and friends do not
	 * exist yet and would quietly answer false for everything.
	 *
	 * Three signals, in order of how much they can be trusted:
	 *
	 *   1. An explicit `saw_basket` in the request. The section's own scripts send
	 *      it, so an AJAX add from the competition screen says what it is rather
	 *      than being guessed at.
	 *   2. The path. Any address under the section's prefix is the section.
	 *   3. The referer, for WooCommerce's own AJAX endpoints — `/?wc-ajax=checkout`
	 *      carries no path of its own, and the page that fired it is the only thing
	 *      left that knows.
	 *
	 * @return string 'saw' or 'main'.
	 */
	public static function context() {
		if ( ! Nera_SAW_Mode::is_standalone() ) {
			return 'main';
		}

		$stamp = self::incoming_stamp();
		if ( null !== $stamp ) {
			return '1' === $stamp ? 'saw' : 'main';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$path = isset( $_SERVER['REQUEST_URI'] )
			? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
			: '';

		if ( self::path_is_section( $path ) ) {
			return 'saw';
		}

		/*
		 * The referer is the last resort, and only for a request that has no address
		 * of its own — an AJAX endpoint. A request that names a page has already
		 * answered the question above, and letting a referer speak for it would hand
		 * the section's basket to whoever arrived at the main site's checkout from a
		 * section page.
		 *
		 * Even then it is weak evidence: WooCommerce's endpoints answer at
		 * `/?wc-ajax=…`, and a form re-rendered inside one carries that address
		 * forward as its referer, which says nothing about where the shopper is.
		 */
		$ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || isset( $_GET['wc-ajax'] );
		if ( $ajax ) {
			$referer = wp_get_referer();
			if ( $referer ) {
				parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $vars );
				if ( ! isset( $vars['wc-ajax'] ) && self::path_is_section( (string) wp_parse_url( $referer, PHP_URL_PATH ) ) ) {
					return 'saw';
				}
			}
		}
		// phpcs:enable

		return 'main';
	}

	/**
	 * The basket this request declares for itself, if it declares one.
	 *
	 * @return string|null '1', '0', or null when the request says nothing.
	 */
	private static function incoming_stamp() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['saw_basket'] ) ) {
			return '1' === (string) $_REQUEST['saw_basket'] ? '1' : '0';
		}

		/*
		 * `update_order_review` does not post the checkout form at the top level —
		 * it nests the whole thing under `post_data` as one encoded string. The
		 * stamp is in there, so read it out rather than fall back to guessing.
		 */
		if ( isset( $_REQUEST['post_data'] ) && is_string( $_REQUEST['post_data'] ) ) {
			parse_str( wp_unslash( $_REQUEST['post_data'] ), $posted ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( isset( $posted['saw_basket'] ) ) {
				return '1' === (string) $posted['saw_basket'] ? '1' : '0';
			}
		}
		// phpcs:enable

		return null;
	}

	/**
	 * Does a path sit inside the section?
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	private static function path_is_section( $path ) {
		if ( '' === $path || ! class_exists( 'Nera_SAW_Router' ) ) {
			return false;
		}

		$prefix = trim( (string) Nera_SAW_Router::prefix(), '/' );
		if ( '' === $prefix ) {
			return false;
		}

		$path = trim( $path, '/' );
		return $path === $prefix || 0 === strpos( $path, $prefix . '/' );
	}

	/**
	 * Put this context's basket into the session before WooCommerce reads it.
	 *
	 * The swap happens in the session's own storage rather than on the cart object,
	 * so WooCommerce hydrates, prices and validates it exactly as it would any other
	 * basket. Nothing downstream can tell the difference, which is the point — two
	 * baskets, one cart implementation.
	 *
	 * A context with nothing saved yet starts empty in the section and inherits the
	 * existing basket on the main site. Carts that predate this split are main-site
	 * carts; treating them as anything else would move somebody's shopping into a
	 * section they have never opened.
	 */
	public static function load_basket_for_context() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$context = self::context();
		$slot    = WC()->session->get( self::slot( $context ), null );

		// A slot that has never been written is not the same as an empty one. The
		// section starts empty; the main site inherits whatever WooCommerce already
		// had, so an existing basket is not thrown away the first time this runs.
		self::$slot_existed = ( null !== $slot );

		if ( null === $slot ) {
			$slot = ( 'saw' === $context ) ? array() : (array) WC()->session->get( 'cart', array() );
		}

		$slot = (array) $slot;
		WC()->session->set( 'cart', $slot );
		self::$loaded = self::fingerprint( $slot );
	}

	/**
	 * A value that changes when the basket's contents change, and not otherwise.
	 *
	 * Sorted by key because the session array's order is not meaningful and an
	 * order-only difference would read as a change.
	 *
	 * @param array $cart Cart contents in session form.
	 * @return string
	 */
	private static function fingerprint( $cart ) {
		$cart = (array) $cart;
		ksort( $cart );
		return md5( wp_json_encode( $cart ) );
	}

	/**
	 * An empty basket has to end up empty.
	 *
	 * `WC_Cart_Session::get_cart_from_session()` only assigns contents when the
	 * session holds some: an empty session cart leaves whatever the cart object
	 * already had. In an ordinary request that object starts empty and the omission
	 * never shows, which is why it is easy to miss — and why swapping baskets is
	 * exactly the case where it would.
	 *
	 * Belt to the swap's braces. It costs one comparison and removes a whole class
	 * of "the other basket leaked in" that would otherwise depend on nothing else
	 * having touched the cart first.
	 */
	public static function enforce_empty_basket() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		$slot = (array) WC()->session->get( self::slot( self::context() ), array() );
		if ( ! $slot && WC()->cart->get_cart_contents() ) {
			WC()->cart->set_cart_contents( array() );
		}
	}

	/**
	 * Save this context's basket back at the end of the request.
	 *
	 * On shutdown rather than on a cart-changed hook, because a request can change
	 * the cart several times — add, recalculate, apply a coupon — and only the state
	 * it ends in is the basket.
	 */
	public static function store_basket_for_context() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$cart = (array) WC()->session->get( 'cart', array() );

		/*
		 * Only write a basket this request actually changed.
		 *
		 * The browser fires overlapping requests — adding to the basket also asks
		 * for refreshed cart fragments — and both of them run load → work → store.
		 * Writing unconditionally means the read-only one, which loaded a moment
		 * earlier and changed nothing, saves its stale snapshot last and undoes the
		 * add. To the shopper the product goes in and then comes straight back out,
		 * which reads as "a second product will not stay in the basket".
		 *
		 * A request that changed nothing has nothing worth saving, so the cheapest
		 * fix is also the correct one: stay out of the way.
		 */
		if ( self::$slot_existed && null !== self::$loaded && self::fingerprint( $cart ) === self::$loaded ) {
			return;
		}

		WC()->session->set( self::slot( self::context() ), $cart );
	}

	/**
	 * Keep entries and ordinary products out of the same basket	/**
	 * One entry at a time.
	 *
	 * What this no longer does is the interesting part. It used to empty the whole
	 * basket, and a matching rule used to strip entries when an ordinary product was
	 * added, because both lived in the same WooCommerce cart and the section's
	 * products kept turning up on the main site's pages.
	 *
	 * The baskets are separate now, so none of that is needed: an ordinary product
	 * cannot be in this basket to remove, and adding one cannot disturb an entry.
	 * All that survives is the section's own rule — a basket here holds one entry,
	 * because that is what Before you pay shows.
	 *
	 * Swapping one competition for another is the ordinary path and is not
	 * announced. A warning every time would turn a player changing their mind into
	 * something that looks like a mistake.
	 *
	 * @param bool $passed     Whether validation passed so far.
	 * @param int  $product_id Product being added.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public static function one_entry_only( $passed, $product_id, $quantity ) {
		if ( ! $passed || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $passed;
		}

		if ( ! Nera_SAW_Competition_Config::is_competition( (int) $product_id ) ) {
			return $passed;
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! empty( $item['product_id'] ) && Nera_SAW_Competition_Config::is_competition( (int) $item['product_id'] ) ) {
				WC()->cart->remove_cart_item( $key );
			}
		}

		return $passed;
	}

	/**
	 * Send an add-to-cart to Before you pay.
	 *
	 * @param string $url Where WooCommerce was going.
	 * @return string
	 */
	public static function redirect_after_add( $url ) {
		$to = self::pre_payment_url();
		return $to ? $to : $url;
	}

	/*
	 * THE CART PAGE IS LEFT ALONE, DELIBERATELY
	 *
	 * An earlier version of this class redirected /cart/ to Before you pay whenever
	 * the basket held an entry. It worked, and it was wrong: the cart page belongs to
	 * the main site, and a shopper who clicks the basket icon in the main site's own
	 * header expects the main site's basket — not to be thrown into a section they
	 * were not browsing.
	 *
	 * Standalone changes how competitions are sold. It does not take over pages the
	 * section does not own. The section keeps players away from the cart by never
	 * linking to it and by redirecting its own add-to-cart, which is the whole of
	 * its business.
	 */

	/* ---------------------------------------------------------------------
	 * Payment methods
	 * ------------------------------------------------------------------ */

	/**
	 * The names of the payment methods available for this basket.
	 *
	 * A gateway's customer-facing title is allowed to carry markup, and plugins use
	 * that: this site's theme appends a "Sufficient Balance" badge to the wallet
	 * gateway through `woocommerce_gateway_title`. Escaped, it prints its own tags
	 * as text; rendered, it arrives with class names from a stylesheet the section
	 * deliberately does not load.
	 *
	 * So elements are removed with their contents rather than unwrapped. The badge
	 * is contextual state, not the method's name, and this list exists to say which
	 * methods the site can take -- the state belongs on the checkout, one screen
	 * later, where acting on it is possible.
	 *
	 * @return string[] Plain names, in the order WooCommerce offers them.
	 */
	public static function method_labels() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return array();
		}

		$labels = array();
		foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway ) {
			$title = self::plain_label( $gateway->get_title() );

			if ( '' === $title ) {
				// A title that was nothing but markup still has to name something.
				$title = trim( wp_strip_all_tags( (string) $gateway->get_method_title() ) );
			}

			if ( '' !== $title ) {
				$labels[] = $title;
			}
		}

		return array_values( array_unique( $labels ) );
	}

	/* ---------------------------------------------------------------------
	 * Checkout
	 * ------------------------------------------------------------------ */

	/**
	 * The section's own checkout page.
	 *
	 * NOT the main site's. An earlier version of this class re-skinned
	 * `/checkout/` whenever the basket held an entry, which broke the rule the
	 * section is built on: standalone adds screens, it never takes over a page the
	 * main site already owns. Two sites sharing one checkout page meant one of them
	 * always had the wrong chrome.
	 *
	 * @return string
	 */
	public static function checkout_url() {
		$url = Nera_SAW_Standalone_Pages::url( 'checkout' );
		if ( $url ) {
			return $url;
		}
		// Until the page exists, WooCommerce's own checkout is better than nowhere.
		return function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : '';
	}

	/**
	 * Is the section's checkout page being viewed?
	 *
	 * @return bool
	 */
	public static function is_section_checkout() {
		if ( ! function_exists( 'is_page' ) || ! is_page() ) {
			return false;
		}
		$id = Nera_SAW_Standalone_Pages::page_id( 'checkout' );
		return $id && (int) $id === (int) get_queried_object_id();
	}

	/**
	 * Is this request part of the section's checkout, page or AJAX?
	 *
	 * WooCommerce re-renders the whole order review — payment methods included —
	 * over AJAX, within a second of the checkout loading and again on every change.
	 * `is_page()` is false in that request, so anything keyed on the page alone
	 * renders correctly once and is then replaced by markup built as though the
	 * section were not there.
	 *
	 * That is not theoretical: the payment methods came back wearing the theme's
	 * markup and Tailwind class names, with the theme's stylesheet dequeued — an
	 * oversized card graphic and washed-out panels that no amount of CSS in this
	 * plugin could have reached, because the classes it would have to target belong
	 * to a framework this section does not load.
	 *
	 * The basket is what identifies the request instead: an entry in the cart during a
	 * WooCommerce AJAX call means the player is in the section -- but only when the
	 * basket holds nothing else. Nothing enforces that a basket holding an entry holds
	 * only entries, and a mixed basket is checked out on the main site's own checkout
	 * page. Treating its AJAX refreshes as the section's swapped in the section's
	 * payment template (with its own terms and place-order block) and WooCommerce's
	 * stock payment-method rows: every terms-time notice printed twice and the gateway
	 * card artwork lost its size cap.
	 *
	 * @return bool
	 */
	public static function is_entry_context() {
		if ( self::is_section_checkout() ) {
			return true;
		}

		$ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ! empty( $_GET['wc-ajax'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return $ajax && null !== self::current_entry() && self::basket_is_only_entries();
	}

	/**
	 * Does the basket hold nothing but competition entries?
	 *
	 * @return bool
	 */
	protected static function basket_is_only_entries() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( ! $product_id || ! Nera_SAW_Competition_Config::is_competition( $product_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tell WooCommerce this page is a checkout.
	 *
	 * Without it `is_checkout()` is false here, and gateways decide whether to render
	 * from that call -- PayPal Payments, Stripe and every express button among them.
	 * A checkout page that silently loses its payment methods is the failure this
	 * one line prevents.
	 *
	 * @param bool $is_checkout Incoming.
	 * @return bool
	 */
	public static function declare_checkout( $is_checkout ) {
		return $is_checkout ? $is_checkout : self::is_section_checkout();
	}

	/**
	 * Point WooCommerce's checkout URL at the section while an entry is in the basket.
	 *
	 * Not a page override: the URL is a link, and the only thing in the basket at
	 * this point is an entry, so the section's checkout is where it should lead. It
	 * has to cover the AJAX submission too, where `is_page()` is false and the
	 * order-received URL is built -- otherwise the player pays inside the section and
	 * lands on the main site to be thanked.
	 *
	 * WooCommerce registers its endpoints with EP_PAGES, so `order-received` resolves
	 * on this page as it does on any other.
	 *
	 * @param string $url WooCommerce's checkout URL.
	 * @return string
	 */
	/**
	 * Say that the section's confirmation screen is an order-received page.
	 *
	 * `is_order_received_page()` asks `is_page( wc_get_page_id( 'checkout' ) )`, and
	 * that page is the main site's. The section has a checkout page of its own, so
	 * the answer was always no — even standing on the finished order. Everything
	 * keyed on it went quiet, including the stylesheet for the play card, which left
	 * the card as a run of unspaced text: the count, the competition name and "Play
	 * quiz" with nothing between them.
	 *
	 * Answered only for the section's own checkout page carrying an order, so the
	 * main site's reading of the same question is untouched.
	 *
	 * @param bool $is What WooCommerce worked out on its own.
	 * @return bool
	 */
	public static function declare_order_received( $is ) {
		if ( $is || ! self::is_section_checkout() ) {
			return $is;
		}

		global $wp;

		return isset( $wp->query_vars['order-received'] );
	}

	/**
	 * A gateway's name with the theme's decoration taken off.
	 *
	 * Gateways return markup, not text: this site's wallet answers "Wallet payment"
	 * followed by a `<span>` badge reading "✓ Sufficient Balance", styled entirely by
	 * the theme's utility classes. The section does not load that stylesheet, so the
	 * badge arrived as unstyled words trailing the gateway's name and wrapping onto
	 * a line of their own.
	 *
	 * Whole elements go first. `wp_strip_all_tags()` on its own would keep the text
	 * inside the badge and leave exactly the run-on it is meant to prevent. Innermost
	 * first, repeatedly, so a badge nested in another element leaves with its wrapper
	 * rather than stranding the wrapper's text.
	 *
	 * @param string $html Whatever the gateway calls itself.
	 * @return string
	 */
	public static function plain_label( $html ) {
		$text     = (string) $html;
		$previous = '';

		while ( $previous !== $text ) {
			$previous = $text;
			$text     = (string) preg_replace( '#<([a-z][a-z0-9-]*)[^>]*>[^<]*</\1\s*>#i', '', $text );
		}

		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
	}

	/**
	 * The same treatment for the payment row of a finished order.
	 *
	 * Limited to the section's own screens. The row is assembled for order emails
	 * too, and those are the main site's to style.
	 *
	 * @param array $totals Rows WooCommerce assembled.
	 * @return array
	 */
	public static function plain_payment_row( $totals ) {
		if ( ! is_array( $totals ) || ! isset( $totals['payment_method']['value'] ) ) {
			return $totals;
		}

		if ( ! self::is_section_checkout() ) {
			return $totals;
		}

		$totals['payment_method']['value'] = self::plain_label( $totals['payment_method']['value'] );

		return $totals;
	}

	public static function checkout_url_for_entries( $url ) {
		/*
		 * Asked of the request, not of the basket, because the basket does not
		 * survive long enough to be asked.
		 *
		 * WooCommerce builds the order-received URL on top of this one, and by then
		 * payment has emptied the cart — so a check for "is there an entry in here"
		 * answers no, and the shopper finishes a section purchase on the main site's
		 * thank-you page. The request still knows which section it belongs to.
		 */
		if ( 'saw' !== self::context() ) {
			return $url;
		}
		$ours = Nera_SAW_Standalone_Pages::url( 'checkout' );
		return $ours ? $ours : $url;
	}

	/* ---------------------------------------------------------------------
	 * Reading the basket
	 * ------------------------------------------------------------------ */

	/**
	 * Permalink of the pre-payment screen, or '' while the page is missing.
	 *
	 * @return string
	 */
	public static function pre_payment_url() {
		return Nera_SAW_Standalone_Pages::url( self::ROUTE );
	}

	/**
	 * The one entry in the basket, assembled for a screen to render.
	 *
	 * Returns the first entry found rather than the only one, because a basket that
	 * somehow holds two is a bug that should render something sensible and not a
	 * fatal.
	 *
	 * @return array|null { spec, tier, quantity, line_total, key }
	 */
	public static function current_entry() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( ! $product_id || ! Nera_SAW_Competition_Config::is_competition( $product_id ) ) {
				continue;
			}

			$spec = Nera_SAW_Competition_Spec::get( $product_id );
			if ( ! $spec ) {
				continue;
			}

			$tier_key = isset( $item[ Nera_SAW_Cart_Entry::CART_KEY ] ) ? (string) $item[ Nera_SAW_Cart_Entry::CART_KEY ] : '';
			$tier     = null;
			foreach ( $spec['tiers'] as $candidate ) {
				if ( $candidate['key'] === $tier_key ) {
					$tier = $candidate;
					break;
				}
			}

			// A tier the ceiling closed between adding and paying is no longer in the
			// spec. Falling back to the cheapest keeps the screen renderable; the
			// price shown still comes from the cart, so it cannot mislead.
			if ( ! $tier && $spec['tiers'] ) {
				$tier = $spec['tiers'][0];
			}

			$quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;

			return array(
				'key'        => $key,
				'spec'       => $spec,
				'tier'       => $tier,
				'quantity'   => $quantity,
				'line_total' => isset( $item['line_total'] ) ? (float) $item['line_total'] + (float) $item['line_tax'] : 0.0,
			);
		}

		return null;
	}
}
