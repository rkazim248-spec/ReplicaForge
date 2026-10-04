<?php
/**
 * Asset and image comparison for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares image and asset presence, identity, and dimensions.
 *
 * An asset that the source never exposed, or one the Phase 4 generator refused
 * to copy, is reported as an availability notice. It is never counted as a
 * design-generation failure.
 */
final class Asset_Comparator {

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

		foreach ( $this->image_checks( $context ) as $check ) {
			$checks[] = $check;
		}
		foreach ( $this->count_checks( $source, $generated ) as $check ) {
			$checks[] = $check;
		}
		foreach ( $this->dimension_checks( $context ) as $check ) {
			$checks[] = $check;
		}
		foreach ( $this->availability_notices( $generated ) as $check ) {
			$checks[] = $check;
		}

		return $checks;
	}

	/**
	 * Compare image presence for every paired component.
	 *
	 * @param Comparison_Context $context Comparison context.
	 * @return array<int, array<string, mixed>>
	 */
	private function image_checks( Comparison_Context $context ) {
		$checks = array();
		foreach ( $context->pairs() as $key => $pair ) {
			$source_image = isset( $pair['source']['image'] ) && is_array( $pair['source']['image'] ) ? $pair['source']['image'] : array();
			if ( empty( $source_image['present'] ) ) {
				continue;
			}
			$element        = ! empty( $pair['generated'] ) ? (string) $pair['generated']['key'] : '';
			$generated_image = ! empty( $pair['generated'] ) && isset( $pair['generated']['image'] ) && is_array( $pair['generated']['image'] ) ? $pair['generated']['image'] : array();
			$present         = ! empty( $generated_image['present'] );

			$checks[] = array(
				'category'            => 'image',
				'target'              => 'component:' . $key,
				'property'            => 'image_present',
				'expected'            => $source_image['src'],
				'actual'              => $present && isset( $generated_image['src'] ) ? $generated_image['src'] : null,
				'state'               => $present ? 'pass' : 'missing',
				'severity'            => $present ? 'informational' : 'moderate',
				'message'             => $present ? '' : sprintf(
					/* translators: %s: Source component key. */
					__( 'The image detected for "%s" is not present in the draft.', 'replicaforge' ),
					$key
				),
				'confidence'          => (float) $pair['confidence'],
				'source_reference'    => array( 'source_component_id' => $key ),
				'generated_reference' => array( 'elementor_element_id' => $element ),
			);
		}
		return $checks;
	}

	/**
	 * Compare image counts.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return array<int, array<string, mixed>>
	 */
	private function count_checks( array $source, array $generated ) {
		$expected = isset( $source['counters']['images'] ) ? (int) $source['counters']['images'] : 0;
		$actual   = isset( $generated['counters']['images'] ) ? (int) $generated['counters']['images'] : 0;

		return array(
			array(
				'category'            => 'asset',
				'target'              => 'document',
				'property'            => 'image_count',
				'expected'            => $expected,
				'actual'              => $actual,
				'difference'          => $actual - $expected,
				'state'               => $expected === $actual ? 'pass' : ( $actual < $expected ? 'missing' : 'extra' ),
				'severity'            => $expected === $actual ? 'informational' : 'moderate',
				'message'             => $expected === $actual ? '' : sprintf(
					/* translators: 1: Source image count, 2: Generated image count. */
					__( 'Image count differs: %1$d detected on the source, %2$d in the draft.', 'replicaforge' ),
					$expected,
					$actual
				),
				'confidence'          => 0.8,
				'source_reference'    => array(),
				'generated_reference' => array(),
			),
		);
	}

	/**
	 * Compare recorded image dimensions and aspect ratio.
	 *
	 * @param Comparison_Context $context Comparison context.
	 * @return array<int, array<string, mixed>>
	 */
	private function dimension_checks( Comparison_Context $context ) {
		$checks = array();
		foreach ( $context->pairs() as $key => $pair ) {
			$source_image = isset( $pair['source']['image'] ) && is_array( $pair['source']['image'] ) ? $pair['source']['image'] : array();
			$generated    = ! empty( $pair['generated'] ) && isset( $pair['generated']['image'] ) && is_array( $pair['generated']['image'] ) ? $pair['generated']['image'] : array();
			$element      = ! empty( $pair['generated'] ) ? (string) $pair['generated']['key'] : '';

			$expected_width  = isset( $source_image['width'] ) && is_numeric( $source_image['width'] ) ? (float) $source_image['width'] : null;
			$expected_height = isset( $source_image['height'] ) && is_numeric( $source_image['height'] ) ? (float) $source_image['height'] : null;
			$actual_width    = isset( $generated['width'] ) && is_numeric( $generated['width'] ) ? (float) $generated['width'] : null;
			$actual_height   = isset( $generated['height'] ) && is_numeric( $generated['height'] ) ? (float) $generated['height'] : null;

			if ( null === $expected_width || null === $expected_height || null === $actual_width || null === $actual_height ) {
				$checks[] = array(
					'category'            => 'image',
					'target'              => 'component:' . $key,
					'property'            => 'aspect_ratio',
					'expected'            => $this->ratio( $expected_width, $expected_height ),
					'actual'              => $this->ratio( $actual_width, $actual_height ),
					'state'               => 'unknown',
					'confidence'          => 0.0,
					'source_reference'    => array( 'source_component_id' => $key ),
					'generated_reference' => array( 'elementor_element_id' => $element ),
				);
				continue;
			}

			$expected_ratio = round( $expected_width / max( 1.0, $expected_height ), 4 );
			$actual_ratio   = round( $actual_width / max( 1.0, $actual_height ), 4 );
			$difference     = round( $actual_ratio - $expected_ratio, 4 );
			$state          = Comparison_Schema::band( $difference, 'ratio' );
			$checks[]       = array(
				'category'            => 'image',
				'target'              => 'component:' . $key,
				'property'            => 'aspect_ratio',
				'expected'            => $expected_ratio,
				'actual'              => $actual_ratio,
				'difference'          => $difference,
				'state'               => $state,
				'tolerance_type'      => 'ratio',
				'severity'            => 'pass' === $state ? 'informational' : 'minor',
				'message'             => 'pass' === $state ? '' : sprintf(
					/* translators: 1: Source dimensions, 2: Generated dimensions. */
					__( 'Image aspect ratio differs: %1$s on the source, %2$s in the draft.', 'replicaforge' ),
					round( $expected_width ) . 'x' . round( $expected_height ),
					round( $actual_width ) . 'x' . round( $actual_height )
				),
				'confidence'          => 0.7,
				'source_reference'    => array( 'source_component_id' => $key ),
				'generated_reference' => array( 'elementor_element_id' => $element ),
			);
		}
		return $checks;
	}

	/**
	 * Report asset availability recorded during generation.
	 *
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return array<int, array<string, mixed>>
	 */
	private function availability_notices( array $generated ) {
		$provenance = isset( $generated['metadata']['provenance'] ) && is_array( $generated['metadata']['provenance'] ) ? $generated['metadata']['provenance'] : array();
		$checks     = array();
		$labels     = array(
			'blocked'     => __( 'One or more source images were blocked because their type could not be verified as safe media.', 'replicaforge' ),
			'failed'      => __( 'One or more source images could not be copied and remain referenced from the source website.', 'replicaforge' ),
			'unavailable' => __( 'One or more source images had no safe source URL in the analysis.', 'replicaforge' ),
		);

		foreach ( $labels as $status => $message ) {
			$count = isset( $provenance[ $status ] ) ? (int) $provenance[ $status ] : 0;
			if ( $count < 1 ) {
				continue;
			}
			$checks[] = array(
				'category'            => 'asset',
				'target'              => 'document',
				'property'            => 'asset_unavailable:' . $status,
				'expected'            => null,
				'actual'              => $count,
				'state'               => 'fail',
				'severity'            => 'informational',
				'message'             => sprintf( '%s (%d)', $message, $count ),
				'confidence'          => 1.0,
				'source_reference'    => array(),
				'generated_reference' => array(),
			);
		}

		return $checks;
	}

	/**
	 * Return a comparable aspect ratio.
	 *
	 * @param float|null $width  Width.
	 * @param float|null $height Height.
	 * @return float|null
	 */
	private function ratio( $width, $height ) {
		if ( null === $width || null === $height || $height <= 0 ) {
			return null;
		}
		return round( (float) $width / (float) $height, 4 );
	}
}
