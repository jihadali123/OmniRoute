<?php
/**
 * Plugin settings.
 *
 * Everything the administrator can configure lives in one autoloaded option
 * (`vvai_settings`) with a per-key sanitizer, so a malformed REST/AJAX payload
 * can never write junk into the database.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Settings
 */
class VVAI_Settings {

	const OPTION_KEY = 'vvai_settings';

	/**
	 * Runtime overrides applied by tests / add-ons.
	 *
	 * @var array<string,mixed>
	 */
	private $overrides = array();

	/**
	 * Defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// AI.
			'active_connection_id'     => '',
			'fallback_connection_id'   => '',
			'allow_fallback'           => false,
			'temperature'              => 0.4,
			'max_clips'                => 5,
			'results_order'            => 'score',
			'transcript_language'      => '',

			// Transcription.
			'transcription_source'     => 'auto', // auto | connection | custom | whisper-cli.
			'transcription_model'      => 'whisper-1',
			'transcription_base_url'   => '',
			'transcription_api_key'    => '',
			'whisper_binary'           => '',
			'transcription_chunk_minutes' => 12,

			// Binaries & server.
			'ffmpeg_path'              => 'ffmpeg',
			'ffprobe_path'             => 'ffprobe',
			// A folder containing ffmpeg[.exe] + ffprobe[.exe]. Easier and far
			// less error-prone than two absolute file paths, and the shape a
			// Windows user gets after unzipping a static build.
			'ffmpeg_dir'               => '',
			'auto_discover_binaries'   => true,
			'ffmpeg_extra_args'        => '',
			'process_timeout'          => 900,
			'max_execution_budget'     => 25,

			// Uploads.
			// 0 = no plugin-imposed cap: uploads are chunked, so the host's
			// per-request limit only has to fit one chunk, not the whole video.
			'max_upload_mb'            => 0,
			'upload_chunk_size'        => 5242880,
			'allowed_extensions'       => array( 'mp4', 'mov', 'webm', 'mkv', 'm4v', 'avi' ),

			// Rendering.
			'default_aspect_ratio'     => '9:16',
			'default_quality'          => '1080p',
			'default_clip_length'      => 'short',
			'default_focus'            => 'viral',
			'crop_mode'                => 'smart', // center | smart.
			'encode_preset'            => 'veryfast',
			'video_crf'                => 21,
			'audio_bitrate'            => '160k',
			'burn_captions'            => false,
			'generate_srt'             => true,
			'allow_upscale'            => false,

			// Retention & housekeeping.
			'temp_retention_hours'     => 6,
			'clip_retention_days'      => 14,
			'delete_source_after_job'  => false,
			'delete_data_on_uninstall' => false,
			'delete_source_retention_days' => 3,
			'auto_cleanup'             => true,

			// Frontend behaviour & security.
			'require_login'            => true,
			'allow_public_downloads'   => false,
			'download_link_ttl'        => 3600,
			'auto_start_job'           => true,
			'show_processing_stage'    => true,
			'max_concurrent_jobs'      => 1,

			// Debug.
			'debug_log'                => false,
			'log_max_kb'               => 512,
		);
	}

	/**
	 * Register the option and its sanitizer.
	 */
	public function register() {
		register_setting(
			'vvai_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
				'autoload'          => 'yes',
			)
		);

		// `register_setting()` only applies to the options.php pipeline; make
		// sure a direct update_option() is sanitized as well.
		add_filter(
			'pre_update_option_' . self::OPTION_KEY,
			array( $this, 'sanitize' ),
			10,
			1
		);
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		if ( array_key_exists( $key, $this->overrides ) ) {
			return $this->overrides[ $key ];
		}

		$settings = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$defaults = self::defaults();

		// A missing key must never mean "null" for the pipeline: fall back.
		$value = array_key_exists( $key, $settings ) ? $settings[ $key ] : ( array_key_exists( $key, $defaults ) ? $defaults[ $key ] : null );

		if ( null === $value && array_key_exists( $key, $defaults ) ) {
			$value = $defaults[ $key ];
		}

		if ( null === $value ) {
			return $default;
		}

		/**
		 * Filter a single setting value.
		 *
		 * @param mixed  $value Value to be returned.
		 * @param string $key   Setting key.
		 */
		return apply_filters( 'vvai_settings_get', $value, $key );
	}

	/**
	 * Write one setting (sanitized through the shared sanitizer).
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public function set( $key, $value ) {
		$current  = $this->all();
		$current[ $key ] = $value;
		$sanitized = $this->sanitize( $current );

		$this->overrides = array();

		update_option( self::OPTION_KEY, $sanitized, 'yes' );

		if ( in_array( $key, array( 'ffmpeg_path', 'ffprobe_path', 'ffmpeg_dir', 'auto_discover_binaries' ), true ) ) {
			self::flush_engine_caches();
		}

		return true;
	}

	/**
	 * Temporarily override a setting for the current request.
	 *
	 * Used by the CLI/worker entry points and the automated tests; never
	 * persisted.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function override( $key, $value ) {
		$this->overrides[ $key ] = $value;
	}

	/**
	 * Clear runtime overrides.
	 */
	public function clear_overrides() {
		$this->overrides = array();
	}

	/**
	 * All settings merged over defaults, sanitized.
	 *
	 * @return array<string,mixed>
	 */
	public function all() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );

		ksort( $merged );

		return $merged;
	}

	/**
	 * Full option sanitizer.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		foreach ( $defaults as $key => $default ) {
			$value = array_key_exists( $key, $input ) ? $input[ $key ] : $default;

			$clean[ $key ] = $this->sanitize_value( $key, $value, $default );
		}

		/**
		 * Filter the sanitized settings before they are stored.
		 *
		 * @param array $clean Sanitized settings.
		 */
		return apply_filters( 'vvai_sanitize_settings', $clean );
	}

	/**
	 * Sanitize a single key.
	 *
	 * @param string $key     Key.
	 * @param mixed  $value   Value.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	protected function sanitize_value( $key, $value, $default ) {
		switch ( $key ) {
			case 'active_connection_id':
			case 'fallback_connection_id':
				return preg_replace( '/[^a-z0-9_-]/i', '', (string) $value );

			case 'allow_fallback':
			case 'debug_log':
			case 'auto_cleanup':
			case 'require_login':
			case 'allow_public_downloads':
			case 'auto_start_job':
			case 'show_processing_stage':
			case 'delete_source_after_job':
			case 'burn_captions':
			case 'generate_srt':
			case 'allow_upscale':
			case 'auto_discover_binaries':
				return vvai_sanitize_bool( $value );

			case 'temperature':
				return round( vvai_sanitize_float( $value, 0, 2, 0.4 ), 2 );

			case 'max_clips':
				return vvai_sanitize_int( $value, 1, 20, 5 );

			case 'results_order':
				return in_array( $value, array( 'score', 'chrono' ), true ) ? $value : 'score';

			case 'transcript_language':
				$value = strtolower( preg_replace( '/[^A-Za-z-]/', '', (string) $value ) );

				return substr( $value, 0, 12 );

			case 'transcription_source':
				return in_array( $value, array( 'auto', 'connection', 'custom', 'whisper-cli', 'disabled' ), true ) ? $value : 'auto';

			case 'transcription_model':
				return vvai_sanitize_text( $value, 80 );

			case 'transcription_base_url':
				return $this->sanitize_url( $value );

			case 'transcription_api_key':
				// Secrets are stored through the crypto layer, but the raw
				// string is sanitized here first.
				return vvai_sanitize_text( $value, 512 );

			case 'whisper_binary':
			case 'ffmpeg_path':
			case 'ffprobe_path':
				return $this->sanitize_binary_path( $value, $default );

			case 'ffmpeg_dir':
				return $this->sanitize_binary_dir( $value );

			case 'ffmpeg_extra_args':
				return $this->sanitize_extra_args( $value );

			case 'process_timeout':
				return vvai_sanitize_int( $value, 30, 14400, 900 );

			case 'max_execution_budget':
				return vvai_sanitize_int( $value, 5, 240, 25 );

			case 'max_upload_mb':
				// 0 is a meaningful value (unlimited) and must survive sanitizing.
				$parsed = vvai_sanitize_int( $value, 0, 2621440, 0 );

				return 0 === $parsed ? 0 : max( 10, $parsed );

			case 'upload_chunk_size':
				return vvai_sanitize_int( $value, 262144, 33554432, 5242880 );

			case 'allowed_extensions':
				$list  = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
				$clean = array();

				foreach ( (array) $list as $ext ) {
					$ext = strtolower( ltrim( trim( (string) $ext ), '.' ) );

					if ( preg_match( '/^[a-z0-9]{1,5}$/', $ext ) ) {
						$clean[ $ext ] = $ext;
					}
				}

				$clean = array_values( $clean );

				return $clean ? $clean : $default;

			case 'default_aspect_ratio':
				return in_array( $value, array( '9:16', '16:9', '1:1', '4:5' ), true ) ? $value : '9:16';

			case 'default_quality':
				return in_array( $value, array( '720p', '1080p', '4k' ), true ) ? $value : '1080p';

			case 'default_clip_length':
				return in_array( $value, array( 'short', 'medium', 'long', 'custom' ), true ) ? $value : 'short';

			case 'default_focus':
				return in_array( $value, array( 'viral', 'action', 'dialogue', 'emotional', 'custom' ), true ) ? $value : 'viral';

			case 'crop_mode':
				return in_array( $value, array( 'center', 'smart' ), true ) ? $value : 'smart';

			case 'encode_preset':
				$presets = array( 'ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow' );

				return in_array( $value, $presets, true ) ? $value : 'veryfast';

			case 'video_crf':
				return vvai_sanitize_int( $value, 14, 35, 21 );

			case 'audio_bitrate':
				$value = preg_replace( '/[^0-9kK]/', '', (string) $value );

				return '' === $value ? '160k' : strtolower( $value ) . 'k';

			case 'temp_retention_hours':
				return vvai_sanitize_int( $value, 0, 168, 6 );

			case 'clip_retention_days':
				return vvai_sanitize_int( $value, 0, 365, 14 );

			case 'delete_source_retention_days':
				return vvai_sanitize_int( $value, 0, 90, 3 );

			case 'download_link_ttl':
				return vvai_sanitize_int( $value, 60, 604800, 3600 );

			case 'max_concurrent_jobs':
				return vvai_sanitize_int( $value, 1, 10, 1 );

			case 'log_max_kb':
				return vvai_sanitize_int( $value, 64, 16384, 512 );
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $default;
	}

	/**
	 * Sanitize a URL used for a custom endpoint.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected function sanitize_url( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$value = esc_url_raw( $value );
		$value = untrailingslashit( (string) $value );

		// Only http(s) is acceptable; also reject localhost style values that
		// would let the server reach internal services by accident.
		if ( ! preg_match( '#^https?://#i', (string) $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Sanitize a folder that contains the FFmpeg binaries.
	 *
	 * A path pointing straight at ffmpeg.exe is accepted too and shortened to
	 * its parent, because that is what people paste.
	 *
	 * @param mixed $value Value.
	 * @return string Absolute folder path or ''.
	 */
	public function sanitize_binary_dir( $value ) {
		$value = trim( (string) $value, " \t\n\r\0\x0B\"\"" );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/[;&|`$><\n\r]/', $value ) || false !== strpos( $value, '"' ) || false !== strpos( $value, "'" ) ) {
			return '';
		}

		$windows = (bool) preg_match( '#^[A-Za-z]:[\\\\/]#', $value );
		$unix    = 0 === strpos( $value, '/' );

		if ( $windows ) {
			if ( ! preg_match( '#^[A-Za-z]:[\\\\/][A-Za-z0-9 ._+~()\\\\/-]*$#', $value ) ) {
				return '';
			}
		} elseif ( $unix ) {
			if ( ! preg_match( '#^/[A-Za-z0-9 ._+~()/-]*$#', $value ) ) {
				return '';
			}
		} else {
			return '';
		}

		if ( false !== strpos( str_replace( '\\', '/', $value ), '..' ) ) {
			return '';
		}

		// Paste protection: a full binary path becomes its folder.
		$base = strtolower( basename( str_replace( '\\', '/', $value ) ) );

		if ( preg_match( '/^ffmpeg\.(exe|bat|cmd)$/', $base ) || preg_match( '/^(ffprobe|whisper)\.(exe|bat|cmd)$/', $base ) ) {
			$value = rtrim( preg_replace( '#[\\\\/][^\\\\/]*$#', '', $value ), '\\/ ' );
		}

		$value = rtrim( $value, '\\/ ' );

		if ( '' === $value ) {
			return '';
		}

		// Nothing executable may live inside the web-writable uploads tree.
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['basedir'] ) ) {
			$base_dir = trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );

			if ( 0 === strpos( trailingslashit( wp_normalize_path( $value ) ), $base_dir ) ) {
				return '';
			}
		}

		return $value;
	}

	/**
	 * Forget every cached answer about FFmpeg availability.
	 *
	 * Without this a corrected path appears to do nothing for up to five
	 * minutes, which reads as "the plugin ignores my settings".
	 */
	public static function flush_engine_caches() {
		delete_transient( class_exists( 'VVAI_FFMPEG' ) ? VVAI_FFMPEG::CACHE_AVAIL : 'vvai_ffmpeg_availability' );
		delete_transient( 'vvai_loopback_check' );
		delete_transient( 'vvai_rest_reachable' );

		if ( class_exists( 'VVAI_Binary_Locator' ) ) {
			VVAI_Binary_Locator::forget();
		}

		// Grants exactly one uncached probe, consumed by availability().
		set_transient( 'vvai_force_probe', 1, 120 );
	}

	/**
	 * Sanitize an executable path.
	 *
	 * Accepts a bare binary name (resolved via PATH) or an absolute path. Shell
	 * metacharacters are removed so the value can never become a command.
	 *
	 * @param mixed  $value   Value.
	 * @param string $default Default.
	 * @return string
	 */
	protected function sanitize_binary_path( $value, $default ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return (string) $default;
		}

		// Reject shell chaining/redirection and quotes. Backslashes and colons are
		// deliberately kept: they are how a Windows path looks
		// (C:\ffmpeg\bin\ffmpeg.exe), and VVAI_Process::binary_is_safe() re-validates
		// the value before anything is executed.
		if ( preg_match( '/[;&|`$><\n\r]/', $value ) || false !== strpos( $value, '"' ) || false !== strpos( $value, "'" ) ) {
			return (string) $default;
		}

		$is_windows = (bool) preg_match( '#^[A-Za-z]:[\\\\/].*$#', $value );

		if ( $is_windows ) {
			// Drive letter, then a normal path: letters, digits, spaces, . _ - and slashes.
			if ( ! preg_match( '#^[A-Za-z]:[\\\\/][A-Za-z0-9 ._%+~()\\\\/-]*$#', $value ) ) {
				return (string) $default;
			}

			if ( false !== strpos( str_replace( '\\', '/', $value ), '..' ) ) {
				return (string) $default;
			}
		} elseif ( ! preg_match( '#^(?:/[A-Za-z0-9._/-]+|[A-Za-z0-9._-]+|[A-Za-z0-9._\\\\/-]+\.[A-Za-z0-9]+)$#', $value ) ) {
			return (string) $default;
		}

		// An executable must never live inside the web-writable uploads tree.
		if ( 0 === strpos( $value, '/' ) || $is_windows ) {
			$uploads = wp_get_upload_dir();
			$basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );

			if ( 0 === strpos( wp_normalize_path( $value ), $basedir ) ) {
				return (string) $default;
			}
		}

		return $value;
	}

	/**
	 * Sanitize extra FFmpeg arguments (advanced users only).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected function sanitize_extra_args( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		// Shell composition, redirection or quoting attempts are refused outright
		// rather than silently stripped: a half-sanitised argument list that is
		// appended to every FFmpeg command is a bigger risk than no extra
		// arguments at all. strpbrk() keeps this escape-free and readable.
		if ( false !== strpbrk( $value, ";|&<>$\n\r'\"`\\" ) ) {
			return '';
		}

		$forbidden = apply_filters(
			'vvai_forbidden_ffmpeg_args',
			array( '-f', 'concat', 'avisynth', 'subfile', '-i', 'gdb', 'sendrc' )
		);

		foreach ( (array) $forbidden as $bad ) {
			if ( '' !== $bad && false !== stripos( $value, (string) $bad ) ) {
				return '';
			}
		}

		return preg_replace( '/\s+/', ' ', $value );
	}

	/**
	 * Effective maximum upload size in bytes, capped by the server limits.
	 *
	 * @return int
	 */
	public function max_upload_bytes() {
		$mb = (int) $this->get( 'max_upload_mb' );

		if ( $mb > 0 ) {
			$server = $this->server_upload_limit_bytes();

			// A host limit below the configured cap still wins: the plugin cannot
			// make PHP accept a bigger POST body.
			$effective = $server > 0 ? min( $mb * MB_IN_BYTES, $server ) : $mb * MB_IN_BYTES;
		} else {
			// Unlimited. Deliberately NOT folded with the server limits: chunks are
			// what hit post_max_size, so a 12 GB source works on a 20M host.
			$effective = 0;
		}

		/**
		 * Filter the maximum accepted upload size in bytes.
		 *
		 * @param int $limit Bytes. 0 = unlimited.
		 */
		return (int) apply_filters( 'vvai_max_upload_bytes', $effective );
	}

	/**
	 * Is this many bytes acceptable for upload?
	 *
	 * @param int $bytes Size in bytes.
	 * @return true|WP_Error
	 */
	public function check_upload_size( $bytes ) {
		$bytes = (int) $bytes;
		$limit = (int) $this->max_upload_bytes();

		if ( $limit <= 0 || $bytes <= $limit ) {
			return true;
		}

		return new WP_Error(
			'vvai_too_large',
			sprintf(
				/* translators: 1: file size, 2: allowed size. */
				__( 'That file is %1$s, but this site is configured to accept at most %2$s. Raise the limit in Viral Video AI → Settings (set 0 for no cap) or increase upload_max_filesize / post_max_size on the server.', 'viral-video-ai' ),
				vvai_human_size( $bytes, 2 ),
				vvai_human_size( $limit, 2 )
			)
		);
	}

	/**
	 * Server-side upload ceiling that WordPress itself would enforce.
	 *
	 * @return int
	 */
	public function server_upload_limit_bytes() {
		return self::php_size_limit(
			(string) ini_get( 'upload_max_filesize' ),
			(string) ini_get( 'post_max_size' )
		);
	}

	/**
	 * Turn two php.ini size strings into one effective byte limit.
	 *
	 * Exposed as a static helper because upload_max_filesize/post_max_size are
	 * PHP_INI_PERDIR and cannot be set at runtime, so this is the only honest way
	 * to test the "no limit" handling.
	 *
	 * @param string $upload upload_max_filesize value.
	 * @param string $post   post_max_size value.
	 * @return int Bytes, or 0 when neither imposes a limit.
	 */
	public static function php_size_limit( $upload, $post ) {
		$candidates = array();

		foreach ( array( $upload, $post ) as $value ) {
			$parsed = vvai_shorthand_to_bytes( (string) $value );

			// php.ini uses 0 and -1 for "no limit".
			if ( $parsed > 0 ) {
				$candidates[] = $parsed;
			}
		}

		return $candidates ? (int) min( $candidates ) : 0;
	}

	/**
	 * Clip length window for a mode, in seconds.
	 *
	 * @param string $mode   short|medium|long|custom.
	 * @param int    $min    Custom minimum.
	 * @param int    $max    Custom maximum.
	 * @return array{0:int,1:int}
	 */
	public static function duration_range( $mode, $min = 0, $max = 0 ) {
		switch ( $mode ) {
			case 'medium':
				return array( 120, 180 );
			case 'long':
				return array( 240, 300 );
			case 'custom':
				$min = max( 5, (int) $min );
				$max = max( $min + 5, (int) $max );

				return array( min( $min, 900 ), min( $max, 1800 ) );
			case 'short':
			default:
				return array( 30, 60 );
		}
	}

	/**
	 * Aspect ratio → [ width_factor, height_factor ].
	 *
	 * @param string $ratio Ratio string.
	 * @return array{0:float,1:float}
	 */
	public static function ratio_factors( $ratio ) {
		switch ( $ratio ) {
			case '16:9':
				return array( 16.0, 9.0 );
			case '1:1':
				return array( 1.0, 1.0 );
			case '4:5':
				return array( 4.0, 5.0 );
			case '9:16':
			default:
				return array( 9.0, 16.0 );
		}
	}

	/**
	 * Quality label → target height in pixels.
	 *
	 * @param string $quality 720p|1080p|4k.
	 * @return int
	 */
	public static function quality_height( $quality ) {
		switch ( $quality ) {
			case '4k':
				return 2160;
			case '720p':
				return 720;
			case '1080p':
			default:
				return 1080;
		}
	}
}
