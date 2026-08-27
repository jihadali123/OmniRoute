<?php
/**
 * FFmpeg / FFprobe gateway.
 *
 * Responsibilities:
 *  - binary discovery and availability (spec §12),
 *  - container inspection through ffprobe JSON,
 *  - audio extraction and segmentation for transcription,
 *  - clip rendering with the real crop/scale pipeline (spec §19, §21),
 *  - output verification, so a "successful" render means a decodable file.
 *
 * Every command is assembled as an argv array and escaped by VVAI_Process; no
 * user input is ever concatenated into a shell string.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_FFMPEG
 */
class VVAI_FFMPEG {

	const CACHE_AVAIL = 'vvai_ffmpeg_availability';
	const CACHE_TTL   = 300;

	/**
	 * Settings.
	 *
	 * @var VVAI_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param VVAI_Settings|null $settings Settings.
	 */
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
	}

	/**
	 * Configured ffmpeg path (resolved).
	 *
	 * @return string
	 */
	public function ffmpeg_path() {
		return VVAI_Process::locate( (string) $this->settings->get( 'ffmpeg_path' ) );
	}

	/**
	 * Configured ffprobe path (resolved).
	 *
	 * @return string
	 */
	public function ffprobe_path() {
		return VVAI_Process::locate( (string) $this->settings->get( 'ffprobe_path' ) );
	}

	/**
	 * Availability of both binaries, with version output.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array{ffmpeg:array<string,mixed>,ffprobe:array<string,mixed>,ok:bool}
	 */
	public function availability( $fresh = false ) {
		// A forced re-probe is only honoured right after an explicit "Re-check now".
		// Rendering the dashboard or settings many times a minute must not spawn
		// three processes each time (and must not expose a host error to the user).
		if ( $fresh && ! get_transient( 'vvai_force_probe' ) ) {
			$fresh = false;
		}

		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_AVAIL );

			if ( is_array( $cached ) && isset( $cached['ok'] ) ) {
				return $cached;
			}
		}

		$out = array(
			'ffmpeg'  => $this->probe_binary( $this->ffmpeg_path(), 'ffmpeg' ),
			'ffprobe' => $this->probe_binary( $this->ffprobe_path(), 'ffprobe' ),
			'ok'      => false,
		);

		$out['ok'] = ! empty( $out['ffmpeg']['available'] ) && ! empty( $out['ffprobe']['available'] );

		set_transient( self::CACHE_AVAIL, $out, self::CACHE_TTL );

		return $out;
	}

	/**
	 * Run `<binary> -version` and interpret the result.
	 *
	 * @param string $binary Binary path.
	 * @param string $label  Human label.
	 * @return array<string,mixed>
	 */
	public function probe_binary( $binary, $label ) {
		$result = array(
			'available' => false,
			'path'      => (string) $binary,
			'version'   => '',
			'error'     => '',
		);

		if ( '' === $binary ) {
			$result['error'] = sprintf(
				/* translators: %s: binary name. */
				__( '%s path is not configured.', 'viral-video-ai' ),
				ucfirst( $label )
			);

			return $result;
		}

		if ( ! VVAI_Process::binary_is_safe( $binary ) ) {
			$result['error'] = sprintf(
				/* translators: %s: binary path. */
				__( 'The configured path "%s" is not an allowed executable (it must exist, be readable, and contain no shell syntax).', 'viral-video-ai' ),
				$binary
			);

			return $result;
		}

		try {
			$run = VVAI_Process::run( array( $binary, '-version' ), array( 'timeout' => 20 ) );
		} catch ( \Throwable $throwable ) {
			// Never fatal a page over a missing binary: report it instead.
			$result['error'] = sprintf(
				/* translators: %s: error message. */
				__( '%s could not be probed: %s', 'viral-video-ai' ),
				ucfirst( $label ),
				$throwable->getMessage()
			);

			return $result;
		}

		if ( '' !== $run['error'] && 0 !== (int) $run['code'] ) {
			$result['error'] = $run['error'];

			return $result;
		}

		$payload = trim( (string) $run['stdout'] . "\n" . (string) $run['stderr'] );
		$first   = strtok( $payload, "\n" );

		if ( 0 === (int) $run['code'] && is_string( $first ) && false !== stripos( $first, $label ) ) {
			$result['available'] = true;
			$result['version']   = substr( $first, 0, 160 );

			// Extract just the version token for display.
			if ( preg_match( '/version\s+([0-9][A-Za-z0-9.\-]*)/', (string) $first, $m ) ) {
				$result['version_number'] = $m[1];
			}

			// Encode capability discovery drives the quality options we offer.
			if ( 'ffmpeg' === $label ) {
				$result['encoders'] = $this->detect_encoders( $binary, $payload );
			}
		} else {
			$result['error'] = sprintf(
				/* translators: %s: binary name. */
				__( '%s exited with code %d instead of reporting a version. Check the path in the plugin settings.', 'viral-video-ai' ),
				ucfirst( $label ),
				(int) $run['code']
			);
		}

		return $result;
	}

	/**
	 * Detect which encoders this build exposes from the -version banner/help.
	 *
	 * @param string $binary  ffmpeg path.
	 * @param string $banner  Version banner.
	 * @return array<string,bool>
	 */
	private function detect_encoders( $binary, $banner ) {
		unset( $banner );

		$encoders = array();

		// `ffmpeg -loglevel error -encoders` is one extra process but reliable and
		// cached for five minutes by availability().
		try {
			$run = VVAI_Process::run( array( $binary, '-hide_banner', '-loglevel', 'error', '-encoders' ), array( 'timeout' => 20 ) );
		} catch ( \Throwable $throwable ) {
			return $encoders;
		}

		$out = strtolower( (string) $run['stdout'] . (string) $run['stderr'] );

		foreach ( array( 'libx264', 'libx265', 'aac', 'libmp3lame', 'libvpx-vp9', 'libaom-av1', 'h264_nvenc', 'libass', 'subtitles' ) as $needle ) {
			$encoders[ $needle ] = ( false !== strpos( $out, $needle ) );
		}

		return $encoders;
	}

	/**
	 * Read technical metadata with ffprobe.
	 *
	 * @param string $path Absolute media path.
	 * @return array<string,mixed>|WP_Error
	 */
	public function inspect( $path ) {
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'missing_source', __( 'The source video is not on the server any more. Re-upload it (or retry the job).', 'viral-video-ai' ) );
		}

		$args = array(
			$this->ffprobe_path(),
			'-v', 'error',
			'-print_format', 'json',
			'-show_format',
			'-show_streams',
			$path,
		);

		$run = VVAI_Process::run( $args, array( 'timeout' => 120 ) );
		$out = (string) $run['stdout'];

		if ( '' === trim( $out ) ) {
			$out = (string) $run['stderr'];
		}

		if ( 0 !== (int) $run['code'] ) {
			return new WP_Error(
				'ffprobe_failed',
				sprintf(
					/* translators: %s: ffprobe message. */
					__( 'FFprobe could not read this file (%1$s). The file may be corrupt, or the container is not supported by this FFmpeg build.', 'viral-video-ai' ),
					$this->short_error( (string) $run['stderr'] . ' ' . $out )
				),
				array( 'code' => (int) $run['code'] )
			);
		}

		$json = $this->extract_json( $out );

		if ( null === $json ) {
			return new WP_Error( 'ffprobe_unparseable', __( 'FFprobe returned output that could not be parsed.', 'viral-video-ai' ) );
		}

		return $this->parse_probe( $json, $path );
	}

	/**
	 * Turn an ffprobe payload into the plugin's normalized metadata.
	 *
	 * @param array<string,mixed> $json Decoded ffprobe output.
	 * @param string              $path Source path.
	 * @return array<string,mixed>
	 */
	public function parse_probe( array $json, $path = '' ) {
		$meta = array(
			'duration'     => 0.0,
			'width'        => 0,
			'height'       => 0,
			'fps'          => 0.0,
			'vcodec'       => '',
			'acodec'       => '',
			'has_audio'    => false,
			'audio_channels' => 0,
			'audio_sample_rate' => 0,
			'bitrate'      => 0,
			'rotation'     => 0,
			'format'       => '',
			'size'         => ( $path && is_file( $path ) ) ? (int) filesize( $path ) : 0,
			'streams'      => array(),
			'nb_streams'   => 0,
			'multiple_videos' => false,
		);

		if ( isset( $json['format'] ) && is_array( $json['format'] ) ) {
			$format         = $json['format'];
			$meta['format'] = sanitize_text_field( (string) vvai_array_get( $format, 'format_name', vvai_array_get( $format, 'format_long_name', '' ) ) );

			if ( is_numeric( vvai_array_get( $format, 'duration' ) ) ) {
				$meta['duration'] = round( (float) $format['duration'], 3 );
			}

			if ( is_numeric( vvai_array_get( $format, 'bit_rate' ) ) ) {
				$meta['bitrate'] = (int) $format['bit_rate'];
			}

			if ( is_numeric( vvai_array_get( $format, 'size' ) ) && $meta['size'] <= 0 ) {
				$meta['size'] = (int) $format['size'];
			}

			if ( is_numeric( vvai_array_get( $format, 'nb_streams' ) ) ) {
				$meta['nb_streams'] = (int) $format['nb_streams'];
			}
		}

		$video_count = 0;

		if ( isset( $json['streams'] ) && is_array( $json['streams'] ) ) {
			foreach ( $json['streams'] as $stream ) {
				if ( ! is_array( $stream ) ) {
					continue;
				}

				$type = (string) vvai_array_get( $stream, 'codec_type', '' );

				if ( 'video' === $type && '' === $meta['vcodec'] ) {
					$video_count++;

					$meta['vcodec'] = sanitize_text_field( (string) vvai_array_get( $stream, 'codec_name', '' ) );
					$meta['width']  = (int) vvai_array_get( $stream, 'width', 0 );
					$meta['height'] = (int) vvai_array_get( $stream, 'height', 0 );

					// `display matrix` rotation means the stored width/height are
					// swapped relative to what the viewer sees.
					$rotation = $this->stream_rotation( $stream );

					if ( 90 === $rotation || 270 === $rotation ) {
						$swap          = $meta['width'];
						$meta['width']  = $meta['height'];
						$meta['height'] = $swap;
					}

					$meta['rotation'] = $rotation;

					if ( ! empty( $stream['avg_frame_rate'] ) ) {
						$meta['fps'] = $this->parse_rate( $stream['avg_frame_rate'] );
					} elseif ( ! empty( $stream['r_frame_rate'] ) ) {
						$meta['fps'] = $this->parse_rate( $stream['r_frame_rate'] );
					}

					if ( $meta['duration'] <= 0 && is_numeric( vvai_array_get( $stream, 'duration' ) ) ) {
						$meta['duration'] = round( (float) $stream['duration'], 3 );
					}

					$meta['streams'][] = array(
						'type'   => 'video',
						'codec'  => $meta['vcodec'],
						'index'  => (int) vvai_array_get( $stream, 'index', 0 ),
						'width'  => $meta['width'],
						'height' => $meta['height'],
					);
				} elseif ( 'audio' === $type && '' === $meta['acodec'] ) {
					$meta['has_audio']      = true;
					$meta['acodec']         = sanitize_text_field( (string) vvai_array_get( $stream, 'codec_name', '' ) );
					$meta['audio_channels'] = (int) vvai_array_get( $stream, 'channels', 0 );

					if ( is_numeric( vvai_array_get( $stream, 'sample_rate' ) ) ) {
						$meta['audio_sample_rate'] = (int) $stream['sample_rate'];
					}

					$meta['streams'][] = array(
						'type'    => 'audio',
						'codec'   => $meta['acodec'],
						'index'   => (int) vvai_array_get( $stream, 'index', 0 ),
						'channels' => $meta['audio_channels'],
					);
				}
			}
		}

		$meta['multiple_videos'] = $video_count > 1;

		if ( $meta['duration'] <= 0 ) {
			// Some streams (e.g. certain WebM/Pipe inputs) lack a container
			// duration; ask for it explicitly instead of assuming.
			$measured = $this->measure_duration( $path );

			if ( ! is_wp_error( $measured ) ) {
				$meta['duration'] = $measured;
			}
		}

		return $meta;
	}

	/**
	 * Measure duration by decoding with -f null (accurate fallback).
	 *
	 * @param string $path Media path.
	 * @return float|WP_Error
	 */
	public function measure_duration( $path ) {
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'missing_source', __( 'The source file is missing.', 'viral-video-ai' ) );
		}

		$run = VVAI_Process::run(
			array(
				$this->ffmpeg_path(),
				'-hide_banner',
				'-nostdin',
				'-i', $path,
				'-map', '0:v:0',
				'-f', 'null',
				'-',
			),
			array( 'timeout' => 300 )
		);

		$payload = (string) $run['stdout'] . (string) $run['stderr'];

		if ( preg_match( '/time=(\d+):(\d+):(\d{2})(?:\.(\d+))?/', $payload, $m ) ) {
			return round( ( (int) $m[1] * 3600 ) + ( (int) $m[2] * 60 ) + (int) $m[3] + (float) ( '0.' . ( $m[4] ?? '0' ) ), 3 );
		}

		return new WP_Error( 'duration_unknown', __( 'The video duration could not be determined.', 'viral-video-ai' ) );
	}

	/**
	 * Rotation from side data or tags.
	 *
	 * @param array<string,mixed> $stream Stream node.
	 * @return int
	 */
	private function stream_rotation( array $stream ) {
		if ( isset( $stream['side_data_list'] ) && is_array( $stream['side_data_list'] ) ) {
			foreach ( $stream['side_data_list'] as $side ) {
				if ( is_array( $side ) && isset( $side['rotation'] ) && is_numeric( $side['rotation'] ) ) {
					// Normalise to 0..359: iOS stores -90, and PHP's % keeps the sign.
					$degrees = ( (int) round( (float) $side['rotation'] ) ) % 360;

					return $degrees < 0 ? $degrees + 360 : $degrees;
				}
			}
		}

		if ( isset( $stream['tags']['rotate'] ) && is_numeric( $stream['tags']['rotate'] ) ) {
			return (int) $stream['tags']['rotate'];
		}

		return 0;
	}

	/**
	 * Parse "30000/1001" style rates.
	 *
	 * @param mixed $rate Rate string or number.
	 * @return float
	 */
	private function parse_rate( $rate ) {
		if ( is_numeric( $rate ) ) {
			return (float) $rate;
		}

		$rate = (string) $rate;

		if ( false !== strpos( $rate, '/' ) ) {
			list( $num, $den ) = array_pad( explode( '/', $rate, 2 ), 2, '1' );

			$den = (float) $den;

			return ( $den > 0 ) ? round( ( (float) $num ) / $den, 3 ) : 0.0;
		}

		return (float) $rate;
	}

	/**
	 * Extract audio for transcription.
	 *
	 * Mono 16 kHz 32 kbps MP3: the smallest container Whisper-compatible
	 * providers accept, which keeps the upload payload small for hour-long
	 * sources (~14 MB/hour).
	 *
	 * @param string $source Source media path.
	 * @param string $target Output .mp3 path.
	 * @param array  $args   {start, duration, timeout, sample_rate}.
	 * @return array{ok:bool,size:int,duration:float,code:string,message:string,stderr:string}|WP_Error
	 */
	public function extract_audio( $source, $target, array $args = array() ) {
		if ( ! is_file( $source ) ) {
			return new WP_Error( 'missing_source', __( 'The source video is not available on the server.', 'viral-video-ai' ) );
		}

		$directory = dirname( $target );

		if ( ! is_dir( $directory ) && ! vvai_mkdir( $directory ) ) {
			return new WP_Error( 'unwritable_target', __( 'The plugin cannot write to its temporary folder. Check the uploads directory permissions.', 'viral-video-ai' ) );
		}

		$argv = array( $this->ffmpeg_path(), '-hide_banner', '-nostdin', '-y', '-loglevel', 'error' );
		$start = (float) vvai_array_get( $args, 'start', 0 );

		if ( $start > 0 ) {
			$argv[] = '-ss';
			$argv[] = (string) round( $start, 3 );
		}

		$argv[] = '-i';
		$argv[] = $source;

		$length = (float) vvai_array_get( $args, 'duration', 0 );

		if ( $length > 0 ) {
			$argv[] = '-t';
			$argv[] = (string) round( $length, 3 );
		}

		$argv[] = '-vn';
		$argv[] = '-map';
		$argv[] = '0:a:0';
		$argv[] = '-ac';
		$argv[] = '1';
		$argv[] = '-ar';
		$argv[] = (string) max( 8000, min( 48000, (int) vvai_array_get( $args, 'sample_rate', 16000 ) ) );
		$argv[] = '-c:a';
		$argv[] = 'libmp3lame';
		$argv[] = '-b:a';
		$argv[] = '32k';

		$extra = $this->extra_args();

		if ( $extra ) {
			$argv = array_merge( $argv, $extra );
		}

		$argv[] = $target;

		$timeout = (int) vvai_array_get( $args, 'timeout', $this->settings->get( 'process_timeout' ) );
		$run     = VVAI_Process::run( $argv, array( 'timeout' => max( 60, min( 7200, $timeout ) ) ) );

		if ( 0 !== (int) $run['code'] || ! is_file( $target ) || filesize( $target ) < 512 ) {
			$message = $this->short_error( (string) $run['stderr'] . ' ' . (string) $run['stdout'] );

			return new WP_Error(
				'audio_extraction_failed',
				sprintf(
					/* translators: %s: ffmpeg message. */
					__( 'FFmpeg could not extract audio from this video. %s', 'viral-video-ai' ),
					( '' !== $message ? $message : __( 'The file may have no audio track.', 'viral-video-ai' ) )
				),
				array(
					'exit_code' => (int) $run['code'],
					'no_audio'   => $this->looks_like_no_audio( $message ),
				)
			);
		}

		return array(
			'ok'       => true,
			'size'     => (int) filesize( $target ),
			'duration' => $length > 0 ? $length : $this->quick_duration( $target ),
			'code'     => 'ok',
			'message'  => '',
			'stderr'   => '',
		);
	}

	/**
	 * Duration of a generated file, cheaply.
	 *
	 * @param string $path Path.
	 * @return float
	 */
	public function quick_duration( $path ) {
		if ( ! is_file( $path ) ) {
			return 0.0;
		}

		$run = VVAI_Process::run(
			array(
				$this->ffprobe_path(),
				'-v', 'error',
				'-show_entries', 'format=duration',
				'-of', 'default=noprint_wrappers=1:nokey=1',
				$path,
			),
			array( 'timeout' => 45 )
		);

		$value = trim( (string) $run['stdout'] );

		return is_numeric( $value ) ? round( (float) $value, 3 ) : 0.0;
	}

	/**
	 * Render one clip.
	 *
	 * @param string $source Source media.
	 * @param string $target Output mp4 path.
	 * @param array  $args   {
	 *     @type float  $start        Start seconds.
	 *     @type float  $end          End seconds.
	 *     @type string $aspect       9:16|16:9|1:1|4:5.
	 *     @type string $quality      720p|1080p|4k.
	 *     @type array  $source_meta  ffprobe metadata.
	 *     @type string $crop_mode    center|smart.
	 *     @type array  $crop         {x,y,w,h} override.
	 *     @type string $srt          Subtitle file to burn in (optional).
	 *     @type bool   $copy_audio   Copy vs re-encode audio.
	 *     @type int    $timeout      Hard timeout.
	 * }
	 * @return array<string,mixed>|WP_Error Result map with ok, filters, target,
	 *                                      width, height, upscaled, warnings.
	 */
	public function render_clip( $source, $target, array $args = array() ) {
		if ( ! is_file( $source ) ) {
			return new WP_Error( 'missing_source', __( 'The source video is not available on the server.', 'viral-video-ai' ) );
		}

		$availability = $this->availability();

		if ( empty( $availability['ok'] ) ) {
			return new WP_Error(
				'ffmpeg_unavailable',
				__( 'FFmpeg or FFprobe is not available on this server, so clips cannot be rendered. Set the correct paths in Viral Video AI → Settings.', 'viral-video-ai' )
			);
		}

		$start = max( 0.0, (float) vvai_array_get( $args, 'start', 0 ) );
		$end   = (float) vvai_array_get( $args, 'end', 0 );
		$length = round( $end - $start, 3 );

		if ( $length < 0.5 ) {
			return new WP_Error( 'invalid_range', __( 'The requested clip range is too short to render.', 'viral-video-ai' ) );
		}

		$plan = $this->build_render_plan( $args );

		$directory = dirname( $target );

		if ( ! is_dir( $directory ) && ! vvai_mkdir( $directory ) ) {
			return new WP_Error( 'unwritable_target', __( 'The plugin cannot write the clip folder. Check the uploads directory permissions.', 'viral-video-ai' ) );
		}

		// Remove a partial file from an earlier attempt: `stat()` on leftovers
		// would otherwise report success for a truncated render.
		if ( is_file( $target ) ) {
			@unlink( $target );
		}

		$argv = array(
			$this->ffmpeg_path(),
			'-hide_banner',
			'-nostdin',
			'-y',
			'-loglevel', 'error',
			'-ss', (string) $start,
			'-i', $source,
			'-t', (string) $length,
			'-map', '0:v:0',
		);

		$has_audio = ! empty( $plan['source']['has_audio'] );

		if ( $has_audio ) {
			$argv[] = '-map';
			$argv[] = '0:a:0?';
		}

		if ( $plan['filters'] ) {
			$argv[] = '-vf';
			$argv[] = $plan['filters'];
		}

		$argv = array_merge( $argv, $plan['encode_args'] );

		$extra = $this->extra_args();

		if ( $extra ) {
			$argv = array_merge( $argv, $extra );
		}

		$argv[] = $target;

		$timeout = (int) vvai_array_get( $args, 'timeout', $this->settings->get( 'process_timeout' ) );
		$started = microtime( true );
		$run     = VVAI_Process::run( $argv, array( 'timeout' => max( 60, min( 14400, $timeout ) ) ) );

		if ( 0 !== (int) $run['code'] ) {
			$message = $this->short_error( (string) $run['stderr'] . ' ' . (string) $run['stdout'] );

			return new WP_Error(
				'render_failed',
				sprintf(
					/* translators: %s: FFmpeg error text. */
					__( 'FFmpeg exited with code %1$d while rendering this clip. %2$s', 'viral-video-ai' ),
					(int) $run['code'],
					$message
				),
				array(
					'exit_code' => (int) $run['code'],
					'stderr'    => substr( (string) $run['stderr'], 0, 1000 ),
				)
			);
		}

		if ( ! is_file( $target ) || filesize( $target ) < 1024 ) {
			return new WP_Error( 'render_empty_output', __( 'FFmpeg reported success but produced no playable file. Check that the source stream is intact.', 'viral-video-ai' ) );
		}

		$verified = $this->verify_output( $target, $length );

		return array(
			'ok'          => true,
			'target'      => $target,
			'size'        => (int) filesize( $target ),
			'duration'    => $verified['duration'],
			'width'       => $verified['width'],
			'height'      => $verified['height'],
			'filters'     => $plan['filters'],
			'encode_args' => $plan['encode_args'],
			'upscaled'    => $plan['upscaled'],
			'real_fps'    => $plan['fps'],
			'warnings'    => $plan['warnings'],
			'render_time' => round( microtime( true ) - $started, 2 ),
			'crop'        => $plan['crop'],
			'duration_ok' => $verified['ok'],
			'seek_delta'  => $verified['delta'],
		);
	}

	/**
	 * Compute the complete render plan (resolution, crop, filters, encoding).
	 *
	 * Public so the UI can preview "what will happen" without rendering, and so
	 * add-ons can inspect/override the plan through the filter below.
	 *
	 * @param array<string,mixed> $args Render args.
	 * @return array<string,mixed>
	 */
	public function build_render_plan( array $args ) {
		$source  = (array) vvai_array_get( $args, 'source_meta', array() );
		$aspect  = (string) vvai_array_get( $args, 'aspect', '9:16' );
		$quality = (string) vvai_array_get( $args, 'quality', '1080p' );

		if ( ! in_array( $aspect, array( '9:16', '16:9', '1:1', '4:5' ), true ) ) {
			$aspect = '9:16';
		}

		if ( ! in_array( $quality, array( '720p', '1080p', '4k' ), true ) ) {
			$quality = '1080p';
		}

		// Orientation decides which axis the quality label refers to: the short
		// side for vertical output, the height for landscape/square output.
		if ( '9:16' === $aspect ) {
			$mode = 'vertical';
		} elseif ( '16:9' === $aspect ) {
			$mode = 'horizontal';
		} else {
			$mode = 'square';
		}

		list( $ratio_w, $ratio_h ) = VVAI_Settings::ratio_factors( $aspect );
		$target_height = VVAI_Settings::quality_height( $quality );

		if ( 'vertical' === $mode ) {
			// For vertical output the quality label is the short (width) side.
			$target_width  = $target_height;
			$target_height = (int) round( $target_width * ( $ratio_h / $ratio_w ) );
		} else {
			$target_width = (int) round( $target_height * ( $ratio_w / $ratio_h ) );
		}

		$src_width  = max( 2, (int) vvai_array_get( $source, 'width', 0 ) );
		$src_height = max( 2, (int) vvai_array_get( $source, 'height', 0 ) );

		if ( $src_width < 4 || $src_height < 4 ) {
			$src_width  = $target_width;
			$src_height = $target_height;
		}

		$warnings = array();
		$upscaled = false;

		// Even dimensions are mandatory for yuv420p / H.264.
		$target_width  = $this->even( $target_width );
		$target_height = $this->even( $target_height );

		// `scale=W:H:force_original_aspect_ratio=increase` resizes with the LARGER of
			// the two fit factors so the frame covers the target box; whatever falls
			// outside has to be cropped. Keep that geometry in one place: the plan, the
			// crop resolver and the tests all derive from it.
		$cover = $this->cover_geometry( $src_width, $src_height, $target_width, $target_height );

		// Never upscale (spec §18): if covering the target box means inventing
		// pixels, clamp the output to the source's own size instead and say so.
		// "Don't upscale" is judged on the SHORT side, which is what a quality label
		// like 1080p actually means for vertical output: a 1920x1080 source has all
		// the detail a 1080x1920 frame needs (the horizontal narrowing comes from
		// cropping, not from inventing pixels).
		$detail_fit = min( $src_width, $src_height ) / max( 2, min( $target_width, $target_height ) );

		if ( $detail_fit < 0.999 && ! $this->settings->get( 'allow_upscale' ) ) {
			$fit = $detail_fit;

			$target_width  = $this->even( (int) floor( $target_width * $fit ) );
			$target_height = $this->even( (int) floor( $target_height * $fit ) );
			$cover         = $this->cover_geometry( $src_width, $src_height, $target_width, $target_height );
			$upscaled      = true;

			$warnings[] = sprintf(
				/* translators: 1: requested resolution label, 2: real output width, 3: real output height. */
				__( 'The source is smaller than %1$s, so the clip was rendered at its native size (%2$dx%3$d) instead of being upscaled.', 'viral-video-ai' ),
				strtoupper( (string) $quality ),
				$target_width,
				$target_height
			);
		}

		$crop_mode = (string) vvai_array_get( $args, 'crop_mode', $this->settings->get( 'crop_mode' ) );
		$crop      = $this->resolve_crop( $args, $src_width, $src_height, $target_width, $target_height, $mode, $crop_mode, $warnings );

		$filters = array();

		// Baked-in phone rotation must be applied first: everything after this point
		// is described in the orientation the viewer actually sees.
		if ( in_array( (int) vvai_array_get( $source, 'rotation', 0 ), array( 90, 270 ), true ) ) {
			$filters[] = 'transpose=' . ( 90 === (int) $source['rotation'] ? '1' : '2' );
		}

		if ( ! $crop['needed'] ) {
			// Source already matches the target ratio: fit and pad, never crop.
			$filters[] = sprintf( 'scale=%d:%d:force_original_aspect_ratio=decrease:flags=bicubic', $target_width, $target_height );
			// pad's x/y expressions expose iw/ih (the input frame), not w/h:
			// using the wrong constant makes FFmpeg abort with
			// "Undefined constant or missing '(' in 'w)/2'".
			$filters[] = sprintf( 'pad=%d:%d:(%d-iw)/2:(%d-ih)/2:color=black', $target_width, $target_height, $target_width, $target_height );
		} else {
			// Cover-then-crop: scale so the frame covers the target box, then crop
			// the overflow at the chosen offset. Keeps the subject visible while
			// guaranteeing an exact aspect ratio.
			$filters[] = sprintf( 'scale=%d:%d:force_original_aspect_ratio=increase:flags=bicubic', $target_width, $target_height );

			if ( $crop['needed'] ) {
				$filters[] = sprintf( 'crop=%d:%d:%s:%s', $target_width, $target_height, $crop['x'], $crop['y'] );
			}
		}

		$filters[] = 'setsar=1';

		if ( ! empty( $args['srt'] ) && is_file( (string) $args['srt'] ) ) {
			$subtitle_file = $this->safe_subtitle_path( (string) $args['srt'] );

			if ( '' !== $subtitle_file ) {
				$filters[] = 'subtitles=' . $this->ffmpeg_escape_path( $subtitle_file );
			} else {
				$warnings[] = __( 'The caption file could not be used for burn-in (unsafe path), so the clip was rendered without burned-in captions.', 'viral-video-ai' );
			}
		}

		$encode = array(
			'-c:v', 'libx264',
			'-preset', (string) $this->settings->get( 'encode_preset' ),
			'-crf', (string) (int) $this->settings->get( 'video_crf' ),
			'-pix_fmt', 'yuv420p',
			'-profile:v', 'high',
			'-movflags', '+faststart',
		);

		if ( ! empty( $source['fps'] ) && (float) $source['fps'] > 0 && (float) $source['fps'] <= 30 ) {
			// Keep a modest frame cap so 25/30 fps sources do not become 60 fps.
			$encode[] = '-r';
			$encode[] = (string) min( 30, (float) $source['fps'] );
		}

		if ( ! empty( $source['has_audio'] ) ) {
			$encode = array_merge( $encode, array( '-c:a', 'aac', '-b:a', (string) $this->settings->get( 'audio_bitrate' ), '-ac', '2' ) );
		} else {
			$encode = array_merge( $encode, array( '-an' ) );
		}

		$plan = array(
			'filters'      => implode( ',', $filters ),
			'encode_args'  => $encode,
			'width'        => $target_width,
			'height'       => $target_height,
			'crop'         => $crop,
			'upscaled'     => $upscaled,
			'warnings'     => $warnings,
			'mode'         => $mode,
			'fps'          => (float) vvai_array_get( $source, 'fps', 0 ),
			'source'       => array(
				'has_audio' => (bool) vvai_array_get( $source, 'has_audio', false ),
				'width'     => $src_width,
				'height'    => $src_height,
			),
		);

		/**
		 * Filter the assembled render plan.
		 *
		 * Add-ons (AI reframing, background music, silence removal) can extend
		 * `filters` / `encode_args` here without touching the renderer.
		 *
		 * @param array $plan Plan.
		 * @param array $args  Render arguments.
		 */
		return apply_filters( 'vvai_render_plan', $plan, $args );
	}

	/**
	 * Decide the crop window for the clip.
	 *
	 * @param array  $args          Render args.
	 * @param int    $src_width     Source width.
	 * @param int    $src_height    Source height.
	 * @param int    $target_width  Target width.
	 * @param int    $target_height Target height.
	 * @param string $mode          vertical|horizontal|square.
	 * @param string $crop_mode     center|smart.
	 * @param array  $warnings      Warnings collected by reference.
	 * @return array<string,mixed>
	 */
	private function resolve_crop( array $args, $src_width, $src_height, $target_width, $target_height, $mode, $crop_mode, array &$warnings ) {
		$crop = array(
			'x'      => 0,
			'y'      => 0,
			'w'      => $target_width,
			'h'      => $target_height,
			'needed' => false,
			'mode'   => 'center',
			'confidence' => 0.0,
			'letterbox' => array( 'top' => 0, 'bottom' => 0, 'left' => 0, 'right' => 0 ),
		);

		// Measure the overflow in SCALED space: `crop=W:H:x:y` runs after the scale
		// stage, so both the limits and the offsets live in that frame.
		$cover    = $this->cover_geometry( $src_width, $src_height, $target_width, $target_height );
		$overflow = $cover['overflow'];

		$crop['overflow'] = $overflow;
		$crop['scale']    = $cover['scale'];
		$crop['scaled']   = array( $cover['width'], $cover['height'] );

		if ( ( $overflow['x'] + $overflow['y'] ) > 1 ) {
			$crop['needed'] = true;
		}

		if ( ! $crop['needed'] ) {
			return $crop;
		}

		$explicit = isset( $args['crop'] ) && is_array( $args['crop'] ) ? $args['crop'] : null;

		if ( $explicit && isset( $explicit['x'], $explicit['y'] ) ) {
			$crop['x']    = max( 0, (int) $explicit['x'] );
			$crop['y']    = max( 0, (int) $explicit['y'] );
			$crop['mode'] = 'manual';

			return $crop;
		}

		if ( 'smart' !== $crop_mode ) {
			$crop['mode'] = 'center';
			$crop['x']    = (int) floor( $overflow['x'] / 2 );
			$crop['y']    = (int) floor( $overflow['y'] / 2 );

			return $crop;
		}

		$analysis = $this->analyze_composition( $args, $src_width, $src_height, $target_width, $target_height );

		if ( empty( $analysis['ok'] ) ) {
			$warnings[] = __( 'Smart cropping could not analyse this video, so the centre crop was used.', 'viral-video-ai' );
			$crop['mode'] = 'center';
			$crop['x']    = (int) floor( $overflow['x'] / 2 );
			$crop['y']    = (int) floor( $overflow['y'] / 2 );

			return $crop;
		}

		$crop['mode']       = 'smart';
		$crop['x']          = $analysis['x'];
		$crop['y']          = $analysis['y'];
		$crop['confidence'] = $analysis['confidence'];
		$crop['letterbox']  = $analysis['letterbox'];
		$crop['subject']    = $analysis['subject'];

		return $crop;
	}

	/**
	 * Composition analysis for 9:16 reframing.
	 *
	 * Runs FFmpeg's own `cropdetect` over sampled windows to find the real
	 * content box (removing letterbox bars) and to derive where the visual mass
	 * sits, then clamps the crop window inside that box. This is the
	 * future-ready seam: a face/person tracker registers itself through
	 * `vvai_crop_analysis` and replaces this heuristic entirely.
	 *
	 * @param array $args         Render args (needs source path + range).
	 * @param int   $src_width    Source width.
	 * @param int   $src_height   Source height.
	 * @param int   $target_width Target width.
	 * @param int   $target_height Target height.
	 * @return array{ok:bool,x:int,y:int,confidence:float,letterbox:array,subject:array}
	 */
	public function analyze_composition( array $args, $src_width, $src_height, $target_width, $target_height ) {
		$empty = array(
			'ok'         => false,
			'x'          => 0,
			'y'          => 0,
			'confidence' => 0.0,
			'letterbox'  => array( 'top' => 0, 'bottom' => 0, 'left' => 0, 'right' => 0 ),
			'subject'    => array(
				'x' => (int) round( $src_width / 2 ),
				'y' => (int) round( $src_height / 2 ),
				'w' => $src_width,
				'h' => $src_height,
			),
		);

		$source = (string) vvai_array_get( $args, 'source', '' );

		if ( '' === $source || ! is_file( $source ) ) {
			return $empty;
		}

		$analysis = apply_filters( 'vvai_crop_analysis', null, $args );

		if ( is_array( $analysis ) ) {
			// A tracking engine already solved this.
			return wp_parse_args(
				$analysis,
				array_merge(
					$empty,
					array(
						'x' => (int) round( max( 0, min( $src_width - $target_width, ( (int) $analysis['x'] ?? 0 ) ) ) ),
						'y' => (int) round( max( 0, min( $src_height - $target_height, ( (int) $analysis['y'] ?? 0 ) ) ) ),
					)
				)
			);
		}

		$start    = max( 0.0, (float) vvai_array_get( $args, 'start', 0 ) );
		$length   = max( 1.0, (float) vvai_array_get( $args, 'end', 1 ) - $start );
		$samples  = apply_filters( 'vvai_crop_analysis_samples', 5 );
		$samples  = max( 1, min( 9, (int) $samples ) );
		$found    = array();

		for ( $i = 0; $i < $samples; $i++ ) {
			$point = $start + ( ( $i + 0.5 ) * ( $length / $samples ) );

			$run = VVAI_Process::run(
				array(
					$this->ffmpeg_path(),
					'-hide_banner',
					'-nostdin',
					'-loglevel', 'info',
					'-ss', (string) round( max( 0, $point ), 3 ),
					'-t', '1',
					'-i', $source,
					'-an',
					'-vf', 'cropdetect=24:16:0',
					'-f', 'null',
					'-',
				),
				array( 'timeout' => 60 )
			);

			$payload = (string) $run['stdout'] . (string) $run['stderr'];

			if ( preg_match_all( '/\[cropdetect[^\]]*\]\s*crop=(\d+):(\d+):(\d+):(\d+)/', $payload, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $match ) {
					$found[] = array(
						'w' => (int) $match[1],
						'h' => (int) $match[2],
						'x' => (int) $match[3],
						'y' => (int) $match[4],
					);
				}
			}
		}

		if ( ! $found ) {
			return $empty;
		}

		// Median of each component: robust against a single black-frame sample.
		$median = static function ( $values ) {
			sort( $values, SORT_NUMERIC );
			$count = count( $values );

			return (int) round( $values[ (int) floor( $count / 2 ) ] );
		};

		$content = array(
			'w' => $median( wp_list_pluck( $found, 'w' ) ),
			'h' => $median( wp_list_pluck( $found, 'h' ) ),
			'x' => $median( wp_list_pluck( $found, 'x' ) ),
			'y' => $median( wp_list_pluck( $found, 'y' ) ),
		);

		// Guard against a bogus result (all-black frames report tiny boxes).
		if ( $content['w'] < $target_width || $content['h'] < $target_height || $content['w'] <= 0 ) {
			$content = array(
				'w' => $src_width,
				'h' => $src_height,
				'x' => 0,
				'y' => 0,
			);
		}

		$content['w'] = min( $content['w'], $src_width );
		$content['h'] = min( $content['h'], $src_height );

		// Everything below happens in SCALED space: the filter chain is
		// scale=target:force_original_aspect_ratio=increase followed by
		// crop=W:H:x:y, so the offsets belong to the scaled frame, not the source.
		$scale = max(
			$target_width / max( 1, $src_width ),
			$target_height / max( 1, $src_height )
		);

		$scaled_width  = (int) round( $src_width * $scale );
		$scaled_height = (int) round( $src_height * $scale );
		$max_x = max( 0, $scaled_width - $target_width );
		$max_y = max( 0, $scaled_height - $target_height );

		// Centre of the detected content box, mapped into scaled space.
		$center_x = ( $content['x'] + ( $content['w'] / 2 ) ) * $scale;
		$center_y = ( $content['y'] + ( $content['h'] / 2 ) ) * $scale;

		$x = (int) round( max( 0, min( $max_x, $center_x - ( $target_width / 2 ) ) ) );
		$y = (int) round( max( 0, min( $max_y, $center_y - ( $target_height / 2 ) ) ) );

		$letterbox_height = max( 0, $src_height - $content['h'] );

		return array(
			'ok'         => true,
			'x'          => $x,
			'y'          => $y,
			'confidence' => round( min( 1.0, ( $content['w'] * $content['h'] ) / max( 1, $src_width * $src_height ) ), 3 ),
			'letterbox'  => array(
				'top'    => $content['y'],
				'bottom' => $letterbox_height - $content['y'],
				'left'   => $content['x'],
				'right'  => max( 0, $src_width - $content['x'] - $content['w'] ),
			),
			'subject'    => array(
				'x' => (int) round( $content['x'] ),
				'y' => (int) round( $content['y'] ),
				'w' => (int) min( $target_width, $content['w'] ),
				'h' => (int) min( $target_height, $content['h'] ),
			),
			'offset_x'   => $x,
			'offset_y'   => $y,
			'scale'      => round( $scale, 4 ),
			'scaled'     => array( $scaled_width, $scaled_height ),
		);
	}

	/**
	 * Verify a rendered file really is playable and the right length.
	 *
	 * @param string $path   Clip path.
	 * @param float  $expected Expected duration.
	 * @return array{ok:bool,duration:float,width:int,height:int,delta:float}
	 */
	public function verify_output( $path, $expected ) {
		$meta = array(
			'ok'       => false,
			'duration' => 0.0,
			'width'    => 0,
			'height'   => 0,
			'delta'    => 0.0,
		);

		if ( ! is_file( $path ) ) {
			return $meta;
		}

		$run = VVAI_Process::run(
			array(
				$this->ffprobe_path(),
				'-v', 'error',
				'-show_entries', 'stream=codec_type,width,height,codec_name:format=duration',
				'-of', 'json',
				$path,
			),
			array( 'timeout' => 60 )
		);

		$json = $this->extract_json( (string) $run['stdout'] );

		if ( ! is_array( $json ) ) {
			// Fall back to a size/duration heuristic so a render is never reported
			// as successful on an empty file.
			$meta['duration'] = $this->quick_duration( $path );
			$meta['ok']       = ( $meta['duration'] > 0 );
			$meta['delta']    = round( abs( $meta['duration'] - $expected ), 2 );

			return $meta;
		}

		$parsed = $this->parse_probe( $json, $path );

		$meta['duration'] = (float) $parsed['duration'];
		$meta['width']    = (int) $parsed['width'];
		$meta['height']   = (int) $parsed['height'];
		$meta['delta']    = round( abs( $meta['duration'] - $expected ), 2 );

		// ±0.75 s tolerance: keyframe seek + audio priming legitimately shift the
		// container duration a little.
		$meta['ok'] = ( $meta['width'] > 0 && $meta['height'] > 0 && $meta['delta'] <= 0.75 );

		return $meta;
	}

	/**
	 * Geometry of `scale=W:H:force_original_aspect_ratio=increase`.
	 *
	 * Shared by the plan builder and the crop resolver so the two can never disagree
	 * about how much overflow exists (that disagreement is what produces black bars
	 * or an "Invalid crop size" FFmpeg abort).
	 *
	 * @param int $src_width     Source width.
	 * @param int $src_height    Source height.
	 * @param int $target_width  Desired output width.
	 * @param int $target_height Desired output height.
	 * @return array{scale:float,width:int,height:int,overflow:array{x:int,y:int}}
	 */
	public function cover_geometry( $src_width, $src_height, $target_width, $target_height ) {
		$src_width     = max( 2, (int) $src_width );
		$src_height    = max( 2, (int) $src_height );
		$target_width  = max( 2, (int) $target_width );
		$target_height = max( 2, (int) $target_height );

		$scale  = max( $target_width / $src_width, $target_height / $src_height );
		$width  = (int) round( $src_width * $scale );
		$height = (int) round( $src_height * $scale );

		return array(
			'scale'    => $scale,
			'width'    => $width,
			'height'   => $height,
			'overflow' => array(
				'x' => max( 0, $width - $target_width ),
				'y' => max( 0, $height - $target_height ),
			),
		);
	}

	/**
	 * Round to the nearest even integer >= 2.
	 *
	 * @param int $value Value.
	 * @return int
	 */
	private function even( $value ) {
		$value = max( 2, (int) $value );

		return ( $value % 2 ) ? $value - 1 : $value;
	}

	/**
	 * Extra arguments configured by the administrator (already sanitized).
	 *
	 * @return string[]
	 */
	private function extra_args() {
		$extra = trim( (string) $this->settings->get( 'ffmpeg_extra_args' ) );

		if ( '' === $extra ) {
			return array();
		}

		return array_values( array_filter( explode( ' ', $extra ) ) );
	}

	/**
	 * Only allow burning in subtitle files that the plugin itself wrote.
	 *
	 * @param string $path Candidate path.
	 * @return string Safe path or empty string.
	 */
	private function safe_subtitle_path( $path ) {
		$root = vvai_storage_dir();
		$path = wp_normalize_path( (string) $path );
		$root = wp_normalize_path( $root );

		if ( '' === $path || 0 !== strpos( $path, $root ) || ! is_file( $path ) ) {
			return '';
		}

		if ( 'srt' !== strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * Escape a path for use inside an FFmpeg filtergraph argument.
	 *
	 * Filtergraph parsing happens *after* shell parsing, so ':' and '\\' need
	 * escaping again — this is a classic command-injection footgun and is
	 * handled once, here.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function ffmpeg_escape_path( $path ) {
		// Two escaping layers matter here:
		//   1. the filtergraph parser, where ':' and '\' separate filters/options;
		//   2. the filename option's own quoting.
		// `subtitles=filename='…'` is the documented, portable form; wrapping the
		// argument in bare parentheses (a common copy-paste) is NOT valid and makes
		// FFmpeg abort the whole render.
		$path = str_replace( array( '\\', ':', "'" ), array( '\\\\\\\\', '\\:', "\\'" ), (string) $path );

		return "filename='" . $path . "'";
	}

	/**
	 * Pull the first JSON document out of a mixed stdout/stderr payload.
	 *
	 * @param string $payload Raw output.
	 * @return array<string,mixed>|null
	 */
	private function extract_json( $payload ) {
		$payload = trim( (string) $payload );

		if ( '' === $payload ) {
			return null;
		}

		$decoded = json_decode( $payload, true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$blocks = VVAI_Json::find_balanced_blocks( $payload );

		foreach ( $blocks as $block ) {
			$decoded = json_decode( $block, true );

			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Trim FFmpeg noise down to something an admin can act on.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	private function short_error( $message ) {
		$message = trim( (string) $message );

		if ( '' === $message ) {
			return '';
		}

		// Remove progress lines and ANSI noise, keep the last meaningful error.
		$lines = preg_split( '/\r\n|\n|\r/', $message );
		$kept  = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line || preg_match( '/^\s*frame=|\d+x\d+|\d+fps=|size=\d+kB time=|bitrate=/', $line ) ) {
				continue;
			}

			$kept[] = $line;
		}

		$message = implode( ' ', array_slice( $kept, -4 ) );
		$message = preg_replace( '/\s+/', ' ', $message );

		return substr( (string) $message, 0, 400 );
	}

	/**
	 * Recognise "this video has no audio track" from FFmpeg output.
	 *
	 * @param string $message Error text.
	 * @return bool
	 */
	private function looks_like_no_audio( $message ) {
		return (bool) preg_match( '/does not contain any (stream|audio)|Stream map .* matches no streams|Output file .* does not have any stream/i', (string) $message );
	}
}
