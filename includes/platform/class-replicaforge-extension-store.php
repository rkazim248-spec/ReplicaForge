<?php
/**
 * Phase 20: the extension metadata store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Read and write access to `replicaforge_extensions`.
 *
 * ### What this table is, and is not
 *
 * It holds **metadata only**. The provider object is never stored, never serialised and
 * never rehydrated — it exists because already-trusted PHP constructed it this request.
 *
 * That is what makes "extensions cannot be uploaded" true rather than aspirational: even an
 * attacker with write access to this table gains a list of names and a set of permissions,
 * and every permission in it is still checked against
 * {@see Platform_Limits::EXTENSION_PERMISSIONS} at call time. There is no column that a
 * file could be written into and no code path that reads one.
 *
 * ### Why a table rather than an option
 *
 * The console lists extensions filtered by state and capability, and an extension's state
 * changes. Options would mean a linear scan of a serialised array for a filter, which is
 * the same argument Phase 19 used for templates and it holds here unchanged.
 */
class Extension_Store extends Collaboration_Store {

	/**
	 * Entity kind, matching `Workspace_Limits::table()`.
	 *
	 * @var string
	 */
	protected $kind = 'extension';

	/**
	 * Writable columns.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id',
			'extension_id',
			'status',
			'capabilities',
			'permissions',
			'manifest',
			'incompatibilities',
			'failure_count',
			'last_error',
			'created_at',
			'updated_at',
		);
	}

	/**
	 * Column types.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'extension_id'      => 'line',
			'status'            => 'line',
			'capabilities'      => 'json',
			'permissions'       => 'json',
			'manifest'          => 'json',
			'incompatibilities' => 'json',
			'failure_count'     => 'int',
			'last_error'        => 'text',
			'created_at'        => 'datetime',
			'updated_at'        => 'datetime',
		);
	}

	/**
	 * Extensions are looked up by name in the console.
	 *
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array();
	}

	/**
	 * The console's facet counts.
	 *
	 * @return array<int, string>
	 */
	protected function groupable_columns() {
		return array( 'status' );
	}

	/**
	 * Return every extension record.
	 *
	 * ### Why this is a direct read rather than `query( '*', … )`
	 *
	 * Two reasons, one practical and one about what this table is.
	 *
	 * Practically: this store has no `browse()` of its own, and `Collaboration_Store::query()`
	 * takes a workspace id — which extensions do not have, because an installed extension is a
	 * property of the site rather than of one agency's workspace. `find()` accepts `'*'` for
	 * exactly this case but returns one row.
	 *
	 * Structurally: the set is bounded by {@see Platform_Limits::MAX_EXTENSIONS} = 100 and every
	 * row is metadata. Pagination would be machinery for a table that cannot exceed a hundred
	 * rows, and `Extension_Store::save()` already refuses the 101st. A direct read with the same
	 * bound applied is both simpler and harder to get wrong than a paginated path nobody needs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		global $wpdb;

		if ( ! $this->ready() ) {
			return array();
		}

		$table = $this->table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				"SELECT * FROM {$table} ORDER BY extension_id ASC LIMIT %d",
				Platform_Limits::MAX_EXTENSIONS
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->cast( $row );
			}
		}

		return $out;
	}

	/**
	 * Return one extension by its own id.
	 *
	 * Named `find_by_extension_id()` rather than `find()` for the reason
	 * `Collaboration_Store` documents on `delete_rows()`: the parent declares a **protected**
	 * `find( $workspace_id, $public_id )`, and a child cannot widen visibility *and* change
	 * the signature. Doing so is a fatal error at class-composition time rather than a
	 * warning — and `php -l` does not catch it, because the file is perfectly valid on its
	 * own. It only surfaces when the class is actually loaded, which is why the full suite
	 * found it and a lint pass could not.
	 *
	 * Extensions carry no `workspace_id`: an installed extension is a property of the *site*,
	 * not of one agency's workspace, which is why the lookup is explicitly cross-workspace.
	 *
	 * @param string $extension_id Extension id.
	 * @return array<string, mixed>|null
	 */
	public function find_by_extension_id( $extension_id ) {
		$extension_id = $this->clean_key( $extension_id );

		if ( '' === $extension_id || ! $this->ready() ) {
			return null;
		}

		return $this->find_where( '*', array( 'extension_id' => $extension_id ) );
	}

	/**
	 * Insert or update an extension record.
	 *
	 * The two cases are distinguished by whether `public_id` resolves, so a caller that
	 * re-registers an extension updates the row it already owns rather than accumulating
	 * one row per boot. That matters more than it sounds: a provider that registers on
	 * every request would otherwise fill the table within a day.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save( array $record ) {
		if ( ! $this->ready() ) {
			return new \WP_Error(
				'extension_table_missing',
				__( 'The extension tables are not installed. Run the database migration.', 'replicaforge' ),
				array( 'status' => 500 )
			);
		}

		$extension_id = $this->clean_key( $record['extension_id'] ?? '' );

		if ( '' === $extension_id ) {
			return new \WP_Error( 'extension_id_required', __( 'An extension id is required.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		$existing = $this->find_by_extension_id( $extension_id );

		if ( null === $existing ) {
			if ( count( $this->all() ) >= Platform_Limits::MAX_EXTENSIONS ) {
				return new \WP_Error(
					'extension_limit_reached',
					__( 'This site has reached the limit of registered extensions.', 'replicaforge' ),
					array( 'status' => 409 )
				);
			}

			/*
			 * `array_merge()` with the defaults *first*, so `$record` wins — except for
			 * `public_id`, which must not.
			 *
			 * `Extension_Registry::register()` builds its record with
			 * `'public_id' => ''` when it has no prior row (it uses the existing row's id when
			 * there is one), and `array_merge( $defaults, $record )` would let that empty string
			 * overwrite the freshly generated id. Every extension row then had
			 * `public_id = ''`, which collides on the UNIQUE index the moment a second one is
			 * registered — so the third extension to register failed to insert.
			 *
			 * The id is therefore re-asserted *after* the merge, and only when the incoming
			 * value is genuinely absent.
			 */
			$row = array_merge(
				array(
					'public_id'  => $this->new_public_id(),
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				$record,
				array( 'extension_id' => $extension_id )
			);

			if ( '' === (string) $row['public_id'] ) {
				$row['public_id'] = $this->new_public_id();
			}

			$stored = $this->insert( $row );

			return null === $stored
				? new \WP_Error( 'extension_not_stored', __( 'The extension could not be saved.', 'replicaforge' ), array( 'status' => 500 ) )
				: $stored;
		}

		$changes = $record;
		unset( $changes['extension_id'], $changes['created_at'] );

		if ( array() === $changes ) {
			return $existing;
		}

		$this->update_row( (string) $existing['public_id'], $changes );

		$fresh = $this->find_by_extension_id( $extension_id );

		return null === $fresh ? $existing : $fresh;
	}

	/**
	 * Remove an extension's record.
	 *
	 * @param string $extension_id Extension id.
	 * @return bool
	 */
	public function forget( $extension_id ) {
		$extension_id = $this->clean_key( $extension_id );

		if ( '' === $extension_id || ! $this->ready() ) {
			return false;
		}

		Extension_Configuration::forget( $extension_id );

		return (bool) $this->delete_rows( array( 'extension_id' => $extension_id ) );
	}

	/**
	 * Return how many extensions declare a live capability.
	 *
	 * @param string $capability Capability.
	 * @return int
	 */
	public function count_with_capability( $capability ) {
		$count = 0;

		foreach ( $this->all() as $record ) {
			/*
			 * Only *live* extensions count. A disabled, incompatible or failed one declares
			 * its capability in the manifest but cannot serve it, and a console reporting
			 * "3 analyzers" on a site where one is disabled and one is incompatible would
			 * be the more misleading of the two answers.
			 */
			if ( ! Platform_Limits::is_live_extension( (string) ( $record['status'] ?? '' ) ) ) {
				continue;
			}

			if ( in_array( (string) $capability, (array) ( $record['capabilities'] ?? array() ), true ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Reduce an extension id to its storable form.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_key( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ), 0, 64 ) : '';
	}
}
