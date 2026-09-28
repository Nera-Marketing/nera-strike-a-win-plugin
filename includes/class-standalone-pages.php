<?php
/**
 * Standalone screens as real WordPress pages.
 *
 * Each screen is a page an administrator opens, edits and previews like any
 * other, with the plugin supplying the template and a fixed set of ACF fields.
 *
 * WHY PAGES, GIVEN THE OBVIOUS OBJECTION
 * --------------------------------------
 * A page can be renamed, trashed, or filled with a page builder — which is why
 * the first cut of this section used rewrite rules the plugin owned outright.
 * The trade that reverses it is content: everything on these screens is copy
 * somebody needs to change without a developer, and an editable page is the one
 * place in WordPress where people already know how to do that.
 *
 * The objection is answered rather than ignored:
 *
 *   - a missing page is recreated on the next admin request, so deleting one is
 *     an inconvenience rather than a broken section;
 *   - the template is owned here, not by the theme, so the layout survives a
 *     theme change even though the page does not;
 *   - the fields are registered in PHP, so they are versioned with the plugin and
 *     cannot be edited into a different shape from the ACF admin.
 *
 * The section's URL is the root page's slug. Changing the address is therefore
 * editing that page, which is where an administrator would look for it anyway.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Standalone_Pages
 */
class Nera_SAW_Standalone_Pages {

	/**
	 * Option holding route => page ID.
	 */
	const OPTION = 'nera_saw_standalone_pages';

	/**
	 * Meta marking a page as one of ours, so a page an administrator later
	 * repurposes can be told apart from one the plugin made.
	 */
	const META = '_nera_saw_route';

	/**
	 * Template prefix. The value stored in `_wp_page_template`.
	 */
	const TEMPLATE_PREFIX = 'nera-saw-';

	/**
	 * The screens, in the order they are created.
	 *
	 * `parent` names the route a page hangs under, which is what produces
	 * /strikeawin/how-it-works/ from a page called "How it works".
	 *
	 * @return array
	 */
	public static function registry() {
		return apply_filters(
			'nera_saw_standalone_pages',
			array(
				'competitions' => array(
					'title'    => __( 'Competitions', 'nera-strikeawin' ),
					'slug'     => 'strikeawin',
					'template' => 'competitions.php',
					'parent'   => '',
				),
				'how-it-works' => array(
					'title'    => __( 'How it works', 'nera-strikeawin' ),
					'slug'     => 'how-it-works',
					'template' => 'how-it-works.php',
					'parent'   => 'competitions',
				),
				'walkthrough'    => array(
					'title'    => __( 'Quick walkthrough', 'nera-strikeawin' ),
					'slug'     => 'walkthrough',
					'template' => 'walkthrough.php',
					'parent'   => 'competitions',
				),
				'before-you-pay' => array(
					'title'    => __( 'Before you pay', 'nera-strikeawin' ),
					'slug'     => 'before-you-pay',
					'template' => 'before-you-pay.php',
					'parent'   => 'competitions',
				),
				'play'           => array(
					'title'    => __( 'Play Strike A Win', 'nera-strikeawin' ),
					'slug'     => 'play',
					'template' => 'play.php',
					'parent'   => 'competitions',
					// The same shortcode the mix-mode play page carries: one run engine,
					// one app, two frames.
					'content'  => '<!-- wp:shortcode -->[strikeawin_quiz]<!-- /wp:shortcode -->',
				),
				'checkout'       => array(
					'title'    => __( 'Checkout', 'nera-strikeawin' ),
					'slug'     => 'checkout',
					'template' => 'checkout.php',
					'parent'   => 'competitions',
					// The shortcode is the page's content on purpose: WooCommerce's own
					// checkout, rendered by WooCommerce, on a page the section owns. The
					// main site's checkout is left exactly as it was.
					'content'  => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
				),
				'my-account'     => array(
					'title'    => __( 'My account', 'nera-strikeawin' ),
					'slug'     => 'my-account',
					'template' => 'my-account.php',
					'parent'   => 'competitions',
					// Same reasoning as checkout above: WooCommerce's own account
					// shortcode — login, register, orders, view-order, edit-account, the
					// lot — rendered by WooCommerce, on a page the section owns.
					// is_account_page() auto-detects this shortcode in the post content
					// (wc_post_content_has_shortcode()), so every plugin already keyed on
					// "is this the account page" (self-exclusion, spending limits, the
					// age gate) keeps working here with no extra wiring. See
					// Nera_SAW_Standalone_Account for the endpoint-URL and
					// post-login-redirect fixes WooCommerce's own account page does not
					// need, because it does not have a second address to stay inside of.
					'content'  => '<!-- wp:shortcode -->[woocommerce_my_account]<!-- /wp:shortcode -->',
				),
			)
		);
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		// Registered in every mode: a site that switches to standalone should find
		// its pages already there, and the template must keep rendering for anyone
		// who opens the page after switching back.
		add_filter( 'theme_page_templates', array( __CLASS__, 'offer_templates' ) );
		add_filter( 'template_include', array( __CLASS__, 'use_template' ), 98 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'ensure' ) );
			add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Creating and finding
	 * ------------------------------------------------------------------ */

	/**
	 * Create any page that is missing, and repair the stored map.
	 *
	 * Safe to call repeatedly. A page that exists is left completely alone — its
	 * title, slug, content and menu order are the administrator's.
	 */
	public static function ensure() {
		$map     = (array) get_option( self::OPTION, array() );
		$changed = false;

		foreach ( self::registry() as $route => $screen ) {
			$id = isset( $map[ $route ] ) ? (int) $map[ $route ] : 0;

			if ( $id && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
				continue;
			}

			// Adopt a page that already carries our marker before making another.
			// Trash is included on purpose: a trashed page still holds whatever copy
			// an administrator wrote, so restoring it is better than replacing it,
			// and it avoids the duplicate that appears when they later empty the
			// trash's "Restore" instead.
			$found = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $route,     // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			if ( $found ) {
				$id = (int) $found[0];
				if ( 'trash' === get_post_status( $id ) ) {
					wp_untrash_post( $id );
					// wp_untrash_post() restores to 'draft' on modern WordPress, which
					// would leave the section's own page unreachable.
					wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
				}
			} else {
				$id = self::create( $route, $screen, $map );
			}

			if ( $id ) {
				$map[ $route ] = $id;
				$changed       = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $map );
			Nera_SAW_Router::request_flush();
		}
	}

	/**
	 * Insert one page.
	 *
	 * @param string $route  Route key.
	 * @param array  $screen Registry entry.
	 * @param array  $map    Route => ID, for resolving a parent.
	 * @return int Page ID, or 0.
	 */
	private static function create( $route, array $screen, array $map ) {
		$parent = 0;
		if ( ! empty( $screen['parent'] ) && ! empty( $map[ $screen['parent'] ] ) ) {
			$parent = (int) $map[ $screen['parent'] ];
		}

		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => $screen['title'],
				'post_name'      => $screen['slug'],
				'post_parent'    => $parent,
				'post_content'   => isset( $screen['content'] ) ? (string) $screen['content'] : '',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		update_post_meta( $id, '_wp_page_template', self::TEMPLATE_PREFIX . $screen['template'] );
		update_post_meta( $id, self::META, $route );

		return (int) $id;
	}

	/**
	 * The page ID for a route.
	 *
	 * @param string $route Route key.
	 * @return int
	 */
	public static function page_id( $route ) {
		$map = (array) get_option( self::OPTION, array() );
		return isset( $map[ $route ] ) ? (int) $map[ $route ] : 0;
	}

	/**
	 * The route a page serves, or '' if it is not one of ours.
	 *
	 * @param int $page_id Page ID.
	 * @return string
	 */
	public static function route_of( $page_id ) {
		return (string) get_post_meta( (int) $page_id, self::META, true );
	}

	/**
	 * Permalink for a route, falling back to the plugin's own URL builder when the
	 * page is missing — so a link never renders empty while a page is being
	 * recreated.
	 *
	 * @param string $route Route key.
	 * @return string
	 */
	public static function url( $route ) {
		$id = self::page_id( $route );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Templates
	 * ------------------------------------------------------------------ */

	/**
	 * Offer the plugin's templates in Page Attributes.
	 *
	 * Listed for every page, not only ours, because an administrator may well want
	 * the competitions layout on a page they made themselves — and forbidding that
	 * would mean the only way to have two is to edit code.
	 *
	 * @param array $templates Existing templates.
	 * @return array
	 */
	public static function offer_templates( $templates ) {
		foreach ( self::registry() as $screen ) {
			$templates[ self::TEMPLATE_PREFIX . $screen['template'] ] = sprintf(
				/* translators: %s: screen name */
				__( 'Strike A Win — %s', 'nera-strikeawin' ),
				$screen['title']
			);
		}
		return $templates;
	}

	/**
	 * Load the plugin's file for a page using one of its templates.
	 *
	 * Priority 98 — before the router's dynamic routes at 99, so a rewrite route
	 * still wins where both could apply.
	 *
	 * @param string $template Template WordPress resolved.
	 * @return string
	 */
	public static function use_template( $template ) {
		if ( ! is_page() ) {
			return $template;
		}

		$assigned = (string) get_post_meta( get_queried_object_id(), '_wp_page_template', true );
		if ( 0 !== strpos( $assigned, self::TEMPLATE_PREFIX ) ) {
			return $template;
		}

		$file  = substr( $assigned, strlen( self::TEMPLATE_PREFIX ) );
		$found = Nera_SAW_Router::locate( $file );

		return $found ? $found : $template;
	}

	/**
	 * Label our pages in the Pages list, so nobody trashes one by accident.
	 *
	 * @param array   $states Existing states.
	 * @param WP_Post $post   Page.
	 * @return array
	 */
	public static function post_state( $states, $post ) {
		if ( 'page' === $post->post_type && self::route_of( $post->ID ) ) {
			$states['nera_saw'] = __( 'Strike A Win', 'nera-strikeawin' );
		}
		return $states;
	}
}
