<?php
/**
 * Plugin bootstrap / module loader.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Plugin
 */
class Nera_SAW_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Nera_SAW_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the singleton.
	 *
	 * @return Nera_SAW_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Require module files and register hooks.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
			return;
		}

		$dir = NERA_SAW_PLUGIN_DIR . 'includes/';

		require_once $dir . 'class-log.php';
		require_once $dir . 'class-competition-config.php';
		require_once $dir . 'class-competition-spec.php';
		require_once $dir . 'class-draw-prizes.php';
		require_once $dir . 'class-catalogue-isolation.php';
		require_once $dir . 'class-router.php';
		require_once $dir . 'class-language.php';
		require_once $dir . 'class-date.php';
		require_once $dir . 'class-i18n.php';
		require_once $dir . 'class-language-reach.php';
		require_once $dir . 'class-language-switcher.php';
		require_once $dir . 'class-standalone-pages.php';
		require_once $dir . 'class-standalone-fields.php';
		require_once $dir . 'class-standalone-chrome.php';
		require_once $dir . 'class-standalone-basket.php';
		require_once $dir . 'class-standalone-account.php';
		require_once $dir . 'class-account-pages.php';
		require_once $dir . 'class-standalone-result-screen.php';
		require_once $dir . 'class-spin-pool.php';
		require_once $dir . 'class-reservations.php';
		require_once $dir . 'class-run-grants.php';
		require_once $dir . 'class-question-cpt.php';
		require_once $dir . 'class-question-bank.php';
		require_once $dir . 'class-question-import.php';

		// Question CPT (translatable) — register in all contexts.
		Nera_SAW_Question_CPT::init();
		require_once $dir . 'class-ticket-award.php';
		require_once $dir . 'class-run.php';
		require_once $dir . 'class-rest.php';
		require_once $dir . 'class-frontend.php';
		require_once $dir . 'class-product-frontend.php';
		require_once $dir . 'class-cart-entry.php';
		require_once $dir . 'class-order-cta.php';
		require_once $dir . 'class-integrations.php';
		require_once $dir . 'class-seed-image.php';
		require_once $dir . 'class-seeder.php';

		if ( is_admin() ) {
			require_once $dir . 'admin/class-ladder-admin.php';
			require_once $dir . 'admin/class-settings-admin.php';
			require_once $dir . 'admin/class-competition-admin.php';
			require_once $dir . 'admin/class-seeder-admin.php';
			require_once $dir . 'admin/class-report-admin.php';
			require_once $dir . 'admin/class-log-admin.php';
			require_once $dir . 'admin/class-question-import-admin.php';
			require_once $dir . 'admin/class-run-clock-admin.php';
			Nera_SAW_Ladder_Admin::init();
			Nera_SAW_Settings_Admin::init();
			Nera_SAW_Competition_Admin::init();
			Nera_SAW_Draw_Prizes::init();
			Nera_SAW_Seeder_Admin::init();
			Nera_SAW_Report_Admin::init();
			Nera_SAW_Log_Admin::init();
			Nera_SAW_Question_Import_Admin::init();
			Nera_SAW_Run_Clock_Admin::init();
			// Question CRUD is now the native CPT editor (class-question-admin.php retired).
		}

		// Ensure the quiz play page exists on already-active installs (activation
		// won't re-run). Cheap no-op once the option is set.
		if ( is_admin() ) {
			add_action( 'admin_init', array( 'Nera_SAW_Play_Page', 'ensure_page' ) );
		}

		// Diagnostic quiz log (daily prune).
		Nera_SAW_Log::init();

		// Reservation lifecycle bound to the standard WooCommerce order flow.
		Nera_SAW_Reservations::init();

		// Purchase → per-tier run balance (grant + reserve at payment; ADR 0006).
		Nera_SAW_Run_Grants::init();

		// REST API for the quiz run.
		Nera_SAW_Rest::init();

		// Front-end quiz surface (shortcode + assets).
		Nera_SAW_Frontend::init();

		// Single-product tier tabs + runs pill (competition pages).
		Nera_SAW_Product_Frontend::init();

		// Fixed-price tier entry (cart) + platform integrations (STW/LFW suppression).
		Nera_SAW_Cart_Entry::init();
		Nera_SAW_Order_Cta::init();
		Nera_SAW_Integrations::init();
		Nera_SAW_Catalogue_Isolation::init();
		Nera_SAW_Router::init();
		Nera_SAW_Standalone_Pages::init();
		Nera_SAW_Standalone_Fields::init();
		Nera_SAW_Language::init();
		Nera_SAW_Language_Reach::init();
		Nera_SAW_Language_Switcher::init();
		Nera_SAW_Standalone_Chrome::init();
		Nera_SAW_Standalone_Basket::init();
		Nera_SAW_Standalone_Account::init();
		Nera_SAW_Account_Pages::init();
		Nera_SAW_Standalone_Result_Screen::init();
		Nera_SAW_Ticket_Award::init();

		// Finalize abandoned runs on the reservation sweep tick.
		add_action( Nera_SAW_Reservations::CRON_HOOK, array( 'Nera_SAW_Run', 'finalize_stale' ) );
	}

	/**
	 * Admin notice when WooCommerce is inactive.
	 */
	public function notice_missing_woocommerce() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Nera Strike A Win requires WooCommerce to be active.', 'nera-strikeawin' );
		echo '</p></div>';
	}
}
