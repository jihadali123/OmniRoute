<?php
/**
 * Plugin container: builds the service graph and wires the WordPress hooks.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Plugin
 */
final class VVAI_Plugin {

	/**
	 * Singleton.
	 *
	 * @var VVAI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service container.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Whether the front-end hooks were already registered.
	 *
	 * @var bool
	 */
	private $front_end_ready = false;

	/**
	 * Get the singleton.
	 *
	 * @return VVAI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {}

	/**
	 * Build core services and attach hooks.
	 */
	private function boot() {
		load_plugin_textdomain( 'viral-video-ai', false, dirname( VVAI_PLUGIN_BASENAME ) . '/languages' );

		// Core services. Construction order matters only for the container.
		$this->services['settings']      = new VVAI_Settings();
		$this->services['logger']        = new VVAI_Logger( $this->services['settings'] );
		$this->services['crypto']        = new VVAI_Crypto();
		$this->services['jobs']          = new VVAI_Job_Manager();
		$this->services['clips']         = new VVAI_Clip_Repository();
		$this->services['connections']   = new VVAI_Connection_Store( $this->services['crypto'] );
		$this->services['api']           = new VVAI_Api_Connection( $this->services['settings'], $this->services['logger'] );
		$this->services['providers']     = new VVAI_Api_Manager( $this->services['api'], $this->services['settings'], $this->services['logger'] );
		$this->services['router']        = new VVAI_AI_Router( $this->services['connections'], $this->services['providers'], $this->services['settings'], $this->services['logger'] );
		$this->services['ffmpeg']        = new VVAI_FFMPEG( $this->services['settings'] );
		$this->services['uploads']       = new VVAI_Upload_Handler( $this->services['settings'] );
		$this->services['transcription'] = new VVAI_Transcription( $this->services['api'], $this->services['ffmpeg'], $this->services['settings'], $this->services['connections'], $this->services['logger'] );
		$this->services['analyzer']      = new VVAI_AI_Analyzer( $this->services['router'], $this->services['settings'], $this->services['logger'] );
		$this->services['clip_generator'] = new VVAI_Clip_Generator( $this->services['ffmpeg'], $this->services['settings'], $this->services['clips'] );
		$this->services['processor']     = new VVAI_Video_Processor( $this );
		$this->services['queue']         = new VVAI_Job_Queue( $this->services['jobs'], $this->services['processor'] );
		$this->services['results']       = new VVAI_Result_Manager( $this->services['jobs'], $this->services['clips'], $this->services['settings'] );
		$this->services['diagnostics']   = new VVAI_Diagnostics( $this->services['settings'], $this->services['ffmpeg'], $this->services['connections'] );
		$this->services['rest']          = new VVAI_Rest_Api( $this );
		$this->services['ajax']          = new VVAI_Ajax( $this );
		$this->services['shortcode']     = new VVAI_Shortcode( $this );

		$this->services['settings']->register();
		$this->services['rest']->register();
		$this->services['ajax']->register();
		$this->services['queue']->register();
		$this->services['shortcode']->register();

		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->services['admin'] = new VVAI_Admin( $this );
			$this->services['admin']->register();
		}

		add_action( 'init', array( $this, 'on_init' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_front_end_assets' ) );
		add_action( 'wp_ajax_vvai_widget_poll', array( $this, 'ajax_widget_poll' ) );
		add_action( 'wp_ajax_nopriv_vvai_widget_poll', array( $this, 'ajax_widget_poll' ) );

		/**
		 * Fires once every Viral Video AI service is constructed and available.
		 *
		 * @param VVAI_Plugin $plugin Container.
		 */
		do_action( 'vvai_loaded', $this );
	}

	/**
	 * Retrieve a service from the container.
	 *
	 * @param string $key Service key.
	 * @return object|null
	 */
	public function get( $key ) {
		return isset( $this->services[ $key ] ) ? $this->services[ $key ] : null;
	}

	/**
	 * Magic accessors keep call sites readable: `$plugin->jobs`, `$plugin->settings`…
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( $name ) {
		return $this->get( $name );
	}

	/**
	 * Settings service.
	 *
	 * @return VVAI_Settings
	 */
	public function settings() {
		return $this->services['settings'];
	}

	/**
	 * Logger service.
	 *
	 * @return VVAI_Logger
	 */
	public function logger() {
		return $this->services['logger'];
	}

	/**
	 * Job repository.
	 *
	 * @return VVAI_Job_Manager
	 */
	public function jobs() {
		return $this->services['jobs'];
	}

	/**
	 * Clip repository.
	 *
	 * @return VVAI_Clip_Repository
	 */
	public function clips() {
		return $this->services['clips'];
	}

	/**
	 * Connection store.
	 */
	public function connections() {
		return $this->services['connections'];
	}

	/**
	 * Provider registry.
	 *
	 * @return VVAI_Api_Manager
	 */
	public function providers() {
		return $this->services['providers'];
	}

	/**
	 * AI router.
	 *
	 * @return VVAI_AI_Router
	 */
	public function router() {
		return $this->services['router'];
	}

	/**
	 * FFmpeg gateway.
	 *
	 * @return VVAI_FFMPEG
	 */
	public function ffmpeg() {
		return $this->services['ffmpeg'];
	}

	/**
	 * Upload handler.
	 *
	 * @return VVAI_Upload_Handler
	 */
	public function uploads() {
		return $this->services['uploads'];
	}

	/**
	 * Transcription engine.
	 *
	 * @return VVAI_Transcription
	 */
	public function transcription() {
		return $this->services['transcription'];
	}

	/**
	 * AI analyzer.
	 *
	 * @return VVAI_AI_Analyzer
	 */
	public function analyzer() {
		return $this->services['analyzer'];
	}

	/**
	 * Clip generator.
	 *
	 * @return VVAI_Clip_Generator
	 */
	public function clip_generator() {
		return $this->services['clip_generator'];
	}

	/**
	 * Pipeline processor.
	 *
	 * @return VVAI_Video_Processor
	 */
	public function processor() {
		return $this->services['processor'];
	}

	/**
	 * Background queue.
	 *
	 * @return VVAI_Job_Queue
	 */
	public function queue() {
		return $this->services['queue'];
	}

	/**
	 * Results/download manager.
	 *
	 * @return VVAI_Result_Manager
	 */
	public function results() {
		return $this->services['results'];
	}

	/**
	 * Diagnostics collector.
	 *
	 * @return VVAI_Diagnostics
	 */
	public function diagnostics() {
		return $this->services['diagnostics'];
	}

	/**
	 * Wire the Elementor widget and the frontend once all plugins are loaded.
	 */
	public function on_init() {
		// Elementor integration: only touch Elementor when it is actually loaded.
		if ( did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' ) ) {
			$elementor = new VVAI_Elementor_Manager( $this );
			$elementor->register();
			$this->services['elementor'] = $elementor;
		}

		$this->front_end_ready = true;

		/**
		 * Frontend is ready; templates may now render.
		 */
		do_action( 'vvai/frontend_ready' );
	}

	/**
	 * Register (but do not force-enqueue) the frontend assets.
	 *
	 * The widget and the shortcode enqueue these explicitly so sites without a
	 * generator UI pay nothing.
	 */
	public function register_front_end_assets() {
		wp_register_style(
			'vvai-widget',
			VVAI_PLUGIN_URL . 'assets/css/vvai-frontend.css',
			array(),
			VVAI_VERSION
		);

		// One script does upload + polling + results; it has no dependencies so it
		// also works on themes that dequeue jQuery.
		wp_register_script(
			'vvai-widget',
			VVAI_PLUGIN_URL . 'assets/js/vvai-frontend.js',
			array(),
			VVAI_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		$rest_available = VVAI_Rest_Api::is_reachable();

		$config = array(
			'restUrl'        => $rest_available ? untrailingslashit( esc_url_raw( rest_url( VVAI_REST_NAMESPACE ) ) ) : '',
			'ajaxUrl'        => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'ajaxNonce'      => wp_create_nonce( 'vvai_widget' ),
			'chunkSize'      => $this->settings()->get( 'upload_chunk_size' ),
			'maxUploadBytes' => $this->settings()->max_upload_bytes(),
			'restAvailable'  => $rest_available,
			'loggedIn'       => is_user_logged_in(),
			'canSubmit'      => VVAI_Permissions::can_create_job(),
			'i18n'           => $this->frontend_strings(),
			'stages'         => VVAI_Job_Status::stage_labels(),
			'hasConnection'  => (bool) $this->router()->get_active_connection(),
		);

		/**
		 * Filter the configuration handed to the frontend JavaScript.
		 *
		 * Implementations must not add secrets: this array is printed inside a
		 * page. The API keys live server-side only and are never exported.
		 *
		 * @param array $config Config.
		 */
		$config = apply_filters( 'vvai_frontend_config', $config );

		wp_add_inline_script(
			'vvai-widget',
			'window.VVAIConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Translatable strings for the frontend.
	 *
	 * @return array<string,string>
	 */
	private function frontend_strings() {
		return array(
			/* translators: shown while a file is being uploaded. */
			'uploading'        => __( 'Uploading…', 'viral-video-ai' ),
			'paused'           => __( 'Upload paused', 'viral-video-ai' ),
			'processing'       => __( 'Processing…', 'viral-video-ai' ),
			'completed'        => __( 'Completed', 'viral-video-ai' ),
			'failed'           => __( 'Processing failed', 'viral-video-ai' ),
			'starting'         => __( 'Starting…', 'viral-video-ai' ),
			'currentStage'     => __( 'Current stage', 'viral-video-ai' ),
			'download'         => __( 'Download', 'viral-video-ai' ),
			'copy'             => __( 'Copy', 'viral-video-ai' ),
			'copied'           => __( 'Copied', 'viral-video-ai' ),
			'networkError'     => __( 'Connection to the site was lost. Retrying…', 'viral-video-ai' ),
			'noConnection'     => __( 'Please connect an AI provider before processing videos.', 'viral-video-ai' ),
			'notLoggedIn'      => __( 'Please log in to generate clips.', 'viral-video-ai' ),
			'unsupportedFile'  => __( 'Unsupported file type.', 'viral-video-ai' ),
			'tooLarge'         => __( 'The file is larger than the configured upload limit.', 'viral-video-ai' ),
			'cancel'           => __( 'Cancel', 'viral-video-ai' ),
			'retry'            => __( 'Retry', 'viral-video-ai' ),
			'seconds'          => __( 's', 'viral-video-ai' ),
			'clipsFound'       => __( 'clips generated', 'viral-video-ai' ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- no i18n needed.
			'speed'            => __( 'Speed', 'viral-video-ai' ),
		);
	}

	/**
	 * Lightweight polling endpoint for the widget.
	 *
	 * The REST controller owns the full API; this admin-ajax twin exists so the
	 * widget keeps working on sites where REST routes are blocked by a WAF.
	 */
	public function ajax_widget_poll() {
		$nonce = isset( $_REQUEST['_vvai_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_vvai_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'vvai_widget' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'viral-video-ai' ) ), 403 );
		}

		$job_id = isset( $_REQUEST['job'] ) ? absint( $_REQUEST['job'] ) : 0;

		if ( ! $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing job id.', 'viral-video-ai' ) ), 400 );
		}

		$job = $this->jobs()->get( $job_id );

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Job not found.', 'viral-video-ai' ) ), 404 );
		}

		if ( ! VVAI_Permissions::can_read_job( $job ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to view this job.', 'viral-video-ai' ) ), 403 );
		}

		// Status payload only — never file paths, never provider credentials.
		$payload = VVAI_Job_Status::public_payload( $job );

		if ( in_array( $job['status'], array( 'completed', 'failed' ), true ) ) {
			$payload['clips'] = $this->results()->get_clip_payloads( $job_id );
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Admin screens service (only present on admin requests).
	 *
	 * @return VVAI_Admin|null
	 */
	public function admin() {
		return $this->get( 'admin' );
	}

	/**
	 * Elementor integration service (only present when Elementor is loaded).
	 *
	 * @return VVAI_Elementor_Manager|null
	 */
	public function elementor() {
		return $this->get( 'elementor' );
	}

	/**
	 * Whether the frontend asset/UI layer may render.
	 *
	 * @return bool
	 */
	public function is_front_end_ready() {
		return $this->front_end_ready;
	}
	/**
	 * ajax service.
	 *
	 * @return object
	 */
	public function ajax() {
		return $this->get( 'ajax' );
	}

	/**
	 * rest service.
	 *
	 * @return object
	 */
	public function rest() {
		return $this->get( 'rest' );
	}


}
