<?php
/**
 * Standalone section — "thanks for entering" overlay, for an instant win that did
 * not land.
 *
 * A clone of the mu-plugin's `instant-win-no-win.php` in the section's palette. See
 * `Nera_SAW_Standalone_Result_Screen` for why this is a clone rather than an
 * override, and for why the third screen is not cloned.
 *
 * The browse link is sent to the section's own competitions list. The mu-plugin
 * points it at the shop, which is the main site — the right answer there, the wrong
 * one from inside a section the player has not left.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * Override by copying to `nera-strikeawin/result-screen/instant-win-no-win.php`.
 *
 * @package Nera_Strikeawin
 *
 * @var WC_Order        $saw_order
 * @var WC_Product|null $saw_product
 */

defined( 'ABSPATH' ) || exit;

$saw_heading = Nera_SAW_Standalone_Result_Screen::copy( 'lty_rs_no_win_heading', __( 'Thanks for entering!', 'nera-strikeawin' ) );
$saw_message = Nera_SAW_Standalone_Result_Screen::copy( 'lty_rs_no_win_message', __( 'Not this time — but every entry brings you closer. There are always more competitions to enter!', 'nera-strikeawin' ) );
$saw_button  = Nera_SAW_Standalone_Result_Screen::copy( 'lty_rs_no_win_button', __( 'Browse more competitions', 'nera-strikeawin' ) );
$saw_browse  = Nera_SAW_Standalone_Pages::url( 'competitions' );
?>
<div class="lty-rs-overlay saw-result" role="dialog" aria-modal="true" aria-labelledby="saw-result-heading">
	<div class="saw-result__card">

		<button type="button" class="saw-result__close" data-lty-rs-dismiss aria-label="<?php esc_attr_e( 'Close', 'nera-strikeawin' ); ?>">
			<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
		</button>

		<span class="saw-result__mark saw-result__mark--none" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4 4 0 0 1 7 0M9 9.5h.01M15 9.5h.01"/></svg>
		</span>

		<h2 id="saw-result-heading" class="saw-result__heading"><?php echo esc_html( $saw_heading ); ?></h2>

		<p class="saw-result__text"><?php echo esc_html( $saw_message ); ?></p>

		<?php if ( $saw_browse ) : ?>
			<a href="<?php echo esc_url( $saw_browse ); ?>" class="saw-result__btn" data-lty-rs-dismiss>
				<?php echo esc_html( $saw_button ); ?>
			</a>
		<?php else : ?>
			<button type="button" class="saw-result__btn" data-lty-rs-dismiss>
				<?php esc_html_e( 'Close', 'nera-strikeawin' ); ?>
			</button>
		<?php endif; ?>

	</div>
</div>
