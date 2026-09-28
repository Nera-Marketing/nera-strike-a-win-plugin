<?php
/**
 * Run clock migration screen — Strike A Win → Run Clock.
 *
 * Gives the runs that predate `expires_at` a wall clock, in batches, with a
 * preview first.
 *
 * WHY THIS IS A SCREEN AND NOT AN AUTOMATIC UPGRADE
 * -------------------------------------------------
 * Back-filling is not a schema change with a cosmetic effect. Every run it
 * touches becomes sweepable, and the sweep finalizes runs — which **mints the
 * tickets those players earned**. On a site where the sweep has never run (see
 * ADR 0020) that backlog can be large, and it produces real ticket numbers in a
 * real draw, real confirmation emails, and real numbers in the Report.
 *
 * Doing that silently inside `maybe_upgrade()` would mean a routine plugin update
 * mints a month of tickets while nobody is looking. So it is deliberate, visible,
 * batched, and previewable, and it tells the operator what will happen before it
 * happens.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Run_Clock_Admin
 */
class Nera_SAW_Run_Clock_Admin {

	const SLUG   = 'nera-strikeawin-run-clock';
	const ACTION = 'nera_saw_run_clock_migrate';
	const NONCE  = 'nera_saw_run_clock';

	/**
	 * Runs per batch. Small enough that a shared host will not time out, large
	 * enough that a few thousand runs is a handful of clicks.
	 */
	const BATCH = 100;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Register the submenu.
	 */
	public static function menu() {
		/*
		 * A migration tool, not a feature. It appears while there are runs without a
		 * clock and disappears once there are none, so it does not sit in the menu
		 * forever advertising a job that is finished.
		 *
		 * Deliberately NOT gated on the resume policy. The backlog needs clearing
		 * under either one — the sweep skips a run with no clock whatever the policy
		 * says, so hiding this screen in "close" mode would strand those runs
		 * permanently: never minted, never flagged, never seen.
		 */
		$scan = self::scan();
		if ( $scan['pending'] < 1 && ! ( defined( 'NERA_SAW_ALWAYS_SHOW_RUN_CLOCK' ) && NERA_SAW_ALWAYS_SHOW_RUN_CLOCK ) ) {
			return;
		}

		add_submenu_page(
			'nera-strikeawin',
			__( 'Run Clock', 'nera-strikeawin' ),
			__( 'Run Clock', 'nera-strikeawin' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Counting
	 * ------------------------------------------------------------------ */

	/**
	 * What the migration is facing.
	 *
	 * @return array
	 */
	public static function scan() {
		global $wpdb;
		$runs = Nera_SAW_Database::table( 'runs' );
		$now  = current_time( 'mysql' );

		/*
		 * An errored run keeps `status = 'active'` so the Report can find it and
		 * offer Restore (ADR 0013). It is therefore NOT part of this migration: it
		 * is waiting for a person, not for a clock, and every write path here skips
		 * it — the sweep, the back-fill and the legacy settle alike.
		 *
		 * Counting it as pending was a bug: the menu stayed visible advertising work
		 * that none of the buttons could do. It gets its own count instead, so it is
		 * still visible without being mistaken for something to back-fill.
		 */
		$live = "status = 'active' AND end_reason <> 'errored'";

		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs} WHERE {$live} AND expires_at IS NULL" );
		$clocked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs} WHERE {$live} AND expires_at IS NOT NULL" );

		$due = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$runs} WHERE {$live} AND expires_at IS NOT NULL AND expires_at < %s", $now )
		);

		// What the backlog is worth. `spins_confirmed` is what scoring has already
		// credited to these runs; finalizing is what turns it into ticket numbers.
		$owed = (int) $wpdb->get_var( "SELECT COALESCE( SUM( spins_confirmed ), 0 ) FROM {$runs} WHERE {$live} AND expires_at IS NULL" );

		$oldest = $wpdb->get_var( "SELECT MIN( COALESCE( started_at, created_at ) ) FROM {$runs} WHERE {$live} AND expires_at IS NULL" );

		$errored = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs} WHERE status = 'active' AND end_reason = 'errored'" );

		return array(
			'pending' => $pending,
			'clocked' => $clocked,
			'due'     => $due,
			'owed'    => $owed,
			'oldest'  => $oldest,
			'errored' => $errored,
		);
	}

	/**
	 * Preview the next batch without writing anything.
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function preview( $limit = 20 ) {
		global $wpdb;
		$runs = Nera_SAW_Database::table( 'runs' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$runs} WHERE status = 'active' AND end_reason <> 'errored' AND expires_at IS NULL ORDER BY id ASC LIMIT %d",
				max( 1, (int) $limit )
			)
		);

		$now = time();
		$out = array();
		foreach ( (array) $rows as $run ) {
			$derived = Nera_SAW_Run::derive_expires_at( $run );
			$out[]   = array(
				'id'         => (int) $run->id,
				'user_id'    => (int) $run->user_id,
				'started_at' => $run->started_at ? $run->started_at : $run->created_at,
				'expires_at' => $derived,
				'sweeps_now' => $derived && strtotime( $derived ) < $now,
				'tickets'    => (int) $run->spins_confirmed,
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Running
	 * ------------------------------------------------------------------ */

	/**
	 * Back-fill one batch.
	 *
	 * @return array { done: int, remaining: int }
	 */
	public static function run_batch() {
		global $wpdb;
		$runs = Nera_SAW_Database::table( 'runs' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$runs} WHERE status = 'active' AND end_reason <> 'errored' AND expires_at IS NULL ORDER BY id ASC LIMIT %d",
				self::BATCH
			)
		);

		$done = 0;
		foreach ( (array) $ids as $id ) {
			if ( Nera_SAW_Run::backfill_expires_at( (int) $id ) ) {
				$done++;
			}
		}

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs} WHERE status = 'active' AND end_reason <> 'errored' AND expires_at IS NULL" );

		return array(
			'done'      => $done,
			'remaining' => $remaining,
		);
	}

	/**
	 * Handle the form.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'nera-strikeawin' ) );
		}
		check_admin_referer( self::NONCE );

		$args = array( 'page' => self::SLUG );

		if ( isset( $_POST['settle_legacy'] ) ) {
			// The backlog predates the current policy, so it is finished under the
			// one it was played under. Without this, a backlog too large for the
			// settings save to finish would be split in two: the first 500 runs
			// settled as `expired` and minted, the rest reclassified as `errored`.
			$result                 = Nera_SAW_Run::settle_legacy_runs();
			$args['saw_settled']    = (int) $result['settled'];
			$args['saw_unsettled']  = (int) $result['remaining'];
		} elseif ( isset( $_POST['run_sweep'] ) ) {
			// Run the sweep by hand, once, rather than waiting up to five minutes
			// for cron — useful immediately after a back-fill, and the only way to
			// see the result on a site where WP-Cron is disabled.
			Nera_SAW_Run::finalize_stale();
			$args['saw_swept'] = '1';
		} else {
			$result            = self::run_batch();
			$args['saw_done']  = (int) $result['done'];
			$args['saw_left']  = (int) $result['remaining'];
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------ */

	/**
	 * Render.
	 */
	public static function render() {
		$scan = self::scan();
		$next = wp_next_scheduled( Nera_SAW_Reservations::CRON_HOOK );

		echo '<div class="wrap saw-admin">';
		echo '<h1 class="saw-admin__title">' . esc_html__( 'Strike A Win — Run Clock', 'nera-strikeawin' ) . '</h1>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['saw_done'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: rows written, 2: rows remaining */
						__( 'Back-filled %1$d run(s). %2$d still without a clock.', 'nera-strikeawin' ),
						(int) $_GET['saw_done'],
						isset( $_GET['saw_left'] ) ? (int) $_GET['saw_left'] : 0
					)
				)
			);
		}
		if ( isset( $_GET['saw_settled'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: runs settled, 2: runs remaining */
						__( 'Settled %1$d run(s) under the previous policy — tickets issued, runs closed. %2$d left.', 'nera-strikeawin' ),
						(int) $_GET['saw_settled'],
						isset( $_GET['saw_unsettled'] ) ? (int) $_GET['saw_unsettled'] : 0
					)
				)
			);
		}
		if ( isset( $_GET['saw_swept'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sweep run. Any run past its clock has been finalized and its tickets minted.', 'nera-strikeawin' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// --- State -------------------------------------------------------
		echo '<div class="saw-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Where things stand', 'nera-strikeawin' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px">';
		self::row( __( 'Active runs with no clock', 'nera-strikeawin' ), (string) $scan['pending'], __( 'These predate the fix. They can never be swept until back-filled.', 'nera-strikeawin' ) );
		self::row( __( 'Active runs with a clock', 'nera-strikeawin' ), (string) $scan['clocked'], __( 'Normal. Started since the fix, or already back-filled.', 'nera-strikeawin' ) );
		self::row( __( 'Clocked and already past it', 'nera-strikeawin' ), (string) $scan['due'], __( 'The next sweep will finalize these and mint their tickets.', 'nera-strikeawin' ) );
		self::row( __( 'Tickets already scored but unminted', 'nera-strikeawin' ), (string) $scan['owed'], __( 'Earned by players on the clockless runs above. Finalizing is what issues them.', 'nera-strikeawin' ) );
		self::row(
			__( 'Oldest clockless run', 'nera-strikeawin' ),
			$scan['oldest'] ? esc_html( $scan['oldest'] ) : '—',
			__( 'How far the backlog reaches.', 'nera-strikeawin' )
		);
		if ( $scan['errored'] > 0 ) {
			self::row(
				__( 'Waiting for an administrator', 'nera-strikeawin' ),
				(string) $scan['errored'],
				__( 'Runs flagged as errored. Not part of this migration — handle them in the Report, where Restore refunds the run to the player.', 'nera-strikeawin' )
			);
		}
		self::row(
			__( 'Sweep scheduled', 'nera-strikeawin' ),
			$next ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i:s' ) ) : '<strong style="color:#b32d2e">' . esc_html__( 'NOT SCHEDULED', 'nera-strikeawin' ) . '</strong>',
			__( 'Should be within five minutes. If it never advances, WP-Cron is not running on this site.', 'nera-strikeawin' )
		);
		echo '</table>';
		echo '</div>';

		// --- Warning + actions -------------------------------------------
		if ( $scan['pending'] > 0 ) {
			echo '<div class="notice notice-warning inline" style="margin:16px 0;padding:12px 14px">';
			echo '<p><strong>' . esc_html__( 'Read before running this on a live site.', 'nera-strikeawin' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Back-filling makes these runs sweepable. The sweep finalizes them, and finalizing mints the lottery tickets their players earned — real ticket numbers in a real draw, with the confirmation emails that go with them.', 'nera-strikeawin' ) . '</p>';
			echo '<p>' . esc_html__( 'That is the correct outcome: those players earned those tickets and never received them. But it is not something to discover afterwards. Check the preview below, and tell whoever watches the draw that the numbers are about to move.', 'nera-strikeawin' ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Back-filling is what commits you.', 'nera-strikeawin' ) . '</strong> ' . esc_html__( 'The sweep runs by itself every five minutes and will pick these up on its own — there is no second confirmation after this one.', 'nera-strikeawin' ) . '</p>';
			echo '</div>';
		}

		echo '<div class="saw-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Preview — the next rows, unwritten', 'nera-strikeawin' ) . '</h2>';

		$preview = self::preview( 20 );
		if ( ! $preview ) {
			echo '<p class="saw-muted">' . esc_html__( 'Nothing to back-fill. Every active run has a clock.', 'nera-strikeawin' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:900px">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Run', 'nera-strikeawin' ) . '</th>';
			echo '<th>' . esc_html__( 'User', 'nera-strikeawin' ) . '</th>';
			echo '<th>' . esc_html__( 'Started', 'nera-strikeawin' ) . '</th>';
			echo '<th>' . esc_html__( 'Clock would be', 'nera-strikeawin' ) . '</th>';
			echo '<th>' . esc_html__( 'Swept immediately?', 'nera-strikeawin' ) . '</th>';
			echo '<th>' . esc_html__( 'Tickets to mint', 'nera-strikeawin' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $preview as $row ) {
				printf(
					'<tr><td>#%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$d</td></tr>',
					(int) $row['id'],
					esc_html( (string) $row['user_id'] ),
					esc_html( (string) $row['started_at'] ),
					esc_html( (string) $row['expires_at'] ),
					$row['sweeps_now'] ? '<strong>' . esc_html__( 'Yes', 'nera-strikeawin' ) . '</strong>' : esc_html__( 'No', 'nera-strikeawin' ),
					(int) $row['tickets']
				);
			}
			echo '</tbody></table>';
			echo '<p class="saw-muted">' . esc_html( sprintf( /* translators: %d: batch size */ __( 'Showing up to 20. Each run of the button back-fills %d.', 'nera-strikeawin' ), self::BATCH ) ) . '</p>';
		}
		echo '</div>';

		// --- Buttons ------------------------------------------------------
		echo '<div class="saw-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Actions', 'nera-strikeawin' ) . '</h2>';

		$legacy = ! Nera_SAW_Mode::allows_resume() && $scan['pending'] > 0;

		if ( $legacy ) {
			echo '<div class="notice notice-info inline" style="margin:0 0 14px;padding:12px 14px"><p>';
			echo esc_html__( 'These runs were played while interrupted runs were still allowed to continue, so they are settled under that policy: their tickets are issued and the runs closed. They are not reclassified as errors after the fact.', 'nera-strikeawin' );
			echo '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		if ( $legacy ) {
			echo '<input type="hidden" name="settle_legacy" value="1">';
			submit_button( __( 'Settle the previous policy\'s runs', 'nera-strikeawin' ), 'primary', 'submit', false );
		} else {
			submit_button(
				sprintf( /* translators: %d: batch size */ __( 'Back-fill next %d', 'nera-strikeawin' ), self::BATCH ),
				'primary',
				'submit',
				false,
				$scan['pending'] > 0 ? array() : array( 'disabled' => 'disabled' )
			);
		}
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="run_sweep" value="1">';
		submit_button( __( 'Run the sweep now', 'nera-strikeawin' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<p class="saw-muted" style="margin-top:10px">';
		echo '<strong>' . esc_html__( 'Back-fill is the commit point, not the sweep.', 'nera-strikeawin' ) . '</strong> ';
		echo esc_html__( 'The sweep already runs on its own every five minutes, so a run given a clock that is already in the past will be finalized — and its tickets minted — within about five minutes, whether or not you press the second button. That button only saves you the wait.', 'nera-strikeawin' );
		echo '</p>';
		echo '<p class="saw-muted">' . esc_html__( 'So back-fill at the moment you are ready for the tickets to be issued, not before.', 'nera-strikeawin' ) . '</p>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * One row of the state table.
	 *
	 * @param string $label Label.
	 * @param string $value Value (may contain safe markup).
	 * @param string $help  Explanation.
	 */
	private static function row( $label, $value, $help ) {
		printf(
			'<tr><th scope="row" style="width:260px">%1$s</th><td style="width:120px"><strong>%2$s</strong></td><td class="saw-muted">%3$s</td></tr>',
			esc_html( $label ),
			wp_kses_post( $value ),
			esc_html( $help )
		);
	}
}
