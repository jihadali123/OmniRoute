<?php
/**
 * Server diagnostics (spec §12, §37).
 *
 * One place that answers "can this server actually run the pipeline?" — used by
 * the Diagnostics screen, the dashboard and the REST endpoint, and consulted by
 * the pipeline before it accepts a job.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Diagnostics
 */
class VVAI_Diagnostics {

	/**
	 * @var VVAI_Settings
	 */
	private $settings;

	/**
	 * @var VVAI_FFMPEG
	 */
	private $ffmpeg;

	/**
	 * @var VVAI_Connection_Store
	 */
	private $connections;

	/**
	 * Constructor.
	 *
	 * @param VVAI_Settings|null      $settings    Settings.
	 * @param VVAI_FFMPEG|null        $ffmpeg      FFmpeg gateway.
	 * @param VVAI_Connection_Store|null $connections Connections.
	 */
	public function __construct( $settings = null, $ffmpeg = null, $connections = null ) {
		$this->settings    = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
		$this->ffmpeg      = $ffmpeg instanceof VVAI_FFMPEG ? $ffmpeg : new VVAI_FFMPEG( $this->settings );
		$this->connections = $connections instanceof VVAI_Connection_Store ? $connections : new VVAI_Connection_Store();
	}

	/**
	 * Full report.
	 *
	 * @return array{items:array<int,array<string,mixed>>,ready:bool,warnings:int,problems:int}
	 */
	public function report() {
		$items = array();

		$items[] = $this->item( 'wordpress', __( 'WordPress version', 'viral-video-ai' ), $GLOBALS['wp_version'] ?? get_bloginfo( 'version' ), 'ready' );
		$items[] = $this->item(
			'php',
			__( 'PHP version', 'viral-video-ai' ),
			PHP_VERSION,
			version_compare( PHP_VERSION, VVAI_MIN_PHP, '>=' ) ? 'ready' : 'problem',
			version_compare( PHP_VERSION, VVAI_MIN_PHP, '>=' ) ? '' : sprintf(
				/* translators: %s: version. */
				__( 'PHP %s or newer is required.', 'viral-video-ai' ),
				VVAI_MIN_PHP
			)
		);

		$items[] = $this->item( 'memory_limit', __( 'PHP memory limit', 'viral-video-ai' ), (string) ini_get( 'memory_limit' ), $this->memory_status(), $this->memory_hint() );
		$items[] = $this->item( 'upload_max_filesize', __( 'upload_max_filesize', 'viral-video-ai' ), (string) ini_get( 'upload_max_filesize' ), $this->size_status( ini_get( 'upload_max_filesize' ) ) );
		$items[] = $this->item( 'post_max_size', __( 'post_max_size', 'viral-video-ai' ), (string) ini_get( 'post_max_size' ), $this->size_status( ini_get( 'post_max_size' ) ) );
		$items[] = $this->item( 'max_execution_time', __( 'max_execution_time', 'viral-video-ai' ), ini_get( 'max_execution_time' ) . 's', ( (int) ini_get( 'max_execution_time' ) >= 30 || 0 === (int) ini_get( 'max_execution_time' ) ) ? 'ready' : 'warning', __( 'Background stages are capped by the plugin budget, so a low value is survivable — but very low limits slow rendering.', 'viral-video-ai' ) );
		$items[] = $this->item( 'max_input_time', __( 'max_input_time', 'viral-video-ai' ), (string) ini_get( 'max_input_time' ) );
		$items[] = $this->item(
			'vvai_upload_limit',
			__( 'Plugin upload limit', 'viral-video-ai' ),
			vvai_human_size( (int) $this->settings->get( 'max_upload_mb' ) * MB_IN_BYTES ) . ' (' . number_format_i18n( (int) $this->settings->max_upload_bytes() / MB_IN_BYTES ) . ' MB effective)',
			$this->settings->max_upload_bytes() >= 100 * MB_IN_BYTES ? 'ready' : 'warning'
		);

		$availability = $this->ffmpeg->availability( true );

		$items[] = $this->item(
			'ffmpeg',
			__( 'FFmpeg available', 'viral-video-ai' ),
			! empty( $availability['ffmpeg']['available'] ) ? (string) $availability['ffmpeg']['version'] : (string) vvai_array_get( $availability['ffmpeg'], 'error', __( 'Not found', 'viral-video-ai' ) ),
			! empty( $availability['ffmpeg']['available'] ) ? 'ready' : 'problem',
			! empty( $availability['ffmpeg']['available'] ) ? '' : __( 'Install FFmpeg on the server, or set the absolute path in Viral Video AI → Settings.', 'viral-video-ai' )
		);

		$items[] = $this->item(
			'ffprobe',
			__( 'FFprobe available', 'viral-video-ai' ),
			! empty( $availability['ffprobe']['available'] ) ? (string) $availability['ffprobe']['version'] : (string) vvai_array_get( $availability['ffprobe'], 'error', __( 'Not found', 'viral-video-ai' ) ),
			! empty( $availability['ffprobe']['available'] ) ? 'ready' : 'problem'
		);

		$encoders = (array) vvai_array_get( $availability['ffmpeg'], 'encoders', array() );

		$items[] = $this->item(
			'libx264',
			__( 'H.264 encoder (libx264)', 'viral-video-ai' ),
			empty( $encoders['libx264'] ) ? __( 'Missing', 'viral-video-ai' ) : __( 'Available', 'viral-video-ai' ),
			empty( $encoders['libx264'] ) ? 'warning' : 'ready',
			empty( $encoders['libx264'] ) ? __( 'Without libx264 the plugin cannot render broadly compatible MP4 clips. Use an FFmpeg build compiled with x264.', 'viral-video-ai' ) : ''
		);

		$items[] = $this->item(
			'subtitles_filter',
			__( 'Caption burn-in (libass/subtitles filter)', 'viral-video-ai' ),
			empty( $encoders['subtitles'] ) ? __( 'Not available', 'viral-video-ai' ) : __( 'Available', 'viral-video-ai' ),
			empty( $encoders['subtitles'] ) ? 'warning' : 'ready'
		);

		$process = VVAI_Process::capability();

		$items[] = $this->item(
			'shell',
			__( 'PHP can execute processes', 'viral-video-ai' ),
			implode( ', ', (array) $process['methods'] ) ?: __( 'none', 'viral-video-ai' ),
			$process['available'] ? 'ready' : 'problem',
			$process['available'] ? '' : (string) $process['reason']
		);

		$storage = vvai_storage_dir();

		$items[] = $this->item(
			'filesystem',
			__( 'Storage folder writable', 'viral-video-ai' ),
			( is_dir( $storage ) && is_writable( $storage ) ? __( 'Writable', 'viral-video-ai' ) : __( 'Not writable', 'viral-video-ai' ) ) . ' — ' . $this->redacted_path( $storage ),
			( is_dir( $storage ) && is_writable( $storage ) ) ? 'ready' : 'problem'
		);

		$items[] = $this->item(
			'disk',
			__( 'Free disk space', 'viral-video-ai' ),
			is_dir( $storage ) ? vvai_human_size( (int) @disk_free_space( $storage ) ) : __( 'unknown', 'viral-video-ai' ),
			( is_dir( $storage ) && @disk_free_space( $storage ) > 2 * GB_IN_BYTES ) ? 'ready' : 'warning'
		);

		$items[] = $this->item(
			'rest',
			__( 'REST API reachable', 'viral-video-ai' ),
			VVAI_Rest_Api::is_reachable() ? __( 'Yes', 'viral-video-ai' ) : __( 'No — the plugin falls back to admin-ajax', 'viral-video-ai' ),
			VVAI_Rest_Api::is_reachable() ? 'ready' : 'warning'
		);

		$loopback = $this->loopback_check();

		$items[] = $this->item(
			'loopback',
			__( 'Loopback requests (instant job start)', 'viral-video-ai' ),
			$loopback['message'],
			$loopback['status'],
			$loopback['hint']
		);

		$items[] = $this->item(
			'openssl',
			__( 'OpenSSL (credential encryption)', 'viral-video-ai' ),
			extension_loaded( 'openssl' ) ? __( 'Loaded', 'viral-video-ai' ) : __( 'Missing', 'viral-video-ai' ),
			extension_loaded( 'openssl' ) ? 'ready' : 'warning',
			extension_loaded( 'openssl' ) ? '' : ( new VVAI_Crypto() )->status_message()
		);

		$items[] = $this->item(
			'mbstring',
			__( 'mbstring', 'viral-video-ai' ),
			function_exists( 'mb_substr' ) ? __( 'Loaded', 'viral-video-ai' ) : __( 'Missing', 'viral-video-ai' ),
			function_exists( 'mb_substr' ) ? 'ready' : 'warning'
		);

		$items[] = $this->item(
			'curl_or_fsockopen',
			__( 'HTTP transport', 'viral-video-ai' ),
			extension_loaded( 'curl' ) ? 'cURL' : ( function_exists( 'fsockopen' ) ? 'fsockopen' : __( 'none', 'viral-video-ai' ) ),
			( extension_loaded( 'curl' ) || function_exists( 'fsockopen' ) ) ? 'ready' : 'problem'
		);

		$tables = array(
			VVAI_DB::jobs_table(),
			VVAI_DB::clips_table(),
			VVAI_DB::uploads_table(),
		);

		$missing = array();

		foreach ( $tables as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				$missing[] = $table;
			}
		}

		$items[] = $this->item(
			'tables',
			__( 'Database tables', 'viral-video-ai' ),
			$missing ? sprintf(
				/* translators: %s: table names. */
				__( 'Missing: %s', 'viral-video-ai' ),
				implode( ', ', $missing )
			) : __( 'All present', 'viral-video-ai' ),
			$missing ? 'problem' : 'ready'
		);

		$connected = $this->connections->connected();

		$items[] = $this->item(
			'connection',
			__( 'Active AI connection', 'viral-video-ai' ),
			$connected ? ( (string) $connected[0]['title'] . ' (' . VVAI_Api_Manager::label_for( (string) $connected[0]['provider'] ) . ')' ) : __( 'None connected', 'viral-video-ai' ),
			$connected ? 'ready' : 'problem',
			$connected ? '' : __( 'Add and connect a provider in Viral Video AI → API Connections before processing videos.', 'viral-video-ai' )
		);

		$transcription = $this->transcription_check();

		$items[] = $this->item(
			'transcription',
			__( 'Transcription engine', 'viral-video-ai' ),
			$transcription['message'],
			$transcription['status'],
			$transcription['hint']
		);

		$items[] = $this->item(
			'scheduler',
			__( 'Background scheduler', 'viral-video-ai' ),
			VVAI_Job_Queue::has_async_scheduler() ? __( 'Action Scheduler detected', 'viral-video-ai' ) : __( 'WP-Cron (1-minute heartbeat)', 'viral-video-ai' ),
			VVAI_Job_Queue::has_async_scheduler() ? 'ready' : ( $loopback['status'] === 'ready' ? 'ready' : 'warning' ),
			VVAI_Job_Queue::has_async_scheduler() ? '' : __( 'For guaranteed processing on low-traffic sites, install the free Action Scheduler plugin or add a real system cron for wp-cron.php.', 'viral-video-ai' )
		);

		$problems  = 0;
		$warnings = 0;

		foreach ( $items as $item ) {
			if ( 'problem' === $item['status'] ) {
				$problems++;
			} elseif ( 'warning' === $item['status'] ) {
				$warnings++;
			}
		}

		return array(
			'items'    => $items,
			'ready'    => ( 0 === $problems ),
			'warnings' => $warnings,
			'problems' => $problems,
		);
	}

	/**
	 * Can a job be started at all? Used to block submission with a clear message.
	 *
	 * @return array{ok:bool,message:string,code:string}
	 */
	public function preflight() {
		$availability = $this->ffmpeg->availability();

		if ( empty( $availability['ok'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'FFmpeg/FFprobe are not available on this server, so clips cannot be rendered. Fix the paths in Viral Video AI → Settings → Diagnostics.', 'viral-video-ai' ),
				'code'    => 'ffmpeg_unavailable',
			);
		}

		$storage = vvai_storage_dir();

		if ( ! is_dir( $storage ) || ! is_writable( $storage ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The plugin storage folder is not writable (check uploads/vvai permissions).', 'viral-video-ai' ),
				'code'    => 'storage_not_writable',
			);
		}

		if ( ! VVAI_DB::is_installed() ) {
			return array(
				'ok'      => false,
				'message' => __( 'The plugin database tables are missing. Deactivate and reactivate the plugin to create them.', 'viral-video-ai' ),
				'code'    => 'tables_missing',
			);
		}

		return array(
			'ok'      => true,
			'message' => '',
			'code'    => '',
		);
	}

	/**
	 * Compact summary for the dashboard cards.
	 *
	 * @return array<string,mixed>
	 */
	public function summary() {
		$report    = $this->report();
		$available = $this->ffmpeg->availability();

		return array(
			'ready'         => (bool) $report['ready'],
			'problems'      => (int) $report['problems'],
			'warnings'      => (int) $report['warnings'],
			'ffmpeg'        => ! empty( $available['ok'] ),
			'ffmpeg_version' => (string) vvai_array_get( $available['ffmpeg'], 'version_number', '' ),
			'connection'    => $this->connections->get_active( true ),
			'uploads'       => (int) $this->settings->get( 'max_upload_mb' ),
			'memory'        => (string) ini_get( 'memory_limit' ),
		);
	}

	/**
	 * Build one report line.
	 *
	 * @param string $key     Key.
	 * @param string $label   Label.
	 * @param string $value   Value.
	 * @param string $status  ready|warning|problem.
	 * @param string $hint    Hint text.
	 * @return array<string,mixed>
	 */
	protected function item( $key, $label, $value, $status = 'ready', $hint = '' ) {
		return array(
			'key'    => $key,
			'label'  => $label,
			'value'  => (string) $value,
			'status' => in_array( $status, array( 'ready', 'warning', 'problem' ), true ) ? $status : 'ready',
			'hint'   => (string) $hint,
			'icon'   => 'ready' === $status ? '🟢' : ( 'warning' === $status ? '🟡' : '🔴' ),
		);
	}

	/**
	 * Memory limit status.
	 *
	 * @return string
	 */
	protected function memory_status() {
		$limit = vvai_shorthand_to_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			return 'ready';
		}

		return $limit >= ( 256 * MB_IN_BYTES ) ? 'ready' : 'warning';
	}

	/**
	 * Memory hint.
	 *
	 * @return string
	 */
	protected function memory_hint() {
		$limit = vvai_shorthand_to_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $limit > 0 && $limit < ( 256 * MB_IN_BYTES ) ) {
			return __( 'The pipeline streams files instead of loading them, but 256M+ makes transcript handling safer.', 'viral-video-ai' );
		}

		return '';
	}

	/**
	 * Size option status.
	 *
	 * @param mixed $value php.ini value.
	 * @return string
	 */
	protected function size_status( $value ) {
		$bytes = vvai_shorthand_to_bytes( (string) $value );

		if ( $bytes >= ( 512 * MB_IN_BYTES ) || 0 === $bytes ) {
			return 'ready';
		}

		return $bytes >= ( 100 * MB_IN_BYTES ) ? 'warning' : 'problem';
	}


	/**
	 * Transcription engine availability.
	 *
	 * @return array{status:string,message:string,hint:string}
	 */
	protected function transcription_check() {
		$service = new VVAI_Transcription( null, $this->ffmpeg, $this->settings, $this->connections );
		$engine  = $service->choose_engine( array( 'connection_id' => '' ) );

		if ( 'none' === $engine['kind'] ) {
			return array(
				'status'  => 'warning',
				'message' => __( 'No engine', 'viral-video-ai' ),
				'hint'    => (string) $engine['message'],
			);
		}

		return array(
			'status'  => 'ready',
			'message' => (string) $engine['message'],
			'hint'    => 'provider' === $engine['kind'] ? '' : __( 'Timestamps come from the transcription engine; quality follows the model chosen.', 'viral-video-ai' ),
		);
	}

	/**
	 * Loopback HTTP self-check (used by the spawn trigger).
	 *
	 * @return array{status:string,message:string,hint:string}
	 */
	protected function loopback_check() {
		$cache_key = 'vvai_loopback_check';
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url = admin_url( 'admin-ajax.php' );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 5,
				'sslverify' => true,
				'headers'   => array( 'X-VVAI-Self-Check' => '1' ),
			)
		);

		$result = array(
			'status'  => 'ready',
			'message' => __( 'Yes', 'viral-video-ai' ),
			'hint'    => '',
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'status'  => 'warning',
				'message' => __( 'Blocked', 'viral-video-ai' ),
				'hint'    => sprintf(
					/* translators: 1: error, 2: advice. */
					__( 'WordPress could not call itself (%1$s). Jobs still process through WP-Cron, but with up to a minute of extra latency. Allow loopback requests or install Action Scheduler. %2$s', 'viral-video-ai' ),
					$response->get_error_message(),
					''
				),
			);
		}

		set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );

		return $result;
	}


	/**
	 * Does a custom table exist?
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	protected function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
	}

	/**
	 * Show a path without the server root, so support screenshots leak nothing.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected function redacted_path( $path ) {
		$abspath = wp_normalize_path( (string) ABSPATH );
		$path    = wp_normalize_path( (string) $path );

		return str_replace( $abspath, '[WP root]/', $path );
	}
}
