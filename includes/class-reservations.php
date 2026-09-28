<?php
/**
 * Reservation ledger + WooCommerce order-lifecycle binding (ADR 0001).
 *
 * At checkout-init (order placed) we snapshot the competition config onto the
 * order line and atomically reserve the tier's max possible spins. Payment then
 * confirms against the held reservation via WooCommerce's normal order-paid
 * transition. Run finalization settles it (earned -> Confirmed, rest released).
 * Abandoned unpaid orders are released on a TTL sweep.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Reservations
 */
class Nera_SAW_Reservations {

	const CRON_HOOK        = 'nera_saw_sweep_reservations';
	const ITEM_SNAPSHOT    = '_saw_config_snapshot';
	const ITEM_TIER        = '_saw_tier_key';
	const ITEM_RESERVATION = '_saw_reservation_id';

	/**
	 * Register hooks + schedule the sweeper.
	 */
	public static function init() {
		// NOTE (ADR 0006): reservation + grant now happen at PAYMENT via
		// Nera_SAW_Run_Grants (reserve `qty × max-possible-per-run` per tier line),
		// not at checkout-init. The old on_order_created/on_order_released reserve
		// hooks and the TTL sweep are retired. The class is retained for its item
		// meta keys (ITEM_TIER / ITEM_SNAPSHOT) and demo cleanup helpers.
		//
		// The hook name outlived the reservation sweep, though: Nera_SAW_Run hangs
		// finalize_stale() on it. When this method was emptied, nothing was left to
		// schedule CRON_HOOK — so for the whole life of that change, abandoned runs
		// were never closed and the tickets their players had earned were never
		// minted. Scheduling is restored here, where the constant lives, so the two
		// cannot drift apart again (ADR 0020).
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * A five-minute interval for the sweep.
	 *
	 * Tied to how long a player is willing to sit on a results screen that has not
	 * appeared: the sweep is what mints an abandoned run's tickets, so an hourly
	 * tick would mean an hour between earning them and seeing them.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules['nera_saw_five_minutes'] ) ) {
			$schedules['nera_saw_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (Strike A Win)', 'nera-strikeawin' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the sweep if it is not already scheduled.
	 *
	 * On `init` rather than only on activation, because this plugin updates from
	 * GitHub — an update does not re-run the activation hook, so an activation-only
	 * schedule would never reach a site that already had the plugin. The
	 * wp_next_scheduled() guard makes it idempotent.
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'nera_saw_five_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the sweep. Called on deactivation.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * On order placement: for each Strikeawin competition line, snapshot config
	 * and reserve the tier's max possible spins atomically.
	 *
	 * @param int|WC_Order $order_id Order ID or object.
	 */
	public static function on_order_created( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$user_id = (int) $order->get_user_id();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$competition_id = (int) $item->get_product_id();
			if ( ! Nera_SAW_Competition_Config::is_competition( $competition_id ) ) {
				continue;
			}

			// Already reserved for this line? (idempotency)
			if ( wc_get_order_item_meta( $item_id, self::ITEM_RESERVATION, true ) ) {
				continue;
			}

			// Tier is written to the line item by the cart-entry flow (fixed-price
			// entry), not by a variation.
			$tier_key = (string) wc_get_order_item_meta( $item_id, self::ITEM_TIER, true );
			$config   = Nera_SAW_Competition_Config::get( $competition_id );
			$amount   = Nera_SAW_Competition_Config::max_possible_spins( $config, $tier_key );

			// Snapshot the exact config the run will play under (bound here, at
			// checkout-init, so reserved == run max possible).
			wc_add_order_item_meta( $item_id, self::ITEM_SNAPSHOT, wp_json_encode( $config ), true );

			$reserved = Nera_SAW_Spin_Pool::try_reserve( $competition_id, $amount );
			$state    = $reserved ? 'held' : 'failed';

			$reservation_id = self::insert(
				array(
					'competition_id' => $competition_id,
					'order_id'       => (int) $order->get_id(),
					'user_id'        => $user_id,
					'tier_key'       => $tier_key,
					'amount'         => $amount,
					'state'          => $state,
					'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + Nera_SAW_Constants::reservation_ttl_seconds() ),
				)
			);
			wc_add_order_item_meta( $item_id, self::ITEM_RESERVATION, $reservation_id, true );

			if ( ! $reserved ) {
				$order->add_order_note(
					sprintf(
						/* translators: %d: competition id */
						__( 'Strike A Win: could not reserve tickets for competition #%d (stock full). Entry cannot be honoured.', 'nera-strikeawin' ),
						$competition_id
					)
				);
			}
		}
	}

	/**
	 * On order cancel/fail/refund: release any held reservations for the order.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_order_released( $order_id ) {
		foreach ( self::get_by_order( (int) $order_id ) as $res ) {
			if ( 'held' === $res->state ) {
				Nera_SAW_Spin_Pool::release( (int) $res->competition_id, (int) $res->amount );
				self::set_state( (int) $res->id, 'released' );
			}
		}
	}

	/**
	 * Settle a reservation when its run finalizes.
	 *
	 * @param int $reservation_id Reservation ID.
	 * @param int $earned         Spins earned (<= amount).
	 * @return bool
	 */
	public static function settle( $reservation_id, $earned ) {
		$res = self::get( (int) $reservation_id );
		if ( ! $res || 'held' !== $res->state ) {
			return false;
		}
		Nera_SAW_Spin_Pool::confirm( (int) $res->competition_id, (int) $res->amount, (int) $earned );
		self::set_state( (int) $res->id, 'settled' );
		return true;
	}

	/**
	 * TTL sweep: release held reservations past expiry whose order is not paid.
	 */
	public static function sweep() {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'reservations' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE state = 'held' AND expires_at IS NOT NULL AND expires_at < %s",
				$now
			)
		);
		foreach ( $rows as $res ) {
			$order = wc_get_order( (int) $res->order_id );
			// Keep the hold if payment has completed; the run may still be pending.
			if ( $order && $order->is_paid() ) {
				continue;
			}
			Nera_SAW_Spin_Pool::release( (int) $res->competition_id, (int) $res->amount );
			self::set_state( (int) $res->id, 'expired' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Data access                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Insert a reservation row.
	 *
	 * @param array $data Row data.
	 * @return int Inserted ID.
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'reservations' );
		$now   = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'competition_id' => (int) $data['competition_id'],
				'order_id'       => (int) $data['order_id'],
				'user_id'        => (int) $data['user_id'],
				'run_id'         => isset( $data['run_id'] ) ? (int) $data['run_id'] : 0,
				'tier_key'       => (string) $data['tier_key'],
				'amount'         => (int) $data['amount'],
				'state'          => (string) $data['state'],
				'expires_at'     => isset( $data['expires_at'] ) ? $data['expires_at'] : null,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get one reservation.
	 *
	 * @param int $id Reservation ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'reservations' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * All reservations for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public static function get_by_order( $order_id ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'reservations' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", (int) $order_id ) );
	}

	/**
	 * Update reservation state.
	 *
	 * @param int    $id    Reservation ID.
	 * @param string $state New state.
	 */
	public static function set_state( $id, $state ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'reservations' );
		$wpdb->update(
			$table,
			array(
				'state'      => (string) $state,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Attach a run to a reservation.
	 *
	 * @param int $id     Reservation ID.
	 * @param int $run_id Run ID.
	 */
	public static function set_run( $id, $run_id ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'reservations' );
		$wpdb->update(
			$table,
			array(
				'run_id'     => (int) $run_id,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete all reservations for a competition (demo cleanup).
	 *
	 * @param int $competition_id Product ID.
	 */
	public static function delete_for_competition( $competition_id ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'reservations' );
		$wpdb->delete( $table, array( 'competition_id' => (int) $competition_id ), array( '%d' ) );
	}
}
