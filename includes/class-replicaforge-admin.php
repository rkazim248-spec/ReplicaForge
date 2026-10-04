<?php
/**
 * WordPress admin UI for ReplicaForge.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the analyzer screen, optional AI settings, the Phase 5 validation
 * screen, and the user-triggered Phase 3 reconstruction view. Remote values are
 * rendered as text only.
 */
final class Admin {

	const PAGE_SLUG = Workspace_Limits::ADMIN_PAGE;
	const SETTINGS_SLUG = 'replicaforge-settings';
	const VALIDATION_SLUG = 'replicaforge-validation';
	const CORRECTIONS_SLUG = 'replicaforge-corrections';
	const HISTORY_SLUG = 'replicaforge-history';
	const STATUS_SLUG = 'replicaforge-status';
	const LOGS_SLUG = 'replicaforge-logs';

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * History page hook suffix.
	 *
	 * @var string
	 */
	private $history_page_hook = '';

	/**
	 * System status page hook suffix.
	 *
	 * @var string
	 */
	private $status_page_hook = '';

	/**
	 * Logs page hook suffix.
	 *
	 * @var string
	 */
	private $logs_page_hook = '';

	/**
	 * Settings page hook suffix.
	 *
	 * @var string
	 */
	private $settings_page_hook = '';

	/**
	 * AI manager.
	 *
	 * @var Ai_Manager
	 */
	private $ai_manager;

	/**
	 * AI settings service.
	 *
	 * @var Ai_Settings
	 */
	private $ai_settings;

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
	 * Optional Phase 5 report builder.
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
	 * Phase 7 feature flags.
	 *
	 * @var Feature_Flags
	 */
	private $feature_flags;

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
	 * Hook suffix of the Phase 6 corrections screen.
	 *
	 * @var string
	 */
	private $corrections_page_hook = '';

	/**
	 * Hook suffix of the Phase 5 validation screen.
	 *
	 * @var string
	 */
	private $validation_page_hook = '';

	/**
	 * Constructor.
	 *
	 * @param Ai_Manager|null          $ai_manager          Optional AI manager.
	 * @param Ai_Settings|null         $ai_settings         Optional settings service.
	 * @param Elementor_Generator|null $elementor_generator Optional Phase 4 generator.
	 * @param Validation_Engine|null   $validation_engine   Optional Phase 5 engine.
	 * @param Validation_Report|null   $validation_report   Optional Phase 5 report.
	 * @param Validation_Cache|null    $validation_cache    Optional Phase 5 cache.
	 * @param Visual_Renderer|null     $visual_renderer     Optional Phase 5 renderer.
	 * @param Correction_Engine|null   $correction_engine  Optional Phase 6 engine.
	 * @param Correction_Report|null   $correction_report  Optional Phase 6 report.
	 * @param Correction_History|null  $correction_history Optional Phase 6 history.
	 */
	public function __construct( $ai_manager = null, $ai_settings = null, $elementor_generator = null, $validation_engine = null, $validation_report = null, $validation_cache = null, $visual_renderer = null, $correction_engine = null, $correction_report = null, $correction_history = null, $job_runner = null, $maintenance = null, $migrator = null, $system_status = null, $logger = null ) {
		$this->correction_engine  = $correction_engine instanceof Correction_Engine ? $correction_engine : new Correction_Engine();
		$this->correction_report  = $correction_report instanceof Correction_Report ? $correction_report : new Correction_Report();
		$this->correction_history = $correction_history instanceof Correction_History ? $correction_history : new Correction_History();
		$this->ai_manager          = $ai_manager instanceof Ai_Manager ? $ai_manager : new Ai_Manager();
		$this->ai_settings         = $ai_settings instanceof Ai_Settings ? $ai_settings : new Ai_Settings();
		$this->elementor_generator = $elementor_generator instanceof Elementor_Generator ? $elementor_generator : new Elementor_Generator();
		$this->validation_engine   = $validation_engine instanceof Validation_Engine ? $validation_engine : new Validation_Engine();
		$this->validation_report   = $validation_report instanceof Validation_Report ? $validation_report : new Validation_Report();
		$this->validation_cache    = $validation_cache instanceof Validation_Cache ? $validation_cache : new Validation_Cache();
		$this->visual_renderer     = $visual_renderer instanceof Visual_Renderer ? $visual_renderer : new Visual_Renderer();

		// Phase 7 services.
		$this->logger        = $logger instanceof Logger ? $logger : new Logger();
		$this->job_runner    = $job_runner instanceof Job_Runner ? $job_runner : new Job_Runner( array( 'logger' => $this->logger ) );
		$this->maintenance   = $maintenance instanceof Maintenance ? $maintenance : new Maintenance( $this->logger );
		$this->migrator      = $migrator instanceof Migrator ? $migrator : new Migrator( $this->logger );
		$this->system_status = $system_status instanceof System_Status ? $system_status : new System_Status( $this->logger );
		$this->feature_flags = new Feature_Flags();
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_replicaforge_save_ai_settings', array( $this, 'handle_save_ai_settings' ) );
		add_action( 'admin_post_replicaforge_test_ai_connection', array( $this, 'handle_test_ai_connection' ) );
		add_action( 'admin_post_replicaforge_save_render_settings', array( $this, 'handle_save_render_settings' ) );
		add_action( 'admin_post_replicaforge_save_maintenance', array( $this, 'handle_save_maintenance' ) );
		add_action( 'admin_post_replicaforge_run_maintenance', array( $this, 'handle_run_maintenance' ) );
		add_action( 'admin_post_replicaforge_run_migration', array( $this, 'handle_run_migration' ) );
		add_action( 'admin_post_replicaforge_clear_log', array( $this, 'handle_clear_log' ) );

		// Job actions on the history screen are handled through a nonce-checked form
		// rather than bare links, so an action cannot be triggered by a prefetch or
		// an image tag on another site.
		add_action( 'admin_post_replicaforge_job_action', array( $this, 'handle_job_action' ) );
	}

	/**
	 * Add the top-level ReplicaForge menu and its submenu.
	 *
	 * The dashboard is the top-level page, so the menu label and the first
	 * submenu are the same destination. That is the WordPress convention and it
	 * avoids a duplicate entry.
	 *
	 * @return void
	 */
	public function add_menu() {
		$this->page_hook = add_menu_page(
			__( 'ReplicaForge', 'replicaforge' ),
			__( 'ReplicaForge', 'replicaforge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-screenoptions',
			58
		);
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Dashboard', 'replicaforge' ),
			__( 'Dashboard', 'replicaforge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' )
		);
		$this->history_page_hook = add_submenu_page(
			self::PAGE_SLUG,
			__( 'History', 'replicaforge' ),
			__( 'History', 'replicaforge' ),
			'manage_options',
			self::HISTORY_SLUG,
			array( $this, 'render_history_page' )
		);
		$this->settings_page_hook = add_submenu_page(
			self::PAGE_SLUG,
			__( 'ReplicaForge Settings', 'replicaforge' ),
			__( 'Settings', 'replicaforge' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);
		$this->validation_page_hook = add_submenu_page(
			self::PAGE_SLUG,
			__( 'Visual Validation', 'replicaforge' ),
			__( 'Validation', 'replicaforge' ),
			'manage_options',
			self::VALIDATION_SLUG,
			array( $this, 'render_validation_page' )
		);
		$this->corrections_page_hook = add_submenu_page(
			self::PAGE_SLUG,
			__( 'Corrections', 'replicaforge' ),
			__( 'Corrections', 'replicaforge' ),
			'manage_options',
			self::CORRECTIONS_SLUG,
			array( $this, 'render_corrections_page' )
		);
		$this->status_page_hook = add_submenu_page(
			self::PAGE_SLUG,
			__( 'System Status', 'replicaforge' ),
			__( 'System Status', 'replicaforge' ),
			'manage_options',
			self::STATUS_SLUG,
			array( $this, 'render_status_page' )
		);
		$this->logs_page_hook = add_submenu_page(
			self::PAGE_SLUG,
			__( 'Logs', 'replicaforge' ),
			__( 'Logs', 'replicaforge' ),
			'manage_options',
			self::LOGS_SLUG,
			array( $this, 'render_logs_page' )
		);
	}

	/**
	 * Enqueue the small admin front end on the analyzer screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		// Assets load on ReplicaForge screens only. Enqueuing them on every admin
		// page would push ReplicaForge's styles and script into unrelated screens,
		// where they can collide with another plugin's selectors.
		$hooks = array(
			$this->page_hook,
			$this->history_page_hook,
			$this->settings_page_hook,
			$this->validation_page_hook,
			$this->corrections_page_hook,
			$this->status_page_hook,
			$this->logs_page_hook,
		);
		if ( ! in_array( $hook_suffix, array_filter( $hooks ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'replicaforge-admin',
			REPLICAFORGE_URL . 'admin/css/admin.css',
			array(),
			REPLICAFORGE_VERSION
		);

		// The screens added in Phase 7 are server-rendered, so they need the
		// stylesheet but not the workflow script.
		if ( $this->page_hook !== $hook_suffix && $this->validation_page_hook !== $hook_suffix && $this->corrections_page_hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'replicaforge-admin',
			REPLICAFORGE_URL . 'admin/js/admin.js',
			array(),
			REPLICAFORGE_VERSION,
			true
		);

		$public   = $this->ai_manager->get_public_settings();
		$elementor = $this->elementor_generator->status();
		$config = array(
			'endpoint'     => esc_url_raw( rest_url( 'replicaforge/v1/analyze' ) ),
			'aiEndpoint'   => esc_url_raw( rest_url( 'replicaforge/v1/ai/analyze' ) ),
			'generateEndpoint' => esc_url_raw( rest_url( 'replicaforge/v1/generate' ) ),
			'validateEndpoint' => esc_url_raw( rest_url( 'replicaforge/v1/validate' ) ),
			'validationUrl'    => esc_url_raw( admin_url( 'admin.php?page=' . self::VALIDATION_SLUG ) ),
			'planCorrectionsEndpoint' => esc_url_raw( rest_url( 'replicaforge/v1/corrections/plan' ) ),
			'applyCorrectionsEndpoint' => esc_url_raw( rest_url( 'replicaforge/v1/corrections/apply' ) ),
			'rollbackCorrectionsEndpoint' => esc_url_raw( rest_url( 'replicaforge/v1/corrections/rollback' ) ),
			'historyCorrectionsEndpoint' => esc_url_raw( rest_url( 'replicaforge/v1/corrections/history' ) ),
			'exportCorrectionsEndpoint' => esc_url_raw( rest_url( 'replicaforge/v1/corrections/export' ) ),
			'correctionsUrl'  => esc_url_raw( admin_url( 'admin.php?page=' . self::CORRECTIONS_SLUG ) ),
			'settingsUrl'  => esc_url_raw( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'ai'           => array(
				'enabled' => ! empty( $public['enabled'] ),
				'provider' => isset( $public['provider'] ) ? $public['provider'] : 'none',
				'model' => isset( $public['model'] ) ? $public['model'] : '',
			),
			'validation'   => array(
				'schema'     => Validation_Limits::SCHEMA_VERSION,
				'viewports'  => array(
					'desktop' => Validation_Limits::VIEWPORTS['desktop'],
					'tablet'  => Validation_Limits::VIEWPORTS['tablet'],
					'mobile'  => Validation_Limits::VIEWPORTS['mobile'],
				),
				'renderer'  => $this->visual_renderer->public_settings(),
				'capabilities' => $this->visual_renderer->capabilities(),
				'ai_enabled' => ! empty( $public['enabled'] ),
			),
			'corrections'  => array(
				'schema'         => Correction_Limits::SCHEMA_VERSION,
				'levels'         => Correction_Limits::LEVELS,
				'batches'        => Correction_Limits::BATCHES,
				'auto_actions'   => Correction_Limits::AUTO_ACTIONS,
				'blocked_properties' => Correction_Limits::BLOCKED_PROPERTIES,
				'properties'     => ( new Correction_Property_Map() )->all(),
				'max_iterations' => Correction_Limits::MAX_ITERATIONS,
				'min_improvement' => Correction_Limits::MIN_IMPROVEMENT,
				'ai_enabled'     => ! empty( $public['enabled'] ),
			),
			'elementor'    => array(
				'available'  => ! empty( $elementor['available'] ),
				'version'    => isset( $elementor['version'] ) ? (string) $elementor['version'] : '',
				'containers' => ! empty( $elementor['containers'] ),
				'phase'      => Elementor_Limits::PHASE,
			),
			'strings'      => array(
				'analyzing'      => __( 'Analyzing website...', 'replicaforge' ),
				'analyzingHint'  => __( 'Fetching the public page and building a structured analysis.', 'replicaforge' ),
				'completed'      => __( 'Analysis completed.', 'replicaforge' ),
				'invalidUrl'     => __( 'Enter a valid http:// or https:// website URL.', 'replicaforge' ),
				'requestFailed'  => __( 'The analysis could not be completed. Please try again.', 'replicaforge' ),
				'rawTitle'       => __( 'View Raw Analysis', 'replicaforge' ),
				'metadata'       => __( 'Metadata', 'replicaforge' ),
				'headings'       => __( 'Headings', 'replicaforge' ),
				'paragraphs'     => __( 'Paragraphs', 'replicaforge' ),
				'images'         => __( 'Images', 'replicaforge' ),
				'links'          => __( 'Links', 'replicaforge' ),
				'designSignals'  => __( 'Design signals', 'replicaforge' ),
				'colors'         => __( 'Colors', 'replicaforge' ),
				'fonts'          => __( 'Fonts', 'replicaforge' ),
				'spacing'        => __( 'Spacing', 'replicaforge' ),
				'borderRadius'   => __( 'Border radius', 'replicaforge' ),
				'containerWidths' => __( 'Container widths', 'replicaforge' ),
				'responsive'     => __( 'Responsive signals', 'replicaforge' ),
				'empty'          => __( 'No values were found.', 'replicaforge' ),
				'loading'        => __( 'Loading analysis...', 'replicaforge' ),
				'designUnderstanding' => __( 'Design Understanding', 'replicaforge' ),
				'structure'      => __( 'Website Structure', 'replicaforge' ),
				'componentCounts' => __( 'Components', 'replicaforge' ),
				'tokenCounts'    => __( 'Design Tokens', 'replicaforge' ),
				'responsiveRules' => __( 'Responsive Rules', 'replicaforge' ),
				'phase2Details'  => __( 'Phase 2 details', 'replicaforge' ),
				'noPhase2'       => __( 'Phase 2 design understanding was not available for this result.', 'replicaforge' ),
				'aiTitle'        => __( 'AI Design Analysis', 'replicaforge' ),
				'aiIntro'        => __( 'Run an optional, user-triggered AI interpretation of the Phase 2 representation. The deterministic plan remains available when AI is disabled or unavailable.', 'replicaforge' ),
				'aiRun'          => __( 'Run AI Design Analysis', 'replicaforge' ),
				'aiRunning'      => __( 'Running AI design analysis...', 'replicaforge' ),
				'aiStages'       => __( 'Validating layout, component relationships, responsive evidence, and the final reconstruction result. No progress percentage is simulated.', 'replicaforge' ),
				'aiUnavailable'  => __( 'AI analysis unavailable. The deterministic design analysis is still available.', 'replicaforge' ),
				'aiPlanTitle'    => __( 'AI Reconstruction Plan', 'replicaforge' ),
				'aiPageStrategy' => __( 'Page strategy', 'replicaforge' ),
				'aiSections'     => __( 'Sections', 'replicaforge' ),
				'aiDesignSystem' => __( 'Design system', 'replicaforge' ),
				'aiResponsive'   => __( 'Responsive strategy', 'replicaforge' ),
				'aiWarnings'     => __( 'Warnings', 'replicaforge' ),
				'aiConfidence'   => __( 'Inference confidence', 'replicaforge' ),
				'aiJson'         => __( 'View Reconstruction JSON', 'replicaforge' ),
				'aiJsonNote'     => __( 'Developer view of the validated Phase 3 specification. It is displayed as text and never executed.', 'replicaforge' ),
				'aiDisabled'     => __( 'AI is not configured. The deterministic reconstruction plan will be shown.', 'replicaforge' ),
				'aiFallback'     => __( 'Deterministic fallback', 'replicaforge' ),
				'aiNotAvailable' => __( 'No AI plan is available yet.', 'replicaforge' ),
				'openSettings'   => __( 'Open AI settings', 'replicaforge' ),
				'genTitle'       => __( 'Elementor Draft', 'replicaforge' ),
				'genIntro'       => __( 'Turn the validated reconstruction specification into an editable Elementor page. ReplicaForge creates a new draft. It never publishes, and it never modifies an existing page.', 'replicaforge' ),
				'genUnavailable' => __( 'Elementor is required to generate editable replicas. Please install and activate Elementor, then try again.', 'replicaforge' ),
				'genAvailable'   => __( 'Elementor is active and ready.', 'replicaforge' ),
				'genRun'         => __( 'Generate Elementor Draft', 'replicaforge' ),
				'genRunning'     => __( 'Generating the Elementor draft...', 'replicaforge' ),
				'genStages'      => __( 'Validating the specification, building containers and widgets, applying responsive settings, creating the draft, and re-reading the saved document. No progress percentage is simulated.', 'replicaforge' ),
				'genImportAssets' => __( 'Copy detected images into the media library', 'replicaforge' ),
				'genImportNote'  => __( 'Off by default. A publicly reachable image is not automatically licensed for reuse, so copying is an explicit choice. Blocked and referenced assets are listed in the report.', 'replicaforge' ),
				'genSummary'     => __( 'Generation summary', 'replicaforge' ),
				'genReport'      => __( 'View Generation Report', 'replicaforge' ),
				'genLimitations' => __( 'Known limitations', 'replicaforge' ),
				'genSuccess'     => __( 'Replica generated successfully.', 'replicaforge' ),
				'genDraft'       => __( 'Draft', 'replicaforge' ),
				'genOpenDraft'   => __( 'Open Draft', 'replicaforge' ),
				'genEditElementor' => __( 'Edit with Elementor', 'replicaforge' ),
				'genPreview'     => __( 'Preview', 'replicaforge' ),
				'genNotPublished' => __( 'The generated page is a draft. Publishing is left to you in WordPress or Elementor.', 'replicaforge' ),
				'genFailed'      => __( 'The Elementor draft could not be generated.', 'replicaforge' ),
				'genWarnings'    => __( 'Warnings', 'replicaforge' ),
				'genErrors'      => __( 'Errors', 'replicaforge' ),
				'genTechnicalId' => __( 'Technical identifier', 'replicaforge' ),
				'genStagesList'  => __( 'Generation stages', 'replicaforge' ),
				'genStageValidating' => __( 'Validating specification', 'replicaforge' ),
				'genStageLayout'  => __( 'Building layout and widgets', 'replicaforge' ),
				'genStageStyles'  => __( 'Applying styles', 'replicaforge' ),
				'genStageResponsive' => __( 'Applying responsive settings', 'replicaforge' ),
				'genStageDraft'   => __( 'Creating WordPress draft', 'replicaforge' ),
				'genStageValidate' => __( 'Validating Elementor document', 'replicaforge' ),
				'valTitle'            => __( 'Visual Validation', 'replicaforge' ),
				'valIntro'            => __( 'Measure how closely the generated Elementor draft matches the analyzed source. Validation reads, compares, and reports. It never modifies the draft and never publishes.', 'replicaforge' ),
				'valNoDraft'          => __( 'Generate an Elementor draft first. Validation compares a generated draft against the current analysis.', 'replicaforge' ),
				'valRun'              => __( 'Run Validation', 'replicaforge' ),
				'valRunning'          => __( 'Validating the draft...', 'replicaforge' ),
				'valStages'           => __( 'Normalizing the source representation, reading the Elementor document and its generated stylesheet, pairing components, and comparing structure, layout, typography, colours, spacing, assets, and responsive rules. No progress percentage is simulated.', 'replicaforge' ),
				'valUseVisual'        => __( 'Include rendered screenshot comparison', 'replicaforge' ),
				'valUseAi'            => __( 'Ask AI to explain the detected differences', 'replicaforge' ),
				'valForce'            => __( 'Ignore cached result and compare again', 'replicaforge' ),
				'valNotComparable'    => __( 'Not comparable', 'replicaforge' ),
				'valVisualNote'       => __( 'Rendered comparison needs a configured render provider and a server image library. Without them, structural and design-system comparison still run.', 'replicaforge' ),
				'valReadOnly'         => __( 'Validation never modifies the Elementor document and never applies the correction plan.', 'replicaforge' ),
				'valCached'           => __( 'Reused from cache', 'replicaforge' ),
				'valFresh'            => __( 'Newly computed', 'replicaforge' ),
				'valExportJson'       => __( 'Export JSON', 'replicaforge' ),
				'valExportCsv'        => __( 'Export CSV', 'replicaforge' ),
				'valExporting'        => __( 'Preparing export...', 'replicaforge' ),
				'valFailed'           => __( 'The validation could not be completed.', 'replicaforge' ),
				'valMetricsTitle'     => __( 'Similarity metrics', 'replicaforge' ),
				'valMetricsNote'      => __( 'Internal validation measurement based on the configured comparison metrics. It is not an accuracy guarantee and no pixel-perfect claim is made.', 'replicaforge' ),
				'valGroups'           => __( 'Metrics', 'replicaforge' ),
				'valDifferences'      => __( 'Detected differences', 'replicaforge' ),
				'valNoDifferences'    => __( 'No differences were detected between the two representations.', 'replicaforge' ),
				'valWarnings'         => __( 'Warnings', 'replicaforge' ),
				'valLimitations'      => __( 'Limitations of this validation', 'replicaforge' ),
				'valPlanTitle'        => __( 'Machine-readable correction plan', 'replicaforge' ),
				'valPlanNote'         => __( 'Data only. Phase 5 does not apply it.', 'replicaforge' ),
				'valNoPlan'           => __( 'No applicable corrections were detected.', 'replicaforge' ),
				'valPlanRaw'          => __( 'View Correction Plan', 'replicaforge' ),
				'valReportRaw'        => __( 'View Validation Report', 'replicaforge' ),
				'valReportNote'       => __( 'Developer view of the validation record. It is displayed as text and never executed.', 'replicaforge' ),
				'valLevels'           => __( 'Comparison levels', 'replicaforge' ),
				'valAiTitle'          => __( 'AI explanation', 'replicaforge' ),
				'valAiNote'           => __( 'The model may only restate differences that the deterministic comparison already measured. Any unverified recommendation is discarded.', 'replicaforge' ),
				'valVisualTitle'      => __( 'Rendered comparison', 'replicaforge' ),
				'valVisualRegions'    => __( 'Differing regions', 'replicaforge' ),
				'valHistory'          => __( 'Recent validations', 'replicaforge' ),
				'valNoHistory'        => __( 'No validations have been run yet.', 'replicaforge' ),
				'valRendererTitle'    => __( 'Rendered comparison provider', 'replicaforge' ),
				'valRendererIntro'    => __( 'Optional. A WordPress plugin cannot ship a headless browser, so rendered comparison is delegated to an external render service you control. It is disabled by default.', 'replicaforge' ),
				'valRendererEnabled'  => __( 'Enable rendered comparison', 'replicaforge' ),
				'valRendererEndpoint' => __( 'Render endpoint URL', 'replicaforge' ),
				'valRendererToken'    => __( 'Bearer token (optional)', 'replicaforge' ),
				'valRendererTimeout'  => __( 'Request timeout (seconds)', 'replicaforge' ),
				'valRendererMaxWidth' => __( 'Maximum capture width (0 uses the viewport width)', 'replicaforge' ),
				'valRendererHelp'     => __( 'The endpoint must be a public HTTPS URL. The plugin sends a bounded JSON request containing the validated source URL, or the generated document markup and stylesheet URLs. It never sends credentials, and it never renders content in the WordPress process.', 'replicaforge' ),
				'valRendererSave'     => __( 'Save provider settings', 'replicaforge' ),
				'valRendererSaved'    => __( 'Render provider settings saved.', 'replicaforge' ),
				'valRendererError'    => __( 'The render provider settings could not be saved.', 'replicaforge' ),
				'valImageLibrary'     => __( 'Server image library', 'replicaforge' ),
				'valNone'             => __( 'None', 'replicaforge' ),
				'valRunTitle'         => __( 'Run a validation', 'replicaforge' ),
				'valDraftLabel'       => __( 'Generated draft', 'replicaforge' ),
				'valDraftHelp'        => __( 'Only drafts created by ReplicaForge are listed, and only if you can edit them.', 'replicaforge' ),
					'valSourceNote'       => __( 'The Phase 2 representation from the current browser session is sent with the request. It is not persisted by the browser.', 'replicaforge' ),
				'corTitle'            => __( 'Corrections', 'replicaforge' ),
				'corIntro'            => __( 'Review the measurable differences between the analyzed source and the generated draft, then apply the safe ones. ReplicaForge changes real Elementor properties one batch at a time and always keeps a restorable snapshot.', 'replicaforge' ),
				'corNoValidation'     => __( 'Run a validation first. Corrections are planned from a validation result so every change is tied to a measured difference.', 'replicaforge' ),
				'corNoDraft'          => __( 'Generate an Elementor draft first. Corrections are planned against a generated draft.', 'replicaforge' ),
				'corPlan'             => __( 'Review Corrections', 'replicaforge' ),
				'corPlanning'         => __( 'Planning corrections...', 'replicaforge' ),
				'corAi'               => __( 'Let AI suggest the correction order', 'replicaforge' ),
				'corAiNote'           => __( 'Optional. The model may only reorder corrections that were already measured. It cannot add, remove, or change a correction.', 'replicaforge' ),
				'corCounts'           => __( 'Corrections available', 'replicaforge' ),
				'corSafe'             => __( 'Safe', 'replicaforge' ),
				'corReview'           => __( 'Review required', 'replicaforge' ),
				'corBlocked'          => __( 'Blocked', 'replicaforge' ),
				'corApplySafe'        => __( 'Apply Safe Corrections', 'replicaforge' ),
				'corApplySelected'    => __( 'Apply Selected', 'replicaforge' ),
				'corSelectAll'        => __( 'Select all safe', 'replicaforge' ),
				'corSelectNone'       => __( 'Clear selection', 'replicaforge' ),
				'corApplying'         => __( 'Applying corrections...', 'replicaforge' ),
				'corSnapshot'         => __( 'Creating a snapshot before anything is written.', 'replicaforge' ),
				'corApprovedCount'    => __( 'corrections selected. A snapshot of the current draft is created before anything is written.', 'replicaforge' ),
				'corStructural'       => __( 'I approve the structural corrections listed above (insert, remove, or reorder)', 'replicaforge' ),
				'corStructuralNote'   => __( 'Structural corrections change the page layout. They are only applied when this is ticked.', 'replicaforge' ),
				'corCancelled'        => __( 'Cancelled. Nothing was changed.', 'replicaforge' ),
				'corConfirmTitle'     => __( 'Apply corrections?', 'replicaforge' ),
				'corConfirm'          => __( 'The current Elementor draft will be snapshotted, the selected corrections applied, and the document saved once. You can roll back afterwards from the correction history.', 'replicaforge' ),
				'corConfirmYes'       => __( 'Apply corrections', 'replicaforge' ),
				'corCancel'           => __( 'Cancel', 'replicaforge' ),
				'corComplete'         => __( 'Correction complete', 'replicaforge' ),
				'corRolledBack'       => __( 'The correction was rolled back', 'replicaforge' ),
				'corBefore'           => __( 'Before', 'replicaforge' ),
				'corAfter'            => __( 'After', 'replicaforge' ),
				'corImprovement'      => __( 'Measured change', 'replicaforge' ),
				'corMeasurementNote'  => __( 'Internal validation measurement based on the configured comparison metrics. It is not an accuracy guarantee and no visual accuracy is claimed.', 'replicaforge' ),
				'corApplied'          => __( 'Applied', 'replicaforge' ),
				'corRejected'         => __( 'Rejected', 'replicaforge' ),
				'corFailed'           => __( 'Failed', 'replicaforge' ),
				'corRegressions'      => __( 'Regressions', 'replicaforge' ),
				'corChanges'          => __( 'Changes applied', 'replicaforge' ),
				'corNoChanges'        => __( 'No corrections were applied.', 'replicaforge' ),
				'corOpenElementor'    => __( 'Open Elementor', 'replicaforge' ),
				'corPreview'          => __( 'Preview', 'replicaforge' ),
				'corValidate'         => __( 'Validate Again', 'replicaforge' ),
				'corHistory'          => __( 'Correction History', 'replicaforge' ),
				'corRollback'         => __( 'Roll back', 'replicaforge' ),
				'corRollbackConfirm'  => __( 'Restore the document exactly as it was before this correction run?', 'replicaforge' ),
				'corExportPlan'       => __( 'Export Plan JSON', 'replicaforge' ),
				'corExportPlanCsv'    => __( 'Export Plan CSV', 'replicaforge' ),
				'corExportRun'        => __( 'Export Report JSON', 'replicaforge' ),
				'corFailedMessage'   => __( 'The corrections could not be applied. The previous document was preserved.', 'replicaforge' ),
				'corPlanChanged'      => __( 'The draft changed after this plan was created. Plan the corrections again.', 'replicaforge' ),
				'corProperty'         => __( 'Property', 'replicaforge' ),
				'corCurrent'          => __( 'Current', 'replicaforge' ),
				'corSource'           => __( 'Source', 'replicaforge' ),
				'corDifference'       => __( 'Difference', 'replicaforge' ),
				'corViewport'         => __( 'Viewport', 'replicaforge' ),
				'corSeverity'         => __( 'Severity', 'replicaforge' ),
				'corConfidence'       => __( 'Confidence', 'replicaforge' ),
				'corReason'           => __( 'Reason', 'replicaforge' ),
				'corElement'          => __( 'Elementor element', 'replicaforge' ),
				'corBatch'            => __( 'Batch', 'replicaforge' ),
				'corLevel'            => __( 'Level', 'replicaforge' ),
				'corManual'           => __( 'Manually changed', 'replicaforge' ),
				'corManualNote'       => __( 'This property was changed after generation, so ReplicaForge will not overwrite it automatically. Review the recorded value against the source value and decide.', 'replicaforge' ),
				'corHistoryTitle'     => __( 'Correction History', 'replicaforge' ),
				'corHistoryIntro'     => __( 'Every correction run is recorded with what changed, what was rejected, and what the re-validation measured. A run can be rolled back to its snapshot.', 'replicaforge' ),
				'corNoHistory'        => __( 'No corrections have been applied to this draft yet.', 'replicaforge' ),
				'corNotFound'         => __( 'That correction run could not be found.', 'replicaforge' ),
				'corNotPublished'     => __( 'Corrections only ever change a draft. Publishing stays a manual decision in WordPress or Elementor.', 'replicaforge' ),
				'corReadOnly'         => __( 'Reviewing a plan never changes the document. A change only happens when you apply one.', 'replicaforge' ),
				'corStatus'          => __( 'Status', 'replicaforge' ),
				'corPlanFailed'      => __( 'The correction plan could not be produced.', 'replicaforge' ),
				'corExportFailed'   => __( 'The export failed.', 'replicaforge' ),
'valValidation'      => __( 'Validation', 'replicaforge' ),
'p7Jobs'             => __( 'Jobs', 'replicaforge' ),
'p7History'          => __( 'History', 'replicaforge' ),
'p7Status'           => __( 'System Status', 'replicaforge' ),
'p7Logs'             => __( 'Logs', 'replicaforge' ),
'p7RequestId'        => __( 'Request ID', 'replicaforge' ),
'p7Elapsed'          => __( 'Elapsed', 'replicaforge' ),
'p7NoJobs'           => __( 'No jobs have been run yet.', 'replicaforge' ),
'p7MaintenanceDone'  => __( 'The cleanup ran. Removed counts are shown below.', 'replicaforge' ),
'p7MigrationDone'    => __( 'The migration ran.', 'replicaforge' ),
'p7Saved'            => __( 'Settings saved.', 'replicaforge' ),
		),
		);

		$encoded = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( is_string( $encoded ) ) {
			wp_add_inline_script( 'replicaforge-admin', 'window.ReplicaForgeAdmin = ' . $encoded . ';', 'before' );
		}
	}

	/**
	 * Render the dashboard.
	 *
	 * The dashboard answers the three questions a user opens ReplicaForge with:
	 * can I run it, what did I build, and what is it doing right now.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access ReplicaForge.', 'replicaforge' ) );
		}

		$status    = $this->system_status->report();
		$repository = new Job_Repository( $this->logger );
		$jobs       = $repository->recent( array(), 8 );
		$counts     = $repository->counts();
		$log        = $this->logger->summary();
		$storage    = $this->maintenance->storage_report();
		$elementor  = $this->elementor_generator->status();
		$notice     = isset( $_GET['replicaforge_notice'] ) ? sanitize_key( wp_unslash( $_GET['replicaforge_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap replicaforge-admin">
			<header class="replicaforge-admin__header">
				<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Dashboard', 'replicaforge' ); ?></p>
				<h1><?php esc_html_e( 'ReplicaForge', 'replicaforge' ); ?></h1>
				<p class="replicaforge-admin__intro">
					<?php esc_html_e( 'Analyze a public website frontend, understand its design, build an editable Elementor draft, measure the differences, and correct the measurable ones.', 'replicaforge' ); ?>
				</p>
			</header>

			<?php if ( 'maintenance_ran' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The cleanup ran. Removed counts are shown on the System Status screen.', 'replicaforge' ); ?></p></div>
			<?php elseif ( 'migration_ran' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The migration ran.', 'replicaforge' ); ?></p></div>
			<?php endif; ?>

			<div class="replicaforge-dashboard">
				<div class="replicaforge-dashboard__main">
					<section class="replicaforge-card replicaforge-card--cta">
						<h2><?php esc_html_e( 'Create a new replica', 'replicaforge' ); ?></h2>
						<p>
							<?php esc_html_e( 'Enter the address of a public webpage. ReplicaForge analyzes the frontend, builds an editable Elementor draft, and never publishes anything.', 'replicaforge' ); ?>
						</p>
						<p>
							<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
								<?php esc_html_e( 'Create New Replica', 'replicaforge' ); ?>
							</a>
						</p>
						<?php if ( empty( $elementor['available'] ) ) : ?>
							<p class="replicaforge-card__note">
								<?php esc_html_e( 'Analysis and AI planning work now. Generating an Elementor draft needs Elementor active.', 'replicaforge' ); ?>
							</p>
						<?php endif; ?>
					</section>

					<section class="replicaforge-card">
						<h2><?php esc_html_e( 'Recent jobs', 'replicaforge' ); ?></h2>
						<?php $this->render_job_table( $jobs ); ?>
						<p>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::HISTORY_SLUG ) ); ?>">
								<?php esc_html_e( 'View all history', 'replicaforge' ); ?>
							</a>
						</p>
					</section>
				</div>

				<aside class="replicaforge-dashboard__side">
					<section class="replicaforge-card">
						<h2><?php esc_html_e( 'System status', 'replicaforge' ); ?></h2>
						<?php $this->render_status_list( $status ); ?>
						<p>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::STATUS_SLUG ) ); ?>">
								<?php esc_html_e( 'Open System Status', 'replicaforge' ); ?>
							</a>
						</p>
					</section>

					<section class="replicaforge-card">
						<h2><?php esc_html_e( 'At a glance', 'replicaforge' ); ?></h2>
						<ul class="replicaforge-stats">
							<li>
								<span class="replicaforge-stats__label"><?php esc_html_e( 'Queued jobs', 'replicaforge' ); ?></span>
								<span class="replicaforge-stats__value"><?php echo esc_html( (string) $counts['queued'] ); ?></span>
							</li>
							<li>
								<span class="replicaforge-stats__label"><?php esc_html_e( 'Running jobs', 'replicaforge' ); ?></span>
								<span class="replicaforge-stats__value"><?php echo esc_html( (string) $counts['running'] ); ?></span>
							</li>
							<li>
								<span class="replicaforge-stats__label"><?php esc_html_e( 'Completed jobs', 'replicaforge' ); ?></span>
								<span class="replicaforge-stats__value"><?php echo esc_html( (string) $counts['completed'] ); ?></span>
							</li>
							<li>
								<span class="replicaforge-stats__label"><?php esc_html_e( 'Stored log entries', 'replicaforge' ); ?></span>
								<span class="replicaforge-stats__value"><?php echo esc_html( (string) $log['total'] ); ?></span>
							</li>
							<li>
								<span class="replicaforge-stats__label"><?php esc_html_e( 'Stored transients', 'replicaforge' ); ?></span>
								<span class="replicaforge-stats__value"><?php echo esc_html( (string) $storage['transients'] ); ?></span>
							</li>
						</ul>
					</section>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the history screen.
	 *
	 * @return void
	 */
	public function render_history_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access ReplicaForge history.', 'replicaforge' ) );
		}

		$repository = new Job_Repository( $this->logger );
		$type       = isset( $_GET['rf_type'] ) ? sanitize_key( wp_unslash( $_GET['rf_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status     = isset( $_GET['rf_status'] ) ? sanitize_key( wp_unslash( $_GET['rf_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$jobs       = $repository->recent(
			array(
				'type'   => isset( Job_Limits::TYPES[ $type ] ) ? $type : '',
				'status' => isset( Job_Limits::STATUSES[ $status ] ) ? $status : '',
			),
			50
		);

		?>
		<div class="wrap replicaforge-admin">
			<header class="replicaforge-admin__header">
				<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'History', 'replicaforge' ); ?></p>
				<h1><?php esc_html_e( 'Project history', 'replicaforge' ); ?></h1>
				<p class="replicaforge-admin__intro">
					<?php esc_html_e( 'Every analysis, generation, validation, and correction run ReplicaForge has carried out, newest first. Nothing here is deleted while a draft still depends on it.', 'replicaforge' ); ?>
				</p>
			</header>

			<form method="get" class="replicaforge-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::HISTORY_SLUG ); ?>" />
				<label for="rf_type"><?php esc_html_e( 'Type', 'replicaforge' ); ?></label>
				<select name="rf_type" id="rf_type">
					<option value=""><?php esc_html_e( 'All types', 'replicaforge' ); ?></option>
					<?php foreach ( Job_Limits::TYPES as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>>
							<?php echo esc_html( ucfirst( (string) $label ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="rf_status"><?php esc_html_e( 'Status', 'replicaforge' ); ?></label>
				<select name="rf_status" id="rf_status">
					<option value=""><?php esc_html_e( 'All statuses', 'replicaforge' ); ?></option>
					<?php foreach ( Job_Limits::STATUSES as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
							<?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $label ) ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'replicaforge' ); ?></button>
			</form>

			<?php $this->render_job_table( $jobs, true ); ?>
		</div>
		<?php
	}

	/**
	 * Render a table of jobs.
	 *
	 * @param array<int, array<string, mixed>> $jobs  Presented jobs.
	 * @param bool                             $detailed Whether to show the full column set.
	 * @return void
	 */
	private function render_job_table( array $jobs, $detailed = false ) {
		if ( empty( $jobs ) ) {
			echo '<p class="replicaforge-empty">' . esc_html__( 'No jobs have been run yet.', 'replicaforge' ) . '</p>';
			return;
		}

		?>
		<div class="replicaforge-table-wrap" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Job list', 'replicaforge' ); ?>">
			<table class="widefat striped replicaforge-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'ReplicaForge jobs', 'replicaforge' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Source', 'replicaforge' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'replicaforge' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Stage', 'replicaforge' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Progress', 'replicaforge' ); ?></th>
						<?php if ( $detailed ) : ?>
							<th scope="col"><?php esc_html_e( 'Result', 'replicaforge' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Started', 'replicaforge' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Duration', 'replicaforge' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'replicaforge' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $jobs as $job ) : ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Source', 'replicaforge' ); ?>">
							<?php if ( '' !== (string) $job['source_host'] ) : ?>
								<code><?php echo esc_html( (string) $job['source_host'] ); ?></code>
							<?php else : ?>
								<span aria-hidden="true">—</span>
								<span class="screen-reader-text"><?php esc_html_e( 'Not recorded', 'replicaforge' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Status', 'replicaforge' ); ?>">
							<?php echo $this->status_badge( (string) $job['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Stage', 'replicaforge' ); ?>">
							<?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $job['stage'] ) ) ); ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Progress', 'replicaforge' ); ?>">
							<?php $this->render_progress( (int) $job['progress'], (string) $job['status'] ); ?>
						</td>
						<?php if ( $detailed ) : ?>
							<td data-label="<?php esc_attr_e( 'Result', 'replicaforge' ); ?>">
								<?php
								$result = is_array( $job['result'] ) ? $job['result'] : array();
								if ( ! empty( $result['draft_id'] ) ) {
									printf(
										'<a href="%1$s">%2$s</a>',
										esc_url( (string) ( get_edit_post_link( (int) $result['draft_id'], 'raw' ) ?: admin_url( 'admin.php?page=' . self::HISTORY_SLUG ) ) ),
										esc_html(
											sprintf(
												/* translators: %d: Draft post identifier. */
												__( 'Draft #%d', 'replicaforge' ),
												(int) $result['draft_id']
											)
										)
									);
								} elseif ( ! empty( $job['error']['message'] ) ) {
									echo esc_html( (string) $job['error']['message'] );
								} else {
									echo '<span aria-hidden="true">—</span>';
								}
								?>
							</td>
							<td data-label="<?php esc_attr_e( 'Started', 'replicaforge' ); ?>">
								<?php
								$started = (string) $job['started_at'];
								echo esc_html( '' === $started ? '—' : $started );
								?>
							</td>
							<td data-label="<?php esc_attr_e( 'Duration', 'replicaforge' ); ?>">
								<?php echo esc_html( $this->format_duration( (int) $job['duration_ms'] ) ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Actions', 'replicaforge' ); ?>">
								<?php $this->render_job_actions( $job ); ?>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render a truthful progress indicator.
	 *
	 * The value is the job's own recorded progress. A finished job shows a
	 * completed bar; a queued one shows that it has not started. Nothing is
	 * animated or estimated.
	 *
	 * @param int    $progress Percentage.
	 * @param string $status   Job status.
	 * @return void
	 */
	private function render_progress( $progress, $status ) {
		$progress = max( 0, min( 100, (int) $progress ) );
		$label    = sprintf(
			/* translators: %d: Percentage complete. */
			__( '%d%%', 'replicaforge' ),
			$progress
		);

		// A finished bar is a different colour as well as a different length, so the
		// state does not depend on judging the width or on telling hues apart.
		$finished = Job_Limits::is_terminal( (string) $status );
		$classes  = 'replicaforge-progress__bar' . ( $finished ? ' is-complete' : '' );

		// The accessible name states the status, because a percentage alone does not
		// tell a screen reader user whether the job is still working or has stopped.
		$described = sprintf(
			/* translators: 1: Percentage complete, 2: Job status. */
			__( 'Job progress: %1$d percent, %2$s', 'replicaforge' ),
			$progress,
			$status
		);
		?>
		<div class="replicaforge-progress">
			<div
				class="<?php echo esc_attr( $classes ); ?>"
				role="progressbar"
				aria-valuenow="<?php echo esc_attr( (string) $progress ); ?>"
				aria-valuemin="0"
				aria-valuemax="100"
				aria-label="<?php echo esc_attr( $described ); ?>"
			>
				<span class="replicaforge-progress__fill<?php echo $finished ? ' is-complete' : ''; ?>" style="width: <?php echo esc_attr( (string) $progress ); ?>%"></span>
			</div>
			<span class="replicaforge-progress__label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	/**
	 * Render a status badge.
	 *
	 * The status is written as text as well as colour, so the state does not
	 * depend on being able to distinguish hues.
	 *
	 * @param string $status Job status.
	 * @return string
	 */
	private function status_badge( $status ) {
		$classes = array(
			'queued'    => 'is-queued',
			'running'   => 'is-running',
			'paused'    => 'is-paused',
			'completed' => 'is-completed',
			'failed'    => 'is-failed',
			'cancelled' => 'is-cancelled',
		);
		$class = isset( $classes[ $status ] ) ? $classes[ $status ] : 'is-queued';
		$text  = ucfirst( str_replace( '_', ' ', (string) $status ) );

		return sprintf(
			'<span class="replicaforge-badge %1$s"><span class="replicaforge-badge__dot" aria-hidden="true"></span>%2$s</span>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}

	/**
	 * Render the actions available for a job.
	 *
	 * @param array<string, mixed> $job Presented job.
	 * @return void
	 */
	private function render_job_actions( array $job ) {
		$job_id  = (string) $job['job_id'];
		$actions = array();

		if ( ! empty( $job['can_resume'] ) ) {
			$actions['resume'] = __( 'Resume', 'replicaforge' );
		} elseif ( ! empty( $job['can_retry'] ) ) {
			$actions['retry'] = __( 'Retry', 'replicaforge' );
		}
		if ( ! empty( $job['can_cancel'] ) ) {
			$actions['cancel'] = __( 'Cancel', 'replicaforge' );
		}
		$actions['delete'] = __( 'Delete', 'replicaforge' );

		echo '<span class="replicaforge-actions">';
		foreach ( $actions as $action => $label ) {
			printf(
				'<form method="post" action="%1$s" class="replicaforge-inline-form">
					<input type="hidden" name="action" value="replicaforge_job_action" />
					<input type="hidden" name="rf_action" value="%2$s" />
					<input type="hidden" name="rf_job" value="%3$s" />
					%4$s
					<button type="submit" class="button button-small rf-action rf-action--%2$s">%5$s</button>
				</form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( (string) $action ),
				esc_attr( $job_id ),
				wp_nonce_field( 'replicaforge_job_action_' . $job_id, 'rf_nonce', true, false ),
				esc_html( $label )
			);
		}
		echo '</span>';
	}

	/**
	 * Handle a job action posted from the history screen.
	 *
	 * @return void
	 */
	public function handle_job_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'replicaforge' ) );
		}

		$job_id = isset( $_POST['rf_job'] ) ? sanitize_text_field( wp_unslash( $_POST['rf_job'] ) ) : '';
		$action = isset( $_POST['rf_action'] ) ? sanitize_key( wp_unslash( $_POST['rf_action'] ) ) : '';
		$nonce  = isset( $_POST['rf_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['rf_nonce'] ) ) : '';

		if ( '' === $job_id || 1 !== preg_match( '/^job_[a-f0-9]{12,32}$/', $job_id ) ) {
			wp_die( esc_html__( 'That job could not be identified.', 'replicaforge' ), 400 );
		}
		if ( ! wp_verify_nonce( $nonce, 'replicaforge_job_action_' . $job_id ) ) {
			wp_die( esc_html__( 'Your session could not be verified. Please reload the page and try again.', 'replicaforge' ), 403 );
		}

		$queue      = new Job_Queue( new Job_Repository( $this->logger ), $this->logger );
		$repository = new Job_Repository( $this->logger );

		switch ( $action ) {
			case 'resume':
				$queue->resume( $job_id );
				break;
			case 'retry':
				if ( ! empty( $queue->resume( $job_id )['success'] ) ) {
					$queue->start( $job_id );
				}
				break;
			case 'cancel':
				$queue->cancel( $job_id );
				break;
			case 'delete':
				$repository->delete( $job_id );
				break;
			default:
				wp_die( esc_html__( 'That action is not supported.', 'replicaforge' ), 400 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::HISTORY_SLUG ) );
		exit;
	}

	/**
	 * Format a millisecond duration for a person.
	 *
	 * @param int $milliseconds Duration.
	 * @return string
	 */
	private function format_duration( $milliseconds ) {
		$milliseconds = max( 0, (int) $milliseconds );
		if ( 0 === $milliseconds ) {
			return '—';
		}
		$seconds = $milliseconds / 1000;
		if ( $seconds < 60 ) {
			return sprintf(
				/* translators: %s: Number of seconds. */
				__( '%ss', 'replicaforge' ),
				number_format_i18n( $seconds, 1 )
			);
		}
		$minutes = (int) floor( $seconds / 60 );
		return sprintf(
			/* translators: 1: Minutes, 2: Seconds. */
			__( '%1$dm %2$ds', 'replicaforge' ),
			$minutes,
			number_format_i18n( $seconds - ( $minutes * 60 ), 0 )
		);
	}

	/**
	 * Render the system status screen.
	 *
	 * @return void
	 */
	public function render_status_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access ReplicaForge system status.', 'replicaforge' ) );
		}

		$report  = $this->system_status->report();
		$storage = $this->maintenance->storage_report();
		$schema  = $this->migrator->state();
		$notice  = isset( $_GET['replicaforge_notice'] ) ? sanitize_key( wp_unslash( $_GET['replicaforge_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap replicaforge-admin">
			<header class="replicaforge-admin__header">
				<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'System Status', 'replicaforge' ); ?></p>
				<h1><?php esc_html_e( 'System status', 'replicaforge' ); ?></h1>
				<p class="replicaforge-admin__intro">
					<?php esc_html_e( 'Whether this install can run ReplicaForge, and what to do when it cannot.', 'replicaforge' ); ?>
				</p>
			</header>

			<?php if ( 'maintenance_ran' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The cleanup ran.', 'replicaforge' ); ?></p></div>
			<?php elseif ( 'migration_ran' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The migration ran.', 'replicaforge' ); ?></p></div>
			<?php endif; ?>

			<section class="replicaforge-card">
				<h2><?php esc_html_e( 'Checks', 'replicaforge' ); ?></h2>
				<?php $this->render_status_list( $report ); ?>
			</section>

			<section class="replicaforge-card">
				<h2><?php esc_html_e( 'Versions', 'replicaforge' ); ?></h2>
				<table class="widefat striped replicaforge-table">
					<tbody>
					<?php foreach ( $report['versions'] as $key => $value ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $key ) ) ); ?></th>
							<td><code><?php echo esc_html( (string) $value ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>

			<section class="replicaforge-card">
				<h2><?php esc_html_e( 'Stored data', 'replicaforge' ); ?></h2>
				<ul class="replicaforge-stats">
					<?php
					$labels = array(
						'transients'  => __( 'Transients', 'replicaforge' ),
						'log_entries' => __( 'Log entries', 'replicaforge' ),
						'log_bytes'   => __( 'Log size (bytes)', 'replicaforge' ),
						'snapshots'   => __( 'Snapshots', 'replicaforge' ),
						'jobs'        => __( 'Job record rows', 'replicaforge' ),
					);
					foreach ( $labels as $key => $label ) :
						?>
						<li>
							<span class="replicaforge-stats__label"><?php echo esc_html( $label ); ?></span>
							<span class="replicaforge-stats__value"><?php echo esc_html( (string) ( isset( $storage[ $key ] ) ? $storage[ $key ] : 0 ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<section class="replicaforge-card">
				<h2><?php esc_html_e( 'Maintenance', 'replicaforge' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="replicaforge-form">
					<input type="hidden" name="action" value="replicaforge_run_maintenance" />
					<?php wp_nonce_field( 'replicaforge_run_maintenance' ); ?>
					<p><?php esc_html_e( 'Remove expired transients, finished jobs, old log entries, and abandoned idempotency records. Active jobs and restorable snapshots are never removed.', 'replicaforge' ); ?></p>
					<button type="submit" class="button"><?php esc_html_e( 'Run cleanup now', 'replicaforge' ); ?></button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="replicaforge-form">
					<input type="hidden" name="action" value="replicaforge_run_migration" />
					<?php wp_nonce_field( 'replicaforge_run_migration' ); ?>
					<p>
						<?php
						printf(
							/* translators: 1: Installed schema version, 2: Expected schema version. */
							esc_html__( 'Stored data is at schema %1$s; this release expects %2$s. A migration never deletes user data.', 'replicaforge' ),
							esc_html( '' === (string) $schema['installed'] ? __( 'unknown', 'replicaforge' ) : (string) $schema['installed'] ),
							esc_html( (string) $schema['current'] )
						);
						?>
					</p>
					<button type="submit" class="button"><?php esc_html_e( 'Run migration', 'replicaforge' ); ?></button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="replicaforge-form">
					<input type="hidden" name="action" value="replicaforge_save_maintenance" />
					<?php wp_nonce_field( 'replicaforge_save_maintenance' ); ?>
					<?php $settings = $this->maintenance->settings(); ?>
					<table class="form-table" role="presentation">
						<tbody>
						<?php
						$fields = array(
							'log_days'      => __( 'Keep log entries for (days)', 'replicaforge' ),
							'job_days'      => __( 'Keep finished jobs for (days)', 'replicaforge' ),
							'snapshot_days' => __( 'Keep snapshots for (days)', 'replicaforge' ),
							'log_limit'     => __( 'Maximum log entries', 'replicaforge' ),
						);
						foreach ( $fields as $key => $label ) :
							?>
							<tr>
								<th scope="row"><label for="rf_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
								<td>
									<input
										type="number"
										id="rf_<?php echo esc_attr( $key ); ?>"
										name="<?php echo esc_attr( $key ); ?>"
										value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>"
										min="1"
										step="1"
										class="small-text"
									/>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<button type="submit" class="button"><?php esc_html_e( 'Save retention settings', 'replicaforge' ); ?></button>
				</form>
			</section>
		</div>
		<?php
	}

	/**
	 * Render a list of status checks.
	 *
	 * @param array<string, mixed> $report Status report.
	 * @return void
	 */
	private function render_status_list( array $report ) {
		$symbols = array(
			'ok'   => array( '&#10003;', __( 'OK', 'replicaforge' ) ),
			'warn' => array( '&#33;', __( 'Warning', 'replicaforge' ) ),
			'fail' => array( '&#10007;', __( 'Problem', 'replicaforge' ) ),
		);

		echo '<ul class="replicaforge-status">';
		foreach ( $report['checks'] as $check ) {
			$state   = (string) $check['state'];
			$symbol  = isset( $symbols[ $state ] ) ? $symbols[ $state ] : $symbols['ok'];
			$classes = 'replicaforge-status__item is-' . $state;
			?>
			<li class="<?php echo esc_attr( $classes ); ?>">
				<span class="replicaforge-status__mark" aria-hidden="true"><?php echo wp_kses_post( $symbol[0] ); ?></span>
				<span class="replicaforge-status__text">
					<span class="screen-reader-text"><?php echo esc_html( (string) $symbol[1] ); ?>: </span>
					<strong><?php echo esc_html( (string) $check['label'] ); ?></strong>
					<?php if ( '' !== (string) $check['detail'] ) : ?>
						<span class="replicaforge-status__detail"><?php echo esc_html( (string) $check['detail'] ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== (string) $check['action'] ) : ?>
						<span class="replicaforge-status__action"><?php echo esc_html( (string) $check['action'] ); ?></span>
					<?php endif; ?>
				</span>
			</li>
			<?php
		}
		echo '</ul>';
	}

	/**
	 * Render the logs screen.
	 *
	 * @return void
	 */
	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access ReplicaForge logs.', 'replicaforge' ) );
		}

		$logger = new Logger();
		$level  = isset( $_GET['rf_level'] ) ? sanitize_key( wp_unslash( $_GET['rf_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['rf_search'] ) ? sanitize_text_field( wp_unslash( $_GET['rf_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$level  = in_array( $level, Logger::LEVELS, true ) ? $level : '';

		$entries = $logger->recent(
			array(
				'level'  => $level,
				'search' => $search,
			),
			200
		);
		$summary = $logger->summary();
		$notice  = isset( $_GET['replicaforge_notice'] ) ? sanitize_key( wp_unslash( $_GET['replicaforge_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap replicaforge-admin">
			<header class="replicaforge-admin__header">
				<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Logs', 'replicaforge' ); ?></p>
				<h1><?php esc_html_e( 'Logs', 'replicaforge' ); ?></h1>
				<p class="replicaforge-admin__intro">
					<?php esc_html_e( 'Structured ReplicaForge activity. Secrets and private network detail are removed before anything is written, and again on export.', 'replicaforge' ); ?>
				</p>
			</header>

			<?php if ( 'logs_cleared' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The log was cleared.', 'replicaforge' ); ?></p></div>
			<?php endif; ?>

			<p class="replicaforge-log-summary">
				<?php
				printf(
					/* translators: 1: Entry count, 2: Retention limit. */
					esc_html__( '%1$d entries retained, up to %2$d.', 'replicaforge' ),
					(int) $summary['total'],
					(int) $summary['limit']
				);
				?>
				&nbsp;
				<a class="button" href="<?php echo esc_url( rest_url( 'replicaforge/v1/logs/export' ) ); ?>">
					<?php esc_html_e( 'Export log', 'replicaforge' ); ?>
				</a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="replicaforge-inline-form">
					<input type="hidden" name="action" value="replicaforge_clear_log" />
					<?php wp_nonce_field( 'replicaforge_clear_log' ); ?>
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Clear log', 'replicaforge' ); ?></button>
				</form>
			</p>

			<form method="get" class="replicaforge-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::LOGS_SLUG ); ?>" />
				<label for="rf_level"><?php esc_html_e( 'Level', 'replicaforge' ); ?></label>
				<select name="rf_level" id="rf_level">
					<option value=""><?php esc_html_e( 'All levels', 'replicaforge' ); ?></option>
					<?php foreach ( Logger::LEVELS as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $level, $option ); ?>>
							<?php echo esc_html( ucfirst( $option ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label for="rf_search"><?php esc_html_e( 'Search', 'replicaforge' ); ?></label>
				<input type="search" name="rf_search" id="rf_search" value="<?php echo esc_attr( $search ); ?>" />

				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'replicaforge' ); ?></button>
			</form>

			<?php if ( empty( $entries ) ) : ?>
				<p class="replicaforge-empty"><?php esc_html_e( 'No log entries match.', 'replicaforge' ); ?></p>
			<?php else : ?>
				<div class="replicaforge-table-wrap" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Log entries', 'replicaforge' ); ?>">
					<table class="widefat striped replicaforge-table">
						<caption class="screen-reader-text"><?php esc_html_e( 'ReplicaForge log entries', 'replicaforge' ); ?></caption>
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Time', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Level', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Event', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Message', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Request', 'replicaforge' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Time', 'replicaforge' ); ?>"><code><?php echo esc_html( (string) $entry['time'] ); ?></code></td>
								<td data-label="<?php esc_attr_e( 'Level', 'replicaforge' ); ?>">
									<?php
									$entry_level = (string) $entry['level'];
									printf(
										'<span class="replicaforge-badge is-%1$s"><span class="replicaforge-badge__dot" aria-hidden="true"></span>%2$s</span>',
										esc_attr( 'info' === $entry_level || 'debug' === $entry_level ? 'queued' : $entry_level ),
										esc_html( ucfirst( $entry_level ) )
									);
									?>
								</td>
								<td data-label="<?php esc_attr_e( 'Event', 'replicaforge' ); ?>"><code><?php echo esc_html( (string) $entry['event'] ); ?></code></td>
								<td data-label="<?php esc_attr_e( 'Message', 'replicaforge' ); ?>"><?php echo esc_html( (string) $entry['message'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Request', 'replicaforge' ); ?>"><code><?php echo esc_html( (string) ( $entry['request_id'] ?? '' ) ); ?></code></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle a request to clear the log.
	 *
	 * @return void
	 */
	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'replicaforge' ) );
		}
		check_admin_referer( 'replicaforge_clear_log' );
		( new Logger() )->clear();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::LOGS_SLUG . '&replicaforge_notice=logs_cleared' ) );
		exit;
	}

	/**
	 * Handle a request to run the cleanup.
	 *
	 * @return void
	 */
	public function handle_run_maintenance() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'replicaforge' ) );
		}
		check_admin_referer( 'replicaforge_run_maintenance' );
		$summary = $this->maintenance->daily();
		unset( $summary );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::STATUS_SLUG . '&replicaforge_notice=maintenance_ran' ) );
		exit;
	}

	/**
	 * Handle a request to run the migration.
	 *
	 * @return void
	 */
	public function handle_run_migration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'replicaforge' ) );
		}
		check_admin_referer( 'replicaforge_run_migration' );
		$this->migrator->run( true );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::STATUS_SLUG . '&replicaforge_notice=migration_ran' ) );
		exit;
	}

	/**
	 * Handle a request to save the retention settings.
	 *
	 * @return void
	 */
	public function handle_save_maintenance() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'replicaforge' ) );
		}
		check_admin_referer( 'replicaforge_save_maintenance' );

		foreach ( array( 'log_days', 'job_days', 'snapshot_days', 'log_limit' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$this->maintenance->set_setting( $key, absint( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		$this->logger->info( 'retention_saved', 'Retention settings were saved.', array(), 'system' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::STATUS_SLUG . '&replicaforge_notice=maintenance_ran' ) );
		exit;
	}

	/**
	 * Render the analyzer screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access ReplicaForge.', 'replicaforge' ) );
		}
		?>
		<div class="wrap replicaforge-admin">
			<header class="replicaforge-admin__header">
				<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Frontend intelligence', 'replicaforge' ); ?></p>
				<h1><?php esc_html_e( 'ReplicaForge', 'replicaforge' ); ?></h1>
				<p class="replicaforge-admin__intro">
					<?php esc_html_e( 'Securely inspect a public website, understand its structure, and optionally plan a reconstruction.', 'replicaforge' ); ?>
				</p>
			</header>

			<div class="replicaforge-admin__grid">
				<section class="replicaforge-card replicaforge-card--form" aria-labelledby="replicaforge-analyzer-title">
					<h2 id="replicaforge-analyzer-title"><?php esc_html_e( 'Frontend Analyzer', 'replicaforge' ); ?></h2>
					<p class="replicaforge-card__description">
						<?php esc_html_e( 'Enter one public HTTP or HTTPS URL. ReplicaForge reads the frontend only and never runs remote code.', 'replicaforge' ); ?>
					</p>
					<form id="replicaforge-analyze-form" class="replicaforge-form" novalidate>
						<label class="replicaforge-form__label" for="replicaforge-url"><?php esc_html_e( 'Website URL', 'replicaforge' ); ?></label>
						<input
							class="replicaforge-form__input"
							id="replicaforge-url"
							name="url"
							type="url"
							inputmode="url"
							autocomplete="url"
							spellcheck="false"
							placeholder="https://example.com"
							aria-describedby="replicaforge-url-help"
							required
						/>
						<p class="replicaforge-form__help" id="replicaforge-url-help">
							<?php esc_html_e( 'Only public frontend pages are supported. Private, local, and administrative addresses are blocked.', 'replicaforge' ); ?>
						</p>
						<button class="button button-primary button-hero replicaforge-form__button" id="replicaforge-analyze-button" type="submit">
							<?php esc_html_e( 'Analyze Website', 'replicaforge' ); ?>
						</button>
					</form>
				</section>

				<section class="replicaforge-card replicaforge-card--status" aria-labelledby="replicaforge-status-title">
					<h2 id="replicaforge-status-title"><?php esc_html_e( 'Analysis Status', 'replicaforge' ); ?></h2>
					<div class="replicaforge-status" id="replicaforge-status" aria-live="polite" aria-atomic="true">
						<p class="replicaforge-status__text" id="replicaforge-status-text"><?php esc_html_e( 'Not analyzed yet.', 'replicaforge' ); ?></p>
						<span class="replicaforge-spinner" id="replicaforge-spinner" aria-hidden="true" hidden></span>
					</div>
					<p class="replicaforge-status__hint" id="replicaforge-status-hint" hidden></p>
					<p class="replicaforge-status__error" id="replicaforge-error" role="alert" hidden></p>
					<p class="replicaforge-card__note">
						<?php esc_html_e( 'Phase 2 analyzes one page at a time and does not crawl the rest of the site.', 'replicaforge' ); ?>
					</p>
				</section>
			</div>

			<section class="replicaforge-results" id="replicaforge-results" aria-labelledby="replicaforge-results-title" hidden>
				<div class="replicaforge-results__heading">
					<div>
						<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Structured result', 'replicaforge' ); ?></p>
						<h2 id="replicaforge-results-title"><?php esc_html_e( 'Analysis Completed', 'replicaforge' ); ?></h2>
					</div>
					<span class="replicaforge-results__duration" id="replicaforge-duration"></span>
				</div>

				<div class="replicaforge-summary" id="replicaforge-summary"></div>

				<div class="replicaforge-results__columns">
					<section class="replicaforge-card" aria-labelledby="replicaforge-content-title">
						<h3 id="replicaforge-content-title"><?php esc_html_e( 'Content', 'replicaforge' ); ?></h3>
						<div class="replicaforge-stat-grid" id="replicaforge-content-stats"></div>
					</section>
					<section class="replicaforge-card" aria-labelledby="replicaforge-design-title">
						<h3 id="replicaforge-design-title"><?php esc_html_e( 'Design System', 'replicaforge' ); ?></h3>
						<div class="replicaforge-stat-grid" id="replicaforge-design-stats"></div>
					</section>
				</div>

				<section class="replicaforge-card replicaforge-sections" aria-labelledby="replicaforge-sections-title">
					<h3 id="replicaforge-sections-title"><?php esc_html_e( 'Sections', 'replicaforge' ); ?></h3>
					<div id="replicaforge-sections-list"></div>
				</section>

				<section class="replicaforge-card replicaforge-design-understanding" id="replicaforge-design-understanding" aria-labelledby="replicaforge-design-understanding-title" hidden>
					<div class="replicaforge-results__heading replicaforge-design-understanding__heading">
						<div>
							<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Phase 2', 'replicaforge' ); ?></p>
							<h3 id="replicaforge-design-understanding-title"><?php esc_html_e( 'Design Understanding', 'replicaforge' ); ?></h3>
						</div>
						<span class="replicaforge-results__duration" id="replicaforge-design-confidence"></span>
					</div>
					<div class="replicaforge-stat-grid" id="replicaforge-phase2-stats"></div>
					<div class="replicaforge-structure" id="replicaforge-structure-list"></div>
					<div class="replicaforge-details" id="replicaforge-phase2-details"></div>
					<details class="replicaforge-raw" id="replicaforge-representation-raw">
						<summary><?php esc_html_e( 'View Design Representation', 'replicaforge' ); ?></summary>
						<p class="replicaforge-card__note"><?php esc_html_e( 'Developer view of the normalized Phase 2 representation.', 'replicaforge' ); ?></p>
						<pre id="replicaforge-representation-json" tabindex="0"></pre>
					</details>
				</section>

				<section class="replicaforge-card replicaforge-ai" id="replicaforge-ai" aria-labelledby="replicaforge-ai-title" hidden>
					<div class="replicaforge-results__heading replicaforge-ai__heading">
						<div>
							<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Phase 3 · optional', 'replicaforge' ); ?></p>
							<h3 id="replicaforge-ai-title"><?php esc_html_e( 'AI Design Analysis', 'replicaforge' ); ?></h3>
						</div>
						<span class="replicaforge-results__duration" id="replicaforge-ai-status-label"></span>
					</div>
					<p class="replicaforge-card__description" id="replicaforge-ai-description">
						<?php esc_html_e( 'Run an optional AI interpretation of the structured Phase 2 representation. AI is never called automatically.', 'replicaforge' ); ?>
					</p>
					<p class="replicaforge-ai__provider" id="replicaforge-ai-provider"></p>
					<p class="replicaforge-form__help"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Open AI settings', 'replicaforge' ); ?></a></p>
					<button class="button button-primary" id="replicaforge-ai-run" type="button">
						<?php esc_html_e( 'Run AI Design Analysis', 'replicaforge' ); ?>
					</button>
					<p class="replicaforge-status__error" id="replicaforge-ai-error" role="alert" hidden></p>
					<p class="replicaforge-status__hint" id="replicaforge-ai-hint" hidden></p>
					<div id="replicaforge-ai-plan" hidden>
						<div class="replicaforge-results__heading replicaforge-ai__plan-heading">
							<h4 id="replicaforge-ai-plan-title"><?php esc_html_e( 'AI Reconstruction Plan', 'replicaforge' ); ?></h4>
							<span class="replicaforge-results__duration" id="replicaforge-ai-confidence"></span>
						</div>
						<div class="replicaforge-ai__strategy" id="replicaforge-ai-strategy"></div>
						<div class="replicaforge-ai__grid">
							<section class="replicaforge-ai__panel" aria-labelledby="replicaforge-ai-sections-title">
								<h5 id="replicaforge-ai-sections-title"><?php esc_html_e( 'Sections', 'replicaforge' ); ?></h5>
								<div id="replicaforge-ai-sections"></div>
							</section>
							<section class="replicaforge-ai__panel" aria-labelledby="replicaforge-ai-design-title">
								<h5 id="replicaforge-ai-design-title"><?php esc_html_e( 'Design system', 'replicaforge' ); ?></h5>
								<div id="replicaforge-ai-design"></div>
							</section>
							<section class="replicaforge-ai__panel" aria-labelledby="replicaforge-ai-responsive-title">
								<h5 id="replicaforge-ai-responsive-title"><?php esc_html_e( 'Responsive strategy', 'replicaforge' ); ?></h5>
								<div id="replicaforge-ai-responsive"></div>
							</section>
							<section class="replicaforge-ai__panel" aria-labelledby="replicaforge-ai-warnings-title">
								<h5 id="replicaforge-ai-warnings-title"><?php esc_html_e( 'Warnings', 'replicaforge' ); ?></h5>
								<div id="replicaforge-ai-warnings"></div>
							</section>
						</div>
						<details class="replicaforge-raw" id="replicaforge-ai-json-raw">
							<summary><?php esc_html_e( 'View Reconstruction JSON', 'replicaforge' ); ?></summary>
							<p class="replicaforge-card__note"><?php esc_html_e( 'Developer view of the validated specification. It is displayed as text and never executed.', 'replicaforge' ); ?></p>
							<pre id="replicaforge-ai-json" tabindex="0"></pre>
						</details>
					</div>
				</section>

				<section class="replicaforge-card replicaforge-phase4" id="replicaforge-phase4" aria-labelledby="replicaforge-phase4-title" hidden>
					<div class="replicaforge-results__heading replicaforge-phase4__heading">
						<div>
							<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Phase 4', 'replicaforge' ); ?></p>
							<h3 id="replicaforge-phase4-title"><?php esc_html_e( 'Elementor Draft', 'replicaforge' ); ?></h3>
						</div>
						<span class="replicaforge-results__duration" id="replicaforge-phase4-status"></span>
					</div>
					<p class="replicaforge-card__description" id="replicaforge-phase4-description">
						<?php esc_html_e( 'Convert the validated reconstruction specification into an editable Elementor page.', 'replicaforge' ); ?>
					</p>
					<p class="replicaforge-phase4__status" id="replicaforge-phase4-elementor"></p>
					<p class="replicaforge-form__help" id="replicaforge-phase4-help"></p>
					<div class="replicaforge-phase4__options">
						<label>
							<input type="checkbox" id="replicaforge-phase4-import" value="1" />
							<?php esc_html_e( 'Copy detected images into the media library', 'replicaforge' ); ?>
						</label>
						<p class="replicaforge-card__note"><?php esc_html_e( 'Off by default. A publicly reachable image is not automatically licensed for reuse, so copying is an explicit choice.', 'replicaforge' ); ?></p>
					</div>
					<button class="button button-primary" id="replicaforge-phase4-run" type="button">
						<?php esc_html_e( 'Generate Elementor Draft', 'replicaforge' ); ?>
					</button>
					<p class="replicaforge-status__hint" id="replicaforge-phase4-hint" hidden></p>
					<p class="replicaforge-status__error" id="replicaforge-phase4-error" role="alert" hidden></p>
					<div class="replicaforge-phase4__result" id="replicaforge-phase4-result" hidden>
						<h4 id="replicaforge-phase4-result-title"><?php esc_html_e( 'Replica generated successfully.', 'replicaforge' ); ?></h4>
						<p class="replicaforge-phase4__draft" id="replicaforge-phase4-draft"></p>
						<p class="replicaforge-stat-grid" id="replicaforge-phase4-stats"></p>
						<div class="replicaforge-phase4__actions">
							<a class="button" id="replicaforge-phase4-open" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Draft', 'replicaforge' ); ?></a>
							<a class="button" id="replicaforge-phase4-elementor-edit" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit with Elementor', 'replicaforge' ); ?></a>
							<a class="button" id="replicaforge-phase4-preview" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview', 'replicaforge' ); ?></a>
						</div>
						<p class="replicaforge-card__note"><?php esc_html_e( 'The generated page is a draft. Publishing is left to you in WordPress or Elementor.', 'replicaforge' ); ?></p>
						<div class="replicaforge-phase4__panels">
							<section class="replicaforge-ai__panel" aria-labelledby="replicaforge-phase4-warnings-title">
								<h5 id="replicaforge-phase4-warnings-title"><?php esc_html_e( 'Warnings', 'replicaforge' ); ?></h5>
								<div id="replicaforge-phase4-warnings"></div>
							</section>
							<section class="replicaforge-ai__panel" aria-labelledby="replicaforge-phase4-limitations-title">
								<h5 id="replicaforge-phase4-limitations-title"><?php esc_html_e( 'Known limitations', 'replicaforge' ); ?></h5>
								<div id="replicaforge-phase4-limitations"></div>
							</section>
						</div>
						<details class="replicaforge-raw" id="replicaforge-phase4-report-raw">
							<summary><?php esc_html_e( 'View Generation Report', 'replicaforge' ); ?></summary>
							<p class="replicaforge-card__note"><?php esc_html_e( 'Developer view of the generation report. It is displayed as text and never executed.', 'replicaforge' ); ?></p>
							<pre id="replicaforge-phase4-report" tabindex="0"></pre>
						</details>
					</div>
				</section>


				<section class="replicaforge-card replicaforge-phase5" id="replicaforge-phase5" aria-labelledby="replicaforge-phase5-title" hidden>
					<div class="replicaforge-results__heading replicaforge-phase5__heading">
						<div>
							<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Phase 5', 'replicaforge' ); ?></p>
							<h3 id="replicaforge-phase5-title"><?php esc_html_e( 'Visual Validation', 'replicaforge' ); ?></h3>
						</div>
						<span class="replicaforge-results__duration" id="replicaforge-phase5-status"></span>
					</div>
					<p class="replicaforge-card__description" id="replicaforge-phase5-description">
						<?php esc_html_e( 'Measure how closely the generated draft matches the analyzed source.', 'replicaforge' ); ?>
					</p>
					<p class="replicaforge-phase5__notice" id="replicaforge-phase5-notice" hidden></p>
					<div class="replicaforge-phase5__options">
						<label>
							<input type="checkbox" id="replicaforge-phase5-visual" value="1" />
							<?php esc_html_e( 'Include rendered screenshot comparison', 'replicaforge' ); ?>
						</label>
						<label>
							<input type="checkbox" id="replicaforge-phase5-ai" value="1" />
							<?php esc_html_e( 'Ask AI to explain the detected differences', 'replicaforge' ); ?>
						</label>
						<label>
							<input type="checkbox" id="replicaforge-phase5-force" value="1" />
							<?php esc_html_e( 'Ignore cached result and compare again', 'replicaforge' ); ?>
						</label>
						<p class="replicaforge-card__note" id="replicaforge-phase5-visual-note"></p>
					</div>
					<button class="button button-primary" id="replicaforge-phase5-run" type="button">
						<?php esc_html_e( 'Run Validation', 'replicaforge' ); ?>
					</button>
					<p class="replicaforge-form__help"><?php esc_html_e( 'Validation never modifies the Elementor document and never applies the correction plan.', 'replicaforge' ); ?></p>
					<p class="replicaforge-status__hint" id="replicaforge-phase5-hint" hidden></p>
					<p class="replicaforge-status__error" id="replicaforge-phase5-error" role="alert" hidden></p>
					<div class="replicaforge-phase5__result" id="replicaforge-phase5-result" hidden>
						<h4 id="replicaforge-phase5-result-title"><?php esc_html_e( 'Validation report', 'replicaforge' ); ?></h4>
						<p class="replicaforge-stat-grid" id="replicaforge-phase5-summary"></p>
						<div class="replicaforge-phase5__tabs" id="replicaforge-phase5-tabs" role="tablist"></div>
						<div class="replicaforge-phase5__panels" id="replicaforge-phase5-panels"></div>
						<div class="replicaforge-phase4__actions">
							<button class="button" id="replicaforge-phase5-export-json" type="button"><?php esc_html_e( 'Export JSON', 'replicaforge' ); ?></button>
							<button class="button" id="replicaforge-phase5-export-csv" type="button"><?php esc_html_e( 'Export CSV', 'replicaforge' ); ?></button>
						</div>
						<details class="replicaforge-raw" id="replicaforge-phase5-report-raw">
							<summary><?php esc_html_e( 'View Validation Report', 'replicaforge' ); ?></summary>
							<p class="replicaforge-card__note"><?php esc_html_e( 'Developer view of the validation record. It is displayed as text and never executed.', 'replicaforge' ); ?></p>
							<pre id="replicaforge-phase5-report" tabindex="0"></pre>
						</details>
					</div>
				</section>

				<section class="replicaforge-card replicaforge-phase6" id="replicaforge-phase6" aria-labelledby="replicaforge-phase6-title" hidden>
					<div class="replicaforge-results__heading replicaforge-phase6__heading">
						<div>
							<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Phase 6', 'replicaforge' ); ?></p>
							<h3 id="replicaforge-phase6-title"><?php esc_html_e( 'Corrections', 'replicaforge' ); ?></h3>
						</div>
						<span class="replicaforge-results__duration" id="replicaforge-phase6-status"></span>
					</div>
					<p class="replicaforge-card__description" id="replicaforge-phase6-description">
						<?php esc_html_e( 'Review the measurable differences and apply the safe ones.', 'replicaforge' ); ?>
					</p>
					<p class="replicaforge-phase6__notice" id="replicaforge-phase6-notice" hidden></p>
					<div class="replicaforge-phase6__options">
						<label>
							<input type="checkbox" id="replicaforge-phase6-ai" value="1" />
							<?php esc_html_e( 'Let AI suggest the correction order', 'replicaforge' ); ?>
						</label>
						<p class="replicaforge-card__note" id="replicaforge-phase6-ai-note"></p>
					</div>
					<button class="button button-primary" id="replicaforge-phase6-plan" type="button">
						<?php esc_html_e( 'Review Corrections', 'replicaforge' ); ?>
					</button>
					<p class="replicaforge-form__help"><?php esc_html_e( 'Reviewing a plan never changes the document. A change only happens when you apply one.', 'replicaforge' ); ?></p>
					<p class="replicaforge-status__hint" id="replicaforge-phase6-hint" hidden></p>
					<p class="replicaforge-status__error" id="replicaforge-phase6-error" role="alert" hidden></p>
					<div class="replicaforge-phase6__review" id="replicaforge-phase6-review" hidden>
						<div class="replicaforge-results__heading">
							<h4 id="replicaforge-phase6-review-title"><?php esc_html_e( 'Corrections available', 'replicaforge' ); ?></h4>
							<span class="replicaforge-results__duration" id="replicaforge-phase6-plan-id"></span>
						</div>
						<p class="replicaforge-stat-grid" id="replicaforge-phase6-counts"></p>
						<div class="replicaforge-phase6__toolbar">
							<button class="button" id="replicaforge-phase6-select-all" type="button"><?php esc_html_e( 'Select all safe', 'replicaforge' ); ?></button>
							<button class="button" id="replicaforge-phase6-select-none" type="button"><?php esc_html_e( 'Clear selection', 'replicaforge' ); ?></button>
							<button class="button" id="replicaforge-phase6-apply-safe" type="button"><?php esc_html_e( 'Apply Safe Corrections', 'replicaforge' ); ?></button>
							<button class="button button-primary" id="replicaforge-phase6-apply-selected" type="button"><?php esc_html_e( 'Apply Selected', 'replicaforge' ); ?></button>
						</div>
						<label class="replicaforge-phase6__structural">
							<input type="checkbox" id="replicaforge-phase6-structural" value="1" />
							<?php esc_html_e( 'I approve the structural corrections listed above (insert, remove, or reorder)', 'replicaforge' ); ?>
						</label>
						<p class="replicaforge-card__note" id="replicaforge-phase6-structural-note"><?php esc_html_e( 'Structural corrections change the page layout. They are only applied when this is ticked.', 'replicaforge' ); ?></p>
						<div class="replicaforge-phase6__groups" id="replicaforge-phase6-groups"></div>
						<div class="replicaforge-phase4__actions">
							<button class="button" id="replicaforge-phase6-export-plan" type="button"><?php esc_html_e( 'Export Plan JSON', 'replicaforge' ); ?></button>
							<button class="button" id="replicaforge-phase6-export-plan-csv" type="button"><?php esc_html_e( 'Export Plan CSV', 'replicaforge' ); ?></button>
						</div>
					</div>
					<div class="replicaforge-phase6__result" id="replicaforge-phase6-result" hidden>
						<h4 id="replicaforge-phase6-result-title"><?php esc_html_e( 'Correction complete', 'replicaforge' ); ?></h4>
						<p class="replicaforge-stat-grid" id="replicaforge-phase6-summary"></p>
						<p class="replicaforge-card__note" id="replicaforge-phase6-measurement"></p>
						<div class="replicaforge-phase6__panels" id="replicaforge-phase6-panels"></div>
						<div class="replicaforge-phase4__actions">
							<a class="button" id="replicaforge-phase6-elementor" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Elementor', 'replicaforge' ); ?></a>
							<a class="button" id="replicaforge-phase6-preview" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Preview', 'replicaforge' ); ?></a>
							<button class="button" id="replicaforge-phase6-validate" type="button"><?php esc_html_e( 'Validate Again', 'replicaforge' ); ?></button>
							<button class="button" id="replicaforge-phase6-export-run" type="button"><?php esc_html_e( 'Export Report JSON', 'replicaforge' ); ?></button>
						</div>
						<p class="replicaforge-card__note"><?php esc_html_e( 'Corrections only ever change a draft. Publishing stays a manual decision in WordPress or Elementor.', 'replicaforge' ); ?></p>
					</div>
				</section>
				<div class="replicaforge-details" id="replicaforge-details"></div>

				<details class="replicaforge-card replicaforge-raw" id="replicaforge-raw-analysis">
					<summary><?php esc_html_e( 'View Raw Analysis', 'replicaforge' ); ?></summary>
					<p class="replicaforge-card__note"><?php esc_html_e( 'Developer view of the same structured result returned by the REST endpoint.', 'replicaforge' ); ?></p>
					<pre id="replicaforge-raw-json" tabindex="0"></pre>
				</details>
			</section>

			<noscript>
				<div class="notice notice-warning"><p><?php esc_html_e( 'JavaScript is required to run the ReplicaForge analyzer.', 'replicaforge' ); ?></p></div>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Render the Phase 5 validation screen.
	 *
	 * The screen lists the drafts this user may validate, the render provider
	 * configuration, and the recent validation history. It performs no
	 * comparison itself; results are produced by the REST endpoint.
	 *
	 * @return void
	 */
	public function render_validation_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access ReplicaForge validation.', 'replicaforge' ) );
		}
		$renderer  = $this->visual_renderer->public_settings();
		$library   = $this->visual_renderer->image_library();
		$history   = $this->validation_cache->recent( 10 );
		$drafts    = $this->validation_drafts();
		$notice    = isset( $_GET['replicaforge_notice'] ) ? sanitize_key( wp_unslash( $_GET['replicaforge_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ai_public = $this->ai_manager->get_public_settings();
		$public_ai = ! empty( $ai_public['enabled'] );
		?>
		<div class="wrap replicaforge-admin">
			<header class="replicaforge-admin__header">
				<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Phase 5', 'replicaforge' ); ?></p>
				<h1><?php esc_html_e( 'Visual Validation', 'replicaforge' ); ?></h1>
				<p class="replicaforge-admin__intro">
					<?php esc_html_e( 'Compare a generated Elementor draft against the analyzed source page and read the measured differences.', 'replicaforge' ); ?>
				</p>
			</header>

			<?php if ( 'renderer_saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Render provider settings saved.', 'replicaforge' ); ?></p></div>
			<?php elseif ( 'renderer_error' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The render provider settings could not be saved.', 'replicaforge' ); ?></p></div>
			<?php endif; ?>

			<div class="replicaforge-admin__grid">
				<section class="replicaforge-card replicaforge-card--status" aria-labelledby="replicaforge-val-caps-title">
					<h2 id="replicaforge-val-caps-title"><?php esc_html_e( 'Comparison levels available', 'replicaforge' ); ?></h2>
					<ul class="replicaforge-val__levels">
						<li class="replicaforge-val__level is-on"><span class="replicaforge-badge replicaforge-badge--ok"><?php esc_html_e( 'Available', 'replicaforge' ); ?></span> <?php esc_html_e( 'Structural comparison', 'replicaforge' ); ?></li>
						<li class="replicaforge-val__level is-on"><span class="replicaforge-badge replicaforge-badge--ok"><?php esc_html_e( 'Available', 'replicaforge' ); ?></span> <?php esc_html_e( 'Design-system comparison', 'replicaforge' ); ?></li>
						<li class="replicaforge-val__level <?php echo empty( $renderer['enabled'] ) ? 'is-off' : 'is-on'; ?>">
							<span class="replicaforge-badge <?php echo empty( $renderer['enabled'] ) ? 'replicaforge-badge--muted' : 'replicaforge-badge--ok'; ?>"><?php echo empty( $renderer['enabled'] ) ? esc_html__( 'Unavailable', 'replicaforge' ) : esc_html__( 'Available', 'replicaforge' ); ?></span>
							<?php esc_html_e( 'Rendered visual comparison', 'replicaforge' ); ?>
						</li>
						<li class="replicaforge-val__level <?php echo empty( $public_ai ) ? 'is-off' : 'is-on'; ?>">
							<span class="replicaforge-badge <?php echo empty( $public_ai ) ? 'replicaforge-badge--muted' : 'replicaforge-badge--ok'; ?>"><?php echo empty( $public_ai ) ? esc_html__( 'Unavailable', 'replicaforge' ) : esc_html__( 'Available', 'replicaforge' ); ?></span>
							<?php esc_html_e( 'AI explanation of measured differences', 'replicaforge' ); ?>
						</li>
					</ul>
					<p class="replicaforge-card__note">
						<?php esc_html_e( 'Server image library:', 'replicaforge' ); ?>
						<strong><?php echo '' === $library ? esc_html__( 'None', 'replicaforge' ) : esc_html( $library ); ?></strong>
					</p>
				</section>

				<section class="replicaforge-card replicaforge-card--status" aria-labelledby="replicaforge-val-run-title">
					<h2 id="replicaforge-val-run-title"><?php esc_html_e( 'Run a validation', 'replicaforge' ); ?></h2>
					<p class="replicaforge-card__description">
						<?php esc_html_e( 'Validation is started from the analyzer screen so the Phase 2 representation stays paired with the draft it came from.', 'replicaforge' ); ?>
					</p>
					<p class="replicaforge-card__note">
						<?php esc_html_e( 'Drafts available to you:', 'replicaforge' ); ?>
						<strong><?php echo esc_html( number_format_i18n( count( $drafts ) ) ); ?></strong>
					</p>
					<p class="replicaforge-form__help">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Open the analyzer', 'replicaforge' ); ?></a>
					</p>
				</section>
			</div>

			<section class="replicaforge-card" aria-labelledby="replicaforge-val-render-title">
				<h2 id="replicaforge-val-render-title"><?php esc_html_e( 'Rendered comparison provider', 'replicaforge' ); ?></h2>
				<p class="replicaforge-card__description">
					<?php esc_html_e( 'Optional. A WordPress plugin cannot ship a headless browser, so rendered comparison is delegated to an external render service you control. It is disabled by default.', 'replicaforge' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="replicaforge-form">
					<input type="hidden" name="action" value="replicaforge_save_render_settings" />
					<?php wp_nonce_field( 'replicaforge_save_render_settings' ); ?>
					<label class="replicaforge-form__label">
						<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $renderer['enabled'] ) ); ?> />
						<?php esc_html_e( 'Enable rendered comparison', 'replicaforge' ); ?>
					</label>
					<label class="replicaforge-form__label" for="replicaforge-render-endpoint"><?php esc_html_e( 'Render endpoint URL', 'replicaforge' ); ?></label>
					<input class="replicaforge-form__input" id="replicaforge-render-endpoint" name="endpoint" type="url" inputmode="url" spellcheck="false" placeholder="https://renderer.internal.example/screenshot" value="<?php echo esc_attr( $renderer['endpoint'] ); ?>" />
					<label class="replicaforge-form__label" for="replicaforge-render-token"><?php esc_html_e( 'Bearer token (optional)', 'replicaforge' ); ?></label>
					<input class="replicaforge-form__input" id="replicaforge-render-token" name="token" type="password" autocomplete="off" value="" placeholder="<?php echo $renderer['has_token'] ? esc_attr__( 'Stored. Leave blank to keep.', 'replicaforge' ) : ''; ?>" />
					<label class="replicaforge-form__label" for="replicaforge-render-timeout"><?php esc_html_e( 'Request timeout (seconds)', 'replicaforge' ); ?></label>
					<input class="replicaforge-form__input" id="replicaforge-render-timeout" name="timeout" type="number" min="5" max="60" step="1" value="<?php echo esc_attr( (string) $renderer['timeout'] ); ?>" />
					<label class="replicaforge-form__label" for="replicaforge-render-maxwidth"><?php esc_html_e( 'Maximum capture width (0 uses the viewport width)', 'replicaforge' ); ?></label>
					<input class="replicaforge-form__input" id="replicaforge-render-maxwidth" name="max_width" type="number" min="0" max="4000" step="1" value="<?php echo esc_attr( (string) $renderer['max_width'] ); ?>" />
					<p class="replicaforge-form__help">
						<?php esc_html_e( 'The endpoint must be a public HTTPS URL. The plugin sends a bounded JSON request containing the validated source URL, or the generated document markup and stylesheet URLs. It never sends credentials, and it never renders content inside the WordPress process.', 'replicaforge' ); ?>
					</p>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Save provider settings', 'replicaforge' ); ?></button>
				</form>
			</section>

			<section class="replicaforge-card" aria-labelledby="replicaforge-val-history-title">
				<h2 id="replicaforge-val-history-title"><?php esc_html_e( 'Recent validations', 'replicaforge' ); ?></h2>
				<?php if ( empty( $history ) ) : ?>
					<p class="replicaforge-card__note"><?php esc_html_e( 'No validations have been run yet.', 'replicaforge' ); ?></p>
				<?php else : ?>
					<table class="widefat striped replicaforge-val__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Validation ID', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Source', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Draft', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Differences', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Completed', 'replicaforge' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $history as $record ) : ?>
							<tr>
								<td><code><?php echo esc_html( isset( $record['validation_id'] ) ? (string) $record['validation_id'] : '' ); ?></code></td>
								<td><?php echo esc_html( isset( $record['source_url'] ) ? (string) $record['source_url'] : '' ); ?></td>
								<td>
									<?php $rf_draft = isset( $record['draft_post_id'] ) ? (int) $record['draft_post_id'] : 0; ?>
									<?php if ( $rf_draft > 0 && current_user_can( 'edit_post', $rf_draft ) ) : ?>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $rf_draft ) ); ?>">#<?php echo esc_html( (string) $rf_draft ); ?></a>
									<?php else : ?>
										#<?php echo esc_html( (string) $rf_draft ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( number_format_i18n( isset( $record['difference_count'] ) ? (int) $record['difference_count'] : 0 ) ); ?></td>
								<td><?php echo esc_html( isset( $record['completed_at'] ) ? (string) $record['completed_at'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>

			<noscript>
				<div class="notice notice-warning"><p><?php esc_html_e( 'JavaScript is required to run ReplicaForge validation from the analyzer screen.', 'replicaforge' ); ?></p></div>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Render the Phase 6 corrections screen.
	 *
	 * The screen is informational: it lists the correction runs recorded for a
	 * draft and the snapshots that can be restored. Planning and applying both
	 * happen from the analyzer screen, so a correction is always reviewed against
	 * the validation that produced it.
	 *
	 * @return void
	 */
	public function render_corrections_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access ReplicaForge corrections.', 'replicaforge' ) );
		}

		$drafts    = $this->validation_drafts();
		$library   = array();
		$property_map = new Correction_Property_Map();
		foreach ( $drafts as $draft ) {
			$library[ (int) $draft['id']] = $draft;
		}

		$selected = isset( $_GET['draft'] ) ? absint( $_GET['draft'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $selected < 1 || ! isset( $library[ $selected ] ) ) {
			$selected = ! empty( $library ) ? (int) array_key_first( $library ) : 0;
		}
		$history = $selected > 0 ? $this->correction_history->for_draft( $selected, 25 ) : array();
		$snapshots = $selected > 0 ? ( new Correction_Snapshot() )->snapshots( $selected ) : array();
		?>
		<div class="wrap replicaforge-admin">
			<header class="replicaforge-admin__header">
				<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Phase 6', 'replicaforge' ); ?></p>
				<h1><?php esc_html_e( 'Corrections', 'replicaforge' ); ?></h1>
				<p class="replicaforge-admin__intro">
					<?php esc_html_e( 'Every correction run applied to a ReplicaForge draft, with what changed, what was rejected, and what the re-validation measured.', 'replicaforge' ); ?>
				</p>
			</header>

			<section class="replicaforge-card" aria-labelledby="replicaforge-cor-history-title">
				<h2 id="replicaforge-cor-history-title"><?php esc_html_e( 'Correction History', 'replicaforge' ); ?></h2>
				<?php if ( empty( $drafts ) ) : ?>
					<p class="replicaforge-card__note"><?php esc_html_e( 'No ReplicaForge drafts are available to you yet.', 'replicaforge' ); ?></p>
				<?php else : ?>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="replicaforge-form">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::CORRECTIONS_SLUG ); ?>" />
						<label class="replicaforge-form__label" for="replicaforge-cor-draft"><?php esc_html_e( 'Generated draft', 'replicaforge' ); ?></label>
						<select class="replicaforge-form__input" id="replicaforge-cor-draft" name="draft">
							<?php foreach ( $library as $rf_id => $rf_draft ) : ?>
								<option value="<?php echo esc_attr( (string) $rf_id ); ?>" <?php selected( $selected, (int) $rf_id ); ?>>
									#<?php echo esc_html( (string) $rf_id ); ?> &mdash; <?php echo esc_html( $rf_draft['title'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button class="button" type="submit"><?php esc_html_e( 'Show history', 'replicaforge' ); ?></button>
					</form>
				<?php endif; ?>
				<?php if ( $selected < 1 ) : ?>
					<p class="replicaforge-card__note"><?php esc_html_e( 'No corrections have been applied to this draft yet.', 'replicaforge' ); ?></p>
				<?php elseif ( empty( $history ) ) : ?>
					<p class="replicaforge-card__note"><?php esc_html_e( 'No corrections have been applied to this draft yet.', 'replicaforge' ); ?></p>
				<?php else : ?>
					<table class="widefat striped replicaforge-val__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Run', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'When', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Applied', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Before', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'After', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Change', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Regressions', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'replicaforge' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $history as $record ) : ?>
							<tr>
								<td><code><?php echo esc_html( isset( $record['correction_id'] ) ? (string) $record['correction_id'] : '' ); ?></code></td>
								<td><?php echo esc_html( isset( $record['created_at'] ) ? (string) $record['created_at'] : '' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( isset( $record['counts']['applied'] ) ? (int) $record['counts']['applied'] : 0 ) ); ?></td>
								<td><?php echo esc_html( isset( $record['validation_before'] ) && is_numeric( $record['validation_before'] ) ? (string) $record['validation_before'] : '—' ); ?></td>
								<td><?php echo esc_html( isset( $record['validation_after'] ) && is_numeric( $record['validation_after'] ) ? (string) $record['validation_after'] : '—' ); ?></td>
								<td><?php echo esc_html( isset( $record['improvement'] ) && is_numeric( $record['improvement'] ) ? ( ( $record['improvement'] > 0 ? '+' : '' ) . (string) $record['improvement'] ) : '—' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( isset( $record['regressions'] ) ? (int) $record['regressions'] : 0 ) ); ?></td>
								<td><?php echo esc_html( isset( $record['status'] ) ? (string) $record['status'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="replicaforge-card__note"><?php esc_html_e( 'Measurements are the internal validation metrics described in the validation report. A change is a measured difference against the source analysis, not a guarantee of visual quality.', 'replicaforge' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="replicaforge-card" aria-labelledby="replicaforge-cor-snapshots-title">
				<h2 id="replicaforge-cor-snapshots-title"><?php esc_html_e( 'Snapshots', 'replicaforge' ); ?></h2>
				<?php if ( empty( $snapshots ) ) : ?>
					<p class="replicaforge-card__note"><?php esc_html_e( 'No snapshots exist for this draft. A snapshot is created before every applied correction batch.', 'replicaforge' ); ?></p>
				<?php else : ?>
					<table class="widefat striped replicaforge-val__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Snapshot', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Document hash', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Size', 'replicaforge' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Created', 'replicaforge' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $snapshots as $snapshot ) : ?>
							<tr>
								<td><code><?php echo esc_html( isset( $snapshot['snapshot_id'] ) ? (string) $snapshot['snapshot_id'] : '—' ); ?></code></td>
								<td><code><?php echo esc_html( substr( isset( $snapshot['document_hash'] ) ? (string) $snapshot['document_hash'] : '', 0, 16 ) ); ?></code></td>
								<td><?php echo esc_html( size_format( isset( $snapshot['bytes'] ) ? (int) $snapshot['bytes'] : 0 ) ); ?></td>
								<td><?php echo esc_html( isset( $snapshot['created_at'] ) ? (string) $snapshot['created_at'] : '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="replicaforge-card__note"><?php esc_html_e( 'Snapshots are kept for a limited time and only the most recent ones are retained. A rollback restores the exact stored document, after validating it.', 'replicaforge' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="replicaforge-card" aria-labelledby="replicaforge-cor-levels-title">
				<h2 id="replicaforge-cor-levels-title"><?php esc_html_e( 'Correction levels and writable properties', 'replicaforge' ); ?></h2>
				<ul class="replicaforge-val__levels">
					<?php foreach ( Correction_Limits::LEVELS as $rf_level => $rf_name ) : ?>
						<li class="replicaforge-val__level is-on">
							<span class="replicaforge-badge replicaforge-badge--muted"><?php echo esc_html( (string) $rf_level ); ?></span>
							<?php echo esc_html( str_replace( '_', ' ', $rf_name ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="replicaforge-card__note"><?php esc_html_e( 'A property outside this list has no approved Elementor control, so ReplicaForge reports the difference and never writes it.', 'replicaforge' ); ?></p>
				<table class="widefat striped replicaforge-val__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Property', 'replicaforge' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Elementor control', 'replicaforge' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Level', 'replicaforge' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Batch', 'replicaforge' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Per device', 'replicaforge' ); ?></th>
						</tr>
					</thead>
						<tbody>
						<?php foreach ( $property_map->all() as $rf_property => $rf_entry ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $rf_property ); ?></code></td>
								<td><code><?php echo esc_html( (string) $rf_entry['control'] ); ?></code></td>
								<td><?php echo esc_html( (string) (int) $rf_entry['level'] ); ?></td>
								<td><?php echo esc_html( (string) $rf_entry['batch'] ); ?></td>
								<td><?php echo empty( $rf_entry['devices'] ) ? esc_html__( 'No', 'replicaforge' ) : esc_html__( 'Yes', 'replicaforge' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
			</section>

			<noscript>
				<div class="notice notice-warning"><p><?php esc_html_e( 'JavaScript is required to plan and apply corrections from the analyzer screen.', 'replicaforge' ); ?></p></div>
			</noscript>
		</div>
		<?php
	}
	/**
	 * Return the ReplicaForge drafts the current user may validate.
	 *
	 * Only drafts generated by this plugin are listed, and only when the
	 * current user can edit them, so a validation never exposes another user's
	 * document.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function validation_drafts() {
		$drafts = array();
		$query  = new \WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => array( 'draft', 'pending', 'private' ),
				'posts_per_page'         => 20,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Elementor_Limits::META_PREFIX . 'generation_id',
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
			)
		);

		foreach ( (array) $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$drafts[] = array(
				'id'     => $post_id,
				'title'  => (string) get_the_title( $post_id ),
				'source' => (string) get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'source_url', true ),
				'when'   => (string) get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'generated_at', true ),
			);
		}
		return $drafts;
	}

	/**
	 * Save the render provider configuration.
	 *
	 * The request is a capability-checked, nonce-protected form post. The
	 * endpoint is re-validated with the shared public-target policy, so a private
	 * or local address is refused here as well as at request time.
	 *
	 * @return void
	 */
	public function handle_save_render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change ReplicaForge settings.', 'replicaforge' ) );
		}
		check_admin_referer( 'replicaforge_save_render_settings' );

		$input = array(
			'enabled'   => ! empty( $_POST['enabled'] ),
			'endpoint'  => isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '',
			'timeout'   => isset( $_POST['timeout'] ) ? absint( $_POST['timeout'] ) : Validation_Limits::RENDER_TIMEOUT,
			'max_width' => isset( $_POST['max_width'] ) ? absint( $_POST['max_width'] ) : 0,
		);

		$token = isset( $_POST['token'] ) ? trim( (string) wp_unslash( $_POST['token'] ) ) : '';
		if ( '' === $token ) {
			// An empty field keeps the stored token rather than clearing it.
			$existing = $this->visual_renderer->settings();
			$token    = isset( $existing['token'] ) ? (string) $existing['token'] : '';
		}
		$input['token'] = $token;

		$stored = $this->visual_renderer->save_settings( $input );
		Security::log_event( 
			empty( $stored['success'] ) ? 'validation_render_settings_rejected' : 'validation_render_settings_saved',
			array( 'reason' => 'admin' )
		);
		wp_safe_redirect(
			admin_url(
				'admin.php?page=' . self::VALIDATION_SLUG . '&replicaforge_notice=' . ( empty( $stored['success'] ) ? 'renderer_error' : 'renderer_saved' )
			)
		);
		exit;
	}
	/**
	 * Render the server-side AI settings screen.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access ReplicaForge settings.', 'replicaforge' ) );
		}
		$settings = $this->ai_settings->get();
		$public   = $this->ai_manager->get_public_settings();
		$notice   = isset( $_GET['replicaforge_ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['replicaforge_ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'saved'            => __( 'AI settings saved.', 'replicaforge' ),
			'test_success'     => __( 'The AI provider connection succeeded.', 'replicaforge' ),
			'test_failed'      => __( 'The AI provider connection failed. Check the provider, model, and server-side key.', 'replicaforge' ),
			'save_failed'      => __( 'AI settings could not be saved.', 'replicaforge' ),
		);
		?>
		<div class="wrap replicaforge-admin">
			<header class="replicaforge-admin__header">
				<p class="replicaforge-admin__eyebrow"><?php esc_html_e( 'Optional Phase 3 services', 'replicaforge' ); ?></p>
				<h1><?php esc_html_e( 'ReplicaForge Settings', 'replicaforge' ); ?></h1>
				<p class="replicaforge-admin__intro"><?php esc_html_e( 'Configure an optional AI provider. Credentials stay server-side and are never sent to the browser, the analyzed website, or the analysis JSON.', 'replicaforge' ); ?></p>
			</header>
			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice <?php echo 'saved' === $notice || 'test_success' === $notice ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
			<?php endif; ?>
			<section class="replicaforge-card replicaforge-settings" aria-labelledby="replicaforge-settings-title">
				<h2 id="replicaforge-settings-title"><?php esc_html_e( 'AI Provider', 'replicaforge' ); ?></h2>
				<p class="replicaforge-card__description"><?php esc_html_e( 'AI is optional. Phase 1 and Phase 2 continue to work when it is disabled or unavailable. Provider and model names are not hard-coded by the analyzer.', 'replicaforge' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="replicaforge-form">
					<input type="hidden" name="action" value="replicaforge_save_ai_settings" />
					<?php wp_nonce_field( 'replicaforge_ai_settings', 'replicaforge_ai_nonce' ); ?>
					<label class="replicaforge-form__label" for="replicaforge-ai-provider"><?php esc_html_e( 'Provider', 'replicaforge' ); ?></label>
					<select class="replicaforge-form__input" id="replicaforge-ai-provider" name="provider">
						<option value="none" <?php selected( 'none', $settings['provider'] ); ?>><?php esc_html_e( 'None (deterministic only)', 'replicaforge' ); ?></option>
						<option value="openai" <?php selected( 'openai', $settings['provider'] ); ?>><?php esc_html_e( 'OpenAI-compatible', 'replicaforge' ); ?></option>
						<option value="gemini" <?php selected( 'gemini', $settings['provider'] ); ?>><?php esc_html_e( 'Google Gemini', 'replicaforge' ); ?></option>
					</select>
					<label class="replicaforge-form__label" for="replicaforge-ai-model"><?php esc_html_e( 'Model', 'replicaforge' ); ?></label>
					<input class="replicaforge-form__input" id="replicaforge-ai-model" name="model" type="text" value="<?php echo esc_attr( (string) $settings['model'] ); ?>" maxlength="120" autocomplete="off" />
					<label class="replicaforge-form__label" for="replicaforge-ai-key"><?php esc_html_e( 'API Key', 'replicaforge' ); ?></label>
					<input class="replicaforge-form__input" id="replicaforge-ai-key" name="api_key" type="password" value="" maxlength="512" autocomplete="new-password" placeholder="<?php echo esc_attr( $public['api_key_set'] ? __( 'A server-side key is stored. Leave blank to keep it.', 'replicaforge' ) : __( 'Enter a key (stored server-side)', 'replicaforge' ) ); ?>" />
					<p class="replicaforge-form__help"><?php echo esc_html( $public['api_key_set'] ? __( 'Stored key: •••••••••••• (never displayed).', 'replicaforge' ) : __( 'The key is never rendered back to the browser.', 'replicaforge' ) ); ?></p>
					<label class="replicaforge-form__label" for="replicaforge-ai-timeout"><?php esc_html_e( 'Provider timeout (seconds)', 'replicaforge' ); ?></label>
					<input class="replicaforge-form__input" id="replicaforge-ai-timeout" name="timeout" type="number" min="<?php echo esc_attr( (string) Ai_Limits::MIN_TIMEOUT ); ?>" max="<?php echo esc_attr( (string) Ai_Limits::MAX_TIMEOUT ); ?>" value="<?php echo esc_attr( (string) $settings['timeout'] ); ?>" />
					<label><input name="enabled" type="checkbox" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> /> <?php esc_html_e( 'Enable AI analysis', 'replicaforge' ); ?></label>
					<p class="replicaforge-form__help"><?php esc_html_e( 'The AI action is always user-triggered from the analyzer screen. It is not called during ordinary URL analysis.', 'replicaforge' ); ?></p>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Save AI Settings', 'replicaforge' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="replicaforge-form replicaforge-form--secondary">
					<input type="hidden" name="action" value="replicaforge_test_ai_connection" />
					<?php wp_nonce_field( 'replicaforge_ai_test', 'replicaforge_ai_test_nonce' ); ?>
					<button class="button" type="submit"><?php esc_html_e( 'Test Connection', 'replicaforge' ); ?></button>
				</form>
			</section>
		</div>
		<?php
	}

	/**
	 * Save AI settings from a nonce-protected admin-post request.
	 *
	 * @return void
	 */
	public function handle_save_ai_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change ReplicaForge settings.', 'replicaforge' ) );
		}
		check_admin_referer( 'replicaforge_ai_settings', 'replicaforge_ai_nonce' );
		$input = array(
			'provider' => isset( $_POST['provider'] ) && is_scalar( $_POST['provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider'] ) ) : 'none',
			'model'    => isset( $_POST['model'] ) && is_scalar( $_POST['model'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['model'] ) ) : '',
			'api_key'  => isset( $_POST['api_key'] ) && is_scalar( $_POST['api_key'] ) ? (string) wp_unslash( $_POST['api_key'] ) : '',
			'timeout'  => isset( $_POST['timeout'] ) && is_scalar( $_POST['timeout'] ) ? absint( wp_unslash( $_POST['timeout'] ) ) : Ai_Limits::DEFAULT_TIMEOUT,
			'enabled'  => ! empty( $_POST['enabled'] ),
		);
		$this->ai_settings->save( $input );
		$this->redirect_settings( 'saved' );
	}

	/**
	 * Test the configured provider from a nonce-protected admin-post request.
	 *
	 * @return void
	 */
	public function handle_test_ai_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to test ReplicaForge settings.', 'replicaforge' ) );
		}
		check_admin_referer( 'replicaforge_ai_test', 'replicaforge_ai_test_nonce' );
		$result = $this->ai_manager->test_connection();
		$this->redirect_settings( ! empty( $result['success'] ) ? 'test_success' : 'test_failed' );
	}

	/**
	 * Redirect back to the settings screen with a safe notice code.
	 *
	 * @param string $notice Notice code.
	 * @return void
	 */
	private function redirect_settings( $notice ) {
		$url = add_query_arg( 'page', self::SETTINGS_SLUG, admin_url( 'admin.php' ) );
		$url = add_query_arg( 'replicaforge_ai_notice', sanitize_key( $notice ), $url );
		wp_safe_redirect( $url );
		exit;
	}
}
