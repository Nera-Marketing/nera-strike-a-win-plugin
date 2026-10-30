<?php
/**
 * Standalone section: free sign-up, /free/verify/.
 *
 * Neutral layout only: a theme gives it its look by copying this file to
 * `nera-strikeawin/free-verify.php`. The pending address comes from
 * Nera_SAW_Free_Signup (a cookie token, never the URL); without one the visitor
 * is sent back to the form before this renders.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_view = Nera_SAW_Free_Signup::verify_view();

Nera_SAW_Router::part( 'header.php', array( 'saw_title' => Nera_SAW_I18n::t( 'Enter your unlock code' ), 'saw_inner' => true ) );
?>

<div class="saw-screen saw-screen--narrow">
	<div class="saw-verify">
		<h1 class="saw-verify__title"><?php echo esc_html( Nera_SAW_I18n::t( 'Enter your unlock code' ) ); ?></h1>
		<p class="saw-verify__sub">
			<?php
			echo wp_kses(
				Nera_SAW_I18n::t( 'Enter the 6-digit code we sent to {e}.', array( 'e' => '<b>' . esc_html( $saw_view['email'] ) . '</b>' ) ),
				array( 'b' => array() )
			);
			?>
		</p>
	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
