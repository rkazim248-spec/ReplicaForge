<?php
/**
 * Correction engine for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The Phase 6 entry point: plan, review, apply, re-validate, and stop.
 *
 * The engine is read-mostly. `plan()` only reads; `apply()` only ever acts on the
 * exact correction identifiers the user selected from a plan the user reviewed.
 * Nothing is applied implicitly, no document is published, and the iteration loop
 * has a hard maximum with explicit stop conditions so it can never run forever.
 */
final class Correction_Engine {

	/**
	 * Property whitelist.
	 *
	 * @var Correction_Property_Map
	 */
	private $properties;

	/**
	 * Document reader.
	 *
	 * @var Elementor_Document_Reader
	 */
	private $reader;

	/**
	 * Planner.
	 *
	 * @var Correction_Planner
	 */
	private $planner;

	/**
	 * Plan validator.
	 *
	 * @var Correction_Validator
	 */
	private $validator;

	/**
	 * Applier.
	 *
	 * @var Correction_Applier
	 */
	private $applier;

	/**
	 * Correction history.
	 *
	 * @var Correction_History
	 */
	private $history;

	/**
	 * Report builder.
	 *
	 * @var Correction_Report
	 */
	private $report;

	/**
	 * AI planner.
	 *
	 * @var Ai_Correction_Planner
	 */
	private $ai;

	/**
	 * Reconciliation specification repository, used for approved section inserts.
	 *
	 * @var Elementor_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $services Optional service overrides.
	 */
	public function __construct( array $services = array() ) {
		$this->properties = isset( $services['properties'] ) && $services['properties'] instanceof Correction_Property_Map
			? $services['properties']
			: new Correction_Property_Map();
		$this->validator  = isset( $services['validator'] ) && $services['validator'] instanceof Correction_Validator
			? $services['validator']
			: new Correction_Validator( $this->properties );
		$this->reader     = isset( $services['reader'] ) && $services['reader'] instanceof Elementor_Document_Reader
			? $services['reader']
			: new Elementor_Document_Reader( null, $this->properties );
		$this->repository = isset( $services['repository'] ) && $services['repository'] instanceof Elementor_Repository
			? $services['repository']
			: new Elementor_Repository();
		$this->planner    = isset( $services['planner'] ) && $services['planner'] instanceof Correction_Planner
			? $services['planner']
			: new Correction_Planner( $this->properties );
		$this->history    = isset( $services['history'] ) && $services['history'] instanceof Correction_History
			? $services['history']
			: new Correction_History();
		$this->applier    = isset( $services['applier'] ) && $services['applier'] instanceof Correction_Applier
			? $services['applier']
			: new Correction_Applier( null, $this->properties, $this->validator );
		$this->report     = isset( $services['report'] ) && $services['report'] instanceof Correction_Report
			? $services['report']
			: new Correction_Report();
		$this->ai         = isset( $services['ai'] ) && $services['ai'] instanceof Ai_Correction_Planner
			? $services['ai']
			: new Ai_Correction_Planner( null, $this->validator );
	}

	/**
	 * Build a correction plan from a Phase 5 validation result.
	 *
	 * This reads a draft and a validation. It never writes.
	 *
	 * @param array<string, mixed> $validation      Phase 5 validation result.
	 * @param int                  $post_id         Draft post identifier.
	 * @param array<string, mixed> $options         Plan options.
	 * @return array<string, mixed>
	 */
	public function plan( array $validation, $post_id, array $options = array() ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return $this->error( 'forbidden', __( 'You do not have permission to review corrections on this site.', 'replicaforge' ), 403 );
		}

		$post_id = absint( $post_id );
		if ( ! $this->reader->load( $post_id ) ) {
			return $this->error(
				'draft_not_correctionable',
				sprintf(
					/* translators: %s: Reason code. */
					__( 'This draft cannot be corrected (%s). ReplicaForge only corrects its own Elementor drafts that are still drafts.', 'replicaforge' ),
					$this->reader->error()
				),
				403
			);
		}

		$plan = $this->planner->plan( $validation, $this->reader, $post_id );

		if ( ! empty( $options['ai'] ) && $this->ai->is_available() ) {
			$ai = $this->ai->plan( $plan );
			$plan['ai_planning'] = $ai;
			if ( ! empty( $ai['available'] ) ) {
				$plan['corrections'] = $this->ai->apply_order( $plan['corrections'], $ai['recommendations'] );
				$plan['levels_used'] = max( 5, (int) $plan['levels_used'] );
			}
		} else {
			$plan['ai_planning'] = array(
				'available' => false,
				'requested' => ! empty( $options['ai'] ),
				'warnings'  => array( __( 'AI planning was not run. The deterministic plan is complete on its own and remains available.', 'replicaforge' ) ),
			);
		}

		$plan['specification_id'] = (string) get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'specification_id', true );
		$plan['post_status']      = (string) get_post( $post_id )->post_status;

		return $this->report->plan( $plan, $post_id );
	}

	/**
	 * Apply a reviewed plan.
	 *
	 * @param string               $plan_id  Plan identifier.
	 * @param array<int, string>  $selected Correction identifiers the user approved.
	 * @param array<string, mixed> $options  Apply options.
	 * @return array<string, mixed>
	 */
	public function apply( $plan_id, array $selected, array $options = array() ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return $this->error( 'forbidden', __( 'You do not have permission to apply corrections on this site.', 'replicaforge' ), 403 );
		}

		$plan = $this->planner->load( $plan_id );
		if ( null === $plan ) {
			return $this->error( 'plan_not_found', __( 'That correction plan is no longer available. Plan the corrections again.', 'replicaforge' ), 404 );
		}

		$post_id = isset( $plan['post_id'] ) ? absint( $plan['post_id'] ) : 0;
		if ( ! $this->reader->load( $post_id ) ) {
			return $this->error(
				'draft_not_correctionable',
				sprintf(
					/* translators: %s: Reason code. */
					__( 'This draft cannot be corrected (%s).', 'replicaforge' ),
					$this->reader->error()
				),
				403
			);
		}

		// The plan was built against a specific document. A changed document means
		// the review the user approved no longer describes what will be written.
		$current_hash = $this->reader->document_hash();
		if ( isset( $plan['document_hash'] ) && (string) $plan['document_hash'] !== $current_hash ) {
			return $this->error(
				'plan_document_changed',
				__( 'The draft changed after this plan was created, so the reviewed corrections no longer describe it. Plan the corrections again to see the current state.', 'replicaforge' ),
				409
			);
		}

		$structural = ! empty( $options['approved_structural'] );
		if ( $structural ) {
			$plan = $this->attach_specification( $plan );
		}

		$report = $this->report->plan( $plan, $post_id );

		return $this->run( $report, $plan, $selected, $options, 1 );
	}

	/**
	 * Run one iteration of the correction loop.
	 *
	 * @param array<string, mixed> $report   Plan report.
	 * @param array<string, mixed> $plan     Stored plan.
	 * @param array<int, string>   $selected Selected correction identifiers.
	 * @param array<string, mixed> $options  Apply options.
	 * @param int                  $iteration Current iteration.
	 * @return array<string, mixed>
	 */
	private function run( array $report, array $plan, array $selected, array $options, $iteration ) {
		$iterations = isset( $options['iterations'] ) ? max( 1, min( Correction_Limits::MAX_ITERATIONS, (int) $options['iterations'] ) ) : 1;
		$before     = isset( $report['validation_score'] ) ? $report['validation_score'] : null;

		$result = $this->applier->apply(
			$plan,
			$selected,
			array(
				'reader'               => $this->reader,
				'approved_structural'  => ! empty( $options['approved_structural'] ),
				'design_representation' => isset( $options['design_representation'] ) ? $options['design_representation'] : null,
				'revalidate'           => ! isset( $options['revalidate'] ) || false !== $options['revalidate'],
			)
		);

		if ( empty( $result['success'] ) ) {
			return $result;
		}

		$run_report = $this->report->run( $result, $plan['post_id'] );
		$run_report['iteration']      = (int) $iteration;
		$run_report['max_iterations'] = (int) $iterations;
		$run_report['stop_conditions'] = array(
			'no_safe_corrections'      => __( 'No further safe corrections remain.', 'replicaforge' ),
			'improvement_below_limit'  => sprintf(
				/* translators: %s: Minimum improvement. */
				__( 'The measured improvement was below %s, so correcting again would be chasing noise.', 'replicaforge' ),
				(string) Correction_Limits::MIN_IMPROVEMENT
			),
			'max_iterations_reached'   => __( 'The configured maximum number of iterations was reached.', 'replicaforge' ),
			'regression_detected'      => __( 'A regression was detected, so the loop stopped.', 'replicaforge' ),
			'rolled_back'              => __( 'The previous document was restored, so the loop stopped.', 'replicaforge' ),
		);
		$run_report['stop_reason_text'] = $this->stop_text( $run_report, $iteration, $iterations );

		return $run_report;
	}

	/**
	 * Return a human explanation of why the loop stopped.
	 *
	 * @param array<string, mixed> $report    Run report.
	 * @param int                  $iteration Current iteration.
	 * @param int                  $maximum   Configured maximum.
	 * @return string
	 */
	private function stop_text( array $report, $iteration, $maximum ) {
		if ( ! empty( $report['rolled_back'] ) ) {
			return __( 'The correction caused a regression, so ReplicaForge restored the previous document automatically. Review the reported regression before correcting again.', 'replicaforge' );
		}

		// Nothing was written, so the explanation must not say a change was applied.
		// The document is exactly as it was, and the user has to know that.
		$applied = isset( $report['counts']['applied'] ) ? (int) $report['counts']['applied'] : 0;
		if ( 0 === $applied ) {
			$reason = ! empty( $report['failed'] ) ? __( 'Every reviewed correction was refused, so nothing was written', 'replicaforge' ) : '';
			if ( '' !== $reason ) {
				return sprintf(
					/* translators: %s: Reason the corrections were refused. */
					__( '%s. The document is unchanged.', 'replicaforge' ),
					$reason
				);
			}
			return __( 'None of the reviewed corrections changed anything, so the document is unchanged.', 'replicaforge' );
		}

		if ( (int) $report['iterations'] > 1 && (int) $iteration >= (int) $maximum ) {
			return __( 'The configured maximum number of correction iterations was reached.', 'replicaforge' );
		}
		if ( ! isset( $report['improvement'] ) || null === $report['improvement'] ) {
			return sprintf(
				/* translators: %d: Number of corrections written. */
				__( '%d correction(s) were written. The result could not be re-validated in this request, so open the analyzer and validate again to measure the change.', 'replicaforge' ),
				$applied
			);
		}
		if ( (float) $report['improvement'] < Correction_Limits::MIN_IMPROVEMENT ) {
			return sprintf(
				/* translators: 1: Measured improvement, 2: Minimum improvement. */
				__( 'The change was applied, but the measured improvement was %1$s, below the %2$s minimum. Correcting again would be chasing noise.', 'replicaforge' ),
				number_format_i18n( (float) $report['improvement'], 1 ),
				number_format_i18n( Correction_Limits::MIN_IMPROVEMENT, 1 )
			);
		}
		unset( $iteration, $maximum );
		return __( 'The change was applied and the re-validation measured an improvement. Plan the corrections again to see what is left.', 'replicaforge' );
	}

	/**
	 * Attach the reconstruction specification to approved structural corrections.
	 *
	 * An approved section insert is rebuilt from the stored specification. When
	 * the specification is no longer available the insert stays refused rather than
	 * being approximated.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array<string, mixed>
	 */
	private function attach_specification( array $plan ) {
		$specification_id = isset( $plan['specification_id'] ) ? (string) $plan['specification_id'] : '';
		if ( '' === $specification_id ) {
			$specification_id = (string) get_post_meta( (int) $plan['post_id'], Elementor_Limits::META_PREFIX . 'specification_id', true );
		}

		$specification = '' !== $specification_id ? $this->repository->get_specification( $specification_id ) : null;
		$normalized    = is_array( $specification ) ? $this->normalize_specification( $specification ) : null;

		foreach ( $plan['corrections'] as $position => $correction ) {
			if ( 'insert' !== (string) $correction['action'] ) {
				continue;
			}
			$plan['corrections'][ $position ]['specification'] = null === $normalized
				? array()
				: array(
					'plan'       => $normalized,
					'section_id' => (string) ( $correction['target']['source_section_id'] ?? '' ),
				);
		}

		$plan['specification_available'] = null !== $normalized;
		return $plan;
	}

	/**
	 * Normalize a stored specification into the shape the mapper consumes.
	 *
	 * @param array<string, mixed> $specification Stored specification.
	 * @return array<string, mixed>|null
	 */
	private function normalize_specification( array $specification ) {
		$validator = new Elementor_Spec_Validator();
		$checked   = $validator->validate( $specification );
		return empty( $checked['valid'] ) || empty( $checked['plan'] ) ? null : $checked['plan'];
	}

	/**
	 * Roll a correction run back.
	 *
	 * @param int    $post_id        Draft post identifier.
	 * @param string $correction_id  Correction run identifier.
	 * @return array<string, mixed>
	 */
	public function rollback( $post_id, $correction_id ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return $this->error( 'forbidden', __( 'You do not have permission to roll back corrections on this site.', 'replicaforge' ), 403 );
		}
		return $this->applier->rollback( absint( $post_id ), (string) $correction_id );
	}

	/**
	 * Return the correction history for a draft.
	 *
	 * @param int $post_id Draft post identifier.
	 * @param int $limit   Maximum records.
	 * @return array<int, array<string, mixed>>
	 */
	public function history( $post_id, $limit = 10 ) {
		return $this->history->for_draft( $post_id, $limit );
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
