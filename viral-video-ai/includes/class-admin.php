<?php
/**
 * Admin UI: menus, pages, assets.
 *
 * The screens are thin: every mutation is performed through the same REST
 * endpoints the frontend uses, so the permission logic exists exactly once.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Admin
 */
class VVAI_Admin {

	/**
	 * @var VVAI_Plugin
	 */
	private $plugin;

	/**
	 * Current page hook suffixes.
	 *
	 * @var array<string,string>
	 */
	private $pages = array();

	/**
	 * Constructor.
	 *
	 * @param VVAI_Plugin $plugin Container.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook everything up.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );

		// The job actions live in the admin table; handled by VVAI_Ajax.
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
	}

	/**
	 * Register the menu tree.
	 */
	public function menu() {
		$capability = 'manage_options';

		$this->pages['dashboard'] = add_menu_page(
			__( 'Viral Video AI', 'viral-video-ai' ),
			__( 'Viral Video AI', 'viral-video-ai' ),
			$capability,
			'vvai',
			array( $this, 'page_dashboard' ),
			'dashicons-video-alt3',
			58
		);

		$this->pages['jobs']        = add_submenu_page( 'vvai', __( 'Jobs', 'viral-video-ai' ), __( 'Jobs', 'viral-video-ai' ), $capability, 'vvai-jobs', array( $this, 'page_jobs' ) );
		$this->pages['connections'] = add_submenu_page( 'vvai', __( 'AI Connections', 'viral-video-ai' ), __( 'AI Connections', 'viral-video-ai' ), $capability, 'vvai-connections', array( $this, 'page_connections' ) );
		$this->pages['settings']    = add_submenu_page( 'vvai', __( 'Settings', 'viral-video-ai' ), __( 'Settings', 'viral-video-ai' ), $capability, 'vvai-settings', array( $this, 'page_settings' ) );
		$this->pages['diagnostics'] = add_submenu_page( 'vvai', __( 'Diagnostics', 'viral-video-ai' ), __( 'Diagnostics', 'viral-video-ai' ), $capability, 'vvai-diagnostics', array( $this, 'page_diagnostics' ) );

		foreach ( $this->pages as $suffix => $hook ) {
			$this->pages[ $suffix ] = $hook;
		}
	}

	/**
	 * Is the current screen one of ours?
	 *
	 * @param string $page Optional page key.
	 * @return bool
	 */
	public function on( $page = '' ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || empty( $screen->id ) ) {
			return false;
		}

		if ( '' === $page ) {
			return in_array( (string) $screen->id, array_values( $this->pages ), true );
		}

		return isset( $this->pages[ $page ] ) && (string) $screen->id === $this->pages[ $page ];
	}

	/**
	 * Admin body class, used by the CSS for the dark accent.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		if ( $this->on() ) {
			$classes .= ' vvai-admin';
		}

		return $classes;
	}

	/**
	 * Enqueue admin assets only on our screens.
	 *
	 * @param string $hook Page hook.
	 */
	public function assets( $hook ) {
		if ( ! $this->on() ) {
			return;
		}

		wp_enqueue_style( 'vvai-admin', VVAI_PLUGIN_URL . 'admin/css/admin.css', array(), VVAI_VERSION );
		wp_enqueue_script( 'vvai-admin', VVAI_PLUGIN_URL . 'admin/js/admin.js', array(), VVAI_VERSION, true );

		wp_localize_script(
			'vvai-admin',
			'VVAIAdmin',
			array(
				'restUrl'  => esc_url_raw( rest_url( VVAI_REST_NAMESPACE ) ),
				'ajaxUrl'  => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'ajaxNonce' => wp_create_nonce( 'vvai_admin' ),
				'page'     => $this->current_page_key( $hook ),
				'i18n'     => array(
					'connecting'   => __( 'Connecting…', 'viral-video-ai' ),
					'connected'    => __( 'Connected', 'viral-video-ai' ),
					'failed'       => __( 'Connection failed', 'viral-video-ai' ),
					'disconnected' => __( 'Disconnected', 'viral-video-ai' ),
					'save'         => __( 'Save', 'viral-video-ai' ),
					'saved'        => __( 'Saved', 'viral-video-ai' ),
					'saving'       => __( 'Saving…', 'viral-video-ai' ),
					'confirmDelete' => __( 'Delete this connection and its stored API key? Jobs that used it keep their results.', 'viral-video-ai' ),
					'confirmJobDelete' => __( 'Delete this job and all of its rendered clips? This cannot be undone.', 'viral-video-ai' ),
					'reconnectRequired' => __( 'A key is required.', 'viral-video-ai' ),
					'copy'         => __( 'Copy', 'viral-video-ai' ),
					'copied'       => __( 'Copied', 'viral-video-ai' ),
					'networkError' => __( 'The site could not be reached. Check the console for details.', 'viral-video-ai' ),
					'processing'   => __( 'Working…', 'viral-video-ai' ),
					'setActive'    => __( 'Set as active', 'viral-video-ai' ),
					'active'       => __( 'Active', 'viral-video-ai' ),
					'none'         => __( 'None', 'viral-video-ai' ),
					'pollHint'     => __( 'Status updates automatically while a job is running.', 'viral-video-ai' ),
				),
				'config'   => array(
					'providers'    => $this->provider_options(),
					'settings'     => $this->plugin->settings()->all(),
					'connections'  => $this->plugin->connections()->list_public(),
					'focuses'      => VVAI_Prompt_Builder::focuses(),
					'active'       => (string) $this->plugin->settings()->get( 'active_connection_id' ),
					'fallback'     => (string) $this->plugin->settings()->get( 'fallback_connection_id' ),
					'diagnostics'  => $this->plugin->diagnostics()->summary(),
					'encryption'   => array(
						'ok'      => ( new VVAI_Crypto() )->is_available(),
						'message' => ( new VVAI_Crypto() )->status_message(),
					),
					'uploads'      => array(
						'maxBytes'  => (int) $this->plugin->settings()->max_upload_bytes(),
						'chunkSize' => (int) $this->plugin->settings()->get( 'upload_chunk_size' ),
					),
				),
			)
		);
	}

	/**
	 * Which page key is this hook?
	 *
	 * @param string $hook Hook suffix.
	 * @return string
	 */
	protected function current_page_key( $hook ) {
		$key = array_search( (string) $hook, $this->pages, true );

		return is_string( $key ) ? $key : str_replace( array( 'vvai-', 'vvai' ), array( '', 'dashboard' ), (string) $hook );
	}

	/**
	 * Provider select options.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function provider_options() {
		$out = array();

		foreach ( $this->plugin->providers()->catalogue() as $provider ) {
			$capabilities = (array) $provider['capabilities'];

			$out[] = array(
				'key'           => (string) $provider['key'],
				'label'         => (string) $provider['label'],
				'defaultModel'  => (string) $provider['defaultModel'],
				'models'        => (array) $provider['models'],
				'baseUrl'       => (string) $provider['baseUrl'],
				'json'          => (bool) $provider['json'],
				'transcription' => (string) $provider['transcription'],
				'docs'          => (string) vvai_array_get( $capabilities, 'docs', '' ),
				'notes'         => (string) vvai_array_get( $capabilities, 'notes', '' ),
				'prefix'        => (string) vvai_array_get( $capabilities, 'api_key_prefix', '' ),
				'requiresUrl'   => (bool) vvai_array_get( $capabilities, 'requires_base_url', false ),
			);
		}

		return $out;
	}

	/**
	 * One-time "activated" notice, plus setup guidance.
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$activation_error = get_option( 'vvai_activation_error', false );

		if ( is_array( $activation_error ) && $activation_error ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong></p><ul class="vvai-notice-list"><li>%s</li></ul><p>%s</p></div>',
				esc_html__( 'Viral Video AI could not finish installing its database tables.', 'viral-video-ai' ),
				esc_html( implode( ' | ', array_map( 'strval', $activation_error ) ) ),
				esc_html__( 'Deactivate and reactivate the plugin, or check that the database user may execute CREATE TABLE.', 'viral-video-ai' )
			);

			delete_option( 'vvai_activation_error' );

			return;
		}

		if ( get_transient( 'vvai_activated' ) ) {
			delete_transient( 'vvai_activated' );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s <a href="%s"><strong>%s</strong></a> · <a href="%s">%s</a></p></div>',
				esc_html__( 'Viral Video AI is installed.', 'viral-video-ai' ),
				esc_url( admin_url( 'admin.php?page=vvai-connections' ) ),
				esc_html__( 'Connect your AI provider to begin', 'viral-video-ai' ),
				esc_url( admin_url( 'admin.php?page=vvai-diagnostics' ) ),
				esc_html__( 'Check your server', 'viral-video-ai' )
			);
		}

		if ( ! $this->plugin->connections()->connected() && $this->on() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'No AI provider is connected yet — clip generation stays disabled until you add one in Viral Video AI → AI Connections.', 'viral-video-ai' )
			);
		}
	}

	/**
	 * Send the admin to the connections screen after activation.
	 */
	public function maybe_redirect_after_activation() {
		if ( ! get_transient( 'vvai_activated_redirect' ) ) {
			return;
		}

		delete_transient( 'vvai_activated_redirect' );

		if ( ! wp_safe_redirect( admin_url( 'admin.php?page=vvai-connections' ) ) ) {
			return;
		}

		exit;
	}

	/**
	 * Dashboard page.
	 */
	public function page_dashboard() {
		$report = $this->plugin->diagnostics()->report();

		$data = array(
			'stats'        => $this->plugin->jobs()->stats(),
			'summary'      => $this->plugin->diagnostics()->summary(),
			'active'       => $this->plugin->connections()->get_active( false ),
			'connections'  => $this->plugin->connections()->list_public(),
			'recent'       => $this->plugin->results()->recent_jobs( 8 ),
			'usage'        => $this->plugin->results()->storage_usage(),
			'report'       => $report,
			'plugin_url'   => VVAI_PLUGIN_URL,
			'admin_url'    => admin_url( 'admin.php' ),
			'transcription' => (string) $this->plugin->settings()->get( 'transcription_source' ),
		);

		$this->view( 'dashboard', $data );
	}


	/**
	 * Jobs list page.
	 */
	public function page_jobs() {
		$args = array(
			'per_page' => 25,
			'page'     => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1,
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at',
			'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
		);

		$page = $this->plugin->jobs()->query(
			array(
				'per_page' => $args['per_page'],
				'page'     => $args['page'],
				'status'   => $args['status'],
				'search'   => $args['search'],
				'order_by' => $args['orderby'],
				'order'    => $args['order'],
			)
		);

		$this->view(
			'jobs',
			array(
				'page'  => $page,
				'args'  => $args,
				'stats' => $this->plugin->jobs()->stats(),
				'jobs'  => array_map( array( VVAI_Job_Status::class, 'public_payload' ), (array) $page['items'] ),
				'rows'  => (array) $page['items'],
			)
		);
	}

	/**
	 * Connections page.
	 */
	public function page_connections() {
		$this->view(
			'connections',
			array(
				'connections' => $this->plugin->connections()->list_public(),
				'providers'    => $this->provider_options(),
				'active'        => (string) $this->plugin->settings()->get( 'active_connection_id' ),
				'fallback'      => (string) $this->plugin->settings()->get( 'fallback_connection_id' ),
				'allow_fallback' => (bool) $this->plugin->settings()->get( 'allow_fallback' ),
				'crypto'        => array(
					'ok'      => ( new VVAI_Crypto() )->is_available(),
					'message' => ( new VVAI_Crypto() )->status_message(),
				),
			)
		);
	}

	/**
	 * Settings page.
	 */
	public function page_settings() {
		$this->view(
			'settings',
			array(
				'settings' => $this->plugin->settings()->all(),
				'defaults' => VVAI_Settings::defaults(),
				'limits'   => array(
					'server_upload' => $this->plugin->settings()->server_upload_limit_bytes(),
					'effective'     => $this->plugin->settings()->max_upload_bytes(),
					'memory'        => (string) ini_get( 'memory_limit' ),
					'time_limit'    => (string) ini_get( 'max_execution_time' ),
				),
				'connections' => $this->plugin->connections()->list_public(),
				'ffmpeg'      => $this->plugin->ffmpeg()->availability( true ),
				'scheduler'   => array(
					'async'    => VVAI_Job_Queue::has_async_scheduler(),
					'heartbeat' => (int) ( wp_next_scheduled( VVAI_Job_Queue::HEARTBEAT ) ?: 0 ),
					'cleanup'   => (int) ( wp_next_scheduled( VVAI_Job_Queue::CLEANUP ) ?: 0 ),
					'rest'      => VVAI_Rest_Api::is_reachable(),
				),
			)
		);
	}

	/**
	 * Diagnostics page.
	 */
	public function page_diagnostics() {
		delete_transient( VVAI_FFMPEG::CACHE_AVAIL );

		$this->view(
			'diagnostics',
			array(
				'report' => $this->plugin->diagnostics()->report(),
				'log'      => array(
					'enabled' => (bool) $this->plugin->settings()->get( 'debug_log' ),
					'tail'    => $this->plugin->logger()->tail( 60 ),
					'file'    => $this->plugin->logger()->log_file(),
				),
				'usage'    => $this->plugin->results()->storage_usage(),
			)
		);
	}

	/**
	 * Include a view file with extracted data.
	 *
	 * @param string              $name View name (without .php).
	 * @param array<string,mixed> $data Variables.
	 */
	protected function view( $name, array $data ) {
		$path = VVAI_PLUGIN_DIR . 'admin/views/' . vvai_sanitize_filename( $name, 'dashboard' ) . '.php';

		if ( ! is_file( $path ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( 'Missing admin view: ' . $name ) );

			return;
		}

		extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- scoped view variables.

		include $path;
	}
}
