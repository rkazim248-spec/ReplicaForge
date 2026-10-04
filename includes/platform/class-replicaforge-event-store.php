<?php
/**
 * Phase 20: the event store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Read and write access to `replicaforge_events`.
 *
 * ### Why a table rather than an option
 *
 * `Audit_Log` stores 500 entries in one option, which is the right shape for "what did this
 * person just do". Events are a different question: *what happened across this workspace*,
 * filtered by type, project and time, for a console with a date range. That is a query, not
 * a scan, and `MAX_RECENT_EVENTS` is a per-workspace trim rather than a global ring — a
 * global ring would silently drop a quiet workspace's history because a busy one filled up.
 *
 * ### The correlation id and the ancestry are the interesting columns
 *
 * `correlation_id` is the thread an event belongs to. `ancestry` is the list of *automations*
 * that have already fired on this thread.
 *
 * Together they are what makes {@see Platform_Limits::AUTOMATION_MAX_DEPTH} enforceable. A
 * cycle is `workflow.completed → automation → start_workflow → workflow.completed`, and the
 * ancestry is what distinguishes that from a legitimate second pass: the second
 * `workflow.completed` arrives carrying an ancestry that already contains the automation's
 * own id.
 *
 * ### What is never stored
 *
 * The `data` column is reduced through `Data_Redactor::structure()` by `coerce()`, so an
 * event cannot carry an object graph, and a value too large to reduce becomes a description
 * of itself rather than a payload. The table has no column for a secret, and the emitter
 * refuses to put one there — see `Event_Dispatcher::emit()`.
 */
class Event_Store extends Collaboration_Store {

	/**
	 * Entity kind, matching `Workspace_Limits::table()`.
	 *
	 * @var string
	 */
	protected $kind = 'event';

	/**
	 * Writable columns.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id',
			'event_id',
			'workspace_id',
			'project_id',
			'resource_id',
			'event_type',
			'version',
			'actor_id',
			'correlation_id',
			'ancestry',
			'depth',
			'status',
			'data',
			'created_at',
		);
	}

	/**
	 * Column types.
	 *
	 * `ancestry` is json and bounded to a handful of ids; `depth` is an int so the loop guard
	 * is a comparison rather than a count of an array.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'event_id'       => 'line',
			'workspace_id'   => 'line',
			'project_id'     => 'line',
			'resource_id'    => 'line',
			'event_type'     => 'line',
			'version'        => 'line',
			'actor_id'       => 'int',
			'correlation_id' => 'line',
			'ancestry'       => 'json',
			'depth'          => 'int',
			'status'         => 'line',
			'data'           => 'json',
			'created_at'     => 'datetime',
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array();
	}

	/**
	 * @return array<int, string>
	 */
	protected function groupable_columns() {
		return array( 'status', 'event_type' );
	}

	/**
	 * Record an event.
	 *
	 * @param array<string, mixed> $event Event record.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function record( array $event ) {
		if ( ! $this->ready() ) {
			return new \WP_Error( 'event_table_missing', __( 'The event tables are not installed. Run the database migration.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		$type = (string) ( $event['event_type'] ?? '' );

		if ( ! Platform_Limits::is_event( $type ) ) {
			return new \WP_Error(
				'event_type_unknown',
				__( 'That event type does not exist, so nothing was recorded.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$workspace_id = $this->clean_id( $event['workspace_id'] ?? '' );
		$ancestry     = array_slice( array_values( array_filter( array_map( 'strval', (array) ( $event['ancestry'] ?? array() ) ) ) ), 0, 8 );

		$row = array(
			'public_id'      => $this->new_public_id(),
			'event_id'       => (string) ( $event['event_id'] ?? '' ),
			'workspace_id'   => $workspace_id,
			'project_id'     => $this->clean_project( $event['project_id'] ?? '' ),
			'resource_id'    => substr( (string) ( $event['resource_id'] ?? '' ), 0, 64 ),
			'event_type'     => $type,
			'version'        => Platform_Limits::EVENT_SCHEMA_VERSION,
			'actor_id'       => max( 0, (int) ( $event['actor_id'] ?? 0 ) ),
			'correlation_id' => substr( (string) ( $event['correlation_id'] ?? '' ), 0, 64 ),
			'ancestry'       => $ancestry,
			'depth'          => max( 0, (int) ( $event['depth'] ?? 0 ) ),
			'status'         => (string) ( $event['status'] ?? 'recorded' ),
			'data'           => is_array( $event['data'] ?? null ) ? $event['data'] : array(),
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
		);

		$stored = $this->insert( $row );

		if ( null === $stored ) {
			return new \WP_Error( 'event_not_stored', __( 'The event could not be recorded.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		$this->bound( $workspace_id );

		return $stored;
	}

	/**
	 * Read an event by its event id.
	 *
	 * @param string $event_id Event id.
	 * @return array<string, mixed>|null
	 */
	public function read( $event_id ) {
		/*
		 * The sanitising pattern keeps underscores.
		 *
		 * This was `'/[^A-Za-z0-9]/'`, which stripped them — and every event id ReplicaForge
		 * issues *contains* one, because `Request_Context::make_id( 'evt', 10 )` produces
		 * `evt_640bd7750dd…`. The lookup therefore searched for `evt640bd7750dd…` while the row
		 * stored `evt_640bd7750dd…`, so `read()` returned null for every event the platform
		 * had recorded.
		 *
		 * The failure was silent and consequential: `Webhook_Delivery::event_for()` calls this
		 * on every delivery, and its null branch substitutes a placeholder payload — so a
		 * webhook would have received "the full event is no longer retained" for an event
		 * recorded a moment earlier, while the delivery log showed a perfectly valid event id
		 * that could not be looked up.
		 */
		$event_id = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $event_id ), 0, 64 );

		if ( '' === $event_id || ! $this->ready() ) {
			return null;
		}

		return $this->find_where( '*', array( 'event_id' => $event_id ) );
	}

	/**
	 * List events.
	 *
	 * @param string              $workspace_id Workspace id.
	 * @param array<string, mixed> $args         Filters: `type`, `project_id`, `status`,
	 *                                            `per_page`, `page`, `before`.
	 * @return array<string, mixed>
	 */
	public function browse( $workspace_id, array $args = array() ) {
		$workspace_id = $this->clean_id( $workspace_id );

		if ( '' === $workspace_id || ! $this->ready() ) {
			return $this->empty_page();
		}

		$clean = array( 'per_page' => isset( $args['per_page'] ) ? (int) $args['per_page'] : Workspace_Limits::page_size( 0 ) );

		if ( isset( $args['type'] ) && Platform_Limits::is_event( $args['type'] ) ) {
			$clean['type'] = (string) $args['type'];
		}

		if ( isset( $args['status'] ) && '' !== (string) $args['status'] ) {
			$clean['status'] = substr( (string) $args['status'], 0, 32 );
		}

		if ( isset( $args['project_id'] ) && '' !== (string) $args['project_id'] ) {
			$clean['project_id'] = (string) $args['project_id'];
		}

		if ( isset( $args['before'] ) && is_array( $args['before'] ) ) {
			$clean['before'] = $args['before'];
		}

		return $this->query( $workspace_id, $clean );
	}

	/**
	 * Return event counts by type for a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, int>
	 */
	public function counts( $workspace_id ) {
		$workspace_id = $this->clean_id( $workspace_id );

		if ( '' === $workspace_id || ! $this->ready() ) {
			return array();
		}

		return $this->group_counts( $workspace_id, 'event_type' );
	}

	/**
	 * Trim a workspace's history to the retention ceiling.
	 *
	 * Per workspace, and the *oldest* go. An event is a record rather than content, so the
	 * loss is bounded and self-describing.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return int Rows removed.
	 */
	public function bound( $workspace_id ) {
		$workspace_id = $this->clean_id( $workspace_id );

		if ( '' === $workspace_id || ! $this->ready() ) {
			return 0;
		}

		global $wpdb;

		$table = $this->table();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				"SELECT COUNT(*) FROM {$table} WHERE workspace_id = %s",
				$workspace_id
			)
		);

		$overflow = $count - Platform_Limits::MAX_RECENT_EVENTS;

		if ( $overflow <= 0 ) {
			return 0;
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				"SELECT public_id FROM {$table} WHERE workspace_id = %s ORDER BY created_at ASC, id ASC LIMIT %d",
				$workspace_id,
				$overflow
			)
		);

		$removed = 0;

		foreach ( (array) $rows as $row ) {
			$this->delete_rows( array( 'public_id' => (string) $row ) );
			$removed++;
		}

		return $removed;
	}

	/**
	 * Remove a workspace's events.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return int
	 */
	public function forget_workspace( $workspace_id ) {
		$workspace_id = $this->clean_id( $workspace_id );

		if ( '' === $workspace_id || ! $this->ready() ) {
			return 0;
		}

		return (int) $this->delete_rows( array( 'workspace_id' => $workspace_id ) );
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_project( $value ) {
		return is_scalar( $value ) ? substr( (string) $value, 0, 64 ) : '';
	}
}
