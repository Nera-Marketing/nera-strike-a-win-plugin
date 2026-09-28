<?php
/**
 * Standalone section — account and login/registration plumbing.
 *
 * `[woocommerce_my_account]` on templates/standalone/my-account.php does almost
 * everything by itself: `is_account_page()` finds it on its own by detecting the
 * shortcode in the post content, so every plugin that already keys on "is this
 * the account page" — self-exclusion, spending limits, the age gate — keeps
 * working here with no extra wiring.
 *
 * What it does not do by itself is stay inside the section:
 *
 *   - `wc_get_endpoint_url()` builds Orders / View order / Edit account / Log
 *     out links from the *main site's* account permalink, because that is the
 *     only base WooCommerce knows about. Left alone, clicking any tab on this
 *     page would carry the player back to the main site.
 *   - Logging in or registering from this page has nowhere of its own to
 *     return to: `myaccount/form-login.php` posts no redirect field, so both
 *     `woocommerce_login_redirect` and `woocommerce_registration_redirect`
 *     fall back to WooCommerce's own default, the main site's account URL.
 *   - A run has to belong to a player for `Nera_SAW_Run_Grants` to have
 *     anywhere to record it, so a run's purchase is what asks for an account —
 *     not the play screen itself, which stays open so a visitor can see what a
 *     tier costs before deciding whether to sign in.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Standalone_Account
 */
class Nera_SAW_Standalone_Account {

	/**
	 * Query var carrying where to return to after logging in or registering
	 * from inside the section, since WooCommerce's own form has no field of
	 * its own for it.
	 */
	const RETURN_PARAM = 'saw_return';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_get_endpoint_url', array( __CLASS__, 'endpoint_url' ), 10, 4 );
		add_filter( 'woocommerce_get_myaccount_page_permalink', array( __CLASS__, 'myaccount_page_permalink' ) );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'login_redirect' ) );
		add_filter( 'woocommerce_registration_redirect', array( __CLASS__, 'login_redirect' ) );

		add_action( 'woocommerce_login_form_start', array( __CLASS__, 'carry_return_field' ) );
		add_action( 'woocommerce_register_form_start', array( __CLASS__, 'carry_return_field' ) );

		add_action( 'template_redirect', array( __CLASS__, 'require_login_before_pay' ) );
	}

	/* ---------------------------------------------------------------------
	 * Staying inside the section
	 * ------------------------------------------------------------------ */

	/**
	 * Rebuild an account endpoint's URL to stay on the section's own page.
	 *
	 * @param string $url       Endpoint URL WooCommerce built.
	 * @param string $endpoint  Endpoint slug.
	 * @param string $value     Endpoint value, e.g. an order ID.
	 * @param string $permalink Permalink the endpoint was built from.
	 * @return string
	 */
	public static function endpoint_url( $url, $endpoint, $value, $permalink ) {
		unset( $endpoint, $value, $permalink );

		if ( 'saw' !== Nera_SAW_Standalone_Basket::context() ) {
			return $url;
		}

		// The raw main-site permalink, not wc_get_page_permalink( 'myaccount' ):
		// that call passes through myaccount_page_permalink() below, which would
		// hand back our own URL here and leave nothing to detect and strip.
		$main_id   = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0;
		$main_base = $main_id ? (string) get_permalink( $main_id ) : '';
		$our_base  = class_exists( 'Nera_SAW_Standalone_Pages' ) ? Nera_SAW_Standalone_Pages::url( 'my-account' ) : '';

		if ( '' === $main_base || '' === $our_base ) {
			return $url;
		}

		$main_base = trailingslashit( $main_base );
		if ( 0 !== strpos( $url, $main_base ) ) {
			return $url;
		}

		return trailingslashit( $our_base ) . substr( $url, strlen( $main_base ) );
	}

	/**
	 * Send `wc_get_page_permalink( 'myaccount' )` itself to the section's page.
	 *
	 * `wc_get_account_endpoint_url( 'dashboard' )` — the account nav's own first
	 * row — is a special case in WooCommerce core: unlike every other endpoint it
	 * returns this permalink directly, never touching `wc_get_endpoint_url()`, so
	 * `endpoint_url()` above never sees it. Every other direct call to
	 * `wc_get_page_permalink( 'myaccount' )` on this site — a "log in to see your
	 * account" notice, a plugin's own account link — is carried the same way.
	 *
	 * @param string $permalink WooCommerce's own resolved permalink.
	 * @return string
	 */
	public static function myaccount_page_permalink( $permalink ) {
		if ( 'saw' !== Nera_SAW_Standalone_Basket::context() ) {
			return $permalink;
		}

		$ours = class_exists( 'Nera_SAW_Standalone_Pages' ) ? Nera_SAW_Standalone_Pages::url( 'my-account' ) : '';

		return $ours ? $ours : $permalink;
	}

	/**
	 * Keep a fresh login or registration inside the section, and honour a
	 * specific return address when the form carried one — set by
	 * `require_login_before_pay()` below, e.g. back to Before you pay.
	 *
	 * @param string $redirect WooCommerce's own default redirect.
	 * @return string
	 */
	public static function login_redirect( $redirect ) {
		if ( 'saw' !== Nera_SAW_Standalone_Basket::context() ) {
			return $redirect;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$to = isset( $_REQUEST[ self::RETURN_PARAM ] ) ? esc_url_raw( wp_unslash( $_REQUEST[ self::RETURN_PARAM ] ) ) : '';
		if ( $to && self::url_is_section( $to ) ) {
			return $to;
		}

		$ours = class_exists( 'Nera_SAW_Standalone_Pages' ) ? Nera_SAW_Standalone_Pages::url( 'my-account' ) : '';
		return $ours ? $ours : $redirect;
	}

	/**
	 * Echo a hidden field carrying the return address through the login and
	 * register forms, which post nothing of their own for it.
	 */
	public static function carry_return_field() {
		if ( 'saw' !== Nera_SAW_Standalone_Basket::context() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to = isset( $_GET[ self::RETURN_PARAM ] ) ? esc_url_raw( wp_unslash( $_GET[ self::RETURN_PARAM ] ) ) : '';
		if ( '' === $to || ! self::url_is_section( $to ) ) {
			return;
		}

		printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( self::RETURN_PARAM ), esc_attr( $to ) );
	}

	/**
	 * Whether a URL points somewhere inside this section, so a return address
	 * can never send a player (or an attacker crafting the query string)
	 * off-section after logging in.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private static function url_is_section( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host && $host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return false;
		}

		$prefix = trim( (string) Nera_SAW_Router::prefix(), '/' );
		if ( '' === $prefix ) {
			return false;
		}

		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		return $path === $prefix || 0 === strpos( $path, $prefix . '/' );
	}

	/* ---------------------------------------------------------------------
	 * Requiring an account to pay
	 * ------------------------------------------------------------------ */

	/**
	 * Send a logged-out visitor to sign in before they can pay for a run.
	 *
	 * Gated on Before you pay and on the section's own checkout, not on the
	 * play screen: a visitor can still open a tier and see what it costs
	 * without an account, and is only asked for one once there is a run to
	 * attach it to.
	 */
	public static function require_login_before_pay() {
		if ( is_user_logged_in() || is_admin() ) {
			return;
		}

		if ( 'saw' !== Nera_SAW_Standalone_Basket::context() ) {
			return;
		}

		if ( ! self::is_gated_page() ) {
			return;
		}

		$account_url = class_exists( 'Nera_SAW_Standalone_Pages' ) ? Nera_SAW_Standalone_Pages::url( 'my-account' ) : '';
		if ( ! $account_url ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$here        = home_url( $request_uri );

		wp_safe_redirect( add_query_arg( self::RETURN_PARAM, rawurlencode( $here ), $account_url ) );
		exit;
	}

	/**
	 * Whether the current request is one of the pages a player must be signed
	 * in to reach.
	 *
	 * @return bool
	 */
	private static function is_gated_page() {
		if ( ! class_exists( 'Nera_SAW_Standalone_Pages' ) || ! is_page() ) {
			return false;
		}

		$id = (int) get_queried_object_id();
		foreach ( array( 'before-you-pay', 'checkout' ) as $route ) {
			if ( $id === Nera_SAW_Standalone_Pages::page_id( $route ) ) {
				return true;
			}
		}

		return false;
	}
}
