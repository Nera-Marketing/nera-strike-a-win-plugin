<?php
/**
 * Standalone section — the result blurb and footnote.
 *
 * Shared by the competition page and the play page: what happens to a
 * player's tickets, stated once so both screens agree with each other.
 *
 * Override by copying to `nera-strikeawin/parts/result-teaser.php` in a theme.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="saw-result-teaser">
	<p class="saw-result-teaser__title"><?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_result_title' ) ); ?></p>
	<p class="saw-result-teaser__note"><?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_result_note' ) ); ?></p>
</section>

<p class="saw-footnote"><?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_cd_footnote' ) ); ?></p>
