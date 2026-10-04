<?php
/**
 * Phase 15: the collaboration table schema.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's first tables.
 *
 * ### Why Phase 15 introduces tables at all
 *
 * Every other phase stores in options. That was the right call for what they held: one
 * project, one analysis, one report — a small, bounded blob that is read whole and written
 * whole. Option storage is simple and needs no migration story.
 *
 * §45 asks for an agency with 50 to 500 projects, 50 members, and **thousands** of activity
 * events, comments and tasks. That is a different shape of data, and an option cannot hold
 * it:
 *
 * - every read deserialises the entire blob, so listing 25 comments loads all of them;
 * - every write rewrites the entire blob, so one comment rewrites a megabyte of history;
 * - there is no index, so "comments on this project, newest first, page 4" is a full scan
 *   followed by a sort in PHP;
 * - there is no cursor, so §33's workspace search cannot paginate at all;
 * - `update_option` will grow a row until it can no longer be written, and it fails at the
 *   worst possible moment.
 *
 * So the collaboration layer gets tables. That is a real architectural step for this
 * plugin, which is why the split is deliberate rather than gradual:
 *
 * **Tables** — many rows per workspace or project, queried by index, paginated: activity,
 * audit, comments, tasks, issues, notifications, and the membership and invitation tables
 * (500 projects times 50 members is 25,000 project-member rows, and an invitation needs a
 * unique lookup by token hash).
 *
 * **Options, untouched** — `Project_Repository` and every other Phase 1-14 store. The
 * reconstruction engine's storage is not migrated, not shadowed and not duplicated. Phase
 * 15 reads projects through the repository that has always held them.
 *
 * ### Every table is created here and nowhere else
 *
 * Names come from {@see Workspace_Limits::table()}. There is no
 * `prefix . 'replicaforge_' . $kind` anywhere in the layer, because a table name assembled
 * from a variable is one typo away from being the wrong table.
 *
 * ### dbDelta's rules, and why they look fussy
 *
 * `dbDelta` compares a *string* against a live table. It wants the table name inline, one
 * column per line, `KEY` declarations inside the `CREATE`, and two spaces between
 * `PRIMARY KEY` and its columns. A definition that a human would consider identical but
 * that differs in whitespace produces a table with no indexes — which looks successful
 * and is not. So the definitions below are written in exactly the shape dbDelta expects,
 * and the indexes are inside them rather than added by a second `ALTER`.
 */
final class Collaboration_Schema {

	/**
	 * The schema version these tables belong to.
	 */
	/**
	 * The installed schema version.
	 *
	 * Bumped to `19.0.0` for the Phase 19 template tables. `install()` is idempotent and
	 * re-runs `dbDelta` over every definition, so an existing install gains the three new
	 * tables the next time it runs — which is what makes the addition additive rather than
	 * a migration that has to know what state each site is in.
	 *
	 * @var string
	 */
	const VERSION = '20.0.0';

	/**
	 * Option recording the installed table version.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'replicaforge_collaboration_schema';

	/**
	 * Create or update every collaboration table.
	 *
	 * @return array<string, mixed>
	 */
	public function install() {
		global $wpdb;
		// `dbDelta` lives in `wp-admin/includes/upgrade.php`, which is loaded on the admin
		// screens and *not* on the front end, on a REST request, or on a cron run. Calling
		// it without this is an undefined-function fatal, and the contexts that hit it -
		// a WP-CLI install, a front-end triggered migration - are both plausible.
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$created = array();
		$errors  = array();

		foreach ( $this->definitions() as $kind => $definition ) {
			$table = Workspace_Limits::prefixed_table( $kind );
			if ( '' === $table ) {
				$errors[ $kind ] = __( 'No table is defined for that entity.', 'replicaforge' );
				continue;
			}

			$sql = sprintf( $definition, $table ) . $this->charset();

			/*
			 * `dbDelta` on a table that already exists emits notices that look like failures.
			 * They are suppressed for the duration of the call only, and the outcome is then
			 * verified independently below, so suppression never hides a real problem - it only
			 * stops an expected one being reported as unexpected.
			 */
			$previous = $wpdb->suppress_errors( true );
			dbDelta( $sql );
			$wpdb->suppress_errors( $previous );

			/*
			 * Verify the table afterwards, **unconditionally**.
			 *
			 * This is what the check has always been documented as doing, and until now it only
			 * did it when `dbDelta` returned an empty array. That is a real hole, and Phase 20
			 * found it: `dbDelta` returns the columns it created, which is non-empty on
			 * success - but it also returned a non-empty array for a table it had silently
			 * failed to create, because `TRIGGER` is a MySQL reserved word and the
			 * `CREATE TABLE` would not parse. `install()` recorded the kind in `$created` and
			 * reported `ok => true` for a table that did not exist.
			 *
			 * That is the worst possible shape of failure for a schema installer: the caller
			 * believes the migration ran, the version option is written, and the missing table
			 * surfaces much later as an unrelated "table doesn't exist" database error - which
			 * is exactly how the Phase 15 and Phase 19 suites found it, two suites and one
			 * phase after it was introduced.
			 *
			 * So the existence check no longer depends on what `dbDelta` reported. It costs one
			 * `SHOW TABLES` per table on an install, which is not a cost worth optimising away
			 * from a correctness guarantee - and it is the check that makes `migrate_platform()`
			 * throwing on `! $install['ok']` actually mean something.
			 */
			if ( ! $this->table_exists( $table ) ) {
				$errors[ $kind ] = __( 'The table could not be created. The database user may not have CREATE permission, or the definition may use a reserved word.', 'replicaforge' );
				unset( $created[ $kind ] );
				continue;
			}

			$created[ $kind ] = $table;
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );

		return array(
			'version' => self::VERSION,
			'tables'  => $created,
			'errors'  => $errors,
			'ok'      => ( array() === $errors ),
		);
	}

	/**
	 * Return whether every collaboration table exists.
	 *
	 * Checked explicitly rather than trusted from the version option, because a table can
	 * be dropped by a database cleanup, a migration tool, or a partial restore while the
	 * option still claims it is there. Then a missing table surfaces as a confusing
	 * database error during a client review instead of as a status report.
	 *
	 * @return array<string, mixed>
	 */
	public function status() {
		$present = array();
		$missing = array();

		foreach ( array_keys( $this->definitions() ) as $kind ) {
			$table = Workspace_Limits::prefixed_table( $kind );
			if ( $this->table_exists( $table ) ) {
				$present[] = $table;
			} else {
				$missing[] = $table;
			}
		}

		return array(
			'version' => (string) get_option( self::VERSION_OPTION, '' ),
			'present' => $present,
			'missing' => $missing,
			'ok'      => ( array() === $missing ),
		);
	}

	/**
	 * Return whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public function table_exists( $table ) {
		global $wpdb;
		if ( '' === (string) $table ) {
			return false;
		}
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return ( $found === $table );
	}

	/**
	 * Return a callable that suppresses database errors, and its inverse.
	 *
	 * `dbDelta` on a shared host can emit a notice for a table that already exists, which
	 * would fill the debug log with noise that looks like a failure. Errors are suppressed
	 * *for the call only* and the outcome is then verified independently, so suppression
	 * never hides a real problem — it only stops an expected one from being reported as
	 * unexpected.
	 *
	 * @return callable
	 */
	/**
	 * Return the charset clause.
	 *
	 * Taken verbatim from `$wpdb->get_charset_collate()` rather than assembled, because
	 * the assembly is where a hand-written charset clause goes wrong and the consequence
	 * is a table that cannot store a four-byte emoji in a project name.
	 *
	 * @return string
	 */
	private function charset() {
		global $wpdb;
		$charset = (string) $wpdb->get_charset_collate();
		return ( '' === $charset ) ? '' : ' ' . $charset . ';';
	}

	/**
	 * Return every table definition, as a `CREATE TABLE %s` format string.
	 *
	 * ### Column conventions
	 *
	 * - Every table carries an auto-increment `id` and a **public** random `public_id`. The
	 *   public id is what appears in URLs, comments and activity; the sequential id never
	 *   leaves PHP. §17 forbids using sequential database ids as authorization, and the
	 *   cheapest way to honour that is for the sequential id to never be used anywhere but
	 *   as a primary key.
	 * - Every table carries `workspace_id`. Filtering every single query by workspace is
	 *   what makes cross-workspace isolation a property of the schema rather than a
	 *   condition someone has to remember.
	 * - Timestamps are `DATETIME` in UTC, matching the ISO-8601 strings the rest of the
	 *   plugin stores, so the two storage styles can be compared.
	 * - No column holds a secret in recoverable form. Invitation tokens and review-link
	 *   passwords are stored as SHA-256 hashes in `CHAR(64)`.
	 * - The one deliberate redundancy: `reviews` stores both `version_id` and
	 *   `version_number`, and `version_id` is `NOT NULL`. §15 forbids approving "the
	 *   project", and the cheapest way to make that impossible is a column that cannot be
	 *   null.
	 *
	 * ### Why `link_hash` is not unique
	 *
	 * A review with no link yet stores `link_hash = ''`, so a UNIQUE index on that column
	 * permits exactly **one** linkless review per workspace: the first insert succeeds and
	 * every later one fails with a duplicate-key error. That was found by a test that
	 * created a second review, not by reading the schema, which is the whole argument for
	 * running the assertions.
	 *
	 * Uniqueness was never load-bearing. A link hash is an HMAC over a fresh 32-byte random
	 * token, so two reviews cannot collide, and "one live link per review" is structural -
	 * the link fields live on the review row, so there is only ever one of them per row.
	 *
	 * The index is still needed: `Review_Store::by_link_hash()` resolves a presented token
	 * through it on every review-link open.
	 *
	 * ### Why there is no composite `workspace_user` / `project_user` index
	 *
	 * Both membership tables carry `user_id` and a workspace, and the obvious index is the
	 * pair. It was declared UNIQUE to stop the same account being added twice, which is
	 * wrong for this data: a workspace legitimately holds many rows with no linked account
	 * at all - client contacts, reviewers invited by email - and they all share
	 * `user_id = 0`, so the second one violated the constraint and the invitation failed at
	 * the database instead of being stored.
	 *
	 * Relaxing the index to a plain one is the obvious fix, and it does not work. `dbDelta`
	 * reconciles whether an index *exists*, not whether it is unique, so an install that
	 * already ran the UNIQUE version keeps it indefinitely. A schema correction that
	 * silently fails to reach existing installs is worse than the bug it was fixing.
	 *
	 * So the index is omitted, and the single-column `user_id` index already declared on
	 * each table serves both lookups. Both queries are driven by the user id, which is
	 * highly selective, so the engine finds at most one row and `workspace_id` becomes a
	 * comparison on that row rather than a filter over a scan.
	 *
	 * The duplicate check the unique index provided now lives in
	 * {@see Workspace_Member_Store::add()}, and it is *better* there: it matches on an
	 * address as well as on a user id, so it catches the same person invited twice by two
	 * slightly different addresses, which a unique key on `(workspace_id, user_id)` could
	 * never do.
	 *
	 * @return array<string, string>
	 */
	private function definitions() {
		$now = 'DATETIME NULL DEFAULT NULL';

		return array(

			'workspaces' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				name VARCHAR(191) NOT NULL,
				owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(32) NOT NULL DEFAULT 'active',
				slug VARCHAR(191) NOT NULL DEFAULT '',
				settings LONGTEXT NULL,
				migrated TINYINT(1) NOT NULL DEFAULT 0,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY owner_id (owner_id),
				KEY status (status)
			)",

			'members' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				email VARCHAR(191) NOT NULL DEFAULT '',
				display_name VARCHAR(191) NOT NULL DEFAULT '',
				role VARCHAR(32) NOT NULL DEFAULT 'reviewer',
				status VARCHAR(32) NOT NULL DEFAULT 'active',
				invited_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				joined_at $now,
				last_seen_at $now,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY workspace_role (workspace_id, role),
				KEY user_id (user_id),
				KEY email (email)
			)",

			'invitations' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL,
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				email VARCHAR(191) NOT NULL DEFAULT '',
				role VARCHAR(32) NOT NULL DEFAULT 'reviewer',
				project_role VARCHAR(32) NOT NULL DEFAULT '',
				token_hash CHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(32) NOT NULL DEFAULT 'pending',
				created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				expires_at $now,
				accepted_at $now,
				accepted_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY token_hash (token_hash),
				KEY workspace_status (workspace_id, status),
				KEY email (email),
				KEY project_id (project_id)
			)",

			'clients' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL,
				name VARCHAR(191) NOT NULL DEFAULT '',
				company VARCHAR(191) NOT NULL DEFAULT '',
				email VARCHAR(191) NOT NULL DEFAULT '',
				phone VARCHAR(64) NOT NULL DEFAULT '',
				website VARCHAR(255) NOT NULL DEFAULT '',
				notes LONGTEXT NULL,
				status VARCHAR(32) NOT NULL DEFAULT 'active',
				archived_at $now,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY workspace_status (workspace_id, status),
				KEY name (name)
			)",

			'contacts' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL,
				client_id CHAR(26) NOT NULL DEFAULT '',
				name VARCHAR(191) NOT NULL DEFAULT '',
				email VARCHAR(191) NOT NULL DEFAULT '',
				role VARCHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(32) NOT NULL DEFAULT 'active',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY client_id (client_id),
				KEY workspace_id (workspace_id),
				KEY email (email)
			)",

			'project_member' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL,
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				email VARCHAR(191) NOT NULL DEFAULT '',
				role VARCHAR(32) NOT NULL DEFAULT 'observer',
				status VARCHAR(32) NOT NULL DEFAULT 'active',
				invited_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				added_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY workspace_id (workspace_id),
				KEY user_id (user_id)
			)",

			'reviews' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL,
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				version_id VARCHAR(64) NOT NULL DEFAULT '',
				version_number INT UNSIGNED NOT NULL DEFAULT 0,
				page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				generation_id VARCHAR(64) NOT NULL DEFAULT '',
				validation_id VARCHAR(64) NOT NULL DEFAULT '',
				type VARCHAR(32) NOT NULL DEFAULT 'internal',
				status VARCHAR(32) NOT NULL DEFAULT 'pending',
				reviewer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				reviewer_email VARCHAR(191) NOT NULL DEFAULT '',
				link_hash CHAR(64) NOT NULL DEFAULT '',
				link_password_hash CHAR(64) NOT NULL DEFAULT '',
				link_expires_at $now,
				link_revoked TINYINT(1) NOT NULL DEFAULT 0,
				link_attempts INT UNSIGNED NOT NULL DEFAULT 0,
				link_suspended TINYINT(1) NOT NULL DEFAULT 0,
				link_last_used_at $now,
				title VARCHAR(191) NOT NULL DEFAULT '',
				note LONGTEXT NULL,
				decision_note LONGTEXT NULL,
				requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				requested_at $now,
				completed_at $now,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY link_hash (link_hash),
				KEY project_status (project_id, status),
				KEY workspace_id (workspace_id),
				KEY reviewer_id (reviewer_id),
				KEY version_id (version_id)
			)",

			'comments' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL,
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				version_id VARCHAR(64) NOT NULL DEFAULT '',
				parent_id CHAR(26) NOT NULL DEFAULT '',
				anchor_type VARCHAR(32) NOT NULL DEFAULT 'project',
				section_id VARCHAR(64) NOT NULL DEFAULT '',
				component_id VARCHAR(64) NOT NULL DEFAULT '',
				element_id VARCHAR(64) NOT NULL DEFAULT '',
				difference_id VARCHAR(64) NOT NULL DEFAULT '',
				mapping_id VARCHAR(64) NOT NULL DEFAULT '',
				viewport VARCHAR(16) NOT NULL DEFAULT '',
				region_x DECIMAL(8,3) NULL DEFAULT NULL,
				region_y DECIMAL(8,3) NULL DEFAULT NULL,
				region_width DECIMAL(8,3) NULL DEFAULT NULL,
				region_height DECIMAL(8,3) NULL DEFAULT NULL,
				body TEXT NOT NULL,
				status VARCHAR(32) NOT NULL DEFAULT 'open',
				mentions TEXT NULL,
				author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				author_name VARCHAR(191) NOT NULL DEFAULT '',
				is_client TINYINT(1) NOT NULL DEFAULT 0,
				resolved_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				resolved_at $now,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY project_status (project_id, status),
				KEY parent_id (parent_id),
				KEY version_id (version_id),
				KEY workspace_id (workspace_id),
				KEY component_id (component_id)
			)",

			'tasks' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL,
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				title VARCHAR(255) NOT NULL DEFAULT '',
				description TEXT NULL,
				status VARCHAR(32) NOT NULL DEFAULT 'todo',
				priority VARCHAR(16) NOT NULL DEFAULT 'medium',
				assignee_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				creator_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				due_date $now,
				linked_issue_id CHAR(26) NOT NULL DEFAULT '',
				promoted_from VARCHAR(32) NOT NULL DEFAULT '',
				linked_page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				linked_component_id VARCHAR(64) NOT NULL DEFAULT '',
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY project_status (project_id, status),
				KEY assignee_id (assignee_id),
				KEY workspace_id (workspace_id)
			)",

			'issues' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL,
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				title VARCHAR(255) NOT NULL DEFAULT '',
				description TEXT NULL,
				severity VARCHAR(32) NOT NULL DEFAULT 'moderate',
				category VARCHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(32) NOT NULL DEFAULT 'open',
				assignee_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				reporter_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				source VARCHAR(32) NOT NULL DEFAULT 'manual',
				source_reference LONGTEXT NULL,
				generated_reference LONGTEXT NULL,
				validation_id VARCHAR(64) NOT NULL DEFAULT '',
				page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				component_id VARCHAR(64) NOT NULL DEFAULT '',
				section_id VARCHAR(64) NOT NULL DEFAULT '',
				created_at $now,
				updated_at $now,
				resolved_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY project_status (project_id, status),
				KEY assignee_id (assignee_id),
				KEY workspace_id (workspace_id),
				KEY validation_id (validation_id)
			)",

			'notifications' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				type VARCHAR(48) NOT NULL DEFAULT '',
				title VARCHAR(255) NOT NULL DEFAULT '',
				body TEXT NULL,
				link VARCHAR(255) NOT NULL DEFAULT '',
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				channel VARCHAR(16) NOT NULL DEFAULT 'in_app',
				status VARCHAR(32) NOT NULL DEFAULT 'unread',
				email_sent_at $now,
				read_at $now,
				created_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY user_status (user_id, status),
				KEY workspace_id (workspace_id),
				KEY type (type)
			)",

			// The compound indexes here are the ones that matter. A project timeline is
			// "newest first for this project", and a single-column index on project_id
			// would still need a filesort on created_at for every page.
			'activity' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL,
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				actor_name VARCHAR(191) NOT NULL DEFAULT '',
				action VARCHAR(48) NOT NULL DEFAULT '',
				resource_type VARCHAR(32) NOT NULL DEFAULT '',
				resource_id VARCHAR(64) NOT NULL DEFAULT '',
				metadata LONGTEXT NULL,
				created_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY project_created (project_id, created_at),
				KEY workspace_created (workspace_id, created_at),
				KEY actor_id (actor_id)
			)",

			'audit' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL DEFAULT '',
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				action VARCHAR(48) NOT NULL DEFAULT '',
				target_type VARCHAR(32) NOT NULL DEFAULT '',
				target_id VARCHAR(64) NOT NULL DEFAULT '',
				context_hash CHAR(64) NOT NULL DEFAULT '',
				ip_hash CHAR(64) NOT NULL DEFAULT '',
				metadata LONGTEXT NULL,
				created_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY workspace_created (workspace_id, created_at),
				KEY project_id (project_id),
				KEY action (action)
			)",

			/*
			 * Phase 19 — the template library.
			 *
			 * Three tables, matching the three lifecycles named in `Workspace_Limits::table()`.
			 * The design notes that matter, because `dbDelta` cannot fix them afterwards:
			 *
			 * - `templates` is the mutable row. The document itself is NOT here: an Elementor
			 *   document is far larger than a row should carry, and the library list renders
			 *   a row per template, so a document here would make listing templates mean
			 *   loading every document. The document lives in `template_versions.document`,
			 *   read only when a template is opened.
			 *
			 * - `template_versions` is append-only. There is deliberately no `updated_at`
			 *   semantic that invites an in-place edit; a significant change is a new row.
			 *   `dbDelta` will happily add a column to this table, so the immutability is
			 *   enforced in `Template_Version_Store`, which refuses to update a stored
			 *   version and says why.
			 *
			 * - `template_components` is a separate reusable entity rather than a column on
			 *   either of the above, because a component is versioned, shared across
			 *   templates, and updateable independently — §10 and §30 both require
			 *   "which templates use this component" to be answerable without a scan.
			 *
			 * Index choices worth stating, because they are the queries the library runs:
			 * - `templates (workspace_id, status, updated_at)` — the default library view.
			 * - `templates (project_id, status)` — "templates for this project", and the
			 *   index that stops `§8`'s "what does this token change affect?" from scanning.
			 * - `templates (workspace_id, template_type)` — the type filter.
			 * - `template_versions (template_id, version)` — the unique invariant, and the
			 *   lookup for "latest version". The UNIQUE is on the pair, not on
			 *   `template_id` alone, so trimming old versions cannot collide.
			 * - `template_components (workspace_id, component_type)` — the component filter.
			 *
			 * No column holds a secret. No column is UNIQUE on a value that legitimately
			 * repeats, for the `link_hash` reason documented on this method: a UNIQUE index
			 * on a column with an empty-string default permits exactly one such row.
			 */
			'templates' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL DEFAULT '',
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				name VARCHAR(191) NOT NULL DEFAULT '',
				description TEXT NULL,
				type VARCHAR(32) NOT NULL DEFAULT 'custom',
				category VARCHAR(32) NOT NULL DEFAULT 'mine',
				status VARCHAR(32) NOT NULL DEFAULT 'draft',
				visibility VARCHAR(32) NOT NULL DEFAULT 'private',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				current_version VARCHAR(64) NOT NULL DEFAULT '',
				version_count INT UNSIGNED NOT NULL DEFAULT 0,
				source_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				source_project_id VARCHAR(64) NOT NULL DEFAULT '',
				validation_state VARCHAR(32) NOT NULL DEFAULT '',
				tags LONGTEXT NULL,
				install_count INT UNSIGNED NOT NULL DEFAULT 0,
				archived_at $now,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY workspace_status (workspace_id, status, updated_at),
				KEY project_status (project_id, status),
				KEY workspace_type (workspace_id, type),
				KEY user_id (user_id)
			)",

			'template_version' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL DEFAULT '',
				template_id VARCHAR(64) NOT NULL DEFAULT '',
				version INT UNSIGNED NOT NULL DEFAULT 1,
				version_id VARCHAR(64) NOT NULL DEFAULT '',
				schema_version VARCHAR(16) NOT NULL DEFAULT '',
				engine_version VARCHAR(16) NOT NULL DEFAULT '',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				document LONGTEXT NULL,
				responsive LONGTEXT NULL,
				interactions LONGTEXT NULL,
				design_system LONGTEXT NULL,
				tokens LONGTEXT NULL,
				components LONGTEXT NULL,
				content_slots LONGTEXT NULL,
				assets LONGTEXT NULL,
				dependencies LONGTEXT NULL,
				compatibility LONGTEXT NULL,
				provenance LONGTEXT NULL,
				validation LONGTEXT NULL,
				bytes INT UNSIGNED NOT NULL DEFAULT 0,
				hash CHAR(64) NOT NULL DEFAULT '',
				change_note TEXT NULL,
				created_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY template_version (template_id, version),
				KEY template_created (template_id, created_at),
				KEY user_id (user_id)
			)",

			'template_component' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL DEFAULT '',
				component_id VARCHAR(64) NOT NULL DEFAULT '',
				name VARCHAR(191) NOT NULL DEFAULT '',
				type VARCHAR(32) NOT NULL DEFAULT '',
				description TEXT NULL,
				status VARCHAR(32) NOT NULL DEFAULT 'active',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				version INT UNSIGNED NOT NULL DEFAULT 1,
				version_count INT UNSIGNED NOT NULL DEFAULT 0,
				document LONGTEXT NULL,
				design_system LONGTEXT NULL,
				tokens LONGTEXT NULL,
				content_slots LONGTEXT NULL,
				assets LONGTEXT NULL,
				dependencies LONGTEXT NULL,
				compatibility LONGTEXT NULL,
				provenance LONGTEXT NULL,
				validation LONGTEXT NULL,
				template_count INT UNSIGNED NOT NULL DEFAULT 0,
				usage_count INT UNSIGNED NOT NULL DEFAULT 0,
				hash CHAR(64) NOT NULL DEFAULT '',
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY workspace_type (workspace_id, type),
				KEY workspace_component (workspace_id, component_id)
			)",
			/* ---------------------------------------------------------------------
			 * Phase 20: the developer platform.
			 *
			 * Six tables. The same argument as Phase 19 — each is filtered, paginated and
			 * workspace-scoped in the developer console, so none can be served from an
			 * option without loading the whole set for every view — plus one genuine
			 * security difference: `api_credentials` is the only table in the plugin that
			 * stores something derived from a secret.
			 *
			 * Index choices, stated because they are the queries that run:
			 * - `api_credentials (workspace_id, status)` — the console list, and the
			 *   live-count check behind `MAX_CREDENTIALS`.
			 * - `api_credentials (prefix)` — the lookup on every authenticated request.
			 *   Not UNIQUE: two credentials can legitimately share an 8-character hash
			 *   prefix, so uniqueness there would be a bug waiting for 2^32 credentials.
			 *   `Api_Credential_Store::authenticate()` compares with `hash_equals()` over
			 *   the handful of rows returned.
			 * - `webhooks (workspace_id, status)` — the console list, and the
			 *   "which subscriptions hear this event" read on every emission.
			 * - `webhook_deliveries (status, next_attempt_at)` — the cron's due-read.
			 *   This index is what keeps a dormant subscription's backlog from delaying a
			 *   live one.
			 * - `webhook_deliveries (webhook_id, created_at)` — a subscription's history.
			 * - `automations (workspace_id, status, trigger)` — trigger matching on every
			 *   emission, for one workspace only.
			 * - `events (workspace_id, created_at)` — the console's recent list, and the
			 *   retention trim that deletes the oldest rows for one workspace.
			 *
			 * ### What is not stored, and why that is a schema decision
			 *
			 * `api_credentials` has **no `token` column** and `webhooks` has **no `secret`
			 * column**. Those absences are the security control, not an oversight: there is
			 * no code path that could write a credential into this table, and a
			 * `SELECT *` — a backup, a support export, a phpmyadmin screenshot — cannot
			 * produce a usable secret because none was ever written.
			 *
			 * `api_credentials.token_hash` is an HMAC from `Secure_Token::salt()` keyed on
			 * the token, so it is not a password hash that can be brute-forced offline and
			 * not a value that becomes the token when the salt is known elsewhere.
			 *
			 * A webhook's signing secret is derived, never stored at all — see
			 * `Webhook_Signer`. Rotating it therefore means rotating the site salt, which
			 * rotates every subscription's secret at once. That is a coarse control, and it
			 * is the honest one available when the secret was never at rest.
			 */
			'api_credential' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL DEFAULT '',
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				name VARCHAR(191) NOT NULL DEFAULT '',
				prefix CHAR(8) NOT NULL DEFAULT '',
				token_hash CHAR(64) NOT NULL DEFAULT '',
				scopes LONGTEXT NULL,
				status VARCHAR(32) NOT NULL DEFAULT 'active',
				expires_at $now,
				last_used_at $now,
				last_used_ip CHAR(16) NOT NULL DEFAULT '',
				request_count INT UNSIGNED NOT NULL DEFAULT 0,
				revoked_at $now,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY workspace_status (workspace_id, status),
				KEY prefix (prefix),
				KEY user_id (user_id)
			)",

			'webhook' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL DEFAULT '',
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				name VARCHAR(191) NOT NULL DEFAULT '',
				endpoint TEXT NULL,
				events LONGTEXT NULL,
				status VARCHAR(32) NOT NULL DEFAULT 'active',
				failure_count INT UNSIGNED NOT NULL DEFAULT 0,
				consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
				last_delivered_at $now,
				last_failure_at $now,
				last_error TEXT NULL,
				last_signature CHAR(32) NOT NULL DEFAULT '',
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY workspace_status (workspace_id, status),
				KEY project_id (project_id)
			)",

			'webhook_delivery' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL DEFAULT '',
				webhook_id CHAR(26) NOT NULL DEFAULT '',
				event_id VARCHAR(64) NOT NULL DEFAULT '',
				event_type VARCHAR(64) NOT NULL DEFAULT '',
				event_version VARCHAR(16) NOT NULL DEFAULT '',
				signature CHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(32) NOT NULL DEFAULT 'pending',
				attempts INT UNSIGNED NOT NULL DEFAULT 0,
				next_attempt_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
				delivered_at $now,
				response_code INT NOT NULL DEFAULT 0,
				error TEXT NULL,
				dropped TINYINT(1) NOT NULL DEFAULT 0,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY status_next (status, next_attempt_at),
				KEY webhook_created (webhook_id, created_at),
				KEY event_id (event_id)
			)",

			// Metadata only. The provider object is never stored, never serialised and
			// never rehydrated: it exists because already-trusted PHP constructed it this
			// request. So even a full compromise of this table yields a list of names and
			// a set of permissions, and every permission in it is still checked against
			// `Platform_Limits::EXTENSION_PERMISSIONS` at call time.
			'extension' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				extension_id VARCHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(32) NOT NULL DEFAULT 'registered',
				capabilities LONGTEXT NULL,
				permissions LONGTEXT NULL,
				manifest LONGTEXT NULL,
				incompatibilities LONGTEXT NULL,
				failure_count INT UNSIGNED NOT NULL DEFAULT 0,
				last_error TEXT NULL,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY extension_id (extension_id),
				KEY status (status)
			)",

			// An automation is a definition, not code: a trigger and an action, both from
			// closed vocabularies. There is no column for a callable, a template, a query or
			// a URL, so there is nothing an automation could be *changed into* by editing
			// this table.
			'automation' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				workspace_id CHAR(26) NOT NULL DEFAULT '',
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				extension_id VARCHAR(64) NOT NULL DEFAULT '',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				name VARCHAR(191) NOT NULL DEFAULT '',
				trigger_event VARCHAR(64) NOT NULL DEFAULT '',
				action VARCHAR(32) NOT NULL DEFAULT '',
				options LONGTEXT NULL,
				status VARCHAR(32) NOT NULL DEFAULT 'active',
				run_count INT UNSIGNED NOT NULL DEFAULT 0,
				failure_count INT UNSIGNED NOT NULL DEFAULT 0,
				last_run_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
				last_error TEXT NULL,
				created_at $now,
				updated_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY workspace_trigger (workspace_id, status, trigger_event),
				KEY workspace_status (workspace_id, status)
			)",

			// `ancestry` and `depth` are the columns that make loop prevention enforceable.
			// `depth` is an INT so the check is a comparison rather than a count of an
			// array, and `correlation_id` is indexed because it is what a developer pastes
			// from a failed webhook delivery to find the request that caused it.
			'event' => "CREATE TABLE %s (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				event_id VARCHAR(64) NOT NULL DEFAULT '',
				workspace_id CHAR(26) NOT NULL DEFAULT '',
				project_id VARCHAR(64) NOT NULL DEFAULT '',
				resource_id VARCHAR(64) NOT NULL DEFAULT '',
				event_type VARCHAR(64) NOT NULL DEFAULT '',
				version VARCHAR(16) NOT NULL DEFAULT '',
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				correlation_id VARCHAR(64) NOT NULL DEFAULT '',
				ancestry LONGTEXT NULL,
				depth INT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(32) NOT NULL DEFAULT 'recorded',
				data LONGTEXT NULL,
				created_at $now,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY workspace_created (workspace_id, created_at),
				KEY event_id (event_id),
				KEY correlation_id (correlation_id),
				KEY workspace_type (workspace_id, event_type),
				KEY status (status)
			)",
		);
	}
}
