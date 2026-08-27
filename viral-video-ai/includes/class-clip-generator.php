<?php
/**
 * Clip generation (spec §21).
 *
 * Takes the validated timestamp plan and produces real files with FFmpeg, then
 * records each successful render in the clips table so the results grid, the
 * download authorizer and the retention scheduler all read the same data.
 *
 * Rendering is resumable: a clip whose file already exists and is valid is not
 * re-encoded, which is what makes "retry failed clips only" cheap after a render
 * that died half-way.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Clip_Generator
 */
class VVAI_Clip_Generator {

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
	 * Clip rows.
	 *
	 * @var VVAI_Clip_Repository
	 */
	private $clips;

	/**
	 * Constructor.
	 *
	 * @param VVAI_FFMPEG|null          $ffmpeg    FFmpeg gateway.
	 * @param VVAI_Settings|null        $settings  Settings.
	 * @param VVAI_Clip_Repository|null $clips     Clip repository.
	 */
	public function __construct( $ffmpeg = null, $settings = null, $clips = null ) {
		$this->ffmpeg   = $ffmpeg instanceof VVAI_FFMPEG ? $ffmpeg : new VVAI_FFMPEG();
		$this->settings = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
		$this->clips    = $clips instanceof VVAI_Clip_Repository ? $clips : new VVAI_Clip_Repository();
	}

	/**
	 * Folder holding one job's outputs.
	 *
	 * @param int $job_id Job id.
	 * @return string
	 */
	public function job_dir( $job_id ) {
		return vvai_storage_dir( 'jobs/job-' . max( 1, (int) $job_id ) );
	}

	/**
	 * Where a rendered clip lives.
	 *
	 * @param int $job_id     Job id.
	 * @param int $clip_index Clip index (0-based).
	 * @param string $extension Container.
	 * @return string
	 */
	public function clip_path( $job_id, $clip_index, $extension = 'mp4' ) {
		$extension = in_array( strtolower( (string) $extension ), array( 'mp4', 'webm', 'mov', 'mkv' ), true ) ? strtolower( (string) $extension ) : 'mp4';

		return $this->job_dir( $job_id ) . sprintf( '/clip-%03d.%s', max( 0, (int) $clip_index ), $extension );
	}

	/**
	 * Render every pending clip of a job, one PHP request at a time.
	 *
	 * @param array<string,mixed> $job   Job row.
	 * @param array               $clips Clip plan.
	 * @param int                 $start Index to resume from.
	 * @return array{done:array<int,array<string,mixed>>,failed:array<int,array<string,mixed>>,remaining:int,next:int,budget_exceeded:bool}
	 */
	public function generate_batch( array $job, array $clips, $start = 0 ) {
		$start   = max( 0, (int) $start );
		$total   = count( $clips );
		$budget  = max( 5, (int) $this->settings->get( 'max_execution_budget' ) );
		$started = microtime( true );

		$out = array(
			'done'            => array(),
			'failed'          => array(),
			'remaining'       => 0,
			'next'            => $start,
			'budget_exceeded' => false,
		);

		for ( $index = $start; $index < $total; $index++ ) {
			if ( ( microtime( true ) - $started ) > $budget ) {
				$out['budget_exceeded'] = true;
				break;
			}

			$clip = $clips[ $index ];

			// Reuse the file when the pipeline is resuming after a partial failure.
			$existing = $this->clip_path( (int) $job['id'], $index );

			if ( is_file( $existing ) && filesize( $existing ) > 1024 ) {
				$verified = $this->ffmpeg->verify_output( $existing, (float) vvai_array_get( $clip, 'duration', 0 ) );

				if ( ! empty( $verified['ok'] ) ) {
					$saved = $this->persist( $job, $clip, $index, $existing, $verified, 0.0, array(), true );

					$out['done'][] = $saved;
					$out['next']   = $index + 1;

					continue;
				}

				@unlink( $existing );
			}

			$render = $this->render_single( $job, $clip, $index );

			if ( is_wp_error( $render ) ) {
				$out['failed'][] = array(
					'index'   => $index,
					'code'    => (string) $render->get_error_code(),
					'message' => (string) $render->get_error_message(),
				);

				$out['next'] = $index + 1;

				continue;
			}

			$saved = $this->persist( $job, $clip, $index, $render['path'], $render, (float) $render['render_time'], $render['warnings'], false );

			$out['done'][]  = $saved;
			$out['next']    = $index + 1;
		}

		$out['remaining'] = max( 0, $total - $out['next'] );

		return $out;
	}

	/**
	 * Render one clip.
	 *
	 * @param array<string,mixed> $job   Job row.
	 * @param array<string,mixed> $clip   Clip plan item.
	 * @param int                 $index  Clip index.
	 * @return array<string,mixed>|WP_Error
	 */
	public function render_single( array $job, array $clip, $index ) {
		$source = (string) vvai_array_get( $job, 'source_path', '' );

		if ( '' === $source || ! is_file( $source ) ) {
			return new WP_Error( 'missing_source', __( 'The source video is no longer available on the server, so this clip cannot be rendered.', 'viral-video-ai' ) );
		}

		$settings = isset( $job['settings_array'] ) && is_array( $job['settings_array'] ) ? $job['settings_array'] : array();

		$target = $this->clip_path( (int) $job['id'], (int) $index );

		if ( ! vvai_mkdir( dirname( $target ) ) ) {
			return new WP_Error( 'unwritable_output', __( 'The plugin cannot create its job folder. Check that the uploads directory is writable.', 'viral-video-ai' ) );
		}

		$transcript = (array) vvai_json_decode( vvai_array_get( $job, 'transcript', '' ), true );

		if ( ! $transcript && ! empty( $job['transcript_array'] ) ) {
			$transcript = (array) $job['transcript_array'];
		}

		$srt = '';

		if ( ! empty( $settings['burn_captions'] ) && $transcript ) {
			$written = $this->write_captions( $job, $clip, $index, $transcript, true );

			if ( ! is_wp_error( $written ) ) {
				$srt = $written;
			}
		}

		$result = $this->ffmpeg->render_clip(
			$source,
			$target,
			array(
				'start'       => (float) vvai_array_get( $clip, 'start_time', 0 ),
				'end'         => (float) vvai_array_get( $clip, 'end_time', 0 ),
				'aspect'      => (string) vvai_array_get( $settings, 'aspect_ratio', '9:16' ),
				'quality'     => (string) vvai_array_get( $settings, 'quality', '1080p' ),
				'crop_mode'   => (string) vvai_array_get( $settings, 'crop_mode', $this->settings->get( 'crop_mode' ) ),
				'source_meta' => array(
					'has_audio' => (int) vvai_array_get( $job, 'has_audio', 0 ),
					'width'     => (int) vvai_array_get( $job, 'width', 0 ),
					'height'    => (int) vvai_array_get( $job, 'height', 0 ),
					'fps'       => (float) vvai_array_get( $job, 'fps', 0 ),
					'rotation'  => (int) vvai_array_get( $job, 'rotation', 0 ),
				),
				'srt'         => $srt,
				'timeout'     => (int) $this->settings->get( 'process_timeout' ),
			)
		);

		if ( $srt && '' !== $srt ) {
			@unlink( $srt );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Sidecar captions for the user (not burned in).
		if ( ! empty( $settings['generate_srt'] ) && $transcript ) {
			$sidecar = $this->write_captions( $job, $clip, $index, $transcript, false );

			$result['srt_path'] = is_wp_error( $sidecar ) ? '' : (string) $sidecar;
		} else {
			$result['srt_path'] = '';
		}

		$result['path'] = $target;

		return $result;
	}

	/**
	 * Write the SRT file for a clip.
	 *
	 * @param array<string,mixed> $job        Job row.
	 * @param array<string,mixed> $clip        Clip plan item.
	 * @param int                 $index       Clip index.
	 * @param array               $transcript  Transcript segments.
	 * @param bool                $for_burn_in Temporary file used by the renderer.
	 * @return string|WP_Error Path.
	 */
	public function write_captions( array $job, array $clip, $index, array $transcript, $for_burn_in = false ) {
		$srt = VVAI_Transcript::to_srt(
			$transcript,
			(float) vvai_array_get( $clip, 'start_time', 0 ),
			(float) vvai_array_get( $clip, 'end_time', 0 ),
			$for_burn_in ? 42 : 200
		);

		if ( '' === trim( $srt ) ) {
			return new WP_Error( 'no_caption_text', __( 'No transcript text overlaps this clip, so no captions were written.', 'viral-video-ai' ) );
		}

		$path = $for_burn_in
			? vvai_storage_dir( sprintf( 'tmp/job-%d/captions-%03d.srt', (int) $job['id'], (int) $index ) )
			: $this->job_dir( (int) $job['id'] ) . sprintf( '/clip-%03d.srt', (int) $index );

		if ( ! vvai_mkdir( dirname( $path ) ) ) {
			return new WP_Error( 'unwritable_output', __( 'The caption file could not be written.', 'viral-video-ai' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- caption sidecar.
		if ( false === @file_put_contents( $path, $srt, LOCK_EX ) ) {
			return new WP_Error( 'caption_write_failed', __( 'The caption file could not be written.', 'viral-video-ai' ) );
		}

		return $path;
	}

	/**
	 * Persist a rendered clip.
	 *
	 * @param array<string,mixed> $job     Job row.
	 * @param array<string,mixed> $clip     Clip plan item.
	 * @param int                 $index    Index.
	 * @param string              $path     File path.
	 * @param array<string,mixed> $result   Render result.
	 * @param float               $seconds  Render time.
	 * @param array               $warnings Render warnings.
	 * @param bool                $reused   Whether the existing file was reused.
	 * @return array<string,mixed>
	 */
	protected function persist( array $job, array $clip, $index, $path, array $result, $seconds, array $warnings, $reused ) {
		$settings = isset( $job['settings_array'] ) && is_array( $job['settings_array'] ) ? $job['settings_array'] : array();

		$row = array(
			'job_id'         => (int) $job['id'],
			'author_id'      => (int) vvai_array_get( $job, 'author_id', 0 ),
			'clip_index'     => (int) $index,
			'status'         => 'rendered',
			'title'          => (string) vvai_array_get( $clip, 'title', '' ),
			'caption'        => (string) vvai_array_get( $clip, 'social_caption', '' ),
			'hashtags'       => (array) vvai_array_get( $clip, 'hashtags', array() ),
			'viral_score'    => (int) vvai_array_get( $clip, 'viral_score', 0 ),
			'reasoning'      => (string) vvai_array_get( $clip, 'reasoning', '' ),
			'start_time'     => (float) vvai_array_get( $clip, 'start_time', 0 ),
			'end_time'       => (float) vvai_array_get( $clip, 'end_time', 0 ),
			'duration'       => (float) vvai_array_get( $clip, 'duration', max( 0.0, (float) vvai_array_get( $clip, 'end_time', 0 ) - (float) vvai_array_get( $clip, 'start_time', 0 ) ) ),
			'file_path'      => (string) $path,
			'file_name'      => (string) basename( (string) $path ),
			'srt_path'       => (string) vvai_array_get( $result, 'srt_path', '' ),
			'file_size'      => is_file( $path ) ? (int) filesize( $path ) : 0,
			'width'          => (int) vvai_array_get( $result, 'width', $result['height'] ?? 0 ),
			'height'         => (int) vvai_array_get( $result, 'height', 0 ),
			'aspect_ratio'   => (string) vvai_array_get( $settings, 'aspect_ratio', '9:16' ),
			'quality'        => (string) vvai_array_get( $settings, 'quality', '1080p' ),
			'crop_mode'      => (string) vvai_array_get( (array) vvai_array_get( $result, 'crop', array() ), 'mode', 'center' ),
			'render_seconds' => (float) $seconds,
			'metrics'        => array(
				'filters'     => (string) vvai_array_get( $result, 'filters', '' ),
				'upscaled'    => (bool) vvai_array_get( $result, 'upscaled', false ),
				'reused'      => (bool) $reused,
				'warnings'    => array_values( array_map( 'strval', (array) $warnings ) ),
				'duration_ok' => (bool) vvai_array_get( $result, 'duration_ok', false ),
				'seek_delta'  => (float) vvai_array_get( $result, 'seek_delta', 0 ),
				'confidence'  => (float) vvai_array_get( (array) vvai_array_get( $result, 'crop', array() ), 'confidence', 0 ),
			),
		);

		$saved = $this->clips->save( $row );

		$row['id']  = is_wp_error( $saved ) ? 0 : (int) $saved;
		$row['url'] = '';

		if ( $row['id'] ) {
			$row['download_url'] = rest_url( VVAI_REST_NAMESPACE . '/clips/' . $row['id'] . '/download' );
		}

		return $row;
	}

	/**
	 * How many planned clips actually exist on disk for this job.
	 *
	 * The pipeline asks this before declaring success, so "completed" always means
	 * real files — never a render that silently produced nothing.
	 *
	 * @param int $job_id Job id.
	 * @return int
	 */
	public function count_rendered( $job_id ) {
		return $this->clips->count_rendered( (int) $job_id );
	}

	/**
	 * Delete a job's rendered output.
	 *
	 * @param int $job_id Job id.
	 * @return int Number of files removed.
	 */
	public function delete_outputs( $job_id ) {
		$directory = vvai_storage_dir( 'jobs/job-' . (int) $job_id );
		$removed   = 0;

		if ( ! is_dir( $directory ) ) {
			return 0;
		}

		foreach ( (array) glob( $directory . '/clip-*' ) as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
				$removed++;
			}
		}

		$this->clips->delete_for_job( (int) $job_id );

		return $removed;
	}

	/**
	 * Remove only the clips that failed to render, so a retry re-renders just
	 * those (spec §40).
	 *
	 * @param int   $job_id Job id.
	 * @param array $clips   Plan.
	 * @param array $failed  Failed indexes.
	 * @return int
	 */
	public function drop_failed_outputs( $job_id, array $clips, array $failed ) {
		$removed = 0;

		foreach ( $failed as $entry ) {
			$index = is_array( $entry ) ? (int) vvai_array_get( $entry, 'index', -1 ) : (int) $entry;

			if ( $index < 0 || $index >= count( $clips ) ) {
				continue;
			}

			$path = $this->clip_path( $job_id, $index );

			if ( is_file( $path ) ) {
				@unlink( $path );
				$removed++;
			}

			$existing = $this->clips->find( $job_id, $index );

			if ( $existing ) {
				$this->clips->delete( (int) $existing['id'] );
			}
		}

		return $removed;
	}
}
