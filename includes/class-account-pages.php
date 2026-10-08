<?php
/**
 * My Account — the new endpoints the client's "My runs and tickets" feedback
 * asked for: "My runs & tickets", "Draw results", "Responsible play".
 *
 * Every other row this plugin's My Account page already shows — Wallet,
 * Orders, "Manage my account" — is registered by a SIBLING plugin's own
 * `woocommerce_account_menu_items`/`add_rewrite_endpoint()` pair (woo-wallet,
 * nera-self-exclusion-plugin). This plugin never had a WooCommerce account
 * endpoint of its own before now; these three do, because the content behind
 * them — a player's own runs, tickets and draw results — is this plugin's
 * own data, not a sibling's.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Account_Pages
 */
class Nera_SAW_Account_Pages {

	/**
	 * Endpoint slugs, also the `woocommerce_account_{slug}_endpoint` action
	 * each one fires and the query var WooCommerce reads it from.
	 */
	const RUNS_TICKETS    = 'my-runs-tickets';
	const DRAW_RESULTS    = 'draw-results';
	const RESPONSIBLE_PLAY = 'responsible-play';

	/**
	 * Option flushed rewrite rules were generated against — bumped whenever
	 * a new endpoint is added, since an already-active install's rewrite
	 * rules were written before this class existed and activation (which
	 * would normally flush them) does not re-run on an existing install.
	 */
	const REWRITE_VERSION_OPTION = 'nera_saw_account_pages_rewrite_version';
	const REWRITE_VERSION        = 1;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoints' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ) );

		add_action( 'woocommerce_account_' . self::RUNS_TICKETS . '_endpoint', array( __CLASS__, 'render_runs_tickets' ) );
		add_action( 'woocommerce_account_' . self::DRAW_RESULTS . '_endpoint', array( __CLASS__, 'render_draw_results' ) );
		add_action( 'woocommerce_account_' . self::RESPONSIBLE_PLAY . '_endpoint', array( __CLASS__, 'render_responsible_play' ) );

		add_action( 'admin_init', array( __CLASS__, 'maybe_flush_rewrite_rules' ) );
	}

	/**
	 * Register the rewrite endpoints. Also called from the activation hook
	 * (see nera-strikeawin.php), so it must be safe to call standalone.
	 */
	public static function add_endpoints() {
		add_rewrite_endpoint( self::RUNS_TICKETS, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::DRAW_RESULTS, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::RESPONSIBLE_PLAY, EP_ROOT | EP_PAGES );
	}

	/**
	 * Flush once per rewrite-rule version bump — cheap no-op after the
	 * first run, same pattern `Nera_SAW_Play_Page::ensure_page()` already
	 * uses for an already-active install that never re-runs activation.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( (int) get_option( self::REWRITE_VERSION_OPTION, 0 ) >= self::REWRITE_VERSION ) {
			return;
		}

		self::add_endpoints();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION );
	}

	/**
	 * Register the endpoints as WooCommerce query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::RUNS_TICKETS ]     = self::RUNS_TICKETS;
		$vars[ self::DRAW_RESULTS ]     = self::DRAW_RESULTS;
		$vars[ self::RESPONSIBLE_PLAY ] = self::RESPONSIBLE_PLAY;

		return $vars;
	}

	/**
	 * Insert the three new rows into the My Account nav, right after
	 * Orders — ahead of Wallet/Account details, since "what did I win and
	 * what's still live" is the reason a player who just finished a run
	 * opens this page at all (client finding #2's own mock orders it this
	 * way: runs/tickets, then Draw results, then Responsible play, ahead of
	 * Account details).
	 *
	 * @param array $items Existing menu items.
	 * @return array
	 */
	public static function menu_items( $items ) {
		$new = array();

		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::RUNS_TICKETS ]     = __( 'My runs & tickets', 'nera-strikeawin' );
				$new[ self::DRAW_RESULTS ]     = __( 'Draw results', 'nera-strikeawin' );
				$new[ self::RESPONSIBLE_PLAY ] = __( 'Responsible play', 'nera-strikeawin' );
			}
		}

		// 'orders' missing entirely (e.g. filtered out upstream) — append
		// rather than silently dropping the three rows.
		if ( ! isset( $new[ self::RUNS_TICKETS ] ) ) {
			$new[ self::RUNS_TICKETS ]     = __( 'My runs & tickets', 'nera-strikeawin' );
			$new[ self::DRAW_RESULTS ]     = __( 'Draw results', 'nera-strikeawin' );
			$new[ self::RESPONSIBLE_PLAY ] = __( 'Responsible play', 'nera-strikeawin' );
		}

		return $new;
	}

	/**
	 * "My runs & tickets" endpoint content.
	 */
	public static function render_runs_tickets() {
		$path = NERA_SAW_PLUGIN_DIR . 'templates/woocommerce/myaccount/my-runs-tickets.php';
		if ( is_readable( $path ) ) {
			include $path;
		}
	}

	/**
	 * "Draw results" endpoint content.
	 */
	public static function render_draw_results() {
		$path = NERA_SAW_PLUGIN_DIR . 'templates/woocommerce/myaccount/draw-results.php';
		if ( is_readable( $path ) ) {
			include $path;
		}
	}

	/**
	 * "Responsible play" endpoint content.
	 */
	public static function render_responsible_play() {
		$path = NERA_SAW_PLUGIN_DIR . 'templates/woocommerce/myaccount/responsible-play.php';
		if ( is_readable( $path ) ) {
			include $path;
		}
	}
}
