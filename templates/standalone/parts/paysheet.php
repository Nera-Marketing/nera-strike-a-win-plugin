<?php
/**
 * Standalone section — the pay sheet body: the basket's entry, its spec, the one
 * consent tick and Pay.
 *
 * Drawn by Before you pay, and inside the sheet that opens over the competition
 * page after Continue (`$saw_sheet`). The sheet copy arrives in the add-to-cart
 * response, never in the page HTML, so a cached competition page holds no basket
 * or account data.
 *
 * Expects: `$saw_entry` (Nera_SAW_Standalone_Basket::current_entry()), `$saw_sheet`
 * (bool).
 *
 * Override by copying to `nera-strikeawin/parts/paysheet.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_sheet = ! empty( $saw_sheet );
$saw_entry = isset( $saw_entry ) ? $saw_entry : null;

// The copy belongs to the Before you pay page, wherever the body is drawn.
$saw_pp = (int) Nera_SAW_Standalone_Pages::page_id( Nera_SAW_Standalone_Basket::ROUTE );
?>

<?php if ( $saw_sheet ) : ?>
<div class="saw-paysheet" role="dialog" aria-modal="true" aria-labelledby="saw-paysheet-title">
	<div class="saw-paysheet__head">
		<h2 class="saw-paysheet__title" id="saw-paysheet-title"><?php echo wp_kses_post( Nera_SAW_Standalone_Fields::text( 'saw_pp_heading', null, $saw_pp ) ); ?></h2>
		<button type="button" class="saw-paysheet__close" data-saw-sheet-close aria-label="<?php esc_attr_e( 'Close', 'nera-strikeawin' ); ?>">&times;</button>
	</div>
<?php endif; ?>

<?php if ( ! $saw_entry ) : ?>

	<div class="saw-empty">
		<p><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_pp_empty', null, $saw_pp ) ); ?></p>
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
				'label' => __( 'Most this run', 'nera-strikeawin' ),
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
				'value' => Nera_SAW_Date::display( $saw_spec['closes_timestamp'] ),
			);
		}
		?>
		<?php if ( Nera_SAW_Checkout::show_summary_facts( (int) $saw_spec['id'] ) ) : ?>
			<dl class="saw-summary__facts">
				<?php foreach ( $saw_facts as $saw_fact ) : ?>
					<div class="saw-fact">
						<dt><?php echo esc_html( $saw_fact['label'] ); ?></dt>
						<dd><?php echo esc_html( $saw_fact['value'] ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		<?php endif; ?>

		<div class="saw-summary__rule"></div>

		<p class="saw-summary__risk"><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_pp_risk', null, $saw_pp ) ); ?></p>
	</section>

	<?php
	/*
	 * A GET form to the real checkout URL. Nothing is posted: the basket already
	 * holds the choice, and the browser's own required-field check is what stops
	 * an unticked box from getting past this screen.
	 */
	$saw_checkout = Nera_SAW_Standalone_Basket::checkout_url();

	// Sent back from checkout because a box was not ticked (no script, or script failed).
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$saw_missing = ! $saw_sheet && isset( $_GET[ Nera_SAW_Checkout::ARG_MISSING ] );
	?>
	<form class="saw-consent" method="get" action="<?php echo esc_url( $saw_checkout ); ?>" novalidate data-saw-consent<?php echo $saw_sheet ? ' data-saw-pay-step-current="1"' : ''; ?>>

		<?php if ( class_exists( 'Nera_SAW_Language' ) ) { Nera_SAW_Language::hidden_field(); } ?>
		<input type="hidden" name="<?php echo esc_attr( Nera_SAW_Checkout::ARG_CHECKED ); ?>" value="1">

		<?php if ( $saw_sheet ) : ?>
			<?php
			// What placing the order from the sheet will send. Disabled, so the GET to
			// checkout carries none of it (an email address does not belong in a URL).
			?>
			<fieldset class="saw-paysheet__post" data-saw-pay-post="<?php echo esc_url( WC_AJAX::get_endpoint( 'checkout' ) ); ?>" disabled hidden>
				<input type="hidden" name="saw_basket" value="1">
				<input type="hidden" name="<?php echo esc_attr( Nera_SAW_Checkout::ARG_HASH ); ?>" value="<?php echo esc_attr( Nera_SAW_Checkout::basket_hash() ); ?>">
				<?php if ( is_user_logged_in() ) { wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce', false ); } ?>
			</fieldset>
			<?php // The billing fields Pay sends, prefilled from the account; WooCommerce saves what is typed to it. ?>
			<fieldset class="saw-paysheet__fields" data-saw-pay-fields data-saw-pay-step="1">
				<?php foreach ( Nera_SAW_Standalone_Basket::pay_sheet_fields( (int) $saw_spec['id'] ) as $saw_field ) : ?>
					<?php
					$saw_fid = 'saw-pay-' . str_replace( '_', '-', $saw_field['name'] );
					// WooCommerce's messages for this field, shown when Pay on step 1 finds it wrong.
					$saw_ferr = ' data-saw-err-required="' . esc_attr( $saw_field['errors']['required'] ) . '"' . ( '' !== $saw_field['errors']['email'] ? ' data-saw-err-email="' . esc_attr( $saw_field['errors']['email'] ) . '"' : '' );
					?>
					<div class="saw-field">
						<label for="<?php echo esc_attr( $saw_fid ); ?>"><?php echo esc_html( $saw_field['label'] ); ?></label>
						<?php if ( 'select' === $saw_field['type'] ) : ?>
							<select id="<?php echo esc_attr( $saw_fid ); ?>" name="<?php echo esc_attr( $saw_field['name'] ); ?>" autocomplete="<?php echo esc_attr( $saw_field['autocomplete'] ); ?>"<?php echo $saw_field['required'] ? ' required' : ''; ?><?php echo $saw_ferr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
								<?php foreach ( $saw_field['options'] as $saw_code => $saw_name ) : ?>
									<option value="<?php echo esc_attr( $saw_code ); ?>"<?php selected( $saw_field['value'], (string) $saw_code ); ?>><?php echo esc_html( html_entity_decode( $saw_name, ENT_QUOTES, 'UTF-8' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="<?php echo esc_attr( $saw_field['type'] ); ?>" id="<?php echo esc_attr( $saw_fid ); ?>" name="<?php echo esc_attr( $saw_field['name'] ); ?>" value="<?php echo esc_attr( $saw_field['value'] ); ?>" autocomplete="<?php echo esc_attr( $saw_field['autocomplete'] ); ?>"<?php echo 'email' === $saw_field['type'] ? ' inputmode="email"' : ''; ?><?php echo $saw_field['required'] ? ' required' : ''; ?><?php echo $saw_ferr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</fieldset>
			<?php
			// The methods ticked for the section checkout, as rows: which can be chosen, the wallet
			// figures and each one's info all come from Nera_SAW_Standalone_Basket::pay_sheet_rows().
			// Each says how Pay takes it: posted from here, or on the checkout page.
			$saw_pay_rows   = Nera_SAW_Standalone_Basket::pay_sheet_rows();
			$saw_pay_first  = Nera_SAW_Standalone_Basket::pay_sheet_gateway( $saw_pay_rows );
			$saw_pay_notice = Nera_SAW_Standalone_Basket::pay_sheet_notice();
			$saw_pay_methods = wp_list_pluck( $saw_pay_rows, 'title', 'id' );
			?>
			<?php // Step 2, in the fields' place once Pay on step 1 finds them complete. ?>
			<div class="saw-paysheet__step" data-saw-pay-step="2" hidden>
				<?php if ( $saw_pay_rows ) : ?>
					<?php $saw_pay_choice = count( $saw_pay_rows ) > 1; ?>
					<<?php echo $saw_pay_choice ? 'fieldset' : 'div tabindex="-1"'; ?> class="saw-paysheet__methods" data-saw-pay-methods>
						<?php if ( $saw_pay_choice ) : ?>
							<legend><?php esc_html_e( 'Pay with', 'nera-strikeawin' ); ?></legend>
						<?php else : ?>
							<p class="saw-paysheet__legend"><?php esc_html_e( 'Pay with', 'nera-strikeawin' ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $saw_pay_notice ) : ?>
							<div class="saw-paysheet__notice" data-saw-pay-notice><?php echo wp_kses_post( $saw_pay_notice ); ?></div>
						<?php endif; ?>
						<?php foreach ( $saw_pay_rows as $saw_pm ) : ?>
							<?php $saw_pm_on = $saw_pm['id'] === $saw_pay_first; ?>
							<div class="saw-method<?php echo $saw_pay_choice ? '' : ' saw-method--only'; ?><?php echo $saw_pm['disabled'] ? ' is-off' : ''; ?>" data-saw-method="<?php echo esc_attr( $saw_pm['id'] ); ?>">
								<<?php echo $saw_pay_choice ? 'label' : 'div'; ?> class="saw-method__row">
									<?php if ( $saw_pay_choice ) : ?>
										<input type="radio" name="payment_method" value="<?php echo esc_attr( $saw_pm['id'] ); ?>" data-saw-pay-mode="<?php echo esc_attr( $saw_pm['mode'] ); ?>"<?php checked( $saw_pm_on ); ?><?php disabled( $saw_pm['disabled'] ); ?>>
									<?php else : ?>
										<input type="hidden" name="payment_method" value="<?php echo esc_attr( $saw_pm['id'] ); ?>" data-saw-pay-mode="<?php echo esc_attr( $saw_pm['mode'] ); ?>">
									<?php endif; ?>
									<span class="saw-method__icon"><?php echo $saw_pm['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG from the builder. ?></span>
									<span class="saw-method__title"><?php echo esc_html( $saw_pm['title'] ); ?></span>
									<?php if ( '' !== $saw_pm['brand'] ) : ?>
										<span class="saw-method__brand"><?php echo wp_kses_post( $saw_pm['brand'] ); ?></span>
									<?php endif; ?>
								</<?php echo $saw_pay_choice ? 'label' : 'div'; ?>>
								<?php if ( '' !== $saw_pm['note'] ) : ?>
									<div class="saw-method__note"><?php echo wp_kses_post( $saw_pm['note'] ); ?></div>
								<?php endif; ?>
								<?php if ( '' !== $saw_pm['info'] ) : ?>
									<div class="saw-method__info" data-saw-method-info<?php echo $saw_pm_on ? '' : ' hidden'; ?>><?php echo wp_kses_post( $saw_pm['info'] ); ?></div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</<?php echo $saw_pay_choice ? 'fieldset' : 'div'; ?>>
				<?php endif; ?>
				<button type="button" class="saw-paysheet__back" data-saw-pay-back><?php esc_html_e( 'Back', 'nera-strikeawin' ); ?></button>
			</div>
		<?php endif; ?>

		<label class="saw-check">
			<input type="checkbox" name="saw_confirm_age" value="1" autocomplete="off" aria-describedby="saw-consent-msg"<?php echo $saw_missing ? ' aria-invalid="true"' : ''; ?>>
			<span><?php echo wp_kses_post( Nera_SAW_Standalone_Fields::text( 'saw_pp_consent_age', null, $saw_pp ) ); ?></span>
		</label>

		<?php
		$saw_cta = (string) Nera_SAW_Standalone_Fields::text( 'saw_pp_cta', null, $saw_pp );
		$saw_sum = wp_strip_all_tags( wc_price( $saw_total ) );

		// The label carries the price, so an editor who drops the placeholder must
		// not end up with a button that hides what is about to be charged.
		$saw_label = false !== strpos( $saw_cta, '%s' )
			? sprintf( $saw_cta, $saw_sum )
			: trim( $saw_cta . ' ' . $saw_sum );
		?>
		<button type="submit" class="saw-cta"><?php echo esc_html( $saw_label ); ?></button>
		<p class="saw-consent__msg" id="saw-consent-msg" role="alert"<?php echo $saw_missing ? '' : ' hidden'; ?>><?php esc_html_e( 'Tick the box to continue', 'nera-strikeawin' ); ?></p>
		<?php if ( $saw_sheet ) : ?>
			<div class="saw-paysheet__errors" data-saw-pay-errors data-saw-fallback="<?php esc_attr_e( 'Something went wrong. Please try again.', 'nera-strikeawin' ); ?>" role="alert" hidden></div>
		<?php endif; ?>

		<?php
		/*
		 * The real gateways, not the design's fixed Card / Apple Pay / Google Pay
		 * chips. Naming a method this site cannot take is a promise broken one
		 * screen later.
		 *
		 * Names only -- see method_labels() for why a gateway title cannot simply
		 * be escaped and printed.
		 */
		// In the sheet, step 2 names the methods.
		$saw_methods = ( $saw_sheet && ! empty( $saw_pay_methods ) ) ? array() : Nera_SAW_Standalone_Basket::method_labels();
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

<?php if ( $saw_sheet ) : ?>
</div>
<?php endif; ?>
