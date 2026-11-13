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
	const MONTHS_SHORT_RU   = array( 1 => 'янв.', 2 => 'февр.', 3 => 'мар.', 4 => 'апр.', 5 => 'мая', 6 => 'июн.', 7 => 'июл.', 8 => 'авг.', 9 => 'сент.', 10 => 'окт.', 11 => 'нояб.', 12 => 'дек.' );

	/**
	 * THE date format of the section: "Sun 14 Sep, 21:00 UK" (the design's), on every
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

		return self::with_zone( wp_date( 'D j M, H:i', $timestamp ) );
	}

	/**
	 * A time of day alone, 24-hour, in the same shape as display(): "21:00 UK"
	 * in English, "21:00" in Russian.
	 *
	 * @param int|false         $timestamp Unix timestamp, or falsy for an empty result.
	 * @param DateTimeZone|null $timezone  Zone to print the time in; the site's by default.
	 * @return string
	 */
	public static function clock( $timestamp, $timezone = null ) {
		if ( ! $timestamp ) {
			return '';
		}

		return self::with_zone( wp_date( 'H:i', $timestamp, $timezone ) );
	}

	/**
	 * When a competition closes, as a Unix timestamp, or 0 when it has no end date.
	 *
	 * Read from `_lty_end_date_gmt`, the meta the lottery plugin closes the draw
	 * at. The local `_lty_end_date` can disagree with it, so it is never used.
	 *
	 * @param int $product_id Competition (product) ID.
	 * @return int
	 */
	public static function lty_end_ts( $product_id ) {
		$gmt = (string) get_post_meta( (int) $product_id, '_lty_end_date_gmt', true );

		return '' !== $gmt ? (int) strtotime( $gmt . ' UTC' ) : 0;
	}

	/**
	 * The time zone label printed after an English time ("UK"); '' in Russian,
	 * whose screens add their own note. Filter `nera_saw_date_zone_label`.
	 *
	 * @return string
	 */
	public static function zone_label() {
		$ru = class_exists( 'Nera_SAW_Language' ) && 'ru' === Nera_SAW_Language::current();

		return trim( (string) apply_filters( 'nera_saw_date_zone_label', $ru ? '' : 'UK', $ru ? 'ru' : 'en' ) );
	}

	/**
	 * Append the zone label to a formatted time.
	 *
	 * @param string $time Formatted date and/or time.
	 * @return string
	 */
	private static function with_zone( $time ) {
		$label = self::zone_label();

		return '' === $label ? $time : $time . ' ' . $label;
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
			return array( 'd' => 'д', 'h' => 'ч', 'm' => 'м', 's' => 'с' );
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
