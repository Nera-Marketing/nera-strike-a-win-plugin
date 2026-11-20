<?php
/**
 * Payment confirmed, as a sheet over the competition page.
 *
 * A paid entry order sends its owner to the first entry's competition page with
 * `saw_paid=<order id>&key=<order key>`; that page draws the confirmed sheet
 * (parts/paid-sheet.php) and nothing else from the order. Orders still awaiting
 * payment, failed orders and anyone but the signed-in owner holding the right key
 * keep the section's order-received page.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Paid_Sheet
 */
class Nera_SAW_Paid_Sheet {

	const ARG = 'saw_paid';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! Nera_SAW_Mode::is_standalone() ) {
			return;
		}
		add_filter( 'woocommerce_get_checkout_order_received_url', array( __CLASS__, 'received_url' ), 20, 2 );
		// After wc_clear_cart_after_payment (20), which empties the basket on this view.
		add_action( 'template_redirect', array( __CLASS__, 'leave_order_received' ), 25 );
		add_action( 'template_redirect', array( __CLASS__, 'guard_competition' ), 25 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 5 );
	}

	/**
	 * Is this the confirmed state for the current visitor: a paid section entry
	 * order of theirs, opened with its key? The redirect away from order received
	 * and the competition page both ask this, so neither can send the player back
	 * to the other.
	 *
	 * @param WC_Order|false $order Order.
	 * @param string|null    $key   Key from the URL; null skips the key (the URL is being built for the owner).
	 * @return bool
	 */
	public static function confirmed( $order, $key = null ) {
		return self::owned( $order, $key ) && self::paid( $order );
	}

	/**
	 * Signed-in owner of a section entry order (entry lines only), with the right key.
	 *
	 * @param WC_Order|false $order Order.
	 * @param string|null    $key   Key from the URL; null skips the key.
	 * @return bool
	 */
	private static function owned( $order, $key ) {
		if ( ! $order instanceof WC_Order || ! is_user_logged_in() ) {
			return false;
		}
		if ( (int) $order->get_user_id() !== get_current_user_id() ) {
			return false;
		}
		if ( null !== $key && ! hash_equals( (string) $order->get_order_key(), (string) $key ) ) {
			return false;
		}
		return self::entries_only( $order );
	}

	/**
	 * Paid, in a status the section's thank-you treats as done.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function paid( $order ) {
		return $order->is_paid()
			&& $order->has_status( apply_filters( 'nera_saw_thankyou_allowed_statuses', array( 'processing', 'completed' ) ) );
	}

	/**
	 * Every line of the order is a competition entry line. A mixed basket goes
	 * through the main checkout, so it keeps the main order-received page.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function entries_only( $order ) {
		$items = $order->get_items();
		if ( ! $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( ! self::is_entry_line( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A line bought through the section: an entry tier on a competition.
	 *
	 * @param WC_Order_Item $item Order line.
	 * @return bool
	 */
	private static function is_entry_line( $item ) {
		return $item instanceof WC_Order_Item_Product
			&& '' !== (string) $item->get_meta( Nera_SAW_Reservations::ITEM_TIER )
			&& Nera_SAW_Competition_Config::is_competition( (int) $item->get_product_id() );
	}

	/**
	 * The competition of the order's first entry line, or 0 when the order holds none.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public static function competition( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( self::is_entry_line( $item ) ) {
				return (int) $item->get_product_id();
			}
		}
		return 0;
	}

	/**
	 * The competition page with the sheet open.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function url( $order ) {
		$post = get_post( self::competition( $order ) );
		if ( ! $post ) {
			return '';
		}
		$url = add_query_arg(
			array(
				self::ARG => $order->get_id(),
				'key'     => $order->get_order_key(),
			),
			Nera_SAW_Router::url( 'competition', $post->post_name )
		);
		return Nera_SAW_Language::carry_current_language( $url );
	}

	/**
	 * Where the gateway sends the player after paying. Only an order already paid
	 * when the URL is built: a gateway that returns to order received to finish
	 * taking payment keeps that page.
	 *
	 * @param string   $url   Order received URL.
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function received_url( $url, $order ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $url;
		}
		if ( ! self::confirmed( $order ) ) {
			return $url;
		}
		$target = self::url( $order );
		return '' !== $target ? $target : $url;
	}

	/**
	 * A visit to the section's order-received page for a confirmed order (a
	 * reload, a gateway that paid after building its return URL) moves on to the
	 * sheet.
	 */
	public static function leave_order_received() {
		if ( ! Nera_SAW_Standalone_Basket::is_section_checkout() ) {
			return;
		}
		$id = absint( get_query_var( 'order-received' ) );
		if ( ! $id ) {
			return;
		}
		$order = wc_get_order( $id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the check.
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! self::confirmed( $order, $key ) ) {
			return;
		}
		$target = self::url( $order );
		if ( '' !== $target ) {
			wp_safe_redirect( $target );
			exit;
		}
	}

	/**
	 * The order on a competition URL, when it is the confirmed state for this page.
	 *
	 * @return WC_Order|null
	 */
	private static function order_here() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key is the check.
		if ( 'competition' !== Nera_SAW_Router::current_route() || empty( $_GET[ self::ARG ] ) ) {
			return null;
		}
		$order = wc_get_order( absint( $_GET[ self::ARG ] ) );
		$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		if ( ! self::owned( $order, $key ) ) {
			return null;
		}
		$post = get_post( self::competition( $order ) );
		if ( ! $post || (string) get_query_var( Nera_SAW_Router::QV_ARG ) !== $post->post_name ) {
			return null;
		}
		return $order;
	}

	/**
	 * The owner's own order with the right key that is not paid (yet, or any more)
	 * goes back to order received, which shows its awaiting or failed state.
	 * Anyone else just gets the competition page.
	 */
	public static function guard_competition() {
		$order = self::order_here();
		if ( ! $order ) {
			return;
		}
		if ( ! self::paid( $order ) ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}
		nocache_headers();
	}

	/**
	 * What the sheet says: the runs on the order still left to play, and the quiz facts when the
	 * order is for one competition only.
	 *
	 * @param WC_Order $order Order.
	 * @return array{runs:int, questions:int, seconds:int, url:string}
	 */
	public static function data( $order ) {
		// Idempotent: makes sure the order's grants exist before they are counted.
		Nera_SAW_Run_Grants::grant_for_order( $order->get_id() );

		$runs         = 0;
		$competitions = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || '' === (string) $item->get_meta( Nera_SAW_Reservations::ITEM_TIER ) ) {
				continue;
			}
			$stats = Nera_SAW_Run_Grants::order_line_stats( $order->get_id(), $item_id );
			if ( ! $stats ) {
				continue;
			}
			$runs += (int) $stats['remaining'];
			$competitions[ (int) $item->get_product_id() ] = true;
		}

		$questions = 0;
		$seconds   = 0;
		if ( 1 === count( $competitions ) ) {
			$spec      = Nera_SAW_Competition_Spec::get( (int) key( $competitions ) );
			$questions = (int) ( $spec['quiz']['questions'] ?? 0 );
			$seconds   = (int) ( $spec['timer']['seconds'] ?? 0 );
		}

		return array(
			'runs'      => $runs,
			'questions' => $questions,
			'seconds'   => $seconds,
			'url'       => nera_saw_get_play_url( self::competition( $order ) ),
		);
	}

	/**
	 * Draw the sheet over the competition page.
	 */
	public static function render() {
		$order = self::order_here();
		if ( ! $order || ! self::paid( $order ) ) {
			return;
		}
		Nera_SAW_Router::part( 'parts/paid-sheet.php', array( 'saw_paid' => self::data( $order ) ) );
	}
}
