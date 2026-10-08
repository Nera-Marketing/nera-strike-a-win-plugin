<?php
/**
 * Standalone section — My account.
 *
 * Rebuilt to the client's own design (client findings #2/#45/#46, confirmed
 * with the user against the actual mock screenshot): a hub screen — avatar,
 * name, two stat tiles, then a short list of rows to open — rather than the
 * previous version's one page with every endpoint's content accordioned
 * open at once. Each row now opens its own page; the hub is what a bare
 * visit to `/my-account/` shows, and every other screen gets a "Back" link
 * rather than staying folded into this one.
 *
 * WHAT MOVED
 * ----------
 * Addresses and Payment methods are gone from the nav entirely (the client's
 * own call: a digital run has neither to manage). Orders and My Wallet no
 * longer have rows of their own — both are folded into "Account details"
 * alongside the account-edit form, each still its own `<details>` so
 * reading an order does not mean losing the wallet balance. My runs &
 * tickets / Draw results / Responsible play are new endpoints this plugin
 * registers itself (`Nera_SAW_Account_Pages`).
 *
 * WHY A SEPARATE "ACCOUNT DETAILS" COMPOSITE RATHER THAN THREE ROWS
 * -------------------------------------------------------------------
 * The hub's own row count was the point of the redesign — four rows to
 * scan, not eight. Account details' three endpoints keep the previous
 * version's own reasoning for being on one screen together (reading an
 * order should not mean leaving the wallet balance), just moved under one
 * hub row instead of three.
 *
 * Override by copying to `nera-strikeawin/woocommerce/myaccount/my-account.php`
 * in a theme (see Nera_SAW_Standalone_Chrome::unoverride_template()).
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

$saw_user_id = get_current_user_id();
if ( ! $saw_user_id ) {
	return;
}

$saw_items = wc_get_account_menu_items();

/**
 * Endpoints folded into the "Account details" composite rather than kept
 * as rows of their own.
 *
 * @param string[] $keys Endpoint keys.
 */
$saw_account_details_keys = (array) apply_filters(
	'nera_saw_account_details_endpoints',
	array( 'edit-account', 'orders', 'woo-wallet' )
);

global $wp;

/**
 * Which hub row (if any) the current request is inside. Endpoints folded
 * into "Account details" all answer to that one key; everything else
 * answers to its own.
 *
 * @return string Row key, or '' for the hub itself.
 */
$saw_current_row = static function () use ( $saw_account_details_keys ) {
	foreach ( array_merge( $saw_account_details_keys, array( 'my-runs-tickets', 'draw-results', 'responsible-play' ) ) as $saw_key ) {
		if ( wc_is_current_account_menu_item( $saw_key ) ) {
			return in_array( $saw_key, $saw_account_details_keys, true ) ? 'account-details' : $saw_key;
		}
	}
	return '';
};
$saw_row = $saw_current_row();

if ( '' === $saw_row ) {
	// ---------------------------------------------------------------
	// Hub.
	// ---------------------------------------------------------------
	$saw_user         = wp_get_current_user();
	$saw_display_name = $saw_user->display_name ? $saw_user->display_name : $saw_user->user_login;
	$saw_initial      = function_exists( 'mb_substr' ) ? mb_substr( $saw_display_name, 0, 1 ) : substr( $saw_display_name, 0, 1 );

	$saw_runs_unplayed = 0;
	if ( class_exists( 'Nera_SAW_Run_Grants' ) ) {
		foreach ( Nera_SAW_Run_Grants::balance_by_competition( $saw_user_id ) as $saw_bal ) {
			$saw_runs_unplayed += (int) $saw_bal['total'];
		}
	}

	// Tickets still live: earned, but on a competition that has not drawn
	// yet -- once a competition's own draw has happened those tickets move
	// to "Draw results" instead (client finding #45's own "runs unplayed /
	// tickets live" pair of stat tiles).
	$saw_tickets_live = 0;
	if ( class_exists( 'Nera_SAW_Run' ) ) {
		foreach ( Nera_SAW_Run::runs_for_user( $saw_user_id ) as $saw_entry ) {
			$saw_status = get_post_meta( $saw_entry['competition_id'], 'lty_lottery_status', true );
			if ( 'lty_lottery_finished' === $saw_status ) {
				continue;
			}
			foreach ( $saw_entry['completed'] as $saw_completed_run ) {
				$saw_tickets_live += count( $saw_completed_run['ticket_numbers'] );
			}
		}
	}

	/**
	 * The hub's own four rows, in the order the client's mock shows them.
	 *
	 * @param array $rows Endpoint key => label.
	 */
	$saw_hub_rows = (array) apply_filters(
		'nera_saw_account_hub_rows',
		array(
			'my-runs-tickets'  => __( 'My runs & tickets', 'nera-strikeawin' ),
			'draw-results'     => __( 'Draw results', 'nera-strikeawin' ),
			'responsible-play' => __( 'Responsible play', 'nera-strikeawin' ),
			'account-details'  => __( 'Account details', 'nera-strikeawin' ),
		)
	);
	?>
	<div class="saw-account-hub">

		<div class="saw-account-hub__identity">
			<span class="saw-account-hub__avatar" aria-hidden="true"><?php echo esc_html( strtoupper( $saw_initial ) ); ?></span>
			<p class="saw-account-hub__name"><?php echo esc_html( $saw_display_name ); ?></p>
			<p class="saw-account-hub__email"><?php echo esc_html( $saw_user->user_email ); ?></p>
		</div>

		<div class="saw-account-hub__stats">
			<div class="saw-account-hub__stat">
				<span class="saw-account-hub__stat-value"><?php echo esc_html( (string) $saw_runs_unplayed ); ?></span>
				<span class="saw-account-hub__stat-label"><?php esc_html_e( 'Runs unplayed', 'nera-strikeawin' ); ?></span>
			</div>
			<div class="saw-account-hub__stat">
				<span class="saw-account-hub__stat-value"><?php echo esc_html( (string) $saw_tickets_live ); ?></span>
				<span class="saw-account-hub__stat-label"><?php esc_html_e( 'Tickets live', 'nera-strikeawin' ); ?></span>
			</div>
		</div>

		<nav class="saw-account-hub__rows">
			<?php foreach ( $saw_hub_rows as $saw_key => $saw_label ) : ?>
				<?php
				// account-details has no WooCommerce menu-item key of its own --
				// it is this template's composite, not a registered endpoint --
				// so it always gets its row; the three real endpoints only show
				// once WooCommerce actually offers them.
				if ( 'account-details' !== $saw_key && ! isset( $saw_items[ $saw_key ] ) ) {
					continue;
				}
				$saw_url = 'account-details' === $saw_key
					? wc_get_account_endpoint_url( 'edit-account' )
					: wc_get_account_endpoint_url( $saw_key );
				?>
				<a class="saw-account-hub__row" href="<?php echo esc_url( $saw_url ); ?>">
					<span><?php echo esc_html( $saw_label ); ?></span>
					<span class="saw-account-hub__row-chevron" aria-hidden="true">›</span>
				</a>
			<?php endforeach; ?>
		</nav>

		<a class="saw-account-hub__logout" href="<?php echo esc_url( wc_logout_url() ); ?>">
			<?php esc_html_e( 'Log out', 'nera-strikeawin' ); ?>
		</a>

	</div>
	<?php
	return;
}

// ---------------------------------------------------------------------
// Any other row: a "Back" link, then that row's own content.
// ---------------------------------------------------------------------
?>
<a class="saw-account-hub__back" href="<?php echo esc_url( wc_get_account_endpoint_url( 'dashboard' ) ); ?>">
	&larr; <?php esc_html_e( 'Account', 'nera-strikeawin' ); ?>
</a>

<?php if ( 'account-details' === $saw_row ) : ?>

	<?php
	/**
	 * Composite panel: the same three endpoints the previous version of
	 * this screen always showed together, now reached from one hub row
	 * instead of three. `view-order` is a sub-endpoint of Orders, not a row
	 * of its own (reached only by clicking a specific order in the list),
	 * so it opens the Orders panel the same way the previous version of
	 * this screen did.
	 */
	$saw_view_order_id = isset( $wp->query_vars['view-order'] ) ? $wp->query_vars['view-order'] : '';

	foreach ( $saw_account_details_keys as $saw_endpoint ) {
		if ( ! isset( $saw_items[ $saw_endpoint ] ) ) {
			continue;
		}
		$saw_open = wc_is_current_account_menu_item( $saw_endpoint );
		printf(
			'<details class="saw-account-acc %1$s"%2$s><summary class="saw-account-acc__label">%3$s</summary><div class="saw-account-acc__panel">',
			esc_attr( wc_get_account_menu_item_classes( $saw_endpoint ) ),
			$saw_open ? ' open' : '',
			esc_html( $saw_items[ $saw_endpoint ] )
		);

		if ( 'orders' === $saw_endpoint && '' !== $saw_view_order_id && has_action( 'woocommerce_account_view-order_endpoint' ) ) {
			do_action( 'woocommerce_account_view-order_endpoint', $saw_view_order_id );
		} elseif ( has_action( 'woocommerce_account_' . $saw_endpoint . '_endpoint' ) ) {
			do_action( 'woocommerce_account_' . $saw_endpoint . '_endpoint', '' );
		}

		echo '</div></details>';
	}
	?>

<?php elseif ( has_action( 'woocommerce_account_' . $saw_row . '_endpoint' ) ) : ?>

	<?php do_action( 'woocommerce_account_' . $saw_row . '_endpoint', '' ); ?>

<?php endif; ?>
