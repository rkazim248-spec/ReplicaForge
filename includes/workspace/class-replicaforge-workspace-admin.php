<?php
/**
 * Phase 15: the workspace admin screens.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The team, projects and review screens.
 *
 * ### Why this is a separate class from the existing admin
 *
 * Every submenu in the pre-Phase-15 admin is registered with the `manage_options`
 * capability, because until this phase the only question was "are you a site
 * administrator?". That is the wrong question now. §4 says roles are labels and the
 * *capability* is the security boundary, and a designer who may generate but not approve has
 * a screen they can reach and a button they cannot press.
 *
 * So these screens are registered with a permission callback rather than a capability string,
 * and that callback resolves a real {@see Permission_Manager} answer for the workspace being
 * viewed. A screen that nobody can reach is a correct answer for a member of no workspace; a
 * screen that everybody who is signed in can reach is not.
 *
 * The existing admin is not modified. Its seven pages keep their `manage_options` gate,
 * because its content is site-wide and a workspace role has no business seeing it.
 *
 * ### Every mutating action is a POST with a nonce and a capability check
 *
 * §41 lists CSRF and privilege escalation. So: no action is reachable by GET, every form
 * carries a nonce, and the handler re-resolves the capability server-side rather than
 * trusting a hidden field. A hidden field can be edited by anyone who can view the page;
 * a capability cannot be widened by editing HTML.
 */
final class Workspace_Admin {

	/**
	 * The team screen.
	 *
	 * @var string
	 */
	const TEAM_SLUG = 'replicaforge-team';

	/**
	 * The projects screen.
	 *
	 * @var string
	 */
	const PROJECTS_SLUG = 'replicaforge-projects';

	/**
	 * One project's review screen.
	 *
	 * @var string
	 */
	const REVIEW_SLUG = 'replicaforge-review';

	/**
	 * The page hook suffixes, for enqueueing.
	 *
	 * @var array<string, string>
	 */
	private $hooks = array();

	/**
	 * The permission resolver.
	 *
	 * @var Permission_Manager
	 */
	private $permissions;

	/**
	 * The log.
	 *
	 * @var Collaboration_Log
	 */
	private $log;

	/**
	 * The invitations.
	 *
	 * @var Invitation_Service
	 */
	private $invitations;

	/**
	 * The review links.
	 *
	 * @var Review_Link_Service
	 */
	private $links;

	/**
	 * The workspace store.
	 *
	 * @var Workspace_Store
	 */
	private $workspaces;

	/**
	 * The notifications.
	 *
	 * @var Notification_Service
	 */
	private $notifications;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
	}

	/**
	 * Register the three screens.
	 *
	 * `add_submenu_page()` takes a capability string, not a callback, so the callback form is
	 * achieved by registering with the lowest capability that can possibly be held and then
	 * refusing inside the render method. That is the shape WordPress allows; the alternative
	 * - a real callback - is only available on `rest_api_register_routes`, which is not
	 * relevant here.
	 *
	 * @return void
	 */
	public function add_menu() {
		$slug = Workspace_Limits::ADMIN_PAGE;

		/*
		 * `read` is the floor: any signed-in user has it, and every render method below
		 * resolves the real capability and refuses if it is not held. Registering with
		 * `manage_options` instead would make the screens invisible to the very roles this
		 * phase exists for.
		 */
		$this->hooks['projects'] = add_submenu_page(
			$slug,
			__( 'Projects', 'replicaforge' ),
			__( 'Projects', 'replicaforge' ),
			'read',
			self::PROJECTS_SLUG,
			array( $this, 'render_projects_page' )
		);

		$this->hooks['team'] = add_submenu_page(
			$slug,
			__( 'Team', 'replicaforge' ),
			__( 'Team', 'replicaforge' ),
			'read',
			self::TEAM_SLUG,
			array( $this, 'render_team_page' )
		);

		$this->hooks['review'] = add_submenu_page(
			$slug,
			__( 'Review', 'replicaforge' ),
			__( 'Review', 'replicaforge' ),
			'read',
			self::REVIEW_SLUG,
			array( $this, 'render_review_page' )
		);

		foreach ( $this->hooks as $hook ) {
			if ( is_string( $hook ) ) {
				add_action( 'load-' . $hook, array( $this, 'on_load' ) );
			}
		}
	}

	/**
	 * Prepare a screen before it renders.
	 *
	 * @return void
	 */
	public function on_load() {
		// Nothing is enqueued here. The screens are server-rendered, and the visual
		// annotation layer needs the screenshot itself, which arrives with the review rather
		// than with the shell.
	}

	/* ---------------------------------------------------------------------
	 * Workspace resolution
	 * ------------------------------------------------------------------ */

	/**
	 * Return the workspace this request is acting in.
	 *
	 * ### Why the fallback is "create one" rather than "refuse"
	 *
	 * §2 says individual users should receive a personal workspace, and §57 says existing
	 * installations must keep working. A signed-in user with no workspace at all is a normal
	 * state immediately after the migration on a fresh install, and refusing every screen
	 * until somebody visits a settings page and presses a button is a worse failure than
	 * provisioning one. So a workspace is created here, owned by the caller.
	 *
	 * The creation is not a way to gain access: a workspace grants its owner what they could
	 * already do, and it grants nothing to anybody else.
	 *
	 * @return array<string, mixed>|null
	 */
	private function current_workspace() {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return null;
		}

		$requested = isset( $_GET['workspace'] ) ? sanitize_text_field( wp_unslash( $_GET['workspace'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read, and the value is validated below.
		if ( '' !== $requested ) {
			$workspace = $this->workspaces->get( $requested );
			if ( null !== $workspace && ( $this->permissions->can( $user_id, $requested, 'workspace.view' ) || user_can( $user_id, 'manage_options' ) ) ) {
				return $workspace;
			}
			/*
			 * A workspace the caller may not see is reported as not existing, and the
			 * fallback below then hands them their own. That is deliberate: confirming the
			 * existence of a workspace is itself a disclosure, and a redirect to "your own
			 * workspace" says nothing about whether the id they typed was real.
			 */
		}

		$owned = $this->workspaces->for_user( $user_id, true );
		if ( array() !== $owned ) {
			return $owned[0];
		}

		$member = $this->workspaces->for_user( $user_id, false );
		if ( array() !== $member ) {
			return $member[0];
		}

		$user = get_userdata( $user_id );
		$name = ( $user instanceof \WP_User ) && '' !== trim( (string) $user->display_name )
			? (string) $user->display_name . __( "'s Workspace", 'replicaforge' )
			: __( 'My Workspace', 'replicaforge' );

		$created = $this->workspaces->create( $user_id, $name );
		if ( null === $created ) {
			$this->notice( 'error', __( 'A workspace could not be created.', 'replicaforge' ) );
			return null;
		}

		$this->log->audit( (string) $created['public_id'], 'workspace_created', array( 'target_type' => 'workspace', 'target_id' => (string) $created['public_id'], 'metadata' => array( 'reason' => 'provisioned_on_first_screen' ) ), $user_id );

		return $created;
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	/**
	 * Handle a posted action.
	 *
	 * Every branch follows the same three steps in the same order: verify the nonce, resolve
	 * the capability, then act. None of them trusts a value from the form that decides what
	 * the caller may do - the capability comes from the resolver, and the ids are re-resolved
	 * through the workspace before anything is written.
	 *
	 * @return void
	 */
	public function handle_post() {
		if ( empty( $_POST['replicaforge_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['replicaforge_action'] ) );
		$nonce  = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'replicaforge_workspace_' . $action ) ) {
			wp_die(
				esc_html__( 'That request could not be verified. Please reload the page and try again.', 'replicaforge' ),
				esc_html__( 'Request rejected', 'replicaforge' ),
				array( 'response' => 403 )
			);
		}

		$user_id      = get_current_user_id();
		$workspace_id = isset( $_POST['workspace_id'] ) ? sanitize_text_field( wp_unslash( $_POST['workspace_id'] ) ) : '';

		if ( $user_id < 1 || '' === $workspace_id ) {
			wp_die( esc_html__( 'That request could not be completed.', 'replicaforge' ), '', array( 'response' => 400 ) );
		}

		if ( ! $this->permissions->can( $user_id, $workspace_id, 'workspace.view' ) ) {
			wp_die( esc_html__( 'That workspace is not available.', 'replicaforge' ), '', array( 'response' => 403 ) );
		}

		switch ( $action ) {

			case 'add_member':
				$this->do_add_member( $workspace_id, $user_id );
				break;

			case 'change_role':
				$this->do_change_role( $workspace_id, $user_id );
				break;

			case 'remove_member':
				$this->do_remove_member( $workspace_id, $user_id );
				break;

			case 'invite':
				$this->do_invite( $workspace_id, $user_id );
				break;

			case 'revoke_invitation':
				$this->do_revoke_invitation( $workspace_id, $user_id );
				break;

			case 'set_stage':
				$this->do_set_stage( $workspace_id, $user_id );
				break;

			case 'create_client':
				$this->do_create_client( $workspace_id, $user_id );
				break;

			case 'request_review':
				$this->do_request_review( $workspace_id, $user_id );
				break;

			case 'decide_review':
				$this->do_decide_review( $workspace_id, $user_id );
				break;

			case 'create_review_link':
				$this->do_create_review_link( $workspace_id, $user_id );
				break;

			case 'post_comment':
				$this->do_post_comment( $workspace_id, $user_id );
				break;

			case 'resolve_comment':
				$this->do_resolve_comment( $workspace_id, $user_id );
				break;

			case 'promote_difference':
				$this->do_promote_difference( $workspace_id, $user_id );
				break;

			case 'save_preferences':
				$this->do_save_preferences( $user_id );
				break;

			default:
				// An unknown action is refused rather than ignored. Ignoring it would let a
				// stale form submit report success, which is the same failure as a silently
				// ignored permission.
				$this->notice( 'error', __( 'That action is not available.', 'replicaforge' ) );
		}
	}

	/**
	 * Add a member directly.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_add_member( $workspace_id, $actor_id ) {
		if ( ! $this->permissions->can( $actor_id, $workspace_id, 'members.invite' ) ) {
			$this->deny( __( 'You cannot add people to this workspace.', 'replicaforge' ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$role  = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';
		$user  = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;

		if ( $user < 1 && '' !== $email ) {
			$found = get_user_by( 'email', $email );
			$user  = ( $found instanceof \WP_User ) ? (int) $found->ID : 0;
		}

		$members = new Workspace_Member_Store();
		$member  = $members->add( $workspace_id, array( 'user_id' => $user, 'email' => $email, 'role' => $role, 'invited_by' => $actor_id ) );

		if ( null === $member ) {
			$this->notice( 'error', __( 'That person could not be added. They may already be a member, or the role may not exist.', 'replicaforge' ) );
			return;
		}

		$this->log->audit( $workspace_id, 'member_added', array( 'target_type' => 'member', 'target_id' => (string) $member['public_id'], 'metadata' => array( 'role' => (string) $member['role'] ) ), $actor_id );
		$this->log->activity( $workspace_id, 'member_added', array( 'resource_type' => 'member', 'resource_id' => (string) $member['public_id'], 'metadata' => array( 'name' => (string) $member['display_name'], 'role' => (string) $member['role'] ) ), $actor_id );
		$this->notifications->notify( $workspace_id, 'member_added', array( 'actor_id' => $actor_id ) );

		$this->notice( 'success', __( 'Member added.', 'replicaforge' ) );
	}

	/**
	 * Change a member's role.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_change_role( $workspace_id, $actor_id ) {
		if ( ! $this->permissions->can( $actor_id, $workspace_id, 'roles.manage' ) ) {
			$this->deny( __( 'You cannot change roles in this workspace.', 'replicaforge' ) );
		}

		$member = isset( $_POST['member'] ) ? sanitize_text_field( wp_unslash( $_POST['member'] ) ) : '';
		$role   = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';

		$store  = new Workspace_Member_Store();
		$before = $store->get( $workspace_id, $member );
		$after  = $store->set_role( $workspace_id, $member, $role );

		if ( null === $after || null === $before ) {
			$this->notice( 'error', __( 'That role could not be assigned. The owner, an unknown role, and the last admin are all refused.', 'replicaforge' ) );
			return;
		}

		$this->log->audit( $workspace_id, 'permission_changed', array( 'target_type' => 'member', 'target_id' => $member, 'metadata' => array( 'from' => (string) $before['role'], 'to' => (string) $after['role'] ) ), $actor_id );
		$this->log->activity( $workspace_id, 'member_role_changed', array( 'resource_type' => 'member', 'resource_id' => $member, 'metadata' => array( 'name' => (string) $after['display_name'], 'role' => (string) $after['role'] ) ), $actor_id );

		$this->notice( 'success', __( 'Role updated.', 'replicaforge' ) );
	}

	/**
	 * Remove a member.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_remove_member( $workspace_id, $actor_id ) {
		if ( ! $this->permissions->can( $actor_id, $workspace_id, 'members.remove' ) ) {
			$this->deny( __( 'You cannot remove people from this workspace.', 'replicaforge' ) );
		}

		$member = isset( $_POST['member'] ) ? sanitize_text_field( wp_unslash( $_POST['member'] ) ) : '';

		if ( ! ( new Workspace_Member_Store() )->remove( $workspace_id, $member ) ) {
			$this->notice( 'error', __( 'That member could not be removed. The owner and the last administrator are both protected.', 'replicaforge' ) );
			return;
		}

		$this->log->audit( $workspace_id, 'member_removed', array( 'target_type' => 'member', 'target_id' => $member ), $actor_id );

		$this->notice( 'success', __( 'Member removed.', 'replicaforge' ) );
	}

	/**
	 * Send an invitation.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_invite( $workspace_id, $actor_id ) {
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$role  = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';

		$result = $this->invitations->invite( $workspace_id, $email, $role, array( 'inviter_id' => $actor_id ) );

		if ( empty( $result['ok'] ) ) {
			$this->notice( 'error', (string) $result['message'] );
			return;
		}

		/*
		 * The link is shown once, here, and never again. It is not stored anywhere that can
		 * be read back, so this is the only moment the inviter sees it - which is why the
		 * notice says so rather than presenting it as a field.
		 */
		$this->notice(
			'success',
			sprintf(
				/* translators: %s: invitation link. */
				__( 'Invitation created. This link is shown once and is not recoverable afterwards: %s', 'replicaforge' ),
				'<code>' . esc_html( (string) $result['url'] ) . '</code>'
			),
			false
		);
	}

	/**
	 * Revoke an invitation.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_revoke_invitation( $workspace_id, $actor_id ) {
		$invitation = isset( $_POST['invitation'] ) ? sanitize_text_field( wp_unslash( $_POST['invitation'] ) ) : '';
		$result     = $this->invitations->revoke( $workspace_id, $invitation, $actor_id );

		if ( empty( $result['ok'] ) ) {
			$this->notice( 'error', __( 'That invitation could not be revoked.', 'replicaforge' ) );
			return;
		}

		$this->notice( 'success', __( 'Invitation revoked.', 'replicaforge' ) );
	}

	/**
	 * Change a project's stage.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_set_stage( $workspace_id, $actor_id ) {
		$project_id = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';
		$stage      = isset( $_POST['stage'] ) ? sanitize_text_field( wp_unslash( $_POST['stage'] ) ) : '';

		$context = new Project_Context_Store();
		if ( null === $context->owned( $workspace_id, $project_id ) ) {
			$this->notice( 'error', __( 'That project is not available.', 'replicaforge' ) );
			return;
		}

		if ( ! $this->permissions->can_in_project( $actor_id, $workspace_id, $project_id, 'projects.edit' ) ) {
			$this->deny( __( 'You cannot change this project.', 'replicaforge' ) );
		}

		$before = (string) $context->get( $project_id )['stage'];
		$after  = $context->update( $project_id, array( 'stage' => $stage ) );

		/*
		 * An unrecognised stage is *ignored* rather than reset - `update()` leaves the
		 * stored value alone - so comparing the two tells the caller which happened. A
		 * silent "updated" on a request that changed nothing is the failure mode this
		 * avoids.
		 */
		if ( (string) $after['stage'] === $before ) {
			$this->notice( 'error', __( 'That is not a stage this plugin knows. The project was left as it was.', 'replicaforge' ) );
			return;
		}

		$this->log->activity( $workspace_id, 'project_updated', array( 'project_id' => $project_id, 'resource_type' => 'project', 'resource_id' => $project_id, 'metadata' => array( 'field' => 'stage', 'from' => $before, 'to' => (string) $after['stage'] ) ), $actor_id );

		$this->notice( 'success', __( 'Stage updated.', 'replicaforge' ) );
	}

	/**
	 * Create a client.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_create_client( $workspace_id, $actor_id ) {
		if ( ! $this->permissions->can( $actor_id, $workspace_id, 'clients.create' ) ) {
			$this->deny( __( 'You cannot add clients here.', 'replicaforge' ) );
		}

		$client = ( new Client_Store() )->create(
			$workspace_id,
			array(
				'name'    => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'company' => isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '',
				'email'   => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				'website' => isset( $_POST['website'] ) ? esc_url_raw( wp_unslash( $_POST['website'] ) ) : '',
				'notes'   => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			)
		);

		if ( null === $client ) {
			$this->notice( 'error', __( 'A client needs at least a name or a company.', 'replicaforge' ) );
			return;
		}

		$this->log->activity( $workspace_id, 'client_created', array( 'resource_type' => 'client', 'resource_id' => (string) $client['public_id'], 'metadata' => array( 'name' => (string) $client['name'] ) ), $actor_id );

		$this->notice( 'success', __( 'Client added.', 'replicaforge' ) );
	}

	/**
	 * Request a review of a version.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_request_review( $workspace_id, $actor_id ) {
		$project_id = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';

		if ( ! $this->permissions->can_in_project( $actor_id, $workspace_id, $project_id, 'reviews.create' ) ) {
			$this->deny( __( 'You cannot request a review here.', 'replicaforge' ) );
		}

		$review = ( new Review_Store() )->create(
			$workspace_id,
			$project_id,
			array(
				'reviewer_id'    => isset( $_POST['reviewer_id'] ) ? (int) $_POST['reviewer_id'] : 0,
				'reviewer_email' => isset( $_POST['reviewer_email'] ) ? sanitize_email( wp_unslash( $_POST['reviewer_email'] ) ) : '',
				'type'           => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'internal',
				'note'           => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
				'requested_by'   => $actor_id,
			)
		);

		if ( null === $review ) {
			$this->notice( 'error', __( 'The review could not be created. A review names a version that exists, and a reviewer.', 'replicaforge' ) );
			return;
		}

		$this->log->activity( $workspace_id, 'review_requested', array( 'project_id' => $project_id, 'resource_type' => 'review', 'resource_id' => (string) $review['public_id'], 'metadata' => array( 'version' => (string) $review['version_number'] ) ), $actor_id );
		$this->notifications->notify( $workspace_id, 'review_requested', array( 'project_id' => $project_id, 'actor_id' => $actor_id, 'resource_id' => (string) $review['public_id'] ) );

		$this->notice( 'success', sprintf( /* translators: %s: version number. */ __( 'Review requested for version %s.', 'replicaforge' ), (string) $review['version_number'] ) );
	}

	/**
	 * Record a decision on a review.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_decide_review( $workspace_id, $actor_id ) {
		$review_id = isset( $_POST['review'] ) ? sanitize_text_field( wp_unslash( $_POST['review'] ) ) : '';
		$decision  = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$note      = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$store  = new Review_Store();
		$review = $store->get( $workspace_id, $review_id );

		if ( null === $review ) {
			$this->notice( 'error', __( 'That review is not available.', 'replicaforge' ) );
			return;
		}

		/*
		 * The capability is resolved *per review*, not per workspace. That is what lets a
		 * client contact approve the one review that names them without holding
		 * `reviews.approve` in the workspace - and it is why the same code path cannot be
		 * used to reach any other review.
		 */
		$capability = ( 'changes' === $decision ) ? 'reviews.reject' : 'reviews.' . $decision;

		if ( ! $this->permissions->can_act_on_review( $actor_id, $workspace_id, $review, $capability ) ) {
			$this->deny( __( 'You cannot decide this review.', 'replicaforge' ) );
		}

		if ( 'changes' === $decision ) {
			$result = $store->request_changes( $workspace_id, $review_id, $note );
		} elseif ( 'reject' === $decision ) {
			$result = $store->reject( $workspace_id, $review_id, $note );
		} else {
			$result = $store->approve( $workspace_id, $review_id, $note );
		}

		if ( null === $result ) {
			$this->notice( 'error', __( 'That review is not waiting for that decision.', 'replicaforge' ) );
			return;
		}

		$event = ( 'approve' === $decision ) ? 'approval_granted' : 'changes_requested';

		$this->log->activity( $workspace_id, $event, array( 'project_id' => (string) $result['project_id'], 'resource_type' => 'review', 'resource_id' => $review_id, 'metadata' => array( 'version' => (string) $result['version_number'] ) ), $actor_id );
		$this->notifications->notify( $workspace_id, ( 'approve' === $decision ) ? 'review_approved' : 'review_changes_requested', array( 'project_id' => (string) $result['project_id'], 'actor_id' => $actor_id, 'resource_id' => $review_id ) );

		$this->notice( 'success', __( 'Decision recorded against that version.', 'replicaforge' ) );
	}

	/**
	 * Create a review link.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_create_review_link( $workspace_id, $actor_id ) {
		$review_id = isset( $_POST['review'] ) ? sanitize_text_field( wp_unslash( $_POST['review'] ) ) : '';
		$password  = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		$result = $this->links->create( $workspace_id, $review_id, array( 'actor_id' => $actor_id, 'password' => $password ) );

		if ( empty( $result['ok'] ) ) {
			$this->notice( 'error', (string) $result['message'] );
			return;
		}

		$this->notice(
			'success',
			sprintf(
				/* translators: 1: link, 2: expiry date. */
				__( 'Review link created. Shown once; it expires %s: %s', 'replicaforge' ),
				esc_html( (string) $result['expires_at'] ),
				'<code>' . esc_html( (string) $result['url'] ) . '</code>'
			),
			false
		);
	}

	/**
	 * Post a comment.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_post_comment( $workspace_id, $actor_id ) {
		$project_id = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';
		$version_id = isset( $_POST['version_id'] ) ? sanitize_text_field( wp_unslash( $_POST['version_id'] ) ) : '';

		if ( ! $this->permissions->can_in_project( $actor_id, $workspace_id, $project_id, 'comments.create' ) ) {
			$this->deny( __( 'You cannot comment here.', 'replicaforge' ) );
		}

		$comment = ( new Comment_Store() )->create(
			$workspace_id,
			$project_id,
			array(
				'body'          => isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '',
				'version_id'    => $version_id,
				'parent_id'     => isset( $_POST['parent'] ) ? sanitize_text_field( wp_unslash( $_POST['parent'] ) ) : '',
				'anchor_type'   => isset( $_POST['anchor_type'] ) ? sanitize_key( wp_unslash( $_POST['anchor_type'] ) ) : 'project',
				'section_id'    => isset( $_POST['section_id'] ) ? sanitize_text_field( wp_unslash( $_POST['section_id'] ) ) : '',
				'component_id'  => isset( $_POST['component_id'] ) ? sanitize_text_field( wp_unslash( $_POST['component_id'] ) ) : '',
				'element_id'    => isset( $_POST['element_id'] ) ? sanitize_text_field( wp_unslash( $_POST['element_id'] ) ) : '',
				'viewport'      => isset( $_POST['viewport'] ) ? sanitize_key( wp_unslash( $_POST['viewport'] ) ) : '',
				'region_x'      => isset( $_POST['region_x'] ) ? (float) $_POST['region_x'] : null,
				'region_y'      => isset( $_POST['region_y'] ) ? (float) $_POST['region_y'] : null,
				'region_width'  => isset( $_POST['region_width'] ) ? (float) $_POST['region_width'] : null,
				'region_height' => isset( $_POST['region_height'] ) ? (float) $_POST['region_height'] : null,
				'mentions'      => isset( $_POST['mentions'] ) ? sanitize_text_field( wp_unslash( $_POST['mentions'] ) ) : '',
				'author_id'     => $actor_id,
			)
		);

		if ( null === $comment ) {
			$this->notice( 'error', __( 'The comment could not be saved. It needs a body and a version to attach to.', 'replicaforge' ) );
			return;
		}

		$this->log->activity( $workspace_id, 'comment_added', array( 'project_id' => $project_id, 'resource_type' => 'comment', 'resource_id' => (string) $comment['public_id'], 'metadata' => array( 'name' => (string) $comment['author_name'] ) ), $actor_id );
		$this->notifications->notify( $workspace_id, 'comment_reply', array( 'project_id' => $project_id, 'actor_id' => $actor_id, 'resource_id' => (string) $comment['public_id'] ) );

		$this->notice( 'success', __( 'Comment posted.', 'replicaforge' ) );
	}

	/**
	 * Resolve or reopen a comment.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_resolve_comment( $workspace_id, $actor_id ) {
		$comment_id = isset( $_POST['comment'] ) ? sanitize_text_field( wp_unslash( $_POST['comment'] ) ) : '';
		$opener     = ! empty( $_POST['reopen'] );

		$comments = new Comment_Store();
		$comment  = $comments->get( $workspace_id, $comment_id );

		if ( null === $comment ) {
			$this->notice( 'error', __( 'That comment is not available.', 'replicaforge' ) );
			return;
		}

		if ( ! $this->permissions->can_in_project( $actor_id, $workspace_id, (string) $comment['project_id'], 'comments.resolve' ) ) {
			$this->deny( __( 'You cannot resolve comments here.', 'replicaforge' ) );
		}

		$result = $opener ? $comments->reopen( $workspace_id, $comment_id ) : $comments->resolve( $workspace_id, $comment_id, $actor_id );

		if ( null === $result ) {
			$this->notice( 'error', __( 'That comment is not in a state that can change.', 'replicaforge' ) );
			return;
		}

		if ( ! $opener ) {
			$this->log->activity( $workspace_id, 'comment_resolved', array( 'project_id' => (string) $comment['project_id'], 'resource_type' => 'comment', 'resource_id' => $comment_id ), $actor_id );
		}

		$this->notice( 'success', $opener ? __( 'Comment reopened.', 'replicaforge' ) : __( 'Comment resolved.', 'replicaforge' ) );
	}

	/**
	 * Promote a validation difference to a task.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $actor_id     Actor.
	 * @return void
	 */
	private function do_promote_difference( $workspace_id, $actor_id ) {
		$project_id = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';

		if ( ! $this->permissions->can_in_project( $actor_id, $workspace_id, $project_id, 'generation.run' ) ) {
			$this->deny( __( 'You cannot create tasks here.', 'replicaforge' ) );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$task  = ( new Task_Store() )->from_difference(
			$workspace_id,
			$project_id,
			array(
				'title'        => $title,
				'description'  => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
				// The source is fixed by the screen that offers this action, not by the
				// form. A request that names its own provenance would be a claim about where
				// work came from, and there is no reason for the form to be trusted on it.
				'source'       => 'validation',
				'severity'     => isset( $_POST['severity'] ) ? sanitize_key( wp_unslash( $_POST['severity'] ) ) : 'moderate',
				'page_id'      => isset( $_POST['page_id'] ) ? (int) $_POST['page_id'] : 0,
				'component_id' => isset( $_POST['component_id'] ) ? sanitize_text_field( wp_unslash( $_POST['component_id'] ) ) : '',
				'creator_id'   => $actor_id,
			)
		);

		if ( null === $task ) {
			$this->notice( 'error', __( 'The task could not be created.', 'replicaforge' ) );
			return;
		}

		$this->log->activity( $workspace_id, 'task_created', array( 'project_id' => $project_id, 'resource_type' => 'task', 'resource_id' => (string) $task['public_id'], 'metadata' => array( 'title' => (string) $task['title'] ) ), $actor_id );

		if ( (int) $task['assignee_id'] > 0 ) {
			$this->notifications->notify( $workspace_id, 'task_assigned', array( 'project_id' => $project_id, 'actor_id' => $actor_id, 'assignee_id' => (int) $task['assignee_id'] ) );
		}

		$this->notice( 'success', __( 'Task created from the difference, with its evidence attached.', 'replicaforge' ) );
	}

	/**
	 * Save the caller's notification preferences.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private function do_save_preferences( $user_id ) {
		$changes = array();
		$posted  = isset( $_POST['preferences'] ) && is_array( $_POST['preferences'] ) ? wp_unslash( $_POST['preferences'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is sanitised below.

		foreach ( Workspace_Limits::PREFERENCE_KEYS as $key ) {
			foreach ( array( 'in_app', 'email' ) as $channel ) {
				$field = $key . '_' . $channel;
				if ( isset( $posted[ $field ] ) ) {
					$changes[ $key ][ $channel ] = ( '1' === (string) $posted[ $field ] || 'on' === (string) $posted[ $field ] );
				}
			}
		}

		$this->notifications->set_preferences( $user_id, $changes );
		$this->notice( 'success', __( 'Notification preferences saved.', 'replicaforge' ) );
	}

	/* ---------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------ */

	/**
	 * Render the projects screen.
	 *
	 * @return void
	 */
	public function render_projects_page() {
		$workspace = $this->current_workspace();

		echo '<div class="wrap replicaforge-admin replicaforge-workspace">';
		$this->render_header( __( 'Projects', 'replicaforge' ), __( 'Every replica in this workspace, with its agency stage and its reconstruction status side by side.', 'replicaforge' ) );
		$this->render_notices();

		if ( null === $workspace ) {
			echo '</div>';
			return;
		}

		$workspace_id = (string) $workspace['public_id'];
		$user_id      = get_current_user_id();
		$permissions  = $this->permissions;
		$context      = new Project_Context_Store();

		$filter_stage = isset( $_GET['stage'] ) ? sanitize_text_field( wp_unslash( $_GET['stage'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read.
		$filters      = array();
		if ( '' !== $filter_stage && Workspace_Limits::is_stage( $filter_stage ) ) {
			$filters['stage'] = $filter_stage;
		}

		$rows = array();
		foreach ( $context->list_projects( $workspace_id, $filters ) as $entry ) {
			$project_id = (string) $entry['project_id'];

			// Project scoping, applied here rather than in the store: it is a permission,
			// not a filter, and a store that silently hid rows would be a store whose count
			// disagreed with what the caller could see.
			if ( ! $permissions->can_in_project( $user_id, $workspace_id, $project_id, 'projects.view' ) ) {
				continue;
			}

			$project = $entry['project'];
			$rows[]  = array(
				'project_id' => $project_id,
				'name'       => (string) ( $project['name'] ?? '' ),
				'source_url' => (string) ( $project['source_url'] ?? '' ),
				'status'     => (string) ( $project['status'] ?? '' ),
				'stage'      => (string) $entry['context']['stage'],
				'priority'   => (string) $entry['context']['priority'],
				'versions'   => count( (array) ( $project['versions'] ?? array() ) ),
				'open'       => $context->gates( $project_id, $entry['context'], $this->workspaces->settings( $workspace_id ) ),
			);
		}

		$this->render_stage_filter( $workspace_id, $filter_stage );

		if ( array() === $rows ) {
			echo '<p class="replicaforge-empty">' . esc_html__( 'No projects are in this workspace yet. An analysis creates one.', 'replicaforge' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped replicaforge-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Project', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Stage', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Priority', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Versions', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Approvals', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Stage', 'replicaforge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$may_edit = $permissions->can_in_project( $user_id, $workspace_id, $row['project_id'], 'projects.edit' );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $row['name'] ) . '</strong><br /><a href="' . esc_url( $row['source_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $row['source_url'] ) . '</a></td>';

			// The two axes, labelled distinctly. They are not the same thing and a screen
			// that showed only one of them would misrepresent either.
			echo '<td><span class="replicaforge-badge replicaforge-badge--stage">' . esc_html( Workspace_Limits::stage_label( $row['stage'] ) ) . '</span></td>';
			echo '<td><code>' . esc_html( $row['status'] ) . '</code></td>';
			echo '<td>' . esc_html( Workspace_Limits::priority_label( $row['priority'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['versions'] ) . '</td>';

			$outstanding = (array) ( $row['open']['outstanding'] ?? array() );
			echo '<td>';
			if ( array() === $outstanding ) {
				echo '<span class="replicaforge-badge replicaforge-badge--ok">' . esc_html__( 'Complete', 'replicaforge' ) . '</span>';
			} else {
				echo esc_html( implode( ', ', array_map( 'strval', $outstanding ) ) );
			}
			echo '</td>';

			echo '<td>';

			if ( $may_edit ) {
				echo '<form method="post" class="replicaforge-inline-form">';
				wp_nonce_field( 'replicaforge_workspace_set_stage' );
				echo '<input type="hidden" name="replicaforge_action" value="set_stage" />';
				echo '<input type="hidden" name="workspace_id" value="' . esc_attr( $workspace_id ) . '" />';
				echo '<input type="hidden" name="project_id" value="' . esc_attr( $row['project_id'] ) . '" />';
				echo '<select name="stage" aria-label="' . esc_attr__( 'Stage', 'replicaforge' ) . '">';
				foreach ( Workspace_Limits::STAGES as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $row['stage'], false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select> ';
				echo '<button type="submit" class="button">' . esc_html__( 'Set', 'replicaforge' ) . '</button>';
				echo '</form>';
			}

			echo '<a class="button button-secondary" href="' . esc_url(
				admin_url(
					'admin.php?page=' . self::REVIEW_SLUG . '&amp;workspace=' . rawurlencode( $workspace_id ) . '&amp;project=' . rawurlencode( $row['project_id'] )
				)
			) . '">' . esc_html__( 'Review', 'replicaforge' ) . '</a>';

			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Render the stage filter.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $current      Current filter.
	 * @return void
	 */
	private function render_stage_filter( $workspace_id, $current ) {
		echo '<nav class="replicaforge-filter"><span class="replicaforge-filter__label">' . esc_html__( 'Stage:', 'replicaforge' ) . '</span> ';

		$base = admin_url( 'admin.php?page=' . self::PROJECTS_SLUG . '&workspace=' . rawurlencode( $workspace_id ) );

		echo '<a class="replicaforge-filter__chip' . ( '' === $current ? ' is-active' : '' ) . '" href="' . esc_url( $base ) . '">' . esc_html__( 'All', 'replicaforge' ) . '</a>';

		foreach ( Workspace_Limits::STAGES as $value => $label ) {
			echo '<a class="replicaforge-filter__chip' . ( $value === $current ? ' is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'stage', rawurlencode( $value ), $base ) ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '</nav>';
	}

	/**
	 * Render the team screen.
	 *
	 * @return void
	 */
	public function render_team_page() {
		$workspace = $this->current_workspace();

		echo '<div class="wrap replicaforge-admin replicaforge-workspace">';
		$this->render_header( __( 'Team', 'replicaforge' ), __( 'Who is in this workspace, what each role can do, and who has been invited.', 'replicaforge' ) );
		$this->render_notices();

		if ( null === $workspace ) {
			echo '</div>';
			return;
		}

		$workspace_id = (string) $workspace['public_id'];
		$user_id      = get_current_user_id();
		$members      = new Workspace_Member_Store();
		$may_invite   = $this->permissions->can( $user_id, $workspace_id, 'members.invite' );
		$may_manage   = $this->permissions->can( $user_id, $workspace_id, 'roles.manage' );
		$may_remove   = $this->permissions->can( $user_id, $workspace_id, 'members.remove' );

		$page = $members->members( $workspace_id, array( 'per_page' => Workspace_Limits::MAX_MEMBERS ) );
		$roles = $members->role_counts( $workspace_id );

		echo '<table class="widefat striped replicaforge-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Member', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Role', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Capabilities', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'replicaforge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $page['items'] as $member ) {
			$is_owner = ( 'owner' === (string) $member['role'] );

			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) $member['display_name'] ) . '</strong>';
			if ( '' !== (string) $member['email'] ) {
				echo '<br /><code>' . esc_html( (string) $member['email'] ) . '</code>';
			}
			echo '</td>';

			echo '<td>';
			if ( $is_owner || ! $may_manage ) {
				// The owner is derived from `workspace.owner_id`, so the role shown is the
				// role that is in force, and there is nothing to change.
				echo '<span class="replicaforge-badge">' . esc_html( $member['role'] ) . '</span>';
			} else {
				echo '<form method="post" class="replicaforge-inline-form">';
				wp_nonce_field( 'replicaforge_workspace_change_role' );
				echo '<input type="hidden" name="replicaforge_action" value="change_role" />';
				echo '<input type="hidden" name="workspace_id" value="' . esc_attr( $workspace_id ) . '" />';
				echo '<input type="hidden" name="member" value="' . esc_attr( (string) $member['public_id'] ) . '" />';
				echo '<select name="role" aria-label="' . esc_attr__( 'Role', 'replicaforge' ) . '">';
				foreach ( Workspace_Limits::ASSIGNABLE_ROLES as $role ) {
					echo '<option value="' . esc_attr( $role ) . '"' . selected( $role, (string) $member['role'], false ) . '>' . esc_html( Workspace_Limits::role_label( $role ) ) . '</option>';
				}
				echo '</select> <button type="submit" class="button">' . esc_html__( 'Change', 'replicaforge' ) . '</button>';
				echo '</form>';
			}
			echo '</td>';

			echo '<td>' . esc_html( (string) $member['status'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( Permission_Manager::capabilities_for_role( (string) $member['role'] ) ? count( Permission_Manager::capabilities_for_role( (string) $member['role'] ) ) : 0 ) ) . '</td>';

			echo '<td>';
			if ( ! $is_owner && $may_remove ) {
				echo '<form method="post" class="replicaforge-inline-form">';
				wp_nonce_field( 'replicaforge_workspace_remove_member' );
				echo '<input type="hidden" name="replicaforge_action" value="remove_member" />';
				echo '<input type="hidden" name="workspace_id" value="' . esc_attr( $workspace_id ) . '" />';
				echo '<input type="hidden" name="member" value="' . esc_attr( (string) $member['public_id'] ) . '" />';
				echo '<button type="submit" class="button button-link-delete" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Remove this member?', 'replicaforge' ) ) ) . ')">' . esc_html__( 'Remove', 'replicaforge' ) . '</button>';
				echo '</form>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<p class="replicaforge-note">' . esc_html__( 'Role counts:', 'replicaforge' ) . ' ';
		$pairs = array();
		foreach ( (array) $roles as $role => $count ) {
			$pairs[] = esc_html( $role ) . ': ' . esc_html( (string) $count );
		}
		echo esc_html( implode( ', ', $pairs ) ) . '</p>';

		// Outstanding invitations.
		$outstanding = $this->invitations->outstanding( $workspace_id, array( 'per_page' => 25 ) );

		echo '<h2>' . esc_html__( 'Outstanding invitations', 'replicaforge' ) . '</h2>';

		if ( 0 === (int) $outstanding['count'] ) {
			echo '<p>' . esc_html__( 'None.', 'replicaforge' ) . '</p>';
		} else {
			echo '<table class="widefat striped replicaforge-table"><thead><tr><th>' . esc_html__( 'Address', 'replicaforge' ) . '</th><th>' . esc_html__( 'Role', 'replicaforge' ) . '</th><th>' . esc_html__( 'Expires', 'replicaforge' ) . '</th><th>' . esc_html__( 'Reference', 'replicaforge' ) . '</th><th></th></tr></thead><tbody>';

			foreach ( $outstanding['items'] as $invitation ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $invitation['email'] ) . '</code></td>';
				echo '<td>' . esc_html( (string) $invitation['role'] ) . '</td>';
				echo '<td>' . esc_html( (string) $invitation['expires_at'] ) . '</td>';
				// A short reference, never the token: a list that could be copied out of the
				// page is a list of working invitations.
				echo '<td><code>' . esc_html( (string) ( $invitation['token_ref'] ?? '' ) ) . '</code></td>';
				echo '<td>';

				if ( $may_invite ) {
					echo '<form method="post" class="replicaforge-inline-form">';
					wp_nonce_field( 'replicaforge_workspace_revoke_invitation' );
					echo '<input type="hidden" name="replicaforge_action" value="revoke_invitation" />';
					echo '<input type="hidden" name="workspace_id" value="' . esc_attr( $workspace_id ) . '" />';
					echo '<input type="hidden" name="invitation" value="' . esc_attr( (string) $invitation['public_id'] ) . '" />';
					echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Revoke', 'replicaforge' ) . '</button>';
					echo '</form>';
				}

				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		// The add-and-invite forms.
		if ( $may_invite ) {
			echo '<h2>' . esc_html__( 'Add someone', 'replicaforge' ) . '</h2>';
			echo '<p class="replicaforge-note">' . esc_html__( 'For somebody with an account on this site, add them directly. For anybody else, send an invitation - the link is shown once and cannot be recovered afterwards.', 'replicaforge' ) . '</p>';

			echo '<div class="replicaforge-form-row">';

			echo '<form method="post" class="replicaforge-form">';
			wp_nonce_field( 'replicaforge_workspace_add_member' );
			echo '<input type="hidden" name="replicaforge_action" value="add_member" />';
			echo '<input type="hidden" name="workspace_id" value="' . esc_attr( $workspace_id ) . '" />';
			echo '<h3>' . esc_html__( 'Existing account', 'replicaforge' ) . '</h3>';
			$this->field_email( 'email', __( 'Email address', 'replicaforge' ) );
			$this->field_role( 'role' );
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Add member', 'replicaforge' ) . '</button>';
			echo '</form>';

			echo '<form method="post" class="replicaforge-form">';
			wp_nonce_field( 'replicaforge_workspace_invite' );
			echo '<input type="hidden" name="replicaforge_action" value="invite" />';
			echo '<input type="hidden" name="workspace_id" value="' . esc_attr( $workspace_id ) . '" />';
			echo '<h3>' . esc_html__( 'Invite by email', 'replicaforge' ) . '</h3>';
			$this->field_email( 'email', __( 'Email address', 'replicaforge' ) );
			$this->field_role( 'role' );
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Create invitation', 'replicaforge' ) . '</button>';
			echo '</form>';

			echo '</div>';
		}

		// The permission matrix, so the roles are not a black box.
		$this->render_permission_matrix( $workspace_id, $user_id );

		// Notification preferences, which are the reader's own rather than the workspace's.
		$this->render_preferences();

		echo '</div>';
	}

	/**
	 * Render the permission matrix.
	 *
	 * §47 asks for the matrix to be documented, and §40 for role changes to be logged. A
	 * matrix nobody can read is documentation; one rendered from the same vocabulary the
	 * resolver uses cannot disagree with it.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      Viewer.
	 * @return void
	 */
	private function render_permission_matrix( $workspace_id, $user_id ) {
		echo '<h2>' . esc_html__( 'What each role can do', 'replicaforge' ) . '</h2>';
		echo '<p class="replicaforge-note">' . esc_html__( 'Read from the same capability table the permission resolver uses, so it cannot drift from what is enforced. A role is a label; the capability is what is checked.', 'replicaforge' ) . '</p>';

		$capabilities = Workspace_Limits::capabilities();
		$mine         = $this->permissions->capabilities_for( $user_id, $workspace_id );

		echo '<div class="replicaforge-matrix-wrap"><table class="widefat striped replicaforge-matrix"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Capability', 'replicaforge' ) . '</th>';
		foreach ( Workspace_Limits::ROLES as $role ) {
			echo '<th scope="col">' . esc_html( Workspace_Limits::role_label( $role ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $capabilities as $capability ) {
			echo '<tr><th scope="row"><code>' . esc_html( $capability ) . '</code></th>';

			foreach ( Workspace_Limits::ROLES as $role ) {
				$granted = Permission_Manager::role_grants( $role, $capability );
				echo '<td class="replicaforge-matrix__cell' . ( $granted ? ' is-granted' : '' ) . '">';
				echo $granted ? '<span aria-hidden="true">&#10003;</span><span class="screen-reader-text">' . esc_html__( 'Yes', 'replicaforge' ) . '</span>' : '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'No', 'replicaforge' ) . '</span>';
				echo '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table></div>';

		$granted = array();
		foreach ( (array) $mine as $capability => $holds ) {
			if ( $holds ) {
				$granted[] = (string) $capability;
			}
		}

		echo '<p class="replicaforge-note"><strong>' . esc_html__( 'Yours:', 'replicaforge' ) . '</strong> ' . esc_html( implode( ', ', $granted ) ) . '</p>';
	}

	/**
	 * Render the reader's notification preferences.
	 *
	 * @return void
	 */
	private function render_preferences() {
		$user_id     = get_current_user_id();
		$preferences = $this->notifications->preferences( $user_id );

		echo '<h2>' . esc_html__( 'Your notifications', 'replicaforge' ) . '</h2>';
		echo '<p class="replicaforge-note">' . esc_html__( 'In-app notifications are always on. Email is off unless you turn it on here, for each category, because it is the channel that leaves the site.', 'replicaforge' ) . '</p>';

		echo '<form method="post" class="replicaforge-form">';
		wp_nonce_field( 'replicaforge_workspace_save_preferences' );
		echo '<input type="hidden" name="replicaforge_action" value="save_preferences" />';

		echo '<table class="widefat striped replicaforge-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Category', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'In app', 'replicaforge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Email', 'replicaforge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $preferences as $key => $channels ) {
			echo '<tr><th scope="row">' . esc_html( Workspace_Limits::preference_label( (string) $key ) ) . '</th>';

			foreach ( array( 'in_app', 'email' ) as $channel ) {
				echo '<td><input type="hidden" name="preferences[' . esc_attr( $key . '_' . $channel ) . ']" value="0" />';
				echo '<input type="checkbox" name="preferences[' . esc_attr( $key . '_' . $channel ) . ']" value="1"' . checked( ! empty( $channels[ $channel ] ), true, false ) . ' /></td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save preferences', 'replicaforge' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Render the review screen for one project.
	 *
	 * @return void
	 */
	public function render_review_page() {
		$workspace = $this->current_workspace();

		echo '<div class="wrap replicaforge-admin replicaforge-workspace">';
		$this->render_header( __( 'Review', 'replicaforge' ), __( 'Versions, reviews, comments and issues for one project.', 'replicaforge' ) );
		$this->render_notices();

		if ( null === $workspace ) {
			echo '</div>';
			return;
		}

		$workspace_id = (string) $workspace['public_id'];
		$user_id      = get_current_user_id();
		$project_id   = isset( $_GET['project'] ) ? sanitize_text_field( wp_unslash( $_GET['project'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read.

		$context = new Project_Context_Store();
		$owned   = $context->owned( $workspace_id, $project_id );

		if ( null === $owned ) {
			echo '<p>' . esc_html__( 'That project is not available.', 'replicaforge' ) . '</p></div>';
			return;
		}

		if ( ! $this->permissions->can_in_project( $user_id, $workspace_id, $project_id, 'reviews.view' ) ) {
			echo '<p>' . esc_html__( 'That project is not available.', 'replicaforge' ) . '</p></div>';
			return;
		}

		$project  = (array) $owned['project'];
		$collab   = (array) $owned['context'];
		$versions = (array) ( $project['versions'] ?? array() );
		$gates    = $context->gates( $project_id, $collab, $this->workspaces->settings( $workspace_id ) );

		$latest_id     = '';
		$latest_number = 0;
		foreach ( $versions as $version ) {
			if ( (int) ( $version['version'] ?? 0 ) >= $latest_number ) {
				$latest_number = (int) ( $version['version'] ?? 0 );
				$latest_id     = (string) ( $version['version_id'] ?? '' );
			}
		}

		echo '<h2>' . esc_html( (string) ( $project['name'] ?? '' ) ) . '</h2>';

		// Both axes, and the gates, stated together. This is the screen a person reads to
		// answer "can this ship", and all three answers belong in one place.
		echo '<ul class="replicaforge-facts">';
		echo '<li>' . esc_html__( 'Stage:', 'replicaforge' ) . ' <strong>' . esc_html( Workspace_Limits::stage_label( (string) $collab['stage'] ) ) . '</strong></li>';
		echo '<li>' . esc_html__( 'Status:', 'replicaforge' ) . ' <code>' . esc_html( (string) ( $project['status'] ?? '' ) ) . '</code></li>';
		echo '<li>' . esc_html__( 'Version:', 'replicaforge' ) . ' <strong>' . esc_html( (string) $latest_number ) . '</strong></li>';
		echo '<li>' . esc_html__( 'Completable:', 'replicaforge' ) . ' <strong>' . ( empty( $gates['completable'] ) ? esc_html__( 'No', 'replicaforge' ) : esc_html__( 'Yes', 'replicaforge' ) ) . '</strong></li>';
		if ( ! empty( $gates['reason'] ) ) {
			echo '<li>' . esc_html__( 'Outstanding:', 'replicaforge' ) . ' ' . esc_html( (string) $gates['reason'] ) . '</li>';
		}
		echo '</ul>';

		// ---- Versions.
		echo '<h3>' . esc_html__( 'Versions', 'replicaforge' ) . '</h3>';

		if ( array() === $versions ) {
			echo '<p>' . esc_html__( 'Nothing has been generated yet, so there is no version to review.', 'replicaforge' ) . '</p>';
		} else {
			echo '<table class="widefat striped replicaforge-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Version', 'replicaforge' ) . '</th>';
			echo '<th>' . esc_html__( 'Created', 'replicaforge' ) . '</th>';
			echo '<th>' . esc_html__( 'Change', 'replicaforge' ) . '</th>';
			echo '<th>' . esc_html__( 'Validation', 'replicaforge' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $versions as $version ) {
				echo '<tr>';
				echo '<td><strong>' . esc_html( (string) ( $version['version'] ?? 0 ) ) . '</strong></td>';
				echo '<td>' . esc_html( (string) ( $version['created_at'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $version['change'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $version['validation_id'] ?? '' ) ) . '</code></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		// ---- Reviews.
		$reviews = ( new Review_Store() )->reviews( $workspace_id, array( 'project_id' => $project_id, 'per_page' => 50 ) );

		echo '<h3>' . esc_html__( 'Reviews', 'replicaforge' ) . '</h3>';

		if ( 0 === (int) $reviews['count'] ) {
			echo '<p>' . esc_html__( 'No reviews requested.', 'replicaforge' ) . '</p>';
		} else {
			echo '<table class="widefat striped replicaforge-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Version', 'replicaforge' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'replicaforge' ) . '</th>';
			echo '<th>' . esc_html__( 'Reviewer', 'replicaforge' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'replicaforge' ) . '</th>';
			echo '<th>' . esc_html__( 'Decision note', 'replicaforge' ) . '</th>';
			echo '<th>' . esc_html__( 'Decide', 'replicaforge' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $reviews['items'] as $review ) {
				$decide = array( 'approve' => 'reviews.approve', 'reject' => 'reviews.reject', 'changes' => 'reviews.reject' );

				echo '<tr>';
				echo '<td><strong>' . esc_html( (string) $review['version_number'] ) . '</strong></td>';
				echo '<td>' . esc_html( (string) $review['type'] ) . '</td>';
				// A reviewer named by address has no account, so there is no display name
				// column on the review to read. Both are read defensively, and an empty
				// result renders as an em dash rather than as a bare gap - a row with an
				// empty cell reads as a rendering fault rather than as "no account".
				$reviewer_name = (string) ( $review['display_name'] ?? '' );
				if ( '' === trim( $reviewer_name ) ) {
					$reviewer_name = (string) ( $review['reviewer_email'] ?? '' );
				}
				echo '<td>' . esc_html( '' !== trim( $reviewer_name ) ? $reviewer_name : __( 'no account', 'replicaforge' ) ) . '</td>';
				echo '<td><span class="replicaforge-badge">' . esc_html( (string) $review['status'] ) . '</span></td>';
				echo '<td>' . esc_html( (string) $review['decision_note'] ) . '</td>';
				echo '<td>';

				if ( in_array( (string) $review['status'], Workspace_Limits::OPEN_REVIEW_STATUSES, true ) ) {
					foreach ( $decide as $action => $capability ) {
						/*
						 * Checked per review, not per workspace: this is the whole of the
						 * client-scoped grant, and it is why the same code path cannot
						 * reach a review the caller is not named on.
						 */
						if ( ! $this->permissions->can_act_on_review( $user_id, $workspace_id, $review, $capability ) ) {
							continue;
						}

						echo '<form method="post" class="replicaforge-inline-form">';
						wp_nonce_field( 'replicaforge_workspace_decide_review' );
						echo '<input type="hidden" name="replicaforge_action" value="decide_review" />';
						echo '<input type="hidden" name="workspace_id" value="' . esc_attr( $workspace_id ) . '" />';
						echo '<input type="hidden" name="review" value="' . esc_attr( (string) $review['public_id'] ) . '" />';
						echo '<input type="hidden" name="decision" value="' . esc_attr( $action ) . '" />';
						echo '<input type="text" name="note" placeholder="' . esc_attr__( 'Note', 'replicaforge' ) . '" /> ';
						echo '<button type="submit" class="button">' . esc_html( ucfirst( $action ) ) . '</button>';
						echo '</form>';
					}
				}

				// A link is only offered where the workspace permits it and the review is a
				// client review - the same two conditions the service itself enforces.
				if ( 'client' === (string) $review['type'] && $this->permissions->can( $user_id, $workspace_id, 'reviews.create' ) ) {
					echo '<form method="post" class="replicaforge-inline-form">';
					wp_nonce_field( 'replicaforge_workspace_create_review_link' );
					echo '<input type="hidden" name="replicaforge_action" value="create_review_link" />';
					echo '<input type="hidden" name="workspace_id" value="' . esc_attr( $workspace_id ) . '" />';
					echo '<input type="hidden" name="review" value="' . esc_attr( (string) $review['public_id'] ) . '" />';
					echo '<button type="submit" class="button">' . esc_html__( 'Create review link', 'replicaforge' ) . '</button>';
					echo '</form>';
				}

				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		// ---- Request a review.
		if ( $this->permissions->can_in_project( $user_id, $workspace_id, $project_id, 'reviews.create' ) && '' !== $latest_id ) {
			echo '<h3>' . esc_html__( 'Request a review', 'replicaforge' ) . '</h3>';

			echo '<form method="post" class="replicaforge-form">';
			wp_nonce_field( 'replicaforge_workspace_request_review' );
			echo '<input type="hidden" name="replicaforge_action" value="request_review" />';
			echo '<input type="hidden" name="workspace_id" value="' . esc_attr( $workspace_id ) . '" />';
			echo '<input type="hidden" name="project_id" value="' . esc_attr( $project_id ) . '" />';
			echo '<input type="hidden" name="version_id" value="' . esc_attr( $latest_id ) . '" />';

			echo '<p class="replicaforge-note">' . sprintf( /* translators: %s: version number. */ esc_html__( 'A review always names a version, and defaults to the newest (%s). §15 exists so an approval can never be ambiguous about what it approved.', 'replicaforge' ), esc_html( (string) $latest_number ) ) . '</p>';

			echo '<label><span>' . esc_html__( 'Reviewer email', 'replicaforge' ) . '</span><input type="email" name="reviewer_email" value="" /></label> ';
			echo '<label><span>' . esc_html__( 'Type', 'replicaforge' ) . '</span><select name="type">';
			foreach ( Workspace_Limits::REVIEW_TYPES as $type => $label ) {
				echo '<option value="' . esc_attr( $type ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></label> ';
			echo '<label><span>' . esc_html__( 'Note', 'replicaforge' ) . '</span><input type="text" name="note" value="" /></label> ';
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Request review', 'replicaforge' ) . '</button>';
			echo '</form>';
		}

		// ---- Comments.
		$comments = ( new Comment_Store() )->comments( $workspace_id, $project_id, array( 'per_page' => 50 ) );

		echo '<h3>' . esc_html__( 'Comments', 'replicaforge' ) . '</h3>';

		if ( 0 === (int) $comments['count'] ) {
			echo '<p>' . esc_html__( 'No comments yet.', 'replicaforge' ) . '</p>';
		} else {
			echo '<table class="widefat striped replicaforge-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Author', 'replicaforge' ) . '</th>';
			echo '<th>' . esc_html__( 'Comment', 'replicaforge' ) . '</th>';
			echo '<th>' . esc_html__( 'Anchor', 'replicaforge' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'replicaforge' ) . '</th>';
			echo '<th></th>';
			echo '</tr></thead><tbody>';

			foreach ( $comments['items'] as $comment ) {
				$anchor = Comment_Store::anchored( $comment );

				echo '<tr>';
				echo '<td>' . esc_html( (string) $comment['author_name'] ) . ( ! empty( $comment['is_client'] ) ? ' <span class="replicaforge-badge">' . esc_html__( 'client', 'replicaforge' ) . '</span>' : '' ) . '</td>';
				// Untrusted user text: escaped here, and the store already sanitised it on the
				// way in. Escaping on output as well is deliberate - sanitising on input is not
				// a licence to trust the value later.
				echo '<td>' . esc_html( (string) $comment['body'] ) . '</td>';

				// The anchor says what it is anchored *to*, not where on a screenshot. A
				// coordinate without a stable id is reported as a pinpoint, so nobody re-draws
				// a marker that will not survive a regeneration.
				echo '<td>';
				if ( ! empty( $anchor['has_stable'] ) ) {
					echo '<code>' . esc_html( (string) $anchor['stable_id'] ) . '</code> <span class="replicaforge-note">' . esc_html( (string) $anchor['kind'] ) . '</span>';
				} elseif ( ! empty( $anchor['has_region'] ) ) {
					echo '<span class="replicaforge-note">' . esc_html__( 'Position only', 'replicaforge' ) . '</span>';
				} else {
					echo '<span class="replicaforge-note">' . esc_html__( 'Whole project', 'replicaforge' ) . '</span>';
				}
				echo '</td>';

				echo '<td><span class="replicaforge-badge">' . esc_html( (string) $comment['status'] ) . '</span></td>';
				echo '<td>';

				if ( $this->permissions->can_in_project( $user_id, $workspace_id, $project_id, 'comments.resolve' ) ) {
					$reopening = in_array( (string) $comment['status'], array( 'resolved' ), true );

					echo '<form method="post" class="replicaforge-inline-form">';
					wp_nonce_field( 'replicaforge_workspace_resolve_comment' );
					echo '<input type="hidden" name="replicaforge_action" value="resolve_comment" />';
					echo '<input type="hidden" name="workspace_id" value="' . esc_attr( $workspace_id ) . '" />';
					echo '<input type="hidden" name="comment" value="' . esc_attr( (string) $comment['public_id'] ) . '" />';
					if ( $reopening ) {
						echo '<input type="hidden" name="reopen" value="1" />';
					}
					echo '<button type="submit" class="button">' . esc_html( $reopening ? __( 'Reopen', 'replicaforge' ) : __( 'Resolve', 'replicaforge' ) ) . '</button>';
					echo '</form>';
				}

				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		// ---- Post a comment, with an optional visual anchor.
		if ( $this->permissions->can_in_project( $user_id, $workspace_id, $project_id, 'comments.create' ) && '' !== $latest_id ) {
			echo '<h3>' . esc_html__( 'Add a comment', 'replicaforge' ) . '</h3>';

			echo '<form method="post" class="replicaforge-form">';
			wp_nonce_field( 'replicaforge_workspace_post_comment' );
			echo '<input type="hidden" name="replicaforge_action" value="post_comment" />';
			echo '<input type="hidden" name="workspace_id" value="' . esc_attr( $workspace_id ) . '" />';
			echo '<input type="hidden" name="project_id" value="' . esc_attr( $project_id ) . '" />';
			echo '<input type="hidden" name="version_id" value="' . esc_attr( $latest_id ) . '" />';

			echo '<p class="replicaforge-note">' . esc_html__( 'A comment always attaches to a version. Give it a stable anchor where one exists - a component or an element id - and coordinates only as a supplement; a position alone will not survive a regeneration.', 'replicaforge' ) . '</p>';

			echo '<label><span>' . esc_html__( 'Comment', 'replicaforge' ) . '</span><textarea name="body" rows="4" required></textarea></label> ';
			echo '<label><span>' . esc_html__( 'Anchor type', 'replicaforge' ) . '</span><select name="anchor_type">';
			foreach ( Workspace_Limits::COMMENT_ANCHORS as $anchor ) {
				echo '<option value="' . esc_attr( $anchor ) . '">' . esc_html( $anchor ) . '</option>';
			}
			echo '</select></label> ';
			echo '<label><span>' . esc_html__( 'Component id', 'replicaforge' ) . '</span><input type="text" name="component_id" value="" /></label> ';
			echo '<label><span>' . esc_html__( 'Element id', 'replicaforge' ) . '</span><input type="text" name="element_id" value="" /></label> ';
			echo '<label><span>' . esc_html__( 'Viewport', 'replicaforge' ) . '</span><select name="viewport"><option value="">-</option>';
			foreach ( array( 'desktop', 'tablet', 'mobile' ) as $viewport ) {
				echo '<option value="' . esc_attr( $viewport ) . '">' . esc_html( $viewport ) . '</option>';
			}
			echo '</select></label> ';
			echo '<label><span>' . esc_html__( 'Mention', 'replicaforge' ) . '</span><input type="text" name="mentions" value="" /></label> ';

			echo '<fieldset class="replicaforge-region"><legend>' . esc_html__( 'Optional position (percentages of the viewport)', 'replicaforge' ) . '</legend>';
			foreach ( array( 'x', 'y', 'width', 'height' ) as $axis ) {
				echo '<label><span>' . esc_html( ucfirst( $axis ) ) . '</span><input type="number" step="0.1" min="0" max="100" name="region_' . esc_attr( $axis ) . '" /></label> ';
			}
			echo '</fieldset>';

			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Post comment', 'replicaforge' ) . '</button>';
			echo '</form>';
		}

		// ---- Tasks and issues.
		$tasks  = ( new Task_Store() )->tasks( $workspace_id, $project_id, array( 'per_page' => 25 ) );
		$issues = ( new Issue_Store() )->issues( $workspace_id, $project_id, array( 'per_page' => 25 ) );

		echo '<h3>' . esc_html__( 'Tasks', 'replicaforge' ) . '</h3>';

		if ( 0 === (int) $tasks['count'] ) {
			echo '<p>' . esc_html__( 'No tasks.', 'replicaforge' ) . '</p>';
		} else {
			echo '<table class="widefat striped replicaforge-table"><thead><tr><th>' . esc_html__( 'Task', 'replicaforge' ) . '</th><th>' . esc_html__( 'Priority', 'replicaforge' ) . '</th><th>' . esc_html__( 'Status', 'replicaforge' ) . '</th><th>' . esc_html__( 'From', 'replicaforge' ) . '</th></tr></thead><tbody>';

			foreach ( $tasks['items'] as $task ) {
				echo '<tr><td>' . esc_html( (string) $task['title'] ) . '</td>';
				echo '<td>' . esc_html( (string) $task['priority'] ) . '</td>';
				echo '<td>' . esc_html( (string) $task['status'] ) . '</td>';
				// The provenance line. A task that cannot say where it came from is a task
				// somebody has to investigate from scratch.
				echo '<td>' . esc_html( (string) ( $task['promoted_from'] ?? '' ) ) . '</td></tr>';
			}

			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Issues', 'replicaforge' ) . '</h3>';

		if ( 0 === (int) $issues['count'] ) {
			echo '<p>' . esc_html__( 'No issues.', 'replicaforge' ) . '</p>';
		} else {
			echo '<table class="widefat striped replicaforge-table"><thead><tr><th>' . esc_html__( 'Issue', 'replicaforge' ) . '</th><th>' . esc_html__( 'Severity', 'replicaforge' ) . '</th><th>' . esc_html__( 'Status', 'replicaforge' ) . '</th><th>' . esc_html__( 'Source', 'replicaforge' ) . '</th></tr></thead><tbody>';

			foreach ( $issues['items'] as $issue ) {
				echo '<tr><td>' . esc_html( (string) $issue['title'] ) . '</td>';
				echo '<td>' . esc_html( (string) $issue['severity'] ) . '</td>';
				echo '<td>' . esc_html( (string) $issue['status'] ) . '</td>';
				echo '<td>' . esc_html( (string) $issue['source'] ) . '</td></tr>';
			}

			echo '</tbody></table>';
		}

		// ---- Activity.
		$timeline = ( new Collaboration_Log() )->timeline( $workspace_id, $project_id, array( 'per_page' => 25 ) );

		echo '<h3>' . esc_html__( 'Activity', 'replicaforge' ) . '</h3>';
		echo '<ol class="replicaforge-timeline">';

		foreach ( $timeline['items'] as $event ) {
			echo '<li><span class="replicaforge-timeline__summary">' . esc_html( (string) ( $event['summary'] ?? '' ) ) . '</span> <time>' . esc_html( (string) $event['created_at'] ) . '</time></li>';
		}

		echo '</ol>';

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Chrome
	 * ------------------------------------------------------------------ */

	/**
	 * Render the screen header.
	 *
	 * @param string $title Title.
	 * @param string $intro Introduction.
	 * @return void
	 */
	private function render_header( $title, $intro ) {
		echo '<header class="replicaforge-admin__header">';
		echo '<p class="replicaforge-admin__eyebrow">' . esc_html__( 'Collaboration', 'replicaforge' ) . '</p>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p class="replicaforge-admin__intro">' . esc_html( $intro ) . '</p>';
		echo '</header>';
	}

	/**
	 * Render a queued notice.
	 *
	 * @return void
	 */
	private function render_notices() {
		$notices = $this->notices();
		if ( array() === $notices ) {
			return;
		}

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				'error' === (string) $notice['type'] ? 'error' : 'success',
				wp_kses_post( (string) $notice['message'] )
			);
		}

		// Cleared as it is read, so a notice cannot be shown twice by a reload.
		$this->clear_notices();
	}

	/**
	 * Queue a notice for the next render.
	 *
	 * @param string $type    `success` or `error`.
	 * @param string $message Message.
	 * @param bool   $kses    Whether the message may contain markup.
	 * @return void
	 */
	private function notice( $type, $message, $kses = true ) {
		$notices   = $this->notices();
		$notices[] = array(
			'type'    => ( 'error' === (string) $type ) ? 'error' : 'success',
			'message' => $kses ? wp_kses_post( (string) $message ) : esc_html( (string) $message ),
		);

		// A transient, so a notice never outlives the request that queued it. Queuing into
		// the same request works because `handle_post()` redirects, and the render that
		// follows is a new request.
		set_transient( 'replicaforge_workspace_notices_' . get_current_user_id(), $notices, 60 );
	}

	/**
	 * Return the queued notices.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function notices() {
		$notices = get_transient( 'replicaforge_workspace_notices_' . get_current_user_id() );
		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * Discard the queued notices.
	 *
	 * @return void
	 */
	private function clear_notices() {
		delete_transient( 'replicaforge_workspace_notices_' . get_current_user_id() );
	}

	/**
	 * Refuse, and say so.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function deny( $message ) {
		$this->notice( 'error', $message );
		$this->redirect_back();
	}

	/**
	 * Return to the screen the action came from.
	 *
	 * @return void
	 */
	private function redirect_back() {
		$referer = wp_get_referer();

		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=' . self::PROJECTS_SLUG ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Fields
	 * ------------------------------------------------------------------ */

	/**
	 * Render an email field.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @return void
	 */
	private function field_email( $name, $label ) {
		echo '<label><span>' . esc_html( $label ) . '</span><input type="email" name="' . esc_attr( $name ) . '" value="" required /></label> ';
	}

	/**
	 * Render a role select.
	 *
	 * @param string $name Field name.
	 * @return void
	 */
	private function field_role( $name ) {
		echo '<label><span>' . esc_html__( 'Role', 'replicaforge' ) . '</span><select name="' . esc_attr( $name ) . '">';

		foreach ( Workspace_Limits::ASSIGNABLE_ROLES as $role ) {
			echo '<option value="' . esc_attr( $role ) . '">' . esc_html( Workspace_Limits::role_label( $role ) ) . '</option>';
		}

		echo '</select></label> ';
	}

	/**
	 * Constructor.
	 *
	 * The stores are built once here rather than per screen, so a page that renders a
	 * hundred rows does not construct a hundred gateways.
	 */
	public function __construct() {
		$this->permissions   = new Permission_Manager();
		$this->log           = new Collaboration_Log();
		$this->invitations   = new Invitation_Service();
		$this->links         = new Review_Link_Service();
		$this->workspaces    = new Workspace_Store();
		$this->notifications = new Notification_Service();
	}
}
