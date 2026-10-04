<?php
/**
 * Phase 10: the plans, usage, licensing, and onboarding REST surface.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The commercial read and configuration surface.
 *
 * This is a **separate controller** from {@see Rest_Api} rather than more routes
 * appended to it, for two reasons. The Phase 1 to 8 controller is the security
 * boundary for a browser submitting an analysis to be generated, and every change
 * to it is a chance to weaken that boundary. And the two have genuinely different
 * authorisation shapes: `Rest_Api` asks "may this user run a reconstruction", while
 * everything here asks "may this user see a plan, or change one" — a question about
 * a *site-wide* configuration, which is where the multi-user problem actually
 * lives.
 *
 * Nothing here writes an Elementor document, so nothing here accepts Elementor
 * data. There is no route in this file that takes a property name and a value, and
 * that is the property §25 and §52 are actually asking about. A forged plan id, a
 * forged usage counter, and a forged license state are all rejected by the same
 * rule: none of the three is ever read from the request body. The plan comes from
 * {@see Plan_Manager}, the usage from {@see Usage_Manager}, and the license from
 * the provider — and a request may only ever ask to *change* a plan to one that
 * exists in the compiled set.
 *
 * The response envelope follows §35 exactly: `success`, `data`, and a `meta`
 * carrying a `request_id`, or `success: false` with an `error` object carrying a
 * stable `code`. It is built here rather than shared with {@see Rest_Api} because
 * {@see Rest_Api} predates the requirement and changing its shape would touch
 * every Phase 1 to 8 test for no user-visible gain. That duplication is a real
 * cost and is recorded in `docs/PLANS-AND-USAGE.md` as technical debt, with the
 * note that the right fix is to standardise the older envelope and update its
 * tests, not to have two shapes forever.
 */
final class Plans_Api {

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
	 * Plan manager.
	 *
	 * @var Plan_Manager
	 */
	private $plans;

	/**
	 * Usage manager.
	 *
	 * @var Usage_Manager
	 */
	private $usage;

	/**
	 * License manager.
	 *
	 * @var License_Manager
	 */
	private $licenses;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Entitlement_Manager|null $entitlements Optional entitlement manager.
	 * @param Logger|null              $logger       Optional logger.
	 */
	public function __construct( $entitlements = null, $logger = null ) {
		$this->logger       = $logger instanceof Logger ? $logger : new Logger();
		$this->entitlements = $entitlements instanceof Entitlement_Manager
			? $entitlements
			: new Entitlement_Manager( null, null, null, $this->logger );
		$this->plans        = $this->entitlements->plans();
		$this->usage        = $this->entitlements->usage();
		$this->licenses     = $this->plans->licenses();
	}

	/**
	 * Register the Phase 10 routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->register_read_routes();
		$this->register_write_routes();
	}

	/* ---------------------------------------------------------------------
	 * Read
	 * ------------------------------------------------------------------ */

	/**
	 * GET /plans — the plan matrix and the reader's own plan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_plans( $request ) {
		$user_id = get_current_user_id();

		return $this->ok(
			array(
				'matrix'  => $this->plans->matrix(),
				'current' => $this->current_payload( $user_id ),
				'notice'  => Onboarding::product_notice(),
			)
		);
	}

	/**
	 * GET /usage — the reader's usage meter.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_usage( $request ) {
		$user_id = get_current_user_id();
		$plan    = $this->plans->current_plan( $user_id );

		return $this->ok(
			array(
				'plan'   => $plan->to_public_array(),
				'usage'  => $this->usage->meter( $user_id, $plan ),
				'recent' => $this->usage->recent( $user_id, 25 ),
				'trial'  => $this->licenses->trial( $user_id ),
			)
		);
	}

	/**
	 * GET /license — the license state.
	 *
	 * Readable by any signed-in user, because a user needs to know whether their
	 * plan is real before they act on it. It returns the state and the plan, never
	 * anything a provider considers secret — {@see License_State::to_array()} has no
	 * field that could hold one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_license( $request ) {
		$user_id = get_current_user_id();

		return $this->ok(
			array(
				'license'     => $this->licenses->state_array(),
				'diagnostics' => $this->licenses->diagnostics(),
				'plan'        => $this->current_payload( $user_id ),
			)
		);
	}

	/**
	 * GET /capabilities — the role and capability report.
	 *
	 * Administrator only, because it is the permission model itself and there is no
	 * reason for a user to see it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_capabilities( $request ) {
		return $this->ok(
			array(
				'capabilities' => Capabilities::all(),
				'roles'        => Capabilities::role_report(),
				'orphan_check' => Capabilities::orphan_check(),
				'mine'         => $this->my_capabilities(),
			)
		);
	}

	/**
	 * GET /audit — recent commercial audit entries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_audit( $request ) {
		$limit = isset( $request['limit'] ) ? (int) $request['limit'] : 50;

		$filters = array();
		if ( ! empty( $request['event'] ) ) {
			$filters['event'] = sanitize_key( (string) $request['event'] );
		}
		if ( ! empty( $request['user_id'] ) ) {
			$filters['user_id'] = (int) $request['user_id'];
		}

		return $this->ok(
			array(
				'events'  => Audit_Log::events(),
				'entries' => Audit_Log::recent( $filters, $limit ),
				'summary' => Audit_Log::summary(),
				'stored'  => Audit_Log::count(),
				'max'     => Audit_Log::MAX_ENTRIES,
			)
		);
	}

	/**
	 * GET /onboarding — onboarding state and the applicable tours.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_onboarding( $request ) {
		$user_id = get_current_user_id();
		$moment  = Onboarding::moment_for( $this->project_from_request( $request ) );

		return $this->ok(
			array(
				'site'   => Onboarding::state(),
				'user'   => Onboarding::user_state( $user_id ),
				'welcome' => Onboarding::welcome(),
				'tours'  => Onboarding::active_tours( $moment, $user_id ),
				'moment' => $moment,
				'needs_welcome' => Onboarding::needs_welcome( $user_id ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Write
	 * ------------------------------------------------------------------ */

	/**
	 * POST /onboarding — record a welcome or tour event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_onboarding( $request ) {
		$action = isset( $request['action'] ) ? sanitize_key( (string) $request['action'] ) : '';
		$user_id = get_current_user_id();

		switch ( $action ) {
			case 'seen':
				Onboarding::mark_seen( $user_id );
				break;
			case 'complete':
				Onboarding::mark_seen( $user_id );
				Onboarding::complete( false );
				break;
			case 'skip':
				Onboarding::mark_seen( $user_id );
				Onboarding::complete( true );
				break;
			case 'dismiss_tour':
				$tour = isset( $request['tour'] ) ? sanitize_key( (string) $request['tour'] ) : '';
				Onboarding::dismiss_tour( $user_id, $tour );
				break;
			case 'restore_tour':
				$tour = isset( $request['tour'] ) ? sanitize_key( (string) $request['tour'] ) : '';
				Onboarding::restore_tour( $user_id, $tour );
				break;
			default:
				return $this->error(
					'invalid_action',
					__( 'That onboarding action is not available.', 'replicaforge' ),
					400
				);
		}

		return $this->ok(
			array(
				'site' => Onboarding::state(),
				'user' => Onboarding::user_state( $user_id ),
			)
		);
	}

	/**
	 * POST /plans/site — set the plan this site runs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_site_plan( $request ) {
		$plan_id = isset( $request['plan_id'] ) ? (string) $request['plan_id'] : '';
		$before  = $this->licenses->configured_plan()->id();

		$result = $this->plans->set_site_plan( $plan_id );

		if ( empty( $result['success'] ) ) {
			return $this->error(
				'commercial_plan_not_found',
				implode( ' ', (array) $result['errors'] ),
				400,
				array( 'rejected' => $plan_id )
			);
		}

		$after = (string) $result['plan_id'];

		// Recorded after the change, with the before value captured first, so the
		// audit entry describes a real transition rather than an intention.
		Audit_Log::record(
			'plan_changed',
			array(
				'plan_id'   => $after,
				'from_plan' => $before,
				'to_plan'   => $after,
			),
			get_current_user_id()
		);

		$this->plans->flush();

		return $this->ok(
			array(
				'plan'    => $this->current_payload( get_current_user_id() ),
				'license' => $this->licenses->state_array(),
			)
		);
	}

	/**
	 * POST /plans/trial — configure trials.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_trial_settings( $request ) {
		$settings = array(
			'enabled'       => ! empty( $request['enabled'] ),
			'days'          => isset( $request['days'] ) ? (int) $request['days'] : 14,
			'plan'          => isset( $request['plan'] ) ? sanitize_key( (string) $request['plan'] ) : 'pro',
			'allow_reentry' => ! empty( $request['allow_reentry'] ),
		);

		$result = $this->plans->set_trial_settings( $settings );

		if ( empty( $result['success'] ) ) {
			return $this->error(
				'trial_settings_invalid',
				implode( ' ', (array) $result['errors'] ),
				400
			);
		}

		Audit_Log::record(
			'trial_settings_changed',
			array(
				'enabled' => (bool) $settings['enabled'],
				'days'    => (int) $settings['days'],
				'plan'    => (string) $settings['plan'],
				'by'      => get_current_user_id(),
			),
			get_current_user_id()
		);

		return $this->ok( array( 'trial' => $result['settings'] ) );
	}

	/**
	 * POST /trial — start the caller's own trial.
	 *
	 * Requires no administrative capability. A trial is something a user starts for
	 * themselves, and the server decides whether one is available.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_start_trial( $request ) {
		$user_id = get_current_user_id();
		$result  = $this->licenses->start_trial( $user_id );

		if ( empty( $result['success'] ) ) {
			return $this->error(
				'trial_unavailable',
				implode( ' ', (array) $result['errors'] ),
				403,
				array( 'trial' => $result['trial'] )
			);
		}

		Audit_Log::record(
			'trial_started',
			array(
				'plan' => (string) $result['trial']['plan'],
				'days' => $this->trial_days( $result['trial'] ),
				'by'   => $user_id,
			),
			$user_id
		);

		$this->plans->flush();

		return $this->ok(
			array(
				'trial' => $result['trial'],
				'plan'  => $this->current_payload( $user_id ),
			)
		);
	}

	/**
	 * POST /license — store a local licensing record.
	 *
	 * Administrator only, and only when the active provider is the local one. A
	 * remote provider's state is not writable through this route at all, because a
	 * provider that answers from a server cannot also accept an answer from a
	 * browser.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_license( $request ) {
		$provider = $this->licenses->provider();

		if ( ! $provider instanceof Local_License_Provider ) {
			return $this->error(
				'license_not_configurable',
				__( 'This site uses an external licensing provider, so its license state cannot be set here.', 'replicaforge' ),
				409
			);
		}

		$before = $provider->state()->effective_name();

		$record = array(
			'state'      => isset( $request['state'] ) ? (string) $request['state'] : '',
			'expires_at' => isset( $request['expires_at'] ) ? $request['expires_at'] : 0,
			'reference'  => isset( $request['reference'] ) ? (string) $request['reference'] : '',
		);

		$result = $provider->store( $record );

		if ( empty( $result['success'] ) ) {
			return $this->error(
				'license_state_invalid',
				implode( ' ', (array) $result['errors'] ),
				400
			);
		}

		$after = $provider->state()->effective_name();

		$problem = in_array( $after, array( License_State::INVALID, License_State::REVOKED, License_State::EXPIRED ), true );

		Audit_Log::record(
			$problem ? 'license_invalidated' : 'license_state_changed',
			array(
				'from_state' => $before,
				'to_state'   => $after,
				'provider'   => $provider->id(),
			),
			get_current_user_id()
		);

		$this->plans->flush();

		return $this->ok(
			array(
				'license'     => $this->licenses->state_array(),
				'diagnostics' => $this->licenses->diagnostics(),
				'plan'        => $this->current_payload( get_current_user_id() ),
			)
		);
	}

	/**
	 * GET /plans/export — export the plan configuration.
	 *
	 * Administrator only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_export( $request ) {
		$payload = $this->plans->export_configuration();

		Audit_Log::record(
			'plan_definition_exported',
			array(
				'count' => count( isset( $payload['plans'] ) ? (array) $payload['plans'] : array() ),
				'by'    => get_current_user_id(),
			),
			get_current_user_id()
		);

		return $this->ok( $payload );
	}

	/**
	 * POST /plans/import — import a plan configuration.
	 *
	 * Every value is rebuilt through `Plan_Definition`, which drops unknown keys, so
	 * there is no path from an imported value to execution. §31 asks for that
	 * explicitly and this is the mechanism.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_import( $request ) {
		$payload = $request->get_param( 'configuration' );
		if ( ! is_array( $payload ) ) {
			$payload = isset( $request['plans'] ) && is_array( $request['plans'] )
				? array( 'plans' => (array) $request['plans'] )
				: array();
		}

		$result = $this->plans->import_configuration( $payload );

		if ( empty( $result['success'] ) ) {
			return $this->error(
				'plan_import_invalid',
				implode( ' ', array_merge( (array) $result['errors'], $this->rejected_messages( $result ) ) ),
				400,
				array( 'rejected' => (array) $result['rejected'] )
			);
		}

		Audit_Log::record(
			'plan_definition_imported',
			array(
				'stored'   => (int) $result['stored'],
				'rejected' => count( (array) $result['rejected'] ),
				'by'      => get_current_user_id(),
			),
			get_current_user_id()
		);

		$this->plans->flush();

		return $this->ok(
			array(
				'imported' => (int) $result['stored'],
				'rejected' => (array) $result['rejected'],
				'matrix'   => $this->plans->matrix(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Permission callbacks
	 * ------------------------------------------------------------------ */

	/**
	 * Permission callback for read routes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_read( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'authentication_required',
				__( 'Sign in to use ReplicaForge.', 'replicaforge' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Permission callback for the caller's own routes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_use( $request ) {
		$read = $this->can_read( $request );
		if ( true !== $read ) {
			return $read;
		}
		if ( ! Capabilities::current_user_can( 'replicaforge_use' ) ) {
			return new \WP_Error(
				'capability_missing',
				__( 'Your account is not allowed to use ReplicaForge.', 'replicaforge' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Permission callback for administrator-only routes.
	 *
	 * The ReplicaForge capability is checked, and `manage_options` is accepted as
	 * well. An installation that predates the capability grants, or a site where the
	 * activation hook never ran, would otherwise lock its own administrator out of
	 * the plan settings — and a lockout here is unrecoverable without a database
	 * edit, which is a worse outcome than an administrator seeing a settings screen.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_manage( $request ) {
		$read = $this->can_read( $request );
		if ( true !== $read ) {
			return $read;
		}
		if ( Capabilities::current_user_can( 'replicaforge_manage_plans' ) ) {
			return true;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error(
			'capability_missing',
			__( 'Only an administrator can change ReplicaForge plans and licensing.', 'replicaforge' ),
			array( 'status' => 403 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Route registration
	 * ------------------------------------------------------------------ */

	/**
	 * Register the read routes.
	 *
	 * @return void
	 */
	private function register_read_routes() {
		$this->route( '/plans', 'GET', 'get_plans', 'can_use' );
		$this->route( '/usage', 'GET', 'get_usage', 'can_use' );
		$this->route( '/license', 'GET', 'get_license', 'can_read' );
		$this->route( '/capabilities', 'GET', 'get_capabilities', 'can_manage' );
		$this->route( '/audit', 'GET', 'get_audit', 'can_manage' );
		$this->route( '/onboarding', 'GET', 'get_onboarding', 'can_read' );
		$this->route( '/plans/export', 'GET', 'get_export', 'can_manage' );
	}

	/**
	 * Register the write routes.
	 *
	 * @return void
	 */
	private function register_write_routes() {
		$this->route(
			'/onboarding',
			'POST',
			'post_onboarding',
			'can_read',
			array(
				'action' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'seen', 'complete', 'skip', 'dismiss_tour', 'restore_tour' ),
				),
				'tour'   => array(
					'type'     => 'string',
					'required' => false,
					'maxLength' => 40,
				),
			)
		);

		$this->route(
			'/plans/site',
			'POST',
			'post_site_plan',
			'can_manage',
			array(
				'plan_id' => array(
					'type'        => 'string',
					'required'    => true,
					'maxLength'   => 40,
					'description' => __( 'Plan identifier from the compiled plan set.', 'replicaforge' ),
				),
			)
		);

		$this->route(
			'/plans/trial',
			'POST',
			'post_trial_settings',
			'can_manage',
			array(
				'enabled'       => array( 'type' => 'boolean', 'required' => false ),
				'days'          => array( 'type' => 'integer', 'required' => false, 'minimum' => 1, 'maximum' => 365 ),
				'plan'          => array( 'type' => 'string', 'required' => false, 'maxLength' => 40 ),
				'allow_reentry' => array( 'type' => 'boolean', 'required' => false ),
			)
		);

		$this->route( '/trial', 'POST', 'post_start_trial', 'can_use' );

		$this->route(
			'/license',
			'POST',
			'post_license',
			'can_manage',
			array(
				'state'      => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => License_State::STATES,
				),
				'expires_at' => array(
					'type'     => 'integer',
					'required' => false,
					'minimum'  => 0,
				),
				'reference'  => array(
					'type'     => 'string',
					'required' => false,
					'maxLength' => 80,
				),
			)
		);

		$this->route(
			'/plans/import',
			'POST',
			'post_import',
			'can_manage',
			array(
				'configuration' => array(
					'type'     => 'object',
					'required' => false,
				),
				'plans'         => array(
					'type'     => 'object',
					'required' => false,
				),
			)
		);
	}

	/**
	 * Register one route.
	 *
	 * @param string               $path     Route path.
	 * @param string               $method   HTTP method.
	 * @param string               $callback Callback method name.
	 * @param string               $permission Permission callback name.
	 * @param array<string, mixed> $args     Argument schema.
	 * @return void
	 */
	private function route( $path, $method, $callback, $permission, array $args = array() ) {
		register_rest_route(
			self::NAMESPACE_V1,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, $permission ),
				'args'                => $args,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Build a success response in the §35 envelope.
	 *
	 * @param array<string, mixed> $data Response data.
	 * @return \WP_REST_Response
	 */
	private function ok( array $data ) {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
				'meta'    => array( 'request_id' => Request_Context::request_id() ),
			),
			200
		);
	}

	/**
	 * Build a failure response in the §35 envelope.
	 *
	 * The status is clamped to the 4xx range for a client fault and forced to 500
	 * for a server fault. A PHP notice leaking a message into `error.message` is
	 * the risk §35 is guarding against, so anything that is not an explicit client
	 * error becomes a generic message with the detail going to the log instead.
	 *
	 * @param string               $code    Stable error code.
	 * @param string               $message User-facing message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $details Extra details.
	 * @return \WP_REST_Response
	 */
	private function error( $code, $message, $status = 400, array $details = array() ) {
		$status = (int) $status;
		if ( $status < 400 || $status > 499 ) {
			$status = 500;
		}
		if ( $status >= 500 ) {
			$this->logger->error(
				'plans_api_error',
				'A plans request failed server-side.',
				array(
					'code'    => sanitize_key( (string) $code ),
					'status'  => $status,
					'details' => array_keys( $details ),
				),
				'plans'
			);
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
				'meta'    => array( 'request_id' => Request_Context::request_id() ),
			),
			$status
		);
	}

	/**
	 * Return the current plan payload for a user.
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>
	 */
	private function current_payload( $user_id ) {
		$resolution = $this->plans->resolution( (int) $user_id );
		$plan       = $resolution['plan'];

		return array(
			'plan_id'     => $plan->id(),
			'name'        => $plan->name(),
			'description' => $plan->description(),
			'via'         => (string) $resolution['via'],
			'limits'      => $plan->limits(),
			'features'    => $plan->features(),
			'trial'       => $resolution['trial'],
			'license'     => $resolution['license'],
		);
	}

	/**
	 * Return the caller's own capabilities.
	 *
	 * @return array<string, bool>
	 */
	private function my_capabilities() {
		$out = array();
		foreach ( array_keys( Plan_Limits::CAPABILITIES ) as $capability ) {
			$out[ $capability ] = Capabilities::current_user_can( $capability );
		}
		return $out;
	}

	/**
	 * Return the project named by a request, if the user may see it.
	 *
	 * Onboarding needs a project to work out which moment the reader is at, and the
	 * reader may only supply a project id. The id is resolved through
	 * {@see Project_Access}, so a caller cannot use this route to read somebody
	 * else's project state — a project they cannot see resolves to nothing, and the
	 * tour falls back to the starting moment.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|null
	 */
	private function project_from_request( $request ) {
		$project_id = isset( $request['project_id'] ) ? (string) $request['project_id'] : '';
		if ( '' === $project_id ) {
			return null;
		}

		$project = $this->entitlements->access()->readable_project( get_current_user_id(), $project_id );
		return is_array( $project ) ? $project : null;
	}

	/**
	 * Return messages for rejected import entries.
	 *
	 * @param array<string, mixed> $result Import result.
	 * @return array<int, string>
	 */
	private function rejected_messages( array $result ) {
		$rejected = isset( $result['rejected'] ) ? (array) $result['rejected'] : array();
		if ( array() === $rejected ) {
			return array();
		}

		return array(
			sprintf(
				/* translators: %s: comma-separated list of rejected plan identifiers. */
				__( 'These entries were rejected and not stored: %s.', 'replicaforge' ),
				implode( ', ', array_map( 'strval', array_slice( $rejected, 0, 10 ) ) )
			),
		);
	}

	/**
	 * Return a trial's length in days, from its timestamps.
	 *
	 * The trial record stores a start and an expiry rather than a day count, because
	 * that is what has to be compared against the clock. The audit entry wants days,
	 * so it is derived here rather than stored twice.
	 *
	 * @param array<string, mixed> $trial Trial state.
	 * @return int
	 */
	private function trial_days( array $trial ) {
		$started = isset( $trial['started_at'] ) ? (int) $trial['started_at'] : 0;
		$expires = isset( $trial['expires_at'] ) ? (int) $trial['expires_at'] : 0;
		if ( $started < 1 || $expires <= $started ) {
			return 0;
		}
		return (int) max( 0, (int) round( ( $expires - $started ) / DAY_IN_SECONDS ) );
	}
}
