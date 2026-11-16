<?php
/**
 * Standalone section — a shared result, /r/<token>/.
 *
 * Neutral layout only: a theme gives it its look by copying this file to
 * `nera-strikeawin/share.php`. Data, validity and the meta tags come from
 * Nera_SAW_Share, so an override only decides the markup.
 *
 * Never prints the player's name, email or ticket numbers.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_share = Nera_SAW_Share::view();

if ( 'missing' === $saw_share['state'] ) {
	// The router answers 200 for every route it serves; an unknown token is not a page.
	status_header( 404 );
	nocache_headers();
}

Nera_SAW_Router::part(
	'header.php',
	array(
		'saw_title' => 'missing' === $saw_share['state'] ? __( 'Not found', 'nera-strikeawin' ) : $saw_share['name'],
		'saw_inner' => true,
	)
);
?>

<div class="saw-screen saw-screen--narrow">
	<?php if ( 'missing' === $saw_share['state'] ) : ?>
		<div class="saw-share saw-share--missing">
			<h1 class="saw-share__title"><?php esc_html_e( 'Not found', 'nera-strikeawin' ); ?></h1>
			<p class="saw-share__lead"><?php echo esc_html( Nera_SAW_Share::t( 'That link does not exist.' ) ); ?></p>
			<p><a class="saw-share__try" href="<?php echo esc_url( $saw_share['try_url'] ); ?>"><?php esc_html_e( 'See what is open', 'nera-strikeawin' ); ?></a></p>
		</div>
	<?php else : ?>
		<article class="saw-share saw-share--<?php echo esc_attr( $saw_share['state'] ); ?>">
			<?php if ( $saw_share['image'] ) : ?>
				<figure class="saw-share__photo"><img src="<?php echo esc_url( $saw_share['image'] ); ?>" alt="<?php echo esc_attr( $saw_share['image_alt'] ); ?>"></figure>
			<?php endif; ?>
			<div class="saw-share__body">
				<p class="saw-share__eyebrow"><?php echo esc_html( Nera_SAW_Share::t( 'Shared result' ) ); ?></p>
				<h1 class="saw-share__title"><?php echo esc_html( $saw_share['name'] ); ?></h1>
				<?php if ( 'valid' === $saw_share['state'] ) : ?>
					<dl class="saw-share__stats">
						<div class="saw-share__stat">
							<dt><?php echo esc_html( Nera_SAW_I18n::t( 'Score' ) ); ?></dt>
							<dd><?php echo esc_html( $saw_share['score'] . '/' . $saw_share['questions'] ); ?></dd>
						</div>
						<div class="saw-share__stat saw-share__stat--tickets">
							<dt><?php echo esc_html( Nera_SAW_I18n::t( 'Tickets earned' ) ); ?></dt>
							<dd><?php echo esc_html( $saw_share['tickets_text'] ); ?></dd>
						</div>
					</dl>
					<p class="saw-share__lead"><?php echo esc_html( Nera_SAW_Share::t( 'Answer timed questions and earn tickets for the draw. Think you can beat it?' ) ); ?></p>
				<?php else : ?>
					<h2 class="saw-share__expired"><?php echo esc_html( Nera_SAW_Share::t( 'This link has expired' ) ); ?></h2>
					<p class="saw-share__lead"><?php echo esc_html( Nera_SAW_Share::t( 'Shared results are shown for a limited time and not after the draw. You can still try the quiz yourself.' ) ); ?></p>
				<?php endif; ?>
				<p><a class="saw-share__try" href="<?php echo esc_url( $saw_share['try_url'] ); ?>"><?php echo esc_html( Nera_SAW_Share::t( 'Try it' ) ); ?></a></p>
			</div>
		</article>
	<?php endif; ?>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
