<?php
/**
 * Standalone section — Before you pay.
 *
 * The screen that replaces the basket. It shows the one entry being bought, the
 * spec the player is agreeing to, and one consent check, then hands over to
 * WooCommerce checkout.
 *
 * WHY THE CHECKBOXES ARE `required` AND NOT JAVASCRIPT
 * ---------------------------------------------------
 * The browser refuses to submit the form until it is ticked, with no script
 * involved. A JS gate on a screen like this fails open the moment a script errors
 * earlier on the page — and the thing it is gating is the player's confirmation
 * that a run can earn nothing. Native validation cannot fail open.
 *
 * The money is still taken by WooCommerce. Tickets are minted from order lines by
 * Nera_SAW_Ticket_Award, and the grant ledger reads those same lines, so an entry
 * that skipped the order would be an entry that can never pay out.
 *
 * Override by copying to `nera-strikeawin/before-you-pay.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_entry   = Nera_SAW_Standalone_Basket::current_entry();
$saw_heading = Nera_SAW_Standalone_Fields::text( 'saw_pp_heading' );

Nera_SAW_Router::part(
	'header.php',
	array(
		'saw_title' => get_the_title(),
		'saw_inner' => true,
	)
);

// Back goes to the competition being bought, which the basket always knows; a visitor
// with an empty basket gets the list.
$saw_back = ( $saw_entry && ! empty( $saw_entry['spec']['id'] ) ) ? Nera_SAW_Router::competition_url( '', $saw_entry['spec']['id'] ) : '';

Nera_SAW_Router::part(
	'screen-head.php',
	array(
		'saw_screen_title' => $saw_heading,
		'saw_back_url'     => $saw_back,
	)
);
?>

<div class="saw-screen saw-screen--narrow">
	<div class="saw-stack">
		<?php
		Nera_SAW_Router::part(
			'parts/paysheet.php',
			array(
				'saw_entry' => $saw_entry,
				'saw_sheet' => false,
			)
		);
		?>
	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
