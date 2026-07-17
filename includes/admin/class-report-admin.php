<?php
/**
 * Strike A Win → Report.
 *
 * Pick a competition (AJAX search), see its summary (remaining / won tickets /
 * games run) and a paginated, filterable table of quiz submissions (runs). Each
 * submission opens a detail view: per-question snapshot of the player's answer,
 * tickets won, and the LFW numbers that question earned (as tags).
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Report_Admin
 */
class Nera_SAW_Report_Admin {

	const SLUG            = 'nera-saw-report';
	const AJAX_COMPS      = 'nera_saw_search_comps';
	const AJAX_USERS      = 'nera_saw_search_users';
	const AJAX_BODY       = 'nera_saw_report_body';
	const AJAX_NONCE      = 'nera_saw_report_ajax';
	const RESTORE_ACTION  = 'nera_saw_restore_run';
	const RESTORE_NONCE   = 'nera_saw_restore_run';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_COMPS, array( __CLASS__, 'ajax_comps' ) );
		add_action( 'wp_ajax_' . self::AJAX_USERS, array( __CLASS__, 'ajax_users' ) );
		add_action( 'wp_ajax_' . self::AJAX_BODY, array( __CLASS__, 'ajax_body' ) );
		add_action( 'admin_post_' . self::RESTORE_ACTION, array( __CLASS__, 'handle_restore' ) );
	}

	/**
	 * Whether a run is an errored, restorable run (active + flagged errored).
	 *
	 * @param object $run Run row.
	 * @return bool
	 */
	public static function is_restorable( $run ) {
		return $run && 'active' === (string) $run->status && 'errored' === (string) $run->end_reason;
	}

	/**
	 * Nonce'd Restore URL for a run.
	 *
	 * @param int $competition_id Competition ID.
	 * @param int $run_id         Run ID.
	 * @return string
	 */
	public static function restore_url( $competition_id, $run_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::RESTORE_ACTION,
					'run'         => (int) $run_id,
					'competition' => (int) $competition_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::RESTORE_NONCE
		);
	}

	/**
	 * Handle the Restore-run admin action: refund the run + void it (ADR 0013).
	 */
	public static function handle_restore() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'nera-strikeawin' ) );
		}
		check_admin_referer( self::RESTORE_NONCE );

		$run_id         = isset( $_GET['run'] ) ? absint( $_GET['run'] ) : 0;
		$competition_id = isset( $_GET['competition'] ) ? absint( $_GET['competition'] ) : 0;

		$result  = Nera_SAW_Run::restore( $run_id );
		$notice  = is_wp_error( $result ) ? 'restore_failed' : 'restored';

		$redirect = add_query_arg(
			array(
				'page'        => self::SLUG,
				'competition' => $competition_id,
				'saw_notice'  => $notice,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Submenu under Strike A Win.
	 */
	public static function menu() {
		add_submenu_page(
			Nera_SAW_Ladder_Admin::SLUG,
			__( 'Report', 'nera-strikeawin' ),
			__( 'Report', 'nera-strikeawin' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Assets (autocomplete + admin styles) on the report page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'nera-saw-admin', NERA_SAW_PLUGIN_URL . 'assets/css/admin.css', array(), NERA_SAW_VERSION );
		wp_enqueue_script( 'jquery-ui-autocomplete' );
		// WooCommerce bundles select2 (as "selectWoo") + its stylesheet.
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_style( 'select2' );
	}

	/**
	 * AJAX: search Strike A Win competitions by title.
	 */
	public static function ajax_comps() {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$ids  = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				's'              => $term,
				'fields'         => 'ids',
				'meta_key'       => Nera_SAW_Competition_Config::META_IS_COMP, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$results = array();
		foreach ( $ids as $id ) {
			$results[] = array( 'id' => (int) $id, 'text' => get_the_title( $id ) . ' (#' . (int) $id . ')' );
		}
		wp_send_json( array( 'results' => $results ) ); // select2 shape.
	}

	/**
	 * AJAX: render the competition report body (summary + submissions table) for
	 * inline loading when a competition is picked (no page reload).
	 */
	public static function ajax_body() {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$competition_id = isset( $_GET['competition'] ) ? absint( $_GET['competition'] ) : 0;
		if ( ! $competition_id ) {
			wp_send_json_error();
		}

		// Make the list table's pagination/filter links point back at the report
		// page (not admin-ajax.php), preserving competition + any filters. WP_List_Table
		// builds links from a PATH-relative REQUEST_URI (host is prepended).
		$args = array( 'page' => self::SLUG, 'competition' => $competition_id );
		foreach ( array( 's_user', 's_order', 'paged' ) as $k ) {
			if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] ) {
				$args[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) );
			}
		}
		$admin_path             = (string) wp_parse_url( admin_url( 'admin.php' ), PHP_URL_PATH );
		$_SERVER['REQUEST_URI'] = $admin_path . '?' . http_build_query( $args );

		set_current_screen( self::SLUG );

		ob_start();
		self::render_competition( $competition_id );
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * AJAX: search users by name/login/email.
	 */
	public static function ajax_users() {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$term  = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$users = get_users(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_nicename', 'user_email', 'display_name' ),
				'number'         => 20,
			)
		);
		$out = array();
		foreach ( $users as $u ) {
			$out[] = array( 'label' => $u->display_name . ' (' . $u->user_login . ')', 'value' => $u->display_name );
		}
		wp_send_json( $out );
	}

	/**
	 * Router: landing / list / detail.
	 */
	public static function render() {
		$competition_id = isset( $_GET['competition'] ) ? absint( $_GET['competition'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$run_id         = isset( $_GET['run'] ) ? absint( $_GET['run'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap saw-admin">';
		echo '<h1 class="saw-admin__title">' . esc_html__( 'Strike A Win — Report', 'nera-strikeawin' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['saw_notice'] ) ? sanitize_key( wp_unslash( $_GET['saw_notice'] ) ) : '';
		if ( 'restored' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Run restored: the run was returned to the player and the stuck run voided.', 'nera-strikeawin' ) . '</p></div>';
		} elseif ( 'restore_failed' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not restore that run (only an errored, stuck run can be restored).', 'nera-strikeawin' ) . '</p></div>';
		}

		self::competition_picker( $competition_id );

		echo '<div id="saw-report-body">';
		if ( $competition_id && $run_id ) {
			self::render_detail( $competition_id, $run_id );
		} elseif ( $competition_id ) {
			self::render_competition( $competition_id );
		} else {
			echo '<div class="saw-card"><p class="saw-muted">' . esc_html__( 'Search for and select a Strike A Win competition to see its submissions.', 'nera-strikeawin' ) . '</p></div>';
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * AJAX competition search box.
	 *
	 * @param int $competition_id Current competition.
	 */
	private static function competition_picker( $competition_id ) {
		$nonce      = wp_create_nonce( self::AJAX_NONCE );
		$edit_link  = $competition_id ? get_edit_post_link( $competition_id, 'url' ) : '';
		echo '<div class="saw-card">';
		echo '<div class="saw-picker-row">';
		echo '<label class="saw-field saw-picker-field"><span>' . esc_html__( 'Competition', 'nera-strikeawin' ) . '</span>';
		echo '<select id="saw-comp-select" style="width:100%">';
		if ( $competition_id ) {
			echo '<option value="' . (int) $competition_id . '" selected>' . esc_html( get_the_title( $competition_id ) . ' (#' . (int) $competition_id . ')' ) . '</option>';
		}
		echo '</select></label>';
		printf(
			'<a id="saw-view-product" class="button saw-view-product" href="%s"%s>%s</a>',
			esc_url( $edit_link ),
			$competition_id ? '' : ' style="display:none"',
			esc_html__( 'View giveaway product', 'nera-strikeawin' )
		);
		echo '</div>';
		echo '</div>';
		?>
		<script>
		jQuery( function( $ ) {
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var pageUrl = '<?php echo esc_js( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>';
			var productEditBase = '<?php echo esc_js( admin_url( 'post.php' ) ); ?>';

			// Username autocomplete inside the (possibly AJAX-injected) filter bar.
			window.sawInitUserAutocomplete = function() {
				var el = $( '#saw-filter-user' );
				if ( ! el.length || ! $.fn.autocomplete ) { return; }
				el.autocomplete( {
					minLength: 1,
					source: function( req, res ) {
						$.getJSON( ajaxurl, { action: '<?php echo esc_js( self::AJAX_USERS ); ?>', nonce: nonce, term: req.term }, res );
					}
				} );
			};
			window.sawInitUserAutocomplete();

			var $sel = $( '#saw-comp-select' );
			if ( $sel.select2 ) {
				$sel.select2( {
					width: '100%',
					placeholder: '<?php echo esc_js( __( 'Type to search competitions…', 'nera-strikeawin' ) ); ?>',
					minimumInputLength: 0,
					ajax: {
						url: ajaxurl,
						dataType: 'json',
						delay: 200,
						data: function( params ) {
							return { action: '<?php echo esc_js( self::AJAX_COMPS ); ?>', nonce: nonce, term: params.term || '' };
						},
						processResults: function( data ) { return data; }
					}
				} );
			}

			$sel.on( 'select2:select change', function() {
				var id = $( this ).val();
				if ( ! id ) { return; }
				// Point the product-edit button at the selected giveaway + reveal it.
				$( '#saw-view-product' )
					.attr( 'href', productEditBase + '?post=' + id + '&action=edit' )
					.show();
				if ( window.history && history.pushState ) {
					history.pushState( {}, '', pageUrl + '&competition=' + id );
				}
				var $body = $( '#saw-report-body' ).html( '<div class="saw-card"><p class="saw-muted"><?php echo esc_js( __( 'Loading…', 'nera-strikeawin' ) ); ?></p></div>' );
				$.getJSON( ajaxurl, { action: '<?php echo esc_js( self::AJAX_BODY ); ?>', nonce: nonce, competition: id }, function( res ) {
					if ( res && res.success ) {
						$body.html( res.data.html );
						window.sawInitUserAutocomplete();
					} else {
						$body.html( '<div class="saw-card"><p class="saw-muted"><?php echo esc_js( __( 'Could not load the report.', 'nera-strikeawin' ) ); ?></p></div>' );
					}
				} );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Competition summary + submissions list.
	 *
	 * @param int $competition_id Competition.
	 */
	private static function render_competition( $competition_id ) {
		global $wpdb;
		$runs = Nera_SAW_Database::table( 'runs' );

		$won   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(spins_confirmed),0) FROM {$runs} WHERE competition_id = %d AND status = 'finalized'", $competition_id ) );
		$games = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$runs} WHERE competition_id = %d", $competition_id ) );

		$pool      = Nera_SAW_Spin_Pool::get( $competition_id );
		$remaining = $pool ? (int) $pool->available : max( 0, Nera_SAW_Competition_Config::lfw_stock( $competition_id ) - $won );

		echo '<div class="saw-summary">';
		self::stat( __( 'Remaining tickets', 'nera-strikeawin' ), number_format_i18n( $remaining ), 'blue' );
		self::stat( __( 'Won tickets', 'nera-strikeawin' ), number_format_i18n( $won ), 'amber' );
		self::stat( __( 'Games run', 'nera-strikeawin' ), number_format_i18n( $games ) );
		echo '</div>';

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once NERA_SAW_PLUGIN_DIR . 'includes/admin/class-runs-list-table.php';
		$table = new Nera_SAW_Runs_List_Table( $competition_id );
		$table->prepare_items();

		echo '<div class="saw-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Quiz submissions', 'nera-strikeawin' ) . '</h2>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="hidden" name="competition" value="' . (int) $competition_id . '">';
		$table->filter_bar();
		$table->display();
		echo '</form>';
		echo '</div>';
		// The username autocomplete is (re)initialised by the picker script via
		// window.sawInitUserAutocomplete() — inline scripts here would not run when
		// this markup is injected via AJAX.
	}

	/**
	 * Submission detail view.
	 *
	 * @param int $competition_id Competition.
	 * @param int $run_id         Run.
	 */
	private static function render_detail( $competition_id, $run_id ) {
		global $wpdb;
		$runs_t  = Nera_SAW_Database::table( 'runs' );
		$slots_t = Nera_SAW_Database::table( 'run_slots' );

		$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$runs_t} WHERE id = %d AND competition_id = %d", $run_id, $competition_id ) );
		if ( ! $run ) {
			echo '<div class="saw-card"><p>' . esc_html__( 'Submission not found.', 'nera-strikeawin' ) . '</p></div>';
			return;
		}
		$slots = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$slots_t} WHERE run_id = %d ORDER BY slot_no ASC", $run_id ) );
		$slots = self::backfill_slot_numbers( $slots, $run );

		$answered   = 0;
		foreach ( $slots as $s ) {
			if ( null !== $s->chosen_text && '' !== (string) $s->chosen_text ) {
				$answered++;
			}
		}
		$total       = count( $slots );
		$unanswered  = max( 0, $total - $answered );
		$won         = 'finalized' === $run->status ? (int) $run->spins_confirmed : (int) self::sum_awarded( $slots );

		// Resolve the tier label from the run snapshot (fall back to global set).
		$tier_label = (string) $run->tier_key;
		$cfg        = $run->config_snapshot ? json_decode( $run->config_snapshot, true ) : array();
		$tier_src   = ( is_array( $cfg ) && ! empty( $cfg['tiers'] ) ) ? $cfg['tiers'] : Nera_SAW_Constants::global_tiers();
		foreach ( (array) $tier_src as $t ) {
			if ( (string) $t['key'] === (string) $run->tier_key ) {
				$tier_label = (string) $t['label'];
				break;
			}
		}

		$back = admin_url( 'admin.php?page=' . self::SLUG . '&competition=' . (int) $competition_id );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to submissions', 'nera-strikeawin' ) . '</a></p>';

		// Errored (stuck) run: offer Restore (refund the run + void it) — ADR 0013.
		if ( self::is_restorable( $run ) ) {
			echo '<div class="notice notice-warning inline" style="margin:0 0 14px"><p>';
			echo '<span class="saw-pill saw-pill--red" style="margin-right:8px">' . esc_html__( 'Errored', 'nera-strikeawin' ) . '</span>';
			echo esc_html__( 'This run got stuck mid-quiz through an error (not a deliberate leave). Restoring returns the run to the player and voids this stuck run.', 'nera-strikeawin' );
			echo ' <a class="button button-primary" style="margin-left:8px" href="' . esc_url( self::restore_url( $competition_id, $run_id ) ) . '"';
			echo ' onclick="return confirm(\'' . esc_js( __( 'Restore this run? The player gets their run back and this stuck run is voided.', 'nera-strikeawin' ) ) . '\');">';
			echo esc_html__( 'Restore run', 'nera-strikeawin' ) . '</a>';
			echo '</p></div>';
		} elseif ( 'voided' === (string) $run->status ) {
			echo '<div class="notice notice-info inline" style="margin:0 0 14px"><p>';
			echo '<span class="saw-pill saw-pill--expired" style="margin-right:8px">' . esc_html__( 'Voided', 'nera-strikeawin' ) . '</span>';
			echo esc_html__( 'This run was restored: the run was returned to the player and this record voided.', 'nera-strikeawin' );
			echo '</p></div>';
		}

		echo '<div class="saw-summary">';
		self::stat( __( 'Tickets won', 'nera-strikeawin' ), number_format_i18n( $won ), 'amber' );
		self::stat( __( 'Answered', 'nera-strikeawin' ), (int) $answered . ' / ' . (int) $total, 'green' );
		self::stat( __( 'Unanswered', 'nera-strikeawin' ), (int) $unanswered . ' / ' . (int) $total, 'red' );
		self::stat( __( 'Tier', 'nera-strikeawin' ), '' !== $tier_label ? $tier_label : '—', 'blue' );
		echo '</div>';

		echo '<div class="saw-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Questions', 'nera-strikeawin' ) . '</h2>';
		if ( empty( $slots ) ) {
			echo '<p class="saw-muted">' . esc_html__( 'No question records for this submission.', 'nera-strikeawin' ) . '</p>';
		}
		foreach ( $slots as $s ) {
			$snap    = $s->question_snapshot ? json_decode( $s->question_snapshot, true ) : array();
			$qtext   = ! empty( $snap['question_text'] ) ? $snap['question_text'] : ( '#' . (int) $s->question_id );
			$qid     = ! empty( $snap['question_id'] ) ? (int) $snap['question_id'] : (int) $s->question_id;
			$editlnk = get_edit_post_link( $qid );
			$numbers = self::decode_awarded_numbers( $s->awarded_numbers );
			$lvl_key = ! empty( $snap['level_key'] ) ? (string) $snap['level_key'] : (string) $s->level_key;
			$dot     = Nera_SAW_Constants::level_color( $lvl_key );

			echo '<div class="saw-qrow">';
			echo '<div class="saw-qrow__q"><span class="saw-dot" style="background:' . esc_attr( $dot ) . '"></span>' . (int) $s->slot_no . '. ';
			if ( $editlnk ) {
				echo '<a href="' . esc_url( $editlnk ) . '" target="_blank">' . esc_html( $qtext ) . '</a>';
			} else {
				echo esc_html( $qtext );
			}
			echo '</div>';

			// Player's answer.
			if ( null === $s->chosen_text || '' === (string) $s->chosen_text ) {
				// Distinguish a deliberate leave from a genuine timeout (ADR 0010).
				$outcome = isset( $s->outcome ) ? (string) $s->outcome : '';
				if ( 'abandoned' === $outcome ) {
					$none_label = __( 'Left the quiz (unanswered)', 'nera-strikeawin' );
				} elseif ( 'timeout' === $outcome ) {
					$none_label = __( 'No answer (timed out)', 'nera-strikeawin' );
				} else {
					$none_label = __( 'No answer (timed out / skipped)', 'nera-strikeawin' );
				}
				echo '<div class="saw-ans--none">' . esc_html( $none_label ) . '</div>';
			} else {
				$cls = $s->is_correct ? 'saw-ans--correct' : 'saw-ans--wrong';
				echo '<div class="' . esc_attr( $cls ) . '">' . esc_html( $s->chosen_text ) . ' ' . ( $s->is_correct ? '&#10003;' : '&#10007;' ) . '</div>';
			}
			if ( ! $s->is_correct && null !== $s->correct_text && '' !== (string) $s->correct_text ) {
				echo '<div class="saw-muted">' . esc_html__( 'Correct answer:', 'nera-strikeawin' ) . ' ' . esc_html( $s->correct_text ) . '</div>';
			}

			// Tickets won + LFW numbers as tags.
			if ( (int) $s->spins_awarded > 0 ) {
				echo '<div class="saw-slot-tickets">';
				echo '<div class="saw-slot-tickets__label">' . esc_html( sprintf( _n( '%d ticket earned', '%d tickets earned', (int) $s->spins_awarded, 'nera-strikeawin' ), (int) $s->spins_awarded ) ) . '</div>';
				if ( ! empty( $numbers ) ) {
					self::render_ticket_tags( $numbers );
				} else {
					echo '<p class="saw-muted" style="margin:0">' . esc_html__( 'Numbers pending mint.', 'nera-strikeawin' ) . '</p>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';

		self::render_run_log( $run_id );
	}

	/**
	 * Diagnostic log rows for a single run (lifecycle + any errors/stalls).
	 *
	 * @param int $run_id Run ID.
	 */
	private static function render_run_log( $run_id ) {
		if ( ! class_exists( 'Nera_SAW_Log' ) ) {
			return;
		}
		$rows = Nera_SAW_Log::for_run( (int) $run_id );
		if ( empty( $rows ) ) {
			return;
		}
		echo '<div class="saw-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Activity log', 'nera-strikeawin' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'nera-strikeawin' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'nera-strikeawin' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'nera-strikeawin' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$is_err = ( 'error' === $row->level );
			echo '<tr>';
			echo '<td>' . esc_html( mysql2date( 'Y-m-d H:i:s', $row->created_at ) ) . '</td>';
			echo '<td>' . esc_html( $row->event ) . '</td>';
			echo '<td' . ( $is_err ? ' style="color:#b32020"' : '' ) . '>' . esc_html( (string) $row->message );
			if ( ! empty( $row->context ) ) {
				echo '<br><code style="font-size:11px">' . esc_html( wp_strip_all_tags( (string) $row->context ) ) . '</code>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Decode a slot's awarded_numbers JSON column.
	 *
	 * @param string|null $raw JSON array of ticket numbers.
	 * @return string[]
	 */
	private static function decode_awarded_numbers( $raw ) {
		if ( ! $raw ) {
			return array();
		}
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $decoded ), 'strlen' ) );
	}

	/**
	 * Render LFW ticket numbers as tag tiles.
	 *
	 * @param string[] $numbers Ticket numbers.
	 */
	private static function render_ticket_tags( array $numbers ) {
		if ( empty( $numbers ) ) {
			return;
		}
		echo '<div class="saw-tags">';
		foreach ( $numbers as $n ) {
			echo '<span class="saw-tag">' . esc_html( $n ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Backfill missing per-slot numbers from the order's LFW ticket meta.
	 *
	 * Older demo seeds (or failed allocation) may have minted tickets on the
	 * order without awarded_numbers on each slot — the Report still needs to
	 * show the tag tiles.
	 *
	 * @param array  $slots Slot rows.
	 * @param object $run   Run row.
	 * @return array
	 */
	private static function backfill_slot_numbers( $slots, $run ) {
		$needs = false;
		foreach ( (array) $slots as $s ) {
			if ( (int) $s->spins_awarded > 0 && empty( self::decode_awarded_numbers( $s->awarded_numbers ) ) ) {
				$needs = true;
				break;
			}
		}
		if ( ! $needs ) {
			return $slots;
		}

		$order_nums = self::order_ticket_numbers( (int) $run->order_id, (int) $run->competition_id, (string) $run->tier_key );
		if ( empty( $order_nums ) ) {
			return $slots;
		}

		$cur   = 0;
		$total = count( $order_nums );
		foreach ( $slots as $s ) {
			$take = (int) $s->spins_awarded;
			if ( $take < 1 || ! empty( self::decode_awarded_numbers( $s->awarded_numbers ) ) ) {
				continue;
			}
			$chunk = array();
			for ( $i = 0; $i < $take && $cur < $total; $i++, $cur++ ) {
				$chunk[] = (string) $order_nums[ $cur ];
			}
			$s->awarded_numbers = wp_json_encode( $chunk );
		}
		return $slots;
	}

	/**
	 * Read minted LFW numbers from the competition line on an order.
	 *
	 * @param int    $order_id       Order ID.
	 * @param int    $competition_id Product ID.
	 * @param string $tier_key       Optional tier scope.
	 * @return string[]
	 */
	private static function order_ticket_numbers( $order_id, $competition_id, $tier_key = '' ) {
		if ( $order_id < 1 || $competition_id < 1 || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		if ( '' !== (string) $tier_key ) {
			return Nera_SAW_Run::ticket_numbers_for_order_tier( (int) $order_id, (int) $competition_id, (string) $tier_key );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_product_id() !== (int) $competition_id ) {
				continue;
			}
			$nums = (array) $item->get_meta( '_lty_lottery_tickets' );
			if ( empty( $nums ) ) {
				$nums = (array) $item->get_meta( 'lty_lottery_tickets' );
			}
			$nums = array_values( array_filter( array_map( 'strval', $nums ), 'strlen' ) );
			if ( ! empty( $nums ) ) {
				return $nums;
			}
		}

		// Fallback: read the authoritative ticket posts LFW created for this order.
		// The mint/confirm flow can clear the order-item meta, but every ticket
		// post keeps its originating order in `lty_order_id` and its number in
		// `lty_ticket_number`.
		return self::order_ticket_numbers_from_posts( $order_id );
	}

	/**
	 * Read minted ticket numbers straight from the LFW ticket posts for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string[]
	 */
	private static function order_ticket_numbers_from_posts( $order_id ) {
		global $wpdb;
		if ( (int) $order_id < 1 ) {
			return array();
		}
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT num.meta_value
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} oid ON oid.post_id = p.ID AND oid.meta_key = 'lty_order_id'
				 INNER JOIN {$wpdb->postmeta} num ON num.post_id = p.ID AND num.meta_key = 'lty_ticket_number'
				 WHERE p.post_type = 'lty_lottery_ticket' AND oid.meta_value = %s",
				(string) $order_id
			)
		);
		return array_values( array_filter( array_map( 'strval', (array) $rows ), 'strlen' ) );
	}

	/**
	 * Sum awarded tickets across slots.
	 *
	 * @param array $slots Slots.
	 * @return int
	 */
	private static function sum_awarded( $slots ) {
		$sum = 0;
		foreach ( (array) $slots as $s ) {
			$sum += (int) $s->spins_awarded;
		}
		return $sum;
	}

	/**
	 * Render a stat tile.
	 *
	 * @param string $label   Label.
	 * @param string $value   Value.
	 * @param string $variant Colour variant: amber|green|red|blue (optional).
	 */
	private static function stat( $label, $value, $variant = '' ) {
		$cls = 'saw-stat' . ( '' !== $variant ? ' saw-stat--' . sanitize_html_class( $variant ) : '' );
		echo '<div class="' . esc_attr( $cls ) . '"><div class="saw-stat__label">' . esc_html( $label ) . '</div><div class="saw-stat__value">' . esc_html( $value ) . '</div></div>';
	}
}
