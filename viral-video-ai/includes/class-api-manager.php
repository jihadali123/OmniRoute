<?php
/**
 * Provider registry.
 *
 * The only place that knows which adapter class serves which provider key.
 * Add-ons register their own provider with `VVAI_Api_Manager::register()` (or
 * the `vvai_register_providers` action) and the whole plugin picks it up: the
 * connection UI dropdown, the router, diagnostics.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Api_Manager
 */
class VVAI_Api_Manager {

	/**
	 * Built-in provider keys in UI order.
	 *
	 * @var string[]
	 */
	private static $keys = array( 'openai', 'gemini', 'anthropic', 'groq', 'openrouter', 'custom' );

	/**
	 * Provider key => class name.
	 *
	 * @var array<string,string>
	 */
	private static $classes = array(
		'openai'     => 'VVAI_OpenAI_Provider',
		'gemini'     => 'VVAI_Gemini_Provider',
		'anthropic'  => 'VVAI_Anthropic_Provider',
		'groq'       => 'VVAI_Groq_Provider',
		'openrouter' => 'VVAI_OpenRouter_Provider',
		'custom'     => 'VVAI_Custom_Provider',
	);

	/**
	 * Instantiated adapters.
	 *
	 * @var array<string,VVAI_AI_Provider_Interface>
	 */
	private $instances = array();

	/**
	 * Shared static instances (used by the static helpers).
	 *
	 * @var array<string,VVAI_AI_Provider_Interface>
	 */
	private static $shared = array();

	/**
	 * HTTP transport.
	 *
	 * @var VVAI_Api_Connection
	 */
	private $http;

	/**
	 * Logger.
	 *
	 * @var VVAI_Logger
	 */
	private $logger;

	/**
	 * Settings.
	 *
	 * @var VVAI_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param VVAI_Api_Connection|null $http     Transport.
	 * @param VVAI_Logger|null         $logger   Logger.
	 * @param VVAI_Settings|null       $settings Settings.
	 */
	public function __construct( $http = null, $logger = null, $settings = null ) {
		$this->http     = $http instanceof VVAI_Api_Connection ? $http : new VVAI_Api_Connection();
		$this->logger   = $logger instanceof VVAI_Logger ? $logger : new VVAI_Logger();
		$this->settings = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
	}

	/**
	 * Register a provider adapter.
	 *
	 * @param string $key   Provider key.
	 * @param string $class Class implementing VVAI_AI_Provider_Interface.
	 * @return bool
	 */
	public static function register( $key, $class ) {
		$key = strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $key ) );

		if ( '' === $key || ! class_exists( $class ) ) {
			return false;
		}

		if ( ! in_array( $key, self::$keys, true ) ) {
			self::$keys[] = $key;
		}

		self::$classes[ $key ] = $class;

		return true;
	}

	/**
	 * All known provider keys.
	 *
	 * @return string[]
	 */
	public static function provider_keys() {
		/**
		 * Let add-ons register providers.
		 *
		 * @param VVAI_Api_Manager|null Manager instance, null in static context.
		 */
		do_action( 'vvai_register_providers', null );

		return self::$keys;
	}

	/**
	 * Get an adapter instance (shared, dependency-free variant for static use).
	 *
	 * @param string $key Provider key.
	 * @return VVAI_AI_Provider_Interface|null
	 */
	public static function shared( $key ) {
		$key = (string) $key;

		if ( ! isset( self::$classes[ $key ] ) ) {
			return null;
		}

		if ( ! isset( self::$shared[ $key ] ) ) {
			$class = self::$classes[ $key ];

			if ( ! class_exists( $class ) ) {
				return null;
			}

			self::$shared[ $key ] = new $class();
		}

		return self::$shared[ $key ];
	}

	/**
	 * Resolve an adapter, injecting the shared transport.
	 *
	 * @param string $key Provider key.
	 * @return VVAI_AI_Provider_Interface|WP_Error
	 */
	public function get( $key ) {
		$key = (string) $key;

		if ( ! isset( self::$classes[ $key ] ) ) {
			/**
			 * Last chance for another plugin to supply an adapter instance.
			 *
			 * @param VVAI_AI_Provider_Interface|null $provider Provider.
			 * @param string $key Provider key.
			 */
			$filtered = apply_filters( 'vvai_provider_instance', null, $key );

			if ( $filtered instanceof VVAI_AI_Provider_Interface ) {
				return $filtered;
			}

			return new WP_Error(
				'unknown_provider',
				sprintf(
					/* translators: %s: provider key. */
					__( 'Unknown AI provider "%s".', 'viral-video-ai' ),
					$key
				)
			);
		}

		if ( ! isset( $this->instances[ $key ] ) ) {
			$class = self::$classes[ $key ];

			if ( ! class_exists( $class ) ) {
				/* translators: %s: PHP class name. */
				return new WP_Error( 'missing_provider_class', sprintf( __( 'Provider class %s is missing. Reinstall the plugin.', 'viral-video-ai' ), $class ) );
			}

			$adapter = new $class( $this->http, $this->logger, $this->settings );

			if ( ! $adapter instanceof VVAI_AI_Provider_Interface ) {
				return new WP_Error( 'invalid_provider_class', __( 'A registered provider does not implement VVAI_AI_Provider_Interface.', 'viral-video-ai' ) );
			}

			$this->instances[ $key ] = $adapter;
		}

		return $this->instances[ $key ];
	}

	/**
	 * Provider catalogue for the UI.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function catalogue() {
		$out = array();

		foreach ( self::$keys as $key ) {
			$adapter = self::shared( $key );

			if ( ! $adapter ) {
				continue;
			}

			$out[] = array(
				'key'          => $key,
				'label'        => $adapter->get_label(),
				'defaultModel' => $adapter->get_default_model(),
				'models'       => $adapter->get_model_options(),
				'json'         => $adapter->supports_json(),
				'transcription' => $adapter->transcription_mode(),
				'baseUrl'      => $adapter->get_base_url(),
				'auth'         => $adapter->auth_style(),
				'capabilities' => $adapter->get_capabilities(),
			);
		}

		return $out;
	}

	/**
	 * Label for a provider key.
	 *
	 * @param string $key Provider key.
	 * @return string
	 */
	public static function label_for( $key ) {
		$adapter = self::shared( $key );

		return $adapter ? $adapter->get_label() : ucfirst( (string) $key );
	}

	/**
	 * Default model for a provider key.
	 *
	 * @param string $key Provider key.
	 * @return string
	 */
	public static function default_model_for( $key ) {
		$adapter = self::shared( $key );

		return $adapter ? $adapter->get_default_model() : '';
	}

	/**
	 * Default base URL for a provider key.
	 *
	 * @param string $key Provider key.
	 * @return string
	 */
	public static function base_url_for( $key ) {
		$adapter = self::shared( $key );

		return $adapter ? $adapter->get_base_url() : '';
	}

	/**
	 * Whether the provider can transcribe audio by itself.
	 *
	 * @param string $key Provider key.
	 * @return bool
	 */
	public static function provider_can_transcribe( $key ) {
		$adapter = self::shared( $key );

		return $adapter ? ( 'none' !== $adapter->transcription_mode() ) : false;
	}
}
