<?php
/**
 * Quiz run engine: server-authoritative scoring, drip delivery, timer, finalize.
 *
 * A run is single-shot and non-resumable. The server owns each slot's deadline;
 * the answer key never leaves the server. Sudden death is removed — wrong/timeout
 * score zero and the run continues through all slots.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Run
 */
class Nera_SAW_Run {

	/**
	 * Start (or resume) a run for a Competition + Tier, consuming one run from the
	 * player's balance (FIFO). Resumes an existing active run without consuming.
	 *
	 * @param int    $competition_id Competition product ID.
	 * @param string $tier_key       Tier key.
	 * @param int    $user_id        Acting user ID.
	 * @return array|WP_Error Run state payload, or WP_Error.
	 */
	public static function start( $competition_id, $tier_key, $user_id ) {
		$competition_id = (int) $competition_id;
		$user_id        = (int) $user_id;
		$tier_key       = (string) $tier_key;

		if ( ! Nera_SAW_Competition_Config::is_competition( $competition_id ) ) {
			return new WP_Error( 'saw_not_competition', __( 'Not a Strike A Win competition.', 'nera-strikeawin' ) );
		}

		// Resume an in-progress run for this competition (do not consume another).
		$resumed = self::resume_active_run( $competition_id, $user_id );
		if ( is_wp_error( $resumed ) ) {
			return $resumed;
		}
		if ( null !== $resumed ) {
			return $resumed;
		}

		// Consume one run from the balance (FIFO, attributed to the granting order).
		$grant = Nera_SAW_Run_Grants::consume_fifo( $user_id, $competition_id, $tier_key );
		if ( ! $grant ) {
			return new WP_Error( 'saw_no_runs', __( 'You have no runs left for this tier. Buy an entry to play.', 'nera-strikeawin' ), array( 'status' => 402 ) );
		}

		// Always draw from the competition's live config so admin distribution
		// updates apply to the next run (grant snapshots are for purchase audit only).
		$config = Nera_SAW_Competition_Config::get( $competition_id );

		$draw = Nera_SAW_Question_Bank::draw_for_run( $config, $user_id, $competition_id );
		if ( empty( $draw['slots'] ) ) {
			// Refund the consumed run; nothing to play.
			Nera_SAW_Run_Grants::refund( (int) $grant->id );
			return new WP_Error( 'saw_no_questions', __( 'No questions are available for this competition.', 'nera-strikeawin' ) );
		}

		$run_id = self::insert_run(
			array(
				'user_id'            => $user_id,
				'competition_id'     => $competition_id,
				'order_id'           => (int) $grant->order_id,
				'variation_id'       => 0,
				'tier_key'           => $tier_key,
				'language'           => (string) $config['language'],
				'config_snapshot'    => wp_json_encode( $config ),
				'status'             => 'active',
				// One run's worth of reserved tickets — settled at finalize.
				'max_possible_spins' => (int) $grant->reserved_per_run,
			)
		);

		$question_ids = array();
		foreach ( $draw['slots'] as $slot ) {
			self::insert_slot( $run_id, $slot );
			$question_ids[] = $slot['question_id'];
		}
		Nera_SAW_Question_Bank::mark_seen( $user_id, $question_ids );

		return self::state( self::get( $run_id ) );
	}

	/**
	 * The player's in-progress run for a competition (if any).
	 *
	 * @param int $competition_id Competition ID.
	 * @param int $user_id        User ID.
	 * @return object|null
	 */
	private static function active_run( $competition_id, $user_id ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'runs' );
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE user_id = %d AND competition_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
				(int) $user_id,
				(int) $competition_id
			)
		);
	}

	/**
	 * Resume an active run, redrawing slots when the live distribution changed
	 * and the player has not answered yet (so admin edits apply immediately).
	 *
	 * @param int $competition_id Competition ID.
	 * @param int $user_id        User ID.
	 * @return array|WP_Error|null Run state, error, or null when no active run.
	 */
	private static function resume_active_run( $competition_id, $user_id ) {
		$active = self::active_run( $competition_id, $user_id );
		if ( ! $active ) {
			return null;
		}

		$live_config = Nera_SAW_Competition_Config::get( $competition_id );
		$slot_count  = (int) self::count_slots( (int) $active->id );
		$expected    = Nera_SAW_Competition_Config::total_questions( $live_config );

		if ( $slot_count !== $expected && 0 === self::count_answered_slots( (int) $active->id ) ) {
			$active = self::redraw_run_slots( $active, $live_config, $user_id, $competition_id );
			if ( is_wp_error( $active ) ) {
				return $active;
			}
		}

		return self::state( $active );
	}

	/**
	 * Replace all slots on an unanswered run with a fresh draw from live config.
	 *
	 * @param object $run            Run row.
	 * @param array  $config         Live competition config.
	 * @param int    $user_id        User ID.
	 * @param int    $competition_id Competition ID.
	 * @return object|WP_Error Updated run row.
	 */
	private static function redraw_run_slots( $run, array $config, $user_id, $competition_id ) {
		$run_id = (int) $run->id;
		$draw   = Nera_SAW_Question_Bank::draw_for_run( $config, $user_id, $competition_id );
		if ( empty( $draw['slots'] ) ) {
			return new WP_Error( 'saw_no_questions', __( 'No questions are available for this competition.', 'nera-strikeawin' ) );
		}

		global $wpdb;
		$slots_table = Nera_SAW_Database::table( 'run_slots' );
		$wpdb->delete( $slots_table, array( 'run_id' => $run_id ), array( '%d' ) );

		self::update_run(
			$run_id,
			array(
				'config_snapshot' => wp_json_encode( $config ),
				'language'        => (string) $config['language'],
			)
		);

		$question_ids = array();
		foreach ( $draw['slots'] as $slot ) {
			self::insert_slot( $run_id, $slot );
			$question_ids[] = $slot['question_id'];
		}
		Nera_SAW_Question_Bank::mark_seen( $user_id, $question_ids );

		return self::get_run( $run_id );
	}

	/**
	 * Fetch a run row by ID.
	 *
	 * @param int $run_id Run ID.
	 * @return object|null
	 */
	private static function get_run( $run_id ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'runs' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $run_id ) );
	}

	/**
	 * Count slots that already have an answer (including timeouts).
	 *
	 * @param int $run_id Run ID.
	 * @return int
	 */
	private static function count_answered_slots( $run_id ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'run_slots' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE run_id = %d AND answered_at IS NOT NULL",
				(int) $run_id
			)
		);
	}

	/**
	 * Serve a slot: stamp served_at + deadline on first view, return question
	 * WITHOUT the correct option.
	 *
	 * @param int $run_id  Run ID.
	 * @param int $user_id Acting user.
	 * @param int $slot_no Slot number.
	 * @return array|WP_Error
	 */
	public static function serve_slot( $run_id, $user_id, $slot_no ) {
		$run = self::owned_run( $run_id, $user_id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( 'active' !== $run->status ) {
			return self::state( $run );
		}

		$slot = self::get_slot( $run_id, $slot_no );
		if ( ! $slot ) {
			return new WP_Error( 'saw_bad_slot', __( 'Invalid slot.', 'nera-strikeawin' ) );
		}
		if ( $slot->answered_at ) {
			return new WP_Error( 'saw_answered', __( 'Slot already answered.', 'nera-strikeawin' ) );
		}

		$config = json_decode( $run->config_snapshot, true );
		$timer  = Nera_SAW_Constants::clamp_timer( isset( $config['timer_seconds'] ) ? $config['timer_seconds'] : Nera_SAW_Constants::TIMER_MAX_SECONDS );

		// Snapshot the full question onto the slot on first serve (immune to later
		// edits/soft-delete; drives scoring + the Report).
		$snapshot = self::ensure_slot_snapshot( $slot );
		if ( ! $snapshot ) {
			return new WP_Error( 'saw_bad_question', __( 'Question missing.', 'nera-strikeawin' ) );
		}

		if ( ! $slot->served_at ) {
			$served   = current_time( 'mysql' );
			$deadline = gmdate( 'Y-m-d H:i:s', self::ts( $served ) + $timer );
			self::update_slot( $slot->id, array( 'served_at' => $served, 'deadline_at' => $deadline ) );
			$slot->served_at   = $served;
			$slot->deadline_at = $deadline;
		}

		$remaining = max( 0, ( self::ts( $slot->deadline_at ) - self::ts( current_time( 'mysql' ) ) ) );

		// Client-safe answers in the slot's randomised display order: original
		// index + text only (never the correct flag). Scoring uses the original index.
		$all   = (array) $snapshot['answers'];
		$order = ! empty( $snapshot['display_order'] ) ? (array) $snapshot['display_order'] : array_keys( $all );
		$answers = array();
		foreach ( $order as $i ) {
			$i = (int) $i;
			if ( isset( $all[ $i ] ) ) {
				$answers[] = array(
					'index' => $i,
					'text'  => (string) $all[ $i ]['text'],
				);
			}
		}

		return array(
			'run_id'        => (int) $run_id,
			'slot_no'       => (int) $slot->slot_no,
			'total_slots'   => (int) self::count_slots( $run_id ),
			'level'         => $slot->level_key,
			'question'      => (string) $snapshot['question_text'],
			'answers'       => $answers,
			'timer_seconds' => (int) $timer,
			'seconds_left'  => (int) $remaining,
			'spins_so_far'  => (int) self::spins_so_far( $run_id ),
		);
	}

	/**
	 * Ensure a slot carries a question snapshot; capture it from the live
	 * question on first access. Returns the decoded snapshot (or null).
	 *
	 * @param object $slot Slot row (updated in place).
	 * @return array|null { question_id, question_text, answers[], correct_index, level_key }
	 */
	private static function ensure_slot_snapshot( &$slot ) {
		if ( ! empty( $slot->question_snapshot ) ) {
			$decoded = json_decode( $slot->question_snapshot, true );
			if ( is_array( $decoded ) && isset( $decoded['answers'] ) ) {
				return $decoded;
			}
		}
		$question = Nera_SAW_Question_Bank::get( (int) $slot->question_id );
		if ( ! $question ) {
			return null;
		}
		$answers = array_values( (array) $question->answers );
		// Stable randomised display order (indices into $answers), captured once so
		// the shown order is consistent across re-serves. Scoring stays by the
		// original index, so shuffling display never affects correctness.
		$display_order = range( 0, max( 0, count( $answers ) - 1 ) );
		if ( count( $answers ) > 1 ) {
			shuffle( $display_order );
		}
		$snapshot = array(
			'question_id'   => (int) $question->id,
			'question_text' => (string) $question->question_text,
			'answers'       => $answers,
			'correct_index' => (int) $question->correct_index,
			'level_key'     => (string) $question->level_key,
			'display_order' => $display_order,
		);
		$json = wp_json_encode( $snapshot );
		self::update_slot( $slot->id, array( 'question_snapshot' => $json ) );
		$slot->question_snapshot = $json;
		return $snapshot;
	}

	/**
	 * Submit an answer for a slot. Scored server-side against the deadline.
	 *
	 * @param int    $run_id  Run ID.
	 * @param int    $user_id Acting user.
	 * @param int    $slot_no Slot number.
	 * @param string $chosen  Chosen option a-d (or '' for none).
	 * @return array|WP_Error
	 */
	public static function submit_answer( $run_id, $user_id, $slot_no, $chosen ) {
		$run = self::owned_run( $run_id, $user_id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( 'active' !== $run->status ) {
			return self::state( $run );
		}

		$slot = self::get_slot( $run_id, $slot_no );
		if ( ! $slot || ! $slot->served_at ) {
			return new WP_Error( 'saw_not_served', __( 'Slot was not served.', 'nera-strikeawin' ) );
		}
		if ( $slot->answered_at ) {
			return new WP_Error( 'saw_answered', __( 'Slot already answered.', 'nera-strikeawin' ) );
		}

		$now_ts    = self::ts( current_time( 'mysql' ) );
		$deadline  = self::ts( $slot->deadline_at ) + Nera_SAW_Constants::LATENCY_GRACE_SECONDS;
		$timed_out = $now_ts > $deadline;

		// Score against the slot snapshot (stable across later question edits).
		$snapshot = self::ensure_slot_snapshot( $slot );
		$answers  = $snapshot ? (array) $snapshot['answers'] : array();
		$correct_index = $snapshot ? (int) $snapshot['correct_index'] : -1;

		// Chosen answer is an index ('' / non-numeric = no answer).
		$has_choice   = ( '' !== (string) $chosen && is_numeric( $chosen ) );
		$chosen_index = $has_choice ? (int) $chosen : null;
		if ( null !== $chosen_index && ! isset( $answers[ $chosen_index ] ) ) {
			$chosen_index = null; // out-of-range guard.
		}

		$correct     = ( ! $timed_out && null !== $chosen_index && $chosen_index === $correct_index );
		$awarded     = $correct ? (int) $slot->reward_base * self::tier_multiplier( $run ) : 0;
		$chosen_text = ( ! $timed_out && null !== $chosen_index ) ? (string) $answers[ $chosen_index ]['text'] : null;
		$correct_txt = isset( $answers[ $correct_index ] ) ? (string) $answers[ $correct_index ]['text'] : null;

		// Per-slot outcome (see CONTEXT "Slot outcome"). A non-answer — timed out or
		// an empty submission — reads as 'timeout'; a wrong pick reads as 'wrong'.
		if ( $correct ) {
			$outcome = 'correct';
		} elseif ( $timed_out || null === $chosen_index ) {
			$outcome = 'timeout';
		} else {
			$outcome = 'wrong';
		}

		self::update_slot(
			$slot->id,
			array(
				'answered_at'   => current_time( 'mysql' ),
				'chosen_index'  => $timed_out ? null : $chosen_index,
				'chosen_text'   => $chosen_text,
				'correct_text'  => $correct_txt,
				'is_correct'    => $correct ? 1 : 0,
				'outcome'       => $outcome,
				'spins_awarded' => $awarded,
			)
		);

		$next = self::next_unanswered_slot( $run_id );
		if ( ! $next ) {
			// Score only — do NOT mint here. Minting is heavy (creates every earned
			// LFW ticket + confirm + email) and would block this answer request, so
			// the client could never reach the results screen (ADR 0011). The mint is
			// deferred to complete_run(), which the results screen calls behind its
			// "Adding your tickets…" spinner. (abandon()/expired still mint inline —
			// they have no results screen to defer to.)
			self::finalize_scoring( $run_id );
		}

		$state           = self::state( self::get( $run_id ) );
		$state['result'] = array(
			'slot_no'       => (int) $slot_no,
			'correct'       => (bool) $correct,
			'timed_out'     => (bool) $timed_out,
			'spins_awarded' => (int) $awarded,
		);
		return $state;
	}

	/**
	 * Finalize a run (idempotent): settle pool, mark finalized, and mint the
	 * earned LFW tickets inline. Used by the paths that have no results screen to
	 * defer to — abandon() and the expired-run cron — so their tickets are still
	 * minted (ADR 0011). Normal completion does NOT call this: submit_answer() only
	 * scores (finalize_scoring) and the results screen mints via complete_run(), so
	 * the heavy mint never blocks the answer request.
	 *
	 * @param int    $run_id     Run ID.
	 * @param string $end_reason Why the run ended: completed|abandoned|expired.
	 * @return bool
	 */
	public static function finalize( $run_id, $end_reason = 'completed' ) {
		if ( ! self::finalize_scoring( $run_id, $end_reason ) ) {
			return false;
		}
		self::mint_tickets_for_run( $run_id );
		return true;
	}

	/**
	 * Score settlement only — fast path after the last answer (no LFW mint).
	 *
	 * @param int    $run_id     Run ID.
	 * @param string $end_reason Why the run ended: completed|abandoned|expired.
	 * @return bool
	 */
	public static function finalize_scoring( $run_id, $end_reason = 'completed' ) {
		$run = self::get( $run_id );
		if ( ! $run || 'finalized' === $run->status ) {
			return false;
		}

		$correct = (int) self::count_correct( $run_id );
		$spins   = (int) self::spins_so_far( $run_id );

		self::update_run(
			$run_id,
			array(
				'status'          => 'finalized',
				'end_reason'      => (string) $end_reason,
				'correct_count'   => $correct,
				'spins_confirmed' => $spins,
				'finalized_at'    => current_time( 'mysql' ),
			)
		);

		$reserved_slice = (int) $run->max_possible_spins;
		if ( $reserved_slice > 0 ) {
			Nera_SAW_Spin_Pool::confirm( (int) $run->competition_id, $reserved_slice, $spins );
		}

		return true;
	}

	/**
	 * Mint LFW tickets for a finalized run (idempotent). Returns this run's numbers.
	 *
	 * @param int $run_id Run ID.
	 * @return string[]
	 */
	public static function mint_tickets_for_run( $run_id ) {
		$run = self::get( $run_id );
		if ( ! $run || 'finalized' !== $run->status ) {
			return array();
		}

		$spins = (int) $run->spins_confirmed;
		if ( $spins < 1 ) {
			return array();
		}

		if ( self::tickets_minted_for_run( $run_id ) ) {
			return self::collect_run_ticket_numbers( $run_id );
		}

		$numbers = Nera_SAW_Ticket_Award::award( (int) $run->order_id, (int) $run->competition_id, (int) $run->user_id, $spins, (string) $run->tier_key );
		$picked  = is_array( $numbers ) ? $numbers : array();
		self::allocate_numbers_to_slots( $run_id, $picked );
		Nera_SAW_Ticket_Award::prune_orphan_tickets_for_order( (int) $run->order_id, (int) $run->competition_id );
		Nera_SAW_Integrations::sync_public_sold_count( (int) $run->competition_id );
		self::maybe_close_competition( $run );

		return self::collect_run_ticket_numbers( $run_id );
	}

	/**
	 * Client completion payload: mint tickets (if needed) + balances.
	 *
	 * @param int $run_id  Run ID.
	 * @param int $user_id User ID.
	 * @return array|WP_Error
	 */
	public static function complete_run( $run_id, $user_id ) {
		$run = self::owned_run( $run_id, $user_id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( 'finalized' !== $run->status ) {
			return new WP_Error( 'saw_not_finalized', __( 'This run is not finished yet.', 'nera-strikeawin' ), array( 'status' => 400 ) );
		}

		$numbers  = self::mint_tickets_for_run( (int) $run_id );
		$balances = Nera_SAW_Run_Grants::balance( (int) $user_id, (int) $run->competition_id );
		$tier_key = (string) $run->tier_key;

		$config     = json_decode( $run->config_snapshot, true );
		$tier_label = $tier_key;
		if ( is_array( $config ) ) {
			$tier = Nera_SAW_Competition_Config::tier( $config, $tier_key );
			if ( $tier && ! empty( $tier['label'] ) ) {
				$tier_label = (string) $tier['label'];
			}
		}

		return array(
			'run_id'                => (int) $run_id,
			'competition_id'        => (int) $run->competition_id,
			'order_id'              => (int) $run->order_id,
			'tier_key'              => $tier_key,
			'tier_label'            => $tier_label,
			'spins_final'           => (int) $run->spins_confirmed,
			'ticket_numbers'        => $numbers,
			'runs_remaining_total'  => Nera_SAW_Run_Grants::balance_total( (int) $user_id, (int) $run->competition_id ),
			'runs_remaining_tier'   => isset( $balances[ $tier_key ] ) ? (int) $balances[ $tier_key ] : 0,
		);
	}

	/**
	 * Abandon an active run: the player deliberately left mid-quiz (ADR 0010).
	 * Every unanswered slot is snapshotted (so its question detail is stored even
	 * if it was never displayed) and scored zero, tickets already earned are
	 * minted, and the run is finalized with end_reason = abandoned. Idempotent:
	 * a run already finalized (e.g. by a duplicate beacon or the cron backstop)
	 * just returns its completion summary without re-scoring.
	 *
	 * @param int $run_id  Run ID.
	 * @param int $user_id Acting user.
	 * @return array|WP_Error Completion summary, or WP_Error.
	 */
	public static function abandon( $run_id, $user_id ) {
		$run = self::owned_run( $run_id, $user_id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( 'active' === $run->status ) {
			global $wpdb;
			$t      = Nera_SAW_Database::table( 'run_slots' );
			$now    = current_time( 'mysql' );
			$now_ts = self::ts( $now );
			$slots  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE run_id = %d AND answered_at IS NULL", (int) $run_id ) );

			foreach ( (array) $slots as $slot ) {
				// Capture the question detail even for slots never served.
				self::ensure_slot_snapshot( $slot );
				// A served slot whose deadline already lapsed is a genuine timeout;
				// anything still in time (or never served) is abandoned-on-leave.
				$expired = $slot->deadline_at
					&& ( self::ts( $slot->deadline_at ) + Nera_SAW_Constants::LATENCY_GRACE_SECONDS ) < $now_ts;
				self::update_slot(
					(int) $slot->id,
					array(
						'answered_at'   => $now,
						'chosen_index'  => null,
						'is_correct'    => 0,
						'outcome'       => $expired ? 'timeout' : 'abandoned',
						'spins_awarded' => 0,
					)
				);
			}

			self::finalize( (int) $run_id, 'abandoned' );
		}

		return self::complete_run( (int) $run_id, (int) $user_id );
	}

	/**
	 * Flag an active run as errored (ADR 0013): a client error/stall left it stuck
	 * mid-quiz. Marks end_reason='errored' WITHOUT finalizing, so the Report can
	 * surface it and offer Restore. No-op unless the run is active and owned — a run
	 * the player later retries and finishes gets overwritten to completed/abandoned
	 * by finalize_scoring(), so only genuinely stuck runs keep the marker.
	 *
	 * @param int $run_id  Run ID.
	 * @param int $user_id Acting user (ownership guard).
	 * @return void
	 */
	public static function mark_errored( $run_id, $user_id ) {
		$run = self::get( $run_id );
		if ( ! $run || (int) $run->user_id !== (int) $user_id ) {
			return;
		}
		if ( 'active' !== $run->status || 'errored' === (string) $run->end_reason ) {
			return;
		}
		self::update_run( $run_id, array( 'end_reason' => 'errored' ) );
	}

	/**
	 * Restore an errored run (admin action, ADR 0013): the run got stuck through a
	 * fault that was not the player's (e.g. a network drop), so return the consumed
	 * run to their balance and void the stuck run. Errored runs never minted (mint
	 * only happens at finish/leave), so there are no tickets to revert.
	 *
	 * Strictly limited to active + errored runs so it can never void an in-progress
	 * or finalized run.
	 *
	 * @param int $run_id Run ID.
	 * @return array|WP_Error Result summary, or WP_Error.
	 */
	public static function restore( $run_id ) {
		$run = self::get( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'saw_no_run', __( 'Run not found.', 'nera-strikeawin' ) );
		}
		if ( 'active' !== $run->status || 'errored' !== (string) $run->end_reason ) {
			return new WP_Error( 'saw_not_restorable', __( 'Only an errored (stuck) run can be restored.', 'nera-strikeawin' ), array( 'status' => 400 ) );
		}

		$refunded = Nera_SAW_Run_Grants::refund_one(
			(int) $run->user_id,
			(int) $run->competition_id,
			(string) $run->tier_key,
			(int) $run->order_id
		);

		self::update_run(
			$run_id,
			array(
				'status'       => 'voided',
				'finalized_at' => current_time( 'mysql' ),
			)
		);

		if ( class_exists( 'Nera_SAW_Log' ) ) {
			Nera_SAW_Log::add(
				'run_restored',
				array(
					'run_id'         => (int) $run_id,
					'competition_id' => (int) $run->competition_id,
					'tier_key'       => (string) $run->tier_key,
					'order_id'       => (int) $run->order_id,
					'user_id'        => (int) $run->user_id,
					'message'        => sprintf(
						'Run restored by admin #%d — run %s to player balance, run voided.',
						get_current_user_id(),
						$refunded ? 'returned' : 'NOT returned (no consumed grant found)'
					),
					'context'        => array( 'admin_id' => get_current_user_id(), 'refunded' => $refunded ),
				)
			);
		}

		return array(
			'run_id'   => (int) $run_id,
			'refunded' => (bool) $refunded,
			'status'   => 'voided',
		);
	}

	/**
	 * Whether ticket numbers were allocated to earning slots for this run.
	 *
	 * @param int $run_id Run ID.
	 * @return bool
	 */
	private static function tickets_minted_for_run( $run_id ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'run_slots' );
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT awarded_numbers FROM {$t}
				 WHERE run_id = %d AND spins_awarded > 0
				   AND awarded_numbers IS NOT NULL AND awarded_numbers != '' AND awarded_numbers != '[]'
				 LIMIT 1",
				(int) $run_id
			)
		);
		return (bool) $row;
	}

	/**
	 * All ticket numbers minted for this run (from slot allocation).
	 *
	 * @param int $run_id Run ID.
	 * @return string[]
	 */
	public static function collect_run_ticket_numbers( $run_id ) {
		global $wpdb;
		$t     = Nera_SAW_Database::table( 'run_slots' );
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT awarded_numbers FROM {$t} WHERE run_id = %d AND awarded_numbers IS NOT NULL AND awarded_numbers != ''", (int) $run_id ) );
		$out   = array();
		foreach ( (array) $rows as $raw ) {
			$chunk = json_decode( (string) $raw, true );
			if ( is_array( $chunk ) ) {
				foreach ( $chunk as $num ) {
					$num = (string) $num;
					if ( '' !== $num ) {
						$out[] = $num;
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Ticket numbers earned on all finalized runs for one order line tier.
	 *
	 * @param int    $order_id       Order ID.
	 * @param int    $competition_id Competition product ID.
	 * @param string $tier_key       Tier key.
	 * @return string[]
	 */
	public static function ticket_numbers_for_order_tier( $order_id, $competition_id, $tier_key ) {
		global $wpdb;
		$t       = Nera_SAW_Database::table( 'runs' );
		$run_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE order_id = %d AND competition_id = %d AND tier_key = %s AND status = 'finalized'",
				(int) $order_id,
				(int) $competition_id,
				(string) $tier_key
			)
		);
		$numbers = array();
		foreach ( (array) $run_ids as $run_id ) {
			$numbers = array_merge( $numbers, self::collect_run_ticket_numbers( (int) $run_id ) );
		}
		return array_values( array_unique( array_map( 'strval', $numbers ) ) );
	}

	/**
	 * Ticket numbers earned on all finalized runs for an order (every tier).
	 *
	 * @param int $order_id       Order ID.
	 * @param int $competition_id Competition product ID.
	 * @return string[]
	 */
	public static function ticket_numbers_for_order( $order_id, $competition_id ) {
		global $wpdb;
		$t       = Nera_SAW_Database::table( 'runs' );
		$run_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE order_id = %d AND competition_id = %d AND status = 'finalized'",
				(int) $order_id,
				(int) $competition_id
			)
		);
		$numbers = array();
		foreach ( (array) $run_ids as $run_id ) {
			$numbers = array_merge( $numbers, self::collect_run_ticket_numbers( (int) $run_id ) );
		}
		return array_values( array_unique( array_map( 'strval', $numbers ) ) );
	}


	/**
	 * Finalize runs whose last slot deadline has passed (abandoned). Cron-driven.
	 */
	public static function finalize_stale() {
		global $wpdb;
		$runs  = Nera_SAW_Database::table( 'runs' );
		$slots = Nera_SAW_Database::table( 'run_slots' );
		$now   = current_time( 'mysql' );

		// Active runs with no unanswered slot still within its deadline.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT r.id FROM {$runs} r
				 WHERE r.status = 'active'
				 AND NOT EXISTS (
				   SELECT 1 FROM {$slots} s
				   WHERE s.run_id = r.id AND s.answered_at IS NULL
				   AND ( s.deadline_at IS NULL OR s.deadline_at >= %s )
				 )",
				$now
			)
		);
		foreach ( (array) $ids as $rid ) {
			// Mark unanswered, past-deadline slots as timeouts. These runs went
			// silent (no leave captured), so the run ends as 'expired' (ADR 0010).
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$slots} SET answered_at = %s, is_correct = 0, outcome = 'timeout', spins_awarded = 0
					 WHERE run_id = %d AND answered_at IS NULL",
					$now,
					(int) $rid
				)
			);
			self::finalize( (int) $rid, 'expired' );
		}
	}

	/**
	 * Allocate minted LFW numbers to the slots that earned them, in play order.
	 * Each correct slot takes its spins_awarded next numbers; wrong/timeout slots
	 * take none. Deterministic and sums to the minted total (ADR 0003).
	 *
	 * @param int      $run_id  Run ID.
	 * @param string[] $numbers Minted ticket numbers (in mint order).
	 */
	private static function allocate_numbers_to_slots( $run_id, array $numbers ) {
		global $wpdb;
		$t      = Nera_SAW_Database::table( 'run_slots' );
		$slots  = $wpdb->get_results( $wpdb->prepare( "SELECT id, spins_awarded FROM {$t} WHERE run_id = %d AND spins_awarded > 0 ORDER BY slot_no ASC", (int) $run_id ) );
		$cursor = 0;
		$total  = count( $numbers );
		foreach ( (array) $slots as $slot ) {
			$take  = (int) $slot->spins_awarded;
			$chunk = array();
			for ( $i = 0; $i < $take && $cursor < $total; $i++, $cursor++ ) {
				$chunk[] = (string) $numbers[ $cursor ];
			}
			self::update_slot( (int) $slot->id, array( 'awarded_numbers' => wp_json_encode( $chunk ) ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helpers / data access                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Tier multiplier for a run (from its snapshot).
	 *
	 * @param object $run Run row.
	 * @return int
	 */
	private static function tier_multiplier( $run ) {
		$config = json_decode( $run->config_snapshot, true );
		$tier   = is_array( $config ) ? Nera_SAW_Competition_Config::tier( $config, $run->tier_key ) : null;
		return $tier ? max( 1, (int) $tier['multiplier'] ) : 1;
	}

	/**
	 * Close the competition if capacity can no longer honour the lowest tier.
	 *
	 * @param object $run Run row.
	 */
	private static function maybe_close_competition( $run ) {
		$config = json_decode( $run->config_snapshot, true );
		if ( ! is_array( $config ) || empty( $config['tiers'] ) ) {
			return;
		}
		$lowest = PHP_INT_MAX;
		foreach ( $config['tiers'] as $tier ) {
			$max    = Nera_SAW_Competition_Config::max_possible_spins( $config, $tier['key'] );
			$lowest = min( $lowest, $max );
		}
		if ( PHP_INT_MAX !== $lowest ) {
			Nera_SAW_Spin_Pool::maybe_close( (int) $run->competition_id, (int) $lowest );
		}
	}

	/**
	 * Find the first Strikeawin competition line item: [item_id, product_id, variation_id].
	 *
	 * @param WC_Order $order Order.
	 * @return array|null
	 */
	private static function find_competition_item( $order ) {
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$pid = (int) $item->get_product_id();
			if ( Nera_SAW_Competition_Config::is_competition( $pid ) ) {
				return array( $item_id, $pid, (int) $item->get_variation_id() );
			}
		}
		return null;
	}

	/**
	 * MySQL datetime -> unix timestamp (site timezone aware).
	 *
	 * @param string $mysql Datetime.
	 * @return int
	 */
	private static function ts( $mysql ) {
		return (int) strtotime( $mysql );
	}

	/**
	 * Build the client-safe run state payload.
	 *
	 * @param object $run Run row.
	 * @return array
	 */
	private static function state( $run ) {
		$next = self::next_unanswered_slot( (int) $run->id );
		return array(
			'run_id'       => (int) $run->id,
			'status'       => $run->status,
			'total_slots'  => (int) self::count_slots( (int) $run->id ),
			'next_slot'    => $next ? (int) $next->slot_no : null,
			'spins_so_far' => (int) self::spins_so_far( (int) $run->id ),
			'spins_final'  => 'finalized' === $run->status ? (int) $run->spins_confirmed : null,
			'competition_id' => (int) $run->competition_id,
			'tier_key'     => (string) $run->tier_key,
			'order_id'     => (int) $run->order_id,
		);
	}

	/**
	 * Fetch + ownership check.
	 *
	 * @param int $run_id  Run ID.
	 * @param int $user_id User ID.
	 * @return object|WP_Error
	 */
	private static function owned_run( $run_id, $user_id ) {
		$run = self::get( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'saw_no_run', __( 'Run not found.', 'nera-strikeawin' ) );
		}
		if ( (int) $run->user_id !== (int) $user_id ) {
			return new WP_Error( 'saw_forbidden', __( 'Not your run.', 'nera-strikeawin' ), array( 'status' => 403 ) );
		}
		return $run;
	}

	/**
	 * Get a run row.
	 *
	 * @param int $run_id Run ID.
	 * @return object|null
	 */
	public static function get( $run_id ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'runs' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $run_id ) );
	}

	/**
	 * Existing run for an order+competition.
	 *
	 * @param int $order_id       Order ID.
	 * @param int $competition_id Product ID.
	 * @return object|null
	 */
	private static function get_by_order_competition( $order_id, $competition_id ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'runs' );
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE order_id = %d AND competition_id = %d ORDER BY id DESC LIMIT 1",
				(int) $order_id,
				(int) $competition_id
			)
		);
	}

	/**
	 * Insert a run row.
	 *
	 * @param array $data Data.
	 * @return int
	 */
	private static function insert_run( array $data ) {
		global $wpdb;
		$t   = Nera_SAW_Database::table( 'runs' );
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$t,
			array(
				'user_id'            => (int) $data['user_id'],
				'competition_id'     => (int) $data['competition_id'],
				'order_id'           => (int) $data['order_id'],
				'variation_id'       => (int) $data['variation_id'],
				'tier_key'           => (string) $data['tier_key'],
				'language'           => (string) $data['language'],
				'config_snapshot'    => (string) $data['config_snapshot'],
				'status'             => (string) $data['status'],
				'max_possible_spins' => (int) $data['max_possible_spins'],
				'created_at'         => $now,
				'started_at'         => $now,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a run row.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $data   Fields.
	 */
	private static function update_run( $run_id, array $data ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'runs' );
		$wpdb->update( $t, $data, array( 'id' => (int) $run_id ) );
	}

	/**
	 * Insert a run slot.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $slot   Slot spec.
	 */
	private static function insert_slot( $run_id, array $slot ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'run_slots' );
		$wpdb->insert(
			$t,
			array(
				'run_id'      => (int) $run_id,
				'slot_no'     => (int) $slot['slot_no'],
				'level_key'   => (string) $slot['level_key'],
				'question_id' => (int) $slot['question_id'],
				'reward_base' => (int) $slot['reward_base'],
			),
			array( '%d', '%d', '%s', '%d', '%d' )
		);
	}

	/**
	 * Update a slot row.
	 *
	 * @param int   $slot_id Slot row ID.
	 * @param array $data    Fields.
	 */
	private static function update_slot( $slot_id, array $data ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'run_slots' );
		$wpdb->update( $t, $data, array( 'id' => (int) $slot_id ) );
	}

	/**
	 * Get a slot by run + slot number.
	 *
	 * @param int $run_id  Run ID.
	 * @param int $slot_no Slot number.
	 * @return object|null
	 */
	private static function get_slot( $run_id, $slot_no ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'run_slots' );
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE run_id = %d AND slot_no = %d", (int) $run_id, (int) $slot_no )
		);
	}

	/**
	 * The next unanswered slot (lowest slot_no).
	 *
	 * @param int $run_id Run ID.
	 * @return object|null
	 */
	private static function next_unanswered_slot( $run_id ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'run_slots' );
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE run_id = %d AND answered_at IS NULL ORDER BY slot_no ASC LIMIT 1", (int) $run_id )
		);
	}

	/**
	 * Count slots in a run.
	 *
	 * @param int $run_id Run ID.
	 * @return int
	 */
	private static function count_slots( $run_id ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'run_slots' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE run_id = %d", (int) $run_id ) );
	}

	/**
	 * Count correct answers.
	 *
	 * @param int $run_id Run ID.
	 * @return int
	 */
	private static function count_correct( $run_id ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'run_slots' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE run_id = %d AND is_correct = 1", (int) $run_id ) );
	}

	/**
	 * Spins earned so far in a run.
	 *
	 * @param int $run_id Run ID.
	 * @return int
	 */
	private static function spins_so_far( $run_id ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'run_slots' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(spins_awarded),0) FROM {$t} WHERE run_id = %d", (int) $run_id ) );
	}

	/**
	 * The reservation linked to a run.
	 *
	 * @param int $run_id Run ID.
	 * @return object|null
	 */
	private static function reservation_for_run( $run_id ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'reservations' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE run_id = %d ORDER BY id DESC LIMIT 1", (int) $run_id ) );
	}
}
