<?php
/**
 * Standalone section — the run.
 *
 * The page the player lands on after paying. The chrome is the competition
 * page's own — same hero, same quiz-spec panel, built from the same
 * Nera_SAW_Competition_Spec — so a player sees the prize and the promise they
 * already read before paying, not a bare, unstyled shell. Two sections from
 * that page are deliberately left out: the tier/quantity buy form (an entry is
 * already bought by the time a player is here) and the stage-map preview (this
 * screen is about to show the real thing, not a preview of it).
 *
 * What replaces them is a Start panel: one card per tier the player has a run
 * balance on. The run engine, the REST API and the Vue app underneath are the
 * ones mix mode already uses — `Nera_SAW_Frontend::enqueue_app()` and
 * `::start_launcher_script()` are shared, not reimplemented, so a Start click
 * mounts the same token-gated quiz (ADR 0007: no URL starts a run).
 *
 * WHY THE RUN IS THE ONE SCREEN THAT NEEDS JAVASCRIPT
 * --------------------------------------------------
 * Every other screen in this section is server-rendered and works with scripting
 * off, deliberately. The run cannot be: a question is on a clock, an answer is
 * scored without a page load, and the next question has to arrive before the
 * player's attention does. A form round-trip per question would change what is
 * being sold.
 *
 * The server still owns everything that matters. Deadlines are stamped and scored
 * server-side, the answer key never reaches the browser, and tickets are minted at
 * finalize — the app is a display for a run the server is running.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * Override by copying to `nera-strikeawin/play.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$saw_competition_id = isset( $_GET[ Nera_SAW_Play_Page::QV_COMPETITION ] ) ? absint( $_GET[ Nera_SAW_Play_Page::QV_COMPETITION ] ) : 0;
$saw_spec            = $saw_competition_id ? Nera_SAW_Competition_Spec::get( $saw_competition_id ) : null;

// No competition named (or it no longer exists): fall back to the shortcode's
// own hub view rather than a screen with nothing on it. Reached directly, or
// by a stale link, not by the normal after-purchase path.
if ( ! $saw_spec ) {
	Nera_SAW_Router::part(
		'header.php',
		array(
			'saw_title' => get_the_title(),
			'saw_inner' => true,
		)
	);
	?>
	<div class="saw-screen saw-screen--narrow">
		<div class="saw-run-frame">
			<?php
			while ( have_posts() ) :
				the_post();
				the_content();
			endwhile;
			?>
		</div>
	</div>
	<?php
	Nera_SAW_Router::part( 'footer.php', array( 'saw_bare' => true ) );
	return;
}

$saw_date_fmt = get_option( 'date_format' ) . ', ' . get_option( 'time_format' );
$saw_sub      = $saw_spec['closes_timestamp']
	? sprintf(
		/* translators: %s: closing date and time */
		__( 'Closes %s', 'nera-strikeawin' ),
		Nera_SAW_Date::localized( $saw_spec['closes_timestamp'], $saw_date_fmt )
	)
	: '';

Nera_SAW_Router::part(
	'header.php',
	array(
		'saw_title' => $saw_spec['name'],
		'saw_inner' => true,
	)
);

Nera_SAW_Router::part(
	'screen-head.php',
	array(
		'saw_screen_title' => $saw_spec['name'],
		'saw_screen_sub'   => $saw_sub,
	)
);
?>

<div class="saw-screen saw-screen--narrow">
<article class="saw-path">

	<?php Nera_SAW_Router::part( 'parts/hero.php', array( 'saw_spec' => $saw_spec ) ); ?>

	<div class="saw-connector" aria-hidden="true"></div>

	<?php Nera_SAW_Router::part( 'parts/quiz-spec.php', array( 'saw_spec' => $saw_spec ) ); ?>

	<div class="saw-connector" aria-hidden="true"></div>

	<?php // --- start, or sign in first --------------------------------------- ?>
	<div class="saw-hub--competition">

	<?php if ( ! is_user_logged_in() ) : ?>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$saw_current_url = home_url( esc_url_raw( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' ) ) );
		$saw_account_url = class_exists( 'Nera_SAW_Standalone_Pages' ) ? Nera_SAW_Standalone_Pages::url( 'my-account' ) : '';
		$saw_login_url   = $saw_account_url
			? add_query_arg( Nera_SAW_Standalone_Account::RETURN_PARAM, rawurlencode( $saw_current_url ), $saw_account_url )
			: wp_login_url( $saw_current_url );
		?>
		<section class="saw-empty">
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: login URL */
						__( 'Please <a href="%s">log in</a> to use your runs and earn lottery tickets.', 'nera-strikeawin' ),
						esc_url( $saw_login_url )
					)
				);
				?>
			</p>
		</section>

	<?php else : ?>

		<?php
		Nera_SAW_Frontend::enqueue_app( $saw_competition_id );

		$saw_user_id   = get_current_user_id();
		$saw_balances  = Nera_SAW_Run_Grants::balance( $saw_user_id, $saw_competition_id );
		$saw_config    = Nera_SAW_Competition_Config::get( $saw_competition_id );
		$saw_has_runs  = false;

		foreach ( $saw_balances as $saw_bal ) {
			if ( (int) $saw_bal > 0 ) {
				$saw_has_runs = true;
				break;
			}
		}
		?>

		<section class="saw-buy" aria-labelledby="saw-start-title">
			<p class="saw-buy__eyebrow" id="saw-start-title">
				<?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_tier_heading' ) ); ?>
			</p>

			<ul class="saw-tiers">
				<?php foreach ( $saw_spec['tiers'] as $saw_tier ) : ?>
					<?php
					$saw_key     = (string) $saw_tier['key'];
					$saw_runs    = isset( $saw_balances[ $saw_key ] ) ? (int) $saw_balances[ $saw_key ] : 0;
					$saw_offered = Nera_SAW_Competition_Config::tier_offered( $saw_config, $saw_competition_id, $saw_key );
					?>
					<li>
						<div class="saw-tier saw-tier--start">
							<span class="saw-tier__body">
								<span class="saw-tier__label"><?php echo esc_html( $saw_tier['label'] ); ?></span>
								<span class="saw-tier__price">
									<?php
									echo esc_html(
										$saw_runs > 0
											? Nera_SAW_Frontend::runs_label( $saw_runs )
											: __( 'No runs to play', 'nera-strikeawin' )
									);
									?>
								</span>
								<span class="saw-tier__perfect">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: tickets */
											_n( 'Perfect run: %s ticket', 'Perfect run: %s tickets', $saw_tier['perfect_run'], 'nera-strikeawin' ),
											number_format_i18n( $saw_tier['perfect_run'] )
										)
									);
									?>
								</span>
							</span>

							<?php if ( $saw_runs > 0 ) : ?>
								<?php $saw_token = wp_create_nonce( Nera_SAW_Rest::start_token_action( $saw_competition_id, $saw_key ) ); ?>
								<button type="button" class="saw-cta saw-start-run"
									data-competition="<?php echo esc_attr( (string) $saw_competition_id ); ?>"
									data-tier="<?php echo esc_attr( $saw_key ); ?>"
									data-nonce="<?php echo esc_attr( $saw_token ); ?>">
									<?php esc_html_e( 'Play quiz', 'nera-strikeawin' ); ?>
								</button>
							<?php elseif ( $saw_offered ) : ?>
								<?php
								$saw_buy_url = add_query_arg(
									array(
										'add-to-cart'                  => $saw_competition_id,
										Nera_SAW_Cart_Entry::CART_KEY => $saw_key,
										'saw_basket'                   => 1,
									),
									function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : get_permalink( $saw_competition_id )
								);
								?>
								<a class="saw-cta saw-cta--ghost" href="<?php echo esc_url( $saw_buy_url ); ?>">
									<?php esc_html_e( 'Buy a run', 'nera-strikeawin' ); ?>
								</a>
							<?php else : ?>
								<span class="saw-cta saw-cta--disabled"><?php esc_html_e( 'Unavailable', 'nera-strikeawin' ); ?></span>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( ! $saw_has_runs ) : ?>
				<p class="saw-buy__risk">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: competition page URL */
							__( 'No runs left for this competition. <a href="%s">Buy an entry</a> to play.', 'nera-strikeawin' ),
							esc_url( get_permalink( $saw_competition_id ) )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</section>

	<?php endif; ?>

	</div>

	<?php if ( is_user_logged_in() ) : ?>
		<?php
		/*
		 * Deliberately OUTSIDE .saw-hub--competition: the launcher hides that whole
		 * wrapper on Start (see start_launcher_script()), and a mount nested inside
		 * the thing being hidden would be hidden right along with it — invisible
		 * even after its own `hidden` attribute is cleared, because a display:none
		 * ancestor wins over a visible descendant. This cost a real click a real
		 * silent no-op once already.
		 */
		?>
		<div id="saw-quiz" class="saw-quiz-mount" hidden data-show-feedback="<?php echo esc_attr( Nera_SAW_Constants::quiz_feedback_enabled() ? '1' : '0' ); ?>"></div>
		<?php echo Nera_SAW_Frontend::start_launcher_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup, no user input. ?>
		<?php
		/*
		 * Arriving from the competition page's Play button: press the matching
		 * Start button ourselves, so the player goes straight to the language
		 * choice instead of seeing this overview a second time. It clicks the real
		 * button, so the server-issued token still does the starting (ADR 0007) —
		 * the URL only says which tier, never that a run may begin. With no runs at
		 * that tier there is no button to press and the overview simply shows.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saw_autostart = ! empty( $_GET['saw_autostart'] ) && isset( $_GET[ Nera_SAW_Play_Page::QV_TIER ] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saw_auto_tier = $saw_autostart ? sanitize_key( wp_unslash( $_GET[ Nera_SAW_Play_Page::QV_TIER ] ) ) : '';
		// Which button to press: a tier card, or one of the interrupted-run popup's
		// two (arriving from the competition page's popup — see parts/resume-popup.php).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saw_auto_mode = ! empty( $_GET['saw_leave'] ) ? 'leave' : ( ! empty( $_GET['saw_resume'] ) ? 'resume' : 'tier' );
		?>
		<?php
		// A run held for Resume: offered before anything else on the screen.
		Nera_SAW_Router::part(
			'parts/resume-popup.php',
			array(
				'saw_on_play'           => true,
				'saw_focus_competition' => $saw_competition_id,
			)
		);
		?>
		<?php if ( '' !== $saw_auto_tier ) : ?>
			<style>.saw-hub--competition{visibility:hidden}.saw-resume-pop{display:none}</style>
			<script>
			( function () {
				var tier = <?php echo wp_json_encode( $saw_auto_tier ); ?>;
				var mode = <?php echo wp_json_encode( $saw_auto_mode ); ?>;
				var wanted = function ( b ) {
					var isResume = b.hasAttribute( 'data-saw-resume' );
					var isLeave = b.hasAttribute( 'data-saw-leave' );
					if ( 'leave' === mode ) { return isLeave; }
					if ( 'resume' === mode ) { return isResume; }
					return ! isResume && ! isLeave;
				};
				var run = function () {
					var btns = document.querySelectorAll( '.saw-start-run' );
					var hit = null;
					for ( var i = 0; i < btns.length; i++ ) {
						if ( wanted( btns[ i ] ) && btns[ i ].getAttribute( 'data-tier' ) === tier ) { hit = btns[ i ]; break; }
					}
					if ( ! hit ) {
						// Nothing to launch: show the overview (and any popup) rather than
						// a blank panel.
						var hub = document.querySelector( '.saw-hub--competition' );
						if ( hub ) { hub.style.visibility = 'visible'; }
						var pop = document.querySelector( '.saw-resume-pop' );
						if ( pop ) { pop.style.display = ''; }
						return;
					}
					if ( window.history && window.history.replaceState ) {
						var u = new URL( window.location.href );
						u.searchParams.delete( 'saw_autostart' );
						u.searchParams.delete( 'saw_resume' );
						u.searchParams.delete( 'saw_leave' );
						window.history.replaceState( null, '', u.toString() );
					}
					hit.click();
				};
				if ( 'loading' === document.readyState ) {
					document.addEventListener( 'DOMContentLoaded', run );
				} else {
					run();
				}
			} )();
			</script>
		<?php endif; ?>
	<?php endif; ?>

	<div class="saw-connector" aria-hidden="true"></div>

	<?php Nera_SAW_Router::part( 'parts/result-teaser.php' ); ?>

</article>
</div>

<?php
Nera_SAW_Router::part( 'footer.php', array( 'saw_bare' => true ) );
