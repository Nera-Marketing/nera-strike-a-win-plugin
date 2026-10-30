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
 * `per_ip` per IP in `window` seconds; `tries` wrong codes void it (filter
 * `nera_saw_free_code_limits`). A new code voids the old one.
 *
 * The right code is the login: a new address becomes a WooCommerce customer, an
 * address that already has an account logs into it (the code proves the address,
 * as a password reset link does). Accounts that can edit content and accounts a
 * login gate refuses (`wp_authenticate_user`) are not logged in this way. A
 * logged-in visitor verifies their own account's address only.
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
	 * User meta written when an address is proved by a code.
	 */
	const META_VERIFIED  = '_nera_saw_email_verified';
	const META_CONSENT   = '_nera_saw_free_consent';
	const META_PHONE     = '_nera_saw_phone_e164';
	const META_MARKETING = '_nera_saw_marketing_optin';

	/**
	 * Error and notice of the verify screen's plain form post, for its template.
	 *
	 * @var array{error:WP_Error|null,notice:string}
	 */
	private static $verify_state = array( 'error' => null, 'notice' => '' );

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
	 * @return array{ttl:int,wait:int,per_email:int,per_ip:int,window:int,tries:int}
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
				'tries'     => 5,
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
		return self::issue_code( $fields );
	}

	/**
	 * Store a pending sign-up and email it a new code, within the send limits.
	 *
	 * @param array $fields Normalised fields (see validate()); `email` is required.
	 * @return true|WP_Error
	 */
	public static function issue_code( array $fields ) {
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
				'sends'          => $sends,
				'competition_id' => $fields['competition_id'],
				'fields'         => $fields,
			),
			$limits['window']
		);
		self::reset_tries( $key, $limits['window'] );
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

	/**
	 * Send a new code to an address that has a pending sign-up (same fields).
	 *
	 * @param string $email Address.
	 * @return true|WP_Error
	 */
	public static function resend( $email ) {
		$record = '' !== (string) $email ? get_transient( self::record_key( $email ) ) : false;
		if ( ! is_array( $record ) || empty( $record['fields'] ) ) {
			return self::code_error( 'saw_code_expired' );
		}
		return self::issue_code( (array) $record['fields'] );
	}

	/* ---------------------------------------------------------------------
	 * Checking the code
	 * ------------------------------------------------------------------ */

	/**
	 * Check a code and log its owner in: a new customer for a new address, the
	 * existing account otherwise. A logged-in visitor stays logged in and only their
	 * own address is accepted.
	 *
	 * @param string $email Address the code was sent to.
	 * @param string $code  6 digits.
	 * @param array  $extra `consent`: the 18+ and free rules tick, needed when the
	 *                      pending sign-up has none (a logged-in account).
	 * @return int|WP_Error User ID.
	 */
	public static function verify( $email, $code, array $extra = array() ) {
		$email   = strtolower( trim( (string) $email ) );
		$code    = preg_replace( '/\D+/', '', (string) $code );
		$limits  = self::limits();
		$current = is_user_logged_in() ? wp_get_current_user() : null;

		// An old pending cookie for another address must not switch accounts.
		if ( $current && strtolower( (string) $current->user_email ) !== $email ) {
			return self::code_error( 'saw_code_account' );
		}

		$key    = '' !== $email ? self::record_key( $email ) : '';
		$record = '' !== $key ? get_transient( $key ) : false;
		if ( ! is_array( $record ) || empty( $record['code_hash'] ) ) {
			return self::code_error( 'saw_code_expired' );
		}
		// Anyone can start a sign-up for any address, so a logged-in account takes
		// nothing (phone, marketing, consent) from the pending record: only its own tick.
		$fields = ! $current && isset( $record['fields'] ) ? (array) $record['fields'] : array();

		if ( empty( $fields['consent_at'] ) && empty( $extra['consent'] ) ) {
			$message = Nera_SAW_I18n::t( 'Tick this box to continue.' );
			return new WP_Error( 'saw_invalid', $message, array( 'status' => 400, 'fields' => array( 'consent' => $message ) ) );
		}
		if ( (int) $record['expires'] <= time() ) {
			return self::code_error( 'saw_code_expired' );
		}

		// The try is counted before the code is checked, atomically, so requests sent
		// at the same time cannot share one count.
		$try = self::claim_try( $key, $limits['tries'] );
		if ( is_string( $try ) ) {
			return self::code_error( $try );
		}
		if ( 6 !== strlen( $code ) || ! wp_check_password( $code, $record['code_hash'] ) ) {
			return self::code_error( $try >= $limits['tries'] ? 'saw_code_void' : 'saw_code_wrong' );
		}

		// A code works once, whatever happens next: only the request that removes the
		// counter goes on.
		if ( ! self::drop_tries( $key ) ) {
			return self::code_error( 'saw_code_expired' );
		}
		self::forget( $key );

		if ( $current ) {
			$user = $current;
		} else {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				// Staff accounts keep their password (and any second factor).
				if ( user_can( $user, 'edit_posts' ) ) {
					return self::code_error( 'saw_use_login' );
				}
				// The login gates a password login passes through (self-exclusion…).
				$user = apply_filters( 'wp_authenticate_user', $user, '' );
				if ( is_wp_error( $user ) ) {
					$user->add_data( array( 'status' => 403, 'field' => 'code' ) );
					return $user;
				}
			} else {
				$id = wc_create_new_customer( $email, wc_create_new_customer_username( $email ), wp_generate_password() );
				if ( is_wp_error( $id ) ) {
					$id->add_data( array( 'status' => 500, 'field' => 'code' ) );
					return $id;
				}
				$user = get_user_by( 'id', $id );
				update_user_meta( $id, 'billing_email', $email );
			}
		}

		self::mark_verified( (int) $user->ID, $fields, ! empty( $extra['consent'] ) );

		if ( ! $current ) {
			wc_set_customer_auth_cookie( $user->ID );
			do_action( 'wp_login', $user->user_login, $user );
		}
		return (int) $user->ID;
	}

	/**
	 * Whether an account's address has been proved by a code.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_verified( $user_id ) {
		return (int) get_user_meta( (int) $user_id, self::META_VERIFIED, true ) > 0;
	}

	/**
	 * Wrong-code counter of a pending sign-up. It has its own row (stored as a
	 * transient, so the expired-transient sweep removes leftovers) because the
	 * record's value cannot be raised atomically.
	 *
	 * @param string $key Record transient name.
	 * @return string Transient name.
	 */
	private static function tries_name( $key ) {
		return 'nera_saw_free_try_' . md5( (string) $key );
	}

	/**
	 * Start the counter at 0 for a new code.
	 *
	 * @param string $key Record transient name.
	 * @param int    $ttl Seconds to keep it.
	 */
	private static function reset_tries( $key, $ttl ) {
		global $wpdb;
		$name = self::tries_name( $key );
		$rows = array(
			'_transient_timeout_' . $name => (string) ( time() + (int) $ttl ),
			'_transient_' . $name         => '0',
		);
		foreach ( $rows as $option => $value ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $option, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( $option, 'options' );
		}
	}

	/**
	 * Tries used on the current code, or null when it has no counter (used or gone).
	 *
	 * @param string $key Record transient name.
	 * @return int|null
	 */
	public static function tries( $key ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . self::tries_name( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null === $value ? null : (int) $value;
	}

	/**
	 * Count one try in a single UPDATE, refused once the limit is reached.
	 *
	 * @param string $key   Record transient name.
	 * @param int    $limit Tries allowed.
	 * @return int|string This try's number, or the error code when none is left.
	 */
	private static function claim_try( $key, $limit ) {
		global $wpdb;
		$option = '_transient_' . self::tries_name( $key );
		$done   = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( CAST( option_value AS UNSIGNED ) + 1 ) WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d", $option, (int) $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $option, 'options' );
		if ( ! $done ) {
			return null === self::tries( $key ) ? 'saw_code_expired' : 'saw_code_void';
		}
		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Remove the counter; true only for the one request that removed it.
	 *
	 * @param string $key Record transient name.
	 * @return bool
	 */
	private static function drop_tries( $key ) {
		global $wpdb;
		$name = self::tries_name( $key );
		$done = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", '_transient_timeout_' . $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( '_transient_' . $name, 'options' );
		wp_cache_delete( '_transient_timeout_' . $name, 'options' );
		return 1 === (int) $done;
	}

	/**
	 * Store what the sign-up gave on the account. Never the age check of paid
	 * entries: the 18+ tick is self-declared.
	 *
	 * @param int   $user_id User ID.
	 * @param array $fields  Pending sign-up fields.
	 * @param bool  $ticked  The tick was given on the verify screen.
	 */
	private static function mark_verified( $user_id, array $fields, $ticked ) {
		$now = time();
		update_user_meta( $user_id, self::META_VERIFIED, $now );

		$consent = ! empty( $fields['consent_at'] ) ? (int) $fields['consent_at'] : ( $ticked ? $now : 0 );
		if ( $consent ) {
			update_user_meta( $user_id, self::META_CONSENT, $consent );
		}
		if ( ! empty( $fields['phone'] ) ) {
			update_user_meta( $user_id, self::META_PHONE, (string) $fields['phone'] );
			if ( '' === (string) get_user_meta( $user_id, 'billing_phone', true ) ) {
				update_user_meta( $user_id, 'billing_phone', (string) $fields['phone'] );
			}
		}
		if ( isset( $fields['marketing'] ) && in_array( $fields['marketing'], array( 'yes', 'no' ), true ) ) {
			update_user_meta( $user_id, self::META_MARKETING, $fields['marketing'] );
			update_user_meta( $user_id, self::META_MARKETING . '_at', $now );
		}
	}

	/**
	 * Drop a used pending sign-up and this browser's pointer to it.
	 *
	 * @param string $key Record transient name.
	 */
	private static function forget( $key ) {
		delete_transient( $key );
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' !== $token ) {
			delete_transient( self::TOKEN_PREFIX . $token );
		}
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', array( 'expires' => time() - YEAR_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Errors of the code step, shown under the code boxes.
	 *
	 * @param string $code saw_code_wrong, saw_code_void, saw_code_expired, saw_code_account, saw_use_login.
	 * @return WP_Error
	 */
	private static function code_error( $code ) {
		$errors = array(
			'saw_code_wrong'   => array( 400, 'That code is not right. Check the email and try again.' ),
			'saw_code_void'    => array( 429, 'Too many wrong codes. Ask for a new one.' ),
			'saw_code_expired' => array( 410, 'This code has expired. Ask for a new one.' ),
			'saw_code_account' => array( 403, 'This code is for another email address.' ),
			'saw_use_login'    => array( 403, 'This account signs in with its password. Use Sign in.' ),
		);
		$e = $errors[ $code ];
		return new WP_Error( $code, Nera_SAW_I18n::t( $e[1] ), array( 'status' => $e[0], 'field' => 'code' ) );
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
	 * @return array|null The record (email, code_hash, expires, sends, fields…) plus its `key`.
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
	 * Where a verified player goes: the Free page.
	 *
	 * @return string
	 */
	public static function free_url() {
		$page = Nera_SAW_Free::page_url();
		$page = '' !== $page ? $page : home_url( '/free/' );
		return class_exists( 'Nera_SAW_Language' ) ? Nera_SAW_Language::carry_current_language( $page ) : $page;
	}

	/**
	 * Address the code step works on: the account's when logged in, else the
	 * pending sign-up's (from the cookie), never a request parameter.
	 *
	 * @return string
	 */
	public static function session_email() {
		if ( is_user_logged_in() ) {
			return strtolower( (string) wp_get_current_user()->user_email );
		}
		$pending = self::pending();
		return $pending ? (string) $pending['email'] : '';
	}

	/**
	 * A logged-in account that has not proved its address: make sure a live code
	 * is on its way. A code still running is reused, so a reload sends nothing.
	 *
	 * @param WP_User $user  Account.
	 * @param int     $asked Competition ID from the link.
	 * @return WP_Error|null Why no code could be sent.
	 */
	private static function ensure_account_code( WP_User $user, $asked ) {
		$email  = strtolower( (string) $user->user_email );
		$key    = self::record_key( $email );
		$record = get_transient( $key );
		$tries  = self::tries( $key );
		$fields = array(
			'email'          => $email,
			'phone'          => '',
			'country'        => '',
			'consent_at'     => 0,
			'competition_id' => self::competition( $asked ),
		);
		if ( ! is_array( $record ) || (int) $record['expires'] <= time() || null === $tries || $tries >= self::limits()['tries'] ) {
			$sent = self::issue_code( $fields );
			if ( true !== $sent ) {
				return $sent;
			}
		} elseif ( ! empty( $record['fields']['phone'] ) || ! empty( $record['fields']['consent_at'] ) || isset( $record['fields']['marketing'] ) ) {
			// A sign-up someone started for this address while logged out: its code
			// still went to the account's inbox, but its phone and ticks are not the account's.
			$record['fields'] = $fields;
			set_transient( $key, $record, self::limits()['window'] );
		}
		$pending = self::pending();
		if ( ! $pending || $pending['email'] !== $email ) {
			self::remember( $email );
		}
		return null;
	}

	/**
	 * Both screens are per visitor: never cached. Plain form posts are handled
	 * here. A logged-in account that proved its address goes to the Free page; one
	 * that has not goes to the code step with a code sent to its address. The code
	 * step without a pending sign-up goes back to the form.
	 */
	public static function handle_screens() {
		$route = Nera_SAW_Router::current_route();
		if ( 'free-join' !== $route && 'free-verify' !== $route ) {
			return;
		}
		nocache_headers();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which competition the link is for.
		$asked = isset( $_GET['c'] ) ? absint( $_GET['c'] ) : 0;
		$user  = is_user_logged_in() ? wp_get_current_user() : null;
		$post  = 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		if ( $user && self::is_verified( $user->ID ) ) {
			wp_safe_redirect( self::free_url() );
			exit;
		}

		if ( 'free-verify' === $route ) {
			if ( $user ) {
				self::$verify_state['error'] = self::ensure_account_code( $user, $asked );
			} elseif ( ! self::pending() ) {
				wp_safe_redirect( self::url( 'join' ) );
				exit;
			}
			if ( $post && isset( $_POST['saw_free_verify'] ) ) {
				self::handle_verify_post();
			}
			return;
		}

		if ( $user ) {
			wp_safe_redirect( self::url( 'verify', $asked ? array( 'c' => $asked ) : array() ) );
			exit;
		}

		if ( ! $post || ! isset( $_POST['saw_free_join'] ) ) {
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
	 * The code form posted without script: the code, "Resend code" or "Change email".
	 */
	private static function handle_verify_post() {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			self::$verify_state['error'] = new WP_Error( 'saw_expired', Nera_SAW_I18n::t( 'This page has expired. Reload it and try again.' ), array( 'status' => 403, 'field' => 'code' ) );
			return;
		}
		$email = self::session_email();

		if ( isset( $_POST['saw_free_change'] ) && ! is_user_logged_in() ) {
			wp_safe_redirect( self::url( 'join' ) );
			exit;
		}
		if ( isset( $_POST['saw_free_resend'] ) ) {
			$sent = self::resend( $email );
			if ( true === $sent ) {
				self::$verify_state = array( 'error' => null, 'notice' => Nera_SAW_I18n::t( 'New code sent to {e}', array( 'e' => $email ) ) );
			} else {
				self::$verify_state['error'] = $sent;
			}
			return;
		}

		$code = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- digits only are kept.
		$code = is_array( $code ) ? implode( '', array_map( 'strval', $code ) ) : (string) $code;
		$done = self::verify( $email, $code, array( 'consent' => ! empty( $_POST['consent'] ) ) );
		if ( ! is_wp_error( $done ) ) {
			wp_safe_redirect( self::free_url() );
			exit;
		}
		self::$verify_state['error'] = $done;
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
		$posted  = self::$posted ? self::$posted : array( 'values' => self::pending_values(), 'errors' => array() );
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
	 * The pending sign-up's form values ("Change email" comes back to them).
	 *
	 * @return array
	 */
	private static function pending_values() {
		$pending = is_user_logged_in() ? null : self::pending();
		$f       = $pending && ! empty( $pending['fields'] ) ? (array) $pending['fields'] : array();
		if ( empty( $f['email'] ) ) {
			return array();
		}
		$country = (string) ( $f['country'] ?? '' );
		$dial    = isset( self::COUNTRIES[ $country ] ) ? '+' . self::COUNTRIES[ $country ] : '';
		$phone   = (string) ( $f['phone'] ?? '' );
		return array(
			'email'     => (string) $f['email'],
			'country'   => $country,
			'phone'     => '' !== $dial && 0 === strpos( $phone, $dial ) ? substr( $phone, strlen( $dial ) ) : $phone,
			'consent'   => ! empty( $f['consent_at'] ),
			'marketing' => 'yes' === ( $f['marketing'] ?? '' ),
		);
	}

	/**
	 * What the verify screen shows.
	 *
	 * @return array email, logged_in, consent (show the 18+ and free rules tick),
	 *               error, error_field ('code' or 'consent'), notice, rules_url,
	 *               action, rest_verify, rest_resend, nonce.
	 */
	public static function verify_view() {
		$email  = self::session_email();
		$error  = self::$verify_state['error'];
		$field  = '';
		if ( $error instanceof WP_Error ) {
			$field = array_key_first( self::field_errors( $error ) );
			$field = 'consent' === $field ? 'consent' : 'code';
		}
		return array(
			'email'       => $email,
			'logged_in'   => is_user_logged_in(),
			'consent'     => is_user_logged_in(),
			'error'       => $error instanceof WP_Error ? $error->get_error_message() : '',
			'error_field' => $field,
			'notice'      => (string) self::$verify_state['notice'],
			'rules_url'   => class_exists( 'Nera_SAW_Standalone_Pages' ) ? (string) Nera_SAW_Standalone_Pages::url( 'free-competition-rules' ) : '',
			'action'      => self::url( 'verify' ),
			'rest_verify' => rest_url( Nera_SAW_Rest::NS . '/free/verify' ),
			'rest_resend' => rest_url( Nera_SAW_Rest::NS . '/free/resend' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
		);
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
			return self::rest_error( $sent );
		}
		self::remember( $input['email'] );
		return rest_ensure_response( array( 'redirect' => self::url( 'verify' ) ) );
	}

	/**
	 * POST free/verify: check the code (`code`, plus `consent` when asked for) for
	 * this browser's sign-up or the logged-in account; logs in on success.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_verify( $request ) {
		$code = $request->get_param( 'code' );
		$code = is_array( $code ) ? implode( '', array_map( 'strval', $code ) ) : (string) $code;
		$done = self::verify( self::session_email(), $code, array( 'consent' => ! empty( $request->get_param( 'consent' ) ) ) );
		if ( is_wp_error( $done ) ) {
			return self::rest_error( $done );
		}
		return rest_ensure_response( array( 'redirect' => self::free_url() ) );
	}

	/**
	 * POST free/resend: a new code for this browser's sign-up or the logged-in account.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_resend( $request ) {
		unset( $request );
		$email = self::session_email();
		$sent  = self::resend( $email );
		if ( is_wp_error( $sent ) ) {
			return self::rest_error( $sent );
		}
		return rest_ensure_response( array( 'message' => Nera_SAW_I18n::t( 'New code sent to {e}', array( 'e' => $email ) ) ) );
	}

	/**
	 * An error with its field => message map, for the inline errors.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private static function rest_error( WP_Error $error ) {
		$data           = (array) $error->get_error_data();
		$data['fields'] = self::field_errors( $error );
		$error->add_data( $data );
		return $error;
	}
}
