<?php
/**
 * The processing pipeline.
 *
 * One job moves through real stages, each of which is executed inside its own
 * short PHP request (spec §36): inspect → extract audio → transcribe → analyze →
 * select clips → render clips → finalize. A stage returns as soon as the time
 * budget is exhausted and the queue re-triggers the job, so a 90-minute source
 * can be processed on a shared host without ever hitting max_execution_time.
 *
 * Nothing here pretends: a stage that did not run leaves the job in the same
 * status, and a failure records the reason plus the stage to resume from.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Video_Processor
 */
class VVAI_Video_Processor {

	/**
	 * Lock lifetime for one processing tick.
	 */
	const LOCK_TTL = 1800;

	/**
	 * @var VVAI_Job_Manager
	 */
	private $jobs;

	/**
	 * @var VVAI_FFMPEG
	 */
	private $ffmpeg;

	/**
	 * @var VVAI_Transcription
	 */
	private $transcription;

	/**
	 * @var VVAI_AI_Analyzer
	 */
	private $analyzer;

	/**
	 * @var VVAI_Clip_Generator
	 */
	private $clips;

	/**
	 * @var VVAI_AI_Router
	 */
	private $router;

	/**
	 * @var VVAI_Settings
	 */
	private $settings;

	/**
	 * @var VVAI_Logger
	 */
	private $logger;

	/**
	 * @var VVAI_Connection_Store
	 */
	private $connections;

	/**
	 * Constructor.
	 *
	 * @param VVAI_Plugin|null $plugin Container; when null the services resolve
	 *                                  themselves (used by the test harness).
	 */
	public function __construct( $plugin = null ) {
		if ( $plugin instanceof VVAI_Plugin ) {
			$this->jobs        = $plugin->jobs();
			$this->ffmpeg      = $plugin->ffmpeg();
			$this->transcription = $plugin->transcription();
			$this->analyzer    = $plugin->analyzer();
			$this->clips       = $plugin->clip_generator();
			$this->router      = $plugin->router();
			$this->settings    = $plugin->settings();
			$this->logger      = $plugin->logger();
			$this->connections = $plugin->connections();

			return;
		}

		$this->settings      = new VVAI_Settings();
		$this->logger        = new VVAI_Logger( $this->settings );
		$this->connections   = new VVAI_Connection_Store();
		$this->jobs          = new VVAI_Job_Manager();
		$this->ffmpeg        = new VVAI_FFMPEG( $this->settings );
		$this->clips         = new VVAI_Clip_Generator( $this->ffmpeg, $this->settings, new VVAI_Clip_Repository() );
		$this->router        = new VVAI_AI_Router( $this->connections, new VVAI_Api_Manager( null, $this->logger, $this->settings ), $this->settings, $this->logger );
		$this->transcription = new VVAI_Transcription( null, $this->ffmpeg, $this->settings, $this->connections, $this->logger );
		$this->analyzer      = new VVAI_AI_Analyzer( $this->router, $this->settings, $this->logger );
	}

	/**
	 * Process one job, advancing as many stages as the time budget allows.
	 *
	 * @param int $job_id Job id.
	 * @return array{status:string,progress:int,stage:string,advanced:int,waiting:bool,message:string}
	 */
	public function process( $job_id ) {
		$job_id = (int) $job_id;
		$job    = $this->jobs->get( $job_id );

		$out = array(
			'status'   => $job ? (string) $job['status'] : VVAI_Job_Status::FAILED,
			'progress' => $job ? (int) $job['progress'] : 0,
			'stage'    => $job ? (string) $job['stage'] : '',
			'advanced' => 0,
			'waiting'  => false,
			'message'  => '',
		);

		if ( ! $job ) {
			$out['status']  = VVAI_Job_Status::FAILED;
			$out['message'] = __( 'Job not found.', 'viral-video-ai' );

			return $out;
		}

		if ( VVAI_Job_Status::is_terminal( (string) $job['status'] ) ) {
			$out['message'] = __( 'This job is already finished.', 'viral-video-ai' );

			return $out;
		}

		if ( ! $this->jobs->claim( $job_id, self::LOCK_TTL ) ) {
			$out['waiting'] = true;
			$out['message'] = __( 'Another process is already working on this job.', 'viral-video-ai' );

			return $out;
		}

		$budget  = max( 5, (int) $this->settings->get( 'max_execution_budget' ) );
		$started = microtime( true );

		try {
			// A job that is merely "created" must pass through the upload gate.
			if ( in_array( (string) $job['status'], array( VVAI_Job_Status::QUEUED, VVAI_Job_Status::UPLOADING, VVAI_Job_Status::UPLOADED ), true ) ) {
				$job = $this->enter_pipeline( $job );

				if ( VVAI_Job_Status::FAILED === (string) $job['status'] ) {
					$out['status']  = VVAI_Job_Status::FAILED;
					$out['message'] = (string) vvai_array_get( $job, 'error_message', '' );

					$this->jobs->release( $job_id );

					return $out;
				}
			}

			while ( ! VVAI_Job_Status::is_terminal( (string) $job['status'] ) ) {
				$stage = (string) $job['stage'];

				// Re-read the job so a filter/add-on can change course between stages.
				$fresh = $this->jobs->get( $job_id );

				if ( $fresh ) {
					$job = $fresh;
				}

				$result = $this->run_stage( $job, $stage );

				$out['advanced']++;
				$out['stage']    = (string) $job['stage'];
				$out['status']   = (string) $job['status'];
				$out['progress'] = (int) $job['progress'];

				if ( 'failed' === $result ) {
					$out['message'] = (string) vvai_array_get( $job, 'error_message', '' );
					break;
				}

				if ( 'waiting' === $result ) {
					$out['waiting'] = true;
					break;
				}

				if ( VVAI_Job_Status::is_terminal( (string) $job['status'] ) ) {
					break;
				}

				if ( ( microtime( true ) - $started ) > $budget ) {
					$out['waiting'] = true;
					$out['message'] = __( 'Time budget reached; the job continues in the background.', 'viral-video-ai' );

					$this->jobs->set_progress( $job_id, (int) $job['progress'], (string) $job['stage'] );

					break;
				}

				$this->jobs->renew_lock( $job_id, self::LOCK_TTL );

				$next = VVAI_Job_Status::next( $stage );

				if ( '' === $next ) {
					break;
				}

				if ( $next !== (string) $job['stage'] && 'render' !== $result ) {
					$this->jobs->set_stage( $job_id, $next );
					$job['stage']  = $next;
					$job['status'] = $next;
					$out['stage']  = $next;
					$out['status'] = $next;
				}
			}
		} catch ( \Throwable $throwable ) {
			// A PHP error inside FFmpeg glue or a provider adapter must still land
			// as a readable failure instead of a job stuck in "analyzing" forever.
			$this->fail(
				$job_id,
				'server_error',
				sprintf(
					/* translators: 1: exception class, 2: message. */
					__( 'Unexpected server error while processing (%1$s): %2$s', 'viral-video-ai' ),
					get_class( $throwable ),
					$throwable->getMessage()
				),
				(string) $job['stage'],
				$this->retry_stage_for( (string) $job['stage'] )
			);

			$this->logger->critical(
				'Pipeline exception',
				array(
					'job'   => $job_id,
					'class' => get_class( $throwable ),
					'line'  => (int) $throwable->getLine(),
				)
			);

			$out['status']  = VVAI_Job_Status::FAILED;
			$out['message'] = 'exception';
		}

		$this->jobs->release( $job_id );

		$latest = $this->jobs->get( $job_id );

		if ( $latest ) {
			$out['status']   = (string) $latest['status'];
			$out['progress'] = (int) $latest['progress'];
			$out['stage']    = (string) $latest['stage'];
		}

		return $out;
	}

	/**
	 * Decide the entry stage and validate the prerequisites.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return array<string,mixed>
	 */
	protected function enter_pipeline( array $job ) {
		$job_id = (int) $job['id'];

		// Server prerequisites first: failing after an hour of transcription
		// because ffmpeg is missing would be cruel.
		$availability = $this->ffmpeg->availability();

		if ( empty( $availability['ok'] ) ) {
			$this->fail(
				$job_id,
				'ffmpeg_unavailable',
				__( 'FFmpeg is not usable on this server, so clips cannot be generated. ' ) . (string) vvai_array_get( $availability['ffmpeg'], 'error', '' ),
				(string) $job['stage'],
				'inspect'
			);

			return (array) $this->jobs->get( $job_id );
		}

		$problem = $this->router->connection_problem( (string) vvai_array_get( (array) $job['settings_array'], 'connection_id', '' ) );

		if ( '' !== $problem ) {
			$this->fail( $job_id, 'no_ai_connection', $problem, (string) $job['stage'], 'inspect' );

			return (array) $this->jobs->get( $job_id );
		}

		$source = (string) vvai_array_get( $job, 'source_path', '' );

		if ( '' === $source || ! is_file( $source ) ) {
			$this->fail(
				$job_id,
				'missing_source',
				__( 'The uploaded video is not on the server any more. Upload it again or pick a different file.', 'viral-video-ai' ),
				(string) $job['stage'],
				'inspect'
			);

			return (array) $this->jobs->get( $job_id );
		}

		// A retry may resume later in the pipeline; only start from inspect when
		// the metadata is not usable yet.
		$has_meta = ( (float) vvai_array_get( $job, 'duration', 0 ) > 0 ) && ( (int) vvai_array_get( $job, 'width', 0 ) > 0 );
		$stage    = (string) vvai_array_get( $job, 'stage', '' );
		$resume   = (string) vvai_array_get( $job, 'retry_from', '' );

		if ( '' !== $resume && isset( VVAI_Job_Status::retry_targets()[ $resume ] ) ) {
			$stage = VVAI_Job_Status::retry_targets()[ $resume ];
		} elseif ( ! $has_meta || ! VVAI_Job_Status::is_stage( $stage ) || in_array( $stage, array( VVAI_Job_Status::QUEUED, VVAI_Job_Status::UPLOADING, VVAI_Job_Status::UPLOADED ), true ) ) {
			$stage = VVAI_Job_Status::INSPECTING;
		}

		$this->jobs->update(
			$job_id,
			array(
				'status'    => $stage,
				'stage'     => $stage,
				'progress'  => VVAI_Job_Status::progress_for( $stage ),
				'file_size' => (int) filesize( $source ),
			)
		);

		return (array) $this->jobs->get( $job_id );
	}

	/**
	 * Dispatch one stage.
	 *
	 * @param array<string,mixed> $job   Job row (updated by reference).
	 * @param string              $stage Stage key.
	 * @return string done|continue|waiting|failed|render
	 */
	protected function run_stage( array &$job, $stage ) {
		switch ( $stage ) {
			case VVAI_Job_Status::INSPECTING:
				return $this->stage_inspect( $job );

			case VVAI_Job_Status::EXTRACTING_AUDIO:
				return $this->stage_extract_audio( $job );

			case VVAI_Job_Status::TRANSCRIBING:
				return $this->stage_transcribe( $job );

			case VVAI_Job_Status::ANALYZING:
				return $this->stage_analyze( $job );

			case VVAI_Job_Status::SELECTING_CLIPS:
				return $this->stage_select_clips( $job );

			case VVAI_Job_Status::RENDERING:
				return $this->stage_render( $job );

			case VVAI_Job_Status::FINALIZING:
				return $this->stage_finalize( $job );
		}

		$this->fail(
			(int) $job['id'],
			'unknown_stage',
			sprintf(
				/* translators: %s: stage name. */
				__( 'The job is in an unknown stage (%s) and cannot continue.', 'viral-video-ai' ),
				$stage
			),
			$stage,
			'inspect'
		);

		return 'failed';
	}

	/**
	 * ffprobe metadata.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return string
	 */
	protected function stage_inspect( array &$job ) {
		$job_id = (int) $job['id'];
		$source = (string) vvai_array_get( $job, 'source_path', '' );
		$meta   = $this->ffmpeg->inspect( $source );

		if ( is_wp_error( $meta ) ) {
			$this->fail( $job_id, (string) $meta->get_error_code(), $meta->get_error_message(), VVAI_Job_Status::INSPECTING, 'inspect' );
			$job = (array) $this->jobs->get( $job_id );

			return 'failed';
		}

		$duration = (float) vvai_array_get( $meta, 'duration', 0 );

		if ( $duration <= 0 ) {
			$this->fail(
				$job_id,
				'unreadable_video',
				__( 'FFprobe could not read a duration from this file. It may be corrupt, truncated, or use a codec this server cannot decode.', 'viral-video-ai' ),
				VVAI_Job_Status::INSPECTING,
				'inspect'
			);
			$job = (array) $this->jobs->get( $job_id );

			return 'failed';
		}

		$this->jobs->update(
			$job_id,
			array(
				'duration'  => $duration,
				'width'     => (int) vvai_array_get( $meta, 'width', 0 ),
				'height'    => (int) vvai_array_get( $meta, 'height', 0 ),
				'fps'       => (float) vvai_array_get( $meta, 'fps', 0 ),
				'vcodec'    => (string) vvai_array_get( $meta, 'vcodec', '' ),
				'acodec'    => (string) vvai_array_get( $meta, 'acodec', '' ),
				'has_audio' => (int) vvai_array_get( $meta, 'has_audio', 0 ),
				'rotation'  => (int) vvai_array_get( $meta, 'rotation', 0 ),
				'file_size' => (int) vvai_array_get( $meta, 'size', 0 ),
			)
		);

		// Refuse a source that cannot possibly contain a clip of the requested size.
		$settings = (array) $job['settings_array'];
		$bounds   = $this->analyzer->bounds( $settings, $duration );

		if ( empty( $bounds['ok'] ) ) {
			$this->fail( $job_id, (string) $bounds['code'], (string) $bounds['message'], VVAI_Job_Status::INSPECTING, 'inspect' );
			$job = (array) $this->jobs->get( $job_id );

			return 'failed';
		}

		$job = (array) $this->jobs->get( $job_id );

		$this->logger->info(
			'Video inspected',
			array(
				'job'      => $job_id,
				'duration' => $duration,
				'size'     => sprintf( '%dx%d', (int) $meta['width'], (int) $meta['height'] ),
				'audio'    => (int) vvai_array_get( $meta, 'has_audio', 0 ),
			)
		);

		return 'continue';
	}

	/**
	 * Audio extraction.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return string
	 */
	protected function stage_extract_audio( array &$job ) {
		$job_id = (int) $job['id'];
		$audio  = $this->audio_path( $job_id );

		if ( is_file( $audio ) && filesize( $audio ) > 4096 ) {
			$job = (array) $this->jobs->get( $job_id );

			return 'continue';
		}

		if ( ! (int) vvai_array_get( $job, 'has_audio', 0 ) ) {
			// No audio track: transcription is skipped and the analyzer is told.
			$this->jobs->update(
				$job_id,
				array(
					'transcript' => '[]',
					'stage'      => VVAI_Job_Status::TRANSCRIBING,
					'status'     => VVAI_Job_Status::TRANSCRIBING,
				)
			);

			$job = (array) $this->jobs->get( $job_id );

			$this->logger->warning( 'Source has no audio track; skipping transcription', array( 'job' => $job_id ) );

			return 'continue';
		}

		$extract = $this->ffmpeg->extract_audio(
			(string) vvai_array_get( $job, 'source_path', '' ),
			$audio,
			array( 'timeout' => (int) $this->settings->get( 'process_timeout' ) )
		);

		if ( is_wp_error( $extract ) ) {
			$data    = (array) $extract->get_error_data();
			$no_audio = ! empty( $data['no_audio'] );

			if ( $no_audio ) {
				// ffprobe saw an audio stream that FFmpeg could not decode:
				// continue without a transcript instead of failing the job.
				$this->jobs->update(
					$job_id,
					array(
						'has_audio'  => 0,
						'transcript' => '[]',
					)
				);

				$job = (array) $this->jobs->get( $job_id );

				return 'continue';
			}

			$this->fail( $job_id, (string) $extract->get_error_code(), $extract->get_error_message(), VVAI_Job_Status::EXTRACTING_AUDIO, 'extract_audio' );
			$job = (array) $this->jobs->get( $job_id );

			return 'failed';
		}

		$job = (array) $this->jobs->get( $job_id );

		return 'continue';
	}

	/**
	 * Transcription.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return string
	 */
	protected function stage_transcribe( array &$job ) {
		$job_id = (int) $job['id'];

		$existing = (array) vvai_json_decode( vvai_array_get( $job, 'transcript', '' ), true );

		if ( $existing ) {
			// Already transcribed (retry after an AI failure): reuse it, never pay twice.
			$job = (array) $this->jobs->get( $job_id );

			return 'continue';
		}

		if ( ! (int) vvai_array_get( $job, 'has_audio', 0 ) ) {
			$this->fail(
				$job_id,
				'no_audio_track',
				__( 'This video has no audio track, so there is nothing to transcribe. Clip selection needs speech or a custom focus that describes the visuals.', 'viral-video-ai' ),
				VVAI_Job_Status::TRANSCRIBING,
				'transcribe'
			);

			$job = (array) $this->jobs->get( $job_id );

			return 'failed';
		}

		$progress = function ( $done, $total ) use ( $job_id ) {
			$this->jobs->set_progress( $job_id, VVAI_Job_Status::transcription_progress( $done, $total ), VVAI_Job_Status::TRANSCRIBING );
		};

		$result = $this->transcription->transcribe(
			array_merge(
				(array) $job,
				array( 'audio_path' => $this->audio_path( $job_id ) )
			),
			$progress
		);

		if ( empty( $result['ok'] ) ) {
			$this->fail(
				$job_id,
				(string) ( $result['code'] ?: 'transcription_failed' ),
				(string) ( $result['message'] ?: __( 'Transcription failed.', 'viral-video-ai' ) ),
				VVAI_Job_Status::TRANSCRIBING,
				'transcribe'
			);

			$job = (array) $this->jobs->get( $job_id );

			return 'failed';
		}

		$this->jobs->store_transcript( $job_id, (array) $result['segments'] );

		if ( ! empty( $result['partial'] ) ) {
			$this->jobs->update(
				$job_id,
				array(
					'error_code'    => 'partial_transcript',
					'error_message' => vvai_sanitize_paragraph( (string) $result['message'], 400 ),
				)
			);
		}

		$job = (array) $this->jobs->get( $job_id );

		$this->logger->info(
			'Transcription finished',
			array(
				'job'      => $job_id,
				'segments' => count( (array) $result['segments'] ),
				'words'    => (int) $result['words'],
				'engine'   => (string) $result['engine'],
				'chunks'   => (int) $result['chunks'],
			)
		);

		return 'continue';
	}

	/**
	 * AI analysis.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return string
	 */
	protected function stage_analyze( array &$job ) {
		$job_id = (int) $job['id'];

		$progress = function ( $percent, $label ) use ( $job_id ) {
			$this->jobs->set_progress( $job_id, (int) $percent, VVAI_Job_Status::ANALYZING );

			// The stage label shown to the user can be more specific than the key.
			set_transient( 'vvai_stage_note_' . $job_id, (string) $label, 300 );
		};

		$result = $this->analyzer->analyze(
			(array) vvai_json_decode( vvai_array_get( $job, 'transcript', '' ), true ),
			(array) $job,
			$progress
		);

		if ( empty( $result['ok'] ) ) {
			$this->fail(
				$job_id,
				(string) ( $result['code'] ?: 'analysis_failed' ),
				(string) ( $result['message'] ?: __( 'The AI could not analyse this transcript.', 'viral-video-ai' ) ),
				VVAI_Job_Status::ANALYZING,
				'analyze'
			);

			$job = (array) $this->jobs->get( $job_id );

			return 'failed';
		}

		$this->jobs->store_ai_response(
			$job_id,
			(string) vvai_array_get( $result, 'raw', '' ),
			array(
				'usage'        => (array) vvai_array_get( $result, 'usage', array() ),
				'passes'       => (int) vvai_array_get( $result, 'passes', 0 ),
				'connection'   => (string) vvai_array_get( $result, 'connection_id', '' ),
				'provider'     => (string) vvai_array_get( $result, 'provider', '' ),
				'rejected'     => count( (array) vvai_array_get( $result, 'rejected', array() ) ),
				'warnings'     => array_values( array_map( 'strval', (array) vvai_array_get( $result, 'warnings', array() ) ) ),
			)
		);

		$this->jobs->store_clips( $job_id, (array) vvai_array_get( $result, 'clips', array() ) );
		$this->jobs->set_progress( $job_id, 66, VVAI_Job_Status::ANALYZING );

		$job = (array) $this->jobs->get( $job_id );

		return 'continue';
	}

	/**
	 * Final clip selection: re-validate against the real source, then queue work.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return string
	 */
	protected function stage_select_clips( array &$job ) {
		$job_id   = (int) $job['id'];
		$plan     = (array) vvai_json_decode( vvai_array_get( $job, 'clips', '' ), true );
		$segments = (array) vvai_json_decode( vvai_array_get( $job, 'transcript', '' ), true );
		$settings = (array) $job['settings_array'];
		$duration = (float) vvai_array_get( $job, 'duration', 0 );
		$bounds   = $this->analyzer->bounds( $settings, $duration );

		$validated = $this->analyzer->validate_clips(
			$plan,
			$segments ? $segments : $this->fallback_segments( $plan, $duration ),
			$duration,
			$bounds,
			(int) vvai_array_get( $settings, 'target_clips', count( $plan ) )
		);

		if ( ! $validated['clips'] ) {
			$this->fail(
				$job_id,
				'no_valid_clips',
				__( 'No clip candidate survived validation against this video, so nothing was rendered.', 'viral-video-ai' ),
				VVAI_Job_Status::SELECTING_CLIPS,
				'analyze'
			);

			$job = (array) $this->jobs->get( $job_id );

			return 'failed';
		}

		// Drop anything left from an earlier attempt so the counter is truthful.
		$this->clips->delete_outputs( $job_id );

		$this->jobs->store_clips( $job_id, $validated['clips'] );
		$this->jobs->update(
			$job_id,
			array(
				'clip_count'     => count( $validated['clips'] ),
				'rendered_count' => 0,
			)
		);

		$job = (array) $this->jobs->get( $job_id );

		return 'continue';
	}

	/**
	 * FFmpeg rendering, resumable.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return string
	 */
	protected function stage_render( array &$job ) {
		$job_id = (int) $job['id'];
		$plan   = (array) vvai_json_decode( vvai_array_get( $job, 'clips', '' ), true );

		if ( ! $plan ) {
			$this->fail( $job_id, 'no_clip_plan', __( 'The clip plan disappeared before rendering. Retry the analysis.', 'viral-video-ai' ), VVAI_Job_Status::RENDERING, 'analyze' );
			$job = (array) $this->jobs->get( $job_id );

			return 'failed';
		}

		$next  = (int) vvai_array_get( $job, 'rendered_count', 0 );
		$batch = $this->clips->generate_batch( (array) $job, $plan, $next );

		if ( $batch['done'] ) {
			$this->jobs->update(
				$job_id,
				array( 'rendered_count' => (int) $batch['next'] )
			);
		}

		$this->jobs->set_progress(
			$job_id,
			VVAI_Job_Status::render_progress( (int) $batch['next'], count( $plan ) ),
			VVAI_Job_Status::RENDERING
		);

		if ( $batch['failed'] ) {
			foreach ( $batch['failed'] as $failure ) {
				$this->logger->error(
					'Clip render failed',
					array(
						'job'   => $job_id,
						'clip'  => (int) vvai_array_get( $failure, 'index', 0 ) + 1,
						'code'  => (string) vvai_array_get( $failure, 'code', '' ),
						'error' => (string) vvai_array_get( $failure, 'message', '' ),
					)
				);
			}
		}

		$job = (array) $this->jobs->get( $job_id );

		if ( (int) $batch['next'] < count( $plan ) ) {
			// Either the budget ran out or some clips failed: keep going in the
			// next tick until every index has been attempted.
			if ( ! $batch['budget_exceeded'] && $batch['failed'] && (int) $batch['next'] >= count( $plan ) ) {
				return 'continue';
			}

			return (int) $batch['next'] >= count( $plan ) ? 'continue' : 'waiting';
		}

		$rendered = $this->clips->count_rendered( $job_id );

		if ( 0 === $rendered ) {
			$first = $batch['failed'] ? (string) vvai_array_get( $batch['failed'][0], 'message', '' ) : '';

			$this->fail(
				$job_id,
				'render_failed_all',
				__( 'FFmpeg could not render any of the planned clips. ', 'viral-video-ai' ) . $first,
				VVAI_Job_Status::RENDERING,
				'render'
			);

			$job = (array) $this->jobs->get( $job_id );

			return 'failed';
		}

		if ( $rendered < count( $plan ) ) {
			$this->jobs->update(
				$job_id,
				array(
					'error_code'    => 'partial_render',
					'error_message' => sprintf(
						/* translators: 1: rendered, 2: planned. */
						__( '%1$d of %2$d clips rendered successfully.', 'viral-video-ai' ),
						$rendered,
						count( $plan )
					),
				)
			);
		}

		$job = (array) $this->jobs->get( $job_id );

		return 'continue';
	}

	/**
	 * Finalization: counters, cleanup policy, notification.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return string
	 */
	protected function stage_finalize( array &$job ) {
		$job_id   = (int) $job['id'];
		$rendered = $this->clips->count_rendered( $job_id );

		$this->jobs->update(
			$job_id,
			array(
				'rendered_count' => $rendered,
				'clip_count'     => max( $rendered, (int) vvai_array_get( $job, 'clip_count', 0 ) ),
			)
		);

		$this->cleanup_temp( $job_id );
		$this->jobs->complete( $job_id );
		$this->jobs->touch_retention( $job_id );

		$job = (array) $this->jobs->get( $job_id );

		$this->logger->info( 'Job completed', array( 'job' => $job_id, 'clips' => $rendered ) );

		return 'done';
	}

	/**
	 * Delete the scratch files of a job (audio, chunk pieces).
	 *
	 * @param int $job_id Job id.
	 * @return int Bytes freed.
	 */
	public function cleanup_temp( $job_id ) {
		if ( ! $this->settings->get( 'delete_source_after_job' ) ) {
			$directory = vvai_storage_dir( 'tmp/job-' . (int) $job_id );

			if ( ! is_dir( $directory ) ) {
				return 0;
			}

			// vvai_rrmdir() also removes the dotfile guards (index.php, .htaccess)
			// that vvai_mkdir() writes, so the folder itself can actually go away.
			return $this->remove_tree( $directory );
		}

		// Retention policy says the source goes away with the job: remove both.
		$bytes = $this->cleanup_temp_only( $job_id );
		$job   = $this->jobs->get( $job_id );

		if ( $job && '' !== (string) $job['source_path'] && is_file( (string) $job['source_path'] ) ) {
			$bytes += (int) filesize( (string) $job['source_path'] );
			@unlink( (string) $job['source_path'] );

			$this->jobs->update( $job_id, array( 'source_path' => '' ) );
		}

		return $bytes;
	}

	/**
	 * Remove the tmp folder without touching the source.
	 *
	 * @param int $job_id Job id.
	 * @return int
	 */
	protected function cleanup_temp_only( $job_id ) {
		return $this->remove_tree( vvai_storage_dir( 'tmp/job-' . (int) $job_id ) );
	}

	/**
	 * Delete a directory and report how much space it freed.
	 *
	 * `glob()` skips dotfiles, and every folder the plugin creates contains the
	 * index.php/.htaccess guards — so a naive glob loop silently leaves the
	 * directory behind on every single job.
	 *
	 * @param string $directory Absolute path.
	 * @return int Bytes freed.
	 */
	protected function remove_tree( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		$bytes = 0;

		foreach ( (array) scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . '/' . $entry;

			if ( is_file( $path ) ) {
				$bytes += (int) filesize( $path );
				@unlink( $path );
			}
		}

		@rmdir( $directory );

		return $bytes;
	}

	/**
	 * Where the extracted audio for a job is kept.
	 *
	 * @param int $job_id Job id.
	 * @return string
	 */
	public function audio_path( $job_id ) {
		return vvai_storage_dir( 'tmp/job-' . (int) $job_id ) . '/audio.mp3';
	}

	/**
	 * Fallback "segments" built from the plan itself, so a job without a stored
	 * transcript can still be re-rendered (spec §40).
	 *
	 * @param array $plan     Clips.
	 * @param float $duration Source duration.
	 * @return array
	 */
	protected function fallback_segments( array $plan, $duration ) {
		$segments = array();

		foreach ( $plan as $clip ) {
			$start = (float) vvai_array_get( $clip, 'start_time', 0 );
			$end   = (float) vvai_array_get( $clip, 'end_time', 0 );

			$segments[] = array(
				'start' => $start,
				'end'   => min( $duration, $end ),
				'text'  => (string) vvai_array_get( $clip, 'transcript', vvai_array_get( $clip, 'title', '' ) ),
			);
		}

		return $segments;
	}

	/**
	 * Persist a failure.
	 *
	 * @param int    $job_id     Job id.
	 * @param string $code       Error code.
	 * @param string $message    Message.
	 * @param string $stage      Stage.
	 * @param string $retry_from Resume key.
	 */
	public function fail( $job_id, $code, $message, $stage, $retry_from = '' ) {
		$this->jobs->fail( $job_id, $code, $message, $stage, $retry_from );
		$this->jobs->release( $job_id );

		/**
		 * Fires when a job fails.
		 *
		 * @param int    $job_id Job id.
		 * @param string $code      Error code.
		 * @param string $message   Message.
		 * @param string $stage     Stage.
		 */
		do_action( 'vvai_job_failed', (int) $job_id, $code, $message, $stage );
	}

	/**
	 * Map a stage to the retry key that would recover from it.
	 *
	 * @param string $stage Stage.
	 * @return string
	 */
	public function retry_stage_for( $stage ) {
		switch ( $stage ) {
			case VVAI_Job_Status::EXTRACTING_AUDIO:
				return 'extract_audio';
			case VVAI_Job_Status::TRANSCRIBING:
				return 'transcribe';
			case VVAI_Job_Status::ANALYZING:
				return 'analyze';
			case VVAI_Job_Status::SELECTING_CLIPS:
				return 'select';
			case VVAI_Job_Status::RENDERING:
			case VVAI_Job_Status::FINALIZING:
				return 'render';
		}

		return 'inspect';
	}
}
