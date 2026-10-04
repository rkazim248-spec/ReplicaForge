<?php
/**
 * Phase 15: the client contact store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The people at a client who review work.
 *
 * ### A contact is not a user
 *
 * §7 says client contacts "should only see explicitly shared projects/review links". A
 * contact row therefore grants **nothing** on its own: it records who to address, who to
 * name on a review, and who to check an email against. The access comes from a review that
 * names them, and `Permission_Manager::can_act_on_review()` is the only thing that turns a
 * contact into access.
 *
 * That is deliberate. If a contact row implied access, adding a billing contact to a client
 * would silently give them the client's projects — and billing contacts are exactly the
 * people an agency adds without intending to share anything with.
 *
 * ### The email is matched, never trusted
 *
 * When a review link is used, the address presented is compared against the signed-in
 * user's own address and against the contacts of the client that owns the project. It is
 * never compared against an address supplied in the request body.
 */
final class Client_Contact_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'contacts';

	/**
	 * The contact roles §7 lists.
	 *
	 * @var array<int, string>
	 */
	const CONTACT_ROLES = array( 'Marketing Manager', 'Owner', 'Designer', 'Developer', 'Reviewer', 'Other' );

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array( 'public_id', 'workspace_id', 'client_id', 'name', 'email', 'role', 'status', 'user_id', 'created_at', 'updated_at' );
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
			'client_id'    => 'string',
			'name'         => 'line',
			'email'        => 'email',
			'role'         => 'line',
			'status'       => 'line',
			'user_id'      => 'int',
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
		return array( 'name', 'email' );
	}

	/**
	 * Return one contact.
	 *
	 * The store could add and update a contact but had no public getter, so a caller
	 * holding a contact id had no way to read one back except through `update()` - which
	 * only works when the caller also knows what to change. The base's `find()` is
	 * protected and stays that way: it is a raw gateway that takes a workspace scope.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Contact public id.
	 * @return array<string, mixed>|null
	 */
	public function get( $workspace_id, $public_id ) {
		return $this->find( (string) $workspace_id, (string) $public_id );
	}
	/**
	 * Return a page of a client's contacts.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $client_id    Client public id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function contacts( $workspace_id, $client_id, array $args = array() ) {
		return $this->query( (string) $workspace_id, array_merge( $args, array( 'client_id' => (string) $client_id ) ) );
	}

	/**
	 * Return every contact across a workspace, for the team screen.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $limit        Maximum.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( $workspace_id, $limit = 200 ) {
		$page = $this->query( (string) $workspace_id, array( 'per_page' => $limit ) );
		return $page['items'];
	}

	/**
	 * Return a contact by email, within a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $email        Email.
	 * @return array<string, mixed>|null
	 */
	public function by_email( $workspace_id, $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return null;
		}
		return $this->find_where( (string) $workspace_id, array( 'email' => $email ) );
	}

	/**
	 * Add a contact.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $client_id    Client public id.
	 * @param array<string, mixed> $data         Contact data.
	 * @return array<string, mixed>|null
	 */
	public function add( $workspace_id, $client_id, array $data ) {
		$workspace_id = (string) $workspace_id;
		$client_id    = (string) $client_id;
		if ( '' === $workspace_id || '' === $client_id || ! $this->ready() ) {
			return null;
		}

		$name  = trim( (string) ( $data['name'] ?? '' ) );
		$email = strtolower( trim( (string) ( $data['email'] ?? '' ) ) );
		if ( '' === $name && '' === $email ) {
			return null;
		}

		if ( $this->count_where( $workspace_id, array( 'client_id' => $client_id ) ) >= Workspace_Limits::MAX_CLIENT_CONTACTS ) {
			return null;
		}

		// Idempotent by address, so adding the same person twice does not produce two rows
		// that a reviewer would then have to choose between.
		if ( '' !== $email ) {
			$existing = $this->find_where( $workspace_id, array( 'client_id' => $client_id, 'email' => $email ) );
			if ( null !== $existing ) {
				return $existing;
			}
		}

		$role = trim( (string) ( $data['role'] ?? '' ) );
		if ( '' === $role ) {
			$role = 'Reviewer';
		}

		$user_id = (int) ( $data['user_id'] ?? 0 );
		if ( $user_id < 1 && '' !== $email ) {
			// A contact who already has an account is linked to it, so a review addressed to
			// them is recognisable as being for a real person. Lookup by email only - never
			// by a supplied id - because that is not something a request should be able to
			// assert.
			$user = get_user_by( 'email', $email );
			if ( $user instanceof \WP_User ) {
				$user_id = (int) $user->ID;
			}
		}

		return $this->insert(
			array(
				'public_id'    => $this->new_public_id(),
				'workspace_id' => $workspace_id,
				'client_id'    => $client_id,
				'name'         => substr( $name, 0, 120 ),
				'email'        => $email,
				'role'         => substr( $role, 0, 60 ),
				'status'       => 'active',
				'user_id'      => $user_id,
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Update a contact.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $public_id    Contact public id.
	 * @param array<string, mixed> $changes     Changes.
	 * @return array<string, mixed>|null
	 */
	public function update( $workspace_id, $public_id, array $changes ) {
		$allowed = array( 'name', 'email', 'role', 'status' );
		$clean   = array();
		foreach ( $allowed as $column ) {
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
	 * Remove a contact.
	 *
	 * Removing a contact does not revoke a review link that already names them. The link is
	 * revoked separately, by `Review_Link_Service::revoke()`, because a review that was
	 * already sent to somebody is a fact about the past and pretending otherwise would let
	 * an agency "remove" an auditor by deleting a row.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Contact public id.
	 * @return bool
	 */
	public function remove( $workspace_id, $public_id ) {
		$contact = $this->find( (string) $workspace_id, (string) $public_id );
		if ( null === $contact ) {
			return false;
		}
		// Suspended rather than deleted: the record that a review was addressed to this
		// person is part of the review's history.
		$this->update_row( (string) $public_id, array( 'status' => 'removed' ) );
		return true;
	}
}
