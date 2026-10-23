<?php
/**
 * House copy rules for text this plugin shows but does not own.
 *
 * Player copy has no dashes (em, en or a spaced hyphen). Text we write is fixed at
 * the source; text another plugin or a stored record supplies is cleaned on its way
 * out, in the SAW screens only.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

class Nera_SAW_Copy {

	/**
	 * Replace dashes in the text of an HTML fragment ("Citizens Advice - general
	 * advice" becomes "Citizens Advice: general advice"). Tags and attributes are
	 * left alone.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function undash( $html ) {
		$parts = preg_split( '/(<[^>]*>)/', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( '' === $part || '<' === $part[0] ) {
				continue;
			}
			$part      = str_replace( array( '&mdash;', '&ndash;', '&#8212;', '&#8211;' ), array( '—', '–', '—', '–' ), $part );
			$parts[ $i ] = self::undash_text( $part );
		}
		return implode( '', $parts );
	}

	/**
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function undash_text( $text ) {
		$text = preg_replace( '/\s+[—–-]\s+/u', ': ', $text );
		return preg_replace( '/[—–]/u', ', ', $text );
	}

	/**
	 * Dashes inside a sentence: a full stop before a new sentence, otherwise a comma.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function undash_prose( $text ) {
		$text = preg_replace_callback(
			'/\s+[—–-]\s+(\S)/u',
			static function ( $m ) {
				return ( mb_strtoupper( $m[1] ) === $m[1] && mb_strtolower( $m[1] ) !== $m[1] ? '. ' : ', ' ) . $m[1];
			},
			$text
		);
		return preg_replace( '/[—–]/u', ', ', $text );
	}

	const MIGRATION_OPTION = 'nera_saw_copy_migrated';
	const MIGRATION_LEVEL  = 2;

	/**
	 * Fields saved before the copy sheet keep their old wording over the new default.
	 * Rewritten once, and only where a stored value still equals what we used to ship
	 * (or just carries a dash), so an editor's own wording is never touched.
	 */
	public static function migrate() {
		if ( (int) get_option( self::MIGRATION_OPTION, 0 ) >= self::MIGRATION_LEVEL ) {
			return;
		}

		$exact = array(
			'options_saw_logo_text'   => array( 'Strike <em>A</em> Win' => '[BRAND]' ),
			'options_saw_footer_line' => array(
				'Competitions Standard — skill-based prize competitions.' => '[BRAND].',
				'Strike A Win — skill-based prize competitions.'          => '[BRAND].',
			),
			'options_saw_pp_consent_age' => array( 'I am 18 or over and a UK resident.' => 'I am over 18 and agree to the terms and conditions.' ),
			'options_saw_cd_footnote'    => array(
				'A run can earn zero tickets. Only correct answers earn entries, and that is what makes this a game of skill, not a lottery.' => 'A run can earn zero tickets. Only correct answers earn entries, and that is what makes this a game of skill, not a game of chance.',
			),
		);
		foreach ( $exact as $name => $map ) {
			$value = get_option( $name, null );
			if ( is_string( $value ) && isset( $map[ $value ] ) ) {
				update_option( $name, $map[ $value ] );
			}
		}

		// Any other stored SAW field or result-screen message: dashes out.
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'options\_saw\_%' OR option_name LIKE 'options\_lty\_rs\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			if ( is_serialized( $row->option_value ) || ! preg_match( '/[—–]|\s-\s/u', $row->option_value ) ) {
				continue;
			}
			update_option( $row->option_name, self::undash_prose( $row->option_value ) );
		}

		// Seeded translations of those fields.
		$i18n = get_option( 'nera_saw_standalone_i18n', null );
		if ( is_array( $i18n ) ) {
			foreach ( $i18n as $field => $langs ) {
				foreach ( (array) $langs as $lang => $text ) {
					if ( is_string( $text ) && 'saw_logo_text' === $field && 'Strike <em>A</em> Win' === $text ) {
						$i18n[ $field ][ $lang ] = '[BRAND]';
					} elseif ( is_string( $text ) && 'saw_footer_line' === $field && false !== strpos( $text, ' — ' ) ) {
						$i18n[ $field ][ $lang ] = '[BRAND].';
					} elseif ( is_string( $text ) && preg_match( '/[—–]|\s-\s/u', $text ) ) {
						$i18n[ $field ][ $lang ] = self::undash_prose( $text );
					}
				}
			}
			update_option( 'nera_saw_standalone_i18n', $i18n );
		}

		// "Lottery" never reaches a player; where a stored text had it, it says "game of chance".
		$i18n = get_option( 'nera_saw_standalone_i18n', null );
		if ( is_array( $i18n ) ) {
			foreach ( $i18n as $field => $langs ) {
				foreach ( (array) $langs as $lang => $text ) {
					if ( is_string( $text ) && preg_match( '/лотере/u', $text ) ) {
						$i18n[ $field ][ $lang ] = preg_replace( '/а не лотере\p{L}*/u', 'а не азартная игра', $text );
					}
				}
			}
			update_option( 'nera_saw_standalone_i18n', $i18n );
		}

		// The same fields saved on a page (Before you pay, the competition page, ...) live in
		// post meta, not options.
		$meta = $wpdb->get_results( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE 'saw\_%' AND meta_key NOT LIKE '\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $meta as $row ) {
			$new = $row->meta_value;
			if ( 'saw_pp_consent_age' === $row->meta_key && 'I am 18 or over and a UK resident.' === $new ) {
				$new = 'I am over 18 and agree to the terms and conditions.';
			} elseif ( 'saw_logo_text' === $row->meta_key && 'Strike <em>A</em> Win' === $new ) {
				$new = '[BRAND]';
			} elseif ( ! is_serialized( $new ) && preg_match( '/[—–]|\s-\s/u', $new ) ) {
				$new = self::undash_prose( $new );
			}
			if ( $new !== $row->meta_value ) {
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $new ), array( 'meta_id' => $row->meta_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		// The seeded demo description is no longer shown to players.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = '', post_excerpt = '' WHERE post_type = 'product' AND post_content = %s", 'A demo competition used for testing Strike A Win.' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		update_option( self::MIGRATION_OPTION, self::MIGRATION_LEVEL );
	}
}
