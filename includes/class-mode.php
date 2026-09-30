<?php
/**
 * Mode resolution — the three settings that gate behaviour, and the single place
 * every caller asks about them.
 *
 * Nothing outside this class reads those three keys from the settings option.
 * Two reasons, both of which have already caught people out elsewhere in this
 * plugin: a competition may override the quiz method, so the global value is not
 * the answer; and each resolves through a filter, so a site can force one without
 * a code change. A caller reading the raw option misses both.
 *
 * Defaults are chosen so an install that upgrades into this code behaves exactly
 * as it did before — mix, random, questions-only. See ADR 0019.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Mode
 */
class Nera_SAW_Mode {

	/**
	 * StrikeAWin Method — which front end the site runs.
	 */
	const METHOD_MIX        = 'mix';
	const METHOD_STANDALONE = 'standalone';

	/**
	 * Quiz Method — how a run's slots are ordered.
	 */
	const QUIZ_RANDOM = 'random';
	const QUIZ_LADDER = 'ladder';

	/**
	 * Language Scope — how far the language choice reaches. At the default the
	 * question bank alone carries a language (ADR 0024); the wider scope is where
	 * a translation layer would enter, and it does not exist yet.
	 */
	const SCOPE_QUESTIONS  = 'questions_only';
	const SCOPE_STANDALONE = 'whole_standalone';

	/**
	 * Resume policy — what happens when a run is interrupted.
	 */
	const RESUME_ALLOW = 'resume';
	const RESUME_CLOSE = 'close';

	/**
	 * The per-competition "use the global value" marker. Stored as an empty
	 * string rather than absent, so a competition saved before this setting
	 * existed and one deliberately set to inherit are the same state.
	 */
	const INHERIT = '';

	/* ---------------------------------------------------------------------
	 * Choices (admin)
	 * ------------------------------------------------------------------ */

	/**
	 * StrikeAWin Method choices, key => label.
	 *
	 * @return array
	 */
	public static function methods() {
		return array(
			self::METHOD_MIX        => __( 'Mix — competitions sit in the main catalogue', 'nera-strikeawin' ),
			self::METHOD_STANDALONE => __( 'Standalone — the plugin serves its own section', 'nera-strikeawin' ),
		);
	}

	/**
	 * Quiz Method choices, key => label.
	 *
	 * @return array
	 */
	public static function quiz_methods() {
		return array(
			self::QUIZ_RANDOM => __( 'Random — difficulty mixed across the run', 'nera-strikeawin' ),
			self::QUIZ_LADDER => __( 'Ladder — stages ascending, easy to hard', 'nera-strikeawin' ),
		);
	}

	/**
	 * Language Scope choices, key => label.
	 *
	 * @return array
	 */
	public static function language_scopes() {
		return array(
			self::SCOPE_QUESTIONS  => __( 'Questions only — the interface stays in one language; the player picks the language of the questions', 'nera-strikeawin' ),
			self::SCOPE_STANDALONE => __( 'Whole standalone section — content follows the language too', 'nera-strikeawin' ),
		);
	}

	/**
	 * Resume policy choices, key => label.
	 *
	 * @return array
	 */
	public static function resume_policies() {
		return array(
			self::RESUME_ALLOW => __( 'Let the player continue — the run is held, and they are offered Resume on the question they were on. If they do not resume in time, the run is closed for an administrator', 'nera-strikeawin' ),
			self::RESUME_CLOSE => __( 'Close the run — an administrator reviews it and can refund the run', 'nera-strikeawin' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Resolution
	 * ------------------------------------------------------------------ */

	/**
	 * The site's StrikeAWin Method.
	 *
	 * @return string One of the METHOD_* constants.
	 */
	public static function method() {
		$s    = Nera_SAW_Constants::settings();
		$mode = self::sanitize_method( isset( $s['strikeawin_method'] ) ? $s['strikeawin_method'] : self::METHOD_MIX );

		/**
		 * Filter the resolved StrikeAWin Method.
		 *
		 * @param string $mode One of the METHOD_* constants.
		 */
		return self::sanitize_method( apply_filters( 'nera_saw_method', $mode ) );
	}

	/**
	 * Is the site running the standalone front end?
	 *
	 * @return bool
	 */
	public static function is_standalone() {
		return self::METHOD_STANDALONE === self::method();
	}

	/**
	 * Is the site running competitions inside the main catalogue?
	 *
	 * @return bool
	 */
	public static function is_mix() {
		return self::METHOD_MIX === self::method();
	}

	/**
	 * The Quiz Method in force for a competition.
	 *
	 * Resolution order: the competition's own override, then the global default.
	 * Pass 0 (or nothing) for the global value — which is what a screen showing
	 * the setting itself wants, not what a screen showing a competition wants.
	 *
	 * @param int $competition_id Competition product ID (0 = global default).
	 * @return string One of the QUIZ_* constants.
	 */
	public static function quiz_method( $competition_id = 0 ) {
		$s      = Nera_SAW_Constants::settings();
		$global = self::sanitize_quiz_method( isset( $s['quiz_method'] ) ? $s['quiz_method'] : self::QUIZ_RANDOM );
		$value  = $global;

		$competition_id = (int) $competition_id;
		if ( $competition_id > 0 ) {
			$config   = Nera_SAW_Competition_Config::get( $competition_id );
			$override = self::key_of( isset( $config['quiz_method'] ) ? $config['quiz_method'] : self::INHERIT );
			if ( self::INHERIT !== $override ) {
				$value = self::sanitize_quiz_method( $override );
			}
		}

		/**
		 * Filter the resolved Quiz Method.
		 *
		 * @param string $value          One of the QUIZ_* constants.
		 * @param int    $competition_id Competition product ID (0 = global).
		 * @param string $global         The global default, before any override.
		 */
		return self::sanitize_quiz_method( apply_filters( 'nera_saw_quiz_method', $value, $competition_id, $global ) );
	}

	/**
	 * Does this competition run its stages in ascending difficulty?
	 *
	 * The question every caller actually has. `Stage` is only a meaningful word
	 * when this is true — see CONTEXT.md.
	 *
	 * @param int $competition_id Competition product ID (0 = global default).
	 * @return bool
	 */
	public static function is_ladder( $competition_id = 0 ) {
		return self::QUIZ_LADDER === self::quiz_method( $competition_id );
	}

	/**
	 * How far the site's language choice reaches.
	 *
	 * @return string One of the SCOPE_* constants.
	 */
	public static function language_scope() {
		$s     = Nera_SAW_Constants::settings();
		$scope = self::sanitize_language_scope( isset( $s['language_scope'] ) ? $s['language_scope'] : self::SCOPE_QUESTIONS );

		/**
		 * Filter the resolved Language Scope.
		 *
		 * @param string $scope One of the SCOPE_* constants.
		 */
		return self::sanitize_language_scope( apply_filters( 'nera_saw_language_scope', $scope ) );
	}

	/**
	 * What happens to a run interrupted by a disconnect.
	 *
	 * Note this is about an *interruption*, not about leaving. A player who uses
	 * the leave button and confirms is making a choice, and that is an abandoned
	 * run under either policy — the dialog already told them what it costs.
	 *
	 * @return string One of the RESUME_* constants.
	 */
	public static function resume_policy() {
		$s      = Nera_SAW_Constants::settings();
		$policy = self::sanitize_resume_policy( isset( $s['resume_policy'] ) ? $s['resume_policy'] : self::RESUME_ALLOW );

		/**
		 * Filter the resolved resume policy.
		 *
		 * @param string $policy One of the RESUME_* constants.
		 */
		return self::sanitize_resume_policy( apply_filters( 'nera_saw_resume_policy', $policy ) );
	}

	/**
	 * May an interrupted run be continued?
	 *
	 * @return bool
	 */
	public static function allows_resume() {
		return self::RESUME_ALLOW === self::resume_policy();
	}

	/**
	 * How long an interrupted run is held for Resume, in seconds.
	 *
	 * The admin field is in minutes with no upper limit. Saved blank it means the
	 * short built-in window rather than "no window" — a run cannot be held open
	 * forever. Anything that is not a positive whole number reads as blank.
	 *
	 * @return int
	 */
	public static function resume_window_seconds() {
		$s       = Nera_SAW_Constants::settings();
		$minutes = isset( $s['resume_window_minutes'] ) ? $s['resume_window_minutes'] : Nera_SAW_Constants::RESUME_WINDOW_DEFAULT_MINUTES;

		if ( ! is_numeric( $minutes ) || (int) $minutes < 1 ) {
			$minutes = Nera_SAW_Constants::RESUME_WINDOW_BLANK_MINUTES;
		}

		return (int) $minutes * MINUTE_IN_SECONDS;
	}

	/* ---------------------------------------------------------------------
	 * Sanitisers
	 *
	 * Each falls back to the default rather than erroring. An unrecognised
	 * stored value means a downgrade, a hand-edited option or a typo in a
	 * filter; in every case the safe answer is the behaviour the site had
	 * before these settings existed.
	 * ------------------------------------------------------------------ */

	/**
	 * Reduce any stored or posted value to a comparable key.
	 *
	 * Guards the string cast: a hand-edited option or a malformed POST can hold
	 * an array, and casting that emits "Array to string conversion" — a warning
	 * on a settings read, which on a site with display_errors on means a notice
	 * printed into the page. Anything non-scalar is simply not a valid choice,
	 * so it reduces to '' and each caller's fallback takes over.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function key_of( $value ) {
		return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_method( $value ) {
		$value = self::key_of( $value );
		return array_key_exists( $value, self::methods() ) ? $value : self::METHOD_MIX;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_quiz_method( $value ) {
		$value = self::key_of( $value );
		return array_key_exists( $value, self::quiz_methods() ) ? $value : self::QUIZ_RANDOM;
	}

	/**
	 * Sanitise a per-competition Quiz Method override, where "inherit the global
	 * value" is a legal answer and the plain sanitiser's fallback is not.
	 *
	 * @param mixed $value Raw value.
	 * @return string One of the QUIZ_* constants, or INHERIT.
	 */
	public static function sanitize_quiz_method_override( $value ) {
		$value = self::key_of( $value );
		if ( '' === $value ) {
			return self::INHERIT;
		}
		return array_key_exists( $value, self::quiz_methods() ) ? $value : self::INHERIT;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_resume_policy( $value ) {
		$value = self::key_of( $value );
		return array_key_exists( $value, self::resume_policies() ) ? $value : self::RESUME_ALLOW;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_language_scope( $value ) {
		$value = self::key_of( $value );
		return array_key_exists( $value, self::language_scopes() ) ? $value : self::SCOPE_QUESTIONS;
	}
}
