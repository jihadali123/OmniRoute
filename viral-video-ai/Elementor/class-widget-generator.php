<?php
/**
 * Elementor widget: Viral Video AI generator.
 *
 * Extends the Elementor base classes only inside this file, which is required
 * after `elementor/loaded` — never at plugin boot (spec §44).
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * Class VVAI_Widget_Generator
 */
class VVAI_Widget_Generator extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'viral-video-ai';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Viral Video AI', 'viral-video-ai' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-play';
	}

	/**
	 * Categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'viral-video-ai', 'general' );
	}

	/**
	 * Keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'video', 'shorts', 'viral', 'ai', 'clips', 'ffmpeg', 'transcription', 'tiktok', 'reels' );
	}

	/**
	 * Styles the widget needs in the editor preview.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( 'vvai-widget' );
	}

	/**
	 * Scripts the widget needs on the frontend.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( 'vvai-widget' );
	}

	/**
	 * Register panel controls.
	 */
	protected function register_controls() {
		$settings_service = new VVAI_Settings();

		// ---------------- Content ----------------
		$this->start_controls_section(
			'vvai_content',
			array(
				'label' => __( 'Generator', 'viral-video-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'source_modes',
			array(
				'label'       => __( 'Allowed sources', 'viral-video-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => array(
					'upload' => __( 'File upload', 'viral-video-ai' ),
					'url'    => __( 'Direct video URL', 'viral-video-ai' ),
					'media'  => __( 'Media library', 'viral-video-ai' ),
				),
				'default'     => array( 'upload', 'url', 'media' ),
				'label_block' => true,
				'description' => __( 'Media library selection requires the WordPress media modal and an editor-level permission.', 'viral-video-ai' ),
			)
		);

		$focuses = array();

		foreach ( VVAI_Prompt_Builder::focuses() as $key => $focus ) {
			$focuses[ $key ] = $focus['label'];
		}

		$this->add_control(
			'target_clips',
			array(
				'label'      => __( 'Clips per video', 'viral-video-ai' ),
				'type'       => \Elementor\Controls_Manager::NUMBER,
				'min'        => 1,
				'max'        => max( 1, (int) $settings_service->get( 'max_clips' ) ),
				'default'    => min( 3, (int) $settings_service->get( 'max_clips' ) ),
				'tooltip'    => __( 'The server clamps this to the maximum configured in Viral Video AI → Settings.', 'viral-video-ai' ),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'   => __( 'Button label', 'viral-video-ai' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => __( 'Generate Clips', 'viral-video-ai' ),
			)
		);

		$this->add_control(
			'show_advanced',
			array(
				'label'   => __( 'Show advanced options', 'viral-video-ai' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => 'yes',
			)
		);

		$this->end_controls_section();

		// ---------------- Output ----------------
		$this->start_controls_section(
			'vvai_output',
			array(
				'label' => __( 'Output', 'viral-video-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'aspect_ratio',
			array(
				'label'   => __( 'Aspect ratio', 'viral-video-ai' ),
				'type'    => \Elementor\Controls_Manager::CHOOSE,
				'default' => $settings_service->get( 'default_aspect_ratio' ),
				'options' => array(
					'9:16' => array(
						'title' => __( 'Vertical', 'viral-video-ai' ),
						'icon'  => 'eicon-device-mobile',
					),
					'16:9' => array(
						'title' => __( 'Landscape', 'viral-video-ai' ),
						'icon'  => 'eicon-device-desktop',
					),
					'1:1'  => array(
						'title' => __( 'Square', 'viral-video-ai' ),
						'icon'  => 'eicon-square',
					),
					'4:5'  => array(
						'title' => __( 'Portrait', 'viral-video-ai' ),
						'icon'  => 'eicon-align-center-vertically',
					),
				),
				'toggle'  => false,
			)
		);

		$this->add_control(
			'quality',
			array(
				'label'   => __( 'Quality', 'viral-video-ai' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => $settings_service->get( 'default_quality' ),
				'options' => array(
					'720p'  => __( '720p', 'viral-video-ai' ),
					'1080p' => __( '1080p', 'viral-video-ai' ),
					'4k'    => __( '4K (only when the source supports it)', 'viral-video-ai' ),
				),
			)
		);

		$this->add_control(
			'connection_note',
			array(
				'label'       => __( 'AI connection', 'viral-video-ai' ),
			'type'        => \Elementor\Controls_Manager::RAW_HTML,
				'raw'           => __( 'Visitors use the connection selected in Viral Video AI \u2192 AI Connections. Each connected provider can also be chosen per job in the advanced panel.', 'viral-video-ai' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->end_controls_section();

		// ---------------- Style ----------------
		$this->start_controls_section(
			'vvai_style',
			array(
				'label' => __( 'Style', 'viral-video-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'theme',
			array(
				'label'   => __( 'Palette', 'viral-video-ai' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'dark',
				'options' => array(
					'dark'  => __( 'Dark', 'viral-video-ai' ),
					'light' => __( 'Light', 'viral-video-ai' ),
				),
			)
		);

		$this->add_responsive_control(
			'max_width',
			array(
				'label'      => __( 'Max width', 'viral-video-ai' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array( 'min' => 320, 'max' => 1600 ),
					'%'  => array( 'min' => 30, 'max' => 100 ),
				),
				'default'    => array( 'unit' => 'px', 'size' => 1180 ),
				'selectors'  => array( '{{WRAPPER}} .vvai-shell' => 'max-width: {{SIZE}}{{UNIT}};', '{{WRAPPER}} .vvai-app' => 'max-width: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'padding',
			array(
				'label'     => __( 'Padding', 'viral-video-ai' ),
				'type'      => \Elementor\Controls_Manager::DIMENSIONS,
				'selectors' => array( '{{WRAPPER}} .vvai-app' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'accent',
			array(
				'label'     => __( 'Accent colour', 'viral-video-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .vvai-app' => '--vvai-accent: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'surface',
			array(
				'label'     => __( 'Card background', 'viral-video-ai' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .vvai-app' => '--vvai-surface: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'radius',
			array(
				'label'      => __( 'Corner radius', 'viral-video-ai' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}} .vvai-app' => '--vvai-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$settings = is_array( $settings ) ? $settings : array();

		// Deliberately few values. Anything the panel no longer offers falls back
		// to the site defaults, so an editor cannot mis-configure a video.
		$defaults_service = new VVAI_Settings();

		$normalized = array(
			'elementor_mode'  => true,
			'aspect_ratio'    => (string) vvai_array_get( $settings, 'aspect_ratio', $defaults_service->get( 'default_aspect_ratio' ) ),
			'quality'         => (string) vvai_array_get( $settings, 'quality', $defaults_service->get( 'default_quality' ) ),
			'target_clips'    => (int) vvai_array_get( $settings, 'target_clips', 3 ),
			'button_text'     => (string) vvai_array_get( $settings, 'button_text', '' ),
			'show_advanced'   => 'yes' === (string) vvai_array_get( $settings, 'show_advanced', 'yes' ) ? 'yes' : 'no',
			'show_source'     => implode( ',', array_map( 'strval', (array) vvai_array_get( $settings, 'source_modes', array( 'upload', 'url' ) ) ) ),
			'theme'           => (string) vvai_array_get( $settings, 'theme', 'dark' ),
		);

		$frontend = new VVAI_Frontend( vvai() );

		echo $frontend->render( $normalized ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template output.
	}

	/**
	 * Editor preview: a static shell so the design is visible without processing.
	 */
	protected function content_template() {
		?>
		<div class="vvai-app vvai-app--preview">
			<div class="vvai-shell">
				<header class="vvai-head">
					<div class="vvai-head__title">
						<span class="vvai-badge">AI</span>
						<h3><?php echo esc_html__( 'Long video to viral clips', 'viral-video-ai' ); ?></h3>
						<p><?php echo esc_html__( 'Publish the page to let visitors upload a video and generate clips.', 'viral-video-ai' ); ?></p>
					</div>
				</header>
				<div class="vvai-grid">
					<div class="vvai-card"><div class="vvai-drop"><div class="vvai-drop__inner"><strong><?php echo esc_html__( 'Upload zone', 'viral-video-ai' ); ?></strong></div></div></div>
					<div class="vvai-card"><div class="vvai-field"><label><?php echo esc_html__( 'Clip length', 'viral-video-ai' ); ?></label></div></div>
					<div class="vvai-card"><div class="vvai-field"><label><?php echo esc_html__( 'Output', 'viral-video-ai' ); ?></label></div></div>
				</div>
			</div>
		</div>
		<?php
	}
}
