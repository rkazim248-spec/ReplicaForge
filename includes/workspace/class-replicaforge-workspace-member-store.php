<?php
/**
 * Phase 15: the workspace membership store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Who is in a workspace, and in what capacity.
 *
 * ### A membership row is not the grant
 *
 * `owner` appears in a membership row so that listing members shows the owner, and its
 * `role` column is written for display. The *permission* still comes from
 * `workspace.owner_id`, which is why a row cannot be promoted to `owner` through this
 * class: `ASSIGNABLE_ROLES` excludes it, and an attempt is refused rather than stored.
 *
 * That separation is the difference between "the owner can be demoted" — a supported,
 * audited operation that moves `owner_id` — and "the owner can be impersonated by editing
 * a row".
 */
final class Workspace_Member_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'members';

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array( 'public_id', 'workspace_id', 'user_id', 'email', 'display_name', 'role', 'status', 'invited_by', 'joined_at', 'last_seen_at', 'created_at', 'updated_at' );
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
			'user_id'      => 'int',
			'email'        => 'email',
			'display_name' => 'line',
			'role'         => 'line',
			'status'       => 'line',
			'invited_by'   => 'int',
			'joined_at'    => 'datetime',
			'last_seen_at' => 'datetime',
			'created_at'   => 'datetime',
			'updated_at'   => 'datetime',
		);
	}

	/**
	 * Return the columns free text may search.
	 *
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'email', 'display_name' );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return a user's membership in a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @return array<string, mixed>|null
	 */
	public function membership( $workspace_id, $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return null;
		}
		return $this->find_where( (string) $workspace_id, array( 'user_id' => $user_id ) );
	}

	/**
	 * Return a membership by the email it was invited with.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $email        Email address.
	 * @return array<string, mixed>|null
	 */
	public function membership_by_email( $workspace_id, $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return null;
		}
		return $this->find_where( (string) $workspace_id, array( 'email' => $email ) );
	}

	/**
		* Return a membership by its public id.
		*
		* Public because `Workspace_Api::update_member()` needs it. The base class
		* `find()` is protected and stays that way: it is a raw gateway that takes a
		* workspace scope, and reaching across into it is a fatal the moment either
		* class is refactored.
		*
		* @param string $workspace_id Workspace id.
		* @param string $public_id    Membership public id.
		* @return array<string, mixed>|null
	*/
	public function get( $workspace_id, $public_id ) {
		return $this->find( (string) $workspace_id, (string) $public_id );
	}

	/**
	 * Return a page of a workspace's members.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function members( $workspace_id, array $args = array() ) {
		$page                = $this->query( (string) $workspace_id, $args );
		$page['roles']       = $this->role_counts( (string) $workspace_id );
		return $page;
	}

	/**
	 * Return the number of active members, for the cap check.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return int
	 */
	public function active_count( $workspace_id ) {
		return $this->count_where( (string) $workspace_id, array( 'status' => 'active' ) );
	}

	/**
	 * Return a role histogram, for the team screen.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, int>
	 */
	public function role_counts( $workspace_id ) {
		return $this->group_counts( (string) $workspace_id, 'role', array( 'status' => 'active' ) );
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Add a member to a workspace.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param array<string, mixed> $data         Member data.
	 * @return array<string, mixed>|null
	 */
	public function add( $workspace_id, array $data ) {
		$workspace_id = (string) $workspace_id;
		if ( '' === $workspace_id || ! $this->ready() ) {
			return null;
		}

		$user_id = (int) ( $data['user_id'] ?? 0 );
		$email   = strtolower( trim( (string) ( $data['email'] ?? '' ) ) );

		if ( $user_id < 1 && '' === $email ) {
			// A member with neither an account nor an address is not a member; it is a
			// row someone forgot to fill in.
			return null;
		}

		$role = $this->clean_role( (string) ( $data['role'] ?? 'reviewer' ) );
		if ( '' === $role ) {
			return null;
		}

		// Idempotent by identity, not by row. Adding someone twice returns the row that
		// already exists rather than a duplicate, and a duplicate would make
		// `workspace_user`'s unique index reject the insert with a database error instead
		// of a clean answer.
		$existing = $this->existing( $workspace_id, $user_id, $email );
		if ( null !== $existing ) {
			return $existing;
		}

		if ( $user_id > 0 && $this->active_count( $workspace_id ) >= Workspace_Limits::MAX_MEMBERS ) {
			$this->logger->warning(
				'workspace_member_cap',
				'A workspace reached its member limit, so the invitation was not stored.',
				array( 'workspace' => $workspace_id, 'limit' => Workspace_Limits::MAX_MEMBERS ),
				'workspace'
			);
			return null;
		}

		$display = (string) ( $data['display_name'] ?? '' );
		if ( '' === $display && $user_id > 0 ) {
			$user    = get_userdata( $user_id );
			$display = ( $user instanceof \WP_User ) ? (string) $user->display_name : '';
		}
		if ( '' === $email && $user_id > 0 ) {
			$user  = get_userdata( $user_id );
			$email = ( $user instanceof \WP_User ) ? strtolower( (string) $user->user_email ) : '';
		}

		$member = $this->insert(
			array(
				'public_id'    => $this->new_public_id(),
				'workspace_id' => $workspace_id,
				'user_id'      => $user_id,
				'email'        => $email,
				'display_name' => substr( $display, 0, 120 ),
				'role'         => $role,
				'status'       => 'active',
				'invited_by'   => (int) ( $data['invited_by'] ?? 0 ),
				'joined_at'    => ( $user_id > 0 ) ? gmdate( 'Y-m-d H:i:s' ) : null,
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		Permission_Manager::flush();
		return $member;
	}

	/**
	 * Record the workspace owner as a member.
	 *
	 * ### Why this is a separate method
	 *
	 * `add()` refuses the role `owner`, deliberately: `ASSIGNABLE_ROLES` excludes it, so a
	 * role-change request can never mint an owner or create a second one. That refusal is
	 * correct and stays.
	 *
	 * It also meant `Workspace_Store::create()` could not record the owner, because it was
	 * calling `add( ... 'role' => 'owner' )` and getting a silent `null` back. The owner
	 * then had *no membership row at all* - invisible in the team list, and passing
	 * permission checks only because the test user was also a site administrator. A bug
	 * that a permissive default was hiding.
	 *
	 * So the owner is recorded here, by a method whose name says what it does. The
	 * `owner` role on the row is for display; the *permission* still comes from
	 * `workspace.owner_id`, which is why this cannot grant anything by itself.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $owner_id     Owner user id.
	 * @return array<string, mixed>|null
	 */
	public function add_owner( $workspace_id, $owner_id ) {
		$workspace_id = (string) $workspace_id;
		$owner_id     = (int) $owner_id;
		if ( '' === $workspace_id || $owner_id < 1 || ! $this->ready() ) {
			return null;
		}

		$existing = $this->membership( $workspace_id, $owner_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$user = get_userdata( $owner_id );

		$owner = $this->insert(
			array(
				'public_id'    => $this->new_public_id(),
				'workspace_id' => $workspace_id,
				'user_id'      => $owner_id,
				'email'        => ( $user instanceof \WP_User ) ? strtolower( (string) $user->user_email ) : '',
				'display_name' => ( $user instanceof \WP_User ) ? substr( (string) $user->display_name, 0, 120 ) : '',
				'role'         => 'owner',
				'status'       => 'active',
				'joined_at'    => gmdate( 'Y-m-d H:i:s' ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		Permission_Manager::flush();
		return $owner;
	}

	/**
	 * Change a member's role.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Membership public id.
	 * @param string $role         New role.
	 * @return array<string, mixed>|null
	 */
	public function set_role( $workspace_id, $public_id, $role ) {
		$role = $this->clean_role( (string) $role );
		if ( '' === $role ) {
			return null;
		}

		$member = $this->find( (string) $workspace_id, (string) $public_id );
		if ( null === $member ) {
			return null;
		}

		// The owner own row is a display record, not the grant - the grant is
		// `workspace.owner_id`. So its role has to keep saying "owner", and a role change
		// against it is refused rather than applied.
		//
		// Without this, demoting the owner left the team screen showing them as a
		// reviewer while their permissions were unchanged, and the audit trail recorded a
		// permission change that had not happened. A record that disagrees with reality is
		// worse than no record: it is what an administrator reads afterwards to find out
		// what happened.
		if ( 'owner' === (string) $member['role'] ) {
			return null;
		}

		// The last admin guard. A workspace whose only admin is demoted has nobody who can
		// invite, remove or change a role again - a soft lock that no user can undo and that
		// takes a database query to escape.
		if ( (string) $member['role'] === $role ) {
			return $member;
		}
		if ( 'admin' === (string) $member['role'] ) {
			$admins = $this->role_counts( (string) $workspace_id );
			if ( 1 >= (int) ( $admins['admin'] ?? 0 ) ) {
				return null;
			}
		}

		$this->update_row( (string) $public_id, array( 'role' => $role ) );
		Permission_Manager::flush();

		return $this->find( (string) $workspace_id, (string) $public_id );
	}

	/**
	 * Suspend or restore a member.
	 *
	 * A suspension, not a delete: §25 wants the record of who was in the workspace to
	 * survive, and §19 says the same about a comment thread.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Membership public id.
	 * @param bool   $active       Whether the member is active.
	 * @return array<string, mixed>|null
	 */
	public function set_status( $workspace_id, $public_id, $active ) {
		$member = $this->find( (string) $workspace_id, (string) $public_id );
		if ( null === $member ) {
			return null;
		}

		// The owner own row is a display record; the grant is `workspace.owner_id`.
		// Suspending it would leave the team screen showing the owner as suspended while
		// their permissions were unchanged, and the audit trail would record a suspension
		// that had not happened.
		if ( 'owner' === (string) $member['role'] ) {
			return null;
		}

		$this->update_row( (string) $public_id, array( 'status' => $active ? 'active' : 'suspended' ) );
		Permission_Manager::flush();

		return $this->find( (string) $workspace_id, (string) $public_id );
	}

	/**
	 * Remove a member.
	 *
	 * The row is deleted rather than suspended, because a membership has no history of
	 * its own — the history lives in `activity_events` and `audit_events`, which name the
	 * user id and are not affected.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Membership public id.
	 * @return bool
	 */
	public function remove( $workspace_id, $public_id ) {
		$member = $this->find( (string) $workspace_id, (string) $public_id );
		if ( null === $member ) {
			return false;
		}

		// Removing the owner row is not a removal of the owner - the owner is
		// `workspace.owner_id`. But `Permission_Manager::resolve()` checks membership before
		// it consults that, so a deleted row silently strips the owner's access entirely, and
		// a workspace whose owner is a plain member would be left with nobody who could
		// administer it.
		if ( 'owner' === (string) $member['role'] ) {
			return false;
		}

		if ( 'admin' === (string) $member['role'] ) {
			$admins = $this->role_counts( (string) $workspace_id );
			if ( 1 >= (int) ( $admins['admin'] ?? 0 ) ) {
				return false;
			}
		}

		$removed = $this->delete_rows( array( 'workspace_id' => (string) $workspace_id, 'public_id' => (string) $public_id ) );
		Permission_Manager::flush();

		return $removed > 0;
	}

	/**
	 * Record that a user was seen.
	 *
	 * Coarse on purpose — one write per request at most, and never on a read-only
	 * request. §31 shows "Last Active", and an accurate-to-the-second last-seen would mean
	 * a write on every page view.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @return void
	 */
	public function touch( $workspace_id, $user_id ) {
		$member = $this->membership( (string) $workspace_id, $user_id );
		if ( null === $member ) {
			return;
		}
		$this->update_row( (string) $member['public_id'], array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return the membership that already exists for an account or an address.
	 * This is the duplicate check that replaced the unique index on
	 * `(workspace_id, user_id)`, and it is the reason the index could be dropped rather
	 * than merely relaxed. It matches on **either** a user id or an address, so it catches
	 * a case the index never could: the same person invited twice by two slightly different
	 * addresses, or invited once by address and again after creating an account with that
	 * address. A unique key on the pair would have let both through.
	 * 
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @param string $email        Email.
	 * @return array<string, mixed>|null
	 */
	private function existing( $workspace_id, $user_id, $email ) {
		if ( $user_id > 0 ) {
			$by_user = $this->membership( $workspace_id, $user_id );
			if ( null !== $by_user ) {
				return $by_user;
			}
		}
		if ( '' !== $email ) {
			$by_email = $this->membership_by_email( $workspace_id, $email );
			if ( null !== $by_email ) {
				return $by_email;
			}
		}
		return null;
	}
	/**
	 * Validate a role name, returning an empty string when it is not assignable.
	 *
	 * `owner` is absent from `ASSIGNABLE_ROLES` and therefore refused here, which is what
	 * stops a role-change request from minting an owner.
	 *
	 * @param string $role Role.
	 * @return string
	 */
	private function clean_role( $role ) {
		$role = strtolower( trim( (string) $role ) );
		if ( 'owner' === $role ) {
			// The owner is `workspace.owner_id`. A request that asks to be the owner is
			// refused rather than redirected to a transfer, because silently doing
			// something else is worse than refusing.
			return '';
		}
		return in_array( $role, Workspace_Limits::ASSIGNABLE_ROLES, true ) ? $role : '';
	}
}
