<?php
/**
 * Play page manager: the single WordPress page that hosts the [strikeawin_quiz]
 * shortcode, plus URL resolution for every link/button/tag that sends a player
 * into a run.
 *
 * The page is auto-created on activation (if one does not already exist) and can
 * be overridden from Strike A Win → Settings. All callers resolve the play URL
 * through nera_saw_get_play_url() so competition/tier deep links are consistent.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Play_Page
 */
class Nera_SAW_Play_Page {

	/**
	 * Option storing the play page ID.
	 */
	const OPTION = 'nera_saw_play_page_id';

	/**
	 * Query var: competition (product) ID to play.
	 */
	const QV_COMPETITION = 'saw_competition';

	/**
	 * Query var: tier key to preselect.
	 */
	const QV_TIER = 'saw_tier';

	/**
	 * Default page title / slug used when auto-creating.
	 */
	const DEFAULT_TITLE = 'Play Strike A Win';
	const DEFAULT_SLUG  = 'play-strike-a-win';

	/**
	 * Quiz shortcode tag. Mirrors self::SHORTCODE but declared here
	 * so the page can be created at activation before the frontend class loads.
	 */
	const SHORTCODE = 'strikeawin_quiz';

	/**
	 * Resolve the configured play page ID, creating the page if none exists yet.
	 * Safe to call on the front end: creation only happens once, then the option
	 * short-circuits every later call.
	 *
	 * @return int Page ID (0 if creation failed).
	 */
	public static function ensure_page() {
		$page_id = (int) get_option( self::OPTION, 0 );
		if ( $page_id > 0 && self::is_valid_page( $page_id ) ) {
			return $page_id;
		}

		// Adopt an existing page that already contains the shortcode.
		$existing = self::find_page_with_shortcode();
		if ( $existing > 0 ) {
			update_option( self::OPTION, $existing );
			return $existing;
		}

		// Create a fresh page with the shortcode.
		$page_id = wp_insert_post(
			array(
				'post_title'   => self::DEFAULT_TITLE,
				'post_name'    => self::DEFAULT_SLUG,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '[' . self::SHORTCODE . ']',
			)
		);
		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return 0;
		}
		update_option( self::OPTION, (int) $page_id );
		return (int) $page_id;
	}

	/**
	 * The configured play page ID (without forcing creation), or 0.
	 *
	 * @return int
	 */
	public static function get_page_id() {
		$page_id = (int) get_option( self::OPTION, 0 );
		if ( $page_id > 0 && self::is_valid_page( $page_id ) ) {
			return $page_id;
		}
		return 0;
	}

	/**
	 * Persist the admin-selected play page.
	 *
	 * @param int $page_id Page ID (0 clears the override, reverting to auto).
	 */
	public static function set_page_id( $page_id ) {
		$page_id = (int) $page_id;
		if ( $page_id > 0 && self::is_valid_page( $page_id ) ) {
			update_option( self::OPTION, $page_id );
			return;
		}
		delete_option( self::OPTION );
	}

	/**
	 * Build a play URL, optionally deep-linking a competition + tier.
	 *
	 * @param int    $competition_id Competition product ID (0 = hub/no preselect).
	 * @param string $tier_key       Tier key to preselect (optional).
	 * @return string URL ('' if the page cannot be resolved).
	 */
	public static function url( $competition_id = 0, $tier_key = '' ) {
		$page_id = self::get_page_id();
		if ( $page_id < 1 ) {
			$page_id = self::ensure_page();
		}
		if ( $page_id < 1 ) {
			return '';
		}
		$base = get_permalink( $page_id );
		if ( ! $base ) {
			return '';
		}

		$args = array();
		if ( (int) $competition_id > 0 ) {
			$args[ self::QV_COMPETITION ] = (int) $competition_id;
		}
		if ( '' !== (string) $tier_key ) {
			$args[ self::QV_TIER ] = sanitize_key( $tier_key );
		}

		return empty( $args ) ? $base : add_query_arg( $args, $base );
	}

	/**
	 * Whether a post ID is a usable published/private page.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	private static function is_valid_page( $page_id ) {
		$post = get_post( (int) $page_id );
		return $post
			&& 'page' === $post->post_type
			&& in_array( $post->post_status, array( 'publish', 'private' ), true );
	}

	/**
	 * Find a published page whose content contains the quiz shortcode.
	 *
	 * @return int Page ID (0 if none).
	 */
	private static function find_page_with_shortcode() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				's'              => '[' . self::SHORTCODE . ']',
			)
		);
		return empty( $pages ) ? 0 : (int) $pages[0];
	}
}

if ( ! function_exists( 'nera_saw_get_play_url' ) ) {
	/**
	 * Resolve the Strike A Win play URL for a competition + tier.
	 *
	 * @param int    $competition_id Competition product ID (0 = no preselect).
	 * @param string $tier_key       Tier key (optional).
	 * @return string
	 */
	function nera_saw_get_play_url( $competition_id = 0, $tier_key = '' ) {
		return Nera_SAW_Play_Page::url( $competition_id, $tier_key );
	}
}
