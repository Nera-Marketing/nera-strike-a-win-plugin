<?php
/**
 * Strike A Win config as a WooCommerce Product Data tab, shown for lottery
 * (competition) products below the Spin To Win tab. Strike A Win is its own
 * competition type — the tab is NOT gated on Spin-to-Win.
 *
 * Ticket stock and end/draw date come from the Lottery product fields (not here).
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Competition_Admin
 */
class Nera_SAW_Competition_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 20, 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'scripts' ) );
	}

	/**
	 * Tab for lottery products, below Spin To Win (85 -> 86).
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs['nera_strikeawin'] = array(
			'label'    => __( 'Strike A Win', 'nera-strikeawin' ),
			'target'   => 'nera_strikeawin_data',
			'class'    => array( 'show_if_lottery' ),
			'priority' => 86,
		);
		return $tabs;
	}

	/**
	 * Quiz-type conditional field JS (Type 1 per-question timer; Type 2 hidden).
	 *
	 * @param string $hook Hook.
	 */
	public static function scripts( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		wp_enqueue_style( 'nera-saw-admin', NERA_SAW_PLUGIN_URL . 'assets/css/admin.css', array(), NERA_SAW_VERSION );
		wp_register_script( 'nera-saw-admin-product', '', array( 'jquery' ), NERA_SAW_VERSION, true );
		wp_enqueue_script( 'nera-saw-admin-product' );
		$js = "(function($){
			function sync(){
				var t = $('#saw_quiz_type').val();
				var isWhole = t === 'whole_period';
				$('.saw-field-per-question').toggle(!isWhole);
				var \$whole = $('.saw-field-whole-period');
				\$whole.toggle(isWhole);
				// Hidden invalid controls (e.g. min=1, value=0) block WooCommerce product save.
				\$whole.find(':input').prop('disabled', !isWhole);
			}
			$(document).on('change', '#saw_quiz_type', sync);
			$(function(){ sync(); });

			$(document).on('click', '.saw-shortcode-box', function(){
				var text = $(this).find('code').text();
				if (navigator.clipboard) { navigator.clipboard.writeText(text); }
			});

			// Offline draw: add/remove prize rows. Clones the real parsed <tr>
			// node out of the <template>'s own content fragment (not a string
			// reparse — a bare <tr> HTML string gets silently dropped by some
			// parsers outside a <table>), then swaps the '__i__' placeholder
			// in each field's name for a freshly generated id so a new row
			// never collides with another row's array key on submit.
			$(document).on('click', '.saw-draw-prizes__add', function(){
				var tpl  = document.getElementById('saw-draw-prize-row-template');
				var row  = tpl.content.querySelector('tr').cloneNode(true);
				var freshId = 'saw_prize_new_' + Date.now() + '_' + Math.floor(Math.random() * 10000);
				row.querySelectorAll('[name]').forEach(function(el){
					el.setAttribute('name', el.getAttribute('name').replace('__i__', freshId));
				});
				document.querySelector('.saw-draw-prizes tbody').appendChild(row);
			});
			$(document).on('click', '.saw-draw-prizes__remove', function(){
				$(this).closest('tr').remove();
			});
		})(jQuery);";
		wp_add_inline_script( 'nera-saw-admin-product', $js );
	}

	/**
	 * Panel markup.
	 */
	public static function panel() {
		global $post;
		$product_id = (int) $post->ID;
		$config     = Nera_SAW_Competition_Config::get( $product_id );
		$is_comp    = Nera_SAW_Competition_Config::is_competition( $product_id );
		?>
		<div id="nera_strikeawin_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="form-field">
					<label for="saw_quiz_enabled"><?php esc_html_e( 'Earn tickets via the Strike A Win quiz', 'nera-strikeawin' ); ?></label>
					<input type="checkbox" class="checkbox" name="saw_quiz_enabled" id="saw_quiz_enabled" value="1" <?php checked( $is_comp, true ); ?> />
					<span class="description"><?php esc_html_e( 'Players earn this competition\'s LFW tickets only by answering quiz questions correctly (Section 9). Ticket stock and draw date come from the Lottery product settings.', 'nera-strikeawin' ); ?></span>
				</p>
				<p class="form-field">
					<label for="saw_quiz_type"><?php esc_html_e( 'Quiz type', 'nera-strikeawin' ); ?></label>
					<select id="saw_quiz_type" name="saw_quiz_type" class="select short" style="width:180px">
						<option value="per_question" <?php selected( $config['quiz_type'], 'per_question' ); ?>><?php esc_html_e( 'Timeout per question', 'nera-strikeawin' ); ?></option>
						<?php /* Type 2 (whole-quiz period) is built but withheld from live use pending Lewis sign-off. */ ?>
					</select>
				</p>
				<p class="form-field saw-field-per-question">
					<label for="saw_timer"><?php esc_html_e( 'Per-question timer (s)', 'nera-strikeawin' ); ?></label>
					<input type="number" name="saw_timer" id="saw_timer" class="short" style="width:90px" min="<?php echo (int) Nera_SAW_Constants::timer_min(); ?>" max="<?php echo (int) Nera_SAW_Constants::timer_max(); ?>" value="<?php echo (int) $config['timer_seconds']; ?>" />
					<span class="description"><?php echo esc_html( sprintf( __( 'Allowed window %1$d–%2$ds (set in Strike A Win → Settings).', 'nera-strikeawin' ), Nera_SAW_Constants::timer_min(), Nera_SAW_Constants::timer_max() ) ); ?></span>
				</p>
				<p class="form-field saw-field-whole-period" style="display:none">
					<label for="saw_total_time"><?php esc_html_e( 'Total time (minutes)', 'nera-strikeawin' ); ?></label>
					<input type="number" name="saw_total_time" id="saw_total_time" min="0" value="<?php echo (int) $config['total_time']; ?>" <?php disabled( 'whole_period' !== $config['quiz_type'] ); ?> />
					<span class="description"><?php esc_html_e( 'Whole-quiz timing (not available for live competitions yet).', 'nera-strikeawin' ); ?></span>
				</p>
			</div>

			<div class="options_group">
				<p class="form-field">
					<label><?php esc_html_e( 'ShortCode', 'nera-strikeawin' ); ?></label>
					<span class="saw-shortcode-box" title="<?php esc_attr_e( 'Click to copy', 'nera-strikeawin' ); ?>">
						<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
						<code><?php echo esc_html( sprintf( '[strikeawin_quiz id="%d"]', $product_id ) ); ?></code>
					</span>
					<span class="description"><?php esc_html_e( 'Embeds this competition\'s quiz on any page — every tier the player has runs on gets a Start card. The id is only needed off the site\'s own Play page; there, the page\'s own link already says which competition.', 'nera-strikeawin' ); ?></span>
				</p>
				<?php
				$saw_sc_overrides = isset( $config['tier_overrides'] ) && is_array( $config['tier_overrides'] ) ? $config['tier_overrides'] : array();
				$saw_sc_tiers     = array();
				foreach ( Nera_SAW_Constants::global_tiers() as $saw_sc_tier ) {
					$saw_sc_key = (string) $saw_sc_tier['key'];
					$saw_sc_ov  = isset( $saw_sc_overrides[ $saw_sc_key ] ) && is_array( $saw_sc_overrides[ $saw_sc_key ] ) ? $saw_sc_overrides[ $saw_sc_key ] : array();
					if ( isset( $saw_sc_ov['enabled'] ) && empty( $saw_sc_ov['enabled'] ) ) {
						continue;
					}
					$saw_sc_tiers[] = $saw_sc_tier;
				}
				?>
				<?php if ( $saw_sc_tiers ) : ?>
					<p class="form-field">
						<label><?php esc_html_e( 'Per tier', 'nera-strikeawin' ); ?></label>
						<?php foreach ( $saw_sc_tiers as $saw_sc_tier ) : ?>
							<span class="saw-shortcode-box" title="<?php esc_attr_e( 'Click to copy', 'nera-strikeawin' ); ?>" style="margin:0 8px 8px 0">
								<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
								<code><?php echo esc_html( sprintf( '[strikeawin_quiz id="%d" tier="%s"]', $product_id, $saw_sc_tier['key'] ) ); ?></code>
							</span>
						<?php endforeach; ?>
						<span class="description"><?php esc_html_e( 'Shows only that tier\'s Start card, for embedding a single tier\'s quiz on its own.', 'nera-strikeawin' ); ?></span>
					</p>
				<?php endif; ?>
			</div>

			<div class="options_group">
				<?php
				/*
				 * Per-competition Quiz Method. Blank is "inherit", and the label
				 * names the global value so an administrator can see what
				 * inheriting actually means without opening Settings in another
				 * tab. Whether the run shows stages follows from this, so the
				 * description says so — it is the part people are surprised by.
				 */
				$saw_global_quiz   = Nera_SAW_Mode::quiz_method();
				$saw_quiz_choices  = Nera_SAW_Mode::quiz_methods();
				$saw_quiz_override = isset( $config['quiz_method'] ) ? (string) $config['quiz_method'] : Nera_SAW_Mode::INHERIT;
				?>
				<p class="form-field">
					<label for="saw_cash_alternative"><?php esc_html_e( 'Cash alternative', 'nera-strikeawin' ); ?></label>
					<input type="text" name="saw_cash_alternative" id="saw_cash_alternative" class="short" style="width:180px"
						value="<?php echo esc_attr( isset( $config['cash_alternative'] ) ? $config['cash_alternative'] : '' ); ?>"
						placeholder="<?php esc_attr_e( '£28,000', 'nera-strikeawin' ); ?>" />
					<span class="description"><?php esc_html_e( 'Shown on the competition page as what the winner may take instead of the prize. Printed exactly as typed — leave empty to hide the row.', 'nera-strikeawin' ); ?></span>
				</p>
				<p class="form-field">
					<label for="saw_quiz_method"><?php esc_html_e( 'Quiz Method', 'nera-strikeawin' ); ?></label>
					<select name="saw_quiz_method" id="saw_quiz_method" class="short" style="width:260px">
						<option value="" <?php selected( $saw_quiz_override, Nera_SAW_Mode::INHERIT ); ?>>
							<?php
							/* translators: %s: the global Quiz Method label. */
							echo esc_html( sprintf( __( 'Inherit global — %s', 'nera-strikeawin' ), $saw_quiz_choices[ $saw_global_quiz ] ) );
							?>
						</option>
						<?php foreach ( $saw_quiz_choices as $saw_key => $saw_label ) : ?>
							<option value="<?php echo esc_attr( $saw_key ); ?>" <?php selected( $saw_quiz_override, $saw_key ); ?>><?php echo esc_html( $saw_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'Ladder runs the levels in stages, easy to hard, and the competition page shows a stage for each. Random mixes them across the run, so there are no stages to show — only how many questions come from each level.', 'nera-strikeawin' ); ?></span>
				</p>
			</div>

			<div class="options_group">
				<p class="form-field"><label><?php esc_html_e( 'Questions per level + tickets', 'nera-strikeawin' ); ?></label>
					<span class="description"><?php esc_html_e( 'Questions are drawn from the bank matching this product\'s categories and the current language. Levels come from Strike A Win → Difficulty Ladder.', 'nera-strikeawin' ); ?></span></p>
				<table class="widefat" style="max-width:640px;margin:0 12px 12px;">
					<thead><tr><th><?php esc_html_e( 'Level', 'nera-strikeawin' ); ?></th><th><?php esc_html_e( 'Questions', 'nera-strikeawin' ); ?></th><th><?php esc_html_e( 'Tickets (blank = default)', 'nera-strikeawin' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( Nera_SAW_Constants::ladder() as $lv ) : ?>
						<?php
						$count  = isset( $config['distribution'][ $lv['key'] ] ) ? (int) $config['distribution'][ $lv['key'] ] : 0;
						$reward = isset( $config['level_rewards'][ $lv['key'] ] ) ? $config['level_rewards'][ $lv['key'] ] : '';
						$dot    = Nera_SAW_Constants::level_color( $lv['key'] );
						?>
						<tr>
							<td><span class="saw-dot" style="background:<?php echo esc_attr( $dot ); ?>"></span><?php echo esc_html( $lv['label'] ); ?></td>
							<td><input type="number" min="0" name="saw_dist[<?php echo esc_attr( $lv['key'] ); ?>]" value="<?php echo (int) $count; ?>" style="width:80px" /></td>
							<td><input type="number" min="0" name="saw_reward[<?php echo esc_attr( $lv['key'] ); ?>]" value="<?php echo esc_attr( $reward ); ?>" placeholder="<?php echo (int) $lv['reward']; ?>" style="width:80px" /></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="options_group">
				<p class="form-field"><label><?php esc_html_e( 'Tiers', 'nera-strikeawin' ); ?></label>
					<span class="description"><?php esc_html_e( 'Tiers come from Strike A Win → Settings. Enable/disable them here and override price, multiplier or ceiling for this competition (blank = use the global value). Multiplier scales tickets won, never chance. Ceiling = max tickets won at this tier for this competition (0 = no cap).', 'nera-strikeawin' ); ?></span></p>
				<table class="widefat" style="max-width:760px;margin:0 12px 12px;">
					<thead><tr>
						<th><?php esc_html_e( 'Tier', 'nera-strikeawin' ); ?></th>
						<th><?php esc_html_e( 'Enabled', 'nera-strikeawin' ); ?></th>
						<th><?php esc_html_e( 'Price', 'nera-strikeawin' ); ?></th>
						<th><?php esc_html_e( 'Multiplier', 'nera-strikeawin' ); ?></th>
						<th><?php esc_html_e( 'Ceiling', 'nera-strikeawin' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					$overrides = isset( $config['tier_overrides'] ) && is_array( $config['tier_overrides'] ) ? $config['tier_overrides'] : array();
					foreach ( Nera_SAW_Constants::global_tiers() as $g ) :
						$key       = (string) $g['key'];
						$ov        = isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ? $overrides[ $key ] : array();
						$enabled   = ! isset( $ov['enabled'] ) || ! empty( $ov['enabled'] );
						$status    = self::tier_status_for( $config, $product_id, $key, $g );
						$dot_class = 'saw-dot--' . $status['status'];
						$title     = $status['title'];
						?>
						<tr>
							<td><span class="saw-dot <?php echo esc_attr( $dot_class ); ?>" title="<?php echo esc_attr( $title ); ?>"></span><?php echo esc_html( $g['label'] ); ?> <code><?php echo esc_html( $key ); ?></code></td>
							<td><input type="checkbox" name="saw_tier_ov[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $enabled, true ); ?> /></td>
							<td><input type="number" step="0.01" min="0" name="saw_tier_ov[<?php echo esc_attr( $key ); ?>][price]" value="<?php echo esc_attr( isset( $ov['price'] ) ? $ov['price'] : '' ); ?>" placeholder="<?php echo esc_attr( $g['price'] ); ?>" style="width:90px" /></td>
							<td><input type="number" min="1" name="saw_tier_ov[<?php echo esc_attr( $key ); ?>][multiplier]" value="<?php echo esc_attr( isset( $ov['multiplier'] ) ? $ov['multiplier'] : '' ); ?>" placeholder="<?php echo (int) $g['multiplier']; ?>" style="width:80px" /></td>
							<td><input type="number" min="0" name="saw_tier_ov[<?php echo esc_attr( $key ); ?>][ceiling]" value="<?php echo esc_attr( isset( $ov['ceiling'] ) ? $ov['ceiling'] : '' ); ?>" placeholder="<?php echo (int) $g['ceiling']; ?>" style="width:80px" /></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="margin:0 12px 12px;">
					<span class="saw-dot saw-dot--green"></span> <?php esc_html_e( 'available', 'nera-strikeawin' ); ?>
					&nbsp; <span class="saw-dot saw-dot--orange"></span> <?php esc_html_e( 'almost at ceiling (hidden on frontend)', 'nera-strikeawin' ); ?>
					&nbsp; <span class="saw-dot saw-dot--red"></span> <?php esc_html_e( 'ceiling reached', 'nera-strikeawin' ); ?>
				</p>
			</div>

			<?php self::draw_panel( $product_id, $config ); ?>
		</div>
		<?php
	}

	/**
	 * Offline draw entry: a prize table (one row per prize, each with the
	 * ticket number(s) an offline draw actually picked) and a "Draw closed"
	 * checkbox that, once saved, awards every pending number and marks the
	 * competition drawn. See `Nera_SAW_Draw_Prizes`'s own docblock for the
	 * full reasoning (why this exists alongside lottery-for-woocommerce's
	 * own draw, why prizes aren't auto-fulfilled the way instant-win's are).
	 *
	 * @param int   $product_id Product ID.
	 * @param array $config     Resolved config (has 'draw_closed' and 'prizes').
	 */
	private static function draw_panel( $product_id, array $config ) {
		$draw_closed = ! empty( $config['draw_closed'] );
		$prizes      = isset( $config['prizes'] ) && is_array( $config['prizes'] ) ? $config['prizes'] : array();
		?>
		<div class="options_group saw-draw-panel">
			<p class="form-field">
				<label for="saw_draw_closed"><?php esc_html_e( 'Draw closed — entries drawn offline', 'nera-strikeawin' ); ?></label>
				<input type="checkbox" class="checkbox" name="saw_draw_closed" id="saw_draw_closed" value="1" <?php checked( $draw_closed, true ); ?> />
				<span class="description"><?php esc_html_e( 'Stops new runs and marks the competition drawn (the same status Draw results and the account hub already read). Saving with this on awards every prize row below against the numbers an offline draw actually picked — do this once the physical draw has happened.', 'nera-strikeawin' ); ?></span>
			</p>

			<p class="form-field">
				<label><?php esc_html_e( 'Prizes', 'nera-strikeawin' ); ?></label>
				<span class="description"><?php esc_html_e( 'One row per prize. A prize can have more than one winning entry number (e.g. three runner-up prizes of the same kind — one row, three numbers). Numbers are checked against this competition\'s own minted entries when you save; the owner is never shown here.', 'nera-strikeawin' ); ?></span>
			</p>
			<table class="widefat saw-draw-prizes" style="max-width:900px;margin:0 12px 12px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Prize', 'nera-strikeawin' ); ?></th>
						<th><?php esc_html_e( 'Type', 'nera-strikeawin' ); ?></th>
						<th><?php esc_html_e( 'Gift product ID', 'nera-strikeawin' ); ?></th>
						<th><?php esc_html_e( 'Coupon amount', 'nera-strikeawin' ); ?></th>
						<th><?php esc_html_e( 'Winning entry number(s)', 'nera-strikeawin' ); ?></th>
						<th><?php esc_html_e( 'Awarded', 'nera-strikeawin' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $prizes as $saw_row ) : ?>
						<?php self::draw_prize_row( $saw_row ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin:0 12px 12px;">
				<button type="button" class="button saw-draw-prizes__add"><?php esc_html_e( '+ Add prize', 'nera-strikeawin' ); ?></button>
			</p>
			<template id="saw-draw-prize-row-template">
				<table><tbody>
				<?php self::draw_prize_row( array() ); ?>
				</tbody></table>
			</template>
		</div>
		<?php
	}

	/**
	 * One prize row (used both for an existing row and the blank JS template).
	 *
	 * Each row's fields are keyed by its own stable `id` (`saw_prize[<id>]
	 * [...]`) rather than a plain numeric index — a numeric index would
	 * collide across rows once the JS template (which has no real id yet)
	 * clones itself more than once on one page. The template row instead
	 * carries the literal placeholder `__i__`, which the "Add prize" script
	 * replaces with a freshly generated id on each click.
	 *
	 * @param array  $row Prize row (empty array for a blank template row).
	 * @param string $key Explicit array key to use; defaults to the row's own id, or '__i__' for the template.
	 */
	private static function draw_prize_row( array $row, $key = '' ) {
		$name       = isset( $row['name'] ) ? (string) $row['name'] : '';
		$type       = isset( $row['prize_type'] ) && 'coupon' === $row['prize_type'] ? 'coupon' : 'product';
		$product_id = isset( $row['gift_product_id'] ) ? (int) $row['gift_product_id'] : 0;
		$amount     = isset( $row['coupon_amount'] ) && '' !== $row['coupon_amount'] ? (float) $row['coupon_amount'] : '';
		$numbers    = isset( $row['ticket_numbers'] ) && is_array( $row['ticket_numbers'] ) ? implode( ', ', $row['ticket_numbers'] ) : '';
		$awarded    = isset( $row['awarded'] ) && is_array( $row['awarded'] ) ? count( $row['awarded'] ) : 0;
		$total      = isset( $row['ticket_numbers'] ) && is_array( $row['ticket_numbers'] ) ? count( $row['ticket_numbers'] ) : 0;
		if ( '' === $key ) {
			$key = isset( $row['id'] ) && '' !== $row['id'] ? (string) $row['id'] : '__i__';
		}
		?>
		<tr class="saw-draw-prizes__row">
			<td>
				<input type="hidden" name="saw_prize[<?php echo esc_attr( $key ); ?>][id]" value="<?php echo esc_attr( isset( $row['id'] ) ? $row['id'] : '' ); ?>" />
				<input type="text" name="saw_prize[<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" style="width:100%" placeholder="<?php esc_attr_e( 'e.g. £500 cash', 'nera-strikeawin' ); ?>" />
			</td>
			<td>
				<select name="saw_prize[<?php echo esc_attr( $key ); ?>][prize_type]">
					<option value="product" <?php selected( $type, 'product' ); ?>><?php esc_html_e( 'Gift product', 'nera-strikeawin' ); ?></option>
					<option value="coupon" <?php selected( $type, 'coupon' ); ?>><?php esc_html_e( 'Coupon', 'nera-strikeawin' ); ?></option>
				</select>
			</td>
			<td><input type="number" min="0" name="saw_prize[<?php echo esc_attr( $key ); ?>][gift_product_id]" value="<?php echo esc_attr( $product_id ? $product_id : '' ); ?>" style="width:90px" /></td>
			<td><input type="number" min="0" step="0.01" name="saw_prize[<?php echo esc_attr( $key ); ?>][coupon_amount]" value="<?php echo esc_attr( '' !== $amount ? $amount : '' ); ?>" style="width:90px" /></td>
			<td><input type="text" name="saw_prize[<?php echo esc_attr( $key ); ?>][ticket_numbers]" value="<?php echo esc_attr( $numbers ); ?>" style="width:100%" placeholder="<?php esc_attr_e( 'e.g. 48, 1902, 3310', 'nera-strikeawin' ); ?>" /></td>
			<td>
				<?php if ( $total > 0 ) : ?>
					<?php
					printf(
						/* translators: 1: numbers already awarded, 2: total numbers on this row */
						esc_html__( '%1$d / %2$d', 'nera-strikeawin' ),
						(int) $awarded,
						(int) $total
					);
					?>
				<?php endif; ?>
			</td>
			<td><button type="button" class="button-link-delete saw-draw-prizes__remove"><?php esc_html_e( 'Remove', 'nera-strikeawin' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * Compute a tier's ceiling status for the product editor circle, using the
	 * global tier + any competition override (independent of enable/disable).
	 *
	 * @param array  $config     Resolved config.
	 * @param int    $product_id Product ID.
	 * @param string $key        Tier key.
	 * @param array  $global     Global tier row.
	 * @return array { status, title }
	 */
	private static function tier_status_for( array $config, $product_id, $key, array $global ) {
		$overrides = isset( $config['tier_overrides'][ $key ] ) && is_array( $config['tier_overrides'][ $key ] ) ? $config['tier_overrides'][ $key ] : array();
		$mult      = ( isset( $overrides['multiplier'] ) && '' !== $overrides['multiplier'] ) ? max( 1, (int) $overrides['multiplier'] ) : max( 1, (int) $global['multiplier'] );
		$ceiling   = ( isset( $overrides['ceiling'] ) && '' !== $overrides['ceiling'] ) ? max( 0, (int) $overrides['ceiling'] ) : max( 0, (int) $global['ceiling'] );

		$base = 0;
		foreach ( (array) $config['distribution'] as $lk => $c ) {
			$base += (int) $c * Nera_SAW_Competition_Config::effective_reward( $config, $lk );
		}
		$per_run = $base * $mult;

		if ( $ceiling <= 0 ) {
			return array( 'status' => 'green', 'title' => __( 'No ceiling', 'nera-strikeawin' ) );
		}
		$won       = Nera_SAW_Competition_Config::tickets_won_for_tier( (int) $product_id, $key );
		$remaining = $ceiling - $won;
		if ( $remaining <= 0 ) {
			$status = 'red';
		} elseif ( $remaining < $per_run ) {
			$status = 'orange';
		} else {
			$status = 'green';
		}
		/* translators: 1: tickets won, 2: ceiling, 3: per-run max */
		$title = sprintf( __( 'Won %1$d of %2$d (per run up to %3$d)', 'nera-strikeawin' ), $won, $ceiling, $per_run );
		return array( 'status' => $status, 'title' => $title );
	}

	/**
	 * Save (on lottery products). Marks a competition when the quiz toggle is on.
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// If our field wasn't present at all (non-lottery product), do nothing.
		if ( ! isset( $_POST['saw_quiz_type'] ) && ! isset( $_POST['saw_quiz_enabled'] ) && ! isset( $_POST['saw_dist'] )
			&& ! isset( $_POST['saw_draw_closed'] ) && ! isset( $_POST['saw_prize'] )
		) {
			return;
		}

		$existing    = Nera_SAW_Competition_Config::get( $post_id );
		$draw_fields = Nera_SAW_Draw_Prizes::save_from_request( $post_id, $existing );

		if ( empty( $_POST['saw_quiz_enabled'] ) ) {
			Nera_SAW_Competition_Config::set_competition( $post_id, false );
			// The draw/prize table is independent of quiz-earning being on —
			// persist it even when the quiz itself is being turned off.
			Nera_SAW_Competition_Config::save( $post_id, array_merge( $existing, $draw_fields ) );
			return;
		}

		$dist = array();
		foreach ( (array) ( $_POST['saw_dist'] ?? array() ) as $key => $count ) {
			$dist[ sanitize_key( $key ) ] = max( 0, (int) $count );
		}
		$rewards = array();
		foreach ( (array) ( $_POST['saw_reward'] ?? array() ) as $key => $reward ) {
			$reward = trim( (string) $reward );
			if ( '' !== $reward ) {
				$rewards[ sanitize_key( $key ) ] = max( 0, (int) $reward );
			}
		}
		// Per-competition tier overrides (keyed by the global tier key). A blank
		// price/multiplier/ceiling means "inherit the global value".
		$tier_overrides = array();
		$posted_ov      = (array) ( $_POST['saw_tier_ov'] ?? array() );
		foreach ( Nera_SAW_Constants::global_tiers() as $g ) {
			$key = (string) $g['key'];
			$row = isset( $posted_ov[ $key ] ) && is_array( $posted_ov[ $key ] ) ? $posted_ov[ $key ] : array();
			$ov  = array( 'enabled' => ! empty( $row['enabled'] ) );
			foreach ( array( 'price', 'multiplier', 'ceiling' ) as $field ) {
				$val = isset( $row[ $field ] ) ? trim( (string) $row[ $field ] ) : '';
				if ( '' !== $val ) {
					$ov[ $field ] = 'price' === $field ? (float) $val : max( ( 'multiplier' === $field ? 1 : 0 ), (int) $val );
				}
			}
			$tier_overrides[ $key ] = $ov;
		}

		$quiz_type    = isset( $_POST['saw_quiz_type'] ) ? sanitize_key( wp_unslash( $_POST['saw_quiz_type'] ) ) : 'per_question';
		if ( 'per_question' !== $quiz_type ) {
			$quiz_type = 'per_question';
		}

		$total_time = isset( $_POST['saw_total_time'] ) ? max( 0, (int) $_POST['saw_total_time'] ) : (int) $existing['total_time'];

		$config = array(
			'enabled'        => true,
			'quiz_type'      => $quiz_type,
			'cash_alternative' => isset( $_POST['saw_cash_alternative'] ) ? sanitize_text_field( wp_unslash( $_POST['saw_cash_alternative'] ) ) : '',
			'quiz_method'    => Nera_SAW_Mode::sanitize_quiz_method_override(
				isset( $_POST['saw_quiz_method'] ) ? wp_unslash( $_POST['saw_quiz_method'] ) : Nera_SAW_Mode::INHERIT
			),
			'timer_seconds'  => isset( $_POST['saw_timer'] ) ? (int) $_POST['saw_timer'] : Nera_SAW_Constants::timer_max(),
			'total_time'     => $total_time,
			'distribution'   => $dist,
			'level_rewards'  => $rewards,
			'tier_overrides' => $tier_overrides,
			'draw_closed'    => $draw_fields['draw_closed'],
			'prizes'         => $draw_fields['prizes'],
		);

		Nera_SAW_Competition_Config::save( $post_id, $config );
		Nera_SAW_Competition_Config::set_competition( $post_id, true );
	}
}
