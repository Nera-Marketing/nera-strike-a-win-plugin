<?php
/**
 * The site's brand name, held in one place.
 *
 * The client is renaming the site and the new name is not confirmed. It lives in
 * one SAW setting (Settings > Brand name) and nowhere in a template: player copy
 * writes the token [BRAND] and this class swaps it in when the string is read.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

class Nera_SAW_Brand {

	const TOKEN   = '[BRAND]';
	const DEFAULT = 'Strike And Win';

	public static function init() {
		add_filter( 'gettext', array( __CLASS__, 'filter_gettext' ), 20, 3 );
		add_filter( 'ngettext', array( __CLASS__, 'filter_ngettext' ), 20, 5 );
		// A page title can carry the token too (the Play page is "Play [BRAND]").
		add_filter( 'the_title', function ( $title ) { return is_admin() ? $title : self::apply( $title ); }, 20 );
		// The browser tab: "Page, [BRAND]" on the section's screens, no dash separator and
		// never the old site title.
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_title_parts' ), 20 );
		add_filter( 'document_title_separator', array( __CLASS__, 'filter_title_separator' ), 20 );
	}

	/**
	 * The brand name as the player should see it.
	 *
	 * @return string
	 */
	public static function name() {
		$settings = Nera_SAW_Constants::settings();
		$name     = isset( $settings['brand_name'] ) ? trim( (string) $settings['brand_name'] ) : '';
		return '' === $name ? self::DEFAULT : $name;
	}

	/**
	 * Replace the token in a string.
	 *
	 * @param string $text Text that may contain [BRAND].
	 * @return string
	 */
	public static function apply( $text ) {
		return ( is_string( $text ) && false !== strpos( $text, self::TOKEN ) ) ? str_replace( self::TOKEN, self::name(), $text ) : $text;
	}

	public static function filter_gettext( $translation, $text, $domain ) {
		return 'nera-strikeawin' === $domain ? self::apply( $translation ) : $translation;
	}

	public static function filter_ngettext( $translation, $single, $plural, $number, $domain ) {
		return 'nera-strikeawin' === $domain ? self::apply( $translation ) : $translation;
	}

	public static function filter_title_parts( $parts ) {
		if ( ! class_exists( 'Nera_SAW_Router' ) || ! Nera_SAW_Router::is_standalone_screen() ) {
			return $parts;
		}
		foreach ( array( 'title', 'site' ) as $key ) {
			if ( isset( $parts[ $key ] ) ) {
				$parts[ $key ] = self::apply( $parts[ $key ] );
			}
		}
		$parts['site'] = self::name();
		return $parts;
	}

	public static function filter_title_separator( $sep ) {
		return ( class_exists( 'Nera_SAW_Router' ) && Nera_SAW_Router::is_standalone_screen() ) ? '|' : $sep;
	}
}
