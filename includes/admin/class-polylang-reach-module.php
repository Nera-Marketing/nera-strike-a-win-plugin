<?php
/**
 * The reach control as Polylang draws it.
 *
 * Loaded only from `Nera_SAW_Language_Reach::register_module()`, on Polylang's own
 * settings screen. It extends a Polylang class, so requiring it anywhere else would
 * be a fatal on a site without Polylang.
 *
 * The saving is our own rather than Polylang's. `PLL_Settings_Module::save_options()`
 * merges whatever was posted into Polylang's typed options object, which validates
 * against its own schema — this setting is not one of Polylang's and does not belong
 * in there. So the module keeps the card and the form, and writes its own option.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Polylang_Reach_Module
 */
class Nera_SAW_Polylang_Reach_Module extends PLL_Settings_Module {

	/**
	 * Where it sits on the settings screen — after Polylang's own cards, because it
	 * is a decision about Polylang's output rather than about Polylang.
	 *
	 * @var int
	 */
	public $priority = 95;

	/**
	 * Constructor.
	 *
	 * @param PLL_Settings $polylang Polylang settings object.
	 */
	public function __construct( &$polylang ) {
		parent::__construct(
			$polylang,
			array(
				'module'      => 'nera_saw_reach',
				'title'       => __( 'Strike A Win — language reach', 'nera-strikeawin' ),
				'description' => __( 'How far the languages above are served on the front end. The admin is unaffected either way: language columns, filters and per-post language pickers keep working, so pages can be translated before they are served.', 'nera-strikeawin' ),
			)
		);
	}

	/**
	 * Always available — there is nothing to switch on.
	 *
	 * @return bool
	 */
	public function is_active() {
		return true;
	}

	/**
	 * The two radios.
	 */
	protected function form() {
		$reach = Nera_SAW_Language_Reach::reach();

		$choices = array(
			Nera_SAW_Language_Reach::WHOLE_SITE   => array(
				'label' => __( 'The whole site', 'nera-strikeawin' ),
				'help'  => __( 'Polylang behaves normally. Every language is served everywhere.', 'nera-strikeawin' ),
			),
			Nera_SAW_Language_Reach::SECTION_ONLY => array(
				'label' => __( 'Strike A Win section only', 'nera-strikeawin' ),
				'help'  => __( 'The competition section is multilingual. The rest of the front end is held to the default language: no switcher, and a non-default URL is redirected to the canonical page instead of serving the same content twice.', 'nera-strikeawin' ),
			),
		);

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Language reach', 'nera-strikeawin' ) . '</legend>';

		foreach ( $choices as $value => $choice ) {
			printf(
				'<label style="display:block;margin:0 0 10px"><input name="%1$s" type="radio" value="%2$s"%3$s> <strong>%4$s</strong><span style="display:block;margin:2px 0 0 25px;color:#646970">%5$s</span></label>',
				esc_attr( Nera_SAW_Language_Reach::OPTION ),
				esc_attr( $value ),
				checked( $reach, $value, false ),
				esc_html( $choice['label'] ),
				esc_html( $choice['help'] )
			);
		}

		echo '</fieldset>';

		if ( class_exists( 'Nera_SAW_Mode' ) && ! Nera_SAW_Mode::is_standalone() ) {
			printf(
				'<p class="description" style="margin-top:12px">%s</p>',
				esc_html__( 'Strike A Win is currently in mixed mode, so it has no section of its own. "Strike A Win section only" will hold the whole front end to one language until standalone mode is switched on.', 'nera-strikeawin' )
			);
		}
	}

	/**
	 * Save our own option, and leave Polylang's alone.
	 */
	public function save_options() {
		check_ajax_referer( 'pll_options', '_pll_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$module = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		if ( $this->module !== $module ) {
			return;
		}

		$posted = isset( $_POST[ Nera_SAW_Language_Reach::OPTION ] )
			? sanitize_key( wp_unslash( $_POST[ Nera_SAW_Language_Reach::OPTION ] ) )
			: Nera_SAW_Language_Reach::WHOLE_SITE;

		$value = Nera_SAW_Language_Reach::SECTION_ONLY === $posted
			? Nera_SAW_Language_Reach::SECTION_ONLY
			: Nera_SAW_Language_Reach::WHOLE_SITE;

		update_option( Nera_SAW_Language_Reach::OPTION, $value );

		ob_start();
		pll_add_notice( new WP_Error( 'settings_updated', __( 'Settings saved.', 'polylang' ), 'success' ) );
		settings_errors( 'polylang' );

		$response = new WP_Ajax_Response( array( 'what' => 'success', 'data' => ob_get_clean() ) );
		$response->send();
	}
}
