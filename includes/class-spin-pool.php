<?php
/**
 * Per-competition spin pool: the no-oversell ledger.
 *
 * Invariant: reserved + confirmed <= cap, and available = cap - reserved - confirmed.
 * All state transitions are single atomic guarded UPDATEs so concurrent checkouts
 * cannot oversell (ADR 0001).
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Spin_Pool
 */
class Nera_SAW_Spin_Pool {

	/**
	 * Create the pool row if absent; re-derive available from cap on cap change.
	 *
	 * @param int $competition_id Product ID.
	 * @param int $cap            Hard spin cap.
	 */
	public static function ensure( $competition_id, $cap ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'spin_pool' );
		$cid   = (int) $competition_id;
		$cap   = max( 0, (int) $cap );
		$now   = current_time( 'mysql' );

		$row = self::get( $cid );
		if ( ! $row ) {
			$wpdb->insert(
				$table,
				array(
					'competition_id' => $cid,
					'cap'            => $cap,
					'available'      => $cap,
					'reserved'       => 0,
					'confirmed'      => 0,
					'status'         => 'open',
					'updated_at'     => $now,
				),
				array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
			);
			return;
		}

		// Cap changed: available = cap - reserved - confirmed (clamped).
		$available = max( 0, $cap - (int) $row->reserved - (int) $row->confirmed );
		$wpdb->update(
			$table,
			array(
				'cap'        => $cap,
				'available'  => $available,
				'updated_at' => $now,
			),
			array( 'competition_id' => $cid ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get the pool row.
	 *
	 * @param int $competition_id Product ID.
	 * @return object|null
	 */
	public static function get( $competition_id ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'spin_pool' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE competition_id = %d", (int) $competition_id ) );
	}

	/**
	 * Atomically move `amount` from Available to Reserved.
	 *
	 * @param int $competition_id Product ID.
	 * @param int $amount         Spins to reserve.
	 * @return bool True if reserved (fit + open); false if it did not fit / closed.
	 */
	public static function try_reserve( $competition_id, $amount ) {
		global $wpdb;
		$table  = Nera_SAW_Database::table( 'spin_pool' );
		$amount = max( 0, (int) $amount );
		if ( $amount < 1 ) {
			return false;
		}
		$now = current_time( 'mysql' );
		$sql = $wpdb->prepare(
			"UPDATE {$table}
			 SET available = available - %d, reserved = reserved + %d, updated_at = %s
			 WHERE competition_id = %d AND status = 'open' AND available >= %d",
			$amount,
			$amount,
			$now,
			(int) $competition_id,
			$amount
		);
		$wpdb->query( $sql );
		return $wpdb->rows_affected > 0;
	}

	/**
	 * Confirm a run: of `reserved_amount` held, `earned` become Confirmed and the
	 * remainder returns to Available.
	 *
	 * @param int $competition_id  Product ID.
	 * @param int $reserved_amount Originally reserved (max possible).
	 * @param int $earned          Spins actually earned (<= reserved_amount).
	 * @return bool
	 */
	public static function confirm( $competition_id, $reserved_amount, $earned ) {
		global $wpdb;
		$table           = Nera_SAW_Database::table( 'spin_pool' );
		$reserved_amount = max( 0, (int) $reserved_amount );
		$earned          = max( 0, min( (int) $earned, $reserved_amount ) );
		$release         = $reserved_amount - $earned;
		$now             = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"UPDATE {$table}
			 SET reserved = reserved - %d, confirmed = confirmed + %d, available = available + %d, updated_at = %s
			 WHERE competition_id = %d AND reserved >= %d",
			$reserved_amount,
			$earned,
			$release,
			$now,
			(int) $competition_id,
			$reserved_amount
		);
		$wpdb->query( $sql );
		return $wpdb->rows_affected > 0;
	}

	/**
	 * Confirm a run's earnings, never claiming more than the pool can actually
	 * back — even when the run's own reservation is missing or short.
	 *
	 * The happy path is exactly confirm() above: a run's tickets were reserved
	 * worst-case at grant time (Nera_SAW_Run_Grants::grant_for_order()), so by the
	 * time it finalizes, `reserved` already covers `$reserved_amount` and the
	 * guarded UPDATE succeeds outright. Nera_SAW_Cart_Entry::validate_pool_capacity()
	 * and check_cart_pool_capacity() now stop a sale that reservation couldn't
	 * cover, so that path should be the only one reached in practice.
	 *
	 * The fallback exists for whatever still finds its way around that — a run
	 * granted outside checkout, a reservation released early by some other bug.
	 * Rather than write a run's raw score into spins_confirmed regardless (the
	 * original defect this exists to close), it confirms only what the pool's
	 * own `available` can support at that moment, via the same guarded-UPDATE
	 * shape as every other transition here, and reports back exactly how much
	 * that was so the caller records the true number — never a number larger
	 * than what was actually reserved for it.
	 *
	 * @param int $competition_id  Product ID.
	 * @param int $reserved_amount Originally reserved for this run (max possible).
	 * @param int $earned          Spins actually earned (<= reserved_amount).
	 * @return int The amount actually confirmed. May be less than $earned.
	 */
	public static function confirm_clamped( $competition_id, $reserved_amount, $earned ) {
		$reserved_amount = max( 0, (int) $reserved_amount );
		$earned          = max( 0, (int) $earned );
		if ( $reserved_amount > 0 ) {
			$earned = min( $earned, $reserved_amount );
		}

		if ( $reserved_amount > 0 && self::confirm( $competition_id, $reserved_amount, $earned ) ) {
			return $earned;
		}

		if ( $earned < 1 ) {
			return 0;
		}

		global $wpdb;
		$table = Nera_SAW_Database::table( 'spin_pool' );
		$now   = current_time( 'mysql' );

		$sql = $wpdb->prepare(
			"UPDATE {$table}
			 SET available = available - %d, confirmed = confirmed + %d, updated_at = %s
			 WHERE competition_id = %d AND status = 'open' AND available >= %d",
			$earned,
			$earned,
			$now,
			(int) $competition_id,
			$earned
		);
		$wpdb->query( $sql );
		if ( $wpdb->rows_affected > 0 ) {
			return $earned;
		}

		// Even the full earned amount doesn't fit: take exactly what `available`
		// holds right now. A read then a guarded write, but only reached once
		// both the purchase-time gate and this run's own reservation are already
		// gone — and the WHERE clause still refuses to push available negative,
		// so the worst this can do is under-confirm, never oversell.
		$row = self::get( $competition_id );
		$can = $row ? max( 0, (int) $row->available ) : 0;
		if ( $can < 1 ) {
			return 0;
		}

		$sql = $wpdb->prepare(
			"UPDATE {$table}
			 SET available = available - %d, confirmed = confirmed + %d, updated_at = %s
			 WHERE competition_id = %d AND status = 'open' AND available >= %d",
			$can,
			$can,
			$now,
			(int) $competition_id,
			$can
		);
		$wpdb->query( $sql );
		return $wpdb->rows_affected > 0 ? $can : 0;
	}

	/**
	 * Release a held reservation fully back to Available (abandon/expire/fail).
	 *
	 * @param int $competition_id Product ID.
	 * @param int $amount         Held amount to release.
	 * @return bool
	 */
	public static function release( $competition_id, $amount ) {
		global $wpdb;
		$table  = Nera_SAW_Database::table( 'spin_pool' );
		$amount = max( 0, (int) $amount );
		if ( $amount < 1 ) {
			return false;
		}
		$now = current_time( 'mysql' );
		$sql = $wpdb->prepare(
			"UPDATE {$table}
			 SET reserved = reserved - %d, available = available + %d, updated_at = %s
			 WHERE competition_id = %d AND reserved >= %d",
			$amount,
			$amount,
			$now,
			(int) $competition_id,
			$amount
		);
		$wpdb->query( $sql );
		return $wpdb->rows_affected > 0;
	}

	/**
	 * Close the competition if it can no longer sell or return capacity:
	 * available < lowest tier max possible AND no live reservations.
	 * (Date-based close is handled by the scheduler elsewhere.)
	 *
	 * @param int $competition_id     Product ID.
	 * @param int $lowest_tier_max    Lowest tier's max possible spins.
	 * @return bool True if now closed.
	 */
	public static function maybe_close( $competition_id, $lowest_tier_max ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'spin_pool' );
		$now   = current_time( 'mysql' );
		$sql   = $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'closed', updated_at = %s
			 WHERE competition_id = %d AND status = 'open' AND reserved = 0 AND available < %d",
			$now,
			(int) $competition_id,
			max( 1, (int) $lowest_tier_max )
		);
		$wpdb->query( $sql );
		return $wpdb->rows_affected > 0;
	}

	/**
	 * Force-close (e.g. draw/close date reached).
	 *
	 * @param int $competition_id Product ID.
	 */
	public static function close( $competition_id ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'spin_pool' );
		$wpdb->update(
			$table,
			array(
				'status'     => 'closed',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'competition_id' => (int) $competition_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a pool row (demo cleanup).
	 *
	 * @param int $competition_id Product ID.
	 */
	public static function delete( $competition_id ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'spin_pool' );
		$wpdb->delete( $table, array( 'competition_id' => (int) $competition_id ), array( '%d' ) );
	}
}
