<?php
/**
 * Phase 20: agency automation execution.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Runs automations when their trigger fires, using Phase 17 to do the work.
 *
 * ### There is no second workflow engine here
 *
 * `start_workflow` calls {@see Workflow_Executor::run()}. It does not have its own stages,
 * its own checkpoints, its own approvals, its own retries or its own budgets. Every gate in
 * {@see Orchestrator_Limits::GATES} still applies, `Workflow_Executor::run()` still returns
 * `waiting_approval` rather than publishing, and the plan limits are still enforced by
 * `Entitlement_Manager` inside the executor.
 *
 * ### Two construction traps, avoided
 *
 * The audit that preceded this phase found two:
 *
 * 1. `Workflow_Executor::__construct()` defaults artifacts to `new Workflow_Artifacts( '' )`,
 *    and `sanitize_id('')` is `''` — so every artifact lands in one shared option literally
 *    named `replicaforge_workflow_artifacts_`. The REST handlers avoid it by constructing
 *    artifacts per workflow. So does this class.
 * 2. `Workflow_Repository::__construct()` defaults `$permissions` to `null`, and
 *    `may_access()` treats a null permission service as **DENY**. So this class always
 *    passes a real {@see Permission_Manager}.
 *
 * Both are the kind of default that works until the first multi-workflow request.
 *
 * ### Loop prevention, in three guards
 *
 * The failure is `workflow.completed → automation → start_workflow → workflow.completed`.
 *
 * - **Per-automation.** An automation whose own id is already in the event's ancestry is
 *   refused. This catches the self-triggering case on the first pass rather than at the
 *   depth ceiling.
 * - **Depth.** {@see Platform_Limits::AUTOMATION_MAX_DEPTH} bounds the chain, and
 *   {@see Event_Dispatcher::emit()} refuses to *record* a fourth link, so the stop is
 *   visible in the event log.
 * - **Cooldown.** {@see Platform_Limits::AUTOMATION_COOLDOWN_SECONDS} per
 *   (automation, trigger, resource), so a trigger storm — a hundred workflows finishing in
 *   the same minute — produces one run and ninety-nine recorded skips rather than a hundred
 *   workflows.
 *
 * The cooldown is what handles the non-cyclic duplicate case, which the other two do not:
 * twenty legitimate projects finishing at once are twenty *different* resources, so the
 * per-automation guard does not fire and the depth guard never increments, and only the
 * cooldown bounds it.
 */
final class Automation_Runner {

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Automation store.
	 *
	 * @var Automation_Store
	 */
	private $store;

	/**
	 * Event dispatcher.
	 *
	 * @var Event_Dispatcher
	 */
	private $dispatcher;

	/**
	 * Runs already attempted in this PHP process, keyed for the cooldown.
	 *
	 * @var array<string, int>
	 */
	private $recent = array();

	/**
	 * Constructor.
	 *
	 * @param Logger|null         $logger     Logger.
	 * @param Automation_Store|null $store     Automation store.
	 * @param Event_Dispatcher|null $dispatcher Dispatcher.
	 */
	public function __construct( $logger = null, $store = null, $dispatcher = null ) {
		$this->logger     = $logger instanceof Logger ? $logger : new Logger();
		$this->store      = $store instanceof Automation_Store ? $store : new Automation_Store( null, $this->logger );
		$this->dispatcher = $dispatcher instanceof Event_Dispatcher ? $dispatcher : null;
	}

	/* ---------------------------------------------------------------------
	 * Trigger
	 * ------------------------------------------------------------------ */

	/**
	 * Evaluate the automations listening for an event.
	 *
	 * @param array<string, mixed> $event A stored event.
	 * @return array<string, mixed> What happened, for the caller's log.
	 */
	public function on_event( array $event ) {
		$type        = (string) ( $event['event_type'] ?? '' );
		$workspace_id = (string) ( $event['workspace_id'] ?? '' );

		if ( ! Platform_Limits::is_event( $type ) || '' === $workspace_id ) {
			return array( 'considered' => 0, 'fired' => 0, 'skipped' => 0 );
		}

		$out = array( 'considered' => 0, 'fired' => 0, 'skipped' => 0, 'refused' => 0, 'failures' => array() );

		$candidates = $this->store->listening( $workspace_id, $type );

		foreach ( $candidates as $automation ) {
			$out['considered']++;

			$verdict = $this->may_fire( $automation, $event );

			if ( true !== $verdict ) {
				/*
				 * `skipped` counts every non-firing candidate; `refused` counts the ones a
				 * guard turned away and `cool_downs` the ones a guard turned away because
				 * they were too soon. Keeping them apart matters: "20 skipped" is alarming
				 * and "20 cooldown, 0 refused" is a healthy system under load.
				 */
				$out['skipped']++;

				if ( 'cooldown_active' === $verdict ) {
					$out['cool_downs'] = ( $out['cool_downs'] ?? 0 ) + 1;
				} else {
					$out['refused']++;

					$this->logger->info(
						'automation_skipped',
						'An automation did not run for this event.',
						array(
							'automation_id' => (string) $automation['public_id'],
							'event_type'    => (string) $event['event_type'],
							'reason'        => (string) $verdict,
							'depth'         => (int) ( $event['depth'] ?? 0 ),
						),
						'platform'
					);
				}

				continue;
			}

			$result = $this->fire( $automation, $event );

			if ( ! empty( $result['ok'] ) ) {
				$out['fired']++;
				continue;
			}

			$out['failures'][] = array(
				'automation_id' => (string) $automation['public_id'],
				'reason'        => (string) ( $result['reason'] ?? 'unknown' ),
			);
		}

		return $out;
	}

	/**
	 * Run one automation on demand.
	 *
	 * @param string              $automation_id Automation public id.
	 * @param array<string, mixed> $context       `actor_id`, `correlation_id`, `ancestry`.
	 * @return array<string, mixed>
	 */
	public function run_now( $automation_id, array $context = array() ) {
		$automation = $this->store->read( '*', $automation_id );

		if ( null === $automation ) {
			return array( 'ok' => false, 'reason' => 'automation_not_found' );
		}

		if ( 'active' !== (string) $automation['status'] ) {
			return array( 'ok' => false, 'reason' => 'automation_' . (string) $automation['status'] );
		}

		$event = array(
			'event_type'     => (string) $automation['trigger'],
			'event_id'       => Request_Context::make_id( 'evt', 10 ),
			'workspace_id'   => (string) $automation['workspace_id'],
			'project_id'     => (string) ( $automation['project_id'] ?? '' ),
			'resource_id'    => '',
			'actor_id'       => (int) ( $context['actor_id'] ?? get_current_user_id() ),
			'correlation_id' => (string) ( $context['correlation_id'] ?? Request_Context::request_id() ),
			'ancestry'       => array_values( (array) ( $context['ancestry'] ?? array() ) ),
			'depth'          => max( 0, (int) ( $context['depth'] ?? 0 ) ),
			'data'           => array( 'manual' => true ),
		);

		$result = $this->fire( $automation, $event );

		/*
		 * Recorded as an event so a manual run appears in the same history an automatic one
		 * does. An operator asking "why did this workflow start" should not have to guess
		 * whether it was a person or a rule.
		 */
		if ( $this->dispatcher instanceof Event_Dispatcher ) {
			$this->dispatcher->emit(
				'automation.failed',
				array(
					'workspace_id'   => (string) $automation['workspace_id'],
					'resource_id'    => (string) $automation['public_id'],
					'actor_id'       => (int) $event['actor_id'],
					'correlation_id' => (string) $event['correlation_id'],
					'data'           => array( 'manual' => true, 'ok' => (bool) ( $result['ok'] ?? false ) ),
				)
			);
		}

		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Guards
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether an automation may fire for an event.
	 *
	 * @param array<string, mixed> $automation Automation record.
	 * @param array<string, mixed> $event      Event record.
	 * @return true|string True, or the reason it may not.
	 */
	private function may_fire( array $automation, array $event ) {
		$public_id = (string) $automation['public_id'];
		$ancestry  = array_map( 'strval', (array) ( $event['ancestry'] ?? array() ) );

		// Guard 1: this automation already fired on this thread.
		if ( in_array( $public_id, $ancestry, true ) ) {
			return 'already_fired_on_this_chain';
		}

		// Guard 2: the chain is already as deep as it may go.
		$depth = max( 0, (int) ( $event['depth'] ?? 0 ) );

		if ( $depth >= Platform_Limits::AUTOMATION_MAX_DEPTH ) {
			return 'chain_depth_exceeded';
		}

		// Guard 3: this exact trigger on this exact resource fired recently.
		$key = $this->cooldown_key( $public_id, $event );
		$last = isset( $this->recent[ $key ] ) ? (int) $this->recent[ $key ] : 0;

		if ( $last > 0 && ( time() - $last ) < Platform_Limits::AUTOMATION_COOLDOWN_SECONDS ) {
			return 'cooldown_active';
		}

		return true;
	}

	/**
	 * Build the cooldown key.
	 *
	 * Per automation, per trigger, per resource — so twenty different projects finishing
	 * together each get their own run, while one project finishing twice in a minute gets
	 * one run and one skip.
	 *
	 * @param string              $automation_id Automation id.
	 * @param array<string, mixed> $event        Event.
	 * @return string
	 */
	private function cooldown_key( $automation_id, array $event ) {
		return substr(
			hash(
				'sha256',
				$automation_id . '|' . (string) ( $event['event_type'] ?? '' ) . '|' . (string) ( $event['project_id'] ?? '' ) . '|' . (string) ( $event['resource_id'] ?? '' )
			),
			0,
			32
		);
	}

	/* ---------------------------------------------------------------------
	 * Execution
	 * ------------------------------------------------------------------ */

	/**
	 * Execute an automation's action.
	 *
	 * @param array<string, mixed> $automation Automation record.
	 * @param array<string, mixed> $event      Event record.
	 * @return array<string, mixed>
	 */
	private function fire( array $automation, array $event ) {
		$public_id = (string) $automation['public_id'];

		$this->recent[ $this->cooldown_key( $public_id, $event ) ] = time();

		$options  = (array) ( $automation['options'] ?? array() );
		$actor_id = (int) ( $event['actor_id'] ?? 0 );
		$outcome  = array( 'ok' => false, 'reason' => 'unknown' );

		try {
			switch ( (string) $automation['action'] ) {
				case 'start_workflow':
					$outcome = $this->start_workflow( $automation, $event, $options, $actor_id );
					break;

				case 'notify':
					$outcome = $this->notify( $automation, $event, $options );
					break;

				case 'create_review':
				case 'create_task':
					$outcome = $this->create_item( $automation, $event, $options, $actor_id );
					break;

				case 'webhook':
					$outcome = $this->call_webhook( $automation, $event, $options );
					break;

				default:
					$outcome = array( 'ok' => false, 'reason' => 'unknown_action' );
			}
		} catch ( \Throwable $error ) {
			$outcome = array( 'ok' => false, 'reason' => 'threw', 'detail' => substr( get_class( $error ) . ': ' . $error->getMessage(), 0, 160 ) );
		}

		$this->store->record_run( $public_id, ! empty( $outcome['ok'] ), (string) ( $outcome['reason'] ?? '' ) );

		/**
		 * Fires after an automation attempted its action.
		 *
		 * @param string              $automation_id Automation public id.
		 * @param array<string, mixed> $event         The event that triggered it.
		 * @param array<string, mixed> $outcome       What happened.
		 */
		do_action( 'replicaforge_automation_fired', $public_id, $event, $outcome );

		if ( empty( $outcome['ok'] ) && $this->dispatcher instanceof Event_Dispatcher ) {
			/*
			 * A failure is an event, so it appears in the console and can be watched by a
			 * webhook the operator set up for exactly this. It is not public, so it does not
			 * go to third parties on its own.
			 */
			$this->dispatcher->emit(
				'automation.failed',
				array(
					'workspace_id'   => (string) $automation['workspace_id'],
					'resource_id'    => $public_id,
					'actor_id'       => $actor_id,
					'correlation_id' => (string) ( $event['correlation_id'] ?? '' ),
					'ancestry'       => (array) ( $event['ancestry'] ?? array() ),
					'depth'          => (int) ( $event['depth'] ?? 0 ),
					'data'           => array(
						'automation' => (string) $automation['name'],
						'action'     => (string) $automation['action'],
						'reason'     => (string) ( $outcome['reason'] ?? 'unknown' ),
					),
				)
			);
		}

		return $outcome;
	}

	/**
	 * Start a Phase 17 workflow.
	 *
	 * @param array<string, mixed> $automation Automation.
	 * @param array<string, mixed> $event      Event.
	 * @param array<string, mixed> $options    Options.
	 * @param int                 $actor_id   Acting user.
	 * @return array<string, mixed>
	 */
	private function start_workflow( array $automation, array $event, array $options, $actor_id ) {
		$projects = new Project_Repository( $this->logger );

		$project_id = (string) ( $options['project_id'] ?? '' );

		/*
		 * If the automation names no project, the event's project is used. A
		 * notify-on-completion rule then acts on the project that triggered it rather than
		 * needing one configured per project.
		 */
		if ( '' === $project_id ) {
			$project_id = (string) ( $event['project_id'] ?? '' );
		}

		if ( '' === $project_id ) {
			return array( 'ok' => false, 'reason' => 'no_project' );
		}

		$project = $projects->find( $project_id );

		if ( null === $project ) {
			return array( 'ok' => false, 'reason' => 'project_not_found' );
		}

		/*
		 * The permission check is not optional and is not inherited from the event. An
		 * automation runs as its creator, and a rule created by an administrator would
		 * otherwise be able to start work in a project that administrator cannot see.
		 */
		$context = ( new Project_Context_Store() )->get( $project_id );
		$allowed = ( new Permission_Manager() )->can_in_project(
			$actor_id,
			(string) ( $context['workspace_id'] ?? '' ),
			$project_id,
			'analysis.run'
		);

		if ( ! $allowed && ! user_can( $actor_id, 'manage_options' ) ) {
			return array( 'ok' => false, 'reason' => 'permission_denied' );
		}

		/*
		 * Both objects constructed explicitly, with the permission manager and a
		 * per-workflow artifact store. See the class docblock: the shared instances deny
		 * non-creator access and share one artifact bucket.
		 */
		$workflows = new Workflow_Repository( $projects, new Permission_Manager(), new Job_Lock( $this->logger ) );

		$created = $workflows->create(
			array(
				'source_url' => (string) ( $options['source_url'] ?? $project['source_url'] ?? '' ),
				'project_id' => $project_id,
				'created_by' => $actor_id,
				'type'       => (string) ( $options['type'] ?? 'single_page' ),
				'mode'       => (string) ( $options['mode'] ?? 'balanced' ),
				/*
				 * Derived from the automation and the triggering event, so the same rule
				 * firing twice on the same project is one workflow rather than two. This is
				 * `Workflow_Repository`'s existing idempotency mechanism, reused rather than
				 * replaced.
				 */
				'idempotency_key' => 'auto-' . substr( hash( 'sha256', (string) $automation['public_id'] . '|' . $project_id ), 0, 24 ),
			)
		);

		if ( is_wp_error( $created ) ) {
			return array( 'ok' => false, 'reason' => (string) $created->get_error_code() );
		}

		$workflow  = is_array( $created[0] ?? null ) ? (array) $created[0] : (array) $created;
		$duplicate = ! empty( $created[1] );

		$executor = new Workflow_Executor(
			$workflows,
			new Capability_Registry(),
			new Workflow_Artifacts( (string) ( $workflow['workflow_id'] ?? '' ) ),
			new Entitlement_Manager()
		);

		$report = $executor->run( (string) ( $workflow['workflow_id'] ?? '' ), $actor_id );

		if ( is_wp_error( $report ) ) {
			return array( 'ok' => false, 'reason' => (string) $report->get_error_code() );
		}

		$outcome = (string) ( $report['outcome'] ?? '' );

		/*
		 * `waiting_approval` is a *success* for an automation: the workflow did what it was
		 * asked to do and stopped where the site's approval policy says it stops. Reporting
		 * it as a failure would put a rule into `failing` for obeying a gate, which is the
		 * wrong incentive to build into a system.
		 */
		$ok = in_array( $outcome, array( 'finished', 'waiting_approval', 'paused' ), true );

		return array(
			'ok'         => $ok,
			'reason'     => $ok ? $outcome : $outcome,
			'workflow_id' => (string) ( $workflow['workflow_id'] ?? '' ),
			'outcome'    => $outcome,
			'duplicate'  => $duplicate,
			'detail'     => isset( $report['final_status'] ) ? (string) $report['final_status'] : '',
		);
	}

	/**
	 * Send a notification through Phase 15's service.
	 *
	 * @param array<string, mixed> $automation Automation.
	 * @param array<string, mixed> $event      Event.
	 * @param array<string, mixed> $options    Options.
	 * @return array<string, mixed>
	 */
	private function notify( array $automation, array $event, array $options ) {
		$service = new Notification_Service();

		/*
		 * `Notification_Service::notify()` returns `array( 'ok' => bool, 'reason' => string )`
		 * — not a boolean and not a WP_Error. An earlier draft of this method tested
		 * `is_wp_error()` and compared the result against `false`, which would have reported
		 * every delivery as a success, because an array is neither.
		 *
		 * A provider accepting the request is enough for the automation. Whether a
		 * particular channel then succeeded is that channel's own business and is reported
		 * through the notification's own record.
		 */
		$sent = $service->notify(
			(string) $automation['workspace_id'],
			(string) ( $options['type'] ?? '' ),
			array(
				'project_id'  => (string) ( $options['project_id'] ?? $event['project_id'] ?? '' ),
				'actor_id'    => (int) ( $event['actor_id'] ?? 0 ),
				'resource_id' => (string) ( $event['resource_id'] ?? '' ),
				/* Only the event type and ids. Not the event's `data`, which can carry
				 * project metadata, and never anything resembling source-page content. */
				'event_type'  => (string) ( $event['event_type'] ?? '' ),
			)
		);

		if ( ! is_array( $sent ) ) {
			return array( 'ok' => false, 'reason' => 'notification_unreadable' );
		}

		return array(
			'ok'     => ! empty( $sent['ok'] ),
			'reason' => (string) ( $sent['reason'] ?? ( empty( $sent['ok'] ) ? 'notification_declined' : 'accepted' ) ),
		);
	}

	/**
	 * Create a review or a task.
	 *
	 * @param array<string, mixed> $automation Automation.
	 * @param array<string, mixed> $event      Event.
	 * @param array<string, mixed> $options    Options.
	 * @param int                 $actor_id   Acting user.
	 * @return array<string, mixed>
	 */
	private function create_item( array $automation, array $event, array $options, $actor_id ) {
		$project_id = (string) ( $options['project_id'] ?? $event['project_id'] ?? '' );

		if ( '' === $project_id ) {
			return array( 'ok' => false, 'reason' => 'no_project' );
		}

		$context = ( new Project_Context_Store() )->get( $project_id );
		$workspace_id = (string) ( $context['workspace_id'] ?? '' );

		$is_review = 'create_review' === (string) $automation['action'];

		/*
		 * `comments.create`, not a task-specific capability, and the reason is that no
		 * `tasks.create` exists: `Workspace_Limits::CAPABILITY_GROUPS` has no `tasks`
		 * group, and the one place a task is created by hand — `Workspace_Admin` — gates it
		 * on `comments.create`. Using a capability that is not in the vocabulary would be
		 * refused by `Permission_Manager::can()` and this automation would fail every run
		 * for a reason invisible from the row.
		 */
		$needed = $is_review ? 'reviews.create' : 'comments.create';

		$allowed = ( new Permission_Manager() )->can_in_project( $actor_id, $workspace_id, $project_id, $needed );

		if ( ! $allowed && ! user_can( $actor_id, 'manage_options' ) ) {
			return array( 'ok' => false, 'reason' => 'permission_denied' );
		}

		$title = (string) ( $options['title'] ?? '' );

		if ( '' === $title ) {
			$title = sprintf(
				/* translators: 1: the event type, 2: the project id. */
				__( 'ReplicaForge: %1$s on %2$s', 'replicaforge' ),
				(string) ( $event['event_type'] ?? 'event' ),
				$project_id
			);
		}

		if ( $is_review ) {
			$review = ( new Review_Store() )->create(
				$workspace_id,
				$project_id,
				array(
					'type'          => (string) ( $options['type'] ?? 'internal' ),
					'title'         => $title,
					'note'          => sprintf(
						/* translators: 1: the event type, 2: the automation name. */
						__( 'Opened automatically because of %1$s, by the automation "%2$s".', 'replicaforge' ),
						(string) ( $event['event_type'] ?? 'an event' ),
						(string) $automation['name']
					),
					'reviewer_id'    => (int) ( $options['reviewer_id'] ?? 0 ),
					'reviewer_email' => (string) ( $options['reviewer_email'] ?? '' ),
					/* No `version_id` from the event. `Review_Store` resolves the version from
					 * the project's own history, and §15 requires a review to be bound to the
					 * thing it approves — an event's resource id is not that, so passing it
					 * would risk a review naming a version it does not cover. */
					'requested_by'   => $actor_id,
				)
			);

			/*
			 * `Review_Store::create()` returns null rather than a WP_Error, for two
			 * documented reasons: no resolvable version, or no reviewer. Both are silent, so
			 * both are reported here rather than being counted as a bare failure.
			 */
			if ( null === $review ) {
				return array( 'ok' => false, 'reason' => 'review_not_created_no_version_or_reviewer' );
			}

			return array( 'ok' => true, 'reason' => 'review_created', 'review_id' => (string) ( $review['public_id'] ?? '' ) );
		}

		$task = ( new Task_Store() )->create(
			$workspace_id,
			$project_id,
			array(
				'title'       => $title,
				'description' => (string) ( $options['description'] ?? '' ),
				'assignee_id' => (int) ( $options['assignee_id'] ?? 0 ),
				'creator_id'  => $actor_id,
			)
		);

		/* Also null on failure, here only for an empty title or a missing table. */
		if ( null === $task ) {
			return array( 'ok' => false, 'reason' => 'task_not_created' );
		}

		return array( 'ok' => true, 'reason' => 'task_created', 'task_id' => (string) ( $task['public_id'] ?? '' ) );
	}

	/**
	 * Queue a delivery to a webhook subscription.
	 *
	 * @param array<string, mixed> $automation Automation.
	 * @param array<string, mixed> $event      Event.
	 * @param array<string, mixed> $options    Options.
	 * @return array<string, mixed>
	 */
	private function call_webhook( array $automation, array $event, array $options ) {
		$subscriptions = new Webhook_Store( null, $this->logger );

		$subscription = $subscriptions->read( (string) $automation['workspace_id'], (string) ( $options['webhook_id'] ?? '' ) );

		if ( null === $subscription ) {
			return array( 'ok' => false, 'reason' => 'webhook_not_found' );
		}

		if ( 'active' !== (string) $subscription['status'] ) {
			return array( 'ok' => false, 'reason' => 'webhook_' . (string) $subscription['status'] );
		}

		$queued = ( new Webhook_Delivery_Store( null, $this->logger ) )->enqueue( (string) $subscription['public_id'], $event );

		return is_wp_error( $queued )
			? array( 'ok' => false, 'reason' => (string) $queued->get_error_code() )
			: array( 'ok' => true, 'reason' => 'queued', 'delivery_id' => (string) $queued['public_id'] );
	}

	/* ---------------------------------------------------------------------
	 * Diagnostics
	 * ------------------------------------------------------------------ */

	/**
	 * Return a summary for the console.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, mixed>
	 */
	public function summary( $workspace_id ) {
		$page = $this->store->browse( (string) $workspace_id, array( 'per_page' => 200 ) );

		$by_trigger = array();
		$failing    = 0;

		foreach ( $page['items'] as $automation ) {
			$trigger = (string) $automation['trigger'];
			$by_trigger[ $trigger ] = ( $by_trigger[ $trigger ] ?? 0 ) + 1;

			if ( 'failing' === (string) $automation['status'] ) {
				$failing++;
			}
		}

		return array(
			'total'      => (int) $page['count'],
			'by_trigger' => $by_trigger,
			'failing'    => $failing,
			'max_depth'  => Platform_Limits::AUTOMATION_MAX_DEPTH,
			'cooldown'   => Platform_Limits::AUTOMATION_COOLDOWN_SECONDS,
		);
	}
}
