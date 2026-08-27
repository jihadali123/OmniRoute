<?php
/**
 * Audio transcription.
 *
 * Extracts (or reuses) the mono 16 kHz audio track, splits it into windows when
 * the video is long, and hands each window to whichever engine the site
 * configured:
 *
 *   connection  – the provider's own endpoint (OpenAI / Groq / Gemini / custom)
 *   custom      – any OpenAI-compatible /audio/transcriptions endpoint
 *   whisper-cli – a local whisper binary, no external API at all
 *   auto        – first of the above that is actually usable
 *
 * Every engine's output is normalized to [{start,end,text}] with absolute
 * seconds before the AI ever sees it.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Transcription
 */
class VVAI_Transcription {

	/**
	 * HTTP transport.
	 *
	 * @var VVAI_Api_Connection
	 */
	private $api;

	/**
	 * FFmpeg gateway.
	 *
	 * @var VVAI_FFMPEG
	 */
	private $ffmpeg;

	/**
	 * Settings.
	 *
	 * @var VVAI_Settings
	 */
	private $settings;

	/**
	 * Connections.
	 *
	 * @var VVAI_Connection_Store
	 */
	private $connections;

	/**
	 * Logger.
	 *
	 * @var VVAI_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param VVAI_Api_Connection|null $api         Transport.
	 * @param VVAI_FFMPEG|null         $ffmpeg      FFmpeg gateway.
	 * @param VVAI_Settings|null       $settings    Settings.
	 * @param VVAI_Connection_Store|null $connections Connections.
	 * @param VVAI_Logger|null         $logger      Logger.
	 */
	public function __construct( $api = null, $ffmpeg = null, $settings = null, $connections = null, $logger = null ) {
		$this->api         = $api instanceof VVAI_Api_Connection ? $api : new VVAI_Api_Connection();
		$this->ffmpeg      = $ffmpeg instanceof VVAI_FFMPEG ? $ffmpeg : new VVAI_FFMPEG();
		$this->settings    = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
		$this->connections = $connections instanceof VVAI_Connection_Store ? $connections : new VVAI_Connection_Store();
		$this->logger      = $logger instanceof VVAI_Logger ? $logger : new VVAI_Logger( $this->settings );
	}

	/**
	 * Decide which engine will be used and whether it can work.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return array{kind:string,reason:string,message:string}
	 */
	public function choose_engine( array $job ) {
		$wanted = (string) $this->settings->get( 'transcription_source' );

		if ( 'disabled' === $wanted ) {
			return array(
				'kind'    => 'none',
				'reason'  => 'disabled',
				'message' => __( 'Transcription is switched off in the plugin settings, so clips can only be cut from AI analysis of the metadata.', 'viral-video-ai' ),
			);
		}

		$connection = $this->connection_for( $job );

		switch ( $wanted ) {
			case 'whisper-cli':
				return $this->local_cli_available()
					? array(
						'kind'    => 'whisper-cli',
						'reason'  => '',
						'message' => __( 'Local Whisper binary', 'viral-video-ai' ),
					)
					: array(
						'kind'    => 'none',
						'reason'  => 'whisper_binary_missing',
						'message' => __( 'The local transcription binary configured in the settings cannot be executed. Check the path in Viral Video AI → Settings.', 'viral-video-ai' ),
					);

			case 'custom':
				return $this->custom_endpoint_available()
					? array(
						'kind'    => 'custom',
						'reason'  => '',
						'message' => __( 'Custom transcription endpoint', 'viral-video-ai' ),
					)
					: array(
						'kind'    => 'none',
						'reason'  => 'custom_endpoint_missing',
						'message' => __( 'No usable custom transcription endpoint is configured (a full https:// URL to an OpenAI-compatible /audio/transcriptions route is required).', 'viral-video-ai' ),
					);

			case 'connection':
				if ( ! $connection ) {
					return array(
						'kind'    => 'none',
						'reason'  => 'no_connection',
						'message' => __( 'Please connect an AI provider before processing videos.', 'viral-video-ai' ),
					);
				}

				return VVAI_Api_Manager::provider_can_transcribe( (string) $connection['provider'] )
					? array(
						'kind'    => 'provider',
						'reason'  => '',
						'message' => sprintf(
							/* translators: %s: provider label. */
							__( '%s transcription endpoint', 'viral-video-ai' ),
							VVAI_Api_Manager::label_for( (string) $connection['provider'] )
						),
					)
					: array(
						'kind'    => 'none',
						'reason'  => 'provider_cannot_transcribe',
						'message' => sprintf(
							/* translators: %s: provider label. */
							__( 'The connected provider (%s) has no transcription endpoint. Switch transcription to a custom endpoint, to OpenAI/Groq, or to a local Whisper binary.', 'viral-video-ai' ),
							VVAI_Api_Manager::label_for( (string) $connection['provider'] )
						),
					);

			case 'auto':
			default:
				if ( $connection && VVAI_Api_Manager::provider_can_transcribe( (string) $connection['provider'] ) ) {
					return array(
						'kind'    => 'provider',
						'reason'  => '',
						'message' => sprintf(
							/* translators: %s: provider label. */
							__( '%s transcription endpoint', 'viral-video-ai' ),
							VVAI_Api_Manager::label_for( (string) $connection['provider'] )
						),
					);
				}

				if ( $this->custom_endpoint_available() ) {
					return array(
						'kind'    => 'custom',
						'reason'  => '',
						'message' => __( 'Custom transcription endpoint', 'viral-video-ai' ),
					);
				}

				if ( $this->local_cli_available() ) {
					return array(
						'kind'    => 'whisper-cli',
						'reason'  => '',
						'message' => __( 'Local Whisper binary', 'viral-video-ai' ),
					);
				}

				return array(
					'kind'    => 'none',
					'reason'  => 'no_transcription_engine',
					'message' => __( 'No transcription engine is available. Connect an OpenAI or Groq API key (they transcribe audio), configure a custom OpenAI-compatible transcription endpoint, or point the plugin at a local Whisper binary.', 'viral-video-ai' ),
				);
		}
	}

	/**
	 * Transcribe a job's audio.
	 *
	 * @param array<string,mixed> $job      Job row.
	 * @param callable|null       $progress Receives (int $done, int $total).
	 * @return array{ok:bool,segments:array<int,array<string,mixed>>,engine:string,words:int,duration:float,code:string,message:string,hint:string,chunks:int,failed_chunks:int,partial:bool}
	 */
	public function transcribe( array $job, $progress = null ) {
		$result = array(
			'ok'          => false,
			'segments'    => array(),
			'engine'      => '',
			'words'       => 0,
			'duration'    => 0.0,
			'code'        => '',
			'message'     => '',
			'hint'        => '',
			'chunks'      => 0,
			'failed_chunks' => 0,
			'partial'     => false,
		);

		$audio = (string) vvai_array_get( $job, 'audio_path', '' );

		if ( '' === $audio ) {
			$directory = vvai_storage_dir( 'tmp/job-' . (int) $job['id'] );
			$audio     = $directory . '/audio.mp3';
		}

		if ( ! is_file( $audio ) ) {
			$result['code']    = 'missing_audio';
			$result['message'] = __( 'The extracted audio file is missing. Re-run the job from the audio extraction step.', 'viral-video-ai' );

			return $result;
		}

		$engine = $this->choose_engine( $job );
		$result['engine'] = $engine['kind'];

		if ( 'none' === $engine['kind'] ) {
			$result['code']    = (string) $engine['reason'];
			$result['message'] = $engine['message'];

			return $result;
		}

		$duration  = (float) ( vvai_array_get( $job, 'duration', 0 ) ?: $this->ffmpeg->quick_duration( $audio ) );
		$chunk_len = max( 60, (int) $this->settings->get( 'transcription_chunk_minutes' ) * 60 );
		$windows   = $this->plan_windows( $duration, $chunk_len );

		$result['duration'] = $duration;
		$result['chunks']    = count( $windows );

		$segments = array();
		$failed   = 0;

		foreach ( $windows as $index => $window ) {
			$chunk_audio = $audio;

			if ( count( $windows ) > 1 ) {
				$chunk_audio = $this->slice_audio( $audio, $index, $window );

				if ( is_wp_error( $chunk_audio ) ) {
					$failed++;
					$result['message'] = $chunk_audio->get_error_message();
					continue;
				}
			}

			$attempt = $this->transcribe_window( $job, $engine['kind'], $chunk_audio, $window );

			if ( is_wp_error( $attempt ) ) {
				$failed++;
				$result['code']    = (string) $attempt->get_error_code();
				$result['message'] = $attempt->get_error_message();
				$result['hint']    = (string) $attempt->get_error_data();

				$this->logger->warning(
					'Transcription window failed',
					array(
						'job'    => (int) $job['id'],
						'window' => $index + 1,
						'engine' => $engine['kind'],
						'error'  => $attempt->get_error_message(),
					)
				);
			} else {
				$segments = array_merge( $segments, $attempt );
			}

			if ( is_callable( $progress ) ) {
				call_user_func( $progress, $index + 1, count( $windows ) );
			}

			if ( count( $windows ) > 1 && is_file( $chunk_audio ) && 0 === strpos( $chunk_audio, vvai_storage_dir() ) ) {
				@unlink( $chunk_audio );
			}
		}

		$normalized = VVAI_Transcript::normalize( $segments, 0.0, $duration );
		$result['words']  = str_word_count( strip_tags( implode( ' ', wp_list_pluck( $normalized, 'text' ) ) ) );
		$result['segments'] = $normalized;
		$result['failed_chunks'] = $failed;

		if ( ! $normalized ) {
			if ( '' === $result['message'] ) {
				$result['message'] = __( 'The transcription engine returned no speech. The video may contain no audible dialogue.', 'viral-video-ai' );
				$result['code']    = 'empty_transcription';
				$result['hint']    = __( 'If the video is music-only or silent, clips can still be cut automatically from the audio energy instead.', 'viral-video-ai' );
			}

			return $result;
		}

		$result['ok']      = true;
		$result['partial'] = ( $failed > 0 );
		$result['code']    = 'ok';

		if ( $failed > 0 ) {
			$result['message'] = sprintf(
				/* translators: 1: failed chunks, 2: total chunks. */
				__( '%1$d of %2$d audio windows could not be transcribed; clips were selected from the part that succeeded.', 'viral-video-ai' ),
				$failed,
				count( $windows )
			);
		}

		return $result;
	}

	/**
	 * Transcribe one audio window.
	 *
	 * @param array<string,mixed> $job    Job row.
	 * @param string              $kind   Engine kind.
	 * @param string              $audio  Audio path.
	 * @param array{start:float,end:float,index:int} $window Window.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	protected function transcribe_window( array $job, $kind, $audio, array $window ) {
		$args = array(
			'offset'   => (float) $window['start'],
			'duration' => ( max( 1.0, (float) $window['end'] - (float) $window['start'] ) ),
			'language' => (string) $this->settings->get( 'transcript_language' ),
			'job_hint' => 'job:' . (int) $job['id'],
			'prompt'   => $this->context_hint( $job ),
		);

		switch ( $kind ) {
			case 'provider':
				$connection = $this->connection_for( $job );

				if ( ! $connection ) {
					return new WP_Error( 'no_connection', __( 'The AI connection disappeared while transcribing.', 'viral-video-ai' ) );
				}

				$router = new VVAI_AI_Router( $this->connections, new VVAI_Api_Manager( $this->api, $this->logger, $this->settings ), $this->settings, $this->logger );
				$out    = $router->transcribe( $connection, $audio, $args );

				if ( empty( $out['ok'] ) ) {
					return new WP_Error(
						(string) ( $out['code'] ?: 'transcription_failed' ),
						(string) ( $out['message'] ?: __( 'The provider could not transcribe this audio.', 'viral-video-ai' ) ),
						(string) vvai_array_get( $out, 'hint', '' )
					);
				}

				return (array) $out['segments'];

			case 'custom':
				$record = array(
					'id'         => 'custom-transcription',
					'provider'   => 'openai',
					'title'      => __( 'Custom transcription endpoint', 'viral-video-ai' ),
					'api_key'    => (string) $this->settings->get( 'transcription_api_key' ),
					'base_url'   => (string) $this->settings->get( 'transcription_base_url' ),
					'model'      => (string) $this->settings->get( 'transcription_model' ),
					'timeout'    => 600,
				);

				$adapter = new VVAI_OpenAI_Provider( $this->api, $this->logger, $this->settings );
				$out     = $adapter->transcribe( $record, $audio, $args );

				if ( empty( $out['ok'] ) ) {
					return new WP_Error(
						(string) ( $out['code'] ?: 'transcription_failed' ),
						(string) ( $out['message'] ?: __( 'The custom transcription endpoint failed.', 'viral-video-ai' ) ),
						(string) vvai_array_get( $out, 'hint', '' )
					);
				}

				return (array) $out['segments'];

			case 'whisper-cli':
				return $this->transcribe_with_cli( $audio, $args );
		}

		return new WP_Error( 'no_transcription_engine', __( 'No transcription engine is configured.', 'viral-video-ai' ) );
	}

	/**
	 * Local whisper binary path (verified).
	 *
	 * @return string
	 */
	public function whisper_binary() {
		$configured = (string) $this->settings->get( 'whisper_binary' );

		if ( '' === $configured ) {
			foreach ( array( 'whisper-cpp', 'whisper.cpp', 'whisper', 'main' ) as $candidate ) {
				$found = VVAI_Process::locate( $candidate );

				if ( '' !== $found && is_file( $found ) ) {
					return $found;
				}
			}

			return '';
		}

		return VVAI_Process::binary_is_safe( $configured ) && is_file( $configured ) ? $configured : '';
	}

	/**
	 * Can the local CLI be used?
	 *
	 * @return bool
	 */
	public function local_cli_available() {
		return '' !== $this->whisper_binary() && VVAI_Process::capability()['available'];
	}

	/**
	 * Is a custom endpoint configured and valid?
	 *
	 * @return bool
	 */
	public function custom_endpoint_available() {
		$url = (string) $this->settings->get( 'transcription_base_url' );

		return '' !== $url && VVAI_Connection_Store::is_valid_endpoint( $url );
	}

	/**
	 * Transcribe with a local whisper/whisper.cpp binary (no external API).
	 *
	 * @param string $audio Audio path.
	 * @param array  $args   {offset, language, timeout}.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	protected function transcribe_with_cli( $audio, array $args ) {
		$binary = $this->whisper_binary();

		if ( '' === $binary ) {
			return new WP_Error( 'whisper_binary_missing', __( 'The configured local transcription binary could not be found or executed.', 'viral-video-ai' ) );
		}

		$out_dir = dirname( $audio ) . '/whisper-' . vvai_random_id( 6 );

		if ( ! vvai_mkdir( $out_dir ) ) {
			return new WP_Error( 'whisper_output_dir', __( 'The plugin cannot write the transcription output folder.', 'viral-video-ai' ) );
		}

		// whisper.cpp CLI: `-oj` writes `<input>.json` next to the input.
		$argv = array(
			$binary,
			'-m', $this->whisper_model_path(),
			'-f', $audio,
			'-oj',
			'-l', ( '' !== (string) $args['language'] ) ? (string) $args['language'] : 'auto',
			'-tp', '0.0',
		);

		$argv = (array) apply_filters( 'vvai_whisper_cli_args', $argv, $audio, $args );

		$run = VVAI_Process::run( $argv, array( 'timeout' => max( 120, (int) $this->settings->get( 'process_timeout' ) ) ) );

		$json = $out_dir . '/nothing';

		// Locate the produced JSON (whisper.cpp writes beside the input file).
		$candidates = array(
			dirname( $audio ) . '/' . pathinfo( $audio, PATHINFO_FILENAME ) . '.json',
			$out_dir . '/' . pathinfo( $audio, PATHINFO_FILENAME ) . '.json',
		);

		unset( $json );

		$path = '';

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				$path = $candidate;
				break;
			}
		}

		if ( '' === $path ) {
			@unlink( $out_dir );

			$message = $this->cli_error( (string) $run['stderr'] . ' ' . (string) $run['stdout'] );

			return new WP_Error(
				'whisper_cli_failed',
				sprintf(
					/* translators: %s: binary output. */
					__( 'The local transcription binary failed (exit %d). %s', 'viral-video-ai' ),
					(int) $run['code'],
					$message
				)
			);
		}

		$decoded = vvai_json_decode( file_get_contents( $path ) );

		@unlink( $path );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'whisper_cli_output', __( 'The local transcription binary produced output that could not be parsed.', 'viral-video-ai' ) );
		}

		$segments = isset( $decoded['segments'] ) && is_array( $decoded['segments'] ) ? $decoded['segments'] : array();

		if ( ! $segments && isset( $decoded['transcription'] ) && is_array( $decoded['transcription'] ) ) {
			// faster-whisper / openai-whisper JSON layouts.
			foreach ( $decoded['transcription'] as $entry ) {
				$segments[] = array(
					'start' => vvai_array_get( $entry, 'offset', 0 ),
					'end'   => vvai_array_get( $entry, 'duration', 0 ),
					'text'  => vvai_array_get( $entry, 'text', '' ),
				);
			}
		}

		return VVAI_Transcript::normalize( $segments, (float) $args['offset'] );
	}

	/**
	 * Whisper model path (optional, only for whisper.cpp).
	 *
	 * @return string
	 */
	protected function whisper_model_path() {
		$model = (string) $this->settings->get( 'whisper_model' );

		return ( '' !== $model && is_file( $model ) ) ? $model : 'models/ggml-base.en.bin';
	}

	/**
	 * Build the transcription windows for a duration.
	 *
	 * @param float $duration  Total duration.
	 * @param int   $chunk_len Seconds per window.
	 * @return array<int,array{index:int,start:float,end:float}>
	 */
	public function plan_windows( $duration, $chunk_len ) {
		$duration = max( 0.0, (float) $duration );
		$chunk_len = max( 60, (int) $chunk_len );

		if ( $duration <= 0 ) {
			// Unknown duration: one window, the engine decides where it ends.
			return array(
				array(
					'index' => 0,
					'start' => 0.0,
					'end'   => 0.0,
				),
			);
		}

		$windows = array();
		$start   = 0.0;
		$index   = 0;

		while ( $start < $duration ) {
			$end = min( $duration, $start + $chunk_len );

			$windows[] = array(
				'index' => $index,
				'start' => round( $start, 2 ),
				'end'   => round( $end, 2 ),
			);

			$start = $end;
			$index++;

			if ( $index > 400 ) {
				// Absolute guard against a pathological loop on bogus metadata.
				break;
			}
		}

		return $windows;
	}

	/**
	 * Cut one audio window out of the master audio file.
	 *
	 * @param string $audio  Master audio.
	 * @param int    $index  Window index.
	 * @param array  $window Window.
	 * @return string|WP_Error Path to the chunk.
	 */
	protected function slice_audio( $audio, $index, array $window ) {
		$target = dirname( $audio ) . sprintf( '/chunk-%03d.mp3', (int) $index );

		$length = (float) $window['end'] - (float) $window['start'];

		// Small tail so a sentence crossing the boundary is not clipped mid-word.
		$result = $this->ffmpeg->extract_audio(
			$audio,
			$target,
			array(
				'start'      => (float) $window['start'],
				'duration'   => max( 1.0, $length + 1.0 ),
				'sample_rate' => 16000,
				'timeout'    => max( 120, (int) $this->settings->get( 'process_timeout' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $target;
	}

	/**
	 * Connection to use for provider transcription.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return array<string,mixed>|null
	 */
	protected function connection_for( array $job ) {
		$id = (string) vvai_array_get( $job, 'connection_id', '' );

		if ( '' !== $id ) {
			$record = $this->connections->get( $id );

			if ( $record && VVAI_Connection_Store::STATUS_CONNECTED === (string) $record['status'] ) {
				return $record;
			}
		}

		return $this->connections->get_active( true );
	}

	/**
	 * Vocabulary hint that improves names/technical terms in the transcript.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return string
	 */
	protected function context_hint( array $job ) {
		$title = (string) vvai_array_get( $job, 'title', '' );
		$hints = (array) apply_filters( 'vvai_transcription_hint', array(), $job );

		$hint = trim( $title . ' ' . implode( ' ', array_map( 'strval', $hints ) ) );

		return substr( preg_replace( '/\s+/', ' ', $hint ), 0, 400 );
	}

	/**
	 * Turn binary noise into a usable message.
	 *
	 * @param string $raw Output.
	 * @return string
	 */
	protected function cli_error( $raw ) {
		$raw = trim( wp_strip_all_tags( (string) $raw ) );

		if ( '' === $raw ) {
			return '';
		}

		if ( false !== stripos( $raw, 'failed to open' ) || false !== stripos( $raw, 'no such file' ) ) {
			return __( 'The model file could not be opened — check the whisper model path.', 'viral-video-ai' );
		}

		return substr( $raw, -240 );
	}
}
