<?php
/**
 * Correction report and export for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the correction report and its export payloads.
 *
 * The report shows what was reviewed, what was selected, what changed, and what
 * the re-validation measured. It never presents the improvement as a guarantee of
 * visual quality, and it never includes a credential, source content, or document
 * markup.
 */
final class Correction_Report {

	/**
	 * Build the report for a plan.
	 *
	 * @param array<string, mixed> $plan  Stored plan.
	 * @param int                  $post_id Draft post identifier.
	 * @return array<string, mixed>
	 */
	public function plan( array $plan, $post_id ) {
		$corrections = isset( $plan['corrections'] ) && is_array( $plan['corrections'] ) ? $plan['corrections'] : array();
		$blocked     = isset( $plan['blocked'] ) && is_array( $plan['blocked'] ) ? $plan['blocked'] : array();

		$groups = array(
			'safe'            => array(),
			'requires_review' => array(),
			'blocked'         => array(),
		);
		foreach ( $corrections as $correction ) {
			$eligibility = isset( $correction['eligibility'] ) ? (string) $correction['eligibility'] : 'requires_review';
			if ( isset( $groups[ $eligibility ] ) ) {
				$groups[ $eligibility ][] = $correction;
			}
		}
		foreach ( $blocked as $correction ) {
			$groups['blocked'][] = $correction;
		}

		$view = array();
		foreach ( $groups as $eligibility => $list ) {
			$view[ $eligibility ] = array_map( array( $this, 'view' ), $list );
		}

		// The counts are derived from the corrections that are actually present
		// rather than copied from the plan, so a count can never disagree with the
		// list rendered beside it and a caller cannot report a number that was
		// never measured.
		$counts = array(
			'safe'            => count( $groups['safe'] ),
			'requires_review' => count( $groups['requires_review'] ),
			'blocked'         => count( $groups['blocked'] ),
			'total'           => count( $corrections ) + count( $blocked ),
		);

		$batches = array();
		foreach ( isset( $plan['batches'] ) && is_array( $plan['batches'] ) ? $plan['batches'] : array() as $batch ) {
			$batches[] = array(
				'batch'  => (string) $batch['batch'],
				'safe'   => (int) $batch['safe'],
				'review' => (int) $batch['review'],
				'total'  => (int) $batch['total'],
				'ids'    => array_values( array_map( 'strval', (array) $batch['ids'] ) ),
			);
		}

		return array(
			'schema_version'    => Correction_Limits::SCHEMA_VERSION,
			'kind'              => 'plan',
			'plan_id'           => isset( $plan['plan_id'] ) ? (string) $plan['plan_id'] : '',
			'post_id'           => absint( $post_id ),
			'validation_id'     => isset( $plan['validation_id'] ) ? (string) $plan['validation_id'] : '',
			'validation_score'  => isset( $plan['validation_score'] ) ? $plan['validation_score'] : null,
			'document_hash'     => isset( $plan['document_hash'] ) ? (string) $plan['document_hash'] : '',
			'created_at'        => isset( $plan['created_at'] ) ? (string) $plan['created_at'] : '',
			'counts'            => $counts,
			'levels_used'       => isset( $plan['levels_used'] ) ? (int) $plan['levels_used'] : 0,
			'levels'            => Correction_Limits::LEVELS,
			'batches'           => $batches,
			'safe'              => $view['safe'],
			'requires_review'   => $view['requires_review'],
			'blocked'           => $view['blocked'],
			'auto_available'    => $counts['safe'],
			'human_review_required' => true,
			'applied'           => false,
			'published'         => false,
			'snapshots'         => $this->snapshots( $post_id ),
		);
	}

	/**
	 * Build the report for a completed run.
	 *
	 * @param array<string, mixed> $result Applier result.
	 * @param int                  $post_id Draft post identifier.
	 * @return array<string, mixed>
	 */
	public function run( array $result, $post_id ) {
		$record = ( new Correction_History() )->get( isset( $result['correction_id'] ) ? (string) $result['correction_id'] : '' );
		$record = is_array( $record ) ? $record : array();

		$before = isset( $result['validation_before'] ) ? $result['validation_before'] : ( isset( $record['validation_before'] ) ? $record['validation_before'] : null );
		$after  = isset( $result['validation_after'] ) ? $result['validation_after'] : ( isset( $record['validation_after'] ) ? $record['validation_after'] : null );
		$delta  = ( null !== $before && null !== $after ) ? round( (float) $after - (float) $before, 2 ) : null;

		return array(
			'schema_version'   => Correction_Limits::SCHEMA_VERSION,
			'kind'             => 'run',
			'correction_id'    => isset( $result['correction_id'] ) ? (string) $result['correction_id'] : '',
			'plan_id'          => isset( $result['plan_id'] ) ? (string) $result['plan_id'] : '',
			'post_id'          => absint( $post_id ),
			'snapshot_id'      => isset( $result['snapshot_id'] ) ? (string) $result['snapshot_id'] : '',
			'validation_id'    => isset( $record['validation_id'] ) ? (string) $record['validation_id'] : '',
			'generation_id'    => isset( $record['generation_id'] ) ? (string) $record['generation_id'] : '',
			'status'           => isset( $record['status'] ) ? (string) $record['status'] : ( isset( $result['rolled_back'] ) && $result['rolled_back'] ? 'rolled_back' : 'completed' ),
			'stop_reason'      => isset( $result['stop_reason'] ) ? (string) $result['stop_reason'] : '',
			'iterations'       => isset( $record['iterations'] ) ? (int) $record['iterations'] : 1,
			'validation_before' => $before,
			'validation_after'  => $after,
			'improvement'       => $delta,
			'measurement_note' => __( 'These are the internal validation measurements described in the validation report. An improvement is a measured change against the source analysis. It is not a guarantee of visual quality and no visual accuracy is claimed.', 'replicaforge' ),
			'counts'          => isset( $record['counts'] ) && is_array( $record['counts'] ) ? $record['counts'] : array(
				'applied' => isset( $result['applied'] ) ? (int) $result['applied'] : 0,
			),
			'changes'         => isset( $result['changes'] ) && is_array( $result['changes'] ) ? $result['changes'] : ( isset( $record['changes'] ) ? $record['changes'] : array() ),
			'refused'         => isset( $result['refused'] ) && is_array( $result['refused'] ) ? $result['refused'] : array(),
			'skipped'         => isset( $result['skipped'] ) && is_array( $result['skipped'] ) ? $result['skipped'] : array(),
			'failed'          => isset( $result['failed'] ) && is_array( $result['failed'] ) ? $result['failed'] : array(),
			'comparison'      => isset( $result['comparison'] ) && is_array( $result['comparison'] ) ? $result['comparison'] : array(),
			'regressions'     => isset( $result['regressions'] ) && is_array( $result['regressions'] ) ? $result['regressions'] : array(),
			'regression_count' => isset( $result['regressions'] ) && is_array( $result['regressions'] ) ? count( $result['regressions'] ) : 0,
			'rolled_back'     => ! empty( $result['rolled_back'] ),
			'duration_ms'     => isset( $result['duration_ms'] ) ? (int) $result['duration_ms'] : 0,
			'warnings'        => isset( $record['warnings'] ) && is_array( $record['warnings'] ) ? $record['warnings'] : array(),
			'snapshots'       => $this->snapshots( $post_id ),
			'published'       => false,
		);
	}

	/**
	 * Build the JSON export of a plan.
	 *
	 * @param array<string, mixed> $report  Report.
	 * @param int                  $post_id Draft post identifier.
	 * @return array<string, mixed>
	 */
	public function export_plan_json( array $report, $post_id ) {
		return array(
			'schema_version' => Correction_Limits::SCHEMA_VERSION,
			'kind'           => 'plan',
			'exported_at'    => gmdate( 'c' ),
			'post_id'        => absint( $post_id ),
			'plan'           => array(
				'plan_id'          => isset( $report['plan_id'] ) ? (string) $report['plan_id'] : '',
				'validation_id'    => isset( $report['validation_id'] ) ? (string) $report['validation_id'] : '',
				'validation_score' => isset( $report['validation_score'] ) ? $report['validation_score'] : null,
				'counts'           => isset( $report['counts'] ) ? $report['counts'] : array(),
				'levels'           => isset( $report['levels'] ) ? $report['levels'] : array(),
				'batches'          => isset( $report['batches'] ) ? $report['batches'] : array(),
			),
			'corrections'    => array(
				'safe'            => isset( $report['safe'] ) ? $report['safe'] : array(),
				'requires_review' => isset( $report['requires_review'] ) ? $report['requires_review'] : array(),
				'blocked'         => isset( $report['blocked'] ) ? $report['blocked'] : array(),
			),
			'applied'         => false,
			'published'       => false,
		);
	}

	/**
	 * Build the JSON export of a completed run.
	 *
	 * @param array<string, mixed> $report  Report.
	 * @param int                  $post_id Draft post identifier.
	 * @return array<string, mixed>
	 */
	public function export_run_json( array $report, $post_id ) {
		return array(
			'schema_version'    => Correction_Limits::SCHEMA_VERSION,
			'kind'              => 'run',
			'exported_at'       => gmdate( 'c' ),
			'post_id'           => absint( $post_id ),
			'correction_id'     => isset( $report['correction_id'] ) ? (string) $report['correction_id'] : '',
			'plan_id'           => isset( $report['plan_id'] ) ? (string) $report['plan_id'] : '',
			'validation_id'     => isset( $report['validation_id'] ) ? (string) $report['validation_id'] : '',
			'generation_id'     => isset( $report['generation_id'] ) ? (string) $report['generation_id'] : '',
			'status'            => isset( $report['status'] ) ? (string) $report['status'] : '',
			'iterations'        => isset( $report['iterations'] ) ? (int) $report['iterations'] : 0,
			'validation_before' => isset( $report['validation_before'] ) ? $report['validation_before'] : null,
			'validation_after'  => isset( $report['validation_after'] ) ? $report['validation_after'] : null,
			'improvement'       => isset( $report['improvement'] ) ? $report['improvement'] : null,
			'measurement_note'  => isset( $report['measurement_note'] ) ? (string) $report['measurement_note'] : '',
			'counts'            => isset( $report['counts'] ) ? $report['counts'] : array(),
			'changes'           => isset( $report['changes'] ) ? $report['changes'] : array(),
			'refused'           => isset( $report['refused'] ) ? $report['refused'] : array(),
			'skipped'           => isset( $report['skipped'] ) ? $report['skipped'] : array(),
			'failed'            => isset( $report['failed'] ) ? $report['failed'] : array(),
			'comparison'        => isset( $report['comparison'] ) ? $report['comparison'] : array(),
			'regressions'       => isset( $report['regressions'] ) ? $report['regressions'] : array(),
			'rolled_back'       => ! empty( $report['rolled_back'] ),
			'warnings'          => isset( $report['warnings'] ) ? $report['warnings'] : array(),
			'published'         => false,
		);
	}

	/**
	 * Build the CSV export of a plan.
	 *
	 * @param array<string, mixed> $report Report.
	 * @return string
	 */
	public function export_plan_csv( array $report ) {
		$columns = array(
			'correction_id',
			'eligibility',
			'level',
			'batch',
			'category',
			'property',
			'viewport',
			'action',
			'elementor_element_id',
			'source_component_id',
			'current',
			'expected',
			'confidence',
			'severity',
			'manually_modified',
			'reason',
		);

		$rows = array( implode( ',', $columns ) );
		foreach ( array( 'safe', 'requires_review', 'blocked' ) as $eligibility ) {
			foreach ( isset( $report[ $eligibility ] ) && is_array( $report[ $eligibility ] ) ? $report[ $eligibility ] : array() as $correction ) {
				$rows[] = implode(
					',',
					array(
						$this->cell( isset( $correction['correction_id'] ) ? $correction['correction_id'] : '' ),
						$this->cell( $eligibility ),
						$this->cell( isset( $correction['level'] ) ? $correction['level'] : 0 ),
						$this->cell( isset( $correction['batch'] ) ? $correction['batch'] : '' ),
						$this->cell( isset( $correction['category'] ) ? $correction['category'] : '' ),
						$this->cell( isset( $correction['property'] ) ? $correction['property'] : '' ),
						$this->cell( isset( $correction['viewport'] ) ? $correction['viewport'] : '' ),
						$this->cell( isset( $correction['action'] ) ? $correction['action'] : '' ),
						$this->cell( isset( $correction['target']['elementor_element_id'] ) ? $correction['target']['elementor_element_id'] : '' ),
						$this->cell( isset( $correction['target']['source_component_id'] ) ? $correction['target']['source_component_id'] : '' ),
						$this->cell( isset( $correction['current_label'] ) ? $correction['current_label'] : '' ),
						$this->cell( isset( $correction['expected_label'] ) ? $correction['expected_label'] : '' ),
						$this->cell( isset( $correction['confidence'] ) ? $correction['confidence'] : '' ),
						$this->cell( isset( $correction['severity'] ) ? $correction['severity'] : '' ),
						$this->cell( ! empty( $correction['manual_change']['modified'] ) ? 'yes' : 'no' ),
						$this->cell( isset( $correction['reason'] ) ? $correction['reason'] : '' ),
					)
				);
			}
		}

		return implode( "\n", $rows );
	}

	/**
	 * Reduce one correction to the review-screen view.
	 *
	 * @param array<string, mixed> $correction Correction.
	 * @return array<string, mixed>
	 */
	public function view( array $correction ) {
		return array(
			'correction_id'    => isset( $correction['correction_id'] ) ? (string) $correction['correction_id'] : '',
			'difference_id'    => isset( $correction['difference_id'] ) ? (string) $correction['difference_id'] : '',
			'category'         => isset( $correction['category'] ) ? (string) $correction['category'] : '',
			'property'         => isset( $correction['property'] ) ? (string) $correction['property'] : '',
			'property_label'   => isset( $correction['property_label'] ) ? (string) $correction['property_label'] : '',
			'control'          => isset( $correction['control'] ) ? (string) $correction['control'] : '',
			'action'           => isset( $correction['action'] ) ? (string) $correction['action'] : '',
			'level'            => isset( $correction['level'] ) ? (int) $correction['level'] : 0,
			'batch'            => isset( $correction['batch'] ) ? (string) $correction['batch'] : '',
			'viewport'         => isset( $correction['viewport'] ) ? (string) $correction['viewport'] : 'desktop',
			'severity'         => isset( $correction['severity'] ) ? (string) $correction['severity'] : 'moderate',
			'confidence'       => isset( $correction['confidence'] ) ? (float) $correction['confidence'] : 0.0,
			'eligibility'      => isset( $correction['eligibility'] ) ? (string) $correction['eligibility'] : 'requires_review',
			'auto'             => ! empty( $correction['auto'] ),
			'current_label'    => isset( $correction['current_label'] ) ? (string) $correction['current_label'] : '',
			'expected_label'   => isset( $correction['value_label'] ) ? (string) $correction['value_label'] : '',
			'current_document_label' => isset( $correction['current_document_value'] ) ? $this->label( $correction['current_document_value'] ) : '',
			'difference'       => isset( $correction['difference'] ) ? $correction['difference'] : null,
			'target'           => isset( $correction['target'] ) && is_array( $correction['target'] ) ? $correction['target'] : array(),
			'manually_modified' => ! empty( $correction['manual_change']['modified'] ),
			'manual_change'    => isset( $correction['manual_change'] ) && is_array( $correction['manual_change'] ) ? array(
				'modified'       => ! empty( $correction['manual_change']['modified'] ),
				'recorded_value' => isset( $correction['manual_change']['recorded_value'] ) ? $this->label( $correction['manual_change']['recorded_value'] ) : '—',
				'current_value'  => isset( $correction['manual_change']['current_value'] ) ? $this->label( $correction['manual_change']['current_value'] ) : '—',
			) : array( 'modified' => false ),
			'reason'           => isset( $correction['reason'] ) ? (string) $correction['reason'] : '',
			'measured_message' => isset( $correction['measured_message'] ) ? (string) $correction['measured_message'] : '',
		);
	}

	/**
	 * Return the snapshots available for a draft.
	 *
	 * @param int $post_id Draft post identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function snapshots( $post_id ) {
		$service   = new Correction_Snapshot();
		$snapshots = array();
		foreach ( $service->snapshots( $post_id ) as $snapshot ) {
			$snapshots[] = array(
				'snapshot_id'   => isset( $snapshot['snapshot_id'] ) ? (string) $snapshot['snapshot_id'] : '',
				'document_hash' => isset( $snapshot['document_hash'] ) ? (string) $snapshot['document_hash'] : '',
				'bytes'         => isset( $snapshot['bytes'] ) ? (int) $snapshot['bytes'] : 0,
				'created_at'    => isset( $snapshot['created_at'] ) ? (string) $snapshot['created_at'] : '',
				'restorable'    => '' !== (string) ( isset( $snapshot['snapshot_id'] ) ? $snapshot['snapshot_id'] : '' ),
				'legacy'        => ! empty( $snapshot['legacy'] ),
			);
		}
		return $snapshots;
	}

	/**
	 * Return a display label for a value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function label( $value ) {
		if ( null === $value ) {
			return '—';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			$encoded = wp_json_encode( $value );
			return is_string( $encoded ) ? substr( $encoded, 0, 120 ) : '—';
		}
		return substr( (string) $value, 0, 120 );
	}

	/**
	 * Escape one CSV cell.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function cell( $value ) {
		$value = str_replace( array( "\r", "\n", "\t" ), ' ', (string) $value );
		if ( false !== strpos( $value, ',' ) || false !== strpos( $value, '"' ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}
}
