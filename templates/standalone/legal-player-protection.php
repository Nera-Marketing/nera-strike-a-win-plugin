<?php
/**
 * Standalone section — Our commitment to player protection.
 *
 * Calls nera-voluntary-code-plugin's own `Nera_VC_Page::render_block()`
 * directly — the same calling pattern used throughout this section for
 * sibling-plugin-owned content — rather than holding a copy of its markup.
 * An administrator keeps editing that plugin's own settings screen exactly
 * as before; this screen only wraps its rendered output in the section's
 * own skin. That plugin's own CSS is enqueued unconditionally on every
 * front-end request already, so no extra wiring is needed here.
 *
 * Client findings #4/#48: that plugin's own page
 * (`/our-commitment-to-player-protection/`) was rendering in the stock
 * theme's own skin. The bare URL itself now 301s here (see
 * Nera_SAW_Stock_Page_Redirects); the sibling plugin's own page and its
 * self-heal behaviour are both left completely alone.
 *
 * Registered only when nera-voluntary-code-plugin is active — see
 * Nera_SAW_Standalone_Legal_Pages::add_pages().
 *
 * Override by copying to `nera-strikeawin/legal-player-protection.php` in
 * a theme.
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
		if ( class_exists( 'Nera_SAW_Language' ) && 'ru' === Nera_SAW_Language::current() && class_exists( 'Nera_SAW_Standalone_Legal_RU_Content' ) ) {
			// The intro copy and every measure's title/body are plain data,
			// not gettext — see class-standalone-legal-ru-content.php.
			echo Nera_SAW_Standalone_Legal_RU_Content::render_player_protection(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped HTML.
		} elseif ( class_exists( 'Nera_VC_Page' ) && method_exists( 'Nera_VC_Page', 'render_block' ) ) {
			echo Nera_VC_Page::render_block(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- that plugin's own, already-escaped HTML.
		}
		?>
	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
