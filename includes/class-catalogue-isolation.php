<?php
/**
 * Keep competitions out of the main site's catalogue — standalone mode only.
 *
 * In standalone the plugin serves its own section, and its products must not also
 * turn up in the shop, in search, among related products, or in the REST feeds a
 * headless front end reads. Mix mode is untouched: there, appearing in the
 * catalogue is the entire point.
 *
 * WHY NOT JUST pre_get_posts
 * --------------------------
 * `pre_get_posts` covers more than it looks like it does — the Store API, the
 * product shortcodes and the Gutenberg blocks all run a real `WP_Query`, so they
 * are caught as long as the hook does NOT restrict itself to `is_main_query()`.
 * That restriction is the usual mistake and it silently leaves three surfaces
 * showing what the rest of the site is hiding.
 *
 * Four things it still cannot reach, each hooked separately below: related
 * products (own query args), the WooCommerce REST controllers (own filter),
 * up-sells and cross-sells (queried by explicit ID), and the single-product
 * permalink itself, which stays perfectly reachable by URL.
 *
 * WHAT IS DELIBERATELY NOT FILTERED
 * ---------------------------------
 * The cart, checkout, orders and emails. They load a product through
 * `wc_get_product()`, which reads `get_post()` directly rather than running a
 * `WP_Query` — so hiding competitions from listings cannot break a basket that
 * already holds one. That is what makes this approach safe at all.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Catalogue_Isolation
 */
class Nera_SAW_Catalogue_Isolation {

	/**
	 * Query var a caller sets to opt out of the exclusion.
	 *
	 * The plugin's own listings are the obvious case: the standalone competitions
	 * page is a product query, and without a way to say "not me" this class would
	 * hide the plugin's products from the plugin's own pages.
	 */
	const OPT_OUT = 'nera_saw_include_competitions';

	/**
	 * Cache key for the competition ID list.
	 */
	const CACHE_KEY = 'nera_saw_competition_ids';

	/**
	 * Hooks. Everything here no-ops outside standalone mode.
	 */
	public static function init() {
		// Cache invalidation runs in every mode: the list must not be stale on the
		// day someone switches to standalone.
		add_action( 'save_post_product', array( __CLASS__, 'flush' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		add_action( 'trashed_post', array( __CLASS__, 'flush' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'flush' ) );

		/*
		 * `save_post_product` alone is not enough: a product is a competition
		 * because of its `_saw_is_competition` meta, and that meta is written by
		 * `Nera_SAW_Competition_Config::set_competition()` in a separate
		 * `update_post_meta()` call, after the post itself has already saved (and
		 * already fired `save_post_product`, recomputing this list -- correctly,
		 * without the product that is still one call away from being marked a
		 * competition). Found live, not assumed: a competition seeded second in
		 * the same request stayed cached as "not a competition" and kept leaking
		 * into the WooCommerce Store API's own product list. Watching the meta
		 * key directly closes the gap regardless of which code path sets it.
		 */
		add_action( 'updated_post_meta', array( __CLASS__, 'flush_on_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'flush_on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'flush_on_meta' ), 10, 3 );

		if ( ! Nera_SAW_Mode::is_standalone() ) {
			return;
		}

		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_from_queries' ) );
		add_filter( 'woocommerce_product_related_posts_query', array( __CLASS__, 'exclude_from_related' ), 10, 1 );
		add_filter( 'woocommerce_rest_product_object_query', array( __CLASS__, 'exclude_from_rest' ), 10, 1 );
		add_filter( 'woocommerce_product_get_upsell_ids', array( __CLASS__, 'exclude_ids' ), 10, 1 );
		add_filter( 'woocommerce_product_get_cross_sell_ids', array( __CLASS__, 'exclude_ids' ), 10, 1 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'exclude_from_sitemap' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_single' ) );
	}

	/* ---------------------------------------------------------------------
	 * The list
	 * ------------------------------------------------------------------ */

	/**
	 * Every competition product ID.
	 *
	 * Cached because this runs on most front-end queries. A `post__not_in` of a few
	 * dozen IDs beats a `meta_query` on every shop page — the meta approach adds a
	 * postmeta join to queries that otherwise have none, for a list that changes
	 * about as often as somebody adds a competition.
	 *
	 * @return int[]
	 */
	public static function competition_ids() {
		$ids = get_transient( self::CACHE_KEY );
		if ( is_array( $ids ) ) {
			return $ids;
		}

		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
				Nera_SAW_Competition_Config::META_IS_COMP
			)
		);
		$ids = array_map( 'intval', (array) $ids );

		set_transient( self::CACHE_KEY, $ids, DAY_IN_SECONDS );
		return $ids;
	}

	/**
	 * Drop the cached list.
	 *
	 * @param int $post_id Post being saved or removed (unused; the list is whole).
	 */
	public static function flush( $post_id = 0 ) {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Drop the cached list when the one meta key that defines it changes,
	 * regardless of which object type it was written against — a plain
	 * `update_post_meta()` call fires no `save_post_*` action of its own.
	 *
	 * @param int|array $meta_id   Meta row ID (or IDs, for a delete).
	 * @param int       $object_id Post the meta belongs to (unused; the list is whole).
	 * @param string    $meta_key  Meta key written.
	 */
	public static function flush_on_meta( $meta_id, $object_id, $meta_key ) {
		if ( Nera_SAW_Competition_Config::META_IS_COMP === $meta_key ) {
			self::flush();
		}
	}

	/* ---------------------------------------------------------------------
	 * Query exclusion
	 * ------------------------------------------------------------------ */

	/**
	 * Should this query have competitions removed from it?
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	private static function applies( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return false;
		}

		// An administrator must still be able to find and edit a competition.
		// wp_doing_ajax() is checked because admin-ajax runs with is_admin() true
		// while serving the front end, and the reverse mistake hides products from
		// front-end AJAX that should show them.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		// The plugin's own listings say so explicitly.
		if ( $query->get( self::OPT_OUT ) ) {
			return false;
		}

		// A single product is handled by redirect_single(), not by filtering: a
		// filtered query 404s the URL, which loses the redirect and tells a search
		// engine the page is gone rather than moved.
		if ( $query->is_singular ) {
			return false;
		}

		$types = (array) $query->get( 'post_type' );
		if ( ! $types || array( '' ) === $types ) {
			// A search or an untyped archive can still return products.
			$types = $query->is_search() ? array( 'any' ) : array();
		}

		if ( ! in_array( 'product', $types, true ) && ! in_array( 'any', $types, true ) ) {
			return false;
		}

		/**
		 * Filter whether a query has competitions excluded from it.
		 *
		 * @param bool     $applies Whether to exclude.
		 * @param WP_Query $query   The query.
		 */
		return (bool) apply_filters( 'nera_saw_isolate_query', true, $query );
	}

	/**
	 * Remove competitions from product listings.
	 *
	 * Deliberately NOT limited to the main query. The Store API, the product
	 * shortcodes and the product blocks each run their own `WP_Query`, and an
	 * `is_main_query()` guard would leave all three showing what the shop hides.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function exclude_from_queries( $query ) {
		if ( ! self::applies( $query ) ) {
			return;
		}

		$ids = self::competition_ids();
		if ( ! $ids ) {
			return;
		}

		// Merge rather than assign: another plugin may already be excluding
		// something, and overwriting its list would silently undo that.
		$existing = (array) $query->get( 'post__not_in' );
		$query->set( 'post__not_in', array_values( array_unique( array_merge( $existing, $ids ) ) ) );
	}

	/**
	 * Remove competitions from the related-products query.
	 *
	 * @param array $args Query pieces WooCommerce assembled.
	 * @return array
	 */
	public static function exclude_from_related( $args ) {
		$ids = self::competition_ids();
		if ( ! $ids || ! is_array( $args ) ) {
			return $args;
		}

		global $wpdb;
		$list = implode( ',', array_map( 'absint', $ids ) );

		// The related-products query is assembled as SQL fragments, not query vars,
		// so the exclusion has to be appended as one.
		$args['where'] = ( isset( $args['where'] ) ? $args['where'] : '' ) . " AND p.ID NOT IN ({$list})";

		return $args;
	}

	/**
	 * Remove competitions from the WooCommerce REST product endpoints.
	 *
	 * @param array $args WP_Query args.
	 * @return array
	 */
	public static function exclude_from_rest( $args ) {
		$ids = self::competition_ids();
		if ( ! $ids ) {
			return $args;
		}
		$existing             = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
		$args['post__not_in'] = array_values( array_unique( array_merge( $existing, $ids ) ) );
		return $args;
	}

	/**
	 * Remove competitions from an explicit ID list (up-sells, cross-sells).
	 *
	 * These are chosen by hand on another product and queried by ID, so no query
	 * filter reaches them.
	 *
	 * @param array $ids Product IDs.
	 * @return array
	 */
	public static function exclude_ids( $ids ) {
		$competitions = self::competition_ids();
		if ( ! $competitions || ! is_array( $ids ) ) {
			return $ids;
		}
		return array_values( array_diff( array_map( 'intval', $ids ), $competitions ) );
	}

	/**
	 * Keep competitions out of the WordPress sitemap.
	 *
	 * @param array  $args      Query args.
	 * @param string $post_type Post type being listed.
	 * @return array
	 */
	public static function exclude_from_sitemap( $args, $post_type ) {
		if ( 'product' !== $post_type ) {
			return $args;
		}
		return self::exclude_from_rest( $args );
	}

	/* ---------------------------------------------------------------------
	 * The permalink
	 * ------------------------------------------------------------------ */

	/**
	 * Send the main site's product URL to the standalone page for that competition.
	 *
	 * Hiding a product from every listing does not unpublish it: the permalink still
	 * resolves, and it is in every link anyone shared before the switch. A redirect
	 * keeps those working and points search engines at the page that now exists.
	 *
	 * 301, not 302: in standalone the main-site URL is not coming back.
	 */
	public static function redirect_single() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$id = (int) get_queried_object_id();
		if ( ! $id || ! Nera_SAW_Competition_Config::is_competition( $id ) ) {
			return;
		}

		/**
		 * Filter where a competition's main-site URL sends the visitor.
		 *
		 * Empty means do not redirect — which is what happens until the standalone
		 * routes exist, so this class can ship before they do without breaking a
		 * live product page.
		 *
		 * @param string $url            Destination.
		 * @param int    $competition_id Competition product ID.
		 */
		$url = apply_filters( 'nera_saw_standalone_competition_url', '', $id );
		if ( ! $url ) {
			return;
		}

		wp_safe_redirect( $url, 301 );
		exit;
	}
}
