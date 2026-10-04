<?php
/**
 * Plugin bootstrap and dependency wiring.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Phase 1, Phase 2, and optional Phase 3 services into WordPress hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * URL validator.
	 *
	 * @var Url_Validator
	 */
	private $validator;

	/**
	 * Safe HTTP client.
	 *
	 * @var Http_Client
	 */
	private $http_client;

	/**
	 * HTML parser.
	 *
	 * @var Html_Parser
	 */
	private $parser;

	/**
	 * Phase 2 design-understanding service.
	 *
	 * @var Design_Analyzer
	 */
	private $design_analyzer;

	/**
	 * Analyzer service.
	 *
	 * @var Analyzer
	 */
	private $analyzer;

	/**
	 * REST controller.
	 *
	 * @var Rest_Api
	 */
	private $rest_api;

	/**
	 * Phase 3 AI manager.
	 *
	 * @var Ai_Manager
	 */
	private $ai_manager;

	/**
	 * Phase 3 AI settings service.
	 *
	 * @var Ai_Settings
	 */
	private $ai_settings;

	/**
	 * Admin controller.
	 *
	 * @var Admin
	 */
	private $admin;

	/**
	 * Optional Phase 4 Elementor generator.
	 *
	 * @var Elementor_Generator
	 */
	private $elementor_generator;

	/**
	 * Optional Phase 5 validation engine.
	 *
	 * @var Validation_Engine
	 */
	private $validation_engine;

	/**
	 * Optional Phase 5 validation report builder.
	 *
	 * @var Validation_Report
	 */
	private $validation_report;

	/**
	 * Optional Phase 5 validation cache.
	 *
	 * @var Validation_Cache
	 */
	private $validation_cache;

	/**
	 * Optional Phase 5 visual renderer.
	 *
	 * @var Visual_Renderer
	 */
	private $visual_renderer;

	/**
	 * Optional Phase 6 correction engine.
	 *
	 * @var Correction_Engine
	 */
	private $correction_engine;

	/**
	 * Optional Phase 6 correction report builder.
	 *
	 * @var Correction_Report
	 */
	private $correction_report;

	/**
	 * Optional Phase 6 correction history.
	 *
	 * @var Correction_History
	 */
	private $correction_history;

	/**
	 * Phase 7 structured logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Phase 7 job runner.
	 *
	 * @var Job_Runner
	 */
	private $job_runner;

	/**
	 * Phase 7 scheduled maintenance.
	 *
	 * @var Maintenance
	 */
	private $maintenance;

	/**
	 * Phase 7 migrator.
	 *
	 * @var Migrator
	 */
	private $migrator;

	/**
	 * Phase 7 system status.
	 *
	 * @var System_Status
	 */
	private $system_status;

	/**
	 * Phase 11 job orchestrator.
	 *
	 * Held rather than constructed locally in `boot()` because the lock set must be
	 * shared between admission and execution within one request, and because the
	 * shutdown handler that releases locks needs a reference that outlives the call
	 * that registered it.
	 *
	 * @var Job_Manager|null
	 */
	private $orchestrator;

	/**
	 * Phase 12 multi-page controller.
	 *
	 * Held for the same reason as the orchestrator: the registry it owns carries
	 * component identity and user overrides, and those must be the *same* registry
	 * across the request that analysed a website and the request that later reads or
	 * protects it. A fresh registry per request would forget every override and make
	 * §47's protection a no-op.
	 *
	 * @var Multi_Page_Api|null
	 */
	private $multipage_api;

	/**
	 * Phase 13 visual controller.
	 *
	 * @var Visual_Api|null
	 */
	private $visual_api;

	/**
	 * Phase 14 content service.
	 *
	 * @var Content_Service|null
	 */
	private $content_service;

	/**
	 * Phase 14 content controller.
	 *
	 * @var Content_Api|null
	 */
	private $content_api;

	/**
		* The Phase 15 collaboration log.
		*
		* @var Collaboration_Log|null
	*/
	private $collaboration_log;

	/**
		* The Phase 15 REST layer.
		*
		* @var Workspace_Api|null
	*/
	private $workspace_api;

	/**
		* The Phase 15 admin screens.
		*
		* Separate from `Admin` because those seven pages are site-wide and keep their
		* `manage_options` gate. These are workspace-scoped and gate on a resolved
		* capability, so a designer can reach the projects screen and not the approve button.
		*
		* @var Workspace_Admin|null
	*/
	private $workspace_admin;

	/**
		* The Phase 16 interaction service.
		*
		* Constructed once and shared, because it holds the browser driver and the
		* observation cache key material, and a second instance would be a second
		* answer to "is a browser configured".
		*
		* @var Interaction_Service|null
	*/
	private $interactions;

	/**
		* The Phase 16 REST layer.
		*
		* @var Interaction_Api|null
	*/
	private $interaction_api;

	/**
	 * The Phase 17 capability registry.
	 *
	 * Built once and shared. A probe is not free - the Elementor probe costs about six
	 * megabytes on a real install - and a workflow asks about capabilities once per
	 * stage, plus once in preflight, plus once per report.
	 *
	 * Deliberately NOT named 'orchestrator'. That property already holds phase
	 * 11's Job_Manager, and reusing the name would silently replace the job queue's
	 * orchestrator with a stage executor. The two are different things and the
	 * collision would only show up as a missing job tick.
	 *
	 * @var Capability_Registry|null
	 */
	private $capabilities;

	/**
	 * The Phase 17 workflow repository.
	 *
	 * @var Workflow_Repository|null
	 */
	private $workflows;

	/**
	 * The Phase 17 stage executor.
	 *
	 * @var Workflow_Executor|null
	 */
	private $workflow_executor;

	/**
	 * The Phase 17 REST layer.
	 *
	 * @var Orchestrator_Api|null
	 */
	private $orchestrator_api;

	/**
	 * The Phase 17 admin screens.
	 *
	 * @var Orchestrator_Admin|null
	 */
	private $orchestrator_admin;

	/**
	 * Phase 19. The template library REST surface.
	 *
	 * @var Template_Api|null
	 */
	private $template_api;

	/**
	 * Phase 20: the developer platform.
	 *
	 * Declared as a group rather than one-by-one because they form a single connected graph:
	 * the dispatcher and the automation runner reference each other, and both the API and the
	 * console need the same four stores. Splitting them across the file would suggest they are
	 * independent, which is the opposite of the reason the order in `boot()` matters.
	 *
	 * @var Rate_Limiter
	 */
	private $rate_limiter;

	/**
	 * @var Extension_Registry
	 */
	private $extension_registry;

	/**
	 * @var Event_Store
	 */
	private $event_store;

	/**
	 * @var Webhook_Store
	 */
	private $webhook_store;

	/**
	 * @var Webhook_Delivery_Store
	 */
	private $webhook_deliveries;

	/**
	 * @var Webhook_Delivery
	 */
	private $webhook_delivery;

	/**
	 * @var Event_Dispatcher
	 */
	private $event_dispatcher;

	/**
	 * @var Automation_Store
	 */
	private $automation_store;

	/**
	 * @var Automation_Runner
	 */
	private $automation_runner;

	/**
	 * @var Api_Credential_Store
	 */
	private $api_credentials;

	/**
	 * @var Api_Authenticator
	 */
	private $api_authenticator;

	/**
	 * @var Developer_Api
	 */
	private $developer_api;

	/**
	 * @var Developer_Admin
	 */
	private $developer_admin;

	/**
	 * Phase 19. The template library admin screen.
	 *
	 * @var Template_Admin|null
	 */
	private $template_admin;

	/**
	 * Return the job orchestrator.
	 *
	 * @return Job_Manager|null Null before `boot()` has run.
	 */
	public function orchestrator() {
		return $this->orchestrator;
	}

	/**
	 * Return the multi-page controller.
	 *
	 * @return Multi_Page_Api|null Null before `boot()` has run.
	 */
	public function multipage_api() {
		return $this->multipage_api;
	}

	/**
	 * Return the visual controller.
	 *
	 * @return Visual_Api|null Null before `boot()` has run.
	 */
	public function visual_api() {
		return $this->visual_api;
	}

	/**
	 * Return the content intelligence service.
	 *
	 * @return Content_Service|null Null before `boot()` has run.
	 */
	public function content_service() {
		return $this->content_service;
	}

	/**
	 * Return the content intelligence controller.
	 *
	 * @return Content_Api|null Null before `boot()` has run.
	 */
	public function content_api() {
		return $this->content_api;
	}
	/**
	 * Return the Phase 15 collaboration log.
	 *
	 * @return Collaboration_Log|null Null before `boot()` has run.
	 */
	public function collaboration_log() {
		return $this->collaboration_log;
	}

	/**
	 * Return the Phase 15 REST layer.
	 *
	 * @return Workspace_Api|null Null before `boot()` has run.
	 */
	public function workspace_api() {
		return $this->workspace_api;
	}

	/**
		* Return the Phase 15 admin screens.
		*
		* @return Workspace_Admin|null Null before `boot()` has run.
	*/
	public function workspace_admin() {
		return $this->workspace_admin;
	}

	/**
		* Return the Phase 16 interaction service.
		*
		* @return Interaction_Service|null Null before `boot()` has run.
	*/
	public function interactions() {
		return $this->interactions;
	}

	/**
		* Return the Phase 16 REST layer.
		*
		* @return Interaction_Api|null Null before `boot()` has run.
	*/
	public function interaction_api() {
		return $this->interaction_api;
	}

	/**
	 * Return the Phase 17 capability registry.
	 *
	 * @return Capability_Registry|null Null before oot() has run.
	 */
	public function capabilities() {
		return $this->capabilities;
	}

	/**
	 * Return the Phase 17 workflow repository.
	 *
	 * @return Workflow_Repository|null
	 */
	public function workflows() {
		return $this->workflows;
	}

	/**
	 * Return the Phase 17 stage executor.
	 *
	 * Named for the workflow it executes. orchestrator() is phase 11's Job_Manager
	 * and stays that way.
	 *
	 * @return Workflow_Executor|null
	 */
	public function workflow_executor() {
		return $this->workflow_executor;
	}

	/**
	 * Return the Phase 17 REST layer.
	 *
	 * @return Orchestrator_Api|null
	 */
	public function orchestrator_api() {
		return $this->orchestrator_api;
	}

	/**
	 * Return the Phase 19 template library API.
	 *
	 * Exposed on the same terms as every other service accessor here, so a caller holding
	 * the plugin instance can reach the template surface without constructing its own — which
	 * is how the admin screen and the orchestrator executor get it in later phases without
	 * duplicating the object graph.
	 *
	 * @return Template_Api|null
	 */
	public function template_api() {
		return $this->template_api;
	}

	/**
	 * Return the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Build the service graph without performing network or parsing work.
	 */
	private function __construct() {
		$this->validator   = new Url_Validator();
		$this->http_client = new Http_Client( $this->validator );
		$this->parser      = new Html_Parser();
		$this->design_analyzer = new Design_Analyzer( null, null, null, null, null, null, null, new Stylesheet_Loader( $this->http_client ) );
		$this->analyzer        = new Analyzer( $this->validator, $this->http_client, $this->parser, $this->design_analyzer );
		$this->ai_settings     = new Ai_Settings();
		$this->ai_manager      = new Ai_Manager( $this->ai_settings );
		$this->elementor_generator = new Elementor_Generator(
			array(
				'assets' => new Elementor_Assets( $this->http_client ),
			)
		);
		$this->visual_renderer     = new Visual_Renderer( new Security() );
		$this->validation_cache    = new Validation_Cache();
		$this->validation_report   = new Validation_Report();
		$this->validation_engine   = new Validation_Engine(
			array(
				'cache'    => $this->validation_cache,
				'renderer' => $this->visual_renderer,
				'differ'   => new Image_Differ( $this->visual_renderer->image_library() ),
				'ai_manager' => $this->ai_manager,
			)
		);

		// Phase 6 shares the Phase 4 mapper and builder so an approved section
		// insert is rebuilt by exactly the same code that generated the page.
		$properties    = new Correction_Property_Map();
		$snapshots     = new Correction_Snapshot( $properties );
		$validator     = new Correction_Validator( $properties );
		$regressions   = new Regression_Detector();
		$registry      = new Elementor_Widget_Registry();
		$document      = new Elementor_Validator( $registry );
		$mapper        = new Elementor_Mapper( $registry );
		$builder       = new Elementor_Document_Builder();
		$repository    = new Elementor_Repository();

		$this->correction_report  = new Correction_Report();
		$this->correction_history = new Correction_History();
		$this->correction_engine  = new Correction_Engine(
			array(
				'properties' => $properties,
				'validator'  => $validator,
				'repository' => $repository,
				'reader'     => new Elementor_Document_Reader( $document, $properties ),
				'planner'    => new Correction_Planner( $properties, new Correction_Eligibility( $properties ), $snapshots ),
				'history'    => $this->correction_history,
				'report'     => $this->correction_report,
				'ai'         => new Ai_Correction_Planner( $this->ai_manager, $validator ),
				'applier'    => new Correction_Applier(
					new Elementor_Document_Writer( $document, $properties, $mapper, $builder ),
					$properties,
					$validator,
					$snapshots,
					$regressions,
					$this->validation_engine
				),
			)
		);

		$this->rest_api        = new Rest_Api( $this->analyzer, $this->ai_manager, $this->elementor_generator, $this->validation_engine, $this->validation_report, $this->validation_cache, $this->visual_renderer, $this->correction_engine, $this->correction_report );

		// Phase 7 services. The logger is created first because the rest report to
		// it, and the runner last because it needs the Phase 1 to 6 services.
		$this->logger        = new Logger();
		$this->migrator      = new Migrator( $this->logger );
		$this->maintenance   = new Maintenance( $this->logger );
		$this->system_status = new System_Status( $this->logger );

		$queue = new Job_Queue( new Job_Repository( $this->logger ), $this->logger );
		$this->job_runner    = new Job_Runner(
			array(
				'queue'             => $queue,
				'logger'            => $this->logger,
				'analyzer'          => $this->analyzer,
				'design_analyzer'   => $this->design_analyzer,
				'ai_manager'        => $this->ai_manager,
				'generator'         => $this->elementor_generator,
				'validation_engine' => $this->validation_engine,
				'validation_cache'  => $this->validation_cache,
				'correction_engine' => $this->correction_engine,
				'flags'             => new Feature_Flags(),
			)
		);

		$this->admin = new Admin(
			$this->ai_manager,
			$this->ai_settings,
			$this->elementor_generator,
			$this->validation_engine,
			$this->validation_report,
			$this->validation_cache,
			$this->visual_renderer,
			$this->correction_engine,
			$this->correction_report,
			$this->correction_history,
			$this->job_runner,
			$this->maintenance,
			$this->migrator,
			$this->system_status,
			$this->logger
		);
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function boot() {
		// A one-minute schedule for job processing. WP-Cron only fires on traffic, so
		// this is a ceiling on how long a queued job waits, not a guarantee. A site
		// with real cron should invoke the hook on its own schedule instead.
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				if ( ! is_array( $schedules ) ) {
					$schedules = array();
				}
				if ( ! isset( $schedules['replicaforge_minute'] ) ) {
					$schedules['replicaforge_minute'] = array(
						'interval' => MINUTE_IN_SECONDS,
						'display'  => __( 'Every minute (ReplicaForge)', 'replicaforge' ),
					);
				}
				return $schedules;
			}
		);

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'maybe_migrate' ), 5 );
		add_action( 'init', array( $this, 'ensure_scheduled' ), 6 );
		add_action( 'rest_api_init', array( $this->rest_api, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this, 'register_job_routes' ) );

		// Phase 10. The plans controller is constructed once and shared, so the
		// per-request plan resolution cache is shared across the routes that read
		// it rather than each route resolving the plan separately.
		$plans_api = new Plans_Api();
		add_action( 'rest_api_init', array( $plans_api, 'register_routes' ) );

		// Phase 11. The orchestrator is constructed once and shared, and it reuses
		// the Phase 7 `Job_Runner` rather than building a second one: a `Job_Lock`
		// per request would mean a lock taken during admission is invisible to the
		// tick that runs the stages.
		$orchestrator = new Job_Manager();
		$orchestrator->set_runner( $this->job_runner );
		$this->orchestrator = $orchestrator;

		// Background work runs on cron so a long operation is not tied to one
		// browser request. A queued job that nobody polls still completes.
		add_action( Maintenance::JOB_HOOK, array( $this, 'process_jobs' ) );
		add_action( Maintenance::DAILY_HOOK, array( $this, 'run_maintenance' ) );

		// Locks are released on every exit including a fatal one. Without this, a
		// process killed mid-stage holds its project lock until the TTL, and the
		// next job on that project waits for no reason.
		register_shutdown_function( array( $orchestrator->locks(), 'release_all' ) );

		// Phase 12. The multi-page controller is registered on the same hook as the
		// other two controllers, and it is given the *same* `Project_Access` the
		// entitlement manager already holds, so ownership is resolved once per request
		// rather than re-derived by each controller. Its registry is shared with the
		// analyzer so a component identity survives between the analysis that created
		// it and the request that reads it.
		$registry      = new Component_Registry( $this->logger );
		$website_store = new Website_Repository( $this->logger );
		$this->multipage_api = new Multi_Page_Api(
			new Entitlement_Manager( null, null, null, $this->logger ),
			new Project_Access(),
			$website_store,
			$registry,
			$this->logger
		);
		add_action( 'rest_api_init', array( $this->multipage_api, 'register_routes' ) );

		// Phase 13. The visual controller is given the *same* `Visual_Renderer` the
		// Phase 5 validation engine already uses, so there is exactly one place a
		// screenshot is captured and exactly one place an SSRF mistake could hide. A
		// second capture path would be a second boundary to audit, and Phase 13 would
		// then have to be argued safe separately from Phase 5.
		$this->visual_api = new Visual_Api(
			array(
				'logger'     => $this->logger,
				'renderers'  => new Renderer_Manager( $this->visual_renderer, $this->logger ),
			)
		);
		add_action( 'rest_api_init', array( $this->visual_api, 'register_routes' ) );

		// Phase 14. The content service is given the same logger and the same project
		// access object the visual layer uses, so ownership, logging, and the read-side
		// security boundary are one implementation rather than two that agree today.
		$this->content_service = new Content_Service(
			array(
				'logger' => $this->logger,
				'user_id' => get_current_user_id(),
			)
		);
		$this->content_api = new Content_Api(
			array(
				'logger'  => $this->logger,
				'content' => $this->content_service,
			)
		);
		add_action( 'rest_api_init', array( $this->content_api, 'register_routes' ) );

		/*
		 * Phase 15: the collaboration layer.
		 *
		 * Constructed here rather than lazily so that the REST routes, the migration and the
		 * notification providers all share one set of stores. Two resolvers would mean two
		 * answers to "may this user act here", which is the failure mode section 11 exists
		 * to prevent.
		 *
		 * The table install is deliberately *not* done here. It belongs to the migration,
		 * which records that it ran and can report what it skipped. Installing on every boot
		 * would make an install silently repair itself, and an install that repairs itself
		 * has a migration record that no longer says what happened.
		 */
		$this->collaboration_log = new Collaboration_Log();

		$this->workspace_api = new Workspace_Api();
		add_action( 'rest_api_init', array( $this->workspace_api, 'register_routes' ) );

/*
 * The screens. Registered in the same place as the routes so the two never
 * drift: a screen with no routes would show nothing, and routes with no screen
 * would be reachable only by a client that knows the path.
 */
$this->workspace_admin = new Workspace_Admin();
$this->workspace_admin->register();

	/*
	 * Phase 16. Constructed here rather than lazily in the accessor so that an
	 * operator filter registering a browser driver at `replicaforge_interaction_driver`
	 * is honoured, and so a failure to build it is a boot-time failure rather than
	 * a surprise on the first REST call.
	 */
	$driver = function_exists( 'apply_filters' )
		? apply_filters( 'replicaforge_interaction_driver', null )
		: null;

	$this->interactions = new Interaction_Service(
		array( 'logger' => $this->logger ),
	);

	if ( $driver instanceof Browser_Driver_Contract ) {
		$this->interactions->set_driver( $driver );
	}

	$this->interaction_api = new Interaction_Api(
		array( 'service' => $this->interactions ),
	);

	add_action( 'rest_api_init', array( $this->interaction_api, 'register_routes' ) );

	/*
	 * Phase 17: the reconstruction orchestrator.
	 *
	 * Built last of everything, because the capability registry measures what actually
	 * loaded rather than what was expected to be there - so every phase above must be
	 * constructed before it is asked a question.
	 */
	$this->capabilities = new Capability_Registry();

	$this->workflows = new Workflow_Repository();

	$this->workflow_executor = new Workflow_Executor(
		$this->workflows,
		$this->capabilities
	);

	$this->orchestrator_api = new Orchestrator_Api(
		array(
			'repository'  => $this->workflows,
			'capabilities' => $this->capabilities,
			'executor'     => $this->workflow_executor,
		),
	);

	add_action( 'rest_api_init', array( $this->orchestrator_api, 'register_routes' ) );

	$this->orchestrator_admin = new Orchestrator_Admin(
		array(
			'repository'  => $this->workflows,
			'capabilities' => $this->capabilities,
			'executor'     => $this->workflow_executor,
		),
	);

		/*
		 * Phase 19: the template library.
		 *
		 * Registered unconditionally, like every other REST surface here, because the routes
		 * are themselves the permission boundary — `Template_Api::may_use()` refuses an
		 * unauthenticated caller before any query runs. Registering only under `is_admin()`
		 * would make the library unreachable to a front-end editor, which is the opposite of
		 * what a workspace feature is for.
		 *
		 * The admin *screen* is genuinely admin-only, since it renders a `wp-admin` page.
		 */
		$this->template_api = new Template_Api( $this->logger );

		add_action( 'rest_api_init', array( $this->template_api, 'register_routes' ) );

		$this->template_admin = new Template_Admin( $this->logger );

		/*
		 * Phase 20: the developer platform.
		 *
		 * Built here rather than lazily, and registered unconditionally for the same reason
		 * every other REST surface above is: the routes are the permission boundary, so they
		 * must exist for any caller, not only for `wp-admin`.
		 *
		 * The dependency order is not arbitrary and is the reason this block sits after
		 * Phase 17 and Phase 19:
		 *
		 * - `Extension_Registry` first, because the developer API reports extensions and an
		 *   extension may contribute automations.
		 * - `Event_Dispatcher` before `Automation_Runner`, because the runner emits failure
		 *   events and the dispatcher needs a runner to fire automations. One of the two has
		 *   to be built first and injected afterwards; `set_automation_runner()` exists for
		 *   exactly that cycle.
		 * - `Api_Authenticator` before `Developer_Api`, because the API is handed the
		 *   authenticator and never builds its own.
		 *
		 * The authenticator's `register()` also installs the `rest_pre_dispatch` scope gate,
		 * which is what stops a read-only credential reaching a write route among the ~130
		 * that Phases 1-19 already registered. That gate is not optional and not conditional
		 * on any Phase 20 route being called.
		 */
		$this->rate_limiter = new Rate_Limiter( $this->logger );

		$this->extension_registry = new Extension_Registry( null, $this->logger );

		$this->event_store        = new Event_Store( null, $this->logger );
		$this->webhook_store      = new Webhook_Store( null, $this->logger );
		$this->webhook_deliveries = new Webhook_Delivery_Store( null, $this->logger );
		$this->webhook_delivery   = new Webhook_Delivery( $this->logger, $this->webhook_store, $this->webhook_deliveries );

		$this->event_dispatcher = new Event_Dispatcher( $this->logger, $this->event_store, $this->webhook_store, $this->webhook_deliveries );

		$this->automation_store  = new Automation_Store( null, $this->logger );
		$this->automation_runner = new Automation_Runner( $this->logger, $this->automation_store, $this->event_dispatcher );

		/*
		 * Closes the dispatcher/runner cycle. Without this, events would be recorded and
		 * delivered to webhooks but would never fire an automation.
		 */
		$this->event_dispatcher->set_automation_runner( $this->automation_runner );

		$this->api_credentials = new Api_Credential_Store( null, $this->logger );

		$this->api_authenticator = new Api_Authenticator(
			$this->api_credentials,
			$this->rate_limiter,
			$this->logger,
			/*
			 * `new Entitlement_Manager()` and `new Permission_Manager()` inline, matching how
			 * every other consumer in this file gets them. Both hold their own state but
			 * share static caches (`Permission_Manager::flush()` is static), so a second
			 * instance is cheap and there is no reason for this block to be the one place
			 * that holds a reference.
			 */
			new Entitlement_Manager(),
			new Permission_Manager()
		);

		/*
		 * Authentication and the scope gate, registered here so the `determine_current_user`
		 * filter is in place for every request — REST or admin, credential or session.
		 */
		$this->api_authenticator->register();

		$this->developer_api = new Developer_Api(
			$this->api_authenticator,
			$this->logger,
			$this->extension_registry,
			$this->event_dispatcher,
			$this->automation_runner,
			$this->api_credentials,
			$this->webhook_store,
			$this->event_store,
			$this->automation_store,
			$this->webhook_deliveries,
			$this->webhook_delivery,
			$this->rate_limiter
		);

		add_action( 'rest_api_init', array( $this->developer_api, 'register_routes' ) );

		$this->developer_admin = new Developer_Admin(
			$this->logger,
			$this->api_credentials,
			$this->webhook_store,
			$this->webhook_delivery,
			$this->webhook_deliveries,
			$this->automation_store,
			$this->automation_runner,
			$this->extension_registry,
			$this->event_store,
			$this->event_dispatcher,
			new Permission_Manager()
		);

		/*
		 * The webhook delivery tick, on the plugin's existing job cron.
		 *
		 * Deliberately *not* a new schedule. A webhook is background work by definition, and
		 * a second cron is a second thing to schedule, a second thing to miss, and a second
		 * thing to reason about when it stops firing. `Webhook_Delivery::tick()` takes
		 * `Job_Lock` for mutual exclusion, so two ticks cannot deliver the same payload.
		 *
		 * It is also deliberately *not* a job in the job queue. `Job_Runner::dispatch()`
		 * switches on `Job_Limits::STAGES` and force-advances anything unrecognised into the
		 * `analyze` stage, so a webhook delivery placed there would be driven into the
		 * reconstruction pipeline. The delivery store is an audit log drained by this hook —
		 * see `Webhook_Delivery_Store`'s docblock for why that is not a second queue.
		 */
		add_action( Maintenance::JOB_HOOK, array( $this, 'process_webhook_deliveries' ) );

		$this->register_event_sources();

		if ( is_admin() ) {
			$this->admin->register();
			$this->orchestrator_admin->register();
			$this->template_admin->register();
			$this->developer_admin->register();
		}
	}

	/**
	 * Subscribe the platform to what the earlier phases already emit.
	 *
	 * ### Why listeners rather than edits to the emitting classes
	 *
	 * Phase 17's executor and Phase 19's installer were written without knowledge of events,
	 * and adding an emit call into each would mean every future change to those classes has to
	 * remember the platform exists. Listening to what they already fire is also what keeps
	 * the event vocabulary honest: the events derive from behaviour that is already tested,
	 * rather than from a declaration that could drift away from it.
	 *
	 * @return void
	 */
	private function register_event_sources() {
		/*
		 * The credential lifecycle. `Api_Credential_Store` fires
		 * `replicaforge_credential_revoked` with the *public id* and never the token, so this
		 * listener can emit the public event without any risk of forwarding a secret.
		 */
		add_action(
			'replicaforge_credential_revoked',
			function ( $credential_id, $record ) {
				$this->event_dispatcher->emit(
					'credential.revoked',
					array(
						'workspace_id' => (string) ( $record['workspace_id'] ?? '' ),
						'resource_id'  => (string) $credential_id,
						'actor_id'     => (int) ( $record['user_id'] ?? 0 ),
						'data'         => array( 'name' => (string) ( $record['name'] ?? '' ) ),
					)
				);
			},
			10,
			2
		);

		/*
		 * Phase 19 template lifecycle. The mapping from action to event type lives here so
		 * that adding a template event does not require touching the template layer.
		 *
		 * `data` is limited to identifiers and a summary. A template's document is never put
		 * in an event payload: an event is delivered to a third-party webhook, and a full
		 * document is exactly the kind of payload §15 says to keep out.
		 */
		$template_events = array(
			'replicaforge_template_created'  => 'template.created',
			'replicaforge_template_imported' => 'template.imported',
			'replicaforge_template_updated'  => 'template.updated',
		);

		foreach ( $template_events as $action => $event_type ) {
			add_action(
				$action,
				function ( $record ) use ( $event_type ) {
					if ( ! is_array( $record ) ) {
						return;
					}

					$workspace_id = (string) ( $record['workspace_id'] ?? '' );

					if ( '' === $workspace_id ) {
						return;
					}

					$this->event_dispatcher->emit(
						$event_type,
						array(
							'workspace_id' => $workspace_id,
							'project_id'   => (string) ( $record['project_id'] ?? '' ),
							'resource_id'  => (string) ( $record['public_id'] ?? $record['template_id'] ?? '' ),
							'actor_id'     => (int) ( $record['user_id'] ?? 0 ),
							'data'         => array(
								'name'    => (string) ( $record['name'] ?? '' ),
								'type'    => (string) ( $record['type'] ?? $record['template_type'] ?? '' ),
								'status'  => (string) ( $record['status'] ?? '' ),
								/* A version number is metadata about a change, not the change
								 * itself, so it is safe to expose where a document is not. */
								'version' => (string) ( $record['version'] ?? $record['current_version'] ?? '' ),
							),
						)
					);
				},
				10,
				1
			);
		}

		/*
		 * Phase 17 workflow lifecycle. `Workflow_Executor::run()` is the only public entry
		 * point and it is synchronous, so listening for the actions it already fires is
		 * enough to learn when a workflow reached a terminal state.
		 */
		add_action(
			'replicaforge_workflow_completed',
			function ( $workflow ) {
				$this->emit_workflow( 'workflow.completed', $workflow );
			},
			10,
			1
		);

		add_action(
			'replicaforge_workflow_failed',
			function ( $workflow ) {
				$this->emit_workflow( 'workflow.failed', $workflow );
			},
			10,
			1
		);
	}

	/**
	 * Emit a workflow event from a Phase 17 workflow record.
	 *
	 * The correlation id is derived from the workflow id rather than the current request, so
	 * every event belonging to one workflow shares a thread even when its stages run in
	 * different PHP processes — which they do, because the executor is synchronous but the
	 * queue that invokes it is not.
	 *
	 * @param string               $event_type Event type.
	 * @param array<string, mixed> $workflow   Workflow record.
	 * @return void
	 */
	private function emit_workflow( $event_type, $workflow ) {
		if ( ! is_array( $workflow ) ) {
			return;
		}

		$workspace_id = (string) ( $workflow['workspace_id'] ?? '' );

		/*
		 * A workflow with no workspace is refused rather than recorded. An event without a
		 * workspace has no permission boundary: nothing downstream could decide whether the
		 * actor may see the resource it names, and that is the property that keeps events out
		 * of a cross-workspace leak.
		 */
		if ( '' === $workspace_id ) {
			return;
		}

		$workflow_id = (string) ( $workflow['workflow_id'] ?? $workflow['public_id'] ?? '' );

		$this->event_dispatcher->emit(
			$event_type,
			array(
				'workspace_id'   => $workspace_id,
				'project_id'     => (string) ( $workflow['project_id'] ?? '' ),
				'resource_id'    => $workflow_id,
				'actor_id'       => (int) ( $workflow['created_by'] ?? $workflow['owner_id'] ?? 0 ),
				'correlation_id' => '' !== $workflow_id ? substr( hash( 'sha256', 'workflow|' . $workflow_id ), 0, 32 ) : '',
				'data'           => array(
					'state' => (string) ( $workflow['state'] ?? '' ),
					'stage' => (string) ( $workflow['stage'] ?? '' ),
					'type'  => (string) ( $workflow['type'] ?? '' ),
					'mode'  => (string) ( $workflow['mode'] ?? '' ),
				),
			)
		);
	}

	/**
	 * Attempt the webhook deliveries that are due.
	 *
	 * @return array<string, mixed>
	 */
	public function process_webhook_deliveries() {
		return $this->webhook_delivery->tick();
	}

	/**
	 * Restore the schedule if it is missing.
	 *
	 * A schedule is lost when a plugin is deactivated and reactivated without its
	 * activation hook firing, or when an update replaces the plugin files. An
	 * unscheduled job queue would silently stop processing, so this repairs it. The
	 * check costs two option reads per request.
	 *
	 * @return void
	 */
	public function ensure_scheduled() {
		if ( wp_next_scheduled( Maintenance::JOB_HOOK ) && wp_next_scheduled( Maintenance::DAILY_HOOK ) ) {
			return;
		}
		$this->maintenance->schedule();
	}

	/**
	 * Run pending migrations once per request, cheaply.
	 *
	 * The check is a single option read, so the common case costs almost nothing.
	 *
	 * @return void
	 */
	public function maybe_migrate() {
		$installed = Schema::installed();
		if ( '' !== $installed && version_compare( $installed, Schema::DB_SCHEMA_VERSION, '>=' ) ) {
			return;
		}
		$this->migrator->run();
	}

	/**
	 * Register the job REST routes.
	 *
	 * @return void
	 */
	public function register_job_routes() {
		( new Job_Api( $this->job_runner, $this->logger, $this->system_status, $this->maintenance ) )->register_routes();
	}

	/**
	 * Process queued jobs.
	 *
	 * @return array<string, mixed>
	 */
	public function process_jobs() {
		$flags = new Feature_Flags();
		if ( ! $flags->enabled( 'background_jobs_enabled' ) ) {
			return array( 'count' => 0, 'skipped' => 'background_jobs_disabled' );
		}
		return $this->job_runner->tick( 2 );
	}

	/**
	 * Run the scheduled cleanup.
	 *
	 * @return array<string, mixed>
	 */
	public function run_maintenance() {
		return $this->maintenance->daily();
	}

	/**
	 * Load translations when a language directory is present.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'replicaforge', false, dirname( plugin_basename( REPLICAFORGE_FILE ) ) . '/languages' );
	}

	/**
	 * Activation hook.
	 *
	 * Activation records versions and schedules the background work. It deliberately
	 * does not create data, run analysis, or touch any content, because activating a
	 * plugin should not do work the user did not ask for.
	 *
	 * @return void
	 */
	public static function activate() {
		update_option( 'replicaforge_version', REPLICAFORGE_VERSION, false );

		$maintenance = new Maintenance();
		$maintenance->schedule();

		$migrator = new Migrator();
		$migrator->run();

		// Granted on activation as well as in the migration. The migration is
		// skipped when the recorded schema version is already current, so an
		// activation on an up-to-date install would otherwise not re-grant a
		// capability an administrator had since removed from a role.
		$granted = Capabilities::grant_default_roles();
		if ( array() !== $granted ) {
			Audit_Log::record(
				'capabilities_granted',
				array(
					'roles' => implode( ',', array_keys( $granted ) ),
					'count' => array_sum( $granted ),
				),
				0
			);
		}
	}

	/**
	 * Deactivation hook.
	 *
	 * Scheduled work is unscheduled so a deactivated plugin does not keep running
	 * jobs. Stored data is kept: deactivating is usually temporary, and destroying a
	 * user's history because they paused a plugin would lose their work for no
	 * reason. The uninstall routine is the documented way to remove data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$maintenance = new Maintenance();
		$maintenance->unschedule();
	}
}
