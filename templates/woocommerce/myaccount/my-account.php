<?php
/**
 * Standalone section — My account, as one accordion.
 *
 * WooCommerce's own stock `my-account.php` renders a nav (`navigation.php`)
 * and, separately, whichever ONE endpoint the current URL names
 * (`woocommerce_account_content()` in wc-template-functions.php walks the
 * request's query vars and fires the first `woocommerce_account_{key}_endpoint`
 * action it finds, then stops) — nine rows to choose from, one page of content
 * at a time. Reading an order meant leaving Addresses; checking the wallet
 * meant leaving Orders.
 *
 * This renders every row's content on every load instead, each inside its own
 * closed `<details>`, and lets the browser do the showing and hiding.
 *
 * WHY EVERY PANEL, EVERY LOAD — NOT LOADED ON OPEN
 * -------------------------------------------------
 * The alternative was fetching a panel's content by script only once its
 * `<details>` opens. That needs JavaScript and an endpoint to ask it from,
 * and both are a second way for this screen to break that a plain page load
 * cannot. WooCommerce's own six render functions were checked one at a time
 * before this was built this way: none of them touch `$post` or `$wp_query`,
 * none require a query var this template does not already know how to supply
 * (`edit-address` needs an explicit empty string — its own default parameter
 * is `'billing'`, which is the individual form, not the Addresses overview
 * this row is supposed to show), and none error for a visitor who has never
 * used the feature — an empty Orders list or a never-touched wallet renders
 * its own "nothing here yet" notice, the same one it would show on its own
 * page. Six extra reads on every visit to this one screen is the honest cost
 * of a real answer instead of another click.
 *
 * FLAT, NOT GROUPED
 * -----------------
 * An earlier version of this screen folded Orders/Addresses/Payment
 * methods/Account details under one "Account" heading and Manage my
 * account/Need support under "Support" — two extra `<details>` to open before
 * reaching a row's own. Once every row already opens on its own to show its
 * content, the outer grouping was doing nothing a flat, deliberately ordered
 * list does not do better: $saw_order below is the order chosen for this
 * screen specifically (My Wallet first — it is the row most players open —
 * down to the two rows that are not content at all).
 *
 * WHAT STAYS A PLAIN LINK
 * ------------------------
 * "Need support?" points at a page of its own — Nera_RP_Settings' help page —
 * not a panel this screen owns; a support desk is not a section, so it stays
 * a normal `<a>`. Log out is a request, not something to read, and is the
 * last row for the same reason it used to be dropped to the bottom of a
 * group: past everything a visitor might actually open.
 *
 * WHAT DOES NOT APPEAR AT ALL
 * ----------------------------
 * Dashboard is WooCommerce's own default fallback — a greeting and a short
 * summary — and this page's fields already say more before a visitor opens
 * anything. Keeping a row whose only content restates what the screen already
 * shows was a row with nothing behind it; removed rather than given an empty
 * accordion body.
 *
 * Override by copying to `nera-strikeawin/woocommerce/myaccount/my-account.php`
 * in a theme (see Nera_SAW_Standalone_Chrome::unoverride_template()).
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Row order for this screen. An endpoint WooCommerce does not currently offer
 * (no gateway supports saved payment methods, a plugin that adds a row is
 * inactive) is simply absent from `wc_get_account_menu_items()` and skipped —
 * nothing here assumes all of these exist.
 *
 * @param string[] $order Endpoint keys, in the order to render them.
 */
$saw_order = (array) apply_filters(
	'nera_saw_account_nav_order',
	array(
		'woo-wallet',
		'orders',
		'payment-methods',
		'edit-account',
		'edit-address',
		'account-status',
		'nera-support',
		'customer-logout',
	)
);

/**
 * Endpoints left out of the accordion entirely — nothing here to open.
 *
 * @param string[] $hidden Endpoint keys.
 */
$saw_hidden = (array) apply_filters( 'nera_saw_account_nav_hidden', array( 'dashboard' ) );

/**
 * Endpoints that stay a plain link rather than becoming an accordion row —
 * an outbound page, or an action, neither of which is content to reveal.
 *
 * @param string[] $plain Endpoint keys.
 */
$saw_plain = (array) apply_filters( 'nera_saw_account_nav_plain', array( 'nera-support', 'customer-logout' ) );

/**
 * Label overrides. "Manage my account" is the self-exclusion screen's own
 * name for itself; on a row that already reads as part of a "Support" flow,
 * sitting right above "Need support?", the longer name was saying the same
 * thing twice.
 *
 * @param array $labels Endpoint key => label.
 */
$saw_labels = (array) apply_filters( 'nera_saw_account_nav_labels', array( 'account-status' => __( 'Support', 'nera-strikeawin' ) ) );

/*
 * The row labels themselves are not this plugin's copy to translate the
 * normal way (Nera_SAW_Standalone_Fields' seeded CMS fields): six of the
 * eight come straight from wc_get_account_menu_items(), gettext strings that
 * follow WordPress's own site locale, not this section's saw_lang toggle --
 * the two never switch together, since saw_lang was built to resolve this
 * plugin's own content, not to call switch_to_locale() for every plugin on
 * the page. Confirmed live: with saw_lang=ru, the page heading (a real
 * seeded field) translated correctly and every row label did not. Fixed
 * narrowly, the same way as everything else that isn't this plugin's own
 * field: a small hand-written table, read only when the section is actually
 * serving a non-default language.
 */
if ( class_exists( 'Nera_SAW_Language' ) && Nera_SAW_Language::current() !== Nera_SAW_Language::default_code() ) {
	$saw_row_translations = (array) apply_filters(
		'nera_saw_account_nav_label_translations',
		array(
			'ru' => array(
				'woo-wallet'       => __( 'Кошелёк', 'nera-strikeawin' ),
				'orders'           => __( 'Заказы', 'nera-strikeawin' ),
				'payment-methods'  => __( 'Способы оплаты', 'nera-strikeawin' ),
				'edit-account'     => __( 'Данные аккаунта', 'nera-strikeawin' ),
				'edit-address'     => __( 'Адреса', 'nera-strikeawin' ),
				'account-status'   => __( 'Поддержка', 'nera-strikeawin' ),
				'nera-support'     => __( 'Нужна помощь?', 'nera-strikeawin' ),
				'customer-logout'  => __( 'Выйти', 'nera-strikeawin' ),
			),
		)
	);
	$saw_current_lang = Nera_SAW_Language::current();
	if ( isset( $saw_row_translations[ $saw_current_lang ] ) ) {
		$saw_labels = array_merge( $saw_labels, $saw_row_translations[ $saw_current_lang ] );
	}
}

$saw_items = wc_get_account_menu_items();

// Anything WooCommerce offers that $saw_order does not mention yet still
// gets a row, appended before Log out rather than silently dropped — a
// plugin adding a new endpoint later should not have to also know to list
// it here.
$saw_rest = array_values( array_diff( array_keys( $saw_items ), $saw_order, $saw_hidden ) );
$saw_logout_pos = array_search( 'customer-logout', $saw_order, true );
if ( false !== $saw_logout_pos && $saw_rest ) {
	array_splice( $saw_order, $saw_logout_pos, 0, $saw_rest );
} else {
	$saw_order = array_merge( $saw_order, $saw_rest );
}

/**
 * One endpoint's own content, exactly what its own page would show — see the
 * docblock above for why every one of these is safe to call unconditionally.
 *
 * @param string $endpoint Endpoint key.
 */
$saw_panel = static function ( $endpoint ) {
	if ( has_action( 'woocommerce_account_' . $endpoint . '_endpoint' ) ) {
		do_action( 'woocommerce_account_' . $endpoint . '_endpoint', '' );
	}
};

/**
 * One row: an accordion item whose body is that endpoint's own panel.
 *
 * @param string $endpoint Endpoint key.
 * @param string $label    Row label.
 */
$saw_row = static function ( $endpoint, $label ) use ( $saw_panel ) {
	$open = wc_is_current_account_menu_item( $endpoint );
	printf(
		'<details class="saw-account-acc %1$s"%2$s><summary class="saw-account-acc__label">%3$s</summary><div class="saw-account-acc__panel">',
		esc_attr( wc_get_account_menu_item_classes( $endpoint ) ),
		$open ? ' open' : '',
		esc_html( $label )
	);
	$saw_panel( $endpoint );
	echo '</div></details>';
};

/**
 * A plain link row — no content of its own to reveal.
 *
 * @param string $endpoint Endpoint key.
 * @param string $label    Row label.
 */
$saw_link = static function ( $endpoint, $label ) {
	$extra = 'customer-logout' === $endpoint ? ' saw-account-link--logout' : '';
	printf(
		'<a class="saw-account-link%1$s %2$s" href="%3$s" %4$s>%5$s</a>',
		esc_attr( $extra ),
		esc_attr( wc_get_account_menu_item_classes( $endpoint ) ),
		esc_url( wc_get_account_endpoint_url( $endpoint ) ),
		wc_is_current_account_menu_item( $endpoint ) ? 'aria-current="page"' : '',
		esc_html( $label )
	);
};
?>

<div class="saw-account-nav">
	<?php
	foreach ( $saw_order as $saw_endpoint ) {
		if ( ! isset( $saw_items[ $saw_endpoint ] ) || in_array( $saw_endpoint, $saw_hidden, true ) ) {
			continue;
		}

		$saw_label = isset( $saw_labels[ $saw_endpoint ] ) ? $saw_labels[ $saw_endpoint ] : $saw_items[ $saw_endpoint ];

		if ( in_array( $saw_endpoint, $saw_plain, true ) ) {
			$saw_link( $saw_endpoint, $saw_label );
		} else {
			$saw_row( $saw_endpoint, $saw_label );
		}
	}
	?>
</div>
