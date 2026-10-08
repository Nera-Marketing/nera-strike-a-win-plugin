<?php
/**
 * Russian date/time rendering for the standalone section.
 *
 * `wp_date()`/`date_i18n()` format using the site's WordPress locale
 * (`get_locale()`), not `Nera_SAW_Language::current()` — so every date on a
 * Russian-served page rendered in English regardless of which language the
 * section itself was serving. Client finding #8/#23 ("Закрывается Thu 29 Oct,
 * 2am", "October 29, 2026, 2:34 am"). This class is the Russian branch each
 * date call site switches to; the English branch is untouched.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Date
 */
class Nera_SAW_Date {

	/**
	 * Month names in the genitive case ("29 октября", not "29 октябрь") — the
	 * form a day-of-month date takes in Russian. From the client's supplied
	 * `_date_format` spec.
	 *
	 * @var array<int,string>
	 */
	const MONTHS_GENITIVE = array(
		1  => 'января',
		2  => 'февраля',
		3  => 'марта',
		4  => 'апреля',
		5  => 'мая',
		6  => 'июня',
		7  => 'июля',
		8  => 'августа',
		9  => 'сентября',
		10 => 'октября',
		11 => 'ноября',
		12 => 'декабря',
	);

	/**
	 * A timestamp, in whichever language is current — the English format
	 * string is passed through unchanged (`wp_date()`), and the Russian
	 * rendering always takes the shape the client specified: day, genitive
	 * month, year, and a 24-hour time if asked for ("29 октября 2026, 21:00").
	 * No day-of-week, no am/pm — not in the client's own example either.
	 *
	 * @param int|false $timestamp Unix timestamp, or falsy for an empty result.
	 * @param string    $en_format `wp_date()`-style format string for English.
	 * @param bool      $with_time Whether to append the time.
	 * @return string
	 */
	public static function localized( $timestamp, $en_format, $with_time = true ) {
		if ( ! $timestamp ) {
			return '';
		}

		if ( 'ru' !== Nera_SAW_Language::current() ) {
			return wp_date( $en_format, $timestamp );
		}

		return self::format_ru( $timestamp, $with_time );
	}

	/**
	 * The Russian date (and, optionally, time) for a timestamp directly —
	 * for the one call site (`class-standalone-result-screen.php`) that
	 * already branches on language itself rather than calling `wp_date()`
	 * unconditionally.
	 *
	 * @param int  $timestamp Unix timestamp.
	 * @param bool $with_time Whether to append the time.
	 * @return string
	 */
	public static function format_ru( $timestamp, $with_time = true ) {
		if ( ! $timestamp ) {
			return '';
		}

		$day   = (int) wp_date( 'j', $timestamp );
		$month = (int) wp_date( 'n', $timestamp );
		$year  = wp_date( 'Y', $timestamp );

		$date = sprintf(
			'%d %s %s',
			$day,
			self::MONTHS_GENITIVE[ $month ] ?? '',
			$year
		);

		if ( ! $with_time ) {
			return $date;
		}

		return $date . ', ' . wp_date( 'H:i', $timestamp );
	}

	/**
	 * Time only, 24-hour clock — for a call site that never shows a date
	 * (`resume-popup.php`'s "resume until").
	 *
	 * @param int|false $timestamp Unix timestamp, or falsy for an empty result.
	 * @param string    $en_format `wp_date()`-style format string for English.
	 * @return string
	 */
	public static function localized_time( $timestamp, $en_format ) {
		if ( ! $timestamp ) {
			return '';
		}

		if ( 'ru' !== Nera_SAW_Language::current() ) {
			return wp_date( $en_format, $timestamp );
		}

		return wp_date( 'H:i', $timestamp );
	}
}
