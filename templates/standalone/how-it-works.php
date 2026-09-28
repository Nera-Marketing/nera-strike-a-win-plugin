<?php
/**
 * Standalone section — How it works.
 *
 * The screen the header links to on every other screen, and the one a regulator
 * or a complaint will be pointed at. Its whole job is to say, before any money
 * moves, that the test is hard and that losing is normal.
 *
 * All of it is editable, because this copy gets rewritten: by legal, after a
 * complaint, or when the wording of a term changes. None of it is hard-coded.
 *
 * Steps are one per line in a textarea rather than a repeater. A repeater is the
 * tidier data model, but this list is read top to bottom and reordered often, and
 * moving line three above line two is a drag in one and a retype in the other.
 *
 * LAYOUT
 * An inner screen: the logo bar is hidden on mobile and the back button carries
 * the title instead, and the content sits in a 600px column on desktop rather
 * than the full shell width — a measure wide enough to stretch a sentence across
 * a monitor is a measure nobody reads.
 *
 * The section footer is suppressed because this screen prints the same notice in
 * its own legal line. That line falls back to its default when emptied, so the
 * age mark cannot disappear by clearing a field.
 *
 * Override by copying to `nera-strikeawin/how-it-works.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_heading  = Nera_SAW_Standalone_Fields::text( 'saw_hiw_heading' );
$saw_steps    = Nera_SAW_Standalone_Fields::text( 'saw_hiw_steps' );
$saw_callout  = Nera_SAW_Standalone_Fields::text( 'saw_hiw_callout' );
$saw_cta_url  = Nera_SAW_Router::walkthrough_url();
$saw_cta_text = Nera_SAW_Standalone_Fields::text( 'saw_hiw_cta_label' );
$saw_legal    = Nera_SAW_Standalone_Fields::text( 'saw_hiw_legal' );

// Blank lines are how somebody separates thoughts while editing; they are not
// steps, and numbering them would leave gaps in the list.
$saw_lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $saw_steps ) ) ) );

Nera_SAW_Router::part(
	'header.php',
	array(
		'saw_title' => get_the_title(),
		'saw_inner' => true,
	)
);
?>

<div class="saw-screen saw-screen--narrow">

	<?php Nera_SAW_Router::part( 'screen-head.php', array( 'saw_screen_title' => $saw_heading ) ); ?>

	<div class="saw-stack">

		<?php if ( $saw_lines ) : ?>
			<ol class="saw-steps">
				<?php foreach ( $saw_lines as $saw_index => $saw_line ) : ?>
					<li class="saw-step">
						<span class="saw-step__n"><?php echo esc_html( number_format_i18n( $saw_index + 1 ) ); ?>.</span>
						<?php echo wp_kses_post( $saw_line ); ?>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>

		<?php if ( '' !== trim( (string) $saw_callout ) ) : ?>
			<p class="saw-callout"><?php echo esc_html( $saw_callout ); ?></p>
		<?php endif; ?>

		<?php
		/*
		 * Rendered only when an address has been set. The design's button opens a
		 * walkthrough that does not exist yet, and shipping a button that goes
		 * nowhere is worse than shipping no button -- so the field is empty by
		 * default and the screen simply has one fewer element until there is
		 * something to point at.
		 */
		if ( '' !== trim( (string) $saw_cta_url ) ) :
			?>
			<a class="saw-secondary-cta" href="<?php echo esc_url( $saw_cta_url ); ?>"><?php echo esc_html( $saw_cta_text ); ?></a>
		<?php endif; ?>

		<p class="saw-legal-line"><?php echo wp_kses_post( $saw_legal ); ?></p>

	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php', array( 'saw_bare' => true ) );
