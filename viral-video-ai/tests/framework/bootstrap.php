<?php
/**
 * Test bootstrap: loads the WordPress shim, the plugin, and installs the tables.
 *
 * Every plugin class runs for real here — same files that ship in the ZIP.
 */

require_once __DIR__ . '/wp-shim.php';

define( 'VVAI_TEST_ROOT', dirname( __DIR__ ) );
/**
 * Environment for the harness.
 *
 * The WebAssembly PHP runtime does not inherit the host environment, so the node
 * runner forwards what it knows through a small JSON file; a normal `php` CLI run
 * just uses getenv().
 *
 * @param string $key     Variable name.
 * @param string $default Fallback.
 * @return string
 */
function vvai_test_env( $key, $default = '' ) {
	$value = getenv( $key );

	if ( is_string( $value ) && '' !== $value ) {
		return $value;
	}

	if ( isset( $_SERVER[ $key ] ) && '' !== (string) $_SERVER[ $key ] ) {
		return (string) $_SERVER[ $key ];
	}

	static $forwarded = null;

	if ( null === $forwarded ) {
		$file       = is_readable( '/tmp/__harness_args.json' ) ? '/tmp/__harness_args.json' : ( sys_get_temp_dir() . '/__harness_args.json' );
		$forwarded = is_readable( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array();
		$forwarded = (array) vvai_array_get( $forwarded, 'env', array() );
	}

	$forwarded_value = (string) vvai_array_get( $forwarded, $key, '' );

	// An empty forwarded value means "not set": falling back to the default keeps a
	// caller that forwards a blank environment from disabling the harness.
	if ( '' !== $forwarded_value ) {
		return $forwarded_value;
	}

	return (string) $default;
}


define( 'VVAI_PLUGIN_UNDER_TEST', getenv( 'VVAI_PLUGIN_DIR' ) ?: dirname( __DIR__, 2 ) );

// Fresh database per run.
$GLOBALS['wpdb'] = new VVAI_Test_WPDB( 'memory' );

// The plugin expects an uploads dir; give it a real, writable one.
vvai_test_uploads_dir();

require_once VVAI_PLUGIN_UNDER_TEST . '/viral-video-ai.php';

/**
 * Simulate a WordPress request lifecycle for the plugin.
 */
function vvai_test_boot( array $options = array() ) {
	$GLOBALS['vvai_test']['is_admin'] = ! empty( $options['admin'] );

	do_action( 'plugins_loaded' );

	VVAI_Activator::activate();

	vvai_test_configure( (array) ( $options['settings'] ?? array() ) );

	// Route every external binary through the exec bridge: the tests then drive
	// the real FFmpeg with the plugin's real argv arrays.
	if ( false === ( $options['bridge'] ?? true ) || '' !== vvai_test_env( 'VVAI_NO_BRIDGE' ) ) {
		// disabled by the caller
	} else {
		vvai_test_enable_ffmpeg_bridge( $options['bridge'] ?? 'http://127.0.0.1:8799/exec' );
	}

	do_action( 'init' );
	do_action( 'rest_api_init' );

	if ( ! empty( $options['admin'] ) ) {
		do_action( 'admin_menu' );
	}

	// Activate hooks registered on init by the plugin container.
	return vvai();
}

/**
 * Route VVAI_Process through the exec bridge so FFmpeg runs for real.
 */
function vvai_test_enable_ffmpeg_bridge( $bridge = 'http://127.0.0.1:8799/exec' ) {
	add_filter(
		'vvai_process_runner',
		static function ( $override, $argv, $args ) use ( $bridge ) {
			$response = wp_remote_post(
				$bridge,
				array(
					'timeout' => max( 30, (int) ( $args['timeout'] ?? 60 ) ),
					'json'    => array(
						'argv'    => $argv,
						'timeout' => (int) ( $args['timeout'] ?? 60 ),
						'cwd'     => isset( $args['cwd'] ) ? $args['cwd'] : null,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'code'   => -1,
					'stdout' => '',
					'stderr' => '',
					'error'  => 'exec bridge unreachable: ' . $response->get_error_message(),
				);
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $decoded ) ) {
				return array(
					'code'   => -1,
					'stdout' => '',
					'stderr' => '',
					'error'  => 'exec bridge returned no JSON',
				);
			}

			return array(
				'code'   => isset( $decoded['code'] ) ? (int) $decoded['code'] : 1,
				'stdout' => (string) ( $decoded['stdout'] ?? '' ),
				'stderr' => (string) ( $decoded['stderr'] ?? '' ),
				'error'  => (string) ( $decoded['error'] ?? '' ),
			);
		},
		10,
		3
	);
}

/**
 * Allow chunk files that PHP staged in the temp directory.
 *
 * `is_uploaded_file()` is only true for a real browser POST; the tests hand the
 * plugin a staged file, which is exactly the case the plugin's documented seam
 * exists for. Paths outside the system temp dir stay refused.
 */
add_filter(
	'vvai_upload_part_accepted',
	static function ( $accepted, $temp_file ) {
		if ( $accepted ) {
			return true;
		}

		$real = is_string( $temp_file ) ? realpath( $temp_file ) : false;
		$tmp  = realpath( sys_get_temp_dir() );

		return ( $real && $tmp && 0 === strpos( $real, $tmp . '/' ) );
	},
	10,
	2
);

/**
 * Configure the plugin for tests (paths, limits, no network unless asked).
 */
function vvai_test_configure( array $settings = array() ) {
	$defaults = array(
		'ffmpeg_path'          => vvai_test_env( 'VVAI_FFMPEG', 'ffmpeg' ),
		'ffprobe_path'         => vvai_test_env( 'VVAI_FFPROBE', 'ffprobe' ),
		'debug_log'            => true,
		'process_timeout'      => 180,
		'max_execution_budget' => 600,
		'transcription_source' => 'custom',
		'transcription_base_url' => 'http://127.0.0.1:8791/v1',
		'transcription_api_key'  => 'mock-key',
		'transcription_model'    => 'whisper-1',
		'auto_start_job'       => false,
		'clip_retention_days'  => 14,
		'temp_retention_hours' => 6,
		'allow_fallback'       => false,
		'require_login'        => true,
		'max_clips'            => 5,
	);

	$stored = get_option( VVAI_Settings::OPTION_KEY, array() );
	update_option( VVAI_Settings::OPTION_KEY, array_merge( (array) $stored, $defaults, $settings ), 'yes' );

	delete_transient( VVAI_FFMPEG::CACHE_AVAIL );
}

/**
 * Tiny assertion framework with a summary, so `exit code` reflects reality.
 */
/**
 * Create a throwaway folder holding pretend FFmpeg binaries.
 *
 * The files are real shell scripts (the exec bridge runs them), so discovery,
 * verification and probing are all exercised against actual process output
 * instead of a mocked return value.
 *
 * @param string $tag        Name suffix (keeps parallel runs apart).
 * @param string $banner     Banner the fake ffmpeg should print for -version.
 * @param bool   $with_probe Whether to write an ffprobe twin as well.
 * @return string Absolute folder path.
 */
function vvai_test_fake_bin_dir( $tag, $banner = '', $with_probe = true ) {
	$dir = sys_get_temp_dir() . '/vvai-' . $tag . '-' . substr( md5( uniqid( (string) microtime( true ), true ) ), 0, 8 );

	@mkdir( $dir, 0777, true );

	if ( '' !== $banner ) {
		$script = "#!/bin/sh\nif [ \"$1\" = \"-version\" ]; then echo \"" . $banner . "\"; exit 0; fi\nexit 1\n";

		file_put_contents( $dir . '/ffmpeg', $script );
		chmod( $dir . '/ffmpeg', 0755 );

		if ( $with_probe ) {
			$probe = "#!/bin/sh\nif [ \"$1\" = \"-version\" ]; then echo \"" . str_replace( 'ffmpeg', 'ffprobe', $banner ) . "\"; exit 0; fi\nexit 1\n";

			file_put_contents( $dir . '/ffprobe', $probe );
			chmod( $dir . '/ffprobe', 0755 );
		}
	}

	return $dir;
}

/**
 * Delete a folder produced by vvai_test_fake_bin_dir().
 *
 * @param string $dir Path.
 */
function vvai_test_remove_bin_dir( $dir ) {
	foreach ( (array) @scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		@unlink( is_file( $dir . '/' . $entry ) ? $dir . '/' . $entry : $dir . '/' . $entry );
	}

	@rmdir( $dir );
}

class VVAI_Test_Runner {
	public $passed = 0;
	public $failed = 0;
	public $skipped = 0;
	public $current = '';
	public $failures = array();
	private $started_at;
	private $section = '';

	public function section( $name ) {
		$this->section = $name;
		echo "\n\033[1m" . $name . "\033[0m\n";
	}

	public function test( $name, callable $body ) {
		$this->current = $name;

		try {
			$body();
			echo "  \033[32m✓\033[0m " . $name . "\n";
		} catch ( VVAI_Test_Assertion $assertion ) {
			$this->failed++;
			$this->failures[] = $name . ': ' . $assertion->getMessage();
			echo "  \033[31m✗\033[0m " . $name . "\n      " . $assertion->getMessage() . "\n";
		} catch ( \Throwable $throwable ) {
			$this->failed++;
			$this->failures[] = $name . ': ' . get_class( $throwable ) . ' — ' . $throwable->getMessage() . ' @ ' . $throwable->getFile() . ':' . $throwable->getLine();
			echo "  \033[31m✗\033[0m " . $name . "\n      " . get_class( $throwable ) . ': ' . $throwable->getMessage() . "\n      " . $throwable->getFile() . ':' . $throwable->getLine() . "\n";
		}
	}

	public function skip( $name, $reason = '' ) {
		$this->skipped++;
		echo "  \033[33m–\033[0m " . $name . ( $reason ? ' (' . $reason . ')' : '' ) . "\n";
	}

	public function assert( $condition, $message ) {
		if ( ! $condition ) {
			throw new VVAI_Test_Assertion( $message );
		}

		$this->passed++;
	}

	public function same( $expected, $actual, $message = '' ) {
		if ( $expected !== $actual ) {
			throw new VVAI_Test_Assertion(
				( $message ? $message . ' — ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
			);
		}

		$this->passed++;
	}

	public function contains( $needle, $haystack, $message = '' ) {
		if ( false === strpos( (string) $haystack, (string) $needle ) ) {
			throw new VVAI_Test_Assertion( ( $message ? $message . ' — ' : '' ) . 'expected to find "' . $needle . '" in: ' . substr( (string) $haystack, 0, 400 ) );
		}

		$this->passed++;
	}

	public function between( $min, $max, $value, $message = '' ) {
		if ( ! is_numeric( $value ) || $value < $min || $value > $max ) {
			throw new VVAI_Test_Assertion( ( $message ? $message . ' — ' : '' ) . $value . ' is not between ' . $min . ' and ' . $max );
		}

		$this->passed++;
	}

	public function summary() {
		$total = $this->passed + $this->failed;

		echo "\n" . str_repeat( '─', 64 ) . "\n";

		if ( $this->failed ) {
			echo "\033[31m" . $this->failed . " failed\033[0m, \033[32m" . $this->passed . " assertions passed\033[0m, " . $this->skipped . " skipped\n";

			foreach ( $this->failures as $failure ) {
				echo '  - ' . $failure . "\n";
			}

			return 1;
		}

		echo "\033[32mall " . $this->passed . " assertions passed\033[0m (" . count( $this->failures ) . " failures), " . $this->skipped . " skipped\n";

		return 0;
	}
}

class VVAI_Test_Assertion extends Exception {}

/**
 * Build a fake multi-window source video with real content, so the clips have
 * something meaningful to contain (colour bars + tone + timecode burn-in).
 *
 * @return array{path:string,duration:float,width:int,height:int}
 */
function vvai_test_make_source_video( $duration = 26.0, $width = 640, $height = 360 ) {
	$directory = sys_get_temp_dir() . '/vvai-fixtures';

	@mkdir( $directory, 0777, true );

	$path = sprintf( '%s/source-%dx%d-%ds.mp4', $directory, $width, $height, (int) $duration );

	if ( is_file( $path ) && filesize( $path ) > 1024 ) {
		return array(
			'path'     => $path,
			'duration' => (float) $duration,
			'width'    => $width,
			'height'   => $height,
		);
	}

	$ffmpeg = vvai_test_env( 'VVAI_FFMPEG', 'ffmpeg' );

	// The sandboxed PHP cannot spawn: ask the exec bridge to build the fixture.
	$argv = array(
		$ffmpeg,
		'-hide_banner',
		'-loglevel', 'error',
		'-y',
		'-f', 'lavfi',
		'-i', sprintf( 'testsrc=size=%dx%d:rate=15:duration=%s', (int) $width, (int) $height, (string) (float) $duration ),
		'-f', 'lavfi',
		'-i', sprintf( 'sine=frequency=440:duration=%s', (string) (float) $duration ),
		'-pix_fmt', 'yuv420p',
		'-c:v', 'libx264',
		'-preset', 'ultrafast',
		'-crf', '32',
		'-c:a', 'aac',
		'-b:a', '48k',
		'-shortest',
		'-movflags', '+faststart',
		$path,
	);

	$bridge = vvai_test_env( 'VVAI_BRIDGE', 'http://127.0.0.1:8799/exec' );
	$result = array();

	if ( function_exists( 'exec' ) && ! defined( 'VVAI_IN_WASM' ) ) {
		// Real PHP CLI: run it directly.
		$command = implode( ' ', array_map( 'escapeshellarg', $argv ) ) . ' 2>&1';
		$output   = array();
		$code     = 0;
		@exec( $command, $output, $code );

		$result = array( 'code' => $code, 'stdout' => implode( "\n", $output ) );
	} else {
		$bridge = vvai_test_env( 'VVAI_BRIDGE', 'http://127.0.0.1:8799/exec' );

		$response = wp_remote_post(
			$bridge,
			array(
				'timeout' => 180,
				'json'    => array(
					'argv'    => $argv,
					'timeout' => 180,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'fixture: exec bridge unreachable — ' . $response->get_error_message() );
		}

		$result = (array) json_decode( wp_remote_retrieve_body( $response ), true );
	}

	if ( ! is_file( $path ) || 0 !== (int) ( $result['code'] ?? 1 ) ) {
		throw new RuntimeException( 'fixture video could not be created: ' . substr( (string) ( $result['stdout'] ?? '' ) . (string) ( $result['stderr'] ?? '' ), 0, 500 ) );
	}

	return array(
		'path'     => $path,
		'duration' => (float) $duration,
		'width'    => $width,
		'height'   => $height,
	);
}

/**
 * A deterministic, timestamped transcript of the fixture video.
 *
 * @return array<int,array{start:float,end:float,text:string}>
 */
function vvai_test_transcript( $duration = 26.0 ) {
	$lines = array(
		'I am going to show you the one trick that changed everything for me.',
		'Nobody talks about this, but the numbers do not lie at all today.',
		'Watch what happens when I turn the dial all the way up to eleven.',
		'That is the moment everyone remembers afterwards, guaranteed.',
		'You can copy this exactly, and the results show up within a week.',
	);

	$segments = array();
	$count    = count( $lines );
	$each     = $duration / $count;

	foreach ( $lines as $index => $line ) {
		$segments[] = array(
			'start' => round( $index * $each, 2 ),
			'end'   => round( ( $index + 1 ) * $each - 0.2, 2 ),
			'text'  => $line,
		);
	}

	return $segments;
}
