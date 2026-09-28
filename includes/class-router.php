<?php
/**
 * Standalone routing — URLs, and the templates behind them.
 *
 * In standalone mode the plugin serves its own section of the site under one
 * configurable prefix. Every route resolves to a template inside the plugin, and
 * the active theme is bypassed entirely: the section is meant to look like the
 * prototype on any site the plugin ships to, including ones whose theme knows
 * nothing about it.
 *
 * WHY REWRITE RULES RATHER THAN PAGES
 * -----------------------------------
 * The play page is a real WordPress page holding a shortcode, which works because
 * there is one of it. A dozen of them — list, detail per competition, how it
 * works, four walkthrough cards, login, register, account — would be a dozen rows
 * an administrator can rename, move to the trash, or fill with a page builder.
 * Rules are owned by the plugin and cannot be edited out from under it.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Router
 */
class Nera_SAW_Router {

	/**
	 * Query var naming which standalone route is being served.
	 */
	const QV_ROUTE = 'saw_route';

	/**
	 * Query var carrying a route's argument — a competition slug, mostly.
	 */
	const QV_ARG = 'saw_arg';

	/**
	 * Option holding the URL prefix.
	 */
	const OPTION_PREFIX = 'nera_saw_standalone_prefix';

	/**
	 * Option flag: rules need flushing on the next request.
	 */
	const OPTION_FLUSH = 'nera_saw_flush_rules';

	/**
	 * Default prefix. Changeable in Settings, because a site may already own
	 * /competitions/ or want the section under its own word.
	 */
	const DEFAULT_PREFIX = 'strikeawin';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );

		if ( ! Nera_SAW_Mode::is_standalone() ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'add_rules' ) );
		add_action( 'wp', array( __CLASS__, 'maybe_flush' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'nera_saw_standalone_competition_url', array( __CLASS__, 'competition_url' ), 10, 2 );
		add_filter( 'nera_saw_play_url', array( __CLASS__, 'play_url' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * URLs
	 * ------------------------------------------------------------------ */

	/**
	 * The configured prefix, without slashes.
	 *
	 * @return string
	 */
	public static function prefix() {
		// The root page's slug is the section's address: an administrator who wants
		// the section at /competitions/ renames that page, which is where they
		// would look. The stored option is only the fallback for a site whose root
		// page has not been created yet.
		if ( class_exists( 'Nera_SAW_Standalone_Pages' ) ) {
			$root = Nera_SAW_Standalone_Pages::page_id( 'competitions' );
			if ( $root && 'publish' === get_post_status( $root ) ) {
				$slug = get_post_field( 'post_name', $root );
				if ( $slug ) {
					return $slug;
				}
			}
		}

		$prefix = (string) get_option( self::OPTION_PREFIX, self::DEFAULT_PREFIX );
		$prefix = trim( sanitize_title( $prefix ) );
		return $prefix ? $prefix : self::DEFAULT_PREFIX;
	}

	/**
	 * Absolute URL for a standalone route.
	 *
	 * @param string $route Route name ('' for the section home).
	 * @param string $arg   Optional argument (a competition slug).
	 * @return string
	 */
	public static function url( $route = '', $arg = '' ) {
		// A screen that has a page of its own answers with its own permalink, so
		// renaming the page in the admin moves every link to it. Only the dynamic
		// routes -- a competition, which has no page -- fall through to the rules.
		if ( ! $arg && class_exists( 'Nera_SAW_Standalone_Pages' ) ) {
			$permalink = Nera_SAW_Standalone_Pages::url( $route ? $route : 'competitions' );
			if ( $permalink ) {
				return $permalink;
			}
		}

		$path = self::prefix();
		if ( $route ) {
			$path .= '/' . ltrim( $route, '/' );
		}
		if ( $arg ) {
			$path .= '/' . ltrim( $arg, '/' );
		}
		return home_url( user_trailingslashit( $path ) );
	}

	/**
	 * Where a run is played, in standalone.
	 *
	 * The section's own page, carrying the query arguments the mix-mode page would
	 * have carried — the app reads the same two, so a deep link into a specific
	 * competition and tier keeps working.
	 *
	 * @param string $url            What the play page resolved to.
	 * @param int    $competition_id Competition product ID, or 0.
	 * @param string $tier_key       Tier key, or ''.
	 * @return string
	 */
	public static function play_url( $url, $competition_id = 0, $tier_key = '' ) {
		if ( ! class_exists( 'Nera_SAW_Standalone_Pages' ) ) {
			return $url;
		}

		$page = Nera_SAW_Standalone_Pages::url( 'play' );
		if ( ! $page ) {
			return $url;
		}

		$args = array();
		if ( (int) $competition_id > 0 ) {
			$args[ Nera_SAW_Play_Page::QV_COMPETITION ] = (int) $competition_id;
		}
		if ( '' !== (string) $tier_key ) {
			$args[ Nera_SAW_Play_Page::QV_TIER ] = sanitize_key( $tier_key );
		}

		return $args ? add_query_arg( $args, $page ) : $page;
	}

	/**
	 * Where the walkthrough lives.
	 *
	 * The page wins over the field. Three controls point here — the header button,
	 * the floating button and the button on How it works — and before the page
	 * existed the only way to light them up was to paste a URL into a field. Now the
	 * page answers, and the field stays as an override for a site that hosts its
	 * walkthrough somewhere else.
	 *
	 * Empty means no walkthrough, and every caller hides its control rather than
	 * rendering a link to nothing.
	 *
	 * @return string
	 */
	public static function walkthrough_url() {
		if ( class_exists( 'Nera_SAW_Standalone_Pages' ) ) {
			$page = Nera_SAW_Standalone_Pages::url( 'walkthrough' );
			if ( $page ) {
				return $page;
			}
		}

		if ( ! class_exists( 'Nera_SAW_Standalone_Fields' ) ) {
			return '';
		}

		return (string) Nera_SAW_Standalone_Fields::text(
			'saw_hiw_cta_url',
			'',
			class_exists( 'Nera_SAW_Standalone_Pages' ) ? Nera_SAW_Standalone_Pages::page_id( 'how-it-works' ) : 0
		);
	}

	/**
	 * Where the account screens live.
	 *
	 * WooCommerce's, not one of ours: orders, addresses, downloads and the password
	 * reset flow are all WooCommerce's, and a second account area beside them would
	 * be a second place for a player to look for the same order. What changes in
	 * standalone mode is only the address it opens at — the section's own page,
	 * carrying WooCommerce's account shortcode, so the player never leaves the
	 * section's skin to sign in or see an order.
	 *
	 * @return string Empty when there is nowhere to send the player, so a caller can
	 *                leave the link out rather than render a dead one.
	 */
	public static function account_url() {
		if ( class_exists( 'Nera_SAW_Standalone_Pages' ) ) {
			$url = Nera_SAW_Standalone_Pages::url( 'my-account' );
			if ( $url ) {
				return $url;
			}
		}

		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return '';
		}
		$url = wc_get_page_permalink( 'myaccount' );
		return $url ? (string) $url : '';
	}

	/**
	 * Where a competition lives inside the section.
	 *
	 * Wired to the filter `Nera_SAW_Catalogue_Isolation` consults, so the main
	 * site's product permalink redirects here once these routes exist — and does
	 * not redirect at all before that.
	 *
	 * @param string $url            Incoming (empty).
	 * @param int    $competition_id Competition product ID.
	 * @return string
	 */
	public static function competition_url( $url, $competition_id ) {
		$post = get_post( (int) $competition_id );
		return $post ? self::url( 'competition', $post->post_name ) : $url;
	}

	/* ---------------------------------------------------------------------
	 * Rules
	 * ------------------------------------------------------------------ */

	/**
	 * Register the section's rewrite rules.
	 */
	public static function add_rules() {
		$prefix = preg_quote( self::prefix(), '#' );

		// Most specific first: a two-segment route must not be eaten by the
		// one-segment catch-all below it.
		add_rewrite_rule(
			'^' . $prefix . '/competition/([^/]+)/?$',
			'index.php?' . self::QV_ROUTE . '=competition&' . self::QV_ARG . '=$matches[1]',
			'top'
		);

		/*
		 * No rule for the section root. It is a real page now, and claiming its URL
		 * here would shadow the page an administrator is editing -- they would save
		 * a change, reload, and see the old screen with no explanation.
		 */
	}

	/**
	 * Flush once after the prefix changes or the mode is switched on.
	 *
	 * Flushing on every load is expensive; flushing never leaves a 404 until
	 * somebody visits Permalinks and presses Save, which nobody thinks to do.
	 */
	public static function maybe_flush() {
		if ( ! get_option( self::OPTION_FLUSH ) ) {
			return;
		}
		delete_option( self::OPTION_FLUSH );
		flush_rewrite_rules( false );
	}

	/**
	 * Ask for a flush on the next request.
	 */
	public static function request_flush() {
		update_option( self::OPTION_FLUSH, 1 );
	}

	/**
	 * Register the query vars.
	 *
	 * @param array $vars Existing.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QV_ROUTE;
		$vars[] = self::QV_ARG;
		return $vars;
	}

	/**
	 * Section stylesheet.
	 *
	 * Only on a standalone route: the theme's own pages must not inherit these
	 * tokens, and a site running mix mode never loads this at all.
	 */
	public static function assets() {
		if ( ! self::is_standalone_screen() ) {
			return;
		}

		$saw_css = NERA_SAW_PLUGIN_DIR . 'assets/css/standalone.css';
		wp_enqueue_style(
			'nera-saw-standalone',
			NERA_SAW_PLUGIN_URL . 'assets/css/standalone.css',
			array(),
			// The file's own mtime, not NERA_SAW_VERSION: this stylesheet is
			// edited far more often than the plugin cuts a release, and a stale
			// browser cache between one edit and the next has already been
			// mistaken for the CSS fix not having worked at all.
			file_exists( $saw_css ) ? (string) filemtime( $saw_css ) : NERA_SAW_VERSION
		);

		/*
		 * "Enter now" confirms before it leaves, on the competition screen only.
		 */
		if ( 'competition' === self::current_route() ) {
			wp_enqueue_script(
				'nera-saw-add-to-basket',
				NERA_SAW_PLUGIN_URL . 'assets/js/add-to-basket.js',
				array(),
				NERA_SAW_VERSION,
				true
			);
			wp_localize_script(
				'nera-saw-add-to-basket',
				'neraSawBasket',
				array(
					'ajaxUrl' => function_exists( 'WC_AJAX' ) || class_exists( 'WC_AJAX' )
						? WC_AJAX::get_endpoint( 'add_to_cart' )
						: '',
					'next'    => class_exists( 'Nera_SAW_Standalone_Basket' )
						? Nera_SAW_Standalone_Basket::pre_payment_url()
						: '',
					'added'   => __( 'Entry added. Taking you to Before you pay…', 'nera-strikeawin' ),
				)
			);
		}

		/*
		 * The walkthrough's step-swapping script, and only there. It is an
		 * enhancement over links that already work, so it is never a dependency --
		 * which is also why it loads nowhere else.
		 */
		if ( class_exists( 'Nera_SAW_Standalone_Pages' ) && is_page() ) {
			$walkthrough = Nera_SAW_Standalone_Pages::page_id( 'walkthrough' );
			if ( $walkthrough && (int) $walkthrough === (int) get_queried_object_id() ) {
				wp_enqueue_script(
					'nera-saw-walkthrough',
					NERA_SAW_PLUGIN_URL . 'assets/js/walkthrough.js',
					array(),
					NERA_SAW_VERSION,
					true
				);
			}
		}

		/*
		 * A payment gateway on checkout (found: the "Part wallet, part card"
		 * option) renders its icon as `<span class="material-symbols-outlined">
		 * account_balance_wallet</span>` with no `aria-hidden`, unlike every
		 * other icon on the same screen — the age-verification dialog's own
		 * icons included. It renders correctly (the icon font's ligature turns
		 * it into a glyph), so this is screen-reader-only: without aria-hidden,
		 * the ligature's raw name is read aloud right before the label it
		 * decorates. Not this plugin's own markup and not traced to which
		 * plugin generates it, so fixed generically here rather than left
		 * alone: any Material Symbols icon is always either purely decorative
		 * or paired with its own visible label (never the sole accessible name
		 * for a control), so one with no aria-hidden at all is reliably an
		 * oversight, not a deliberate label. Re-run after `updated_checkout` —
		 * WooCommerce rebuilds the payment methods list over AJAX, which would
		 * otherwise drop the fix a second after it first ran.
		 */
		if ( class_exists( 'Nera_SAW_Standalone_Basket' ) && Nera_SAW_Standalone_Basket::is_section_checkout() ) {
			wp_add_inline_script(
				'jquery-core',
				"(function(){function sawHideDecorativeIcons(){document.querySelectorAll('.material-symbols-outlined:not([aria-hidden])').forEach(function(el){if(/^[a-z_]+$/.test(el.textContent.trim())){el.setAttribute('aria-hidden','true');}});}
				function sawStart(){sawHideDecorativeIcons();new MutationObserver(sawHideDecorativeIcons).observe(document.body,{childList:true,subtree:true});}
				if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',sawStart);}else{sawStart();}})();"
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Templates
	 * ------------------------------------------------------------------ */

	/**
	 * Routes this plugin serves, and the template each one loads.
	 *
	 * @return array route => template file (relative to templates/standalone/).
	 */
	public static function routes() {
		return apply_filters(
			'nera_saw_standalone_routes',
			array(
				'competitions' => 'competitions.php',
				'competition'  => 'competition.php',
			)
		);
	}

	/**
	 * Is this request one of the section's screens, however it was reached?
	 *
	 * Two answers are possible and both are standalone: a dynamic rewrite route
	 * such as a competition, and a real page carrying one of the plugin's
	 * templates. Checking only the query var was enough while every screen was a
	 * rewrite rule; once the screens became editable pages it stopped being true,
	 * and the first symptom was the list page rendering with no stylesheet at all.
	 *
	 * @return bool
	 */
	public static function is_standalone_screen() {
		if ( self::current_route() ) {
			return true;
		}

		if ( ! is_page() || ! class_exists( 'Nera_SAW_Standalone_Pages' ) ) {
			return false;
		}

		$assigned = (string) get_post_meta( get_queried_object_id(), '_wp_page_template', true );
		$is_ours  = 0 === strpos( $assigned, Nera_SAW_Standalone_Pages::TEMPLATE_PREFIX );

		/**
		 * Filter whether this request is a standalone screen.
		 *
		 * Checkout is the case this exists for: it is WooCommerce's own page, so it
		 * carries none of the plugin's markers, but the section renders it and it
		 * must load the section's stylesheet like everything else.
		 *
		 * @param bool $is_ours Whether the request is a standalone screen.
		 */
		return (bool) apply_filters( 'nera_saw_is_standalone_screen', $is_ours );
	}

	/**
	 * The current route, or '' when this is not a standalone request.
	 *
	 * @return string
	 */
	public static function current_route() {
		$route = get_query_var( self::QV_ROUTE );
		return is_string( $route ) ? $route : '';
	}

	/**
	 * Swap WordPress's chosen template for the plugin's.
	 *
	 * Runs at priority 99 so a theme that filters `template_include` for its own
	 * reasons has already had its say — this is the last word, because the whole
	 * point of the section is that it does not depend on the theme.
	 *
	 * @param string $template Template WordPress resolved.
	 * @return string
	 */
	public static function template( $template ) {
		$route = self::current_route();
		if ( ! $route ) {
			return $template;
		}

		$routes = self::routes();
		if ( ! isset( $routes[ $route ] ) ) {
			return $template;
		}

		$found = self::locate( $routes[ $route ] );
		if ( ! $found ) {
			return $template;
		}

		// A standalone route is never a 404, whatever WordPress decided while
		// resolving a URL it does not recognise.
		status_header( 200 );
		global $wp_query;
		$wp_query->is_404 = false;

		return $found;
	}

	/**
	 * Find a template, letting a theme override it.
	 *
	 * The section bypasses the theme's layout, but a site that genuinely wants to
	 * change one screen should not have to fork the plugin. Theme first, plugin
	 * second — the usual WooCommerce arrangement.
	 *
	 * @param string $file Template file name.
	 * @return string Absolute path, or '' when missing.
	 */
	public static function locate( $file ) {
		$theme = locate_template( array( 'nera-strikeawin/' . $file ) );
		if ( $theme ) {
			return $theme;
		}

		$path = NERA_SAW_PLUGIN_DIR . 'templates/standalone/' . $file;
		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Load a partial from the same places.
	 *
	 * @param string $file Template file name.
	 * @param array  $args Variables to expose to the partial.
	 */
	public static function part( $file, array $args = array() ) {
		$path = self::locate( $file );
		if ( ! $path ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $args, EXTR_SKIP );
		include $path;
	}
}
