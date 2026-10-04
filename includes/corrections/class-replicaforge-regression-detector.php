<?php
/**
 * Regression detection for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares a validation result before and after a correction batch.
 *
 * A correction that improves one measurement can damage another. This class
 * compares the overall measurement, every per-viewport measurement, and every
 * metric group, and reports a regression wherever a measurement fell further from
 * the source than it was before. It never hides a regression behind an overall
 * gain.
 */
final class Regression_Detector {

	/**
	 * Compare a validation result before and after a correction.
	 *
	 * @param array<string, mixed> $before Validation before the correction.
	 * @param array<string, mixed> $after  Validation after the correction.
	 * @return array<string, mixed>
	 */
	public function compare( array $before, array $after ) {
		$overall = $this->delta(
			$this->overall( $before ),
			$this->overall( $after ),
			Correction_Limits::REGRESSION_THRESHOLDS['overall'],
			'overall'
		);

		$viewports = array();
		foreach ( array_keys( Validation_Limits::VIEWPORTS ) as $device ) {
			$delta = $this->delta(
				$this->viewport( $before, $device ),
				$this->viewport( $after, $device ),
				Correction_Limits::REGRESSION_THRESHOLDS['viewport'],
				'viewport:' . $device
			);
			if ( null !== $delta ) {
				$viewports[ $device ] = $delta;
			}
		}

		$groups = array();
		$before_groups = $this->groups( $before );
		$after_groups  = $this->groups( $after );
		foreach ( $after_groups as $group => $value ) {
			if ( ! array_key_exists( $group, $before_groups ) ) {
				continue;
			}
			$delta = $this->delta(
				$before_groups[ $group ],
				$value,
				Correction_Limits::REGRESSION_THRESHOLDS['group'],
				'group:' . $group
			);
			if ( null !== $delta ) {
				$groups[ $group ] = $delta;
			}
		}

		$regressions = array();
		if ( null !== $overall ) {
			$regressions[] = $overall;
		}
		foreach ( $viewports as $regression ) {
			$regressions[] = $regression;
		}
		foreach ( $groups as $regression ) {
			$regressions[] = $regression;
		}

		$improvement = null;
		if ( null !== $this->overall( $before ) && null !== $this->overall( $after ) ) {
			$improvement = round( $this->overall( $after ) - $this->overall( $before ), 2 );
		}

		return array(
			'available'         => null !== $improvement,
			'before'            => $this->overall( $before ),
			'after'             => $this->overall( $after ),
			'improvement'       => $improvement,
			'regressions'       => $regressions,
			'regression_count'  => count( $regressions ),
			'has_regression'    => ! empty( $regressions ),
			'viewports'         => $viewports,
			'groups'            => $groups,
			'thresholds'        => Correction_Limits::REGRESSION_THRESHOLDS,
			'interpretation'    => __( 'These measurements are the internal validation metrics described in the validation report. An improvement is a measured change against the source analysis, not a guarantee of visual quality.', 'replicaforge' ),
		);
	}

	/**
	 * Build one comparison record.
	 *
	 * @param float|null $before    Value before.
	 * @param float|null $after     Value after.
	 * @param float      $threshold Maximum tolerated drop.
	 * @param string     $scope     Comparison scope.
	 * @return array<string, mixed>|null
	 */
	private function delta( $before, $after, $threshold, $scope ) {
		if ( null === $before || null === $after ) {
			return null;
		}
		$change = round( (float) $after - (float) $before, 2 );
		if ( $change >= -$threshold ) {
			return null;
		}
		return array(
			'scope'    => $scope,
			'before'   => round( (float) $before, 2 ),
			'after'    => round( (float) $after, 2 ),
			'change'   => $change,
			'severity' => $change <= -$threshold * 2 ? 'major' : 'moderate',
		);
	}

	/**
	 * Return the overall measurement of a validation result.
	 *
	 * @param array<string, mixed> $validation Validation result.
	 * @return float|null
	 */
	private function overall( array $validation ) {
		$value = isset( $validation['metrics']['overall']['value'] ) ? $validation['metrics']['overall']['value'] : null;
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Return the measurement of one viewport.
	 *
	 * @param array<string, mixed> $validation Validation result.
	 * @param string               $device     Device key.
	 * @return float|null
	 */
	private function viewport( array $validation, $device ) {
		$value = isset( $validation['viewports'][ $device ]['score'] ) ? $validation['viewports'][ $device ]['score'] : null;
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Return the group measurements of a validation result.
	 *
	 * @param array<string, mixed> $validation Validation result.
	 * @return array<string, float>
	 */
	private function groups( array $validation ) {
		$groups = isset( $validation['metrics']['groups'] ) && is_array( $validation['metrics']['groups'] ) ? $validation['metrics']['groups'] : array();
		$result = array();
		foreach ( $groups as $group => $record ) {
			if ( is_array( $record ) && isset( $record['value'] ) && is_numeric( $record['value'] ) ) {
				$result[ (string) $group ] = (float) $record['value'];
			}
		}
		return $result;
	}
}
