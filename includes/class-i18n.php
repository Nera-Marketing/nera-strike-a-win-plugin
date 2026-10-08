<?php
/**
 * Russian strings for the standalone section — entries page, checkout, orders,
 * account and the quiz app's own chrome.
 *
 * The client supplied two exact-match English → Russian dictionaries
 * (`languages/ru/02-russian-strings-prototype.json`,
 * `languages/ru/02b-russian-strings-dev-build.json`, loaded as given, per
 * their own instruction), keyed by the literal English source string rather
 * than an abstract key, with `{placeholder}` substitution and Russian's three
 * plural forms (one/few/many) packed into one pipe-separated value where a
 * count is involved.
 *
 * This is deliberately a second i18n layer, not an extension of the existing
 * `gettext`/`ngettext` filters in class-language.php: those intercept
 * WordPress's own `__()`/`_n()` calls (`%1$d`-style placeholders, a two-form
 * English plural rule) and are what produced the client's reported bug in the
 * first place — `_n()`'s own singular/plural choice decides which one of two
 * Russian strings a gettext filter can ever return, which is why counts other
 * than 1 always read the "many" Russian form ("41 билетов" instead of "41
 * билет"). Call sites migrating to the strings here call `t()`/`n()`
 * directly instead of `__()`/`_n()`.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_I18n
 */
class Nera_SAW_I18n {

	/**
	 * Supplied strings files, relative to the plugin root, loaded as given.
	 *
	 * @var string[]
	 */
	const FILES = array(
		'languages/ru/02-russian-strings-prototype.json',
		'languages/ru/02b-russian-strings-dev-build.json',
	);

	/**
	 * Merged English => Russian lookup, built once per request.
	 *
	 * @var array<string,string>|null
	 */
	protected static $strings = null;

	/**
	 * The merged lookup table, loading both files on first use.
	 *
	 * Keys starting with `_` are the supplied files' own section dividers and
	 * notes (`_note`, `_quiz_chrome`, `_plural_helper_js`, `_date_format`,
	 * etc.) — not strings to serve — and are skipped.
	 *
	 * @return array<string,string>
	 */
	protected static function strings() {
		if ( null !== self::$strings ) {
			return self::$strings;
		}

		$merged = array();
		foreach ( self::FILES as $relative_path ) {
			$path = NERA_SAW_PLUGIN_DIR . $relative_path;
			if ( ! is_readable( $path ) ) {
				continue;
			}

			$data = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			foreach ( $data as $key => $value ) {
				if ( '_' === substr( (string) $key, 0, 1 ) || ! is_string( $value ) ) {
					continue;
				}
				$merged[ $key ] = $value;
			}
		}

		self::$strings = $merged;

		return $merged;
	}

	/**
	 * A string with no count attached — the entries page, checkout, account
	 * and quiz chrome's non-pluralized copy.
	 *
	 * Looked up by its literal English text. A string this plugin's call
	 * sites pass that is missing from the supplied files (not yet covered —
	 * see the client's own offer to add more once told which) renders as
	 * plain English, same as before this layer existed.
	 *
	 * @param string               $english English source string; also the Russian lookup key.
	 * @param array<string,mixed>  $vars    `{placeholder}` substitutions, e.g. array( 'n' => 3 ).
	 * @return string
	 */
	public static function t( $english, array $vars = array() ) {
		$value = ( 'ru' === Nera_SAW_Language::current() )
			? ( self::strings()[ $english ] ?? $english )
			: $english;

		return self::brand( self::substitute( $value, $vars ) );
	}

	/**
	 * A string with a count attached — Russian takes whichever of its three
	 * supplied forms (one/few/many) the count resolves to; English keeps
	 * ordinary two-form pluralization, exactly as `_n()` already did at
	 * every call site this replaces.
	 *
	 * `$count` is also made available as `{n}` in `$vars` unless the caller
	 * already supplied its own `n`.
	 *
	 * @param string               $single English singular form; also the Russian lookup key.
	 * @param string               $plural English plural form, used only when serving English.
	 * @param int                  $count  The count deciding which form to use.
	 * @param array<string,mixed>  $vars   `{placeholder}` substitutions.
	 * @return string
	 */
	public static function n( $single, $plural, $count, array $vars = array() ) {
		if ( ! array_key_exists( 'n', $vars ) ) {
			$vars['n'] = $count;
		}

		if ( 'ru' !== Nera_SAW_Language::current() ) {
			$value = ( 1 === (int) $count ) ? $single : $plural;
			return self::brand( self::substitute( $value, $vars ) );
		}

		$template = self::strings()[ $single ] ?? $single;
		$value    = self::ru_plural( (int) $count, $template );

		return self::brand( self::substitute( $value, $vars ) );
	}

	/**
	 * Russian's three plural forms (one/few/many) from one pipe-separated
	 * string. Identical to the client-supplied `_plural_helper_php` in
	 * `languages/ru/02b-russian-strings-dev-build.json` — kept here as a
	 * plain function rather than `eval()`-ing that value at runtime.
	 *
	 * one = 1, 21, 31…; few = 2-4, 22-24…; many = 0, 5-20, 25-30… (the
	 * client's own `_date_format`-adjacent note in that file).
	 *
	 * @param int    $n     The count.
	 * @param string $forms Pipe-separated `one|few|many`, or a single un-pluralized string.
	 * @return string
	 */
	protected static function ru_plural( $n, $forms ) {
		$f = explode( '|', $forms );
		if ( count( $f ) === 1 ) {
			return $f[0];
		}

		$m10  = $n % 10;
		$m100 = $n % 100;

		if ( 1 === $m10 && 11 !== $m100 ) {
			return $f[0];
		}
		if ( $m10 >= 2 && $m10 <= 4 && ( $m100 < 12 || $m100 > 14 ) ) {
			return $f[1];
		}

		return $f[2];
	}

	/**
	 * `{placeholder}` substitution — the style the supplied strings use,
	 * distinct from `sprintf()`'s `%1$d` this plugin's existing `__()`/`_n()`
	 * call sites were built around.
	 *
	 * @param string               $string Template possibly containing `{key}` placeholders.
	 * @param array<string,mixed>  $vars   Replacement values, keyed without braces.
	 * @return string
	 */
	protected static function substitute( $string, array $vars ) {
		if ( empty( $vars ) ) {
			return $string;
		}

		$replace = array();
		foreach ( $vars as $key => $value ) {
			$replace[ '{' . $key . '}' ] = $value;
		}

		return strtr( $string, $replace );
	}

	/**
	 * `[BRAND]` → the site title. One substitution point, reusing the
	 * existing `get_bloginfo( 'name' )` convention (see `header.php`,
	 * `class-seeder.php`) rather than a new, separate setting — the client's
	 * new name just needs setting once in Settings → General when confirmed.
	 *
	 * @param string $string String possibly containing a literal `[BRAND]` token.
	 * @return string
	 */
	protected static function brand( $string ) {
		if ( false === strpos( $string, '[BRAND]' ) ) {
			return $string;
		}

		return str_replace( '[BRAND]', get_bloginfo( 'name' ), $string );
	}
}
