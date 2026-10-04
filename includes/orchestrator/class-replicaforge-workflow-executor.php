<?php
/**
 * Phase 17: the stage executor.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a workflow's stages, in dependency order, against the real services.
 *
 * ### The one rule everything else serves
 *
 * **A stage is not successful because a service did not error.** It is successful because
 * its declared output exists. `expected_outputs()` in the definition declares what each
 * stage produces; after the call, {@see self::verify_outputs()} checks that what arrived
 * matches. A fetch that returns an error page, a generation that produces zero elements, an
 * analysis that yields no sections - all of those return normally, and all of them would
 * otherwise be recorded as success and let the workflow claim a reconstruction it did not
 * make.
 *
 * ### Idempotency, in the sense that matters here
 *
 * Two things must not happen twice: a second draft for the same source, and a second
 * correction for the same difference. Both are prevented by looking before doing rather than
 * by a lock. A stage whose output is already in the artifact store is recorded as
 * `succeeded` with the existing output and is not re-run. That is what makes a resume after
 * an interruption safe, and it is also what makes a retry of a failed stage not re-do the
 * stages before it.
 *
 * ### What this class does not do
 *
 * It does not fetch, analyse, generate, validate or correct anything itself. Every stage
 * body calls an existing phase's public method, and if that phase is unavailable the stage
 * is skipped with a reason. There is no second crawler, no second renderer and no second
 * correction engine here, because a second one would be a second set of bugs.
 */
final class Workflow_Executor {

	/**
	 * The repository.
	 *
	 * @var Workflow_Repository
	 */
	private $workflows;

	/**
	 * The capability registry.
	 *
	 * @var Capability_Registry
	 */
	private $capabilities;

	/**
	 * The artifact store for the workflow being run.
	 *
	 * @var Workflow_Artifacts
	 */
	private $artifacts;

	/**
	 * The entitlement manager, for reserving and settling plan usage.
	 *
	 * @var Entitlement_Manager|null
	 */
	private $entitlements;

	/**
	 * The service instances, built lazily and memoised.
	 *
	 * @var array<string, mixed>
	 */
	private $services = array();

	/**
	 * Open reservations, keyed by stage, so a stage that fails releases what it reserved.
	 *
	 * @var array<string, string>
	 */
	private $reservations = array();

	/**
	 * The user the current run belongs to.
	 *
	 * Remembered because a release has to name the user whose reservation is being given
	 * back. An earlier version passed `0`, which the entitlement manager reads as "not
	 * authenticated" - so the release would be refused and the reservation would sit open
	 * until its two-hour expiry, counting against the user's quota the whole time.
	 *
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * Build the executor.
	 *
	 * @param Workflow_Repository|null     $workflows    Repository.
	 * @param Capability_Registry|null    $capabilities Registry.
	 * @param Workflow_Artifacts|null     $artifacts    Artifacts.
	 * @param Entitlement_Manager|null    $entitlements Entitlements.
	 */
	public function __construct( $workflows = null, $capabilities = null, $artifacts = null, $entitlements = null ) {
		$this->workflows    = ( $workflows instanceof Workflow_Repository ) ? $workflows : new Workflow_Repository();
		$this->capabilities = ( $capabilities instanceof Capability_Registry ) ? $capabilities : new Capability_Registry();
		$this->artifacts    = ( $artifacts instanceof Workflow_Artifacts ) ? $artifacts : new Workflow_Artifacts( '' );

		/*
		 * Phase 10's entitlement manager is constructed here when the caller does not supply
		 * one, so metering is on by default rather than silently off.
		 *
		 * That default matters. `Job_Manager::admit()` - the only other thing in the plugin
		 * that reserves usage - has no caller in `includes/` today, and the phase 1-6 REST
		 * routes never consult an entitlement manager at all, so without this line a
		 * reconstruction would be entirely unmetered on a real install. Reusing the existing
		 * service is the right call; the fact that the rest of the plugin does not use it yet
		 * is a Phase 10 gap, and this layer closing it for its own path is the honest
		 * contribution rather than a second accounting system.
		 */
		if ( $entitlements instanceof Entitlement_Manager ) {
			$this->entitlements = $entitlements;
		} elseif ( class_exists( 'ReplicaForge\\Entitlement_Manager' ) ) {
			$this->entitlements = new Entitlement_Manager();
		}
	}

	/* ---------------------------------------------------------------------
	 * The run loop
	 * ------------------------------------------------------------------ */

	/**
	 * Run a workflow as far as it can go.
	 *
	 * Returns a report rather than throwing, and the report says how far it got. A workflow
	 * that stops at an approval gate and a workflow that fails are different outcomes and the
	 * caller has to be able to tell them apart without inspecting a stage record.
	 *
	 * @param string $workflow_id Workflow id.
	 * @param int    $user_id     The user running it.
	 * @param array  $options     `from_stage` to resume mid-graph.
	 * @return array|\WP_Error
	 */
	public function run( $workflow_id, $user_id, array $options = array() ) {
		$record = $this->workflows->require_workflow( $workflow_id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$access = $this->workflows->may_access( $record, $user_id, 'analysis.run' );

		if ( is_wp_error( $access ) ) {
			return $access;
		}

		// The run loop and the mutation path both take the lock, so a second request cannot
		// interleave with this one. The check happens before any state change, not after.
		return $this->workflows->with_execution_lock(
			(string) $record['workflow_id'],
			function () use ( $record, $user_id, $options ) {
				return $this->run_locked( $record, (int) $user_id, $options );
			},
			'user_' . (int) $user_id
		);
	}

	/**
	 * Run the loop, holding the lock.
	 *
	 * @param array $record  The workflow.
	 * @param int   $user_id The user.
	 * @param array $options Options.
	 * @return array
	 */
	private function run_locked( array $record, $user_id, array $options ) {
		$workflow_id = (string) $record['workflow_id'];

		$this->user_id = (int) $user_id;

		// A fresh view, because the lock may have been held long enough for the record to move.
		$record = $this->workflows->require_workflow( $workflow_id );

		if ( is_wp_error( $record ) ) {
			return array( 'outcome' => 'error', 'code' => 'workflow_lost' );
		}

		if ( ! empty( $record['cancel_requested'] ) ) {
			return $this->stop( $record, 'cancelled' );
		}

		/*
		 * The plan is the executor's map. Without one there is nothing to run, and starting
		 * from a stage list would mean guessing which stages apply to which type.
		 */
		if ( empty( $record['plan'] ) || empty( $record['plan']['stages'] ) ) {
			$built = $this->build_plan( $record );

			if ( is_wp_error( $built ) ) {
				return array( 'outcome' => 'error', 'code' => $built->get_error_code() );
			}

			$record = $built;
		}

		$stages = (array) $record['plan']['stages'];

		if ( ! empty( $options['from_stage'] ) ) {
			$from     = (string) $options['from_stage'];
			$position = array_search( $from, $stages, true );

			if ( false === $position ) {
				return array( 'outcome' => 'error', 'code' => 'workflow_unknown_stage' );
			}

			$stages = array_slice( $stages, (int) $position );
		}

		$this->move( $record, 'queued' );
		$this->move( $record, 'running' );

		$record = $this->workflows->require_workflow( $workflow_id );
		$expected = (array) ( $record['plan']['expected_outputs'] ?? array() );

		foreach ( $stages as $stage ) {
			$record = $this->workflows->require_workflow( $workflow_id );

			if ( is_wp_error( $record ) ) {
				return array( 'outcome' => 'error', 'code' => 'workflow_lost' );
			}

			// Cancellation is checked between stages, not only at the end. A cancelled
			// workflow must not start new work, and the only place that is knowable is here.
			if ( ! empty( $record['cancel_requested'] ) ) {
				return $this->stop( $record, 'cancelled' );
			}

			$decision = $this->should_run( $record, $stage );

			if ( 'skip' === $decision['verdict'] ) {
				$this->workflows->record_stage( $workflow_id, $stage, 'skipped', array( 'reason' => $decision['reason'] ) );
				$this->workflows->checkpoint( $workflow_id, $stage );
				continue;
			}

			if ( 'blocked' === $decision['verdict'] ) {
				$this->workflows->record_stage( $workflow_id, $stage, 'blocked', array( 'reason' => $decision['reason'] ) );

				/*
				 * A blocked stage blocks its dependents, and the reason is carried forward
				 * rather than replaced. If generation is unavailable, validation is not
				 * "also blocked because of validation" - it is blocked because generation did
				 * not happen, and a reader needs to know which.
				 */
				$this->workflows->checkpoint( $workflow_id, $stage );
				continue;
			}

			if ( 'wait' === $decision['verdict'] ) {
				$this->workflows->record_stage( $workflow_id, $stage, 'pending', array( 'reason' => $decision['reason'] ) );
				$this->move( $record, 'waiting_approval' );

				return array(
					'outcome'  => 'waiting_approval',
					'stage'    => $stage,
					'gate'     => $decision['gate'],
					'reason'   => $decision['reason'],
					'workflow' => $this->workflows->require_workflow( $workflow_id ),
				);
			}

			$result = $this->run_stage( $record, $stage, $user_id );

			if ( 'retry' === $result['verdict'] ) {
				$this->workflows->record_stage(
					$workflow_id,
					$stage,
					'pending',
					array( 'reason' => $result['reason'], 'error' => $result['error'], 'attempts' => (int) $result['attempts'] )
				);
				$this->move( $record, 'paused' );

				return array(
					'outcome'  => 'paused',
					'stage'    => $stage,
					'reason'   => $result['reason'],
					'error'    => $result['error'],
					'attempts' => (int) $result['attempts'],
					'workflow' => $this->workflows->require_workflow( $workflow_id ),
				);
			}

			$this->workflows->record_stage(
				$workflow_id,
				$stage,
				$result['verdict'],
				array(
					'reason'   => $result['reason'],
					'error'    => $result['error'],
					'attempts' => (int) $result['attempts'],
					'outputs'  => $result['outputs'],
				)
			);

			$this->workflows->checkpoint( $workflow_id, $stage );

			if ( 'failed' === $result['verdict'] ) {
				$this->release_all();
				$this->move( $record, 'failed' );

				return array(
					'outcome'  => 'failed',
					'stage'    => $stage,
					'reason'   => $result['reason'],
					'error'    => $result['error'],
					'workflow' => $this->workflows->require_workflow( $workflow_id ),
				);
			}

			// Verification happens after the outcome is known, because a stage that ran and
			// produced nothing is a failure, not a success with a note.
			$verdict = $this->verify_outputs( $stage, (array) $expected[ $stage ] ?? array(), (array) $result['outputs'] );

			if ( 'failed' === $verdict['verdict'] ) {
				$this->workflows->record_stage( $workflow_id, $stage, 'failed', array( 'reason' => $verdict['reason'] ) );
				$this->move( $record, 'failed' );

				return array(
					'outcome'  => 'failed',
					'stage'    => $stage,
					'reason'   => $verdict['reason'],
					'workflow' => $this->workflows->require_workflow( $workflow_id ),
				);
			}
		}

		$this->release_all();

		$record = $this->workflows->require_workflow( $workflow_id );
		$gate   = new Quality_Gate( $this->capabilities );
		$final  = $gate->evaluate( $record, $this->artifacts );

		$this->workflows->record_stage( $workflow_id, 'final_review', 'succeeded', array( 'outputs' => array( 'final_status' => $final['status'] ) ) );
		$this->artifacts->put( 'quality_gate', 'quality_gate', $final, array( 'source' => 'orchestrator', 'schema_version' => Orchestrator_Limits::SCHEMA_VERSION ) );

		$state = in_array( $final['status'], array( 'passed', 'passed_with_warnings' ), true ) ? 'completed' : 'final_review';

		if ( 'failed' === $final['status'] ) {
			$state = 'failed';
		}

		$record = $this->move( $workflow_id, $state, array( 'result' => $final ) );

		$this->workflows->record_stage( $workflow_id, 'completion', 'succeeded', array( 'outputs' => array( 'final_status' => $final['status'] ) ) );

		return array(
			'outcome'      => 'finished',
			'final_status' => $final['status'],
			'workflow'     => is_wp_error( $record ) ? $this->workflows->require_workflow( $workflow_id ) : $record,
		);
	}

	/* ---------------------------------------------------------------------
	 * Stage eligibility
	 * ------------------------------------------------------------------ */

	/**
	 * Decide whether a stage may run, and why not if it may not.
	 *
	 * Three ways a stage does not run, kept apart because they mean different things:
	 *
	 * - **skip** - the workflow type does not need it. A decision.
	 * - **blocked** - a prerequisite did not succeed, or the capability is missing. A
	 *   circumstance, and a different one in each case.
	 * - **wait** - an approval gate is unsatisfied. A human is needed.
	 *
	 * Collapsing these into one "not run" would make a report unable to say whether the
	 * workflow chose not to do something or was unable to.
	 *
	 * @param array  $record The workflow.
	 * @param string $stage  Stage name.
	 * @return array{verdict: string, reason: string, gate: string}
	 */
	private function should_run( array $record, $stage ) {
		$definition = isset( $record['plan'] ) ? (array) $record['plan'] : array();

		// 1. The type does not need it.
		foreach ( (array) ( $definition['not_required'] ?? array() ) as $entry ) {
			if ( (string) ( $entry['stage'] ?? '' ) === $stage ) {
				return array(
					'verdict' => 'skip',
					'reason'  => (string) ( $entry['reason'] ?? '' ),
					'gate'    => '',
				);
			}
		}

		// 2. A prerequisite has not succeeded. Skipped is not succeeded: a stage whose
		//    prerequisite was skipped did not get what it needed.
		foreach ( (array) ( Orchestrator_Limits::STAGE_DEPENDENCIES[ $stage ] ?? array() ) as $dependency ) {
			if ( ! in_array( (string) $dependency, (array) ( $definition['stages'] ?? array() ), true ) ) {
				continue;
			}

			$outcome = (string) ( $record['stages'][ $dependency ]['outcome'] ?? 'pending' );

			if ( 'succeeded' === $outcome ) {
				continue;
			}

			if ( 'skipped' === $outcome ) {
				return array(
					'verdict' => 'blocked',
					'reason'  => sprintf(
						/* translators: 1: stage name, 2: its prerequisite. */
						__( 'Not run because it requires "%2$s", which was not needed for this workflow type, and a stage that did not run has not succeeded.', 'replicaforge' ),
						(string) $stage,
						(string) $dependency
					),
					'gate'    => '',
				);
			}

			return array(
				'verdict' => 'blocked',
				'reason'  => sprintf(
					/* translators: 1: stage, 2: its prerequisite, 3: that prerequisite's outcome. */
					__( 'Not run because "%1$s" requires "%2$s", which ended as "%3$s".', 'replicaforge' ),
					(string) $stage,
					(string) $dependency,
					$outcome
				),
				'gate'    => '',
			);
		}

		// 3. The capability is absent.
		$capability = (string) ( Orchestrator_Limits::STAGE_CAPABILITIES[ $stage ] ?? 'core' );
		$probe      = $this->capabilities->get( $capability );

		if ( 'unavailable' === $probe['status'] ) {
			return array(
				'verdict' => 'blocked',
				'reason'  => (string) $probe['reason'],
				'gate'    => '',
			);
		}

		// 4. An approval gate sits here and is unsatisfied.
		foreach ( (array) ( $definition['approval_checkpoints'] ?? array() ) as $checkpoint ) {
			if ( (string) ( $checkpoint['stage'] ?? '' ) !== $stage ) {
				continue;
			}

			$gate = (string) ( $checkpoint['gate'] ?? '' );

			// A gate with no authorisation requirement is not a gate.
			if ( '' === (string) ( $checkpoint['capability'] ?? '' ) ) {
				continue;
			}

			if ( $this->workflows->gate_satisfied( $record, $gate ) ) {
				continue;
			}

			$status = $this->workflows->gate_status( $record, $gate );

			if ( 'approved' === $status['status'] && ! empty( $status['stale'] ) ) {
				return array(
					'verdict' => 'wait',
					'reason'  => sprintf(
						/* translators: 1: gate name, 2: capability. */
						__( 'The plan changed after it was approved, so the "%1$s" approval is no longer valid and a new decision is needed.', 'replicaforge' ),
						$gate
					),
					'gate'    => $gate,
				);
			}

			if ( 'rejected' === $status['status'] ) {
				return array(
					'verdict' => 'blocked',
					'reason'  => sprintf(
						/* translators: %s: gate name. */
						__( 'The "%s" approval was rejected.', 'replicaforge' ),
						$gate
					),
					'gate'    => $gate,
				);
			}

			return array(
				'verdict' => 'wait',
				'reason'  => sprintf(
					/* translators: 1: gate name, 2: capability. */
					__( 'Waiting for the "%1$s" approval, which requires the "%2$s" capability.', 'replicaforge' ),
					$gate,
					(string) ( $checkpoint['capability'] ?? '' )
				),
				'gate'    => $gate,
			);
		}

		return array( 'verdict' => 'run', 'reason' => '', 'gate' => '' );
	}

	/* ---------------------------------------------------------------------
	 * Stage execution
	 * ------------------------------------------------------------------ */

	/**
	 * Run one stage, with bounded retries.
	 *
	 * @param array  $record  The workflow.
	 * @param string $stage   Stage name.
	 * @param int    $user_id The user.
	 * @return array{verdict: string, reason: string, error: string, outputs: array, attempts: int}
	 */
	private function run_stage( array $record, $stage, $user_id ) {
		$max      = (int) Orchestrator_Limits::BUDGETS['max_attempts'];
		$previous = $this->workflows->stage( $record, $stage );
		$attempts = (int) $previous['attempts'];

		$last_error   = '';
		$last_reason  = '';

		while ( $attempts < $max ) {
			$attempts++;

			$outcome = $this->dispatch( $record, $stage, $user_id );

			if ( 'succeeded' === $outcome['verdict'] ) {
				return array(
					'verdict'  => 'succeeded',
					'reason'   => (string) ( $outcome['reason'] ?? '' ),
					'error'    => '',
					'outputs'  => (array) ( $outcome['outputs'] ?? array() ),
					'attempts' => $attempts,
				);
			}

			$last_error  = (string) ( $outcome['error'] ?? '' );
			$last_reason = (string) ( $outcome['reason'] ?? '' );

			/*
			 * A permanent failure is not retried. Retrying a refused URL or a missing plugin
			 * three times produces three identical log lines and a slower failure, and it
			 * spends plan quota on work that cannot succeed.
			 */
			if ( ! empty( $outcome['permanent'] ) ) {
				return array(
					'verdict'  => 'failed',
					'reason'   => $last_reason,
					'error'    => $last_error,
					'outputs'  => array(),
					'attempts' => $attempts,
				);
			}

			// Back off a little between attempts so a rate-limited provider gets a moment.
			if ( $attempts < $max ) {
				sleep( min( 2, $attempts ) );
			}
		}

		return array(
			'verdict'  => 'retry',
			'reason'   => sprintf(
				/* translators: 1: stage, 2: attempt count. */
				__( 'The stage "%1$s" did not succeed after %2$d attempts. It is paused so the failure can be reviewed and retried.', 'replicaforge' ),
				$stage,
				$attempts
			),
			'error'    => $last_error,
			'outputs'  => array(),
			'attempts' => $attempts,
		);
	}

	/**
	 * Route a stage to its implementation.
	 *
	 * @param array  $record  The workflow.
	 * @param string $stage   Stage name.
	 * @param int    $user_id The user.
	 * @return array
	 */
	private function dispatch( array $record, $stage, $user_id ) {
		try {
			switch ( $stage ) {
				case 'preflight':
					return $this->stage_preflight( $record, $user_id );
				case 'planning':
					return $this->stage_planning( $record );
				case 'analysis':
					return $this->stage_analysis( $record );
				case 'site_architecture':
					return $this->stage_site_architecture( $record );
				case 'sync':
					return $this->stage_sync( $record );
				case 'intelligence':
					return $this->stage_intelligence( $record );
				case 'specification':
					return $this->stage_specification( $record );
				case 'approval_plan':
				case 'approval_draft':
					// Gates that have already been satisfied by the time the stage runs. The
					// decision happened in `should_run`; this stage only records that it did.
					return $this->stage_gate_passed( $record, $stage );
				case 'generation':
					return $this->stage_generation( $record, $user_id );
				case 'render':
					return $this->stage_render( $record );
				case 'validation':
					return $this->stage_validation( $record, $user_id );
				case 'interactions':
					return $this->stage_interactions( $record );
				case 'corrections':
					return $this->stage_corrections( $record, $user_id );
				case 'regression':
					return $this->stage_regression( $record, $user_id );
				case 'final_review':
					return $this->stage_final_review( $record );
				case 'completion':
					return $this->stage_completion( $record );
				default:
					return $this->failed( __( 'That stage has no implementation.', 'replicaforge' ), 'orchestrator_stage_unimplemented' );
			}
		} catch ( \Throwable $e ) {
			/*
			 * A throw is a failure of that stage, not of the workflow. It is caught here
			 * because an uncaught throw from one stage would abandon the run with the
			 * workflow still marked `running`, and nothing would ever be able to resume it.
			 */
			return array(
				'verdict' => 'failed',
				'reason'  => __( 'The stage raised an unexpected error.', 'replicaforge' ),
				'error'   => $this->safe_error( $e ),
				'outputs' => array(),
				'permanent' => true,
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * The stages
	 * ------------------------------------------------------------------ */

	/**
	 * Preflight: run the assessment and store it.
	 *
	 * @param array $record  The workflow.
	 * @param int   $user_id The user.
	 * @return array
	 */
	private function stage_preflight( array $record, $user_id ) {
		$preflight = new Preflight_Assessment( $this->capabilities, null, (int) $user_id );

		$report = $preflight->assess(
			array(
				'project_id' => (string) $record['project_id'],
				'source_url' => (string) $record['source_url'],
				'type'       => (string) $record['type'],
				'mode'       => (string) $record['mode'],
			)
		);

		$stored = $this->artifacts->put( 'preflight', 'preflight', $report, array( 'source' => 'orchestrator.preflight' ) );

		if ( is_wp_error( $stored ) ) {
			return $this->failed( (string) $stored->get_error_message(), (string) $stored->get_error_code(), false );
		}

		// A preflight that cannot proceed is a permanent failure for this workflow. Retrying
		// a refused source URL or an absent Elementor produces the same result.
		if ( empty( $report['can_proceed'] ) ) {
			return array(
				'verdict'   => 'failed',
				'reason'    => (string) $report['summary'],
				'error'     => (string) $report['summary'],
				'outputs'   => array( 'can_proceed' => 'no' ),
				'permanent' => true,
			);
		}

		return $this->succeeded(
			array( 'can_proceed' => 'yes', 'warnings' => (string) $report['counts']['warn'] ),
			'' === (string) $report['counts']['warn'] ? '' : (string) $report['summary']
		);
	}

	/**
	 * Planning: build the definition and the plan document.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function stage_planning( array $record ) {
		$built = $this->build_plan( $record );

		if ( is_wp_error( $built ) ) {
			return $this->failed( (string) $built->get_error_message(), (string) $built->get_error_code() );
		}

		// The definition was validated by `create()`; the plan document is what the user
		// reviews, and it is stored so the approval is bound to a real artifact.
		$plan = $built['plan'];

		$stored = $this->artifacts->put( 'plan', 'plan', $plan, array( 'source' => 'orchestrator.planner' ) );

		if ( is_wp_error( $stored ) ) {
			return $this->failed( (string) $stored->get_error_message(), (string) $stored->get_error_code(), false );
		}

		return $this->succeeded(
			array( 'plan_id' => (string) $plan['plan_id'], 'type' => (string) $plan['type'], 'mode' => (string) $plan['mode'] )
		);
	}

	/**
	 * Analysis: fetch the source and produce a phase 2 representation.
	 *
	 * This is where the representation that the audit found had no home is given one.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function stage_analysis( array $record ) {
		$url = (string) $record['source_url'];

		// The URL is validated again here even though preflight validated it. The gap
		// between the two is a user editing the source, and a workflow must not fetch
		// whatever the record currently holds without re-asking the validator.
		$verdict = ( new Url_Validator() )->validate( $url );

		if ( empty( $verdict['success'] ) ) {
			$error = (array) ( $verdict['error'] ?? array() );

			return array(
				'verdict'    => 'failed',
				'reason'     => (string) ( $error['message'] ?? __( 'The source URL was refused.', 'replicaforge' ) ),
				'error'      => (string) ( $error['code'] ?? 'source_refused' ),
				'outputs'    => array(),
				'permanent'  => true,
			);
		}

		$client = $this->service( 'http', fn() => new Http_Client() );
		$fetch  = $client->fetch( $url );

		if ( is_wp_error( $fetch ) || empty( $fetch['success'] ) ) {
			$code = is_wp_error( $fetch ) ? (string) $fetch->get_error_code() : (string) ( $fetch['error']['code'] ?? 'fetch_failed' );
			$text = is_wp_error( $fetch ) ? (string) $fetch->get_error_message() : (string) ( $fetch['error']['message'] ?? __( 'The source page could not be fetched.', 'replicaforge' ) );

			return array(
				'verdict'   => 'failed',
				'reason'    => $text,
				'error'     => $code,
				'outputs'   => array(),
				/*
				 * A 4xx will not become a 2xx on a retry. A 5xx or a timeout will, so only the
				 * client-side failures are marked permanent.
				 */
				'permanent' => 0 === strpos( $code, 'source_refused' ) || false !== strpos( $code, 'not_found' ) || false !== strpos( $code, 'forbidden' ),
			);
		}

		$html = (string) ( $fetch['body'] ?? '' );

		if ( '' === trim( $html ) ) {
			return $this->failed( __( 'The source page returned no content.', 'replicaforge' ), 'empty_source', true );
		}

		$analyzer = $this->service( 'dom', fn() => new Dom_Analyzer() );
		$built    = $analyzer->build( $html, (string) $verdict['url'] );

		$representation = $this->extract_representation( $built );

		if ( array() === $representation ) {
			return $this->failed( __( 'The page could not be turned into a structural representation.', 'replicaforge' ), 'no_representation' );
		}

		$stored = $this->artifacts->put(
			'representation',
			'representation',
			$representation,
			array( 'source' => 'phase2', 'url' => (string) $verdict['url'], 'schema_version' => '2.0' )
		);

		if ( is_wp_error( $stored ) ) {
			/*
			 * An oversized representation is not retried - the same page will be the same
			 * size. And it is a workflow-level stop, because every later stage needs it.
			 */
			return $this->failed( (string) $stored->get_error_message(), (string) $stored->get_error_code(), true );
		}

		$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? count( $representation['sections'] ) : 0;

		return $this->succeeded(
			array( 'representation' => 'stored', 'sections' => (string) $sections, 'bytes' => (string) $this->artifacts->total_size() )
		);
	}

	/**
	 * Site architecture, for a multi-page workflow.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function stage_site_architecture( array $record ) {
		$representation = $this->artifacts->get( 'representation' );

		if ( is_wp_error( $representation ) ) {
			return $this->failed( __( 'The site analysis needs the page representation, which is missing.', 'replicaforge' ), 'missing_representation' );
		}

		$analyzer = $this->service( 'site', fn() => new Site_Analyzer() );

		$site = $analyzer->analyze(
			(string) $record['project_id'],
			array( (string) $record['workflow_id'] => (array) $representation['payload'] ),
			array( 'source_url' => (string) $record['source_url'], 'mode' => (string) $record['mode'] )
		);

		if ( empty( $site['validation']['valid'] ) ) {
			return $this->failed( __( 'The website analysis did not produce a usable result.', 'replicaforge' ), 'site_analysis_invalid' );
		}

		$stored = $this->artifacts->put( 'site_specification', 'site_specification', $site, array( 'source' => 'phase12' ) );

		if ( is_wp_error( $stored ) ) {
			return $this->failed( (string) $stored->get_error_message(), (string) $stored->get_error_code() );
		}

		$components = isset( $site['stages']['shared_components'] ) && is_array( $site['stages']['shared_components'] ) ? count( $site['stages']['shared_components'] ) : 0;

		return $this->succeeded( array( 'site_specification' => 'stored', 'shared_components' => (string) $components ) );
	}

	/**
	 * Synchronisation: compare the source against the stored representation.
	 *
	 * The change *detection* primitives are complete and tested. Nothing in the plugin wires
	 * them to a draft, so this stage detects and reports, and says plainly that applying the
	 * differences is not possible. It does not pretend otherwise, and it does not skip
	 * silently either.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function stage_sync( array $record ) {
		$previous = $this->artifacts->get( 'baseline_representation' );
		$current  = $this->artifacts->get( 'representation' );

		if ( is_wp_error( $current ) ) {
			return $this->failed( __( 'The comparison needs a fresh page representation.', 'replicaforge' ), 'missing_representation' );
		}

		// The first run of an incremental workflow has nothing to compare against, which is
		// recorded as the baseline rather than treated as "no changes".
		if ( is_wp_error( $previous ) ) {
			$this->artifacts->put( 'baseline_representation', 'representation', $current['payload'], array( 'source' => 'phase2', 'schema_version' => '2.0' ) );

			return $this->succeeded(
				array( 'sync' => 'baseline_recorded', 'changes' => '0' ),
				__( 'This is the first analysis of this page, so a baseline was recorded rather than a comparison.', 'replicaforge' )
			);
		}

		$detector = $this->service( 'changes', fn() => new Change_Detector() );
		$changes  = $detector->compare( (array) $previous['payload'], (array) $current['payload'] );

		$list = (array) ( $changes['changes'] ?? array() );

		$this->artifacts->put(
			'page_record',
			'page_record',
			array( 'detected_at' => gmdate( 'c' ), 'change_count' => count( $list ), 'changes' => array_slice( $list, 0, 100 ) ),
			array( 'source' => 'phase9' )
		);

		$applied = (bool) ( $changes['applicable'] ?? false );

		return $this->succeeded(
			array( 'sync' => $applied ? 'applied' : 'detected_only', 'changes' => (string) count( $list ) ),
			$applied ? '' : __( 'Changes were detected but not applied: no synchronisation service connects the comparison to a generated draft.', 'replicaforge' )
		);
	}

	/**
	 * Intelligence aggregation: gather references from the phases that have run.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function stage_intelligence( array $record ) {
		$references = $this->artifacts->references();
		$kinds      = array();

		foreach ( $references as $reference ) {
			$kinds[] = (string) $reference['kind'];
		}

		if ( array() === $kinds ) {
			return $this->failed( __( 'There is nothing to aggregate, because no earlier stage produced an artifact.', 'replicaforge' ), 'no_intelligence' );
		}

		/*
		 * References, not payloads. The specification is explicit that a large dataset is
		 * not copied into a workflow record, and the artifact store is where the payloads
		 * live - so the context is a set of pointers plus the provenance needed to decide
		 * whether a consumer may read one.
		 */
		$context = array(
			'schema_version' => Orchestrator_Limits::SCHEMA_VERSION,
			'built_at'       => gmdate( 'c' ),
			'precedence'     => array(
				'user_decision',
				'source_evidence',
				'validated_analysis',
				'destination_content',
				'ai_interpretation',
				'heuristic_inference',
			),
			'artifacts'      => $references,
			'kinds'          => array_values( array_unique( $kinds ) ),
		);

		$stored = $this->artifacts->put( 'context', 'context', $context, array( 'source' => 'orchestrator.intelligence' ) );

		if ( is_wp_error( $stored ) ) {
			return $this->failed( (string) $stored->get_error_message(), (string) $stored->get_error_code() );
		}

		return $this->succeeded( array( 'context' => 'stored', 'artifacts' => (string) count( $references ) ) );
	}

	/**
	 * Specification: build a reconstruction plan.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function stage_specification( array $record ) {
		$representation = $this->artifacts->get( 'representation' );

		if ( is_wp_error( $representation ) ) {
			return $this->failed( __( 'The specification needs the page representation, which is missing.', 'replicaforge' ), 'missing_representation' );
		}

		$planner = $this->service( 'planner', fn() => new Ai_Reconstruction_Planner() );

		$specification = $planner->build(
			(array) $representation['payload'],
			array( 'source_url' => (string) $record['source_url'], 'mode' => (string) $record['mode'] )
		);

		if ( ! is_array( $specification ) || empty( $specification['validation']['valid'] ) ) {
			return $this->failed( __( 'The reconstruction plan failed its own validation.', 'replicaforge' ), 'specification_invalid' );
		}

		/*
		 * The specification is persisted in phase 4's own store and referenced here. Phase 4
		 * is the owner of specifications; this does not become a second copy of one.
		 */
		$repository = $this->service( 'elementor_repo', fn() => new Elementor_Repository() );
		$spec_id    = $repository->save_specification(
			$specification,
			array( 'source_url' => (string) $record['source_url'], 'schema_version' => (string) ( $specification['schema_version'] ?? '3.0' ) )
		);

		if ( '' === (string) $spec_id ) {
			return $this->failed( __( 'The reconstruction plan could not be stored.', 'replicaforge' ), 'specification_not_stored' );
		}

		$this->artifacts->put( 'specification', 'specification_reference', array( 'specification_id' => (string) $spec_id ), array( 'source' => 'phase3' ) );

		$sections = isset( $specification['sections'] ) && is_array( $specification['sections'] ) ? count( $specification['sections'] ) : 0;

		$note = '';

		if ( ! $this->capabilities->can( 'ai' ) ) {
			$note = __( 'The plan was built by the deterministic planner, because no AI provider is configured.', 'replicaforge' );
		}

		return $this->succeeded(
			array( 'specification_id' => (string) $spec_id, 'sections' => (string) $sections ),
			$note
		);
	}

	/**
	 * A gate that has already been satisfied.
	 *
	 * @param array  $record The workflow.
	 * @param string $stage  Stage name.
	 * @return array
	 */
	private function stage_gate_passed( array $record, $stage ) {
		$gate = '';

		foreach ( (array) ( $record['plan']['approval_checkpoints'] ?? array() ) as $checkpoint ) {
			if ( (string) ( $checkpoint['stage'] ?? '' ) === $stage ) {
				$gate = (string) ( $checkpoint['gate'] ?? '' );
				break;
			}
		}

		$status = $this->workflows->gate_status( $record, $gate );

		return $this->succeeded(
			array( 'gate' => $gate, 'status' => (string) $status['status'], 'reviewer' => (string) (int) ( $status['reviewer_id'] ?? 0 ) )
		);
	}

	/**
	 * Generation: create the Elementor draft.
	 *
	 * @param array $record  The workflow.
	 * @param int   $user_id The user.
	 * @return array
	 */
	private function stage_generation( array $record, $user_id ) {
		$representation = $this->artifacts->get( 'representation' );
		$specification  = $this->artifacts->get( 'specification' );

		if ( is_wp_error( $representation ) ) {
			return $this->failed( __( 'Generation needs the page representation, which is missing.', 'replicaforge' ), 'missing_representation' );
		}

		if ( is_wp_error( $specification ) ) {
			return $this->failed( __( 'Generation needs the reconstruction plan, which is missing.', 'replicaforge' ), 'missing_specification' );
		}

		$reservation = $this->reserve( $user_id, 'generation', (string) $record['project_id'] );

		if ( is_wp_error( $reservation ) ) {
			return $this->failed( (string) $reservation->get_error_message(), (string) $reservation->get_error_code(), true );
		}

		$generator = $this->service( 'generator', fn() => new Elementor_Generator() );

		$result = $generator->generate(
			(array) $representation['payload'],
			null,
			array( 'source_url' => (string) $record['source_url'], 'specification_id' => (string) ( $specification['payload']['specification_id'] ?? '' ) )
		);

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			$code = is_wp_error( $result ) ? (string) $result->get_error_code() : 'generation_failed';
			$text = is_wp_error( $result ) ? (string) $result->get_error_message() : __( 'The draft could not be generated.', 'replicaforge' );

			// The reservation is released, because nothing was produced.
			$this->release( $user_id, 'generation' );

			return array(
				'verdict' => 'failed',
				'reason'  => $text,
				'error'   => $code,
				'outputs' => array(),
			);
		}

		// Produced: the reservation is settled, not released.
		$this->settle( $user_id, 'generation' );

		$draft_id = (int) ( $result['draft_id'] ?? 0 );

		if ( $draft_id < 1 ) {
			return $this->failed( __( 'Generation reported success but produced no draft.', 'replicaforge' ), 'no_draft' );
		}

		$elements = (int) ( $result['generated_elements'] ?? 0 );

		if ( $elements < 1 ) {
			/*
			 * A draft with no elements is not a reconstruction. Recorded as a failure so the
			 * workflow does not go on to validate an empty page and report it as a faithful
			 * copy of an empty source.
			 */
			return $this->failed( __( 'The generated draft contains no elements.', 'replicaforge' ), 'empty_draft' );
		}

		$this->artifacts->put(
			'draft',
			'page_record',
			array(
				'draft_id'       => $draft_id,
				'generation_id'  => (string) ( $result['generation_id'] ?? '' ),
				'edit_url'       => (string) ( $result['edit_url'] ?? '' ),
				'post_status'    => (string) ( $result['post_status'] ?? 'draft' ),
				'published'      => (bool) ( $result['published'] ?? false ),
				'elements'       => $elements,
				'sections'       => (int) ( $result['generated_sections'] ?? 0 ),
				'components'     => (int) ( $result['generated_components'] ?? 0 ),
			),
			array( 'source' => 'phase4' )
		);

		return $this->succeeded(
			array( 'draft_id' => (string) $draft_id, 'generation_id' => (string) ( $result['generation_id'] ?? '' ), 'elements' => (string) $elements )
		);
	}

	/**
	 * Render: capture the draft for comparison.
	 *
	 * Always blocked on a site with no renderer, and `should_run` refuses it before this is
	 * reached. The body exists so that a site which registers a renderer later has something
	 * real to do rather than a stage that reports success without capturing anything.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function stage_render( array $record ) {
		$draft = $this->artifacts->get( 'draft' );

		if ( is_wp_error( $draft ) ) {
			return $this->failed( __( 'Rendering needs the generated draft.', 'replicaforge' ), 'missing_draft' );
		}

		return $this->failed( __( 'No rendering service is configured, so the draft could not be captured.', 'replicaforge' ), 'no_renderer', true );
	}

	/**
	 * Validation: check the draft against the source.
	 *
	 * @param array $record  The workflow.
	 * @param int   $user_id The user.
	 * @return array
	 */
	private function stage_validation( array $record, $user_id ) {
		$representation = $this->artifacts->get( 'representation' );
		$draft          = $this->artifacts->get( 'draft' );

		if ( is_wp_error( $representation ) ) {
			return $this->failed( __( 'Validation needs the page representation, which is missing.', 'replicaforge' ), 'missing_representation' );
		}

		if ( is_wp_error( $draft ) ) {
			return $this->failed( __( 'Validation needs the generated draft.', 'replicaforge' ), 'missing_draft' );
		}

		$draft_id = (int) ( $draft['payload']['draft_id'] ?? 0 );

		$engine = $this->service( 'validator', fn() => new Validation_Engine() );

		$reservation = $this->reserve( $user_id, 'validation', (string) $record['project_id'] );

		if ( is_wp_error( $reservation ) ) {
			return $this->failed( (string) $reservation->get_error_message(), (string) $reservation->get_error_code(), true );
		}

		$result = $engine->validate( (array) $representation['payload'], $draft_id, array( 'visual' => $this->capabilities->can( 'rendering' ) ) );

		// A success result has no `success` key; a failure is an envelope with one.
		if ( is_wp_error( $result ) || isset( $result['success'] ) && empty( $result['success'] ) ) {
			$code = is_wp_error( $result ) ? (string) $result->get_error_code() : (string) ( $result['error']['code'] ?? 'validation_failed' );

			$this->release( $user_id, 'validation' );

			return array(
				'verdict'   => 'failed',
				'reason'    => is_wp_error( $result ) ? (string) $result->get_error_message() : __( 'The draft could not be validated.', 'replicaforge' ),
				'error'     => $code,
				'outputs'   => array(),
				'permanent' => in_array( $code, array( 'forbidden_draft', 'not_elementor_document', 'invalid_draft' ), true ),
			);
		}

		$this->settle( $user_id, 'validation' );

		$validation_id = (string) ( $result['validation_id'] ?? '' );

		$this->artifacts->put( 'validation', 'validation_reference', array( 'validation_id' => $validation_id, 'draft_id' => (string) $draft_id ), array( 'source' => 'phase5' ) );

		$differences = isset( $result['differences'] ) && is_array( $result['differences'] ) ? count( $result['differences'] ) : 0;

		$note = $this->capabilities->can( 'rendering' ) ? '' : __( 'Validation compared structure and computed styles only. No pixel comparison was possible, so visual differences may be unreported.', 'replicaforge' );

		return $this->succeeded(
			array( 'validation_id' => $validation_id, 'differences' => (string) $differences ),
			$note
		);
	}

	/**
	 * Interactions: model the source page's interactive behaviour.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function stage_interactions( array $record ) {
		$plugin  = Plugin::instance();
		$service = ( null === $plugin ) ? null : $plugin->interactions();

		if ( null === $service ) {
			return $this->failed( __( 'The interaction service is unavailable.', 'replicaforge' ), 'no_interactions' );
		}

		$representation = $this->artifacts->get( 'representation' );

		if ( is_wp_error( $representation ) ) {
			return $this->failed( __( 'Interaction modelling needs the page representation.', 'replicaforge' ), 'missing_representation' );
		}

		$draft   = $this->artifacts->get( 'draft' );
		$page_id = is_wp_error( $draft ) ? 0 : (int) ( $draft['payload']['draft_id'] ?? 0 );

		$report = $service->analyze(
			$page_id,
			(string) $record['source_url'],
			(array) $representation['payload'],
			array( 'project_id' => (string) $record['project_id'], 'observe' => $this->capabilities->can( 'browser' ) )
		);

		$models = isset( $report['model']['candidates'] ) && is_array( $report['model']['candidates'] ) ? count( $report['model']['candidates'] ) : 0;

		/*
		 * Only identifiers are stored. Phase 16 persists its own model keyed by project, and
		 * duplicating it here would create a second copy that could disagree with the first.
		 */
		$this->artifacts->put(
			'interaction_model',
			'interaction_reference',
			array( 'project_id' => (string) $record['project_id'], 'status' => (string) ( $report['status'] ?? '' ) ),
			array( 'source' => 'phase16' )
		);

		$note = $this->capabilities->can( 'browser' ) ? '' : __( 'Interactions were modelled from markup and ARIA only. No browser driver is registered, so behaviour that depends on script was not observed.', 'replicaforge' );

		return $this->succeeded(
			array( 'interaction_model' => 'stored', 'candidates' => (string) $models ),
			$note
		);
	}

	/**
	 * Corrections: plan and apply eligible corrections, within a bounded loop.
	 *
	 * @param array $record  The workflow.
	 * @param int   $user_id The user.
	 * @return array
	 */
	private function stage_corrections( array $record, $user_id ) {
		$validation = $this->artifacts->get( 'validation' );
		$draft      = $this->artifacts->get( 'draft' );

		if ( is_wp_error( $validation ) ) {
			return $this->failed( __( 'Corrections need a validation result, which is missing.', 'replicaforge' ), 'missing_validation' );
		}

		if ( is_wp_error( $draft ) ) {
			return $this->failed( __( 'Corrections need the generated draft.', 'replicaforge' ), 'missing_draft' );
		}

		$draft_id  = (int) ( $draft['payload']['draft_id'] ?? 0 );
		$engine    = $this->service( 'corrector', fn() => new Correction_Engine() );
		$applied   = 0;
		$iterations = 0;
		$history    = array();

		$ceiling = (int) Orchestrator_Limits::BUDGETS['max_corrections'];

		/*
		 * The bounded loop. Each pass plans, applies the safe corrections, and stops. The
		 * ceiling is a hard stop rather than "until nothing is left", because a correction
		 * pass can always find something new to propose and a loop that only ends when the
		 * differences run out is a loop that may not end.
		 */
		while ( $iterations < $ceiling ) {
			$spend = $this->workflows->spend( (string) $record['workflow_id'], 'max_corrections', 1 );

			if ( is_wp_error( $spend ) ) {
				// The budget is the stop condition, and it is a *clean* stop, not a failure.
				return $this->succeeded(
					array( 'correction_history' => (string) count( $history ), 'iterations' => (string) $iterations, 'applied' => (string) $applied, 'stopped' => 'budget' ),
					__( 'The correction iteration budget was reached, so no further corrections were attempted.', 'replicaforge' )
				);
			}

			$iterations++;

			$plan = $engine->plan( array(), $draft_id, array() );

			if ( is_wp_error( $plan ) || empty( $plan['plan_id'] ) ) {
				$code = is_wp_error( $plan ) ? (string) $plan->get_error_code() : 'no_plan';

				// A document that is not correctable is a permanent stop for this stage.
				return $this->failed(
					is_wp_error( $plan ) ? (string) $plan->get_error_message() : __( 'No correction plan could be produced.', 'replicaforge' ),
					$code,
					in_array( $code, array( 'draft_not_correctionable', 'forbidden' ), true )
				);
			}

			$corrections = (array) ( $plan['corrections'] ?? array() );

			if ( array() === $corrections ) {
				return $this->succeeded(
					array( 'correction_history' => (string) count( $history ), 'iterations' => (string) $iterations, 'applied' => (string) $applied, 'stopped' => 'none_eligible' ),
					__( 'No further eligible corrections were found.', 'replicaforge' )
				);
			}

			/*
			 * Only the safe, deterministic corrections are selected. §11's preference order
			 * puts them first, and §17 forbids letting a model drive a modification - so
			 * nothing selected here came from an AI proposal.
			 */
			$selected = array();

			foreach ( $corrections as $correction ) {
				$id = (string) ( $correction['correction_id'] ?? ( $correction['id'] ?? '' ) );

				if ( '' === $id ) {
					continue;
				}

				if ( ! empty( $correction['requires_approval'] ) ) {
					$history[] = array( 'id' => $id, 'outcome' => 'skipped_requires_approval' );
					continue;
				}

				$selected[] = $id;
			}

			if ( array() === $selected ) {
				return $this->succeeded(
					array( 'correction_history' => (string) count( $history ), 'iterations' => (string) $iterations, 'applied' => (string) $applied, 'stopped' => 'all_require_approval' ),
					__( 'Every remaining correction needs an approval, so none was applied automatically.', 'replicaforge' )
				);
			}

			$reservation = $this->reserve( $user_id, 'correction', (string) $record['project_id'] );

			if ( is_wp_error( $reservation ) ) {
				return $this->succeeded(
					array( 'correction_history' => (string) count( $history ), 'iterations' => (string) $iterations, 'applied' => (string) $applied, 'stopped' => 'quota' ),
					__( 'The plan quota does not allow further corrections.', 'replicaforge' )
				);
			}

			$outcome = $engine->apply( (string) $plan['plan_id'], $selected, array( 'iterations' => 1 ) );

			if ( is_wp_error( $outcome ) ) {
				$this->release( $user_id, 'correction' );
				$history[] = array( 'outcome' => 'failed', 'code' => (string) $outcome->get_error_code() );

				return $this->failed( (string) $outcome->get_error_message(), (string) $outcome->get_error_code() );
			}

			$this->settle( $user_id, 'correction' );

			$applied += count( $selected );

			$history[] = array(
				'iteration' => $iterations,
				'plan_id'   => (string) $plan['plan_id'],
				'requested' => count( $selected ),
				'outcome'   => 'applied',
			);
		}

		$this->artifacts->put( 'correction_history', 'page_record', array( 'iterations' => $iterations, 'applied' => $applied, 'history' => $history ), array( 'source' => 'phase6' ) );

		return $this->succeeded(
			array( 'correction_history' => (string) count( $history ), 'iterations' => (string) $iterations, 'applied' => (string) $applied, 'stopped' => 'iteration_ceiling' ),
			sprintf(
				/* translators: %d: iteration count. */
				__( 'The correction loop stopped after %d iteration(s), the configured maximum.', 'replicaforge' ),
				$iterations
			)
		);
	}

	/**
	 * Regression: re-validate and compare against the pre-correction result.
	 *
	 * @param array $record  The workflow.
	 * @param int   $user_id The user.
	 * @return array
	 */
	private function stage_regression( array $record, $user_id ) {
		$representation = $this->artifacts->get( 'representation' );
		$draft          = $this->artifacts->get( 'draft' );

		if ( is_wp_error( $representation ) || is_wp_error( $draft ) ) {
			return $this->failed( __( 'The regression check needs the representation and the draft.', 'replicaforge' ), 'missing_input' );
		}

		$before = $this->artifacts->get( 'validation' );

		if ( is_wp_error( $before ) ) {
			// Nothing to compare against. That is not a regression and not a pass.
			return $this->succeeded(
				array( 'regression' => 'no_baseline' ),
				__( 'There was no earlier validation result, so the regression check had nothing to compare against.', 'replicaforge' )
			);
		}

		$engine = $this->service( 'validator', fn() => new Validation_Engine() );
		$after  = $engine->validate(
			(array) $representation['payload'],
			(int) ( $draft['payload']['draft_id'] ?? 0 ),
			array( 'force' => true, 'visual' => $this->capabilities->can( 'rendering' ) )
		);

		if ( is_wp_error( $after ) || isset( $after['success'] ) && empty( $after['success'] ) ) {
			return $this->failed( __( 'The re-validation could not be completed.', 'replicaforge' ), 'revalidation_failed' );
		}

		$before_count = (int) ( $before['payload']['difference_count'] ?? 0 );
		$after_count  = isset( $after['differences'] ) && is_array( $after['differences'] ) ? count( $after['differences'] ) : 0;

		$verdict = $after_count > $before_count ? 'regressed' : ( $after_count < $before_count ? 'improved' : 'unchanged' );

		$this->artifacts->put(
			'regression',
			'regression',
			array( 'verdict' => $verdict, 'before' => $before_count, 'after' => $after_count, 'checked_at' => gmdate( 'c' ) ),
			array( 'source' => 'orchestrator' )
		);

		$note = '';

		if ( 'regressed' === $verdict ) {
			$note = sprintf(
				/* translators: 1: before count, 2: after count. */
				__( 'Differences increased from %1$d to %2$d after correction, so the corrections made the reconstruction worse.', 'replicaforge' ),
				$before_count,
				$after_count
			);
		}

		return $this->succeeded( array( 'regression' => $verdict, 'before' => (string) $before_count, 'after' => (string) $after_count ), $note );
	}

	/**
	 * Final review: evaluate the quality gate.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function stage_final_review( array $record ) {
		$gate  = new Quality_Gate( $this->capabilities );
		$final = $gate->evaluate( $record, $this->artifacts );

		$this->artifacts->put( 'quality_gate', 'quality_gate', $final, array( 'source' => 'orchestrator' ) );

		return $this->succeeded( array( 'quality_gate' => (string) $final['status'] ) );
	}

	/**
	 * Completion: build the report.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function stage_completion( array $record ) {
		$report = (new Workflow_Report( $this->capabilities ) )->build( $record, $this->artifacts );

		$this->artifacts->put( 'report', 'quality_gate', $report, array( 'source' => 'orchestrator' ) );

		return $this->succeeded( array( 'report' => 'stored' ) );
	}

	/* ---------------------------------------------------------------------
	 * Output verification
	 * ------------------------------------------------------------------ */

	/**
	 * Check that a stage produced what it declared.
	 *
	 * This is the rule the whole class exists to enforce. A stage body returns `succeeded`
	 * when the service did not error; this converts that into a real answer by looking for
	 * the outputs the definition promised.
	 *
	 * @param string $stage    Stage name.
	 * @param array  $expected Declared output names.
	 * @param array  $actual   What the stage produced.
	 * @return array{verdict: string, reason: string}
	 */
	private function verify_outputs( $stage, array $expected, array $actual ) {
		if ( array() === $expected ) {
			return array( 'verdict' => 'succeeded', 'reason' => '' );
		}

		$missing = array();

		foreach ( $expected as $name ) {
			$name = trim( (string) $name );

			if ( '' === $name ) {
				continue;
			}

			// A declared output of "0" is a real value, not a missing one.
			if ( ! array_key_exists( $name, $actual ) || '' === (string) ( $actual[ $name ] ?? '' ) ) {
				$missing[] = $name;
			}
		}

		if ( array() === $missing ) {
			return array( 'verdict' => 'succeeded', 'reason' => '' );
		}

		return array(
			'verdict' => 'failed',
			'reason'  => sprintf(
				/* translators: 1: stage name, 2: comma separated expected outputs. */
				__( 'The stage "%1$s" reported success but produced none of its expected output (%2$s).', 'replicaforge' ),
				(string) $stage,
				implode( ', ', $missing )
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Plan construction
	 * ------------------------------------------------------------------ */

	/**
	 * Build the definition and attach it to the workflow.
	 *
	 * @param array $record The workflow.
	 * @return array|\WP_Error
	 */
	private function build_plan( array $record ) {
		$definition = Workflow_Definition::create(
			(string) $record['type'],
			(string) $record['mode'],
			$this->capabilities->all()
		);

		if ( is_wp_error( $definition ) ) {
			return $definition;
		}

		$record['plan_id'] = $definition->plan_id();
		$record['plan']    = $definition->to_plan();

		$this->workflows->save( $record );

		return $record;
	}

	/* ---------------------------------------------------------------------
	 * Plan usage
	 * ------------------------------------------------------------------ */

	/**
	 * Reserve plan usage for an operation.
	 *
	 * The operation names are from `Plan_Limits::OPERATIONS` exactly. A name outside that
	 * list is refused by the entitlement manager with a 400, which reads as "no plan has
	 * this" - so a typo here would silently make every plan look like the free one.
	 *
	 * @param int    $user_id    The user.
	 * @param string $operation  The operation.
	 * @param string $project_id Project id.
	 * @return true|\WP_Error
	 */
	private function reserve( $user_id, $operation, $project_id ) {
		if ( null === $this->entitlements ) {
			// No entitlement service. The workflow runs unmetered, and the report says so
			// rather than pretending a reservation was made.
			return true;
		}

		$begin = $this->entitlements->begin( $operation, (int) $user_id, array( 'project_id' => (string) $project_id ) );

		if ( empty( $begin['allowed'] ) ) {
			$details = (array) ( $begin['details'] ?? array() );

			return new \WP_Error(
				(string) ( $begin['code'] ?? 'quota_refused' ),
				(string) ( $begin['message'] ?? __( 'Your plan does not allow this operation.', 'replicaforge' ) ),
				array( 'status' => (int) ( $begin['status'] ?? 402 ), 'details' => $details )
			);
		}

		$this->reservations[ (string) $operation ] = (string) ( $begin['reservation'] ?? '' );

		return true;
	}

	/**
	 * Commit a reservation.
	 *
	 * @param int    $user_id   The user.
	 * @param string $operation The operation.
	 * @return void
	 */
	private function settle( $user_id, $operation ) {
		if ( null === $this->entitlements ) {
			return;
		}

		$token = (string) ( $this->reservations[ $operation ] ?? '' );

		if ( '' === $token ) {
			return;
		}

		$this->entitlements->settle( (int) $user_id, $token, '', array( 'source' => 'orchestrator' ) );

		unset( $this->reservations[ $operation ] );
	}

	/**
	 * Release a reservation.
	 *
	 * @param int    $user_id   The user.
	 * @param string $operation The operation.
	 * @return void
	 */
	private function release( $user_id, $operation ) {
		if ( null === $this->entitlements ) {
			return;
		}

		$token = (string) ( $this->reservations[ $operation ] ?? '' );

		if ( '' === $token ) {
			return;
		}

		$this->entitlements->fail( (int) $user_id, $token, 'workflow_stage_failed' );

		unset( $this->reservations[ $operation ] );
	}

	/**
	 * Release every open reservation.
	 *
	 * @return void
	 */
	private function release_all() {
		if ( array() === $this->reservations ) {
			return;
		}

		foreach ( array_keys( $this->reservations ) as $operation ) {
			$this->release( $this->user_id, (string) $operation );
		}
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return a memoised service instance.
	 *
	 * @param string   $key      The key.
	 * @param callable $factory  The factory.
	 * @return mixed
	 */
	private function service( $key, callable $factory ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			$this->services[ $key ] = call_user_func( $factory );
		}

		return $this->services[ $key ];
	}

	/**
	 * Move a workflow to a state, tolerating a no-op.
	 *
	 * @param array|string $record The workflow or its id.
	 * @param string       $state  Target state.
	 * @param array        $extra  Extra fields.
	 * @return array|\WP_Error
	 */
	private function move( $record, $state, array $extra = array() ) {
		$id = is_array( $record ) ? (string) $record['workflow_id'] : (string) $record;

		if ( $this->workflows->can_transition( $id, $state ) ) {
			return $this->workflows->transition( $id, $state, $extra );
		}

		if ( array() === $extra ) {
			return $this->workflows->require_workflow( $id );
		}

		$record = $this->workflows->require_workflow( $id );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$this->workflows->save( array_merge( $record, $extra ) );

		return $this->workflows->require_workflow( $id );
	}

	/**
	 * Return a successful stage result.
	 *
	 * @param array  $outputs The outputs.
	 * @param string $reason  A recorded limitation.
	 * @return array
	 */
	private function succeeded( array $outputs, $reason = '' ) {
		return array( 'verdict' => 'succeeded', 'reason' => (string) $reason, 'error' => '', 'outputs' => $outputs );
	}

	/**
	 * Return a failed stage result.
	 *
	 * @param string $reason    What happened.
	 * @param string $code      Machine code.
	 * @param bool   $permanent Whether retrying could help.
	 * @return array
	 */
	private function failed( $reason, $code, $permanent = false ) {
		return array(
			'verdict'   => 'failed',
			'reason'    => (string) $reason,
			'error'     => (string) $code,
			'outputs'   => array(),
			'permanent' => (bool) $permanent,
		);
	}

	/**
	 * Stop a workflow for a stated reason.
	 *
	 * @param array  $record The workflow.
	 * @param string $state  The state to move to.
	 * @return array
	 */
	private function stop( array $record, $state ) {
		$this->release_all();

		$id = (string) $record['workflow_id'];

		if ( $this->workflows->can_transition( $id, $state ) ) {
			$this->workflows->transition( $id, $state );
		}

		return array( 'outcome' => $state, 'workflow' => $this->workflows->require_workflow( $id ) );
	}

	/**
	 * Reduce a throwable to a message safe for a workflow record.
	 *
	 * @param \Throwable $e The throwable.
	 * @return string
	 */
	private function safe_error( \Throwable $e ) {
		$message = trim( (string) $e->getMessage() );

		if ( '' === $message ) {
			return get_class( $e );
		}

		$message = (string) preg_replace( '#[A-Za-z]:\\\\[^\s\'"]+|/[\w./-]{6,}#', '[path]', $message );

		return substr( trim( $message ), 0, 200 );
	}

	/**
	 * Pull the representation out of whatever the DOM analyser returned.
	 *
	 * Phase 2's `build()` has been wrapped by more than one caller over the plugin's life, so
	 * this accepts the two shapes that exist rather than assuming one. It returns an empty
	 * array when neither matches, which the caller turns into a failed stage rather than
	 * passing an empty representation on.
	 *
	 * @param mixed $built The analyser's return.
	 * @return array
	 */
	private function extract_representation( $built ) {
		if ( ! is_array( $built ) ) {
			return array();
		}

		if ( isset( $built['representation'] ) && is_array( $built['representation'] ) ) {
			return $built['representation'];
		}

		// The analyser returning the representation directly.
		if ( isset( $built['sections'] ) || isset( $built['components'] ) || isset( $built['page'] ) ) {
			return $built;
		}

		// A success envelope around it.
		if ( ! empty( $built['success'] ) ) {
			foreach ( array( 'design', 'data', 'result' ) as $key ) {
				if ( isset( $built[ $key ] ) && is_array( $built[ $key ] ) ) {
					return $built[ $key ];
				}
			}
		}

		return array();
	}
}
