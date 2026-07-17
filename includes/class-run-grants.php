<?php
/**
 * Run balance ledger (ADR 0006).
 *
 * Buying a Tier grants `qty` runs to a per-user, per-Competition, per-Tier balance
 * when the order is paid (Spin-to-Win-style, idempotent per order line). Each grant
 * reserves `qty × max-possible-per-run` from the no-oversell pool at purchase.
 * Playing consumes one run FIFO (oldest grant first), attributing the Run to the
 * granting order; the run settles one `reserved_per_run` slice at finalize. The
 * per-tier balance is derived from the grants (sum of qty − consumed on active
 * grants), so this table is the single source of truth.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Run_Grants
 */
class Nera_SAW_Run_Grants {

	/**
	 * Hooks: grant on paid statuses; release on cancel/fail/refund.
	 */
	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'grant_for_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'grant_for_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'grant_for_order' ), 20, 1 );

		foreach ( array( 'cancelled', 'failed', 'refunded' ) as $status ) {
			add_action( "woocommerce_order_status_{$status}", array( __CLASS__, 'release_for_order' ), 20, 1 );
		}
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		return Nera_SAW_Database::table( 'run_grants' );
	}

	/**
	 * Grant runs for each Strike A Win competition line on a paid order + reserve
	 * that line's max-possible tickets. Idempotent per order line.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function grant_for_order( $order_id ) {
		global $wpdb;
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
			return;
		}
		$user_id = (int) $order->get_user_id();
		if ( $user_id < 1 ) {
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$competition_id = (int) $item->get_product_id();
			if ( ! Nera_SAW_Competition_Config::is_competition( $competition_id ) ) {
				continue;
			}

			$qty = (int) $item->get_quantity();
			if ( $qty < 1 ) {
				continue;
			}

			$tier_key = (string) wc_get_order_item_meta( $item_id, Nera_SAW_Reservations::ITEM_TIER, true );

			// Config snapshot: prefer the one bound to the line at add-to-cart;
			// else the competition's current config.
			$snapshot_json = wc_get_order_item_meta( $item_id, Nera_SAW_Reservations::ITEM_SNAPSHOT, true );
			$config        = $snapshot_json ? json_decode( $snapshot_json, true ) : null;
			if ( ! is_array( $config ) ) {
				$config        = Nera_SAW_Competition_Config::get( $competition_id );
				$snapshot_json = wp_json_encode( $config );
				wc_add_order_item_meta( $item_id, Nera_SAW_Reservations::ITEM_SNAPSHOT, $snapshot_json, true );
			}

			$per_run = (int) Nera_SAW_Competition_Config::max_possible_spins( $config, $tier_key );

			// Idempotent grant per (order, item).
			$now      = current_time( 'mysql' );
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO " . self::table() . "
					 (user_id, competition_id, order_id, order_item_id, tier_key, qty, consumed, reserved_per_run, config_snapshot, status, created_at, updated_at)
					 VALUES (%d, %d, %d, %d, %s, %d, 0, %d, %s, 'active', %s, %s)",
					$user_id,
					$competition_id,
					(int) $order->get_id(),
					(int) $item_id,
					$tier_key,
					$qty,
					$per_run,
					$snapshot_json,
					$now,
					$now
				)
			);

			// Only reserve on a fresh grant (INSERT IGNORE affects 0 rows on repeat).
			if ( $inserted && $per_run > 0 ) {
				$reserved = Nera_SAW_Spin_Pool::try_reserve( $competition_id, $qty * $per_run );
				if ( ! $reserved ) {
					$order->add_order_note(
						sprintf(
							/* translators: %d competition id */
							__( 'Strike A Win: could not reserve tickets for competition #%d (stock full). Runs granted but may not all be honoured.', 'nera-strikeawin' ),
							$competition_id
						)
					);
				}
			}
		}
	}

	/**
	 * Release the still-held (unconsumed) reservation for an order and void its
	 * grants (cancel/fail/refund).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function release_for_order( $order_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE order_id = %d AND status = 'active'", (int) $order_id ) );
		foreach ( (array) $rows as $g ) {
			$remaining = max( 0, (int) $g->qty - (int) $g->consumed );
			$release   = $remaining * (int) $g->reserved_per_run;
			if ( $release > 0 ) {
				Nera_SAW_Spin_Pool::release( (int) $g->competition_id, $release );
			}
			$wpdb->update( self::table(), array( 'status' => 'void', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $g->id ) );
		}
	}

	/**
	 * Per-tier balance (remaining runs) for a user in a competition.
	 *
	 * @param int $user_id        User ID.
	 * @param int $competition_id Competition ID.
	 * @return array tier_key => remaining runs.
	 */
	public static function balance( $user_id, $competition_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tier_key, COALESCE(SUM(qty - consumed),0) AS remaining
				 FROM " . self::table() . "
				 WHERE user_id = %d AND competition_id = %d AND status = 'active'
				 GROUP BY tier_key",
				(int) $user_id,
				(int) $competition_id
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$rem = (int) $r->remaining;
			if ( $rem > 0 ) {
				$out[ (string) $r->tier_key ] = $rem;
			}
		}
		return $out;
	}

	/**
	 * Total remaining runs across all tiers for a user in a competition.
	 *
	 * @param int $user_id        User ID.
	 * @param int $competition_id Competition ID.
	 * @return int
	 */
	public static function balance_total( $user_id, $competition_id ) {
		$sum = 0;
		foreach ( self::balance( $user_id, $competition_id ) as $rem ) {
			$sum += (int) $rem;
		}
		return $sum;
	}

	/**
	 * Remaining runs grouped by competition and tier for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{total: int, tiers: array<string, int>}>
	 */
	public static function balance_by_competition( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT competition_id, tier_key, COALESCE(SUM(qty - consumed),0) AS remaining
				 FROM " . self::table() . "
				 WHERE user_id = %d AND status = 'active'
				 GROUP BY competition_id, tier_key
				 HAVING remaining > 0",
				(int) $user_id
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$competition_id = (int) $row->competition_id;
			$tier_key       = (string) $row->tier_key;
			$remaining      = (int) $row->remaining;
			if ( $competition_id < 1 || '' === $tier_key || $remaining < 1 ) {
				continue;
			}
			if ( ! isset( $out[ $competition_id ] ) ) {
				$out[ $competition_id ] = array(
					'total' => 0,
					'tiers' => array(),
				);
			}
			$out[ $competition_id ]['tiers'][ $tier_key ] = $remaining;
			$out[ $competition_id ]['total']              += $remaining;
		}
		return $out;
	}

	/**
	 * Consume one run FIFO for (user, competition, tier). Returns the consumed
	 * grant row (for order attribution + config snapshot + reserved_per_run), or
	 * null if the balance is empty.
	 *
	 * @param int    $user_id        User ID.
	 * @param int    $competition_id Competition ID.
	 * @param string $tier_key       Tier key.
	 * @return object|null
	 */
	public static function consume_fifo( $user_id, $competition_id, $tier_key ) {
		global $wpdb;
		$table = self::table();

		// Oldest active grant for this tier with runs remaining, consumed atomically.
		$grant = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE user_id = %d AND competition_id = %d AND tier_key = %s AND status = 'active' AND consumed < qty
				 ORDER BY id ASC LIMIT 1",
				(int) $user_id,
				(int) $competition_id,
				(string) $tier_key
			)
		);
		if ( ! $grant ) {
			return null;
		}

		// Guarded increment so two concurrent starts can't consume the same unit.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET consumed = consumed + 1, updated_at = %s
				 WHERE id = %d AND consumed < qty",
				current_time( 'mysql' ),
				(int) $grant->id
			)
		);
		if ( $affected < 1 ) {
			// Lost the race — retry once from the top.
			return self::consume_fifo( $user_id, $competition_id, $tier_key );
		}

		$grant->consumed = (int) $grant->consumed + 1;
		return $grant;
	}

	/**
	 * Return one consumed run to the balance (e.g. a run that could not start).
	 *
	 * @param int $grant_id Grant ID.
	 */
	public static function refund( $grant_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET consumed = GREATEST(0, consumed - 1), updated_at = %s WHERE id = %d",
				current_time( 'mysql' ),
				(int) $grant_id
			)
		);
	}

	/**
	 * Return one consumed run to the balance for a given run, choosing a grant that
	 * actually has a consumed unit. Prefers the run's granting order, then any
	 * matching (user, competition, tier) grant. Used when an admin restores an
	 * errored run (ADR 0013).
	 *
	 * @param int    $user_id        User ID.
	 * @param int    $competition_id Competition ID.
	 * @param string $tier_key       Tier key.
	 * @param int    $order_id       Preferred granting order (0 to ignore).
	 * @return bool True if a run was refunded.
	 */
	public static function refund_one( $user_id, $competition_id, $tier_key, $order_id = 0 ) {
		global $wpdb;
		$table = self::table();

		$grant_id = 0;
		if ( $order_id > 0 ) {
			$grant_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table}
					 WHERE user_id = %d AND competition_id = %d AND tier_key = %s AND order_id = %d AND consumed > 0
					 ORDER BY id ASC LIMIT 1",
					(int) $user_id,
					(int) $competition_id,
					(string) $tier_key,
					(int) $order_id
				)
			);
		}
		if ( ! $grant_id ) {
			$grant_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table}
					 WHERE user_id = %d AND competition_id = %d AND tier_key = %s AND consumed > 0
					 ORDER BY id ASC LIMIT 1",
					(int) $user_id,
					(int) $competition_id,
					(string) $tier_key
				)
			);
		}
		if ( ! $grant_id ) {
			return false;
		}
		self::refund( $grant_id );
		return true;
	}

	/**
	 * Record a grant for a directly-seeded demo run (already consumed). Keeps the
	 * balance/report coherent for demo submissions.
	 *
	 * @param array $data user_id, competition_id, order_id, order_item_id, tier_key, reserved_per_run, config_snapshot.
	 * @return int Grant ID.
	 */
	public static function insert_consumed( array $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . self::table() . "
				 (user_id, competition_id, order_id, order_item_id, tier_key, qty, consumed, reserved_per_run, config_snapshot, status, created_at, updated_at)
				 VALUES (%d, %d, %d, %d, %s, 1, 1, %d, %s, 'active', %s, %s)",
				(int) $data['user_id'],
				(int) $data['competition_id'],
				(int) ( $data['order_id'] ?? 0 ),
				(int) ( $data['order_item_id'] ?? 0 ),
				(string) ( $data['tier_key'] ?? '' ),
				(int) ( $data['reserved_per_run'] ?? 0 ),
				(string) ( $data['config_snapshot'] ?? '' ),
				$now,
				$now
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Run counts for a specific order line (from the grant row).
	 *
	 * @param int $order_id      Order ID.
	 * @param int $order_item_id Order line item ID.
	 * @return array|null { total: int, completed: int, remaining: int, status: string } or null.
	 */
	public static function order_line_stats( $order_id, $order_item_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT tier_key, qty, consumed, status, config_snapshot FROM ' . self::table() . ' WHERE order_id = %d AND order_item_id = %d LIMIT 1',
				(int) $order_id,
				(int) $order_item_id
			)
		);
		if ( ! $row ) {
			return null;
		}
		$total     = max( 0, (int) $row->qty );
		$completed = max( 0, min( $total, (int) $row->consumed ) );
		$config    = $row->config_snapshot ? json_decode( $row->config_snapshot, true ) : null;
		return array(
			'tier_key'  => (string) $row->tier_key,
			'total'     => $total,
			'completed' => $completed,
			'remaining' => max( 0, $total - $completed ),
			'status'    => (string) $row->status,
			'config'    => is_array( $config ) ? $config : null,
		);
	}

	/**
	 * Delete grants for a competition (demo cleanup).
	 *
	 * @param int $competition_id Competition ID.
	 */
	public static function delete_for_competition( $competition_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'competition_id' => (int) $competition_id ), array( '%d' ) );
	}
}
