<?php
/**
 * Standalone section — Privacy Policy.
 *
 * Prints this page's own `post_content`, live, every request, in the
 * section's skin. An administrator edits the text in wp-admin like any page;
 * the Russian text is the Polylang translation of it, when there is one.
 *
 * Since ADR 0032 this is the page WordPress's own "Privacy Policy" setting
 * (`wp_page_for_privacy_policy`) points at; the old theme-skinned page is in
 * the trash.
 *
 * Override by copying to `nera-strikeawin/legal-privacy.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_source = get_queried_object();

// A machine-translated Russian version of this exact page, linked via
// Polylang (see Nera_SAW_Standalone_Legal_Pages::ensure_ru_translations()) —
// an administrator may since have edited it directly in wp-admin. Falls
// back to the English source, same as when no translation exists yet.
if ( $saw_source && class_exists( 'Nera_SAW_Language' ) && 'ru' === Nera_SAW_Language::current() && function_exists( 'pll_get_post' ) ) {
	$saw_ru_id = pll_get_post( $saw_source->ID, 'ru' );
	if ( $saw_ru_id ) {
		$saw_ru_post = get_post( $saw_ru_id );
		if ( $saw_ru_post && 'publish' === $saw_ru_post->post_status ) {
			$saw_source = $saw_ru_post;
		}
	}
}

Nera_SAW_Router::part(
	'header.php',
	array(
		'saw_title' => get_the_title(),
		'saw_inner' => true,
	)
);

Nera_SAW_Router::part(
	'screen-head.php',
	array(
		'saw_screen_title' => get_the_title(),
		'saw_back_url'     => Nera_SAW_Router::url(),
	)
);
?>

<div class="saw-screen saw-screen--narrow">
	<div class="saw-stack saw-legal-mirror">
		<?php if ( $saw_source ) : ?>
			<?php echo Nera_SAW_Copy::undash( apply_filters( 'the_content', $saw_source->post_content ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content runs its own kses/sanitising pass. ?>
		<?php else : ?>
			<p><?php esc_html_e( 'This page has not been set up yet.', 'nera-strikeawin' ); ?></p>
		<?php endif; ?>
	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
