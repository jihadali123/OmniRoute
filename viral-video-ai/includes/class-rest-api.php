<?php
/**
 * REST API.
 *
 * Namespace `vvai/v1`. The frontend widget and the admin screens both speak to
 * this controller, so there is exactly one implementation of every rule
 * (capability, ownership, nonce, validation) — the AJAX layer only wraps it.
 *
 * Secrets never appear in any response: connections are returned through
 * VVAI_Connection_Store::public_view(), which exposes a mask instead of a key.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Rest_Api
 */
class VVAI_Rest_Api {

	/**
	 * @var VVAI_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param VVAI_Plugin $plugin Container.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register routes.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Whether REST endpoints resolve (some hosts disable them).
	 *
	 * @return bool
	 */
	public static function is_reachable() {
		$cached = get_transient( 'vvai_rest_reachable' );

		if ( 'yes' === $cached ) {
			return true;
		}

		if ( 'no' === $cached ) {
			return false;
		}

		if ( ! function_exists( 'rest_url' ) ) {
			return false;
		}

		$response = wp_remote_get(
			esc_url_raw( rest_url( VVAI_REST_NAMESPACE . '/providers' ) ),
			array(
				'timeout'   => 4,
				'sslverify' => true,
			)
		);

		$ok = ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 500 );

		set_transient( 'vvai_rest_reachable', $ok ? 'yes' : 'no', 15 * MINUTE_IN_SECONDS );

		return $ok;
	}

	/**
	 * Route table.
	 */
	public function routes() {
		$manage    = array( $this, 'can_manage' );
		$generate  = array( $this, 'can_generate' );
		$read_job  = array( $this, 'can_read_job' );
		$public    = '__return_true';

		// Provider catalogue (needed by the widget to describe the active engine).
		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_providers' ),
				'permission_callback' => $public,
			)
		);

		// Connections.
		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/connections',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'route_list_connections' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'route_create_connection' ),
					'permission_callback' => $manage,
				),
			)
		);

		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/connections/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'route_get_connection' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'route_update_connection' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'route_delete_connection' ),
					'permission_callback' => $manage,
				),
			)
		);

		foreach ( array( 'connect', 'disconnect', 'activate', 'models' ) as $action ) {
			register_rest_route(
				VVAI_REST_NAMESPACE,
				'/connections/(?P<id>[a-zA-Z0-9_-]+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'route_connection_action' ),
					'permission_callback' => $manage,
					'args'                => array( 'action' => array( 'sanitize_callback' => 'sanitize_key' ) ),
				)
			);
		}

		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/connections/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_verify_candidate' ),
				'permission_callback' => $manage,
			)
		);

		// Uploads.
		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/uploads',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_upload_init' ),
				'permission_callback' => $generate,
			)
		);

		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/uploads/(?P<handle>[a-zA-Z0-9_-]+)/chunk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_upload_chunk' ),
				'permission_callback' => $generate,
			)
		);

		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/uploads/(?P<handle>[a-zA-Z0-9_-]+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_upload_complete' ),
				'permission_callback' => $generate,
			)
		);

		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/uploads/(?P<handle>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_upload_status' ),
				'permission_callback' => $generate,
			)
		);

		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/sources/url',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_source_url' ),
				'permission_callback' => $generate,
			)
		);

		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/sources/media',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_source_media' ),
				'permission_callback' => $generate,
			)
		);

		// Jobs.
		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/jobs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'route_list_jobs' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'route_create_job' ),
					'permission_callback' => $generate,
				),
			)
		);

		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/jobs/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'route_get_job' ),
					'permission_callback' => $read_job,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'route_delete_job' ),
					'permission_callback' => array( $this, 'can_modify_job' ),
				),
			)
		);

		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/jobs/(?P<id>\d+)/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_job_status' ),
				'permission_callback' => $read_job,
			)
		);

		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/jobs/(?P<id>\d+)/results',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_job_results' ),
				'permission_callback' => $read_job,
			)
		);

		foreach ( array( 'retry', 'cancel', 'start' ) as $action ) {
			register_rest_route(
				VVAI_REST_NAMESPACE,
				'/jobs/(?P<id>\d+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'route_job_action' ),
					'permission_callback' => array( $this, 'can_modify_job' ),
					'args'                => array( 'action' => array( 'sanitize_callback' => 'sanitize_key' ) ),
				)
			);
		}

		// File streaming (preview + download), tokenised for guests.
		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/clips/(?P<id>\d+)/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_clip_file' ),
				'permission_callback' => $public,
				'args'                => array(
					'mode'  => array( 'sanitize_callback' => 'sanitize_key' ),
					'token' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		// Diagnostics + settings.
		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_diagnostics' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'route_get_settings' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'route_save_settings' ),
					'permission_callback' => $manage,
				),
			)
		);

		// Widget bootstrap: the options a logged-out visitor may legitimately see.
		register_rest_route(
			VVAI_REST_NAMESPACE,
			'/config',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_config' ),
				'permission_callback' => $public,
			)
		);
	}

	/**
	 * Administrators only.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage() {
		if ( VVAI_Permissions::can_manage() ) {
			return true;
		}

		return new WP_Error( 'vvai_forbidden', __( 'You are not allowed to manage Viral Video AI.', 'viral-video-ai' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Users who may upload + generate.
	 *
	 * @return bool|WP_Error
	 */
	public function can_generate() {
		if ( VVAI_Permissions::can_create_job() ) {
			return true;
		}

		if ( ! is_user_logged_in() && $this->plugin->settings()->get( 'require_login' ) ) {
			return new WP_Error( 'vvai_login_required', __( 'Please log in to generate clips.', 'viral-video-ai' ), array( 'status' => 401 ) );
		}

		return new WP_Error( 'vvai_forbidden', __( 'You are not allowed to upload videos here.', 'viral-video-ai' ), array( 'status' => 403 ) );
	}

	/**
	 * Job owner or administrator.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_read_job( $request ) {
		$job = $this->job_from_request( $request );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( VVAI_Permissions::can_read_job( $job ) ) {
			return true;
		}

		return new WP_Error( 'vvai_forbidden', __( 'You are not allowed to view this job.', 'viral-video-ai' ), array( 'status' => 403 ) );
	}

	/**
	 * Job owner or administrator (write operations).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_modify_job( $request ) {
		$job = $this->job_from_request( $request );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( VVAI_Permissions::can_modify_job( $job ) ) {
			return true;
		}

		return new WP_Error( 'vvai_forbidden', __( 'You are not allowed to change this job.', 'viral-video-ai' ), array( 'status' => 403 ) );
	}

	/**
	 * Load the job referenced by a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	protected function job_from_request( $request ) {
		$id  = (int) $request['id'];
		$job = $id > 0 ? $this->plugin->jobs()->get( $id ) : null;

		if ( ! $job ) {
			return new WP_Error( 'vvai_job_missing', __( 'Job not found.', 'viral-video-ai' ), array( 'status' => 404 ) );
		}

		return $job;
	}

	/* ------------------------------------------------------------------ *
	 * Providers & connections
	 * ------------------------------------------------------------------ */

	/**
	 * GET /providers — safe catalogue, no secrets.
	 *
	 * @return array<string,mixed>
	 */
	public function route_providers() {
		$catalogue = $this->plugin->providers()->catalogue();

		foreach ( $catalogue as $index => $provider ) {
			// The widget does not need the full model list of every provider.
			$catalogue[ $index ]['models'] = array_slice( (array) $provider['models'], 0, 8 );
		}

		return array(
			'providers' => $catalogue,
			'version'   => VVAI_VERSION,
		);
	}

	/**
	 * GET /connections.
	 *
	 * @return array<string,mixed>
	 */
	public function route_list_connections() {
		$store  = $this->plugin->connections();
		$active = (string) $this->plugin->settings()->get( 'active_connection_id' );

		return array(
			'connections' => $store->list_public(),
			'active'      => $active,
			'fallback'    => (string) $this->plugin->settings()->get( 'fallback_connection_id' ),
			'encryption'  => array(
				'available' => ( $store ? true : true ) && ( new VVAI_Crypto() )->is_available(),
				'message'   => ( new VVAI_Crypto() )->status_message(),
			),
		);
	}

	/**
	 * GET /connections/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_get_connection( $request ) {
		$record = $this->plugin->connections()->get( (string) $request['id'] );

		if ( ! $record ) {
			return new WP_Error( 'vvai_connection_missing', __( 'Connection not found.', 'viral-video-ai' ), array( 'status' => 404 ) );
		}

		return $this->plugin->connections()->public_view( $record );
	}

	/**
	 * POST /connections — create (and optionally connect immediately).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function route_create_connection( $request ) {
		$store  = $this->plugin->connections();
		$params = $request->get_params();

		$saved = $store->save(
			array(
				'title'        => vvai_array_get( $params, 'title', '' ),
				'provider'     => sanitize_key( (string) vvai_array_get( $params, 'provider', 'openai' ) ),
				'secret_input' => (string) vvai_array_get( $params, 'api_key', '' ),
				'model'        => (string) vvai_array_get( $params, 'model', '' ),
				'base_url'     => (string) vvai_array_get( $params, 'base_url', '' ),
				'temperature'  => vvai_array_get( $params, 'temperature', 0.4 ),
				'max_tokens'   => vvai_array_get( $params, 'max_tokens', 4000 ),
				'timeout'      => vvai_array_get( $params, 'timeout', 120 ),
				'smoke_test'   => vvai_array_get( $params, 'smoke_test', false ),
				'note'         => vvai_array_get( $params, 'note', '' ),
			)
		);

		if ( empty( $saved['ok'] ) ) {
			return new WP_Error(
				(string) vvai_array_get( $saved, 'code', 'vvai_connection_invalid' ),
				(string) vvai_array_get( $saved, 'message', __( 'The connection could not be saved.', 'viral-video-ai' ) ),
				array( 'status' => 400 )
			);
		}

		$record = $saved['record'];
		$result = null;

		// "Connect" right away when asked, so the UI needs a single round trip.
		if ( ! isset( $params['connect'] ) || vvai_sanitize_bool( $params['connect'] ) ) {
			$result = $this->plugin->router()->connect( $record['id'] );
		}

		$response = array(
			'connection' => $store->public_view( (array) $store->get( $record['id'] ) ),
		);

		if ( $result ) {
			$response['connected'] = (bool) $result['ok'];
			$response['message']   = (string) $result['message'];
			$response['code']      = (string) $result['code'];
			$response['hint']      = (string) vvai_array_get( $result, 'hint', '' );

			if ( empty( $result['ok'] ) ) {
				return new WP_Error(
					(string) $result['code'],
					(string) $result['message'],
					array(
						'status' => 400,
						'data'   => $response,
					)
				);
			}
		}

		return rest_ensure_response( $response );
	}

	/**
	 * PUT /connections/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_update_connection( $request ) {
		$store  = $this->plugin->connections();
		$params = $request->get_params();
		$id     = (string) $request['id'];

		$existing = $store->get( $id );

		if ( ! $existing ) {
			return new WP_Error( 'vvai_connection_missing', __( 'Connection not found.', 'viral-video-ai' ), array( 'status' => 404 ) );
		}

		$saved = $store->save(
			array_merge(
				(array) $existing,
				array(
					'id'           => $id,
					'title'        => vvai_array_get( $params, 'title', $existing['title'] ),
					'provider'     => (string) vvai_array_get( $params, 'provider', $existing['provider'] ),
					'secret_input' => (string) vvai_array_get( $params, 'api_key', '' ),
					'model'        => (string) vvai_array_get( $params, 'model', $existing['model'] ),
					'base_url'     => (string) vvai_array_get( $params, 'base_url', $existing['base_url'] ),
					'temperature'  => vvai_array_get( $params, 'temperature', $existing['temperature'] ),
					'max_tokens'   => vvai_array_get( $params, 'max_tokens', $existing['max_tokens'] ),
					'timeout'      => vvai_array_get( $params, 'timeout', $existing['timeout'] ),
					'smoke_test'   => vvai_array_get( $params, 'smoke_test', $existing['smoke_test'] ),
					'note'         => vvai_array_get( $params, 'note', $existing['note'] ),
					'transcription_mode' => vvai_array_get( $params, 'transcription_mode', $existing['transcription_mode'] ),
				)
			)
		);

		if ( empty( $saved['ok'] ) ) {
			return new WP_Error(
				(string) vvai_array_get( $saved, 'code', 'vvai_connection_invalid' ),
				(string) vvai_array_get( $saved, 'message', __( 'The connection could not be saved.', 'viral-video-ai' ) ),
				array( 'status' => 400 )
			);
		}

		$out = array( 'connection' => $store->public_view( $saved['record'] ) );

		if ( isset( $params['connect'] ) && vvai_sanitize_bool( $params['connect'] ) ) {
			$result        = $this->plugin->router()->connect( $id );
			$out['connected'] = (bool) $result['ok'];
			$out['message']   = (string) $result['message'];
		}

		return $out;
	}

	/**
	 * DELETE /connections/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_delete_connection( $request ) {
		$id = (string) $request['id'];

		if ( ! $this->plugin->connections()->get( $id ) ) {
			return new WP_Error( 'vvai_connection_missing', __( 'Connection not found.', 'viral-video-ai' ), array( 'status' => 404 ) );
		}

		$this->plugin->connections()->delete( $id );

		return array(
			'deleted' => true,
			'message' => __( 'Connection removed. Its key was deleted from the database.', 'viral-video-ai' ),
		);
	}

	/**
	 * POST /connections/{id}/connect|disconnect|activate|models.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_connection_action( $request ) {
		$action = sanitize_key( (string) $request['action'] );
		$id     = (string) $request['id'];
		$store  = $this->plugin->connections();

		if ( ! $store->get( $id ) ) {
			return new WP_Error( 'vvai_connection_missing', __( 'Connection not found.', 'viral-video-ai' ), array( 'status' => 404 ) );
		}

		switch ( $action ) {
			case 'connect':
				$result = $this->plugin->router()->connect( $id );

				$response = array(
					'connected'  => (bool) $result['ok'],
					'status'     => (string) vvai_array_get( $result, 'status_label', $result['ok'] ? 'connected' : 'failed' ),
					'message'    => (string) $result['message'],
					'code'       => (string) $result['code'],
					'hint'       => (string) vvai_array_get( $result, 'hint', '' ),
					'connection' => (array) $result['record'],
					'latency'    => (int) vvai_array_get( $result, 'latency', 0 ),
				);

				if ( empty( $result['ok'] ) ) {
					return new WP_Error(
						(string) ( $result['code'] ?: 'connection_failed' ),
						(string) $result['message'],
						array(
							'status' => 400,
							'data'   => $response,
						)
					);
				}

				return $response;

			case 'disconnect':
				return array(
					'connected'  => false,
					'status'     => 'disconnected',
					'message'    => __( 'Disconnected. This key will not be used for processing until it is reconnected.', 'viral-video-ai' ),
					'connection' => $store->disconnect( $id ),
				);

			case 'activate':
				$record = $store->get( $id );

				if ( VVAI_Connection_Store::STATUS_CONNECTED !== (string) $record['status'] ) {
					return new WP_Error( 'vvai_not_connected', __( 'Please connect this provider before selecting it as the active AI engine.', 'viral-video-ai' ), array( 'status' => 400 ) );
				}

				$store->set_active( $id );

				return array(
					'active'   => $id,
					'message'  => sprintf(
						/* translators: %s: connection title. */
						__( '%s is now the active AI connection.', 'viral-video-ai' ),
						(string) $record['title']
					),
				);

			case 'models':
				$models = $this->plugin->router()->list_models( $id );

				return array(
					'models'  => (array) $models['models'],
					'message' => (string) $models['message'],
					'ok'      => (bool) $models['ok'],
				);
		}

		return new WP_Error( 'vvai_unknown_action', __( 'Unknown connection action.', 'viral-video-ai' ), array( 'status' => 400 ) );
	}

	/**
	 * POST /connections/verify — test credentials before saving.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_verify_candidate( $request ) {
		$params = $request->get_params();

		$result = $this->plugin->router()->verify_candidate(
			array(
				'provider'   => sanitize_key( (string) vvai_array_get( $params, 'provider', 'openai' ) ),
				'api_key'    => (string) vvai_array_get( $params, 'api_key', '' ),
				'model'      => (string) vvai_array_get( $params, 'model', '' ),
				'base_url'   => (string) vvai_array_get( $params, 'base_url', '' ),
				'smoke_test' => (bool) vvai_array_get( $params, 'smoke_test', false ),
				'timeout'    => 25,
				'title'      => __( 'Unsaved connection', 'viral-video-ai' ),
			)
		);

		return array(
			'ok'      => (bool) $result['ok'],
			'code'    => (string) $result['code'],
			'message' => (string) $result['message'],
			'models'  => array_slice( (array) $result['models'], 0, 60 ),
			'latency' => (int) $result['latency'],
		);
	}

	/* ------------------------------------------------------------------ *
	 * Uploads
	 * ------------------------------------------------------------------ */

	/**
	 * POST /uploads — open or resume a session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_upload_init( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$session = $this->plugin->uploads()->init_session(
			get_current_user_id(),
			array(
				'name'   => (string) vvai_array_get( $params, 'name', '' ),
				'size'   => (int) vvai_array_get( $params, 'size', 0 ),
				'chunk'  => (int) vvai_array_get( $params, 'chunk_size', $this->plugin->settings()->get( 'upload_chunk_size' ) ),
				'hash'   => (string) vvai_array_get( $params, 'hash', '' ),
				'handle' => (string) vvai_array_get( $params, 'handle', '' ),
			)
		);

		if ( is_wp_error( $session ) ) {
			return $this->to_rest_error( $session );
		}

		return array(
			'handle'     => (string) $session['handle'],
			'chunkSize'  => (int) $session['chunk_size'],
			'chunkTotal' => (int) $session['chunk_total'],
			'received'   => array_values( array_map( 'intval', (array) vvai_array_get( $session, 'received', array() ) ) ),
			'resume'     => ! empty( $session['resume'] ),
			'totalBytes' => (int) $session['total_bytes'],
			'finalized'  => isset( $session['finalized'] ) ? (array) $session['finalized'] : null,
		);
	}

	/**
	 * POST /uploads/{handle}/chunk — append one part.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_upload_chunk( $request ) {
		$handle = (string) $request['handle'];
		$files  = $request->get_file_params();

		if ( empty( $files['chunk']['tmp_name'] ) ) {
			return new WP_Error( 'vvai_no_chunk', __( 'No chunk was received.', 'viral-video-ai' ), array( 'status' => 400 ) );
		}

		$index = isset( $files['chunk']['name'] ) ? (int) preg_replace( '/\D/', '', (string) $files['chunk']['name'] ) : 0;

		// The client also sends the index as a field; trust the field.
		$provided = $request->get_param( 'chunk_index' );

		if ( null !== $provided && is_numeric( $provided ) ) {
			$index = (int) $provided;
		}

		$result = $this->plugin->uploads()->store_chunk( $handle, $index, (string) $files['chunk']['tmp_name'] );

		if ( is_wp_error( $result ) ) {
			return $this->to_rest_error( $result, 400 );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /uploads/{handle}/complete.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_upload_complete( $request ) {
		$handle  = (string) $request['handle'];
		$started = microtime( true );

		$this->extend_request_time();

		$final = $this->plugin->uploads()->finalize( $handle, get_current_user_id() );

		if ( is_wp_error( $final ) ) {
			return $this->to_rest_error( $final, 400 );
		}

		return array(
			'handle'     => (string) $final['handle'],
			'name'       => (string) $final['name'],
			'size'       => (int) $final['size'],
			'sizeLabel'  => vvai_human_size( (int) $final['size'] ),
			'mime'       => (string) $final['mime'],
			'hash'       => (string) $final['hash'],
			'sourceRef'  => $this->issue_source_ref( $final ),
			'sniffMs'    => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);
	}

	/**
	 * GET /uploads/{handle}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_upload_status( $request ) {
		$status = $this->plugin->uploads()->status( (string) $request['handle'] );

		if ( is_wp_error( $status ) ) {
			return $this->to_rest_error( $status, 404 );
		}

		return $status;
	}

	/**
	 * POST /sources/url — import a direct video URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_source_url( $request ) {
		$this->extend_request_time();

		$source = $this->plugin->uploads()->from_url(
			(string) $request->get_param( 'url' ),
			get_current_user_id()
		);

		if ( is_wp_error( $source ) ) {
			return $this->to_rest_error( $source, 400 );
		}

		return array(
			'name'      => (string) $source['name'],
			'size'      => (int) $source['size'],
			'sizeLabel' => vvai_human_size( (int) $source['size'] ),
			'mime'      => (string) $source['mime'],
			'sourceRef' => $this->issue_source_ref( $source ),
		);
	}

	/**
	 * POST /sources/media — use a media library attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_source_media( $request ) {
		$attachment = (int) $request->get_param( 'attachment_id' );

		if ( $attachment <= 0 || ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'vvai_bad_attachment', __( 'Select a video from the media library.', 'viral-video-ai' ), array( 'status' => 400 ) );
		}

		$source = $this->plugin->uploads()->from_attachment( $attachment );

		if ( is_wp_error( $source ) ) {
			return $this->to_rest_error( $source, 400 );
		}

		return array(
			'name'         => (string) $source['name'],
			'size'         => (int) $source['size'],
			'sizeLabel'    => vvai_human_size( (int) $source['size'] ),
			'mime'         => (string) $source['mime'],
			'attachmentId' => $attachment,
			'sourceRef'    => $this->issue_source_ref( $source ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Jobs
	 * ------------------------------------------------------------------ */

	/**
	 * GET /jobs (admin list).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function route_list_jobs( $request ) {
		$page = $this->plugin->jobs()->query(
			array(
				'per_page' => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) ),
				'page'     => max( 1, (int) $request->get_param( 'page' ) ?: 1 ),
				'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
				'author_id' => (int) $request->get_param( 'author_id' ),
				'search'   => (string) $request->get_param( 'search' ),
				'order_by' => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'order'    => sanitize_key( (string) $request->get_param( 'order' ) ),
			)
		);

		$items = array();

		foreach ( (array) $page['items'] as $job ) {
			$items[] = $this->job_view( $job );
		}

		return array(
			'items' => $items,
			'total' => (int) $page['total'],
			'pages' => (int) $page['pages'],
			'page'  => (int) $page['page'],
			'stats' => $this->plugin->jobs()->stats(),
		);
	}

	/**
	 * POST /jobs — create and (usually) start a job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_create_job( $request ) {
		$preflight = $this->plugin->diagnostics()->preflight();

		if ( empty( $preflight['ok'] ) ) {
			return new WP_Error(
				(string) $preflight['code'],
				(string) $preflight['message'],
				array( 'status' => 503 )
			);
		}

		$params = $request->get_params();

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$source = $this->resolve_source_ref( (string) vvai_array_get( $params, 'source_ref', '' ) );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$settings = $this->sanitize_job_settings( $params );
		$connection = $this->resolve_connection_choice( $params );

		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$jobs = $this->plugin->jobs();

		$job_id = $jobs->create(
			array(
				'author_id'    => get_current_user_id(),
				'title'        => (string) vvai_array_get( $source, 'name', __( 'Video job', 'viral-video-ai' ) ),
				'source_type'  => (string) vvai_array_get( $source, 'type', 'upload' ),
				'source_path'  => (string) vvai_array_get( $source, 'path', '' ),
				'source_url'   => (string) vvai_array_get( $source, 'url', '' ),
				'source_hash'  => (string) vvai_array_get( $source, 'hash', '' ),
				'file_size'    => (int) vvai_array_get( $source, 'size', 0 ),
				'connection_id' => $connection,
				'settings'     => $settings,
				'retention_days' => (int) $this->plugin->settings()->get( 'clip_retention_days' ),
			)
		);

		if ( is_wp_error( $job_id ) ) {
			return $this->to_rest_error( $job_id, 500 );
		}

		$job_id = (int) $job_id;

		$jobs->set_stage( $job_id, VVAI_Job_Status::UPLOADED, 8 );

		if ( ! empty( $settings['title'] ) ) {
			$jobs->update( $job_id, array( 'title' => (string) $settings['title'] ) );
		}

		$started = false;

		if ( $this->plugin->settings()->get( 'auto_start_job' ) && ! isset( $params['auto_start'] ) ) {
			$started = true;
		} elseif ( isset( $params['auto_start'] ) && vvai_sanitize_bool( $params['auto_start'] ) ) {
			$started = true;
		}

		if ( $started ) {
			$this->plugin->queue()->dispatch( $job_id, 1 );
		}

		$job = $jobs->get( $job_id );

		// Consume the source reference: it is single-use.
		$this->consume_source_ref( (string) vvai_array_get( $params, 'source_ref', '' ) );

		return array(
			'job'     => $this->job_view( (array) $job ),
			'started' => $started,
			'message' => $started
				? __( 'Upload received. Processing started.', 'viral-video-ai' )
				: __( 'Upload received. Start processing when you are ready.', 'viral-video-ai' ),
		);
	}

	/**
	 * GET /jobs/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_get_job( $request ) {
		$job = $this->job_from_request( $request );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$view = $this->job_view( (array) $job );

		if ( VVAI_Job_Status::COMPLETED === (string) $job['status'] || VVAI_Job_Status::FAILED === (string) $job['status'] ) {
			$view['clips'] = $this->plugin->results()->clip_payloads( (int) $job['id'] );
		}

		$view['analysis'] = $this->analysis_summary( (array) $job );

		return $view;
	}

	/**
	 * GET /jobs/{id}/status — the polling endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_job_status( $request ) {
		$job = $this->job_from_request( $request );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$payload = VVAI_Job_Status::public_payload( (array) $job );

		// A more specific stage note is set by the pipeline while analysing.
		$note = get_transient( 'vvai_stage_note_' . (int) $job['id'] );

		if ( $note && VVAI_Job_Status::ANALYZING === (string) $job['status'] ) {
			$payload['stageLabel'] = (string) $note;
		}

		if ( in_array( (string) $job['status'], array( VVAI_Job_Status::COMPLETED, VVAI_Job_Status::FAILED ), true ) ) {
			$payload['clips'] = $this->plugin->results()->clip_payloads( (int) $job['id'] );
		}

		return $payload;
	}

	/**
	 * GET /jobs/{id}/results.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_job_results( $request ) {
		$job = $this->job_from_request( $request );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return array(
			'job'   => VVAI_Job_Status::public_payload( (array) $job ),
			'clips' => $this->plugin->results()->clip_payloads( (int) $job['id'] ),
			'meta'  => $this->analysis_summary( (array) $job ),
		);
	}

	/**
	 * POST /jobs/{id}/retry|start|cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_job_action( $request ) {
		$action = sanitize_key( (string) $request['action'] );
		$job    = $this->job_from_request( $request );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$jobs = $this->plugin->jobs();

		switch ( $action ) {
			case 'retry':
				$from  = sanitize_key( (string) $request->get_param( 'from' ) );
				$count = $this->plugin->clip_generator()->delete_outputs( (int) $job['id'] );
				$retry = $jobs->prepare_retry( (int) $job['id'], $from );

				if ( empty( $retry['ok'] ) ) {
					return new WP_Error( 'vvai_retry_blocked', (string) $retry['message'], array( 'status' => 400 ) );
				}

				if ( '' !== (string) $request->get_param( 'connection' ) ) {
					$settings = (array) $job['settings_array'];
					$settings['connection_id'] = sanitize_text_field( (string) $request->get_param( 'connection' ) );
					$jobs->update( (int) $job['id'], array( 'settings' => wp_json_encode( $settings ) ) );
				}

				$this->plugin->queue()->dispatch( (int) $job['id'], 1 );

				return array(
					'message'  => (string) $retry['message'],
					'stage'    => (string) $retry['stage'],
					'job'      => $this->job_view( (array) $jobs->get( (int) $job['id'] ) ),
					'rendered' => (int) $count,
				);

			case 'start':
				if ( VVAI_Job_Status::QUEUED !== (string) $job['status'] ) {
					return new WP_Error( 'vvai_already_started', __( 'This job is already in progress.', 'viral-video-ai' ), array( 'status' => 400 ) );
				}

				$problem = $this->plugin->router()->connection_problem( (string) vvai_array_get( (array) $job['settings_array'], 'connection_id', '' ) );

				if ( '' !== $problem ) {
					return new WP_Error( 'vvai_no_connection', $problem, array( 'status' => 400 ) );
				}

				$this->plugin->queue()->dispatch( (int) $job['id'], 1 );

				return array(
					'message' => __( 'Processing started.', 'viral-video-ai' ),
					'job'     => $this->job_view( (array) $jobs->get( (int) $job['id'] ) ),
				);

			case 'cancel':
				if ( VVAI_Job_Status::is_terminal( (string) $job['status'] ) ) {
					return new WP_Error( 'vvai_finished', __( 'This job already finished.', 'viral-video-ai' ), array( 'status' => 400 ) );
				}

				$jobs->update(
					(int) $job['id'],
					array(
						'status'       => VVAI_Job_Status::CANCELLED,
						'error_code'   => 'cancelled',
						'error_message' => __( 'Cancelled by the user.', 'viral-video-ai' ),
						'finished_at'  => gmdate( 'Y-m-d H:i:s' ),
					)
				);

				$jobs->release( (int) $job['id'] );

				return array(
					'message' => __( 'Job cancelled.', 'viral-video-ai' ),
					'job'     => $this->job_view( (array) $jobs->get( (int) $job['id'] ) ),
				);
		}

		return new WP_Error( 'vvai_unknown_action', __( 'Unknown job action.', 'viral-video-ai' ), array( 'status' => 400 ) );
	}

	/**
	 * DELETE /jobs/{id} — row, clips, files.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_delete_job( $request ) {
		$job = $this->job_from_request( $request );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$files  = $this->plugin->results()->delete_job_files( (int) $job['id'] );
		$deleted = $this->plugin->jobs()->delete( (int) $job['id'] );

		if ( ! $deleted ) {
			return new WP_Error( 'vvai_delete_failed', __( 'The job could not be deleted.', 'viral-video-ai' ), array( 'status' => 500 ) );
		}

		return array(
			'deleted' => true,
			'files'   => (int) $files['files'],
			'bytes'   => (int) $files['bytes'],
			'message' => sprintf(
				/* translators: %s: human size. */
				__( 'Job and its rendered files removed (%s freed).', 'viral-video-ai' ),
				vvai_human_size( (int) $files['bytes'] )
			),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Files, diagnostics, settings
	 * ------------------------------------------------------------------ */

	/**
	 * GET /clips/{id}/file — authorised stream with byte ranges.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return null|WP_Error Null on success (the response is already streamed).
	 */
	public function route_clip_file( $request ) {
		$clip_id = (int) $request['id'];
		$mode    = sanitize_key( (string) $request->get_param( 'mode' ) );
		$token   = (string) $request->get_param( 'vvai_token' );

		// A token-bearing GET has no side effects, so no nonce applies; ownership
		// is enforced inside authorize() through the capability *or* the HMAC.
		$authorized = $this->plugin->results()->authorize( $clip_id, $token, in_array( $mode, array( 'preview', 'download', 'captions' ), true ) ? $mode : 'preview' );

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$this->plugin->clips()->record_download( $clip_id );

		$this->plugin->results()->stream( (array) $authorized );

		return null;
	}

	/**
	 * GET /diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public function route_diagnostics() {
		$report = $this->plugin->diagnostics()->report();

		$report['log'] = array(
			'enabled' => (bool) $this->plugin->settings()->get( 'debug_log' ),
			'tail'    => $this->plugin->logger()->tail( 30 ),
		);

		return $report;
	}

	/**
	 * GET /settings (secrets excluded).
	 *
	 * @return array<string,mixed>
	 */
	public function route_get_settings() {
		$settings = $this->plugin->settings()->all();

		unset( $settings['transcription_api_key'] );

		return array(
			'settings'  => $settings,
			'defaults'  => VVAI_Settings::defaults(),
		);
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function route_save_settings( $request ) {
		$params = $request->get_params();

		if ( ! is_array( $params ) ) {
			return new WP_Error( 'vvai_bad_payload', __( 'Invalid settings payload.', 'viral-video-ai' ), array( 'status' => 400 ) );
		}

		$stored = $this->plugin->settings()->all();

		// A blank key field means "keep what is stored".
		if ( ! isset( $params['transcription_api_key'] ) || '' === trim( (string) $params['transcription_api_key'] ) ) {
			$params['transcription_api_key'] = (string) $stored['transcription_api_key'];
		}

		$clean = $this->plugin->settings()->sanitize( array_merge( $stored, $params ) );

		update_option( VVAI_Settings::OPTION_KEY, $clean, 'yes' );

		return array(
			'saved'    => true,
			'settings' => $clean,
			'message'  => __( 'Settings saved.', 'viral-video-ai' ),
		);
	}

	/**
	 * GET /config — what the widget needs before anyone authenticates.
	 *
	 * @return array<string,mixed>
	 */
	public function route_config() {
		$settings    = $this->plugin->settings();
		$connections = array();

		foreach ( $this->plugin->connections()->connected() as $record ) {
			$connections[] = array(
				'id'     => (string) $record['id'],
				'title'  => (string) $record['title'],
				'provider' => VVAI_Api_Manager::label_for( (string) $record['provider'] ),
			);
		}

		$active = $this->plugin->router()->get_active_connection();

		return array(
			'version'        => VVAI_VERSION,
			'hasConnection'  => (bool) $active,
			'connectionError' => $this->plugin->router()->connection_problem(),
			'connections'    => $connections,
			'requireLogin'   => (bool) $settings->get( 'require_login' ),
			'loggedIn'       => is_user_logged_in(),
			'canSubmit'      => VVAI_Permissions::can_create_job(),
			'maxUploadBytes' => (int) $settings->max_upload_bytes(),
			'chunkSize'      => (int) $settings->get( 'upload_chunk_size' ),
			'allowedExtensions' => $settings->get( 'allowed_extensions' ),
			'defaults'       => array(
				'aspect'  => (string) $settings->get( 'default_aspect_ratio' ),
				'quality' => (string) $settings->get( 'default_quality' ),
				'length'  => (string) $settings->get( 'default_clip_length' ),
				'focus'   => (string) $settings->get( 'default_focus' ),
				'clips'   => (int) $settings->get( 'max_clips' ),
			),
			'rest'           => true,
			'stages'         => VVAI_Job_Status::stage_labels(),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Turn a WP_Error into a REST error, mapping our codes to statuses.
	 *
	 * @param WP_Error $error   Error.
	 * @param int      $default Default status.
	 * @return WP_Error
	 */
	protected function to_rest_error( $error, $default = 400 ) {
		$data = (array) $error->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : $default;

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array(
				'status' => $status ?: $default,
				'data'   => $data,
			)
		);
	}

	/**
	 * Give this request more wall clock for large file assembly.
	 */
	protected function extend_request_time() {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		@set_time_limit( (int) $this->plugin->settings()->get( 'process_timeout' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- hosts may forbid it.
	}

	/**
	 * Job view for the API: safe fields only.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return array<string,mixed>
	 */
	protected function job_view( array $job ) {
		$payload = VVAI_Job_Status::public_payload( $job );
		$settings = isset( $job['settings_array'] ) ? (array) $job['settings_array'] : array();

		$payload['source'] = array(
			'name' => (string) $job['title'],
			'size' => (int) $job['file_size'],
			'sizeLabel' => vvai_human_size( (int) $job['file_size'] ),
			'type' => (string) $job['source_type'],
			'available' => ( '' !== (string) $job['source_path'] && is_file( (string) $job['source_path'] ) ),
		);

		$payload['media'] = array(
			'duration'     => round( (float) $job['duration'], 2 ),
			'durationLabel' => vvai_format_time( (float) $job['duration'] ),
			'width'        => (int) $job['width'],
			'height'       => (int) $job['height'],
			'fps'          => round( (float) $job['fps'], 2 ),
			'vcodec'       => (string) $job['vcodec'],
			'acodec'       => (string) $job['acodec'],
			'hasAudio'     => (bool) $job['has_audio'],
		);

		$payload['options'] = array(
			'clipLength'  => (string) vvai_array_get( $settings, 'clip_length', 'short' ),
			'minDuration' => (int) vvai_array_get( $settings, 'min_duration', 0 ),
			'maxDuration' => (int) vvai_array_get( $settings, 'max_duration', 0 ),
			'focus'       => (string) vvai_array_get( $settings, 'focus', 'viral' ),
			'aspect'      => (string) vvai_array_get( $settings, 'aspect_ratio', '9:16' ),
			'quality'     => (string) vvai_array_get( $settings, 'quality', '1080p' ),
			'cropMode'    => (string) vvai_array_get( $settings, 'crop_mode', 'center' ),
			'targetClips' => (int) vvai_array_get( $settings, 'target_clips', 5 ),
			'burnCaptions' => (bool) vvai_array_get( $settings, 'burn_captions', false ),
			'cleanedAt'   => (string) vvai_array_get( $settings, 'cleaned_at', '' ),
		);

		$payload['author'] = (int) $job['author_id'];
		$payload['authorName'] = $this->author_name( (int) $job['author_id'] );

		return $payload;
	}

	/**
	 * Analysis bookkeeping (no secrets, no raw provider payloads).
	 *
	 * @param array<string,mixed> $job Job row.
	 * @return array<string,mixed>
	 */
	protected function analysis_summary( array $job ) {
		$raw      = vvai_json_decode( (string) $job['ai_response'], true );
		$meta     = is_array( $raw ) ? (array) vvai_array_get( $raw, 'usage', array() ) : array();
		$out      = array(
			'transcriptSegments' => count( (array) vvai_json_decode( (string) $job['transcript'], true ) ),
			'passes'      => is_array( $raw ) ? (int) vvai_array_get( $raw, 'passes', 0 ) : 0,
			'rejected'    => is_array( $raw ) ? (int) vvai_array_get( $raw, 'rejected', 0 ) : 0,
			'provider'    => is_array( $raw ) ? (string) vvai_array_get( $raw, 'provider', '' ) : '',
			'connectionId' => is_array( $raw ) ? (string) vvai_array_get( $raw, 'connection', '' ) : '',
			'usage'       => $meta,
			'warnings'    => is_array( $raw ) ? (array) vvai_array_get( $raw, 'warnings', array() ) : array(),
		);

		$out['transcriptChars'] = 0;

		foreach ( (array) vvai_json_decode( (string) $job['transcript'], true ) as $segment ) {
			$out['transcriptChars'] += strlen( (string) vvai_array_get( $segment, 'text', '' ) );
		}

		return $out;
	}

	/**
	 * Author display name.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	protected function author_name( $user_id ) {
		$user = get_user_by( 'id', (int) $user_id );

		return $user ? (string) $user->display_name : __( 'Removed user', 'viral-video-ai' );
	}

	/**
	 * Validate the job options coming from the widget/admin form.
	 *
	 * @param array<string,mixed> $params Params.
	 * @return array<string,mixed>
	 */
	protected function sanitize_job_settings( array $params ) {
		$settings = $this->plugin->settings();

		$mode = sanitize_key( (string) vvai_array_get( $params, 'clip_length', $settings->get( 'default_clip_length' ) ) );

		if ( ! in_array( $mode, array( 'short', 'medium', 'long', 'custom' ), true ) ) {
			$mode = (string) $settings->get( 'default_clip_length' );
		}

		list( $range_min, $range_max ) = VVAI_Settings::duration_range(
			$mode,
			(int) vvai_array_get( $params, 'min_duration', 0 ),
			(int) vvai_array_get( $params, 'max_duration', 0 )
		);

		$focus = sanitize_key( (string) vvai_array_get( $params, 'focus', $settings->get( 'default_focus' ) ) );

		if ( ! isset( VVAI_Prompt_Builder::focuses()[ $focus ] ) ) {
			$focus = (string) $settings->get( 'default_focus' );
		}

		$aspect = sanitize_text_field( (string) vvai_array_get( $params, 'aspect_ratio', $settings->get( 'default_aspect_ratio' ) ) );

		if ( ! in_array( $aspect, array( '9:16', '16:9', '1:1', '4:5' ), true ) ) {
			$aspect = (string) $settings->get( 'default_aspect_ratio' );
		}

		$quality = sanitize_key( (string) vvai_array_get( $params, 'quality', $settings->get( 'default_quality' ) ) );

		if ( ! in_array( $quality, array( '720p', '1080p', '4k' ), true ) ) {
			$quality = (string) $settings->get( 'default_quality' );
		}

		return array(
			'clip_length'   => $mode,
			'min_duration'  => (int) $range_min,
			'max_duration'  => (int) $range_max,
			'focus'         => $focus,
			'custom_focus'  => vvai_sanitize_text( vvai_array_get( $params, 'custom_focus', '' ), 300 ),
			'aspect_ratio'  => $aspect,
			'quality'       => $quality,
			'crop_mode'     => in_array( sanitize_key( (string) vvai_array_get( $params, 'crop_mode', $settings->get( 'crop_mode' ) ) ), array( 'center', 'smart' ), true ) ? sanitize_key( (string) vvai_array_get( $params, 'crop_mode', $settings->get( 'crop_mode' ) ) ) : 'smart',
			'target_clips'  => vvai_sanitize_int( vvai_array_get( $params, 'target_clips', $settings->get( 'max_clips' ) ), 1, (int) $settings->get( 'max_clips' ), (int) $settings->get( 'max_clips' ) ),
			'burn_captions' => isset( $params['burn_captions'] ) ? vvai_sanitize_bool( $params['burn_captions'] ) : (bool) $settings->get( 'burn_captions' ),
			'generate_srt'  => isset( $params['generate_srt'] ) ? vvai_sanitize_bool( $params['generate_srt'] ) : (bool) $settings->get( 'generate_srt' ),
			'title'         => vvai_sanitize_text( vvai_array_get( $params, 'title', '' ), 100 ),
		);
	}

	/**
	 * Validate the connection selection before a job is created.
	 *
	 * @param array<string,mixed> $params Params.
	 * @return string|WP_Error Connection id or empty for "use the active one".
	 */
	protected function resolve_connection_choice( array $params ) {
		$requested = sanitize_text_field( (string) vvai_array_get( $params, 'connection', '' ) );

		if ( '' === $requested ) {
			$problem = $this->plugin->router()->connection_problem( '' );

			if ( '' !== $problem ) {
				return new WP_Error( 'vvai_no_connection', $problem, array( 'status' => 400 ) );
			}

			return '';
		}

		$record = $this->plugin->connections()->get( $requested );

		if ( ! $record ) {
			return new WP_Error( 'vvai_connection_missing', __( 'That AI connection no longer exists.', 'viral-video-ai' ), array( 'status' => 400 ) );
		}

		if ( VVAI_Connection_Store::STATUS_CONNECTED !== (string) $record['status'] ) {
			return new WP_Error( 'vvai_not_connected', __( 'Please connect an AI provider before processing videos.', 'viral-video-ai' ), array( 'status' => 400 ) );
		}

		return (string) $record['id'];
	}

	/**
	 * Issue a single-use reference for a finished upload.
	 *
	 * The client never receives (or sends back) a filesystem path: it gets an
	 * opaque, expiring reference that maps to the stored file server-side.
	 *
	 * @param array<string,mixed> $source Source descriptor.
	 * @return string
	 */
	public function issue_source_ref( array $source ) {
		$ref = 'src_' . vvai_random_id( 24 );

		set_transient(
			'vvai_src_' . $ref,
			array(
				'path'  => (string) vvai_array_get( $source, 'path', '' ),
				'name'  => (string) vvai_array_get( $source, 'name', '' ),
				'size'  => (int) vvai_array_get( $source, 'size', 0 ),
				'hash'  => (string) vvai_array_get( $source, 'hash', '' ),
				'type'  => (string) vvai_array_get( $source, 'type', 'upload' ),
				'url'   => (string) vvai_array_get( $source, 'sourceUrl', '' ),
				'user'  => get_current_user_id(),
			),
			2 * HOUR_IN_SECONDS
		);

		return $ref;
	}

	/**
	 * Resolve a source reference for the current user.
	 *
	 * @param string $ref Reference.
	 * @return array<string,mixed>|WP_Error
	 */
	protected function resolve_source_ref( $ref ) {
		$ref = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $ref );

		if ( '' === $ref ) {
			return new WP_Error( 'vvai_no_source', __( 'Upload a video (or import one) before starting a job.', 'viral-video-ai' ), array( 'status' => 400 ) );
		}

		$data = get_transient( 'vvai_src_' . $ref );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vvai_source_expired', __( 'That upload reference expired. Please upload the video again.', 'viral-video-ai' ), array( 'status' => 410 ) );
		}

		if ( (int) $data['user'] !== get_current_user_id() && ! VVAI_Permissions::can_manage() ) {
			return new WP_Error( 'vvai_forbidden', __( 'That upload belongs to a different user.', 'viral-video-ai' ), array( 'status' => 403 ) );
		}

		if ( '' === (string) $data['path'] || ! is_file( (string) $data['path'] ) ) {
			return new WP_Error( 'vvai_source_missing', __( 'The uploaded file is no longer on the server.', 'viral-video-ai' ), array( 'status' => 410 ) );
		}

		return $data;
	}

	/**
	 * Burn a used reference.
	 *
	 * @param string $ref Reference.
	 */
	protected function consume_source_ref( $ref ) {
		$ref = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $ref );

		if ( '' !== $ref ) {
			delete_transient( 'vvai_src_' . $ref );
		}
	}
}
