<?php
/**
 * Award earned LFW tickets at run finalize (replaces the Spin-to-Win wheel grant).
 *
 * The quiz-earned COUNT (not the order quantity) of ticket numbers is picked at
 * random from the competition's remaining LFW pool, written to the order line
 * item using the same meta contract nera-lottery-async's prepick uses
 * (`_lty_lottery_tickets` / `lty_lottery_tickets` + `_lty_hold_tickets`), then the
 * async bulk generator mints them — which also attaches the numbers to the order
 * item. Section 9: tickets are minted only here (earned), never on purchase.
 *
 * NOTE (staging verification): this replicates prepick for an arbitrary count
 * using LFW public methods + documented meta. Concurrency at the number level
 * relies on the async per-product lock; the earned COUNT is already guaranteed by
 * our checkout-init reservation (ADR 0001). Verify end-to-end on staging.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Ticket_Award
 */
class Nera_SAW_Ticket_Award {

	/**
	 * Order meta flag marking that this order's tickets were earned via the
	 * Strike A Win quiz (not purchased). Read by the confirmation-email copy
	 * filter to tailor the wording. @see Nera_SAW_Integrations::filter_email_body().
	 */
	const ORDER_FLAG_EARNED = '_nera_saw_quiz_earned';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'sync_order_ticket_lines_on_view' ), 4, 1 );
	}

	/**
	 * Reconcile per-tier ticket meta before the order table renders (fixes LFW
	 * copying all product tickets onto every line when one order has two tiers).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function sync_order_ticket_lines_on_view( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$competition_ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product_id = (int) $item->get_product_id();
			if ( $product_id && Nera_SAW_Competition_Config::is_competition( $product_id ) ) {
				$competition_ids[ $product_id ] = true;
			}
		}
		foreach ( array_keys( $competition_ids ) as $competition_id ) {
			self::sync_order_line_tickets( (int) $order->get_id(), (int) $competition_id );
			self::prune_orphan_tickets_for_order( (int) $order->get_id(), (int) $competition_id );
			Nera_SAW_Integrations::sync_public_sold_count( (int) $competition_id );
		}
	}

	/**
	 * Generate `$count` earned LFW tickets for the competition line of an order.
	 *
	 * @param int    $order_id       Order ID.
	 * @param int    $competition_id Competition product ID.
	 * @param int    $user_id        User ID.
	 * @param int    $count          Earned ticket count.
	 * @param string $tier_key       Tier that earned the tickets (required when the order has multiple tier lines).
	 * @return string[] Minted ticket numbers (empty on failure).
	 */
	public static function award( $order_id, $competition_id, $user_id, $count, $tier_key = '' ) {
		$count = max( 0, (int) $count );
		if ( $count < 1 ) {
			return array();
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}
		$product = wc_get_product( $competition_id );
		if ( ! $product || ! function_exists( 'lty_is_lottery_product' ) || ! lty_is_lottery_product( $product ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[nera-strikeawin] Ticket award: not a lottery product #' . (int) $competition_id );
			}
			return array();
		}

		$item = self::find_item( $order, (int) $competition_id, (string) $tier_key );
		if ( ! $item ) {
			return array();
		}

		$picked = self::pick_numbers( $product, $count );
		if ( empty( $picked ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[nera-strikeawin] Ticket award: no available numbers for #' . (int) $competition_id );
			}
			return array();
		}

		// Merge with numbers already earned on this tier line (run-backed; never
		// trust order-item meta — LFW confirm can copy foreign tickets onto siblings).
		$existing = Nera_SAW_Run::ticket_numbers_for_order_tier( (int) $order_id, (int) $competition_id, (string) $tier_key );
		$numbers  = array_values( array_unique( array_merge( $existing, array_map( 'strval', $picked ) ) ) );
		$item->update_meta_data( '_lty_lottery_tickets', $numbers );
		$item->update_meta_data( 'lty_lottery_tickets', $numbers );
		$item->save();

		// Hold picked numbers so concurrent workers don't reuse them.
		$held   = (array) get_post_meta( $competition_id, '_lty_hold_tickets', true );
		$merged = array_values( array_unique( array_merge( array_map( 'strval', $held ), array_map( 'strval', $picked ) ) ) );
		update_post_meta( $competition_id, '_lty_hold_tickets', $merged );
		update_post_meta( $competition_id, 'lty_hold_tickets', $merged );

		// Flag the order as quiz-earned BEFORE confirmation so the ticket
		// confirmation email (fired synchronously during confirm_tickets below)
		// can tailor its copy. @see Nera_SAW_Integrations::filter_email_body().
		$order->update_meta_data( self::ORDER_FLAG_EARNED, 'yes' );
		$order->save();

		// Mint via the async bulk generator (attaches numbers to the order item,
		// fires lty_lottery_ticket_after_created).
		if ( class_exists( 'Nera_LTY_Async_Bulk_Generator' ) && method_exists( 'Nera_LTY_Async_Bulk_Generator', 'create_ticket_for_order' ) ) {
			try {
				Nera_LTY_Async_Bulk_Generator::create_ticket_for_order( $order );
			} catch ( Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[nera-strikeawin] Ticket generation failed: ' . $e->getMessage() );
				}
				return array();
			}

			// The bulk generator mints tickets in `lty_ticket_pending` status and
			// fires only `lty_lottery_ticket_after_created` — it does NOT confirm
			// them. Confirm now (advancing them to `lty_ticket_buyer`) so
			// `lty_lottery_ticket_confirmed` fires and the customer gets the ticket
			// confirmation email. Mirrors the async worker's confirm sequence.
			self::confirm_tickets( $order_id );
		} else {
			/**
			 * Fires when tickets should be generated but the async generator is
			 * unavailable. A fallback generator can hook this.
			 *
			 * @param int   $order_id       Order ID.
			 * @param int   $competition_id Product ID.
			 * @param int   $user_id        User ID.
			 * @param array $picked         Picked ticket numbers.
			 */
			do_action( 'nera_saw_generate_tickets_fallback', $order_id, $competition_id, $user_id, $picked );
		}

		// Release the transient hold on the picked numbers now that they are real
		// (confirmed) ticket posts and therefore counted as "placed". Without this
		// the hold list grows every run until it covers the whole pool and
		// pick_numbers() can never find an available number again — silently
		// killing ticket generation on a live server. (The async path releases
		// holds the same way via Nera_LTY_Async_Prepick::cleanup_holds().)
		self::release_holds( $competition_id, $picked );

		/**
		 * Fires after Strike A Win generates earned tickets for a run. Used to
		 * tailor the confirmation email copy for quiz-earned tickets.
		 *
		 * @param int   $order_id       Order ID.
		 * @param int   $competition_id Product ID.
		 * @param int   $user_id        User ID.
		 * @param array $picked         Ticket numbers generated.
		 */
		do_action( 'nera_saw_tickets_awarded', $order_id, $competition_id, $user_id, $picked );

		self::sync_order_line_tickets( (int) $order_id, (int) $competition_id );

		return array_map( 'strval', $picked );
	}

	/**
	 * Remove LFW ticket posts for an order that are not backed by run slot data.
	 *
	 * @param int $order_id       Order ID.
	 * @param int $competition_id Competition product ID.
	 */
	public static function prune_orphan_tickets_for_order( $order_id, $competition_id ) {
		if ( $order_id < 1 || $competition_id < 1 || ! class_exists( 'Nera_LTY_Async_Helpers' ) ) {
			return;
		}

		$valid_numbers = Nera_SAW_Run::ticket_numbers_for_order( $order_id, $competition_id );
		if ( empty( $valid_numbers ) ) {
			return;
		}
		$valid = array_fill_keys( $valid_numbers, true );

		foreach ( Nera_LTY_Async_Helpers::get_ticket_post_ids_for_order( $order_id ) as $ticket_id ) {
			$product_id = (int) get_post_meta( (int) $ticket_id, 'lty_product_id', true );
			if ( $product_id !== (int) $competition_id ) {
				continue;
			}
			$number = (string) get_post_meta( (int) $ticket_id, 'lty_ticket_number', true );
			if ( '' === $number || isset( $valid[ $number ] ) ) {
				continue;
			}
			wp_trash_post( (int) $ticket_id );
		}

		if ( class_exists( 'LTY_Transient_Handler' ) && method_exists( 'LTY_Transient_Handler', 'delete_all_transients' ) ) {
			LTY_Transient_Handler::delete_all_transients( (int) $competition_id, 0 );
		}
	}

	/**
	 * Write each tier line's `_lty_lottery_tickets` from finalized runs (source of truth).
	 *
	 * @param int $order_id       Order ID.
	 * @param int $competition_id Competition product ID.
	 */
	public static function sync_order_line_tickets( $order_id, $competition_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || ! Nera_SAW_Competition_Config::is_competition( (int) $competition_id ) ) {
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_product_id() !== (int) $competition_id ) {
				continue;
			}

			$tier_key = (string) $item->get_meta( Nera_SAW_Reservations::ITEM_TIER );
			if ( '' === $tier_key ) {
				$stats = Nera_SAW_Run_Grants::order_line_stats( (int) $order_id, (int) $item_id );
				if ( $stats && ! empty( $stats['tier_key'] ) ) {
					$tier_key = (string) $stats['tier_key'];
				}
			}
			if ( '' === $tier_key ) {
				continue;
			}

			$numbers = Nera_SAW_Run::ticket_numbers_for_order_tier( (int) $order_id, (int) $competition_id, $tier_key );
			$item->update_meta_data( '_lty_lottery_tickets', $numbers );
			$item->update_meta_data( 'lty_lottery_tickets', $numbers );
			$item->save();
		}
	}

	/**
	 * Release picked numbers from the competition's LFW hold list. The hold is
	 * only meant to stop a concurrent run from grabbing the same number between
	 * pick and mint; once the ticket posts exist it must be released, or the hold
	 * accumulates until the whole pool is "held" and no run can ever win again.
	 *
	 * @param int      $competition_id Competition product ID.
	 * @param string[] $picked         Numbers to release.
	 * @return void
	 */
	private static function release_holds( $competition_id, array $picked ) {
		if ( empty( $picked ) ) {
			return;
		}
		$drop = array_fill_keys( array_map( 'strval', $picked ), true );

		foreach ( array( '_lty_hold_tickets', 'lty_hold_tickets' ) as $key ) {
			$held = (array) get_post_meta( (int) $competition_id, $key, true );
			if ( empty( $held ) ) {
				continue;
			}
			$pruned = array_values(
				array_filter(
					array_map( 'strval', $held ),
					static function ( $n ) use ( $drop ) {
						return ! isset( $drop[ $n ] );
					}
				)
			);
			update_post_meta( (int) $competition_id, $key, $pruned );
		}
	}

	/**
	 * Confirm freshly minted (pending) tickets for an order so their status
	 * advances to `lty_ticket_buyer` and `lty_lottery_ticket_confirmed` fires
	 * (which sends the ticket confirmation email).
	 *
	 * This replicates the nera-lottery-async worker's confirm step: our direct
	 * mint path (`create_ticket_for_order`) sets `lty_lottery_ticket_created_once`
	 * but does not persist the `lty_ticket_ids_in_order` list that LFW's
	 * confirmation reads, so we rebuild it first. LFW's own
	 * `lty_lottery_ticket_updated_once` guard keeps this idempotent, and the async
	 * email has its own sent-once guard, so a second call is safe.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private static function confirm_tickets( $order_id ) {
		if ( ! class_exists( 'LTY_Order_Handler' ) || ! method_exists( 'LTY_Order_Handler', 'update_lottery_ticket_in_order' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[nera-strikeawin] Ticket confirm skipped: LTY_Order_Handler unavailable for order #' . (int) $order_id );
			}
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$order->read_meta_data( true );

		// Already confirmed — nothing to do (LFW guards this too).
		if ( $order->get_meta( 'lty_lottery_ticket_updated_once' ) ) {
			return;
		}

		// Rebuild the ticket-id list our mint path does not persist, so LFW's
		// confirmation can find the tickets to advance (matches the worker).
		if ( class_exists( 'Nera_LTY_Async_Helpers' ) && method_exists( 'Nera_LTY_Async_Helpers', 'ensure_ticket_ids_in_order' ) ) {
			Nera_LTY_Async_Helpers::ensure_ticket_ids_in_order( $order );
		}

		LTY_Order_Handler::update_lottery_ticket_in_order( $order->get_id(), $order );
	}

	/**
	 * Pick `$count` random available ticket numbers from the product's pool.
	 * available = full pool − placed − held.
	 *
	 * @param WC_Product $product Lottery product.
	 * @param int        $count   How many.
	 * @return string[]
	 */
	private static function pick_numbers( $product, $count ) {
		$pool = array();
		if ( method_exists( $product, 'get_ticket_numbers_based_on_start_number' ) ) {
			$pool = (array) $product->get_ticket_numbers_based_on_start_number();
		} elseif ( method_exists( $product, 'get_overall_tickets' ) ) {
			$pool = (array) $product->get_overall_tickets();
		}
		if ( empty( $pool ) && method_exists( $product, 'get_lty_maximum_tickets' ) ) {
			$max = (int) $product->get_lty_maximum_tickets();
			for ( $i = 1; $i <= $max; $i++ ) {
				$pool[] = (string) $i;
			}
		}

		$placed = method_exists( $product, 'get_placed_tickets' ) ? (array) $product->get_placed_tickets( true ) : array();
		$held   = (array) get_post_meta( $product->get_id(), '_lty_hold_tickets', true );
		$used   = array_fill_keys( array_map( 'strval', array_merge( $placed, $held ) ), true );

		$available = array_values(
			array_filter(
				array_map( 'strval', $pool ),
				static function ( $n ) use ( $used ) {
					return ! isset( $used[ $n ] );
				}
			)
		);
		if ( empty( $available ) ) {
			return array();
		}

		$number_type = (string) get_post_meta( $product->get_id(), '_lty_ticket_number_type', true );
		if ( '1' !== $number_type ) {
			shuffle( $available );
		}
		return array_slice( $available, 0, (int) $count );
	}

	/**
	 * Find the order line for a competition + tier.
	 *
	 * @param WC_Order $order          Order.
	 * @param int      $competition_id Product ID.
	 * @param string   $tier_key       Tier key (optional).
	 * @return WC_Order_Item_Product|null
	 */
	private static function find_item( $order, $competition_id, $tier_key = '' ) {
		$order_id = (int) $order->get_id();

		if ( '' !== $tier_key ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_product_id() !== (int) $competition_id ) {
					continue;
				}
				$item_tier = (string) $item->get_meta( Nera_SAW_Reservations::ITEM_TIER );
				if ( '' === $item_tier ) {
					$stats = Nera_SAW_Run_Grants::order_line_stats( $order_id, (int) $item_id );
					if ( $stats && ! empty( $stats['tier_key'] ) ) {
						$item_tier = (string) $stats['tier_key'];
					}
				}
				if ( $item_tier === (string) $tier_key ) {
					return $item;
				}
			}
		}

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && (int) $item->get_product_id() === (int) $competition_id ) {
				return $item;
			}
		}
		return null;
	}
}
