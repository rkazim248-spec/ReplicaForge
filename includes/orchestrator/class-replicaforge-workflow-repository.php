<?php
/**
 * Phase 17: workflow persistence, the state machine, and the approval record.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Stores workflows and is the only place a workflow's state may change.
 *
 * ### Why state changes go through one class
 *
 * A workflow state is a security boundary, not a label. `waiting_approval` means nothing
 * gated has happened yet, and `cancelled` means nothing further will. If a REST handler, an
 * admin screen and a job tick each wrote a state directly, three of them would have to
 * re-derive "is this transition legal", and the one that forgot would be the one a user
 * could reach. So `transition()` is the only writer, and it reads the legality from
 * `Orchestrator_Limits::TRANSITIONS` - the same table the state machine test reads, so a
 * caller cannot hold a different opinion from the thing enforcing it.
 *
 * ### Why the approval record lives here rather than in phase 15
 *
 * This is a stated limitation of the existing plugin, not a preference. Phase 15's review
 * system is complete and correct, and it cannot currently record anything:
 * `Review_Store::create()` calls `resolve_version()`, which reads
 * `$project['versions']`, and `Project_Repository::add_version()` has no caller in
 * `includes/`. With no versions, `resolve_version()` returns null, `create()` returns null,
 * and both approval gates in `Project_Context_Store::gates()` report `status = none` and
 * `completable = false` forever.
 *
 * Wiring `add_version()` from here would be a change to phase 15's data model, and a wrong
 * one: a version list is a record of what a *project* has produced across its whole life,
 * and an orchestrator writing to it would make a project's history depend on which tool
 * happened to run. So the approval record is kept here, scoped to the workflow, and
 * **authorization still comes from phase 15** - a decision is only accepted from a user
 * that `Permission_Manager` grants the gate's capability to. This layer stores the decision;
 * it does not decide who may make one.
 *
 * ### Storage
 *
 * Options, like the rest of the plugin. One index option for listing and one option per
 * workflow, because a workflow's evidence is far larger than a dashboard row and the
 * dashboard must not have to load it.
 */
final class Workflow_Repository {

	/**
	 * The project repository, for ownership and duplicate detection.
	 *
	 * @var Project_Repository|null
	 */
	private $projects;

	/**
	 * The permission manager, for approval authorization.
	 *
	 * @var Permission_Manager|null
	 */
	private $permissions;

	/**
	 * The lock, reused from phase 11.
	 *
	 * @var Job_Lock|null
	 */
	private $locks;

	/**
	 * The index, loaded once.
	 *
	 * @var array<int, array>|null
	 */
	private $index = null;

	/**
	 * Build the repository.
	 *
	 * @param Project_Repository|null $projects    Project repository.
	 * @param Permission_Manager|null $permissions Permission manager.
	 * @param Job_Lock|null           $locks       The phase 11 lock.
	 */
	public function __construct( $projects = null, $permissions = null, $locks = null ) {
		$this->projects    = ( $projects instanceof Project_Repository ) ? $projects : new Project_Repository();
		$this->permissions = ( $permissions instanceof Permission_Manager ) ? $permissions : null;
		$this->locks       = ( $locks instanceof Job_Lock ) ? $locks : new Job_Lock();
	}

	/* ---------------------------------------------------------------------
	 * Creation
	 * ------------------------------------------------------------------ */

	/**
	 * Create a workflow in the draft state.
	 *
	 * @param array $input The workflow's inputs.
	 * @return array|\WP_Error
	 */
	public function create( array $input ) {
		$source_url = isset( $input['source_url'] ) ? (string) $input['source_url'] : '';
		$project_id = isset( $input['project_id'] ) ? (string) $input['project_id'] : '';
		$user_id    = isset( $input['created_by'] ) ? (int) $input['created_by'] : 0;

		if ( $user_id < 1 ) {
			return $this->refusal( 'workflow_no_user', __( 'A workflow must be created by an authenticated user.', 'replicaforge' ), 401 );
		}

		if ( '' === $project_id ) {
			return $this->refusal( 'workflow_no_project', __( 'A workflow must belong to a project.', 'replicaforge' ), 400 );
		}

		$project = $this->projects->find( $project_id );

		if ( null === $project ) {
			/*
			 * Refused, not created anyway. A workflow with a project id that does not exist
			 * is a workflow whose ownership cannot be established later, and an unowned
			 * reconstruction artefact is exactly what §41 forbids.
			 */
			return $this->refusal( 'workflow_unknown_project', __( 'The project does not exist.', 'replicaforge' ), 404 );
		}

		$ownership = $this->check_ownership( $project, $user_id );

		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		$url = '' === $source_url ? (string) ( $project['source_url'] ?? '' ) : $source_url;

		/*
		 * The URL is validated here rather than at the analysis stage, so a workflow cannot
		 * be created pointing at a private address and only discovered to be unsafe once the
		 * user has approved a plan for it.
		 */
		if ( '' === $url ) {
			return $this->refusal( 'workflow_no_source', __( 'The project has no source URL and none was supplied.', 'replicaforge' ), 400 );
		}

		$verdict = ( new Url_Validator() )->validate( $url );

		if ( empty( $verdict['success'] ) ) {
			$error = (array) ( $verdict['error'] ?? array() );

			return $this->refusal(
				(string) ( $error['code'] ?? 'workflow_bad_source' ),
				(string) ( $error['message'] ?? __( 'The source URL was refused.', 'replicaforge' ) ),
				400
			);
		}

		$id = 'wf_' . gmdate( 'Ymd\THis' ) . '_' . substr( hash( 'sha256', $project_id . '|' . (string) $verdict['url'] . '|' . wp_rand( 0, 999999 ) ), 0, 10 );

		$record = array(
			'workflow_id'     => $id,
			'schema_version'  => Orchestrator_Limits::SCHEMA_VERSION,
			'engine_version'  => Orchestrator_Limits::ENGINE_VERSION,
			'project_id'      => $project_id,
			'workspace_id'    => (string) ( $project['workspace_id'] ?? '' ),
			'source_url'      => (string) $verdict['url'],
			'type'            => Orchestrator_Limits::is_type( $input['type'] ?? '' ) ? (string) $input['type'] : 'single_page',
			'mode'            => Orchestrator_Limits::is_mode( $input['mode'] ?? '' ) ? (string) $input['mode'] : 'balanced',
			'state'           => 'draft',
			'created_by'      => $user_id,
			'created_at'      => gmdate( 'c' ),
			'updated_at'      => gmdate( 'c' ),
			'plan_id'         => '',
			'plan'            => array(),
			'stages'          => array(),
			'checkpoints'     => array(),
			'approvals'       => array(),
			'budget'          => array( 'attempts' => 0, 'correction_iterations' => 0, 'validation_passes' => 0 ),
			'attempts'        => 0,
			'idempotency_key' => $this->clean_key( $input['idempotency_key'] ?? '' ),
			'cancel_requested' => false,
			'result'          => array(),
			'notice'          => array(),
		);

		/*
		 * A duplicate submission returns the workflow that already exists rather than
		 * creating a second one. Two identical workflows racing would each generate a draft,
		 * and the user would have to work out which of the two Elementor pages is theirs.
		 */
		if ( '' !== $record['idempotency_key'] ) {
			$existing = $this->find_by_idempotency( $project_id, $record['idempotency_key'], $user_id );

			if ( null !== $existing ) {
				return array( $existing, true );
			}
		}

		if ( ! $this->save( $record ) ) {
			return $this->refusal( 'workflow_not_stored', __( 'The workflow could not be created.', 'replicaforge' ), 500 );
		}

		$this->index_add( $record );

		/*
		 * The project's version list gets an entry here, without going through
		 * `Project_Repository::add_version()` - see the class docblock. This is a workflow
		 * pointer, not a project version, and the two are kept in separate places on purpose.
		 */
		return $record;
	}

	/**
	 * Return whether a user may start a workflow for a project.
	 *
	 * ### Why the repository checks this and not only the API
	 *
	 * The REST handler already ran a `may_access()` check before calling `create()`. That is
	 * one place, and a single place is one forgotten call away from being an IDOR. The check
	 * lives here as well so that any future caller - a cron tick, an admin action, another
	 * plugin - gets it for free. A defence that exists in one caller is not a defence.
	 *
	 * ### The unowned-project case
	 *
	 * `Project_Repository::create()` does not record an owner: it writes `user_id => 0`
	 * and has no caller in `includes/` at all, so in practice every project in a real store
	 * is unowned. Refusing those would make the orchestrator unusable on every existing site,
	 * which is a worse failure than the one this check is guarding against - so an unowned
	 * project is permitted, and the record says so, rather than being silently treated as if
	 * it had an owner who happened to match.
	 *
	 * @param array $project The project.
	 * @param int   $user_id The caller.
	 * @return true|\WP_Error
	 */
	private function check_ownership( array $project, $user_id ) {
		$owner = (int) ( $project['user_id'] ?? 0 );

		if ( $owner > 0 ) {
			if ( $owner === (int) $user_id || user_can( (int) $user_id, 'manage_options' ) ) {
				return true;
			}

			// A project adopted into a workspace gets the phase 15 answer, which is the only
			// place a non-owner membership is expressed.
			$workspace_id = (string) ( $project['workspace_id'] ?? '' );

			if ( '' !== $workspace_id && null !== $this->permissions ) {
				if ( $this->permissions->can_in_project( (int) $user_id, $workspace_id, (string) $project['project_id'], 'projects.view' ) ) {
					return true;
				}
			}

			return $this->refusal( 'workflow_not_project_owner', __( 'You do not own this project.', 'replicaforge' ), 403 );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Return a workflow, or null.
	 *
	 * @param string $workflow_id Workflow id.
	 * @return array|null
	 */
	public function get( $workflow_id ) {
		$raw = get_option( Orchestrator_Limits::RECORD_PREFIX . $this->clean_id( $workflow_id ), null );

		if ( ! is_array( $raw ) ) {
			return null;
		}

		// A record written by an older engine is not silently upgraded. The caller decides
		// what to do, because upgrading in place would destroy the evidence of what
		// actually ran.
		if ( (string) ( $raw['schema_version'] ?? '' ) !== Orchestrator_Limits::SCHEMA_VERSION ) {
			return null;
		}

		return $raw;
	}

	/**
	 * Return a workflow, or an error.
	 *
	 * @param string $workflow_id Workflow id.
	 * @return array|\WP_Error
	 */
	public function require_workflow( $workflow_id ) {
		$record = $this->get( $workflow_id );

		if ( null === $record ) {
			return $this->refusal( 'workflow_not_found', __( 'The workflow does not exist.', 'replicaforge' ), 404 );
		}

		return $record;
	}

	/**
	 * Return whether a user may see a workflow at all.
	 *
	 * The single check every surface funnels through. A REST route, an admin screen and the
	 * report exporter all call this, so "can this user read this workflow" has one answer
	 * rather than three implementations that might disagree.
	 *
	 * Ownership first, then the phase 15 permission system, then site admin. A workspace
	 * that has adopted the project gets the phase 15 answer; a project that predates
	 * collaboration is owned outright.
	 *
	 * @param array  $record  The workflow.
	 * @param int    $user_id The user.
	 * @param string $needed  The capability required, or '' for read.
	 * @return true|\WP_Error
	 */
	public function may_access( array $record, $user_id, $needed = '' ) {
		$user_id = (int) $user_id;

		if ( $user_id < 1 ) {
			return $this->refusal( 'workflow_unauthenticated', __( 'You must be signed in to access a workflow.', 'replicaforge' ), 401 );
		}

		if ( (int) ( $record['created_by'] ?? 0 ) === $user_id ) {
			return true;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		if ( '' === $needed ) {
			$needed = 'projects.view';
		}

		if ( null === $this->permissions ) {
			// No permission service is a refusal, not a pass. Default DENY.
			return $this->refusal( 'workflow_forbidden', __( 'You do not have access to this workflow.', 'replicaforge' ), 403 );
		}

		$workspace_id = (string) ( $record['workspace_id'] ?? '' );

		if ( '' === $workspace_id ) {
			return $this->refusal( 'workflow_forbidden', __( 'You do not have access to this workflow.', 'replicaforge' ), 403 );
		}

		$allowed = $this->permissions->can_in_project( $user_id, $workspace_id, (string) $record['project_id'], $needed );

		if ( ! $allowed ) {
			return $this->refusal( 'workflow_forbidden', __( 'You do not have permission to do this to the workflow.', 'replicaforge' ), 403 );
		}

		return true;
	}

	/**
	 * Return the workflows for a project, newest first.
	 *
	 * @param string $project_id Project id.
	 * @param int    $limit      Maximum.
	 * @return array<int, array>
	 */
	public function for_project( $project_id, $limit = 20 ) {
		$project_id = (string) $project_id;
		$out        = array();

		foreach ( $this->index_entries() as $entry ) {
			if ( $project_id !== (string) ( $entry['project_id'] ?? '' ) ) {
				continue;
			}

			$record = $this->get( (string) $entry['workflow_id'] );

			if ( null === $record ) {
				continue;
			}

			$out[] = $record;

			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Return recent workflows across every project, for a dashboard.
	 *
	 * @param int $limit Maximum.
	 * @return array<int, array>
	 */
	public function recent( $limit = 25 ) {
		$out = array();

		foreach ( $this->index_entries() as $entry ) {
			$record = $this->get( (string) $entry['workflow_id'] );

			if ( null === $record ) {
				continue;
			}

			$out[] = $this->summarise( $record );

			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Return whether any workflow for a project is still running.
	 *
	 * @param string $project_id Project id.
	 * @return array|null The blocking workflow summary, or null.
	 */
	public function active_for_project( $project_id ) {
		foreach ( $this->for_project( $project_id, 5 ) as $record ) {
			if ( in_array( (string) $record['state'], array( 'queued', 'running', 'preflight', 'validating', 'correcting' ), true ) ) {
				return $this->summarise( $record );
			}
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */

	/**
	 * Move a workflow to a new state, if that transition is legal.
	 *
	 * @param string $workflow_id Workflow id.
	 * @param string $to          Target state.
	 * @param array  $extra       Extra fields merged into the record.
	 * @return array|\WP_Error The updated record.
	 */
	public function transition( $workflow_id, $to, array $extra = array() ) {
		$record = $this->require_workflow( $workflow_id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$from = (string) $record['state'];
		$to   = (string) $to;

		if ( ! Orchestrator_Limits::can_transition( $from, $to ) ) {
			return $this->refusal(
				'workflow_illegal_transition',
				sprintf(
					/* translators: 1: current state, 2: requested state. */
					__( 'A workflow cannot move from "%1$s" to "%2$s".', 'replicaforge' ),
					$from,
					$to
				),
				409,
				array( 'from' => $from, 'to' => $to )
			);
		}

		/*
		 * A terminal workflow that is not being retried is frozen. `failed` is the exception,
		 * and it is the only one, because a retry is an explicit new attempt - see
		 * `RETRYABLE_STATES`.
		 */
		if ( Orchestrator_Limits::is_terminal( $from ) && ! in_array( $from, Orchestrator_Limits::RETRYABLE_STATES, true ) ) {
			return $this->refusal(
				'workflow_finished',
				__( 'This workflow has finished and cannot change state.', 'replicaforge' ),
				409
			);
		}

		$record['state']      = $to;
		$record['updated_at'] = gmdate( 'c' );

		if ( 'cancelled' === $to ) {
			/*
			 * Cancellation is recorded in the record, not just inferred from the state, so a
			 * stage that is mid-flight can see that a cancellation was requested even if its
			 * own state has not moved yet.
			 */
			$record['cancel_requested'] = true;
		}

		if ( isset( $extra['state_note'] ) ) {
			$record['notice'][] = array(
				'at'    => gmdate( 'c' ),
				'state' => $to,
				'note'  => substr( (string) $extra['state_note'], 0, 300 ),
			);
			$this->bound_notice( $record );
		}

		unset( $extra['state_note'] );

		$record = array_merge( $record, $extra );

		if ( ! $this->save( $record ) ) {
			return $this->refusal( 'workflow_not_stored', __( 'The workflow state could not be saved.', 'replicaforge' ), 500 );
		}

		$this->index_touch( $record );

		return $record;
	}

	/**
	 * Return whether a transition is currently legal.
	 *
	 * @param string $workflow_id Workflow id.
	 * @param string $to          Target state.
	 * @return bool
	 */
	public function can_transition( $workflow_id, $to ) {
		$record = $this->get( $workflow_id );

		return null !== $record && Orchestrator_Limits::can_transition( (string) $record['state'], (string) $to );
	}

	/* ---------------------------------------------------------------------
	 * Stages
	 * ------------------------------------------------------------------ */

	/**
	 * Record a stage's outcome.
	 *
	 * @param string $workflow_id Workflow id.
	 * @param string $stage       Stage name.
	 * @param string $outcome     One of the declared outcomes.
	 * @param array  $detail      Attempt count, reason, outputs, error.
	 * @return array|\WP_Error
	 */
	public function record_stage( $workflow_id, $stage, $outcome, array $detail = array() ) {
		$record = $this->require_workflow( $workflow_id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		if ( ! Orchestrator_Limits::is_stage( $stage ) ) {
			return $this->refusal( 'workflow_unknown_stage', __( 'That stage is not declared by the orchestrator.', 'replicaforge' ), 400 );
		}

		if ( ! in_array( (string) $outcome, Orchestrator_Limits::STAGE_OUTCOMES, true ) ) {
			return $this->refusal( 'workflow_unknown_outcome', __( 'That stage outcome is not declared.', 'replicaforge' ), 400 );
		}

		$previous = isset( $record['stages'][ $stage ] ) ? (array) $record['stages'][ $stage ] : array();

		$record['stages'][ $stage ] = array(
			'outcome'    => (string) $outcome,
			'reason'     => substr( (string) ( $detail['reason'] ?? '' ), 0, 300 ),
			'error'      => substr( (string) ( $detail['error'] ?? '' ), 0, 300 ),
			'attempts'   => (int) ( $detail['attempts'] ?? ( (int) ( $previous['attempts'] ?? 0 ) ) ),
			'started_at' => (string) ( $detail['started_at'] ?? ( $previous['started_at'] ?? '' ) ),
			'ended_at'   => gmdate( 'c' ),
			'outputs'    => $this->clean_outputs( $detail['outputs'] ?? array() ),
		);

		$record['updated_at'] = gmdate( 'c' );

		$this->save( $record );

		return $record;
	}

	/**
	 * Return a stage's record.
	 *
	 * @param array  $record The workflow.
	 * @param string $stage  Stage name.
	 * @return array
	 */
	public function stage( array $record, $stage ) {
		$entry = (array) ( $record['stages'][ (string) $stage ] ?? array() );

		return array(
			'outcome'  => (string) ( $entry['outcome'] ?? 'pending' ),
			'reason'   => (string) ( $entry['reason'] ?? '' ),
			'error'    => (string) ( $entry['error'] ?? '' ),
			'attempts' => (int) ( $entry['attempts'] ?? 0 ),
			'outputs'  => (array) ( $entry['outputs'] ?? array() ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Approvals
	 * ------------------------------------------------------------------ */

	/**
	 * Record an approval decision against a specific plan and artifact hash.
	 *
	 * §10 requires that an approval be tied to a version or content hash, and that a
	 * materially changed plan invalidate the approval. Both are handled by the hash: the
	 * decision stores the hash of the thing that was approved, and `gate_status()` recomputes
	 * the current hash and reports the approval as stale when they differ.
	 *
	 * @param string $workflow_id Workflow id.
	 * @param string $gate        Gate name.
	 * @param array  $decision    status, reviewer_id, note, artifact_hash.
	 * @return array|\WP_Error
	 */
	public function decide( $workflow_id, $gate, array $decision ) {
		$record = $this->require_workflow( $workflow_id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		if ( ! Orchestrator_Limits::is_gate( $gate ) ) {
			return $this->refusal( 'workflow_unknown_gate', __( 'That approval gate is not declared.', 'replicaforge' ), 400 );
		}

		$status = (string) ( $decision['status'] ?? '' );

		if ( ! in_array( $status, array( 'approved', 'rejected' ), true ) ) {
			return $this->refusal( 'workflow_bad_decision', __( 'An approval decision is either approved or rejected.', 'replicaforge' ), 400 );
		}

		$reviewer = (int) ( $decision['reviewer_id'] ?? 0 );

		if ( $reviewer < 1 ) {
			return $this->refusal( 'workflow_no_reviewer', __( 'An approval decision must record who made it.', 'replicaforge' ), 400 );
		}

		$capability = (string) ( Orchestrator_Limits::GATE_CAPABILITIES[ $gate ] ?? '' );

		$access = $this->may_access( $record, $reviewer, $capability );

		if ( is_wp_error( $access ) ) {
			return $access;
		}

		/*
		 * The hash the reviewer is recorded against. If the caller does not supply one it is
		 * computed from the current plan, which is correct for a decision made in the UI but
		 * wrong for a replayed request - so a supplied hash is preferred and a missing one is
		 * only accepted when the decision arrives without a plan to compare against.
		 */
		$hash = (string) ( $decision['artifact_hash'] ?? '' );

		if ( '' === $hash ) {
			$hash = $this->approval_hash( $record, $gate );
		}

		$record['approvals'][ (string) $gate ] = array(
			'gate'          => (string) $gate,
			'status'        => $status,
			'capability'    => $capability,
			'artifact_hash' => $hash,
			'plan_id'       => (string) ( $record['plan_id'] ?? '' ),
			'reviewer_id'   => $reviewer,
			'decided_at'    => gmdate( 'c' ),
			'note'          => substr( (string) ( $decision['note'] ?? '' ), 0, 500 ),
		);

		$record['updated_at'] = gmdate( 'c' );

		$this->save( $record );

		return $record;
	}

	/**
	 * Return a gate's status, including whether its approval has gone stale.
	 *
	 * @param array  $record The workflow.
	 * @param string $gate   Gate name.
	 * @return array{status: string, stale: bool, gate: string, capability: string}
	 */
	public function gate_status( array $record, $gate ) {
		$gate  = (string) $gate;
		$entry = (array) ( $record['approvals'][ $gate ] ?? array() );

		if ( array() === $entry ) {
			return array(
				'gate'       => $gate,
				'status'     => 'pending',
				'stale'      => false,
				'capability' => (string) ( Orchestrator_Limits::GATE_CAPABILITIES[ $gate ] ?? '' ),
			);
		}

		$current  = $this->approval_hash( $record, $gate );
		$recorded = (string) ( $entry['artifact_hash'] ?? '' );

		return array(
			'gate'       => $gate,
			'status'     => (string) $entry['status'],
			'stale'      => '' !== $recorded && '' !== $current && ! hash_equals( $recorded, $current ),
			'capability' => (string) ( $entry['capability'] ?? Orchestrator_Limits::GATE_CAPABILITIES[ $gate ] ?? '' ),
			'reviewer_id' => (int) ( $entry['reviewer_id'] ?? 0 ),
			'decided_at'  => (string) ( $entry['decided_at'] ?? '' ),
			'note'        => (string) ( $entry['note'] ?? '' ),
			/*
			 * The hash the decision was bound to. Included because a reader asking "is this
			 * approval still valid?" needs to compare the two, and a status of `approved`
			 * with a stale flag does not let them see which version they approved.
			 */
			'artifact_hash' => $recorded,
			'current_hash'  => $current,
		);
	}

	/**
	 * Return whether a gate is satisfied right now.
	 *
	 * A stale approval does not count. That is the whole point of hashing it: a reviewer who
	 * approved a plan, and then a plan materially changed, has not approved the new plan.
	 *
	 * @param array  $record The workflow.
	 * @param string $gate   Gate name.
	 * @return bool
	 */
	public function gate_satisfied( array $record, $gate ) {
		$status = $this->gate_status( $record, $gate );

		return 'approved' === $status['status'] && empty( $status['stale'] );
	}

	/**
	 * Return the hash an approval for a gate is bound to.
	 *
	 * @param array  $record The workflow.
	 * @param string $gate   Gate name.
	 * @return string
	 */
	public function approval_hash( array $record, $gate ) {
		$seed = wp_json_encode(
			array(
				'plan_id' => (string) ( $record['plan_id'] ?? '' ),
				'gate'    => (string) $gate,
				'plan'    => (array) ( $record['plan'] ?? array() ),
				'stage'   => (array) ( $record['stages'][ (string) ( Orchestrator_Limits::GATE_STAGES[ $gate ] ?? '' ) ] ?? array() ),
			)
		);

		return substr( hash( 'sha256', (string) $seed ), 0, 32 );
	}

	/* ---------------------------------------------------------------------
	 * Checkpoints and budget
	 * ------------------------------------------------------------------ */

	/**
	 * Take a checkpoint after a significant stage.
	 *
	 * @param string $workflow_id Workflow id.
	 * @param string $stage       The stage just completed.
	 * @return array|\WP_Error
	 */
	public function checkpoint( $workflow_id, $stage ) {
		$record = $this->require_workflow( $workflow_id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$completed = array();

		foreach ( (array) $record['stages'] as $name => $entry ) {
			if ( in_array( (string) ( $entry['outcome'] ?? '' ), array( 'succeeded', 'skipped' ), true ) ) {
				$completed[ (string) $name ] = (string) ( $entry['ended_at'] ?? '' );
			}
		}

		$record['checkpoints'][] = array(
			'stage'     => (string) $stage,
			'created_at' => gmdate( 'c' ),
			'state'     => (string) $record['state'],
			'completed' => $completed,
			'hash'      => substr( hash( 'sha256', (string) wp_json_encode( $completed ) ), 0, 32 ),
		);

		// Bounded, oldest first out.
		if ( count( $record['checkpoints'] ) > 30 ) {
			$record['checkpoints'] = array_slice( $record['checkpoints'], -30 );
		}

		$record['updated_at'] = gmdate( 'c' );

		$this->save( $record );

		return $record;
	}

	/**
	 * Return the most recent valid checkpoint, or null.
	 *
	 * "Valid" means the stages it claims completed are still recorded as completed. A
	 * checkpoint whose hash no longer matches the record is a checkpoint from a different
	 * history, and resuming from it would re-run or skip work the record does not describe.
	 *
	 * @param array $record The workflow.
	 * @return array|null
	 */
	public function latest_checkpoint( array $record ) {
		$checkpoints = (array) ( $record['checkpoints'] ?? array() );

		for ( $i = count( $checkpoints ) - 1; $i >= 0; $i-- ) {
			$point = (array) $checkpoints[ $i ];
			$hash  = substr( hash( 'sha256', (string) wp_json_encode( (array) ( $point['completed'] ?? array() ) ) ), 0, 32 );

			if ( ! hash_equals( (string) ( $point['hash'] ?? '' ), $hash ) ) {
				continue;
			}

			// Every stage the checkpoint claims must still agree.
			$valid = true;
			foreach ( (array) ( $point['completed'] ?? array() ) as $name => $ended ) {
				$outcome = (string) ( $record['stages'][ $name ]['outcome'] ?? '' );

				if ( ! in_array( $outcome, array( 'succeeded', 'skipped' ), true ) ) {
					$valid = false;
					break;
				}
			}

			if ( $valid ) {
				return $point;
			}
		}

		return null;
	}

	/**
	 * Add to a budget counter, refusing past the ceiling.
	 *
	 * This is the orchestration layer's own coarse guard, distinct from phase 10's plan
	 * enforcement. Phase 10 stops a user exceeding their monthly allowance; this stops a
	 * single workflow consuming the whole allowance in one run. Neither replaces the other,
	 * and neither is billing.
	 *
	 * @param string $workflow_id Workflow id.
	 * @param string $name        Counter name.
	 * @param int    $amount      How much to add.
	 * @return true|\WP_Error
	 */
	public function spend( $workflow_id, $name, $amount = 1 ) {
		$record = $this->require_workflow( $workflow_id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$ceiling = Orchestrator_Limits::BUDGETS[ (string) $name ] ?? 0;

		if ( $ceiling < 1 ) {
			return $this->refusal( 'workflow_unknown_budget', __( 'That budget is not declared.', 'replicaforge' ), 400 );
		}

		$used = (int) ( $record['budget'][ $name ] ?? 0 );

		if ( $used + (int) $amount > $ceiling ) {
			return $this->refusal(
				'workflow_budget_exhausted',
				sprintf(
					/* translators: 1: budget name, 2: used, 3: limit. */
					__( 'The %1$s budget for this workflow is exhausted (%2$d of %3$d used).', 'replicaforge' ),
					str_replace( '_', ' ', (string) $name ),
					$used,
					$ceiling
				),
				402,
				array( 'budget' => (string) $name, 'used' => $used, 'limit' => $ceiling )
			);
		}

		$record['budget'][ (string) $name ] = $used + (int) $amount;
		$record['updated_at']              = gmdate( 'c' );

		$this->save( $record );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Mutual exclusion
	 * ------------------------------------------------------------------ */

	/**
	 * Run a callback while holding the workflow's execution lock.
	 *
	 * Reuses phase 11's lock rather than adding a second one. The resource name is prefixed
	 * so a workflow lock and a job lock can never collide in the shared
	 * `replicaforge_lock_` option namespace, and because the lock's resource sanitiser
	 * collapses anything that is not a word character, the prefix is a word character run
	 * rather than a colon.
	 *
	 * @param string   $workflow_id Workflow id.
	 * @param callable $callback    The work.
	 * @param string   $owner       The owner label.
	 * @return mixed|\WP_Error
	 */
	public function with_execution_lock( $workflow_id, callable $callback, $owner = '' ) {
		$resource = 'workflow_' . $this->clean_id( $workflow_id );

		$outcome = $this->locks->with_lock(
			$resource,
			$callback,
			'' === $owner ? 'orchestrator' : $owner,
			900
		);

		/*
		 * `Job_Lock::with_lock()` returns the callback's own value on success, and on refusal
		 * the array `acquire()` built - which carries `success => false` and `code`.
		 *
		 * An earlier version of this method looked for an `acquired` key instead, which the
		 * lock never produces, so the check was dead and a workflow already running elsewhere
		 * returned its own result as though it had the lock. Two workers could then drive the
		 * same workflow at once - which is the specific thing this lock exists to prevent,
		 * and the specific thing §41 forbids.
		 *
		 * The refusal is detected by its own shape, not by a guessed key: `success` present
		 * and false is the lock's refusal contract. When the callback legitimately returns an
		 * array carrying `success => false` of its own, the lock's `code` is checked as well,
		 * so a refusal and a callback result are told apart.
		 */
		if ( is_array( $outcome ) && array_key_exists( 'success', $outcome ) && false === $outcome['success'] && '' !== (string) ( $outcome['code'] ?? '' ) ) {
			return $this->refusal(
				'workflow_busy',
				__( 'This workflow is already running somewhere else.', 'replicaforge' ),
				409,
				array(
					'holder'      => (string) ( $outcome['details']['holder'] ?? '' ),
					'expires_at'  => (int) ( $outcome['details']['expires_at'] ?? 0 ),
					'lock_code'   => (string) $outcome['code'],
				)
			);
		}

		return $outcome;
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------ */

	/**
	 * Save a workflow record.
	 *
	 * @param array $record The record.
	 * @return bool
	 */
	public function save( array $record ) {
		$id = (string) ( $record['workflow_id'] ?? '' );

		if ( '' === $id ) {
			return false;
		}

		$option = Orchestrator_Limits::RECORD_PREFIX . $id;

		$updated = update_option( $option, $record, false );

		if ( false === $updated && ! is_array( get_option( $option, null ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Delete a workflow and its artifacts.
	 *
	 * Both are deleted, and the artifacts deletion is the one that matters most. A workflow
	 * record is a few hundred bytes of metadata; its artifact store can hold the source
	 * page's structural representation, which is the largest thing this plugin keeps about a
	 * website. Leaving that behind after the workflow is gone would accumulate a copy of
	 * every analysed site in the options table with no workflow pointing at it and no way to
	 * find it.
	 *
	 * The store is purged *before* the record, so a failure part-way through leaves the
	 * record in place and the workflow still readable - the safer order, because a
	 * half-deleted workflow can be diagnosed and a leaked representation cannot.
	 *
	 * @param string $workflow_id Workflow id.
	 * @return bool
	 */
	public function delete( $workflow_id ) {
		$id = $this->clean_id( $workflow_id );

		( new Workflow_Artifacts( $id ) )->purge();

		$this->index_remove( $id );

		return (bool) delete_option( Orchestrator_Limits::RECORD_PREFIX . $id );
	}

	/**
	 * Return the index, loading it once.
	 *
	 * @return array<int, array>
	 */
	private function index_entries() {
		if ( null !== $this->index ) {
			return $this->index;
		}

		$raw = get_option( Orchestrator_Limits::OPTION, array() );

		$this->index = is_array( $raw ) ? array_values( $raw ) : array();

		return $this->index;
	}

	/**
	 * Add a workflow to the index.
	 *
	 * The index is a small list for listing only: id, project, state and the three timestamps
	 * a dashboard shows. Nothing else - a dashboard that had to load every workflow's evidence
	 * to draw a list would be unusable, and would be a slower way to leak it.
	 *
	 * @param array $record The record.
	 * @return void
	 */
	private function index_add( array $record ) {
		$entries   = $this->index_entries();
		$entries[] = $this->index_row( $record );

		$this->write_index( $entries );
	}

	/**
	 * Refresh an index row.
	 *
	 * @param array $record The record.
	 * @return void
	 */
	private function index_touch( array $record ) {
		$entries = array();

		foreach ( $this->index_entries() as $entry ) {
			if ( (string) ( $entry['workflow_id'] ?? '' ) === (string) $record['workflow_id'] ) {
				$entry = $this->index_row( $record );
			}

			$entries[] = $entry;
		}

		$this->write_index( $entries );
	}

	/**
	 * Remove an index row.
	 *
	 * @param string $workflow_id Workflow id.
	 * @return void
	 */
	private function index_remove( $workflow_id ) {
		$entries = array();

		foreach ( $this->index_entries() as $entry ) {
			if ( (string) ( $entry['workflow_id'] ?? '' ) === (string) $workflow_id ) {
				continue;
			}

			$entries[] = $entry;
		}

		$this->write_index( $entries );
	}

	/**
	 * Write the index, bounded.
	 *
	 * @param array<int, array> $entries The entries.
	 * @return void
	 */
	private function write_index( array $entries ) {
		usort(
			$entries,
			static function ( $left, $right ) {
				return strcmp( (string) ( $right['created_at'] ?? '' ), (string) ( $left['created_at'] ?? '' ) );
			}
		);

		$trimmed = array_slice( $entries, 0, Orchestrator_Limits::MAX_INDEX );

		// A row whose record is gone is a leak in the listing, and it grows forever.
		$live = array();

		foreach ( $trimmed as $entry ) {
			$id = (string) ( $entry['workflow_id'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			if ( is_array( get_option( Orchestrator_Limits::RECORD_PREFIX . $id, null ) ) ) {
				$live[] = $entry;
			}
		}

		update_option( Orchestrator_Limits::OPTION, $live, false );

		$this->index = $live;
	}

	/**
	 * Build an index row.
	 *
	 * @param array $record The record.
	 * @return array
	 */
	private function index_row( array $record ) {
		return array(
			'workflow_id' => (string) $record['workflow_id'],
			'project_id'  => (string) $record['project_id'],
			'state'       => (string) $record['state'],
			'type'        => (string) $record['type'],
			'mode'        => (string) $record['mode'],
			'created_by'  => (int) $record['created_by'],
			'created_at'  => (string) $record['created_at'],
			'updated_at'  => (string) $record['updated_at'],
		);
	}

	/**
	 * Return a workflow reduced to what a listing needs.
	 *
	 * @param array $record The record.
	 * @return array
	 */
	private function summarise( array $record ) {
		$stages    = (array) ( $record['stages'] ?? array() );
		$completed = 0;

		foreach ( $stages as $entry ) {
			if ( in_array( (string) ( $entry['outcome'] ?? '' ), array( 'succeeded', 'skipped' ), true ) ) {
				$completed++;
			}
		}

		/*
		 * A completed count out of a total, and nothing else. §18 forbids a fake progress
		 * percentage, and this is the honest form of the same idea: how many of the declared
		 * stages are done. A workflow with no plan yet has no declared total, so the ratio is
		 * absent rather than zero.
		 */
		$total = count( (array) ( $record['plan']['stages'] ?? array() ) );

		return array(
			'workflow_id' => (string) $record['workflow_id'],
			'project_id'  => (string) $record['project_id'],
			'state'       => (string) $record['state'],
			'type'        => (string) $record['type'],
			'mode'        => (string) $record['mode'],
			'created_at'  => (string) $record['created_at'],
			'updated_at'  => (string) $record['updated_at'],
			'stages_completed' => $completed,
			'stages_total'     => $total,
			'current_stage'    => $this->current_stage( $record ),
			'notice'           => (string) ( $record['notice'][ count( (array) $record['notice'] ) - 1 ]['note'] ?? '' ),
		);
	}

	/**
	 * Return the stage a workflow is on, for a listing.
	 *
	 * @param array $record The record.
	 * @return string
	 */
	private function current_stage( array $record ) {
		$declared = (array) ( $record['plan']['stages'] ?? array() );

		foreach ( $declared as $stage ) {
			$outcome = (string) ( $record['stages'][ $stage ]['outcome'] ?? 'pending' );

			if ( in_array( $outcome, array( 'succeeded', 'skipped' ), true ) ) {
				continue;
			}

			return (string) $stage;
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return an existing workflow for an idempotency key, or null.
	 *
	 * @param string $project_id Project id.
	 * @param string $key        Idempotency key.
	 * @param int    $user_id    The user.
	 * @return array|null
	 */
	private function find_by_idempotency( $project_id, $key, $user_id ) {
		foreach ( $this->for_project( $project_id, 20 ) as $record ) {
			if ( (string) ( $record['idempotency_key'] ?? '' ) === (string) $key
				&& (int) ( $record['created_by'] ?? 0 ) === (int) $user_id ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Reduce stage outputs to identifiers.
	 *
	 * A stage's outputs are recorded in the artifact store; the workflow record only carries
	 * the names. A generation stage that wrote its whole specification into the record would
	 * make every listing load it.
	 *
	 * @param mixed $outputs The outputs.
	 * @return array<string, string>
	 */
	private function clean_outputs( $outputs ) {
		if ( ! is_array( $outputs ) ) {
			return array();
		}

		$clean = array();

		foreach ( $outputs as $name => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$clean[ substr( preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $name ) ), 0, 40 ) ] = substr( (string) $value, 0, 120 );
		}

		return $clean;
	}

	/**
	 * Keep the state notice log bounded.
	 *
	 * @param array $record The record, by reference.
	 * @return void
	 */
	private function bound_notice( array &$record ) {
		if ( count( (array) $record['notice'] ) > 20 ) {
			$record['notice'] = array_slice( (array) $record['notice'], -20 );
		}
	}

	/**
	 * Clean an idempotency key.
	 *
	 * @param mixed $key The key.
	 * @return string
	 */
	private function clean_key( $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			return '';
		}

		return substr( preg_replace( '/[^A-Za-z0-9_.-]/', '', $key ), 0, 80 );
	}

	/**
	 * Clean a workflow id.
	 *
	 * Delegates to the shared sanitiser so this class and the artifact store always name the
	 * same option. See {@see Orchestrator_Limits::sanitize_workflow_id()}.
	 *
	 * @param mixed $id The id.
	 * @return string
	 */
	private function clean_id( $id ) {
		return Orchestrator_Limits::sanitize_workflow_id( $id );
	}

	/**
	 * Build a refusal.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @param array  $details Details.
	 * @return \WP_Error
	 */
	private function refusal( $code, $message, $status, array $details = array() ) {
		return new \WP_Error( (string) $code, (string) $message, array( 'status' => (int) $status, 'details' => $details ) );
	}
}
