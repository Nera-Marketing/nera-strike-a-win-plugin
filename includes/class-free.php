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
	 * The tier a free run is played on: Classic (`standard`), or the competition's
	 * first tier when Classic is switched off for it.
	 */
	const FREE_TIER = 'standard';

	/**
	 * Query arg carrying a refused start back to the Free page.
	 */
	const QV_REFUSED = 'saw_free';

	/**
	 * Refusal codes and their messages.
	 *
	 * @return array<string, string> Code (without the `saw_free_` prefix) => message.
	 */
	public static function refusals() {
		return array(
			'used'       => Nera_SAW_I18n::t( 'You have already had your free entry for this competition. One free quiz per person.' ),
			'phone_used' => Nera_SAW_I18n::t( 'This mobile number has already been used for a free entry in this competition. One free quiz per person.' ),
			'closing'    => Nera_SAW_I18n::t( 'This competition closes too soon to play a full quiz, so free entries are closed.' ),
			'closed'     => Nera_SAW_I18n::t( 'This free competition is not open.' ),
			'full'       => Nera_SAW_I18n::t( 'All tickets in this free competition have been given out.' ),
			'unverified' => Nera_SAW_I18n::t( 'Confirm your email address before you start the free challenge.' ),
		);
	}

	/**
	 * The message for a start refused on this request (query arg on the Free page), or ''.
	 *
	 * @return string
	 */
	public static function refusal() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$code = isset( $_GET[ self::QV_REFUSED ] ) ? sanitize_key( wp_unslash( $_GET[ self::QV_REFUSED ] ) ) : '';
		$all  = self::refusals();
		return isset( $all[ $code ] ) ? $all[ $code ] : '';
	}

	/**
	 * Start a verified player's one free run on a free competition: create the free
	 * grant (the briefing then offers its single run).
	 *
	 * Refused, with no grant, when the account already has a free grant for this
	 * competition (any status), when another account with the same mobile has one,
	 * or when the competition closes before a full run could finish.
	 *
	 * @param int $user_id        User ID.
	 * @param int $competition_id Free competition ID.
	 * @return int|WP_Error Grant ID, or WP_Error `saw_free_<code>` (see refusals()).
	 */
	public static function start( $user_id, $competition_id ) {
		$user_id        = (int) $user_id;
		$competition_id = (int) $competition_id;
		$fail           = static function ( $code ) {
			$all = self::refusals();
			return new WP_Error( 'saw_free_' . $code, $all[ $code ], array( 'status' => 403 ) );
		};

		if ( $user_id < 1 || ! Nera_SAW_Free_Signup::is_verified( $user_id ) ) {
			return $fail( 'unverified' );
		}
		if ( ! Nera_SAW_Competition_Config::is_competition( $competition_id ) || ! Nera_SAW_Competition_Config::is_free( $competition_id ) || 'publish' !== get_post_status( $competition_id ) ) {
			return $fail( 'closed' );
		}
		if ( Nera_SAW_Run_Grants::free_grant( $user_id, $competition_id ) ) {
			return $fail( 'used' );
		}
		$phone = (string) get_user_meta( $user_id, Nera_SAW_Free_Signup::META_PHONE, true );
		if ( Nera_SAW_Run_Grants::free_phone_used( $phone, $competition_id, $user_id ) ) {
			return $fail( 'phone_used' );
		}

		$config = Nera_SAW_Competition_Config::get( $competition_id );
		$end    = (string) get_post_meta( $competition_id, '_lty_end_date_gmt', true );
		if ( '' !== $end ) {
			$closes = strtotime( $end . ' UTC' );
			$window = Nera_SAW_Run::run_window_seconds( $config, Nera_SAW_Competition_Config::total_questions( $config ) );
			if ( $closes <= time() ) {
				return $fail( 'closed' );
			}
			if ( $closes - time() < $window ) {
				return $fail( 'closing' );
			}
		}

		$tier = self::tier( $config );
		if ( '' === $tier ) {
			return $fail( 'closed' );
		}
		$grant_id = Nera_SAW_Run_Grants::grant_free( $user_id, $competition_id, $tier, $config );
		if ( is_wp_error( $grant_id ) ) {
			return $fail( 'saw_free_full' === $grant_id->get_error_code() ? 'full' : 'used' );
		}

		/**
		 * Fires right after a free grant is created.
		 *
		 * @param int $grant_id       Grant ID.
		 * @param int $user_id        User ID.
		 * @param int $competition_id Free competition ID.
		 */
		do_action( 'nera_saw_free_grant_created', $grant_id, $user_id, $competition_id );

		return $grant_id;
	}

	/**
	 * The tier key a free run uses on this competition, or '' when it has none.
	 *
	 * @param array $config Competition config.
	 * @return string
	 */
	public static function tier( array $config ) {
		$tiers = isset( $config['tiers'] ) ? (array) $config['tiers'] : array();
		foreach ( $tiers as $t ) {
			if ( self::FREE_TIER === (string) $t['key'] ) {
				return self::FREE_TIER;
			}
		}
		return $tiers ? (string) $tiers[0]['key'] : '';
	}

	/**
	 * Where 'Start the free challenge' sends a verified, logged-in player: the briefing
	 * of their free run, or the Free page with the refusal.
	 *
	 * @param int $user_id        User ID.
	 * @param int $competition_id Competition asked for (0 = the current free competition).
	 * @return string URL.
	 */
	public static function start_url( $user_id, $competition_id ) {
		$competition_id = $competition_id ? (int) $competition_id : self::current();
		$started        = self::start( $user_id, $competition_id );
		if ( is_wp_error( $started ) ) {
			return add_query_arg( self::QV_REFUSED, substr( $started->get_error_code(), strlen( 'saw_free_' ) ), self::page_url() );
		}
		$config = Nera_SAW_Competition_Config::get( $competition_id );
		return Nera_SAW_Play_Page::url( $competition_id, self::tier( $config ) );
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
