<?php
/**
 * Secure debug log.
 *
 * Writes to `uploads/vvai/logs/vvai-debug.log` only when logging is enabled,
 * redacts anything that looks like a credential, and caps the file size so a
 * busy site cannot fill the disk.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Logger
 */
class VVAI_Logger {

	const LEVELS = array( 'debug', 'info', 'warning', 'error', 'critical' );

	/**
	 * Settings.
	 *
	 * @var VVAI_Settings
	 */
	private $settings;

	/**
	 * In-memory buffer, also used as fallback when the file is not writable.
	 *
	 * @var string[]
	 */
	private $buffer = array();

	/**
	 * Constructor.
	 *
	 * @param VVAI_Settings|null $settings Settings service.
	 */
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
	}

	/**
	 * Log at an arbitrary level.
	 *
	 * @param string              $level   One of LEVELS.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Structured context (never contains secrets).
	 */
	public function log( $level, $message, array $context = array() ) {
		$level = in_array( $level, self::LEVELS, true ) ? $level : 'info';

		$line = sprintf(
			'[%s] %s: %s',
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$message
		);

		if ( $context ) {
			$line .= ' ' . wp_json_encode( $this->clean_context( $context ) );
		}

		$line = vvai_redact_secrets( $line );

		// The ring buffer is always maintained: the diagnostics screen reads it
		// even when file logging is switched off.
		$this->buffer[] = $line;

		if ( count( $this->buffer ) > 200 ) {
			array_shift( $this->buffer );
		}

		if ( ! $this->should_log( $level ) ) {
			return;
		}

		$path = $this->log_file();

		if ( ! $path ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- best effort logging.
		$handle = @fopen( $path, 'ab' );

		if ( ! $handle ) {
			return;
		}

		if ( flock( $handle, LOCK_EX ) ) {
			fwrite( $handle, $line . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- appended below lock.
			flock( $handle, LOCK_UN );
		}

		fclose( $handle );

		$this->rotate_if_needed( $path );
	}

	/**
	 * Convenience helpers.
	 *
	 * @param string              $message  Message.
	 * @param array<string,mixed> $context  Context.
	 */
	public function debug( $message, array $context = array() ) {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Info level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function info( $message, array $context = array() ) {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Warning level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function warning( $message, array $context = array() ) {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Error level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function error( $message, array $context = array() ) {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Critical level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function critical( $message, array $context = array() ) {
		$this->log( 'critical', $message, $context );
	}

	/**
	 * Last buffered lines (for the diagnostics screen).
	 *
	 * @param int $count Number of lines.
	 * @return string[]
	 */
	public function tail( $count = 40 ) {
		$path = $this->log_file();

		if ( $path && is_readable( $path ) ) {
			$lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

			if ( is_array( $lines ) && $lines ) {
				return array_slice( $lines, -1 * max( 1, (int) $count ) );
			}
		}

		return array_slice( $this->buffer, -1 * max( 1, (int) $count ) );
	}

	/**
	 * Clear the log file.
	 *
	 * @return bool
	 */
	public function clear() {
		$path = $this->log_file();

		if ( $path && file_exists( $path ) ) {
			$this->buffer = array();

			return @unlink( $path );
		}

		$this->buffer = array();

		return true;
	}

	/**
	 * Absolute log path, or empty string when logging cannot write.
	 *
	 * @return string
	 */
	public function log_file() {
		$dir = vvai_storage_path( 'logs' );

		if ( '' === $dir ) {
			return '';
		}

		return $dir . '/vvai-debug.log';
	}

	/**
	 * Whether a level passes the current threshold.
	 *
	 * @param string $level Level.
	 * @return bool
	 */
	protected function should_log( $level ) {
		if ( ! $this->settings->get( 'debug_log' ) ) {
			return false;
		}

		/**
		 * Filter the minimum level that is written.
		 *
		 * @param string $minimum Minimum level name.
		 */
		$minimum = apply_filters( 'vvai_log_level', 'debug' );
		$order   = array_flip( self::LEVELS );

		return ( $order[ $level ] ?? PHP_INT_MAX ) >= ( $order[ $minimum ] ?? 0 );
	}

	/**
	 * Strip values that must never be persisted.
	 *
	 * @param array<string,mixed> $context Context.
	 * @return array<string,mixed>
	 */
	protected function clean_context( array $context ) {
		$blocked = array(
			'api_key',
			'apikey',
			'authorization',
			'headers',
			'secret',
			'token',
			'password',
			'x-api-key',
			'access_token',
			'body',
			'raw_body',
			'transcript',
		);

		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $blocked, true ) ) {
				$clean[ $key ] = '[filtered]';
				continue;
			}

			if ( is_array( $value ) ) {
				$value = $this->clean_context( $value );
			} elseif ( is_object( $value ) ) {
				$value = get_class( $value );
			} elseif ( ! is_scalar( $value ) ) {
				$value = gettype( $value );
			} elseif ( is_string( $value ) && strlen( $value ) > 400 ) {
				$value = substr( $value, 0, 400 ) . '…';
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * Rotate the log when it grows past the configured size.
	 *
	 * Keeps the newest half of the file instead of deleting the whole log, so a
	 * failure that happened right before the rotation is still readable.
	 *
	 * @param string $path Log path.
	 */
	protected function rotate_if_needed( $path ) {
		$max = (int) $this->settings->get( 'log_max_kb' ) * KB_IN_BYTES;

		if ( $max <= 0 || ! is_file( $path ) || filesize( $path ) <= $max ) {
			return;
		}

		$lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( ! is_array( $lines ) ) {
			return;
		}

		// Roughly half the budget, measured with an assumed 160 bytes per line.
		$keep_lines = max( 50, (int) floor( ( $max / 2 ) / 160 ) );
		$lines      = array_slice( $lines, -1 * $keep_lines );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rotation rewrite.
		@file_put_contents( $path, implode( PHP_EOL, $lines ) . PHP_EOL, LOCK_EX );
	}
}
