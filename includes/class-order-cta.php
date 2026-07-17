<?php
/**
 * Post-purchase play CTAs (thank-you + My Account order view).
 *
 * Renders one merged "runs to play" card per Strike A Win competition on the
 * order (tiers collapsed), styled like the single-product CTA
 * (assets/css/order-cta.css): a count badge (the competition's total remaining
 * runs) + "N runs to play" + "Play quiz", linking to the competition play page
 * where the player picks a tier. Shown only while runs remain.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Order_Cta
 */
class Nera_SAW_Order_Cta {

	const STYLE_HANDLE = 'nera-saw-order-cta';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! Nera_SAW_Constants::frontend_ui_enabled() ) {
			return;
		}
		// Below woocommerce_order_details_table (priority 10), same as Spin-to-Win.
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou_cta' ), 5, 1 );
		add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'view_order_cta' ), 5, 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	/**
	 * Enqueue the play-card styles on the order-received + view-order pages.
	 */
	public static function enqueue_styles() {
		if ( ! function_exists( 'is_order_received_page' ) ) {
			return;
		}
		$is_order_page = is_order_received_page()
			|| ( is_account_page() && is_wc_endpoint_url( 'view-order' ) );
		if ( ! $is_order_page ) {
			return;
		}
		wp_enqueue_style( self::STYLE_HANDLE, NERA_SAW_PLUGIN_URL . 'assets/css/order-cta.css', array(), NERA_SAW_VERSION );
	}

	/**
	 * Play links for the Strike A Win competitions on an order — one merged card
	 * per competition (tiers collapsed).
	 *
	 * The badge number is the competition's total remaining runs across all the
	 * user's orders (Nera_SAW_Run_Grants::balance_total), and the link points at
	 * the competition play page (no tier), where the player picks a tier.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{url: string, runs: int, name: string}> Keyed by competition ID.
	 */
	private static function collect_play_links_for_order( $order ) {
		if ( ! $order instanceof WC_Order || ! function_exists( 'nera_saw_get_play_url' ) ) {
			return array();
		}

		$user_id = (int) $order->get_user_id();
		if ( $user_id < 1 ) {
			return array();
		}

		// Idempotent — ensures runs exist before we check balances on thank-you.
		Nera_SAW_Run_Grants::grant_for_order( (int) $order->get_id() );

		$links = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$competition_id = (int) $item->get_product_id();
			if ( ! Nera_SAW_Competition_Config::is_competition( $competition_id ) ) {
				continue;
			}

			// One card per competition — skip further tier lines once added.
			if ( isset( $links[ $competition_id ] ) ) {
				continue;
			}

			if ( '' === (string) $item->get_meta( Nera_SAW_Reservations::ITEM_TIER ) ) {
				continue;
			}

			$runs = (int) Nera_SAW_Run_Grants::balance_total( $user_id, $competition_id );
			if ( $runs < 1 ) {
				continue;
			}

			$url = nera_saw_get_play_url( $competition_id );
			if ( '' === $url ) {
				continue;
			}

			$product = $item->get_product();
			$name    = $product ? $product->get_name() : get_the_title( $competition_id );

			$links[ $competition_id ] = array(
				'url'  => $url,
				'runs' => $runs,
				'name' => (string) $name,
			);
		}

		return $links;
	}

	/**
	 * Human label for a run count ("1 run to play" / "%d runs to play").
	 *
	 * @param int $runs Run count.
	 * @return string
	 */
	private static function runs_label( $runs ) {
		$runs = (int) $runs;
		if ( 1 === $runs ) {
			return __( '1 run to play', 'nera-strikeawin' );
		}
		return sprintf(
			/* translators: %d: run count */
			__( '%d runs to play', 'nera-strikeawin' ),
			$runs
		);
	}

	/**
	 * Render the play cards — one merged card per competition, styled like the
	 * single-product "runs to play" CTA: a count badge (the competition's total
	 * remaining runs) + "{Competition} — N runs to play" + "Play quiz".
	 *
	 * @param array<int, array{url: string, runs: int, name: string}> $links   Play links.
	 * @param bool                                                     $compact Shrink cards to content (thank-you page).
	 */
	private static function render_cards( array $links, $compact = false ) {
		if ( empty( $links ) ) {
			return;
		}

		$action  = esc_html__( 'Play quiz', 'nera-strikeawin' );
		$wrap_cls = 'saw-order-ctas' . ( $compact ? ' saw-order-ctas--compact' : '' );

		echo '<div class="' . esc_attr( $wrap_cls ) . '">';
		foreach ( $links as $row ) {
			$runs       = (int) $row['runs'];
			$name       = isset( $row['name'] ) ? (string) $row['name'] : '';
			$runs_label = self::runs_label( $runs );
			$runs_text  = '' !== $name
				? sprintf(
					/* translators: 1: competition name, 2: "N runs to play". */
					__( '%1$s — %2$s', 'nera-strikeawin' ),
					$name,
					$runs_label
				)
				: $runs_label;

			echo '<a class="saw-runs-cta" href="' . esc_url( $row['url'] ) . '" aria-label="' . esc_attr( $runs_text . ' — ' . $action ) . '">';
			echo '<span class="saw-runs-cta__count" aria-hidden="true">' . esc_html( (string) $runs ) . '</span>';
			echo '<span class="saw-runs-cta__copy">';
			echo '<span class="saw-runs-cta__runs">' . esc_html( $runs_text ) . '</span>';
			echo '<span class="saw-runs-cta__action">' . $action . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
			echo '</span>';
			echo '</a>';
		}
		echo '</div>';
	}

	/**
	 * Thank-you page CTA.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function thankyou_cta( $order_id ) {
		$order_id = absint( $order_id );
		$order    = wc_get_order( $order_id );
		if ( ! $order || ! $order->has_status( apply_filters( 'nera_saw_thankyou_allowed_statuses', array( 'processing', 'completed' ) ) ) ) {
			return;
		}

		if ( ! is_user_logged_in() || (int) $order->get_user_id() !== get_current_user_id() ) {
			return;
		}

		self::render_cards( self::collect_play_links_for_order( $order ), true );
	}

	/**
	 * My Account → view order CTA (guarded so thank-you does not duplicate).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function view_order_cta( $order ) {
		if ( ! is_user_logged_in() || ! is_account_page() || ! is_wc_endpoint_url( 'view-order' ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( (int) $order->get_user_id() !== get_current_user_id() ) {
			return;
		}

		$allowed = apply_filters( 'nera_saw_view_order_allowed_statuses', array( 'processing', 'completed', 'on-hold' ) );
		if ( ! $order->has_status( $allowed ) ) {
			return;
		}

		self::render_cards( self::collect_play_links_for_order( $order ) );
	}
}
