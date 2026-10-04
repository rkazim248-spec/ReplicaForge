<?php
/**
 * Typography hierarchy analysis.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts conservative font hierarchy signals from static declarations.
 */
final class Typography_Analyzer {

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
	 * Analyze typography hierarchy.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @return array<string, mixed>
	 */
	public function analyze( $context ) {
		$hierarchy = array();
		foreach ( array( 'h1' => 'heading_1', 'h2' => 'heading_2', 'h3' => 'heading_3' ) as $tag => $key ) {
			$hierarchy[ $key ] = $this->analyze_role( $context, $tag, $key );
		}
		$body_node_id = $this->find_first( $context, 'body' );
		$hierarchy['body'] = $this->analyze_role( $context, 'body', 'body' );
		if ( ! $body_node_id ) {
			$paragraphs = $this->find_first( $context, 'p' );
			if ( $paragraphs ) {
				$hierarchy['body'] = $this->analyze_role( $context, 'p', 'body' );
			}
		}

		return array(
			'hierarchy' => $hierarchy,
			'families'  => $this->font_family_records(),
			'sizes'     => $this->filter_records( $this->style->get_declaration_records(), 'font_sizes' ),
			'weights'   => $this->filter_records( $this->style->get_declaration_records(), 'font_weights' ),
			'line_heights' => $this->filter_records( $this->style->get_declaration_records(), 'line_heights' ),
			'letter_spacing' => $this->filter_records( $this->style->get_declaration_records(), 'letter_spacing' ),
			'text_transform' => $this->filter_records( $this->style->get_declaration_records(), 'text_transform' ),
		);
	}

	/**
	 * Find the first node with a tag.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $tag     Tag name.
	 * @return string
	 */
	private function find_first( $context, $tag ) {
		foreach ( $this->dom->get_node_ids( $context ) as $node_id ) {
			$node = $this->dom->get_node( $context, $node_id );
			if ( ! empty( $node['visible'] ) && isset( $node['tag'] ) && $tag === $node['tag'] ) {
				return $node_id;
			}
		}
		return '';
	}

	/**
	 * Analyze one semantic typography role.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $tag     Source tag.
	 * @param string               $role    Typography role.
	 * @return array<string, mixed>
	 */
	private function analyze_role( $context, $tag, $role ) {
		$node_id = $this->find_first( $context, $tag );
		$declarations = $node_id ? $this->style->get_declarations( $this->dom, $context, $node_id ) : array();
		$source = $node_id ? $this->dom->get_source( $context, $node_id ) : array( 'tag' => $tag, 'selector' => $tag );
		$record = array(
			'role'         => $role,
			'font_family'  => isset( $declarations['font-family'] ) ? $declarations['font-family'] : null,
			'font_size'    => isset( $declarations['font-size'] ) ? $declarations['font-size'] : null,
			'font_weight'  => isset( $declarations['font-weight'] ) ? $this->numeric_weight( $declarations['font-weight'] ) : null,
			'line_height'  => isset( $declarations['line-height'] ) ? $this->line_height( $declarations['line-height'] ) : null,
			'letter_spacing' => isset( $declarations['letter-spacing'] ) ? $declarations['letter-spacing'] : null,
			'text_transform' => isset( $declarations['text-transform'] ) ? $declarations['text-transform'] : null,
			'source'       => $source,
			'confidence'   => $this->has_values( $declarations ) ? 0.82 : 0.25,
			'source_type'  => $this->has_values( $declarations ) ? 'detected' : 'inferred',
		);
		return $record;
	}

	/**
	 * Split comma-separated font-family declarations into individual tokens.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function font_family_records() {
		$families = array();
		foreach ( $this->filter_records( $this->style->get_declaration_records(), 'fonts' ) as $record ) {
			foreach ( preg_split( '/\s*,\s*/', $record['value'] ) as $family ) {
				$family = trim( $family, " \t\n\r\0\x0B\"'" );
				if ( '' === $family ) {
					continue;
				}
				$key = strtolower( $family );
				if ( ! isset( $families[ $key ] ) ) {
					$families[ $key ] = array( 'property' => 'font-family', 'value' => $family, 'count' => 0 );
				}
				$families[ $key ]['count'] += (int) $record['count'];
			}
		}
		return array_values( $families );
	}

	/**
	 * Filter declaration records by a token family.
	 *
	 * @param array<int, array<string, mixed>> $records Records.
	 * @param string                            $family  Family key.
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_records( $records, $family ) {
		$properties = array(
			'fonts'          => array( 'font-family' ),
			'font_sizes'     => array( 'font-size' ),
			'font_weights'   => array( 'font-weight' ),
			'line_heights'   => array( 'line-height' ),
			'letter_spacing' => array( 'letter-spacing' ),
			'text_transform' => array( 'text-transform' ),
		);
		$allowed = isset( $properties[ $family ] ) ? $properties[ $family ] : array();
		$result = array();
		foreach ( (array) $records as $record ) {
			if ( isset( $record['property'] ) && in_array( $record['property'], $allowed, true ) ) {
				if ( isset( $record['selectors'] ) && is_array( $record['selectors'] ) ) {
					$selectors = array();
					foreach ( $record['selectors'] as $selector ) {
						$selector = Analysis_Normalizer::selector( $selector );
						if ( '' !== $selector && ! in_array( $selector, $selectors, true ) ) {
							$selectors[] = $selector;
						}
					}
					$record['selectors'] = $selectors;
				}
				$result[] = $record;
			}
			if ( count( $result ) >= Analysis_Limits::MAX_TOKENS_PER_FAMILY ) {
				break;
			}
		}
		return $result;
	}

	/**
	 * Determine whether a declaration map has useful typography values.
	 *
	 * @param array<string, string> $declarations Declarations.
	 * @return bool
	 */
	private function has_values( $declarations ) {
		if ( ! is_array( $declarations ) ) {
			return false;
		}
		foreach ( array( 'font-family', 'font_size', 'font-size', 'font_weight', 'font-weight', 'line_height', 'line-height', 'letter_spacing', 'letter-spacing', 'text_transform', 'text-transform' ) as $property ) {
			if ( isset( $declarations[ $property ] ) && '' !== $declarations[ $property ] && null !== $declarations[ $property ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize numeric font weights while preserving named values.
	 *
	 * @param string $value Raw weight.
	 * @return int|string
	 */
	private function numeric_weight( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^\d{3}$/', $value ) ? absint( $value ) : $value;
	}

	/**
	 * Normalize line-height numbers and preserve unit values.
	 *
	 * @param string $value Raw line height.
	 * @return float|string
	 */
	private function line_height( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^\d+(?:\.\d+)?$/', $value ) ? (float) $value : $value;
	}
}
