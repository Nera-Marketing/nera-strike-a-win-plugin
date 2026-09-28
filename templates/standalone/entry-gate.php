<?php
/**
 * Standalone section — the entry pop-up.
 *
 * Two jobs: choose a language, and acknowledge being over 18. Either half can be
 * absent and the pop-up still makes sense.
 *
 * - Fewer than two languages: the language buttons go, the age line stays.
 * - Already verified through `nera-age-shield-plugin`: the age question is replaced
 *   by a statement of the fact, not a pre-ticked box. A tick the player did not make
 *   cannot evidence a choice they made, and an auditor reading a pre-ticked consent
 *   control is entitled to say so. The same information, stated rather than
 *   pretended, is what §8.2 of docs/LANGUAGE-PLAN.md raised for the client.
 * - No age plugin at all: the 18+ line is a self-declaration and nothing is recorded.
 *
 * Every control is a link or a form submit, so the pop-up works with scripting off —
 * which matters, because it stands between the player and the whole section.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * Override by copying to `nera-strikeawin/entry-gate.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_languages = Nera_SAW_Language_Switcher::options();
$saw_age       = Nera_SAW_Language_Switcher::age_state();
?>
<div class="saw-gate" role="dialog" aria-modal="true" aria-labelledby="saw-gate-title">
	<div class="saw-gate__card">

		<h2 id="saw-gate-title" class="saw-gate__title">
			<?php esc_html_e( 'Before you start', 'nera-strikeawin' ); ?>
		</h2>

		<?php if ( count( $saw_languages ) > 1 ) : ?>
			<p class="saw-gate__label"><?php esc_html_e( 'Choose your language', 'nera-strikeawin' ); ?></p>

			<div class="saw-gate__languages">
				<?php
				/*
				 * entry_url() marks the pop-up as answered (saw_entry=1) — correct once
				 * $saw_age['verified'] already, but if age is still unconfirmed a
				 * language pick must not double as the 18+ answer nobody gave. Use the
				 * plain language switch (stays on the gate) until then.
				 */
				$saw_lang_url = static function ( $code ) use ( $saw_age ) {
					return $saw_age['verified']
						? Nera_SAW_Language_Switcher::entry_url( $code )
						: Nera_SAW_Language_Switcher::url_for( $code );
				};
				foreach ( $saw_languages as $saw_lang ) :
					?>
					<a
						class="saw-gate__language<?php echo $saw_lang['current'] ? ' is-current' : ''; ?>"
						href="<?php echo esc_url( $saw_lang_url( $saw_lang['code'] ) ); ?>"
						hreflang="<?php echo esc_attr( $saw_lang['code'] ); ?>"
						lang="<?php echo esc_attr( $saw_lang['code'] ); ?>"
					>
						<span class="saw-gate__language-code"><?php echo esc_html( strtoupper( $saw_lang['code'] ) ); ?></span>
						<span class="saw-gate__language-name"><?php echo esc_html( $saw_lang['name'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $saw_age['verified'] ) : ?>
			<p class="saw-gate__verified">
				<span class="saw-gate__tick" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
				</span>
				<?php esc_html_e( 'Verified: you have confirmed you are over 18.', 'nera-strikeawin' ); ?>
			</p>

			<a class="saw-gate__enter" href="<?php echo esc_url( Nera_SAW_Language_Switcher::entry_url() ); ?>">
				<?php esc_html_e( 'Continue', 'nera-strikeawin' ); ?>
			</a>
		<?php else : ?>
			<form class="saw-gate__form" method="get" action="<?php echo esc_url( Nera_SAW_Language_Switcher::current_url() ); ?>">
				<?php
				/*
				 * A GET form, so the answer lands in the address the same way the
				 * language links do, and the page that follows is linkable. The hidden
				 * fields carry the current request so the player is returned to where
				 * they were rather than to the section's front page.
				 */
				foreach ( Nera_SAW_Language_Switcher::gate_return_fields() as $saw_name => $saw_value ) :
					?>
					<input type="hidden" name="<?php echo esc_attr( $saw_name ); ?>" value="<?php echo esc_attr( $saw_value ); ?>">
				<?php endforeach; ?>
				<input type="hidden" name="saw_entry" value="1">

				<label class="saw-gate__check">
					<input type="checkbox" name="saw_over18" value="1" required>
					<span><?php esc_html_e( 'I confirm that I am 18 or over.', 'nera-strikeawin' ); ?></span>
				</label>

				<?php if ( ! $saw_age['recorded'] ) : ?>
					<p class="saw-gate__note"><?php esc_html_e( 'This is a self-declaration. Nothing is recorded against your account.', 'nera-strikeawin' ); ?></p>
				<?php endif; ?>

				<button type="submit" class="saw-gate__enter"><?php esc_html_e( 'Enter', 'nera-strikeawin' ); ?></button>
			</form>
		<?php endif; ?>

	</div>
</div>
