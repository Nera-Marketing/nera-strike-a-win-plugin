<?php
/**
 * My Account — "My runs & tickets" (client findings #2/#45/#46).
 *
 * Per competition this player has touched at all: runs still in progress
 * (Continue), runs ready to play (still in the balance, never started),
 * completed runs with the tickets and entry numbers they earned, and the
 * draw's own closing date — the one place a player can check what they
 * hold in a draw once the result screen has been left, which until now was
 * nowhere.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_user_id = get_current_user_id();
if ( ! $saw_user_id ) {
	return;
}

$saw_runs    = class_exists( 'Nera_SAW_Run' ) ? Nera_SAW_Run::runs_for_user( $saw_user_id ) : array();
$saw_balance = class_exists( 'Nera_SAW_Run_Grants' ) ? Nera_SAW_Run_Grants::balance_by_competition( $saw_user_id ) : array();

// Index runs_for_user()'s list form by competition_id so it can be merged
// with the balance array below — a competition can appear in either, or
// both (balance left AND history on it), never neither.
$saw_by_competition = array();
foreach ( $saw_runs as $saw_row ) {
	$saw_by_competition[ $saw_row['competition_id'] ] = $saw_row;
}

$saw_competition_ids = array_unique(
	array_merge( array_keys( $saw_by_competition ), array_keys( $saw_balance ) )
);

if ( empty( $saw_competition_ids ) ) {
	?>
	<p class="saw-runs-empty">
		<?php esc_html_e( 'Your runs, tickets and draw results will appear here.', 'nera-strikeawin' ); ?>
	</p>
	<?php
	return;
}
?>

<div class="saw-runs-tickets">

	<?php foreach ( $saw_competition_ids as $saw_competition_id ) : ?>
		<?php
		$saw_competition_id = (int) $saw_competition_id;
		$saw_entry          = isset( $saw_by_competition[ $saw_competition_id ] )
			? $saw_by_competition[ $saw_competition_id ]
			: array(
				'title'       => get_the_title( $saw_competition_id ),
				'in_progress' => array(),
				'completed'   => array(),
			);
		$saw_ready_tiers = isset( $saw_balance[ $saw_competition_id ]['tiers'] ) ? $saw_balance[ $saw_competition_id ]['tiers'] : array();
		$saw_config      = class_exists( 'Nera_SAW_Competition_Config' ) ? Nera_SAW_Competition_Config::get( $saw_competition_id ) : array();

		$saw_tier_label = static function ( $tier_key ) use ( $saw_config ) {
			if ( ! $saw_config ) {
				return $tier_key;
			}
			$tier = Nera_SAW_Competition_Config::tier( $saw_config, $tier_key );
			return $tier && ! empty( $tier['label'] ) ? (string) $tier['label'] : $tier_key;
		};

		$saw_end_date_gmt = get_post_meta( $saw_competition_id, '_lty_end_date_gmt', true );
		?>

		<section class="saw-runs-tickets__competition">
			<h3 class="saw-runs-tickets__title"><?php echo esc_html( $saw_entry['title'] ); ?></h3>

			<?php if ( $saw_end_date_gmt ) : ?>
				<p class="saw-runs-tickets__draw">
					<?php
					printf(
						/* translators: %s: localized draw date */
						esc_html__( 'Draw: %s', 'nera-strikeawin' ),
						esc_html( Nera_SAW_Date::localized( strtotime( (string) $saw_end_date_gmt ), get_option( 'date_format' ) ) )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $saw_entry['in_progress'] ) ) : ?>
				<div class="saw-runs-tickets__group saw-runs-tickets__group--progress">
					<p class="saw-runs-tickets__group-label"><?php esc_html_e( 'Runs in progress', 'nera-strikeawin' ); ?></p>
					<?php foreach ( $saw_entry['in_progress'] as $saw_run ) : ?>
						<div class="saw-runs-tickets__row">
							<span><?php echo esc_html( $saw_tier_label( $saw_run['tier_key'] ) ); ?></span>
							<a class="saw-runs-tickets__continue" href="<?php echo esc_url( nera_saw_get_play_url( $saw_competition_id, $saw_run['tier_key'] ) ); ?>">
								<?php esc_html_e( 'Continue', 'nera-strikeawin' ); ?>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $saw_ready_tiers ) ) : ?>
				<div class="saw-runs-tickets__group saw-runs-tickets__group--ready">
					<p class="saw-runs-tickets__group-label"><?php esc_html_e( 'Ready to play', 'nera-strikeawin' ); ?></p>
					<?php foreach ( $saw_ready_tiers as $saw_tier_key => $saw_count ) : ?>
						<div class="saw-runs-tickets__row">
							<span>
								<?php
								// Reuses the same "%d runs left on %s" string play.php's own
								// runs-pill already carries (saw_ru_strings()) -- the number
								// first, no Russian noun to decline right after it, is why
								// that one string already works for every count without
								// needing a real three-form plural.
								echo esc_html(
									sprintf(
										/* translators: 1: runs remaining, 2: tier label */
										__( '%d runs left on %s', 'nera-strikeawin' ),
										(int) $saw_count,
										$saw_tier_label( $saw_tier_key )
									)
								);
								?>
							</span>
							<a class="saw-runs-tickets__play" href="<?php echo esc_url( nera_saw_get_play_url( $saw_competition_id, $saw_tier_key ) ); ?>">
								<?php esc_html_e( 'Play quiz', 'nera-strikeawin' ); ?>
							</a>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $saw_entry['completed'] ) ) : ?>
				<div class="saw-runs-tickets__group saw-runs-tickets__group--completed">
					<p class="saw-runs-tickets__group-label"><?php esc_html_e( 'Completed', 'nera-strikeawin' ); ?></p>
					<?php foreach ( $saw_entry['completed'] as $saw_run ) : ?>
						<?php
						$saw_numbers  = $saw_run['ticket_numbers'];
						$saw_visible  = array_slice( $saw_numbers, 0, 6 );
						$saw_extra    = max( 0, count( $saw_numbers ) - 6 );
						?>
						<div class="saw-runs-tickets__completed-run">
							<div class="saw-runs-tickets__row">
								<span><?php echo esc_html( $saw_tier_label( $saw_run['tier_key'] ) ); ?></span>
								<span class="saw-runs-tickets__ticket-count">
									<?php
									// Reuses the "%s ticket"/"%s tickets" pair the competition
									// hero already carries (saw_ru_strings()) rather than a
									// new "%d ticket(s)" pair needing its own translations.
									echo esc_html(
										sprintf(
											/* translators: %d: tickets earned */
											_n( '%s ticket', '%s tickets', count( $saw_numbers ), 'nera-strikeawin' ),
											number_format_i18n( count( $saw_numbers ) )
										)
									);
									?>
								</span>
							</div>
							<?php if ( ! empty( $saw_numbers ) ) : ?>
								<div class="saw-runs-tickets__numbers">
									<?php foreach ( $saw_visible as $saw_num ) : ?>
										<span class="saw-numchip"><?php echo esc_html( $saw_num ); ?></span>
									<?php endforeach; ?>
									<?php if ( $saw_extra > 0 ) : ?>
										<details class="saw-numchip-more">
											<summary class="saw-numchip saw-numchip--more">
												<?php
												printf(
													/* translators: %d: how many more entry numbers there are */
													esc_html__( '+%d more', 'nera-strikeawin' ),
													(int) $saw_extra
												);
												?>
											</summary>
											<div class="saw-numchip-more__panel">
												<?php foreach ( array_slice( $saw_numbers, 6 ) as $saw_num ) : ?>
													<span class="saw-numchip"><?php echo esc_html( $saw_num ); ?></span>
												<?php endforeach; ?>
											</div>
										</details>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		</section>
	<?php endforeach; ?>

</div>
