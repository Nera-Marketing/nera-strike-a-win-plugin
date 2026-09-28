<?php
/**
 * Standalone section — the quiz spec panel.
 *
 * Shared by the competition page (before paying) and the play page (while
 * playing): the dark card listing question count, timer, difficulty bands and
 * what a perfect run pays per tier. Every number comes from $saw_spec, so a
 * player reads the same promise whichever screen they are on.
 *
 * Override by copying to `nera-strikeawin/parts/quiz-spec.php` in a theme.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * @package Nera_Strikeawin
 * @var array $saw_spec Nera_SAW_Competition_Spec::get() output.
 */

defined( 'ABSPATH' ) || exit;

$saw_qs_quiz = $saw_spec['quiz'];
?>
<section class="saw-spec">
	<p class="saw-spec__eyebrow">
		<span class="saw-spec__bullet"></span>
		<?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_spec_heading' ) ); ?>
	</p>

	<ul class="saw-spec__nums">
		<li>
			<strong><?php echo esc_html( number_format_i18n( $saw_qs_quiz['questions'] ) ); ?></strong>
			<span><?php esc_html_e( 'questions', 'nera-strikeawin' ); ?></span>
		</li>
		<li>
			<strong><?php echo esc_html( sprintf( /* translators: %d: seconds */ __( '%ds', 'nera-strikeawin' ), $saw_spec['timer']['seconds'] ) ); ?></strong>
			<span><?php esc_html_e( 'per question', 'nera-strikeawin' ); ?></span>
		</li>
		<li>
			<strong><?php echo esc_html( number_format_i18n( count( $saw_qs_quiz['levels'] ) ) ); ?></strong>
			<span><?php esc_html_e( 'difficulty bands', 'nera-strikeawin' ); ?></span>
		</li>
	</ul>

	<ul class="saw-spec__levels">
		<?php foreach ( $saw_qs_quiz['levels'] as $saw_qs_level ) : ?>
			<li>
				<span class="saw-spec__dot" style="background:<?php echo esc_attr( $saw_qs_level['colour'] ); ?>"></span>
				<span class="saw-spec__label"><?php echo esc_html( $saw_qs_level['label'] ); ?></span>
				<span class="saw-spec__count">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of questions */
							_n( '%s question', '%s questions', $saw_qs_level['questions'], 'nera-strikeawin' ),
							number_format_i18n( $saw_qs_level['questions'] )
						)
					);
					?>
				</span>
				<span class="saw-spec__each">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: tickets per correct answer */
							_n( '%s ticket each', '%s tickets each', $saw_qs_level['tickets'], 'nera-strikeawin' ),
							number_format_i18n( $saw_qs_level['tickets'] )
						)
					);
					?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $saw_spec['tiers'] ) : ?>
		<ul class="saw-spec__perfects">
			<?php
			// The last tier is the expensive one, and the design gives it the accent
			// so the difference between the two is visible without reading both.
			$saw_qs_last = count( $saw_spec['tiers'] ) - 1;
			foreach ( $saw_spec['tiers'] as $saw_qs_i => $saw_qs_tier ) :
				?>
				<li class="<?php echo $saw_qs_i === $saw_qs_last && $saw_qs_last > 0 ? 'is-accent' : ''; ?>">
					<span class="saw-spec__perfect-label">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: tier name */
								__( 'Perfect run, %s', 'nera-strikeawin' ),
								$saw_qs_tier['label']
							)
						);
						?>
					</span>
					<strong>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: tickets */
								_n( '%s ticket', '%s tickets', $saw_qs_tier['perfect_run'], 'nera-strikeawin' ),
								number_format_i18n( $saw_qs_tier['perfect_run'] )
							)
						);
						?>
					</strong>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<p class="saw-spec__note"><?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_spec_note' ) ); ?></p>
</section>
