<?php
/**
 * Standalone section — "Resume your run?" popup.
 *
 * Shown on the play page and the competition page when the player has a run that
 * was interrupted and is being held for them (ADR 0030). The server decides that —
 * this file only asks Nera_SAW_Run::pending_for_user() and renders what it gets.
 * The browser's localStorage is never consulted: it would not follow the player to
 * another device, and it cannot be trusted with a paid run.
 *
 * There is deliberately no "not now". The run is already paid for and its clock is
 * frozen, so there is nothing to postpone: Resume carries on, End run finishes it
 * (earned tickets are minted, the rest of the reserved stock goes back, and it can
 * no longer be restored). Doing neither leaves it held until the window closes.
 *
 * Args:
 *   saw_on_play     bool — rendered on the play page, where the quiz launcher is
 *                   loaded and the buttons can start it in place. Elsewhere they
 *                   are links into the play page, which starts it on arrival.
 *   saw_focus_competition int — prefer this competition's held run when several.
 *
 * Override by copying to `nera-strikeawin/parts/resume-popup.php` in a theme.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_on_play = ! empty( $saw_on_play );
$saw_focus   = isset( $saw_focus_competition ) ? (int) $saw_focus_competition : 0;
$saw_held    = is_user_logged_in() ? Nera_SAW_Run::pending_for_user( get_current_user_id() ) : array();

if ( empty( $saw_held ) ) {
	return;
}

$saw_run = $saw_held[0];
foreach ( $saw_held as $saw_candidate ) {
	if ( $saw_focus && (int) $saw_candidate->competition_id === $saw_focus ) {
		$saw_run = $saw_candidate;
		break;
	}
}

$saw_pop_cid  = (int) $saw_run->competition_id;
$saw_pop_tier = (string) $saw_run->tier_key;
$saw_pop_spec = Nera_SAW_Competition_Spec::get( $saw_pop_cid );

$saw_pop_tier_label = $saw_pop_tier;
if ( $saw_pop_spec ) {
	foreach ( $saw_pop_spec['tiers'] as $saw_pop_t ) {
		if ( (string) $saw_pop_t['key'] === $saw_pop_tier ) {
			$saw_pop_tier_label = (string) $saw_pop_t['label'];
			break;
		}
	}
}

$saw_pop_name  = $saw_pop_spec ? (string) $saw_pop_spec['name'] : get_the_title( $saw_pop_cid );
$saw_pop_until = wp_date( get_option( 'time_format' ), (int) $saw_run->resume_until );

if ( $saw_on_play ) {
	$saw_pop_token = wp_create_nonce( Nera_SAW_Rest::start_token_action( $saw_pop_cid, $saw_pop_tier ) );
} else {
	$saw_pop_base   = nera_saw_get_play_url( $saw_pop_cid, $saw_pop_tier );
	$saw_pop_resume = add_query_arg( array( 'saw_autostart' => '1', 'saw_resume' => '1' ), $saw_pop_base );
	$saw_pop_leave  = add_query_arg( array( 'saw_autostart' => '1', 'saw_leave' => '1' ), $saw_pop_base );
}
?>
<div class="saw-resume-pop" role="dialog" aria-modal="true" aria-labelledby="saw-resume-title" aria-describedby="saw-resume-body">
	<div class="saw-resume-pop__card">
		<h2 class="saw-resume-pop__title" id="saw-resume-title"><?php esc_html_e( 'Resume your run?', 'nera-strikeawin' ); ?></h2>

		<p class="saw-resume-pop__body" id="saw-resume-body">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: competition name, 2: tier name, 3: time of day */
					__( 'Your %2$s run for %1$s was interrupted. Pick up on the question you were on, with the time you had left. This is held for you until %3$s.', 'nera-strikeawin' ),
					$saw_pop_name,
					$saw_pop_tier_label,
					$saw_pop_until
				)
			);
			?>
		</p>

		<div class="saw-resume-pop__actions">
			<?php if ( $saw_on_play ) : ?>
				<button type="button" class="saw-cta saw-start-run" data-saw-resume
					data-competition="<?php echo esc_attr( (string) $saw_pop_cid ); ?>"
					data-tier="<?php echo esc_attr( $saw_pop_tier ); ?>"
					data-nonce="<?php echo esc_attr( $saw_pop_token ); ?>">
					<?php esc_html_e( 'Resume', 'nera-strikeawin' ); ?>
				</button>
				<button type="button" class="saw-cta saw-cta--ghost saw-start-run" data-saw-leave
					data-competition="<?php echo esc_attr( (string) $saw_pop_cid ); ?>"
					data-tier="<?php echo esc_attr( $saw_pop_tier ); ?>"
					data-nonce="<?php echo esc_attr( $saw_pop_token ); ?>">
					<?php esc_html_e( 'End run and see my results', 'nera-strikeawin' ); ?>
				</button>
			<?php else : ?>
				<a class="saw-cta" href="<?php echo esc_url( $saw_pop_resume ); ?>"><?php esc_html_e( 'Resume', 'nera-strikeawin' ); ?></a>
				<a class="saw-cta saw-cta--ghost" href="<?php echo esc_url( $saw_pop_leave ); ?>"><?php esc_html_e( 'End run and see my results', 'nera-strikeawin' ); ?></a>
			<?php endif; ?>
		</div>

		<p class="saw-resume-pop__note"><?php esc_html_e( 'Ending the run keeps any tickets you have already won. It cannot be undone.', 'nera-strikeawin' ); ?></p>
	</div>
</div>
<script>
( function () {
	var pop = document.querySelector( '.saw-resume-pop' );
	if ( ! pop ) { return; }
	var first = pop.querySelector( '.saw-cta' );
	if ( first ) { first.focus(); }
	// On the play page the buttons start the quiz in place (the launcher listens on
	// the document); the popup only has to get out of the way.
	pop.addEventListener( 'click', function ( e ) {
		if ( e.target.closest && e.target.closest( '.saw-start-run' ) ) { pop.hidden = true; }
	} );
} )();
</script>
