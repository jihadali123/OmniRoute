<?php
/**
 * Shortcodes.
 *
 * `[vvai_generator]` renders the same UI as the Elementor widget, so the plugin
 * is fully usable on sites that never install Elementor (spec §44).
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Shortcode
 */
class VVAI_Shortcode {

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
	 * Register the shortcodes.
	 */
	public function register() {
		add_shortcode( 'vvai_generator', array( $this, 'render_generator' ) );
		add_shortcode( 'viral_video_ai', array( $this, 'render_generator' ) );
		add_shortcode( 'vvai_my_clips', array( $this, 'render_library' ) );
	}

	/**
	 * [vvai_generator clip_length="short" focus="viral" aspect_ratio="9:16"
	 *  quality="1080p" target_clips="5" title="…" show_source="upload,url,media"]
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_generator( $atts = array() ) {
		$atts = is_array( $atts ) ? $atts : array();

		if ( ! $this->plugin->is_front_end_ready() ) {
			return $this->notice( __( 'Viral Video AI is still loading. Please refresh the page.', 'viral-video-ai' ), 'warning' );
		}

		$problem = $this->plugin->diagnostics()->preflight();

		if ( empty( $problem['ok'] ) && ! VVAI_Permissions::can_manage() ) {
			return $this->notice( (string) $problem['message'], 'error' );
		}

		$settings = $this->sanitize_atts(
			$atts,
			array(
				'clip_length',
				'focus',
				'aspect_ratio',
				'quality',
				'target_clips',
				'custom_focus',
				'title',
				'show_source',
				'show_advanced',
				'button_text',
				'connection_id',
			)
		);

		$frontend = new VVAI_Frontend( $this->plugin );

		$html = $frontend->render( $settings );

		if ( ! VVAI_Permissions::can_manage() ) {
			$connection_problem = $this->plugin->router()->connection_problem( (string) vvai_array_get( $settings, 'connection_id', '' ) );

			if ( '' !== $connection_problem ) {
				$html .= $this->notice( $connection_problem, 'warning' );
			}
		}

		/**
		 * Filter the generator markup.
		 *
		 * @param string $html     Markup.
		 * @param array  $settings Settings.
		 */
		return (string) apply_filters( 'vvai_generator_html', $html, $settings );
	}

	/**
	 * [vvai_my_clips] — the user's own finished clips.
	 *
	 * @param array<string,string>|string $atts Attributes.
	 * @return string
	 */
	public function render_library( $atts = array() ) {
		$atts = is_array( $atts ) ? $atts : array();

		if ( ! is_user_logged_in() ) {
			return $this->notice( __( 'Log in to see the clips you generated.', 'viral-video-ai' ), 'info' );
		}

		$per_page = isset( $atts['count'] ) ? vvai_sanitize_int( $atts['count'], 1, 48, 12 ) : 12;
		$results  = $this->plugin->results();
		$jobs     = $this->plugin->jobs()->query(
			array(
				'author_id' => get_current_user_id(),
				'status'    => VVAI_Job_Status::COMPLETED,
				'per_page'  => max( 1, min( 12, $per_page ) ),
				'order_by'  => 'created_at',
				'order'     => 'desc',
			)
		);

		$clips = array();

		foreach ( (array) $jobs['items'] as $job ) {
			foreach ( $results->clip_payloads( (int) $job['id'] ) as $clip ) {
				$clip['jobTitle'] = (string) $job['title'];
				$clips[]          = $clip;

				if ( count( $clips ) >= $per_page ) {
					break 2;
				}
			}
		}

		$frontend = new VVAI_Frontend( $this->plugin );
		$frontend->enqueue_assets();

		ob_start();
		vvai_get_template(
			'library.php',
			array(
				'clips'  => $clips,
				'config' => $frontend->config( array() ),
			)
		);
		$html = (string) ob_get_clean();

		if ( ! $clips ) {
			$html = $this->notice( __( 'No finished clips yet — generate some first.', 'viral-video-ai' ), 'info' );
		}

		return $html;
	}

	/**
	 * Sanitize shortcode attributes against the allowed vocabulary.
	 *
	 * @param array<string,mixed> $atts    Raw attributes.
	 * @param string[]            $allowed Allowed keys.
	 * @return array<string,mixed>
	 */
	protected function sanitize_atts( array $atts, array $allowed ) {
		$out = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $atts ) ) {
				continue;
			}

			$value = (string) $atts[ $key ];

			switch ( $key ) {
				case 'clip_length':
					$out[ $key ] = in_array( $value, array( 'short', 'medium', 'long', 'custom' ), true ) ? $value : 'short';
					break;
				case 'focus':
					$out[ $key ] = isset( VVAI_Prompt_Builder::focuses()[ $value ] ) ? $value : 'viral';
					break;
				case 'aspect_ratio':
					$out[ $key ] = in_array( $value, array( '9:16', '16:9', '1:1', '4:5' ), true ) ? $value : '9:16';
					break;
				case 'quality':
					$out[ $key ] = in_array( strtolower( $value ), array( '720p', '1080p', '4k' ), true ) ? strtolower( $value ) : '1080p';
					break;
				case 'target_clips':
					$out[ $key ] = vvai_sanitize_int( $value, 1, (int) $this->plugin->settings()->get( 'max_clips' ), 5 );
					break;
				case 'show_advanced':
				case 'show_source':
					$out[ $key ] = $value;
					break;
				default:
					$out[ $key ] = vvai_sanitize_text( $value, 120 );
			}
		}

		return $out;
	}

	/**
	 * Simple notice markup for the frontend.
	 *
	 * @param string $message Message.
	 * @param string $kind    info|warning|error.
	 * @return string
	 */
	protected function notice( $message, $kind = 'info' ) {
		$kind = in_array( $kind, array( 'info', 'warning', 'error' ), true ) ? $kind : 'info';

		return sprintf(
			'<div class="vvai-notice vvai-notice--%1$s" role="status"><p>%2$s</p></div>',
			esc_attr( $kind ),
			esc_html( $message )
		);
	}
}
