<?php
/**
 * Phase 15: the workspace store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Workspaces, and the personal-workspace provisioning §2 asks for.
 *
 * ### Why a personal workspace is provisioned automatically, and what it must not do
 *
 * §2 says individual users "should automatically receive a personal/default workspace
 * where appropriate", and §57 says existing single-user installations must keep working.
 *
 * The two together mean the migration *must* assign existing projects somewhere, and the
 * only somewhere that does not change how a single user sees their site is their own
 * personal workspace. So:
 *
 * - a user with no workspace gets one on first use, named after them, owned by them;
 * - a user with projects and no workspace gets one that **adopts** those projects, so the
 *   projects do not move;
 * - a user with a workspace already is left alone.
 *
 * The last point is the one that matters. A migration that "tidies up" a user who has
 * already organised themselves does harm in the name of consistency.
 */
final class Workspace_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'workspaces';

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array( 'public_id', 'name', 'owner_id', 'status', 'slug', 'settings', 'migrated', 'created_at', 'updated_at' );
	}

	/**
	 * Return the storage type of each column.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'public_id'   => 'string',
			'name'        => 'line',
			'owner_id'    => 'int',
			'status'      => 'line',
			'slug'        => 'line',
			'settings'    => 'json',
			'migrated'    => 'bool',
			'created_at'  => 'datetime',
			'updated_at'  => 'datetime',
		);
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return a workspace by its public id.
	 *
	 * The store's `find()` is protected and takes a workspace scope, but a workspace *is*
	 * the scope, so it cannot scope to itself. This is the one entity read with `'*'`, and
	 * it is explicit.
	 *
	 * @param string $public_id Workspace public id.
	 * @return array<string, mixed>|null
	 */
	public function get( $public_id ) {
		return $this->find( '*', (string) $public_id );
	}

	/**
	 * Return the workspaces a user owns or belongs to.
	 *
	 * @param int    $user_id User id.
	 * @param bool   $owned   Whether to return only owned workspaces.
	 * @param string $status  Optional status filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( $user_id, $owned = false, $status = '' ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! $this->ready() ) {
			return array();
		}

		$table = Workspace_Limits::prefixed_table( 'workspaces' );

		if ( $owned ) {
			$sql  = "SELECT * FROM {$table} WHERE owner_id = %d";
			$args = array( $user_id );
			if ( '' !== $status ) {
				$sql   .= ' AND status = %s';
				$args[] = $status;
			}
			$sql .= ' ORDER BY created_at DESC';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table is an internal constant.
		} else {
			$sql = "SELECT w.* FROM {$table} w
				INNER JOIN " . Workspace_Limits::prefixed_table( 'members' ) . " m
					ON m.workspace_id = w.public_id
				WHERE m.user_id = %d AND m.status = 'active'";
			$args = array( $user_id );
			if ( '' !== $status ) {
				$sql   .= ' AND w.status = %s';
				$args[] = $status;
			}
			$sql .= ' ORDER BY w.created_at DESC';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- two internal constants joined explicitly.
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = $this->cast( $row );
		}
		return $out;
	}

	/**
	 * Return the count of workspaces a user owns.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public function owned_count( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! $this->ready() ) {
			return 0;
		}
		$table = $this->table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Create a workspace, with the owner as its first member.
	 *
	 * @param int                   $owner_id Owner.
	 * @param string                $name     Name.
	 * @param array<string, mixed> $options  Options: `settings`, `migrated`, `slug`.
	 * @return array<string, mixed>|null
	 */
	public function create( $owner_id, $name, array $options = array() ) {
		$owner_id = (int) $owner_id;
		if ( $owner_id < 1 ) {
			return null;
		}

		$settings = $this->default_settings();
		if ( isset( $options['settings'] ) && is_array( $options['settings'] ) ) {
			$settings = array_merge( $settings, $this->clean_settings( $options['settings'] ) );
		}

		$name = trim( (string) $name );
		if ( '' === $name ) {
			$user  = get_userdata( $owner_id );
			$name  = ( $user instanceof \WP_User ) ? (string) $user->display_name : __( 'My Workspace', 'replicaforge' );
		}

		$workspace = $this->insert(
			array(
				'public_id' => $this->new_public_id(),
				'name'      => $this->bounded( $name, 120 ),
				'owner_id'  => $owner_id,
				'status'    => 'active',
				'slug'      => isset( $options['slug'] ) ? $this->slug( (string) $options['slug'] ) : $this->slug( $name ),
				'settings'  => $settings,
				'migrated'  => ! empty( $options['migrated'] ),
				'created_at'=> gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( null === $workspace ) {
			return null;
		}

		// The owner is added as a member with role `owner` so that listing members shows
		// the owner. The *permission* still comes from `workspace.owner_id`, so this row is
		// a record rather than the grant — see `Permission_Manager::role()`.
		( new Workspace_Member_Store() )->add_owner( (string) $workspace['public_id'], $owner_id );

		$this->logger->info(
			'workspace_created',
			'Created a workspace.',
			array( 'workspace' => (string) $workspace['public_id'], 'owner' => $owner_id ),
			'workspace'
		);

		return $workspace;
	}

	/**
	 * Update a workspace.
	 *
	 * Narrowed to four columns. `owner_id` and `migrated` are not settable through this
	 * path: transferring ownership is a different, separately-audited operation, and a
	 * `migrated` flag that a request could set would let a caller mark the install
	 * migration as done.
	 *
	 * @param string               $public_id Workspace public id.
	 * @param array<string, mixed> $changes   Changes.
	 * @return array<string, mixed>|null The stored workspace, or null when the write did not happen.
	 */
	public function update( $public_id, array $changes ) {
		$allowed = array( 'name', 'status', 'slug', 'settings' );

		$clean = array();
		foreach ( $allowed as $column ) {
			if ( array_key_exists( $column, $changes ) ) {
				$clean[ $column ] = $changes[ $column ];
			}
		}
		if ( array() === $clean ) {
			return false;
		}
		if ( isset( $clean['settings'] ) ) {
			$clean['settings'] = $this->clean_settings( (array) $clean['settings'] );
		}
		if ( isset( $clean['slug'] ) ) {
			$clean['slug'] = $this->slug( (string) $clean['slug'] );
		}

		$ok = $this->update_row( (string) $public_id, $clean );

		// The per-request capability cache is keyed by role, so a name or status change
		// does not alter it - but a settings change can change project scoping, and
		// therefore what a user may do. Flushed either way, because a stale permission
		// answer for the rest of an admin request is the whole class of bug Â§25 exists to
		// prevent.
		Permission_Manager::flush();

		// Re-read, for the reason in the docblock: a caller needs the stored value, not merely the fact that a write was attempted. A bool would let it report success for a settings key that was silently dropped.
		if ( ! $ok ) {
			return null;
		}

		return $this->get( $public_id );
	}

	/**
	 * Return a workspace's settings, with defaults filled in.
	 *
	 * @param string $public_id Workspace public id.
	 * @return array<string, mixed>
	 */
	public function settings( $public_id ) {
		$workspace = $this->get( (string) $public_id );
		if ( null === $workspace ) {
			return $this->default_settings();
		}
		return array_merge( $this->default_settings(), (array) ( $workspace['settings'] ?? array() ) );
	}

	/**
	 * Return the conservative §40 defaults.
	 *
	 * §40 says "Defaults should be conservative", and every one of these is the
	 * restrictive choice:
	 *
	 * - `require_internal_approval` on: an agency's own reviewer signs off first.
	 * - `require_client_approval` on: a client is a client.
	 * - `allow_client_comments` on, `allow_client_review_links` **off**: a signed link is a
	 *   capability that has to be asked for.
	 * - `project_scoping` **off**: off is the backward-compatible state, because turning
	 *   it on narrows who can see what, and a migration must not narrow anyone's access.
	 * - `allow_automatic_notifications` **off**: §26 makes notifications opt-in, and
	 *   §27 is about not leaking.
	 *
	 * @return array<string, mixed>
	 */
	public function default_settings() {
		return array(
			'project_scoping'             => false,
			'require_internal_approval'   => true,
			'require_client_approval'     => true,
			'allow_client_comments'       => true,
			'allow_client_review_links'   => false,
			'allow_automatic_notifications'=> false,
			'default_reviewer_id'         => 0,
			'client_can_see_validation'   => true,
			'client_can_switch_viewport'  => true,
			'activity_retention_days'     => 180,
		);
	}

	/**
	 * Return the settings keys a caller may set.
	 *
	 * @return array<int, string>
	 */
	public function setting_keys() {
		return array_keys( $this->default_settings() );
	}

	/**
	 * Merge and validate settings.
	 *
	 * Unknown keys are dropped rather than stored, so a typo does not become a setting
	 * that appears to work and never does.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	public function clean_settings( array $settings ) {
		$out = array();
		foreach ( $this->default_settings() as $key => $default ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			$value = $settings[ $key ];
			if ( is_bool( $default ) ) {
				$out[ $key ] = $this->to_bool( $value );
			} elseif ( is_int( $default ) ) {
				$out[ $key ] = (int) $value;
			} else {
				$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return a URL-safe slug, never empty.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public function slug( $value ) {
		$slug = sanitize_title( (string) $value );
		return ( '' === $slug ) ? 'workspace' : substr( $slug, 0, 80 );
	}

	/**
	 * Return a bounded string.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum.
	 * @return string
	 */
	protected function bounded( $value, $length ) {
		$value = (string) $value;
		return ( strlen( $value ) <= $length ) ? $value : substr( $value, 0, $length );
	}

	/**
	 * Coerce a value to a boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	protected function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}


}
