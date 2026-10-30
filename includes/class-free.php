<?php
/**
 * Free competitions: a competition flagged free in its Strike And Win tab
 * (`_saw_is_free` = 1) is entered by one free quiz run per person, never bought.
 *
 * - It cannot be added to the basket, and a basket that already holds it cannot pay.
 * - Its competition page redirects to the Free page (route `free`), which shows the
 *   open free competition closing soonest (`current()`).
 * - Paid lists leave it out with `paid_only_clause()` in their meta query.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Free
 */
class Nera_SAW_Free {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'refuse_add_to_cart' ), 5, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_competition_page' ), 5 );
	}

	/**
	 * Meta query clause for paid competitions only: no free flag, or a flag that is not 1.
	 *
	 * @return array
	 */
	public static function paid_only_clause() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => Nera_SAW_Competition_Config::META_IS_FREE,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Nera_SAW_Competition_Config::META_IS_FREE,
				'value'   => '1',
				'compare' => '!=',
			),
		);
	}

	/**
	 * The open free competition closing soonest, or 0.
	 *
	 * @return int Product ID.
	 */
	public static function current() {
		static $id = null;
		if ( null !== $id ) {
			return $id;
		}
		$query = new WP_Query(
			array(
				'post_type'                           => 'product',
				'post_status'                         => 'publish',
				'posts_per_page'                      => 1,
				'no_found_rows'                       => true,
				'fields'                              => 'ids',
				'meta_key'                            => '_lty_end_date_gmt', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'                             => 'meta_value',
				'order'                               => 'ASC',
				Nera_SAW_Catalogue_Isolation::OPT_OUT => true,
				'meta_query'                          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => Nera_SAW_Competition_Config::META_IS_COMP,
						'value' => '1',
					),
					array(
						'key'   => Nera_SAW_Competition_Config::META_IS_FREE,
						'value' => '1',
					),
					array(
						'key'     => '_lty_end_date_gmt',
						'value'   => gmdate( 'Y-m-d H:i:s' ),
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
				),
			)
		);
		$id = $query->posts ? (int) $query->posts[0] : 0;
		return $id;
	}

	/**
	 * The Free page's URL, or '' when the page is missing or unpublished.
	 *
	 * @return string
	 */
	public static function page_url() {
		return class_exists( 'Nera_SAW_Standalone_Pages' ) ? (string) Nera_SAW_Standalone_Pages::url( 'free' ) : '';
	}

	/**
	 * Where 'Start the free challenge' goes: /free/join/?c=<id>.
	 *
	 * @param int $competition_id Free competition ID.
	 * @return string
	 */
	public static function join_url( $competition_id ) {
		$page = self::page_url();
		if ( '' === $page ) {
			return '';
		}
		$parts = explode( '?', $page, 2 );
		$url   = trailingslashit( $parts[0] ) . 'join/' . ( isset( $parts[1] ) ? '?' . $parts[1] : '' );
		return add_query_arg( 'c', (int) $competition_id, $url );
	}

	/**
	 * A free competition cannot be bought.
	 *
	 * @param bool $passed     Whether validation passed so far.
	 * @param int  $product_id Product being added.
	 * @return bool
	 */
	public static function refuse_add_to_cart( $passed, $product_id ) {
		if ( ! Nera_SAW_Competition_Config::is_free( $product_id ) ) {
			return $passed;
		}
		wc_add_notice( __( 'This is a free competition: it is entered by playing the free quiz, not bought.', 'nera-strikeawin' ), 'error' );
		return false;
	}

	/**
	 * A competition made free after it went into a basket blocks paying for it.
	 */
	public static function check_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$seen = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( ! $product_id || isset( $seen[ $product_id ] ) || ! Nera_SAW_Competition_Config::is_free( $product_id ) ) {
				continue;
			}
			$seen[ $product_id ] = true;
			$product             = wc_get_product( $product_id );
			wc_add_notice(
				sprintf(
					/* translators: %s: competition name */
					__( '%s is now a free competition and cannot be bought. Please remove it from your basket to continue.', 'nera-strikeawin' ),
					esc_html( $product ? $product->get_name() : __( 'This competition', 'nera-strikeawin' ) )
				),
				'error'
			);
		}
	}

	/**
	 * A free competition's own page is the Free page.
	 */
	public static function redirect_competition_page() {
		if ( ! class_exists( 'Nera_SAW_Router' ) || 'competition' !== Nera_SAW_Router::current_route() ) {
			return;
		}
		$slug = get_query_var( Nera_SAW_Router::QV_ARG );
		$post = $slug ? get_page_by_path( sanitize_title( $slug ), OBJECT, 'product' ) : null;
		if ( ! $post || ! Nera_SAW_Competition_Config::is_free( $post->ID ) ) {
			return;
		}
		$url = self::page_url();
		if ( '' === $url ) {
			return;
		}
		wp_safe_redirect( $url, 302 );
		exit;
	}
}
