<?php
/**
 * Responsive comparison for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares behaviour per device and detects responsive regressions.
 *
 * The source side is static evidence. When the source analysis contains no
 * responsive evidence for a device, that device is reported as unknown and is
 * excluded from the metric denominator. ReplicaForge never reports a responsive
 * difference it cannot support with evidence from one side, and never claims the
 * generated responsive behaviour is correct without a rendered comparison.
 */
final class Responsive_Comparator {

	/**
	 * Run the comparison.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return array<int, array<string, mixed>>
	 */
	public function compare( array $source, array $generated ) {
		$checks = array();

		foreach ( array_keys( Validation_Limits::VIEWPORTS ) as $device ) {
			foreach ( $this->device_checks( $device, $source, $generated ) as $check ) {
				$checks[] = $check;
			}
		}
		foreach ( $this->regression_checks( $source, $generated ) as $check ) {
			$checks[] = $check;
		}
		foreach ( $this->navigation_checks( $source, $generated ) as $check ) {
			$checks[] = $check;
		}

		return $checks;
	}

	/**
	 * Compare one device.
	 *
	 * @param string               $device    Device key.
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return array<int, array<string, mixed>>
	 */
	private function device_checks( $device, array $source, array $generated ) {
		$source_view    = isset( $source['viewports'][ $device ] ) && is_array( $source['viewports'][ $device ] ) ? $source['viewports'][ $device ] : array();
		$generated_view = isset( $generated['viewports'][ $device ] ) && is_array( $generated['viewports'][ $device ] ) ? $generated['viewports'][ $device ] : array();

		$source_evidence    = ! empty( $source_view['evidence'] );
		$generated_evidence = ! empty( $generated_view['evidence'] ) || ( isset( $generated_view['overrides'] ) && (int) $generated_view['overrides'] > 0 );
		$checks             = array();

		$checks[] = array(
			'category'            => 'responsive',
			'target'              => 'document',
			'property'            => $device . '_evidence',
			'expected'            => $source_evidence ? 'responsive_evidence_detected' : 'no_evidence',
			'actual'              => $generated_evidence ? 'device_rules_present' : 'no_device_rules',
			'state'               => 'unknown',
			'viewport'            => $device,
			'confidence'          => 0.0,
			'source_reference'    => array(),
			'generated_reference' => array(),
		);

		$source_changes    = isset( $source_view['layout_changes'] ) && is_array( $source_view['layout_changes'] ) ? $source_view['layout_changes'] : array();
		$generated_changes = $this->generated_changes( $generated_view, $device );
		$has_source_change = false;
		foreach ( $source_changes as $change ) {
			if ( $this->is_layout_change( $change ) ) {
				$has_source_change = true;
				break;
			}
		}

		$checks[] = array(
			'category'            => 'responsive',
			'target'              => 'document',
			'property'            => $device . '_layout_changes',
			'expected'            => $has_source_change ? 'layout_change_detected' : 'no_layout_change_detected',
			'actual'              => $generated_changes ? 'device_rules_present' : 'no_device_rules',
			'state'               => $this->change_state( $has_source_change, $generated_changes ),
			'severity'            => 'moderate',
			'viewport'            => $device,
			'message'             => $this->change_message( $device, $has_source_change, $generated_changes ),
			'confidence'          => $has_source_change ? 0.7 : 0.0,
			'source_reference'    => array(),
			'generated_reference' => array(),
		);

		$checks[] = $this->column_check( $device, $source, $generated, $has_source_change );

		$source_visibility = isset( $source_view['visibility'] ) && is_array( $source_view['visibility'] ) ? $source_view['visibility'] : array();
		$generated_visibility = isset( $generated_view['visibility'] ) && is_array( $generated_view['visibility'] ) ? $generated_view['visibility'] : array();
		if ( ! empty( $source_visibility ) || ! empty( $generated_visibility ) ) {
			$hidden_source    = count( array_filter( $source_visibility ) );
			$hidden_generated = count( array_filter( $generated_visibility, static function ( $value ) {
				return 'none' === $value;
			} ) );
			$state            = $hidden_source === $hidden_generated ? 'pass' : 'fail';
			$checks[]         = array(
				'category'            => 'visibility',
				'target'              => 'document',
				'property'            => $device . '_hidden_elements',
				'expected'            => $hidden_source,
				'actual'              => $hidden_generated,
				'difference'          => $hidden_generated - $hidden_source,
				'state'               => $state,
				'severity'            => 'pass' === $state ? 'informational' : 'minor',
				'viewport'            => $device,
				'message'             => 'pass' === $state ? '' : sprintf(
					/* translators: 1: Device, 2: Source hidden count, 3: Generated hidden count. */
					__( 'Hidden element count differs at %1$s: %2$d on the source, %3$d in the draft.', 'replicaforge' ),
					$device,
					$hidden_source,
					$hidden_generated
				),
				'confidence'          => 0.5,
				'source_reference'    => array(),
				'generated_reference' => array(),
			);
		}

		return $checks;
	}

	/**
	 * Compare the effective column count per device.
	 *
	 * @param string               $device           Device key.
	 * @param array<string, mixed> $source           Normalized source side.
	 * @param array<string, mixed> $generated        Normalized generated side.
	 * @param bool                 $has_source_change Whether the source has layout evidence.
	 * @return array<string, mixed>
	 */
	private function column_check( $device, array $source, array $generated, $has_source_change ) {
		$expected = isset( $source['viewports'][ $device ]['column_count'] ) ? $source['viewports'][ $device ]['column_count'] : null;
		$actual   = isset( $generated['viewports'][ $device ]['column_count'] ) ? $generated['viewports'][ $device ]['column_count'] : null;

		if ( null === $expected || null === $actual || ! $has_source_change ) {
			return array(
				'category'            => 'responsive',
				'target'              => 'document',
				'property'            => $device . '_column_count',
				'expected'            => $expected,
				'actual'              => $actual,
				'state'               => 'unknown',
				'viewport'            => $device,
				'confidence'          => 0.0,
				'source_reference'    => array(),
				'generated_reference' => array(),
			);
		}

		$state = (int) $expected === (int) $actual ? 'pass' : 'fail';
		return array(
			'category'            => 'responsive',
			'target'              => 'document',
			'property'            => $device . '_column_count',
			'expected'            => (int) $expected,
			'actual'              => (int) $actual,
			'difference'          => (int) $actual - (int) $expected,
			'state'               => $state,
			'severity'            => $state === 'pass' ? 'informational' : 'major',
			'viewport'            => $device,
			'message'             => $state === 'pass' ? '' : sprintf(
				/* translators: 1: Device, 2: Source column count, 3: Generated column count. */
				__( 'Breakpoint mismatch at %1$s: the source uses %2$d columns and the draft uses %3$d.', 'replicaforge' ),
				$device,
				(int) $expected,
				(int) $actual
			),
			'confidence'          => 0.75,
			'source_reference'    => array(),
			'generated_reference' => array(),
		);
	}

	/**
	 * Detect a mismatch between the source and generated column progression.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return array<int, array<string, mixed>>
	 */
	private function regression_checks( array $source, array $generated ) {
		$progression = array( 'desktop', 'tablet', 'mobile' );
		$source_line = array();
		$actual_line = array();
		$known       = 0;

		foreach ( $progression as $device ) {
			$expected = isset( $source['viewports'][ $device ]['column_count'] ) ? $source['viewports'][ $device ]['column_count'] : null;
			$actual   = isset( $generated['viewports'][ $device ]['column_count'] ) ? $generated['viewports'][ $device ]['column_count'] : null;
			if ( null === $expected ) {
				continue;
			}
			$known++;
			$source_line[] = (string) (int) $expected;
			$actual_line[] = null === $actual ? '?' : (string) (int) $actual;
		}

		if ( $known < 2 ) {
			return array(
				array(
					'category'            => 'responsive',
					'target'              => 'document',
					'property'            => 'column_progression',
					'expected'            => implode( ' > ', $source_line ),
					'actual'              => implode( ' > ', $actual_line ),
					'state'               => 'unknown',
					'confidence'          => 0.0,
					'source_reference'    => array(),
					'generated_reference' => array(),
				),
			);
		}

		$mismatch = $source_line !== $actual_line;
		return array(
			array(
				'category'            => 'responsive',
				'target'              => 'document',
				'property'            => 'column_progression',
				'expected'            => implode( ' > ', $source_line ),
				'actual'              => implode( ' > ', $actual_line ),
				'state'               => $mismatch ? 'fail' : 'pass',
				'severity'            => $mismatch ? 'major' : 'informational',
				'message'             => $mismatch ? sprintf(
					/* translators: 1: Source progression, 2: Generated progression. */
					__( 'Responsive progression mismatch: the source changes %1$s while the draft changes %2$s.', 'replicaforge' ),
					implode( ' > ', $source_line ),
					implode( ' > ', $actual_line )
				) : '',
				'confidence'          => 0.7,
				'source_reference'    => array(),
				'generated_reference' => array(),
			),
		);
	}

	/**
	 * Compare mobile navigation reproduction.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return array<int, array<string, mixed>>
	 */
	private function navigation_checks( array $source, array $generated ) {
		$detected   = isset( $source['navigation']['mobile_detected'] ) ? $source['navigation']['mobile_detected'] : null;
		$source_behavior = isset( $source['navigation']['behavior'] ) ? (string) $source['navigation']['behavior'] : 'unknown';
		$hidden     = isset( $generated['navigation']['hidden_on_mobile'] ) ? (int) $generated['navigation']['hidden_on_mobile'] : 0;

		if ( null === $detected || ! $detected ) {
			return array(
				array(
					'category'            => 'navigation',
					'target'              => 'document',
					'property'            => 'mobile_navigation',
					'expected'            => 'not_detected',
					'actual'              => $hidden > 0 ? 'hidden_on_mobile' : 'no_change_detected',
					'state'               => 'unknown',
					'confidence'          => 0.0,
					'source_reference'    => array(),
					'generated_reference' => array(),
				),
			);
		}

		// Source navigation is detected but ReplicaForge does not reproduce menu
		// behaviour. The state is reported honestly and is never claimed correct.
		$state = $hidden > 0 ? 'partial' : 'missing';
		return array(
			array(
				'category'            => 'navigation',
				'target'              => 'document',
				'property'            => 'mobile_navigation',
				'expected'            => 'mobile_navigation_detected',
				'actual'              => $state,
				'state'               => $state,
				'severity'            => 'moderate',
				'viewport'            => 'mobile',
				'message'             => sprintf(
					/* translators: %s: Source behaviour label. */
					__( 'The source uses a mobile navigation pattern (%s). ReplicaForge does not reproduce menu interaction, so the draft shows static navigation content only. Verify the behaviour in a browser.', 'replicaforge' ),
					$source_behavior
				),
				'confidence'          => 0.6,
				'source_reference'    => array(),
				'generated_reference' => array(),
			),
		);
	}

	/**
	 * Return whether a change label describes a layout change.
	 *
	 * @param string $change Change label.
	 * @return bool
	 */
	private function is_layout_change( $change ) {
		$change = strtolower( (string) $change );
		foreach ( array( 'layout_change', 'grid_columns_change', 'flex_direction_change', 'display_change' ) as $needle ) {
			if ( false !== strpos( $change, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return whether the generated side has rules for a device.
	 *
	 * @param array<string, mixed> $view   Generated viewport record.
	 * @param string               $device Device key.
	 * @return bool
	 */
	private function generated_changes( array $view, $device ) {
		if ( isset( $view['overrides'] ) && (int) $view['overrides'] > 0 ) {
			return true;
		}
		if ( 'desktop' === $device ) {
			return true;
		}
		unset( $view );
		return false;
	}

	/**
	 * Return the comparison state for a layout change.
	 *
	 * @param bool $has_source_change   Whether the source has layout evidence.
	 * @param bool $has_generated_rules Whether the draft has device rules.
	 * @return string
	 */
	private function change_state( $has_source_change, $has_generated_rules ) {
		if ( ! $has_source_change ) {
			return 'unknown';
		}
		return $has_generated_rules ? 'partial' : 'missing';
	}

	/**
	 * Return a human-readable layout change message.
	 *
	 * @param string $device             Device key.
	 * @param bool   $has_source_change  Whether the source has layout evidence.
	 * @param bool   $has_generated_rules Whether the draft has device rules.
	 * @return string
	 */
	private function change_message( $device, $has_source_change, $has_generated_rules ) {
		if ( ! $has_source_change ) {
			return '';
		}
		if ( $has_generated_rules ) {
			return sprintf(
				/* translators: %s: Device name. */
				__( 'The source changes layout at %s and the draft has device rules. Confirm the result visually; static analysis cannot confirm the rendering matches.', 'replicaforge' ),
				$device
			);
		}
		return sprintf(
			/* translators: %s: Device name. */
			__( 'The source changes layout at %s but the draft has no device rules for it.', 'replicaforge' ),
			$device
		);
	}
}
