<?php
/**
 * Standalone section — order received, wearing the section's skin.
 *
 * WooCommerce's own thank-you template, rebuilt in the section's design language
 * rather than its default definition list. Only the markup differs: every hook,
 * filter and sub-template WooCommerce calls is called here too, in the same order,
 * because gateways, Lottery for WooCommerce and the wallet all hang their
 * confirmation UI off them.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * `Nera_SAW_Standalone_Chrome::unoverride_template()` drops the theme's templates
 * inside the section, which left WooCommerce's stock markup carrying none of the
 * section's styling — the confirmation arrived as an unstyled run of text with two
 * full-width slabs where Pay and Cancel should be. The section owns this screen, so
 * the section supplies the template.
 *
 * This file is reached only through that unoverride, which runs only on a
 * standalone screen. The main site's own thank-you page is untouched.
 *
 * NOTHING IS DECLARED IN THIS FILE. WooCommerce includes a template as many times
 * as it needs to, and a declaration is a fatal on the second include.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package Nera_Strikeawin
 *
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="woocommerce-order saw-order">

	<?php
	if ( $order ) :

		do_action( 'woocommerce_before_thankyou', $order->get_id() );
		?>

		<?php if ( $order->has_status( 'failed' ) ) : ?>

			<div class="saw-order__hero saw-order__hero--bad">
				<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed saw-order__lead">
					<?php esc_html_e( 'Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce' ); ?>
				</p>
			</div>

			<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions saw-order__actions">
				<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="button pay order-actions-button"><?php esc_html_e( 'Pay', 'woocommerce' ); ?></a>
				<?php
				/*
				 * WooCommerce offers My Account here. That page belongs to the main
				 * site, and sending a player there from inside the section drops them
				 * somewhere with a different basket and a different header. The
				 * competitions list is the section's own way back.
				 */
				$saw_back = Nera_SAW_Standalone_Pages::url( 'competitions' );
				if ( $saw_back ) :
					?>
					<a href="<?php echo esc_url( $saw_back ); ?>" class="button cancel order-actions-button"><?php esc_html_e( 'Back to competitions', 'nera-strikeawin' ); ?></a>
				<?php endif; ?>
			</p>

		<?php else : ?>

			<?php
			/*
			 * An order that still needs paying is not a finished one. A green tick
			 * over a Pay button told the player two different things at once, so the
			 * mark waits for the payment it is reporting.
			 */
			$saw_awaiting = $order->needs_payment();
			?>
			<div class="saw-order__hero<?php echo $saw_awaiting ? ' saw-order__hero--wait' : ''; ?>">
				<span class="saw-order__tick" aria-hidden="true">
					<?php if ( $saw_awaiting ) : ?>
						<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
					<?php else : ?>
						<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
					<?php endif; ?>
				</span>
				<?php
				// Keeps `woocommerce_thankyou_order_received_text` working, which is
				// how the wording is changed without touching a template.
				wc_get_template( 'checkout/order-received.php', array( 'order' => $order ) );
				?>
			</div>

			<section class="saw-summary saw-order__facts">
				<dl class="saw-summary__facts woocommerce-order-overview woocommerce-thankyou-order-details order_details">

					<div class="saw-fact woocommerce-order-overview__order order">
						<dt><?php esc_html_e( 'Order number', 'woocommerce' ); ?></dt>
						<dd><?php echo esc_html( $order->get_order_number() ); ?></dd>
					</div>

					<div class="saw-fact woocommerce-order-overview__date date">
						<dt><?php esc_html_e( 'Date', 'woocommerce' ); ?></dt>
						<dd><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></dd>
					</div>

					<div class="saw-fact woocommerce-order-overview__total total">
						<dt><?php esc_html_e( 'Total', 'woocommerce' ); ?></dt>
						<dd><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></dd>
					</div>

					<?php if ( $order->get_payment_method_title() ) : ?>
						<div class="saw-fact woocommerce-order-overview__payment-method method">
							<dt><?php esc_html_e( 'Payment method', 'woocommerce' ); ?></dt>
							<dd><?php echo esc_html( Nera_SAW_Standalone_Basket::plain_label( $order->get_payment_method_title() ) ); ?></dd>
						</div>
					<?php endif; ?>

					<?php if ( is_user_logged_in() && $order->get_user_id() === get_current_user_id() && $order->get_billing_email() ) : ?>
						<div class="saw-fact saw-fact--wide woocommerce-order-overview__email email">
							<dt><?php esc_html_e( 'Email', 'woocommerce' ); ?></dt>
							<dd><?php echo esc_html( $order->get_billing_email() ); ?></dd>
						</div>
					<?php endif; ?>

				</dl>
			</section>

		<?php endif; ?>

		<?php do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() ); ?>
		<?php do_action( 'woocommerce_thankyou', $order->get_id() ); ?>

	<?php else : ?>

		<?php wc_get_template( 'checkout/order-received.php', array( 'order' => false ) ); ?>

	<?php endif; ?>

</div>
