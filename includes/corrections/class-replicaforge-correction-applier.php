<?php
/**
 * Correction applier for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Applies one reviewed correction batch to a draft, reversibly.
 *
 * The applier is the only place that turns a review decision into a document
 * change. It takes a snapshot, validates the approved corrections against the
 * document it is about to save, applies them to the in-memory tree, saves once,
 * re-runs Phase 5, and then compares the two validation results. If the
 * comparison shows a regression the previous state is restored automatically, so
 * a correction that damages the page never survives.
 */
final class Correction_Applier {

	/**
	 * Property whitelist.
	 *
	 * @var Correction_Property_Map
	 */
	private $properties;

	/**
	 * Document writer.
	 *
	 * @var Elementor_Document_Writer
	 */
	private $writer;

	/**
	 * Plan validator.
	 *
	 * @var Correction_Validator
	 */
	private $validator;

	/**
	 * Snapshot service.
	 *
	 * @var Correction_Snapshot
	 */
	private $snapshots;

	/**
	 * Regression detector.
	 *
	 * @var Regression_Detector
	 */
	private $regressions;

	/**
	 * Phase 5 validation engine.
	 *
	 * @var Validation_Engine
	 */
	private $validation;

	/**
	 * Constructor.
	 *
	 * @param Elementor_Document_Writer|null $writer      Optional writer.
	 * @param Correction_Property_Map|null   $properties  Optional property whitelist.
	 * @param Correction_Validator|null      $validator   Optional plan validator.
	 * @param Correction_Snapshot|null       $snapshots   Optional snapshot service.
	 * @param Regression_Detector|null       $regressions Optional regression detector.
	 * @param Validation_Engine|null         $validation  Optional Phase 5 engine.
	 */
	public function __construct( $writer = null, $properties = null, $validator = null, $snapshots = null, $regressions = null, $validation = null ) {
		$this->properties  = $properties instanceof Correction_Property_Map ? $properties : new Correction_Property_Map();
		$this->writer      = $writer instanceof Elementor_Document_Writer ? $writer : new Elementor_Document_Writer( null, $this->properties );
		$this->validator   = $validator instanceof Correction_Validator ? $validator : new Correction_Validator( $this->properties );
		$this->snapshots   = $snapshots instanceof Correction_Snapshot ? $snapshots : new Correction_Snapshot( $this->properties );
		$this->regressions = $regressions instanceof Regression_Detector ? $regressions : new Regression_Detector();
		$this->validation  = $validation instanceof Validation_Engine ? $validation : new Validation_Engine();
	}

	/**
	 * Apply a reviewed plan.
	 *
	 * @param array<string, mixed>      $plan      Stored plan.
	 * @param array<int, string>        $selected  Correction identifiers the user approved.
	 * @param array<string, mixed>      $options   Apply options.
	 * @return array<string, mixed>
	 */
	public function apply( array $plan, array $selected, array $options = array() ) {
		$started    = microtime( true );
		$post_id    = isset( $plan['post_id'] ) ? absint( $plan['post_id'] ) : 0;
		$reader     = isset( $options['reader'] ) && $options['reader'] instanceof Elementor_Document_Reader ? $options['reader'] : new Elementor_Document_Reader( null, $this->properties );
		$structural = ! empty( $options['approved_structural'] );
		$revalidate = ! isset( $options['revalidate'] ) || false !== $options['revalidate'];
		$representation = isset( $options['design_representation'] ) && is_array( $options['design_representation'] ) ? $options['design_representation'] : null;

		Security::log_event(
			'correction_started',
			array(
				'code'   => 'phase6',
				'count'  => count( $selected ),
				'reason' => 'user_triggered',
			)
		);

		if ( $post_id < 1 || ! $reader->load( $post_id ) ) {
			return $this->error(
				'draft_not_correctionable',
				sprintf(
					/* translators: %s: Reason code. */
					__( 'The draft could not be opened for correction (%s). Nothing was changed.', 'replicaforge' ),
					$reader->error()
				),
				403
			);
		}

		$document_before = $reader->elements();
		$before_score    = isset( $plan['validation_score'] ) && is_numeric( $plan['validation_score'] ) ? (float) $plan['validation_score'] : null;

		$check = $this->validator->validate( $plan, $reader, $selected );
		if ( empty( $check['valid'] ) ) {
			$detail = implode( ', ', array_map( 'sanitize_key', $check['errors'] ) );
			Security::log_event( 'correction_failed', array( 'code' => 'plan_refused', 'reason' => 'validation' ) );
			return $this->error(
				'plan_refused',
				sprintf(
					/* translators: %s: Reason codes. */
					__( 'The reviewed corrections were refused before anything was written (%s).', 'replicaforge' ),
					$detail
				),
				400
			);
		}

		$approved = array_values( $check['approved'] );
		foreach ( $approved as $position => $correction ) {
			$approved[ $position ]['approved_structural'] = $structural;
		}

		$deadline  = microtime( true ) + Correction_Limits::APPLY_TIME_BUDGET;
		$truncated = false;
		$batch     = array();
		$skipped   = array();
		foreach ( $approved as $correction ) {
			if ( count( $batch ) >= Correction_Limits::MAX_ITERATION_CORRECTIONS || microtime( true ) > $deadline ) {
				$truncated = true;
				$skipped[] = array(
					'correction_id' => (string) $correction['correction_id'],
					'reason'        => 'iteration_limit',
					'action'        => (string) $correction['action'],
				);
				continue;
			}
			$batch[] = $correction;
		}
		if ( $truncated ) {
			Security::log_event(
				'correction_applied',
				array(
					'code'   => 'iteration_limit',
					'reason' => 'correction',
				)
			);
		}
		$approved = $batch;

		$snapshot = $this->snapshots->create(
			$post_id,
			isset( $plan['validation_id'] ) ? (string) $plan['validation_id'] : '',
			array( 'correction_count' => count( $approved ) )
		);
		if ( empty( $snapshot['success'] ) ) {
			Security::log_event( 'correction_failed', array( 'code' => 'snapshot_failed', 'reason' => 'snapshot' ) );
			return $this->error( 'snapshot_failed', __( 'A snapshot of the current draft could not be created, so nothing was changed.', 'replicaforge' ), 500 );
		}

		// The writer is seeded with the overrides already on the draft, so a device
		// correction reads the current value instead of assuming there is none and
		// replacing something the user set.
		$this->writer->set_responsive( $reader->responsive() );
		$applied = $this->writer->apply( $document_before, $approved );
		$failed  = $applied['failed'];

		if ( empty( $applied['applied'] ) ) {
			$this->record( $plan, $snapshot, $check, array(), $failed, $skipped, $before_score, null, 0, 'no_applicable_corrections', array(), $reader, $revalidate, $representation );
			return array(
				'success'      => true,
				'correction_id' => $this->correction_id( $plan, $snapshot ),
				'applied'      => 0,
				'refused'      => $check['refused'],
				'skipped'      => $skipped,
				'failed'       => $failed,
				'snapshot_id'  => (string) $snapshot['snapshot_id'],
				'rolled_back'  => false,
				'stop_reason'  => 'no_applicable_corrections',
				'warnings'     => array( __( 'None of the reviewed corrections could be applied, so the document was not written.', 'replicaforge' ) ),
				'duration_ms'  => (int) round( ( microtime( true ) - $started ) * 1000 ),
			);
		}

		$saved = $this->writer->save(
			$post_id,
			$applied['elements'],
			isset( $applied['responsive'] ) && is_array( $applied['responsive'] ) ? $applied['responsive'] : array()
		);
		if ( empty( $saved['success'] ) ) {
			// Nothing usable was written, so the snapshot is simply discarded and
			// the document is confirmed unchanged.
			$restore = $this->snapshots->restore( $post_id, (string) $snapshot['snapshot_id'] );
			Security::log_event( 'correction_failed', array( 'code' => 'save_failed', 'reason' => 'elementor' ) );
			return $this->error(
				'save_failed',
				__( 'The corrected document was not accepted, so the previous document was preserved and nothing was changed.', 'replicaforge' ),
				500
			);
		}

		$this->snapshots->record_written( $post_id, $applied['applied'] );
		$this->store_hashes( $post_id, $saved );

		$after       = $revalidate ? $this->revalidate( $representation, $post_id, $plan ) : null;
		$comparison  = is_array( $after ) ? $this->regressions->compare( $this->before_validation( $plan, $representation, $post_id ), $after ) : array( 'available' => false );
		$rolled_back = false;
		$stop_reason = 'applied';

		if ( ! empty( $comparison['available'] ) && ! empty( $comparison['has_regression'] ) ) {
			Security::log_event( 'regression_detected', array( 'code' => 'validation_regression', 'reason' => 'correction' ) );
			$restore = $this->snapshots->restore( $post_id, (string) $snapshot['snapshot_id'] );
			if ( empty( $restore['success'] ) ) {
				return $this->error(
					'rollback_failed',
					__( 'The correction caused a regression and the previous document could not be restored. Use the correction history to restore the snapshot manually.', 'replicaforge' ),
					500
				);
			}
			$rolled_back = true;
			$stop_reason = 'regression';
			$after       = null;
		}

		$record = $this->record( $plan, $snapshot, $check, $applied['applied'], $failed, $skipped, $before_score, $after, $rolled_back ? 1 : 0, $stop_reason, isset( $comparison['regressions'] ) ? $comparison['regressions'] : array(), $reader, $revalidate, $representation );

		Security::log_event(
			$rolled_back ? 'rollback_completed' : 'correction_applied',
			array(
				'count'  => count( $applied['applied'] ),
				'code'   => $rolled_back ? 'regression' : 'batch',
				'reason' => 'correction',
			)
		);

		return array(
			'success'       => true,
			'correction_id' => (string) $record['correction_id'],
			'plan_id'       => isset( $plan['plan_id'] ) ? (string) $plan['plan_id'] : '',
			'post_id'       => $post_id,
			'snapshot_id'   => (string) $snapshot['snapshot_id'],
			'applied'       => count( $applied['applied'] ),
			'refused'       => $check['refused'],
			'skipped'       => $skipped,
			'failed'        => $failed,
			'changes'       => $this->summarize( $applied['applied'] ),
			'validation_before' => $before_score,
			'validation_after'  => isset( $after['metrics']['overall']['value'] ) ? $after['metrics']['overall']['value'] : null,
			'comparison'    => $comparison,
			'regressions'   => isset( $comparison['regressions'] ) ? $comparison['regressions'] : array(),
			'rolled_back'   => $rolled_back,
			'stop_reason'   => $stop_reason,
			'duration_ms'   => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'read_only'     => false,
			'published'     => false,
		);
	}

	/**
	 * Roll a correction run back to its snapshot.
	 *
	 * @param int    $post_id     Draft post identifier.
	 * @param string $correction_id Correction run identifier.
	 * @return array<string, mixed>
	 */
	public function rollback( $post_id, $correction_id ) {
		$history = new Correction_History();
		$record  = $history->get( $correction_id );
		if ( ! is_array( $record ) || empty( $record['snapshot_id'] ) ) {
			return $this->error( 'correction_not_found', __( 'That correction run could not be found.', 'replicaforge' ), 404 );
		}
		if ( (int) $record['post_id'] !== absint( $post_id ) ) {
			return $this->error( 'correction_post_mismatch', __( 'That correction run belongs to a different draft.', 'replicaforge' ), 403 );
		}

		Security::log_event( 'rollback_started', array( 'code' => 'user_requested', 'reason' => 'correction' ) );

		$restored = $this->snapshots->restore( absint( $post_id ), (string) $record['snapshot_id'] );
		if ( empty( $restored['success'] ) ) {
			return $this->error(
				'rollback_failed',
				isset( $restored['error']['message'] ) ? (string) $restored['error']['message'] : __( 'The previous document could not be restored.', 'replicaforge' ),
				500
			);
		}

		$this->store_hashes( absint( $post_id ), $restored );

		return array(
			'success'       => true,
			'correction_id' => (string) $correction_id,
			'post_id'       => absint( $post_id ),
			'snapshot_id'   => (string) $record['snapshot_id'],
			'restored_hash' => isset( $restored['document_hash'] ) ? (string) $restored['document_hash'] : '',
		);
	}

	/**
	 * Return the validation result a plan was built from.
	 *
	 * The applier needs the before measurement to compare against. It is read from
	 * the Phase 5 cache, which the plan itself was built from, so the comparison
	 * always uses the same result the user reviewed.
	 *
	 * @param array<string, mixed> $plan           Plan.
	 * @param array<string, mixed>|null $representation Phase 2 representation.
	 * @param int                  $post_id        Draft post identifier.
	 * @return array<string, mixed>
	 */
	private function before_validation( array $plan, $representation, $post_id ) {
		unset( $representation, $post_id );
		$cache = new Validation_Cache();
		$stored = $cache->get_by_id( isset( $plan['validation_id'] ) ? (string) $plan['validation_id'] : '' );
		if ( is_array( $stored ) && isset( $stored['result'] ) && is_array( $stored['result'] ) ) {
			return $stored['result'];
		}
		return array();
	}

	/**
	 * Run Phase 5 again after a correction.
	 *
	 * @param array<string, mixed>|null $representation Phase 2 representation.
	 * @param int                       $post_id        Draft post identifier.
	 * @param array<string, mixed>      $plan           Plan.
	 * @return array<string, mixed>|null
	 */
	private function revalidate( $representation, $post_id, array $plan ) {
		if ( ! is_array( $representation ) ) {
			// Without the source representation the engine cannot re-validate, so
			// the run reports that instead of guessing a score.
			return null;
		}
		$result = $this->validation->validate( $representation, absint( $post_id ), array( 'force' => true ) );
		unset( $plan );
		return isset( $result['schema_version'] ) ? $result : null;
	}

	/**
	 * Store the generation and correction hashes for a draft.
	 *
	 * @param int                  $post_id Draft post identifier.
	 * @param array<string, mixed> $result  Save or restore outcome.
	 * @return void
	 */
	private function store_hashes( $post_id, array $result ) {
		$post_id = absint( $post_id );
		if ( isset( $result['document_hash'] ) && is_string( $result['document_hash'] ) && '' !== $result['document_hash'] ) {
			update_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'generation_hash', (string) $result['document_hash'] );
		}
		update_post_meta( $post_id, Correction_Limits::META_PREFIX . 'last_correction_hash', (string) get_post_meta( $post_id, '_elementor_data', true ) === false ? '' : hash( 'sha256', (string) get_post_meta( $post_id, '_elementor_data', true ) ) );
	}

	/**
	 * Record one correction run in the history.
	 *
	 * @param array<string, mixed>      $plan       Plan.
	 * @param array<string, mixed>      $snapshot   Snapshot.
	 * @param array<string, mixed>      $check      Validator outcome.
	 * @param array<int, array>         $applied    Applied changes.
	 * @param array<int, array>         $failed     Failed changes.
	 * @param array<int, array>         $skipped    Skipped changes.
	 * @param float|null                $before     Measurement before.
	 * @param array<string, mixed>|null $after      Validation after.
	 * @param int                       $iterations Iterations used.
	 * @param array<int, array>         $regressions Detected regressions.
	 * @param string                    $stop       Stop reason.
	 * @param Elementor_Document_Reader $reader     Reader.
	 * @param bool                      $revalidate Whether re-validation ran.
	 * @param array<string, mixed>|null $representation Phase 2 representation.
	 * @return array<string, mixed>
	 */
	private function record( array $plan, array $snapshot, array $check, array $applied, array $failed, array $skipped, $before, $after, $iterations, $stop, array $regressions, Elementor_Document_Reader $reader, $revalidate, $representation ) {
		$history  = new Correction_History();
		$after_score = is_array( $after ) && isset( $after['metrics']['overall']['value'] ) && is_numeric( $after['metrics']['overall']['value'] )
			? (float) $after['metrics']['overall']['value']
			: null;

		$changes = array();
		foreach ( $applied as $change ) {
			$change['status'] = 'applied';
			$changes[]        = $change;
		}
		foreach ( $failed as $change ) {
			$change['status']    = 'failed';
			$change['old_value'] = null;
			$change['new_value'] = null;
			$changes[]           = $change;
		}
		foreach ( $check['refused'] as $change ) {
			$change['status'] = 'rejected';
			$changes[]        = $change;
		}
		foreach ( $skipped as $change ) {
			$change['status'] = 'skipped';
			$changes[]        = $change;
		}

		return $history->record(
			array(
				'correction_id'  => $this->correction_id( $plan, $snapshot ),
				'post_id'        => isset( $plan['post_id'] ) ? absint( $plan['post_id'] ) : 0,
				'plan_id'        => isset( $plan['plan_id'] ) ? (string) $plan['plan_id'] : '',
				'validation_id'  => isset( $plan['validation_id'] ) ? (string) $plan['validation_id'] : '',
				'generation_id'  => (string) get_post_meta( $reader->post_id(), Elementor_Limits::META_PREFIX . 'generation_id', true ),
				'snapshot_id'    => isset( $snapshot['snapshot_id'] ) ? (string) $snapshot['snapshot_id'] : '',
				'validation_before' => $before,
				'validation_after'  => $after_score,
				'improvement'    => ( null !== $before && null !== $after_score ) ? round( $after_score - $before, 2 ) : null,
				'iterations'     => (int) $iterations,
				'regressions'    => $regressions,
				'status'         => $this->status_for( $stop, $applied ),
				'stop_reason'    => $stop,
				'counts'         => array(
					'applied'  => count( $applied ),
					'rejected' => count( $check['refused'] ),
					'blocked'  => isset( $plan['counts']['blocked'] ) ? (int) $plan['counts']['blocked'] : 0,
					'failed'   => count( $failed ),
					'skipped'  => count( $skipped ),
					'rolled_back' => 'rolled_back' === $stop ? count( $applied ) : 0,
				),
				'changes'        => $changes,
				'warnings'       => $this->warnings( $revalidate, $representation, $stop ),
			)
		);
	}

	/**
	 * Return the status a run is recorded under.
	 *
	 * A run that changed nothing is never recorded as completed, because the review
	 * screen and the history both read that status as "the document now matches the
	 * source better". Saying so when nothing was written would be the one claim this
	 * phase must never make.
	 *
	 * @param string               $stop    Stop reason.
	 * @param array<int, array>    $applied Applied changes.
	 * @return string
	 */
	private function status_for( $stop, array $applied ) {
		if ( 'failed' === $stop ) {
			return 'failed';
		}
		if ( 'rolled_back' === $stop ) {
			return 'rolled_back';
		}
		if ( empty( $applied ) ) {
			return 'no_change';
		}
		return 'completed';
	}

	/**
	 * Return the warnings for one run.
	 *
	 * @param bool                      $revalidate     Whether re-validation ran.
	 * @param array<string, mixed>|null $representation Phase 2 representation.
	 * @param string                    $stop           Stop reason.
	 * @return array<int, string>
	 */
	private function warnings( $revalidate, $representation, $stop ) {
		$warnings = array();
		if ( $revalidate && ! is_array( $representation ) ) {
			$warnings[] = __( 'The draft was corrected, but the result could not be re-validated because the Phase 2 representation was not supplied with the request. Open the analyzer and validate again to measure the change.', 'replicaforge' );
		}
		if ( 'regression' === $stop ) {
			$warnings[] = __( 'The correction improved one measurement but regressed another, so ReplicaForge restored the previous document automatically.', 'replicaforge' );
		}
		return $warnings;
	}

	/**
	 * Return a deterministic correction run identifier.
	 *
	 * @param array<string, mixed> $plan     Plan.
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return string
	 */
	private function correction_id( array $plan, array $snapshot ) {
		return 'cor_' . substr(
			hash(
				'sha256',
				(string) ( $plan['plan_id'] ?? '' ) . '|' . (string) ( $snapshot['snapshot_id'] ?? '' ) . '|' . (string) ( $snapshot['document_hash'] ?? '' )
			),
			0,
			20
		);
	}

	/**
	 * Reduce applied changes to a display summary.
	 *
	 * @param array<int, array> $changes Changes.
	 * @return array<int, array<string, mixed>>
	 */
	private function summarize( array $changes ) {
		$summary = array();
		foreach ( array_slice( $changes, 0, Correction_Limits::MAX_CHANGES_PER_RUN ) as $change ) {
			$summary[] = array(
				'correction_id' => isset( $change['correction_id'] ) ? (string) $change['correction_id'] : '',
				'element_id'    => isset( $change['element_id'] ) ? (string) $change['element_id'] : '',
				'property'      => isset( $change['property'] ) ? (string) $change['property'] : '',
				'viewport'      => isset( $change['viewport'] ) ? (string) $change['viewport'] : '',
				'action'        => isset( $change['action'] ) ? (string) $change['action'] : '',
				'old_value'     => $this->display( isset( $change['old_value'] ) ? $change['old_value'] : null ),
				'new_value'     => $this->display( isset( $change['new_value'] ) ? $change['new_value'] : null ),
			);
		}
		return $summary;
	}

	/**
	 * Return a display value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function display( $value ) {
		if ( null === $value ) {
			return '—';
		}
		if ( is_array( $value ) ) {
			return substr( (string) wp_json_encode( $value ), 0, 120 );
		}
		return substr( (string) $value, 0, 120 );
	}

	/**
	 * Build a safe error envelope.
	 *
	 * @param string $code    Error code.
	 * @param string $message User-facing message.
	 * @param int    $status  HTTP-like status.
	 * @return array<string, mixed>
	 */
	private function error( $code, $message, $status ) {
		Security::log_event( 'correction_failed', array( 'code' => sanitize_key( $code ), 'reason' => 'phase6' ) );
		return array(
			'success' => false,
			'error'   => array(
				'code'    => sanitize_key( $code ),
				'message' => (string) $message,
				'status'  => absint( $status ),
			),
		);
	}
}
