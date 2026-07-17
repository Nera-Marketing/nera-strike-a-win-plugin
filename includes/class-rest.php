<?php
/**
 * REST API for the quiz run (drip delivery + server scoring).
 *
 * Namespace: nera-saw/v1
 *   POST /run/start                 { competition_id, tier }
 *   GET  /run/(?P<id>\d+)/slot/(?P<n>\d+)
 *   POST /run/(?P<id>\d+)/answer     { slot, option }
 *   GET  /competition/(?P<id>\d+)/pool
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Rest
 */
class Nera_SAW_Rest {

	const NS = 'nera-saw/v1';

	/**
	 * Register routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Only logged-in users may play (login required, incl. at demo).
	 *
	 * @return bool
	 */
	public static function require_login() {
		return is_user_logged_in();
	}

	/**
	 * Define routes.
	 */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/run/start',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'args'                => array(
					'competition_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'tier'           => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
					'start_token'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				),
				'callback'            => array( __CLASS__, 'start' ),
			)
		);

		register_rest_route(
			self::NS,
			'/run/(?P<id>\d+)/slot/(?P<n>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'callback'            => array( __CLASS__, 'slot' ),
			)
		);

		register_rest_route(
			self::NS,
			'/run/(?P<id>\d+)/answer',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'args'                => array(
					'slot'   => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'option' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				),
				'callback'            => array( __CLASS__, 'answer' ),
			)
		);

		register_rest_route(
			self::NS,
			'/run/(?P<id>\d+)/complete',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'callback'            => array( __CLASS__, 'complete' ),
			)
		);

		register_rest_route(
			self::NS,
			'/run/(?P<id>\d+)/abandon',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'callback'            => array( __CLASS__, 'abandon' ),
			)
		);

		register_rest_route(
			self::NS,
			'/competition/(?P<id>\d+)/pool',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'pool' ),
			)
		);

		// Client-side diagnostic reporting (errors + stalled requests). Login-only,
		// scoped to the caller's own run; feeds the Quiz Log.
		register_rest_route(
			self::NS,
			'/log',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'require_login' ),
				'callback'            => array( __CLASS__, 'client_log' ),
			)
		);
	}

	/**
	 * POST /run/start
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start( WP_REST_Request $req ) {
		$competition_id = (int) $req['competition_id'];
		$tier           = (string) $req->get_param( 'tier' );
		$token          = (string) $req->get_param( 'start_token' );

		// A run is a paid asset: it can be started ONLY from a deliberate Start
		// click on the Overview screen, which issues this per-user/competition/tier
		// token. A bare/pasted/bookmarked/prefetched URL carries no valid token and
		// cannot consume a run (ADR 0007). Resuming an already-active run still
		// requires a valid token (the Overview re-issues one each load).
		if ( ! wp_verify_nonce( $token, self::start_token_action( $competition_id, $tier ) ) ) {
			return new WP_Error(
				'saw_bad_token',
				__( 'Your play session expired. Reload the page to start again.', 'nera-strikeawin' ),
				array( 'status' => 403 )
			);
		}

		$result = Nera_SAW_Run::start( $competition_id, $tier, get_current_user_id() );
		if ( ! is_wp_error( $result ) ) {
			Nera_SAW_Log::add(
				'run_start',
				array(
					'run_id'         => (int) ( $result['run_id'] ?? 0 ),
					'competition_id' => $competition_id,
					'tier_key'       => $tier,
					'user_id'        => get_current_user_id(),
					'message'        => sprintf( 'Run started (%s)', (string) ( $result['status'] ?? '' ) ),
					'context'        => array(
						'status'      => $result['status'] ?? '',
						'total_slots' => $result['total_slots'] ?? 0,
					),
				)
			);
		}
		return self::respond( $result, array( 'op' => 'start', 'competition_id' => $competition_id, 'tier_key' => $tier ) );
	}

	/**
	 * Nonce action for a Start token, scoped to competition + tier so a token for
	 * one entry cannot start another.
	 *
	 * @param int    $competition_id Competition product ID.
	 * @param string $tier           Tier key.
	 * @return string
	 */
	public static function start_token_action( $competition_id, $tier ) {
		return 'saw_start_run_' . (int) $competition_id . '_' . sanitize_key( (string) $tier );
	}

	/**
	 * GET /run/{id}/slot/{n}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function slot( WP_REST_Request $req ) {
		$result = Nera_SAW_Run::serve_slot( (int) $req['id'], get_current_user_id(), (int) $req['n'] );
		return self::respond( $result, array( 'op' => 'slot', 'run_id' => (int) $req['id'], 'slot_no' => (int) $req['n'] ) );
	}

	/**
	 * POST /run/{id}/answer
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function answer( WP_REST_Request $req ) {
		$result = Nera_SAW_Run::submit_answer(
			(int) $req['id'],
			get_current_user_id(),
			(int) $req['slot'],
			(string) $req->get_param( 'option' )
		);
		if ( ! is_wp_error( $result ) ) {
			$r       = isset( $result['result'] ) ? (array) $result['result'] : array();
			$outcome = ! empty( $r['correct'] ) ? 'correct' : ( ! empty( $r['timed_out'] ) ? 'timeout' : 'wrong' );
			Nera_SAW_Log::add(
				'answer',
				array(
					'run_id'         => (int) $req['id'],
					'slot_no'        => (int) $req['slot'],
					'competition_id' => (int) ( $result['competition_id'] ?? 0 ),
					'tier_key'       => (string) ( $result['tier_key'] ?? '' ),
					'user_id'        => get_current_user_id(),
					'message'        => sprintf( 'Answer slot %d: %s (+%d tickets)', (int) $req['slot'], $outcome, (int) ( $r['spins_awarded'] ?? 0 ) ),
					'context'        => $r,
				)
			);
		}
		return self::respond( $result, array( 'op' => 'answer', 'run_id' => (int) $req['id'], 'slot_no' => (int) $req['slot'] ) );
	}

	/**
	 * POST /run/{id}/complete — mint tickets + return summary (deferred from last answer).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function complete( WP_REST_Request $req ) {
		$result = Nera_SAW_Run::complete_run( (int) $req['id'], get_current_user_id() );
		if ( ! is_wp_error( $result ) ) {
			$minted = is_array( $result['ticket_numbers'] ?? null ) ? count( $result['ticket_numbers'] ) : 0;
			Nera_SAW_Log::add(
				'run_complete',
				array(
					'run_id'         => (int) $req['id'],
					'competition_id' => (int) ( $result['competition_id'] ?? 0 ),
					'tier_key'       => (string) ( $result['tier_key'] ?? '' ),
					'order_id'       => (int) ( $result['order_id'] ?? 0 ),
					'user_id'        => get_current_user_id(),
					'message'        => sprintf( 'Run complete: %d tickets won, %d minted', (int) ( $result['spins_final'] ?? 0 ), $minted ),
					'context'        => array( 'spins_final' => (int) ( $result['spins_final'] ?? 0 ), 'minted' => $minted ),
				)
			);
		}
		return self::respond( $result, array( 'op' => 'complete', 'run_id' => (int) $req['id'] ) );
	}

	/**
	 * POST /run/{id}/abandon — the player left mid-quiz: finalize now, scoring
	 * unanswered questions zero and recording them (ADR 0010). Called both by an
	 * awaited fetch (custom leave card) and by navigator.sendBeacon (page unload);
	 * the underlying operation is idempotent.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function abandon( WP_REST_Request $req ) {
		$result = Nera_SAW_Run::abandon( (int) $req['id'], get_current_user_id() );
		if ( ! is_wp_error( $result ) ) {
			Nera_SAW_Log::add(
				'run_abandon',
				array(
					'run_id'         => (int) $req['id'],
					'competition_id' => (int) ( $result['competition_id'] ?? 0 ),
					'tier_key'       => (string) ( $result['tier_key'] ?? '' ),
					'order_id'       => (int) ( $result['order_id'] ?? 0 ),
					'user_id'        => get_current_user_id(),
					'message'        => sprintf( 'Run abandoned (left mid-quiz): %d tickets kept', (int) ( $result['spins_final'] ?? 0 ) ),
					'context'        => array( 'spins_final' => (int) ( $result['spins_final'] ?? 0 ) ),
				)
			);
		}
		return self::respond( $result, array( 'op' => 'abandon', 'run_id' => (int) $req['id'] ) );
	}

	/**
	 * POST /log — record a client-side diagnostic event (error or stalled request)
	 * against the caller's own run. Feeds the Quiz Log so a browser-side lock
	 * (frozen screen, stopped countdown) leaves a trace the admin can inspect.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function client_log( WP_REST_Request $req ) {
		$event = sanitize_key( (string) $req->get_param( 'event' ) );
		if ( ! in_array( $event, array( 'client_error', 'client_stall' ), true ) ) {
			$event = 'client_error';
		}

		// Only accept a run_id that belongs to the caller (prevents cross-user noise).
		$run_id = (int) $req->get_param( 'run_id' );
		$run    = null;
		if ( $run_id > 0 ) {
			$run = Nera_SAW_Run::get( $run_id );
			if ( ! $run || (int) $run->user_id !== get_current_user_id() ) {
				$run_id = 0;
				$run    = null;
			}
		}

		Nera_SAW_Log::error(
			$event,
			(string) $req->get_param( 'message' ),
			array(
				'run_id'         => $run_id,
				'competition_id' => $run ? (int) $run->competition_id : (int) $req->get_param( 'competition_id' ),
				'tier_key'       => $run ? (string) $run->tier_key : '',
				'user_id'        => get_current_user_id(),
				'slot_no'        => (int) $req->get_param( 'slot' ),
				'context'        => array(
					'phase'  => sanitize_text_field( (string) $req->get_param( 'phase' ) ),
					'path'   => sanitize_text_field( (string) $req->get_param( 'path' ) ),
					'status' => (int) $req->get_param( 'status' ),
				),
			)
		);

		// A client error/stall means the run may be stuck mid-quiz: flag it so an
		// admin can restore it (ADR 0013). No-op unless still active + owned.
		if ( $run_id > 0 ) {
			Nera_SAW_Run::mark_errored( $run_id, get_current_user_id() );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * GET /competition/{id}/pool — live counter (no per-tier odds; win-chance is the wheel's).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function pool( WP_REST_Request $req ) {
		$row = Nera_SAW_Spin_Pool::get( (int) $req['id'] );
		if ( ! $row ) {
			return new WP_REST_Response( array( 'exists' => false ), 200 );
		}
		return new WP_REST_Response(
			array(
				'exists'    => true,
				'cap'       => (int) $row->cap,
				'available' => (int) $row->available,
				'reserved'  => (int) $row->reserved,
				'confirmed' => (int) $row->confirmed,
				'status'    => $row->status,
			),
			200
		);
	}

	/**
	 * Normalise a service result (array or WP_Error) to a REST response. Every
	 * WP_Error returned to the client is logged (the choke point for server errors);
	 * $ctx carries the op/run/slot so the log row is diagnosable.
	 *
	 * @param mixed $result Result.
	 * @param array $ctx    Optional context: op, run_id, competition_id, tier_key, slot_no.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function respond( $result, array $ctx = array() ) {
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			Nera_SAW_Log::error(
				'error',
				$result->get_error_message(),
				array(
					'run_id'         => (int) ( $ctx['run_id'] ?? 0 ),
					'competition_id' => (int) ( $ctx['competition_id'] ?? 0 ),
					'tier_key'       => (string) ( $ctx['tier_key'] ?? '' ),
					'slot_no'        => (int) ( $ctx['slot_no'] ?? 0 ),
					'user_id'        => get_current_user_id(),
					'message'        => $result->get_error_message(),
					'context'        => array(
						'op'     => (string) ( $ctx['op'] ?? '' ),
						'code'   => $result->get_error_code(),
						'status' => $status,
					),
				)
			);
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}
		return new WP_REST_Response( $result, 200 );
	}
}
