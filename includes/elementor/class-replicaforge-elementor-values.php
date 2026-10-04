<?php
/**
 * Shared value sanitizers for ReplicaForge Phase 4.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an untrusted value is safe to write into a document.
 *
 * Input validation, mapping, document building, and document validation all use
 * the same policy from one place, so a value accepted by one stage cannot be
 * rejected by another, or silently accepted by another.
 */
final class Elementor_Values {

	/**
	 * Patterns that indicate markup, script, or active content.
	 *
	 * @var string
	 */
	private static $executable_pattern = '/<\s*\/?\s*(?:script|iframe|object|embed|form|input|button|link|meta|style|svg|html|body)\b|<\?php|<\?=|javascript\s*:|vbscript\s*:|data\s*:\s*text\/html|data\s*:\s*image\/svg|\bon(?:error|load|click|mouseover|focus|submit)\s*=|expression\s*\(|eval\s*\(|base64\s*,|url\s*\(\s*[\'"]?\s*javascript/i';

	/**
	 * Check whether a value is a color ReplicaForge is willing to write.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_safe_color( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 80 ) {
			return false;
		}
		if ( self::is_executable( $value ) ) {
			return false;
		}
		$patterns = array(
			'/^#[0-9a-f]{3,8}$/i',
			'/^rgba?\(\s*[0-9]{1,3}\s*,\s*[0-9]{1,3}\s*,\s*[0-9]{1,3}\s*(?:,\s*(?:0|1|0?\.[0-9]{1,2})\s*)?\)$/i',
			'/^hsla?\(\s*[0-9]{1,3}(?:\.[0-9]+)?(?:deg|rad|turn)?\s*,\s*[0-9]{1,3}%\s*,\s*[0-9]{1,3}%\s*(?:,\s*(?:0|1|0?\.[0-9]{1,2})\s*)?\)$/i',
			'/^[a-z]{3,20}$/i',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether a value is a safe CSS length.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_safe_length( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return false;
		}
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 24 || self::is_executable( $value ) ) {
			return false;
		}
		return 1 === preg_match( '/^-?(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,2})?(?:px|%|em|rem|vh|vw|vmin|vmax|ch|pt)?$/i', $value );
	}

	/**
	 * Check whether a value is a safe font family list.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_safe_font_family( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 160 || self::is_executable( $value ) ) {
			return false;
		}
		return 1 === preg_match( '/^[a-z0-9 ,\'"\-]{2,160}$/i', $value );
	}

	/**
	 * Check whether a value is a safe font weight.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_safe_font_weight( $value ) {
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( 'normal', 'bold', 'bolder', 'lighter', 'inherit' ), true ) ) {
				return true;
			}
		}
		if ( ! is_numeric( $value ) ) {
			return false;
		}
		$weight = (int) $value;
		return $weight >= 100 && $weight <= 900 && 0 === $weight % 100;
	}

	/**
	 * Check whether a value is a safe line height.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_safe_line_height( $value ) {
		if ( ! is_numeric( $value ) ) {
			return false;
		}
		$value = (float) $value;
		return $value >= 0.5 && $value <= 5;
	}

	/**
	 * Detect markup or script-like content in an untrusted value.
	 *
	 * The value is entity-decoded first so an encoded payload cannot hide behind
	 * `&lt;script&gt;` or `&#106;avascript:`.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_executable( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		$decoded = $value;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$next = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $next === $decoded ) {
				break;
			}
			$decoded = $next;
		}
		return 1 === preg_match( self::$executable_pattern, $decoded );
	}

	/**
	 * Build an Elementor slider control value from a safe CSS length.
	 *
	 * @param string $value CSS length.
	 * @return array<string, mixed>
	 */
	public static function slider( $value ) {
		if ( ! preg_match( '/^(-?[0-9]*\.?[0-9]+)([a-z%]*)$/i', trim( (string) $value ), $matches ) ) {
			return array(
				'size' => 0,
				'unit' => 'px',
			);
		}
		return array(
			'size' => (float) $matches[1],
			'unit' => '' !== $matches[2] ? strtolower( $matches[2] ) : 'px',
		);
	}

	/**
	 * Build an Elementor dimensions control value from one to four lengths.
	 *
	 * @param string $value Space separated CSS lengths.
	 * @return array<string, mixed>|null
	 */
	public static function dimensions( $value ) {
		$parts = preg_split( '/\s+/', trim( (string) $value ) );
		if ( ! is_array( $parts ) || count( $parts ) < 1 || count( $parts ) > 4 ) {
			return null;
		}

		$unit   = '';
		$values = array();
		foreach ( $parts as $part ) {
			if ( ! self::is_safe_length( $part ) ) {
				return null;
			}
			$parsed = self::slider( $part );
			if ( empty( $values ) ) {
				$unit = $parsed['unit'];
			} elseif ( $parsed['unit'] !== $unit ) {
				return null;
			}
			$values[] = $parsed['size'];
		}

		$count  = count( $values );
		$top    = $values[0];
		$right  = $count > 1 ? $values[1] : $top;
		$bottom = $count > 2 ? $values[2] : $top;
		$left   = $count > 3 ? $values[3] : $right;

		return array(
			'top'      => $top,
			'right'    => $right,
			'bottom'   => $bottom,
			'left'     => $left,
			'unit'     => $unit,
			'isLinked' => 1 === $count || 3 === $count,
		);
	}

	/**
	 * Build an Elementor gaps control value.
	 *
	 * @param string $value CSS length.
	 * @return array<string, mixed>
	 */
	public static function gaps( $value ) {
		$slider = self::slider( $value );
		return array(
			'row'      => $slider['size'],
			'column'   => $slider['size'],
			'unit'     => $slider['unit'],
			'isLinked' => true,
		);
	}
}
