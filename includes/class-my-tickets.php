<?php
/**
 * My tickets: what a player holds, per competition, in one read.
 *
 * The account's "My runs & tickets" view needs counts over every run a player has
 * finished, not one page of them, so they come from grouped queries here rather than
 * from Nera_SAW_Run::runs_for_user(), which pages runs and loads ticket numbers.
 * Tickets are summed the same way as Nera_SAW_Account_Pages::tickets_live(), so the
 * undrawn competitions here always add up to the header badge.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_My_Tickets
 */
class Nera_SAW_My_Tickets {

	/**
	 * Per competition the player has tickets, runs to play or a run in progress on:
	 * open competitions first (closing soonest first), drawn ones after.
	 *
	 * @param int $user_id User ID.
	 * @return array{
	 *     total: int,
	 *     live_count: int,
	 *     competitions: array<int, array{
	 *         competition_id: int, name: string, image: string, url: string,
	 *         tickets: int, played: int, to_play: int, in_progress: int,
	 *         tiers: array<string, int>, tier_label: string,
	 *         closes: string, closes_ts: int, drawn: bool
	 *     }>
	 * } `total` is tickets in undrawn draws; `live_count` the undrawn competitions holding them.
	 */
	public static function summary( $user_id ) {
		static $cache = array();

		$user_id = (int) $user_id;
		$empty   = array(
			'total'        => 0,
			'live_count'   => 0,
			'competitions' => array(),
		);
		if ( $user_id < 1 || ! class_exists( 'Nera_SAW_Database' ) ) {
			return $empty;
		}
		if ( isset( $cache[ $user_id ] ) ) {
			return $cache[ $user_id ];
		}

		global $wpdb;
		$runs  = Nera_SAW_Database::table( 'runs' );
		$slots = Nera_SAW_Database::table( 'run_slots' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Nera_SAW_Database.
		$finished = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.competition_id, COUNT( DISTINCT r.id ) AS played, COALESCE( SUM( s.spins_awarded ), 0 ) AS tickets
				 FROM {$runs} r LEFT JOIN {$slots} s ON s.run_id = r.id
				 WHERE r.user_id = %d AND r.status = 'finalized'
				 GROUP BY r.competition_id",
				$user_id
			)
		);
		$active   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT competition_id, COUNT(*) AS n FROM {$runs} WHERE user_id = %d AND status = 'active' GROUP BY competition_id",
				$user_id
			)
		);
		// phpcs:enable

		$by = array();
		$at = static function ( $cid ) use ( &$by ) {
			if ( ! isset( $by[ $cid ] ) ) {
				$by[ $cid ] = array(
					'tickets'     => 0,
					'played'      => 0,
					'to_play'     => 0,
					'in_progress' => 0,
					'tiers'       => array(),
				);
			}
		};
		foreach ( (array) $finished as $row ) {
			$cid = (int) $row->competition_id;
			$at( $cid );
			$by[ $cid ]['tickets'] = (int) $row->tickets;
			$by[ $cid ]['played']  = (int) $row->played;
		}
		foreach ( (array) $active as $row ) {
			$cid = (int) $row->competition_id;
			$at( $cid );
			$by[ $cid ]['in_progress'] = (int) $row->n;
		}
		if ( class_exists( 'Nera_SAW_Run_Grants' ) ) {
			foreach ( Nera_SAW_Run_Grants::balance_by_competition( $user_id ) as $cid => $bal ) {
				$cid = (int) $cid;
				$at( $cid );
				$by[ $cid ]['to_play'] = (int) $bal['total'];
				$by[ $cid ]['tiers']   = (array) $bal['tiers'];
			}
		}

		$out = $empty;
		foreach ( $by as $cid => $c ) {
			// A finished run that earned nothing, with nothing left to play: nothing to show.
			if ( $cid < 1 || ( $c['tickets'] < 1 && $c['to_play'] < 1 && $c['in_progress'] < 1 ) ) {
				continue;
			}

			$tiers = $c['tiers'];
			arsort( $tiers );
			$tier_label = '';
			if ( $tiers ) {
				$config     = class_exists( 'Nera_SAW_Competition_Config' ) ? Nera_SAW_Competition_Config::get( $cid ) : array();
				$tier_label = Nera_SAW_Account_Pages::tier_label( $config, (string) key( $tiers ) );
			}

			$end_gmt = (string) get_post_meta( $cid, '_lty_end_date_gmt', true );
			$end     = (string) get_post_meta( $cid, '_lty_end_date', true );
			$image   = get_the_post_thumbnail_url( $cid, 'woocommerce_thumbnail' );

			$out['competitions'][] = array(
				'competition_id' => $cid,
				'name'           => get_the_title( $cid ),
				'image'          => $image ? (string) $image : '',
				'url'            => class_exists( 'Nera_SAW_Router' ) ? Nera_SAW_Router::url( 'competition', get_post_field( 'post_name', $cid ) ) : (string) get_permalink( $cid ),
				'tickets'        => $c['tickets'],
				'played'         => $c['played'],
				'to_play'        => $c['to_play'],
				'in_progress'    => $c['in_progress'],
				'tiers'          => $c['tiers'],
				'tier_label'     => $tier_label,
				'closes'         => '' !== $end ? substr( $end, 0, 16 ) : '',
				'closes_ts'      => '' !== $end_gmt ? (int) strtotime( $end_gmt . ' UTC' ) : 0,
				'drawn'          => 'lty_lottery_finished' === get_post_meta( $cid, 'lty_lottery_status', true ),
			);
		}

		usort(
			$out['competitions'],
			static function ( $a, $b ) {
				if ( $a['drawn'] !== $b['drawn'] ) {
					return $a['drawn'] ? 1 : -1;
				}
				// Open: closing soonest first. Drawn: most recent draw first. No date sorts last.
				$x = $a['closes_ts'] ? $a['closes_ts'] : PHP_INT_MAX;
				$y = $b['closes_ts'] ? $b['closes_ts'] : PHP_INT_MAX;
				return $a['drawn'] ? $b['closes_ts'] <=> $a['closes_ts'] : $x <=> $y;
			}
		);

		foreach ( $out['competitions'] as $c ) {
			if ( ! $c['drawn'] && $c['tickets'] > 0 ) {
				++$out['live_count'];
			}
		}
		$out['total'] = Nera_SAW_Account_Pages::tickets_live( $user_id );

		$cache[ $user_id ] = $out;
		return $out;
	}
}
