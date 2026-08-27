<?php
/**
 * Strict JSON extraction for AI responses.
 *
 * Models routinely wrap JSON in prose or a markdown fence, and occasionally
 * emit a trailing comma. This class only ever *extracts* — the semantic
 * validation of the payload happens in VVAI_AI_Analyzer, which is where the
 * "never trust raw AI output" rule lives (spec §20, §50).
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Json
 */
final class VVAI_Json {

	/**
	 * Extract the first complete JSON object/array from a model response.
	 *
	 * @param string $text Raw model text.
	 * @return array{ok:bool,data:mixed,method:string,error:string}
	 */
	public static function extract( $text ) {
		$original = (string) $text;
		$result   = array(
			'ok'     => false,
			'data'   => null,
			'method' => 'none',
			'error'  => '',
		);

		$trimmed = trim( $original );

		if ( '' === $trimmed ) {
			$result['error'] = __( 'The model returned an empty response.', 'viral-video-ai' );

			return $result;
		}

		// 1. Direct decode (the well-behaved case).
		$decoded = json_decode( $trimmed, true );

		if ( self::is_usable( $decoded ) ) {
			$result['ok']   = true;
			$result['data']  = $decoded;
			$result['method'] = 'direct';

			return $result;
		}

		// 2. Fenced block: ```json … ``` — models do this even when told not to.
		if ( preg_match( '/```(?:json|JSON)?\s*(.+?)\s*```/su', $trimmed, $m ) ) {
			$candidate = trim( $m[1] );
			$decoded    = json_decode( $candidate, true );

			if ( self::is_usable( $decoded ) ) {
				$result['ok']    = true;
				$result['data']  = $decoded;
				$result['method'] = 'fenced';

				return $result;
			}

			$repaired = self::repair( $candidate );
			$decoded  = json_decode( $repaired, true );

			if ( self::is_usable( $decoded ) ) {
				$result['ok']    = true;
				$result['data']  = $decoded;
				$result['method'] = 'fenced+repaired';

				return $result;
			}
		}

		// 3. Balanced-scan the largest object/array in the document.
		$blocks = self::find_balanced_blocks( $trimmed );

		foreach ( $blocks as $block ) {
			$decoded = json_decode( $block, true );

			if ( self::is_usable( $decoded ) ) {
				$result['ok']    = true;
				$result['data']  = $decoded;
				$result['method'] = 'scan';

				return $result;
			}

			$repaired = self::repair( $block );
			$decoded   = json_decode( $repaired, true );

			if ( self::is_usable( $decoded ) ) {
				$result['ok']    = true;
				$result['data']   = $decoded;
				$result['method'] = 'scan+repaired';

				return $result;
			}
		}

		$result['error'] = ( json_last_error() > 0 )
			? sprintf(
				/* translators: %s: JSON error text. */
				__( 'The model response was not valid JSON (%s).', 'viral-video-ai' ),
				json_last_error_msg()
			)
			: __( 'The model response did not contain a JSON object.', 'viral-video-ai' );

		return $result;
	}

	/**
	 * Decode and require an object shape with a given key.
	 *
	 * @param string $text Raw text.
	 * @param string $key  Required top-level key.
	 * @return array{ok:bool,data:mixed,list:array<int,mixed>,error:string,method:string}
	 */
	public static function extract_list( $text, $key = 'clips' ) {
		$extracted = self::extract( $text );
		$out       = array(
			'ok'     => false,
			'data'   => null,
			'list'   => array(),
			'error'  => (string) $extracted['error'],
			'method' => (string) $extracted['method'],
		);

		if ( ! $extracted['ok'] ) {
			return $out;
		}

		$data = $extracted['data'];
		$out['data'] = $data;

		if ( is_array( $data ) && isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
			$out['ok']   = true;
			$out['list'] = array_values( $data[ $key ] );

			return $out;
		}

		// Some models answer with a bare array, or use `selected_clips`,
		// `moments`, `results`. Accept those before rejecting.
		if ( is_array( $data ) && $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) && is_array( reset( $data ) ) ) {
			$out['ok']   = true;
			$out['list'] = array_values( $data );

			return $out;
		}

		foreach ( array( 'clips', 'selected_clips', 'viral_clips', 'moments', 'results', 'items' ) as $alias ) {
			if ( is_array( $data ) && isset( $data[ $alias ] ) && is_array( $data[ $alias ] ) ) {
				$out['ok']   = true;
				$out['list'] = array_values( $data[ $alias ] );

				return $out;
			}
		}

		$out['error'] = sprintf(
			/* translators: %s: expected JSON key. */
			__( 'The model returned JSON without a "%s" array.', 'viral-video-ai' ),
			$key
		);

		return $out;
	}

	/**
	 * Small, safe repairs for the most common model formatting mistakes.
	 *
	 * @param string $json Candidate JSON.
	 * @return string
	 */
	public static function repair( $json ) {
		$json = (string) $json;

		// Smart quotes used as JSON quotes.
		$json = str_replace( array( '“', '”', '„', '‟' ), '"', $json );

		// Curly apostrophes inside strings are legal, but a stray backtick fence is not.
		$json = preg_replace( '/,\s*([\]}])/', '$1', $json );

		// Unquoted keys such as { start_time: 12 }.
		$json = preg_replace( '/([{,]\s*)([A-Za-z_][A-Za-z0-9_]*)(\s*:)/', '$1"$2"$3', $json );

		// Single-quoted strings → double quotes (only when the value has no quotes inside).
		$json = preg_replace( "/:\s*'([^'\\\\]*)'/", ': "$1"', $json );

		return trim( $json );
	}

	/**
	 * Collect balanced {...} / [...] candidates, biggest first.
	 *
	 * String-aware so braces inside text values do not confuse the scanner.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function find_balanced_blocks( $text ) {
		$blocks  = array();
		$length  = strlen( $text );
		$openers = array(
			'{' => '}',
			'[' => ']',
		);

		for ( $i = 0; $i < $length; $i++ ) {
			if ( ! isset( $openers[ $text[ $i ] ] ) ) {
				continue;
			}

			$open   = $text[ $i ];
			$close  = $openers[ $open ];
			$depth  = 0;
			$in_str = false;
			$escape = false;

			for ( $j = $i; $j < $length; $j++ ) {
				$char = $text[ $j ];

				if ( $in_str ) {
					if ( $escape ) {
						$escape = false;
					} elseif ( '\\' === $char ) {
						$escape = true;
					} elseif ( '"' === $char ) {
						$in_str = false;
					}

					continue;
				}

				if ( '"' === $char ) {
					$in_str = true;
				} elseif ( $char === $open ) {
					$depth++;
				} elseif ( $char === $close ) {
					$depth--;

					if ( 0 === $depth ) {
						$blocks[] = substr( $text, $i, ( $j - $i ) + 1 );
						$i         = $j;
						continue 2;
					}

					if ( $depth < 0 ) {
						break;
					}
				}
			}
		}

		// Largest block first: the outermost object is the one we want.
		usort(
			$blocks,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		return array_slice( $blocks, 0, 6 );
	}

	/**
	 * Whether a decoded value is a non-empty array/object (not `null`, `0`).
	 *
	 * @param mixed $decoded Decoded value.
	 * @return bool
	 */
	private static function is_usable( $decoded ) {
		return is_array( $decoded ) && ! empty( $decoded );
	}
}
