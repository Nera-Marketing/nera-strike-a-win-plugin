<?php
/**
 * What languages this site actually serves, and which one is being served now.
 *
 * Step L1 of [docs/LANGUAGE-PLAN.md](../docs/LANGUAGE-PLAN.md). Everything else in
 * the multilingual workstream reads this and nothing else asks Polylang directly,
 * so there is one answer to "is this site multilingual?" rather than a different
 * one at every call site.
 *
 * DEGRADE TO SILENCE
 * ------------------
 * A missing multilingual plugin is not a fault. Strike A Win is monolingual by
 * default and ships to sites that will never install Polylang, so every method here
 * answers plainly when there is no engine: no languages, not multilingual, English.
 * Nothing here assumes Polylang's functions exist, and nothing raises a notice when
 * they do not — see §3 of the plan.
 *
 * WHY POLYLANG IS ASKED RATHER THAN MIRRORED
 * ------------------------------------------
 * Polylang owns the list of languages; this plugin owns how far they reach. Keeping
 * a second copy of the language list would be a second thing to get out of step.
 * See [ADR 0028](../docs/adr/0028-polylang-owns-the-languages-strike-a-win-owns-the-reach.md).
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Language
 */
class Nera_SAW_Language {

	/**
	 * What a site with no multilingual plugin is serving.
	 */
	const FALLBACK = 'en';

	/**
	 * The query argument the header toggle uses to switch language.
	 */
	const SWITCH_ARG = 'saw_lang';

	/**
	 * Hooks.
	 */
	public static function init() {
		/*
		 * The question bank is managed by Polylang rather than by a taxonomy of its
		 * own — answer 1 in §8 of the plan. A Russian question is not a translation
		 * of an English one, so the two are never paired; Polylang is happy to hold a
		 * post in one language with no counterpart, and the admin language column and
		 * filter come along for free.
		 *
		 * This supersedes the bespoke `saw_language` taxonomy of ADR 0024.
		 */
		add_filter( 'pll_get_post_types', array( __CLASS__, 'translate_questions' ), 10, 2 );

		if ( class_exists( 'Nera_SAW_Mode' ) && Nera_SAW_Mode::is_standalone() ) {
			add_filter( 'user_trailingslashit', array( __CLASS__, 'carry_current_language' ), 10, 1 );
			add_filter( 'gettext', array( __CLASS__, 'translate_age_gate_strings' ), 10, 3 );
			add_filter( 'gettext', array( __CLASS__, 'translate_saw_strings' ), 10, 3 );
			add_filter( 'gettext', array( __CLASS__, 'translate_loginreg_strings' ), 10, 3 );
			add_filter( 'ngettext', array( __CLASS__, 'translate_saw_plurals' ), 10, 5 );

			// See self::current()'s docblock (step 2) for why this needs a cookie
			// rather than the WooCommerce session this used at first.
			add_action( 'template_redirect', array( __CLASS__, 'remember_in_cookie' ) );

			/*
			 * WooCommerce's own checkout form has no hidden saw_lang field either, so
			 * its AJAX submission carries no language — same problem as the two GET
			 * forms hidden_field() already covers. Nera_SAW_Standalone_Basket::
			 * stamp_context() stamps this same form with saw_basket, on the same
			 * hook, for the same reason.
			 */
			add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'hidden_field' ) );
		}
	}

	/**
	 * Translate the sibling age-shield plugin's own front-end strings (the age
	 * verification dialog, and the date-of-birth/phone fields it shares with the
	 * registration form) to whatever language `self::current()` says this request
	 * is being served in.
	 *
	 * That plugin calls WordPress's own `__()` at the site's real WordPress
	 * locale — confirmed `en_US` here — which `self::current()` never touches (see
	 * the class docblock: this plugin's reach stops at its own seeded content).
	 * Rather than teach a sibling plugin about this one, or duplicate its locale
	 * handling, a `gettext` filter scoped strictly to its text domain intercepts
	 * exactly the handful of strings its dialog and forms use and swaps them for
	 * a hand‑maintained Russian set — no .mo file, no locale switch, and no
	 * effect on any other plugin's or WordPress core's own translations.
	 *
	 * @param string $translated Text WordPress would otherwise return.
	 * @param string $original   Original (English) string.
	 * @param string $domain     Text domain the call was made with.
	 * @return string
	 */
	public static function translate_age_gate_strings( $translated, $original, $domain ) {
		if ( 'nera-dcms-age-gate' !== $domain || is_admin() || 'ru' !== self::current() ) {
			return $translated;
		}

		static $ru = null;
		if ( null === $ru ) {
			$ru = array(
				'Age Verification' => 'Проверка возраста',
				'This site and its prize draws are only available to users aged %d and over. Please confirm your date of birth to continue.'
					=> 'Этот сайт и его призовые розыгрыши доступны только пользователям старше %d лет. Пожалуйста, подтвердите дату рождения, чтобы продолжить.',
				'Date of birth' => 'Дата рождения',
				'Phone number'  => 'Номер телефона',
				'Required'      => 'Обязательно',
				'Please enter your phone number.'      => 'Пожалуйста, введите номер телефона.',
				'Please enter a valid phone number.'   => 'Пожалуйста, введите корректный номер телефона.',
				'Please enter a valid date of birth.'  => 'Пожалуйста, укажите корректную дату рождения.',
				'Please select a valid day, month and year.' => 'Пожалуйста, выберите корректные день, месяц и год.',
				'Continue'   => 'Продолжить',
				'Close'      => 'Закрыть',
				'Verifying…' => 'Проверка…',
				'Something went wrong. Please try again.' => 'Что-то пошло не так. Пожалуйста, попробуйте снова.',
				'Sorry, you must be %d or over to take part in prize draws on this site.'
					=> 'Извините, вам должно быть не менее %d лет, чтобы участвовать в призовых розыгрышах на этом сайте.',
				'You must be %d or over to take part in prize draws on this site.'
					=> 'Вам должно быть не менее %d лет, чтобы участвовать в призовых розыгрышах на этом сайте.',
				'You must be %d or over to register and take part.'
					=> 'Вам должно быть не менее %d лет, чтобы зарегистрироваться и принять участие.',
				'You must be %d or over to create an account on this site.'
					=> 'Вам должно быть не менее %d лет, чтобы создать аккаунт на этом сайте.',
				'e.g. 07700 900123' => 'например, +7 900 123-45-67',
				'Day'   => 'День',
				'Month' => 'Месяц',
				'Year'  => 'Год',
				'January' => 'Январь', 'February' => 'Февраль', 'March' => 'Март', 'April' => 'Апрель',
				'May' => 'Май', 'June' => 'Июнь', 'July' => 'Июль', 'August' => 'Август',
				'September' => 'Сентябрь', 'October' => 'Октябрь', 'November' => 'Ноябрь', 'December' => 'Декабрь',
			);
		}

		return isset( $ru[ $original ] ) ? $ru[ $original ] : $translated;
	}

	/**
	 * Translate this plugin's OWN fixed UI vocabulary — the catalogue card, the
	 * competition detail page, before‑you‑pay, and the quiz run app's own string
	 * table (`Nera_SAW_Frontend`'s `wp_localize_script( …, 'strings' => […] )`,
	 * read by `src/App.vue`'s `t()`/`fmt()` helpers) — to Russian.
	 *
	 * All of it calls `__()`/`_n()` with this plugin's own `nera-strikeawin`
	 * domain directly, the same way the age‑gate dialog's strings did before that
	 * one got the same treatment: never wired through the shell()/seeder system
	 * the rest of a page like competition.php uses for its admin‑editable copy,
	 * so `saw_lang=ru` had no effect on any of it. Deliberately does NOT cover
	 * the difficulty‑band names (Easy/Moderate/Hard/…) or the tier names
	 * (Standard/Premium) — those are admin‑editable labels (`class-constants.php`,
	 * a competition's own tier config), not this plugin's fixed chrome, and
	 * translating them would need a per‑value table, not a fixed string map.
	 *
	 * `is_admin()` excludes this plugin's OWN admin screens, several of which
	 * reuse plain English words from this same list (`Day`, `Month`, `Closes`,
	 * `Sold out`…) for their own admin-facing UI, which must keep following the
	 * admin's own WordPress locale rather than a front-end player's language
	 * toggle.
	 *
	 * @param string $translated Text WordPress would otherwise return.
	 * @param string $original   Original (English) string.
	 * @param string $domain     Text domain the call was made with.
	 * @return string
	 */
	public static function translate_saw_strings( $translated, $original, $domain ) {
		if ( 'nera-strikeawin' !== $domain || is_admin() || 'ru' !== self::current() ) {
			return $translated;
		}

		$ru = self::saw_ru_strings();

		return isset( $ru[ $original ] ) ? $ru[ $original ] : $translated;
	}

	/**
	 * Translate the WooCommerce-domain strings on the standalone Login/Register
	 * screen (`templates/woocommerce/myaccount/form-login.php` — the restored
	 * stock template plus this site's own three register fields).
	 *
	 * A `'woocommerce' === $domain` check alone would hijack every WooCommerce
	 * string on the whole install the moment a request's language happens to be
	 * Russian — cart, checkout, the theme's own account page, anywhere the core
	 * templates run — none of which this plugin owns or has any business
	 * relabelling. `Nera_SAW_Router::is_standalone_screen()` is the same guard
	 * `class-router.php` uses to decide whether this section's own assets load
	 * at all, so this filter only ever fires on the section's own screens,
	 * exactly where that template is the one actually rendering.
	 *
	 * @param string $translated Text WordPress would otherwise return.
	 * @param string $original   Original (English) string.
	 * @param string $domain     Text domain the call was made with.
	 * @return string
	 */
	public static function translate_loginreg_strings( $translated, $original, $domain ) {
		if ( 'woocommerce' !== $domain || is_admin() || 'ru' !== self::current() ) {
			return $translated;
		}
		if ( ! class_exists( 'Nera_SAW_Router' ) || ! Nera_SAW_Router::is_standalone_screen() ) {
			return $translated;
		}

		static $ru = null;
		if ( null === $ru ) {
			$ru = array(
				'Login'                            => 'Вход',
				'Register'                         => 'Регистрация',
				'Required'                         => 'Обязательно',
				'Username or email address'       => 'Имя пользователя или email',
				'Username'                          => 'Имя пользователя',
				'Password'                          => 'Пароль',
				'Remember me'                       => 'Запомнить меня',
				'Log in'                            => 'Войти',
				'Lost your password?'              => 'Забыли пароль?',
				'Full Name'                         => 'Полное имя',
				'Email address'                     => 'Адрес электронной почты',
				'A link to set a new password will be sent to your email address.'
					=> 'Ссылка для установки нового пароля будет отправлена на ваш email.',
				'I agree to the %s'                => 'Принимаю %s',
				'Terms &amp; Conditions'           => 'Условия использования',
				'I am over the age of 18'         => 'Мне есть 18 лет',
			);
		}

		return isset( $ru[ $original ] ) ? $ru[ $original ] : $translated;
	}

	/**
	 * The plural half of the same string set — `_n()` never calls `gettext`
	 * at all, WordPress routes it through its own `ngettext` filter instead,
	 * so every plural entry in `self::saw_ru_strings()` (every `%s ticket(s)`,
	 * `%s question(s)`, `%s run(s)` pair among them) silently stayed English
	 * until this was added alongside `translate_saw_strings()`. `$translated`
	 * arrives already resolved to whichever of `$single`/`$plural` core's own
	 * (untranslated) pluralisation chose, so it can be looked up in the same
	 * map exactly like `translate_saw_strings()` looks up `$original` — both
	 * the singular and plural English forms are already separate keys in it.
	 *
	 * @param string $translated Text WordPress would otherwise return.
	 * @param string $single     Singular source string.
	 * @param string $plural     Plural source string.
	 * @param int    $number     The count being formatted for.
	 * @param string $domain     Text domain the call was made with.
	 * @return string
	 */
	public static function translate_saw_plurals( $translated, $single, $plural, $number, $domain ) {
		unset( $single, $plural, $number );
		if ( 'nera-strikeawin' !== $domain || is_admin() || 'ru' !== self::current() ) {
			return $translated;
		}

		$ru = self::saw_ru_strings();

		return isset( $ru[ $translated ] ) ? $ru[ $translated ] : $translated;
	}

	/**
	 * The Russian string map shared by `translate_saw_strings()` (gettext) and
	 * `translate_saw_plurals()` (ngettext) — see the docblock above
	 * `translate_saw_strings()` for what this does and does not cover.
	 *
	 * @return array<string, string>
	 */
	private static function saw_ru_strings() {
		static $ru = null;
		if ( null === $ru ) {
			$ru = array(
				// Catalogue card, competition detail, before-you-pay — shared facts.
				'Sold out'                              => 'Продано',
				'%s entries left'                        => 'Осталось: %s',
				'From %s'                                => 'От %s',
				'Only %s left'                            => 'Осталось только %s',
				'Closes %s · Drawn automatically'        => 'Закрывается %s · Розыгрыш автоматически',
				'Drawn automatically'                    => 'Розыгрыш автоматически',
				'Closes %s'                              => 'Закрывается %s',
				'Closes'                                 => 'Закрывается',
				'Draw'                                   => 'Розыгрыш',
				'Automatic, once closed'                 => 'Автоматически, после закрытия',
				'Cash alternative'                       => 'Денежный эквивалент',
				'Entries left'                           => 'Осталось билетов',
				'%1$s of %2$s'                           => '%1$s из %2$s',
				'Full terms &amp; conditions'            => 'Полные правила',
				'Random draw, independently witnessed'   => 'Случайный розыгрыш под независимым наблюдением',
				'%1$s question · %2$s stages'            => '%1$s вопрос · %2$s этап',
				'%1$s questions · %2$s stages'           => '%1$s вопросов · %2$s этапов',
				'%s question · mixed difficulty'         => '%s вопрос · смешанная сложность',
				'%s questions · mixed difficulty'        => '%s вопросов · смешанная сложность',
				// Competition detail page.
				'Not found'                              => 'Не найдено',
				'Every entry has gone. Nothing more can be bought for this competition.'
					=> 'Все билеты раскуплены. Больше нельзя купить участие в этом конкурсе.',
				'This competition has closed.'           => 'Этот конкурс закрыт.',
				'See what is open'                       => 'Посмотреть открытые конкурсы',
				'%1$s &middot; tickets &times;%2$d'      => '%1$s &middot; билетов &times;%2$d',
				'Perfect run: %s ticket'                 => 'Идеальный забег: %s билет',
				'Perfect run: %s tickets'                => 'Идеальный забег: %s билетов',
				'%1$s questions, %2$d seconds each.'     => '%1$s вопросов, по %2$d секунд на каждый.',
				'%1$s questions, %2$s stages'            => '%1$s вопросов, %2$s этапов',
				'%s questions, in random order'          => '%s вопросов, в случайном порядке',
				'%s, in random order'                    => '%s, в случайном порядке',
				'Stage %1$d · %2$s · %3$s'               => 'Этап %1$d · %2$s · %3$s',
				'%s ticket each'                          => '%s билет за каждый',
				'%s tickets each'                        => '%s билетов за каждый',
				'%s ticket'                               => '%s билет',
				'%s tickets'                              => '%s билетов',
				'questions'                               => 'вопросов',
				'%ds'                                      => '%dс',
				'per question'                            => 'на вопрос',
				'difficulty bands'                        => 'уровней сложности',
				'%s question'                             => '%s вопрос',
				'%s questions'                            => '%s вопросов',
				'Perfect run, %s'                         => 'Идеальный забег, %s',
				// Before-you-pay.
				'%1$s tier · tickets ×%2$d · %3$s'       => '%1$s · билетов ×%2$d · %3$s',
				'%s run'                                   => '%s забег',
				'%s runs'                                  => '%s забегов',
				'Questions'                               => 'Вопросы',
				'%1$s, in %2$s stages'                   => '%1$s, в %2$s этапах',
				'Timer'                                    => 'Таймер',
				'%d seconds per question'                 => '%d секунд на вопрос',
				'Ticket ceiling'                           => 'Максимум билетов',
				// Play screen (before a run starts).
				'Please <a href="%s">log in</a> to use your runs and earn lottery tickets.'
					=> 'Пожалуйста, <a href="%s">войдите</a>, чтобы использовать свои забеги и получать билеты лотереи.',
				'No runs to play'                         => 'Нет доступных забегов',
				'1 run to play'                            => '1 забег доступен',
				'%d runs to play'                         => 'Доступно забегов: %d',
				'Play quiz'                                => 'Играть',
				'Buy a run'                                => 'Купить забег',
				'No runs left for this competition. <a href="%s">Buy an entry</a> to play.'
					=> 'Забегов для этого конкурса не осталось. <a href="%s">Купите участие</a>, чтобы играть.',
				// The quiz run app (src/App.vue), via Nera_SAW_Frontend's 'strings'.
				'Loading your entry…'                     => 'Загружаем ваш забег…',
				'View competition'                        => 'Смотреть конкурс',
				'Play again'                               => 'Играть снова',
				'Choose an answer, then confirm to lock it in.' => 'Выберите ответ, затем подтвердите, чтобы зафиксировать его.',
				'Submit answer'                           => 'Отправить ответ',
				'Checking…'                                => 'Проверка…',
				'Next question'                           => 'Следующий вопрос',
				'See my results'                          => 'Посмотреть результаты',
				'Correct. %d tickets earned.'             => 'Правильно. Получено билетов: %d.',
				'Wrong. The correct answer was: %s'       => 'Неверно. Правильный ответ: %s',
				"Time up, no answer counted. The correct answer was: %s" => 'Время вышло, ответ не засчитан. Правильный ответ: %s',
				'Wrong — no tickets.'                     => 'Неверно — билеты не начислены.',
				'Time up — no answer counted.'            => 'Время вышло — ответ не засчитан.',
				'Leave this page? The timer keeps running and unanswered questions score zero.'
					=> 'Покинуть страницу? Таймер продолжает идти, а вопросы без ответа дают ноль баллов.',
				'Leave the quiz?'                         => 'Покинуть викторину?',
				'The countdown keeps running on the server while you are away. Any question you have not submitted scores zero.'
					=> 'Отсчёт времени на сервере продолжается, пока вас нет. Любой неотправленный вопрос даёт ноль баллов.',
				'This answer is already saved. If you leave now, every question you have not reached scores zero.'
					=> 'Этот ответ уже сохранён. Если вы уйдёте сейчас, все оставшиеся вопросы получат ноль баллов.',
				'Keep playing'                            => 'Продолжить игру',
				'Leave anyway'                            => 'Всё равно уйти',
				'Adding your tickets to the draw…'        => 'Добавляем ваши билеты в розыгрыш…',
				'Your ticket numbers'                     => 'Номера ваших билетов',
				'%d runs left for this competition'       => 'Осталось забегов для этого конкурса: %d',
				'%d runs left on %s'                      => 'Забегов осталось: %d на уровне %s',
				'We could not add your tickets right now.' => 'Не удалось добавить билеты прямо сейчас.',
				'Try again'                                 => 'Повторить',
				'Something went wrong. Please contact support and we will investigate and restore your run.'
					=> 'Что-то пошло не так. Пожалуйста, свяжитесь с поддержкой — мы разберёмся и восстановим ваш забег.',
				'Run reference'                           => 'Номер забега',
				'Quiz language'                           => 'Язык викторины',
				'Questions and answers will appear in this language' => 'Вопросы и ответы будут показаны на этом языке',
				'Question %1$d of %2$d'                  => 'Вопрос %1$d из %2$d',
				'Worth %d tickets'                        => 'Даёт %d билетов',
				'Worth %d ticket'                         => 'Даёт %d билет',
				'Tickets'                                  => 'Билеты',
				'Stage %1$d of %2$d · %3$s'              => 'Этап %1$d из %2$d · %3$s',
				'+%1$d banked. %2$d tickets total.'      => '+%1$d начислено. Всего билетов: %2$d.',
				"Time's up. %d tickets total."            => 'Время вышло. Всего билетов: %d.',
				'Not this time. %d tickets total.'        => 'На этот раз мимо. Всего билетов: %d.',
				'Stage %1$d of %2$d'                      => 'Этап %1$d из %2$d',
				// Per-stage headlines (Nera_SAW_Frontend's stageBreakHeadline_1..5,
				// read by src/App.vue's t('stageBreakHeadline_' + stageNo, …)) — same
				// fixed-vocabulary treatment as the rest of this map.
				'Warming up'                                => 'Разминка',
				'Stepping up'                                => 'Прибавляем обороты',
				'Getting serious'                           => 'Переходим к серьёзному',
				'Into the hard part'                        => 'Самая сложная часть',
				'Final stretch'                              => 'Финишная прямая',
				'Run complete'                             => 'Забег завершён',
				"You're in the draw"                      => 'Вы участвуете в розыгрыше',
				'%1$d tickets banked from %2$d correct answers.' => 'Начислено билетов: %1$d за %2$d правильных ответов.',
				'No tickets this run'                     => 'В этом забеге билетов нет',
				'None of the %d answers landed in time.'  => 'Ни один из %d ответов не уложился во время.',
				'%1$d of %2$d correct, but not enough to bank a ticket.' => 'Правильных ответов: %1$d из %2$d — этого недостаточно для билета.',
				'Score'                                     => 'Результат',
				'%1$d of %2$d correct'                    => '%1$d из %2$d правильно',
				'Tickets earned'                          => 'Получено билетов',
				'Your entry numbers'                      => 'Номера ваших билетов',
				'+%d more'                                  => 'ещё +%d',
				"Numbers are allocated at random from this draw's pool." => 'Номера выбираются случайным образом из пула этого розыгрыша.',
				"Random draw, independently witnessed. You'll be notified either way."
					=> 'Случайный розыгрыш под независимым наблюдением. Мы сообщим вам о результате в любом случае.',
				'Draw: %s'                                 => 'Розыгрыш: %s',
				'No tickets were earned, so there is no entry in this draw, and no refund is due.'
					=> 'Билеты не были начислены, поэтому участия в розыгрыше нет, и возврат средств не производится.',
				'The draw closes %s.'                     => 'Розыгрыш закрывается %s.',
				'Play another run'                        => 'Сыграть ещё один забег',
				'Back to competitions'                    => 'Назад к конкурсам',
				'Enter another competition'                => 'Участвовать в другом конкурсе',
				// Result-screen overlays (templates/standalone/result-screen/*.php) —
				// admin-editable ACF defaults (Nera_SAW_Standalone_Result_Screen::copy()),
				// same treatment as everything else: only the fallback English text
				// used when the field itself is empty, same as this whole map.
				"You're in the draw!"                     => 'Вы участвуете в розыгрыше!',
				'Your entry is confirmed — fingers crossed!' => 'Ваше участие подтверждено — держим кулачки!',
				'Good luck!'                                => 'Удачи!',
				'Got it!'                                   => 'Понятно!',
				'Thanks for entering!'                      => 'Спасибо за участие!',
				'Not this time — but every entry brings you closer. There are always more competitions to enter!'
					=> 'На этот раз не повезло — но каждая попытка приближает вас к победе. Впереди ещё много конкурсов!',
				'Browse more competitions'                  => 'Смотреть другие конкурсы',
				// Entry gate (age/language pop-up).
				'Before you start'                          => 'Прежде чем начать',
				'Choose your language'                      => 'Выберите язык',
				'Verified: you have confirmed you are over 18.' => 'Подтверждено: вы указали, что вам больше 18 лет.',
				'Continue'                                   => 'Продолжить',
				'I confirm that I am 18 or over.'           => 'Я подтверждаю, что мне есть 18 лет.',
				'This is a self-declaration. Nothing is recorded against your account.'
					=> 'Это самостоятельное подтверждение. Оно нигде не фиксируется в вашем аккаунте.',
				'Enter'                                      => 'Войти',
				// Language toggle.
				'Language'                                    => 'Язык',
				// Walkthrough.
				'Walkthrough, step %1$d of %2$d'             => 'Инструкция, шаг %1$d из %2$d',
				'Close the walkthrough'                      => 'Закрыть инструкцию',
				'%1$d of %2$d'                                => '%1$d из %2$d',
				'Example ladder'                              => 'Пример лестницы уровней',
				'Easy'                                         => 'Легко',
				'Medium'                                       => 'Средне',
				'Hard'                                         => 'Сложно',
				'Expert'                                       => 'Эксперт',
				'%s each'                                      => 'по %s',
				'Try it again'                                 => 'Попробовать ещё раз',
				'Walkthrough steps'                            => 'Шаги инструкции',
				'Previous step'                                => 'Предыдущий шаг',
				'Step %1$d of %2$d'                           => 'Шаг %1$d из %2$d',
				'Next step'                                    => 'Следующий шаг',
				'Example timer, %d seconds'                   => 'Пример таймера, %d секунд',
				// Nera_SAW_Competition_Config::reward_label() — the per-level reward
				// line shown on the stage-break screen and the per-question badge.
				'%d ticket per correct answer'                => '%d билет за правильный ответ',
				'%d tickets per correct answer'               => '%d билетов за правильный ответ',
			);
		}

		return $ru;
	}

	/**
	 * Put the question bank under Polylang.
	 *
	 * @param array $types    Post types Polylang manages.
	 * @param bool  $is_settings Whether Polylang is drawing its settings screen.
	 * @return array
	 */
	public static function translate_questions( $types, $is_settings = false ) {
		unset( $is_settings );

		$types[ Nera_SAW_Question_CPT::POST_TYPE ] = Nera_SAW_Question_CPT::POST_TYPE;

		return $types;
	}

	/**
	 * Is there a multilingual engine at all?
	 *
	 * @return bool
	 */
	public static function engine_present() {
		return function_exists( 'pll_languages_list' ) && function_exists( 'pll_default_language' );
	}

	/**
	 * Every language code this site declares, in Polylang's order.
	 *
	 * Empty when there is no engine — deliberately not `array( 'en' )`. A site with
	 * no plugin has not declared a language; it simply has one, and a caller asking
	 * for the list is asking a question that only makes sense when languages are
	 * something the site manages.
	 *
	 * @return array<int, string>
	 */
	public static function codes() {
		if ( ! self::engine_present() ) {
			return array();
		}

		$codes = pll_languages_list( array( 'fields' => 'slug' ) );

		return is_array( $codes ) ? array_values( array_filter( array_map( 'strval', $codes ) ) ) : array();
	}

	/**
	 * Is there a choice to offer?
	 *
	 * The switcher, the entry gate's language buttons and `hreflang` all hang off
	 * this. One language is not a choice, so nothing is rendered.
	 *
	 * @return bool
	 */
	public static function is_multilingual() {
		return count( self::codes() ) > 1;
	}

	/**
	 * Does this site serve that language?
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public static function has( $code ) {
		$code = sanitize_key( (string) $code );

		return '' !== $code && in_array( $code, self::codes(), true );
	}

	/**
	 * The site's default language.
	 *
	 * @return string
	 */
	public static function default_code() {
		if ( ! self::engine_present() ) {
			return self::FALLBACK;
		}

		$default = (string) pll_default_language( 'slug' );

		return '' !== $default ? $default : self::FALLBACK;
	}

	/**
	 * The language being served this request.
	 *
	 * In order, first hit wins — §5 of the plan, plus a fourth answer this class
	 * added since:
	 *
	 *   1. An explicit switch carried by this request (the header toggle)
	 *   2. Remembered in a plain cookie by `self::remember_in_cookie()`
	 *   3. Polylang's own resolution: URL, then cookie, then user preference
	 *   4. The site default
	 *
	 * Deliberately not `sessionStorage`. The prototype can use it because it
	 * translates the DOM after load; this section is rendered by PHP, so the
	 * language has to be known before any markup is written and JavaScript arrives
	 * too late to be asked. Switching is a page load, not a repaint — which is also
	 * what makes a translated page linkable and the toggle work with scripting off.
	 *
	 * Step 2 exists because two things this section does never carry `saw_lang` in
	 * their own request at all: WooCommerce re-renders the whole checkout order
	 * review over AJAX within a second of the page loading (and again on every
	 * change), and the quiz app's own `fetch()` calls (`src/api.js`) hit this
	 * plugin's REST routes with nothing but a run ID and an answer — no query
	 * string, no referer read. A `WC()->session` value was tried here first, since
	 * `Nera_SAW_Standalone_Basket::stamp_context()` uses exactly that session for
	 * a similar reason — but `WC_Session_Handler` only ever loads a signed-in
	 * player's stored session on requests that run WooCommerce's own `wp_loaded`
	 * bootstrap, which a bare custom REST route never triggers, so
	 * `WC()->session->get()` came back empty on every quiz-run request even
	 * though the exact same value was sitting in that session's own DB row
	 * (confirmed directly against `wp_woocommerce_sessions`). A plain cookie has
	 * no such bootstrap dependency: the browser attaches it to every same-origin
	 * request, `fetch()` included, with nothing extra to opt into.
	 *
	 * @return string
	 */
	public static function current() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a display preference, not acting on one.
		$asked = isset( $_REQUEST[ self::SWITCH_ARG ] ) ? sanitize_key( wp_unslash( $_REQUEST[ self::SWITCH_ARG ] ) ) : '';
		if ( '' !== $asked && self::has( $asked ) ) {
			return $asked;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended -- reading a display preference, not acting on one.
		$remembered = isset( $_COOKIE[ self::SWITCH_ARG ] ) ? sanitize_key( $_COOKIE[ self::SWITCH_ARG ] ) : '';
		if ( '' !== $remembered && self::has( $remembered ) ) {
			return $remembered;
		}

		if ( self::engine_present() && function_exists( 'pll_current_language' ) ) {
			$current = (string) pll_current_language( 'slug' );
			if ( '' !== $current ) {
				return $current;
			}
		}

		return self::default_code();
	}

	/**
	 * Remember the language this request is being served in on a plain cookie,
	 * so a later request that carries no `saw_lang` of its own — the checkout
	 * order-review AJAX refresh, every quiz-run REST call — still resolves it
	 * correctly via `self::current()`'s step 2. See that method's docblock for
	 * why a cookie rather than the `WC()->session` this used at first.
	 *
	 * Hooked on `template_redirect`: early enough that headers have not gone out
	 * yet (a cookie set any later is silently dropped), late enough that the main
	 * query — and so `self::current()` — is already resolved.
	 */
	public static function remember_in_cookie() {
		if ( headers_sent() ) {
			return;
		}

		$current = self::current();
		if ( '' === $current ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- read for a same-request equality check, never output or stored.
		$stored = isset( $_COOKIE[ self::SWITCH_ARG ] ) ? (string) $_COOKIE[ self::SWITCH_ARG ] : '';

		if ( $current === self::default_code() ) {
			/*
			 * Nothing to carry for the default language, but a stale cookie from an
			 * earlier switch away from it must not be left behind: hidden_field()
			 * omits saw_lang for the default too, so a GET form hop back to it often
			 * lands on a bare URL with no saw_lang of its own — and without this,
			 * self::current() would keep reading the old cookie on every such
			 * request afterwards and silently reverting the page back to it. Found
			 * exactly this way: browsing in English right after a Russian session
			 * still produced a Russian order-received redirect on the next
			 * saw_lang-less GET form hop.
			 */
			if ( '' !== $stored ) {
				setcookie( self::SWITCH_ARG, '', time() - YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			}
			return;
		}

		if ( $stored === $current ) {
			return;
		}

		setcookie(
			self::SWITCH_ARG,
			$current,
			time() + DAY_IN_SECONDS,
			COOKIEPATH ? COOKIEPATH : '/',
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
	}

	/**
	 * A hidden `<input>` carrying the language being served, for a GET form
	 * whose submission would otherwise drop it.
	 *
	 * A browser submitting a GET form discards whatever query string is
	 * already in the form's `action` and replaces it with the form's own
	 * fields — `self::carry_current_language()`'s `user_trailingslashit`
	 * hook only ever helps a plain `<a href>`, never this. The entry gate's
	 * own form already works around this one field at a time via
	 * `Nera_SAW_Language_Switcher::gate_return_fields()`; this is the same
	 * fix as a one-line call for every other GET form in the section
	 * (`before-you-pay`'s checkout form, the competition page's buy form) and
	 * for the actual WooCommerce checkout form, which has no form of its own
	 * to add a field to — this is hooked onto it instead.
	 *
	 * Echoes nothing for the default language — there is nothing to carry.
	 */
	public static function hidden_field() {
		$current = self::current();
		if ( '' === $current || $current === self::default_code() ) {
			return;
		}

		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( self::SWITCH_ARG ), esc_attr( $current ) );
	}

	/**
	 * Carry the language being served onto every link inside the section.
	 *
	 * The header toggle and the entry gate both build their own link explicitly —
	 * `add_query_arg( self::SWITCH_ARG, $code, $here )` — but ordinary navigation
	 * (the logo, the back link, a competition card, a CTA button) is built the
	 * plain way, with `get_permalink()` or `Nera_SAW_Router::url()`, and knows
	 * nothing about a language that was never in its own URL to begin with.
	 * Without this, choosing Russian on one screen and then following any link
	 * that is not the toggle itself lands back in English — the section's own
	 * `saw_lang` was never in that link, and these pages are not Polylang's to
	 * carry a language across on their own (only the question bank is under its
	 * management; see `translate_questions()` above).
	 *
	 * Hooked on `user_trailingslashit` rather than `home_url`: every permalink
	 * builder — `get_page_link()`, `get_post_permalink()`, and
	 * `Nera_SAW_Router::url()`'s own fallback — passes through it exactly once,
	 * and always last, after its own trailing slash is already settled. Hooking
	 * `home_url` instead was tried first and shipped a bug: `_get_page_link()`
	 * calls `home_url( $path )` and only trailing-slashes the result afterwards,
	 * so a query string added inside the `home_url` filter was still there when
	 * `user_trailingslashit()` ran next — turning
	 * `…/how-it-works/?saw_lang=ru` into `…/how-it-works?saw_lang=ru/`, a link
	 * WordPress happily builds and no less broken for it. Running after that
	 * step instead of before it is what avoids the whole class of bug rather
	 * than one instance of it.
	 *
	 * One hook point, rather than a `saw_lang` clause written into every
	 * template that prints an `href`. Guarded to the section's own path so a
	 * link to anywhere else this fires for — the main site, the admin, a feed —
	 * is never touched.
	 *
	 * @param string $url The URL (or, for a route built path-first, the bare
	 *                    path) with its trailing slash already applied.
	 * @return string
	 */
	public static function carry_current_language( $url ) {
		if ( is_admin() ) {
			return $url;
		}

		$lang = self::current();
		if ( $lang === self::default_code() ) {
			return $url;
		}

		if ( ! class_exists( 'Nera_SAW_Router' ) ) {
			return $url;
		}
		$prefix = trim( (string) Nera_SAW_Router::prefix(), '/' );
		if ( '' === $prefix ) {
			return $url;
		}
		$url_path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( $url_path !== $prefix && 0 !== strpos( $url_path, $prefix . '/' ) ) {
			return $url;
		}

		// Already explicit -- the toggle's own link, or this filter having already
		// run once earlier in the same build-up of a URL -- so leave it alone
		// rather than layering a second copy of the argument on top.
		if ( false !== strpos( $url, self::SWITCH_ARG . '=' ) ) {
			return $url;
		}

		return add_query_arg( self::SWITCH_ARG, $lang, $url );
	}

	/**
	 * A language's name in its own language, for a control the reader is choosing
	 * from — someone looking for Russian is looking for "Русский", not "Russian".
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function name( $code ) {
		$code = sanitize_key( (string) $code );

		if ( self::engine_present() && function_exists( 'pll_the_languages' ) ) {
			foreach ( (array) pll_the_languages( array( 'raw' => 1 ) ) as $lang ) {
				if ( isset( $lang['slug'] ) && $lang['slug'] === $code ) {
					return isset( $lang['name'] ) ? (string) $lang['name'] : $code;
				}
			}
		}

		return $code;
	}

	/**
	 * Record which language a post is in.
	 *
	 * Used by the seeder. Without an engine there is nowhere to record it, and the
	 * caller is expected to have checked — see `Nera_SAW_Seeder::questions_language`,
	 * which refuses rather than creating posts whose language nothing can tell.
	 *
	 * @param int    $post_id Post to mark.
	 * @param string $code    Language code.
	 * @return bool Whether the language was recorded.
	 */
	public static function set_for_post( $post_id, $code ) {
		$post_id = (int) $post_id;
		$code    = sanitize_key( (string) $code );

		if ( $post_id < 1 || '' === $code || ! function_exists( 'pll_set_post_language' ) || ! self::has( $code ) ) {
			return false;
		}

		pll_set_post_language( $post_id, $code );

		return true;
	}

	/**
	 * The language a post is in, or an empty string when nothing knows.
	 *
	 * @param int $post_id Post to ask about.
	 * @return string
	 */
	public static function for_post( $post_id ) {
		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return '';
		}

		$code = pll_get_post_language( (int) $post_id, 'slug' );

		return is_string( $code ) ? $code : '';
	}
}
