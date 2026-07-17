<?php
/**
 * Quiz-submissions (runs) WP_List_Table for the Report page.
 *
 * Loaded on demand from Nera_SAW_Report_Admin::render_competition() AFTER
 * wp-admin/includes/class-wp-list-table.php, so the WP_List_Table parent exists
 * when this class is declared.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP_List_Table of quiz submissions (runs) for a competition.
 */
class Nera_SAW_Runs_List_Table extends WP_List_Table {

	/**
	 * Competition ID.
	 *
	 * @var int
	 */
	private $competition_id;

	/**
	 * Constructor.
	 *
	 * @param int $competition_id Competition.
	 */
	public function __construct( $competition_id ) {
		parent::__construct( array( 'singular' => 'saw_run', 'plural' => 'saw_runs', 'ajax' => false ) );
		$this->competition_id = (int) $competition_id;
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'order'   => __( 'Order', 'nera-strikeawin' ),
			'user'    => __( 'User', 'nera-strikeawin' ),
			'tier'    => __( 'Tier', 'nera-strikeawin' ),
			'status'  => __( 'Status', 'nera-strikeawin' ),
			'played'  => __( 'Played at', 'nera-strikeawin' ),
			'tickets' => __( 'Tickets won', 'nera-strikeawin' ),
			'view'    => '',
		);
	}

	/**
	 * The filter bar (AJAX username + order number).
	 */
	public function filter_bar() {
		$user  = isset( $_GET['s_user'] ) ? sanitize_text_field( wp_unslash( $_GET['s_user'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_GET['s_order'] ) ? sanitize_text_field( wp_unslash( $_GET['s_order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="saw-field-row" style="margin-bottom:12px">';
		echo '<label class="saw-field"><span>' . esc_html__( 'User name', 'nera-strikeawin' ) . '</span><input type="text" id="saw-filter-user" name="s_user" value="' . esc_attr( $user ) . '" autocomplete="off"></label>';
		echo '<label class="saw-field"><span>' . esc_html__( 'Order number', 'nera-strikeawin' ) . '</span><input type="text" name="s_order" value="' . esc_attr( $order ) . '"></label>';
		echo '<label class="saw-field"><span>&nbsp;</span>';
		submit_button( __( 'Filter', 'nera-strikeawin' ), '', 'filter', false );
		echo '</label>';
		echo '</div>';
	}

	/**
	 * Prepare items (query + pagination + filters).
	 */
	public function prepare_items() {
		global $wpdb;
		$runs = Nera_SAW_Database::table( 'runs' );

		$per_page = 20;
		$paged    = max( 1, (int) $this->get_pagenum() );
		$offset   = ( $paged - 1 ) * $per_page;

		$where  = array( 'competition_id = %d' );
		$params = array( $this->competition_id );

		$s_order = isset( $_GET['s_order'] ) ? absint( $_GET['s_order'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $s_order ) {
			$where[]  = 'order_id = %d';
			$params[] = $s_order;
		}

		$s_user = isset( $_GET['s_user'] ) ? sanitize_text_field( wp_unslash( $_GET['s_user'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $s_user ) {
			$user_ids = get_users(
				array(
					'search'         => '*' . $s_user . '*',
					'search_columns' => array( 'user_login', 'user_nicename', 'user_email', 'display_name' ),
					'fields'         => 'ID',
					'number'         => 200,
				)
			);
			$user_ids = array_map( 'intval', (array) $user_ids );
			if ( empty( $user_ids ) ) {
				$user_ids = array( 0 );
			}
			$where[] = 'user_id IN (' . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $user_ids );
		}

		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$runs} WHERE {$where_sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$sql          = "SELECT * FROM {$runs} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params_page  = array_merge( $params, array( $per_page, $offset ) );
		$this->items  = $wpdb->get_results( $wpdb->prepare( $sql, $params_page ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per_page, 'total_pages' => (int) ceil( $total / $per_page ) ) );
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Default column fallback.
	 *
	 * @param object $item   Run row.
	 * @param string $column Column key.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		return '';
	}

	/**
	 * Order column.
	 *
	 * @param object $item Run row.
	 * @return string
	 */
	public function column_order( $item ) {
		$order_id = (int) $item->order_id;
		if ( ! $order_id ) {
			return '&mdash;';
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$url   = $order ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		return '<a href="' . esc_url( $url ) . '">#' . $order_id . '</a>';
	}

	/**
	 * User column.
	 *
	 * @param object $item Run row.
	 * @return string
	 */
	public function column_user( $item ) {
		$uid = (int) $item->user_id;
		$u   = $uid ? get_userdata( $uid ) : null;
		if ( ! $u ) {
			return '&mdash;';
		}
		$url = get_edit_user_link( $uid );
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $u->display_name ) . '</a>';
	}

	/**
	 * Tier column.
	 *
	 * @param object $item Run row.
	 * @return string
	 */
	public function column_tier( $item ) {
		$key = (string) $item->tier_key;
		if ( '' === $key ) {
			return '&mdash;';
		}
		// Prefer the run snapshot's tier label; fall back to the global set.
		$config = $item->config_snapshot ? json_decode( $item->config_snapshot, true ) : array();
		if ( is_array( $config ) ) {
			foreach ( (array) ( $config['tiers'] ?? array() ) as $t ) {
				if ( (string) $t['key'] === $key ) {
					return esc_html( $t['label'] );
				}
			}
		}
		foreach ( Nera_SAW_Constants::global_tiers() as $t ) {
			if ( (string) $t['key'] === $key ) {
				return esc_html( $t['label'] );
			}
		}
		return esc_html( $key );
	}

	/**
	 * Status column.
	 *
	 * @param object $item Run row.
	 * @return string
	 */
	public function column_status( $item ) {
		$status     = (string) $item->status;
		$end_reason = isset( $item->end_reason ) ? (string) $item->end_reason : '';

		// An active run flagged 'errored' is stuck mid-quiz (ADR 0013): red pill +
		// a Restore button (refund the run + void it). Only these are restorable.
		if ( 'active' === $status && 'errored' === $end_reason ) {
			$pill    = '<span class="saw-pill saw-pill--red">' . esc_html__( 'Errored', 'nera-strikeawin' ) . '</span>';
			$restore = '<a class="button button-small" style="margin-left:6px" href="'
				. esc_url( Nera_SAW_Report_Admin::restore_url( $this->competition_id, (int) $item->id ) ) . '"'
				. ' onclick="return confirm(\'' . esc_js( __( 'Restore this run? The player gets their run back and this stuck run is voided.', 'nera-strikeawin' ) ) . '\');">'
				. esc_html__( 'Restore', 'nera-strikeawin' ) . '</a>';
			return $pill . $restore;
		}
		if ( 'voided' === $status ) {
			return '<span class="saw-pill saw-pill--expired">' . esc_html__( 'Voided', 'nera-strikeawin' ) . '</span>';
		}

		// A finalized run also carries an end_reason (completed | abandoned |
		// expired). Surface abandoned/expired distinctly (ADR 0010); a plain
		// completed run keeps the neutral "finalized" pill.
		if ( 'finalized' === $status && 'abandoned' === $end_reason ) {
			return '<span class="saw-pill saw-pill--abandoned">' . esc_html__( 'Abandoned', 'nera-strikeawin' ) . '</span>';
		}
		if ( 'finalized' === $status && 'expired' === $end_reason ) {
			return '<span class="saw-pill saw-pill--expired">' . esc_html__( 'Expired', 'nera-strikeawin' ) . '</span>';
		}
		$class = 'finalized' === $status ? 'saw-pill--finalized' : 'saw-pill--active';
		return '<span class="saw-pill ' . esc_attr( $class ) . '">' . esc_html( $status ) . '</span>';
	}

	/**
	 * Played-at column.
	 *
	 * @param object $item Run row.
	 * @return string
	 */
	public function column_played( $item ) {
		$when = $item->started_at ? $item->started_at : $item->created_at;
		if ( ! $when ) {
			return '&mdash;';
		}
		$out = esc_html( mysql2date( 'Y-m-d H:i', $when ) );
		if ( $item->finalized_at ) {
			$out .= '<br><span class="saw-muted" style="font-size:11px">' . esc_html__( 'ended', 'nera-strikeawin' ) . ' ' . esc_html( mysql2date( 'H:i', $item->finalized_at ) ) . '</span>';
		}
		return $out;
	}

	/**
	 * Tickets-won column.
	 *
	 * @param object $item Run row.
	 * @return string
	 */
	public function column_tickets( $item ) {
		return (int) $item->spins_confirmed;
	}

	/**
	 * View-details column.
	 *
	 * @param object $item Run row.
	 * @return string
	 */
	public function column_view( $item ) {
		$url = admin_url( 'admin.php?page=' . Nera_SAW_Report_Admin::SLUG . '&competition=' . (int) $this->competition_id . '&run=' . (int) $item->id );
		return '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'View details', 'nera-strikeawin' ) . '</a>';
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		esc_html_e( 'No quiz submissions match your filters.', 'nera-strikeawin' );
	}
}
