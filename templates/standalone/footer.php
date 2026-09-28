<?php
/**
 * Standalone section — footer and document close.
 *
 * One paragraph, not a stack of lines: the design runs the company details, the
 * legal basis, the age mark and the risk warning together as continuous small
 * print, which is how this kind of notice is normally set.
 *
 * Only the first sentence has ever been in the ACF admin group. The rest —
 * Gambling Act, the risk statement, the terms link's own text — is required on
 * every page of a paid prize-competition site, so it was hand-written English
 * with no field behind it at all rather than something an administrator could
 * empty by accident. That second reason no longer needs hardcoding to hold: as
 * of the fields below, it is shell()-backed like the rest of this file, with
 * no ACF group entry, so there is still no admin screen where it could be
 * cleared — but the seeder can now write a translation of it, which a bare
 * `esc_html_e()` call never could.
 *
 * `$saw_bare` closes the document without the footer, for a screen that carries
 * the same notice in its own content. How it works is the one that does; leaving
 * both in would print the age mark twice on one screen, which reads as a bug.
 *
 * @package Nera_Strikeawin
 * @var bool $saw_bare Skip the footer block.
 */

defined( 'ABSPATH' ) || exit;

$saw_bare = isset( $saw_bare ) ? (bool) $saw_bare : false;
?>
<?php if ( ! $saw_bare ) : ?>
	<footer class="saw-footer">
		<?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_footer_line' ) ); ?>
		<?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_footer_legal' ) ); ?>
		<strong>18+</strong> &middot;
		<a href="https://www.begambleaware.org/" target="_blank" rel="noopener noreferrer">BeGambleAware.org</a> &middot;
		<?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_footer_risk' ) ); ?>
		<?php
		/*
		 * Linked only when WooCommerce has a terms page. The design shows "Full
		 * terms" on every draw, but a link to a page that does not exist is worse
		 * than the sentence without it.
		 */
		$saw_terms = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'terms' ) : '';
		if ( $saw_terms ) :
			?>
			&middot; <a href="<?php echo esc_url( $saw_terms ); ?>"><?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_footer_terms_label' ) ); ?></a>
			<?php echo esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_footer_terms_suffix' ) ); ?>
		<?php endif; ?>
	</footer>
<?php endif; ?>

</div><!-- .saw-shell -->

<?php
/*
 * Last in the document on purpose. It is positioned over the page by CSS rather
 * than by sitting in front of it in the markup, so a reader whose stylesheet did
 * not load still reaches the content, and a crawler is not met by a dialog.
 */
if ( class_exists( 'Nera_SAW_Language_Switcher' ) && Nera_SAW_Language_Switcher::gate_due() ) {
	Nera_SAW_Router::part( 'entry-gate.php' );
}
?>

<?php
/*
 * The walkthrough button. On mobile the design floats it bottom-right; on desktop
 * the same button lives in the header, so this one is hidden there rather than
 * rendered twice. Both read the one address, and both disappear while it is empty.
 */
$saw_walk = Nera_SAW_Router::walkthrough_url();
if ( $saw_walk ) :
	?>
	<a class="saw-fab" href="<?php echo esc_url( $saw_walk ); ?>"
		aria-label="<?php echo esc_attr( Nera_SAW_Standalone_Fields::shell( 'saw_walkthrough_aria' ) ); ?>">
		<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
			<path d="M3 5.5h18a1 1 0 0 1 1 1v2.6a3 3 0 0 0 0 5.8v2.6a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-2.6a3 3 0 0 0 0-5.8V6.5a1 1 0 0 1 1-1Z"></path>
			<path d="M10.3 10a1.8 1.8 0 0 1 3.5.6c0 1.2-1.8 1.7-1.8 1.7"></path>
			<circle cx="12" cy="15" r="0.4" fill="currentColor" stroke="none"></circle>
		</svg>
	</a>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
