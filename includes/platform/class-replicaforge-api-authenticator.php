<?php
/**
 * Phase 20: API authentication and scope enforcement.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticates REST requests carrying a ReplicaForge credential, and enforces scopes.
 *
 * ### Why this is not application passwords
 *
 * WordPress application passwords are the right answer for most integrations and the wrong
 * one for the CI and monitoring cases this API exists for, for exactly one reason: **they
 * are all-or-nothing.** An application password grants everything the account can do, so a
 * job whose entire job is to poll "is this page still valid?" would also be able to trigger
 * a reconstruction, delete a template, and read every project in the workspace.
 *
 * So ReplicaForge issues its own credentials, and it issues *narrow* ones. The scope list
 * has no `admin`, no `root`, no `everything`. That is a deliberate decision about the threat
 * model rather than a gap in the list: a credential is issued to a program, and a program
 * cannot be asked to confirm intent the way a person can.
 *
 * Application passwords continue to work. This class does not intercept them — WordPress
 * authenticates them before any ReplicaForge code runs — and a request arriving without a
 * ReplicaForge credential falls through to the normal session path, where every existing
 * workspace permission check applies unchanged. Nothing here weakens the existing path; it
 * adds a second, narrower one.
 *
 * ### The authentication hook
 *
 * `determine_current_user` is WordPress's own extension point for this, and using it rather
 * than wrapping every route means:
 *
 *  - a credential works on any REST route, not only the ones this class declares, so a
 *    credential cannot be accidentally narrower than the endpoints it is issued for;
 *  - `is_user_logged_in()` and `current_user_can()` are correct inside every handler;
 *  - nonces, cookies and application passwords keep working untouched.
 *
 * ### Every request is charged three times
 *
 * 1. **Scope** — does the credential hold the scope the route requires?
 * 2. **Workspace capability** — does the *user behind the credential* actually hold the
 *    matching capability in that workspace, right now?
 * 3. **Plan operation** — has the plan already done this enough times?
 *
 * The middle one is the important one. A credential is a narrowing of a person, never a
 * promotion of one: revoking a person's access revokes the credential, and demoting them
 * demotes the credential on the very next request, with no revocation step to forget.
 */
final class Api_Authenticator {

	/**
	 * Namespace claimed by this API.
	 *
	 * @var string
	 */
	const NAMESPACE = 'replicaforge/v1';

	/**
	 * Guard flag preventing recursion.
	 *
	 * @var bool
	 */
	private $resolving = false;

	/**
	 * Credential store.
	 *
	 * @var Api_Credential_Store
	 */
	private $credentials;

	/**
	 * Rate limiter.
	 *
	 * @var Rate_Limiter
	 */
	private $limiter;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Entitlement manager, for the plan gate.
	 *
	 * @var Entitlement_Manager|null
	 */
	private $entitlements;

	/**
	 * Permission manager, for the workspace gate.
	 *
	 * @var Permission_Manager|null
	 */
	private $permissions;

	/**
	 * The credential used for the current request, if any.
	 *
	 * @var array<string, mixed>|null
	 */
	private $current = null;

	/**
	 * Scopes required by the routes hit during this request.
	 *
	 * @var array<int, string>
	 */
	private $required = array();

	/**
	 * The plan reservation taken by the last metered gate, awaiting settlement.
	 *
	 * Held here rather than returned to the caller so an error path can release it through
	 * {@see self::release()}. Handing the token to the route and hoping it is released on
	 * the failure branch is the kind of thing that works until one route forgets, and a
	 * forgotten release holds the user's allowance hostage until `RESERVATION_TTL` expires.
	 *
	 * @var array<string, mixed>
	 */
	private $reservation = array();

	/**
	 * Constructor.
	 *
	 * @param Api_Credential_Store|null $credentials  Credential store.
	 * @param Rate_Limiter|null         $limiter      Rate limiter.
	 * @param Logger|null               $logger       Logger.
	 * @param Entitlement_Manager|null  $entitlements Entitlement manager.
	 * @param Permission_Manager|null   $permissions  Permission manager.
	 */
	public function __construct( $credentials = null, $limiter = null, $logger = null, $entitlements = null, $permissions = null ) {
		$this->logger       = $logger instanceof Logger ? $logger : new Logger();
		$this->credentials  = $credentials instanceof Api_Credential_Store ? $credentials : new Api_Credential_Store( null, $this->logger );
		$this->limiter      = $limiter instanceof Rate_Limiter ? $limiter : new Rate_Limiter( $this->logger );
		$this->entitlements = $entitlements instanceof Entitlement_Manager ? $entitlements : null;
		$this->permissions  = $permissions instanceof Permission_Manager ? $permissions : null;
	}

	/* ---------------------------------------------------------------------
	 * Wiring
	 * ------------------------------------------------------------------ */

	/**
	 * Register the authentication hook.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'determine_current_user', array( $this, 'determine_user' ), 20 );
		add_filter( 'rest_pre_dispatch', array( $this, 'enforce_route_scope' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'flush_rate_limit' ), 0 );

		/**
		 * Fires once per authenticated API request, after authentication and before the
		 * handler runs.
		 *
		 * An extension that needs to know a request was made through a credential listens
		 * here rather than polling. The credential is passed as metadata only — scopes,
		 * name, prefix — and never with the token.
		 *
		 * @param array<string, mixed> $credential The credential record, minus the hash.
		 */
		do_action( 'replicaforge_api_authenticated', $this->present( $this->current ) );
	}

	/**
	 * Refuse a credential request to a route its scopes do not cover.
	 *
	 * ### This is the §34 scope-escalation defence, and it is the most important method here
	 *
	 * `determine_current_user()` makes a credential *work*; it cannot make it *narrow*. The
	 * routes registered by Phases 1–19 know nothing about scopes — they check WordPress
	 * authentication and a Phase 15 workspace capability — so once a credential resolves to a
	 * user id, all of them would accept it at full width. Without this method a
	 * `projects:read` token could trigger an analysis, import a template and approve a review.
	 *
	 * So before dispatch, the matched route's `permission_callback` is read, its method name
	 * is looked up in {@see Platform_Limits::GATE_SCOPES}, and the credential must hold one of
	 * the scopes that gate maps to. Three outcomes:
	 *
	 * - **Mapped and held** → pass. The route's own permission callback still runs afterwards,
	 *   so this narrows and never widens.
	 * - **Mapped and not held** → 403 with the scope named.
	 * - **Not mapped** → 403. Fail-closed. A route that introduces a new permission gate is
	 *   unreachable by a credential until somebody decides which scope it should need, and
	 *   the developer document reports it as `scope: null` so the omission is visible rather
	 *   than silently permissive.
	 *
	 * Requests made with an ordinary WordPress session are not touched at all, which is what
	 * keeps the admin UI and existing integrations working exactly as before.
	 *
	 * @param mixed           $result  Short-circuit result.
	 * @param mixed           $server  The REST server.
	 * @param \WP_REST_Request $request The request.
	 * @return mixed
	 */
	public function enforce_route_scope( $result, $server, $request ) {
		// Never interfere with an existing short-circuit, or with a request that is not ours.
		if ( null !== $result ) {
			return $result;
		}

		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		if ( ! $this->using_credential() ) {
			return $result;
		}

		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );
		$handle = $this->find_route( $server, $route );

		if ( null === $handle ) {
			/*
			 * No matching route at all. WordPress would answer 404 `rest_no_route` a moment
			 * later; returning our own 403 here would tell a caller with a stale URL that it
			 * has a scope problem, which it does not.
			 */
			return $result;
		}

		$gate = $this->gate_name( $handle );

		if ( '' === $gate ) {
			/*
			 * The route declares no callable permission gate - `__return_true`, or a closure.
			 * There is no declared intent to map, so a credential is refused. This is the
			 * same fail-closed rule as an unmapped gate name, applied one step earlier: an
			 * ungated route is exactly the route a credential must not reach.
			 */
			return $this->route_refusal(
				'api_route_ungated',
				__( 'This endpoint does not declare a permission, so an API credential cannot be used with it. Use a signed-in WordPress session.', 'replicaforge' ),
				403,
				array( 'route' => $route )
			);
		}

		$accepted = Platform_Limits::gate_scopes( $gate );

		if ( array() === $accepted ) {
			return $this->route_refusal(
				'api_route_unscoped',
				sprintf(
					/* translators: %s: the permission gate name. */
					__( 'This endpoint requires the "%s" permission, and no API scope has been assigned to it. This is a gap in ReplicaForge, not a problem with the credential.', 'replicaforge' ),
					$gate
				),
				403,
				array( 'route' => $route, 'gate' => $gate )
			);
		}

		$granted = array_map( 'strval', (array) ( $this->current['scopes'] ?? array() ) );
		$matched = array_values( array_intersect( $granted, $accepted ) );

		if ( array() === $matched ) {
			return $this->route_refusal(
				'scope_not_granted',
				sprintf(
					/* translators: 1: the endpoint, 2: comma-separated acceptable scopes. */
					__( 'This credential cannot use %1$s. That endpoint needs one of: %2$s.', 'replicaforge' ),
					$method . ' ' . $route,
					implode( ', ', $accepted )
				),
				403,
				array(
					'route'     => $route,
					'gate'      => $gate,
					'required'  => $accepted,
					'granted'   => $granted,
				)
			);
		}

		$this->required[] = $matched[0];

		$this->logger->info(
			'api_route_scoped',
			'An API request passed the scope gate.',
			array( 'route' => $route, 'method' => $method, 'gate' => $gate, 'scope' => $matched[0] ),
			'platform'
		);

		return $result;
	}

	/**
	 * Find the registered route handler matching a path.
	 *
	 * @param mixed  $server The REST server.
	 * @param string $route  Route path.
	 * @return array<string, mixed>|null
	 */
	private function find_route( $server, $route ) {
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) ) {
			return null;
		}

		$routes = $server->get_routes();
		$route  = '/' . ltrim( $route, '/' );

		if ( isset( $routes[ $route ] ) ) {
			return $routes[ $route ];
		}

		foreach ( $routes as $pattern => $handlers ) {
			$regex = '#^' . $pattern . '$#';

			if ( preg_match( $regex, $route ) ) {
				return $handlers;
			}
		}

		return null;
	}

	/**
	 * Read the permission gate's method name off a route handler.
	 *
	 * @param mixed $handlers A route's handler array.
	 * @return string
	 */
	private function gate_name( $handlers ) {
		$methods = array();

		if ( isset( $handlers['methods'][ \WP_REST_Server::READABLE ] ) ) {
			$methods = (array) $handlers['methods'][ \WP_REST_Server::READABLE ];
		} elseif ( isset( $handlers['methods'] ) && is_array( $handlers['methods'] ) ) {
			foreach ( $handlers['methods'] as $set ) {
				$methods = array_merge( $methods, (array) $set );
			}
		}

		foreach ( $methods as $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['permission_callback'] ) ) {
				continue;
			}

			$callback = $handler['permission_callback'];

			if ( is_array( $callback ) && isset( $callback[1] ) && is_string( $callback[1] ) ) {
				return (string) $callback[1];
			}
		}

		return '';
	}

	/**
	 * Build a route refusal response in the documented error shape.
	 *
	 * @param string              $code    Stable code.
	 * @param string              $message Message.
	 * @param int                 $status  HTTP status.
	 * @param array<string, mixed> $data    Extra data.
	 * @return \WP_REST_Response
	 */
	private function route_refusal( $code, $message, $status, array $data = array() ) {
		$this->logger->info(
			'api_route_refused',
			'An API request was refused by the scope gate.',
			array(
				'code'    => (string) $code,
				'route'   => (string) ( $data['route'] ?? '' ),
				'gate'    => (string) ( $data['gate'] ?? '' ),
				'prefix'  => (string) ( $this->current['prefix'] ?? '' ),
			),
			'platform'
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'error'   => array(
					'code'    => (string) $code,
					'message' => (string) $message,
					'details' => $data,
				),
				'meta'    => array(
					'request_id' => Request_Context::request_id(),
					/* §38: one identifier shared by the request, the workflow, the job, the
					 * webhook and the log, so a developer can paste it into the console. */
					'api_version' => self::NAMESPACE,
				),
			),
			(int) $status
		);
	}

	/**
	 * Persist the rate limiter's counters once per request.
	 *
	 * @return void
	 */
	public function flush_rate_limit() {
		$this->limiter->flush();
	}

	/**
	 * Resolve a presented credential to a user id.
	 *
	 * @param int|false $user_id The id WordPress resolved, or false.
	 * @return int|false
	 */
	public function determine_user( $user_id ) {
		// An already-authenticated request is left completely alone. This is what keeps
		// application passwords, cookies and nonces working, and it means a broken
		// credential on a session request cannot log a person out.
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		if ( $this->resolving ) {
			return $user_id;
		}

		if ( ! $this->is_our_route() ) {
			return $user_id;
		}

		$token = $this->presented_token();

		if ( '' === $token ) {
			return $user_id;
		}

		$this->resolving = true;

		try {
			$record = $this->credentials->authenticate( $token );
		} catch ( \Throwable $error ) {
			$this->logger->warning(
				'api_authenticate_failed',
				'Authenticating an API credential failed.',
				array( 'reason' => substr( get_class( $error ), 0, 60 ) ),
				'platform'
			);

			$this->resolving = false;

			return $user_id;
		}

		$this->resolving = false;

		if ( null === $record ) {
			/*
			 * A presented credential that does not resolve is refused here rather than
			 * being allowed to fall through as anonymous, because "your key is wrong" and
			 * "you are not logged in" are different problems for a developer and produce
			 * different fixes. The error is attached to the request so the REST layer can
			 * report it.
			 */
			$this->fail( 'api_credential_invalid', __( 'That API credential is not valid, or it has been revoked.', 'replicaforge' ), 401 );

			return $user_id;
		}

		$user_id = (int) ( $record['user_id'] ?? 0 );

		if ( $user_id < 1 ) {
			$this->fail( 'api_credential_orphaned', __( 'That API credential is not attached to a user any more.', 'replicaforge' ), 401 );

			return false;
		}

		if ( ! get_userdata( $user_id ) instanceof \WP_User ) {
			$this->fail( 'api_credential_orphaned', __( 'That API credential is not attached to a user any more.', 'replicaforge' ), 401 );

			return false;
		}

		$this->current = $record;

		$this->credentials->touch( (string) $record['public_id'], array( 'ip_hash' => $this->ip_hash() ) );

		return $user_id;
	}

	/* ---------------------------------------------------------------------
	 * Gates
	 * ------------------------------------------------------------------ */

	/**
	 * Require a scope, and charge it.
	 *
	 * The one method every Phase 20 route calls. It runs the three gates in order and stops
	 * at the first refusal, so a request that would fail on scope never reaches the
	 * workspace lookup — which matters for the rate limiter's fairness and means a
	 * credential cannot probe another workspace's capability names by guessing scopes.
	 *
	 * @param string              $scope        Required scope.
	 * @param array<string, mixed> $context      `workspace_id`, `project_id`.
	 * @return true|\WP_Error
	 */
	public function require_scope( $scope, array $context = array() ) {
		$this->required[] = (string) $scope;

		if ( ! Platform_Limits::is_api_scope( $scope ) ) {
			return $this->fail(
				'scope_unknown',
				__( 'That is not a scope this API offers.', 'replicaforge' ),
				500
			);
		}

		$rate = $this->limiter->check( $this->rate_key(), Platform_Limits::RATE_LIMIT_PER_MINUTE );

		if ( ! $rate['allowed'] ) {
			return $this->fail(
				'api_rate_limited',
				__( 'Too many API requests. Slow down and try again shortly.', 'replicaforge' ),
				429,
				array(
					/* Retry-After is not decoration: a well-behaved integration reads it and
					 * backs off, and one that does not gets refused sooner. */
					'retry_after' => (int) $rate['retry_after'],
					'limit'       => Platform_Limits::RATE_LIMIT_PER_MINUTE,
				)
			);
		}

		if ( null !== $this->current ) {
			$scopes = array_map( 'strval', (array) ( $this->current['scopes'] ?? array() ) );

			if ( ! in_array( $scope, $scopes, true ) ) {
				return $this->fail(
					'scope_not_granted',
					sprintf(
						/* translators: %s: the scope that was required. */
						__( 'This credential does not have the "%s" scope, so it cannot make this request.', 'replicaforge' ),
						$scope
					),
					403,
					array( 'required' => $scope, 'granted' => $scopes )
				);
			}
		}

		$definition = Platform_Limits::scope( $scope );

		$workspace_id = (string) ( $context['workspace_id'] ?? '' );
		$project_id   = (string) ( $context['project_id'] ?? '' );
		$user_id      = null !== $this->current ? (int) $this->current['user_id'] : get_current_user_id();

		/* --- gate 2: the workspace capability, for the person behind the credential --- */
		if ( '' !== $definition['workspace'] ) {
			$permissions = $this->permissions instanceof Permission_Manager ? $this->permissions : new Permission_Manager();

			$allowed = '' !== $project_id
				? $permissions->can_in_project( $user_id, $workspace_id, $project_id, (string) $definition['workspace'] )
				: $permissions->can( $user_id, $workspace_id, (string) $definition['workspace'] );

			if ( ! $allowed && ! user_can( $user_id, 'manage_options' ) ) {
				return $this->fail(
					'permission_denied',
					sprintf(
						/* translators: %s: the workspace capability that was missing. */
						__( 'The user this credential belongs to does not have the "%s" permission in this workspace.', 'replicaforge' ),
						(string) $definition['workspace']
					),
					403
				);
			}
		}

		/* --- gate 3: the plan operation, where the scope consumes a resource --- */
		if ( '' !== $definition['operation'] && $this->entitlements instanceof Entitlement_Manager ) {
			/*
			 * `begin()`, not `check()`.
			 *
			 * `check()` answers "may this proceed" and consumes nothing, so between the
			 * check and the work there is a window in which another request can also pass
			 * the same check — which is the exact race Phase 10 §39 exists to close. The
			 * reservation returned here is what makes the meter hold under concurrency,
			 * and it is committed by {@see self::settle()} or released by
			 * {@see self::release()} depending on whether the work actually happened.
			 */
			$reservation = $this->entitlements->begin(
				(string) $definition['operation'],
				$user_id,
				array(
					'project_id' => $project_id,
					'quantity'   => max( 1, (int) ( $context['quantity'] ?? 1 ) ),
					'metadata'   => array(
						'workspace_id' => $workspace_id,
						'scope'        => (string) $scope,
					),
				)
			);

			if ( empty( $reservation['allowed'] ) ) {
				return $this->fail(
					(string) ( $reservation['code'] ?? 'plan_limit_reached' ),
					(string) ( $reservation['message'] ?? __( 'The plan limit for this operation has been reached.', 'replicaforge' ) ),
					(int) ( $reservation['status'] ?? 402 ),
					(array) ( $reservation['details'] ?? array() )
				);
			}

			$this->reservation = array(
				'user_id'     => $user_id,
				'token'       => (string) ( $reservation['reservation'] ?? '' ),
				'plan_id'     => (string) ( $reservation['plan_id'] ?? '' ),
				'scope'       => (string) $scope,
				'operation'   => (string) $definition['operation'],
			);
		}

		return true;
	}

	/**
	 * Commit the reservation taken by {@see self::require_scope()}.
	 *
	 * Called after the work succeeded, so the meter records what happened rather than what
	 * was attempted. A route that returns an error must call {@see self::release()} instead —
	 * a reserved-but-never-settled operation expires harmlessly, but relying on the
	 * `RESERVATION_TTL` timeout to clean up would hold the user's allowance hostage for two
	 * hours after a failed export.
	 *
	 * @param array<string, mixed> $metadata Allowed metadata for the audit line.
	 * @return bool Whether something was committed.
	 */
	public function settle( array $metadata = array() ) {
		if ( ! $this->entitlements instanceof Entitlement_Manager || array() === $this->reservation ) {
			return false;
		}

		$reservation = $this->reservation;
		$this->reservation = array();

		$result = $this->entitlements->settle(
			(int) $reservation['user_id'],
			(string) $reservation['token'],
			(string) $reservation['plan_id'],
			/* `Audit_Log` filters metadata to the declared keys, so this cannot smuggle
			 * anything into the log even if a caller passes something unexpected. */
			array_merge( array( 'operation' => (string) $reservation['operation'] ), $metadata )
		);

		return is_array( $result ) && ! empty( $result['success'] );
	}

	/**
	 * Release a reservation without charging.
	 *
	 * @param string $reason Short machine reason.
	 * @return bool
	 */
	public function release( $reason = 'operation_failed' ) {
		if ( ! $this->entitlements instanceof Entitlement_Manager || array() === $this->reservation ) {
			return false;
		}

		$reservation = $this->reservation;
		$this->reservation = array();

		$result = $this->entitlements->fail(
			(int) $reservation['user_id'],
			(string) $reservation['token'],
			(string) $reason
		);

		return is_array( $result ) && ! empty( $result['success'] );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether the current request was made with a ReplicaForge credential.
	 *
	 * @return bool
	 */
	public function using_credential() {
		return null !== $this->current;
	}

	/**
	 * Return the current credential, reduced to safe fields.
	 *
	 * The token hash is **not** returned, and neither is the token — there is no copy of
	 * either in this object to return.
	 *
	 * @return array<string, mixed>
	 */
	public function credential() {
		return $this->present( $this->current );
	}

	/**
	 * Return whether the current credential holds a scope.
	 *
	 * @param string $scope Scope.
	 * @return bool
	 */
	public function has_scope( $scope ) {
		if ( null === $this->current ) {
			return false;
		}

		return in_array( (string) $scope, array_map( 'strval', (array) ( $this->current['scopes'] ?? array() ) ), true );
	}

	/**
	 * Return the scopes required by the routes hit so far.
	 *
	 * @return array<int, string>
	 */
	public function required_scopes() {
		return array_values( array_unique( $this->required ) );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether the current request targets this plugin's API.
	 *
	 * Checked before any credential work so an unrelated REST request — another plugin's,
	 * or a core route — pays nothing. It also keeps the `determine_current_user` filter off
	 * requests that could never be a Phase 20 request.
	 *
	 * @return bool
	 */
	private function is_our_route() {
		$route = '';

		/*
		 * `$GLOBALS['wp']` is an object, so `isset()` cannot be applied to a property-access
		 * chain: the property and the array key are fetched first and only then is the whole
		 * expression tested for null. `$wp` is also absent entirely outside a REST request.
		 */
		if ( isset( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars ) ) {
			$vars = $GLOBALS['wp']->query_vars;

			if ( is_array( $vars ) && isset( $vars['rest_route'] ) ) {
				$route = (string) $vars['rest_route'];
			}
		}

		if ( '' === $route && isset( $_GET['rest_route'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read of the route name.
			$route = (string) wp_unslash( $_GET['rest_route'] );
		}

		if ( '' === $route ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$at  = strpos( $uri, '/wp-json/' );

			if ( false === $at ) {
				return false;
			}

			$route = (string) substr( $uri, $at + 9 );
		}

		$route = ltrim( (string) wp_parse_url( $route, PHP_URL_PATH ), '/' );

		return 0 === strpos( $route, self::NAMESPACE . '/' );
	}

	/**
	 * Read the presented token.
	 *
	 * Two sources, and only two. `Authorization: Bearer …` is the documented one; the
	 * `X-ReplicaForge-Token` header exists for clients that cannot set an Authorization
	 * header, which is a real constraint for some CI runners and for `curl` behind a proxy
	 * that strips it. Neither is read from a query string — a token in a query string lands
	 * in browser history and in every proxy log between here and the caller.
	 *
	 * @return string
	 */
	private function presented_token() {
		$header = '';

		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared against a strict pattern, never stored.
			$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		}

		if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- as above.
			$header = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		if ( '' !== $header && 0 === stripos( $header, 'bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}

		if ( isset( $_SERVER['HTTP_X_REPLICAFORGE_TOKEN'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- as above.
			return trim( (string) wp_unslash( $_SERVER['HTTP_X_REPLICAFORGE_TOKEN'] ) );
		}

		return '';
	}

	/**
	 * Build the rate limit key for this request.
	 *
	 * Keyed on the credential where there is one and on the user otherwise, so one noisy
	 * integration cannot exhaust an agency's allowance for everyone else in the workspace.
	 * The workspace is deliberately *not* in the key: sharing one budget across a team is
	 * the more common and more predictable behaviour, and a per-workspace bucket would let
	 * one client's polling starve another's.
	 *
	 * @return string
	 */
	private function rate_key() {
		if ( null !== $this->current ) {
			return 'cred:' . (string) $this->current['public_id'];
		}

		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			return 'user:' . $user_id;
		}

		return 'anon:' . substr( $this->ip_hash(), 0, 16 );
	}

	/**
	 * Hash the request address.
	 *
	 * @return string
	 */
	private function ip_hash() {
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never stored raw.

		return '' === $raw ? '' : hash( 'sha256', $raw );
	}

	/**
	 * Record a refusal as a REST error and log it.
	 *
	 * @param string              $code    Stable error code.
	 * @param string              $message Human-readable message.
	 * @param int                 $status  HTTP status.
	 * @param array<string, mixed> $data    Extra response data.
	 * @return \WP_Error
	 */
	private function fail( $code, $message, $status = 400, array $data = array() ) {
		$this->logger->info(
			'api_refused',
			'An API request was refused.',
			array(
				'code'   => (string) $code,
				'status' => (int) $status,
				/* The credential's prefix, never the token, and never the full request. */
				'prefix' => (string) ( $this->current['prefix'] ?? '' ),
			),
			'platform'
		);

		return new \WP_Error( (string) $code, (string) $message, array_merge( array( 'status' => (int) $status ), $data ) );
	}

	/**
	 * Reduce a credential to the fields safe to hand out.
	 *
	 * @param mixed $record Stored record.
	 * @return array<string, mixed>
	 */
	private function present( $record ) {
		if ( ! is_array( $record ) ) {
			return array();
		}

		return array(
			'credential_id' => (string) ( $record['public_id'] ?? '' ),
			'name'          => (string) ( $record['name'] ?? '' ),
			'prefix'        => (string) ( $record['prefix'] ?? '' ),
			'scopes'        => array_values( array_map( 'strval', (array) ( $record['scopes'] ?? array() ) ) ),
			'workspace_id'  => (string) ( $record['workspace_id'] ?? '' ),
			'project_id'    => (string) ( $record['project_id'] ?? '' ),
			'user_id'       => (int) ( $record['user_id'] ?? 0 ),
			'status'        => (string) ( $record['status'] ?? '' ),
			'expires_at'    => (string) ( $record['expires_at'] ?? '' ),
		);
	}
}
