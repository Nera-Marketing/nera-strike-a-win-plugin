<?php
/**
 * Strike A Win → Settings: global plugin configuration.
 *
 *  - Per-question timer WORKING bounds (min/max) the product field validates
 *    against, themselves fenced by the code-level hard clamp (ADR 0005).
 *  - The global Tier set (key, label, default price/multiplier/ceiling) shown on
 *    every competition's Strike A Win tab and overridable there (ADR 0004).
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Settings_Admin
 */
class Nera_SAW_Settings_Admin {

	const SLUG   = 'nera-saw-settings';
	const ACTION = 'nera_saw_save_settings';
	const NONCE  = 'nera_saw_settings_nonce';

	/**
	 * Hook menu + save handler + assets.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Submenu under the Strike A Win menu.
	 */
	public static function menu() {
		add_submenu_page(
			Nera_SAW_Ladder_Admin::SLUG,
			__( 'Settings', 'nera-strikeawin' ),
			__( 'Settings', 'nera-strikeawin' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Shared admin stylesheet on our settings page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'nera-saw-admin', NERA_SAW_PLUGIN_URL . 'assets/css/admin.css', array(), NERA_SAW_VERSION );
	}

	/**
	 * Persist settings.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'nera-strikeawin' ) );
		}
		check_admin_referer( self::NONCE );

		$strikeawin_method = Nera_SAW_Mode::sanitize_method( $_POST['strikeawin_method'] ?? '' );
		$quiz_method       = Nera_SAW_Mode::sanitize_quiz_method( $_POST['quiz_method'] ?? '' );
		$language_scope    = Nera_SAW_Mode::sanitize_language_scope( $_POST['language_scope'] ?? '' );
		$resume_policy     = Nera_SAW_Mode::sanitize_resume_policy( $_POST['resume_policy'] ?? '' );
		$policy_before     = Nera_SAW_Mode::resume_policy();

		$timer_min = isset( $_POST['timer_min'] ) ? (int) $_POST['timer_min'] : Nera_SAW_Constants::TIMER_MIN_SECONDS;
		$timer_max = isset( $_POST['timer_max'] ) ? (int) $_POST['timer_max'] : Nera_SAW_Constants::TIMER_MAX_SECONDS;
		$timer_warn = isset( $_POST['timer_warn_seconds'] ) ? (int) $_POST['timer_warn_seconds'] : 3;
		$timer_min = max( 1, $timer_min );
		$timer_max = max( 1, $timer_max );
		if ( $timer_max < $timer_min ) {
			$timer_max = $timer_min;
		}
		$timer_warn = max( 1, min( $timer_max, $timer_warn ) );

		$feedback_seconds = isset( $_POST['feedback_seconds'] ) ? (int) $_POST['feedback_seconds'] : Nera_SAW_Constants::FEEDBACK_SECONDS_DEFAULT;
		$feedback_seconds = max( Nera_SAW_Constants::FEEDBACK_SECONDS_MIN, min( Nera_SAW_Constants::FEEDBACK_SECONDS_MAX, $feedback_seconds ) );

		$tiers = array();
		foreach ( (array) ( $_POST['tier'] ?? array() ) as $row ) {
			$key = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
			if ( '' === $key ) {
				continue;
			}
			$tiers[] = array(
				'key'        => $key,
				'label'      => isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : ucfirst( $key ),
				'price'      => isset( $row['price'] ) ? (float) $row['price'] : 0,
				'multiplier' => isset( $row['multiplier'] ) ? max( 1, (int) $row['multiplier'] ) : 1,
				'ceiling'    => isset( $row['ceiling'] ) ? max( 0, (int) $row['ceiling'] ) : 0,
			);
		}
		if ( empty( $tiers ) ) {
			$tiers = Nera_SAW_Constants::default_settings()['tiers'];
		}

		update_option(
			Nera_SAW_Constants::OPTION_SETTINGS,
			array(
				// This is a whole-array write: every key the settings own must be
				// listed here or saving the page deletes it.
				'strikeawin_method'  => $strikeawin_method,
				'quiz_method'        => $quiz_method,
				'language_scope'     => $language_scope,
				'resume_policy'      => $resume_policy,
				'timer_min'          => $timer_min,
				'timer_max'          => $timer_max,
				'timer_warn_seconds' => $timer_warn,
				'feedback_seconds'   => $feedback_seconds,
				'tiers'              => $tiers,
			)
		);

		// No feature-flag write here: this form renders none of them. The Answer
		// reveal on/off switch is a feature flag and lives on the demo page.

		// Play page override (0 = revert to auto-created page).
		if ( isset( $_POST['play_page_id'] ) ) {
			Nera_SAW_Play_Page::set_page_id( (int) $_POST['play_page_id'] );
		}

		$args = array( 'page' => self::SLUG, 'saw_saved' => '1' );

		/*
		 * Switching to "close the run" settles whatever was already stuck, under the
		 * policy those runs were played under. Otherwise the backlog falls between
		 * the two: too old for the new policy to judge fairly, and invisible to the
		 * sweep because it has no clock.
		 */
		if ( Nera_SAW_Mode::RESUME_CLOSE === $resume_policy && Nera_SAW_Mode::RESUME_ALLOW === $policy_before ) {
			$result                = Nera_SAW_Run::settle_legacy_runs();
			$args['saw_settled']   = (int) $result['settled'];
			$args['saw_unsettled'] = (int) $result['remaining'];
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public static function render() {
		$s     = Nera_SAW_Constants::settings();
		$tiers = ! empty( $s['tiers'] ) ? array_values( $s['tiers'] ) : array();
		// No spare row: use the "Add tier" button to append rows.

		echo '<div class="wrap saw-admin">';
		echo '<h1 class="saw-admin__title">' . esc_html__( 'Strike A Win — Settings', 'nera-strikeawin' ) . '</h1>';

		if ( isset( $_GET['saw_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'nera-strikeawin' ) . '</p></div>';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['saw_settled'] ) ) {
			$settled   = (int) $_GET['saw_settled'];
			$unsettled = isset( $_GET['saw_unsettled'] ) ? (int) $_GET['saw_unsettled'] : 0;

			if ( $settled > 0 ) {
				printf(
					'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %d: number of runs */
							_n(
								'Settled %d run left over from the previous policy — its tickets have been issued and the run closed.',
								'Settled %d runs left over from the previous policy — their tickets have been issued and the runs closed.',
								$settled,
								'nera-strikeawin'
							),
							$settled
						)
					)
				);
			}
			if ( $unsettled > 0 ) {
				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %d: number of runs */
							__( '%d older run(s) still need settling — too many to finish in one save. Go to Strike A Win → Run Clock and back-fill the rest.', 'nera-strikeawin' ),
							$unsettled
						)
					)
				);
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		// --- Behaviour card -------------------------------------------------
		// First on the page because these three gate what the rest of the
		// settings even mean: the play page is a mix-mode concept, and the
		// stage-related copy elsewhere only applies under the ladder.
		echo '<div class="saw-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Behaviour', 'nera-strikeawin' ) . '</h2>';

		self::radio_field(
			'strikeawin_method',
			__( 'StrikeAWin Method', 'nera-strikeawin' ),
			__( 'Where competitions live. Mix keeps them in the main catalogue alongside every other product. Standalone gives the plugin its own section and removes its products from the main site.', 'nera-strikeawin' ),
			Nera_SAW_Mode::methods(),
			Nera_SAW_Mode::method()
		);

		self::radio_field(
			'quiz_method',
			__( 'Quiz Method', 'nera-strikeawin' ),
			__( 'How a run is ordered. Random mixes the difficulty levels across the run; Ladder walks them in stages, easy to hard. Individual competitions can override this on their Strike A Win tab. The front-end prototype was designed around Ladder — Random is the default so that upgrading changes nothing.', 'nera-strikeawin' ),
			Nera_SAW_Mode::quiz_methods(),
			Nera_SAW_Mode::quiz_method()
		);

		self::radio_field(
			'resume_policy',
			__( 'If a run is interrupted', 'nera-strikeawin' ),
			__( 'What happens when a player loses their connection or closes the browser mid-quiz and comes back. This does not cover the Leave button — using that and confirming it is a choice, and it ends the run either way.', 'nera-strikeawin' ),
			Nera_SAW_Mode::resume_policies(),
			Nera_SAW_Mode::resume_policy()
		);

		self::radio_field(
			'language_scope',
			__( 'Language Scope', 'nera-strikeawin' ),
			__( 'How far the language choice reaches. Questions only keeps the interface in one language and lets the player choose the language of the questions when a run starts. Whole standalone section also translates the section\'s own content.', 'nera-strikeawin' ),
			Nera_SAW_Mode::language_scopes(),
			Nera_SAW_Mode::language_scope()
		);

		echo '</div>';

		// --- Play page card ------------------------------------------------
		// Only in mix. Standalone serves the quiz from the plugin's own route, so the
		// card would be pointing at a page nothing loads -- and an administrator who
		// "fixed" it by picking a different page would see no change at all, which is
		// worse than the setting being absent.
		//
		// Hiding it does not clear it: the save above is guarded by isset(), so the
		// stored override survives a switch to standalone and comes back intact.
		if ( ! Nera_SAW_Mode::is_standalone() ) {
			$play_page_id = Nera_SAW_Play_Page::get_page_id();
			echo '<div class="saw-card">';
			echo '<h2 class="saw-card__head">' . esc_html__( 'Quiz play page', 'nera-strikeawin' ) . '</h2>';
			echo '<p class="saw-muted">' . esc_html__( 'The page that hosts the quiz. Auto-created on activation; override here if you want the quiz on a different page. The page must contain the [strikeawin_quiz] shortcode.', 'nera-strikeawin' ) . '</p>';
			echo '<div class="saw-field-row"><label class="saw-field"><span>' . esc_html__( 'Play page', 'nera-strikeawin' ) . '</span>';
			wp_dropdown_pages(
				array(
					'name'              => 'play_page_id',
					'selected'         => (int) $play_page_id,
					'show_option_none' => __( '— Auto (create/adopt) —', 'nera-strikeawin' ),
					'option_none_value' => '0',
				)
			);
			echo '</label>';
			if ( $play_page_id > 0 ) {
				echo '<span class="saw-field"><span>' . esc_html__( 'URL', 'nera-strikeawin' ) . '</span><a href="' . esc_url( get_permalink( $play_page_id ) ) . '" target="_blank" rel="noopener">' . esc_html( get_permalink( $play_page_id ) ) . '</a></span>';
			}
			echo '</div>';
			echo '</div>';
		}

		// --- Timer bounds card ---------------------------------------------
		echo '<div class="saw-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Per-question timer bounds', 'nera-strikeawin' ) . '</h2>';
		echo '<p class="saw-muted">' . esc_html__( 'The window each competition\'s per-question timer may be set within. Minimum is at least 1 second; maximum must be greater than or equal to the minimum.', 'nera-strikeawin' ) . '</p>';
		echo '<div class="saw-field-row">';
		printf(
			'<label class="saw-field"><span>%s</span><input type="number" name="timer_min" min="1" value="%d"></label>',
			esc_html__( 'Minimum (s)', 'nera-strikeawin' ),
			(int) $s['timer_min']
		);
		printf(
			'<label class="saw-field"><span>%s</span><input type="number" name="timer_max" min="1" value="%d"></label>',
			esc_html__( 'Maximum (s)', 'nera-strikeawin' ),
			(int) $s['timer_max']
		);
		printf(
			'<label class="saw-field"><span>%s</span><input type="number" name="timer_warn_seconds" min="1" max="%d" value="%d"></label>',
			esc_html__( 'Turn red below (s)', 'nera-strikeawin' ),
			(int) Nera_SAW_Constants::timer_max(),
			(int) Nera_SAW_Constants::timer_warn_seconds()
		);
		echo '</div>';
		echo '<p class="saw-muted">' . esc_html__( 'When a question has this many seconds or fewer left, the countdown bar and number turn red.', 'nera-strikeawin' ) . '</p>';
		echo '</div>';

		// --- Answer reveal card --------------------------------------------
		echo '<div class="saw-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Answer reveal', 'nera-strikeawin' ) . '</h2>';
		echo '<p class="saw-muted">' . esc_html__( 'After a player submits an answer, the question is held on screen with the correct option highlighted green, a wrong pick red, and the ticket total updated. The player can skip the wait with the Next button.', 'nera-strikeawin' ) . '</p>';
		// The on/off switch lives with the other feature flags on the demo page; this
		// card owns only the duration. If the reveal is not appearing, that switch is
		// the first thing to check.
		if ( ! Nera_SAW_Constants::quiz_feedback_enabled() ) {
			echo '<p class="saw-muted"><strong>' . esc_html__( 'Answer feedback is currently switched off, so this hold does nothing.', 'nera-strikeawin' ) . '</strong> ';
			printf(
				'<a href="%s">%s</a></p>',
				esc_url( admin_url( 'tools.php?page=' . Nera_SAW_Seeder_Admin::SLUG ) ),
				esc_html__( 'Turn it on under Feature flags →', 'nera-strikeawin' )
			);
		}
		echo '<div class="saw-field-row">';
		printf(
			'<label class="saw-field"><span>%s</span><input type="number" name="feedback_seconds" min="%d" max="%d" value="%d"></label>',
			esc_html__( 'Hold for (s)', 'nera-strikeawin' ),
			(int) Nera_SAW_Constants::FEEDBACK_SECONDS_MIN,
			(int) Nera_SAW_Constants::FEEDBACK_SECONDS_MAX,
			(int) Nera_SAW_Constants::feedback_seconds()
		);
		echo '</div>';
		echo '<p class="saw-muted">' . esc_html__( 'The next question\'s timer starts when that question is served, so this hold never eats into a player\'s answering time.', 'nera-strikeawin' ) . '</p>';
		echo '</div>';

		// --- Global tiers card ---------------------------------------------
		echo '<div class="saw-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Global tiers', 'nera-strikeawin' ) . '</h2>';
		echo '<p class="saw-muted">' . esc_html__( 'The entry tiers offered on every competition. Each competition can override price / multiplier / ceiling and enable or disable individual tiers on its Strike A Win tab. Multiplier scales tickets won, never chance. Ceiling = max tickets won per tier per competition (0 = no cap).', 'nera-strikeawin' ) . '</p>';
		echo '<table class="saw-table" id="saw-tiers-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Key', 'nera-strikeawin' ) . '</th>';
		echo '<th>' . esc_html__( 'Label', 'nera-strikeawin' ) . '</th>';
		echo '<th>' . esc_html__( 'Price', 'nera-strikeawin' ) . '</th>';
		echo '<th>' . esc_html__( 'Multiplier', 'nera-strikeawin' ) . '</th>';
		echo '<th>' . esc_html__( 'Ceiling', 'nera-strikeawin' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		foreach ( $tiers as $i => $tier ) {
			self::tier_row( (int) $i, $tier );
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="saw-add-tier">' . esc_html__( '+ Add tier', 'nera-strikeawin' ) . '</button></p>';
		echo '</div>';

		submit_button( __( 'Save settings', 'nera-strikeawin' ), 'primary saw-btn-primary' );
		echo '</form>';

		// Tier repeater JS.
		?>
		<script type="text/html" id="saw-tier-template">
			<?php self::tier_row( 0, array( 'key' => '', 'label' => '', 'price' => '', 'multiplier' => '', 'ceiling' => '' ), true ); ?>
		</script>
		<script>
		( function() {
			var body = document.querySelector( '#saw-tiers-table tbody' );
			var tpl  = document.getElementById( 'saw-tier-template' ).innerHTML;
			function reindex() {
				body.querySelectorAll( 'tr' ).forEach( function( tr, i ) {
					tr.querySelectorAll( '[data-name]' ).forEach( function( el ) {
						el.setAttribute( 'name', 'tier[' + i + '][' + el.getAttribute( 'data-name' ) + ']' );
					} );
				} );
			}
			document.getElementById( 'saw-add-tier' ).addEventListener( 'click', function() {
				var tr = document.createElement( 'tr' );
				tr.innerHTML = tpl.trim();
				body.appendChild( tr );
				reindex();
			} );
			body.addEventListener( 'click', function( e ) {
				if ( e.target.classList.contains( 'saw-remove-tier' ) ) {
					e.target.closest( 'tr' ).remove();
					reindex();
				}
			} );
			reindex();
		} )();
		</script>
		<?php
		echo '</div>';
	}

	/**
	 * Render a global-tier table row.
	 *
	 * @param int   $i    Row index.
	 * @param array $tier Tier row.
	 * @param bool  $tpl  Template mode.
	 */
	private static function tier_row( $i, $tier, $tpl = false ) {
		$n = function ( $field ) use ( $i, $tpl ) {
			return $tpl ? '' : sprintf( 'name="tier[%d][%s]"', (int) $i, $field );
		};
		echo '<tr>';
		printf( '<td><input type="text" data-name="key" %s value="%s"></td>', $n( 'key' ), esc_attr( $tier['key'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<td><input type="text" data-name="label" %s value="%s"></td>', $n( 'label' ), esc_attr( $tier['label'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<td><input type="number" step="0.01" min="0" data-name="price" %s value="%s" style="width:90px"></td>', $n( 'price' ), esc_attr( $tier['price'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<td><input type="number" min="1" data-name="multiplier" %s value="%s" style="width:80px"></td>', $n( 'multiplier' ), esc_attr( $tier['multiplier'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<td><input type="number" min="0" data-name="ceiling" %s value="%s" style="width:80px"></td>', $n( 'ceiling' ), esc_attr( $tier['ceiling'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td><button type="button" class="button-link saw-remove-tier" title="' . esc_attr__( 'Remove', 'nera-strikeawin' ) . '">&times;</button></td>';
		echo '</tr>';
	}

	/**
	 * A labelled set of radios for one of the behaviour settings.
	 *
	 * Radios rather than a select: there are only ever two choices and each one
	 * needs its own sentence. A select hides the option not currently chosen,
	 * which is exactly the one an administrator is trying to understand.
	 *
	 * @param string $name     Field name.
	 * @param string $label    Field label.
	 * @param string $help     Explanatory sentence shown under the label.
	 * @param array  $choices  key => label.
	 * @param string $selected Currently resolved value.
	 */
	private static function radio_field( $name, $label, $help, array $choices, $selected ) {
		echo '<div class="saw-field-block">';
		echo '<p class="saw-field-block__label"><strong>' . esc_html( $label ) . '</strong></p>';
		echo '<p class="saw-muted">' . esc_html( $help ) . '</p>';
		foreach ( $choices as $value => $choice_label ) {
			printf(
				'<label class="saw-radio"><input type="radio" name="%1$s" value="%2$s"%3$s> <span>%4$s</span></label>',
				esc_attr( $name ),
				esc_attr( $value ),
				checked( (string) $selected, (string) $value, false ),
				esc_html( $choice_label )
			);
		}
		echo '</div>';
	}
}
