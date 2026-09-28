<?php
/**
 * Standalone section — order details, wearing the section's skin.
 *
 * The same content WooCommerce's stock template renders, laid out as the section's
 * summary card rather than a `shop_table`. Every hook is kept and fired in the same
 * order: the ticket lines a Strike A Win entry earns are attached by callbacks on
 * `woocommerce_order_details_before_order_table`, and the wallet's part-payment
 * rows arrive through `get_order_item_totals()`.
 *
 * Pay and Cancel are rendered as ordinary section buttons. They are alternatives
 * rather than a pair, so Cancel is the quiet one — WooCommerce gives both the same
 * `.button` class, which in this skin used to make them two identical slabs.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package Nera_Strikeawin
 *
 * @var bool $show_downloads Controls whether the downloads table should be rendered.
 */

defined( 'ABSPATH' ) || exit;

$order = wc_get_order( $order_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

if ( ! $order ) {
	return;
}

$saw_items          = $order->get_items( apply_filters( 'woocommerce_purchase_order_item_types', 'line_item' ) );
$show_purchase_note = $order->has_status( apply_filters( 'woocommerce_purchase_note_order_statuses', array( 'completed', 'processing' ) ) );
$saw_downloads      = $order->get_downloadable_items();
$saw_actions        = array_filter(
	wc_get_account_orders_actions( $order ),
	function ( $key ) {
		return 'view' !== $key;
	},
	ARRAY_FILTER_USE_KEY
);

// True for a guest viewing a guest order too, since both user IDs are 0.
$saw_show_customer = $order->get_user_id() === get_current_user_id();

if ( $show_downloads ) {
	wc_get_template(
		'order/order-downloads.php',
		array(
			'downloads'  => $saw_downloads,
			'show_title' => true,
		)
	);
}
?>
<section class="woocommerce-order-details saw-order__details">

	<?php do_action( 'woocommerce_order_details_before_order_table', $order ); ?>

	<h2 class="woocommerce-order-details__title saw-order__title"><?php esc_html_e( 'Order details', 'woocommerce' ); ?></h2>

	<div class="saw-summary">

		<div class="saw-order__items">
			<?php
			do_action( 'woocommerce_order_details_before_order_table_items', $order );

			foreach ( $saw_items as $saw_item_id => $saw_item ) {
				$saw_product = $saw_item->get_product();

				wc_get_template(
					'order/order-details-item.php',
					array(
						'order'              => $order,
						'item_id'            => $saw_item_id,
						'item'               => $saw_item,
						'show_purchase_note' => $show_purchase_note,
						'purchase_note'      => $saw_product ? $saw_product->get_purchase_note() : '',
						'product'            => $saw_product,
					)
				);
			}

			do_action( 'woocommerce_order_details_after_order_table_items', $order );
			?>
		</div>

		<?php $saw_totals = $order->get_order_item_totals(); ?>
		<?php if ( $saw_totals ) : ?>
			<div class="saw-summary__rule"></div>
			<dl class="saw-order__totals">
				<?php foreach ( $saw_totals as $saw_key => $saw_total ) : ?>
					<div class="saw-order__total <?php echo esc_attr( 'order_total' === $saw_key ? 'saw-order__total--grand' : '' ); ?>">
						<dt><?php echo esc_html( $saw_total['label'] ); ?></dt>
						<dd><?php echo wp_kses_post( $saw_total['value'] ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		<?php endif; ?>

		<?php if ( ! empty( $saw_actions ) ) : ?>
			<div class="saw-summary__rule"></div>
			<div class="saw-order__actions">
				<span class="saw-order__actions-label order-actions--heading"><?php esc_html_e( 'Actions', 'woocommerce' ); ?>:</span>
				<span class="saw-order__actions-buttons">
					<?php
					foreach ( $saw_actions as $saw_action_key => $saw_action ) {
						$saw_aria = empty( $saw_action['aria-label'] )
							/* translators: %1$s Action name, %2$s Order number. */
							? sprintf( __( '%1$s order number %2$s', 'woocommerce' ), $saw_action['name'], $order->get_order_number() )
							: $saw_action['aria-label'];

						printf(
							'<a href="%1$s" class="button %2$s order-actions-button" aria-label="%3$s">%4$s</a>',
							esc_url( $saw_action['url'] ),
							esc_attr( sanitize_html_class( $saw_action_key ) ),
							esc_attr( $saw_aria ),
							esc_html( $saw_action['name'] )
						);
					}
					?>
				</span>
			</div>
		<?php endif; ?>

	</div>

	<?php do_action( 'woocommerce_order_details_after_order_table', $order ); ?>

</section>

<?php
if ( $saw_show_customer ) {
	wc_get_template( 'order/order-details-customer.php', array( 'order' => $order ) );
}
