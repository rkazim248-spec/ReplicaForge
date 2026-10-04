<?php
/**
 * Bounded inline-CSS design extraction for ReplicaForge.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts conservative design and responsive signals from inline CSS.
 *
 * External stylesheets are intentionally not fetched in Phase 1. The target
 * is untrusted and fetching arbitrary CSS would add bandwidth, privacy, and
 * SSRF surface without improving the core data model enough to justify it.
 */
final class Design_Extractor {

	const MAX_CSS_SIZE = 500000;

	/**
	 * HTML parser helpers.
	 *
	 * @var Html_Parser
	 */
	private $parser;

	/**
	 * Constructor.
	 *
	 * @param Html_Parser|null $parser HTML parser.
	 */
	public function __construct( $parser = null ) {
		$this->parser = $parser instanceof Html_Parser ? $parser : new Html_Parser();
	}

	/**
	 * Extract design and responsive signals.
	 *
	 * @param array<int, \DOMElement> $elements Document elements.
	 * @return array{design: array<string, mixed>, responsive: array<string, mixed>}
	 */
	public function extract( $elements ) {
		$css = $this->collect_css( $elements );
		$css = preg_replace( '/\/\*[\s\S]*?\*\//', ' ', $css );
		$css = is_string( $css ) ? $css : '';

		$breakpoints            = array();
		$media_queries_detected = (bool) preg_match( '/@media\b/i', $css );
		if ( preg_match_all( '/@media[^{}]*?(?:min|max)-width\s*:\s*([0-9]+(?:\.[0-9]+)?(?:px|em|rem))/i', $css, $breakpoint_matches ) ) {
			foreach ( $breakpoint_matches[1] as $breakpoint ) {
				$breakpoints[] = strtolower( trim( $breakpoint ) );
			}
		}
		$breakpoints = array_values( array_unique( $breakpoints ) );
		sort( $breakpoints, SORT_NATURAL );
		$breakpoints = array_slice( $breakpoints, 0, 20 );

		return array(
			'design'     => array(
				'colors'           => $this->extract_colors( $css ),
				'fonts'            => $this->extract_fonts( $css ),
				'spacing'          => $this->extract_spacing( $css ),
				'border_radius'    => $this->extract_radius( $css ),
				'container_widths' => $this->extract_widths( $css ),
			),
			'responsive' => array(
				'media_queries_detected' => $media_queries_detected,
				'breakpoints'            => $breakpoints,
			),
		);
	}

	/**
	 * Collect inline CSS without fetching external stylesheets.
	 *
	 * @param array<int, \DOMElement> $elements Document elements.
	 * @return string
	 */
	private function collect_css( $elements ) {
		$parts = array();
		$total = 0;

		foreach ( $elements as $element ) {
			if ( 'style' === strtolower( $element->tagName ) ) {
				$css  = (string) $element->textContent;
				$total += strlen( $css );
				if ( $total > self::MAX_CSS_SIZE ) {
					$css = substr( $css, 0, max( 0, self::MAX_CSS_SIZE - ( $total - strlen( $css ) ) ) );
				}
				$parts[] = $css;
			}

			$style = $this->parser->get_attribute( $element, 'style' );
			if ( '' !== $style ) {
				$total += strlen( $style );
				$parts[] = $style;
				if ( $total > self::MAX_CSS_SIZE ) {
					break;
				}
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Extract color tokens from CSS.
	 *
	 * @param string $css CSS text.
	 * @return array<int, string>
	 */
	private function extract_colors( $css ) {
		$colors = array();
		$named  = 'black|white|red|green|blue|yellow|orange|purple|pink|gray|grey|brown|cyan|magenta|navy|teal|olive|maroon|silver|gold|beige|ivory|coral|salmon|khaki|indigo|violet|turquoise|lime|aqua';
		$pattern = '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?)\(\s*[0-9.%+\-\s,\/]+\)|\b(?:' . $named . ')\b/i';

		if ( ! preg_match_all( $pattern, $css, $matches ) ) {
			return array();
		}

		foreach ( $matches[0] as $match ) {
			$value = strtolower( trim( preg_replace( '/\s+/', '', $match ) ) );
			if ( '' !== $value && strlen( $value ) <= 80 && ! in_array( $value, $colors, true ) ) {
				$colors[] = $value;
			}
			if ( count( $colors ) >= 100 ) {
				break;
			}
		}

		return $colors;
	}

	/**
	 * Extract font-family references from inline CSS.
	 *
	 * @param string $css CSS text.
	 * @return array<int, array{family: string, source: string}>
	 */
	private function extract_fonts( $css ) {
		$fonts = array();
		$seen  = array();

		if ( ! preg_match_all( '/\bfont-family\s*:\s*([^;}{]+)/i', $css, $matches ) ) {
			return $fonts;
		}

		foreach ( $matches[1] as $declaration ) {
			$families = preg_split( '/\s*,\s*/', $declaration );
			if ( ! is_array( $families ) ) {
				continue;
			}
			foreach ( $families as $family ) {
				$family = $this->clean_css_value( $family, 100 );
				$family = trim( $family, " \t\n\r\0\x0B\"'" );
				if ( '' === $family || false !== stripos( $family, 'url(' ) ) {
					continue;
				}
				$key = strtolower( $family );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$fonts[]      = array(
					'family' => $family,
					'source' => 'inline',
				);
				if ( count( $fonts ) >= 100 ) {
					return $fonts;
				}
			}
		}

		return $fonts;
	}

	/**
	 * Extract spacing values from common CSS properties.
	 *
	 * @param string $css CSS text.
	 * @return array<int, string>
	 */
	private function extract_spacing( $css ) {
		$values  = array();
		$pattern = '/\b(?:margin(?:-[a-z]+)?|padding(?:-[a-z]+)?|gap|row-gap|column-gap)\s*:\s*([^;}{]+)/i';
		if ( ! preg_match_all( $pattern, $css, $matches ) ) {
			return $values;
		}

		foreach ( $matches[1] as $declaration ) {
			$parts = preg_split( '/\s+/', trim( $declaration ) );
			if ( ! is_array( $parts ) ) {
				continue;
			}
			foreach ( $parts as $part ) {
				$value = $this->clean_css_value( $part, 80 );
				if ( '' !== $value && ! in_array( $value, $values, true ) ) {
					$values[] = $value;
				}
				if ( count( $values ) >= 100 ) {
					return $values;
				}
			}
		}

		return $values;
	}

	/**
	 * Extract border-radius values.
	 *
	 * @param string $css CSS text.
	 * @return array<int, string>
	 */
	private function extract_radius( $css ) {
		$values = array();
		if ( ! preg_match_all( '/\bborder(?:-[a-z]+)*-radius\s*:\s*([^;}{]+)/i', $css, $matches ) ) {
			return $values;
		}
		foreach ( $matches[1] as $declaration ) {
			$value = $this->clean_css_value( $declaration, 100 );
			if ( '' !== $value && ! in_array( $value, $values, true ) ) {
				$values[] = $value;
			}
			if ( count( $values ) >= 100 ) {
				break;
			}
		}
		return $values;
	}

	/**
	 * Extract common width/container values.
	 *
	 * @param string $css CSS text.
	 * @return array<int, string>
	 */
	private function extract_widths( $css ) {
		$values = array();
		if ( ! preg_match_all( '/(?<![-a-z])(?:max-)?width\s*:\s*([^;}{]+)/i', $css, $matches ) ) {
			return $values;
		}
		foreach ( $matches[1] as $declaration ) {
			$parts = preg_split( '/\s+/', trim( $declaration ) );
			if ( ! is_array( $parts ) ) {
				continue;
			}
			foreach ( $parts as $part ) {
				$value = $this->clean_css_value( $part, 80 );
				if ( '' === $value || 'auto' === strtolower( $value ) || ! preg_match( '/^(?:\d+(?:\.\d+)?)(?:px|em|rem|%|vw|vh)$/i', $value ) ) {
					continue;
				}
				if ( ! in_array( $value, $values, true ) ) {
					$values[] = $value;
				}
				if ( count( $values ) >= 100 ) {
					return $values;
				}
			}
		}
		return $values;
	}

	/**
	 * Clean a CSS scalar without attempting to interpret it as code.
	 *
	 * @param string $value      CSS value.
	 * @param int    $max_length Maximum length.
	 * @return string
	 */
	private function clean_css_value( $value, $max_length ) {
		$value = preg_replace( '/[<>{}();]/', ' ', (string) $value );
		$value = preg_replace( '/\s+/', ' ', (string) $value );
		return Security::clean_text( trim( (string) $value ), $max_length );
	}
}
