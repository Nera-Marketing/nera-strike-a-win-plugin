<?php
/**
 * Editable copy for the standalone screens.
 *
 * Two kinds of field, because the screens have two kinds of content:
 *
 *   - the shell — logo, header link, footer legal line — which is the same on
 *     every screen and lives on an options page;
 *   - each screen's own copy, which lives on that screen's page and appears when
 *     its template is selected.
 *
 * Registered in PHP with `acf_add_local_field_group()` rather than created in the
 * ACF admin. The templates read these keys by name, so a field somebody renames or
 * deletes from a UI is a screen that silently loses its heading. In code they are
 * versioned with the plugin, travel with a deploy, and cannot be edited into a
 * different shape.
 *
 * Every field has a default that matches the prototype, and every template falls
 * back to it. An empty field means "use the default", not "render nothing" — so a
 * fresh install looks right before anybody has opened the page.
 *
 * NOTE ON INSTRUCTION TEXT
 * ACF prints `instructions` as HTML, not as text. A tag written there is a tag,
 * so an unclosed one leaks into every field below it — which is how an example
 * `<em>` in the Heading field's help text turned the rest of the metabox italic
 * and pushed it out of its container. Any tag being *named* rather than *used*
 * goes in `<code>&lt;em&gt;</code> form.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Standalone_Fields
 */
class Nera_SAW_Standalone_Fields {

	/**
	 * ACF options page slug for the shared shell.
	 */
	const OPTIONS_SLUG = 'nera-saw-standalone-content';

	/**
	 * Option holding seeded translations of this catalogue, keyed by field name
	 * then language code: `array( 'saw_cd_cta' => array( 'ru' => '...' ) )`.
	 *
	 * A field here has no post and no ACF options-page field of its own to carry
	 * a second language — `scope => 'option'` is one flat `wp_options` row, and
	 * `scope => <route>` is post meta on the one page that route has, since the
	 * standalone section never gave a route a page per language (the header
	 * toggle changes `Nera_SAW_Language::current()` on the same URL, not the
	 * page it resolves to — see class-language.php's SWITCH_ARG). Polylang's own
	 * post-translation model has nothing to attach to here, so this is a small,
	 * plugin-owned side table instead: resolved by `text()`/`shell()` at read
	 * time, next to the field's own designed default, and written by
	 * `seed_defaults()` next to that default's own seeding logic.
	 */
	const I18N_OPTION = 'nera_saw_standalone_i18n';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		add_action( 'acf/init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the options page and the field groups.
	 */
	public static function register() {
		if ( function_exists( 'acf_add_options_page' ) ) {
			acf_add_options_page(
				array(
					'page_title'  => __( 'Standalone Content', 'nera-strikeawin' ),
					'menu_title'  => __( 'Standalone Content', 'nera-strikeawin' ),
					'menu_slug'   => self::OPTIONS_SLUG,
					'parent_slug' => 'nera-strikeawin',
					'capability'  => 'manage_options',
					'redirect'    => false,
				)
			);
		}

		self::shell_group();
		self::competitions_group();
		self::competition_detail_group();
		self::how_it_works_group();
		self::pre_payment_group();
		self::walkthrough_group();
		self::checkout_account_group();
	}

	/* ---------------------------------------------------------------------
	 * The catalogue
	 * ------------------------------------------------------------------ */

	/**
	 * Every field, with its key, where it is stored, and its designed value.
	 *
	 * One copy of the copy. Three things need it and they must agree: the templates
	 * render it when a field is empty, the editor shows it as a placeholder, and the
	 * seeder writes it in as real content. When they drift, a page reads one way on
	 * the front end and another in the editor, and nobody can tell which is live.
	 *
	 * `scope` is 'option' for the shared shell, or the route whose page holds the
	 * field. `seed` marks the ones the seeder writes -- an image has no text default,
	 * so it stays empty and the header falls back to the logo text.
	 *
	 * `fill` decides when the seeder may write:
	 *
	 *   'empty'  -- whenever the field has no value. Safe for copy, because an empty
	 *               text field and its default render identically, so "deliberately
	 *               blank" is not a state that exists to protect. Writing the default
	 *               in only ever replaces nothing with something.
	 *   'unset'  -- only if the field has never been saved. For the switch, where off
	 *               is a real choice that looks exactly like empty, and re-seeding
	 *               must not quietly turn the panel back on.
	 *
	 * @return array name => array{key: string, scope: string, default: mixed, seed: bool, fill: string}
	 */
	public static function catalogue() {
		return array(
			// --- shell, shared by every screen ---------------------------------
			'saw_logo'          => array(
				'key'     => 'field_nera_saw_logo',
				'scope'   => 'option',
				'default' => null,
				'seed'    => false,
				'fill'    => 'unset',
			),
			'saw_logo_text'     => array(
				'key'     => 'field_nera_saw_logo_text',
				'scope'   => 'option',
				'default' => __( 'Strike <em>A</em> Win', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_header_link'   => array(
				'key'     => 'field_nera_saw_header_link',
				'scope'   => 'option',
				'default' => __( 'How it works', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_footer_line'   => array(
				'key'     => 'field_nera_saw_footer_line',
				'scope'   => 'option',
				/* translators: %s: site name */
				'default' => sprintf( __( '%s — skill-based prize competitions.', 'nera-strikeawin' ), get_bloginfo( 'name' ) ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			// The rest of the footer's small print (Gambling Act, the run-can-earn-
			// nothing statement, the terms link) was hardcoded English directly in
			// footer.php — not a field at all, so it never had a translation to seed
			// in the first place. Fields now, same as the sentence above it, kept
			// out of the ACF admin group below only because an administrator
			// clearing required legal copy by accident is worse than not being able
			// to edit it — translatable is still the point.
			'saw_footer_legal'  => array(
				'key'     => 'field_nera_saw_footer_legal',
				'scope'   => 'option',
				'default' => __( 'Skill-based prize competitions under the Gambling Act 2005.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_footer_risk'   => array(
				'key'     => 'field_nera_saw_footer_risk',
				'scope'   => 'option',
				'default' => __( 'A run can earn zero tickets and no refund is due.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_footer_terms_label'  => array(
				'key'     => 'field_nera_saw_footer_terms_label',
				'scope'   => 'option',
				'default' => __( 'Full terms', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_footer_terms_suffix' => array(
				'key'     => 'field_nera_saw_footer_terms_suffix',
				'scope'   => 'option',
				'default' => __( 'on every draw.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			// Header/back chrome — same story: real strings, hardcoded, never
			// seeded because they were never a field.
			'saw_signin_label'  => array(
				'key'     => 'field_nera_saw_signin_label',
				'scope'   => 'option',
				'default' => __( 'Sign in', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_walkthrough_aria'    => array(
				'key'     => 'field_nera_saw_walkthrough_aria',
				'scope'   => 'option',
				'default' => __( 'Quick walkthrough', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_account_aria'  => array(
				'key'     => 'field_nera_saw_account_aria',
				'scope'   => 'option',
				'default' => __( 'Account', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_back_aria'     => array(
				'key'     => 'field_nera_saw_back_aria',
				'scope'   => 'option',
				'default' => __( 'Back', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),

			// --- competitions list --------------------------------------------
			'saw_eyebrow'       => array(
				'key'     => 'field_nera_saw_c_eyebrow',
				'scope'   => 'competitions',
				'default' => __( 'Open draws', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_heading'       => array(
				'key'     => 'field_nera_saw_c_heading',
				'scope'   => 'competitions',
				'default' => __( 'Pick <em>a</em> competition', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_lede'          => array(
				'key'     => 'field_nera_saw_c_lede',
				'scope'   => 'competitions',
				'default' => __( 'Closing soonest first. Every entry is a timed skill test, and it is genuinely hard.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_empty'         => array(
				'key'     => 'field_nera_saw_c_empty',
				'scope'   => 'competitions',
				'default' => __( 'No competitions are open right now. Check back soon.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_panel_show'    => array(
				'key'     => 'field_nera_saw_c_panel_show',
				'scope'   => 'competitions',
				'default' => 1,
				'seed'    => true,
				'fill'    => 'unset',
			),
			'saw_panel_heading' => array(
				'key'     => 'field_nera_saw_c_panel_heading',
				'scope'   => 'competitions',
				'default' => __( 'Know what you’re buying', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_panel_body'    => array(
				'key'     => 'field_nera_saw_c_panel_body',
				'scope'   => 'competitions',
				'default' => __( 'A run is a timed set of questions, and each competition sets its own. Wrong answers and expired timers earn nothing, and a meaningful share of players finish a run with no tickets and no entry.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			// --- competition detail -------------------------------------------
			// On the options page, not a page of its own: one template serves every
			// competition, so a sentence about how runs work must not be able to say
			// something different on two of them.
			'saw_cd_spec_heading'  => array(
				'key'     => 'field_nera_saw_cd_spec_heading',
				'scope'   => 'option',
				'default' => __( 'This draw’s quiz spec', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_cd_spec_note'     => array(
				'key'     => 'field_nera_saw_cd_spec_note',
				'scope'   => 'option',
				'default' => __( 'Set for this draw. Question count, difficulty mix, ticket values and the clock differ from one competition to the next.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_cd_tier_heading'  => array(
				'key'     => 'field_nera_saw_cd_tier_heading',
				'scope'   => 'option',
				'default' => __( 'Choose your tier', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_cd_runs_heading'  => array(
				'key'     => 'field_nera_saw_cd_runs_heading',
				'scope'   => 'option',
				'default' => __( 'How many runs?', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_cd_runs_note'     => array(
				'key'     => 'field_nera_saw_cd_runs_note',
				'scope'   => 'option',
				'default' => __( 'Each run is a fresh set of questions.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_cd_cta'           => array(
				'key'     => 'field_nera_saw_cd_cta',
				'scope'   => 'option',
				// Not "Enter for GBP 20" as the prototype has it: the price follows the
				// tier the player selects, and this button is rendered server-side
				// before that choice exists. A fixed price here would be wrong for
				// every tier but the first.
				'default' => __( 'Enter now', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_cd_risk'          => array(
				'key'     => 'field_nera_saw_cd_risk',
				'scope'   => 'option',
				'default' => __( 'Wrong answers earn nothing, and some runs end with zero tickets.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_cd_run_heading'   => array(
				'key'     => 'field_nera_saw_cd_run_heading',
				'scope'   => 'option',
				'default' => __( 'The run', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_cd_random_note'   => array(
				'key'     => 'field_nera_saw_cd_random_note',
				'scope'   => 'option',
				'default' => __( 'The order is shuffled for every run, so which question comes first is not shown.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_cd_result_title'  => array(
				'key'     => 'field_nera_saw_cd_result_title',
				'scope'   => 'option',
				'default' => __( 'Result', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_cd_result_note'   => array(
				'key'     => 'field_nera_saw_cd_result_note',
				'scope'   => 'option',
				'default' => __( 'Your tickets and entry numbers, straight into the draw.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_cd_footnote'      => array(
				'key'     => 'field_nera_saw_cd_footnote',
				'scope'   => 'option',
				'default' => __( 'A run can earn zero tickets. Only correct answers earn entries, and that is what makes this a game of skill, not a lottery.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),

			// --- how it works --------------------------------------------------
			'saw_hiw_heading'      => array(
				'key'     => 'field_nera_saw_hiw_heading',
				'scope'   => 'how-it-works',
				'default' => __( 'How it works', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_hiw_steps'        => array(
				'key'     => 'field_nera_saw_hiw_steps',
				'scope'   => 'how-it-works',
				// Joined rather than written as one string so each step is its own
				// translatable unit -- a translator should not have to keep four
				// sentences and their line breaks intact to change one of them.
				'default' => implode(
					"\n",
					array(
						__( 'You pay for one run at a timed quiz. Each competition sets its own number of questions, difficulty bands and ticket values.', 'nera-strikeawin' ),
						__( 'Each question is on a short clock. Correct answers earn tickets, and harder questions are worth more. The exact spec is shown on every competition page before you pay.', 'nera-strikeawin' ),
						__( 'Wrong answers and expired timers earn nothing, and take nothing away. You always finish the full run.', 'nera-strikeawin' ),
						__( 'Your tickets enter a random draw, drawn on the published date and independently witnessed.', 'nera-strikeawin' ),
					)
				),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_hiw_callout'      => array(
				'key'     => 'field_nera_saw_hiw_callout',
				'scope'   => 'how-it-works',
				'default' => __( 'The test is genuinely hard, and that is the point. There are no hints, no retries, no pauses and no way to buy more time. A meaningful share of runs end with zero tickets, no entry, and no refund.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_hiw_cta_label'    => array(
				'key'     => 'field_nera_saw_hiw_cta_label',
				'scope'   => 'how-it-works',
				'default' => __( 'Open the quick walkthrough', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_hiw_cta_url'      => array(
				'key'     => 'field_nera_saw_hiw_cta_url',
				'scope'   => 'how-it-works',
				// Empty on purpose, and the template hides the button when it is. The
				// walkthrough it points at in the prototype is an interactive screen
				// that does not exist here yet, and a button that 404s is worse than
				// no button.
				'default' => '',
				'seed'    => false,
				'fill'    => 'unset',
			),
			'saw_hiw_legal'        => array(
				'key'     => 'field_nera_saw_hiw_legal',
				'scope'   => 'how-it-works',
				// This screen suppresses the section footer, so this line is the only
				// place the required marks appear on it -- which is why the template
				// falls back to this default rather than rendering nothing when the
				// field is cleared. Clearing it cannot remove the 18+ mark.
				'default' => __( 'Skill-based prize competitions under the Gambling Act 2005. 18+ · BeGambleAware.org · Full terms on every draw.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),

			// --- checkout ------------------------------------------------------
			'saw_checkout_heading' => array(
				'key'     => 'field_nera_saw_checkout_heading',
				'scope'   => 'option',
				'default' => __( 'Checkout', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),

			// --- my account ------------------------------------------------------
			'saw_account_heading'  => array(
				'key'     => 'field_nera_saw_account_heading',
				'scope'   => 'option',
				'default' => __( 'My account', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),

			// --- before you pay ------------------------------------------------
			'saw_pp_heading'       => array(
				'key'     => 'field_nera_saw_pp_heading',
				'scope'   => 'before-you-pay',
				'default' => __( 'Before you pay', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_pp_risk'          => array(
				'key'     => 'field_nera_saw_pp_risk',
				'scope'   => 'before-you-pay',
				'default' => __( 'Every correct answer earns tickets. Wrong answers and expired timers earn nothing and take nothing away. You always finish the full run. A run can end with zero tickets, and no refund is due.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_pp_consent_age'   => array(
				'key'     => 'field_nera_saw_pp_consent_age',
				'scope'   => 'before-you-pay',
				'default' => __( 'I am 18 or over and a UK resident.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_pp_consent_rules' => array(
				'key'     => 'field_nera_saw_pp_consent_rules',
				'scope'   => 'before-you-pay',
				'default' => __( 'I have read the rules and understand a run can earn zero tickets, with no refund.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_pp_cta'           => array(
				'key'     => 'field_nera_saw_pp_cta',
				'scope'   => 'before-you-pay',
				// %s is the total. Unlike the competition page's button, the amount is
				// known by the time this screen renders -- the basket holds it -- which
				// is why the design shows it here and not there.
				'default' => __( 'Pay %s and start', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			'saw_pp_empty'         => array(
				'key'     => 'field_nera_saw_pp_empty',
				'scope'   => 'before-you-pay',
				'default' => __( 'There is nothing in your basket yet.', 'nera-strikeawin' ),
				'seed'    => true,
				'fill'    => 'empty',
			),
			// --- walkthrough ---------------------------------------------------
			'saw_wt_s1_title'      => array(
				'key' => 'field_nera_saw_wt_s1_title', 'scope' => 'walkthrough',
				'default' => __( 'One payment, one run', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s1_body'       => array(
				'key' => 'field_nera_saw_wt_s1_body', 'scope' => 'walkthrough',
				'default' => __( 'A run is a set of questions in stages, getting harder as you climb. You answer every one, whatever happens along the way. Each competition sets its own length, and the example below is one shape.', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s1_note'       => array(
				'key' => 'field_nera_saw_wt_s1_note', 'scope' => 'walkthrough',
				'default' => __( 'The same path shape appears on every competition page, from the prize down to your result.', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s2_title'      => array(
				'key' => 'field_nera_saw_wt_s2_title', 'scope' => 'walkthrough',
				'default' => __( 'What you earn', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s2_body'       => array(
				'key' => 'field_nera_saw_wt_s2_body', 'scope' => 'walkthrough',
				'default' => __( 'Only correct answers earn tickets, and harder questions pay more.', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s2_note'       => array(
				'key' => 'field_nera_saw_wt_s2_note', 'scope' => 'walkthrough',
				'default' => __( 'Below is one example set. Every competition sets its own number of questions, difficulty bands and ticket values, and shows them on its page before you pay.', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s2_summary'    => array(
				'key' => 'field_nera_saw_wt_s2_summary', 'scope' => 'walkthrough',
				'default' => __( 'On this example set, a perfect run would pay 25 tickets on Standard and 150 on Premium.', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s3_title'      => array(
				'key' => 'field_nera_saw_wt_s3_title', 'scope' => 'walkthrough',
				'default' => __( 'Try one', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s3_note'       => array(
				'key' => 'field_nera_saw_wt_s3_note', 'scope' => 'walkthrough',
				'default' => __( 'No money, no tickets, nothing at stake. This one is genuinely hard, like the real thing.', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s3_seconds'    => array(
				'key' => 'field_nera_saw_wt_s3_seconds', 'scope' => 'walkthrough',
				'default' => 7,
				'seed' => true, 'fill' => 'unset',
			),
			'saw_wt_s3_question'   => array(
				'key' => 'field_nera_saw_wt_s3_question', 'scope' => 'walkthrough',
				'default' => __( 'Which planet has the shortest day?', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s3_options'    => array(
				'key' => 'field_nera_saw_wt_s3_options', 'scope' => 'walkthrough',
				'default' => implode(
					"\n",
					array(
						__( 'Mercury', 'nera-strikeawin' ),
						__( 'Venus', 'nera-strikeawin' ),
						__( 'Jupiter', 'nera-strikeawin' ),
						__( 'Mars', 'nera-strikeawin' ),
					)
				),
				'seed' => true, 'fill' => 'empty',
			),
			// Jupiter, at about 9h 55m. The design's mock-up never marked an answer,
			// so this is the one number on the screen that had to be looked up rather
			// than copied -- and getting it wrong would teach a falsehood on the
			// screen that exists to build trust.
			'saw_wt_s3_answer'     => array(
				'key' => 'field_nera_saw_wt_s3_answer', 'scope' => 'walkthrough',
				'default' => 3,
				'seed' => true, 'fill' => 'unset',
			),
			'saw_wt_s3_right'      => array(
				'key' => 'field_nera_saw_wt_s3_right', 'scope' => 'walkthrough',
				'default' => __( 'Correct. In a real run that would have earned tickets.', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s3_wrong'      => array(
				'key' => 'field_nera_saw_wt_s3_wrong', 'scope' => 'walkthrough',
				'default' => __( 'Not this time. In a real run a wrong answer earns nothing, and takes nothing away.', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s4_title'      => array(
				'key' => 'field_nera_saw_wt_s4_title', 'scope' => 'walkthrough',
				'default' => __( 'Before you play', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s4_callout'    => array(
				'key' => 'field_nera_saw_wt_s4_callout', 'scope' => 'walkthrough',
				'default' => __( 'A run can earn zero tickets, and no refund is due. The questions are hard and the clock is short.', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s4_body'       => array(
				'key' => 'field_nera_saw_wt_s4_body', 'scope' => 'walkthrough',
				'default' => __( 'Then the draw: a random number from the pool, on the published date, streamed live and recorded.', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
			'saw_wt_s4_cta'        => array(
				'key' => 'field_nera_saw_wt_s4_cta', 'scope' => 'walkthrough',
				'default' => __( 'Back to the competitions', 'nera-strikeawin' ),
				'seed' => true, 'fill' => 'empty',
			),
		);
	}

	/**
	 * The designed value of one field.
	 *
	 * @param string $name Field name.
	 * @return mixed
	 */
	public static function default_for( $name ) {
		$all = self::catalogue();
		return isset( $all[ $name ] ) ? $all[ $name ]['default'] : '';
	}

	/* ---------------------------------------------------------------------
	 * Seeding
	 * ------------------------------------------------------------------ */

	/**
	 * Write the designed copy into fields that have never been saved.
	 *
	 * A seeded page was arriving with every field empty. The front end looked
	 * correct -- the templates fall back to these same values -- but the editor
	 * showed grey placeholders, so there was no way to tell designed copy from copy
	 * somebody wrote, and changing one word meant retyping the whole sentence first.
	 *
	 * Nothing an administrator wrote is ever overwritten -- these pages are not
	 * removed by the teardown and outlive the demo data around them, so re-running
	 * the seeder on a site in use has to be harmless. See `fill` in catalogue() for
	 * the two rules and why copy and the switch need different ones.
	 *
	 * Translations follow the same never-overwrite rule, checked against the i18n
	 * side table rather than the field's own value: seeding `ru` twice fills a
	 * translation that is still empty and leaves alone one an editor already
	 * wrote, exactly as the base copy does.
	 *
	 * @param array $langs        Language codes to also seed a translation for.
	 * @param array $translations Translated copy, `lang => field name => text`
	 *                            (from the seeder, which owns the written pools —
	 *                            this class owns the schema, not the copy).
	 * @return array{filled: string[], translated: string[]} Field names written,
	 *                                                        and "field@lang"
	 *                                                        translations written.
	 */
	public static function seed_defaults( array $langs = array(), array $translations = array() ) {
		if ( ! function_exists( 'update_field' ) ) {
			return array( 'filled' => array(), 'translated' => array() );
		}

		$written    = array();
		$translated = array();

		foreach ( self::catalogue() as $name => $field ) {
			if ( empty( $field['seed'] ) ) {
				continue;
			}

			if ( 'option' === $field['scope'] ) {
				$target = 'option';
				// ACF stores an options-page value as options_{name}. Its absence is
				// the only reliable "never saved" signal here.
				$saved = ( false !== get_option( 'options_' . $name, false ) );
			} else {
				$target = Nera_SAW_Standalone_Pages::page_id( $field['scope'] );
				if ( ! $target ) {
					continue;
				}
				$saved = metadata_exists( 'post', $target, $name );
			}

			if ( 'unset' === $field['fill'] ) {
				if ( ! $saved ) {
					update_field( $field['key'], $field['default'], $target );
					$written[] = $name;
				}
			} else {
				$current = get_field( $name, $target );
				if ( '' === $current || null === $current || false === $current || array() === $current ) {
					update_field( $field['key'], $field['default'], $target );
					$written[] = $name;
				}
			}

			foreach ( $langs as $lang ) {
				if ( ! isset( $translations[ $lang ][ $name ] ) ) {
					continue;
				}
				if ( '' !== self::get_translation( $name, $lang ) ) {
					continue;
				}
				self::set_translation( $name, $lang, $translations[ $lang ][ $name ] );
				$translated[] = $name . '@' . $lang;
			}
		}

		return array( 'filled' => $written, 'translated' => $translated );
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * A screen field, with its default.
	 *
	 * @param string $name    Field name.
	 * @param mixed  $default Fallback when empty.
	 * @param int    $post_id Page ID (0 = current).
	 * @return mixed
	 */
	public static function text( $name, $default = null, $post_id = 0 ) {
		if ( null === $default ) {
			$default = self::default_for( $name );
		}
		if ( ! function_exists( 'get_field' ) ) {
			return $default;
		}
		$post_id = $post_id ? (int) $post_id : get_the_ID();
		$value   = get_field( $name, $post_id );

		// An empty field is "leave it as designed", not "print nothing". The
		// alternative is a page that loses its heading the first time someone
		// clears a box to see what it does.
		$value = ( '' === $value || null === $value || false === $value ) ? $default : $value;

		return self::localize( $name, $value );
	}

	/**
	 * A shell field from the options page, with its default.
	 *
	 * @param string $name    Field name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function shell( $name, $default = null ) {
		if ( null === $default ) {
			$default = self::default_for( $name );
		}
		if ( ! function_exists( 'get_field' ) ) {
			return $default;
		}
		$value = get_field( $name, 'option' );
		$value = ( '' === $value || null === $value || false === $value ) ? $default : $value;

		return self::localize( $name, $value );
	}

	/**
	 * Swap in a seeded translation of a field when the request is being served
	 * in a language other than the site's default — the same "empty means use
	 * what's designed" logic `text()`/`shell()` apply to the base value, applied
	 * a second time to its translation. A field with no seeded translation, or a
	 * request in the default language, returns $value unchanged: this is a
	 * fallback on top of the existing value, never a second way to blank it.
	 *
	 * @param string $name  Field name.
	 * @param mixed  $value The value already resolved (real or default).
	 * @return mixed
	 */
	private static function localize( $name, $value ) {
		if ( ! class_exists( 'Nera_SAW_Language' ) ) {
			return $value;
		}

		$lang = Nera_SAW_Language::current();
		if ( $lang === Nera_SAW_Language::default_code() ) {
			return $value;
		}

		$translated = self::get_translation( $name, $lang );

		return ( '' !== $translated && null !== $translated ) ? $translated : $value;
	}

	/* ---------------------------------------------------------------------
	 * Translations
	 * ------------------------------------------------------------------ */

	/**
	 * A field's seeded translation, or '' when none was ever written.
	 *
	 * @param string $name Field name.
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function get_translation( $name, $lang ) {
		$map = (array) get_option( self::I18N_OPTION, array() );

		return isset( $map[ $name ][ $lang ] ) ? (string) $map[ $name ][ $lang ] : '';
	}

	/**
	 * Record a field's translation.
	 *
	 * @param string $name  Field name.
	 * @param string $lang  Language code.
	 * @param string $value Translated text.
	 */
	public static function set_translation( $name, $lang, $value ) {
		$map = (array) get_option( self::I18N_OPTION, array() );

		if ( ! isset( $map[ $name ] ) || ! is_array( $map[ $name ] ) ) {
			$map[ $name ] = array();
		}
		$map[ $name ][ $lang ] = (string) $value;

		update_option( self::I18N_OPTION, $map );
	}

	/* ---------------------------------------------------------------------
	 * Groups
	 * ------------------------------------------------------------------ */

	/**
	 * Header, footer and logo — shared by every standalone screen.
	 */
	private static function shell_group() {
		acf_add_local_field_group(
			array(
				'key'                   => 'group_nera_saw_shell',
				'title'                 => __( 'Strike A Win — header and footer', 'nera-strikeawin' ),
				'menu_order'            => 0,
				'position'              => 'normal',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'location'              => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => self::OPTIONS_SLUG,
						),
					),
				),
				'fields'                => array(
					array(
						'key'           => 'field_nera_saw_logo',
						'label'         => __( 'Logo', 'nera-strikeawin' ),
						'name'          => 'saw_logo',
						'type'          => 'image',
						'return_format' => 'array',
						'preview_size'  => 'medium',
						'instructions'  => __( 'Shown centred in the header on mobile and left-aligned on desktop. Leave empty to show the site name as text instead. A transparent PNG or SVG around 56px tall works best.', 'nera-strikeawin' ),
					),
					array(
						'key'          => 'field_nera_saw_logo_text',
						'label'        => __( 'Logo text', 'nera-strikeawin' ),
						'name'         => 'saw_logo_text',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_logo_text' ),
						'instructions' => __( 'Used when no logo image is set. Basic HTML is allowed, so <code>&lt;em&gt;a word&lt;/em&gt;</code> takes the accent colour.', 'nera-strikeawin' ),
					),
					array(
						'key'          => 'field_nera_saw_header_link',
						'label'        => __( 'Header link text', 'nera-strikeawin' ),
						'name'         => 'saw_header_link',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_header_link' ),
					),
					array(
						'key'          => 'field_nera_saw_footer_line',
						'label'        => __( 'Footer line', 'nera-strikeawin' ),
						'name'         => 'saw_footer_line',
						'type'         => 'text',
						'instructions' => __( 'The company line above the legal marks. The 18+ mark and the BeGambleAware link are not editable — they are required on every page of a paid prize-competition site.', 'nera-strikeawin' ),
					),
				),
			)
		);
	}

	/**
	 * Checkout and my account — both a single WooCommerce shortcode on their own
	 * page, so the only copy either has of its own is its screen heading.
	 */
	private static function checkout_account_group() {
		acf_add_local_field_group(
			array(
				'key'                   => 'group_nera_saw_checkout_account',
				'title'                 => __( 'Strike A Win — checkout & account', 'nera-strikeawin' ),
				'menu_order'            => 0,
				'position'              => 'normal',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'location'              => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => self::OPTIONS_SLUG,
						),
					),
				),
				'fields'                => array(
					array(
						'key'          => 'field_nera_saw_checkout_heading',
						'label'        => __( 'Checkout heading', 'nera-strikeawin' ),
						'name'         => 'saw_checkout_heading',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_checkout_heading' ),
					),
					array(
						'key'          => 'field_nera_saw_account_heading',
						'label'        => __( 'My account heading', 'nera-strikeawin' ),
						'name'         => 'saw_account_heading',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_account_heading' ),
					),
				),
			)
		);
	}

	/**
	 * The competitions list screen.
	 */
	private static function competitions_group() {
		acf_add_local_field_group(
			array(
				'key'                   => 'group_nera_saw_competitions',
				'title'                 => __( 'Strike A Win — competitions list', 'nera-strikeawin' ),
				'menu_order'            => 0,
				'position'              => 'normal',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'location'              => array(
					array(
						array(
							'param'    => 'page_template',
							'operator' => '==',
							'value'    => Nera_SAW_Standalone_Pages::TEMPLATE_PREFIX . 'competitions.php',
						),
					),
				),
				'fields'                => array(
					array(
						'key'         => 'field_nera_saw_c_eyebrow',
						'label'       => __( 'Eyebrow', 'nera-strikeawin' ),
						'name'        => 'saw_eyebrow',
						'type'        => 'text',
						'placeholder' => self::default_for( 'saw_eyebrow' ),
					),
					array(
						'key'          => 'field_nera_saw_c_heading',
						'label'        => __( 'Heading', 'nera-strikeawin' ),
						'name'         => 'saw_heading',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_heading' ),
						'instructions' => __( 'Basic HTML is allowed. Wrap a word in <code>&lt;em&gt;</code> to give it the accent colour, as the design does with “a”.', 'nera-strikeawin' ),
					),
					array(
						'key'         => 'field_nera_saw_c_lede',
						'label'       => __( 'Intro paragraph', 'nera-strikeawin' ),
						'name'        => 'saw_lede',
						'type'        => 'textarea',
						'rows'        => 2,
						'new_lines'   => '',
						'placeholder' => self::default_for( 'saw_lede' ),
					),
					array(
						'key'          => 'field_nera_saw_c_empty',
						'label'        => __( 'Message when nothing is open', 'nera-strikeawin' ),
						'name'         => 'saw_empty',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_empty' ),
						'instructions' => __( 'Shown in place of the cards when every competition has closed or sold out.', 'nera-strikeawin' ),
					),
					array(
						'key'   => 'field_nera_saw_c_panel_tab',
						'label' => __( 'Panel below the cards', 'nera-strikeawin' ),
						'name'  => '',
						'type'  => 'tab',
					),
					array(
						'key'          => 'field_nera_saw_c_panel_show',
						'label'        => __( 'Show the panel', 'nera-strikeawin' ),
						'name'         => 'saw_panel_show',
						'type'         => 'true_false',
						'ui'           => 1,
						'default_value' => self::default_for( 'saw_panel_show' ),
						'instructions' => __( 'The “Know what you’re buying” panel. It sets expectations about losing, which is why it exists — turning it off is a compliance decision, not a layout one.', 'nera-strikeawin' ),
					),
					array(
						'key'         => 'field_nera_saw_c_panel_heading',
						'label'       => __( 'Panel heading', 'nera-strikeawin' ),
						'name'        => 'saw_panel_heading',
						'type'        => 'text',
						'placeholder' => self::default_for( 'saw_panel_heading' ),
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_nera_saw_c_panel_show',
									'operator' => '==',
									'value'    => '1',
								),
							),
						),
					),
					array(
						'key'         => 'field_nera_saw_c_panel_body',
						'label'       => __( 'Panel text', 'nera-strikeawin' ),
						'name'        => 'saw_panel_body',
						'type'        => 'textarea',
						'rows'        => 3,
						'new_lines'   => '',
						'placeholder' => self::default_for( 'saw_panel_body' ),
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_nera_saw_c_panel_show',
									'operator' => '==',
									'value'    => '1',
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * The competition detail screen.
	 *
	 * On the options page beside the shell, because the screen has no page. See the
	 * note in catalogue() and the header of templates/standalone/competition.php.
	 */
	private static function competition_detail_group() {
		acf_add_local_field_group(
			array(
				'key'                   => 'group_nera_saw_detail',
				'title'                 => __( 'Strike A Win — competition page', 'nera-strikeawin' ),
				'menu_order'            => 1,
				'position'              => 'normal',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'location'              => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => self::OPTIONS_SLUG,
						),
					),
				),
				'fields'                => array(
					array(
						'key'          => 'field_nera_saw_cd_spec_heading',
						'label'        => __( 'Spec heading', 'nera-strikeawin' ),
						'name'         => 'saw_cd_spec_heading',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_cd_spec_heading' ),
						'instructions' => __( 'Above the block showing question count, timer and difficulty bands. Those numbers come from the competition itself and are not editable here — they are what the player is being promised.', 'nera-strikeawin' ),
					),
					array(
						'key'         => 'field_nera_saw_cd_spec_note',
						'label'       => __( 'Spec note', 'nera-strikeawin' ),
						'name'        => 'saw_cd_spec_note',
						'type'        => 'textarea',
						'rows'        => 2,
						'new_lines'   => '',
						'placeholder' => self::default_for( 'saw_cd_spec_note' ),
					),
					array(
						'key'   => 'field_nera_saw_cd_buy_tab',
						'label' => __( 'Buying', 'nera-strikeawin' ),
						'name'  => '',
						'type'  => 'tab',
					),
					array(
						'key'         => 'field_nera_saw_cd_tier_heading',
						'label'       => __( 'Tier heading', 'nera-strikeawin' ),
						'name'        => 'saw_cd_tier_heading',
						'type'        => 'text',
						'placeholder' => self::default_for( 'saw_cd_tier_heading' ),
					),
					array(
						'key'         => 'field_nera_saw_cd_runs_heading',
						'label'       => __( 'Quantity heading', 'nera-strikeawin' ),
						'name'        => 'saw_cd_runs_heading',
						'type'        => 'text',
						'placeholder' => self::default_for( 'saw_cd_runs_heading' ),
					),
					array(
						'key'         => 'field_nera_saw_cd_runs_note',
						'label'       => __( 'Quantity note', 'nera-strikeawin' ),
						'name'        => 'saw_cd_runs_note',
						'type'        => 'text',
						'placeholder' => self::default_for( 'saw_cd_runs_note' ),
					),
					array(
						'key'          => 'field_nera_saw_cd_cta',
						'label'        => __( 'Button', 'nera-strikeawin' ),
						'name'         => 'saw_cd_cta',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_cd_cta' ),
						'instructions' => __( 'Do not put a price in here. The price depends on the tier the player selects, and this button is built before that choice is made.', 'nera-strikeawin' ),
					),
					array(
						'key'          => 'field_nera_saw_cd_risk',
						'label'        => __( 'Line under the button', 'nera-strikeawin' ),
						'name'         => 'saw_cd_risk',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_cd_risk' ),
						'instructions' => __( 'The question count and the clock are printed before this sentence automatically. This is the part that says losing is normal, and it is the last thing read before paying.', 'nera-strikeawin' ),
					),
					array(
						'key'   => 'field_nera_saw_cd_run_tab',
						'label' => __( 'The run', 'nera-strikeawin' ),
						'name'  => '',
						'type'  => 'tab',
					),
					array(
						'key'         => 'field_nera_saw_cd_run_heading',
						'label'       => __( 'Run heading', 'nera-strikeawin' ),
						'name'        => 'saw_cd_run_heading',
						'type'        => 'text',
						'placeholder' => self::default_for( 'saw_cd_run_heading' ),
					),
					array(
						'key'          => 'field_nera_saw_cd_random_note',
						'label'        => __( 'Note shown on random quizzes', 'nera-strikeawin' ),
						'name'         => 'saw_cd_random_note',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_cd_random_note' ),
						'instructions' => __( 'Only appears when the Quiz Method is Random. Ladder shows the stages in order instead, because with Ladder the order is the product.', 'nera-strikeawin' ),
					),
					array(
						'key'         => 'field_nera_saw_cd_result_title',
						'label'       => __( 'Result strip title', 'nera-strikeawin' ),
						'name'        => 'saw_cd_result_title',
						'type'        => 'text',
						'placeholder' => self::default_for( 'saw_cd_result_title' ),
					),
					array(
						'key'         => 'field_nera_saw_cd_result_note',
						'label'       => __( 'Result strip note', 'nera-strikeawin' ),
						'name'        => 'saw_cd_result_note',
						'type'        => 'text',
						'placeholder' => self::default_for( 'saw_cd_result_note' ),
					),
					array(
						'key'          => 'field_nera_saw_cd_footnote',
						'label'        => __( 'Footnote', 'nera-strikeawin' ),
						'name'         => 'saw_cd_footnote',
						'type'         => 'textarea',
						'rows'         => 2,
						'new_lines'    => '',
						'placeholder'  => self::default_for( 'saw_cd_footnote' ),
						'instructions' => __( 'The skill-not-chance statement. It is the plugin\'s compliance basis in one sentence, so change the wording with legal rather than for fit.', 'nera-strikeawin' ),
					),
				),
			)
		);
	}

	/**
	 * The How it works screen.
	 */
	private static function how_it_works_group() {
		acf_add_local_field_group(
			array(
				'key'                   => 'group_nera_saw_hiw',
				'title'                 => __( 'Strike A Win — how it works', 'nera-strikeawin' ),
				'menu_order'            => 0,
				'position'              => 'normal',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'location'              => array(
					array(
						array(
							'param'    => 'page_template',
							'operator' => '==',
							'value'    => Nera_SAW_Standalone_Pages::TEMPLATE_PREFIX . 'how-it-works.php',
						),
					),
				),
				'fields'                => array(
					array(
						'key'          => 'field_nera_saw_hiw_heading',
						'label'        => __( 'Heading', 'nera-strikeawin' ),
						'name'         => 'saw_hiw_heading',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_hiw_heading' ),
						'instructions' => __( 'Basic HTML is allowed. Wrap a word in <code>&lt;em&gt;</code> to give it the accent colour.', 'nera-strikeawin' ),
					),
					array(
						'key'          => 'field_nera_saw_hiw_steps',
						'label'        => __( 'Steps', 'nera-strikeawin' ),
						'name'         => 'saw_hiw_steps',
						'type'         => 'textarea',
						'rows'         => 8,
						'new_lines'    => '',
						'placeholder'  => self::default_for( 'saw_hiw_steps' ),
						'instructions' => __( 'One step per line. They are numbered automatically, so do not type the numbers — deleting a line would leave a gap. Blank lines are ignored.', 'nera-strikeawin' ),
					),
					array(
						'key'          => 'field_nera_saw_hiw_callout',
						'label'        => __( 'Warning panel', 'nera-strikeawin' ),
						'name'         => 'saw_hiw_callout',
						'type'         => 'textarea',
						'rows'         => 3,
						'new_lines'    => '',
						'placeholder'  => self::default_for( 'saw_hiw_callout' ),
						'instructions' => __( 'The highlighted box that says the test is hard and that losing is normal. Clearing it removes the box entirely, which is a compliance decision rather than a layout one.', 'nera-strikeawin' ),
					),
					array(
						'key'   => 'field_nera_saw_hiw_cta_tab',
						'label' => __( 'Walkthrough button', 'nera-strikeawin' ),
						'name'  => '',
						'type'  => 'tab',
					),
					array(
						'key'          => 'field_nera_saw_hiw_cta_url',
						'label'        => __( 'Button address', 'nera-strikeawin' ),
						'name'         => 'saw_hiw_cta_url',
						'type'         => 'url',
						'instructions' => __( 'Leave empty and no button is shown. The walkthrough it points at in the design has not been built yet, so there is deliberately nothing here — fill it in once there is somewhere to send people.', 'nera-strikeawin' ),
					),
					array(
						'key'         => 'field_nera_saw_hiw_cta_label',
						'label'       => __( 'Button text', 'nera-strikeawin' ),
						'name'        => 'saw_hiw_cta_label',
						'type'        => 'text',
						'placeholder' => self::default_for( 'saw_hiw_cta_label' ),
						'conditional_logic' => array(
							array(
								array(
									'field'    => 'field_nera_saw_hiw_cta_url',
									'operator' => '!=empty',
								),
							),
						),
					),
					array(
						'key'   => 'field_nera_saw_hiw_legal_tab',
						'label' => __( 'Legal', 'nera-strikeawin' ),
						'name'  => '',
						'type'  => 'tab',
					),
					array(
						'key'         => 'field_nera_saw_hiw_legal',
						'label'       => __( 'Legal line', 'nera-strikeawin' ),
						'name'        => 'saw_hiw_legal',
						'type'        => 'textarea',
						'rows'        => 2,
						'new_lines'   => '',
						'placeholder' => self::default_for( 'saw_hiw_legal' ),
					),
				),
			)
		);
	}

	/**
	 * The Before you pay screen.
	 *
	 * Everything on it except the numbers, which come from the basket and from the
	 * competition. The two consent lines are the ones a complaint will quote back,
	 * so they are editable without a deploy.
	 */
	private static function pre_payment_group() {
		acf_add_local_field_group(
			array(
				'key'                   => 'group_nera_saw_prepay',
				'title'                 => __( 'Strike A Win — before you pay', 'nera-strikeawin' ),
				'menu_order'            => 0,
				'position'              => 'normal',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'location'              => array(
					array(
						array(
							'param'    => 'page_template',
							'operator' => '==',
							'value'    => Nera_SAW_Standalone_Pages::TEMPLATE_PREFIX . 'before-you-pay.php',
						),
					),
				),
				'fields'                => array(
					array(
						'key'         => 'field_nera_saw_pp_heading',
						'label'       => __( 'Heading', 'nera-strikeawin' ),
						'name'        => 'saw_pp_heading',
						'type'        => 'text',
						'placeholder' => self::default_for( 'saw_pp_heading' ),
					),
					array(
						'key'          => 'field_nera_saw_pp_risk',
						'label'        => __( 'What the player is agreeing to', 'nera-strikeawin' ),
						'name'         => 'saw_pp_risk',
						'type'         => 'textarea',
						'rows'         => 4,
						'new_lines'    => '',
						'placeholder'  => self::default_for( 'saw_pp_risk' ),
						'instructions' => __( 'The last thing read before the consent boxes. The question count, the clock and the ticket ceiling are printed above it automatically from the competition, so do not repeat them here — they would go stale the moment a competition is set up differently.', 'nera-strikeawin' ),
					),
					array(
						'key'   => 'field_nera_saw_pp_consent_tab',
						'label' => __( 'Consent', 'nera-strikeawin' ),
						'name'  => '',
						'type'  => 'tab',
					),
					array(
						'key'          => 'field_nera_saw_pp_consent_age',
						'label'        => __( 'First checkbox', 'nera-strikeawin' ),
						'name'         => 'saw_pp_consent_age',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_pp_consent_age' ),
						'instructions' => __( 'Both boxes are required by the browser itself, so the button cannot be used until they are ticked. Clearing the text does not remove the box.', 'nera-strikeawin' ),
					),
					array(
						'key'          => 'field_nera_saw_pp_consent_rules',
						'label'        => __( 'Second checkbox', 'nera-strikeawin' ),
						'name'         => 'saw_pp_consent_rules',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_pp_consent_rules' ),
						'instructions' => __( 'Basic HTML is allowed, so the word “rules” can be a link to your terms.', 'nera-strikeawin' ),
					),
					array(
						'key'   => 'field_nera_saw_pp_button_tab',
						'label' => __( 'Button', 'nera-strikeawin' ),
						'name'  => '',
						'type'  => 'tab',
					),
					array(
						'key'          => 'field_nera_saw_pp_cta',
						'label'        => __( 'Button text', 'nera-strikeawin' ),
						'name'         => 'saw_pp_cta',
						'type'         => 'text',
						'placeholder'  => self::default_for( 'saw_pp_cta' ),
						'instructions' => __( 'Use <code>%s</code> where the total should appear. Leave it out and the total is added at the end instead — the amount about to be charged is never hidden.', 'nera-strikeawin' ),
					),
					array(
						'key'         => 'field_nera_saw_pp_empty',
						'label'       => __( 'Message when the basket is empty', 'nera-strikeawin' ),
						'name'        => 'saw_pp_empty',
						'type'        => 'text',
						'placeholder' => self::default_for( 'saw_pp_empty' ),
					),
				),
			)
		);
	}

	/**
	 * The walkthrough.
	 *
	 * Grouped by step, because that is how somebody reads it and how they will be
	 * told which bit is wrong ("step three says...").
	 */
	private static function walkthrough_group() {
		$tab = function ( $key, $label ) {
			return array( 'key' => $key, 'label' => $label, 'name' => '', 'type' => 'tab' );
		};
		$text = function ( $name, $label, $rows = 0, $instructions = '' ) {
			$f = array(
				'key'         => 'field_nera_' . $name,
				'label'       => $label,
				'name'        => $name,
				'type'        => $rows ? 'textarea' : 'text',
				'placeholder' => self::default_for( $name ),
			);
			if ( $rows ) {
				$f['rows']      = $rows;
				$f['new_lines'] = '';
			}
			if ( $instructions ) {
				$f['instructions'] = $instructions;
			}
			return $f;
		};

		acf_add_local_field_group(
			array(
				'key'                   => 'group_nera_saw_walkthrough',
				'title'                 => __( 'Strike A Win — walkthrough', 'nera-strikeawin' ),
				'menu_order'            => 0,
				'position'              => 'normal',
				'label_placement'       => 'top',
				'instruction_placement' => 'label',
				'active'                => true,
				'location'              => array(
					array(
						array(
							'param'    => 'page_template',
							'operator' => '==',
							'value'    => Nera_SAW_Standalone_Pages::TEMPLATE_PREFIX . 'walkthrough.php',
						),
					),
				),
				'fields'                => array(
					$tab( 'field_nera_saw_wt_t1', __( '1 · The run', 'nera-strikeawin' ) ),
					$text( 'saw_wt_s1_title', __( 'Heading', 'nera-strikeawin' ) ),
					$text( 'saw_wt_s1_body', __( 'Body', 'nera-strikeawin' ), 3 ),
					$text( 'saw_wt_s1_note', __( 'Note under the diagram', 'nera-strikeawin' ), 2,
						__( 'The diagram itself is a fixed example. Its colours come from the same difficulty ramp every competition uses.', 'nera-strikeawin' ) ),

					$tab( 'field_nera_saw_wt_t2', __( '2 · What you earn', 'nera-strikeawin' ) ),
					$text( 'saw_wt_s2_title', __( 'Heading', 'nera-strikeawin' ) ),
					$text( 'saw_wt_s2_body', __( 'Body', 'nera-strikeawin' ), 2 ),
					$text( 'saw_wt_s2_note', __( 'Note', 'nera-strikeawin' ), 3 ),
					$text( 'saw_wt_s2_summary', __( 'Summary line', 'nera-strikeawin' ), 2,
						__( 'The ladder above it is a fixed example, so the figures here are typed rather than calculated. Change both together or they will disagree.', 'nera-strikeawin' ) ),

					$tab( 'field_nera_saw_wt_t3', __( '3 · Practice question', 'nera-strikeawin' ) ),
					$text( 'saw_wt_s3_title', __( 'Heading', 'nera-strikeawin' ) ),
					$text( 'saw_wt_s3_note', __( 'Note', 'nera-strikeawin' ), 2 ),
					array(
						'key'          => 'field_nera_saw_wt_s3_seconds',
						'label'        => __( 'Timer shown', 'nera-strikeawin' ),
						'name'         => 'saw_wt_s3_seconds',
						'type'         => 'number',
						'min'          => 1,
						'placeholder'  => self::default_for( 'saw_wt_s3_seconds' ),
						'instructions' => __( 'A picture of the control, not a working clock — nothing counts down here and nothing expires. A practice timer that ran out would either do nothing, which teaches the wrong thing, or take something away in a walkthrough.', 'nera-strikeawin' ),
					),
					$text( 'saw_wt_s3_question', __( 'Question', 'nera-strikeawin' ) ),
					$text( 'saw_wt_s3_options', __( 'Answers', 'nera-strikeawin' ), 5,
						__( 'One per line, in the order they appear.', 'nera-strikeawin' ) ),
					array(
						'key'          => 'field_nera_saw_wt_s3_answer',
						'label'        => __( 'Which line is correct', 'nera-strikeawin' ),
						'name'         => 'saw_wt_s3_answer',
						'type'         => 'number',
						'min'          => 1,
						'placeholder'  => self::default_for( 'saw_wt_s3_answer' ),
						'instructions' => __( 'Counting from 1. Check it against the answers above after any edit — this is the one screen whose job is to be believed, and a wrong key here teaches a falsehood.', 'nera-strikeawin' ),
					),
					$text( 'saw_wt_s3_right', __( 'Shown for a correct pick', 'nera-strikeawin' ), 2 ),
					$text( 'saw_wt_s3_wrong', __( 'Shown for a wrong pick', 'nera-strikeawin' ), 2 ),

					$tab( 'field_nera_saw_wt_t4', __( '4 · Before you play', 'nera-strikeawin' ) ),
					$text( 'saw_wt_s4_title', __( 'Heading', 'nera-strikeawin' ) ),
					$text( 'saw_wt_s4_callout', __( 'Warning panel', 'nera-strikeawin' ), 3,
						__( 'The statement that a run can earn nothing. Clearing it removes the panel, which is a compliance decision rather than a layout one.', 'nera-strikeawin' ) ),
					$text( 'saw_wt_s4_body', __( 'Body', 'nera-strikeawin' ), 2 ),
					$text( 'saw_wt_s4_cta', __( 'Button', 'nera-strikeawin' ) ),
				),
			)
		);
	}
}
