<?php
/**
 * Standalone section — Privacy Policy.
 *
 * Mirrors whichever page WordPress's own "Privacy Policy" setting
 * (`wp_page_for_privacy_policy`) currently points at — its `post_content`,
 * live, every request — rather than holding a second copy of the text. An
 * administrator keeps editing that one real page in wp-admin (or changes
 * the Settings → Privacy pointer itself) exactly as before; this screen
 * only wraps the same content in the section's own skin.
 *
 * Client findings #4/#48: that page was rendering in the stock theme's own
 * skin. `/privacy-policy/` itself now 301s here (see
 * Nera_SAW_Stock_Page_Redirects).
 *
 * Registered only when a Privacy Policy page is actually set — see
 * Nera_SAW_Standalone_Legal_Pages::add_pages().
 *
 * Override by copying to `nera-strikeawin/legal-privacy.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_source_id = (int) get_option( 'wp_page_for_privacy_policy' );
$saw_source     = $saw_source_id ? get_post( $saw_source_id ) : null;

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
			<?php echo apply_filters( 'the_content', $saw_source->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content runs its own kses/sanitising pass. ?>
		<?php else : ?>
			<p><?php esc_html_e( 'This page has not been set up yet.', 'nera-strikeawin' ); ?></p>
		<?php endif; ?>
	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
