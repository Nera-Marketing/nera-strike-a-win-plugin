<?php
/**
 * Platform integrations for Strike A Win competitions.
 *
 * Section 9: a quiz entry must not mint lottery tickets on purchase — tickets are
 * earned only by the quiz (generated at run finalize by Nera_SAW_Ticket_Award).
 * We return false on `lty_validate_order_item_ticket_numbers` for competition
 * products so LFW does not assign ticket numbers at order time.
 *
 * (The Spin-to-Win pre-claim suppression was removed when STW was dropped from
 * the core — see ADR 0002.)
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Integrations
 */
class Nera_SAW_Integrations {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'lty_validate_order_item_ticket_numbers', array( __CLASS__, 'suppress_purchase_tickets' ), 20, 2 );
		add_filter( 'nera_lottery_product_data', array( __CLASS__, 'filter_lottery_product_data' ), 10, 2 );
		// Tailor the reused nera-lottery-async ticket confirmation email for
		// quiz-earned tickets (fires during Ticket_Award confirmation).
		add_filter( 'nera_lty_async_ticket_email_body', array( __CLASS__, 'filter_email_body' ), 10, 3 );

		// Block LFW from minting lottery tickets ON PURCHASE for competition orders.
		// Section 9: a competition ticket is minted ONLY by winning the quiz
		// (Nera_SAW_Ticket_Award), never bought. The `lty_validate_*` filter above
		// only gates *display*, not creation — LFW's create_ticket_for_order_item()
		// mints on order-paid regardless. We pre-set the order's
		// `lty_lottery_ticket_created_once` flag (before LFW's priority-10 handler)
		// so that method early-returns for the whole order. Priority 1.
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed', 'woocommerce_order_status_on-hold' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'block_purchase_minting' ), 1, 1 );
		}
	}

	/**
	 * Suppress LFW ticket-number assignment on purchase for competition products.
	 *
	 * @param bool       $valid   Whether LFW should assign ticket numbers now.
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function suppress_purchase_tickets( $valid, $product ) {
		if ( $product instanceof WC_Product && Nera_SAW_Competition_Config::is_competition( $product->get_id() ) ) {
			return false;
		}
		return $valid;
	}

	/**
	 * For Strike A Win competitions, "tickets sold" = quiz-earned total (not raw LFW posts).
	 *
	 * @param array      $data    Lottery product payload from the theme.
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function filter_lottery_product_data( $data, $product ) {
		if ( ! is_array( $data ) || ! ( $product instanceof WC_Product ) ) {
			return $data;
		}
		$product_id = (int) $product->get_id();
		if ( ! Nera_SAW_Competition_Config::is_competition( $product_id ) ) {
			return $data;
		}

		$sold = self::public_tickets_sold_count( $product_id );
		$max  = isset( $data['maxTickets'] ) ? (int) $data['maxTickets'] : 0;

		$data['soldTickets']       = $sold;
		$data['remainingTickets']  = $max > 0 ? max( 0, $max - $sold ) : (int) ( $data['remainingTickets'] ?? 0 );
		$data['progress']          = $max > 0 ? min( 100, (int) round( ( $sold / $max ) * 100 ) ) : (int) ( $data['progress'] ?? 0 );

		return $data;
	}

	/**
	 * Authoritative sold count for a Strike A Win competition.
	 *
	 * @param int $competition_id Product ID.
	 * @return int
	 */
	public static function public_tickets_sold_count( $competition_id ) {
		return Nera_SAW_Competition_Config::tickets_won_total( (int) $competition_id );
	}

	/**
	 * Keep LFW's purchased-count transient aligned with quiz-earned totals.
	 *
	 * @param int $competition_id Product ID.
	 */
	public static function sync_public_sold_count( $competition_id ) {
		$competition_id = (int) $competition_id;
		if ( $competition_id < 1 || ! Nera_SAW_Competition_Config::is_competition( $competition_id ) ) {
			return;
		}
		$sold = self::public_tickets_sold_count( $competition_id );
		delete_transient( 'lty_purchased_ticket_count_' . $competition_id );
		set_transient( 'lty_purchased_ticket_count_' . $competition_id, $sold, HOUR_IN_SECONDS );
		if ( class_exists( 'LTY_Transient_Handler' ) && method_exists( 'LTY_Transient_Handler', 'delete_all_transients' ) ) {
			LTY_Transient_Handler::delete_all_transients( $competition_id, 0 );
		}
	}

	/**
	 * Prevent LFW from minting tickets on purchase for orders containing a Strike A
	 * Win competition. Sets `lty_lottery_ticket_created_once` before LFW's order
	 * handler runs so no tickets are created at checkout; the quiz-win flow mints
	 * (and confirms) them later. NOT setting `lty_lottery_ticket_updated_once` — so
	 * the win-time confirmation can still advance the earned tickets to buyer.
	 *
	 * Caveat: this is order-level. A single order mixing a competition with a
	 * normal (non-competition) lottery product would also block the normal one;
	 * competition entries are expected to be their own order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function block_purchase_minting( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		$has_competition = false;
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && Nera_SAW_Competition_Config::is_competition( (int) $item->get_product_id() ) ) {
				$has_competition = true;
				break;
			}
		}
		if ( ! $has_competition ) {
			return;
		}
		if ( ! $order->get_meta( 'lty_lottery_ticket_created_once' ) ) {
			$order->update_meta_data( 'lty_lottery_ticket_created_once', '1' );
			$order->save();
		}
	}

	/**
	 * Prepend quiz-earned copy to the async ticket confirmation email body when
	 * the order's tickets were earned via the Strike A Win quiz.
	 *
	 * @param string   $body    Email body HTML (before the WooCommerce wrapper).
	 * @param WC_Order $order   Order.
	 * @param bool     $has_win Whether the order contains an instant win (unused).
	 * @return string
	 */
	public static function filter_email_body( $body, $order, $has_win ) {
		unset( $has_win );
		if ( ! ( $order instanceof WC_Order ) ) {
			return $body;
		}
		if ( 'yes' !== $order->get_meta( Nera_SAW_Ticket_Award::ORDER_FLAG_EARNED ) ) {
			return $body;
		}

		$note = '<p style="background:#eef6ff;border:1px solid #b8daff;border-radius:8px;padding:12px 16px;margin:0 0 16px;">'
			. esc_html__( 'You earned these tickets by playing the Strike A Win quiz — they were not purchased. They are now entered into the draw. Good luck!', 'nera-strikeawin' )
			. '</p>';

		return $note . $body;
	}
}
