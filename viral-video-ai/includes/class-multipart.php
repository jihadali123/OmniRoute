<?php
/**
 * Multipart/form-data body builder.
 *
 * WordPress' HTTP API hands `body` arrays to the Requests library, whose
 * multipart behaviour differs between the cURL and the streams transport (and
 * between Requests versions). Building the body here keeps file uploads
 * deterministic on every host.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Multipart
 */
final class VVAI_Multipart {

	/**
	 * Hard ceiling for one upload payload, guarding PHP memory.
	 */
	const MAX_FILE_BYTES = 52428800; // 50 MiB.

	/**
	 * Build the body.
	 *
	 * @param array<string,string|int|float> $fields Scalar form fields.
	 * @param array<int,array{name:string,filename:string,type?:string,path?:string,content?:string}> $files Files.
	 * @return array{content_type:string,body:string,size:int}|WP_Error
	 */
	public static function build( array $fields, array $files = array() ) {
		$boundary = '----vvai' . bin2hex( random_bytes( 12 ) );
		$body     = '';

		foreach ( $fields as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = (string) reset( $value );
			}

			$body .= "--{$boundary}\r\n";
			$body .= 'Content-Disposition: form-data; name="' . self::quote( (string) $name ) . "\"\r\n\r\n";
			$body .= (string) $value . "\r\n";
		}

		foreach ( $files as $file ) {
			$name     = isset( $file['name'] ) ? (string) $file['name'] : 'file';
			$filename = isset( $file['filename'] ) ? vvai_sanitize_filename( $file['filename'], 'audio.wav' ) : 'file.bin';
			$mime     = isset( $file['type'] ) ? (string) $file['type'] : 'application/octet-stream';
			$contents = '';

			if ( isset( $file['path'] ) && is_string( $file['path'] ) && '' !== $file['path'] ) {
				if ( ! is_file( $file['path'] ) ) {
					return new WP_Error( 'missing_upload_file', __( 'The file to upload no longer exists.', 'viral-video-ai' ) );
				}

				$size = (int) filesize( $file['path'] );

				if ( $size > self::MAX_FILE_BYTES ) {
					return new WP_Error(
						'upload_too_large_for_transport',
						sprintf(
							/* translators: %s: human size. */
							__( 'This file (%s) exceeds the per-request multipart ceiling. Lower the transcription chunk size in the plugin settings.', 'viral-video-ai' ),
							vvai_human_size( $size )
						)
					);
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- audio chunk must be in memory to sign the request.
				$contents = file_get_contents( $file['path'] );
			} elseif ( isset( $file['content'] ) ) {
				$contents = (string) $file['content'];
			} else {
				continue;
			}

			if ( '' === $contents ) {
				return new WP_Error( 'empty_upload_file', __( 'The file to upload is empty.', 'viral-video-ai' ) );
			}

			$body .= "--{$boundary}\r\n";
			$body .= 'Content-Disposition: form-data; name="' . self::quote( $name ) . '"; filename="' . self::quote( $filename ) . "\"\r\n";
			// A MIME type is interpolated into a header, so it is validated rather
			// than trusted. The delimiter must not be a character the value may
			// legitimately contain — '#' is legal in a subtype, so ~ is used here
			// (a '#' delimiter silently turns every type into octet-stream).
			$safe_mime = preg_match( '~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~', $mime ) ? $mime : 'application/octet-stream';

			$body .= 'Content-Type: ' . $safe_mime . "\r\n\r\n";
			$body .= $contents . "\r\n";
		}

		$body .= "--{$boundary}--\r\n";

		return array(
			'content_type' => 'multipart/form-data; boundary=' . $boundary,
			'body'         => $body,
			'size'         => strlen( $body ),
		);
	}

	/**
	 * Escape a header parameter.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function quote( $value ) {
		$value = str_replace( array( "\r", "\n", '"', '\\' ), array( ' ', ' ', '', '' ), $value );

		return substr( $value, 0, 200 );
	}
}
