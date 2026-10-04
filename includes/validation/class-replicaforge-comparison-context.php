<?php
/**
 * Comparison context and pairing for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Pairs the two normalized sides before any comparison runs.
 *
 * Pairing prefers the Phase 4 identity map, which links a source component, a
 * reconstruction component, and an Elementor element. Only when that map is
 * incomplete does it fall back to a deterministic type and order match, so two
 * runs over the same inputs always pair the same records.
 */
final class Comparison_Context {

	/**
	 * Paired components keyed by source component key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $pairs = array();

	/**
	 * Paired sections keyed by source section key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $section_pairs = array();

	/**
	 * Source component keys with no generated counterpart.
	 *
	 * @var array<int, string>
	 */
	private $missing_components = array();

	/**
	 * Generated component keys with no source counterpart.
	 *
	 * @var array<int, string>
	 */
	private $extra_components = array();

	/**
	 * Source section keys with no generated counterpart.
	 *
	 * @var array<int, string>
	 */
	private $missing_sections = array();

	/**
	 * Generated section keys with no source counterpart.
	 *
	 * @var array<int, string>
	 */
	private $extra_sections = array();

	/**
	 * Reverse lookup from generated component key to source component key.
	 *
	 * @var array<string, string>
	 */
	private $reverse = array();

	/**
	 * Build the pairing.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return void
	 */
	public function build( array $source, array $generated ) {
		$this->reset();
		$this->pair_sections( $source, $generated );
		$this->pair_components( $source, $generated );
	}

	/**
	 * Clear the pairing state.
	 *
	 * @return void
	 */
	public function reset() {
		$this->pairs              = array();
		$this->section_pairs      = array();
		$this->missing_components = array();
		$this->extra_components   = array();
		$this->missing_sections   = array();
		$this->extra_sections     = array();
		$this->reverse            = array();
	}

	/**
	 * Pair sections by index after a type-aware greedy match.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return void
	 */
	private function pair_sections( array $source, array $generated ) {
		$source_sections   = isset( $source['sections'] ) && is_array( $source['sections'] ) ? $source['sections'] : array();
		$generated_sections = isset( $generated['sections'] ) && is_array( $generated['sections'] ) ? $generated['sections'] : array();

		$used    = array();
		$buckets = array();
		foreach ( $generated_sections as $index => $section ) {
			$type = isset( $section['type'] ) ? (string) $section['type'] : 'section';
			$buckets[ $type ][] = (string) $section['key'];
			unset( $index );
		}

		foreach ( $source_sections as $section ) {
			$key  = (string) $section['key'];
			$type = isset( $section['type'] ) ? (string) $section['type'] : 'section';
			$match = '';
			if ( ! empty( $buckets[ $type ] ) ) {
				$match = (string) array_shift( $buckets[ $type ] );
			} elseif ( ! empty( $generated_sections ) ) {
				foreach ( $generated_sections as $candidate ) {
					$candidate_key = (string) $candidate['key'];
					if ( ! in_array( $candidate_key, $used, true ) ) {
						$match = $candidate_key;
						break;
					}
				}
			}
			if ( '' === $match ) {
				$this->missing_sections[] = $key;
				continue;
			}
			$used[]                        = $match;
			$this->section_pairs[ $key ] = array(
				'source'    => $section,
				'generated' => $this->find_generated_section( $generated_sections, $match ),
			);
		}

		foreach ( $generated_sections as $section ) {
			if ( ! in_array( (string) $section['key'], $used, true ) ) {
				$this->extra_sections[] = (string) $section['key'];
			}
		}
	}

	/**
	 * Return a generated section by key.
	 *
	 * @param array<int, array> $sections Generated sections.
	 * @param string            $key      Section key.
	 * @return array<string, mixed>
	 */
	private function find_generated_section( array $sections, $key ) {
		foreach ( $sections as $section ) {
			if ( (string) $section['key'] === $key ) {
				return $section;
			}
		}
		return array();
	}

	/**
	 * Pair components using the identity map first, then type and order.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return void
	 */
	private function pair_components( array $source, array $generated ) {
		$source_components   = isset( $source['components'] ) && is_array( $source['components'] ) ? $source['components'] : array();
		$generated_components = isset( $generated['components'] ) && is_array( $generated['components'] ) ? $generated['components'] : array();

		$by_source_id = array();
		foreach ( $generated_components as $key => $component ) {
			$source_id = isset( $component['source_id'] ) ? (string) $component['source_id'] : '';
			if ( '' !== $source_id ) {
				$by_source_id[ $source_id ] = (string) $key;
			}
		}

		$used = array();
		foreach ( $source_components as $key => $component ) {
			$key        = (string) $key;
			$generated_key = '';
			if ( isset( $by_source_id[ $key ] ) ) {
				$generated_key = $by_source_id[ $key ];
			}
			if ( '' !== $generated_key ) {
				$used[ $generated_key ] = true;
			}
			$this->pairs[ $key ] = array(
				'source'     => $component,
				'generated'  => '' !== $generated_key && isset( $generated_components[ $generated_key ] ) ? $generated_components[ $generated_key ] : array(),
				'pairing'    => '' !== $generated_key ? 'identity_map' : 'unmatched',
				'confidence' => '' !== $generated_key ? 1.0 : 0.0,
			);
			if ( '' !== $generated_key ) {
				$this->reverse[ $generated_key ] = $key;
			}
		}

		// Deterministic fallback: match remaining generated components of the
		// same type in document order.
		$unmatched = array();
		foreach ( $this->pairs as $key => $pair ) {
			if ( 'unmatched' === $pair['pairing'] ) {
				$unmatched[ $key ] = $pair['source'];
			}
		}
		$queues = array();
		foreach ( $generated_components as $generated_key => $component ) {
			if ( isset( $used[ $generated_key ] ) ) {
				continue;
			}
			$type                        = $this->component_type( $component );
			$queues[ $type ][]           = (string) $generated_key;
		}
		foreach ( $unmatched as $key => $component ) {
			$type = $this->component_type( $component );
			if ( empty( $queues[ $type ] ) ) {
				continue;
			}
			$generated_key = (string) array_shift( $queues[ $type ] );
			$this->pairs[ $key ]['generated']  = $generated_components[ $generated_key ];
			$this->pairs[ $key ]['pairing']    = 'type_and_order';
			$this->pairs[ $key ]['confidence'] = 0.6;
			$this->reverse[ $generated_key ]    = $key;
		}

		foreach ( $source_components as $key => $component ) {
			if ( empty( $this->pairs[ (string) $key ]['generated'] ) ) {
				$this->missing_components[] = (string) $key;
			}
		}
		foreach ( $generated_components as $generated_key => $component ) {
			if ( ! isset( $this->reverse[ (string) $generated_key ] ) ) {
				$this->extra_components[] = (string) $generated_key;
			}
		}
	}

	/**
	 * Return a comparable type for a component record.
	 *
	 * @param array<string, mixed> $component Component record.
	 * @return string
	 */
	private function component_type( array $component ) {
		$type = isset( $component['type'] ) ? (string) $component['type'] : 'unknown';
		if ( 'container' === $type || 'section' === $type || 'widget' === $type ) {
			// Untyped generated elements are matched by their widget identity so
			// a user-added widget is not paired with a typed source component.
			$widget = isset( $component['widget'] ) ? (string) $component['widget'] : '';
			return 'container' === $type ? 'container' : ( '' !== $widget ? 'widget:' . $widget : $type );
		}
		if ( in_array( $type, array( 'card', 'product_card', 'feature_card', 'portfolio_card', 'blog_card', 'team_card', 'pricing_card', 'testimonial_card', 'testimonial' ), true ) ) {
			return 'card';
		}
		if ( in_array( $type, array( 'button', 'link', 'navigation_link' ), true ) ) {
			return 'button';
		}
		return $type;
	}

	/**
	 * Return paired components.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function pairs() {
		return $this->pairs;
	}

	/**
	 * Return a single pair.
	 *
	 * @param string $source_key Source component key.
	 * @return array<string, mixed>
	 */
	public function pair( $source_key ) {
		return isset( $this->pairs[ $source_key ] ) ? $this->pairs[ $source_key ] : array();
	}

	/**
	 * Return paired sections.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function section_pairs() {
		return $this->section_pairs;
	}

	/**
	 * Return source component keys with no generated counterpart.
	 *
	 * @return array<int, string>
	 */
	public function missing_components() {
		return $this->missing_components;
	}

	/**
	 * Return generated component keys with no source counterpart.
	 *
	 * @return array<int, string>
	 */
	public function extra_components() {
		return $this->extra_components;
	}

	/**
	 * Return source section keys with no generated counterpart.
	 *
	 * @return array<int, string>
	 */
	public function missing_sections() {
		return $this->missing_sections;
	}

	/**
	 * Return generated section keys with no source counterpart.
	 *
	 * @return array<int, string>
	 */
	public function extra_sections() {
		return $this->extra_sections;
	}

	/**
	 * Return the source component key of a generated component.
	 *
	 * @param string $generated_key Generated component key.
	 * @return string
	 */
	public function source_key_for( $generated_key ) {
		return isset( $this->reverse[ $generated_key ] ) ? $this->reverse[ $generated_key ] : '';
	}
}
