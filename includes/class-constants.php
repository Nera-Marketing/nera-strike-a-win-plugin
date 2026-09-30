<?php
/**
 * Centralised, compliance-locked constants.
 *
 * Section 9 (Gambling Act 2005 skill exemption) requires certain values to be
 * bounded and NOT admin-editable. They live here in one place so Lewis can
 * confirm/adjust the locked window in a single file. Admin config may only tune
 * WITHIN these bounds, never past them.
 *
 * Values here are placeholders pending Lewis's confirmation.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Constants
 */
class Nera_SAW_Constants {

	/* ---------------------------------------------------------------------
	 * Per-question timer window (seconds).
	 *
	 * Working min/max live in admin Settings (see settings()/timer_min()/timer_max()).
	 * TIMER_MIN/MAX below are only the DEFAULT working bounds used to seed those
	 * settings the first time. There is no separate hard code clamp — Settings
	 * values are the authority (min ≥ 1, max ≥ min). See ADR 0005.
	 * ------------------------------------------------------------------- */
	const TIMER_MIN_SECONDS = 5;  // Default working min (seeds settings).
	const TIMER_MAX_SECONDS = 12; // Default working max (seeds settings).

	/**
	 * @deprecated No longer used as a fence; retained so older references do not fatals.
	 */
	const HARD_TIMER_MIN_SECONDS = 1;

	/**
	 * @deprecated No longer used as a fence; retained so older references do not fatals.
	 */
	const HARD_TIMER_MAX_SECONDS = 86400;

	/**
	 * Server-side allowance (seconds) so a buzzer-beater answer is not killed by
	 * network lag. Locked constant; not admin-editable. See grilling Q10.
	 */
	const LATENCY_GRACE_SECONDS = 1.5;

	/* ---------------------------------------------------------------------
	 * Interrupted runs (ADR 0030).
	 *
	 * No periodic client ping any more (see App.vue's checkAfterReturn(), and
	 * the grilling session that replaced HEARTBEAT_SECONDS's polling with
	 * event-driven checks on tab focus/pagehide instead — a fixed interval
	 * short enough to matter against a 10-second question timer cost more in
	 * request volume than it was worth). `last_seen_at` is now only ever
	 * refreshed by one of those real events, but the same staleness test still
	 * applies: a run whose last check-in is older than HEARTBEAT_STALE_SECONDS
	 * is interrupted, its clock frozen where that check-in left it, and the
	 * player offered Resume for the configured window.
	 * ------------------------------------------------------------------- */
	const HEARTBEAT_STALE_SECONDS = 15;

	/** Minutes a run is held when the admin field is saved blank. */
	const RESUME_WINDOW_BLANK_MINUTES = 5;

	/** Minutes offered in the field before the admin has saved anything. */
	const RESUME_WINDOW_DEFAULT_MINUTES = 10;

	/**
	 * Idle time allowed between one question ending and the next being served
	 * (answer reveal, stage-break screen). Question deadlines are stamped when a
	 * question is served, so this is what keeps a run from being parked forever
	 * between questions.
	 */
	const BETWEEN_QUESTIONS_SLACK_SECONDS = 120;

	/* ---------------------------------------------------------------------
	 * Answer reveal hold (seconds).
	 *
	 * How long the question stays on screen after a submit, showing which option
	 * was correct, before the next slot is served. Purely presentational — the
	 * next slot's deadline is stamped when it is served, so the hold never eats
	 * into a question's timer and carries no compliance weight. Admin-tunable on
	 * the Settings page within the MIN/MAX fence.
	 * ------------------------------------------------------------------- */
	const FEEDBACK_SECONDS_DEFAULT = 5;
	const FEEDBACK_SECONDS_MIN     = 1;
	const FEEDBACK_SECONDS_MAX     = 10;

	/**
	 * Difficulty floor: the ordinal rank on the global ladder below which no
	 * slot/question may be used in a live competition. 1 = lowest rung.
	 * Placeholder pending Lewis. Applied to the ladder's ordered levels.
	 */
	const DIFFICULTY_FLOOR_RANK = 1;

	/**
	 * Reservation TTL (seconds) for an unpaid pending order before the held
	 * spins are released back to Available. Not compliance-locked; a sane
	 * operational default. Override via define( 'NERA_SAW_RESERVATION_TTL', N ).
	 */
	public static function reservation_ttl_seconds() {
		if ( defined( 'NERA_SAW_RESERVATION_TTL' ) ) {
			return (int) NERA_SAW_RESERVATION_TTL;
		}
		return 30 * MINUTE_IN_SECONDS;
	}

	/**
	 * Run-termination strategy. Section 9 currently: sudden death REMOVED ->
	 * 'continue' (run plays through all slots; wrong/timeout = zero, no deduct).
	 * Kept as a single strategy point; flipping to 'sudden_death' is a Section 9
	 * config change requiring Lewis sign-off, not a rebuild.
	 */
	const RUN_TERMINATION = 'continue';

	/* ---------------------------------------------------------------------
	 * Option keys.
	 * ------------------------------------------------------------------- */
	const OPTION_LADDER         = 'nera_saw_difficulty_ladder';
	const OPTION_SETTINGS       = 'nera_saw_settings';
	const OPTION_FEATURE_FLAGS  = 'nera_saw_feature_flags';

	/**
	 * Default per-level display colours (green → red), applied when a level has
	 * no colour set. Indexed by ascending rank position.
	 *
	 * @var string[]
	 */
	const DEFAULT_COLORS = array( '#2e7d32', '#7cb342', '#f9a825', '#ef6c00', '#c62828', '#6a1b9a' );

	/**
	 * Minimum contrast ratio a level colour must reach before it is used as text
	 * on the white quiz card. 4.5:1 is the WCAG 2.1 AA floor for body text; the
	 * question heading is held to it rather than the looser large-text 3:1, since
	 * it is read once, quickly, under a countdown. See level_text_color().
	 */
	const TEXT_CONTRAST_TARGET = 4.5;

	/**
	 * Default global difficulty ladder + default per-level tickets-per-correct.
	 * Admin-managed (add/rename/extend/soft-delete); tickets overridable per
	 * competition. Ordered ascending (rank 1 = easiest). 'reward' = default
	 * tickets per correct answer at that level, before the tier multiplier.
	 * 'color' = display colour (circle in the question-bank listing).
	 */
	public static function default_ladder() {
		return array(
			array( 'key' => 'a', 'label' => 'Easy',      'rank' => 1, 'reward' => 1, 'color' => '#2e7d32', 'deleted' => false ),
			array( 'key' => 'b', 'label' => 'Moderate',  'rank' => 2, 'reward' => 2, 'color' => '#7cb342', 'deleted' => false ),
			array( 'key' => 'c', 'label' => 'Hard',      'rank' => 3, 'reward' => 3, 'color' => '#f9a825', 'deleted' => false ),
			array( 'key' => 'd', 'label' => 'Very Hard', 'rank' => 4, 'reward' => 4, 'color' => '#ef6c00', 'deleted' => false ),
			array( 'key' => 'e', 'label' => 'Expert',    'rank' => 5, 'reward' => 5, 'color' => '#c62828', 'deleted' => false ),
		);
	}

	/**
	 * Default global Settings (timer working bounds + global tier set).
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			// The three behaviour gates. Defaults are today's behaviour on
			// purpose: an install that upgrades into this code must not change
			// shape under a player mid-draw. See ADR 0019.
			'strikeawin_method'  => Nera_SAW_Mode::METHOD_MIX,
			'quiz_method'        => Nera_SAW_Mode::QUIZ_RANDOM,
			'language_scope'     => Nera_SAW_Mode::SCOPE_QUESTIONS,
			'resume_policy'      => Nera_SAW_Mode::RESUME_ALLOW,
			// Minutes an interrupted run is held for Resume. '' (saved blank) means
			// RESUME_WINDOW_BLANK_MINUTES — see Nera_SAW_Mode::resume_window_seconds().
			'resume_window_minutes' => self::RESUME_WINDOW_DEFAULT_MINUTES,
			'timer_min'          => self::TIMER_MIN_SECONDS,
			'timer_max'          => self::TIMER_MAX_SECONDS,
			'timer_warn_seconds' => 3,
			'feedback_seconds'   => self::FEEDBACK_SECONDS_DEFAULT,
			'tiers'              => array(
				array( 'key' => 'standard', 'label' => 'Standard', 'price' => 5.0,  'multiplier' => 1,  'ceiling' => 0 ),
				array( 'key' => 'premium',  'label' => 'Premium',  'price' => 25.0, 'multiplier' => 10, 'ceiling' => 0 ),
			),
		);
	}

	/**
	 * Default feature flags. Both are edited on the demo page (Tools → Strike A Win
	 * Demo → Feature flags); Settings owns only the Answer reveal's hold duration.
	 * save_feature_flags() is still a partial write, so a future second screen can
	 * own a flag without resetting the others.
	 *
	 * @return array
	 */
	public static function default_feature_flags() {
		return array(
			'frontend_ui'   => true,
			'quiz_feedback' => true,
		);
	}

	/**
	 * Seed default options on activation if absent.
	 */
	public static function install_defaults() {
		if ( false === get_option( self::OPTION_LADDER, false ) ) {
			add_option( self::OPTION_LADDER, self::default_ladder() );
		}
		if ( false === get_option( self::OPTION_SETTINGS, false ) ) {
			add_option( self::OPTION_SETTINGS, self::default_settings() );
		}
		if ( false === get_option( self::OPTION_FEATURE_FLAGS, false ) ) {
			add_option( self::OPTION_FEATURE_FLAGS, self::default_feature_flags() );
		}
	}

	/**
	 * Feature flags (option-backed).
	 *
	 * @return array
	 */
	public static function feature_flags() {
		$raw = get_option( self::OPTION_FEATURE_FLAGS, array() );
		return wp_parse_args( is_array( $raw ) ? $raw : array(), self::default_feature_flags() );
	}

	/**
	 * Persist feature flags — a PARTIAL update: only the keys present in $flags are
	 * written, the rest keep their stored values.
	 *
	 * The flags are edited from two screens now (quiz_feedback on Settings,
	 * frontend_ui on the demo page). A whole-array rewrite meant whichever form
	 * saved last silently reset the flag it does not render — an unticked checkbox
	 * and an absent one are indistinguishable in a POST. Callers must pass every
	 * key their own form renders (as an explicit true/false), and no others.
	 *
	 * @param array $flags Flag values, keyed by flag name.
	 * @return bool
	 */
	public static function save_feature_flags( array $flags ) {
		$clean = self::feature_flags();
		foreach ( array_keys( self::default_feature_flags() ) as $key ) {
			if ( array_key_exists( $key, $flags ) ) {
				$clean[ $key ] = ! empty( $flags[ $key ] );
			}
		}
		return update_option( self::OPTION_FEATURE_FLAGS, $clean );
	}

	/**
	 * Global settings (option-backed, merged over defaults).
	 *
	 * @return array
	 */
	public static function settings() {
		$raw = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $raw ) ? $raw : array(), self::default_settings() );
	}

	/**
	 * Admin-configured working timer minimum (seconds). Floor is 1.
	 *
	 * @return int
	 */
	public static function timer_min() {
		$s = self::settings();
		$v = isset( $s['timer_min'] ) ? (int) $s['timer_min'] : self::TIMER_MIN_SECONDS;
		return max( 1, $v );
	}

	/**
	 * Admin-configured working timer maximum (seconds). Never below the working minimum.
	 *
	 * @return int
	 */
	public static function timer_max() {
		$s = self::settings();
		$v = isset( $s['timer_max'] ) ? (int) $s['timer_max'] : self::TIMER_MAX_SECONDS;
		return max( self::timer_min(), max( 1, $v ) );
	}

	/**
	 * Seconds remaining at which the quiz timer turns red (global setting).
	 *
	 * @return int
	 */
	public static function timer_warn_seconds() {
		$s   = self::settings();
		$v   = isset( $s['timer_warn_seconds'] ) ? (int) $s['timer_warn_seconds'] : 3;
		$max = self::timer_max();
		return max( 1, min( $max, $v ) );
	}

	/**
	 * How long the Answer reveal holds the question on screen before the next
	 * slot is served (global setting). Only used when quiz feedback is enabled.
	 *
	 * @return int
	 */
	public static function feedback_seconds() {
		$s = self::settings();
		$v = isset( $s['feedback_seconds'] ) ? (int) $s['feedback_seconds'] : self::FEEDBACK_SECONDS_DEFAULT;
		return max( self::FEEDBACK_SECONDS_MIN, min( self::FEEDBACK_SECONDS_MAX, $v ) );
	}

	/**
	 * The global tier set (canonical). Per-competition overrides are applied by
	 * Nera_SAW_Competition_Config::effective_tiers().
	 *
	 * @return array
	 */
	public static function global_tiers() {
		$s = self::settings();
		return ! empty( $s['tiers'] ) && is_array( $s['tiers'] ) ? array_values( $s['tiers'] ) : self::default_settings()['tiers'];
	}

	/**
	 * The active ladder (option-backed), ordered ascending by rank.
	 *
	 * @param bool $include_deleted Include soft-deleted levels (default false).
	 * @return array
	 */
	public static function ladder( $include_deleted = false ) {
		$ladder = get_option( self::OPTION_LADDER, self::default_ladder() );
		if ( ! is_array( $ladder ) || empty( $ladder ) ) {
			$ladder = self::default_ladder();
		}
		$out = array();
		foreach ( $ladder as $level ) {
			$level = self::normalise_level( $level );
			if ( $include_deleted || empty( $level['deleted'] ) ) {
				$out[] = $level;
			}
		}
		usort(
			$out,
			static function ( $x, $y ) {
				return (int) $x['rank'] <=> (int) $y['rank'];
			}
		);
		return $out;
	}

	/**
	 * Normalise a stored level row to the full shape (back-fills colour/flags).
	 *
	 * @param array $level Level row.
	 * @return array
	 */
	private static function normalise_level( $level ) {
		$level = (array) $level;
		return array(
			'key'     => isset( $level['key'] ) ? (string) $level['key'] : '',
			'label'   => isset( $level['label'] ) ? (string) $level['label'] : '',
			'rank'    => isset( $level['rank'] ) ? (int) $level['rank'] : 0,
			'reward'  => isset( $level['reward'] ) ? (int) $level['reward'] : 0,
			'color'   => ! empty( $level['color'] ) ? (string) $level['color'] : '',
			'deleted' => ! empty( $level['deleted'] ),
		);
	}

	/**
	 * Look up a level definition by key (searches soft-deleted levels too, so
	 * historical questions/runs can always resolve their label + colour).
	 *
	 * @param string $key Level key.
	 * @return array|null
	 */
	public static function level( $key ) {
		foreach ( self::ladder( true ) as $level ) {
			if ( (string) $level['key'] === (string) $key ) {
				return $level;
			}
		}
		return null;
	}

	/**
	 * Resolve a level's display colour (falls back to a rank-based default).
	 *
	 * @param string $key Level key.
	 * @return string Hex colour.
	 */
	public static function level_color( $key ) {
		$level = self::level( $key );
		if ( $level && ! empty( $level['color'] ) ) {
			return $level['color'];
		}
		$rank = $level ? max( 1, (int) $level['rank'] ) : 1;
		$idx  = ( $rank - 1 ) % count( self::DEFAULT_COLORS );
		return self::DEFAULT_COLORS[ $idx ];
	}

	/**
	 * A level's colour, made safe to use as TEXT on the white quiz card.
	 *
	 * Why this is not just level_color(): those colours are chosen to fill a small
	 * circle in the admin (question bank, Report), where a pale fill reads fine.
	 * As text on white they are a different problem — of the five default ladder
	 * colours, three fall below the WCAG AA 4.5:1 minimum, and the amber used for
	 * "Hard" sits at about 2.0:1, which is close to invisible on a phone in
	 * daylight. Since the question is the one thing a player MUST read, and they
	 * are reading it against a running clock, the colour is scaled toward black
	 * (hue preserved) until it clears the target.
	 *
	 * Computed rather than a fixed lookup table, because the Ladder screen lets an
	 * admin pick any hex — including a pale yellow that would otherwise make every
	 * question of that level unreadable.
	 *
	 * @param string $key Level key.
	 * @return string Hex colour, or '' if the level colour is unparseable (the
	 *                caller should then fall back to its normal text colour).
	 */
	public static function level_text_color( $key ) {
		$rgb = self::hex_to_rgb( self::level_color( $key ) );
		if ( null === $rgb ) {
			return '';
		}
		// Step toward black in 2% increments. Black is 21:1, so this always
		// terminates; 2% keeps the overshoot past the target imperceptible.
		for ( $pct = 100; $pct >= 0; $pct -= 2 ) {
			$r = (int) round( $rgb[0] * $pct / 100 );
			$g = (int) round( $rgb[1] * $pct / 100 );
			$b = (int) round( $rgb[2] * $pct / 100 );
			if ( self::contrast_with_white( $r, $g, $b ) >= self::TEXT_CONTRAST_TARGET ) {
				return sprintf( '#%02x%02x%02x', $r, $g, $b );
			}
		}
		return '#000000';
	}

	/**
	 * Parse a 3- or 6-digit hex colour to [ r, g, b ].
	 *
	 * @param string $hex Hex colour, with or without leading '#'.
	 * @return array|null
	 */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return null;
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * WCAG 2.1 contrast ratio of a colour against white.
	 *
	 * @param int $r Red 0-255.
	 * @param int $g Green 0-255.
	 * @param int $b Blue 0-255.
	 * @return float Ratio between 1.0 and 21.0.
	 */
	private static function contrast_with_white( $r, $g, $b ) {
		$lum = 0.2126 * self::linearise_channel( $r )
			+ 0.7152 * self::linearise_channel( $g )
			+ 0.0722 * self::linearise_channel( $b );
		return ( 1.0 + 0.05 ) / ( $lum + 0.05 );
	}

	/**
	 * Convert one 0-255 sRGB channel to its linear value (WCAG 2.1).
	 *
	 * @param int $channel Channel value 0-255.
	 * @return float
	 */
	private static function linearise_channel( $channel ) {
		$c = $channel / 255;
		return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}

	/**
	 * Clamp a proposed per-question timer to the working window (which is itself
	 * fenced by the hard clamp).
	 *
	 * @param int $seconds Proposed timer.
	 * @return int
	 */
	public static function clamp_timer( $seconds ) {
		$seconds = (int) $seconds;
		$seconds = max( self::timer_min(), $seconds );
		$seconds = min( self::timer_max(), $seconds );
		return $seconds;
	}

	/**
	 * Whether a level rank is allowed in a live competition (>= floor).
	 *
	 * @param int $rank Level rank.
	 * @return bool
	 */
	public static function rank_allowed( $rank ) {
		return (int) $rank >= self::DIFFICULTY_FLOOR_RANK;
	}

	/**
	 * Whether frontend UI overrides are active (product tier widget, cart /
	 * checkout tier labels, my-account order tier line).
	 *
	 * Toggle on Tools → Strike A Win Demo → Feature flags.
	 *
	 * @return bool
	 */
	public static function frontend_ui_enabled() {
		$flags = self::feature_flags();
		return (bool) apply_filters( 'nera_saw_frontend_ui_enabled', ! empty( $flags['frontend_ui'] ) );
	}

	/**
	 * Whether the Tools → Strike A Win Demo admin page is registered.
	 *
	 * Only when wp-config.php sets `define( 'NERA_SAW_DEMO_SEEDER', true );`
	 * shows the Tools menu item. The page stays at tools.php?page=nera-saw-demo
	 * for feature flags when the menu is hidden.
	 *
	 * @return bool
	 */
	public static function demo_seeder_enabled() {
		if ( defined( 'NERA_SAW_DEMO_SEEDER' ) ) {
			return (bool) NERA_SAW_DEMO_SEEDER;
		}
		return (bool) apply_filters( 'nera_saw_demo_seeder_enabled', false );
	}

	/**
	 * Whether the quiz UI shows per-question correct / wrong feedback.
	 *
	 * Toggle on Tools → Strike A Win Demo → Feature flags.
	 *
	 * @return bool
	 */
	public static function quiz_feedback_enabled() {
		$flags = self::feature_flags();
		return (bool) apply_filters( 'nera_saw_quiz_feedback_enabled', ! empty( $flags['quiz_feedback'] ) );
	}
}
