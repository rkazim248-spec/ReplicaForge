<?php
/**
 * Phase 19: the reusable component store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * A versioned registry of reusable components.
 *
 * ### How this relates to Phase 12's `Component_Registry`
 *
 * They are different things and are kept apart deliberately.
 *
 * - **Phase 12 `Component_Registry`** (`includes/multipage/`) holds *detected* shared
 *   components per **project**: "these three pages share a header with this fingerprint".
 *   It is a detection record, rewritten on every analysis, and its `overridden` flag
 *   protects a component a user took manual control of.
 * - **This store** holds *reusable* components per **workspace**: a versioned, shareable,
 *   installable design asset with its own identity, changelog and usage count.
 *
 * A Phase 12 detection is a candidate; a Phase 19 component is something a person decided
 * to keep. Merging them would mean a re-analysis could silently rewrite a reusable asset,
 * which is the failure §30 exists to prevent. The bridge is one-directional and explicit:
 * {@see Template_Extractor} promotes a detection into a component, and nothing promotes it
 * back.
 *
 * ### Versioning
 *
 * §10 requires version, changelog, author, dates, compatibility, dependency changes and
 * migration information. The row carries the current version and a `changelog` array
 * accumulated in the document column's sibling — the changelog is bounded and stored as
 * JSON on the component's own row rather than as separate version rows, because a
 * component's history is short (`Template_Limits::MAX_COMPONENT_VERSIONS`) and needs to be
 * readable in one query.
 */
class Template_Component_Store extends Collaboration_Store {

	/**
	 * Entity kind, matching `Workspace_Limits::table()`.
	 *
	 * @var string
	 */
	protected $kind = 'template_component';

	/**
	 * Writable columns.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id',
			'workspace_id',
			'component_id',
			'name',
			'type',
			'description',
			'status',
			'user_id',
			'version',
			'version_count',
			'document',
			'design_system',
			'tokens',
			'content_slots',
			'assets',
			'dependencies',
			'compatibility',
			'provenance',
			'validation',
			'template_count',
			'usage_count',
			'hash',
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
		$json = array( 'document', 'design_system', 'tokens', 'content_slots', 'assets', 'dependencies', 'compatibility', 'provenance', 'validation' );
		$out  = array(
			'workspace_id'  => 'line',
			'component_id'  => 'line',
			'name'          => 'line',
			'type'          => 'line',
			'description'   => 'text',
			'status'        => 'line',
			'user_id'       => 'int',
			'version'       => 'int',
			'version_count' => 'int',
			'template_count' => 'int',
			'usage_count'   => 'int',
			'hash'          => 'line',
			'created_at'    => 'datetime',
			'updated_at'    => 'datetime',
		);

		foreach ( $json as $column ) {
			$out[ $column ] = 'json';
		}

		return $out;
	}

	/**
	 * Columns the component library's free-text search covers.
	 *
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'name', 'description', 'component_id' );
	}

	/**
	 * Columns the facet counts group by.
	 *
	 * @return array<int, string>
	 */
	protected function groupable_columns() {
		return array( 'status', 'type' );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return one component by its own identifier.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param string $component_id Component id.
	 * @return array<string, mixed>|null
	 */
	public function find_component( $workspace_id, $component_id ) {
		$component_id = $this->clean_key( $component_id );

		if ( '' === $component_id || ! $this->ready() ) {
			return null;
		}

		return $this->find_where( (string) $workspace_id, array( 'component_id' => $component_id ) );
	}

	/**
	 * List components for a workspace.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function browse( $workspace_id, array $args = array() ) {
		$workspace_id = $this->clean_public_id( $workspace_id );

		if ( '' === $workspace_id || ! $this->ready() ) {
			return $this->empty_page();
		}

		$clean = array();

		if ( isset( $args['type'] ) && '' !== (string) $args['type'] ) {
			$clean['type'] = (string) $args['type'];
		}
		if ( isset( $args['status'] ) && '' !== (string) $args['status'] ) {
			$clean['status'] = (string) $args['status'];
		}
		if ( isset( $args['search'] ) ) {
			$search = sanitize_text_field( (string) $args['search'] );
			if ( '' !== $search ) {
				$clean['search'] = $search;
			}
		}

		$order              = (string) ( $args['order_by'] ?? 'updated_at' );
		$clean['order_by']  = in_array( $order, array( 'updated_at', 'created_at', 'name', 'type', 'usage_count' ), true ) ? $order : 'updated_at';
		$clean['per_page']  = isset( $args['per_page'] ) ? (int) $args['per_page'] : Workspace_Limits::page_size( 0 );
		$clean['page']      = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		return $this->query( $workspace_id, $clean );
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Register a component, or add a version to an existing one.
	 *
	 * §27 forbids silently overwriting an existing reusable asset, so this is explicit
	 * about which of the two things it is doing and the caller is told. A caller that
	 * wanted a fresh component and got a new version of an old one — or the reverse — has
	 * been given a component it did not ask for.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param array<string, mixed> $input Component input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function register( $workspace_id, array $input ) {
		$workspace_id = $this->clean_public_id( $workspace_id );

		if ( '' === $workspace_id ) {
			return new \WP_Error( 'template_workspace_required', __( 'A component must belong to a workspace.', 'replicaforge' ), array( 'status' => 400 ) );
		}
		if ( ! $this->ready() ) {
			return new \WP_Error( 'template_table_missing', __( 'The component tables are not installed. Run the database migration.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		$component_id = $this->clean_key( $input['component_id'] ?? '' );
		$name         = isset( $input['name'] ) && is_string( $input['name'] ) ? trim( $input['name'] ) : '';
		$type         = $this->clean_key( $input['type'] ?? '' );

		if ( '' === $component_id || strlen( $component_id ) > 64 ) {
			return new \WP_Error( 'template_component_id_required', __( 'A component needs a stable identifier.', 'replicaforge' ), array( 'status' => 400 ) );
		}
		if ( '' === $name || strlen( $name ) > 191 ) {
			return new \WP_Error( 'template_component_name_required', __( 'A component needs a name.', 'replicaforge' ), array( 'status' => 400 ) );
		}
		if ( '' === $type || strlen( $type ) > 32 ) {
			return new \WP_Error( 'template_component_type_required', __( 'A component needs a type.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		$existing = $this->find_component( $workspace_id, $component_id );
		$payload  = $this->payload( $input );

		$encoded = wp_json_encode( $payload );

		if ( ! is_string( $encoded ) ) {
			return new \WP_Error( 'template_component_unencodable', __( 'This component could not be encoded for storage.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		$hash  = hash( 'sha256', $encoded );
		$now   = gmdate( 'c' );
		$actor = (int) ( $input['user_id'] ?? get_current_user_id() );

		if ( null === $existing ) {
			$count = (int) $this->count_where( $workspace_id, array() );

			if ( $count >= Template_Limits::MAX_COMPONENTS ) {
				return new \WP_Error(
					'template_component_limit_reached',
					sprintf(
						/* translators: %d: the maximum number of components. */
						__( 'This workspace already holds %d reusable components, which is the limit.', 'replicaforge' ),
						Template_Limits::MAX_COMPONENTS
					),
					array( 'status' => 409 )
				);
			}

			$row = array(
				'public_id'      => $this->new_public_id(),
				'workspace_id'   => $workspace_id,
				'component_id'   => $component_id,
				'name'           => substr( sanitize_text_field( $name ), 0, 191 ),
				'type'           => substr( $type, 0, 32 ),
				'description'    => isset( $input['description'] ) && is_string( $input['description'] ) ? $input['description'] : '',
				'status'         => Template_Limits::is_status( $input['status'] ?? '' ) ? (string) $input['status'] : 'active',
				'user_id'        => $actor,
				'version'        => 1,
				'version_count'  => 1,
				'template_count' => 0,
				'usage_count'    => 0,
				'hash'           => $hash,
				'created_at'     => $now,
			);

			$row = array_merge( $row, $payload );

			$stored = $this->insert( $row );

			if ( null === $stored ) {
				return new \WP_Error( 'template_component_not_stored', __( 'The component could not be saved.', 'replicaforge' ), array( 'status' => 500 ) );
			}

			$stored['created'] = true;

			return $stored;
		}

		/*
		 * An identical re-registration is *not* a new version.
		 *
		 * Without this, installing two templates that both contain the same component
		 * produced two versions — one for each install — and the version number became a
		 * count of how many templates reference the component rather than a record of how
		 * many times it changed. §10 is explicit that a version exists because a component
		 * was *updated*; nothing was updated here.
		 *
		 * The comparison is on the stored content hash, which covers the document, tokens,
		 * slots, assets, dependencies and compatibility. It deliberately does not cover the
		 * name or the description: renaming a component is metadata, and §10's changelog
		 * records dependency and compatibility changes, which is what a reader compares
		 * before applying an update.
		 *
		 * So this returns the stored row unchanged, flagged, rather than writing. The
		 * version counter is a signal, and a signal that fires on every install stops being
		 * one.
		 */
		$content_hash = hash( 'sha256', (string) wp_json_encode( $payload ) );

		if ( '' !== (string) ( $existing['hash'] ?? '' ) && hash_equals( (string) $existing['hash'], $content_hash ) ) {
			$existing['unchanged'] = true;

			return $existing;
		}

		/*
		 * A new version of an existing component.
		 *
		 * The changelog is accumulated here rather than in a separate table, and is bounded.
		 * It records the dependency and compatibility deltas §10 asks for, because "what
		 * changed between version 2 and 3" is the question a person asks before applying an
		 * update.
		 */
		$next = max( 1, (int) ( $existing['version'] ?? 1 ) + 1 );

		$changelog = isset( $existing['provenance']['changelog'] ) && is_array( $existing['provenance']['changelog'] )
			? $existing['provenance']['changelog']
			: array();

		array_unshift(
			$changelog,
			array(
				'version'        => $next,
				'from'           => (int) ( $existing['version'] ?? 1 ),
				'user_id'        => $actor,
				'created_at'     => gmdate( 'c' ),
				'note'           => isset( $input['change_note'] ) && is_string( $input['change_note'] ) ? substr( sanitize_text_field( $input['change_note'] ), 0, 300 ) : '',
				'dependencies'   => $this->delta( isset( $existing['dependencies'] ) ? (array) $existing['dependencies'] : array(), (array) ( $payload['dependencies'] ?? array() ) ),
				'compatibility'  => $this->compatibility_delta( isset( $existing['compatibility'] ) ? (array) $existing['compatibility'] : array(), (array) ( $payload['compatibility'] ?? array() ) ),
				'validation'     => (string) ( $payload['validation']['state'] ?? '' ),
			)
		);

		$payload['provenance']['changelog'] = array_slice( $changelog, 0, Template_Limits::MAX_COMPONENT_VERSIONS );

		$changes = array_merge(
			$payload,
			array(
				'name'          => substr( sanitize_text_field( $name ), 0, 191 ),
				'version'       => $next,
				'version_count' => max( (int) ( $existing['version_count'] ?? 1 ) + 1, $next ),
				'hash'          => $content_hash,
			)
		);

		$this->update_row( (string) $existing['public_id'], $changes );

		$fresh = $this->find( (string) $workspace_id, (string) $existing['public_id'] );

		return null === $fresh ? $existing : $fresh;
	}

	/**
	 * Record a component usage.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param string $component_id Component id.
	 * @return bool
	 */
	public function record_usage( $workspace_id, $component_id ) {
		global $wpdb;

		$workspace_id = $this->clean_public_id( $workspace_id );
		$component_id = $this->clean_key( $component_id );

		if ( '' === $workspace_id || '' === $component_id || ! $this->ready() ) {
			return false;
		}

		$table = $this->table();

		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET usage_count = usage_count + 1 WHERE workspace_id = %s AND component_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				$workspace_id,
				$component_id
			)
		);
	}

	/**
	 * Archive a component.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param string $component_id Component id.
	 * @return bool
	 */
	public function archive( $workspace_id, $component_id ) {
		$existing = $this->find_component( $workspace_id, $component_id );

		if ( null === $existing ) {
			return false;
		}

		return $this->update_row( (string) $existing['public_id'], array( 'status' => 'archived' ) );
	}

	/* ---------------------------------------------------------------------
	 * Update safety
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether a component may be updated in place.
	 *
	 * §10 and §30 are emphatic: do not silently break existing pages, and never overwrite
	 * manual edits automatically. This is the check that decides it, and it is conservative
	 * by construction — where it cannot determine whether a consumer was hand-edited, it
	 * says so rather than assuming it is safe.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param string $component_id Component id.
	 * @param int    $target_version Version the user wants to move to.
	 * @return array{safe: bool, reason: string, current_version: int, target_version: int, consumers: array<int, array<string, mixed>>, manual_edits_detectable: bool, strategies: array<string, string>}
	 */
	public function update_safety( $workspace_id, $component_id, $target_version ) {
		$component = $this->find_component( $workspace_id, $component_id );

		if ( null === $component ) {
			return array(
				'safe'            => false,
				'reason'          => __( 'That component is not in this workspace.', 'replicaforge' ),
				'current_version' => 0,
				'target_version'  => 0,
				'consumers'       => array(),
				'manual_edits_detectable' => false,
				'strategies'      => array(),
			);
		}

		$current = (int) ( $component['version'] ?? 1 );
		$target  = max( 1, (int) $target_version );

		$consumers = $this->consumers( $workspace_id, $component_id );

		/*
		 * Whether a consumer was hand-edited cannot be determined from stored data. The
		 * honest answer is "no", and the consequence is that an update touching any consumer
		 * is never reported as unconditionally safe.
		 */
		$detectable = false;

		$safe     = true;
		$reason   = __( 'No template or page currently uses this component, so updating it changes nothing that exists.', 'replicaforge' );
		$strategies = array( 'keep_existing' => Template_Limits::CONFLICT_STRATEGIES['keep_existing'] );

		if ( array() !== $consumers ) {
			$safe = false;
			$reason = sprintf(
				/* translators: %d: how many templates use the component. */
				__( '%d template(s) use this component. ReplicaForge cannot tell whether any of them were edited by hand since they were built, so choose which ones to update rather than applying this everywhere.', 'replicaforge' ),
				count( $consumers )
			);
			$strategies = array(
				'update_all' => __( 'Update every template that uses it', 'replicaforge' ),
				'update_selected' => __( 'Update only the templates I choose', 'replicaforge' ),
				'keep_existing' => Template_Limits::CONFLICT_STRATEGIES['keep_existing'],
			);
		}

		if ( $target < $current ) {
			$safe   = false;
			$reason = __( 'A component cannot be moved to an older version: the intermediate versions carry changelogs and dependency information that a downgrade would skip.', 'replicaforge' );
		}

		return array(
			'safe'                   => $safe,
			'reason'                 => $reason,
			'current_version'        => $current,
			'target_version'         => $target,
			'consumers'              => $consumers,
			'manual_edits_detectable' => $detectable,
			'strategies'             => $strategies,
		);
	}

	/**
	 * Return the templates that record a component.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param string $component_id Component id.
	 * @return array<int, array<string, mixed>>
	 */
	public function consumers( $workspace_id, $component_id ) {
		$workspace_id = $this->clean_public_id( $workspace_id );
		$component_id = $this->clean_key( $component_id );

		if ( '' === $workspace_id || '' === $component_id || ! class_exists( 'ReplicaForge\\Template_Store' ) ) {
			return array();
		}

		$templates = ( new Template_Store() )->browse( $workspace_id, array( 'per_page' => Workspace_Limits::page_size( 500 ) ) );
		$versions  = new Template_Version_Store();
		$out       = array();

		foreach ( $templates['items'] as $template ) {
			$template_id = (string) ( $template['public_id'] ?? '' );

			if ( '' === $template_id ) {
				continue;
			}

			$version = $versions->current( $workspace_id, $template_id );

			if ( null === $version ) {
				continue;
			}

			$components = isset( $version['components'] ) && is_array( $version['components'] ) ? $version['components'] : array();

			if ( ! isset( $components[ $component_id ] ) ) {
				continue;
			}

			$out[] = array(
				'template_id' => $template_id,
				'name'        => (string) ( $template['name'] ?? '' ),
				'type'        => (string) ( $template['type'] ?? '' ),
				'status'      => (string) ( $template['status'] ?? '' ),
				'version'     => (int) ( $version['version'] ?? 0 ),
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Reduce a component input to its stored payload sections.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	private function payload( array $input ) {
		$provenance = isset( $input['provenance'] ) && is_array( $input['provenance'] ) ? $input['provenance'] : array();

		$provenance = array(
			'origin'      => isset( $provenance['origin'] ) && is_string( $provenance['origin'] ) ? substr( $provenance['origin'], 0, 60 ) : 'extracted',
			'source_post' => (int) ( $provenance['source_post'] ?? 0 ),
			'source_page' => isset( $provenance['source_page'] ) && is_scalar( $provenance['source_page'] ) ? substr( (string) $provenance['source_page'], 0, 64 ) : '',
			'project_id'  => isset( $provenance['project_id'] ) && is_string( $provenance['project_id'] ) ? substr( $provenance['project_id'], 0, 64 ) : '',
			'url'         => isset( $provenance['url'] ) && is_string( $provenance['url'] ) ? substr( $provenance['url'], 0, 300 ) : '',
			'note'        => isset( $provenance['note'] ) && is_string( $provenance['note'] ) ? substr( $provenance['note'], 0, 300 ) : '',
		);

		return array(
			'document'      => isset( $input['document'] ) && is_array( $input['document'] ) ? $input['document'] : array(),
			'design_system' => isset( $input['design_system'] ) && is_array( $input['design_system'] ) ? $input['design_system'] : array(),
			'tokens'        => isset( $input['tokens'] ) && is_array( $input['tokens'] ) ? $input['tokens'] : array(),
			'content_slots' => isset( $input['content_slots'] ) && is_array( $input['content_slots'] ) ? $input['content_slots'] : array(),
			'assets'        => isset( $input['assets'] ) && is_array( $input['assets'] ) ? $input['assets'] : array(),
			'dependencies'  => isset( $input['dependencies'] ) && is_array( $input['dependencies'] ) ? $input['dependencies'] : array(),
			'compatibility' => isset( $input['compatibility'] ) && is_array( $input['compatibility'] ) ? $input['compatibility'] : array(),
			'validation'    => isset( $input['validation'] ) && is_array( $input['validation'] ) ? $input['validation'] : array(),
			'provenance'    => $provenance,
		);
	}

	/**
	 * Return what changed between two dependency sets.
	 *
	 * @param array<string, mixed> $before Previous.
	 * @param array<string, mixed> $after  New.
	 * @return array{added: array<int, string>, removed: array<int, string>}
	 */
	private function delta( array $before, array $after ) {
		$read = static function ( array $set ) {
			$out = array();
			foreach ( $set as $key => $value ) {
				$out[] = is_string( $key ) ? $key : ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
			}
			return array_values( array_filter( $out ) );
		};

		$from = $read( $before );
		$to   = $read( $after );

		return array(
			'added'   => array_values( array_diff( $to, $from ) ),
			'removed' => array_values( array_diff( $from, $to ) ),
		);
	}

	/**
	 * Return what changed between two compatibility declarations.
	 *
	 * @param array<string, mixed> $before Previous.
	 * @param array<string, mixed> $after  New.
	 * @return array{added: array<int, string>, removed: array<int, string>, raised: bool}
	 */
	private function compatibility_delta( array $before, array $after ) {
		$delta = $this->delta( $before, $after );

		$raised = false;

		foreach ( array( 'elementor' => 'minimum', 'wordpress' => 'minimum' ) as $product => $key ) {
			$was = (string) ( $before[ $product ][ $key ] ?? '' );
			$now = (string) ( $after[ $product ][ $key ] ?? '' );

			if ( '' !== $now && '' !== $was && version_compare( $now, $was, '>' ) ) {
				$raised = true;
			}
		}

		$delta['raised'] = $raised;

		return $delta;
	}

	/**
	 * Reduce a component key to its storable form.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_key( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( $value ) ), 0, 64 ) : '';
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
