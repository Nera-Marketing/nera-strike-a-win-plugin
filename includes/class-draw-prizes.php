<?php
/**
 * Strike A Win — offline draw entry.
 *
 * Client follow-up on "My runs and tickets" (Draw results): some
 * competitions are drawn BY HAND, outside the system entirely (a physical
 * draw event), not by lottery-for-woocommerce's own end-of-lottery
 * mechanism (confirmed with the user: neither its automatic random draw
 * nor its "manual" mode, which still only lets an admin pick a winner FROM
 * the list of already-sold tickets — this instead lets the admin type in
 * whichever numbers the offline draw actually produced, sight-unseen of
 * who holds them, and the system works out who won what).
 *
 * The admin's own job, on the Strike A Win product-edit tab
 * (`Nera_SAW_Competition_Admin`), has two parts:
 *   - A prize table: one row per prize, each with the ticket number(s) that
 *     win it (a row can list more than one number — the user's own call,
 *     for a prize with several winners, e.g. "runner-up x3").
 *   - A "Draw closed" checkbox: flips the competition to drawn (reusing
 *     lottery-for-woocommerce's own `lty_lottery_status` meta — the exact
 *     thing Draw results, the account hub's "Tickets live" stat, and the
 *     "Sold out" → "Closed" badge already read) and awards every
 *     not-yet-awarded, valid ticket number.
 *
 * Prizes are NOT auto-fulfilled the way lottery-for-woocommerce's own
 * instant-win feature is (a coupon/product attached to the ticket's OWN
 * order, live, at purchase time) — confirmed with the user: results here
 * are entered well after that order has long since closed, so there is no
 * "current order" to attach anything to. Instead this mirrors
 * lottery-for-woocommerce's own MAIN draw winner flow
 * (`LTY_Lottery_Winner::create_order_for_winners()`): a brand new $0 order
 * (gift product) or a single-use coupon, created fresh for the winner.
 * Built with plain WooCommerce APIs rather than reusing that class
 * directly — it is lottery-for-woocommerce's own internal implementation,
 * not a surface meant for another plugin to call.
 *
 * Storage: a `prizes` array and a `draw_closed` flag inside the
 * competition's own existing `_saw_config` meta
 * (`Nera_SAW_Competition_Config`) — no new table or post type.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Draw_Prizes
 */
class Nera_SAW_Draw_Prizes {

	/**
	 * Transient key prefix for the one-shot admin notice after a save.
	 */
	const NOTICE_KEY = 'nera_saw_draw_prizes_notice';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_queued_notice' ) );
	}

	/**
	 * Normalize the posted prize table into the stored shape, carrying
	 * forward any row's already-awarded state from the previously saved
	 * config (matched by row id) so a repeat save never re-awards the same
	 * ticket number twice.
	 *
	 * @param array $posted   Raw `$_POST['saw_prize']` rows (already wp_unslash()ed).
	 * @param array $existing Previously stored 'prizes' array.
	 * @return array Normalized prize rows.
	 */
	public static function sanitize_from_request( array $posted, array $existing ) {
		$by_id = array();
		foreach ( $existing as $row ) {
			if ( ! empty( $row['id'] ) ) {
				$by_id[ $row['id'] ] = $row;
			}
		}

		$out = array();
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
			if ( '' === $name ) {
				continue; // An empty row is a removed/never-filled-in row.
			}

			$id = isset( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';
			if ( '' === $id ) {
				$id = 'saw_prize_' . substr( md5( $name . wp_rand() ), 0, 12 );
			}

			$type = isset( $row['prize_type'] ) && 'coupon' === $row['prize_type'] ? 'coupon' : 'product';

			$numbers = array();
			$raw     = isset( $row['ticket_numbers'] ) ? (string) $row['ticket_numbers'] : '';
			foreach ( preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $num ) {
				$num = trim( $num );
				if ( '' !== $num ) {
					$numbers[] = $num;
				}
			}
			$numbers = array_values( array_unique( $numbers ) );

			$prior   = isset( $by_id[ $id ] ) ? $by_id[ $id ] : array();
			$awarded = isset( $prior['awarded'] ) && is_array( $prior['awarded'] )
				? array_values( array_intersect( $prior['awarded'], $numbers ) )
				: array();

			$out[] = array(
				'id'              => $id,
				'name'            => $name,
				'prize_type'      => $type,
				'gift_product_id' => 'product' === $type ? (int) ( $row['gift_product_id'] ?? 0 ) : 0,
				'coupon_amount'   => 'coupon' === $type ? (float) ( $row['coupon_amount'] ?? 0 ) : 0.0,
				'ticket_numbers'  => $numbers,
				'awarded'         => $awarded,
				'awarded_orders'  => isset( $prior['awarded_orders'] ) && is_array( $prior['awarded_orders'] ) ? $prior['awarded_orders'] : array(),
			);
		}
		return $out;
	}

	/**
	 * Ticket numbers across every prize row that do not exist among this
	 * competition's own minted numbers — the admin's typo guard. Existence
	 * only, on purpose (client decision): never resolve or show whose
	 * ticket a number is at this step, so the admin cannot see names before
	 * the numbers are locked in.
	 *
	 * @param int   $competition_id Competition product ID.
	 * @param array $prizes         Sanitized prize rows.
	 * @return string[] Invalid ticket numbers, first-seen order, deduplicated.
	 */
	public static function invalid_ticket_numbers( $competition_id, array $prizes ) {
		$owners = Nera_SAW_Run::ticket_owner_map_for_competition( $competition_id );
		$bad    = array();
		foreach ( $prizes as $row ) {
			foreach ( (array) $row['ticket_numbers'] as $num ) {
				if ( ! isset( $owners[ $num ] ) && ! in_array( $num, $bad, true ) ) {
					$bad[] = $num;
				}
			}
		}
		return $bad;
	}

	/**
	 * Award every not-yet-awarded ticket number across every prize row.
	 * Safe to call repeatedly (e.g. a later save adding more numbers) —
	 * already-awarded numbers are skipped via each row's own 'awarded' list.
	 *
	 * @param int   $competition_id Competition product ID.
	 * @param array $prizes         Sanitized prize rows.
	 * @return array Prize rows with 'awarded'/'awarded_orders' updated.
	 */
	public static function award_all( $competition_id, array $prizes ) {
		$owners = Nera_SAW_Run::ticket_owner_map_for_competition( $competition_id );

		foreach ( $prizes as &$row ) {
			$pending = array_diff( $row['ticket_numbers'], $row['awarded'] );
			foreach ( $pending as $num ) {
				if ( ! isset( $owners[ $num ] ) ) {
					continue; // Caught by invalid_ticket_numbers() upstream; never award a number nobody holds.
				}
				$result = self::award_one( (int) $owners[ $num ]['user_id'], $row );
				if ( null !== $result ) {
					$row['awarded'][]             = $num;
					$row['awarded_orders'][ $num ] = $result;
				}
			}
		}
		unset( $row );
		return $prizes;
	}

	/**
	 * Create the actual prize for one winner.
	 *
	 * @param int   $user_id Winner's user ID.
	 * @param array $row     Prize row.
	 * @return int|string|null Order ID (product prize), coupon code (coupon prize), or null on failure.
	 */
	private static function award_one( $user_id, array $row ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}
		return 'coupon' === $row['prize_type']
			? self::create_prize_coupon( $user, $row )
			: self::create_prize_order( $user, $row );
	}

	/**
	 * A $0 order carrying the prize's gift product, assigned to the winner
	 * — mirrors lottery-for-woocommerce's own main-draw winner order
	 * (`LTY_Lottery_Winner::create_order_for_winners()`), built fresh here
	 * with plain WooCommerce APIs rather than calling that (internal,
	 * not-for-reuse) class.
	 *
	 * @param WP_User $user Winner.
	 * @param array   $row  Prize row.
	 * @return int|null Order ID.
	 */
	private static function create_prize_order( $user, array $row ) {
		$product_id = (int) $row['gift_product_id'];
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		$order = wc_create_order( array( 'customer_id' => $user->ID ) );
		if ( is_wp_error( $order ) ) {
			return null;
		}

		$order->set_billing_email( $user->user_email );
		$order->set_billing_first_name( $user->first_name ? $user->first_name : $user->display_name );
		$order->set_billing_last_name( (string) $user->last_name );

		if ( $product ) {
			$order->add_product( $product, 1, array( 'total' => 0, 'subtotal' => 0 ) );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: prize name */
				__( 'Strike A Win draw prize: %s', 'nera-strikeawin' ),
				$row['name']
			)
		);
		$order->calculate_totals();
		$order->update_status( 'wc-processing' );
		$order->save();

		return (int) $order->get_id();
	}

	/**
	 * A single-use coupon restricted to the winner's own account email.
	 *
	 * @param WP_User $user Winner.
	 * @param array   $row  Prize row.
	 * @return string|null Coupon code.
	 */
	private static function create_prize_coupon( $user, array $row ) {
		$code   = 'SAWPRIZE-' . strtoupper( wp_generate_password( 8, false, false ) );
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( max( 0, (float) $row['coupon_amount'] ) );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_email_restrictions( array( $user->user_email ) );
		$coupon->set_description(
			sprintf(
				/* translators: %s: prize name */
				__( 'Strike A Win draw prize: %s', 'nera-strikeawin' ),
				$row['name']
			)
		);
		$id = $coupon->save();
		return $id ? $code : null;
	}

	/**
	 * Whether a competition reads as "closed for the draw" right now —
	 * either because this feature's own checkbox was saved on (the
	 * `draw_closed` config flag), or because `lty_lottery_status` already
	 * says `lty_lottery_finished` by some OTHER means (an actual
	 * lottery-for-woocommerce draw from before this feature existed, or
	 * the meta set directly during this project's own test-data seeding).
	 *
	 * Without this, the product-edit checkbox could show unchecked for a
	 * competition the front end already displays as drawn (Draw results,
	 * the "Sold out" → "Draw End" badge) — confusing rather than merely
	 * cosmetic, since saving the page in that state would otherwise look
	 * like it is closing an already-closed competition for the first time.
	 *
	 * @param int   $product_id Competition product ID.
	 * @param array $config     Nera_SAW_Competition_Config::get() result.
	 * @return bool
	 */
	public static function is_closed( $product_id, array $config ) {
		if ( ! empty( $config['draw_closed'] ) ) {
			return true;
		}
		return 'lty_lottery_finished' === get_post_meta( (int) $product_id, 'lty_lottery_status', true );
	}

	/**
	 * Entry point from the product-edit save handler.
	 *
	 * @param int   $product_id      Competition product ID.
	 * @param array $existing_config Current Nera_SAW_Competition_Config::get() result.
	 * @return array{draw_closed: bool, prizes: array} Fields to merge into the saved config.
	 */
	public static function save_from_request( $product_id, array $existing_config ) {
		$posted = array();
		if ( isset( $_POST['saw_prize'] ) && is_array( $_POST['saw_prize'] ) ) {
			$posted = wp_unslash( $_POST['saw_prize'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_from_request() sanitizes every field itself.
		}
		$prizes = self::sanitize_from_request( $posted, (array) ( $existing_config['prizes'] ?? array() ) );

		$was_closed = self::is_closed( $product_id, $existing_config );
		$now_closed = ! empty( $_POST['saw_draw_closed'] );

		$invalid = self::invalid_ticket_numbers( $product_id, $prizes );
		if ( ! empty( $invalid ) ) {
			self::queue_notice(
				'error',
				sprintf(
					/* translators: %s: comma-separated entry numbers */
					__( 'These entry numbers do not exist on this competition — fix them before the draw can close: %s', 'nera-strikeawin' ),
					implode( ', ', $invalid )
				)
			);
			// Never flip closed (or award anything) on top of bad data.
			$now_closed = $was_closed;
		} else {
			if ( $now_closed ) {
				$prizes = self::award_all( $product_id, $prizes );
			}
			if ( $now_closed && ! $was_closed ) {
				self::close_competition_for_draw( $product_id );
				self::queue_notice( 'success', __( 'Draw entered — the competition is now closed and winners can see their prize on Draw results.', 'nera-strikeawin' ) );
			}
		}

		return array(
			'draw_closed' => $now_closed,
			'prizes'      => $prizes,
		);
	}

	/**
	 * Flip the underlying lottery-for-woocommerce status meta so every
	 * existing reader of it (Draw results, the account hub's "Tickets
	 * live" stat, the "Sold out" → "Closed" badge) picks this competition
	 * up with no change on their own side. Read-only everywhere else in
	 * this plugin until now; this is the one place that writes it.
	 *
	 * @param int $competition_id Competition product ID.
	 */
	private static function close_competition_for_draw( $competition_id ) {
		update_post_meta( $competition_id, 'lty_lottery_status', 'lty_lottery_finished' );
		if ( ! get_post_meta( $competition_id, 'lty_finished_date', true ) ) {
			update_post_meta( $competition_id, 'lty_finished_date', current_time( 'mysql' ) );
			update_post_meta( $competition_id, 'lty_finished_date_gmt', current_time( 'mysql', true ) );
		}
	}

	/**
	 * Queue a one-shot admin notice for the current user (survives the
	 * redirect `woocommerce_process_product_meta`'s own save does).
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Notice text.
	 */
	private static function queue_notice( $type, $message ) {
		set_transient( self::NOTICE_KEY . '_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Render + clear the queued notice.
	 */
	public static function render_queued_notice() {
		$key    = self::NOTICE_KEY . '_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Front end: which of a player's own ticket numbers on a competition
	 * match a configured prize — Draw results' own "giống dạng hiển thị của
	 * instant win prizes" ask: a flat list, prize name + the specific
	 * number(s) that won it, no grouping.
	 *
	 * @param array    $prizes            Stored 'prizes' config for the competition.
	 * @param string[] $my_ticket_numbers This player's own minted numbers on it.
	 * @return array<int, array{name:string, ticket_numbers:string[]}> Only rows the player actually won.
	 */
	public static function my_wins( array $prizes, array $my_ticket_numbers ) {
		$mine = array_flip( array_map( 'strval', $my_ticket_numbers ) );
		$wins = array();
		foreach ( $prizes as $row ) {
			$matched = array();
			foreach ( (array) ( $row['ticket_numbers'] ?? array() ) as $num ) {
				if ( isset( $mine[ (string) $num ] ) ) {
					$matched[] = (string) $num;
				}
			}
			if ( $matched ) {
				$wins[] = array(
					'name'           => (string) $row['name'],
					'ticket_numbers' => $matched,
				);
			}
		}
		return $wins;
	}
}
