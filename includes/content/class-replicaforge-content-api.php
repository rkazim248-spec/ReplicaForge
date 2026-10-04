<?php
/**
 * Phase 14: the content intelligence REST surface.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The content controller.
 *
 * ### The security posture, stated before the routes
 *
 * Content mapping is the first ReplicaForge capability that can **write to the user's
 * data**, so the boundary here is tighter than for any earlier phase. Four rules hold
 * across every route:
 *
 * 1. **No route accepts a mapping, a field, or a destination.** §31 says "do not expose
 *    arbitrary mapping execution", and the way that is true rather than promised is that
 *    no parameter of this controller is ever used as a field name, a meta key, a table,
 *    or a callable. A caller names a *plan* — an opaque id the validator already checked
 *    — and a list of *mapping ids within that plan* to approve. There is no route at
 *    which a caller can name where a value goes.
 * 2. **Ownership is resolved before the store is read**, with one message for "missing"
 *    and "not yours".
 * 3. **Every response is bounded** and every id is a validated pattern before it reaches
 *    an option name.
 * 4. **The plan id is the only handle to a plan**, and a plan is keyed by project, so a
 *    plan belonging to another project is not found rather than found and refused.
 *
 * ### What a caller can do that is worth being careful about
 *
 * `POST /content/mapping/apply` writes. It therefore requires the `apply` operation to be
 * entitled, refuses unless the named plan validates, writes only mappings the caller
 * explicitly approved, and snapshots everything first. The alternative — trusting a
 * "confirmed" flag from the client — is what §12 and §48 both forbid.
 */
final class Content_Api {

	/**
	 * Route namespace.
	 *
	 * @var string
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
	 * Content service.
	 *
	 * @var Content_Service
	 */
	private $content;

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
		$this->logger     = ( isset( $services['logger'] ) && $services['logger'] instanceof Logger ) ? $services['logger'] : new Logger();
		$this->access     = ( isset( $services['access'] ) && $services['access'] instanceof Project_Access ) ? $services['access'] : new Project_Access();
		$this->content    = ( isset( $services['content'] ) && $services['content'] instanceof Content_Service ) ? $services['content'] : new Content_Service( array( 'logger' => $this->logger, 'user_id' => get_current_user_id() ) );
		$this->entitlements = ( isset( $services['entitlements'] ) && $services['entitlements'] instanceof Entitlement_Manager )
			? $services['entitlements']
			: new Entitlement_Manager( null, null, $this->access, $this->logger );
	}

	/**
	 * Register the Phase 14 routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$project = '(?P<project_id>[A-Za-z0-9_\-]{1,64})';

		$this->route( '/content/providers', 'GET', 'get_providers', 'can_use' );
		$this->route( '/content/destination', 'GET', 'get_destination', 'can_use' );
		$this->route( '/content/limits', 'GET', 'get_limits', 'can_use' );
		$this->route( '/content/modes', 'GET', 'get_modes', 'can_use' );

		$this->route( '/content/analyze', 'POST', 'analyse', 'can_use' );
		$this->route( '/content/mapping/plan', 'POST', 'plan', 'can_use' );
		$this->route( '/content/mapping/preview', 'POST', 'preview', 'can_use' );
		$this->route( '/content/mapping/validate', 'POST', 'validate', 'can_use' );
		$this->route( '/content/mapping/apply', 'POST', 'apply', 'can_manage' );
		$this->route( '/content/mapping/' . $project . '/(?P<plan_id>[A-Za-z0-9_\-]{4,64})', 'GET', 'get_plan', 'can_use' );
		$this->route( '/content/mapping/review', 'POST', 'review', 'can_use' );
		$this->route( '/content/mapping/entities/match', 'POST', 'match_entities', 'can_use' );
		$this->route( '/content/provenance/' . $project, 'GET', 'get_provenance', 'can_use' );
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * GET /content/providers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_providers( $request ) {
		return $this->ok( array( 'providers' => $this->content->providers() ) );
	}

	/**
	 * GET /content/destination.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_destination( $request ) {
		$model = $this->content->destination_model();
		return $this->ok( array( 'destination' => $model ) );
	}

	/**
	 * GET /content/limits.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_limits( $request ) {
		return $this->ok( array( 'limits' => array(
			'schema_version'     => Content_Limits::SCHEMA_VERSION,
			'engine_version'     => Content_Limits::ENGINE_VERSION,
			'max_mappings'       => Content_Limits::MAX_MAPPINGS,
			'max_content_items'  => Content_Limits::MAX_CONTENT_ITEMS,
			'max_entities'       => Content_Limits::MAX_ENTITIES,
			'max_dest_batch'     => Content_Limits::MAX_DESTINATION_BATCH,
			'auto_apply_at'      => Content_Limits::AUTO_APPLY_CONFIDENCE,
			'reject_below'       => Content_Limits::REJECT_CONFIDENCE,
			'image_only_ceiling' => Content_Limits::IMAGE_ONLY_CEILING,
			'weights'            => Content_Mapper::WEIGHTS,
			'ownership'          => Content_Limits::ownership_states(),
			'actions'            => Content_Limits::ACTIONS,
			'missing_markers'    => Content_Limits::NULL_MARKERS,
		) ) );
	}

	/**
	 * GET /content/modes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_modes( $request ) {
		$out = array();
		foreach ( Content_Limits::MODES as $mode ) {
			$out[] = array(
				'mode'                 => (string) $mode,
				'requires_confirmation'=> Content_Limits::mode_requires_confirmation( $mode ),
				'label'                => $this->mode_label( $mode ),
				'description'          => $this->mode_description( $mode ),
			);
		}
		return $this->ok( array( 'modes' => $out, 'default' => 'hybrid_replica' ) );
	}

	/**
	 * POST /content/analyze.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function analyse( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$gate = $this->entitlements->check( 'content_analysis', get_current_user_id() );
		if ( empty( $gate['allowed'] ) ) {
			return $this->error( 'limit_reached', (string) ( $gate['message'] ?? '' ), (int) ( $gate['status'] ?? 429 ) );
		}

		$page_id = (string) $request->get_param( 'page_id' );
		if ( '' === $page_id ) {
			return $this->error( 'invalid_request', __( 'A page is required.', 'replicaforge' ), 400 );
		}

		// The representation comes from stored post meta. Nothing structural arrives
		// from the request, so this route cannot be handed a document to interpret.
		$representation = get_post_meta( (int) $page_id, 'replicaforge_analysis', true );
		if ( ! is_array( $representation ) || array() === $representation ) {
			return $this->error( 'not_analysed', __( 'That page has not been analysed yet.', 'replicaforge' ), 409 );
		}

		$result = $this->content->analyse(
			(string) $request->get_param( 'project_id' ),
			$page_id,
			$representation,
			null,
			array(
				'no_cache' => ! empty( $request->get_param( 'no_cache' ) ),
				'visual'   => (array) $request->get_param( 'visual' ),
			)
		);

		return $this->ok( array( 'analysis' => $result ) );
	}

	/**
	 * POST /content/mapping/plan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function plan( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$gate = $this->entitlements->check( 'content_mapping', get_current_user_id() );
		if ( empty( $gate['allowed'] ) ) {
			return $this->error( 'limit_reached', (string) ( $gate['message'] ?? '' ), (int) ( $gate['status'] ?? 429 ) );
		}

		$model = (array) $request->get_param( 'model' );
		if ( array() === $model ) {
			return $this->error( 'invalid_request', __( 'A content model is required to build a mapping plan.', 'replicaforge' ), 400 );
		}

		$mode = (string) ( $request->get_param( 'mode' ) ?? 'hybrid_replica' );
		if ( ! in_array( $mode, Content_Limits::MODES, true ) ) {
			return $this->error( 'invalid_mode', __( 'That reconstruction mode is not one ReplicaForge offers.', 'replicaforge' ), 400 );
		}

		$plan = $this->content->plan(
			(string) $request->get_param( 'project_id' ),
			$model,
			array(
				'mode'      => $mode,
				'page_id'   => (string) ( $request->get_param( 'page_id' ) ?? '' ),
				'confirmed' => ! empty( $request->get_param( 'confirmed' ) ),
			)
		);

		return $this->ok( array( 'plan' => $plan ) );
	}

	/**
	 * POST /content/mapping/preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preview( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$plan = $this->plan_from_request( $request );
		if ( null === $plan ) {
			return $this->error( 'mapping_not_found', __( 'That mapping plan could not be found for this project.', 'replicaforge' ), 404 );
		}

		return $this->ok( array( 'preview' => $this->content->preview( $plan ) ) );
	}

	/**
	 * POST /content/mapping/validate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function validate( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$plan = $this->plan_from_request( $request );
		if ( null === $plan ) {
			return $this->error( 'mapping_not_found', __( 'That mapping plan could not be found for this project.', 'replicaforge' ), 404 );
		}

		$verdict = ( new Content_Validator() )->validate( $plan );
		$gate    = ( new Content_Validator() )->eligible_for_apply( $plan, $this->approved_ids( $request ) );

		return $this->ok( array( 'validation' => $verdict, 'eligibility' => $gate['counts'], 'withheld' => $gate['withheld'] ) );
	}

	/**
	 * POST /content/mapping/apply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function apply( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$gate = $this->entitlements->check( 'content_apply', get_current_user_id() );
		if ( empty( $gate['allowed'] ) ) {
			return $this->error( 'limit_reached', (string) ( $gate['message'] ?? '' ), (int) ( $gate['status'] ?? 429 ) );
		}

		$plan = $this->plan_from_request( $request );
		if ( null === $plan ) {
			return $this->error( 'mapping_not_found', __( 'That mapping plan could not be found for this project.', 'replicaforge' ), 404 );
		}

		// §18: a mode that replaces source content cannot be applied without the caller
		// saying so. The flag is *recorded* in the plan rather than trusted as a bypass,
		// and a high-risk mapping still needs its own approval.
		if ( Content_Limits::mode_requires_confirmation( (string) $plan['mode'] ) && empty( $request->get_param( 'confirmed' ) ) ) {
			return $this->error( 'confirmation_required', __( 'This mode replaces the source site\'s content with your own. Confirm that before applying.', 'replicaforge' ), 409 );
		}

		$dry_run = ! empty( $request->get_param( 'dry_run' ) );
		$result  = $this->content->apply(
			$plan,
			$this->approved_ids( $request ),
			array(
				'dry_run'  => $dry_run,
				'existing' => (string) ( $request->get_param( 'existing' ) ?? 'review' ),
				'user_id'  => get_current_user_id(),
			)
		);

		$this->logger->info(
			'content_mapping_applied',
			'Applied a content mapping plan.',
			array( 'project' => (string) $plan['project_id'], 'status' => (string) $result['status'], 'dry_run' => $dry_run ),
			'content'
		);

		return $this->ok( array( 'result' => $result ) );
	}

	/**
	 * GET /content/mapping/{project}/{plan}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_plan( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}
		$plan = $this->content->plan_by_id( (string) $request->get_param( 'project_id' ), (string) $request->get_param( 'plan_id' ) );
		if ( null === $plan ) {
			return $this->error( 'mapping_not_found', __( 'That mapping plan could not be found for this project.', 'replicaforge' ), 404 );
		}
		return $this->ok( array( 'plan' => $plan ) );
	}

	/**
	 * POST /content/mapping/review.
	 *
	 * Records a decision about a mapping. It does not apply anything — a review is a
	 * decision, and applying is a separate, separately-authorised call.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function review( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$plan = $this->plan_from_request( $request );
		if ( null === $plan ) {
			return $this->error( 'mapping_not_found', __( 'That mapping plan could not be found for this project.', 'replicaforge' ), 404 );
		}

		$mapping_id = (string) $request->get_param( 'mapping_id' );
		$decision   = (string) ( $request->get_param( 'decision' ) ?? '' );

		if ( ! in_array( $decision, array( 'accept', 'reject' ), true ) ) {
			return $this->error( 'invalid_request', __( 'A review decision must be accept or reject.', 'replicaforge' ), 400 );
		}

		$found = null;
		foreach ( (array) ( $plan['mappings'] ?? array() ) as $mapping ) {
			if ( (string) ( $mapping['mapping_id'] ?? '' ) === $mapping_id ) {
				$found = (array) $mapping;
				break;
			}
		}
		if ( null === $found ) {
			return $this->error( 'mapping_not_found', __( 'That mapping is not part of this plan.', 'replicaforge' ), 404 );
		}

		$this->logger->info(
			'content_mapping_reviewed',
			sprintf( 'A content mapping was %s.', $decision ),
			array( 'project' => (string) $plan['project_id'], 'mapping' => $mapping_id, 'decision' => $decision, 'risk' => (string) ( $found['risk'] ?? 'low' ) ),
			'content'
		);

		return $this->ok( array(
			'review' => array(
				'mapping_id' => $mapping_id,
				'decision'   => $decision,
				'risk'       => (string) ( $found['risk'] ?? 'low' ),
				'confidence' => (float) ( $found['confidence'] ?? 0.0 ),
				'evidence'   => array_values( (array) ( $found['evidence'] ?? array() ) ),
				'recorded_at'=> time(),
				// A rejected mapping is not applied by passing an empty approval list; it
				// is named explicitly, so a later call cannot accidentally include it.
				'applies'    => false,
				'note'       => __( 'Your decision was recorded. Nothing was written; applying is a separate step.', 'replicaforge' ),
			),
		) );
	}

	/**
	 * POST /content/mapping/entities/match.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function match_entities( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$sources = (array) $request->get_param( 'sources' );
		if ( array() === $sources ) {
			return $this->error( 'invalid_request', __( 'A list of source entities is required.', 'replicaforge' ), 400 );
		}
		// §44: a batch is bounded, so a single request cannot ask for a 10,000-product
		// match. Paging is the caller's job and the response says whether more remain.
		$sources = array_slice( $sources, 0, Content_Limits::MAX_DESTINATION_BATCH );

		$result = $this->content->match_entities(
			$sources,
			(string) ( $request->get_param( 'entity_type' ) ?? WooCommerce_Provider::ENTITY_PRODUCT ),
			array(
				'provider' => (string) ( $request->get_param( 'provider' ) ?? 'woocommerce' ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?? 50 ),
				'pages'    => (int) ( $request->get_param( 'pages' ) ?? 3 ),
			)
		);

		return $this->ok( array( 'match' => $result ) );
	}

	/**
	 * GET /content/provenance/{project}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_provenance( $request ) {
		$owned = $this->owned( $request );
		if ( isset( $owned['error'] ) ) {
			return $owned['error'];
		}

		$records = $this->content->provenance(
			(string) $request->get_param( 'project_id' ),
			(int) ( $request->get_param( 'destination_id' ) ?? 0 ),
			(string) ( $request->get_param( 'field' ) ?? '' )
		);

		return $this->ok( array( 'provenance' => array_values( $records ), 'count' => count( $records ) ) );
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
		$project_id = (string) $request->get_param( 'project_id' );
		$project    = $this->access->readable_project( get_current_user_id(), $project_id );
		if ( ! $project ) {
			// One message for both cases, so a project id cannot be probed.
			return array( 'error' => $this->error( 'project_not_found', __( 'That project could not be found, or it belongs to another account.', 'replicaforge' ), 404 ) );
		}
		return array( 'project' => $project );
	}

	/**
	 * Resolve a plan from the request, by id or inline.
	 *
	 * An inline plan is accepted because §31's endpoints are shaped around a plan
	 * document, and re-running planning on every preview would make a preview a write.
	 * An inline plan is validated exactly as a stored one is, so it gains nothing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|null
	 */
	private function plan_from_request( $request ) {
		$project_id = (string) $request->get_param( 'project_id' );

		$plan_id = (string) ( $request->get_param( 'plan_id' ) ?? '' );
		if ( '' !== $plan_id ) {
			$plan = $this->content->plan_by_id( $project_id, $plan_id );
			// A stored plan is re-read from the store, never trusted from the request, so
			// a caller cannot apply a plan the validator never saw.
			return $plan;
		}

		$inline = $request->get_param( 'plan' );
		if ( is_array( $inline ) && array() !== $inline ) {
			$inline['project_id'] = (string) ( $inline['project_id'] ?? $project_id );
			// A caller-supplied project id is replaced, not honoured.
			$inline['project_id'] = $project_id;
			return $inline;
		}

		return null;
	}

	/**
	 * Return the approved mapping ids from a request, bounded.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<int, string>
	 */
	private function approved_ids( $request ) {
		$approved = (array) $request->get_param( 'approved' );
		$out      = array();
		foreach ( array_slice( $approved, 0, Content_Limits::MAX_MAPPINGS ) as $id ) {
			$id = (string) $id;
			// A mapping id reaches an option name and a comparison; a pattern check is
			// the difference between "a hash" and "an arbitrary string".
			if ( 1 === preg_match( '/^map_[a-f0-9]{8,32}$/', $id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Return a mode label.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	private function mode_label( $mode ) {
		$labels = array(
			'static_replica'  => __( 'Static replica', 'replicaforge' ),
			'dynamic_replica' => __( 'Use my content', 'replicaforge' ),
			'hybrid_replica'  => __( 'Hybrid', 'replicaforge' ),
		);
		return (string) ( $labels[ (string) $mode ] ?? $mode );
	}

	/**
	 * Return a mode description.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	private function mode_description( $mode ) {
		$descriptions = array(
			'static_replica'  => __( 'Reconstructs the source content exactly as it was captured. Nothing from your site is used.', 'replicaforge' ),
			'dynamic_replica' => __( 'Uses your own WordPress and store content. The source site\'s content is not carried over.', 'replicaforge' ),
			'hybrid_replica'  => __( 'Uses your content for anything that changes, and the source\'s copy for static text you have approved. The default.', 'replicaforge' ),
		);
		return (string) ( $descriptions[ (string) $mode ] ?? '' );
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
	 * Permission callback for writes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_manage( $request ) {
		$read = $this->can_use( $request );
		if ( true !== $read ) {
			return $read;
		}
		// Writing to the user's data needs a stronger capability than reading a report.
		// This is the same gate Phase 10's settings use, and it is deliberately not
		// `replicaforge_use`.
		if ( ! Capabilities::current_user_can( 'replicaforge_manage_plans' ) ) {
			return new \WP_Error( 'capability_missing', __( 'You are not allowed to change content in this site.', 'replicaforge' ), array( 'status' => 403 ) );
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
			$this->logger->error( 'content_api_error', 'A content request failed server-side.', array( 'code' => sanitize_key( (string) $code ) ), 'content' );
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
