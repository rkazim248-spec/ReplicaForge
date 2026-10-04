<?php
/**
 * Phase 16: the interaction REST API.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The REST surface for interaction analysis, models, mapping and inspection.
 *
 * ### What this API can and cannot do
 *
 * It **reads and analyses**. It does not generate, correct, or write an interaction to an
 * Elementor document, and there is deliberately no route that does. §38's answer for an
 * unreproducible behaviour is a static fallback or a manual note, and both of those are
 * decided by a person reading the report — so the capability here is *seeing clearly*,
 * not *acting automatically*.
 *
 * That is also why the read routes dominate. A user who wants to know what the source
 * does needs the model, the timeline, the evidence and the mapping, and needs them
 * without a job.
 *
 * ### Gating
 *
 * Every route is gated on a signed-in user, and the per-project routes additionally
 * require that the user can see the project. The gate re-checks inside each handler
 * rather than trusting `permission_callback` alone, matching every other ReplicaForge
 * API: a `permission_callback` is a first line, not the only line.
 *
 * ### Analysis is expensive and is a job
 *
 * A route that starts an analysis does not perform it inline. It builds a plan and hands
 * it to the caller, because browser observation is seconds-to-minutes of work and a REST
 * request that does it synchronously would time out and leave the user with an ambiguous
 * state. The one exception is a purely static analysis with a small budget, which is fast
 * enough to be worth doing on demand — and even that is bounded.
 */
final class Interaction_Api {

	/**
	 * The REST namespace version.
	 *
	 * @var string
	 */
	const NAMESPACE_V1 = 'replicaforge/v1';

	/**
	 * The interaction service.
	 *
	 * @var Interaction_Service
	 */
	private $service;

	/**
	 * The mapper, exposed for the capability route.
	 *
	 * @var Interaction_Mapper
	 */
	private $mapper;

	/**
	 * The project repository, for the ownership check.
	 *
	 * @var Project_Repository
	 */
	private $projects;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $services Optional: service, mapper, projects.
	 */
	public function __construct( array $services = array() ) {
		// `isset()` before `instanceof`, for the reason given in Interaction_Service's
		// constructor: the array access is evaluated either way, and on a missing key PHP
		// warns before the type check can answer.
		$this->service  = isset( $services['service'] ) && $services['service'] instanceof Interaction_Service
			? $services['service']
			: new Interaction_Service();
		$this->mapper   = isset( $services['mapper'] ) && $services['mapper'] instanceof Interaction_Mapper
			? $services['mapper']
			: new Interaction_Mapper();
		$this->projects = isset( $services['projects'] ) && $services['projects'] instanceof Project_Repository
			? $services['projects']
			: new Project_Repository();
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->route( '/interactions/capabilities', 'GET', 'get_capabilities', 'gate_signed_in' );
		$this->route( '/interactions/registry', 'GET', 'get_registry', 'gate_signed_in' );
		$this->route( '/interactions/budgets', 'GET', 'get_budgets', 'gate_signed_in' );
		$this->route( '/interactions/detect', 'POST', 'detect', 'gate_signed_in' );
		$this->route( '/interactions/analyze', 'POST', 'analyze', 'gate_signed_in' );
		$this->route( '/interactions/analyze/(?P<project>[A-Za-z0-9_\-]{1,64})', 'POST', 'analyze_project', 'gate_project' );
		$this->route( '/interactions/models/(?P<project>[A-Za-z0-9_\-]{1,64})', 'GET', 'list_models', 'gate_project' );
		$this->route( '/interactions/models/(?P<project>[A-Za-z0-9_\-]{1,64})/(?P<page>[A-Za-z0-9_\-\.]{1,96})', 'GET', 'get_model', 'gate_project' );
		$this->route( '/interactions/models/(?P<project>[A-Za-z0-9_\-]{1,64})/(?P<page>[A-Za-z0-9_\-\.]{1,96})/timeline', 'GET', 'get_timeline', 'gate_project' );
		$this->route( '/interactions/validate', 'POST', 'validate_model', 'gate_signed_in' );
	}

	/**
	 * Register one route.
	 *
	 * @param string $path       Path.
	 * @param string $method     Method.
	 * @param string $callback   Callback.
	 * @param string $permission Permission callback.
	 * @return void
	 */
	private function route( $path, $method, $callback, $permission ) {
		register_rest_route(
			self::NAMESPACE_V1,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, $permission ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Gates
	 * ------------------------------------------------------------------ */

	/**
	 * Require a signed-in user.
	 *
	 * ### Why this is public, and it is not a style choice
	 *
	 * `permission_callback` is invoked by `WP_REST_Server` from *outside* this class. A
	 * `private` gate is not callable from that scope: `is_callable()` returns false and
	 * `call_user_func()` raises, so the route is dispatched with no authorisation check at
	 * all and every one of these endpoints would answer an anonymous caller.
	 *
	 * The first version of this class had both gates private, and a route-table walk
	 * reported them as "set but not callable" — which is exactly the state in which a
	 * permission callback has been provided and does nothing. The gates here are public for
	 * the same reason {@see Workspace_Api}'s twenty-some are, and the test suite asserts
	 * every route's gate is *callable* rather than merely present, because presence is not
	 * the property that matters.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_signed_in( $request ) {
		if ( get_current_user_id() < 1 ) {
			return new \WP_Error(
				'replicaforge_unauthenticated',
				__( 'You must be signed in.', 'replicaforge' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Require a signed-in user who can see the named project.
	 *
	 * Public for the reason given on {@see self::gate_signed_in()}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_project( $request ) {
		$signed_in = $this->gate_signed_in( $request );
		if ( true !== $signed_in ) {
			return $signed_in;
		}

		$project_id = (string) $request->get_param( 'project' );
		if ( ! $this->may_read_project( $project_id ) ) {
			/*
			 * Not found rather than forbidden, so an id that does not exist and one that
			 * belongs to somebody else are answered identically. Distinguishing them would
			 * turn this route into an enumeration oracle for project ids.
			 */
			return new \WP_Error(
				'replicaforge_forbidden',
				__( 'That project is not available.', 'replicaforge' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Return whether the current user may read a project's interactions.
	 *
	 * The owner, any site administrator, and a Phase 15 workspace member with the
	 * `projects.view` capability. The workspace path is consulted through the existing
	 * {@see Permission_Manager} rather than reimplemented, so Phase 16 cannot grant access
	 * Phase 15 would refuse or refuse access Phase 15 grants.
	 *
	 * @param string $project_id Project identifier.
	 * @return bool
	 */
	private function may_read_project( $project_id ) {
		$project_id = (string) $project_id;
		if ( '' === $project_id ) {
			return false;
		}

		$project = $this->projects->find( $project_id );
		if ( ! is_array( $project ) ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		if ( (int) ( $project['owner_id'] ?? 0 ) === $user_id ) {
			return true;
		}

		/*
		 * The project names its own workspace on the collaboration record, so this is one
		 * read rather than a scan of every workspace. A project with no workspace — which
		 * is a legitimate state for a project created before the phase 15 migration, and
		 * for one whose owner could not be resolved — simply has no workspace to check,
		 * and falls through to the owner check above.
		 */
		$context = ( new Project_Context_Store() )->get( $project_id );
		$workspace_id = (string) ( $context['workspace_id'] ?? '' );

		if ( '' !== $workspace_id
			&& ( new Permission_Manager() )->can_in_project( $user_id, $workspace_id, $project_id, 'projects.view' ) ) {
			return true;
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Routes
	 * ------------------------------------------------------------------ */

	/**
	 * Return the capabilities of the interaction layer.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_capabilities( $request ) {
		$error = $this->refuse_unless( $request, 'gate_signed_in' );
		if ( null !== $error ) {
			return $error;
		}

		return $this->ok( $this->service->capabilities() );
	}

	/**
	 * Return the Elementor mapping registry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_registry( $request ) {
		$error = $this->refuse_unless( $request, 'gate_signed_in' );
		if ( null !== $error ) {
			return $error;
		}

		return $this->ok( $this->mapper->registry() );
	}

	/**
	 * Return the observation budgets.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_budgets( $request ) {
		$error = $this->refuse_unless( $request, 'gate_signed_in' );
		if ( null !== $error ) {
			return $error;
		}

		$requested = $request->get_param( 'requested' );
		$budget    = $this->service->budget( is_array( $requested ) ? $requested : array() );

		return $this->ok( array(
			'budget'   => $budget,
			'ceilings' => Interaction_Limits::budgets(),
			'note'     => __( 'Ceilings are fixed. A requested value above a ceiling is clamped, and a filter cannot raise one.', 'replicaforge' ),
		) );
	}

	/**
	 * Detect interaction candidates from supplied HTML.
	 *
	 * Takes the markup directly so a user can try a page without running a full analysis.
	 * The HTML is parsed, never executed — there is no path by which supplied markup runs
	 * anything, and the parser is phase 2's, which reads a DOM and does not evaluate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function detect( $request ) {
		$error = $this->refuse_unless( $request, 'gate_signed_in' );
		if ( null !== $error ) {
			return $error;
		}

		$html = (string) $request->get_param( 'html' );
		if ( '' === trim( $html ) ) {
			return $this->error( 'invalid_request', __( 'No markup was supplied.', 'replicaforge' ), 400 );
		}
		if ( strlen( $html ) > 1500000 ) {
			// The same bound phase 2's renderer applies. A larger document is refused rather
			// than truncated, because a truncated document produces detections for a
			// fragment and presents them as the whole page.
			return $this->error( 'payload_too_large', __( 'The supplied markup is too large to analyse.', 'replicaforge' ), 413 );
		}

		$url    = (string) $request->get_param( 'url' );
		$verdict = ( new Url_Validator() )->validate( '' !== $url ? $url : 'https://example.invalid/' );
		$base   = ! empty( $verdict['success'] ) ? (string) $verdict['url'] : 'https://example.invalid/';

		$report = $this->service->analyze( 'adhoc', $base, $this->context_for( $html, $base ), array(
			'viewport' => (string) $request->get_param( 'viewport' ),
			'observe'  => false,
		) );

		return $this->ok( array(
			'interactions' => $report['interactions'],
			'state_machines' => $report['state_machines'],
			'forms'        => $report['forms'],
			'status'       => $report['status'],
			'complete'     => $report['complete'],
			'limitations'  => $report['limitations'],
		) );
	}

	/**
	 * Analyse a supplied document and return the whole report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function analyze( $request ) {
		$error = $this->refuse_unless( $request, 'gate_signed_in' );
		if ( null !== $error ) {
			return $error;
		}

		$html = (string) $request->get_param( 'html' );
		if ( '' === trim( $html ) ) {
			return $this->error( 'invalid_request', __( 'No markup was supplied.', 'replicaforge' ), 400 );
		}
		if ( strlen( $html ) > 1500000 ) {
			return $this->error( 'payload_too_large', __( 'The supplied markup is too large to analyse.', 'replicaforge' ), 413 );
		}

		$url     = (string) $request->get_param( 'url' );
		$verdict = ( new Url_Validator() )->validate( '' !== $url ? $url : 'https://example.invalid/' );
		$base    = ! empty( $verdict['success'] ) ? (string) $verdict['url'] : 'https://example.invalid/';

		$budget = $request->get_param( 'budget' );
		$report = $this->service->analyze( 'adhoc', $base, $this->context_for( $html, $base ), array(
			'viewport' => (string) $request->get_param( 'viewport' ),
			'budget'   => is_array( $budget ) ? $budget : array(),
			'observe'  => ! empty( $request->get_param( 'observe' ) ),
		) );

		return $this->ok( $report );
	}

	/**
	 * Analyse a stored project.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function analyze_project( $request ) {
		$error = $this->refuse_unless( $request, 'gate_project' );
		if ( null !== $error ) {
			return $error;
		}

		$project_id = (string) $request->get_param( 'project' );
		$page_id    = (string) $request->get_param( 'page' );
		$page       = $this->page_record( $project_id, $page_id );

		if ( null === $page ) {
			return $this->error( 'not_found', __( 'That page is not available.', 'replicaforge' ), 404 );
		}

		$html = $this->stored_html( $project_id, $page_id );
		if ( '' === $html ) {
			/*
			 * No stored markup. Reported as a refusal rather than an empty success,
			 * because "this page has no interactions" and "this page was never analysed"
			 * are different answers and only one of them is a finding.
			 */
			return $this->error( 'not_analysed', __( 'That page has no stored analysis to read interactions from. Analyse the page first.', 'replicaforge' ), 409 );
		}

		$budget = $request->get_param( 'budget' );
		$report = $this->service->analyze( $page_id, (string) $page['source_url'], $this->context_for( $html, (string) $page['source_url'] ), array(
			'viewport'   => (string) $request->get_param( 'viewport' ),
			'project_id' => $project_id,
			'budget'     => is_array( $budget ) ? $budget : array(),
			'observe'    => ! isset( $request['observe'] ) || ! empty( $request->get_param( 'observe' ) ),
		) );

		$stored = $this->store_model( $project_id, $page_id, $report );

		$report['stored'] = $stored;

		return $this->ok( $report );
	}

	/**
	 * List the stored interaction models for a project.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_models( $request ) {
		$error = $this->refuse_unless( $request, 'gate_project' );
		if ( null !== $error ) {
			return $error;
		}

		$project_id = (string) $request->get_param( 'project' );
		$models     = $this->load_models( $project_id );

		$summary = array();
		foreach ( $models as $page_id => $model ) {
			$summary[] = array(
				'page_id'      => (string) $page_id,
				'status'       => (string) ( $model['status'] ?? '' ),
				'complete'     => ! empty( $model['complete'] ),
				'interactions' => count( (array) ( $model['interactions'] ?? array() ) ),
				'forms'        => count( (array) ( $model['forms'] ?? array() ) ),
				'confidence'   => (float) ( $model['confidence']['overall'] ?? 0 ),
				'unsupported'  => count( (array) ( $model['mapping'] ?? array() ) ) ? $this->unsupported_count( (array) $model['mapping'] ) : 0,
				'stored_at'    => (string) ( $model['stored_at'] ?? '' ),
			);
		}

		return $this->ok( array( 'count' => count( $summary ), 'models' => $summary ) );
	}

	/**
	 * Return one stored model.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_model( $request ) {
		$error = $this->refuse_unless( $request, 'gate_project' );
		if ( null !== $error ) {
			return $error;
		}

		$project_id = (string) $request->get_param( 'project' );
		$page_id    = (string) $request->get_param( 'page' );
		$models     = $this->load_models( $project_id );

		if ( ! isset( $models[ $page_id ] ) ) {
			return $this->error( 'not_found', __( 'No interaction model is stored for that page.', 'replicaforge' ), 404 );
		}

		return $this->ok( $models[ $page_id ] );
	}

	/**
	 * Return the interaction timeline for a page.
	 *
	 * The linear, human-readable rendering §49 asks for. It is derived from the stored
	 * state machines rather than stored separately, so it cannot disagree with them.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_timeline( $request ) {
		$error = $this->refuse_unless( $request, 'gate_project' );
		if ( null !== $error ) {
			return $error;
		}

		$project_id = (string) $request->get_param( 'project' );
		$page_id    = (string) $request->get_param( 'page' );
		$models     = $this->load_models( $project_id );

		if ( ! isset( $models[ $page_id ] ) ) {
			return $this->error( 'not_found', __( 'No interaction model is stored for that page.', 'replicaforge' ), 404 );
		}

		$timeline = array();
		foreach ( (array) ( $models[ $page_id ]['state_machines'] ?? array() ) as $machine ) {
			foreach ( State_Machine::timeline( $machine ) as $row ) {
				$row['component_id'] = (string) ( $machine['component_id'] ?? '' );
				$row['type']         = (string) ( $machine['type'] ?? 'unknown' );
				$timeline[]          = $row;
			}
		}

		usort(
			$timeline,
			static function ( $left, $right ) {
				return (int) $left['step'] <=> (int) $right['step'];
			}
		);

		return $this->ok( array( 'page_id' => $page_id, 'rows' => $timeline, 'count' => count( $timeline ) ) );
	}

	/**
	 * Validate a supplied model without analysing anything.
	 *
	 * Exposed because the validator is the §40 gate and a caller who is building a
	 * specification by hand needs to be able to put it through the same gate the plugin
	 * uses. It cannot be used to bypass anything: it reports, it does not apply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function validate_model( $request ) {
		$error = $this->refuse_unless( $request, 'gate_signed_in' );
		if ( null !== $error ) {
			return $error;
		}

		$payload = $request->get_param( 'model' );
		if ( ! is_array( $payload ) || array() === $payload ) {
			return $this->error( 'invalid_request', __( 'No model was supplied.', 'replicaforge' ), 400 );
		}
		if ( strlen( (string) wp_json_encode( $payload ) ) > 2000000 ) {
			return $this->error( 'payload_too_large', __( 'The supplied model is too large to validate.', 'replicaforge' ), 413 );
		}

		$model = $this->model_from_array( $payload );
		if ( null === $model ) {
			return $this->error( 'invalid_model', __( 'The supplied model could not be read.', 'replicaforge' ), 400 );
		}

		$validator = new Interaction_Validator();
		$verdict   = $validator->validate( $model );

		return $this->ok( array(
			'valid'   => (bool) $verdict['valid'],
			'errors'  => (array) $verdict['errors'],
			'warnings' => (array) $verdict['warnings'],
			'checks'  => $validator->checks(),
		) );
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------ */

	/**
	 * The option holding a project's interaction models.
	 *
	 * @param string $project_id Project identifier.
	 * @return string
	 */
	private function option_for( $project_id ) {
		return 'replicaforge_interactions_' . substr( hash( 'sha256', (string) $project_id ), 0, 16 );
	}

	/**
	 * Load a project's stored models.
	 *
	 * @param string $project_id Project identifier.
	 * @return array<string, array>
	 */
	private function load_models( $project_id ) {
		$stored = get_option( $this->option_for( $project_id ), array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Store a model, bounded.
	 *
	 * Bounded per project, oldest first, exactly as the phase 13 representation store is —
	 * an unbounded per-page record in one option is the failure mode every phase of this
	 * plugin has had to learn about separately.
	 *
	 * @param string               $project_id Project identifier.
	 * @param string               $page_id    Page identifier.
	 * @param array<string, mixed> $report     Report.
	 * @return array<string, mixed>
	 */
	private function store_model( $project_id, $page_id, array $report ) {
		$models = $this->load_models( $project_id );

		$record = $report;
		$record['stored_at'] = gmdate( 'c' );

		$models[ (string) $page_id ] = $record;

		$max = 25;
		if ( count( $models ) > $max ) {
			uasort( $models, static function ( $left, $right ) {
				return strcmp( (string) ( $left['stored_at'] ?? '' ), (string) ( $right['stored_at'] ?? '' ) );
			} );
			$models = array_slice( $models, -$max, null, true );
		}

		update_option( $this->option_for( $project_id ), $models, false );

		return array( 'stored' => true, 'page_id' => (string) $page_id, 'count' => count( $models ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Build a phase 2 analysis context from markup.
	 *
	 * @param string $html     Markup.
	 * @param string $base_url Base URL.
	 * @return array<string, mixed>
	 */
	private function context_for( $html, $base_url ) {
		$document = new \DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8" ?><!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		return ( new Dom_Analyzer() )->build( $document, $base_url );
	}

	/**
	 * Return a project's page record.
	 *
	 * @param string $project_id Project identifier.
	 * @param string $page_id    Page identifier.
	 * @return array<string, mixed>|null
	 */
	private function page_record( $project_id, $page_id ) {
		if ( '' === $page_id ) {
			$pages = ( new Website_Repository() )->pages( $project_id );
			foreach ( $pages as $page ) {
				if ( 'analyzed' === (string) ( $page['status'] ?? '' ) || 'generated' === (string) ( $page['status'] ?? '' ) ) {
					return $page;
				}
			}

			return null;
		}

		$pages = ( new Website_Repository() )->pages( $project_id );
		foreach ( $pages as $page ) {
			if ( (string) $page['page_id'] === $page_id ) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * Return the stored markup for a page, when there is any.
	 *
	 * Phase 2 keeps the representation rather than the raw HTML, so this reads the
	 * representation and re-serialises the node table. That is honest: what is analysed is
	 * the same bounded, sanitised node data phase 2 built, not a fresh fetch of the live
	 * page, which would be a second network request and a second set of SSRF questions.
	 *
	 * @param string $project_id Project identifier.
	 * @param string $page_id    Page identifier.
	 * @return string
	 */
	private function stored_html( $project_id, $page_id ) {
		$stored = get_option( 'replicaforge_analyses', array() );
		if ( ! is_array( $stored ) ) {
			return '';
		}

		foreach ( $stored as $analysis ) {
			if ( ! is_array( $analysis ) ) {
				continue;
			}
			if ( (string) ( $analysis['page_id'] ?? '' ) !== (string) $page_id ) {
				continue;
			}
			$html = (string) ( $analysis['html'] ?? '' );
			if ( '' !== $html ) {
				return $html;
			}
		}

		return '';
	}

	/**
	 * Rebuild a model from a stored or supplied array.
	 *
	 * @param array<string, mixed> $payload Model array.
	 * @return Interaction_Model|null
	 */
	private function model_from_array( array $payload ) {
		if ( ! isset( $payload['page_id'] ) && ! isset( $payload['state_machines'] ) ) {
			return null;
		}

		$model = new Interaction_Model( array(
			'page_id'    => (string) ( $payload['page_id'] ?? '' ),
			'source_url' => (string) ( $payload['source_url'] ?? '' ),
			'status'     => (string) ( $payload['status'] ?? '' ),
		) );

		foreach ( (array) ( $payload['state_machines'] ?? array() ) as $machine ) {
			if ( is_array( $machine ) ) {
				$model->add_machine( $machine );
			}
		}

		foreach ( (array) ( $payload['limitations'] ?? array() ) as $limitation ) {
			$model->note_limitation( (string) $limitation );
		}

		foreach ( (array) ( $payload['forms'] ?? array() ) as $form ) {
			if ( is_array( $form ) ) {
				$model->add_form( $form );
			}
		}

		return $model;
	}

	/**
	 * Count the unsupported mappings.
	 *
	 * @param array<int, array<string, mixed>> $mapping Mappings.
	 * @return int
	 */
	private function unsupported_count( array $mapping ) {
		$count = 0;
		foreach ( $mapping as $row ) {
			if ( in_array( (string) ( $row['outcome'] ?? '' ), array( 'unsupported', 'requires_review' ), true ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Re-run a gate and return a response, or null when it passed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $gate    Gate name.
	 * @return \WP_REST_Response|null
	 */
	private function refuse_unless( $request, $gate ) {
		$verdict = call_user_func( array( $this, $gate ), $request );
		if ( true === $verdict ) {
			return null;
		}

		$code    = $verdict instanceof \WP_Error ? (string) $verdict->get_error_code() : 'replicaforge_forbidden';
		$message = $verdict instanceof \WP_Error ? (string) $verdict->get_error_message() : __( 'That request is not available.', 'replicaforge' );
		$data    = $verdict instanceof \WP_Error ? $verdict->get_error_data() : array();
		$status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 403;

		return $this->error( $code, $message, $status > 0 ? $status : 403 );
	}

	/**
	 * Build a success response.
	 *
	 * @param mixed $data   Data.
	 * @param int   $status Status.
	 * @return \WP_REST_Response
	 */
	private function ok( $data, $status = 200 ) {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
				'meta'    => array( 'request_id' => Request_Context::request_id() ),
			),
			(int) $status
		);
	}

	/**
	 * Build an error response.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  Status.
	 * @return \WP_REST_Response
	 */
	private function error( $code, $message, $status = 400 ) {
		$status = (int) $status;
		if ( $status < 400 || $status > 499 ) {
			$status = 500;
		}
		if ( $status >= 500 ) {
			$message = __( 'The request could not be completed.', 'replicaforge' );
		}

		return new \WP_REST_Response(
			array(
				'success' => false,
				'error'   => array(
					'code'    => sanitize_key( (string) $code ),
					'message' => (string) $message,
				),
				'meta'    => array( 'request_id' => Request_Context::request_id() ),
			),
			$status
		);
	}
}
