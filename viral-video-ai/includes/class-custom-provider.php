<?php
/**
 * Custom / self-hosted provider adapter.
 *
 * For advanced users pointing at an OpenAI-compatible (or Anthropic-compatible)
 * gateway: LiteLLM, vLLM, Ollama, llama.cpp server, Azure proxies, an internal
 * inference mesh. Only a base URL is required; everything else is derived.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Custom_Provider
 */
class VVAI_Custom_Provider extends VVAI_Provider_Base {

	/**
	 * {@inheritDoc}
	 */
	public function get_key() {
		return 'custom';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return 'Custom AI Provider';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_model() {
		return 'gpt-4o-mini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_base_url() {
		// No global default: a custom connection must declare its endpoint.
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_model_options() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_json() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports_native_json() {
		return 'openai' === $this->style();
	}

	/**
	 * {@inheritDoc}
	 */
	public function transcription_mode() {
		return 'openai' === $this->style() ? 'audio_api' : 'none';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities() {
		return array_merge(
			parent::get_capabilities(),
			array(
				'api_key_prefix'       => '',
				'docs'                 => '',
				'notes'                => __( 'Any OpenAI-compatible (/chat/completions) or Anthropic-compatible (/messages) endpoint. Requires the Base URL in the Advanced section.', 'viral-video-ai' ),
				'requires_base_url'    => true,
				'styles'               => array( 'openai', 'anthropic' ),
			)
		);
	}

	/**
	 * Wire style: openai (default) or anthropic.
	 *
	 * @return string
	 */
	public function style() {
		$connection = $this->current_connection;

		$style = (string) vvai_array_get( (array) $connection, 'custom_style', 'openai' );

		return in_array( $style, array( 'openai', 'anthropic' ), true ) ? $style : 'openai';
	}

	/**
	 * Connection currently being used, needed by style() inside overrides.
	 *
	 * @var array<string,mixed>|null
	 */
	private $current_connection = null;

	/**
	 * Verify a custom endpoint.
	 *
	 * Self-hosted gateways differ: some expose /models, some only the completion
	 * route. Both are tried for real, so "Connected" always means "this endpoint
	 * accepted our key and answered".
	 *
	 * {@inheritDoc}
	 */
	public function validate_credentials( array $connection ) {
		$this->current_connection = $connection;

		$fail = static function ( $code, $message ) {
			return array(
				'ok'        => false,
				'code'      => $code,
				'message'   => $message,
				'http'      => 0,
				'latency'   => 0,
				'retryable' => false,
				'models'    => array(),
			);
		};

		if ( '' === trim( (string) vvai_array_get( $connection, 'base_url', '' ) ) ) {
			$this->current_connection = null;

			return $fail(
				'missing_base_url',
				__( 'A custom provider needs a base URL. Open the Advanced section of this connection and set it (for example https://gateway.example.com/v1).', 'viral-video-ai' )
			);
		}

		if ( '' === $this->secret( $connection ) ) {
			$this->current_connection = null;

			return $fail( 'missing_api_key', __( 'No API key saved for this connection yet.', 'viral-video-ai' ) );
		}

		$result  = parent::validate_credentials( $connection );
		$probe   = $this->build_probe_request( $connection );
		$skipped = is_wp_error( $probe );

		// Gateway has no /models route at all: go straight to the smoke test.
		if ( $skipped || ( ! $result['ok'] && $this->probe_route_missing( $result ) ) ) {
			$smoke = $this->generate(
				$connection,
				array(
					'prompt'      => 'Reply with exactly this JSON and nothing else: {"ok":true}',
					'system'      => 'You are a connection health check. Output JSON only.',
					'json'        => true,
					'max_tokens'  => 16,
					'temperature' => 0,
					'timeout'     => 30,
					'job_hint'    => 'custom connection check',
				)
			);

			$this->current_connection = null;

			if ( ! empty( $smoke['ok'] ) ) {
				return array(
					'ok'        => true,
					'code'      => 'connected',
					'message'   => __( 'Custom endpoint accepted a generation request. The key works.', 'viral-video-ai' ),
					'http'      => (int) $smoke['http'],
					'latency'   => (int) $smoke['latency'],
					'retryable' => false,
					'models'    => array( (string) $smoke['model'] ),
				);
			}

			// A transport-level failure (DNS/timeout/auth) is far more useful than
			// "no /models route", so surface whatever the generation attempt learned.
			return array(
				'ok'        => false,
				'code'      => ( '' !== $smoke['code'] ? $smoke['code'] : 'endpoint_unreachable' ),
				'message'   => ( '' !== $smoke['message'] ? $smoke['message'] : __( 'The custom endpoint did not answer a generation request.', 'viral-video-ai' ) ),
				'http'      => (int) $smoke['http'],
				'latency'   => (int) $smoke['latency'],
				'retryable' => (bool) $smoke['retryable'],
				'models'    => array(),
			);
		}

		$this->current_connection = null;

		return $result;
	}

	/**
	 * Did the failure come from a missing optional /models route?
	 *
	 * @param array<string,mixed> $result Probe result.
	 * @return bool
	 */
	protected function probe_route_missing( array $result ) {
		$http = (int) vvai_array_get( $result, 'http', 0 );

		if ( in_array( $http, array( 404, 405, 501 ), true ) ) {
			return true;
		}

		return in_array( (string) vvai_array_get( $result, 'code', '' ), array( 'invalid_configuration', 'bad_json', 'missing_choices', 'empty_response' ), true );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function build_probe_request( array $connection ) {
		$this->current_connection = $connection;

		$base = $this->base_url_for( $connection );

		// A base URL that already points at a completion route has no /models twin.
		if ( preg_match( '#/(chat/completions|messages|completions)$#', $base ) ) {
			return new WP_Error( 'no_models_route', '' );
		}

		return array(
			'method'  => 'GET',
			'url'     => $base . '/models',
			'headers' => $this->base_headers( $connection, $this->secret( $connection ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function base_headers( array $connection, $secret ) {
		$headers = array( 'Authorization' => 'Bearer ' . $secret );

		if ( 'anthropic' === $this->style() ) {
			$headers = array(
				'x-api-key'         => $secret,
				'anthropic-version' => '2023-06-01',
			);
		}

		$extra = vvai_array_get( $connection, 'custom_headers', array() );

		if ( is_array( $extra ) ) {
			foreach ( $extra as $key => $value ) {
				$key = trim( (string) $key );

				if ( '' === $key || ! preg_match( '/^[A-Za-z0-9\-]+$/', $key ) || ! is_scalar( $value ) ) {
					continue;
				}

				$headers[ $key ] = substr( (string) $value, 0, 300 );
			}
		}

		return $headers;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function chat_path() {
		return 'anthropic' === $this->style() ? '/messages' : '/chat/completions';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function build_generation_request( array $connection, array $request ) {
		$this->current_connection = $connection;

		$base = $this->base_url_for( $connection );
		$url  = $base;

		// Accept both a root ("…/v1") and a full endpoint URL.
		if ( ! preg_match( '#/(chat/completions|messages|completions|generate)$#', $url ) ) {
			$url = untrailingslashit( $base ) . $this->chat_path();
		}

		if ( 'anthropic' === $this->style() ) {
			$payload = array(
				'model'       => $this->model_for( $connection ),
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => (string) vvai_array_get( $request, 'prompt', '' ),
					),
				),
				'max_tokens'  => vvai_sanitize_int( vvai_array_get( $request, 'max_tokens', 4000 ), 256, 64000, 4000 ),
				'temperature' => vvai_sanitize_float( vvai_array_get( $request, 'temperature', 0.4 ), 0, 1, 0.4 ),
				'stream'      => false,
			);

			if ( '' !== trim( (string) vvai_array_get( $request, 'system', '' ) ) ) {
				$payload['system'] = (string) vvai_array_get( $request, 'system', '' );
			}

			$this->current_connection = null;

			return array(
				'method'  => 'POST',
				'url'     => $url,
				'headers' => array_merge( $this->base_headers( $connection, $this->secret( $connection ) ), array( 'Content-Type' => 'application/json' ) ),
				'payload' => $payload,
			);
		}

		$built = $this->openai_style_payload(
			$connection,
			$request,
			$url,
			$this->base_headers( $connection, $this->secret( $connection ) )
		);

		$this->current_connection = null;

		return $built;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_generation_response( $json, $body ) {
		if ( 'anthropic' === $this->style() && is_array( $json ) && isset( $json['content'] ) && is_array( $json['content'] ) ) {
			$text = '';

			foreach ( $json['content'] as $block ) {
				if ( is_array( $block ) && isset( $block['text'] ) && 'text' === (string) vvai_array_get( $block, 'type', 'text' ) ) {
					$text .= (string) $block['text'];
				}
			}

			if ( '' === trim( $text ) ) {
				return new WP_Error( 'missing_content', __( 'The custom endpoint returned no text content.', 'viral-video-ai' ) );
			}

			return array(
				'text'  => $text,
				'usage' => isset( $json['usage'] ) && is_array( $json['usage'] ) ? $this->normalize_usage( $json['usage'] ) : array(),
				'model' => isset( $json['model'] ) ? sanitize_text_field( (string) $json['model'] ) : '',
			);
		}

		return $this->parse_openai_style_response( $json, $body );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_transcription_model() {
		return (string) $this->settings->get( 'transcription_model' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parse_models_response( $json, $body ) {
		$models = parent::parse_models_response( $json, $body );

		if ( ! $models && is_string( $body ) && 0 === strpos( trim( $body ), '<' ) ) {
			// A captive-portal / HTML error page instead of JSON: do not treat the
			// model list as authoritative.
			return array();
		}

		return $models;
	}
}
