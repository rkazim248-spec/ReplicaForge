<?php
/**
 * Structural, content, and link comparison for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares section presence, section order, component presence, text content,
 * and link destinations.
 *
 * Content is only ever reported as a difference. ReplicaForge never rewrites,
 * repairs, or decides which side is correct.
 */
final class Structural_Comparator {

	/**
	 * Run the comparison.
	 *
	 * @param array<string, mixed> $context Comparison context.
	 * @return array<int, array<string, mixed>>
	 */
	public function compare( Comparison_Context $context ) {
		$checks = array();

		foreach ( $this->sections( $context ) as $check ) {
			$checks[] = $check;
		}
		foreach ( $this->section_order( $context ) as $check ) {
			$checks[] = $check;
		}
		foreach ( $this->components( $context ) as $check ) {
			$checks[] = $check;
		}
		foreach ( $this->content( $context ) as $check ) {
			$checks[] = $check;
		}
		foreach ( $this->links( $context ) as $check ) {
			$checks[] = $check;
		}

		return $checks;
	}

	/**
	 * Compare section presence.
	 *
	 * @param Comparison_Context $context Comparison context.
	 * @return array<int, array<string, mixed>>
	 */
	private function sections( Comparison_Context $context ) {
		$checks = array();
		$pairs  = $context->section_pairs();

		foreach ( $pairs as $key => $pair ) {
			$source    = $pair['source'];
			$generated = $pair['generated'];
			$checks[]  = array(
				'category'             => 'structure',
				'target'               => 'section:' . $key,
				'property'             => 'section_present',
				'expected'             => $source['type'],
				'actual'               => $generated['type'],
				'state'                => empty( $generated ) ? 'missing' : 'pass',
				'severity'             => empty( $generated ) ? 'critical' : 'informational',
				'message'              => empty( $generated )
					? sprintf( 'The source section "%1$s" is missing from the generated draft.', $key )
					: '',
				'confidence'           => (float) $source['confidence'],
				'source_reference'     => array( 'source_section_id' => $key ),
				'generated_reference'  => empty( $generated ) ? array() : array( 'elementor_element_id' => (string) $generated['key'] ),
			);
		}

		foreach ( $context->missing_sections() as $key ) {
			$checks[] = array(
				'category'            => 'structure',
				'target'              => 'section:' . $key,
				'property'            => 'section_present',
				'expected'            => 'present',
				'actual'              => 'missing',
				'state'               => 'missing',
				'severity'            => 'critical',
				'message'             => sprintf( 'The source section "%s" is missing from the generated draft.', $key ),
				'confidence'          => 0.9,
				'source_reference'    => array( 'source_section_id' => $key ),
				'generated_reference' => array(),
			);
		}

		foreach ( $context->extra_sections() as $key ) {
			$checks[] = array(
				'category'            => 'structure',
				'target'              => 'section:' . $key,
				'property'            => 'section_present',
				'expected'            => 'absent',
				'actual'              => 'present',
				'state'               => 'extra',
				'severity'            => 'minor',
				'message'             => sprintf( 'The generated draft contains the section "%s", which was not detected on the source page.', $key ),
				'confidence'          => 0.6,
				'source_reference'    => array(),
				'generated_reference' => array( 'elementor_element_id' => $key ),
			);
		}

		return $checks;
	}

	/**
	 * Compare section order.
	 *
	 * @param Comparison_Context $context Comparison context.
	 * @return array<int, array<string, mixed>>
	 */
	private function section_order( Comparison_Context $context ) {
		$source_keys    = array();
		$generated_keys = array();
		foreach ( $context->section_pairs() as $key => $pair ) {
			$source_keys[]    = $key;
			$generated_keys[] = (string) $pair['generated']['key'];
		}

		$position = array();
		foreach ( $generated_keys as $index => $key ) {
			$position[ $key ] = $index;
		}

		$previous    = -1;
		$out_of_sync = array();
		foreach ( $source_keys as $index => $key ) {
			if ( ! isset( $position[ $generated_keys[ $index ] ] ) ) {
				continue;
			}
			$current = (int) $position[ $generated_keys[ $index ] ];
			if ( $current < $previous ) {
				$out_of_sync[] = $key;
			}
			$previous = max( $previous, $current );
		}

		if ( empty( $out_of_sync ) ) {
			return array(
				array(
					'category'            => 'section_order',
					'target'              => 'document',
					'property'            => 'section_order',
					'expected'            => implode( ' > ', $source_keys ),
					'actual'              => implode( ' > ', $generated_keys ),
					'state'               => 'pass',
					'confidence'          => 1.0,
					'source_reference'    => array( 'source_section_id' => $source_keys ? $source_keys[0] : '' ),
					'generated_reference' => array(),
				),
			);
		}

		return array(
			array(
				'category'            => 'section_order',
				'target'              => 'document',
				'property'            => 'section_order',
				'expected'            => implode( ' > ', $source_keys ),
				'actual'              => implode( ' > ', $generated_keys ),
				'state'               => 'fail',
				'severity'            => 'major',
				'message'             => sprintf(
					'Section order mismatch: %s appear later on the source page than in the generated draft.',
					implode( ', ', $out_of_sync )
				),
				'confidence'          => 0.9,
				'source_reference'    => array( 'source_section_id' => $out_of_sync[0] ),
				'generated_reference' => array(),
			),
		);
	}

	/**
	 * Compare component presence per type.
	 *
	 * @param Comparison_Context $context Comparison context.
	 * @return array<int, array<string, mixed>>
	 */
	private function components( Comparison_Context $context ) {
		$checks  = array();
		$expected = $this->count_types( $this->source_components( $context ) );
		$actual   = $this->count_types( $this->generated_components( $context ) );

		$types = array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ) );
		sort( $types );

		foreach ( $types as $type ) {
			$source_count    = isset( $expected[ $type ] ) ? (int) $expected[ $type ] : 0;
			$generated_count = isset( $actual[ $type ] ) ? (int) $actual[ $type ] : 0;
			$difference      = $generated_count - $source_count;

			$checks[] = array(
				'category'            => 'component',
				'target'              => 'document',
				'property'            => 'component_count:' . $type,
				'expected'            => $source_count,
				'actual'              => $generated_count,
				'difference'          => $difference,
				'state'               => 0 === $difference ? 'pass' : ( $difference < 0 ? 'missing' : 'extra' ),
				'severity'            => 0 === $difference ? 'informational' : ( 'heading' === $type || 'button' === $type ? 'major' : 'moderate' ),
				'message'             => 0 === $difference
					? ''
					: sprintf(
						/* translators: 1: Component type, 2: Missing count, 3: Extra count. */
						__( '%1$s count differs: %2$s missing, %3$s added.', 'replicaforge' ),
						$type,
						$difference < 0 ? (string) abs( $difference ) : '0',
						$difference > 0 ? (string) $difference : '0'
					),
				'confidence'          => 0.8,
				'source_reference'    => array(),
				'generated_reference' => array(),
			);
		}

		foreach ( $context->missing_components() as $key ) {
			$pair  = $context->pair( $key );
			$type  = isset( $pair['source']['type'] ) ? (string) $pair['source']['type'] : 'component';
			$checks[] = array(
				'category'            => 'component',
				'target'              => 'component:' . $key,
				'property'            => 'component_present',
				'expected'            => $type,
				'actual'              => 'missing',
				'state'               => 'missing',
				'severity'            => 'major',
				'message'             => sprintf( 'The source component "%1$s" (%2$s) was not generated.', $key, $type ),
				'confidence'          => isset( $pair['source']['confidence'] ) ? (float) $pair['source']['confidence'] : 0.5,
				'source_reference'    => array( 'source_component_id' => $key ),
				'generated_reference' => array(),
			);
		}

		foreach ( $context->extra_components() as $key ) {
			$checks[] = array(
				'category'            => 'component',
				'target'              => 'component:' . $key,
				'property'            => 'component_present',
				'expected'            => 'absent',
				'actual'              => 'present',
				'state'               => 'extra',
				'severity'            => 'minor',
				'message'             => sprintf( 'The generated draft contains the element "%s", which has no source counterpart.', $key ),
				'confidence'          => 0.5,
				'source_reference'    => array(),
				'generated_reference' => array( 'elementor_element_id' => $key ),
			);
		}

		return $checks;
	}

	/**
	 * Compare detected text content.
	 *
	 * @param Comparison_Context $context Comparison context.
	 * @return array<int, array<string, mixed>>
	 */
	private function content( Comparison_Context $context ) {
		$checks = array();
		foreach ( $context->pairs() as $key => $pair ) {
			$source    = $pair['source'];
			$generated = $pair['generated'];
			$expected  = isset( $source['text'] ) ? trim( (string) $source['text'] ) : '';
			$actual    = ! empty( $generated ) && isset( $generated['text'] ) ? trim( (string) $generated['text'] ) : '';

			if ( '' === $expected ) {
				$checks[] = array(
					'category'            => 'content',
					'target'              => 'component:' . $key,
					'property'            => 'text',
					'expected'            => null,
					'actual'              => '' === $actual ? null : $actual,
					'state'               => 'unknown',
					'confidence'          => 0.0,
					'source_reference'    => array( 'source_component_id' => $key ),
					'generated_reference' => array( 'elementor_element_id' => ! empty( $generated ) ? (string) $generated['key'] : '' ),
				);
				continue;
			}

			$state = 'pass';
			if ( '' === $actual ) {
				$state = 'missing';
			} elseif ( $this->normalize_text( $expected ) !== $this->normalize_text( $actual ) ) {
				$state = 'fail';
			}

			$checks[] = array(
				'category'            => 'content',
				'target'              => 'component:' . $key,
				'property'            => 'text',
				'expected'            => $this->truncate( $expected ),
				'actual'              => '' === $actual ? null : $this->truncate( $actual ),
				'state'               => $state,
				'message'             => 'pass' === $state ? '' : sprintf(
					/* translators: 1: Source text, 2: Generated text. */
					__( 'Content mismatch: the source reads "%1$s" and the draft reads "%2$s".', 'replicaforge' ),
					$this->truncate( $expected, 60 ),
					'' === $actual ? __( 'nothing', 'replicaforge' ) : $this->truncate( $actual, 60 )
				),
				'confidence'          => (float) $pair['confidence'],
				'source_reference'    => array( 'source_component_id' => $key ),
				'generated_reference' => array( 'elementor_element_id' => ! empty( $generated ) ? (string) $generated['key'] : '' ),
			);
		}

		return $checks;
	}

	/**
	 * Compare link destinations.
	 *
	 * @param Comparison_Context $context Comparison context.
	 * @return array<int, array<string, mixed>>
	 */
	private function links( Comparison_Context $context ) {
		$checks = array();
		foreach ( $context->pairs() as $key => $pair ) {
			$expected = isset( $pair['source']['link'] ) ? (string) $pair['source']['link'] : '';
			$actual   = ! empty( $pair['generated'] ) && isset( $pair['generated']['link'] ) ? (string) $pair['generated']['link'] : '';
			if ( '' === $expected ) {
				$checks[] = array(
					'category'            => 'link',
					'target'              => 'component:' . $key,
					'property'            => 'href',
					'expected'            => null,
					'actual'              => '' === $actual ? null : $actual,
					'state'               => 'unknown',
					'confidence'          => 0.0,
					'source_reference'    => array( 'source_component_id' => $key ),
					'generated_reference' => array( 'elementor_element_id' => ! empty( $pair['generated'] ) ? (string) $pair['generated']['key'] : '' ),
				);
				continue;
			}

			$state = 'pass';
			if ( '' === $actual ) {
				$state = 'missing';
			} elseif ( $this->normalize_url( $expected ) !== $this->normalize_url( $actual ) ) {
				$state = 'fail';
			}

			$checks[] = array(
				'category'            => 'link',
				'target'              => 'component:' . $key,
				'property'            => 'href',
				'expected'            => $this->truncate( $expected, 200 ),
				'actual'              => '' === $actual ? null : $this->truncate( $actual, 200 ),
				'state'               => $state,
				'message'             => 'pass' === $state ? '' : sprintf(
					/* translators: 1: Source link, 2: Generated link. */
					__( 'Link mismatch: the source points to "%1$s" and the draft points to "%2$s".', 'replicaforge' ),
					$this->truncate( $expected, 80 ),
					'' === $actual ? __( 'nothing', 'replicaforge' ) : $this->truncate( $actual, 80 )
				),
				'confidence'          => (float) $pair['confidence'],
				'source_reference'    => array( 'source_component_id' => $key ),
				'generated_reference' => array( 'elementor_element_id' => ! empty( $pair['generated'] ) ? (string) $pair['generated']['key'] : '' ),
			);
		}

		return $checks;
	}

	/**
	 * Return the source components of every pair.
	 *
	 * @param Comparison_Context $context Comparison context.
	 * @return array<string, array<string, mixed>>
	 */
	private function source_components( Comparison_Context $context ) {
		$result = array();
		foreach ( $context->pairs() as $key => $pair ) {
			$result[ $key ] = $pair['source'];
		}
		return $result;
	}

	/**
	 * Return the generated components of every pair.
	 *
	 * @param Comparison_Context $context Comparison context.
	 * @return array<string, array<string, mixed>>
	 */
	private function generated_components( Comparison_Context $context ) {
		$result = array();
		foreach ( $context->pairs() as $pair ) {
			if ( ! empty( $pair['generated'] ) ) {
				$result[ (string) $pair['generated']['key'] ] = $pair['generated'];
			}
		}
		return $result;
	}

	/**
	 * Count components per comparable type.
	 *
	 * @param array<string, array<string, mixed>> $components Components.
	 * @return array<string, int>
	 */
	private function count_types( array $components ) {
		$counts = array();
		foreach ( $components as $component ) {
			$type = isset( $component['type'] ) ? (string) $component['type'] : 'unknown';
			if ( 'card_group' === $type ) {
				$type = 'card';
			}
			if ( in_array( $type, array( 'product_card', 'feature_card', 'portfolio_card', 'blog_card', 'team_card', 'pricing_card', 'testimonial_card', 'testimonial' ), true ) ) {
				$type = 'card';
			}
			if ( in_array( $type, array( 'link', 'navigation_link' ), true ) ) {
				$type = 'button';
			}
			if ( 'unknown' === $type || 'container' === $type || 'section' === $type || 'widget' === $type ) {
				continue;
			}
			$counts[ $type ] = isset( $counts[ $type ] ) ? $counts[ $type ] + 1 : 1;
		}
		return $counts;
	}

	/**
	 * Normalize text for comparison.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	private function normalize_text( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Normalize a URL for comparison.
	 *
	 * @param string $value Raw URL.
	 * @return string
	 */
	private function normalize_url( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/#.*$/', '', $value );
		$value = rtrim( (string) $value, '/' );
		return strtolower( (string) $value );
	}

	/**
	 * Truncate a value for display.
	 *
	 * @param string $value      Raw value.
	 * @param int    $max_length Maximum length.
	 * @return string
	 */
	private function truncate( $value, $max_length = 160 ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_length, 'UTF-8' );
		}
		return substr( $value, 0, $max_length );
	}
}
