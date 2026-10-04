<?php
/**
 * Validation metrics for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the deterministic validation measurements.
 *
 * The formula is fixed and published with every result so a number can be
 * explained and reproduced:
 *
 *     category = round( 100 * (passed + 0.5 * partial) / (passed + partial + failed), 1 )
 *
 * A check inside the declared pass band counts as `passed`, a check inside the
 * partial band counts as `half`, and a check beyond it counts as zero. Checks
 * without comparable evidence on both sides are excluded from the denominator
 * and reported separately as `not_comparable`. The overall measurement is the
 * weighted mean of the categories that had at least one comparable check, using
 * the category weights in Validation_Limits. It is an internal validation
 * measurement, not an accuracy claim.
 */
final class Validation_Metrics {

	/**
	 * Compute metrics from the collected checks.
	 *
	 * @param array<int, array<string, mixed>> $checks Check records.
	 * @return array<string, mixed>
	 */
	public function compute( array $checks ) {
		$categories = array();
		foreach ( Validation_Limits::CATEGORIES as $category ) {
			$categories[ $category ] = array(
				'passed'         => 0.0,
				'partial'        => 0.0,
				'failed'         => 0.0,
				'not_comparable' => 0.0,
				'weight'         => 0.0,
			);
		}

		foreach ( $checks as $check ) {
			$category = isset( $check['category'] ) && isset( $categories[ $check['category'] ] ) ? (string) $check['category'] : 'structure';
			$state    = isset( $check['state'] ) ? (string) $check['state'] : 'unknown';
			$weight   = $this->weight_for( $check );
			$bucket   = $this->bucket_for( $state );

			$categories[ $category ][ $bucket ] += $weight;
			$categories[ $category ]['weight'] += $weight;
		}

		$category_scores = array();
		foreach ( $categories as $category => $totals ) {
			$denominator = $totals['passed'] + $totals['partial'] + $totals['failed'];
			$category_scores[ $category ] = array(
				'value'          => $denominator > 0
					? round( 100 * ( $totals['passed'] + ( 0.5 * $totals['partial'] ) ) / $denominator, 1 )
					: null,
				'passed'         => (int) round( $totals['passed'] ),
				'partial'        => (int) round( $totals['partial'] ),
				'failed'         => (int) round( $totals['failed'] ),
				'not_comparable' => (int) round( $totals['not_comparable'] ),
				'comparable'     => (int) round( $denominator ),
				'weight'         => (float) $totals['weight'],
			);
		}

		$groups = array();
		foreach ( Validation_Limits::METRIC_GROUPS as $group => $members ) {
			$groups[ $group ] = $this->aggregate( $members, $category_scores );
		}

		$viewports = $this->viewports( $checks );
		$overall   = $this->overall( $category_scores, $groups );

		return array(
			'categories'     => $category_scores,
			'groups'         => $groups,
			'viewports'      => $viewports,
			'overall'        => $overall,
			'formula'        => 'category = round(100 * (passed + 0.5 * partial) / (passed + partial + failed), 1); viewport = same formula over the checks recorded for that device, with desktop-scoped checks included; overall = weighted mean of available group scores',
			'tolerances'     => Validation_Limits::TOLERANCES,
			'category_weights' => Validation_Limits::CATEGORY_WEIGHTS,
			'not_comparable_total' => array_sum( array_column( $category_scores, 'not_comparable' ) ),
		);
	}

	/**
	 * Compute the per-device measurement.
	 *
	 * A check recorded for a device is compared only against checks recorded for
	 * that device, with the desktop-scoped checks included, because a desktop
	 * difference is also present at every device unless the draft overrides it.
	 *
	 * @param array<int, array<string, mixed>> $checks Check records.
	 * @return array<string, mixed>
	 */
	private function viewports( array $checks ) {
		$viewports = array();
		foreach ( array_keys( Validation_Limits::VIEWPORTS ) as $device ) {
			$selected = array();
			foreach ( $checks as $check ) {
				$viewport = isset( $check['viewport'] ) ? (string) $check['viewport'] : 'desktop';
				if ( 'desktop' === $viewport || $viewport === $device ) {
					$selected[] = $check;
				}
			}

			$passed  = 0.0;
			$partial = 0.0;
			$failed  = 0.0;
			$skipped = 0.0;
			foreach ( $selected as $check ) {
				$weight = $this->weight_for( $check );
				switch ( $this->bucket_for( isset( $check['state'] ) ? $check['state'] : 'unknown' ) ) {
					case 'passed':
						$passed += $weight;
						break;
					case 'partial':
						$partial += $weight;
						break;
					case 'not_comparable':
						$skipped += $weight;
						break;
					default:
						$failed += $weight;
						break;
				}
			}

			$denominator   = $passed + $partial + $failed;
			$viewports[ $device ] = array(
				'value'          => $denominator > 0 ? round( 100 * ( $passed + ( 0.5 * $partial ) ) / $denominator, 1 ) : null,
				'passed'         => (int) round( $passed ),
				'partial'        => (int) round( $partial ),
				'failed'         => (int) round( $failed ),
				'not_comparable' => (int) round( $skipped ),
				'comparable'     => (int) round( $denominator ),
			);
		}
		return $viewports;
	}

	/**
	 * Return the weight of one check.
	 *
	 * @param array<string, mixed> $check Check record.
	 * @return float
	 */
	private function weight_for( array $check ) {
		$type = isset( $check['tolerance_type'] ) && is_string( $check['tolerance_type'] ) ? (string) $check['tolerance_type'] : 'length';
		$tolerance = Comparison_Schema::tolerance( $type );
		return isset( $tolerance['weight'] ) ? (float) $tolerance['weight'] : 1.0;
	}

	/**
	 * Map a check state onto a metric bucket.
	 *
	 * @param string $state Check state.
	 * @return string
	 */
	private function bucket_for( $state ) {
		if ( 'pass' === $state ) {
			return 'passed';
		}
		if ( 'partial' === $state ) {
			return 'partial';
		}
		if ( 'unknown' === $state ) {
			return 'not_comparable';
		}
		return 'failed';
	}

	/**
	 * Aggregate a metric group.
	 *
	 * @param array<int, string>            $members Member categories.
	 * @param array<string, array>          $scores  Category scores.
	 * @return array<string, mixed>
	 */
	private function aggregate( array $members, array $scores ) {
		$passed  = 0.0;
		$partial = 0.0;
		$failed  = 0.0;
		$skipped = 0.0;

		foreach ( $members as $member ) {
			if ( ! isset( $scores[ $member ] ) ) {
				continue;
			}
			$passed  += (float) $scores[ $member ]['passed'];
			$partial += (float) $scores[ $member ]['partial'];
			$failed  += (float) $scores[ $member ]['failed'];
			$skipped += (float) $scores[ $member ]['not_comparable'];
		}

		$denominator = $passed + $partial + $failed;
		return array(
			'value'          => $denominator > 0 ? round( 100 * ( $passed + ( 0.5 * $partial ) ) / $denominator, 1 ) : null,
			'comparable'     => (int) round( $denominator ),
			'not_comparable' => (int) round( $skipped ),
			'categories'     => $members,
		);
	}

	/**
	 * Compute the overall measurement.
	 *
	 * @param array<string, array> $category_scores Category scores.
	 * @param array<string, array> $groups         Group scores.
	 * @return array<string, mixed>
	 */
	private function overall( array $category_scores, array $groups ) {
		$total  = 0.0;
		$weight = 0.0;
		$parts  = array();

		foreach ( $groups as $group => $score ) {
			if ( null === $score['value'] || 'detail' === $group ) {
				continue;
			}
			$group_weight = 0.0;
			foreach ( $score['categories'] as $category ) {
				$group_weight += isset( Validation_Limits::CATEGORY_WEIGHTS[ $category ] )
					? (float) Validation_Limits::CATEGORY_WEIGHTS[ $category ]
					: 1.0;
			}
			$total  += (float) $score['value'] * $group_weight;
			$weight += $group_weight;
			$parts[ $group ] = array(
				'value' => $score['value'],
				'weight' => round( $group_weight, 2 ),
			);
		}

		unset( $category_scores );

		return array(
			'value' => $weight > 0 ? round( $total / $weight, 1 ) : null,
			'parts' => $parts,
			'note'  => __( 'Internal validation measurement based on the declared comparison metrics. It is not an accuracy guarantee and no visual accuracy is claimed.', 'replicaforge' ),
		);
	}
}
