<?php
/**
 * Shareable result links: `/r/<token>/`.
 *
 * A finished run's owner asks for a link (REST, POST run/<id>/share). The link
 * points at a frozen snapshot of the result -- score, tickets earned, the
 * competition -- stored under a random 128-bit token. Nothing in the snapshot or
 * on the public page identifies the player: no name, no email, no ticket numbers.
 *
 * A link is live only while it has not passed its expiry (share_link_days after
 * it was made; global setting with a per-competition override) and the
 * competition has not been drawn. One predicate, is_live(), decides that for
 * the REST call, the page and its meta tags, so the three can never disagree.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Share
 */
class Nera_SAW_Share {

	/**
	 * Route name served by Nera_SAW_Router.
	 */
	const ROUTE = 'share';

	/**
	 * URL segment before the token.
	 */
	const SEGMENT = 'r';

	/**
	 * Days a link lives when nothing else is set.
	 */
	const DEFAULT_DAYS = 30;

	/**
	 * A token: 16 random bytes, base64url, no padding.
	 */
	const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{22}$/';

	/**
	 * Russian for the strings this screen adds. Strings not listed fall back to
	 * Nera_SAW_I18n, which already carries the shared ones (Score, Tickets earned).
	 *
	 * @var array<string,string>
	 */
	private static $ru = array(
		'Shared result'  => 'Результат квиза',
		'Try it'         => 'Попробовать',
		'ticket|tickets' => 'билет|билета|билетов',
		'Answer timed questions and earn tickets for the draw. Think you can beat it?' => 'Отвечайте на вопросы на время и получайте билеты для розыгрыша. Сможете лучше?',
		'This link has expired' => 'Срок действия ссылки истёк',
		'Shared results are shown for a limited time and not after the draw. You can still try the quiz yourself.' => 'Результаты показываются ограниченное время и не после розыгрыша. Но вы можете попробовать квиз сами.',
		'I scored %1$d/%2$d and earned %3$d %4$s for %5$s' => 'Мой результат %1$d/%2$d: %3$d %4$s в розыгрыше «%5$s»',
		'Try the quiz for %s' => 'Попробуйте квиз: %s',
		'A timed quiz where correct answers earn tickets for the draw.' => 'Квиз на время: правильные ответы приносят билеты для розыгрыша.',
		'That link does not exist.' => 'Такой ссылки нет.',
	);

	/**
	 * The resolved view for this request.
	 *
	 * @var array|null
	 */
	private static $view = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'meta' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ) );
	}

	/* ---------------------------------------------------------------------
	 * Rules
	 * ------------------------------------------------------------------ */

	/**
	 * Days a new link lives for a competition: the global setting, then the
	 * competition's own value when it has one.
	 *
	 * @param int $competition_id Competition product ID, or 0 for the global value.
	 * @return int
	 */
	public static function days( $competition_id = 0 ) {
		$settings = Nera_SAW_Constants::settings();
		$global   = isset( $settings['share_link_days'] ) ? (int) $settings['share_link_days'] : self::DEFAULT_DAYS;
		$global   = $global >= 1 ? $global : self::DEFAULT_DAYS;
		$value    = $global;

		if ( (int) $competition_id > 0 ) {
			$config = Nera_SAW_Competition_Config::get( (int) $competition_id );
			$own    = isset( $config['share_link_days'] ) ? trim( (string) $config['share_link_days'] ) : '';
			if ( '' !== $own && (int) $own >= 1 ) {
				$value = (int) $own;
			}
		}

		/**
		 * Filter how many days a new share link lives.
		 *
		 * @param int $value          Resolved days.
		 * @param int $competition_id Competition product ID.
		 * @param int $global         The global setting.
		 */
		return max( 1, (int) apply_filters( 'nera_saw_share_link_days', $value, (int) $competition_id, $global ) );
	}

	/**
	 * Has the competition been drawn? Either the plugin's own offline draw
	 * (draw_closed / unprefixed lty_lottery_status) or Lottery for WooCommerce's
	 * automatic one (prefixed _lty_lottery_status). An end date passing is not a
	 * draw: the link stays live until the draw actually happens.
	 *
	 * @param int $competition_id Competition product ID.
	 * @return bool
	 */
	public static function is_drawn( $competition_id ) {
		$competition_id = (int) $competition_id;
		if ( Nera_SAW_Draw_Prizes::is_closed( $competition_id, Nera_SAW_Competition_Config::get( $competition_id ) ) ) {
			return true;
		}
		$done = array( 'lty_lottery_finished', 'lty_lottery_failed' );
		return in_array( get_post_meta( $competition_id, '_lty_lottery_status', true ), $done, true )
			|| in_array( get_post_meta( $competition_id, 'lty_lottery_status', true ), $done, true );
	}

	/**
	 * The one validity rule: before expiry (UTC) and not drawn.
	 *
	 * @param object $share Row from the shares table.
	 * @return bool
	 */
	public static function is_live( $share ) {
		if ( ! $share || empty( $share->expires_at ) ) {
			return false;
		}
		$expires = strtotime( $share->expires_at . ' UTC' );
		return $expires && time() < $expires && ! self::is_drawn( (int) $share->competition_id );
	}

	/**
	 * Public address of a link.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function url( $token ) {
		return Nera_SAW_Router::url( self::SEGMENT, $token );
	}

	/**
	 * A share by token.
	 *
	 * @param string $token Token from the URL.
	 * @return object|null
	 */
	public static function find( $token ) {
		$token = (string) $token;
		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}
		global $wpdb;
		$table = Nera_SAW_Database::table( 'shares' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from table().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token ) );
		return $row ? $row : null;
	}

	/**
	 * Get or make the link for a finished run.
	 *
	 * @param int $run_id  Run ID.
	 * @param int $user_id Caller.
	 * @return array|WP_Error
	 */
	public static function create_for_run( $run_id, $user_id ) {
		global $wpdb;
		$run_id = (int) $run_id;
		$runs   = Nera_SAW_Database::table( 'runs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$run = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, competition_id, status, correct_count, spins_confirmed FROM {$runs} WHERE id = %d", $run_id ) );

		if ( ! $run ) {
			return new WP_Error( 'saw_share_no_run', __( 'Run not found.', 'nera-strikeawin' ), array( 'status' => 404 ) );
		}
		// Ownership before anything else, so another user learns nothing about the run.
		if ( (int) $run->user_id !== (int) $user_id ) {
			return new WP_Error( 'saw_share_forbidden', __( 'This is not your run.', 'nera-strikeawin' ), array( 'status' => 403 ) );
		}
		// Finalized covers runs that ended early (left or timed out): their result is fixed too.
		if ( 'finalized' !== $run->status ) {
			return new WP_Error( 'saw_share_unfinished', __( 'Only a finished run can be shared.', 'nera-strikeawin' ), array( 'status' => 409 ) );
		}
		$competition_id = (int) $run->competition_id;
		if ( self::is_drawn( $competition_id ) ) {
			return new WP_Error( 'saw_share_drawn', __( 'This competition has been drawn, so its results can no longer be shared.', 'nera-strikeawin' ), array( 'status' => 409 ) );
		}

		$shares = Nera_SAW_Database::table( 'shares' );
		$now    = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$shares} WHERE run_id = %d AND expires_at > %s ORDER BY id DESC LIMIT 1", $run_id, $now ) );
		if ( $existing && self::is_live( $existing ) ) {
			return self::payload( $existing );
		}

		$slots = Nera_SAW_Database::table( 'run_slots' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$questions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$slots} WHERE run_id = %d", $run_id ) );
		$created   = time();
		$row       = array(
			'run_id'         => $run_id,
			'user_id'        => (int) $run->user_id,
			'competition_id' => $competition_id,
			'score'          => (int) $run->correct_count,
			'questions'      => $questions,
			'tickets'        => (int) $run->spins_confirmed,
			'created_at'     => gmdate( 'Y-m-d H:i:s', $created ),
			'expires_at'     => gmdate( 'Y-m-d H:i:s', $created + self::days( $competition_id ) * DAY_IN_SECONDS ),
		);

		// A clash on 128 random bits will not happen; the unique key makes sure of it anyway.
		for ( $i = 0; $i < 3; $i++ ) {
			$row['token'] = rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
			if ( $wpdb->insert( $shares, $row ) ) {
				return self::payload( (object) $row );
			}
		}

		return new WP_Error( 'saw_share_store', __( 'The link could not be made. Please try again.', 'nera-strikeawin' ), array( 'status' => 500 ) );
	}

	/**
	 * What the REST call returns.
	 *
	 * @param object $share Row.
	 * @return array
	 */
	private static function payload( $share ) {
		return array(
			'token'      => (string) $share->token,
			'url'        => self::url( $share->token ),
			'created_at' => gmdate( 'c', strtotime( $share->created_at . ' UTC' ) ),
			'expires_at' => gmdate( 'c', strtotime( $share->expires_at . ' UTC' ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * The page
	 * ------------------------------------------------------------------ */

	/**
	 * Is this request the share route?
	 *
	 * @return bool
	 */
	public static function is_route() {
		return self::ROUTE === Nera_SAW_Router::current_route();
	}

	/**
	 * Everything the page and its meta need, resolved once per request.
	 *
	 * state: 'valid' (show the result), 'expired' (past its days or drawn: no
	 * result data) or 'missing' (no such token: a 404).
	 *
	 * @return array
	 */
	public static function view() {
		if ( null !== self::$view ) {
			return self::$view;
		}

		$share = self::find( (string) get_query_var( Nera_SAW_Router::QV_ARG ) );
		if ( ! $share ) {
			self::$view = array(
				'state'   => 'missing',
				'try_url' => Nera_SAW_Router::url(),
			);
			return self::$view;
		}

		$competition_id = (int) $share->competition_id;
		$spec           = Nera_SAW_Competition_Spec::get( $competition_id );
		$name           = $spec ? (string) $spec['name'] : (string) get_the_title( $competition_id );
		$thumb          = get_post_thumbnail_id( $competition_id );
		$image          = $thumb ? (string) wp_get_attachment_image_url( $thumb, 'full' ) : '';
		$try_url        = $spec && ! empty( $spec['open'] )
			? (string) apply_filters( 'nera_saw_standalone_competition_url', '', $competition_id )
			: '';

		$view = array(
			'state'     => self::is_live( $share ) ? 'valid' : 'expired',
			'name'      => $name,
			'image'     => $image,
			'image_alt' => $name,
			'try_url'   => $try_url ? $try_url : Nera_SAW_Router::url(),
			'url'       => self::url( $share->token ),
		);

		if ( 'valid' === $view['state'] ) {
			$view['score']     = (int) $share->score;
			$view['questions'] = (int) $share->questions;
			$view['tickets']   = (int) $share->tickets;
			$view['tickets_text'] = sprintf( '%d %s', $view['tickets'], self::ticket_word( $view['tickets'] ) );
			$view['title']     = sprintf( self::t( 'I scored %1$d/%2$d and earned %3$d %4$s for %5$s' ), $view['score'], $view['questions'], $view['tickets'], self::ticket_word( $view['tickets'] ), $name );
			$view['description'] = self::t( 'Answer timed questions and earn tickets for the draw. Think you can beat it?' );
		} else {
			$view['title']       = sprintf( self::t( 'Try the quiz for %s' ), $name );
			$view['description'] = self::t( 'A timed quiz where correct answers earn tickets for the draw.' );
		}

		self::$view = $view;
		return self::$view;
	}

	/**
	 * A string in the visitor's language.
	 *
	 * @param string $english English source.
	 * @return string
	 */
	public static function t( $english ) {
		if ( class_exists( 'Nera_SAW_Language' ) && 'ru' === Nera_SAW_Language::current() && isset( self::$ru[ $english ] ) ) {
			return self::$ru[ $english ];
		}
		return Nera_SAW_I18n::t( $english );
	}

	/**
	 * "ticket" / "tickets" (Russian: one, few, many) for a count.
	 *
	 * @param int $n Count.
	 * @return string
	 */
	public static function ticket_word( $n ) {
		$n     = (int) $n;
		$forms = explode( '|', self::t( 'ticket|tickets' ) );
		if ( 3 === count( $forms ) ) {
			$m10  = $n % 10;
			$m100 = $n % 100;
			if ( 1 === $m10 && 11 !== $m100 ) {
				return $forms[0];
			}
			if ( $m10 >= 2 && $m10 <= 4 && ( $m100 < 12 || $m100 > 14 ) ) {
				return $forms[1];
			}
			return $forms[2];
		}
		return 1 === $n ? $forms[0] : $forms[ count( $forms ) - 1 ];
	}

	/**
	 * Validity changes with time and with the draw, so the page must never come
	 * out of a page cache, in any state.
	 */
	public static function no_cache() {
		if ( self::is_route() ) {
			nocache_headers();
		}
	}

	/**
	 * Keep share pages out of search results.
	 *
	 * @param array $robots Directives core prints.
	 * @return array
	 */
	public static function robots( $robots ) {
		if ( self::is_route() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	/**
	 * Open Graph and Twitter tags, on this route only. Printed by the plugin so
	 * they work on any theme that calls wp_head(); a social bot reads them
	 * without cookies or scripts.
	 */
	public static function meta() {
		if ( ! self::is_route() ) {
			return;
		}
		$view = self::view();
		if ( 'missing' === $view['state'] ) {
			return;
		}

		$tags = array(
			'og:type'             => 'website',
			'og:site_name'        => Nera_SAW_Brand::name(),
			'og:title'            => $view['title'],
			'og:description'      => $view['description'],
			'og:url'              => $view['url'],
			'og:image'            => $view['image'],
			'twitter:card'        => 'summary_large_image',
			'twitter:title'       => $view['title'],
			'twitter:description' => $view['description'],
			'twitter:image'       => $view['image'],
		);

		foreach ( $tags as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$attr = 0 === strpos( $key, 'og:' ) ? 'property' : 'name';
			printf( "<meta %s=\"%s\" content=\"%s\" />\n", esc_attr( $attr ), esc_attr( $key ), esc_attr( $value ) );
		}
	}
}
