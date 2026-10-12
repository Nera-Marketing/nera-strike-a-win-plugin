<?php
/**
 * Client findings #4/#9/#50: a couple of stock WordPress/WooCommerce URLs
 * that are still reachable and still show the un-skinned stock experience,
 * each sent to its real equivalent instead.
 *
 * - `/cart/`, when it actually holds something: redirected to "Before you
 *   pay". `Nera_SAW_Standalone_Basket::redirect_after_add()` already sends
 *   an actual add-to-cart there, but that class's own docblock explicitly
 *   decided NOT to also redirect a bare visit to the cart page itself
 *   ("THE CART PAGE IS LEFT ALONE, DELIBERATELY" — a shopper landing there
 *   from the main site's own basket icon should see the main site's own
 *   basket, not be pulled into a section they were not browsing). The
 *   client has since asked for exactly that redirect (finding #50: the
 *   stock cart page lets a player change ticket quantity with +5/+10
 *   outside the tier flow, and its checkout skips Before you pay
 *   entirely) — a direct, informed override of that earlier call, added
 *   here rather than edited into that class's own, differently-reasoned
 *   decision. An EMPTY cart is left alone either way: there is nothing to
 *   review on Before you pay, and the stock empty-cart page is harmless.
 *
 * - `/checkout/`, same guard, same reasoning: the client's own complaint
 *   about `/cart/` was that its checkout skips Before you pay entirely, and
 *   a direct visit to `/checkout/` bypasses the cart redirect above just as
 *   easily as the stock cart page did. Not named explicitly in the
 *   client's report, but the identical gap behind the one they did name.
 *
 * - The bare `/ru/` language root (no further path): redirected to
 *   Polylang's own Russian home page. `/ru/` alone does not resolve to any
 *   real post (confirmed: `url_to_postid()` returns 0 for it) — Polylang's
 *   directory-mode rewrites fall through to an empty "no posts found"
 *   blog-style render instead of the real, Polylang-linked Russian home
 *   page (which lives at a slugged sub-path, `pll_home_url( 'ru' )`).
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Stock_Page_Redirects
 */
class Nera_SAW_Stock_Page_Redirects {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 5 );
	}

	/**
	 * Single entry point for every redirect this class owns.
	 */
	public static function maybe_redirect() {
		if ( is_admin() ) {
			return;
		}

		if ( self::is_stock_cart_or_checkout() ) {
			self::redirect_nonempty_cart();
			return; // Cart/checkout and the bare-/ru/ check can never both be true.
		}

		self::maybe_redirect_bare_ru();
	}

	/**
	 * Whether the CURRENT request is the stock WooCommerce cart/checkout
	 * page specifically — the literal `woocommerce_cart_page_id`/
	 * `woocommerce_checkout_page_id` page, by `is_page()`, never
	 * `is_cart()`/`is_checkout()` themselves. Those two run through a
	 * `woocommerce_is_checkout` filter `Nera_SAW_Standalone_Basket::
	 * declare_checkout()` deliberately also returns true for — the
	 * plugin's OWN before-you-pay-adjacent checkout flow — so using them
	 * directly here would redirect that page to itself.
	 *
	 * @return bool
	 */
	private static function is_stock_cart_or_checkout() {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}
		$cart_id     = (int) wc_get_page_id( 'cart' );
		$checkout_id = (int) wc_get_page_id( 'checkout' );
		return ( $cart_id && is_page( $cart_id ) ) || ( $checkout_id && is_page( $checkout_id ) );
	}

	/**
	 * `/cart/` or `/checkout/`, only once the basket actually holds
	 * something.
	 */
	private static function redirect_nonempty_cart() {
		if ( ! class_exists( 'Nera_SAW_Standalone_Basket' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		if ( WC()->cart->is_empty() ) {
			return; // Nothing to review on Before you pay; the stock empty-cart/checkout page is harmless.
		}

		$to = Nera_SAW_Standalone_Basket::pre_payment_url();
		if ( $to ) {
			wp_safe_redirect( $to );
			exit;
		}
	}

	/**
	 * The bare `/ru/` language root, specifically — never a real sub-page
	 * under it (`/ru/anything/` already resolves to whatever real,
	 * Polylang-translated post that is, and is left alone).
	 */
	private static function maybe_redirect_bare_ru() {
		if ( ! function_exists( 'pll_home_url' ) ) {
			return;
		}

		global $wp;
		$request = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
		if ( 'ru' !== $request ) {
			return;
		}

		$to = pll_home_url( 'ru' );
		// Guard against a redirect loop should Polylang's own home URL ever
		// resolve back to this exact bare path on some other install.
		if ( $to && trim( (string) wp_parse_url( $to, PHP_URL_PATH ), '/' ) !== $request ) {
			wp_safe_redirect( $to );
			exit;
		}
	}
}
