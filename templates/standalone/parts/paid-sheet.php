<?php
/**
 * Standalone section — payment confirmed, the sheet over the competition page
 * after a paid entry order (Nera_SAW_Paid_Sheet).
 *
 * Expects: `$saw_paid` (Nera_SAW_Paid_Sheet::data()): runs, questions, seconds, url.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_line = $saw_paid['runs'] > 0
	? Nera_SAW_I18n::n( '{n} quiz attempt ready to play.', '{n} quiz attempts ready to play.', $saw_paid['runs'] )
	: '';
if ( '' !== $saw_line && $saw_paid['questions'] > 0 && $saw_paid['seconds'] > 0 ) {
	$saw_line .= ' ' . Nera_SAW_I18n::t(
		'Each quiz: {q}, {s}.',
		array(
			'q' => Nera_SAW_I18n::n( '{n} question', '{n} questions', $saw_paid['questions'] ),
			's' => Nera_SAW_I18n::n( '{n} second each', '{n} seconds each', $saw_paid['seconds'] ),
		)
	);
}
?>
<div class="saw-scrim saw-scrim--paid">
	<div class="saw-paidsheet" role="dialog" aria-modal="true" aria-labelledby="saw-paidsheet-title">
		<h2 class="saw-paidsheet__title" id="saw-paidsheet-title"><?php echo esc_html( Nera_SAW_I18n::t( 'Payment confirmed' ) ); ?></h2>
		<?php if ( '' !== $saw_line ) : ?>
			<p class="saw-paidsheet__line"><?php echo esc_html( $saw_line ); ?></p>
		<?php endif; ?>
		<p><a class="saw-go" href="<?php echo esc_url( $saw_paid['url'] ); ?>"><?php echo esc_html( Nera_SAW_I18n::t( 'Go to my challenge' ) ); ?> &rarr;</a></p>
	</div>
</div>
