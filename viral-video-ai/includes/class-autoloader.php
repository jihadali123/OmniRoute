<?php
/**
 * Class autoloader for the `VVAI_` prefix.
 *
 * Follows the WordPress file-naming convention, where the class prefix is not
 * repeated in the file name:
 *
 *   VVAI_Plugin              → includes/class-plugin.php
 *   VVAI_Clip_Generator      → includes/class-clip-generator.php
 *   VVAI_AI_Provider_Interface → includes/interface-ai-provider.php
 *   VVAI_Process             → includes/class-process.php
 *
 * Admin screens and the Elementor widget live in their own folders, so two
 * search directories are supported.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Autoloader
 */
final class VVAI_Autoloader {

	/**
	 * Directories to search (absolute, with trailing separator).
	 *
	 * @var string[]
	 */
	private static $directories = array();

	/**
	 * Resolution cache: class name => bool.
	 *
	 * @var array<string,bool>
	 */
	private static $resolved = array();

	/**
	 * Register the autoloader with one or more search directories.
	 *
	 * @param string|string[] $directory Directories containing class files.
	 */
	public static function register( $directory ) {
		self::$directories = array();

		foreach ( (array) $directory as $entry ) {
			$entry = (string) $entry;

			if ( '' !== $entry ) {
				self::$directories[] = rtrim( $entry, '/\\' ) . '/';
			}
		}

		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Add another search directory after registration.
	 *
	 * @param string $directory Absolute path.
	 */
	public static function add_directory( $directory ) {
		$directory = rtrim( (string) $directory, '/\\' ) . '/';

		if ( '' !== $directory && ! in_array( $directory, self::$directories, true ) ) {
			self::$directories[] = $directory;
		}
	}

	/**
	 * Autoload callback.
	 *
	 * @param string $class Fully qualified class name.
	 * @return bool Whether the class file was loaded.
	 */
	public static function autoload( $class ) {
		$class = (string) $class;

		if ( isset( self::$resolved[ $class ] ) ) {
			return self::$resolved[ $class ];
		}

		// Only our own classes; anything else belongs to WordPress or another plugin.
		if ( 0 !== strpos( $class, 'VVAI_' ) ) {
			return false;
		}

		foreach ( self::candidate_files( $class ) as $file ) {
			/**
			 * Filter the resolved class file path.
			 *
			 * @param string $file   Absolute path.
			 * @param string $class    Class name.
			 */
			$file = apply_filters( 'vvai_autoloader_file', $file, $class );

			if ( is_string( $file ) && is_file( $file ) ) {
				require_once $file;

				self::$resolved[ $class ] = true;

				return true;
			}
		}

		self::$resolved[ $class ] = false;

		return false;
	}

	/**
	 * File names a class may live in, in lookup order.
	 *
	 * @param string $class Class name.
	 * @return string[]
	 */
	public static function candidate_files( $class ) {
		$short = strtolower( substr( $class, strlen( 'VVAI_' ) ) );
		$slug  = str_replace( '_', '-', $short );

		$suffixes = array(
			'class-' . $slug . '.php',
			// Interfaces are named after their subject: VVAI_AI_Provider_Interface
			// lives in interface-ai-provider.php.
			'interface-' . preg_replace( '/-interface$/', '', $slug ) . '.php',
			'interface-' . $slug . '.php',
			'class-' . preg_replace( '/-interface$/', '', $slug ) . '.php',
			'trait-' . $slug . '.php',
		);

		$files = array();

		foreach ( self::$directories as $directory ) {
			foreach ( $suffixes as $suffix ) {
				$files[] = $directory . $suffix;
			}
		}

		return $files;
	}


	/**
	 * Reset the resolution cache (used by the test harness).
	 */
	public static function reset_cache() {
		self::$resolved = array();
	}
}
