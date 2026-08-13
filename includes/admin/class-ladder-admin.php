<?php
/**
 * Strike A Win → Difficulty Ladder: manage the global levels.
 *
 * Each level has a display colour (picker, player-facing — see level_text_color),
 * a label, and default tickets per
 * correct answer. The level 'key' is a stable internal identifier — auto-
 * generated from the label on add and hidden from the admin. Levels are add /
 * remove-able; removing a level is a SOFT delete (retained, flagged) and prompts
 * the admin to reassign its questions to another level or trash them. The
 * compliance floor (Nera_SAW_Constants::DIFFICULTY_FLOOR_RANK) is not editable.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Ladder_Admin
 */
class Nera_SAW_Ladder_Admin {

	const SLUG        = 'nera-strikeawin';
	const ACTION      = 'nera_saw_save_ladder';
	const NONCE       = 'nera_saw_ladder_nonce';
	const AJAX_USAGE  = 'nera_saw_level_usage';
	const AJAX_NONCE  = 'nera_saw_ladder_ajax';
	const DISPOSE_DEL = '__delete__';

	/**
	 * Hook menu + handlers.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_' . self::AJAX_USAGE, array( __CLASS__, 'ajax_usage' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Top-level Strike A Win menu + Difficulty Ladder page.
	 */
	public static function menu() {
		add_menu_page(
			__( 'Strike A Win', 'nera-strikeawin' ),
			__( 'Strike A Win', 'nera-strikeawin' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-forms',
			56
		);
		add_submenu_page(
			self::SLUG,
			__( 'Difficulty Ladder', 'nera-strikeawin' ),
			__( 'Difficulty Ladder', 'nera-strikeawin' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Colour picker + admin styles on the ladder page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_style( 'nera-saw-admin', NERA_SAW_PLUGIN_URL . 'assets/css/admin.css', array(), NERA_SAW_VERSION );
	}

	/**
	 * AJAX: how many questions + competitions use a level (for the remove dialog).
	 */
	public static function ajax_usage() {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		wp_send_json_success(
			array(
				'questions'    => self::count_questions_for_level( $key ),
				'competitions' => self::count_competitions_for_level( $key ),
			)
		);
	}

	/**
	 * Persist the edited ladder (incl. soft-deletes + question reassignment).
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'nera-strikeawin' ) );
		}
		check_admin_referer( self::NONCE );

		$existing = array();
		foreach ( Nera_SAW_Constants::ladder( true ) as $lv ) {
			$existing[ $lv['key'] ] = $lv;
		}

		$rows      = isset( $_POST['level'] ) ? (array) wp_unslash( $_POST['level'] ) : array(); // phpcs:ignore
		$result    = array();
		$seen_keys = array();
		$rank      = 0;

		foreach ( $rows as $row ) {
			$label  = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			$color  = isset( $row['color'] ) ? self::sanitize_hex( $row['color'] ) : '';
			$reward = isset( $row['reward'] ) ? max( 0, (int) $row['reward'] ) : 0;
			$key    = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
			$remove = ! empty( $row['remove'] );

			if ( '' === $key ) {
				if ( '' === $label ) {
					continue; // blank row.
				}
				$key = self::unique_key( $label, $seen_keys );
			}
			$seen_keys[ $key ] = true;

			if ( $remove ) {
				// Reassign or trash this level's questions, then soft-delete it.
				$disposition = isset( $row['disposition'] ) ? sanitize_text_field( $row['disposition'] ) : self::DISPOSE_DEL;
				self::dispose_questions( $key, $disposition );
				$result[] = array(
					'key'     => $key,
					'label'   => $label ?: ( $existing[ $key ]['label'] ?? ucfirst( $key ) ),
					'rank'    => isset( $existing[ $key ]['rank'] ) ? (int) $existing[ $key ]['rank'] : ( ++$rank + 900 ),
					'reward'  => $reward,
					'color'   => $color ?: ( $existing[ $key ]['color'] ?? '' ),
					'deleted' => true,
				);
				continue;
			}

			$result[] = array(
				'key'     => $key,
				'label'   => $label ?: ucfirst( $key ),
				'rank'    => ++$rank,
				'reward'  => $reward,
				'color'   => $color,
				'deleted' => false,
			);
		}

		// Retain any previously soft-deleted levels not present in the form.
		foreach ( $existing as $key => $lv ) {
			if ( ! empty( $lv['deleted'] ) && ! isset( $seen_keys[ $key ] ) ) {
				$result[] = $lv;
			}
		}

		if ( ! empty( $result ) ) {
			update_option( Nera_SAW_Constants::OPTION_LADDER, $result );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saw_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Reassign a level's questions to another level, or trash them.
	 *
	 * @param string $level_key   Level being removed.
	 * @param string $disposition Target level key, or self::DISPOSE_DEL.
	 */
	private static function dispose_questions( $level_key, $disposition ) {
		$ids = get_posts(
			array(
				'post_type'      => Nera_SAW_Question_CPT::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => Nera_SAW_Question_CPT::META_LEVEL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $level_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $ids as $id ) {
			if ( self::DISPOSE_DEL === $disposition ) {
				wp_trash_post( (int) $id ); // Soft-delete (never hard delete).
			} else {
				update_post_meta( (int) $id, Nera_SAW_Question_CPT::META_LEVEL, sanitize_key( $disposition ) );
			}
		}
	}

	/**
	 * Render the ladder editor.
	 */
	public static function render() {
		$ladder = Nera_SAW_Constants::ladder(); // active only.

		echo '<div class="wrap saw-admin">';
		echo '<h1 class="saw-admin__title">' . esc_html__( 'Strike A Win — Difficulty Ladder', 'nera-strikeawin' ) . '</h1>';

		if ( isset( $_GET['saw_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ladder saved.', 'nera-strikeawin' ) . '</p></div>';
		}

		echo '<div class="saw-card">';
		echo '<p class="saw-muted">' . esc_html(
			sprintf(
				/* translators: %1$d floor rank, %2$d min, %3$d max timer */
				__( 'Levels run easy → hard by row order. Colour shows as a circle in the question bank AND colours the question text players see during the quiz (darkened automatically if needed, so a pale colour never becomes unreadable). Tickets = default tickets per correct answer (before the tier multiplier). Compliance floor rank: %1$d. Timer window: %2$d–%3$ds.', 'nera-strikeawin' ),
				Nera_SAW_Constants::DIFFICULTY_FLOOR_RANK,
				Nera_SAW_Constants::timer_min(),
				Nera_SAW_Constants::timer_max()
			)
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="saw-ladder-form">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		echo '<table class="saw-table" id="saw-ladder-table"><thead><tr>';
		echo '<th style="width:52px">' . esc_html__( 'Order', 'nera-strikeawin' ) . '</th>';
		echo '<th style="width:90px">' . esc_html__( 'Colour', 'nera-strikeawin' ) . '</th>';
		echo '<th>' . esc_html__( 'Label', 'nera-strikeawin' ) . '</th>';
		echo '<th style="width:110px">' . esc_html__( 'Tickets', 'nera-strikeawin' ) . '</th>';
		echo '<th style="width:60px"></th>';
		echo '</tr></thead><tbody>';
		foreach ( $ladder as $i => $level ) {
			self::level_row( (int) $i, $level );
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="saw-add-level">' . esc_html__( '+ Add level', 'nera-strikeawin' ) . '</button></p>';
		submit_button( __( 'Save ladder', 'nera-strikeawin' ), 'primary saw-btn-primary' );
		echo '</form>';
		echo '</div>';

		self::modal();
		self::script();
		echo '</div>';
	}

	/**
	 * Render a ladder row.
	 *
	 * @param int   $i     Index.
	 * @param array $level Level.
	 * @param bool  $tpl   Template mode.
	 */
	private static function level_row( $i, $level, $tpl = false ) {
		$key    = $tpl ? '' : (string) $level['key'];
		$label  = $tpl ? '' : (string) $level['label'];
		$reward = $tpl ? 0 : (int) $level['reward'];
		$color  = $tpl ? '#2e7d32' : ( ! empty( $level['color'] ) ? $level['color'] : Nera_SAW_Constants::level_color( $key ) );
		$n      = function ( $field ) use ( $i, $tpl ) {
			return $tpl ? '' : sprintf( 'name="level[%d][%s]"', (int) $i, $field );
		};

		echo '<tr class="saw-level-row" data-key="' . esc_attr( $key ) . '">';
		echo '<td class="saw-order-cell">' . ( $tpl ? '' : esc_html( $i + 1 ) ) . '</td>';
		printf( '<td><input type="text" class="saw-color" data-name="color" %s value="%s"></td>', $n( 'color' ), esc_attr( $color ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<td><input type="text" class="saw-label regular-text" data-name="label" %s value="%s" placeholder="%s"></td>', $n( 'label' ), esc_attr( $label ), esc_attr__( 'e.g. Moderate', 'nera-strikeawin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<td><input type="number" min="0" data-name="reward" %s value="%d" style="width:90px"></td>', $n( 'reward' ), (int) $reward ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<td><input type="hidden" class="saw-key" data-name="key" %s value="%s"><button type="button" class="button-link saw-remove-level" title="%s">&times;</button></td>', $n( 'key' ), esc_attr( $key ), esc_attr__( 'Remove level', 'nera-strikeawin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</tr>';
	}

	/**
	 * The remove-confirmation modal markup.
	 */
	private static function modal() {
		?>
		<div id="saw-remove-modal" class="saw-modal" style="display:none">
			<div class="saw-modal__backdrop"></div>
			<div class="saw-modal__box">
				<h2 class="saw-modal__title"><?php esc_html_e( 'Remove this level?', 'nera-strikeawin' ); ?></h2>
				<p class="saw-modal__body"></p>
				<p class="saw-modal__reassign">
					<label><?php esc_html_e( 'Do this with its questions:', 'nera-strikeawin' ); ?><br>
					<select id="saw-reassign-select" style="min-width:260px;margin-top:6px"></select></label>
				</p>
				<p class="saw-muted"><?php esc_html_e( 'Removal is a soft-delete: the level and its history are retained for tracing, but it is hidden from new questions and competitions.', 'nera-strikeawin' ); ?></p>
				<div class="saw-modal__actions">
					<button type="button" class="button" id="saw-modal-cancel"><?php esc_html_e( 'Cancel', 'nera-strikeawin' ); ?></button>
					<button type="button" class="button button-primary saw-btn-primary" id="saw-modal-confirm"><?php esc_html_e( 'Remove level', 'nera-strikeawin' ); ?></button>
				</div>
			</div>
		</div>
		<style>
			.saw-modal { position: fixed; inset: 0; z-index: 100000; }
			.saw-modal__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,.5); }
			.saw-modal__box { position: relative; max-width: 480px; margin: 10vh auto; background: #fff; border-radius: 12px; padding: 22px 24px; box-shadow: 0 10px 40px rgba(0,0,0,.3); }
			.saw-modal__title { margin: 0 0 10px; }
			.saw-modal__actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
			.saw-level-row.is-removing { opacity: .45; }
		</style>
		<?php
	}

	/**
	 * The ladder editor behaviour (repeater + colour picker + remove modal).
	 */
	private static function script() {
		$ajax_nonce = wp_create_nonce( self::AJAX_NONCE );
		?>
		<script type="text/html" id="saw-level-template"><?php self::level_row( 0, array(), true ); ?></script>
		<script>
		// DOM-ready so wp-color-picker (a footer script) is loaded before init.
		jQuery( function( $ ) {
			var ajaxNonce = '<?php echo esc_js( $ajax_nonce ); ?>';
			var DISPOSE_DEL = '<?php echo esc_js( self::DISPOSE_DEL ); ?>';
			var $table = $( '#saw-ladder-table tbody' );
			var pendingRow = null;

			function initColor( ctx ) {
				$( ctx ).find( '.saw-color' ).wpColorPicker();
			}
			function reindex() {
				$table.children( 'tr' ).each( function( i ) {
					$( this ).find( '[data-name]' ).each( function() {
						$( this ).attr( 'name', 'level[' + i + '][' + $( this ).data( 'name' ) + ']' );
					} );
					if ( ! $( this ).hasClass( 'is-removing' ) ) {
						$( this ).find( '.saw-order-cell' ).text( i + 1 );
					}
				} );
			}
			function activeLevels( exceptKey ) {
				var out = [];
				$table.children( 'tr' ).each( function() {
					if ( $( this ).hasClass( 'is-removing' ) ) { return; }
					var key = $( this ).find( '.saw-key' ).val();
					var label = $( this ).find( '.saw-label' ).val() || key;
					if ( key && key !== exceptKey ) { out.push( { key: key, label: label } ); }
				} );
				return out;
			}

			$( '#saw-add-level' ).on( 'click', function() {
				var html = $( '#saw-level-template' ).html();
				var $tr = $( html.trim() );
				$table.append( $tr );
				initColor( $tr );
				reindex();
			} );

			$table.on( 'click', '.saw-remove-level', function() {
				var $row = $( this ).closest( 'tr' );
				var key = $row.find( '.saw-key' ).val();
				pendingRow = $row;
				if ( ! key ) { // unsaved new row — just drop it.
					$row.remove();
					reindex();
					pendingRow = null;
					return;
				}
				openModal( key );
			} );

			function openModal( key ) {
				var $sel = $( '#saw-reassign-select' ).empty();
				activeLevels( key ).forEach( function( lv ) {
					$sel.append( $( '<option>' ).val( lv.key ).text( '<?php echo esc_js( __( 'Reassign questions to: ', 'nera-strikeawin' ) ); ?>' + lv.label ) );
				} );
				$sel.append( $( '<option>' ).val( DISPOSE_DEL ).text( '<?php echo esc_js( __( 'Delete (trash) the questions', 'nera-strikeawin' ) ); ?>' ) );
				$( '#saw-remove-modal .saw-modal__body' ).text( '<?php echo esc_js( __( 'Loading usage…', 'nera-strikeawin' ) ); ?>' );
				$( '#saw-remove-modal' ).show();

				$.post( ajaxurl, { action: '<?php echo esc_js( self::AJAX_USAGE ); ?>', nonce: ajaxNonce, key: key }, function( res ) {
					if ( res && res.success ) {
						var d = res.data;
						var msg = d.questions + ' <?php echo esc_js( __( 'question(s) are assigned to this level, used by', 'nera-strikeawin' ) ); ?> ' + d.competitions + ' <?php echo esc_js( __( 'competition(s). Those competitions will drop their slots for this level.', 'nera-strikeawin' ) ); ?>';
						$( '#saw-remove-modal .saw-modal__body' ).text( msg );
					}
				} );
			}

			$( '#saw-modal-cancel' ).on( 'click', function() {
				$( '#saw-remove-modal' ).hide();
				pendingRow = null;
			} );

			$( '#saw-modal-confirm' ).on( 'click', function() {
				if ( ! pendingRow ) { return; }
				var disposition = $( '#saw-reassign-select' ).val();
				pendingRow.addClass( 'is-removing' );
				pendingRow.find( '.saw-remove-level' ).prop( 'disabled', true );
				var idx = pendingRow.index();
				$( '<input>' ).attr( { type: 'hidden', name: 'level[' + idx + '][remove]', value: '1' } ).appendTo( pendingRow.find( 'td:last' ) );
				$( '<input>' ).attr( { type: 'hidden', name: 'level[' + idx + '][disposition]', value: disposition } ).appendTo( pendingRow.find( 'td:last' ) );
				$( '#saw-remove-modal' ).hide();
				pendingRow = null;
			} );

			initColor( document );
		} );
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Sanitise a hex colour (falls back to empty).
	 *
	 * @param string $value Colour.
	 * @return string
	 */
	private static function sanitize_hex( $value ) {
		$value = sanitize_text_field( $value );
		return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? $value : '';
	}

	/**
	 * Generate a unique level key from a label.
	 *
	 * @param string $label Label.
	 * @param array  $taken Already-used keys (assoc).
	 * @return string
	 */
	private static function unique_key( $label, array $taken ) {
		$base = sanitize_key( $label );
		if ( '' === $base ) {
			$base = 'level';
		}
		$key = $base;
		$i   = 2;
		while ( isset( $taken[ $key ] ) || null !== Nera_SAW_Constants::level( $key ) ) {
			$key = $base . '-' . $i;
			$i++;
		}
		return $key;
	}

	/**
	 * Count questions assigned to a level (any status).
	 *
	 * @param string $key Level key.
	 * @return int
	 */
	private static function count_questions_for_level( $key ) {
		$q = new WP_Query(
			array(
				'post_type'      => Nera_SAW_Question_CPT::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
				'meta_query'     => array( array( 'key' => Nera_SAW_Question_CPT::META_LEVEL, 'value' => $key ) ), // phpcs:ignore
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * Count competitions whose distribution allocates slots to a level.
	 *
	 * @param string $key Level key.
	 * @return int
	 */
	private static function count_competitions_for_level( $key ) {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => Nera_SAW_Competition_Config::META_IS_COMP, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$count = 0;
		foreach ( $ids as $id ) {
			$cfg = get_post_meta( (int) $id, Nera_SAW_Competition_Config::META_KEY, true );
			if ( is_array( $cfg ) && ! empty( $cfg['distribution'][ $key ] ) ) {
				$count++;
			}
		}
		return $count;
	}
}
