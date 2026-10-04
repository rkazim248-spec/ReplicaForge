<?php
/**
 * WordPress REST API for ReplicaForge.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and serves every ReplicaForge route.
 *
 * Every route reuses the same capability check, every request is validated as
 * untrusted input before it is used, and no route accepts Elementor document data
 * from a browser. Phase 3 plans, Phase 4 documents, Phase 5 reports, and Phase 6
 * corrections are all produced on the server from validated representations.
 */
final class Rest_Api {

	/**
	 * Analyzer service.
	 *
	 * @var Analyzer
	 */
	private $analyzer;

	/**
	 * Optional AI manager.
	 *
	 * @var Ai_Manager
	 */
	private $ai_manager;

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
	 * Optional Phase 5 cache.
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
	 * Constructor.
	 *
	 * @param Analyzer|null            $analyzer            Analyzer service.
	 * @param Ai_Manager|null          $ai_manager          Optional AI manager.
	 * @param Elementor_Generator|null $elementor_generator Optional Phase 4 generator.
	 * @param Validation_Engine|null   $validation_engine   Optional Phase 5 engine.
	 * @param Validation_Report|null   $validation_report   Optional Phase 5 report.
	 * @param Validation_Cache|null    $validation_cache    Optional Phase 5 cache.
	 * @param Visual_Renderer|null     $visual_renderer     Optional Phase 5 renderer.
	 * @param Correction_Engine|null   $correction_engine   Optional Phase 6 engine.
	 * @param Correction_Report|null   $correction_report   Optional Phase 6 report.
	 */
	public function __construct( $analyzer = null, $ai_manager = null, $elementor_generator = null, $validation_engine = null, $validation_report = null, $validation_cache = null, $visual_renderer = null, $correction_engine = null, $correction_report = null ) {
		$this->analyzer            = $analyzer instanceof Analyzer ? $analyzer : new Analyzer();
		$this->ai_manager          = $ai_manager instanceof Ai_Manager ? $ai_manager : new Ai_Manager();
		$this->elementor_generator = $elementor_generator instanceof Elementor_Generator ? $elementor_generator : new Elementor_Generator();
		$this->validation_engine   = $validation_engine instanceof Validation_Engine ? $validation_engine : new Validation_Engine();
		$this->validation_report   = $validation_report instanceof Validation_Report ? $validation_report : new Validation_Report();
		$this->validation_cache    = $validation_cache instanceof Validation_Cache ? $validation_cache : new Validation_Cache();
		$this->visual_renderer     = $visual_renderer instanceof Visual_Renderer ? $visual_renderer : new Visual_Renderer();
		$this->correction_engine   = $correction_engine instanceof Correction_Engine ? $correction_engine : new Correction_Engine();
		$this->correction_report   = $correction_report instanceof Correction_Report ? $correction_report : new Correction_Report();
	}

	/**
	 * Register every ReplicaForge route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'replicaforge/v1',
			'/analyze',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'analyze' ),
				'permission_callback' => array( $this, 'can_analyze' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'maxLength'         => 2048,
						'description'       => __( 'Public http:// or https:// frontend page to analyze.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_url_param' ),
						'validate_callback' => array( $this, 'validate_url_param' ),
					),
				),
			)
		);

		register_rest_route(
			'replicaforge/v1',
			'/ai/analyze',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_analyze' ),
				'permission_callback' => array( $this, 'can_analyze' ),
				'args'                => array(
					'design_representation' => array(
						'required'          => true,
						'type'              => 'object',
						'description'       => __( 'Validated Phase 2 Design Representation 2.0 produced by the analyze endpoint.', 'replicaforge' ),
						'validate_callback' => array( $this, 'validate_representation_param' ),
					),
					'source_url'           => array(
						'required'          => false,
						'type'              => 'string',
						'maxLength'         => 2048,
						'description'       => __( 'Public URL the representation came from.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_url_param' ),
						'validate_callback' => array( $this, 'validate_url_param' ),
					),
					'mode'                => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'plan',
						'enum'              => array( 'plan' ),
						'description'       => __( 'Phase 3 planning mode.', 'replicaforge' ),
					),
				),
			)
		);

		$this->register_generate_route();
		$this->register_validation_routes();
		$this->register_correction_routes();
	}

	/**
	 * Register the Phase 5 validation routes.
	 *
	 * @return void
	 */
	private function register_validation_routes() {
		register_rest_route(
			'replicaforge/v1',
			'/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_draft' ),
				'permission_callback' => array( $this, 'can_analyze' ),
				'args'                => array(
					'design_representation' => array(
						'required'          => true,
						'type'              => 'object',
						'description'       => __( 'Validated Phase 2 Design Representation 2.0 produced by the analyze endpoint.', 'replicaforge' ),
						'validate_callback' => array( $this, 'validate_representation_param' ),
					),
					'draft_id'            => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'description'       => __( 'Identifier of the generated draft to validate.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_post_id_param' ),
						'validate_callback' => array( $this, 'validate_post_id_param' ),
					),
					'visual'              => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'description'       => __( 'Request an optional rendered comparison. Requires a configured render provider.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
						'validate_callback' => array( $this, 'validate_boolean_param' ),
					),
					'ai'                  => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'description'       => __( 'Request an optional AI explanation of the already-detected differences.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
						'validate_callback' => array( $this, 'validate_boolean_param' ),
					),
					'force'               => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'description'       => __( 'Ignore a cached validation result and compare again.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
						'validate_callback' => array( $this, 'validate_boolean_param' ),
					),
					'viewports'           => array(
						'required'          => false,
						'type'              => 'object',
						'description'       => __( 'Optional viewport overrides for the rendered comparison.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_viewports_param' ),
						'validate_callback' => array( $this, 'validate_viewports_param' ),
					),
				),
			)
		);

		register_rest_route(
			'replicaforge/v1',
			'/validate/(?P<validation_id>[A-Za-z0-9_]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_validation' ),
				'permission_callback' => array( $this, 'can_analyze' ),
				'args'                => array(
					'validation_id' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Validation identifier returned by a previous run.', 'replicaforge' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_validation_id_param' ),
					),
				),
			)
		);

		register_rest_route(
			'replicaforge/v1',
			'/validate/(?P<validation_id>[A-Za-z0-9_]+)/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_validation' ),
				'permission_callback' => array( $this, 'can_analyze' ),
				'args'                => array(
					'validation_id' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Validation identifier returned by a previous run.', 'replicaforge' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_validation_id_param' ),
					),
					'format'         => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'json',
						'enum'              => array( 'json', 'csv' ),
						'description'       => __( 'Export format.', 'replicaforge' ),
					),
				),
			)
		);
	}

	/**
	 * Register the user-triggered Phase 4 Elementor generation route.
	 *
	 * The browser never submits an Elementor document. It submits a Phase 2
	 * representation and, optionally, a Phase 3 reconstruction specification
	 * that the server re-validates against the source analysis.
	 *
	 * @return void
	 */
	private function register_generate_route() {
		register_rest_route(
			'replicaforge/v1',
			'/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => array( $this, 'can_analyze' ),
				'args'                => array(
					'design_representation'        => array(
						'required'          => true,
						'type'              => 'object',
						'description'       => __( 'Validated Phase 2 Design Representation 2.0 produced by the analyze endpoint.', 'replicaforge' ),
						'validate_callback' => array( $this, 'validate_representation_param' ),
					),
					'reconstruction_specification' => array(
						'required'          => false,
						'type'              => 'object',
						'description'       => __( 'Optional Phase 3 Reconstruction Specification 3.0. It is re-validated against the Phase 2 representation on the server.', 'replicaforge' ),
						'validate_callback' => array( $this, 'validate_specification_param' ),
					),
					'specification_id'    => array(
						'required'          => false,
						'type'              => 'string',
						'description'       => __( 'Optional identifier of a specification previously stored by the server.', 'replicaforge' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_specification_id_param' ),
					),
					'source_url'          => array(
						'required'          => false,
						'type'              => 'string',
						'description'       => __( 'Optional public source URL used for provenance and validation.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_url_param' ),
						'validate_callback' => array( $this, 'validate_url_param' ),
					),
					'import_assets'       => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'description'       => __( 'Copy detected images into the media library. Off by default because source images are not automatically licensed for reuse.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
						'validate_callback' => array( $this, 'validate_boolean_param' ),
					),
					'mode'                => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'generate',
						'enum'              => array( 'preview', 'generate' ),
						'description'       => __( 'Preview describes what would be generated without creating a draft.', 'replicaforge' ),
					),
				),
			)
		);
	}

	/**
	 * Register the Phase 6 correction routes.
	 *
	 * Every route reuses the same capability check as the other ReplicaForge
	 * routes. A correction request carries only a plan identifier and the list
	 * of correction identifiers the user selected, never Elementor data, so a
	 * browser cannot submit arbitrary document changes.
	 *
	 * @return void
	 */
	private function register_correction_routes() {
		register_rest_route(
			'replicaforge/v1',
			'/corrections/plan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'plan_corrections' ),
				'permission_callback' => array( $this, 'can_analyze' ),
				'args'                => array(
					'validation_id'        => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Validation identifier from a Phase 5 run.', 'replicaforge' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_validation_id_param' ),
					),
					'draft_id'            => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'description'       => __( 'Generated draft to plan corrections for.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_post_id_param' ),
						'validate_callback' => array( $this, 'validate_post_id_param' ),
					),
					'design_representation' => array(
						'required'          => false,
						'type'              => 'object',
						'description'       => __( 'Optional Phase 2 representation, used only to re-measure the result after a correction.', 'replicaforge' ),
						'validate_callback' => array( $this, 'validate_optional_representation_param' ),
					),
					'ai'                  => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'description'       => __( 'Request an optional AI ordering pass over the measured corrections.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
						'validate_callback' => array( $this, 'validate_boolean_param' ),
					),
				),
			)
		);

		register_rest_route(
			'replicaforge/v1',
			'/corrections/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_corrections' ),
				'permission_callback' => array( $this, 'can_analyze' ),
				'args'                => array(
					'plan_id'               => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Correction plan identifier returned by the plan route.', 'replicaforge' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_plan_id_param' ),
					),
					'selected'              => array(
						'required'          => true,
						'type'              => 'array',
						'items'             => array( 'type' => 'string' ),
						'maxItems'          => Correction_Limits::MAX_APPLY,
						'description'       => __( 'Correction identifiers the user explicitly approved.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_selected_param' ),
						'validate_callback' => array( $this, 'validate_selected_param' ),
					),
					'approved_structural'   => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'description'       => __( 'Confirm that structural corrections such as inserting, removing, or reordering sections were explicitly approved.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
						'validate_callback' => array( $this, 'validate_boolean_param' ),
					),
					'revalidate'            => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => true,
						'description'       => __( 'Run Phase 5 again after the correction to measure the change and detect regressions.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_boolean_param' ),
						'validate_callback' => array( $this, 'validate_boolean_param' ),
					),
					'iterations'            => array(
						'required'          => false,
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => Correction_Limits::MAX_ITERATIONS,
						'default'           => 1,
						'description'       => __( 'Maximum correction iterations for this request.', 'replicaforge' ),
						'sanitize_callback' => 'absint',
					),
					'design_representation' => array(
						'required'          => false,
						'type'              => 'object',
						'description'       => __( 'Optional Phase 2 representation, used to re-validate after the correction.', 'replicaforge' ),
						'validate_callback' => array( $this, 'validate_optional_representation_param' ),
					),
				),
			)
		);

		register_rest_route(
			'replicaforge/v1',
			'/corrections/rollback',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rollback_corrections' ),
				'permission_callback' => array( $this, 'can_analyze' ),
				'args'                => array(
					'draft_id'       => array(
						'required'     => true,
						'type'         => 'integer',
						'minimum'      => 1,
						'description'  => __( 'Draft to restore.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_post_id_param' ),
						'validate_callback' => array( $this, 'validate_post_id_param' ),
					),
					'correction_id'  => array(
						'required'     => true,
						'type'         => 'string',
						'description'  => __( 'Correction run to roll back.', 'replicaforge' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_correction_id_param' ),
					),
				),
			)
		);

		register_rest_route(
			'replicaforge/v1',
			'/corrections/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'correction_history' ),
				'permission_callback' => array( $this, 'can_analyze' ),
				'args'                => array(
					'draft_id' => array(
						'required'    => true,
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Draft whose correction history is requested.', 'replicaforge' ),
						'sanitize_callback' => array( $this, 'sanitize_post_id_param' ),
						'validate_callback' => array( $this, 'validate_post_id_param' ),
					),
				),
			)
		);

		register_rest_route(
			'replicaforge/v1',
			'/corrections/export',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'export_corrections' ),
				'permission_callback' => array( $this, 'can_analyze' ),
				'args'                => array(
					'kind'          => array(
						'required'    => true,
						'type'        => 'string',
						'enum'        => array( 'plan', 'run' ),
						'description' => __( 'Which correction artifact to export.', 'replicaforge' ),
					),
					'format'        => array(
						'required'    => false,
						'type'        => 'string',
						'default'     => 'json',
						'enum'        => array( 'json', 'csv' ),
						'description' => __( 'Export format.', 'replicaforge' ),
					),
					'plan_id'       => array(
						'required'    => false,
						'type'        => 'string',
						'description' => __( 'Correction plan identifier, for a plan export.', 'replicaforge' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_plan_id_param' ),
					),
					'correction_id' => array(
						'required'    => false,
						'type'        => 'string',
						'description' => __( 'Correction run identifier, for a run export.', 'replicaforge' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_correction_id_param' ),
					),
					'draft_id'      => array(
						'required'    => false,
						'type'        => 'integer',
						'minimum'     => 1,
						'sanitize_callback' => array( $this, 'sanitize_post_id_param' ),
						'validate_callback' => array( $this, 'validate_post_id_param' ),
						'description' => __( 'Draft the correction belongs to.', 'replicaforge' ),
					),
				),
			)
		);
	}

	/**
	 * Require an authenticated administrator, the correct capability, and a
	 * valid REST nonce. The route is protected independently of the admin UI.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public function can_analyze( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'replicaforge_authentication_required',
				__( 'You must be signed in to analyze a website.', 'replicaforge' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'replicaforge_forbidden',
				__( 'You do not have permission to analyze websites.', 'replicaforge' ),
				array( 'status' => 403 )
			);
		}

		$nonce = $request instanceof \WP_REST_Request ? $request->get_header( 'x_wp_nonce' ) : '';
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			$nonce = $request instanceof \WP_REST_Request ? $request->get_header( 'X-WP-Nonce' ) : '';
		}
		if ( ( ! is_string( $nonce ) || '' === $nonce ) && $request instanceof \WP_REST_Request ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'replicaforge_invalid_nonce',
				__( 'Your session could not be verified. Please reload the page and try again.', 'replicaforge' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Analyze one public frontend page.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function analyze( $request ) {
		try {
			$params = $request->get_json_params();
			if ( ! is_array( $params ) || empty( $params ) ) {
				$params = $request->get_body_params();
			}

			$url = isset( $params['url'] ) ? $this->sanitize_url_param( $params['url'] ) : '';
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				return $this->error_response( 'invalid_url', 'Invalid URL.', 400 );
			}

			$analysis = $this->analyzer->analyze_url( $url );
			if ( ! is_array( $analysis ) || empty( $analysis['success'] ) ) {
				$error = isset( $analysis['error'] ) && is_array( $analysis['error'] ) ? $analysis['error'] : array();
				return $this->error_response(
					isset( $error['code'] ) ? $error['code'] : 'analysis_failed',
					isset( $error['message'] ) ? $error['message'] : 'The website returned an unexpected response.',
					isset( $error['status'] ) ? $error['status'] : 500
				);
			}

			return new \WP_REST_Response(
				array(
					'success' => true,
					'data'    => isset( $analysis['data'] ) && is_array( $analysis['data'] ) ? $analysis['data'] : $analysis,
				),
				200
			);
		} catch ( \Throwable $exception ) {
			Security::log_event( 'rest_analysis_failed', array( 'reason' => 'exception' ) );
			return $this->error_response( 'analysis_failed', 'The website returned an unexpected response.', 500 );
		}
	}

	/**
	 * Run the optional Phase 3 AI analysis for a previously returned Phase 2
	 * representation. The target page is not fetched again and no API key is
	 * accepted from the browser.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function ai_analyze( $request ) {
		try {
			$params = $request->get_json_params();
			if ( ! is_array( $params ) || empty( $params ) ) {
				$params = $request->get_body_params();
			}
			$representation = isset( $params['design_representation'] ) ? $params['design_representation'] : null;
			if ( ! is_array( $representation ) || ! $this->validate_representation_param( $representation ) ) {
				return $this->error_response( 'invalid_design_representation', 'A valid Design Representation 2.0 is required.', 400 );
			}
			$source_url = isset( $params['source_url'] ) ? $this->sanitize_url_param( $params['source_url'] ) : '';
			if ( ! is_string( $source_url ) || strlen( $source_url ) > 2048 ) {
				return $this->error_response( 'invalid_source_url', 'Invalid source URL.', 400 );
			}
			$result = $this->ai_manager->analyze( $representation, $source_url );
			if ( ! is_array( $result ) || empty( $result['success'] ) ) {
				$error = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : array();
				return $this->error_response(
					isset( $error['code'] ) ? $error['code'] : 'ai_analysis_failed',
					isset( $error['message'] ) ? $error['message'] : 'AI analysis could not be completed.',
					isset( $error['status'] ) ? $error['status'] : 500
				);
			}
			return new \WP_REST_Response(
				array(
					'success'  => true,
					'ai_used'   => ! empty( $result['ai_used'] ),
					'fallback'  => ! empty( $result['fallback'] ),
					'cached'    => ! empty( $result['cached'] ),
					'provider'  => isset( $result['provider'] ) ? sanitize_key( $result['provider'] ) : 'none',
					'message'   => isset( $result['message'] ) ? (string) $result['message'] : '',
					'data'      => isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array(),
				),
				200
			);
		} catch ( \Throwable $exception ) {
			Security::log_event( 'rest_ai_analysis_failed', array( 'reason' => 'exception' ) );
			return $this->error_response( 'ai_analysis_failed', 'AI analysis could not be completed.', 500 );
		}
	}

	/**
	 * Generate an editable Elementor draft from a validated specification.
	 *
	 * The request cannot contain an Elementor document. The server resolves and
	 * re-validates the reconstruction specification, generates the document, and
	 * creates a new draft. Nothing is ever published.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function generate( $request ) {
		try {
			$params = $request->get_json_params();
			if ( ! is_array( $params ) || empty( $params ) ) {
				$params = $request->get_body_params();
			}

			$representation = isset( $params['design_representation'] ) ? $params['design_representation'] : null;
			if ( ! is_array( $representation ) || ! $this->validate_representation_param( $representation ) ) {
				return $this->error_response( 'invalid_design_representation', 'A valid Design Representation 2.0 is required.', 400 );
			}

			$specification = isset( $params['reconstruction_specification'] ) && is_array( $params['reconstruction_specification'] )
				? $params['reconstruction_specification']
				: null;
			if ( null !== $specification && ! $this->validate_specification_param( $specification ) ) {
				return $this->error_response( 'invalid_reconstruction_specification', 'The reconstruction specification is malformed.', 400 );
			}

			$source_url = isset( $params['source_url'] ) ? $this->sanitize_url_param( $params['source_url'] ) : '';
			if ( ! is_string( $source_url ) || strlen( $source_url ) > 2048 ) {
				return $this->error_response( 'invalid_source_url', 'Invalid source URL.', 400 );
			}

			$specification_id = isset( $params['specification_id'] ) ? sanitize_text_field( (string) $params['specification_id'] ) : '';
			if ( '' !== $specification_id && ! $this->validate_specification_id_param( $specification_id ) ) {
				return $this->error_response( 'invalid_specification_id', 'Invalid specification reference.', 400 );
			}

			$mode = isset( $params['mode'] ) && 'preview' === $params['mode'] ? 'preview' : 'generate';
			$options = array(
				'source_url'       => $source_url,
				'specification_id' => $specification_id,
				'import_assets'    => ! empty( $params['import_assets'] ) && true === filter_var( $params['import_assets'], FILTER_VALIDATE_BOOLEAN ),
			);

			$result = 'preview' === $mode
				? $this->elementor_generator->preview( $representation, $specification, $options )
				: $this->elementor_generator->generate( $representation, $specification, $options );

			if ( ! is_array( $result ) || empty( $result['success'] ) ) {
				$error = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : array();
				return $this->error_response(
					isset( $error['code'] ) ? $error['code'] : 'generation_failed',
					isset( $error['message'] ) ? $error['message'] : 'The Elementor draft could not be generated.',
					isset( $error['status'] ) ? (int) $error['status'] : 500
				);
			}

			$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();

			return new \WP_REST_Response(
				array(
					'success' => true,
					'data'    => $data,
				),
				200
			);
		} catch ( \Throwable $exception ) {
			Security::log_event( 'generation_failed', array( 'code' => 'rest_exception', 'reason' => 'exception' ) );
			return $this->error_response( 'generation_failed', 'The Elementor draft could not be generated.', 500 );
		}
	}

	/**
	 * Plan corrections for a generated draft.
	 *
	 * The request carries a validation identifier and a draft identifier. The
	 * plan is produced from the stored Phase 5 result, so nothing is measured
	 * twice and no browser-supplied value reaches a document.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function plan_corrections( $request ) {
		try {
			$params         = $this->body( $request );
			$validation_id  = isset( $params['validation_id'] ) ? sanitize_text_field( (string) $params['validation_id'] ) : '';
			$draft_id       = isset( $params['draft_id'] ) ? absint( $params['draft_id'] ) : 0;

			$validation = $this->stored_validation( $validation_id );
			if ( ! is_array( $validation ) ) {
				return $this->error_response( 'validation_not_found', 'The validation result was not found or has expired. Run a validation first.', 404 );
			}
			if ( $draft_id < 1 ) {
				return $this->error_response( 'invalid_draft', 'A draft identifier is required.', 400 );
			}

			$plan = $this->correction_engine->plan(
				$validation,
				$draft_id,
				array(
					'ai' => ! empty( $params['ai'] ),
				)
			);

			if ( empty( $plan['success'] ) ) {
				return $this->error_response( $plan );
			}

			return new \WP_REST_Response(
				array(
					'success' => true,
					'data'    => $plan,
				),
				200
			);
		} catch ( \Throwable $exception ) {
			Security::log_event( 'correction_failed', array( 'code' => 'plan_exception', 'reason' => 'exception' ) );
			return $this->error_response( 'plan_failed', 'The correction plan could not be produced.', 500 );
		}
	}

	/**
	 * Apply a reviewed correction plan.
	 *
	 * Only the identifiers the user selected are applied, and only after the plan
	 * has been re-validated against the current document.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function apply_corrections( $request ) {
		try {
			$params      = $this->body( $request );
			$plan_id     = isset( $params['plan_id'] ) ? sanitize_text_field( (string) $params['plan_id'] ) : '';
			$selected    = isset( $params['selected'] ) && is_array( $params['selected'] ) ? $params['selected'] : array();
			$selected    = array_values(
				array_filter(
					array_map(
						static function ( $value ) {
							return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
						},
						$selected
					)
				)
			);

			if ( '' === $plan_id ) {
				return $this->error_response( 'invalid_plan', 'A correction plan identifier is required.', 400 );
			}
			if ( empty( $selected ) ) {
				return $this->error_response( 'no_selection', 'No corrections were selected, so nothing was changed.', 400 );
			}
			if ( ! $this->valid_selection( $selected ) ) {
				return $this->error_response( 'invalid_selection', 'One or more selected correction identifiers are not valid.', 400 );
			}

			$result = $this->correction_engine->apply(
				$plan_id,
				$selected,
				array(
					'approved_structural'   => ! empty( $params['approved_structural'] ),
					'revalidate'            => ! isset( $params['revalidate'] ) || false !== $params['revalidate'],
					'iterations'            => isset( $params['iterations'] ) ? $params['iterations'] : 1,
					'design_representation' => isset( $params['design_representation'] ) && is_array( $params['design_representation'] ) ? $params['design_representation'] : null,
				)
			);

			if ( empty( $result['success'] ) ) {
				return $this->error_response( $result );
			}

			return new \WP_REST_Response(
				array(
					'success' => true,
					'data'    => $result,
				),
				200
			);
		} catch ( \Throwable $exception ) {
			Security::log_event( 'correction_failed', array( 'code' => 'apply_exception', 'reason' => 'exception' ) );
			return $this->error_response( 'apply_failed', 'The corrections could not be applied. The previous document was preserved.', 500 );
		}
	}

	/**
	 * Roll a correction run back to its snapshot.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function rollback_corrections( $request ) {
		$params = $this->body( $request );
		$result = $this->correction_engine->rollback(
			isset( $params['draft_id'] ) ? absint( $params['draft_id'] ) : 0,
			isset( $params['correction_id'] ) ? sanitize_text_field( (string) $params['correction_id'] ) : ''
		);

		if ( empty( $result['success'] ) ) {
			return $this->error_response( $result );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Return the correction history of a draft.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function correction_history( $request ) {
		$draft_id = isset( $request['draft_id'] ) ? absint( $request['draft_id'] ) : 0;
		if ( $draft_id < 1 || ! current_user_can( 'edit_post', $draft_id ) ) {
			return $this->error_response( 'replicaforge_forbidden', 'You do not have permission to inspect this draft.', 403 );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'post_id'   => $draft_id,
					'corrections' => $this->correction_engine->history( $draft_id, 10 ),
				),
			),
			200
		);
	}

	/**
	 * Export a correction plan or a correction run.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function export_corrections( $request ) {
		$params = $this->body( $request );
		$kind   = isset( $params['kind'] ) && 'run' === $params['kind'] ? 'run' : 'plan';
		$format = isset( $params['format'] ) && 'csv' === $params['format'] ? 'csv' : 'json';
		$draft_id = isset( $params['draft_id'] ) ? absint( $params['draft_id'] ) : 0;

		if ( $draft_id < 1 || ! current_user_can( 'edit_post', $draft_id ) ) {
			return $this->error_response( 'replicaforge_forbidden', 'You do not have permission to export corrections for this draft.', 403 );
		}

		if ( 'run' === $kind ) {
			$report = ( new Correction_History() )->get( isset( $params['correction_id'] ) ? sanitize_text_field( (string) $params['correction_id'] ) : '' );
			if ( ! is_array( $report ) ) {
				return $this->error_response( 'correction_not_found', 'That correction run could not be found.', 404 );
			}
			$filename = 'replicaforge-correction-' . $report['correction_id'];
			if ( 'csv' === $format ) {
				return new \WP_REST_Response(
					array(
						'success'  => true,
						'filename' => $filename . '.csv',
						'format'   => 'csv',
						'content'  => $this->correction_run_csv( $report ),
					),
					200
				);
			}
			return new \WP_REST_Response(
				array(
					'success'  => true,
					'filename' => $filename . '.json',
					'format'   => 'json',
					'data'     => $this->correction_report->export_run_json( $report, $draft_id ),
				),
				200
			);
		}

		$planner = new Correction_Planner();
		$plan    = $planner->load( isset( $params['plan_id'] ) ? sanitize_text_field( (string) $params['plan_id'] ) : '' );
		if ( null === $plan ) {
			return $this->error_response( 'plan_not_found', 'That correction plan is no longer available.', 404 );
		}
		$report  = $this->correction_report->plan( $plan, $draft_id );
		$filename = 'replicaforge-correction-plan-' . $plan['plan_id'];

		if ( 'csv' === $format ) {
			return new \WP_REST_Response(
				array(
					'success'  => true,
					'filename' => $filename . '.csv',
					'format'   => 'csv',
					'content'  => $this->correction_report->export_plan_csv( $report ),
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'filename' => $filename . '.json',
				'format'   => 'json',
				'data'     => $this->correction_report->export_plan_json( $report, $draft_id ),
			),
			200
		);
	}

	/**
	 * Build the CSV export of a completed correction run.
	 *
	 * @param array<string, mixed> $record History record.
	 * @return string
	 */
	private function correction_run_csv( array $record ) {
		$columns = array( 'correction_id', 'status', 'element_id', 'property', 'viewport', 'action', 'old_value', 'new_value' );
		$rows    = array( implode( ',', $columns ) );
		foreach ( isset( $record['changes'] ) && is_array( $record['changes'] ) ? $record['changes'] : array() as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			$rows[] = implode(
				',',
				array_map(
					array( $this, 'csv_cell' ),
					array(
						isset( $change['correction_id'] ) ? $change['correction_id'] : '',
						isset( $change['status'] ) ? $change['status'] : '',
						isset( $change['element_id'] ) ? $change['element_id'] : '',
						isset( $change['property'] ) ? $change['property'] : '',
						isset( $change['viewport'] ) ? $change['viewport'] : '',
						isset( $change['action'] ) ? $change['action'] : '',
						isset( $change['old_value'] ) ? $change['old_value'] : '',
						isset( $change['new_value'] ) ? $change['new_value'] : '',
					)
				)
			);
		}
		return implode( "\n", $rows );
	}

	/**
	 * Escape one CSV cell.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function csv_cell( $value ) {
		$value = str_replace( array( "\r", "\n", "\t" ), ' ', (string) $value );
		if ( false !== strpos( $value, ',' ) || false !== strpos( $value, '"' ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}

	/**
	 * Return the request body, preferring the JSON payload.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return array<string, mixed>
	 */
	private function body( $request ) {
		$params = $request instanceof \WP_REST_Request ? $request->get_json_params() : null;
		if ( ! is_array( $params ) || empty( $params ) ) {
			$params = $request instanceof \WP_REST_Request ? $request->get_body_params() : array();
		}
		return is_array( $params ) ? $params : array();
	}

	/**
	 * Return a stored Phase 5 validation result.
	 *
	 * @param string $validation_id Validation identifier.
	 * @return array<string, mixed>|null
	 */
	private function stored_validation( $validation_id ) {
		if ( ! is_string( $validation_id ) || ! preg_match( '/^val_[a-f0-9]{24}$/', $validation_id ) ) {
			return null;
		}
		$stored = $this->validation_cache->get_by_id( $validation_id );
		return is_array( $stored ) && isset( $stored['result'] ) && is_array( $stored['result'] ) ? $stored['result'] : null;
	}

	/**
	 * Return whether every selected identifier has the correction shape.
	 *
	 * @param array<int, string> $selected Selected identifiers.
	 * @return bool
	 */
	private function valid_selection( array $selected ) {
		foreach ( $selected as $correction_id ) {
			if ( ! is_string( $correction_id ) || ! preg_match( '/^correction_[0-9]{3}_[a-f0-9]{8}$/', $correction_id ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Build an error response from an engine error envelope or a code.
	 *
	 * @param array<string, mixed>|string $error   Engine envelope or a code.
	 * @param string                      $message Optional message.
	 * @param int                         $status  Optional status.
	 * @return \WP_REST_Response
	 */
	private function error_response( $error, $message = '', $status = 500 ) {
		if ( is_array( $error ) && isset( $error['error'] ) && is_array( $error['error'] ) ) {
			$code    = isset( $error['error']['code'] ) ? (string) $error['error']['code'] : 'replicaforge_error';
			$message = isset( $error['error']['message'] ) ? (string) $error['error']['message'] : __( 'The request could not be completed.', 'replicaforge' );
			$status  = isset( $error['error']['status'] ) ? (int) $error['error']['status'] : 500;
		} else {
			$code    = is_string( $error ) ? $error : 'replicaforge_error';
			$message = '' !== $message ? $message : __( 'The request could not be completed.', 'replicaforge' );
		}

		return new \WP_REST_Response(
			array(
				'success' => false,
				'error'   => array(
					'code'    => sanitize_key( $code ),
					'message' => $message,
				),
			),
			absint( $status )
		);
	}

	/**
	 * Validate a generated draft against the source analysis.
	 *
	 * The request cannot change the draft. It carries the Phase 2 representation
	 * and the draft identifier, and the engine returns a read-only report.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function validate_draft( $request ) {
		try {
			$params = $request->get_json_params();
			if ( ! is_array( $params ) || empty( $params ) ) {
				$params = $request->get_body_params();
			}

			$representation = isset( $params['design_representation'] ) ? $params['design_representation'] : null;
			if ( ! is_array( $representation ) || ! $this->validate_representation_param( $representation ) ) {
				return $this->error_response( 'invalid_design_representation', 'A valid Design Representation 2.0 is required.', 400 );
			}

			$draft_id = isset( $params['draft_id'] ) ? absint( $params['draft_id'] ) : 0;
			if ( $draft_id < 1 ) {
				return $this->error_response( 'invalid_draft', 'A draft identifier is required.', 400 );
			}

			$result = $this->validation_engine->validate(
				$representation,
				$draft_id,
				array(
					'visual'    => ! empty( $params['visual'] ),
					'ai'        => ! empty( $params['ai'] ),
					'force'     => ! empty( $params['force'] ),
					'viewports' => isset( $params['viewports'] ) && is_array( $params['viewports'] ) ? $params['viewports'] : array(),
				),
			);

			if ( ! is_array( $result ) || empty( $result['success'] ) ) {
				$error = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : array();
				return $this->error_response(
					isset( $error['code'] ) ? $error['code'] : 'validation_failed',
					isset( $error['message'] ) ? $error['message'] : 'The validation could not be completed.',
					isset( $error['status'] ) ? (int) $error['status'] : 500
				);
			}

			return new \WP_REST_Response(
				array(
					'success' => true,
					'data'    => $this->validation_report->build( $result ),
				),
				200
			);
		} catch ( \Throwable $exception ) {
			Security::log_event( 'validation_failed', array( 'code' => 'rest_exception', 'reason' => 'exception' ) );
			return $this->error_response( 'validation_failed', 'The validation could not be completed.', 500 );
		}
	}

	/**
	 * Return a stored validation result.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_validation( $request ) {
		$validation_id = isset( $request['validation_id'] ) ? sanitize_text_field( (string) $request['validation_id'] ) : '';
		$stored        = $this->validation_cache->get_by_id( $validation_id );
		if ( ! is_array( $stored ) || ! isset( $stored['result'] ) ) {
			return $this->error_response( 'validation_not_found', 'The validation result was not found or has expired.', 404 );
		}
		$draft_id = isset( $stored['result']['generated']['draft_id'] ) ? (int) $stored['result']['generated']['draft_id'] : 0;
		if ( $draft_id > 0 && ! current_user_can( 'edit_post', $draft_id ) ) {
			return $this->error_response( 'replicaforge_forbidden', 'You do not have permission to inspect this validation result.', 403 );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $this->validation_report->build( $stored['result'] ),
			),
			200
		);
	}

	/**
	 * Export a stored validation result as JSON or CSV.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function export_validation( $request ) {
		$validation_id = isset( $request['validation_id'] ) ? sanitize_text_field( (string) $request['validation_id'] ) : '';
		$format        = isset( $request['format'] ) && 'csv' === $request['format'] ? 'csv' : 'json';
		$stored        = $this->validation_cache->get_by_id( $validation_id );
		if ( ! is_array( $stored ) || ! isset( $stored['result'] ) ) {
			return $this->error_response( 'validation_not_found', 'The validation result was not found or has expired.', 404 );
		}
		$draft_id = isset( $stored['result']['generated']['draft_id'] ) ? (int) $stored['result']['generated']['draft_id'] : 0;
		if ( $draft_id > 0 && ! current_user_can( 'edit_post', $draft_id ) ) {
			return $this->error_response( 'replicaforge_forbidden', 'You do not have permission to export this validation result.', 403 );
		}

		$filename = 'replicaforge-validation-' . $validation_id;

		if ( 'csv' === $format ) {
			$csv = $this->validation_report->export_csv( $stored['result'] );
			return new \WP_REST_Response(
				array(
					'success'  => true,
					'filename' => $filename . '.csv',
					'format'   => 'csv',
					'content'  => $csv,
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'filename' => $filename . '.json',
				'format'   => 'json',
				'data'     => $this->validation_report->export_json( $stored['result'] ),
			),
			200
		);
	}

	/**
	 * Validate a submitted reconstruction specification without trusting it.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate_specification_param( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return is_string( $encoded ) && strlen( $encoded ) <= 2097152;
	}

	/**
	 * Validate a stored specification reference.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate_specification_id_param( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^spec_[a-f0-9]{32}$/', $value );
	}

	/**
	 * Validate a submitted representation without trusting its contents.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public function validate_representation_param( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return is_string( $encoded ) && strlen( $encoded ) <= 2097152;
	}

	/**
	 * Validate a post identifier used to address a draft.
	 *
	 * A draft is addressed by a positive integer. Anything else, including a
	 * non-numeric string that a database would coerce, is refused here rather
	 * than being silently turned into post zero or a wildcard.
	 *
	 * @param mixed $value Raw parameter.
	 * @return bool
	 */
	public function validate_post_id_param( $value ) {
		if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) || null === $value ) {
			return false;
		}
		if ( is_string( $value ) ) {
			// Digits only. A decimal point or an exponent is refused rather than
			// truncated, because truncating it would address a different post than
			// the one the caller named.
			return 1 === preg_match( '/^[0-9]{1,20}$/', $value ) && (int) $value > 0;
		}
		if ( is_int( $value ) ) {
			return $value > 0;
		}
		if ( is_float( $value ) ) {
			// A float is accepted only when it is exactly an integer, so 12.0 is a
			// legitimate id from a JSON client and 12.5 is not.
			return is_finite( $value ) && floor( $value ) === $value && $value > 0;
		}
		return false;
	}

	/**
	 * Sanitize a post identifier.
	 *
	 * @param mixed $value Raw parameter.
	 * @return int
	 */
	public function sanitize_post_id_param( $value ) {
		return $this->validate_post_id_param( $value ) ? (int) $value : 0;
	}

	/**
	 * Validate a stored validation identifier.
	 *
	 * The shape is checked rather than the value, because a validation id names a
	 * record in storage. Accepting any string here would let a caller probe the
	 * cache with arbitrary keys.
	 *
	 * @param mixed $value Raw parameter.
	 * @return bool
	 */
	public function validate_validation_id_param( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^val_[a-f0-9]{24}$/', $value );
	}

	/**
	 * Validate a stored correction plan identifier.
	 *
	 * @param mixed $value Raw parameter.
	 * @return bool
	 */
	public function validate_plan_id_param( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^plan_[a-f0-9]{20}$/', $value );
	}

	/**
	 * Validate a stored correction run identifier.
	 *
	 * @param mixed $value Raw parameter.
	 * @return bool
	 */
	public function validate_correction_id_param( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^cor_[a-f0-9]{20}$/', $value );
	}

	/**
	 * Validate the list of correction identifiers a user approved.
	 *
	 * Every entry must look like a correction identifier. The list carries no
	 * document data, so a malformed entry is a mistake rather than an attack, but
	 * it is still refused: applying a correction the server did not plan is exactly
	 * what the review step exists to prevent, and silently dropping an entry the
	 * user ticked would make the review screen lie.
	 *
	 * @param mixed $value Raw parameter.
	 * @return bool
	 */
	public function validate_selected_param( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( count( $value ) > Correction_Limits::MAX_APPLY ) {
			return false;
		}
		foreach ( $value as $entry ) {
			if ( ! $this->validate_correction_id_param( $entry ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Sanitize the approved list without discarding entries the user selected.
	 *
	 * Only the shape is enforced, and a list that fails the check is left as it
	 * arrived so the route can refuse it with a clear message rather than quietly
	 * applying a subset.
	 *
	 * @param mixed $value Raw parameter.
	 * @return array<int, string>
	 */
	public function sanitize_selected_param( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $entry ) {
			if ( is_string( $entry ) ) {
				$out[] = trim( $entry );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Validate optional viewport overrides.
	 *
	 * The override shape is a map of viewport name to a width and a height. The
	 * names must be the ones ReplicaForge knows, and the dimensions must be
	 * plausible, because an override feeds a render request and an unbounded
	 * dimension would be a way to make a renderer allocate an enormous image.
	 *
	 * @param mixed $value Raw parameter.
	 * @return bool
	 */
	public function validate_viewports_param( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return false;
		}
		if ( count( $value ) > count( Validation_Limits::VIEWPORTS ) ) {
			return false;
		}
		foreach ( $value as $name => $size ) {
			if ( ! is_string( $name ) || ! isset( Validation_Limits::VIEWPORTS[ $name ] ) ) {
				return false;
			}
			if ( ! is_array( $size ) ) {
				return false;
			}
			foreach ( array( 'width', 'height' ) as $dimension ) {
				if ( ! isset( $size[ $dimension ] ) ) {
					return false;
				}
				$raw = $size[ $dimension ];
				if ( is_bool( $raw ) || ! is_numeric( $raw ) ) {
					return false;
				}
				$pixels = (int) $raw;
				if ( $pixels < 240 || $pixels > 4096 ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Sanitize viewport overrides, dropping anything not understood.
	 *
	 * @param mixed $value Raw parameter.
	 * @return array<string, array<string, int>>
	 */
	public function sanitize_viewports_param( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $name => $size ) {
			if ( ! is_string( $name ) || ! isset( Validation_Limits::VIEWPORTS[ $name ] ) || ! is_array( $size ) ) {
				continue;
			}
			$entry = array();
			foreach ( array( 'width', 'height' ) as $dimension ) {
				if ( ! isset( $size[ $dimension ] ) || is_bool( $size[ $dimension ] ) || ! is_numeric( $size[ $dimension ] ) ) {
					continue 2;
				}
				$entry[ $dimension ] = max( 240, min( 4096, (int) $size[ $dimension ] ) );
			}
			if ( isset( $entry['width'], $entry['height'] ) ) {
				$out[ $name ] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Validate a boolean argument.
	 *
	 * WordPress coerces several shapes to a boolean, but a bare string such as
	 * "maybe" would become true, so an explicit set is accepted instead.
	 *
	 * @param mixed $value Raw parameter.
	 * @return bool
	 */
	public function validate_boolean_param( $value ) {
		if ( is_bool( $value ) ) {
			return true;
		}
		if ( is_int( $value ) && ( 0 === $value || 1 === $value ) ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '0', '1', 'true', 'false', 'yes', 'no', 'on', 'off' ), true );
		}
		return false;
	}

	/**
	 * Sanitize a boolean argument.
	 *
	 * @param mixed $value Raw parameter.
	 * @return bool
	 */
	public function sanitize_boolean_param( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 1 === $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return false;
	}

	/**
	 * Validate an optional design representation.
	 *
	 * Same size bound as the required argument, and it is a bound rather than a
	 * shape check: the representation is re-validated by the phase that uses it,
	 * so refusing it here for being "wrong" would duplicate that validation and
	 * give two places to keep in step.
	 *
	 * @param mixed $value Raw parameter.
	 * @return bool
	 */
	public function validate_optional_representation_param( $value ) {
		return $this->validate_representation_param( $value );
	}

	/**
	 * Sanitize the URL parameter without resolving or fetching it.
	/**
	 * Sanitize the URL parameter without resolving or fetching it.
`;

/* The new validators are inserted immediately before the existing
   sanitize_url_param(), so the argument validators live together. */
const p = 'C:/Users/dell/Desktop/Wordpress Website/ReplicaForge/wp-content/plugins/replicaforge/includes/class-replicaforge-rest-api.php';
	/**
	 * Sanitize the URL parameter without resolving or fetching it.
	 *
	 * @param mixed $value Raw parameter.
	 * @return string
	 */
	public function sanitize_url_param( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : stripslashes( $value );
		$value = trim( $value );
		if ( strlen( $value ) > 2048 || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Validate the basic REST parameter type and size.
	 *
	 * @param mixed $value Raw parameter.
	 * @return bool
	 */
	public function validate_url_param( $value ) {
		return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 2048;
	}
}
