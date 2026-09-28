<?php
/**
 * Standalone section — the prize hero.
 *
 * Shared by the competition page (before paying) and the play page (while
 * playing), so a player sees the same prize, price and closing date on both —
 * one place decides what an entry is worth, not two copies that can drift.
 *
 * Override by copying to `nera-strikeawin/parts/hero.php` in a theme.
 *
 * NOTHING IS DECLARED IN THIS FILE.
 *
 * @package Nera_Strikeawin
 * @var array $saw_spec Nera_SAW_Competition_Spec::get() output.
 */

defined( 'ABSPATH' ) || exit;

$saw_hero_stock    = $saw_spec['stock'];
$saw_hero_sold_out = ! empty( $saw_hero_stock['sold_out'] );
$saw_hero_date_fmt = get_option( 'date_format' ) . ', ' . get_option( 'time_format' );
?>
<section class="saw-hero">

	<div class="saw-hero__media">
		<?php if ( $saw_spec['image']['url'] ) : ?>
			<img src="<?php echo esc_url( $saw_spec['image']['url'] ); ?>"
				alt="<?php echo esc_attr( $saw_spec['image']['alt'] ? $saw_spec['image']['alt'] : $saw_spec['name'] ); ?>"
				decoding="async">
		<?php endif; ?>

		<?php if ( $saw_hero_sold_out ) : ?>
			<span class="saw-card__pill"><?php esc_html_e( 'Sold out', 'nera-strikeawin' ); ?></span>
		<?php elseif ( ! empty( $saw_hero_stock['low_stock'] ) ) : ?>
			<span class="saw-card__pill">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: entries remaining */
						__( 'Only %s left', 'nera-strikeawin' ),
						number_format_i18n( $saw_hero_stock['remaining'] )
					)
				);
				?>
			</span>
		<?php endif; ?>

		<?php if ( $saw_spec['entry_from'] > 0 ) : ?>
			<span class="saw-card__price">
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: lowest entry price */
						__( 'From %s', 'nera-strikeawin' ),
						wc_price( $saw_spec['entry_from'] )
					)
				);
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php
	/*
	 * "Draw" says "automatically after close" rather than a date, because Lottery
	 * for WooCommerce holds no separate draw date -- inventing one would put a
	 * time on the screen that nothing in the system honours.
	 */
	$saw_hero_facts = array();

	if ( $saw_spec['closes_timestamp'] ) {
		$saw_hero_facts[] = array(
			'label' => __( 'Closes', 'nera-strikeawin' ),
			'value' => wp_date( $saw_hero_date_fmt, $saw_spec['closes_timestamp'] ),
		);
	}

	if ( ! empty( $saw_spec['draw_is_automatic'] ) ) {
		$saw_hero_facts[] = array(
			'label' => __( 'Draw', 'nera-strikeawin' ),
			'value' => __( 'Automatic, once closed', 'nera-strikeawin' ),
		);
	}

	if ( '' !== $saw_spec['cash_alternative'] ) {
		$saw_hero_facts[] = array(
			'label' => __( 'Cash alternative', 'nera-strikeawin' ),
			'value' => $saw_spec['cash_alternative'],
		);
	}

	if ( $saw_hero_stock['total'] > 0 ) {
		$saw_hero_facts[] = array(
			'label' => __( 'Entries left', 'nera-strikeawin' ),
			'value' => sprintf(
				/* translators: 1: entries remaining, 2: entries in total */
				__( '%1$s of %2$s', 'nera-strikeawin' ),
				number_format_i18n( $saw_hero_stock['remaining'] ),
				number_format_i18n( $saw_hero_stock['total'] )
			),
		);
	}
	?>
	<?php if ( $saw_hero_facts ) : ?>
		<dl class="saw-hero__facts">
			<?php foreach ( $saw_hero_facts as $saw_hero_fact ) : ?>
				<div class="saw-fact">
					<dt><?php echo esc_html( $saw_hero_fact['label'] ); ?></dt>
					<dd><?php echo esc_html( $saw_hero_fact['value'] ); ?></dd>
				</div>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>

	<p class="saw-hero__terms">
		<?php
		$saw_hero_terms = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'terms' ) : '';
		if ( $saw_hero_terms ) :
			?>
			<a href="<?php echo esc_url( $saw_hero_terms ); ?>"><?php esc_html_e( 'Full terms &amp; conditions', 'nera-strikeawin' ); ?></a>
			&middot;
		<?php endif; ?>
		<?php esc_html_e( 'Random draw, independently witnessed', 'nera-strikeawin' ); ?>
	</p>
</section>
