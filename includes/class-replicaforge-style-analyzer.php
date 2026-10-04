<?php
/**
 * Static CSS and design-token analysis.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Parses bounded inline/external CSS without executing or rendering it.
 */
final class Style_Analyzer {

	/**
	 * Parsed base CSS rules.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $rules = array();

	/**
	 * Parsed media-query rules.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $media_rules = array();

	/**
	 * Inline declarations keyed by node ID.
	 *
	 * @var array<string, array<string, string>>
	 */
	private $inline_styles = array();

	/**
	 * Aggregate declaration records.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $declaration_records = array();

	/**
	 * Non-sensitive CSS analysis warnings.
	 *
	 * @var array<int, string>
	 */
	private $warnings = array();

	/**
	 * Analyze inline and safely fetched external CSS.
	 *
	 * @param array<string, mixed>                $context      DOM context.
	 * @param array<int, array<string, mixed>>    $stylesheets Loaded stylesheets.
	 * @return array<string, mixed>
	 */
	public function analyze( $context, $stylesheets = array() ) {
		$this->rules          = array();
		$this->media_rules    = array();
		$this->inline_styles  = array();
		$this->declaration_records = array();
		$this->warnings = array();

		$css_parts = array();
		$css_parts[] = $this->collect_inline_css( $context );
		if ( is_array( $stylesheets ) ) {
			foreach ( $stylesheets as $stylesheet ) {
				if ( isset( $stylesheet['css'] ) && is_string( $stylesheet['css'] ) ) {
					$css_parts[] = $stylesheet['css'];
				}
			}
		}
		$css = implode( "\n", $css_parts );
		$css = preg_replace( '/\/\*[\s\S]*?\*\//', ' ', $css );
		$css = is_string( $css ) ? $css : '';

		$parsed = $this->parse_css( $css );
		$this->rules       = $parsed['rules'];
		$this->media_rules = $parsed['media'];
		$this->index_declarations();

		return array(
			'colors'          => $this->extract_color_tokens(),
			'fonts'           => $this->extract_font_tokens(),
			'font_sizes'      => $this->extract_property_tokens( array( 'font-size' ) ),
			'font_weights'    => $this->extract_property_tokens( array( 'font-weight' ) ),
			'line_heights'    => $this->extract_property_tokens( array( 'line-height' ) ),
			'letter_spacing'  => $this->extract_property_tokens( array( 'letter-spacing' ) ),
			'text_transform'  => $this->extract_property_tokens( array( 'text-transform' ) ),
			'spacing'         => $this->extract_property_tokens( array( 'margin', 'padding', 'gap', 'row-gap', 'column-gap' ) ),
			'radius'          => $this->extract_radius_tokens(),
			'shadows'         => $this->extract_shadow_tokens(),
			'containers'      => $this->extract_container_tokens(),
			'button_variants' => $this->extract_button_variants( $context ),
			'layout_rules'    => $this->extract_layout_rules(),
			'sources'         => array(
				'has_external_css' => ! empty( $stylesheets ),
				'rule_count'       => count( $this->rules ),
				'media_rule_count' => count( $this->media_rules ),
			),
			'warnings'         => array_values( array_unique( $this->warnings ) ),
		);
	}

	/**
	 * Return merged base declarations using a Dom_Analyzer instance.
	 *
	 * @param Dom_Analyzer          $dom     DOM analyzer.
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return array<string, string>
	 */
	public function get_declarations( Dom_Analyzer $dom, $context, $node_id ) {
		$declarations = array();
		foreach ( $this->rules as $rule ) {
			if ( ! empty( $rule['media'] ) || empty( $rule['selector'] ) ) {
				continue;
			}
			$selectors = is_array( $rule['selector'] ) ? $rule['selector'] : array( $rule['selector'] );
			foreach ( $selectors as $selector ) {
				if ( $dom->matches_selector( $context, $node_id, $selector ) ) {
					$declarations = array_merge( $declarations, $rule['declarations'] );
					break;
				}
			}
		}
		if ( isset( $this->inline_styles[ $node_id ] ) ) {
			$declarations = array_merge( $declarations, $this->inline_styles[ $node_id ] );
		}
		return $declarations;
	}

	/**
	 * Return parsed media rules.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_media_rules() {
		return $this->media_rules;
	}

	/**
	 * Return parsed base rules.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_rules() {
		return $this->rules;
	}

	/**
	 * Return aggregate declaration records.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_declaration_records() {
		return $this->declaration_records;
	}

	/**
	 * Parse declarations from an inline style string.
	 *
	 * @param string $style Inline style.
	 * @return array<string, string>
	 */
	public function parse_inline_style( $style ) {
		return $this->parse_declarations( (string) $style );
	}

	/**
	 * Collect inline style blocks and attributes.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @return string
	 */
	private function collect_inline_css( $context ) {
		$parts = array();
		$total = 0;
		$inline_total = 0;
		foreach ( array_keys( isset( $context['nodes'] ) && is_array( $context['nodes'] ) ? $context['nodes'] : array() ) as $node_id ) {
			$node = isset( $context['nodes'][ $node_id ] ) ? $context['nodes'][ $node_id ] : array();
			if ( 'style' === ( isset( $node['tag'] ) ? $node['tag'] : '' ) ) {
				$element = isset( $context['dom'][ $node_id ] ) ? $context['dom'][ $node_id ] : null;
				if ( $element instanceof \DOMElement ) {
					$text = (string) $element->textContent;
					$remaining = Analysis_Limits::MAX_INLINE_CSS_SIZE - $total;
					if ( $remaining > 0 ) {
						$text = substr( $text, 0, $remaining );
						$parts[] = $text;
						$total += strlen( $text );
					}
					if ( strlen( $text ) >= $remaining ) {
						$this->warnings[] = 'The inline CSS size limit was reached.';
					}
				}
				continue;
			}
			$inline = isset( $node['style'] ) ? (string) $node['style'] : '';
			if ( '' !== $inline ) {
				$remaining = Analysis_Limits::MAX_INLINE_CSS_SIZE - $total - $inline_total;
				if ( $remaining <= 0 ) {
					$this->warnings[] = 'The inline CSS size limit was reached.';
				} else {
					$inline = substr( $inline, 0, $remaining );
					$inline_total += strlen( $inline );
					$this->inline_styles[ $node_id ] = $this->parse_declarations( $inline );
				}
			}
		}
		return implode( "\n", $parts );
	}

	/**
	 * Parse CSS into base and media rules.
	 *
	 * @param string $css CSS text.
	 * @return array{rules: array<int, array<string, mixed>>, media: array<int, array<string, mixed>>}
	 */
	private function parse_css( $css ) {
		$css = (string) $css;
		if ( strlen( $css ) > Analysis_Limits::MAX_CSS_TEXT_SIZE ) {
			$css = substr( $css, 0, Analysis_Limits::MAX_CSS_TEXT_SIZE );
			$this->warnings[] = 'The CSS parser input limit was reached.';
		}
		$media = $this->extract_media_blocks( $css );
		$base_css = $css;
		foreach ( array_reverse( $media ) as $block ) {
			$start = isset( $block['start'] ) ? (int) $block['start'] : 0;
			$end   = isset( $block['end'] ) ? (int) $block['end'] : $start;
			if ( $end > $start ) {
				$base_css = substr_replace( $base_css, ' ', $start, $end - $start );
			}
		}
		$base  = $this->parse_rule_list( $base_css, '' );

		$media_rules = array();
		foreach ( $media as $block ) {
			foreach ( $this->parse_rule_list( $block['body'], $block['query'] ) as $rule ) {
				$media_rules[] = $rule;
			}
		}

		return array(
			'rules' => array_slice( $base, 0, Analysis_Limits::MAX_CSS_RULES ),
			'media' => array_slice( $media_rules, 0, Analysis_Limits::MAX_CSS_RULES ),
		);
	}

	/**
	 * Extract balanced @media blocks.
	 *
	 * @param string $css CSS text.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_media_blocks( $css ) {
		$blocks = array();
		$offset = 0;
		$length = strlen( $css );
		while ( $offset < $length ) {
			if ( ! preg_match( '/@media([^{]+)\{/i', $css, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
				break;
			}
			$start = $match[0][1];
			$body_start = $start + strlen( $match[0][0] );
			$depth = 1;
			$cursor = $body_start;
			while ( $cursor < $length && $depth > 0 ) {
				if ( '{' === $css[ $cursor ] ) {
					$depth++;
				} elseif ( '}' === $css[ $cursor ] ) {
					$depth--;
				}
				$cursor++;
			}
			$blocks[] = array(
				'query' => trim( $match[1][0] ),
				'body'  => substr( $css, $body_start, max( 0, $cursor - $body_start - ( $depth > 0 ? 0 : 1 ) ) ),
				'start' => $start,
				'end'   => $cursor,
			);
			$offset = $cursor;
			if ( count( $blocks ) >= 200 ) {
				break;
			}
		}
		return $blocks;
	}

	/**
	 * Parse simple rule blocks from a CSS scope.
	 *
	 * @param string $css   CSS scope.
	 * @param string $media Media query context.
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_rule_list( $css, $media = '' ) {
		$rules = array();
		if ( ! preg_match_all( '/([^{}@][^{}]*)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER ) ) {
			return $rules;
		}
		foreach ( $matches as $match ) {
			$selector = trim( preg_replace( '/\s+/', ' ', $match[1] ) );
			if ( '' === $selector || 0 === strpos( $selector, '@' ) || strlen( $selector ) > Analysis_Limits::MAX_SELECTOR_LENGTH ) {
				if ( strlen( $selector ) > Analysis_Limits::MAX_SELECTOR_LENGTH ) {
					$this->warnings[] = 'An oversized CSS selector was skipped.';
				}
				continue;
			}
			$selectors = array_values( array_filter( array_map( 'trim', explode( ',', $selector ) ) ) );
			$declarations = $this->parse_declarations( $match[2] );
			if ( empty( $declarations ) ) {
				continue;
			}
			$rules[] = array(
				'selector'    => $selectors,
				'declarations' => $declarations,
				'media'       => $media,
			);
			if ( count( $rules ) >= Analysis_Limits::MAX_CSS_RULES ) {
				break;
			}
		}
		return $rules;
	}

	/**
	 * Parse a declaration block without evaluating functions.
	 *
	 * @param string $block Declaration block.
	 * @return array<string, string>
	 */
	private function parse_declarations( $block ) {
		$declarations = array();
		$buffer = '';
		$depth = 0;
		$length = strlen( (string) $block );
		for ( $index = 0; $index <= $length; $index++ ) {
			$character = $index < $length ? $block[ $index ] : ';';
			if ( '(' === $character ) {
				$depth++;
			} elseif ( ')' === $character && $depth > 0 ) {
				$depth--;
			}
			if ( ';' === $character && 0 === $depth ) {
				$declaration = trim( $buffer );
				$buffer = '';
				if ( '' === $declaration || false === strpos( $declaration, ':' ) ) {
					continue;
				}
				list( $property, $value ) = array_map( 'trim', explode( ':', $declaration, 2 ) );
				$property = strtolower( preg_replace( '/[^a-z0-9-]/', '', $property ) );
				$value = Analysis_Normalizer::css_value( $value, 240 );
				if ( '' !== $property && '' !== $value ) {
					$declarations[ $property ] = $value;
				}
				if ( count( $declarations ) >= Analysis_Limits::MAX_NODE_DECLARATIONS ) {
					break;
				}
			} else {
				$buffer .= $character;
			}
		}
		return $declarations;
	}

	/**
	 * Index declarations for token frequency and role analysis.
	 *
	 * @return void
	 */
	private function index_declarations() {
		$records = array();
		foreach ( array_merge( $this->rules, $this->media_rules ) as $rule ) {
			foreach ( $rule['declarations'] as $property => $value ) {
				if ( count( $records ) >= Analysis_Limits::MAX_CSS_RULES * 2 && ! isset( $records[ $property . '|' . strtolower( $value ) ] ) ) {
					break 2;
				}
				$key = $property . '|' . strtolower( $value );
				if ( ! isset( $records[ $key ] ) ) {
					$records[ $key ] = array(
						'property'  => $property,
						'value'     => $value,
						'count'     => 0,
						'selectors' => array(),
						'media'     => ! empty( $rule['media'] ),
					);
				}
				$records[ $key ]['count']++;
				if ( count( $records[ $key ]['selectors'] ) < 5 ) {
					foreach ( is_array( $rule['selector'] ) ? $rule['selector'] : array( (string) $rule['selector'] ) as $selector ) {
						$selector = Analysis_Normalizer::selector( $selector );
						if ( '' !== $selector && strlen( $selector ) <= Analysis_Limits::MAX_SELECTOR_LENGTH && ! in_array( $selector, $records[ $key ]['selectors'], true ) ) {
							$records[ $key ]['selectors'][] = $selector;
						}
					}
				}
			}
		}
		foreach ( $this->inline_styles as $node_id => $declarations ) {
			foreach ( $declarations as $property => $value ) {
				if ( count( $records ) >= Analysis_Limits::MAX_CSS_RULES * 2 && ! isset( $records[ $property . '|' . strtolower( $value ) ] ) ) {
					break 2;
				}
				$key = $property . '|' . strtolower( $value );
				if ( ! isset( $records[ $key ] ) ) {
					$records[ $key ] = array(
						'property'  => $property,
						'value'     => $value,
						'count'     => 0,
						'selectors' => array(),
						'media'     => false,
						'inline'    => true,
					);
				}
				$records[ $key ]['count']++;
			}
		}
		$this->declaration_records = array_values( $records );
	}

	/**
	 * Extract color tokens and conservative semantic roles.
	 *
	 * @return array<string, mixed>
	 */
	private function extract_color_tokens() {
		$colors = array();
		$named = 'black|white|red|green|blue|yellow|orange|purple|pink|gray|grey|brown|cyan|magenta|navy|teal|olive|maroon|silver|gold|transparent|currentcolor';
		$pattern = '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?)\(\s*[0-9.%+\-\s,\/]+\)|\b(?:' . $named . ')\b/i';
		foreach ( $this->declaration_records as $record ) {
			if ( ! preg_match_all( $pattern, $record['value'], $matches ) ) {
				continue;
			}
			foreach ( $matches[0] as $match ) {
				$value = strtolower( trim( preg_replace( '/\s+/', '', $match ) ) );
				if ( '' === $value || strlen( $value ) > 80 ) {
					continue;
				}
				$key = strtolower( $value );
				if ( ! isset( $colors[ $key ] ) ) {
					$colors[ $key ] = array(
						'value'      => $value,
						'usage_count' => 0,
						'properties' => array(),
						'selectors'  => array(),
						'button_background' => false,
						'confidence' => 0.0,
					);
				}
				$colors[ $key ]['usage_count'] += max( 1, (int) $record['count'] );
				if ( ! in_array( $record['property'], $colors[ $key ]['properties'], true ) && count( $colors[ $key ]['properties'] ) < 8 ) {
					$colors[ $key ]['properties'][] = $record['property'];
				}
				$selectors = strtolower( implode( ' ', (array) $record['selectors'] ) );
				foreach ( (array) $record['selectors'] as $selector ) {
					$selector = Analysis_Normalizer::selector( $selector );
					if ( '' !== $selector && ! in_array( $selector, $colors[ $key ]['selectors'], true ) && count( $colors[ $key ]['selectors'] ) < 5 ) {
						$colors[ $key ]['selectors'][] = $selector;
					}
				}
				if ( false !== strpos( $selectors, 'btn' ) || false !== strpos( $selectors, 'button' ) || false !== strpos( $selectors, 'cta' ) ) {
					$colors[ $key ]['button_background'] = true;
				}
			}
		}
		$colors = array_values( $colors );
		usort( $colors, static function ( $left, $right ) {
			$priority = $right['usage_count'] <=> $left['usage_count'];
			return 0 !== $priority ? $priority : strnatcasecmp( $left['value'], $right['value'] );
		} );
		$colors = array_slice( $colors, 0, Analysis_Limits::MAX_TOKENS_PER_FAMILY );
		$roles = array();
		$neutral = array( 'white', 'black', 'transparent', 'currentcolor', 'gray', 'grey', '#fff', '#ffffff', '#000', '#000000' );
		foreach ( $colors as $index => $color ) {
			$role = 'unclassified';
			if ( in_array( $color['value'], $neutral, true ) ) {
				$role = in_array( $color['value'], array( 'white', 'black' ), true ) ? 'text_or_surface' : 'utility';
			} elseif ( in_array( 'background', $color['properties'], true ) || in_array( 'background-color', $color['properties'], true ) ) {
				$role = 'background';
			} elseif ( in_array( 'border', $color['properties'], true ) || in_array( 'border-color', $color['properties'], true ) ) {
				$role = 'border';
			} elseif ( in_array( 'color', $color['properties'], true ) ) {
				$role = 'text';
			}
			$colors[ $index ]['role'] = $role;
			$colors[ $index ]['confidence'] = min( 0.82, 0.42 + min( 0.35, $colors[ $index ]['usage_count'] / 20 ) );
			$colors[ $index ]['source'] = 'inferred';
			if ( ! isset( $roles[ $role ] ) ) {
				$roles[ $role ] = $colors[ $index ];
			}
		}
		$by_role = array();
		$assigned = array();
		foreach ( array( 'primary', 'secondary', 'accent', 'background', 'surface', 'text', 'muted', 'border' ) as $role ) {
			$candidate = null;
			$candidates = in_array( $role, array( 'primary', 'secondary', 'accent' ), true ) ? array_merge(
				array_values( array_filter( $colors, static function ( $color ) { return ! empty( $color['button_background'] ); } ) ),
				$colors
			) : $colors;
			foreach ( $candidates as $color ) {
				if ( in_array( $color['value'], $neutral, true ) || isset( $assigned[ $color['value'] ] ) ) {
					continue;
				}
				if ( 'background' === $role && 'background' === $color['role'] ) {
					$candidate = $color;
					break;
				}
				if ( 'border' === $role && 'border' === $color['role'] ) {
					$candidate = $color;
					break;
				}
				if ( 'text' === $role && 'text' === $color['role'] ) {
					$candidate = $color;
					break;
				}
				if ( in_array( $role, array( 'primary', 'secondary', 'accent' ), true ) && ( ! in_array( $color['role'], array( 'background', 'border' ), true ) || ! empty( $color['button_background'] ) ) ) {
					$candidate = $color;
					break;
				}
			}
			if ( $candidate ) {
				$assigned[ $candidate['value'] ] = true;
				$by_role[ $role ] = array(
					'value'      => $candidate['value'],
					'role'       => $role,
					'confidence' => min( 0.78, max( 0.5, $candidate['confidence'] - ( 'primary' === $role ? 0.08 : 0.12 ) ) ),
					'source'     => 'inferred',
					'evidence'   => $candidate['selectors'],
				);
			}
		}
		if ( ! isset( $by_role['surface'] ) ) {
			foreach ( $colors as $color ) {
				if ( isset( $assigned[ $color['value'] ] ) ) {
					continue;
				}
				if ( 'background' === $color['role'] || in_array( $color['value'], array( '#fff', '#ffffff', 'white' ), true ) ) {
					$assigned[ $color['value'] ] = true;
					$by_role['surface'] = array( 'value' => $color['value'], 'role' => 'surface', 'confidence' => 0.5, 'source' => 'inferred', 'evidence' => $color['selectors'] );
					break;
				}
			}
		}
		if ( ! isset( $by_role['muted'] ) ) {
			foreach ( $colors as $color ) {
				if ( isset( $assigned[ $color['value'] ] ) ) {
					continue;
				}
				if ( 'text' === $color['role'] && in_array( $color['value'], array( 'gray', 'grey', '#777', '#777777', '#6b7280' ), true ) ) {
					$assigned[ $color['value'] ] = true;
					$by_role['muted'] = array( 'value' => $color['value'], 'role' => 'muted', 'confidence' => 0.45, 'source' => 'inferred', 'evidence' => $color['selectors'] );
					break;
				}
			}
		}
		foreach ( array( 'success' => 'green', 'warning' => 'yellow', 'error' => 'red' ) as $role => $value ) {
			if ( isset( $by_role[ $role ] ) ) {
				continue;
			}
			foreach ( $colors as $color ) {
				if ( $value === $color['value'] && ! isset( $assigned[ $color['value'] ] ) ) {
					$assigned[ $color['value'] ] = true;
					$by_role[ $role ] = array( 'value' => $color['value'], 'role' => $role, 'confidence' => 0.4, 'source' => 'inferred', 'evidence' => $color['selectors'] );
					break;
				}
			}
		}
		return array( 'tokens' => $colors, 'by_role' => $by_role );
	}

	/**
	 * Extract font-family references.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_font_tokens() {
		$fonts = array();
		foreach ( $this->declaration_records as $record ) {
			if ( 'font-family' !== $record['property'] ) {
				continue;
			}
			$families = preg_split( '/\s*,\s*/', $record['value'] );
			foreach ( (array) $families as $family ) {
				$family = trim( $family, " \t\n\r\0\x0B\"'" );
				if ( '' === $family || false !== stripos( $family, 'url(' ) ) {
					continue;
				}
				$key = strtolower( $family );
				if ( ! isset( $fonts[ $key ] ) ) {
					$fonts[ $key ] = array( 'family' => $family, 'usage_count' => 0 );
				}
				$fonts[ $key ]['usage_count'] += max( 1, (int) $record['count'] );
			}
		}
		$fonts = array_values( $fonts );
		usort( $fonts, static function ( $left, $right ) {
			$priority = $right['usage_count'] <=> $left['usage_count'];
			return 0 !== $priority ? $priority : strnatcasecmp( $left['family'], $right['family'] );
		} );
		return array_slice( $fonts, 0, Analysis_Limits::MAX_TOKENS_PER_FAMILY );
	}

	/**
	 * Extract values for selected CSS properties.
	 *
	 * @param array<int, string> $properties Property names.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_property_tokens( $properties ) {
		$tokens = array();
		foreach ( $this->declaration_records as $record ) {
			if ( ! in_array( $record['property'], $properties, true ) ) {
				continue;
			}
			$key = $record['property'] . '|' . strtolower( $record['value'] );
			if ( ! isset( $tokens[ $key ] ) ) {
				$tokens[ $key ] = array( 'property' => $record['property'], 'value' => $record['value'], 'usage_count' => 0 );
			}
			$tokens[ $key ]['usage_count'] += max( 1, (int) $record['count'] );
		}
		$tokens = array_values( $tokens );
		usort( $tokens, static function ( $left, $right ) {
			$priority = $right['usage_count'] <=> $left['usage_count'];
			if ( 0 !== $priority ) {
				return $priority;
			}
			$property = strnatcasecmp( $left['property'], $right['property'] );
			return 0 !== $property ? $property : strnatcasecmp( $left['value'], $right['value'] );
		} );
		return array_slice( $tokens, 0, Analysis_Limits::MAX_TOKENS_PER_FAMILY );
	}

	/**
	 * Extract radius values.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_radius_tokens() {
		$values = array();
		foreach ( $this->declaration_records as $record ) {
			if ( false === strpos( $record['property'], 'radius' ) ) {
				continue;
			}
			$value = $record['value'];
			if ( '' === $value || ! in_array( $value, $values, true ) ) {
				$values[] = $value;
			}
		}
		return array_slice( $values, 0, Analysis_Limits::MAX_TOKENS_PER_FAMILY );
	}

	/**
	 * Extract recurring box shadows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_shadow_tokens() {
		$shadows = array();
		foreach ( $this->declaration_records as $record ) {
			if ( 'box-shadow' !== $record['property'] && 'text-shadow' !== $record['property'] ) {
				continue;
			}
			$key = strtolower( $record['value'] );
			if ( ! isset( $shadows[ $key ] ) ) {
				$shadows[ $key ] = array( 'value' => $record['value'], 'usage_count' => 0, 'property' => $record['property'] );
			}
			$shadows[ $key ]['usage_count'] += max( 1, (int) $record['count'] );
		}
		return array_slice( array_values( $shadows ), 0, Analysis_Limits::MAX_TOKENS_PER_FAMILY );
	}

	/**
	 * Extract container-like width and centering signals.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_container_tokens() {
		$values = array();
		foreach ( $this->declaration_records as $record ) {
			if ( ! in_array( $record['property'], array( 'width', 'max-width', 'margin-left', 'margin-right' ), true ) ) {
				continue;
			}
			$value = $record['value'];
			if ( ! preg_match( '/^(?:\d+(?:\.\d+)?)(?:px|em|rem|%|vw|vh)$/i', $value ) && 'auto' !== strtolower( $value ) ) {
				continue;
			}
			$key = $record['property'] . '|' . strtolower( $value );
			if ( ! isset( $values[ $key ] ) ) {
				$values[ $key ] = array( 'property' => $record['property'], 'value' => $value, 'usage_count' => 0 );
			}
			$values[ $key ]['usage_count'] += max( 1, (int) $record['count'] );
		}
		return array_slice( array_values( $values ), 0, Analysis_Limits::MAX_TOKENS_PER_FAMILY );
	}

	/**
	 * Extract static layout declarations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_layout_rules() {
		$properties = array( 'display', 'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content', 'gap', 'row-gap', 'column-gap', 'grid-template-columns', 'grid-template-rows', 'width', 'max-width' );
		$layout = array();
		foreach ( $this->declaration_records as $record ) {
			if ( in_array( $record['property'], $properties, true ) ) {
				$layout[] = array( 'property' => $record['property'], 'value' => $record['value'], 'usage_count' => $record['count'] );
			}
		}
		return array_slice( $layout, 0, Analysis_Limits::MAX_TOKENS_PER_FAMILY );
	}

	/**
	 * Extract repeated button-like style signatures.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_button_variants( $context ) {
		$variants = array();
		foreach ( $this->rules as $rule ) {
			$selector_text = strtolower( implode( ',', (array) $rule['selector'] ) );
			if ( false === strpos( $selector_text, 'button' ) && false === strpos( $selector_text, 'btn' ) && false === strpos( $selector_text, 'cta' ) ) {
				continue;
			}
			$this->add_button_variant( $variants, $rule['declarations'], $rule['selector'] );
		}
		foreach ( $this->inline_styles as $node_id => $declarations ) {
			$node = isset( $context['nodes'][ $node_id ] ) ? $context['nodes'][ $node_id ] : array();
			$tag = isset( $node['tag'] ) ? $node['tag'] : '';
			$hint = isset( $context['nodes'][ $node_id ] ) ? strtolower( implode( ' ', $context['nodes'][ $node_id ]['classes'] ) ) : '';
			if ( ! in_array( $tag, array( 'button', 'a' ), true ) || ( false === strpos( $hint, 'btn' ) && false === strpos( $hint, 'button' ) && false === strpos( $hint, 'cta' ) ) ) {
				continue;
			}
			$this->add_button_variant( $variants, $declarations, array( '#' . $node_id ) );
		}
		return array_slice( array_values( $variants ), 0, 20 );
	}

	/**
	 * Add or merge a button style signature.
	 *
	 * @param array<string, array<string, mixed>> $variants     Variant map.
	 * @param array<string, string>                $declarations Declarations.
	 * @param mixed                               $selector     Selector evidence.
	 * @return void
	 */
	private function add_button_variant( &$variants, $declarations, $selector ) {
		$fields = array( 'background', 'background-color', 'color', 'border', 'border-radius', 'padding', 'font-size', 'font-weight' );
		$signature = array();
		foreach ( $fields as $field ) {
			if ( isset( $declarations[ $field ] ) ) {
				$signature[ $field ] = $declarations[ $field ];
			}
		}
		if ( count( $signature ) < 2 ) {
			return;
		}
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $signature ) : json_encode( $signature );
		$key = md5( is_string( $encoded ) ? $encoded : serialize( $signature ) );
		if ( ! isset( $variants[ $key ] ) ) {
			$background = isset( $signature['background'] ) ? $signature['background'] : ( isset( $signature['background-color'] ) ? $signature['background-color'] : 'unknown' );
			$variants[ $key ] = array(
				'name'         => in_array( strtolower( $background ), array( 'transparent', 'none' ), true ) ? 'secondary' : 'primary',
				'background'   => $background,
				'text_color'   => isset( $signature['color'] ) ? $signature['color'] : null,
				'border'       => isset( $signature['border'] ) ? $signature['border'] : null,
				'radius'       => isset( $signature['border-radius'] ) ? $signature['border-radius'] : null,
				'padding'      => isset( $signature['padding'] ) ? $signature['padding'] : null,
				'font_size'    => isset( $signature['font-size'] ) ? $signature['font-size'] : null,
				'font_weight'  => isset( $signature['font-weight'] ) ? $signature['font-weight'] : null,
				'usage_count'  => 0,
				'source'       => Analysis_Normalizer::selector( is_array( $selector ) ? implode( ',', $selector ) : $selector ),
				'confidence'   => 0.68,
			);
		}
		$variants[ $key ]['usage_count']++;
	}
}
