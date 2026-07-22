<?php
/**
 * One-time migration when the plugin directory slug changes.
 *
 * WordPress stores the bootstrap path in active_plugins (e.g.
 * nera-strikeawin/nera-strikeawin.php). Renaming the folder without updating
 * that option deactivates the plugin even though all nera_saw_* settings and
 * custom tables are unchanged.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Plugin_Path_Migration
 */
class Nera_SAW_Plugin_Path_Migration {

	const LEGACY_SLUG     = 'nera-strikeawin';
	const CURRENT_SLUG    = 'nera-strike-a-win-plugin';
	const MAIN_FILE       = 'nera-strikeawin.php';
	const MIGRATED_OPTION = 'nera_saw_plugin_path_migrated_v1';

	/**
	 * Migrate active_plugins + PUC bookkeeping from the legacy folder slug.
	 */
	public static function maybe_migrate() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		$legacy = self::legacy_basename();
		$current = self::current_basename();
		$changed = false;

		if ( self::migrate_active_plugins( $legacy, $current ) ) {
			$changed = true;
		}
		if ( is_multisite() && self::migrate_network_active_plugins( $legacy, $current ) ) {
			$changed = true;
		}
		if ( self::migrate_puc_option( self::LEGACY_SLUG, self::CURRENT_SLUG ) ) {
			$changed = true;
		}

		if ( $changed ) {
			delete_site_transient( 'update_plugins' );
		}

		update_option( self::MIGRATED_OPTION, 1, false );
	}

	/**
	 * @return string
	 */
	public static function current_basename() {
		return self::CURRENT_SLUG . '/' . self::MAIN_FILE;
	}

	/**
	 * @return string
	 */
	public static function legacy_basename() {
		return self::LEGACY_SLUG . '/' . self::MAIN_FILE;
	}

	/**
	 * @param string $legacy  Legacy plugin basename.
	 * @param string $current New plugin basename.
	 * @return bool
	 */
	private static function migrate_active_plugins( $legacy, $current ) {
		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) || ! in_array( $legacy, $active, true ) ) {
			return false;
		}

		$active = array_values(
			array_map(
				static function ( $basename ) use ( $legacy, $current ) {
					return ( $basename === $legacy ) ? $current : $basename;
				},
				$active
			)
		);
		update_option( 'active_plugins', $active, true );
		return true;
	}

	/**
	 * @param string $legacy  Legacy plugin basename.
	 * @param string $current New plugin basename.
	 * @return bool
	 */
	private static function migrate_network_active_plugins( $legacy, $current ) {
		$active = get_site_option( 'active_sitewide_plugins', array() );
		if ( ! is_array( $active ) || ! isset( $active[ $legacy ] ) ) {
			return false;
		}

		$stamp = $active[ $legacy ];
		unset( $active[ $legacy ] );
		$active[ $current ] = $stamp;
		update_site_option( 'active_sitewide_plugins', $active );
		return true;
	}

	/**
	 * Plugin Update Checker stores state under external_updates-{slug}.
	 *
	 * @param string $legacy_slug  Legacy folder slug.
	 * @param string $current_slug Current folder slug.
	 * @return bool
	 */
	private static function migrate_puc_option( $legacy_slug, $current_slug ) {
		$legacy_key  = 'external_updates-' . $legacy_slug;
		$current_key = 'external_updates-' . $current_slug;
		$data        = get_option( $legacy_key, null );

		if ( null === $data ) {
			return false;
		}

		if ( null === get_option( $current_key, null ) ) {
			update_option( $current_key, $data, false );
		}
		delete_option( $legacy_key );
		return true;
	}
}
