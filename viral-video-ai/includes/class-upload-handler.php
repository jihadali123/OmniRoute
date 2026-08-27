<?php
/**
 * Resumable, chunked uploads.
 *
 * A 2 GB source must never travel in one AJAX POST: the browser sends fixed
 * size chunks to `POST /vvai/v1/uploads/{handle}/chunk`, the server appends
 * each one to a part file, and a separate "complete" call assembles, validates
 * and registers the result. Interrupted uploads resume by re-requesting only the
 * chunk indexes the server reports as missing.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Upload_Handler
 */
class VVAI_Upload_Handler {

	/**
	 * Settings.
	 *
	 * @var VVAI_Settings
	 */
	private $settings;

	/**
	 * Chunked session timeout.
	 */
	const SESSION_TTL = 21600; // 6 hours.

	/**
	 * Extensions that FFmpeg can generally decode.
	 *
	 * @var string[]
	 */
	const VIDEO_EXTENSIONS = array( 'mp4', 'mov', 'm4v', 'webm', 'mkv', 'avi', 'mpg', 'mpeg', 'mts', 'm2ts', 'flv', 'wmv', '3gp' );

	/**
	 * MIME types accepted regardless of extension.
	 *
	 * @var string[]
	 */
	const VIDEO_MIMES = array(
		'video/mp4',
		'video/quicktime',
		'video/x-m4v',
		'video/webm',
		'video/x-matroska',
		'video/x-msvideo',
		'video/mpeg',
		'video/mp2t',
		'video/x-flv',
		'video/x-ms-wmv',
		'video/3gpp',
		'video/3gpp2',
		'application/octet-stream', // some hosts sniff nothing; ffprobe is the gatekeeper.
	);

	/**
	 * Constructor.
	 *
	 * @param VVAI_Settings|null $settings Settings.
	 */
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
	}

	/**
	 * Start (or resume) an upload session.
	 *
	 * @param int                $user_id  Owner.
	 * @param array<string,mixed> $args {
	 *     @type string $name       Original file name.
	 *     @type int    $size       Declared total bytes.
	 *     @type int    $chunk      Chunk size.
	 *     @type string $hash       Optional client-computed sha256 for instant resume.
	 *     @type string $handle     Existing handle to resume.
	 * }
	 * @return array<string,mixed>|WP_Error
	 */
	public function init_session( $user_id, array $args ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'vvai_upload_forbidden', __( 'You must be logged in to upload a video.', 'viral-video-ai' ) );
		}

		$name   = vvai_sanitize_filename( vvai_array_get( $args, 'name', 'video.mp4' ), 'video.mp4' );
		$size   = (int) vvai_array_get( $args, 'size', 0 );
		$chunk  = (int) vvai_array_get( $args, 'chunk', 5242880 );
		$max    = $this->settings->max_upload_bytes();

		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, $this->allowed_extensions(), true ) ) {
			return new WP_Error(
				'vvai_bad_extension',
				sprintf(
					/* translators: %s: comma separated list. */
					__( 'Unsupported file type "%s". Allowed: %s', 'viral-video-ai' ),
					( '' !== $extension ? $extension : '?' ),
					implode( ', ', $this->allowed_extensions() )
				)
			);
		}

		if ( $size <= 0 ) {
			return new WP_Error( 'vvai_bad_size', __( 'The browser did not report a file size.', 'viral-video-ai' ) );
		}

		if ( $size > $max ) {
			return new WP_Error(
				'vvai_too_large',
				sprintf(
					/* translators: 1: file size, 2: allowed size. */
					__( 'That file is %1$s, but this site accepts at most %2$s per upload. A hosting limit (upload_max_filesize / post_max_size) may also apply.', 'viral-video-ai' ),
					vvai_human_size( $size ),
					vvai_human_size( $max )
				)
			);
		}

		$chunk = max( 256 * KB_IN_BYTES, min( 32 * MB_IN_BYTES, $chunk ) );
		$total = (int) max( 1, ceil( $size / $chunk ) );

		// Resume an existing session when the client presents its handle, or when
		// an identical (user, hash, size) session is still open.
		$existing = null;
		$handle   = sanitize_text_field( (string) vvai_array_get( $args, 'handle', '' ) );

		if ( '' !== $handle ) {
			$session  = $this->session_dir( $handle );
			$existing = ( '' !== $session && is_file( $session . '/meta.json' ) ) ? $this->read_meta( $handle ) : null;

			if ( $existing && (int) $existing['user_id'] !== $user_id ) {
				return new WP_Error( 'vvai_forbidden', __( 'That upload belongs to another user.', 'viral-video-ai' ) );
			}
		}

		if ( ! $existing && '' !== (string) vvai_array_get( $args, 'hash', '' ) ) {
			$existing = $this->find_by_hash( $user_id, (string) $args['hash'], $size );
		}

		if ( $existing ) {
			// The client may have moved on to a different chunk size; only reuse a
			// session whose geometry matches.
			if ( (int) $existing['chunk_size'] === $chunk && (int) $existing['total_bytes'] === $size ) {
				$existing['received'] = $this->received_chunks( $existing['handle'] );
				$existing['resume']   = true;

				if ( $this->is_complete( $existing ) ) {
					$finalize = $this->finalize( $existing['handle'], $user_id );

					if ( ! is_wp_error( $finalize ) ) {
						$existing['finalized'] = $finalize;
					}
				}

				return $existing;
			}

			$this->discard( (string) $existing['handle'] );
		}

		$handle = 'up_' . vvai_random_id( 20 );
		$dir    = $this->session_dir( $handle, true );

		if ( '' === $dir ) {
			return new WP_Error( 'vvai_storage_error', __( 'The uploads folder is not writable, so the video cannot be stored.', 'viral-video-ai' ) );
		}

		$meta = array(
			'handle'       => $handle,
			'user_id'      => $user_id,
			'name'         => $name,
			'display_name' => vvai_sanitize_text( (string) vvai_array_get( $args, 'name', $name ), 160 ),
			'extension'    => $extension,
			'size'         => $size,
			'total_bytes'  => $size,
			'chunk_size'   => $chunk,
			'chunk_total'  => $total,
			'hash'         => strtolower( preg_replace( '/[^a-f0-9]/', '', (string) vvai_array_get( $args, 'hash', '' ) ) ),
			'received'     => array(),
			'bytes'        => 0,
			'status'       => 'uploading',
			'created'      => time(),
			'expires'      => time() + self::SESSION_TTL,
			'target_path'  => $this->target_path( $handle, $name ),
		);

		$this->write_meta( $handle, $meta );

		// Pre-create the sparse assembly file so chunk offsets are stable.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- sparse allocation.
		$handle_fp = fopen( $dir . '/assembly.part', 'wb' );

		if ( $handle_fp ) {
			ftruncate( $handle_fp, $size );
			fclose( $handle_fp );
		}

		return $meta;
	}

	/**
	 * Store one chunk.
	 *
	 * @param string $handle   Session handle.
	 * @param int    $index    Zero-based chunk index.
	 * @param string $temp_file Absolute path of the uploaded part (already in tmp).
	 * @return array<string,mixed>|WP_Error
	 */
	public function store_chunk( $handle, $index, $temp_file ) {
		$meta = $this->read_meta( $handle );

		if ( ! $meta ) {
			return new WP_Error( 'vvai_unknown_upload', __( 'This upload session no longer exists. Start the upload again.', 'viral-video-ai' ) );
		}

		if ( 'uploading' !== $meta['status'] ) {
			return new WP_Error( 'vvai_upload_closed', __( 'This upload already finished.', 'viral-video-ai' ) );
		}

		$index = (int) $index;

		if ( $index < 0 || $index >= (int) $meta['chunk_total'] ) {
			return new WP_Error( 'vvai_bad_chunk', __( 'Chunk number out of range.', 'viral-video-ai' ) );
		}

		// `is_uploaded_file()` is only true for a genuine PHP upload. Hosts that put
		// a proxy/multipart decoder in front of PHP (and the automated tests) need a
		// documented seam instead of a weakened check, so the trust decision is
		// explicit and filterable rather than implicit.
		$staged = is_uploaded_file( $temp_file );

		/**
		 * Allow an alternative source for one upload chunk.
		 *
		 * Only return true for a file that the server itself staged (e.g. a
		 * proxy-uploaded temp file). Returning true for an arbitrary path would let
		 * a caller read any readable file into the assembly.
		 *
		 * @param bool   $staged     Whether the path may be consumed.
		 * @param string $temp_file    Path PHP handed us.
		 * @param string $handle       Session handle.
		 */
		$staged = (bool) apply_filters( 'vvai_upload_part_accepted', $staged, $temp_file, $handle );

		if ( ! $staged ) {
			return new WP_Error( 'vvai_bad_source', __( 'The chunk did not arrive as an upload.', 'viral-video-ai' ) );
		}

		$expected_size = ( $index === ( (int) $meta['chunk_total'] - 1 ) )
			? ( (int) $meta['total_bytes'] - ( $index * (int) $meta['chunk_size'] ) )
			: (int) $meta['chunk_size'];

		$actual = (int) filesize( $temp_file );

		if ( $actual !== $expected_size ) {
			return new WP_Error(
				'vvai_chunk_size_mismatch',
				sprintf(
					/* translators: 1: expected bytes, 2: received bytes. */
					__( 'Chunk %1$d is incomplete (expected %2$s bytes, received %3$s). The upload will retry this chunk.', 'viral-video-ai' ),
					$index + 1,
					$expected_size,
					$actual
				)
			);
		}

		$directory = $this->session_dir( $handle );
		$offset    = $index * (int) $meta['chunk_size'];
		$target    = $directory . '/assembly.part';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- random access write at the chunk offset.
		$fp = fopen( $target, 'r+b' );

		if ( ! $fp ) {
			return new WP_Error( 'vvai_write_error', __( 'The partial upload could not be written to disk.', 'viral-video-ai' ) );
		}

		fseek( $fp, $offset );

		$source = fopen( $temp_file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the spooled upload.

		if ( ! $source ) {
			fclose( $fp );

			return new WP_Error( 'vvai_read_error', __( 'The uploaded chunk could not be read.', 'viral-video-ai' ) );
		}

		$written = 0;

		while ( ! feof( $source ) ) {
			$buffer = fread( $source, 512 * KB_IN_BYTES );

			if ( false === $buffer || '' === $buffer ) {
				break;
			}

			$written += fwrite( $fp, $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- sequential chunk append.
		}

		fclose( $source );
		fflush( $fp );
		ftruncate( $fp, (int) $meta['total_bytes'] );
		fclose( $fp );

		$received = $this->received_chunks( $handle );

		if ( ! in_array( $index, $received, true ) ) {
			$received[] = $index;
			sort( $received, SORT_NUMERIC );
			$this->put_received( $handle, $received );
		}

		$meta['received'] = $received;
		$meta['bytes']    = min( (int) $meta['total_bytes'], count( $received ) * (int) $meta['chunk_size'] );

		$this->write_meta( $handle, $meta );

		return array(
			'received'   => count( $received ),
			'total'      => (int) $meta['chunk_total'],
			'bytes'      => (int) $written,
			'percentage' => (int) floor( ( count( $received ) / max( 1, (int) $meta['chunk_total'] ) ) * 100 ),
			'complete'   => ( count( $received ) === (int) $meta['chunk_total'] ),
		);
	}

	/**
	 * Finish an upload: seal, sniff, and hand back a source descriptor.
	 *
	 * @param string $handle  Session handle.
	 * @param int    $user_id Expecting user.
	 * @return array<string,mixed>|WP_Error
	 */
	public function finalize( $handle, $user_id ) {
		$meta = $this->read_meta( $handle );

		if ( ! $meta ) {
			return new WP_Error( 'vvai_unknown_upload', __( 'This upload session no longer exists.', 'viral-video-ai' ) );
		}

		if ( (int) $meta['user_id'] !== (int) $user_id && ! VVAI_Permissions::can_manage() ) {
			return new WP_Error( 'vvai_forbidden', __( 'You cannot finish this upload.', 'viral-video-ai' ) );
		}

		$directory = $this->session_dir( $handle );
		$part      = $directory . '/assembly.part';

		if ( ! is_file( $part ) ) {
			return new WP_Error( 'vvai_missing_parts', __( 'The uploaded data disappeared before the file could be assembled.', 'viral-video-ai' ) );
		}

		$missing = $this->missing_chunks( $meta );

		if ( $missing ) {
			return new WP_Error(
				'vvai_incomplete',
				sprintf(
					/* translators: %s: chunk numbers. */
					__( 'The upload is incomplete; chunks still missing: %s', 'viral-video-ai' ),
					implode( ', ', array_slice( $missing, 0, 20 ) )
				),
				array( 'missing' => $missing )
			);
		}

		if ( (int) filesize( $part ) !== (int) $meta['total_bytes'] ) {
			return new WP_Error(
				'vvai_size_mismatch',
				sprintf(
					/* translators: 1: declared size, 2: real size. */
					__( 'The assembled file is %1$s but the browser declared %2$s. Please upload again.', 'viral-video-ai' ),
					vvai_human_size( filesize( $part ) ),
					vvai_human_size( (int) $meta['total_bytes'] )
				)
			);
		}

		$final = (string) $meta['target_path'];
		$dir   = dirname( $final );

		if ( ! is_dir( $dir ) && ! vvai_mkdir( $dir ) ) {
			return new WP_Error( 'vvai_write_error', __( 'The media folder is not writable, so the video cannot be stored.', 'viral-video-ai' ) );
		}

		if ( ! @rename( $part, $final ) ) {
			// Cross-device move (uploads on a different volume): stream a copy.
			if ( ! $this->move_fallback( $part, $final ) ) {
				return new WP_Error( 'vvai_move_failed', __( 'The uploaded video could not be moved into place.', 'viral-video-ai' ) );
			}
		}

		@chmod( $final, 0640 );

		$sniff = $this->sniff( $final, (string) $meta['name'] );

		if ( is_wp_error( $sniff ) ) {
			@unlink( $final );
			$this->discard( $handle );

			return $sniff;
		}

		$meta['status']     = 'complete';
		$meta['finished']   = time();
		$meta['mime_type']  = $sniff['mime'];
		$meta['hash']       = $sniff['hash'];
		$meta['file_size']  = $sniff['size'];

		$this->write_meta( $handle, $meta );

		// The part directory is now empty; drop it.
		vvai_rrmdir( $directory );

		return array(
			'handle'    => $handle,
			'path'      => $final,
			'name'      => (string) vvai_array_get( $meta, 'display_name', (string) $meta['name'] ),
			'stored_name' => (string) $meta['name'],
			'size'      => (int) $sniff['size'],
			'humanSize' => vvai_human_size( (int) $sniff['size'] ),
			'mime'      => (string) $sniff['mime'],
			'hash'      => (string) $sniff['hash'],
			'extension' => (string) $meta['extension'],
		);
	}

	/**
	 * Import a video that already lives in the media library.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function from_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$path          = get_attached_file( $attachment_id );

		if ( ! $attachment_id || ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'vvai_attachment_missing', __( 'That media library item has no file on the server.', 'viral-video-ai' ) );
		}

		$type = get_post_type( $attachment_id );

		if ( 'attachment' !== $type ) {
			return new WP_Error( 'vvai_not_an_attachment', __( 'Select a media library video.', 'viral-video-ai' ) );
		}

		$sniff = $this->sniff( $path, (string) get_the_title( $attachment_id ) );

		if ( is_wp_error( $sniff ) ) {
			return $sniff;
		}

		return array(
			'handle'    => 'media-' . $attachment_id,
			'path'      => $path,
			'name'      => (string) basename( $path ),
			'size'      => (int) $sniff['size'],
			'humanSize' => vvai_human_size( (int) $sniff['size'] ),
			'mime'      => (string) $sniff['mime'],
			'hash'      => (string) $sniff['hash'],
			'extension' => strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ),
			'attachment' => $attachment_id,
		);
	}

	/**
	 * Copy a remote URL into the media pool.
	 *
	 * Direct video URLs only (no HTML pages): the file is streamed to disk with
	 * a hard byte ceiling and then sniffed like an upload.
	 *
	 * @param string $url     Source URL.
	 * @param int    $user_id Owner.
	 * @return array<string,mixed>|WP_Error
	 */
	public function from_url( $url, $user_id ) {
		$url = trim( (string) $url );

		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'vvai_bad_url', __( 'Enter a direct video URL starting with http:// or https://.', 'viral-video-ai' ) );
		}

		if ( ! VVAI_Connection_Store::is_valid_endpoint( $url ) ) {
			return new WP_Error( 'vvai_blocked_url', __( 'That address was refused: the plugin only downloads from public hosts (localhost and private networks are blocked).', 'viral-video-ai' ) );
		}

		$path  = wp_parse_url( $url );
		$name  = basename( (string) vvai_array_get( $path, 'path', 'video.mp4' ) );
		$name  = vvai_sanitize_filename( urldecode( $name ), 'source.mp4' );
		$check = $this->validate_extension( $name );

		if ( is_wp_error( $check ) ) {
			// A URL without a usable extension is still worth sniffing, so only
			// reject clearly non-video extensions.
			$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

			if ( in_array( $extension, array( 'html', 'php', 'txt', 'json', 'xml', 'js', 'css', 'zip', 'svg' ), true ) ) {
				return $check;
			}
		}

		$handle = 'up_' . vvai_random_id( 20 );
		$target = $this->target_path( $handle, $name );

		if ( ! is_dir( dirname( $target ) ) && ! vvai_mkdir( dirname( $target ) ) ) {
			return new WP_Error( 'vvai_write_error', __( 'The media folder is not writable.', 'viral-video-ai' ) );
		}

		$max = $this->settings->max_upload_bytes();

		// download_url() streams straight to a temp file, so the body never enters
		// PHP memory. A global `http_request_args` filter is deliberately NOT used
		// here: it would rewrite every unrelated HTTP request on the site.
		$download = download_url( $url, 300, false );

		if ( is_wp_error( $download ) ) {
			@unlink( $target );

			return new WP_Error(
				'vvai_download_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'The video could not be downloaded: %s', 'viral-video-ai' ),
					$download->get_error_message()
				)
			);
		}

		// download_url() wrote to its own temp file; move it into place.
		if ( is_file( $download ) ) {
			$size = (int) filesize( $download );

			if ( $size > $max ) {
				@unlink( $download );
				@unlink( $target );

				return new WP_Error(
					'vvai_too_large',
					sprintf(
						/* translators: 1: real size, 2: limit. */
						__( 'The linked video is %1$s, above this site\'s %2$s upload limit.', 'viral-video-ai' ),
						vvai_human_size( $size ),
						vvai_human_size( $max )
					)
				);
			}

			if ( ! @rename( $download, $target ) ) {
				$this->move_fallback( $download, $target );
				@unlink( $download );
			}
		} else {
			@unlink( $target );

			return new WP_Error( 'vvai_download_missing', __( 'The download produced no file on disk.', 'viral-video-ai' ) );
		}

		$sniff = $this->sniff( $target, $name );

		if ( is_wp_error( $sniff ) ) {
			@unlink( $target );

			return $sniff;
		}

		$meta = array(
			'handle'      => $handle,
			'user_id'     => (int) $user_id,
			'name'        => $name,
			'extension'   => strtolower( (string) pathinfo( $target, PATHINFO_EXTENSION ) ),
			'size'        => (int) $sniff['size'],
			'total_bytes' => (int) $sniff['size'],
			'chunk_size'  => 0,
			'chunk_total' => 1,
			'hash'        => (string) $sniff['hash'],
			'received'    => array( 0 ),
			'bytes'       => (int) $sniff['size'],
			'status'      => 'complete',
			'created'     => time(),
			'expires'     => time() + self::SESSION_TTL,
			'target_path' => $target,
			'source_url'  => esc_url_raw( $url ),
			'mime_type'   => (string) $sniff['mime'],
		);

		$this->write_meta( $handle, $meta );

		return array(
			'handle'    => $handle,
			'path'      => $target,
			'name'      => $name,
			'size'      => (int) $sniff['size'],
			'humanSize' => vvai_human_size( (int) $sniff['size'] ),
			'mime'      => (string) $sniff['mime'],
			'hash'      => (string) $sniff['hash'],
			'extension' => $meta['extension'],
			'sourceUrl' => esc_url_raw( $url ),
		);
	}

	/**
	 * Sniff the stored file: MIME + hard size + real media validation.
	 *
	 * @param string $path File path.
	 * @param string $name Display name.
	 * @return array<string,mixed>|WP_Error
	 */
	public function sniff( $path, $name = '' ) {
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'vvai_missing_file', __( 'The uploaded file is not readable on the server.', 'viral-video-ai' ) );
		}

		$size = (int) filesize( $path );

		if ( $size < 1024 ) {
			return new WP_Error( 'vvai_empty_file', __( 'The uploaded file is empty or far too small to be a video.', 'viral-video-ai' ) );
		}

		if ( $size > $this->settings->max_upload_bytes() ) {
			return new WP_Error( 'vvai_too_large', __( 'The stored file exceeds this site\'s upload limit.', 'viral-video-ai' ) );
		}

		$mime = '';

		if ( function_exists( 'finfo_open' ) ) {
			// phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.finfo_openFound -- guarded above.
			$finfo = finfo_open( FILEINFO_MIME_TYPE );

			if ( $finfo ) {
				$mime = (string) finfo_file( $finfo, $path );
				finfo_close( $finfo );
			}
		}

		if ( '' === $mime && function_exists( 'wp_check_filetype' ) ) {
			$checked = wp_check_filetype( $name ? $name : $path );
			$mime    = (string) vvai_array_get( $checked, 'type', '' );
		}

		$allowed = (array) apply_filters( 'vvai_allowed_mimes', self::VIDEO_MIMES, $path );

		if ( '' !== $mime && ! in_array( $mime, $allowed, true ) && 0 !== strpos( $mime, 'video/' ) ) {
			return new WP_Error(
				'vvai_bad_mime',
				sprintf(
					/* translators: %s: detected mime type. */
					__( 'The server detected "%s", which is not a video file. The upload was rejected.', 'viral-video-ai' ),
					$mime
				)
			);
		}

		// Magic-byte gate: ISO-BMFF/QuickTime, Matroska/WebM, or AVI RIFF. This is
		// what stops an HTML/zip/text payload from being stored as a "video".
		if ( ! $this->looks_like_video_container( $path ) ) {
			return new WP_Error( 'vvai_not_a_video', __( 'The file does not look like a video container (MP4/MOV/WebM/MKV/AVI). Re-export it and upload again.', 'viral-video-ai' ) );
		}

		return array(
			'mime' => ( '' !== $mime ? $mime : 'video/mp4' ),
			'size' => $size,
			'hash' => (string) vvai_fingerprint_file( $path ),
			'path' => $path,
		);
	}

	/**
	 * Read the first bytes and match known video container signatures.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public function looks_like_video_container( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- header sniff.
		$fp = @fopen( $path, 'rb' );

		if ( ! $fp ) {
			return false;
		}

		$header = (string) fread( $fp, 48 );
		fclose( $fp );

		if ( strlen( $header ) < 12 ) {
			return false;
		}

		// MP4 / MOV / M4V: 'ftyp' at offset 4.
		if ( 'ftyp' === substr( $header, 4, 4 ) ) {
			return true;
		}

		// EBML: Matroska and WebM.
		if ( "\x1a\x45\xdf\xa3" === substr( $header, 0, 4 ) ) {
			return true;
		}

		// AVI: 'RIFF....AVI '.
		if ( 'RIFF' === substr( $header, 0, 4 ) && 'AVI ' === substr( $header, 8, 4 ) ) {
			return true;
		}

		// MPEG program stream / TS.
		if ( "\x00\x00\x01\xba" === substr( $header, 0, 4 ) || "\x47" === $header[0] ) {
			return true;
		}

		// 3GP variant without ftyp at offset 4 (rare) is accepted if 'mdat' follows.
		return false !== strpos( $header, 'mdat' ) || false !== strpos( $header, 'moov' );
	}

	/**
	 * Delete a session and its parts.
	 *
	 * @param string $handle Handle.
	 * @return bool
	 */
	public function discard( $handle ) {
		$meta = $this->read_meta( $handle );
		$dir  = $this->session_dir( $handle );

		if ( $meta && ! empty( $meta['target_path'] ) && is_file( (string) $meta['target_path'] ) && 'complete' !== $meta['status'] ) {
			@unlink( (string) $meta['target_path'] );
		}

		if ( '' === $dir ) {
			return false;
		}

		return vvai_rrmdir( $dir );
	}

	/**
	 * Session status for the UI.
	 *
	 * @param string $handle Handle.
	 * @return array<string,mixed>|WP_Error
	 */
	public function status( $handle ) {
		$meta = $this->read_meta( $handle );

		if ( ! $meta ) {
			return new WP_Error( 'vvai_unknown_upload', __( 'Unknown upload session.', 'viral-video-ai' ) );
		}

		$received = $this->received_chunks( $handle );

		return array(
			'handle'     => $handle,
			'status'     => (string) $meta['status'],
			'received'   => count( $received ),
			'total'      => (int) $meta['chunk_total'],
			'missing'    => array_slice( $this->missing_chunks( $meta ), 0, 200 ),
			'bytes'      => (int) vvai_array_get( $meta, 'bytes', 0 ),
			'totalBytes' => (int) $meta['total_bytes'],
			'percentage' => (int) floor( ( count( $received ) / max( 1, (int) $meta['chunk_total'] ) ) * 100 ),
			'name'       => (string) $meta['name'],
			'expires'    => (int) $meta['expires'],
		);
	}

	/**
	 * Drop expired sessions and their orphaned files.
	 *
	 * @return array{removed:int,bytes:int}
	 */
	public function prune_expired() {
		$root    = vvai_storage_dir( 'tmp' );
		$removed = 0;
		$bytes   = 0;

		if ( ! is_dir( $root ) ) {
			return array(
				'removed' => 0,
				'bytes'   => 0,
			);
		}

		foreach ( (array) scandir( $root ) as $entry ) {
			if ( 0 !== strpos( $entry, 'vvai-upload-' ) ) {
				continue;
			}

			$directory = $root . '/' . $entry;
			$meta      = $this->read_meta( substr( $entry, strlen( 'vvai-upload-' ) ) );
			$expires   = $meta ? (int) $meta['expires'] : ( filemtime( $directory ) + self::SESSION_TTL );

			if ( $expires > time() ) {
				continue;
			}

			foreach ( (array) scandir( $directory ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$file = $directory . '/' . $entry;

				if ( is_file( $file ) ) {
					$bytes += (int) filesize( $file );
					@unlink( $file );
				}
			}

			if ( $meta && ! empty( $meta['target_path'] ) && is_file( (string) $meta['target_path'] ) && 'complete' !== $meta['status'] ) {
				$bytes += (int) @filesize( (string) $meta['target_path'] );
				@unlink( (string) $meta['target_path'] );
			}

			@rmdir( $directory );
			$removed++;
		}

		return array(
			'removed' => $removed,
			'bytes'   => $bytes,
		);
	}

	/**
	 * Allowed extensions from settings (validated).
	 *
	 * @return string[]
	 */
	public function allowed_extensions() {
		$configured = (array) $this->settings->get( 'allowed_extensions' );
		$allowed    = array();

		foreach ( $configured as $extension ) {
			$extension = strtolower( ltrim( (string) $extension, '.' ) );

			if ( in_array( $extension, self::VIDEO_EXTENSIONS, true ) ) {
				$allowed[] = $extension;
			}
		}

		return $allowed ? $allowed : array( 'mp4', 'mov', 'webm', 'mkv' );
	}

	/**
	 * Validate an extension against the allowed list.
	 *
	 * @param string $name File name.
	 * @return true|WP_Error
	 */
	public function validate_extension( $name ) {
		$extension = strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );

		if ( '' === $extension || ! in_array( $extension, $this->allowed_extensions(), true ) ) {
			return new WP_Error(
				'vvai_bad_extension',
				sprintf(
					/* translators: %s: list of extensions. */
					__( 'Unsupported file type "%s". Allowed: %s', 'viral-video-ai' ),
					( '' !== $extension ? $extension : 'unknown' ),
					implode( ', ', $this->allowed_extensions() )
				)
			);
		}

		return true;
	}


	/**
	 * Which chunk indexes are already on disk.
	 *
	 * @param string $handle Session handle.
	 * @return int[]
	 */
	public function received_chunks( $handle ) {
		$directory = $this->session_dir( $handle );
		$index     = $directory . '/received.json';
		$received  = array();

		if ( is_file( $index ) ) {
			$decoded = vvai_json_decode( file_get_contents( $index ) );

			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $value ) {
					if ( is_numeric( $value ) ) {
						$received[] = (int) $value;
					}
				}
			}
		}

		return array_values( array_unique( $received ) );
	 }

	/**
	 * Persist the received chunk list.
	 *
	 * @param string $handle   Session handle.
	 * @param int[]  $received Indexes.
	 */
	protected function put_received( $handle, array $received ) {
		$directory = $this->session_dir( $handle );

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- session bookkeeping.
			$directory . '/received.json',
			wp_json_encode( array_values( array_map( 'intval', $received ) ) ),
			LOCK_EX
		);
	}

	/**
	 * Missing chunk indexes.
	 *
	 * @param array<string,mixed> $meta Session meta.
	 * @return int[]
	 */
	public function missing_chunks( array $meta ) {
		$received = $this->received_chunks( (string) $meta['handle'] );
		$missing  = array();

		for ( $index = 0; $index < (int) $meta['chunk_total']; $index++ ) {
			if ( ! in_array( $index, $received, true ) ) {
				$missing[] = $index;
			}
		}

		return $missing;
	}

	/**
	 * Is every chunk present?
	 *
	 * @param array<string,mixed> $meta Session meta.
	 * @return bool
	 */
	protected function is_complete( array $meta ) {
		return ! $this->missing_chunks( $meta );
	}


	/**
	 * Session directory inside uploads, with the plugin's standard guards.
	 *
	 * @param string $handle Session handle.
	 * @param bool   $create Create when missing.
	 * @return string
	 */
	protected function session_dir( $handle, $create = false ) {
		$handle = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $handle );

		if ( '' === $handle || strlen( $handle ) < 6 ) {
			return '';
		}

		$directory = vvai_storage_dir( 'tmp/vvai-upload-' . $handle );

		if ( $create ) {
			return vvai_mkdir( $directory ) ? $directory : '';
		}

		return is_dir( $directory ) ? $directory : '';
	}

	/**
	 * Where a finished upload is stored.
	 *
	 * @param string $handle Session handle.
	 * @param string $name   Original name (extension reused).
	 * @return string
	 */
	protected function target_path( $handle, $name ) {
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$extension = in_array( $extension, self::VIDEO_EXTENSIONS, true ) ? $extension : 'mp4';

		return vvai_storage_dir( 'sources' ) . '/source-' . preg_replace( '/[^a-zA-Z0-9_-]/', '', $handle ) . '.' . $extension;
	}

	/**
	 * Read session meta.
	 *
	 * @param string $handle Handle.
	 * @return array<string,mixed>|null
	 */
	protected function read_meta( $handle ) {
		$directory = $this->session_dir( $handle );

		if ( '' === $directory ) {
			return null;
		}

		$file = $directory . '/meta.json';

		if ( ! is_file( $file ) ) {
			return null;
		}

		$meta = vvai_json_decode( file_get_contents( $file ) );

		return is_array( $meta ) ? $meta : null;
	}

	/**
	 * Write session meta.
	 *
	 * @param string              $handle Handle.
	 * @param array<string,mixed> $meta   Meta.
	 */
	protected function write_meta( $handle, array $meta ) {
		$directory = $this->session_dir( $handle, true );

		if ( '' === $directory ) {
			return;
		}

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- session bookkeeping.
			$directory . '/meta.json',
			wp_json_encode( $meta ),
			LOCK_EX
		);
	}

	/**
	 * Find an open session with the same fingerprint (instant resume/reuse).
	 *
	 * @param int    $user_id User.
	 * @param string $hash    Client hash.
	 * @param int    $size    Bytes.
	 * @return array<string,mixed>|null
	 */
	protected function find_by_hash( $user_id, $hash, $size ) {
		$hash = strtolower( preg_replace( '/[^a-f0-9]/', '', (string) $hash ) );

		if ( '' === $hash || strlen( $hash ) < 16 ) {
			return null;
		}

		$root = vvai_storage_dir( 'tmp' );

		if ( ! is_dir( $root ) ) {
			return null;
		}

		foreach ( (array) scandir( $root ) as $entry ) {
			if ( 0 !== strpos( $entry, 'vvai-upload-' ) ) {
				continue;
			}

			$meta = $this->read_meta( substr( $entry, strlen( 'vvai-upload-' ) ) );

			if ( ! $meta ) {
				continue;
			}

			if ( (int) $meta['user_id'] === (int) $user_id
				&& (string) $meta['hash'] === $hash
				&& (int) $meta['total_bytes'] === (int) $size
				&& (int) $meta['expires'] > time() ) {
				return $meta;
			}
		}

		return null;
	}

	/**
	 * rename() across filesystems fails; copy + unlink instead.
	 *
	 * @param string $from Source.
	 * @param string $to   Target.
	 * @return bool
	 */
	protected function move_fallback( $from, $to ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed copy.
		$source = @fopen( $from, 'rb' );
		$sink   = @fopen( $to, 'wb' );

		if ( ! $source || ! $sink ) {
			if ( is_resource( $source ) ) {
				fclose( $source );
			}

			if ( is_resource( $sink ) ) {
				fclose( $sink );
			}

			return false;
		}

		while ( ! feof( $source ) ) {
			$buffer = fread( $source, 2 * MB_IN_BYTES );

			if ( false === $buffer || '' === $buffer ) {
				break;
			}

			fwrite( $sink, $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streamed copy.
		}

		fclose( $source );
		fclose( $sink );

		return is_file( $to ) && ( (int) filesize( $to ) === (int) filesize( $from ) );
	}
}
