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
}
