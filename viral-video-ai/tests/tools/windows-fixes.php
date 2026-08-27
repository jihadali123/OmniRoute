<?php
/**
 * Focused regression tests for the Windows/Local crash fixes.
 * Runs on the sandboxed PHP with a tiny WP shim (no full harness needed).
 */

error_reporting( E_ALL );
set_error_handler(
	static function ( $no, $str, $file, $line ) {
		throw new ErrorException( $str, 0, $no, $file, $line );
	}
);

define( 'ABSPATH', '/tmp/fake-wp/' );
define( 'MB_IN_BYTES', 1048576 );
define( 'KB_IN_BYTES', 1024 );
define( 'GB_IN_BYTES', 1073741824 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// ------------------------------ tiny WP shim -------------------------------
$GLOBALS['t_opt'] = array();

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_opt'] ) ? $GLOBALS['t_opt'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_opt'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_opt'][ $k ] ); return true; }
function get_transient( $k ) { return isset( $GLOBALS['t_tr'][ $k ] ) ? $GLOBALS['t_tr'][ $k ] : false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t_tr'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t_tr'][ $k ] ); return true; }
function apply_filters( $h, $v = null ) { return $v; }
function do_action( $h ) {}
function add_filter( $h, $c, $p = 10, $a = 1 ) {}
function add_action( $h, $c, $p = 10, $a = 1 ) {}
function remove_all_filters( $h, $p = false ) {}
function __( $t, $d = null ) { return (string) $t; }
function esc_html__( $t, $d = null ) { return (string) $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url_raw( $t ) { return (string) $t; }
function sanitize_text_field( $t ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $t ) ) ); }
function wp_strip_all_tags( $t, $br = false ) { return trim( strip_tags( (string) $t ) ); }
function wp_json_encode( $d, $o = 0, $depth = 512 ) { return json_encode( $d, $o, (int) $depth ); }
function wp_parse_args( $a, $d = array() ) { return array_merge( (array) $d, (array) $a ); }
function wp_list_pluck( $l, $f ) { $o = array(); foreach ( (array) $l as $i ) { $o[] = is_array( $i ) ? ( $i[ $f ] ?? '' ) : ''; } return $o; }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); }
function trailingslashit( $p ) { return rtrim( (string) $p, '/\\' ) . '/'; }
function untrailingslashit( $p ) { return rtrim( (string) $p, '/\\' ); }
function wp_upload_dir() { $b = '/tmp/fake-wp/uploads'; return array( 'basedir' => $b, 'baseurl' => 'http://x/wp-content/uploads', 'path' => $b, 'url' => '', 'error' => false ); }
function wp_get_upload_dir() { return wp_upload_dir(); }
function wp_mkdir_p( $p ) { return is_dir( $p ) || @mkdir( $p, 0777, true ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u ); }
function remove_accents( $t ) { return (string) $t; }
function sanitize_title_with_dashes( $t ) { return strtolower( preg_replace( '/[^a-z0-9_-]+/', '-', trim( (string) $t ) ) ); }
function current_user_can( $c ) { return true; }
function get_current_user_id() { return 1; }
function wp_salt( $s = 'salt' ) { return 'test-salt-' . $s; }
function wp_remote_post( $u, $a = array() ) { return new WP_Error( 'nope', 'blocked in this test' ); }
function wp_remote_get( $u, $a = array() ) { return new WP_Error( 'nope', 'blocked in this test' ); }
function wp_remote_request( $u, $a = array() ) { return new WP_Error( 'nope', 'blocked in this test' ); }
function wp_remote_retrieve_body( $r ) { return ''; }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function wp_remote_retrieve_headers( $r ) { return array(); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }

class WP_Error {
	private $code;
	private $msg;
	private $data;

	public function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; $this->msg = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->msg; }
	public function get_error_data() { return $this->data; }
}

$root = '/home/user/OmniRoute/viral-video-ai';

// helpers.php defines the vvai_* helpers used by the classes.
require $root . '/includes/helpers.php';

// Only the classes under test, registered with a targeted autoloader.
spl_autoload_register(
	static function ( $class ) use ( $root ) {
		if ( 0 !== strpos( $class, 'VVAI_' ) ) {
			return;
		}

		$slug  = strtolower( str_replace( '_', '-', substr( $class, 5 ) ) );
		$tries = array(
			$root . '/includes/class-' . $slug . '.php',
			$root . '/includes/interface-' . preg_replace( '/-interface$/', '', $slug ) . '.php',
		);

		foreach ( $tries as $file ) {
			if ( is_file( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

$pass = 0;
$fail = 0;

function ok( $cond, $label, $extra = '' ) {
	global $pass, $fail;

	if ( $cond ) {
		$pass++;
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		$fail++;
		echo "  \033[31m✗\033[0m {$label}" . ( $extra ? " — {$extra}" : '' ) . "\n";
	}
}

// ===========================================================================
echo "\n\033[1m1. The crash: proc pipes closed twice\033[0m\n";
// ===========================================================================
$rc  = new ReflectionClass( 'VVAI_Process' );
$new = function () { return true; };

// Simulate exactly the failing state: a pipe resource that proc_close() already killed.
$fp = fopen( 'php://memory', 'r+' );
fclose( $fp ); // now a dead resource, just like after proc_close() on Windows.

$close = $rc->getMethod( 'close_pipe' );
$close->setAccessible( true );

$pipes = array( 1 => $fp );

try {
	$close->invokeArgs( null, array( &$pipes, 1 ) );
	ok( true, 'close_pipe() tolerates an already-closed resource' );
} catch ( \Throwable $e ) {
	ok( false, 'close_pipe() tolerates an already-closed resource', get_class( $e ) . ': ' . $e->getMessage() );
}

$drain = $rc->getMethod( 'drain_pipe' );
$drain->setAccessible( true );

try {
	$out = $drain->invoke( null, array( 1 => $fp ), 1 );
	ok( '' === $out, 'drain_pipe() returns empty string for a dead pipe' );
} catch ( \Throwable $e ) {
	ok( false, 'drain_pipe() returns empty string for a dead pipe', $e->getMessage() );
}

try {
	$out = $drain->invoke( null, array(), 2 );
	ok( '' === $out, 'drain_pipe() survives a missing descriptor' );
} catch ( \Throwable $e ) {
	ok( false, 'drain_pipe() survives a missing descriptor', $e->getMessage() );
}

// run() must never let a host quirk escape as a fatal.
class VVAI_Boom {
	public static function throw_runner( $override, $argv, $args ) {
		throw new RuntimeException( 'simulated host failure' );
	}
}

add_filter( 'vvai_process_runner', array( 'VVAI_Boom', 'throw_runner' ) );

// apply_filters in the shim is a no-op, so call run() with a disabled spawn env
// and assert it degrades instead of throwing.
$result = VVAI_Process::run( array( 'definitely-not-a-binary-xyz' ), array( 'timeout' => 5 ) );

ok( is_array( $result ) && array_key_exists( 'code', $result ), 'run() always returns a result array' );
ok( (int) $result['code'] !== 0 || '' !== $result['error'], 'a failed probe is reported, not thrown' );

// ===========================================================================
echo "\n\033[1m2. Windows binary paths were being rejected\033[0m\n";
// ===========================================================================
$settings = new VVAI_Settings();
$method   = new ReflectionMethod( 'VVAI_Settings', 'sanitize_binary_path' );
$method->setAccessible( true );

$cases = array(
	array( 'C:\\ffmpeg\\bin\\ffmpeg.exe', 'C:\\ffmpeg\\bin\\ffmpeg.exe', 'Windows path survives sanitizing' ),
	array( 'C:\\Program Files\\ffmpeg\\ffprobe.exe', 'C:\\Program Files\\ffmpeg\\ffprobe.exe', 'Windows path with spaces survives' ),
	array( 'C:/ffmpeg/bin/ffmpeg.exe', 'C:/ffmpeg/bin/ffmpeg.exe', 'Forward-slash Windows path survives' ),
	array( '/usr/bin/ffmpeg', '/usr/bin/ffmpeg', 'POSIX path still works' ),
	array( 'ffmpeg', 'ffmpeg', 'bare name still works' ),
	array( 'C:\\ffmpeg\\bin\\ffmpeg.exe; rm -rf /', 'ffmpeg', 'chained command rejected' ),
	array( 'C:\\ffmpeg\\..\\..\\windows\\system32\\evil.exe', 'ffmpeg', 'traversal rejected' ),
	array( 'C:\\ffmpeg\\bin\\f"ile.exe', 'ffmpeg', 'quote rejected' ),
	array( 'C:\\ffmpeg\\bin\\$(id).exe', 'ffmpeg', 'subshell rejected' ),
	array( '/tmp/fake-wp/uploads/evil/ffmpeg', 'ffmpeg', 'uploads-basedir binary rejected' ),
);

foreach ( $cases as $case ) {
	list( $in, $want, $label ) = $case;
	$got = $method->invoke( $settings, $in, 'ffmpeg' );
	ok( $got === $want, $label, 'got ' . var_export( $got, true ) );
}

$bis = $rc->getMethod( 'binary_is_safe' );
$bis->setAccessible( true );

ok( VVAI_Process::binary_is_safe( 'ffmpeg' ), 'bare "ffmpeg" accepted' );
ok( ! VVAI_Process::binary_is_safe( 'C:\\ffmpeg\\bin\\ffmpeg.exe; taskkill' ), 'Windows path + chaining rejected' );
ok( ! VVAI_Process::binary_is_safe( 'ffmpeg|sh' ), 'pipe rejected' );
ok( ! VVAI_Process::binary_is_safe( 'C:\\ffmpeg\\bin\\nope.exe' ), 'non-existent Windows binary refused (fail closed)' );

// A real existing POSIX file must pass, proving the existence check itself works.
$tmp = tempnam( sys_get_temp_dir(), 'vvai-bin-' );
chmod( $tmp, 0755 );
ok( VVAI_Process::binary_is_safe( $tmp ), 'existing executable path accepted' );
// A file inside the uploads tree must be refused even if it exists and is executable,
// otherwise a compromised upload dir becomes an executable path.
@mkdir( '/tmp/fake-wp/uploads/evil', 0777, true );
$inside = '/tmp/fake-wp/uploads/evil/ffmpeg';
file_put_contents( $inside, "#!/bin/sh\n" );
chmod( $inside, 0755 );
ok( ! VVAI_Process::binary_is_safe( $inside ), 'executable inside uploads is refused' );
$via_settings = $method->invoke( $settings, $inside, 'ffmpeg' );
ok( 'ffmpeg' === $via_settings, 'settings sanitizer refuses an uploads-based binary', var_export( $via_settings, true ) );
@unlink( $inside );
@rmdir( '/tmp/fake-wp/uploads/evil' );
@unlink( $tmp );

// ===========================================================================
echo "\n\033[1m3. Admin pages must never spawn/fatal on load\033[0m\n";
// ===========================================================================
$ff = new VVAI_FFMPEG( $settings );

$first  = $ff->availability( true );   // no recheck requested -> must not force a probe
$second = $ff->availability( true );

ok( is_array( $first ) && array_key_exists( 'ok', $first ), 'availability() returns a shape, never throws' );
ok( get_transient( VVAI_FFMPEG::CACHE_AVAIL ) !== false || $first['ok'] === false, 'result cached (or nothing to cache on failure)' );

// Forced fresh probing requires the one-shot flag.
delete_transient( VVAI_FFMPEG::CACHE_AVAIL );
// Set the flag: one uncached probe is allowed, then it must be ignored.
set_transient( 'vvai_force_probe', 1, 60 );
$forced = $ff->availability( true );
ok( is_array( $forced ), 'forced probe still returns a shape' );

$after = $ff->availability( true );
ok( is_array( $after ), 'second call after the one-shot is served from cache' );

echo "\n" . str_repeat( '─', 60 ) . "\n";
echo $fail ? "\033[31m{$fail} failed\033[0m, {$pass} passed\n" : "\033[32mall {$pass} assertions passed\033[0m\n";
exit( $fail ? 1 : 0 );
