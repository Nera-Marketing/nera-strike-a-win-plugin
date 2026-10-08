<?php
/**
 * Keep the rest of the site out of the standalone section.
 *
 * The section bypasses the active theme's templates on purpose — it has to look
 * like the design on any site the plugin ships to. That is only half true while
 * the theme's stylesheet still loads: a Tailwind build ships Preflight, which
 * resets `h1`, `p`, `ul`, `a` and `body` globally, and no amount of care in
 * standalone.css survives a reset that loads after it.
 *
 * This was not a hypothetical. The section rendered with the right markup and the
 * wrong spacing for exactly this reason, and the CSS was rewritten twice before
 * anyone looked at what else was on the page.
 *
 * WHAT IS REMOVED, AND WHAT IS NOT
 * -------------------------------
 * Removed: anything served out of the active theme's directory, and the site-wide
 * footer furniture that other Nera plugins print — the responsible-play strip and
 * the voluntary-code badge. Both say, in the main site's visual language, what
 * the section's own footer already says in its own.
 *
 * Kept: everything else. WooCommerce, the payment gateway, analytics and consent
 * all keep working, because wp_head() and wp_footer() still run and a section
 * that silently dropped them would be a compliance problem rather than a styling
 * choice.
 *
 * Both lists are filterable. Suppressing a compliance widget is an operator's
 * decision, not this file's, and `nera_saw_standalone_suppressed_plugins` is how
 * an operator takes it back.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Standalone_Chrome
 */
class Nera_SAW_Standalone_Chrome {

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( is_admin() || ! Nera_SAW_Mode::is_standalone() ) {
			return;
		}

		// Late, so it runs after everything else has had its say.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'drop_theme_assets' ), 100 );

		// Again before the footer prints. A theme that enqueues from wp_footer --
		// which this one does for two of its scripts -- escapes the pass above
		// entirely, because that one has already finished by then.
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'drop_theme_assets' ), 0 );

		/*
		 * Straight after the play card, which sits on the same hook at 5. Finishing
		 * a purchase leaves the player with exactly two things they might do next:
		 * play the runs they just bought, or go back and enter another competition.
		 * Only the first had a button, so the second meant using the browser's back
		 * button through a completed checkout.
		 */
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'back_to_competitions' ), 6 );
		add_action( 'template_redirect', array( __CLASS__, 'drop_order_again' ), 20 );

		add_action( 'wp_head', array( __CLASS__, 'drop_site_furniture' ), 0 );

		// WooCommerce template overrides live in the theme too, and one of them on
		// this site opens a whole second page inside ours.
		add_filter( 'woocommerce_locate_template', array( __CLASS__, 'unoverride_template' ), 99, 3 );
		add_filter( 'wc_get_template_part', array( __CLASS__, 'unoverride_part' ), 99, 3 );

		// Woo Wallet is not WooCommerce and does not resolve its templates through
		// woocommerce_locate_template -- it has its own filter, and this site's
		// theme has its own re-skinned copy of the wallet screen sitting behind it
		// (a "Back to Dashboard" link included, which is meaningless once My
		// Wallet is a row of this page's own accordion rather than a page of its
		// own).
		add_filter( 'woo_wallet_locate_template', array( __CLASS__, 'unoverride_wallet_template' ), 99, 2 );

		// A gateway's title, description and icon are filterable, and this site's
		// theme dresses all three. Templates alone do not reach them.
		add_action( 'wp_loaded', array( __CLASS__, 'drop_gateway_dressing' ), 99 );

		// The account nav's icons, wallet badge and card styling are the same
		// story one level up: printed straight to wp_head/wp_footer rather than
		// templated, so neither unoverride_template() nor drop_theme_assets()
		// (which only reaches enqueued handles) ever sees them.
		//
		// template_redirect, not wp_loaded: bypassing_theme() resolves through
		// is_standalone_screen(), which reads is_page()/get_queried_object_id() --
		// the main query, which is not parsed yet at wp_loaded. Checked and wrong
		// on this exact page before the fix: bypassing_theme() read false that
		// early and the theme's dressing never got removed. template_redirect is
		// the first hook where the query is settled and still well before
		// wp_head/wp_footer fire.
		add_action( 'template_redirect', array( __CLASS__, 'drop_account_nav_dressing' ) );

		// Every account-nav plugin this section has met so far (self-exclusion,
		// the spending-limit card) gates its own CSS/JS on `is_wc_endpoint_url()`
		// -- reasonable when a WooCommerce account screen really does render one
		// endpoint at a time, false on this one, which renders all of them on one
		// page. Rather than reach into a fourth plugin's own asset list the way
		// drop_account_nav_dressing() and unoverride_wallet_template() do,
		// this makes their own check true for the moment it runs, so each one
		// enqueues itself exactly as it would on its own real endpoint URL.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'stand_in_for_account_endpoints' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'stop_standing_in_for_account_endpoints' ), 999 );
	}

	/**
	 * Take the theme back out of the payment methods.
	 *
	 * Replacing the checkout templates is not enough. A gateway's description passes
	 * through a filter, and this theme hooks it: the wallet gateway arrives carrying
	 * a panel of Tailwind-classed markup and an icon-font ligature. With the theme's
	 * stylesheet dequeued that rendered as a washed-out box with the word "info" in
	 * it — 666 bytes of framework markup where the gateway's own 16-byte sentence
	 * would have done.
	 *
	 * Removed here rather than styled, because styling it would mean this plugin
	 * targeting another project's class names and icon font, and inheriting every
	 * rename.
	 *
	 * The editable "Pay via PayPal" message survives: PayPal Payments overrides
	 * get_description() and fires its own `woocommerce_paypal_payments_gateway_description`
	 * instead, which is not in this list. That is the same override that made the
	 * message need a bespoke filter in the first place.
	 *
	 * It is still theme-owned content, so a site without the theme has no such
	 * message. A field owned by this plugin is where it belongs eventually.
	 */
	public static function drop_gateway_dressing() {
		$slugs = array( wp_normalize_path( get_stylesheet_directory() ), wp_normalize_path( get_template_directory() ) );

		/*
		 * Description only, and the reason for each exclusion is worth keeping.
		 *
		 * The **icon** filter is left alone. The oversized card graphic was the
		 * Cashflows gateway's own icon, not the theme's -- the theme's only icon
		 * filter *hides* the wallet mark, so suppressing it put back a mark somebody
		 * had deliberately removed while doing nothing about the graphic that
		 * actually overflowed. That one is capped in CSS, where it belongs.
		 *
		 * The **title** filter is left alone too: it
		 * appends text, and text renders fine with no stylesheet -- this theme uses it
		 * to rename a gateway from "Cards" to "Pay with Apple / Google Pay (Debit &
		 * Credit)", which tells a shopper more, and to append a wallet-balance badge
		 * that reads as a plain sentence once its styling is gone. Stripping it made
		 * the section's payment list less informative than the main site's for no
		 * visual gain. Where markup in a title does matter -- the chip list on Before
		 * you pay -- Nera_SAW_Standalone_Basket::method_labels() removes it there.
		 */
		$filters = array( 'woocommerce_gateway_description' );

		global $wp_filter;
		foreach ( $filters as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $callback ) {
					$file = self::declared_in( $callback['function'] );
					if ( '' === $file ) {
						continue;
					}
					foreach ( $slugs as $root ) {
						if ( 0 === strpos( $file, $root ) ) {
							/*
							 * Wrapped rather than unset: these filters also run on the main
							 * site's checkout in the same page load is not possible, but they
							 * do run for the chip list on Before you pay and for admin
							 * screens, and a removal there would be a change nobody asked
							 * for. The wrapper defers the decision to render time.
							 */
							$original = $callback['function'];
							$wp_filter[ $hook ]->callbacks[ $priority ][ $id ]['function'] =
								function () use ( $original ) {
									$args = func_get_args();
									if ( self::bypassing_theme() ) {
										return $args[0];
									}
									return call_user_func_array( $original, $args );
								};
							break;
						}
					}
				}
			}
		}
	}

	/**
	 * Take the theme's account-nav dressing back out.
	 *
	 * `myaccount/navigation.php` is WooCommerce's own stock template on this site
	 * -- there is no theme override of it, so `unoverride_template()` has nothing
	 * to intercept. What the theme does instead is print a per-row icon, a wallet
	 * balance badge and a card-styled `<style>` block from three plain functions
	 * on `wp_head`/`wp_footer` (`nera_my_account_styles()`,
	 * `nera_add_account_menu_icons_script()`, `nera_add_wallet_balance_script()`
	 * in the theme's `inc/woocommerce.php`), gated only on `is_account_page()` --
	 * which templates/standalone/my-account.php makes true here on purpose, so
	 * every *other* plugin already keyed on "is this the account page" keeps
	 * working. This theme dressing is the one thing that gate was not meant to
	 * pull in: it is unstyled-by-the-theme here rather than designed for the
	 * section, and it is markup/CSS injected straight to the two hooks rather
	 * than templated, so neither `unoverride_template()` (resolves template
	 * files) nor `drop_theme_assets()` (dequeues enqueued handles) ever sees it.
	 *
	 * A fourth row joins these three for the same reason: the theme's own
	 * comment on `nera_wallet_back_to_dashboard()` says it hooks Woo Wallet's
	 * `woo_wallet_before_my_wallet_content` action rather than overriding
	 * `wc-endpoint-wallet.php` directly "since the template override path
	 * (`woo-wallet/`) differs from the theme's `woocommerce/woo-wallet/`
	 * location" — the theme's own template file is dead code (Woo Wallet never
	 * looks there), but the action-hooked link it exists to place still
	 * prints regardless, gated on the same `is_account_page()`. A "Back to
	 * Dashboard" link is also simply wrong here now: this screen has no
	 * Dashboard row to go back to (My Wallet is one row among several
	 * accordion items on the one page), and the one place it might mean
	 * something is a state that does not exist on mobile-only Tailwind
	 * breakpoints this section's own stylesheet does not define.
	 *
	 * Removed outright rather than wrapped, unlike `drop_gateway_dressing()`:
	 * each of the four fires once, from `wp_head`/`wp_footer`/the wallet
	 * template's own action, for one page — there is no second, non-standalone
	 * place in the same request where the same callback is still wanted, so
	 * there is nothing a wrapper would need to defer.
	 */
	public static function drop_account_nav_dressing() {
		if ( ! self::bypassing_theme() ) {
			return;
		}

		remove_action( 'wp_head', 'nera_my_account_styles', 10 );
		remove_action( 'wp_footer', 'nera_add_account_menu_icons_script', 10 );
		remove_action( 'wp_footer', 'nera_add_wallet_balance_script', 10 );
		remove_action( 'woo_wallet_before_my_wallet_content', 'nera_wallet_back_to_dashboard', 10 );
	}

	/**
	 * Remember which endpoint query vars this class added, so it can take back
	 * exactly those and nothing else.
	 *
	 * @var string[]|null
	 */
	private static $saw_stood_in_endpoints = null;

	/**
	 * The endpoints this screen's CURRENT request actually renders content
	 * for -- the same thing templates/woocommerce/myaccount/my-account.php
	 * works out for itself, computed the same way, so the two never drift
	 * apart into standing in for one set and rendering a different one.
	 *
	 * Rebuilt for the hub redesign (client findings #2/#45/#46): that
	 * template no longer renders every endpoint's content on one page --
	 * only the hub (nothing), a single row, or (for "Account details") up
	 * to three rows folded into one composite. "Responsible play" is the
	 * one case that needs its own addition: it renders self-exclusion's and
	 * the spending-limit plugin's own markup by calling their methods
	 * directly rather than through their usual `woocommerce_account_
	 * {endpoint}_endpoint` action (see templates/woocommerce/myaccount/
	 * responsible-play.php's own docblock for why), so neither plugin's own
	 * `wp_enqueue_scripts` gate -- `is_wc_endpoint_url( 'account-status' )`
	 * and `is_wc_endpoint_url( 'edit-account' )` respectively -- would ever
	 * see itself as "current" on that page without this standing in for
	 * both on its behalf.
	 *
	 * @return string[]
	 */
	private static function account_content_endpoints() {
		if ( ! function_exists( 'wc_get_account_menu_items' )
			|| ! class_exists( 'Nera_SAW_Account_Pages' )
			|| ! function_exists( 'wc_is_current_account_menu_item' )
		) {
			return array();
		}

		$details_keys = (array) apply_filters(
			'nera_saw_account_details_endpoints',
			array( 'edit-account', 'orders', 'woo-wallet' )
		);

		$single_keys = array(
			Nera_SAW_Account_Pages::RUNS_TICKETS,
			Nera_SAW_Account_Pages::DRAW_RESULTS,
		);

		foreach ( $details_keys as $endpoint ) {
			if ( wc_is_current_account_menu_item( $endpoint ) ) {
				return array_values( array_intersect( $details_keys, array_keys( wc_get_account_menu_items() ) ) );
			}
		}

		foreach ( $single_keys as $endpoint ) {
			if ( wc_is_current_account_menu_item( $endpoint ) ) {
				return array( $endpoint );
			}
		}

		if ( wc_is_current_account_menu_item( Nera_SAW_Account_Pages::RESPONSIBLE_PLAY ) ) {
			return (array) apply_filters(
				'nera_saw_responsible_play_stand_in_endpoints',
				array( 'account-status', 'edit-account' )
			);
		}

		// The hub itself -- nothing renders via an endpoint hook.
		return array();
	}

	/**
	 * Make `is_wc_endpoint_url()` true, briefly, for every endpoint this
	 * screen's accordion renders.
	 *
	 * `is_wc_endpoint_url( $endpoint )` (and the `woocommerce_account_menu_item`
	 * classes/active state that read the same query vars) is how a WooCommerce
	 * account screen normally knows "the visitor is looking at this one" --
	 * true for exactly one endpoint on WooCommerce's own paginated account
	 * pages, and the check a plugin reaches for to decide whether its own
	 * endpoint-specific CSS and JS are worth sending. This screen renders every
	 * endpoint's content on the one page, so the honest answer to "is the
	 * visitor looking at Orders" is yes for all six at once, not none of them
	 * — and every plugin met so far that asks the question (self-exclusion,
	 * the spending-limit card) assumed the single-endpoint world and stopped
	 * loading anything at all rather than loading the wrong thing.
	 *
	 * Scoped tightly on purpose: only `wp_enqueue_scripts`, priority 1 to 999,
	 * so this is undone (see stop_standing_in_for_account_endpoints()) before
	 * the body renders. A row's own `open`/`aria-current` state is read later,
	 * from templates/woocommerce/myaccount/my-account.php itself, against the
	 * page's real query vars — faking six endpoints "current" here would make
	 * every row in the accordion claim to be the open one.
	 */
	public static function stand_in_for_account_endpoints() {
		if ( ! self::bypassing_theme() || ! self::is_myaccount_screen() ) {
			return;
		}

		global $wp;
		if ( ! isset( $wp->query_vars ) || ! is_array( $wp->query_vars ) ) {
			return;
		}

		$added = array();
		foreach ( self::account_content_endpoints() as $endpoint ) {
			if ( ! isset( $wp->query_vars[ $endpoint ] ) ) {
				$wp->query_vars[ $endpoint ] = '';
				$added[]                     = $endpoint;
			}
		}

		self::$saw_stood_in_endpoints = $added;
	}

	/**
	 * Take back exactly the query vars stand_in_for_account_endpoints() added.
	 */
	public static function stop_standing_in_for_account_endpoints() {
		if ( empty( self::$saw_stood_in_endpoints ) ) {
			return;
		}

		global $wp;
		if ( isset( $wp->query_vars ) && is_array( $wp->query_vars ) ) {
			foreach ( self::$saw_stood_in_endpoints as $endpoint ) {
				unset( $wp->query_vars[ $endpoint ] );
			}
		}

		self::$saw_stood_in_endpoints = null;
	}

	/**
	 * Is this request the standalone My account accordion specifically? Tighter
	 * than bypassing_theme() alone, which is true for the whole section --
	 * pretending every account endpoint is "current" would be nonsense on, say,
	 * Before you pay.
	 *
	 * @return bool
	 */
	private static function is_myaccount_screen() {
		if ( ! is_page() || ! class_exists( 'Nera_SAW_Standalone_Pages' ) ) {
			return false;
		}

		return 'my-account' === Nera_SAW_Standalone_Pages::route_of( get_queried_object_id() );
	}

	/* ---------------------------------------------------------------------
	 * WooCommerce template overrides
	 * ------------------------------------------------------------------ */

	/**
	 * Use WooCommerce's own template rather than the theme's copy of it.
	 *
	 * The theme on this site overrides `checkout/form-checkout.php` and calls
	 * get_header() from inside it, so the stock checkout renders an entire second
	 * page -- logo, navigation, breadcrumbs, footer -- nested inside the section's
	 * own. With the theme's stylesheet dequeued, that arrives as a bare list of
	 * links down the middle of the payment screen.
	 *
	 * Dropping back to the plugin's template is the same rule the assets follow: the
	 * section bypasses the theme, and a template is not an exception to that. It is
	 * also the markup assets/css/standalone.css is written against, so the checkout
	 * is styled rather than merely unstyled-by-the-theme.
	 *
	 * The cost is real and worth naming: theme overrides carry features as well as
	 * layout. On this site `checkout/payment.php` renders the partial-wallet-payment
	 * controls, and they are not inherited here.
	 *
	 * The section's own copy wins outright, before anything asks whether the
	 * theme touched this template at all. `myaccount/navigation.php` is
	 * WooCommerce's own stock file on this site — nothing to "unoverride" — but
	 * the accordion grouping it needs is still the section's own version of that
	 * screen, the same as a checkout template the theme did reach first.
	 *
	 * @param string $template      Path the theme resolved to.
	 * @param string $template_name Relative template name.
	 * @param string $template_path Template subdirectory.
	 * @return string
	 */
	public static function unoverride_template( $template, $template_name, $template_path ) {
		if ( ! self::bypassing_theme() || ! function_exists( 'WC' ) ) {
			return $template;
		}

		$name = ltrim( (string) $template_name, '/' );

		/*
		 * Some of what a theme puts in a WooCommerce template is a feature rather
		 * than a skin -- this site's checkout/payment.php carries the
		 * part-wallet control -- and dropping to WooCommerce's stock file removes
		 * the feature along with the styling. A copy here keeps both:
		 * WooCommerce's structure, with the parts worth keeping opted back in.
		 */
		$ours = NERA_SAW_PLUGIN_DIR . 'templates/woocommerce/' . $name;
		if ( file_exists( $ours ) ) {
			return $ours;
		}

		if ( ! self::is_theme_file( $template ) ) {
			return $template;
		}

		$default = WC()->plugin_path() . '/templates/' . $name;

		/**
		 * Filter the WooCommerce template a standalone screen falls back to.
		 *
		 * @param string $default       Path in the WooCommerce plugin.
		 * @param string $template_name Relative template name.
		 * @param string $template      Theme path being replaced.
		 */
		$default = apply_filters( 'nera_saw_standalone_wc_template', $default, $template_name, $template );

		return file_exists( $default ) ? $default : $template;
	}

	/**
	 * The same idea as unoverride_template(), for Woo Wallet's own template
	 * resolution — a separate plugin with a separate `locate_template()` of its
	 * own, so `woocommerce_locate_template` never sees it.
	 *
	 * Belt and braces rather than the actual fix: the theme's copy lives at
	 * `woocommerce/woo-wallet/wc-endpoint-wallet.php`, but Woo Wallet's own
	 * `locate_template()` only ever looks under `woo-wallet/` (no
	 * `woocommerce/` prefix) — its own theme file is dead code, never resolved
	 * by the path this filter intercepts. The dressing that file exists to add
	 * ("Back to Dashboard") reaches the page a different way instead — see
	 * `drop_account_nav_dressing()`. Kept anyway: if that path mismatch is ever
	 * fixed upstream, or the theme's copy is moved to where Woo Wallet actually
	 * looks, this is what stops it reaching the section without anyone having
	 * to remember why.
	 *
	 * @param string $template      Path Woo Wallet resolved (the theme's, if it
	 *                              has one).
	 * @param string $template_name Relative template name, e.g.
	 *                              `wc-endpoint-wallet.php`.
	 * @return string
	 */
	public static function unoverride_wallet_template( $template, $template_name ) {
		if ( ! self::bypassing_theme() || ! function_exists( 'woo_wallet' ) ) {
			return $template;
		}

		$name = ltrim( (string) $template_name, '/' );

		$ours = NERA_SAW_PLUGIN_DIR . 'templates/woo-wallet/' . $name;
		if ( file_exists( $ours ) ) {
			return $ours;
		}

		if ( ! self::is_theme_file( $template ) ) {
			return $template;
		}

		$default = WOO_WALLET_ABSPATH . 'templates/' . $name;

		return file_exists( $default ) ? $default : $template;
	}

	/**
	 * The same, for wc_get_template_part().
	 *
	 * @param string $template Resolved path.
	 * @param string $slug     Template slug.
	 * @param string $name     Template name.
	 * @return string
	 */
	public static function unoverride_part( $template, $slug, $name ) {
		if ( ! self::bypassing_theme() || ! self::is_theme_file( $template ) || ! function_exists( 'WC' ) ) {
			return $template;
		}

		$base    = WC()->plugin_path() . '/templates/' . $slug;
		$default = $name ? $base . '-' . $name . '.php' : $base . '.php';

		return file_exists( $default ) ? $default : $template;
	}

	/**
	 * Render a theme template part, only if the theme actually has it.
	 *
	 * Lives here rather than in the template that uses it, and that is the whole
	 * point: a WooCommerce template is included as many times as WooCommerce likes
	 * — twice in one request when the checkout is submitted and the order review is
	 * rebuilt — so a `function` declared inside one is a fatal waiting for the
	 * second include. It cost a checkout that span forever on "Processing your
	 * order", because the fatal broke the AJAX response and the overlay had nothing
	 * to dismiss it.
	 *
	 * `get_template_part()` on a missing part is silent, but it still fires three
	 * actions on the way, and a plugin listening to those would see a part that was
	 * never going to render.
	 *
	 * @param string $slug Part slug, without the .php.
	 * @param array  $args Arguments passed to the part.
	 */
	/**
	 * Take away WooCommerce's "Order again".
	 *
	 * It rebuilds the order in the main site's cart — `/cart/?order_again=…` — which
	 * from inside the section means landing on another site's page holding a basket
	 * the section does not use. It also reads oddly here: an entry is bought for a
	 * chosen competition, so the way on is to choose one, which is what
	 * `back_to_competitions()` offers.
	 */
	public static function drop_order_again() {
		if ( ! self::bypassing_theme() ) {
			return;
		}

		remove_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button' );
	}

	/**
	 * A way back into the section from a finished order.
	 *
	 * Guarded on the section's own screens: in standalone mode this hook also fires
	 * on the main site's thank-you page, and the section must not paint its
	 * navigation onto a page it does not own.
	 *
	 * @param int $order_id Order the shopper has just completed.
	 */
	public static function back_to_competitions( $order_id ) {
		unset( $order_id );

		if ( ! self::bypassing_theme() ) {
			return;
		}

		$url = Nera_SAW_Standalone_Pages::url( 'competitions' );
		if ( ! $url ) {
			return;
		}

		printf(
			'<div class="saw-order__next"><a class="saw-order__next-link" href="%1$s">%2$s</a></div>',
			esc_url( $url ),
			esc_html__( 'Enter another competition', 'nera-strikeawin' )
		);
	}

	public static function theme_part( $slug, array $args = array() ) {
		if ( locate_template( $slug . '.php' ) ) {
			get_template_part( $slug, null, $args );
		}
	}

	/**
	 * Is this request one the section renders?
	 *
	 * Resolved late and never cached: the checkout test reads the basket, and the
	 * basket changes within a request.
	 *
	 * @return bool
	 */
	private static function bypassing_theme() {
		if ( class_exists( 'Nera_SAW_Router' ) && Nera_SAW_Router::is_standalone_screen() ) {
			return true;
		}

		// WooCommerce rebuilds the order review over AJAX, where there is no page to
		// test. See Nera_SAW_Standalone_Basket::is_entry_context().
		return class_exists( 'Nera_SAW_Standalone_Basket' )
			&& Nera_SAW_Standalone_Basket::is_entry_context();
	}

	/**
	 * Does a path live in the active theme?
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private static function is_theme_file( $path ) {
		$path = wp_normalize_path( (string) $path );
		foreach ( array( get_stylesheet_directory(), get_template_directory() ) as $root ) {
			if ( 0 === strpos( $path, wp_normalize_path( $root ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Plugin directory names whose front-end output the section suppresses.
	 *
	 * @return array
	 */
	public static function suppressed_plugins() {
		/*
		 * Empty by design. An earlier version suppressed the responsible-play strip
		 * and the voluntary-code badge here, on the reasoning that the section's own
		 * footer already carries the same marks. The client's answer was the other
		 * one: keep them, and restyle them to the section.
		 *
		 * They are right, and the reason is worth keeping. Those strips are the
		 * site's commitments rendered by the plugins that own them — suppressing
		 * them makes this section the one place on the site where a responsible-play
		 * route is missing, which is a compliance gap dressed as a layout decision.
		 * Styling costs a stylesheet. The filter stays for an operator who disagrees.
		 */
		return (array) apply_filters( 'nera_saw_standalone_suppressed_plugins', array() );
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	/**
	 * Dequeue anything the active theme enqueued.
	 *
	 * Matched by path rather than by handle: handles change when a theme is
	 * rebuilt or renamed, and a hard-coded list would go stale silently — which is
	 * the worst way for a stylesheet rule to come back.
	 */
	public static function drop_theme_assets() {
		if ( ! Nera_SAW_Router::is_standalone_screen() ) {
			return;
		}

		$roots = array_unique(
			array_filter(
				array(
					wp_normalize_path( get_stylesheet_directory_uri() ),
					wp_normalize_path( get_template_directory_uri() ),
				)
			)
		);

		foreach ( array( wp_styles(), wp_scripts() ) as $registry ) {
			$dropped = array();

			foreach ( (array) $registry->queue as $handle ) {
				if ( ! isset( $registry->registered[ $handle ] ) ) {
					continue;
				}

				$src = wp_normalize_path( (string) $registry->registered[ $handle ]->src );
				if ( '' === $src ) {
					continue;
				}

				foreach ( $roots as $root ) {
					if ( 0 === strpos( $src, $root ) ) {
						/**
						 * Filter whether one theme asset is dropped on a standalone screen.
						 *
						 * @param bool   $drop   Default true.
						 * @param string $handle Asset handle.
						 * @param string $src    Resolved URL.
						 */
						if ( apply_filters( 'nera_saw_standalone_drop_theme_asset', true, $handle, $src ) ) {
							$registry->dequeue( $handle );
							$dropped[] = $handle;
						}
						break;
					}
				}
			}

			/*
			 * Dequeueing is not enough on its own. WP_Dependencies prints whatever a
			 * queued item declares as a dependency, whether or not that dependency is
			 * still in the queue -- and this theme hangs its own scripts off a CDN copy
			 * of Alpine, which nothing here drops because the URL is not the theme's.
			 * The two scripts came back every time until the edge was cut as well.
			 */
			if ( $dropped ) {
				foreach ( $registry->registered as $handle => $item ) {
					if ( empty( $item->deps ) ) {
						continue;
					}
					$kept = array_values( array_diff( (array) $item->deps, $dropped ) );
					if ( count( $kept ) !== count( $item->deps ) ) {
						$registry->registered[ $handle ]->deps = $kept;
					}
				}
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Site-wide furniture
	 * ------------------------------------------------------------------ */

	/**
	 * Unhook the site-wide strips other plugins print into wp_footer.
	 *
	 * remove_action() is no use here: these are registered as methods on objects
	 * this code has no handle on, and guessing the instance is how a removal
	 * quietly stops working after an unrelated refactor. Walking the hook and
	 * asking each callback where it was declared is uglier and correct.
	 */
	public static function drop_site_furniture() {
		if ( ! Nera_SAW_Router::is_standalone_screen() ) {
			return;
		}

		$slugs = self::suppressed_plugins();
		if ( ! $slugs ) {
			return;
		}

		$needles = array();
		foreach ( $slugs as $slug ) {
			$needles[] = wp_normalize_path( WP_PLUGIN_DIR . '/' . trim( (string) $slug, '/' ) ) . '/';
		}

		global $wp_filter;
		foreach ( array( 'wp_footer', 'wp_body_open' ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $callback ) {
					$file = self::declared_in( $callback['function'] );
					if ( '' === $file ) {
						continue;
					}

					foreach ( $needles as $needle ) {
						if ( 0 === strpos( $file, $needle ) ) {
							unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $id ] );
							break;
						}
					}
				}
			}
		}
	}

	/**
	 * The file a callback was declared in, or '' when it cannot be resolved.
	 *
	 * @param mixed $function Callback.
	 * @return string
	 */
	private static function declared_in( $function ) {
		try {
			if ( is_string( $function ) && false !== strpos( $function, '::' ) ) {
				$parts      = explode( '::', $function, 2 );
				$reflection = new ReflectionMethod( $parts[0], $parts[1] );
			} elseif ( is_string( $function ) && function_exists( $function ) ) {
				$reflection = new ReflectionFunction( $function );
			} elseif ( is_array( $function ) && isset( $function[0], $function[1] ) ) {
				$reflection = new ReflectionMethod( is_object( $function[0] ) ? get_class( $function[0] ) : $function[0], $function[1] );
			} elseif ( $function instanceof Closure ) {
				$reflection = new ReflectionFunction( $function );
			} else {
				return '';
			}
		} catch ( Throwable $e ) {
			// A callback that cannot be reflected is one this code leaves alone.
			return '';
		}

		return wp_normalize_path( (string) $reflection->getFileName() );
	}
}
