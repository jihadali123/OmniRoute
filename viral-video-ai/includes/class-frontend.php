<?php
/**
 * Frontend renderer shared by the Elementor widget and the shortcode.
 *
 * One implementation of markup, asset loading and state hydration means the
 * generator looks and behaves identically whether it was placed with Elementor
 * or with `[vvai_generator]` in any theme/block.
 *
 * Templates live in `templates/frontend/` and can be overridden per theme via
 * `vvai_template_part()`.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Frontend
 */
class VVAI_Frontend {

	/**
	 * @var VVAI_Plugin
	 */
	private $plugin;

	/**
	 * Whether the assets were printed for this request.
	 *
	 * @var bool
	 */
	private static $assets_done = false;

	/**
	 * Constructor.
	 *
	 * @param VVAI_Plugin $plugin Container.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Enqueue widget CSS/JS once.
	 *
	 * @param array<string,mixed> $settings Widget settings (used to skip JS when disabled).
	 */
	public function enqueue_assets( array $settings = array() ) {
		if ( ! isset( $settings['elementor_mode'] ) ) {
			wp_enqueue_style( 'vvai-widget' );
			wp_enqueue_script( 'vvai-widget' );
		}

		// The Media Library picker reuses core's own modal, but only when the
		// source list actually offers it and the visitor may upload files.
		$modes = array_map( 'trim', explode( ',', (string) vvai_array_get( $settings, 'show_source', 'upload,url,media' ) ) );

		if ( in_array( 'media', $modes, true ) && current_user_can( 'upload_files' ) ) {
			// Core's own guard makes repeat calls cheap; no need to introspect the
			// script stack here.
			wp_enqueue_media();
		}

		if ( self::$assets_done ) {
			return;
		}

		self::$assets_done = true;

		wp_localize_script( 'vvai-widget', 'VVAIConfig', $this->config( $settings ) );
	}

	/**
	 * Configuration handed to the browser.
	 *
	 * Only safe values: no keys, no paths, no provider internals.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<string,mixed>
	 */
	public function config( array $settings = array() ) {
		$settings_service = $this->plugin->settings();
		$router           = $this->plugin->router();
		$active           = $router->get_active_connection();

		$connections = array();

		foreach ( $this->plugin->connections()->connected() as $record ) {
			$connections[] = array(
				'id'     => (string) $record['id'],
				'title'  => (string) $record['title'],
				'active' => ( $active && (string) $active['id'] === (string) $record['id'] ),
			);
		}

		$aspect  = (string) vvai_array_get( $settings, 'aspect_ratio', $settings_service->get( 'default_aspect_ratio' ) );
		$quality = (string) vvai_array_get( $settings, 'quality', $settings_service->get( 'default_quality' ) );

		// Told before the visitor picks a file, so nobody uploads a feature film
		// into a site that cannot render a single frame of it.
		$readiness = array( 'ok' => true, 'code' => '', 'message' => '', 'hint' => '', 'steps' => array(), 'fixUrl' => '' );

		try {
			$readiness = (array) $this->plugin->diagnostics()->frontend_readiness();
		} catch ( \Throwable $throwable ) {
			// Readiness reporting must never break the widget; the job endpoint
			// validates the same things again.
		}

		$config = array(
			'restUrl'        => esc_url_raw( rest_url( VVAI_REST_NAMESPACE ) ),
			'ajaxUrl'        => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'version'        => VVAI_VERSION,
			'loggedIn'       => is_user_logged_in(),
			'canSubmit'      => VVAI_Permissions::can_create_job(),
			'requireLogin'   => (bool) $settings_service->get( 'require_login' ),
			'hasConnection'  => (bool) $active,
			'ready'          => ! empty( $readiness['ok'] ),
			'readyCode'      => (string) vvai_array_get( $readiness, 'code', '' ),
			'readyMessage'   => (string) vvai_array_get( $readiness, 'message', '' ),
			'readyHint'      => (string) vvai_array_get( $readiness, 'hint', '' ),
			'readySteps'     => array_values( array_map( 'strval', (array) vvai_array_get( $readiness, 'steps', array() ) ) ),
			'readyFixUrl'    => VVAI_Permissions::can_manage() ? (string) vvai_array_get( $readiness, 'fixUrl', '' ) : '',
			'connectionError' => $router->connection_problem( (string) vvai_array_get( $settings, 'connection_id', '' ) ),
			'connections'    => $connections,
			'autoStart'      => (bool) $settings_service->get( 'auto_start_job' ),
			'showStages'     => (bool) $settings_service->get( 'show_processing_stage' ),
			'maxUploadBytes' => (int) $settings_service->max_upload_bytes(),
			'chunkSize'      => (int) $settings_service->get( 'upload_chunk_size' ),
			'allowedExtensions' => array_values( (array) $this->plugin->uploads()->allowed_extensions() ),
			'maxClips'       => (int) $settings_service->get( 'max_clips' ),
			'defaults'       => array(
				'clipLength'  => (string) vvai_array_get( $settings, 'clip_length', $settings_service->get( 'default_clip_length' ) ),
				'focus'       => (string) vvai_array_get( $settings, 'focus', $settings_service->get( 'default_focus' ) ),
				'aspect'      => $aspect,
				'quality'     => $quality,
				'targetClips' => (int) vvai_array_get( $settings, 'target_clips', $settings_service->get( 'max_clips' ) ),
				'cropMode'    => (string) $settings_service->get( 'crop_mode' ),
				'burnCaptions' => (bool) $settings_service->get( 'burn_captions' ),
				'generateSrt' => (bool) $settings_service->get( 'generate_srt' ),
				'minDuration' => 30,
				'maxDuration' => 60,
			),
			'stages'         => VVAI_Job_Status::stage_labels(),
			'strings'        => $this->strings(),
			'presets'        => $this->presets(),
			'pollInterval'   => (int) apply_filters( 'vvai_frontend_poll_interval', 1500 ),
		);

		/**
		 * Filter the frontend bootstrap config.
		 *
		 * @param array $config Config.
		 * @param array $settings Widget settings.
		 */
		return apply_filters( 'vvai_frontend_config', $config, $settings );
	}

	/**
	 * Duration presets exposed to the UI (and validated again server-side).
	 *
	 * @return array<string,array<string,int|string>>
	 */
	public function presets() {
		$out = array();

		foreach ( array( 'short', 'medium', 'long' ) as $mode ) {
			list( $min, $max ) = VVAI_Settings::duration_range( $mode, 0, 0 );

			$out[ $mode ] = array(
				'min'     => (int) $min,
				'max'     => (int) $max,
				/* translators: %s: duration label. */
				'label'   => sprintf( __( '%s seconds', 'viral-video-ai' ), $min . '–' . $max ),
			);
		}

		return $out;
	}

	/**
	 * Translatable UI strings.
	 *
	 * @return array<string,string>
	 */
	protected function strings() {
		return array(
			'chooseVideo'      => __( 'Choose a video', 'viral-video-ai' ),
			'dropVideo'        => __( 'Drag a long video here, click to browse, or paste a direct video URL', 'viral-video-ai' ),
			'browsing'         => __( 'Selecting…', 'viral-video-ai' ),
			'uploading'        => __( 'Uploading', 'viral-video-ai' ),
			'verifying'        => __( 'Verifying the file on the server…', 'viral-video-ai' ),
			'processing'       => __( 'Processing', 'viral-video-ai' ),
			'done'             => __( 'Your clips are ready', 'viral-video-ai' ),
			'failed'           => __( 'Processing failed', 'viral-video-ai' ),
			'generate'         => __( 'Generate Clips', 'viral-video-ai' ),
			'generating'       => __( 'Working…', 'viral-video-ai' ),
			'retry'            => __( 'Retry', 'viral-video-ai' ),
			'cancel'           => __( 'Cancel', 'viral-video-ai' ),
			'download'         => __( 'Download', 'viral-video-ai' ),
			'downloadCaptions' => __( 'Captions (.srt)', 'viral-video-ai' ),
			'copy'             => __( 'Copy', 'viral-video-ai' ),
			'copied'           => __( 'Copied', 'viral-video-ai' ),
			'clip'             => __( 'Clip', 'viral-video-ai' ),
			'score'            => __( 'Viral score', 'viral-video-ai' ),
			'reasoning'        => __( 'Why this works', 'viral-video-ai' ),
			'title'            => __( 'Title', 'viral-video-ai' ),
			'caption'          => __( 'Caption', 'viral-video-ai' ),
			'hashtags'         => __( 'Hashtags', 'viral-video-ai' ),
			'range'            => __( 'From the source video', 'viral-video-ai' ),
			'noConnection'     => __( 'Please connect an AI provider before processing videos.', 'viral-video-ai' ),
			'loginRequired'    => __( 'Please log in to generate clips.', 'viral-video-ai' ),
			'uploadTooLarge'   => __( 'That file is larger than this site allows.', 'viral-video-ai' ),
			'uploadFailed'     => __( 'The upload failed. Please try again.', 'viral-video-ai' ),
			'pollFailed'       => __( 'Lost contact with the server — retrying…', 'viral-video-ai' ),
			'speed'           => __( 'Speed', 'viral-video-ai' ),
			'remaining'       => __( 'Remaining', 'viral-video-ai' ),
			'uploaded'        => __( 'Uploaded', 'viral-video-ai' ),
			'stage'           => __( 'Stage', 'viral-video-ai' ),
			'eta'             => __( 'Estimated time left', 'viral-video-ai' ),
			'sourceTooShort'  => __( 'This video is too short for the clip length you chose.', 'viral-video-ai' ),
			'jobError'        => __( 'The server rejected this job.', 'viral-video-ai' ),
			'networkError'    => __( 'A network problem interrupted the request.', 'viral-video-ai' ),
			'resumePrompt'    => __( 'A previous upload of this file was found — resuming.', 'viral-video-ai' ),
			'partial'         => __( 'Partially completed', 'viral-video-ai' ),
			'openJob'         => __( 'Job details', 'viral-video-ai' ),
			'ratioVertical'   => __( 'Vertical 9:16', 'viral-video-ai' ),
			'ratioHorizontal' => __( 'Horizontal 16:9', 'viral-video-ai' ),
		);
	}

	/**
	 * Render the generator UI.
	 *
	 * @param array<string,mixed> $settings Widget/shortcode settings.
	 * @return string Escaped HTML.
	 */
	public function render( array $settings = array() ) {
		$this->enqueue_assets( $settings );

		$config   = $this->config( $settings );
		$instance = 'vvai-' . substr( md5( wp_json_encode( array_keys( $settings ) ) . microtime( true ) ), 0, 8 );

		ob_start();

		vvai_get_template( 'generator.php', compact( 'config', 'settings', 'instance' ) );

		return (string) ob_get_clean();
	}
}

/**
 * Locate and include a template, allowing theme overrides.
 *
 * @param string              $template Template file name inside templates/frontend.
 * @param array<string,mixed> $vars     Variables exposed to the template.
 * @return bool Whether a template was loaded.
 */
function vvai_get_template( $template, array $vars = array() ) {
	$template = vvai_sanitize_filename( $template, 'generator.php' );

	$candidates = array();

	// Theme override: {theme}/viral-video-ai/{template}
	$theme_directory = get_stylesheet_directory();

	if ( is_string( $theme_directory ) && '' !== $theme_directory ) {
		$candidates[] = trailingslashit( $theme_directory ) . 'viral-video-ai/' . $template;
	}

	$candidates[] = VVAI_PLUGIN_DIR . 'templates/frontend/' . $template;
	$candidates[] = VVAI_PLUGIN_DIR . 'templates/' . $template;

	/**
	 * Filter the template lookup chain.
	 *
	 * @param array $candidates Absolute paths, first match wins.
	 * @param string $template    Requested template.
	 */
	$candidates = (array) apply_filters( 'vvai_template_candidates', $candidates, $template );

	foreach ( $candidates as $candidate ) {
		if ( is_file( $candidate ) ) {
			// The template contract: every key of $vars becomes a local variable.
			// Without this the included file sees an empty scope and renders an
			// empty shell (no data-config => no frontend behaviour at all).
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template variable hydration, values are escaped by the template itself.

			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- path resolved from the allowlist above.
			include $candidate;

			return true;
		}
	}

	return false;
}
