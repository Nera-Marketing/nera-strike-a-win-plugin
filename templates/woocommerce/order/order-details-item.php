<?php
/**
 * Standalone section — one line of a received order.
 *
 * WooCommerce's stock template emits a `<tr>`, which only works inside its table.
 * The section renders an order as a card, so this emits the same content as rows
 * of a list instead. Every filter and hook WooCommerce applies to an order line is
 * applied here in the same order — `woocommerce_order_item_visible`,
 * `woocommerce_order_item_class`, `woocommerce_order_item_permalink`,
 * `woocommerce_order_item_name`, `woocommerce_order_item_quantity_html` and the two
 * meta hooks — because the tier an entry was bought at arrives through them.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package Nera_Strikeawin
 *
 * @var WC_Order      $order
 * @var int           $item_id
 * @var WC_Order_Item $item
 * @var bool          $show_purchase_note
 * @var string        $purchase_note
 * @var WC_Product    $product
 */

defined( 'ABSPATH' ) || exit;

if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
	return;
}
?>
<div class="saw-order__item <?php echo esc_attr( apply_filters( 'woocommerce_order_item_class', 'woocommerce-table__line-item order_item', $item, $order ) ); ?>">

	<div class="saw-order__item-main woocommerce-table__product-name product-name">
		<?php
		$saw_is_visible = $product && $product->is_visible();
		$saw_permalink  = apply_filters( 'woocommerce_order_item_permalink', $saw_is_visible ? $product->get_permalink( $item ) : '', $item, $order );

		echo '<span class="saw-order__item-name">';
		echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $saw_permalink ? sprintf( '<a href="%s">%s</a>', esc_url( $saw_permalink ), esc_html( $item->get_name() ) ) : esc_html( $item->get_name() ), $item, $saw_is_visible ) );

		$saw_qty          = $item->get_quantity();
		$saw_refunded_qty = $order->get_qty_refunded_for_item( $item_id );

		if ( $saw_refunded_qty ) {
			$saw_qty_display = '<del>' . esc_html( $saw_qty ) . '</del> <ins>' . esc_html( $saw_qty - ( $saw_refunded_qty * -1 ) ) . '</ins>';
		} else {
			$saw_qty_display = esc_html( $saw_qty );
		}

		echo apply_filters( 'woocommerce_order_item_quantity_html', ' <strong class="product-quantity saw-order__qty">' . sprintf( '&times;&nbsp;%s', $saw_qty_display ) . '</strong>', $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above, filtered markup by design.
		echo '</span>';

		do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, false );

		wc_display_item_meta( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, false );
		?>
	</div>

	<div class="saw-order__item-total woocommerce-table__product-total product-total">
		<?php echo wp_kses_post( $order->get_formatted_line_subtotal( $item ) ); ?>
	</div>

</div>

<?php if ( $show_purchase_note && $purchase_note ) : ?>
	<div class="saw-order__note woocommerce-table__product-purchase-note product-purchase-note">
		<?php echo wpautop( do_shortcode( wp_kses_post( $purchase_note ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
<?php endif; ?>
