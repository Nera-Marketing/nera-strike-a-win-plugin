<?php
/**
 * Checkout payment methods — the section's copy.
 *
 * WooCommerce's own template, plus the wallet rows this site's theme adds. It
 * exists because neither of the other two options worked:
 *
 *   - WooCommerce's template alone loses "Part wallet, part card". That control is
 *     not the theme's decoration; it is how a customer splits a payment between
 *     their wallet balance and a card, and the code that *processes* the split is
 *     still running — `nera_wallet_clamp_contribution` and
 *     `nera_wallet_checkout_commit_contribution` are filters, not templates, and
 *     the section never removed them. Cutting the template cut the only way to
 *     reach a feature that still works.
 *
 *   - The theme's template alone loses the terms checkbox and the Place Order
 *     button. Its header says so outright: it "intentionally excludes
 *     terms/place-order markup because the custom checkout template renders a
 *     single unified footer CTA". The section uses WooCommerce's form-checkout,
 *     which expects this template to render them, so adopting the theme's copy
 *     produces a checkout that cannot be submitted.
 *
 * So: WooCommerce's structure, with the theme's three wallet parts opted into.
 * Every one is guarded — a site without this theme renders the stock checkout and
 * nothing is missing, because on that site the wallet feature does not exist
 * either.
 *
 * NOTHING IS DECLARED IN THIS FILE
 * WooCommerce includes a template as many times as it needs to — twice in one
 * request when a checkout is submitted and the order review is rebuilt. A
 * `function` here is a fatal on the second include, and the symptom is a
 * checkout that spins on "Processing your order" forever, because the fatal
 * breaks the AJAX response that would have dismissed the overlay. The helper
 * lives on Nera_SAW_Standalone_Chrome instead.
 *
 * @package Nera_Strikeawin
 * @var WC_Payment_Gateway[] $available_gateways
 * @var string               $order_button_text
 */

defined( 'ABSPATH' ) || exit;

if ( ! wp_doing_ajax() ) {
	do_action( 'woocommerce_review_order_before_payment' );
}
?>
<div id="payment" class="woocommerce-checkout-payment" role="radiogroup" aria-label="<?php esc_attr_e( 'Payment Methods', 'nera-strikeawin' ); ?>">
	<?php if ( WC()->cart && WC()->cart->needs_payment() ) : ?>
		<ul class="wc_payment_methods payment_methods methods">
			<?php
			// A full-wallet row that stays visible while it cannot be selected, so the
			// customer can see why rather than wonder where it went.
			Nera_SAW_Standalone_Chrome::theme_part( 'template-parts/checkout/wallet-payment-disabled' );

			if ( ! empty( $available_gateways ) ) {
				foreach ( $available_gateways as $gateway ) {
					// Skip the live wallet row when the disabled one above is already
					// standing in for it, or the list shows the same method twice.
					if (
						'wallet' === $gateway->id
						&& function_exists( 'nera_wallet_show_disabled_full_wallet' )
						&& nera_wallet_show_disabled_full_wallet()
					) {
						continue;
					}
					wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
				}
			} else {
				echo '<li>';
				wc_print_notice(
					apply_filters( // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
						'woocommerce_no_available_payment_methods_message',
						WC()->customer->get_billing_country()
							? esc_html__( 'Sorry, it seems that there are no available payment methods. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' )
							: esc_html__( 'Please fill in your details above to see available payment methods.', 'woocommerce' )
					),
					'notice'
				);
				echo '</li>';
			}

			// "Part wallet, part card". Inside the list so it survives the AJAX
			// fragment replacement that rebuilds this block on every change.
			Nera_SAW_Standalone_Chrome::theme_part( 'template-parts/checkout/wallet-partial-payment', array( 'slot' => 'option' ) );
			?>
		</ul>
	<?php endif; ?>

	<?php Nera_SAW_Standalone_Chrome::theme_part( 'template-parts/checkout/wallet-partial-payment', array( 'slot' => 'notice' ) ); ?>

	<div class="form-row place-order">
		<noscript>
			<?php
			/* translators: 1: opening emphasis tag, 2: closing emphasis tag */
			printf( esc_html__( 'Since your browser does not support JavaScript, or it is disabled, please ensure you click the %1$sUpdate Totals%2$s button before placing your order. You may be charged more than the amount stated above if you fail to do so.', 'woocommerce' ), '<em>', '</em>' );
			?>
			<br/><button type="submit" class="button alt" name="woocommerce_checkout_update_totals" value="<?php esc_attr_e( 'Update totals', 'woocommerce' ); ?>"><?php esc_html_e( 'Update totals', 'woocommerce' ); ?></button>
		</noscript>

		<?php wc_get_template( 'checkout/terms.php' ); ?>

		<?php do_action( 'woocommerce_review_order_before_submit' ); ?>

		<?php
		echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WooCommerce.Commenting.CommentHooks.MissingHookComment
			'woocommerce_order_button_html',
			'<button type="submit" class="button alt" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>'
		);
		?>

		<?php do_action( 'woocommerce_review_order_after_submit' ); ?>

		<?php wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' ); ?>
	</div>
</div>
<?php
if ( ! wp_doing_ajax() ) {
	do_action( 'woocommerce_review_order_after_payment' );
}
