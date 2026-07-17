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

		$hard_min = Nera_SAW_Constants::HARD_TIMER_MIN_SECONDS;
		$hard_max = Nera_SAW_Constants::HARD_TIMER_MAX_SECONDS;

		$timer_min = isset( $_POST['timer_min'] ) ? (int) $_POST['timer_min'] : Nera_SAW_Constants::TIMER_MIN_SECONDS;
		$timer_max = isset( $_POST['timer_max'] ) ? (int) $_POST['timer_max'] : Nera_SAW_Constants::TIMER_MAX_SECONDS;
		$timer_warn = isset( $_POST['timer_warn_seconds'] ) ? (int) $_POST['timer_warn_seconds'] : 3;
		$timer_min = max( $hard_min, min( $hard_max, $timer_min ) );
		$timer_max = max( $hard_min, min( $hard_max, $timer_max ) );
		if ( $timer_max < $timer_min ) {
			$timer_max = $timer_min;
		}
		$timer_warn = max( 1, min( $timer_max, $timer_warn ) );

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
				'timer_min'          => $timer_min,
				'timer_max'          => $timer_max,
				'timer_warn_seconds' => $timer_warn,
				'tiers'              => $tiers,
			)
		);

		// Play page override (0 = revert to auto-created page).
		if ( isset( $_POST['play_page_id'] ) ) {
			Nera_SAW_Play_Page::set_page_id( (int) $_POST['play_page_id'] );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saw_saved' => '1' ), admin_url( 'admin.php' ) ) );
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

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		// --- Play page card ------------------------------------------------
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

		// --- Timer bounds card ---------------------------------------------
		echo '<div class="saw-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Per-question timer bounds', 'nera-strikeawin' ) . '</h2>';
		echo '<p class="saw-muted">' . esc_html(
			sprintf(
				/* translators: %1$d hard min, %2$d hard max */
				__( 'The window each competition\'s per-question timer may be set within. Fenced by the fixed safety clamp %1$d–%2$ds.', 'nera-strikeawin' ),
				Nera_SAW_Constants::HARD_TIMER_MIN_SECONDS,
				Nera_SAW_Constants::HARD_TIMER_MAX_SECONDS
			)
		) . '</p>';
		echo '<div class="saw-field-row">';
		printf(
			'<label class="saw-field"><span>%s</span><input type="number" name="timer_min" min="%d" max="%d" value="%d"></label>',
			esc_html__( 'Minimum (s)', 'nera-strikeawin' ),
			(int) Nera_SAW_Constants::HARD_TIMER_MIN_SECONDS,
			(int) Nera_SAW_Constants::HARD_TIMER_MAX_SECONDS,
			(int) $s['timer_min']
		);
		printf(
			'<label class="saw-field"><span>%s</span><input type="number" name="timer_max" min="%d" max="%d" value="%d"></label>',
			esc_html__( 'Maximum (s)', 'nera-strikeawin' ),
			(int) Nera_SAW_Constants::HARD_TIMER_MIN_SECONDS,
			(int) Nera_SAW_Constants::HARD_TIMER_MAX_SECONDS,
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
}
