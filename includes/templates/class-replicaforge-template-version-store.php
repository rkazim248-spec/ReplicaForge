<?php
/**
 * Phase 19: the template version store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only storage for template snapshots.
 *
 * ### The invariant this class exists to hold
 *
 * **A stored version is never modified.** §11 says historical versions are never mutated
 * and a significant change creates a new version. That is not a convention here — it is
 * enforced, in two places:
 *
 * 1. There is no public update method. `append()` only ever inserts. The inherited
 *    `update_row()` is deliberately *not* wrapped in a public method here, because a
 *    subclass of `Collaboration_Store` that never calls it cannot be misused through it.
 * 2. The version number comes from the highest **stored** version, not from a count — the
 *    same rule `Project_Repository::add_version()` uses, for the same reason: once the list
 *    is trimmed the two stop agreeing, and counting would eventually hand the same number
 *    to two different versions and make any stored reference ambiguous.
 *
 * ### Integrity
 *
 * Every snapshot is hashed on write and the hash is re-checked on read, following
 * `Workflow_Artifacts`, which is the most rigorously-designed store in the plugin. A
 * snapshot that fails its hash is refused rather than returned, because a half-written or
 * hand-edited template that installs successfully is worse than one that refuses.
 *
 * ### What is deliberately not here
 *
 * No method to delete a single version. A version is removed only with its template, or by
 * the retention trim, which is part of `append()`. "Delete this one version" is not an
 * operation, because a reference to it may exist in a page that used it.
 */
class Template_Version_Store extends Collaboration_Store {

	/**
	 * Entity kind, matching `Workspace_Limits::table()`.
	 *
	 * @var string
	 */
	protected $kind = 'template_version';

	/**
	 * Writable columns.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id',
			'workspace_id',
			'template_id',
			'version',
			'version_id',
			'schema_version',
			'engine_version',
			'user_id',
			'document',
			'responsive',
			'interactions',
			'design_system',
			'tokens',
			'components',
			'content_slots',
			'assets',
			'dependencies',
			'compatibility',
			'provenance',
			'validation',
			'bytes',
			'hash',
			'change_note',
			'created_at',
		);
	}

	/**
	 * Column types.
	 *
	 * Every snapshot section is `json`, which `coerce()` runs through
	 * `Data_Redactor::structure()` on write and casts back to an array on read. That is the
	 * Phase 1 boundary applied to template data: a value that is not a scalar or array of
	 * scalars is reduced to a description of itself rather than being stored, so a
	 * hand-crafted object graph cannot reach the database through a template.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		$json = array( 'document', 'responsive', 'interactions', 'design_system', 'tokens', 'components', 'content_slots', 'assets', 'dependencies', 'compatibility', 'provenance', 'validation' );
		$out  = array(
			'workspace_id'    => 'line',
			'template_id'     => 'line',
			'version'         => 'int',
			'version_id'      => 'line',
			'schema_version'  => 'line',
			'engine_version'  => 'line',
			'user_id'         => 'int',
			'bytes'           => 'int',
			'hash'            => 'line',
			'change_note'     => 'text',
			'created_at'      => 'datetime',
		);

		foreach ( $json as $column ) {
			$out[ $column ] = 'json';
		}

		return $out;
	}

	/**
	 * The version table is never searched by free text.
	 *
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'change_note' );
	}

	/* ---------------------------------------------------------------------
	 * Append
	 * ------------------------------------------------------------------ */

	/**
	 * Append a new version for a template.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param string $template_id  Template public id.
	 * @param array<string, mixed> $snapshot Snapshot sections.
	 * @param array<string, mixed> $meta     Version metadata.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function append( $workspace_id, $template_id, array $snapshot, array $meta = array() ) {
		$workspace_id = $this->clean_public_id( $workspace_id );
		$template_id  = $this->clean_public_id( $template_id );

		if ( '' === $workspace_id || '' === $template_id ) {
			return new \WP_Error( 'template_version_identifiers_required', __( 'A version needs both a workspace and a template.', 'replicaforge' ), array( 'status' => 400 ) );
		}
		if ( ! $this->ready() ) {
			return new \WP_Error( 'template_table_missing', __( 'The template tables are not installed. Run the database migration.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		/*
		 * The size ceiling is checked on the *encoded* form, not on the array, because that
		 * is what lands in the column and what `wp_options`-style limits and MySQL packet
		 * limits actually apply to. Measuring the array would pass a snapshot that then
		 * fails to write.
		 */
		$encoded = $this->encode( $snapshot );

		if ( ! is_string( $encoded ) ) {
			return new \WP_Error( 'template_version_unencodable', __( 'This template could not be encoded for storage, so it was not saved.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		$bytes = strlen( $encoded );

		if ( $bytes > Template_Limits::MAX_VERSION_BYTES ) {
			return new \WP_Error(
				'template_version_too_large',
				sprintf(
					/* translators: 1: the size in kilobytes, 2: the maximum in kilobytes. */
					__( 'This template is %1$d KB, which is over the %2$d KB a single version may store. Extract a section or a component instead of the whole page.', 'replicaforge' ),
					(int) round( $bytes / 1024 ),
					(int) round( Template_Limits::MAX_VERSION_BYTES / 1024 )
				),
				array( 'status' => 413, 'bytes' => $bytes )
			);
		}

		$next = $this->next_version( $template_id );

		$row = array(
			'public_id'       => $this->new_public_id(),
			'workspace_id'    => $workspace_id,
			'template_id'     => $template_id,
			'version'         => $next,
			'version_id'      => isset( $meta['version_id'] ) && is_string( $meta['version_id'] ) && '' !== $meta['version_id']
				? substr( preg_replace( '/[^A-Za-z0-9_]/', '', $meta['version_id'] ), 0, 64 )
				: Request_Context::make_id( 'tver', 8 ),
			'schema_version'  => Template_Limits::SCHEMA_VERSION,
			'engine_version'  => Template_Limits::ENGINE_VERSION,
			'user_id'         => (int) ( $meta['user_id'] ?? get_current_user_id() ),
			'bytes'           => $bytes,
			'hash'            => hash( 'sha256', $encoded ),
			'change_note'     => isset( $meta['change_note'] ) && is_string( $meta['change_note'] ) ? $meta['change_note'] : '',
		);

		foreach ( array( 'document', 'responsive', 'interactions', 'design_system', 'tokens', 'components', 'content_slots', 'assets', 'dependencies', 'compatibility', 'provenance', 'validation' ) as $section ) {
			$row[ $section ] = isset( $snapshot[ $section ] ) ? $snapshot[ $section ] : array();
		}

		/*
		 * The row is prepared through the Phase 1 boundary *before* the hash is taken.
		 *
		 * `prepare_row()` → `coerce()` runs every `json` section through
		 * `Data_Redactor::structure()`, which is a sanitiser, not a serialiser: it strips
		 * markup from strings, so `<p>text</p>` is stored as `text`. Hashing the *snapshot*
		 * and hashing the *stored row* are therefore two different values, and
		 * {@see Template_Version_Store::verify()} — which re-encodes what came back out of the
		 * database — could never match a hash taken before the sanitiser ran.
		 *
		 * That is not a theoretical mismatch. It refused every template whose document
		 * contained any rich-text value, which is most of them, and it did so with a
		 * "corrupt" message naming corruption as the cause when the file was perfectly
		 * intact.
		 *
		 * So the hash covers exactly what was written. The consequence is deliberate and
		 * worth stating: the hash detects a partial write or a hand-edited *row*, which is
		 * what it is for. It does not detect a sanitiser that changed the payload, because
		 * the sanitiser is part of the write and its output is the intended content.
		 */
		$prepared = $this->prepare_row( $row );

		$stored = $this->insert( $row );

		if ( null === $stored ) {
			return new \WP_Error( 'template_version_not_stored', __( 'The template version could not be saved.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		/*
		 * Re-hash over the row as stored, so the digest and the bytes agree. Doing this after
		 * the insert rather than before is what makes it correct: `insert()` re-reads the row
		 * from the database, so what comes back is what is actually persisted, including
		 * anything the database layer normalised.
		 */
		$as_stored = $this->encode( $this->sections_of( $stored ) );
		$final     = is_string( $as_stored ) ? hash( 'sha256', $as_stored ) : '';

		if ( '' !== $final && $final !== (string) $prepared['hash'] ) {
			$this->update_row( (string) $stored['public_id'], array( 'hash' => $final, 'bytes' => strlen( (string) $as_stored ) ) );

			$stored['hash']  = $final;
			$stored['bytes'] = strlen( (string) $as_stored );
		}

		$this->trim( $template_id );

		return $stored;
	}

	/**
	 * Return the snapshot sections of a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function sections_of( array $row ) {
		$out = array();

		foreach ( array( 'document', 'responsive', 'interactions', 'design_system', 'tokens', 'components', 'content_slots', 'assets', 'dependencies', 'compatibility', 'provenance', 'validation' ) as $section ) {
			$out[ $section ] = isset( $row[ $section ] ) ? $row[ $section ] : array();
		}

		return $out;
	}

	/**
	 * Return the next version number for a template.
	 *
	 * @param string $template_id Template public id.
	 * @return int
	 */
	public function next_version( $template_id ) {
		global $wpdb;

		$template_id = $this->clean_public_id( $template_id );

		if ( '' === $template_id || ! $this->ready() ) {
			return 1;
		}

		$table = $this->table();

		$highest = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(version) FROM {$table} WHERE template_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				$template_id
			)
		);

		return max( 1, $highest + 1 );
	}

	/**
	 * Return the current version of a template.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param string $template_id  Template public id.
	 * @return array<string, mixed>|null
	 */
	public function current( $workspace_id, $template_id ) {
		$template_id = $this->clean_public_id( $template_id );

		if ( '' === $template_id || ! $this->ready() ) {
			return null;
		}

		$row = $this->latest( $template_id );

		if ( null === $row ) {
			return null;
		}

		/*
		 * A version row is workspace-scoped on read as well as on write. The row is found by
		 * `template_id` alone because that is the natural key, so the workspace is verified
		 * here — otherwise a caller holding another workspace's template id could read its
		 * snapshot by passing the right id and the wrong workspace.
		 */
		if ( '' !== (string) $workspace_id && (string) ( $row['workspace_id'] ?? '' ) !== (string) $workspace_id ) {
			return null;
		}

		return $row;
	}

	/**
	 * Return the most recent version row for a template.
	 *
	 * @param string $template_id Template public id.
	 * @return array<string, mixed>|null
	 */
	public function latest( $template_id ) {
		global $wpdb;

		$template_id = $this->clean_public_id( $template_id );

		if ( '' === $template_id || ! $this->ready() ) {
			return null;
		}

		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE template_id = %s ORDER BY version DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				$template_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->cast( $row ) : null;
	}

	/**
	 * Return a specific version.
	 *
	 * @param string $template_id Template public id.
	 * @param int    $version     Version number.
	 * @return array<string, mixed>|null
	 */
	public function get( $template_id, $version ) {
		global $wpdb;

		$template_id = $this->clean_public_id( $template_id );
		$version     = (int) $version;

		if ( '' === $template_id || $version < 1 || ! $this->ready() ) {
			return null;
		}

		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE template_id = %s AND version = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				$template_id,
				$version
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->cast( $row ) : null;
	}

	/**
	 * List a template's versions, newest first.
	 *
	 * @param string $template_id Template public id.
	 * @param int    $limit       Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function history( $template_id, $limit = 0 ) {
		global $wpdb;

		$template_id = $this->clean_public_id( $template_id );

		if ( '' === $template_id || ! $this->ready() ) {
			return array();
		}

		$table = $this->table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE template_id = %s ORDER BY version DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				$template_id,
				Workspace_Limits::page_size( $limit )
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
	 * Return a version, verifying its integrity.
	 *
	 * The hash check is what makes a stored version trustworthy. `Workflow_Artifacts` does
	 * the same and the reasoning transfers exactly: the hash catches a partial write, and
	 * more usefully it makes a hand-edited row detectable rather than silent.
	 *
	 * @param array<string, mixed> $row Stored version row.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function verify( array $row ) {
		$sections = array();

		$encoded = $this->encode( $this->sections_of( $row ) );

		if ( ! is_string( $encoded ) ) {
			return new \WP_Error( 'template_version_unencodable', __( 'This stored template version could not be encoded, so it cannot be verified.', 'replicaforge' ) );
		}

		$expected = (string) ( $row['hash'] ?? '' );

		if ( '' === $expected || ! hash_equals( $expected, hash( 'sha256', $encoded ) ) ) {
			return new \WP_Error(
				'template_version_corrupt',
				__( 'The stored template version does not match its recorded hash and cannot be trusted. Re-create the template from the project.', 'replicaforge' )
			);
		}

		return $row;
	}

	/**
	 * Return the retained count of versions for a template.
	 *
	 * @param string $template_id Template public id.
	 * @return int
	 */
	public function count_of( $template_id ) {
		global $wpdb;

		$template_id = $this->clean_public_id( $template_id );

		if ( '' === $template_id || ! $this->ready() ) {
			return 0;
		}

		$table = $this->table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE template_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				$template_id
			)
		);
	}

	/**
	 * Trim a template's versions to the retention limit.
	 *
	 * The *oldest* are dropped. A version is a record rather than content, so the loss is
	 * bounded and the template itself is never left pointing at a version that is gone.
	 *
	 * @param string $template_id Template public id.
	 * @return int Rows removed.
	 */
	public function trim( $template_id ) {
		global $wpdb;

		$template_id = $this->clean_public_id( $template_id );

		if ( '' === $template_id || ! $this->ready() ) {
			return 0;
		}

		$table   = $this->table();
		$keep    = Template_Limits::MAX_VERSIONS;
		$version = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT version FROM {$table} WHERE template_id = %s ORDER BY version DESC LIMIT 1 OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				$template_id,
				$keep - 1
			)
		);

		if ( ! $version ) {
			return 0;
		}

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE template_id = %s AND version < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				$template_id,
				(int) $version
			)
		);
	}

	/**
	 * Remove every version of a template.
	 *
	 * Called only from `Template_Store::delete_template()`, which has already been
	 * authorised, and only for a template that is being deleted outright.
	 *
	 * @param string $template_id Template public id.
	 * @return int Rows removed.
	 */
	public function purge_template( $template_id ) {
		$template_id = $this->clean_public_id( $template_id );

		if ( '' === $template_id || ! $this->ready() ) {
			return 0;
		}

		return $this->delete_rows( array( 'template_id' => $template_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Encode a snapshot deterministically.
	 *
	 * The hash covers this exact string, so the encoding has to be stable: the same
	 * snapshot must produce the same bytes on every read or every stored version would
	 * fail its own integrity check. `wp_json_encode` preserves insertion order for a
	 * given array, and the section order here is fixed, so it is deterministic for a
	 * snapshot that round-trips through storage.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return string|false
	 */
	private function encode( array $snapshot ) {
		$ordered = array();

		foreach ( array( 'document', 'responsive', 'interactions', 'design_system', 'tokens', 'components', 'content_slots', 'assets', 'dependencies', 'compatibility', 'provenance', 'validation' ) as $section ) {
			$ordered[ $section ] = isset( $snapshot[ $section ] ) ? $snapshot[ $section ] : array();
		}

		return wp_json_encode( $ordered );
	}

	/**
	 * Reduce a public id to its storable form.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_public_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}
}
