<?php
/**
 * My Account — "Responsible play" (client findings #2/#45).
 *
 * One page consolidating three sibling plugins that each own a slice of
 * "play responsibly" (per the client call during planning, confirmed with
 * the user: gather them under one row rather than leaving three separate
 * ones) — this plugin reads/calls each one's own, already-built surface;
 * none of their code is touched:
 *
 * - nera-self-exclusion-plugin: Nera_SE_Account::render_endpoint() — the
 *   SAME pause/suspend/close panel already reachable at the account-status
 *   endpoint today. Called directly rather than via its own
 *   `woocommerce_account_account-status_endpoint` action, so nothing else
 *   hooked onto that specific action fires twice.
 * - nera-spending-amount-limit-plugin: Nera_SL_Account::render_card() — the
 *   spending-limit card. That plugin only ever hangs this off
 *   `woocommerce_edit_account_form_start` (the Account details screen);
 *   called directly here for the same reason as above — `do_action()`-ing
 *   that whole hook would also fire whatever ELSE listens on it.
 * - nera-responsible-play-plugin: a plain link to its own help page
 *   (Nera_RP_Settings::help_page_id()), the same page its "Need support?"
 *   row and footer strip already point at elsewhere in this section.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

if ( ! get_current_user_id() ) {
	return;
}
?>

<div class="saw-responsible-play">

	<?php if ( class_exists( 'Nera_SE_Account' ) && method_exists( 'Nera_SE_Account', 'render_endpoint' ) ) : ?>
		<section class="saw-responsible-play__section saw-responsible-play__section--self-exclusion">
			<?php Nera_SE_Account::render_endpoint(); ?>
		</section>
	<?php endif; ?>

	<?php if ( class_exists( 'Nera_SL_Account' ) && method_exists( 'Nera_SL_Account', 'render_card' ) ) : ?>
		<section class="saw-responsible-play__section saw-responsible-play__section--spending-limit">
			<?php Nera_SL_Account::render_card(); ?>
		</section>
	<?php endif; ?>

	<?php
	$saw_help_url = '';
	if ( class_exists( 'Nera_RP_Settings' ) && method_exists( 'Nera_RP_Settings', 'help_page_id' ) ) {
		$saw_help_page_id = Nera_RP_Settings::help_page_id();
		if ( $saw_help_page_id > 0 && 'publish' === get_post_status( $saw_help_page_id ) ) {
			$saw_help_url = get_permalink( $saw_help_page_id );
		}
	}
	?>
	<?php if ( $saw_help_url ) : ?>
		<section class="saw-responsible-play__section saw-responsible-play__section--support">
			<p class="saw-responsible-play__support-label">
				<?php esc_html_e( 'Need support with your play?', 'nera-responsible-play-plugin' ); ?>
			</p>
			<a class="saw-responsible-play__support-link" href="<?php echo esc_url( $saw_help_url ); ?>">
				<?php esc_html_e( 'Help & support', 'nera-responsible-play-plugin' ); ?>
			</a>
		</section>
	<?php endif; ?>

</div>
