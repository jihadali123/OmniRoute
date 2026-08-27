<?php
/**
 * Job status vocabulary, progress model and the public payload.
 *
 * Single source of truth for the state machine so the admin table, the REST
 * controller, the widget and the pipeline cannot drift apart.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Job_Status
 */
final class VVAI_Job_Status {

	const QUEUED          = 'queued';
	const UPLOADING       = 'uploading';
	const UPLOADED        = 'uploaded';
	const INSPECTING      = 'inspecting';
	const EXTRACTING_AUDIO = 'extracting_audio';
	const TRANSCRIBING    = 'transcribing';
	const ANALYZING       = 'analyzing';
	const SELECTING_CLIPS = 'selecting_clips';
	const RENDERING       = 'rendering_clips';
	const FINALIZING      = 'finalizing';
	const COMPLETED       = 'completed';
	const FAILED          = 'failed';
	const CANCELLED       = 'cancelled';

	/**
	 * Ordered pipeline stages with their progress window.
	 *
	 * `progress` is only ever derived from real work (see
	 * VVAI_Video_Processor), the windows make the bar move monotonically
	 * instead of jumping around.
	 *
	 * @return array<string,array{label:string,progress:int,next:string}>
	 */
	public static function stages() {
		return array(
			self::QUEUED           => array( 'label' => __( 'Queued', 'viral-video-ai' ), 'progress' => 2, 'next' => self::INSPECTING ),
			self::UPLOADING        => array( 'label' => __( 'Uploading', 'viral-video-ai' ), 'progress' => 5, 'next' => self::UPLOADED ),
			self::UPLOADED         => array( 'label' => __( 'Uploaded', 'viral-video-ai' ), 'progress' => 8, 'next' => self::INSPECTING ),
			self::INSPECTING       => array( 'label' => __( 'Inspecting video', 'viral-video-ai' ), 'progress' => 12, 'next' => self::EXTRACTING_AUDIO ),
			self::EXTRACTING_AUDIO => array( 'label' => __( 'Extracting audio', 'viral-video-ai' ), 'progress' => 22, 'next' => self::TRANSCRIBING ),
			self::TRANSCRIBING     => array( 'label' => __( 'Transcribing', 'viral-video-ai' ), 'progress' => 45, 'next' => self::ANALYZING ),
			self::ANALYZING        => array( 'label' => __( 'AI detecting viral moments', 'viral-video-ai' ), 'progress' => 62, 'next' => self::SELECTING_CLIPS ),
			self::SELECTING_CLIPS  => array( 'label' => __( 'Selecting clips', 'viral-video-ai' ), 'progress' => 68, 'next' => self::RENDERING ),
			self::RENDERING        => array( 'label' => __( 'Rendering clips', 'viral-video-ai' ), 'progress' => 70, 'next' => self::FINALIZING ),
			self::FINALIZING       => array( 'label' => __( 'Finalizing', 'viral-video-ai' ), 'progress' => 97, 'next' => self::COMPLETED ),
			self::COMPLETED        => array( 'label' => __( 'Completed', 'viral-video-ai' ), 'progress' => 100, 'next' => '' ),
			self::FAILED           => array( 'label' => __( 'Failed', 'viral-video-ai' ), 'progress' => 100, 'next' => '' ),
			self::CANCELLED       => array( 'label' => __( 'Cancelled', 'viral-video-ai' ), 'progress' => 0, 'next' => '' ),
		);
	}

	/**
	 * Simple stage => label map for the frontend.
	 *
	 * @return array<string,string>
	 */
	public static function stage_labels() {
		$out = array();

		foreach ( self::stages() as $key => $data ) {
			$out[ $key ] = $data['label'];
		}

		return $out;
	}

	/**
	 * Is this a known stage?
	 *
	 * @param string $stage Stage.
	 * @return bool
	 */
	public static function is_stage( $stage ) {
		return is_string( $stage ) && isset( self::stages()[ $stage ] );
	}

	/**
	 * Label for a stage.
	 *
	 * @param string $stage Stage.
	 * @return string
	 */
	public static function label( $stage ) {
		$stages = self::stages();

		return isset( $stages[ $stage ] ) ? $stages[ $stage ]['label'] : ucwords( str_replace( '_', ' ', (string) $stage ) );
	}

	/**
	 * Next stage, or empty string when the stage is terminal.
	 *
	 * @param string $stage Stage.
	 * @return string
	 */
	public static function next( $stage ) {
		$stages = self::stages();

		return isset( $stages[ $stage ] ) ? (string) $stages[ $stage ]['next'] : '';
	}

	/**
	 * Base progress percentage for a stage.
	 *
	 * @param string $stage Stage.
	 * @return int
	 */
	public static function progress_for( $stage ) {
		$stages = self::stages();

		return isset( $stages[ $stage ] ) ? (int) $stages[ $stage ]['progress'] : 0;
	}

	/**
	 * Progress window used while rendering clips (70 → 96).
	 *
	 * @param int $done   Rendered clips.
	 * @param int $total  Total clips.
	 * @return int
	 */
	public static function render_progress( $done, $total ) {
		$done  = max( 0, (int) $done );
		$total = max( 1, (int) $total );

		return (int) min( 96, 70 + (int) floor( ( $done / $total ) * 26 ) );
	}

	/**
	 * Progress window used while transcribing (24 → 45).
	 *
	 * @param int $done  Finished audio chunks.
	 * @param int $total Total audio chunks.
	 * @return int
	 */
	public static function transcription_progress( $done, $total ) {
		$done  = max( 0, (int) $done );
		$total = max( 1, (int) $total );

		return (int) min( 45, 24 + (int) floor( ( $done / $total ) * 21 ) );
	}

	/**
	 * Terminal stages stop the pipeline.
	 *
	 * @param string $stage Stage.
	 * @return bool
	 */
	public static function is_terminal( $stage ) {
		return in_array( $stage, array( self::COMPLETED, self::FAILED, self::CANCELLED ), true );
	}

	/**
	 * Stages that the background worker may claim.
	 *
	 * @return string[]
	 */
	public static function active_stages() {
		return array(
			self::QUEUED,
			self::UPLOADED,
			self::INSPECTING,
			self::EXTRACTING_AUDIO,
			self::TRANSCRIBING,
			self::ANALYZING,
			self::SELECTING_CLIPS,
			self::RENDERING,
			self::FINALIZING,
		);
	}

	/**
	 * Stages that may be retried, mapped to what must be redone.
	 *
	 * @return array<string,string>
	 */
	public static function retry_targets() {
		return array(
			'inspect'          => self::INSPECTING,
			'extract_audio'    => self::EXTRACTING_AUDIO,
			'transcribe'       => self::TRANSCRIBING,
			'analyze'          => self::ANALYZING,
			'select'           => self::SELECTING_CLIPS,
			'render'           => self::RENDERING,
			'finalize'         => self::FINALIZING,
		);
	}

	/**
	 * Badge CSS class for the admin UI.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function badge_class( $status ) {
		switch ( $status ) {
			case self::COMPLETED:
				return 'is-success';
			case self::FAILED:
				return 'is-error';
			case self::CANCELLED:
				return 'is-muted';
			case self::QUEUED:
			case self::UPLOADING:
			case self::UPLOADED:
				return 'is-idle';
		}

		return 'is-running';
	}

	/**
	 * The only representation of a job that ever leaves the server.
	 *
	 * Never contains absolute paths, provider credentials, or raw AI payloads.
	 *
	 * @param array|object $job Job row.
	 * @return array<string,mixed>
	 */
	public static function public_payload( $job ) {
		$job    = (array) $job;
		$stage  = (string) vvai_array_get( $job, 'stage', self::QUEUED );
		$status = (string) vvai_array_get( $job, 'status', self::QUEUED );
		$clip   = vvai_json_decode( vvai_array_get( $job, 'clips', '' ) );
		$clip   = is_array( $clip ) ? $clip : array();

		$rendered = (int) vvai_array_get( $job, 'rendered_count', 0 );
		$planned  = (int) vvai_array_get( $job, 'clip_count', count( $clip ) );

		// Progress must never outrun reality: cap it while anything is pending.
		$progress = (int) vvai_array_get( $job, 'progress', 0 );

		if ( self::COMPLETED !== $status && $progress >= 100 ) {
			$progress = 99;
		}

		if ( self::FAILED === $status ) {
			$progress = (int) min( $progress, 99 );
		}

		$payload = array(
			'id'           => (int) vvai_array_get( $job, 'id', 0 ),
			'title'        => (string) vvai_array_get( $job, 'title', '' ),
			'status'       => $status,
			'stage'        => $stage,
			'stageLabel'   => self::label( $stage ),
			'progress'     => max( 0, min( 100, $progress ) ),
			'duration'     => round( (float) vvai_array_get( $job, 'duration', 0 ), 2 ),
			'width'        => (int) vvai_array_get( $job, 'width', 0 ),
			'height'       => (int) vvai_array_get( $job, 'height', 0 ),
			'fileSize'     => (int) vvai_array_get( $job, 'file_size', 0 ),
			'humanSize'    => vvai_human_size( (int) vvai_array_get( $job, 'file_size', 0 ) ),
			'hasAudio'     => (bool) vvai_array_get( $job, 'has_audio', false ),
			'clipCount'    => $planned,
			'renderedCount'=> $rendered,
			'createdAt'    => (string) vvai_array_get( $job, 'created_at', '' ),
			'updatedAt'    => (string) vvai_array_get( $job, 'updated_at', '' ),
			'retryFrom'    => (string) vvai_array_get( $job, 'retry_from', '' ),
			'attempts'     => (int) vvai_array_get( $job, 'attempts', 0 ),
		);

		if ( self::FAILED === $status ) {
			$payload['error'] = array(
				'code'    => (string) vvai_array_get( $job, 'error_code', '' ),
				'stage'   => (string) vvai_array_get( $job, 'error_stage', '' ),
				// Human readable, already sanitized when stored.
				'message' => (string) vvai_array_get( $job, 'error_message', '' ),
			);
		}

		if ( self::RENDERING === $status || self::COMPLETED === $status ) {
			$payload['stageLabel'] = self::label( $stage );

			if ( self::RENDERING === $status && $planned > 0 ) {
				/* translators: 1: current clip number, 2: total clips. */
				$payload['stageLabel'] = sprintf( __( 'Rendering clip %1$d of %2$d', 'viral-video-ai' ), min( $planned, $rendered + 1 ), $planned );
			}
		}

		return $payload;
	}
}
