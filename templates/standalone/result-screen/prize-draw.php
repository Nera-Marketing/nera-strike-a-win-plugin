<?php
/**
 * Standalone section — "you're in the draw" overlay.
 *
 * A clone of the mu-plugin's `prize-draw-good-luck.php`, in the section's own
 * palette and type. See `Nera_SAW_Standalone_Result_Screen` for why this is a clone
 * rather than a template override.
 *
 * The wording comes from the same ACF options fields the mu-plugin reads, so an
 * administrator edits one copy. `lty-rs-overlay` and `data-lty-rs-dismiss` are kept
 * because the mu-plugin's script binds to them — that is what closes this, handles
 * Escape and moves focus.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * Override by copying to `nera-strikeawin/result-screen/prize-draw.php` in a theme.
 *
 * @package Nera_Strikeawin
 *
 * @var WC_Order       $saw_order
 * @var WC_Product|null $saw_product
 */

defined( 'ABSPATH' ) || exit;

$saw_heading   = Nera_SAW_Standalone_Result_Screen::copy( 'lty_rs_draw_heading', __( "You're in the draw!", 'nera-strikeawin' ) );
$saw_subtext   = Nera_SAW_Standalone_Result_Screen::copy( 'lty_rs_draw_subtext', __( 'Your entry is confirmed — fingers crossed!', 'nera-strikeawin' ) );
$saw_good_luck = Nera_SAW_Standalone_Result_Screen::copy( 'lty_rs_draw_good_luck', __( 'Good luck!', 'nera-strikeawin' ) );
$saw_button    = Nera_SAW_Standalone_Result_Screen::copy( 'lty_rs_draw_button', __( 'Got it!', 'nera-strikeawin' ) );
$saw_draw_date = Nera_SAW_Standalone_Result_Screen::draw_date( $saw_product );
?>
<div class="lty-rs-overlay saw-result" role="dialog" aria-modal="true" aria-labelledby="saw-result-heading">
	<div class="saw-result__card">

		<span class="saw-result__mark saw-result__mark--draw" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
		</span>

		<h2 id="saw-result-heading" class="saw-result__heading"><?php echo esc_html( $saw_heading ); ?></h2>

		<p class="saw-result__text"><?php echo esc_html( $saw_subtext ); ?></p>

		<?php if ( $saw_draw_date ) : ?>
			<p class="saw-result__badge">
				<?php
				printf(
					/* translators: %s: formatted draw date */
					esc_html__( 'Draw: %s', 'nera-strikeawin' ),
					esc_html( $saw_draw_date )
				);
				?>
			</p>
		<?php endif; ?>

		<p class="saw-result__cheer"><?php echo esc_html( $saw_good_luck ); ?></p>

		<button type="button" class="saw-result__btn" data-lty-rs-dismiss>
			<?php echo esc_html( $saw_button ); ?>
		</button>

	</div>
</div>
