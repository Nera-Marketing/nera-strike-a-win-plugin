<?php
/**
 * My Account — the new endpoints the client's "My runs and tickets" feedback
 * asked for: "My runs & tickets", "Draw results", "Responsible play".
 *
 * Every other row this plugin's My Account page already shows — Wallet,
 * Orders, "Manage my account" — is registered by a SIBLING plugin's own
 * `woocommerce_account_menu_items`/`add_rewrite_endpoint()` pair (woo-wallet,
 * nera-self-exclusion-plugin). This plugin never had a WooCommerce account
 * endpoint of its own before now; these three do, because the content behind
 * them — a player's own runs, tickets and draw results — is this plugin's
 * own data, not a sibling's.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Account_Pages
 */
class Nera_SAW_Account_Pages {

	/**
	 * Endpoint slugs, also the `woocommerce_account_{slug}_endpoint` action
	 * each one fires and the query var WooCommerce reads it from.
	 */
	const RUNS_TICKETS    = 'my-runs-tickets';
	const DRAW_RESULTS    = 'draw-results';
	const RESPONSIBLE_PLAY = 'responsible-play';

	/**
	 * Option flushed rewrite rules were generated against — bumped whenever
	 * a new endpoint is added, since an already-active install's rewrite
	 * rules were written before this class existed and activation (which
	 * would normally flush them) does not re-run on an existing install.
	 */
	const REWRITE_VERSION_OPTION = 'nera_saw_account_pages_rewrite_version';
	const REWRITE_VERSION        = 1;

	/**
	 * Completed runs shown per page, per competition, on "My runs & tickets".
	 */
	const RUNS_PER_PAGE = 5;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoints' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ) );

		add_action( 'woocommerce_account_' . self::RUNS_TICKETS . '_endpoint', array( __CLASS__, 'render_runs_tickets' ) );
		add_action( 'woocommerce_account_' . self::DRAW_RESULTS . '_endpoint', array( __CLASS__, 'render_draw_results' ) );
		add_action( 'woocommerce_account_' . self::RESPONSIBLE_PLAY . '_endpoint', array( __CLASS__, 'render_responsible_play' ) );

		add_action( 'admin_init', array( __CLASS__, 'maybe_flush_rewrite_rules' ) );

		// Paging the completed runs without a reload: the REST route hands back the
		// next page already rendered, the script swaps it in.
		add_action( 'rest_api_init', array( __CLASS__, 'register_runs_route' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_runs_script' ) );
	}

	/**
	 * Register the rewrite endpoints. Also called from the activation hook
	 * (see nera-strikeawin.php), so it must be safe to call standalone.
	 */
	public static function add_endpoints() {
		add_rewrite_endpoint( self::RUNS_TICKETS, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::DRAW_RESULTS, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::RESPONSIBLE_PLAY, EP_ROOT | EP_PAGES );
	}

	/**
	 * Flush once per rewrite-rule version bump — cheap no-op after the
	 * first run, same pattern `Nera_SAW_Play_Page::ensure_page()` already
	 * uses for an already-active install that never re-runs activation.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( (int) get_option( self::REWRITE_VERSION_OPTION, 0 ) >= self::REWRITE_VERSION ) {
			return;
		}

		self::add_endpoints();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION );
	}

	/**
	 * Register the endpoints as WooCommerce query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::RUNS_TICKETS ]     = self::RUNS_TICKETS;
		$vars[ self::DRAW_RESULTS ]     = self::DRAW_RESULTS;
		$vars[ self::RESPONSIBLE_PLAY ] = self::RESPONSIBLE_PLAY;

		return $vars;
	}

	/**
	 * Insert the three new rows into the My Account nav, right after
	 * Orders — ahead of Wallet/Account details, since "what did I win and
	 * what's still live" is the reason a player who just finished a run
	 * opens this page at all (client finding #2's own mock orders it this
	 * way: runs/tickets, then Draw results, then Responsible play, ahead of
	 * Account details).
	 *
	 * @param array $items Existing menu items.
	 * @return array
	 */
	public static function menu_items( $items ) {
		$new = array();

		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::RUNS_TICKETS ]     = __( 'My runs & tickets', 'nera-strikeawin' );
				$new[ self::DRAW_RESULTS ]     = __( 'Draw results', 'nera-strikeawin' );
				$new[ self::RESPONSIBLE_PLAY ] = __( 'Responsible play', 'nera-strikeawin' );
			}
		}

		// 'orders' missing entirely (e.g. filtered out upstream) — append
		// rather than silently dropping the three rows.
		if ( ! isset( $new[ self::RUNS_TICKETS ] ) ) {
			$new[ self::RUNS_TICKETS ]     = __( 'My runs & tickets', 'nera-strikeawin' );
			$new[ self::DRAW_RESULTS ]     = __( 'Draw results', 'nera-strikeawin' );
			$new[ self::RESPONSIBLE_PLAY ] = __( 'Responsible play', 'nera-strikeawin' );
		}

		return $new;
	}

	/**
	 * Tickets a player holds in draws that have not happened yet: one cheap query, for the
	 * header's "My tickets" badge. Same meaning as the account hub's "Tickets live".
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function tickets_live( $user_id ) {
		static $cache = array();
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! class_exists( 'Nera_SAW_Database' ) ) {
			return 0;
		}
		if ( ! isset( $cache[ $user_id ] ) ) {
			global $wpdb;
			$runs  = Nera_SAW_Database::table( 'runs' );
			$slots = Nera_SAW_Database::table( 'run_slots' );
			$cache[ $user_id ] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT COALESCE( SUM( s.spins_awarded ), 0 ) FROM {$runs} r
					 JOIN {$slots} s ON s.run_id = r.id
					 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = r.competition_id AND m.meta_key = 'lty_lottery_status'
					 WHERE r.user_id = %d AND r.status = 'finalized' AND ( m.meta_value IS NULL OR m.meta_value <> 'lty_lottery_finished' )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id
				)
			);
		}
		return $cache[ $user_id ];
	}

	/**
	 * "My runs & tickets" endpoint content.
	 */
	public static function render_runs_tickets() {
		$path = NERA_SAW_PLUGIN_DIR . 'templates/woocommerce/myaccount/my-runs-tickets.php';
		if ( is_readable( $path ) ) {
			include $path;
		}
	}

	/**
	 * "Draw results" endpoint content.
	 */
	public static function render_draw_results() {
		$path = NERA_SAW_PLUGIN_DIR . 'templates/woocommerce/myaccount/draw-results.php';
		if ( is_readable( $path ) ) {
			include $path;
		}
	}

	/**
	 * "Responsible play" endpoint content.
	 */
	public static function render_responsible_play() {
		$path = NERA_SAW_PLUGIN_DIR . 'templates/woocommerce/myaccount/responsible-play.php';
		if ( is_readable( $path ) ) {
			include $path;
		}
	}

	/* ---------------------------------------------------------------------
	 * "My runs & tickets": completed runs, paged per competition
	 * ------------------------------------------------------------------ */

	/**
	 * Query-string argument that carries one competition's page number. Per
	 * competition, so two competitions can be on different pages at once, and so the
	 * page still works (as plain links) with scripting off.
	 *
	 * @param int $competition_id Competition ID.
	 * @return string
	 */
	public static function runs_page_arg( $competition_id ) {
		return 'runs_page_' . (int) $competition_id;
	}

	/**
	 * Page numbers asked for in the current address, by competition.
	 *
	 * @return array<int, int>
	 */
	public static function requested_run_pages() {
		$out = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display position, not an action.
		foreach ( (array) $_GET as $key => $value ) {
			if ( preg_match( '/^runs_page_(\d+)$/', (string) $key, $m ) ) {
				$out[ (int) $m[1] ] = max( 1, absint( $value ) );
			}
		}
		return $out;
	}

	/**
	 * A tier's display label, falling back to its key.
	 *
	 * @param array  $config   Competition config.
	 * @param string $tier_key Tier key.
	 * @return string
	 */
	public static function tier_label( array $config, $tier_key ) {
		if ( ! $config || ! class_exists( 'Nera_SAW_Competition_Config' ) ) {
			return (string) $tier_key;
		}
		$tier = Nera_SAW_Competition_Config::tier( $config, $tier_key );
		return $tier && ! empty( $tier['label'] ) ? (string) $tier['label'] : (string) $tier_key;
	}

	/**
	 * Print a page of completed runs. One place for the markup, because the page
	 * prints it on load and the REST route prints it again for every page turn.
	 *
	 * @param int   $competition_id Competition ID.
	 * @param array $runs           Rows from Nera_SAW_Run::runs_for_user()['completed'].
	 */
	public static function render_completed_runs( $competition_id, array $runs ) {
		$config = class_exists( 'Nera_SAW_Competition_Config' ) ? Nera_SAW_Competition_Config::get( (int) $competition_id ) : array();

		foreach ( $runs as $run ) {
			$numbers = $run['ticket_numbers'];
			$visible = array_slice( $numbers, 0, 6 );
			$extra   = max( 0, count( $numbers ) - 6 );
			?>
			<div class="saw-runs-tickets__completed-run">
				<div class="saw-runs-tickets__row">
					<span><?php echo esc_html( self::tier_label( $config, $run['tier_key'] ) ); ?></span>
					<span class="saw-runs-tickets__ticket-count">
						<?php
						// Reuses the "%s ticket"/"%s tickets" pair the competition
						// hero already carries (saw_ru_strings()) rather than a
						// new "%d ticket(s)" pair needing its own translations.
						echo esc_html(
							sprintf(
								/* translators: %d: tickets earned */
								_n( '%s ticket', '%s tickets', count( $numbers ), 'nera-strikeawin' ),
								number_format_i18n( count( $numbers ) )
							)
						);
						?>
					</span>
				</div>
				<?php if ( ! empty( $numbers ) ) : ?>
					<div class="saw-runs-tickets__numbers">
						<?php foreach ( $visible as $num ) : ?>
							<span class="saw-numchip"><?php echo esc_html( $num ); ?></span>
						<?php endforeach; ?>
						<?php if ( $extra > 0 ) : ?>
							<details class="saw-numchip-more">
								<summary class="saw-numchip saw-numchip--more">
									<?php
									printf(
										/* translators: %d: how many more entry numbers there are */
										esc_html__( '+%d more', 'nera-strikeawin' ),
										(int) $extra
									);
									?>
								</summary>
								<div class="saw-numchip-more__panel">
									<?php foreach ( array_slice( $numbers, 6 ) as $num ) : ?>
										<span class="saw-numchip"><?php echo esc_html( $num ); ?></span>
									<?php endforeach; ?>
								</div>
							</details>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Previous / "6-10 of 31" / Next for one competition's completed runs. Plain
	 * links to the same page with the page argument set, so it works with scripting
	 * off; the script upgrades them. Nothing for a single page.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $page           Current page.
	 * @param int    $pages          Number of pages.
	 * @param int    $total          Completed runs in all.
	 * @param string $base_url       Address the links are built on ('' = this request).
	 */
	public static function render_runs_pager( $competition_id, $page, $pages, $total, $base_url = '' ) {
		$pages = max( 1, (int) $pages );
		if ( $pages < 2 ) {
			return;
		}
		$page = min( $pages, max( 1, (int) $page ) );
		$arg  = self::runs_page_arg( $competition_id );
		$link = static function ( $n ) use ( $arg, $competition_id, $base_url ) {
			$url = '' === $base_url ? add_query_arg( $arg, $n ) : add_query_arg( $arg, $n, $base_url );
			return remove_query_arg( 'saw_entry', $url ) . '#saw-runs-' . (int) $competition_id;
		};
		$from = ( $page - 1 ) * self::RUNS_PER_PAGE + 1;
		$to   = min( $total, $page * self::RUNS_PER_PAGE );
		?>
		<nav class="saw-runs-pager" aria-label="<?php esc_attr_e( 'Completed runs pages', 'nera-strikeawin' ); ?>">
			<?php if ( $page > 1 ) : ?>
				<a class="saw-runs-pager__btn" href="<?php echo esc_url( $link( $page - 1 ) ); ?>" data-saw-page="<?php echo (int) ( $page - 1 ); ?>" rel="prev"><?php esc_html_e( 'Previous', 'nera-strikeawin' ); ?></a>
			<?php else : ?>
				<span class="saw-runs-pager__btn is-disabled" aria-disabled="true"><?php esc_html_e( 'Previous', 'nera-strikeawin' ); ?></span>
			<?php endif; ?>

			<span class="saw-runs-pager__status">
				<?php
				printf(
					/* translators: 1: first run number on this page, 2: last, 3: total completed runs */
					esc_html__( '%1$d to %2$d of %3$d', 'nera-strikeawin' ),
					(int) $from,
					(int) $to,
					(int) $total
				);
				?>
			</span>

			<?php if ( $page < $pages ) : ?>
				<a class="saw-runs-pager__btn" href="<?php echo esc_url( $link( $page + 1 ) ); ?>" data-saw-page="<?php echo (int) ( $page + 1 ); ?>" rel="next"><?php esc_html_e( 'Next', 'nera-strikeawin' ); ?></a>
			<?php else : ?>
				<span class="saw-runs-pager__btn is-disabled" aria-disabled="true"><?php esc_html_e( 'Next', 'nera-strikeawin' ); ?></span>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * REST: one competition's completed runs, one page, already rendered.
	 */
	public static function register_runs_route() {
		register_rest_route(
			Nera_SAW_REST::NS,
			'/my-runs/completed',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'competition_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'page'           => array( 'required' => false, 'default' => 1, 'sanitize_callback' => 'absint' ),
				),
				'callback'            => array( __CLASS__, 'rest_completed_runs' ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_completed_runs( $request ) {
		$competition_id = (int) $request['competition_id'];
		$page           = max( 1, (int) $request['page'] );

		// The signed-in player's own runs only: there is no user argument to ask for
		// someone else's.
		$rows = Nera_SAW_Run::runs_for_user( get_current_user_id(), $competition_id, self::RUNS_PER_PAGE, array( $competition_id => $page ) );
		if ( empty( $rows ) ) {
			return new WP_Error( 'saw_no_runs', __( 'No runs found.', 'nera-strikeawin' ), array( 'status' => 404 ) );
		}
		$entry = $rows[0];

		ob_start();
		self::render_completed_runs( $competition_id, $entry['completed'] );
		$html = ob_get_clean();

		ob_start();
		self::render_runs_pager( $competition_id, $entry['completed_page'], $entry['completed_pages'], $entry['completed_total'], wc_get_account_endpoint_url( self::RUNS_TICKETS ) );
		$pager = ob_get_clean();

		return rest_ensure_response(
			array(
				'html'  => $html,
				'pager' => $pager,
				'page'  => $entry['completed_page'],
				'pages' => $entry['completed_pages'],
				'total' => $entry['completed_total'],
			)
		);
	}

	/**
	 * Load the paging script on the "My runs & tickets" screen only.
	 */
	public static function enqueue_runs_script() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::RUNS_TICKETS ) ) {
			return;
		}

		wp_enqueue_script(
			'nera-saw-runs-tickets',
			NERA_SAW_PLUGIN_URL . 'assets/js/runs-tickets.js',
			array(),
			(string) filemtime( NERA_SAW_PLUGIN_DIR . 'assets/js/runs-tickets.js' ),
			true
		);
		wp_localize_script(
			'nera-saw-runs-tickets',
			'nsawRunsTickets',
			array(
				'url'   => esc_url_raw( rest_url( Nera_SAW_REST::NS . '/my-runs/completed' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
