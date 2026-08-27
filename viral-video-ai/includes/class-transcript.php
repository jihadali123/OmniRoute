<?php
/**
 * Transcript normalization, chunking and subtitle generation.
 *
 * Every provider returns a slightly different shape; everything is collapsed
 * into one internal format:
 *
 *     [ { start: float, end: float, text: string, speaker?: int }, … ]
 *
 * Segments are ordered, non-overlapping and gap-free enough for the analyzer to
 * reason about, and for FFmpeg to derive exact cut points from.
 *
 * @package VVAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VVAI_Transcript
 */
final class VVAI_Transcript {

	/**
	 * Longest merged line, in seconds. Merging exists to stitch sentence
	 * fragments together, not to build paragraphs.
	 */
	const MAX_LINE_SECONDS = 12.0;

	/**
	 * Normalize + merge a raw segment list.
	 *
	 * @param array<int,mixed> $segments Raw segments.
	 * @param float            $offset   Time offset in seconds (chunk stitching).
	 * @param float            $limit    Hard maximum time (source duration).
	 * @return array<int,array{start:float,end:float,text:string}>
	 */
	public static function normalize( array $segments, $offset = 0.0, $limit = 0.0 ) {
		$offset = max( 0.0, (float) $offset );
		$limit  = (float) $limit;
		$out    = array();

		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) ) {
				// Plain string chunk (some endpoints return lines only).
				$text = is_scalar( $segment ) ? trim( (string) $segment ) : '';

				if ( '' === $text ) {
					continue;
				}

				$segment = array(
					'start' => 0,
					'end'   => 0,
					'text'  => $text,
				);
			}

			$text = vvai_sanitize_text( vvai_array_get( $segment, 'text', '' ), 600 );

			if ( '' === $text ) {
				continue;
			}

			// Drop provider artifacts that never belong in a clip decision.
			$text = preg_replace( '/\[(?:MUSIC|APPLAUSE|LAUGHTER|silence)\]/i', '', $text );
			$text = preg_replace( '/<<>>|♪/', '', $text );
			$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

			if ( '' === $text ) {
				continue;
			}

			// Provider timestamps are relative to the chunk that produced them, so
			// the window start is added exactly once, here.
			$local_start = self::time_of( $segment, array( 'start', 'start_time', 'offset' ), 0.0 );
			$local_end   = self::time_of( $segment, array( 'end', 'end_time' ), $local_start + max( 1.0, strlen( $text ) / 15.0 ) );
			$start       = $local_start + $offset;
			$end         = max( $start + 0.4, $local_end + $offset );

			if ( $limit > 0 ) {
				$start = min( $start, $limit );
				$end    = min( $end, $limit );
			}

			$out[] = array(
				'start' => round( max( 0.0, $start ), 2 ),
				'end'   => round( max( 0.1, $end ), 2 ),
				'text'  => $text,
			);
		}

		return self::merge( $out );
	}

	/**
	 * Sort, clamp and merge segments that overlap or touch.
	 *
	 * Providers emit one segment per pause; merging short fragments keeps the AI
	 * prompt compact while preserving the outer timestamps of each sentence.
	 *
	 * @param array<int,array<string,mixed>> $segments Normalized segments.
	 * @param int                            $max_chars Maximum characters per merged line.
	 * @return array<int,array{start:float,end:float,text:string}>
	 */
	public static function merge( array $segments, $max_chars = 340 ) {
		usort(
			$segments,
			static function ( $a, $b ) {
				return ( (float) $a['start'] ) <=> ( (float) $b['start'] );
			}
		);

		$merged = array();

		foreach ( $segments as $segment ) {
			$start = (float) $segment['start'];
			$end   = (float) $segment['end'];
			$text  = (string) $segment['text'];

			if ( ! $merged ) {
				$merged[] = array(
					'start' => $start,
					'end'   => $end,
					'text'  => $text,
				);
				continue;
			}

			$last = count( $merged ) - 1;
			$prev = $merged[ $last ];
			$gap  = $start - (float) $prev['end'];

			$merged_length = ( $end - (float) $prev['start'] );

			// Merge contiguous fragments only while the line stays a *sentence*:
			// both a character budget and a duration budget. Without the duration
			// guard a 90-second paragraph becomes one "line", which destroys the
			// sentence-boundary guarantee the clip snapping relies on.
			if (
				$gap <= 0.45
				&& $merged_length <= self::MAX_LINE_SECONDS
				&& ( strlen( $prev['text'] ) + strlen( $text ) + 1 ) <= $max_chars
			) {
				$merged[ $last ] = array(
					'start' => round( (float) $prev['start'], 2 ),
					'end'   => round( max( (float) $prev['end'], $end ), 2 ),
					'text'  => trim( $prev['text'] . ' ' . $text ),
				);
				continue;
			}

			$merged[] = array(
				'start' => round( $start, 2 ),
				'end'   => round( $end, 2 ),
				'text'  => $text,
			);
		}

		return $merged;
	}

	/**
	 * Split a transcript into analysis windows of a bounded character budget.
	 *
	 * @param array<int,array<string,mixed>> $segments Normalized segments.
	 * @param int                            $budget   Characters per window.
	 * @param float                          $overlap  Seconds of overlap between windows.
	 * @return array<int,array{index:int,start:float,end:float,segments:array<int,array<string,mixed>>,chars:int}>
	 */
	public static function chunk( array $segments, $budget = 30000, $overlap = 8.0 ) {
		$budget = max( 4000, (int) $budget );
		$chunks = array();
		$current = array();
		$chars  = 0;
		$start  = null;

		foreach ( $segments as $segment ) {
			$segment_chars = strlen( (string) $segment['text'] ) + 24;

			if ( $current && ( $chars + $segment_chars ) > $budget ) {
				$chunks[] = self::finish_chunk( $current, $start, (float) $segment['start'], $overlap );

				// Carry the tail of the previous chunk forward so a moment that
				// straddles the boundary is still seen whole by the model.
				$carry  = self::tail_for_overlap( $current, (float) $segment['start'], $overlap );
				$current = $carry;
				$chars   = 0;
				$start   = $carry ? (float) $carry[0]['start'] : (float) $segment['start'];

				foreach ( $carry as $item ) {
					$chars += strlen( (string) $item['text'] ) + 24;
				}
			}

			if ( null === $start ) {
				$start = (float) $segment['start'];
			}

			$current[] = $segment;
			$chars     += $segment_chars;
		}

		if ( $current ) {
			$end = (float) end( $current )['end'];
			$chunks[] = self::finish_chunk( $current, $start, $end, 0.0 );
		}

		foreach ( $chunks as $index => $chunk ) {
			$chunks[ $index ]['index'] = $index;
		}

		return $chunks;
	}

	/**
	 * Total spoken characters, used to decide whether the transcript is big enough.
	 *
	 * @param array<int,array<string,mixed>> $segments Segments.
	 * @return int
	 */
	public static function character_count( array $segments ) {
		$count = 0;

		foreach ( $segments as $segment ) {
			$count += strlen( (string) vvai_array_get( $segment, 'text', '' ) );
		}

		return $count;
	}

	/**
	 * Build an SRT document for a range of the transcript.
	 *
	 * Used for the downloadable sidecar and for optional burned-in captions.
	 *
	 * @param array<int,array<string,mixed>> $segments Transcript segments (absolute times).
	 * @param float                          $start    Clip start.
	 * @param float                          $end      Clip end.
	 * @param int                            $max_lines Words per caption block.
	 * @return string
	 */
	public static function to_srt( array $segments, $start, $end, $max_lines = 420 ) {
		$start = max( 0.0, (float) $start );
		$end   = (float) $end;
		$out   = array();
		$index = 1;

		foreach ( $segments as $segment ) {
			$from = (float) $segment['start'];
			$to   = (float) $segment['end'];

			if ( ( $to - 0.05 ) <= $start || ( $from + 0.05 ) >= $end ) {
				continue;
			}

			$from = max( $from, $start ) - $start;
			$to   = min( $to, $end ) - $start;

			if ( $to <= $from ) {
				continue;
			}

			$text = (string) $segment['text'];

			// Long segments are wrapped so a caption block never covers the frame.
			foreach ( self::wrap( $text, (int) $max_lines ) as $piece ) {
				$out[] = $index . "\n" . self::srt_time( $from ) . ' --> ' . self::srt_time( $to ) . "\n" . $piece . "\n";
				$index++;
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * SRT timestamp.
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	public static function srt_time( $seconds ) {
		$seconds = max( 0.0, (float) $seconds );

		// Integer maths: applying % to a float is deprecated since PHP 8.1 and
		// quietly rounds, which would shift every cue by up to a second.
		$total     = (int) floor( $seconds );
		$hours     = intdiv( $total, 3600 );
		$minutes   = intdiv( $total % 3600, 60 );
		$whole     = $total % 60;
		$ms        = (int) round( ( $seconds - $total ) * 1000 );

		if ( $ms >= 1000 ) {
			$ms = 999;
		}

		return sprintf( '%02d:%02d:%02d,%03d', $hours, $minutes, $whole, $ms );
	}

	/**
	 * Words around a timestamp, for caption overlays and hook analysis.
	 *
	 * @param array<int,array<string,mixed>> $segments Segments.
	 * @param float                          $start    Start.
	 * @param float                          $end      End.
	 * @return string
	 */
	public static function text_between( array $segments, $start, $end ) {
		$text = array();

		foreach ( $segments as $segment ) {
			if ( (float) $segment['end'] <= (float) $start || (float) $segment['start'] >= (float) $end ) {
				continue;
			}

			$text[] = (string) $segment['text'];
		}

		return trim( implode( ' ', $text ) );
	}

	/**
	 * Find the segment boundary closest to a timestamp.
	 *
	 * This is the anti-hallucination anchor (spec §50): a model-supplied cut
	 * point is snapped to real transcript boundaries so a clip can never start
	 * or end mid-sentence.
	 *
	 * @param array<int,array<string,mixed>> $segments Segments.
	 * @param float                          $time     Requested time.
	 * @param string                         $edge     start|end.
	 * @param float                          $tolerance Maximum snap distance in seconds.
	 * @return array{time:float,index:int,snapped:bool}
	 */
	public static function snap( array $segments, $time, $edge = 'start', $tolerance = 25.0 ) {
		$time      = (float) $time;
		$best      = null;
		$best_diff = PHP_FLOAT_MAX;
		$tolerance = (float) $tolerance;

		foreach ( array_values( $segments ) as $index => $segment ) {
			$candidate = ( 'end' === $edge ) ? (float) $segment['end'] : (float) $segment['start'];

			// Nothing further than the tolerance in EITHER direction: a timestamp
			// that matches no real boundary is a hallucination and must be rejected.
			if ( abs( $candidate - $time ) > $tolerance ) {
				continue;
			}

			if ( 'end' === $edge && $candidate < $time - 0.001 ) {
				// End points must not cut before the requested moment.
				continue;
			}

			$diff = abs( $candidate - $time );

			if ( $diff < $best_diff ) {
				$best_diff = $diff;
				$best      = array(
					'time'  => round( $candidate, 2 ),
					'index' => (int) $index,
					'snapped' => ( $diff > 0.009 ),
				);
			}
		}

		if ( null === $best ) {
			return array(
				'time'    => round( $time, 2 ),
				'index'   => -1,
				'snapped' => false,
			);
		}

		return $best;
	}

	/**
	 * Segment indexes whose text overlaps a range.
	 *
	 * @param array<int,array<string,mixed>> $segments Segments.
	 * @param float                          $start    Start.
	 * @param float                          $end      End.
	 * @return int[]
	 */
	public static function indexes_in_range( array $segments, $start, $end ) {
		$indexes = array();

		foreach ( array_values( $segments ) as $index => $segment ) {
			if ( (float) $segment['end'] > (float) $start && (float) $segment['start'] < (float) $end ) {
				$indexes[] = (int) $index;
			}
		}

		return $indexes;
	}

	/**
	 * Read a time value from any of the usual provider keys.
	 *
	 * @param array<string,mixed> $segment Segment.
	 * @param string[]            $keys    Candidate keys.
	 * @param float               $default Default.
	 * @return float
	 */
	private static function time_of( array $segment, array $keys, $default ) {
		foreach ( $keys as $key ) {
			if ( isset( $segment[ $key ] ) && ( is_numeric( $segment[ $key ] ) || is_string( $segment[ $key ] ) ) ) {
				$parsed = vvai_parse_time( $segment[ $key ] );

				if ( false !== $parsed ) {
					// `offset` in a chunk response is relative to that chunk, so the
					// caller adds the chunk start separately.
					return max( 0.0, $parsed );
				}
			}
		}

		return max( 0.0, (float) $default );
	}

	/**
	 * Finish one chunk record.
	 *
	 * @param array $segments Chunk segments.
	 * @param float $start    Chunk start.
	 * @param float $end      Chunk end.
	 * @param float $overlap  Requested overlap.
	 * @return array<string,mixed>
	 */
	private static function finish_chunk( array $segments, $start, $end, $overlap ) {
		$chars = 0;

		foreach ( $segments as $segment ) {
			$chars += strlen( (string) $segment['text'] );
		}

		return array(
			'index'    => 0,
			'start'    => round( (float) $start, 2 ),
			'end'      => round( (float) $end, 2 ),
			'segments' => array_values( $segments ),
			'chars'    => $chars,
			'overlap'  => round( (float) $overlap, 2 ),
		);
	}

	/**
	 * Trailing segments to carry into the next window.
	 *
	 * @param array $segments Current chunk segments.
	 * @param float $next     Start of the next segment.
	 * @param float $overlap  Overlap in seconds.
	 * @return array<int,array<string,mixed>>
	 */
	private static function tail_for_overlap( array $segments, $next, $overlap ) {
		if ( $overlap <= 0 ) {
			return array();
		}

		$floor = max( 0.0, (float) $next - $overlap );
		$tail  = array();

		foreach ( $segments as $segment ) {
			if ( (float) $segment['end'] >= $floor ) {
				$tail[] = $segment;
			}
		}

		return $tail;
	}

	/**
	 * Wrap caption text into readable blocks.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Character budget per block.
	 * @return string[]
	 */
	private static function wrap( $text, $limit = 42 ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

		if ( '' === $text ) {
			return array();
		}

		if ( strlen( $text ) <= $limit ) {
			return array( $text );
		}

		$words   = preg_split( '/\s+/', $text );
		$blocks  = array();
		$current = '';

		foreach ( $words as $word ) {
			if ( '' !== $current && ( strlen( $current ) + strlen( $word ) + 1 ) > $limit ) {
				$blocks[]  = $current;
				$current   = $word;
				continue;
			}

			$current = ( '' === $current ) ? $word : ( $current . ' ' . $word );
		}

		if ( '' !== $current ) {
			$blocks[] = $current;
		}

		return $blocks;
	}
}
