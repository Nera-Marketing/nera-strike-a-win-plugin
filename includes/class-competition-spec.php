<?php
/**
 * The competition data contract — one shape, assembled once.
 *
 * Every screen that describes a competition needs the same facts: what it is
 * worth, when it closes, how many entries are left, what the quiz looks like and
 * what each tier costs. Those facts live in four different systems — this plugin's
 * config, the difficulty ladder, Lottery for WooCommerce, and WooCommerce itself —
 * and before this each screen fetched them for itself.
 *
 * This is the handover document's "Data contract" section made real, so a template
 * asks one question and gets an answer it can render without knowing which system
 * any of it came from.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Competition_Spec
 */
class Nera_SAW_Competition_Spec {

	/**
	 * Below this fraction of the pool remaining, a competition reads as low stock.
	 * The prototype's "Entries left" pill appears at ten percent.
	 */
	const LOW_STOCK_FRACTION = 0.10;

	/**
	 * The five brand colours the prototype uses for difficulty, easiest first.
	 *
	 * Deliberately by POSITION in the ladder rather than by each level's own colour.
	 * The ladder's colours are an admin convenience — the circle in the question
	 * list — and an administrator may set them to anything. The front end needs a
	 * ramp that always reads as ascending difficulty, so it owns its own.
	 *
	 * @return string[]
	 */
	public static function ramp() {
		return apply_filters(
			'nera_saw_difficulty_ramp',
			array( '#3f7fb8', '#6a5099', '#a1418a', '#c4483f', '#eb580c' )
		);
	}

	/**
	 * The whole contract for one competition.
	 *
	 * @param int $competition_id Competition product ID.
	 * @return array|null Null when the product is not a competition.
	 */
	public static function get( $competition_id ) {
		$competition_id = (int) $competition_id;
		if ( ! Nera_SAW_Competition_Config::is_competition( $competition_id ) ) {
			return null;
		}

		$product = wc_get_product( $competition_id );
		if ( ! $product ) {
			return null;
		}

		$config = Nera_SAW_Competition_Config::get( $competition_id );

		$spec = array(
			'id'               => $competition_id,
			'name'             => $product->get_name(),
			'permalink'        => get_permalink( $competition_id ),
			'image'            => self::image( $competition_id ),
			'prize_value'      => (float) $product->get_price(),
			'cash_alternative' => isset( $config['cash_alternative'] ) ? (string) $config['cash_alternative'] : '',
			'stock'            => self::stock( $competition_id ),
			'timer'            => self::timer( $config ),
			'quiz'             => self::quiz( $config, $competition_id ),
			'tiers'            => self::tiers( $config, $competition_id ),
		);

		$spec = array_merge( $spec, self::dates( $competition_id ) );
		$spec['entry_from'] = self::entry_from( $spec['tiers'] );
		$spec['open']       = ! $spec['stock']['sold_out'] && ! $spec['closed'] && ! empty( $spec['tiers'] );

		/**
		 * Filter the assembled competition spec.
		 *
		 * @param array $spec           The contract.
		 * @param int   $competition_id Competition product ID.
		 */
		return apply_filters( 'nera_saw_competition_spec', $spec, $competition_id );
	}

	/* ---------------------------------------------------------------------
	 * Parts
	 * ------------------------------------------------------------------ */

	/**
	 * Featured image, at a size a card can use.
	 *
	 * @param int $competition_id Product ID.
	 * @return array { url, alt }
	 */
	private static function image( $competition_id ) {
		$id  = get_post_thumbnail_id( $competition_id );
		$url = $id ? wp_get_attachment_image_url( $id, 'large' ) : '';
		return array(
			'url' => $url ? $url : '',
			'alt' => $id ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : '',
		);
	}

	/**
	 * Entries: how many exist, how many are gone, how many are left.
	 *
	 * The pool is Lottery for WooCommerce's ticket stock. `won` counts tickets this
	 * plugin has actually minted — the ones players earned — which is what the
	 * remaining figure must be measured against, since nothing else consumes the
	 * pool.
	 *
	 * @param int $competition_id Product ID.
	 * @return array
	 */
	private static function stock( $competition_id ) {
		$total = (int) Nera_SAW_Competition_Config::lfw_stock( $competition_id );
		$won   = (int) Nera_SAW_Competition_Config::tickets_won_total( $competition_id );

		// The pool row also tracks what is reserved against runs in progress. A
		// reserved ticket is not won, but it is not available either, so a display
		// that ignores it can promise entries that are already spoken for.
		$pool     = Nera_SAW_Spin_Pool::get( $competition_id );
		$reserved = $pool && isset( $pool->reserved ) ? (int) $pool->reserved : 0;

		$remaining = max( 0, $total - $won - $reserved );

		return array(
			'total'     => $total,
			'won'       => $won,
			'reserved'  => $reserved,
			'remaining' => $remaining,
			'sold_out'  => $total > 0 && $remaining < 1,
			'low_stock' => $total > 0 && $remaining > 0 && $remaining <= (int) ceil( $total * self::LOW_STOCK_FRACTION ),
		);
	}

	/**
	 * When entries close, and when the draw happens.
	 *
	 * Lottery for WooCommerce holds one date, the end date, and the draw follows it
	 * automatically — there is no separate draw date to read. `draw_is_automatic`
	 * says so explicitly, so a template renders "drawn automatically" rather than
	 * inventing a second date that nothing stores.
	 *
	 * @param int $competition_id Product ID.
	 * @return array
	 */
	private static function dates( $competition_id ) {
		$end_gmt = (string) get_post_meta( $competition_id, '_lty_end_date_gmt', true );
		$status  = (string) get_post_meta( $competition_id, '_lty_lottery_status', true );

		$closes_ts = $end_gmt ? (int) strtotime( $end_gmt . ' UTC' ) : 0;

		return array(
			'closes_at'         => $closes_ts ? gmdate( 'c', $closes_ts ) : '',
			'closes_timestamp'  => $closes_ts,
			'draw_is_automatic' => true,
			'lottery_status'    => $status,
			'closed'            => $closes_ts > 0 && $closes_ts < time(),
		);
	}

	/**
	 * Timer: seconds per question, and when the countdown turns red.
	 *
	 * @param array $config Competition config.
	 * @return array
	 */
	private static function timer( array $config ) {
		return array(
			'seconds'   => (int) Nera_SAW_Constants::clamp_timer(
				isset( $config['timer_seconds'] ) ? $config['timer_seconds'] : Nera_SAW_Constants::TIMER_MAX_SECONDS
			),
			'red_below' => (int) Nera_SAW_Constants::timer_warn_seconds(),
		);
	}

	/**
	 * The shape of a run: its levels, and whether they form stages.
	 *
	 * `stages` is deliberately zero under the random method. A stage is a run of
	 * consecutive questions at one level, and random order has none — see ADR 0021.
	 * A template that prints "N questions · M stages" must check this rather than
	 * counting levels, or it promises a structure the run does not have.
	 *
	 * @param array $config         Competition config.
	 * @param int   $competition_id Product ID.
	 * @return array
	 */
	private static function quiz( array $config, $competition_id ) {
		$distribution = isset( $config['distribution'] ) ? (array) $config['distribution'] : array();
		$ramp         = self::ramp();
		$is_ladder    = Nera_SAW_Mode::is_ladder( $competition_id );

		$levels = array();
		$index  = 0;
		foreach ( Nera_SAW_Constants::ladder() as $level ) {
			$key   = $level['key'];
			$count = isset( $distribution[ $key ] ) ? (int) $distribution[ $key ] : 0;
			if ( $count < 1 ) {
				continue;
			}
			$levels[] = array(
				'key'       => $key,
				'label'     => (string) $level['label'],
				'rank'      => (int) $level['rank'],
				'questions' => $count,
				'tickets'   => (int) Nera_SAW_Competition_Config::effective_reward( $config, $key ),
				'colour'    => isset( $ramp[ $index ] ) ? $ramp[ $index ] : end( $ramp ),
			);
			$index++;
		}

		return array(
			'method'    => Nera_SAW_Mode::quiz_method( $competition_id ),
			'is_ladder' => $is_ladder,
			'questions' => (int) Nera_SAW_Competition_Config::total_questions( $config ),
			'stages'    => $is_ladder ? count( $levels ) : 0,
			'levels'    => $levels,
		);
	}

	/**
	 * The tiers a player may enter at, in order, with what each is worth.
	 *
	 * `perfect_run` is every question answered correctly at that tier's multiplier —
	 * the "Most this run" figure on the pre-payment summary. A tier the ceiling has
	 * closed is dropped entirely rather than shown greyed out (ADR: tier ceiling).
	 *
	 * @param array $config         Competition config.
	 * @param int   $competition_id Product ID.
	 * @return array
	 */
	private static function tiers( array $config, $competition_id ) {
		$out = array();
		foreach ( Nera_SAW_Competition_Config::effective_tiers( $config ) as $tier ) {
			$key = (string) $tier['key'];
			if ( ! Nera_SAW_Competition_Config::tier_offered( $config, $competition_id, $key ) ) {
				continue;
			}
			$status = Nera_SAW_Competition_Config::tier_ceiling_status( $config, $competition_id, $key );

			$out[] = array(
				'key'         => $key,
				'label'       => (string) $tier['label'],
				'price'       => (float) $tier['price'],
				'multiplier'  => (int) $tier['multiplier'],
				'status'      => isset( $status['status'] ) ? (string) $status['status'] : 'green',
				'perfect_run' => (int) Nera_SAW_Competition_Config::max_possible_spins( $config, $key ),
			);
		}
		return $out;
	}

	/**
	 * The lowest price on offer — what "From £N" means.
	 *
	 * @param array $tiers Resolved tiers.
	 * @return float 0.0 when nothing is offered.
	 */
	private static function entry_from( array $tiers ) {
		$prices = wp_list_pluck( $tiers, 'price' );
		return $prices ? (float) min( $prices ) : 0.0;
	}
}
