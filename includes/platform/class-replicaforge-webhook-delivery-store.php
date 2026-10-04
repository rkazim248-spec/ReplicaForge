<?php
/**
 * Phase 20: webhook delivery records.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Read and write access to `replicaforge_webhook_deliveries`.
 *
 * ### This is a delivery *log*, not a queue
 *
 * The distinction matters, because "do not create a second queue" is a hard constraint and
 * this class could easily have been one.
 *
 * It is not, for three reasons:
 *
 * 1. **It exists because the brief requires delivery records.** §15 asks for failure
 *    tracking, §23 asks for a delivery view and manual retry, §43 lists "webhook delivery
 *    records" as an entity. Those are audit rows, not work items.
 * 2. **It does not schedule.** A delivery becomes due because of its own
 *    `next_attempt_at` timestamp, and {@see Webhook_Delivery} — the cron worker — decides
 *    what to claim. There is no queue API, no claim/acknowledge pair, no stage machine and
 *    no idempotency-key resolution, because none of those exist to be reused: the job queue's
 *    stage machine is a *replica pipeline* (`Job_Runner::dispatch()` switches on
 *    `Job_Limits::STAGES` and force-advances anything unrecognised into `analyze`), and
 *    putting a webhook delivery on it would drive it into the reconstruction pipeline.
 * 3. **It reuses the surrounding infrastructure rather than reimplementing it.** Mutual
 *    exclusion is `Job_Lock`. Backoff is `Job_Limits::backoff_seconds()`. The tick is the
 *    plugin's existing `replicaforge_process_jobs` cron. Storage is the Phase 15 table
 *    layer. What is new here is a *record shape*, not a scheduler.
 *
 * So: the existing queue could not carry these jobs without being modified in a way that
 * would couple webhook delivery to reconstruction, and building a queue-shaped API on top of
 * an audit table would have been a second queue wearing a log's clothes. A delivery log plus
 * a cron worker is the honest third option.
 *
 * ### Pending deliveries are bounded per webhook
 *
 * A webhook whose endpoint is down must not accumulate rows without limit. At
 * {@see Platform_Limits::MAX_PENDING_DELIVERIES} the oldest pending rows for that
 * subscription are dropped, and the drop is counted, so the console can say "12 events were
 * dropped while this endpoint was unreachable" instead of showing a queue that quietly got
 * shorter.
 */
class Webhook_Delivery_Store extends Collaboration_Store {

	/**
	 * Entity kind, matching `Workspace_Limits::table()`.
	 *
	 * @var string
	 */
	protected $kind = 'webhook_delivery';

	/**
	 * Writable columns.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id',
			'workspace_id',
			'webhook_id',
			'event_id',
			'event_type',
			'event_version',
			'signature',
			'status',
			'attempts',
			'next_attempt_at',
			'delivered_at',
			'response_code',
			'error',
			'dropped',
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
			'workspace_id'    => 'line',
			'webhook_id'      => 'line',
			'event_id'        => 'line',
			'event_type'      => 'line',
			'event_version'   => 'line',
			'signature'       => 'line',
			'status'          => 'line',
			'attempts'        => 'int',
			'next_attempt_at' => 'int',
			'delivered_at'    => 'datetime',
			'response_code'   => 'int',
			'error'           => 'text',
			'dropped'         => 'int',
			'created_at'      => 'datetime',
			'updated_at'      => 'datetime',
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

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Record a pending delivery.
	 *
	 * @param string              $webhook_id Webhook public id.
	 * @param array<string, mixed> $event      The event record.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function enqueue( $webhook_id, array $event ) {
		$webhook_id = $this->clean_id( $webhook_id );

		if ( '' === $webhook_id || ! $this->ready() ) {
			return new \WP_Error( 'delivery_not_stored', __( 'The delivery could not be recorded.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		$payload = $this->payload_for( $event );

		if ( '' === $payload ) {
			return new \WP_Error(
				'delivery_unencodable',
				__( 'This event could not be encoded for delivery.', 'replicaforge' ),
				array( 'status' => 500 )
			);
		}

		$row = array(
			'public_id'      => $this->new_public_id(),
			'workspace_id'   => (string) ( $event['workspace_id'] ?? '' ),
			'webhook_id'     => $webhook_id,
			'event_id'       => (string) ( $event['event_id'] ?? '' ),
			'event_type'     => (string) ( $event['event_type'] ?? '' ),
			'event_version'  => Platform_Limits::EVENT_SCHEMA_VERSION,
			'signature'      => hash( 'sha256', $payload ),
			'status'         => 'pending',
			'attempts'       => 0,
			'next_attempt_at' => time(),
			'dropped'        => 0,
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
		);

		$stored = $this->insert( $row );

		if ( null === $stored ) {
			return new \WP_Error( 'delivery_not_stored', __( 'The delivery could not be recorded.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		$stored['payload'] = $payload;

		$this->bound_pending( $webhook_id );

		return $stored;
	}

	/**
	 * Return the exact payload for an event.
	 *
	 * Re-derived from the stored event fields rather than kept in a column, because a payload
	 * column would be a second copy of the event that could disagree with it. The fields
	 * this needs are all on the delivery row.
	 *
	 * @param array<string, mixed> $event Event record.
	 * @return string
	 */
	public function payload_for( array $event ) {
		$payload = array(
			'schema_version' => Platform_Limits::PAYLOAD_SCHEMA_VERSION,
			'event_id'       => (string) ( $event['event_id'] ?? '' ),
			'event_type'     => (string) ( $event['event_type'] ?? '' ),
			'occurred_at'    => (string) ( $event['created_at'] ?? gmdate( 'c' ) ),
			'workspace_id'   => (string) ( $event['workspace_id'] ?? '' ),
			'project_id'     => (string) ( $event['project_id'] ?? '' ),
			'resource_id'    => (string) ( $event['resource_id'] ?? '' ),
			'actor_id'       => (int) ( $event['actor_id'] ?? 0 ),
			'correlation_id' => (string) ( $event['correlation_id'] ?? '' ),
			/*
			 * `depth` and `ancestry` travel with the payload so a receiver can see how far
			 * down an automation chain it is, without ReplicaForge having to explain the
			 * shape separately.
			 */
			'depth'          => (int) ( $event['depth'] ?? 0 ),
			'ancestry'       => array_slice( (array) ( $event['ancestry'] ?? array() ), 0, 8 ),
			'data'           => Data_Redactor::structure( (array) ( $event['data'] ?? array() ) ),
		);

		$encoded = wp_json_encode( $payload );

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Record a delivery outcome.
	 *
	 * @param string              $delivery_id Delivery public id.
	 * @param array<string, mixed> $changes     Changes.
	 * @return bool
	 */
	public function record( $delivery_id, array $changes ) {
		$delivery_id = $this->clean_id( $delivery_id );

		if ( '' === $delivery_id || ! $this->ready() ) {
			return false;
		}

		$this->update_row( $delivery_id, $changes );

		return true;
	}

	/**
	 * Drop the oldest pending deliveries for a webhook, past the ceiling.
	 *
	 * @param string $webhook_id Webhook public id.
	 * @return int Rows dropped.
	 */
	public function bound_pending( $webhook_id ) {
		$webhook_id = $this->clean_id( $webhook_id );

		if ( '' === $webhook_id || ! $this->ready() ) {
			return 0;
		}

		global $wpdb;

		$table = $this->table();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				"SELECT COUNT(*) FROM {$table} WHERE webhook_id = %s AND status = 'pending'",
				$webhook_id
			)
		);

		$overflow = $count - Platform_Limits::MAX_PENDING_DELIVERIES;

		if ( $overflow <= 0 ) {
			return 0;
		}

		/*
		 * The oldest pending rows go, and they go as `dead` rather than being deleted —
		 * "dropped" is an outcome an operator needs to be able to see. A webhook that has
		 * missed forty events says so, rather than appearing to have never received them.
		 */
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				"SELECT public_id FROM {$table} WHERE webhook_id = %s AND status = 'pending' ORDER BY created_at ASC LIMIT %d",
				$webhook_id,
				$overflow
			)
		);

		$dropped = 0;

		foreach ( (array) $rows as $row ) {
			if ( $this->update_row( (string) $row, array( 'status' => 'dead', 'error' => 'dropped_over_pending_limit', 'dropped' => 1 ) ) ) {
				$dropped++;
			}
		}

		return $dropped;
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return the deliveries that are due.
	 *
	 * @param int    $limit Maximum rows.
	 * @param string $status Status to claim, or empty for any non-terminal.
	 * @return array<int, array<string, mixed>>
	 */
	public function due( $limit = 10, $status = '' ) {
		global $wpdb;

		if ( ! $this->ready() ) {
			return array();
		}

		$table = $this->table();
		$limit = max( 1, min( Platform_Limits::WEBHOOK_BATCH, (int) $limit ) );
		$now   = time();

		/*
		 * Two statuses, because a delivery that was in flight when the process died looks
		 * identical to one that was never started. Both are retried, and the attempts counter
		 * is what stops either retrying forever.
		 */
		$statuses = Platform_Limits::is_delivery_state( $status ) ? array( (string) $status ) : array( 'pending', 'failed', 'delivering' );

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				"SELECT * FROM {$table} WHERE status IN ( {$placeholders} ) AND ( next_attempt_at = 0 OR next_attempt_at <= %d ) ORDER BY created_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( $statuses, array( $now, $limit ) )
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
	 * List a webhook's deliveries, newest first.
	 *
	 * @param string $webhook_id Webhook public id.
	 * @param int    $limit      Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function history( $webhook_id, $limit = 25 ) {
		global $wpdb;

		$webhook_id = $this->clean_id( $webhook_id );

		if ( '' === $webhook_id || ! $this->ready() ) {
			return array();
		}

		$table = $this->table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				"SELECT * FROM {$table} WHERE webhook_id = %s ORDER BY created_at DESC LIMIT %d",
				$webhook_id,
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
	 * Return a delivery record by its public id.
	 *
	 * Cross-workspace on purpose: a delivery id is referenced from the cron worker, which has
	 * no workspace context, and from a retry request that has already been checked against
	 * the subscription's own workspace by the caller.
	 *
	 * @param string $delivery_id Delivery public id.
	 * @return array<string, mixed>|null
	 */
	public function read( $delivery_id ) {
		$delivery_id = $this->clean_id( $delivery_id );

		if ( '' === $delivery_id || ! $this->ready() ) {
			return null;
		}

		/*
		 * `find( '*', … )`, not `read_by_id()`.
		 *
		 * `read_by_id()` looks a row up by its **sequential** `id` column. Passing a public id
		 * there silently casts a 26-character alphanumeric string to 0, so the lookup would
		 * return null for every valid delivery — which reads as "this delivery does not
		 * exist" rather than as the type error it is, and would make manual retry report 404
		 * for a delivery the console had just listed.
		 */
		return $this->find( '*', $delivery_id );
	}

	/**
	 * Return a workspace's delivery counts by state.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, int>
	 */
	public function counts( $workspace_id ) {
		$workspace_id = is_string( $workspace_id ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $workspace_id ), 0, 26 ) : '';

		if ( '' === $workspace_id || ! $this->ready() ) {
			return array();
		}

		return $this->group_counts( $workspace_id, 'status' );
	}

	/**
	 * Remove every delivery for a webhook.
	 *
	 * @param string $webhook_id Webhook public id.
	 * @return int
	 */
	public function purge_webhook( $webhook_id ) {
		$webhook_id = $this->clean_id( $webhook_id );

		if ( '' === $webhook_id || ! $this->ready() ) {
			return 0;
		}

		return (int) $this->delete_rows( array( 'webhook_id' => $webhook_id ) );
	}

	/**
	 * Remove terminal deliveries older than the retention window.
	 *
	 * Called from the daily maintenance tick, so a webhook that has run for a year does not
	 * accumulate a year of successful deliveries.
	 *
	 * @param int $days Retention in days.
	 * @return int Rows removed.
	 */
	public function prune( $days = 0 ) {
		global $wpdb;

		if ( ! $this->ready() ) {
			return 0;
		}

		$days  = $days > 0 ? (int) $days : Platform_Limits::DELIVERY_RETENTION_DAYS;
		$table = $this->table();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				"DELETE FROM {$table} WHERE status IN ('delivered','dead','skipped') AND updated_at < %s",
				$since
			)
		);
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}
}
