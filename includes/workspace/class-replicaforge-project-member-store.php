<?php
/**
 * Phase 15: the project membership store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Who is on a project, and in what capacity.
 *
 * ### A project role narrows; it never widens
 *
 * {@see Permission_Manager} intersects the project role's capabilities with the workspace
 * role's. This store's only job is to say what role a person holds on a project; the
 * intersection is applied there, in one place, where it can be audited.
 *
 * That is why `PROJECT_ROLE_CAPS['lead']` is a long list. It looks like it grants
 * authority, and on its own it would — but it is intersected with whatever the workspace
 * role already allows, so a `client` who is made a project `lead` is still a client. A
 * project membership can hand out work; it cannot hand out permissions the workspace role
 * does not carry, and that is what stops it being a privilege-escalation path.
 *
 * ### `owner` is not a project role
 *
 * There is no project owner. The project's owner is the workspace role that can edit it,
 * and the reconstruction engine's own `project.user_id` remains the record of who created
 * it. Adding a third notion of ownership would mean three answers to "who runs this
 * project", and the two that disagree would be the ones that surface as a support ticket.
 */
final class Project_Member_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'project_member';

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array( 'public_id', 'workspace_id', 'project_id', 'user_id', 'email', 'role', 'status', 'invited_by', 'added_by', 'created_at', 'updated_at' );
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
			'user_id'      => 'int',
			'email'        => 'email',
			'role'         => 'line',
			'status'       => 'line',
			'invited_by'   => 'int',
			'added_by'     => 'int',
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
		return array( 'email', 'project_id' );
	}

	/**
	 * Return a user's membership on a project.
	 *
	 * Scoped by project id, which is the natural key. The workspace is *not* a filter here
	 * because the caller — `Permission_Manager` — has already established which workspace
	 * the project belongs to, and a project id is globally unique within an install.
	 *
	 * @param string $project_id Project id.
	 * @param int    $user_id    User id.
	 * @return array<string, mixed>|null
	 */
	public function membership( $project_id, $user_id ) {
		$project_id = (string) $project_id;
		$user_id    = (int) $user_id;
		if ( '' === $project_id || $user_id < 1 || ! $this->ready() ) {
			return null;
		}
		return $this->find_where( '*', array( 'project_id' => $project_id, 'user_id' => $user_id ) );
	}

	/**
	 * Return a page of a project's members.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function members( $workspace_id, $project_id, array $args = array() ) {
		$page = $this->query( (string) $workspace_id, array_merge( $args, array( 'project_id' => (string) $project_id ) ) );
		$page['roles'] = $this->group_counts( (string) $workspace_id, 'role', array( 'project_id' => (string) $project_id, 'status' => 'active' ) );
		return $page;
	}

	/**
	 * Return the number of active members on a project.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @return int
	 */
	public function active_count( $workspace_id, $project_id ) {
		return $this->count_where( (string) $workspace_id, array( 'project_id' => (string) $project_id, 'status' => 'active' ) );
	}

	/**
	 * Return the projects a user is a member of, for a "my projects" list.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @param int    $limit        Maximum.
	 * @return array<int, string>
	 */
	public function project_ids_for( $workspace_id, $user_id, $limit = 200 ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id < 1 || '' === (string) $workspace_id || ! $this->ready() ) {
			return array();
		}

		$table = Workspace_Limits::prefixed_table( 'project_member' );
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT project_id FROM {$table} WHERE workspace_id = %s AND user_id = %d AND status = 'active' ORDER BY updated_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				(string) $workspace_id,
				$user_id,
				Workspace_Limits::page_size( $limit )
			)
		);

		return array_values( array_map( 'strval', (array) $rows ) );
	}

	/**
	 * Add a member to a project.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $project_id   Project id.
	 * @param array<string, mixed> $data         Member data.
	 * @return array<string, mixed>|null
	 */
	public function add( $workspace_id, $project_id, array $data ) {
		$workspace_id = (string) $workspace_id;
		$project_id   = (string) $project_id;
		if ( '' === $workspace_id || '' === $project_id || ! $this->ready() ) {
			return null;
		}

		$user_id = (int) ( $data['user_id'] ?? 0 );
		$email   = strtolower( trim( (string) ( $data['email'] ?? '' ) ) );
		if ( $user_id < 1 && '' === $email ) {
			return null;
		}

		$role = strtolower( trim( (string) ( $data['role'] ?? 'observer' ) ) );
		if ( ! in_array( $role, Workspace_Limits::PROJECT_ROLES, true ) ) {
			return null;
		}

		$existing = ( $user_id > 0 ) ? $this->membership( $project_id, $user_id ) : null;
		if ( null === $existing && '' !== $email ) {
			$existing = $this->find_where( $workspace_id, array( 'project_id' => $project_id, 'email' => $email ) );
		}
		if ( null !== $existing ) {
			// Already on the project. Changing the role is a separate, explicit action, so
			// adding twice is a no-op rather than a silent promotion.
			return $existing;
		}

		if ( $this->active_count( $workspace_id, $project_id ) >= Workspace_Limits::MAX_PROJECT_MEMBERS ) {
			return null;
		}

		$member = $this->insert(
			array(
				'public_id'    => $this->new_public_id(),
				'workspace_id' => $workspace_id,
				'project_id'   => $project_id,
				'user_id'      => $user_id,
				'email'        => $email,
				'role'         => $role,
				'status'       => 'active',
				'invited_by'   => (int) ( $data['invited_by'] ?? 0 ),
				'added_by'     => (int) ( $data['added_by'] ?? get_current_user_id() ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		Permission_Manager::flush();
		return $member;
	}

	/**
	 * Change a project member's role.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Membership public id.
	 * @param string $role         New role.
	 * @return array<string, mixed>|null
	 */
	public function set_role( $workspace_id, $public_id, $role ) {
		$role = strtolower( trim( (string) $role ) );
		if ( ! in_array( $role, Workspace_Limits::PROJECT_ROLES, true ) ) {
			return null;
		}
		$member = $this->find( (string) $workspace_id, (string) $public_id );
		if ( null === $member ) {
			return null;
		}
		$this->update_row( (string) $public_id, array( 'role' => $role ) );
		Permission_Manager::flush();
		return $this->find( (string) $workspace_id, (string) $public_id );
	}

	/**
	 * Remove a project member.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Membership public id.
	 * @return bool
	 */
	public function remove( $workspace_id, $public_id ) {
		$removed = $this->delete_rows( array( 'workspace_id' => (string) $workspace_id, 'public_id' => (string) $public_id ) );
		Permission_Manager::flush();
		return $removed > 0;
	}

	/**
	 * Remove every membership on a project.
	 *
	 * Used when a project is archived so that a restored project comes back with the team
	 * intact rather than silently unshared.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @return int
	 */
	public function clear( $workspace_id, $project_id ) {
		$removed = $this->delete_rows( array( 'workspace_id' => (string) $workspace_id, 'project_id' => (string) $project_id ) );
		Permission_Manager::flush();
		return $removed;
	}
}
