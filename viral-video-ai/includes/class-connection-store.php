<?php
/**
 * Connection store.
 *
 * A "connection" is one named provider credential (OpenAI — Main, Groq —
 * Fast…). Records live in the `vvai_connections` option, keyed by a random id.
 *
 * The plaintext API key is written to the option only through
 * VVAI_Crypto::encrypt(); every read path that leaves the process (REST,
 * admin screens, AJAX) receives VVAI_Connection::public_view(), which contains
 * a mask instead of a secret.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Connection_Store
 */
class VVAI_Connection_Store {

	const OPTION_KEY = 'vvai_connections';
	const MAX_RECORDS = 40;

	const STATUS_DISCONNECTED = 'disconnected';
	const STATUS_CONNECTING   = 'connecting';
	const STATUS_CONNECTED    = 'connected';
	const STATUS_FAILED       = 'failed';

	/**
	 * Crypto service.
	 *
	 * @var VVAI_Crypto
	 */
	private $crypto;

	/**
	 * Constructor.
	 *
	 * @param VVAI_Crypto|null $crypto Crypto service.
	 */
	public function __construct( $crypto = null ) {
		$this->crypto = $crypto instanceof VVAI_Crypto ? $crypto : new VVAI_Crypto();
	}

	/**
	 * All raw records.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();

		foreach ( $stored as $id => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$record['id'] = (string) $id;
			$clean[ (string) $id ] = $record;
		}

		return $clean;
	}

	/**
	 * Public (safe) list for the UI.
	 *
	 * @param array<string,string> $args { status?: string }
	 * @return array<int,array<string,mixed>>
	 */
	public function list_public( array $args = array() ) {
		$out = array();

		foreach ( $this->all() as $record ) {
			$view = $this->public_view( $record );

			if ( ! empty( $args['status'] ) && $view['status'] !== $args['status'] ) {
				continue;
			}

			$out[] = $view;
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['title'], (string) $b['title'] );
			}
		);

		return $out;
	}

	/**
	 * One record.
	 *
	 * @param string $id Connection id.
	 * @return array<string,mixed>|null
	 */
	public function get( $id ) {
		$id = $this->sanitize_id( $id );

		if ( '' === $id ) {
			return null;
		}

		$all = $this->all();

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Persist a record.
	 *
	 * @param array<string,mixed> $record Raw record.
	 * @return array{ok:bool,record?:array<string,mixed>,code?:string,message?:string}
	 */
	public function save( array $record ) {
		$record = $this->sanitize_record( $record );

		if ( is_wp_error( $record ) ) {
			return array(
				'ok'      => false,
				'code'    => (string) $record->get_error_code(),
				'message' => (string) $record->get_error_message(),
			);
		}

		$all = $this->all();

		if ( '' === $record['id'] ) {
			if ( count( $all ) >= self::MAX_RECORDS ) {
				return array(
					'ok'      => false,
					'code'    => 'too_many_connections',
					'message' => sprintf(
						/* translators: %d: maximum number of connections. */
						__( 'This plugin stores at most %d connections. Delete one first.', 'viral-video-ai' ),
						self::MAX_RECORDS
					),
				);
			}

			$record['id'] = 'conn_' . vvai_random_id( 10 );
		}

		$exists = isset( $all[ $record['id'] ] );

		// Editing must not silently drop an existing credential.
		if ( $exists ) {
			$previous = $all[ $record['id'] ];

			if ( '' === $record['secret_input'] && ! empty( $previous['secret_enc'] ) ) {
				$record['secret_enc']    = $previous['secret_enc'];
				$record['secret_mask']   = $previous['secret_mask'];
				$record['fingerprint']   = $previous['fingerprint'];
			}

			$record['created_at'] = $previous['created_at'] ?? $record['created_at'];
			$record['status']     = ( '' !== $record['secret_input'] ) ? self::STATUS_DISCONNECTED : $record['status'];
		}

		unset( $record['secret_input'] );

		$all[ $record['id'] ] = $record;
		$this->write( $all );

		return array(
			'ok'     => true,
			'record' => $record,
			'created' => ! $exists,
		);
	}

	/**
	 * Delete a record.
	 *
	 * @param string $id Connection id.
	 * @return bool
	 */
	public function delete( $id ) {
		$id  = $this->sanitize_id( $id );
		$all = $this->all();

		if ( '' === $id || ! isset( $all[ $id ] ) ) {
			return false;
		}

		unset( $all[ $id ] );
		$this->write( $all );

		// Clear references to the removed connection.
		$settings = get_option( VVAI_Settings::OPTION_KEY, array() );
		$dirty    = false;

		if ( is_array( $settings ) ) {
			foreach ( array( 'active_connection_id', 'fallback_connection_id' ) as $key ) {
				if ( ! empty( $settings[ $key ] ) && $settings[ $key ] === $id ) {
					$settings[ $key ] = '';
					$dirty            = true;
				}
			}

			if ( $dirty ) {
				update_option( VVAI_Settings::OPTION_KEY, $settings );
			}
		}

		return true;
	}

	/**
	 * Update the connection status and error.
	 *
	 * @param string $id     Connection id.
	 * @param string $status Status.
	 * @param array  $error  Optional error payload.
	 * @param array  $extra  Extra fields to merge (latency, model, http code).
	 * @return array<string,mixed>|null
	 */
	public function set_status( $id, $status, array $error = array(), array $extra = array() ) {
		$id     = $this->sanitize_id( $id );
		$all    = $this->all();
		$status = in_array( $status, array( self::STATUS_CONNECTED, self::STATUS_CONNECTING, self::STATUS_FAILED, self::STATUS_DISCONNECTED ), true )
			? $status
			: self::STATUS_DISCONNECTED;

		if ( '' === $id || ! isset( $all[ $id ] ) ) {
			return null;
		}

		$all[ $id ]['status']      = $status;
		$all[ $id ]['updated_at']  = gmdate( 'Y-m-d H:i:s' );

		if ( self::STATUS_CONNECTED === $status ) {
			$all[ $id ]['last_success_at'] = gmdate( 'Y-m-d H:i:s' );
			$all[ $id ]['last_error']      = array();
		} elseif ( ! empty( $error ) ) {
			$all[ $id ]['last_error'] = array(
				'code'    => substr( (string) vvai_array_get( $error, 'code', 'error' ), 0, 48 ),
				'message' => substr( vvai_sanitize_text( vvai_array_get( $error, 'message', '' ), 300 ), 0, 300 ),
				'http'    => (int) vvai_array_get( $error, 'http', 0 ),
				'when'    => gmdate( 'Y-m-d H:i:s' ),
			);
		}

		foreach ( $extra as $key => $value ) {
			if ( in_array( $key, array( 'last_latency_ms', 'model', 'detected_models', 'last_http_code' ), true ) ) {
				$all[ $id ][ $key ] = $value;
			}
		}

		$this->write( $all );

		return $all[ $id ];
	}

	/**
	 * Mark a connection active (the one used by the video engine).
	 *
	 * @param string $id Connection id.
	 * @return bool
	 */
	public function set_active( $id ) {
		$id     = $this->sanitize_id( $id );
		$record = $this->get( $id );

		if ( ! $record ) {
			return false;
		}

		$settings = get_option( VVAI_Settings::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['active_connection_id'] = $id;

		// Only a connected credential may be selected as the engine.
		if ( self::STATUS_CONNECTED !== $record['status'] ) {
			$settings['fallback_connection_id'] = '';
		}

		update_option( VVAI_Settings::OPTION_KEY, $settings );

		return true;
	}

	/**
	 * The active connection, or null.
	 *
	 * A disconnected connection is never returned: the pipeline must refuse to
	 * run rather than fall back to stale credentials.
	 *
	 * @param bool $require_connected Whether connected status is mandatory.
	 * @return array<string,mixed>|null
	 */
	public function get_active( $require_connected = true ) {
		$settings = get_option( VVAI_Settings::OPTION_KEY, array() );
		$id       = is_array( $settings ) ? $this->sanitize_id( vvai_array_get( $settings, 'active_connection_id', '' ) ) : '';

		if ( '' === $id ) {
			// Fall back to the first connected record: a fresh install that only
			// connected one provider should not need another click.
			foreach ( $this->all() as $record ) {
				if ( ! $require_connected || self::STATUS_CONNECTED === $record['status'] ) {
					return $record;
				}
			}

			return null;
		}

		$record = $this->get( $id );

		if ( ! $record ) {
			return null;
		}

		if ( $require_connected && self::STATUS_CONNECTED !== (string) vvai_array_get( $record, 'status', '' ) ) {
			return null;
		}

		return $record;
	}

	/**
	 * The configured fallback connection, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_fallback() {
		$settings = get_option( VVAI_Settings::OPTION_KEY, array() );
		$id       = is_array( $settings ) ? $this->sanitize_id( vvai_array_get( $settings, 'fallback_connection_id', '' ) ) : '';

		if ( '' === $id || empty( $settings['allow_fallback'] ) ) {
			return null;
		}

		$record = $this->get( $id );

		if ( ! $record || self::STATUS_CONNECTED !== (string) vvai_array_get( $record, 'status', '' ) ) {
			return null;
		}

		if ( $id === (string) vvai_array_get( $settings, 'active_connection_id', '' ) ) {
			return null;
		}

		return $record;
	}

	/**
	 * Reveal the plaintext API key for an immediate outbound request.
	 *
	 * Never exposed via REST/AJAX and never logged.
	 *
	 * @param string $id Connection id.
	 * @return string
	 */
	public function reveal_secret( $id ) {
		$record = $this->get( $id );

		if ( ! $record ) {
			return '';
		}

		$secret = $this->crypto->decrypt( (string) vvai_array_get( $record, 'secret_enc', '' ) );

		/**
		 * Filter the revealed API key.
		 *
		 * Allows vault-backed secrets (AWS Secrets Manager, SOPS, …) to replace
		 * the local encrypted option.
		 *
		 * @param string $secret Plaintext key ('' when unavailable).
		 * @param string $id      Connection id.
		 * @param string $provider Provider key.
		 */
		return (string) apply_filters( 'vvai_reveal_secret', $secret, $record['id'], (string) $record['provider'] );
	}

	/**
	 * Connected records usable as a processing engine.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function connected() {
		$out = array();

		foreach ( $this->all() as $record ) {
			if ( self::STATUS_CONNECTED === (string) vvai_array_get( $record, 'status', '' ) && '' !== (string) vvai_array_get( $record, 'secret_enc', '' ) ) {
				$out[] = $record;
			}
		}

		return $out;
	}

	/**
	 * Sanitized, secret-free view of a record.
	 *
	 * @param array<string,mixed> $record Raw record.
	 * @return array<string,mixed>
	 */
	public function public_view( array $record ) {
		return array(
			'id'              => (string) vvai_array_get( $record, 'id', '' ),
			'title'           => (string) vvai_array_get( $record, 'title', '' ),
			'provider'        => (string) vvai_array_get( $record, 'provider', 'openai' ),
			'providerLabel'   => VVAI_Api_Manager::label_for( (string) vvai_array_get( $record, 'provider', 'openai' ) ),
			'status'          => (string) vvai_array_get( $record, 'status', self::STATUS_DISCONNECTED ),
			'statusLabel'     => self::status_label( (string) vvai_array_get( $record, 'status', self::STATUS_DISCONNECTED ) ),
			'secretMask'      => (string) vvai_array_get( $record, 'secret_mask', '' ),
			'hasSecret'       => '' !== (string) vvai_array_get( $record, 'secret_enc', '' ),
			'model'           => (string) vvai_array_get( $record, 'model', '' ),
			'baseUrl'         => (string) vvai_array_get( $record, 'base_url', '' ),
			'lastSuccessAt'   => (string) vvai_array_get( $record, 'last_success_at', '' ),
			'lastLatencyMs'   => (int) vvai_array_get( $record, 'last_latency_ms', 0 ),
			'lastError'       => (array) vvai_array_get( $record, 'last_error', array() ),
			'isActive'        => (bool) vvai_array_get( $record, 'is_active', false ),
			'supportsJson'    => (bool) vvai_array_get( $record, 'supports_json', true ),
			'transcription'   => (string) vvai_array_get( $record, 'transcription_mode', 'inherit' ),
			'createdAt'       => (string) vvai_array_get( $record, 'created_at', '' ),
			'updatedAt'       => (string) vvai_array_get( $record, 'updated_at', '' ),
			'requestCount'    => (int) vvai_array_get( $record, 'request_count', 0 ),
			'generatedClips'  => (int) vvai_array_get( $record, 'generated_clips', 0 ),
		);
	}

	/**
	 * Status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		switch ( $status ) {
			case self::STATUS_CONNECTED:
				return __( 'Connected', 'viral-video-ai' );
			case self::STATUS_CONNECTING:
				return __( 'Connecting', 'viral-video-ai' );
			case self::STATUS_FAILED:
				return __( 'Connection Failed', 'viral-video-ai' );
		}

		return __( 'Disconnected', 'viral-video-ai' );
	}

	/**
	 * Sanitize a complete record, including encryption of a newly pasted key.
	 *
	 * @param array<string,mixed> $record Raw record.
	 * @return array<string,mixed>|WP_Error
	 */
	protected function sanitize_record( array $record ) {
		$defaults = array(
			'id'                => '',
			'title'             => '',
			'provider'          => 'openai',
			'secret_input'      => '',
			'secret_enc'        => '',
			'secret_mask'       => '',
			'fingerprint'       => '',
			'model'             => '',
			'base_url'          => '',
			'temperature'       => 0.4,
			'max_tokens'        => 4000,
			'timeout'           => 120,
			'status'            => self::STATUS_DISCONNECTED,
			'last_error'        => array(),
			'last_success_at'   => '',
			'last_latency_ms'   => 0,
			'detected_models'   => array(),
			'is_active'         => false,
			'supports_json'     => true,
			'transcription_mode' => 'inherit',
			'smoke_test'        => false,
			'note'              => '',
			'request_count'     => 0,
			'generated_clips'   => 0,
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
		);

		$record = array_merge( $defaults, array_intersect_key( $record, $defaults ) );

		$providers = VVAI_Api_Manager::provider_keys();

		if ( ! in_array( $record['provider'], $providers, true ) ) {
			return new WP_Error( 'invalid_provider', __( 'Unknown AI provider.', 'viral-video-ai' ) );
		}

		$record['id']          = $this->sanitize_id( $record['id'] );
		$record['title']       = vvai_sanitize_text( $record['title'], 60 );
		$record['note']        = vvai_sanitize_text( $record['note'], 160 );
		$record['model']       = vvai_sanitize_text( $record['model'], 80 );
		$record['base_url']    = $this->sanitize_url( $record['base_url'] );
		$record['temperature'] = round( vvai_sanitize_float( $record['temperature'], 0, 2, 0.4 ), 2 );
		$record['max_tokens']  = vvai_sanitize_int( $record['max_tokens'], 256, 128000, 4000 );
		$record['timeout']     = vvai_sanitize_int( $record['timeout'], 10, 900, 120 );
		$record['smoke_test']  = vvai_sanitize_bool( $record['smoke_test'] );
		$record['is_active']   = vvai_sanitize_bool( $record['is_active'] );
		$record['supports_json'] = vvai_sanitize_bool( $record['supports_json'] );
		$record['transcription_mode'] = in_array( $record['transcription_mode'], array( 'inherit', 'provider', 'custom', 'disabled' ), true ) ? $record['transcription_mode'] : 'inherit';

		if ( '' === $record['title'] ) {
			$record['title'] = VVAI_Api_Manager::label_for( $record['provider'] );
		}

		if ( ! is_array( $record['detected_models'] ) ) {
			$record['detected_models'] = array();
		}

		if ( ! is_array( $record['last_error'] ) ) {
			$record['last_error'] = array();
		}

		// A record with no usable secret can never be "connected".
		if ( '' !== $record['secret_input'] ) {
			$secret = trim( (string) $record['secret_input'] );

			if ( strlen( $secret ) < 8 ) {
				return new WP_Error( 'secret_too_short', __( 'That API key looks too short to be valid. Paste the complete key.', 'viral-video-ai' ) );
			}

			if ( strlen( $secret ) > 512 ) {
				return new WP_Error( 'secret_too_long', __( 'The API key is unreasonably long. Paste only the key.', 'viral-video-ai' ) );
			}

			$encrypted = $this->crypto->encrypt( $secret );

			if ( '' === $encrypted ) {
				return new WP_Error( 'encryption_failed', __( 'The key could not be encrypted on this server. The openssl PHP extension is required for encrypted storage.', 'viral-video-ai' ) );
			}

			$record['secret_enc']  = $encrypted;
			$record['secret_mask'] = $this->crypto->mask( $secret );
			$record['fingerprint'] = hash( 'sha256', $secret );
			$record['status']      = self::STATUS_DISCONNECTED;
			$record['last_error']  = array();
		}

		$record['request_count']   = vvai_sanitize_int( $record['request_count'], 0, PHP_INT_MAX, 0 );
		$record['generated_clips'] = vvai_sanitize_int( $record['generated_clips'], 0, PHP_INT_MAX, 0 );

		if ( '' === $record['model'] ) {
			$record['model'] = VVAI_Api_Manager::default_model_for( $record['provider'] );
		}

		return $record;
	}

	/**
	 * Persist the whole option.
	 *
	 * @param array<string,array<string,mixed>> $all Records.
	 */
	protected function write( array $all ) {
		$active_id = '';
		$settings  = get_option( VVAI_Settings::OPTION_KEY, array() );

		if ( is_array( $settings ) ) {
			$active_id = (string) vvai_array_get( $settings, 'active_connection_id', '' );
		}

		foreach ( $all as $id => $record ) {
			$all[ $id ]['is_active'] = ( $id === $active_id );
		}

		update_option( self::OPTION_KEY, $all, 'yes' );
	}

	/**
	 * Connection id sanitizer.
	 *
	 * @param mixed $id Raw id.
	 * @return string
	 */
	public function sanitize_id( $id ) {
		$id = is_scalar( $id ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id ) : '';

		return substr( (string) $id, 0, 40 );
	}

	/**
	 * Base URL sanitizer for custom providers.
	 *
	 * @param mixed $url Raw URL.
	 * @return string
	 */
	protected function sanitize_url( $url ) {
		return $this->is_valid_endpoint( $url ) ? untrailingslashit( esc_url_raw( trim( (string) $url ) ) ) : '';
	}

	/**
	 * Endpoint validation shared with the Custom provider (public because the
	 * provider adapter needs it too).
	 *
	 * @param mixed $url Raw URL.
	 * @return bool
	 */
	public static function is_valid_endpoint( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url || strlen( $url ) > 300 ) {
			return false;
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return false;
		}

		$host = strtolower( (string) $parts['host'] );

		// Block obvious SSRF targets: the loopback interface, link-local
		// (cloud metadata) and private ranges are only allowed when a host
		// deliberately opts in.
		$allow_private = apply_filters( 'vvai_allow_private_endpoints', false, $host );

		if ( ! $allow_private ) {
			$ip = gethostbyname( $host );

			if ( $ip !== $host ) {
				if (
					0 === strpos( $ip, '127.' )
					|| 0 === strpos( $ip, '0.' )
					|| 0 === strpos( $ip, '169.254.' )
					|| 0 === strpos( $ip, '10.' )
					|| 0 === strpos( $ip, '192.168.' )
					|| preg_match( '/^172\.(1[6-9]|2\d|3[01])\./', $ip )
				) {
					return false;
				}
			}

			if ( in_array( $host, array( 'localhost', 'metadata.google.internal', 'metadata' ), true ) ) {
				return false;
			}
		}

		return true;
	}
}
