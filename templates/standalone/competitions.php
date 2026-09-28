<?php
/**
 * Standalone section — the competitions list. The section's home page.
 *
 * Every value on a card comes from Nera_SAW_Competition_Spec, so this file asks
 * one question per competition and renders the answer. It does not know that the
 * price came from the tier table, the stock from Lottery for WooCommerce and the
 * stage count from the Quiz Method.
 *
 * Override by copying to `nera-strikeawin/competitions.php` in a theme.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/*
 * Closing soonest first, as the brief asks. `_lty_end_date_gmt` is a string date
 * in a sortable format, so meta_value ordering is correct without a numeric cast.
 *
 * OPT_OUT is the important argument: this is a product query inside the very
 * section whose products are hidden from product queries. Without it the page
 * excludes exactly what it exists to show.
 */
$saw_query = new WP_Query(
	array(
		'post_type'                              => 'product',
		'post_status'                            => 'publish',
		'posts_per_page'                         => 24,
		'meta_key'                               => '_lty_end_date_gmt', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'orderby'                                => 'meta_value',
		'order'                                  => 'ASC',
		'no_found_rows'                          => true,
		Nera_SAW_Catalogue_Isolation::OPT_OUT    => true,
		'meta_query'                             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'   => Nera_SAW_Competition_Config::META_IS_COMP,
				'value' => '1',
			),
		),
	)
);

$saw_specs = array();
foreach ( $saw_query->posts as $saw_post ) {
	$spec = Nera_SAW_Competition_Spec::get( $saw_post->ID );
	if ( $spec ) {
		$saw_specs[] = $spec;
	}
}
wp_reset_postdata();

Nera_SAW_Router::part(
	'header.php',
	array( 'saw_title' => get_the_title() )
);
?>

<?php
/*
 * Copy comes from the page's own ACF fields, each falling back to the prototype's
 * wording. An empty field means "as designed" rather than "blank", so the screen
 * is never accidentally stripped by someone clearing a box to see what it did.
 */
// No defaults passed: they live in Nera_SAW_Standalone_Fields::catalogue(), which
// the editor's placeholders and the seeder read from the same place.
$saw_eyebrow = Nera_SAW_Standalone_Fields::text( 'saw_eyebrow' );
$saw_heading = Nera_SAW_Standalone_Fields::text( 'saw_heading' );
$saw_lede    = Nera_SAW_Standalone_Fields::text( 'saw_lede' );
?>
<div class="saw-intro">
	<p class="saw-eyebrow"><?php echo esc_html( $saw_eyebrow ); ?></p>
	<h1 class="saw-h1"><?php echo wp_kses_post( $saw_heading ); ?></h1>
	<p class="saw-lede"><?php echo esc_html( $saw_lede ); ?></p>
</div>

<?php if ( ! $saw_specs ) : ?>

	<div class="saw-empty">
		<p><?php echo esc_html( Nera_SAW_Standalone_Fields::text( 'saw_empty' ) ); ?></p>
	</div>

<?php else : ?>

	<ul class="saw-cards">
		<?php
		foreach ( $saw_specs as $spec ) :
			$url      = Nera_SAW_Router::url( 'competition', get_post_field( 'post_name', $spec['id'] ) );
			$sold_out = ! empty( $spec['stock']['sold_out'] );
			?>
			<li class="saw-card<?php echo $sold_out ? ' saw-card--sold-out' : ''; ?>">

				<div class="saw-card__media">
					<?php if ( $spec['image']['url'] ) : ?>
						<img src="<?php echo esc_url( $spec['image']['url'] ); ?>"
							alt="<?php echo esc_attr( $spec['image']['alt'] ? $spec['image']['alt'] : $spec['name'] ); ?>"
							loading="lazy" decoding="async">
					<?php endif; ?>

					<?php
					// The low-stock pill is the prototype's urgency cue, and it must
					// not appear alongside "Sold out" — one of them is always wrong.
					if ( $sold_out ) :
						?>
						<span class="saw-card__pill"><?php esc_html_e( 'Sold out', 'nera-strikeawin' ); ?></span>
					<?php elseif ( ! empty( $spec['stock']['low_stock'] ) ) : ?>
						<span class="saw-card__pill">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of entries left */
									__( '%s entries left', 'nera-strikeawin' ),
									number_format_i18n( $spec['stock']['remaining'] )
								)
							);
							?>
						</span>
					<?php endif; ?>

					<span class="saw-card__price">
						<?php
						if ( $sold_out ) {
							esc_html_e( 'Sold out', 'nera-strikeawin' );
						} else {
							/* translators: %s: lowest tier price */
							echo wp_kses_post( sprintf( __( 'From %s', 'nera-strikeawin' ), wc_price( $spec['entry_from'] ) ) );
						}
						?>
					</span>
				</div>

				<div class="saw-card__body">
					<a class="saw-card__title" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $spec['name'] ); ?></a>
					<p class="saw-card__dates">
						<?php
						if ( $spec['closes_timestamp'] ) {
							echo esc_html(
								sprintf(
									/* translators: %s: closing date and time */
									__( 'Closes %s · Drawn automatically', 'nera-strikeawin' ),
									wp_date( 'D j M, ga', $spec['closes_timestamp'] )
								)
							);
						} else {
							esc_html_e( 'Drawn automatically', 'nera-strikeawin' );
						}
						?>
					</p>
				</div>

				<div class="saw-card__perf" aria-hidden="true"></div>

				<div class="saw-card__foot">
					<?php
					/*
					 * One dot per question, coloured by its level. The last dot of the
					 * hardest level is larger — the prototype's way of showing that a
					 * run ends on something worth more.
					 */
					$dots = array();
					foreach ( $spec['quiz']['levels'] as $level ) {
						for ( $i = 0; $i < (int) $level['questions']; $i++ ) {
							$dots[] = $level['colour'];
						}
					}
					if ( $dots ) :
						$last = count( $dots ) - 1;
						?>
						<span class="saw-dots" aria-hidden="true">
							<?php foreach ( $dots as $i => $colour ) : ?>
								<span class="saw-dot<?php echo $i === $last ? ' saw-dot--last' : ''; ?>"
									style="background:<?php echo esc_attr( $colour ); ?>"></span>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>

					<span class="saw-card__shape">
						<?php
						$questions = (int) $spec['quiz']['questions'];
						if ( $spec['quiz']['is_ladder'] ) {
							echo esc_html(
								sprintf(
									/* translators: 1: number of questions, 2: number of stages */
									_n( '%1$s question · %2$s stages', '%1$s questions · %2$s stages', $questions, 'nera-strikeawin' ),
									number_format_i18n( $questions ),
									number_format_i18n( (int) $spec['quiz']['stages'] )
								)
							);
						} else {
							// No stages under the random method — see ADR 0021. Saying
							// otherwise promises a structure the run does not have.
							echo esc_html(
								sprintf(
									/* translators: %s: number of questions */
									_n( '%s question · mixed difficulty', '%s questions · mixed difficulty', $questions, 'nera-strikeawin' ),
									number_format_i18n( $questions )
								)
							);
						}
						?>
					</span>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>

<?php endif; ?>

<?php
// The panel is switchable because an operator may move this copy elsewhere -- but
// it defaults to on, and the field says why turning it off is a compliance call.
$saw_panel_on = Nera_SAW_Standalone_Fields::text( 'saw_panel_show' );
if ( $saw_panel_on ) :
	$saw_panel_heading = Nera_SAW_Standalone_Fields::text( 'saw_panel_heading' );
	$saw_panel_body    = Nera_SAW_Standalone_Fields::text( 'saw_panel_body' );
	?>
	<section class="saw-panel">
		<h2><?php echo esc_html( $saw_panel_heading ); ?></h2>
		<p>
			<?php echo esc_html( $saw_panel_body ); ?>
			<?php
			// The design puts the route out of this panel and into the explanation,
			// which is the one place a reader who is unsure has already stopped.
			printf(
				' <a href="%1$s">%2$s</a>',
				esc_url( Nera_SAW_Router::url( 'how-it-works' ) ),
				esc_html( Nera_SAW_Standalone_Fields::shell( 'saw_header_link' ) )
			);
			?>
		</p>
	</section>
<?php endif; ?>

<?php Nera_SAW_Router::part( 'footer.php' ); ?>
