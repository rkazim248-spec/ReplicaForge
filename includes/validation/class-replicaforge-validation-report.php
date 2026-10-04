<?php
/**
 * Validation report and export for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the validation report and its export payloads.
 *
 * The report is a read-only view of one validation result. Exporting never
 * includes credentials, document markup, or source content beyond the values a
 * difference already records.
 */
final class Validation_Report {

	/**
	 * Build the report from a validation result.
	 *
	 * @param array<string, mixed> $result Validation result.
	 * @return array<string, mixed>
	 */
	public function build( array $result ) {
		$metrics     = isset( $result['metrics'] ) && is_array( $result['metrics'] ) ? $result['metrics'] : array();
		$groups      = isset( $metrics['groups'] ) && is_array( $metrics['groups'] ) ? $metrics['groups'] : array();
		$differences = isset( $result['differences'] ) && is_array( $result['differences'] ) ? $result['differences'] : array();
		$levels      = isset( $result['levels'] ) && is_array( $result['levels'] ) ? $result['levels'] : array();

		$viewports = array();
		foreach ( array_keys( Validation_Limits::VIEWPORTS ) as $device ) {
			$score = null;
			if ( isset( $metrics['viewports'][ $device ]['value'] ) ) {
				$score = $metrics['viewports'][ $device ]['value'];
			}
			$viewports[ $device ] = array(
				'label'       => Validation_Limits::VIEWPORTS[ $device ]['label'],
				'score'       => $score,
				'metrics'     => isset( $metrics['viewports'][ $device ] ) && is_array( $metrics['viewports'][ $device ] ) ? $metrics['viewports'][ $device ] : array(),
				'differences' => $this->filter( $differences, $device ),
				'visual'      => isset( $result['visual']['viewports'][ $device ] ) && is_array( $result['visual']['viewports'][ $device ] ) ? $result['visual']['viewports'][ $device ] : array( 'available' => false ),
			);
		}

		$categories = array();
		foreach ( $differences as $difference ) {
			$category = (string) $difference['category'];
			if ( ! isset( $categories[ $category ] ) ) {
				$categories[ $category ] = array();
			}
			$categories[ $category ][] = $difference;
		}

		return array(
			'validation_id'   => isset( $result['validation_id'] ) ? (string) $result['validation_id'] : '',
			'created_at'      => isset( $result['created_at'] ) ? (string) $result['created_at'] : '',
			'schema_version'  => isset( $result['schema_version'] ) ? (string) $result['schema_version'] : Validation_Limits::SCHEMA_VERSION,
			'engine_version'  => isset( $result['engine_version'] ) ? (string) $result['engine_version'] : '',
			'cache'           => isset( $result['cache'] ) ? (string) $result['cache'] : 'miss',
			'levels'          => $levels,
			'source'          => isset( $result['source'] ) && is_array( $result['source'] ) ? $result['source'] : array(),
			'generated'       => isset( $result['generated'] ) && is_array( $result['generated'] ) ? $result['generated'] : array(),
			'metrics'         => $metrics,
			'groups'          => $groups,
			'viewports'       => $viewports,
			'categories'      => $categories,
			'summary'         => isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array(),
			'counts'          => isset( $result['counts'] ) && is_array( $result['counts'] ) ? $result['counts'] : array(),
			'visual'          => isset( $result['visual'] ) && is_array( $result['visual'] ) ? $result['visual'] : array(),
			'ai'              => isset( $result['ai'] ) && is_array( $result['ai'] ) ? $result['ai'] : array(),
			'correction_plan' => isset( $result['correction_plan'] ) && is_array( $result['correction_plan'] ) ? $result['correction_plan'] : array(),
			'warnings'        => isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? $result['warnings'] : array(),
			'limitations'     => isset( $result['limitations'] ) && is_array( $result['limitations'] ) ? $result['limitations'] : array(),
			'budget'          => isset( $result['budget'] ) && is_array( $result['budget'] ) ? $result['budget'] : array(),
			'read_only'       => true,
		);
	}

	/**
	 * Return the differences of one viewport.
	 *
	 * @param array<int, array> $differences Differences.
	 * @param string            $viewport     Device key.
	 * @return array<int, array>
	 */
	private function filter( array $differences, $viewport ) {
		$result = array();
		foreach ( $differences as $difference ) {
			$difference_viewport = isset( $difference['viewport'] ) ? (string) $difference['viewport'] : 'desktop';
			if ( 'desktop' === $difference_viewport || $difference_viewport === $viewport ) {
				$result[] = $difference;
			}
		}
		return $result;
	}

	/**
	 * Build the JSON export payload.
	 *
	 * @param array<string, mixed> $result Validation result.
	 * @return array<string, mixed>
	 */
	public function export_json( array $result ) {
		$export = array(
			'schema_version'  => isset( $result['schema_version'] ) ? (string) $result['schema_version'] : Validation_Limits::SCHEMA_VERSION,
			'validation_id'   => isset( $result['validation_id'] ) ? (string) $result['validation_id'] : '',
			'created_at'      => isset( $result['created_at'] ) ? (string) $result['created_at'] : '',
			'engine_version'  => isset( $result['engine_version'] ) ? (string) $result['engine_version'] : '',
			'levels'          => isset( $result['levels'] ) && is_array( $result['levels'] ) ? $result['levels'] : array(),
			'source'          => isset( $result['source'] ) && is_array( $result['source'] ) ? $result['source'] : array(),
			'generated'       => isset( $result['generated'] ) && is_array( $result['generated'] ) ? $result['generated'] : array(),
			'viewports'       => isset( $result['viewports'] ) && is_array( $result['viewports'] ) ? $result['viewports'] : array(),
			'metrics'         => isset( $result['metrics'] ) && is_array( $result['metrics'] ) ? $result['metrics'] : array(),
			'differences'     => isset( $result['differences'] ) && is_array( $result['differences'] ) ? $result['differences'] : array(),
			'visual'          => $this->export_visual( isset( $result['visual'] ) && is_array( $result['visual'] ) ? $result['visual'] : array() ),
			'correction_plan' => isset( $result['correction_plan'] ) && is_array( $result['correction_plan'] ) ? $result['correction_plan'] : array(),
			'counts'          => isset( $result['counts'] ) && is_array( $result['counts'] ) ? $result['counts'] : array(),
			'budget'          => isset( $result['budget'] ) && is_array( $result['budget'] ) ? $result['budget'] : array(),
			'warnings'        => isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? $result['warnings'] : array(),
			'limitations'     => isset( $result['limitations'] ) && is_array( $result['limitations'] ) ? $result['limitations'] : array(),
			'read_only'       => true,
		);

		// The AI explanation is exported as text only and never as a change.
		unset( $export['ai'] );

		return $export;
	}

	/**
	 * Return the exportable part of the visual result.
	 *
	 * @param array<string, mixed> $visual Visual result.
	 * @return array<string, mixed>
	 */
	private function export_visual( array $visual ) {
		$export = array(
			'available' => ! empty( $visual['available'] ),
			'capabilities' => isset( $visual['capabilities'] ) && is_array( $visual['capabilities'] ) ? $visual['capabilities'] : array(),
			'viewports' => array(),
			'warnings'  => isset( $visual['warnings'] ) && is_array( $visual['warnings'] ) ? $visual['warnings'] : array(),
		);
		foreach ( isset( $visual['viewports'] ) && is_array( $visual['viewports'] ) ? $visual['viewports'] : array() as $device => $viewport ) {
			if ( ! is_array( $viewport ) ) {
				continue;
			}
			$export['viewports'][ $device ] = array(
				'available'        => ! empty( $viewport['available'] ),
				'similarity'       => isset( $viewport['similarity'] ) ? $viewport['similarity'] : null,
				'differing_ratio'  => isset( $viewport['differing_ratio'] ) ? $viewport['differing_ratio'] : null,
				'band'             => isset( $viewport['band'] ) ? (string) $viewport['band'] : '',
				'size_mismatch'    => ! empty( $viewport['size_mismatch'] ),
				'source_size'      => isset( $viewport['source_size'] ) ? $viewport['source_size'] : null,
				'generated_size'   => isset( $viewport['generated_size'] ) ? $viewport['generated_size'] : null,
				'regions'          => isset( $viewport['regions'] ) && is_array( $viewport['regions'] ) ? $viewport['regions'] : array(),
				'reason'           => isset( $viewport['reason'] ) ? (string) $viewport['reason'] : '',
			);
		}
		return $export;
	}

	/**
	 * Build the CSV export of the difference list.
	 *
	 * @param array<string, mixed> $result Validation result.
	 * @return string
	 */
	public function export_csv( array $result ) {
		$columns = array(
			'id',
			'severity',
			'category',
			'viewport',
			'target',
			'property',
			'expected',
			'actual',
			'difference',
			'confidence',
			'source_component_id',
			'source_section_id',
			'elementor_element_id',
			'message',
		);

		$rows = array( implode( ',', $columns ) );
		foreach ( isset( $result['differences'] ) && is_array( $result['differences'] ) ? $result['differences'] : array() as $difference ) {
			$rows[] = implode(
				',',
				array(
					$this->csv( isset( $difference['id'] ) ? (string) $difference['id'] : '' ),
					$this->csv( isset( $difference['severity'] ) ? (string) $difference['severity'] : '' ),
					$this->csv( isset( $difference['category'] ) ? (string) $difference['category'] : '' ),
					$this->csv( isset( $difference['viewport'] ) ? (string) $difference['viewport'] : '' ),
					$this->csv( isset( $difference['target'] ) ? (string) $difference['target'] : '' ),
					$this->csv( isset( $difference['property'] ) ? (string) $difference['property'] : '' ),
					$this->csv( $this->scalar( isset( $difference['expected'] ) ? $difference['expected'] : null ) ),
					$this->csv( $this->scalar( isset( $difference['actual'] ) ? $difference['actual'] : null ) ),
					$this->csv( $this->scalar( isset( $difference['difference'] ) ? $difference['difference'] : null ) ),
					$this->csv( $this->scalar( isset( $difference['confidence'] ) ? $difference['confidence'] : null ) ),
					$this->csv( isset( $difference['source_reference']['source_component_id'] ) ? (string) $difference['source_reference']['source_component_id'] : '' ),
					$this->csv( isset( $difference['source_reference']['source_section_id'] ) ? (string) $difference['source_reference']['source_section_id'] : '' ),
					$this->csv( isset( $difference['generated_reference']['elementor_element_id'] ) ? (string) $difference['generated_reference']['elementor_element_id'] : '' ),
					$this->csv( isset( $difference['message'] ) ? (string) $difference['message'] : '' ),
				)
			);
		}

		return implode( "\n", $rows );
	}

	/**
	 * Escape one CSV cell.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function csv( $value ) {
		$value = str_replace( array( "\r", "\n", "\t" ), ' ', (string) $value );
		if ( false !== strpos( $value, ',' ) || false !== strpos( $value, '"' ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}

	/**
	 * Return a scalar as a string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function scalar( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return (string) $value;
	}
}
