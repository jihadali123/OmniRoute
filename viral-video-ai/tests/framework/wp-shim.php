<?php
/**
 * Minimal WordPress runtime for executing Viral Video AI outside WordPress.
 *
 * The plugin's logic (HTTP layer, job state machine, FFmpeg command building,
 * validation, repositories) is exercised here against:
 *   - a real SQLite database through a $wpdb-compatible shim,
 *   - real outbound HTTP via php-curl (so the provider adapters speak to an
 *     actual local mock provider over TCP),
 *   - real FFmpeg/FFprobe, reached through the `vvai_process_runner` filter
 *     which forwards argv to a local exec bridge.
 *
 * It is a test double, not a WordPress replacement: only the APIs the plugin
 * uses are implemented, faithfully.
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/vvai-wp/' );
	@mkdir( ABSPATH, 0777, true );
}

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

foreach ( array(
	'MB_IN_BYTES' => 1048576,
	'KB_IN_BYTES' => 1024,
	'GB_IN_BYTES' => 1073741824,
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
	'MONTH_IN_SECONDS'  => 2592000,
	'YEAR_IN_SECONDS'   => 31536000,
) as $vvai_constant => $vvai_value ) {
	if ( ! defined( $vvai_constant ) ) {
		define( $vvai_constant, $vvai_value );
	}
}

if ( ! defined( 'FILEINFO_MIME_TYPE' ) ) {
	define( 'FILEINFO_MIME_TYPE', 16 );
}

$GLOBALS['wp_version'] = '6.6-test';

if ( ! defined( 'STDOUT' ) ) {
	define( 'STDOUT', fopen( 'php://stdout', 'w' ) );
}

if ( ! defined( 'STDERR' ) ) {
	define( 'STDERR', fopen( 'php://stderr', 'w' ) );
}

// ---------------------------------------------------------------------------
// Test-controlled state
// ---------------------------------------------------------------------------

$GLOBALS['vvai_test'] = array(
	'user_id'   => 1,
	'caps'      => array( 'manage_options', 'upload_files', 'vvai_manage', 'vvai_generate' ),
	'options'   => array(),
	'transients' => array(),
	'scheduled' => array(),
	'actions'   => array(),
	'filters'   => array(),
	'did'       => array(),
	'http_log'  => array(),
	'json_out'  => null,
	'die'       => null,
	'site_url'  => 'http://localhost:8788',
	'uploads'   => null,
	'nonce'     => 'test-nonce',
	'logged_in' => true,
	'files_out' => array(),
);

function vvai_test_state( $key = null, $default = null ) {
	if ( null === $key ) {
		return $GLOBALS['vvai_test'];
	}

	return isset( $GLOBALS['vvai_test'][ $key ] ) ? $GLOBALS['vvai_test'][ $key ] : $default;
}

function vvai_test_set( $key, $value ) {
	$GLOBALS['vvai_test'][ $key ] = $value;
}

function vvai_test_reset() {
	$GLOBALS['vvai_test']['options']    = array();
	$GLOBALS['vvai_test']['transients']  = array();
	$GLOBALS['vvai_test']['scheduled']   = array();
	$GLOBALS['vvai_test']['http_log']    = array();
	$GLOBALS['vvai_test']['actions']     = array();
	$GLOBALS['vvai_test']['did']         = array();
	$GLOBALS['vvai_test']['json_out']    = null;
	$GLOBALS['vvai_test']['die']         = null;
	$GLOBALS['vvai_test']['user_id']     = 1;
	$GLOBALS['vvai_test']['caps']        = array( 'manage_options', 'upload_files', 'vvai_manage', 'vvai_generate' );
	$GLOBALS['vvai_test']['logged_in']   = true;
	@unlink( vvai_test_uploads_dir() . '/vvai' === '' ? '/tmp/x' : vvai_test_uploads_dir() );
}

function vvai_test_uploads_dir() {
	if ( empty( $GLOBALS['vvai_test']['uploads'] ) ) {
		$dir = rtrim( sys_get_temp_dir(), '/' ) . '/vvai-test-uploads-' . getmypid();
		@mkdir( $dir . '/uploads', 0777, true );
		$GLOBALS['vvai_test']['uploads'] = $dir;
	}

	return $GLOBALS['vvai_test']['uploads'] . '/uploads';
}

// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) {
	$GLOBALS['vvai_test']['filters'][ $hook ][ $priority ][] = array( $callback, $accepted );
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted );
}

function remove_filter( $hook, $callback, $priority = 10 ) {
	if ( empty( $GLOBALS['vvai_test']['filters'][ $hook ][ $priority ] ) ) {
		return false;
	}

	foreach ( $GLOBALS['vvai_test']['filters'][ $hook ][ $priority ] as $index => $entry ) {
		if ( $entry[0] === $callback ) {
			unset( $GLOBALS['vvai_test']['filters'][ $hook ][ $priority ][ $index ] );
			return true;
		}
	}

	return false;
}

function remove_all_filters( $hook, $priority = false ) {
	if ( false === $priority ) {
		unset( $GLOBALS['vvai_test']['filters'][ $hook ] );
		return true;
	}

	unset( $GLOBALS['vvai_test']['filters'][ $hook ][ $priority ] );

	return true;
}

function remove_all_actions( $hook, $priority = false ) {
	return remove_all_filters( $hook, $priority );
}

function has_filter( $hook, $callback = false ) {
	return ! empty( $GLOBALS['vvai_test']['filters'][ $hook ] );
}

function has_action( $hook, $callback = false ) {
	return has_filter( $hook, $callback );
}

function apply_filters( $hook, $value = null ) {
	$extra = array_slice( func_get_args(), 2 );

	if ( empty( $GLOBALS['vvai_test']['filters'][ $hook ] ) ) {
		return $value;
	}

	$by_priority = $GLOBALS['vvai_test']['filters'][ $hook ];
	ksort( $by_priority );

	foreach ( $by_priority as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$accepted = (int) $entry[1];
			$args     = array_merge( array( $value ), array_slice( $extra, 0, max( 0, $accepted - 1 ) ) );
			$value    = call_user_func_array( $entry[0], $args );
		}
	}

	return $value;
}

function apply_filters_ref_array( $hook, $args ) {
	$value = array_shift( $args );
	return apply_filters( $hook, $value, ...$args );
}

function do_action( $hook ) {
	$args = array_slice( func_get_args(), 1 );

	$GLOBALS['vvai_test']['did'][ $hook ] = ( $GLOBALS['vvai_test']['did'][ $hook ] ?? 0 ) + 1;
	$GLOBALS['vvai_test']['actions'][]    = $hook;

	if ( empty( $GLOBALS['vvai_test']['filters'][ $hook ] ) ) {
		return;
	}

	$by_priority = $GLOBALS['vvai_test']['filters'][ $hook ];
	ksort( $by_priority );

	foreach ( $by_priority as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, (int) $entry[1] ) );
		}
	}
}

function did_action( $hook ) {
	return (int) ( $GLOBALS['vvai_test']['did'][ $hook ] ?? 0 );
}

function doing_filter( $hook = null ) {
	return false;
}

function wp_doing_ajax() {
	return ! empty( $GLOBALS['vvai_test']['doing_ajax'] );
}

function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function load_plugin_textdomain( $domain, $deprecated = false, $path = false ) { return true; }
function is_textdomain_loaded( $domain ) { return true; }

// ---------------------------------------------------------------------------
// i18n + escaping + sanitizing
// ---------------------------------------------------------------------------

function __( $text, $domain = 'default' ) { return (string) $text; }
function _e( $text, $domain = 'default' ) { echo (string) $text; }
function _x( $text, $context, $domain = 'default' ) { return (string) $text; }
function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === (int) $number ? $single : $plural; }
function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = 'default' ) { return esc_attr( $text ); }
function esc_html_e( $text, $domain = 'default' ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = 'default' ) { echo esc_attr( $text ); }
function translate( $text, $domain = 'default' ) { return (string) $text; }

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_js( $text ) {
	return str_replace( array( '\\', "'", '"', "\n", "\r" ), array( '\\\\', "\\'", '\\"', '\\n', '' ), (string) $text );
}

function esc_url( $url ) {
	return filter_var( (string) $url, FILTER_SANITIZE_URL ) ?: '';
}

function esc_url_raw( $url ) {
	return esc_url( $url );
}

function esc_sql( $data ) {
	return $data;
}

function wp_kses( $string, $allowed_html = array(), $allowed_protocols = array() ) {
	return strip_tags( (string) $string );
}

function wp_kses_post( $string ) {
	return strip_tags( (string) $string );
}

function wp_strip_all_tags( $string, $remove_breaks = false ) {
	$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
	$string = strip_tags( (string) $string );

	if ( $remove_breaks ) {
		$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
	}

	return trim( (string) $string );
}

function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = wp_strip_all_tags( $str );
	$str = preg_replace( '/[\r\n\t]+/', ' ', $str );
	$str = preg_replace( '/ {2,}/', ' ', $str );

	return trim( (string) $str );
}

function sanitize_textarea_field( $str ) {
	$str = wp_strip_all_tags( (string) $str );
	return trim( preg_replace( '/(?:\r\n|\r|\n){3,}/', "\n\n", $str ) );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_file_name( $filename ) {
	$filename = str_replace( array( '%20', '&#038;' ), array( ' ', '&' ), (string) $filename );
	$filename = preg_replace( '/[^A-Za-z0-9._\- ]/', '', basename( $filename ) );
	$filename = trim( str_replace( ' ', '-', $filename ), '.-' );

	return ( '' === $filename ) ? 'file' : substr( $filename, 0, 255 );
}

function sanitize_title( $title ) {
	return preg_replace( '/[^a-z0-9_\-]/', '-', strtolower( preg_replace( '/\s+/', '-', trim( wp_strip_all_tags( (string) $title ) ) ) ) );
}

function sanitize_title_with_dashes( $title, $raw_title = '', $context = 'save' ) {
	$title = strtolower( remove_accents( wp_strip_all_tags( (string) $title ) ) );
	$title = preg_replace( '/[^a-z0-9_\-*]/', '', $title );
	$title = preg_replace( '/[^a-z0-9_\-]+/', '-', $title );

	return trim( (string) $title, '-' );
}

function remove_accents( $text ) {
	if ( function_exists( 'iconv' ) ) {
		$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $text );

		if ( false !== $converted ) {
			return $converted;
		}
	}

	return (string) $text;
}

function absint( $number ) {
	return abs( (int) $number );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : ( is_array( $value ) ? array_map( 'wp_unslash', $value ) : $value );
}

function add_query_arg( ...$args ) {
	if ( is_array( $args[0] ) ) {
		$params  = $args[0];
		$url     = $args[1] ?? '';
	} else {
		$params = array( $args[0] => $args[1] );
		$url    = $args[2] ?? '';
	}

	$fragment = '';
	$hash     = strpos( (string) $url, '#' );

	if ( false !== $hash ) {
		$fragment = substr( (string) $url, $hash );
		$url      = substr( (string) $url, 0, $hash );
	}

	$query = array();

	if ( false !== strpos( (string) $url, '?' ) ) {
		list( $base, $existing ) = explode( '?', (string) $url, 2 );

		foreach ( explode( '&', $existing ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			list( $k, $v ) = array_pad( explode( '=', $pair, 2 ), 2, '' );
			$query[ $k ] = $v;
		}
	} else {
		$base = (string) $url;
	}

	foreach ( $params as $key => $value ) {
		$query[ $key ] = is_scalar( $value ) ? rawurlencode( (string) $value ) : '';
	}

   return $base . ( $query ? '?' . http_build_query( $query ) : '' ) . $fragment;
}

function remove_query_arg( $keys, $url = '' ) {
	return $url;
}

function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	}

	if ( is_string( $args ) ) {
		parse_str( $args, $args );
	}

	if ( ! is_array( $args ) ) {
		$args = array();
	}

	return array_merge( (array) $defaults, $args );
}

function wp_list_pluck( $list, $field, $index_key = null ) {
	$out = array();

	foreach ( (array) $list as $key => $item ) {
		$value = null;

		if ( is_array( $item ) ) {
			$value = $item[ $field ] ?? null;
		} elseif ( is_object( $item ) ) {
			$value = $item->$field ?? null;
		}

		if ( null === $value ) {
			continue;
		}

		if ( null === $index_key ) {
			$out[] = $value;
		} else {
			$out[ ( is_array( $item ) ? ( $item[ $index_key ] ?? $key ) : ( $item->$index_key ?? $key ) ) ] = $value;
		}
	}

	return $out;
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function size_format( $bytes, $decimals = 0 ) {
	return vvai_human_size( (float) $bytes, $decimals );
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals );
}

function get_bloginfo( $show = '' ) {
	switch ( $show ) {
		case 'version':
			return $GLOBALS['wp_version'];
		case 'name':
			return 'Test Site';
		case 'url':
		case 'wpurl':
			return $GLOBALS['vvai_test']['site_url'];
	}

	return '';
}

function get_option( $option, $default = false ) {
	$options = $GLOBALS['vvai_test']['options'];

	if ( array_key_exists( $option, $options ) ) {
		return $options[ $option ];
	}

	return $default;
}

function update_option( $option, $value, $autoload = null ) {
	$previous = get_option( $option, null );

	if ( $previous === $value ) {
		return false;
	}

	$GLOBALS['vvai_test']['options'][ $option ] = $value;

	return true;
}

function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $option, $GLOBALS['vvai_test']['options'] ) ) {
		return false;
	}

	$GLOBALS['vvai_test']['options'][ $option ] = $value;

	return true;
}

function delete_option( $option ) {
	if ( ! array_key_exists( $option, $GLOBALS['vvai_test']['options'] ) ) {
		return false;
	}

	unset( $GLOBALS['vvai_test']['options'][ $option ] );

	return true;
}

function register_setting( $group, $name, $args = array() ) { return true; }
function get_transient( $transient ) {
	$store = $GLOBALS['vvai_test']['transients'];

	if ( ! isset( $store[ $transient ] ) ) {
		return false;
	}

	if ( $store[ $transient ]['expires'] > 0 && $store[ $transient ]['expires'] < time() ) {
		unset( $GLOBALS['vvai_test']['transients'][ $transient ] );
		return false;
	}

	return $store[ $transient ]['value'];
}

function set_transient( $transient, $value, $expiration = 0 ) {
	$GLOBALS['vvai_test']['transients'][ $transient ] = array(
		'value'    => $value,
		'expires'  => $expiration > 0 ? time() + (int) $expiration : 0,
	);

	return true;
}

function delete_transient( $transient ) {
	unset( $GLOBALS['vvai_test']['transients'][ $transient ] );
	return true;
}

function get_site_transient( $transient ) { return get_transient( $transient ); }
function set_site_transient( $transient, $value, $exp = 0 ) { return set_transient( $transient, $value, $exp ); }

function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['vvai_test']['scheduled'] as $event ) {
		if ( $event['hook'] === $hook && ( ! $args || $event['args'] === $args ) ) {
			return $event['timestamp'];
		}
	}

	return false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	$GLOBALS['vvai_test']['scheduled'][] = compact( 'timestamp', 'recurrence', 'hook', 'args' );
	return true;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['vvai_test']['scheduled'][] = array(
		'timestamp' => $timestamp,
		'recurrence' => 'single',
		'hook'      => $hook,
		'args'      => $args,
	);

	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$cleared = 0;

	foreach ( $GLOBALS['vvai_test']['scheduled'] as $index => $event ) {
		if ( $event['hook'] === $hook && ( ! $args || $event['args'] === $args ) ) {
			unset( $GLOBALS['vvai_test']['scheduled'][ $index ] );
			$cleared++;
		}
	}

	$GLOBALS['vvai_test']['scheduled'] = array_values( $GLOBALS['vvai_test']['scheduled'] );

	return $cleared;
}

function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	$GLOBALS['vvai_test']['scheduled'][] = compact( 'hook', 'args', 'group' ) + array( 'timestamp' => time(), 'recurrence' => 'async' );
	return count( $GLOBALS['vvai_test']['scheduled'] );
}

function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
	$GLOBALS['vvai_test']['scheduled'][] = compact( 'timestamp', 'hook', 'args', 'group' ) + array( 'recurrence' => 'single' );
	return count( $GLOBALS['vvai_test']['scheduled'] );
}

function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['vvai_test']['scheduled'] as $event ) {
		if ( $event['hook'] === $hook ) {
			return true;
		}
	}

	return false;
}

// ---------------------------------------------------------------------------
// URLs, paths, uploads
// ---------------------------------------------------------------------------

function vvai_trim_implode( $glue, $parts ) {
	$clean = array();

	foreach ( (array) $parts as $part ) {
		$part = (string) $part;

		if ( '' === $part ) {
			continue;
		}

		$clean[] = rtrim( $part, $glue );
	}

	return implode( $glue, $clean );
}

function home_url( $path = '', $scheme = null ) {
	return vvai_trim_implode( '/', array( $GLOBALS['vvai_test']['site_url'], ltrim( (string) $path, '/' ) ) );
}

function site_url( $path = '' ) { return home_url( $path ); }
function get_site_url() { return $GLOBALS['vvai_test']['site_url']; }
function get_home_url() { return $GLOBALS['vvai_test']['site_url']; }

function admin_url( $path = '', $scheme = 'admin' ) {
	return home_url( 'wp-admin/' . ltrim( (string) $path, '/' ) );
}

function includes_url( $path = '' ) { return home_url( 'wp-includes/' . ltrim( $path, '/' ) ); }
function content_url( $path = '' ) { return home_url( 'wp-content/' . ltrim( $path, '/' ) ); }
function plugins_url( $path = '', $plugin = '' ) { return home_url( 'wp-content/plugins/' . ltrim( $path, '/' ) ); }

function rest_url( $path = '' ) {
	return home_url( 'wp-json/' . ltrim( (string) $path, '/' ) );
}

function rest_get_url_prefix() { return home_url( 'wp-json' ); }

function set_url_scheme( $url, $scheme = null ) { return (string) $url; }



function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
}

function get_upload_space_available() { return 10 * GB_IN_BYTES; }

function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
	$basedir = vvai_test_uploads_dir();
	$baseurl = $GLOBALS['vvai_test']['site_url'] . '/wp-content/uploads';

	return array(
		'path'    => $basedir,
		'url'     => $baseurl,
		'subdir'  => '',
		'basedir' => $basedir,
		'baseurl' => $baseurl,
		'error'   => false,
	);
}

function get_stylesheet_directory() { return ABSPATH . 'wp-content/themes/test-theme'; }
function get_template_directory() { return ABSPATH . 'wp-content/themes/test-theme'; }
function get_permalink( $post = 0 ) { return home_url( '/sample-page/' ); }
function plugin_dir_path( $file ) { return trailingslashit( dirname( $file ) ); }
function plugin_dir_url( $file ) { return home_url( 'wp-content/plugins/' . basename( dirname( $file ) ) ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function wp_mkdir_p( $target ) {
	if ( is_dir( $target ) ) {
		return true;
	}

	return @mkdir( $target, 0777, true ) || is_dir( $target );
}

function get_filesystem_method() { return 'direct'; }
function request_filesystem_credentials( $url = '', $method = false, $error = false ) { return true; }
function wp_raise_memory_limit( $context = 'admin' ) { return false; }

// ---------------------------------------------------------------------------
// Nonces, capabilities, users
// ---------------------------------------------------------------------------

function wp_create_nonce( $action = -1 ) {
	return substr( hash_hmac( 'sha256', 'nonce|' . $action, 'test-salt' ), 0, 10 );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	if ( ! is_string( $nonce ) ) {
		return false;
	}

	$enabled = $GLOBALS['vvai_test']['verify_nonces'] ?? true;

	if ( ! $enabled ) {
		return 1;
	}

	if ( ! hash_equals( wp_create_nonce( $action ), $nonce ) ) {
		return false;
	}

	return 1;
}


function check_ajax_referer( $action = -1, $query = '_wpnonce', $die = true ) {
	$nonce = isset( $_REQUEST[ is_string( $query ) ? $query : $query[0] ] ) ? $_REQUEST[ is_string( $query ) ? $query : $query[0] ] : '';

	$verified = wp_verify_nonce( $nonce, $action );

	if ( ! $verified && $die ) {
		$GLOBALS['vvai_test']['die'] = array( 'type' => 'nonce', 'action' => $action );

		throw new VVAI_Test_Halt( 'nonce_failed' );
	}

	return $verified;
}

function current_user_can( $capability, ...$args ) {
	if ( ! $GLOBALS['vvai_test']['logged_in'] ) {
		return false;
	}

	if ( in_array( 'all_caps', $GLOBALS['vvai_test']['caps'], true ) ) {
		return true;
	}

	return in_array( $capability, $GLOBALS['vvai_test']['caps'], true );
}

function user_can( $user, $capability, ...$args ) {
	return current_user_can( $capability );
}

function get_current_user_id() {
	return $GLOBALS['vvai_test']['logged_in'] ? (int) $GLOBALS['vvai_test']['user_id'] : 0;
}

function is_user_logged_in() { return (bool) $GLOBALS['vvai_test']['logged_in']; }
function is_admin() { return (bool) ( $GLOBALS['vvai_test']['is_admin'] ?? true ); }
function is_multisite() { return false; }
function get_current_blog_id() { return 1; }

function get_user_by( $field, $value ) {
	if ( 'id' !== $field ) {
		return false;
	}

	$id = (int) $value;

	if ( $id <= 0 ) {
		return false;
	}

	return new VVAI_Test_User( $id );
}

function get_users( $args = array() ) { return array( new VVAI_Test_User( 1 ) ); }
function count_users( $mode = 'include_unapproved' ) { return array( 'total_users' => 1, 'avail_roles' => array( 'administrator' => 1 ) ); }

function get_role( $role ) {
	return new VVAI_Test_Role( $role );
}

function add_menu_page( ...$args ) { $GLOBALS['vvai_test']['menu'][] = $args; return 'toplevel_page_vvai'; }
function add_submenu_page( ...$args ) { $GLOBALS['vvai_test']['menu'][] = $args; return 'vvai-page-' . ( $args[3] ?? '' ); }
function get_admin_url( $blog_id = null, $path = '' ) { return admin_url( $path ); }
function wp_safe_redirect( $location, $status = 302 ) { $GLOBALS['vvai_test']['redirect'] = $location; return true; }
function deactivate_plugins( $plugins ) { $GLOBALS['vvai_test']['deactivated'] = $plugins; }
function is_plugin_active( $plugin ) { return true; }
function wp_die( $message = '', $title = '', $args = array() ) {
	$GLOBALS['vvai_test']['die'] = array( 'message' => $message, 'args' => $args );

	throw new VVAI_Test_Halt( 'wp_die' );
}

function wp_send_json_success( $data = null, $status_code = null ) {
	$GLOBALS['vvai_test']['json_out'] = array( 'success' => true, 'data' => $data, 'status' => $status_code );

	throw new VVAI_Test_Halt( 'json_success' );
}

function wp_send_json_error( $data = null, $status_code = null ) {
	$GLOBALS['vvai_test']['json_out'] = array( 'success' => false, 'data' => $data, 'status' => $status_code );

	throw new VVAI_Test_Halt( 'json_error' );
}

function wp_send_json( $response, $status_code = null ) {
	$GLOBALS['vvai_test']['json_out'] = $response;

	throw new VVAI_Test_Halt( 'json' );
}

function get_transient_or_similar() { return null; }

// ---------------------------------------------------------------------------
// Scripts / styles (recorded)
// ---------------------------------------------------------------------------

foreach ( array(
	'wp_enqueue_style', 'wp_enqueue_script', 'wp_register_style', 'wp_register_script',
	'wp_localize_script', 'wp_add_inline_script', 'wp_add_inline_style', 'wp_dequeue_style',
	'wp_dequeue_script', 'wp_script_add_data', 'wp_set_script_translations',
) as $vvai_recorded_function ) {
	eval( 'function ' . $vvai_recorded_function . '( ...$args ) { $GLOBALS["vvai_test"]["assets"][] = array( "' . $vvai_recorded_function . '", $args ); return true; }' );
}

function wp_create_script_asset_handle_data( ...$args ) { return array(); }


// ---------------------------------------------------------------------------
// Escaping / formatting / misc UI helpers
// ---------------------------------------------------------------------------

function checked( $checked, $current = true, $display = true ) {
	$result = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}

function selected( $selected, $current = true, $display = true ) {
	$result = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}

function disabled( $disabled, $current = true, $display = true ) {
	$result = ( (string) $disabled === (string) $current ) ? " disabled='disabled'" : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}

function get_locale() { return 'en_US'; }
function is_rtl() { return false; }
function wp_doing_cron() { return ! empty( $GLOBALS['vvai_test']['doing_cron'] ); }
function wp_installing() { return false; }
function wp_using_ext_object_cache() { return false; }
function wp_cache_get( $key, $group = '' ) { return false; }
function wp_cache_set( $key, $value, $group = '', $expire = 0 ) { return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function wp_cache_add( $key, $value, $group = '', $expire = 0 ) { return true; }
function wp_suspend_cache_addition( $suspend = null ) { return false; }
function flush_rewrite_rules( $hard = true ) { return true; }
function get_current_screen() { return null; }
function wp_get_upload_dir() { return wp_upload_dir(); }
function wp_check_filetype( $filename, $mimes = null ) {
	$extension = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
	$map       = array(
		'mp4'  => 'video/mp4',
		'm4v'  => 'video/x-m4v',
		'mov'  => 'video/quicktime',
		'webm' => 'video/webm',
		'mkv'  => 'video/x-matroska',
		'avi'  => 'video/x-msvideo',
		'mp3'  => 'audio/mpeg',
		'srt'  => 'text/plain',
	);

	return array(
		'ext'  => isset( $map[ $extension ] ) ? $extension : '',
		'type' => $map[ $extension ] ?? '',
	);
}

function wp_check_filetype_and_ext( $file, $filename, $mimes = null ) {
	$checked = wp_check_filetype( $filename, $mimes );

	return array(
		'ext'             => $checked['ext'],
		'type'            => $checked['type'],
		'proper_filename' => $filename,
	);
}

function get_attached_file( $attachment_id, $style = '' ) {
	return $GLOBALS['vvai_test']['attachments'][ (int) $attachment_id ]['file'] ?? false;
}

function get_post_type( $post = null ) {
	$id = is_numeric( $post ) ? (int) $post : 0;

	return isset( $GLOBALS['vvai_test']['attachments'][ $id ] ) ? 'attachment' : false;
}

function get_the_title( $post = 0 ) {
	$id = is_numeric( $post ) ? (int) $post : 0;

	return $GLOBALS['vvai_test']['attachments'][ $id ]['title'] ?? '';
}

function get_post( $id = null ) { return null; }
function wp_get_attachment_url( $id = 0 ) { return ''; }
function add_shortcode( $tag, $callback ) {
	$GLOBALS['vvai_test']['shortcodes'][ $tag ] = $callback;

	return true;
}

function do_shortcode( $content ) { return $content; }
function shortcode_atts( $defaults, $atts, $shortcode = '' ) { return wp_parse_args( (array) $atts, $defaults ); }
function wpautop( $text ) { return '<p>' . str_replace( "\n\n", '</p><p>', (string) $text ) . '</p>'; }
function make_clickable( $text ) { return (string) $text; }
function wp_kses_data( $text ) { return strip_tags( (string) $text ); }
function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) { return html_entity_decode( (string) $text, $quote_style, 'UTF-8' ); }
function wp_slash( $value ) { return is_string( $value ) ? addslashes( $value ) : $value; }
function wp_json_file_decode( $filename, $options = array() ) { return null; }
function get_home_path() { return ABSPATH; }
function list_files( $folder = '', $levels = 100 ) { return array(); }
function wp_handle_upload( &$file, $overrides = false, $time = null ) { return array(); }
function media_handle_upload( $file_id, $post_id, $post = array(), $overrides = array() ) { return new WP_Error( 'upload_failed', 'not supported in the shim' ); }
function url_to_postid( $url ) { return 0; }
function wp_get_referer() { return ''; }
function wp_validate_redirect( $location, $default = '' ) { return $location; }
function nocache_headers() { /* no-op in the CLI harness */ }
function status_header( $code, $description = '' ) {}
function send_origin_headers() {}
function rest_cookie_check_errors( $result ) { return $result; }
function rest_get_required_permissions( ...$args ) { return array(); }
function wp_json_encode_deep( $data ) { return $data; }
function wp_is_writable( $path ) { return is_writable( $path ); }
function wp_is_stream( $path ) { return false; }
function get_file_description( $filename ) { return $filename; }
function wp_convert_bytes_to_hr( $bytes ) { return size_format( $bytes ); }
function size_format_in_bytes( $bytes ) { return (int) $bytes; }
function wp_max_upload_size() { return 2 * GB_IN_BYTES; }
function wp_get_image_editor( $path ) { return new WP_Error( 'no_editor', 'not needed' ); }
function wp_hash( $data, $scheme = 'auth' ) { return hash_hmac( 'md5', (string) $data, wp_salt( $scheme ) ); }
function wp_generate_password( $length = 12, $special_chars = true, $extra = false ) {
	$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$password = '';

	for ( $i = 0; $i < $length; $i++ ) {
		$password .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
	}

	return $password;
}

function wp_generate_uuid4() {
	$data    = random_bytes( 16 );
	$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
	$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

	return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
}

function wp_salt( $scheme = 'salt' ) {
	static $cache = array();

	if ( isset( $cache[ $scheme ] ) ) {
		return $cache[ $scheme ];
	}

	$salts = array(
		'auth'        => 'unit-test-auth-salt',
		'secure_auth' => 'unit-test-secure-auth-salt',
		'logged_in'   => 'unit-test-logged-in-salt',
		'nonce'       => 'unit-test-nonce-salt',
	);

	return $cache[ $scheme ] = ( $salts[ $scheme ] ?? 'unit-test-salt' );
}

// ---------------------------------------------------------------------------
// WP_Error
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}

			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );

			return $codes ? $codes[0] : '';
		}

		public function get_error_message( $code = '' ) {
			$code = $code ? $code : $this->get_error_code();

			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		public function get_error_messages( $code = '' ) {
			if ( '' === $code ) {
				$out = array();

				foreach ( $this->errors as $messages ) {
					$out = array_merge( $out, $messages );
				}

				return $out;
			}

			return $this->errors[ $code ] ?? array();
		}

		public function get_error_data( $code = '' ) {
			$code = $code ? $code : $this->get_error_code();

			return $this->error_data[ $code ] ?? null;
		}

		public function has_errors() {
			return (bool) $this->errors;
		}

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}
}

// ---------------------------------------------------------------------------
// HTTP transport backed by real cURL
// ---------------------------------------------------------------------------

/**
 * Lets a test replace the whole transport (to simulate DNS failures, timeouts,
 * malformed bodies, etc.) without touching the plugin code.
 */
class VVAI_Test_HTTP {
	/** @var callable|null */
	public static $interceptor = null;

	/** @var array<int,array<string,mixed>> */
	public static $log = array();

	public static function reset() {
		self::$interceptor = null;
		self::$log         = array();
	}
}

class VVAI_Test_Headers implements IteratorAggregate, ArrayAccess, Countable {
	private $data = array();

	public function __construct( array $headers = array() ) {
		foreach ( $headers as $key => $value ) {
			$this->data[ strtolower( (string) $key ) ] = (string) $value;
		}
	}

	#[\ReturnTypeWillChange]
	public function getIterator(): Iterator {
		return new ArrayIterator( $this->data );
	}

	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ): bool {
		return isset( $this->data[ strtolower( (string) $offset ) ] );
	}

	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ): mixed {
		return $this->data[ strtolower( (string) $offset ) ] ?? null;
	}

	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ): void {
		$this->data[ strtolower( (string) $offset ) ] = (string) $value;
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ): void {
		unset( $this->data[ strtolower( (string) $offset ) ] );
	}

	#[\ReturnTypeWillChange]
	public function count(): int {
		return count( $this->data );
	}
}

/**
 * Perform the request.
 *
 * @param string $url  URL.
 * @param array  $args Requests-like args.
 * @return array|WP_Error
 */
function vvai_test_http_request( $url, array $args = array() ) {
	$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );

	if ( is_callable( VVAI_Test_HTTP::$interceptor ) ) {
		$result = call_user_func( VVAI_Test_HTTP::$interceptor, $method, $url, $args );

		if ( null !== $result ) {
			VVAI_Test_HTTP::$log[] = array( 'method' => $method, 'url' => $url, 'args' => $args, 'mocked' => true );

			return $result;
		}
	}

	if ( ! function_exists( 'curl_init' ) ) {
		return new WP_Error( 'http_request_failed', 'cURL is unavailable in this PHP build.' );
	}

	$handle = curl_init();
	// Requests-style 'json' shorthand: encode it and set the content type.
	if ( isset( $args['json'] ) && null === ( $args['body'] ?? null ) ) {
		$args['body'] = wp_json_encode( $args['json'] );

		if ( empty( $args['headers']['Content-Type'] ) ) {
			$args['headers']['Content-Type'] = 'application/json';
		}
	}

	$body   = null;

	if ( isset( $args['body'] ) ) {
		$body = is_array( $args['body'] ) ? http_build_query( $args['body'], '', '&', PHP_QUERY_RFC3986 ) : (string) $args['body'];
	}

	$headers = array();

	foreach ( (array) ( $args['headers'] ?? array() ) as $name => $value ) {
		if ( '' === $name || null === $value ) {
			continue;
		}

		$headers[] = $name . ': ' . $value;
	}

	$headers[] = 'Accept: application/json';

	$stream_to = ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) ? (string) $args['filename'] : '';

	$timeout = (float) ( $args['timeout'] ?? 5 );

	curl_setopt_array( $handle, array(
		CURLOPT_URL            => $url,
		CURLOPT_CUSTOMREQUEST  => $method,
		CURLOPT_RETURNTRANSFER => ( '' === $stream_to ),
		CURLOPT_HEADER         => false,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_CONNECTTIMEOUT => max( 1, (int) ( $args['connect_timeout'] ?? 10 ) ),
		CURLOPT_TIMEOUT_MS     => max( 200, (int) round( $timeout * 1000 ) ),
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_SSL_VERIFYPEER => ! empty( $args['sslverify'] ),
		CURLOPT_SSL_VERIFYHOST => ! empty( $args['sslverify'] ) ? 2 : 0,
		CURLOPT_USERAGENT      => (string) ( $args['user-agent'] ?? 'vvai-test' ),
		 CURLOPT_PROTOCOLS     => CURLPROTO_HTTP | CURLPROTO_HTTPS,
	) );

	if ( null !== $body ) {
		curl_setopt( $handle, CURLOPT_POSTFIELDS, $body );
	}

	$stream_handle = null;

	if ( '' !== $stream_to ) {
		$stream_handle = fopen( $stream_to, 'wb' );
		curl_setopt( $handle, CURLOPT_FILE, $stream_handle );
	}

	// curl_exec must run in both modes; with CURLOPT_FILE it returns true and the
	// body lands in the stream instead of the return value.
	$ok_transfer  = curl_exec( $handle );
	$response_body = $stream_handle ? '' : $ok_transfer;
	$errno         = curl_errno( $handle );
	$error         = curl_error( $handle );
	$code          = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

	curl_close( $handle );

	if ( $stream_handle ) {
		fclose( $stream_handle );
	}

	VVAI_Test_HTTP::$log[] = array(
		'method' => $method,
		'url'    => $url,
		'status' => $code,
		'error'  => $error,
		'args'   => array_diff_key( $args, array( 'headers' => true ) ),
	);

	if ( $errno ) {
		$message = curl_strerror( $errno ) ?: $error;

		if ( in_array( $errno, array( CURLE_OPERATION_TIMEDOUT, CURLE_OPERATION_TIMEOUTED ), true ) || false !== stripos( $message, 'timed out' ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: ' . $message );
		}

		return new WP_Error( 'http_request_failed', 'cURL error ' . $errno . ': ' . $message );
	}

	if ( $code >= 300 && $code < 400 ) {
		return new WP_Error( 'http_request_failed', 'Too many redirects (redirection is disabled).' );
	}

	$result = array(
		'headers'  => new VVAI_Test_Headers( array( 'content-type' => 'application/json', 'x-test' => '1' ) ),
		'body'     => (string) $response_body,
		'response' => array( 'code' => $code, 'message' => '' ),
		'cookies'  => array(),
		'filename' => '' !== $stream_to ? $stream_to : false,
	);

	if ( '' !== $stream_to ) {
		$result['body'] = '';
	}

	return $result;
}

function wp_remote_request( $url, array $args = array() ) {
	return vvai_test_http_request( $url, $args );
}

function wp_remote_get( $url, array $args = array() ) {
	$args['method'] = 'GET';

	return vvai_test_http_request( $url, $args );
}

function wp_remote_post( $url, array $args = array() ) {
	$args['method'] = 'POST';

	return vvai_test_http_request( $url, $args );
}

function wp_remote_head( $url, array $args = array() ) {
	$args['method'] = 'HEAD';

	return vvai_test_http_request( $url, $args );
}

function wp_remote_retrieve_response_code( $response ) {
	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		return '';
	}

	return $response['response']['code'] ?? '';
}

function wp_remote_retrieve_response_message( $response ) {
	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		return '';
	}

	return $response['response']['message'] ?? '';
}

function wp_remote_retrieve_body( $response ) {
	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		return '';
	}

	return $response['body'] ?? '';
}

function wp_remote_retrieve_headers( $response ) {
	if ( is_wp_error( $response ) || ! is_array( $response ) ) {
		return new VVAI_Test_Headers();
	}

	return $response['headers'] ?? new VVAI_Test_Headers();
}

function wp_remote_retrieve_header( $response, $header ) {
	$headers = wp_remote_retrieve_headers( $response );

	return $headers[ $header ] ?? '';
}

function download_url( $url, $timeout = 300, $signature_verify = false, $http_args = array() ) {
	$file = tempnam( sys_get_temp_dir(), 'vvai-dl-' );

	$args = array_merge(
		array(
			'method'  => 'GET',
			'timeout' => $timeout,
			'stream'  => true,
			'filename' => $file,
		),
		(array) $http_args
	);

	$response = vvai_test_http_request( $url, $args );

	if ( is_wp_error( $response ) ) {
		@unlink( $file );

		return $response;
	}

	if ( 200 !== (int) ( $response['response']['code'] ?? 0 ) ) {
		$code = (int) ( $response['response']['code'] ?? 0 );
		@unlink( $file );

		return new WP_Error( 'http_error', 'Download failed: ' . ( $code ?: 'no response' ) );
	}

	return $file;
}

function wp_get_remote_content_type( $url ) { return ''; }

// ---------------------------------------------------------------------------
// $wpdb on SQLite (real SQL execution, not a stub)
// ---------------------------------------------------------------------------

/**
 * A wpdb-compatible layer over SQLite3 so the plugin's SQL actually runs.
 *
 * Supports the subset WordPress code uses: prepare() with %d/%s/%f/%i, insert(),
 * update(), delete(), get_row(), get_results(), get_var(), get_col(), query(),
 * esc_like(), plus the `SHOW TABLES LIKE %s` probe used by the installer.
 */
class VVAI_Test_WPDB {
	public $prefix = 'wp_';
	public $options = 'vvai_options';
	public $last_error = '';
	public $last_result = array();
	public $insert_id = 0;
	public $num_rows = 0;
	public $rows_affected = 0;

	/** @var PDO */
	public $db;

	public function __construct( $file = null ) {
		$file = $file ?: ( sys_get_temp_dir() . '/vvai-test-db.sqlite' );

		if ( 'memory' === $file ) {
			$this->db = new PDO( 'sqlite::memory:' );
		} else {
			@unlink( $file );
			$this->db = new PDO( 'sqlite:' . $file );
		}

		$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->db->exec( 'PRAGMA foreign_keys = ON' );
	}

	public function get_charset_collate() { return ''; }

	/**
	 * WordPress-style prepare(): turn %d/%s/%f/%i into ? and bind later.
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$placeholder = "\x01";
		$index       = 0;
		$sql         = preg_replace_callback(
			'/%(-?\d*(?:\.\d+)?)([dsfFi])/',
			function ( $m ) use ( &$index, $placeholder ) {
				$index++;

				return $placeholder;
			},
			$query
		);

		$this->last_query_params = $args;
		$this->last_query          = $sql;

		return $sql;
	}

	public $last_query = '';
	public $last_query_params = array();

	private function bind( PDOStatement $statement, array $params ) {
		// Params already bound by caller (run_query path) are passed in $params.
		$position = 1;

		foreach ( $params as $value ) {
			$type = PDO::PARAM_STR;

			if ( is_int( $value ) ) {
				$type = PDO::PARAM_INT;
			} elseif ( is_bool( $value ) ) {
				$value = $value ? 1 : 0;
				$type  = PDO::PARAM_INT;
			} elseif ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			} elseif ( null === $value ) {
				$type = PDO::PARAM_NULL;
			}

			$statement->bindValue( $position++, $value, $type );
		}
	}

	private function translate( string $sql ): string {
		// Placeholders produced by prepare() become real bound parameters.
		$sql = str_replace( "\x01", '?', $sql );

		// MySQL-isms used by the plugin.
		$sql = preg_replace( '/\bSQL_CALC_FOUND_ROWS\b/i', '', $sql );
		$sql = preg_replace( '/\bON DUPLICATE KEY UPDATE\b/i', 'ON CONFLICT DO UPDATE SET', $sql );
		$sql = preg_replace( '/\bSHOW TABLES LIKE \?/i', "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", $sql );
		$sql = str_replace( 'FOUND_ROWS()', '1', $sql );

		return $sql;
	}

	private function run( string $sql, array $params, string $fetch = null ) {
		try {
			$this->last_error = '';
			$statement = $this->db->prepare( $this->translate( $sql ) );

			// When called with a prepared query from prepare(), splice args back in
			// through bound parameters (positional \x01 markers).
			$this->bind( $statement, $params );
			$statement->execute();

			if ( 'var' === $fetch ) {
				$value = $statement->fetchColumn();

				return false === $value ? null : $value;
			}

			if ( 'col' === $fetch ) {
				return $statement->fetchAll( PDO::FETCH_COLUMN, 0 );
			}

			if ( 'row' === $fetch ) {
				$row = $statement->fetch( PDO::FETCH_ASSOC );

				return false === $row ? null : $row;
			}

			if ( 'all' === $fetch ) {
				return $statement->fetchAll( PDO::FETCH_ASSOC );
			}

			$this->num_rows       = $statement->rowCount();
			$this->rows_affected = $statement->rowCount();

			return $statement->rowCount();
		} catch ( PDOException $exception ) {
			$this->last_error = $exception->getMessage();

			error_log( '[vvai-test-sql] ' . $this->last_error . ' :: ' . $sql );

			return ( 'all' === $fetch || 'col' === $fetch ) ? array() : ( 'row' === $fetch || 'var' === $fetch ? null : false );
		}
	}

	/**
	 * Run a query whose placeholders came from prepare().
	 */
	public function query( $query, ...$args ) {
		if ( $args ) {
			return $this->run( $query, $args );
		}

		// The common WordPress shape: $wpdb->query( $wpdb->prepare( ... ) ) — the
		// placeholder values were remembered by prepare().
		$params = $this->last_query === $query ? $this->last_query_params : array();

		return $this->run( $query, $params );
	}

	public function get_var( $query = null, $x = 0 ) {
		$result = $this->run_for( $query, 'var' );

		return $result;
	}

	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {
		$row = $this->run_for( $query, 'row' );

		if ( null === $row ) {
			return null;
		}

		if ( 'ARRAY_A' === $output ) {
			return $row;
		}

		if ( 'ARRAY_N' === $output ) {
			return array_values( $row );
		}

		return (object) $row;
	}

	public function get_results( $query = null, $output = 'OBJECT' ) {
		$rows = $this->run_for( $query, 'all' );

		if ( 'ARRAY_A' === $output ) {
			return array_values( (array) $rows );
		}

		if ( 'ARRAY_N' === $output ) {
			$out = array();

			foreach ( (array) $rows as $row ) {
				$out[] = array_values( (array) $row );
			}

			return $out;
		}

		return array_map( static function ( $row ) {
			return (object) $row;
		}, (array) $rows );
	}

	public function get_col( $query = null, $x = 0 ) {
		return $this->run_for( $query, 'col' );
	}

	private function run_for( $query, string $fetch ) {
		$params = $this->last_query === $query ? $this->last_query_params : array();

		return $this->run( (string) $query, $params, $fetch );
	}

	public function insert( $table, array $data, $formats = null ) {
		$columns      = array_keys( $data );
		$placeholders = array_fill( 0, count( $columns ), '?' );

		$sql = 'INSERT INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')';

		$result = $this->run( $sql, array_values( $data ) );

		if ( false === $result ) {
			return false;
		}

		$this->insert_id = (int) $this->db->lastInsertId();

		return 1;
	}

	public function replace( $table, array $data, $formats = null ) {
		$columns      = array_keys( $data );
		$placeholders = array_fill( 0, count( $columns ), '?' );

		$sql = 'INSERT OR REPLACE INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')';

		if ( false === $this->run( $sql, array_values( $data ) ) ) {
			return false;
		}

		$this->insert_id = (int) $this->db->lastInsertId();

		return 1;
	}

	public function update( $table, array $data, array $where, $formats = null, $where_formats = null ) {
		$sets   = array();
		$params = array_values( $data );

		foreach ( array_keys( $data ) as $column ) {
			$sets[] = $column . ' = ?';
		}

		$conditions = array();

		foreach ( $where as $column => $value ) {
			$conditions[] = $column . ' = ?';
			$params[]     = $value;
		}

		$sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $conditions );

		return $this->run( $sql, $params );
	}

	public function delete( $table, array $where, $where_formats = null ) {
		$conditions = array();
		$params     = array();

		foreach ( $where as $column => $value ) {
			$conditions[] = $column . ' = ?';
			$params[]     = $value;
		}

		return $this->run( 'DELETE FROM ' . $table . ' WHERE ' . implode( ' AND ', $conditions ), $params );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * Execute a MySQL-flavoured schema statement (used by dbDelta()).
	 */
	public function execute_schema( $sql ) {
		try {
			$this->db->exec( $sql );
			$this->last_error = '';

			return true;
		} catch ( PDOException $exception ) {
			$this->last_error = $exception->getMessage();

			return false;
		}
	}
}

function vvai_test_db_delta( $delta = '' ) {
	global $wpdb;

	$statements = preg_split( '/\b;\s*(?:\r?\n|$)/', (string) $delta );
	$done       = array();

	foreach ( $statements as $statement ) {
		$statement = trim( $statement );

		if ( '' === $statement || 0 !== stripos( $statement, 'CREATE TABLE' ) ) {
			continue;
		}

		$table = '';

		if ( preg_match( '/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/i', $statement, $m ) ) {
			$table = $m[1];
		}

		if ( '' === $table ) {
			continue;
		}

		$converted = vvai_test_mysql_schema_to_sqlite( $statement, $table );

		// Already exists? Then compare columns and add the missing ones.
		$existing = $wpdb->get_col( "PRAGMA table_info({$table})" );

		if ( $existing ) {
			preg_match_all( '/^\s*,?\s*`?([a-z0-9_]+)`?\s+(?:BIGINT|INT|TINYINT|SMALLINT|VARCHAR|DECIMAL|TEXT|LONGTEXT|DATETIME|TIMESTAMP)/im', $converted, $columns );

			foreach ( $columns[1] as $column ) {
				if ( ! in_array( $column, $existing, true ) && ! preg_match( '/^(PRIMARY|KEY|UNIQUE|INDEX)/i', $column ) ) {
					$type = 'TEXT';

					if ( preg_match( '/`?' . preg_quote( $column, '/' ) . '`?\s+([A-Z]+)/i', $converted, $t ) ) {
						$type = in_array( strtoupper( $t[1] ), array( 'BIGINT', 'INT', 'TINYINT', 'SMALLINT' ), true ) ? 'INTEGER' : 'TEXT';
					}

					$wpdb->execute_schema( "ALTER TABLE {$table} ADD COLUMN {$column} {$type} NOT NULL DEFAULT ''" );
				}
			}

			continue;
		}

		if ( $wpdb->execute_schema( $converted ) ) {
			$done[] = $table;
		}
	}

	return $done;
}

/**
 * Translate one MySQL CREATE TABLE into SQLite.
 *
 * Only the constructs the plugin emits are handled: unsigned ints,
 * AUTO_INCREMENT, KEY/INDEX lines, charset suffix and MySQL-style zero dates.
 *
 * @param string $sql   Statement.
 * @param string $table Table name.
 * @return string
 */
function vvai_test_mysql_schema_to_sqlite( $sql, $table ) {
	// Keep only the body.
	if ( ! preg_match( '/\((.*)\)\s*(?:ENGINE|DEFAULT|;|\z)/is', $sql, $m ) ) {
		return $sql;
	}

	$body  = $m[1];
	$lines = preg_split( '/,(?![^(]*\))/', $body );

	$kept = array();

	// Pre-scan: the single-column primary key, so its column line can be
	// rewritten as `INTEGER PRIMARY KEY AUTOINCREMENT`.
	$pk = 'id';

	if ( preg_match( '/PRIMARY KEY\s*\(\s*`?([a-z0-9_]+)`?\s*\)/i', $body, $pk_match ) ) {
		$pk = strtolower( $pk_match[1] );
	}

	foreach ( $lines as $line ) {
		$line = trim( $line );

		if ( '' === $line ) {
			continue;
		}

		if ( preg_match( '/^(PRIMARY KEY|KEY|UNIQUE KEY|INDEX|FULLTEXT|CONSTRAINT)\b/i', $line ) ) {
			if ( preg_match( '/^PRIMARY KEY\s*\(([^)]+)\)/i', $line, $pk ) ) {
				// Handled through AUTO_INCREMENT below.
				$GLOBALS['vvai_test']['pk'] = trim( str_replace( '`', '', $pk[1] ) );
			}

			continue;
		}

		$line = str_replace( '`', '', $line );
		$line = preg_replace( '/\s+UNSIGNED/i', '', $line );
		$line = preg_replace( '/\s+AUTO_INCREMENT/i', '', $line );
		$line = preg_replace( '/(?:BIG|MEDIUM|SMALL|TINY)?INT(\(\d+\))?(?![A-Za-z0-9_])/i', 'INTEGER', $line );
		$line = preg_replace( '/(LONG|MEDIUM)?TEXT/i', 'TEXT', $line );
		$line = preg_replace( '/VARCHAR\(\d+\)/i', 'TEXT', $line );
		$line = preg_replace( '/CHAR\(\d+\)/i', 'TEXT', $line );
		$line = preg_replace( '/DECIMAL\([\d,]+\)/i', 'REAL', $line );
		$line = preg_replace( "/DEFAULT\s+'0000-00-00 00:00:00'/i", "DEFAULT '1970-01-01 00:00:00'", $line );
		$line = preg_replace( '/\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $line );
		$line = trim( $line );

		// The auto-increment primary key.
		if ( preg_match( '/^' . preg_quote( $pk, '/' ) . '\s+INTEGER/i', $line ) ) {
			$line = preg_replace( '/NOT NULL/i', '', $line );
			$line .= ' PRIMARY KEY AUTOINCREMENT';
		}

		$kept[] = $line;
	}

	return 'CREATE TABLE ' . $table . " (\n\t" . implode( ",\n\t", $kept ) . "\n)";
}

function dbDelta( $delta = '', $execute = true ) {
	if ( ! $execute ) {
		return array();
	}

	return vvai_test_db_delta( $delta );
}


/**
 * Build a PCRE body from a WP route pattern: `(?P<id>\\d+)` becomes a named
 * capture so a test can read route arguments back out of the matched URL.
 *
 * @param string $pattern Route pattern.
 * @return string
 */


/**
 * Turn a WP route pattern into a PCRE body with named captures.
 *
 * @param string $pattern Route pattern, e.g. /vvai/v1/jobs/(?P<id>\d+).
 * @return string
 */
function vvai_test_route_regex_body( $pattern ) {
	$body = str_replace( '/', '\\/', (string) $pattern );

	$body = preg_replace_callback(
		'/\\(\\?P<([a-zA-Z0-9_]+)>[^)]*\\)/',
		static function ( $m ) {
			return '(?P<' . $m[1] . '>[^/]+)';
		},
		$body
	);

	return $body;
}

// ---------------------------------------------------------------------------
// REST API surface
// ---------------------------------------------------------------------------

class VVAI_Test_REST_Request implements ArrayAccess {
	public $method = 'GET';
	public $route = '';
	private $params = array();
	private $json = null;
	private $files = array();
	private $headers = array();

	public function __construct( $method = 'GET', $route = '' ) {
		$this->method = strtoupper( $method );
		$this->route  = $route;
	}

	public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	public function get_params() { return $this->params; }
	public function set_json_params( array $data ) { $this->json = $data; $this->params = array_merge( $this->params, $data ); }
	public function get_json_params() { return $this->json; }
	public function set_file_params( array $files ) { $this->files = $files; }
	public function get_file_params() { return $this->files; }
	public function set_header( $key, $value ) { $this->headers[ strtolower( (string) $key ) ] = $value; }
	public function get_header( $key ) { return $this->headers[ strtolower( (string) $key ) ] ?? null; }
	public function has_valid_params() { return true; }
	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ): bool { return isset( $this->params[ $offset ] ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ): mixed { return $this->params[ $offset ] ?? null; }
	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ): void { $this->params[ (string) $offset ] = $value; }
	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ): void { unset( $this->params[ (string) $offset ] ); }
}

class VVAI_Test_REST_Response {
	public $data;
	public $status = 200;
	public $headers = array();

	public function __construct( $data = null, $status = 200, $headers = array() ) {
		$this->data   = $data;
		$this->status = $status;
		$this->headers = $headers;
	}

	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
	public function set_status( $code ) { $this->status = (int) $code; }
	public function header( $key, $value ) { $this->headers[ $key ] = $value; }
}

class VVAI_Test_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';

	public static $routes = array();

	public static function reset() { self::$routes = array(); }

	public static function all() { return self::$routes; }
}

function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
	$key = '/' . trim( $namespace, '/' ) . '/' . ltrim( $route, '/' );

	VVAI_Test_REST_Server::$routes[ $key ] = $args;

	return true;
}

function rest_ensure_response( $response ) {
	if ( is_wp_error( $response ) || $response instanceof VVAI_Test_REST_Response ) {
		return $response;
	}

	return new VVAI_Test_REST_Response( $response );
}

function rest_authorization_required_code() {
	return is_user_logged_in() ? 403 : 401;
}

function rest_get_route_for_post( $post ) { return ''; }
function rest_sanitize_value_from_schema( $value, $args, $param = '' ) { return $value; }
function rest_validate_value_from_schema( $value, $args, $param = '' ) { return true; }
function rest_cookie_check_errors_shim() {}
function wp_get_current_user() { return new VVAI_Test_User( get_current_user_id() ); }
function get_current_user_id_safe() { return get_current_user_id(); }

class VVAI_Test_Role {
	public $name;
	public $capabilities = array();

	public function __construct( $name ) {
		$this->name = $name;
		$this->capabilities = $GLOBALS['vvai_test']['caps'];
	}

	public function has_cap( $cap ) { return in_array( $cap, (array) $GLOBALS['vvai_test']['caps'], true ); }
	public function add_cap( $cap ) { $GLOBALS['vvai_test']['caps'][ $cap ] = $cap; $GLOBALS['vvai_test']['caps'] = array_values( $GLOBALS['vvai_test']['caps'] ); }
	public function remove_cap( $cap ) {
		$GLOBALS['vvai_test']['caps'] = array_values(
			array_filter(
				(array) $GLOBALS['vvai_test']['caps'],
				static function ( $existing ) use ( $cap ) {
					return $existing !== $cap;
				}
			)
		);
	}
}

class VVAI_Test_User {
	public $ID;
	public $display_name;
	public $user_login;

	public function __construct( $id ) {
		$this->ID           = (int) $id;
		$this->display_name = 'Test User ' . (int) $id;
		$this->user_login   = 'test' . (int) $id;
	}

	public function has_cap( $cap, ...$args ) { return current_user_can( $cap ); }
	public function exists() { return $this->ID > 0; }
}

/**
 * Thrown by wp_die()/wp_send_json_* so a test can assert on transport output.
 */
class VVAI_Test_Halt extends Exception {}

/**
 * Invoke a registered REST route the way the server would, including the
 * permission_callback — which is exactly what the security tests assert.
 *
 * @param string $method HTTP method.
 * @param string $route  Concrete route, e.g. /vvai/v1/connections/conn_1/connect.
 * @param array  $params Query/body params.
 * @param array  $files  $_FILES-shaped array.
 * @return array{status:int,data:mixed}
 */
function vvai_test_rest( $method, $route, array $params = array(), array $files = array() ) {
	$method = strtoupper( $method );
	$choice = null;

	foreach ( VVAI_Test_REST_Server::$routes as $pattern => $definition ) {
		$entries = isset( $definition['callback'] ) ? array( $definition ) : $definition;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['callback'] ) ) {
				continue;
			}

			$regex = '#^' . vvai_test_route_regex_body( $pattern ) . '$#';

			if ( ! preg_match( $regex, $route ) ) {
				continue;
			}

			$methods = array_map( 'trim', explode( ',', strtoupper( (string) ( $entry['methods'] ?? 'GET' ) ) ) );

			if ( ! in_array( $method, $methods, true ) ) {
				continue;
			}

			$choice = $entry;
			break 2;
		}
	}

	if ( null === $choice ) {
		return array(
			'status' => 404,
			'data'   => array( 'code' => 'vvai_route_missing', 'message' => 'No route matches ' . $route ),
		);
	}

	$request = new VVAI_Test_REST_Request( $method, $route );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	foreach ( VVAI_Test_REST_Server::$routes as $pattern => $definition ) {
		$regex = '#^' . vvai_test_route_regex_body( $pattern ) . '$#';

		if ( preg_match( $regex, $route, $matches ) ) {
			foreach ( $matches as $key => $value ) {
				if ( ! is_int( $key ) ) {
					$request->set_param( $key, $value );
				}
			}

			break;
		}
	}

	if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
		$request->set_json_params( $params );
	}

	if ( $files ) {
		$request->set_file_params( $files );
	}

	if ( ! empty( $choice['permission_callback'] ) ) {
		$permission = $choice['permission_callback'];

		if ( is_string( $permission ) && function_exists( $permission ) && '__return_true' === $permission ) {
			$allowed = true;
		} elseif ( is_callable( $permission ) ) {
			$allowed = call_user_func( $permission, $request );
		} else {
			$allowed = false;
		}

		if ( is_wp_error( $allowed ) ) {
			$data = (array) $allowed->get_error_data();

			return array(
				'status' => (int) ( $data['status'] ?? 403 ),
				'data'   => array(
					'code'    => $allowed->get_error_code(),
					'message' => $allowed->get_error_message(),
					'data'    => $data,
				),
			);
		}

		if ( ! $allowed ) {
			return array( 'status' => 403, 'data' => array( 'message' => 'Forbidden' ) );
		}
	}

	try {
		$result = call_user_func( $choice['callback'], $request );
	} catch ( VVAI_Test_Halt $halt ) {
		// wp_send_json_* inside a handler.
		return array(
			'status' => isset( $GLOBALS['vvai_test']['json_out']['status'] ) ? (int) $GLOBALS['vvai_test']['json_out']['status'] : 200,
			'data'   => $GLOBALS['vvai_test']['json_out'],
		);
	}

	if ( is_wp_error( $result ) ) {
		$data = (array) $result->get_error_data();

		return array(
			'status' => (int) ( $data['status'] ?? 400 ),
			'data'   => array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data'    => $data,
			),
		);
	}

	if ( $result instanceof VVAI_Test_REST_Response ) {
		return array( 'status' => $result->get_status(), 'data' => $result->get_data() );
	}

	if ( null === $result ) {
		return array( 'status' => 204, 'data' => null );
	}

	return array( 'status' => 200, 'data' => $result );
}

// ---------------------------------------------------------------------------
// Core primitives
// ---------------------------------------------------------------------------

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_error_from_exception( \Throwable $throwable ) { return new WP_Error( 'exception', $throwable->getMessage() ); }
function trailingslashit( $string ) { return rtrim( (string) $string, '/\\' ) . '/'; }
function untrailingslashit( $string ) { return rtrim( (string) $string, '/\\' ); }
function path_join( $path, $more ) { return $path . '/' . ltrim( $more, '/' ); }
function wp_parse_pathinfo( $path ) { return pathinfo( (string) $path ); }
function wp_is_numeric_array( $data ) { return is_array( $data ) && array_keys( $data ) === range( 0, count( $data ) - 1 ); }
function wp_array_slice_assoc( $array, $keys ) { return array_intersect_key( $array, array_flip( $keys ) ); }
function wp_slash_deep() { return array(); }
function wp_check_php_mysql_versions() { return true; }
function wp_cache_add_domains() {}
function wp_login_url( $redirect = '', $force_reauth = false ) { return home_url( 'wp-login.php' ); }
function wp_logout_url( $redirect = '' ) { return home_url( 'wp-login.php?action=logout' ); }
function get_edit_post_link( $id = 0 ) { return admin_url( 'post.php?post=' . (int) $id . '&action=edit' ); }
function wp_get_attachment_link( $id = 0 ) { return ''; }
function esc_url_raw_relaxed( $url ) { return esc_url_raw( $url ); }
function wp_kses_allowed_html( $context = 'post' ) { return array(); }
function wp_installing_state() { return false; }
function wp_raise_memory_limit_value() {}
function wp_convert_hr_to_bytes( $value ) { return vvai_shorthand_to_bytes( $value ); }
function size_format_str( $bytes ) { return size_format( $bytes ); }
function wp_is_uuid( $uuid ) { return false; }
function wp_sprintf( $format, ...$args ) { return vsprintf( (string) $format, $args ); }
function _doing_it_wrong( $function, $message, $version ) { error_log( '[doing_it_wrong] ' . $function . ': ' . $message ); }
function _deprecated_function( $function, $version, $replacement = '' ) { error_log( '[deprecated] ' . $function ); }
function _prime_post_caches() {}
function clean_cache() {}
function wp_observe_memory_limit() {}
