<?php
/**
 * AI viral-moment analysis (spec §14, §20, §48-§52).
 *
 * Flow for a job:
 *
 *   transcript ─▶ fits in one request?  ─yes─▶ single analysis pass
 *                                   └─no──▶ windowed candidate discovery
 *                                            └▶ ranking pass
 *   candidates ─▶ strict validation ─▶ boundary snapping ─▶ de-overlap
 *               ─▶ scoring/ordering ─▶ final clip plan
 *
 * Nothing that arrives from a model is trusted: timestamps are re-derived from
 * real transcript boundaries, every field is sanitized, and any candidate that
 * cannot be placed on the timeline is rejected (optionally sent back to the
 * model once for correction).
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_AI_Analyzer
 */
class VVAI_AI_Analyzer {

	/**
	 * Router.
	 *
	 * @var VVAI_AI_Router
	 */
	private $router;

	/**
	 * Settings.
	 *
	 * @var VVAI_Settings
	 */
	private $settings;

	/**
	 * Logger.
	 *
	 * @var VVAI_Logger
	 */
	private $logger;

	/**
	 * Prompt builder.
	 *
	 * @var VVAI_Prompt_Builder
	 */
	private $prompts;

	/**
	 * Constructor.
	 *
	 * @param VVAI_AI_Router|null $router   Router.
	 * @param VVAI_Settings|null  $settings Settings.
	 * @param VVAI_Logger|null    $logger   Logger.
	 */
	public function __construct( $router = null, $settings = null, $logger = null ) {
		$this->settings = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
		$this->router   = $router instanceof VVAI_AI_Router ? $router : new VVAI_AI_Router( null, null, $this->settings );
		$this->logger   = $logger instanceof VVAI_Logger ? $logger : new VVAI_Logger( $this->settings );
		$this->prompts  = new VVAI_Prompt_Builder();
	}

	/**
	 * Analyze a transcript and return a validated clip plan.
	 *
	 * @param array<int,array<string,mixed>> $segments Transcript segments.
	 * @param array<string,mixed>            $job      Job row.
	 * @param callable|null                  $progress fn( int $percent, string $label ).
	 * @return array{ok:bool,clips:array,code:string,message:string,hint:string,usage:array,warnings:array,rejected:array,passes:int,connection_id:string,provider:string}
	 */
	public function analyze( array $segments, array $job, $progress = null ) {
		$out = array(
			'ok'            => false,
			'clips'         => array(),
			'code'          => '',
			'message'       => '',
			'hint'          => '',
			'usage'         => array( 'input' => 0, 'output' => 0, 'calls' => 0 ),
			'warnings'      => array(),
			'rejected'      => array(),
			'passes'        => 0,
			'connection_id' => '',
			'provider'      => '',
			'raw'           => '',
		);

		$duration = max( 0.0, (float) vvai_array_get( $job, 'duration', 0 ) );
		$settings = isset( $job['settings_array'] ) && is_array( $job['settings_array'] )
			? $job['settings_array']
			: ( new VVAI_Job_Manager() )->normalize_settings( (array) vvai_json_decode( vvai_array_get( $job, 'settings', '' ), true ) );

		if ( ! $segments ) {
			$out['code']    = 'no_transcript';
			$out['message'] = __( 'There is no transcript to analyse, so the AI cannot pick moments.', 'viral-video-ai' );

			return $out;
		}

		$bounds = $this->bounds( $settings, $duration );

		if ( empty( $bounds['ok'] ) ) {
			$out['code']    = (string) $bounds['code'];
			$out['message'] = (string) $bounds['message'];
			$out['hint']    = (string) $bounds['hint'];

			return $out;
		}

		$expected = $this->expected_clips( $settings, $duration, $bounds );
		$focus    = (string) vvai_array_get( $settings, 'focus', 'viral' );
		$custom   = (string) vvai_array_get( $settings, 'custom_focus', '' );

		$shared = array(
			'duration'      => $duration,
			'min_seconds'   => $bounds['min'],
			'max_seconds'   => $bounds['max'],
			'expected_clips'=> $expected,
			'focus'         => $focus,
			'custom_focus'  => $custom,
			'language'      => $this->language_hint(),
			'metadata'      => array(
				'width'     => (int) vvai_array_get( $job, 'width', 0 ),
				'height'    => (int) vvai_array_get( $job, 'height', 0 ),
				'fps'       => (float) vvai_array_get( $job, 'fps', 0 ),
				'vcodec'    => (string) vvai_array_get( $job, 'vcodec', '' ),
				'acodec'    => (string) vvai_array_get( $job, 'acodec', '' ),
				'has_audio' => (int) vvai_array_get( $job, 'has_audio', 0 ),
				'file_size' => (int) vvai_array_get( $job, 'file_size', 0 ),
			),
		);

		$candidates = array();
		$passes     = 0;

		// ---- Pass(es): single request when the transcript fits, windowed otherwise.
		$budget   = $this->prompt_budget();
		$prompt   = $this->prompts->build( array_merge( $shared, array( 'segments' => $segments ) ) );
		$needs_chunking = strlen( (string) $prompt ) > $budget;

		if ( ! $needs_chunking ) {
			if ( is_callable( $progress ) ) {
				call_user_func( $progress, 62, __( 'AI detecting viral moments', 'viral-video-ai' ) );
			}

			$result = $this->request( $prompt, $shared, $settings, $job );

			$passes++;
			$out['usage']  = $this->merge_usage( $out['usage'], $result );
			$out['raw']    = (string) $result['text'];
			$out['code']   = (string) $result['code'];
			$out['message'] = (string) $result['message'];
			$out['hint']   = (string) $result['hint'];
			$out['connection_id'] = (string) vvai_array_get( $result, 'connection_id', '' );
			$out['provider']      = (string) vvai_array_get( $result, 'provider', '' );

			if ( empty( $result['ok'] ) ) {
				return $out;
			}

			$candidates = (array) vvai_array_get( $result, 'clips', array() );
		} else {
			$chunks = VVAI_Transcript::chunk( $segments, $budget, 12.0 );
			$total  = max( 1, count( $chunks ) );

			$out['warnings'][] = sprintf(
				/* translators: %d: number of analysis windows. */
				__( 'The transcript is long, so it was analysed in %d windows and the candidates were then ranked together.', 'viral-video-ai' ),
				$total
			);

			$pool = array();

			foreach ( $chunks as $index => $chunk ) {
				if ( is_callable( $progress ) ) {
					$percent = 55 + (int) floor( ( ( $index + 1 ) / $total ) * 8 );
					call_user_func(
						$progress,
						$percent,
						sprintf(
							/* translators: 1: window number, 2: total windows. */
							__( 'AI scanning window %1$d of %2$d', 'viral-video-ai' ),
							$index + 1,
							$total
						)
					);
				}

				$chunk_prompt = $this->prompts->build(
					array_merge(
						$shared,
						array(
							'segments'     => $chunk['segments'],
							'window_start' => $chunk['start'],
							'window_end'   => $chunk['end'],
							'candidates'   => true,
							'expected_clips' => max( 2, min( 4, (int) ceil( $expected / 2 ) ) ),
						)
					)
				);

				$result = $this->request( $chunk_prompt, $shared, $settings, $job );

				$passes++;
				$out['usage'] = $this->merge_usage( $out['usage'], $result );

				if ( empty( $result['ok'] ) ) {
					// A single failing window must not kill the job: keep going and
					// tell the user what was skipped.
					$out['warnings'][] = sprintf(
						/* translators: 1: window number, 2: error message. */
						__( 'Window %1$d could not be analysed (%2$s).', 'viral-video-ai' ),
						$index + 1,
						(string) $result['message']
					);

					$this->logger->warning(
						'Analysis window failed',
						array(
							'job'    => (int) $job['id'],
							'window' => $index + 1,
							'code'   => (string) $result['code'],
						)
					);

					$out['connection_id'] = (string) vvai_array_get( $result, 'connection_id', $out['connection_id'] );
					$out['provider']      = (string) vvai_array_get( $result, 'provider', $out['provider'] );

					continue;
				}

				$out['connection_id'] = (string) vvai_array_get( $result, 'connection_id', $out['connection_id'] );
				$out['provider']      = (string) vvai_array_get( $result, 'provider', $out['provider'] );

				foreach ( (array) vvai_array_get( $result, 'clips', array() ) as $candidate ) {
					if ( is_array( $candidate ) ) {
						$candidate['__window'] = $index;
						$pool[] = $candidate;
					}
				}
			}

			if ( ! $pool ) {
				$out['code']    = 'no_candidates';
				$out['message'] = __( 'The AI did not find any moment worth clipping in this video.', 'viral-video-ai' );
				$out['hint']    = __( 'Try a longer clip range, the "Viral Moments" focus, or another model with a bigger context window.', 'viral-video-ai' );

				return $out;
			}

			if ( count( $pool ) <= $expected ) {
				$candidates = $pool;
			} else {
				if ( is_callable( $progress ) ) {
					call_user_func( $progress, 66, __( 'Ranking candidate moments', 'viral-video-ai' ) );
				}

				$rank_prompt = $this->prompts->build_ranking_prompt( $pool, $expected, $shared );
				$ranked      = $this->request( $rank_prompt, $shared, $settings, $job, array( 'max_tokens' => $this->ranking_tokens( $expected ) ) );

				$passes++;
				$out['usage'] = $this->merge_usage( $out['usage'], $ranked );
				$out['raw']   = (string) $ranked['text'];

				if ( empty( $ranked['ok'] ) ) {
					// Ranking failed: fall back to the raw pool ordered by score.
					$out['warnings'][] = sprintf(
						/* translators: %s: error message. */
						__( 'The ranking pass failed (%s); the highest-scoring candidates were used instead.', 'viral-video-ai' ),
						(string) $ranked['message']
					);

					$candidates = $pool;
				} else {
					$candidates = $this->merge_ranked( $ranked, $pool );
				}
			}
		}

		if ( ! $candidates ) {
			$out['code']    = 'no_clips';
			$out['message'] = __( 'The model returned no clips. It may have considered nothing in this video shareable.', 'viral-video-ai' );
			$out['hint']    = __( 'Retry with the "Viral Moments" focus, or relax the clip length window.', 'viral-video-ai' );
			$out['ok']      = false;

			return $out;
		}

		// ---- Validation, snapping, de-overlap.
		if ( is_callable( $progress ) ) {
			call_user_func( $progress, 68, __( 'Validating timestamps', 'viral-video-ai' ) );
		}

		$validated = $this->validate_clips( $candidates, $segments, $duration, $bounds, $expected );
		$clips     = $validated['clips'];
		$out['rejected'] = $validated['rejected'];
		$out['warnings'] = array_merge( $out['warnings'], $validated['warnings'] );
		$out['passes']   = $passes;

		// One correction round when the model produced unusable timestamps.
		if ( ! $clips && $out['rejected'] && ! $needs_chunking ) {
			$correction_prompt = $this->prompts->build_correction_prompt( $out['rejected'], array_merge( $shared, array( 'segments' => $segments ) ) );
			$corrected         = $this->request( $correction_prompt, $shared, $settings, $job );

			$out['passes']++;
			$out['usage'] = $this->merge_usage( $out['usage'], $corrected );

			if ( ! empty( $corrected['ok'] ) ) {
				$again = $this->validate_clips( (array) vvai_array_get( $corrected, 'clips', array() ), $segments, $duration, $bounds, $expected );

				if ( $again['clips'] ) {
					$clips = $again['clips'];
					$out['warnings'][] = __( 'The model fixed its timestamps after a validation pass.', 'viral-video-ai' );
				}
			}
		}

		if ( ! $clips ) {
			$out['code']    = 'invalid_timestamps';
			$out['message'] = __( 'The AI returned timestamps that do not match this video, so no clip was created.', 'viral-video-ai' );
			$out['hint']    = __( 'Every timestamp the model produced was outside the video or unmatched to the transcript.', 'viral-video-ai' );

			return $out;
		}

		$out['ok']    = true;
		$out['code']  = 'ok';
		$out['clips'] = $clips;

		return $out;
	}

	/**
	 * Validate and normalize raw model candidates.
	 *
	 * Public because the tests (and add-ons that post-process plans) exercise it
	 * without an API key.
	 *
	 * @param array<int,mixed>             $raw      Raw candidates.
	 * @param array<int,array<string,mixed>> $segments Transcript segments.
	 * @param float                          $duration Source duration.
	 * @param array<string,mixed>            $bounds   {min,max}.
	 * @param int                            $expected Number of clips wanted.
	 * @return array{clips:array<int,array<string,mixed>>,rejected:array<int,array<string,mixed>>,warnings:array<int,string>}
	 */
	public function validate_clips( array $raw, array $segments, $duration, array $bounds, $expected = 5 ) {
		$clips    = array();
		$rejected = array();
		$warnings = array();

		foreach ( array_values( $raw ) as $index => $candidate ) {
			$label = 'clip ' . ( $index + 1 );

			if ( ! is_array( $candidate ) ) {
				$rejected[] = array(
					'reason'     => 'not an object',
					'start_time' => null,
					'end_time'   => null,
				);
				continue;
			}

			$start = vvai_parse_time( vvai_array_get( $candidate, 'start_time', vvai_array_get( $candidate, 'start', null ) ) );
			$end   = vvai_parse_time( vvai_array_get( $candidate, 'end_time', vvai_array_get( $candidate, 'end', null ) ) );

			if ( false === $start || false === $end ) {
				$rejected[] = array(
					'reason'     => 'missing or non-numeric timestamps',
					'start_time' => $start,
					'end_time'   => $end,
				) + $candidate;
				continue;
			}

			if ( $end <= $start ) {
				$rejected[] = array(
					'reason'     => 'end_time is not after start_time',
					'start_time' => $start,
					'end_time'   => $end,
				) + $candidate;
				continue;
			}

			if ( $start < 0 || $end > ( $duration + 1.0 ) ) {
				$rejected[] = array(
					'reason'     => 'timestamps are outside the video duration (' . round( $start, 1 ) . 's - ' . round( $end, 1 ) . 's, duration ' . round( $duration, 1 ) . 's)',
					'start_time' => $start,
					'end_time'   => $end,
				) + $candidate;
				continue;
			}

			$length = $end - $start;

			if ( $length < max( 4.0, $bounds['min'] * 0.45 ) ) {
				$rejected[] = array(
					'reason'     => sprintf(
						/* translators: 1: clip length, 2: minimum length. */
						'clip is %.1fs, shorter than the %.0fs minimum',
						$length,
						$bounds['min']
					),
					'start_time' => $start,
					'end_time'   => $end,
				) + $candidate;
				continue;
			}

			// Snap to real transcript boundaries so cuts never land mid-sentence.
			$snap_start = VVAI_Transcript::snap( $segments, $start, 'start', $this->snap_tolerance() );
			$snap_end   = VVAI_Transcript::snap( $segments, $end, 'end', $this->snap_tolerance() );

			if ( $snap_start['index'] < 0 || $snap_end['index'] < 0 ) {
				$rejected[] = array(
					'reason'     => 'no transcript boundary matches these timestamps',
					'start_time' => $start,
					'end_time'   => $end,
				) + $candidate;
				continue;
			}

			$start = min( $snap_start['time'], $snap_end['time'] );
			$end   = max( $snap_start['time'], $snap_end['time'] );
			$length = $end - $start;

			if ( $length < max( 4.0, $bounds['min'] * 0.45 ) || $end <= $start ) {
				$rejected[] = array(
					'reason'     => 'clip too short after snapping to sentence boundaries',
					'start_time' => $start,
					'end_time'   => $end,
				) + $candidate;
				continue;
			}

			// Over-long candidates are trimmed by dropping trailing segments.
			if ( $length > $bounds['max'] * 1.35 ) {
				$trimmed = $this->trim_to_bounds( $segments, $snap_start['index'], $snap_end['index'], $bounds );

				if ( $trimmed['end'] > $trimmed['start'] ) {
					if ( abs( $trimmed['end'] - $end ) > 0.5 ) {
						$warnings[] = sprintf(
							/* translators: 1: original length, 2: trimmed length. */
							__( '%1$s of the requested clip was trimmed to fit the maximum length (%2$s).', 'viral-video-ai' ),
							$label,
							vvai_format_time( $trimmed['end'] - $trimmed['start'] )
						);
					}

					$end    = $trimmed['end'];
					$length = $end - $start;
				}
			}

			$score = vvai_sanitize_int( vvai_array_get( $candidate, 'viral_score', vvai_array_get( $candidate, 'score', 50 ) ), 1, 100, 50 );

			$title   = vvai_sanitize_text( vvai_array_get( $candidate, 'title', '' ), 90 );
			$caption = vvai_sanitize_paragraph( vvai_array_get( $candidate, 'social_caption', vvai_array_get( $candidate, 'caption', '' ) ), 400 );
			$reason  = vvai_sanitize_paragraph( vvai_array_get( $candidate, 'reasoning', vvai_array_get( $candidate, 'why', '' ) ), 500 );
			$tags    = vvai_sanitize_hashtags( vvai_array_get( $candidate, 'hashtags', vvai_array_get( $candidate, 'tags', '' ) ), 10 );
			$text    = VVAI_Transcript::text_between( $segments, $start, $end );

			if ( '' === $title ) {
				$title = $this->derive_title( $text );

				if ( '' !== $title ) {
					$warnings[] = sprintf(
						/* translators: %s: clip label. */
						__( '%s had no title from the model; a neutral one was derived from the transcript.', 'viral-video-ai' ),
						$label
					);
				}
			}

			if ( '' === $caption ) {
				$caption = $this->derive_caption( $text, $title );
			}

			if ( ! $tags ) {
				$tags = array( '#shorts', '#viral', '#fyp' );
			}

			$clips[] = array(
				'start_time'    => round( $start, 2 ),
				'end_time'      => round( $end, 2 ),
				'duration'      => round( $length, 2 ),
				'viral_score'   => $score,
				'reasoning'     => ( '' !== $reason ? $reason : __( 'No reasoning was returned by the model.', 'viral-video-ai' ) ),
				'title'         => ( '' !== $title ? $title : __( 'Highlighted moment', 'viral-video-ai' ) ),
				'social_caption'=> $caption,
				'hashtags'      => $tags,
				'transcript'    => substr( $text, 0, 4000 ),
				'segment_start' => (int) $snap_start['index'],
				'segment_end'   => (int) $snap_end['index'],
				'snapped'       => ( ! empty( $snap_start['snapped'] ) || ! empty( $snap_end['snapped'] ) ),
				'window'        => (int) vvai_array_get( $candidate, '__window', -1 ),
			);
		}

		$clips = $this->dedupe( $clips, $expected );

		// Ordering: score by default, chronological when configured.
		if ( 'chrono' === (string) $this->settings->get( 'results_order' ) ) {
			usort(
				$clips,
				static function ( $a, $b ) {
					return $a['start_time'] <=> $b['start_time'];
				}
			);
		} else {
			usort(
				$clips,
				static function ( $a, $b ) {
					return $b['viral_score'] <=> $a['viral_score'];
				}
			);
		}

		foreach ( $clips as $index => $clip ) {
			$clips[ $index ]['clip_number'] = $index + 1;
		}

		return array(
			'clips'    => $clips,
			'rejected' => $rejected,
			'warnings' => array_values( array_unique( $warnings ) ),
		);
	}

	/**
	 * Drop duplicates and overlapping clips, keeping the stronger candidate.
	 *
	 * @param array<int,array<string,mixed>> $clips    Validated clips.
	 * @param int                            $expected Maximum count.
	 * @return array<int,array<string,mixed>>
	 */
	public function dedupe( array $clips, $expected = 5 ) {
		usort(
			$clips,
			static function ( $a, $b ) {
				return $b['viral_score'] <=> $a['viral_score'];
			}
		);

		$accepted = array();

		foreach ( $clips as $clip ) {
			$conflict = false;

			foreach ( $accepted as $existing ) {
				$overlap = min( (float) $clip['end_time'], (float) $existing['end_time'] ) - max( (float) $clip['start_time'], (float) $existing['start_time'] );

				if ( $overlap > 1.0 ) {
					$conflict = true;
					break;
				}
			}

			if ( $conflict ) {
				continue;
			}

			$accepted[] = $clip;

			if ( count( $accepted ) >= max( 1, (int) $expected ) ) {
				break;
			}
		}

		return $accepted;
	}

	/**
	 * Clip length window for a job, validated against the source duration.
	 *
	 * @param array<string,mixed> $settings Job settings.
	 * @param float               $duration Source duration.
	 * @return array{ok:bool,min:float,max:float,code:string,message:string,hint:string}
	 */
	public function bounds( array $settings, $duration ) {
		$mode = (string) vvai_array_get( $settings, 'clip_length', 'short' );
		$min  = (float) vvai_array_get( $settings, 'min_duration', 0 );
		$max  = (float) vvai_array_get( $settings, 'max_duration', 0 );

		list( $range_min, $range_max ) = VVAI_Settings::duration_range( $mode, $min, $max );

		$min = $min > 0 ? $min : $range_min;
		$max = $max > 0 ? $max : $range_max;

		$result = array(
			'ok'      => true,
			'min'     => $min,
			'max'     => $max,
			'code'    => '',
			'message' => '',
			'hint'    => '',
		);

		if ( $duration <= 0 ) {
			return $result;
		}

		// The video is shorter than the requested clip length.
		if ( $duration < 8 ) {
			return array(
				'ok'      => false,
				'min'     => $min,
				'max'     => $max,
				'code'    => 'video_too_short',
				'message' => sprintf(
					/* translators: %s: duration. */
					__( 'This video is only %s long — too short to cut clips from.', 'viral-video-ai' ),
					vvai_format_time( $duration, true )
				),
				'hint'    => __( 'Upload a longer video (at least 15 seconds is practical).', 'viral-video-ai' ),
			);
		}

		if ( $duration < $min ) {
			// Shrink the window instead of failing, but never below 8 seconds.
			$result['min'] = max( 8.0, min( $min, $duration * 0.3 ) );
			$result['max'] = max( $result['min'] + 4, min( $max, $duration * 0.85 ) );
			$result['warnings'][] = true;

			return $result;
		}

		if ( $max > $duration ) {
			$result['max'] = max( $min + 4, $duration );
		}

		return $result;
	 }

	/**
	 * How many clips to ask for, bounded by the source length.
	 *
	 * @param array<string,mixed> $settings Job settings.
	 * @param float               $duration Source duration.
	 * @param array<string,mixed> $bounds   Length window.
	 * @return int
	 */
	public function expected_clips( array $settings, $duration, array $bounds ) {
		$requested = vvai_sanitize_int( vvai_array_get( $settings, 'target_clips', 5 ), 1, 20, 5 );
		$cap       = (int) $this->settings->get( 'max_clips' );
		$expected  = min( $requested, max( 1, $cap ) );

		if ( $duration > 0 ) {
			$average   = ( (float) $bounds['min'] + (float) $bounds['max'] ) / 2;
			$physical  = (int) floor( $duration / max( 5.0, $average * 1.08 ) );
			$expected  = max( 1, min( $expected, max( 1, $physical ) ) );
		}

		return $expected;
	}

	/**
	 * One AI request for analysis.
	 *
	 * @param string              $prompt    Prompt.
	 * @param array<string,mixed> $shared    Shared prompt args.
	 * @param array<string,mixed> $settings  Job settings.
	 * @param array<string,mixed> $job       Job row.
	 * @param array<string,mixed> $overrides Request overrides.
	 * @return array<string,mixed>
	 */
	protected function request( $prompt, array $shared, array $settings, array $job, array $overrides = array() ) {
		$expected = (int) vvai_array_get( $shared, 'expected_clips', 5 );

		$args = array(
			'prompt'      => $prompt,
			'system'      => $this->prompts->system_prompt(),
			'json'        => true,
			'max_tokens'  => isset( $overrides['max_tokens'] ) ? (int) $overrides['max_tokens'] : $this->response_budget( $expected ),
			'temperature' => (float) $this->settings->get( 'temperature' ),
			'timeout'     => max( 60, min( 900, (int) $this->settings->get( 'process_timeout' ) ) ),
			'connection'  => (string) vvai_array_get( $settings, 'connection_id', '' ),
			'job_id'      => (int) vvai_array_get( $job, 'id', 0 ),
			'purpose'     => 'analysis',
		);

		$result = $this->router->analyze_transcript(
			array(
				'prompt'  => $args['prompt'],
				'system'  => $args['system'],
			),
			$args
		);

		$result['clips'] = isset( $result['clips'] ) && is_array( $result['clips'] ) ? $result['clips'] : array();
		$result['hint']  = (string) vvai_array_get( $result, 'hint', '' );

		return $result;
	}

	/**
	 * Response token budget sized to the number of requested clips.
	 *
	 * @param int $expected Clips.
	 * @return int
	 */
	protected function response_budget( $expected ) {
		$per_clip = 260; // JSON scaffolding + title + caption + reasoning + tags.

		return max( 900, min( 24000, ( $expected + 1 ) * $per_clip ) );
	}

	/**
	 * Budget for the ranking pass (indexes only, so much smaller).
	 *
	 * @param int $expected Clips.
	 * @return int
	 */
	protected function ranking_tokens( $expected ) {
		return max( 400, min( 4000, $expected * 40 ) );
	}

	/**
	 * Prompt character budget per request.
	 *
	 * @return int
	 */
	protected function prompt_budget() {
		/**
		 * Filter the transcript characters sent to the model in one request.
		 *
		 * Lower it for providers with a small context window; raise it when the
		 * model can take more (the plugin chunks automatically).
		 *
		 * @param int $chars Characters.
		 */
		return (int) apply_filters( 'vvai_analysis_prompt_budget', 42000 );
	}

	/**
	 * Snap tolerance: how far a model timestamp may move to reach a boundary.
	 *
	 * @return float
	 */
	protected function snap_tolerance() {
		return (float) apply_filters( 'vvai_snap_tolerance', 45.0 );
	}

	/**
	 * Trim an over-long clip to the maximum length at a sentence boundary.
	 *
	 * @param array<int,array<string,mixed>> $segments Segments.
	 * @param int                            $from     Start segment index.
	 * @param int                            $to       End segment index.
	 * @param array<string,mixed>              $bounds  Window bounds.
	 * @return array{start:float,end:float}
	 */
	protected function trim_to_bounds( array $segments, $from, $to, array $bounds ) {
		$values = array_values( $segments );
		$start  = (float) $values[ $from ]['start'];
		$end    = $start;
		$max    = (float) $bounds['max'];

		for ( $i = $from; $i <= $to && $i < count( $values ); $i++ ) {
			$candidate = (float) $values[ $i ]['end'];

			if ( ( $candidate - $start ) > $max ) {
				break;
			}

			$end = $candidate;
		}

		if ( $end <= $start ) {
			// Not even one segment fits inside the maximum. Cutting mid-sentence to
			// hit the number would produce a clipped, unusable caption, so the
			// shortest *complete* boundary is kept instead and reported as a warning
			// by the caller. This also keeps the value stable if the plan is
			// validated again on resume.
			$end = (float) $values[ $from ]['end'];
		}

		return array(
			'start' => round( $start, 2 ),
			'end'   => round( $end, 2 ),
		);
	}

	/**
	 * Combine the ranking pass output with the candidate pool.
	 *
	 * The ranker only returns indexes + which candidates to keep, so the pool
	 * stays the source of truth for timestamps (no new numbers can be invented).
	 *
	 * @param array<string,mixed>      $ranked Ranked response.
	 * @param array<int,array<string,mixed>> $pool    Candidate pool.
	 * @return array<int,array<string,mixed>>
	 */
	protected function merge_ranked( array $ranked, array $pool ) {
		$selected = array();
		$list     = (array) vvai_array_get( $ranked, 'clips', array() );

		foreach ( $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$index = null;

			foreach ( array( 'candidate_index', 'index', 'id', 'candidate' ) as $key ) {
				if ( isset( $item[ $key ] ) && is_numeric( $item[ $key ] ) ) {
					$index = (int) $item[ $key ];
					break;
				}
			}

			if ( null === $index ) {
				// Index-based selection failed (models sometimes drop the field);
				// match by timestamp so the pool data is still used.
				$start = vvai_parse_time( vvai_array_get( $item, 'start_time', null ) );

				foreach ( $pool as $candidate_index => $candidate ) {
					$candidate_start = vvai_parse_time( vvai_array_get( $candidate, 'start_time', null ) );

					if ( false !== $start && abs( $candidate_start - $start ) < 1.5 ) {
						$index = (int) $candidate_index;
						break;
					}
				}
			}

			if ( null === $index || ! isset( $pool[ $index ] ) || in_array( $index, array_keys( $selected ), true ) ) {
				continue;
			}

			$merged = $pool[ $index ];

			foreach ( array( 'viral_score', 'title', 'social_caption', 'caption', 'hashtags', 'reasoning' ) as $field ) {
				if ( isset( $item[ $field ] ) ) {
					$merged[ $field ] = $item[ $field ];
				}
			}

			$selected[ $index ] = $merged;
		}

		if ( ! $selected ) {
			return $pool;
		}

		return array_values( $selected );
	}

	/**
	 * Merge provider usage counters.
	 *
	 * @param array<string,int> $usage   Accumulator.
	 * @param array<string,mixed> $result Provider result.
	 * @return array<string,int>
	 */
	protected function merge_usage( array $usage, array $result ) {
		$local = (array) vvai_array_get( $result, 'usage', array() );

		$usage['input']  = (int) vvai_array_get( $usage, 'input', 0 ) + (int) vvai_array_get( $local, 'input', 0 );
		$usage['output'] = (int) vvai_array_get( $usage, 'output', 0 ) + (int) vvai_array_get( $local, 'output', 0 );
		$usage['calls']  = (int) vvai_array_get( $usage, 'calls', 0 ) + 1;

		return $usage;
	}

	/**
	 * Fallback title derived from the actual words in the clip.
	 *
	 * @param string $text Clip transcript.
	 * @return string
	 */
	protected function derive_title( $text ) {
		$text = trim( preg_replace( '/[^\p{L}\p{N}\s\'\-]/u', ' ', (string) $text ) );
		$text = preg_replace( '/\s+/', ' ', $text );
		$words = array_slice( explode( ' ', $text ), 0, 7 );

		if ( count( $words ) < 3 ) {
			return '';
		}

		$title = implode( ' ', $words );
		$title = ucfirst( trim( $title ) );

		return substr( $title, 0, 80 );
	}

	/**
	 * Fallback caption derived from the transcript.
	 *
	 * @param string $text  Clip transcript.
	 * @param string $title Title.
	 * @return string
	 */
	protected function derive_caption( $text, $title ) {
		$sentence = trim( (string) $text );

		if ( '' !== $sentence ) {
			$sentence = preg_replace( '/\s+/', ' ', $sentence );
			$sentence = substr( $sentence, 0, 200 );
		}

		if ( '' !== $title ) {
			return trim( $title . ' — ' . $sentence );
		}

		return trim( $sentence );
	}

	/**
	 * Language instruction for the model.
	 *
	 * @return string
	 */
	protected function language_hint() {
		$code = (string) get_locale();

		$names = array(
			'en_US' => 'English',
			'en_GB' => 'English',
			'es_ES' => 'Spanish',
			'es_MX' => 'Spanish',
			'fr_FR' => 'French',
			'de_DE' => 'German',
			'pt_BR' => 'Brazilian Portuguese',
			'it_IT' => 'Italian',
			'nl_NL' => 'Dutch',
			'pl_PL' => 'Polish',
			'tr_TR' => 'Turkish',
			'ar'    => 'Arabic',
			'he_IL' => 'Hebrew',
			'hi_IN' => 'Hindi',
			'ja'    => 'Japanese',
			'ko_KR' => 'Korean',
			'zh_CN' => 'Simplified Chinese',
			'zh_TW' => 'Traditional Chinese',
			'ru_RU' => 'Russian',
			'uk'    => 'Ukrainian',
			'id_ID' => 'Indonesian',
		);

		foreach ( array( $code, substr( $code, 0, 2 ) ) as $key ) {
			if ( isset( $names[ $key ] ) ) {
				return $names[ $key ];
			}
		}

		return '';
	}
}
