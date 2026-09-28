<?php
/**
 * Strike A Win → Quiz Log.
 *
 * Admin view over the diagnostic log (wp_nera_saw_log): run lifecycle + every
 * server/client error and stall. Search + filter by type, tier and competition,
 * newest first. Turns "a run locked and I don't know why" into an inspectable
 * trail.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Log_Admin
 */
class Nera_SAW_Log_Admin {

	const SLUG = 'nera-saw-log';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Submenu under Strike A Win.
	 */
	public static function menu() {
		add_submenu_page(
			Nera_SAW_Ladder_Admin::SLUG,
			__( 'Quiz Log', 'nera-strikeawin' ),
			__( 'Quiz Log', 'nera-strikeawin' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Admin styles on the log page.
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
	 * Render the page: filter bar + list table.
	 */
	public static function render() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once NERA_SAW_PLUGIN_DIR . 'includes/admin/class-log-list-table.php';

		echo '<div class="wrap saw-admin">';
		echo '<h1 class="saw-admin__title">' . esc_html__( 'Strike A Win — Quiz Log', 'nera-strikeawin' ) . '</h1>';
		echo '<p class="saw-muted">' . esc_html__( 'Diagnostic trail of quiz runs: starts, answers, completions, abandonments, and every error or stalled request (server and browser). Use it to trace a run that locked or failed.', 'nera-strikeawin' ) . '</p>';

		$table = new Nera_SAW_Log_List_Table();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		$table->filter_bar();
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Event type => human label.
	 *
	 * @return array
	 */
	public static function type_labels() {
		return array(
			'run_start'    => __( 'Run started', 'nera-strikeawin' ),
			'answer'       => __( 'Answer', 'nera-strikeawin' ),
			'run_complete' => __( 'Run complete', 'nera-strikeawin' ),
			'run_abandon'  => __( 'Run abandoned', 'nera-strikeawin' ),
			'run_restored' => __( 'Run restored', 'nera-strikeawin' ),
			'run_finalize_race_avoided' => __( 'Finalize race avoided', 'nera-strikeawin' ),
			'error'        => __( 'Server error', 'nera-strikeawin' ),
			'client_error' => __( 'Client error', 'nera-strikeawin' ),
			'client_stall' => __( 'Client stall', 'nera-strikeawin' ),
		);
	}
}
