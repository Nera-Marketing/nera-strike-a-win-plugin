<?php
/**
 * Quiz run engine: server-authoritative scoring, drip delivery, timer, finalize.
 *
 * A run IS resumable: start() finds an active run and returns it. The server owns
 * the clock end to end — the run has a wall-clock `expires_at` stamped when it
 * starts, and each slot's deadline is chained forward from the moment the previous
 * slot ended, so the clock keeps running while the player is disconnected. A
 * player who reconnects lands on whichever question is live and scores zero for
 * the ones that lapsed meanwhile (ADR 0020).
 *
 * The answer key never leaves the server. Sudden death is removed — wrong/timeout
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
	public static function start( $competition_id, $tier_key, $user_id, $language = '' ) {
		$competition_id = (int) $competition_id;
		$user_id        = (int) $user_id;
		$tier_key       = (string) $tier_key;

		if ( ! Nera_SAW_Competition_Config::is_competition( $competition_id ) ) {
			return new WP_Error( 'saw_not_competition', __( 'Not a Strike A Win competition.', 'nera-strikeawin' ) );
		}

		// Resume an in-progress run for this competition (do not consume another).
		// A language requested on a resume is not honoured — the run already has
		// one, and self::redraw_run_slots() (inside resume_active_run()) is what
		// keeps a redraw in it rather than re-resolving.
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

		// Freeze the language and the Quiz Method this run is actually drawn under,
		// so nothing downstream — the draw, the stage display, a later resume — has
		// to ask a live setting again and risk disagreeing with how the run was
		// built. See resolve_run_language() and Nera_SAW_Question_Bank::draw_for_run().
		$config['language']    = self::resolve_run_language( $competition_id, $language );
		$config['quiz_method'] = Nera_SAW_Mode::quiz_method( $competition_id );

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

		foreach ( $draw['slots'] as $slot ) {
			self::insert_slot( $run_id, $slot );
		}

		// Questions are marked seen when a slot is SERVED, not here. A run that is
		// abandoned before question 3 must not burn questions 4-10 out of that
		// player's bank forever — see serve_slot() and ADR 0020.
		self::start_clock( $run_id, count( $draw['slots'] ), $config );

		$run   = self::get( $run_id );
		$state = self::state( $run );
		// The whole run's content, once, instead of one fetch per question as the
		// player advances — see bulk_slots_payload()'s own docblock for why this
		// carries no correct-answer flag and does not start any timer by itself.
		// $state['slots'] = self::bulk_slots_payload( $run );
		// EXPERIMENTAL: one run-wide timer value (CMS-configured, not per-run state)
		// so the client can arm each question's clock itself instead of asking
		// serve_slot() for seconds_left — see the docblock above for why a full
		// reset instead of a real-time countdown is the accepted tradeoff here.
		$state['timer_seconds']     = self::timer_for( $config );
		$state['obfuscation_seed'] = wp_rand( 1, 254 );
		$state['slots']            = self::bulk_slots_payload_experimental_with_answers( $run, $state['obfuscation_seed'] );
		return $state;
	}

	/**
	 * Settle which language a NEW run is drawn in.
	 *
	 * First hit wins:
	 *   1. An explicit request, if that language is actually playable for this
	 *      competition (declared in Polylang AND the bank has enough at every
	 *      level the distribution asks for) — see playable_languages().
	 *   2. Polylang's own current-language resolution, unvalidated — a player who
	 *      never saw a language screen (nothing to choose from, or the request
	 *      skipped it) gets what the site was already showing them, which is
	 *      today's behaviour and must not change under them.
	 *   3. '' — no explicit language at all. draw_for_run() reads this as "leave
	 *      the query at Polylang's ambient scope", which is a no-op without
	 *      Polylang and identical to (2) with it.
	 *
	 * NEVER Nera_SAW_Competition_Config::get()'s bare 'en' default: that default
	 * exists so the config array always has the key, not as a claim that this run
	 * is in English. Trusting it here would hard-lock every run to English on any
	 * multi-language site, silently overriding whatever language the player was
	 * actually looking at the site in.
	 *
	 * @param int    $competition_id Competition product ID.
	 * @param string $requested      What the player asked for, or ''.
	 * @return string Language code, or '' when nothing can be resolved.
	 */
	private static function resolve_run_language( $competition_id, $requested ) {
		$requested = sanitize_key( (string) $requested );

		if ( '' !== $requested && in_array( $requested, self::playable_languages( $competition_id ), true ) ) {
			return $requested;
		}

		if ( class_exists( 'Nera_SAW_Language' ) && Nera_SAW_Language::engine_present() ) {
			$current = Nera_SAW_Language::current();
			if ( '' !== $current ) {
				return $current;
			}
		}

		return '';
	}

	/**
	 * Which languages this competition can actually be played in: declared in
	 * Polylang, AND the bank has enough questions at every level the competition's
	 * distribution asks for. The second half is what stops a language screen
	 * offering a choice that would immediately reuse already-seen questions or
	 * fall short of the run's length — see CONTEXT.md "Bank health".
	 *
	 * With no multilingual engine this returns empty, not `[ 'en' ]` — there is no
	 * *choice* to speak of on a monolingual site, which is what
	 * `Nera_SAW_Language_Switcher`-style callers use to decide whether a language
	 * screen has anything to show at all.
	 *
	 * @param int $competition_id Competition product ID.
	 * @return array<int, string>
	 */
	public static function playable_languages( $competition_id ) {
		$competition_id = (int) $competition_id;

		if ( ! class_exists( 'Nera_SAW_Language' ) || ! Nera_SAW_Language::is_multilingual() ) {
			return array();
		}

		$config       = Nera_SAW_Competition_Config::get( $competition_id );
		$distribution = array_filter( (array) $config['distribution'] );
		if ( empty( $distribution ) ) {
			return array();
		}

		$cat_terms = Nera_SAW_Question_Bank::product_category_ids( $competition_id );
		$offerable = array();

		foreach ( Nera_SAW_Language::codes() as $code ) {
			$healthy = true;
			foreach ( $distribution as $level_key => $need ) {
				if ( Nera_SAW_Question_Bank::count_available( $level_key, $cat_terms, $code ) < (int) $need ) {
					$healthy = false;
					break;
				}
			}
			if ( $healthy ) {
				$offerable[] = $code;
			}
		}

		return $offerable;
	}

	/**
	 * The player's in-progress run for a competition (if any).
	 *
	 * An `errored` run is not in progress: it is parked for an administrator, and
	 * counting it here would make the player's next Start resume a run that can no
	 * longer be played, instead of using the run they still hold.
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
				"SELECT * FROM {$t} WHERE user_id = %d AND competition_id = %d AND status = 'active' AND end_reason <> 'errored' ORDER BY id DESC LIMIT 1",
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

		// Held too long without a Resume: it goes to an administrator, and this
		// Start begins a fresh run from the balance instead.
		if ( self::interruption_expired( $active ) ) {
			self::mark_interruption_expired( $active );
			return null;
		}

		// An explicit Start on a held run is a Resume: the clock picks up where the
		// last heartbeat left it.
		if ( self::pending_info( $active ) ) {
			$active = self::thaw( $active );
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

		$state           = self::state( $active );
		// $state['slots'] = self::bulk_slots_payload( $active );
		$state['timer_seconds']     = self::timer_for( $live_config );
		$state['obfuscation_seed'] = wp_rand( 1, 254 );
		$state['slots']            = self::bulk_slots_payload_experimental_with_answers( $active, $state['obfuscation_seed'] );
		return $state;
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

		/*
		 * Freeze the same two things start() does, but the language comes from the
		 * run itself rather than being re-resolved: this player is already playing
		 * in it, nothing has been answered yet (the only condition under which a
		 * redraw happens), and a redraw must not change the language out from under
		 * them just because the site's ambient language has since moved on. The
		 * Quiz Method IS re-resolved — "always draw from live config" already
		 * applies to distribution, and nothing has been shown yet for it to disagree
		 * with.
		 */
		$config['language']    = (string) $run->language;
		$config['quiz_method'] = Nera_SAW_Mode::quiz_method( $competition_id );

		$draw = Nera_SAW_Question_Bank::draw_for_run( $config, $user_id, $competition_id );
		if ( empty( $draw['slots'] ) ) {
			return new WP_Error( 'saw_no_questions', __( 'No questions are available for this competition.', 'nera-strikeawin' ) );
		}

		global $wpdb;
		$slots_table = Nera_SAW_Database::table( 'run_slots' );

		// All or nothing. Deleting then re-inserting without a transaction can
		// leave a run with zero slots and a grant already consumed, which is
		// unplayable and unrefundable (ADR 0020).
		$wpdb->query( 'START TRANSACTION' );

		$wpdb->delete( $slots_table, array( 'run_id' => $run_id ), array( '%d' ) );

		self::update_run(
			$run_id,
			array(
				'config_snapshot' => wp_json_encode( $config ),
				'language'        => (string) $config['language'],
			)
		);

		$inserted = 0;
		foreach ( $draw['slots'] as $slot ) {
			if ( self::insert_slot( $run_id, $slot ) ) {
				$inserted++;
			}
		}

		if ( $inserted !== count( $draw['slots'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'saw_redraw_failed', __( 'The quiz could not be prepared. Please try again.', 'nera-strikeawin' ) );
		}

		$wpdb->query( 'COMMIT' );

		// The redrawn run may be a different length, so the wall clock restarts
		// with it. Nothing was answered — resume_active_run() only redraws in that
		// case — so no earned time is lost.
		self::start_clock( $run_id, $inserted, $config );

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

		// Settle anything that lapsed while the player was away before deciding
		// what to serve. This is what makes a reconnect land on the live question.
		$run = self::catch_up( $run );

		if ( 'active' !== $run->status ) {
			return self::state( $run );
		}

		$held = self::guard_held( $run );
		if ( $held ) {
			return $held;
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
			$served = current_time( 'mysql' );

			// The question's clock starts here, on first sight, and never again:
			// fetching an already-served slot does NOT restart it, which is the whole
			// point of the server owning the clock (ADR 0030).
			$deadline_at = gmdate( 'Y-m-d H:i:s', self::ts( $served ) + $timer );

			self::update_slot(
				$slot->id,
				array(
					'served_at'   => $served,
					'deadline_at' => $deadline_at,
				)
			);
			$slot->served_at   = $served;
			$slot->deadline_at = $deadline_at;

			self::roll_expiry( $run, $slot, $config );

			// Seen is stamped HERE, on first sight, not when the slot was drawn.
			// A drawn-but-never-served question stays available to this player.
			Nera_SAW_Question_Bank::mark_seen( (int) $run->user_id, array( (int) $slot->question_id ) );
		}

		$remaining = max( 0, ( self::ts( $slot->deadline_at ) - self::ts( current_time( 'mysql' ) ) ) );

		/*
		 * No question text, answers, level or stage metadata here any more — the
		 * client already has every slot's full content from bulk_slots_payload()
		 * (sent once, at Start/Resume), keyed by slot_no. Repeating it on every
		 * arm call was the bulk of this endpoint's own work (building the
		 * shuffled answers list, the stage lookup) and its response size; this is
		 * now only the one thing that has to happen at the moment the player
		 * actually reaches the slot — stamp its clock — plus the small amount of
		 * live progress the client cannot already know locally.
		 */
		return array(
			'run_id'        => (int) $run_id,
			'slot_no'       => (int) $slot->slot_no,
			'total_slots'   => (int) self::count_slots( $run_id ),
			'timer_seconds' => (int) $timer,
			'seconds_left'  => (int) $remaining,
			'spins_so_far'  => (int) self::spins_so_far( $run_id ),
		);
	}

	/**
	 * Every one of a run's questions and answer options at once — the same
	 * shape serve_slot() builds per slot, minus the fields that only mean
	 * something at the moment a slot is actually reached (`timer_seconds`,
	 * `seconds_left`, `spins_so_far`, `total_slots`), and with no correct-answer
	 * flag anywhere in it, same guarantee as serve_slot(). Sent once, from
	 * start()/resume(), so the client can hold a whole run's content locally
	 * and switch screens without a fetch per question — see those methods'
	 * own callers for why.
	 *
	 * Deliberately does NOT call serve_slot() or touch served_at/deadline_at/
	 * mark_seen(): stamping a slot's timer here, before the player has
	 * actually reached it, is the exact bug ADR 0030 fixed (time bleeding from
	 * earlier screens into a later question). ensure_slot_snapshot() is safe
	 * to call this early — it only freezes which question this slot drew and
	 * its randomised display order, not when its clock starts.
	 *
	 * @param object $run Run row.
	 * @return array<int, array> One entry per slot, in slot order.
	 */
	private static function bulk_slots_payload( $run ) {
		global $wpdb;
		$t     = Nera_SAW_Database::table( 'run_slots' );
		$slots = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE run_id = %d ORDER BY slot_no ASC", (int) $run->id ) );

		$config     = json_decode( $run->config_snapshot, true );
		$config     = is_array( $config ) ? $config : array();
		$multiplier = self::tier_multiplier( $run );

		/*
		 * Stage assignment for every slot, computed once from the rows already
		 * fetched above — not stage_of() per slot. stage_of() re-runs its own
		 * "SELECT every slot in this run" query on every single call, which is
		 * fine for its real callers (once, for one slot — the live one, or the
		 * next one), but here, called once per slot in a 12-13 iteration loop,
		 * that turned into 12-13 redundant round trips of the same query,
		 * measured on staging at ~2s added to Start alone. Same grouping rule
		 * as stage_of(), just walked across $slots once.
		 */
		$stage_map = self::stage_map_for_slots( (array) $slots, $config );

		$out = array();
		foreach ( (array) $slots as $slot ) {
			$snapshot = self::ensure_slot_snapshot( $slot );
			if ( ! $snapshot ) {
				continue; // Question missing/deleted — skip rather than break the whole payload.
			}

			$all     = (array) $snapshot['answers'];
			$order   = ! empty( $snapshot['display_order'] ) ? (array) $snapshot['display_order'] : array_keys( $all );
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

			$level_def = Nera_SAW_Constants::level( $slot->level_key );
			$stage     = isset( $stage_map[ (int) $slot->slot_no ] )
				? $stage_map[ (int) $slot->slot_no ]
				: array( 'stage_no' => 1, 'stage_count' => 1, 'is_first_of_stage' => true );

			$out[] = array(
				'slot_no'            => (int) $slot->slot_no,
				'level'              => $slot->level_key,
				'level_label'        => $level_def ? (string) $level_def['label'] : '',
				'level_text_color'   => Nera_SAW_Constants::level_text_color( $slot->level_key ),
				'question'           => (string) $snapshot['question_text'],
				'answers'            => $answers,
				'reward'             => (int) $slot->reward_base * $multiplier,
				'quiz_method'        => isset( $config['quiz_method'] ) ? (string) $config['quiz_method'] : Nera_SAW_Mode::QUIZ_RANDOM,
				'stage_no'           => $stage['stage_no'],
				'stage_count'        => $stage['stage_count'],
				'is_first_of_stage'  => $stage['is_first_of_stage'],
				'stage_reward_label' => Nera_SAW_Competition_Config::reward_label( $config, $slot->level_key ),
				'stage_label'        => Nera_SAW_Competition_Config::stage_label(
					$config,
					$slot->level_key,
					$stage['stage_no'],
					$stage['stage_count'],
					$level_def ? (string) $level_def['label'] : ''
				),
			);
		}

		return $out;
	}

	/**
	 * EXPERIMENTAL — evaluated for release at the user's explicit direction,
	 * against the concrete objection already on record: this sends every
	 * question's correct answer to the browser before the player answers
	 * anything, which is trivially readable via the browser's own Network
	 * tab — no tooling or skill beyond that is needed. It reopens exactly the
	 * guarantee `bulk_slots_payload()` above (and serve_slot()'s own docblock,
	 * and every other screen in this run engine) was built to hold: "the
	 * answer key never leaves the server." For a real-money, Gambling Act
	 * 2005 skill-competition, that guarantee is what makes it a skill
	 * competition rather than a lottery with extra steps.
	 *
	 * `$obfuscation_seed` (XOR'd against each correct index) is not a
	 * security boundary and is not presented as one: the seed has to travel
	 * to the client for the client to undo it, the same reason no client-side
	 * "encryption" scheme can work here (see the grilling session this came
	 * out of). It exists only so the correct index is not sitting in the
	 * network payload as a bare, human-legible integer next to its answer
	 * text — a deterrent against a glance, not a lock.
	 *
	 * @param object $run             Run row.
	 * @param int    $obfuscation_seed XOR seed, generated once per call and
	 *                                 returned alongside so the client can undo it.
	 * @return array<int, array> One entry per slot, in slot order.
	 */
	private static function bulk_slots_payload_experimental_with_answers( $run, $obfuscation_seed ) {
		global $wpdb;
		$t     = Nera_SAW_Database::table( 'run_slots' );
		$slots = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE run_id = %d ORDER BY slot_no ASC", (int) $run->id ) );

		$config     = json_decode( $run->config_snapshot, true );
		$config     = is_array( $config ) ? $config : array();
		$multiplier = self::tier_multiplier( $run );
		$stage_map  = self::stage_map_for_slots( (array) $slots, $config );

		$out = array();
		foreach ( (array) $slots as $slot ) {
			$snapshot = self::ensure_slot_snapshot( $slot );
			if ( ! $snapshot ) {
				continue;
			}

			$all     = (array) $snapshot['answers'];
			$order   = ! empty( $snapshot['display_order'] ) ? (array) $snapshot['display_order'] : array_keys( $all );
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

			$level_def     = Nera_SAW_Constants::level( $slot->level_key );
			$stage         = isset( $stage_map[ (int) $slot->slot_no ] )
				? $stage_map[ (int) $slot->slot_no ]
				: array( 'stage_no' => 1, 'stage_count' => 1, 'is_first_of_stage' => true );
			$correct_index = isset( $snapshot['correct_index'] ) ? (int) $snapshot['correct_index'] : -1;

			$out[] = array(
				'slot_no'            => (int) $slot->slot_no,
				'level'              => $slot->level_key,
				'level_label'        => $level_def ? (string) $level_def['label'] : '',
				'level_text_color'   => Nera_SAW_Constants::level_text_color( $slot->level_key ),
				'question'           => (string) $snapshot['question_text'],
				'answers'            => $answers,
				// See this method's own docblock: obfuscated, not protected.
				'answer_key'         => $correct_index ^ $obfuscation_seed,
				'reward'             => (int) $slot->reward_base * $multiplier,
				'quiz_method'        => isset( $config['quiz_method'] ) ? (string) $config['quiz_method'] : Nera_SAW_Mode::QUIZ_RANDOM,
				'stage_no'           => $stage['stage_no'],
				'stage_count'        => $stage['stage_count'],
				'is_first_of_stage'  => $stage['is_first_of_stage'],
				'stage_reward_label' => Nera_SAW_Competition_Config::reward_label( $config, $slot->level_key ),
				'stage_label'        => Nera_SAW_Competition_Config::stage_label(
					$config,
					$slot->level_key,
					$stage['stage_no'],
					$stage['stage_count'],
					$level_def ? (string) $level_def['label'] : ''
				),
			);
		}

		return $out;
	}

	/**
	 * EXPERIMENTAL companion to bulk_slots_payload_experimental_with_answers():
	 * finalize a run from the client's own report of what it did, instead of
	 * server-verified per-answer state. Ticket math is still computed here
	 * from the competition's own config (never from a client-supplied ticket
	 * count directly), which bounds the payout to what the config actually
	 * allows — but which slots counted as "correct" is taken on the client's
	 * word, because under this design the server has no per-answer record of
	 * its own left to check it against.
	 *
	 * @param int   $run_id  Run ID.
	 * @param int   $user_id Acting user.
	 * @param array $answers [{slot_no, chosen_index}], one per slot the client says it answered.
	 * @return array|WP_Error
	 */
	public static function submit_all_experimental( $run_id, $user_id, array $answers ) {
		$run = self::owned_run( $run_id, $user_id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( 'active' !== $run->status ) {
			return self::state( $run );
		}

		global $wpdb;
		$t     = Nera_SAW_Database::table( 'run_slots' );
		$slots = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE run_id = %d ORDER BY slot_no ASC", (int) $run_id ) );
		$by_no = array();
		foreach ( (array) $slots as $slot ) {
			$by_no[ (int) $slot->slot_no ] = $slot;
		}

		$multiplier = self::tier_multiplier( $run );
		$now        = current_time( 'mysql' );

		foreach ( $answers as $a ) {
			$slot_no = isset( $a['slot_no'] ) ? (int) $a['slot_no'] : 0;
			if ( ! isset( $by_no[ $slot_no ] ) || $by_no[ $slot_no ]->answered_at ) {
				continue;
			}
			$slot          = $by_no[ $slot_no ];
			$snapshot      = self::ensure_slot_snapshot( $slot );
			$correct_index = $snapshot ? (int) $snapshot['correct_index'] : -1;
			$chosen_index  = isset( $a['chosen_index'] ) ? (int) $a['chosen_index'] : -1;
			$correct       = ( $chosen_index === $correct_index );
			$awarded       = $correct ? (int) $slot->reward_base * $multiplier : 0;

			self::update_slot(
				$slot->id,
				array(
					'answered_at'   => $now,
					'chosen_index'  => $chosen_index,
					'is_correct'    => $correct ? 1 : 0,
					'outcome'       => $correct ? 'correct' : 'wrong',
					'spins_awarded' => $awarded,
				)
			);
		}

		// Anything the client's report never mentioned is scored zero, same as
		// an ordinary timeout — see abandon()'s own handling of this.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET answered_at = %s, is_correct = 0, outcome = 'timeout', spins_awarded = 0
				 WHERE run_id = %d AND answered_at IS NULL",
				$now,
				(int) $run_id
			)
		);

		self::finalize( (int) $run_id, 'completed' );

		return self::complete_run( (int) $run_id, (int) $user_id );
	}

	/**
	 * stage_of()'s own grouping rule (ladder: a stage is a consecutive run of
	 * slots sharing one level_key; random: every slot is its own stage), computed
	 * for every slot in one pass over an already-fetched slots list instead of
	 * one query per slot. See bulk_slots_payload(), its only caller.
	 *
	 * @param object[] $slots  Run slot rows, in slot_no order (must include slot_no + level_key).
	 * @param array    $config Decoded config snapshot (reads quiz_method).
	 * @return array<int, array{stage_no:int, stage_count:int, is_first_of_stage:bool}> Keyed by slot_no.
	 */
	private static function stage_map_for_slots( array $slots, array $config ) {
		$is_ladder = isset( $config['quiz_method'] ) && Nera_SAW_Mode::QUIZ_LADDER === $config['quiz_method'];
		$map       = array();

		if ( ! $is_ladder ) {
			$count = count( $slots );
			foreach ( $slots as $i => $row ) {
				$map[ (int) $row->slot_no ] = array(
					'stage_no'          => $i + 1,
					'stage_count'       => $count,
					'is_first_of_stage' => true,
				);
			}
			return $map;
		}

		$stage_no   = 0;
		$prev_level = null;
		foreach ( $slots as $row ) {
			$first = false;
			if ( $row->level_key !== $prev_level ) {
				$stage_no++;
				$prev_level = $row->level_key;
				$first      = true;
			}
			$map[ (int) $row->slot_no ] = array(
				'stage_no'          => $stage_no,
				'stage_count'       => null, // Filled in below, once the final count is known.
				'is_first_of_stage' => $first,
			);
		}
		foreach ( $map as $slot_no => $info ) {
			$map[ $slot_no ]['stage_count'] = $stage_no;
		}

		return $map;
	}

	/**
	 * Where a slot sits in the run's stages.
	 *
	 * Ladder mode: a stage is a consecutive run of slots sharing one level_key —
	 * draw_for_run() already leaves the ladder ascending and grouped, so "new
	 * level_key" is exactly "new stage". Random mode: every slot is its own stage,
	 * by definition (Quiz Method distinguishes the two — see CONTEXT.md).
	 *
	 * @param int   $run_id  Run ID.
	 * @param array $config  Decoded config_snapshot (already carries the frozen
	 *                       quiz_method — see Nera_SAW_Run::start()).
	 * @param int   $slot_no The slot being asked about.
	 * @return array{stage_no:int, stage_count:int, is_first_of_stage:bool}
	 */
	private static function stage_of( $run_id, array $config, $slot_no ) {
		global $wpdb;
		$t    = Nera_SAW_Database::table( 'run_slots' );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT slot_no, level_key FROM {$t} WHERE run_id = %d ORDER BY slot_no ASC", (int) $run_id )
		);

		$is_ladder = isset( $config['quiz_method'] ) && Nera_SAW_Mode::QUIZ_LADDER === $config['quiz_method'];

		if ( ! $is_ladder ) {
			foreach ( $rows as $i => $row ) {
				if ( (int) $row->slot_no === (int) $slot_no ) {
					return array(
						'stage_no'          => $i + 1,
						'stage_count'       => count( $rows ),
						'is_first_of_stage' => true,
					);
				}
			}
			return array( 'stage_no' => 1, 'stage_count' => max( 1, count( $rows ) ), 'is_first_of_stage' => true );
		}

		$stage_no     = 0;
		$prev_level   = null;
		$found        = null;
		$first_in_run = array(); // stage_no => the slot_no that opens it.
		foreach ( $rows as $row ) {
			if ( $row->level_key !== $prev_level ) {
				$stage_no++;
				$prev_level                  = $row->level_key;
				$first_in_run[ $stage_no ]   = (int) $row->slot_no;
			}
			if ( (int) $row->slot_no === (int) $slot_no ) {
				$found = $stage_no;
			}
		}

		if ( null === $found ) {
			// Slot not found (shouldn't happen — caller already loaded it). Answer
			// safely rather than divide-by-zero the caller's percentage math.
			return array( 'stage_no' => 1, 'stage_count' => max( 1, $stage_no ), 'is_first_of_stage' => true );
		}

		return array(
			'stage_no'          => $found,
			'stage_count'       => $stage_no,
			'is_first_of_stage' => isset( $first_in_run[ $found ] ) && $first_in_run[ $found ] === (int) $slot_no,
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

		// An answer arriving on a held run is a stale tab or a client that lost its
		// connection and is back: it must Resume first, or it would answer against a
		// clock that has been frozen since the last heartbeat.
		$held = self::guard_held( $run );
		if ( $held ) {
			return $held;
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
			// The correct option, for the Answer reveal (ADR 0017). serve_slot()
			// never sends this; it is disclosed only here, after the slot has been
			// answered and locked, so it cannot be read before committing to a pick.
			// -1 when the snapshot is unusable — the client then reveals nothing.
			'correct_index' => (int) $correct_index,
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
		global $wpdb;
		$t = Nera_SAW_Database::table( 'runs' );

		/*
		 * Claim this run before touching anything else: lock its row for the
		 * whole critical section (score + pool confirm + write) inside one
		 * transaction, so a double-submit from the client or an overlapping
		 * cron sweep (finalize_stale()) cannot both pass the status check and
		 * both call Nera_SAW_Spin_Pool::confirm_clamped() for the same run.
		 * A plain read-then-write here (as this used to be) left exactly that
		 * window open.
		 */
		$wpdb->query( 'START TRANSACTION' );
		$settled = false;
		try {
			$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d FOR UPDATE", (int) $run_id ) );

			if ( ! $run || 'active' !== $run->status ) {
				$wpdb->query( 'ROLLBACK' );
				$settled = true;
				if ( $run ) {
					Nera_SAW_Log::add(
						'run_finalize_race_avoided',
						array(
							'message'        => sprintf( 'finalize_scoring() found run #%d already %s — another call already claimed it.', (int) $run_id, (string) $run->status ),
							'run_id'         => (int) $run_id,
							'competition_id' => (int) $run->competition_id,
							'tier_key'       => (string) $run->tier_key,
							'user_id'        => (int) $run->user_id,
							'order_id'       => (int) $run->order_id,
						)
					);
				}
				return false;
			}

			$correct = (int) self::count_correct( $run_id );
			$spins   = (int) self::spins_so_far( $run_id );

			/*
			 * Confirm against the pool BEFORE writing spins_confirmed, and write back
			 * whatever the pool actually backed — never the raw score regardless. In
			 * the normal case (this run's worst case was reserved at grant time, and
			 * nothing has since sold past what Nera_SAW_Cart_Entry's checkout gate
			 * allows) confirmed === $spins and nothing here changes behaviour. See
			 * Nera_SAW_Spin_Pool::confirm_clamped() for why a run's recorded ticket
			 * count must never be allowed to exceed what was actually reserved for it.
			 */
			$reserved_slice  = (int) $run->max_possible_spins;
			$confirmed_spins = $spins;
			if ( $reserved_slice > 0 || $spins > 0 ) {
				$confirmed_spins = Nera_SAW_Spin_Pool::confirm_clamped( (int) $run->competition_id, $reserved_slice, $spins );
			}

			self::update_run(
				$run_id,
				array(
					'status'          => 'finalized',
					'end_reason'      => (string) $end_reason,
					'correct_count'   => $correct,
					'spins_confirmed' => $confirmed_spins,
					'finalized_at'    => current_time( 'mysql' ),
				)
			);

			$wpdb->query( 'COMMIT' );
			$settled = true;
		} finally {
			if ( ! $settled ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}

		if ( $confirmed_spins < $spins ) {
			Nera_SAW_Log::error(
				'run_complete',
				sprintf(
					'Ticket pool short at finalize: run earned %1$d but the pool could only confirm %2$d for competition #%3$d. Player was awarded %2$d, not %1$d.',
					$spins,
					$confirmed_spins,
					(int) $run->competition_id
				),
				array(
					'run_id'         => (int) $run_id,
					'competition_id' => (int) $run->competition_id,
					'tier_key'       => (string) $run->tier_key,
					'user_id'        => (int) $run->user_id,
					'order_id'       => (int) $run->order_id,
					'context'        => array(
						'earned'    => $spins,
						'confirmed' => $confirmed_spins,
						'reserved'  => $reserved_slice,
					),
				)
			);
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
			'slot_results'          => self::slot_results( (int) $run_id ),
			// The results screen's "Draw: <date>" line. Reused from the standalone
			// result-overlay clone rather than re-implemented — one place reads the
			// LFW product's end date, in both modes.
			'draw_date'             => class_exists( 'Nera_SAW_Standalone_Result_Screen' )
				? Nera_SAW_Standalone_Result_Screen::draw_date( wc_get_product( (int) $run->competition_id ) )
				: '',
		) + self::purchase_position( $run );
	}

	/**
	 * Per-question outcome for a finished run, in slot order — the results
	 * screen's row of chips ("+2", "0", "–"). Safe to disclose in full: the run is
	 * over, so every one of these is history, not an answer key still in play.
	 *
	 * @param int $run_id Run ID.
	 * @return array<int, array{slot_no:int, outcome:string, spins_awarded:int}>
	 */
	private static function slot_results( $run_id ) {
		global $wpdb;
		$t    = Nera_SAW_Database::table( 'run_slots' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT slot_no, outcome, spins_awarded FROM {$t} WHERE run_id = %d ORDER BY slot_no ASC",
				(int) $run_id
			)
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'slot_no'       => (int) $row->slot_no,
				// A slot the run never reached (an early finalize) has no outcome
				// stamped at all; that is a timeout in every way that matters here —
				// no answer was ever counted for it.
				'outcome'       => $row->outcome ? (string) $row->outcome : 'timeout',
				'spins_awarded' => (int) $row->spins_awarded,
			);
		}
		return $out;
	}

	/**
	 * Where this run sits in the purchase that paid for it — "run 2 of 3".
	 *
	 * Counted within one order line rather than across the player's whole balance,
	 * because that is the promise the customer was sold: they bought three runs on
	 * this competition at this tier, and this is the second of those three. A player
	 * who then buys three more starts again at one, which is what they expect.
	 *
	 * Returns zeroes rather than omitting the keys when the purchase cannot be
	 * identified — a seeded run, or one whose order has since been deleted — so a
	 * template can test `total > 1` without first testing that the keys exist.
	 *
	 * @param object $run Run row.
	 * @return array { purchase_run_no: int, purchase_run_total: int }
	 */
	private static function purchase_position( $run ) {
		$none = array(
			'purchase_run_no'    => 0,
			'purchase_run_total' => 0,
		);

		$order_id = (int) $run->order_id;
		if ( $order_id < 1 ) {
			return $none;
		}

		global $wpdb;
		$grants = Nera_SAW_Database::table( 'run_grants' );
		$runs   = Nera_SAW_Database::table( 'runs' );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( qty ), 0 ) FROM {$grants}
				 WHERE user_id = %d AND competition_id = %d AND tier_key = %s AND order_id = %d",
				(int) $run->user_id,
				(int) $run->competition_id,
				(string) $run->tier_key,
				$order_id
			)
		);
		if ( $total < 1 ) {
			return $none;
		}

		// Position by id: runs are created in the order they are played, so the
		// count of same-purchase runs up to and including this one is its number.
		$position = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$runs}
				 WHERE user_id = %d AND competition_id = %d AND tier_key = %s AND order_id = %d AND id <= %d",
				(int) $run->user_id,
				(int) $run->competition_id,
				(string) $run->tier_key,
				$order_id,
				(int) $run->id
			)
		);

		return array(
			'purchase_run_no'    => max( 1, $position ),
			'purchase_run_total' => max( $total, $position ),
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
	 * How many of an order line's runs are actually finished vs. still live —
	 * the distinction `Nera_SAW_Run_Grants::order_line_stats()` never made
	 * (client finding #41): that method's own "completed" was really
	 * `grants.consumed`, a count of runs drawn from the balance at the
	 * moment they START, not finished — so a run still live at Stage 3
	 * already read "Runs completed". Finished is this row's own
	 * `status = 'finalized'` (set once, only by `finalize_scoring()`); live
	 * is `status = 'active'` — the same two states `abandon()`/normal
	 * completion ever leave a started run in, short of the admin-only
	 * `restore()` path, which refunds the grant and voids the run rather
	 * than leaving it in either bucket. The same `order_id` +
	 * `competition_id` + `tier_key` scoping as `ticket_numbers_for_order_
	 * tier()` above, for the same reason: `runs` has no `order_item_id` of
	 * its own to join on more precisely.
	 *
	 * @param int    $order_id       Order ID.
	 * @param int    $competition_id Competition product ID.
	 * @param string $tier_key       Tier key.
	 * @return array{in_progress: int, completed: int}
	 */
	public static function run_counts_for_order_tier( $order_id, $competition_id, $tier_key ) {
		global $wpdb;
		$t      = Nera_SAW_Database::table( 'runs' );
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS in_progress,
					SUM(CASE WHEN status = 'finalized' THEN 1 ELSE 0 END) AS completed
				FROM {$t} WHERE order_id = %d AND competition_id = %d AND tier_key = %s",
				(int) $order_id,
				(int) $competition_id,
				(string) $tier_key
			)
		);

		return array(
			'in_progress' => $counts ? (int) $counts->in_progress : 0,
			'completed'   => $counts ? (int) $counts->completed : 0,
		);
	}


	/**
	 * Finalize runs whose last slot deadline has passed (abandoned). Cron-driven.
	 */
	/**
	 * @param bool $force_resume Settle under the resume policy whatever the setting
	 *                           says. Used when the policy is being switched: the
	 *                           backlog belongs to the policy that was in force when
	 *                           those runs were played, so it is settled under that
	 *                           one rather than retroactively re-judged.
	 */
	public static function finalize_stale( $force_resume = false ) {
		global $wpdb;
		$runs  = Nera_SAW_Database::table( 'runs' );
		$slots = Nera_SAW_Database::table( 'run_slots' );
		$now   = current_time( 'mysql' );

		/*
		 * Active runs whose own wall clock has passed.
		 *
		 * The previous form of this query asked for runs with no unanswered slot
		 * still within its deadline, and counted `deadline_at IS NULL` — a slot
		 * that was never served — as "within deadline". A player who stopped at
		 * question 5 left slots 6-10 unserved, so the run never qualified and the
		 * only runs it could ever close were those where every slot was served and
		 * none answered. See ADR 0020.
		 *
		 * `expires_at` is NULL only on runs that predate that column and have not
		 * been back-filled; those are skipped here rather than guessed at, and the
		 * migration screen exists to give them a value.
		 *
		 * `errored` runs are excluded. An errored run is still `status = active` by
		 * design — that is how the Report finds it and offers Restore — so without
		 * this clause the sweep would close it, mint its tickets, and remove the
		 * marker before any administrator saw it.
		 */
		$closes = ! $force_resume && ! Nera_SAW_Mode::allows_resume();

		/*
		 * Runs that report a heartbeat are judged by it, not by `expires_at`
		 * (ADR 0030). Under "let the player continue" they are either alive, held
		 * for Resume, or held past the window - and only the last is the sweep's
		 * business: it goes to an administrator, nothing is minted. A run with no
		 * heartbeat at all predates the column and keeps the old rule below.
		 */
		if ( ! $closes && ! $force_resume ) {
			self::expire_interrupted();
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$runs}
				 WHERE status = 'active'
				 AND end_reason <> 'errored'
				 AND expires_at IS NOT NULL
				 AND expires_at < %s
				 " . ( ( $force_resume || $closes ) ? '' : 'AND last_seen_at IS NULL' ) . "
				 LIMIT 200",
				gmdate( 'Y-m-d H:i:s', self::ts( $now ) - Nera_SAW_Constants::LATENCY_GRACE_SECONDS )
			)
		);

		foreach ( (array) $ids as $rid ) {
			if ( $closes ) {
				// Resume is off: a run that went silent is an interruption, and
				// interruptions are an administrator's call, not an automatic
				// score of zero. Nothing is minted.
				$row = self::get_run( (int) $rid );
				if ( $row ) {
					self::close_interrupted( $row );
				}
				continue;
			}

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

		$out = array(
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

		/*
		 * A look at the NEXT slot's stage, without serving it. This is what lets the
		 * client show the stage-break screen BEFORE the next question's clock starts
		 * rather than after — serve_slot() stamps served_at/deadline_at on first
		 * fetch, so fetching early to decide whether to show a break would burn the
		 * player's answering time behind that screen. Reading level_key off the
		 * already-drawn run_slots row costs nothing and starts nothing.
		 */
		if ( $next ) {
			$config = json_decode( $run->config_snapshot, true );
			if ( is_array( $config ) ) {
				$stage      = self::stage_of( (int) $run->id, $config, (int) $next->slot_no );
				$level_def  = Nera_SAW_Constants::level( $next->level_key );

				$out['next_stage'] = array(
					'is_first_of_stage' => (bool) $stage['is_first_of_stage'],
					'stage_no'          => (int) $stage['stage_no'],
					'stage_count'       => (int) $stage['stage_count'],
					'quiz_method'       => isset( $config['quiz_method'] ) ? (string) $config['quiz_method'] : Nera_SAW_Mode::QUIZ_RANDOM,
					'level_label'       => $level_def ? (string) $level_def['label'] : '',
					// The break screen's own colour, not the slot's — it is shown
					// BEFORE that slot is fetched (see the note above this block), so
					// slot.level_text_color on the client still holds the previous
					// stage's colour at that moment.
					'level_text_color'  => Nera_SAW_Constants::level_text_color( $next->level_key ),
					'reward_label'      => Nera_SAW_Competition_Config::reward_label( $config, $next->level_key ),
					'stage_label'       => Nera_SAW_Competition_Config::stage_label(
						$config,
						$next->level_key,
						$stage['stage_no'],
						$stage['stage_count'],
						$level_def ? (string) $level_def['label'] : ''
					),
				);
			}
		}

		return $out;
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
		return (bool) $wpdb->insert(
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

	/* =====================================================================
	 * The run clock (ADR 0020)
	 *
	 * Three pieces, and each exists because the other two cannot do its job:
	 *
	 *  - `runs.expires_at` is the wall clock for the whole run. It is the only
	 *    thing the sweep can key on, because a run that was abandoned early has
	 *    no served slots left to prove it is over.
	 *  - Each slot's `deadline_at` is chained forward from the moment the
	 *    previous slot ended, so the clock runs while nobody is looking.
	 *  - catch_up() settles whatever lapsed before anything is served or scored,
	 *    which is what makes a reconnect land on the live question.
	 * ================================================================== */

	/**
	 * Per-question timer for a run's config, clamped.
	 *
	 * @param array $config Config snapshot.
	 * @return int Seconds.
	 */
	private static function timer_for( array $config ) {
		return Nera_SAW_Constants::clamp_timer(
			isset( $config['timer_seconds'] ) ? $config['timer_seconds'] : Nera_SAW_Constants::TIMER_MAX_SECONDS
		);
	}

	/**
	 * How long a run of this shape may take, end to end.
	 *
	 * One timer per question plus a single grace allowance. Deliberately an upper
	 * bound rather than a tight fit: it decides when a silent run is swept, and
	 * sweeping a run a player is still in would take tickets off them.
	 *
	 * @param array $config     Config snapshot.
	 * @param int   $slot_count Number of slots.
	 * @return int Seconds.
	 */
	public static function run_window_seconds( array $config, $slot_count ) {
		$slot_count = max( 1, (int) $slot_count );

		// Whole seconds. The latency grace is a float, and PHP 8.1 deprecates
		// handing a fractional float to anything expecting an int — which every
		// caller here does, via gmdate(). Round up so the grace is never lost.
		return (int) ceil( ( $slot_count * self::timer_for( $config ) ) + Nera_SAW_Constants::LATENCY_GRACE_SECONDS );
	}

	/**
	 * Stamp the run's wall clock.
	 *
	 * Only the run-level clock. A question's own 10 seconds start when it is served
	 * (serve_slot()), not here and not when the previous answer lands — otherwise
	 * the language screen, the stage-break screen and the answer reveal would all
	 * be paid for out of the question's timer (ADR 0030).
	 *
	 * @param int   $run_id     Run ID.
	 * @param int   $slot_count Number of slots drawn.
	 * @param array $config     Config snapshot.
	 */
	private static function start_clock( $run_id, $slot_count, array $config ) {
		$now = current_time( 'mysql' );

		$fields = array(
			'expires_at'   => gmdate( 'Y-m-d H:i:s', self::ts( $now ) + self::run_window_seconds( $config, $slot_count ) ),
			'last_seen_at' => $now,
		);

		// insert_run() already stamps started_at. Only fill it if it is somehow
		// absent — a redraw restarts the *clock*, but the run still began when it
		// began, and the Report reads started_at as "played at".
		$existing = self::get_run( (int) $run_id );
		if ( $existing && empty( $existing->started_at ) ) {
			$fields['started_at'] = $now;
		}

		self::update_run( (int) $run_id, $fields );
	}

	/* ====================================================================
	 * Interrupted runs — heartbeat, hold, resume (ADR 0030)
	 * ================================================================== */

	/**
	 * Record that the player's client is still there.
	 *
	 * Called every few seconds by the quiz while a run is on screen, and once more
	 * when the page is going away. That last ping is what fixes the moment of
	 * interruption, which is what lets Resume give back exactly the time that was
	 * left.
	 *
	 * A ping on a run already held does NOT quietly revive it: the client is told
	 * so and must Resume, which is the one path that moves the clock.
	 *
	 * @param int $run_id  Run ID.
	 * @param int $user_id Acting user.
	 * @return array|WP_Error Keys: status, held, errored.
	 */
	public static function heartbeat( $run_id, $user_id ) {
		$run = self::owned_run( $run_id, $user_id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( 'active' !== $run->status ) {
			return array( 'status' => (string) $run->status, 'held' => false, 'errored' => false );
		}

		if ( 'errored' === (string) $run->end_reason ) {
			return array( 'status' => 'active', 'held' => false, 'errored' => true );
		}

		if ( Nera_SAW_Mode::allows_resume() ) {
			if ( self::interruption_expired( $run ) ) {
				self::mark_interruption_expired( $run );
				return array( 'status' => 'active', 'held' => false, 'errored' => true );
			}
			if ( self::pending_info( $run ) ) {
				return array( 'status' => 'active', 'held' => true, 'errored' => false );
			}
		}

		self::update_run( (int) $run_id, array( 'last_seen_at' => current_time( 'mysql' ) ) );

		return array( 'status' => 'active', 'held' => false, 'errored' => false );
	}

	/**
	 * Is this run held for Resume right now?
	 *
	 * Derived, never stored: a heartbeat older than the stale threshold, inside the
	 * configured window. Nothing has to run for a run to become held, so there is
	 * no sweep to fall behind — the same reason ADR 0020 gave a run its own clock.
	 *
	 * @param object $run Run row.
	 * @return array|null Keys: since (unix time of the last heartbeat), until. Null when not held.
	 */
	public static function pending_info( $run ) {
		if ( ! $run || 'active' !== $run->status || 'errored' === (string) $run->end_reason || empty( $run->last_seen_at ) ) {
			return null;
		}
		if ( ! Nera_SAW_Mode::allows_resume() ) {
			return null;
		}

		$seen = self::ts( $run->last_seen_at );
		$now  = self::ts( current_time( 'mysql' ) );

		if ( ( $now - $seen ) <= Nera_SAW_Constants::HEARTBEAT_STALE_SECONDS ) {
			return null;
		}

		$until = $seen + Nera_SAW_Mode::resume_window_seconds();
		if ( $now > $until ) {
			return null;
		}

		return array(
			'since' => $seen,
			'until' => $until,
		);
	}

	/**
	 * Was this run held for Resume and then left past its window?
	 *
	 * @param object $run Run row.
	 * @return bool
	 */
	public static function interruption_expired( $run ) {
		if ( ! $run || 'active' !== $run->status || 'errored' === (string) $run->end_reason || empty( $run->last_seen_at ) ) {
			return false;
		}
		if ( ! Nera_SAW_Mode::allows_resume() ) {
			return false;
		}

		return self::ts( current_time( 'mysql' ) ) > ( self::ts( $run->last_seen_at ) + Nera_SAW_Mode::resume_window_seconds() );
	}

	/**
	 * Close a run that was held and never resumed, for an administrator.
	 *
	 * Same end state as the "close the run" policy: `errored`, nothing minted, and
	 * Restore refunds the run.
	 *
	 * @param object $run Run row.
	 * @return object The run, refreshed.
	 */
	private static function mark_interruption_expired( $run ) {
		$run_id = (int) $run->id;

		self::update_run( $run_id, array( 'end_reason' => 'errored' ) );

		if ( class_exists( 'Nera_SAW_Log' ) ) {
			Nera_SAW_Log::error(
				'run_interrupted',
				sprintf(
					/* translators: %d: minutes the run was held */
					__( 'The run was interrupted and not resumed within %d minutes, so it was closed for review.', 'nera-strikeawin' ),
					(int) round( Nera_SAW_Mode::resume_window_seconds() / MINUTE_IN_SECONDS )
				),
				array(
					'run_id'         => $run_id,
					'competition_id' => (int) $run->competition_id,
					'tier_key'       => (string) $run->tier_key,
					'user_id'        => (int) $run->user_id,
				)
			);
		}

		$fresh = self::get_run( $run_id );
		return $fresh ? $fresh : $run;
	}

	/**
	 * Close every held run whose window has run out.
	 *
	 * @param int $user_id Limit to one player (0 = everyone), for the lazy check on
	 *                     a page load.
	 * @return int Runs closed.
	 */
	public static function expire_interrupted( $user_id = 0 ) {
		if ( ! Nera_SAW_Mode::allows_resume() ) {
			return 0;
		}

		global $wpdb;
		$t   = Nera_SAW_Database::table( 'runs' );
		$cut = gmdate( 'Y-m-d H:i:s', self::ts( current_time( 'mysql' ) ) - Nera_SAW_Mode::resume_window_seconds() );

		$sql = "SELECT id FROM {$t}
			WHERE status = 'active' AND end_reason <> 'errored'
			AND last_seen_at IS NOT NULL AND last_seen_at < %s";
		$arg = array( $cut );
		if ( (int) $user_id > 0 ) {
			$sql  .= ' AND user_id = %d';
			$arg[] = (int) $user_id;
		}
		$sql .= ' LIMIT 200';

		$closed = 0;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( $sql, $arg ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$run = self::get_run( (int) $id );
			if ( $run && self::interruption_expired( $run ) ) {
				self::mark_interruption_expired( $run );
				$closed++;
			}
		}

		return $closed;
	}

	/**
	 * The player's runs currently held for Resume, newest first.
	 *
	 * Also closes any that have outlived the window, so the popup never offers a
	 * run that can no longer be resumed.
	 *
	 * @param int $user_id User ID.
	 * @return object[] Run rows, each with a `resume_until` unix time added.
	 */
	public static function pending_for_user( $user_id ) {
		if ( (int) $user_id < 1 || ! Nera_SAW_Mode::allows_resume() ) {
			return array();
		}

		self::expire_interrupted( (int) $user_id );

		global $wpdb;
		$t    = Nera_SAW_Database::table( 'runs' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE user_id = %d AND status = 'active' AND end_reason <> 'errored' AND last_seen_at IS NOT NULL ORDER BY id DESC LIMIT 20",
				(int) $user_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$info = self::pending_info( $row );
			if ( $info ) {
				$row->resume_until = $info['until'];
				$out[]             = $row;
			}
		}

		return $out;
	}

	/**
	 * Resume a held run: give the live question back exactly the time it had when
	 * the last heartbeat arrived, and let the run's own wall clock skip the time
	 * spent away.
	 *
	 * The interrupted question keeps its remaining time, not a fresh full timer —
	 * a fresh one would let a player see a question, leave, look the answer up and
	 * come back (ADR 0030).
	 *
	 * @param int $run_id  Run ID.
	 * @param int $user_id Acting user.
	 * @return array|WP_Error Run state.
	 */
	public static function resume( $run_id, $user_id ) {
		$run = self::owned_run( $run_id, $user_id );
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( 'active' !== $run->status ) {
			return self::state( $run );
		}

		if ( 'errored' === (string) $run->end_reason || self::interruption_expired( $run ) ) {
			if ( 'errored' !== (string) $run->end_reason ) {
				self::mark_interruption_expired( $run );
			}
			return new WP_Error(
				'saw_interrupted',
				__( 'This run was closed after it was interrupted. Please contact support and we will restore it.', 'nera-strikeawin' ),
				array( 'status' => 409 )
			);
		}

		if ( self::pending_info( $run ) ) {
			$run = self::thaw( $run );

			if ( class_exists( 'Nera_SAW_Log' ) ) {
				Nera_SAW_Log::add(
					'run_resume',
					array(
						'run_id'         => (int) $run->id,
						'competition_id' => (int) $run->competition_id,
						'tier_key'       => (string) $run->tier_key,
						'user_id'        => (int) $run->user_id,
						'message'        => 'Interrupted run resumed',
					)
				);
			}
		} else {
			self::update_run( (int) $run_id, array( 'last_seen_at' => current_time( 'mysql' ) ) );
		}

		// Included every time, not only for a fresh Start: a client that reached
		// Resume via a full page reload (not just a tab switch) has no in-memory
		// copy of the run's content left to fall back on.
		$fresh_run       = self::get_run( (int) $run_id );
		$state           = self::state( $fresh_run );
		// $state['slots'] = self::bulk_slots_payload( $fresh_run );
		$fresh_config               = json_decode( (string) $fresh_run->config_snapshot, true );
		$state['timer_seconds']     = self::timer_for( is_array( $fresh_config ) ? $fresh_config : array() );
		$state['obfuscation_seed'] = wp_rand( 1, 254 );
		$state['slots']            = self::bulk_slots_payload_experimental_with_answers( $fresh_run, $state['obfuscation_seed'] );
		return $state;
	}

	/**
	 * Unfreeze a held run.
	 *
	 * @param object $run Run row (must be held).
	 * @return object The run, refreshed.
	 */
	private static function thaw( $run ) {
		global $wpdb;
		$slots  = Nera_SAW_Database::table( 'run_slots' );
		$now    = current_time( 'mysql' );
		$now_ts = self::ts( $now );
		$seen   = self::ts( $run->last_seen_at );

		// The question that was on screen: served, unanswered. Its clock stopped at
		// the last heartbeat, so it gets back what it had left then.
		$live = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$slots} WHERE run_id = %d AND answered_at IS NULL AND served_at IS NOT NULL AND deadline_at IS NOT NULL ORDER BY slot_no ASC LIMIT 1",
				(int) $run->id
			)
		);
		if ( $live ) {
			$remaining = max( 0, self::ts( $live->deadline_at ) - $seen );
			self::update_slot( (int) $live->id, array( 'deadline_at' => gmdate( 'Y-m-d H:i:s', $now_ts + $remaining ) ) );
		}

		$fields = array( 'last_seen_at' => $now );
		if ( $run->expires_at ) {
			$fields['expires_at'] = gmdate( 'Y-m-d H:i:s', self::ts( $run->expires_at ) + max( 0, $now_ts - $seen ) );
		}
		self::update_run( (int) $run->id, $fields );

		$fresh = self::get_run( (int) $run->id );
		return $fresh ? $fresh : $run;
	}

	/**
	 * Refuse to serve or score a run that is held or closed.
	 *
	 * @param object $run Run row.
	 * @return WP_Error|null
	 */
	private static function guard_held( $run ) {
		if ( 'errored' === (string) $run->end_reason ) {
			return new WP_Error(
				'saw_interrupted',
				__( 'This run was closed after it was interrupted. Please contact support and we will restore it.', 'nera-strikeawin' ),
				array( 'status' => 409 )
			);
		}
		if ( self::pending_info( $run ) ) {
			return new WP_Error(
				'saw_pending',
				__( 'Your run was interrupted. Resume to carry on.', 'nera-strikeawin' ),
				array( 'status' => 409 )
			);
		}
		return null;
	}

	/**
	 * Push the run's wall clock out when a question is served.
	 *
	 * Deadlines are stamped at serve time, so the run-level `expires_at` can no
	 * longer be fixed at the start: the answer reveal and the stage-break screen sit
	 * between questions and are not on any question's timer. It rolls forward from
	 * each serve to that question's deadline, plus a timer for every question still
	 * to come, plus the between-question slack.
	 *
	 * @param object $run    Run row.
	 * @param object $slot   The slot just served (deadline_at set).
	 * @param array  $config Config snapshot.
	 */
	private static function roll_expiry( $run, $slot, array $config ) {
		global $wpdb;
		$t     = Nera_SAW_Database::table( 'run_slots' );
		$after = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE run_id = %d AND slot_no > %d", (int) $run->id, (int) $slot->slot_no )
		);

		$expires = self::ts( $slot->deadline_at )
			+ ( $after * self::timer_for( $config ) )
			+ (int) ceil( Nera_SAW_Constants::LATENCY_GRACE_SECONDS )
			+ ( ( $after + 1 ) * Nera_SAW_Constants::BETWEEN_QUESTIONS_SLACK_SECONDS );

		self::update_run(
			(int) $run->id,
			array(
				'expires_at'   => gmdate( 'Y-m-d H:i:s', $expires ),
				'last_seen_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Settle every slot whose window closed while nobody was watching.
	 *
	 * Walks the unanswered slots in order, scoring each lapsed one as a timeout
	 * Stops at
	 * the first slot still inside its window: that is the live question.
	 *
	 * @param object $run Run row.
	 * @return object The run, refreshed (its status may have changed).
	 */
	private static function catch_up( $run ) {
		if ( ! $run || 'active' !== $run->status ) {
			return $run;
		}

		global $wpdb;
		$t      = Nera_SAW_Database::table( 'run_slots' );
		$now_ts = self::ts( current_time( 'mysql' ) );
		$grace  = Nera_SAW_Constants::LATENCY_GRACE_SECONDS;

		/*
		 * A run held for Resume is frozen: nothing lapses while the player is
		 * away, because the clock stopped at the last heartbeat (ADR 0030). One held
		 * past its window is closed for an administrator instead.
		 */
		if ( self::interruption_expired( $run ) ) {
			return self::mark_interruption_expired( $run );
		}
		if ( self::pending_info( $run ) ) {
			return $run;
		}

		$pending = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE run_id = %d AND answered_at IS NULL ORDER BY slot_no ASC", (int) $run->id )
		);
		if ( ! $pending ) {
			return $run;
		}

		$run_over = $run->expires_at && ( self::ts( $run->expires_at ) + $grace ) < $now_ts;
		$changed  = false;

		/*
		 * Under the "close the run" policy an interruption is not something the
		 * player plays through — it ends the run and an administrator decides what
		 * to do with it. Detected here because this is the one place that knows a
		 * question went by with nobody answering it.
		 *
		 * A deliberate leave does not come through here: abandon() handles the
		 * confirm dialog, and confirming it is a choice under either policy.
		 */
		if ( ! Nera_SAW_Mode::allows_resume() ) {
			$first   = $pending[0];
			$missed  = $first->deadline_at && ( self::ts( $first->deadline_at ) + $grace ) < $now_ts;
			if ( $missed || $run_over ) {
				return self::close_interrupted( $run );
			}
			return $run; // Nothing lapsed — a reload inside the current question.
		}

		foreach ( $pending as $slot ) {
			$lapsed = $slot->deadline_at && ( self::ts( $slot->deadline_at ) + $grace ) < $now_ts;

			if ( ! $run_over && ! $lapsed ) {
				break; // The live question. Everything after it is still to come.
			}

			$ended_ts = $slot->deadline_at ? self::ts( $slot->deadline_at ) : $now_ts;
			self::update_slot(
				$slot->id,
				array(
					'answered_at'   => gmdate( 'Y-m-d H:i:s', min( $ended_ts, $now_ts ) ),
					'is_correct'    => 0,
					'outcome'       => 'timeout',
					'spins_awarded' => 0,
				)
			);
			$changed = true;
		}

		if ( ! $changed ) {
			return $run;
		}

		// Everything settled? Then the run is finished, and how it finished is the
		// difference the Report cares about (ADR 0010): a run whose wall clock ran
		// out is `expired`; one whose last question simply lapsed is `abandoned`.
		if ( ! self::next_unanswered_slot( (int) $run->id ) ) {
			self::finalize( (int) $run->id, $run_over ? 'expired' : 'abandoned' );
		}

		$fresh = self::get_run( (int) $run->id );
		return $fresh ? $fresh : $run;
	}

	/**
	 * Settle the runs left over from the previous resume policy.
	 *
	 * Called when an administrator switches to "close the run". Everything already
	 * stuck was played while the old policy was in force, so it is finished under
	 * the old policy — the tickets those players earned are issued and the runs are
	 * closed properly — rather than being reclassified as somebody's problem after
	 * the fact.
	 *
	 * Capped, because minting is heavy: it creates every earned ticket, confirms it
	 * and emails the player (ADR 0011). A few thousand runs inside one settings save
	 * would time out, so the cap stops and the caller says what is left.
	 *
	 * @param int $limit Maximum runs to back-fill in this pass.
	 * @return array { settled: int, remaining: int }
	 */
	public static function settle_legacy_runs( $limit = 500 ) {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'runs' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$t}
				 WHERE status = 'active' AND end_reason <> 'errored' AND expires_at IS NULL
				 ORDER BY id ASC LIMIT %d",
				max( 1, (int) $limit )
			)
		);

		$settled = 0;
		foreach ( (array) $ids as $id ) {
			if ( self::backfill_expires_at( (int) $id ) ) {
				$settled++;
			}
		}

		// Sweep them now, under the policy they were played under. The sweep is
		// itself capped per pass, so loop until it stops finding work.
		$guard = 0;
		do {
			// current_time( 'mysql' ), not UTC_TIMESTAMP(): expires_at is written in
			// the site's own time (start_clock()), so comparing it against MySQL's
			// UTC would be off by the site's offset.
			$before = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$t} WHERE status = 'active' AND end_reason <> 'errored' AND expires_at IS NOT NULL AND expires_at < %s",
					current_time( 'mysql' )
				)
			);
			if ( $before < 1 ) {
				break;
			}
			self::finalize_stale( true );
			$guard++;
		} while ( $guard < 20 );

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'active' AND end_reason <> 'errored' AND expires_at IS NULL" );

		return array(
			'settled'   => $settled,
			'remaining' => $remaining,
		);
	}

	/**
	 * End a run that was interrupted, and leave it for an administrator.
	 *
	 * Marked `errored` rather than `expired` on purpose. `errored` is the existing
	 * "stuck, a human should look" state (ADR 0013) and it deliberately does NOT
	 * finalize — so nothing is minted, the Report surfaces it, and Restore refunds
	 * the run to the player. Under this policy that is the fair outcome: the player
	 * did not get the run they paid for, so they get the run back rather than a
	 * partial score.
	 *
	 * @param object $run Run row.
	 * @return object The run, refreshed.
	 */
	private static function close_interrupted( $run ) {
		$run_id = (int) $run->id;

		if ( 'active' !== $run->status || 'errored' === (string) $run->end_reason ) {
			return $run;
		}

		self::update_run( $run_id, array( 'end_reason' => 'errored' ) );

		if ( class_exists( 'Nera_SAW_Log' ) ) {
			Nera_SAW_Log::error(
				'run_interrupted',
				__( 'The run was interrupted — the connection was lost or the browser was closed mid-quiz. Resume is switched off, so the run was closed for review.', 'nera-strikeawin' ),
				array(
					'run_id'  => $run_id,
					'user_id' => (int) $run->user_id,
				)
			);
		}

		$fresh = self::get_run( $run_id );
		return $fresh ? $fresh : $run;
	}

	/**
	 * Give a pre-`expires_at` run a wall clock, derived from what it holds.
	 *
	 * Used by the migration screen, never automatically: these runs were left
	 * `active` by a sweep that has never run, so back-filling them is what makes
	 * the first sweep close them — and closing them mints the tickets their
	 * players earned. That is correct, and it is not something to do to a live
	 * site without looking first.
	 *
	 * @param object $run Run row (expires_at NULL).
	 * @return string|null The computed datetime, or null if it cannot be derived.
	 */
	public static function derive_expires_at( $run ) {
		if ( ! $run ) {
			return null;
		}

		$config = json_decode( $run->config_snapshot, true );
		$config = is_array( $config ) ? $config : array();
		$count  = (int) self::count_slots( (int) $run->id );
		if ( $count < 1 ) {
			$count = 1;
		}

		// started_at is the honest origin. Runs that never started fall back to
		// created_at, which is never later, so the window can only be generous.
		$origin = $run->started_at ? $run->started_at : $run->created_at;
		if ( ! $origin ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', self::ts( $origin ) + self::run_window_seconds( $config, $count ) );
	}

	/**
	 * Persist a derived wall clock onto one run.
	 *
	 * @param int $run_id Run ID.
	 * @return bool Whether a value was written.
	 */
	public static function backfill_expires_at( $run_id ) {
		$run = self::get_run( (int) $run_id );
		if ( ! $run || $run->expires_at ) {
			return false;
		}
		$value = self::derive_expires_at( $run );
		if ( ! $value ) {
			return false;
		}
		self::update_run( (int) $run_id, array( 'expires_at' => $value ) );
		return true;
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
