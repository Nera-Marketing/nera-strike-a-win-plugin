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
	 * Weekday and month abbreviations for the Russian short form (Sun = 0).
	 */
	const WEEKDAYS_SHORT_RU = array( 'вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб' );
	const MONTHS_SHORT_RU   = array( 1 => 'янв', 2 => 'фев', 3 => 'мар', 4 => 'апр', 5 => 'мая', 6 => 'июн', 7 => 'июл', 8 => 'авг', 9 => 'сен', 10 => 'окт', 11 => 'ноя', 12 => 'дек' );

	/**
	 * THE date format of the section: "Sun 14 Sep, 9:00pm" (the design's), on every
	 * screen that shows a date -- the cards, the competition page, Before you pay,
	 * Play, My account, the results screen and the quiz app. One function so the
	 * two formats the client found (card "Thu 29 Oct, 2am", page "October 29,
	 * 2026, 2:34 am") cannot come back by someone passing a different pattern.
	 *
	 * Russian is the same shape in Russian: "вс, 14 сен, 21:00" (24-hour clock).
	 *
	 * @param int|false $timestamp Unix timestamp, or falsy for an empty result.
	 * @return string
	 */
	public static function display( $timestamp ) {
		if ( ! $timestamp ) {
			return '';
		}

		if ( class_exists( 'Nera_SAW_Language' ) && 'ru' === Nera_SAW_Language::current() ) {
			return sprintf(
				'%s, %d %s, %s',
				self::WEEKDAYS_SHORT_RU[ (int) wp_date( 'w', $timestamp ) ],
				(int) wp_date( 'j', $timestamp ),
				self::MONTHS_SHORT_RU[ (int) wp_date( 'n', $timestamp ) ],
				wp_date( 'H:i', $timestamp )
			);
		}

		return wp_date( 'D j M, g:ia', $timestamp );
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

	/**
	 * Time left until a draw closes, as short text: "2d 4h", "5h 12m", "42m 10s".
	 * The same rule countdown.js applies as it ticks, so the page is right before
	 * (and without) the script.
	 *
	 * @param int  $seconds Seconds remaining.
	 * @param bool $suffix  Append "left" ("2d 4h left") -- for a chip with no label of its own.
	 * @return string
	 */
	public static function countdown_text( $seconds, $suffix = true ) {
		$seconds = (int) $seconds;
		if ( $seconds <= 0 ) {
			return __( 'Closed', 'nera-strikeawin' );
		}

		$d = intdiv( $seconds, 86400 );
		$h = intdiv( $seconds % 86400, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;

		$u = self::countdown_units();
		if ( $d > 0 ) {
			$text = $d . $u['d'] . ' ' . $h . $u['h'];
		} elseif ( $h > 0 ) {
			$text = $h . $u['h'] . ' ' . $m . $u['m'];
		} elseif ( $m > 0 ) {
			$text = $m . $u['m'] . ' ' . $s . $u['s'];
		} else {
			$text = $s . $u['s'];
		}

		/* translators: %s: time remaining, e.g. "2d 4h" */
		return $suffix ? sprintf( __( '%s left', 'nera-strikeawin' ), $text ) : $text;
	}

	/**
	 * Unit letters for the countdown, translated.
	 *
	 * @return array{d:string,h:string,m:string,s:string}
	 */
	public static function countdown_units() {
		// A language switch rather than _x(): the strings table only maps plain msgids.
		if ( class_exists( 'Nera_SAW_Language' ) && 'ru' === Nera_SAW_Language::current() ) {
			return array( 'd' => 'д', 'h' => 'ч', 'm' => 'мин', 's' => 'с' );
		}
		return array( 'd' => 'd', 'h' => 'h', 'm' => 'm', 's' => 's' );
	}

	/**
	 * A countdown to a draw. `data-saw-now` is the SERVER's clock for this request, so
	 * countdown.js measures against it rather than the visitor's own clock, which can
	 * be minutes out.
	 *
	 * @param int    $closes_timestamp Unix timestamp the draw closes.
	 * @param string $class            Extra class(es).
	 * @param bool   $suffix           Append "left".
	 * @return string Safe HTML ('' when there is no closing date).
	 */
	public static function countdown_html( $closes_timestamp, $class = '', $suffix = true ) {
		$closes_timestamp = (int) $closes_timestamp;
		if ( $closes_timestamp < 1 ) {
			return '';
		}
		$now = time();

		return sprintf(
			'<span class="saw-countdown %1$s%2$s" role="timer" data-saw-countdown="%3$d" data-saw-now="%4$d"%5$s>%6$s</span>',
			esc_attr( $class ),
			$closes_timestamp <= $now ? ' is-ended' : '',
			$closes_timestamp,
			$now,
			$suffix ? ' data-saw-suffix="1"' : '',
			esc_html( self::countdown_text( $closes_timestamp - $now, $suffix ) )
		);
	}
}
