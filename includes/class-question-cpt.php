<?php
/**
 * Question Custom Post Type: `saw_question`.
 *
 * Questions are a translatable CPT so WPML/Polylang manage language versions
 * natively (the draw is auto-scoped to the current language by those plugins).
 * Question text = post_content; the Answers are a variable-length repeater
 * (`{ text, correct }`, exactly one correct) stored in a single serialized meta;
 * the difficulty level is its own meta (edited outside the Answers group);
 * category = the shared WooCommerce `product_cat` taxonomy.
 *
 * Questions are never hard-deleted from the admin — "Delete" trashes them
 * (soft-delete); permanent purge is blocked (ADR 0003) except for the demo
 * seeder wipe, which flips {@see self::$allow_purge}.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Question_CPT
 */
class Nera_SAW_Question_CPT {

	const POST_TYPE = 'saw_question';

	const META_ANSWERS      = '_saw_answers';       // Serialized list of { text, correct }.
	const META_ANSWERS_TEXT = '_saw_answers_text';  // Derived plain-text mirror for list search (ADR 0016).
	const META_LEVEL        = '_saw_level_key';
	const META_SEED         = '_saw_seed_batch';
	const META_IMPORT       = '_saw_import_batch';  // Import-batch stamp (CONTEXT.md → Import batch).

	const OPTION_MIRROR_DONE = 'nera_saw_answers_mirror_done';

	/**
	 * When true, permanent deletion of a question is allowed (seeder wipe only).
	 *
	 * @var bool
	 */
	public static $allow_purge = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );

		// Never hard-delete a question via the admin (soft-delete only).
		add_filter( 'pre_delete_post', array( __CLASS__, 'guard_purge' ), 10, 3 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'bulk_actions' ) );

		// Question-bank listing: colour circle before the title + level/category filters
		// + Level/Category columns + answer-text search (ADR 0016).
		// The dot is injected client-side (the title column is escaped server-side,
		// so an HTML span in the_title would render as literal text).
		add_action( 'admin_footer-edit.php', array( __CLASS__, 'list_dot_script' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'level_filter_dropdown' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'category_filter_dropdown' ) );
		add_filter( 'parse_query', array( __CLASS__, 'apply_list_filters' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'list_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_list_column' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_filter( 'posts_clauses', array( __CLASS__, 'level_orderby_clauses' ), 10, 2 );
		add_filter( 'posts_search', array( __CLASS__, 'extend_list_search' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'editor_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_backfill_answers_mirror' ) );
	}

	/**
	 * Register the CPT and attach the product_cat taxonomy.
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Questions', 'nera-strikeawin' ),
					'singular_name' => __( 'Question', 'nera-strikeawin' ),
					'add_new_item'  => __( 'Add Question', 'nera-strikeawin' ),
					'edit_item'     => __( 'Edit Question', 'nera-strikeawin' ),
					'menu_name'     => __( 'Strike A Win', 'nera-strikeawin' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'nera-strikeawin', // under the Strike A Win menu.
				'show_in_rest'    => true,              // Gutenberg + Polylang/WPML friendliness.
				'supports'        => array( 'title', 'editor' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);

		// Share the WooCommerce product categories with questions (category filter).
		if ( taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy_for_object_type( 'product_cat', self::POST_TYPE );
		}
	}

	/**
	 * Answer + difficulty meta boxes (difficulty is its own box, outside answers).
	 */
	public static function meta_box() {
		add_meta_box(
			'saw-question-answers',
			__( 'Answers', 'nera-strikeawin' ),
			array( __CLASS__, 'render_answers_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'saw-question-difficulty',
			__( 'Difficulty level', 'nera-strikeawin' ),
			array( __CLASS__, 'render_difficulty_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Editor assets (repeater JS + admin styles) on the question editor + list.
	 *
	 * @param string $hook Admin hook suffix.
	 */
	public static function editor_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'nera-saw-admin', NERA_SAW_PLUGIN_URL . 'assets/css/admin.css', array(), NERA_SAW_VERSION );
	}

	/**
	 * Render the Answers repeater meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_answers_box( $post ) {
		wp_nonce_field( 'saw_question_meta', 'saw_question_meta_nonce' );
		$answers = self::get_answers( $post->ID );
		if ( empty( $answers ) ) {
			$answers = array(
				array( 'text' => '', 'correct' => true ),
				array( 'text' => '', 'correct' => false ),
			);
		}
		echo '<p class="description">' . esc_html__( 'The question text goes in the main editor above. Add the answers below and mark exactly one as correct.', 'nera-strikeawin' ) . '</p>';
		echo '<div class="saw-answers-repeater" id="saw-answers-repeater">';
		foreach ( array_values( $answers ) as $i => $ans ) {
			self::render_answer_row( (int) $i, (string) $ans['text'], ! empty( $ans['correct'] ) );
		}
		echo '</div>';
		echo '<p><button type="button" class="button" id="saw-add-answer">' . esc_html__( '+ Add answer', 'nera-strikeawin' ) . '</button></p>';

		// Row template + repeater behaviour.
		?>
		<script type="text/html" id="saw-answer-template">
			<?php self::render_answer_row( 0, '', false, true ); ?>
		</script>
		<script>
		( function() {
			var wrap = document.getElementById( 'saw-answers-repeater' );
			var tpl  = document.getElementById( 'saw-answer-template' ).innerHTML;
			function reindex() {
				wrap.querySelectorAll( '.saw-answer-row' ).forEach( function( row, i ) {
					row.querySelector( '.saw-answer-text' ).setAttribute( 'name', 'saw_answers[' + i + '][text]' );
					var r = row.querySelector( '.saw-answer-correct' );
					r.value = i;
				} );
			}
			document.getElementById( 'saw-add-answer' ).addEventListener( 'click', function() {
				var div = document.createElement( 'div' );
				div.innerHTML = tpl.trim();
				wrap.appendChild( div.firstChild );
				reindex();
			} );
			wrap.addEventListener( 'click', function( e ) {
				if ( e.target.classList.contains( 'saw-remove-answer' ) ) {
					var rows = wrap.querySelectorAll( '.saw-answer-row' );
					if ( rows.length <= 2 ) { return; } // keep at least two answers.
					e.target.closest( '.saw-answer-row' ).remove();
					reindex();
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Render a single answer repeater row.
	 *
	 * @param int    $i       Row index.
	 * @param string $text    Answer text.
	 * @param bool   $correct Whether this answer is correct.
	 * @param bool   $tpl     Template mode (no name/index binding needed).
	 */
	private static function render_answer_row( $i, $text, $correct, $tpl = false ) {
		$name = $tpl ? 'saw_answers[0][text]' : sprintf( 'saw_answers[%d][text]', (int) $i );
		echo '<div class="saw-answer-row">';
		echo '<label class="saw-answer-correct-wrap"><input type="radio" class="saw-answer-correct" name="saw_correct" value="' . (int) $i . '" ' . checked( $correct, true, false ) . '> ' . esc_html__( 'Correct', 'nera-strikeawin' ) . '</label>';
		echo '<input type="text" class="saw-answer-text regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $text ) . '" placeholder="' . esc_attr__( 'Answer text', 'nera-strikeawin' ) . '">';
		echo '<button type="button" class="button-link saw-remove-answer" title="' . esc_attr__( 'Remove', 'nera-strikeawin' ) . '">&times;</button>';
		echo '</div>';
	}

	/**
	 * Render the difficulty-level meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_difficulty_box( $post ) {
		$level = get_post_meta( $post->ID, self::META_LEVEL, true );
		echo '<select name="saw_level" style="width:100%">';
		foreach ( Nera_SAW_Constants::ladder() as $lv ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $lv['key'] ),
				selected( $level, $lv['key'], false ),
				esc_html( $lv['label'] )
			);
		}
		echo '</select>';
	}

	/**
	 * Save the answer + difficulty meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['saw_question_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['saw_question_meta_nonce'] ) ), 'saw_question_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$correct_index = isset( $_POST['saw_correct'] ) ? (int) $_POST['saw_correct'] : 0;
		$rows          = isset( $_POST['saw_answers'] ) ? (array) wp_unslash( $_POST['saw_answers'] ) : array(); // phpcs:ignore
		$answers       = array();
		$i             = 0;
		foreach ( $rows as $row ) {
			$text = isset( $row['text'] ) ? sanitize_text_field( $row['text'] ) : '';
			if ( '' === $text ) {
				$i++;
				continue;
			}
			$answers[] = array(
				'text'    => $text,
				'correct' => ( $i === $correct_index ),
			);
			$i++;
		}

		// Guarantee exactly one correct answer.
		if ( ! empty( $answers ) && ! self::has_correct( $answers ) ) {
			$answers[0]['correct'] = true;
		}

		update_post_meta( $post_id, self::META_ANSWERS, $answers );
		self::sync_answers_text_mirror( $post_id, $answers );
		update_post_meta( $post_id, self::META_LEVEL, isset( $_POST['saw_level'] ) ? sanitize_key( wp_unslash( $_POST['saw_level'] ) ) : '' );
	}

	/**
	 * Rebuild the derived plain-text answers mirror used by list search (ADR 0016).
	 * Call from every write path that touches answers.
	 *
	 * @param int        $post_id Post ID.
	 * @param array|null $answers Answers list, or null to read from meta.
	 */
	public static function sync_answers_text_mirror( $post_id, $answers = null ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return;
		}
		if ( null === $answers ) {
			$answers = self::get_answers( $post_id );
		}
		$parts = array();
		foreach ( (array) $answers as $a ) {
			$text = isset( $a['text'] ) ? trim( (string) $a['text'] ) : '';
			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}
		update_post_meta( $post_id, self::META_ANSWERS_TEXT, implode( ' ', $parts ) );
	}

	/**
	 * One-time chunked backfill of `_saw_answers_text` for existing Questions.
	 */
	public static function maybe_backfill_answers_mirror() {
		if ( get_option( self::OPTION_MIRROR_DONE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => 100,
				'no_found_rows'          => true,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_ANSWERS_TEXT,
						'compare' => 'NOT EXISTS',
					),
				),
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( empty( $ids ) ) {
			update_option( self::OPTION_MIRROR_DONE, 1, false );
			return;
		}
		foreach ( $ids as $id ) {
			self::sync_answers_text_mirror( (int) $id );
		}
	}

	/**
	 * Whether an answers list has at least one correct row.
	 *
	 * @param array $answers Answers.
	 * @return bool
	 */
	private static function has_correct( array $answers ) {
		foreach ( $answers as $a ) {
			if ( ! empty( $a['correct'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read a question's answers list (normalised).
	 *
	 * @param int $post_id Post ID.
	 * @return array[] List of { text, correct }.
	 */
	public static function get_answers( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META_ANSWERS, true );
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $a ) {
				$out[] = array(
					'text'    => isset( $a['text'] ) ? (string) $a['text'] : '',
					'correct' => ! empty( $a['correct'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Block permanent deletion of questions (soft-delete only), except when the
	 * seeder wipe has opted in via self::$allow_purge.
	 *
	 * @param WP_Post|false|null $delete       Short-circuit value.
	 * @param WP_Post            $post         Post being deleted.
	 * @param bool               $force_delete Whether this is a permanent purge.
	 * @return WP_Post|false|null
	 */
	public static function guard_purge( $delete, $post, $force_delete ) {
		if ( $force_delete && isset( $post->post_type ) && self::POST_TYPE === $post->post_type && ! self::$allow_purge ) {
			return false; // Prevent hard delete; Trash (soft-delete) still works.
		}
		return $delete;
	}

	/**
	 * Remove the "Delete Permanently" row action on the question list.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( isset( $post->post_type ) && self::POST_TYPE === $post->post_type ) {
			unset( $actions['delete'] );
		}
		return $actions;
	}

	/**
	 * Remove the permanent-delete bulk action.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		unset( $actions['delete'] );
		return $actions;
	}

	/**
	 * Print an inline colour dot before each question title on the list screen.
	 * Runs in the footer (posts are queried by then) and builds a post→colour map
	 * so the client can prepend the dot without hitting the escaped title output.
	 */
	public static function list_dot_script() {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}
		$map = array();
		foreach ( (array) ( $GLOBALS['wp_query']->posts ?? array() ) as $post ) {
			$id    = is_object( $post ) ? (int) $post->ID : (int) $post;
			$level = get_post_meta( $id, self::META_LEVEL, true );
			if ( '' !== (string) $level ) {
				$map[ $id ] = Nera_SAW_Constants::level_color( $level );
			}
		}
		if ( empty( $map ) ) {
			return;
		}
		?>
		<script>
		jQuery( function( $ ) {
			var colors = <?php echo wp_json_encode( $map ); ?>;
			$( '#the-list > tr' ).each( function() {
				var m = ( this.id || '' ).match( /post-(\d+)/ );
				if ( ! m ) { return; }
				var c = colors[ m[1] ];
				if ( ! c ) { return; }
				var $t = $( this ).find( 'td.title .row-title' ).first();
				if ( ! $t.length || $t.prev( '.saw-level-dot' ).length ) { return; }
				$( '<span class="saw-level-dot"></span>' )
					.css( { display: 'inline-block', width: '10px', height: '10px', borderRadius: '50%', marginRight: '7px', verticalAlign: 'middle', background: c } )
					.insertBefore( $t );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Difficulty-level filter dropdown on the question list toolbar.
	 *
	 * @param string $post_type Current post type.
	 */
	public static function level_filter_dropdown( $post_type = '' ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}
		$current = isset( $_GET['saw_level'] ) ? sanitize_key( wp_unslash( $_GET['saw_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<select name="saw_level">';
		echo '<option value="">' . esc_html__( 'All difficulty levels', 'nera-strikeawin' ) . '</option>';
		foreach ( Nera_SAW_Constants::ladder() as $lv ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $lv['key'] ),
				selected( $current, $lv['key'], false ),
				esc_html( $lv['label'] )
			);
		}
		echo '</select>';
	}

	/**
	 * product_cat filter dropdown beside the level filter.
	 *
	 * @param string $post_type Current post type.
	 */
	public static function category_filter_dropdown( $post_type = '' ) {
		if ( self::POST_TYPE !== $post_type || ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}
		$current = isset( $_GET['saw_product_cat'] ) ? (int) $_GET['saw_product_cat'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_dropdown_categories(
			array(
				'show_option_all' => __( 'All categories', 'nera-strikeawin' ),
				'taxonomy'        => 'product_cat',
				'name'            => 'saw_product_cat',
				'orderby'         => 'name',
				'selected'        => $current,
				'hierarchical'    => true,
				'depth'           => 3,
				'show_count'      => false,
				'hide_empty'      => false,
				'value_field'     => 'term_id',
			)
		);
	}

	/**
	 * Apply level + category filters on the question list query.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function apply_list_filters( $query ) {
		if ( ! self::is_bank_list_query( $query ) ) {
			return;
		}
		$level = isset( $_GET['saw_level'] ) ? sanitize_key( wp_unslash( $_GET['saw_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $level ) {
			$meta_query   = (array) $query->get( 'meta_query' );
			$meta_query[] = array(
				'key'   => self::META_LEVEL,
				'value' => $level,
			);
			$query->set( 'meta_query', $meta_query );
		}

		$cat = isset( $_GET['saw_product_cat'] ) ? (int) $_GET['saw_product_cat'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $cat > 0 && taxonomy_exists( 'product_cat' ) ) {
			$tax_query   = (array) $query->get( 'tax_query' );
			$tax_query[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => array( $cat ),
			);
			$query->set( 'tax_query', $tax_query );
		}
	}

	/**
	 * Add Level + Category columns to the question list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function list_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['saw_level']    = __( 'Level', 'nera-strikeawin' );
				$out['saw_category'] = __( 'Category', 'nera-strikeawin' );
			}
		}
		return $out;
	}

	/**
	 * Render Level / Category column cells.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_list_column( $column, $post_id ) {
		if ( 'saw_level' === $column ) {
			$key   = (string) get_post_meta( (int) $post_id, self::META_LEVEL, true );
			$level = $key ? Nera_SAW_Constants::level( $key ) : null;
			echo $level ? esc_html( $level['label'] ) : '&mdash;';
			return;
		}
		if ( 'saw_category' === $column ) {
			if ( ! taxonomy_exists( 'product_cat' ) ) {
				echo '&mdash;';
				return;
			}
			$terms = get_the_terms( (int) $post_id, 'product_cat' );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				echo '&mdash;';
				return;
			}
			$names = array();
			foreach ( $terms as $term ) {
				$names[] = $term->name;
			}
			echo esc_html( implode( ', ', $names ) );
		}
	}

	/**
	 * Make the Level column sortable (by ladder rank).
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns['saw_level'] = 'saw_level';
		return $columns;
	}

	/**
	 * ORDER BY ladder rank when sorting the Level column.
	 *
	 * @param array    $clauses Query clauses.
	 * @param WP_Query $query   Query.
	 * @return array
	 */
	public static function level_orderby_clauses( $clauses, $query ) {
		global $wpdb;
		if ( ! self::is_bank_list_query( $query ) ) {
			return $clauses;
		}
		if ( 'saw_level' !== $query->get( 'orderby' ) ) {
			return $clauses;
		}
		$order = ( 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ) ? 'DESC' : 'ASC';
		$cases = array( "WHEN '' THEN 9999" );
		foreach ( Nera_SAW_Constants::ladder( true ) as $lv ) {
			$cases[] = $wpdb->prepare( 'WHEN %s THEN %d', $lv['key'], (int) $lv['rank'] );
		}
		$join = " LEFT JOIN {$wpdb->postmeta} AS saw_lvl ON ({$wpdb->posts}.ID = saw_lvl.post_id AND saw_lvl.meta_key = '" . esc_sql( self::META_LEVEL ) . "') ";
		if ( false === strpos( $clauses['join'], 'saw_lvl' ) ) {
			$clauses['join'] .= $join;
		}
		$clauses['orderby'] = 'CASE saw_lvl.meta_value ' . implode( ' ', $cases ) . ' ELSE 9998 END ' . $order;
		return $clauses;
	}

	/**
	 * Extend the list search to also match the answers-text mirror (ADR 0016).
	 *
	 * @param string   $search Search SQL.
	 * @param WP_Query $query  Query.
	 * @return string
	 */
	public static function extend_list_search( $search, $query ) {
		global $wpdb;
		if ( ! self::is_bank_list_query( $query ) ) {
			return $search;
		}
		$term = trim( (string) $query->get( 's' ) );
		if ( '' === $term ) {
			return $search;
		}
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		return $wpdb->prepare(
			" AND (
				{$wpdb->posts}.post_title LIKE %s
				OR {$wpdb->posts}.post_content LIKE %s
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} saw_ans
					WHERE saw_ans.post_id = {$wpdb->posts}.ID
					  AND saw_ans.meta_key = %s
					  AND saw_ans.meta_value LIKE %s
				)
			)",
			$like,
			$like,
			self::META_ANSWERS_TEXT,
			$like
		);
	}

	/**
	 * Whether this is the main Questions list table query.
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	public static function is_bank_list_query( $query ) {
		global $pagenow;
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return false;
		}
		if ( 'edit.php' !== $pagenow ) {
			return false;
		}
		return self::POST_TYPE === ( $query->get( 'post_type' ) ?: ( $query->query_vars['post_type'] ?? '' ) );
	}

	/**
	 * Normalise a question post into the shape the run engine expects.
	 *
	 * @param int|WP_Post $post Post or ID.
	 * @return object|null { id, question_text, answers[], correct_index, level_key }
	 */
	public static function to_row( $post ) {
		$post = get_post( $post );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$answers       = self::get_answers( $post->ID );
		$correct_index = -1;
		foreach ( $answers as $i => $a ) {
			if ( ! empty( $a['correct'] ) ) {
				$correct_index = (int) $i;
				break;
			}
		}
		return (object) array(
			'id'            => (int) $post->ID,
			'question_text' => $post->post_content,
			'answers'       => $answers,
			'correct_index' => $correct_index,
			'level_key'     => (string) get_post_meta( $post->ID, self::META_LEVEL, true ),
		);
	}
}
