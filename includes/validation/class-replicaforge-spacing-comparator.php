<?php
/**
 * Spacing comparison for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the detected spacing scale and the section rhythm.
 *
 * Spacing is compared with the declared tolerance bands rather than requiring
 * exact pixel equality. A two pixel difference is a minor difference; anything
 * beyond the medium band is reported as moderate.
 */
final class Spacing_Comparator {

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

		foreach ( $this->scale_checks( $source, $generated ) as $check ) {
			$checks[] = $check;
		}
		foreach ( $context->section_pairs() as $key => $pair ) {
			$expected = isset( $pair['source']['gap'] ) ? $pair['source']['gap'] : null;
			$actual   = ! empty( $pair['generated'] ) && isset( $pair['generated']['gap'] ) ? $pair['generated']['gap'] : null;
			if ( null === $expected || null === $actual ) {
				continue;
			}
			$difference = round( (float) $actual - (float) $expected, 2 );
			$state      = Comparison_Schema::band( $difference, 'length' );
			$checks[]   = array(
				'category'            => 'spacing',
				'target'              => 'section:' . (string) $key,
				'property'            => 'section_gap',
				'expected'            => round( (float) $expected, 2 ),
				'actual'              => round( (float) $actual, 2 ),
				'difference'          => $difference,
				'state'               => $state,
				'tolerance_type'      => 'length',
				'severity'            => 'pass' === $state ? 'informational' : ( 'fail' === $state ? 'moderate' : 'minor' ),
				'message'             => 'pass' === $state ? '' : sprintf(
					/* translators: 1: Section key, 2: Expected gap, 3: Actual gap, 4: Difference. */
					__( 'Spacing differs in section "%1$s": %2$spx expected, %3$spx generated (%4$spx).', 'replicaforge' ),
					(string) $key,
					round( (float) $expected, 2 ),
					round( (float) $actual, 2 ),
					round( abs( $difference ), 2 )
				),
				'confidence'          => (float) $pair['source']['confidence'],
				'source_reference'    => array( 'source_section_id' => (string) $key ),
				'generated_reference' => array( 'elementor_element_id' => (string) $pair['generated']['key'] ),
			);
		}

		return $checks;
	}

	/**
	 * Compare the detected spacing scale as a set.
	 *
	 * Each detected source spacing value is looked up in the generated scale.
	 * A value counts as matched when the nearest generated value is inside the
	 * pass band and as partial when it is inside the medium band.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return array<int, array<string, mixed>>
	 */
	private function scale_checks( array $source, array $generated ) {
		$expected = isset( $source['design_system']['spacing'] ) && is_array( $source['design_system']['spacing'] ) ? $source['design_system']['spacing'] : array();
		$actual   = isset( $generated['design_system']['spacing'] ) && is_array( $generated['design_system']['spacing'] ) ? $generated['design_system']['spacing'] : array();
		if ( empty( $expected ) ) {
			return array();
		}

		$checks    = array();
		$index     = 0;
		$available = array();
		foreach ( $actual as $value ) {
			if ( is_numeric( $value ) ) {
				$available[] = (float) $value;
			}
		}

		foreach ( $expected as $value ) {
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$index++;
			$value      = (float) $value;
			$nearest    = $this->nearest( $value, $available );
			$difference = null === $nearest ? null : round( $nearest - $value, 2 );
			$state      = Comparison_Schema::band( $difference, 'length' );

			$checks[] = array(
				'category'            => 'spacing',
				'target'              => 'design_system',
				'property'            => 'spacing_scale:' . $index,
				'expected'            => round( $value, 2 ),
				'actual'              => null === $nearest ? null : round( $nearest, 2 ),
				'difference'          => $difference,
				'state'               => $state,
				'tolerance_type'      => 'length',
				'severity'            => 'pass' === $state ? 'informational' : 'minor',
				'message'             => 'pass' === $state ? '' : sprintf(
					/* translators: 1: Expected spacing, 2: Nearest generated spacing. */
					__( 'Spacing scale differs: %1$spx detected on the source, nearest generated value is %2$spx.', 'replicaforge' ),
					round( $value, 2 ),
					null === $nearest ? 0 : round( $nearest, 2 )
				),
				'confidence'          => 0.6,
				'source_reference'    => array(),
				'generated_reference' => array(),
			);
		}

		return $checks;
	}

	/**
	 * Return the closest value in a list.
	 *
	 * @param float            $value Value to look up.
	 * @param array<int, float> $list  Candidate values.
	 * @return float|null
	 */
	private function nearest( $value, array $list ) {
		if ( empty( $list ) ) {
			return null;
		}
		$best     = null;
		$distance = null;
		foreach ( $list as $candidate ) {
			$current = abs( $candidate - $value );
			if ( null === $distance || $current < $distance ) {
				$distance = $current;
				$best     = $candidate;
			}
		}
		return $best;
	}
}
