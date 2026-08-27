<?php
/**
 * Results, authorised streaming and retention.
 *
 * Generated clips live under uploads/vvai/jobs/job-{id}/ which is protected by
 * an .htaccess deny rule and an index.php guard, so a file can only ever be read
 * through this class: it checks the caller (capability or job ownership, or a
 * short-lived HMAC token), resolves the path from the database row only, and
 * streams with byte-range support so the browser can seek inside the preview.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Result_Manager
 */
class VVAI_Result_Manager {

	/**
	 * @var VVAI_Job_Manager
	 */
	private $jobs;

	/**
	 * @var VVAI_Clip_Repository
	 */
	private $clips;

	/**
	 * @var VVAI_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param VVAI_Job_Manager|null      $jobs     Jobs.
	 * @param VVAI_Clip_Repository|null  $clips    Clips.
	 * @param VVAI_Settings|null         $settings Settings.
	 */
	public function __construct( $jobs = null, $clips = null, $settings = null ) {
		$this->settings = $settings instanceof VVAI_Settings ? $settings : new VVAI_Settings();
		$this->jobs     = $jobs instanceof VVAI_Job_Manager ? $jobs : new VVAI_Job_Manager();
		$this->clips    = $clips instanceof VVAI_Clip_Repository ? $clips : new VVAI_Clip_Repository();
	}

	/**
	 * Public clip payloads for a job, ready for the frontend.
	 *
	 * @param int  $job_id Job id.
	 * @param bool $signed Whether to include tokenised URLs (for guests/links).
	 * @return array<int,array<string,mixed>>
	 */
	public function clip_payloads( $job_id, $signed = true ) {
		$order = (string) $this->settings->get( 'results_order' );
		$rows  = $this->clips->for_job( (int) $job_id, in_array( $order, array( 'chrono', 'index' ), true ) ? 'chrono' : 'score' );
		$out   = array();

		foreach ( $rows as $row ) {
			if ( '' === (string) $row['file_path'] || ! is_file( (string) $row['file_path'] ) ) {
				continue;
			}

			$out[] = $this->payload( $row, $signed );
		}

		return $out;
	}

	/**
	 * One clip as it is exposed to clients. Never contains a filesystem path.
	 *
	 * @param array<string,mixed> $clip    Clip row.
	 * @param bool                $signed  Include tokenised URLs.
	 * @return array<string,mixed>
	 */
	public function payload( array $clip, $signed = true ) {
		$id       = (int) $clip['id'];
		$duration = (float) $clip['duration'];
		$payload  = array(
			'id'           => $id,
			'jobId'        => (int) $clip['job_id'],
			'index'        => (int) $clip['clip_index'],
			'number'       => ( (int) $clip['clip_index'] ) + 1,
			'title'        => (string) $clip['title'],
			'caption'      => (string) $clip['caption'],
			'hashtags'     => (array) $clip['hashtags'],
			'hashtagText'  => implode( ' ', array_map( 'strval', (array) $clip['hashtags'] ) ),
			'score'        => (int) $clip['viral_score'],
			'reasoning'    => (string) $clip['reasoning'],
			'start'        => round( (float) $clip['start_time'], 2 ),
			'end'          => round( (float) $clip['end_time'], 2 ),
			'startLabel'   => vvai_format_time( (float) $clip['start_time'] ),
			'endLabel'     => vvai_format_time( (float) $clip['end_time'] ),
			'duration'     => $duration,
			'durationLabel' => vvai_format_time( $duration ),
			'size'         => (int) $clip['file_size'],
			'sizeLabel'    => vvai_human_size( (int) $clip['file_size'] ),
			'width'        => (int) $clip['width'],
			'height'       => (int) $clip['height'],
			'aspect'       => (string) $clip['aspect_ratio'],
			'quality'      => (string) $clip['quality'],
			'cropMode'     => (string) $clip['crop_mode'],
			'fileName'     => (string) $clip['file_name'],
			'downloads'    => (int) $clip['download_count'],
			'createdAt'    => (string) $clip['created_at'],
			'hasCaptions'  => ( '' !== (string) $clip['srt_path'] && is_file( (string) $clip['srt_path'] ) ),
		);

		$payload['previewUrl'] = $this->file_url( $id, 'preview', $signed );
		$payload['downloadUrl'] = $this->file_url( $id, 'download', $signed );
		$payload['captionUrl']  = $this->file_url( $id, 'captions', $signed );

		return $payload;
	}

	/**
	 * URL for a clip asset, tokenised when the viewer may not be logged in.
	 *
	 * @param int    $clip_id Clip id.
	 * @param string $mode    preview|download|captions.
	 * @param bool   $signed  Sign the URL.
	 * @return string
	 */
	public function file_url( $clip_id, $mode = 'preview', $signed = true ) {
		$mode = in_array( $mode, array( 'preview', 'download', 'captions' ), true ) ? $mode : 'preview';

		$url = add_query_arg(
			array( 'mode' => $mode ),
			rest_url( sprintf( '%1$s/clips/%2$d/file', VVAI_REST_NAMESPACE, (int) $clip_id ) )
		);

		if ( $signed && ! is_user_logged_in() ) {
			$url = add_query_arg( 'vvai_token', $this->issue_token( (int) $clip_id ), $url );
		}

		return $url;
	}

	/**
	 * Issue a short-lived, single-purpose access token for a clip.
	 *
	 * @param int $clip_id Clip id.
	 * @param int $ttl     Seconds.
	 * @return string
	 */
	public function issue_token( $clip_id, $ttl = 0 ) {
		$clip_id = (int) $clip_id;
		$ttl     = $ttl > 0 ? $ttl : max( 60, (int) $this->settings->get( 'download_link_ttl' ) );
		$expires = time() + $ttl;

		return $expires . '.' . hash_hmac( 'sha256', 'clip|' . $clip_id . '|' . $expires, $this->token_secret() );
	}

	/**
	 * Verify a clip token.
	 *
	 * @param int    $clip_id Clip id.
	 * @param string $token    Token.
	 * @return bool
	 */
	public function verify_token( $clip_id, $token ) {
		$token = (string) $token;

		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return false;
		}

		list( $expires, $signature ) = explode( '.', $token, 2 );

		if ( ! is_numeric( $expires ) || (int) $expires < time() ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', 'clip|' . (int) $clip_id . '|' . (int) $expires, $this->token_secret() );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Secret used for clip tokens; tied to the site salts.
	 *
	 * @return string
	 */
	protected function token_secret() {
		$secret = (string) wp_salt( 'auth' );

		/**
		 * Filter the secret used to sign clip download tokens.
		 *
		 * @param string $secret Secret.
		 */
		return (string) apply_filters( 'vvai_token_secret', $secret . '|vvai-clips' );
	}

	/**
	 * May this request read this clip file?
	 *
	 * @param int    $clip_id Clip id.
	 * @param string $token    Query token (may be empty).
	 * @param string $mode     preview|download|captions.
	 * @return array{clip:array<string,mixed>,job:array<string,mixed>,path:string,name:string,mime:string,mode:string}|WP_Error
	 */
	public function authorize( $clip_id, $token = '', $mode = 'download' ) {
		$clip_id = (int) $clip_id;
		$mode    = in_array( $mode, array( 'preview', 'download', 'captions' ), true ) ? $mode : 'download';
		$clip    = $this->clips->get( $clip_id );

		if ( ! $clip ) {
			return new WP_Error( 'vvai_clip_missing', __( 'This clip no longer exists.', 'viral-video-ai' ), array( 'status' => 404 ) );
		}

		$job = $this->jobs->get( (int) $clip['job_id'] );

		if ( ! $job ) {
			return new WP_Error( 'vvai_job_missing', __( 'The job this clip belongs to was deleted.', 'viral-video-ai' ), array( 'status' => 404 ) );
		}

		$allowed = false;

		if ( VVAI_Permissions::can_modify_job( $job ) ) {
			$allowed = true;
		} elseif ( '' !== $token && $this->verify_token( $clip_id, $token ) ) {
			$allowed = true;
		} elseif ( $this->settings->get( 'allow_public_downloads' ) && VVAI_Job_Status::COMPLETED === (string) $job['status'] ) {
			$allowed = true;
		}

		/**
		 * Filter clip file access.
		 *
		 * @param bool  $allowed Allowed.
		 * @param array $clip       Clip row.
		 * @param array $job        Job row.
		 * @param string $mode       Mode.
		 */
		$allowed = (bool) apply_filters( 'vvai_allow_clip_access', $allowed, $clip, $job, $mode );

		if ( ! $allowed ) {
			return new WP_Error( 'vvai_forbidden', __( 'You are not allowed to download this clip.', 'viral-video-ai' ), array( 'status' => 403 ) );
		}

		// Captions are optional; missing files are reported, never guessed at.
		if ( 'captions' === $mode ) {
			$path = (string) $clip['srt_path'];

			if ( '' === $path || ! is_file( $path ) ) {
				$sidecar = preg_replace( '/\.mp4$/.i', '', (string) $clip['file_path'] ) . '.srt';
				$path    = is_file( $sidecar ) ? $sidecar : '';
			}

			if ( '' === $path ) {
				return new WP_Error( 'vvai_caption_missing', __( 'No caption file was generated for this clip.', 'viral-video-ai' ), array( 'status' => 404 ) );
			}

			return array(
				'clip' => $clip,
				'job'  => $job,
				'path' => $path,
				'name' => (string) pathinfo( $path, PATHINFO_FILENAME ) . '.srt',
				'mime' => 'application/x-subrip',
				'mode' => $mode,
			);
		}

		$path = $this->resolve_path( (string) $clip['file_path'], (int) $clip['job_id'], (int) $clip['clip_index'] );

		if ( '' === $path ) {
			return new WP_Error( 'vvai_file_missing', __( 'The rendered file is no longer on the server (it may have expired under the retention policy).', 'viral-video-ai' ), array( 'status' => 410 ) );
		}

		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$mimes     = array(
			'mp4'  => 'video/mp4',
			'mov'  => 'video/quicktime',
			'webm' => 'video/webm',
			'mkv'  => 'video/x-matroska',
		);

		if ( ! isset( $mimes[ $extension ] ) ) {
			// The stored extension is not a container this endpoint serves.
			return new WP_Error( 'vvai_bad_container', __( 'This clip uses an unsupported container and cannot be served.', 'viral-video-ai' ), array( 'status' => 415 ) );
		}

		return array(
			'clip' => $clip,
			'job'  => $job,
			'path' => $path,
			'name' => sprintf(
				'%s.mp4',
				'clip-' . str_pad( (string) ( (int) $clip['clip_index'] + 1 ), 3, '0', STR_PAD_LEFT ) . '-' . vvai_slug( (string) $clip['title'], 40 )
			),
			'mime' => $mimes[ $extension ],
			'mode' => $mode,
		);
	}

	/**
	 * Resolve and validate the stored path.
	 *
	 * Two independent guards: the path must sit inside the plugin storage root,
	 * and it must match the deterministic path for this job/clip. A tampered
	 * database value therefore cannot be used to read an arbitrary file.
	 *
	 * @param string $stored  Stored path.
	 * @param int    $job_id  Job id.
	 * @param int    $index   Clip index.
	 * @return string Absolute path or ''.
	 */
	public function resolve_path( $stored, $job_id, $index ) {
		$stored = wp_normalize_path( (string) $stored );

		if ( '' === $stored || ! is_file( $stored ) || is_link( $stored ) ) {
			return '';
		}

		$root = wp_normalize_path( vvai_storage_dir() );

		if ( 0 !== strpos( $stored, $root . '/jobs/' ) ) {
			return '';
		}

		$expected = vvai_storage_dir( sprintf( 'jobs/job-%d/clip-%03d.mp4', (int) $job_id, (int) $index ) );
		$webm     = vvai_storage_dir( sprintf( 'jobs/job-%d/clip-%03d.webm', (int) $job_id, (int) $index ) );

		if ( $stored !== wp_normalize_path( $expected ) && $stored !== wp_normalize_path( $webm ) ) {
			return '';
		}

		if ( false !== strpos( basename( $stored ), '..' ) ) {
			return '';
		}

		return $stored;
	}

	/**
	 * Stream a file with Range support.
	 *
	 * @param array<string,mixed> $file authorize() result.
	 */
	public function stream( array $file ) {
		$path = (string) vvai_array_get( $file, 'path', '' );
		$mime = (string) vvai_array_get( $file, 'mime', 'application/octet-stream' );
		$name = (string) vvai_array_get( $file, 'name', 'clip.mp4' );
		$mode = (string) vvai_array_get( $file, 'mode', 'preview' );

		if ( '' === $path || ! is_file( $path ) ) {
			if ( $this->may_send_headers() ) {
				http_response_code( 404 );
			}

			return;
		}

		$size = (int) filesize( $path );

		if ( $size <= 0 ) {
			if ( $this->may_send_headers() ) {
				http_response_code( 410 );
			}

			return;
		}

		if ( $this->may_send_headers() ) {
			nocache_headers();

			header( 'Content-Type: ' . $mime );
			header( 'Accept-Ranges: bytes' );
			header( 'Content-Length: ' . $size );
			header(
				'Content-Disposition: ' . ( 'download' === $mode ? 'attachment' : 'inline' ) . '; filename="' . str_replace( '"', '', $name ) . '"'
			);

			if ( 'download' !== $mode ) {
				header( 'Cache-Control: private, max-age=3600' );
			}

			header( 'X-Content-Type-Options: nosniff' );
		}

		$start = 0;
		$end   = $size - 1;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

		// The header is `Range: bytes=START-END`, so the separator is an equals
		// sign (`bytes=0-1023`); `bytes=100-` means "to the end" and a multi-range
		// header is answered with its first range, as required by RFC 7233 §3.1.
		// Ignoring any of these makes <video> seeking restart the whole download.
		if ( '' !== $range && preg_match( '/bytes=\s*(\d+)\s*(?:-\s*(\d*))?/', $range, $m ) ) {
			$m[2] = isset( $m[2] ) ? $m[2] : '';

			if ( '' !== $m[1] && '' === $m[2] ) {
				// bytes=N-  → from N to the end of the file.
				$start = min( (int) $m[1], max( 0, $size - 1 ) );
				$end    = $size - 1;
			} else {
				if ( '' !== $m[1] ) {
					$start = min( (int) $m[1], max( 0, $size - 1 ) );
				}

				if ( '' !== $m[2] ) {
					$end = min( (int) $m[2], $size - 1 );
				}
			}

			if ( $start > $end ) {
				if ( $this->may_send_headers() ) {
					header( 'HTTP/1.1 416 Requested Range Not Satisfiable' );
					header( 'Content-Range: bytes */' . $size );
				}

				return;
			}

			if ( ( 0 !== $start || $end !== ( $size - 1 ) ) && $this->may_send_headers() ) {
				http_response_code( 206 );
				header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
				header( 'Content-Length: ' . ( $end - $start + 1 ) );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to the client.
		$handle = fopen( $path, 'rb' );

		if ( ! $handle ) {
			http_response_code( 500 );

			return;
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$remaining = ( $end - $start ) + 1;
		$chunk     = 512 * KB_IN_BYTES;

		while ( $remaining > 0 && ! feof( $handle ) && 0 === ( connection_aborted() ) ) {
			$read   = (int) min( $chunk, $remaining );
			$buffer = fread( $handle, $read );

			if ( false === $buffer || '' === $buffer ) {
				break;
			}

			$remaining -= strlen( $buffer );

			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary media stream.
			flush();
		}

		fclose( $handle );
	}

	/**
	 * Can response headers still be written?
	 *
	 * Guards against the "headers already sent" warnings hosts produce when a
	 * previous plugin already echoed output, and lets the CLI test harness skip
	 * header emission while still exercising the byte-range logic.
	 *
	 * @return bool
	 */
	protected function may_send_headers() {
		if ( ! empty( $GLOBALS['vvai_test']['no_headers'] ) ) {
			return false;
		}

		return ! headers_sent();
	}

	/**
	 * Delete every file belonging to a job plus its rows.
	 *
	 * @param int $job_id Job id.
	 * @return array{files:int,bytes:int,clips:int}
	 */
	public function delete_job_files( $job_id ) {
		$report = array(
			'files' => 0,
			'bytes' => 0,
			'clips' => 0,
		);

		$job = $this->jobs->get( $job_id );

		if ( ! $job ) {
			return $report;
		}

		foreach ( $this->clips->for_job( (int) $job_id, 'index' ) as $clip ) {
			foreach ( array( 'file_path', 'srt_path' ) as $key ) {
				$path = (string) $clip[ $key ];

				if ( '' === $path ) {
					continue;
				}

				$resolved = 'file_path' === $key
					? $this->resolve_path( $path, (int) $job_id, (int) $clip['clip_index'] )
					: $this->resolve_sibling( $path, (int) $job_id );

				if ( '' !== $resolved && is_file( $resolved ) ) {
					$report['bytes'] += (int) filesize( $resolved );
					@unlink( $resolved );
					$report['files']++;
				}
			}
		}

		$report['clips'] = $this->clips->delete_for_job( (int) $job_id );

		// Sources are only inside the storage root, verified before unlinking.
		$source = $this->resolve_sibling( (string) $job['source_path'], (int) $job_id );

		if ( '' !== $source ) {
			$report['bytes'] += (int) filesize( $source );
			@unlink( $source );
			$report['files']++;
		}

		$directory = vvai_storage_dir( 'jobs/job-' . (int) $job_id );

		if ( is_dir( $directory ) ) {
			vvai_rrmdir( $directory );
		}

		$job_dir = vvai_storage_dir( 'tmp/job-' . (int) $job_id );

		if ( is_dir( $job_dir ) ) {
			vvai_rrmdir( $job_dir );
		}

		return $report;
	}

	/**
	 * Guard a stored path that is not a clip file (source, sidecar).
	 *
	 * @param string $stored Stored path.
	 * @param int    $job_id Job id (0 = any job of this plugin).
	 * @return string Safe path or ''.
	 */
	public function resolve_sibling( $stored, $job_id = 0 ) {
		$stored = wp_normalize_path( (string) $stored );

		if ( '' === $stored || ! is_file( $stored ) || is_link( $stored ) ) {
			return '';
		}

		$root = wp_normalize_path( vvai_storage_dir() );

		if ( 0 !== strpos( $stored, $root . '/' ) ) {
			return '';
		}

		if ( 0 !== strpos( basename( dirname( $stored ) ), 'job-' ) && 'sources' !== basename( dirname( $stored ) ) && 'tmp' !== basename( dirname( $stored ) ) ) {
			return '';
		}

		if ( $job_id > 0 && false !== strpos( dirname( $stored ), 'job-' ) ) {
			$folder = basename( dirname( $stored ) );

			if ( 'job-' . (int) $job_id !== $folder ) {
				return '';
			}
		}

		// Only media/subtitle extensions are ever served from disk.
		$extension = strtolower( (string) pathinfo( $stored, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'mp4', 'mov', 'webm', 'mkv', 'm4v', 'srt', 'json', 'mp3', 'txt', 'part' ), true ) ) {
			return '';
		}

		return $stored;
	}

	/**
	 * Enforce the retention policy.
	 *
	 * @return array{jobs:int,files:int,bytes:int}
	 */
	public function enforce_retention() {
		$report = array(
			'jobs'  => 0,
			'files' => 0,
			'bytes' => 0,
		);

		foreach ( $this->jobs->jobs_due_for_cleanup( 100 ) as $job ) {
			$deleted = $this->delete_job_files( (int) $job['id'] );

			$report['jobs']++;
			$report['files'] += (int) $deleted['files'];
			$report['bytes'] += (int) $deleted['bytes'];

			// The row survives as history, but is marked cleaned so it is never
			// swept again and the results grid shows a clear explanation.
			$settings = (array) $job['settings_array'];
			$settings['cleaned_at'] = gmdate( 'Y-m-d H:i:s' );

			$this->jobs->update(
				(int) $job['id'],
				array(
					'source_path'   => '',
					'cleanup_after' => '1970-01-01 00:00:00',
					'settings'      => wp_json_encode( $settings ),
				)
			);
		}

		return $report;
	}

	/**
	 * Delete scratch folders left behind by crashed jobs.
	 *
	 * @param int $max_age Seconds.
	 * @return array{folders:int,bytes:int}
	 */
	public function sweep_orphan_temp( $max_age ) {
		$root    = vvai_storage_dir( 'tmp' );
		$folders = 0;
		$bytes   = 0;

		if ( ! is_dir( $root ) ) {
			return array(
				'folders' => 0,
				'bytes'   => 0,
			);
		}

		foreach ( (array) scandir( $root ) as $entry ) {
			if ( 0 !== strpos( $entry, 'job-' ) ) {
				continue;
			}

			$path  = $root . '/' . $entry;
			$age   = time() - (int) filemtime( $path );
			$job_id = (int) substr( $entry, 4 );
			$job    = $job_id > 0 ? $this->jobs->get( $job_id ) : null;

			// Never touch the scratch of a job that is still running.
			if ( $job && ! VVAI_Job_Status::is_terminal( (string) $job['status'] ) ) {
				continue;
			}

			// A folder with no job row at all is garbage: the row was deleted while
			// files remained. Waiting for the age threshold would leak disk forever.
			if ( $job && $age < $max_age ) {
				continue;
			}

			// Includes the guard dotfiles, otherwise the folder survives forever.
			foreach ( (array) scandir( $path ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$file = $path . '/' . $entry;

				if ( is_file( $file ) ) {
					$bytes += (int) filesize( $file );
					@unlink( $file );
				}
			}

			@rmdir( $path );
			$folders++;
		}

		return array(
			'folders' => $folders,
			'bytes'   => $bytes,
		);
	}

	/**
	 * Storage usage of the plugin, for the dashboard.
	 *
	 * @return array{bytes:int,files:int,clips:int,sources:int}
	 */
	public function storage_usage() {
		$root  = vvai_storage_dir();
		$usage = array(
			'bytes'   => 0,
			'files'   => 0,
			'clips'   => 0,
			'sources' => 0,
		);

		if ( ! is_dir( $root ) ) {
			return $usage;
		}

		$stack = array( $root );

		while ( $stack ) {
			$directory = array_pop( $stack );

			foreach ( (array) scandir( $directory ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$path = $directory . '/' . $entry;

				if ( is_dir( $path ) ) {
					$stack[] = $path;
					continue;
				}

				if ( ! is_file( $path ) ) {
					continue;
				}

				$size = (int) filesize( $path );

				if ( 0 === $size ) {
					continue;
				}

				$usage['bytes'] += $size;
				$usage['files']++;

				if ( 'mp4' === strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
					$usage['clips']++;
				}

				if ( 'sources' === basename( $directory ) ) {
					$usage['sources']++;
				}
			}
		}

		return $usage;
	}

	/**
	 * Jobs for the dashboard widget (newest first, tiny payload).
	 *
	 * @param int $count Number of rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_jobs( $count = 8 ) {
		$page = $this->jobs->query(
			array(
				'per_page' => max( 1, min( 50, (int) $count ) ),
				'page'     => 1,
				'order_by' => 'created_at',
				'order'    => 'desc',
			)
		);

		return (array) vvai_array_get( $page, 'items', array() );
	}
}
