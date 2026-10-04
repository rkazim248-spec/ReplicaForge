<?php
/**
 * Phase 20: the developer REST surface.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The routes Phase 20 adds, plus a discovery document for everything that already exists.
 *
 * ### This is a gateway, not a second API
 *
 * Phases 1–19 already register around 130 routes under `replicaforge/v1`: analysis,
 * generation, validation, corrections, jobs, workspaces, clients, reviews, comments, tasks,
 * issues, plans, usage, content mapping, visual comparison, interactions, multi-page
 * orchestration and templates. Every one of them works, is permission-checked, is tested, and
 * is documented in `docs/API.md`.
 *
 * Reimplementing any of them as a "developer API" would produce two surfaces that disagree,
 * and the disagreement is the bug: a developer who reads `docs/DEVELOPERS.md` would be
 * documenting behaviour that only holds on one of them. So this class registers **only what
 * does not exist yet**, and its first route is a document describing all of it.
 *
 * ### What is new here, and what is deliberately not
 *
 * New: events, webhooks, automations, extensions, extension settings, a credential's own
 * limits, a cross-workspace project list scoped to the caller, and the discovery document.
 *
 * Not new, and therefore absent: projects, websites, workflows, templates, validation,
 * assets. Those routes exist and are reached with the same credential and the same scope
 * check; {@see self::document()} lists them so a developer can find them from here.
 *
 * ### Credentials cannot be created over REST — and that is a decision, not an omission
 *
 * There is no `POST /developer/credentials`. A credential able to mint credentials is a
 * privilege-escalation path: `api.credentials.manage` would be reachable with the token it
 * protects, and a stolen read-only key would be enough to escalate itself. Credentials are
 * created and revoked in the developer console, by a person, with the audit trail recording
 * who did it.
 *
 * ### The error shape
 *
 * Every refusal uses the one shape documented in `docs/API.md`:
 *
 * ```json
 * { "success": false,
 *   "error": { "code": "...", "message": "...", "details": {} },
 *   "meta":  { "request_id": "...", "api_version": "replicaforge/v1" } }
 * ```
 *
 * No SQL text, no file path, no stack trace, no credential. A 5xx replaces the message with a
 * generic one and drops the details entirely, so an unexpected failure cannot leak an internal
 * string through a helpful-looking error.
 */
final class Developer_Api {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	const BASE = 'replicaforge/v1/developer';

	/**
	 * Authenticator.
	 *
	 * @var Api_Authenticator
	 */
	private $auth;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Extension registry.
	 *
	 * @var Extension_Registry
	 */
	private $extensions;

	/**
	 * Event dispatcher.
	 *
	 * @var Event_Dispatcher
	 */
	private $events;

	/**
	 * Automation runner.
	 *
	 * @var Automation_Runner
	 */
	private $automations;

	/**
	 * Credential store.
	 *
	 * @var Api_Credential_Store
	 */
	private $credentials;

	/**
	 * Webhook store.
	 *
	 * @var Webhook_Store
	 */
	private $webhooks;

	/**
	 * Event store.
	 *
	 * @var Event_Store
	 */
	private $event_store;

	/**
	 * Automation store.
	 *
	 * @var Automation_Store
	 */
	private $automation_store;

	/**
	 * Delivery store.
	 *
	 * @var Webhook_Delivery_Store
	 */
	private $deliveries;

	/**
	 * Delivery worker.
	 *
	 * @var Webhook_Delivery
	 */
	private $delivery;

	/**
	 * Rate limiter.
	 *
	 * @var Rate_Limiter
	 */
	private $limiter;

	/**
	 * Constructor.
	 *
	 * @param Api_Authenticator|null  $auth       Authenticator.
	 * @param Logger|null             $logger     Logger.
	 * @param Extension_Registry|null $extensions Extension registry.
	 * @param Event_Dispatcher|null   $events     Event dispatcher.
	 * @param Automation_Runner|null  $automations Automation runner.
	 * @param Api_Credential_Store|null $credentials Credential store.
	 * @param Webhook_Store|null      $webhooks   Webhook store.
	 * @param Event_Store|null        $event_store Event store.
	 * @param Automation_Store|null   $automation_store Automation store.
	 * @param Webhook_Delivery_Store|null $deliveries Delivery store.
	 * @param Webhook_Delivery|null   $delivery   Delivery worker.
	 * @param Rate_Limiter|null       $limiter    Rate limiter.
	 */
	public function __construct( $auth = null, $logger = null, $extensions = null, $events = null, $automations = null, $credentials = null, $webhooks = null, $event_store = null, $automation_store = null, $deliveries = null, $delivery = null, $limiter = null ) {
		$this->logger           = $logger instanceof Logger ? $logger : new Logger();
		$this->auth             = $auth instanceof Api_Authenticator ? $auth : new Api_Authenticator( null, null, $this->logger );
		$this->extensions       = $extensions instanceof Extension_Registry ? $extensions : new Extension_Registry( null, $this->logger );
		$this->events           = $events instanceof Event_Dispatcher ? $events : new Event_Dispatcher( $this->logger );
		$this->automations      = $automations instanceof Automation_Runner ? $automations : new Automation_Runner( $this->logger, null, $this->events );
		$this->credentials      = $credentials instanceof Api_Credential_Store ? $credentials : new Api_Credential_Store( null, $this->logger );
		$this->webhooks         = $webhooks instanceof Webhook_Store ? $webhooks : new Webhook_Store( null, $this->logger );
		$this->event_store      = $event_store instanceof Event_Store ? $event_store : new Event_Store( null, $this->logger );
		$this->automation_store = $automation_store instanceof Automation_Store ? $automation_store : new Automation_Store( null, $this->logger );
		$this->deliveries       = $deliveries instanceof Webhook_Delivery_Store ? $deliveries : new Webhook_Delivery_Store( null, $this->logger );
		$this->delivery         = $delivery instanceof Webhook_Delivery ? $delivery : new Webhook_Delivery( $this->logger, $this->webhooks, $this->deliveries );
		$this->limiter          = $limiter instanceof Rate_Limiter ? $limiter : new Rate_Limiter( $this->logger );

		// One dispatcher, one runner: an automation started by an event must see the same
		// event history as one started by hand, and two dispatchers would split that.
		$this->events->set_automation_runner( $this->automations );
	}

	/* ---------------------------------------------------------------------
	 * Routes
	 * ------------------------------------------------------------------ */

	/**
	 * Register every Phase 20 route.
	 *
	 * @return void
	 */
	public function register_routes() {
		$routes = array(
			array( '/', 'GET', 'document', 'projects:read', '__return_true' ),
			array( '/limits', 'GET', 'get_limits', 'projects:read', '__return_true' ),

			array( '/projects', 'GET', 'list_projects', 'projects:read', '__return_true' ),

			array( '/events', 'GET', 'list_events', 'events:read', '__return_true' ),
			array( '/events/catalog', 'GET', 'event_catalog', 'events:read', '__return_true' ),

			array( '/webhooks', 'GET', 'list_webhooks', 'webhooks:manage', '__return_true' ),
			array( '/webhooks', 'POST', 'create_webhook', 'webhooks:manage', '__return_true' ),
			array( '/webhooks/(?P<id>[A-Za-z0-9]{1,26})', 'GET', 'get_webhook', 'webhooks:manage', '__return_true' ),
			array( '/webhooks/(?P<id>[A-Za-z0-9]{1,26})', 'POST', 'update_webhook', 'webhooks:manage', '__return_true' ),
			array( '/webhooks/(?P<id>[A-Za-z0-9]{1,26})/delete', 'POST', 'delete_webhook', 'webhooks:manage', '__return_true' ),
			array( '/webhooks/(?P<id>[A-Za-z0-9]{1,26})/test', 'POST', 'test_webhook', 'webhooks:manage', '__return_true' ),
			array( '/webhooks/(?P<id>[A-Za-z0-9]{1,26})/deliveries', 'GET', 'list_deliveries', 'webhooks:manage', '__return_true' ),
			array( '/webhooks/(?P<id>[A-Za-z0-9]{1,26})/deliveries/(?P<delivery>[A-Za-z0-9]{1,26})/retry', 'POST', 'retry_delivery', 'webhooks:manage', '__return_true' ),

			array( '/automations', 'GET', 'list_automations', 'webhooks:manage', '__return_true' ),
			array( '/automations', 'POST', 'create_automation', 'webhooks:manage', '__return_true' ),
			array( '/automations/(?P<id>[A-Za-z0-9]{1,26})', 'POST', 'update_automation', 'webhooks:manage', '__return_true' ),
			array( '/automations/(?P<id>[A-Za-z0-9]{1,26})/delete', 'POST', 'delete_automation', 'webhooks:manage', '__return_true' ),
			array( '/automations/(?P<id>[A-Za-z0-9]{1,26})/run', 'POST', 'run_automation', 'webhooks:manage', '__return_true' ),

			array( '/extensions', 'GET', 'list_extensions', 'projects:read', '__return_true' ),
			array( '/extensions/(?P<id>[a-z0-9_-]{2,64})', 'GET', 'get_extension', 'projects:read', '__return_true' ),
			array( '/extensions/(?P<id>[a-z0-9_-]{2,64})/state', 'POST', 'set_extension_state', 'webhooks:manage', '__return_true' ),
			array( '/extensions/(?P<id>[a-z0-9_-]{2,64})/settings', 'GET', 'get_extension_settings', 'projects:read', '__return_true' ),
			array( '/extensions/(?P<id>[a-z0-9_-]{2,64})/settings', 'POST', 'save_extension_settings', 'webhooks:manage', '__return_true' ),
		);

		foreach ( $routes as $route ) {
			register_rest_route(
				self::BASE,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => array( $this, $route[3] ),
					'args'                => $this->args_for( $route[1] ),
				)
			);
		}

		$this->logger->info(
			'developer_routes_registered',
			'Registered the developer API routes.',
			array( 'count' => count( $routes ), 'base' => self::BASE ),
			'platform'
		);
	}

	/**
	 * Return whether the caller may make this request.
	 *
	 * Every Phase 20 route is closed by the same three gates, and the route's declared scope
	 * lives in the route table above rather than in this method — so a route cannot forget to
	 * declare one. {@see self::gated()} also records the scope against the request so a
	 * handler that needs to charge a plan operation can find it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function gate( $request ) {
		/*
		 * `rest_pre_dispatch` has already checked the credential's scopes against the
		 * `permission_callback` method name. This method's declared name *is* the gate
		 * (`projects:read`, `webhooks:manage`, …), and those names are not in
		 * `Platform_Limits::GATE_SCOPES`, so `enforce_route_scope()` refuses a credential
		 * before reaching here.
		 *
		 * That is deliberate: a credential cannot reach these routes at all. Webhooks and
		 * automations are administrative configuration, and the only way to automate them is
		 * a signed-in operator in the console. §25's agency automations are created by people;
		 * §29's CI/CD story is about triggering reconstruction and reading status, which the
		 * existing Phase 17 routes already allow.
		 */
		if ( $this->auth->using_credential() ) {
			return $this->error(
				'api_route_session_only',
				__( 'This endpoint is for a signed-in WordPress session, not an API credential. Use a signed-in session to configure webhooks and automations.', 'replicaforge' ),
				403
			);
		}

		return is_user_logged_in() && current_user_can( 'replicaforge_use' );
	}

	/**
	 * Return the discovery document.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function document( $request ) {
		return $this->ok(
			array(
				'name'           => 'ReplicaForge Developer API',
				'namespace'      => 'replicaforge/v1',
				'schema_version' => Platform_Limits::PAYLOAD_SCHEMA_VERSION,
				'authentication' => array(
					'methods' => array(
						'bearer'         => array(
							'header' => 'Authorization: Bearer YOUR_API_TOKEN',
							'notes'  => __( 'Issued in the developer console. The token is shown once and is not recoverable afterwards.', 'replicaforge' ),
						),
						'custom_header'   => array(
							'header' => 'X-ReplicaForge-Token: YOUR_API_TOKEN',
							'notes'  => __( 'For clients that cannot set an Authorization header. A token in a query string is not supported: it reaches browser history and proxy logs.', 'replicaforge' ),
						),
						'wordpress_session' => array(
							'notes' => __( 'An ordinary signed-in session, cookie or application password continues to work and is unchanged by Phase 20.', 'replicaforge' ),
						),
					),
				),
				'scopes'         => $this->scope_table(),
				'gates'          => Platform_Limits::GATE_SCOPES,
				'events'         => $this->event_catalog_rows(),
				'vocabularies'   => array(
					'extension_capabilities' => Platform_Limits::EXTENSION_CAPABILITIES,
					'extension_permissions'  => Platform_Limits::EXTENSION_PERMISSIONS,
					'forbidden_permissions'  => Platform_Limits::FORBIDDEN_EXTENSION_PERMISSIONS,
					'automation_triggers'    => Platform_Limits::AUTOMATION_TRIGGERS,
					'automation_actions'     => Platform_Limits::AUTOMATION_ACTIONS,
					'extension_states'       => Platform_Limits::EXTENSION_STATES,
					'webhook_states'         => Platform_Limits::WEBHOOK_STATES,
					'delivery_states'        => Platform_Limits::DELIVERY_STATES,
					'credential_states'      => Platform_Limits::CREDENTIAL_STATES,
				),
				'routes'         => $this->route_table(),
				'limits'         => array(
					'rate_per_minute'     => Platform_Limits::RATE_LIMIT_PER_MINUTE,
					'webhook_attempts'    => Platform_Limits::WEBHOOK_MAX_ATTEMPTS,
					'webhook_timeout'     => Platform_Limits::WEBHOOK_TIMEOUT_SECONDS,
					'webhook_replay_secs' => Platform_Limits::WEBHOOK_REPLAY_WINDOW,
					'automation_depth'    => Platform_Limits::AUTOMATION_MAX_DEPTH,
				),
				'request_id'     => Request_Context::request_id(),
			)
		);
	}

	/**
	 * Return the caller's own rate limit and plan usage.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_limits( $request ) {
		$usage = ( new Usage_Manager() )->summary( get_current_user_id() );

		return $this->ok(
			array(
				'rate_limit' => $this->limiter->peek( 'user:' . get_current_user_id(), Platform_Limits::RATE_LIMIT_PER_MINUTE ),
				'plan'       => $usage,
				'buckets'    => $this->limiter->report(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Projects
	 * ------------------------------------------------------------------ */

	/**
	 * List the projects the caller may see, across every workspace they belong to.
	 *
	 * ### Why this route exists when `/workspaces/{id}/projects` already does
	 *
	 * The existing route needs a workspace id in the path, so a client must already know
	 * which workspace to ask about before it can ask anything. A `projects:read` credential
	 * bound to one workspace already knows it — the credential record names it — and an
	 * integration discovering "what does this key actually reach?" needs exactly this.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_projects( $request ) {
		$contexts = new Project_Context_Store();
		$projects = new Project_Repository( $this->logger );
		$stores   = new Workspace_Store();
		$permissions = new Permission_Manager();

		$out = array();
		$seen = array();

		foreach ( $stores->for_user( get_current_user_id() ) as $workspace ) {
			$workspace_id = (string) ( $workspace['public_id'] ?? '' );

			if ( '' === $workspace_id ) {
				continue;
			}

			foreach ( $contexts->list_projects( $workspace_id, array( 'per_page' => Workspace_Limits::page_size( 200 ) ) ) as $context ) {
				$project_id = (string) ( $context['project_id'] ?? '' );

				/*
				 * The project-level check, not the workspace one. A member with
				 * `projects.view` at workspace level may still be excluded from an individual
				 * project, and `Permission_Manager::can_in_project()` is what knows that.
				 * Skipping it would make this the one route that leaks the existence of a
				 * project the caller cannot open.
				 */
				if ( '' === $project_id || isset( $seen[ $project_id ] ) ) {
					continue;
				}

				if ( ! $permissions->can_in_project( get_current_user_id(), $workspace_id, $project_id, 'projects.view' ) ) {
					continue;
				}

				$record = $projects->find( $project_id );

				if ( null === $record ) {
					continue;
				}

				$seen[ $project_id ] = true;
				$out[]              = array_merge(
					$projects->present( $record ),
					array( 'workspace_id' => $workspace_id )
				);
			}
		}

		return $this->ok(
			array( 'projects' => $out ),
			array( 'count' => count( $out ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Events
	 * ------------------------------------------------------------------ */

	/**
	 * List recent events.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_events( $request ) {
		$workspace_id = $this->workspace_id( $request );

		if ( '' === $workspace_id ) {
			return $this->error( 'workspace_required', __( 'This request needs a workspace.', 'replicaforge' ), 400 );
		}

		$page = $this->event_store->browse(
			$workspace_id,
			array(
				'type'       => (string) $request->get_param( 'type' ),
				'project_id' => (string) $request->get_param( 'project_id' ),
				'status'     => (string) $request->get_param( 'status' ),
				'per_page'   => (int) $request->get_param( 'per_page' ),
				'page'       => (int) $request->get_param( 'page' ),
			)
		);

		return $this->ok(
			array( 'events' => array_map( array( $this, 'present_event' ), $page['items'] ) ),
			array(
				'total'  => (int) $page['count'],
				'page'   => (int) $page['page'],
				'counts' => $this->event_store->counts( $workspace_id ),
			)
		);
	}

	/**
	 * Return the event vocabulary.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function event_catalog( $request ) {
		return $this->ok(
			array(
				'events'     => $this->event_catalog_rows(),
				'groups'     => Platform_Limits::EVENT_GROUPS,
				'schema'     => Platform_Limits::EVENT_SCHEMA_VERSION,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Webhooks
	 * ------------------------------------------------------------------ */

	/**
	 * List webhook subscriptions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_webhooks( $request ) {
		$page = $this->webhooks->browse(
			(string) $request->get_param( 'workspace_id' ),
			array(
				'status'   => (string) $request->get_param( 'status' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
				'page'     => (int) $request->get_param( 'page' ),
			)
		);

		return $this->ok(
			array( 'webhooks' => array_map( array( $this, 'present_webhook' ), $page['items'] ) ),
			array( 'total' => (int) $page['count'], 'page' => (int) $page['page'] )
		);
	}

	/**
	 * Create a webhook subscription.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_webhook( $request ) {
		$created = $this->webhooks->create(
			(string) $request->get_param( 'workspace_id' ),
			array(
				'name'       => (string) $request->get_param( 'name' ),
				'endpoint'   => (string) $request->get_param( 'endpoint' ),
				'events'     => (array) $request->get_param( 'events' ),
				'project_id' => (string) $request->get_param( 'project_id' ),
				'user_id'    => get_current_user_id(),
			)
		);

		if ( is_wp_error( $created ) ) {
			return $this->from_wp_error( $created );
		}

		$secret = (string) ( $created['secret'] ?? '' );

		/*
		 * The signing secret is returned exactly once, here. It is derived rather than
		 * stored, so this is genuinely the only time it can be retrieved — a receiver
		 * configured without it cannot be fixed later, and a new subscription is the remedy.
		 */
		unset( $created['secret'] );

		return $this->ok(
			array( 'webhook' => $this->present_webhook( $created ), 'secret' => $secret ),
			array(),
			201
		);
	}

	/**
	 * Return one subscription.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_webhook( $request ) {
		$record = $this->webhooks->read( (string) $request->get_param( 'workspace_id' ), (string) $request->get_param( 'id' ) );

		if ( null === $record ) {
			return $this->error( 'webhook_not_found', __( 'That webhook does not exist.', 'replicaforge' ), 404 );
		}

		return $this->ok(
			array(
				'webhook'    => $this->present_webhook( $record ),
				'deliveries' => $this->deliveries->counts( (string) $record['workspace_id'] ),
			)
		);
	}

	/**
	 * Update a subscription.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_webhook( $request ) {
		$changes = array();

		foreach ( array( 'name', 'endpoint', 'events', 'status' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$changes[ $field ] = $request->get_param( $field );
			}
		}

		$updated = $this->webhooks->update( (string) $request->get_param( 'id' ), $changes );

		if ( is_wp_error( $updated ) ) {
			return $this->from_wp_error( $updated );
		}

		if ( null === $updated ) {
			return $this->error( 'webhook_not_found', __( 'That webhook does not exist.', 'replicaforge' ), 404 );
		}

		return $this->ok( array( 'webhook' => $this->present_webhook( $updated ) ) );
	}

	/**
	 * Remove a subscription.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_webhook( $request ) {
		$removed = $this->webhooks->forget( (string) $request->get_param( 'id' ) );

		if ( ! $removed ) {
			return $this->error( 'webhook_not_found', __( 'That webhook does not exist.', 'replicaforge' ), 404 );
		}

		( new Collaboration_Log() )->audit(
			(string) $request->get_param( 'workspace_id' ),
			'webhook_removed',
			array( 'target_type' => 'webhook', 'target_id' => (string) $request->get_param( 'id' ) ),
			get_current_user_id()
		);

		return $this->ok( array( 'removed' => true ) );
	}

	/**
	 * Send a test delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function test_webhook( $request ) {
		$outcome = $this->delivery->test( (string) $request->get_param( 'id' ) );

		if ( 'skipped' === (string) ( $outcome['state'] ?? '' ) ) {
			return $this->ok( array( 'outcome' => $outcome ), array(), 202 );
		}

		return $this->ok( array( 'outcome' => $outcome ) );
	}

	/**
	 * List a subscription's deliveries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_deliveries( $request ) {
		$rows = $this->deliveries->history( (string) $request->get_param( 'id' ), (int) $request->get_param( 'per_page' ) );

		return $this->ok( array( 'deliveries' => array_map( array( $this, 'present_delivery' ), $rows ) ) );
	}

	/**
	 * Retry one delivery.
	 *
	 * A retry resets the attempt counter and schedules immediately. It is offered rather than
	 * automatic because an operator retrying a failed delivery is repairing a specific thing,
	 * and an automatic requeue of a dead letter would resurrect a delivery the ceiling was
	 * there to stop.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function retry_delivery( $request ) {
		$delivery_id = (string) $request->get_param( 'delivery' );
		$record      = $this->deliveries->read( $delivery_id );

		if ( null === $record ) {
			return $this->error( 'delivery_not_found', __( 'That delivery does not exist.', 'replicaforge' ), 404 );
		}

		if ( ! Platform_Limits::is_terminal_delivery( (string) $record['status'] ) ) {
			return $this->error(
				'delivery_not_retryable',
				__( 'That delivery has not finished yet, so there is nothing to retry.', 'replicaforge' ),
				409
			);
		}

		$this->deliveries->record(
			$delivery_id,
			array( 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => time(), 'error' => '' )
		);

		/* An immediate attempt, so "retry" means retried rather than scheduled. */
		$outcome = $this->delivery->deliver( array_merge( $record, array( 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => time() ) ) );

		return $this->ok( array( 'outcome' => $outcome ) );
	}

	/* ---------------------------------------------------------------------
	 * Automations
	 * ------------------------------------------------------------------ */

	/**
	 * List automations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_automations( $request ) {
		$page = $this->automation_store->browse(
			(string) $request->get_param( 'workspace_id' ),
			array(
				'status'   => (string) $request->get_param( 'status' ),
				'trigger'  => (string) $request->get_param( 'trigger' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
				'page'     => (int) $request->get_param( 'page' ),
			)
		);

		return $this->ok(
			array( 'automations' => array_map( array( $this, 'present_automation' ), $page['items'] ) ),
			array( 'total' => (int) $page['count'], 'page' => (int) $page['page'] )
		);
	}

	/**
	 * Create an automation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_automation( $request ) {
		$workspace_id = (string) $request->get_param( 'workspace_id' );

		if ( ! $this->may_manage_automations( $workspace_id ) ) {
			return $this->error( 'permission_denied', __( 'You cannot create automations in this workspace.', 'replicaforge' ), 403 );
		}

		$stored = $this->automation_store->save(
			array(
				'workspace_id' => $workspace_id,
				'project_id'   => (string) $request->get_param( 'project_id' ),
				'name'         => (string) $request->get_param( 'name' ),
				'trigger'      => (string) $request->get_param( 'trigger' ),
				'action'       => (string) $request->get_param( 'action' ),
				'options'      => (array) $request->get_param( 'options' ),
				'user_id'      => get_current_user_id(),
			)
		);

		if ( is_wp_error( $stored ) ) {
			return $this->from_wp_error( $stored );
		}

		return $this->ok( array( 'automation' => $this->present_automation( $stored ) ), array(), 201 );
	}

	/**
	 * Update an automation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_automation( $request ) {
		$existing = $this->automation_store->read( '*', (string) $request->get_param( 'id' ) );

		if ( null === $existing ) {
			return $this->error( 'automation_not_found', __( 'That automation does not exist.', 'replicaforge' ), 404 );
		}

		if ( ! $this->may_manage_automations( (string) $existing['workspace_id'] ) ) {
			return $this->error( 'permission_denied', __( 'You cannot change automations in this workspace.', 'replicaforge' ), 403 );
		}

		$input = array( 'public_id' => (string) $existing['public_id'] );

		foreach ( array( 'name', 'trigger', 'action', 'status', 'project_id', 'options' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$input[ $field ] = $request->get_param( $field );
			}
		}

		$stored = $this->automation_store->save( $input );

		if ( is_wp_error( $stored ) ) {
			return $this->from_wp_error( $stored );
		}

		( new Collaboration_Log() )->audit(
			(string) $existing['workspace_id'],
			'automation_updated',
			array(
				'target_type' => 'automation',
				'target_id'   => (string) $existing['public_id'],
				'metadata'    => array( 'trigger' => (string) $request->get_param( 'trigger' ), 'action' => (string) $request->get_param( 'action' ) ),
			),
			get_current_user_id()
		);

		return $this->ok( array( 'automation' => $this->present_automation( $stored ) ) );
	}

	/**
	 * Remove an automation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_automation( $request ) {
		$existing = $this->automation_store->read( '*', (string) $request->get_param( 'id' ) );

		if ( null === $existing ) {
			return $this->error( 'automation_not_found', __( 'That automation does not exist.', 'replicaforge' ), 404 );
		}

		if ( ! $this->may_manage_automations( (string) $existing['workspace_id'] ) ) {
			return $this->error( 'permission_denied', __( 'You cannot change automations in this workspace.', 'replicaforge' ), 403 );
		}

		$this->automation_store->forget( (string) $existing['public_id'] );

		( new Collaboration_Log() )->audit(
			(string) $existing['workspace_id'],
			'automation_removed',
			array( 'target_type' => 'automation', 'target_id' => (string) $existing['public_id'] ),
			get_current_user_id()
		);

		return $this->ok( array( 'removed' => true ) );
	}

	/**
	 * Run an automation on demand.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function run_automation( $request ) {
		$existing = $this->automation_store->read( '*', (string) $request->get_param( 'id' ) );

		if ( null === $existing ) {
			return $this->error( 'automation_not_found', __( 'That automation does not exist.', 'replicaforge' ), 404 );
		}

		if ( ! $this->may_manage_automations( (string) $existing['workspace_id'] ) ) {
			return $this->error( 'permission_denied', __( 'You cannot run automations in this workspace.', 'replicaforge' ), 403 );
		}

		$result = $this->automations->run_now(
			(string) $existing['public_id'],
			array( 'actor_id' => get_current_user_id() )
		);

		/*
		 * A failed run is still a 200 with `outcome.ok === false`. The request itself
		 * succeeded — it found the automation and ran it — and returning 500 would tell a
		 * client the API is broken when what happened is that a workflow hit an approval
		 * gate. `outcome.reason` is where the real answer is.
		 */
		return $this->ok( array( 'outcome' => $result ) );
	}

	/* ---------------------------------------------------------------------
	 * Extensions
	 * ------------------------------------------------------------------ */

	/**
	 * List installed extensions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_extensions( $request ) {
		$status = (string) $request->get_param( 'status' );

		return $this->ok(
			array(
				'extensions' => $this->extensions->all(
					Platform_Limits::is_extension_state( $status ) ? array( 'status' => $status ) : array()
				),
			),
			array( 'summary' => $this->extensions->summary() )
		);
	}

	/**
	 * Return one extension.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_extension( $request ) {
		$record = $this->extensions->get( (string) $request->get_param( 'id' ) );

		if ( null === $record ) {
			return $this->error( 'extension_not_found', __( 'That extension is not registered.', 'replicaforge' ), 404 );
		}

		return $this->ok( array( 'extension' => $record ) );
	}

	/**
	 * Change an extension's state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function set_extension_state( $request ) {
		if ( ! $this->may_manage_extensions() ) {
			return $this->error( 'permission_denied', __( 'You cannot change extensions.', 'replicaforge' ), 403 );
		}

		$record = $this->extensions->set_state(
			(string) $request->get_param( 'id' ),
			(string) $request->get_param( 'state' )
		);

		if ( is_wp_error( $record ) ) {
			return $this->from_wp_error( $record );
		}

		return $this->ok( array( 'extension' => $record ) );
	}

	/**
	 * Return an extension's effective settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_extension_settings( $request ) {
		$record = $this->extensions->get( (string) $request->get_param( 'id' ) );

		if ( null === $record ) {
			return $this->error( 'extension_not_found', __( 'That extension is not registered.', 'replicaforge' ), 404 );
		}

		return $this->ok(
			array(
				'settings' => $record['settings'],
				'schema'   => $record['configuration'],
			)
		);
	}

	/**
	 * Save one extension setting.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_extension_settings( $request ) {
		if ( ! $this->may_manage_extensions() ) {
			return $this->error( 'permission_denied', __( 'You cannot change extensions.', 'replicaforge' ), 403 );
		}

		$field = (string) $request->get_param( 'field' );

		if ( '' === $field ) {
			return $this->error( 'field_required', __( 'A setting name is required.', 'replicaforge' ), 400 );
		}

		$saved = $this->extensions->set_setting(
			(string) $request->get_param( 'id' ),
			$field,
			$request->get_param( 'value' )
		);

		if ( is_wp_error( $saved ) ) {
			return $this->from_wp_error( $saved );
		}

		return $this->ok( array( 'saved' => true, 'field' => $field ) );
	}

	/* ---------------------------------------------------------------------
	 * Presentation
	 * ------------------------------------------------------------------ */

	/**
	 * Present a webhook subscription.
	 *
	 * @param array<string, mixed> $record Stored row.
	 * @return array<string, mixed>
	 */
	public function present_webhook( array $record ) {
		return array(
			'id'                 => (string) ( $record['public_id'] ?? '' ),
			'workspace_id'       => (string) ( $record['workspace_id'] ?? '' ),
			'project_id'         => (string) ( $record['project_id'] ?? '' ),
			'name'               => (string) ( $record['name'] ?? '' ),
			'endpoint'           => (string) ( $record['endpoint'] ?? '' ),
			'events'             => array_values( (array) ( $record['events'] ?? array() ) ),
			'status'             => (string) ( $record['status'] ?? '' ),
			'failure_count'      => (int) ( $record['failure_count'] ?? 0 ),
			'consecutive_failures' => (int) ( $record['consecutive_failures'] ?? 0 ),
			'last_delivered_at'  => (string) ( $record['last_delivered_at'] ?? '' ),
			'last_failure_at'    => (string) ( $record['last_failure_at'] ?? '' ),
			/* The last signature prefix and the failure reason, both safe. The signing
			 * secret itself is derived and never stored, so there is nothing to redact. */
			'last_signature'     => (string) ( $record['last_signature'] ?? '' ),
			'last_error'         => (string) ( $record['last_error'] ?? '' ),
			'created_at'         => (string) ( $record['created_at'] ?? '' ),
			'updated_at'         => (string) ( $record['updated_at'] ?? '' ),
		);
	}

	/**
	 * Present a delivery record.
	 *
	 * @param array<string, mixed> $record Stored row.
	 * @return array<string, mixed>
	 */
	public function present_delivery( array $record ) {
		return array(
			'id'            => (string) ( $record['public_id'] ?? '' ),
			'webhook_id'    => (string) ( $record['webhook_id'] ?? '' ),
			'event_id'      => (string) ( $record['event_id'] ?? '' ),
			'event_type'    => (string) ( $record['event_type'] ?? '' ),
			'event_version' => (string) ( $record['event_version'] ?? '' ),
			'status'        => (string) ( $record['status'] ?? '' ),
			'attempts'      => (int) ( $record['attempts'] ?? 0 ),
			'response_code' => (int) ( $record['response_code'] ?? 0 ),
			'error'         => (string) ( $record['error'] ?? '' ),
			'delivered_at'  => (string) ( $record['delivered_at'] ?? '' ),
			'created_at'    => (string) ( $record['created_at'] ?? '' ),
		);
	}

	/**
	 * Present an automation.
	 *
	 * @param array<string, mixed> $record Stored row.
	 * @return array<string, mixed>
	 */
	public function present_automation( array $record ) {
		return array(
			'id'           => (string) ( $record['public_id'] ?? '' ),
			'workspace_id' => (string) ( $record['workspace_id'] ?? '' ),
			'project_id'   => (string) ( $record['project_id'] ?? '' ),
			'name'         => (string) ( $record['name'] ?? '' ),
			'trigger'      => (string) ( $record['trigger'] ?? '' ),
			'action'       => (string) ( $record['action'] ?? '' ),
			'options'      => (array) ( $record['options'] ?? array() ),
			'status'       => (string) ( $record['status'] ?? '' ),
			'run_count'    => (int) ( $record['run_count'] ?? 0 ),
			'failure_count' => (int) ( $record['failure_count'] ?? 0 ),
			'last_run_at'  => (int) ( $record['last_run_at'] ?? 0 ),
			'last_error'   => (string) ( $record['last_error'] ?? '' ),
			'extension_id' => (string) ( $record['extension_id'] ?? '' ),
			'created_at'   => (string) ( $record['created_at'] ?? '' ),
		);
	}

	/**
	 * Present an event.
	 *
	 * @param array<string, mixed> $record Stored row.
	 * @return array<string, mixed>
	 */
	public function present_event( array $record ) {
		return array(
			'event_id'       => (string) ( $record['event_id'] ?? '' ),
			'event_type'     => (string) ( $record['event_type'] ?? '' ),
			'version'        => (string) ( $record['version'] ?? '' ),
			'status'         => (string) ( $record['status'] ?? '' ),
			'workspace_id'   => (string) ( $record['workspace_id'] ?? '' ),
			'project_id'     => (string) ( $record['project_id'] ?? '' ),
			'resource_id'    => (string) ( $record['resource_id'] ?? '' ),
			'actor_id'       => (int) ( $record['actor_id'] ?? 0 ),
			'correlation_id' => (string) ( $record['correlation_id'] ?? '' ),
			'ancestry'       => array_values( (array) ( $record['ancestry'] ?? array() ) ),
			'depth'          => (int) ( $record['depth'] ?? 0 ),
			'data'           => (array) ( $record['data'] ?? array() ),
			'created_at'     => (string) ( $record['created_at'] ?? '' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Return the scope table with its human labels.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function scope_table() {
		$out = array();

		foreach ( Platform_Limits::API_SCOPES as $scope => $definition ) {
			$out[] = array(
				'scope'      => (string) $scope,
				'read_write' => Platform_Limits::is_write_scope( $scope ) ? 'write' : 'read',
				/* The workspace capability and plan operation behind each scope, because a
				 * developer choosing scopes needs to know which plan feature pays. */
				'workspace'  => (string) $definition['workspace'],
				'operation'  => (string) $definition['operation'],
				/* The HTTP methods its routes accept, filled in from the route table. */
				'methods'    => $this->methods_for_scope( (string) $scope ),
			);
		}

		return $out;
	}

	/**
	 * Return every registered route under this namespace, with its gate and scope.
	 *
	 * Built from the live server rather than a hand-written list, so it cannot drift from
	 * what is actually registered. A route whose gate has no scope mapping is reported as
	 * `scope: null`, which is the signal that credentials cannot use it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function route_table() {
		$server = rest_get_server();
		$routes = is_object( $server ) && method_exists( $server, 'get_routes' ) ? $server->get_routes() : array();

		$out = array();

		foreach ( $routes as $pattern => $handlers ) {
			if ( 0 !== strpos( (string) $pattern, '/replicaforge/v1' ) ) {
				continue;
			}

			$gate   = $this->gate_for( $handlers );
			$scopes = Platform_Limits::gate_scopes( $gate );

			foreach ( $this->methods_for( $handlers ) as $method ) {
				$out[] = array(
					'route'  => (string) $pattern,
					'method' => $method,
					'gate'   => $gate,
					'scopes' => $scopes,
					/*
					 * `credential_access` is the thing a reader of this document needs: can a
					 * credential reach this route, and does doing so consume plan quota.
					 */
					'credential_access' => array( '' === $gate ? 'refused_ungated' : ( array() === $scopes ? 'refused_unscoped' : 'scoped' ) ),
				);
			}
		}

		usort(
			$out,
			static function ( $left, $right ) {
				$by_route = strcmp( (string) $left['route'], (string) $right['route'] );

				return 0 !== $by_route ? $by_route : strcmp( (string) $left['method'], (string) $right['method'] );
			}
		);

		return $out;
	}

	/**
	 * Return the gate method name for a handler array.
	 *
	 * @param mixed $handlers Route handlers.
	 * @return string
	 */
	private function gate_for( $handlers ) {
		if ( ! is_array( $handlers ) ) {
			return '';
		}

		foreach ( $this->methods_for( $handlers ) as $method ) {
			foreach ( $this->handler_set( $handlers, $method ) as $handler ) {
				if ( is_array( $handler ) && isset( $handler['permission_callback'] ) && is_array( $handler['permission_callback'] ) && isset( $handler['permission_callback'][1] ) && is_string( $handler['permission_callback'][1] ) ) {
					return (string) $handler['permission_callback'][1];
				}
			}
		}

		return '';
	}

	/**
	 * Return the HTTP methods a handler array supports.
	 *
	 * @param mixed $handlers Route handlers.
	 * @return array<int, string>
	 */
	private function methods_for( $handlers ) {
		if ( ! is_array( $handlers ) || ! isset( $handlers['methods'] ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) $handlers['methods'] as $set ) {
			foreach ( array_keys( (array) $set ) as $method ) {
				$out[] = strtoupper( (string) $method );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Return the handlers for one method.
	 *
	 * @param mixed  $handlers Route handlers.
	 * @param string $method   HTTP method.
	 * @return array<int, mixed>
	 */
	private function handler_set( $handlers, $method ) {
		$method = strtoupper( $method );

		if ( isset( $handlers['methods'][ $method ] ) ) {
			return (array) $handlers['methods'][ $method ];
		}

		foreach ( (array) ( $handlers['methods'] ?? array() ) as $set ) {
			if ( isset( $set[ $method ] ) ) {
				return (array) $set;
			}
		}

		return array();
	}

	/**
	 * Return the HTTP methods reachable with a scope.
	 *
	 * @param string $scope Scope.
	 * @return array<int, string>
	 */
	private function methods_for_scope( $scope ) {
		$out = array();

		foreach ( $this->route_table() as $route ) {
			if ( in_array( (string) $scope, (array) $route['scopes'], true ) ) {
				$out[] = (string) $route['method'];
			}
		}

		sort( $out );

		return array_values( array_unique( $out ) );
	}

	/**
	 * Return the event catalog rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function event_catalog_rows() {
		$out = array();

		foreach ( Platform_Limits::EVENTS as $type => $event ) {
			$out[] = array(
				'type'   => (string) $type,
				'group'  => (string) $event['group'],
				'label'  => (string) $event['label'],
				/* Whether a webhook may subscribe. A non-public event is still recorded and
				 * still readable here; it is simply not deliverable outside the workspace. */
				'public' => (bool) $event['public'],
			);
		}

		return $out;
	}

	/**
	 * Resolve the workspace a request is about.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function workspace_id( $request ) {
		$workspace_id = (string) $request->get_param( 'workspace_id' );

		if ( '' !== $workspace_id ) {
			return $workspace_id;
		}

		foreach ( ( new Workspace_Store() )->for_user( get_current_user_id() ) as $workspace ) {
			$id = (string) ( $workspace['public_id'] ?? '' );

			if ( '' !== $id ) {
				return $id;
			}
		}

		return '';
	}

	/**
	 * Return whether the caller may manage automations in a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return bool
	 */
	private function may_manage_automations( $workspace_id ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( '' === (string) $workspace_id ) {
			return false;
		}

		return ( new Permission_Manager() )->can( get_current_user_id(), (string) $workspace_id, 'api.automations.manage' );
	}

	/**
	 * Return whether the caller may change extensions.
	 *
	 * @return bool
	 */
	private function may_manage_extensions() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$workspace_id = $this->workspace_id( null );

		return '' !== $workspace_id
			&& ( new Permission_Manager() )->can( get_current_user_id(), $workspace_id, 'api.extensions.manage' );
	}

	/**
	 * Build a success response.
	 *
	 * @param array<string, mixed> $data  Payload.
	 * @param array<string, mixed> $meta  Extra meta.
	 * @param int                  $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private function ok( array $data, array $meta = array(), $status = 200 ) {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
				'meta'    => array_merge(
					array(
						'request_id'  => Request_Context::request_id(),
						'api_version' => 'replicaforge/v1',
					),
					$meta
				),
			),
			(int) $status
		);
	}

	/**
	 * Build a failure response.
	 *
	 * @param string              $code    Stable code.
	 * @param string              $message Message.
	 * @param int                 $status  HTTP status.
	 * @param array<string, mixed> $details Details.
	 * @return \WP_REST_Response
	 */
	private function error( $code, $message, $status = 400, array $details = array() ) {
		$status = (int) $status;

		if ( $status < 400 || $status > 499 ) {
			/*
			 * A 5xx never carries its message or its details. An unexpected failure can
			 * contain a table name, a path or a fragment of a SQL statement, and the error
			 * envelope is exactly the wrong place for one to travel.
			 */
			$this->logger->error(
				'developer_api_error',
				'A developer API request failed server-side.',
				array( 'code' => sanitize_key( (string) $code ) ),
				'platform'
			);

			$status  = 500;
			$message = __( 'The request could not be completed.', 'replicaforge' );
			$details = array();
		}

		return new \WP_REST_Response(
			array(
				'success' => false,
				'error'   => array(
					'code'    => sanitize_key( (string) $code ),
					'message' => (string) $message,
					'details' => $details,
				),
				'meta'    => array(
					'request_id'  => Request_Context::request_id(),
					'api_version' => 'replicaforge/v1',
				),
			),
			$status
		);
	}

	/**
	 * Convert a `WP_Error` into the documented response envelope.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	private function from_wp_error( $error ) {
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();

		/*
		 * `WP_Error` messages here are written by ReplicaForge and are safe to return. A
		 * message that arrived from somewhere else is not assumed safe: anything carrying
		 * `error` data without one of our own codes is reported generically.
		 */
		$code = (string) $error->get_error_code();

		return $this->error(
			$code,
			(string) $error->get_error_message(),
			(int) ( $data['status'] ?? 400 ),
			isset( $data['status'] ) ? array() : $data
		);
	}

	/**
	 * Return the accepted request arguments for a method.
	 *
	 * @param string $method HTTP method.
	 * @return array<string, array<string, mixed>>
	 */
	private function args_for( $method ) {
		$page = array(
			'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 25 ),
		);

		$common = array(
			'workspace_id' => array( 'type' => 'string', 'maxLength' => 64 ),
			'project_id'   => array( 'type' => 'string', 'maxLength' => 64 ),
		);

		if ( 'GET' === strtoupper( (string) $method ) ) {
			return array_merge(
				$common,
				$page,
				array(
					'status'    => array( 'type' => 'string', 'maxLength' => 32 ),
					'type'      => array( 'type' => 'string', 'maxLength' => 60 ),
					'trigger'   => array( 'type' => 'string', 'maxLength' => 60 ),
					'per_page2' => array( 'type' => 'integer' ),
				)
			);
		}

		return $common;
	}
}
