<?php
/**
 * Standalone section — the Free page: the open free competition closing soonest,
 * how free entry works and the link that starts it. With no free competition open
 * it says so. Neutral layout only; a theme draws the page its own way by copying
 * this file to `nera-strikeawin/free.php`.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_free_id   = Nera_SAW_Free::current();
$saw_free_spec = $saw_free_id ? Nera_SAW_Competition_Spec::get( $saw_free_id ) : null;

Nera_SAW_Router::part( 'header.php', array( 'saw_title' => get_the_title(), 'saw_inner' => true ) );
Nera_SAW_Router::part( 'screen-head.php', array( 'saw_screen_title' => get_the_title(), 'saw_back_url' => Nera_SAW_Router::url() ) );
?>

<div class="saw-screen saw-screen--narrow">
	<div class="saw-stack">
		<?php if ( ! $saw_free_spec ) : ?>
			<div class="saw-empty">
				<p><strong><?php esc_html_e( 'Coming soon', 'nera-strikeawin' ); ?></strong></p>
				<p><?php esc_html_e( 'There is no free competition open right now. In the meantime, see what is open.', 'nera-strikeawin' ); ?></p>
				<p><a href="<?php echo esc_url( Nera_SAW_Router::url() ); ?>"><?php esc_html_e( 'See what is open', 'nera-strikeawin' ); ?></a></p>
			</div>
		<?php else : ?>
			<section class="saw-free">
				<?php if ( ! empty( $saw_free_spec['image']['url'] ) ) : ?>
					<img src="<?php echo esc_url( $saw_free_spec['image']['url'] ); ?>" alt="" decoding="async">
				<?php endif; ?>
				<h1><?php echo esc_html( $saw_free_spec['name'] ); ?></h1>
				<p><?php esc_html_e( 'Free entry · no purchase needed', 'nera-strikeawin' ); ?></p>
				<?php if ( ! empty( $saw_free_spec['closes_timestamp'] ) && class_exists( 'Nera_SAW_Date' ) ) : ?>
					<p><?php esc_html_e( 'Entries close', 'nera-strikeawin' ); ?> <b><?php echo esc_html( Nera_SAW_Date::display( (int) $saw_free_spec['closes_timestamp'] ) ); ?></b></p>
				<?php endif; ?>
				<ol>
					<li><?php esc_html_e( 'Sign up for free with your email and mobile number.', 'nera-strikeawin' ); ?></li>
					<li><?php esc_html_e( 'Play the quiz: correct answers earn tickets.', 'nera-strikeawin' ); ?></li>
					<li><?php esc_html_e( 'Your tickets go into the free prize draw.', 'nera-strikeawin' ); ?></li>
				</ol>
				<p><a class="saw-cta" href="<?php echo esc_url( Nera_SAW_Free::join_url( $saw_free_id ) ); ?>"><?php esc_html_e( 'Start the free challenge', 'nera-strikeawin' ); ?></a></p>
				<p><?php esc_html_e( 'No purchase necessary · One free quiz per person · Free competition rules apply', 'nera-strikeawin' ); ?></p>
			</section>
		<?php endif; ?>
	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
