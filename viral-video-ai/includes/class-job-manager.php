<?php
/**
 * Job repository.
 *
 * Owns the `{prefix}vvai_jobs` table: creation, the status machine, progress,
 * pessimistic locking (so two workers can never render the same job) and the
 * retention bookkeeping.
 *
 * All writes go through sanitize_column-filtered insert/update helpers or
 * $wpdb->prepare(); no caller-supplied string is ever concatenated into SQL.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Job_Manager
 */
class VVAI_Job_Manager {

	/**
	 * Column => sanitizer map. Anything not listed cannot be written.
	 *
	 * @var array<string,string>
	 */
	private static $columns = array(
		'id'                     => 'int',
		'author_id'              => 'int',
		'title'                  => 'text',
		'status'                 => 'key',
		'stage'                  => 'key',
		'progress'               => 'int',
		'source_type'            => 'key',
		'source_path'            => 'path',
		'source_url'             => 'url',
		'source_hash'            => 'hex',
		'file_size'              => 'int',
		'duration'               => 'float',
		'width'                  => 'int',
		'height'                 => 'int',
		'fps'                    => 'float',
		'vcodec'                 => 'text',
		'acodec'                 => 'text',
		'has_audio'              => 'bool',
		'rotation'               => 'int',
		'settings'               => 'json',
		'transcript'             => 'json',
		'ai_response'            => 'longtext',
		'clips'                  => 'json',
		'connection_id'          => 'key',
		'fallback_connection_id' => 'key',
		'clip_count'             => 'int',
		'rendered_count'         => 'int',
		'error_code'             => 'key',
		'error_message'          => 'longtext',
		'error_stage'            => 'key',
		'retry_from'             => 'key',
		'attempts'               => 'int',
		'lock_token'             => 'key',
		'lock_expires'           => 'datetime',
		'public'                 => 'bool',
		'retention_days'         => 'int',
		'cleanup_after'          => 'datetime',
		'started_at'             => 'datetime',
		'finished_at'            => 'datetime',
		'created_at'             => 'datetime',
		'updated_at'             => 'datetime',
	);

	/**
	 * Table name.
	 *
	 * @return string
	 */
	protected function table() {
		return VVAI_DB::jobs_table();
	}

	/**
	 * Column definitions (public for the admin list table + tests).
	 *
	 * @return array<string,string>
	 */
	public static function columns() {
		return self::$columns;
	}

	/**
	 * Create a job.
	 *
	 * @param array<string,mixed> $args Job fields.
	 * @return int|WP_Error Insert id or error.
	 */
	public function create( array $args ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'author_id'      => isset( $args['author_id'] ) ? (int) $args['author_id'] : get_current_user_id(),
			'title'          => vvai_array_get( $args, 'title', __( 'Untitled video', 'viral-video-ai' ) ),
			'status'         => VVAI_Job_Status::QUEUED,
			'stage'          => VVAI_Job_Status::QUEUED,
			'progress'       => 0,
			'source_type'    => in_array( (string) vvai_array_get( $args, 'source_type', 'upload' ), array( 'upload', 'url', 'media' ), true ) ? (string) vvai_array_get( $args, 'source_type', 'upload' ) : 'upload',
			'source_path'    => (string) vvai_array_get( $args, 'source_path', '' ),
			'source_url'     => (string) vvai_array_get( $args, 'source_url', '' ),
			'source_hash'    => (string) vvai_array_get( $args, 'source_hash', '' ),
			'file_size'      => (int) vvai_array_get( $args, 'file_size', 0 ),
			'settings'       => wp_json_encode( $this->normalize_settings( (array) vvai_array_get( $args, 'settings', array() ) ) ),
			'connection_id'  => (string) vvai_array_get( $args, 'connection_id', '' ),
			'retention_days' => (int) vvai_array_get( $args, 'retention_days', 14 ),
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		if ( ! empty( $args['public'] ) ) {
			$row['public'] = 1;
		}

		$inserted = $wpdb->insert( $this->table(), $this->sanitize_all( $row ), array( $this->format_for( $row ) ) );

		if ( false === $inserted ) {
			return new WP_Error( 'job_insert_failed', __( 'The processing job could not be created. The database may be unavailable.', 'viral-video-ai' ), array( 'db_error' => $this->db_error() ) );
		}

		$id = (int) $wpdb->insert_id;

		if ( $id <= 0 ) {
			return new WP_Error( 'job_insert_failed', __( 'The processing job could not be created.', 'viral-video-ai' ) );
		}

		$this->touch_retention( $id );

		/**
		 * Fires after a job row is created.
		 *
		 * @param int   $id   Job id.
		 * @param array $row    Stored row.
		 */
		do_action( 'vvai_job_created', $id, $row );

		return $id;
	}

	/**
	 * Fetch a job.
	 *
	 * @param int $id Job id.
	 * @return array<string,mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;

		$id = (int) $id;

		if ( $id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from a whitelisted table name + prepared placeholder.
			$wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ),
			'ARRAY_A'
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Update fields.
	 *
	 * @param int                 $id     Job id.
	 * @param array<string,mixed> $fields Fields to write.
	 * @return bool
	 */
	public function update( $id, array $fields ) {
		global $wpdb;

		$id    = (int) $id;
		$write = array();

		foreach ( $fields as $key => $value ) {
			if ( ! isset( self::$columns[ $key ] ) || 'id' === $key ) {
				continue;
			}

			$write[ $key ] = $value;
		}

		if ( ! $write ) {
			return false;
		}

		$write['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$updated = $wpdb->update( $this->table(), $this->sanitize_all( $write ), array( 'id' => $id ), $this->format_for( $write ), array( '%d' ) );

		return ( false !== $updated );
	}

	/**
	 * Move a job to a stage and (optionally) set progress.
	 *
	 * @param int    $id       Job id.
	 * @param string $stage    Stage key.
	 * @param int    $progress Explicit progress, or null for the stage default.
	 * @return bool
	 */
	public function set_stage( $id, $stage, $progress = null ) {
		if ( ! VVAI_Job_Status::is_stage( $stage ) ) {
			return false;
		}

		$status = ( VVAI_Job_Status::COMPLETED === $stage ) ? VVAI_Job_Status::COMPLETED : $stage;

		$fields = array(
			'status'   => $status,
			'stage'    => $stage,
			'progress' => ( null === $progress ) ? VVAI_Job_Status::progress_for( $stage ) : max( 0, min( 99, (int) $progress ) ),
		);

		if ( VVAI_Job_Status::INSPECTING === $stage ) {
			$fields['started_at'] = gmdate( 'Y-m-d H:i:s' );
		}

		if ( VVAI_Job_Status::COMPLETED === $stage ) {
			$fields['progress']     = 100;
			$fields['finished_at']   = gmdate( 'Y-m-d H:i:s' );
			$fields['error_code']    = '';
			$fields['error_message'] = '';
			$fields['error_stage']   = '';
		}

		// Moving forward means the previous error no longer describes reality.
		$fields['error_code']    = '';
		$fields['error_message'] = '';
		$fields['error_stage']   = '';

		return $this->update( $id, $fields );
	}

	/**
	 * Set progress without changing the stage.
	 *
	 * @param int $id       Job id.
	 * @param int $progress Percent.
	 * @param string $stage Optional stage label override.
	 * @return bool
	 */
	public function set_progress( $id, $progress, $stage = '' ) {
		$fields = array( 'progress' => max( 0, min( 99, (int) $progress ) ) );

		if ( '' !== $stage && VVAI_Job_Status::is_stage( $stage ) ) {
			$fields['stage'] = $stage;
		}

		return $this->update( $id, $fields );
	}

	/**
	 * Mark a job failed.
	 *
	 * @param int    $id      Job id.
	 * @param string $code    Error code.
	 * @param string $message Human message.
	 * @param string $stage   Stage that failed.
	 * @param string $retry_from Retry entry point.
	 * @return bool
	 */
	public function fail( $id, $code, $message, $stage = '', $retry_from = '' ) {
		$job = $this->get( $id );

		return $this->update(
			(int) $id,
			array(
				'status'        => VVAI_Job_Status::FAILED,
				'stage'         => ( '' !== $stage && VVAI_Job_Status::is_stage( $stage ) ) ? $stage : (string) vvai_array_get( (array) $job, 'stage', '' ),
				'progress'      => (int) vvai_array_get( (array) $job, 'progress', 0 ),
				'error_code'    => substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $code ) ), 0, 48 ),
				'error_message' => vvai_sanitize_paragraph( $message, 600 ),
				'error_stage'   => ( '' !== $stage && VVAI_Job_Status::is_stage( $stage ) ) ? $stage : '',
				'retry_from'    => ( '' !== $retry_from && isset( VVAI_Job_Status::retry_targets()[ $retry_from ] ) ) ? $retry_from : '',
				'lock_token'    => '',
				'lock_expires'  => '1970-01-01 00:00:00',
				'finished_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Mark complete.
	 *
	 * @param int $id Job id.
	 * @return bool
	 */
	public function complete( $id ) {
		$result = $this->set_stage( $id, VVAI_Job_Status::COMPLETED, 100 );

		/**
		 * Fires when a job finished successfully.
		 *
		 * @param int $id Job id.
		 */
		do_action( 'vvai_job_completed', (int) $id );

		return $result;
	}

	/**
	 * Store the normalized transcript.
	 *
	 * @param int                          $id       Job id.
	 * @param array<int,array<string,mixed>> $segments Segments.
	 * @return bool
	 */
	public function store_transcript( $id, array $segments ) {
		return $this->update(
			(int) $id,
			array(
				'transcript' => wp_json_encode( array_values( $segments ) ),
			)
		);
	}

	/**
	 * Store the raw (sanitized) AI payload for debugging.
	 *
	 * @param int    $id   Job id.
	 * @param string $raw  Raw text.
	 * @param array  $meta Usage meta.
	 * @return bool
	 */
	public function store_ai_response( $id, $raw, array $meta = array() ) {
		return $this->update(
			(int) $id,
			array(
				'ai_response' => wp_json_encode(
					array(
						'at'        => gmdate( 'Y-m-d H:i:s' ),
						'raw'       => substr( (string) $raw, 0, 60000 ),
						'usage'     => $meta,
					)
				),
			)
		);
	}

	/**
	 * Store the accepted clip plan.
	 *
	 * @param int   $id    Job id.
	 * @param array $clips Clips.
	 * @return bool
	 */
	public function store_clips( $id, array $clips ) {
		return $this->update(
			(int) $id,
			array(
				'clips'      => wp_json_encode( array_values( $clips ) ),
				'clip_count' => count( $clips ),
			)
		);
	}

	/**
	 * Read the decoded clip plan.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return array<int,array<string,mixed>>
	 */
	public function clips_of( array $job ) {
		$clips = vvai_json_decode( vvai_array_get( $job, 'clips', '' ) );

		return is_array( $clips ) ? $clips : array();
	}

	/**
	 * Read the decoded settings.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return array<string,mixed>
	 */
	public function settings_of( array $job ) {
		$settings = vvai_json_decode( vvai_array_get( $job, 'settings', '' ) );
		$settings = is_array( $settings ) ? $settings : array();

		return $this->normalize_settings( $settings );
	}

	/**
	 * Read the decoded transcript.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return array<int,array<string,mixed>>
	 */
	public function transcript_of( array $job ) {
		$transcript = vvai_json_decode( vvai_array_get( $job, 'transcript', '' ) );

		return is_array( $transcript ) ? $transcript : array();
	}

	/**
	 * List jobs with filters.
	 *
	 * @param array<string,mixed> $args {
	 *     @type int    $author_id Filter by user (0 = all).
	 *     @type string $status    Status filter.
	 *     @type int    $per_page  Page size.
	 *     @type int    $page      Page number.
	 *     @type string $order_by  created_at|updated_at|id|status|progress.
	 *     @type string $order     asc|desc.
	 *     @type string $search    Title search.
	 *     @type bool   $active_only Only unfinished jobs.
	 * }
	 * @return array{items:array<int,array<string,mixed>>,total:int,pages:int,page:int}
	 */
	public function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'author_id'   => 0,
				'status'      => '',
				'per_page'    => 20,
				'page'        => 1,
				'order_by'    => 'created_at',
				'order'       => 'desc',
				'search'      => '',
				'active_only' => false,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( (int) $args['author_id'] > 0 ) {
			$where[]  = 'author_id = %d';
			$params[] = (int) $args['author_id'];
		}

		if ( '' !== $args['status'] && VVAI_Job_Status::is_stage( (string) $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}

		if ( ! empty( $args['active_only'] ) ) {
			// Placeholders only, never an interpolated list.
			$active  = VVAI_Job_Status::active_stages();
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $active ), '%s' ) ) . ')';
			$params  = array_merge( $params, $active );
		}

		if ( '' !== (string) $args['search'] ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		$table = $this->table();
		$clause = implode( ' AND ', $where );
		$order  = VVAI_DB::safe_column( (string) $args['order_by'], array( 'created_at', 'updated_at', 'id', 'status', 'progress', 'clip_count' ), 'created_at' );
		$dir    = VVAI_DB::safe_order( (string) $args['order'] );

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		// `SQL_CALC_FOUND_ROWS` is deprecated in MySQL 8, so the total is counted
		// with its own prepared query.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $clause, $params )
		);

		$sql = 'SELECT * FROM ' . $table . ' WHERE ' . $clause . ' ORDER BY ' . $order . ' ' . $dir . ' LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- whitelisted identifiers + prepared values.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), 'ARRAY_A' );

		$items = array();

		foreach ( (array) $rows as $row ) {
			$items[] = $this->hydrate( $row );
		}

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( max( 1, $total ) / $per_page ),
			'page'  => $page,
		);
	}

	/**
	 * Aggregate counters for the dashboard.
	 *
	 * @param int $author_id Restrict to a user (0 = everyone).
	 * @return array<string,int>
	 */
	public function stats( $author_id = 0 ) {
		global $wpdb;

		$table  = $this->table();
		$params = array();
		$filter = '';

		if ( (int) $author_id > 0 ) {
			$filter   = ' WHERE author_id = %d';
			$params[] = (int) $author_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is whitelisted, filter uses a placeholder.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM ' . $table . $filter . ' GROUP BY status', $params ), 'ARRAY_A' );

		$stats = array(
			'total'     => 0,
			'completed' => 0,
			'failed'    => 0,
			'active'    => 0,
			'queued'    => 0,
			'clips'     => 0,
		);

		foreach ( $rows as $row ) {
			$status = (string) vvai_array_get( $row, 'status', '' );
			$count  = (int) vvai_array_get( $row, 'total', 0 );

			$stats['total'] += $count;

			if ( VVAI_Job_Status::COMPLETED === $status ) {
				$stats['completed'] += $count;
			} elseif ( VVAI_Job_Status::FAILED === $status ) {
				$stats['failed'] += $count;
			} elseif ( VVAI_Job_Status::QUEUED === $status ) {
				$stats['queued'] += $count;
			} elseif ( in_array( $status, VVAI_Job_Status::active_stages(), true ) ) {
				$stats['active'] += $count;
			}
		}

		// Generated clips live in their own table; one cheap aggregate.
		if ( (int) $author_id > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$stats['clips'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VVAI_DB::clips_table() . ' WHERE author_id = %d AND file_path <> %s', (int) $author_id, '' ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$stats['clips'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . VVAI_DB::clips_table() . " WHERE file_path <> ''" );
		}

		return $stats;
	}

	/**
	 * Try to take the processing lock for a job.
	 *
	 * Atomic single-statement claim, so two workers racing on the same job
	 * cannot both render it.
	 *
	 * @param int $id  Job id.
	 * @param int $ttl Lock lifetime in seconds.
	 * @return bool
	 */
	public function claim( $id, $ttl = 900 ) {
		global $wpdb;

		$token     = bin2hex( random_bytes( 12 ) );
		$expires   = gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) $ttl ) );
		$now       = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- whitelisted table name.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET lock_token = %s, lock_expires = %s, updated_at = %s WHERE id = %d AND ( lock_token = %s OR lock_token IS NULL OR lock_expires < %s )',
				$token,
				$expires,
				$now,
				(int) $id,
				'',
				$now
			)
		);

		if ( ! $affected ) {
			return false;
		}

		if ( ! isset( $GLOBALS['vvai_lock_token'] ) || ! is_array( $GLOBALS['vvai_lock_token'] ) ) {
			$GLOBALS['vvai_lock_token'] = array();
		}

		$GLOBALS['vvai_lock_token'][ (int) $id ] = $token;

		return true;
	}

	/**
	 * Extend the lock while a stage is still running.
	 *
	 * @param int $id  Job id.
	 * @param int $ttl Seconds.
	 * @return bool
	 */
	public function renew_lock( $id, $ttl = 900 ) {
		global $wpdb;

		$token = $this->lock_token( $id );

		if ( '' === $token ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET lock_expires = %s, updated_at = %s WHERE id = %d AND lock_token = %s',
				gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) $ttl ) ),
				gmdate( 'Y-m-d H:i:s' ),
				(int) $id,
				$token
			)
		);
	}

	/**
	 * Release the lock (idempotent).
	 *
	 * @param int $id Job id.
	 */
	public function release( $id ) {
		global $wpdb;

		unset( $GLOBALS['vvai_lock_token'][ (int) $id ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET lock_token = %s, lock_expires = NULL WHERE id = %d',
				'',
				(int) $id
			)
		);
	}

	/**
	 * Release every lock (deactivation).
	 */
	public function release_all_locks() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'UPDATE ' . $this->table() . " SET lock_token = '', lock_expires = NULL WHERE lock_token <> ''" );

		unset( $GLOBALS['vvai_lock_token'] );
	}

	/**
	 * Current lock token held by this request for a job.
	 *
	 * @param int $id Job id.
	 * @return string
	 */
	public function lock_token( $id ) {
		$id = (int) $id;

		if ( ! isset( $GLOBALS['vvai_lock_token'] ) || ! is_array( $GLOBALS['vvai_lock_token'] ) ) {
			return '';
		}

		return isset( $GLOBALS['vvai_lock_token'][ $id ] ) ? (string) $GLOBALS['vvai_lock_token'][ $id ] : '';
	}

	/**
	 * Jobs whose status says "running" but whose lock expired: abandoned by a
	 * killed PHP process (an OOM or a max_execution_time cut).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function abandoned_jobs() {
		global $wpdb;

		$active = VVAI_Job_Status::active_stages();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE ( lock_token = %s OR lock_token IS NULL OR lock_expires < %s ) AND status IN (' . implode( ',', array_fill( 0, count( $active ), '%s' ) ) . ') ORDER BY id ASC LIMIT 25',
				array_merge( array( '', '', gmdate( 'Y-m-d H:i:s' ) ), $active )
			),
			'ARRAY_A'
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	 }

	/**
	 * Queued/created jobs waiting for the worker, oldest first.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function pending_jobs( $limit = 5 ) {
		global $wpdb;

		$limit = max( 1, min( 25, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . " WHERE status IN (%s, %s) ORDER BY id ASC LIMIT %d",
				array( VVAI_Job_Status::QUEUED, VVAI_Job_Status::UPLOADED, $limit )
			),
			'ARRAY_A'
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Reset a job to a retry entry point.
	 *
	 * @param int    $id   Job id.
	 * @param string $from Key from VVAI_Job_Status::retry_targets().
	 * @return array{ok:bool,stage:string,message:string}
	 */
	public function prepare_retry( $id, $from = '' ) {
		$job = $this->get( $id );

		if ( ! $job ) {
			return array(
				'ok'      => false,
				'stage'   => '',
				'message' => __( 'Job not found.', 'viral-video-ai' ),
			);
		}

		if ( VVAI_Job_Status::FAILED !== (string) $job['status'] && VVAI_Job_Status::CANCELLED !== (string) $job['status'] ) {
			return array(
				'ok'      => false,
				'stage'   => '',
				'message' => __( 'Only failed or cancelled jobs can be retried.', 'viral-video-ai' ),
			);
		}

		$targets = VVAI_Job_Status::retry_targets();
		$from    = (string) $from;

		if ( '' === $from ) {
			// Resume from where it died: the stage recorded on the job, or the
			// stage that failed.
			$from = (string) ( vvai_array_get( $job, 'retry_from', '' ) ?: vvai_array_get( $job, 'error_stage', '' ) );
		}

		$stage = '';

		foreach ( $targets as $key => $candidate ) {
			if ( $key === $from || $candidate === $from ) {
				$stage = $candidate;
				break;
			}
		}

		if ( '' === $stage ) {
			$stage = VVAI_Job_Status::INSPECTING;
		}

		// A retry is pointless without the source, unless we resume after the
		// point where the source was already consumed (transcript exists).
		$source_path    = (string) vvai_array_get( $job, 'source_path', '' );
		$source_missing = ( '' === $source_path || ! is_file( $source_path ) );

		if ( $source_missing ) {
			$has_transcript = ( '' !== (string) vvai_array_get( $job, 'transcript', '' ) && '[]' !== (string) vvai_array_get( $job, 'transcript', '' ) );

			if ( ! $has_transcript ) {
				return array(
					'ok'      => false,
					'stage'   => '',
					'message' => __( 'The source file was removed by the retention policy, so this job cannot be retried. Upload the video again.', 'viral-video-ai' ),
				);
			}

			// Transcript survives, so resuming from analysis onwards is valid.
			if ( in_array( $stage, array( VVAI_Job_Status::INSPECTING, VVAI_Job_Status::EXTRACTING_AUDIO, VVAI_Job_Status::TRANSCRIBING ), true ) ) {
				$stage = VVAI_Job_Status::ANALYZING;
			}
		}

		$this->update(
			(int) $id,
			array(
				'status'        => VVAI_Job_Status::QUEUED,
				'stage'         => $stage,
				'progress'      => VVAI_Job_Status::progress_for( $stage ),
				'error_code'    => '',
				'error_message' => '',
				'error_stage'   => '',
				'retry_from'    => '',
				'attempts'      => (int) vvai_array_get( $job, 'attempts', 0 ) + 1,
				'lock_token'    => '',
				'lock_expires'  => '1970-01-01 00:00:00',
				'finished_at'   => '1970-01-01 00:00:00',
			)
		);

		/**
		 * Fires when a job is re-queued.
		 *
		 * @param int    $id   Job id.
		 * @param string $stage Restart stage.
		 */
		do_action( 'vvai_job_retried', (int) $id, $stage );

		return array(
			'ok'      => true,
			'stage'   => $stage,
			'message' => sprintf(
				/* translators: %s: stage label. */
				__( 'Job re-queued. Processing will resume at: %s', 'viral-video-ai' ),
				VVAI_Job_Status::label( $stage )
			),
		);
	}

	/**
	 * Delete a job row (files are removed by VVAI_Result_Manager).
	 *
	 * @param int $id Job id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$id = (int) $id;

		$wpdb->delete( VVAI_DB::clips_table(), array( 'job_id' => $id ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );

		$this->release( $id );

		return ( false !== $deleted );
	}

	/**
	 * Jobs whose retention window elapsed.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function jobs_due_for_cleanup( $limit = 50 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE cleanup_after IS NOT NULL AND cleanup_after <> %s AND cleanup_after < %s ORDER BY cleanup_after ASC LIMIT %d',
				array( '', gmdate( 'Y-m-d H:i:s' ), max( 1, min( 200, (int) $limit ) ) )
			),
			'ARRAY_A'
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * (Re)compute cleanup_after from the retention policy.
	 *
	 * @param int $id Job id.
	 */
	public function touch_retention( $id ) {
		$job = $this->get( $id );

		if ( ! $job ) {
			return;
		}

		$days = (int) $job['retention_days'];

		if ( $days < 0 ) {
			$days = (int) $this->setting( 'clip_retention_days' );
		}

		$cleanup = ( $days > 0 ) ? gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) ) : '';

		// Zero retention means "keep forever": never schedule cleanup.
		$this->update(
			(int) $id,
			array(
				'retention_days' => max( 0, $days ),
				'cleanup_after'  => $cleanup,
			)
		);
	}

	/**
	 * Settings shortcut (used during retention computation).
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private function setting( $key ) {
		static $settings = null;

		if ( null === $settings ) {
			$stored   = get_option( VVAI_Settings::OPTION_KEY, array() );
			$settings = is_array( $stored ) ? $stored : array();
		}

		return vvai_array_get( $settings, $key, vvai_array_get( VVAI_Settings::defaults(), $key, '' ) );
	}

	/**
	 * Add decoded JSON fields to a raw row.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	protected function hydrate( array $row ) {
		$row['settings_array']  = $this->normalize_settings( (array) vvai_json_decode( vvai_array_get( $row, 'settings', '' ), true ) );
		$row['transcript_array'] = (array) vvai_json_decode( vvai_array_get( $row, 'transcript', '' ), true );
		$row['clips_array']     = (array) vvai_json_decode( vvai_array_get( $row, 'clips', '' ), true );

		unset( $row['lock_token'] );

		return $row;
	}

	/**
	 * Normalize the per-job settings blob.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,mixed>
	 */
	public function normalize_settings( array $settings ) {
		$mode = (string) vvai_array_get( $settings, 'clip_length', 'short' );

		if ( ! in_array( $mode, array( 'short', 'medium', 'long', 'custom' ), true ) ) {
			$mode = 'short';
		}

		$focus = (string) vvai_array_get( $settings, 'focus', 'viral' );

		if ( ! isset( VVAI_Prompt_Builder::focuses()[ $focus ] ) ) {
			$focus = 'viral';
		}

		$aspect = (string) vvai_array_get( $settings, 'aspect_ratio', '9:16' );

		if ( ! in_array( $aspect, array( '9:16', '16:9', '1:1', '4:5' ), true ) ) {
			$aspect = '9:16';
		}

		$quality = (string) vvai_array_get( $settings, 'quality', '1080p' );

		if ( ! in_array( $quality, array( '720p', '1080p', '4k' ), true ) ) {
			$quality = '1080p';
		}

		list( $min_default, $max_default ) = VVAI_Settings::duration_range( $mode, (int) vvai_array_get( $settings, 'min_duration', 0 ), (int) vvai_array_get( $settings, 'max_duration', 0 ) );

		return array(
			'clip_length'     => $mode,
			'min_duration'    => vvai_sanitize_int( vvai_array_get( $settings, 'min_duration', $min_default ), 5, 1800, $min_default ),
			'max_duration'    => vvai_sanitize_int( vvai_array_get( $settings, 'max_duration', $max_default ), 10, 3600, $max_default ),
			'focus'           => $focus,
			'custom_focus'    => vvai_sanitize_text( vvai_array_get( $settings, 'custom_focus', '' ), 300 ),
			'aspect_ratio'    => $aspect,
			'quality'         => $quality,
			'crop_mode'       => in_array( (string) vvai_array_get( $settings, 'crop_mode', 'smart' ), array( 'center', 'smart' ), true ) ? (string) vvai_array_get( $settings, 'crop_mode', 'smart' ) : 'smart',
			'target_clips'    => vvai_sanitize_int( vvai_array_get( $settings, 'target_clips', 5 ), 1, 20, 5 ),
			'burn_captions'   => vvai_sanitize_bool( vvai_array_get( $settings, 'burn_captions', false ) ),
			'generate_srt'    => vvai_sanitize_bool( vvai_array_get( $settings, 'generate_srt', true ) ),
			'connection_id'   => preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) vvai_array_get( $settings, 'connection_id', '' ) ),
			'fallback_id'     => preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) vvai_array_get( $settings, 'fallback_connection_id', '' ) ),
			'title'           => vvai_sanitize_text( vvai_array_get( $settings, 'title', '' ), 100 ),
		);
	}

	/**
	 * Sanitize a row against the column whitelist.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	protected function sanitize_all( array $row ) {
		$clean = array();

		foreach ( $row as $key => $value ) {
			if ( ! isset( self::$columns[ $key ] ) ) {
				continue;
			}

			$clean[ $key ] = $this->sanitize_value( self::$columns[ $key ], $value );
		}

		return $clean;
	}

	/**
	 * Per-type sanitizer.
	 *
	 * @param string $type  Type key.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	protected function sanitize_value( $type, $value ) {
		switch ( $type ) {
			case 'int':
				return (int) $value;
			case 'float':
				return round( (float) $value, 3 );
			case 'bool':
				return vvai_sanitize_bool( $value ) ? 1 : 0;
			case 'key':
				return substr( strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ), 0, 48 );
			case 'hex':
				return substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $value ) ), 0, 64 );
			case 'text':
				return vvai_sanitize_text( $value, 191 );
			case 'paragraph':
				return vvai_sanitize_paragraph( $value, 1000 );
			case 'longtext':
				return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			case 'json':
				return is_string( $value ) ? ( '' !== trim( $value ) ? $value : '[]' ) : wp_json_encode( $value );
			case 'url':
				return substr( (string) esc_url_raw( (string) $value ), 0, 600 );
			case 'path':
				// Stored paths are generated by the plugin, never by the client;
				// normalize and cap the length rather than trying to "sanitize"
				// a filesystem path into something meaningless.
				return substr( wp_normalize_path( (string) $value ), 0, 255 );
			case 'datetime':
				if ( '' === $value || null === $value ) {
					return null;
				}

				$parsed = strtotime( (string) $value );

				return $parsed ? gmdate( 'Y-m-d H:i:s', $parsed ) : null;
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * $wpdb format array matching a row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<int,string>
	 */
	protected function format_for( array $row ) {
		$formats = array();

		foreach ( $row as $key => $unused ) {
			if ( ! isset( self::$columns[ $key ] ) ) {
				continue;
			}

			$type = self::$columns[ $key ];

			$formats[ $key ] = ( 'int' === $type || 'bool' === $type ) ? '%d' : '%s';
		}

		return $formats;
	}

	/**
	 * Last database error, truncated.
	 *
	 * @return string
	 */
	protected function db_error() {
		global $wpdb;

		return substr( (string) ( isset( $wpdb->last_error ) ? $wpdb->last_error : '' ), 0, 300 );
	}
}
