<?php
/**
 * Competition configuration: read/write the per-competition quiz config, the
 * spin math, and the WooCommerce variation sync (one variation per tier).
 *
 * A Competition is a variable WooCommerce product. Its full config lives in the
 * `_saw_config` product meta (the unified Strikeawin panel writes here). Tiers
 * are mirrored to WC variations for native pricing/checkout.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Competition_Config
 */
class Nera_SAW_Competition_Config {

	const META_KEY     = '_saw_config';
	const META_IS_COMP = '_saw_is_competition';

	/**
	 * Default config skeleton.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => true,
			'language'       => 'en',
			'categories'     => array(), // empty = whole bank.
			'quiz_type'      => 'per_question', // per_question (live) | whole_period (hidden, future).
			// Per-competition Quiz Method. Blank = inherit the global setting;
			// resolve through Nera_SAW_Mode::quiz_method(), never read directly.
			'quiz_method'    => Nera_SAW_Mode::INHERIT,
			// Free text, not a number: the prototype prints it verbatim ("£28,000")
			// and different competitions word it differently. Formatting it here
			// would take that choice away from whoever writes the competition.
			'cash_alternative' => '',
			'timer_seconds'  => Nera_SAW_Constants::TIMER_MAX_SECONDS, // per-question (Type 1).
			'total_time'     => 0, // whole-quiz minutes (Type 2, future).
			'distribution'   => array(), // level_key => count.
			'level_rewards'  => array(), // level_key => tickets override.
			'tier_overrides' => array(), // tier_key => { enabled, price, multiplier, ceiling } (blank = inherit global).
		);
	}

	/**
	 * The canonical (global) tier set. Kept as a method for back-compat with
	 * callers; the source of truth is the Settings page.
	 *
	 * @return array
	 */
	public static function default_tiers() {
		return Nera_SAW_Constants::global_tiers();
	}

	/**
	 * Resolve the tiers offered by a competition: the global set, with per-tier
	 * price/multiplier/ceiling overrides applied and disabled tiers dropped.
	 *
	 * @param array $config Stored config (must contain 'tier_overrides').
	 * @return array List of resolved tier arrays.
	 */
	public static function effective_tiers( array $config ) {
		$overrides = isset( $config['tier_overrides'] ) && is_array( $config['tier_overrides'] ) ? $config['tier_overrides'] : array();
		$out       = array();
		foreach ( Nera_SAW_Constants::global_tiers() as $tier ) {
			$key = (string) $tier['key'];
			$ov  = isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ? $overrides[ $key ] : array();

			// Disabled for this competition.
			if ( isset( $ov['enabled'] ) && ! $ov['enabled'] ) {
				continue;
			}

			$out[] = array(
				'key'        => $key,
				'label'      => (string) $tier['label'],
				'price'      => ( isset( $ov['price'] ) && '' !== $ov['price'] && null !== $ov['price'] ) ? (float) $ov['price'] : (float) $tier['price'],
				'multiplier' => ( isset( $ov['multiplier'] ) && '' !== $ov['multiplier'] && null !== $ov['multiplier'] ) ? max( 1, (int) $ov['multiplier'] ) : max( 1, (int) $tier['multiplier'] ),
				'ceiling'    => ( isset( $ov['ceiling'] ) && '' !== $ov['ceiling'] && null !== $ov['ceiling'] ) ? max( 0, (int) $ov['ceiling'] ) : max( 0, (int) $tier['ceiling'] ),
			);
		}
		return $out;
	}

	/**
	 * Read a competition's config (merged over defaults). The resolved tier list
	 * is injected as 'tiers' so downstream readers (run engine, cart, snapshot)
	 * see the effective tiers for this competition.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get( $product_id ) {
		$raw = get_post_meta( (int) $product_id, self::META_KEY, true );
		$cfg = is_array( $raw ) ? $raw : array();
		$cfg = wp_parse_args( $cfg, self::defaults() );
		$cfg['tiers'] = self::effective_tiers( $cfg );
		return $cfg;
	}

	/**
	 * Tickets already WON (confirmed at finalize) via a tier in a competition.
	 *
	 * @param int    $competition_id Competition product ID.
	 * @param string $tier_key       Tier key.
	 * @return int
	 */
	public static function tickets_won_for_tier( $competition_id, $tier_key ) {
		global $wpdb;
		$runs = Nera_SAW_Database::table( 'runs' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(spins_confirmed),0) FROM {$runs} WHERE competition_id = %d AND tier_key = %s AND status = 'finalized'",
				(int) $competition_id,
				(string) $tier_key
			)
		);
	}

	/**
	 * Quiz-earned tickets across all tiers for a competition (authoritative sold count).
	 *
	 * @param int $competition_id Competition product ID.
	 * @return int
	 */
	public static function tickets_won_total( $competition_id ) {
		global $wpdb;
		$runs = Nera_SAW_Database::table( 'runs' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(spins_confirmed),0) FROM {$runs} WHERE competition_id = %d AND status = 'finalized'",
				(int) $competition_id
			)
		);
	}

	/**
	 * Ceiling status for a tier in a competition.
	 *
	 * @param array  $config         Config.
	 * @param int    $competition_id Competition product ID.
	 * @param string $tier_key       Tier key.
	 * @return array { status: green|orange|red, offered: bool, won: int, ceiling: int, remaining: int|null, per_run_max: int }
	 */
	public static function tier_ceiling_status( array $config, $competition_id, $tier_key ) {
		$tier        = self::tier( $config, $tier_key );
		$ceiling     = $tier ? (int) $tier['ceiling'] : 0;
		$per_run_max = self::max_possible_spins( $config, $tier_key );

		if ( $ceiling <= 0 ) {
			return array( 'status' => 'green', 'offered' => true, 'won' => 0, 'ceiling' => 0, 'remaining' => null, 'per_run_max' => $per_run_max );
		}

		$won       = self::tickets_won_for_tier( $competition_id, $tier_key );
		$remaining = $ceiling - $won;

		if ( $remaining <= 0 ) {
			$status = 'red';
		} elseif ( $remaining < $per_run_max ) {
			$status = 'orange';
		} else {
			$status = 'green';
		}

		return array(
			'status'      => $status,
			'offered'     => ( 'green' === $status ), // hidden on orange + red (a fresh perfect run could breach).
			'won'         => $won,
			'ceiling'     => $ceiling,
			'remaining'   => $remaining,
			'per_run_max' => $per_run_max,
		);
	}

	/**
	 * Whether a tier should be offered on the frontend (ceiling gate).
	 *
	 * @param array  $config         Config.
	 * @param int    $competition_id Competition product ID.
	 * @param string $tier_key       Tier key.
	 * @return bool
	 */
	public static function tier_offered( array $config, $competition_id, $tier_key ) {
		$status = self::tier_ceiling_status( $config, $competition_id, $tier_key );
		return (bool) $status['offered'];
	}

	/**
	 * Is this product a Strikeawin competition?
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_competition( $product_id ) {
		return '1' === (string) get_post_meta( (int) $product_id, self::META_IS_COMP, true );
	}

	/**
	 * Persist config and clamp locked values.
	 *
	 * A competition is a lottery (Spin-to-Win) product; tiers are Strikeawin-owned
	 * (stored in config) and are NOT WooCommerce variations — the entry is a
	 * fixed-price cart item (see Nera_SAW_Cart_Entry). Marking the product as a
	 * competition is the caller's responsibility (the "earn via quiz" toggle).
	 *
	 * @param int   $product_id Product ID.
	 * @param array $config     Config array.
	 */
	public static function save( $product_id, array $config ) {
		$product_id = (int) $product_id;
		$config     = wp_parse_args( $config, self::defaults() );

		// 'tiers' is derived (global set + overrides); never persist it.
		unset( $config['tiers'] );

		// Compliance-locked clamps.
		$config['timer_seconds'] = Nera_SAW_Constants::clamp_timer( $config['timer_seconds'] );

		// Blank stays blank: "inherit" is a legal answer here, so the plain
		// sanitiser's fall back to `random` would silently pin every competition.
		$config['quiz_method'] = Nera_SAW_Mode::sanitize_quiz_method_override(
			isset( $config['quiz_method'] ) ? $config['quiz_method'] : Nera_SAW_Mode::INHERIT
		);

		$config['cash_alternative'] = sanitize_text_field(
			isset( $config['cash_alternative'] ) ? (string) $config['cash_alternative'] : ''
		);

		// Drop distribution entries below the difficulty floor.
		$config['distribution'] = self::filter_distribution_by_floor( $config['distribution'] );

		update_post_meta( $product_id, self::META_KEY, $config );

		// The reservation pool cap is the competition's LFW ticket stock (not a
		// Strike A Win field). No-oversell holds against this at checkout-init.
		Nera_SAW_Spin_Pool::ensure( $product_id, self::lfw_stock( $product_id ) );
	}

	/**
	 * Competition ticket cap = LFW maximum tickets.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	public static function lfw_stock( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( $product && method_exists( $product, 'get_lty_maximum_tickets' ) ) {
			return (int) $product->get_lty_maximum_tickets();
		}
		return 0;
	}

	/**
	 * Mark / unmark this product as a Strikeawin quiz competition.
	 *
	 * @param int  $product_id Product ID.
	 * @param bool $is_comp    Whether it earns spins via the quiz.
	 */
	public static function set_competition( $product_id, $is_comp ) {
		if ( $is_comp ) {
			update_post_meta( (int) $product_id, self::META_IS_COMP, '1' );
		} else {
			delete_post_meta( (int) $product_id, self::META_IS_COMP );
		}
	}

	/**
	 * Remove distribution levels whose rank is below the locked floor.
	 *
	 * @param array $distribution level_key => count.
	 * @return array
	 */
	private static function filter_distribution_by_floor( $distribution ) {
		$out = array();
		if ( ! is_array( $distribution ) ) {
			return $out;
		}
		foreach ( $distribution as $level_key => $count ) {
			$level = Nera_SAW_Constants::level( $level_key );
			if ( $level && Nera_SAW_Constants::rank_allowed( $level['rank'] ) && (int) $count > 0 ) {
				$out[ $level_key ] = (int) $count;
			}
		}
		return $out;
	}

	/**
	 * Effective per-correct reward for a level (competition override or global default).
	 *
	 * @param array  $config    Config.
	 * @param string $level_key Level key.
	 * @return int
	 */
	public static function effective_reward( array $config, $level_key ) {
		if ( isset( $config['level_rewards'][ $level_key ] ) && '' !== $config['level_rewards'][ $level_key ] ) {
			return max( 0, (int) $config['level_rewards'][ $level_key ] );
		}
		$level = Nera_SAW_Constants::level( $level_key );
		return $level ? (int) $level['reward'] : 0;
	}

	/**
	 * "2 tickets per correct answer" — the reward line the stage-break screen and
	 * the per-question badge both read. One place for the wording, not two.
	 *
	 * @param array  $config    Config.
	 * @param string $level_key Level key.
	 * @return string
	 */
	public static function reward_label( array $config, $level_key ) {
		$reward = self::effective_reward( $config, $level_key );

		return sprintf(
			/* translators: %d: tickets per correct answer at this level. */
			_n( '%d ticket per correct answer', '%d tickets per correct answer', $reward, 'nera-strikeawin' ),
			$reward
		);
	}

	/**
	 * Total number of questions in a run for this config.
	 *
	 * @param array $config Config.
	 * @return int
	 */
	public static function total_questions( array $config ) {
		$total = 0;
		foreach ( (array) $config['distribution'] as $count ) {
			$total += (int) $count;
		}
		return $total;
	}

	/**
	 * Find a tier definition by key.
	 *
	 * @param array  $config   Config.
	 * @param string $tier_key Tier key.
	 * @return array|null
	 */
	public static function tier( array $config, $tier_key ) {
		foreach ( (array) $config['tiers'] as $tier ) {
			if ( (string) $tier['key'] === (string) $tier_key ) {
				return $tier;
			}
		}
		return null;
	}

	/**
	 * Maximum possible spins for a tier:
	 *   Sum( count_level * effective_reward_level ) * tier_multiplier
	 *
	 * @param array  $config   Config.
	 * @param string $tier_key Tier key.
	 * @return int
	 */
	public static function max_possible_spins( array $config, $tier_key ) {
		$base = 0;
		foreach ( (array) $config['distribution'] as $level_key => $count ) {
			$base += (int) $count * self::effective_reward( $config, $level_key );
		}
		$tier = self::tier( $config, $tier_key );
		$mult = $tier ? max( 1, (int) $tier['multiplier'] ) : 1;
		return $base * $mult;
	}

	/**
	 * The lowest tier's max possible spins (used for close/fit checks).
	 *
	 * @param array $config Config.
	 * @return int
	 */
	public static function lowest_tier_max( array $config ) {
		$lowest = 0;
		foreach ( (array) $config['tiers'] as $tier ) {
			$max = self::max_possible_spins( $config, $tier['key'] );
			if ( 0 === $lowest || $max < $lowest ) {
				$lowest = $max;
			}
		}
		return $lowest;
	}
}
