<?php
/**
 * Referral: a player's personal link to the free competition, and one bonus
 * ticket each for the player and the friend once the friend qualifies.
 *
 * A friend qualifies when their account was created by the free sign-up while
 * this browser held a valid link code, their email is verified and they complete
 * a free quiz. Both bonus tickets go into the free competition the friend just
 * played, once per friend ever, up to the cap of rewarded friends per player.
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
	 * User meta on a referrer: IDs of the friends already rewarded.
	 */
	const META_FRIENDS = '_nera_saw_ref_friends';

	/**
	 * Review reason on a friend's free grant: same IP as the referrer.
	 */
	const REVIEW = 'ref';

	/**
	 * `SW` and five uppercase letters or digits.
	 */
	const CODE_PATTERN = '/^SW[0-9A-Z]{5}$/';

	/**
	 * Rewarded friends per referrer when the setting is blank.
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
	 * Most friends a player can be rewarded for, over their lifetime.
	 *
	 * @return int At least 1.
	 */
	public static function cap() {
		$s   = Nera_SAW_Constants::settings();
		$cap = isset( $s['referral_cap'] ) && '' !== $s['referral_cap'] ? (int) $s['referral_cap'] : self::CAP_DEFAULT;

		/**
		 * Filter the number of rewarded friends per referrer.
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
	 * it. Referral on, a cookie code that belongs to a player who is under the
	 * cap; a logged-in visitor must be that player's referred friend, not yet
	 * rewarded, with no free grant of their own on the open free competition.
	 *
	 * @return bool
	 */
	public static function has_valid_ref() {
		if ( ! self::enabled() ) {
			return false;
		}
		$code     = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against the code pattern.
		$referrer = self::user_by_code( $code );
		if ( ! $referrer || count( self::friends( $referrer ) ) >= self::cap() ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return true;
		}
		if ( $user_id === $referrer ) {
			return false;
		}
		if ( (int) get_user_meta( $user_id, self::META_BY, true ) !== $referrer || '' !== (string) get_user_meta( $user_id, self::META_REWARDED, true ) ) {
			return false;
		}
		$free_id = class_exists( 'Nera_SAW_Free' ) ? (int) Nera_SAW_Free::current() : 0;
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
	 * ticket for them and one for their referrer in that free competition.
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
		if ( '' !== (string) get_user_meta( $friend, self::META_REWARDED, true ) ) {
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
		// Without the lock the cap check and the friends list update are not atomic: skip rather than risk passing the cap.
		if ( ! $locked ) {
			Nera_SAW_Log::add( 'referral', $log + array( 'level' => 'warning', 'message' => sprintf( 'Referral bonus skipped: referrer #%1$d lock busy. Friend #%2$d, competition #%3$d.', $referrer, $friend, $cid ) ) );
			return;
		}
		try {
			// Another request may have rewarded this friend, or filled the cap, while we waited.
			wp_cache_delete( $friend, 'user_meta' );
			wp_cache_delete( $referrer, 'user_meta' );
			if ( '' !== (string) get_user_meta( $friend, self::META_REWARDED, true ) ) {
				return;
			}
			$friends = self::friends( $referrer );
			if ( count( $friends ) >= self::cap() ) {
				Nera_SAW_Log::add( 'referral', $log + array( 'message' => sprintf( 'Referral bonus not minted: referrer #%1$d already has %2$d rewarded friends. Friend #%3$d.', $referrer, count( $friends ), $friend ) ) );
				return;
			}
			if ( ! self::draw_open( $cid ) || ! Nera_SAW_Spin_Pool::try_reserve( $cid, 2 ) ) {
				Nera_SAW_Log::add( 'referral', $log + array( 'level' => 'warning', 'message' => sprintf( 'Referral bonus not minted: free competition #%1$d is closed or has no tickets left. Friend #%2$d, referrer #%3$d.', $cid, $friend, $referrer ) ) );
				return;
			}

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
				return;
			}
			if ( 2 !== $held ) {
				Nera_SAW_Log::error( 'referral', sprintf( 'Referral bonus incomplete on competition #%1$d: 1 of 2 tickets exist, the %2$s ticket is missing. Friend #%3$d, referrer #%4$d. Friend marked rewarded; add the missing ticket by hand.', $cid, $got[ $friend ] ? 'referrer\'s' : 'friend\'s', $friend, $referrer ), $log );
			}
			update_user_meta( $friend, self::META_REWARDED, $cid );
			// The friends list counts the referrer's bonus tickets, so it only grows when the referrer's ticket exists.
			if ( $got[ $referrer ] ) {
				$friends[] = $friend;
				update_user_meta( $referrer, self::META_FRIENDS, array_values( array_unique( $friends ) ) );
			}
			self::flag_same_ip( $friend, $referrer, $cid );
		} finally {
			if ( $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
			}
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
	 * IDs of the friends a referrer has been rewarded for.
	 *
	 * @param int $user_id Referrer's user ID.
	 * @return int[]
	 */
	public static function friends( $user_id ) {
		$list = get_user_meta( (int) $user_id, self::META_FRIENDS, true );
		return is_array( $list ) ? array_values( array_filter( array_map( 'intval', $list ) ) ) : array();
	}

	/**
	 * How many friends a referrer has been rewarded for.
	 *
	 * @param int $user_id Referrer's user ID.
	 * @return int
	 */
	public static function rewarded_count( $user_id ) {
		return count( self::friends( $user_id ) );
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
