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
 * - The five old, top-level legal/help pages (Terms and Conditions,
 *   Privacy Policy, Complaints, Help and support, Our commitment to
 *   player protection): 301'd to their new standalone-section mirror —
 *   see `Nera_SAW_Standalone_Legal_Pages` for where those live and why
 *   each one only exists once its own content source does. The OLD page
 *   is never touched (no trash, no content change) — it is still exactly
 *   what the new page mirrors/calls into; only the bare URL now forwards
 *   to the skinned one.
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
			return; // Cart/checkout and the other checks can never both be true.
		}

		self::maybe_redirect_legal_page();
		self::maybe_redirect_bare_ru();
	}

	/**
	 * Old top-level slug (or, for Privacy Policy, whatever page the stock
	 * `wp_page_for_privacy_policy` option names) => the standalone registry
	 * route that now mirrors/calls into it. Resolved fresh on every request
	 * rather than cached, since which of the three plugin-backed routes
	 * even exist depends on which sibling plugins are active right now.
	 *
	 * @return array<int,string> Old page ID => route key.
	 */
	private static function legal_page_redirect_map() {
		$map = array();

		$by_slug = array(
			'terms-and-conditions'                 => 'terms-and-conditions',
			'complaints'                            => 'complaints',
			'help-and-support'                       => 'help-and-support',
			'our-commitment-to-player-protection'   => 'player-protection',
		);
		foreach ( $by_slug as $slug => $route ) {
			$page = get_page_by_path( $slug );
			if ( $page ) {
				$map[ $page->ID ] = $route;
			}
		}

		// Not a slug — whichever page WordPress's own Privacy Policy
		// setting currently names.
		$privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $privacy_id ) {
			$map[ $privacy_id ] = 'privacy-policy';
		}

		return $map;
	}

	/**
	 * One of the five old top-level pages above -> its new standalone
	 * mirror, IF that mirror is actually registered right now (the three
	 * plugin-backed ones only exist while their own plugin is active — see
	 * `Nera_SAW_Standalone_Legal_Pages::add_pages()`). No mirror yet (or
	 * not in standalone mode at all, where `url()` always returns '')
	 * leaves the old page rendering exactly as it always has, rather than
	 * redirecting somewhere empty.
	 */
	private static function maybe_redirect_legal_page() {
		if ( ! is_page() || ! class_exists( 'Nera_SAW_Standalone_Pages' ) ) {
			return;
		}

		$map   = self::legal_page_redirect_map();
		$route = isset( $map[ get_queried_object_id() ] ) ? $map[ get_queried_object_id() ] : '';
		if ( '' === $route ) {
			return;
		}

		$to = Nera_SAW_Standalone_Pages::url( $route );
		if ( $to ) {
			wp_safe_redirect( $to, 301 );
			exit;
		}
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
