<?php
/**
 * admin-ajax endpoints for the backend screens.
 *
 * The admin UI talks to admin-ajax (like the rest of WordPress core UI) while
 * the public widget uses REST. Both layers call the same service methods, so
 * validation and permissions exist once — this class only adapts transport.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Ajax
 */
class VVAI_Ajax {

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
	 * Hook the actions.
	 */
	public function register() {
		$actions = array(
			'vvai_connection_save',
			'vvai_connection_connect',
			'vvai_connection_disconnect',
			'vvai_connection_delete',
			'vvai_connection_activate',
			'vvai_connection_models',
			'vvai_job_action',
			'vvai_settings_save',
			'vvai_diagnostics_recheck',
			'vvai_ffmpeg_engine',
			'vvai_cleanup_now',
			'vvai_log_clear',
			'vvai_source_upload',
			'vvai_chunk_upload',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'handle' ) );
		}
	}

	/**
	 * Single dispatcher: verify, then call `handle_{action}`.
	 */
	public function handle() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.

		$method = 'handle_' . str_replace( 'vvai_', '', $action );

		if ( ! method_exists( $this, $method ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'viral-video-ai' ) ), 400 );
		}

		// Every action here mutates server state.
		check_ajax_referer( 'vvai_admin', 'nonce' );

		if ( ! VVAI_Permissions::can_manage() && ! in_array( $action, array( 'vvai_source_upload', 'vvai_chunk_upload', 'vvai_job_action' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage Viral Video AI.', 'viral-video-ai' ) ), 403 );
		}

		try {
			$result = $this->{$method}();
		} catch ( \Exception $exception ) {
			$this->plugin->logger()->error(
				'AJAX handler failed',
				array(
					'action' => $action,
					'class'  => get_class( $exception ),
				)
			);

			$result = new WP_Error( 'vvai_handler_error', __( 'The request could not be completed. Check the plugin log.', 'viral-video-ai' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
					'data'    => (array) $result->get_error_data(),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Create or update a connection.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function handle_connection_save() {
		$store  = $this->plugin->connections();
		$params = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.

		$id = isset( $params['id'] ) ? $store->sanitize_id( $params['id'] ) : '';

		$record = array(
			'id'           => $id,
			'title'        => isset( $params['title'] ) ? $params['title'] : '',
			'provider'     => isset( $params['provider'] ) ? sanitize_key( $params['provider'] ) : 'openai',
			'secret_input' => isset( $params['api_key'] ) ? trim( (string) $params['api_key'] ) : '',
			'model'        => isset( $params['model'] ) ? $params['model'] : '',
			'base_url'     => isset( $params['base_url'] ) ? $params['base_url'] : '',
			'temperature'  => isset( $params['temperature'] ) ? $params['temperature'] : 0.4,
			'max_tokens'   => isset( $params['max_tokens'] ) ? $params['max_tokens'] : 4000,
			'timeout'      => isset( $params['timeout'] ) ? $params['timeout'] : 120,
			'smoke_test'   => ! empty( $params['smoke_test'] ),
			'note'         => isset( $params['note'] ) ? $params['note'] : '',
		);

		$saved = $store->save( $record );

		if ( empty( $saved['ok'] ) ) {
			return new WP_Error(
				(string) vvai_array_get( $saved, 'code', 'save_failed' ),
				(string) vvai_array_get( $saved, 'message', __( 'The connection could not be saved.', 'viral-video-ai' ) )
			);
		}

		$response = array(
			'connection' => $store->public_view( $saved['record'] ),
			'message'    => ! empty( $saved['created'] )
				? __( 'Connection saved.', 'viral-video-ai' )
				: __( 'Connection updated.', 'viral-video-ai' ),
			'connections' => $store->list_public(),
			'active'      => (string) $this->plugin->settings()->get( 'active_connection_id' ),
		);

		// Connect immediately when asked (default on create).
		$should_connect = empty( $id ) || ! empty( $params['connect'] );

		if ( $should_connect && '' !== $record['secret_input'] ) {
			$verified = $this->plugin->router()->connect( $saved['record']['id'] );

			$response['connected'] = (bool) $verified['ok'];
			$response['message']   = (string) $verified['message'];
			$response['hint']      = (string) vvai_array_get( $verified, 'hint', '' );
			$response['connection'] = (array) vvai_array_get( $verified, 'record', $response['connection'] );
			$response['connections'] = $store->list_public();

			if ( empty( $verified['ok'] ) ) {
				$response['failed'] = true;
			}
		}

		return $response;
	}

	/**
	 * Connect (real verification against the provider).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function handle_connection_connect() {
		$id = isset( $_POST['id'] ) ? $this->plugin->connections()->sanitize_id( wp_unslash( $_POST['id'] ) ) : '';

		if ( '' === $id ) {
			return new WP_Error( 'vvai_bad_id', __( 'Missing connection id.', 'viral-video-ai' ) );
		}

		$result = $this->plugin->router()->connect( $id );

		$out = array(
			'connected'   => (bool) $result['ok'],
			'message'     => (string) $result['message'],
			'hint'        => (string) vvai_array_get( $result, 'hint', '' ),
			'connection'  => (array) vvai_array_get( $result, 'record', array() ),
			'connections' => $this->plugin->connections()->list_public(),
			'status'      => (string) $result['code'],
		);

		if ( empty( $result['ok'] ) ) {
			$out['failed'] = true;
		}

		return $out;
	}

	/**
	 * Disconnect.
	 *
	 * @return array<string,mixed>
	 */
	protected function handle_connection_disconnect() {
		$id = isset( $_POST['id'] ) ? $this->plugin->connections()->sanitize_id( wp_unslash( $_POST['id'] ) ) : '';

		return array(
			'connection'  => $this->plugin->connections()->disconnect( $id ),
			'connections' => $this->plugin->connections()->list_public(),
			'message'     => __( 'Disconnected. This key will not be used for processing.', 'viral-video-ai' ),
		);
	}

	/**
	 * Delete a connection record.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function handle_connection_delete() {
		$id = isset( $_POST['id'] ) ? $this->plugin->connections()->sanitize_id( wp_unslash( $_POST['id'] ) ) : '';

		if ( '' === $id || ! $this->plugin->connections()->delete( $id ) ) {
			return new WP_Error( 'vvai_delete_failed', __( 'That connection could not be removed.', 'viral-video-ai' ) );
		}

		return array(
			'deleted'     => true,
			'connections' => $this->plugin->connections()->list_public(),
			'active'      => (string) $this->plugin->settings()->get( 'active_connection_id' ),
			'message'     => __( 'Connection removed, including its stored key.', 'viral-video-ai' ),
		);
	}

	/**
	 * Choose the active connection (or the fallback).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function handle_connection_activate() {
		$id       = isset( $_POST['id'] ) ? $this->plugin->connections()->sanitize_id( wp_unslash( $_POST['id'] ) ) : '';
		$slot     = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : 'active';
		$record   = $this->plugin->connections()->get( $id );

		if ( ! $record ) {
			return new WP_Error( 'vvai_connection_missing', __( 'Connection not found.', 'viral-video-ai' ) );
		}

		if ( VVAI_Connection_Store::STATUS_CONNECTED !== (string) $record['status'] ) {
			return new WP_Error( 'vvai_not_connected', __( 'Connect this provider before selecting it.', 'viral-video-ai' ) );
		}

		$settings = $this->plugin->settings()->all();

		if ( 'fallback' === $slot ) {
			$settings['fallback_connection_id'] = $id;
			$settings['allow_fallback']         = true;
		} else {
			$settings['active_connection_id'] = $id;
		}

		$this->plugin->settings()->set( 'active_connection_id', $settings['active_connection_id'] );

		if ( 'fallback' === $slot ) {
			$this->plugin->settings()->set( 'fallback_connection_id', $id );
			$this->plugin->settings()->set( 'allow_fallback', true );
		}

		return array(
			'slot'    => $slot,
			'id'      => $id,
			'message' => sprintf(
				/* translators: %s: connection title. */
				__( '%s selected.', 'viral-video-ai' ),
				(string) $record['title']
			),
			'connections' => $this->plugin->connections()->list_public(),
		);
	}

	/**
	 * Fetch the provider's model list for the Advanced panel.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function handle_connection_models() {
		$id     = isset( $_POST['id'] ) ? $this->plugin->connections()->sanitize_id( wp_unslash( $_POST['id'] ) ) : '';
		$models = $this->plugin->router()->list_models( $id );

		if ( empty( $models['ok'] ) ) {
			return new WP_Error( 'vvai_models_unavailable', (string) $models['message'] );
		}

		return array(
			'models' => array_slice( (array) $models['models'], 0, 200 ),
		);
	}

	/**
	 * Job actions from the admin table: retry / cancel / delete / start.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function handle_job_action() {
		$id     = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$action = isset( $_POST['job_action'] ) ? sanitize_key( wp_unslash( $_POST['job_action'] ) ) : '';
		$jobs   = $this->plugin->jobs();
		$job    = $jobs->get( $id );

		if ( ! $job ) {
			return new WP_Error( 'vvai_job_missing', __( 'Job not found.', 'viral-video-ai' ) );
		}

		if ( ! VVAI_Permissions::can_modify_job( $job ) ) {
			return new WP_Error( 'vvai_forbidden', __( 'You cannot modify this job.', 'viral-video-ai' ) );
		}

		switch ( $action ) {
			case 'retry':
				$from  = isset( $_POST['from'] ) ? sanitize_key( wp_unslash( $_POST['from'] ) ) : '';
				$retry = $jobs->prepare_retry( $id, $from );

				if ( empty( $retry['ok'] ) ) {
					return new WP_Error( 'vvai_retry_blocked', (string) $retry['message'] );
				}

				$this->plugin->queue()->dispatch( $id, 1 );

				return array(
					'message' => (string) $retry['message'],
					'job'     => VVAI_Job_Status::public_payload( (array) $jobs->get( $id ) ),
				);

			case 'cancel':
				$jobs->update(
					$id,
					array(
						'status'        => VVAI_Job_Status::CANCELLED,
						'error_message' => __( 'Cancelled by an administrator.', 'viral-video-ai' ),
						'error_code'    => 'cancelled',
						'finished_at'   => gmdate( 'Y-m-d H:i:s' ),
					)
				);

				$jobs->release( $id );

				return array(
					'message' => __( 'Job cancelled.', 'viral-video-ai' ),
					'job'     => VVAI_Job_Status::public_payload( (array) $jobs->get( $id ) ),
				);

			case 'delete':
				$removed = $this->plugin->results()->delete_job_files( $id );
				$jobs->delete( $id );

				return array(
					'deleted' => true,
					'files'   => (int) $removed['files'],
					'message' => sprintf(
						/* translators: %s: size freed. */
						__( 'Job deleted (%s freed).', 'viral-video-ai' ),
						vvai_human_size( (int) $removed['bytes'] )
					),
				);

			case 'start':
				$problem = $this->plugin->router()->connection_problem( (string) vvai_array_get( (array) $job['settings_array'], 'connection_id', '' ) );

				if ( '' !== $problem ) {
					return new WP_Error( 'vvai_no_connection', $problem );
				}

				$this->plugin->queue()->dispatch( $id, 1 );

				return array( 'message' => __( 'Processing queued.', 'viral-video-ai' ) );
		}

		return new WP_Error( 'vvai_bad_action', __( 'Unsupported job action.', 'viral-video-ai' ) );
	}

	/**
	 * Save settings from the admin form.
	 *
	 * @return array<string,mixed>
	 */
	protected function handle_settings_save() {
		$params = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by Settings::sanitize().
		$stored = $this->plugin->settings()->all();
		$input  = isset( $params['vvai'] ) && is_array( $params['vvai'] ) ? $params['vvai'] : array();

		// A blank key field keeps the stored secret instead of wiping it.
		if ( ! isset( $input['transcription_api_key'] ) || '' === trim( (string) $input['transcription_api_key'] ) ) {
			$input['transcription_api_key'] = (string) $stored['transcription_api_key'];
		}

		$clean = $this->plugin->settings()->sanitize( array_merge( $stored, $input ) );

		update_option( VVAI_Settings::OPTION_KEY, $clean, 'yes' );

		// A corrected FFmpeg path has to take effect immediately — otherwise the
		// site owner fixes the setting, retries, and sees the same stale failure
		// for up to five minutes (the availability cache).
		$engine_keys = array( 'ffmpeg_path', 'ffprobe_path', 'ffmpeg_dir', 'auto_discover_binaries' );

		foreach ( $engine_keys as $key ) {
			if ( array_key_exists( $key, $input ) && (string) ( $input[ $key ] ?? '' ) !== (string) ( $stored[ $key ] ?? '' ) ) {
				VVAI_Settings::flush_engine_caches();

				break;
			}
		}

		return array(
			'message'  => __( 'Settings saved.', 'viral-video-ai' ),
			'settings' => $clean,
		);
	}

	/**
	 * Re-run diagnostics (and refresh the FFmpeg cache).
	 *
	 * @return array<string,mixed>
	 */
	protected function handle_diagnostics_recheck() {
		delete_transient( VVAI_FFMPEG::CACHE_AVAIL );
		delete_transient( 'vvai_loopback_check' );
		delete_transient( 'vvai_rest_reachable' );

		// Grants exactly one uncached probe (consumed by availability()).
		set_transient( 'vvai_force_probe', 1, 60 );

		return array( 'report' => $this->plugin->diagnostics()->report() );
	}

	/**
	 * FFmpeg engine panel: status, search this server, or apply a folder.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function handle_ffmpeg_engine() {
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'status'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.
		$dir  = isset( $_POST['dir'] ) ? (string) wp_unslash( $_POST['dir'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the dispatcher.

		if ( ! in_array( $mode, array( 'status', 'search', 'apply' ), true ) ) {
			$mode = 'status';
		}

		$result = $this->plugin->diagnostics()->ffmpeg_engine( $mode, $dir );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['report'] = $this->plugin->diagnostics()->report();

		return $result;
	}

	/**
	 * Run retention/cleanup immediately.
	 *
	 * @return array<string,mixed>
	 */
	protected function handle_cleanup_now() {
		$report = $this->plugin->queue()->cleanup();

		return array(
			'report'  => $report,
			'message' => sprintf(
				/* translators: %s: size freed. */
				__( 'Cleanup finished — %s freed.', 'viral-video-ai' ),
				vvai_human_size( (int) vvai_array_get( $report, 'bytes', 0 ) )
			),
		);
	}

	/**
	 * Empty the debug log.
	 *
	 * @return array<string,mixed>
	 */
	protected function handle_log_clear() {
		$this->plugin->logger()->clear();

		return array(
			'message' => __( 'Debug log cleared.', 'viral-video-ai' ),
			'tail'    => $this->plugin->logger()->tail( 30 ),
		);
	}

	/**
	 * One-shot whole-file upload for small videos (admin form convenience).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function handle_source_upload() {
		if ( empty( $_FILES['video'] ) ) {
			return new WP_Error( 'vvai_no_file', __( 'No file was received. Check upload_max_filesize on this server.', 'viral-video-ai' ) );
		}

		$file = $_FILES['video']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPressVIPMinimum.Security.ProperEscapingFunction -- validated below.

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'vvai_php_upload_error', $this->php_upload_error( (int) $file['error'] ) );
		}

		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'vvai_bad_upload', __( 'The upload did not arrive correctly.', 'viral-video-ai' ) );
		}

		$too_big = $this->plugin->settings()->check_upload_size( (int) $file['size'] );

		if ( is_wp_error( $too_big ) ) {
			return $too_big;
		}

		// Route the whole file through the chunking engine as a single chunk so
		// validation, storage layout and reference handling stay identical.
		$name = (string) $file['name'];

		$session = $this->plugin->uploads()->init_session(
			get_current_user_id(),
			array(
				'name'  => $name,
				'size'  => (int) $file['size'],
				'chunk' => max( 1, (int) $file['size'] ),
			)
		);

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		// The chunk is already on disk in the PHP temp dir; copy it in place.
		$stored = $this->plugin->uploads()->store_chunk( (string) $session['handle'], 0, (string) $file['tmp_name'] );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$final = $this->plugin->uploads()->finalize( (string) $session['handle'], get_current_user_id() );

		if ( is_wp_error( $final ) ) {
			return $final;
		}

		$rest = new VVAI_Rest_Api( $this->plugin );

		return array(
			'name'      => (string) $final['name'],
			'size'      => (int) $final['size'],
			'sizeLabel' => vvai_human_size( (int) $final['size'] ),
			'sourceRef' => $rest->issue_source_ref( (array) $final ),
			'message'   => __( 'Video received.', 'viral-video-ai' ),
		);
	}


	/**
	 * Chunked upload for the admin UI (same REST logic, admin transport).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function handle_chunk_upload() {
		$handle = isset( $_POST['handle'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', wp_unslash( $_POST['handle'] ) ) : '';
		$index  = isset( $_POST['chunk_index'] ) ? absint( wp_unslash( $_POST['chunk_index'] ) ) : 0;

		if ( '' === $handle || empty( $_FILES['chunk'] ) ) {
			return new WP_Error( 'vvai_bad_chunk', __( 'Missing upload handle or chunk.', 'viral-video-ai' ) );
		}

		$file = $_FILES['chunk']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- validated below.

		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'vvai_bad_chunk', __( 'The chunk did not arrive correctly.', 'viral-video-ai' ) );
		}

		$result = $this->plugin->uploads()->store_chunk( $handle, $index, (string) $file['tmp_name'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['complete'] ) && ! empty( $_POST['finalize'] ) ) {
			$final = $this->plugin->uploads()->finalize( $handle, get_current_user_id() );

			if ( is_wp_error( $final ) ) {
				return $final;
			}

			$rest = new VVAI_Rest_Api( $this->plugin );

			$result['finalized'] = array(
				'name'      => (string) $final['name'],
				'size'      => (int) $final['size'],
				'sourceRef' => $rest->issue_source_ref( (array) $final ),
			);
		}

		return $result;
	}

	/**
	 * Translate a PHP upload error code.
	 *
	 * @param int $code Code.
	 * @return string
	 */
	protected function php_upload_error( $code ) {
		switch ( (int) $code ) {
			case 1:
				return __( 'The file is larger than this server allows (upload_max_filesize in php.ini).', 'viral-video-ai' );
			case 2:
				return __( 'The file is larger than the HTML form limit (post_max_size in php.ini).', 'viral-video-ai' );
			case 3:
				return __( 'The upload was interrupted part-way. Please try again.', 'viral-video-ai' );
			case 4:
				return __( 'No file was uploaded.', 'viral-video-ai' );
			case 6:
				return __( 'The server has no temporary folder configured for uploads.', 'viral-video-ai' );
			case 7:
				return __( 'Writing the upload to disk failed (disk full or permissions).', 'viral-video-ai' );
			case 8:
				return __( 'A PHP extension blocked the upload.', 'viral-video-ai' );
		}

		return __( 'The upload failed with an unknown error.', 'viral-video-ai' );
	}
}
