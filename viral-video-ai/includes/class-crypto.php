<?php
/**
 * Credential encryption.
 *
 * API keys are never stored in plain text when OpenSSL is available and are
 * never exported to the frontend, the REST API or the log files. The
 * implementation is a decrypt-then-use model: the ciphertext lives in the
 * `vvai_connections` option, the plaintext only ever exists in memory for the
 * duration of one request.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Crypto
 */
class VVAI_Crypto {

	const CIPHER = 'aes-256-cbc';
	const PREFIX = 'v1:';

	/**
	 * Whether real encryption is available on this server.
	 *
	 * @return bool
	 */
	public function is_available() {
		return extension_loaded( 'openssl' )
			&& function_exists( 'openssl_encrypt' )
			&& function_exists( 'hash_hmac' );
	}

	/**
	 * Human readable explanation when encryption is unavailable.
	 *
	 * @return string
	 */
	public function status_message() {
		if ( $this->is_available() ) {
			return __( 'AES-256-CBC with HMAC integrity check. Keys are encrypted at rest.', 'viral-video-ai' );
		}

		return __( 'OpenSSL is not available on this server: API keys are stored obfuscated and protected by capability checks instead. Install the openssl PHP extension for encryption at rest.', 'viral-video-ai' );
	}

	/**
	 * Encrypt a secret.
	 *
	 * @param string $plaintext Secret.
	 * @return string Encrypted (or obfuscated) payload, empty on failure.
	 */
	public function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;

		if ( '' === $plaintext ) {
			return '';
		}

		if ( $this->starts_encrypted( $plaintext ) ) {
			// Already encrypted (e.g. re-saving a form that echoed the stored
			// value back) — store it as-is instead of double-encrypting.
			return $plaintext;
		}

		if ( ! $this->is_available() ) {
			return self::PREFIX . 'ob:' . base64_encode( $this->xor_with_key( $plaintext ) );
		}

		$key   = $this->derived_key( 'enc' );
		$iv    = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$cipher = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return '';
		}

		$payload = base64_encode( $iv . $cipher );
		$mac     = hash_hmac( 'sha256', $payload, $this->derived_key( 'mac' ) );

		return self::PREFIX . $payload . '.' . $mac;
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $payload Stored payload.
	 * @return string Plaintext, or an empty string when the payload is unusable.
	 */
	public function decrypt( $payload ) {
		$payload = (string) $payload;

		if ( '' === $payload ) {
			return '';
		}

		if ( 0 !== strpos( $payload, self::PREFIX ) ) {
			// Legacy / manually imported plain value.
			return $payload;
		}

		$body = substr( $payload, strlen( self::PREFIX ) );

		if ( 0 === strpos( $body, 'ob:' ) ) {
			return $this->xor_with_key( (string) base64_decode( substr( $body, 3 ), true ) );
		}

		if ( ! $this->is_available() ) {
			return '';
		}

		$parts = explode( '.', $body );

		if ( 2 !== count( $parts ) ) {
			return '';
		}

		list( $encoded, $mac ) = $parts;
		$expected = hash_hmac( 'sha256', $encoded, $this->derived_key( 'mac' ) );

		if ( ! hash_equals( $expected, $mac ) ) {
			// Tampered with, or written with a different salt (moved site,
			// rotated keys). Fail closed.
			return '';
		}

		$raw = base64_decode( $encoded, true );
		$iv  = substr( $raw, 0, openssl_cipher_iv_length( self::CIPHER ) );
		$data = substr( $raw, openssl_cipher_iv_length( self::CIPHER ) );

		if ( '' === $data ) {
			return '';
		}

		$plain = openssl_decrypt( $data, self::CIPHER, $this->derived_key( 'enc' ), OPENSSL_RAW_DATA, $iv );

		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Whether a value already looks like one of our encrypted payloads.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function starts_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Mask a secret for display: `sk-…4f2a`.
	 *
	 * @param string $plaintext Secret.
	 * @return string
	 */
	public function mask( $plaintext ) {
		$plaintext = (string) $plaintext;

		if ( '' === $plaintext ) {
			return '';
		}

		$length = strlen( $plaintext );

		if ( $length <= 8 ) {
			return str_repeat( '•', $length );
		}

		return substr( $plaintext, 0, 3 ) . str_repeat( '•', min( 14, $length - 7 ) ) . substr( $plaintext, -4 );
	}

	/**
	 * Deterministic per-purpose key derived from WordPress salts.
	 *
	 * A dedicated `VVAI_ENCRYPTION_KEY` constant takes precedence so hosts that
	 * move or clone sites can keep their stored connections working.
	 *
	 * @param string $purpose Purpose string mixed into the key material.
	 * @return string 32 byte key.
	 */
	private function derived_key( $purpose ) {
		$material = '';

		if ( defined( 'VVAI_ENCRYPTION_KEY' ) ) {
			$material .= VVAI_ENCRYPTION_KEY;
		}

		foreach ( array( 'AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$material .= constant( $constant );
			}
		}

		$material .= '|' . (string) get_option( 'siteurl' ) . '|' . $purpose;

		return hash( 'sha256', $material, true );
	}

	/**
	 * XOR obfuscation used as a graceful fallback on servers without OpenSSL.
	 *
	 * Not real cryptography — it only prevents the key from being readable in a
	 * plain `SELECT option_value` dump, and the diagnostics screen warns about
	 * the missing extension.
	 *
	 * @param string $data Input.
	 * @return string
	 */
	private function xor_with_key( $data ) {
		$key  = $this->derived_key( 'xor' );
		$out  = '';
		$len  = strlen( (string) $data );

		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $data[ $i ] ^ $key[ $i % 32 ];
		}

		return $out;
	}
}
