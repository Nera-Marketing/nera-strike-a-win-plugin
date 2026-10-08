<?php
/**
 * My Account — "Draw results" (client findings #2/#45/#46).
 *
 * Only the draws this player actually entered — a competition they have at
 * least one finalized run on — and only once that competition's own draw
 * has actually happened (`lty_lottery_status === 'lty_lottery_finished'`,
 * the sibling lottery-for-woocommerce plugin's own status meta, read, never
 * written, from this plugin). A competition still open shows nothing here
 * yet; "My runs & tickets" is where an open competition's own tickets live
 * until then.
 *
 * Whether this player won anything is lottery-for-woocommerce's own
 * `lty_lottery_winner` post type (post_parent = the competition, meta
 * `lty_user_id` = the winner) — created by that plugin's own
 * `LTY_Lottery_Winner::handle_lottery_winner()` when a draw closes. Reading
 * it here is the same kind of read-only, filter-free reuse this plugin
 * already does elsewhere for a sibling plugin's own data (e.g.
 * `Nera_DCMS_Storage::is_verified()` for age verification) — never writing
 * to that post type, never duplicating what it already records.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_user_id = get_current_user_id();
if ( ! $saw_user_id ) {
	return;
}

$saw_runs       = class_exists( 'Nera_SAW_Run' ) ? Nera_SAW_Run::runs_for_user( $saw_user_id ) : array();
$saw_draw_rows  = array();

foreach ( $saw_runs as $saw_entry ) {
	if ( empty( $saw_entry['completed'] ) ) {
		continue; // No finalized run on this competition -- never actually entered.
	}

	$saw_competition_id = (int) $saw_entry['competition_id'];
	$saw_status         = get_post_meta( $saw_competition_id, 'lty_lottery_status', true );
	if ( 'lty_lottery_finished' !== $saw_status ) {
		continue; // Drawn yet, or this install has no lottery-for-woocommerce at all.
	}

	$saw_finished_date = get_post_meta( $saw_competition_id, 'lty_finished_date', true );
	$saw_winner_posts   = get_posts(
		array(
			'post_type'      => 'lty_lottery_winner',
			'post_parent'    => $saw_competition_id,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'meta_key'       => 'lty_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $saw_user_id,  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'         => 'ids',
		)
	);

	$saw_prizes = array();
	foreach ( $saw_winner_posts as $saw_winner_post_id ) {
		$saw_gift_ids = get_post_meta( $saw_winner_post_id, 'lty_gift_products', true );
		foreach ( (array) $saw_gift_ids as $saw_gift_id ) {
			$saw_title = get_the_title( (int) $saw_gift_id );
			if ( $saw_title ) {
				$saw_prizes[] = $saw_title;
			}
		}
	}

	$saw_draw_rows[] = array(
		'competition_id' => $saw_competition_id,
		'title'          => $saw_entry['title'],
		'finished_date'  => $saw_finished_date,
		'won'            => ! empty( $saw_prizes ),
		'prizes'         => $saw_prizes,
	);
}

if ( empty( $saw_draw_rows ) ) {
	?>
	<p class="saw-draw-results-empty">
		<?php esc_html_e( 'Your runs, tickets and draw results will appear here.', 'nera-strikeawin' ); ?>
	</p>
	<?php
	return;
}
?>

<div class="saw-draw-results">
	<?php foreach ( $saw_draw_rows as $saw_row ) : ?>
		<section class="saw-draw-results__row">
			<h3 class="saw-draw-results__title"><?php echo esc_html( $saw_row['title'] ); ?></h3>

			<?php if ( $saw_row['finished_date'] ) : ?>
				<p class="saw-draw-results__date">
					<?php
					printf(
						/* translators: %s: localized draw date */
						esc_html__( 'Draw: %s', 'nera-strikeawin' ),
						esc_html( Nera_SAW_Date::localized( strtotime( (string) $saw_row['finished_date'] ), get_option( 'date_format' ) ) )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $saw_row['won'] ) : ?>
				<p class="saw-draw-results__outcome saw-draw-results__outcome--won">
					<?php echo esc_html( implode( ', ', $saw_row['prizes'] ) ); ?>
				</p>
			<?php else : ?>
				<p class="saw-draw-results__outcome saw-draw-results__outcome--none">
					<?php esc_html_e( 'Not this time — but every entry brings you closer. There are always more competitions to enter!', 'nera-strikeawin' ); ?>
				</p>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
