<?php
/**
 * Standalone section — my account, wearing the section's skin.
 *
 * This is WooCommerce's own account page, not a copy of it — same reasoning as
 * checkout.php. `[woocommerce_my_account]` renders login, register, the
 * dashboard, orders, a single order, and account details, all through
 * WooCommerce's own endpoints (`/my-account/orders/`, `/my-account/view-order/
 * 123/`, …), which resolve on this page the same way they do on the main
 * site's because WooCommerce registers them with EP_PAGES. is_account_page()
 * sees this page on its own, by detecting the shortcode in its content
 * (`wc_post_content_has_shortcode()`), so every plugin that already keys on
 * "is this the account page" — self-exclusion, spending limits, the age
 * gate's own registration fields — keeps working here without being told
 * about this page individually.
 *
 * What WooCommerce's own account page does not need, because it has nowhere
 * else to stay inside of, is handled by Nera_SAW_Standalone_Account: endpoint
 * links (`wc_get_account_endpoint_url()`) rebuilt to stay on this page instead
 * of the main site's, and the post-login/post-registration redirect, which
 * defaults to the main site's account URL with nothing in WooCommerce's own
 * login form to override it.
 *
 * A PAGE OF ITS OWN, NOT THE MAIN SITE'S
 * The main site's `/my-account/` is untouched. This is the section's own
 * account page — same WordPress users, same WooCommerce orders (D7); only the
 * address and the skin are separate.
 *
 * Override by copying to `nera-strikeawin/my-account.php` in a theme.
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
		'saw_screen_title' => Nera_SAW_Standalone_Fields::shell( 'saw_account_heading' ),
		'saw_back_url'     => Nera_SAW_Router::url(),
	)
);
?>

<div class="saw-screen saw-screen--narrow">
	<div class="saw-stack saw-woo saw-myaccount">
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
