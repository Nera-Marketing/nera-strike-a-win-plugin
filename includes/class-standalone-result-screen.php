<?php
/**
 * The confirmation overlay, wearing the section's skin.
 *
 * The Lottery for WooCommerce result screens live in an mu-plugin
 * (`mu-plugins/lty-result-screens`) and come dressed in their own indigo palette.
 * On the main site that is what they should look like. Inside the section it
 * arrived as a purple card over a cream page, in a typeface the section does not
 * use — a different product's dialog sitting on top of ours.
 *
 * WHY A CLONE RATHER THAN AN OVERRIDE
 * -----------------------------------
 * The mu-plugin looks for overrides in exactly one place:
 *
 *     {stylesheet}/lty-result-screens/{template}
 *
 * A theme directory, with no filter on the path. This plugin may not write there —
 * the theme is not guaranteed to be present, and every change belongs inside this
 * plugin. What it does offer is `lty_rs_show_overlay`, a documented way to say "not
 * this one". So the section declines the overlay it would have drawn and draws its
 * own in the same place, from templates that live here.
 *
 * WHAT IS DELIBERATELY SHARED
 * ---------------------------
 * - The wording, which is still read from the same ACF options fields, so an
 *   administrator edits one copy and both skins follow.
 * - `lty_rs_show_overlay` decides which screen is due. The section reuses that
 *   answer rather than working it out again and drifting from it.
 * - The root class `lty-rs-overlay` and the `data-lty-rs-dismiss` attribute, which
 *   are what the mu-plugin's script binds to. Keeping them means the close button,
 *   the Escape key, the focus move and the scroll lock keep working, with no second
 *   copy of that behaviour to maintain.
 *
 * The main site is untouched: every hook here returns early unless the request is
 * on a standalone screen.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Standalone_Result_Screen
 */
class Nera_SAW_Standalone_Result_Screen {

	/**
	 * Which screen the mu-plugin was about to draw, once it has told us.
	 *
	 * @var string
	 */
	private static $due = '';

	/**
	 * The order that screen belongs to.
	 *
	 * @var WC_Order|null
	 */
	private static $order = null;

	/**
	 * The screens the section draws for itself.
	 *
	 * `instant-win-won` is missing on purpose. It is the one screen the section
	 * cannot reach — the section sells Strike A Win entries, which are never instant
	 * winners — and it carries a coupon code, a clipboard copy and a wallet payout
	 * block. Cloning all that against a path that cannot occur would be writing
	 * markup nobody can see and nobody can test. If the section is ever given
	 * instant-win products, that screen shows the mu-plugin's own styling, which is
	 * correct but unskinned, and this is the note that says so.
	 *
	 * @var array<int, string>
	 */
	private static $ours = array( 'prize-draw', 'instant-win-no-win' );

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! Nera_SAW_Mode::is_standalone() ) {
			return;
		}

		add_filter( 'lty_rs_show_overlay', array( __CLASS__, 'claim_overlay' ), 10, 3 );

		// After the mu-plugin's own render at 10, which our filter has just declined.
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'render' ), 11 );
	}

	/**
	 * Take the overlay off the mu-plugin's hands, on our screens only.
	 *
	 * Returning false here is the whole of the handover: the mu-plugin renders
	 * nothing, and tells us which screen it had chosen on the way out.
	 *
	 * @param bool     $show  Whether the mu-plugin should draw it.
	 * @param string   $type  Which screen it had chosen.
	 * @param WC_Order $order The finished order.
	 * @return bool
	 */
	public static function claim_overlay( $show, $type, $order ) {
		if ( ! $show || ! self::on_section_screen() ) {
			return $show;
		}

		if ( ! in_array( (string) $type, self::$ours, true ) ) {
			return $show;
		}

		self::$due   = (string) $type;
		self::$order = $order instanceof WC_Order ? $order : null;

		return false;
	}

	/**
	 * Draw the section's version — disabled by request.
	 *
	 * `claim_overlay()` above still declines the mu-plugin's own draw on the
	 * section's screens (so its unskinned purple popup cannot leak through
	 * instead), but this no longer replaces it with anything of its own: the
	 * order-received confirmation popup is suppressed entirely on the
	 * standalone section. The main site is unaffected either way — the
	 * mu-plugin's overlay still renders normally there, since `claim_overlay()`
	 * only ever intercepts `on_section_screen()`.
	 *
	 * The state is still reset here (not just left set) so a stray second call
	 * on the same request — the "one draw per request" guard this always had —
	 * cannot leave `self::$due`/`self::$order` pointing at a stale order.
	 *
	 * @param int $order_id Order the shopper has just completed.
	 */
	public static function render( $order_id ) {
		unset( $order_id );

		self::$due   = '';
		self::$order = null;
	}

	/**
	 * A draw date, formatted, or an empty string when the product has none.
	 *
	 * @param WC_Product|null $product The product being drawn.
	 * @return string
	 */
	public static function draw_date( $product ) {
		if ( ! $product || ! method_exists( $product, 'get_lty_end_date' ) ) {
			return '';
		}

		$end = $product->get_lty_end_date();
		if ( ! $end ) {
			return '';
		}

		$stamp = is_numeric( $end ) ? (int) $end : strtotime( (string) $end );

		return Nera_SAW_Date::localized( $stamp, get_option( 'date_format' ), false );
	}

	/**
	 * A wording field, falling back to the same default the mu-plugin uses.
	 *
	 * Read from the same options so the two skins never disagree about the copy.
	 *
	 * @param string $field    ACF field name.
	 * @param string $fallback Default text.
	 * @return string
	 */
	public static function copy( $field, $fallback ) {
		if ( ! function_exists( 'get_field' ) ) {
			return $fallback;
		}

		$value = get_field( $field, 'option' );

		return $value ? (string) $value : $fallback;
	}

	/**
	 * Is this one of the section's screens?
	 *
	 * @return bool
	 */
	private static function on_section_screen() {
		return class_exists( 'Nera_SAW_Standalone_Basket' )
			&& Nera_SAW_Standalone_Basket::is_section_checkout();
	}
}
