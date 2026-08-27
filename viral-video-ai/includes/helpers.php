<?php
/**
 * Global helper functions.
 *
 * Every function is prefixed with `vvai_` to avoid collisions. These helpers
 * are deliberately dependency-free (no database, no HTTP) so they can be
 * unit-tested in isolation.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read a nested array value with a default.
 *
 * @param array      $array   Source array.
 * @param string|int $key     Key.
 * @param mixed      $default Default value when the key is absent or null.
 * @return mixed
 */
function vvai_array_get( $array, $key, $default = null ) {
	if ( ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
		return $default;
	}

	return ( null === $array[ $key ] ) ? $default : $array[ $key ];
}

/**
 * Clamp a numeric value into an inclusive range.
 *
 * @param mixed $value    Value to clamp.
 * @param mixed $min      Lower bound.
 * @param mixed $max      Upper bound.
 * @param mixed $fallback Returned when the value is not numeric.
 * @return float
 */
function vvai_clamp( $value, $min, $max, $fallback = null ) {
	if ( ! is_numeric( $value ) ) {
		return ( null === $fallback ) ? (float) $min : (float) $fallback;
	}

	return max( (float) $min, min( (float) $max, (float) $value ) );
}

/**
 * Sanitize a float, clamped to a range.
 *
 * @param mixed $value Value.
 * @param float $min   Minimum.
 * @param float $max   Maximum.
 * @param float $fallback Used when the value is not numeric.
 * @return float
 */
function vvai_sanitize_float( $value, $min = -PHP_FLOAT_MAX, $max = PHP_FLOAT_MAX, $fallback = 0.0 ) {
	if ( ! is_numeric( $value ) ) {
		return (float) $fallback;
	}

	return vvai_clamp( (float) $value, $min, $max, $fallback );
}

/**
 * Sanitize an integer, clamped to a range.
 *
 * @param mixed $value    Value.
 * @param int   $min      Minimum.
 * @param int   $max      Maximum.
 * @param int   $fallback Fallback for non-numeric input.
 * @return int
 */
function vvai_sanitize_int( $value, $min = PHP_INT_MIN, $max = PHP_INT_MAX, $fallback = 0 ) {
	if ( ! is_numeric( $value ) ) {
		return (int) $fallback;
	}

	return (int) max( $min, min( $max, (int) $value ) );
}

/**
 * Sanitize a boolean-ish value coming from a form or JSON payload.
 *
 * @param mixed $value Value.
 * @return bool
 */
function vvai_sanitize_bool( $value ) {
	if ( is_bool( $value ) ) {
		return $value;
	}

	if ( is_string( $value ) ) {
		$value = strtolower( trim( $value ) );
		if ( in_array( $value, array( 'no', 'off', 'false', '0', '' ), true ) ) {
			return false;
		}
	}

	return (bool) $value;
}

/**
 * Sanitize a single-line text field.
 *
 * Keeps readable punctuation while removing anything that could produce
 * markup when echoed into HTML.
 *
 * @param mixed $value  Raw value.
 * @param int   $limit  Maximum length in characters.
 * @return string
 */
function vvai_sanitize_text( $value, $limit = 191 ) {
	$value = is_scalar( $value ) ? (string) $value : '';
	$value = wp_strip_all_tags( $value, false );
	$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value );
	$value = trim( (string) $value );

	if ( $limit > 0 && function_exists( 'mb_substr' ) && mb_strlen( $value ) > $limit ) {
		$value = rtrim( mb_substr( $value, 0, $limit ) ) . '…';
	} elseif ( $limit > 0 && strlen( $value ) > $limit ) {
		$value = substr( $value, 0, $limit );
	}

	return $value;
}

/**
 * Sanitize multi-line free text (AI reasoning, captions).
 *
 * @param mixed $value Raw value.
 * @param int   $limit Maximum length.
 * @return string
 */
function vvai_sanitize_paragraph( $value, $limit = 2000 ) {
	$value = is_scalar( $value ) ? (string) $value : '';
	$value = wp_strip_all_tags( $value, false );
	$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
	$value = preg_replace( '/[ \t]{2,}/', ' ', $value );
	$value = preg_replace( "/\n{3,}/", "\n\n", $value );
	$value = trim( (string) $value );

	if ( $limit > 0 && function_exists( 'mb_substr' ) && mb_strlen( $value ) > $limit ) {
		$value = mb_substr( $value, 0, $limit );
	}

	return $value;
}

/**
 * Normalize a hashtag list into a clean array of `#tag` strings.
 *
 * Accepts arrays or a whitespace/comma separated string. Values are stripped
 * of markup, de-duplicated and limited to 30 characters.
 *
 * @param mixed $input Raw hashtags.
 * @param int   $max   Maximum number of tags.
 * @return string[]
 */
function vvai_sanitize_hashtags( $input, $max = 15 ) {
	if ( is_string( $input ) ) {
		$raw = trim( $input );

		// A model sometimes answers with a hashtag list, sometimes with plain words
		// ("viral, shorts"), and sometimes with a sentence. Sentences must NOT be
		// turned into tags, so free text without any separator is rejected.
		if ( false === strpos( $raw, '#' ) && ! preg_match( '/^[^\s,]+(?:\s*,\s*[^\s,]+)*$/', $raw ) ) {
			return array();
		}

		$input = preg_split( '/[\s,]+/', $raw );
	}

	if ( ! is_array( $input ) ) {
		return array();
	}

	$out = array();

	foreach ( $input as $tag ) {
		if ( ! is_scalar( $tag ) ) {
			continue;
		}

		$tag = (string) $tag;
		$tag = wp_strip_all_tags( $tag );
		$tag = str_replace( array( "\n", "\r", "\t" ), ' ', $tag );
		$tag = trim( preg_replace( '/\s+/', ' ', $tag ) );
		$tag = ltrim( $tag, '#!＃' );

		// A hashtag cannot contain spaces; drop anything that still does.
		if ( '' === $tag || preg_match( '/\s/', $tag ) ) {
			continue;
		}

		if ( function_exists( 'mb_substr' ) ) {
			$tag = mb_substr( $tag, 0, 30 );
		} else {
			$tag = substr( $tag, 0, 30 );
		}

		$key = strtolower( $tag );

		if ( '' === $key || isset( $out[ $key ] ) ) {
			continue;
		}

		$out[ $key ] = '#' . $tag;

		if ( count( $out ) >= $max ) {
			break;
		}
	}

	return array_values( $out );
}

/**
 * Convert seconds into a `HH:MM:SS` (or `MM:SS` when shorter) string.
 *
 * @param float $seconds Seconds.
 * @param bool  $with_ms Append hundredths (used by transcripts/captions).
 * @return string
 */
function vvai_format_time( $seconds, $with_ms = false ) {
	$seconds = max( 0.0, (float) $seconds );

	$total   = (int) floor( $seconds );
	$hours   = intdiv( $total, 3600 );
	$minutes = intdiv( $total % 3600, 60 );
	$whole   = $total % 60;

	if ( $with_ms ) {
		$ms = (int) floor( ( $seconds - floor( $seconds ) ) * 100 );

		return sprintf( '%02d:%02d:%02d.%02d', $hours, $minutes, $whole, $ms );
	}

	if ( $hours > 0 ) {
		return sprintf( '%02d:%02d:%02d', $hours, $minutes, $whole );
	}

	return sprintf( '%02d:%02d', $minutes, $whole );
}

/**
 * Parse a human time string into seconds.
 *
 * Understands `83`, `83.5`, `1:23`, `0:01:23`, `01:02:03.50`.
 *
 * @param mixed $value Time string or number.
 * @return float|false Seconds, or false when unparseable.
 */
function vvai_parse_time( $value ) {
	if ( is_numeric( $value ) ) {
		return (float) $value;
	}

	$value = trim( (string) $value );

	if ( '' === $value ) {
		return false;
	}

	if ( ! preg_match( '/^(?:\d+:)?(?:\d{1,2}:)?\d{1,2}(?:[.,]\d{1,3})?$/', $value ) ) {
		return false;
	}

	$parts    = explode( ':', str_replace( ',', '.', $value ) );
	$seconds = 0.0;

	foreach ( $parts as $part ) {
		$seconds = ( $seconds * 60 ) + (float) $part;
	}

	return $seconds;
}

/**
 * Human readable byte size.
 *
 * @param int|float $bytes  Byte count.
 * @param int       $decimals Decimals.
 * @return string
 */
function vvai_human_size( $bytes, $decimals = 1 ) {
	$bytes = (float) $bytes;

	if ( $bytes <= 0 ) {
		return '0 B';
	}

	$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
	$power = (int) floor( log( $bytes ) / log( 1024 ) );
	$power = min( $power, count( $units ) - 1 );

	return sprintf( '%.' . (int) $decimals . 'f %s', $bytes / ( 1024 ** $power ), $units[ $power ] );
}

/**
 * Convert a `2M`/`1G`/`500K` shorthand into bytes.
 *
 * @param mixed $value Shorthand.
 * @return int
 */
function vvai_shorthand_to_bytes( $value ) {
	$value = trim( (string) $value );

	if ( '' === $value ) {
		return 0;
	}

	if ( preg_match( '/^(\d+(?:\.\d+)?)\s*([KMGT]?B?)$/i', $value, $m ) ) {
		$number = (float) $m[1];
		$unit   = strtoupper( $m[2] );
		$mult   = 1;

		if ( 0 === strpos( $unit, 'K' ) ) {
			$mult = 1024;
		} elseif ( 0 === strpos( $unit, 'M' ) ) {
			$mult = 1024 * 1024;
		} elseif ( 0 === strpos( $unit, 'G' ) ) {
			$mult = 1024 * 1024 * 1024;
		} elseif ( 0 === strpos( $unit, 'T' ) ) {
			$mult = 1024 * 1024 * 1024 * 1024;
		}

		return (int) round( $number * $mult );
	}

	return (int) $value;
}

/**
 * Generate a URL-safe random identifier.
 *
 * @param int $length Character count.
 * @return string
 */
function vvai_random_id( $length = 16 ) {
	$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
	$length   = max( 4, min( 64, (int) $length ) );
	$id       = '';

	for ( $i = 0; $i < $length; $i++ ) {
		$id .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
	}

	return $id;
}

/**
 * Build a filesystem-safe name from untrusted input.
 *
 * Used for upload handles and clip titles. Never trusts the client supplied
 * filename: separators, dots used for traversal and control characters are
 * removed, and the result is limited in length.
 *
 * @param mixed  $name    Candidate name.
 * @param string $fallback Returned when nothing usable remains.
 * @param int    $max     Maximum length.
 * @return string
 */
function vvai_sanitize_filename( $name, $fallback = 'file', $max = 80 ) {
	$name = is_scalar( $name ) ? (string) $name : '';
	$name = str_replace( '\\', '/', $name );
	$name = basename( $name );
	$name = remove_accents( $name );
	$name = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name );
	$name = preg_replace( '/\.{2,}/', '.', (string) $name );
	$name = trim( (string) $name, '.-' );

	if ( '' === $name || '.' === $name || '..' === $name ) {
		$name = $fallback;
	}

	if ( strlen( $name ) > $max ) {
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$name = substr( $name, 0, $max - ( $ext ? strlen( $ext ) + 1 : 0 ) );
		$name = trim( $name, '.-' );

		if ( '' === $name ) {
			$name = $fallback;
		}
	}

	return $name;
}

/**
 * Slugify text for use inside a filename while keeping it readable.
 *
 * @param mixed $text Text.
 * @param int   $max  Maximum length.
 * @return string
 */
function vvai_slug( $text, $max = 60 ) {
	$text = is_scalar( $text ) ? (string) $text : '';
	$text = wp_strip_all_tags( $text );
	$text = sanitize_title_with_dashes( strtolower( remove_accents( $text ) ), '', 'save' );
	$text = preg_replace( '/-+/', '-', $text );
	$text = trim( (string) $text, '-' );

	if ( '' === $text ) {
		$text = 'clip';
	}

	return substr( $text, 0, $max );
}

/**
 * Quote one argument of a shell command.
 *
 * Falls back to a strict manual escape when `escapeshellarg()` is disabled —
 * which happens on hardened hosts — rather than silently producing a
 * vulnerable command line.
 *
 * @param string $argument Argument.
 * @return string
 */
function vvai_shell_arg( $argument ) {
	$argument = (string) $argument;

	if ( function_exists( 'escapeshellarg' ) && ! vvai_function_disabled( 'escapeshellarg' ) ) {
		return escapeshellarg( $argument );
	}

	// Manual escape: allow only a safe whitelist, otherwise refuse.
	$safe = preg_replace( "/[^A-Za-z0-9 ._:\-\/\\\\]/", '', $argument );

	return "'" . str_replace( "'", "'", (string) $safe ) . "'";
}

/**
 * Is a PHP function disabled by php.ini?
 *
 * @param string $function Function name.
 * @return bool
 */
function vvai_function_disabled( $function ) {
	$disabled = (string) ini_get( 'disable_functions' );

	if ( '' === $disabled ) {
		return false;
	}

	$disabled = strtolower( str_replace( ' ', '', $disabled ) );
	$disabled = preg_replace( '/\s+/', ',', $disabled );

	return in_array( $function, explode( ',', $disabled ), true );
}

/**
 * Create a directory tree with minimal listing protection.
 *
 * @param string $directory Absolute path.
 * @return bool True when the directory exists (or was created) and is writable.
 */
function vvai_mkdir( $directory ) {
	if ( is_dir( $directory ) ) {
		return is_writable( $directory );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- recursive tree needed.
	$made = wp_mkdir_p( $directory );

	if ( ! $made ) {
		return false;
	}

	vvai_harden_directory( $directory );

	return true;
}

/**
 * Add `index.php` + `.htaccess` guards to a directory inside uploads.
 *
 * @param string $directory Absolute path.
 */
function vvai_harden_directory( $directory ) {
	if ( ! is_dir( $directory ) ) {
		return;
	}

	$index = trailingslashit( $directory ) . 'index.php';

	if ( ! file_exists( $index ) && is_writable( $directory ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- silent guard file.
		@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
	}

	$htaccess = trailingslashit( $directory ) . '.htaccess';

	if ( ! file_exists( $htaccess ) && is_writable( $directory ) ) {
		$rules = "# Viral Video AI: never serve these files directly.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- silent guard file.
		@file_put_contents( $htaccess, $rules );
	}
}

/**
 * Base directory (inside uploads) used for everything the plugin writes.
 *
 * @param string $relative Optional sub-path, e.g. "jobs/12".
 * @return string
 */
function vvai_storage_dir( $relative = '' ) {
	$uploads = wp_get_upload_dir();
	$base    = trailingslashit( $uploads['basedir'] ) . 'vvai';

	/**
	 * Filter the plugin storage root.
	 *
	 * Allow overriding the base directory so large installs can move render
	 * scratch space onto a faster volume. Implementations must return an
	 * absolute path.
	 *
	 * @param string $base Absolute path without trailing slash.
	 */
	$base = apply_filters( 'vvai_storage_dir', $base );
	$base = untrailingslashit( (string) $base );

	if ( '' === $relative ) {
		return $base;
	}

	// Prevent traversal through the relative segment.
	$relative = trim( str_replace( '\\', '/', $relative ), '/' );
	$parts    = array();

	foreach ( explode( '/', $relative ) as $part ) {
		if ( '' === $part || '.' === $part || '..' === $part ) {
			continue;
		}

		$parts[] = preg_replace( '/[^A-Za-z0-9._-]/', '', $part );
	}

	return $base . ( $parts ? '/' . implode( '/', $parts ) : '' );
}

/**
 * Public URL for the storage directory (only ever used for guarded files).
 *
 * @param string $relative Relative path.
 * @return string
 */
function vvai_storage_url( $relative = '' ) {
	$uploads = wp_get_upload_dir();
	$base    = trailingslashit( $uploads['baseurl'] ) . 'vvai';

	return untrailingslashit( $base ) . ( '' !== $relative ? '/' . ltrim( $relative, '/' ) : '' );
}

/**
 * Ensure a directory exists and return its path.
 *
 * @param string $relative Relative path below the storage root.
 * @return string Absolute path, or empty string on failure.
 */
function vvai_storage_path( $relative = '' ) {
	$directory = vvai_storage_dir( $relative );

	return vvai_mkdir( $directory ) ? $directory : '';
}

/**
 * Recursively delete a directory created by the plugin.
 *
 * Only paths inside the plugin storage root are accepted, so a bug elsewhere
 * can never make this delete arbitrary files.
 *
 * @param string $path Absolute path.
 * @return bool
 */
function vvai_rrmdir( $path ) {
	$root = vvai_storage_dir();
	$path = wp_normalize_path( (string) $path );
	$root = wp_normalize_path( $root );

	if ( '' === $path || 0 !== strpos( $path, $root ) ) {
		return false;
	}

	if ( is_file( $path ) ) {
		return @unlink( $path );
	}

	if ( ! is_dir( $path ) ) {
		return false;
	}

	$items = @scandir( $path );

	if ( ! is_array( $items ) ) {
		return false;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$child = $path . '/' . $item;

		if ( is_dir( $child ) && ! is_link( $child ) ) {
			vvai_rrmdir( $child );
		} else {
			@unlink( $child );
		}
	}

	return @rmdir( $path );
}

/**
 * Content fingerprint of a large file without loading it into memory.
 *
 * Hashes size + first 8 MiB + last 8 MiB. That is enough to de-duplicate
 * re-uploaded sources while keeping the cost constant for multi-gigabyte
 * files.
 *
 * @param string $path Absolute path.
 * @return string|false Hex digest or false when unreadable.
 */
function vvai_fingerprint_file( $path ) {
	if ( ! is_file( $path ) ) {
		return false;
	}

	$handle = @fopen( $path, 'rb' );

	if ( ! $handle ) {
		return false;
	}

	$context = hash_init( 'sha256' );

	hash_update( $context, (string) filesize( $path ) );

	$size     = filesize( $path );
	$window   = 8 * MB_IN_BYTES;
	$consumed = 0;
	$chunk    = 512 * KB_IN_BYTES;

	while ( ! feof( $handle ) && $consumed < $window ) {
		$data = fread( $handle, min( $chunk, $window - $consumed ) );

		if ( false === $data || '' === $data ) {
			break;
		}

		hash_update( $context, $data );
		$consumed += strlen( $data );
	}

	// Tail window, when the file is bigger than head + tail.
	if ( $size > ( 2 * $window ) ) {
		if ( fseek( $handle, $size - $window, SEEK_SET ) ) {
			$read = 0;

			while ( ! feof( $handle ) && $read < $window ) {
				$data = fread( $handle, min( $chunk, $window - $read ) );

				if ( false === $data || '' === $data ) {
					break;
				}

				hash_update( $context, $data );
				$read += strlen( $data );
			}
		}
	}

	fclose( $handle );

	return hash_final( $context );
}

/**
 * JSON encode with the flags this plugin always wants.
 *
 * @param mixed $value  Value.
 * @param int   $options Extra flags.
 * @return string
 */
function vvai_json_encode( $value, $options = 0 ) {
	$json = wp_json_encode( $value, $options | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	return ( false === $json ) ? '{}' : $json;
}

/**
 * Decode a JSON document into an array, tolerating `null` and objects.
 *
 * @param mixed $json  Raw JSON string or already-decoded value.
 * @param bool  $assoc Return arrays instead of objects.
 * @return mixed Null when undecodable.
 */
function vvai_json_decode( $json, $assoc = true ) {
	if ( is_array( $json ) || is_object( $json ) ) {
		return $json;
	}

	if ( ! is_string( $json ) || '' === trim( $json ) ) {
		return null;
	}

	return json_decode( $json, $assoc );
}

/**
 * Redact anything that looks like a secret before it reaches a log file.
 *
 * @param string $message Message.
 * @return string
 */
function vvai_redact_secrets( $message ) {
	$message = (string) $message;

	// Bearer tokens / API keys.
	$message = preg_replace( '/(Bearer\s+)[A-Za-z0-9\-_\.=]{6,}/i', '$1[redacted]', $message );
	$message = preg_replace( '/(sk-[A-Za-z0-9\-_]{4,})/', 'sk-[redacted]', $message );
	$message = preg_replace( '/("?(?:api_key|apikey|authorization|x-api-key|access_token|secret)"?\s*[:=]\s*")[^"]*(")/i', '$1[redacted]$2', $message );

	return $message;
}

/**
 * Escape a string for safe use inside a JS string passed through wp_add_inline_script.
 *
 * @param string $value Value.
 * @return string
 */
function vvai_esc_js( $value ) {
	return esc_js( is_scalar( $value ) ? (string) $value : '' );
}

/**
 * Whether the current request is one of the plugin's own endpoints.
 *
 * @return bool
 */
function vvai_is_own_request() {
	if ( wp_doing_ajax() && isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- context detection only.
		$action = sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized above.

		return 0 === strpos( $action, 'vvai_' );
	}

	$rest_prefix = '/' . VVAI_REST_NAMESPACE . '/';

	if ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( wp_unslash( $_SERVER['REQUEST_URI'] ), $rest_prefix ) ) {
		return true;
	}

	return false;
}
