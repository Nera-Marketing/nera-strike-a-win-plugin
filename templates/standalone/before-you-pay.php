<?php
/**
 * Standalone section — Before you pay.
 *
 * The screen that replaces the basket. It shows the one entry being bought, the
 * spec the player is agreeing to, and two consent checks, then hands over to
 * WooCommerce checkout.
 *
 * WHY THE CHECKBOXES ARE `required` AND NOT JAVASCRIPT
 * ---------------------------------------------------
 * The browser refuses to submit the form until both are ticked, with no script
 * involved. A JS gate on a screen like this fails open the moment a script errors
 * earlier on the page — and the thing it is gating is the player's confirmation
 * that a run can earn nothing. Native validation cannot fail open.
 *
 * The money is still taken by WooCommerce. Tickets are minted from order lines by
 * Nera_SAW_Ticket_Award, and the grant ledger reads those same lines, so an entry
 * that skipped the order would be an entry that can never pay out.
 *
 * Override by copying to `nera-strikeawin/before-you-pay.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_entry   = Nera_SAW_Standalone_Basket::current_entry();
$saw_heading = Nera_SAW_Standalone_Fields::text( 'saw_pp_heading' );

Nera_SAW_Router::part(
	'header.php',
	array(
		'saw_title' => get_the_title(),
		'saw_inner' => true,
	)
);

Nera_SAW_Router::part( 'screen-head.php', array( 'saw_screen_title' => $saw_heading ) );
?>

<div class="saw-screen saw-screen--narrow">
	<div class="saw-stack">

	<?php if ( ! $saw_entry ) : ?>

		<div class="saw-empty">
			<p><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_pp_empty' ) ); ?></p>
			<p><a href="<?php echo esc_url( Nera_SAW_Router::url() ); ?>"><?php esc_html_e( 'See what is open', 'nera-strikeawin' ); ?></a></p>
		</div>

	<?php else : ?>

		<?php
		$saw_spec = $saw_entry['spec'];
		$saw_tier = $saw_entry['tier'];
		$saw_qty  = $saw_entry['quantity'];
		$saw_quiz = $saw_spec['quiz'];

		// The total comes from the cart, never from the tier price multiplied here.
		// WooCommerce is the authority on what will be charged, and a figure computed
		// on this screen would be the one the player remembers when it disagrees.
		$saw_total = $saw_entry['line_total'] > 0
			? $saw_entry['line_total']
			: ( $saw_tier ? (float) $saw_tier['price'] * $saw_qty : 0.0 );
		?>

		<section class="saw-summary">
			<div class="saw-summary__top">
				<p class="saw-summary__name"><?php echo esc_html( $saw_spec['name'] ); ?></p>
				<p class="saw-summary__price"><?php echo wp_kses_post( wc_price( $saw_total ) ); ?></p>
			</div>

			<p class="saw-summary__line">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: tier name, 2: ticket multiplier, 3: number of runs */
						__( '%1$s tier · tickets ×%2$d · %3$s', 'nera-strikeawin' ),
						$saw_tier ? $saw_tier['label'] : '',
						$saw_tier ? $saw_tier['multiplier'] : 1,
						sprintf(
							/* translators: %s: number of runs */
							_n( '%s run', '%s runs', $saw_qty, 'nera-strikeawin' ),
							number_format_i18n( $saw_qty )
						)
					)
				);
				?>
			</p>

			<div class="saw-summary__rule"></div>

			<?php
			$saw_facts = array(
				array(
					'label' => __( 'Questions', 'nera-strikeawin' ),
					'value' => $saw_quiz['is_ladder']
						? sprintf(
							/* translators: 1: questions, 2: stages */
							__( '%1$s, in %2$s stages', 'nera-strikeawin' ),
							number_format_i18n( $saw_quiz['questions'] ),
							number_format_i18n( $saw_quiz['stages'] )
						)
						: sprintf(
							/* translators: %s: questions */
							__( '%s, in random order', 'nera-strikeawin' ),
							number_format_i18n( $saw_quiz['questions'] )
						),
				),
				array(
					'label' => __( 'Timer', 'nera-strikeawin' ),
					'value' => sprintf(
						/* translators: %d: seconds */
						__( '%d seconds per question', 'nera-strikeawin' ),
						$saw_spec['timer']['seconds']
					),
				),
			);

			if ( $saw_tier ) {
				$saw_facts[] = array(
					'label' => __( 'Ticket ceiling', 'nera-strikeawin' ),
					'value' => sprintf(
						/* translators: %s: tickets */
						_n( '%s ticket', '%s tickets', $saw_tier['perfect_run'] * $saw_qty, 'nera-strikeawin' ),
						number_format_i18n( $saw_tier['perfect_run'] * $saw_qty )
					),
				);
			}

			if ( $saw_spec['closes_timestamp'] ) {
				$saw_facts[] = array(
					'label' => __( 'Closes', 'nera-strikeawin' ),
					'value' => Nera_SAW_Date::localized( $saw_spec['closes_timestamp'], get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) ),
				);
			}
			?>
			<dl class="saw-summary__facts">
				<?php foreach ( $saw_facts as $saw_fact ) : ?>
					<div class="saw-fact">
						<dt><?php echo esc_html( $saw_fact['label'] ); ?></dt>
						<dd><?php echo esc_html( $saw_fact['value'] ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>

			<div class="saw-summary__rule"></div>

			<p class="saw-summary__risk"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_pp_risk' ) ); ?></p>
		</section>

		<?php
		/*
		 * A GET form to the real checkout URL. Nothing is posted: the basket already
		 * holds the choice, and the browser's own required-field check is what stops
		 * an unticked box from getting past this screen.
		 */
		$saw_checkout = Nera_SAW_Standalone_Basket::checkout_url();
		?>
		<form class="saw-consent" method="get" action="<?php echo esc_url( $saw_checkout ); ?>">

			<?php if ( class_exists( 'Nera_SAW_Language' ) ) { Nera_SAW_Language::hidden_field(); } ?>

			<?php
			/*
			 * Pre-ticked, not pre-answered: an account already carrying a real
			 * age-shield verification (the same source the entry gate itself
			 * trusts — see Nera_SAW_Language_Switcher::age_state()) has already
			 * given this answer once and should not have to tick the identical
			 * box again on every single purchase. Still a real, un-disabled
			 * checkbox the player can untick, and still `required`, so an
			 * unverified account gets exactly the same native browser gate as
			 * before.
			 */
			$saw_age_verified = class_exists( 'Nera_SAW_Language_Switcher' ) && Nera_SAW_Language_Switcher::age_state()['verified'];
			?>
			<label class="saw-check">
				<input type="checkbox" name="saw_confirm_age" value="1" required<?php checked( $saw_age_verified ); ?>>
				<span><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_pp_consent_age' ) ); ?></span>
			</label>

			<label class="saw-check">
				<input type="checkbox" name="saw_confirm_rules" value="1" required>
				<span><?php echo wp_kses_post( Nera_SAW_Standalone_Fields::text( 'saw_pp_consent_rules' ) ); ?></span>
			</label>

			<?php
			$saw_cta = (string) Nera_SAW_Standalone_Fields::text( 'saw_pp_cta' );
			$saw_sum = wp_strip_all_tags( wc_price( $saw_total ) );

			// The label carries the price, so an editor who drops the placeholder must
			// not end up with a button that hides what is about to be charged.
			$saw_label = false !== strpos( $saw_cta, '%s' )
				? sprintf( $saw_cta, $saw_sum )
				: trim( $saw_cta . ' ' . $saw_sum );
			?>
			<button type="submit" class="saw-cta"><?php echo esc_html( $saw_label ); ?></button>

			<?php
			/*
			 * The real gateways, not the design's fixed Card / Apple Pay / Google Pay
			 * chips. Naming a method this site cannot take is a promise broken one
			 * screen later.
			 *
			 * Names only -- see method_labels() for why a gateway title cannot simply
			 * be escaped and printed.
			 */
			$saw_methods = Nera_SAW_Standalone_Basket::method_labels();
			?>
			<?php if ( $saw_methods ) : ?>
				<ul class="saw-methods">
					<?php foreach ( $saw_methods as $saw_method ) : ?>
						<li><?php echo esc_html( $saw_method ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</form>

	<?php endif; ?>

	</div>
</div>

<?php
Nera_SAW_Router::part( 'footer.php' );
