<?php
/**
 * Client findings #4/#9/#50, reshaped by ADR 0032: two old stock URLs that no longer
 * have a page behind them, each sent to its real equivalent instead.
 *
 * - `/cart/`: WooCommerce's Cart setting points at Before you pay and the old
 *   stock cart page is in the trash, so the bare URL, still held by old links and
 *   bookmarks, goes there.
 *
 * - The bare `/ru/` language root (no further path): redirected to the section's
 *   home in Russian. `/ru/` alone does not resolve to any real post, and the
 *   Russian copy of the old home page it used to forward to is gone.
 *
 * The old `/checkout/` and `/my-account/` need nothing: those addresses now ARE the
 * section's Checkout and My account pages.
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

		self::maybe_redirect_bare_cart();
		self::maybe_redirect_bare_ru();
	}

	/**
	 * The old stock `/cart/` page is gone (ADR 0032): WooCommerce's Cart setting
	 * now points at Before you pay, and `/cart/` itself, still held by old links
	 * and bookmarks, goes there too.
	 */
	private static function maybe_redirect_bare_cart() {
		global $wp;
		$request = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
		if ( 'cart' !== $request || ! class_exists( 'Nera_SAW_Standalone_Basket' ) ) {
			return;
		}

		$to = Nera_SAW_Standalone_Basket::pre_payment_url();
		if ( $to ) {
			wp_safe_redirect( $to, 301 );
			exit;
		}
	}

	/**
	 * The bare `/ru/` language root, specifically — never a real sub-page
	 * under it (`/ru/anything/` already resolves to whatever real,
	 * Polylang-translated post that is, and is left alone).
	 *
	 * `/ru/` alone resolves to no page, and the Russian copy of the old home
	 * page it used to be sent to is gone (ADR 0032), so it goes to the
	 * section's own home in Russian.
	 */
	private static function maybe_redirect_bare_ru() {
		global $wp;
		$request = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
		if ( 'ru' !== $request || ! class_exists( 'Nera_SAW_Language' ) || ! Nera_SAW_Language::has( 'ru' ) ) {
			return;
		}

		wp_safe_redirect( add_query_arg( Nera_SAW_Language::SWITCH_ARG, 'ru', home_url( '/' ) ), 301 );
		exit;
	}
}
