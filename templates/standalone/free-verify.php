<?php
/**
 * Standalone section: free sign-up, /free/verify/.
 *
 * Neutral layout only: a theme gives it its look by copying this file to
 * `nera-strikeawin/free-verify.php`. The address comes from Nera_SAW_Free_Signup
 * (the pending cookie or the logged-in account, never the URL); without one the
 * visitor is sent back to the form before this renders. The form posts to the page
 * itself: the code (six boxes, `code[]`), "Resend code" or "Change email".
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_view = Nera_SAW_Free_Signup::verify_view();
$saw_code = Nera_SAW_I18n::t( 'Verification code' );

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

		<form class="saw-verify__form" method="post" action="<?php echo esc_url( $saw_view['action'] ); ?>" novalidate>
			<input type="hidden" name="saw_free_verify" value="1">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $saw_view['nonce'] ); ?>">
			<input type="hidden" name="saw_lang" value="<?php echo esc_attr( Nera_SAW_Language::current() ); ?>">

			<div class="saw-verify__otp" role="group" aria-label="<?php echo esc_attr( $saw_code ); ?>">
				<?php for ( $saw_n = 1; $saw_n <= 6; $saw_n++ ) : ?>
					<input class="saw-verify__box" name="code[]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" size="1" aria-label="<?php echo esc_attr( $saw_code . ' ' . $saw_n ); ?>" aria-describedby="saw-verify-code-err"<?php echo 1 === $saw_n ? ' autocomplete="one-time-code"' : ' autocomplete="off"'; ?><?php echo 'code' === $saw_view['error_field'] ? ' aria-invalid="true"' : ''; ?>>
				<?php endfor; ?>
			</div>
			<p id="saw-verify-code-err" role="alert"<?php echo 'code' === $saw_view['error_field'] ? '' : ' hidden'; ?>><?php echo esc_html( 'code' === $saw_view['error_field'] ? $saw_view['error'] : '' ); ?></p>

			<?php if ( $saw_view['consent'] ) : ?>
				<p>
					<label for="saw-verify-age"><input type="checkbox" id="saw-verify-age" name="consent" value="1" aria-describedby="saw-verify-consent-err"<?php echo 'consent' === $saw_view['error_field'] ? ' aria-invalid="true"' : ''; ?>>
					<?php echo wp_kses( Nera_SAW_I18n::t( 'I am 18 or over and accept the {r}.', array( 'r' => '<a href="' . esc_url( $saw_view['rules_url'] ) . '">' . esc_html( Nera_SAW_I18n::t( 'free competition rules' ) ) . '</a>' ) ), array( 'a' => array( 'href' => true ) ) ); ?></label>
				</p>
				<p id="saw-verify-consent-err" role="alert"<?php echo 'consent' === $saw_view['error_field'] ? '' : ' hidden'; ?>><?php echo esc_html( 'consent' === $saw_view['error_field'] ? $saw_view['error'] : '' ); ?></p>
			<?php endif; ?>

			<p><button type="submit"><?php echo esc_html( Nera_SAW_I18n::t( 'Unlock and play' ) ); ?></button></p>
			<p>
				<button type="submit" name="saw_free_resend" value="1"><?php echo esc_html( Nera_SAW_I18n::t( 'Resend code' ) ); ?></button>
				<?php if ( ! $saw_view['logged_in'] ) : ?>
					<button type="submit" name="saw_free_change" value="1"><?php echo esc_html( Nera_SAW_I18n::t( 'Change email' ) ); ?></button>
				<?php endif; ?>
			</p>
			<?php if ( '' !== $saw_view['notice'] ) : ?>
				<p role="status"><?php echo esc_html( $saw_view['notice'] ); ?></p>
			<?php endif; ?>
		</form>
	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
