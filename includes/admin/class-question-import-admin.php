<?php
/**
 * Question bank Import / Export admin UI (Milestone 4).
 *
 * Import: modal (preview → confirm → chunked AJAX).
 * Export: chunked AJAX to a server temp file, progress bar above the list
 * filter bar, auto-download when done. Example CSV + undo of last import.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Question_Import_Admin
 */
class Nera_SAW_Question_Import_Admin {

	const NONCE         = 'nera_saw_question_import';
	const AJAX_UPLOAD   = 'nera_saw_import_upload';
	const AJAX_APPLY    = 'nera_saw_import_apply';
	const AJAX_UNDO     = 'nera_saw_import_undo';
	const AJAX_EXPORT_START = 'nera_saw_export_start';
	const AJAX_EXPORT_CHUNK = 'nera_saw_export_chunk';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_footer-edit.php', array( __CLASS__, 'render_modal' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'toolbar_buttons' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'handle_downloads' ) );

		add_action( 'wp_ajax_' . self::AJAX_UPLOAD, array( __CLASS__, 'ajax_upload' ) );
		add_action( 'wp_ajax_' . self::AJAX_APPLY, array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_ajax_' . self::AJAX_UNDO, array( __CLASS__, 'ajax_undo' ) );
		add_action( 'wp_ajax_' . self::AJAX_EXPORT_START, array( __CLASS__, 'ajax_export_start' ) );
		add_action( 'wp_ajax_' . self::AJAX_EXPORT_CHUNK, array( __CLASS__, 'ajax_export_chunk' ) );
	}

	/**
	 * Enqueue list-screen assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || Nera_SAW_Question_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_enqueue_style( 'nera-saw-admin', NERA_SAW_PLUGIN_URL . 'assets/css/admin.css', array(), NERA_SAW_VERSION );
		wp_enqueue_script(
			'nera-saw-question-import',
			NERA_SAW_PLUGIN_URL . 'assets/js/question-import.js',
			array( 'jquery' ),
			NERA_SAW_VERSION,
			true
		);

		$last = Nera_SAW_Question_Import::last_batch();
		wp_localize_script(
			'nera-saw-question-import',
			'neraSawImport',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE ),
				'upload'       => self::AJAX_UPLOAD,
				'apply'        => self::AJAX_APPLY,
				'undo'         => self::AJAX_UNDO,
				'exportStart'  => self::AJAX_EXPORT_START,
				'exportChunk'  => self::AJAX_EXPORT_CHUNK,
				'filters'      => self::current_filter_args(),
				'exampleUrl'   => add_query_arg(
					array(
						'post_type'   => Nera_SAW_Question_CPT::POST_TYPE,
						'saw_example' => '1',
						'_wpnonce'    => wp_create_nonce( self::NONCE ),
					),
					admin_url( 'edit.php' )
				),
				'lastBatch'    => $last ? array(
					'batch_id' => $last['batch_id'],
					'created'  => count( (array) $last['created_ids'] ),
					'updated'  => count( (array) $last['updated_ids'] ),
					'at'       => $last['at'],
				) : null,
				'i18n'         => array(
					'import'           => __( 'Import', 'nera-strikeawin' ),
					'export'           => __( 'Export', 'nera-strikeawin' ),
					'example'          => __( 'Download example CSV', 'nera-strikeawin' ),
					'upload'           => __( 'Choose CSV file', 'nera-strikeawin' ),
					'preview'          => __( 'Preview', 'nera-strikeawin' ),
					'confirm'          => __( 'Confirm import', 'nera-strikeawin' ),
					'cancel'           => __( 'Cancel', 'nera-strikeawin' ),
					'close'            => __( 'Close', 'nera-strikeawin' ),
					'importing'        => __( 'Importing', 'nera-strikeawin' ),
					'exporting'        => __( 'Exporting', 'nera-strikeawin' ),
					'importDone'       => __( 'Import complete', 'nera-strikeawin' ),
					'exportDone'       => __( 'Export complete', 'nera-strikeawin' ),
					'failed'           => __( 'Something went wrong. Please try again.', 'nera-strikeawin' ),
					'publish'          => __( 'Publish now', 'nera-strikeawin' ),
					'draft'            => __( 'Save as drafts', 'nera-strikeawin' ),
					'undo'             => __( 'Undo this import', 'nera-strikeawin' ),
					'undoConfirm'      => __( 'Trash the questions created by the most recent import? Updated questions will not be reverted.', 'nera-strikeawin' ),
					'undoDone'         => __( 'Undone — created questions moved to Trash.', 'nera-strikeawin' ),
					'create'           => __( 'create', 'nera-strikeawin' ),
					'overwrite'        => __( 'overwrite', 'nera-strikeawin' ),
					'warning'          => __( 'warning', 'nera-strikeawin' ),
					'rejected'         => __( 'rejected', 'nera-strikeawin' ),
					'warnings'         => __( 'warnings', 'nera-strikeawin' ),
					'toDraft'          => __( '→ draft', 'nera-strikeawin' ),
					'line'             => __( 'Line', 'nera-strikeawin' ),
					'question'         => __( 'Question', 'nera-strikeawin' ),
					'level'            => __( 'Level', 'nera-strikeawin' ),
					'category'         => __( 'Category', 'nera-strikeawin' ),
					'correct'          => __( 'Correct', 'nera-strikeawin' ),
					'action'           => __( 'Action', 'nera-strikeawin' ),
					'noFile'           => __( 'Please choose a CSV file first.', 'nera-strikeawin' ),
					'questions'        => __( 'questions', 'nera-strikeawin' ),
				),
			)
		);
	}

	/**
	 * Import / Export / Undo buttons in the list toolbar.
	 *
	 * @param string $post_type Post type.
	 */
	public static function toolbar_buttons( $post_type = '' ) {
		if ( Nera_SAW_Question_CPT::POST_TYPE !== $post_type || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$example_url = add_query_arg(
			array(
				'post_type'   => Nera_SAW_Question_CPT::POST_TYPE,
				'saw_example' => '1',
				'_wpnonce'    => wp_create_nonce( self::NONCE ),
			),
			admin_url( 'edit.php' )
		);

		echo '<span class="saw-import-toolbar">';
		echo '<button type="button" class="button" id="saw-import-open">' . esc_html__( 'Import', 'nera-strikeawin' ) . '</button>';
		$last = Nera_SAW_Question_Import::last_batch();
		if ( $last && ! empty( $last['created_ids'] ) ) {
			echo '<button type="button" class="button" id="saw-import-undo">' . esc_html__( 'Undo last import', 'nera-strikeawin' ) . '</button>';
		}
		echo '<button type="button" class="button" id="saw-export-start">' . esc_html__( 'Export', 'nera-strikeawin' ) . '</button>';
		echo '</span>';
		printf( '<script>window.neraSawImportExampleUrl=%s;</script>', wp_json_encode( $example_url ) );
	}

	/**
	 * Current list filter/search args for Export.
	 *
	 * @return array
	 */
	public static function current_filter_args() {
		$args = array();
		if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_GET['saw_level'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['saw_level'] = sanitize_key( wp_unslash( $_GET['saw_level'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_GET['saw_product_cat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['saw_product_cat'] = (int) $_GET['saw_product_cat']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! empty( $_GET['post_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args['post_status'] = sanitize_key( wp_unslash( $_GET['post_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return $args;
	}

	/**
	 * Build WP_Query args from list filter GET/POST fields.
	 *
	 * @param array $filters Raw filter map.
	 * @return array
	 */
	private static function query_args_from_filters( array $filters ) {
		$query_args = array();
		if ( ! empty( $filters['s'] ) ) {
			$query_args['s'] = sanitize_text_field( $filters['s'] );
		}
		if ( ! empty( $filters['post_status'] ) && 'all' !== $filters['post_status'] ) {
			$query_args['post_status'] = sanitize_key( $filters['post_status'] );
		}
		if ( ! empty( $filters['saw_level'] ) ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => Nera_SAW_Question_CPT::META_LEVEL,
					'value' => sanitize_key( $filters['saw_level'] ),
				),
			);
		}
		if ( ! empty( $filters['saw_product_cat'] ) ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => array( (int) $filters['saw_product_cat'] ),
				),
			);
		}
		return $query_args;
	}

	/**
	 * Handle example CSV + finished export-file downloads.
	 */
	public static function handle_downloads() {
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$example = isset( $_GET['saw_example'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$file    = isset( $_GET['saw_export_file'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $example && ! $file ) {
			return;
		}
		if ( empty( $_GET['post_type'] ) || Nera_SAW_Question_CPT::POST_TYPE !== $_GET['post_type'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		check_admin_referer( self::NONCE );

		if ( $example ) {
			Nera_SAW_Question_Import::stream_example();
		}
		if ( $file ) {
			Nera_SAW_Question_Import::stream_export_file( sanitize_key( wp_unslash( $_GET['saw_export_file'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Import modal + inline export progress markup.
	 */
	public static function render_modal() {
		$screen = get_current_screen();
		if ( ! $screen || Nera_SAW_Question_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$example_url = wp_nonce_url(
			add_query_arg(
				array(
					'post_type'   => Nera_SAW_Question_CPT::POST_TYPE,
					'saw_example' => '1',
				),
				admin_url( 'edit.php' )
			),
			self::NONCE
		);
		?>
		<div id="saw-job-progress" class="saw-job-progress" hidden>
			<p class="saw-job-progress__status" id="saw-job-status"></p>
			<div class="saw-seeder-bar" data-tone="seed"><span class="saw-seeder-bar__fill" id="saw-job-bar"></span></div>
		</div>

		<div id="saw-import-modal" class="saw-import-modal" hidden>
			<div class="saw-import-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="saw-import-title">
				<header class="saw-import-modal__head">
					<h2 id="saw-import-title"><?php esc_html_e( 'Import questions', 'nera-strikeawin' ); ?></h2>
					<button type="button" class="saw-import-modal__close" id="saw-import-close" aria-label="<?php esc_attr_e( 'Close', 'nera-strikeawin' ); ?>">&times;</button>
				</header>
				<div class="saw-import-modal__body">
					<div class="saw-import-step" data-step="upload">
						<p class="description">
							<?php esc_html_e( 'Upload a CSV with columns: id, question, level, category, answer_1…answer_N, correct. Blank id creates a new question; filled id updates that question. Blank cells on update leave the existing value unchanged. Level and category must match existing names (never auto-created). correct is the answer number (2 = answer_2).', 'nera-strikeawin' ); ?>
						</p>
						<p>
							<a href="<?php echo esc_url( $example_url ); ?>" id="saw-import-example" download="saw-questions-example.csv"><?php esc_html_e( 'Download example CSV', 'nera-strikeawin' ); ?></a>
							<span class="saw-muted"> — <?php esc_html_e( 'built from this site’s levels and categories; rows with an id are update demos, EXAMPLE — rows are create demos.', 'nera-strikeawin' ); ?></span>
						</p>
						<p>
							<input type="file" id="saw-import-file" accept=".csv,text/csv">
						</p>
						<p>
							<button type="button" class="button button-primary" id="saw-import-preview"><?php esc_html_e( 'Preview', 'nera-strikeawin' ); ?></button>
						</p>
					</div>

					<div class="saw-import-step" data-step="preview" hidden>
						<p class="saw-import-summary" id="saw-import-summary"></p>
						<div class="saw-import-preview-wrap">
							<table class="widefat saw-import-preview" id="saw-import-preview-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Line', 'nera-strikeawin' ); ?></th>
										<th><?php esc_html_e( 'Action', 'nera-strikeawin' ); ?></th>
										<th><?php esc_html_e( 'Question', 'nera-strikeawin' ); ?></th>
										<th><?php esc_html_e( 'Level', 'nera-strikeawin' ); ?></th>
										<th><?php esc_html_e( 'Category', 'nera-strikeawin' ); ?></th>
										<th><?php esc_html_e( 'Correct', 'nera-strikeawin' ); ?></th>
										<th><?php esc_html_e( 'Notes', 'nera-strikeawin' ); ?></th>
									</tr>
								</thead>
								<tbody></tbody>
							</table>
						</div>
						<p class="saw-import-status-choice">
							<label><input type="radio" name="saw_import_status" value="publish" checked> <?php esc_html_e( 'Publish now', 'nera-strikeawin' ); ?></label>
							<label><input type="radio" name="saw_import_status" value="draft"> <?php esc_html_e( 'Save as drafts', 'nera-strikeawin' ); ?></label>
							<span class="description"><?php esc_html_e( '(Publish/draft applies to new questions without warnings. Rows with warnings are always saved as drafts. Overwrites without warnings keep their current status.)', 'nera-strikeawin' ); ?></span>
						</p>
						<p>
							<button type="button" class="button" id="saw-import-back"><?php esc_html_e( 'Back', 'nera-strikeawin' ); ?></button>
							<button type="button" class="button button-primary" id="saw-import-confirm"><?php esc_html_e( 'Confirm import', 'nera-strikeawin' ); ?></button>
						</p>
					</div>

					<div class="saw-import-step" data-step="running" hidden>
						<p class="saw-job-progress__status" id="saw-import-status"></p>
						<div class="saw-seeder-bar" data-tone="seed"><span class="saw-seeder-bar__fill" id="saw-import-bar"></span></div>
					</div>

					<div class="saw-import-step" data-step="done" hidden>
						<p class="saw-import-done-msg" id="saw-import-done-msg"></p>
						<p>
							<button type="button" class="button button-primary" id="saw-import-finish"><?php esc_html_e( 'Close &amp; reload', 'nera-strikeawin' ); ?></button>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: upload + parse + preview.
	 */
	public static function ajax_upload() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nera-strikeawin' ) ), 403 );
		}
		if ( empty( $_FILES['file'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'nera-strikeawin' ) ) );
		}
		$parsed = Nera_SAW_Question_Import::parse_file( $_FILES['file']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
		}
		$preview = Nera_SAW_Question_Import::build_preview( $parsed );
		$token   = Nera_SAW_Question_Import::store_preview( $preview );

		$rows = array();
		$all  = array_merge( $preview['create'], $preview['update'], $preview['rejected'] );
		usort(
			$all,
			static function ( $a, $b ) {
				return (int) $a['_line'] <=> (int) $b['_line'];
			}
		);
		foreach ( $all as $r ) {
			$rows[] = array(
				'line'     => (int) $r['_line'],
				'action'   => ! empty( $r['errors'] ) ? 'reject' : $r['action'],
				'id'       => (int) ( $r['id'] ?? 0 ),
				'question' => (string) ( $r['display']['question'] ?? '' ),
				'level'    => (string) ( $r['display']['level'] ?? '' ),
				'category' => (string) ( $r['display']['category'] ?? '' ),
				'correct'  => (string) ( $r['display']['correct'] ?? '' ),
				'errors'   => array_values( (array) ( $r['errors'] ?? array() ) ),
				'warnings' => array_values( (array) ( $r['warnings'] ?? array() ) ),
			);
		}

		wp_send_json_success(
			array(
				'token'   => $token,
				'summary' => $preview['summary'],
				'rows'    => $rows,
			)
		);
	}

	/**
	 * AJAX: apply one import chunk.
	 */
	public static function ajax_apply() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nera-strikeawin' ) ), 403 );
		}
		$token    = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$offset   = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'publish';
		$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
		$result   = Nera_SAW_Question_Import::apply_chunk( $token, $offset, $status, $batch_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: undo most recent import.
	 */
	public static function ajax_undo() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nera-strikeawin' ) ), 403 );
		}
		$result = Nera_SAW_Question_Import::undo_last();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: start chunked export (prepare temp file + header).
	 */
	public static function ajax_export_start() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nera-strikeawin' ) ), 403 );
		}
		$selected = array();
		if ( ! empty( $_POST['selected'] ) && is_array( $_POST['selected'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$selected = array_map( 'intval', wp_unslash( $_POST['selected'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$selected = array_values( array_filter( $selected ) );
		}
		if ( ! empty( $selected ) ) {
			$query_args = array( 'post__in' => $selected );
		} else {
			$filters = array();
			if ( ! empty( $_POST['filters'] ) && is_array( $_POST['filters'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$filters = wp_unslash( $_POST['filters'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
			$query_args = self::query_args_from_filters( $filters );
		}
		$result = Nera_SAW_Question_Import::start_export( $query_args );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: append one export chunk.
	 */
	public static function ajax_export_chunk() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nera-strikeawin' ) ), 403 );
		}
		$token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$result = Nera_SAW_Question_Import::export_chunk( $token, $offset );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}
}
