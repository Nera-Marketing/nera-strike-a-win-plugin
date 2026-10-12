<?php
/**
 * Standalone section — Complaints.
 *
 * Calls nera-complaints-plugin's own `Nera_CMP_Shortcodes::render_page()`
 * directly — the same pattern
 * `templates/woocommerce/myaccount/responsible-play.php` already uses for
 * two OTHER sibling plugins — rather than holding a copy of its markup.
 * An administrator keeps editing that plugin's own settings screen exactly
 * as before; this screen only wraps its rendered output in the section's
 * own skin.
 *
 * CSS: see Nera_SAW_Standalone_Legal_Pages::maybe_enqueue_complaints_css()
 * for why that plugin's own, page-ID-gated enqueue never fires on this
 * page, and the early hook that fixes it from this plugin's own side.
 *
 * Client findings #4/#48: that plugin's own page (`/complaints/`) was
 * rendering in the stock theme's own skin. `/complaints/` itself now 301s
 * here (see Nera_SAW_Stock_Page_Redirects); the sibling plugin's own page
 * and its self-heal behaviour are both left completely alone.
 *
 * Registered only when nera-complaints-plugin is active — see
 * Nera_SAW_Standalone_Legal_Pages::add_pages().
 *
 * Override by copying to `nera-strikeawin/legal-complaints.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

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
		<?php
		if ( class_exists( 'Nera_CMP_Shortcodes' ) && method_exists( 'Nera_CMP_Shortcodes', 'render_page' ) ) {
			echo Nera_CMP_Shortcodes::render_page(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- that plugin's own, already-escaped HTML.
		}
		?>
	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
