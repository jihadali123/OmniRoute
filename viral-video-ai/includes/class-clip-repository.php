<?php
/**
 * Clip repository.
 *
 * Clips are first-class rows: results grids, downloads, retention and the
 * dashboard counters all read them, so the data is indexed instead of being
 * buried in a job's JSON blob.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Clip_Repository
 */
class VVAI_Clip_Repository {

	/**
	 * Column => sanitizer map.
	 *
	 * @var array<string,string>
	 */
	private static $columns = array(
		'id'              => 'int',
		'job_id'          => 'int',
		'author_id'       => 'int',
		'clip_index'      => 'int',
		'status'          => 'key',
		'title'           => 'text',
		'caption'         => 'paragraph',
		'hashtags'        => 'tags',
		'viral_score'     => 'int',
		'reasoning'       => 'paragraph',
		'start_time'      => 'float',
		'end_time'        => 'float',
		'duration'        => 'float',
		'file_path'       => 'path',
		'file_name'       => 'text',
		'srt_path'        => 'path',
		'file_size'       => 'int',
		'width'           => 'int',
		'height'          => 'int',
		'aspect_ratio'    => 'key',
		'quality'         => 'key',
		'crop_mode'       => 'key',
		'render_seconds'  => 'float',
		'download_count'  => 'int',
		'metrics'         => 'json',
		'created_at'      => 'datetime',
	);

	/**
	 * Table name.
	 *
	 * @return string
	 */
	protected function table() {
		return VVAI_DB::clips_table();
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string,string>
	 */
	public static function columns() {
		return self::$columns;
	}

	/**
	 * Insert or update one clip row.
	 *
	 * @param array<string,mixed> $clip Clip data (must include job_id + clip_index).
	 * @return int|WP_Error Row id.
	 */
	public function save( array $clip ) {
		global $wpdb;

		$job_id     = (int) vvai_array_get( $clip, 'job_id', 0 );
		$clip_index = (int) vvai_array_get( $clip, 'clip_index', 0 );

		if ( $job_id <= 0 || $clip_index < 0 ) {
			return new WP_Error( 'invalid_clip', __( 'A clip needs a job id and a clip index.', 'viral-video-ai' ) );
		}

		$existing = $this->find( $job_id, $clip_index );
		$row      = $this->sanitize_all( $clip );

		$row['job_id']     = $job_id;
		$row['clip_index'] = $clip_index;

		if ( $existing ) {
			unset( $row['created_at'] );

			$updated = $wpdb->update( $this->table(), $row, array( 'id' => (int) $existing['id'] ), $this->format_for( $row ), array( '%d' ) );

			if ( false === $updated ) {
				return new WP_Error( 'clip_update_failed', __( 'The clip record could not be updated.', 'viral-video-ai' ), array( 'db' => $this->db_error() ) );
			}

			return (int) $existing['id'];
		}

		$row['created_at'] = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert( $this->table(), $row, $this->format_for( $row ) );

		if ( false === $inserted ) {
			return new WP_Error( 'clip_insert_failed', __( 'The clip record could not be saved.', 'viral-video-ai' ), array( 'db' => $this->db_error() ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * One clip by job + index.
	 *
	 * @param int $job_id     Job id.
	 * @param int $clip_index Clip index.
	 * @return array<string,mixed>|null
	 */
	public function find( $job_id, $clip_index ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE job_id = %d AND clip_index = %d', (int) $job_id, (int) $clip_index ),
			'ARRAY_A'
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Clip by id.
	 *
	 * @param int $id Clip id.
	 * @return array<string,mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', (int) $id ), 'ARRAY_A' );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * All clips of a job, ordered by score (or chronologically).
	 *
	 * @param int    $job_id Job id.
	 * @param string $order  score|chrono|index.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_job( $job_id, $order = 'score' ) {
		global $wpdb;

		switch ( $order ) {
			case 'chrono':
			case 'index':
				$clause = 'ORDER BY start_time ASC, clip_index ASC';
				break;
			default:
				$clause = 'ORDER BY viral_score DESC, clip_index ASC';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE job_id = %d ' . $clause, (int) $job_id ),
			'ARRAY_A'
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = $this->hydrate( $row );
		}

		return $out;
	}

	/**
	 * Delete one clip row (files handled by VVAI_Result_Manager).
	 *
	 * @param int $id Clip id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( $this->table(), array( 'id' => (int) $id ), array( '%d' ) );

		return ( false !== $deleted );
	}

	/**
	 * Remove every clip row of a job.
	 *
	 * @param int $job_id Job id.
	 * @return int Affected rows.
	 */
	public function delete_for_job( $job_id ) {
		global $wpdb;

		$deleted = $wpdb->delete( $this->table(), array( 'job_id' => (int) $job_id ), array( '%d' ) );

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Count clips that exist on disk for a job.
	 *
	 * @param int $job_id Job id.
	 * @return int
	 */
	public function count_rendered( $job_id ) {
		$count = 0;

		foreach ( $this->for_job( $job_id, 'index' ) as $clip ) {
			if ( '' !== (string) $clip['file_path'] && is_file( (string) $clip['file_path'] ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Bump the download counter.
	 *
	 * @param int $id Clip id.
	 */
	public function record_download( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $this->table() . ' SET download_count = download_count + 1 WHERE id = %d',
				(int) $id
			)
		);
	}


	/**
	 * Total clips in the whole installation (dashboard widget).
	 *
	 * @return int
	 */
	public function total_clips() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table() . " WHERE file_path <> ''" );
	}

	/**
	 * Sanitize a whole row against the whitelist.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	protected function sanitize_all( array $row ) {
		$clean = array();

		foreach ( $row as $key => $value ) {
			if ( ! isset( self::$columns[ $key ] ) || 'id' === $key ) {
				continue;
			}

			$clean[ $key ] = $this->sanitize_value( self::$columns[ $key ], $value );
		}

		return $clean;
	}

	/**
	 * Field sanitizer.
	 *
	 * @param string $type  Type.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	protected function sanitize_value( $type, $value ) {
		switch ( $type ) {
			case 'int':
				return (int) $value;
			case 'float':
				return round( (float) $value, 3 );
			case 'key':
				return substr( strtolower( preg_replace( '/[^a-z0-9_:\-.]/', '', (string) $value ) ), 0, 24 );
			case 'text':
				return vvai_sanitize_text( $value, 191 );
			case 'paragraph':
				return vvai_sanitize_paragraph( $value, 1200 );
			case 'tags':
				if ( is_string( $value ) && '' !== trim( $value ) && '[' === trim( $value )[0] ) {
					$decoded = vvai_json_decode( $value );
					$value   = is_array( $decoded ) ? $decoded : $value;
				}

				return wp_json_encode( vvai_sanitize_hashtags( $value, 12 ) );
			case 'json':
				return wp_json_encode( is_string( $value ) ? vvai_json_decode( $value ) : $value );
			case 'path':
				return substr( wp_normalize_path( (string) $value ), 0, 255 );
			case 'datetime':
				$parsed = strtotime( (string) $value );

				return $parsed ? gmdate( 'Y-m-d H:i:s', $parsed ) : gmdate( 'Y-m-d H:i:s' );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * $wpdb formats for a row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,string>
	 */
	protected function format_for( array $row ) {
		$formats = array();

		foreach ( $row as $key => $unused ) {
			if ( isset( self::$columns[ $key ] ) ) {
				$formats[ $key ] = ( in_array( self::$columns[ $key ], array( 'int' ), true ) ) ? '%d' : '%s';
			}
		}

		return $formats;
	}

	/**
	 * Decode JSON columns for output.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	protected function hydrate( array $row ) {
		$row['hashtags'] = (array) vvai_json_decode( vvai_array_get( $row, 'hashtags', array() ), true );
		$row['metrics']  = (array) vvai_json_decode( vvai_array_get( $row, 'metrics', array() ), true );

		return $row;
	}

	/**
	 * Last database error.
	 *
	 * @return string
	 */
	protected function db_error() {
		global $wpdb;

		return substr( (string) ( isset( $wpdb->last_error ) ? $wpdb->last_error : '' ), 0, 200 );
	}
}
