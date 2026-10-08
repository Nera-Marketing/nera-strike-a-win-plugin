<?php
/**
 * Fixed-price cart entry for a Strikeawin competition (a lottery product).
 *
 * Tiers are Strikeawin-owned (not WC variations). The player picks a tier; the
 * product is added to the cart tagged with the tier, its line price is
 * overridden to the tier's fixed fee (bypassing the lottery's ticket pricing),
 * quantity is forced to 1 (one entry = one run), and the tier is written to
 * order-item meta that the reservation/run chain reads.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Cart_Entry
 */
class Nera_SAW_Cart_Entry {

	const CART_KEY = 'saw_tier';

	/**
	 * Hooks + shortcode.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_cart_item_from_session' ), 10, 2 );
		// Pool capacity is a real gate in both directions: at add-to-cart (catches
		// the common case early) and again at checkout submission, immediately
		// before payment (catches the pool having shrunk between the two — the
		// race this exists for). Neither replaces the other.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_pool_capacity' ), 20, 3 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_pool_capacity' ) );
		// Quantity is the number of runs bought (ADR 0006) — allow >1 even if the
		// underlying lottery product is otherwise sold individually.
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'allow_multiple_runs' ), 20, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_price' ), 20, 1 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_tier' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'add_order_item_meta' ), 10, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_internal_order_meta' ) );
		if ( Nera_SAW_Constants::frontend_ui_enabled() ) {
			add_action( 'woocommerce_order_item_meta_start', array( __CLASS__, 'render_order_tier_badge' ), 10, 3 );
			add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'prepare_account_order_view_lines' ), 6, 1 );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_order_styles' ), 20 );
			add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'hide_ticket_numbers_on_thankyou' ), 20, 2 );
		}
		add_shortcode( 'strikeawin_entry', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Tag the cart item with the chosen tier (validated against config).
	 *
	 * @param array $data       Cart item data.
	 * @param int   $product_id Product ID.
	 * @return array
	 */
	public static function add_cart_item_data( $data, $product_id ) {
		if ( ! Nera_SAW_Competition_Config::is_competition( $product_id ) ) {
			return $data;
		}
		$tier_key = isset( $_REQUEST[ self::CART_KEY ] ) ? sanitize_key( wp_unslash( $_REQUEST[ self::CART_KEY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$config   = Nera_SAW_Competition_Config::get( $product_id );
		if ( '' === $tier_key || ! Nera_SAW_Competition_Config::tier( $config, $tier_key ) ) {
			return $data; // no valid tier -> leave as a normal add (won't get SAW price).
		}
		$data[ self::CART_KEY ] = $tier_key;
		return $data;
	}

	/**
	 * Block an add-to-cart the ticket pool cannot possibly honour.
	 *
	 * A run's tickets are reserved worst-case at grant time
	 * (`Nera_SAW_Run_Grants::grant_for_order()`, `qty * max_possible_spins()`), but
	 * that reservation happens *after* payment — by design, ADR 0006 moved it there
	 * so an unpaid cart never holds stock hostage. That leaves nothing upstream
	 * stopping a sale the pool cannot back, unless something checks here, before
	 * money moves. This is that check.
	 *
	 * @param bool $passed     Whether validation passed so far.
	 * @param int  $product_id Product being added.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public static function validate_pool_capacity( $passed, $product_id, $quantity ) {
		if ( ! $passed || ! Nera_SAW_Competition_Config::is_competition( $product_id ) ) {
			return $passed;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tier_key = isset( $_REQUEST[ self::CART_KEY ] ) ? sanitize_key( wp_unslash( $_REQUEST[ self::CART_KEY ] ) ) : '';
		if ( '' === $tier_key ) {
			return $passed; // No SAW tier tagged -> not this class's add to gate.
		}

		$config = Nera_SAW_Competition_Config::get( $product_id );
		if ( ! Nera_SAW_Competition_Config::tier( $config, $tier_key ) ) {
			return $passed;
		}

		if ( ! self::pool_has_room( $product_id, $config, $tier_key, (int) $quantity ) ) {
			wc_add_notice(
				__( 'Sorry, this competition does not have enough tickets left to cover that many runs at this tier. Try a smaller quantity, another tier, or check back once the draw refreshes.', 'nera-strikeawin' ),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/**
	 * Re-check the whole cart's pool capacity immediately before payment.
	 *
	 * `woocommerce_check_cart_items` runs from `WC_Checkout::validate_checkout()`
	 * on every submission — this is the check that actually matters, because the
	 * pool can shrink between an item being added and the same shopper reaching
	 * payment (another order, or another line in this one). `wc_add_notice()`
	 * with type 'error' is how WooCommerce itself blocks `process_checkout()`
	 * here; nothing further needs to be returned.
	 */
	public static function check_cart_pool_capacity() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		// Sum quantity per (competition, tier): add-to-cart validation only ever
		// sees one line at a time, but two lines of the same tier together can
		// still ask for more than the pool has left.
		$needed = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$tier_key   = isset( $item[ self::CART_KEY ] ) ? (string) $item[ self::CART_KEY ] : '';
			if ( ! $product_id || '' === $tier_key || ! Nera_SAW_Competition_Config::is_competition( $product_id ) ) {
				continue;
			}
			$key            = $product_id . '|' . $tier_key;
			$needed[ $key ] = ( isset( $needed[ $key ] ) ? $needed[ $key ] : 0 ) + ( isset( $item['quantity'] ) ? (int) $item['quantity'] : 1 );
		}

		$flagged = array();
		foreach ( $needed as $key => $qty ) {
			list( $product_id, $tier_key ) = explode( '|', $key, 2 );
			$product_id = (int) $product_id;
			$config     = Nera_SAW_Competition_Config::get( $product_id );
			if ( self::pool_has_room( $product_id, $config, $tier_key, $qty ) ) {
				continue;
			}
			if ( isset( $flagged[ $product_id ] ) ) {
				continue; // One notice per competition is enough even if several tiers are short.
			}
			$flagged[ $product_id ] = true;

			$product = wc_get_product( $product_id );
			$name    = $product ? $product->get_name() : __( 'this competition', 'nera-strikeawin' );
			wc_add_notice(
				sprintf(
					/* translators: %s: competition name */
					__( 'Sorry, %s no longer has enough tickets left for the runs in your basket. Please lower the quantity or remove it to continue.', 'nera-strikeawin' ),
					esc_html( $name )
				),
				'error'
			);
		}
	}

	/**
	 * Does the pool have room for `quantity` more runs at this tier's worst case?
	 *
	 * Mirrors the display-only "fits" check the entry shortcode already uses
	 * (`shortcode()` below) — this is that same arithmetic, promoted to an actual
	 * gate instead of a disabled button.
	 *
	 * @param int    $product_id Competition product ID.
	 * @param array  $config     Resolved competition config.
	 * @param string $tier_key   Tier key.
	 * @param int    $quantity   Runs being requested.
	 * @return bool
	 */
	private static function pool_has_room( $product_id, array $config, $tier_key, $quantity ) {
		$quantity = max( 1, (int) $quantity );
		$max      = (int) Nera_SAW_Competition_Config::max_possible_spins( $config, $tier_key );
		if ( $max < 1 ) {
			return true; // Nothing would be reserved; nothing to block on.
		}

		$pool = Nera_SAW_Spin_Pool::get( $product_id );
		if ( ! $pool ) {
			return true; // Pool not provisioned yet — unchanged from today's behaviour.
		}
		if ( 'open' !== $pool->status ) {
			return false;
		}

		return (int) $pool->available >= ( $quantity * $max );
	}

	/**
	 * Restore the tier tag when the cart is loaded from session.
	 *
	 * @param array $cart_item Cart line.
	 * @param array $values    Stored session values.
	 * @return array
	 */
	public static function get_cart_item_from_session( $cart_item, $values ) {
		if ( isset( $values[ self::CART_KEY ] ) ) {
			$cart_item[ self::CART_KEY ] = sanitize_key( (string) $values[ self::CART_KEY ] );
		}
		return $cart_item;
	}

	/**
	 * Resolved tier for a cart line (null when not a tagged SAW entry).
	 *
	 * @param array $cart_item Cart line.
	 * @return array|null
	 */
	public static function cart_item_tier( $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) || empty( $cart_item['product_id'] ) ) {
			return null;
		}
		$config = Nera_SAW_Competition_Config::get( (int) $cart_item['product_id'] );
		$tier   = Nera_SAW_Competition_Config::tier( $config, $cart_item[ self::CART_KEY ] );
		return $tier ? $tier : null;
	}

	/**
	 * Cart/checkout unit badge HTML: "<tier> - <price> / run".
	 *
	 * @param array $cart_item Cart line.
	 * @return string Empty when the line is not a tagged SAW entry.
	 */
	public static function cart_unit_badge_html( $cart_item ) {
		if ( ! Nera_SAW_Constants::frontend_ui_enabled() ) {
			return '';
		}
		$tier = self::cart_item_tier( $cart_item );
		if ( ! $tier ) {
			return '';
		}
		$html = self::tier_price_badge_html( $tier );
		/**
		 * Filter the cart unit badge for a Strike A Win tier line.
		 *
		 * @param string $html      Badge HTML.
		 * @param array  $tier      Resolved tier.
		 * @param array  $cart_item Cart line.
		 */
		return (string) apply_filters( 'nera_saw_cart_unit_badge_html', $html, $tier, $cart_item );
	}

	/**
	 * One entry = one run: force quantity 1 for competitions.
	 *
	 * @param int $qty        Quantity.
	 * @param int $product_id Product ID.
	 * @return int
	 */
	public static function force_single_qty( $qty, $product_id ) {
		return Nera_SAW_Competition_Config::is_competition( $product_id ) ? 1 : $qty;
	}

	/**
	 * Sell competitions individually (enforces qty 1 in the cart UI too).
	 *
	 * @param bool       $individual Current.
	 * @param WC_Product $product    Product.
	 * @return bool
	 */
	public static function sold_individually( $individual, $product ) {
		if ( $product && Nera_SAW_Competition_Config::is_competition( $product->get_id() ) ) {
			return true;
		}
		return $individual;
	}

	/**
	 * Allow competitions to be bought with quantity > 1 (quantity = runs), even
	 * if the underlying lottery product would otherwise be sold individually.
	 *
	 * @param bool       $individual Current sold-individually flag.
	 * @param WC_Product $product    Product.
	 * @return bool
	 */
	public static function allow_multiple_runs( $individual, $product ) {
		if ( $product && Nera_SAW_Competition_Config::is_competition( $product->get_id() ) ) {
			return false;
		}
		return $individual;
	}

	/**
	 * Override the line price to the tier's fixed fee.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function apply_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ self::CART_KEY ] ) || empty( $item['data'] ) ) {
				continue;
			}
			$product_id = $item['data']->get_id();
			$config     = Nera_SAW_Competition_Config::get( $product_id );
			$tier       = Nera_SAW_Competition_Config::tier( $config, $item[ self::CART_KEY ] );
			if ( $tier ) {
				$item['data']->set_price( (float) $tier['price'] );
			}
		}
	}

	/**
	 * Show the tier label in cart/checkout.
	 *
	 * @param array $item_data Item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function display_tier( $item_data, $cart_item ) {
		if ( ! Nera_SAW_Constants::frontend_ui_enabled() ) {
			return $item_data;
		}
		if ( empty( $cart_item[ self::CART_KEY ] ) ) {
			return $item_data;
		}
		$config = Nera_SAW_Competition_Config::get( $cart_item['product_id'] );
		$tier   = Nera_SAW_Competition_Config::tier( $config, $cart_item[ self::CART_KEY ] );
		if ( $tier ) {
			$item_data[] = array(
				'key'   => __( 'Tier', 'nera-strikeawin' ),
				'value' => self::tier_price_label( $tier ),
			);
		}
		return $item_data;
	}

	/**
	 * Build the cart/checkout tier label: "<tier> - <price> / run" as plain text
	 * (WooCommerce esc_html's item-data values, so no markup — the currency symbol
	 * is decoded from wc_price()).
	 *
	 * @param array $tier Resolved tier (key, label, price, …).
	 * @return string
	 */
	private static function tier_price_label( array $tier ) {
		$price = isset( $tier['price'] ) ? (float) $tier['price'] : 0;
		$price_text = function_exists( 'wc_price' )
			? html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' )
			: number_format_i18n( $price, 2 );

		return sprintf(
			/* translators: 1: tier label, 2: formatted price. Result e.g. "Gold - £5.00 / run". */
			__( '%1$s - %2$s / run', 'nera-strikeawin' ),
			(string) $tier['label'],
			$price_text
		);
	}

	/**
	 * HTML badge for theme cart rows: "<tier> - <wc_price> / run".
	 *
	 * @param array $tier Resolved tier.
	 * @return string
	 */
	private static function tier_price_badge_html( array $tier ) {
		$price = isset( $tier['price'] ) ? (float) $tier['price'] : 0;
		$price_html = function_exists( 'wc_price' )
			? wc_price( $price )
			: esc_html( number_format_i18n( $price, 2 ) );

		return esc_html( (string) $tier['label'] ) . ' - ' . $price_html . esc_html__( ' / run', 'nera-strikeawin' );
	}

	/**
	 * Order view styles (thank-you + My Account order details).
	 */
	public static function enqueue_order_styles() {
		if ( ! function_exists( 'is_account_page' ) ) {
			return;
		}
		if ( self::is_account_view_order_page() || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
			self::enqueue_order_assets();
		}
	}

	/**
	 * Whether the current request is My Account → view order.
	 *
	 * @return bool
	 */
	private static function is_account_view_order_page() {
		if ( ! is_account_page() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) ) {
			return true;
		}
		global $wp;
		return isset( $wp->query_vars['view-order'] );
	}

	/**
	 * Enqueue order line CSS (and allow late script enqueue from render hooks).
	 */
	private static function enqueue_order_assets() {
		wp_enqueue_style( 'nera-saw-order', NERA_SAW_PLUGIN_URL . 'assets/css/order.css', array(), NERA_SAW_VERSION );
	}

	/**
	 * The child theme view-order template renders items without woocommerce_order_item_meta_start.
	 * Inject tier + run stats into each line card via a small script.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function prepare_account_order_view_lines( $order ) {
		if ( ! $order instanceof WC_Order || ! self::is_account_view_order_page() ) {
			return;
		}

		$lines    = array();
		$item_ids = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$item_ids[] = (int) $item_id;
			$html       = self::order_line_meta_html( $item_id, $item, $order );
			if ( '' === $html ) {
				continue;
			}
			$lines[] = array(
				'id'   => (int) $item_id,
				'qty'  => (int) $item->get_quantity(),
				'html' => $html,
			);
		}
		if ( empty( $lines ) ) {
			return;
		}

		self::enqueue_order_assets();
		wp_enqueue_script(
			'nera-saw-order-view',
			NERA_SAW_PLUGIN_URL . 'assets/js/order-view.js',
			array(),
			NERA_SAW_VERSION,
			true
		);
		wp_add_inline_script(
			'nera-saw-order-view',
			'window.NeraSAWOrderViewLines = ' . wp_json_encode( $lines ) . ';' . "\n"
			. 'window.NeraSAWOrderItemIds = ' . wp_json_encode( $item_ids ) . ';',
			'before'
		);
	}

	/**
	 * Tier badge for a placed order line (grant tier is authoritative when present).
	 *
	 * @param WC_Order_Item_Product $item           Order line.
	 * @param int                   $competition_id Product ID.
	 * @param string                $tier_key       Tier key.
	 * @param array|null            $config         Config snapshot (optional).
	 * @return string
	 */
	private static function order_tier_badge_html( $item, $competition_id, $tier_key, $config = null ) {
		if ( '' === $tier_key || ! Nera_SAW_Competition_Config::is_competition( $competition_id ) ) {
			return '';
		}
		if ( ! is_array( $config ) ) {
			$config = Nera_SAW_Competition_Config::get( $competition_id );
		}
		$tier = Nera_SAW_Competition_Config::tier( $config, $tier_key );
		if ( ! $tier ) {
			return '';
		}
		$label = esc_html( (string) $tier['label'] );
		$price = isset( $tier['price'] ) ? (float) $tier['price'] : 0;
		$price_html = function_exists( 'wc_price' )
			? wc_price( $price )
			: esc_html( number_format_i18n( $price, 2 ) );

		return '<span class="saw-order-tier-badge__label">' . $label . '</span>'
			. '<span class="saw-order-tier-badge__price">' . wp_kses_post( $price_html ) . esc_html__( ' / run', 'nera-strikeawin' ) . '</span>';
	}

	/**
	 * Tier badge for a placed order line (my account / thank-you).
	 *
	 * @param WC_Order_Item_Product $item Order line.
	 * @return string Empty when UI is off or the line is not a tagged SAW entry.
	 */
	public static function order_unit_badge_html( $item ) {
		if ( ! Nera_SAW_Constants::frontend_ui_enabled() || ! $item instanceof WC_Order_Item_Product ) {
			return '';
		}
		$tier_key = (string) $item->get_meta( Nera_SAW_Reservations::ITEM_TIER );
		if ( '' === $tier_key ) {
			return '';
		}
		$product_id = (int) $item->get_product_id();
		return self::order_tier_badge_html( $item, $product_id, $tier_key );
	}

	/**
	 * Runs completed / remaining for an order line (grant-backed).
	 *
	 * @param int                   $item_id Order item ID.
	 * @param WC_Order_Item_Product $item    Order line.
	 * @param WC_Order              $order   Order.
	 * @return string Empty when not a SAW line or grant is absent.
	 */
	public static function order_run_stats_html( $item_id, $item, $order ) {
		if ( ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order ) {
			return '';
		}
		$product_id = (int) $item->get_product_id();
		if ( ! $product_id || ! Nera_SAW_Competition_Config::is_competition( $product_id ) ) {
			return '';
		}
		$tier_key = (string) $item->get_meta( Nera_SAW_Reservations::ITEM_TIER );
		if ( '' === $tier_key ) {
			return '';
		}

		if ( $order->is_paid() ) {
			Nera_SAW_Run_Grants::grant_for_order( (int) $order->get_id() );
		}

		$stats = Nera_SAW_Run_Grants::order_line_stats( (int) $order->get_id(), (int) $item_id );
		if ( ! $stats || $stats['total'] < 1 ) {
			return '';
		}

		return self::format_order_run_stats_html( $stats );
	}

	/**
	 * Render purchased / completed / remaining run counts.
	 *
	 * @param array $stats Grant stats from order_line_stats().
	 * @return string
	 */
	private static function format_order_run_stats_html( array $stats ) {
		$total     = (int) $stats['total'];
		$completed = (int) $stats['completed'];
		$remaining = (int) $stats['remaining'];

		$html  = '<dl class="saw-order-runs">';
		$html .= '<div class="saw-order-runs__stat"><dt>' . esc_html__( 'Runs purchased', 'nera-strikeawin' ) . '</dt><dd>' . esc_html( (string) $total ) . '</dd></div>';
		$html .= '<div class="saw-order-runs__stat"><dt>' . esc_html__( 'Runs completed', 'nera-strikeawin' ) . '</dt><dd>' . esc_html( (string) $completed ) . '</dd></div>';
		$html .= '<div class="saw-order-runs__stat saw-order-runs__stat--remaining"><dt>' . esc_html__( 'Runs remaining', 'nera-strikeawin' ) . '</dt><dd>' . esc_html( (string) $remaining ) . '</dd></div>';
		$html .= '</dl>';
		if ( 'void' === $stats['status'] ) {
			$html .= '<p class="saw-order-runs__note">' . esc_html__( 'This entry was voided.', 'nera-strikeawin' ) . '</p>';
		}

		return $html;
	}

	/**
	 * Tier badge + run stats for a placed order line.
	 *
	 * @param int                   $item_id Order item ID.
	 * @param WC_Order_Item_Product $item    Order line.
	 * @param WC_Order              $order   Order.
	 * @return string
	 */
	public static function order_line_meta_html( $item_id, $item, $order ) {
		if ( ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order ) {
			return '';
		}

		$product_id = (int) $item->get_product_id();
		if ( ! $product_id || ! Nera_SAW_Competition_Config::is_competition( $product_id ) ) {
			return '';
		}

		if ( $order->is_paid() ) {
			Nera_SAW_Run_Grants::grant_for_order( (int) $order->get_id() );
		}

		$stats    = Nera_SAW_Run_Grants::order_line_stats( (int) $order->get_id(), (int) $item_id );
		$tier_key = '';
		$config   = null;

		if ( $stats ) {
			if ( ! empty( $stats['tier_key'] ) ) {
				$tier_key = (string) $stats['tier_key'];
			}
			$config = ! empty( $stats['config'] ) ? $stats['config'] : null;
		}
		if ( '' === $tier_key ) {
			$tier_key = (string) $item->get_meta( Nera_SAW_Reservations::ITEM_TIER );
		}

		if ( '' === $tier_key ) {
			return '';
		}

		$badge = self::order_tier_badge_html( $item, $product_id, $tier_key, $config );
		$stats_html = ( $stats && $stats['total'] > 0 ) ? self::format_order_run_stats_html( $stats ) : '';

		if ( '' === $badge && '' === $stats_html ) {
			return '';
		}

		$html = '<div class="saw-order-line" data-order-item-id="' . esc_attr( (string) (int) $item_id ) . '">';
		if ( '' !== $badge ) {
			$html .= '<div class="saw-order-tier-badge">' . wp_kses_post( $badge ) . '</div>';
		}
		if ( '' !== $stats_html ) {
			$html .= $stats_html;
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Echo the tier badge on order line items (checkout thank-you + my account).
	 *
	 * @param int                   $item_id Order item ID.
	 * @param WC_Order_Item_Product $item    Order line.
	 * @param WC_Order              $order   Order.
	 */
	public static function render_order_tier_badge( $item_id, $item, $order ) {
		$html = self::order_line_meta_html( $item_id, $item, $order );
		if ( '' === $html ) {
			return;
		}
		echo wp_kses_post( $html );
	}

	/**
	 * Keep internal SAW order-item meta out of customer-facing meta lists.
	 *
	 * @param array $hidden Hidden meta keys.
	 * @return array
	 */
	public static function hide_internal_order_meta( $hidden ) {
		$hidden[] = Nera_SAW_Reservations::ITEM_TIER;
		$hidden[] = Nera_SAW_Reservations::ITEM_SNAPSHOT;
		$hidden[] = Nera_SAW_Reservations::ITEM_RESERVATION;
		return $hidden;
	}

	/**
	 * Drop the lottery "Ticket Number( s )" meta from Strike A Win competition
	 * lines on the thank-you (order-received) page only.
	 *
	 * Buyers earn tickets by playing runs, not at purchase, so the raw ticket
	 * list on the order-received screen confuses them. The My Account order view,
	 * emails and admin are left untouched.
	 *
	 * @param array $formatted_meta Formatted meta objects keyed by meta ID.
	 * @param mixed $item           Order line item.
	 * @return array
	 */
	public static function hide_ticket_numbers_on_thankyou( $formatted_meta, $item ) {
		if ( ! is_array( $formatted_meta ) || empty( $formatted_meta ) ) {
			return $formatted_meta;
		}
		if ( ! function_exists( 'lty_get_order_item_ticket_number_name' ) || ! function_exists( 'is_order_received_page' ) ) {
			return $formatted_meta;
		}
		if ( ! is_order_received_page() ) {
			return $formatted_meta;
		}
		if ( ! $item instanceof WC_Order_Item_Product || ! Nera_SAW_Competition_Config::is_competition( (int) $item->get_product_id() ) ) {
			return $formatted_meta;
		}

		$ticket_key = lty_get_order_item_ticket_number_name();
		foreach ( $formatted_meta as $meta_id => $meta ) {
			if ( isset( $meta->key ) && $meta->key === $ticket_key ) {
				unset( $formatted_meta[ $meta_id ] );
			}
		}

		return $formatted_meta;
	}

	/**
	 * Persist the tier to the order line item (read by the reservation/run chain).
	 *
	 * @param WC_Order_Item_Product $item          Item.
	 * @param string                $cart_item_key Key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order.
	 */
	public static function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values[ self::CART_KEY ] ) ) {
			$item->update_meta_data( Nera_SAW_Reservations::ITEM_TIER, sanitize_key( $values[ self::CART_KEY ] ) );
		}
	}

	/**
	 * [strikeawin_entry id="123"] — tier cards that add the entry to the cart.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		if ( ! Nera_SAW_Constants::frontend_ui_enabled() ) {
			return '';
		}
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'strikeawin_entry' );
		$pid  = (int) $atts['id'];
		if ( ! $pid ) {
			global $product;
			if ( $product instanceof WC_Product ) {
				$pid = $product->get_id();
			}
		}
		if ( ! $pid || ! Nera_SAW_Competition_Config::is_competition( $pid ) ) {
			return '';
		}

		wp_enqueue_style( 'nera-saw-styles-shell', NERA_SAW_PLUGIN_URL . 'assets/css/quiz.css', array(), NERA_SAW_VERSION );

		$config = Nera_SAW_Competition_Config::get( $pid );
		$pool   = Nera_SAW_Spin_Pool::get( $pid );
		$closed = $pool && 'open' !== $pool->status;

		$cards = '';
		foreach ( (array) $config['tiers'] as $tier ) {
			// Ceiling gate: hide a tier a fresh perfect run could push over its cap.
			if ( ! Nera_SAW_Competition_Config::tier_offered( $config, $pid, $tier['key'] ) ) {
				continue;
			}
			$max      = Nera_SAW_Competition_Config::max_possible_spins( $config, $tier['key'] );
			$fits     = $pool ? ( (int) $pool->available >= $max ) : true;
			$url      = add_query_arg(
				array( 'add-to-cart' => $pid, self::CART_KEY => $tier['key'] ),
				function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' )
			);
			$disabled = ( $closed || ! $fits );
			$label    = esc_html( $tier['label'] );
			$price    = function_exists( 'wc_price' ) ? wc_price( $tier['price'] ) : esc_html( $tier['price'] );
			$note     = $closed
				? esc_html__( 'Competition closed', 'nera-strikeawin' )
				: ( $fits ? esc_html( sprintf( __( 'Up to %d tickets', 'nera-strikeawin' ), $max ) ) : esc_html__( 'Sold out at this tier', 'nera-strikeawin' ) );

			$cards .= '<li class="saw-tier-card">';
			$cards .= '<div class="saw-tier-label">' . $label . '</div>';
			$cards .= '<div class="saw-tier-price">' . $price . '</div>'; // wc_price returns escaped markup.
			$cards .= '<div class="saw-tier-note">' . $note . '</div>';
			if ( $disabled ) {
				$cards .= '<span class="saw-btn saw-btn-disabled">' . esc_html__( 'Enter', 'nera-strikeawin' ) . '</span>';
			} else {
				$cards .= '<a class="saw-btn" href="' . esc_url( $url ) . '">' . esc_html__( 'Enter', 'nera-strikeawin' ) . '</a>';
			}
			$cards .= '</li>';
		}

		return '<div class="saw-quiz"><h3>' . esc_html__( 'Choose your entry', 'nera-strikeawin' ) . '</h3><ul class="saw-tier-list">' . $cards . '</ul></div>';
	}
}
