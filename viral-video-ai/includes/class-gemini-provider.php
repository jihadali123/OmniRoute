<?php
/**
 * Google Gemini adapter.
 *
 * `models/{model}:generateContent` with the API key either in the query string
 * (the documented simple auth) or in `x-goog-api-key`, and
 * `generationConfig.responseMimeType = application/json` for constrained output.
 *
 * Gemini is also able to consume audio directly, so this adapter implements an
 * `inline_content` transcription path: the extracted audio is posted as base64
 * and the model is asked for timestamped segments.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Gemini_Provider
 */
class VVAI_Gemini_Provider extends VVAI_Provider_Base {

	/**
	 * Maximum audio payload accepted for the inline path (raw bytes).
	 */
	const MAX_INLINE_AUDIO = 18874368; // 18 MiB → ~24 MiB base64.

	/**
	 * {@inheritDoc}
	 */
	public function get_key() {
		return 'gemini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return 'Google Gemini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_model() {
		return 'gemini-2.0-flash';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_base_url() {
		return 'https://generativelanguage.googleapis.com/v1beta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_model_options() {
		return array(
			'gemini-2.0-flash',
			'gemini-2.0-flash-lite',
			'gemini-2.5-flash',
			'gemini-2.5-pro',
			'gemini-1.5-flash',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function auth_style() {
		return 'query-key';
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_native_json() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function transcription_mode() {
		return 'inline_content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities() {
		return array_merge(
			parent::get_capabilities(),
			array(
				'transcription_models' => array( 'gemini-2.0-flash', 'gemini-2.5-flash' ),
				'api_key_prefix'       => 'AIza',
				'docs'                 => 'https://aistudio.google.com/apikey',
				'notes'                => __( 'Huge context window. Audio can be transcribed through the model itself (timestamps are model-estimated), or delegated to a Whisper-compatible connection.', 'viral-video-ai' ),
				'json_mode'            => 'responseMimeType',
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function models_path() {
		return '/models';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Gemini authenticates with a query parameter (or x-goog-api-key); the
	 * Authorization header is not understood, so the probe has to carry the key.
	 */
	protected function build_probe_request( array $connection ) {
		$secret = $this->secret( $connection );

		if ( '' === $secret ) {
			return new WP_Error( 'missing_api_key', __( 'No API key saved for this connection yet.', 'viral-video-ai' ) );
		}

		return array(
			'method'  => 'GET',
			'url'     => $this->base_url_for( $connection ) . $this->models_path(),
			'headers' => $this->base_headers( $connection, $secret ),
			'query'   => array( 'key' => $secret ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function base_headers( array $connection, $secret ) {
		unset( $connection );

		// The query parameter is used for compatibility, the header is the
		// modern documented method. Sending both is accepted by the API.
		return array( 'x-goog-api-key' => $secret );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function build_generation_request( array $connection, array $request ) {
		$check = $this->check_prompt_size( (string) vvai_array_get( $request, 'prompt', '' ) );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$parts = array(
			array( 'text' => (string) vvai_array_get( $request, 'prompt', '' ) ),
		);

		$payload = array(
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => $parts,
				),
			),
			'generationConfig'  => array(
				'temperature'     => vvai_sanitize_float( vvai_array_get( $request, 'temperature', 0.4 ), 0, 2, 0.4 ),
				'maxOutputTokens' => vvai_sanitize_int( vvai_array_get( $request, 'max_tokens', 4000 ), 64, 65536, 4000 ),
			),
		);

		if ( '' !== trim( (string) vvai_array_get( $request, 'system', '' ) ) ) {
			$payload['systemInstruction'] = array( 'parts' => array( array( 'text' => (string) $request['system'] ) ) );
		}

		if ( ! empty( $request['json'] ) ) {
			$payload['generationConfig']['responseMimeType'] = 'application/json';
		}

		/** This filter is documented in includes/class-provider-base.php */
		$payload = apply_filters( 'vvai_provider_payload', $payload, $connection, $request );

		return array(
			'method'  => 'POST',
			'url'     => $this->base_url_for( $connection ) . '/models/' . rawurlencode( $this->model_for( $connection ) ) . ':generateContent',
			'headers' => array_merge(
				$this->base_headers( $connection, $this->secret( $connection ) ),
				array( 'Content-Type' => 'application/json' )
			),
			'query'   => array( 'key' => $this->secret( $connection ) ),
			'payload' => $payload,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_generation_response( $json, $body ) {
		unset( $body );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'bad_json', __( 'Gemini returned a body that was not JSON.', 'viral-video-ai' ) );
		}

		$text = '';

		if ( isset( $json['candidates'][0]['content']['parts'] ) && is_array( $json['candidates'][0]['content']['parts'] ) ) {
			foreach ( $json['candidates'][0]['content']['parts'] as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) ) {
					$text .= (string) $part['text'];
				}
			}
		}

		if ( '' === trim( $text ) ) {
			$reason = '';

			if ( isset( $json['promptFeedback']['blockReason'] ) ) {
				$reason = (string) $json['promptFeedback']['blockReason'];
			} elseif ( isset( $json['candidates'][0]['finishReason'] ) ) {
				$reason = (string) $json['candidates'][0]['finishReason'];
			}

			return new WP_Error(
				'missing_content',
				( '' !== $reason )
					? sprintf(
						/* translators: %s: finish reason. */
						__( 'Gemini produced no text (reason: %s). The prompt may have been blocked by the safety filters.', 'viral-video-ai' ),
						$reason
					)
					: __( 'Gemini produced no text. The prompt may have been blocked by the safety filters.', 'viral-video-ai' )
			);
		}

		$usage = array();

		if ( isset( $json['usageMetadata'] ) && is_array( $json['usageMetadata'] ) ) {
			$usage = $this->normalize_usage(
				array(
					'input_tokens'  => vvai_array_get( $json['usageMetadata'], 'promptTokenCount', 0 ),
					'output_tokens' => vvai_array_get( $json['usageMetadata'], 'candidatesTokenCount', 0 ),
					'total_tokens'  => vvai_array_get( $json['usageMetadata'], 'totalTokenCount', 0 ),
				)
			);
		}

		return array(
			'text'  => $text,
			'usage' => $usage,
			'model' => '',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_models_response( $json, $body ) {
		$models = parent::parse_models_response( $json, $body );

		// Gemini names models `models/gemini-2.0-flash`; the API wants the bare id.
		return array_values(
			array_filter(
				array_map(
					static function ( $model ) {
						$model = (string) $model;

						if ( 0 === strpos( $model, 'models/' ) ) {
							$model = substr( $model, 7 );
						}

						return $model;
					},
					$models
				)
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function transcribe( array $connection, $audio_path, array $args = array() ) {
		$out = array(
			'ok'        => false,
			'segments'  => array(),
			'text'      => '',
			'code'      => '',
			'message'   => '',
			'latency'   => 0,
			'retryable' => false,
			'model'     => '',
		);

		if ( ! is_file( $audio_path ) ) {
			$out['code']    = 'missing_audio';
			$out['message'] = __( 'The extracted audio file is missing, so Gemini cannot transcribe it.', 'viral-video-ai' );

			return $out;
		}

		$size = (int) filesize( $audio_path );

		if ( $size > self::MAX_INLINE_AUDIO ) {
			$out['code']    = 'audio_too_large';
			$out['message'] = sprintf(
				/* translators: %s: human size limit. */
				__( 'The audio chunk (%1$s) is larger than the %2$s Gemini inline limit. Lower the transcription chunk length in the settings.', 'viral-video-ai' ),
				vvai_human_size( $size ),
				vvai_human_size( self::MAX_INLINE_AUDIO )
			);

			return $out;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- audio chunk for inline upload.
		$contents = @file_get_contents( $audio_path );

		if ( false === $contents || '' === $contents ) {
			$out['code']    = 'unreadable_audio';
			$out['message'] = __( 'The extracted audio file could not be read.', 'viral-video-ai' );

			return $out;
		}

		$extension = strtolower( (string) pathinfo( $audio_path, PATHINFO_EXTENSION ) );
		$mime      = $this->audio_mime_for( $extension );
		$model     = $this->model_for( $connection );
		$start     = (float) vvai_array_get( $args, 'offset', 0 );
		$duration  = (float) vvai_array_get( $args, 'duration', 0 );

		$prompt = "Transcribe this audio.\n";
		$prompt .= 'Return ONLY a JSON object of the form {"segments":[{"start":SECONDS,"end":SECONDS,"text":"…"}]}.'."\n";
		$prompt .= 'Rules: start/end are plain numbers in seconds from the beginning of THIS audio file (' . sprintf( '%.2f s long', max( 1, $duration ) ) . '), in ascending order, no overlap, one entry per sentence or per speaker turn. Keep the original language and wording verbatim. No commentary.';

		$language = (string) vvai_array_get( $args, 'language', '' );

		if ( '' !== $language ) {
			$prompt .= ' Spoken language hint: ' . sanitize_text_field( $language ) . '.';
		}

		$payload = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $prompt ),
						array(
							'inline_data' => array(
								'mime_type' => $mime,
								'data'      => base64_encode( $contents ),
							),
						),
					),
				),
			),
			'generationConfig' => array(
				'temperature'     => 0,
				'maxOutputTokens' => 65536,
				'responseMimeType' => 'application/json',
			),
		);

		$response = $this->http->request(
			'POST',
			$this->base_url_for( $connection ) . '/models/' . rawurlencode( $model ) . ':generateContent',
			array(
				'headers'  => array_merge( $this->base_headers( $connection, $this->secret( $connection ) ), array( 'Content-Type' => 'application/json' ) ),
				'json'     => $payload,
				'query'    => array( 'key' => $this->secret( $connection ) ),
				'timeout'  => min( 600, max( 60, (int) vvai_array_get( $args, 'timeout', 300 ) ) ),
				'job_hint' => (string) vvai_array_get( $args, 'job_hint', 'gemini transcription' ),
			)
		);

		$out['latency'] = (int) $response['latency'];
		$out['model']   = $model;

		if ( ! $response['ok'] ) {
			$error = is_array( $response['error'] ) ? $response['error'] : array();

			$out['code']      = (string) vvai_array_get( $error, 'code', 'transcription_failed' );
			$out['message']   = (string) vvai_array_get( $error, 'message', __( 'Gemini could not transcribe this audio.', 'viral-video-ai' ) );
			$out['retryable'] = (bool) vvai_array_get( $error, 'retryable', false );

			return $out;
		}

		$parsed = $this->parse_generation_response( $response['json'], $response['body'] );

		if ( is_wp_error( $parsed ) ) {
			$out['code']    = 'transcription_unparseable';
			$out['message'] = $parsed->get_error_message();

			return $out;
		}

		$decoded = VVAI_Json::extract( $parsed['text'] );

		if ( empty( $decoded['ok'] ) || ! is_array( $decoded['data'] ) ) {
			$out['code']    = 'transcription_unparseable';
			$out['message'] = __( 'Gemini returned a transcription that was not valid JSON.', 'viral-video-ai' );

			return $out;
		}

		$raw_segments = isset( $decoded['data']['segments'] ) && is_array( $decoded['data']['segments'] )
			? $decoded['data']['segments']
			: ( ( isset( $decoded['data']['transcript'] ) && is_array( $decoded['data']['transcript'] ) ) ? $decoded['data']['transcript'] : array() );

		$segments = array();

		foreach ( $raw_segments as $segment ) {
			if ( ! is_array( $segment ) ) {
				continue;
			}

			$text = vvai_sanitize_text( vvai_array_get( $segment, 'text', '' ), 400 );

			if ( '' === $text ) {
				continue;
			}

			$segment_start = vvai_parse_time( vvai_array_get( $segment, 'start', vvai_array_get( $segment, 'start_time', null ) ) );
			$segment_end   = vvai_parse_time( vvai_array_get( $segment, 'end', vvai_array_get( $segment, 'end_time', null ) ) );

			if ( false === $segment_start ) {
				$segment_start = $segments ? (float) end( $segments )['end'] : 0.0;
			}

			$segment_start += $start;

			if ( ! is_numeric( $segment_end ) || $segment_end <= 0 ) {
				$segment_end = $segment_start + max( 3, strlen( $text ) / 15 );
			} else {
				$segment_end += $start;
			}

			$segments[] = array(
				'start' => round( $segment_start, 2 ),
				'end'   => round( max( $segment_start + 0.5, $segment_end ), 2 ),
				'text'  => $text,
			);
		}

		if ( ! $segments ) {
			$out['code']    = 'empty_transcription';
			$out['message'] = __( 'Gemini returned no transcript segments for this audio chunk.', 'viral-video-ai' );

			return $out;
		}

		usort(
			$segments,
			static function ( $a, $b ) {
				return $a['start'] <=> $b['start'];
			}
		);

		$out['ok']       = true;
		$out['code']     = 'ok';
		$out['segments'] = $segments;
		$out['text']     = implode( ' ', wp_list_pluck( $segments, 'text' ) );

		return $out;
	}
}
