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
 * Whether this player won anything checks TWO sources, since a competition
 * can have been drawn either way:
 *   - lottery-for-woocommerce's own `lty_lottery_winner` post type
 *     (post_parent = the competition, meta `lty_user_id` = the winner) —
 *     created by that plugin's own `LTY_Lottery_Winner::handle_lottery_
 *     winner()`. Read-only, never written, from this plugin.
 *   - This plugin's own offline-draw prize table (`Nera_SAW_Draw_Prizes`,
 *     client follow-up): a competition drawn BY HAND outside the system
 *     never gets an `lty_lottery_winner` post at all, so Draw results has
 *     to also check the admin-entered prize numbers against this player's
 *     own ticket numbers directly.
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

	// Client follow-up: the row showed the outcome but not what was actually
	// played -- how many runs this competition's balance was spent on, and
	// how many tickets came out of them.
	$saw_runs_played   = count( $saw_entry['completed'] );
	$saw_tickets_total = 0;
	$saw_my_numbers    = array();
	foreach ( $saw_entry['completed'] as $saw_completed_run ) {
		$saw_tickets_total += count( $saw_completed_run['ticket_numbers'] );
		$saw_my_numbers     = array_merge( $saw_my_numbers, $saw_completed_run['ticket_numbers'] );
	}

	// Offline-draw prizes (client follow-up): which of this player's own
	// numbers match a configured prize on this competition.
	$saw_prize_wins = array();
	if ( class_exists( 'Nera_SAW_Draw_Prizes' ) ) {
		$saw_comp_config = Nera_SAW_Competition_Config::get( $saw_competition_id );
		$saw_prize_wins  = Nera_SAW_Draw_Prizes::my_wins(
			isset( $saw_comp_config['prizes'] ) && is_array( $saw_comp_config['prizes'] ) ? $saw_comp_config['prizes'] : array(),
			$saw_my_numbers
		);
	}

	$saw_draw_rows[] = array(
		'competition_id' => $saw_competition_id,
		'title'          => $saw_entry['title'],
		'finished_date'  => $saw_finished_date,
		'won'            => ! empty( $saw_prizes ) || ! empty( $saw_prize_wins ),
		'prizes'         => $saw_prizes,
		'prize_wins'     => $saw_prize_wins,
		'runs_played'    => $saw_runs_played,
		'tickets_total'  => $saw_tickets_total,
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

			<p class="saw-draw-results__stats">
				<?php
				// Reuses the "%s run"/"%s runs" and "%s ticket"/"%s tickets"
				// pairs before-you-pay and "My runs & tickets" already carry
				// (saw_ru_strings()) rather than minting a new pair here.
				echo esc_html(
					sprintf(
						/* translators: %s: number of runs played */
						_n( '%s run', '%s runs', $saw_row['runs_played'], 'nera-strikeawin' ),
						number_format_i18n( $saw_row['runs_played'] )
					)
				);
				echo ' &middot; '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
				echo esc_html(
					sprintf(
						/* translators: %s: tickets earned */
						_n( '%s ticket', '%s tickets', $saw_row['tickets_total'], 'nera-strikeawin' ),
						number_format_i18n( $saw_row['tickets_total'] )
					)
				);
				?>
			</p>

			<?php if ( ! empty( $saw_row['prize_wins'] ) ) : ?>
				<ul class="saw-draw-results__prizes">
					<?php foreach ( $saw_row['prize_wins'] as $saw_win ) : ?>
						<li class="saw-draw-results__prize">
							<span class="saw-draw-results__prize-name"><?php echo esc_html( $saw_win['name'] ); ?></span>
							<span class="saw-draw-results__prize-numbers">
								<?php
								printf(
									/* translators: %s: comma-separated winning entry numbers */
									esc_html__( 'Entry %s', 'nera-strikeawin' ),
									esc_html( implode( ', ', $saw_win['ticket_numbers'] ) )
								);
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( $saw_row['won'] ) : ?>
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
