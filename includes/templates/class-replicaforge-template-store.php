<?php
/**
 * Phase 19: the template store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Read and write access to `replicaforge_templates`.
 *
 * ### Why a table and not an option
 *
 * Every other ReplicaForge store is an option: the job list, the project list, the workflow
 * index. Templates are the first entity that genuinely needs a table, for three reasons
 * that compound:
 *
 * 1. **Scale.** `Site_Limits::MAX_TEMPLATES` is 500. Loading 500 rows to render a library
 *    list means loading every template's metadata on every page view of that screen. The
 *    §41 requirement not to load every template into memory is not satisfiable from an
 *    option.
 * 2. **Pagination and filtering.** §24 requires filtering by type, project, category,
 *    compatibility, author, updated date, validation status and tags, and §41 requires
 *    pagination. A `LIKE` scan of a serialised option satisfies neither.
 * 3. **Versioning.** A template has immutable version rows. Storing a document plus twenty
 *    versions in one option row is what produced the Phase 9/10/12 unbounded-growth
 *    findings; `Render_Cache` is the documented example of a cache with no byte budget.
 *
 * The document itself is deliberately **not** in this table — it is in
 * `template_versions.document`, read only when a template is opened. The library list
 * therefore reads one narrow row per template.
 *
 * ### Reuse
 *
 * Extends `Collaboration_Store`, so pagination, cursor handling, the search clause, type
 * coercion on write, casting on read, public-id generation and the `prepare()` boundary
 * all come from the Phase 15 layer rather than being written again.
 */
class Template_Store extends Collaboration_Store {

	/**
	 * Entity kind, matching `Workspace_Limits::table()`.
	 *
	 * @var string
	 */
	protected $kind = 'templates';

	/**
	 * Writable columns.
	 *
	 * `id` is absent because a sequential id must never leave PHP, and the public id is
	 * generated here rather than accepted from a caller.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id',
			'workspace_id',
			'project_id',
			'name',
			'description',
			'type',
			'category',
			'status',
			'visibility',
			'user_id',
			'current_version',
			'version_count',
			'source_post_id',
			'source_project_id',
			'validation_state',
			'tags',
			'install_count',
			'archived_at',
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
			'workspace_id'      => 'line',
			'project_id'        => 'line',
			'name'              => 'line',
			'description'       => 'text',
			'type'              => 'line',
			'category'          => 'line',
			'status'            => 'line',
			'visibility'        => 'line',
			'user_id'           => 'int',
			'current_version'   => 'line',
			'version_count'     => 'int',
			'source_post_id'    => 'int',
			'source_project_id' => 'line',
			'validation_state'  => 'line',
			'tags'              => 'json',
			'install_count'     => 'int',
			'archived_at'       => 'datetime',
			'created_at'        => 'datetime',
			'updated_at'        => 'datetime',
		);
	}

	/**
	 * Columns the library's free-text search covers.
	 *
	 * `description` is added because §24 requires searching name *and* description, and the
	 * base default is name and title — a column this table does not have.
	 *
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'name', 'description' );
	}

	/**
	 * Columns the library's facet counts group by.
	 *
	 * @return array<int, string>
	 */
	protected function groupable_columns() {
		return array( 'status', 'type', 'category', 'visibility', 'validation_state' );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return one template, workspace-scoped.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param string $template_id  Template public id.
	 * @return array<string, mixed>|null
	 */
	public function find_template( $workspace_id, $template_id ) {
		$template_id = $this->clean_public_id( $template_id );

		if ( '' === $template_id || ! $this->ready() ) {
			return null;
		}

		return $this->find( (string) $workspace_id, $template_id );
	}

	/**
	 * List templates for a workspace.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, count: int, has_more: bool, per_page: int, page: int, cursor: array<string, mixed>}
	 */
	public function browse( $workspace_id, array $args = array() ) {
		$workspace_id = $this->clean_public_id( $workspace_id );

		if ( '' === $workspace_id || ! $this->ready() ) {
			return $this->empty_page();
		}

		$args = $this->clean_query( $args );

		return $this->query( $workspace_id, $args );
	}

	/**
	 * Return every template a user may see in a workspace.
	 *
	 * ### Why this exists separately from `browse()`
	 *
	 * `browse()` returns a workspace's rows. This returns the subset one user may see, and
	 * it is the function the library screen must use. A row is visible when the user holds
	 * `templates.view` in the workspace, or owns it, or it is shared at a visibility their
	 * role permits.
	 *
	 * The filtering happens **here, in the query**, not in a loop after it. §34 requires
	 * permissions to be respected at query time and says explicitly not to rely on frontend
	 * filtering for security — and a store that silently hides rows is a store whose count
	 * disagrees with what the caller can see, which is the failure
	 * `Workspace_Admin::render_projects_page()` documents at length.
	 *
	 * @param string $workspace_id  Workspace public id.
	 * @param int    $user_id       Acting user.
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, count: int, has_more: bool, per_page: int, page: int, cursor: array<string, mixed>, hidden: int}
	 */
	public function browse_for_user( $workspace_id, $user_id, array $args = array() ) {
		$workspace_id = $this->clean_public_id( $workspace_id );
		$user_id      = (int) $user_id;

		if ( '' === $workspace_id || $user_id < 1 || ! $this->ready() ) {
			return $this->empty_page() + array( 'hidden' => 0 );
		}

		/*
		 * The whole-workspace grant, checked once. A site administrator and a workspace owner
		 * see everything; everyone else is filtered to their own and their workspace's shared
		 * rows.
		 */
		$permissions = new Permission_Manager();
		$unrestricted = user_can( $user_id, 'manage_options' ) || $permissions->can( $user_id, $workspace_id, 'templates.view' );

		$args = $this->clean_query( $args );

		if ( $unrestricted ) {
			$page = $this->query( $workspace_id, $args );
			$page['hidden'] = 0;
			return $page;
		}

		$visible = array( 'mine', 'workspace' );
		$page    = $this->query( $workspace_id, array_merge( $args, array( 'categories' => $visible ) ) );

		/*
		 * A private template belonging to somebody else, and an archived template, are both
		 * removed here. The `categories` filter cannot express "mine or workspace but not
		 * archived", because archived is a `status` and private is a `visibility`, and the
		 * two are independent axes — a `categories` filter and a `status` filter are
		 * combined with AND, so filtering on `status = active` here would also hide
		 * legitimately-archived rows from a caller that asked for them.
		 */
		$kept   = array();
		$shown  = 0;
		$hidden = 0;

		foreach ( $page['items'] as $item ) {
			if ( 'archived' === (string) ( $item['status'] ?? '' ) && empty( $args['include_archived'] ) ) {
				$hidden++;
				continue;
			}
			if ( 'private' === (string) ( $item['visibility'] ?? '' ) && (int) ( $item['user_id'] ?? 0 ) !== $user_id ) {
				$hidden++;
				continue;
			}
			if ( 'client_review' === (string) ( $item['visibility'] ?? '' ) ) {
				$hidden++;
				continue;
			}
			$kept[] = $item;
			$shown++;
		}

		$page['items']  = $kept;
		$page['count']  = $shown;
		$page['hidden'] = $hidden;

		return $page;
	}

	/**
	 * Return the templates and components that record a given design token.
	 *
	 * §8's "identify affected templates and components", answered from stored data. The
	 * join is on the `tokens` JSON of the *current* version of each template and on each
	 * component, so a token referenced only by a superseded version is not reported — which
	 * is correct, because changing it would not affect anything still in use.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param string $token_id     Token id.
	 * @return array<int, array<string, mixed>>
	 */
	public function consumers_of_token( $workspace_id, $token_id ) {
		$workspace_id = $this->clean_public_id( $workspace_id );
		$token_id     = is_string( $token_id ) ? trim( $token_id ) : '';

		if ( '' === $workspace_id || '' === $token_id || ! $this->ready() ) {
			return array();
		}

		$versions = new Template_Version_Store();
		$out      = array();

		// Templates: walk the current version of each active template in the workspace.
		$templates = $this->query(
			$workspace_id,
			array( 'status' => 'active', 'per_page' => Workspace_Limits::page_size( 500 ) )
		);

		foreach ( $templates['items'] as $template ) {
			$template_id = (string) ( $template['public_id'] ?? '' );

			if ( '' === $template_id || '' === (string) ( $template['current_version'] ?? '' ) ) {
				continue;
			}

			$version = $versions->current( $workspace_id, $template_id );

			if ( null === $version ) {
				continue;
			}

			$tokens = isset( $version['tokens'] ) && is_array( $version['tokens'] ) ? $version['tokens'] : array();

			if ( ! isset( $tokens[ $token_id ] ) ) {
				continue;
			}

			$out[] = array(
				'kind'    => 'template',
				'id'      => $template_id,
				'name'    => (string) ( $template['name'] ?? '' ),
				'type'    => (string) ( $template['type'] ?? '' ),
				'status'  => (string) ( $template['status'] ?? '' ),
				'version' => (int) ( $template['version_count'] ?? 0 ),
			);
		}

		// Components.
		$components = new Template_Component_Store();
		$listed     = $components->query( $workspace_id, array( 'per_page' => Workspace_Limits::page_size( 500 ) ) );

		foreach ( $listed['items'] as $component ) {
			$tokens = isset( $component['tokens'] ) && is_array( $component['tokens'] ) ? $component['tokens'] : array();

			if ( ! isset( $tokens[ $token_id ] ) ) {
				continue;
			}

			$out[] = array(
				'kind'    => 'component',
				'id'      => (string) ( $component['component_id'] ?? '' ),
				'name'    => (string) ( $component['name'] ?? '' ),
				'type'    => (string) ( $component['type'] ?? '' ),
				'status'  => (string) ( $component['status'] ?? '' ),
				'version' => (int) ( $component['version'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Return facet counts for the library screen.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @return array<string, array<string, int>>
	 */
	public function facets( $workspace_id ) {
		$workspace_id = $this->clean_public_id( $workspace_id );

		if ( '' === $workspace_id || ! $this->ready() ) {
			return array( 'status' => array(), 'type' => array(), 'category' => array(), 'validation_state' => array() );
		}

		return array(
			'status'           => $this->group_counts( $workspace_id, 'status' ),
			'type'             => $this->group_counts( $workspace_id, 'type' ),
			'category'         => $this->group_counts( $workspace_id, 'category' ),
			'validation_state' => $this->group_counts( $workspace_id, 'validation_state' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Create a template.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param array<string, mixed> $input Template input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( $workspace_id, array $input ) {
		$workspace_id = $this->clean_public_id( $workspace_id );

		if ( '' === $workspace_id ) {
			return new \WP_Error( 'template_workspace_required', __( 'A template must belong to a workspace.', 'replicaforge' ), array( 'status' => 400 ) );
		}
		if ( ! $this->ready() ) {
			return new \WP_Error( 'template_table_missing', __( 'The template tables are not installed. Run the database migration.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		$name = isset( $input['name'] ) && is_string( $input['name'] ) ? trim( $input['name'] ) : '';

		if ( '' === $name || strlen( $name ) > 191 ) {
			return new \WP_Error( 'template_name_required', __( 'A template needs a name.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		$type = isset( $input['type'] ) && Template_Limits::is_template_type( $input['type'] )
			? (string) $input['type']
			: 'custom';

		/*
		 * Each vocabulary is read into a local and then validated, rather than validated
		 * through `??` and re-read in the true branch. Same reason as `safe_visibility()`:
		 * `??` in the test and a bare read in the branch is a warning waiting for a key that
		 * is absent, and for a string-valued default it is a warning on *every* call.
		 */
		$category   = isset( $input['category'] ) && is_string( $input['category'] ) ? (string) $input['category'] : '';
		$status     = isset( $input['status'] ) && is_string( $input['status'] ) ? (string) $input['status'] : '';
		$visibility = isset( $input['visibility'] ) && is_string( $input['visibility'] ) ? (string) $input['visibility'] : '';

		$row = array(
			'public_id'         => $this->new_public_id(),
			'workspace_id'      => $workspace_id,
			'project_id'        => $this->clean_line( $input['project_id'] ?? '', 64 ),
			'name'              => $this->clean_line( $name, 191 ),
			'description'       => isset( $input['description'] ) && is_string( $input['description'] ) ? $input['description'] : '',
			'type'              => $type,
			'category'          => Template_Limits::is_category( $category ) ? $category : 'mine',
			'status'            => Template_Limits::is_status( $status ) ? $status : 'draft',
			'visibility'        => Template_Limits::is_visibility( $visibility ) ? $visibility : 'private',
			'user_id'           => (int) ( $input['user_id'] ?? get_current_user_id() ),
			'version_count'     => 0,
			'source_post_id'    => (int) ( $input['source_post_id'] ?? 0 ),
			'source_project_id' => $this->clean_line( $input['source_project_id'] ?? '', 64 ),
			'tags'              => $this->clean_tags( $input['tags'] ?? array() ),
		);

		$count = (int) $this->count_where( $workspace_id, array() );

		if ( $count >= Template_Limits::MAX_TEMPLATES ) {
			return new \WP_Error(
				'template_limit_reached',
				sprintf(
					/* translators: %d: the maximum number of templates. */
					__( 'This workspace already holds %d templates, which is the limit. Archive or delete some before adding more.', 'replicaforge' ),
					Template_Limits::MAX_TEMPLATES
				),
				array( 'status' => 409 )
			);
		}

		$stored = $this->insert( $row );

		if ( null === $stored ) {
			return new \WP_Error( 'template_not_created', __( 'The template could not be saved.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		return $stored;
	}

	/**
	 * Update a template's mutable metadata.
	 *
	 * §11 is emphatic that historical versions are never mutated. This method therefore
	 * refuses to touch `current_version`, `version_count` and the stored snapshot — those
	 * are owned by `Template_Version_Store`, and a caller that could write them here could
	 * point a template at a version that does not exist.
	 *
	 * @param string $template_id Template public id.
	 * @param array<string, mixed> $changes Allowed changes.
	 * @return true|\WP_Error
	 */
	public function update_meta( $template_id, array $changes ) {
		$template_id = $this->clean_public_id( $template_id );

		if ( '' === $template_id || ! $this->ready() ) {
			return new \WP_Error( 'template_not_found', __( 'That template does not exist.', 'replicaforge' ), array( 'status' => 404 ) );
		}

		$allowed = array( 'name', 'description', 'type', 'category', 'visibility', 'tags' );

		$row = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $changes ) ) {
				continue;
			}

			switch ( $key ) {
				case 'type':
					if ( ! Template_Limits::is_template_type( $changes[ $key ] ) ) {
						return new \WP_Error( 'template_type_unknown', __( 'That is not a template type this version knows.', 'replicaforge' ), array( 'status' => 400 ) );
					}
					$row[ $key ] = (string) $changes[ $key ];
					break;

				case 'category':
					if ( ! Template_Limits::is_category( $changes[ $key ] ) ) {
						return new \WP_Error( 'template_category_unknown', __( 'That is not a library category this version knows.', 'replicaforge' ), array( 'status' => 400 ) );
					}
					$row[ $key ] = (string) $changes[ $key ];
					break;

				case 'visibility':
					if ( ! Template_Limits::is_visibility( $changes[ $key ] ) ) {
						return new \WP_Error( 'template_visibility_unknown', __( 'That is not a visibility this version knows.', 'replicaforge' ), array( 'status' => 400 ) );
					}
					$row[ $key ] = (string) $changes[ $key ];
					break;

				case 'name':
					$name = is_string( $changes[ $key ] ) ? trim( $changes[ $key ] ) : '';
					if ( '' === $name || strlen( $name ) > 191 ) {
						return new \WP_Error( 'template_name_required', __( 'A template needs a name.', 'replicaforge' ), array( 'status' => 400 ) );
					}
					$row[ $key ] = $this->clean_line( $name, 191 );
					break;

				case 'tags':
					$row[ $key ] = $this->clean_tags( $changes[ $key ] );
					break;

				default:
					$row[ $key ] = is_string( $changes[ $key ] ) ? $changes[ $key ] : '';
			}
		}

		if ( array() === $row ) {
			return true;
		}

		return $this->update_row( $template_id, $row )
			? true
			: new \WP_Error( 'template_not_updated', __( 'The template could not be updated.', 'replicaforge' ), array( 'status' => 500 ) );
	}

	/**
	 * Set the current version pointer after a version is stored.
	 *
	 * @param string $template_id Template public id.
	 * @param string $version_id  Version public id.
	 * @param int    $count       Version count.
	 * @param string $validation  Validation state.
	 * @return bool
	 */
	public function set_current( $template_id, $version_id, $count, $validation ) {
		return $this->update_row(
			$this->clean_public_id( $template_id ),
			array(
				'current_version'  => $this->clean_line( $version_id, 64 ),
				'version_count'    => max( 0, (int) $count ),
				'validation_state' => Template_Limits::is_validation_state( $validation ) ? (string) $validation : '',
			)
		);
	}

	/**
	 * Archive a template.
	 *
	 * A soft delete, and deliberately reversible. §24 lists an archived category and §11
	 * requires versions to be preserved; a template that was superseded is different from
	 * one that was abandoned, and destroying its history because someone tidied up is not
	 * recoverable.
	 *
	 * @param string $template_id Template public id.
	 * @return bool
	 */
	public function archive( $template_id ) {
		return $this->update_row(
			$this->clean_public_id( $template_id ),
			array( 'status' => 'archived', 'archived_at' => gmdate( 'c' ) )
		);
	}

	/**
	 * Restore an archived template.
	 *
	 * @param string $template_id Template public id.
	 * @return bool
	 */
	public function restore( $template_id ) {
		return $this->update_row(
			$this->clean_public_id( $template_id ),
			array( 'status' => 'active', 'archived_at' => null )
		);
	}

	/**
	 * Record that a template was installed.
	 *
	 * @param string $template_id Template public id.
	 * @return bool
	 */
	public function record_install( $template_id ) {
		global $wpdb;

		$template_id = $this->clean_public_id( $template_id );

		if ( '' === $template_id || ! $this->ready() ) {
			return false;
		}

		$table = $this->table();

		// An expression, not a read-modify-write: two concurrent installs must not lose
		// one another's count.
		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET install_count = install_count + 1 WHERE public_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				$template_id
			)
		);
	}

	/**
	 * Delete a template and its versions.
	 *
	 * Called only from an explicit delete action that has already been authorised. The
	 * versions go first for the same reason `Workflow_Repository::delete()` purges
	 * artifacts first: a template row that outlives its versions is a library entry that
	 * cannot be opened.
	 *
	 * @param string $template_id Template public id.
	 * @return bool
	 */
	public function delete_template( $template_id ) {
		$template_id = $this->clean_public_id( $template_id );

		if ( '' === $template_id || ! $this->ready() ) {
			return false;
		}

		( new Template_Version_Store() )->purge_template( $template_id );

		return (bool) $this->delete_rows( array( 'public_id' => $template_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Validate and reduce query arguments.
	 *
	 * @param array<string, mixed> $args Raw arguments.
	 * @return array<string, mixed>
	 */
	private function clean_query( array $args ) {
		$out = array();

		/*
		 * `category` is a comma list here rather than a single value, because the library's
		 * primary filter is "mine or workspace". The base `query()` treats a `category` as an
		 * exact match, so a list is turned into a list query the same way `statuses` is — and
		 * the split happens here so no caller can pass a fragment.
		 */
		unset( $args['category'] );

		$categories = array();

		if ( isset( $args['categories'] ) ) {
			$categories = is_array( $args['categories'] ) ? $args['categories'] : array( $args['categories'] );
		}

		$categories = array_values( array_filter( array_map( 'strval', $categories ) ) );
		$valid      = array();

		foreach ( $categories as $category ) {
			if ( Template_Limits::is_category( $category ) ) {
				$valid[] = $category;
			}
		}

		if ( array() !== $valid ) {
			$out['categories'] = array_slice( array_values( array_unique( $valid ) ), 0, 6 );
		}

		foreach ( array( 'status', 'type', 'visibility', 'validation_state', 'project_id' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				$out[ $key ] = (string) $args[ $key ];
			}
		}

		if ( isset( $args['validation_states'] ) && is_array( $args['validation_states'] ) ) {
			$states = array_values( array_filter( array_map( 'strval', $args['validation_states'] ) ) );
			$valid  = array();

			foreach ( $states as $state ) {
				if ( Template_Limits::is_validation_state( $state ) ) {
					$valid[] = $state;
				}
			}
			if ( array() !== $valid ) {
				$out['validation_states'] = array_slice( array_values( array_unique( $valid ) ), 0, 5 );
			}
		}

		if ( isset( $args['user_id'] ) && (int) $args['user_id'] > 0 ) {
			$out['user_id'] = (int) $args['user_id'];
		}

		if ( isset( $args['search'] ) ) {
			$search = sanitize_text_field( (string) $args['search'] );
			if ( '' !== $search ) {
				$out['search'] = $search;
			}
		}

		if ( isset( $args['order_by'] ) ) {
			$order  = (string) $args['order_by'];
			$out['order_by'] = in_array( $order, array( 'created_at', 'updated_at', 'name', 'type', 'install_count', 'validation_state' ), true )
				? $order
				: 'updated_at';
		}

		if ( isset( $args['before'] ) && is_array( $args['before'] ) ) {
			$out['before'] = $args['before'];
		}

		$out['per_page'] = isset( $args['per_page'] ) ? (int) $args['per_page'] : Workspace_Limits::page_size( 0 );
		$out['page']     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		if ( ! empty( $args['include_archived'] ) ) {
			$out['include_archived'] = true;
		}

		return $out;
	}

	/**
	 * Reduce a tag list to a clean, bounded array.
	 *
	 * @param mixed $value Candidate.
	 * @return array<int, string>
	 */
	private function clean_tags( $value ) {
		$value = is_array( $value ) ? $value : ( is_string( $value ) ? explode( ',', $value ) : array() );
		$out   = array();

		foreach ( $value as $tag ) {
			if ( ! is_scalar( $tag ) ) {
				continue;
			}
			$tag = sanitize_key( (string) $tag );
			if ( '' !== $tag && ! in_array( $tag, $out, true ) ) {
				$out[] = $tag;
			}
		}

		return array_slice( $out, 0, 20 );
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

	/**
	 * Reduce a string to a bounded, tag-free line.
	 *
	 * @param mixed $value  Candidate.
	 * @param int   $length Maximum length.
	 * @return string
	 */
	private function clean_line( $value, $length ) {
		return is_string( $value ) ? substr( sanitize_text_field( trim( $value ) ), 0, (int) $length ) : '';
	}
}
