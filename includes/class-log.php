<?php
/**
 * Quiz diagnostic log: lifecycle + error trail for runs.
 *
 * Writes one row per notable event (run start, each answer, complete, abandon,
 * and every server/client error or stall) to a custom table, shown on the admin
 * "Quiz Log" page and inside a submission's Report detail. Purpose is
 * troubleshooting — e.g. a run that locks mid-quiz with the timer stopped now
 * leaves a traceable client_stall / client_error row instead of vanishing.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Log
 */
class Nera_SAW_Log {

	/** Event types (also the admin filter options). */
	const EVENTS = array(
		'run_start',
		'answer',
		'run_complete',
		'run_abandon',
		'run_restored',
		'error',
		'client_error',
		'client_stall',
	);

	const RETENTION_DAYS = 90;
	const PRUNE_FLAG     = 'nera_saw_log_pruned';

	/**
	 * Register the once-a-day prune (cron-free: guarded by a daily transient, so
	 * it runs at most once per day on any request).
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_prune' ), 99 );
	}

	/**
	 * Append a log row. Never throws — logging must not break gameplay.
	 *
	 * @param string $event   One of self::EVENTS.
	 * @param array  $args     { message, level, run_id, competition_id, tier_key,
	 *                           user_id, order_id, slot_no, context(array) }.
	 * @return void
	 */
	public static function add( $event, array $args = array() ) {
		global $wpdb;
		try {
			$event = sanitize_key( (string) $event );
			$level = isset( $args['level'] ) ? sanitize_key( (string) $args['level'] ) : 'info';

			$context = isset( $args['context'] ) ? $args['context'] : null;
			if ( null !== $context && ! is_string( $context ) ) {
				$context = wp_json_encode( $context );
			}
			// Cap message length so a pathological client payload can't bloat a row.
			$message = isset( $args['message'] ) ? (string) $args['message'] : '';
			if ( strlen( $message ) > 1000 ) {
				$message = substr( $message, 0, 1000 );
			}

			$wpdb->insert(
				Nera_SAW_Database::table( 'log' ),
				array(
					'created_at'     => current_time( 'mysql' ),
					'event'          => $event,
					'level'          => $level,
					'run_id'         => (int) ( $args['run_id'] ?? 0 ),
					'competition_id' => (int) ( $args['competition_id'] ?? 0 ),
					'tier_key'       => (string) ( $args['tier_key'] ?? '' ),
					'user_id'        => (int) ( $args['user_id'] ?? 0 ),
					'order_id'       => (int) ( $args['order_id'] ?? 0 ),
					'slot_no'        => (int) ( $args['slot_no'] ?? 0 ),
					'message'        => $message,
					'context'        => $context,
				),
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
			);
		} catch ( Throwable $e ) {
			// Swallow: the log must never interfere with the run.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[nera-strikeawin] log insert failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Convenience: log an error-level row.
	 *
	 * @param string $event Event key.
	 * @param string $message Message.
	 * @param array  $args   Extra fields.
	 */
	public static function error( $event, $message, array $args = array() ) {
		$args['level']   = 'error';
		$args['message'] = $message;
		self::add( $event, $args );
	}

	/**
	 * Delete rows older than the retention window, at most once per day.
	 */
	public static function maybe_prune() {
		if ( get_transient( self::PRUNE_FLAG ) ) {
			return;
		}
		set_transient( self::PRUNE_FLAG, 1, DAY_IN_SECONDS );
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Nera_SAW_Database::table( 'log' ) . ' WHERE created_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$cutoff
			)
		);
	}

	/**
	 * Rows for a single run (oldest first), for the Report detail view.
	 *
	 * @param int $run_id Run ID.
	 * @return array
	 */
	public static function for_run( $run_id ) {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Nera_SAW_Database::table( 'log' ) . ' WHERE run_id = %d ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $run_id
			)
		);
	}
}
