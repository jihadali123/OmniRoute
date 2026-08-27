<?php
/**
 * Provider-agnostic AI router.
 *
 * Callers say "analyze this transcript" and receive validated data. The router
 * decides which connection to use, decrypts the key for exactly one call,
 * applies the retry/fallback policy, and records usage.
 *
 * Fallback policy (spec §33):
 *  - transport/availability failures (timeout, DNS, 429, 5xx) → fall back;
 *  - authentication/permission failures → never fall back silently, because a
 *    bad key is a configuration problem the admin must see.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_AI_Router
 */
class VVAI_AI_Router {

	/**
	 * Connection store.
	 *
	 * @var VVAI_Connection_Store
	 */
	private $connections;

	/**
	 * Provider registry.
	 *
	 * @var VVAI_Api_Manager
	 */
	private $providers;

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
	 * Constructor.
	 *
	 * @param VVAI_Connection_Store|null $connections Connections.
	 * @param VVAI_Api_Manager|null      $providers    Registry.
	 * @param VVAI_Settings|null         $settings     Settings.
	 * @param VVAI_Logger|null           $logger       Logger.
	 */
	public function __construct( $connections = null, $providers = null, $settings = null, $logger = null ) {
		$this->connections = $connections instanceof VVAI_Connection_Store ? $connections : new VVAI_Connection_Store();
		$this->providers   = $providers instanceof VVAI_Api_Manager ? $providers : new VVAI_Api_Manager();
		$this->settings    = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
		$this->logger      = $logger instanceof VVAI_Logger ? $logger : new VVAI_Logger( $this->settings );
	}

	/**
	 * The connection that processing will use.
	 *
	 * @param string $explicit_id Optional explicit connection id from the widget.
	 * @return array<string,mixed>|null
	 */
	public function get_active_connection( $explicit_id = '' ) {
		if ( '' !== $explicit_id ) {
			$record = $this->connections->get( $explicit_id );

			if ( $record && VVAI_Connection_Store::STATUS_CONNECTED === (string) $record['status'] ) {
				return $record;
			}

			return null;
		}

		return $this->connections->get_active( true );
	}

	/**
	 * Human-readable reason why no connection can be used, if any.
	 *
	 * @param string $explicit_id Requested connection.
	 * @return string Empty when a usable connection exists.
	 */
	public function connection_problem( $explicit_id = '' ) {
		if ( '' !== $explicit_id ) {
			$record = $this->connections->get( $explicit_id );

			if ( ! $record ) {
				return __( 'The selected AI connection no longer exists. Choose another connection.', 'viral-video-ai' );
			}

			if ( VVAI_Connection_Store::STATUS_CONNECTED !== (string) $record['status'] ) {
				return __( 'Please connect an AI provider before processing videos.', 'viral-video-ai' );
			}

			if ( '' === $this->connections->reveal_secret( $record['id'] ) ) {
				return __( 'The saved key for this connection could not be decrypted. Re-enter the API key (the WordPress salts may have changed).', 'viral-video-ai' );
			}

			return '';
		}

		$record = $this->connections->get_active( true );

		if ( ! $record ) {
			$any = $this->connections->all();

			if ( ! $any ) {
				return __( 'No AI provider is connected yet. Add a connection in Viral Video AI → API Connections.', 'viral-video-ai' );
			}

			foreach ( $any as $candidate ) {
				if ( VVAI_Connection_Store::STATUS_CONNECTED === (string) $candidate['status'] ) {
					return __( 'Please connect an AI provider before processing videos.', 'viral-video-ai' );
				}
			}

			return __( 'Your AI connection is disconnected. Reconnect it in Viral Video AI → API Connections.', 'viral-video-ai' );
		}

		if ( '' === $this->connections->reveal_secret( $record['id'] ) ) {
			return __( 'The saved API key could not be decrypted on this server. Open the connection, paste the key again and click Connect.', 'viral-video-ai' );
		}

		return '';
	}

	/**
	 * Connect a saved record: real server-side verification against the provider.
	 *
	 * @param string $id Connection id.
	 * @return array{ok:bool,code:string,message:string,record:array<string,mixed>}
	 */
	public function connect( $id ) {
		$record = $this->connections->get( $id );

		if ( ! $record ) {
			return array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => __( 'Connection not found.', 'viral-video-ai' ),
				'record'  => array(),
			);
		}

		$adapter = $this->providers->get( (string) $record['provider'] );

		if ( is_wp_error( $adapter ) ) {
			$this->connections->set_status( $id, VVAI_Connection_Store::STATUS_FAILED, array( 'code' => $adapter->get_error_code(), 'message' => $adapter->get_error_message() ) );

			return array(
				'ok'      => false,
				'code'    => (string) $adapter->get_error_code(),
				'message' => (string) $adapter->get_error_message(),
				'record'  => $this->connections->public_view( (array) $this->connections->get( $id ) ),
			);
		}

		// "Connecting" first so a concurrent request can see the state.
		$this->connections->set_status( $id, VVAI_Connection_Store::STATUS_CONNECTING );

		$attempt = $this->attempt_verification( $record, $adapter );

		if ( $attempt['ok'] ) {
			$updated = $this->connections->set_status(
				$id,
				VVAI_Connection_Store::STATUS_CONNECTED,
				array(),
				array(
					'last_latency_ms' => (int) $attempt['latency'],
					'detected_models' => array_slice( (array) $attempt['models'], 0, 120 ),
					'last_http_code'  => (int) $attempt['http'],
				)
			);

			// First successful connection becomes the active engine automatically.
			$settings    = get_option( VVAI_Settings::OPTION_KEY, array() );
			$settings    = is_array( $settings ) ? $settings : array();
			$active      = (string) vvai_array_get( $settings, 'active_connection_id', '' );
			$connected   = $this->connections->connected();

			if ( '' === $active && 1 === count( $connected ) ) {
				$this->connections->set_active( $id );
			}

			return array(
				'ok'      => true,
				'code'    => 'connected',
				'message' => (string) $attempt['message'],
				'latency' => (int) $attempt['latency'],
				'http'    => (int) $attempt['http'],
				'models'  => array_slice( (array) $attempt['models'], 0, 120 ),
				'record'  => $this->connections->public_view( $updated ? $updated : (array) $this->connections->get( $id ) ),
			);
		}

		$this->connections->set_status(
			$id,
			VVAI_Connection_Store::STATUS_FAILED,
			array(
				'code'    => (string) $attempt['code'],
				'message' => (string) $attempt['message'],
				'http'    => (int) $attempt['http'],
			),
			array( 'last_latency_ms' => (int) $attempt['latency'] )
		);

		return array(
			'ok'      => false,
			'code'    => (string) $attempt['code'],
			'message' => (string) $attempt['message'],
			'hint'    => (string) vvai_array_get( $attempt, 'hint', '' ),
			'latency' => (int) $attempt['latency'],
			'http'    => (int) $attempt['http'],
			'record'  => $this->connections->public_view( (array) $this->connections->get( $id ) ),
		);
	}

	/**
	 * Verify unsaved credentials (used by the "Add connection" form before the
	 * record exists, so a user never saves a broken key by accident).
	 *
	 * @param array<string,mixed> $candidate Draft record (with `api_key`).
	 * @return array{ok:bool,code:string,message:string,models:array<int,string>}
	 */
	public function verify_candidate( array $candidate ) {
		$adapter = $this->providers->get( (string) vvai_array_get( $candidate, 'provider', 'openai' ) );

		if ( is_wp_error( $adapter ) ) {
			return array(
				'ok'      => false,
				'code'    => (string) $adapter->get_error_code(),
				'message' => (string) $adapter->get_error_message(),
				'models'  => array(),
			);
		}

		return $this->attempt_verification( $candidate, $adapter );
	}

	/**
	 * Disconnect: the record stays, the credential is unusable for processing.
	 *
	 * @param string $id Connection id.
	 * @return array<string,mixed>
	 */
	public function disconnect( $id ) {
		$record = $this->connections->set_status( $id, VVAI_Connection_Store::STATUS_DISCONNECTED, array( 'code' => 'disconnected', 'message' => __( 'Disconnected by an administrator.', 'viral-video-ai' ) ) );

		// Clear selection pointers so the pipeline refuses to run.
		$settings = get_option( VVAI_Settings::OPTION_KEY, array() );

		if ( is_array( $settings ) ) {
			$changed = false;

			foreach ( array( 'active_connection_id', 'fallback_connection_id' ) as $key ) {
				if ( (string) vvai_array_get( $settings, $key, '' ) === (string) $id ) {
					$settings[ $key ] = '';
					$changed          = true;
				}
			}

			if ( $changed ) {
				update_option( VVAI_Settings::OPTION_KEY, $settings );
			}
		}

		return $record ? $this->connections->public_view( $record ) : array();
	}

	/**
	 * Run a generation request with the fallback policy applied.
	 *
	 * @param array<string,mixed> $args {
	 *     @type string $prompt       Required prompt.
	 *     @type string $system       System prompt.
	 *     @type bool   $json         Expect JSON.
	 *     @type int    $max_tokens   Response ceiling.
	 *     @type float  $temperature  Temperature.
	 *     @type int    $timeout      HTTP timeout.
	 *     @type string $connection   Explicit connection id.
	 *     @type int    $job_id       Job id for usage accounting and logs.
	 *     @type bool   $allow_fallback Override the setting.
	 * }
	 * @return array<string,mixed> Provider generate() result, plus `connection_id`,
	 *                              `provider`, `attempts`, `fallback_used`.
	 */
	public function generate( array $args ) {
		$chain = $this->build_chain( $args );

		if ( ! $chain ) {
			return array(
				'ok'      => false,
				'text'    => '',
				'json'    => null,
				'code'    => 'no_connection',
				'message' => $this->connection_problem( (string) vvai_array_get( $args, 'connection', '' ) ),
				'http'    => 0,
				'latency' => 0,
				'usage'   => array(),
				'attempts' => array(),
			);
		}

		$attempts   = array();
		$last       = null;
		$max_tries  = count( $chain );

		for ( $index = 0; $index < $max_tries; $index++ ) {
			$item = $chain[ $index ];

			$result = $this->call( $item, $args, $index );

			$attempts[] = array(
				'connection' => (string) $item['record']['id'],
				'provider'   => (string) $item['record']['provider'],
				'title'      => (string) $item['record']['title'],
				'ok'         => (bool) $result['ok'],
				'code'       => (string) $result['code'],
				'http'       => (int) $result['http'],
				'latency'    => (int) $result['latency'],
			);

			$last = $result;

			if ( $result['ok'] ) {
				$this->record_success( $item['record'], $result, $args );

				$result['attempts']      = $attempts;
				$result['connection_id']  = (string) $item['record']['id'];
				$result['provider']       = (string) $item['record']['provider'];
				$result['fallback_used']  = ( $index > 0 );

				return $result;
			}

			// A response we could not parse is a model problem, not a routing
			// problem: the caller retries with a correction prompt instead of
			// silently spending the fallback provider's quota.
			if ( ! empty( $result['invalid'] ) ) {
				break;
			}

			if ( ! $this->should_fall_back( $result, $chain, $index ) ) {
				break;
			}

			$this->logger->warning(
				'Primary AI connection failed, trying fallback',
				array(
					'code'       => (string) $result['code'],
					'from'       => (string) $item['record']['id'],
					'to'         => (string) $chain[ $index + 1 ]['record']['id'],
					'job'        => isset( $args['job_id'] ) ? (int) $args['job_id'] : 0,
				)
			);
		}

		$last = is_array( $last ) ? $last : array();

		return array_merge(
			array(
				'ok'      => false,
				'text'    => '',
				'json'    => null,
				'code'    => 'provider_error',
				'message' => __( 'The AI provider request failed.', 'viral-video-ai' ),
				'http'    => 0,
				'latency' => 0,
				'usage'   => array(),
			),
			$last,
			array(
				'attempts'    => $attempts,
				'connection_id' => (string) $chain[0]['record']['id'],
				'provider'      => (string) $chain[0]['record']['provider'],
				'fallback_used' => ( count( $attempts ) > 1 ),
			)
		);
	}

	/**
	 * Convenience wrapper used by the analyzer.
	 *
	 * @param array<string,mixed> $payload Analysis payload (prompt, segments meta).
	 * @param array<string,mixed> $args     Router args.
	 * @return array<string,mixed>
	 */
	public function analyze_transcript( array $payload, array $args = array() ) {
		$args['prompt']  = (string) vvai_array_get( $payload, 'prompt', '' );
		$args['system']  = (string) vvai_array_get( $payload, 'system', '' );
		$args['json']    = true;
		$args['purpose'] = 'analysis';

		$result = $this->generate( $args );

		if ( empty( $result['ok'] ) ) {
			$result['clips'] = array();

			return $result;
		}

		$list = VVAI_Json::extract_list( (string) $result['text'], 'clips' );

		if ( empty( $list['ok'] ) ) {
			$result['ok']      = false;
			$result['code']    = 'invalid_json';
			$result['message'] = (string) $list['error'];
			$result['clips']   = array();
			$result['invalid']  = true;

			return $result;
		}

		$result['clips']  = $list['list'];
		$result['method'] = (string) $list['method'];

		return $result;
	}

	/**
	 * Ask a model to transcribe audio, using the connection's own capability or
	 * the configured transcription endpoint.
	 *
	 * @param array<string,mixed> $connection Connection record.
	 * @param string              $audio_path Absolute audio path.
	 * @param array<string,mixed> $args        {language, model, offset, duration, job_hint}.
	 * @return array<string,mixed>
	 */
	public function transcribe( array $connection, $audio_path, array $args = array() ) {
		$adapter = $this->providers->get( (string) vvai_array_get( $connection, 'provider', '' ) );

		if ( is_wp_error( $adapter ) ) {
			return array(
				'ok'      => false,
				'segments' => array(),
				'text'    => '',
				'code'    => (string) $adapter->get_error_code(),
				'message' => (string) $adapter->get_error_message(),
			);
		}

		return $adapter->transcribe( $this->hydrate( $connection ), $audio_path, $args );
	}

	/**
	 * List the models a connection offers (Advanced UI helper).
	 *
	 * @param string $id Connection id.
	 * @return array{ok:bool,models:array<int,string>,message:string}
	 */
	public function list_models( $id ) {
		$record  = $this->connections->get( $id );
		$empty   = array(
			'ok'      => false,
			'models'  => array(),
			'message' => __( 'Connection not found.', 'viral-video-ai' ),
		);

		if ( ! $record ) {
			return $empty;
		}

		$adapter = $this->providers->get( (string) $record['provider'] );

		if ( is_wp_error( $adapter ) ) {
			return array(
				'ok'      => false,
				'models'  => array(),
				'message' => (string) $adapter->get_error_message(),
			);
		}

		$verified = $adapter->validate_credentials( $this->hydrate( $record ) );

		if ( empty( $verified['ok'] ) && ! $verified['models'] ) {
			return array(
				'ok'      => false,
				'models'  => array(),
				'message' => (string) $verified['message'],
			);
		}

		return array(
			'ok'      => true,
			'models'  => array_values( (array) $verified['models'] ),
			'message' => '',
		);
	}

	/**
	 * Attach the plaintext secret to a copy of the record.
	 *
	 * @param array<string,mixed> $record Connection record.
	 * @return array<string,mixed>
	 */
	public function hydrate( array $record ) {
		$record['api_key'] = $this->connections->reveal_secret( (string) $record['id'] );

		return $record;
	}

	/**
	 * Build the ordered list of connections to try.
	 *
	 * @param array<string,mixed> $args Router args.
	 * @return array<int,array{record:array<string,mixed>,adapter:VVAI_AI_Provider_Interface}>
	 */
	private function build_chain( array $args ) {
		$chain    = array();
		$explicit = (string) vvai_array_get( $args, 'connection', '' );
		$primary  = $this->get_active_connection( $explicit );

		if ( $primary ) {
			$adapter = $this->providers->get( (string) $primary['provider'] );

			if ( ! is_wp_error( $adapter ) ) {
				$chain[] = array(
					'record'  => $this->hydrate( $primary ),
					'adapter' => $adapter,
				);
			}
		}

		$allow_fallback = array_key_exists( 'allow_fallback', $args )
			? (bool) $args['allow_fallback']
			: (bool) $this->settings->get( 'allow_fallback' );

		if ( $allow_fallback ) {
			$fallback = $this->connections->get_fallback();

			if ( $fallback && ( ! $primary || $fallback['id'] !== $primary['id'] ) ) {
				$adapter = $this->providers->get( (string) $fallback['provider'] );

				if ( ! is_wp_error( $adapter ) ) {
					$chain[] = array(
						'record'  => $this->hydrate( $fallback ),
						'adapter' => $adapter,
					);
				}
			}
		}

		return $chain;
	}

	/**
	 * One provider call.
	 *
	 * @param array<string,mixed> $item  Chain item.
	 * @param array<string,mixed> $args   Router args.
	 * @param int                 $index  Attempt index.
	 * @return array<string,mixed>
	 */
	private function call( array $item, array $args, $index ) {
		$request = array(
			'prompt'      => (string) vvai_array_get( $args, 'prompt', '' ),
			'system'      => (string) vvai_array_get( $args, 'system', '' ),
			'json'        => (bool) vvai_array_get( $args, 'json', true ),
			'temperature' => isset( $args['temperature'] ) ? (float) $args['temperature'] : (float) $this->settings->get( 'temperature' ),
			'max_tokens'  => isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 4000,
			'timeout'     => isset( $args['timeout'] ) ? (int) $args['timeout'] : $this->default_timeout(),
			'job_hint'    => sprintf( 'job:%1$s attempt:%2$s', isset( $args['job_id'] ) ? (int) $args['job_id'] : 0, (int) $index ),
		);

		if ( isset( $args['messages'] ) && is_array( $args['messages'] ) ) {
			$request['messages'] = $args['messages'];
		}

		$result = $item['adapter']->generate( $item['record'], $request );

		if ( ! empty( $result['code'] ) && empty( $result['ok'] ) ) {
			// Providers can describe the same problem with their own vocabulary;
			// normalize_error() maps it onto the plugin-wide error codes so the UI
			// and the fallback policy behave identically for every adapter.
			$normalized = $item['adapter']->normalize_error(
				array(
					'code'          => (string) $result['code'],
					'message'       => (string) $result['message'],
					'http'          => (int) $result['http'],
					'retryable'     => (bool) $result['retryable'],
					'provider_code' => (string) vvai_array_get( $result, 'provider_code', '' ),
					'provider_status' => (string) vvai_array_get( $result, 'provider_status', '' ),
				)
			);

			$result['code']      = (string) vvai_array_get( $normalized, 'code', $result['code'] );
			$result['message']   = (string) vvai_array_get( $normalized, 'message', $result['message'] );
			$result['retryable'] = (bool) vvai_array_get( $normalized, 'retryable', $result['retryable'] );
			$result['hint']      = (string) vvai_array_get( $normalized, 'hint', vvai_array_get( $result, 'hint', '' ) );
		}

		return $result;
	}

	/**
	 * Default timeout for analysis calls.
	 *
	 * @return int
	 */
	private function default_timeout() {
		return max( 30, min( 900, (int) $this->settings->get( 'process_timeout' ) ) );
	}

	/**
	 * Decide whether to move on to the fallback connection.
	 *
	 * @param array<string,mixed> $result Provider result.
	 * @param array             $chain   Chain.
	 * @param int               $index   Current index.
	 * @return bool
	 */
	private function should_fall_back( array $result, array $chain, $index ) {
		if ( ! isset( $chain[ $index + 1 ] ) ) {
			return false;
		}

		$code = (string) vvai_array_get( $result, 'code', '' );

		// Never hide an authentication/quota problem behind another provider:
		// the admin must fix the key or the billing.
		$blocking = array( 'invalid_api_key', 'forbidden', 'quota_exceeded', 'missing_api_key', 'bad_request', 'unsupported_transcription' );

		if ( in_array( $code, $blocking, true ) ) {
			/**
			 * Filter whether a specific error code may trigger provider fallback.
			 *
			 * @param bool   $allow Allow fallback for this code.
			 * @param string $code     Error code.
			 * @param array  $result    Provider result.
			 */
			return (bool) apply_filters( 'vvai_fallback_on_error', false, $code, $result );
		}

		return (bool) vvai_array_get( $result, 'retryable', false )
			|| in_array( $code, array( 'provider_unavailable', 'model_unavailable', 'payload_too_large', 'provider_timeout', 'empty_response', 'unparseable_response' ), true );
	}

	/**
	 * Verify credentials against the provider (used by connect()).
	 *
	 * @param array<string,mixed>          $record   Connection record.
	 * @param VVAI_AI_Provider_Interface   $adapter  Adapter.
	 * @return array<string,mixed>
	 */
	private function attempt_verification( array $record, $adapter ) {
		$hydrated = $this->hydrate( $record );

		// Do not reuse a stale detected model list for validation.
		unset( $hydrated['detected_models'] );

		$result = $adapter->validate_credentials( $hydrated );

		return array_merge(
			array(
				'ok'        => false,
				'code'      => 'unknown',
				'message'   => __( 'The provider did not answer.', 'viral-video-ai' ),
				'http'      => 0,
				'latency'   => 0,
				'retryable' => false,
				'models'    => array(),
			),
			is_array( $result ) ? $result : array()
		);
	}

	/**
	 * Bookkeeping after a successful call.
	 *
	 * @param array<string,mixed> $record Connection record.
	 * @param array<string,mixed> $result  Provider result.
	 * @param array<string,mixed> $args     Router args.
	 */
	private function record_success( array $record, array $result, array $args ) {
		$id = (string) vvai_array_get( $record, 'id', '' );

		if ( '' === $id ) {
			return;
		}

		$all = $this->connections->all();

		if ( isset( $all[ $id ] ) ) {
			$all[ $id ]['request_count'] = (int) vvai_array_get( $all[ $id ], 'request_count', 0 ) + 1;
			$all[ $id ]['last_used_at']  = gmdate( 'Y-m-d H:i:s' );
			$all[ $id ]['last_latency_ms'] = (int) vvai_array_get( $result, 'latency', 0 );

			update_option( VVAI_Connection_Store::OPTION_KEY, $all, 'yes' );
		}

		// Token accounting is kept on the connection record itself: the pipeline
		// reads it for the job detail screen, and the admin sees provider usage per
		// connection without needing a second table.
		$in  = (int) vvai_array_get( (array) vvai_array_get( $result, 'usage', array() ), 'input', 0 );
		$out = (int) vvai_array_get( (array) vvai_array_get( $result, 'usage', array() ), 'output', 0 );

		if ( $in || $out ) {
			$all = $this->connections->all();

			if ( isset( $all[ $id ] ) ) {
				$all[ $id ]['tokens_in']  = (int) vvai_array_get( $all[ $id ], 'tokens_in', 0 ) + $in;
				$all[ $id ]['tokens_out'] = (int) vvai_array_get( $all[ $id ], 'tokens_out', 0 ) + $out;

				update_option( VVAI_Connection_Store::OPTION_KEY, $all, 'yes' );
			}
		}
	}
}
