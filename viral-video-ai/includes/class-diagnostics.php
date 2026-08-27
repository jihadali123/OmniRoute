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

		// availability() self-censors: it only re-probes when a recheck was asked
		// for, so this stays cheap on every dashboard load.
		$availability = $this->ffmpeg->availability( true );

		$ffmpeg_ok  = ! empty( $availability['ffmpeg']['available'] );
		$ffprobe_ok = ! empty( $availability['ffprobe']['available'] );

		$items[] = $this->item(
			'ffmpeg',
			__( 'FFmpeg available', 'viral-video-ai' ),
			$ffmpeg_ok ? (string) $availability['ffmpeg']['version'] : (string) vvai_array_get( $availability['ffmpeg'], 'error', __( 'Not found', 'viral-video-ai' ) ),
			$ffmpeg_ok ? 'ready' : 'problem',
			$ffmpeg_ok ? (string) vvai_array_get( $availability['ffmpeg'], 'path', '' ) : $this->engine_hint_text( $availability )
		);

		$items[] = $this->item(
			'ffprobe',
			__( 'FFprobe available', 'viral-video-ai' ),
			$ffprobe_ok ? (string) $availability['ffprobe']['version'] : (string) vvai_array_get( $availability['ffprobe'], 'error', __( 'Not found', 'viral-video-ai' ) ),
			$ffprobe_ok ? 'ready' : 'problem',
			$ffprobe_ok ? (string) vvai_array_get( $availability['ffprobe'], 'path', '' ) : ''
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
		$ready = $this->frontend_readiness();

		if ( empty( $ready['ok'] ) ) {
			return $ready;
		}

		if ( ! VVAI_DB::is_installed() ) {
			return array(
				'ok'      => false,
				'message' => __( 'The plugin database tables are missing. Deactivate and reactivate the plugin to create them.', 'viral-video-ai' ),
				'code'    => 'tables_missing',
				'reason'  => 'tables_missing',
				'steps'   => array( __( 'Deactivate Viral Video AI and activate it again — that recreates the vvai_jobs, vvai_clips and vvai_uploads tables.', 'viral-video-ai' ) ),
				'hint'    => __( 'Deactivate and reactivate the plugin.', 'viral-video-ai' ),
				'fixUrl'  => '',
			);
		}

		return $ready;
	}

	/**
	 * Readiness for public screens.
	 *
	 * Deliberately cheap: the FFmpeg answer comes from the cached availability
	 * report and no table is queried, so a widget in a page builder can ask this
	 * on every view. Its whole purpose is to stop a visitor uploading a 2 GB
	 * video into a pipeline that cannot render it.
	 *
	 * @return array<string,mixed>
	 */
	public function frontend_readiness() {
		$availability = $this->ffmpeg->availability();

		if ( empty( $availability['ok'] ) ) {
			$steps = array_map( 'strval', (array) vvai_array_get( $availability, 'steps', array() ) );

			return array(
				'ok'      => false,
				'message' => '' !== (string) vvai_array_get( $availability, 'title', '' )
					? (string) $availability['title']
					: __( 'FFmpeg/FFprobe are not available on this server, so clips cannot be rendered.', 'viral-video-ai' ),
				'code'    => 'ffmpeg_unavailable',
				'reason'  => (string) vvai_array_get( $availability, 'reason', 'not_found' ),
				'steps'   => $steps,
				'hint'    => $steps ? implode( ' ', $steps ) : __( 'Ask the site administrator to install FFmpeg or set its folder in Viral Video AI → Settings.', 'viral-video-ai' ),
				'fixUrl'  => (string) vvai_array_get( $availability, 'fix_url', '' ),
			);
		}

		$storage = vvai_storage_dir();

		if ( ! is_dir( $storage ) || ! is_writable( $storage ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The plugin storage folder is not writable, so nothing can be rendered.', 'viral-video-ai' ),
				'code'    => 'storage_not_writable',
				'reason'  => 'storage_not_writable',
				'steps'   => array( __( 'Give the web server write access to wp-content/uploads/vvai (755 with the right owner, 775 if the folder is shared).', 'viral-video-ai' ) ),
				'hint'    => __( 'Check the permissions on wp-content/uploads/vvai.', 'viral-video-ai' ),
				'fixUrl'  => '',
			);
		}

		return array(
			'ok'      => true,
			'message' => '',
			'code'    => '',
			'reason'  => '',
			'steps'   => array(),
			'hint'    => '',
			'fixUrl'  => '',
		);
	}

	/**
	 * Compact summary for the dashboard cards.
	 *
	 * @return array<string,mixed>
	 */
	public function summary() {
		try {
			$report = $this->report();
		} catch ( \Throwable $throwable ) {
			// Diagnostics must never be the thing that breaks wp-admin.
			$report = array(
				'items'    => array(),
				'ready'    => false,
				'warnings' => 0,
				'problems' => 1,
			);
		}

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
	 * One-line "how do I fix this" text for a failed availability probe.
	 *
	 * @param array<string,mixed> $availability Availability report.
	 * @return string
	 */
	protected function engine_hint_text( array $availability ) {
		$steps = array_map( 'strval', (array) vvai_array_get( $availability, 'steps', array() ) );

		if ( ! $steps ) {
			return __( 'Install FFmpeg on the server, or point Viral Video AI → Settings at the folder that contains it.', 'viral-video-ai' );
		}

		return implode( ' ', $steps );
	}

	/**
	 * Everything the Diagnostics screen needs to know about the video engine.
	 *
	 * @param string $mode 'status' | 'search' | 'apply'.
	 * @param string $dir  Folder to apply (mode=apply).
	 * @return array<string,mixed>|WP_Error
	 */
	public function ffmpeg_engine( $mode = 'status', $dir = '' ) {
		$mode = in_array( (string) $mode, array( 'status', 'search', 'apply' ), true ) ? (string) $mode : 'status';

		if ( 'apply' === $mode ) {
			$applied = $this->ffmpeg_apply_dir( $dir );

			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
		} elseif ( 'search' === $mode ) {
			// A search must see the filesystem as it is now, not the cached answer.
			VVAI_Settings::flush_engine_caches();
		}

		$availability = $this->ffmpeg->availability();

		$payload = array(
			'mode'       => $mode,
			'ok'         => ! empty( $availability['ok'] ),
			'reason'     => (string) vvai_array_get( $availability, 'reason', '' ),
			'title'      => (string) vvai_array_get( $availability, 'title', '' ),
			'steps'      => array_map( 'strval', (array) vvai_array_get( $availability, 'steps', array() ) ),
			'os'         => VVAI_Binary_Locator::is_windows() ? 'windows' : 'unix',
			'executable' => VVAI_Process::capability(),
			'settings'   => array(
				'ffmpeg_path'    => (string) $this->settings->get( 'ffmpeg_path' ),
				'ffprobe_path'   => (string) $this->settings->get( 'ffprobe_path' ),
				'ffmpeg_dir'     => (string) $this->settings->get( 'ffmpeg_dir' ),
				'auto_discover'  => (bool) $this->settings->get( 'auto_discover_binaries' ),
			),
			'bins'       => array(),
			'searched'   => array_slice( VVAI_Binary_Locator::search_dirs(), 0, 16 ),
			'found'      => array(),
			'message'    => '',
		);

		foreach ( array( 'ffmpeg' => 'ffmpeg_path', 'ffprobe' => 'ffprobe_path' ) as $kind => $setting ) {
			$resolved = 'ffmpeg' === $kind ? $this->ffmpeg->ffmpeg_path() : $this->ffmpeg->ffprobe_path();
			$state    = (array) vvai_array_get( $availability, $kind, array() );

			$payload['bins'][] = array(
				'kind'       => $kind,
				'configured' => (string) $this->settings->get( $setting ),
				'resolved'   => (string) $resolved,
				'ok'         => ! empty( $state['available'] ),
				'version'    => (string) vvai_array_get( $state, 'version', '' ),
				'error'      => (string) vvai_array_get( $state, 'error', '' ),
			);
		}

		if ( 'status' !== $mode ) {
			$payload['found'] = $this->ffmpeg_candidates();
		}

		if ( 'search' === $mode ) {
			$payload['message'] = $payload['found']
				? sprintf(
					/* translators: %s: number of locations. */
					__( '%s possible FFmpeg location(s) inspected on this server.', 'viral-video-ai' ),
					(string) count( $payload['found'] )
				)
				: __( 'Nothing that looks like FFmpeg was found on this server. Install a build, then apply it here or set the folder in Settings.', 'viral-video-ai' );
		}

		return $payload;
	}

	/**
	 * Inspect every discovered FFmpeg folder, verifying each binary by running it.
	 *
	 * Verification matters: a file called ffmpeg.exe proves nothing, and the
	 * plugin must never save a path it has not seen answer `-version`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function ffmpeg_candidates() {
		$by_dir = array();

		foreach ( array( 'ffmpeg', 'ffprobe' ) as $kind ) {
			foreach ( VVAI_Binary_Locator::discover_all( $kind ) as $path ) {
				$dir = str_replace( '\\', '/', dirname( str_replace( '\\', '/', (string) $path ) ) );

				if ( ! isset( $by_dir[ $dir ] ) ) {
					$by_dir[ $dir ] = array();
				}

				$by_dir[ $dir ][ $kind ] = $path;
			}
		}

		$rows   = array();
		$probes = 0;

		foreach ( $by_dir as $dir => $found ) {
			$row = array(
				'dir'   => (string) $dir,
				'ok'    => false,
				'bins'  => array(),
			);

			foreach ( $found as $kind => $path ) {
				if ( $probes >= 12 ) {
					break;
				}

				$probes++;

				$verified           = VVAI_Binary_Locator::verify( $path, $kind );
				$row['bins'][ $kind ] = array(
					'path'    => (string) $verified['path'],
					'ok'      => (bool) $verified['ok'],
					'version' => (string) $verified['version'],
					'error'   => (string) $verified['error'],
				);
			}

			$row['ok'] = ! empty( $row['bins']['ffmpeg']['ok'] ) && ! empty( $row['bins']['ffprobe']['ok'] );

			$rows[] = $row;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $b['ok'] - (int) $a['ok'];
			}
		);

		return array_slice( $rows, 0, 8 );
	}

	/**
	 * Point the plugin at a discovered FFmpeg folder.
	 *
	 * @param string $dir Folder path.
	 * @return true|WP_Error
	 */
	protected function ffmpeg_apply_dir( $dir ) {
		$dir = $this->settings->sanitize_binary_dir( $dir );

		if ( '' === $dir ) {
			return new WP_Error( 'vvai_bad_dir', __( 'That folder is not a valid path this plugin may use (it must be absolute and outside the uploads folder).', 'viral-video-ai' ) );
		}

		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'vvai_missing_dir', __( 'That folder does not exist on this server.', 'viral-video-ai' ) );
		}

		$ffmpeg_found  = false;
		$ffprobe_found = false;

		foreach ( VVAI_Binary_Locator::names( 'ffmpeg' ) as $name ) {
			if ( is_file( VVAI_Binary_Locator::join( $dir, $name ) ) ) {
				$ffmpeg_found = true;

				break;
			}
		}

		foreach ( VVAI_Binary_Locator::names( 'ffprobe' ) as $name ) {
			if ( is_file( VVAI_Binary_Locator::join( $dir, $name ) ) ) {
				$ffprobe_found = true;

				break;
			}
		}

		if ( ! $ffmpeg_found || ! $ffprobe_found ) {
			return new WP_Error(
				'vvai_incomplete_dir',
				sprintf(
					/* translators: %s: folder path. */
					__( '%s must contain both ffmpeg and ffprobe to be used.', 'viral-video-ai' ),
					$dir
				)
			);
		}

		$stored = $this->settings->all();
		$stored['ffmpeg_dir']  = $dir;
		$stored['ffmpeg_path'] = 'ffmpeg';
		$stored['ffprobe_path'] = 'ffprobe';

		update_option( VVAI_Settings::OPTION_KEY, $this->settings->sanitize( $stored ), 'yes' );

		VVAI_Settings::flush_engine_caches();

		$availability = $this->ffmpeg->availability();

		if ( empty( $availability['ok'] ) ) {
			return new WP_Error(
				'vvai_engine_still_down',
				sprintf(
					/* translators: %s: folder path. */
					__( 'The folder was saved, but %s did not produce a working FFmpeg and FFprobe. See the error next to each binary.', 'viral-video-ai' ),
					$dir
				),
				array( 'hint' => $this->engine_hint_text( $availability ) )
			);
		}

		return true;
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
