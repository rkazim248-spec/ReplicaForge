<?php
/**
 * Phase 17: the workflow REST surface.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes workflows over REST.
 *
 * ### Every permission callback is public, and that is load-bearing
 *
 * Phase 16 shipped ten routes whose callbacks were declared `private`. Inside the class
 * `is_callable( array( $this, 'gate_signed_in' ) )` is false for a private method, so
 * WordPress could not reach the callback from outside - and a permission callback that cannot
 * be called is not a check. All ten endpoints answered anonymous callers until the suite
 * started asserting `is_callable()` rather than `isset()`.
 *
 * So every callback below is `public`, and the test suite asserts `is_callable()` on each one.
 * That is the whole reason this paragraph exists.
 *
 * ### Ownership is checked on every route, from the record not the request
 *
 * The id in the URL selects a workflow; the authorization decision then comes from that
 * workflow's own `created_by` and `project_id`, never from a `user_id` the caller supplied.
 * Passing another workspace's workflow id is the IDOR this is written to prevent, and the
 * only way to prevent it is for the check to read the record rather than the request.
 */
final class Orchestrator_Api {

	/**
	 * The REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE_V1 = 'replicaforge/v1';

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
	 * The executor.
	 *
	 * @var Workflow_Executor
	 */
	private $executor;

	/**
	 * Build the API.
	 *
	 * @param array $services repository, capabilities, executor, preflight.
	 */
	public function __construct( array $services = array() ) {
		$this->workflows    = ( $services['repository'] ?? null ) instanceof Workflow_Repository
			? $services['repository']
			: new Workflow_Repository();

		$this->capabilities = ( $services['capabilities'] ?? null ) instanceof Capability_Registry
			? $services['capabilities']
			: new Capability_Registry();

		$this->executor = ( $services['executor'] ?? null ) instanceof Workflow_Executor
			? $services['executor']
			: new Workflow_Executor( $this->workflows, $this->capabilities );
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$base = self::NAMESPACE_V1 . '/orchestrator';

		register_rest_route(
			$base,
			'/capabilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_capabilities' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		register_rest_route(
			$base,
			'/workflows',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_workflows' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_workflow' ),
					'permission_callback' => array( $this, 'can_create' ),
				),
			)
		);

		register_rest_route(
			$base,
			'/workflows/(?P<id>[A-Za-z0-9_]{4,60})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_workflow' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);

		register_rest_route(
			$base,
			'/workflows/(?P<id>[A-Za-z0-9_]{4,60})/preflight',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_preflight' ),
				'permission_callback' => array( $this, 'can_run' ),
			)
		);

		register_rest_route(
			$base,
			'/workflows/(?P<id>[A-Za-z0-9_]{4,60})/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_workflow' ),
				'permission_callback' => array( $this, 'can_run' ),
			)
		);

		// Pausing, resuming and cancelling are one route with one body field, because they
		// are the same operation against the state machine: ask for a state, and let the
		// machine decide whether the transition is legal. Three routes would be three copies
		// of the same transition check.
		register_rest_route(
			$base,
			'/workflows/(?P<id>[A-Za-z0-9_]{4,60})/state',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'change_state' ),
				'permission_callback' => array( $this, 'can_run' ),
			)
		);

		register_rest_route(
			$base,
			'/workflows/(?P<id>[A-Za-z0-9_]{4,60})/approvals',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'decide_approval' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);

		register_rest_route(
			$base,
			'/workflows/(?P<id>[A-Za-z0-9_]{4,60})/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry_stage' ),
				'permission_callback' => array( $this, 'can_run' ),
			)
		);

		register_rest_route(
			$base,
			'/workflows/(?P<id>[A-Za-z0-9_]{4,60})/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_report' ),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Permission callbacks
	 *
	 * All public, all reachable, all asserted callable by the suite. See the class
	 * docblock for why that is not a style preference.
	 * ------------------------------------------------------------------ */

	/**
	 * Allow any signed-in user to read capability information.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error
	 */
	public function can_read( $request ) {
		if ( ! is_user_logged_in() ) {
			return $this->error( 'orchestrator_unauthenticated', __( 'You must be signed in.', 'replicaforge' ), 401 );
		}

		return true;
	}

	/**
	 * Require the capability to start a workflow.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error
	 */
	public function can_create( $request ) {
		$read = $this->can_read( $request );

		if ( is_wp_error( $read ) ) {
			return $read;
		}

		if ( ! Capabilities::user_can( get_current_user_id(), 'replicaforge_use' ) ) {
			return $this->error( 'orchestrator_forbidden', __( 'You do not have permission to start a workflow.', 'replicaforge' ), 403 );
		}

		return true;
	}

	/**
	 * Require the capability to run, pause, cancel or retry a workflow.
	 *
	 * The per-workflow ownership check happens in the callback, because the id is in the
	 * route. The two-stage shape is deliberate: this gate answers "may this user run
	 * workflows at all", and the handler answers "may this user run *this* one".
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error
	 */
	public function can_run( $request ) {
		$create = $this->can_create( $request );

		if ( is_wp_error( $create ) ) {
			return $create;
		}

		$id      = (string) $request['id'];
		$record  = $this->workflows->get( $id );

		if ( null === $record ) {
			// Refused identically whether the workflow does not exist or belongs to someone
			// else, so the route is not a way to discover which workflow ids are real.
			return $this->error( 'orchestrator_not_found', __( 'The workflow does not exist.', 'replicaforge' ), 404 );
		}

		$access = $this->workflows->may_access( $record, get_current_user_id(), 'generation.run' );

		return is_wp_error( $access ) ? $access : true;
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Return what this site can do.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_capabilities( $request ) {
		$summary = $this->capabilities->summary();

		return $this->ok(
			array(
				'schema_version' => Orchestrator_Limits::SCHEMA_VERSION,
				'capabilities'   => $summary,
				'stages'         => Orchestrator_Limits::STAGES,
				'types'          => Orchestrator_Limits::TYPES,
				'modes'          => Orchestrator_Limits::MODES,
				'states'         => Orchestrator_Limits::STATES,
				'gates'          => Orchestrator_Limits::GATES,
				'budgets'        => Orchestrator_Limits::BUDGETS,
			)
		);
	}

	/**
	 * List the workflows a user may see.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function list_workflows( $request ) {
		$user_id    = get_current_user_id();
		$project_id = (string) $request->get_param( 'project_id' );
		$limit      = min( 50, max( 1, (int) $request->get_param( 'limit' ) ) );

		$records = ( '' === $project_id )
			? $this->workflows->recent( 200 )
			: $this->workflows->for_project( $project_id, 50 );

		$out = array();

		foreach ( $records as $record ) {
			// The same access check, per record. A listing is as much an IDOR surface as a
			// single fetch, and a filter here is cheaper than a leak.
			$access = $this->workflows->may_access( $record, $user_id, 'projects.view' );

			if ( is_wp_error( $access ) ) {
				continue;
			}

			$out[] = $this->present_summary( $record );

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $this->ok( array( 'workflows' => $out, 'count' => count( $out ) ) );
	}

	/**
	 * Create a workflow.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function create_workflow( $request ) {
		$input = array(
			'project_id'      => (string) $request->get_param( 'project_id' ),
			'source_url'      => (string) $request->get_param( 'source_url' ),
			'type'            => (string) $request->get_param( 'type' ),
			'mode'            => (string) $request->get_param( 'mode' ),
			'created_by'      => get_current_user_id(),
			'idempotency_key' => (string) $request->get_param( 'idempotency_key' ),
		);

		// The mode is only replaced when it is a declared one. An unrecognised mode does not
		// fall through to `balanced` silently, because a caller who asked for
		// `visual_accuracy` and got `balanced` would not know why the plan looks different.
		if ( '' !== $input['mode'] && ! Orchestrator_Limits::is_mode( $input['mode'] ) ) {
			return $this->error( 'orchestrator_bad_mode', __( 'That reconstruction mode is not recognised.', 'replicaforge' ), 400 );
		}

		if ( '' !== $input['type'] && ! Orchestrator_Limits::is_type( $input['type'] ) ) {
			return $this->error( 'orchestrator_bad_type', __( 'That workflow type is not recognised.', 'replicaforge' ), 400 );
		}

		$access = $this->workflows->may_access(
			array( 'project_id' => $input['project_id'], 'created_by' => 0, 'workspace_id' => '' ),
			get_current_user_id(),
			'projects.create'
		);

		// A missing project is the common case, and `create()` reports it properly, so the
		// pre-check only runs when the caller is neither the owner nor a site admin.
		if ( is_wp_error( $access ) && ! $this->is_own_project( $input['project_id'] ) ) {
			return $access;
		}

		$result = $this->workflows->create( $input );

		if ( is_wp_error( $result ) ) {
			return $this->from_error( $result );
		}

		$record     = is_array( $result ) ? ( is_array( $result[0] ?? null ) ? $result[0] : $result ) : array();
		$was_existing = is_array( $result ) && ! empty( $result[1] );

		return $this->ok(
			array( 'workflow' => $this->present( $record ), 'existing' => $was_existing ),
			$was_existing ? 200 : 201
		);
	}

	/**
	 * Return one workflow.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_workflow( $request ) {
		$record = $this->workflows->get( (string) $request['id'] );

		if ( null === $record ) {
			return $this->error( 'orchestrator_not_found', __( 'The workflow does not exist.', 'replicaforge' ), 404 );
		}

		$access = $this->workflows->may_access( $record, get_current_user_id(), 'projects.view' );

		if ( is_wp_error( $access ) ) {
			return $this->error( 'orchestrator_forbidden', __( 'You do not have access to this workflow.', 'replicaforge' ), 403 );
		}

		return $this->ok( array( 'workflow' => $this->present( $record, get_current_user_id() ) ) );
	}

	/**
	 * Run the preflight assessment without starting a workflow.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function run_preflight( $request ) {
		$record = $this->workflows->get( (string) $request['id'] );

		if ( null === $record ) {
			return $this->error( 'orchestrator_not_found', __( 'The workflow does not exist.', 'replicaforge' ), 404 );
		}

		$access = $this->workflows->may_access( $record, get_current_user_id(), 'analysis.run' );

		if ( is_wp_error( $access ) ) {
			return $access;
		}

		$preflight = new Preflight_Assessment( $this->capabilities, null, get_current_user_id() );

		$report = $preflight->assess(
			array(
				'project_id' => (string) $record['project_id'],
				'source_url' => (string) $record['source_url'],
				'type'       => (string) $record['type'],
				'mode'       => (string) $record['mode'],
			)
		);

		$recommendation = $preflight->recommend_mode( (string) $record['type'], (string) $record['mode'] );

		return $this->ok( array( 'preflight' => $report, 'recommendation' => $recommendation ) );
	}

	/**
	 * Execute a workflow.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function run_workflow( $request ) {
		$id     = (string) $request['id'];
		$record = $this->workflows->get( $id );

		if ( null === $record ) {
			return $this->error( 'orchestrator_not_found', __( 'The workflow does not exist.', 'replicaforge' ), 404 );
		}

		$from = (string) $request->get_param( 'from_stage' );

		if ( '' !== $from && ! in_array( $from, (array) ( $record['plan']['stages'] ?? array() ), true ) && ! Orchestrator_Limits::is_stage( $from ) ) {
			return $this->error( 'orchestrator_bad_stage', __( 'That stage is not part of this workflow.', 'replicaforge' ), 400 );
		}

		$artifacts = new Workflow_Artifacts( $id );

		$executor = new Workflow_Executor( $this->workflows, $this->capabilities, $artifacts );

		$outcome = $executor->run( $id, get_current_user_id(), '' === $from ? array() : array( 'from_stage' => $from ) );

		if ( is_wp_error( $outcome ) ) {
			return $this->from_error( $outcome );
		}

		$status = 'waiting_approval' === (string) ( $outcome['outcome'] ?? '' ) ? 202 : 200;

		return $this->ok( array( 'result' => $outcome ), $status );
	}

	/**
	 * Pause, resume or cancel a workflow.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function change_state( $request ) {
		$id  = (string) $request['id'];
		$to  = (string) $request->get_param( 'state' );
		$out = (string) $request->get_param( 'outcome' );

		/*
		 * The caller names the state they want; the state machine decides whether they may
		 * have it. There is no `can_pause()` / `can_cancel()` pair here, because a pair would
		 * be a second opinion about legality that could disagree with the one enforcing it.
		 */
		$allowed = array( 'paused', 'queued', 'cancelled' );

		if ( ! in_array( $to, $allowed, true ) ) {
			return $this->error(
				'orchestrator_bad_state',
				sprintf(
					/* translators: %s: comma separated state names. */
					__( 'A workflow can only be moved to one of: %s.', 'replicaforge' ),
					implode( ', ', $allowed )
				),
				400
			);
		}

		$record = $this->workflows->transition( $id, $to, '' === $out ? array() : array( 'state_note' => $out ) );

		if ( is_wp_error( $record ) ) {
			return $this->from_error( $record );
		}

		return $this->ok( array( 'workflow' => $this->present( $record, get_current_user_id() ) ) );
	}

	/**
	 * Record an approval decision.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function decide_approval( $request ) {
		$id     = (string) $request['id'];
		$record = $this->workflows->get( $id );

		if ( null === $record ) {
			return $this->error( 'orchestrator_not_found', __( 'The workflow does not exist.', 'replicaforge' ), 404 );
		}

		$gate = (string) $request->get_param( 'gate' );

		if ( ! Orchestrator_Limits::is_gate( $gate ) ) {
			return $this->error( 'orchestrator_bad_gate', __( 'That approval gate is not recognised.', 'replicaforge' ), 400 );
		}

		/*
		 * `reviewer_id` is taken from the session, never from the request. Accepting a
		 * reviewer id in the body would let any caller record a decision in someone else's
		 * name, which is not a decision at all.
		 */
		$updated = $this->workflows->decide(
			$id,
			$gate,
			array(
				'status'        => (string) $request->get_param( 'status' ),
				'reviewer_id'   => get_current_user_id(),
				'note'          => (string) $request->get_param( 'note' ),
				'artifact_hash' => (string) $request->get_param( 'artifact_hash' ),
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $this->from_error( $updated );
		}

		return $this->ok( array( 'workflow' => $this->present( $updated, get_current_user_id() ) ) );
	}

	/**
	 * Retry a failed or paused stage.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function retry_stage( $request ) {
		$id     = (string) $request['id'];
		$record = $this->workflows->get( $id );

		if ( null === $record ) {
			return $this->error( 'orchestrator_not_found', __( 'The workflow does not exist.', 'replicaforge' ), 404 );
		}

		$stage = (string) $request->get_param( 'stage' );

		if ( ! Orchestrator_Limits::is_stage( $stage ) ) {
			return $this->error( 'orchestrator_bad_stage', __( 'That stage is not recognised.', 'replicaforge' ), 400 );
		}

		// A retry is a new attempt, so the attempt counter for this stage resets. Without
		// that, a workflow that exhausted its retries could never be retried after a fix.
		$record['stages'][ $stage ]['attempts'] = 0;

		$this->workflows->save( $record );

		$artifacts = new Workflow_Artifacts( $id );
		$executor  = new Workflow_Executor( $this->workflows, $this->capabilities, $artifacts );

		$outcome = $executor->run( $id, get_current_user_id(), array( 'from_stage' => $stage ) );

		if ( is_wp_error( $outcome ) ) {
			return $this->from_error( $outcome );
		}

		return $this->ok( array( 'result' => $outcome ) );
	}

	/**
	 * Return the final report.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_report( $request ) {
		$id     = (string) $request['id'];
		$record = $this->workflows->get( $id );

		if ( null === $record ) {
			return $this->error( 'orchestrator_not_found', __( 'The workflow does not exist.', 'replicaforge' ), 404 );
		}

		$access = $this->workflows->may_access( $record, get_current_user_id(), 'projects.view' );

		if ( is_wp_error( $access ) ) {
			return $access;
		}

		$artifacts = new Workflow_Artifacts( $id );

		$report = $artifacts->get( 'report' );

		if ( is_wp_error( $report ) ) {
			// The report is not always written - a workflow blocked before completion has
			// none - so the fallback builds one from what is recorded rather than 404ing.
			$report = ( new Workflow_Report( $this->capabilities ) )->build( $record, $artifacts );
		}

		return $this->ok( array( 'report' => $report['payload'] ) );
	}

	/* ---------------------------------------------------------------------
	 * Presentation
	 * ------------------------------------------------------------------ */

	/**
	 * Present a workflow, reduced to what its audience may see.
	 *
	 * @param array $record  The workflow.
	 * @param int   $user_id The reader.
	 * @return array
	 */
	private function present( array $record, $user_id = 0 ) {
		$user_id = (int) ( $user_id ?: get_current_user_id() );

		$definition = (array) ( $record['plan'] ?? array() );
		$stages     = array();

		foreach ( (array) $definition['stages'] as $stage ) {
			$entry = (array) ( $record['stages'][ $stage ] ?? array() );

			$stages[] = array(
				'stage'     => (string) $stage,
				'outcome'   => (string) ( $entry['outcome'] ?? 'pending' ),
				'reason'    => (string) ( $entry['reason'] ?? '' ),
				'attempts'  => (int) ( $entry['attempts'] ?? 0 ),
				'outputs'   => (array) ( $entry['outputs'] ?? array() ),
				'capability' => (string) ( Orchestrator_Limits::STAGE_CAPABILITIES[ $stage ] ?? 'core' ),
			);
		}

		$gates = array();

		foreach ( (array) $definition['approval_checkpoints'] as $checkpoint ) {
			$gate = (string) ( $checkpoint['gate'] ?? '' );

			if ( '' === $gate ) {
				continue;
			}

			$status = $this->workflows->gate_status( $record, $gate );

			$gates[] = array(
				'gate'       => $gate,
				'stage'      => (string) ( $checkpoint['stage'] ?? '' ),
				'capability' => (string) ( $checkpoint['capability'] ?? '' ),
				'status'     => (string) $status['status'],
				'stale'      => (bool) ( $status['stale'] ?? false ),
				// The reviewer's identity is only included for a reader who could act on
				// the gate; a project member who can see the workflow learns that a decision
				// exists, not who made it.
				'reviewer_id' => $this->may_decide( $record, $user_id, (string) ( $status['capability'] ?? '' ) )
					? (int) ( $status['reviewer_id'] ?? 0 )
					: 0,
			);
		}

		return array(
			'id'            => (string) $record['workflow_id'],
			'project_id'    => (string) $record['project_id'],
			'source_url'    => (string) $record['source_url'],
			'type'          => (string) $record['type'],
			'mode'          => (string) $record['mode'],
			'state'         => (string) $record['state'],
			'plan_id'       => (string) ( $record['plan_id'] ?? '' ),
			'created_at'    => (string) $record['created_at'],
			'updated_at'    => (string) $record['updated_at'],
			'stages'        => $stages,
			'gates'         => $gates,
			'checkpoints'   => (array) ( $record['checkpoints'] ?? array() ),
			'budget'        => (array) ( $record['budget'] ?? array() ),
			'result'        => (array) ( $record['result'] ?? array() ),
			'actions'       => $this->available_actions( $record, $user_id ),
		);
	}

	/**
	 * Present a workflow as a summary row.
	 *
	 * @param array $record The workflow.
	 * @return array
	 */
	private function present_summary( array $record ) {
		$stages    = (array) ( $record['plan']['stages'] ?? array() );
		$completed = 0;

		foreach ( $stages as $stage ) {
			$outcome = (string) ( $record['stages'][ $stage ]['outcome'] ?? 'pending' );

			if ( in_array( $outcome, array( 'succeeded', 'skipped' ), true ) ) {
				$completed++;
			}
		}

		$current = '';

		foreach ( $stages as $stage ) {
			$outcome = (string) ( $record['stages'][ $stage ]['outcome'] ?? 'pending' );

			if ( ! in_array( $outcome, array( 'succeeded', 'skipped' ), true ) ) {
				$current = (string) $stage;
				break;
			}
		}

		return array(
			'id'         => (string) $record['workflow_id'],
			'project_id' => (string) $record['project_id'],
			'state'      => (string) $record['state'],
			'type'       => (string) $record['type'],
			'mode'       => (string) $record['mode'],
			'updated_at' => (string) $record['updated_at'],
			// A stage count, not a percentage. There is no progress figure to show and
			// inventing one is what §18 forbids.
			'stages_completed' => $completed,
			'stages_total'     => count( $stages ),
			'current_stage'    => $current,
			'final_status'     => (string) ( $record['result']['status'] ?? '' ),
		);
	}

	/**
	 * Return the actions a user may take, and which the state allows.
	 *
	 * A disabled action is returned as `enabled => false` with a reason rather than omitted,
	 * so the UI can explain why rather than leaving a user wondering where a button went. The
	 * UI is not the security boundary - the API re-checks - but a control that vanishes is
	 * worse than one that explains itself.
	 *
	 * @param array $record  The workflow.
	 * @param int   $user_id The user.
	 * @return array<string, array>
	 */
	private function available_actions( array $record, $user_id ) {
		$state   = (string) $record['state'];
		$actions = array();

		$definitions = array(
			'run'     => array( 'from' => array( 'draft', 'preflight', 'queued', 'paused', 'failed' ), 'capability' => 'generation.run' ),
			'pause'   => array( 'from' => array( 'running', 'waiting_approval' ), 'capability' => 'generation.run' ),
			'resume'  => array( 'from' => array( 'paused', 'failed' ), 'capability' => 'generation.run' ),
			'cancel'  => array( 'from' => array( 'draft', 'preflight', 'queued', 'running', 'waiting_approval', 'paused', 'validating', 'correcting', 'final_review' ), 'capability' => 'generation.run' ),
			'retry'   => array( 'from' => array( 'paused', 'failed' ), 'capability' => 'generation.run' ),
			'approve' => array( 'from' => array( 'waiting_approval' ), 'capability' => 'reviews.approve' ),
			'report'  => array( 'from' => array( 'completed', 'completed_with_warnings', 'failed', 'cancelled' ), 'capability' => 'projects.view' ),
		);

		foreach ( $definitions as $name => $definition ) {
			$state_ok = in_array( $state, $definition['from'], true );
			$gate_ok  = $this->may_decide( $record, $user_id, $definition['capability'] );

			$actions[ $name ] = array(
				'enabled' => $state_ok && $gate_ok,
				'reason'  => $this->action_reason( $name, $state_ok, $gate_ok ),
			);
		}

		return $actions;
	}

	/**
	 * Explain why an action is unavailable.
	 *
	 * @param string $name     The action.
	 * @param bool   $state_ok Whether the state allows it.
	 * @param bool   $gate_ok  Whether the user may.
	 * @return string
	 */
	private function action_reason( $name, $state_ok, $gate_ok ) {
		if ( ! $gate_ok ) {
			return __( 'You do not have the capability for this action.', 'replicaforge' );
		}

		if ( ! $state_ok ) {
			return __( 'This action is not available in the workflow\'s current state.', 'replicaforge' );
		}

		return '';
	}

	/**
	 * Return whether a user could take a decision on a capability.
	 *
	 * @param array  $record     The workflow.
	 * @param int    $user_id    The user.
	 * @param string $capability The capability.
	 * @return bool
	 */
	private function may_decide( array $record, $user_id, $capability ) {
		return ! is_wp_error( $this->workflows->may_access( $record, (int) $user_id, '' === $capability ? 'projects.view' : $capability ) );
	}

	/**
	 * Return whether the current user owns a project.
	 *
	 * @param string $project_id Project id.
	 * @return bool
	 */
	private function is_own_project( $project_id ) {
		$repository = new Project_Repository();
		$project    = $repository->find( (string) $project_id );

		return null !== $project && (int) ( $project['user_id'] ?? 0 ) === get_current_user_id();
	}

	/* ---------------------------------------------------------------------
	 * Responses
	 * ------------------------------------------------------------------ */

	/**
	 * Build a success response.
	 *
	 * @param array $data   The payload.
	 * @param int   $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private function ok( array $data, $status = 200 ) {
		return new \WP_REST_Response( $data, (int) $status );
	}

	/**
	 * Build an error response.
	 *
	 * The message is the service's own, which every service in this plugin writes for a human
	 * and none of them populate with internal detail. The status comes from the error data so
	 * a refusal is 403 rather than a blanket 400.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private function error( $code, $message, $status = 400 ) {
		return new \WP_Error( (string) $code, (string) $message, array( 'status' => (int) $status ) );
	}

	/**
	 * Turn a service refusal into a response.
	 *
	 * @param \WP_Error $error The error.
	 * @return \WP_REST_Response
	 */
	private function from_error( \WP_Error $error ) {
		$data   = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 400 );

		return new \WP_REST_Response(
			array(
				'code'    => (string) $error->get_error_code(),
				'message' => (string) $error->get_error_message(),
				'status'  => $status,
			),
			$status
		);
	}
}
