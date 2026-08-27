<?php
/**
 * Outbound HTTP transport.
 *
 * Every AI call goes through this class. It owns the timeouts, the WordPress
 * HTTP API interaction, the classification of transport failures into
 * human-readable messages (spec §9) and the redaction of credentials before
 * anything is logged.
 *
 * Provider adapters never call wp_remote_*() themselves.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Api_Connection
 */
class VVAI_Api_Connection {

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
	 * @param VVAI_Settings|null $settings Settings.
	 * @param VVAI_Logger|null   $logger   Logger.
	 */
	public function __construct( $settings = null, $logger = null ) {
		$this->settings = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
		$this->logger   = $logger instanceof VVAI_Logger ? $logger : new VVAI_Logger( $this->settings );
	}

	/**
	 * Perform a request.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $url    Endpoint.
	 * @param array<string,mixed> $args   {
	 *     @type array<string,string> $headers   Extra headers.
	 *     @type array|string|null     $json      JSON body.
	 *     @type string|null          $body      Raw body.
	 *     @type array<int,array{name:string,filename:string,type:string,contents:string}> $files Multipart files.
	 *     @type int                  $timeout   Seconds.
	 *     @type string             $job_hint  Free-form context for the log.
	 * }
	 * @return array{ok:bool,http:int,body:string,json:mixed,error:array<string,mixed>|null,latency:int,headers:array<string,string>}
	 */
	public function request( $method, $url, array $args = array() ) {
		$method = strtoupper( (string) $method );
		$start  = microtime( true );

		$result = array(
			'ok'      => false,
			'http'    => 0,
			'body'    => '',
			'json'    => null,
			'error'   => null,
			'latency' => 0,
			'headers' => array(),
		);

		if ( ! is_string( $url ) || '' === $url ) {
			$result['error'] = $this->error( 'invalid_url', __( 'The provider endpoint is not configured.', 'viral-video-ai' ) );

			return $result;
		}

		$timeout = isset( $args['timeout'] ) ? max( 5, min( 900, (int) $args['timeout'] ) ) : 60;

		$request = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'connect_timeout' => min( 15, $timeout ),
			'redirection' => 0,
			'headers'     => $this->sanitize_headers( isset( $args['headers'] ) ? (array) $args['headers'] : array() ),
			'sslverify'   => true,
			'httpversion' => '1.1',
			'user-agent'  => 'ViralVideoAI/' . VVAI_VERSION . ' (WordPress)',
		);

		if ( isset( $args['json'] ) && null !== $args['json'] ) {
			$request['body']    = wp_json_encode( $args['json'] );
			$request['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$request['headers']['Accept']        = 'application/json';
		} elseif ( isset( $args['multipart'] ) && is_array( $args['multipart'] ) ) {
			// Multipart is assembled manually so it behaves identically on the
			// cURL and the streams transport, and so a file can be sent without
			// relying on CURLFile availability.
			$built = VVAI_Multipart::build(
				isset( $args['multipart']['fields'] ) ? (array) $args['multipart']['fields'] : array(),
				isset( $args['multipart']['files'] ) ? (array) $args['multipart']['files'] : array()
			);

			if ( is_wp_error( $built ) ) {
				$result['error'] = $this->error( 'multipart_failed', $built->get_error_message() );

				return $result;
			}

			$request['body']                     = $built['body'];
			$request['headers']['Content-Type']  = $built['content_type'];
			$request['headers']['Expect']        = '';
			$request['headers']['Accept']        = 'application/json';
		} elseif ( isset( $args['body'] ) && is_string( $args['body'] ) ) {
			$request['body'] = $args['body'];
		} elseif ( isset( $args['formdata'] ) && is_array( $args['formdata'] ) ) {
			$request['body'] = $args['formdata'];
		}

		if ( isset( $args['query'] ) && is_array( $args['query'] ) && $args['query'] ) {
			$url = add_query_arg( array_map( 'rawurlencode', $args['query'] ), $url );
		}

		/**
		 * Filter the outbound request before it leaves WordPress.
		 *
		 * Add-ons may inject proxy settings or extra headers. Removing
		 * `sslverify` or lowering `timeout` below safe values is the site
		 * owner's responsibility.
		 *
		 * @param array $request Requests arguments.
		 * @param string $url       Endpoint.
		 * @param array $args       Original args.
		 */
		$request = apply_filters( 'vvai_http_request_args', $request, $url, $args );

		$response = ( 'GET' === $request['method'] )
			? wp_remote_get( $url, $request )
			: wp_remote_request( $url, $request );

		$result['latency'] = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$result['error'] = $this->classify_transport_error( $response );
			$this->log_failure( $url, $result, $args );

			return $result;
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$body  = (string) wp_remote_retrieve_body( $response );
		$http_headers = wp_remote_retrieve_headers( $response );

		$result['http']    = $code;
		$result['body']    = $body;
		$result['headers'] = is_object( $http_headers ) ? $this->header_map( $http_headers ) : (array) $http_headers;
		$result['json']    = json_decode( $body, true );

		if ( $code >= 200 && $code < 300 ) {
			$result['ok'] = true;

			$this->logger->debug(
				'AI request succeeded',
				array(
					'endpoint' => $this->safe_endpoint( $url ),
					'http'     => $code,
					'latency'  => $result['latency'],
					'job'      => isset( $args['job_hint'] ) ? $args['job_hint'] : null,
				)
			);

			return $result;
		}

		$result['error'] = $this->classify_http_error( $code, $body, $result['json'] );
		$this->log_failure( $url, $result, $args );

		return $result;
	}

	/**
	 * GET helper.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args Args.
	 * @return array
	 */
	public function get( $url, array $args = array() ) {
		$args['method'] = 'GET';

		return $this->request( 'GET', $url, $args );
	}

	/**
	 * POST helper.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args Args.
	 * @return array
	 */
	public function post( $url, array $args = array() ) {
		return $this->request( 'POST', $url, $args );
	}

	/**
	 * Turn a provider error body into a normalised error array.
	 *
	 * Providers disagree on shape, so OpenAI-style `{"error":{"message":…}}`,
	 * Anthropic-style `{"type":"error","error":{…}}` and Gemini-style
	 * `{"error":{"status":"INVALID_ARGUMENT"}}` are all handled here.
	 *
	 * @param mixed $json Decoded body.
	 * @return array{code:string,message:string,provider_status:string}
	 */
	public function extract_provider_error( $json ) {
		$out = array(
			'code'           => '',
			'message'        => '',
			'provider_status' => '',
		);

		if ( ! is_array( $json ) ) {
			return $out;
		}

		$node = isset( $json['error'] ) ? $json['error'] : ( isset( $json['errors'][0] ) ? $json['errors'][0] : null );

		if ( is_string( $node ) ) {
			$out['message'] = $node;

			return $out;
		}

		if ( ! is_array( $node ) ) {
			if ( isset( $json['message'] ) && is_string( $json['message'] ) ) {
				$out['message'] = $json['message'];
			}

			return $out;
		}

		foreach ( array( 'message', 'msg', 'detail' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && '' !== $node[ $key ] ) {
				$out['message'] = $node[ $key ];
				break;
			}
		}

		foreach ( array( 'code', 'type' ) as $key ) {
			if ( isset( $node[ $key ] ) && ( is_string( $node[ $key ] ) || is_int( $node[ $key ] ) ) ) {
				$out['code'] = (string) $node[ $key ];
				break;
			}
		}

		foreach ( array( 'status', 'reason' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) ) {
				$out['provider_status'] = $node[ $key ];
				break;
			}
		}

		return $out;
	}

	/**
	 * Build a normalised error array.
	 *
	 * @param string $code     Machine code.
	 * @param string $message  Human message.
	 * @param array  $extra    Extra keys (http, retryable, hint, provider_code).
	 * @return array<string,mixed>
	 */
	public function error( $code, $message, array $extra = array() ) {
		return array_merge(
			array(
				'code'      => (string) $code,
				'message'   => substr( vvai_sanitize_text( $message, 400 ), 0, 400 ),
				'http'      => 0,
				'retryable' => false,
				'hint'      => '',
			),
			$extra
		);
	}

	/**
	 * Map a WordPress HTTP_Error to a meaningful message.
	 *
	 * @param WP_Error $error Error.
	 * @return array<string,mixed>
	 */
	protected function classify_transport_error( $error ) {
		$wp_code = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();
		$lower   = strtolower( $message . ' ' . $wp_code );

		if ( false !== strpos( $lower, 'timed out' ) || false !== strpos( $lower, 'timeout' ) ) {
			return $this->error(
				'timeout',
				__( 'The AI provider did not answer in time. The request timed out before the server replied.', 'viral-video-ai' ),
				array(
					'retryable' => true,
					'hint'      => __( 'Retry, or lower the number of clips / pick a faster provider (Groq).', 'viral-video-ai' ),
				)
			);
		}

		if ( false !== strpos( $lower, 'ssl' ) || false !== strpos( $lower, 'certificate' ) || false !== strpos( $lower, 'handshake' ) ) {
			return $this->error(
				'ssl_error',
				__( 'SSL error while contacting the provider. The server certificate could not be verified.', 'viral-video-ai' ),
				array( 'hint' => __( 'Ask your host to update the CA bundle (php.ini openssl.cafile) — do not disable SSL verification.', 'viral-video-ai' ) )
			);
		}

		if (
			false !== strpos( $lower, 'name resolution' )
			|| false !== strpos( $lower, 'dns' )
			|| false !== strpos( $lower, 'could not resolve host' )
			|| 'http_request_failed' === $wp_code && false !== strpos( $lower, 'resolve' )
		) {
			return $this->error(
				'dns_error',
				__( 'Your server could not resolve the provider hostname (DNS/network failure).', 'viral-video-ai' ),
				array(
					'retryable' => true,
					'hint'      => __( 'Outbound HTTP may be blocked by the firewall. Test `curl https://api.openai.com` from the server.', 'viral-video-ai' ),
				)
			);
		}

		if ( false !== strpos( $lower, 'connection refused' ) || false !== strpos( $lower, 'network is unreachable' ) || false !== strpos( $lower, 'no route to host' ) ) {
			return $this->error(
				'network_error',
				__( 'The provider server is unreachable from this host (connection refused / no route).', 'viral-video-ai' ),
				array(
					'retryable' => true,
					'hint'      => __( 'Outbound connections on port 443 are probably blocked. Ask your hosting provider to allow them.', 'viral-video-ai' ),
				)
			);
		}

		if ( false !== strpos( $lower, 'blocked' ) || false !== strpos( $lower, 'not allowed' ) ) {
			return $this->error( 'http_blocked', __( 'WordPress blocked this outbound request (http_request_allowed or a security plugin denied it).', 'viral-video-ai' ) );
		}

		return $this->error(
			'wp_http_error',
			sprintf(
				/* translators: %s: transport error message. */
				__( 'The WordPress HTTP API could not reach the provider: %s', 'viral-video-ai' ),
				( '' !== $message ? $message : __( 'unknown transport error.', 'viral-video-ai' ) )
			),
			array( 'retryable' => true )
		);
	}

	/**
	 * Map an HTTP status + provider payload to a normalised error.
	 *
	 * @param int         $code  HTTP status.
	 * @param string      $body  Raw body.
	 * @param mixed       $json  Decoded body.
	 * @return array<string,mixed>
	 */
	protected function classify_http_error( $code, $body, $json ) {
		$provider = $this->extract_provider_error( $json );
		$detail   = '' !== $provider['message'] ? $provider['message'] : trim( wp_strip_all_tags( substr( (string) $body, 0, 240 ) ) );
		$extra    = array( 'http' => $code );

		if ( '' !== $provider['code'] ) {
			$extra['provider_code'] = substr( (string) $provider['code'], 0, 60 );
		}

		if ( '' !== $provider['provider_status'] ) {
			$extra['provider_status'] = substr( (string) $provider['provider_status'], 0, 40 );
		}

		switch ( $code ) {
			case 401:
			case 407:
				return $this->error(
					'invalid_api_key',
					__( 'The provider rejected this API key (HTTP 401 Unauthorized). The key is wrong, revoked, or belongs to a different provider.', 'viral-video-ai' ),
					array_merge( $extra, array( 'hint' => __( 'Copy the key again from the provider dashboard and reconnect. Free/trial keys can expire — check the account balance too.', 'viral-video-ai' ) ) )
				);

			case 403:
				return $this->error(
					'forbidden',
					__( 'The provider refused this account (HTTP 403 Forbidden).', 'viral-video-ai' ),
					array_merge(
						$extra,
						array(
							'detail' => $detail,
							'hint'   => __( 'The key may be restricted by IP, project, or model permissions. Provider message: ', 'viral-video-ai' ) . $detail,
						)
					)
				);

			case 404:
				return $this->error(
					'model_unavailable',
					__( 'The endpoint or model was not found (HTTP 404). The model id may not exist on this account.', 'viral-video-ai' ),
					array_merge( $extra, array( 'detail' => $detail, 'hint' => __( 'Open the connection and pick a different model, or fix the base URL for a custom provider.', 'viral-video-ai' ) ) )
				);

			case 400:
				if ( false !== stripos( $detail, 'model' ) && false !== stripos( $detail, 'not' ) ) {
					return $this->error( 'model_unavailable', __( 'The requested model is not available for this account.', 'viral-video-ai' ), array_merge( $extra, array( 'detail' => $detail ) ) );
				}

				return $this->error(
					'bad_request',
					__( 'The provider rejected the request (HTTP 400).', 'viral-video-ai' ),
					array_merge( $extra, array( 'detail' => $detail, 'hint' => __( 'Provider message: ', 'viral-video-ai' ) . $detail, 'retryable' => false ) )
				);

			case 413:
				return $this->error( 'payload_too_large', __( 'The request was too large for the provider (HTTP 413).', 'viral-video-ai' ), array_merge( $extra, array( 'retryable' => true, 'hint' => __( 'Reduce "Maximum clips per job" or the transcript chunk size.', 'viral-video-ai' ) ) ) );

			case 422:
				return $this->error( 'unprocessable', __( 'The provider could not process this request (HTTP 422).', 'viral-video-ai' ), array_merge( $extra, array( 'detail' => $detail ) ) );

			case 429:
				return $this->error(
					'rate_limited',
					__( 'Rate limit exceeded (HTTP 429). The provider account has run out of available requests or credits for this minute.', 'viral-video-ai' ),
					array_merge(
						$extra,
						array(
							'retryable' => true,
							'detail'    => $detail,
							'hint'      => __( 'Wait a moment and retry, lower the transcript chunk size, or select a connection with more quota.', 'viral-video-ai' ),
						)
					)
				);

			case 402:
				return $this->error( 'quota_exceeded', __( 'The provider account has no credit left (HTTP 402).', 'viral-video-ai' ), array_merge( $extra, array( 'detail' => $detail, 'hint' => __( 'Top up the account or switch to a connection with credit.', 'viral-video-ai' ) ) ) );

			case 408:
				return $this->error( 'provider_timeout', __( 'The provider timed out while answering (HTTP 408).', 'viral-video-ai' ), array_merge( $extra, array( 'retryable' => true ) ) );
		}

		if ( $code >= 500 ) {
			return $this->error(
				'provider_unavailable',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The provider is having problems (HTTP %d). This is on their side, not your WordPress installation.', 'viral-video-ai' ),
					$code
				),
				array_merge( $extra, array( 'retryable' => true, 'detail' => $detail ) )
			);
		}

		return $this->error(
			'http_error',
			sprintf(
				/* translators: 1: HTTP status, 2: provider message. */
				__( 'The provider returned HTTP %1$d. %2$s', 'viral-video-ai' ),
				$code,
				$detail
			),
			$extra
		);
	}

	/**
	 * Strip values that must never be written to disk.
	 *
	 * @param array<string,mixed> $headers Headers.
	 * @return array<string,string>
	 */
	protected function sanitize_headers( array $headers ) {
		$clean = array();

		foreach ( $headers as $key => $value ) {
			$key = trim( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			$clean[ $key ] = (string) $value;
		}

		return $clean;
	}

	/**
	 * Flatten a Requests_Utility_CaseInsensitiveDictionary into an array.
	 *
	 * @param object $headers Header bag.
	 * @return array<string,string>
	 */
	protected function header_map( $headers ) {
		$out = array();

		foreach ( $headers as $key => $value ) {
			$lower = strtolower( (string) $key );

			if ( in_array( $lower, array( 'set-cookie', 'authorization', 'x-request-id' ), true ) && 'x-request-id' !== $lower ) {
				$out[ $key ] = '[redacted]';
				continue;
			}

			$out[ $key ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return $out;
	}

	/**
	 * Endpoint without credentials/query secrets, for logs.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function safe_endpoint( $url ) {
		$parts = wp_parse_url( (string) $url );
		$safe  = ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['path'] ) ? $parts['path'] : '' );

		return substr( $safe, 0, 160 );
	}

	/**
	 * Log a failed request without ever including headers or bodies.
	 *
	 * @param string $url    URL.
	 * @param array  $result Result.
	 * @param array  $args   Args.
	 */
	protected function log_failure( $url, array $result, array $args ) {
		$error = is_array( $result['error'] ) ? $result['error'] : array();

		$this->logger->error(
			'AI request failed',
			array(
				'endpoint' => $this->safe_endpoint( $url ),
				'http'     => (int) $result['http'],
				'code'     => (string) vvai_array_get( $error, 'code', '' ),
				'message'  => substr( (string) vvai_array_get( $error, 'message', '' ), 0, 200 ),
				'latency'  => (int) $result['latency'],
				'job'      => isset( $args['job_hint'] ) ? $args['job_hint'] : null,
			)
		);
	}
}
