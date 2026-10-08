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
	 * Is this request the WooCommerce My Account page (any endpoint — login/
	 * register when signed out, orders/addresses/payment-methods/wallet/
	 * spending-limit/account-status when signed in)?
	 *
	 * `Nera_SAW_Router::is_standalone_screen()` answers a narrower question —
	 * a bespoke SAW route, or a page carrying this plugin's own page-template
	 * meta — and WooCommerce's native My Account page is neither: it is a
	 * plain page with the `[woocommerce_my_account]` shortcode, with no SAW
	 * route and no SAW template meta of its own, confirmed by checking this
	 * install's own `myaccount` page directly. Every `gettext`/`ngettext`
	 * filter below that targets this page (the login/register screen, the
	 * sibling wallet/spending-limit/self-exclusion panels, the orders/
	 * addresses/payment-methods chrome) was gated on
	 * `is_standalone_screen()` alone and so never actually fired there —
	 * found by curling the account-status and edit-account endpoints with an
	 * authenticated cookie and `saw_lang=ru` and seeing English throughout,
	 * including strings PR3/PR4 already believed shipped (`'My account'`,
	 * `'My Wallet'`). `is_account_page()` is WooCommerce's own canonical
	 * check for exactly this page, on any of its endpoints, signed in or not.
	 *
	 * @return bool
	 */
	protected static function is_account_screen() {
		if ( class_exists( 'Nera_SAW_Router' ) && Nera_SAW_Router::is_standalone_screen() ) {
			return true;
		}

		return function_exists( 'is_account_page' ) && is_account_page();
	}

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
			add_filter( 'gettext', array( __CLASS__, 'translate_responsible_play_strings' ), 10, 3 );
			add_filter( 'gettext', array( __CLASS__, 'translate_wallet_strings' ), 10, 3 );
			add_filter( 'woo_wallet_locate_template', array( __CLASS__, 'override_wallet_template' ), 99, 4 );
			add_filter( 'gettext', array( __CLASS__, 'translate_spending_limit_strings' ), 10, 3 );
			add_filter( 'gettext', array( __CLASS__, 'translate_self_exclusion_strings' ), 10, 3 );
			add_filter( 'gettext_with_context', array( __CLASS__, 'translate_order_status_labels' ), 10, 4 );
			add_filter( 'woocommerce_get_privacy_policy_text', array( __CLASS__, 'translate_privacy_policy_text' ), 10, 2 );
			add_filter( 'ngettext', array( __CLASS__, 'translate_saw_plurals' ), 10, 5 );
			add_filter( 'ngettext', array( __CLASS__, 'translate_woocommerce_plurals' ), 10, 5 );
			add_filter( 'ngettext', array( __CLASS__, 'translate_spending_limit_plurals' ), 10, 5 );

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
	 * Translate the sibling responsible-play plugin's footer strip
	 * ("Need support with your play?" / "Help & support") on the section's
	 * own screens. Client finding #23: kept (not suppressed) on standalone by
	 * deliberate choice — see class-standalone-chrome.php — but nothing
	 * translated its domain before now. Also covers this domain's "Need
	 * support?" My Account nav item (`class-account.php`'s own menu-item
	 * filter) — found untranslated alongside the rest of that nav row's
	 * labels during the broader self-translate pass. `self::is_account_screen()`
	 * widens the original `is_standalone_screen()`-only guard just enough to
	 * reach that nav row too (see its own docblock for why), without
	 * loosening anything: still this plugin's own domain only, never
	 * hijacked on an unrelated main-site page.
	 *
	 * @param string $translated Text WordPress would otherwise return.
	 * @param string $original   Original (English) string.
	 * @param string $domain     Text domain the call was made with.
	 * @return string
	 */
	public static function translate_responsible_play_strings( $translated, $original, $domain ) {
		if ( 'nera-responsible-play-plugin' !== $domain || is_admin() || 'ru' !== self::current() ) {
			return $translated;
		}
		if ( ! self::is_account_screen() ) {
			return $translated;
		}

		static $ru = null;
		if ( null === $ru ) {
			$ru = array(
				'Need support with your play?' => 'Нужна помощь с игрой?',
				'Help & support'                => 'Помощь и поддержка',
				'Need support?'                 => 'Нужна помощь?',
			);
		}

		return isset( $ru[ $original ] ) ? $ru[ $original ] : $translated;
	}

	/**
	 * Translate the sibling woo-wallet plugin's My Wallet panel on the
	 * my-account page, on the section's own screens. Reported by the user
	 * after this session's first pass at the account page (the wallet panel
	 * stayed entirely English despite its own accordion row label already
	 * translating via templates/woocommerce/myaccount/my-account.php's own
	 * hand array) — that array only ever touched the row label, never this
	 * domain's own strings in the panel body.
	 *
	 * Several of the client's supplied Russian values are reused here under
	 * a DIFFERENT English key than the one they were supplied against —
	 * woo-wallet's own copy differs in case/wording from the client's
	 * (`'Total Balance'` here vs the supplied `'Total balance'`, `'Wallet
	 * topup'` vs `'Wallet top-up'`, `'Balance History'` vs `'Balance
	 * history'`) — same Russian word either way, so the translation still
	 * applies; the gettext filter has to match woo-wallet's own exact
	 * source string to ever fire at all. Two strings this plugin's own
	 * code never calls — `'Manage your wallet and transactions
	 * seamlessly.'` (the panel subtitle) and `'For order payment #'` (the
	 * balance-history row prefix) — had no supplied Russian in either
	 * client file either, so both get this plugin's own translation
	 * rather than staying English, per the broader self-translate pass.
	 *
	 * @param string $translated Text WordPress would otherwise return.
	 * @param string $original   Original (English) string.
	 * @param string $domain     Text domain the call was made with.
	 * @return string
	 */
	public static function translate_wallet_strings( $translated, $original, $domain ) {
		if ( 'woo-wallet' !== $domain || is_admin() || 'ru' !== self::current() ) {
			return $translated;
		}
		if ( ! self::is_account_screen() ) {
			return $translated;
		}

		static $ru = null;
		if ( null === $ru ) {
			$ru = array(
				'My Wallet'      => 'Мой кошелёк',
				'Total Balance'  => 'Баланс',
				'Wallet topup'   => 'Пополнить кошелёк',
				'Transactions'   => 'Операции',
				'Balance History' => 'История баланса',
				'Description'    => 'Описание',
				'Amount'         => 'Сумма',
				'Manage your wallet and transactions seamlessly.' => 'Управляйте своим кошельком и операциями в одном месте.',
				'For order payment #' => 'Оплата заказа №',
			);
		}

		return isset( $ru[ $original ] ) ? $ru[ $original ] : $translated;
	}

	/**
	 * Serve this plugin's own copy of woo-wallet's `wc-endpoint-wallet.php`
	 * (`templates/woo-wallet/wc-endpoint-wallet.php`) for a Russian request,
	 * via that plugin's own `woo_wallet_locate_template` filter — the same
	 * technique `Nera_SAW_Standalone_Chrome::unoverride_template()` already
	 * uses for WooCommerce's own `woocommerce_locate_template` filter.
	 *
	 * The one thing that copy changes is why this exists at all: a wallet
	 * transaction's description (`$transaction->details`) is written to
	 * woo-wallet's own database table once, when the transaction happens
	 * (`class-woo-wallet-wallet.php`'s `debit()`/`credit()`), and this
	 * template prints that stored value with no filter of its own in
	 * between — `self::translate_wallet_strings()` above can translate the
	 * live `__( 'For order payment #', 'woo-wallet' )` call at the moment a
	 * NEW transaction is created, but an OLDER row, already saved in
	 * English before that filter existed (or saved while a player's request
	 * was English), has nothing left to translate by the time it is read
	 * back — found exactly this way, from a screenshot of an existing
	 * balance-history row still reading "For order payment #31438" after
	 * everything else on that screen had already switched to Russian.
	 *
	 * @param string $template      Path woo-wallet resolved.
	 * @param string $template_name Relative template name.
	 * @param string $template_path Template subdirectory woo-wallet was given.
	 * @param string $default_path  Fallback path woo-wallet was given.
	 * @return string
	 */
	public static function override_wallet_template( $template, $template_name, $template_path, $default_path ) {
		unset( $template_path, $default_path );
		if ( 'wc-endpoint-wallet.php' !== $template_name || is_admin() || 'ru' !== self::current() ) {
			return $template;
		}

		$ours = NERA_SAW_PLUGIN_DIR . 'templates/woo-wallet/' . $template_name;

		return file_exists( $ours ) ? $ours : $template;
	}

	/**
	 * Translate an already-stored wallet transaction description on the way
	 * out — see `self::override_wallet_template()`'s docblock for why this
	 * cannot be done any earlier. Only the one known prefix this plugin's
	 * own sibling-plugin integration ever produces is recognised; anything
	 * else (a manual adjustment, a different add-on's own wallet credit)
	 * passes through unchanged, same caution as every map in this file.
	 *
	 * Public rather than this file's usual `protected`/private pattern:
	 * called directly from `templates/woo-wallet/wc-endpoint-wallet.php`,
	 * outside this class.
	 *
	 * @param string $details Stored transaction description.
	 * @return string
	 */
	public static function translate_wallet_transaction_details( $details ) {
		if ( 'ru' !== self::current() ) {
			return $details;
		}

		$prefix = 'For order payment #';
		if ( 0 === strpos( (string) $details, $prefix ) ) {
			return 'Оплата заказа №' . substr( (string) $details, strlen( $prefix ) );
		}

		return $details;
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
		if ( ! self::is_account_screen() ) {
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
				'privacy policy'                   => 'политика конфиденциальности',
				'terms and conditions'             => 'условия использования',
				/*
				 * Client findings #27/#28: the my-account page's own nav row
				 * labels already translate (templates/woocommerce/myaccount/
				 * my-account.php's own hand-translated array), but every panel
				 * body fired via woocommerce_account_{endpoint}_endpoint, and
				 * the checkout/order-received page, are stock WooCommerce core
				 * templates this plugin cannot edit — gettext is the only hook
				 * available for them, same as the rest of this array. Added
				 * from languages/ru/02-russian-strings-prototype.json and
				 * 02b-russian-strings-dev-build.json (confirmed exact matches
				 * only — see this session's cross-check report for what is
				 * NOT yet covered, e.g. "Actions", billing address fields,
				 * shipping, coupons).
				 */
				'My account'                        => 'Мой аккаунт',
				'Order details'                     => 'Детали заказа',
				'Orders'                             => 'Заказы',
				'Payment method'                     => 'Способ оплаты',
				'First name'                         => 'Имя',
				'Last name'                          => 'Фамилия',
				'Place order'                        => 'Оплатить',
				'Billing details'                    => 'Платёжные данные',
				'Your order'                         => 'Ваш заказ',
				'Date'                                => 'Дата',
				'Total'                               => 'Итого',
				'Email'                               => 'Эл. почта',
				'Order number'                       => 'Номер заказа',
				'Subtotal'                            => 'Промежуточный итог',
				// Addresses panel body (templates/woocommerce/myaccount/my-
				// address.php, stock core) — "Billing address" is the one
				// row-title string with a supplied Russian equivalent; the
				// rest of this block ("Shipping address", the "Edit %s"/
				// "Add %s" wrapper and the empty-state copy) has none in
				// either client file, so it gets this plugin's own
				// translation instead, per the broader self-translate pass.
				'Billing address'                    => 'Платёжный адрес',
				// Edit-account form (templates/myaccount/form-edit-account.php,
				// stock core) — found still English in the broader
				// self-translate pass (screenshot: "Password change" etc.).
				'Display name'                        => 'Отображаемое имя',
				'This will be how your name will be displayed in the account section and in reviews'
					=> 'Так ваше имя будет отображаться в разделе аккаунта и в отзывах',
				'Password change'                     => 'Смена пароля',
				'Current password (leave blank to leave unchanged)'
					=> 'Текущий пароль (оставьте пустым, если не хотите менять)',
				'New password (leave blank to leave unchanged)'
					=> 'Новый пароль (оставьте пустым, если не хотите менять)',
				'Confirm new password'                => 'Подтвердите новый пароль',
				'Save changes'                        => 'Сохранить изменения',
				'Shipping address'                   => 'Адрес доставки',
				'The following addresses will be used on the checkout page by default.'
					=> 'Эти адреса будут использоваться по умолчанию на странице оформления заказа.',
				'Edit %s'                             => 'Изменить %s',
				'Add %s'                              => 'Добавить %s',
				'You have not set up this type of address yet.'
					=> 'Вы ещё не указали адрес этого типа.',
				// Payment methods panel body (templates/woocommerce/myaccount/
				// payment-methods.php, form-add-payment-method.php) — same
				// situation: no supplied Russian in either client file.
				'No saved methods found.'             => 'Сохранённые способы оплаты не найдены.',
				'Add payment method'                  => 'Добавить способ оплаты',
				// Orders table (templates/woocommerce/myaccount/orders.php) —
				// column headings. "Date" and "Total" already have entries
				// above; the rest did not.
				'Order'                                => 'Заказ',
				'Status'                               => 'Статус',
				'Actions'                              => 'Действия',
				'View'                                 => 'Просмотр',
				// My Account nav row (wc_get_account_menu_items(), domain
				// 'woocommerce') — found untranslated during the broader
				// self-translate pass. "Log out" is rendered as a theme-
				// filtered "Logout" on this site's actual nav markup, which
				// never passes through gettext again after that filter runs,
				// so this entry is kept for correctness but will not show on
				// that one row — a theme-level gap this plugin cannot reach.
				'Dashboard'                            => 'Панель управления',
				'Payment methods'                      => 'Способы оплаты',
				'Account details'                       => 'Данные аккаунта',
				'Log out'                               => 'Выйти',
			);
		}

		return isset( $ru[ $original ] ) ? $ru[ $original ] : $translated;
	}

	/**
	 * The registration screen's privacy-policy paragraph — the one string on
	 * this page `translate_loginreg_strings()` above cannot reach. WooCommerce
	 * builds it with `get_option( 'woocommerce_registration_privacy_policy_text',
	 * $default )`, and this site (like most) has that option saved with a real
	 * value already, so `get_option()` returns the stored row and the `__()`
	 * call building the fallback default never even runs — there is no
	 * `gettext` call here to filter. `woocommerce_get_privacy_policy_text` is
	 * the one filter WooCommerce still passes the resolved text through
	 * regardless of which branch produced it (see `wc_get_privacy_policy_text()`
	 * in wc-template-functions.php), which is why this hooks that one instead.
	 * The `[privacy_policy]` placeholder is left intact — `wc_replace_policy_
	 * page_link_placeholders()` swaps it for the actual link afterwards, and
	 * that link's own text ("privacy policy") is a plain `__()` call already
	 * covered by the map above.
	 *
	 * @param string $text Text WordPress would otherwise return.
	 * @param string $type Which policy text this is ('checkout' or 'registration').
	 * @return string
	 */
	public static function translate_privacy_policy_text( $text, $type ) {
		if ( 'registration' !== $type || is_admin() || 'ru' !== self::current() ) {
			return $text;
		}
		if ( ! self::is_account_screen() ) {
			return $text;
		}

		return 'Ваши персональные данные будут использованы для улучшения вашего опыта на этом сайте, управления доступом к вашему аккаунту, а также для других целей, описанных в нашей [privacy_policy].';
	}

	/**
	 * WooCommerce's order-status labels (`wc-order-functions.php`,
	 * `class-wc-post-types.php`) — the Orders table's own "Status" column and
	 * the order-details page both print one of these. Every one of them is a
	 * `_x( $label, 'Order status', 'woocommerce' )` call, which WordPress
	 * routes through `gettext_with_context` rather than plain `gettext` — a
	 * second, separate filter hook, which is why none of this ever matched
	 * `translate_loginreg_strings()` above despite sharing its domain. No
	 * supplied Russian in either client file, so this is this plugin's own
	 * translation, per the broader self-translate pass.
	 *
	 * @param string $translated Text WordPress would otherwise return.
	 * @param string $text       Original (English) string.
	 * @param string $context    Disambiguating context WordPress was given.
	 * @param string $domain     Text domain the call was made with.
	 * @return string
	 */
	public static function translate_order_status_labels( $translated, $text, $context, $domain ) {
		if ( 'woocommerce' !== $domain || 'Order status' !== $context || is_admin() || 'ru' !== self::current() ) {
			return $translated;
		}
		if ( ! self::is_account_screen() ) {
			return $translated;
		}

		static $ru = null;
		if ( null === $ru ) {
			$ru = array(
				'Pending payment' => 'Ожидает оплаты',
				'Processing'      => 'В обработке',
				'On hold'         => 'Отложен',
				'Completed'       => 'Выполнен',
				'Cancelled'       => 'Отменён',
				'Refunded'        => 'Возвращён',
				'Failed'          => 'Не выполнен',
				'Draft'           => 'Черновик',
			);
		}

		return isset( $ru[ $text ] ) ? $ru[ $text ] : $translated;
	}

	/**
	 * The Orders table's item-count cell (`templates/myaccount/orders.php`):
	 * `sprintf( _n( '%1$s for %2$s item', '%1$s for %2$s items', $count,
	 * 'woocommerce' ), $total, $count )`. English only ever chooses between
	 * its own two forms; Russian needs three (один товар / два товара / пять
	 * товаров), the same mismatch `Nera_SAW_I18n::n()` exists to fix for this
	 * plugin's own strings — but that class is deliberately scoped to this
	 * plugin's own `nera-strikeawin` domain (see its docblock), so a stock
	 * WooCommerce template needs its own `ngettext` handling rather than
	 * reusing it. `self::ru_plural_form()` is the same one/few/many rule,
	 * kept as a small private duplicate here rather than reaching into that
	 * class's own (deliberately protected) implementation.
	 *
	 * Also covers `wc_get_account_menu_items()`'s own "Addresses" nav label —
	 * `_n( 'Address', 'Addresses', $count, 'woocommerce' )`, the one nav-row
	 * label built with a real plural rather than a fixed string, so it could
	 * never live in `translate_loginreg_strings()`'s plain map either. Found
	 * alongside the item-count plural, same domain, same filter, so handled
	 * here rather than opening a third `ngettext` method for one more string.
	 *
	 * Only the two known `$single` values are ever translated; anything else
	 * (a core update adding a new plural string to this domain) falls
	 * through to `$translated` unchanged, same as every other filter in this
	 * file.
	 *
	 * @param string $translated Text WordPress would otherwise return.
	 * @param string $single     Singular source string.
	 * @param string $plural     Plural source string.
	 * @param int    $number     The count being formatted for.
	 * @param string $domain     Text domain the call was made with.
	 * @return string
	 */
	public static function translate_woocommerce_plurals( $translated, $single, $plural, $number, $domain ) {
		unset( $plural );
		if ( 'woocommerce' !== $domain || is_admin() || 'ru' !== self::current() ) {
			return $translated;
		}
		if ( ! self::is_account_screen() ) {
			return $translated;
		}

		if ( '%1$s for %2$s item' === $single ) {
			return self::ru_plural_form( (int) $number, '%1$s за %2$s товар|%1$s за %2$s товара|%1$s за %2$s товаров' );
		}

		if ( 'Address' === $single ) {
			return 'Addresses' === $translated ? 'Адреса' : 'Адрес';
		}

		return $translated;
	}

	/**
	 * Translate the sibling spending-limit plugin's Account Details card —
	 * heading, fields, status messages and the small AJAX/checkout dialogs —
	 * on the section's own screens. None of it has supplied Russian in
	 * either client file (confirmed: not one of these English strings
	 * matches a key in `languages/ru/02-russian-strings-prototype.json` or
	 * `02b-russian-strings-dev-build.json`), so this is this plugin's own
	 * translation, per the broader self-translate pass. The one genuine
	 * count-driven plural this domain has ("N period(s) configured") is
	 * handled separately, by `self::translate_spending_limit_plurals()`
	 * below — `_n()` never reaches a plain `gettext` filter.
	 *
	 * @param string $translated Text WordPress would otherwise return.
	 * @param string $original   Original (English) string.
	 * @param string $domain     Text domain the call was made with.
	 * @return string
	 */
	public static function translate_spending_limit_strings( $translated, $original, $domain ) {
		if ( 'nera-spending-limit' !== $domain || is_admin() || 'ru' !== self::current() ) {
			return $translated;
		}
		if ( ! self::is_account_screen() ) {
			return $translated;
		}

		static $ru = null;
		if ( null === $ru ) {
			$ru = array(
				// Account Details card.
				'Spending limit'   => 'Лимит расходов',
				'Set a voluntary cap on how much you spend.' => 'Установите добровольное ограничение суммы расходов.',
				'Amount limit'     => 'Сумма лимита',
				'Enter the maximum amount you want to spend in the selected period.'
					=> 'Укажите максимальную сумму, которую вы готовы потратить за выбранный период.',
				'Limit type'       => 'Тип лимита',
				'Custom period'    => 'Произвольный период',
				'Save'             => 'Сохранить',
				'Your spending limit is turned off.' => 'Ваш лимит расходов отключён.',
				'You have not set a spending limit yet.' => 'Вы ещё не установили лимит расходов.',
				'Select one or more periods on the calendar to activate your limit.'
					=> 'Выберите один или несколько периодов в календаре, чтобы активировать лимит.',
				'Your limit is %1$s (%2$s).' => 'Ваш лимит: %1$s (%2$s).',
				'You must be logged in.' => 'Вы должны войти в аккаунт.',
				'Security check failed. Please refresh and try again.'
					=> 'Проверка безопасности не пройдена. Обновите страницу и попробуйте снова.',
				'This feature is not available.' => 'Эта функция недоступна.',
				'Your spending limit has been turned off.' => 'Ваш лимит расходов отключён.',
				'Your spending limit has been removed. Select periods to set a new one.'
					=> 'Ваш лимит расходов удалён. Выберите периоды, чтобы установить новый.',
				'Your spending limit has been saved.' => 'Ваш лимит расходов сохранён.',
				// Limit-type / custom-period dropdown options (class-settings.php) —
				// reach this filter via wp_localize_script's own __() calls; see
				// class-assets.php's docblock note on why that is still reachable.
				'Daily'   => 'Ежедневно',
				'Weekly'  => 'Еженедельно',
				'Monthly' => 'Ежемесячно',
				'Yearly'  => 'Ежегодно',
				'Custom'  => 'Произвольный',
				'Day'     => 'День',
				'Week'    => 'Неделя',
				'Month'   => 'Месяц',
				'Year'    => 'Год',
				'Custom (%s)' => 'Произвольный (%s)',
				// Remove-period dialog, save button states (class-assets.php).
				'Remove this period?' => 'Удалить этот период?',
				'This period will no longer have a spending limit applied.'
					=> 'На этот период больше не будет действовать лимит расходов.',
				'Cancel'   => 'Отмена',
				'Remove'   => 'Удалить',
				'Saving…'  => 'Сохранение…',
				'Something went wrong. Please try again.' => 'Что-то пошло не так. Пожалуйста, попробуйте снова.',
				'Click the calendar to select the periods to limit.'
					=> 'Нажмите на календарь, чтобы выбрать периоды для лимита.',
				// Checkout over-limit dialog (class-assets.php, class-cart.php).
				'You are over your spending limit' => 'Вы превысили лимит расходов',
				'Continue anyway' => 'Всё равно продолжить',
				// Checkout spending-limit card (class-checkout.php).
				'Spending limit (%s)' => 'Лимит расходов (%s)',
				'Your limit'          => 'Ваш лимит',
				'Spent this period'   => 'Потрачено за период',
				'Remaining'           => 'Осталось',
				'This order'          => 'Этот заказ',
				'Wallet balance'      => 'Баланс кошелька',
				'This order exceeds your spending limit and your wallet balance does not cover it. Top up your wallet or reduce the order to continue.'
					=> 'Этот заказ превышает ваш лимит расходов, а баланс кошелька его не покрывает. Пополните кошелёк или уменьшите заказ, чтобы продолжить.',
				'This order will take you over the spending limit you set. You can continue, but you will be asked to confirm.'
					=> 'Этот заказ превысит установленный вами лимит расходов. Вы можете продолжить, но потребуется подтверждение.',
				'This order exceeds your spending limit and your wallet balance does not cover it. Please top up your wallet or reduce your order.'
					=> 'Этот заказ превышает ваш лимит расходов, а баланс кошелька его не покрывает. Пожалуйста, пополните кошелёк или уменьшите заказ.',
				'This order exceeds the spending limit you set. Please confirm you want to continue.'
					=> 'Этот заказ превышает установленный вами лимит расходов. Подтвердите, что хотите продолжить.',
				// Validation errors (class-user-limit.php).
				'Please choose a valid limit type.' => 'Пожалуйста, выберите корректный тип лимита.',
				'Please set an amount of at least 1.' => 'Пожалуйста, укажите сумму не менее 1.',
				'Please choose a valid custom period type.' => 'Пожалуйста, выберите корректный тип произвольного периода.',
			);
		}

		return isset( $ru[ $original ] ) ? $ru[ $original ] : $translated;
	}

	/**
	 * The one genuine count-driven plural the spending-limit domain has:
	 * `class-account.php`'s "Your limit is {amount} per selected {period} —
	 * N period(s) configured." sentence. Same reasoning as
	 * `self::translate_woocommerce_plurals()` above — Russian's three forms
	 * packed into one lookup, picked by `self::ru_plural_form()` rather than
	 * the plain two-way map `self::translate_spending_limit_strings()` uses
	 * for everything else in this domain.
	 *
	 * @param string $translated Text WordPress would otherwise return.
	 * @param string $single     Singular source string.
	 * @param string $plural     Plural source string.
	 * @param int    $number     The count being formatted for.
	 * @param string $domain     Text domain the call was made with.
	 * @return string
	 */
	public static function translate_spending_limit_plurals( $translated, $single, $plural, $number, $domain ) {
		unset( $plural );
		if ( 'nera-spending-limit' !== $domain || is_admin() || 'ru' !== self::current() ) {
			return $translated;
		}
		if ( ! self::is_account_screen() ) {
			return $translated;
		}
		if ( 'Your limit is %1$s per selected %2$s — %3$d period configured.' !== $single ) {
			return $translated;
		}

		return self::ru_plural_form(
			(int) $number,
			'Лимит: %1$s за период «%2$s» — настроен %3$d период.'
				. '|Лимит: %1$s за период «%2$s» — настроено %3$d периода.'
				. '|Лимит: %1$s за период «%2$s» — настроено %3$d периодов.'
		);
	}

	/**
	 * Translate the sibling self-exclusion plugin's "Manage my account" panel
	 * (the pause/suspend/close forms and their status messages) and the
	 * login/entry blocking messages it shows an excluded user. None of it
	 * has supplied Russian in either client file, so this is this plugin's
	 * own translation, per the broader self-translate pass.
	 *
	 * `'CLOSE'` itself — the placeholder and the literal word the close form
	 * asks a player to type — is deliberately NOT in this map.
	 * `class-account.php::handle_request()` and `assets/js/account-status.js`
	 * both check the submitted text against the literal English word `CLOSE`
	 * (case-sensitive, not translatable on their side), so translating the
	 * placeholder would show the player a word that then fails that check.
	 * The surrounding sentence (`'Type CLOSE to confirm:'` etc.) still
	 * translates — with `CLOSE` kept in Latin letters inside the Russian
	 * text — the same convention the client's own files use for `[BRAND]`.
	 *
	 * One known gap this cannot close: the login-block and entry-block
	 * messages (`class-guard.php`) splice a reactivation date in via
	 * `date_i18n()` and plain `sprintf()`, AFTER this filter has already
	 * returned the (translatable) template — the date value itself is
	 * built by `class-guard.php`'s own code, never passed through a
	 * `gettext` call this plugin can intercept, and keeps WordPress's
	 * actual site locale (English) regardless of `saw_lang`.
	 *
	 * @param string $translated Text WordPress would otherwise return.
	 * @param string $original   Original (English) string.
	 * @param string $domain     Text domain the call was made with.
	 * @return string
	 */
	public static function translate_self_exclusion_strings( $translated, $original, $domain ) {
		if ( 'nera-self-exclusion' !== $domain || is_admin() || 'ru' !== self::current() ) {
			return $translated;
		}
		if ( ! self::is_account_screen() ) {
			return $translated;
		}

		static $ru = null;
		if ( null === $ru ) {
			$ru = array(
				// Status labels (class-state.php) — shared by the status panel's
				// own heading and every message below that embeds one.
				'Paused'    => 'Приостановлен',
				'Suspended' => 'Заблокирован',
				'Closed'    => 'Закрыт',
				'Active'    => 'Активен',
				// My Account nav row (class-account.php's own menu-item filter).
				'Manage my account' => 'Управление аккаунтом',
				// MODE A — already-excluded status panel.
				'Account %s' => 'Аккаунт: %s',
				'Your account has been permanently closed. This cannot be reversed.'
					=> 'Ваш аккаунт был закрыт навсегда. Это действие нельзя отменить.',
				'If you believe this was an error, or you need assistance, please contact our support team.'
					=> 'Если вы считаете, что это ошибка, или вам нужна помощь, свяжитесь с нашей службой поддержки.',
				'Your account is %1$s until %2$s.' => 'Ваш аккаунт %1$s до %2$s.',
				'This break cannot be ended early. Your account will reactivate automatically once the period has passed.'
					=> 'Этот перерыв нельзя закончить досрочно. Аккаунт автоматически восстановится по окончании срока.',
				// MODE B — the three forms.
				'Responsible gambling tools' => 'Инструменты ответственной игры',
				'If you want to take a break from competitions, you can pause or suspend your account for a set period, or close it permanently. These tools are here to help you stay in control.'
					=> 'Если вы хотите сделать перерыв в участии в конкурсах, вы можете приостановить или заблокировать аккаунт на определённый срок либо закрыть его навсегда. Эти инструменты помогут вам сохранять контроль.',
				'Take a short break' => 'Сделать короткий перерыв',
				'Pause your account for up to 6 months. It will reactivate automatically.'
					=> 'Приостановите аккаунт на срок до 6 месяцев. Он восстановится автоматически.',
				'How long would you like to pause?' => 'На сколько вы хотите поставить аккаунт на паузу?',
				'1 day'    => '1 день',
				'1 week'   => '1 неделя',
				'1 month'  => '1 месяц',
				'3 months' => '3 месяца',
				'6 months' => '6 месяцев',
				'Or enter a custom number of days (1–183):' => 'Или укажите своё количество дней (1–183):',
				'e.g. 14' => 'например, 14',
				'Pause my account' => 'Поставить аккаунт на паузу',
				'Longer suspension' => 'Длительная блокировка',
				'Suspend your account for 6 months to 5 years. It will reactivate automatically.'
					=> 'Заблокируйте аккаунт на срок от 6 месяцев до 5 лет. Он восстановится автоматически.',
				'How long would you like to suspend?' => 'На какой срок вы хотите заблокировать аккаунт?',
				'1 year'  => '1 год',
				'2 years' => '2 года',
				'5 years' => '5 лет',
				'Suspend my account' => 'Заблокировать аккаунт',
				'Permanently close account' => 'Закрыть аккаунт навсегда',
				'This is irreversible. Once closed, your account cannot be reopened.'
					=> 'Это необратимо. После закрытия аккаунт нельзя будет восстановить.',
				'Warning: this action is permanent and cannot be undone.'
					=> 'Внимание: это действие необратимо, и его нельзя отменить.',
				'Closing your account will immediately log you out and permanently prevent you from logging back in. You will lose access to your competition history and any active entries. Please contact support before proceeding if you have any questions.'
					=> 'Закрытие аккаунта немедленно завершит вашу сессию и навсегда лишит вас возможности войти снова. Вы потеряете доступ к истории конкурсов и всем активным заявкам. Если у вас есть вопросы, свяжитесь со службой поддержки, прежде чем продолжить.',
				'I understand this will permanently close my account and cannot be reversed.'
					=> 'Я понимаю, что это навсегда закроет мой аккаунт и это действие нельзя отменить.',
				'Type CLOSE to confirm:' => 'Введите CLOSE для подтверждения:',
				// JS confirm() dialog (class-assets.php's 'confirmClose').
				'This will permanently close your account and cannot be reversed. Continue?'
					=> 'Это навсегда закроет ваш аккаунт, и действие нельзя будет отменить. Продолжить?',
				'Permanently close my account' => 'Закрыть мой аккаунт навсегда',
				// Server-side validation / flow messages (class-account.php).
				'Security check failed. Please refresh the page and try again.'
					=> 'Проверка безопасности не пройдена. Обновите страницу и попробуйте снова.',
				'Invalid request. Please try again.' => 'Некорректный запрос. Пожалуйста, попробуйте снова.',
				'Please choose a pause duration between 1 and 183 days.'
					=> 'Пожалуйста, выберите длительность паузы от 1 до 183 дней.',
				'Please choose a suspension duration between 6 and 60 months.'
					=> 'Пожалуйста, выберите длительность блокировки от 6 до 60 месяцев.',
				'Please tick the confirmation checkbox and type CLOSE to permanently close your account.'
					=> 'Пожалуйста, отметьте флажок подтверждения и введите CLOSE, чтобы навсегда закрыть аккаунт.',
				'Your account has been permanently closed (%s). You will not be able to log in again. Please contact support if you need help.'
					=> 'Ваш аккаунт был окончательно закрыт (%s). Вы больше не сможете войти. Если вам нужна помощь, свяжитесь со службой поддержки.',
				'Your self-exclusion request has been received (%s). You have been logged out. Your account will reactivate automatically when the period ends.'
					=> 'Ваш запрос на самоисключение получен (%s). Вы вышли из аккаунта. Он автоматически восстановится по окончании срока.',
				// Login-block / entry-block messages (class-guard.php). The
				// reactivation date these splice in via %2$s stays English —
				// see this method's docblock.
				'Your account has been permanently closed and can no longer be used to log in. Please contact support if you need help.'
					=> 'Ваш аккаунт был окончательно закрыт, и вход больше невозможен. Если вам нужна помощь, свяжитесь со службой поддержки.',
				'Your account is currently %1$s until %2$s. It will reactivate automatically — you cannot log in until then. Please contact support if you need help.'
					=> 'Ваш аккаунт сейчас %1$s до %2$s. Он восстановится автоматически — до этого момента вход невозможен. Если вам нужна помощь, свяжитесь со службой поддержки.',
				'Your account is closed, so you cannot enter competitions.'
					=> 'Ваш аккаунт закрыт, поэтому вы не можете участвовать в конкурсах.',
				'Your account is currently %1$s, so you cannot enter competitions. It will reactivate on %2$s.'
					=> 'Ваш аккаунт сейчас %1$s, поэтому вы не можете участвовать в конкурсах. Он восстановится %2$s.',
			);
		}

		return isset( $ru[ $original ] ) ? $ru[ $original ] : $translated;
	}

	/**
	 * Russian's three plural forms (one/few/many) from one pipe-separated
	 * string — the same rule as `Nera_SAW_I18n::ru_plural()`, kept as a
	 * small private duplicate here rather than reaching into that class's
	 * own (deliberately protected) implementation; see
	 * `self::translate_woocommerce_plurals()`'s docblock for why this file
	 * needs its own copy rather than reusing that one.
	 *
	 * @param int    $n     The count.
	 * @param string $forms Pipe-separated `one|few|many`.
	 * @return string
	 */
	private static function ru_plural_form( $n, $forms ) {
		$f = explode( '|', $forms );

		$m10  = $n % 10;
		$m100 = $n % 100;

		if ( 1 === $m10 && 11 !== $m100 ) {
			return $f[0];
		}
		if ( $m10 >= 2 && $m10 <= 4 && ( $m100 < 12 || $m100 > 14 ) ) {
			return $f[1];
		}

		return $f[2];
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

		self::remember_in_polylang_cookie( $current );

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
	 * Also set Polylang's own cookie, so the language survives a plain page
	 * load, a bookmark, or a link from outside the section while signed out —
	 * the thing `self::SWITCH_ARG`'s own cookie, by itself, never gave a
	 * reader, because nothing outside this plugin's own resolver in
	 * `self::current()` ever looks at it. Client finding #8.
	 *
	 * Routed through Polylang's own `PLL_Cookie::set()` rather than a raw
	 * `setcookie()` so this picks up whatever cookie name, domain, samesite
	 * and expiration Polylang (or a filter on its own hooks) is actually
	 * configured with, instead of this plugin guessing at a second copy of
	 * that policy. A no-op when Polylang is absent, per the class docblock.
	 *
	 * @param string $current Language code this request resolved to.
	 */
	protected static function remember_in_polylang_cookie( $current ) {
		if ( ! class_exists( 'PLL_Cookie' ) ) {
			return;
		}

		PLL_Cookie::set( $current );
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
