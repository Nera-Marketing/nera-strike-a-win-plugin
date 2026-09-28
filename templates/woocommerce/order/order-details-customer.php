<?php
/**
 * Standalone section — customer details on a received order.
 *
 * WooCommerce's stock template renders bare `<address>` elements, which in this
 * skin came out as a run of italics with no card around them. Same content, same
 * hooks, laid out as the section's summary card.
 *
 * A competition entry needs no shipping address, but the shipping half is kept
 * because a site may sell something else alongside and an order that needs it must
 * still show it.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package Nera_Strikeawin
 *
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;

$saw_show_shipping = ! wc_ship_to_billing_address_only() && $order->needs_shipping_address();
?>
<section class="woocommerce-customer-details saw-order__customer">

	<h2 class="saw-order__title"><?php esc_html_e( 'Billing address', 'woocommerce' ); ?></h2>

	<div class="saw-summary">
		<address class="saw-order__address">
			<?php echo wp_kses_post( $order->get_formatted_billing_address( esc_html__( 'N/A', 'woocommerce' ) ) ); ?>

			<?php if ( $order->get_billing_phone() ) : ?>
				<span class="woocommerce-customer-details--phone"><?php echo esc_html( $order->get_billing_phone() ); ?></span>
			<?php endif; ?>

			<?php if ( $order->get_billing_email() ) : ?>
				<span class="woocommerce-customer-details--email"><?php echo esc_html( $order->get_billing_email() ); ?></span>
			<?php endif; ?>

			<?php do_action( 'woocommerce_order_details_after_customer_address', 'billing', $order ); ?>
		</address>
	</div>

	<?php if ( $saw_show_shipping ) : ?>
		<h2 class="saw-order__title"><?php esc_html_e( 'Shipping address', 'woocommerce' ); ?></h2>

		<div class="saw-summary">
			<address class="saw-order__address">
				<?php echo wp_kses_post( $order->get_formatted_shipping_address( esc_html__( 'N/A', 'woocommerce' ) ) ); ?>

				<?php if ( $order->get_shipping_phone() ) : ?>
					<span class="woocommerce-customer-details--phone"><?php echo esc_html( $order->get_shipping_phone() ); ?></span>
				<?php endif; ?>

				<?php do_action( 'woocommerce_order_details_after_customer_address', 'shipping', $order ); ?>
			</address>
		</div>
	<?php endif; ?>

</section>
