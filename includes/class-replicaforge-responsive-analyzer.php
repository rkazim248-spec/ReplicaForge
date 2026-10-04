<?php
/**
 * Static responsive evidence analysis.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts media-query, breakpoint, and mobile-navigation signals.
 */
final class Responsive_Analyzer {

	/**
	 * DOM analyzer.
	 *
	 * @var Dom_Analyzer
	 */
	private $dom;

	/**
	 * Style analyzer.
	 *
	 * @var Style_Analyzer
	 */
	private $style;

	/**
	 * Constructor.
	 *
	 * @param Dom_Analyzer|null   $dom   DOM analyzer.
	 * @param Style_Analyzer|null $style Style analyzer.
	 */
	public function __construct( $dom = null, $style = null ) {
		$this->dom   = $dom instanceof Dom_Analyzer ? $dom : new Dom_Analyzer();
		$this->style = $style instanceof Style_Analyzer ? $style : new Style_Analyzer();
	}

	/**
	 * Analyze responsive signals.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param array<string, mixed> $phase1  Normalized Phase 1 data.
	 * @return array<string, mixed>
	 */
	public function analyze( $context, $phase1 ) {
		$breakpoints = array();
		$rules       = array();
		$media_rules = $this->style->get_media_rules();
		foreach ( $media_rules as $rule ) {
			$query = isset( $rule['media'] ) ? strtolower( (string) $rule['media'] ) : '';
			$value = $this->extract_breakpoint( $query );
			$key   = '' !== $value ? $value : 'query_' . count( $rules );
			if ( ! isset( $breakpoints[ $key ] ) ) {
				$breakpoints[ $key ] = array( 'value' => $value, 'rules_count' => 0, 'queries' => array() );
			}
			$breakpoints[ $key ]['rules_count']++;
			if ( ! in_array( $query, $breakpoints[ $key ]['queries'], true ) && count( $breakpoints[ $key ]['queries'] ) < 8 ) {
				$breakpoints[ $key ]['queries'][] = $query;
			}
			$changes = $this->detect_changes( $rule );
			if ( ! empty( $changes ) ) {
				$rules[] = array(
					'breakpoint' => $value,
					'query'      => $query,
					'changes'    => $changes,
					'selectors'  => array_map( array( $this, 'safe_selector' ), is_array( $rule['selector'] ) ? $rule['selector'] : array( (string) $rule['selector'] ) ),
					'rule_count' => 1,
					'confidence' => 0.68,
					'source_type' => 'detected',
				);
			}
		}
		foreach ( $breakpoints as &$breakpoint ) {
			$breakpoint['rules_count'] = absint( $breakpoint['rules_count'] );
		}
		unset( $breakpoint );
		$breakpoints = array_values( $breakpoints );
		usort( $breakpoints, static function ( $left, $right ) {
			$value = strnatcasecmp( $left['value'], $right['value'] );
			if ( 0 !== $value ) {
				return $value;
			}
			return strnatcasecmp( implode( ',', $left['queries'] ), implode( ',', $right['queries'] ) );
		} );
		$breakpoints = array_slice( $breakpoints, 0, 50 );

		$mobile = $this->detect_mobile_navigation( $context );
		$phase_breakpoints = isset( $phase1['responsive']['breakpoints'] ) ? (array) $phase1['responsive']['breakpoints'] : array();
		if ( empty( $breakpoints ) && ! empty( $phase_breakpoints ) ) {
			foreach ( array_slice( $phase_breakpoints, 0, 50 ) as $value ) {
				$breakpoints[] = array( 'value' => $value, 'rules_count' => 0, 'queries' => array() );
			}
		}

		return array(
			'viewport_meta'          => ! empty( $phase1['page']['viewport'] ),
			'media_queries_detected' => ! empty( $media_rules ),
			'breakpoints'            => $breakpoints,
			'rules'                  => array_slice( $rules, 0, 200 ),
			'mobile_navigation'      => $mobile,
			'confidence'             => ! empty( $media_rules ) ? 0.72 : 0.25,
		);
	}

	/**
	 * Extract a width value from a media query.
	 *
	 * @param string $query Media query.
	 * @return string
	 */
	private function extract_breakpoint( $query ) {
		if ( preg_match( '/(?:min|max)-width\s*:\s*([0-9]+(?:\.[0-9]+)?(?:px|em|rem))/i', $query, $match ) ) {
			return strtolower( $match[1] );
		}
		return '';
	}

	/**
	 * Normalize a CSS selector for representation output.
	 *
	 * @param mixed $selector Raw selector.
	 * @return string
	 */
	private function safe_selector( $selector ) {
		return Analysis_Normalizer::selector( $selector );
	}

	/**
	 * Detect evidence-backed changes in a media rule.
	 *
	 * @param array<string, mixed> $rule CSS rule.
	 * @return array<int, string>
	 */
	private function detect_changes( $rule ) {
		$changes = array();
		$declarations = isset( $rule['declarations'] ) && is_array( $rule['declarations'] ) ? $rule['declarations'] : array();
		foreach ( $declarations as $property => $value ) {
			if ( in_array( $property, array( 'grid-template-columns', 'grid-template-rows' ), true ) ) {
				$changes[] = 'grid_columns_change';
			} elseif ( in_array( $property, array( 'flex-direction', 'flex-wrap', 'display' ), true ) ) {
				$changes[] = 'layout_change';
			} elseif ( 'font-size' === $property ) {
				$changes[] = 'typography_change';
			} elseif ( in_array( $property, array( 'padding', 'padding-top', 'padding-bottom', 'margin', 'gap', 'row-gap', 'column-gap' ), true ) ) {
				$changes[] = 'spacing_change';
			} elseif ( in_array( $property, array( 'visibility', 'opacity', 'position', 'transform', 'display' ), true ) ) {
				$changes[] = 'visibility_or_interaction_change';
			} elseif ( in_array( $property, array( 'width', 'max-width', 'height', 'min-height' ), true ) ) {
				$changes[] = 'size_change';
			}
		}
		$selector = strtolower( implode( ',', (array) ( isset( $rule['selector'] ) ? $rule['selector'] : array() ) ) );
		foreach ( array( 'nav', 'menu', 'hamburger', 'mobile' ) as $needle ) {
			if ( false !== strpos( $selector, $needle ) ) {
				$changes[] = 'navigation_change';
				break;
			}
		}
		foreach ( array( 'img', 'image', 'background', 'hero' ) as $needle ) {
			if ( false !== strpos( $selector, $needle ) ) {
				$changes[] = 'image_change';
				break;
			}
		}
		return array_values( array_unique( $changes ) );
	}

	/**
	 * Detect static mobile navigation evidence without executing scripts.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @return array<string, mixed>
	 */
	private function detect_mobile_navigation( $context ) {
		$evidence = array();
		foreach ( $this->dom->get_node_ids( $context ) as $node_id ) {
			$node = $this->dom->get_node( $context, $node_id );
			if ( empty( $node['visible'] ) ) {
				continue;
			}
			$tag = isset( $node['tag'] ) ? $node['tag'] : '';
			if ( ! in_array( $tag, array( 'button', 'a', 'nav', 'div' ), true ) ) {
				continue;
			}
			$hint = $this->dom->get_hint_text( $context, $node_id );
			$attributes = isset( $node['attributes'] ) ? $node['attributes'] : array();
			$menu_context = (bool) preg_match( '/(?:mobile|menu|nav|hamburger|drawer|offcanvas)/i', $hint );
			$has_trigger = false;
			foreach ( array( 'hamburger', 'menu-toggle', 'nav-toggle', 'mobile-menu', 'mobile-nav', 'menu-icon' ) as $needle ) {
				if ( false !== strpos( $hint, $needle ) ) {
					$has_trigger = true;
					break;
				}
			}
			if ( $menu_context && ( isset( $attributes['aria-expanded'] ) || isset( $attributes['aria-controls'] ) || isset( $attributes['data-toggle'] ) || isset( $attributes['data-bs-toggle'] ) ) ) {
				$has_trigger = true;
			}
			if ( $has_trigger ) {
				$evidence[] = $this->dom->get_source( $context, $node_id );
				if ( count( $evidence ) >= 5 ) {
					break;
				}
			}
		}
		return array(
			'detected'   => ! empty( $evidence ),
			'behavior'   => ! empty( $evidence ) ? 'unknown' : 'not_detected',
			'confidence' => ! empty( $evidence ) ? 0.58 : 0.2,
			'evidence'   => $evidence,
			'source_type' => ! empty( $evidence ) ? 'detected' : 'inferred',
		);
	}
}
