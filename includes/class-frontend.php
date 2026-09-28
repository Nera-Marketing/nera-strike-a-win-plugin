<?php
/**
 * Front-end quiz surface: the [strikeawin_quiz] shortcode + Vite/Vue assets.
 *
 * The gameplay is rendered by a Vue island (built with Vite). The server stays
 * authoritative: Vue calls the drip REST API per slot; the answer key never
 * ships to the client; localStorage holds only a breadcrumb.
 *
 * Dev mode: define( 'NERA_SAW_DEV', true ) in wp-config.php and run `npm run dev`
 * in the plugin directory (port 5175).
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Frontend
 */
class Nera_SAW_Frontend {

	const SHORTCODE      = 'strikeawin_quiz';
	const SCRIPT_HANDLE  = 'nera-saw-app';
	const STYLE_HANDLE   = 'nera-saw-styles';
	const DATA_OBJECT    = 'NeraSAW';
	const DEV_SERVER_URL = 'http://localhost:5175';
	const DEV_ENTRY      = 'src/strikeawin.js';

	/**
	 * Hook shortcode + play-page chrome.
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_play_page_assets' ), 16 );
	}

	/**
	 * Whether the current request is the configured play page.
	 *
	 * @return bool
	 */
	private static function is_play_page() {
		$page_id = Nera_SAW_Play_Page::get_page_id();
		return $page_id > 0 && is_page( $page_id );
	}

	/**
	 * Full-width play page layout + background (Spin To Win parity).
	 */
	public static function enqueue_play_page_assets() {
		if ( ! self::is_play_page() ) {
			return;
		}
		wp_enqueue_style(
			'nera-saw-play-page',
			NERA_SAW_PLUGIN_URL . 'assets/css/play-page.css',
			array(),
			NERA_SAW_VERSION
		);
	}

	/**
	 * @param array $classes Body classes.
	 * @return array
	 */
	public static function body_class( $classes ) {
		if ( self::is_play_page() ) {
			$classes[] = 'nera-strikeawin-page';
		}
		return $classes;
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		self::enqueue_shell_styles();

		if ( ! is_user_logged_in() ) {
			$inner = '<div class="saw-login-card"><p class="saw-login-text">' . sprintf(
				/* translators: %s login url */
				wp_kses_post( __( 'Please <a href="%s">log in</a> to use your runs and earn lottery tickets.', 'nera-strikeawin' ) ),
				esc_url( wp_login_url( get_permalink() ) )
			) . '</p></div>';

			return self::wrap_page_shell(
				$inner,
				array(
					'title' => __( 'Play Strike A Win', 'nera-strikeawin' ),
					'intro' => __( 'Log in to play the quiz and earn tickets for the draw.', 'nera-strikeawin' ),
				)
			);
		}

		/*
		 * The URL wins when both are present, so a deep link (e.g. an order's Play
		 * button) can still send a player to a specific competition on the play
		 * page even though that page's shortcode carries no `id` of its own. The
		 * attribute exists for the opposite case: embedding this competition's
		 * quiz on some OTHER page, where there is no `saw_competition` query arg
		 * to read.
		 */
		$atts = shortcode_atts( array( 'id' => 0, 'tier' => '' ), $atts, self::SHORTCODE );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$competition_id = isset( $_GET[ Nera_SAW_Play_Page::QV_COMPETITION ] )
			? absint( $_GET[ Nera_SAW_Play_Page::QV_COMPETITION ] )
			: absint( $atts['id'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tier_key = isset( $_GET[ Nera_SAW_Play_Page::QV_TIER ] )
			? sanitize_key( wp_unslash( $_GET[ Nera_SAW_Play_Page::QV_TIER ] ) )
			: sanitize_key( $atts['tier'] );

		// A run is started ONLY from the Overview screen's Start button (token-gated,
		// in-page); no URL starts a run (ADR 0007). So the play page always renders
		// the Overview for a competition, or the hub. A tier narrows which of that
		// competition's Start cards the Overview shows — it never skips the click.
		if ( $competition_id > 0 ) {
			return self::wrap_page_shell(
				self::render_competition_overview( $competition_id, $tier_key ),
				self::competition_hero( wc_get_product( $competition_id ), $competition_id )
			);
		}

		return self::wrap_page_shell(
			self::render_hub(),
			array(
				'title' => __( 'Your Strike A Win entries', 'nera-strikeawin' ),
				'intro' => __( 'Use your runs from ticket purchases — answer the quiz to earn lottery tickets.', 'nera-strikeawin' ),
			)
		);
	}

	/**
	 * Shell styles for hub + overview (Vue ships its own gameplay CSS).
	 */
	private static function enqueue_shell_styles() {
		wp_enqueue_style( self::STYLE_HANDLE . '-shell', NERA_SAW_PLUGIN_URL . 'assets/css/quiz.css', array(), NERA_SAW_VERSION );
	}

	/**
	 * Enqueue the Vue island (Vite manifest in prod, dev server in dev) + localise.
	 *
	 * Public: the standalone section's own play template mounts the same Vue app
	 * without going through render()/render_competition_overview(), so it calls
	 * this directly.
	 */
	public static function enqueue_app( $competition_id = 0 ) {
		self::enqueue_shell_styles();

		$feedback_on = Nera_SAW_Constants::quiz_feedback_enabled();
		$competition_id = (int) $competition_id;

		// Which languages the quiz-language screen can actually offer for THIS
		// competition — empty on a monolingual site, or when the Russian (etc.)
		// bank is too thin for this competition's distribution. See
		// Nera_SAW_Run::playable_languages() and CONTEXT.md "Bank health".
		$languages = $competition_id ? Nera_SAW_Run::playable_languages( $competition_id ) : array();
		$language_names = array();
		foreach ( $languages as $code ) {
			$language_names[ $code ] = class_exists( 'Nera_SAW_Language' ) ? Nera_SAW_Language::name( $code ) : $code;
		}

		// Stage count + stage 1's label/colour, computed WITHOUT starting a run, so
		// the quiz-language screen (shown before any slot exists) can render the same
		// .saw-stagebar header the question/stage-break screens use instead of a bare
		// card — matching the reference design's screen 24. "Stage" is only a
		// deterministic, pre-run concept in ladder mode (Nera_SAW_Run::stage_of()'s own
		// doc comment): in random mode every question is its own stage and slot 1's
		// level is shuffled server-side per run, so a "first stage" label/colour would
		// just be a guess. Leave them blank there rather than show something that
		// might not match the run once it actually starts; stageCount still gets a
		// real (deterministic) total-question count so the "Stage 1 of N" text isn't
		// stuck at a hardcoded default.
		$stage_count        = 0;
		$first_stage_label  = '';
		$first_stage_color  = '';
		if ( $competition_id ) {
			$config       = Nera_SAW_Competition_Config::get( $competition_id );
			$distribution = array_filter( (array) $config['distribution'] );
			if ( Nera_SAW_Mode::is_ladder( $competition_id ) ) {
				$stage_count = count( $distribution );
				foreach ( Nera_SAW_Constants::ladder() as $level ) {
					if ( ! empty( $distribution[ $level['key'] ] ) ) {
						$first_stage_label = (string) $level['label'];
						$first_stage_color = Nera_SAW_Constants::level_text_color( $level['key'] );
						break;
					}
				}
			} else {
				$stage_count = Nera_SAW_Competition_Config::total_questions( $config );
			}
		}

		$data = array(
			'root'               => esc_url_raw( rest_url( Nera_SAW_Rest::NS ) ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			// wp_localize_script string-casts scalars (false → ""), so use 1/0.
			'showAnswerFeedback' => $feedback_on ? 1 : 0,
			'timerWarnSeconds'   => Nera_SAW_Constants::timer_warn_seconds(),
			'feedbackSeconds'    => Nera_SAW_Constants::feedback_seconds(),
			'playableLanguages'  => $languages,
			'languageNames'      => $language_names,
			// Pre-run stage header data — see the comment above where these are
			// computed. Read by App.vue's language phase only.
			'stageCount'         => $stage_count,
			'firstStageLabel'    => $first_stage_label,
			'firstStageColor'    => $first_stage_color,
			// "Back to competitions" on the results screen — the section's own list
			// in standalone; the main shop page in mix, where there is no equivalent
			// dedicated list. Never the empty string, so the button is never dead.
			'competitionsUrl'    => esc_url_raw(
				( class_exists( 'Nera_SAW_Standalone_Pages' ) && Nera_SAW_Standalone_Pages::url( 'competitions' ) )
					? Nera_SAW_Standalone_Pages::url( 'competitions' )
					: ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) )
			),
			'strings'            => array(
				'loading'            => __( 'Loading your entry…', 'nera-strikeawin' ),
				'viewCompetition'    => __( 'View competition', 'nera-strikeawin' ),
				'playAgain'          => __( 'Play again', 'nera-strikeawin' ),
				'selectAnswer'       => __( 'Choose an answer, then confirm to lock it in.', 'nera-strikeawin' ),
				'submitAnswer'       => __( 'Submit answer', 'nera-strikeawin' ),
				'checkingAnswer'     => __( 'Checking…', 'nera-strikeawin' ),
				'nextQuestion'       => __( 'Next question', 'nera-strikeawin' ),
				'continueLabel'      => __( 'Continue', 'nera-strikeawin' ),
				'seeResults'         => __( 'See my results', 'nera-strikeawin' ),
				// Screen-reader-only announcements for the Answer reveal (the visual
				// reveal is colour alone, which assistive tech cannot convey).
				'revealCorrect'      => __( 'Correct. %d tickets earned.', 'nera-strikeawin' ),
				'revealWrong'        => __( 'Wrong. The correct answer was: %s', 'nera-strikeawin' ),
				'revealTimeout'      => __( 'Time up, no answer counted. The correct answer was: %s', 'nera-strikeawin' ),
				'revealWrongBare'    => __( 'Wrong — no tickets.', 'nera-strikeawin' ),
				'revealTimeoutBare'  => __( 'Time up — no answer counted.', 'nera-strikeawin' ),
				'leaveWarning'       => __( 'Leave this page? The timer keeps running and unanswered questions score zero.', 'nera-strikeawin' ),
				'leaveDialogTitle'   => __( 'Leave the quiz?', 'nera-strikeawin' ),
				'leaveDialogBody'    => __( 'The countdown keeps running on the server while you are away. Any question you have not submitted scores zero.', 'nera-strikeawin' ),
				// Shown instead of the above during the Answer reveal, where the
				// current question is already scored and no clock is running.
				'leaveDialogBodyReveal' => __( 'This answer is already saved. If you leave now, every question you have not reached scores zero.', 'nera-strikeawin' ),
				'stayOnQuiz'         => __( 'Keep playing', 'nera-strikeawin' ),
				'leaveQuiz'          => __( 'Leave anyway', 'nera-strikeawin' ),
				'mintingTickets'     => __( 'Adding your tickets to the draw…', 'nera-strikeawin' ),
				'yourTicketNumbers'  => __( 'Your ticket numbers', 'nera-strikeawin' ),
				'runsRemaining'      => __( '%d runs left for this competition', 'nera-strikeawin' ),
				'runsRemainingTier'  => __( '%d runs left on %s', 'nera-strikeawin' ),
				'ticketsMintError'   => __( 'We could not add your tickets right now.', 'nera-strikeawin' ),
				'retryTickets'       => __( 'Try again', 'nera-strikeawin' ),
				'tryAgain'           => __( 'Try again', 'nera-strikeawin' ),
				'errorContactAdmin'  => __( 'Something went wrong. Please contact support and we will investigate and restore your run.', 'nera-strikeawin' ),
				'runRef'             => __( 'Run reference', 'nera-strikeawin' ),
				// Quiz language screen.
				'quizLanguageTitle'  => __( 'Quiz language', 'nera-strikeawin' ),
				'quizLanguageIntro'  => __( 'Questions and answers will appear in this language', 'nera-strikeawin' ),
				// Question screen.
				'questionOf'         => __( 'Question %1$d of %2$d', 'nera-strikeawin' ),
				'worthTickets'       => __( 'Worth %d tickets', 'nera-strikeawin' ),
				'worthTicket'        => __( 'Worth %d ticket', 'nera-strikeawin' ),
				'ticketsLabel'       => __( 'Tickets', 'nera-strikeawin' ),
				'stageOf'            => __( 'Stage %1$d of %2$d · %3$s', 'nera-strikeawin' ),
				'bankedLine'         => __( '+%1$d banked. %2$d tickets total.', 'nera-strikeawin' ),
				'timeUpLine'         => __( "Time's up. %d tickets total.", 'nera-strikeawin' ),
				'wrongLine'          => __( 'Not this time. %d tickets total.', 'nera-strikeawin' ),
				// Stage break.
				'stageBreakEyebrow'  => __( 'Stage %1$d of %2$d', 'nera-strikeawin' ),
				// Per-stage headline, read by App.vue as `stageBreakHeadline_{stage_no}`
				// with the difficulty label itself as the fallback for any stage
				// number beyond this list (a competition can have more or fewer
				// stages than the five named here). Stage 2's "Stepping up" is the
				// reference design's own example; the rest follow its tone.
				'stageBreakHeadline_1' => __( 'Warming up', 'nera-strikeawin' ),
				'stageBreakHeadline_2' => __( 'Stepping up', 'nera-strikeawin' ),
				'stageBreakHeadline_3' => __( 'Getting serious', 'nera-strikeawin' ),
				'stageBreakHeadline_4' => __( 'Into the hard part', 'nera-strikeawin' ),
				'stageBreakHeadline_5' => __( 'Final stretch', 'nera-strikeawin' ),
				// Results.
				'runComplete'        => __( 'Run complete', 'nera-strikeawin' ),
				'inTheDrawTitle'     => __( "You're in the draw", 'nera-strikeawin' ),
				'inTheDrawSubtitle'  => __( '%1$d tickets banked from %2$d correct answers.', 'nera-strikeawin' ),
				'zeroTicketsTitle'   => __( 'No tickets this run', 'nera-strikeawin' ),
				'zeroCorrectSubtitle' => __( 'None of the %d answers landed in time.', 'nera-strikeawin' ),
				'zeroSomeCorrectSubtitle' => __( '%1$d of %2$d correct, but not enough to bank a ticket.', 'nera-strikeawin' ),
				'scoreLabel'         => __( 'Score', 'nera-strikeawin' ),
				'scoreValue'         => __( '%1$d of %2$d correct', 'nera-strikeawin' ),
				'ticketsEarnedLabel' => __( 'Tickets earned', 'nera-strikeawin' ),
				'yourEntryNumbers'   => __( 'Your entry numbers', 'nera-strikeawin' ),
				'moreNumbers'        => __( '+%d more', 'nera-strikeawin' ),
				'numbersPoolNote'    => __( 'Numbers are allocated at random from this draw\'s pool.', 'nera-strikeawin' ),
				'drawInfoWithEntry'  => __( 'Random draw, independently witnessed. You\'ll be notified either way.', 'nera-strikeawin' ),
				'drawDatePrefix'     => __( 'Draw: %s', 'nera-strikeawin' ),
				'noEntryNote'        => __( 'No tickets were earned, so there is no entry in this draw, and no refund is due.', 'nera-strikeawin' ),
				'drawClosesPrefix'   => __( 'The draw closes %s.', 'nera-strikeawin' ),
				'playAnotherRun'     => __( 'Play another run', 'nera-strikeawin' ),
				'backToCompetitions' => __( 'Back to competitions', 'nera-strikeawin' ),
			),
		);

		if ( self::is_dev_server_running() ) {
			add_action( 'wp_head', array( __CLASS__, 'inject_vite_client' ), 1 );
			add_action( 'wp_footer', array( __CLASS__, 'inject_dev_entry' ), 5 );
			wp_register_script( self::SCRIPT_HANDLE, '', array(), NERA_SAW_VERSION, true );
			wp_enqueue_script( self::SCRIPT_HANDLE );
		} else {
			self::enqueue_from_manifest();
		}

		wp_localize_script( self::SCRIPT_HANDLE, self::DATA_OBJECT, $data );
	}

	/**
	 * Load JS + CSS from the Vite manifest.
	 */
	private static function enqueue_from_manifest() {
		$manifest_path = NERA_SAW_PLUGIN_DIR . 'dist/.vite/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			return;
		}
		$manifest = json_decode( file_get_contents( $manifest_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! isset( $manifest[ self::DEV_ENTRY ] ) ) {
			return;
		}
		$entry  = $manifest[ self::DEV_ENTRY ];
		$js_url = NERA_SAW_PLUGIN_URL . 'dist/' . $entry['file'];

		wp_enqueue_script( self::SCRIPT_HANDLE, $js_url, array(), NERA_SAW_VERSION, true );
		add_filter( 'script_loader_tag', array( __CLASS__, 'add_module_type' ), 10, 2 );

		if ( ! empty( $entry['css'] ) ) {
			foreach ( $entry['css'] as $i => $css_file ) {
				wp_enqueue_style(
					self::STYLE_HANDLE . ( $i > 0 ? "-{$i}" : '' ),
					NERA_SAW_PLUGIN_URL . 'dist/' . $css_file,
					array(),
					NERA_SAW_VERSION
				);
			}
		}
	}

	/**
	 * Add type="module" to the app script tag.
	 *
	 * @param string $tag    Tag HTML.
	 * @param string $handle Handle.
	 * @return string
	 */
	public static function add_module_type( $tag, $handle ) {
		if ( self::SCRIPT_HANDLE !== $handle ) {
			return $tag;
		}
		return str_replace( '<script ', '<script type="module" ', $tag );
	}

	/**
	 * Inject the Vite HMR client (dev only).
	 */
	public static function inject_vite_client() {
		echo '<script type="module" src="' . esc_url( self::DEV_SERVER_URL . '/@vite/client' ) . '"></script>' . "\n";
	}

	/**
	 * Inject the Vite dev entry (dev only).
	 */
	public static function inject_dev_entry() {
		echo '<script type="module" src="' . esc_url( self::DEV_SERVER_URL . '/' . self::DEV_ENTRY ) . '"></script>' . "\n";
	}

	/**
	 * Is the plugin's Vite dev server reachable?
	 *
	 * @return bool
	 */
	private static function is_dev_server_running() {
		if ( ! defined( 'NERA_SAW_DEV' ) || ! NERA_SAW_DEV ) {
			return false;
		}
		$ctx = stream_context_create( array( 'http' => array( 'timeout' => 1 ) ) );
		return false !== @file_get_contents( self::DEV_SERVER_URL . '/@vite/client', false, $ctx ); // phpcs:ignore
	}

	/**
	 * Hub: competitions the player still has runs for.
	 *
	 * @return string
	 */
	private static function render_hub() {
		$user_id    = get_current_user_id();
		$by_comp    = Nera_SAW_Run_Grants::balance_by_competition( $user_id );
		$play_base  = Nera_SAW_Play_Page::get_page_id() ? get_permalink( Nera_SAW_Play_Page::get_page_id() ) : get_permalink();
		$cards_html = '';

		foreach ( $by_comp as $competition_id => $row ) {
			$product = wc_get_product( (int) $competition_id );
			if ( ! $product || ! Nera_SAW_Competition_Config::is_competition( (int) $competition_id ) ) {
				continue;
			}

			$url   = add_query_arg( Nera_SAW_Play_Page::QV_COMPETITION, (int) $competition_id, $play_base );
			$image = self::competition_image_html( $product, 'saw-hub-card__img' );
			$runs  = (int) $row['total'];

			$cards_html .= '<a class="saw-hub-card" href="' . esc_url( $url ) . '">';
			$cards_html .= '<div class="saw-hub-card__media">' . $image . '</div>';
			$cards_html .= '<div class="saw-hub-card__body">';
			$cards_html .= '<h3 class="saw-hub-card__title">' . esc_html( $product->get_name() ) . '</h3>';
			$cards_html .= '<p class="saw-hub-card__runs">' . esc_html( self::runs_label( $runs ) ) . '</p>';
			$cards_html .= '<span class="saw-hub-card__cta">' . esc_html__( 'Open', 'nera-strikeawin' ) . '</span>';
			$cards_html .= '</div></a>';
		}

		if ( '' === $cards_html ) {
			return self::notice(
				__( 'You have no runs to play yet. Buy an entry on a competition page, then come back here.', 'nera-strikeawin' )
			);
		}

		return '<div class="saw-hub"><div class="saw-hub__grid">' . $cards_html . '</div></div>';
	}

	/**
	 * Competition overview: hero, quick guide, tier play cards.
	 *
	 * @param int    $competition_id Competition product ID.
	 * @param string $tier_key       Narrow the Start section to one tier ('' = show every tier).
	 * @return string
	 */
	private static function render_competition_overview( $competition_id, $tier_key = '' ) {
		$competition_id = (int) $competition_id;
		if ( ! Nera_SAW_Competition_Config::is_competition( $competition_id ) ) {
			return self::notice( __( 'That competition could not be found.', 'nera-strikeawin' ), 'error' );
		}

		$product = wc_get_product( $competition_id );
		if ( ! $product ) {
			return self::notice( __( 'That competition could not be found.', 'nera-strikeawin' ), 'error' );
		}

		// Load the Vue quiz app so a Start click can mount it in place.
		self::enqueue_app( $competition_id );

		$config     = Nera_SAW_Competition_Config::get( $competition_id );
		$user_id    = get_current_user_id();
		$balances   = Nera_SAW_Run_Grants::balance( $user_id, $competition_id );
		$play_base  = get_permalink();
		$shop_url   = get_permalink( $competition_id );
		$hub_url    = remove_query_arg( array( Nera_SAW_Play_Page::QV_COMPETITION, Nera_SAW_Play_Page::QV_TIER, 'saw_start' ), $play_base );
		$timer      = Nera_SAW_Constants::clamp_timer(
			isset( $config['timer_seconds'] ) ? $config['timer_seconds'] : Nera_SAW_Constants::TIMER_MAX_SECONDS
		);
		$timer_note = sprintf(
			/* translators: %d: seconds allowed per question (from competition config) */
			_n(
				'You have %d second per question. Wrong or too slow earns no tickets for that question.',
				'You have %d seconds per question. Wrong or too slow earns no tickets for that question.',
				$timer,
				'nera-strikeawin'
			),
			$timer
		);

		$description = $product->get_short_description();
		if ( '' === trim( wp_strip_all_tags( (string) $description ) ) ) {
			$description = wp_trim_words( wp_strip_all_tags( $product->get_description() ), 42, '…' );
		}

		$html  = '<div class="saw-hub saw-hub--competition">';
		$html .= '<nav class="saw-hub__nav">';
		$html .= '<a class="saw-hub__back" href="' . esc_url( $hub_url ) . '">' . esc_html__( 'All entries', 'nera-strikeawin' ) . '</a>';
		$html .= '<a class="saw-hub__back saw-hub__back--shop" href="' . esc_url( $shop_url ) . '">' . esc_html__( 'View competition', 'nera-strikeawin' ) . '</a>';
		$html .= '</nav>';

		$html .= '<div class="saw-overview">';
		$html .= '<section class="saw-competition-hero" aria-label="' . esc_attr__( 'Competition details', 'nera-strikeawin' ) . '">';
		$html .= '<div class="saw-competition-hero__media">' . self::competition_image_html( $product, 'saw-competition-hero__img' ) . '</div>';
		$html .= '<div class="saw-competition-hero__body">';
		if ( '' !== trim( wp_strip_all_tags( (string) $description ) ) ) {
			$html .= '<div class="saw-competition-hero__desc">' . wp_kses_post( wpautop( $description ) ) . '</div>';
		}
		$total_runs = Nera_SAW_Run_Grants::balance_total( $user_id, $competition_id );
		if ( $total_runs > 0 ) {
			$html .= '<p class="saw-competition-hero__balance">' . esc_html( self::runs_label( $total_runs ) ) . '</p>';
		}
		$html .= '</div></section>';

		$html .= '<aside class="saw-guide" aria-labelledby="saw-guide-title">';
		$html .= '<h3 id="saw-guide-title" class="saw-guide__title">' . esc_html__( 'How to earn tickets', 'nera-strikeawin' ) . '</h3>';
		$html .= '<ol class="saw-guide__steps">';
		$html .= '<li class="saw-guide__step"><span class="saw-guide__marker" aria-hidden="true">1</span><div><strong>' . esc_html__( 'Use a run', 'nera-strikeawin' ) . '</strong><p>' . esc_html__( 'Each run is one full quiz. Pick the tier you entered with.', 'nera-strikeawin' ) . '</p></div></li>';
		$html .= '<li class="saw-guide__step"><span class="saw-guide__marker" aria-hidden="true">2</span><div><strong>' . esc_html__( 'Answer every question in the quiz', 'nera-strikeawin' ) . '</strong><p>' . esc_html( $timer_note ) . ' ' . esc_html__( 'Pick an answer and tap Submit — your choice is not sent until you confirm.', 'nera-strikeawin' ) . '</p></div></li>';
		$html .= '<li class="saw-guide__step saw-guide__step--stay"><span class="saw-guide__marker" aria-hidden="true">3</span><div><span class="saw-guide__tag">' . esc_html__( 'Player tip', 'nera-strikeawin' ) . '</span><strong>' . esc_html__( 'Stay on this page while you play', 'nera-strikeawin' ) . '</strong><p>' . esc_html__( 'Refreshing, using the back button, or leaving mid-run will ask you to confirm. The countdown keeps running on the server — time you spend away still counts.', 'nera-strikeawin' ) . '</p></div></li>';
		$html .= '<li class="saw-guide__step"><span class="saw-guide__marker" aria-hidden="true">4</span><div><strong>' . esc_html__( 'Tickets enter the draw', 'nera-strikeawin' ) . '</strong><p>' . esc_html__( 'Correct answers mint real lottery tickets for this competition when your run finishes. One order can include several runs — each run adds its tickets to the same entry.', 'nera-strikeawin' ) . '</p></div></li>';
		$html .= '</ol></aside>';
		$html .= '</div>';

		$html .= '<section class="saw-tier-pick" aria-labelledby="saw-tier-pick-title">';
		$html .= '<h3 id="saw-tier-pick-title" class="saw-tier-pick__title">' . esc_html__( 'Start a run', 'nera-strikeawin' ) . '</h3>';
		$html .= '<ul class="saw-tier-pick__list">';

		$has_playable = false;
		foreach ( (array) $config['tiers'] as $tier ) {
			$key = (string) $tier['key'];
			if ( '' !== $tier_key && $key !== $tier_key ) {
				continue;
			}
			$runs    = isset( $balances[ $key ] ) ? (int) $balances[ $key ] : 0;
			$max     = (int) Nera_SAW_Competition_Config::max_possible_spins( $config, $key );
			$offered = Nera_SAW_Competition_Config::tier_offered( $config, $competition_id, $key );
			$classes = 'saw-tier-pick__card';
			if ( $runs > 0 ) {
				$has_playable = true;
			}

			$html .= '<li class="' . esc_attr( $classes ) . '">';
			$html .= '<div class="saw-tier-pick__head">';
			$html .= '<span class="saw-tier-pick__label">' . esc_html( (string) $tier['label'] ) . '</span>';
			$html .= '<span class="saw-tier-pick__runs">' . esc_html( self::runs_label( $runs ) ) . '</span>';
			$html .= '</div>';
			$html .= '<p class="saw-tier-pick__note">' . esc_html( sprintf( __( 'Up to %d tickets per run', 'nera-strikeawin' ), $max ) ) . '</p>';

			if ( $runs > 0 ) {
				// In-page, token-gated Start (ADR 0007). The token is a per-user,
				// per-competition+tier nonce; the launcher reads these data-attrs and
				// mounts the quiz — no URL can start a run.
				$token   = wp_create_nonce( Nera_SAW_Rest::start_token_action( $competition_id, $key ) );
				$html   .= '<button type="button" class="saw-btn saw-btn-play saw-start-run"'
					. ' data-competition="' . esc_attr( (string) $competition_id ) . '"'
					. ' data-tier="' . esc_attr( $key ) . '"'
					. ' data-nonce="' . esc_attr( $token ) . '">'
					. esc_html__( 'Play quiz', 'nera-strikeawin' ) . '</button>';
			} elseif ( ! $offered ) {
				$html .= '<span class="saw-btn saw-btn-disabled">' . esc_html__( 'Unavailable', 'nera-strikeawin' ) . '</span>';
			} else {
				$buy_url = add_query_arg(
					array(
						'add-to-cart'              => $competition_id,
						Nera_SAW_Cart_Entry::CART_KEY => $key,
					),
					function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : $shop_url
				);
				$html .= '<a class="saw-btn saw-btn-ghost" href="' . esc_url( $buy_url ) . '">' . esc_html__( 'Buy a run', 'nera-strikeawin' ) . '</a>';
			}
			$html .= '</li>';
		}

		$html .= '</ul>';

		if ( ! $has_playable ) {
			$html .= '<p class="saw-tier-pick__empty">' . sprintf(
				/* translators: %s competition page url */
				wp_kses_post( __( 'No runs left for this competition. <a href="%s">Buy an entry</a> to play.', 'nera-strikeawin' ) ),
				esc_url( $shop_url )
			) . '</p>';
		}

		$html .= '</section></div>';

		// Hidden quiz mount + launcher. A Start click mounts the Vue quiz in place,
		// passing the per-tier Start token; no URL can trigger a run (ADR 0007).
		$html .= '<div id="saw-quiz" class="saw-quiz-mount" hidden data-show-feedback="' . esc_attr( Nera_SAW_Constants::quiz_feedback_enabled() ? '1' : '0' ) . '"></div>';
		$html .= self::start_launcher_script();

		return $html;
	}

	/**
	 * Inline launcher: on a Start-button click, mount the quiz in place with the
	 * button's competition/tier/token. Vanilla JS, bound once. The token travels in
	 * the mount call (then the REST body), never the URL.
	 *
	 * Public for the same reason as enqueue_app(): the standalone section builds
	 * its own Start markup and reuses this rather than re-implementing the
	 * token-gated mount dance (ADR 0007 — no URL starts a run).
	 *
	 * @return string
	 */
	public static function start_launcher_script() {
		ob_start();
		?>
		<script>
		( function () {
			if ( window.__sawStartBound ) { return; }
			window.__sawStartBound = true;
			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest ? e.target.closest( '.saw-start-run' ) : null;
				if ( ! btn ) { return; }
				e.preventDefault();
				var mount = document.getElementById( 'saw-quiz' );
				if ( ! mount ) { return; }
				mount.setAttribute( 'data-competition', btn.getAttribute( 'data-competition' ) || '' );
				mount.setAttribute( 'data-tier', btn.getAttribute( 'data-tier' ) || '' );
				var overview = document.querySelector( '.saw-hub--competition' );
				if ( overview ) { overview.style.display = 'none'; }
				mount.hidden = false;
				var go = function () {
					if ( window.NeraSAWLaunch ) {
						window.NeraSAWLaunch( {
							competitionId: parseInt( btn.getAttribute( 'data-competition' ), 10 ) || 0,
							tier: btn.getAttribute( 'data-tier' ) || '',
							token: btn.getAttribute( 'data-nonce' ) || '',
							mountEl: mount
						} );
					} else {
						// Bundle not ready yet; retry briefly.
						window.setTimeout( go, 120 );
					}
				};
				go();
			} );
		} )();
		</script>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Hero copy for a competition overview / quiz start.
	 *
	 * @param WC_Product|false|null $product        Product.
	 * @param int                   $competition_id Competition ID.
	 * @return array{title: string, intro: string}
	 */
	private static function competition_hero( $product, $competition_id ) {
		$title = $product ? $product->get_name() : get_the_title( (int) $competition_id );
		$intro = __( 'Use your runs from ticket purchases — answer the quiz before time runs out to earn lottery tickets.', 'nera-strikeawin' );

		if ( $product ) {
			$short = $product->get_short_description();
			if ( '' !== trim( wp_strip_all_tags( (string) $short ) ) ) {
				$intro = wp_trim_words( wp_strip_all_tags( $short ), 28, '…' );
			}
		}

		return array(
			'title' => (string) $title,
			'intro' => (string) $intro,
		);
	}

	/**
	 * Spin To Win–style page shell: gradient background, hero, white card.
	 *
	 * @param string               $inner Inner HTML (placed inside .saw-root-wrap).
	 * @param array<string,string> $hero  Optional title + intro overrides.
	 * @return string
	 */
	private static function wrap_page_shell( $inner, array $hero = array() ) {
		$badge = isset( $hero['badge'] ) ? (string) $hero['badge'] : __( 'Strike A Win', 'nera-strikeawin' );
		$title = isset( $hero['title'] ) ? (string) $hero['title'] : __( 'Play Strike A Win', 'nera-strikeawin' );
		$intro = isset( $hero['intro'] ) ? (string) $hero['intro'] : __( 'Use your runs from ticket purchases — answer the quiz to earn lottery tickets.', 'nera-strikeawin' );

		$html  = '<div class="saw-page">';
		$html .= '<div class="saw-page-bg" aria-hidden="true"></div>';
		$html .= '<div class="saw-page-blob-right" aria-hidden="true"></div>';
		$html .= '<div class="saw-page-blob-left" aria-hidden="true"></div>';
		$html .= '<div class="saw-hero-container">';
		$html .= '<header class="saw-hero-header">';
		$html .= '<p class="saw-hero-badge"><span class="saw-hero-badge-dot" aria-hidden="true"></span>' . esc_html( $badge ) . '</p>';
		$html .= '<h1 class="saw-hero-heading">' . esc_html( $title ) . '</h1>';
		$html .= '<div class="saw-hero-divider" aria-hidden="true"></div>';
		$html .= '<p class="saw-hero-intro">' . esc_html( $intro ) . '</p>';
		$html .= '</header>';
		$html .= '<div class="saw-root-wrap">';
		$html .= '<div class="saw-root-blob-right" aria-hidden="true"></div>';
		$html .= '<div class="saw-root-blob-left" aria-hidden="true"></div>';
		$html .= $inner;
		$html .= '</div></div></div>';

		return $html;
	}

	/**
	 * Featured image markup for a competition product.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $class   Image class.
	 * @return string
	 */
	private static function competition_image_html( $product, $class ) {
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			return wp_get_attachment_image( $image_id, 'medium_large', false, array( 'class' => $class ) );
		}
		$placeholder = function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'medium_large' ) : '';
		if ( '' === $placeholder ) {
			return '';
		}
		return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $placeholder ) . '" alt="" loading="lazy" decoding="async" />';
	}

	/**
	 * Human label for a run count.
	 *
	 * Public: the standalone play template's Start panel shows the same count.
	 *
	 * @param int $runs Run count.
	 * @return string
	 */
	public static function runs_label( $runs ) {
		$runs = (int) $runs;
		if ( 1 === $runs ) {
			return __( '1 run to play', 'nera-strikeawin' );
		}
		return sprintf(
			/* translators: %d run count */
			__( '%d runs to play', 'nera-strikeawin' ),
			$runs
		);
	}

	/**
	 * Simple notice wrapper.
	 *
	 * @param string $message Message.
	 * @param string $type    Optional type class suffix.
	 * @return string
	 */
	private static function notice( $message, $type = '' ) {
		$class = 'saw-hub saw-notice';
		if ( '' !== $type ) {
			$class .= ' saw-' . sanitize_html_class( $type );
		}
		return '<div class="' . esc_attr( $class ) . '">' . wp_kses_post( $message ) . '</div>';
	}
}