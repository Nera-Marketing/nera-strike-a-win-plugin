<?php
/**
 * Quiz Log WP_List_Table.
 *
 * Loaded on demand from Nera_SAW_Log_Admin::render() AFTER
 * wp-admin/includes/class-wp-list-table.php.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP_List_Table over the diagnostic quiz log.
 */
class Nera_SAW_Log_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array( 'singular' => 'saw_log', 'plural' => 'saw_logs', 'ajax' => false ) );
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'created_at'  => __( 'Time', 'nera-strikeawin' ),
			'event'       => __( 'Type', 'nera-strikeawin' ),
			'competition' => __( 'Competition', 'nera-strikeawin' ),
			'tier'        => __( 'Tier', 'nera-strikeawin' ),
			'user'        => __( 'User', 'nera-strikeawin' ),
			'run'         => __( 'Run', 'nera-strikeawin' ),
			'message'     => __( 'Message', 'nera-strikeawin' ),
		);
	}

	/**
	 * Sortable columns (Time, newest first by default).
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array( 'created_at' => array( 'created_at', true ) );
	}

	/**
	 * Filter bar: search + type + tier + competition.
	 */
	public function filter_bar() {
		$type = isset( $_GET['s_type'] ) ? sanitize_key( wp_unslash( $_GET['s_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tier = isset( $_GET['s_tier'] ) ? sanitize_key( wp_unslash( $_GET['s_tier'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$comp = isset( $_GET['s_comp'] ) ? absint( $_GET['s_comp'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$s    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="saw-field-row" style="margin-bottom:12px;gap:10px;flex-wrap:wrap">';

		// Search.
		echo '<label class="saw-field"><span>' . esc_html__( 'Search', 'nera-strikeawin' ) . '</span>';
		echo '<input type="text" name="s" value="' . esc_attr( $s ) . '" placeholder="' . esc_attr__( 'message, user, run #, order #', 'nera-strikeawin' ) . '" autocomplete="off"></label>';

		// Type.
		echo '<label class="saw-field"><span>' . esc_html__( 'Type', 'nera-strikeawin' ) . '</span><select name="s_type">';
		echo '<option value="">' . esc_html__( 'All types', 'nera-strikeawin' ) . '</option>';
		foreach ( Nera_SAW_Log_Admin::type_labels() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $type, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';

		// Tier.
		echo '<label class="saw-field"><span>' . esc_html__( 'Tier', 'nera-strikeawin' ) . '</span><select name="s_tier">';
		echo '<option value="">' . esc_html__( 'All tiers', 'nera-strikeawin' ) . '</option>';
		foreach ( Nera_SAW_Constants::global_tiers() as $t ) {
			$key = (string) $t['key'];
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $tier, $key, false ) . '>' . esc_html( (string) $t['label'] ) . '</option>';
		}
		echo '</select></label>';

		// Competition.
		echo '<label class="saw-field"><span>' . esc_html__( 'Competition', 'nera-strikeawin' ) . '</span><select name="s_comp">';
		echo '<option value="0">' . esc_html__( 'All competitions', 'nera-strikeawin' ) . '</option>';
		foreach ( self::competition_options() as $id => $title ) {
			echo '<option value="' . (int) $id . '"' . selected( $comp, (int) $id, false ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '</select></label>';

		echo '<label class="saw-field"><span>&nbsp;</span>';
		submit_button( __( 'Filter', 'nera-strikeawin' ), '', 'filter', false );
		echo '</label>';
		echo '</div>';
	}

	/**
	 * SAW competitions for the filter dropdown (id => "Title (#id)").
	 *
	 * @return array
	 */
	private static function competition_options() {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => Nera_SAW_Competition_Config::META_IS_COMP, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$out = array();
		foreach ( (array) $ids as $id ) {
			$out[ (int) $id ] = get_the_title( $id ) . ' (#' . (int) $id . ')';
		}
		return $out;
	}

	/**
	 * Query rows with filters + pagination.
	 */
	public function prepare_items() {
		global $wpdb;
		$t = Nera_SAW_Database::table( 'log' );

		$per_page = 50;
		$paged    = max( 1, (int) $this->get_pagenum() );
		$offset   = ( $paged - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		$type = isset( $_GET['s_type'] ) ? sanitize_key( wp_unslash( $_GET['s_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $type ) {
			$where[]  = 'event = %s';
			$params[] = $type;
		}

		$tier = isset( $_GET['s_tier'] ) ? sanitize_key( wp_unslash( $_GET['s_tier'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $tier ) {
			$where[]  = 'tier_key = %s';
			$params[] = $tier;
		}

		$comp = isset( $_GET['s_comp'] ) ? absint( $_GET['s_comp'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $comp ) {
			$where[]  = 'competition_id = %d';
			$params[] = $comp;
		}

		$s = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $s ) {
			$like    = '%' . $wpdb->esc_like( $s ) . '%';
			$clause  = 'message LIKE %s';
			$params[] = $like;
			// Numeric search also matches run/order id.
			if ( ctype_digit( $s ) ) {
				$clause  .= ' OR run_id = %d OR order_id = %d';
				$params[] = (int) $s;
				$params[] = (int) $s;
			}
			// Name search also matches user ids.
			$user_ids = get_users(
				array(
					'search'         => '*' . $s . '*',
					'search_columns' => array( 'user_login', 'user_nicename', 'user_email', 'display_name' ),
					'fields'         => 'ID',
					'number'         => 100,
				)
			);
			$user_ids = array_map( 'intval', (array) $user_ids );
			if ( ! empty( $user_ids ) ) {
				$clause  .= ' OR user_id IN (' . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')';
				$params   = array_merge( $params, $user_ids );
			}
			$where[] = '(' . $clause . ')';
		}

		$where_sql = implode( ' AND ', $where );

		// Time ordering (newest first by default; Time column is sortable).
		$order = ( isset( $_GET['order'] ) && 'asc' === strtolower( (string) $_GET['order'] ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where_sql}";
		$total     = (int) ( empty( $params )
			? $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL
			: $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$rows_sql    = "SELECT * FROM {$t} WHERE {$where_sql} ORDER BY id {$order} LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( $per_page, $offset ) );
		$this->items = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per_page, 'total_pages' => (int) ceil( $total / $per_page ) ) );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Default column.
	 *
	 * @param object $item   Row.
	 * @param string $column Column key.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		return '';
	}

	/**
	 * Time column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_created_at( $item ) {
		return esc_html( mysql2date( 'Y-m-d H:i:s', $item->created_at ) );
	}

	/**
	 * Type column (coloured pill; errors/stalls red).
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_event( $item ) {
		$labels = Nera_SAW_Log_Admin::type_labels();
		$label  = isset( $labels[ $item->event ] ) ? $labels[ $item->event ] : $item->event;

		// Severity-coded so problems jump out: red = broke, amber = attention,
		// green = success, blue/grey = info.
		$class_map = array(
			'run_start'    => 'saw-pill--active',   // info (blue)
			'answer'       => 'saw-pill--muted',    // low-signal (grey)
			'run_complete' => 'saw-pill--green',    // success
			'run_abandon'  => 'saw-pill--orange',   // attention (amber)
			'run_restored' => 'saw-pill--green',    // admin recovery (success)
			'error'        => 'saw-pill--red',      // problem
			'client_error' => 'saw-pill--red',
			'client_stall' => 'saw-pill--red',
		);
		$class = isset( $class_map[ $item->event ] ) ? $class_map[ $item->event ] : 'saw-pill--active';
		// Any error-level row without a mapped event still reads red.
		if ( 'error' === $item->level && ! isset( $class_map[ $item->event ] ) ) {
			$class = 'saw-pill--red';
		}
		return '<span class="saw-pill ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Competition column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_competition( $item ) {
		$id = (int) $item->competition_id;
		if ( ! $id ) {
			return '&mdash;';
		}
		$title = get_the_title( $id );
		$title = '' !== $title ? $title : ( '#' . $id );
		$url   = get_edit_post_link( $id, 'url' );
		return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
	}

	/**
	 * Tier column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_tier( $item ) {
		$key = (string) $item->tier_key;
		if ( '' === $key ) {
			return '&mdash;';
		}
		foreach ( Nera_SAW_Constants::global_tiers() as $t ) {
			if ( (string) $t['key'] === $key ) {
				return esc_html( (string) $t['label'] );
			}
		}
		return esc_html( $key );
	}

	/**
	 * User column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_user( $item ) {
		$uid = (int) $item->user_id;
		if ( ! $uid ) {
			return '&mdash;';
		}
		$u = get_userdata( $uid );
		if ( ! $u ) {
			return '#' . $uid;
		}
		$url = get_edit_user_link( $uid );
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $u->display_name ) . '</a>';
	}

	/**
	 * Run column (links to the Report submission detail).
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_run( $item ) {
		$run = (int) $item->run_id;
		if ( ! $run ) {
			return '&mdash;';
		}
		if ( (int) $item->competition_id ) {
			$url = admin_url( 'admin.php?page=' . Nera_SAW_Report_Admin::SLUG . '&competition=' . (int) $item->competition_id . '&run=' . $run );
			return '<a href="' . esc_url( $url ) . '">#' . $run . '</a>';
		}
		return '#' . $run;
	}

	/**
	 * Message column (+ context snippet + slot).
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_message( $item ) {
		$out = esc_html( (string) $item->message );
		if ( (int) $item->slot_no ) {
			$out .= ' <span class="saw-muted" style="font-size:11px">' . esc_html( sprintf( __( 'slot %d', 'nera-strikeawin' ), (int) $item->slot_no ) ) . '</span>';
		}
		if ( ! empty( $item->context ) ) {
			$ctx = wp_strip_all_tags( (string) $item->context );
			if ( strlen( $ctx ) > 160 ) {
				$ctx = substr( $ctx, 0, 160 ) . '…';
			}
			$out .= '<br><code class="saw-muted" style="font-size:11px">' . esc_html( $ctx ) . '</code>';
		}
		return $out;
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		esc_html_e( 'No log entries match your filters.', 'nera-strikeawin' );
	}
}
