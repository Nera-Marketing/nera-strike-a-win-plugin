<?php
/**
 * The two language controls inside the section — step L3 of
 * [docs/LANGUAGE-PLAN.md](../docs/LANGUAGE-PLAN.md).
 *
 * The entry pop-up chooses the language once; the header toggle changes it
 * afterwards. Both come from the client's answer on 16 Sep (Option 1 + Option 2 of
 * `strikeawin-stage.pages.dev/options/`), recorded as
 * [ADR 0027](../docs/adr/0027-the-gate-chooses-the-language-the-header-changes-it.md).
 *
 * NO JAVASCRIPT DEPENDENCY
 * ------------------------
 * Switching is a page load, not a repaint. The section is rendered by PHP, so the
 * language has to be settled before any markup is written — JavaScript arrives too
 * late to be asked. Both controls are therefore ordinary links: they work with
 * scripting off, a translated page can be linked to and bookmarked, and the back
 * button does what it looks like it does. §5 of the plan.
 *
 * DEGRADE TO SILENCE
 * ------------------
 * With fewer than two languages neither control renders and nothing is said about
 * it. A site with no multilingual plugin is not a broken site — it is a monolingual
 * one, which is what Strike A Win is by default. §3 of the plan.
 *
 * The pop-up keeps its other job when the language half falls away: it still carries
 * the age acknowledgement, so it does not disappear along with the languages.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Language_Switcher
 */
class Nera_SAW_Language_Switcher {

	/**
	 * Remembers that the pop-up has been answered.
	 */
	const COOKIE = 'nera_saw_entry';

	/**
	 * How long that answer stands. Answer 4 in §8: until the cookie is cleared or
	 * the player logs out.
	 */
	const COOKIE_LIFE = YEAR_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! Nera_SAW_Mode::is_standalone() ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'remember_entry' ), 20 );
	}

	/**
	 * Record the pop-up's answer, then send the player back to the page they were on.
	 *
	 * A redirect rather than rendering the page directly, so the address bar ends up
	 * holding the language the player chose. Without it the chosen language would be
	 * invisible in the URL and unlinkable.
	 */
	public static function remember_entry() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['saw_entry'] ) ) {
			return;
		}

		/*
		 * `saw_entry` alone is not an answer — it is also the query arg the
		 * language links (entry_url()) and the already-verified "Continue"
		 * link carry, and both of those are reachable by anyone who copies or
		 * guesses the URL, not only by a visitor who actually ticked the 18+
		 * box. Recording entry on `saw_entry`'s presence alone let a bare
		 * `?saw_entry=1` admit an unverified visitor with no age answer on
		 * record at all — this checks for the real answer behind it: either
		 * the checkbox form's own `saw_over18=1`, or a `nera-age-shield`
		 * verification already on this account (the same source the "already
		 * verified" branch of the gate itself trusts, see age_state()).
		 */
		$ticked   = isset( $_GET['saw_over18'] ) && '1' === $_GET['saw_over18'];
		$verified = self::age_state()['verified'];
		if ( ! $ticked && ! $verified ) {
			return;
		}
		// phpcs:enable

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				'1',
				array(
					'expires'  => time() + self::COOKIE_LIFE,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}

		$_COOKIE[ self::COOKIE ] = '1';
	}

	/**
	 * Has this player already been through the pop-up?
	 *
	 * @return bool
	 */
	public static function entered() {
		return ! empty( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Should the pop-up be shown on this screen?
	 *
	 * @return bool
	 */
	public static function gate_due() {
		if ( ! Nera_SAW_Mode::is_standalone() || is_admin() ) {
			return false;
		}

		if ( ! class_exists( 'Nera_SAW_Router' ) || ! Nera_SAW_Router::is_standalone_screen() ) {
			return false;
		}

		return ! self::entered();
	}

	/**
	 * Are there languages to offer?
	 *
	 * @return bool
	 */
	public static function offering_languages() {
		return class_exists( 'Nera_SAW_Language' ) && Nera_SAW_Language::is_multilingual();
	}

	/**
	 * Every language, as something to render.
	 *
	 * @return array<int, array{code: string, name: string, url: string, current: bool}>
	 */
	public static function options() {
		if ( ! self::offering_languages() ) {
			return array();
		}

		$current = Nera_SAW_Language::current();
		$out     = array();

		foreach ( Nera_SAW_Language::codes() as $code ) {
			$out[] = array(
				'code'    => $code,
				'name'    => Nera_SAW_Language::name( $code ),
				'url'     => self::url_for( $code ),
				'current' => $code === $current,
			);
		}

		return $out;
	}

	/**
	 * This page, in another language.
	 *
	 * Polylang's own translation of the current page when there is one — that is the
	 * address a reader wants, and the one a search engine should be given. When there
	 * is not, the same page carrying an explicit request, which `Nera_SAW_Language`
	 * reads first. Falling back to the section's front page would lose the player's
	 * place for no reason.
	 *
	 * @param string $code Language to switch to.
	 * @return string
	 */
	public static function url_for( $code ) {
		$code = sanitize_key( (string) $code );

		if ( function_exists( 'pll_get_post' ) ) {
			$queried = get_queried_object_id();
			if ( $queried ) {
				$twin = (int) pll_get_post( $queried, $code );
				if ( $twin && 'publish' === get_post_status( $twin ) ) {
					/*
					 * Not a bare `get_permalink()` return: that call runs through
					 * `user_trailingslashit` on its way out, which is exactly where
					 * `Nera_SAW_Language::carry_current_language()` stamps the
					 * CURRENT request's language onto any section link that does
					 * not already carry one — and this twin permalink never did,
					 * so switching away from a non-default language (e.g. RU back
					 * to EN) got the current, wrong language re-stamped onto the
					 * very link meant to escape it, silently undoing the switch.
					 * Found by clicking "EN" while on a RU page whose Polylang
					 * translation exists: the resulting href still read
					 * `?saw_lang=ru`. `add_query_arg()` here replaces rather than
					 * duplicates an existing key, so this wins regardless of
					 * whether that stamping already happened inside
					 * `get_permalink()` itself.
					 */
					return add_query_arg( Nera_SAW_Language::SWITCH_ARG, $code, get_permalink( $twin ) );
				}
			}
		}

		$here = home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' );

		return add_query_arg( Nera_SAW_Language::SWITCH_ARG, $code, $here );
	}

	/**
	 * The current request's URL, unchanged.
	 *
	 * The one place both `entry_url()` and the gate's GET-form action need: a page
	 * identified by its regex route (e.g. `/strikeawin/competition/{slug}/`) has no
	 * `page_id` to fall back on, so the request's own path is the only thing that
	 * reliably gets the player back to it.
	 *
	 * @return string
	 */
	public static function current_url() {
		return home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' );
	}

	/**
	 * The address that dismisses the pop-up, in a given language.
	 *
	 * @param string $code Language chosen, or '' to keep the current one.
	 * @return string
	 */
	public static function entry_url( $code = '' ) {
		$url = '' !== $code ? self::url_for( $code ) : home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' );

		return add_query_arg( 'saw_entry', '1', $url );
	}

	/**
	 * The hidden fields that bring the player back to the page they were on.
	 *
	 * The gate posts with GET so the answer lands in the address, which means the
	 * form's action replaces the current URL entirely — without these the player is
	 * returned to the site's front page instead of the screen they were reading.
	 *
	 * @return array<string, string>
	 */
	public static function gate_return_fields() {
		$fields = array();

		$queried = get_queried_object_id();
		if ( $queried && is_page() ) {
			$fields['page_id'] = (string) $queried;
		}

		$current = class_exists( 'Nera_SAW_Language' ) ? Nera_SAW_Language::current() : '';
		if ( '' !== $current ) {
			$fields[ Nera_SAW_Language::SWITCH_ARG ] = $current;
		}

		return $fields;
	}

	/**
	 * Does the age question need asking, and is anything recording the answer?
	 *
	 * Answers 3 and 5 in §8: follow `nera-age-shield-plugin` when it is there, and
	 * show the 18+ line as a self-declaration when it is not.
	 *
	 * @return array{ask: bool, verified: bool, recorded: bool}
	 */
	public static function age_state() {
		// The age-shield plugin's storage class, checked by name because the plugin is
		// optional and this must not assume it is installed.
		$recorded = class_exists( 'Nera_DCMS_Storage' ) && method_exists( 'Nera_DCMS_Storage', 'is_verified' );
		$verified = false;

		if ( $recorded && is_user_logged_in() ) {
			$verified = (bool) Nera_DCMS_Storage::is_verified( get_current_user_id() );
		}

		return array(
			'ask'      => ! $verified,
			'verified' => $verified,
			'recorded' => $recorded,
		);
	}
}
