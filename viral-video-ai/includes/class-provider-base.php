<?php
/**
 * Shared provider adapter behaviour.
 *
 * Concrete adapters only describe their dialect (endpoint, headers, payload
 * shape, response shape). Everything that is identical across providers —
 * timeouts, error normalisation, JSON decoding, credential probing,
 * transcription plumbing, response-size guards — lives here.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Provider_Base
 */
abstract class VVAI_Provider_Base implements VVAI_AI_Provider_Interface {

	/**
	 * HTTP transport.
	 *
	 * @var VVAI_Api_Connection
	 */
	protected $http;

	/**
	 * Logger.
	 *
	 * @var VVAI_Logger
	 */
	protected $logger;

	/**
	 * Settings.
	 *
	 * @var VVAI_Settings
	 */
	protected $settings;

	/**
	 * Estimated characters per token, used for payload budgeting.
	 */
	const CHARS_PER_TOKEN = 3.6;

	/**
	 * Constructor.
	 *
	 * @param VVAI_Api_Connection|null $http     Transport.
	 * @param VVAI_Logger|null         $logger   Logger.
	 * @param VVAI_Settings|null       $settings Settings.
	 */
	public function __construct( $http = null, $logger = null, $settings = null ) {
		$this->http     = $http instanceof VVAI_Api_Connection ? $http : new VVAI_Api_Connection();
		$this->logger   = $logger instanceof VVAI_Logger ? $logger : new VVAI_Logger();
		$this->settings = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_model_options() {
		return array( $this->get_default_model() );
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
	public function transcription_mode() {
		return 'none';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capabilities() {
		return array(
			'label'            => $this->get_label(),
			'json'             => $this->supports_json(),
			'transcription'    => $this->transcription_mode(),
			'audio_to_text'    => 'none' !== $this->transcription_mode(),
			'default_model'    => $this->get_default_model(),
			'models'           => $this->get_model_options(),
			'base_url'         => $this->get_base_url(),
			'auth'             => $this->auth_style(),
			'streaming'        => false,
			'json_native'      => $this->supports_native_json(),
			'context_hint'     => $this->max_input_chars(),
			'requires_key'     => true,
			'needs_binary'     => false,
		);
	}

	/**
	 * How the key is sent (used by the UI help text).
	 *
	 * @return string
	 */
	public function auth_style() {
		return 'bearer';
	}

	/**
	 * Whether the provider has a native "force JSON" response format.
	 *
	 * @return bool
	 */
	public function supports_native_json() {
		return false;
	}

	/**
	 * Ceiling for one prompt, in characters (guards against 413/context errors).
	 *
	 * @return int
	 */
	public function max_input_chars() {
		/**
		 * Filter the maximum prompt size handed to a provider in one request.
		 *
		 * @param int $chars Character budget.
		 */
		return (int) apply_filters( 'vvai_max_prompt_chars', 120000 );
	}

	/**
	 * API root in use (connection override wins).
	 *
	 * @param array<string,mixed> $connection Connection record.
	 * @return string
	 */
	public function base_url_for( array $connection ) {
		$override = trim( (string) vvai_array_get( $connection, 'base_url', '' ) );

		if ( '' !== $override && VVAI_Connection_Store::is_valid_endpoint( $override ) ) {
			return untrailingslashit( $override );
		}

		return untrailingslashit( $this->get_base_url() );
	}

	/**
	 * Model in use (connection override wins).
	 *
	 * @param array<string,mixed> $connection Connection record.
	 * @return string
	 */
	public function model_for( array $connection ) {
		$model = sanitize_text_field( (string) vvai_array_get( $connection, 'model', '' ) );

		return ( '' !== $model ) ? $model : $this->get_default_model();
	}

	/**
	 * Plaintext secret for this request, with validation.
	 *
	 * @param array<string,mixed> $connection Connection record.
	 * @return string
	 */
	protected function secret( array $connection ) {
		return trim( (string) vvai_array_get( $connection, 'api_key', '' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function validate_credentials( array $connection ) {
		$out = array(
			'ok'      => false,
			'code'    => '',
			'message' => '',
			'http'    => 0,
			'latency' => 0,
			'retryable' => false,
			'models'  => array(),
		);

		if ( '' === $this->secret( $connection ) ) {
			$out['code']    = 'missing_api_key';
			$out['message'] = __( 'No API key saved for this connection yet.', 'viral-video-ai' );

			return $out;
		}

		$probe = $this->build_probe_request( $connection );

		if ( is_wp_error( $probe ) ) {
			$out['code']    = 'invalid_configuration';
			$out['message'] = $probe->get_error_message();

			return $out;
		}

		$response = $this->http->request(
			$probe['method'],
			$probe['url'],
			array(
				'headers'   => isset( $probe['headers'] ) ? $probe['headers'] : array(),
				'timeout'   => min( 25, (int) vvai_array_get( $connection, 'timeout', 25 ) ),
				'job_hint'  => (string) vvai_array_get( $connection, 'title', 'connection check' ),
				'query'     => isset( $probe['query'] ) ? $probe['query'] : array(),
			)
		);

		$out['latency'] = (int) $response['latency'];

		if ( ! $response['ok'] ) {
			$error = is_array( $response['error'] ) ? $response['error'] : array();

			$out['code']      = (string) vvai_array_get( $error, 'code', 'connection_failed' );
			$out['message']   = (string) vvai_array_get( $error, 'message', __( 'The provider could not be reached.', 'viral-video-ai' ) );
			$out['http']      = (int) vvai_array_get( $error, 'http', 0 );
			$out['retryable'] = (bool) vvai_array_get( $error, 'retryable', false );

			return $out;
		}

		$models = $this->parse_models_response( $response['json'], $response['body'] );

		$out['models'] = $models;
		$out['ok']     = true;
		$out['code']   = 'connected';
		$out['http']   = (int) $response['http'];
		$out['message'] = sprintf(
			/* translators: %s: provider name. */
			__( '%s accepted the API key.', 'viral-video-ai' ),
			$this->get_label()
		);

		$model = $this->model_for( $connection );

		if ( $models && ! in_array( $model, $models, true ) ) {
			// Key is valid but the configured model is not offered to it.
			$closest = $this->closest_model( $model, $models );

			$out['ok']      = false;
			$out['code']    = 'model_unavailable';
			$out['message'] = sprintf(
				/* translators: 1: model id, 2: provider name, 3: suggested model. */
				__( 'The key is valid, but model "%1$s" is not available on this %2$s account. Try "%3$s" or another model from the Advanced list.', 'viral-video-ai' ),
				$model,
				$this->get_label(),
				( '' !== $closest ? $closest : $this->get_default_model() )
			);

			return $out;
		}

		// Optional smoke test: one generated token proves generation, not just
		// read access to the model list.
		if ( ! empty( $connection['smoke_test'] ) ) {
			$smoke = $this->generate(
				$connection,
				array(
					'prompt'      => 'Reply with exactly this JSON and nothing else: {"ok":true}',
					'system'      => 'You are a connection health check. Output JSON only.',
					'json'        => true,
					'max_tokens'  => 16,
					'temperature' => 0,
					'timeout'     => 30,
					'job_hint'    => 'smoke test',
				)
			);

			$out['latency'] += (int) $smoke['latency'];

			if ( empty( $smoke['ok'] ) ) {
				$out['ok']       = false;
				$out['code']     = ( '' !== $smoke['code'] ) ? $smoke['code'] : 'generation_unavailable';
				$out['message']  = sprintf(
					/* translators: 1: provider label, 2: error. */
					__( '%1$s accepted the key but refused a generation request: %2$s', 'viral-video-ai' ),
					$this->get_label(),
					$smoke['message']
				);
				$out['http']      = (int) $smoke['http'];
				$out['retryable'] = (bool) $smoke['retryable'];
			}
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate( array $connection, array $request ) {
		$result = array(
			'ok'        => false,
			'text'      => '',
			'json'      => null,
			'code'      => '',
			'message'   => '',
			'http'      => 0,
			'latency'   => 0,
			'retryable' => false,
			'usage'     => array(),
			'model'     => $this->model_for( $connection ),
		);

		if ( '' === $this->secret( $connection ) ) {
			$result['code']    = 'missing_api_key';
			$result['message'] = __( 'No API key available for this connection.', 'viral-video-ai' );

			return $result;
		}

		$built = $this->build_generation_request( $connection, $request );

		if ( is_wp_error( $built ) ) {
			$result['code']    = 'invalid_request';
			$result['message'] = $built->get_error_message();

			return $result;
		}

		if ( ! empty( $built['skip_http'] ) ) {
			return array_merge( $result, (array) $built );
		}

		$response = $this->http->request(
			$built['method'],
			$built['url'],
			array(
				'headers'  => $built['headers'],
				'json'     => $built['payload'],
				'timeout'  => (int) vvai_array_get( $request, 'timeout', $this->default_timeout( $connection ) ),
				'job_hint' => (string) vvai_array_get( $request, 'job_hint', '' ),
				'query'    => isset( $built['query'] ) ? (array) $built['query'] : array(),
			)
		);

		$result['latency'] = (int) $response['latency'];
		$result['http']    = (int) $response['http'];

		if ( ! $response['ok'] ) {
			$error = is_array( $response['error'] ) ? $response['error'] : array();

			$result['code']      = (string) vvai_array_get( $error, 'code', 'provider_error' );
			$result['message']   = (string) vvai_array_get( $error, 'message', __( 'The provider request failed.', 'viral-video-ai' ) );
			$result['retryable'] = (bool) vvai_array_get( $error, 'retryable', false );
			$result['detail']     = (string) vvai_array_get( $error, 'detail', '' );
			$result['hint']       = (string) vvai_array_get( $error, 'hint', '' );

			return $result;
		}

		$parsed = $this->parse_generation_response( $response['json'], $response['body'] );

		if ( is_wp_error( $parsed ) ) {
			$result['code']    = 'unparseable_response';
			$result['message'] = $parsed->get_error_message();

			return $result;
		}

		$result['text']  = (string) vvai_array_get( $parsed, 'text', '' );
		$result['usage'] = (array) vvai_array_get( $parsed, 'usage', array() );
		$result['model'] = (string) vvai_array_get( $parsed, 'model', $result['model'] );

		if ( '' === trim( $result['text'] ) ) {
			$result['code']    = 'empty_response';
			$result['message'] = __( 'The provider answered successfully but returned no text. It may have refused the request or hit a length limit.', 'viral-video-ai' );

			return $result;
		}

		$result['ok'] = true;
		$result['code'] = 'ok';

		if ( ! empty( $request['json'] ) ) {
			$decoded = VVAI_Json::extract( $result['text'] );

			if ( empty( $decoded['ok'] ) ) {
				$result['ok']      = false;
				$result['code']    = 'invalid_json';
				$result['message'] = __( 'The model did not return parsable JSON.', 'viral-video-ai' );
				$result['invalid']  = true;

				return $result;
			}

			$result['json'] = $decoded['data'];
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function analyze_transcript( array $connection, array $payload ) {
		$builder = new VVAI_Prompt_Builder();

		$prompt      = (string) vvai_array_get( $payload, 'prompt', '' );
		$system      = ( '' === (string) vvai_array_get( $payload, 'system', '' ) )
			? $builder->system_prompt()
			: (string) vvai_array_get( $payload, 'system', '' );

		if ( '' === $prompt ) {
			return array(
				'ok'      => false,
				'clips'   => array(),
				'code'    => 'missing_prompt',
				'message' => __( 'No prompt was supplied for analysis.', 'viral-video-ai' ),
				'raw'     => '',
				'usage'   => array(),
			);
		}

		$generated = $this->generate(
			$connection,
			array(
				'prompt'      => $prompt,
				'system'      => $system,
				'json'        => true,
				'temperature' => (float) vvai_array_get( $payload, 'temperature', $this->settings->get( 'temperature' ) ),
				'max_tokens'  => (int) vvai_array_get( $payload, 'max_tokens', $this->suggested_max_tokens( $connection ) ),
				'timeout'     => (int) vvai_array_get( $payload, 'timeout', $this->default_timeout( $connection ) ),
				'job_hint'    => (string) vvai_array_get( $payload, 'job_hint', '' ),
			)
		);

		$out = array(
			'ok'       => false,
			'clips'    => array(),
			'code'     => (string) $generated['code'],
			'message'  => (string) $generated['message'],
			'raw'      => (string) $generated['text'],
			'usage'    => (array) $generated['usage'],
			'latency'  => (int) $generated['latency'],
			'http'     => (int) $generated['http'],
			'retryable'=> (bool) $generated['retryable'],
			'invalid'  => ! empty( $generated['invalid'] ),
		);

		if ( ! $generated['ok'] ) {
			return $out;
		}

		$list = VVAI_Json::extract_list( $out['raw'], 'clips' );

		if ( empty( $list['ok'] ) ) {
			$out['ok']      = false;
			$out['code']    = 'invalid_json';
			$out['message'] = (string) $list['error'];
			$out['invalid']  = true;

			return $out;
		}

		$out['ok']    = true;
		$out['code']  = 'ok';
		$out['clips'] = $list['list'];

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function transcribe( array $connection, $audio_path, array $args = array() ) {
		$out = array(
			'ok'       => false,
			'segments' => array(),
			'text'     => '',
			'code'     => '',
			'message'  => '',
			'latency'  => 0,
			'retryable' => false,
			'model'    => '',
		);

		if ( 'audio_api' !== $this->transcription_mode() ) {
			$out['code']    = 'unsupported_transcription';
			$out['message'] = sprintf(
				/* translators: %s: provider name. */
				__( '%s does not offer an audio transcription endpoint. Configure an OpenAI/Groq connection or a custom transcription endpoint for transcription.', 'viral-video-ai' ),
				$this->get_label()
			);

			return $out;
		}

		if ( '' === $this->secret( $connection ) || ! is_file( $audio_path ) ) {
			$out['code']    = 'cannot_transcribe';
			$out['message'] = __( 'The audio file or the API key required for transcription is missing.', 'viral-video-ai' );

			return $out;
		}

		$built = $this->build_transcription_request( $connection, $audio_path, $args );

		if ( is_wp_error( $built ) ) {
			$out['code']    = 'invalid_request';
			$out['message'] = $built->get_error_message();

			return $out;
		}

		$response = $this->http->request(
			$built['method'],
			$built['url'],
			array(
				'headers'   => $built['headers'],
				'multipart' => $built['multipart'],
				'timeout'   => (int) vvai_array_get( $args, 'timeout', 300 ),
				'job_hint'  => (string) vvai_array_get( $args, 'job_hint', 'transcription' ),
				'query'     => isset( $built['query'] ) ? (array) $built['query'] : array(),
			)
		);

		$out['latency'] = (int) $response['latency'];
		$out['model']   = (string) $built['model'];

		if ( ! $response['ok'] ) {
			$error = is_array( $response['error'] ) ? $response['error'] : array();

			$out['code']      = (string) vvai_array_get( $error, 'code', 'transcription_failed' );
			$out['message']   = (string) vvai_array_get( $error, 'message', __( 'Transcription failed.', 'viral-video-ai' ) );
			$out['retryable'] = (bool) vvai_array_get( $error, 'retryable', false );

			return $out;
		}

		$decoded = $this->parse_transcription_response( $response['json'], $response['body'] );

		if ( is_wp_error( $decoded ) ) {
			$out['code']    = 'transcription_unparseable';
			$out['message'] = $decoded->get_error_message();

			return $out;
		}

		$out['ok']       = true;
		$out['code']     = 'ok';
		$out['segments'] = $decoded['segments'];
		$out['text']     = $decoded['text'];

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function normalize_error( array $error ) {
		$code = (string) vvai_array_get( $error, 'code', 'provider_error' );

		// Provider specific codes that the generic classifier cannot know about.
		$known = array(
			'invalid_api_key',
			'forbidden',
			'rate_limited',
			'quota_exceeded',
			'model_unavailable',
			'provider_unavailable',
			'timeout',
			'dns_error',
			'network_error',
			'ssl_error',
			'wp_http_error',
			'bad_request',
			'payload_too_large',
			'provider_timeout',
			'http_error',
			'invalid_request',
		);

		if ( in_array( $code, $known, true ) ) {
			return $error;
		}

		$provider_code = strtolower( (string) vvai_array_get( $error, 'provider_code', '' ) . ' ' . vvai_array_get( $error, 'provider_status', '' ) );

		if ( false !== strpos( $provider_code, 'insufficient' ) || false !== strpos( $provider_code, 'quota' ) ) {
			return array_merge(
				$error,
				array(
					'code'    => 'quota_exceeded',
					'message' => __( 'The provider account has no quota left for this model.', 'viral-video-ai' ),
					'hint'    => __( 'Add credit to the provider account, or select a connection that still has quota.', 'viral-video-ai' ),
				)
			);
		}

		if ( false !== strpos( $provider_code, 'authentication' ) || false !== strpos( $provider_code, 'unauthenticated' ) ) {
			return array_merge(
				$error,
				array(
					'code'    => 'invalid_api_key',
					'message' => __( 'Invalid API key for this provider.', 'viral-video-ai' ),
				)
			);
		}

		if ( false !== strpos( $provider_code, 'notfound' ) || false !== strpos( $provider_code, 'not_found' ) || false !== strpos( $provider_code, 'unknown model' ) ) {
			return array_merge(
				$error,
				array(
					'code'    => 'model_unavailable',
					'message' => __( 'Model unavailable for this connection.', 'viral-video-ai' ),
				)
			);
		}

		return $error;
	}

	/**
	 * Default HTTP timeout for this provider.
	 *
	 * @param array<string,mixed> $connection Connection.
	 * @return int
	 */
	protected function default_timeout( array $connection ) {
		$timeout = (int) vvai_array_get( $connection, 'timeout', 0 );

		if ( $timeout <= 0 ) {
			$timeout = (int) $this->settings->get( 'process_timeout' );
		}

		return max( 15, min( 900, $timeout ) );
	}

	/**
	 * Response ceiling that comfortably fits a clip list.
	 *
	 * @param array<string,mixed> $connection Connection.
	 * @return int
	 */
	protected function suggested_max_tokens( array $connection ) {
		$configured = (int) vvai_array_get( $connection, 'max_tokens', 0 );

		if ( $configured > 0 ) {
			return $configured;
		}

		return 4000;
	}

	/**
	 * Estimate the token count of a text blob.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public function estimate_tokens( $text ) {
		return (int) ceil( strlen( (string) $text ) / self::CHARS_PER_TOKEN );
	}

	/**
	 * Headers used for every request of this provider.
	 *
	 * @param array<string,mixed> $connection Connection.
	 * @param string              $secret     Plaintext key (never logged).
	 * @return array<string,string>
	 */
	protected function base_headers( array $connection, $secret ) {
		unset( $connection );

		return array( 'Authorization' => 'Bearer ' . $secret );
	}

	/**
	 * OpenAI-compatible chat request builder, reused by Groq/OpenRouter/Custom.
	 *
	 * @param array<string,mixed> $connection Connection.
	 * @param array<string,mixed> $request     Request.
	 * @param string              $url         Endpoint.
	 * @param array<string,string> $headers   Headers.
	 * @return array{method:string,url:string,headers:array,payload:array}
	 */
	protected function openai_style_payload( array $connection, array $request, $url, array $headers ) {
		$model       = $this->model_for( $connection );
		$temperature = isset( $request['temperature'] ) ? vvai_sanitize_float( $request['temperature'], 0, 2, 0.4 ) : 0.4;
		$max_tokens  = vvai_sanitize_int( vvai_array_get( $request, 'max_tokens', $this->suggested_max_tokens( $connection ) ), 64, 128000, 4000 );

		$messages = array();

		if ( '' !== trim( (string) vvai_array_get( $request, 'system', '' ) ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => (string) $request['system'],
			);
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => (string) vvai_array_get( $request, 'prompt', '' ),
		);

		if ( isset( $request['messages'] ) && is_array( $request['messages'] ) && $request['messages'] ) {
			$messages = $request['messages'];
		}

		$payload = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => $temperature,
			'max_tokens'  => $max_tokens,
			'stream'      => false,
		);

		if ( ! empty( $request['json'] ) && $this->supports_native_json() ) {
			$payload['response_format'] = array( 'type' => 'json_object' );
		}

		if ( isset( $request['seed'] ) ) {
			$payload['seed'] = vvai_sanitize_int( $request['seed'], 0, PHP_INT_MAX, 0 );
		}

		/**
		 * Filter the provider payload just before it is sent.
		 *
		 * @param array $payload    Request payload.
		 * @param array $connection Connection record.
		 * @param array $request    Generation request.
		 */
		$payload = apply_filters( 'vvai_provider_payload', $payload, $connection, $request );

		unset( $headers['Content-Type'] );

		return array(
			'method'  => 'POST',
			'url'     => $url,
			'headers' => array_merge( $headers, array( 'Content-Type' => 'application/json' ) ),
			'payload' => $payload,
		);
	}

	/**
	 * Parse an OpenAI-compatible chat response.
	 *
	 * @param mixed  $json Decoded body.
	 * @param string $body Raw body.
	 * @return array{text:string,usage:array,model:string}|WP_Error
	 */
	protected function parse_openai_style_response( $json, $body ) {
		unset( $body );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'bad_json', __( 'The provider returned a body that was not JSON.', 'viral-video-ai' ) );
		}

		// Completions API shape.
		if ( isset( $json['choices'][0]['message']['content'] ) ) {
			$text = (string) $json['choices'][0]['message']['content'];
		} elseif ( isset( $json['choices'][0]['text'] ) ) {
			$text = (string) $json['choices'][0]['text'];
		} elseif ( isset( $json['output'][0]['content'][0]['text'] ) ) {
			// OpenAI Responses API shape, used by some compatible gateways.
			$text = (string) $json['output'][0]['content'][0]['text'];
		} elseif ( isset( $json['output_text'] ) && is_string( $json['output_text'] ) ) {
			$text = $json['output_text'];
		} else {
			return new WP_Error( 'missing_choices', __( 'The provider response did not contain any generated text.', 'viral-video-ai' ) );
		}

		return array(
			'text'  => $text,
			'usage' => isset( $json['usage'] ) && is_array( $json['usage'] ) ? $this->normalize_usage( $json['usage'] ) : array(),
			'model' => isset( $json['model'] ) ? sanitize_text_field( (string) $json['model'] ) : '',
		);
	}

	/**
	 * Normalize usage maps across providers.
	 *
	 * @param array<string,mixed> $usage Usage.
	 * @return array<string,int>
	 */
	protected function normalize_usage( array $usage ) {
		$map = array(
			'prompt_tokens'     => 'input',
			'input_tokens'      => 'input',
			'completion_tokens' => 'output',
			'output_tokens'     => 'output',
			'total_tokens'      => 'total',
			'thoughts_tokens'   => 'reasoning',
		);

		$clean = array();

		foreach ( $map as $key => $label ) {
			if ( isset( $usage[ $key ] ) && is_numeric( $usage[ $key ] ) ) {
				$clean[ $label ] = (int) $usage[ $key ];
			}
		}

		if ( ! isset( $clean['total'] ) ) {
			$clean['total'] = ( $clean['input'] ?? 0 ) + ( $clean['output'] ?? 0 );
		}

		return $clean;
	}

	/**
	 * Default model-list parsing for `{data:[{id}]}` and `{models:[{name}]}`.
	 *
	 * @param mixed  $json Decoded body.
	 * @param string $body Raw body.
	 * @return array<int,string>
	 */
	protected function parse_models_response( $json, $body ) {
		unset( $body );

		if ( ! is_array( $json ) ) {
			return array();
		}

		$candidates = array();

		foreach ( array( 'data', 'models', 'result', 'objects' ) as $key ) {
			if ( isset( $json[ $key ] ) && is_array( $json[ $key ] ) ) {
				$candidates = $json[ $key ];
				break;
			}
		}

		$models = array();

		foreach ( $candidates as $entry ) {
			if ( is_string( $entry ) ) {
				$models[] = $entry;
				continue;
			}

			if ( ! is_array( $entry ) ) {
				continue;
			}

			foreach ( array( 'id', 'name', 'model' ) as $field ) {
				if ( isset( $entry[ $field ] ) && is_string( $entry[ $field ] ) && '' !== $entry[ $field ] ) {
					$models[] = $entry[ $field ];
					break;
				}
			}
		}

		$models = array_values( array_unique( $models ) );

		// Deterministic order, newest-looking ids first is not useful; sort A-Z.
		sort( $models, SORT_STRING );

		return array_slice( $models, 0, 400 );
	}

	/**
	 * Turn `{text, segments:[{start,end,word}]}` into normalized segments.
	 *
	 * @param mixed  $json Decoded body.
	 * @param string $body Raw body.
	 * @return array{segments:array<int,array{start:float,end:float,text:string}>,text:string}|WP_Error
	 */
	protected function parse_transcription_response( $json, $body ) {
		if ( ! is_array( $json ) ) {
			// Some transports answer with plain text for the `text` format.
			$text = is_string( $body ) ? trim( wp_strip_all_tags( $body ) ) : '';

			if ( '' === $text ) {
				return new WP_Error( 'empty_transcription', __( 'The transcription endpoint returned no text.', 'viral-video-ai' ) );
			}

			return array(
				'segments' => array(
					array(
						'start' => 0.0,
						'end'   => 0.0,
						'text'  => $text,
					),
				),
				'text'     => $text,
			);
		}

		$segments = array();
		$text     = isset( $json['text'] ) && is_string( $json['text'] ) ? $json['text'] : '';

		if ( isset( $json['segments'] ) && is_array( $json['segments'] ) ) {
			foreach ( $json['segments'] as $segment ) {
				if ( ! is_array( $segment ) ) {
					continue;
				}

				$start = isset( $segment['start'] ) ? $segment['start'] : ( isset( $segment['start_time'] ) ? $segment['start_time'] : null );
				$end   = isset( $segment['end'] ) ? $segment['end'] : ( isset( $segment['end_time'] ) ? $segment['end_time'] : null );
				$body  = isset( $segment['text'] ) ? trim( (string) $segment['text'] ) : '';

				if ( '' === $body || ! is_numeric( $start ) || ! is_numeric( $end ) ) {
					continue;
				}

				$segments[] = array(
					'start' => round( (float) $start, 2 ),
					'end'   => round( max( (float) $start + 0.2, (float) $end ), 2 ),
					'text'  => $body,
				);
			}
		}

		if ( ! $segments && '' !== $text ) {
			$segments[] = array(
				'start' => 0.0,
				'end'   => 0.0,
				'text'  => $text,
			);
		}

		if ( ! $segments ) {
			return new WP_Error( 'empty_transcription', __( 'The transcription response contained no usable segments.', 'viral-video-ai' ) );
		}

		return array(
			'segments' => $segments,
			'text'     => ( '' !== $text ) ? $text : implode( ' ', wp_list_pluck( $segments, 'text' ) ),
		);
	}

	/**
	 * Transcription request for OpenAI-compatible `/audio/transcriptions`.
	 *
	 * @param array<string,mixed> $connection  Connection.
	 * @param string              $audio_path  Audio file.
	 * @param array<string,mixed> $args         Transcription args.
	 * @return array{method:string,url:string,headers:array,multipart:array,model:string}|WP_Error
	 */
	protected function build_audio_api_transcription_request( array $connection, $audio_path, array $args ) {
		$base   = $this->base_url_for( $connection );
		$model  = sanitize_text_field( (string) ( vvai_array_get( $args, 'model', '' ) ?: $this->default_transcription_model() ) );
		$secret = $this->secret( $connection );

		if ( '' === $model ) {
			return new WP_Error( 'no_transcription_model', __( 'No transcription model configured for this provider.', 'viral-video-ai' ) );
		}

		$fields = array(
			'model'          => $model,
			'response_format' => 'verbose_json',
			'temperature'     => '0',
		);

		$language = sanitize_text_field( (string) vvai_array_get( $args, 'language', $this->settings->get( 'transcript_language' ) ) );

		if ( '' !== $language ) {
			$fields['language'] = $language;
		}

		if ( '' !== (string) vvai_array_get( $args, 'prompt', '' ) ) {
			$fields['prompt'] = substr( (string) $args['prompt'], 0, 800 );
		}

		$extension = strtolower( (string) pathinfo( $audio_path, PATHINFO_EXTENSION ) );

		return array(
			'method'  => 'POST',
			'url'     => $base . '/audio/transcriptions',
			'headers' => array( 'Authorization' => 'Bearer ' . $secret, 'Accept' => 'application/json' ),
			'multipart' => array(
				'fields' => $fields,
				'files'  => array(
					array(
						'name'     => 'file',
						'filename' => 'audio.' . ( $extension ? $extension : 'mp3' ),
						'type'     => $this->audio_mime_for( $extension ),
						'path'     => (string) $audio_path,
					),
				),
			),
			'model'   => $model,
		);
	}

	/**
	 * MIME type for the extracted audio container.
	 *
	 * @param string $extension Extension without dot.
	 * @return string
	 */
	protected function audio_mime_for( $extension ) {
		switch ( $extension ) {
			case 'wav':
				return 'audio/wav';
			case 'ogg':
			case 'opus':
				return 'audio/ogg';
			case 'flac':
				return 'audio/flac';
			case 'aac':
			case 'm4a':
				return 'audio/aac';
			case 'mp3':
			default:
				return 'audio/mpeg';
		}
	}

	/**
	 * Transcription model used when the provider has no configured default.
	 *
	 * @return string
	 */
	protected function default_transcription_model() {
		return '';
	}

	/**
	 * Probe request used by validate_credentials().
	 *
	 * @param array<string,mixed> $connection Connection.
	 * @return array{method:string,url:string,headers:array,query?:array}|WP_Error
	 */
	protected function build_probe_request( array $connection ) {
		return array(
			'method'  => 'GET',
			'url'     => $this->base_url_for( $connection ) . $this->models_path(),
			'headers' => $this->base_headers( $connection, $this->secret( $connection ) ),
		);
	}

	/**
	 * Path of the model list endpoint.
	 *
	 * @return string
	 */
	protected function models_path() {
		return '/models';
	}

	/**
	 * Generation endpoint path.
	 *
	 * @return string
	 */
	protected function chat_path() {
		return '/chat/completions';
	}

	/**
	 * Build the provider-specific generation request.
	 *
	 * @param array<string,mixed> $connection Connection.
	 * @param array<string,mixed> $request     Request.
	 * @return array{method:string,url:string,headers:array,payload:array,query?:array}|WP_Error
	 */
	protected function build_generation_request( array $connection, array $request ) {
		return $this->openai_style_payload(
			$connection,
			$request,
			$this->base_url_for( $connection ) . $this->chat_path(),
			$this->base_headers( $connection, $this->secret( $connection ) )
		);
	}

	/**
	 * Parse a provider-specific generation response.
	 *
	 * @param mixed  $json Decoded body.
	 * @param string $body Raw body.
	 * @return array{text:string,usage:array,model:string}|WP_Error
	 */
	protected function parse_generation_response( $json, $body ) {
		return $this->parse_openai_style_response( $json, $body );
	}

	/**
	 * Build a provider-specific transcription request.
	 *
	 * @param array<string,mixed> $connection Connection.
	 * @param string              $audio_path Audio path.
	 * @param array<string,mixed> $args        Args.
	 * @return array|WP_Error
	 */
	protected function build_transcription_request( array $connection, $audio_path, array $args ) {
		return $this->build_audio_api_transcription_request( $connection, $audio_path, $args );
	}

	/**
	 * Pick the closest known model name for a suggestion.
	 *
	 * @param string        $wanted Wanted model.
	 * @param array<string> $models Available models.
	 * @return string
	 */
	protected function closest_model( $wanted, array $models ) {
		$wanted = strtolower( $wanted );
		$prefix = strtok( $wanted, '.' );
		$best   = '';
		$score  = -1;

		foreach ( $models as $model ) {
			$lower = strtolower( $model );
			$value = 0;

			if ( $lower === $wanted ) {
				return $model;
			}

			if ( '' !== $prefix && 0 === strpos( $lower, $prefix ) ) {
				$value += 10;
			}

			similar_text( $wanted, $lower, $percent );
			$value += (int) round( $percent / 10 );

			if ( false !== strpos( $lower, 'mini' ) || false !== strpos( $lower, 'flash' ) || false !== strpos( $lower, 'turbo' ) ) {
				$value += 1;
			}

			if ( $value > $score ) {
				$score = $value;
				$best  = $model;
			}
		}

		return $best;
	}

	/**
	 * Whether a prompt is acceptable for one request, with a soft size guard.
	 *
	 * @param string $prompt Prompt.
	 * @return true|WP_Error
	 */
	protected function check_prompt_size( $prompt ) {
		$max = $this->max_input_chars();

		if ( strlen( $prompt ) <= $max ) {
			return true;
		}

		return new WP_Error(
			'prompt_too_large',
			sprintf(
				/* translators: 1: human size, 2: limit in characters. */
				__( 'The analysis prompt is %1$s of text, above the %2$s character limit for this provider. Lower the transcript chunk size or the maximum number of clips.', 'viral-video-ai' ),
				vvai_human_size( strlen( $prompt ) * 1 ),
				number_format_i18n( $max )
			)
		);
	}
}
