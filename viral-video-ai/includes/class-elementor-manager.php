<?php
/**
 * Elementor integration bootstrap.
 *
 * Loaded only when Elementor is actually present, and never before
 * `elementor/loaded` — extending \Elementor\Widget_Base too early is the classic
 * fatal error in widget plugins (spec §44).
 *
 * Without Elementor the plugin is fully functional through the
 * `[vvai_generator]` shortcode; only the widget is unavailable.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Elementor_Manager
 */
class VVAI_Elementor_Manager {

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
	 * Is Elementor installed and active?
	 *
	 * @return bool
	 */
	public static function available() {
		return did_action( 'elementor/loaded' ) > 0 || class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Register widget + category.
	 */
	public function register() {
		if ( ! self::available() ) {
			return;
		}

		// Elementor 3.5+ API, with the legacy hook kept for older installs.
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_widgets' ) );
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );

		// Editor assets: the widget reuses the same stylesheet so the preview
		// matches the published page.
		add_action( 'elementor/editor/after_enqueue_styles', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'elementor/preview/enqueue_styles', array( __CLASS__, 'enqueue_editor_assets' ) );
	}

	/**
	 * Styles used inside the Elementor editor canvas.
	 */
	public static function enqueue_editor_assets() {
		wp_enqueue_style( 'vvai-widget', VVAI_PLUGIN_URL . 'assets/css/vvai-frontend.css', array(), VVAI_VERSION );
	}

	/**
	 * Add our widget category.
	 *
	 * @param object $elements_manager Elementor elements manager.
	 */
	public function register_category( $elements_manager ) {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			'viral-video-ai',
			array(
				'title' => __( 'Viral Video AI', 'viral-video-ai' ),
				'icon'  => 'fa fa-magic',
			)
		);
	}

	/**
	 * Register the widget classes.
	 *
	 * @param object|null $manager Elementor widget manager (new API passes it).
	 */
	public function register_widgets( $manager = null ) {
		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;

		$file = VVAI_PLUGIN_DIR . 'Elementor/class-widget-generator.php';

		if ( ! is_file( $file ) ) {
			return;
		}

		require_once $file;

		if ( ! class_exists( 'VVAI_Widget_Generator' ) ) {
			return;
		}

		if ( is_object( $manager ) && method_exists( $manager, 'register' ) ) {
			$manager->register( new VVAI_Widget_Generator() );

			return;
		}

		// Elementor < 3.5.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
			$instance = \Elementor\Plugin::$instance;

			if ( isset( $instance->widgets_manager ) && method_exists( $instance->widgets_manager, 'register_widget_type' ) ) {
				$instance->widgets_manager->register_widget_type( new VVAI_Widget_Generator() );
			}
		}
	}
}
