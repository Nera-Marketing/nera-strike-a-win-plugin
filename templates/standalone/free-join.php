<?php
/**
 * Standalone section: free sign-up, /free/join/.
 *
 * Neutral layout only: a theme gives it its look by copying this file to
 * `nera-strikeawin/free-join.php`. Data, checks and sending come from
 * Nera_SAW_Free_Signup; the form posts here without script, and to REST
 * `free/code` with it (the theme's script).
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_view = Nera_SAW_Free_Signup::join_view();
$saw_err  = $saw_view['errors'];
$saw_val  = $saw_view['values'];
$saw_name = $saw_view['competition_id'] ? get_the_title( $saw_view['competition_id'] ) : '';

Nera_SAW_Router::part( 'header.php', array( 'saw_title' => Nera_SAW_I18n::t( 'Your challenge is ready.' ), 'saw_inner' => true ) );
?>

<div class="saw-screen saw-screen--narrow">
	<div class="saw-join">
		<?php if ( ! $saw_view['competition_id'] ) : ?>
			<p class="saw-join__closed"><?php echo esc_html( Nera_SAW_I18n::t( 'No free competition is open right now.' ) ); ?></p>
			<p><a href="<?php echo esc_url( Nera_SAW_Router::url() ); ?>"><?php echo esc_html( Nera_SAW_I18n::t( 'Back to competitions' ) ); ?></a></p>
		<?php else : ?>
			<p class="saw-join__prize"><?php echo esc_html( Nera_SAW_I18n::t( 'Playing for' ) ); ?> <b><?php echo esc_html( $saw_name ); ?></b></p>
			<h1 class="saw-join__title"><?php echo esc_html( Nera_SAW_I18n::t( 'Your challenge is ready.' ) ); ?></h1>
			<form class="saw-join__form" method="post" action="<?php echo esc_url( $saw_view['action'] ); ?>" novalidate data-saw-free-join data-rest="<?php echo esc_url( $saw_view['rest_url'] ); ?>">
				<input type="hidden" name="saw_free_join" value="1">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $saw_view['nonce'] ); ?>">
				<input type="hidden" name="c" value="<?php echo esc_attr( (string) $saw_view['competition_id'] ); ?>">
				<input type="hidden" name="saw_lang" value="<?php echo esc_attr( Nera_SAW_Language::current() ); ?>">
				<p>
					<label for="saw-join-email">Email</label><br>
					<input id="saw-join-email" name="email" type="email" autocomplete="email" value="<?php echo esc_attr( (string) ( $saw_val['email'] ?? '' ) ); ?>"<?php echo isset( $saw_err['email'] ) ? ' aria-invalid="true"' : ''; ?> aria-describedby="saw-join-email-err">
					<span class="saw-join__err" id="saw-join-email-err" data-saw-err="email"<?php echo isset( $saw_err['email'] ) ? '' : ' hidden'; ?>><?php echo esc_html( $saw_err['email'] ?? '' ); ?></span>
				</p>
				<p>
					<label for="saw-join-phone"><?php echo esc_html( Nera_SAW_I18n::t( 'Mobile / WhatsApp number' ) ); ?></label><br>
					<select name="country" aria-label="<?php echo esc_attr( Nera_SAW_I18n::t( 'Country code' ) ); ?>" autocomplete="tel-country-code">
						<?php foreach ( $saw_view['countries'] as $saw_iso => $saw_dial ) : ?>
							<option value="<?php echo esc_attr( $saw_iso ); ?>"<?php selected( $saw_view['country'], $saw_iso ); ?>><?php echo esc_html( $saw_iso . ' ' . $saw_dial ); ?></option>
						<?php endforeach; ?>
					</select>
					<input id="saw-join-phone" name="phone" type="tel" autocomplete="tel-national" value="<?php echo esc_attr( (string) ( $saw_val['phone'] ?? '' ) ); ?>"<?php echo isset( $saw_err['phone'] ) ? ' aria-invalid="true"' : ''; ?> aria-describedby="saw-join-phone-err">
					<span class="saw-join__err" id="saw-join-phone-err" data-saw-err="phone"<?php echo isset( $saw_err['phone'] ) ? '' : ' hidden'; ?>><?php echo esc_html( $saw_err['phone'] ?? '' ); ?></span>
				</p>
				<p>
					<label><input type="checkbox" name="consent" value="1"<?php checked( ! empty( $saw_val['consent'] ) ); ?><?php echo isset( $saw_err['consent'] ) ? ' aria-invalid="true"' : ''; ?> aria-describedby="saw-join-consent-err">
						<?php
						$saw_rules = '<a href="' . esc_url( $saw_view['rules_url'] ) . '">' . esc_html( Nera_SAW_I18n::t( 'free competition rules' ) ) . '</a>';
						echo wp_kses_post( Nera_SAW_I18n::t( 'I am 18 or over and accept the {r}.', array( 'r' => $saw_rules ) ) );
						?>
					</label>
					<span class="saw-join__err" id="saw-join-consent-err" data-saw-err="consent"<?php echo isset( $saw_err['consent'] ) ? '' : ' hidden'; ?>><?php echo esc_html( $saw_err['consent'] ?? '' ); ?></span>
				</p>
				<p><label><input type="checkbox" name="marketing" value="1"<?php checked( ! empty( $saw_val['marketing'] ) ); ?>> <?php echo esc_html( Nera_SAW_I18n::t( 'Send me news about free competitions, new prizes and upcoming draws.' ) ); ?></label></p>
				<p><button type="submit" class="saw-join__go"><?php echo esc_html( Nera_SAW_I18n::t( 'Send my unlock code' ) ); ?></button></p>
				<p class="saw-join__priv"><?php echo esc_html( Nera_SAW_I18n::t( 'We’ll email you a 6-digit code. Your details are used as described in our Privacy policy.' ) ); ?></p>
			</form>
		<?php endif; ?>
	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
