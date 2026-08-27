<?php
/**
 * Plugin Name:       Viral Video AI — AI-Powered Long Video to Viral Shorts Generator
 * Plugin URI:        https://example.com/viral-video-ai
 * Description:       Upload a long video (or paste a supported URL), let your connected AI provider find the most viral moments with exact timestamps, then render real vertical/horizontal short clips with FFmpeg — complete with viral scores, titles, captions, hashtags, previews and secure downloads.
 * Version:           1.0.1
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Viral Video AI
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       viral-video-ai
 * Domain Path:       /languages
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version. Bumped together with the header above.
 */
define( 'VVAI_VERSION', '1.0.1' );

/**
 * Minimum supported PHP version.
 */
define( 'VVAI_MIN_PHP', '7.4' );

/**
 * Filesystem paths.
 */
define( 'VVAI_PLUGIN_FILE', __FILE__ );
define( 'VVAI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VVAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VVAI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * REST API namespace used by both the admin screens and the Elementor widget.
 */
define( 'VVAI_REST_NAMESPACE', 'vvai/v1' );

/**
 * Database table prefix used by all custom tables.
 */
define( 'VVAI_TABLE_PREFIX', 'vvai_' );

/**
 * Capability required to manage the plugin.
 *
 * Kept as a constant-style definition so site owners can re-map it through
 * the `vvai_manage_capability` filter (see VVAI_Permissions).
 */
define( 'VVAI_MANAGE_CAP_DEFAULT', 'manage_options' );

/**
 * Locate the Composer autoloader if a vendor directory was shipped (optional),
 * then register the plugin's own class autoloader.
 */
if ( file_exists( VVAI_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once VVAI_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once VVAI_PLUGIN_DIR . 'includes/class-autoloader.php';
/**
 * The plugin's own folders. Admin screens and the Elementor widget live beside
 * includes/ so their classes resolve through the same convention.
 */
VVAI_Autoloader::register(
	array(
		VVAI_PLUGIN_DIR . 'includes/',
		VVAI_PLUGIN_DIR . 'admin/',
		VVAI_PLUGIN_DIR . 'Elementor/',
	)
);

/**
 * The procedural helper functions (prefixed `vvai_`).
 *
 * Loaded eagerly because almost every class uses them.
 */
require_once VVAI_PLUGIN_DIR . 'includes/helpers.php';

/**
 * Activation / deactivation / uninstall bootstrap.
 */
register_activation_hook( __FILE__, array( 'VVAI_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VVAI_Deactivator', 'deactivate' ) );

/**
 * Boot the plugin.
 *
 * Mounted on `plugins_loaded` with a low priority so that add-ons (custom
 * providers, custom crop engines) can hook in before the core services are
 * constructed.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( version_compare( PHP_VERSION, VVAI_MIN_PHP, '<' ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: 1: required PHP version, 2: current PHP version. */
								__( 'Viral Video AI requires PHP %1$s or newer. The server is running PHP %2$s. The plugin is inactive.', 'viral-video-ai' ),
								VVAI_MIN_PHP,
								PHP_VERSION
							)
						)
					);
				}
			);
			return;
		}

		VVAI_Plugin::instance();
	},
	5
);

/**
 * Convenience accessor so integrators do not need to know the class name.
 *
 * @return VVAI_Plugin
 */
function vvai() {
	return VVAI_Plugin::instance();
}
