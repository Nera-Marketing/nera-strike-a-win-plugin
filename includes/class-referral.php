<?php
/**
 * Referral: a player's personal link to the free competition, and one bonus
 * ticket each for the player and the friend once the friend qualifies.
 *
 * A friend qualifies when their account was created by the free sign-up while
 * this browser held a valid link code, their email is verified and they complete
 * a free quiz. Both bonus tickets go into the free competition the friend just
 * played, once per friend ever, up to the cap of rewarded friends per player in
 * each free competition. When that draw is closed, full or at the cap, they go
 * into the next open free competition that can take them, or wait as pending
 * until one opens.
 * See ADR 0040.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Referral
 */
class Nera_SAW_Referral {

	/**
	 * Cookie holding the link code the visitor arrived with.
	 */
	const COOKIE = 'nera_saw_ref';

	/**
	 * Readable by scripts (the code cookie is not): tells a cached page that this
	 * browser holds a code, so it can ask for the friend message.
	 */
	const MARKER = 'nera_saw_ref_seen';

	/**
	 * User meta: the player's link code.
	 */
	const META_CODE = '_nera_saw_ref_code';

	/**
	 * User meta on a friend: the referrer's user ID.
	 */
	const META_BY = '_nera_saw_referred_by';

	/**
	 * User meta on a friend: the device id their browser had at sign-up ('' if none).
	 */
	const META_DEVICE = '_nera_saw_ref_device';

	/**
	 * User meta on a friend: the competition their reward was minted in.
	 */
	const META_REWARDED = '_nera_saw_ref_rewarded';

	/**
	 * User meta on a referrer: IDs of the friends already rewarded, per free
	 * competition the bonus tickets landed in (competition ID => friend IDs).
	 */
	const META_FRIENDS = '_nera_saw_ref_friends_by_competition';

	/**
	 * User meta on a friend who qualified while no free competition could take the
	 * bonus: referrer, origin (competition played), run_id, qualified_at.
	 */
	const META_PENDING = '_nera_saw_ref_pending';

	/**
	 * Option: IDs of the pending friends.
	 */
	const OPTION_PENDING = 'nera_saw_ref_pending';

	/**
	 * Option set when a friend could not be added to the pending list: the catch-up
	 * rebuilds the list from the friends' pending meta.
	 */
	const OPTION_REINDEX = 'nera_saw_ref_pending_reindex';

	/**
	 * Transient that spaces out the pending catch-up on page requests.
	 */
	const CATCH_UP = 'nera_saw_ref_catch_up';

	/**
	 * Review reason on a friend's free grant: same IP as the referrer.
	 */
	const REVIEW = 'ref';

	/**
	 * `SW` and five uppercase letters or digits.
	 */
	const CODE_PATTERN = '/^SW[0-9A-Z]{5}$/';

	/**
	 * Rewarded friends per referrer per free competition when the setting is blank.
	 */
	const CAP_DEFAULT = 5;

	/**
	 * Hooks.
	 */
	public static function init() {
		// Ahead of the free sign-up screens, which redirect and exit.
		add_action( 'template_redirect', array( __CLASS__, 'keep_links_out_of_page_cache' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'capture' ), 4 );
		add_action( 'template_redirect', array( __CLASS__, 'keep_invite_out_of_page_cache' ), 5 );
		add_action( 'nera_saw_free_account_created', array( __CLASS__, 'attribute' ) );
		add_action( 'nera_saw_free_run_finalized', array( __CLASS__, 'reward' ), 20, 4 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_publish' ), 20, 4 );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_catch_up' ), 20 );
	}

	/**
	 * Whether referral is switched on (Settings; off by default).
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = Nera_SAW_Constants::settings();

		/**
		 * Filter whether referral links and rewards are on.
		 *
		 * @param bool $on Value from Settings.
		 */
		return (bool) apply_filters( 'nera_saw_referral_enabled', ! empty( $s['referral_enabled'] ) );
	}

	/**
	 * Most friends a player can be rewarded for in each free competition.
	 *
	 * @return int At least 1.
	 */
	public static function cap() {
		$s   = Nera_SAW_Constants::settings();
		$cap = isset( $s['referral_cap'] ) && '' !== $s['referral_cap'] ? (int) $s['referral_cap'] : self::CAP_DEFAULT;

		/**
		 * Filter the number of rewarded friends per referrer per free competition.
		 *
		 * @param int $cap Value from Settings.
		 */
		return max( 1, (int) apply_filters( 'nera_saw_referral_cap', $cap ) );
	}

	/**
	 * The player's link code, created on first use and stable after that.
	 *
	 * @param int $user_id User ID.
	 * @return string '' when the user does not exist.
	 */
	public static function code( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return '';
		}
		$code = (string) get_user_meta( $user_id, self::META_CODE, true );
		if ( preg_match( self::CODE_PATTERN, $code ) ) {
			return $code;
		}

		$chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		for ( $try = 0; $try < 10; $try++ ) {
			$code = 'SW';
			for ( $i = 0; $i < 5; $i++ ) {
				$code .= $chars[ random_int( 0, 35 ) ];
			}
			if ( self::user_by_code( $code ) ) {
				continue;
			}
			// Unique add: of two first calls at once, the one that loses reads the winner's code.
			if ( ! add_user_meta( $user_id, self::META_CODE, $code, true ) ) {
				wp_cache_delete( $user_id, 'user_meta' );
				$code = (string) get_user_meta( $user_id, self::META_CODE, true );
			}
			return $code;
		}
		return '';
	}

	/**
	 * The player a link code belongs to.
	 *
	 * @param string $code Link code.
	 * @return int User ID, or 0.
	 */
	public static function user_by_code( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		if ( ! preg_match( self::CODE_PATTERN, $code ) ) {
			return 0;
		}
		$ids = get_users(
			array(
				'meta_key'    => self::META_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * The player's personal link: the Free page with `?ref=<code>`, or '' when
	 * referral is off.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function link( $user_id ) {
		if ( ! self::enabled() ) {
			return '';
		}
		$code = self::code( $user_id );
		if ( '' === $code ) {
			return '';
		}
		// Without the sharer's language: the friend's browser picks its own.
		$page  = Nera_SAW_Free::page_url();
		$parts = explode( '?', '' !== $page ? $page : home_url( '/free/' ), 2 );
		return add_query_arg( 'ref', $code, $parts[0] );
	}

	/**
	 * A valid `?ref=` on a front-end request: keep its code in a cookie for 30 days.
	 * An unknown code is ignored.
	 *
	 * @return int The cookie's expiry, or 0 when nothing was stored.
	 */
	public static function capture() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public link.
		if ( ! isset( $_GET['ref'] ) || ! self::enabled() ) {
			return 0;
		}
		$code = strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::user_by_code( $code ) ) {
			return 0;
		}
		$expires                 = time() + 30 * DAY_IN_SECONDS;
		$_COOKIE[ self::COOKIE ] = $code;
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$code,
				array(
					'expires'  => $expires,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
			setcookie(
				self::MARKER,
				'1',
				array(
					'expires'  => $expires,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
		}
		return $expires;
	}

	/**
	 * Whether this visitor arrived through a player's link and may be shown the
	 * friend message, which promises a bonus: only when reward() could still pay
	 * it. Referral on and a cookie code that belongs to a player, whatever their
	 * count in the open free competition (at the cap the bonus goes to a later free
	 * competition or waits as pending); a logged-in visitor must be that player's
	 * referred friend, not yet rewarded or pending, with no free grant of their own
	 * on the open free competition.
	 *
	 * @return bool
	 */
	public static function has_valid_ref() {
		if ( ! self::enabled() ) {
			return false;
		}
		$code     = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against the code pattern.
		$referrer = self::user_by_code( $code );
		$free_id  = class_exists( 'Nera_SAW_Free' ) ? (int) Nera_SAW_Free::current() : 0;
		if ( ! $referrer ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return true;
		}
		if ( $user_id === $referrer ) {
			return false;
		}
		if ( (int) get_user_meta( $user_id, self::META_BY, true ) !== $referrer || '' !== (string) get_user_meta( $user_id, self::META_REWARDED, true ) || self::is_pending( $user_id ) ) {
			return false;
		}
		return ! ( $free_id && Nera_SAW_Run_Grants::free_grant( $user_id, $free_id ) );
	}

	/**
	 * Whether this request is the Free page (a routed screen or its page).
	 *
	 * @return bool
	 */
	public static function on_free_page() {
		if ( 'free' === Nera_SAW_Router::current_route() ) {
			return true;
		}
		return is_page() && class_exists( 'Nera_SAW_Standalone_Pages' ) && 'free' === Nera_SAW_Standalone_Pages::route_of( get_queried_object_id() );
	}

	/**
	 * A referral link must always reach capture(), and the message fetch must always get a live
	 * render: neither may be stored by a page cache, whatever the setting, the code or the route.
	 * Runs first, ahead of the redirects on template_redirect.
	 */
	public static function keep_links_out_of_page_cache() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks the keys are present.
		if ( ! isset( $_GET['ref'] ) && ! isset( $_GET['saw_refinv'] ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	/**
	 * The Free page renders the friend message from the visitor's code cookie, which
	 * a host page cache ignores: a response carrying the message must never be stored
	 * (it would be served to everyone), and the script on the cached page fetches it.
	 */
	public static function keep_invite_out_of_page_cache() {
		if ( ! self::on_free_page() || ! self::has_valid_ref() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Vary: Cookie', false );
	}

	/**
	 * A new account from the free sign-up: record who referred it, unless the
	 * referrer is the same person.
	 *
	 * @param int $user_id New user ID.
	 */
	public static function attribute( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! self::enabled() ) {
			return;
		}
		$code     = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against the code pattern.
		$referrer = self::user_by_code( $code );
		if ( ! $referrer ) {
			return;
		}
		$device = self::browser_device();
		if ( self::is_self_referral( $referrer, $user_id, $device ) ) {
			return;
		}
		update_user_meta( $user_id, self::META_BY, $referrer );
		update_user_meta( $user_id, self::META_DEVICE, $device );
	}

	/**
	 * The device id this browser already carries, never a new one: it only exists
	 * once the browser has started a free run.
	 *
	 * @return string
	 */
	private static function browser_device() {
		$id = isset( $_COOKIE[ Nera_SAW_Free_Review::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ Nera_SAW_Free_Review::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by the pattern below.
		return preg_match( '/^[A-Za-z0-9-]{1,64}$/', $id ) ? $id : '';
	}

	/**
	 * The friend is the referrer: same account, same mailbox, or the browser the
	 * referrer played their free runs (or signed up) on. A referrer with no free
	 * grant and no recorded device cannot match the browser rule.
	 *
	 * @param int    $referrer Referrer user ID.
	 * @param int    $friend   New user ID.
	 * @param string $device   This browser's device id ('' if none).
	 * @return bool
	 */
	private static function is_self_referral( $referrer, $friend, $device ) {
		if ( (int) $referrer === (int) $friend ) {
			return true;
		}
		$a = get_userdata( (int) $referrer );
		$b = get_userdata( (int) $friend );
		if ( $a && $b && self::normalise_email( $a->user_email ) === self::normalise_email( $b->user_email ) ) {
			return true;
		}
		if ( '' === $device ) {
			return false;
		}
		if ( (string) get_user_meta( (int) $referrer, self::META_DEVICE, true ) === $device ) {
			return true;
		}
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Nera_SAW_Database::table( 'run_grants' ) . ' WHERE source = %s AND user_id = %d AND device_id = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Nera_SAW_Run_Grants::SOURCE_FREE,
				(int) $referrer,
				$device
			)
		) > 0;
	}

	/**
	 * One mailbox, one form: lowercase, no `+tag`, and for Gmail no dots and one domain.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function normalise_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return $email;
		}
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$plus   = strpos( $local, '+' );
		if ( false !== $plus ) {
			$local = substr( $local, 0, $plus );
		}
		if ( 'gmail.com' === $domain || 'googlemail.com' === $domain ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}
		return $local . '@' . $domain;
	}

	/**
	 * A friend's free run finalized: on their first completed quiz, mint one bonus
	 * ticket for them and one for their referrer. The tickets go into the free
	 * competition the friend just played when it is open, has room for both and the
	 * referrer is under the cap there; otherwise into the next open free competition
	 * that qualifies; with none, the friend is held as pending until one opens. A busy
	 * referrer lock or a mint that produced no ticket also holds the friend as pending,
	 * for the catch-up to pay.
	 *
	 * @param int    $run_id         Run ID.
	 * @param int    $user_id        Friend's user ID.
	 * @param int    $competition_id Free competition ID.
	 * @param string $end_reason     completed|abandoned|expired.
	 */
	public static function reward( $run_id, $user_id, $competition_id, $end_reason ) {
		$friend = (int) $user_id;
		$cid    = (int) $competition_id;
		if ( 'completed' !== (string) $end_reason || ! self::enabled() ) {
			return;
		}
		$referrer = (int) get_user_meta( $friend, self::META_BY, true );
		if ( $referrer < 1 || $referrer === $friend || ! get_userdata( $referrer ) || ! Nera_SAW_Free_Signup::is_verified( $friend ) ) {
			return;
		}
		if ( '' !== (string) get_user_meta( $friend, self::META_REWARDED, true ) || self::is_pending( $friend ) ) {
			return;
		}

		global $wpdb;
		$lock   = 'nera_saw_ref_' . $referrer;
		$locked = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock ) );
		$log    = array(
			'run_id'         => (int) $run_id,
			'competition_id' => $cid,
			'user_id'        => $friend,
			'context'        => array( 'referrer' => $referrer ),
		);
		// Without the lock the cap check and the friends list update are not atomic, so nothing is minted here:
		// the friend is held as pending, and the catch-up takes the lock and checks the cap.
		if ( ! $locked ) {
			wp_cache_delete( $friend, 'user_meta' );
			if ( '' !== (string) get_user_meta( $friend, self::META_REWARDED, true ) || self::is_pending( $friend ) ) {
				return;
			}
			self::hold( $friend, $referrer, $cid, (int) $run_id );
			Nera_SAW_Log::add( 'referral', $log + array( 'level' => 'warning', 'message' => sprintf( 'Referral bonus pending: referrer #%1$d lock busy. Friend #%2$d, competition #%3$d. Minted by the next catch-up.', $referrer, $friend, $cid ) ) );
			return;
		}
		try {
			// Another request may have rewarded or held this friend, or filled the cap, while we waited.
			wp_cache_delete( $friend, 'user_meta' );
			wp_cache_delete( $referrer, 'user_meta' );
			if ( '' !== (string) get_user_meta( $friend, self::META_REWARDED, true ) || self::is_pending( $friend ) ) {
				return;
			}
			$target = self::reserve_target( $referrer, array_merge( array( $cid ), self::next_draws() ) );
			if ( ! $target ) {
				self::hold( $friend, $referrer, $cid, (int) $run_id );
				Nera_SAW_Log::add( 'referral', $log + array( 'message' => sprintf( 'Referral bonus pending: free competition #%1$d is closed, full or at the referrer\'s cap, and no other open free competition can take it. Friend #%2$d, referrer #%3$d. Minted when the next free competition opens.', $cid, $friend, $referrer ) ) );
				return;
			}
			if ( $target !== $cid ) {
				Nera_SAW_Log::add( 'referral', array( 'competition_id' => $target ) + $log + array( 'message' => sprintf( 'Referral bonus moved from free competition #%1$d to #%2$d: #%1$d is closed, full or at the referrer\'s cap. Friend #%3$d, referrer #%4$d.', $cid, $target, $friend, $referrer ) ) );
			}
			if ( self::pay( $friend, $referrer, $target, $cid, (int) $run_id ) < 1 ) {
				self::hold( $friend, $referrer, $cid, (int) $run_id );
				Nera_SAW_Log::add( 'referral', array( 'competition_id' => $target ) + $log + array( 'level' => 'warning', 'message' => sprintf( 'Referral bonus pending: no ticket could be minted in free competition #%1$d. Friend #%2$d, referrer #%3$d. Retried by the next catch-up.', $target, $friend, $referrer ) ) );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
	}

	/**
	 * The first of the competitions that can take a referral bonus for this referrer
	 * (open, under the cap, room for two tickets), with the two tickets reserved from
	 * its pool. Call under the referrer's lock.
	 *
	 * @param int   $referrer   Referrer's user ID.
	 * @param int[] $candidates Competition IDs, in order of preference.
	 * @return int Competition ID, or 0 when none can.
	 */
	private static function reserve_target( $referrer, array $candidates ) {
		$cap = self::cap();
		foreach ( array_unique( array_map( 'intval', $candidates ) ) as $cid ) {
			if ( $cid > 0 && self::draw_open( $cid ) && self::rewarded_count( $referrer, $cid ) < $cap && Nera_SAW_Spin_Pool::try_reserve( $cid, 2 ) ) {
				return $cid;
			}
		}
		return 0;
	}

	/**
	 * Mint the friend's and the referrer's bonus tickets into a competition whose pool
	 * already holds two for them, mark the friend rewarded and count them for the
	 * referrer there. Call under the referrer's lock.
	 *
	 * @param int $friend   Friend's user ID.
	 * @param int $referrer Referrer's user ID.
	 * @param int $cid      Competition the tickets go into.
	 * @param int $origin   Free competition the friend played.
	 * @param int $run_id   Friend's run ID.
	 * @return int Tickets that exist after the mint (0-2).
	 */
	private static function pay( $friend, $referrer, $cid, $origin, $run_id ) {
		$log    = array(
			'run_id'         => (int) $run_id,
			'competition_id' => (int) $cid,
			'user_id'        => (int) $friend,
			'context'        => array(
				'referrer' => (int) $referrer,
				'origin'   => (int) $origin,
			),
		);
		$minted = 0;
		$got    = array();
		foreach ( array( $friend, $referrer ) as $owner ) {
			$result = Nera_SAW_Ticket_Award::award_bonus( $cid, $owner, $friend );
			// The friend's next free run is always another competition, so a missing ticket can only be finished here.
			if ( '' === $result ) {
				$result = Nera_SAW_Ticket_Award::award_bonus( $cid, $owner, $friend );
			}
			if ( 'minted' === $result ) {
				++$minted;
			}
			$got[ $owner ] = '' !== $result;
		}
		Nera_SAW_Spin_Pool::confirm( $cid, 2, $minted );

		$held = count( array_filter( $got ) );
		if ( 0 === $held ) {
			Nera_SAW_Log::error( 'referral', sprintf( 'Referral bonus incomplete on competition #%1$d: 0 of 2 tickets exist. Friend #%2$d, referrer #%3$d.', $cid, $friend, $referrer ), $log );
			return 0;
		}
		if ( 2 !== $held ) {
			Nera_SAW_Log::error( 'referral', sprintf( 'Referral bonus incomplete on competition #%1$d: 1 of 2 tickets exist, the %2$s ticket is missing. Friend #%3$d, referrer #%4$d. Friend marked rewarded; add the missing ticket by hand.', $cid, $got[ $friend ] ? 'referrer\'s' : 'friend\'s', $friend, $referrer ), $log );
		}
		update_user_meta( $friend, self::META_REWARDED, $cid );
		// The friends list counts the referrer's bonus tickets in this competition, so it only grows when the referrer's ticket exists.
		if ( $got[ $referrer ] ) {
			$map         = self::friends_map( $referrer );
			$friends     = self::friends( $referrer, $cid );
			$friends[]   = $friend;
			$map[ $cid ] = array_values( array_unique( $friends ) );
			update_user_meta( $referrer, self::META_FRIENDS, $map );
		}
		self::flag_same_ip( $friend, $referrer, $origin );
		return $held;
	}

	/**
	 * Hold a qualified friend as pending: meta on the friend, and their ID in the list
	 * the catch-up reads.
	 *
	 * @param int $friend   Friend's user ID.
	 * @param int $referrer Referrer's user ID.
	 * @param int $origin   Free competition the friend played.
	 * @param int $run_id   Friend's run ID.
	 */
	private static function hold( $friend, $referrer, $origin, $run_id ) {
		// Unique add: a friend already held keeps their first qualification time.
		add_user_meta(
			(int) $friend,
			self::META_PENDING,
			array(
				'referrer'     => (int) $referrer,
				'origin'       => (int) $origin,
				'run_id'       => (int) $run_id,
				'qualified_at' => time(),
			),
			true
		);
		self::index_pending( $friend, true );
	}

	/**
	 * Whether a friend qualified but is still waiting for a free competition to take their bonus.
	 *
	 * @param int $user_id Friend's user ID.
	 * @return bool
	 */
	public static function is_pending( $user_id ) {
		$p = get_user_meta( (int) $user_id, self::META_PENDING, true );
		return is_array( $p ) && ! empty( $p['referrer'] );
	}

	/**
	 * IDs of the friends held as pending. Kept in one autoloaded option so the check on
	 * every request costs nothing when it is empty.
	 *
	 * @return int[]
	 */
	public static function pending_friends() {
		$ids = get_option( self::OPTION_PENDING, array() );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
	}

	/**
	 * The pending list straight from the table: the request's option cache may predate
	 * another request's change.
	 *
	 * @return int[]
	 */
	private static function stored_pending() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_PENDING ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = null === $raw ? array() : maybe_unserialize( $raw );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Add a friend to the pending list, or remove them.
	 *
	 * @param int  $friend Friend's user ID.
	 * @param bool $add    Add (true) or remove (false).
	 * @return bool False when the list's lock was busy and nothing was written.
	 */
	private static function index_pending( $friend, $add ) {
		global $wpdb;
		$lock = 'nera_saw_ref_pending';
		// A read-modify-write without the lock can overwrite another request's change and lose a friend.
		// The friend's pending meta stays, so a missed add is marked for the catch-up to rebuild the list;
		// a missed remove leaves an ID the catch-up drops once it finds no pending meta.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock ) ) ) {
			if ( $add ) {
				update_option( self::OPTION_REINDEX, 1, true );
			}
			return false;
		}
		try {
			$ids = self::stored_pending();
			$ids = $add ? array_merge( $ids, array( (int) $friend ) ) : array_diff( $ids, array( (int) $friend ) );
			$ids = array_values( array_unique( array_filter( $ids ) ) );
			wp_cache_delete( self::OPTION_PENDING, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( $ids ) {
				update_option( self::OPTION_PENDING, $ids, true );
			} else {
				delete_option( self::OPTION_PENDING );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
		return true;
	}

	/**
	 * Put back into the pending list every friend who holds the pending meta but is
	 * missing from it.
	 */
	private static function reindex() {
		global $wpdb;
		// Cleared first: a failed add after the query below sets it again for the next catch-up. Kept as an
		// autoloaded 0 rather than deleted, so the check on every request never queries for a missing option.
		if ( get_option( self::OPTION_REINDEX ) ) {
			update_option( self::OPTION_REINDEX, 0, true );
		}
		$held = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::META_PENDING ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( array_diff( $held, self::stored_pending() ) as $friend ) {
			self::index_pending( (int) $friend, true );
		}
	}

	/**
	 * Whether the catch-up has anything to do: friends in the pending list, or a list
	 * marked for a rebuild.
	 *
	 * @return bool
	 */
	private static function has_pending_work() {
		return self::pending_friends() || get_option( self::OPTION_REINDEX );
	}

	/**
	 * The open free competitions a referral bonus can move to, closing soonest first.
	 * Read fresh on every call: Nera_SAW_Free::current() keeps one answer per request
	 * and does not check the draw or the pool.
	 *
	 * @return int[]
	 */
	public static function next_draws() {
		$query = new WP_Query(
			array(
				'post_type'                           => 'product',
				'post_status'                         => 'publish',
				'posts_per_page'                      => 10,
				'no_found_rows'                       => true,
				'fields'                              => 'ids',
				'meta_key'                            => '_lty_end_date_gmt', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'                             => 'meta_value',
				'order'                               => 'ASC',
				Nera_SAW_Catalogue_Isolation::OPT_OUT => true,
				'meta_query'                          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => Nera_SAW_Competition_Config::META_IS_COMP,
						'value' => '1',
					),
					array(
						'key'   => Nera_SAW_Competition_Config::META_IS_FREE,
						'value' => '1',
					),
					array(
						'key'     => '_lty_end_date_gmt',
						'value'   => gmdate( 'Y-m-d H:i:s' ),
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
				),
			)
		);
		$ids = array_values( array_filter( array_map( 'intval', $query->posts ), array( __CLASS__, 'draw_open' ) ) );

		/**
		 * Filter the free competitions a referral bonus can move to, in order of preference.
		 *
		 * @param int[] $ids Open free competitions, closing soonest first.
		 */
		return array_values( array_map( 'intval', (array) apply_filters( 'nera_saw_referral_next_draws', $ids ) ) );
	}

	/**
	 * A free competition was published (by hand or on its schedule): pay the pending
	 * friends into it. Runs after the post's meta is saved; a pool row created later
	 * is picked up by the catch-up.
	 *
	 * @param int          $post_id     Post ID.
	 * @param WP_Post      $post        Post.
	 * @param bool         $update      Whether an existing post was updated.
	 * @param WP_Post|null $post_before The post before the update.
	 */
	public static function on_publish( $post_id, $post, $update, $post_before ) {
		if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		if ( $post_before && 'publish' === $post_before->post_status ) {
			return;
		}
		if ( ! self::has_pending_work() || ! Nera_SAW_Competition_Config::is_free( (int) $post_id ) ) {
			return;
		}
		self::catch_up( array( (int) $post_id ) );
	}

	/**
	 * On front-end requests, at most every few minutes and only while friends are
	 * pending: pay them into an open free competition. Covers a publish whose hook
	 * did not pay (pool row saved later, missed hook); wp-cron is not relied on.
	 */
	public static function maybe_catch_up() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! self::has_pending_work() || ! self::enabled() || get_transient( self::CATCH_UP ) ) {
			return;
		}
		set_transient( self::CATCH_UP, 1, 5 * MINUTE_IN_SECONDS );
		self::catch_up();
	}

	/**
	 * Pay pending friends, oldest qualification first so each referrer's cap fills
	 * in order. A friend whose referrer's lock is busy, or for whom no competition
	 * can take the bonus yet, stays pending. First puts back into the list any
	 * pending friend missing from it.
	 *
	 * @param int[]|null $only Competitions to pay into; null for the open free competitions.
	 */
	public static function catch_up( $only = null ) {
		if ( ! self::enabled() ) {
			return;
		}
		self::reindex();
		$ids = self::pending_friends();
		if ( ! $ids ) {
			return;
		}
		$candidates = null === $only ? self::next_draws() : array_map( 'intval', (array) $only );
		if ( ! $candidates ) {
			return;
		}
		$queue = array();
		foreach ( $ids as $friend ) {
			wp_cache_delete( $friend, 'user_meta' );
			if ( ! self::is_pending( $friend ) ) {
				self::index_pending( $friend, false );
				continue;
			}
			$queue[ $friend ] = (int) get_user_meta( $friend, self::META_PENDING, true )['qualified_at'];
		}
		asort( $queue );
		foreach ( array_keys( $queue ) as $friend ) {
			self::pay_pending( (int) $friend, $candidates );
		}
	}

	/**
	 * Pay one pending friend into the first candidate that can take the bonus.
	 *
	 * @param int   $friend     Friend's user ID.
	 * @param int[] $candidates Competition IDs, in order of preference.
	 */
	private static function pay_pending( $friend, array $candidates ) {
		global $wpdb;
		$p        = get_user_meta( $friend, self::META_PENDING, true );
		$referrer = (int) $p['referrer'];
		$origin   = (int) ( $p['origin'] ?? 0 );
		$log      = array(
			'run_id'         => (int) ( $p['run_id'] ?? 0 ),
			'competition_id' => $origin,
			'user_id'        => $friend,
			'context'        => array( 'referrer' => $referrer ),
		);
		if ( ! get_userdata( $friend ) || ! get_userdata( $referrer ) ) {
			delete_user_meta( $friend, self::META_PENDING );
			self::index_pending( $friend, false );
			Nera_SAW_Log::add( 'referral', $log + array( 'level' => 'warning', 'message' => sprintf( 'Referral bonus pending dropped: friend #%1$d or referrer #%2$d no longer exists.', $friend, $referrer ) ) );
			return;
		}
		$lock = 'nera_saw_ref_' . $referrer;
		// No wait on a page request: a busy lock means the referrer's reward is being decided elsewhere; try next time.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ) ) {
			return;
		}
		try {
			wp_cache_delete( $friend, 'user_meta' );
			wp_cache_delete( $referrer, 'user_meta' );
			if ( ! self::is_pending( $friend ) || '' !== (string) get_user_meta( $friend, self::META_REWARDED, true ) ) {
				delete_user_meta( $friend, self::META_PENDING );
				self::index_pending( $friend, false );
				return;
			}
			$target = self::reserve_target( $referrer, $candidates );
			if ( ! $target ) {
				return;
			}
			$held = self::pay( $friend, $referrer, $target, $origin, $log['run_id'] );
			if ( $held < 1 ) {
				return;
			}
			delete_user_meta( $friend, self::META_PENDING );
			self::index_pending( $friend, false );
			Nera_SAW_Log::add( 'referral', array( 'competition_id' => $target ) + $log + array( 'message' => sprintf( 'Referral bonus pending since %1$s minted in free competition #%2$d (friend played #%3$d). Friend #%4$d, referrer #%5$d.', gmdate( 'Y-m-d H:i', (int) ( $p['qualified_at'] ?? 0 ) ), $target, $origin, $friend, $referrer ) ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
	}

	/**
	 * Whether a free competition still takes tickets: published, not drawn, not past its end.
	 *
	 * @param int $competition_id Free competition ID.
	 * @return bool
	 */
	public static function draw_open( $competition_id ) {
		$cid  = (int) $competition_id;
		$open = Nera_SAW_Competition_Config::is_competition( $cid )
			&& Nera_SAW_Competition_Config::is_free( $cid )
			&& 'publish' === get_post_status( $cid )
			&& 'lty_lottery_finished' !== get_post_meta( $cid, 'lty_lottery_status', true );
		$end  = (string) get_post_meta( $cid, '_lty_end_date_gmt', true );
		if ( $open && '' !== $end ) {
			$open = strtotime( $end . ' UTC' ) > time();
		}

		/**
		 * Filter whether a free competition can take referral bonus tickets.
		 *
		 * @param bool $open           Computed value.
		 * @param int  $competition_id Free competition ID.
		 */
		return (bool) apply_filters( 'nera_saw_referral_draw_open', $open, $cid );
	}

	/**
	 * The friend's free entry came from an IP the referrer's free entries used:
	 * flag it for review. Never blocks.
	 *
	 * @param int $friend   Friend's user ID.
	 * @param int $referrer Referrer's user ID.
	 * @param int $cid      Free competition ID.
	 */
	private static function flag_same_ip( $friend, $referrer, $cid ) {
		global $wpdb;
		$grant = Nera_SAW_Run_Grants::free_grant( $friend, $cid );
		if ( ! $grant || '' === (string) $grant->ip_hash ) {
			return;
		}
		$table  = Nera_SAW_Database::table( 'run_grants' );
		$shared = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE source = %s AND user_id = %d AND ip_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Nera_SAW_Run_Grants::SOURCE_FREE,
				(int) $referrer,
				(string) $grant->ip_hash
			)
		);
		if ( $shared < 1 ) {
			return;
		}
		$merged = Nera_SAW_Free_Review::merge( (string) $grant->review, array( self::REVIEW ) );
		if ( $merged !== (string) $grant->review ) {
			$wpdb->update( $table, array( 'review' => $merged ), array( 'id' => (int) $grant->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * The friends a referrer has been rewarded for, per free competition.
	 *
	 * @param int $user_id Referrer's user ID.
	 * @return array<int, int[]> Competition ID => friend IDs.
	 */
	private static function friends_map( $user_id ) {
		$map = get_user_meta( (int) $user_id, self::META_FRIENDS, true );
		$out = array();
		foreach ( is_array( $map ) ? $map : array() as $cid => $ids ) {
			if ( (int) $cid > 0 && is_array( $ids ) ) {
				$out[ (int) $cid ] = array_values( array_filter( array_map( 'intval', $ids ) ) );
			}
		}
		return $out;
	}

	/**
	 * IDs of the friends a referrer has been rewarded for in one free competition.
	 *
	 * @param int $user_id        Referrer's user ID.
	 * @param int $competition_id Free competition ID.
	 * @return int[]
	 */
	public static function friends( $user_id, $competition_id ) {
		$map = self::friends_map( $user_id );
		return isset( $map[ (int) $competition_id ] ) ? $map[ (int) $competition_id ] : array();
	}

	/**
	 * How many friends a referrer has been rewarded for in one free competition.
	 *
	 * @param int $user_id        Referrer's user ID.
	 * @param int $competition_id Free competition ID.
	 * @return int
	 */
	public static function rewarded_count( $user_id, $competition_id ) {
		return count( self::friends( $user_id, $competition_id ) );
	}

	/**
	 * A player's referral bonus tickets, per competition.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, int> Competition ID => tickets.
	 */
	public static function bonus_by_competition( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT c.meta_value AS cid, COUNT(*) AS n FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s
				 JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'lty_user_id' AND u.meta_value = %s
				 JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = 'lty_product_id'
				 WHERE p.post_type = 'lty_lottery_ticket' AND p.post_status IN ( 'lty_ticket_buyer', 'lty_ticket_winner' )
				 GROUP BY c.meta_value",
				Nera_SAW_Ticket_Award::BONUS_META,
				(string) (int) $user_id
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->cid ] = (int) $row->n;
		}
		return $out;
	}
}
