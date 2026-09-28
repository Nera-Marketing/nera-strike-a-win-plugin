<?php
/**
 * Question bank access over the `saw_question` CPT.
 *
 * The draw is a WP_Query (auto-scoped to the current language by WPML/Polylang),
 * filtered by the competition product's categories (`product_cat`, any-overlap)
 * and the slot's difficulty level, excluding questions the user has already seen.
 * The per-user seen ledger stays in the `question_seen` table (post IDs).
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Question_Bank
 */
class Nera_SAW_Question_Bank {

	/**
	 * Create a question post from a data array (seeder / CSV import).
	 *
	 * @param array $data level_key, question_text, answers (list of { text,
	 *                    correct }), category (product_cat term id/name),
	 *                    seed_batch_id. The full question text is the post title.
	 * @return int Post ID (0 on failure).
	 */
	public static function insert( array $data ) {
		$text    = (string) ( $data['question_text'] ?? '' );
		$post_id = wp_insert_post(
			array(
				'post_type'    => Nera_SAW_Question_CPT::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $text, // Full question text (no truncation).
				'post_content' => $text,
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		$answers = array();
		foreach ( (array) ( $data['answers'] ?? array() ) as $a ) {
			$answers[] = array(
				'text'    => isset( $a['text'] ) ? (string) $a['text'] : '',
				'correct' => ! empty( $a['correct'] ),
			);
		}
		update_post_meta( $post_id, Nera_SAW_Question_CPT::META_ANSWERS, $answers );
		Nera_SAW_Question_CPT::sync_answers_text_mirror( $post_id, $answers );
		update_post_meta( $post_id, Nera_SAW_Question_CPT::META_LEVEL, sanitize_key( (string) ( $data['level_key'] ?? '' ) ) );
		if ( ! empty( $data['seed_batch_id'] ) ) {
			update_post_meta( $post_id, Nera_SAW_Question_CPT::META_SEED, (string) $data['seed_batch_id'] );
		}
		if ( ! empty( $data['import_batch_id'] ) ) {
			update_post_meta( $post_id, Nera_SAW_Question_CPT::META_IMPORT, (string) $data['import_batch_id'] );
		}

		/*
		 * Which language this question is in. Recorded through Polylang, because the
		 * bank is managed there rather than by a taxonomy of its own — §4 and answer
		 * 1 of docs/LANGUAGE-PLAN.md. Silently ignored when there is no engine or the
		 * site does not declare that language; callers that must not create unlabelled
		 * questions check first.
		 */
		if ( ! empty( $data['language'] ) && class_exists( 'Nera_SAW_Language' ) ) {
			Nera_SAW_Language::set_for_post( $post_id, (string) $data['language'] );
		}

		if ( ! empty( $data['category'] ) && taxonomy_exists( 'product_cat' ) ) {
			$term = is_numeric( $data['category'] ) ? (int) $data['category'] : (string) $data['category'];
			wp_set_object_terms( $post_id, $term, 'product_cat', false );
		}
		return (int) $post_id;
	}

	/**
	 * Normalised question row (run-engine shape).
	 *
	 * @param int $id Post ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		return Nera_SAW_Question_CPT::to_row( $id );
	}

	/**
	 * Delete a question and its seen rows.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Nera_SAW_Database::table( 'question_seen' ), array( 'question_id' => (int) $id ), array( '%d' ) );
		return (bool) wp_delete_post( (int) $id, true );
	}

	/**
	 * Build the ordered slot list for a run.
	 *
	 * @param array $config         Competition config (snapshot).
	 * @param int   $user_id        User ID.
	 * @param int   $competition_id Competition product ID (for product_cat filter).
	 * @return array { slots: [...], exhausted: [level_key] }
	 */
	public static function draw_for_run( array $config, $user_id, $competition_id = 0 ) {
		$distribution = (array) $config['distribution'];
		$cat_terms    = self::product_category_ids( (int) $competition_id );
		$exhausted    = array();
		$picked       = array();

		/*
		 * The run's language, read from the frozen config rather than asked of
		 * Polylang's ambient state — see query_level(). Whoever calls this must have
		 * already resolved and validated it (Nera_SAW_Run::start() does, against
		 * playable_languages()); an empty string here means "no explicit choice",
		 * which leaves every query at today's default (Polylang's current-language
		 * scope, or no scoping at all without Polylang).
		 */
		$lang = isset( $config['language'] ) ? sanitize_key( (string) $config['language'] ) : '';

		// Gather the drawn question IDs per level. Two things are already true here,
		// and the Quiz Method only decides whether to keep them:
		//
		//   - Nera_SAW_Constants::ladder() is sorted ascending by rank, so $bag is
		//     built easiest-first.
		//   - query_level() draws with orderby => rand, so the questions inside one
		//     level are already in random order.
		//
		// Which is to say the ladder shape was there all along, and the shuffle below
		// was the only thing destroying it. See ADR 0019.
		$per_level = array(); // level_key => { ids: [...], reward: int }
		$bag       = array(); // one level_key entry per remaining question.
		foreach ( Nera_SAW_Constants::ladder() as $level ) {
			$key = $level['key'];
			if ( empty( $distribution[ $key ] ) ) {
				continue;
			}
			$need   = (int) $distribution[ $key ];
			$reward = Nera_SAW_Competition_Config::effective_reward( $config, $key );

			$fresh = self::query_level( $key, $cat_terms, (int) $user_id, $need, $picked, false, $lang );
			if ( count( $fresh ) < $need ) {
				$exhausted[] = $key;
				$fresh       = array_merge(
					$fresh,
					self::query_level( $key, $cat_terms, (int) $user_id, $need - count( $fresh ), array_merge( $picked, $fresh ), true, $lang )
				);
			}
			// Unique within this Run (and across levels already in $picked).
			$fresh = array_values( array_unique( array_map( 'intval', $fresh ) ) );
			$fresh = array_values(
				array_filter(
					$fresh,
					static function ( $qid ) use ( $picked ) {
						return $qid > 0 && ! in_array( (int) $qid, $picked, true );
					}
				)
			);
			if ( count( $fresh ) > $need ) {
				$fresh = array_slice( $fresh, 0, $need );
			}
			foreach ( $fresh as $qid ) {
				$picked[] = (int) $qid;
			}
			if ( ! empty( $fresh ) ) {
				$per_level[ $key ] = array( 'ids' => $fresh, 'reward' => (int) $reward );
				foreach ( $fresh as $ignored ) {
					$bag[] = $key;
				}
			}
		}

		/*
		 * Read from the frozen config rather than asked live: the caller resolves
		 * Nera_SAW_Mode::quiz_method() once, before the draw, and snapshots it — see
		 * Nera_SAW_Run::start(). Reading it fresh here would let a mid-run admin
		 * setting change disagree with how the run was actually drawn, which is
		 * exactly the mismatch the stage display (CONTEXT.md "Stage") depends on
		 * not happening. Anything but the ladder constant is treated as random,
		 * matching the resolver's own default.
		 */
		$is_ladder = isset( $config['quiz_method'] ) && Nera_SAW_Mode::QUIZ_LADDER === $config['quiz_method'];
		if ( ! $is_ladder ) {
			// Random: interleave the levels across the whole run, weighted by how
			// many questions each still has. Slot 1 may be Expert, slot 10 Easy.
			shuffle( $bag );
		}
		// Ladder: leave $bag alone — ascending by rank, shuffled within each level,
		// so the stages are in order without the run being identical every time.

		$slots   = array();
		$slot_no = 0;
		$used_q  = array();
		foreach ( $bag as $key ) {
			$qid = array_shift( $per_level[ $key ]['ids'] );
			if ( null === $qid ) {
				continue;
			}
			$qid = (int) $qid;
			if ( isset( $used_q[ $qid ] ) ) {
				continue;
			}
			$used_q[ $qid ] = true;
			$slots[]        = array(
				'slot_no'     => ++$slot_no,
				'level_key'   => $key,
				'question_id' => $qid,
				'reward_base' => (int) $per_level[ $key ]['reward'],
			);
		}

		if ( ! empty( $exhausted ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[nera-strikeawin] exhausted levels: ' . implode( ',', $exhausted ) );
		}

		return array(
			'slots'     => $slots,
			'exhausted' => $exhausted,
		);
	}

	/**
	 * Query up to $limit question IDs for a level (current language via WPML/Polylang).
	 *
	 * @param string $level_key   Level.
	 * @param int[]  $cat_terms   product_cat term IDs (empty = any).
	 * @param int    $user_id     User.
	 * @param int    $limit       Max.
	 * @param int[]  $exclude     Already-picked IDs.
	 * @param bool   $allow_seen  Fallback: allow already-seen questions.
	 * @param string $lang        Explicit language to draw in, or '' to leave the
	 *                            query at Polylang's ambient current-language scope
	 *                            (today's default, unchanged for any caller that
	 *                            does not pass one).
	 * @return int[]
	 */
	private static function query_level( $level_key, $cat_terms, $user_id, $limit, $exclude, $allow_seen, $lang = '' ) {
		if ( $limit < 1 ) {
			return array();
		}
		$args = array(
			'post_type'      => Nera_SAW_Question_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'orderby'        => 'rand',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => Nera_SAW_Question_CPT::META_LEVEL,
					'value' => $level_key,
				),
			),
		);

		/*
		 * The run's language, told to the query rather than left to Polylang's
		 * ambient "current language" — CONTEXT.md's rule: "The draw is told which
		 * language to use; it never asks what language 'we' are in." Two players
		 * can be mid-run in different languages on the same competition, and this
		 * is what makes that true rather than aspirational.
		 */
		if ( '' !== $lang ) {
			$args['lang'] = sanitize_key( $lang );
		}

		$not_in = array_map( 'intval', (array) $exclude );
		if ( ! $allow_seen ) {
			$not_in = array_merge( $not_in, self::seen_ids( (int) $user_id ) );
		}
		if ( ! empty( $not_in ) ) {
			$args['post__not_in'] = array_values( array_unique( $not_in ) );
		}

		if ( ! empty( $cat_terms ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => array_map( 'intval', $cat_terms ),
					'operator' => 'IN',
				),
			);
		}

		$q = new WP_Query( $args );
		return array_map( 'intval', $q->posts );
	}

	/**
	 * How many questions exist at a level, in a language, regardless of who has
	 * seen them — the bank-health question, not the draw question. Used to decide
	 * whether a language can be offered for a competition at all, before any run
	 * exists to draw for.
	 *
	 * @param string $level_key Level.
	 * @param int[]  $cat_terms product_cat term IDs (empty = any).
	 * @param string $lang      Language code, or '' for Polylang's ambient scope.
	 * @return int
	 */
	public static function count_available( $level_key, array $cat_terms, $lang = '' ) {
		$args = array(
			'post_type'      => Nera_SAW_Question_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => Nera_SAW_Question_CPT::META_LEVEL,
					'value' => $level_key,
				),
			),
		);
		if ( '' !== $lang ) {
			$args['lang'] = sanitize_key( $lang );
		}
		if ( ! empty( $cat_terms ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => array_map( 'intval', $cat_terms ),
					'operator' => 'IN',
				),
			);
		}

		$q = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	/**
	 * product_cat term IDs assigned to the competition product.
	 *
	 * @param int $competition_id Product ID.
	 * @return int[]
	 */
	public static function product_category_ids( $competition_id ) {
		if ( $competition_id < 1 || ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}
		$terms = wp_get_post_terms( $competition_id, 'product_cat', array( 'fields' => 'ids' ) );
		return is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
	}

	/**
	 * Seen post IDs for a user.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	private static function seen_ids( $user_id ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'question_seen' );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT question_id FROM {$table} WHERE user_id = %d", (int) $user_id ) ) );
	}

	/**
	 * Record served questions for a user (idempotent).
	 *
	 * @param int   $user_id      User.
	 * @param int[] $question_ids Post IDs.
	 */
	public static function mark_seen( $user_id, array $question_ids ) {
		global $wpdb;
		$table = Nera_SAW_Database::table( 'question_seen' );
		$now   = current_time( 'mysql' );
		foreach ( array_unique( array_map( 'intval', $question_ids ) ) as $qid ) {
			if ( $qid < 1 ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (user_id, question_id, first_seen_at) VALUES (%d, %d, %s)", (int) $user_id, $qid, $now ) );
		}
	}

	/**
	 * Count questions (optionally by seed batch).
	 *
	 * @param string|null $seed_batch_id Batch.
	 * @return int
	 */
	public static function count( $seed_batch_id = null ) {
		$args = array(
			'post_type'      => Nera_SAW_Question_CPT::POST_TYPE,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => false,
			// Every language. Polylang scopes a WP_Query on a translatable post type
			// to the current language unless told otherwise, and 'lang' => '' is how
			// it is told otherwise. Without this a bank count only ever reported the
			// default language's questions, so seeding Russian looked like it made
			// the bank smaller than it did.
			'lang'           => '',
		);
		if ( null !== $seed_batch_id ) {
			$args['meta_query'] = array( array( 'key' => Nera_SAW_Question_CPT::META_SEED, 'value' => $seed_batch_id ) ); // phpcs:ignore
		}
		$q = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	/**
	 * Delete all questions for a seed batch (+ their seen rows).
	 *
	 * @param string $seed_batch_id Batch.
	 * @return int Deleted count.
	 */
	public static function delete_by_batch( $seed_batch_id ) {
		$ids = get_posts(
			array(
				'post_type'      => Nera_SAW_Question_CPT::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => Nera_SAW_Question_CPT::META_SEED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $seed_batch_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				/*
				 * Every language, not just the current one. Polylang scopes queries
				 * on a translatable post type by default, and this wipe is what
				 * `begin_questions_seed()` runs before every reseed. Without this a
				 * Russian question was never found here, so it was never removed —
				 * each "Seed demo data" click piled a fresh copy on top of the last
				 * one instead of replacing it, which is how a bank ends up with the
				 * same Russian question twice.
				 */
				'lang'           => '',
			)
		);
		foreach ( $ids as $id ) {
			self::delete( (int) $id );
		}
		return count( $ids );
	}
}
