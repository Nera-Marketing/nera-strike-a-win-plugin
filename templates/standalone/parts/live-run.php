<?php
/**
 * Standalone section: the player's run in progress, on the play page and the
 * competition page.
 *
 * The server decides what is shown (Nera_SAW_Run::live_state()), once, for every screen:
 *
 *   live    a run is in progress. With Resume allowed: "Continue your run".
 *           Without it: a line saying a run is in progress, and no button.
 *   held    interrupted, held for the configured window (Resume allowed only):
 *           "Continue your run", with the time it is held until.
 *   closed  the last run was interrupted and closed for an administrator. A notice,
 *           and nothing else changes: the player's other runs can still be played.
 *
 * Where there is something to act on (Continue, or the closed notice) it opens as a
 * modal over the page, so it cannot be missed. Closing it (x, Escape or the backdrop)
 * does different things by state:
 *
 *   held    the player has decided not to continue: closing ENDS the run, exactly as
 *           "End run and see my results" does (a deliberate leave: the tickets earned so
 *           far are kept, it cannot be restored), and the popup does not come back.
 *   live    the run is being played in another window: closing only dismisses it. The
 *           slim banner stays, with the same action.
 *   closed  OK / close dismisses it; it is not shown again in the same browser session.
 *
 * Ending the run on purpose (closing a held run's popup, or "End run and see my results")
 * asks first, with the same kind of card the quiz shows when leaving mid-run.
 *
 * Without scripting the modal is simply there, and its buttons are links.
 *
 * While a run is live or held the screen never offers a new one: the caller hides its
 * Play quiz and Buy a run controls when Nera_SAW_Run::blocks_new_run() is true.
 *
 * The buttons start the quiz in place on the play page (the launcher listens on the
 * document); elsewhere they are links into the play page, which starts it on arrival.
 *
 * Args:
 *   saw_competition_id int  The competition.
 *   saw_on_play        bool Rendered where the quiz launcher is loaded.
 *
 * Override by copying to `nera-strikeawin/parts/live-run.php` in a theme.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_lr_cid     = isset( $saw_competition_id ) ? (int) $saw_competition_id : 0;
$saw_lr_on_play = ! empty( $saw_on_play );
$saw_lr         = is_user_logged_in() ? Nera_SAW_Run::live_state( get_current_user_id(), $saw_lr_cid ) : array( 'state' => 'none', 'run' => null, 'until' => 0 );

if ( 'none' === $saw_lr['state'] ) {
	return;
}

$saw_lr_state = (string) $saw_lr['state'];
$saw_lr_run   = (int) $saw_lr['run']->id;
$saw_lr_allow = Nera_SAW_Mode::allows_resume();
$saw_lr_tier  = (string) $saw_lr['run']->tier_key;

// "Closed" and "live without Resume" have nothing to act on; "closed" still deserves a modal.
$saw_lr_modal = ( 'closed' === $saw_lr_state ) || $saw_lr_allow;

if ( 'closed' === $saw_lr_state ) {
	$saw_lr_title = __( 'Your last run was interrupted', 'nera-strikeawin' );
	$saw_lr_body  = __( 'It has been closed. Contact support and we will restore it. Your other runs are unaffected.', 'nera-strikeawin' );
} elseif ( ! $saw_lr_allow ) {
	$saw_lr_title = __( 'Run in progress', 'nera-strikeawin' );
	$saw_lr_body  = __( 'You have a run in progress. Finish it before you start another.', 'nera-strikeawin' );
} elseif ( 'held' === $saw_lr_state ) {
	$saw_lr_title = __( 'Your run was interrupted', 'nera-strikeawin' );
	$saw_lr_body  = sprintf(
		/* translators: %s: time of day */
		__( 'Pick up where you left off. It is held for you until %s.', 'nera-strikeawin' ),
		// `until` is the stored site-local wall clock read as UTC (the run table's own
		// convention), so it is printed in UTC to show that same wall clock.
		wp_date( 'ru' === Nera_SAW_Language::current() ? 'H:i' : get_option( 'time_format' ), (int) $saw_lr['until'], new DateTimeZone( 'UTC' ) )
	);
} else {
	$saw_lr_title = __( 'Run in progress', 'nera-strikeawin' );
	$saw_lr_body  = __( 'Pick up where you left off.', 'nera-strikeawin' );
}

$saw_lr_token = ( $saw_lr_allow && $saw_lr_on_play ) ? wp_create_nonce( Nera_SAW_Rest::start_token_action( $saw_lr_cid, $saw_lr_tier ) ) : '';
$saw_lr_base  = ( $saw_lr_allow && ! $saw_lr_on_play ) ? nera_saw_get_play_url( $saw_lr_cid, $saw_lr_tier ) : '';

/**
 * The two actions, as buttons that start the quiz in place or as links into the play page.
 *
 * @param string $kind  'continue' or 'end'.
 * @param string $class Extra classes.
 */
$saw_lr_action = static function ( $kind, $class ) use ( $saw_lr_on_play, $saw_lr_cid, $saw_lr_tier, $saw_lr_token, $saw_lr_base ) {
	$label = 'end' === $kind ? __( 'End run and see my results', 'nera-strikeawin' ) : __( 'Continue your run', 'nera-strikeawin' );
	if ( $saw_lr_on_play ) {
		printf(
			'<button type="button" class="%1$s saw-start-run" data-saw-%2$s data-competition="%3$d" data-tier="%4$s" data-nonce="%5$s">%6$s</button>',
			esc_attr( $class ),
			'end' === $kind ? 'leave' : 'resume',
			(int) $saw_lr_cid,
			esc_attr( $saw_lr_tier ),
			esc_attr( $saw_lr_token ),
			esc_html( $label )
		);
		return;
	}
	printf(
		'<a class="%1$s" href="%2$s">%3$s</a>',
		esc_attr( $class ),
		esc_url( add_query_arg( array( 'saw_autostart' => '1', 'end' === $kind ? 'saw_leave' : 'saw_resume' => '1' ), $saw_lr_base ) ),
		esc_html( $label )
	);
};
?>
<?php // The slim banner: always there, and what is left once the modal is closed. ?>
<section class="saw-live-run<?php echo 'closed' === $saw_lr_state ? ' saw-live-run--closed' : ''; ?>" role="status" data-saw-live-run="<?php echo esc_attr( (string) $saw_lr_run ); ?>">
	<div class="saw-live-run__text">
		<p class="saw-live-run__title"><?php echo esc_html( $saw_lr_title ); ?></p>
		<p class="saw-live-run__body"><?php echo esc_html( $saw_lr_body ); ?></p>
	</div>
	<?php if ( $saw_lr_allow && 'closed' !== $saw_lr_state ) : ?>
		<div class="saw-live-run__actions">
			<?php $saw_lr_action( 'continue', 'saw-cta saw-cta--compact' ); ?>
		</div>
	<?php endif; ?>
</section>

<?php if ( $saw_lr_modal ) : ?>
<div class="saw-live-modal<?php echo 'closed' === $saw_lr_state ? ' saw-live-modal--closed' : ''; ?>" data-saw-live-modal="<?php echo esc_attr( $saw_lr_state . '-' . $saw_lr_run ); ?>"<?php if ( 'held' === $saw_lr_state ) : ?> data-saw-end-url="<?php echo esc_url( rest_url( Nera_SAW_Rest::NS . '/run/' . $saw_lr_run . '/abandon' ) ); ?>" data-saw-end-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"<?php endif; ?> role="dialog" aria-modal="true" aria-labelledby="saw-live-modal-title" aria-describedby="saw-live-modal-body">
	<div class="saw-live-modal__backdrop" data-saw-live-close></div>
	<div class="saw-live-confirm" data-saw-live-confirm hidden role="alertdialog" aria-modal="true" aria-labelledby="saw-live-confirm-title" aria-describedby="saw-live-confirm-body">
		<div class="saw-live-confirm__backdrop" data-saw-live-confirm-stay></div>
		<div class="saw-live-confirm__panel">
			<h2 class="saw-live-confirm__title" id="saw-live-confirm-title"><?php esc_html_e( 'End this run?', 'nera-strikeawin' ); ?></h2>
			<p class="saw-live-confirm__body" id="saw-live-confirm-body"><?php esc_html_e( 'Ending the run finishes it now. Tickets you have already won are kept. This cannot be undone.', 'nera-strikeawin' ); ?></p>
			<div class="saw-live-confirm__actions">
				<button type="button" class="saw-live-confirm__stay" data-saw-live-confirm-stay><?php esc_html_e( 'Keep my run', 'nera-strikeawin' ); ?></button>
				<button type="button" class="saw-live-confirm__go" data-saw-live-confirm-go><?php esc_html_e( 'End run anyway', 'nera-strikeawin' ); ?></button>
			</div>
		</div>
	</div>
	<div class="saw-live-modal__card">
		<button type="button" class="saw-live-modal__x" data-saw-live-close aria-label="<?php esc_attr_e( 'Close', 'nera-strikeawin' ); ?>">&times;</button>
		<span class="saw-live-modal__icon" aria-hidden="true">
			<?php if ( 'closed' === $saw_lr_state ) : ?>
				<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v6"></path><path d="M12 16.5v.01"></path></svg>
			<?php else : ?>
				<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12a7 7 0 0 1 12-4.9L19 9"></path><path d="M19 4v5h-5"></path><path d="M19 12a7 7 0 0 1-12 4.9L5 15"></path><path d="M5 20v-5h5"></path></svg>
			<?php endif; ?>
		</span>
		<h2 class="saw-live-modal__title" id="saw-live-modal-title"><?php echo esc_html( $saw_lr_title ); ?></h2>
		<p class="saw-live-modal__body" id="saw-live-modal-body"><?php echo esc_html( $saw_lr_body ); ?></p>
		<?php if ( 'held' === $saw_lr_state ) : ?>
			<p class="saw-live-modal__note"><?php esc_html_e( 'If you close this window the run ends. Tickets you have already won are kept.', 'nera-strikeawin' ); ?></p>
		<?php endif; ?>
		<div class="saw-live-modal__actions">
			<?php if ( 'closed' === $saw_lr_state ) : ?>
				<button type="button" class="saw-cta" data-saw-live-close><?php esc_html_e( 'OK', 'nera-strikeawin' ); ?></button>
			<?php else : ?>
				<?php $saw_lr_action( 'continue', 'saw-cta' ); ?>
				<?php $saw_lr_action( 'end', 'saw-live-modal__end' ); ?>
			<?php endif; ?>
		</div>
	</div>
</div>
<script>
( function () {
	var modal = document.querySelector( '[data-saw-live-modal]' );
	if ( ! modal ) { return; }
	var key = 'saw_live_modal_' + modal.getAttribute( 'data-saw-live-modal' );
	var closedNotice = modal.classList.contains( 'saw-live-modal--closed' );
	var root = document.documentElement;

	function remember() {
		// A closed run is told once per browser session; a run to continue is offered on every visit.
		if ( ! closedNotice ) { return; }
		try { window.sessionStorage.setItem( key, '1' ); } catch ( e ) {}
	}
	function hide() {
		modal.hidden = true;
		root.classList.remove( 'saw-live-modal-open' );
	}
	function close() {
		remember();
		hide();
	}
	function show() {
		modal.hidden = false;
		root.classList.add( 'saw-live-modal-open' );
		var first = modal.querySelector( '.saw-cta' );
		if ( first ) { first.focus(); }
	}

	var endUrl = modal.getAttribute( 'data-saw-end-url' );
	var endNonce = modal.getAttribute( 'data-saw-end-nonce' );
	var ending = false;

	// Closing a held run's popup ends the run, as the player chose not to continue it.
	function endRun() {
		if ( ending ) { return; }
		ending = true;
		modal.classList.add( 'is-ending' );
		var card = modal.querySelector( '.saw-live-modal__card' );
		if ( card ) { card.setAttribute( 'aria-busy', 'true' ); }
		var done = function () { window.location.reload(); };
		var failed = function () { ending = false; modal.classList.remove( 'is-ending' ); if ( card ) { card.removeAttribute( 'aria-busy' ); } hide(); };
		try {
			window.fetch( endUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': endNonce, 'Content-Type': 'application/json' },
				body: '{}'
			} ).then( function ( r ) { return r.ok ? done() : failed(); } ).catch( failed );
		} catch ( e ) {
			failed();
		}
	}

	var seen = false;
	try { seen = closedNotice && '1' === window.sessionStorage.getItem( key ); } catch ( e ) {}
	if ( seen ) { hide(); } else { show(); }

	// The confirmation: one question, two answers. `pending` is what "End run anyway" does.
	var confirmEl = modal.querySelector( '[data-saw-live-confirm]' );
	var pending = null;
	function ask( onYes ) {
		pending = onYes;
		confirmEl.hidden = false;
		var stay = confirmEl.querySelector( '.saw-live-confirm__stay' );
		if ( stay ) { stay.focus(); }
	}
	function stay() {
		pending = null;
		confirmEl.hidden = true;
		var first = modal.querySelector( '.saw-live-modal__card .saw-cta' );
		if ( first ) { first.focus(); }
	}

	// A programmatic click (the play page pressing the button it was sent here to press,
	// after the player already confirmed on the page before) is let through untouched.
	modal.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( ! t.closest ) { return; }

		if ( t.closest( '[data-saw-live-confirm-stay]' ) ) { stay(); return; }
		if ( t.closest( '[data-saw-live-confirm-go]' ) ) {
			var go = pending;
			pending = null;
			confirmEl.hidden = true;
			if ( go ) { go(); }
			return;
		}

		if ( t.closest( '[data-saw-live-close]' ) ) {
			if ( endUrl ) { ask( endRun ); } else { close(); }
			return;
		}

		var end = t.closest( '[data-saw-leave], .saw-live-modal__end' );
		if ( end && e.isTrusted ) {
			e.preventDefault();
			e.stopPropagation();
			ask( function () {
				if ( 'A' === end.tagName ) {
					window.location.href = end.href;
					return;
				}
				// Press the same button again, this time as a click we let through.
				end.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
			} );
			return;
		}

		// Starting the quiz in place: the launcher takes it from here.
		if ( t.closest( '.saw-start-run' ) ) { hide(); }
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key || modal.hidden ) { return; }
		if ( ! confirmEl.hidden ) { stay(); return; }
		if ( endUrl ) { ask( endRun ); } else { close(); }
	} );
}() );
</script>
<?php endif; ?>
