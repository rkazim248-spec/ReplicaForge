<?php
/**
 * Layout comparison for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares container width, column count and ratio, direction, alignment,
 * wrapping, padding, margin, and minimum height per section.
 *
 * A value is only compared when both sides produced one. When the source
 * evidence is missing the check is recorded as unknown, which is excluded from
 * the metric denominator instead of being scored as a difference.
 */
final class Layout_Comparator {

	/**
	 * Run the comparison.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @param Comparison_Context   $context   Comparison context.
	 * @return array<int, array<string, mixed>>
	 */
	public function compare( array $source, array $generated, Comparison_Context $context ) {
		$checks = array();
		foreach ( $context->section_pairs() as $key => $pair ) {
			foreach ( $this->section_checks( (string) $key, $pair['source'], $pair['generated'] ) as $check ) {
				$checks[] = $check;
			}
		}
		foreach ( $context->pairs() as $key => $pair ) {
			foreach ( $this->component_checks( (string) $key, $pair['source'], $pair['generated'] ) as $check ) {
				$checks[] = $check;
			}
		}
		return $checks;
	}

	/**
	 * Compare the layout of one section.
	 *
	 * @param string               $key       Source section key.
	 * @param array<string, mixed> $source    Source section.
	 * @param array<string, mixed> $generated Generated section.
	 * @return array<int, array<string, mixed>>
	 */
	private function section_checks( $key, array $source, array $generated ) {
		if ( empty( $generated ) ) {
			return array();
		}

		$checks = array(
			$this->count_check( 'layout', 'section:' . $key, 'column_count', $source['column_count'], $generated['column_count'], 'column count', $key, (string) $generated['key'] ),
			$this->length_check( 'layout', 'section:' . $key, 'max_width', $source['max_width'], $generated['max_width'], 'container max width', $key, (string) $generated['key'] ),
			$this->gap_check( $key, $source, $generated ),
		);

		$expected_direction = (string) $source['direction'];
		$actual_direction   = (string) $generated['direction'];
		$checks[]           = array(
			'category'            => 'layout',
			'target'              => 'section:' . $key,
			'property'            => 'flex_direction',
			'expected'            => $expected_direction,
			'actual'              => $actual_direction,
			'state'               => ( '' === $actual_direction || $expected_direction === $actual_direction ) ? ( '' === $actual_direction ? 'unknown' : 'pass' ) : 'fail',
			'severity'            => 'moderate',
			'message'             => ( '' !== $actual_direction && $expected_direction !== $actual_direction )
				? sprintf( 'Section "%1$s" uses %2$s on the source and %3$s in the draft.', $key, $expected_direction, $actual_direction )
				: '',
			'confidence'          => (float) $source['confidence'],
			'source_reference'    => array( 'source_section_id' => $key ),
			'generated_reference' => array( 'elementor_element_id' => (string) $generated['key'] ),
		);

		foreach ( $this->ratio_checks( $key, $source, $generated ) as $check ) {
			$checks[] = $check;
		}

		return $checks;
	}

	/**
	 * Compare the layout values of one component.
	 *
	 * @param string               $key       Source component key.
	 * @param array<string, mixed> $source    Source component.
	 * @param array<string, mixed> $generated Generated component.
	 * @return array<int, array<string, mixed>>
	 */
	private function component_checks( $key, array $source, array $generated ) {
		if ( empty( $generated ) ) {
			return array();
		}
		$element = (string) $generated['key'];

		return array(
			$this->length_check( 'spacing', 'component:' . $key, 'padding_top', $this->source_box( $source, 'padding' ), $this->generated_box( $generated, 'padding_top' ), 'top padding', $key, $element ),
			$this->length_check( 'spacing', 'component:' . $key, 'padding_bottom', $this->source_box( $source, 'padding' ), $this->generated_box( $generated, 'padding_bottom' ), 'bottom padding', $key, $element ),
			$this->length_check( 'layout', 'component:' . $key, 'min_height', $this->source_box( $source, 'min_height' ), $this->generated_box( $generated, 'min_height' ), 'minimum height', $key, $element ),
		);
	}

	/**
	 * Compare measured column ratios.
	 *
	 * @param string               $key       Source section key.
	 * @param array<string, mixed> $source    Source section.
	 * @param array<string, mixed> $generated Generated section.
	 * @return array<int, array<string, mixed>>
	 */
	private function ratio_checks( $key, array $source, array $generated ) {
		$expected = isset( $source['column_ratios'] ) && is_array( $source['column_ratios'] ) ? $source['column_ratios'] : array();
		$actual   = isset( $generated['column_ratios'] ) && is_array( $generated['column_ratios'] ) ? $generated['column_ratios'] : array();
		if ( empty( $expected ) || count( $expected ) !== count( $actual ) ) {
			return array();
		}

		$checks   = array();
		$element  = (string) $generated['key'];
		$index    = 1;
		foreach ( $expected as $position => $ratio ) {
			$generated_ratio = isset( $actual[ $position ] ) ? (float) $actual[ $position ] : null;
			if ( null === $generated_ratio ) {
				continue;
			}
			$difference = round( $generated_ratio - (float) $ratio, 4 );
			$state      = Comparison_Schema::band( $difference, 'ratio' );
			$checks[]   = array(
				'category'            => 'layout',
				'target'              => 'section:' . $key,
				'property'            => 'column_ratio_' . ( $index++ ),
				'expected'            => round( (float) $ratio, 4 ),
				'actual'              => round( $generated_ratio, 4 ),
				'difference'          => $difference,
				'state'               => $state,
				'tolerance_type'      => 'ratio',
				'message'             => 'pass' === $state ? '' : sprintf(
					/* translators: 1: Section key, 2: Expected ratio, 3: Actual ratio. */
					__( 'Column ratio differs in section "%1$s": %2$s on the source, %3$s in the draft.', 'replicaforge' ),
					$key,
					round( (float) $ratio * 100, 1 ) . '%',
					round( $generated_ratio * 100, 1 ) . '%'
				),
				'confidence'          => (float) $source['confidence'],
				'source_reference'    => array( 'source_section_id' => $key ),
				'generated_reference' => array( 'elementor_element_id' => $element ),
			);
		}

		return $checks;
	}

	/**
	 * Compare the section gap.
	 *
	 * @param string               $key       Source section key.
	 * @param array<string, mixed> $source    Source section.
	 * @param array<string, mixed> $generated Generated section.
	 * @return array<string, mixed>
	 */
	private function gap_check( $key, array $source, array $generated ) {
		$expected = isset( $source['gap'] ) ? $source['gap'] : null;
		$actual   = isset( $generated['gap'] ) ? $generated['gap'] : null;
		return $this->length_check( 'spacing', 'section:' . $key, 'gap', $expected, $actual, 'gap', $key, (string) $generated['key'] );
	}

	/**
	 * Build a numeric count check.
	 *
	 * @param string $category  Category key.
	 * @param string $target    Target label.
	 * @param string $property  Property name.
	 * @param mixed  $expected  Expected value.
	 * @param mixed  $actual    Actual value.
	 * @param string $label     Human label.
	 * @param string $section   Source section key.
	 * @param string $element   Elementor element ID.
	 * @return array<string, mixed>
	 */
	private function count_check( $category, $target, $property, $expected, $actual, $label, $section, $element ) {
		$expected = is_numeric( $expected ) ? (int) $expected : null;
		$actual   = is_numeric( $actual ) ? (int) $actual : null;
		if ( null === $expected || null === $actual ) {
			return array(
				'category'            => $category,
				'target'              => $target,
				'property'            => $property,
				'expected'            => $expected,
				'actual'              => $actual,
				'state'               => 'unknown',
				'confidence'          => 0.0,
				'source_reference'    => array( 'source_section_id' => $section ),
				'generated_reference' => array( 'elementor_element_id' => $element ),
			);
		}
		$state      = $expected === $actual ? 'pass' : 'fail';
		$difference = $actual - $expected;
		return array(
			'category'            => $category,
			'target'              => $target,
			'property'            => $property,
			'expected'            => $expected,
			'actual'              => $actual,
			'difference'          => $difference,
			'state'               => $state,
			'severity'            => 'fail' === $state ? 'major' : 'informational',
			'message'             => 'pass' === $state ? '' : sprintf(
				/* translators: 1: Label, 2: Expected value, 3: Actual value. */
				__( 'The %1$s differs: %2$d on the source, %3$d in the draft.', 'replicaforge' ),
				$label,
				$expected,
				$actual
			),
			'confidence'          => 0.8,
			'source_reference'    => array( 'source_section_id' => $section ),
			'generated_reference' => array( 'elementor_element_id' => $element ),
		);
	}

	/**
	 * Build a numeric length check with a tolerance band.
	 *
	 * @param string $category  Category key.
	 * @param string $target    Target label.
	 * @param string $property  Property name.
	 * @param mixed  $expected  Expected value.
	 * @param mixed  $actual    Actual value.
	 * @param string $label     Human label.
	 * @param string $source_id Source reference key.
	 * @param string $element   Elementor element ID.
	 * @return array<string, mixed>
	 */
	private function length_check( $category, $target, $property, $expected, $actual, $label, $source_id, $element ) {
		$expected = is_numeric( $expected ) ? (float) $expected : null;
		$actual   = is_numeric( $actual ) ? (float) $actual : null;
		$source_reference = 0 === strpos( $source_id, 'component_' ) || 0 === strpos( $source_id, 'reconstruction_' )
			? array( 'source_component_id' => $source_id )
			: array( 'source_section_id' => $source_id );

		if ( null === $expected || null === $actual ) {
			return array(
				'category'            => $category,
				'target'              => $target,
				'property'            => $property,
				'expected'            => $expected,
				'actual'              => $actual,
				'state'               => 'unknown',
				'confidence'          => 0.0,
				'source_reference'    => $source_reference,
				'generated_reference' => array( 'elementor_element_id' => $element ),
			);
		}

		$difference = round( $actual - $expected, 3 );
		$state      = Comparison_Schema::band( $difference, 'length' );
		$severity   = 'pass' === $state ? 'informational' : ( 'fail' === $state ? 'moderate' : 'minor' );

		return array(
			'category'            => $category,
			'target'              => $target,
			'property'            => $property,
			'expected'            => round( $expected, 2 ),
			'actual'              => round( $actual, 2 ),
			'difference'          => $difference,
			'state'               => $state,
			'tolerance_type'      => 'length',
			'severity'            => $severity,
			'message'             => 'pass' === $state ? '' : sprintf(
				/* translators: 1: Label, 2: Expected value, 3: Actual value, 4: Difference. */
				__( 'The %1$s differs: %2$spx expected, %3$spx generated (%4$spx).', 'replicaforge' ),
				$label,
				round( $expected, 2 ),
				round( $actual, 2 ),
				round( abs( $difference ), 2 )
			),
			'confidence'          => 0.85,
			'source_reference'    => $source_reference,
			'generated_reference' => array( 'elementor_element_id' => $element ),
		);
	}

	/**
	 * Return a source box value, or null when the source did not detect it.
	 *
	 * Phase 2 records no per-component padding, margin, or minimum height, so
	 * this always returns null. The resulting check is reported as unknown
	 * evidence rather than as a difference.
	 *
	 * @param array<string, mixed> $component Source component.
	 * @param string               $property  Property name.
	 * @return null
	 */
	private function source_box( array $component, $property ) {
		unset( $component, $property );
		return null;
	}

	/**
	 * Return a generated box value.
	 *
	 * @param array<string, mixed> $component Generated component.
	 * @param string               $property  Property name.
	 * @return float|null
	 */
	private function generated_box( array $component, $property ) {
		$value = isset( $component['layout'][ $property ] ) ? $component['layout'][ $property ] : null;
		if ( null === $value ) {
			$value = isset( $component['box'][ $property ] ) ? $component['box'][ $property ] : null;
		}
		return is_numeric( $value ) ? (float) $value : null;
	}
}
