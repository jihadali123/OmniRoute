<?php
/**
 * The single seam between the plugin and the operating system.
 *
 * Every external binary call (ffmpeg, ffprobe, whisper) goes through
 * VVAI_Process, which:
 *
 *  - builds a properly escaped command line from an argv array,
 *  - enforces a hard wall-clock timeout,
 *  - reports exit code + stdout + stderr without throwing,
 *  - can be re-routed to a remote worker (`vvai_process_runner`) so a future
 *    version can move rendering onto a dedicated processing node without any
 *    call-site change.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Process
 */
class VVAI_Process {

	/**
	 * Whether the server can execute external programs at all.
	 *
	 * @return array{available:bool,reason:string,methods:string[]}
	 */
	public static function capability() {
		$methods = array();

		foreach ( array( 'proc_open', 'exec', 'shell_exec', 'system', 'passthru' ) as $function ) {
			if ( function_exists( $function ) && ! vvai_function_disabled( $function ) ) {
				$methods[] = $function;
			}
		}

		$available = in_array( 'proc_open', $methods, true ) || in_array( 'exec', $methods, true );

		$reason = $available
			? __( 'PHP can start external processes.', 'viral-video-ai' )
			: __( 'PHP cannot start external processes: proc_open/exec are missing or listed in disable_functions. FFmpeg rendering will not work on this server.', 'viral-video-ai' );

		return array(
			'available' => $available,
			'reason'    => $reason,
			'methods'   => $methods,
		);
	}

	/**
	 * Run a command.
	 *
	 * @param string[]         $argv  argv array; $argv[0] is the binary.
	 * @param array{timeout?:int,stdin?:string,cwd?:string,env?:array<string,string>} $args Options.
	 * @return array{code:int,stdout:string,stderr:string,error:string,duration:float,command:string}
	 */
	public static function run( array $argv, array $args = array() ) {
		$argv = array_values( array_filter( $argv, static function ( $part ) {
			return is_scalar( $part ) && '' !== (string) $part;
		} ) );

		$result = array(
			'code'     => -1,
			'stdout'   => '',
			'stderr'   => '',
			'error'    => '',
			'duration' => 0.0,
			'command'  => implode( ' ', array_map( 'vvai_shell_arg', $argv ) ),
		);

		if ( ! $argv ) {
			$result['error'] = __( 'No command to run.', 'viral-video-ai' );

			return $result;
		}

		/**
		 * Replace the local process runner.
		 *
		 * Returning a non-null array hands the command to a custom runner — used
		 * by remote worker setups and by the automated test suite. The returned
		 * array must contain the `code`, `stdout`, `stderr` and `error` keys.
		 *
		 * @param array|null $override Override result (null = run locally).
		 * @param string[]   $argv       argv.
		 * @param array      $args       Options.
		 */
		$override = apply_filters( 'vvai_process_runner', null, $argv, $args );

		if ( is_array( $override ) ) {
			return wp_parse_args( $override, $result );
		}

		if ( count( $argv ) < 1 || ! self::binary_is_safe( $argv[0] ) ) {
			$result['error'] = __( 'Refusing to execute an unvalidated binary path.', 'viral-video-ai' );

			return $result;
		}

		$timeout = isset( $args['timeout'] ) ? max( 1, (int) $args['timeout'] ) : 300;
		$started = microtime( true );

		try {
			if ( function_exists( 'proc_open' ) && ! vvai_function_disabled( 'proc_open' ) ) {
				$result = self::run_with_proc_open( $argv, $args, $timeout, $result );
			} elseif ( function_exists( 'exec' ) && ! vvai_function_disabled( 'exec' ) ) {
				$result = self::run_with_exec( $argv, $timeout, $result, $args );
			} else {
				$result['error'] = __( 'This server does not allow PHP to run external commands (proc_open and exec are disabled).', 'viral-video-ai' );
			}
		} catch ( \Throwable $throwable ) {
			// A binary probe must never take a page down: report it as a failed run
			// so Diagnostics can show it and the pipeline can fail with a reason.
			$result['code']  = -1;
			$result['error'] = sprintf(
				/* translators: 1: error class, 2: message. */
				__( 'The server could not run the command (%1$s): %2$s', 'viral-video-ai' ),
				get_class( $throwable ),
				$throwable->getMessage()
			);
		}

		$result['duration'] = round( microtime( true ) - $started, 3 );

		/**
		 * Fires after an external process finished.
		 *
		 * @param array $result  Result.
		 * @param array $argv    argv.
		 * @param array $args    Options.
		 */
		do_action( 'vvai_process_finished', $result, $argv, $args );

		return $result;
	}

	/**
	 * Is the binary path acceptable to execute?
	 *
	 * Accepts either a bare command name (resolved through PATH by the shell)
	 * or an absolute path to an existing, readable file. Anything with shell
	 * metacharacters, relative traversals or a writable uploads path is
	 * rejected outright.
	 *
	 * @param string $binary Binary path or name.
	 * @return bool
	 */
	public static function binary_is_safe( $binary ) {
		$binary = (string) $binary;

		if ( '' === $binary || strlen( $binary ) > 500 ) {
			return false;
		}

		// Shell syntax is what must never reach a command line. Backslashes are
		// allowed because they are the Windows path separator — a real Windows
		// install cannot set C:\\ffmpeg\\bin\\ffmpeg.exe otherwise.
		if ( preg_match( '/[;&|`$><\n\r]/', $binary ) || false !== strpos( $binary, '"' ) || false !== strpos( $binary, "'" ) ) {
			return false;
		}

		$windows = (bool) preg_match( '#^[A-Za-z]:[\\\\/][^";\'\r\n<>|&`$]*$#', $binary );

		if ( $windows ) {
			if ( false !== strpos( str_replace( '\\', '/', $binary ), '..' ) ) {
				return false;
			}

			$resolved = wp_normalize_path( $binary );

			if ( ! is_file( $binary ) && ! is_file( $resolved ) ) {
				return false;
			}

			$uploads = wp_get_upload_dir();

			if ( 0 === strpos( $resolved, trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) ) ) {
				return false;
			}

			return true;
		}

		if ( preg_match( '#^/[A-Za-z0-9._/-]+$#', $binary ) ) {
			if ( false !== strpos( $binary, '..' ) ) {
				return false;
			}

			if ( ! is_file( $binary ) || ! is_readable( $binary ) ) {
				return false;
			}

			$uploads = wp_get_upload_dir();

			if ( 0 === strpos( wp_normalize_path( $binary ), wp_normalize_path( $uploads['basedir'] ) ) ) {
				return false;
			}

			return true;
		}

		return (bool) preg_match( '/^[A-Za-z0-9._-]+$/', $binary );
	}

	/**
	 * Is this an absolute path (POSIX or Windows)?
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_absolute( $path ) {
		$path = (string) $path;

		return 0 === strpos( $path, '/' ) || 0 === strpos( $path, '\\' ) || (bool) preg_match( '#^[A-Za-z]:[\\\\/]#', $path );
	}

	/**
	 * Resolve an absolute path for a binary name using PATH and the usual
	 * install locations.
	 *
	 * A bare name is only useful to PHP if PHP can actually see it: the PATH of
	 * a web server process regularly differs from the PATH of the terminal that
	 * installed the tool (Windows services, php-fpm with clear_env, Local by
	 * Flywheel). Discovery therefore asks VVAI_Binary_Locator, which scans PATH
	 * *plus* the conventional install folders and caches the answer. When
	 * nothing is found the original name is returned unchanged, so the shell
	 * still gets a chance to resolve it.
	 *
	 * @param string $binary Name or path.
	 * @return string Absolute path, or the original value when unresolvable.
	 */
	public static function locate( $binary ) {
		$binary = (string) $binary;

		if ( '' === $binary ) {
			return '';
		}

		if ( self::is_absolute( $binary ) ) {
			return $binary;
		}

		$bare = preg_replace( '/[^A-Za-z0-9._-]/', '', $binary );

		if ( '' === $bare ) {
			return $binary;
		}

		if ( class_exists( 'VVAI_Binary_Locator' ) ) {
			$found = VVAI_Binary_Locator::find( $bare );

			if ( '' !== $found ) {
				return $found;
			}
		}

		return $binary;
	}

	/**
	 * Is this file descriptor still a usable pipe?
	 *
	 * @param array<int,mixed> $pipes Pipe map from proc_open().
	 * @param int              $fd    Descriptor index.
	 * @return bool
	 */
	private static function is_pipe( $pipes, $fd ) {
		return isset( $pipes[ $fd ] ) && is_resource( $pipes[ $fd ] );
	}

	/**
	 * Read the remainder of a pipe, tolerating an already-closed one.
	 *
	 * @param array<int,mixed> $pipes Pipe map.
	 * @param int              $fd    Descriptor index.
	 * @return string
	 */
	private static function drain_pipe( $pipes, $fd ) {
		if ( ! self::is_pipe( $pipes, $fd ) ) {
			return '';
		}

		$rest = @stream_get_contents( $pipes[ $fd ] );

		return is_string( $rest ) ? $rest : '';
	}

	/**
	 * Close a pipe only when it is still open.
	 *
	 * @param array<int,mixed> $pipes Pipe map (by reference so the slot clears).
	 * @param int              $fd    Descriptor index.
	 */
	private static function close_pipe( &$pipes, $fd ) {
		if ( ! isset( $pipes[ $fd ] ) ) {
			return;
		}

		if ( is_resource( $pipes[ $fd ] ) ) {
			fclose( $pipes[ $fd ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- guarded by is_resource().
		}

		unset( $pipes[ $fd ] );
	}

	/**
	 * Working directory + environment for the child process.
	 *
	 * Shared FFmpeg builds (the default `ffmpeg.exe` in most Windows archives)
	 * load their sibling DLLs relative to the *current directory*, and some
	 * hosts start PHP with a PATH that does not contain the tool folder at all.
	 * Running the binary from its own directory with that directory prepended to
	 * PATH fixes both without weakening anything: the argv is still built from a
	 * validated absolute path.
	 *
	 * @param string[] $argv argv.
	 * @param array    $args Options.
	 * @return array{0:?string,1:?array<string,string>}
	 */
	private static function spawn_context( array $argv, array $args ) {
		$cwd = isset( $args['cwd'] ) && is_dir( (string) $args['cwd'] ) ? (string) $args['cwd'] : null;
		$env = ! empty( $args['env'] ) && is_array( $args['env'] ) ? $args['env'] : null;

		if ( null === $cwd ) {
			$binary = isset( $argv[0] ) ? (string) $argv[0] : '';

			if ( '' !== $binary && self::is_absolute( $binary ) ) {
				$dir = dirname( str_replace( '\\', '/', $binary ) );

				if ( '' !== $dir && '.' !== $dir && is_dir( $dir ) ) {
					$cwd = $dir;
				}
			}
		}

		if ( null === $env && null !== $cwd ) {
			$env = self::child_env( $cwd );
		}

		return array( $cwd, $env );
	}

	/**
	 * Environment for a child process: the parent's, with the tool folder first.
	 *
	 * proc_open() replaces the whole environment when an array is given, so the
	 * inherited values must be copied explicitly — a child without SystemRoot or
	 * TEMP cannot even start on Windows.
	 *
	 * @param string $dir Directory to prepend to PATH.
	 * @return array<string,string>
	 */
	private static function child_env( $dir ) {
		$env = array();

		$inherited = getenv();

		if ( is_array( $inherited ) ) {
			foreach ( $inherited as $key => $value ) {
				if ( is_string( $key ) && is_scalar( $value ) ) {
					$env[ $key ] = (string) $value;
				}
			}
		}

		if ( ! $env && is_array( $_ENV ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- environment passthrough.
			foreach ( $_ENV as $key => $value ) {
				if ( is_string( $key ) && is_scalar( $value ) ) {
					$env[ $key ] = (string) $value;
				}
			}
		}

		$path = isset( $env['PATH'] ) ? (string) $env['PATH'] : (string) getenv( 'PATH' );

		if ( '' === $path || false === strpos( $path, $dir ) ) {
			$path = '' === $path ? $dir : $dir . PATH_SEPARATOR . $path;
		}

		$env['PATH'] = $path;

		if ( class_exists( 'VVAI_Binary_Locator' ) && VVAI_Binary_Locator::is_windows() ) {
			foreach ( array( 'SystemRoot', 'windir', 'COMSPEC', 'TEMP', 'TMP' ) as $key ) {
				if ( empty( $env[ $key ] ) ) {
					$value = getenv( $key );

					if ( is_string( $value ) && '' !== $value ) {
						$env[ $key ] = $value;
					}
				}
			}

			if ( empty( $env['SystemRoot'] ) && is_dir( 'C:\\Windows' ) ) {
				$env['SystemRoot'] = 'C:\\Windows';
			}
		}

		/**
		 * Filter the environment handed to a child process.
		 *
		 * @param array  $env Environment map.
		 * @param string $dir Tool directory.
		 */
		return (array) apply_filters( 'vvai_process_env', $env, $dir );
	}

	/**
	 * proc_open based runner with separated stdout/stderr and a real timeout.
	 *
	 * @param string[] $argv    argv.
	 * @param array    $args    Options.
	 * @param int      $timeout Timeout in seconds.
	 * @param array    $result  Result skeleton.
	 * @return array
	 */
	private static function run_with_proc_open( array $argv, array $args, $timeout, array $result ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		list( $cwd, $env ) = self::spawn_context( $argv, $args );

		$pipes = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- this class is the audited place where it happens.
		$process = @proc_open( $result['command'], $descriptors, $pipes, $cwd, $env );

		if ( ! is_resource( $process ) ) {
			$result['error'] = __( 'The server refused to start the process.', 'viral-video-ai' );

			return $result;
		}

		if ( isset( $args['stdin'] ) && '' !== $args['stdin'] && self::is_pipe( $pipes, 0 ) ) {
			fwrite( $pipes[0], (string) $args['stdin'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- feeding stdin.
		}

		self::close_pipe( $pipes, 0 );

		// The child may have died immediately (binary missing, blocked exec, a
		// host that closes pipes early), so every pipe access is guarded.
		if ( self::is_pipe( $pipes, 1 ) ) {
			stream_set_blocking( $pipes[1], false );
		}

		if ( self::is_pipe( $pipes, 2 ) ) {
			stream_set_blocking( $pipes[2], false );
		}

		$stdout = '';
		$stderr = '';
		$deadline = microtime( true ) + $timeout;

		while ( true ) {
			$read  = array();
			$index = array();

			foreach ( array( 1 => 'out', 2 => 'err' ) as $fd => $slot ) {
				if ( self::is_pipe( $pipes, $fd ) ) {
					$read[ $slot ] = $pipes[ $fd ];
				}
			}

			$write  = null;
			$except = null;
			$ready  = $read ? @stream_select( $read, $write, $except, 1 ) : false;

			if ( is_int( $ready ) && $ready > 0 ) {
				foreach ( $read as $slot => $stream ) {
					$chunk = self::is_pipe( $pipes, 'out' === $slot ? 1 : 2 ) ? fread( $stream, 8192 ) : '';

					if ( false === $chunk || '' === $chunk ) {
						continue;
					}

					if ( 'out' === $slot ) {
						$stdout .= $chunk;
					} else {
						$stderr .= $chunk;
					}
				}
			}

			$status = is_resource( $process ) ? proc_get_status( $process ) : array( 'running' => false );

			if ( empty( $status['running'] ) ) {
				// Drain whatever is still buffered, then finish.
				$stdout .= self::drain_pipe( $pipes, 1 );
				$stderr .= self::drain_pipe( $pipes, 2 );
				break;
			}

			if ( microtime( true ) > $deadline ) {
				if ( is_resource( $process ) ) {
					proc_terminate( $process, 14 ); // SIGALRM: ffmpeg prints "Killed" instead of hanging silently.
				}

				usleep( 200000 );
				$stdout .= self::drain_pipe( $pipes, 1 );
				$stderr .= self::drain_pipe( $pipes, 2 );

				$status = is_resource( $process ) ? proc_get_status( $process ) : array( 'running' => false );

				if ( ! empty( $status['running'] ) && is_resource( $process ) ) {
					proc_terminate( $process, 9 );
				}

				$result['error'] = sprintf(
					/* translators: %d: timeout in seconds. */
					__( 'Process timed out after %d seconds and was terminated.', 'viral-video-ai' ),
					$timeout
				);

				break;
			}
		}

		$code = is_resource( $process ) ? proc_close( $process ) : -1;

		// proc_close() already closed the child's pipes. Calling fclose() on the
		// resulting dead resource raises "TypeError: supplied resource is not a
		// valid stream resource" on PHP 8 (seen on Windows/Local), so pipes are
		// closed only while they are still real resources.
		self::close_pipe( $pipes, 1 );
		self::close_pipe( $pipes, 2 );

		$result['stdout']  = $stdout;
		$result['stderr']  = $stderr;
		$result['code']    = ( $code < 0 ) ? 1 : (int) $code;
		$result['command'] = (string) $result['command'];

		return $result;
	}

	/**
	 * exec() fallback. stderr is merged into stdout because exec() cannot
	 * separate the streams; parsers therefore extract JSON from the payload
	 * instead of trusting it verbatim.
	 *
	 * @param string[] $argv    argv.
	 * @param int      $timeout Timeout in seconds.
	 * @param array    $result  Result skeleton.
	 * @param array    $args    Options (cwd is honoured here as a `cd` prefix).
	 * @return array
	 */
	private static function run_with_exec( array $argv, $timeout, array $result, array $args = array() ) {
		$command = $result['command'] . ' 2>&1';

		// Same reasoning as spawn_context(): run the tool from its own folder so
		// a shared build finds its sibling DLLs even when PATH is short.
		list( $cwd ) = self::spawn_context( $argv, $args );

		if ( null !== $cwd ) {
			$windows  = class_exists( 'VVAI_Binary_Locator' ) && VVAI_Binary_Locator::is_windows();
			$prefix   = $windows ? 'cd /d ' : 'cd ';
			$command  = $prefix . vvai_shell_arg( $cwd ) . ' && ' . $command;
		}
		$output  = array();
		$code    = 1;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- argv already escaped.
		@exec( $command, $output, $code );

		$result['stdout'] = implode( "\n", array_map( 'strval', (array) $output ) );
		$result['stderr'] = '';
		$result['code']   = (int) $code;

		if ( $timeout > 0 && 124 === (int) $code ) {
			$result['error'] = __( 'The process timed out.', 'viral-video-ai' );
		}

		return $result;
	}
}
