<?php
/**
 * Demo seeder + safe, marker-based cleanup.
 *
 * Seeds a graded demo question bank, one or more demo Strike A Win lottery
 * competitions, and (optionally) demo quiz submissions — real paid WooCommerce
 * orders owned by created demo customers, with directly-written finalized runs
 * and REAL minted LFW tickets. Every artifact is stamped with a demo marker so
 * the wipe removes ONLY seeded data and can never touch real records.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Seeder
 */
class Nera_SAW_Seeder {

	const BATCH_ID        = 'saw_demo';
	const PRODUCT_MARKER  = '_saw_demo';
	const ORDER_MARKER    = '_saw_demo_order';
	const USER_MARKER     = 'saw_demo';
	const TICKET_MARKER   = '_saw_demo_ticket';
	const OPTION_PRODUCTS = 'nera_saw_demo_products';
	const OPTION_ORDERS   = 'nera_saw_demo_orders';
	const OPTION_USERS    = 'nera_saw_demo_users';
	const OPTION_SEED_OPTS = 'nera_saw_seed_opts';
	/** Transient: question texts used during the in-progress seed run (dedupe). */
	const TRANSIENT_USED  = 'nera_saw_seed_used_texts';

	const QUESTIONS_PER_LEVEL     = 8;
	const DEFAULT_PRODUCTS        = 1;
	const DEFAULT_SUCCESS_SUBS    = 15;
	const DEFAULT_ERROR_SUBS      = 5;
	const DEFAULT_CUSTOMERS       = 8;
	const DEFAULT_WALLET_MIN      = 5.0;
	const DEFAULT_WALLET_MAX      = 200.0;
	const DEFAULT_TICKETS_MIN     = 100;
	const DEFAULT_TICKETS_MAX     = 1000;
	const DEFAULT_DIST_MIN        = 1;
	const DEFAULT_DIST_MAX        = 3;
	const DEMO_STOCK              = 500;
	const DEMO_CATEGORY           = 'Strike A Win Demo';
	const PURCHASE_BATCH_MIN      = 1; // runs bought per tier when a player needs a run.
	const PURCHASE_BATCH_MAX      = 4;

	/**
	 * Is this environment safe to seed without forcing?
	 *
	 * @return bool
	 */
	public static function is_non_production() {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			return 'production' !== wp_get_environment_type();
		}
		return true;
	}

	/**
	 * Seed the demo data.
	 *
	 * @param bool $force Bypass the production guard.
	 * @return array Result summary (or WP_Error-like array with 'error').
	 */
	public static function seed( $force = false, array $opts = array() ) {
		if ( ! $force && ! self::is_non_production() ) {
			return array( 'error' => __( 'Refusing to seed on a production environment. Use the force option if you are certain.', 'nera-strikeawin' ) );
		}
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return array( 'error' => __( 'WooCommerce is required to seed.', 'nera-strikeawin' ) );
		}

		$per_level    = isset( $opts['questions_per_level'] ) ? max( 1, (int) $opts['questions_per_level'] ) : self::QUESTIONS_PER_LEVEL;
		$num_products = isset( $opts['products'] ) ? max( 1, (int) $opts['products'] ) : self::DEFAULT_PRODUCTS;
		$num_success  = isset( $opts['success_submissions'] ) ? max( 0, (int) $opts['success_submissions'] ) : 0;
		$num_error    = isset( $opts['error_submissions'] ) ? max( 0, (int) $opts['error_submissions'] ) : 0;
		$num_customers = isset( $opts['customers'] ) ? max( 0, (int) $opts['customers'] ) : self::DEFAULT_CUSTOMERS;
		$wallet_min   = isset( $opts['wallet_min'] ) ? max( 0.0, (float) $opts['wallet_min'] ) : self::DEFAULT_WALLET_MIN;
		$wallet_max   = isset( $opts['wallet_max'] ) ? max( $wallet_min, (float) $opts['wallet_max'] ) : max( $wallet_min, self::DEFAULT_WALLET_MAX );
		$tickets_min  = isset( $opts['tickets_min'] ) ? max( 1, (int) $opts['tickets_min'] ) : self::DEFAULT_TICKETS_MIN;
		$tickets_max  = isset( $opts['tickets_max'] ) ? max( $tickets_min, (int) $opts['tickets_max'] ) : max( $tickets_min, self::DEFAULT_TICKETS_MAX );
		$dist_min     = isset( $opts['dist_min'] ) ? max( 1, (int) $opts['dist_min'] ) : self::DEFAULT_DIST_MIN;
		$dist_max     = isset( $opts['dist_max'] ) ? max( $dist_min, (int) $opts['dist_max'] ) : max( $dist_min, self::DEFAULT_DIST_MAX );
		// A per-level quiz count can never exceed the questions seeded per level.
		$dist_min = min( $dist_min, $per_level );
		$dist_max = min( $dist_max, $per_level );

		self::begin_questions_seed();
		$questions_created = self::seed_questions( $per_level );
		delete_transient( self::used_transient_key() );
		$messages          = array(
			sprintf(
				/* translators: %d: question count */
				__( 'Seeded %d questions across the ladder.', 'nera-strikeawin' ),
				(int) $questions_created
			),
		);

		$new_products = array();
		for ( $i = 1; $i <= $num_products; $i++ ) {
			$new_products[] = self::seed_competition( $i, wp_rand( $tickets_min, $tickets_max ), $dist_min, $dist_max );
		}
		$messages[] = sprintf(
			/* translators: %d: competition count */
			__( 'Created %d demo giveaway competition(s).', 'nera-strikeawin' ),
			count( $new_products )
		);

		$products = (array) get_option( self::OPTION_PRODUCTS, array() );
		$products = array_values( array_unique( array_map( 'intval', array_merge( $products, $new_products ) ) ) );
		update_option( self::OPTION_PRODUCTS, $products );

		// Demo customers (wallet-funded via TeraWallet when available).
		$customers = self::create_demo_customers( $num_customers, $wallet_min, $wallet_max );
		$messages[] = sprintf(
			/* translators: %d: customer count */
			__( 'Created %d demo customer(s) with wallet balances.', 'nera-strikeawin' ),
			count( $customers )
		);

		$sub_result = array( 'success' => 0, 'errored' => 0, 'tickets_minted' => 0 );
		if ( ( $num_success + $num_error ) > 0 ) {
			$messages[] = sprintf(
				/* translators: 1: success count, 2: error count */
				__( 'Simulating %1$d successful and %2$d errored quiz submission(s)…', 'nera-strikeawin' ),
				$num_success,
				$num_error
			);
			$sub_result = self::simulate_plays( $new_products, $num_success, $num_error, $customers );
			$messages[] = sprintf(
				/* translators: 1: success runs, 2: errored runs, 3: tickets minted */
				__( '%1$d completed, %2$d errored (restorable); minted %3$d LFW ticket(s).', 'nera-strikeawin' ),
				(int) $sub_result['success'],
				(int) $sub_result['errored'],
				(int) $sub_result['tickets_minted']
			);
		}

		return array(
			'questions'       => $questions_created,
			'competitions'    => count( $new_products ),
			'competition_ids' => $new_products,
			'customers'       => count( $customers ),
			'submissions'     => (int) $sub_result['success'] + (int) $sub_result['errored'],
			'tickets_minted'  => (int) $sub_result['tickets_minted'],
			'total_in_bank'   => Nera_SAW_Question_Bank::count( self::BATCH_ID ),
			'messages'        => $messages,
		);
	}

	/**
	 * Out-of-the-box defaults for the seed form fields.
	 *
	 * @return array
	 */
	public static function form_defaults() {
		return array(
			'questions_per_level' => self::QUESTIONS_PER_LEVEL,
			'products'            => self::DEFAULT_PRODUCTS,
			'tickets_min'         => self::DEFAULT_TICKETS_MIN,
			'tickets_max'         => self::DEFAULT_TICKETS_MAX,
			'dist_min'            => self::DEFAULT_DIST_MIN,
			'dist_max'            => self::DEFAULT_DIST_MAX,
			'success_submissions' => self::DEFAULT_SUCCESS_SUBS,
			'error_submissions'   => self::DEFAULT_ERROR_SUBS,
			'customers'           => self::DEFAULT_CUSTOMERS,
			'wallet_min'          => self::DEFAULT_WALLET_MIN,
			'wallet_max'          => self::DEFAULT_WALLET_MAX,
		);
	}

	/**
	 * Last-used seed options (merged over form defaults) used to pre-fill the seed
	 * form so it remembers the latest values.
	 *
	 * @return array
	 */
	public static function saved_opts() {
		$raw = get_option( self::OPTION_SEED_OPTS, array() );
		return wp_parse_args( is_array( $raw ) ? $raw : array(), self::form_defaults() );
	}

	/**
	 * Persist the seed options last used (site-wide), so the form pre-fills them.
	 *
	 * @param array $opts Seed options (same shape as parse_request()['opts']).
	 */
	public static function save_opts( array $opts ) {
		$d = self::form_defaults();
		update_option(
			self::OPTION_SEED_OPTS,
			array(
				'questions_per_level' => max( 1, (int) ( $opts['questions_per_level'] ?? $d['questions_per_level'] ) ),
				'products'            => max( 1, (int) ( $opts['products'] ?? $d['products'] ) ),
				'tickets_min'         => max( 1, (int) ( $opts['tickets_min'] ?? $d['tickets_min'] ) ),
				'tickets_max'         => max( 1, (int) ( $opts['tickets_max'] ?? $d['tickets_max'] ) ),
				'dist_min'            => max( 1, (int) ( $opts['dist_min'] ?? $d['dist_min'] ) ),
				'dist_max'            => max( 1, (int) ( $opts['dist_max'] ?? $d['dist_max'] ) ),
				'success_submissions' => max( 0, (int) ( $opts['success_submissions'] ?? $d['success_submissions'] ) ),
				'error_submissions'   => max( 0, (int) ( $opts['error_submissions'] ?? $d['error_submissions'] ) ),
				'customers'           => max( 0, (int) ( $opts['customers'] ?? $d['customers'] ) ),
				'wallet_min'          => max( 0, (float) ( $opts['wallet_min'] ?? $d['wallet_min'] ) ),
				'wallet_max'          => max( 0, (float) ( $opts['wallet_max'] ?? $d['wallet_max'] ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Staged phase API (drives the admin's live per-phase AJAX progress). */
	/* ------------------------------------------------------------------ */

	/**
	 * Phase: seed the graded question bank (whole bank in one call). Retained for
	 * the non-JS fallback; the admin AJAX path uses phase_questions_chunk() so large
	 * per-level counts can't time out (each question is a full wp_insert_post).
	 *
	 * @param array $opts Seed options.
	 * @return int Questions created.
	 */
	public static function phase_questions( array $opts ) {
		$per_level = isset( $opts['questions_per_level'] ) ? max( 1, (int) $opts['questions_per_level'] ) : self::QUESTIONS_PER_LEVEL;
		self::begin_questions_seed();
		return (int) self::seed_questions( $per_level );
	}

	/**
	 * Phase: seed a chunk of the question bank (browser-driven, resumable). The
	 * offset is a linear cursor across the whole bank (levels × per_level); each
	 * call inserts up to $limit questions and the caller loops until offset reaches
	 * questions_total(). Offset 0 wipes prior demo questions and resets dedupe state.
	 *
	 * @param array $opts   Seed options.
	 * @param int   $offset Questions already seeded this run.
	 * @param int   $limit  How many to insert this chunk.
	 * @return int Questions created this chunk.
	 */
	public static function phase_questions_chunk( array $opts, $offset, $limit ) {
		$per_level = isset( $opts['questions_per_level'] ) ? max( 1, (int) $opts['questions_per_level'] ) : self::QUESTIONS_PER_LEVEL;
		$offset    = (int) $offset;
		if ( 0 === $offset ) {
			self::begin_questions_seed();
		}
		$created = (int) self::seed_questions_slice( $per_level, $offset, (int) $limit );
		if ( ( $offset + (int) $limit ) >= self::questions_total( $per_level ) ) {
			delete_transient( self::used_transient_key() );
		}
		return $created;
	}

	/**
	 * Start a fresh question-bank seed: remove prior demo questions and clear the
	 * in-run dedupe map so Seed never stacks duplicates on top of an old bank.
	 */
	public static function begin_questions_seed() {
		self::wipe_questions();
		delete_transient( self::used_transient_key() );
		set_transient( self::used_transient_key(), array(), HOUR_IN_SECONDS );
	}

	/**
	 * @return string
	 */
	private static function used_transient_key() {
		return self::TRANSIENT_USED . '_' . get_current_user_id();
	}

	/**
	 * @return array<string,bool>
	 */
	private static function load_used_texts() {
		$raw = get_transient( self::used_transient_key() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param array<string,bool> $used Used map.
	 */
	private static function save_used_texts( array $used ) {
		set_transient( self::used_transient_key(), $used, HOUR_IN_SECONDS );
	}

	/**
	 * Total questions the bank will hold for a given per-level count (all ladder
	 * levels). Drives the chunked questions phase's progress bar.
	 *
	 * @param int $per_level Questions per level.
	 * @return int
	 */
	public static function questions_total( $per_level ) {
		return max( 1, (int) $per_level ) * count( Nera_SAW_Constants::ladder() );
	}

	/**
	 * Phase: create the demo competitions (tracked in the products option).
	 *
	 * @param array $opts Seed options.
	 * @return int Competitions created.
	 */
	public static function phase_competitions( array $opts ) {
		$per_level    = isset( $opts['questions_per_level'] ) ? max( 1, (int) $opts['questions_per_level'] ) : self::QUESTIONS_PER_LEVEL;
		$num_products = isset( $opts['products'] ) ? max( 1, (int) $opts['products'] ) : self::DEFAULT_PRODUCTS;
		$tickets_min  = isset( $opts['tickets_min'] ) ? max( 1, (int) $opts['tickets_min'] ) : self::DEFAULT_TICKETS_MIN;
		$tickets_max  = isset( $opts['tickets_max'] ) ? max( $tickets_min, (int) $opts['tickets_max'] ) : max( $tickets_min, self::DEFAULT_TICKETS_MAX );
		$dist_min     = isset( $opts['dist_min'] ) ? max( 1, (int) $opts['dist_min'] ) : self::DEFAULT_DIST_MIN;
		$dist_max     = isset( $opts['dist_max'] ) ? max( $dist_min, (int) $opts['dist_max'] ) : max( $dist_min, self::DEFAULT_DIST_MAX );
		$dist_min     = min( $dist_min, $per_level );
		$dist_max     = min( $dist_max, $per_level );

		$new = array();
		for ( $i = 1; $i <= $num_products; $i++ ) {
			$new[] = self::seed_competition( $i, wp_rand( $tickets_min, $tickets_max ), $dist_min, $dist_max );
		}
		$products = (array) get_option( self::OPTION_PRODUCTS, array() );
		$products = array_values( array_unique( array_map( 'intval', array_merge( $products, $new ) ) ) );
		update_option( self::OPTION_PRODUCTS, $products );

		return count( $new );
	}

	/**
	 * Phase: ensure the demo customer pool exists (wallet-funded).
	 *
	 * @param array $opts Seed options.
	 * @return int Customers in the pool.
	 */
	public static function phase_customers( array $opts ) {
		$num  = isset( $opts['customers'] ) ? max( 0, (int) $opts['customers'] ) : self::DEFAULT_CUSTOMERS;
		$wmin = isset( $opts['wallet_min'] ) ? max( 0.0, (float) $opts['wallet_min'] ) : self::DEFAULT_WALLET_MIN;
		$wmax = isset( $opts['wallet_max'] ) ? max( $wmin, (float) $opts['wallet_max'] ) : max( $wmin, self::DEFAULT_WALLET_MAX );
		return count( self::create_demo_customers( $num, $wmin, $wmax ) );
	}

	/**
	 * Total simulated plays for a seed (success + error).
	 *
	 * @param array $opts Seed options.
	 * @return int
	 */
	public static function simulate_total( array $opts ) {
		$success = isset( $opts['success_submissions'] ) ? max( 0, (int) $opts['success_submissions'] ) : 0;
		$error   = isset( $opts['error_submissions'] ) ? max( 0, (int) $opts['error_submissions'] ) : 0;
		return $success + $error;
	}

	/**
	 * Phase (chunked): simulate players over the linear sequence of plays. Indices
	 * [0, success) are successful runs; [success, success+error) are errored runs.
	 * Each play buys grants as needed and drives the real run engine.
	 *
	 * @param array $opts   Seed options.
	 * @param int   $offset Plays already simulated this run.
	 * @param int   $limit  How many to attempt this chunk.
	 * @return array { success, errored, tickets_minted }
	 */
	public static function phase_simulate( array $opts, $offset, $limit ) {
		$offset  = max( 0, (int) $offset );
		$limit   = max( 0, (int) $limit );
		$success = isset( $opts['success_submissions'] ) ? max( 0, (int) $opts['success_submissions'] ) : 0;
		$error   = isset( $opts['error_submissions'] ) ? max( 0, (int) $opts['error_submissions'] ) : 0;
		$total   = $success + $error;
		$end     = min( $offset + $limit, $total );

		$res = array( 'success' => 0, 'errored' => 0, 'tickets_minted' => 0 );
		if ( $limit < 1 || $offset >= $end ) {
			return $res;
		}

		$product_ids = array_values( array_filter( array_map( 'intval', (array) get_option( self::OPTION_PRODUCTS, array() ) ) ) );
		$num         = isset( $opts['customers'] ) ? max( 0, (int) $opts['customers'] ) : self::DEFAULT_CUSTOMERS;
		$wmin        = isset( $opts['wallet_min'] ) ? max( 0.0, (float) $opts['wallet_min'] ) : self::DEFAULT_WALLET_MIN;
		$wmax        = isset( $opts['wallet_max'] ) ? max( $wmin, (float) $opts['wallet_max'] ) : max( $wmin, self::DEFAULT_WALLET_MAX );
		$customers   = self::create_demo_customers( $num, $wmin, $wmax );

		for ( $o = $offset; $o < $end; $o++ ) {
			$one = self::simulate_one_play( $o < $success, $product_ids, $customers );
			if ( ! $one ) {
				continue;
			}
			if ( $o < $success ) {
				$res['success']++;
				$res['tickets_minted'] += (int) $one['tickets'];
			} else {
				$res['errored']++;
			}
		}
		return $res;
	}

	/**
	 * Non-JS path: simulate all plays in one go.
	 *
	 * @param array $product_ids Demo competition IDs.
	 * @param int   $success     Success plays.
	 * @param int   $error       Error plays.
	 * @param array $customers   Demo customer IDs.
	 * @return array { success, errored, tickets_minted }
	 */
	private static function simulate_plays( array $product_ids, $success, $error, array $customers ) {
		$product_ids = array_values( array_filter( array_map( 'intval', $product_ids ) ) );
		$res         = array( 'success' => 0, 'errored' => 0, 'tickets_minted' => 0 );
		$total       = max( 0, (int) $success ) + max( 0, (int) $error );
		for ( $o = 0; $o < $total; $o++ ) {
			$one = self::simulate_one_play( $o < (int) $success, $product_ids, $customers );
			if ( ! $one ) {
				continue;
			}
			if ( $o < (int) $success ) {
				$res['success']++;
				$res['tickets_minted'] += (int) $one['tickets'];
			} else {
				$res['errored']++;
			}
		}
		return $res;
	}

	/**
	 * Seed demo questions across the ladder using realistic trivia graded by
	 * difficulty. Each question's full text is its title; answers are the new
	 * repeater rows (exactly one correct). Levels beyond the curated set of five
	 * fall back to full-sentence generated placeholders.
	 *
	 * @return int Count created.
	 */
	private static function seed_questions( $per_level = self::QUESTIONS_PER_LEVEL ) {
		$per_level = max( 1, (int) $per_level );
		return self::seed_questions_slice( $per_level, 0, self::questions_total( $per_level ) );
	}

	/**
	 * Seed a contiguous slice of the graded question bank. The bank is a flat
	 * sequence of `count(ladder) × per_level` questions ordered level-by-level;
	 * offset O maps to level intdiv(O, per_level), position O % per_level. Slicing
	 * lets the admin seed large banks in chunks without a single request timing out
	 * (each question is a full wp_insert_post + meta + term). Question texts are
	 * deduped across the whole seed run (all chunks and levels) via a transient map.
	 *
	 * @param int $per_level Questions per level.
	 * @param int $offset    Linear start index (inclusive).
	 * @param int $limit     Max questions to insert this call.
	 * @return int Count created.
	 */
	private static function seed_questions_slice( $per_level, $offset, $limit ) {
		$per_level = max( 1, (int) $per_level );
		$offset    = max( 0, (int) $offset );
		$limit     = max( 0, (int) $limit );
		if ( $limit < 1 ) {
			return 0;
		}

		$levels = array_values( Nera_SAW_Constants::ladder() );
		$total  = count( $levels ) * $per_level;
		$end    = min( $offset + $limit, $total );
		if ( $offset >= $end ) {
			return 0;
		}

		$pool    = self::trivia_pool();
		$created = 0;
		$used    = self::load_used_texts();

		for ( $o = $offset; $o < $end; $o++ ) {
			$rank_i  = intdiv( $o, $per_level );
			$i       = $o % $per_level;
			$level   = $levels[ $rank_i ];
			$curated = isset( $pool[ $rank_i ] ) ? $pool[ $rank_i ] : array();

			$text  = '';
			$built = array();

			if ( isset( $curated[ $i ] ) ) {
				$q    = $curated[ $i ];
				$cand = (string) $q['q'];
				if ( '' !== $cand && ! isset( $used[ $cand ] ) ) {
					$text  = $cand;
					$built = self::build_answers( $q['a'], (int) $q['c'] );
				}
			}

			if ( '' === $text ) {
				// Generated (or curated text already used): unique across the whole run.
				$gen   = self::generate_question( $rank_i, $o, $used );
				$text  = (string) $gen['q'];
				$built = self::build_answers( $gen['a'], (int) $gen['c'] );
			}

			$used[ $text ] = true;

			Nera_SAW_Question_Bank::insert(
				array(
					'level_key'     => $level['key'],
					'category'      => self::DEMO_CATEGORY,
					'question_text' => $text,
					'answers'       => $built,
					'seed_batch_id' => self::BATCH_ID,
				)
			);
			$created++;
		}

		self::save_used_texts( $used );
		return $created;
	}

	/**
	 * Build a normalised answer list ({ text, correct }) with the correct answer
	 * placed at a RANDOM position (so seeded data never has a fixed "always #1"
	 * pattern). Exactly one answer is flagged correct, even if texts collide.
	 *
	 * @param array $texts         Ordered answer texts.
	 * @param int   $correct_index Index of the correct answer within $texts.
	 * @return array List of { text, correct }.
	 */
	private static function build_answers( array $texts, $correct_index ) {
		$texts = array_values( array_map( 'strval', $texts ) );
		if ( empty( $texts ) ) {
			return array( array( 'text' => '', 'correct' => true ) );
		}
		$correct_text = isset( $texts[ $correct_index ] ) ? $texts[ $correct_index ] : $texts[0];

		shuffle( $texts );

		$out         = array();
		$flagged     = false;
		foreach ( $texts as $t ) {
			$is_correct = ( ! $flagged && $t === $correct_text );
			if ( $is_correct ) {
				$flagged = true;
			}
			$out[] = array( 'text' => $t, 'correct' => $is_correct );
		}
		if ( ! $flagged ) {
			$out[0]['correct'] = true; // safety: guarantee one correct.
		}
		return $out;
	}

	/**
	 * Generate one real, unique question for a difficulty rank. Retries to avoid
	 * any text already used in this seed run, then falls back to a wide-entropy
	 * arithmetic question guaranteed unique via the linear ordinal.
	 *
	 * @param int   $rank    0-based difficulty rank (0 = easiest).
	 * @param int   $ordinal Global linear position (adds entropy + unique fallback).
	 * @param array $used    Map of already-used question texts.
	 * @return array { q, a: string[], c: int }
	 */
	private static function generate_question( $rank, $ordinal, array $used ) {
		for ( $attempt = 0; $attempt < 24; $attempt++ ) {
			$gen = self::gen_by_rank( (int) $rank, (int) $ordinal + $attempt, $used );
			if ( '' !== (string) $gen['q'] && ! isset( $used[ $gen['q'] ] ) ) {
				return $gen;
			}
		}
		return self::gen_arithmetic_wide( (int) $ordinal );
	}

	/**
	 * Dispatch to a generator appropriate for the rank, rotating by ordinal so a
	 * level mixes question shapes.
	 *
	 * @param int   $rank    Difficulty rank.
	 * @param int   $ordinal Rotation seed.
	 * @param array $used    Already-used question texts (for pool-based gens).
	 * @return array { q, a, c }
	 */
	private static function gen_by_rank( $rank, $ordinal, array $used = array() ) {
		switch ( (int) $rank ) {
			case 0:
				$gens = array( 'gen_add', 'gen_subtract', 'gen_times_table' );
				break;
			case 1:
				$gens = array( 'gen_multiply', 'gen_divide', 'gen_percent', 'gen_add_two_digit' );
				break;
			case 2:
				$gens = array( 'gen_capital', 'gen_square', 'gen_multiply_large' );
				break;
			case 3:
				$gens = array( 'gen_element_symbol', 'gen_sqrt', 'gen_capital', 'gen_sequence' );
				break;
			default:
				$gens = array( 'gen_atomic_number', 'gen_power', 'gen_roman', 'gen_element_symbol' );
				break;
		}
		$method = $gens[ $ordinal % count( $gens ) ];
		if ( in_array( $method, array( 'gen_capital', 'gen_element_symbol', 'gen_atomic_number' ), true ) ) {
			return self::$method( $ordinal, $used );
		}
		return self::$method( $ordinal );
	}

	/**
	 * Wrap a numeric answer with three distinct near-miss distractors.
	 *
	 * @param int|float $correct Correct value.
	 * @param int       $spread  Max distance for distractors.
	 * @return array { a: string[], c: int } (correct at index 0; positions get
	 *               randomised later by build_answers()).
	 */
	private static function numeric_choices( $correct, $spread = 5 ) {
		$spread = max( 2, (int) $spread );
		$opts   = array();
		$tries  = 0;
		while ( count( $opts ) < 3 && $tries < 60 ) {
			$tries++;
			$delta = wp_rand( 1, $spread ) * ( wp_rand( 0, 1 ) ? 1 : -1 );
			$cand  = $correct + $delta;
			if ( $cand === $correct || in_array( $cand, $opts, true ) || $cand < 0 ) {
				continue;
			}
			$opts[] = $cand;
		}
		$n = 1;
		while ( count( $opts ) < 3 ) {
			$cand = $correct + $n;
			if ( ! in_array( $cand, $opts, true ) && $cand !== $correct ) {
				$opts[] = $cand;
			}
			$n++;
		}
		return array( 'a' => array_map( 'strval', array_merge( array( $correct ), $opts ) ), 'c' => 0 );
	}

	/** Easy: addition. */
	private static function gen_add( $ordinal ) {
		$a = wp_rand( 2, 20 );
		$b = wp_rand( 2, 20 );
		$c = self::numeric_choices( $a + $b, 6 );
		return array( 'q' => sprintf( 'What is %d + %d?', $a, $b ), 'a' => $c['a'], 'c' => $c['c'] );
	}

	/** Easy: subtraction (non-negative). */
	private static function gen_subtract( $ordinal ) {
		$a = wp_rand( 10, 40 );
		$b = wp_rand( 1, $a );
		$c = self::numeric_choices( $a - $b, 6 );
		return array( 'q' => sprintf( 'What is %d − %d?', $a, $b ), 'a' => $c['a'], 'c' => $c['c'] );
	}

	/** Easy: times tables. */
	private static function gen_times_table( $ordinal ) {
		$a = wp_rand( 2, 9 );
		$b = wp_rand( 2, 9 );
		$c = self::numeric_choices( $a * $b, 8 );
		return array( 'q' => sprintf( 'What is %d × %d?', $a, $b ), 'a' => $c['a'], 'c' => $c['c'] );
	}

	/** Moderate: two-factor multiplication. */
	private static function gen_multiply( $ordinal ) {
		$a = wp_rand( 3, 12 );
		$b = wp_rand( 4, 12 );
		$c = self::numeric_choices( $a * $b, 10 );
		return array( 'q' => sprintf( 'What is %d × %d?', $a, $b ), 'a' => $c['a'], 'c' => $c['c'] );
	}

	/** Moderate: exact division. */
	private static function gen_divide( $ordinal ) {
		$b = wp_rand( 3, 12 );
		$a = wp_rand( 2, 12 );
		$p = $a * $b;
		$c = self::numeric_choices( $a, 4 );
		return array( 'q' => sprintf( 'What is %d ÷ %d?', $p, $b ), 'a' => $c['a'], 'c' => $c['c'] );
	}

	/** Moderate: simple percentages. */
	private static function gen_percent( $ordinal ) {
		$pcts = array( 10, 20, 25, 50 );
		$p    = $pcts[ wp_rand( 0, count( $pcts ) - 1 ) ];
		$base = wp_rand( 2, 20 ) * 20; // multiple of 20 keeps answers whole.
		$ans  = (int) round( $base * $p / 100 );
		$c    = self::numeric_choices( $ans, 8 );
		return array( 'q' => sprintf( 'What is %d%% of %d?', $p, $base ), 'a' => $c['a'], 'c' => $c['c'] );
	}

	/** Moderate: two-digit addition. */
	private static function gen_add_two_digit( $ordinal ) {
		$a = wp_rand( 20, 99 );
		$b = wp_rand( 20, 99 );
		$c = self::numeric_choices( $a + $b, 10 );
		return array( 'q' => sprintf( 'What is %d + %d?', $a, $b ), 'a' => $c['a'], 'c' => $c['c'] );
	}

	/** Hard: squares. */
	private static function gen_square( $ordinal ) {
		$n = wp_rand( 5, 20 );
		$c = self::numeric_choices( $n * $n, 12 );
		return array( 'q' => sprintf( 'What is %d squared (%d²)?', $n, $n ), 'a' => $c['a'], 'c' => $c['c'] );
	}

	/** Hard: larger multiplication. */
	private static function gen_multiply_large( $ordinal ) {
		$a = wp_rand( 11, 25 );
		$b = wp_rand( 3, 12 );
		$c = self::numeric_choices( $a * $b, 14 );
		return array( 'q' => sprintf( 'What is %d × %d?', $a, $b ), 'a' => $c['a'], 'c' => $c['c'] );
	}

	/** Very hard: square roots of perfect squares. */
	private static function gen_sqrt( $ordinal ) {
		$n = wp_rand( 6, 25 );
		$c = self::numeric_choices( $n, 4 );
		return array( 'q' => sprintf( 'What is the square root of %d?', $n * $n ), 'a' => $c['a'], 'c' => $c['c'] );
	}

	/** Very hard: next term of an arithmetic sequence. */
	private static function gen_sequence( $ordinal ) {
		$start = wp_rand( 2, 12 );
		$step  = wp_rand( 2, 9 );
		$terms = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$terms[] = $start + $i * $step;
		}
		$next = $start + 4 * $step;
		$c    = self::numeric_choices( $next, $step + 2 );
		return array(
			'q' => sprintf( 'What is the next number in the sequence %s, …?', implode( ', ', $terms ) ),
			'a' => $c['a'],
			'c' => $c['c'],
		);
	}

	/** Expert: powers of two. */
	private static function gen_power( $ordinal ) {
		$n = wp_rand( 5, 12 );
		$c = self::numeric_choices( 2 ** $n, 40 );
		return array( 'q' => sprintf( 'What is 2 to the power of %d (2^%d)?', $n, $n ), 'a' => $c['a'], 'c' => $c['c'] );
	}

	/** Hard/Very hard: capital of a country (skips texts already used this seed). */
	private static function gen_capital( $ordinal, array $used = array() ) {
		$map  = self::capitals();
		$keys = array_keys( $map );
		shuffle( $keys );
		foreach ( $keys as $k ) {
			$q = sprintf( 'What is the capital of %s?', $k );
			if ( isset( $used[ $q ] ) ) {
				continue;
			}
			$correct = $map[ $k ];
			$others  = array_values( array_diff( array_values( $map ), array( $correct ) ) );
			shuffle( $others );
			return array(
				'q' => $q,
				'a' => array_merge( array( $correct ), array_slice( $others, 0, 3 ) ),
				'c' => 0,
			);
		}
		return array( 'q' => '', 'a' => array( '0', '1', '2', '3' ), 'c' => 0 );
	}

	/** Very hard/Expert: chemical symbol of an element. */
	private static function gen_element_symbol( $ordinal, array $used = array() ) {
		$map  = self::elements();
		$keys = array_keys( $map );
		shuffle( $keys );
		foreach ( $keys as $k ) {
			$q = sprintf( 'What is the chemical symbol for %s?', $k );
			if ( isset( $used[ $q ] ) ) {
				continue;
			}
			$correct = $map[ $k ]['symbol'];
			$others  = array();
			foreach ( $map as $name => $data ) {
				if ( $data['symbol'] !== $correct ) {
					$others[] = $data['symbol'];
				}
			}
			$others = array_values( array_unique( $others ) );
			shuffle( $others );
			return array(
				'q' => $q,
				'a' => array_merge( array( $correct ), array_slice( $others, 0, 3 ) ),
				'c' => 0,
			);
		}
		return array( 'q' => '', 'a' => array( '0', '1', '2', '3' ), 'c' => 0 );
	}

	/** Expert: element by atomic number. */
	private static function gen_atomic_number( $ordinal, array $used = array() ) {
		$map  = self::elements();
		$keys = array_keys( $map );
		shuffle( $keys );
		foreach ( $keys as $k ) {
			$number = $map[ $k ]['z'];
			$q      = sprintf( 'Which element has the atomic number %d?', (int) $number );
			if ( isset( $used[ $q ] ) ) {
				continue;
			}
			$others = array_values( array_diff( $keys, array( $k ) ) );
			shuffle( $others );
			return array(
				'q' => $q,
				'a' => array_merge( array( $k ), array_slice( $others, 0, 3 ) ),
				'c' => 0,
			);
		}
		return array( 'q' => '', 'a' => array( '0', '1', '2', '3' ), 'c' => 0 );
	}

	/** Expert: number to Roman numerals. */
	private static function gen_roman( $ordinal ) {
		$n       = wp_rand( 4, 89 );
		$correct = self::to_roman( $n );
		$others  = array();
		$tries   = 0;
		while ( count( $others ) < 3 && $tries < 40 ) {
			$tries++;
			$delta = wp_rand( 1, 6 ) * ( wp_rand( 0, 1 ) ? 1 : -1 );
			$cand  = self::to_roman( max( 1, $n + $delta ) );
			if ( $cand !== $correct && ! in_array( $cand, $others, true ) ) {
				$others[] = $cand;
			}
		}
		while ( count( $others ) < 3 ) {
			$others[] = self::to_roman( $n + count( $others ) + 1 );
		}
		return array(
			'q' => sprintf( 'What is %d in Roman numerals?', $n ),
			'a' => array_merge( array( $correct ), $others ),
			'c' => 0,
		);
	}

	/** Guaranteed-unique wide-range arithmetic fallback (ordinal baked into operands). */
	private static function gen_arithmetic_wide( $ordinal ) {
		$ordinal = max( 0, (int) $ordinal );
		$a       = 10000 + $ordinal;
		$b       = 3 + ( $ordinal % 97 );
		$c       = self::numeric_choices( $a + $b, 30 );
		return array(
			'q' => sprintf( 'What is %d + %d?', $a, $b ),
			'a' => $c['a'],
			'c' => $c['c'],
		);
	}

	/**
	 * Convert an integer (1–3999) to Roman numerals.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	private static function to_roman( $n ) {
		$n   = max( 1, min( 3999, (int) $n ) );
		$map = array(
			'M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400,
			'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40,
			'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1,
		);
		$out = '';
		foreach ( $map as $roman => $val ) {
			while ( $n >= $val ) {
				$out .= $roman;
				$n   -= $val;
			}
		}
		return $out;
	}

	/**
	 * Country => capital data for generated geography questions.
	 *
	 * @return array<string,string>
	 */
	private static function capitals() {
		return array(
			'France' => 'Paris', 'Germany' => 'Berlin', 'Spain' => 'Madrid', 'Italy' => 'Rome',
			'Portugal' => 'Lisbon', 'Greece' => 'Athens', 'Japan' => 'Tokyo', 'China' => 'Beijing',
			'Canada' => 'Ottawa', 'Australia' => 'Canberra', 'Brazil' => 'Brasília', 'Egypt' => 'Cairo',
			'Russia' => 'Moscow', 'India' => 'New Delhi', 'Peru' => 'Lima', 'Norway' => 'Oslo',
			'Sweden' => 'Stockholm', 'Finland' => 'Helsinki', 'Poland' => 'Warsaw', 'Austria' => 'Vienna',
			'Ireland' => 'Dublin', 'Turkey' => 'Ankara', 'Kenya' => 'Nairobi', 'Mexico' => 'Mexico City',
			'Argentina' => 'Buenos Aires', 'Netherlands' => 'Amsterdam', 'Belgium' => 'Brussels',
			'Switzerland' => 'Bern', 'Thailand' => 'Bangkok', 'Vietnam' => 'Hanoi', 'Chile' => 'Santiago',
			'Morocco' => 'Rabat', 'Hungary' => 'Budapest', 'Denmark' => 'Copenhagen', 'Iceland' => 'Reykjavík',
			'South Korea' => 'Seoul', 'New Zealand' => 'Wellington', 'Cuba' => 'Havana',
		);
	}

	/**
	 * Element name => { symbol, z (atomic number) } for generated chemistry
	 * questions.
	 *
	 * @return array<string,array{symbol:string,z:int}>
	 */
	private static function elements() {
		return array(
			'Hydrogen'  => array( 'symbol' => 'H', 'z' => 1 ),
			'Helium'    => array( 'symbol' => 'He', 'z' => 2 ),
			'Lithium'   => array( 'symbol' => 'Li', 'z' => 3 ),
			'Carbon'    => array( 'symbol' => 'C', 'z' => 6 ),
			'Nitrogen'  => array( 'symbol' => 'N', 'z' => 7 ),
			'Oxygen'    => array( 'symbol' => 'O', 'z' => 8 ),
			'Sodium'    => array( 'symbol' => 'Na', 'z' => 11 ),
			'Magnesium' => array( 'symbol' => 'Mg', 'z' => 12 ),
			'Aluminium' => array( 'symbol' => 'Al', 'z' => 13 ),
			'Silicon'   => array( 'symbol' => 'Si', 'z' => 14 ),
			'Phosphorus' => array( 'symbol' => 'P', 'z' => 15 ),
			'Sulfur'    => array( 'symbol' => 'S', 'z' => 16 ),
			'Chlorine'  => array( 'symbol' => 'Cl', 'z' => 17 ),
			'Potassium' => array( 'symbol' => 'K', 'z' => 19 ),
			'Calcium'   => array( 'symbol' => 'Ca', 'z' => 20 ),
			'Iron'      => array( 'symbol' => 'Fe', 'z' => 26 ),
			'Copper'    => array( 'symbol' => 'Cu', 'z' => 29 ),
			'Zinc'      => array( 'symbol' => 'Zn', 'z' => 30 ),
			'Silver'    => array( 'symbol' => 'Ag', 'z' => 47 ),
			'Tin'       => array( 'symbol' => 'Sn', 'z' => 50 ),
			'Iodine'    => array( 'symbol' => 'I', 'z' => 53 ),
			'Gold'      => array( 'symbol' => 'Au', 'z' => 79 ),
			'Mercury'   => array( 'symbol' => 'Hg', 'z' => 80 ),
			'Lead'      => array( 'symbol' => 'Pb', 'z' => 82 ),
			'Tungsten'  => array( 'symbol' => 'W', 'z' => 74 ),
			'Platinum'  => array( 'symbol' => 'Pt', 'z' => 78 ),
			'Neon'      => array( 'symbol' => 'Ne', 'z' => 10 ),
			'Argon'     => array( 'symbol' => 'Ar', 'z' => 18 ),
			'Nickel'    => array( 'symbol' => 'Ni', 'z' => 28 ),
			'Uranium'   => array( 'symbol' => 'U', 'z' => 92 ),
		);
	}

	/**
	 * Curated trivia by difficulty rank (0 = easiest). Each item: q, a[], c (index
	 * of the correct answer). Eight per level to match QUESTIONS_PER_LEVEL.
	 *
	 * @return array[]
	 */
	private static function trivia_pool() {
		return array(
			// Easy.
			array(
				array( 'q' => 'What is the capital city of France?', 'a' => array( 'Paris', 'London', 'Rome', 'Madrid' ), 'c' => 0 ),
				array( 'q' => 'How many days are there in a standard (non-leap) year?', 'a' => array( '365', '360', '366', '364' ), 'c' => 0 ),
				array( 'q' => 'Which planet is known as the Red Planet?', 'a' => array( 'Mars', 'Venus', 'Jupiter', 'Mercury' ), 'c' => 0 ),
				array( 'q' => 'What colour do you get by mixing blue and yellow?', 'a' => array( 'Green', 'Purple', 'Orange', 'Brown' ), 'c' => 0 ),
				array( 'q' => 'How many legs does a spider have?', 'a' => array( 'Eight', 'Six', 'Ten', 'Four' ), 'c' => 0 ),
				array( 'q' => 'What is the largest mammal on Earth?', 'a' => array( 'Blue whale', 'African elephant', 'Giraffe', 'Great white shark' ), 'c' => 0 ),
				array( 'q' => 'Which gas do plants absorb from the atmosphere?', 'a' => array( 'Carbon dioxide', 'Oxygen', 'Nitrogen', 'Helium' ), 'c' => 0 ),
				array( 'q' => 'What is H2O more commonly known as?', 'a' => array( 'Water', 'Salt', 'Hydrogen', 'Oxygen' ), 'c' => 0 ),
			),
			// Moderate.
			array(
				array( 'q' => 'Who wrote the play "Romeo and Juliet"?', 'a' => array( 'William Shakespeare', 'Charles Dickens', 'Jane Austen', 'Mark Twain' ), 'c' => 0 ),
				array( 'q' => 'What is the chemical symbol for gold?', 'a' => array( 'Au', 'Ag', 'Gd', 'Go' ), 'c' => 0 ),
				array( 'q' => 'In which country would you find the Great Barrier Reef?', 'a' => array( 'Australia', 'Brazil', 'Thailand', 'Mexico' ), 'c' => 0 ),
				array( 'q' => 'How many players are on a standard football (soccer) team on the pitch?', 'a' => array( 'Eleven', 'Ten', 'Twelve', 'Nine' ), 'c' => 0 ),
				array( 'q' => 'Which ocean is the largest by surface area?', 'a' => array( 'Pacific', 'Atlantic', 'Indian', 'Arctic' ), 'c' => 0 ),
				array( 'q' => 'What currency is used in Japan?', 'a' => array( 'Yen', 'Won', 'Yuan', 'Ringgit' ), 'c' => 0 ),
				array( 'q' => 'Which organ in the human body pumps blood?', 'a' => array( 'Heart', 'Liver', 'Lungs', 'Kidney' ), 'c' => 0 ),
				array( 'q' => 'What is the tallest mountain above sea level?', 'a' => array( 'Mount Everest', 'K2', 'Kilimanjaro', 'Mont Blanc' ), 'c' => 0 ),
			),
			// Hard.
			array(
				array( 'q' => 'In what year did the Berlin Wall fall?', 'a' => array( '1989', '1991', '1987', '1993' ), 'c' => 0 ),
				array( 'q' => 'What is the powerhouse of the cell?', 'a' => array( 'Mitochondrion', 'Nucleus', 'Ribosome', 'Golgi apparatus' ), 'c' => 0 ),
				array( 'q' => 'Who painted the ceiling of the Sistine Chapel?', 'a' => array( 'Michelangelo', 'Leonardo da Vinci', 'Raphael', 'Donatello' ), 'c' => 0 ),
				array( 'q' => 'What is the square root of 144?', 'a' => array( '12', '14', '11', '16' ), 'c' => 0 ),
				array( 'q' => 'Which element has the atomic number 1?', 'a' => array( 'Hydrogen', 'Helium', 'Oxygen', 'Carbon' ), 'c' => 0 ),
				array( 'q' => 'What is the capital of Canada?', 'a' => array( 'Ottawa', 'Toronto', 'Vancouver', 'Montreal' ), 'c' => 0 ),
				array( 'q' => 'Which artist recorded the album "Thriller"?', 'a' => array( 'Michael Jackson', 'Prince', 'Stevie Wonder', 'Lionel Richie' ), 'c' => 0 ),
				array( 'q' => 'How many bones are in the adult human body?', 'a' => array( '206', '201', '212', '198' ), 'c' => 0 ),
			),
			// Very Hard.
			array(
				array( 'q' => 'Which scientist proposed the three laws of motion?', 'a' => array( 'Isaac Newton', 'Albert Einstein', 'Galileo Galilei', 'Niels Bohr' ), 'c' => 0 ),
				array( 'q' => 'What is the longest river in the world?', 'a' => array( 'The Nile', 'The Amazon', 'The Yangtze', 'The Mississippi' ), 'c' => 0 ),
				array( 'q' => 'In which year did World War I begin?', 'a' => array( '1914', '1918', '1912', '1916' ), 'c' => 0 ),
				array( 'q' => 'What is the chemical symbol for potassium?', 'a' => array( 'K', 'P', 'Po', 'Pt' ), 'c' => 0 ),
				array( 'q' => 'Which country has the most natural lakes?', 'a' => array( 'Canada', 'Russia', 'United States', 'Finland' ), 'c' => 0 ),
				array( 'q' => 'Who developed the theory of general relativity?', 'a' => array( 'Albert Einstein', 'Max Planck', 'Isaac Newton', 'Richard Feynman' ), 'c' => 0 ),
				array( 'q' => 'What is the smallest prime number?', 'a' => array( '2', '1', '3', '0' ), 'c' => 0 ),
				array( 'q' => 'Which sea is the saltiest body of open water?', 'a' => array( 'The Dead Sea', 'The Red Sea', 'The Caspian Sea', 'The Mediterranean' ), 'c' => 0 ),
			),
			// Expert.
			array(
				array( 'q' => 'Which element has the atomic number 79?', 'a' => array( 'Gold', 'Silver', 'Platinum', 'Mercury' ), 'c' => 0 ),
				array( 'q' => 'In computing, what does the acronym "SQL" stand for?', 'a' => array( 'Structured Query Language', 'Simple Query Logic', 'Sequential Query Language', 'System Quality Level' ), 'c' => 0 ),
				array( 'q' => 'Who wrote the philosophical work "Critique of Pure Reason"?', 'a' => array( 'Immanuel Kant', 'Friedrich Nietzsche', 'René Descartes', 'David Hume' ), 'c' => 0 ),
				array( 'q' => 'What is the value of pi to two decimal places?', 'a' => array( '3.14', '3.16', '3.12', '3.18' ), 'c' => 0 ),
				array( 'q' => 'Which planet has the most moons in our solar system?', 'a' => array( 'Saturn', 'Jupiter', 'Uranus', 'Neptune' ), 'c' => 0 ),
				array( 'q' => 'In what year was the first successful powered flight by the Wright brothers?', 'a' => array( '1903', '1908', '1900', '1912' ), 'c' => 0 ),
				array( 'q' => 'What is the hardest known naturally occurring material?', 'a' => array( 'Diamond', 'Quartz', 'Titanium', 'Corundum' ), 'c' => 0 ),
				array( 'q' => 'Which mathematician is known for the "last theorem" proved in 1994?', 'a' => array( 'Pierre de Fermat', 'Carl Gauss', 'Leonhard Euler', 'Alan Turing' ), 'c' => 0 ),
			),
		);
	}

	/**
	 * Create the demo competition product + full quiz config.
	 *
	 * @param int $index       1-based competition index (for naming when seeding many).
	 * @param int $max_tickets LFW maximum tickets (pool size / stock) for this product.
	 * @param int $dist_min    Minimum random questions per level (quiz distribution).
	 * @param int $dist_max    Maximum random questions per level (quiz distribution).
	 * @return int Product ID.
	 */
	private static function seed_competition( $index = 1, $max_tickets = self::DEMO_STOCK, $dist_min = self::DEFAULT_DIST_MIN, $dist_max = self::DEFAULT_DIST_MAX ) {
		$tiers       = Nera_SAW_Competition_Config::default_tiers();
		$max_tickets = max( 1, (int) $max_tickets );
		$name        = $index > 1
			? sprintf( 'Strike A Win Demo Competition %d', (int) $index )
			: 'Strike A Win Demo Competition';

		$price = (string) ( isset( $tiers[0]['price'] ) ? $tiers[0]['price'] : 5 );
		$now   = current_time( 'mysql' );
		$end   = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );

		// A Strike A Win competition is an LFW "Giveaway" (lottery) product. Build
		// it with the real WC_Product_Lottery class so the type + meta are valid
		// (stock, minting, order linking, draw). Fall back to a simple product with
		// corrected _lty_* meta if the LFW class is unavailable.
		if ( class_exists( 'WC_Product_Lottery' ) ) {
			$product = new WC_Product_Lottery();
			$product->set_name( $name );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
			$product->set_sold_individually( true );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $max_tickets );
			$product->set_description( 'Demo Giveaway seeded by Nera Strike A Win. Safe to wipe from Tools → Strike A Win Demo.' );
			$product->set_regular_price( $price );
			$product->set_price( $price );
			$product_id = $product->save();

			self::apply_lottery_meta( (int) $product_id, $max_tickets, $price, $now, $end );

			wp_set_object_terms( $product_id, self::DEMO_CATEGORY, 'product_cat', false );
			update_post_meta( $product_id, self::PRODUCT_MARKER, '1' );

			// Finalise ticket pool + stock like the LFW admin save does.
			$saved = wc_get_product( $product_id );
			if ( $saved && function_exists( 'lty_is_lottery_product' ) && lty_is_lottery_product( $saved ) ) {
				if ( method_exists( $saved, 'format_and_update_automatic_ticket_numbers' ) ) {
					$saved->format_and_update_automatic_ticket_numbers();
				}
				if ( function_exists( 'wc_update_product_stock' ) ) {
					wc_update_product_stock( $product_id, $max_tickets, 'set', true );
				}
				wc_delete_product_transients( $product_id );
			}
		} else {
			// Fallback: simple product with the lottery type term + corrected meta.
			$product = new WC_Product_Simple();
			$product->set_name( $name );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
			$product->set_regular_price( $price );
			$product->set_sold_individually( true );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $max_tickets );
			$product->set_description( 'Demo Giveaway seeded by Nera Strike A Win. Safe to wipe from Tools → Strike A Win Demo.' );
			$product_id = $product->save();

			wp_set_object_terms( $product_id, 'lottery', 'product_type' );
			wp_set_object_terms( $product_id, self::DEMO_CATEGORY, 'product_cat', false );
			update_post_meta( $product_id, self::PRODUCT_MARKER, '1' );

			self::apply_lottery_meta( (int) $product_id, $max_tickets, $price, $now, $end );
		}

		// Distribution: 2 questions per level across the whole ladder.
		// Random per-level quiz count in [dist_min, dist_max]; total varies.
		$dist_min = max( 1, (int) $dist_min );
		$dist_max = max( $dist_min, (int) $dist_max );
		$distribution = array();
		foreach ( Nera_SAW_Constants::ladder() as $level ) {
			$distribution[ $level['key'] ] = wp_rand( $dist_min, $dist_max );
		}

		$config = wp_parse_args(
			array(
				'enabled'        => true,
				'quiz_type'      => 'per_question',
				'timer_seconds'  => 10,
				'total_time'     => 0,
				'distribution'   => $distribution,
				'level_rewards'  => array(),
				'tier_overrides' => array(), // inherit the global tier set.
			),
			Nera_SAW_Competition_Config::defaults()
		);

		Nera_SAW_Competition_Config::save( $product_id, $config );
		Nera_SAW_Competition_Config::set_competition( $product_id, true );
		return $product_id;
	}

	/**
	 * Write the LFW lottery meta a Giveaway product needs to be valid + mintable
	 * (mirrors the Nera Async Tester's product meta). Keys are the `_lty_*` names
	 * the LFW getters read.
	 *
	 * @param int    $product_id  Product ID.
	 * @param int    $max_tickets Ticket pool size.
	 * @param string $price       Ticket price.
	 * @param string $now         Local start datetime.
	 * @param string $end         Local end datetime.
	 */
	private static function apply_lottery_meta( $product_id, $max_tickets, $price, $now, $end ) {
		$now_gmt = $now;
		$end_gmt = $end;
		if ( class_exists( 'LTY_Date_Time' ) ) {
			$now_gmt = LTY_Date_Time::get_mysql_date_time_format( $now, false, 'UTC' );
			$end_gmt = LTY_Date_Time::get_mysql_date_time_format( $end, false, 'UTC' );
		}
		$price = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $price ) : $price;

		$meta = array(
			'_lty_lottery_schedule_type'          => '1',
			'_lty_start_date'                     => $now,
			'_lty_start_date_gmt'                 => $now_gmt,
			'_lty_end_date'                       => $end,
			'_lty_end_date_gmt'                   => $end_gmt,
			'_lty_minimum_tickets'                => '1',
			'_lty_maximum_tickets'                => (string) $max_tickets,
			'_lty_winners_count'                  => '1',
			'_lty_user_minimum_tickets'           => '1',
			'_lty_user_maximum_tickets'           => (string) $max_tickets,
			'_lty_order_maximum_tickets'          => (string) $max_tickets,
			'_lty_ticket_price_type'              => '1',
			'_lty_regular_price'                  => $price,
			'_regular_price'                      => $price,
			'_price'                              => $price,
			'_lty_ticket_generation_type'         => '1', // automatic.
			'_lty_ticket_number_type'             => '1', // sequential (matches tester).
			'_lty_ticket_sequential_start_number' => '1',
			'_lty_ticket_shuffled_start_number'   => '1',
			'_lty_ticket_start_number'            => '1',
			'_lty_manage_question'                => 'no',
			'_lty_lucky_dip'                      => 'no',
			'_lty_instant_winners'                => 'no',
			'_manage_stock'                       => 'yes',
			'_stock'                              => (string) $max_tickets,
			'lty_lottery_status'                  => 'lty_lottery_started',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $product_id, $key, $value );
		}
	}

	/**
	 * Collected LFW ticket post IDs minted during a demo seed (for marking).
	 *
	 * @var int[]
	 */
	private static $minted_ticket_ids = array();

	/**
	 * Collect a minted LFW ticket ID (hooked during demo minting only).
	 *
	 * @param mixed $ticket Ticket ID or object.
	 */
	public static function collect_ticket( $ticket ) {
		$id = is_object( $ticket ) ? ( isset( $ticket->ID ) ? (int) $ticket->ID : 0 ) : (int) $ticket;
		if ( $id > 0 ) {
			self::$minted_ticket_ids[] = $id;
		}
	}

	/**
	 * Simulate ONE player: pick a random demo customer + competition + tier, buy
	 * run grants on both tiers if needed, then drive the REAL run engine to either
	 * complete the quiz (success) or get stuck mid-quiz (error, restorable). Writes
	 * matching Quiz Log rows throughout. Returns { tickets } or null if skipped.
	 *
	 * @param bool  $is_success  True = play to completion; false = stuck/errored.
	 * @param int[] $product_ids Demo competition IDs.
	 * @param int[] $customers   Demo customer IDs.
	 * @return array|null
	 */
	private static function simulate_one_play( $is_success, array $product_ids, array $customers ) {
		if ( empty( $product_ids ) || empty( $customers ) || ! function_exists( 'wc_create_order' ) ) {
			return null;
		}

		$product_id = (int) $product_ids[ array_rand( $product_ids ) ];
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}
		$config = Nera_SAW_Competition_Config::get( $product_id );
		$tiers  = ! empty( $config['tiers'] ) ? array_values( $config['tiers'] ) : array();
		if ( empty( $tiers ) ) {
			return null;
		}
		$tier     = $tiers[ array_rand( $tiers ) ];
		$tier_key = (string) $tier['key'];

		// A player with no active run for this competition, so start() consumes a
		// fresh run instead of resuming a stuck/errored one.
		$user_id = self::pick_player( $customers, $product_id );
		if ( ! $user_id ) {
			return null;
		}

		// Buy grants (both tiers) if this tier's balance is empty.
		$balance = Nera_SAW_Run_Grants::balance( $user_id, $product_id );
		if ( (int) ( $balance[ $tier_key ] ?? 0 ) < 1 ) {
			self::purchase_runs( $user_id, $product, $config, $tiers );
		}

		// Start (consumes one run FIFO) via the real engine.
		$state = Nera_SAW_Run::start( $product_id, $tier_key, $user_id );
		if ( is_wp_error( $state ) || empty( $state['run_id'] ) ) {
			Nera_SAW_Log::error(
				'error',
				is_wp_error( $state ) ? $state->get_error_message() : 'Simulated start failed.',
				array(
					'competition_id' => $product_id,
					'tier_key'       => $tier_key,
					'user_id'        => $user_id,
					'context'        => array( 'op' => 'simulate_start' ),
				)
			);
			return null;
		}
		$run_id = (int) $state['run_id'];
		$total  = (int) ( $state['total_slots'] ?? 0 );

		Nera_SAW_Log::add(
			'run_start',
			array(
				'run_id'         => $run_id,
				'competition_id' => $product_id,
				'tier_key'       => $tier_key,
				'user_id'        => $user_id,
				'message'        => sprintf( 'Run started (%s)', (string) ( $state['status'] ?? '' ) ),
				'context'        => array( 'status' => $state['status'] ?? '', 'total_slots' => $total, 'simulated' => true ),
			)
		);

		if ( $total < 1 ) {
			return array( 'tickets' => 0 );
		}

		// Success plays every slot; error answers a random partial then gets stuck.
		$to_answer = $is_success ? $total : max( 1, wp_rand( 1, max( 1, $total - 1 ) ) );
		$last_slot = 0;
		for ( $n = 1; $n <= $to_answer; $n++ ) {
			$roll    = wp_rand( 1, 100 );
			$outcome = ( $roll <= 10 ) ? 'timeout' : ( ( $roll <= 45 ) ? 'wrong' : 'correct' );
			$r       = self::play_slot( $run_id, $user_id, $n, $outcome );
			if ( null === $r ) {
				break;
			}
			$last_slot = $n;
			$res_out   = ! empty( $r['correct'] ) ? 'correct' : ( ! empty( $r['timed_out'] ) ? 'timeout' : 'wrong' );
			Nera_SAW_Log::add(
				'answer',
				array(
					'run_id'         => $run_id,
					'competition_id' => $product_id,
					'tier_key'       => $tier_key,
					'user_id'        => $user_id,
					'slot_no'        => $n,
					'message'        => sprintf( 'Answer slot %d: %s (+%d tickets)', $n, $res_out, (int) ( $r['spins_awarded'] ?? 0 ) ),
					'context'        => $r,
				)
			);
		}

		if ( $is_success ) {
			// The last answer finalized scoring; complete_run mints the tickets.
			self::$minted_ticket_ids = array();
			add_action( 'lty_lottery_ticket_after_created', array( __CLASS__, 'collect_ticket' ), 10, 1 );
			$summary = Nera_SAW_Run::complete_run( $run_id, $user_id );
			remove_action( 'lty_lottery_ticket_after_created', array( __CLASS__, 'collect_ticket' ), 10 );

			if ( is_wp_error( $summary ) ) {
				return null; // Incomplete (a slot failed) — leave it, don't count.
			}
			foreach ( array_unique( self::$minted_ticket_ids ) as $tid ) {
				update_post_meta( (int) $tid, self::TICKET_MARKER, '1' );
			}
			$tickets = is_array( $summary['ticket_numbers'] ?? null ) ? count( $summary['ticket_numbers'] ) : 0;
			Nera_SAW_Log::add(
				'run_complete',
				array(
					'run_id'         => $run_id,
					'competition_id' => $product_id,
					'tier_key'       => $tier_key,
					'order_id'       => (int) ( $summary['order_id'] ?? 0 ),
					'user_id'        => $user_id,
					'message'        => sprintf( 'Run complete: %d tickets won, %d minted', (int) ( $summary['spins_final'] ?? 0 ), $tickets ),
					'context'        => array( 'spins_final' => (int) ( $summary['spins_final'] ?? 0 ), 'minted' => $tickets, 'simulated' => true ),
				)
			);
			return array( 'tickets' => $tickets );
		}

		// Error play: leave the run stuck + flag it errored (restorable), and log a
		// client stall/error so it looks like a real mid-quiz failure.
		Nera_SAW_Run::mark_errored( $run_id, $user_id );
		$ev  = wp_rand( 0, 1 ) ? 'client_stall' : 'client_error';
		$msg = ( 'client_stall' === $ev ) ? 'The server did not respond in time.' : 'A connection error interrupted the quiz.';
		Nera_SAW_Log::error(
			$ev,
			$msg,
			array(
				'run_id'         => $run_id,
				'competition_id' => $product_id,
				'tier_key'       => $tier_key,
				'user_id'        => $user_id,
				'slot_no'        => $last_slot,
				'context'        => array( 'phase' => 'question', 'simulated' => true ),
			)
		);
		return array( 'tickets' => 0 );
	}

	/**
	 * Pick a demo customer with no active run for the competition (so a fresh run
	 * is started rather than resuming a stuck one). Falls back to any customer.
	 *
	 * @param int[] $customers      Customer IDs.
	 * @param int   $competition_id Competition ID.
	 * @return int
	 */
	private static function pick_player( array $customers, $competition_id ) {
		global $wpdb;
		$pool = $customers;
		shuffle( $pool );
		$runs_t = Nera_SAW_Database::table( 'runs' );
		foreach ( $pool as $uid ) {
			$active = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$runs_t} WHERE user_id = %d AND competition_id = %d AND status = 'active'",
					(int) $uid,
					(int) $competition_id
				)
			);
			if ( 0 === $active ) {
				return (int) $uid;
			}
		}
		return ! empty( $customers ) ? (int) $customers[ array_rand( $customers ) ] : 0;
	}

	/**
	 * Place a paid demo order granting a small random batch of runs on BOTH tiers
	 * (tops up the demo wallet if short so the buy always succeeds), then grants the
	 * runs via the real grant path. Tracks the order for wipe.
	 *
	 * @param int        $user_id User ID.
	 * @param WC_Product $product Competition product.
	 * @param array      $config  Competition config.
	 * @param array      $tiers   Tier list.
	 * @return void
	 */
	private static function purchase_runs( $user_id, $product, array $config, array $tiers ) {
		$order = wc_create_order( array( 'customer_id' => (int) $user_id ) );
		if ( is_wp_error( $order ) || ! $order ) {
			return;
		}
		$wallet_ok = function_exists( 'woo_wallet' ) && is_object( woo_wallet() ) && isset( woo_wallet()->wallet );
		$total     = 0.0;

		foreach ( $tiers as $tier ) {
			$qty     = wp_rand( self::PURCHASE_BATCH_MIN, self::PURCHASE_BATCH_MAX );
			$item_id = $order->add_product( $product, $qty );
			if ( ! $item_id ) {
				continue;
			}
			wc_add_order_item_meta( $item_id, Nera_SAW_Reservations::ITEM_TIER, (string) $tier['key'], true );
			wc_add_order_item_meta( $item_id, Nera_SAW_Reservations::ITEM_SNAPSHOT, wp_json_encode( $config ), true );
			$line = $order->get_item( $item_id );
			if ( $line ) {
				$line->set_subtotal( (float) $tier['price'] * $qty );
				$line->set_total( (float) $tier['price'] * $qty );
				$line->save();
			}
			$total += (float) $tier['price'] * $qty;
		}

		$order->update_meta_data( self::ORDER_MARKER, '1' );
		$order->calculate_totals();
		if ( $wallet_ok ) {
			$order->set_payment_method( 'wallet' );
			$order->set_payment_method_title( __( 'Wallet', 'nera-strikeawin' ) );
		}
		$order->set_status( 'processing' ); // A paid status → grants + reserves.
		$order->save();
		$order_id = (int) $order->get_id();

		if ( $wallet_ok && $total > 0 ) {
			$wallet_balance = (float) woo_wallet()->wallet->get_wallet_balance( (int) $user_id, 'edit' );
			if ( $wallet_balance < $total ) {
				woo_wallet()->wallet->credit(
					(int) $user_id,
					( $total - $wallet_balance ) + 5,
					__( 'Strike A Win demo — top-up for entry', 'nera-strikeawin' ),
					array( 'for' => 'saw_demo_topup' )
				);
			}
			woo_wallet()->wallet->debit(
				(int) $user_id,
				$total,
				/* translators: %d order id */
				sprintf( __( 'Strike A Win demo entry (order #%d)', 'nera-strikeawin' ), $order_id ),
				array( 'for' => 'saw_demo_entry' )
			);
		}

		// Grant the runs for both tier line items (idempotent per order+item).
		Nera_SAW_Run_Grants::grant_for_order( $order_id );

		$order_ids   = (array) get_option( self::OPTION_ORDERS, array() );
		$order_ids[] = $order_id;
		update_option( self::OPTION_ORDERS, array_values( array_unique( array_map( 'intval', $order_ids ) ) ) );
	}

	/**
	 * Serve + answer one slot through the real engine, forcing the given outcome
	 * (correct | wrong | timeout). Returns the answer result, or null on failure.
	 *
	 * @param int    $run_id  Run ID.
	 * @param int    $user_id User ID.
	 * @param int    $slot_no Slot number.
	 * @param string $outcome Desired outcome.
	 * @return array|null
	 */
	private static function play_slot( $run_id, $user_id, $slot_no, $outcome ) {
		$served = Nera_SAW_Run::serve_slot( $run_id, $user_id, $slot_no );
		if ( is_wp_error( $served ) ) {
			return null;
		}

		// The correct index is on the slot snapshot the engine stored at serve time.
		global $wpdb;
		$t         = Nera_SAW_Database::table( 'run_slots' );
		$snap_json = $wpdb->get_var( $wpdb->prepare( "SELECT question_snapshot FROM {$t} WHERE run_id = %d AND slot_no = %d", (int) $run_id, (int) $slot_no ) );
		$snap      = $snap_json ? json_decode( $snap_json, true ) : array();
		$answers   = isset( $snap['answers'] ) ? (array) $snap['answers'] : array();
		$correct   = isset( $snap['correct_index'] ) ? (int) $snap['correct_index'] : -1;

		$chosen = '';
		if ( 'correct' === $outcome && $correct >= 0 ) {
			$chosen = (string) $correct;
		} elseif ( 'wrong' === $outcome ) {
			$wrong = array();
			foreach ( array_keys( $answers ) as $i ) {
				if ( (int) $i !== $correct ) {
					$wrong[] = (int) $i;
				}
			}
			$chosen = ! empty( $wrong ) ? (string) $wrong[ array_rand( $wrong ) ] : '';
		}

		$state = Nera_SAW_Run::submit_answer( $run_id, $user_id, $slot_no, $chosen );
		if ( is_wp_error( $state ) ) {
			return null;
		}
		return isset( $state['result'] ) ? (array) $state['result'] : array();
	}

	/**
	 * Create a pool of demo customer accounts, each credited a random wallet
	 * balance in [min,max] via TeraWallet (when active). Existing tracked demo
	 * customers are reused. Returns the pool of user IDs.
	 *
	 * @param int   $count      Number of customers to ensure.
	 * @param float $wallet_min Minimum wallet credit.
	 * @param float $wallet_max Maximum wallet credit.
	 * @return int[]
	 */
	private static function create_demo_customers( $count, $wallet_min, $wallet_max ) {
		$count = max( 0, (int) $count );

		$existing = array_values( array_filter( array_map( 'intval', (array) get_option( self::OPTION_USERS, array() ) ) ) );
		$existing = array_values( array_filter( $existing, static function ( $id ) {
			return (bool) get_userdata( $id );
		} ) );

		$wallet_ok  = function_exists( 'woo_wallet' ) && is_object( woo_wallet() ) && isset( woo_wallet()->wallet );
		$wallet_min = max( 0.0, (float) $wallet_min );
		$wallet_max = max( $wallet_min, (float) $wallet_max );

		$next = count( $existing ) + 1;
		while ( count( $existing ) < $count ) {
			$suffix  = strtolower( wp_generate_password( 6, false ) );
			$login   = 'saw_demo_' . $suffix;
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 20 ),
					'user_email'   => $login . '@example.test',
					'display_name' => sprintf( 'Demo Player %d', $next ),
					'first_name'   => 'Demo',
					'last_name'    => sprintf( 'Player %d', $next ),
					'role'         => 'customer',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				break; // Avoid an infinite loop on failure.
			}
			$user_id = (int) $user_id;
			update_user_meta( $user_id, self::USER_MARKER, '1' );

			if ( $wallet_ok ) {
				$balance = self::random_amount( $wallet_min, $wallet_max );
				if ( $balance > 0 ) {
					woo_wallet()->wallet->credit(
						$user_id,
						$balance,
						__( 'Strike A Win demo — seeded wallet balance', 'nera-strikeawin' ),
						array( 'for' => 'saw_demo_seed' )
					);
				}
			}

			$existing[] = $user_id;
			$next++;
		}

		update_option( self::OPTION_USERS, array_values( array_unique( $existing ) ) );

		// Only hand submissions the requested-size pool.
		return $count > 0 ? array_slice( $existing, 0, $count ) : $existing;
	}

	/**
	 * Random monetary amount in [min,max] at the store's price precision.
	 *
	 * @param float $min Minimum.
	 * @param float $max Maximum.
	 * @return float
	 */
	private static function random_amount( $min, $max ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$factor   = 10 ** $decimals;
		$lo       = (int) round( $min * $factor );
		$hi       = (int) round( $max * $factor );
		if ( $hi < $lo ) {
			$hi = $lo;
		}
		return round( wp_rand( $lo, $hi ) / $factor, $decimals );
	}

	/**
	 * Wipe all demo data. Marker-scoped; never touches real records.
	 *
	 * @return array Result summary.
	 */
	public static function wipe() {
		$competitions = self::wipe_competitions();
		$questions    = self::wipe_questions();
		$tickets      = self::wipe_tickets();
		$orders       = self::wipe_orders();
		$users        = self::wipe_users();

		return array(
			'competitions_removed' => $competitions,
			'questions_removed'    => $questions,
			'orders_removed'       => $orders,
			'tickets_removed'      => $tickets,
			'users_removed'        => $users,
			'messages'             => array(
				/* translators: %d: competition count */
				sprintf( __( 'Removed %d demo competition(s) and their runs.', 'nera-strikeawin' ), (int) $competitions ),
				/* translators: %d: question count */
				sprintf( __( 'Removed %d demo question(s) from the bank.', 'nera-strikeawin' ), (int) $questions ),
				/* translators: %d: ticket count */
				sprintf( __( 'Removed %d demo LFW ticket(s).', 'nera-strikeawin' ), (int) $tickets ),
				/* translators: %d: order count */
				sprintf( __( 'Removed %d demo order(s).', 'nera-strikeawin' ), (int) $orders ),
				/* translators: %d: user count */
				sprintf( __( 'Removed %d demo user(s).', 'nera-strikeawin' ), (int) $users ),
			),
		);
	}

	/**
	 * Wipe phase: delete demo competitions and everything scoped to them (runs,
	 * slots, reservations, grants, pool, wheel data, the product + variations).
	 * Marker-scoped (tracked option + the product marker meta).
	 *
	 * @return int Competitions removed.
	 */
	public static function wipe_competitions() {
		global $wpdb;

		$products = (array) get_option( self::OPTION_PRODUCTS, array() );
		// Belt-and-braces: also find any product carrying the marker.
		$marked = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
				self::PRODUCT_MARKER
			)
		);
		$products = array_values( array_unique( array_map( 'intval', array_merge( $products, (array) $marked ) ) ) );

		$runs_table  = Nera_SAW_Database::table( 'runs' );
		$slots_table = Nera_SAW_Database::table( 'run_slots' );

		$removed = 0;
		foreach ( $products as $competition_id ) {
			if ( $competition_id < 1 ) {
				continue;
			}

			// Run slots for this competition's runs, then the runs.
			$run_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$runs_table} WHERE competition_id = %d", $competition_id ) );
			if ( ! empty( $run_ids ) ) {
				$run_ids = array_map( 'intval', $run_ids );
				$place   = implode( ',', array_fill( 0, count( $run_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$slots_table} WHERE run_id IN ({$place})", $run_ids ) );
			}
			$wpdb->delete( $runs_table, array( 'competition_id' => $competition_id ), array( '%d' ) );

			// Reservations + grants + pool.
			Nera_SAW_Reservations::delete_for_competition( $competition_id );
			Nera_SAW_Run_Grants::delete_for_competition( $competition_id );
			Nera_SAW_Spin_Pool::delete( $competition_id );

			// Diagnostic log rows for this competition (lifecycle + errors).
			$wpdb->delete( Nera_SAW_Database::table( 'log' ), array( 'competition_id' => $competition_id ), array( '%d' ) );

			// Wheel demo data (scoped strictly to the demo product).
			self::wipe_wheel_data( $competition_id );

			// Trash variations then delete the product.
			$product = wc_get_product( $competition_id );
			if ( $product ) {
				foreach ( $product->get_children() as $variation_id ) {
					wp_delete_post( $variation_id, true );
				}
			}
			// Force-delete the product post regardless of type (lottery products
			// included), then confirm it's gone.
			wp_delete_post( $competition_id, true );
			if ( ! get_post( $competition_id ) ) {
				$removed++;
			}
		}

		delete_option( self::OPTION_PRODUCTS );
		return $removed;
	}

	/**
	 * Wipe phase: purge demo questions (soft-delete guard opened for the batch).
	 *
	 * @return int Questions removed.
	 */
	public static function wipe_questions() {
		Nera_SAW_Question_CPT::$allow_purge = true;
		$removed = Nera_SAW_Question_Bank::delete_by_batch( self::BATCH_ID );
		Nera_SAW_Question_CPT::$allow_purge = false;
		return (int) $removed;
	}

	/**
	 * Wipe phase: remove demo-minted LFW tickets.
	 *
	 * @return int Tickets removed.
	 */
	public static function wipe_tickets() {
		return (int) self::wipe_demo_tickets();
	}

	/**
	 * Wipe phase: remove demo orders.
	 *
	 * @return int Orders removed.
	 */
	public static function wipe_orders() {
		return (int) self::wipe_demo_orders();
	}

	/**
	 * Wipe phase: remove demo customers.
	 *
	 * @return int Users removed.
	 */
	public static function wipe_users() {
		return (int) self::wipe_demo_users();
	}

	/**
	 * Delete the demo-minted LFW tickets (marker-scoped).
	 *
	 * @return int Count removed.
	 */
	private static function wipe_demo_tickets() {
		if ( ! post_type_exists( 'lty_lottery_ticket' ) ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lty_lottery_ticket',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::TICKET_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		return count( $ids );
	}

	/**
	 * Delete the demo WooCommerce orders (tracked list + marker fallback).
	 *
	 * @return int Count removed.
	 */
	private static function wipe_demo_orders() {
		$ids = array_map( 'intval', (array) get_option( self::OPTION_ORDERS, array() ) );

		if ( function_exists( 'wc_get_orders' ) ) {
			$marked = wc_get_orders(
				array(
					'limit'      => -1,
					'return'     => 'ids',
					'meta_key'   => self::ORDER_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			$ids = array_merge( $ids, array_map( 'intval', (array) $marked ) );
		}

		$ids   = array_values( array_unique( array_filter( $ids ) ) );
		$count = 0;
		foreach ( $ids as $id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
			if ( $order ) {
				$order->delete( true );
				$count++;
			}
		}
		delete_option( self::OPTION_ORDERS );
		return $count;
	}

	/**
	 * Delete the demo customer accounts (tracked list + marker fallback).
	 *
	 * @return int Count removed.
	 */
	private static function wipe_demo_users() {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$ids    = array_map( 'intval', (array) get_option( self::OPTION_USERS, array() ) );
		$marked = get_users(
			array(
				'meta_key'   => self::USER_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
			)
		);
		$ids   = array_values( array_unique( array_filter( array_merge( $ids, array_map( 'intval', (array) $marked ) ) ) ) );
		$count = 0;
		foreach ( $ids as $id ) {
			if ( ! get_userdata( $id ) ) {
				continue;
			}
			// Remove TeraWallet balance/transactions before deleting the user.
			if ( function_exists( 'delete_user_wallet_transactions' ) ) {
				delete_user_wallet_transactions( $id, true );
			}
			if ( wp_delete_user( $id ) ) {
				$count++;
			}
		}
		delete_option( self::OPTION_USERS );
		return $count;
	}

	/**
	 * Delete the demo product's rows from Spin-to-Win tables (product-scoped).
	 *
	 * @param int $product_id Product ID.
	 */
	private static function wipe_wheel_data( $product_id ) {
		global $wpdb;
		$tables = array(
			'nera_stw_balances',
			'nera_stw_order_grants',
			'nera_stw_segment_stock',
			'nera_stw_pending_spins',
			'nera_stw_spin_audit',
			'nera_stw_history',
		);
		foreach ( $tables as $bare ) {
			$table  = $wpdb->prefix . $bare;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				continue;
			}
			$has_product = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'product_id' ) );
			if ( $has_product ) {
				$wpdb->delete( $table, array( 'product_id' => (int) $product_id ), array( '%d' ) );
			}
		}
	}
}
