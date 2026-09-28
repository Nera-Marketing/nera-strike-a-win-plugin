<?php
/**
 * Standalone section — a screen's own header: back button, title, and a line of
 * context under it.
 *
 * Every screen below the list has one. On mobile it is the only header the screen
 * gets: the design drops the logo bar entirely there, because 430px cannot hold a
 * 110px logo and a title and still leave room for the screen itself. On desktop it
 * sits under the sticky site bar.
 *
 * The back button is a real link rather than history.back(): a player who arrives
 * from a search result, a shared link or a payment redirect has no history to go
 * back through, and a button that does nothing is worse than one that goes
 * somewhere predictable.
 *
 * Override by copying to `nera-strikeawin/screen-head.php` in a theme.
 *
 * @package Nera_Strikeawin
 * @var string $saw_screen_title Title shown beside the back button.
 * @var string $saw_screen_sub   Optional second line, e.g. a closing date.
 * @var string $saw_back_url     Where back goes. Defaults to the section root.
 */

defined( 'ABSPATH' ) || exit;

$saw_screen_title = isset( $saw_screen_title ) ? $saw_screen_title : '';
$saw_screen_sub   = isset( $saw_screen_sub ) ? $saw_screen_sub : '';
$saw_back_url     = isset( $saw_back_url ) && $saw_back_url ? $saw_back_url : Nera_SAW_Router::url();
?>
<div class="saw-screen-head">
	<a class="saw-screen-head__back" href="<?php echo esc_url( $saw_back_url ); ?>"
		aria-label="<?php echo esc_attr( Nera_SAW_Standalone_Fields::shell( 'saw_back_aria' ) ); ?>">
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
			<path d="M15 18l-6-6 6-6"></path>
		</svg>
	</a>
	<div class="saw-screen-head__text">
		<h1 class="saw-screen-head__title"><?php echo wp_kses_post( $saw_screen_title ); ?></h1>
		<?php if ( '' !== trim( (string) $saw_screen_sub ) ) : ?>
			<p class="saw-screen-head__sub"><?php echo esc_html( $saw_screen_sub ); ?></p>
		<?php endif; ?>
	</div>
</div>
