<?php
/**
 * Single-product frontend for Strike A Win competitions.
 *
 * On a competition product page this:
 *  - swaps the theme's price/quantity block for tier tabs (JS/DOM), where the
 *    selected tab drives the displayed price and the tier used at checkout, and
 *    the quantity selector means "number of runs to buy";
 *  - shows a runs-to-play button inside the purchase card when the logged-in
 *    user already holds runs for this competition (links to the play page).
 *
 * The theme is not edited: everything is injected by an enqueued script that
 * targets the theme's purchase-card DOM. Add-to-cart uses the standard WooCommerce
 * GET handler (?add-to-cart=&quantity=&saw_tier=), which Nera_SAW_Cart_Entry reads.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Product_Frontend
 */
class Nera_SAW_Product_Frontend {

	const HANDLE = 'nera-saw-product-tiers';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * The competition product for the current single-product view, or null.
	 *
	 * @return WC_Product|null
	 */
	private static function current_competition() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return null;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product || ! Nera_SAW_Competition_Config::is_competition( $product->get_id() ) ) {
			return null;
		}
		return $product;
	}

	/**
	 * Tag the body so critical CSS can hide the native price/quantity until the
	 * tier widget is built (limits the swap flash).
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public static function body_class( $classes ) {
		if ( Nera_SAW_Constants::frontend_ui_enabled() && self::current_competition() ) {
			$classes[] = 'saw-competition';
		}
		return $classes;
	}

	/**
	 * Enqueue + localise the tier widget on competition product pages.
	 */
	public static function enqueue() {
		if ( ! Nera_SAW_Constants::frontend_ui_enabled() ) {
			return;
		}
		$product = self::current_competition();
		if ( ! $product ) {
			return;
		}
		$competition_id = (int) $product->get_id();
		$config         = Nera_SAW_Competition_Config::get( $competition_id );

		$pool      = Nera_SAW_Spin_Pool::get( $competition_id );
		$pool_open = ! $pool || 'open' === $pool->status;
		$available = $pool ? (int) $pool->available : PHP_INT_MAX;

		$tiers = array();
		foreach ( (array) $config['tiers'] as $tier ) {
			$key    = (string) $tier['key'];
			$max    = (int) Nera_SAW_Competition_Config::max_possible_spins( $config, $key );
			$status = Nera_SAW_Competition_Config::tier_ceiling_status( $config, $competition_id, $key );
			$fits   = ( $available >= $max );
			$offered = $pool_open && $fits && (bool) $status['offered'];

			$tiers[] = array(
				'key'       => $key,
				'label'     => (string) $tier['label'],
				'price'     => (float) $tier['price'],
				'priceHtml' => wc_price( (float) $tier['price'] ),
				'max'       => $max,
				'offered'   => $offered,
				'note'      => self::tier_note( $offered, $pool_open, $fits, $max ),
			);
		}

		$user_id    = get_current_user_id();
		$runs_total = $user_id ? (int) Nera_SAW_Run_Grants::balance_total( $user_id, $competition_id ) : 0;

		wp_register_script( self::HANDLE, NERA_SAW_PLUGIN_URL . 'assets/js/product-tiers.js', array(), NERA_SAW_VERSION, true );
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( 'nera-saw-product', NERA_SAW_PLUGIN_URL . 'assets/css/product.css', array(), NERA_SAW_VERSION );

		wp_localize_script(
			self::HANDLE,
			'NeraSAWProduct',
			array(
				'version'       => NERA_SAW_VERSION,
				'competitionId' => $competition_id,
				'productName'   => $product->get_name(),
				'tiers'         => $tiers,
				'addToCartBase' => wc_get_cart_url(),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'cartUrl'       => wc_get_cart_url(),
				'poolOpen'      => (bool) $pool_open,
				'isLoggedIn'    => (bool) $user_id,
				'loginUrl'      => wp_login_url( get_permalink( $competition_id ) ),
				'runsTotal'     => $runs_total,
				'playUrl'       => nera_saw_get_play_url( $competition_id ),
				'i18n'          => array(
					'chooseTier'   => __( 'Choose your entry tier', 'nera-strikeawin' ),
					'runs'         => __( 'runs', 'nera-strikeawin' ),
					'perRun'       => __( '/ run', 'nera-strikeawin' ),
					'upTo'         => __( 'up to %d tickets', 'nera-strikeawin' ),
					'total'        => __( 'Total', 'nera-strikeawin' ),
					'addToCart'    => __( 'Add to cart', 'nera-strikeawin' ),
					'adding'       => __( 'Adding…', 'nera-strikeawin' ),
					'added'        => __( 'Added to cart.', 'nera-strikeawin' ),
					'addFailed'    => __( 'Could not add to cart. Please try again.', 'nera-strikeawin' ),
					'viewCart'     => __( 'View cart', 'nera-strikeawin' ),
					'loginToBuy'   => __( 'Log in to enter', 'nera-strikeawin' ),
					'closed'       => __( 'Competition closed', 'nera-strikeawin' ),
					'runsToPlay'   => __( '%d runs to play', 'nera-strikeawin' ),
					'runToPlay'    => __( '1 run to play', 'nera-strikeawin' ),
					'playQuiz'     => __( 'Play quiz', 'nera-strikeawin' ),
				),
			)
		);
	}

	/**
	 * Short availability note for a tier.
	 *
	 * @param bool $offered   Whether the tier is selectable.
	 * @param bool $pool_open Whether the competition pool is open.
	 * @param bool $fits      Whether a full run still fits the remaining pool.
	 * @param int  $max       Max tickets per run at this tier.
	 * @return string
	 */
	private static function tier_note( $offered, $pool_open, $fits, $max ) {
		if ( ! $pool_open ) {
			return __( 'Competition closed', 'nera-strikeawin' );
		}
		if ( ! $fits ) {
			return __( 'Sold out at this tier', 'nera-strikeawin' );
		}
		return sprintf(
			/* translators: %d: maximum tickets a perfect run can win at this tier. */
			__( 'Up to %d tickets per run', 'nera-strikeawin' ),
			(int) $max
		);
	}
}
