<?php
/**
 * How far the site's languages are served — step L2 of
 * [docs/LANGUAGE-PLAN.md](../docs/LANGUAGE-PLAN.md).
 *
 * Polylang owns which languages exist. This owns how far they reach: the whole site,
 * or the Strike A Win section only. The control lives inside Polylang's own settings
 * screen rather than in this plugin's, because that is where an administrator goes
 * when they are thinking about languages — see
 * [ADR 0028](../docs/adr/0028-polylang-owns-the-languages-strike-a-win-owns-the-reach.md).
 *
 * FRONT END ONLY
 * --------------
 * Neither setting touches the admin. Language columns, filters and the per-post
 * language picker keep working in both. An editor translating main-site pages behind
 * a one-language front end is a site being prepared, not a broken one — §2 of the
 * plan.
 *
 * THE PART THAT IS EASY TO MISS
 * -----------------------------
 * Holding the main site to one language is not only a matter of hiding the switcher.
 * A `/ru/` address still routes, and left alone it renders a second copy of an
 * English page — duplicate content that looks fine until somebody finds the URL. So
 * `redirect_stray_language()` sends those back to the canonical page. §8.1 of the
 * plan makes this non-optional: ticking `product` in Polylang pulls the whole
 * catalogue in, not only the competitions.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Language_Reach
 */
class Nera_SAW_Language_Reach {

	/**
	 * Where the choice is kept. Ours, not Polylang's — Polylang's options are typed
	 * and validated against its own schema, and an unknown key does not belong there.
	 */
	const OPTION = 'nera_saw_language_reach';

	/**
	 * Polylang behaves normally, everywhere.
	 */
	const WHOLE_SITE = 'site';

	/**
	 * The section is multilingual; the main site front end is held to one language.
	 */
	const SECTION_ONLY = 'section';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'pll_settings_modules', array( __CLASS__, 'register_module' ) );

		// Front-end reach. Each of these is a no-op under "whole site".
		add_filter( 'pll_the_languages', array( __CLASS__, 'hide_switcher_off_section' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_stray_language' ), 1 );
	}

	/**
	 * The current reach.
	 *
	 * Defaults to the whole site, which is what Polylang does on its own — so an
	 * install that never opens this control behaves exactly as it did before this
	 * plugin was there.
	 *
	 * @return string
	 */
	public static function reach() {
		$value = get_option( self::OPTION, self::WHOLE_SITE );

		return self::SECTION_ONLY === $value ? self::SECTION_ONLY : self::WHOLE_SITE;
	}

	/**
	 * Is the main site front end being held to one language?
	 *
	 * @return bool
	 */
	public static function section_only() {
		return self::SECTION_ONLY === self::reach();
	}

	/**
	 * Add the card to Polylang's settings screen.
	 *
	 * The class is declared only here, because it extends a Polylang class that does
	 * not exist anywhere else — requiring the file at boot would be a fatal on any
	 * site without Polylang, which is most of them.
	 *
	 * @param array $modules Module class names.
	 * @return array
	 */
	public static function register_module( $modules ) {
		if ( ! class_exists( 'PLL_Settings_Module' ) ) {
			return $modules;
		}

		require_once NERA_SAW_PLUGIN_DIR . 'includes/admin/class-polylang-reach-module.php';

		$modules[] = 'Nera_SAW_Polylang_Reach_Module';

		return $modules;
	}

	/**
	 * Does this request belong to the Strike A Win section?
	 *
	 * @return bool
	 */
	public static function on_section() {
		if ( ! class_exists( 'Nera_SAW_Mode' ) || ! Nera_SAW_Mode::is_standalone() ) {
			return false;
		}

		return class_exists( 'Nera_SAW_Router' ) && Nera_SAW_Router::is_standalone_screen();
	}

	/**
	 * Take the language switcher off main-site requests.
	 *
	 * Polylang builds every switcher through this filter, including the widget, the
	 * nav-menu item and `pll_the_languages()` called by a theme. Returning nothing is
	 * what "held to one language" looks like to a visitor.
	 *
	 * @param array $languages The switcher's entries.
	 * @param array $args      Switcher arguments.
	 * @return array
	 */
	public static function hide_switcher_off_section( $languages, $args = array() ) {
		unset( $args );

		if ( is_admin() || ! self::section_only() || self::on_section() ) {
			return $languages;
		}

		return array();
	}

	/**
	 * Send a non-default-language main-site URL back to the canonical page.
	 *
	 * Without this the `/ru/` address of a main-site page renders a second copy of it
	 * — the same content on two addresses, which is the definition of duplicate
	 * content. A 301 says the page moved rather than that it is gone, which is the
	 * accurate thing to tell both a visitor and a crawler.
	 */
	public static function redirect_stray_language() {
		if ( is_admin() || wp_doing_ajax() || ! self::section_only() || self::on_section() ) {
			return;
		}

		if ( ! class_exists( 'Nera_SAW_Language' ) || ! Nera_SAW_Language::engine_present() ) {
			return;
		}

		$default = Nera_SAW_Language::default_code();
		$current = function_exists( 'pll_current_language' ) ? (string) pll_current_language( 'slug' ) : '';

		if ( '' === $current || $current === $default ) {
			return;
		}

		$canonical = '';

		$queried = get_queried_object_id();
		if ( $queried && function_exists( 'pll_get_post' ) ) {
			$twin = (int) pll_get_post( $queried, $default );
			if ( $twin ) {
				$canonical = (string) get_permalink( $twin );
			}
		}

		if ( '' === $canonical && function_exists( 'pll_home_url' ) ) {
			$canonical = (string) pll_home_url( $default );
		}

		if ( '' === $canonical ) {
			return;
		}

		wp_safe_redirect( $canonical, 301 );
		exit;
	}
}
