<?php
/**
 * Standalone section — the quick walkthrough.
 *
 * Four steps that explain a run before anyone pays for one: the shape of a run,
 * what answers are worth, a practice question, and what can go wrong.
 *
 * WHY IT IS FOUR URLs AND NOT A JAVASCRIPT CAROUSEL
 * ------------------------------------------------
 * The step lives in the query string, so every step is linkable, the browser's
 * back button walks back through them, and the whole thing works with scripting
 * off. The design draws it as a modal; a modal is a presentation, not a reason to
 * hold four screens of copy in memory.
 *
 * The practice question is answered the same way — the pick is a link, and the
 * result is worked out on the server. Nothing here needs to run in the browser.
 *
 * THE TIMER RING IS NOT A CLOCK
 * A real question's timer is owned by the server and has consequences. This one
 * is a picture of that control at rest, on a question where the screen says
 * plainly that nothing is at stake. A practice countdown that expires would
 * either do nothing, which teaches the wrong thing, or take something away in a
 * walkthrough, which would be worse.
 *
 * Override by copying to `nera-strikeawin/walkthrough.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_steps = 4;
$saw_step  = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$saw_step  = max( 1, min( $saw_steps, $saw_step ) );

$saw_page = Nera_SAW_Standalone_Pages::url( 'walkthrough' );
$saw_link = function ( $step, $extra = array() ) use ( $saw_page ) {
	$args = array_merge( array( 'step' => (int) $step ), $extra );
	return esc_url( add_query_arg( $args, $saw_page ) );
};

$saw_ramp = Nera_SAW_Competition_Spec::ramp();

Nera_SAW_Router::part(
	'header.php',
	array(
		'saw_title' => get_the_title(),
		'saw_inner' => true,
	)
);
?>

<div class="saw-wt">
	<div class="saw-wt__card" role="group"
		aria-label="<?php echo esc_attr( sprintf( /* translators: 1: step, 2: total */ __( 'Walkthrough, step %1$d of %2$d', 'nera-strikeawin' ), $saw_step, $saw_steps ) ); ?>">

		<a class="saw-wt__close" href="<?php echo esc_url( Nera_SAW_Router::url() ); ?>"
			aria-label="<?php esc_attr_e( 'Close the walkthrough', 'nera-strikeawin' ); ?>">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true" focusable="false">
				<path d="M18 6L6 18M6 6l12 12"></path>
			</svg>
		</a>

		<div class="saw-wt__body">

			<?php // ---------------------------------------------------- step 1 ?>
			<?php if ( 1 === $saw_step ) : ?>

				<p class="saw-wt__count"><?php echo esc_html( sprintf( /* translators: 1: step, 2: total */ __( '%1$d of %2$d', 'nera-strikeawin' ), 1, $saw_steps ) ); ?></p>
				<h1 class="saw-wt__title"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s1_title' ) ); ?></h1>
				<p class="saw-wt__lede"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s1_body' ) ); ?></p>

				<?php
				/*
				 * A fixed example, as the copy says. The colours come from the same ramp
				 * every competition uses, so the example looks like the real thing even
				 * though its shape is invented.
				 */
				$saw_example = array(
					array( 'colour' => $saw_ramp[0], 'count' => 3 ),
					array( 'colour' => $saw_ramp[1], 'count' => 3 ),
					array( 'colour' => $saw_ramp[2], 'count' => 3 ),
					array( 'colour' => end( $saw_ramp ), 'count' => 1 ),
				);
				$saw_i = 0;
				?>
				<div class="saw-wt__path" aria-hidden="true">
					<span class="saw-wt__rail"></span>
					<ul class="saw-wt__dots">
						<?php
						foreach ( $saw_example as $saw_band ) :
							for ( $saw_q = 0; $saw_q < $saw_band['count']; $saw_q++ ) :
								$saw_i++;
								$saw_last = ( count( $saw_example ) - 1 === array_search( $saw_band, $saw_example, true ) );
								?>
								<li class="saw-wt__dot<?php echo $saw_last ? ' saw-wt__dot--final' : ''; ?><?php echo 0 === $saw_i % 2 ? ' is-low' : ' is-high'; ?>"
									style="background:<?php echo esc_attr( $saw_band['colour'] ); ?>"></li>
								<?php
							endfor;
						endforeach;
						?>
					</ul>
				</div>

				<p class="saw-wt__note"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s1_note' ) ); ?></p>

			<?php // ---------------------------------------------------- step 2 ?>
			<?php elseif ( 2 === $saw_step ) : ?>

				<p class="saw-wt__count"><?php echo esc_html( sprintf( /* translators: 1: step, 2: total */ __( '%1$d of %2$d', 'nera-strikeawin' ), 2, $saw_steps ) ); ?></p>
				<h1 class="saw-wt__title"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s2_title' ) ); ?></h1>
				<p class="saw-wt__lede"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s2_body' ) ); ?></p>
				<p class="saw-wt__note"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s2_note' ) ); ?></p>

				<p class="saw-wt__eyebrow"><?php esc_html_e( 'Example ladder', 'nera-strikeawin' ); ?></p>

				<?php
				$saw_bands = array(
					array( 'label' => __( 'Easy', 'nera-strikeawin' ),   'colour' => $saw_ramp[0], 'count' => 3, 'value' => 1 ),
					array( 'label' => __( 'Medium', 'nera-strikeawin' ), 'colour' => $saw_ramp[1], 'count' => 3, 'value' => 2 ),
					array( 'label' => __( 'Hard', 'nera-strikeawin' ),   'colour' => $saw_ramp[2], 'count' => 3, 'value' => 3 ),
					array( 'label' => __( 'Expert', 'nera-strikeawin' ), 'colour' => end( $saw_ramp ), 'count' => 1, 'value' => 7 ),
				);
				?>
				<ul class="saw-wt__ladder">
					<?php foreach ( $saw_bands as $saw_band ) : ?>
						<li>
							<span class="saw-wt__band" style="background:<?php echo esc_attr( $saw_band['colour'] ); ?>"><?php echo esc_html( $saw_band['label'] ); ?></span>
							<span class="saw-wt__beads">
								<?php for ( $saw_q = 0; $saw_q < $saw_band['count']; $saw_q++ ) : ?>
									<span class="saw-wt__bead" style="border-color:<?php echo esc_attr( $saw_band['colour'] ); ?>;color:<?php echo esc_attr( $saw_band['colour'] ); ?>"><?php echo esc_html( number_format_i18n( $saw_band['value'] ) ); ?></span>
								<?php endfor; ?>
							</span>
							<span class="saw-wt__each">
								<?php
								echo esc_html(
									1 === $saw_band['count']
										? sprintf(
											/* translators: %s: tickets */
											_n( '%s ticket', '%s tickets', $saw_band['value'], 'nera-strikeawin' ),
											number_format_i18n( $saw_band['value'] )
										)
										: sprintf(
											/* translators: %s: tickets */
											__( '%s each', 'nera-strikeawin' ),
											number_format_i18n( $saw_band['value'] )
										)
								);
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>

				<p class="saw-wt__summary"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s2_summary' ) ); ?></p>

			<?php // ---------------------------------------------------- step 3 ?>
			<?php elseif ( 3 === $saw_step ) : ?>

				<?php
				$saw_options = array_values(
					array_filter(
						array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) Nera_SAW_Standalone_Fields::text( 'saw_wt_s3_options' ) ) )
					)
				);
				$saw_answer  = (int) Nera_SAW_Standalone_Fields::text( 'saw_wt_s3_answer' );
				$saw_pick    = isset( $_GET['pick'] ) ? absint( $_GET['pick'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$saw_picked  = ( $saw_pick >= 1 && $saw_pick <= count( $saw_options ) ) ? $saw_pick : 0;
				?>

				<div class="saw-wt__head3">
					<div>
						<p class="saw-wt__count"><?php echo esc_html( sprintf( /* translators: 1: step, 2: total */ __( '%1$d of %2$d', 'nera-strikeawin' ), 3, $saw_steps ) ); ?></p>
						<h1 class="saw-wt__title"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s3_title' ) ); ?></h1>
					</div>
					<?php
					// A picture of the control, not a working clock — see the file header.
					$saw_seconds = (int) Nera_SAW_Constants::clamp_timer( (int) Nera_SAW_Standalone_Fields::text( 'saw_wt_s3_seconds' ) );
					?>
					<span class="saw-wt__timer" role="img"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %d: seconds */ __( 'Example timer, %d seconds', 'nera-strikeawin' ), $saw_seconds ) ); ?>">
						<svg viewBox="0 0 56 56" aria-hidden="true" focusable="false">
							<circle cx="28" cy="28" r="25" fill="none" stroke="var(--saw-control)" stroke-width="4"></circle>
							<circle cx="28" cy="28" r="25" fill="none" stroke="var(--saw-primary)" stroke-width="4"
								stroke-linecap="round" stroke-dasharray="157" stroke-dashoffset="47"
								transform="rotate(-90 28 28)"></circle>
						</svg>
						<b><?php echo esc_html( number_format_i18n( $saw_seconds ) ); ?></b>
					</span>
				</div>

				<p class="saw-wt__note"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s3_note' ) ); ?></p>

				<p class="saw-wt__question"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s3_question' ) ); ?></p>

				<ul class="saw-wt__options">
					<?php foreach ( $saw_options as $saw_index => $saw_option ) : ?>
						<?php
						$saw_no    = $saw_index + 1;
						$saw_class = '';
						if ( $saw_picked ) {
							if ( $saw_no === $saw_answer ) {
								$saw_class = ' is-right';
							} elseif ( $saw_no === $saw_picked ) {
								$saw_class = ' is-wrong';
							}
						}
						?>
						<li>
							<?php if ( $saw_picked ) : ?>
								<span class="saw-wt__option<?php echo esc_attr( $saw_class ); ?>"><?php echo esc_html( $saw_option ); ?></span>
							<?php else : ?>
								<a class="saw-wt__option" href="<?php echo $saw_link( 3, array( 'pick' => $saw_no ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php echo esc_html( $saw_option ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ( $saw_picked ) : ?>
					<p class="saw-wt__verdict <?php echo $saw_picked === $saw_answer ? 'is-right' : 'is-wrong'; ?>">
						<?php
						echo esc_html(
							$saw_picked === $saw_answer
								? Nera_SAW_Standalone_Fields::text( 'saw_wt_s3_right' )
								: Nera_SAW_Standalone_Fields::text( 'saw_wt_s3_wrong' )
						);
						?>
					</p>
					<p class="saw-wt__note">
						<a href="<?php echo $saw_link( 3 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Try it again', 'nera-strikeawin' ); ?></a>
					</p>
				<?php endif; ?>

			<?php // ---------------------------------------------------- step 4 ?>
			<?php else : ?>

				<p class="saw-wt__count"><?php echo esc_html( sprintf( /* translators: 1: step, 2: total */ __( '%1$d of %2$d', 'nera-strikeawin' ), 4, $saw_steps ) ); ?></p>
				<h1 class="saw-wt__title"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s4_title' ) ); ?></h1>

				<p class="saw-callout"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s4_callout' ) ); ?></p>
				<p class="saw-wt__lede"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s4_body' ) ); ?></p>

				<a class="saw-cta saw-wt__finish" href="<?php echo esc_url( Nera_SAW_Router::url() ); ?>">
					<?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_wt_s4_cta' ) ); ?>
				</a>

			<?php endif; ?>

		</div>

		<nav class="saw-wt__foot" aria-label="<?php esc_attr_e( 'Walkthrough steps', 'nera-strikeawin' ); ?>">
			<?php if ( $saw_step > 1 ) : ?>
				<a class="saw-wt__nav" href="<?php echo $saw_link( $saw_step - 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
					aria-label="<?php esc_attr_e( 'Previous step', 'nera-strikeawin' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 18l-6-6 6-6"></path></svg>
				</a>
			<?php else : ?>
				<span class="saw-wt__nav saw-wt__nav--empty" aria-hidden="true"></span>
			<?php endif; ?>

			<ol class="saw-wt__pips">
				<?php for ( $saw_s = 1; $saw_s <= $saw_steps; $saw_s++ ) : ?>
					<li>
						<a class="saw-wt__pip<?php echo $saw_s === $saw_step ? ' is-on' : ''; ?>"
							href="<?php echo $saw_link( $saw_s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
							<?php echo $saw_s === $saw_step ? ' aria-current="step"' : ''; ?>
							aria-label="<?php echo esc_attr( sprintf( /* translators: 1: step, 2: total */ __( 'Step %1$d of %2$d', 'nera-strikeawin' ), $saw_s, $saw_steps ) ); ?>"></a>
					</li>
				<?php endfor; ?>
			</ol>

			<?php if ( $saw_step < $saw_steps ) : ?>
				<a class="saw-wt__nav" href="<?php echo $saw_link( $saw_step + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
					aria-label="<?php esc_attr_e( 'Next step', 'nera-strikeawin' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 18l6-6-6-6"></path></svg>
				</a>
			<?php else : ?>
				<span class="saw-wt__nav saw-wt__nav--empty" aria-hidden="true"></span>
			<?php endif; ?>
		</nav>

	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php', array( 'saw_bare' => true ) );
