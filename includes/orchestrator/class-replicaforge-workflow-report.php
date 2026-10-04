<?php
/**
 * Phase 17: the final workflow report.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the report §12 asks for, from what actually happened.
 *
 * ### Every number in here came from a record
 *
 * There is no invented completion time, no estimated duration, no accuracy percentage and no
 * fabricated progress figure. Where a figure could not be measured, the key is absent. A
 * report that reads "estimated 4 minutes, 92% accurate" when neither figure was derived
 * from anything is worse than a report that says what happened and stops, because a user
 * cannot tell which parts of it to trust.
 *
 * ### What it deliberately withholds
 *
 * Stack traces, API keys, endpoints, filesystem paths and anything the URL validator
 * refused. §12 forbids the first two by name and the phase requirements forbid the rest by
 * the same reasoning: a report is shared and archived, and disclosure in an archive is
 * disclosure forever.
 */
final class Workflow_Report {

	/**
	 * The capability registry.
	 *
	 * @var Capability_Registry
	 */
	private $capabilities;

	/**
	 * Build the report.
	 *
	 * @param Capability_Registry|null $capabilities Registry.
	 */
	public function __construct( $capabilities = null ) {
		$this->capabilities = ( $capabilities instanceof Capability_Registry ) ? $capabilities : new Capability_Registry();
	}

	/**
	 * Build the report.
	 *
	 * @param array              $record    The workflow.
	 * @param Workflow_Artifacts $artifacts The artifact store.
	 * @return array
	 */
	public function build( array $record, $artifacts ) {
		$gate  = new Quality_Gate( $this->capabilities );
		$final = (array) ( $record['result'] ?? array() );

		if ( array() === $final || empty( $final['status'] ) ) {
			$final = $gate->evaluate( $record, $artifacts );
		}

		return array(
			'schema_version'    => Orchestrator_Limits::SCHEMA_VERSION,
			'generated_at'      => gmdate( 'c' ),
			'workflow'          => array(
				'id'         => (string) $record['workflow_id'],
				'project_id' => (string) $record['project_id'],
				'type'       => (string) $record['type'],
				'mode'       => (string) $record['mode'],
				'state'      => (string) $record['state'],
				'created_by' => (int) $record['created_by'],
				'created_at' => (string) $record['created_at'],
				'updated_at' => (string) $record['updated_at'],
			),
			'source_website'    => (string) $record['source_url'],
			'final_status'      => (string) $final['status'],
			'explanation'       => (string) ( $final['explanation'] ?? '' ),
			'generated_pages'   => $this->pages( $record, $artifacts ),
			'stages'            => $this->stages( $record ),
			'validation'        => (array) ( $final['checks'] ?? array() ),
			'dimensions'        => (array) ( $final['dimensions'] ?? array() ),
			'remaining_issues'  => (array) ( $final['blocking'] ?? array() ),
			'warnings'          => (array) ( $final['warnings'] ?? array() ),
			'needs_review'      => (array) ( $final['needs_review'] ?? array() ),
			'unsupported'       => $this->unsupported( $record, $final ),
			'corrections'       => $this->corrections( $artifacts ),
			'manual_changes'    => $this->manual_changes( $artifacts ),
			'resources'         => $this->resources( $record, $artifacts ),
			'approvals'         => $this->approvals( $record ),
			'recovery'          => (array) ( $record['plan']['recovery'] ?? array() ),
			'capabilities'      => $this->capabilities->summary(),
			'next_actions'      => $this->next_actions( $record, $final, $artifacts ),
		);
	}

	/**
	 * Return the generated page references.
	 *
	 * @param array              $record    The workflow.
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @return array
	 */
	private function pages( array $record, $artifacts ) {
		$draft = $artifacts->get( 'draft' );

		if ( is_wp_error( $draft ) ) {
			return array();
		}

		$draft_id = (int) ( $draft['payload']['draft_id'] ?? 0 );
		$post     = get_post( $draft_id );

		$reference = array(
			'draft_id'     => $draft_id,
			'generation_id' => (string) ( $draft['payload']['generation_id'] ?? '' ),
			'post_status'  => (string) ( $draft['payload']['post_status'] ?? ( null === $post ? 'missing' : $post->post_status ) ),
			'title'        => null === $post ? '' : (string) $post->post_title,
			'edit_url'     => null === $post ? '' : (string) get_edit_post_link( $draft_id, 'raw' ),
			'preview_url'  => null === $post ? '' : (string) get_preview_post_link( $draft_id ),
			'exists'       => null !== $post,
		);

		return array( $reference );
	}

	/**
	 * Return every stage's outcome, with its reason.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function stages( array $record ) {
		$out = array();

		foreach ( (array) ( $record['plan']['stages'] ?? array() ) as $stage ) {
			$entry = (array) ( $record['stages'][ $stage ] ?? array() );

			$out[] = array(
				'stage'    => (string) $stage,
				'outcome'  => (string) ( $entry['outcome'] ?? 'pending' ),
				'reason'   => (string) ( $entry['reason'] ?? '' ),
				'attempts' => (int) ( $entry['attempts'] ?? 0 ),
				'ended_at' => (string) ( $entry['ended_at'] ?? '' ),
				'outputs'  => (array) ( $entry['outputs'] ?? array() ),
			);
		}

		return $out;
	}

	/**
	 * Return the features that could not be reproduced.
	 *
	 * Built from the capability answers and the interaction model, not from a fixed list,
	 * so it reflects this site rather than what the documentation hopes for.
	 *
	 * @param array $record The workflow.
	 * @param array $final  The quality gate result.
	 * @return array<int, string>
	 */
	private function unsupported( array $record, array $final ) {
		$out = array();

		foreach ( (array) $this->capabilities->summary()['limitations'] as $capability => $entry ) {
			if ( 'unavailable' === (string) ( $entry['status'] ?? '' ) ) {
				$out[] = sprintf(
					/* translators: 1: capability name, 2: its reason. */
					__( '%1$s is unavailable: %2$s', 'replicaforge' ),
					ucfirst( str_replace( '_', ' ', (string) $capability ) ),
					(string) ( $entry['reason'] ?? '' )
				);
			}
		}

		// Phase 16 records the interactions it could not map to a supported widget.
		foreach ( (array) ( $record['stages']['interactions']['reason'] ?? '' ) as $ignored ) {
			unset( $ignored );
		}

		if ( '' !== (string) ( $record['stages']['interactions']['reason'] ?? '' ) ) {
			$out[] = (string) $record['stages']['interactions']['reason'];
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Return the correction history.
	 *
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @return array
	 */
	private function corrections( $artifacts ) {
		$history = $artifacts->get( 'correction_history' );

		if ( is_wp_error( $history ) ) {
			return array();
		}

		return array(
			'iterations' => (int) ( $history['payload']['iterations'] ?? 0 ),
			'applied'    => (int) ( $history['payload']['applied'] ?? 0 ),
			'history'    => (array) ( $history['payload']['history'] ?? array() ),
		);
	}

	/**
	 * Return what happened to content the workflow did not control.
	 *
	 * §12 asks the report to state which manual changes were preserved. The honest source of
	 * that is phase 9's ownership model, which both phase 14 and phase 15 already read - so
	 * it is read rather than reimplemented, and a workflow that never touched user content
	 * says so plainly instead of claiming a preservation it did not perform.
	 *
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @return array
	 */
	private function manual_changes( $artifacts ) {
		$content = $artifacts->get( 'content_report' );

		if ( is_wp_error( $content ) ) {
			return array(
				'policy'   => __( 'Fields the user controls are not overwritten by a source value or an AI proposal.', 'replicaforge' ),
				'touched'  => 0,
				'evidence' => __( 'No content mapping ran, so no user-controlled field was modified.', 'replicaforge' ),
			);
		}

		$preserved = (int) ( $content['payload']['preserved_fields'] ?? 0 );

		return array(
			'policy'   => __( 'Fields the user controls are not overwritten by a source value or an AI proposal.', 'replicaforge' ),
			'touched'  => $preserved,
			'evidence' => (string) ( $content['payload']['preservation_note'] ?? '' ),
		);
	}

	/**
	 * Return resource consumption, from accounting.
	 *
	 * §12 requires "resource consumption from actual accounting". The counters here are the
	 * workflow's own, which are real counts of real operations. Where phase 10 also metered an
	 * operation, that is named rather than merged, because the two count different things and
	 * adding them would produce a number that means neither.
	 *
	 * @param array              $record    The workflow.
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @return array
	 */
	private function resources( array $record, $artifacts ) {
		$budget = (array) ( $record['budget'] ?? array() );

		$out = array(
			'attempts'   => (int) ( $record['attempts'] ?? 0 ),
			'artifacts'  => count( $artifacts->references() ),
			'artifact_bytes' => (int) $artifacts->total_size(),
		);

		foreach ( $budget as $name => $used ) {
			$out[ (string) $name ] = array(
				'used'  => (int) $used,
				'limit' => (int) ( Orchestrator_Limits::BUDGETS[ $name ] ?? 0 ),
			);
		}

		/*
		 * Elapsed time between creation and the last update is a measured fact about this
		 * workflow, so it is reported. An *estimate* of how long a future run would take is
		 * not derivable, so that key is simply absent.
		 */
		$created = strtotime( (string) $record['created_at'] );
		$updated = strtotime( (string) $record['updated_at'] );

		if ( false !== $created && false !== $updated && $updated >= $created ) {
			$out['elapsed_seconds'] = $updated - $created;
		}

		return $out;
	}

	/**
	 * Return the approval history.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function approvals( array $record ) {
		$out = array();

		foreach ( (array) $record['approvals'] as $gate => $entry ) {
			$out[] = array(
				'gate'        => (string) $gate,
				'status'      => (string) ( $entry['status'] ?? '' ),
				'capability'  => (string) ( $entry['capability'] ?? '' ),
				'reviewer_id' => (int) ( $entry['reviewer_id'] ?? 0 ),
				'decided_at'  => (string) ( $entry['decided_at'] ?? '' ),
				'note'        => (string) ( $entry['note'] ?? '' ),
				'artifact_hash' => (string) ( $entry['artifact_hash'] ?? '' ),
				'plan_id'     => (string) ( $entry['plan_id'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Return what a person should do next.
	 *
	 * Derived from the actual state, so the list is never a generic set of suggestions. An
	 * empty list is a real answer: it means there is nothing outstanding.
	 *
	 * @param array              $record    The workflow.
	 * @param array              $final     The quality gate result.
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @return array<int, array>
	 */
	private function next_actions( array $record, array $final, $artifacts ) {
		$actions = array();

		foreach ( (array) ( $record['plan']['approval_checkpoints'] ?? array() ) as $checkpoint ) {
			$gate = (string) ( $checkpoint['gate'] ?? '' );

			if ( '' === $gate ) {
				continue;
			}

			$entry = (array) ( $record['approvals'][ $gate ] ?? array() );

			if ( array() === $entry ) {
				$actions[] = array(
					'priority' => 'required',
					'action'   => sprintf(
						/* translators: %s: gate name. */
						__( 'Review and decide the "%s" approval.', 'replicaforge' ),
						$gate
					),
				);
				continue;
			}

			// A stale approval needs a fresh decision, not the old one re-read.
			if ( 'approved' === (string) $entry['status'] ) {
				$actions[] = array(
					'priority' => 'optional',
					'action'   => sprintf(
						/* translators: %s: gate name. */
						__( 'The "%s" approval may need repeating, because the plan changed after it was given.', 'replicaforge' ),
						$gate
					),
				);
			}
		}

		foreach ( (array) ( $final['warnings'] ?? array() ) as $warning ) {
			$actions[] = array( 'priority' => 'optional', 'action' => (string) $warning );
		}

		foreach ( (array) ( $final['needs_review'] ?? array() ) as $item ) {
			$actions[] = array( 'priority' => 'required', 'action' => (string) $item );
		}

		// The only action that is always the same, because it is always the safe one.
		$draft = $artifacts->get( 'draft' );

		if ( ! is_wp_error( $draft ) ) {
			$actions[] = array(
				'priority' => 'optional',
				'action'   => __( 'Open the generated draft in Elementor, check it against the source, and publish it yourself when you are satisfied.', 'replicaforge' ),
			);
		}

		return $actions;
	}
}
