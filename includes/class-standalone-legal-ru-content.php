<?php
/**
 * Russian renderings of the two sibling-plugin screens whose content is
 * plain PHP data/constants rather than gettext calls — Help and support's
 * services directory (nera-responsible-play-plugin) and Our commitment to
 * player protection's measures (nera-voluntary-code-plugin).
 *
 * `includes/class-language.php`'s gettext filters translate every FIXED
 * label on these two screens already (headings, the GOV.UK/complaints
 * cross-link text…). What they cannot reach is the data itself: the
 * services directory's rows and the commitment page's intro copy/measures
 * are read via `get_field()`/plain array constants and output through
 * `esc_html()`, never through `__()`, so no gettext filter can see them.
 *
 * Rather than edit either sibling plugin (out of scope — see this
 * plugin's own standing rule), this class calls their own PUBLIC raw-data
 * getters directly — `Nera_RP_Services::get_services()`,
 * `Nera_VC_Content::get_measures()` — the same getters an admin's ACF
 * edits flow through, then re-renders the exact same markup/CSS classes
 * the sibling's own renderer uses, with a curated, exact-string Russian
 * lookup over the CURRENT English text. An admin who edits a row's text
 * in wp-admin simply stops matching a lookup key and that row quietly
 * reverts to English — the same "degrade to the source language" rule
 * every other translator in this plugin follows — rather than silently
 * drifting out of sync with a forked copy.
 *
 * A first-pass machine translation, pending the client's own review on
 * staging — see the task this was built for.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Standalone_Legal_RU_Content
 */
class Nera_SAW_Standalone_Legal_RU_Content {

	/**
	 * Russian rendering of `Nera_RP_Services::render_directory( ['intro' => true] )`
	 * — same markup/CSS classes, so `nera-rp-directory.css` styles it identically.
	 *
	 * @return string
	 */
	public static function render_help_directory() {
		if ( ! class_exists( 'Nera_RP_Services' ) || ! class_exists( 'Nera_RP_Settings' ) ) {
			return '';
		}

		$services = Nera_RP_Services::get_services();
		$intro    = Nera_RP_Settings::intro_copy();
		// Only translate the intro if it is still the plugin's own, unedited
		// default — an admin's own rewrite has no Russian counterpart here.
		// Whitespace-normalised: a WYSIWYG save round-trip reformats the
		// blank line between the two <p> tags without changing the text.
		if ( self::normalize_whitespace( $intro ) === self::normalize_whitespace( Nera_RP_Settings::DEFAULT_INTRO_COPY ) ) {
			$intro = '<p>Мы хотим, чтобы вы наслаждались нашими конкурсами ответственно. '
				. 'Если вы когда-либо почувствуете, что траты становятся поводом для '
				. 'беспокойства, или вам просто нужен перерыв, бесплатная помощь всегда '
				. 'доступна. Организации ниже предлагают конфиденциальные, беспристрастные '
				. 'консультации бесплатно.</p><p>Вы также можете связаться с нами напрямую '
				. 'в любое время, чтобы приостановить или закрыть свой аккаунт.</p>';
		}

		$blurbs = self::help_blurb_translations();

		ob_start();
		echo '<div class="nera-rp-directory">';
		echo '<div class="nera-rp-directory__intro">' . wp_kses_post( $intro ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() above.
		echo '<h2 class="nera-rp-directory__heading">' . esc_html( 'Организации поддержки' ) . '</h2>';

		if ( empty( $services ) ) {
			echo '<p>' . esc_html( 'Сейчас нет доступных служб поддержки.' ) . '</p>';
		} else {
			echo '<ul class="nera-rp-directory__list">';
			foreach ( $services as $service ) {
				$blurb = isset( $blurbs[ $service['name'] ] ) ? $blurbs[ $service['name'] ] : $service['blurb'];

				echo '<li class="nera-rp-directory__item">';
				echo '<strong class="nera-rp-directory__name">' . esc_html( $service['name'] ) . '</strong>';
				if ( $blurb ) {
					echo '<span class="nera-rp-directory__blurb"> — ' . esc_html( $blurb ) . '</span>';
				}
				echo '<span class="nera-rp-directory__contacts">';
				if ( $service['phone'] ) {
					$tel_href = 'tel:' . preg_replace( '/[^0-9+]/', '', $service['phone'] );
					echo '<a class="nera-rp-directory__phone" href="' . esc_attr( $tel_href ) . '">' . esc_html( $service['phone'] ) . '</a>';
				}
				if ( $service['url'] ) {
					echo '<a class="nera-rp-directory__link" href="' . esc_url( $service['url'] ) . '" rel="noopener noreferrer" target="_blank">' . esc_html( $service['url'] ) . '</a>';
				}
				echo '</span>';
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</div><!-- .nera-rp-directory -->';

		return (string) ob_get_clean();
	}

	/**
	 * Collapse all whitespace runs to a single space, for comparing stored
	 * text against this class's own curated originals regardless of how a
	 * WYSIWYG/repeater field happens to have reformatted line breaks.
	 *
	 * @param string $text Text to normalise.
	 * @return string
	 */
	private static function normalize_whitespace( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		return str_replace( '> <', '><', $text );
	}

	/**
	 * Curated Russian blurbs for the plugin's own seeded default services,
	 * keyed by the service NAME (a name stays in English/unchanged either
	 * way — these are real organisations). A service an admin has renamed,
	 * or a custom row they added themselves, simply has no entry here and
	 * keeps its own (English) blurb.
	 *
	 * @return array<string,string>
	 */
	private static function help_blurb_translations() {
		return array(
			'Citizens Advice'                          => 'консультации по потребительским и финансовым вопросам',
			'National Debtline (Money Advice Trust)'   => 'бесплатная консультация по долгам',
			'Samaritans'                                => 'эмоциональная поддержка, доступно 24/7',
			'Mind'                                       => 'поддержка психического здоровья',
		);
	}

	/**
	 * Russian rendering of `Nera_VC_Content::render_page()` — same markup/
	 * CSS classes, so `nera-vc-page.css` (or equivalent) styles it
	 * identically. `build_measure_body()`/`replace_tokens()` are private on
	 * that class, so the token substitution and cross-links are replicated
	 * here using its own PUBLIC getters — see `replace_tokens()` below.
	 *
	 * @return string
	 */
	public static function render_player_protection() {
		if ( ! class_exists( 'Nera_VC_Content' ) || ! class_exists( 'Nera_VC_Settings' ) ) {
			return '';
		}

		$sections = array(
			array( 'area' => 'protections', 'heading' => 'Защита игроков' ),
			array( 'area' => 'transparency', 'heading' => 'Прозрачность' ),
			array( 'area' => 'accountability', 'heading' => 'Подотчётность' ),
		);

		$intro_raw = Nera_VC_Settings::intro_copy();
		if ( self::normalize_whitespace( $intro_raw ) === self::normalize_whitespace( Nera_VC_Settings::DEFAULT_INTRO_COPY ) ) {
			$intro_raw = '<p><strong>[Business Name]</strong> соблюдает <strong>Добровольный кодекс '
				. 'добросовестной практики для организаторов призовых розыгрышей</strong>, '
				. 'опубликованный Министерством цифровых технологий, культуры, СМИ и спорта '
				. 'Великобритании (DCMS). Мы стремимся проводить наши призовые розыгрыши честно, '
				. 'прозрачно и с надёжной защитой наших игроков. Приведённые ниже меры точно '
				. 'описывают, что мы для этого делаем.</p>';
		}
		$intro = self::replace_tokens( $intro_raw );
		$govuk = Nera_VC_Settings::govuk_url();

		$measure_translations = self::measure_translations();

		$html  = '<div class="nera-vc-page">' . "\n";
		$html .= '<div class="nera-vc-intro">' . wp_kses_post( $intro ) . '</div>' . "\n";
		$html .= '<p class="nera-vc-govuk"><a class="nera-vc-govuk__link" href="' . esc_url( $govuk ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html( 'Полный текст Кодекса на GOV.UK' )
			. '</a></p>' . "\n";

		foreach ( $sections as $section ) {
			$measures = Nera_VC_Content::get_measures( $section['area'] );

			$html .= '<section class="nera-vc-section">' . "\n";
			$html .= '<h2 class="nera-vc-section__title">' . esc_html( $section['heading'] ) . '</h2>' . "\n";
			$html .= '<ul class="nera-vc-measures">' . "\n";

			foreach ( $measures as $measure ) {
				$known      = isset( $measure_translations[ $measure['title'] ] )
					&& self::normalize_whitespace( $measure_translations[ $measure['title'] ]['body'] ) === self::normalize_whitespace( $measure['body'] );
				$ru_title   = $known ? $measure_translations[ $measure['title'] ]['title'] : $measure['title'];
				$body_text  = $known ? $measure_translations[ $measure['title'] ]['body_ru'] : $measure['body'];
				$body_html  = wp_kses_post( wpautop( self::replace_tokens( $body_text ) ) );

				if ( 'protections' === $section['area'] && 'Support when you need it' === $measure['title'] ) {
					$body_html .= self::help_page_link();
				}
				if ( 'accountability' === $section['area'] && 'Complaints process' === $measure['title'] ) {
					$body_html .= self::complaints_page_link();
				}

				$html .= '<li class="nera-vc-measure">' . "\n";
				$html .= '<strong class="nera-vc-measure__title">' . esc_html( $ru_title ) . '</strong>' . "\n";
				$html .= '<div class="nera-vc-measure__body">' . $body_html . '</div>' . "\n";
				$html .= '</li>' . "\n";
			}

			$html .= '</ul>' . "\n";
			$html .= '</section>' . "\n";
		}

		$html .= '</div>' . "\n";

		return $html;
	}

	/**
	 * Same four tokens `Nera_VC_Content::replace_tokens()` (private) applies,
	 * read the same way, via that class's own public getters/ACF option
	 * fields — so a Russian measure substitutes the real business name/
	 * address/email/ADR provider exactly like the English original does.
	 *
	 * @param string $text Text possibly containing [Business Name] etc.
	 * @return string
	 */
	private static function replace_tokens( $text ) {
		$business_name = '';
		if ( function_exists( 'get_field' ) ) {
			$business_name = (string) get_field( 'legal_business_name', 'option' );
		}
		if ( '' === $business_name ) {
			$business_name = Nera_SAW_Brand::name();
		}
		$business_name = sanitize_text_field( $business_name );

		$business_address = '';
		if ( function_exists( 'get_field' ) ) {
			$business_address = (string) get_field( 'legal_business_address', 'option' );
		}
		if ( '' === $business_address ) {
			$business_address = 'address available on request';
		} else {
			$business_address = preg_replace( '/[\r\n]+/', ', ', $business_address );
			$business_address = sanitize_text_field( $business_address );
		}

		$email = '';
		if ( function_exists( 'get_field' ) ) {
			$email = (string) get_field( 'legal_contact_email', 'option' );
		}
		if ( '' === $email ) {
			$email = (string) get_option( 'admin_email' );
		}
		$email = sanitize_text_field( $email );

		$adr = class_exists( 'Nera_VC_Settings' ) ? Nera_VC_Settings::adr_provider() : '';
		if ( '' === $adr ) {
			$adr = 'our nominated ADR provider (details available on request)';
		}
		$adr = sanitize_text_field( $adr );

		$text = str_replace( '[Business Name]', $business_name, $text );
		$text = str_replace( '[Business Address]', $business_address, $text );
		$text = str_replace( '[Email]', $email, $text );
		$text = str_replace( '[ADR Provider]', $adr, $text );

		return $text;
	}

	/**
	 * Mirrors `Nera_VC_Content::help_page_link()` (private), in Russian.
	 *
	 * @return string
	 */
	private static function help_page_link() {
		$url = class_exists( 'Nera_VC_Settings' ) ? Nera_VC_Settings::help_link_url() : '';
		if ( '' === $url ) {
			return '';
		}

		$label = Nera_VC_Settings::help_link_label();
		if ( 'See our Help & support page' === $label ) {
			$label = 'Смотрите нашу страницу помощи и поддержки';
		}

		return ' <a class="nera-vc-measure__link" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Mirrors `Nera_VC_Content::complaints_page_link()` (private), in Russian.
	 *
	 * @return string
	 */
	private static function complaints_page_link() {
		$page = get_page_by_path( 'complaints' );
		if ( ! $page || 'publish' !== get_post_status( $page ) ) {
			$page = get_page_by_path( 'complaints-procedure' );
		}
		if ( ! $page || 'publish' !== get_post_status( $page ) ) {
			return '';
		}

		$permalink = get_permalink( $page->ID );
		if ( ! $permalink ) {
			return '';
		}

		return ' <a class="nera-vc-measure__link" href="' . esc_url( $permalink ) . '">' . esc_html( 'Наша процедура рассмотрения жалоб' ) . '</a>';
	}

	/**
	 * Curated Russian title/body pairs for the plugin's own seeded default
	 * measures, keyed by the CURRENT English title. Matched against both
	 * title AND body before use (`render_player_protection()`'s `$known`
	 * check) — an admin who has edited a measure's body no longer matches,
	 * and that single row falls back to English rather than pairing an
	 * edited English body with a stale Russian title.
	 *
	 * @return array<string,array{title:string,body:string,body_ru:string}>
	 */
	private static function measure_translations() {
		$rows = array(
			array(
				'title'   => 'Age verification (18+)',
				'body'    => 'Entry is restricted to people aged 18 or over. We run age checks at registration and before entry.',
				'title_ru' => 'Проверка возраста (18+)',
				'body_ru'  => 'Участие доступно только лицам от 18 лет. Мы проводим проверку возраста при регистрации и перед участием.',
			),
			array(
				'title'   => 'Spending limits',
				'body'    => 'We apply proportionate monthly spend limits across all our draws, and you can set your own monthly limit (including £0) when you register or any time from your account.',
				'title_ru' => 'Лимиты трат',
				'body_ru'  => 'Мы применяем соразмерные ежемесячные лимиты трат по всем нашим розыгрышам, и вы можете установить собственный ежемесячный лимит (включая £0) при регистрации или в любое время в своём аккаунте.',
			),
			array(
				'title'   => 'Pause, suspend or close your account',
				'body'    => 'You can take a break, suspend your account (minimum 6 months) or close it permanently at any time. We send no marketing while your account is suspended.',
				'title_ru' => 'Приостановить или закрыть аккаунт',
				'body_ru'  => 'Вы можете сделать перерыв, приостановить свой аккаунт (минимум на 6 месяцев) или закрыть его навсегда в любое время. Пока ваш аккаунт приостановлен, мы не отправляем маркетинговые рассылки.',
			),
			array(
				'title'   => 'Credit card limits',
				'body'    => 'Credit-card entries are capped at £250 per player per month, and we never accept credit cards for instant-win draws.',
				'title_ru' => 'Лимиты по кредитным картам',
				'body_ru'  => 'Участие с оплатой кредитной картой ограничено £250 на игрока в месяц, и мы никогда не принимаем кредитные карты для розыгрышей с мгновенным выигрышем.',
			),
			array(
				'title'   => 'Support when you need it',
				'body'    => 'We signpost free, confidential help (Citizens Advice, National Debtline, Samaritans, Mind) and monitor for signs of harm so we can step in.',
				'title_ru' => 'Поддержка, когда она нужна',
				'body_ru'  => 'Мы указываем на бесплатную, конфиденциальную помощь (Citizens Advice, National Debtline, Samaritans, Mind) и следим за признаками вреда, чтобы вовремя вмешаться.',
			),
			array(
				'title'   => 'Ticket numbers & odds',
				'body'    => 'Maximum ticket numbers and your odds of winning are shown clearly before you enter.',
				'title_ru' => 'Количество билетов и шансы',
				'body_ru'  => 'Максимальное количество билетов и ваши шансы на выигрыш чётко указаны до участия.',
			),
			array(
				'title'   => 'Closing dates',
				'body'    => 'Every draw\'s closing date is displayed up front and is never changed once set.',
				'title_ru' => 'Даты закрытия',
				'body_ru'  => 'Дата закрытия каждого розыгрыша указывается заранее и никогда не меняется после установки.',
			),
			array(
				'title'   => 'Prize details',
				'body'    => 'Full prize details are shown. The advertised prize (or a fair cash alternative) is always awarded, never reduced or cancelled because of low ticket sales.',
				'title_ru' => 'Детали приза',
				'body_ru'  => 'Полные детали приза всегда указаны. Заявленный приз (или справедливый денежный эквивалент) всегда вручается, никогда не уменьшается и не отменяется из-за низких продаж билетов.',
			),
			array(
				'title'   => 'Free entry route',
				'body'    => 'Every paid draw has a genuine free postal entry route with an equal chance of winning, shown prominently before purchase.',
				'title_ru' => 'Бесплатный способ участия',
				'body_ru'  => 'У каждого платного розыгрыша есть настоящий бесплатный способ участия по почте с равными шансами на выигрыш, явно указанный перед покупкой.',
			),
			array(
				'title'   => 'Fair draws',
				'body'    => 'Winners are selected by a verifiably random, auditable process, in accordance with the laws of chance.',
				'title_ru' => 'Честные розыгрыши',
				'body_ru'  => 'Победители выбираются с помощью проверяемо случайного, подлежащего аудиту процесса, в соответствии с законами вероятности.',
			),
			array(
				'title'   => 'Complaints process',
				'body'    => 'We have a clear complaints process with defined response times.',
				'title_ru' => 'Процедура подачи жалоб',
				'body_ru'  => 'У нас есть чёткая процедура подачи жалоб с установленными сроками ответа.',
			),
			array(
				'title'   => 'Independent dispute resolution',
				'body'    => 'If we cannot resolve your complaint to your satisfaction, you can escalate it to an independent alternative dispute resolution (ADR) provider: [ADR Provider].',
				'title_ru' => 'Независимое разрешение споров',
				'body_ru'  => 'Если мы не можем разрешить вашу жалобу к вашему удовлетворению, вы можете передать её независимому органу по альтернативному разрешению споров (ADR): [ADR Provider].',
			),
			array(
				'title'   => 'Who we are & how to reach us',
				'body'    => 'Operator: [Business Name], [Business Address]. Contact us at [Email]. Our prize draws run under the free-entry exemption and are not licensed by the Gambling Commission. You can give feedback on the Code to DCMS at prizedrawcode@dcms.gov.uk.',
				'title_ru' => 'Кто мы и как с нами связаться',
				'body_ru'  => 'Оператор: [Business Name], [Business Address]. Свяжитесь с нами по адресу [Email]. Наши призовые розыгрыши проводятся в рамках исключения для бесплатного участия и не лицензируются Gambling Commission. Вы можете оставить отзыв о Кодексе в DCMS по адресу prizedrawcode@dcms.gov.uk.',
			),
			array(
				'title'   => 'Ongoing compliance',
				'body'    => 'We monitor and regularly review our compliance with the Code, and we require third parties who support our draws to follow it too.',
				'title_ru' => 'Постоянное соответствие требованиям',
				'body_ru'  => 'Мы отслеживаем и регулярно пересматриваем соблюдение Кодекса, а также требуем от третьих сторон, поддерживающих наши розыгрыши, также соблюдать его.',
			),
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row['title'] ] = array(
				'title'   => $row['title_ru'],
				'body'    => $row['body'],
				'body_ru' => $row['body_ru'],
			);
		}

		return $map;
	}
}
