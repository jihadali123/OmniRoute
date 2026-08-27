<?php
/**
 * AI prompt engine (spec §49).
 *
 * Builds the exact instruction set that makes the model behave like a clip
 * editor instead of a chatbot: fixed JSON schema, real transcript timestamps
 * only, duration windows, content focus, no-overlap and self-containment
 * rules. Kept separate from the analysis logic so the wording can be tuned (or
 * filtered) without touching the pipeline.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Prompt_Builder
 */
class VVAI_Prompt_Builder {

	/**
	 * Focus presets: what to look for, phrased for a model.
	 *
	 * @return array<string,array{label:string,instructions:string}>
	 */
	public static function focuses() {
		return array(
			'viral'     => array(
				'label'        => __( 'Viral Moments', 'viral-video-ai' ),
				'instructions' => 'Find the moments most likely to stop the scroll: a strong hook in the first 3 seconds, surprise, an unexpected claim, a story payoff, or a highly shareable line. Rank by rewatch/share potential.',
			),
			'action'    => array(
				'label'        => __( 'High Action', 'viral-video-ai' ),
				'instructions' => 'Prioritise high-energy, fast-moving, physically intense or action-packed passages. Prefer segments where something visibly happens over talking-head exposition.',
			),
			'dialogue'  => array(
				'label'        => __( 'Key Dialogues / Humor', 'viral-video-ai' ),
				'instructions' => 'Prioritise memorable lines, punchlines, witty exchanges and quotable dialogue. The clip must land the joke or the quote inside its own runtime.',
			),
			'emotional' => array(
				'label'        => __( 'Emotional Highlights', 'viral-video-ai' ),
				'instructions' => 'Prioritise emotional intensity:confession, conflict, confrontation, revelation, vulnerability, triumph. Keep the lead-in that makes the emotion legible.',
			),
			'insight'   => array(
				'label'        => __( 'Useful Insight', 'viral-video-ai' ),
				'instructions' => 'Prioritise self-contained teaching moments: one insight, tactic, number or lesson a viewer can act on immediately, with the setup needed to understand it.',
			),
			'custom'    => array(
				'label'        => __( 'Custom', 'viral-video-ai' ),
				'instructions' => '',
			),
		);
	}

	/**
	 * The system instruction shared by every provider.
	 *
	 * @return string
	 */
	public function system_prompt() {
		return 'You are a senior short-form video editor and growth strategist. You watch a transcript of a long video and cut it into standalone vertical clips that would perform on TikTok, YouTube Shorts and Instagram Reels. '
			. 'You never invent content. You only reference timestamps that exist in the supplied transcript. '
			. 'You answer with a single JSON object and nothing else: no markdown, no code fence, no preamble, no commentary, no trailing commas.';
	}

	/**
	 * Build the user prompt for one analysis pass.
	 *
	 * @param array<string,mixed> $args {
	 *     @type int    $duration       Source duration in seconds.
	 *     @type float  $min_seconds    Minimum clip length.
	 *     @type float  $max_seconds    Maximum clip length.
	 *     @type int    $expected_clips Number of clips requested.
	 *     @type string $focus          Focus key.
	 *     @type string $custom_focus   Free-form focus text when focus=custom.
	 *     @type array  $segments       Transcript segments [{start,end,text}].
	 *     @type string $language       Expected output language name, optional.
	 *     @type array  $metadata       Video metadata for context.
	 *     @type int    $window_start   Chunk offset (seconds) for chunked passes.
	 *     @type int    $window_end     Chunk end (seconds).
	 *     @type bool   $candidates     True for a candidate-discovery pass (looser output).
	 * }
	 * @return string
	 */
	public function build( array $args ) {
		$segments      = isset( $args['segments'] ) && is_array( $args['segments'] ) ? $args['segments'] : array();
		$duration      = max( 1.0, (float) vvai_array_get( $args, 'duration', 0 ) );
		$min_seconds   = max( 5.0, (float) vvai_array_get( $args, 'min_seconds', 30 ) );
		$max_seconds   = max( $min_seconds + 5, (float) vvai_array_get( $args, 'max_seconds', 60 ) );
		$expected      = vvai_sanitize_int( vvai_array_get( $args, 'expected_clips', 5 ), 1, 20, 5 );
		$focus         = (string) vvai_array_get( $args, 'focus', 'viral' );
		$custom        = trim( (string) vvai_array_get( $args, 'custom_focus', '' ) );
		$candidates    = ! empty( $args['candidates'] );
		$window_start  = (float) vvai_array_get( $args, 'window_start', 0 );
		$window_end    = (float) vvai_array_get( $args, 'window_end', $duration );
		$focus_list    = self::focuses();
		$instructions  = isset( $focus_list[ $focus ] ) ? $focus_list[ $focus ]['instructions'] : '';

		if ( 'custom' === $focus && '' !== $custom ) {
			$instructions = 'The client supplied this specific editorial direction, follow it exactly: ' . $custom;
		}

		if ( '' === $instructions ) {
			$instructions = $focus_list['viral']['instructions'];
		}

		$lines   = array();
		$lines[] = '# TASK';
		$lines[] = 'Identify the strongest self-contained moments in the transcript below that should be cut into short vertical videos, then return exact timestamps for them.';
		$lines[] = '';
		$lines[] = '# SOURCE';
		$lines[] = '- Total video duration: ' . sprintf( '%.2f seconds (%s)', $duration, vvai_format_time( $duration ) );
		$lines[] = '- Transcript window analysed here: ' . sprintf( '%.2f s → %.2f s (%s → %s)', $window_start, $window_end, vvai_format_time( $window_start ), vvai_format_time( $window_end ) );

		if ( ! empty( $args['metadata'] ) && is_array( $args['metadata'] ) ) {
			$meta      = $args['metadata'];
			$lines[]   = '- Source: ' . sprintf(
				'%dx%d, %.2f fps, %s video / %s audio, %s',
				(int) vvai_array_get( $meta, 'width', 0 ),
				(int) vvai_array_get( $meta, 'height', 0 ),
				(float) vvai_array_get( $meta, 'fps', 0 ),
				(string) vvai_array_get( $meta, 'vcodec', 'unknown' ),
				( (int) vvai_array_get( $meta, 'has_audio', 0 ) ? (string) vvai_array_get( $meta, 'acodec', 'unknown' ) : 'no' ),
				vvai_human_size( (int) vvai_array_get( $meta, 'file_size', 0 ) )
			);
		}

		$lines[] = '';
		$lines[] = '# EDITORIAL TARGET';
		$lines[] = '- Number of clips requested: ' . $expected . ( $candidates ? ' per window (this is a discovery pass)' : '' );
		$lines[] = '- Clip length: between ' . sprintf( '%d and %d seconds', (int) floor( $min_seconds ), (int) ceil( $max_seconds ) ) . '.';
		$lines[] = '- Content focus: ' . $instructions;
		$lines[] = '- Evaluate each candidate against: strong hook, emotional intensity, surprise, humour, conflict, useful insight, controversial statement, strong opinion, story payoff, suspense, curiosity gap, high-energy action, memorable dialogue, relatability, shareability.';
		$lines[] = '';
		$lines[] = '# HARD RULES';
		$lines[] = '1. start_time and end_time MUST be taken from the timestamps of the transcript lines below. Do not invent timestamps.';
		$lines[] = '2. A clip must start at the beginning of a transcript line and end at the end of a transcript line, so it never begins or ends mid-sentence.';
		$lines[] = '3. Both timestamps must fall inside the analysed window and inside the video duration. end_time > start_time.';
		$lines[] = '4. Respect the clip length window; if a great moment is longer than the maximum, trim it to the most valuable continuous part.';
		$lines[] = '5. Clips must not overlap each other and must not repeat the same content. Leave at least 2 seconds between clips.';
		$lines[] = '6. Prefer self-contained moments: the viewer must understand the clip without having seen the rest of the video. Include the minimum setup the moment needs.';
		$lines[] = '7. Titles, captions and hashtags must describe what actually happens. No clickbait that the clip does not deliver.';
		$lines[] = '8. Skip filler: intros, outro pitches, sponsor reads, small talk, and long pauses, unless they carry the hook themselves.';

		if ( ! empty( $args['language'] ) ) {
			$lines[] = '9. Write title, social_caption and reasoning in ' . $args['language'] . '. Keep transcript quoting accurate.';
		}

		$lines[] = '';
		$lines[] = '# TRANSCRIPT (start | end | text, in seconds)';

		foreach ( $segments as $segment ) {
			$lines[] = sprintf(
				'[%.2f | %.2f] %s',
				(float) vvai_array_get( $segment, 'start', 0 ),
				(float) vvai_array_get( $segment, 'end', 0 ),
				(string) vvai_array_get( $segment, 'text', '' )
			);
		}

		$lines[] = '';
		$lines[] = '# OUTPUT FORMAT';

		if ( $candidates ) {
			$lines[] = 'Return ONLY this JSON object, nothing else:';
			$lines[] = '{"clips":[{"start_time":125.40,"end_time":174.80,"viral_score":94,"reasoning":"Why this moment works, one sentence.","title":"Short punchy title","social_caption":"Caption for the post","hashtags":["#viral","#shorts"]}]}';
			$lines[] = 'Return an empty array ({"clips":[]}) when the window contains nothing worth clipping. Never pad with weak candidates.';
		} else {
			$lines[] = 'Return ONLY this JSON object, nothing else. Exactly ' . $expected . ' clips, ordered by viral_score descending:';
			$lines[] = '{"clips":[{"start_time":125.40,"end_time":174.80,"viral_score":94,"reasoning":"Strong emotional payoff with a high curiosity hook.","title":"The Moment Everything Changed","social_caption":"This moment completely changed the story…","hashtags":["#viral","#shorts","#trending"]}]}';
		}

		$lines[] = '';
		$lines[] = '# FIELD RULES';
		$lines[] = '- start_time, end_time: numbers in seconds, copied from the transcript.';
		$lines[] = '- viral_score: integer 1-100, honest (reserve 90+ for genuinely exceptional moments).';
		$lines[] = '- reasoning: one sentence, specific to this content, no generic praise.';
		$lines[] = '- title: max 60 characters, no hashtags, no emojis, no trailing punctuation.';
		$lines[] = '- social_caption: max 220 characters, written for the platform, ends with a call to action or a question.';
		$lines[] = '- hashtags: 3-8 items, each starting with #, no duplicates, no spaces.';
		$lines[] = '- No additional keys. No markdown. JSON only.';

		$prompt = implode( "\n", $lines );

		/**
		 * Filter the assembled analysis prompt.
		 *
		 * @param string $prompt Prompt text.
		 * @param array  $args    Builder arguments.
		 */
		return (string) apply_filters( 'vvai_analysis_prompt', $prompt, $args );
	}

	/**
	 * Ranking pass for long transcripts (spec §48).
	 *
	 * The model only chooses and orders candidates that already exist; it never
	 * supplies new timestamps, which keeps the anti-hallucination guarantee.
	 *
	 * @param array<int,array<string,mixed>> $candidates Candidate pool.
	 * @param int                            $expected    Number of clips to keep.
	 * @param array<string,mixed>            $shared      Shared prompt context.
	 * @return string
	 */
	public function build_ranking_prompt( array $candidates, $expected, array $shared = array() ) {
		$lines   = array();
		$lines[] = '# RANKING TASK';
		$lines[] = 'Below are candidate clips already extracted from the video, each with the transcript evidence for it. Choose the ' . max( 1, (int) $expected ) . ' clips that will perform best as short vertical videos, best first.';
		$lines[] = '';
		$lines[] = '# RULES';
		$lines[] = '- Reference candidates by their index (the number in square brackets). Never invent new timestamps.';
		$lines[] = '- Do not keep two candidates that cover the same content; keep the stronger one.';
		$lines[] = '- Keep the selection diverse: five different moments beat five variations of one moment.';
		$lines[] = '- Re-score each chosen candidate 1-100 using the viral criteria below.';
		$lines[] = '- Rewrite title / social_caption / hashtags for the chosen clips only, from the evidence text provided.';
		$lines[] = '';
		$lines[] = '# CRITERIA';
		$lines[] = 'Strong hook, emotional intensity, surprise, humour, conflict, useful insight, controversial statement, strong opinion, story payoff, suspense, curiosity gap, high-energy action, memorable dialogue, relatability, shareability.';
		$lines[] = '';

		if ( ! empty( $shared['min_seconds'] ) && ! empty( $shared['max_seconds'] ) ) {
			$lines[] = '# CLIP LENGTH';
			$lines[] = sprintf( 'Each clip must stay within %.0f-%.0f seconds.', (float) $shared['min_seconds'], (float) $shared['max_seconds'] );
			$lines[] = '';
		}

		$lines[] = '# CANDIDATES';

		foreach ( array_values( $candidates ) as $index => $candidate ) {
			$lines[] = sprintf(
				'[%d] %.2fs - %.2fs (%.0fs) score=%s title="%s"',
				$index,
				(float) vvai_array_get( $candidate, 'start_time', 0 ),
				(float) vvai_array_get( $candidate, 'end_time', 0 ),
				(float) vvai_array_get( $candidate, 'end_time', 0 ) - (float) vvai_array_get( $candidate, 'start_time', 0 ),
				(string) vvai_array_get( $candidate, 'viral_score', '?' ),
				vvai_sanitize_text( vvai_array_get( $candidate, 'title', '' ), 70 )
			);
			$lines[] = '    why: ' . vvai_sanitize_text( vvai_array_get( $candidate, 'reasoning', '' ), 200 );
			$lines[] = '    said: ' . vvai_sanitize_text( vvai_array_get( $candidate, 'transcript', '' ), 700 );
		}

		$lines[] = '';
		$lines[] = '# OUTPUT';
		$lines[] = 'Return ONLY JSON: {"clips":[{"candidate_index":3,"viral_score":94,"reasoning":"…","title":"…","social_caption":"…","hashtags":["#viral"]}]}';
		$lines[] = 'Copy nothing else: the server re-applies the timestamps from the candidate you referenced. No markdown, no other keys.';

		/** This filter is documented in includes/class-prompt-builder.php */
		return (string) apply_filters( 'vvai_ranking_prompt', implode( "\n", $lines ), $candidates, $expected, $shared );
	}

	/**
	 * Prompt used to ask a model to fix rejected timestamps (spec §50).
	 *
	 * @param array<int,array<string,mixed>> $rejected Rejected candidates with reasons.
	 * @param array<string,mixed>            $args     Original builder args.
	 * @return string
	 */
	public function build_correction_prompt( array $rejected, array $args ) {
		$lines   = array();
		$lines[] = '# CORRECTION PASS';
		$lines[] = 'Your previous answer was rejected by the server validator. Fix ONLY the timestamps; keep the same moments.';
		$lines[] = '';
		$lines[] = '# VALIDATOR ERRORS';

		foreach ( $rejected as $index => $item ) {
			$lines[] = sprintf(
				'- Clip %d: %s (start_time=%s, end_time=%s). Window: %.2f-%.2f s, length must be %.0f-%.0f s.',
				$index + 1,
				(string) vvai_array_get( $item, 'reason', 'invalid' ),
				var_export( vvai_array_get( $item, 'start_time', null ), true ),
				var_export( vvai_array_get( $item, 'end_time', null ), true ),
				(float) vvai_array_get( $args, 'window_start', 0 ),
				(float) vvai_array_get( $args, 'window_end', 0 ),
				(float) vvai_array_get( $args, 'min_seconds', 30 ),
				(float) vvai_array_get( $args, 'max_seconds', 60 )
			);
		}

		$lines[] = '';
		$lines[] = '# TRANSCRIPT (start | end | text, in seconds)';

		foreach ( (array) vvai_array_get( $args, 'segments', array() ) as $segment ) {
			$lines[] = sprintf(
				'[%.2f | %.2f] %s',
				(float) vvai_array_get( $segment, 'start', 0 ),
				(float) vvai_array_get( $segment, 'end', 0 ),
				(string) vvai_array_get( $segment, 'text', '' )
			);
		}

		$lines[] = '';
		$lines[] = 'Return the corrected JSON object only, same schema as before: {"clips":[…]}.';

		return implode( "\n", $lines );
	}

	/**
	 * System prompt for the segment-summarising pass used on very long videos.
	 *
	 * @return string
	 */
	public function summarize_system_prompt() {
		return 'You compress a timestamped transcript into editorial notes for a video editor. Keep every line short, keep the timestamps attached, and never invent content.';
	}

	/**
	 * Build the chunk summarisation prompt (spec §48).
	 *
	 * @param array<int,array<string,mixed>> $segments Segments in this chunk.
	 * @return string
	 */
	public function build_summarize_prompt( array $segments ) {
		$lines   = array();
		$lines[] = 'Below is one chunk of a long timestamped transcript. Produce editorial notes that let me pick clips without re-reading the transcript.';
		$lines[] = 'Return ONLY JSON: {"notes":[{"start":12.0,"end":45.0,"topic":"…","hook":"…","emotion":"neutral|funny|intense|controversial|inspirational","worth_clipping":true,"why":"…"}]}';
		$lines[] = 'Cover the chunk with 3-12 notes. Keep start/end values from the transcript. Mark worth_clipping=false for filler.';
		$lines[] = '';
		$lines[] = 'TRANSCRIPT:';

		foreach ( $segments as $segment ) {
			$lines[] = sprintf(
				'[%.2f | %.2f] %s',
				(float) vvai_array_get( $segment, 'start', 0 ),
				(float) vvai_array_get( $segment, 'end', 0 ),
				(string) vvai_array_get( $segment, 'text', '' )
			);
		}

		return implode( "\n", $lines );
	}
}
