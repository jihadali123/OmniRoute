<?php
/**
 * Database schema.
 *
 * Custom tables keep job rows and clip rows out of `wp_posts` — a 4K render
 * queue should not pollute content queries. All access goes through
 * $wpdb->prepare() (or the whitelist-based writer helpers below), never through
 * string concatenation.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_DB
 */
final class VVAI_DB {

	const DB_VERSION = '1.0.0';

	/**
	 * Jobs table name (with prefix).
	 *
	 * @return string
	 */
	public static function jobs_table() {
		global $wpdb;

		return ( isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_' ) . VVAI_TABLE_PREFIX . 'jobs';
	}

	/**
	 * Clips table name (with prefix).
	 *
	 * @return string
	 */
	public static function clips_table() {
		global $wpdb;

		return ( isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_' ) . VVAI_TABLE_PREFIX . 'clips';
	}

	/**
	 * Chunked-upload session table.
	 *
	 * @return string
	 */
	public static function uploads_table() {
		global $wpdb;

		return ( isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_' ) . VVAI_TABLE_PREFIX . 'uploads';
	}

	/**
	 * Create/upgrade the tables.
	 *
	 * @param bool $verbose Whether to collect errors.
	 * @return array{ok:bool,errors:string[],tables:string[]}
	 */
	public static function install( $verbose = false ) {
		global $wpdb;

		$errors = array();
		$tables = array();

		// dbDelta lives in wp-admin; it is not always loadable (CLI, front-channel
		// requests, unusual ABSPATH). Fall back to raw DDL instead of fataling.
		$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';

		if ( is_readable( $upgrade ) ) {
			require_once $upgrade; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- core file path.
		}

		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$jobs    = self::jobs_table();
		$clips   = self::clips_table();
		$uploads = self::uploads_table();

		$jobs_sql = "CREATE TABLE {$jobs} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(24) NOT NULL DEFAULT 'queued',
			stage VARCHAR(48) NOT NULL DEFAULT 'queued',
			progress TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			source_type VARCHAR(16) NOT NULL DEFAULT 'upload',
			source_path VARCHAR(255) NOT NULL DEFAULT '',
			source_url VARCHAR(600) NOT NULL DEFAULT '',
			source_hash CHAR(64) NOT NULL DEFAULT '',
			file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			duration DECIMAL(12,3) NOT NULL DEFAULT 0,
			width INT(11) UNSIGNED NOT NULL DEFAULT 0,
			height INT(11) UNSIGNED NOT NULL DEFAULT 0,
			fps DECIMAL(8,3) NOT NULL DEFAULT 0,
			vcodec VARCHAR(32) NOT NULL DEFAULT '',
			acodec VARCHAR(32) NOT NULL DEFAULT '',
			has_audio TINYINT(1) NOT NULL DEFAULT 0,
			rotation SMALLINT(6) NOT NULL DEFAULT 0,
			settings LONGTEXT NULL,
			transcript LONGTEXT NULL,
			ai_response LONGTEXT NULL,
			clips LONGTEXT NULL,
			connection_id VARCHAR(64) NOT NULL DEFAULT '',
			fallback_connection_id VARCHAR(64) NOT NULL DEFAULT '',
			clip_count SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			rendered_count SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			error_code VARCHAR(48) NOT NULL DEFAULT '',
			error_message TEXT NULL,
			error_stage VARCHAR(48) NOT NULL DEFAULT '',
			retry_from VARCHAR(48) NOT NULL DEFAULT '',
			attempts SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			lock_token CHAR(32) NULL,
			lock_expires DATETIME NULL,
			public TINYINT(1) NOT NULL DEFAULT 0,
			retention_days SMALLINT(5) NOT NULL DEFAULT 14,
			cleanup_after DATETIME NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status),
			KEY author_status (author_id, status),
			KEY cleanup (cleanup_after),
			KEY lock_expires (lock_expires),
			KEY created (created_at)
		) {$charset};";

		$clips_sql = "CREATE TABLE {$clips} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			clip_index SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(24) NOT NULL DEFAULT 'rendered',
			title VARCHAR(191) NOT NULL DEFAULT '',
			caption TEXT NULL,
			hashtags TEXT NULL,
			viral_score TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			reasoning TEXT NULL,
			start_time DECIMAL(12,3) NOT NULL DEFAULT 0,
			end_time DECIMAL(12,3) NOT NULL DEFAULT 0,
			duration DECIMAL(12,3) NOT NULL DEFAULT 0,
			file_path VARCHAR(255) NOT NULL DEFAULT '',
			file_name VARCHAR(191) NOT NULL DEFAULT '',
			srt_path VARCHAR(255) NOT NULL DEFAULT '',
			file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			width INT(11) UNSIGNED NOT NULL DEFAULT 0,
			height INT(11) UNSIGNED NOT NULL DEFAULT 0,
			aspect_ratio VARCHAR(8) NOT NULL DEFAULT '9:16',
			quality VARCHAR(8) NOT NULL DEFAULT '1080p',
			crop_mode VARCHAR(16) NOT NULL DEFAULT 'center',
			render_seconds DECIMAL(10,3) NOT NULL DEFAULT 0,
			download_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
			metrics LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY author_id (author_id),
			KEY score (viral_score),
			KEY job_index (job_id, clip_index)
		) {$charset};";

		$uploads_sql = "CREATE TABLE {$uploads} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			handle CHAR(32) NOT NULL DEFAULT '',
			author_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			file_name VARCHAR(191) NOT NULL DEFAULT '',
			mime_type VARCHAR(80) NOT NULL DEFAULT '',
			chunk_size INT(10) UNSIGNED NOT NULL DEFAULT 0,
			chunk_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
			received_chunks LONGTEXT NULL,
			bytes_written BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			total_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'uploading',
			target_path VARCHAR(255) NOT NULL DEFAULT '',
			error_message TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			expires_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY handle (handle),
			KEY status (status),
			KEY expires (expires_at)
		) {$charset};";

		/**
		 * Filter the raw DBDelta schema before it runs.
		 *
		 * @param array $schema Map of table name => CREATE statement.
		 */
		$schema = apply_filters(
			'vvai_db_schema',
			array(
				$jobs    => $jobs_sql,
				$clips   => $clips_sql,
				$uploads => $uploads_sql,
			)
		);

		foreach ( $schema as $table_name => $sql ) {
			if ( function_exists( 'dbDelta' ) ) {
				$result = dbDelta( $sql );
			} else {
				// Straight DDL: works on first install, and simply errors on a
				// table that already exists (which the probe below tolerates).
				$result = ( false !== $wpdb->query( $sql ) ) ? array( $table_name ) : array();
			}

			if ( is_array( $result ) && $result ) {
				$tables = array_merge( $tables, array_values( $result ) );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- existence probe during install.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

			if ( $exists !== $table_name ) {
				$errors[] = sprintf(
					/* translators: %s: table name. */
					__( 'Could not create the %s table.', 'viral-video-ai' ),
					$table_name
				);

				if ( $verbose && ! empty( $wpdb->last_error ) ) {
					$errors[] = $wpdb->last_error;
				}
			}
		}

		update_option( 'vvai_db_version', self::DB_VERSION, false );

		return array(
			'ok'    => empty( $errors ),
			'errors' => $errors,
			'tables' => $tables,
		);
	}

	/**
	 * Drop the plugin tables (uninstall only).
	 */
	public static function uninstall() {
		global $wpdb;

		foreach ( array( self::clips_table(), self::jobs_table(), self::uploads_table() ) as $table ) {
			// Table names come from the class above, never from user input.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- DDL cannot be prepared.
		}

		delete_option( 'vvai_db_version' );
	}

	/**
	 * Are the custom tables present?
	 *
	 * @return bool
	 */
	public static function is_installed() {
		global $wpdb;

		$schema_version = get_option( 'vvai_db_version', '' );
		$stored         = get_option( VVAI_Settings::OPTION_KEY, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cheap probe used by diagnostics.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::jobs_table() ) );

		return ( $exists === self::jobs_table() )
			&& version_compare( (string) $schema_version, self::DB_VERSION, '>=' )
			&& false !== $stored;
	 }

	/**
	 * Whitelisted column check used before building an ORDER BY clause.
	 *
	 * @param string $column Requested column.
	 * @param array  $allowed Allowed columns.
	 * @param string $fallback Default column.
	 * @return string
	 */
	public static function safe_column( $column, array $allowed, $fallback ) {
		$column = strtolower( trim( (string) $column ) );

		return in_array( $column, $allowed, true ) ? $column : $fallback;
	}

	/**
	 * Safe ORDER direction.
	 *
	 * @param string $direction Requested direction.
	 * @return string
	 */
	public static function safe_order( $direction ) {
		return 'asc' === strtolower( trim( (string) $direction ) ) ? 'ASC' : 'DESC';
	}
}
