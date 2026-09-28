<?php
/**
 * Standalone section — the header language toggle.
 *
 * Plain links, one per language. Switching is a page load, so this works with
 * scripting off and each language has an address that can be linked to — §5 of
 * docs/LANGUAGE-PLAN.md.
 *
 * Renders nothing when the site has fewer than two languages, and says nothing about
 * it: that is a monolingual site, not a misconfigured one.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * Override by copying to `nera-strikeawin/language-toggle.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_languages = Nera_SAW_Language_Switcher::options();

if ( count( $saw_languages ) < 2 ) {
	return;
}
?>
<nav class="saw-lang" aria-label="<?php esc_attr_e( 'Language', 'nera-strikeawin' ); ?>">
	<?php foreach ( $saw_languages as $saw_lang ) : ?>
		<a
			class="saw-lang__option<?php echo $saw_lang['current'] ? ' is-current' : ''; ?>"
			href="<?php echo esc_url( $saw_lang['url'] ); ?>"
			hreflang="<?php echo esc_attr( $saw_lang['code'] ); ?>"
			lang="<?php echo esc_attr( $saw_lang['code'] ); ?>"
			<?php echo $saw_lang['current'] ? ' aria-current="true"' : ''; ?>
		><?php echo esc_html( strtoupper( $saw_lang['code'] ) ); ?></a>
	<?php endforeach; ?>
</nav>
