<?php
/**
 * Five legal/help screens as children of the standalone section (client
 * findings #4/#9/#48): Terms and Conditions, Privacy Policy, Complaints,
 * Help and support, and Our commitment to player protection — each
 * reachable at `/strikeawin/<slug>/`, in the section's own skin, instead
 * of the stock theme's.
 *
 * Content stays wherever it already lives and is already edited — this
 * class never forks a copy of anything:
 *   - Terms and Privacy Policy mirror the main site's own real page (its
 *     `post_content`, live, every request) — an administrator keeps
 *     editing that same page in wp-admin exactly as before. See
 *     `templates/standalone/legal-terms.php` / `legal-privacy.php`.
 *   - Complaints, Help and support, and Our commitment to player
 *     protection call straight into the sibling plugin that already owns
 *     that content — the same pattern
 *     `templates/woocommerce/myaccount/responsible-play.php` already uses
 *     for two OTHER sibling plugins (self-exclusion, spending limit).
 *     Admin keeps editing each one's own settings screen. See
 *     `templates/standalone/legal-complaints.php` / `legal-help.php` /
 *     `legal-player-protection.php`.
 *
 * Every row is OPTIONAL, not assumed: the three plugin-backed screens
 * register only when that exact plugin is active (`class_exists()`
 * below), and Terms/Privacy register only when the page they mirror
 * actually exists. Deactivating a sibling plugin makes its standalone row
 * quietly disappear on the next `Nera_SAW_Standalone_Pages::ensure()`
 * run, rather than leaving a broken page behind.
 *
 * Nothing here edits a sibling plugin's own files — only this plugin's
 * own filter (`nera_saw_standalone_pages`) and, for Complaints' own CSS
 * timing quirk, an early hook on a stock WordPress action this plugin is
 * equally entitled to use.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Standalone_Legal_Pages
 */
class Nera_SAW_Standalone_Legal_Pages {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'nera_saw_standalone_pages', array( __CLASS__, 'add_pages' ) );

		// Priority 5: ahead of nera-complaints-plugin's own `wp_enqueue_scripts`
		// callback (and well ahead of `wp_head()`'s `wp_print_styles()` call),
		// so the style this forces onto the queue is still there to print —
		// see maybe_enqueue_complaints_css()'s own docblock for why that
		// plugin's usual, page-ID-gated enqueue never fires here at all.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_complaints_css' ), 5 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'ensure_ru_translations' ) );
		}
	}

	/**
	 * Add this class's rows to the standalone page registry — each one
	 * only once its own content source is actually available.
	 *
	 * @param array $pages Existing registry (route => screen definition).
	 * @return array
	 */
	public static function add_pages( array $pages ) {
		if ( get_page_by_path( 'terms-and-conditions' ) ) {
			$pages['terms-and-conditions'] = array(
				'title'    => __( 'Terms and Conditions', 'nera-strikeawin' ),
				'slug'     => 'terms-and-conditions',
				'template' => 'legal-terms.php',
				'parent'   => 'competitions',
			);
		}

		// WordPress's own "Privacy Policy" setting, not a hardcoded slug —
		// whichever real page an admin has assigned there is the one
		// mirrored, the same source `wp_privacy_policy_link()` and every
		// core privacy-policy admin notice already read.
		if ( get_option( 'wp_page_for_privacy_policy' ) ) {
			$pages['privacy-policy'] = array(
				'title'    => __( 'Privacy Policy', 'nera-strikeawin' ),
				'slug'     => 'privacy-policy',
				'template' => 'legal-privacy.php',
				'parent'   => 'competitions',
			);
		}

		if ( class_exists( 'Nera_CMP_Shortcodes' ) ) {
			$pages['complaints'] = array(
				'title'    => __( 'Complaints', 'nera-strikeawin' ),
				'slug'     => 'complaints',
				'template' => 'legal-complaints.php',
				'parent'   => 'competitions',
			);
		}

		if ( class_exists( 'Nera_RP_Page' ) ) {
			$pages['help-and-support'] = array(
				'title'    => __( 'Help and support', 'nera-strikeawin' ),
				'slug'     => 'help-and-support',
				'template' => 'legal-help.php',
				'parent'   => 'competitions',
			);
		}

		if ( class_exists( 'Nera_VC_Page' ) ) {
			$pages['player-protection'] = array(
				'title'    => __( 'Our commitment to player protection', 'nera-strikeawin' ),
				'slug'     => 'our-commitment-to-player-protection',
				'template' => 'legal-player-protection.php',
				'parent'   => 'competitions',
			);
		}

		return $pages;
	}

	/**
	 * nera-complaints-plugin's own `Nera_CMP_Assets::enqueue_styles()` is a
	 * force-enqueue (bypasses its usual page-ID gate), but it only ever
	 * runs from INSIDE `Nera_CMP_Shortcodes::render_page()` — called from
	 * this screen's own template body, which executes well after
	 * `Nera_SAW_Router::part( 'header.php', ... )` has already called
	 * `wp_head()`. `wp_enqueue_style()` after `wp_head()` has already fired
	 * `wp_print_styles()` queues a style that is never printed — there is
	 * no second print call in `wp_footer()`. So the complaints screen would
	 * silently ship with no `nera-cmp` CSS at all, gate or no gate.
	 *
	 * Calling the SAME public method here instead, on the standard
	 * `wp_enqueue_scripts` action (before `wp_head()` runs), fixes it with
	 * no edit to that plugin's own files.
	 */
	public static function maybe_enqueue_complaints_css() {
		if ( ! class_exists( 'Nera_CMP_Assets' ) || ! method_exists( 'Nera_CMP_Assets', 'enqueue_styles' ) ) {
			return;
		}
		$id = Nera_SAW_Standalone_Pages::page_id( 'complaints' );
		if ( $id && is_page( $id ) ) {
			Nera_CMP_Assets::enqueue_styles();
		}
	}

	/**
	 * Create a Russian Polylang translation of the mirrored Terms and
	 * Privacy Policy pages, once, from a machine-translated starting file —
	 * never a copy baked into this plugin's own code.
	 *
	 * Terms/Privacy are real WordPress pages an administrator edits
	 * directly in wp-admin (see `templates/standalone/legal-terms.php`'s own
	 * docblock) — there is no per-plugin string table to hook the way the
	 * other three screens are translated. Polylang, already relied on
	 * elsewhere in this plugin (`Nera_SAW_Language::set_for_post()`, the
	 * seeded question bank), is this site's own existing mechanism for a
	 * second real page in a second language, so a translation is created as
	 * one: a normal, Polylang-linked `page` post the admin can keep editing
	 * like any other, not a second copy this class re-generates each run.
	 *
	 * Runs once per source page, ever: if a Russian translation is already
	 * linked (`pll_get_post()` returns something), this leaves it
	 * completely alone — same "existing content is the administrator's"
	 * rule `Nera_SAW_Standalone_Pages::ensure()` follows for the pages
	 * themselves. The seed text comes from `languages/ru/legal-terms-
	 * content.html` / `legal-privacy-content.html` — a first-pass machine
	 * translation, pending the client's own legal review on staging.
	 */
	public static function ensure_ru_translations() {
		if ( ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_save_post_translations' ) ) {
			return;
		}
		if ( ! class_exists( 'Nera_SAW_Language' ) || ! Nera_SAW_Language::has( 'ru' ) ) {
			return;
		}

		self::ensure_ru_translation(
			get_page_by_path( 'terms-and-conditions' ),
			'Условия использования (RU)',
			'terms-and-conditions-ru',
			NERA_SAW_PLUGIN_DIR . 'languages/ru/legal-terms-content.html'
		);

		$privacy_id = (int) get_option( 'wp_page_for_privacy_policy' );
		self::ensure_ru_translation(
			$privacy_id ? get_post( $privacy_id ) : null,
			'Политика конфиденциальности (RU)',
			'privacy-policy-ru',
			NERA_SAW_PLUGIN_DIR . 'languages/ru/legal-privacy-content.html'
		);
	}

	/**
	 * Create and link one page's Russian translation, if it does not
	 * already have one.
	 *
	 * @param WP_Post|null $source     The English (or site-default-language) page to translate.
	 * @param string       $post_title Title for the new Russian page.
	 * @param string       $slug       Slug for the new Russian page.
	 * @param string       $content_file Path to the seed `post_content` HTML.
	 */
	private static function ensure_ru_translation( $source, $post_title, $slug, $content_file ) {
		if ( ! $source instanceof WP_Post ) {
			return;
		}
		if ( function_exists( 'pll_get_post' ) ) {
			$existing = pll_get_post( $source->ID, 'ru' );
			if ( $existing ) {
				return; // Already linked — an administrator may have taken it over since.
			}
		}
		if ( ! is_readable( $content_file ) ) {
			return;
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $post_title,
				'post_name'    => $slug,
				'post_content' => (string) file_get_contents( $content_file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file, not a remote URL.
			),
			true
		);
		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return;
		}

		Nera_SAW_Language::set_for_post( $new_id, 'ru' );

		$source_lang = Nera_SAW_Language::for_post( $source->ID );
		if ( '' === $source_lang && function_exists( 'pll_default_language' ) ) {
			$source_lang = (string) pll_default_language();
		}
		if ( '' !== $source_lang ) {
			pll_save_post_translations(
				array(
					$source_lang => $source->ID,
					'ru'         => $new_id,
				)
			);
		}
	}
}
