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
		<dl class="saw-summary__facts">
			<?php foreach ( $saw_facts as $saw_fact ) : ?>
				<div class="saw-fact">
					<dt><?php echo esc_html( $saw_fact['label'] ); ?></dt>
					<dd><?php echo esc_html( $saw_fact['value'] ); ?></dd>
				</div>
			<?php endforeach; ?>
		</dl>

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
	<form class="saw-consent" method="get" action="<?php echo esc_url( $saw_checkout ); ?>" novalidate data-saw-consent>

		<?php if ( class_exists( 'Nera_SAW_Language' ) ) { Nera_SAW_Language::hidden_field(); } ?>
		<input type="hidden" name="<?php echo esc_attr( Nera_SAW_Checkout::ARG_CHECKED ); ?>" value="1">

		<?php if ( $saw_sheet ) : ?>
			<?php
			// What placing the order from the sheet will send. Disabled, so the GET to
			// checkout carries none of it (an email address does not belong in a URL).
			?>
			<fieldset class="saw-paysheet__post" data-saw-pay-post disabled hidden>
				<input type="hidden" name="payment_method" value="<?php echo esc_attr( Nera_SAW_Standalone_Basket::pay_sheet_gateway() ); ?>">
				<?php foreach ( Nera_SAW_Standalone_Basket::pay_sheet_billing() as $saw_key => $saw_value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $saw_key ); ?>" value="<?php echo esc_attr( $saw_value ); ?>">
				<?php endforeach; ?>
			</fieldset>
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

<?php if ( $saw_sheet ) : ?>
</div>
<?php endif; ?>
