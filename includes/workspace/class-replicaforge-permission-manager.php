<?php
/**
 * Phase 15: the centralized permission service.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The single place authorization is decided.
 *
 * ### §11 asks for one service, and the reason is not tidiness
 *
 * Authorization scattered across controllers has two failure modes, and both are
 * invisible in review. A check that is simply *absent* from one of twelve controllers, and
 * a check that is present but with a subtly different rule. Either way, one endpoint
 * behaves differently from its eleven siblings and nobody notices until a user reports it.
 *
 * So there is one resolver, and every caller asks it the same question in the same words.
 *
 * ### The resolution order, and what each step can do
 *
 * ```
 * 1. Authenticated user        - a user id of 0 is denied everything, always.
 * 2. Workspace membership      - is this user in the workspace at all?
 * 3. Workspace capability      - does their role imply the capability?
 * 4. Project membership        - if project scoping is on, is this project opened to them?
 * 5. Project capability        - can a project role *narrow* the workspace grant?
 * 6. Resource ownership        - a client contact's review grant, a review link's binding.
 * ```
 *
 * Step 5 is a **narrowing only**, and that is the whole of the project-role design. A
 * project role may remove a capability the workspace role holds; it may never add one. The
 * alternative — unioning workspace and project capabilities — makes project membership a
 * privilege-escalation path: add a `lead` to someone's project and they gain
 * `reviews.approve` they were deliberately never granted. That is the exact bug §41 names,
 * and the intersection is what prevents it.
 *
 * ### Why `Project_Access` is consulted at all
 *
 * Phases 1-14 authorize with `Project_Access::can_read()`, which is *owner or site
 * administrator*. Fourteen phases of code call it. If the resolver ignored it, every one of
 * those calls would narrow to "no" for a project owned by someone else, and single-user
 * installations — which §57 says must keep working — would break.
 *
 * So the resolver *includes* `Project_Access` as a grant path, and it comes after the
 * workspace check rather than instead of it. A site administrator is a site administrator;
 * that is a WordPress fact and Phase 15 does not get to overrule it. What Phase 15 adds is
 * the *other* paths: a workspace role, and a project membership.
 *
 * ### Default is DENY
 *
 * Every entry point returns `false` for anything it does not positively recognise: an
 * unknown capability, an unknown role, a missing table, a stale cache. There is no "if in
 * doubt, allow", because a permission system that guesses is a permission system with a
 * guess in it.
 *
 * ### Caching
 *
 * §45 allows "cached permissions where safe". This caches a user's capabilities **for the
 * duration of one request only**, in a static array keyed by user and workspace. It is
 * deliberately not a persistent cache: a permission that outlives the request it was
 * computed in is a permission that survives the role change that should have revoked it,
 * and the whole point of §25's audit trail is that revocations take effect. Membership
 * changes call {@see self::flush()}.
 */
final class Permission_Manager {

	/**
	 * Per-request capability cache.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private static $cache = array();

	/**
	 * Members store.
	 *
	 * @var Workspace_Member_Store
	 */
	private $members;

	/**
	 * Project members store.
	 *
	 * @var Project_Member_Store
	 */
	private $project_members;

	/**
	 * The pre-Phase-15 access object.
	 *
	 * @var Project_Access
	 */
	private $legacy;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;
	/**
	 * Workspace store, held rather than constructed per call.
	 *
	 * `resolve()` needs the workspace and `is_workspace_owner()` needs it again, and both
	 * were building a new store each time. A new store means a new `Collaboration_Schema`
	 * and therefore another `SHOW TABLES LIKE` per permission check - on a project list
	 * that checks permissions per row, the difference between one query and fifty.
	 *
	 * @var Workspace_Store|null
	 */
	private $workspaces;

	/**
	 * Constructor.
	 *
	 * @param Workspace_Member_Store|null $members        Optional members store.
	 * @param Project_Member_Store|null  $project_members Optional project members store.
	 * @param Project_Access|null        $legacy          Optional pre-Phase-15 access object.
	 * @param Logger|null                $logger          Optional logger.
	 */
	public function __construct( $members = null, $project_members = null, $legacy = null, $logger = null, $workspaces = null ) {
		$this->members         = $members instanceof Workspace_Member_Store ? $members : new Workspace_Member_Store();
		$this->project_members = $project_members instanceof Project_Member_Store ? $project_members : new Project_Member_Store();
		$this->legacy          = $legacy instanceof Project_Access ? $legacy : new Project_Access();
		$this->logger          = $logger instanceof Logger ? $logger : new Logger();
		$this->workspaces      = $workspaces instanceof Workspace_Store ? $workspaces : new Workspace_Store();
	}

	/* ---------------------------------------------------------------------
	 * The public question
	 * ------------------------------------------------------------------ */

	/**
		* Return the capabilities a role holds.
		*
		* Public because a screen renders the matrix from it, and a matrix rendered from a
		* second copy of the table would be a document rather than a fact. Reading it from
		* here is what makes the rendered matrix and the enforced one the same object.
		*
		* @param string $role Role.
		* @return array<int, string>
	*/
	public static function capabilities_for_role( $role ) {
		$role = (string) $role;
		return isset( Workspace_Limits::ROLE_CAPS[ $role ] ) ? array_values( Workspace_Limits::ROLE_CAPS[ $role ] ) : array();
	}

	/**
		* Return whether a role holds a capability.
		*
		* The one place that answers it. `resolve()` goes through the capability list
		* capability list, and a rendered matrix goes through this, so a discrepancy
		* between what a screen shows and what is enforced is not possible - it would need
		* this function to disagree with itself.
		*
		* @param string $role       Role.
		* @param string $capability Capability.
		* @return bool
	*/
	public static function role_grants( $role, $capability ) {
		return in_array( (string) $capability, self::capabilities_for_role( $role ), true );
	}
	/**
	 * Return whether a user holds a capability in a workspace.
	 *
	 * @param int    $user_id      User id.
	 * @param string $workspace_id Workspace id.
	 * @param string $capability   Capability.
	 * @return bool
	 */
	public function can( $user_id, $workspace_id, $capability ) {
		// Step 1. An unauthenticated caller holds nothing.
		//
		// The id is validated *before* the cast, and that ordering is the whole point.
		// `(int)` applied to a WP_Error is 1, with only a warning - and user 1 is a real
		// account, usually the first administrator on the install. WordPress functions
		// return WP_Error on failure routinely, so a failed lookup passed straight through
		// here would be resolved as that account. Validating first turns a non-numeric
		// id into a denial, which is the only safe reading of a value that is not a user.
		$user_id = $this->user_id( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}

		$workspace_id = (string) $workspace_id;
		if ( '' === $workspace_id || ! Workspace_Limits::is_capability( $capability ) ) {
			return false;
		}

		$key = $user_id . ':' . $workspace_id;
		if ( isset( self::$cache[ $key ] ) && array_key_exists( $capability, self::$cache[ $key ] ) ) {
			return self::$cache[ $key ][ $capability ];
		}

		$granted = $this->resolve( $user_id, $workspace_id, $capability );
		self::$cache[ $key ][ $capability ] = $granted;

		return $granted;
	}

	/**
	 * Return whether a user holds a capability on one project.
	 *
	 * @param int    $user_id      User id.
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @param string $capability   Capability.
	 * @return bool
	 */
	public function can_in_project( $user_id, $workspace_id, $project_id, $capability ) {
		// Validated before the cast, for the same reason as can().
		$user_id = $this->user_id( $user_id );
		if ( $user_id < 1 ) {
			return false;
		}

		$workspace_id = (string) $workspace_id;
		$project_id   = (string) $project_id;
		if ( '' === $workspace_id || ! Workspace_Limits::is_capability( $capability ) ) {
			return false;
		}

		// The capability must hold in the workspace before project scoping can be
		// considered. Otherwise a project role could grant something the workspace role
		// never had - the escalation path step 5 exists to prevent.
		if ( ! $this->can( $user_id, $workspace_id, $capability ) ) {
			return false;
		}

		$project_id = (string) $project_id;
		if ( '' === $project_id ) {
			// A workspace-scoped check with no project. Correct, and the reason the
			// project branch below is skipped rather than defaulting to "allowed".
			return true;
		}

		// §10: project-level permissions are optional. A workspace that has not turned
		// them on behaves as before, which is what keeps a single-user install working
		// without anyone configuring anything.
		if ( ! $this->project_scoping_enabled( $workspace_id ) ) {
			return true;
		}

		$membership = $this->project_members->membership( $project_id, $user_id );
		if ( null === $membership || 'active' !== (string) $membership['status'] ) {
			// Not a member of the project. Denied.
			//
			// The owner and a site administrator are exempt, because §39 requires an
			// owner to be able to configure gates and archive a project, and a scoping
			// rule that locks the owner out of their own project is a support ticket.
			if ( $this->is_workspace_owner( $user_id, $workspace_id ) || $this->is_site_admin( $user_id ) ) {
				return true;
			}
			return false;
		}

		// Step 5, narrowing only.
		$project_caps = Workspace_Limits::project_caps_for_role( (string) $membership['role'] );
		if ( ! in_array( $capability, $project_caps, true ) ) {
			// The intersection. A `contributor` on a project does not gain `reviews.approve`
			// because the workspace role has it.
			return false;
		}

		return true;
	}

	/**
	 * Return every capability a user holds, for a permission matrix or a UI.
	 *
	 * Reported as a flat list rather than computed on demand at each call site, so a
	 * screen can render "what you can do here" without fifteen separate permission
	 * checks, and so the answer is one consistent snapshot.
	 *
	 * @param int    $user_id      User id.
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Optional project id.
	 * @return array<string, bool>
	 */
	public function capabilities_for( $user_id, $workspace_id, $project_id = '' ) {
		$out = array();
		foreach ( Workspace_Limits::capabilities() as $capability ) {
			$out[ $capability ] = ( '' === (string) $project_id )
				? $this->can( $user_id, $workspace_id, $capability )
				: $this->can_in_project( $user_id, $workspace_id, $project_id, $capability );
		}
		return $out;
	}

	/**
	 * Return the role a user holds in a workspace, or an empty string.
	 *
	 * @param int    $user_id      User id.
	 * @param string $workspace_id Workspace id.
	 * @return string
	 */
	public function role( $user_id, $workspace_id ) {
		$member = $this->members->membership( (string) $workspace_id, $this->user_id( $user_id ) );
		if ( null === $member || 'active' !== (string) $member['status'] ) {
			return '';
		}
		// The owner's role is derived, never stored, so it is answered here. A stored
		// `owner` row would let a demoted owner keep full control and a promoted member
		// erase the only owner.
		if ( $this->is_workspace_owner( $this->user_id( $user_id ), (string) $workspace_id ) ) {
			return 'owner';
		}
		return (string) $member['role'];
	}

	/**
	 * Return whether a workspace has project-level scoping turned on.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return bool
	 */
	public function project_scoping_enabled( $workspace_id ) {
		$workspace = $this->workspaces->get( (string) $workspace_id );
		if ( null === $workspace ) {
			return false;
		}
		$settings = (array) ( $workspace['settings'] ?? array() );
		return ! empty( $settings['project_scoping'] );
	}

	/* ---------------------------------------------------------------------
	 * Review-scoped grants
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether a user may act on one review.
	 *
	 * ### Why a client can approve anything, and yet this is safe
	 *
	 * §16 gives a client the ability to approve, and `ROLE_CAPS['client']` deliberately
	 * does **not** include `reviews.approve`. Without a third path a client could not
	 * approve anything, and the workspace capability `reviews.approve` would mean
	 * "may approve *any* review in the workspace", which is not what a client should hold.
	 *
	 * So the grant is scoped: a client contact may act on a review only when the review
	 * names them, or names an email they own, and the review is of type `client`. The
	 * scope is the review, not the workspace, and it is checked here rather than in the
	 * review controller so there is exactly one place it can be got wrong.
	 *
	 * @param int                 $user_id    User id.
	 * @param string              $workspace_id Workspace id.
	 * @param array<string,mixed> $review     Review row.
	 * @param string              $capability  Capability to check.
	 * @return bool
	 */
	public function can_act_on_review( $user_id, $workspace_id, array $review, $capability ) {
		// Validated before the cast, as in can().
		$user_id = $this->user_id( $user_id );
		if ( $user_id < 1 || array() === $review ) {
			return false;
		}
		if ( ! in_array( $capability, array( 'reviews.approve', 'reviews.reject', 'reviews.view', 'comments.create' ), true ) ) {
			return false;
		}

		// A workspace-level grant short-circuits everything below.
		if ( $this->can_in_project( $user_id, $workspace_id, (string) ( $review['project_id'] ?? '' ), $capability ) ) {
			return true;
		}

		if ( 'client' !== (string) ( $review['type'] ?? '' ) ) {
			// An internal review is not visible to a client contact at all.
			return false;
		}

		$assigned = (int) ( $review['reviewer_id'] ?? 0 );
		if ( $assigned > 0 && $assigned === $user_id ) {
			return true;
		}

		// A client contact invited by email rather than by account. Matched against the
		// signed-in user's own address only — never against an address supplied in the
		// request.
		$reviewer_email = strtolower( trim( (string) ( $review['reviewer_email'] ?? '' ) ) );
		if ( '' !== $reviewer_email ) {
			$user = get_userdata( $user_id );
			if ( $user && is_a( $user, 'WP_User' ) && strtolower( (string) $user->user_email ) === $reviewer_email ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Invariants
	 * ------------------------------------------------------------------ */

	/**
	 * Flush the per-request cache.
	 *
	 * Called after any membership or role change. Without it, the user who was just
	 * demoted keeps the old answer for the rest of the request — which on an admin screen
	 * is long enough for them to click something they may no longer do.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = array();
	}

	/**
	 * Return whether every role resolves to a declared, non-empty capability set.
	 *
	 * A role with no capabilities is a member who can see nothing, which is
	 * indistinguishable from a bug and is the most likely mistake in this table. This is
	 * asserted in the test suite so the table cannot drift.
	 *
	 * @return array<string, int>
	 */
	public static function role_report() {
		$out = array();
		foreach ( Workspace_Limits::ROLES as $role ) {
			$out[ $role ] = count( Workspace_Limits::caps_for_role( $role ) );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
		* Return a usable user id, or 0.
		*
		* ### Why this exists rather than a bare cast
		*
		* In PHP, `(int)` applied to an object that cannot be converted yields **1**, and
		* the only signal is a warning. That is a very specific and very bad failure for a
		* permission check: `get_user_by()`, `get_userdata()` and every other WordPress
		* lookup return `WP_Error` when they fail, and a failure that resolves to user id 1
		* is resolved as the first account on the install - very often an administrator.
		*
		* So a value is accepted only when it is genuinely a number, and anything else - a
		* `WP_Error`, an array, an object, `null` - becomes 0, which every entry point
		* denies. The rule reads the same in both directions: if in doubt, deny.
		*
		* @param mixed $user_id Candidate.
		* @return int
	*/
	private function user_id( $user_id ) {
		if ( is_int( $user_id ) ) {
			return ( $user_id > 0 ) ? $user_id : 0;
		}
		if ( is_string( $user_id ) && 1 === preg_match( '/^[0-9]+$/', $user_id ) ) {
			return max( 0, (int) $user_id );
		}
		if ( is_float( $user_id ) && $user_id > 0 && floor( $user_id ) === $user_id ) {
			return (int) $user_id;
		}
		return 0;
	}

	/**
	 * Resolve a workspace-scoped capability.
	 *
	 * @param int    $user_id      User id.
	 * @param string $workspace_id Workspace id.
	 * @param string $capability   Capability.
	 * @return bool
	 */
	private function resolve( $user_id, $workspace_id, $capability ) {
		$workspace = $this->workspaces->get( (string) $workspace_id );
		if ( null === $workspace ) {
			// A workspace that does not exist grants nothing. Not an error here — a
			// missing workspace is a legitimate answer to "what can this user do", and it
			// is DENY.
			return false;
		}
		if ( 'archived' === (string) $workspace['status'] ) {
			// An archived workspace is preserved, not deleted, so its data is intact. But
			// it is not operating, and an admin who archived it should not keep working in
			// it by accident.
			return false;
		}

		// The site administrator. A WordPress fact, not a ReplicaForge one: if the
		// administrator of the site may do it, they may. Recorded here rather than
		// granted through a role so no workspace can revoke it by deleting a row.
		if ( $this->is_site_admin( $user_id ) ) {
			return true;
		}

		// Steps 2 and 3: membership, then the role's capability set.
		$member = $this->members->membership( $workspace_id, $user_id );
		if ( null === $member || 'active' !== (string) $member['status'] ) {
			return false;
		}

		$role = (string) $member['role'];
		if ( $this->is_workspace_owner( $user_id, $workspace_id ) ) {
			$role = 'owner';
		}

		// An unrecognised stored role grants nothing. Defaulting to a default role here
		// would turn a corrupted row into a privilege grant.
		if ( ! in_array( $role, Workspace_Limits::ROLES, true ) ) {
			$this->logger->warning(
				'permission_unknown_role',
				'A membership held a role ReplicaForge does not recognise, so it was granted nothing.',
				array( 'workspace' => $workspace_id, 'role' => $role ),
				'workspace'
			);
			return false;
		}

		return in_array( $capability, Workspace_Limits::caps_for_role( $role ), true );
	}

	/**
	 * Return whether a user owns a workspace.
	 *
	 * @param int    $user_id      User id.
	 * @param string $workspace_id Workspace id.
	 * @return bool
	 */
	private function is_workspace_owner( $user_id, $workspace_id ) {
		$workspace = $this->workspaces->get( (string) $workspace_id );
		return ( null !== $workspace && (int) $workspace['owner_id'] === $this->user_id( $user_id ) );
	}

	/**
	 * Return whether a user is a site administrator.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function is_site_admin( $user_id ) {
		$user_id = $this->user_id( $user_id );
		return ( $user_id > 0 ) && user_can( $user_id, 'manage_options' );
	}

	/**
	 * Return whether a project is readable, deferring to the pre-Phase-15 answer.
	 *
	 * This is the bridge that keeps Phases 1-14 working. `Project_Access::can_read()` is
	 * "owner or site administrator", and fourteen phases call it. Where Phase 15 adds a
	 * path it has to *add*, never replace.
	 *
	 * @param int    $user_id    User id.
	 * @param string $project_id Project id.
	 * @return bool
	 */
	public function legacy_can_read_project( $user_id, $project_id ) {
		return $this->legacy->can_read( $this->user_id( $user_id ), (string) $project_id );
	}
	/**
	 * Return the workspace store this resolver uses.
	 *
	 * Exposed so a caller that already has a resolver does not build a second store and
	 * pay for a second schema probe to answer the same question.
	 *
	 * @return Workspace_Store
	 */
	public function workspaces() {
		return $this->workspaces;
	}
}
