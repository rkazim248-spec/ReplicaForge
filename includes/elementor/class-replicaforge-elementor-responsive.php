<?php
/**
 * Responsive strategy mapping for ReplicaForge Phase 4.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Converts the Phase 3 responsive strategy into Elementor device settings.
 *
 * Desktop values come from the measured Phase 2 layout. Tablet and mobile
 * settings are only emitted when the reconstruction specification contains
 * corresponding responsive evidence. An `unknown` value is never treated as
 * mobile stacking.
 */
final class Elementor_Responsive {

	/**
	 * Values that describe an evidence-backed change toward a stacked layout.
	 *
	 * @var array<int, string>
	 */
	private $stacking_values = array(
		'responsive_layout_change_detected',
		'stack',
		'stacked',
		'single_column',
		'single-column',
		'one_column',
		'column',
		'vertical',
	);

	/**
	 * Build the responsive plan for every section.
	 *
	 * @param array<string, mixed> $plan Normalized reconstruction plan.
	 * @return array<string, mixed>
	 */
	public function build( array $plan ) {
		$responsive = isset( $plan['responsive'] ) && is_array( $plan['responsive'] ) ? $plan['responsive'] : array();
		$devices   = $this->active_devices();

		$mobile_change   = $this->viewport_has_change( $responsive, 'mobile' );
		$tablet_change   = $this->viewport_has_change( $responsive, 'tablet' );
		$mobile_inferred = false;
		$tablet_inferred = false;
		$mobile_stacks   = false;
		$tablet_stacks   = false;

		$sections = array();
		foreach ( isset( $plan['sections'] ) && is_array( $plan['sections'] ) ? $plan['sections'] : array() as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['source_id'] ) ) {
				continue;
			}
			$section_id = (string) $section['source_id'];
			$record     = isset( $responsive['sections'][ $section_id ] ) && is_array( $responsive['sections'][ $section_id ] ) ? $responsive['sections'][ $section_id ] : array();
			$columns    = isset( $section['layout']['column_count'] ) ? (int) $section['layout']['column_count'] : 1;

			$entry = array(
				'desktop' => array(
					'columns'  => max( 1, $columns ),
					'direction' => isset( $section['layout']['direction'] ) ? (string) $section['layout']['direction'] : 'column',
				),
			);

			foreach ( array( 'tablet', 'mobile' ) as $device ) {
				$entry[ $device ] = array(
					'columns'   => null,
					'direction' => '',
					'evidence'  => 'not_detected',
				);

				$value = isset( $record[ $device ] ) ? (string) $record[ $device ] : 'unknown';
				if ( 'unknown' === $value || '' === $value ) {
					continue;
				}

				$has_change = $this->is_change_value( $value ) || ( 'mobile' === $device ? $mobile_change : $tablet_change );
				if ( ! $has_change ) {
					$entry[ $device ]['evidence'] = 'detected_without_layout_change';
					continue;
				}

				$entry[ $device ]['evidence'] = 'responsive_layout_change_detected';
				if ( $columns > 1 && $this->implies_stacking( $value ) ) {
					$entry[ $device ]['columns']   = 1;
					$entry[ $device ]['direction'] = 'column';
					if ( 'mobile' === $device ) {
						$mobile_inferred = true;
						$mobile_stacks   = true;
					} else {
						$tablet_inferred = true;
						$tablet_stacks   = true;
					}
				}
			}

			$sections[ $section_id ] = $entry;
		}

		$warnings = array();
		if ( $mobile_stacks ) {
			$warnings[] = __( 'Mobile stacking was inferred from detected responsive layout-change evidence. It was not measured, so it should be reviewed in the Elementor editor.', 'replicaforge' );
		} else {
			$warnings[] = __( 'No mobile layout change could be evidenced from the reconstruction specification, so no mobile stacking was invented. Review tablet and mobile manually.', 'replicaforge' );
		}
		if ( $tablet_stacks ) {
			$warnings[] = __( 'Tablet stacking was inferred from detected responsive layout-change evidence. It was not measured.', 'replicaforge' );
		}

		$report = array(
			'desktop' => $this->device_report( $sections, 'desktop', $devices ),
			'tablet'  => $this->device_report( $sections, 'tablet', $devices ),
			'mobile'  => $this->device_report( $sections, 'mobile', $devices ),
		);

		return array(
			'devices'  => $devices,
			'sections' => $sections,
			'report'   => $report,
			'warnings' => $warnings,
			'inferred' => array(
				'tablet' => $tablet_inferred,
				'mobile' => $mobile_inferred,
			),
		);
	}

	/**
	 * Return the responsive devices this generation may target.
	 *
	 * @return array<int, string>
	 */
	public function active_devices() {
		$devices = array( 'desktop', 'tablet', 'mobile' );
		if ( class_exists( '\Elementor\Core\Breakpoints\Manager' ) && isset( \Elementor\Plugin::$instance ) && is_object( \Elementor\Plugin::$instance ) ) {
			$breakpoints = isset( \Elementor\Plugin::$instance->breakpoints ) ? \Elementor\Plugin::$instance->breakpoints : null;
			if ( $breakpoints && method_exists( $breakpoints, 'get_active_breakpoints' ) ) {
				try {
					$active = $breakpoints->get_active_breakpoints();
				} catch ( \Throwable $exception ) {
					$active = null;
				}
				if ( is_array( $active ) && ! empty( $active ) ) {
					$supported = array();
					foreach ( $active as $key => $value ) {
						$name = is_string( $key ) ? $key : ( isset( $value['name'] ) && is_string( $value['name'] ) ? $value['name'] : '' );
						if ( in_array( $name, $devices, true ) ) {
							$supported[] = $name;
						}
					}
					if ( ! empty( $supported ) ) {
						$devices = array_values( array_unique( $supported ) );
					}
				}
			}
		}
		return $devices;
	}

	/**
	 * Determine whether a viewport has layout-change evidence.
	 *
	 * @param array<string, mixed> $responsive Normalized responsive block.
	 * @param string               $viewport   Viewport name.
	 * @return bool
	 */
	private function viewport_has_change( array $responsive, $viewport ) {
		$record = isset( $responsive[ $viewport ] ) && is_array( $responsive[ $viewport ] ) ? $responsive[ $viewport ] : array();
		if ( isset( $record['layout'] ) && $this->is_change_value( (string) $record['layout'] ) ) {
			return true;
		}
		foreach ( isset( $responsive['evidence'] ) && is_array( $responsive['evidence'] ) ? $responsive['evidence'] : array() as $evidence ) {
			if ( ! is_array( $evidence ) || ! isset( $evidence['viewport'] ) || $viewport !== $evidence['viewport'] ) {
				continue;
			}
			foreach ( isset( $evidence['changes'] ) && is_array( $evidence['changes'] ) ? $evidence['changes'] : array() as $change ) {
				if ( in_array( $change, array( 'layout_change', 'grid_columns_change', 'flex_direction_change', 'display_change' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Return true when a responsive value describes a layout change.
	 *
	 * @param string $value Layout value.
	 * @return bool
	 */
	private function is_change_value( $value ) {
		return in_array( $value, $this->stacking_values, true );
	}

	/**
	 * Return true when a responsive value implies a stacked layout.
	 *
	 * @param string $value Layout value.
	 * @return bool
	 */
	private function implies_stacking( $value ) {
		return in_array( $value, $this->stacking_values, true );
	}

	/**
	 * Count sections that received a stacked device plan.
	 *
	 * @param array<string, array> $sections Section plans.
	 * @param string               $device   Device key.
	 * @return int
	 */
	private function count_stacked( array $sections, $device ) {
		$count = 0;
		foreach ( $sections as $entry ) {
			if ( isset( $entry[ $device ]['columns'] ) && 1 === (int) $entry[ $device ]['columns'] ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Build an honest per-device report value.
	 *
	 * @param array<string, array> $sections Section plans.
	 * @param string               $device   Device key.
	 * @param array<int, string>   $devices  Active devices.
	 * @return string
	 */
	private function device_report( array $sections, $device, array $devices ) {
		if ( ! in_array( $device, $devices, true ) ) {
			return 'not_supported';
		}
		if ( 'desktop' === $device ) {
			return empty( $sections ) ? 'no_sections' : 'applied';
		}
		$count = $this->count_stacked( $sections, $device );
		if ( $count > 0 ) {
			return 'applied_from_evidence';
		}
		return 'not_detected';
	}
}
