<?php
/**
 * Phase 15: the shared table store for collaboration entities.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Prepared-statement storage for the collaboration tables.
 *
 * ### Why a base class at all
 *
 * Thirteen tables need insert, read, update, delete and a paginated query. Written
 * individually that is thirteen chances to (a) forget `->prepare()` on a value that came
 * from a request, (b) forget the workspace filter on exactly one of them, and (c) get the
 * column casting subtly different from its neighbour so a `status` arrives as an integer
 * in one place and a string in another.
 *
 * The first two are security bugs. The third is a correctness bug that shows up as a
 * comparison that is mysteriously false.
 *
 * ### The rule this class exists to make hard
 *
 * **Every read is workspace-scoped, and the scope is a required argument rather than an
 * option.** {@see self::find()} and {@see self::query()} both take `$workspace_id` and
 * both refuse an empty one. There is deliberately no "find by id across all workspaces"
 * method, because that is the method a cross-tenant bug reaches for. A caller that
 * genuinely needs a cross-workspace read — and there are two legitimate ones, the install
 * migration and the site-admin repair path — has to say so by passing `'*'` *and* has its
 * capability checked by the caller, which is visible at the call site.
 *
 * ### Nothing here knows what a workspace is
 *
 * This is a table gateway, not a permission system. It does not decide whether a user may
 * read a row; {@see Permission_Manager} does that, and the repositories above this one
 * call it. Mixing the two would mean a storage helper whose behaviour changes based on a
 * global, which is the sort of thing that passes a test and fails in production.
 */
abstract class Collaboration_Store {

	/**
	 * The `Collaboration_Schema`, used to confirm a table exists before querying it.
	 *
	 * @var Collaboration_Schema
	 */
	protected $schema;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected $logger;

	/**
	 * The entity kind this store owns, as a key in {@see Workspace_Limits::table()}.
	 *
	 * @var string
	 */
	protected $kind = '';

	/**
	 * Constructor.
	 *
	 * @param Collaboration_Schema|null $schema Optional schema.
	 * @param Logger|null              $logger Optional logger.
	 */
	public function __construct( $schema = null, $logger = null ) {
		$this->schema = $schema instanceof Collaboration_Schema ? $schema : new Collaboration_Schema();
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Return the entity kind.
	 *
	 * @return string
	 */
	public function kind() {
		return (string) $this->kind;
	}

	/**
	 * Return the prefixed table name, or an empty string when the kind is unknown.
	 *
	 * @return string
	 */
	protected function table() {
		return Workspace_Limits::prefixed_table( $this->kind );
	}

	/**
	 * Return whether the table is present.
	 *
	 * Checked before every write. A missing table otherwise produces a database error deep
	 * inside a review request, and "the collaboration tables are not installed" is a
	 * diagnostic an administrator can act on while "Table 'wp_replicaforge_reviews' doesn't
	 * exist" at the end of a fatal is not.
	 *
	 * @return bool
	 */
	protected function ready() {
		$table = $this->table();
		return ( '' !== $table ) && $this->schema->table_exists( $table );
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Insert a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>|null The stored row as re-read, or null on failure.
	 */
	protected function insert( array $row ) {
		global $wpdb;

		if ( ! $this->ready() || array() === $row ) {
			return null;
		}

		$inserted = $wpdb->insert( $this->table(), $this->prepare_row( $row ) );
		if ( false === $inserted ) {
			$this->logger->error(
				'collaboration_insert_failed',
				'Could not store a collaboration record.',
				array( 'kind' => $this->kind ),
				'workspace'
			);
			return null;
		}

		// Re-read rather than returning the input. `$wpdb->insert` reports success, and
		// the row as stored is what a later read will see - so returning the input would
		// let a caller observe a value the database did not keep.
		$read = $this->read_by_id( (int) $wpdb->insert_id );
		return ( null === $read ) ? null : $read;
	}

	/**
	 * Update a row by its public id.
	 *
	 * Named `update_row()` and not `update()` on purpose. A concrete store is expected to
	 * expose a *public* `update()` as part of its own vocabulary, and PHP lets a subclass
	 * widen a protected parent's visibility - at which point every internal
	 * `$this->update()` call in that subclass resolves to the subclass method instead of
	 * this one. Two methods that differ only in visibility cannot coexist under one name.
	 *
	 * @param string               $public_id Public id.
	 * @param array<string, mixed> $changes   Column changes.
	 * @return bool
	 */
	protected function update_row( $public_id, array $changes ) {
		global $wpdb;

		if ( ! $this->ready() || '' === (string) $public_id || array() === $changes ) {
			return false;
		}

		$updated = $wpdb->update( $this->table(), $this->prepare_row( $changes ), array( 'public_id' => (string) $public_id ) );
		if ( false === $updated ) {
			$this->logger->error(
				'collaboration_update_failed',
				'Could not update a collaboration record.',
				array( 'kind' => $this->kind ),
				'workspace'
			);
			return false;
		}

		// `0` means "matched nothing to change", which for an idempotent write is a
		// success, not a failure. Reporting false would make a retry loop on a write
		// that had already landed.
		return true;
	}

	/**
	 * Delete rows matching a column set.
	 *
	 * Named `delete_rows()` for the same reason as `update_row()`: a concrete store
	 * exposes a public `delete()` of its own, and two methods that differ only in
	 * visibility cannot coexist under one name.
	 *
	 * Hard deletes are used only for rows with no history value: a *task* the user
	 * created, a notification that was read, a revoked invitation. Nothing a project
	 * produced, and nothing a security question could be answered from, is ever
	 * hard-deleted through this method.
	 *
	 * @param array<string, mixed> $where Where.
	 * @return int Rows removed, or 0.
	 */
	protected function delete_rows( array $where ) {
		global $wpdb;

		if ( ! $this->ready() || array() === $where ) {
			return 0;
		}
		return (int) $wpdb->delete( $this->table(), $where );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return one row by its public id, within a workspace.
	 *
	 * @param string $workspace_id Workspace id, or `'*'` for an explicit cross-workspace read.
	 * @param string $public_id    Public id.
	 * @return array<string, mixed>|null
	 */
	protected function find( $workspace_id, $public_id ) {
		$workspace_id = (string) $workspace_id;
		$public_id    = (string) $public_id;

		if ( ! $this->ready() || '' === $workspace_id || '' === $public_id ) {
			return null;
		}

		$table = $this->table();
		global $wpdb;

		if ( '*' === $workspace_id ) {
			// The only cross-workspace read, and it is spelled out rather than reached by
			// omission. Callers using it have had their capability checked.
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s LIMIT 1", $public_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
				ARRAY_A
			);
			return $row ? $this->cast( $row ) : null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE workspace_id = %s AND public_id = %s LIMIT 1", $workspace_id, $public_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			ARRAY_A
		);
		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Return one row by its sequential id.
	 *
	 * @param int $id Sequential id.
	 * @return array<string, mixed>|null
	 */
	protected function read_by_id( $id ) {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an internal constant.
			ARRAY_A
		);
		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Return one row by a set of exact column matches, within a workspace.
	 *
	 * @param string               $workspace_id Workspace id, or `'*'`.
	 * @param array<string, mixed> $where       Column matches.
	 * @return array<string, mixed>|null
	 */
	protected function find_where( $workspace_id, array $where ) {
		global $wpdb;

		$workspace_id = (string) $workspace_id;
		if ( ! $this->ready() || '' === $workspace_id || array() === $where ) {
			return null;
		}

		$table  = $this->table();
		$clause = $this->where_clause( $where, array( 'workspace_id' ) );
		if ( '' === $clause['sql'] ) {
			return null;
		}

		$sql = "SELECT * FROM {$table} WHERE " . $clause['sql'] . ' LIMIT 1';
		if ( '*' !== $workspace_id ) {
			$sql = "SELECT * FROM {$table} WHERE workspace_id = %s AND " . $clause['sql'] . ' LIMIT 1';
			$row = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( array( $workspace_id ), $clause['args'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from an internal constant plus a generated clause of placeholders.
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $clause['args'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from an internal constant plus a generated clause of placeholders.
		}

		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Return rows matching a set of conditions, newest first, paginated.
	 *
	 * ### Why a cursor and not an offset
	 *
	 * §45 asks for cursor pagination "where useful", and this is where it is useful: a
	 * comment thread grows while a reviewer is scrolling it. With `OFFSET`, inserting one
	 * comment shifts every later page by one and the reviewer sees a comment twice and
	 * misses another. With a `(created_at, id)` cursor, the page boundary is fixed and
	 * nothing shifts.
	 *
	 * `page` is still accepted, because a numbered list is genuinely what a UI wants when
	 * the data is not changing underneath it, and refusing it would be the plugin being
	 * clever at the caller's expense.
	 *
	 * @param string               $workspace_id Workspace id, or `'*'`.
	 * @param array<string, mixed> $args        Query arguments.
	 * @return array<string, mixed>
	 */
	protected function query( $workspace_id, array $args = array() ) {
		global $wpdb;

		$workspace_id = (string) $workspace_id;
		if ( ! $this->ready() || '' === $workspace_id ) {
			return $this->empty_page();
		}

		$table = $this->table();
		$limit = Workspace_Limits::page_size( $args['per_page'] ?? 0 );

		$conditions = array();
		$values     = array();

		if ( '*' !== $workspace_id ) {
			$conditions[] = 'workspace_id = %s';
			$values[]     = $workspace_id;
		}

		/*
		 * Exact-match filters. Every one is a placeholder, never an interpolated value.
		 *
		 * Phase 19 adds the last six. They are internal constant column names belonging to
		 * the template tables, and adding them here rather than overriding `query()` in three
		 * separate stores is the point: a subclass that re-implements the whole method to
		 * filter one column is a subclass that has to be kept in step with pagination,
		 * cursor handling and the search clause forever.
		 */
		$exact = array( 'status', 'project_id', 'user_id', 'role', 'type', 'parent_id', 'assignee_id', 'source', 'version_id', 'client_id', 'page_id', 'author_id', 'anchor_type', 'category', 'visibility', 'validation_state', 'template_id', 'component_id', 'component_type' );
		foreach ( $exact as $column ) {
			if ( ! isset( $args[ $column ] ) || '' === (string) $args[ $column ] || null === $args[ $column ] ) {
				continue;
			}
			$conditions[] = $column . ' = %s';
			$values[]     = (string) $args[ $column ];
		}

		/*
		 * "One of several" filters.
		 *
		 * A dashboard needs "open or in_progress" and the library needs "mine or workspace",
		 * neither of which a single-value filter can express. Phase 19 needed this for two
		 * more columns, so it is generalised rather than duplicated: each key names the
		 * *request* argument and the column it filters, both as literals here, so a caller
		 * cannot pass a column name and have it reach the query.
		 *
		 * `statuses` is the original spelling and is kept, so nothing that used it before
		 * this change breaks.
		 */
		$in_columns = array(
			'statuses'          => 'status',
			'categories'        => 'category',
			'validation_states' => 'validation_state',
			'visibilities'      => 'visibility',
		);

		foreach ( $in_columns as $argument => $column ) {
			if ( ! isset( $args[ $argument ] ) || ! is_array( $args[ $argument ] ) || array() === $args[ $argument ] ) {
				continue;
			}

			$values = array_slice( array_values( array_filter( array_map( 'strval', $args[ $argument ] ) ) ), 0, 10 );

			if ( array() === $values ) {
				continue;
			}

			$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
			$conditions[] = $column . ' IN (' . $placeholders . ')';

			// Appended in the same order the condition was appended, because `$wpdb->prepare`
			// binds positionally: the nth placeholder takes the nth value. Getting these two
			// out of step produces a query that runs and returns the wrong rows.
			foreach ( $values as $value ) {
				$values[] = $value;
			}
		}

		// Free text. `LIKE` with a prepared value, and the wildcards are added *inside* the
		// value so a caller cannot inject a pattern. The column list is a constant, not
		// input.
		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$search    = '%' . $this->esc_like( (string) $args['search'] ) . '%';
			$searchable = $this->searchable_columns();
			if ( array() !== $searchable ) {
				$parts = array();
				foreach ( $searchable as $column ) {
					$parts[]  = $column . ' LIKE %s';
					$values[] = $search;
				}
				$conditions[] = '(' . implode( ' OR ', $parts ) . ')';
			}
		}

		// Cursor: strictly older than the given (created_at, id).
		if ( isset( $args['before'] ) && is_array( $args['before'] ) && ! empty( $args['before']['created_at'] ) ) {
			$conditions[] = '( created_at < %s OR ( created_at = %s AND id < %d ) )';
			$values[]     = (string) $args['before']['created_at'];
			$values[]     = (string) $args['before']['created_at'];
			$values[]     = (int) ( $args['before']['id'] ?? 0 );
		}

		$where = ( array() === $conditions ) ? '1=1' : implode( ' AND ', $conditions );
		$order = $this->order_clause( (string) ( $args['order_by'] ?? 'created_at' ) );

		$offset = max( 0, (int) ( $args['page'] ?? 1 ) - 1 ) * $limit;
		$sql    = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table, where and order are all built from internal constants and placeholders.
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $values, array( $limit + 1, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- as above.

		$has_more = false;
		if ( is_array( $rows ) && count( $rows ) > $limit ) {
			$has_more = true;
			$rows     = array_slice( $rows, 0, $limit );
		}

		$items = array();
		$last  = null;
		foreach ( (array) $rows as $row ) {
			$item    = $this->cast( $row );
			$items[] = $item;
			$last    = $item;
		}

		return array(
			'items' => $items,
			'count' => count( $items ),
			'has_more' => $has_more,
			'per_page' => $limit,
			'page' => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'cursor' => ( null === $last ) ? null : array( 'created_at' => (string) ( $last['created_at'] ?? '' ), 'id' => (int) ( $last['id'] ?? 0 ) ),
		);
	}

	/**
	 * Count rows matching a set of conditions, within a workspace.
	 *
	 * @param string               $workspace_id Workspace id, or `'*'`.
	 * @param array<string, mixed> $args        Conditions.
	 * @return int
	 */
	protected function count_where( $workspace_id, array $args = array() ) {
		global $wpdb;

		$workspace_id = (string) $workspace_id;
		if ( ! $this->ready() || '' === $workspace_id ) {
			return 0;
		}

		$table       = $this->table();
		$conditions  = array();
		$values      = array();

		if ( '*' !== $workspace_id ) {
			$conditions[] = 'workspace_id = %s';
			$values[]     = $workspace_id;
		}
		foreach ( array( 'status', 'project_id', 'user_id', 'role', 'type', 'assignee_id', 'author_id' ) as $column ) {
			if ( ! isset( $args[ $column ] ) || '' === (string) $args[ $column ] ) {
				continue;
			}
			$conditions[] = $column . ' = %s';
			$values[]     = (string) $args[ $column ];
		}
		if ( isset( $args['statuses'] ) && is_array( $args['statuses'] ) && array() !== $args['statuses'] ) {
			$statuses = array_slice( array_values( array_filter( array_map( 'strval', $args['statuses'] ) ) ), 0, 10 );
			if ( array() !== $statuses ) {
				$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
				$conditions[] = 'status IN (' . $placeholders . ')';
				foreach ( $statuses as $status ) {
					$values[] = $status;
				}
			}
		}

		$where = ( array() === $conditions ) ? '1=1' : implode( ' AND ', $conditions );
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $values ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table is internal, where is placeholders only.
		);

		return (int) $count;
	}

	/**
	 * Count rows grouped by a column, within a workspace.
	 *
	 * ### The filter is a column *and* a value, not a fragment
	 *
	 * The first version took a raw `$filter` string and interpolated it into the SQL. A
	 * column name cannot be a prepared-statement placeholder, so that signature made an
	 * injection point of the only part of the query a placeholder cannot cover - and it
	 * also appended a stray empty value to the argument list, which would have shifted
	 * every argument after it.
	 *
	 * So the extra condition is declared as a `column => value` pair. The column is
	 * validated against the same constant list as the group-by, and the value goes through
	 * a placeholder like everything else. There is no signature on this class that accepts
	 * a SQL fragment.
	 *
	 * @param string               $workspace_id Workspace id, or `'*'`.
	 * @param string               $column       Column to group by.
	 * @param array<string, mixed> $filter       Optional `column => value` condition.
	 * @return array<string, int>
	 */
	protected function group_counts( $workspace_id, $column, array $filter = array() ) {
		global $wpdb;

		$workspace_id = (string) $workspace_id;
		$groupable    = $this->groupable_columns();

		if ( ! $this->ready() || '' === $workspace_id || ! in_array( $column, $groupable, true ) ) {
			return array();
		}

		$conditions = array( 'workspace_id = %s' );
		$values     = array( $workspace_id );

		foreach ( $filter as $filter_column => $filter_value ) {
			if ( ! in_array( $filter_column, $groupable, true ) ) {
				// An unknown filter column is dropped rather than interpolated. Silently
				// ignoring it would return a count that is quietly wrong, so the caller
				// also gets an empty array rather than an unfiltered total.
				return array();
			}
			$conditions[] = $filter_column . ' = %s';
			$values[]     = (string) $filter_value;
		}

		$table = $this->table();
		$where = implode( ' AND ', $conditions );

		$sql = "SELECT {$column} AS label, COUNT(*) AS total FROM {$table} WHERE {$where} GROUP BY {$column} ORDER BY total DESC";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- both columns are validated against constant lists; every value is a placeholder.
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['label'] ] = (int) $row['total'];
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return a fresh public id for a table.
	 *
	 * ### Why base32 rather than base64
	 *
	 * Section 17 forbids using sequential database ids as authorisation, so the public id
	 * is what appears in URLs, comments and activity, and it has to be unguessable. Sixteen
	 * bytes of `random_bytes` is 128 bits, which is far more than enough.
	 *
	 * The first version derived a string from `base64_encode()` and stripped `+`, `/` and
	 * `=`. That alphabet is URL-safe, but the *length* is not fixed: whenever the random
	 * bytes happened to produce a `+` or a `/`, the id came out 21 characters instead of
	 * 22. So "a 22-character id" was not true, and neither was "128 bits" - a
	 * 21-character id carries fewer. Neither is exploitable, but an identifier that leaks
	 * two bits of its own length is a surprising thing to hand a system that treats these
	 * as secrets, and a `CHAR(26)` column holding 21 or 22 characters is a schema that
	 * does not say what it means.
	 *
	 * Base32 with Crockford's alphabet is used instead: fixed width, no characters needing
	 * escaping in a URL or a query string, and no padding to strip. 128 bits is exactly 26
	 * characters, which is why the column is declared `CHAR(26)`.
	 *
	 * The conversion is integer bit-shifting rather than a string of bits grouped five at a
	 * time. The string version was tried first and produced ids in which almost every
	 * character was `0`, because a hexadecimal string padded to a binary width loses most
	 * of its data to the padding. Shifting an integer is unambiguous, and the width falls
	 * out of the arithmetic instead of being enforced by a `substr`.
	 *
	 * @return string
	 */
	protected function new_public_id() {
		try {
			$bytes = random_bytes( 16 );
		} catch ( \Exception $e ) {
			/*
			 * `random_bytes` is available on every supported PHP, so this is unreachable in
			 * practice. If it ever is reachable, `wp_generate_password` is seeded from the
			 * same source rather than from `rand()`, which would not be acceptable for an
			 * identifier that participates in an authorisation decision - so the fallback is
			 * deliberately not `rand()`.
			 */
			$bytes = substr( (string) wp_generate_password( 32, false, false ), 0, 16 );
		}

		// Crockford's alphabet: no I, L, O or U, so an id cannot be misread as another.
		$alphabet = '0123456789abcdefghjkmnpqrstvwxyz';

		$id       = '';
		$buffer   = 0;
		$bitsHeld = 0;

		foreach ( str_split( (string) $bytes ) as $byte ) {
			$buffer   = ( $buffer << 8 ) | ord( $byte );
			$bitsHeld += 8;

			while ( $bitsHeld >= 5 ) {
				$bitsHeld -= 5;
				$id       .= $alphabet[ ( $buffer >> $bitsHeld ) & 31 ];
			}
		}

		// 128 bits gives 25 whole characters and three bits over, so exactly 26.
		if ( $bitsHeld > 0 ) {
			$id .= $alphabet[ ( $buffer << ( 5 - $bitsHeld ) ) & 31 ];
		}

		return $id;
	}

	/**
	 * Normalise a row for storage: drop unknown keys, coerce types, add timestamps.
	 *
	 * Dropping unknown keys matters: a caller that passes `user_id` to a table without
	 * one gets it ignored rather than producing a column that does not exist, and it means
	 * a typo like `'titel'` is silently absent rather than silently stored.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	protected function prepare_row( array $row ) {
		$allowed = $this->writable_columns();
		$out     = array();
		$now     = gmdate( 'Y-m-d H:i:s' );

		foreach ( $row as $column => $value ) {
			if ( ! in_array( $column, $allowed, true ) ) {
				continue;
			}
			$out[ $column ] = $this->coerce( $column, $value );
		}

		if ( array() !== $out && ! isset( $out['updated_at'] ) && in_array( 'updated_at', $allowed, true ) ) {
			$out['updated_at'] = $now;
		}
		return $out;
	}

	/**
	 * Coerce a value to the type its column stores.
	 *
	 * One place, so a `status` is a string in every table and a `TINYINT(1)` is an integer
	 * everywhere. A boolean arriving as `true` and being stored as `1` is fine; a boolean
	 * arriving as `true` and being stored as the string `"1"` is a comparison that is
	 * mysteriously false three tables later.
	 *
	 * @param string $column Column.
	 * @param mixed  $value  Value.
	 * @return mixed
	 */
	protected function coerce( $column, $value ) {
		$type = $this->column_types();

		if ( ! isset( $type[ $column ] ) ) {
			return is_bool( $value ) ? (int) $value : (string) $value;
		}

		switch ( $type[ $column ] ) {
			case 'int':
				// A zero user id means "invited by email, no account yet". It is stored as
				// 0 rather than NULL so the column can stay NOT NULL, which in turn lets
				// the membership index be a plain index instead of a composite unique one
				// that would forbid two email-only rows in the same workspace. The
				// uniqueness check that index would have provided is done in `add()`,
				// before the insert.
				return (int) $value;
			case 'float':
				return ( null === $value || '' === $value ) ? null : (float) $value;
			case 'bool':
				return (int) ( (bool) $value );
			case 'json':
				return wp_json_encode( $this->sanitise_json( $value ) );
			case 'text':
				// `sanitize_textarea_field` keeps newlines, which a comment body needs, and
				// strips the tags a comment body must never carry (§42).
				return sanitize_textarea_field( (string) $value );
			case 'line':
				return sanitize_text_field( (string) $value );
			case 'datetime':
				return $this->normalise_datetime( $value );
			case 'url':
				return $this->clean_url( $value );
			case 'email':
				return sanitize_email( (string) $value );
			default:
				return (string) $value;
		}
	}

	/**
	 * Cast a row as read from the database.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	protected function cast( array $row ) {
		$types = $this->column_types();

		foreach ( $row as $column => $value ) {
			if ( ! isset( $types[ $column ] ) ) {
				continue;
			}
			if ( 'int' === $types[ $column ] ) {
				$row[ $column ] = (int) $value;
			} elseif ( 'bool' === $types[ $column ] ) {
				$row[ $column ] = (bool) (int) $value;
			} elseif ( 'float' === $types[ $column ] ) {
				$row[ $column ] = ( null === $value ) ? null : (float) $value;
			} elseif ( 'json' === $types[ $column ] ) {
				$decoded        = json_decode( (string) $value, true );
				$row[ $column ] = is_array( $decoded ) ? $decoded : array();
			} elseif ( 'datetime' === $types[ $column ] ) {
				$row[ $column ] = ( null === $value || '' === $value ) ? null : (string) $value;
			}
		}
		return $row;
	}

	/**
	 * Escape a LIKE wildcard.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	protected function esc_like( $value ) {
		global $wpdb;
		return (string) $wpdb->esc_like( (string) $value );
	}

	/**
	 * Build a `WHERE` fragment and its arguments from an exact-match set.
	 *
	 * @param array<string, mixed> $where    Matches.
	 * @param array<int, string>   $excluded Columns to skip.
	 * @return array{sql: string, args: array<int, mixed>}
	 */
	protected function where_clause( array $where, array $excluded = array() ) {
		$parts = array();
		$args  = array();
		foreach ( $where as $column => $value ) {
			if ( in_array( $column, $excluded, true ) || null === $value ) {
				continue;
			}
			$parts[] = $column . ' = %s';
			$args[]  = is_bool( $value ) ? (int) $value : (string) $value;
		}
		return array( 'sql' => implode( ' AND ', $parts ), 'args' => $args );
	}

	/**
	 * Build a safe `ORDER BY` clause.
	 *
	 * The column is validated against a constant list. An `order_by` arriving from a
	 * request and interpolated into `ORDER BY` is the one place a prepared statement does
	 * not protect you, because the position is not a value.
	 *
	 * @param string $column Requested column.
	 * @return string
	 */
	protected function order_clause( $column ) {
		/*
		 * Phase 19 adds the last five so the template library can sort the ways §24 asks for:
		 * by name, by type, by how often a template has been installed, and by the
		 * `validation_state` a reviewer would want at the top.
		 *
		 * These are still literal column names, not fragments, and each exists on at least
		 * one table. A column absent from a table simply never reaches here, because
		 * `order_by` is validated here and the *table* is chosen by `$this->kind` before the
		 * query is built — so an order-by a given table does not have is a caller error, and
		 * is corrected by `Template_Store` before it reaches this method.
		 */
		$allowed = array( 'created_at', 'updated_at', 'id', 'due_date', 'status', 'severity', 'priority', 'name', 'type', 'install_count', 'validation_state', 'usage_count' );
		$column  = in_array( $column, $allowed, true ) ? $column : 'created_at';
		// `id` is the tiebreaker everywhere so a cursor is stable when many rows share a
		// timestamp, which they do when a bulk import writes a page of events at once.
		return $column . ' DESC, id DESC';
	}

	/**
	 * Return the UTC datetime for a value.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	protected function normalise_datetime( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\d{9,11}$/', $value ) ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}
		$time = strtotime( (string) $value );
		return ( false === $time ) ? null : gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Validate and clean a URL, returning an empty string rather than a broken one.
	 *
	 * Goes through `Url_Validator`, so a client website pointing at a private address is
	 * dropped at write time rather than becoming a request ReplicaForge later refuses.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected function clean_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$verdict = ( new Url_Validator() )->validate( $value );
		return empty( $verdict['success'] ) ? '' : (string) $verdict['url'];
	}

	/**
	 * Sanitise a value destined for a JSON column.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	protected function sanitise_json( $value ) {
		return Data_Redactor::structure( is_array( $value ) ? $value : array() );
	}

	/**
	 * Return an empty page.
	 *
	 * @return array<string, mixed>
	 */
	protected function empty_page() {
		return array(
			'items'    => array(),
			'count'    => 0,
			'has_more' => false,
			'per_page' => Workspace_Limits::PAGE['default'],
			'page'     => 1,
			'cursor'   => null,
		);
	}

	/* ---------------------------------------------------------------------
	 * Abstract description of the concrete table
	 * ------------------------------------------------------------------ */

	/**
	 * Return the columns this table may be written with.
	 *
	 * @return array<int, string>
	 */
	abstract protected function writable_columns();

	/**
	 * Return the storage type of each column.
	 *
	 * @return array<string, string>
	 */
	abstract protected function column_types();

	/**
	 * Return the columns free text may search.
	 *
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'name', 'title' );
	}

	/**
	 * Return the columns a group count may be taken over.
	 *
	 * @return array<int, string>
	 */
	protected function groupable_columns() {
		return array( 'status', 'role', 'type', 'priority', 'severity' );
	}
}
