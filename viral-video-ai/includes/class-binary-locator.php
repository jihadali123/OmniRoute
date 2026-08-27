<?php
/**
 * Binary discovery.
 *
 * "FFmpeg is installed" and "PHP can find FFmpeg" are two different things.
 * On Windows (Local by Flywheel, XAMPP, IIS) and on many shared hosts the PHP
 * process does not inherit the PATH a human sees in a terminal, so a bare
 * `ffmpeg` lookup fails even though C:\ffmpeg\bin\ffmpeg.exe is sitting right
 * there. This class answers the question the plugin actually needs:
 * *which absolute file on this machine is a working FFmpeg binary?*
 *
 * Rules:
 *  - only files that exist, are readable, and are outside the web-writable
 *    uploads tree are ever returned,
 *  - every candidate must still pass VVAI_Process::binary_is_safe(),
 *  - a candidate is only "verified" when its own `-version` banner identifies
 *    it as FFmpeg/FFprobe — a renamed or arbitrary executable is rejected,
 *  - nothing here shells out unless the server allows process execution, and a
 *    failed lookup is cached so a page load never scans the disk repeatedly.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Binary_Locator
 */
class VVAI_Binary_Locator {

	/**
	 * Transient holding the discovery result (name => absolute path | '').
	 */
	const CACHE_TRANSIENT = 'vvai_binary_discovery';

	/**
	 * How long a discovery result stays trusted.
	 */
	const CACHE_TTL = 21600;

	/**
	 * Hard cap on how many candidate files one lookup will consider.
	 */
	const MAX_CANDIDATES = 12;

	/**
	 * Per-request memo so a single page load scans the disk once at most.
	 *
	 * @var array<string,string>
	 */
	private static $memo = array();

	/**
	 * Are we on Windows? (Path shapes and executable suffixes differ.)
	 *
	 * @return bool
	 */
	public static function is_windows() {
		$family = defined( 'PHP_OS_FAMILY' ) ? PHP_OS_FAMILY : PHP_OS;

		return (bool) preg_match( '/^win/i', (string) $family );
	}

	/**
	 * Filenames to try for a logical binary name.
	 *
	 * @param string $base Logical name (ffmpeg, ffprobe, whisper).
	 * @return string[]
	 */
	public static function names( $base ) {
		$base = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $base );

		if ( '' === $base ) {
			return array();
		}

		$names = array( $base );

		if ( self::is_windows() ) {
			$names = array( $base . '.exe', $base . '.bat', $base . '.cmd', $base );
		}

		/**
		 * Filter the filenames probed for a logical binary.
		 *
		 * @param array  $names Filenames.
		 * @param string $base  Logical name.
		 */
		return array_values( array_unique( (array) apply_filters( 'vvai_binary_names', $names, $base ) ) );
	}

	/**
	 * Read an environment variable, tolerating case differences on Windows.
	 *
	 * @param string $key Variable name.
	 * @return string
	 */
	public static function env( $key ) {
		$value = getenv( $key );

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return $value;
		}

		foreach ( array( $_ENV, $_SERVER ) as $bag ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- environment, never echoed raw.
			if ( ! is_array( $bag ) ) {
				continue;
			}

			foreach ( $bag as $name => $candidate ) {
				if ( is_string( $name ) && 0 === strcasecmp( $name, $key ) && is_scalar( $candidate ) && '' !== trim( (string) $candidate ) ) {
					return (string) $candidate;
				}
			}
		}

		return '';
	}

	/**
	 * Directories taken from PATH.
	 *
	 * @return string[]
	 */
	public static function path_dirs() {
		$raw = self::env( 'PATH' );

		if ( '' === $raw ) {
			return array();
		}

		$dirs = array();

		foreach ( explode( PATH_SEPARATOR, $raw ) as $dir ) {
			$dir = trim( (string) $dir, " \t\n\r\0\x0B\"'" );

			if ( '' !== $dir ) {
				$dirs[] = $dir;
			}
		}

		return $dirs;
	}

	/**
	 * Where FFmpeg is conventionally installed on this platform.
	 *
	 * @return string[]
	 */
	public static function system_dirs() {
		if ( self::is_windows() ) {
			$systemroot   = self::env( 'SystemRoot' ) ?: 'C:\\Windows';
			$programfiles = self::env( 'ProgramFiles' ) ?: 'C:\\Program Files';
			$programfiles32 = self::env( 'ProgramFiles(x86)' ) ?: 'C:\\Program Files (x86)';
			$programdata  = self::env( 'ProgramData' ) ?: 'C:\\ProgramData';
			$local          = self::env( 'LOCALAPPDATA' );
			$profile        = self::env( 'USERPROFILE' );

			$dirs = array(
				$systemroot . '\\System32',
				'C:\\ffmpeg\\bin',
				'C:\\ffmpeg',
				'C:\\tools\\ffmpeg\\bin',
				'C:\\Program Files\\ffmpeg\\bin',
				$programfiles . '\\ffmpeg\\bin',
				$programfiles32 . '\\ffmpeg\\bin',
				$programdata . '\\chocolatey\\bin',
				$programdata . '\\community.chocolatey\\bin',
			);

			if ( '' !== $local ) {
				$dirs[] = $local . '\\Microsoft\\WinGet\\Links';
				$dirs[] = $local . '\\ffmpeg\\bin';
			}

			if ( '' !== $profile ) {
				$dirs[] = $profile . '\\scoop\\shims';
				$dirs[] = $profile . '\\ffmpeg\\bin';
			}

			$dirs[] = $systemroot;
		} else {
			$dirs = array(
				'/usr/local/bin',
				'/usr/bin',
				'/bin',
				'/usr/sbin',
				'/usr/local/sbin',
				'/sbin',
				'/opt/homebrew/bin',
				'/opt/local/bin',
				'/snap/bin',
				'/usr/local/ffmpeg/bin',
				'/opt/ffmpeg/bin',
			);
		}

		return array_values( array_filter( array_map( 'strval', $dirs ) ) );
	}

	/**
	 * Every directory worth probing, in priority order.
	 *
	 * Includes the administrator-configured "FFmpeg folder", a `bin` folder next
	 * to wp-content, and `bin` inside the plugin (a documented escape hatch for
	 * hosts where nothing can be installed system-wide).
	 *
	 * @return string[]
	 */
	public static function search_dirs() {
		$settings = wp_parse_args( (array) get_option( VVAI_Settings::OPTION_KEY, array() ), VVAI_Settings::defaults() );
		$dirs     = array();

		$configured_dir = trim( (string) vvai_array_get( $settings, 'ffmpeg_dir', '' ) );

		if ( '' !== $configured_dir ) {
			$dirs[] = $configured_dir;
		}

		$dirs = array_merge( $dirs, self::path_dirs(), self::system_dirs() );

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$dirs[] = WP_CONTENT_DIR . '/bin';
		}

		if ( defined( 'VVAI_PLUGIN_DIR' ) ) {
			$dirs[] = rtrim( VVAI_PLUGIN_DIR, '/\\' ) . '/bin';
		}

		/**
		 * Filter the directories searched for FFmpeg/FFprobe.
		 *
		 * @param array $dirs Directories.
		 */
		$dirs = (array) apply_filters( 'vvai_binary_search_dirs', $dirs );

		$clean = array();

		foreach ( $dirs as $dir ) {
			$dir = trim( (string) $dir, " \t\n\r\0\x0B\"'" );

			if ( '' === $dir ) {
				continue;
			}

			$key = strtolower( str_replace( '\\', '/', $dir ) );

			if ( isset( $clean[ $key ] ) ) {
				continue;
			}

			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$clean[ $key ] = $dir;
		}

		return array_values( $clean );
	}

	/**
	 * Join a directory and a filename with the platform separator.
	 *
	 * @param string $dir  Directory.
	 * @param string $name Filename.
	 * @return string
	 */
	public static function join( $dir, $name ) {
		$dir = rtrim( (string) $dir, '/\\' );

		if ( '' === $dir ) {
			return (string) $name;
		}

		$separator = self::is_windows() && false !== strpos( $dir, '\\' ) ? '\\' : '/';

		return $dir . $separator . $name;
	}

	/**
	 * Is this path inside the web-writable uploads tree?
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function in_uploads( $path ) {
		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['basedir'] ) ) {
			return false;
		}

		$base = trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );

		return 0 === strpos( wp_normalize_path( (string) $path ), $base );
	}

	/**
	 * Candidate absolute paths for a binary, deduped and safety-filtered.
	 *
	 * @param string $base Logical name.
	 * @return string[]
	 */
	public static function candidates( $base ) {
		$names = self::names( $base );

		if ( ! $names ) {
			return array();
		}

		$found = array();

		foreach ( self::search_dirs() as $dir ) {
			foreach ( $names as $name ) {
				$path = self::join( $dir, $name );

				if ( isset( $found[ $path ] ) || ! is_file( $path ) || ! is_readable( $path ) ) {
					continue;
				}

				if ( self::in_uploads( $path ) ) {
					continue;
				}

				if ( ! VVAI_Process::binary_is_safe( $path ) ) {
					continue;
				}

				$found[ $path ] = $path;

				if ( count( $found ) >= self::MAX_CANDIDATES ) {
					return array_values( $found );
				}
			}
		}

		return array_values( $found );
	}

	/**
	 * Ask the shell where a binary lives (`where.exe` / `which`).
	 *
	 * Never runs when PHP cannot execute programs, and a rejected command (a
	 * sandboxed runner with an allowlist) simply yields nothing.
	 *
	 * @param string $base Logical name.
	 * @return string[]
	 */
	public static function shell_lookup( $base ) {
		$base = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $base );
		$cap  = VVAI_Process::capability();

		if ( '' === $base || empty( $cap['available'] ) ) {
			return array();
		}

		$argv  = self::is_windows() ? array( 'where.exe', $base ) : array( 'which', $base );
		$found = array();

		try {
			$run = VVAI_Process::run( $argv, array( 'timeout' => 10 ) );
		} catch ( \Throwable $throwable ) {
			return array();
		}

		if ( 0 !== (int) $run['code'] ) {
			return array();
		}

		foreach ( preg_split( '/\R/', trim( (string) $run['stdout'] ) ) as $line ) {
			$line = trim( (string) $line, " \t\"'" );

			if ( '' === $line || ! is_file( $line ) || self::in_uploads( $line ) ) {
				continue;
			}

			if ( ! VVAI_Process::binary_is_safe( $line ) ) {
				continue;
			}

			$found[ $line ] = $line;
		}

		return array_values( $found );
	}

	/**
	 * Every place this binary appears (filesystem scan + shell lookup).
	 *
	 * @param string $base Logical name.
	 * @return string[]
	 */
	public static function discover_all( $base ) {
		return array_values( array_unique( array_merge( self::candidates( $base ), self::shell_lookup( $base ) ) ) );
	}

	/**
	 * Does this file really behave like the binary it claims to be?
	 *
	 * @param string $path Absolute binary path.
	 * @param string $base Logical name (ffmpeg|ffprobe|...).
	 * @return array{path:string,ok:bool,version:string,error:string}
	 */
	public static function verify( $path, $base ) {
		$result = array(
			'path'    => (string) $path,
			'ok'      => false,
			'version' => '',
			'error'   => '',
		);

		if ( '' === $path || ! is_file( $path ) ) {
			$result['error'] = __( 'The file does not exist on this server.', 'viral-video-ai' );

			return $result;
		}

		if ( ! VVAI_Process::binary_is_safe( $path ) ) {
			$result['error'] = __( 'That path is not an allowed executable location.', 'viral-video-ai' );

			return $result;
		}

		try {
			$run = VVAI_Process::run( array( $path, '-version' ), array( 'timeout' => 20 ) );
		} catch ( \Throwable $throwable ) {
			$result['error'] = $throwable->getMessage();

			return $result;
		}

		$payload = trim( (string) $run['stdout'] . "\n" . (string) $run['stderr'] );
		$first   = strtok( $payload, "\n" );
		$label   = strtolower( (string) $base );

		if ( 0 === (int) $run['code'] && is_string( $first ) ) {
			// The file has to introduce itself like FFmpeg does ("ffmpeg version
			// 6.1 …", "ffprobe version n6.1-0 …", "avconv version …"). Requiring
			// the version banner means a renamed or unrelated executable that
			// happens to sit in a searched folder can never be saved as the
			// plugin's renderer — the name of a file proves nothing.
			$looks_right = (bool) preg_match( '/\b(?:ffmpeg|ffprobe|avconv)\s+version\b/i', $first )
				|| ( false !== stripos( $first, $label ) && false !== stripos( $first, 'version' ) );

			if ( $looks_right ) {
				$result['ok']      = true;
				$result['version'] = substr( $first, 0, 160 );

				return $result;
			}
		}

		$result['error'] = '' !== (string) $run['error']
			? (string) $run['error']
			: sprintf(
				/* translators: %s: exit code. */
				__( 'The program exited with code %s instead of reporting a version.', 'viral-video-ai' ),
				(string) (int) $run['code']
			);

		return $result;
	}

	/**
	 * Resolve one logical binary to an absolute path (cached).
	 *
	 * @param string $base    Logical name.
	 * @param bool   $refresh Ignore the cache.
	 * @return string Absolute path, or '' when nothing was found.
	 */
	public static function find( $base, $refresh = false ) {
		$base = strtolower( (string) $base );

		if ( '' === $base ) {
			return '';
		}

		if ( ! $refresh && array_key_exists( $base, self::$memo ) ) {
			return self::$memo[ $base ];
		}

		$cache = get_transient( self::CACHE_TRANSIENT );

		if ( ! $refresh && is_array( $cache ) && array_key_exists( $base, $cache ) ) {
			self::$memo[ $base ] = (string) $cache[ $base ];

			return self::$memo[ $base ];
		}

		$resolved = '';

		foreach ( self::candidates( $base ) as $candidate ) {
			$resolved = $candidate;

			break;
		}

		if ( '' === $resolved ) {
			foreach ( self::shell_lookup( $base ) as $candidate ) {
				$resolved = $candidate;

				break;
			}
		}

		self::$memo[ $base ] = $resolved;

		$cache           = is_array( $cache ) ? $cache : array();
		$cache[ $base ]  = $resolved;

		set_transient( self::CACHE_TRANSIENT, $cache, self::CACHE_TTL );

		return $resolved;
	}

	/**
	 * Drop cached discovery results (after a settings change or a re-check).
	 */
	public static function forget() {
		self::$memo = array();

		delete_transient( self::CACHE_TRANSIENT );
	}

	/**
	 * Human-readable summary of what was searched, for Diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public static function describe() {
		$dirs = self::search_dirs();

		return array(
			'os'         => self::is_windows() ? 'windows' : 'unix',
			'path_dirs'  => count( self::path_dirs() ),
			'searched'   => array_slice( $dirs, 0, 16 ),
			'searched_total' => count( $dirs ),
			'executable' => VVAI_Process::capability(),
		);
	}
}
