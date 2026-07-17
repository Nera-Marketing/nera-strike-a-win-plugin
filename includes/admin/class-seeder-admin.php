<?php
/**
 * Tools -> Strikeawin Demo: seed / wipe demo data.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Seeder_Admin
 */
class Nera_SAW_Seeder_Admin {

	const SLUG            = 'nera-saw-demo';
	const ACTION          = 'nera_saw_seeder';
	const AJAX_ACTION     = 'nera_saw_seeder_ajax';
	const NONCE           = 'nera_saw_seeder_nonce';
	const FLAGS_ACTION    = 'nera_saw_save_feature_flags';
	const FLAGS_NONCE     = 'nera_saw_feature_flags_nonce';

	/**
	 * Hook the admin page + handler.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::FLAGS_ACTION, array( __CLASS__, 'save_feature_flags' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Shared admin styles on the demo page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'nera-saw-admin', NERA_SAW_PLUGIN_URL . 'assets/css/admin.css', array(), NERA_SAW_VERSION );
		wp_enqueue_script(
			'nera-saw-seeder-admin',
			NERA_SAW_PLUGIN_URL . 'assets/js/seeder-admin.js',
			array( 'jquery' ),
			NERA_SAW_VERSION,
			true
		);
		wp_localize_script(
			'nera-saw-seeder-admin',
			'neraSawSeeder',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'seeding'     => __( 'Seeding demo data…', 'nera-strikeawin' ),
					'wiping'      => __( 'Removing demo data…', 'nera-strikeawin' ),
					'done'        => __( 'Done.', 'nera-strikeawin' ),
					'failed'      => __( 'Operation failed.', 'nera-strikeawin' ),
					'confirmWipe' => __( 'Remove all Strike A Win demo data? This cannot be undone.', 'nera-strikeawin' ),
				),
			)
		);
	}

	/**
	 * Register under Tools when NERA_SAW_DEMO_SEEDER is true; otherwise hidden
	 * from the menu but still reachable at tools.php?page=nera-saw-demo (feature flags).
	 */
	public static function menu() {
		$title    = __( 'Strike A Win Demo', 'nera-strikeawin' );
		$callback = array( __CLASS__, 'render' );
		$cap      = 'manage_options';
		$slug     = self::SLUG;

		if ( Nera_SAW_Constants::demo_seeder_enabled() ) {
			add_management_page( $title, $title, $cap, $slug, $callback );
			return;
		}

		add_submenu_page( null, $title, $title, $cap, $slug, $callback );
	}

	/**
	 * Persist feature-flag toggles from the demo page.
	 */
	public static function save_feature_flags() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'nera-strikeawin' ) );
		}
		check_admin_referer( self::FLAGS_NONCE );

		Nera_SAW_Constants::save_feature_flags(
			array(
				'frontend_ui'   => isset( $_POST['frontend_ui'] ),
				'quiz_feedback' => isset( $_POST['quiz_feedback'] ),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::SLUG,
					'saw_flags_saved' => '1',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render a single labelled number field inside a field grid.
	 *
	 * @param string     $label Field label.
	 * @param string     $name  Input name.
	 * @param int|float  $value Default value.
	 * @param string     $attrs Extra input attributes (e.g. min/step).
	 */
	private static function field( $label, $name, $value, $attrs = '' ) {
		printf(
			'<label class="saw-field"><span>%s</span><input type="number" %s name="%s" value="%s"></label>',
			esc_html( $label ),
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute strings.
			esc_attr( $name ),
			esc_attr( $value )
		);
	}

	/**
	 * Render a coupled min–max range control (two inputs, one label).
	 *
	 * @param string    $label    Shared label.
	 * @param string    $min_name Min input name.
	 * @param int|float $min_val  Min default.
	 * @param string    $max_name Max input name.
	 * @param int|float $max_val  Max default.
	 * @param string    $attrs    Extra input attributes.
	 */
	private static function range( $label, $min_name, $min_val, $max_name, $max_val, $attrs = '' ) {
		echo '<div class="saw-field"><span>' . esc_html( $label ) . '</span><div class="saw-range">';
		printf(
			'<input type="number" %s name="%s" value="%s" aria-label="%s">',
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute strings.
			esc_attr( $min_name ),
			esc_attr( $min_val ),
			esc_attr( sprintf( /* translators: %s field label */ __( '%s (min)', 'nera-strikeawin' ), $label ) )
		);
		echo '<span class="saw-range__sep">' . esc_html__( 'to', 'nera-strikeawin' ) . '</span>';
		printf(
			'<input type="number" %s name="%s" value="%s" aria-label="%s">',
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute strings.
			esc_attr( $max_name ),
			esc_attr( $max_val ),
			esc_attr( sprintf( /* translators: %s field label */ __( '%s (max)', 'nera-strikeawin' ), $label ) )
		);
		echo '</div></div>';
	}

	/**
	 * Parse seed options from a request (POST or AJAX).
	 *
	 * @return array{op:string,force:bool,opts:array}
	 */
	private static function parse_request() {
		$source = array_merge( $_GET, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$op     = isset( $source['op'] ) ? sanitize_key( wp_unslash( $source['op'] ) ) : '';
		$force  = ! empty( $source['force'] );
		$opts   = array(
			'questions_per_level' => isset( $source['questions_per_level'] ) ? (int) $source['questions_per_level'] : Nera_SAW_Seeder::QUESTIONS_PER_LEVEL,
			'products'            => isset( $source['products'] ) ? (int) $source['products'] : Nera_SAW_Seeder::DEFAULT_PRODUCTS,
			'success_submissions' => isset( $source['success_submissions'] ) ? (int) $source['success_submissions'] : Nera_SAW_Seeder::DEFAULT_SUCCESS_SUBS,
			'error_submissions'   => isset( $source['error_submissions'] ) ? (int) $source['error_submissions'] : Nera_SAW_Seeder::DEFAULT_ERROR_SUBS,
			'customers'           => isset( $source['customers'] ) ? (int) $source['customers'] : Nera_SAW_Seeder::DEFAULT_CUSTOMERS,
			'wallet_min'          => isset( $source['wallet_min'] ) ? (float) $source['wallet_min'] : Nera_SAW_Seeder::DEFAULT_WALLET_MIN,
			'wallet_max'          => isset( $source['wallet_max'] ) ? (float) $source['wallet_max'] : Nera_SAW_Seeder::DEFAULT_WALLET_MAX,
			'tickets_min'         => isset( $source['tickets_min'] ) ? (int) $source['tickets_min'] : Nera_SAW_Seeder::DEFAULT_TICKETS_MIN,
			'tickets_max'         => isset( $source['tickets_max'] ) ? (int) $source['tickets_max'] : Nera_SAW_Seeder::DEFAULT_TICKETS_MAX,
			'dist_min'            => isset( $source['dist_min'] ) ? (int) $source['dist_min'] : Nera_SAW_Seeder::DEFAULT_DIST_MIN,
			'dist_max'            => isset( $source['dist_max'] ) ? (int) $source['dist_max'] : Nera_SAW_Seeder::DEFAULT_DIST_MAX,
		);
		return array(
			'op'    => $op,
			'force' => $force,
			'opts'  => $opts,
		);
	}

	/**
	 * Run seed or wipe and return the result array.
	 *
	 * @param string $op    Operation: seed|wipe.
	 * @param bool   $force Force seed on production.
	 * @param array  $opts  Seed options.
	 * @return array
	 */
	private static function run_operation( $op, $force, array $opts ) {
		if ( 'seed' === $op ) {
			return Nera_SAW_Seeder::seed( $force, $opts );
		}
		if ( 'wipe' === $op ) {
			return Nera_SAW_Seeder::wipe();
		}
		return array( 'error' => __( 'Unknown operation.', 'nera-strikeawin' ) );
	}

	/**
	 * AJAX: run ONE phase of a seed/wipe and report live progress. The browser
	 * drives the sequence, calling back with the returned `next` phase until it is
	 * null. This gives genuine per-phase state (and chunked submissions).
	 */
	public static function ajax_handle() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nera-strikeawin' ) ) );
		}

		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$parsed = self::parse_request();
		$op     = $parsed['op'];
		$phase  = isset( $_REQUEST['phase'] ) ? sanitize_key( wp_unslash( $_REQUEST['phase'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset = isset( $_REQUEST['offset'] ) ? max( 0, (int) $_REQUEST['offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Remember the seed values site-wide so the form pre-fills them next time.
		// Saved on the first phase call (before any production guard) so a blocked
		// attempt still remembers what was entered.
		if ( 'seed' === $op && '' === $phase ) {
			Nera_SAW_Seeder::save_opts( $parsed['opts'] );
		}

		if ( 'seed' === $op && ! $parsed['force'] && ! Nera_SAW_Seeder::is_non_production() ) {
			wp_send_json_error( array( 'message' => __( 'Refusing to seed on a production environment. Tick the force option.', 'nera-strikeawin' ) ) );
		}

		if ( '' === $phase ) {
			$phase = ( 'wipe' === $op ) ? 'competitions' : 'questions';
		}

		try {
			if ( 'seed' === $op ) {
				$res = self::seed_phase( $phase, $parsed['opts'], $offset );
			} elseif ( 'wipe' === $op ) {
				$res = self::wipe_phase( $phase );
			} else {
				wp_send_json_error( array( 'message' => __( 'Unknown operation.', 'nera-strikeawin' ) ) );
			}
		} catch ( Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: phase, 2: error message */
						__( 'Failed during "%1$s": %2$s', 'nera-strikeawin' ),
						$phase,
						$e->getMessage()
					),
				)
			);
		}

		$res['stats'] = self::stats_payload();
		wp_send_json_success( $res );
	}

	/**
	 * Run one seed phase.
	 *
	 * @param string $phase  Phase key.
	 * @param array  $opts   Seed options.
	 * @param int    $offset Attempts already made (submissions phase).
	 * @return array Phase result for the UI.
	 */
	private static function seed_phase( $phase, array $opts, $offset ) {
		switch ( $phase ) {
			case 'questions':
				// Chunked: each question is a full wp_insert_post, so seeding a large
				// per-level count in one request times out. Insert a slice per call,
				// carrying a linear offset, until the whole bank is seeded.
				$per_level = isset( $opts['questions_per_level'] ) ? max( 1, (int) $opts['questions_per_level'] ) : Nera_SAW_Seeder::QUESTIONS_PER_LEVEL;
				$total     = Nera_SAW_Seeder::questions_total( $per_level );
				$chunk     = 50;
				$limit     = max( 0, min( $chunk, $total - $offset ) );
				if ( $limit > 0 ) {
					Nera_SAW_Seeder::phase_questions_chunk( $opts, $offset, $limit );
				}
				$new_off   = $offset + $limit;
				$done      = $new_off >= $total;
				return array(
					'phase'    => 'questions',
					'label'    => __( 'Question bank', 'nera-strikeawin' ),
					/* translators: 1: questions seeded so far, 2: total questions */
					'message'  => sprintf( __( '%1$d/%2$d seeded.', 'nera-strikeawin' ), $new_off, $total ),
					'progress' => array( 'current' => $new_off, 'total' => $total ),
					'offset'   => $new_off,
					'next'     => $done ? 'competitions' : 'questions',
				);

			case 'competitions':
				$n = Nera_SAW_Seeder::phase_competitions( $opts );
				return array(
					'phase'   => 'competitions',
					'label'   => __( 'Giveaways', 'nera-strikeawin' ),
					/* translators: %d: competitions */
					'message' => sprintf( __( 'Created %d demo giveaway competition(s).', 'nera-strikeawin' ), $n ),
					'next'    => 'customers',
				);

			case 'customers':
				$n     = Nera_SAW_Seeder::phase_customers( $opts );
				$plays = Nera_SAW_Seeder::simulate_total( $opts );
				return array(
					'phase'   => 'customers',
					'label'   => __( 'Customers', 'nera-strikeawin' ),
					/* translators: %d: customers */
					'message' => sprintf( __( 'Prepared %d demo customer(s) with wallet balances.', 'nera-strikeawin' ), $n ),
					'next'    => $plays > 0 ? 'submissions' : null,
				);

			case 'submissions':
				$total = Nera_SAW_Seeder::simulate_total( $opts );
				if ( $total < 1 ) {
					return array( 'phase' => 'submissions', 'label' => __( 'Simulating players', 'nera-strikeawin' ), 'message' => __( 'No submissions requested.', 'nera-strikeawin' ), 'next' => null );
				}
				// Engine-driven simulation (start/serve/answer/complete + mint) is
				// heavy per play, so keep the chunk small.
				$chunk    = 3;
				$attempts = max( 0, min( $chunk, $total - $offset ) );
				$r        = $attempts > 0 ? Nera_SAW_Seeder::phase_simulate( $opts, $offset, $attempts ) : array( 'success' => 0, 'errored' => 0, 'tickets_minted' => 0 );
				$new_off  = $offset + $attempts;
				$done     = $new_off >= $total;
				return array(
					'phase'    => 'submissions',
					'label'    => __( 'Simulating players', 'nera-strikeawin' ),
					/* translators: 1: done, 2: total, 3: completed, 4: errored, 5: tickets minted this batch */
					'message'  => sprintf( __( '%1$d/%2$d — %3$d completed, %4$d errored, %5$d ticket(s) minted this batch.', 'nera-strikeawin' ), $new_off, $total, (int) $r['success'], (int) $r['errored'], (int) $r['tickets_minted'] ),
					'progress' => array( 'current' => $new_off, 'total' => $total ),
					'offset'   => $new_off,
					'next'     => $done ? null : 'submissions',
				);
		}

		return array( 'phase' => $phase, 'message' => __( 'Done.', 'nera-strikeawin' ), 'next' => null );
	}

	/**
	 * Run one wipe phase.
	 *
	 * @param string $phase Phase key.
	 * @return array Phase result for the UI.
	 */
	private static function wipe_phase( $phase ) {
		switch ( $phase ) {
			case 'competitions':
				$n = Nera_SAW_Seeder::wipe_competitions();
				return array( 'phase' => 'competitions', 'label' => __( 'Competitions', 'nera-strikeawin' ), /* translators: %d */ 'message' => sprintf( __( 'Removed %d demo competition(s) and their runs.', 'nera-strikeawin' ), $n ), 'next' => 'questions' );
			case 'questions':
				$n = Nera_SAW_Seeder::wipe_questions();
				return array( 'phase' => 'questions', 'label' => __( 'Questions', 'nera-strikeawin' ), /* translators: %d */ 'message' => sprintf( __( 'Removed %d demo question(s).', 'nera-strikeawin' ), $n ), 'next' => 'tickets' );
			case 'tickets':
				$n = Nera_SAW_Seeder::wipe_tickets();
				return array( 'phase' => 'tickets', 'label' => __( 'LFW tickets', 'nera-strikeawin' ), /* translators: %d */ 'message' => sprintf( __( 'Removed %d demo LFW ticket(s).', 'nera-strikeawin' ), $n ), 'next' => 'orders' );
			case 'orders':
				$n = Nera_SAW_Seeder::wipe_orders();
				return array( 'phase' => 'orders', 'label' => __( 'Orders', 'nera-strikeawin' ), /* translators: %d */ 'message' => sprintf( __( 'Removed %d demo order(s).', 'nera-strikeawin' ), $n ), 'next' => 'users' );
			case 'users':
				$n = Nera_SAW_Seeder::wipe_users();
				return array( 'phase' => 'users', 'label' => __( 'Customers', 'nera-strikeawin' ), /* translators: %d */ 'message' => sprintf( __( 'Removed %d demo user(s).', 'nera-strikeawin' ), $n ), 'next' => null );
		}
		return array( 'phase' => $phase, 'message' => __( 'Done.', 'nera-strikeawin' ), 'next' => null );
	}

	/**
	 * Build a short success line for the status panel.
	 *
	 * @param string $op     Operation.
	 * @param array  $result Seeder result.
	 * @return string
	 */
	private static function success_message( $op, array $result ) {
		if ( 'wipe' === $op ) {
			return sprintf(
				/* translators: 1: competitions removed, 2: questions removed */
				__( 'Demo data removed (%1$d competitions, %2$d questions).', 'nera-strikeawin' ),
				(int) ( $result['competitions_removed'] ?? 0 ),
				(int) ( $result['questions_removed'] ?? 0 )
			);
		}
		return sprintf(
			/* translators: 1: questions in bank, 2: demo competitions */
			__( 'Demo data seeded (%1$d questions in bank, %2$d new competitions).', 'nera-strikeawin' ),
			(int) ( $result['total_in_bank'] ?? 0 ),
			(int) ( $result['competitions'] ?? 0 )
		);
	}

	/**
	 * Current stat-tile values for live refresh after AJAX.
	 *
	 * @return array
	 */
	private static function stats_payload() {
		$products   = (array) get_option( Nera_SAW_Seeder::OPTION_PRODUCTS, array() );
		$bank_count = Nera_SAW_Question_Bank::count( Nera_SAW_Seeder::BATCH_ID );
		$user_count = count(
			get_users(
				array(
					'meta_key'   => Nera_SAW_Seeder::USER_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'     => 'ID',
				)
			)
		);
		return array(
			'bank'        => (int) $bank_count,
			'competitions' => count( $products ),
			'users'       => (int) $user_count,
		);
	}

	/**
	 * Handle seed/wipe POST (non-JS fallback).
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'nera-strikeawin' ) );
		}
		check_admin_referer( self::NONCE );

		$parsed = self::parse_request();
		if ( 'seed' === $parsed['op'] ) {
			Nera_SAW_Seeder::save_opts( $parsed['opts'] );
		}
		$result = self::run_operation( $parsed['op'], $parsed['force'], $parsed['opts'] );

		$redirect = add_query_arg(
			array(
				'page'   => self::SLUG,
				'saw_op' => $parsed['op'],
				'saw_r'  => rawurlencode( wp_json_encode( $result ) ),
			),
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		$demo_on    = Nera_SAW_Constants::demo_seeder_enabled();
		$is_prod    = ! Nera_SAW_Seeder::is_non_production();
		$products   = (array) get_option( Nera_SAW_Seeder::OPTION_PRODUCTS, array() );
		$bank_count = Nera_SAW_Question_Bank::count( Nera_SAW_Seeder::BATCH_ID );
		$user_count = count(
			get_users(
				array(
					'meta_key'   => Nera_SAW_Seeder::USER_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'     => 'ID',
				)
			)
		);

		echo '<div class="wrap saw-admin">';

		// Hero.
		echo '<div class="saw-demo__hero">';
		echo '<div class="saw-demo__mark" aria-hidden="true">&#127919;</div>';
		echo '<div>';
		echo '<h2>' . esc_html__( 'Strike A Win — Demo data', 'nera-strikeawin' ) . '</h2>';
		echo '<p>' . esc_html__( 'Spin up a realistic, playable competition with a graded trivia bank — then wipe it clean in one click. Only marker-stamped demo data is ever touched.', 'nera-strikeawin' ) . '</p>';
		if ( ! $demo_on ) {
			echo '<p class="saw-muted">' . esc_html__( 'This page is hidden from the Tools menu. Set NERA_SAW_DEMO_SEEDER to true in wp-config.php to show it there.', 'nera-strikeawin' ) . '</p>';
		}
		echo '</div></div>';

		// Result notice.
		if ( isset( $_GET['saw_flags_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Feature flags saved.', 'nera-strikeawin' ) . '</p></div>';
		}

		if ( isset( $_GET['saw_r'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$decoded = json_decode( wp_unslash( $_GET['saw_r'] ), true ); // phpcs:ignore
			if ( is_array( $decoded ) ) {
				$class = isset( $decoded['error'] ) ? 'notice-error' : 'notice-success';
				echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>';
				echo isset( $decoded['error'] )
					? esc_html( $decoded['error'] )
					: esc_html__( 'Done. Demo data updated.', 'nera-strikeawin' );
				echo '</p></div>';
			}
		}

		$flags = Nera_SAW_Constants::feature_flags();
		echo '<div class="saw-card" style="margin-bottom:1.5rem">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Feature flags', 'nera-strikeawin' ) . '</h2>';
		echo '<p class="saw-muted">' . esc_html__( 'Toggle Strike A Win frontend behaviour. Changes apply on the next page load.', 'nera-strikeawin' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::FLAGS_NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::FLAGS_ACTION ) . '">';
		echo '<ul class="saw-flag-list">';
		echo '<li><label><input type="checkbox" name="frontend_ui" value="1"' . checked( ! empty( $flags['frontend_ui'] ), true, false ) . '> ';
		echo esc_html__( 'Frontend UI overrides', 'nera-strikeawin' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Product tier widget, cart/checkout tier labels, and my-account order tier line.', 'nera-strikeawin' ) . '</p></li>';
		echo '<li><label><input type="checkbox" name="quiz_feedback" value="1"' . checked( ! empty( $flags['quiz_feedback'] ), true, false ) . '> ';
		echo esc_html__( 'Quiz answer feedback', 'nera-strikeawin' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Brief correct / wrong screen between questions during quiz play.', 'nera-strikeawin' ) . '</p></li>';
		echo '</ul>';
		submit_button( __( 'Save feature flags', 'nera-strikeawin' ), 'secondary', 'submit', false );
		echo '</form></div>';

		// Stat tiles.
		$env_pill = $is_prod
			? '<span class="saw-pill saw-pill--red">' . esc_html__( 'production', 'nera-strikeawin' ) . '</span>'
			: '<span class="saw-pill saw-pill--green">' . esc_html__( 'non-production', 'nera-strikeawin' ) . '</span>';
		echo '<div class="saw-stats" id="saw-demo-stats">';
		echo '<div class="saw-stat"><div class="saw-stat__label">' . esc_html__( 'Environment', 'nera-strikeawin' ) . '</div><div class="saw-stat__value">' . $env_pill . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="saw-stat"><div class="saw-stat__label">' . esc_html__( 'Demo questions in bank', 'nera-strikeawin' ) . '</div><div class="saw-stat__value" data-saw-stat="bank">' . esc_html( number_format_i18n( $bank_count ) ) . '</div></div>';
		echo '<div class="saw-stat"><div class="saw-stat__label">' . esc_html__( 'Demo competitions', 'nera-strikeawin' ) . '</div><div class="saw-stat__value" data-saw-stat="competitions">' . esc_html( number_format_i18n( count( $products ) ) ) . '</div></div>';
		echo '<div class="saw-stat"><div class="saw-stat__label">' . esc_html__( 'Demo users', 'nera-strikeawin' ) . '</div><div class="saw-stat__value" data-saw-stat="users">' . esc_html( number_format_i18n( $user_count ) ) . '</div></div>';
		echo '</div>';

		echo '<div id="saw-seeder-status" class="saw-seeder-status" role="status" aria-live="polite" aria-atomic="true">';
		echo '<div class="saw-seeder-status__head"><span class="saw-seeder-status__spinner" aria-hidden="true"></span><span class="saw-seeder-status__title"></span></div>';
		echo '<ul class="saw-seeder-status__log"></ul>';
		echo '</div>';

		if ( $is_prod ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This looks like a production environment. Seeding is blocked unless you tick the force option below.', 'nera-strikeawin' ) . '</p></div>';
		}

		echo '<div class="saw-actions">';

		// Seed card.
		echo '<div class="saw-card saw-action-card">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Seed demo data', 'nera-strikeawin' ) . '</h2>';
		echo '<p>' . esc_html__( 'Creates a graded trivia question bank (easy → expert), demo Strike A Win lottery competitions, and — optionally — demo quiz submissions: real paid WooCommerce orders from demo customers, with finalized runs and minted LFW tickets.', 'nera-strikeawin' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="saw-seeder-form" data-op="seed">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="op" value="seed">';

		// Pre-fill with the last-used values (saved on each Seed click), falling
		// back to the out-of-the-box defaults when nothing has been saved yet.
		$opts = Nera_SAW_Seeder::saved_opts();

		// --- Question bank --------------------------------------------------
		echo '<div class="saw-fieldset">';
		echo '<div class="saw-fieldset__legend">' . esc_html__( 'Question bank', 'nera-strikeawin' ) . '</div>';
		echo '<div class="saw-field-grid">';
		self::field( __( 'Questions per level', 'nera-strikeawin' ), 'questions_per_level', (int) $opts['questions_per_level'], 'min="1"' );
		echo '</div></div>';

		// --- Giveaways ------------------------------------------------------
		echo '<div class="saw-fieldset">';
		echo '<div class="saw-fieldset__legend">' . esc_html__( 'Giveaways', 'nera-strikeawin' ) . '</div>';
		echo '<div class="saw-field-grid">';
		self::field( __( 'Competitions (quiz enabled)', 'nera-strikeawin' ), 'products', (int) $opts['products'], 'min="1"' );
		self::range( __( 'Tickets per giveaway', 'nera-strikeawin' ), 'tickets_min', (int) $opts['tickets_min'], 'tickets_max', (int) $opts['tickets_max'], 'min="1"' );
		self::range( __( 'Quiz questions per level', 'nera-strikeawin' ), 'dist_min', (int) $opts['dist_min'], 'dist_max', (int) $opts['dist_max'], 'min="1"' );
		echo '</div></div>';

		// --- Submissions & customers ---------------------------------------
		echo '<div class="saw-fieldset">';
		echo '<div class="saw-fieldset__legend">' . esc_html__( 'Submissions & customers', 'nera-strikeawin' ) . '</div>';
		echo '<div class="saw-field-grid">';
		self::field( __( 'Success submissions', 'nera-strikeawin' ), 'success_submissions', (int) $opts['success_submissions'], 'min="0"' );
		self::field( __( 'Error submissions', 'nera-strikeawin' ), 'error_submissions', (int) $opts['error_submissions'], 'min="0"' );
		self::field( __( 'Demo customers', 'nera-strikeawin' ), 'customers', (int) $opts['customers'], 'min="0"' );
		self::range( __( 'Wallet balance', 'nera-strikeawin' ), 'wallet_min', (float) $opts['wallet_min'], 'wallet_max', (float) $opts['wallet_max'], 'min="0" step="0.01"' );
		echo '</div></div>';

		echo '<p class="saw-muted">' . esc_html__( 'The simulator acts as demo players: each picks a random customer + competition, buys run grants on both tiers (wallet-paid), then plays. Success submissions complete the quiz (mint LFW tickets); error submissions get stuck mid-quiz and show the red “Errored” status so you can test Restore. Every step is logged to the Quiz Log. Set both to 0 to skip. Everything is demo-marked and removed by “Remove demo data”.', 'nera-strikeawin' ) . '</p>';

		if ( $is_prod ) {
			echo '<p><label><input type="checkbox" name="force" value="1"> ' . esc_html__( 'Force seed on production (I understand)', 'nera-strikeawin' ) . '</label></p>';
		}
		submit_button( __( 'Seed demo data', 'nera-strikeawin' ), 'primary saw-btn-primary', 'submit', false );
		echo '</form>';
		echo '</div>';

		// Wipe card.
		echo '<div class="saw-card saw-action-card saw-action-card--danger">';
		echo '<h2 class="saw-card__head">' . esc_html__( 'Clean up demo data', 'nera-strikeawin' ) . '</h2>';
		echo '<p>' . esc_html__( 'Deletes only marker-stamped demo data: demo questions, competitions (pool / reservations / runs / grants), simulated orders, customers and their wallet transactions, minted LFW tickets, and the demo quiz-log rows. Real data is untouched.', 'nera-strikeawin' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="saw-seeder-form" data-op="wipe" data-confirm="' . esc_attr__( 'Remove all Strike A Win demo data? This cannot be undone.', 'nera-strikeawin' ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="op" value="wipe">';
		submit_button( __( 'Remove demo data', 'nera-strikeawin' ), 'primary saw-btn-wipe', 'submit', false );
		echo '</form>';
		echo '</div>';

		echo '</div>'; // .saw-actions
		echo '</div>'; // .wrap
	}
}
