<?php
/**
 * Plugin Name: Nera – Strike A Win
 * Plugin URI: https://github.com/Nera-Marketing/nera-strike-a-win-plugin
 * Description: Skill-based prize-competition quiz mechanic. Paid entry -> timed increasing-difficulty quiz -> earned LFW lottery tickets entered into the competition draw. Server-scored, no-oversell reservation pool, compliance-locked (Gambling Act 2005 skill exemption).
 * Version: 1.1.4
 * Author: Nera
 * Text Domain: nera-strikeawin
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5p5\Vcs\GitHubApi;

/**
 * Optional wp-config.php override:
 *
 *   define( 'NERA_SAW_DEMO_SEEDER', true ); — show Tools → Strike A Win Demo in the menu
 *
 * Frontend UI and quiz feedback are toggled on the demo admin page
 * (tools.php?page=nera-saw-demo → Feature flags). Note that page is dropped from
 * the Tools menu unless NERA_SAW_DEMO_SEEDER is defined, so on a production site
 * both switches are reachable only by typing that URL. The Answer reveal's hold
 * duration is separate, on Strike A Win → Settings → Answer reveal.
 */

define( 'NERA_SAW_VERSION', '1.1.4' );
define( 'NERA_SAW_PLUGIN_SLUG', 'nera-strike-a-win-plugin' );
define( 'NERA_SAW_PLUGIN_FILE', __FILE__ );
define( 'NERA_SAW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NERA_SAW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once NERA_SAW_PLUGIN_DIR . 'includes/class-plugin-path-migration.php';
Nera_SAW_Plugin_Path_Migration::maybe_migrate();

/**
 * GitHub updates (Plugin Update Checker v5.5). On by default when
 * `lib/plugin-update-checker/load-v5p5.php` exists.
 *
 * The plugin folder/slug is `nera-strike-a-win-plugin` (matches the GitHub repo
 * `nera-strike-a-win-plugin`). PUC's third argument MUST stay the folder slug.
 *
 * Disable only if the repo is missing or you are developing without GitHub:
 *   define( 'NERA_SAW_DISABLE_GITHUB_UPDATES', true );
 *
 * Private repo: `define( 'NERA_SAW_GITHUB_TOKEN', 'ghp_...' );`
 * Custom URL:   `define( 'NERA_SAW_GITHUB_REPO_URL', 'https://github.com/Owner/repo/' );` or filter `nera_saw_github_repo_url`.
 *
 * PUC reads the `Version` header in this file from the GitHub ref it selects (not the tag name alone). Bump `Version`
 * and `NERA_SAW_VERSION` for every release, then tag/push to match (release.sh does this).
 *
 * A custom `setReleaseFilter` callback (always true) plus `maxReleases` > 1 makes `GitHubApi` use the paginated
 * `/releases` endpoint instead of `/latest` (which 404s without a GitHub "latest" release). Pre-releases are skipped;
 * `enableReleaseAssets()` prefers the attached zip over the tag tarball.
 *
 * @link https://github.com/YahnisElsts/plugin-update-checker
 * @link https://github.com/Nera-Marketing/nera-strike-a-win-plugin/
 */
if ( ! defined( 'NERA_SAW_DISABLE_GITHUB_UPDATES' ) || ! NERA_SAW_DISABLE_GITHUB_UPDATES ) {
	$nera_saw_github_repo_default = 'https://github.com/Nera-Marketing/nera-strike-a-win-plugin/';
	if ( defined( 'NERA_SAW_GITHUB_REPO_URL' ) && is_string( NERA_SAW_GITHUB_REPO_URL ) && NERA_SAW_GITHUB_REPO_URL !== '' ) {
		$nera_saw_github_repo_default = NERA_SAW_GITHUB_REPO_URL;
	}
	$nera_saw_github_repo = apply_filters( 'nera_saw_github_repo_url', $nera_saw_github_repo_default );

	$nera_saw_puc_loader = NERA_SAW_PLUGIN_DIR . 'lib/plugin-update-checker/load-v5p5.php';
	if ( is_readable( $nera_saw_puc_loader ) ) {
		require_once $nera_saw_puc_loader;
		// Fourth argument: check period in hours (PUC default is 12).
		$nera_saw_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			$nera_saw_github_repo,
			__FILE__,
			NERA_SAW_PLUGIN_SLUG,
			6
		);
		$nera_saw_update_checker->setBranch( 'main' );

		if ( defined( 'NERA_SAW_GITHUB_TOKEN' ) && is_string( NERA_SAW_GITHUB_TOKEN ) && NERA_SAW_GITHUB_TOKEN !== '' ) {
			$nera_saw_update_checker->setAuthentication( NERA_SAW_GITHUB_TOKEN );
		}

		$nera_saw_puc_vcs = $nera_saw_update_checker->getVcsApi();
		if ( $nera_saw_puc_vcs instanceof GitHubApi ) {
			// Force paginated /releases (see docblock): custom filter + maxReleases > 1.
			$nera_saw_puc_vcs->setReleaseFilter(
				static function ( $version_number, $release_object ) {
					unset( $version_number, $release_object );
					return true;
				},
				\YahnisElsts\PluginUpdateChecker\v5p5\Vcs\Api::RELEASE_FILTER_SKIP_PRERELEASE,
				20
			);
			$nera_saw_puc_vcs->enableReleaseAssets();
		}
	}
}

require_once NERA_SAW_PLUGIN_DIR . 'includes/class-constants.php';
require_once NERA_SAW_PLUGIN_DIR . 'includes/class-mode.php';
require_once NERA_SAW_PLUGIN_DIR . 'includes/class-database.php';
require_once NERA_SAW_PLUGIN_DIR . 'includes/class-play-page.php';
require_once NERA_SAW_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Whether Strike A Win frontend UI overrides are active.
 *
 * @return bool
 */
function nera_saw_frontend_ui_enabled() {
	return Nera_SAW_Constants::frontend_ui_enabled();
}

/**
 * Whether the Tools → Strike A Win Demo admin page is registered.
 *
 * @return bool
 */
function nera_saw_demo_seeder_enabled() {
	return Nera_SAW_Constants::demo_seeder_enabled();
}

/**
 * Whether the quiz shows the Answer reveal — the held moment after a submit that
 * marks the correct option. Toggled at Settings → Answer reveal.
 *
 * @return bool
 */
function nera_saw_quiz_feedback_enabled() {
	return Nera_SAW_Constants::quiz_feedback_enabled();
}

/**
 * Activation: create/upgrade custom tables, seed defaults, ensure the play page.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Nera_SAW_Database::install();
		Nera_SAW_Constants::install_defaults();
		Nera_SAW_Play_Page::ensure_page();

		// Belt and braces: ensure_scheduled() also runs on `init`, which is what
		// actually reaches sites that update from GitHub without reactivating.
		// The class is loaded on plugins_loaded, which has not necessarily fired
		// for this plugin during its own activation request.
		if ( class_exists( 'Nera_SAW_Reservations' ) ) {
			Nera_SAW_Reservations::ensure_scheduled();
		}
	}
);

/**
 * Deactivation: drop the abandoned-run sweep.
 *
 * Without this a deactivated plugin leaves a cron event pointing at a hook nobody
 * answers — harmless, but it survives reinstalls and makes the schedule lie about
 * what the site does.
 */
register_deactivation_hook(
	__FILE__,
	static function () {
		if ( class_exists( 'Nera_SAW_Reservations' ) ) {
			Nera_SAW_Reservations::unschedule();
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded (WooCommerce + Spin-to-Win present).
 */
add_action(
	'plugins_loaded',
	static function () {
		Nera_SAW_Database::maybe_upgrade();
		Nera_SAW_Constants::install_defaults();
		Nera_SAW_Plugin::instance()->boot();
	},
	20
);
