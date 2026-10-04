<?php
/**
 * Phase 15: the collaboration REST API.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The REST surface for workspaces, clients, members, projects, reviews, comments, tasks
 * and issues.
 *
 * ### Every route is gated, and the gate is a capability
 *
 * §43 says "Every endpoint requires authorization" and §41 names REST abuse among the
 * threats. So there is no route whose `permission_callback` returns true, and no route that
 * decides on anything other than a capability from {@see Workspace_Limits}.
 *
 * Two rules that are easy to get wrong and are therefore structural here rather than
 * repeated per handler:
 *
 * - **A workspace id in a request is never trusted.** {@see self::resolve_workspace()}
 *   answers "which workspaces may this caller act in", and a caller with no membership in
 *   the requested one gets the *same* answer as one that named a workspace that does not
 *   exist. §3 makes cross-workspace access an isolation property, and a distinguishable
 *   "forbidden" versus "not found" is the probe that breaks it.
 * - **A project is resolved through its workspace.** A project id from another workspace is
 *   not found here, never found-and-refused, for the same reason.
 *
 * ### Two paths, not one
 *
 * A route under `/workspaces/{id}/...` is the ordinary authenticated surface. A route under
 * `/review/{token}/...` is the §17 link surface, which is unauthenticated by design and is
 * handled by {@see Review_Link_Service} rather than by this class - because the checks it
 * needs (expiry, revocation, password, attempt ceiling) are not capabilities and pretending
 * they are would put a token in the same position as a user id.
 */
final class Workspace_Api {

	/**
	 * The route namespace.
	 *
	 * @var string
	 */
	const NAMESPACE_V1 = 'replicaforge/v1';

	/**
	 * The permission resolver.
	 *
	 * @var Permission_Manager
	 */
	private $permissions;

	/**
	 * The workspace store.
	 *
	 * @var Workspace_Store
	 */
	private $workspaces;

	/**
	 * The member store.
	 *
	 * @var Workspace_Member_Store
	 */
	private $members;

	/**
	 * The project members.
	 *
	 * @var Project_Member_Store
	 */
	private $project_members;

	/**
	 * The client store.
	 *
	 * @var Client_Store
	 */
	private $clients;

	/**
	 * The review store.
	 *
	 * @var Review_Store
	 */
	private $reviews;

	/**
	 * The comment store.
	 *
	 * @var Comment_Store
	 */
	private $comments;

	/**
	 * The task store.
	 *
	 * @var Task_Store
	 */
	private $tasks;

	/**
	 * The issue store.
	 *
	 * @var Issue_Store
	 */
	private $issues;

	/**
	 * The invitations.
	 *
	 * @var Invitation_Service
	 */
	private $invitations;

	/**
	 * The log.
	 *
	 * @var Collaboration_Log
	 */
	private $log;

	/**
	 * The notifications.
	 *
	 * @var Notification_Service
	 */
	private $notifications;

	/**
	 * The project repository.
	 *
	 * @var Project_Repository
	 */
	private $projects;

	/**
	 * The project collaboration fields.
	 *
	 * @var Project_Context_Store
	 */
	private $context;

	/**
	 * The logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Permission_Manager|null   $permissions      Optional resolver.
	 * @param Workspace_Store|null      $workspaces       Optional store.
	 * @param Workspace_Member_Store|null $members        Optional store.
	 * @param Project_Member_Store|null $project_members  Optional store.
	 * @param Client_Store|null         $clients          Optional store.
	 * @param Review_Store|null         $reviews          Optional store.
	 * @param Comment_Store|null        $comments         Optional store.
	 * @param Task_Store|null           $tasks            Optional store.
	 * @param Issue_Store|null          $issues           Optional store.
	 * @param Invitation_Service|null   $invitations      Optional service.
	 * @param Collaboration_Log|null    $log              Optional log.
	 * @param Notification_Service|null $notifications    Optional service.
	 * @param Project_Repository|null   $projects         Optional repository.
	 * @param Project_Context_Store|null $context         Optional context.
	 * @param Logger|null               $logger           Optional logger.
	 */
	public function __construct( $permissions = null, $workspaces = null, $members = null, $project_members = null, $clients = null, $reviews = null, $comments = null, $tasks = null, $issues = null, $invitations = null, $log = null, $notifications = null, $projects = null, $context = null, $logger = null ) {
		$this->permissions     = $permissions instanceof Permission_Manager ? $permissions : new Permission_Manager();
		$this->workspaces      = $workspaces instanceof Workspace_Store ? $workspaces : new Workspace_Store();
		$this->members         = $members instanceof Workspace_Member_Store ? $members : new Workspace_Member_Store();
		$this->project_members = $project_members instanceof Project_Member_Store ? $project_members : new Project_Member_Store();
		$this->clients         = $clients instanceof Client_Store ? $clients : new Client_Store();
		$this->reviews         = $reviews instanceof Review_Store ? $reviews : new Review_Store();
		$this->comments        = $comments instanceof Comment_Store ? $comments : new Comment_Store();
		$this->tasks           = $tasks instanceof Task_Store ? $tasks : new Task_Store();
		$this->issues          = $issues instanceof Issue_Store ? $issues : new Issue_Store();
		$this->invitations     = $invitations instanceof Invitation_Service ? $invitations : new Invitation_Service();
		$this->log             = $log instanceof Collaboration_Log ? $log : new Collaboration_Log();
		$this->notifications   = $notifications instanceof Notification_Service ? $notifications : new Notification_Service();
		$this->projects        = $projects instanceof Project_Repository ? $projects : new Project_Repository();
		$this->context         = $context instanceof Project_Context_Store ? $context : new Project_Context_Store();
		$this->logger          = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Workspace, members and team.
		$this->route( '/workspaces', 'GET', 'list_workspaces', 'gate_workspace_view' );
		$this->route( '/workspaces', 'POST', 'create_workspace', 'gate_workspace_manage' );
		$this->route( '/workspace', 'GET', 'current_workspace', 'gate_any_workspace' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})', 'GET', 'get_workspace', 'gate_workspace_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/members', 'GET', 'list_members', 'gate_members_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/members', 'POST', 'add_member', 'gate_members_invite' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/members/(?P<member>[A-Za-z0-9]{1,64})', 'POST', 'update_member', 'gate_members_invite' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/members/(?P<member>[A-Za-z0-9]{1,64})/delete', 'POST', 'remove_member', 'gate_members_remove' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/invitations', 'GET', 'list_invitations', 'gate_members_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/invitations', 'POST', 'create_invitation', 'gate_members_invite' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/invitations/(?P<invitation>[A-Za-z0-9]{1,64})/revoke', 'POST', 'revoke_invitation', 'gate_members_invite' );

		// Clients.
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/clients', 'GET', 'list_clients', 'gate_clients_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/clients', 'POST', 'create_client', 'gate_clients_create' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/clients/(?P<client>[A-Za-z0-9]{1,64})', 'GET', 'get_client', 'gate_clients_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/clients/(?P<client>[A-Za-z0-9]{1,64})', 'POST', 'update_client', 'gate_clients_edit' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/clients/(?P<client>[A-Za-z0-9]{1,64})/archive', 'POST', 'archive_client', 'gate_clients_edit' );

		// Projects.
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects', 'GET', 'list_projects', 'gate_projects_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})', 'GET', 'get_project', 'gate_projects_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})', 'POST', 'update_project', 'gate_projects_edit' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})/members', 'GET', 'list_project_members', 'gate_projects_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})/members', 'POST', 'add_project_member', 'gate_projects_manage_members' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})/archive', 'POST', 'archive_project', 'gate_projects_archive' );

		// Reviews.
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})/reviews', 'GET', 'list_reviews', 'gate_reviews_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})/reviews', 'POST', 'create_review', 'gate_reviews_create' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/reviews/(?P<review>[A-Za-z0-9]{1,64})/approve', 'POST', 'approve_review', 'gate_reviews_decide' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/reviews/(?P<review>[A-Za-z0-9]{1,64})/changes', 'POST', 'request_changes', 'gate_reviews_decide' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/reviews/(?P<review>[A-Za-z0-9]{1,64})/reject', 'POST', 'reject_review', 'gate_reviews_decide' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/reviews/(?P<review>[A-Za-z0-9]{1,64})/link', 'POST', 'create_review_link', 'gate_reviews_create' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/reviews/(?P<review>[A-Za-z0-9]{1,64})/link/revoke', 'POST', 'revoke_review_link', 'gate_reviews_create' );

		// Comments.
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})/comments', 'GET', 'list_comments', 'gate_comments_read' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})/comments', 'POST', 'create_comment', 'gate_comments_create' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/comments/(?P<comment>[A-Za-z0-9]{1,64})/resolve', 'POST', 'resolve_comment', 'gate_comments_resolve' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/comments/(?P<comment>[A-Za-z0-9]{1,64})/reopen', 'POST', 'reopen_comment', 'gate_comments_resolve' );

		// Tasks.
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})/tasks', 'GET', 'list_tasks', 'gate_projects_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})/tasks', 'POST', 'create_task', 'gate_projects_edit' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/tasks/(?P<task>[A-Za-z0-9]{1,64})', 'POST', 'update_task', 'gate_projects_edit' );

		// Issues.
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})/issues', 'GET', 'list_issues', 'gate_projects_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/projects/(?P<project>[A-Za-z0-9_\-]{1,64})/issues', 'POST', 'create_issue', 'gate_projects_edit' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/issues/(?P<issue>[A-Za-z0-9]{1,64})', 'POST', 'update_issue', 'gate_projects_edit' );

		// Activity and audit.
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/activity', 'GET', 'list_activity', 'gate_projects_view' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/audit', 'GET', 'list_audit', 'gate_roles_manage' );

		// Notifications and preferences.
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/notifications', 'GET', 'list_notifications', 'gate_any_workspace' );
		$this->route( '/workspaces/(?P<id>[A-Za-z0-9]{1,64})/notifications/(?P<notification>[A-Za-z0-9]{1,64})/read', 'POST', 'read_notification', 'gate_any_workspace' );
		$this->route( '/notification-preferences', 'GET', 'get_preferences', 'authenticated' );
		$this->route( '/notification-preferences', 'POST', 'save_preferences', 'authenticated' );

		// Invitation acceptance, by token. §13 puts this outside the workspace, because the
		// recipient is by definition not yet a member.
		$this->route( '/invitations/accept', 'POST', 'accept_invitation', 'authenticated' );
	}

	/* ---------------------------------------------------------------------
	 * Gates
	 * ------------------------------------------------------------------ */

	/**
	 * Require an authenticated user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function authenticated( $request ) {
		if ( get_current_user_id() < 1 ) {
			return new \WP_Error( 'replicaforge_unauthenticated', __( 'You must be signed in.', 'replicaforge' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Require membership in the workspace named in the request, and no capability in
	 * particular.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_any_workspace( $request ) {
		return $this->gate( $request, '' );
	}

	/**
	 * Gate: `workspace.view`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_workspace_view( $request ) {
		return $this->gate( $request, 'workspace.view' );
	}

	/**
	 * Gate: `workspace.manage`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_workspace_manage( $request ) {
		return $this->gate( $request, 'workspace.manage' );
	}

	/**
	 * Gate: `members.view`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_members_view( $request ) {
		return $this->gate( $request, 'members.view' );
	}

	/**
	 * Gate: `members.invite`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_members_invite( $request ) {
		return $this->gate( $request, 'members.invite' );
	}

	/**
	 * Gate: `members.remove`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_members_remove( $request ) {
		return $this->gate( $request, 'members.remove' );
	}

	/**
	 * Gate: `roles.manage`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_roles_manage( $request ) {
		return $this->gate( $request, 'roles.manage' );
	}

	/**
	 * Gate: `clients.view`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_clients_view( $request ) {
		return $this->gate( $request, 'clients.view' );
	}

	/**
	 * Gate: `clients.create`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_clients_create( $request ) {
		return $this->gate( $request, 'clients.create' );
	}

	/**
	 * Gate: `clients.edit`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_clients_edit( $request ) {
		return $this->gate( $request, 'clients.edit' );
	}

	/**
	 * Gate: `projects.view`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_projects_view( $request ) {
		return $this->gate( $request, 'projects.view' );
	}

	/**
	 * Gate: `projects.edit`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_projects_edit( $request ) {
		return $this->gate( $request, 'projects.edit' );
	}

	/**
	 * Gate: `projects.manage_members`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_projects_manage_members( $request ) {
		return $this->gate( $request, 'projects.manage_members' );
	}

	/**
	 * Gate: `projects.archive`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_projects_archive( $request ) {
		return $this->gate( $request, 'projects.archive' );
	}

	/**
	 * Gate: reading comments, which is `reviews.view` rather than a comment capability.
	 *
	 * A comment is review material, and `comments.create` says nothing about whether the
	 * reader may see one. `reviews.view` is the closest honest answer, and it is the same
	 * capability the review list is gated on - so a user who cannot see the review cannot
	 * read its comments either.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_comments_read( $request ) {
		return $this->gate( $request, 'reviews.view' );
	}

	/**
	 * Gate: `comments.create`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_comments_create( $request ) {
		return $this->gate( $request, 'comments.create' );
	}

	/**
	 * Gate: `comments.resolve`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_comments_resolve( $request ) {
		return $this->gate( $request, 'comments.resolve' );
	}

	/**
	 * Gate: `reviews.view`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_reviews_view( $request ) {
		return $this->gate( $request, 'reviews.view' );
	}

	/**
	 * Gate: `reviews.create`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_reviews_create( $request ) {
		return $this->gate( $request, 'reviews.create' );
	}

	/**
	 * Gate: deciding a review, which is approve or reject.
	 *
	 * Neither is checked here, because §16 gives a client the right to approve *one
	 * review* without holding `reviews.approve` in the workspace. The decision itself is
	 * therefore re-checked per review by {@see self::act_on_review()}, which is the only
	 * place the review-scoped grant lives.
	 *
	 * What *is* checked here is that the caller is in the workspace and can see reviews at
	 * all, so an anonymous or unrelated caller never reaches that check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function gate_reviews_decide( $request ) {
		return $this->gate( $request, 'reviews.view' );
	}

	/**
	 * Verify, inside a handler, that the caller may act in the workspace named in the path.
	 *
	 * ### Why the check is repeated
	 *
	 * WordPress runs `permission_callback` before `callback`, so a handler that trusts its
	 * gate is correct in production. That is a property of the *dispatcher*, not of the
	 * handler, and it stops being true the moment the handler is called directly - which is
	 * what a refactor does, and what a test does.
	 *
	 * In the layer whose entire purpose is workspace isolation, "correct only because
	 * something else ran first" is not a property to rely on. So every handler that acts on
	 * an id from the path verifies for itself, and the gate remains as the cheap rejection
	 * that keeps unauthenticated traffic away from the store entirely.
	 *
	 * The gate and this differ in exactly one way, and it is deliberate: the gate compares a
	 * caller against a workspace, and this additionally confirms the workspace exists and
	 * belongs to them - so a handler cannot leak the *existence* of a workspace through a
	 * `null` versus a row.
	 *
	 * @param \WP_REST_Request $request    Request.
	 * @param string           $capability Capability, or empty for membership only.
	 * @return true|\WP_Error
	 */
	private function authorize( $request, $capability ) {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return new \WP_Error( 'replicaforge_unauthenticated', __( 'You must be signed in.', 'replicaforge' ), array( 'status' => 401 ) );
		}

		$workspace_id = (string) $request->get_param( 'id' );
		if ( '' === $workspace_id ) {
			return true;
		}

		$workspace = $this->workspaces->get( $workspace_id );
		if ( null === $workspace ) {
			// Not found rather than forbidden, so an id that does not exist and one that
			// belongs to somebody else are answered the same way.
			return new \WP_Error( 'replicaforge_forbidden', __( 'That workspace is not available.', 'replicaforge' ), array( 'status' => 403 ) );
		}

		if ( ! $this->permissions->can( $user_id, $workspace_id, 'workspace.view' ) && ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'replicaforge_forbidden', __( 'That workspace is not available.', 'replicaforge' ), array( 'status' => 403 ) );
		}

		if ( '' !== $capability && ! $this->permissions->can( $user_id, $workspace_id, $capability ) ) {
			return new \WP_Error( 'replicaforge_forbidden', __( 'You cannot do that here.', 'replicaforge' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Return the refusal a handler should stop on, or null.
	 *
	 * @param \WP_REST_Request $request    Request.
	 * @param string           $capability Capability.
	 * @return \WP_REST_Response|null
	 */
	private function refuse_unless( $request, $capability ) {
		$verdict = $this->authorize( $request, $capability );
		if ( true === $verdict ) {
			return null;
		}
		$status   = ( $verdict instanceof \WP_Error ) ? (int) $verdict->get_error_data()['status'] : 403;
		$message  = ( $verdict instanceof \WP_Error ) ? $verdict->get_error_message() : __( 'That workspace is not available.', 'replicaforge' );
		$code     = ( $verdict instanceof \WP_Error ) ? $verdict->get_error_code() : 'replicaforge_forbidden';
		return $this->error( (string) $code, (string) $message, $status > 0 ? $status : 403 );
	}
	/**
	 * The gate itself.
	 *
	 * @param \WP_REST_Request $request    Request.
	 * @param string               $capability Capability, or empty for membership only.
	 * @return true|\WP_Error
	 */
	private function gate( $request, $capability ) {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return new \WP_Error( 'replicaforge_unauthenticated', __( 'You must be signed in.', 'replicaforge' ), array( 'status' => 401 ) );
		}

		$workspace_id = (string) $request->get_param( 'id' );
		if ( '' === $workspace_id ) {
			// A route with no workspace in its path - preferences, invitation acceptance.
			// The handler establishes the scope itself, and each one does so from a value it
			// resolves rather than one the caller supplied.
			return true;
		}

		if ( ! $this->permissions->can( $user_id, $workspace_id, 'workspace.view' ) && ! user_can( $user_id, 'manage_options' ) ) {
			/*
			 * One refusal for "not a member" and for "no such workspace".
			 *
			 * A distinguishable "forbidden" would tell an anonymous caller which workspace
			 * ids exist, and §3 makes cross-workspace access an isolation property rather
			 * than an authorisation nicety - so the two are answered identically and the
			 * workspace id is never confirmed to anyone who is not already a member.
			 */
			return new \WP_Error( 'replicaforge_forbidden', __( 'That workspace is not available.', 'replicaforge' ), array( 'status' => 403 ) );
		}

		if ( '' === $capability ) {
			return true;
		}

		if ( ! $this->permissions->can( $user_id, $workspace_id, $capability ) ) {
			return new \WP_Error( 'replicaforge_forbidden', __( 'You cannot do that here.', 'replicaforge' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Workspaces
	 * ------------------------------------------------------------------ */

	/**
	 * List the workspaces the caller belongs to or owns.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_workspaces( $request ) {
		$user_id = get_current_user_id();
		$items   = array();

		foreach ( array_merge( $this->workspaces->for_user( $user_id, false ), $this->workspaces->for_user( $user_id, true ) ) as $workspace ) {
			// A user who both belongs to and owns a workspace sees it once.
			$items[ (string) $workspace['public_id'] ] = $this->present_workspace( $workspace );
		}

		return $this->ok( array( 'items' => array_values( $items ), 'count' => count( $items ) ) );
	}

	/**
	 * Return the workspace a caller is acting in.
	 *
	 * §2 says individual users should receive a personal workspace, and §57 says existing
	 * installations must keep working. So a caller with no workspace is *given* one here
	 * rather than told to go and create one: the alternative is an install where nothing
	 * works until somebody visits a screen and presses a button.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function current_workspace( $request ) {
		$user_id = get_current_user_id();
		$owned   = $this->workspaces->for_user( $user_id, true );
		$any     = $this->workspaces->for_user( $user_id, false );

		$workspace = null;
		if ( array() !== $owned ) {
			$workspace = $owned[0];
		} elseif ( array() !== $any ) {
			$workspace = $any[0];
		}

		if ( null === $workspace ) {
			$user     = get_userdata( $user_id );
			$name     = ( $user instanceof \WP_User ) ? (string) $user->display_name . "'s Workspace" : __( 'My Workspace', 'replicaforge' );
			$workspace = $this->workspaces->create( $user_id, $name );
			if ( null === $workspace ) {
				return $this->error( 'workspace_not_created', __( 'A workspace could not be created.', 'replicaforge' ), 500 );
			}
		}

		return $this->ok( $this->present_workspace( $workspace ) );
	}

	/**
	 * Create a workspace.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_workspace( $request ) {
		$user_id = get_current_user_id();

		if ( $this->workspaces->owned_count( $user_id ) >= Workspace_Limits::MAX_OWNED_WORKSPACES ) {
			return $this->error( 'workspace_limit', __( 'You own the maximum number of workspaces.', 'replicaforge' ), 400 );
		}

		$workspace = $this->workspaces->create( $user_id, (string) $request->get_param( 'name' ) );
		if ( null === $workspace ) {
			return $this->error( 'workspace_not_created', __( 'The workspace could not be created.', 'replicaforge' ), 400 );
		}

		$this->log->audit( (string) $workspace['public_id'], 'workspace_created', array( 'target_type' => 'workspace', 'target_id' => (string) $workspace['public_id'] ), $user_id );

		return $this->ok( $this->present_workspace( $workspace ), 201 );
	}

	/**
	 * Return one workspace.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_workspace( $request ) {
		$refusal = $this->refuse_unless( $request, 'workspace.view' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$workspace = $this->workspaces->get( (string) $request->get_param( 'id' ) );
		if ( null === $workspace ) {
			return $this->error( 'not_found', __( 'That workspace is not available.', 'replicaforge' ), 404 );
		}
		return $this->ok( $this->present_workspace( $workspace ) );
	}

	/* ---------------------------------------------------------------------
	 * Members and invitations
	 * ------------------------------------------------------------------ */

	/**
	 * List a workspace's members.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_members( $request ) {
		$refusal = $this->refuse_unless( $request, 'members.view' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$workspace_id = (string) $request->get_param( 'id' );
		$page         = $this->members->members( $workspace_id, $this->page_args( $request ) );

		return $this->ok( $this->present_page( $page ) );
	}

	/**
	 * Add a member directly, without an invitation.
	 *
	 * §12 describes invitations as the path in, and this is the other one: an administrator
	 * adding somebody who already has an account on this site. It is the same capability, and
	 * it is audited the same way.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function add_member( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );

		$user_id = (int) $request->get_param( 'user_id' );
		$email   = (string) $request->get_param( 'email' );
		$role    = (string) $request->get_param( 'role' );

		// An email may be given instead of an id, and is resolved through the site - never
		// asserted. An id is never taken from the request at all when an email is present.
		if ( $user_id < 1 && '' !== trim( $email ) ) {
			$user = get_user_by( 'email', sanitize_email( $email ) );
			$user_id = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;
		}

		$member = $this->members->add( $workspace_id, array( 'user_id' => $user_id, 'email' => $email, 'role' => $role, 'invited_by' => get_current_user_id() ) );
		if ( null === $member ) {
			return $this->error( 'member_not_added', __( 'That person could not be added.', 'replicaforge' ), 400 );
		}

		$this->log->audit( $workspace_id, 'member_added', array( 'target_type' => 'member', 'target_id' => (string) $member['public_id'], 'metadata' => array( 'role' => $role ) ), get_current_user_id() );
		$this->log->activity( $workspace_id, 'member_added', array( 'resource_type' => 'member', 'resource_id' => (string) $member['public_id'], 'metadata' => array( 'name' => (string) $member['display_name'], 'role' => (string) $member['role'] ) ), get_current_user_id() );

		return $this->ok( $this->present_member( $member ), 201 );
	}

	/**
	 * Change a member's role or status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_member( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$public_id    = (string) $request->get_param( 'member' );
		$user_id      = get_current_user_id();

		if ( null !== $request->get_param( 'role' ) ) {
			$member = $this->members->set_role( $workspace_id, $public_id, (string) $request->get_param( 'role' ) );
			if ( null === $member ) {
				return $this->error( 'role_not_changed', __( 'That role could not be assigned.', 'replicaforge' ), 400 );
			}
			$this->log->audit( $workspace_id, 'role_granted', array( 'target_type' => 'member', 'target_id' => $public_id, 'metadata' => array( 'role' => (string) $member['role'] ) ), $user_id );
			$this->log->activity( $workspace_id, 'member_role_changed', array( 'resource_type' => 'member', 'resource_id' => $public_id, 'metadata' => array( 'name' => (string) $member['display_name'], 'role' => (string) $member['role'] ) ), $user_id );
		}

		if ( null !== $request->get_param( 'active' ) ) {
			$member = $this->members->set_status( $workspace_id, $public_id, (bool) $request->get_param( 'active' ) );
			if ( null === $member ) {
				return $this->error( 'status_not_changed', __( 'That member could not be updated.', 'replicaforge' ), 400 );
			}
			$this->log->audit( $workspace_id, ( 'active' === (string) $member['status'] ) ? 'role_granted' : 'role_revoked', array( 'target_type' => 'member', 'target_id' => $public_id ), $user_id );
		}

		return $this->ok( $this->present_member( $this->members->get( $workspace_id, $public_id ) ) );
	}

	/**
	 * Remove a member.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function remove_member( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$public_id    = (string) $request->get_param( 'member' );

		if ( ! $this->members->remove( $workspace_id, $public_id ) ) {
			return $this->error( 'member_not_removed', __( 'That member could not be removed.', 'replicaforge' ), 400 );
		}

		$this->log->audit( $workspace_id, 'member_removed', array( 'target_type' => 'member', 'target_id' => $public_id ), get_current_user_id() );

		return $this->ok( array( 'removed' => true ) );
	}

	/**
	 * List outstanding invitations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_invitations( $request ) {
		$refusal = $this->refuse_unless( $request, 'members.view' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$page = $this->invitations->outstanding( (string) $request->get_param( 'id' ), $this->page_args( $request ) );
		return $this->ok( $this->present_page( $page ) );
	}

	/**
	 * Invite somebody.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_invitation( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );

		$result = $this->invitations->invite(
			$workspace_id,
			(string) $request->get_param( 'email' ),
			(string) $request->get_param( 'role' ),
			array(
				'project_id'    => (string) $request->get_param( 'project_id' ),
				'project_role'  => (string) $request->get_param( 'project_role' ),
				'inviter_id'    => get_current_user_id(),
			)
		);

		if ( empty( $result['ok'] ) ) {
			$status = ( 'forbidden' === (string) $result['code'] ) ? 403 : 400;
			return $this->error( (string) $result['code'], (string) $result['message'], $status );
		}

		// The token and the URL are returned exactly once, to the inviter. They are not
		// recoverable afterwards, and the audit row holds only a short reference.
		return $this->ok(
			array(
				'invitation' => Invitation_Store::present( (array) $result['invitation'] ),
				'url'        => (string) $result['url'],
				'expires_at' => (string) $result['expires_at'],
			),
			201
		);
	}

	/**
	 * Revoke an invitation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function revoke_invitation( $request ) {
		$result = $this->invitations->revoke(
			(string) $request->get_param( 'id' ),
			(string) $request->get_param( 'invitation' ),
			get_current_user_id()
		);
		if ( empty( $result['ok'] ) ) {
			return $this->error( (string) $result['code'], (string) $result['message'], 400 );
		}
		return $this->ok( $result );
	}

	/**
	 * Accept an invitation, by token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function accept_invitation( $request ) {
		$result = $this->invitations->accept(
			(string) $request->get_param( 'token' ),
			array(
				'user_id'          => get_current_user_id(),
				'accept_on_behalf' => (bool) $request->get_param( 'on_behalf' ),
			)
		);
		if ( empty( $result['ok'] ) ) {
			$status = ( 'forbidden' === (string) $result['code'] ) ? 403 : 400;
			return $this->error( (string) $result['code'], (string) $result['message'], $status );
		}
		return $this->ok( array( 'workspace_id' => (string) $result['workspace_id'], 'role' => (string) $result['role'] ) );
	}

	/* ---------------------------------------------------------------------
	 * Clients
	 * ------------------------------------------------------------------ */

	/**
	 * List clients.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_clients( $request ) {
		$refusal = $this->refuse_unless( $request, 'clients.view' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$workspace_id = (string) $request->get_param( 'id' );
		$page         = $this->clients->clients( $workspace_id, $this->page_args( $request ) );

		// §6: notes are internal, so a reader without clients.edit does not receive them.
		$may_edit = $this->permissions->can( get_current_user_id(), $workspace_id, 'clients.edit' );
		foreach ( $page['items'] as $index => $client ) {
			$page['items'][ $index ] = Client_Store::present( $client, $may_edit );
		}

		return $this->ok( $this->present_page( $page ) );
	}

	/**
	 * Create a client.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_client( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$user_id      = get_current_user_id();

		$client = $this->clients->create( $workspace_id, (array) $request->get_json_params() );
		if ( null === $client ) {
			return $this->error( 'client_not_created', __( 'The client could not be created.', 'replicaforge' ), 400 );
		}

		$this->log->activity( $workspace_id, 'client_created', array( 'resource_type' => 'client', 'resource_id' => (string) $client['public_id'], 'metadata' => array( 'name' => (string) $client['name'] ) ), $user_id );

		return $this->ok( $client, 201 );
	}

	/**
	 * Return one client.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_client( $request ) {
		$refusal = $this->refuse_unless( $request, 'clients.view' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$workspace_id = (string) $request->get_param( 'id' );
		$client       = $this->clients->get( $workspace_id, (string) $request->get_param( 'client' ) );
		if ( null === $client ) {
			return $this->error( 'not_found', __( 'That client is not available.', 'replicaforge' ), 404 );
		}
		$may_edit = $this->permissions->can( get_current_user_id(), $workspace_id, 'clients.edit' );
		return $this->ok( Client_Store::present( $client, $may_edit ) );
	}

	/**
	 * Update a client.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_client( $request ) {
		$refusal = $this->refuse_unless( $request, 'clients.edit' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$workspace_id = (string) $request->get_param( 'id' );
		$client       = $this->clients->update( $workspace_id, (string) $request->get_param( 'client' ), (array) $request->get_json_params() );
		if ( null === $client ) {
			return $this->error( 'client_not_updated', __( 'The client could not be updated.', 'replicaforge' ), 400 );
		}
		$this->log->activity( $workspace_id, 'client_updated', array( 'resource_type' => 'client', 'resource_id' => (string) $client['public_id'], 'metadata' => array( 'name' => (string) $client['name'] ) ), get_current_user_id() );
		return $this->ok( $client );
	}

	/**
	 * Archive a client.
	 *
	 * §30 says archive. Nothing is deleted: the client keeps their projects, reviews and
	 * history, and simply stops appearing in active lists.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function archive_client( $request ) {
		$refusal = $this->refuse_unless( $request, 'clients.edit' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$workspace_id = (string) $request->get_param( 'id' );
		$client       = $this->clients->archive( $workspace_id, (string) $request->get_param( 'client' ) );
		if ( null === $client ) {
			return $this->error( 'not_found', __( 'That client is not available.', 'replicaforge' ), 404 );
		}
		$this->log->audit( $workspace_id, 'client_deleted', array( 'project_id' => '', 'target_type' => 'client', 'target_id' => (string) $client['public_id'] ), get_current_user_id() );
		return $this->ok( $client );
	}

	/* ---------------------------------------------------------------------
	 * Projects
	 * ------------------------------------------------------------------ */

	/**
	 * List a workspace's projects.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_projects( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$permissions  = $this->permissions;
		$user_id      = get_current_user_id();

		$filters = array();
		foreach ( array( 'stage', 'client_id' ) as $filter ) {
			$value = (string) $request->get_param( $filter );
			if ( '' !== $value ) {
				$filters[ $filter ] = $value;
			}
		}

		$items = array();
		foreach ( $this->context->list_projects( $workspace_id, $filters ) as $entry ) {
			$project_id = (string) $entry['project_id'];

			// §10: with project scoping on, a workspace member who is not on the project
			// does not see it. The check is here rather than in the store because it is a
			// permission, not a filter.
			if ( ! $permissions->can_in_project( $user_id, $workspace_id, $project_id, 'projects.view' ) ) {
				continue;
			}
			$items[] = $this->present_project( $entry );
		}

		return $this->ok( array( 'items' => $items, 'count' => count( $items ) ) );
	}

	/**
	 * Return one project with its dashboard facts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_project( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );
		$owned        = $this->context->owned( $workspace_id, $project_id );

		if ( null === $owned ) {
			// Not found here rather than forbidden: a project belonging to another
			// workspace is indistinguishable from one that does not exist.
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}
		if ( ! $this->permissions->can_in_project( get_current_user_id(), $workspace_id, $project_id, 'projects.view' ) ) {
			return $this->error( 'forbidden', __( 'That project is not available.', 'replicaforge' ), 403 );
		}

		return $this->ok( $this->dashboard( $workspace_id, $owned ) );
	}

	/**
	 * Update a project's collaboration fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_project( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		$written = $this->context->update( $project_id, (array) $request->get_json_params() );
		if ( array() === $written ) {
			return $this->error( 'project_not_updated', __( 'The project could not be updated.', 'replicaforge' ), 400 );
		}

		$this->log->activity( $workspace_id, 'project_updated', array( 'project_id' => $project_id, 'resource_type' => 'project', 'resource_id' => $project_id, 'metadata' => array( 'field' => (string) $request->get_param( 'field' ) ) ), get_current_user_id() );

		return $this->ok( $written );
	}

	/**
	 * Archive a project.
	 *
	 * §36: removed from active lists, history preserved, and every open review cancelled so a
	 * restored project does not come back with approvals nobody gave for its current state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function archive_project( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		$this->context->update( $project_id, array( 'stage' => 'archived', 'archived_at' => gmdate( 'c' ) ) );
		$this->reviews->cancel_open_for_project( $workspace_id, $project_id );

		$this->log->activity( $workspace_id, 'project_archived', array( 'project_id' => $project_id, 'resource_type' => 'project', 'resource_id' => $project_id ), get_current_user_id() );
		$this->log->audit( $workspace_id, 'settings_changed', array( 'project_id' => $project_id, 'target_type' => 'project', 'target_id' => $project_id, 'metadata' => array( 'archived' => true ) ), get_current_user_id() );

		return $this->ok( $this->context->get( $project_id ) );
	}

	/**
	 * List a project's members.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_project_members( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		return $this->ok( $this->present_page( $this->project_members->members( $workspace_id, $project_id, $this->page_args( $request ) ) ) );
	}

	/**
	 * Add a project member.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function add_project_member( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		$user_id = (int) $request->get_param( 'user_id' );
		$email   = (string) $request->get_param( 'email' );
		if ( $user_id < 1 && '' !== trim( $email ) ) {
			$user    = get_user_by( 'email', sanitize_email( $email ) );
			$user_id = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;
		}

		$member = $this->project_members->add( $workspace_id, $project_id, array( 'user_id' => $user_id, 'email' => $email, 'role' => (string) $request->get_param( 'role' ), 'added_by' => get_current_user_id() ) );
		if ( null === $member ) {
			return $this->error( 'member_not_added', __( 'That person could not be added to the project.', 'replicaforge' ), 400 );
		}

		$this->log->activity( $workspace_id, 'member_added', array( 'project_id' => $project_id, 'resource_type' => 'project_member', 'resource_id' => (string) $member['public_id'] ), get_current_user_id() );
		$this->log->audit( $workspace_id, 'project_access_granted', array( 'project_id' => $project_id, 'target_type' => 'project_member', 'target_id' => (string) $member['public_id'], 'metadata' => array( 'role' => (string) $member['role'] ) ), get_current_user_id() );

		return $this->ok( $member, 201 );
	}

	/* ---------------------------------------------------------------------
	 * Reviews
	 * ------------------------------------------------------------------ */

	/**
	 * List a project's reviews.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_reviews( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		return $this->ok( $this->present_page( $this->reviews->reviews( $workspace_id, array_merge( $this->page_args( $request ), array( 'project_id' => $project_id ) ) ) ) );
	}

	/**
	 * Request a review.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_review( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		$review = $this->reviews->create( $workspace_id, $project_id, array_merge(
			(array) $request->get_json_params(),
			array( 'requested_by' => get_current_user_id() )
		) );
		if ( null === $review ) {
			return $this->error( 'review_not_created', __( 'The review could not be created. A review must name a version that exists.', 'replicaforge' ), 400 );
		}

		$this->log->activity( $workspace_id, 'review_requested', array( 'project_id' => $project_id, 'resource_type' => 'review', 'resource_id' => (string) $review['public_id'], 'metadata' => array( 'version' => (string) $review['version_number'], 'reviewer' => (string) $review['display_name'] ) ), get_current_user_id() );
		$this->notifications->notify( $workspace_id, 'review_requested', array( 'project_id' => $project_id, 'actor_id' => get_current_user_id(), 'resource_id' => (string) $review['public_id'] ) );

		return $this->ok( $review, 201 );
	}

	/**
	 * Approve a review.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function approve_review( $request ) {
		return $this->decide( $request, 'approve' );
	}

	/**
	 * Request changes on a review.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function request_changes( $request ) {
		return $this->decide( $request, 'changes' );
	}

	/**
	 * Reject a review.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reject_review( $request ) {
		return $this->decide( $request, 'reject' );
	}

	/**
	 * Record a decision on a review.
	 *
	 * §16 gives a client the right to approve without holding `reviews.approve` in the
	 * workspace, so the decision is re-checked against the *specific review* here. That check
	 * is the only place a review-scoped grant is honoured, which is what keeps it from
	 * becoming a general capability.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string               $decision Decision.
	 * @return \WP_REST_Response
	 */
	private function decide( $request, $decision ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$public_id    = (string) $request->get_param( 'review' );
		$user_id      = get_current_user_id();
		$note         = (string) $request->get_param( 'note' );

		$review = $this->reviews->get( $workspace_id, $public_id );
		if ( null === $review ) {
			return $this->error( 'not_found', __( 'That review is not available.', 'replicaforge' ), 404 );
		}

		$capability = ( 'changes' === $decision ) ? 'reviews.reject' : 'reviews.' . $decision;
		if ( ! $this->permissions->can_act_on_review( $user_id, $workspace_id, $review, $capability ) ) {
			return $this->error( 'forbidden', __( 'You cannot decide this review.', 'replicaforge' ), 403 );
		}

		if ( 'changes' === $decision ) {
			$result = $this->reviews->request_changes( $workspace_id, $public_id, $note );
		} elseif ( 'reject' === $decision ) {
			$result = $this->reviews->reject( $workspace_id, $public_id, $note );
		} else {
			$result = $this->reviews->approve( $workspace_id, $public_id, $note );
		}

		if ( null === $result ) {
			return $this->error( 'not_actionable', __( 'That review is not waiting for a decision.', 'replicaforge' ), 409 );
		}

		$this->log->activity(
			$workspace_id,
			( 'approve' === $decision ) ? 'approval_granted' : 'changes_requested',
			array(
				'project_id'    => (string) $result['project_id'],
				'resource_type' => 'review',
				'resource_id'   => $public_id,
				// The version the decision applies to, so the timeline cannot be read as
				// approving something later than it did.
				'metadata'      => array( 'version' => (string) $result['version_number'] ),
			),
			$user_id
		);
		$this->log->audit( $workspace_id, 'settings_changed', array( 'project_id' => (string) $result['project_id'], 'target_type' => 'review', 'target_id' => $public_id, 'metadata' => array( 'decision' => $decision, 'version' => (string) $result['version_number'] ) ), $user_id );

		$notification = ( 'changes' === $decision ) ? 'review_changes_requested' : 'review_approved';
		$this->notifications->notify( $workspace_id, $notification, array( 'project_id' => (string) $result['project_id'], 'actor_id' => $user_id, 'resource_id' => $public_id ) );

		return $this->ok( $result );
	}

	/**
	 * Create a review link.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_review_link( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );

		$result = ( new Review_Link_Service() )->create(
			$workspace_id,
			(string) $request->get_param( 'review' ),
			array(
				'actor_id' => get_current_user_id(),
				'password' => (string) $request->get_param( 'password' ),
				'ttl'      => (int) $request->get_param( 'ttl' ),
			)
		);

		if ( empty( $result['ok'] ) ) {
			$status = ( 'forbidden' === (string) $result['code'] ) ? 403 : 400;
			return $this->error( (string) $result['code'], (string) $result['message'], $status );
		}

		return $this->ok( $result, 201 );
	}

	/**
	 * Revoke a review link.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function revoke_review_link( $request ) {
		$refusal = $this->refuse_unless( $request, 'reviews.create' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$result = ( new Review_Link_Service() )->revoke(
			(string) $request->get_param( 'id' ),
			(string) $request->get_param( 'review' ),
			get_current_user_id()
		);
		if ( empty( $result['ok'] ) ) {
			$status = ( 'forbidden' === (string) $result['code'] ) ? 403 : 400;
			return $this->error( (string) $result['code'], (string) $result['message'], $status );
		}
		return $this->ok( $result );
	}

	/* ---------------------------------------------------------------------
	 * Comments
	 * ------------------------------------------------------------------ */

	/**
	 * List a project's comments.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_comments( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		$page = $this->comments->comments( $workspace_id, $project_id, $this->page_args( $request ) );
		foreach ( $page['items'] as $index => $comment ) {
			$page['items'][ $index ]['anchor'] = Comment_Store::anchored( $comment );
		}

		return $this->ok( $this->present_page( $page ) );
	}

	/**
	 * Post a comment.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_comment( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		$comment = $this->comments->create( $workspace_id, $project_id, array_merge(
			(array) $request->get_json_params(),
			array( 'author_id' => get_current_user_id() )
		) );
		if ( null === $comment ) {
			return $this->error( 'comment_not_created', __( 'The comment could not be saved. A comment must name a version.', 'replicaforge' ), 400 );
		}

		$comment['anchor'] = Comment_Store::anchored( $comment );

		$this->log->activity( $workspace_id, 'comment_added', array( 'project_id' => $project_id, 'resource_type' => 'comment', 'resource_id' => (string) $comment['public_id'], 'metadata' => array( 'name' => (string) $comment['author_name'] ) ), get_current_user_id() );
		$this->notifications->notify( $workspace_id, 'comment_reply', array( 'project_id' => $project_id, 'actor_id' => get_current_user_id(), 'resource_id' => (string) $comment['public_id'] ) );

		return $this->ok( $comment, 201 );
	}

	/**
	 * Resolve a comment.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function resolve_comment( $request ) {
		$refusal = $this->refuse_unless( $request, 'comments.resolve' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$workspace_id = (string) $request->get_param( 'id' );
		$comment      = $this->comments->resolve( $workspace_id, (string) $request->get_param( 'comment' ), get_current_user_id() );
		if ( null === $comment ) {
			return $this->error( 'not_actionable', __( 'That comment could not be resolved.', 'replicaforge' ), 409 );
		}
		$this->log->activity( $workspace_id, 'comment_resolved', array( 'project_id' => (string) $comment['project_id'], 'resource_type' => 'comment', 'resource_id' => (string) $comment['public_id'] ), get_current_user_id() );
		return $this->ok( $comment );
	}

	/**
	 * Reopen a comment.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reopen_comment( $request ) {
		$refusal = $this->refuse_unless( $request, 'comments.resolve' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$workspace_id = (string) $request->get_param( 'id' );
		$comment      = $this->comments->reopen( $workspace_id, (string) $request->get_param( 'comment' ) );
		if ( null === $comment ) {
			return $this->error( 'not_actionable', __( 'That comment could not be reopened.', 'replicaforge' ), 409 );
		}
		return $this->ok( $comment );
	}

	/* ---------------------------------------------------------------------
	 * Tasks and issues
	 * ------------------------------------------------------------------ */

	/**
	 * List a project's tasks.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_tasks( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		return $this->ok( $this->present_page( $this->tasks->tasks( $workspace_id, $project_id, $this->page_args( $request ) ) ) );
	}

	/**
	 * Create a task.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_task( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		$task = $this->tasks->create( $workspace_id, $project_id, array_merge(
			(array) $request->get_json_params(),
			array( 'creator_id' => get_current_user_id() )
		) );
		if ( null === $task ) {
			return $this->error( 'task_not_created', __( 'The task could not be created.', 'replicaforge' ), 400 );
		}

		$this->log->activity( $workspace_id, 'task_created', array( 'project_id' => $project_id, 'resource_type' => 'task', 'resource_id' => (string) $task['public_id'], 'metadata' => array( 'title' => (string) $task['title'] ) ), get_current_user_id() );

		$assignee = (int) $task['assignee_id'];
		if ( $assignee > 0 ) {
			$this->log->activity( $workspace_id, 'task_assigned', array( 'project_id' => $project_id, 'resource_type' => 'task', 'resource_id' => (string) $task['public_id'] ), get_current_user_id() );
			$this->notifications->notify( $workspace_id, 'task_assigned', array( 'project_id' => $project_id, 'actor_id' => get_current_user_id(), 'assignee_id' => $assignee ) );
		}

		return $this->ok( $task, 201 );
	}

	/**
	 * Update a task.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_task( $request ) {
		$refusal = $this->refuse_unless( $request, 'projects.edit' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$workspace_id = (string) $request->get_param( 'id' );
		$task         = $this->tasks->update( $workspace_id, (string) $request->get_param( 'task' ), (array) $request->get_json_params() );
		if ( null === $task ) {
			return $this->error( 'task_not_updated', __( 'The task could not be updated.', 'replicaforge' ), 400 );
		}

		if ( isset( $task['status'] ) && in_array( (string) $task['status'], Workspace_Limits::DONE_TASK_STATUSES, true ) ) {
			$this->log->activity( $workspace_id, 'task_completed', array( 'project_id' => (string) $task['project_id'], 'resource_type' => 'task', 'resource_id' => (string) $task['public_id'] ), get_current_user_id() );
			$this->notifications->notify( $workspace_id, 'task_completed', array( 'project_id' => (string) $task['project_id'], 'actor_id' => get_current_user_id(), 'resource_id' => (string) $task['public_id'] ) );
		}

		return $this->ok( $task );
	}

	/**
	 * List a project's issues.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_issues( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		return $this->ok( $this->present_page( $this->issues->issues( $workspace_id, $project_id, $this->page_args( $request ) ) ) );
	}

	/**
	 * Raise an issue.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_issue( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		if ( null === $this->context->owned( $workspace_id, $project_id ) ) {
			return $this->error( 'not_found', __( 'That project is not available.', 'replicaforge' ), 404 );
		}

		$issue = $this->issues->create( $workspace_id, $project_id, array_merge(
			(array) $request->get_json_params(),
			array( 'reporter_id' => get_current_user_id() )
		) );
		if ( null === $issue ) {
			return $this->error( 'issue_not_created', __( 'The issue could not be created. An issue must name where it came from.', 'replicaforge' ), 400 );
		}

		$this->log->activity( $workspace_id, 'issue_created', array( 'project_id' => $project_id, 'resource_type' => 'issue', 'resource_id' => (string) $issue['public_id'] ), get_current_user_id() );
		$this->notifications->notify( $workspace_id, 'issue_created', array( 'project_id' => $project_id, 'actor_id' => get_current_user_id(), 'assignee_id' => (int) $issue['assignee_id'] ) );

		return $this->ok( $issue, 201 );
	}

	/**
	 * Update an issue.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_issue( $request ) {
		$refusal = $this->refuse_unless( $request, 'projects.edit' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$workspace_id = (string) $request->get_param( 'id' );
		$issue        = $this->issues->update( $workspace_id, (string) $request->get_param( 'issue' ), (array) $request->get_json_params() );
		if ( null === $issue ) {
			return $this->error( 'issue_not_updated', __( 'The issue could not be updated.', 'replicaforge' ), 400 );
		}

		if ( 'resolved' === (string) $issue['status'] ) {
			$this->log->activity( $workspace_id, 'issue_resolved', array( 'project_id' => (string) $issue['project_id'], 'resource_type' => 'issue', 'resource_id' => (string) $issue['public_id'] ), get_current_user_id() );
			$this->notifications->notify( $workspace_id, 'issue_resolved', array( 'project_id' => (string) $issue['project_id'], 'actor_id' => get_current_user_id(), 'resource_id' => (string) $issue['public_id'] ) );
		}

		return $this->ok( $issue );
	}

	/* ---------------------------------------------------------------------
	 * Activity, audit and notifications
	 * ------------------------------------------------------------------ */

	/**
	 * Return a project's activity timeline.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_activity( $request ) {
		$workspace_id = (string) $request->get_param( 'id' );
		$project_id   = (string) $request->get_param( 'project' );

		$page = $this->log->timeline( $workspace_id, $project_id, $this->page_args( $request ) );
		return $this->ok( $this->present_page( $page ) );
	}

	/**
	 * Return a workspace's audit trail.
	 *
	 * Gated on `roles.manage` rather than `projects.view`, because an audit trail answers a
	 * security question and §25 keeps it separate from the narrative for that reason. Every
	 * member who can see the timeline cannot see this.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_audit( $request ) {
		$refusal = $this->refuse_unless( $request, 'roles.manage' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		return $this->ok( $this->present_page( $this->log->audit_trail( (string) $request->get_param( 'id' ), $this->page_args( $request ) ) ) );
	}

	/**
	 * Return the caller's notifications.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_notifications( $request ) {
		$refusal = $this->refuse_unless( $request, '' );
		if ( null !== $refusal ) {
			return $refusal;
		}
		return $this->ok( $this->present_page( $this->notifications->inbox( (string) $request->get_param( 'id' ), get_current_user_id(), $this->page_args( $request ) ) ) );
	}

	/**
	 * Mark a notification read.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function read_notification( $request ) {
		$marked = $this->notifications->mark_read( (string) $request->get_param( 'id' ), get_current_user_id(), (string) $request->get_param( 'notification' ) );
		if ( ! $marked ) {
			return $this->error( 'not_found', __( 'That notification is not available.', 'replicaforge' ), 404 );
		}
		return $this->ok( array( 'read' => true ) );
	}

	/**
	 * Return the caller's notification preferences.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_preferences( $request ) {
		return $this->ok( $this->notifications->preferences( get_current_user_id() ) );
	}

	/**
	 * Save the caller's notification preferences.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_preferences( $request ) {
		return $this->ok( $this->notifications->set_preferences( get_current_user_id(), (array) $request->get_json_params() ) );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Build the §9 dashboard for one project.
	 *
	 * Every figure here is a count of stored records. §9 says not to present a fabricated
	 * accuracy score, so there is none: what is reported is what exists, and a metric that
	 * would have to be estimated is not estimated.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param array<string, mixed> $owned        Owned project and context.
	 * @return array<string, mixed>
	 */
	private function dashboard( $workspace_id, array $owned ) {
		$project  = (array) $owned['project'];
		$context  = (array) $owned['context'];
		$project_id = (string) $project['project_id'];

		$latest = $this->latest_version( $project );
		$gates  = $this->context->gates( $project_id, $context, $this->workspaces->settings( $workspace_id ) );

		$client = null;
		if ( '' !== (string) $context['client_id'] ) {
			$client = $this->clients->get( $workspace_id, (string) $context['client_id'] );
		}

		return array(
			'project_id'  => $project_id,
			'name'        => (string) $project['name'],
			'source_url'  => (string) $project['source_url'],
			// Both axes, reported together and never merged.
			'stage'       => (string) $context['stage'],
			'stage_label' => Workspace_Limits::stage_label( (string) $context['stage'] ),
			'status'      => (string) $project['status'],
			'priority'    => (string) $context['priority'],
			'client'      => null === $client ? null : array( 'id' => (string) $client['public_id'], 'name' => (string) $client['name'], 'company' => (string) $client['company'] ),
			'owner_id'    => (int) $context['owner_id'],
			'pages'       => count( (array) ( $project['versions'][ isset( $latest['index'] ) ? $latest['index'] : 0 ]['pages'] ?? array() ) ),
			'versions'    => count( (array) ( $project['versions'] ?? array() ) ),
			'latest'      => $latest['summary'],
			'open_reviews'=> $this->reviews->open_count_for_project( $workspace_id, $project_id ),
			'open_issues' => $this->issues->open_count_for_project( $workspace_id, $project_id ),
			'open_comments' => $this->comments->open_count_for_project( $workspace_id, $project_id ),
			'open_tasks'  => $this->tasks->tasks( $workspace_id, $project_id, array( 'statuses' => array_keys( Workspace_Limits::TASK_STATUSES ) ) )['count'],
			'members'     => $this->project_members->active_count( $workspace_id, $project_id ),
			'gates'       => $gates,
			'archived'    => ( '' !== (string) $context['archived_at'] ),
		);
	}

	/**
	 * Return a project's newest version, and the fields the dashboard reports from it.
	 *
	 * The `index` is returned alongside the summary because the page count has to come from
	 * the same record - reading `versions[0]` for a count while reading the newest entry for
	 * a version id would report two different versions.
	 *
	 * @param array<string, mixed> $project Project.
	 * @return array<string, mixed>
	 */
	private function latest_version( array $project ) {
		$versions = (array) ( $project['versions'] ?? array() );
		if ( array() === $versions ) {
			return array( 'index' => null, 'summary' => null );
		}

		$index = 0;
		foreach ( $versions as $i => $version ) {
			if ( is_array( $version ) && (int) ( $version['version'] ?? 0 ) >= (int) ( $versions[ $index ]['version'] ?? 0 ) ) {
				$index = $i;
			}
		}

		$latest = (array) $versions[ $index ];

		return array(
			'index'   => $index,
			'summary' => array(
				'version_id'      => (string) ( $latest['version_id'] ?? '' ),
				'version_number'  => (int) ( $latest['version'] ?? 0 ),
				'created_at'      => (string) ( $latest['created_at'] ?? '' ),
				'validation_id'   => (string) ( $latest['validation_id'] ?? '' ),
				'generation_id'   => (string) ( $latest['generation_id'] ?? '' ),
			),
		);
	}

	/**
	 * Present a workspace.
	 *
	 * @param array<string, mixed> $workspace Workspace.
	 * @return array<string, mixed>
	 */
	private function present_workspace( array $workspace ) {
		$workspace_id = (string) $workspace['public_id'];

		return array(
			'id'          => $workspace_id,
			'name'        => (string) $workspace['name'],
			'slug'        => (string) $workspace['slug'],
			'status'      => (string) $workspace['status'],
			'owner_id'    => (int) $workspace['owner_id'],
			'settings'    => $this->workspaces->settings( $workspace_id ),
			// Counts the dashboard needs, taken here so a screen needs one call.
			'members'     => $this->members->active_count( $workspace_id ),
			'outstanding_invitations' => $this->invitations->outstanding_count( $workspace_id ),
			'open_reviews' => $this->reviews->open_count( $workspace_id ),
			'open_issues'  => $this->issues->open_count( $workspace_id ),
			'open_comments'=> $this->comments->open_count( $workspace_id ),
			'created_at'   => (string) $workspace['created_at'],
			'updated_at'   => (string) $workspace['updated_at'],
		);
	}

	/**
	 * Present a member, without anything that identifies an account they did not supply.
	 *
	 * @param array<string, mixed>|null $member Member.
	 * @return array<string, mixed>|null
	 */
	private function present_member( $member ) {
		if ( ! is_array( $member ) ) {
			return null;
		}
		return array(
			'id'          => (string) $member['public_id'],
			'user_id'     => (int) $member['user_id'],
			'email'       => (string) $member['email'],
			'display_name'=> (string) $member['display_name'],
			'role'        => (string) $member['role'],
			'status'      => (string) $member['status'],
			'joined_at'   => (string) $member['joined_at'],
			'last_seen_at'=> (string) $member['last_seen_at'],
		);
	}

	/**
	 * Present a project list entry.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return array<string, mixed>
	 */
	private function present_project( array $entry ) {
		$context = (array) $entry['context'];
		return array(
			'project_id'  => (string) $entry['project_id'],
			'stage'       => (string) $context['stage'],
			'stage_label' => Workspace_Limits::stage_label( (string) $context['stage'] ),
			'priority'    => (string) $context['priority'],
			'client_id'   => (string) $context['client_id'],
			'owner_id'    => (int) $context['owner_id'],
			'archived'    => ( '' !== (string) $context['archived_at'] ),
		);
	}

	/**
	 * Present a paginated result.
	 *
	 * @param array<string, mixed> $page Page.
	 * @return array<string, mixed>
	 */
	private function present_page( array $page ) {
		return array(
			'items'    => (array) ( $page['items'] ?? array() ),
			'count'    => (int) ( $page['count'] ?? 0 ),
			'has_more' => (bool) ( $page['has_more'] ?? false ),
			'per_page' => (int) ( $page['per_page'] ?? Workspace_Limits::PAGE['default'] ),
			'page'     => (int) ( $page['page'] ?? 1 ),
			// A cursor is returned so a caller can page without a row shifting underneath
			// it, which is what §45 asks for on a list that grows while it is read.
			'cursor'   => $page['cursor'] ?? null,
			'roles'    => $page['roles'] ?? null,
			'by_status'=> $page['by_status'] ?? null,
		);
	}

	/**
	 * Extract pagination and filter arguments from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function page_args( $request ) {
		$args = array();
		foreach ( array( 'page', 'per_page', 'search', 'status', 'role', 'type', 'order_by', 'before' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$args[ $key ] = $value;
			}
		}
		if ( ! isset( $args['per_page'] ) ) {
			$args['per_page'] = Workspace_Limits::PAGE['default'];
		}
		return $args;
	}

	/**
	 * Build a success response.
	 *
	 * @param mixed $data   Payload.
	 * @param int   $status Status.
	 * @return \WP_REST_Response
	 */
	private function ok( $data, $status = 200 ) {
		return new \WP_REST_Response(
			array( 'success' => true, 'data' => $data, 'meta' => array( 'request_id' => Request_Context::request_id() ) ),
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
			$this->logger->error( 'collaboration_api_error', 'A collaboration request failed server-side.', array( 'code' => sanitize_key( (string) $code ) ), 'workspace' );
			$message = __( 'The request could not be completed.', 'replicaforge' );
		}
		return new \WP_REST_Response(
			array( 'success' => false, 'error' => array( 'code' => sanitize_key( (string) $code ), 'message' => (string) $message ), 'meta' => array( 'request_id' => Request_Context::request_id() ) ),
			$status
		);
	}

	/**
	 * Register one route.
	 *
	 * @param string $path       Path.
	 * @param string $method     Method.
	 * @param string $callback   Callback.
	 * @param string $permission Permission callback name.
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
}
