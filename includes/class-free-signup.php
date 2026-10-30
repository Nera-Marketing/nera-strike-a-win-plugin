<?php
/**
 * Free competitions: sign up with an email code.
 *
 * /free/join/ takes an email, a mobile number, the 18+ and free rules tick and an
 * optional marketing tick, then emails a 6-digit code and sends the visitor to
 * /free/verify/. The form posts by script to REST `free/code` and also works as a
 * plain form post to /free/join/.
 *
 * Nothing is written to the database tables: the sign-up waits in a transient keyed
 * by a hash of the normalised email (`record_key()`), holding the code hash, its
 * expiry, the send times and the form fields. The verify screen finds it through a
 * random token in an httponly cookie (`COOKIE`), so the address never travels in a
 * URL.
 *
 * Code rules: 6 random digits, stored only as a password hash, valid `ttl` seconds;
 * another send waits `wait` seconds; at most `per_email` sends per address and
 * `per_ip` per IP in `window` seconds (filter `nera_saw_free_code_limits`). A new
 * code voids the old one.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Free_Signup
 */
class Nera_SAW_Free_Signup {

	/**
	 * Cookie holding the pending sign-up's token.
	 */
	const COOKIE = 'nera_saw_free_pending';

	/**
	 * Transient prefixes: the pending record, the cookie token, the IP counter.
	 */
	const RECORD_PREFIX = 'nera_saw_free_su_';
	const TOKEN_PREFIX  = 'nera_saw_free_tok_';
	const IP_PREFIX     = 'nera_saw_free_ip_';

	/**
	 * Countries offered for the mobile number: ISO code => dialling code, GB first.
	 */
	const COUNTRIES = array(
		'GB' => '44',
		'AE' => '971',
		'RU' => '7',
		'KZ' => '7',
		'UA' => '380',
		'BY' => '375',
		'GE' => '995',
		'AM' => '374',
		'AZ' => '994',
		'UZ' => '998',
		'KG' => '996',
		'IL' => '972',
		'CY' => '357',
		'TR' => '90',
		'DE' => '49',
		'FR' => '33',
		'ES' => '34',
		'IT' => '39',
		'NL' => '31',
		'PL' => '48',
		'LV' => '371',
		'LT' => '370',
		'EE' => '372',
		'US' => '1',
	);

	/**
	 * Errors and values of a plain form post that failed, for the join template.
	 *
	 * @var array|null
	 */
	private static $posted = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_screens' ), 5 );
	}

	/* ---------------------------------------------------------------------
	 * Rules
	 * ------------------------------------------------------------------ */

	/**
	 * Code limits.
	 *
	 * @return array{ttl:int,wait:int,per_email:int,per_ip:int,window:int}
	 */
	public static function limits() {
		$limits = (array) apply_filters(
			'nera_saw_free_code_limits',
			array(
				'ttl'       => 10 * MINUTE_IN_SECONDS,
				'wait'      => MINUTE_IN_SECONDS,
				'per_email' => 5,
				'per_ip'    => 10,
				'window'    => HOUR_IN_SECONDS,
			)
		);
		return array_map( 'intval', $limits );
	}

	/**
	 * The free competition a sign-up is for: the one asked for when it is free and
	 * still open, else the open one closing soonest; 0 when none is open.
	 *
	 * @param int $asked Competition ID from the link, 0 for none.
	 * @return int
	 */
	public static function competition( $asked ) {
		$asked = (int) $asked;
		if ( $asked > 0 && Nera_SAW_Competition_Config::is_free( $asked ) && 'publish' === get_post_status( $asked ) ) {
			$spec = Nera_SAW_Competition_Spec::get( $asked );
			if ( $spec && empty( $spec['closed'] ) ) {
				return $asked;
			}
		}
		return Nera_SAW_Free::current();
	}

	/**
	 * Check and normalise the form fields.
	 *
	 * @param array $input email, phone, country, consent, marketing, competition_id.
	 * @return array|WP_Error Normalised fields, or an error whose data names each bad field.
	 */
	public static function validate( array $input ) {
		$email   = strtolower( trim( sanitize_email( (string) ( $input['email'] ?? '' ) ) ) );
		$country = strtoupper( sanitize_key( (string) ( $input['country'] ?? '' ) ) );
		$digits  = preg_replace( '/\D+/', '', (string) ( $input['phone'] ?? '' ) );
		$consent = ! empty( $input['consent'] );

		// A national number written with its trunk 0 (07700 900123) drops it, except
		// where the 0 belongs to the number itself.
		if ( 'IT' !== $country && '0' === substr( $digits, 0, 1 ) ) {
			$digits = substr( $digits, 1 );
		}

		$fields = array();
		if ( '' === $email || ! is_email( $email ) ) {
			$fields['email'] = Nera_SAW_I18n::t( 'Enter a valid email address.' );
		}
		if ( ! isset( self::COUNTRIES[ $country ] ) || strlen( $digits ) < 6 || strlen( $digits ) > 14 ) {
			$fields['phone'] = Nera_SAW_I18n::t( 'Enter a valid mobile number.' );
		}
		if ( ! $consent ) {
			$fields['consent'] = Nera_SAW_I18n::t( 'Tick this box to continue.' );
		}
		if ( $fields ) {
			return new WP_Error( 'saw_invalid', reset( $fields ), array( 'status' => 400, 'fields' => $fields ) );
		}

		return array(
			'email'          => $email,
			'phone'          => '+' . self::COUNTRIES[ $country ] . $digits,
			'country'        => $country,
			'consent_at'     => time(),
			'marketing'      => ! empty( $input['marketing'] ) ? 'yes' : 'no',
			'competition_id' => self::competition( $input['competition_id'] ?? 0 ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Sending the code
	 * ------------------------------------------------------------------ */

	/**
	 * Validate the form, store the pending sign-up and email a new code.
	 *
	 * @param array $input See validate().
	 * @return true|WP_Error
	 */
	public static function send_code( array $input ) {
		$fields = self::validate( $input );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		if ( ! $fields['competition_id'] ) {
			return new WP_Error( 'saw_free_closed', Nera_SAW_I18n::t( 'No free competition is open right now.' ), array( 'status' => 409 ) );
		}

		$limits = self::limits();
		$now    = time();
		$key    = self::record_key( $fields['email'] );
		$record = get_transient( $key );
		$sends  = is_array( $record ) && isset( $record['sends'] ) ? self::recent( (array) $record['sends'], $now, $limits['window'] ) : array();

		if ( $sends && max( $sends ) > $now - $limits['wait'] ) {
			return new WP_Error(
				'saw_code_wait',
				Nera_SAW_I18n::t( 'Wait a minute before asking for another code.' ),
				array( 'status' => 429, 'field' => 'email', 'retry_after' => max( $sends ) + $limits['wait'] - $now )
			);
		}

		$ip_key   = self::ip_key( self::ip() );
		$ip_sends = self::recent( (array) get_transient( $ip_key ), $now, $limits['window'] );
		if ( count( $sends ) >= $limits['per_email'] || count( $ip_sends ) >= $limits['per_ip'] ) {
			return new WP_Error( 'saw_code_limit', Nera_SAW_I18n::t( 'Too many codes asked for. Try again in an hour.' ), array( 'status' => 429, 'field' => 'email' ) );
		}

		$code = sprintf( '%06d', random_int( 0, 999999 ) );

		$sends[]    = $now;
		$ip_sends[] = $now;
		set_transient(
			$key,
			array(
				'email'          => $fields['email'],
				'code_hash'      => wp_hash_password( $code ),
				'expires'        => $now + $limits['ttl'],
				'tries'          => 0,
				'sends'          => $sends,
				'competition_id' => $fields['competition_id'],
				'fields'         => $fields,
			),
			$limits['window']
		);
		set_transient( $ip_key, $ip_sends, $limits['window'] );

		if ( ! self::mail_code( $fields['email'], $code, $limits['ttl'] ) ) {
			return new WP_Error( 'saw_mail_failed', Nera_SAW_I18n::t( 'We could not send the email. Try again.' ), array( 'status' => 500, 'field' => 'email' ) );
		}

		return true;
	}

	/**
	 * Email the code, in the visitor's language.
	 *
	 * @param string $email Address.
	 * @param string $code  6 digits.
	 * @param int    $ttl   Seconds the code works.
	 * @return bool
	 */
	private static function mail_code( $email, $code, $ttl ) {
		$subject = Nera_SAW_I18n::t( 'Your unlock code' );
		$body    = Nera_SAW_I18n::t( 'Your unlock code is {code}.', array( 'code' => $code ) ) . "\n\n"
			. Nera_SAW_I18n::t( 'It works for {m} minutes. If you did not ask for it, ignore this email.', array( 'm' => (int) round( $ttl / MINUTE_IN_SECONDS ) ) ) . "\n";
		return (bool) wp_mail( $email, $subject, $body );
	}

	/**
	 * Times inside the window.
	 *
	 * @param array $times  Unix times.
	 * @param int   $now    Now.
	 * @param int   $window Seconds.
	 * @return int[]
	 */
	private static function recent( array $times, $now, $window ) {
		return array_values(
			array_filter(
				array_map( 'intval', $times ),
				static function ( $t ) use ( $now, $window ) {
					return $t > $now - $window;
				}
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Storage keys
	 * ------------------------------------------------------------------ */

	/**
	 * Transient name of the pending sign-up for an address.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function record_key( $email ) {
		return self::RECORD_PREFIX . substr( hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Transient name of the send counter for an IP.
	 *
	 * @param string $ip Address.
	 * @return string
	 */
	public static function ip_key( $ip ) {
		return self::IP_PREFIX . md5( (string) $ip );
	}

	/**
	 * The visitor's IP: REMOTE_ADDR only (forwarded headers are the visitor's to set).
	 *
	 * @return string
	 */
	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' !== $ip ? $ip : 'no-ip';
	}

	/**
	 * Point this browser at the pending sign-up for an address.
	 *
	 * @param string $email Address.
	 */
	public static function remember( $email ) {
		$token = wp_generate_password( 32, false );
		set_transient( self::TOKEN_PREFIX . $token, self::record_key( $email ), self::limits()['window'] );
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$token,
				array(
					'expires'  => time() + self::limits()['window'],
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $token;
	}

	/**
	 * The pending sign-up this browser started, or null.
	 *
	 * @return array|null The record (email, code_hash, expires, tries, sends, fields…) plus its `key`.
	 */
	public static function pending() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $token ) {
			return null;
		}
		$key    = (string) get_transient( self::TOKEN_PREFIX . $token );
		$record = '' !== $key ? get_transient( $key ) : false;
		if ( ! is_array( $record ) ) {
			return null;
		}
		$record['key'] = $key;
		return $record;
	}

	/* ---------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------ */

	/**
	 * /free/join/ or /free/verify/ under the Free page, in the current language.
	 *
	 * @param string $step 'join' or 'verify'.
	 * @param array  $args Query arguments.
	 * @return string
	 */
	public static function url( $step, array $args = array() ) {
		$page = Nera_SAW_Free::page_url();
		if ( '' === $page ) {
			$page = home_url( '/free/' );
		}
		$parts = explode( '?', $page, 2 );
		$url   = trailingslashit( $parts[0] ) . $step . '/' . ( isset( $parts[1] ) ? '?' . $parts[1] : '' );
		$url   = $args ? add_query_arg( $args, $url ) : $url;
		return class_exists( 'Nera_SAW_Language' ) ? Nera_SAW_Language::carry_current_language( $url ) : $url;
	}

	/**
	 * Both screens are per visitor: never cached. A plain form post is handled
	 * here; the verify screen without a pending sign-up goes back to the form.
	 */
	public static function handle_screens() {
		$route = Nera_SAW_Router::current_route();
		if ( 'free-join' !== $route && 'free-verify' !== $route ) {
			return;
		}
		nocache_headers();

		if ( 'free-verify' === $route ) {
			if ( ! self::pending() ) {
				wp_safe_redirect( self::url( 'join' ) );
				exit;
			}
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || ! isset( $_POST['saw_free_join'] ) ) {
			return;
		}

		$input = self::input_from( wp_unslash( $_POST ) );
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$sent  = wp_verify_nonce( $nonce, 'wp_rest' )
			? self::send_code( $input )
			: new WP_Error( 'saw_expired', Nera_SAW_I18n::t( 'This page has expired. Reload it and try again.' ), array( 'status' => 403, 'field' => 'email' ) );

		if ( true === $sent ) {
			self::remember( $input['email'] );
			wp_safe_redirect( self::url( 'verify' ) );
			exit;
		}

		self::$posted = array(
			'values' => $input,
			'errors' => self::field_errors( $sent ),
		);
	}

	/**
	 * Form fields from a request's parameters.
	 *
	 * @param array $params Raw parameters.
	 * @return array
	 */
	public static function input_from( array $params ) {
		return array(
			'email'          => sanitize_text_field( (string) ( $params['email'] ?? '' ) ),
			'phone'          => sanitize_text_field( (string) ( $params['phone'] ?? '' ) ),
			'country'        => sanitize_key( (string) ( $params['country'] ?? '' ) ),
			'consent'        => ! empty( $params['consent'] ),
			'marketing'      => ! empty( $params['marketing'] ),
			'competition_id' => absint( $params['c'] ?? 0 ),
		);
	}

	/**
	 * Field => message for an error from send_code().
	 *
	 * @param WP_Error $error Error.
	 * @return array
	 */
	public static function field_errors( WP_Error $error ) {
		$data = (array) $error->get_error_data();
		if ( ! empty( $data['fields'] ) ) {
			return (array) $data['fields'];
		}
		return array( isset( $data['field'] ) ? (string) $data['field'] : 'email' => $error->get_error_message() );
	}

	/**
	 * What the join screen shows.
	 *
	 * @return array competition_id, countries (ISO => '+code'), country, values, errors,
	 *               rules_url, rest_url, nonce.
	 */
	public static function join_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which competition to show.
		$asked   = isset( $_GET['c'] ) ? absint( $_GET['c'] ) : 0;
		$posted  = self::$posted ? self::$posted : array( 'values' => array(), 'errors' => array() );
		$values  = $posted['values'];
		$default = 'ru' === Nera_SAW_Language::current() ? 'RU' : 'GB';
		$country = isset( $values['country'] ) ? strtoupper( (string) $values['country'] ) : $default;

		return array(
			'competition_id' => self::competition( ! empty( $values['competition_id'] ) ? $values['competition_id'] : $asked ),
			'countries'      => array_map(
				static function ( $code ) {
					return '+' . $code;
				},
				self::COUNTRIES
			),
			'country'        => isset( self::COUNTRIES[ $country ] ) ? $country : $default,
			'values'         => $values,
			'errors'         => $posted['errors'],
			'rules_url'      => class_exists( 'Nera_SAW_Standalone_Pages' ) ? (string) Nera_SAW_Standalone_Pages::url( 'free-competition-rules' ) : '',
			'action'         => self::url( 'join' ),
			'rest_url'       => rest_url( Nera_SAW_Rest::NS . '/free/code' ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
		);
	}

	/**
	 * What the verify screen shows.
	 *
	 * @return array email.
	 */
	public static function verify_view() {
		$pending = self::pending();
		return array( 'email' => $pending ? (string) $pending['email'] : '' );
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------ */

	/**
	 * Public route, but only from a page this site served: the REST nonce is checked
	 * here because core skips it for logged-out visitors.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function rest_permission( $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}
		return new WP_Error( 'saw_expired', Nera_SAW_I18n::t( 'This page has expired. Reload it and try again.' ), array( 'status' => 403, 'field' => 'email' ) );
	}

	/**
	 * POST free/code: send a code; the answer says where to go next.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_send( $request ) {
		$input = self::input_from( (array) $request->get_params() );
		$sent  = self::send_code( $input );
		if ( is_wp_error( $sent ) ) {
			$data           = (array) $sent->get_error_data();
			$data['fields'] = self::field_errors( $sent );
			$sent->add_data( $data );
			return $sent;
		}
		self::remember( $input['email'] );
		return rest_ensure_response( array( 'redirect' => self::url( 'verify' ) ) );
	}
}
