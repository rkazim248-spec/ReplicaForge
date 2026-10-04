<?php
/**
 * Machine-readable correction plan for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a correction plan from detected differences.
 *
 * The plan is data only. Phase 5 never applies it, and Phase 5 never writes to
 * the Elementor document. A later phase may consume this plan, and every entry
 * keeps the identifiers required to locate the target element.
 */
final class Correction_Plan {

	/**
	 * Difference categories that can be turned into a correction.
	 *
	 * @var array<int, string>
	 */
	private $categories = array(
		'typography',
		'color',
		'background',
		'layout',
		'spacing',
		'border',
		'shadow',
		'responsive',
		'link',
	);

	/**
	 * Build the plan.
	 *
	 * @param array<int, array<string, mixed>> $differences Difference records.
	 * @return array<string, mixed>
	 */
	public function build( array $differences ) {
		$corrections = array();
		$skipped     = 0;

		foreach ( $differences as $difference ) {
			if ( ! is_array( $difference ) ) {
				continue;
			}
			$category = isset( $difference['category'] ) ? (string) $difference['category'] : '';
			$state    = isset( $difference['state'] ) ? (string) $difference['state'] : '';
			if ( ! in_array( $category, $this->categories, true ) || 'pass' === $state || 'unknown' === $state ) {
				$skipped++;
				continue;
			}
			$expected = isset( $difference['expected'] ) ? $difference['expected'] : null;
			$actual   = isset( $difference['actual'] ) ? $difference['actual'] : null;
			if ( null === $expected || null === $actual ) {
				$skipped++;
				continue;
			}
			if ( count( $corrections ) >= Validation_Limits::MAX_CORRECTIONS ) {
				$skipped++;
				continue;
			}

			$corrections[] = array(
				'target'     => isset( $difference['target'] ) ? (string) $difference['target'] : '',
				'category'   => $category,
				'property'   => isset( $difference['property'] ) ? (string) $difference['property'] : '',
				'viewport'   => isset( $difference['viewport'] ) ? (string) $difference['viewport'] : 'desktop',
				'from'       => $actual,
				'to'         => $expected,
				'reason'     => isset( $difference['message'] ) && '' !== $difference['message']
					? (string) $difference['message']
					: __( 'Deterministic source comparison detected a difference.', 'replicaforge' ),
				'severity'   => isset( $difference['severity'] ) ? (string) $difference['severity'] : 'moderate',
				'confidence' => isset( $difference['confidence'] ) ? (float) $difference['confidence'] : 0.0,
				'difference_id' => isset( $difference['id'] ) ? (string) $difference['id'] : '',
				'source_reference'    => isset( $difference['source_reference'] ) && is_array( $difference['source_reference'] ) ? $difference['source_reference'] : array(),
				'generated_reference' => isset( $difference['generated_reference'] ) && is_array( $difference['generated_reference'] ) ? $difference['generated_reference'] : array(),
			);
		}

		return array(
			'schema_version' => Validation_Limits::SCHEMA_VERSION,
			'generated_at'   => gmdate( 'c' ),
			'applied'        => false,
			'applied_at'     => null,
			'note'           => __( 'This plan is data only. ReplicaForge does not apply it, and no Elementor page is modified by validation.', 'replicaforge' ),
			'corrections'    => $corrections,
			'correction_count' => count( $corrections ),
			'skipped_count'  => $skipped,
		);
	}
}
