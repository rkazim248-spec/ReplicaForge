<?php
/**
 * Phase 13: the visual intelligence REST surface.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The visual controller.
 *
 * ### The security posture, stated before the routes
 *
 * Rendering and screenshots are the most dangerous capability in the plugin, because
 * they involve fetching and storing a rendering of somebody else's page. Three
 * rules hold across every route here:
 *
 * 1. **No route accepts a URL to render.** Discovery is the only path that produces
 *    URLs, and every one it produces has been through {@see Url_Validator}. A caller
 *    that wants a page rendered names a *project page*, not an address — so this
 *    controller cannot be used to ask the render provider to fetch an internal
 *    address, even by accident.
 * 2. **No route returns a filesystem path.** Screenshot access is by opaque capture
 *    id through {@see Render_Cache}, and the response carries bytes and dimensions
 *    only. §80 requires this and a leaked `wp-content/uploads/...` path is a
 *    reconnaissance gift.
 * 3. **Ownership is resolved before the store is read**, with one message for
 *    "missing" and "not yours", so a project or capture id cannot be enumerated.
 */
final class Visual_Api {

	/**
	 * Route namespace.
	 */
	const NAMESPACE_V1 = 'replicaforge/v1';

	/**
	 * Entitlement manager.
	 *
	 * @var Entitlement_Manager
	 */
	private $entitlements;

	/**
	 * Project access.
	 *
	 * @var Project_Access
	 */
	private $access;

	/**
	 * Renderer manager.
	 *
	 * @var Renderer_Manager
	 */
	private $renderers;

	/**
	 * Visual analyzer.
	 *
	 * @var Visual_Analyzer
	 */
	private $analyzer;

	/**
	 * Visual features.
	 *
	 * @var Visual_Features
	 */
	private $features;

	/**
	 * Dynamic detector.
	 *
	 * @var Dynamic_Detector
	 */
	private $dynamics;

	/**
	 * Comparator.
	 *
	 * @var Visual_Comparator
	 */
	private $comparator;

	/**
	 * Corrector.
	 *
	 * @var Visual_Corrector
	 */
	private $corrector;

	/**
	 * Cache.
	 *
	 * @var Render_Cache
	 */
	private $cache;

	/**
	 * Viewport manager.
	 *
	 * @var Viewport_Manager
	 */
	private $viewports;

	/**
	 * Render job builder.
	 *
	 * @var Render_Job
	 */
	private $jobs;

	/**
	 * Visual AI.
	 *
	 * @var Visual_AI
	 */
	private $ai;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $services Optional service overrides.
	 */
	public function __construct( array $services = array() ) {
		$this->logger     = isset( $services['logger'] ) && $services['logger'] instanceof Logger ? $services['logger'] : new Logger();
		$this->access     = isset( $services['access'] ) && $services['access'] instanceof Project_Access ? $services['access'] : new Project_Access();
		$this->renderers  = isset( $services['renderers'] ) && $services['renderers'] instanceof Renderer_Manager ? $services['renderers'] : new Renderer_Manager( null, $this->logger );
		$this->analyzer   = isset( $services['analyzer'] ) && $services['analyzer'] instanceof Visual_Analyzer ? $services['analyzer'] : new Visual_Analyzer();
		$this->features   = isset( $services['features'] ) && $services['features'] instanceof Visual_Features ? $services['features'] : new Visual_Features();
		$this->dynamics   = isset( $services['dynamics'] ) && $services['dynamics'] instanceof Dynamic_Detector ? $services['dynamics'] : new Dynamic_Detector();
		$this->comparator = isset( $services['comparator'] ) && $services['comparator'] instanceof Visual_Comparator ? $services['comparator'] : new Visual_Comparator();
		$this->corrector  = isset( $services['corrector'] ) && $services['corrector'] instanceof Visual_Corrector ? $services['corrector'] : new Visual_Corrector();
		$this->cache      = isset( $services['cache'] ) && $services['cache'] instanceof Render_Cache ? $services['cache'] : new Render_Cache( $this->logger );
		$this->viewports  = isset( $services['viewports'] ) && $services['viewports'] instanceof Viewport_Manager ? $services['viewports'] : new Viewport_Manager();
		$this->jobs       = isset( $services['jobs'] ) && $services['jobs'] instanceof Render_Job ? $services['jobs'] : new Render_Job( $this->logger );
		$this->ai         = isset( $services['ai'] ) && $services['ai'] instanceof Visual_AI ? $services['ai'] : new Visual_AI( null, $this->logger );
		// `isset`, not a bare read. A partially-populated service array is a normal
		// thing for a caller to pass, and reading a missing key emits a notice on
		// every such request.
		$this->entitlements = ( isset( $services['entitlements'] ) && $services['entitlements'] instanceof Entitlement_Manager )
			? $services['entitlements']
			: new Entitlement_Manager( null, null, $this->access, $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * Routes
	 * ------------------------------------------------------------------ */

	/**
	 * Register the Phase 13 routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$project = '(?P<project_id>[A-Za-z0-9_\-]{1,64})';

		$this->route( '/visual/capabilities', 'GET', 'get_capabilities', 'can_use' );
		$this->route( '/visual/viewports', 'GET', 'get_viewports', 'can_use' );
		$this->route( '/visual/settings', 'GET', 'get_settings', 'can_manage' );
		$this->route( '/visual/settings', 'POST', 'save_settings', 'can_manage' );
		$this->route( '/visual/cache', 'GET', 'get_cache', 'can_use' );
		$this->route( '/visual/cache/prune', 'POST', 'prune_cache', 'can_manage' );

		$this->route( '/websites/' . $project . '/visual/capabilities', 'GET', 'get_site_capabilities', 'can_use' );
		$this->route( '/websites/' . $project . '/visual/analyze', 'POST', 'analyze_page', 'can_use' );
		$this->route( '/websites/' . $project . '/visual/representation', 'GET', 'get_representation', 'can_use' );
		$this->route( '/websites/' . $project . '/visual/dynamic', 'GET', 'get_dynamic', 'can_use' );
		$this->route( '/websites/' . $project . '/visual/plan', 'POST', 'plan_render', 'can_use' );
		$this->route( '/websites/' . $project . '/visual/compare', 'POST', 'compare', 'can_use' );
		$this->route( '/websites/' . $project . '/visual/corrections', 'POST', 'plan_corrections', 'can_use' );
		$this->route( '/websites/' . $project . '/visual/dashboard', 'GET', 'get_dashboard', 'can_use' );
		$this->route( '/websites/' . $project . '/visual/consistency', 'GET', 'get_consistency', 'can_use' );

		// Screenshot bytes, by opaque capture id. No path is ever accepted or
		// returned — §80.
		$this->route( '/visual/captures/(?P<capture_id>[A-Za-z0-9_]{8,64})', 'GET', 'get_capture', 'can_use' );
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * GET /visual/capabilities.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_capabilities( $request ) {
		$renderer = $this->renderers->capabilities();

		return $this->ok( array(
			'renderer'     => $renderer,
			'image_reader' => Image_Readers::resolve()->id(),
			'viewports'    => $this->viewports->profiles(),
			'stages'       => Render_Job::stages(),
			'limits'       => array(
				'max_renders_per_job'     => Visual_Limits::MAX_RENDERS_PER_JOB,
				'max_correction_iterations'=> Visual_Limits::MAX_CORRECTION_ITERATIONS,
				'max_screenshot_bytes'    => Visual_Limits::max_screenshot_bytes(),
				'max_boxed_elements'      => Visual_Limits::MAX_BOXED_ELEMENTS,
				'max_sampled_pixels'      => Visual_Limits::MAX_SAMPLED_PIXELS,
			),
			'signals'      => Visual_Limits::SIGNALS,
			'relationships'=> Visual_Limits::RELATIONSHIPS,
			'warnings'     => Renderer_Manager::warnings(),
			'policy'       => array(
				'screenshots_are_evidence' => __( 'A screenshot is evidence for analysis and validation. It is never turned into the page.', 'replicaforge' ),
				'no_scripts_in_wordpress'  => __( 'Source website JavaScript never executes inside WordPress. Rendering happens out of process, or not at all.', 'replicaforge' ),
			),
		) );
	}

	/**
	 * GET /visual/viewports.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_viewports( $request ) {
		$profiles = $this->viewports->profiles();
		$out     = array();
		foreach ( $profiles as $name => $profile ) {
			$out[] = $profile;
		}

		return $this->ok( array(
			'viewports' => $out,
			'set'       => $this->viewports->render_set( 3 ),
			'note'      => __( 'Desktop, tablet, and mobile are Phase 5\'s own profiles, read rather than restated, so a validation report and a visual report agree on what "tablet" means.', 'replicaforge' ),
		) );
	}

	/**
	 * GET /visual/settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_settings( $request ) {
		return $this->ok( array( 'settings' => $this->ai->public_settings() ) );
	}

	/**
	 * POST /visual/settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( $request ) {
		$result = $this->ai->save_settings( (array) $request->get_param( 'settings' ) );
		return $this->ok( $result );
	}

	/**
	 * GET /visual/cache.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_cache( $request ) {
		return $this->ok( array( 'cache' => $this->cache->report() ) );
	}

	/**
	 * POST /visual/cache/prune.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function prune_cache( $request ) {
		$removed = $this->cache->prune();
		return $this->ok( array( 'removed' => $removed, 'cache' => $this->cache->report() ) );
	}

	/**
	 * GET /websites/{id}/visual/capabilities.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_site_capabilities( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}
		return $this->get_capabilities( $request );
	}

	/**
	 * POST /websites/{id}/visual/analyze.
	 *
	 * Analyses a page's visual features and returns the representation. It does
	 * **not** require a renderer: with none configured, this is a CSS-derived visual
	 * model with a confidence cap, which is a real and useful result.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function analyze_page( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$user_id = get_current_user_id();
		$id      = (string) $request->get_param( 'project_id' );
		$page_id = (string) $request->get_param( 'page_id' );
		$viewport_name = (string) ( $request->get_param( 'viewport' ) ?? 'desktop' );

		$pages = $this->websites()->pages( $id );
		if ( ! isset( $pages[ $page_id ] ) ) {
			return $this->error( 'unknown_page', __( 'That page is not in this project.', 'replicaforge' ), 404 );
		}

		$gate = $this->entitlements->check( 'analyze', $user_id );
		if ( empty( $gate['allowed'] ) ) {
			return $this->error( 'limit_reached', (string) ( $gate['message'] ?? '' ), (int) ( $gate['status'] ?? 429 ) );
		}

		$profile = $this->viewports->profile( $viewport_name );
		if ( null === $profile ) {
			return $this->error( 'unknown_viewport', __( 'That viewport profile is not available.', 'replicaforge' ), 400 );
		}

		// The representation comes from stored post meta. Nothing structural arrives
		// from the request, so this route cannot be handed a document.
		$representation = get_post_meta( $page_id, 'replicaforge_analysis', true );
		if ( ! is_array( $representation ) || array() === $representation ) {
			return $this->error( 'not_analyzed', __( 'This page has not been analyzed yet. Run the page analysis first.', 'replicaforge' ), 409 );
		}

		$render = array( 'succeeded' => false );
		if ( ! empty( $this->ai->settings()['screenshot_validation'] ) && $this->renderers->is_available() ) {
			$render = $this->capture_page( $pages[ $page_id ], $profile );
		}

		$geometry = $this->analyzer->analyze( $representation, $render, $profile );
		$features = $this->features->extract( $representation, $render );
		$dynamic  = $this->dynamics->detect( $representation, $render );

		$document = new Visual_Representation();
		$spec     = $document->build( $geometry, $features, $dynamic, array_merge( $profile, array( 'rendered' => (bool) $render['succeeded'] ) ) );

		// Measurement conflicts are recorded through the representation's own rule so
		// §52's banding and its both-sides-kept guarantee are the only implementation.
		foreach ( (array) ( $geometry['measurement_conflicts'] ?? array() ) as $conflict ) {
			if ( ! is_array( $conflict ) || empty( $conflict['delta'] ) ) {
				continue;
			}
			$document->record_conflict( 'geometry', $conflict['computed'] ?? array(), $conflict['rendered'] ?? array(), (float) $conflict['delta'], (string) ( $conflict['id'] ?? '' ) );
		}
		$spec = $document->to_array();

		$this->store_representation( $id, $page_id, $viewport_name, $spec );

		$built = new Visual_Representation( $spec );
		$verdict = $built->validate();

		return $this->ok( array(
			'page_id'        => $page_id,
			'viewport'       => $profile,
			'representation' => $spec,
			'validation'     => $verdict,
			'rendered'       => (bool) $render['succeeded'],
			'render'         => $render,
			'warnings'       => $verdict['warnings'],
		) );
	}

	/**
	 * GET /websites/{id}/visual/representation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_representation( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$id     = (string) $request->get_param( 'project_id' );
		$page   = (string) $request->get_param( 'page_id' );
		$name   = (string) ( $request->get_param( 'viewport' ) ?? 'desktop' );
		$stored = $this->load_representation( $id, $page, $name );

		if ( null === $stored ) {
			return $this->error( 'not_found', __( 'No visual representation has been built for that page and viewport.', 'replicaforge' ), 404 );
		}

		return $this->ok( array( 'representation' => $stored ) );
	}

	/**
	 * GET /websites/{id}/visual/dynamic.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_dynamic( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$id   = (string) $request->get_param( 'project_id' );
		$page = (string) $request->get_param( 'page_id' );

		$pages = $this->websites()->pages( $id );
		if ( ! isset( $pages[ $page ] ) ) {
			return $this->error( 'unknown_page', __( 'That page is not in this project.', 'replicaforge' ), 404 );
		}

		$representation = get_post_meta( $page, 'replicaforge_analysis', true );
		if ( ! is_array( $representation ) ) {
			return $this->error( 'not_analyzed', __( 'This page has not been analyzed yet.', 'replicaforge' ), 409 );
		}

		return $this->ok( array( 'dynamic' => $this->dynamics->detect( $representation ) ) );
	}

	/**
	 * POST /websites/{id}/visual/plan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function plan_render( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$user_id = get_current_user_id();
		$id      = (string) $request->get_param( 'project_id' );

		$gate = $this->entitlements->check( 'generate', $user_id );
		if ( empty( $gate['allowed'] ) ) {
			return $this->error( 'limit_reached', (string) ( $gate['message'] ?? '' ), (int) ( $gate['status'] ?? 429 ) );
		}

		$pages = $this->websites()->selected( $id );
		if ( array() === $pages ) {
			return $this->error( 'no_pages', __( 'Select at least one page before planning a render.', 'replicaforge' ), 400 );
		}

		$job = $this->jobs->build( array_merge(
			(array) $request->get_param( 'options' ),
			array( 'project_id' => $id, 'pages' => $pages )
		) );

		return $this->ok( array(
			'job'      => $job,
			'renderer' => $this->renderers->capabilities(),
			'note'     => __( 'This is a plan. It runs on the Phase 11 queue and nothing is captured until the job is admitted.', 'replicaforge' ),
		) );
	}

	/**
	 * POST /websites/{id}/visual/compare.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function compare( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$id     = (string) $request->get_param( 'project_id' );
		$name   = (string) ( $request->get_param( 'viewport' ) ?? 'desktop' );
		$source = (string) $request->get_param( 'source_capture' );
		$target = (string) $request->get_param( 'replica_capture' );

		// Captures are addressed by opaque cache key, and a key that is not in *this
		// project's* cache is refused. That is what stops a caller reading a capture
		// belonging to another project.
		$source_bytes = $this->capture_bytes( $id, $source );
		$target_bytes = $this->capture_bytes( $id, $target );

		if ( null === $source_bytes || null === $target_bytes ) {
			return $this->error( 'unknown_capture', __( 'One of those captures is not stored for this project.', 'replicaforge' ), 404 );
		}

		$profile = $this->viewports->profile( $name );
		$report  = $this->comparator->compare( array(
			'source_image'          => $source_bytes,
			'replica_image'         => $target_bytes,
			'viewport'              => $profile ?: array( 'name' => $name ),
			'masks'                 => (array) $request->get_param( 'masks' ),
			'regions'               => (array) $request->get_param( 'regions' ),
			'geometry'              => (array) $request->get_param( 'geometry' ),
			'source_elements'       => (array) $request->get_param( 'source_elements' ),
			'replica_elements'      => (array) $request->get_param( 'replica_elements' ),
			'source_palette'        => (array) $request->get_param( 'source_palette' ),
			'replica_palette'       => (array) $request->get_param( 'replica_palette' ),
			'source_typography'     => (array) $request->get_param( 'source_typography' ),
			'replica_typography'    => (array) $request->get_param( 'replica_typography' ),
			'animation_normalized'  => ! empty( $request->get_param( 'animation_normalized' ) ),
		) );

		$report['page_id'] = (string) $request->get_param( 'page_id' );
		$this->store_report( $id, $report );

		return $this->ok( array( 'report' => $report ) );
	}

	/**
	 * POST /websites/{id}/visual/corrections.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function plan_corrections( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$id     = (string) $request->get_param( 'project_id' );
		$before = (array) $request->get_param( 'before' );
		$after  = (array) $request->get_param( 'after' );
		$report = (array) $request->get_param( 'report' );

		$plan = $this->corrector->plan( $report );

		$out = array( 'plan' => $plan );
		if ( array() !== $before && array() !== $after ) {
			$out['regression']  = $this->corrector->regression_check( $before, $after );
			$out['before_after'] = $this->corrector->before_after( $before, $after );
		}

		$requested = (int) $request->get_param( 'iterations' );
		$out['iteration_budget'] = array(
			'requested' => $requested,
			'allowed'   => Visual_Limits::MAX_CORRECTION_ITERATIONS,
			'clamped'   => ( $requested > Visual_Limits::MAX_CORRECTION_ITERATIONS ),
		);

		$this->logger->info(
			'visual_corrections_planned',
			'Planned visual corrections for a project.',
			array( 'project' => $id, 'eligible' => $plan['counts']['eligible'], 'refused' => $plan['counts']['refused'] ),
			'visual'
		);

		return $this->ok( $out );
	}

	/**
	 * GET /websites/{id}/visual/dashboard.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_dashboard( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$id      = (string) $request->get_param( 'project_id' );
		$reports = $this->load_reports( $id );

		return $this->ok( array( 'dashboard' => $this->jobs->dashboard( $reports ) ) );
	}

	/**
	 * GET /websites/{id}/visual/consistency.
	 *
	 * §69: compare a shared component across pages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_consistency( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$id   = (string) $request->get_param( 'project_id' );
		$spec = $this->websites()->specification( $id );
		if ( array() === $spec ) {
			return $this->error( 'no_specification', __( 'Analyze the selected pages first.', 'replicaforge' ), 400 );
		}

		$components = (array) ( $spec['shared_components'] ?? array() );
		$out        = array();
		$inconsistent = 0;

		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || empty( $component['component_id'] ) ) {
				continue;
			}
			$id_component = (string) $component['component_id'];

			$observed = array();
			$missing  = array();
			foreach ( (array) ( $component['pages'] ?? array() ) as $page_id ) {
				$page_id = (string) $page_id;
				$stored  = $this->load_representation( $id, $page_id, (string) $request->get_param( 'viewport' ) );
				if ( null === $stored ) {
					$missing[] = $page_id;
					continue;
				}
				$component_record = $this->component_of( $stored, $id_component );
				if ( null === $component_record ) {
					$missing[] = $page_id;
					continue;
				}
				$observed[ $page_id ] = $this->component_signature( $component_record );
			}

			$variants = array_count_values( $observed );
			$inconsistent += max( 0, count( $variants ) - 1 );

			$out[] = array(
				'component_id' => $id_component,
				'role'         => (string) ( $component['role'] ?? '' ),
				'pages'        => array_keys( $observed ),
				'not_analysed' => $missing,
				'variants'     => count( $variants ),
				'consistent'   => ( count( $variants ) <= 1 ),
				'note'         => ( count( $variants ) > 1 )
					? __( 'This shared component was analysed differently on different pages.', 'replicaforge' )
					: ( array() === $missing ? '' : __( 'Not every page using this component has a visual representation yet, so consistency could not be established for all of them.', 'replicaforge' ) ),
			);
		}

		return $this->ok( array(
			'components'   => $out,
			'inconsistent' => $inconsistent,
			'total'        => count( $out ),
			'note'         => __( 'A page with no visual representation is reported as unanalysed rather than assumed consistent.', 'replicaforge' ),
		) );
	}

	/**
	 * GET /visual/captures/{id}.
	 *
	 * Returns the bytes and the facts about them. Never a path, never a filesystem
	 * location, and never a capture belonging to another project.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_capture( $request ) {
		$user_id    = get_current_user_id();
		$capture_id = (string) $request->get_param( 'capture_id' );
		$project_id = (string) $request->get_param( 'project_id' );

		// A capture is addressed by its own opaque key, so the project is a
		// *claim* about ownership. The key itself carries the project, and the
		// comparison is the check: a caller that guesses a key from another project
		// gets nothing, and guessing is not possible because keys are hashes of
		// project, source, viewport, and version.
		$entry = $this->cache->get( $capture_id );
		if ( null === $entry || ( '' !== $project_id && (string) ( $entry['project_id'] ?? '' ) !== $project_id ) ) {
			return $this->error( 'unknown_capture', __( 'That capture is not available.', 'replicaforge' ), 404 );
		}

		if ( $this->access->can_read( $user_id, (string) ( $entry['project_id'] ?? '' ) ) === false ) {
			return $this->error( 'not_permitted', __( 'That capture belongs to another project.', 'replicaforge' ), 403 );
		}

		$bytes = (string) $entry['body'];

		return new \WP_REST_Response( $bytes, 200, array(
			'Content-Type'        => 'image/png',
			'Content-Length'      => (string) strlen( $bytes ),
			'Content-Disposition' => 'inline; filename="capture.png"',
			// A screenshot rendered from a third party's page must not be indexed,
			// framed, or treated as same-origin content by anything.
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control'       => 'private, max-age=0, no-store',
		) );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve ownership of a project route.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function owned( $request ) {
		$user_id    = get_current_user_id();
		$project_id = (string) $request->get_param( 'project_id' );

		$project = $this->access->readable_project( $user_id, $project_id );
		if ( ! $project ) {
			// One message for both cases, so a project id cannot be probed.
			return array( 'error' => $this->error( 'project_not_found', __( 'That project could not be found, or it belongs to another account.', 'replicaforge' ), 404 ) );
		}
		return array( 'project' => $project );
	}

	/**
	 * Return the website store, shared with the analyzer.
	 *
	 * @return Website_Repository
	 */
	private function websites() {
		static $store = null;
		if ( $store instanceof Website_Repository ) {
			return $store;
		}
		$store = new Website_Repository( $this->logger );
		return $store;
	}

	/**
	 * Capture a page, or explain why it could not be captured.
	 *
	 * @param array<string, mixed> $page    Page record.
	 * @param array<string, mixed> $profile Viewport profile.
	 * @return array<string, mixed>
	 */
	private function capture_page( array $page, array $profile ) {
		$url = (string) ( $page['source_url'] ?? '' );
		if ( '' === $url ) {
			return array( 'succeeded' => false, 'reason' => 'no_url' );
		}

		$job      = $this->jobs->build( array( 'pages' => array( $page ) ) );
		$stabilize = (array) ( $job['payload']['stabilize'] ?? array() );

		$result = $this->renderers->capture( array(
			'url'        => $url,
			'viewport'   => $profile,
			'full_page'  => ! empty( $job['payload']['full_page'] ),
			'stabilize'  => $stabilize,
		) );

		if ( empty( $result['success'] ) ) {
			return array( 'succeeded' => false, 'reason' => (string) ( $result['error']['code'] ?? 'render_failed' ), 'message' => (string) ( $result['error']['message'] ?? '' ) );
		}

		$key = $this->cache->key( (string) ( $job['payload']['project_id'] ?? '' ), $url, $this->cache->parts_from( array(
			'viewport'  => $profile,
			'source_hash'=> (string) ( $page['source_hash'] ?? '' ),
			'kind'       => 'screenshot',
		) ) );
		$this->cache->put( $key, (string) $result['image'], 'screenshot', 0, (string) ( $job['payload']['project_id'] ?? '' ) );

		return array(
			'succeeded'  => true,
			'bytes'      => (int) ( $result['bytes'] ?? 0 ),
			'viewport'   => (array) ( $result['viewport'] ?? array() ),
			'boxes'      => array(),
			'capture_id' => $key,
		);
	}

	/**
	 * Return the stored bytes for a capture, or null.
	 *
	 * @param string $project_id Project id.
	 * @param string $capture_id Capture id.
	 * @return string|null
	 */
	private function capture_bytes( $project_id, $capture_id ) {
		if ( '' === (string) $capture_id ) {
			return null;
		}
		$entry = $this->cache->get( (string) $capture_id );
		if ( null === $entry || (string) ( $entry['project_id'] ?? '' ) !== (string) $project_id ) {
			return null;
		}
		$body = $entry['body'];
		return is_string( $body ) ? $body : null;
	}

	/**
	 * Return a component from a stored representation.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @param string               $component_id   Component id.
	 * @return array<string, mixed>|null
	 */
	private function component_of( array $representation, $component_id ) {
		foreach ( (array) ( $representation['components'] ?? array() ) as $component ) {
			if ( is_array( $component ) && (string) ( $component['component_id'] ?? '' ) === $component_id ) {
				return $component;
			}
		}
		return null;
	}

	/**
	 * Return a comparable signature for a component.
	 *
	 * Only the properties that should be *identical* across pages for a shared
	 * component. Geometry is deliberately excluded: two pages are different lengths,
	 * so a hero's y-position legitimately differs, and including it would report every
	 * shared component as inconsistent.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return string
	 */
	private function component_signature( array $component ) {
		$parts = array(
			'type'        => (string) ( $component['type'] ?? '' ),
			'background'  => (string) wp_json_encode( $component['background'] ?? array() ),
			'typography'  => (string) wp_json_encode( $component['typography'] ?? array() ),
			'shadow'      => (string) wp_json_encode( $component['shadow'] ?? array() ),
			'border'      => (string) wp_json_encode( $component['border'] ?? array() ),
			'radius'      => (string) wp_json_encode( $component['radius'] ?? array() ),
		);
		return substr( hash( 'sha256', (string) wp_json_encode( $parts ) ), 0, 16 );
	}

	/**
	 * Store a representation.
	 *
	 * Its own option, not the Phase 12 website store. A visual representation is a
	 * Phase 13 artifact, it is written and read many times per render, and putting it
	 * in the page store would mean rewriting the whole page list on every analysis.
	 * The first draft did that, and also re-saved the specification afterwards —
	 * which would have clobbered a Phase 12 specification with an empty array the
	 * moment two analyses ran in a row.
	 *
	 * @param string               $project_id Project id.
	 * @param string               $page_id    Page id.
	 * @param string               $viewport   Viewport name.
	 * @param array<string, mixed> $spec       Specification.
	 * @return bool
	 */
	private function store_representation( $project_id, $page_id, $viewport, array $spec ) {
		$option = $this->representation_option( $project_id );
		$all    = get_option( $option, array() );
		$all    = is_array( $all ) ? $all : array();

		$all[ (string) $page_id . '::' . (string) $viewport ] = $spec;

		// Bounded. A project with 100 pages at 3 viewports is 300 entries; past that
		// a representation is stale anyway, because the source has moved on.
		if ( count( $all ) > 400 ) {
			$all = array_slice( $all, -400, null, true );
		}

		update_option( $option, $all, false );
		return true;
	}

	/**
	 * Load a stored representation.
	 *
	 * @param string $project_id Project id.
	 * @param string $page_id    Page id.
	 * @param string $viewport   Viewport name.
	 * @return array<string, mixed>|null
	 */
	private function load_representation( $project_id, $page_id, $viewport ) {
		$all = get_option( $this->representation_option( $project_id ), array() );
		$all = is_array( $all ) ? $all : array();
		$key = (string) $page_id . '::' . (string) $viewport;
		return isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Return the option name holding a project's representations.
	 *
	 * @param string $project_id Project id.
	 * @return string
	 */
	private function representation_option( $project_id ) {
		return 'replicaforge_visual_repr_' . substr( hash( 'sha256', (string) $project_id ), 0, 16 );
	}

	/**
	 * Store a comparison report.
	 *
	 * @param string               $project_id Project id.
	 * @param array<string, mixed> $report     Report.
	 * @return void
	 */
	private function store_report( $project_id, array $report ) {
		$key = 'replicaforge_visual_reports_' . substr( hash( 'sha256', (string) $project_id ), 0, 16 );
		$all = get_option( $key, array() );
		$all = is_array( $all ) ? $all : array();
		$all[] = $report;
		if ( count( $all ) > 40 ) {
			$all = array_slice( $all, -40 );
		}
		update_option( $key, $all, false );
	}

	/**
	 * Load a project's reports.
	 *
	 * @param string $project_id Project id.
	 * @return array<int, array<string, mixed>>
	 */
	private function load_reports( $project_id ) {
		$key = 'replicaforge_visual_reports_' . substr( hash( 'sha256', (string) $project_id ), 0, 16 );
		$all = get_option( $key, array() );
		return is_array( $all ) ? array_values( $all ) : array();
	}

	/**
	 * Permission callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_use( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'authentication_required', __( 'Sign in to use ReplicaForge.', 'replicaforge' ), array( 'status' => 401 ) );
		}
		if ( ! Capabilities::current_user_can( 'replicaforge_use' ) ) {
			return new \WP_Error( 'capability_missing', __( 'Your account is not allowed to use ReplicaForge.', 'replicaforge' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission callback for settings changes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_manage( $request ) {
		$read = $this->can_use( $request );
		if ( true !== $read ) {
			return $read;
		}
		if ( ! Capabilities::current_user_can( 'replicaforge_manage_plans' ) ) {
			return new \WP_Error( 'capability_missing', __( 'You are not allowed to change ReplicaForge settings.', 'replicaforge' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Build a success response.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return \WP_REST_Response
	 */
	private function ok( array $data ) {
		return new \WP_REST_Response(
			array( 'success' => true, 'data' => $data, 'meta' => array( 'request_id' => Request_Context::request_id() ) ),
			200
		);
	}

	/**
	 * Build a failure response.
	 *
	 * @param string               $code    Stable code.
	 * @param string               $message Message.
	 * @param int                  $status  Status.
	 * @param array<string, mixed> $details Details.
	 * @return \WP_REST_Response
	 */
	private function error( $code, $message, $status = 400, array $details = array() ) {
		$status = (int) $status;
		if ( $status < 400 || $status > 499 ) {
			$status = 500;
		}
		if ( $status >= 500 ) {
			$this->logger->error( 'visual_api_error', 'A visual request failed server-side.', array( 'code' => sanitize_key( (string) $code ) ), 'visual' );
			$message = __( 'The request could not be completed.', 'replicaforge' );
			$details = array();
		}
		return new \WP_REST_Response(
			array( 'success' => false, 'error' => array( 'code' => sanitize_key( (string) $code ), 'message' => (string) $message, 'details' => $details ), 'meta' => array( 'request_id' => Request_Context::request_id() ) ),
			$status
		);
	}

	/**
	 * Register one route.
	 *
	 * @param string $path       Path.
	 * @param string $method     Method.
	 * @param string $callback   Callback.
	 * @param string $permission Permission.
	 * @return void
	 */
	private function route( $path, $method, $callback, $permission ) {
		register_rest_route(
			self::NAMESPACE_V1,
			$path,
			array( 'methods' => $method, 'callback' => array( $this, $callback ), 'permission_callback' => array( $this, $permission ) )
		);
	}
}
