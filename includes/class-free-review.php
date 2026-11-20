<?php
/**
 * Free entries that share an IP or a device: flagged for an administrator to review.
 *
 * At each free start the grant records a hash of the visitor's IP (the raw
 * address is never stored) and a device id kept in a first-party cookie. When
 * another free grant on the same competition shares either, both grants are
 * marked for review with the reason. A flag never blocks the player and is
 * never shown to them: it only surfaces in the Report page's submissions list.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Free_Review
 */
class Nera_SAW_Free_Review {

	/**
	 * Device id cookie.
	 */
	const COOKIE = 'nera_saw_dev';

	/**
	 * Review reasons, in the order they are stored.
	 */
	const REASONS = array( 'ip', 'device', 'ref' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'nera_saw_free_grant_created', array( __CLASS__, 'record' ), 10, 3 );
	}

	/**
	 * Record the IP hash and device id on a new free grant, then flag it and every
	 * other free grant of the competition that shares either.
	 *
	 * @param int $grant_id       Grant ID.
	 * @param int $user_id        User ID.
	 * @param int $competition_id Free competition ID.
	 */
	public static function record( $grant_id, $user_id, $competition_id ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'run_grants' );

		// Without an address every such entry would share one hash: record nothing.
		$ip      = Nera_SAW_Free_Signup::ip();
		$ip_hash = 'no-ip' === $ip ? '' : wp_hash( $ip );
		$device  = self::device_id();

		$wpdb->update(
			$table,
			array( 'ip_hash' => $ip_hash, 'device_id' => $device ),
			array( 'id' => (int) $grant_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$others = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, ip_hash, device_id, review FROM {$table}
				 WHERE source = %s AND competition_id = %d AND user_id <> %d AND id <> %d
				   AND ( ( ip_hash <> '' AND ip_hash = %s ) OR ( device_id <> '' AND device_id = %s ) )",
				Nera_SAW_Run_Grants::SOURCE_FREE,
				(int) $competition_id,
				(int) $user_id,
				(int) $grant_id,
				$ip_hash,
				$device
			)
		);
		if ( ! $others ) {
			return;
		}

		$mine = array();
		foreach ( $others as $row ) {
			$shared = array();
			if ( '' !== $ip_hash && $row->ip_hash === $ip_hash ) {
				$shared[] = 'ip';
			}
			if ( '' !== $device && $row->device_id === $device ) {
				$shared[] = 'device';
			}
			$mine   = array_merge( $mine, $shared );
			$merged = self::merge( (string) $row->review, $shared );
			if ( $merged !== (string) $row->review ) {
				$wpdb->update( $table, array( 'review' => $merged ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
			}
		}
		$wpdb->update( $table, array( 'review' => self::merge( '', $mine ) ), array( 'id' => (int) $grant_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * A stored reason list plus new reasons, de-duplicated, in fixed order.
	 *
	 * @param string $stored  Stored value: '' or a comma list of REASONS ('ref' = same IP as the referrer).
	 * @param array  $reasons Reasons to add.
	 * @return string
	 */
	public static function merge( $stored, array $reasons ) {
		$all = array_merge( '' === $stored ? array() : explode( ',', $stored ), $reasons );
		return implode( ',', array_values( array_intersect( self::REASONS, $all ) ) );
	}

	/**
	 * Admin label for a stored reason list, or '' when not flagged.
	 *
	 * @param string $review Stored value.
	 * @return string
	 */
	public static function label( $review ) {
		$parts  = '' === (string) $review ? array() : explode( ',', (string) $review );
		$ip     = in_array( 'ip', $parts, true );
		$device = in_array( 'device', $parts, true );
		$out    = array();
		if ( $ip && $device ) {
			$out[] = __( 'Same IP and device', 'nera-strikeawin' );
		} elseif ( $ip ) {
			$out[] = __( 'Same IP', 'nera-strikeawin' );
		} elseif ( $device ) {
			$out[] = __( 'Same device', 'nera-strikeawin' );
		}
		if ( in_array( 'ref', $parts, true ) ) {
			$out[] = __( 'Same IP as referrer', 'nera-strikeawin' );
		}
		return implode( ' · ', $out );
	}

	/**
	 * This browser's device id: the cookie's value when well formed, else a new
	 * one. The cookie is (re)set for a year either way.
	 *
	 * @return string
	 */
	private static function device_id() {
		$id = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by the pattern below.
		if ( ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $id ) ) {
			$id = wp_generate_uuid4();
		}
		$_COOKIE[ self::COOKIE ] = $id;
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$id,
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		return $id;
	}
}
