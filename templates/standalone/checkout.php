<?php
/**
 * Standalone section — checkout, wearing the section's skin.
 *
 * This is WooCommerce's own checkout page, not a copy of it. The URL is the real
 * one, `is_checkout()` is true, and the page content — block or shortcode — is
 * rendered untouched. Only the document around it belongs to this plugin.
 *
 * A PAGE OF ITS OWN, NOT THE MAIN SITE'S
 * --------------------------------------
 * This is the section's checkout page, holding `[woocommerce_checkout]`. The main
 * site's `/checkout/` is untouched, because standalone adds screens and never
 * takes over a page the main site already owns.
 *
 * An earlier revision of this file argued the opposite -- that a second page would
 * be quietly broken because `is_checkout()` is false anywhere but the configured
 * checkout page. That was wrong on the facts: `is_checkout()` is filterable
 * (`woocommerce_is_checkout`) and also returns true for any page carrying the
 * shortcode, so gateways see a checkout here exactly as they do there.
 *
 * The design has no checkout artboard. Rather than invent one, this renders the
 * checkout WooCommerce and the active gateways produce, inside the section's
 * header, footer and type. That keeps the payment step recognisable to the
 * gateway's own support documentation while the player stays inside the section.
 *
 * Override by copying to `nera-strikeawin/checkout.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

Nera_SAW_Router::part(
	'header.php',
	array(
		'saw_title' => get_the_title(),
		'saw_inner' => true,
	)
);

Nera_SAW_Router::part(
	'screen-head.php',
	array(
		'saw_screen_title' => Nera_SAW_Standalone_Fields::shell( 'saw_checkout_heading' ),
		// Back goes to Before you pay, which is where the player came from and where
		// the entry can still be changed. The competitions list would lose it.
		'saw_back_url'     => Nera_SAW_Standalone_Basket::pre_payment_url(),
	)
);
?>

<div class="saw-screen saw-screen--narrow">
	<div class="saw-stack saw-woo">
		<?php
		while ( have_posts() ) :
			the_post();
			the_content();
		endwhile;
		?>
	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
