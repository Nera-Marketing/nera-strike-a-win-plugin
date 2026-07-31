<?php
/**
 * Question bank CSV import / export (ADR 0015).
 *
 * Columns: id, question, level, category, answer_1..answer_N, correct.
 * Blank id = create; filled id = update in place. Blank cells on update mean
 * "leave unchanged". Answers are all-or-nothing. Level + category matched by
 * display name, never auto-created.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Question_Import
 */
class Nera_SAW_Question_Import {

	const CHUNK         = 50;
	const TRANSIENT_TTL = 1800; // 30 minutes.
	const OPTION_LAST   = 'nera_saw_last_import_batch';
	const EXAMPLE_UPDATE  = 5;
	const EXAMPLE_CREATE  = 5;

	/**
	 * Transient key for a user's pending import preview.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function transient_key( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		return 'nera_saw_import_preview_' . $user_id;
	}

	/**
	 * Parse an uploaded CSV into raw row arrays keyed by normalised headers.
	 *
	 * @param string $path Absolute path to the uploaded file.
	 * @return array|\WP_Error { headers, answer_cols, rows: [ line => cells ] }
	 */
	public static function parse_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'saw_import_unreadable', __( 'Could not read the uploaded file.', 'nera-strikeawin' ) );
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw || '' === $raw ) {
			return new WP_Error( 'saw_import_empty', __( 'The uploaded file is empty.', 'nera-strikeawin' ) );
		}
		// Strip UTF-8 BOM.
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		$delimiter = self::detect_delimiter( $raw );
		$handle    = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $handle, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );

		$header_row = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $header_row ) || empty( $header_row ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'saw_import_header', __( 'CSV header row is missing.', 'nera-strikeawin' ) );
		}
		$headers = array();
		foreach ( $header_row as $i => $h ) {
			$headers[ $i ] = self::normalise_header( (string) $h );
		}
		$required = array( 'question' );
		foreach ( $required as $need ) {
			if ( ! in_array( $need, $headers, true ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new WP_Error(
					'saw_import_columns',
					sprintf(
						/* translators: %s: column name */
						__( 'CSV is missing required column: %s', 'nera-strikeawin' ),
						$need
					)
				);
			}
		}
		$answer_cols = array();
		foreach ( $headers as $i => $h ) {
			if ( preg_match( '/^answer_(\d+)$/', $h, $m ) ) {
				$answer_cols[ (int) $m[1] ] = $i;
			}
		}
		ksort( $answer_cols, SORT_NUMERIC );

		$rows     = array();
		$line_num = 1; // Header is line 1.
		while ( ( $cells = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			++$line_num;
			if ( ! is_array( $cells ) ) {
				continue;
			}
			// Skip fully blank rows.
			$joined = trim( implode( '', array_map( 'strval', $cells ) ) );
			if ( '' === $joined ) {
				continue;
			}
			$assoc = array(
				'id'         => '',
				'question'   => '',
				'level'      => '',
				'category'   => '',
				'correct'    => '',
				'answers'    => array(),
				'_line'      => $line_num,
			);
			foreach ( $headers as $i => $key ) {
				$val = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
				if ( 'id' === $key || 'question' === $key || 'level' === $key || 'category' === $key || 'correct' === $key ) {
					$assoc[ $key ] = $val;
				}
			}
			foreach ( $answer_cols as $n => $i ) {
				$assoc['answers'][ $n ] = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
			}
			$rows[] = $assoc;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( empty( $rows ) ) {
			return new WP_Error( 'saw_import_norows', __( 'CSV has no data rows.', 'nera-strikeawin' ) );
		}

		return array(
			'answer_cols' => array_keys( $answer_cols ),
			'rows'        => $rows,
		);
	}

	/**
	 * Resolve parsed rows into create / update / reject with display values.
	 *
	 * @param array $parsed Output of parse_file().
	 * @return array Preview payload.
	 */
	public static function build_preview( array $parsed ) {
		$level_map = self::level_label_map();
		$cat_map   = self::category_name_map();
		$bank_dupes = self::existing_question_texts();

		$file_texts = array(); // normalised text => first line.
		$create     = array();
		$update     = array();
		$rejected   = array();
		$warnings   = array();
		$queue      = array();

		foreach ( $parsed['rows'] as $row ) {
			$resolved = self::resolve_row( $row, $level_map, $cat_map, $bank_dupes, $file_texts );
			if ( ! empty( $resolved['errors'] ) ) {
				$rejected[] = $resolved;
				continue;
			}
			if ( ! empty( $resolved['warnings'] ) ) {
				$warnings[] = $resolved;
			}
			$queue[] = $resolved;
			if ( 'update' === $resolved['action'] ) {
				$update[] = $resolved;
			} else {
				$create[] = $resolved;
			}
		}

		// Partitioned preview counts (mutually exclusive): clean create / clean
		// overwrite / warning / rejected — each row counted once (ADR 0017).
		$clean_create = 0;
		$clean_update = 0;
		foreach ( $create as $r ) {
			if ( empty( $r['warnings'] ) ) {
				++$clean_create;
			}
		}
		foreach ( $update as $r ) {
			if ( empty( $r['warnings'] ) ) {
				++$clean_update;
			}
		}

		return array(
			'create'      => $create,
			'update'      => $update,
			'rejected'    => $rejected,
			'warnings'    => $warnings,
			'queue'       => $queue,
			'summary'     => array(
				'create'   => $clean_create,
				'update'   => $clean_update,
				'warning'  => count( $warnings ),
				'rejected' => count( $rejected ),
			),
			'answer_cols' => $parsed['answer_cols'],
		);
	}

	/**
	 * Store a preview for confirm + chunked apply.
	 *
	 * @param array $preview Preview payload.
	 * @return string Token (transient key suffix).
	 */
	public static function store_preview( array $preview ) {
		$token = strtolower( wp_generate_password( 12, false, false ) );
		$key   = self::transient_key() . '_' . $token;
		$queue = isset( $preview['queue'] ) ? (array) $preview['queue'] : array_merge( (array) $preview['create'], (array) $preview['update'] );
		set_transient(
			$key,
			array(
				'queue'   => $queue,
				'summary' => $preview['summary'],
				'user_id' => get_current_user_id(),
			),
			self::TRANSIENT_TTL
		);
		return $token;
	}

	/**
	 * Load a stored preview by token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function load_preview( $token ) {
		$key  = self::transient_key() . '_' . sanitize_key( $token );
		$data = get_transient( $key );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Apply one chunk of a stored import.
	 *
	 * @param string $token      Preview token.
	 * @param int    $offset     Row offset.
	 * @param string $new_status publish|draft for created rows without warnings.
	 *                           Warned rows are always saved as draft (ADR 0017).
	 * @param string $batch_id   Import batch id (created on first chunk).
	 * @return array|\WP_Error
	 */
	public static function apply_chunk( $token, $offset, $new_status, $batch_id = '' ) {
		$data = self::load_preview( $token );
		if ( ! $data ) {
			return new WP_Error( 'saw_import_expired', __( 'Import preview expired. Please upload the file again.', 'nera-strikeawin' ) );
		}
		$queue  = (array) $data['queue'];
		$total  = count( $queue );
		$offset = max( 0, (int) $offset );
		if ( '' === $batch_id ) {
			$batch_id = self::new_batch_id();
		}
		$new_status = ( 'draft' === $new_status ) ? 'draft' : 'publish';

		$slice   = array_slice( $queue, $offset, self::CHUNK );
		$created = array();
		$updated = array();
		$failed  = array();

		foreach ( $slice as $row ) {
			$result = self::apply_row( $row, $new_status, $batch_id );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'line'    => (int) ( $row['_line'] ?? 0 ),
					'message' => $result->get_error_message(),
				);
				continue;
			}
			if ( 'create' === $result['action'] ) {
				$created[] = (int) $result['id'];
			} else {
				$updated[] = (int) $result['id'];
			}
		}

		$next_offset = $offset + count( $slice );
		$done        = $next_offset >= $total;

		// Accumulate batch ledger across chunks.
		$ledger = isset( $data['ledger'] ) && is_array( $data['ledger'] ) ? $data['ledger'] : array(
			'batch_id'    => $batch_id,
			'created_ids' => array(),
			'updated_ids' => array(),
			'failed'      => array(),
			'at'          => current_time( 'mysql' ),
			'user_id'     => get_current_user_id(),
		);
		$ledger['created_ids'] = array_values( array_unique( array_merge( $ledger['created_ids'], $created ) ) );
		$ledger['updated_ids'] = array_values( array_unique( array_merge( $ledger['updated_ids'], $updated ) ) );
		$ledger['failed']      = array_merge( $ledger['failed'], $failed );

		$key = self::transient_key() . '_' . sanitize_key( $token );
		if ( $done ) {
			update_option(
				self::OPTION_LAST,
				array(
					'batch_id'    => $batch_id,
					'created_ids' => $ledger['created_ids'],
					'updated_ids' => $ledger['updated_ids'],
					'at'          => $ledger['at'],
					'user_id'     => (int) $ledger['user_id'],
				),
				false
			);
			delete_transient( $key );
		} else {
			$data['ledger']   = $ledger;
			$data['batch_id'] = $batch_id;
			set_transient( $key, $data, self::TRANSIENT_TTL );
		}

		return array(
			'batch_id'    => $batch_id,
			'offset'      => $next_offset,
			'total'       => $total,
			'done'        => $done,
			'created'     => count( $ledger['created_ids'] ),
			'updated'     => count( $ledger['updated_ids'] ),
			'failed'      => $ledger['failed'],
			'progress'    => array(
				'current' => min( $next_offset, $total ),
				'total'   => $total,
			),
		);
	}

	/**
	 * Undo the most recent import batch: trash created questions only.
	 *
	 * @return array|\WP_Error { trashed: int, batch_id: string }
	 */
	public static function undo_last() {
		$last = get_option( self::OPTION_LAST, array() );
		if ( empty( $last['batch_id'] ) || empty( $last['created_ids'] ) ) {
			return new WP_Error( 'saw_import_no_undo', __( 'Nothing to undo — no recent import created questions.', 'nera-strikeawin' ) );
		}
		$trashed = 0;
		foreach ( (array) $last['created_ids'] as $id ) {
			$id = (int) $id;
			$p  = get_post( $id );
			if ( ! $p || Nera_SAW_Question_CPT::POST_TYPE !== $p->post_type ) {
				continue;
			}
			$batch = (string) get_post_meta( $id, Nera_SAW_Question_CPT::META_IMPORT, true );
			if ( $batch !== (string) $last['batch_id'] ) {
				continue;
			}
			if ( wp_trash_post( $id ) ) {
				++$trashed;
			}
		}
		delete_option( self::OPTION_LAST );
		return array(
			'trashed'  => $trashed,
			'batch_id' => (string) $last['batch_id'],
		);
	}

	/**
	 * Most recent import batch summary (for the undo button).
	 *
	 * @return array|null
	 */
	public static function last_batch() {
		$last = get_option( self::OPTION_LAST, array() );
		return ( ! empty( $last['batch_id'] ) ) ? $last : null;
	}

	/**
	 * Resolve post IDs for an export given list-screen filter args and/or selection.
	 *
	 * @param array $query_args Filters (s, post_status, meta_query, tax_query, …).
	 *                          Pass `post__in` / selected IDs to export only those.
	 * @return int[]
	 */
	public static function query_export_ids( array $query_args = array() ) {
		if ( ! empty( $query_args['post__in'] ) ) {
			$selected = array_values( array_unique( array_filter( array_map( 'intval', (array) $query_args['post__in'] ) ) ) );
			if ( empty( $selected ) ) {
				return array();
			}
			$args = array(
				'post_type'      => Nera_SAW_Question_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'post__in'       => $selected,
				'orderby'        => 'post__in',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			);
			return array_map( 'intval', (array) get_posts( $args ) );
		}

		$search = '';
		if ( isset( $query_args['s'] ) ) {
			$search = trim( (string) $query_args['s'] );
			unset( $query_args['s'] );
		}
		$args = array_merge(
			array(
				'post_type'      => Nera_SAW_Question_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			),
			$query_args
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
			$filter    = static function ( $sql, $query ) use ( $search ) {
				unset( $query );
				global $wpdb;
				$like = '%' . $wpdb->esc_like( $search ) . '%';
				return $wpdb->prepare(
					" AND (
						{$wpdb->posts}.post_title LIKE %s
						OR {$wpdb->posts}.post_content LIKE %s
						OR EXISTS (
							SELECT 1 FROM {$wpdb->postmeta} saw_ans
							WHERE saw_ans.post_id = {$wpdb->posts}.ID
							  AND saw_ans.meta_key = %s
							  AND saw_ans.meta_value LIKE %s
						)
					)",
					$like,
					$like,
					Nera_SAW_Question_CPT::META_ANSWERS_TEXT,
					$like
				);
			};
			add_filter( 'posts_search', $filter, 10, 2 );
			$ids = get_posts( $args );
			remove_filter( 'posts_search', $filter, 10 );
		} else {
			$ids = get_posts( $args );
		}
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Start a chunked export job (prepare temp CSV + header).
	 *
	 * @param array $query_args List filters.
	 * @return array|\WP_Error { token, total }
	 */
	public static function start_export( array $query_args = array() ) {
		$ids = self::query_export_ids( $query_args );
		$max = 2;
		foreach ( $ids as $id ) {
			$max = max( $max, count( Nera_SAW_Question_CPT::get_answers( (int) $id ) ) );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'saw_export_uploads', __( 'Could not access the uploads directory.', 'nera-strikeawin' ) );
		}
		$token    = strtolower( wp_generate_password( 12, false, false ) );
		$filename = 'saw-questions-' . gmdate( 'Y-m-d' ) . '.csv';
		$path     = trailingslashit( $uploads['basedir'] ) . 'saw-export-' . get_current_user_id() . '-' . $token . '.csv';

		$out = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			return new WP_Error( 'saw_export_write', __( 'Could not create the export file.', 'nera-strikeawin' ) );
		}
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$header = array( 'id', 'question', 'level', 'category' );
		for ( $n = 1; $n <= $max; $n++ ) {
			$header[] = 'answer_' . $n;
		}
		$header[] = 'correct';
		fputcsv( $out, $header );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		set_transient(
			self::export_transient_key( $token ),
			array(
				'path'        => $path,
				'ids'         => $ids,
				'max_answers' => $max,
				'filename'    => $filename,
				'user_id'     => get_current_user_id(),
			),
			self::TRANSIENT_TTL
		);

		return array(
			'token' => $token,
			'total' => count( $ids ),
		);
	}

	/**
	 * Append one chunk of rows to the export temp file.
	 *
	 * @param string $token  Job token.
	 * @param int    $offset Row offset into the id list.
	 * @return array|\WP_Error
	 */
	public static function export_chunk( $token, $offset ) {
		$key = self::export_transient_key( $token );
		$job = get_transient( $key );
		if ( ! is_array( $job ) || empty( $job['path'] ) || (int) ( $job['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'saw_export_expired', __( 'Export expired. Please try again.', 'nera-strikeawin' ) );
		}
		$ids    = array_map( 'intval', (array) $job['ids'] );
		$total  = count( $ids );
		$offset = max( 0, (int) $offset );
		$max    = max( 2, (int) $job['max_answers'] );
		$slice  = array_slice( $ids, $offset, self::CHUNK );

		$out = fopen( $job['path'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			return new WP_Error( 'saw_export_write', __( 'Could not write the export file.', 'nera-strikeawin' ) );
		}
		foreach ( $slice as $id ) {
			fputcsv( $out, self::export_row( (int) $id, $max ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$next = $offset + count( $slice );
		$done = $next >= $total;

		return array(
			'offset'   => $next,
			'total'    => $total,
			'done'     => $done,
			'progress' => array(
				'current' => min( $next, $total ),
				'total'   => $total,
			),
			'download' => $done ? self::export_download_url( $token ) : '',
		);
	}

	/**
	 * Stream a finished export temp file to the browser, then delete it.
	 *
	 * @param string $token Job token.
	 */
	public static function stream_export_file( $token ) {
		$key = self::export_transient_key( $token );
		$job = get_transient( $key );
		if ( ! is_array( $job ) || empty( $job['path'] ) || (int) ( $job['user_id'] ?? 0 ) !== get_current_user_id() ) {
			wp_die( esc_html__( 'Export file not found or expired.', 'nera-strikeawin' ), 404 );
		}
		$path     = (string) $job['path'];
		$filename = ! empty( $job['filename'] ) ? (string) $job['filename'] : 'saw-questions.csv';
		if ( ! is_readable( $path ) ) {
			delete_transient( $key );
			wp_die( esc_html__( 'Export file not found or expired.', 'nera-strikeawin' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $path );
		delete_transient( $key );
		exit;
	}

	/**
	 * @param string $token Token.
	 * @return string
	 */
	public static function export_transient_key( $token ) {
		return 'nera_saw_export_' . get_current_user_id() . '_' . sanitize_key( $token );
	}

	/**
	 * @param string $token Token.
	 * @return string Raw URL suitable for JS (not HTML-escaped).
	 */
	public static function export_download_url( $token ) {
		return add_query_arg(
			array(
				'post_type'       => Nera_SAW_Question_CPT::POST_TYPE,
				'saw_export_file' => sanitize_key( $token ),
				'_wpnonce'        => wp_create_nonce( 'nera_saw_question_import' ),
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * One CSV data row for a question.
	 *
	 * @param int $id         Post ID.
	 * @param int $max_answers Column count for answer_N.
	 * @return array
	 */
	private static function export_row( $id, $max_answers ) {
		$post    = get_post( $id );
		$answers = Nera_SAW_Question_CPT::get_answers( $id );
		$level   = Nera_SAW_Constants::level( (string) get_post_meta( $id, Nera_SAW_Question_CPT::META_LEVEL, true ) );
		$cats    = array();
		if ( taxonomy_exists( 'product_cat' ) ) {
			$terms = get_the_terms( $id, 'product_cat' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $t ) {
					$cats[] = $t->name;
				}
			}
		}
		$correct = '';
		foreach ( $answers as $i => $a ) {
			if ( ! empty( $a['correct'] ) ) {
				$correct = (string) ( $i + 1 );
				break;
			}
		}
		$line = array(
			$id,
			$post ? $post->post_content : '',
			$level ? $level['label'] : '',
			implode( '|', $cats ),
		);
		for ( $n = 1; $n <= $max_answers; $n++ ) {
			$line[] = isset( $answers[ $n - 1 ] ) ? $answers[ $n - 1 ]['text'] : '';
		}
		$line[] = $correct;
		return $line;
	}

	/**
	 * Stream a CSV export for the current list filters / search (legacy one-shot).
	 *
	 * @param array $query_args WP_Query-style args from the list screen.
	 */
	public static function stream_export( array $query_args = array() ) {
		$started = self::start_export( $query_args );
		if ( is_wp_error( $started ) ) {
			wp_die( esc_html( $started->get_error_message() ) );
		}
		$token  = $started['token'];
		$offset = 0;
		do {
			$chunk = self::export_chunk( $token, $offset );
			if ( is_wp_error( $chunk ) ) {
				wp_die( esc_html( $chunk->get_error_message() ) );
			}
			$offset = (int) $chunk['offset'];
		} while ( empty( $chunk['done'] ) );
		self::stream_export_file( $token );
	}

	/**
	 * Build a live example CSV: 5 update rows (real ids) + 5 create rows (blank id).
	 * Same column format as a real export (dynamic answer_N width).
	 */
	public static function stream_example() {
		$ladder = Nera_SAW_Constants::ladder();
		$ids    = get_posts(
			array(
				'post_type'      => Nera_SAW_Question_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => self::EXAMPLE_UPDATE,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		$ids = array_map( 'intval', (array) $ids );

		$max_answers = 2;
		foreach ( $ids as $id ) {
			$max_answers = max( $max_answers, count( Nera_SAW_Question_CPT::get_answers( (int) $id ) ) );
		}
		$max_answers = max( 4, $max_answers );

		$filename = 'saw-questions-example.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$header = array( 'id', 'question', 'level', 'category' );
		for ( $n = 1; $n <= $max_answers; $n++ ) {
			$header[] = 'answer_' . $n;
		}
		$header[] = 'correct';
		fputcsv( $out, $header );

		$fallback_level = ! empty( $ladder[0]['label'] ) ? $ladder[0]['label'] : 'Easy';
		$fallback_cat   = self::first_category_name();

		foreach ( $ids as $id ) {
			fputcsv( $out, self::export_row( (int) $id, $max_answers ) );
		}

		for ( $i = 1; $i <= self::EXAMPLE_CREATE; $i++ ) {
			$line = array(
				'',
				sprintf(
					/* translators: %d: example row number */
					__( 'EXAMPLE — Sample new question %d (import creates this)', 'nera-strikeawin' ),
					$i
				),
				$fallback_level,
				$fallback_cat,
			);
			$answers = array( 'Option A', 'Option B', 'Option C', 'Option D' );
			for ( $n = 1; $n <= $max_answers; $n++ ) {
				$line[] = isset( $answers[ $n - 1 ] ) ? $answers[ $n - 1 ] : '';
			}
			$line[] = '2';
			fputcsv( $out, $line );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Resolve one CSV row.
	 *
	 * @param array $row          Raw row.
	 * @param array $level_map    lower(label) => key.
	 * @param array $cat_map      lower(name) => term_id.
	 * @param array $bank_dupes   lower(text) => post_id.
	 * @param array $file_texts   lower(text) => line (by ref).
	 * @return array
	 */
	private static function resolve_row( array $row, array $level_map, array $cat_map, array $bank_dupes, array &$file_texts ) {
		$line    = (int) $row['_line'];
		$errors  = array();
		$warns   = array();
		$id_raw  = trim( (string) $row['id'] );
		$action  = ( '' === $id_raw ) ? 'create' : 'update';
		$post_id = 0;
		$existing = null;

		if ( 'update' === $action ) {
			$post_id = self::parse_positive_int( $id_raw );
			if ( $post_id < 1 ) {
				$errors[] = __( 'id must be a positive integer.', 'nera-strikeawin' );
			} else {
				$existing = get_post( $post_id );
				if ( ! $existing || Nera_SAW_Question_CPT::POST_TYPE !== $existing->post_type ) {
					$errors[] = sprintf(
						/* translators: %d: post ID */
						__( 'id %d is not a Question.', 'nera-strikeawin' ),
						$post_id
					);
					$existing = null;
					$post_id  = 0;
				}
			}
		}

		$question = (string) $row['question'];
		if ( 'create' === $action && '' === $question ) {
			$errors[] = __( 'question is required for new rows.', 'nera-strikeawin' );
		}

		$level_key   = null;
		$level_label = '';
		$level_raw   = trim( (string) $row['level'] );
		if ( '' !== $level_raw ) {
			$lk = strtolower( $level_raw );
			if ( isset( $level_map[ $lk ] ) ) {
				$level_key   = $level_map[ $lk ]['key'];
				$level_label = $level_map[ $lk ]['label'];
			} else {
				$valid = array();
				foreach ( $level_map as $info ) {
					$valid[ $info['label'] ] = true;
				}
				$errors[] = sprintf(
					/* translators: 1: supplied level, 2: valid labels */
					__( 'Unknown level "%1$s". Valid: %2$s', 'nera-strikeawin' ),
					$level_raw,
					implode( ', ', array_keys( $valid ) )
				);
			}
		} elseif ( 'create' === $action ) {
			$errors[] = __( 'level is required for new rows.', 'nera-strikeawin' );
		}

		$cat_ids    = array();
		$cat_labels = array();
		$cat_raw    = trim( (string) $row['category'] );
		if ( '' !== $cat_raw ) {
			$parts = array_filter( array_map( 'trim', explode( '|', $cat_raw ) ) );
			foreach ( $parts as $name ) {
				$ck = strtolower( $name );
				if ( ! isset( $cat_map[ $ck ] ) ) {
					$errors[] = sprintf(
						/* translators: %s: category name */
						__( 'Unknown category "%s". Categories are never auto-created.', 'nera-strikeawin' ),
						$name
					);
					continue;
				}
				$cat_ids[]    = (int) $cat_map[ $ck ];
				$cat_labels[] = $name;
			}
		} else {
			$warns[] = __( 'Blank category — this question will not be drawn by competitions that have categories.', 'nera-strikeawin' );
		}

		$answers_supplied = false;
		$answer_texts     = array();
		foreach ( (array) $row['answers'] as $n => $text ) {
			$text = trim( (string) $text );
			if ( '' !== $text ) {
				$answers_supplied = true;
				$answer_texts[ (int) $n ] = $text;
			}
		}
		$correct_raw = trim( (string) $row['correct'] );
		$answers_out = array();
		$correct_text = '';

		if ( $answers_supplied || '' !== $correct_raw ) {
			if ( ! $answers_supplied ) {
				$errors[] = __( 'correct was set but no answer_N values were supplied.', 'nera-strikeawin' );
			} else {
				if ( count( $answer_texts ) < 2 ) {
					$errors[] = __( 'At least two non-empty answers are required.', 'nera-strikeawin' );
				}
				if ( '' === $correct_raw ) {
					$errors[] = __( 'correct is required when answers are supplied.', 'nera-strikeawin' );
				} else {
					$correct_n = self::parse_positive_int( $correct_raw );
					if ( $correct_n < 1 ) {
						$errors[] = __( 'correct must be the answer number (e.g. 2 for answer_2).', 'nera-strikeawin' );
					} elseif ( ! isset( $answer_texts[ $correct_n ] ) ) {
						$errors[] = sprintf(
							/* translators: %d: correct answer number */
							__( 'correct=%d but answer_%d is empty or missing.', 'nera-strikeawin' ),
							$correct_n,
							$correct_n
						);
					} else {
						$correct_text = $answer_texts[ $correct_n ];
						ksort( $answer_texts, SORT_NUMERIC );
						foreach ( $answer_texts as $n => $text ) {
							$answers_out[] = array(
								'text'    => $text,
								'correct' => ( (int) $n === $correct_n ),
							);
						}
					}
				}
			}
		} elseif ( 'create' === $action ) {
			$errors[] = __( 'Answers (answer_1…) and correct are required for new rows.', 'nera-strikeawin' );
		}

		// Duplicate question text vs other Questions (or earlier rows) → reject.
		// Updating a Question with its own text is allowed.
		$norm_q = self::normalise_text( $question );
		if ( '' !== $norm_q ) {
			if ( isset( $file_texts[ $norm_q ] ) ) {
				$errors[] = sprintf(
					/* translators: %d: earlier CSV line */
					__( 'Duplicate question text within this file (also on line %d).', 'nera-strikeawin' ),
					(int) $file_texts[ $norm_q ]
				);
			} else {
				$file_texts[ $norm_q ] = $line;
			}
			if ( isset( $bank_dupes[ $norm_q ] ) && (int) $bank_dupes[ $norm_q ] !== $post_id ) {
				$errors[] = sprintf(
					/* translators: %d: existing question ID */
					__( 'Duplicate of existing question #%d.', 'nera-strikeawin' ),
					(int) $bank_dupes[ $norm_q ]
				);
			}
		}

		// Resolved display values for preview (fill from existing on blank update fields).
		$display_question = $question;
		$display_level    = $level_label;
		$display_cats     = $cat_labels;
		$display_answers  = $answers_out;
		$display_correct  = $correct_text;

		if ( $existing ) {
			if ( '' === $display_question ) {
				$display_question = $existing->post_content;
			}
			if ( null === $level_key ) {
				$lv = Nera_SAW_Constants::level( (string) get_post_meta( $post_id, Nera_SAW_Question_CPT::META_LEVEL, true ) );
				$display_level = $lv ? $lv['label'] : '';
			}
			if ( '' === $cat_raw ) {
				$terms = taxonomy_exists( 'product_cat' ) ? get_the_terms( $post_id, 'product_cat' ) : array();
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $t ) {
						$display_cats[] = $t->name;
					}
				}
			}
			if ( empty( $display_answers ) ) {
				$display_answers = Nera_SAW_Question_CPT::get_answers( $post_id );
				foreach ( $display_answers as $a ) {
					if ( ! empty( $a['correct'] ) ) {
						$display_correct = $a['text'];
						break;
					}
				}
			}
		}

		return array(
			'action'          => $action,
			'id'              => $post_id,
			'_line'           => $line,
			'question'        => $question,
			'level_key'       => $level_key,
			'level_supplied'  => ( '' !== $level_raw ),
			'category_ids'    => $cat_ids,
			'category_supplied' => ( '' !== $cat_raw ),
			'answers'         => $answers_out,
			'answers_supplied'=> $answers_supplied,
			'errors'          => $errors,
			'warnings'        => $warns,
			'display'         => array(
				'question' => $display_question,
				'level'    => $display_level,
				'category' => implode( ' | ', $display_cats ),
				'answers'  => $display_answers,
				'correct'  => $display_correct,
			),
		);
	}

	/**
	 * Persist one resolved row.
	 *
	 * @param array  $row        Resolved row.
	 * @param string $new_status Status for clean creates (warned rows force draft).
	 * @param string $batch_id   Import batch id.
	 * @return array|\WP_Error
	 */
	private static function apply_row( array $row, $new_status, $batch_id ) {
		// Warned rows are always draft (quiz-draw uses publish only) — ADR 0017.
		$force_draft = ! empty( $row['warnings'] );
		$status      = $force_draft ? 'draft' : $new_status;

		if ( 'create' === $row['action'] ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => Nera_SAW_Question_CPT::POST_TYPE,
					'post_status'  => $status,
					'post_title'   => (string) $row['question'],
					'post_content' => (string) $row['question'],
				),
				true
			);
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return is_wp_error( $post_id ) ? $post_id : new WP_Error( 'saw_import_create', __( 'Failed to create question.', 'nera-strikeawin' ) );
			}
			update_post_meta( $post_id, Nera_SAW_Question_CPT::META_ANSWERS, $row['answers'] );
			Nera_SAW_Question_CPT::sync_answers_text_mirror( $post_id, $row['answers'] );
			update_post_meta( $post_id, Nera_SAW_Question_CPT::META_LEVEL, sanitize_key( (string) $row['level_key'] ) );
			update_post_meta( $post_id, Nera_SAW_Question_CPT::META_IMPORT, $batch_id );
			if ( ! empty( $row['category_ids'] ) && taxonomy_exists( 'product_cat' ) ) {
				wp_set_object_terms( $post_id, array_map( 'intval', $row['category_ids'] ), 'product_cat', false );
			}
			return array( 'action' => 'create', 'id' => (int) $post_id );
		}

		$post_id = (int) $row['id'];
		$update  = array( 'ID' => $post_id );
		if ( '' !== (string) $row['question'] ) {
			$update['post_title']   = (string) $row['question'];
			$update['post_content'] = (string) $row['question'];
		}
		if ( $force_draft ) {
			$update['post_status'] = 'draft';
		}
		if ( count( $update ) > 1 ) {
			$r = wp_update_post( $update, true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		if ( ! empty( $row['answers_supplied'] ) ) {
			update_post_meta( $post_id, Nera_SAW_Question_CPT::META_ANSWERS, $row['answers'] );
			Nera_SAW_Question_CPT::sync_answers_text_mirror( $post_id, $row['answers'] );
		}
		if ( ! empty( $row['level_supplied'] ) && $row['level_key'] ) {
			update_post_meta( $post_id, Nera_SAW_Question_CPT::META_LEVEL, sanitize_key( (string) $row['level_key'] ) );
		}
		if ( ! empty( $row['category_supplied'] ) && taxonomy_exists( 'product_cat' ) ) {
			wp_set_object_terms( $post_id, array_map( 'intval', $row['category_ids'] ), 'product_cat', false );
		}
		update_post_meta( $post_id, Nera_SAW_Question_CPT::META_IMPORT, $batch_id );
		return array( 'action' => 'update', 'id' => $post_id );
	}

	/**
	 * lower(label) => { key, label }.
	 *
	 * @return array
	 */
	private static function level_label_map() {
		$map = array();
		foreach ( Nera_SAW_Constants::ladder() as $lv ) {
			$map[ strtolower( trim( $lv['label'] ) ) ] = array(
				'key'   => $lv['key'],
				'label' => $lv['label'],
			);
		}
		return $map;
	}

	/**
	 * lower(name) => term_id for product_cat.
	 *
	 * @return array
	 */
	private static function category_name_map() {
		$map = array();
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return $map;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $map;
		}
		foreach ( $terms as $t ) {
			$map[ strtolower( trim( $t->name ) ) ] = (int) $t->term_id;
		}
		return $map;
	}

	/**
	 * lower(question text) => post_id for duplicate warnings.
	 *
	 * @return array
	 */
	private static function existing_question_texts() {
		global $wpdb;
		$pt   = Nera_SAW_Question_CPT::POST_TYPE;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')",
				$pt
			)
		);
		$map = array();
		foreach ( (array) $rows as $r ) {
			$n = self::normalise_text( $r->post_content );
			if ( '' !== $n && ! isset( $map[ $n ] ) ) {
				$map[ $n ] = (int) $r->ID;
			}
		}
		return $map;
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalise_text( $text ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $text ) ) );
	}

	/**
	 * Parse a CSV id/correct cell as a positive int (tolerates Excel "12.0").
	 *
	 * @param string $raw Raw cell.
	 * @return int Positive int, or 0 if empty/invalid.
	 */
	private static function parse_positive_int( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return 0;
		}
		if ( ctype_digit( $raw ) ) {
			return (int) $raw;
		}
		if ( preg_match( '/^(\d+)\.0+$/', $raw, $m ) ) {
			return (int) $m[1];
		}
		if ( is_numeric( $raw ) ) {
			$n = (float) $raw;
			if ( $n > 0 && floor( $n ) === $n ) {
				return (int) $n;
			}
		}
		return 0;
	}

	/**
	 * @param string $header Header cell.
	 * @return string
	 */
	private static function normalise_header( $header ) {
		$h = strtolower( trim( $header ) );
		$h = preg_replace( '/[\s\-]+/', '_', $h );
		return $h;
	}

	/**
	 * Detect comma vs semicolon delimiter from the first line.
	 *
	 * @param string $raw File contents.
	 * @return string
	 */
	private static function detect_delimiter( $raw ) {
		$first = strtok( $raw, "\r\n" );
		if ( false === $first ) {
			return ',';
		}
		$commas     = substr_count( $first, ',' );
		$semicolons = substr_count( $first, ';' );
		return ( $semicolons > $commas ) ? ';' : ',';
	}

	/**
	 * @return string
	 */
	private static function new_batch_id() {
		return 'imp_' . gmdate( 'Ymd_His' ) . '_' . get_current_user_id() . '_' . wp_generate_password( 6, false, false );
	}

	/**
	 * @return string
	 */
	private static function first_category_name() {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return '';
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1,
			)
		);
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		return $terms[0]->name;
	}
}
