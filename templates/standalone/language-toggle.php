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
 * Client finding #25: at 360px the two-segment pill below, always both options
 * at once, was part of what pushed the header past the viewport. With exactly
 * two languages (this site's actual case) a second, single-link "swap" control
 * is rendered alongside it — current language's flag + code, linking straight
 * to the OTHER language — and CSS (`standalone.css`'s existing <900px header
 * breakpoint) shows exactly one of the two per viewport: the swap pill on
 * mobile, the original side-by-side pill on desktop, matching the client's own
 * prototype (`option-toggle/index.html`, "Option 2"). Both are still ordinary
 * `<a href>` links — see `saw-lang-swap.js` for the click enhancement, which is
 * cosmetic only and never required for the link to work.
 *
 * With anything other than exactly two languages there is no single "other"
 * language to swap to, so the swap pill is skipped and the original pill is
 * left visible at every width, unchanged.
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

$saw_swap_current = null;
$saw_swap_other   = null;
if ( 2 === count( $saw_languages ) ) {
	foreach ( $saw_languages as $saw_lang ) {
		if ( $saw_lang['current'] ) {
			$saw_swap_current = $saw_lang;
		} else {
			$saw_swap_other = $saw_lang;
		}
	}
}
?>
<?php if ( $saw_swap_current && $saw_swap_other ) : ?>
	<a
		class="saw-lang-swap"
		data-saw-lang-swap
		data-other-code="<?php echo esc_attr( strtoupper( $saw_swap_other['code'] ) ); ?>"
		data-other-aria-label="<?php echo esc_attr( Nera_SAW_Language_Switcher::swap_aria_label( $saw_swap_other['code'], $saw_swap_current['code'] ) ); ?>"
		href="<?php echo esc_url( $saw_swap_other['url'] ); ?>"
		hreflang="<?php echo esc_attr( $saw_swap_other['code'] ); ?>"
		aria-label="<?php echo esc_attr( Nera_SAW_Language_Switcher::swap_aria_label( $saw_swap_current['code'], $saw_swap_other['code'] ) ); ?>"
	>
		<?php foreach ( array( $saw_swap_current, $saw_swap_other ) as $saw_swap_flag ) : ?>
			<span
				data-saw-lang-swap-flag="<?php echo esc_attr( $saw_swap_flag['code'] ); ?>"
				<?php echo $saw_swap_flag['current'] ? '' : ' hidden'; ?>
			><?php echo wp_kses( Nera_SAW_Language_Switcher::flag_svg( $saw_swap_flag['code'] ), Nera_SAW_Language_Switcher::flag_svg_allowed_html() ); ?></span>
		<?php endforeach; ?>
		<span data-saw-lang-swap-code><?php echo esc_html( strtoupper( $saw_swap_current['code'] ) ); ?></span>
	</a>
<?php endif; ?>
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
