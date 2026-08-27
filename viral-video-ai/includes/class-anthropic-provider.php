<?php
/**
 * Anthropic Claude adapter.
 *
 * Uses the Messages API (`/v1/messages`) with `x-api-key` + `anthropic-version`
 * headers, a separate `system` field, and a mandatory `max_tokens`.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Anthropic_Provider
 */
class VVAI_Anthropic_Provider extends VVAI_Provider_Base {

	const VERSION = '2023-06-01';

	/**
	 * {@inheritDoc}
	 */
	public function get_key() {
		return 'anthropic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return 'Anthropic Claude';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_model() {
		return 'claude-3-5-haiku-latest';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_base_url() {
		return 'https://api.anthropic.com/v1';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_model_options() {
		return array(
			'claude-3-5-haiku-latest',
			'claude-3-5-sonnet-latest',
			'claude-3-7-sonnet-latest',
			'claude-sonnet-4-20250514',
			'claude-opus-4-20250514',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function auth_style() {
		return 'x-api-key';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities() {
		return array_merge(
			parent::get_capabilities(),
			array(
				'transcription_models' => array(),
				'api_key_prefix'       => 'sk-ant-',
				'docs'                 => 'https://console.anthropic.com/settings/keys',
				'notes'                => __( 'Strong at long transcripts. No transcription endpoint and no forced-JSON mode: the JSON schema is enforced by the prompt and validated server-side.', 'viral-video-ai' ),
				'json_mode'            => 'prompt-only',
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_json() {
		// The plugin still asks for JSON and validates it; the provider simply
		// has no server-side constraint, hence supports_native_json() is false.
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function chat_path() {
		return '/messages';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function base_headers( array $connection, $secret ) {
		unset( $connection );

		return array(
			'x-api-key'         => $secret,
			'anthropic-version' => self::VERSION,
			'Accept'            => 'application/json',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function build_generation_request( array $connection, array $request ) {
		$check = $this->check_prompt_size( (string) vvai_array_get( $request, 'prompt', '' ) );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => (string) vvai_array_get( $request, 'prompt', '' ),
			),
		);

		$payload = array(
			'model'       => $this->model_for( $connection ),
			'messages'    => $messages,
			'max_tokens'  => vvai_sanitize_int( vvai_array_get( $request, 'max_tokens', 4000 ), 256, 64000, 4000 ),
			'temperature' => vvai_sanitize_float( vvai_array_get( $request, 'temperature', 0.4 ), 0, 1, 0.4 ),
			'stream'      => false,
		);

		if ( '' !== trim( (string) vvai_array_get( $request, 'system', '' ) ) ) {
			$payload['system'] = (string) $request['system'];
		}

		/** This filter is documented in includes/class-provider-base.php */
		$payload = apply_filters( 'vvai_provider_payload', $payload, $connection, $request );

		return array(
			'method'  => 'POST',
			'url'     => $this->base_url_for( $connection ) . $this->chat_path(),
			'headers' => array_merge(
				$this->base_headers( $connection, $this->secret( $connection ) ),
				array( 'Content-Type' => 'application/json' )
			),
			'payload' => $payload,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_generation_response( $json, $body ) {
		unset( $body );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'bad_json', __( 'Anthropic returned a body that was not JSON.', 'viral-video-ai' ) );
		}

		$text = '';

		if ( isset( $json['content'] ) && is_array( $json['content'] ) ) {
			foreach ( $json['content'] as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				// Thinking blocks are intentionally skipped: only text is fed
				// to the JSON validator.
				if ( 'text' === (string) vvai_array_get( $block, 'type', 'text' ) && isset( $block['text'] ) ) {
					$text .= (string) $block['text'];
				}
			}
		}

		if ( '' === trim( $text ) ) {
			$reason = (string) vvai_array_get( $json, 'stop_reason', '' );

			return new WP_Error(
				'missing_content',
				( '' !== $reason )
					? sprintf(
						/* translators: %s: stop reason. */
						__( 'The model returned no text (stop reason: %s).', 'viral-video-ai' ),
						$reason
					)
					: __( 'The model returned no text.', 'viral-video-ai' )
			);
		}

		return array(
			'text'  => $text,
			'usage' => isset( $json['usage'] ) && is_array( $json['usage'] ) ? $this->normalize_usage( $json['usage'] ) : array(),
			'model' => isset( $json['model'] ) ? sanitize_text_field( (string) $json['model'] ) : '',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_models_response( $json, $body ) {
		unset( $body );

		if ( ! is_array( $json ) || ! isset( $json['data'] ) || ! is_array( $json['data'] ) ) {
			return array();
		}

		$models = array();

		foreach ( $json['data'] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'] ) && is_string( $entry['id'] ) ) {
				$models[] = $entry['id'];
			}
		}

		sort( $models, SORT_STRING );

		return array_slice( $models, 0, 200 );
	}
}
