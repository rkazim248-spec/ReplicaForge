<?php
/**
 * Phase 15: workspace and project invitations.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Inviting somebody into a workspace, or onto a project.
 *
 * ### The five failure cases §13 lists, and where each is answered
 *
 * | Case | Answered by | Reason it cannot be answered elsewhere |
 * |---|---|---|
 * | expired | {@see self::accept()} | the row's `expires_at`, compared against now |
 * | revoked | {@see self::accept()} | the row's status, already `revoked` |
 * | already accepted | {@see self::accept()} | the status, already `accepted` |
 * | wrong workspace | {@see self::accept()} | the project is resolved *through* the workspace |
 * | wrong account | {@see self::accept()} | the signed-in address against the invited one |
 *
 * All five return the *same* answer to the caller: a refusal naming the case. That is
 * deliberate. If an expired invitation said "expired" and a wrong-workspace one said
 * "no such invitation", the difference is a probe: anybody holding a token could learn
 * which workspaces have outstanding invitations and whether a given address was ever
 * invited. One shape of refusal, five causes.
 *
 * ### The address is matched, never asserted
 *
 * An invitation names an address. Accepting it requires that the signed-in user's own
 * address matches. The address is never taken from the request, because the whole security
 * property of an emailed invitation is that the *link* proves the address — a request
 * that supplies its own address proves nothing at all.
 *
 * A workspace administrator is the one exception, and it is deliberate: an admin can
 * accept on behalf of a colleague who has not yet signed up, which is a real agency
 * workflow. It requires `members.invite` *and* `members.remove` — the two capabilities
 * together are what "manage membership" means — and it is audited.
 */
final class Invitation_Service {

	/**
	 * The invitation store.
	 *
	 * @var Invitation_Store
	 */
	private $invitations;

	/**
	 * The member stores.
	 *
	 * @var Workspace_Member_Store
	 */
	private $members;

	/**
	 * @var Project_Member_Store
	 */
	private $project_members;

	/**
	 * The permissions.
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
	 * The notifications.
	 *
	 * @var Notification_Service
	 */
	private $notifications;

	/**
	 * Constructor.
	 *
	 * @param Invitation_Store|null       $invitations      Optional store.
	 * @param Workspace_Member_Store|null $members          Optional member store.
	 * @param Project_Member_Store|null   $project_members  Optional project member store.
	 * @param Permission_Manager|null     $permissions      Optional permissions.
	 * @param Collaboration_Log|null      $log              Optional log.
	 * @param Notification_Service|null   $notifications    Optional notifications.
	 */
	public function __construct( $invitations = null, $members = null, $project_members = null, $permissions = null, $log = null, $notifications = null ) {
		$this->invitations     = $invitations instanceof Invitation_Store ? $invitations : new Invitation_Store();
		$this->members         = $members instanceof Workspace_Member_Store ? $members : new Workspace_Member_Store();
		$this->project_members = $project_members instanceof Project_Member_Store ? $project_members : new Project_Member_Store();
		$this->permissions     = $permissions instanceof Permission_Manager ? $permissions : new Permission_Manager();
		$this->log             = $log instanceof Collaboration_Log ? $log : new Collaboration_Log();
		$this->notifications   = $notifications instanceof Notification_Service ? $notifications : new Notification_Service();
	}

	/* ---------------------------------------------------------------------
	 * Issuing
	 * ------------------------------------------------------------------ */

	/**
	 * Invite somebody to a workspace, or to a project within one.
	 *
	 * The plaintext token is returned **once**, in the result, and is never recoverable
	 * afterwards — only its hash is stored. The caller is expected to put it in a link and
	 * send it; if the link is lost, the answer is to revoke and re-invite, which is what
	 * §13's revocation requirement is for.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $email        Address to invite.
	 * @param string               $role         Workspace role.
	 * @param array<string, mixed> $options      `project_id`, `project_role`, `inviter_id`.
	 * @return array<string, mixed>
	 */
	public function invite( $workspace_id, $email, $role, array $options = array() ) {
		$workspace_id = (string) $workspace_id;
		$email        = sanitize_email( (string) $email );
		$role         = strtolower( trim( (string) $role ) );
		$inviter_id   = (int) ( $options['inviter_id'] ?? get_current_user_id() );

		if ( '' === $workspace_id || '' === $email || ! is_email( $email ) ) {
			return $this->refuse( 'invalid', __( 'That address is not usable.', 'replicaforge' ) );
		}

		// `owner` is refused here as well as in the member store. Two gates would be
		// redundant if one of them could be bypassed; this one is the gate a caller
		// reaches first, and the store's is the one that holds if this is bypassed.
		if ( ! in_array( $role, Workspace_Limits::ASSIGNABLE_ROLES, true ) ) {
			return $this->refuse( 'forbidden', __( 'That role cannot be assigned by invitation.', 'replicaforge' ) );
		}

		$capability = ( '' !== (string) ( $options['project_id'] ?? '' ) ) ? 'projects.manage_members' : 'members.invite';
		if ( ! $this->permissions->can( $inviter_id, $workspace_id, $capability ) ) {
			return $this->refuse( 'forbidden', __( 'You cannot invite people here.', 'replicaforge' ) );
		}

		// Already a member? There is nothing to invite. Refusing is more useful than
		// storing an invitation that would be accepted into a membership that already
		// exists, which is how a "pending" count gets permanently inflated.
		if ( null !== $this->members->membership_by_email( $workspace_id, $email ) ) {
			return $this->refuse( 'already_member', __( 'That address is already a member of this workspace.', 'replicaforge' ) );
		}

		$project_id = (string) ( $options['project_id'] ?? '' );
		$project_role = (string) ( $options['project_role'] ?? '' );

		if ( '' !== $project_id ) {
			// The project is resolved *through* the workspace, so an invitation for a
			// project in another workspace is not found rather than found-and-refused.
			$owned = ( new Project_Context_Store() )->owned( $workspace_id, $project_id );
			if ( null === $owned ) {
				return $this->refuse( 'not_found', __( 'That invitation could not be completed.', 'replicaforge' ) );
			}
			if ( '' !== $project_role && ! in_array( $project_role, Workspace_Limits::PROJECT_ROLES, true ) ) {
				return $this->refuse( 'invalid', __( 'That project role does not exist.', 'replicaforge' ) );
			}
		} elseif ( '' !== $project_role ) {
			// A project role with no project is a caller mistake, and accepting it would
			// store a role nobody can ever be granted.
			return $this->refuse( 'invalid', __( 'A project role needs a project.', 'replicaforge' ) );
		}

		// An outstanding invitation for the same address is revoked rather than left to
		// accumulate. Two live invitations for one address means the earlier one is either
		// lost or was superseded, and it should not still work.
		$this->revoke_pending( $workspace_id, $email );

		$issued = Secure_Token::issue();
		$scope  = ( '' === $project_id ) ? 'workspace' : 'project';

		$invitation = $this->invitations->create(
			$workspace_id,
			array(
				'email'        => $email,
				'role'         => $role,
				'project_id'   => $project_id,
				'project_role' => $project_role,
				'token_hash'   => $issued['hash'],
				'inviter_id'   => $inviter_id,
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + Workspace_Limits::INVITATION_TTL[ $scope ] ),
			)
		);

		if ( null === $invitation ) {
			return $this->refuse( 'failed', __( 'The invitation could not be stored.', 'replicaforge' ) );
		}

		$this->log->audit(
			$workspace_id,
			'member_invited',
			array(
				'project_id' => $project_id,
				'target_type'=> 'invitation',
				'target_id'  => (string) $invitation['public_id'],
				// The address is stored. An audit row that cannot name who was invited
				// cannot answer "was this person ever offered access", which is the
				// question an audit exists for. The *token* is never stored.
				'metadata'   => array(
					'email'     => $email,
					'role'      => $role,
					'scope'     => $scope,
					'ref'       => Secure_Token::reference( $issued['hash'] ),
					'expires_at'=> (string) $invitation['expires_at'],
				),
			),
			$inviter_id
		);

		return array(
			'ok'         => true,
			'invitation' => $invitation,
			// Returned once, and never recoverable again.
			'token'      => $issued['token'],
			'expires_at' => (string) $invitation['expires_at'],
			'url'        => Secure_Token::token_url(
				home_url( '/' ),
				$issued['token'],
				'replicaforge_invitation'
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Accepting
	 * ------------------------------------------------------------------ */

	/**
	 * Accept an invitation.
	 *
	 * @param string               $token      Plaintext token from the link.
	 * @param array<string, mixed> $options    `user_id`, `accept_on_behalf`.
	 * @return array<string, mixed>
	 */
	public function accept( $token, array $options = array() ) {
		$user_id = (int) ( $options['user_id'] ?? get_current_user_id() );

		if ( ! Secure_Token::looks_valid( $token ) ) {
			return $this->refuse( 'invalid', __( 'That invitation link is not valid.', 'replicaforge' ) );
		}

		$invitation = $this->invitations->by_token( $token );
		if ( null === $invitation ) {
			return $this->refuse( 'invalid', __( 'That invitation link is not valid.', 'replicaforge' ) );
		}

		// From here on, every refusal is the *same* refusal. The cases below are
		// distinguishable to somebody reading this method, and indistinguishable to
		// somebody holding a token - which is the point. See the class docblock.
		$generic = __( 'That invitation link is no longer valid.', 'replicaforge' );

		if ( 'accepted' === (string) $invitation['status'] ) {
			return $this->refuse( 'already_accepted', $generic );
		}
		if ( 'revoked' === (string) $invitation['status'] ) {
			return $this->refuse( 'revoked', $generic );
		}
		if ( '' !== (string) $invitation['expires_at'] && strtotime( (string) $invitation['expires_at'] ) < time() ) {
			// Recorded as expired, so the state is visible to an administrator rather than
			// the row sitting `pending` forever and the link appearing merely dead.
			$this->invitations->expire( (string) $invitation['public_id'] );
			return $this->refuse( 'expired', $generic );
		}

		$accepting_user = $this->resolve_acceptor( $invitation, $user_id, $options );
		if ( null === $accepting_user ) {
			return $this->refuse( 'wrong_account', $generic );
		}

		$workspace_id = (string) $invitation['workspace_id'];

		// Claim the invitation before granting anything. Two concurrent clicks on a link
		// would otherwise both pass the status check and both create a membership; the
		// claim is a single conditional update, so exactly one wins.
		$claimed = $this->invitations->claim( (string) $invitation['public_id'], $accepting_user );
		if ( false === $claimed ) {
			return $this->refuse( 'already_accepted', $generic );
		}

		$member = $this->members->add(
			$workspace_id,
			array(
				'user_id'      => $accepting_user,
				'email'        => (string) $invitation['email'],
				'role'         => (string) $invitation['role'],
				'invited_by'   => (int) $invitation['created_by'],
			)
		);

		if ( null === $member && null === $this->members->membership( $workspace_id, $accepting_user ) ) {
			// The invitation is now consumed but no membership exists. The row is put back
			// to `pending` so the link still works: a failed grant must not also destroy the
			// recipient's ability to try again.
			$this->invitations->release( (string) $invitation['public_id'] );
			return $this->refuse( 'failed', __( 'The membership could not be created.', 'replicaforge' ) );
		}

		$project_id = (string) $invitation['project_id'];
		if ( '' !== $project_id && '' !== (string) $invitation['project_role'] ) {
			$this->project_members->add( $workspace_id, $project_id, array( 'user_id' => $accepting_user, 'role' => (string) $invitation['project_role'] ) );
		}

		$this->log->audit(
			$workspace_id,
			'invitation_accepted',
			array(
				'project_id'  => $project_id,
				'target_type' => 'invitation',
				'target_id'   => (string) $invitation['public_id'],
				'metadata'    => array(
					'role'   => (string) $invitation['role'],
					'ref'    => Secure_Token::reference( (string) $invitation['token_hash'] ),
					'on_behalf' => ! empty( $options['accept_on_behalf'] ),
				),
			),
			$accepting_user
		);

		$this->log->activity(
			$workspace_id,
			'member_added',
			array(
				'project_id'    => $project_id,
				'resource_type' => 'member',
				'resource_id'   => (string) $member['public_id'],
				'metadata'      => array( 'name' => (string) ( $member['display_name'] ?? '' ), 'role' => (string) $invitation['role'] ),
			),
			$accepting_user
		);

		$this->notifications->notify(
			$workspace_id,
			'member_added',
			array( 'project_id' => $project_id, 'actor_id' => $accepting_user )
		);

		return array(
			'ok'          => true,
			'workspace_id'=> $workspace_id,
			'project_id'  => $project_id,
			'role'        => (string) $invitation['role'],
			'member'      => $member,
		);
	}

	/* ---------------------------------------------------------------------
	 * Revoking
	 * ------------------------------------------------------------------ */

	/**
	 * Revoke one invitation.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Invitation public id.
	 * @param int    $actor_id     Actor.
	 * @return array<string, mixed>
	 */
	public function revoke( $workspace_id, $public_id, $actor_id = 0 ) {
		$actor_id = (int) $actor_id;
		if ( ! $this->permissions->can( $actor_id, (string) $workspace_id, 'members.invite' ) ) {
			return $this->refuse( 'forbidden', __( 'You cannot revoke invitations here.', 'replicaforge' ) );
		}

		$revoked = $this->invitations->revoke( $workspace_id, $public_id );
		if ( null === $revoked ) {
			return $this->refuse( 'not_found', __( 'That invitation could not be found.', 'replicaforge' ) );
		}

		$this->log->audit(
			$workspace_id,
			'invitation_revoked',
			array(
				'target_type' => 'invitation',
				'target_id'   => (string) $public_id,
				'metadata'    => array( 'email' => (string) $revoked['email'], 'ref' => Secure_Token::reference( (string) $revoked['token_hash'] ) ),
			),
			$actor_id
		);

		return array( 'ok' => true, 'invitation' => $this->invitations->present( $revoked ) );
	}

	/**
	 * Revoke every outstanding invitation for an address.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $email        Address.
	 * @return int Revoked.
	 */
	private function revoke_pending( $workspace_id, $email ) {
		return $this->invitations->revoke_pending( $workspace_id, $email );
	}

	/**
	 * Return the outstanding invitations for a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function outstanding( $workspace_id, array $args = array() ) {
		$page = $this->invitations->outstanding( $workspace_id, $args );
		foreach ( $page['items'] as $index => $invitation ) {
			$page['items'][ $index ] = $this->invitations->present( $invitation );
		}
		return $page;
	}

	/**
	 * Return the count of outstanding invitations.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return int
	 */
	public function outstanding_count( $workspace_id ) {
		return $this->invitations->pending_count( $workspace_id );
	}

	/**
	 * Sweep expired invitations, so a team screen does not fill with dead rows.
	 *
	 * @return int Expired.
	 */
	public function expire_stale() {
		return $this->invitations->expire_stale();
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Decide who is accepting, and refuse when nobody can.
	 *
	 * @param array<string, mixed> $invitation Invitation.
	 * @param int                  $user_id    Candidate.
	 * @param array<string, mixed> $options    Options.
	 * @return int|null Accepting user id, or null.
	 */
	private function resolve_acceptor( array $invitation, $user_id, array $options ) {
		$user_id = (int) $user_id;
		$invited = strtolower( (string) $invitation['email'] );

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User && strtolower( (string) $user->user_email ) === $invited ) {
				return $user_id;
			}

			// An administrator accepting on a colleague's behalf. Real agency workflow:
			// somebody is invited before they have an account, and the admin who invited
			// them wants them seated now. It requires both membership capabilities, and it
			// is audited, because it is the one path where the address is not the
			// acceptor's own.
			if ( ! empty( $options['accept_on_behalf'] )
				&& $this->permissions->can( $user_id, (string) $invitation['workspace_id'], 'members.invite' )
				&& $this->permissions->can( $user_id, (string) $invitation['workspace_id'], 'members.remove' ) ) {
				// Bind to the invited address, which becomes the account to seat.
				$by_email = get_user_by( 'email', $invited );
				if ( $by_email instanceof \WP_User ) {
					return (int) $by_email->ID;
				}
				$invited_user = wp_insert_user( array(
					'user_login'   => $this->proposed_login( $invited ),
					'user_email'   => $invited,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'role'         => 'subscriber',
					// A password nobody knows. The real credential arrives by the agency's
					// own out-of-band process; this account exists so the membership has
					// somebody to attach to, and a password reset is the expected next step.
					'show_admin_bar_front' => 'false',
				) );
				return is_wp_error( $invited_user ) ? null : (int) $invited_user;
			}

			return null;
		}

		// Not signed in. The link alone is not enough — the recipient has to prove the
		// address is theirs by authenticating, which is §13's "user authentication" step.
		return null;
	}

	/**
	 * Return a usable login derived from an address.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private function proposed_login( $email ) {
		$base = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base = ( '' === $base ) ? 'invited' : $base;
		$base = substr( $base, 0, 20 );

		$candidate = $base;
		$suffix    = 1;
		while ( get_user_by( 'login', $candidate ) ) {
			$suffix++;
			$candidate = $base . $suffix;
		}
		return $candidate;
	}

	/**
	 * Return a refusal.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Message.
	 * @return array<string, mixed>
	 */
	private function refuse( $code, $message ) {
		return array(
			'ok'      => false,
			'code'    => (string) $code,
			'message' => (string) $message,
		);
	}
}

/**
 * Storage for invitations.
 *
 * Kept separate from {@see Invitation_Service} because the state machine — claim, expire,
 * revoke — is where a concurrency bug would live, and it is worth being able to read it
 * without also reading the invitation-link construction.
 */
final class Invitation_Store extends Collaboration_Store {

	/**
	 * Return an invitation by its public id.
	 *
	 * The store could be read by token and mutated by status, but had no public getter by
	 * public id, so the only way to read one back was through a method that also changed it.
	 * The base's `find()` is protected and stays that way - it is a raw gateway that takes
	 * a workspace scope, and a public entity accessor belongs on the entity.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Invitation public id.
	 * @return array<string, mixed>|null
	 */
	public function get( $workspace_id, $public_id ) {
		return $this->find( (string) $workspace_id, (string) $public_id );
	}
	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'invitations';

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array( 'public_id', 'workspace_id', 'project_id', 'email', 'role', 'project_role', 'token_hash', 'status', 'created_by', 'expires_at', 'accepted_at', 'accepted_by', 'created_at' );
	}

	/**
	 * Return the storage type of each column.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'public_id'    => 'string',
			'workspace_id' => 'string',
			'project_id'   => 'string',
			'email'        => 'email',
			'role'         => 'line',
			'project_role' => 'line',
			'token_hash'   => 'string',
			'status'       => 'line',
			'created_by'   => 'int',
			'expires_at'   => 'datetime',
			'accepted_at'  => 'datetime',
			'accepted_by'  => 'int',
			'created_at'   => 'datetime',
		);
	}

	/**
	 * Return the columns free text may search.
	 *
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'email' );
	}

	/**
	 * Create an invitation.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param array<string, mixed> $data         Invitation data.
	 * @return array<string, mixed>|null
	 */
	public function create( $workspace_id, array $data ) {
		$workspace_id = (string) $workspace_id;
		$token_hash   = (string) ( $data['token_hash'] ?? '' );

		if ( '' === $workspace_id || 64 !== strlen( $token_hash ) || ! $this->ready() ) {
			return null;
		}

		return $this->insert(
			array(
				'public_id'    => $this->new_public_id(),
				'workspace_id' => $workspace_id,
				'project_id'   => (string) ( $data['project_id'] ?? '' ),
				'email'        => (string) ( $data['email'] ?? '' ),
				'role'         => (string) ( $data['role'] ?? 'reviewer' ),
				'project_role' => (string) ( $data['project_role'] ?? '' ),
				'token_hash'   => $token_hash,
				'status'       => 'pending',
				'created_by'   => max( 0, (int) ( $data['inviter_id'] ?? 0 ) ),
				'expires_at'   => (string) ( $data['expires_at'] ?? '' ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Update an invitation.
	 *
	 * ### Why this exists
	 *
	 * Every other store in this layer has a public `update()`, and an invitation genuinely
	 * needs one: an administrator shortening an expiry, or correcting an address that was
	 * invited under the wrong one. Without it the only way to write the row was the
	 * protected `update_row()`, and the two protected helpers that do exist - `revoke()` and
	 * `expire()` - only reach the two fields they are about, so nothing could adjust an
	 * expiry at all.
	 *
	 * Narrowed to the fields an administrator may reasonably change. `token_hash` is
	 * excluded on purpose: rotating a token is `Review_Link_Service`'s job and happens
	 * through `create()`, so that rotating and auditing stay in one place.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $public_id    Invitation public id.
	 * @param array<string, mixed> $changes     Changes.
	 * @return array<string, mixed>|null The stored invitation, or null.
	 */
	public function update( $workspace_id, $public_id, array $changes ) {
		$invitation = $this->find( (string) $workspace_id, (string) $public_id );
		if ( null === $invitation ) {
			return null;
		}

		$clean = array();
		foreach ( array( 'email', 'role', 'project_role', 'expires_at' ) as $column ) {
			if ( array_key_exists( $column, $changes ) ) {
				$clean[ $column ] = $changes[ $column ];
			}
		}
		if ( array() === $clean ) {
			return null;
		}

		$this->update_row( (string) $public_id, $clean );
		return $this->find( (string) $workspace_id, (string) $public_id );
	}

	/**
	 * Return an invitation by its plaintext token.
	 *
	 * @param string $token Plaintext token.
	 * @return array<string, mixed>|null
	 */
	public function by_token( $token ) {
		$token = (string) $token;
		if ( ! Secure_Token::looks_valid( $token ) || ! $this->ready() ) {
			return null;
		}
		// The lookup is by the *computed* hash, so the plaintext token is never a query
		// parameter anywhere, not even transiently in a `WHERE` clause that might reach a
		// slow-query log.
		return $this->find_where( '*', array( 'token_hash' => Secure_Token::hash( $token ) ) );
	}

	/**
	 * Claim an invitation for acceptance.
	 *
	 * ### One conditional UPDATE, not a read-then-write
	 *
	 * The obvious implementation reads the row, checks the status, and writes `accepted`.
	 * Two people clicking the same link at the same moment both read `pending`, and both
	 * create a membership. A single `UPDATE ... WHERE status = 'pending'` is atomic, so
	 * exactly one caller sees a changed row and the other is told it is already accepted.
	 *
	 * This is the race §41 names, and it is the only place in this phase where a read
	 * followed by a write would be wrong.
	 *
	 * @param string $public_id  Invitation public id.
	 * @param int    $accepted_by Acceptor.
	 * @return bool
	 */
	public function claim( $public_id, $accepted_by ) {
		global $wpdb;

		if ( ! $this->ready() ) {
			return false;
		}

		$table = $this->table();
		$rows  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'accepted', accepted_at = %s, accepted_by = %d WHERE public_id = %s AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				gmdate( 'Y-m-d H:i:s' ),
				(int) $accepted_by,
				(string) $public_id
			)
		);

		return ( 1 === (int) $rows );
	}

	/**
	 * Put a claimed invitation back to pending.
	 *
	 * Used when the membership could not be created after the claim succeeded. Without it,
	 * a transient failure would consume the recipient's link permanently.
	 *
	 * @param string $public_id Invitation public id.
	 * @return bool
	 */
	public function release( $public_id ) {
		$this->update_row( (string) $public_id, array( 'status' => 'pending', 'accepted_at' => null, 'accepted_by' => 0 ) );
		return true;
	}

	/**
	 * Revoke one invitation.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Invitation public id.
	 * @return array<string, mixed>|null
	 */
	public function revoke( $workspace_id, $public_id ) {
		$invitation = $this->find( (string) $workspace_id, (string) $public_id );
		if ( null === $invitation || ! in_array( (string) $invitation['status'], array( 'pending' ), true ) ) {
			// Only a pending invitation is revocable. An accepted one cannot be un-accepted
			// — the membership exists, and removing it is a different, separately
			// permissioned operation.
			return null;
		}
		$this->update_row( (string) $public_id, array( 'status' => 'revoked' ) );
		return $this->find( (string) $workspace_id, (string) $public_id );
	}

	/**
	 * Revoke every pending invitation for an address.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $email        Address.
	 * @return int Revoked.
	 */
	public function revoke_pending( $workspace_id, $email ) {
		global $wpdb;
		if ( ! $this->ready() ) {
			return 0;
		}
		$table = $this->table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'revoked' WHERE workspace_id = %s AND email = %s AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				(string) $workspace_id,
				strtolower( trim( (string) $email ) )
			)
		);
	}

	/**
	 * Mark one invitation expired.
	 *
	 * @param string $public_id Invitation public id.
	 * @return bool
	 */
	public function expire( $public_id ) {
		$this->update_row( (string) $public_id, array( 'status' => 'expired' ) );
		return true;
	}

	/**
	 * Mark every past-due pending invitation expired.
	 *
	 * @return int Expired.
	 */
	public function expire_stale() {
		global $wpdb;
		if ( ! $this->ready() ) {
			return 0;
		}
		$table = $this->table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'expired' WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}


	/**
	 * Return the number of pending invitations for a workspace.
	 *
	 * Public because `Invitation_Service::outstanding_count()` needs it, and the base
	 * `count_where()` is protected. Reaching across into a protected gateway is not a
	 * matter of style: it is a compile-time-or-not call that happens to work only while the
	 * two classes stay in the same inheritance position, and it turns into a fatal the
	 * moment either is refactored.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return int
	 */
	public function pending_count( $workspace_id ) {
		return $this->count_where( (string) $workspace_id, array( 'status' => 'pending' ) );
	}

	/**
	 * Return the outstanding invitations for a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function outstanding( $workspace_id, array $args = array() ) {
		return $this->query( (string) $workspace_id, array_merge( array( 'status' => 'pending' ), $args ) );
	}

	/**
	 * Present an invitation, without its token hash.
	 *
	 * The hash is not a secret — it cannot be turned back into a token — but there is no
	 * reason to put it in a response a client might log, and a field that is never
	 * returned cannot be leaked by a caller that forgets.
	 *
	 * @param array<string, mixed> $invitation Invitation.
	 * @return array<string, mixed>
	 */
	public static function present( array $invitation ) {
		$token_hash = (string) ( $invitation['token_hash'] ?? '' );
		unset( $invitation['token_hash'] );
		$invitation['token_ref'] = Secure_Token::reference( $token_hash );
		return $invitation;
	}
}
