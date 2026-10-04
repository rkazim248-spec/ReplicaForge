<?php
/**
 * Phase 20: webhook subscriptions.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Read and write access to `replicaforge_webhooks`.
 *
 * ### The signing secret is hashed, not stored
 *
 * This is the decision the whole webhook design turns on. A signing secret has to be
 * *recoverable* by the sender — every delivery re-signs with it — so it cannot simply be
 * hashed like a credential.
 *
 * The resolution is that **it is never stored at all.** {@see Webhook_Signer} derives the
 * signing secret from a site-wide secret plus the subscription's public id, so the secret is
 * a pure function of two things ReplicaForge already has. The table holds no secret column,
 * which means:
 *
 * - a database dump reveals no signing secret;
 * - there is no secret to leak in the console, in an API response, in a log line, or in a
 *   support screenshot;
 * - rotating one means rotating the site secret, which rotates every subscription's secret
 *   at once — a coarse control, but an honest one, and the only one available when the
 *   secret was never at rest.
 *
 * The receiver still sees a per-subscription secret in its own configuration, because that
 * is what lets two subscriptions from one site be told apart by a receiver verifying
 * signatures.
 *
 * ### What the endpoint column does and does not guarantee
 *
 * An endpoint passes the same SSRF boundary as everything else in the plugin
 * ({@see Security::is_safe_public_reference()}) at registration *and* at every delivery,
 * because DNS can change between the two. A host that resolved publicly at registration and
 * privately later is a real attack, and re-checking at send time is the only defence that
 * catches it.
 */
class Webhook_Store extends Collaboration_Store {

	/**
	 * Entity kind, matching `Workspace_Limits::table()`.
	 *
	 * @var string
	 */
	protected $kind = 'webhook';

	/**
	 * Writable columns.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id',
			'workspace_id',
			'project_id',
			'user_id',
			'name',
			'endpoint',
			'events',
			'status',
			'secret_hash',
			'failure_count',
			'last_delivered_at',
			'last_failure_at',
			'last_error',
			'last_signature',
			'consecutive_failures',
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
			'user_id'           => 'int',
			'name'              => 'line',
			'endpoint'          => 'text',
			'events'            => 'json',
			'status'            => 'line',
			'secret_hash'       => 'line',
			'failure_count'     => 'int',
			'consecutive_failures' => 'int',
			'last_delivered_at' => 'datetime',
			'last_failure_at'   => 'datetime',
			'last_error'        => 'text',
			'last_signature'    => 'line',
			'created_at'        => 'datetime',
			'updated_at'        => 'datetime',
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'name', 'endpoint' );
	}

	/**
	 * @return array<int, string>
	 */
	protected function groupable_columns() {
		return array( 'status' );
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Create a subscription.
	 *
	 * @param string              $workspace_id Workspace public id.
	 * @param array<string, mixed> $input        `name`, `endpoint`, `events`, `user_id`,
	 *                                            `project_id`.
	 * @return array<string, mixed>|\WP_Error The record, plus `secret` exactly once.
	 */
	public function create( $workspace_id, array $input ) {
		$workspace_id = $this->clean_workspace( $workspace_id );

		if ( '' === $workspace_id ) {
			return new \WP_Error( 'webhook_workspace_required', __( 'A webhook must belong to a workspace.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		if ( ! $this->ready() ) {
			return new \WP_Error( 'webhook_table_missing', __( 'The webhook tables are not installed. Run the database migration.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		$name = isset( $input['name'] ) && is_string( $input['name'] ) ? trim( sanitize_text_field( $input['name'] ) ) : '';

		if ( '' === $name || strlen( $name ) > 120 ) {
			return new \WP_Error( 'webhook_name_required', __( 'A webhook needs a name.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		$endpoint = $this->clean_endpoint( isset( $input['endpoint'] ) ? $input['endpoint'] : '' );

		if ( is_wp_error( $endpoint ) ) {
			return $endpoint;
		}

		$events = $this->clean_events( isset( $input['events'] ) ? $input['events'] : array() );

		if ( array() === $events ) {
			return new \WP_Error(
				'webhook_event_required',
				__( 'A webhook needs at least one event to subscribe to.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		if ( (int) $this->count_where( $workspace_id, array() ) >= Platform_Limits::MAX_WEBHOOKS ) {
			return new \WP_Error(
				'webhook_limit_reached',
				sprintf(
					/* translators: %d: the maximum number of webhooks. */
					__( 'This workspace already has %d webhooks, which is the limit.', 'replicaforge' ),
					Platform_Limits::MAX_WEBHOOKS
				),
				array( 'status' => 409 )
			);
		}

		$public_id = $this->new_public_id();

		$row = array(
			'public_id'    => $public_id,
			'workspace_id' => $workspace_id,
			'project_id'   => isset( $input['project_id'] ) && is_scalar( $input['project_id'] ) ? substr( (string) $input['project_id'], 0, 64 ) : '',
			'user_id'      => (int) ( $input['user_id'] ?? get_current_user_id() ),
			'name'         => substr( $name, 0, 120 ),
			'endpoint'     => $endpoint,
			'events'       => $events,
			'status'       => 'active',
			'failure_count' => 0,
			'consecutive_failures' => 0,
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		);

		$stored = $this->insert( $row );

		if ( null === $stored ) {
			return new \WP_Error( 'webhook_not_stored', __( 'The webhook could not be saved.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		( new Collaboration_Log() )->audit(
			$workspace_id,
			'webhook_created',
			array(
				'target_type' => 'webhook',
				'target_id'   => $public_id,
				/* The endpoint host, not the full URL. A webhook endpoint can carry a token
				 * in its query string — some providers require one — and an audit trail is
				 * the last place that should be copied. The host is enough to answer "which
				 * service is this pointed at". */
				'metadata'    => array(
					'host'   => (string) wp_parse_url( $endpoint, PHP_URL_HOST ),
					'events' => $events,
				),
			),
			(int) $row['user_id']
		);

		/*
		 * The derived secret, shown once. It is not stored, so this is the only time it can
		 * be retrieved — see the class docblock for why that is the right trade rather than
		 * a limitation.
		 */
		$stored['secret'] = Webhook_Signer::secret_for( $public_id );

		return $stored;
	}

	/**
	 * Update a subscription.
	 *
	 * @param string              $webhook_id Webhook public id.
	 * @param array<string, mixed> $changes    Changes.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( $webhook_id, array $changes ) {
		$webhook_id = $this->clean_public_id( $webhook_id );

		if ( '' === $webhook_id || ! $this->ready() ) {
			return new \WP_Error( 'webhook_not_found', __( 'That webhook does not exist.', 'replicaforge' ), array( 'status' => 404 ) );
		}

		$row = array();

		if ( array_key_exists( 'name', $changes ) ) {
			$name = is_string( $changes['name'] ) ? trim( sanitize_text_field( $changes['name'] ) ) : '';

			if ( '' === $name || strlen( $name ) > 120 ) {
				return new \WP_Error( 'webhook_name_required', __( 'A webhook needs a name.', 'replicaforge' ), array( 'status' => 400 ) );
			}

			$row['name'] = substr( $name, 0, 120 );
		}

		if ( array_key_exists( 'endpoint', $changes ) ) {
			$endpoint = $this->clean_endpoint( $changes['endpoint'] );

			if ( is_wp_error( $endpoint ) ) {
				return $endpoint;
			}

			$row['endpoint'] = $endpoint;
		}

		if ( array_key_exists( 'events', $changes ) ) {
			$events = $this->clean_events( $changes['events'] );

			if ( array() === $events ) {
				return new \WP_Error( 'webhook_event_required', __( 'A webhook needs at least one event.', 'replicaforge' ), array( 'status' => 400 ) );
			}

			$row['events'] = $events;
		}

		if ( array_key_exists( 'status', $changes ) && Platform_Limits::is_webhook_state( $changes['status'] ) ) {
			$row['status'] = (string) $changes['status'];
		}

		if ( array() === $row ) {
			return $this->read( '*', $webhook_id );
		}

		$this->update_row( $webhook_id, $row );

		return $this->read( '*', $webhook_id );
	}

	/**
	 * Record delivery outcomes on the subscription.
	 *
	 * @param string $webhook_id Webhook public id.
	 * @param bool   $delivered  Whether it succeeded.
	 * @param string $error      Failure reason, or a signature when delivered.
	 * @return bool
	 */
	public function record_outcome( $webhook_id, $delivered, $error = '' ) {
		$webhook_id = $this->clean_public_id( $webhook_id );

		if ( '' === $webhook_id || ! $this->ready() ) {
			return false;
		}

		$record = $this->read( '*', $webhook_id );

		if ( null === $record ) {
			return false;
		}

		$now      = gmdate( 'Y-m-d H:i:s' );
		$failures = $delivered ? 0 : (int) ( $record['consecutive_failures'] ?? 0 ) + 1;

		$changes = array(
			'failure_count'       => (int) ( $record['failure_count'] ?? 0 ) + 1,
			'consecutive_failures' => $failures,
		);

		if ( $delivered ) {
			$changes['last_delivered_at'] = $now;
			$changes['last_signature']    = substr( (string) $error, 0, 16 );
			$changes['last_error']        = '';

			/* A subscription that recovered returns to active, because leaving it in `failing`
			 * after one success would make the console a bad guide: an operator would disable
			 * a webhook that is plainly working. */
			if ( 'failing' === (string) $record['status'] ) {
				$changes['status'] = 'active';
			}
		} else {
			$changes['last_failure_at'] = $now;
			$changes['last_error']      = substr( (string) $error, 0, 300 );

			if ( $failures >= Platform_Limits::WEBHOOK_MAX_FAILURES ) {
				$changes['status'] = 'failing';
			}
		}

		$this->update_row( $webhook_id, $changes );

		return true;
	}

	/**
	 * Remove a subscription and its pending deliveries.
	 *
	 * @param string $webhook_id Webhook public id.
	 * @return bool
	 */
	public function forget( $webhook_id ) {
		$webhook_id = $this->clean_public_id( $webhook_id );

		if ( '' === $webhook_id || ! $this->ready() ) {
			return false;
		}

		( new Webhook_Delivery_Store() )->purge_webhook( $webhook_id );

		return (bool) $this->delete_rows( array( 'public_id' => $webhook_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return one subscription.
	 *
	 * @param string $workspace_id Workspace id, or `'*'` for any.
	 * @param string $webhook_id   Webhook public id.
	 * @return array<string, mixed>|null
	 */
	public function read( $workspace_id, $webhook_id ) {
		$webhook_id = $this->clean_public_id( $webhook_id );

		if ( '' === $webhook_id || ! $this->ready() ) {
			return null;
		}

		if ( '' === (string) $workspace_id || '*' === (string) $workspace_id ) {
			global $wpdb;

			$table = $this->table();
			$row   = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
					"SELECT * FROM {$table} WHERE public_id = %s LIMIT 1",
					$webhook_id
				),
				ARRAY_A
			);

			return is_array( $row ) ? $this->cast( $row ) : null;
		}

		return $this->find( $this->clean_workspace( $workspace_id ), $webhook_id );
	}

	/**
	 * List subscriptions.
	 *
	 * @param string              $workspace_id Workspace id, or `'*'` for any.
	 * @param array<string, mixed> $args         Filters.
	 * @return array<string, mixed>
	 */
	public function browse( $workspace_id, array $args = array() ) {
		if ( ! $this->ready() ) {
			return $this->empty_page();
		}

		$clean = array( 'per_page' => isset( $args['per_page'] ) ? (int) $args['per_page'] : Workspace_Limits::page_size( 0 ) );

		if ( isset( $args['status'] ) && Platform_Limits::is_webhook_state( $args['status'] ) ) {
			$clean['status'] = (string) $args['status'];
		}

		if ( isset( $args['project_id'] ) && '' !== (string) $args['project_id'] ) {
			$clean['project_id'] = (string) $args['project_id'];
		}

		if ( isset( $args['search'] ) ) {
			$search = sanitize_text_field( (string) $args['search'] );
			if ( '' !== $search ) {
				$clean['search'] = $search;
			}
		}

		$workspace_id = $this->clean_workspace( $workspace_id );

		return $this->query( '' !== $workspace_id ? $workspace_id : '*', $clean );
	}

	/**
	 * Return every active subscription listening for an event.
	 *
	 * Called on every emission, so it is one indexed read per workspace the event names
	 * rather than a scan of every subscription on the site.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $event        Event type.
	 * @return array<int, array<string, mixed>>
	 */
	public function listening( $workspace_id, $event, $project_id = '' ) {
		$workspace_id = $this->clean_workspace( $workspace_id );
		$project_id   = is_scalar( $project_id ) ? substr( (string) $project_id, 0, 64 ) : '';

		if ( '' === $workspace_id || ! Platform_Limits::is_event( $event ) || ! $this->ready() ) {
			return array();
		}

		$page = $this->query( $workspace_id, array( 'status' => 'active', 'per_page' => Workspace_Limits::page_size( Platform_Limits::MAX_WEBHOOKS ) ) );
		$out  = array();

		foreach ( $page['items'] as $record ) {
			if ( ! in_array( (string) $event, (array) ( $record['events'] ?? array() ), true ) ) {
				continue;
			}

			/*
			 * A project-scoped subscription only hears about its own project. Without this a
			 * subscription added while watching one client project would receive every other
			 * project's events in the workspace, which is the single most likely way for this
			 * feature to leak something — and it would leak silently, because the
			 * subscription still looks correct in the console.
			 */
			$scoped = (string) ( $record['project_id'] ?? '' );

			if ( '' !== $scoped && $scoped !== $project_id ) {
				continue;
			}

			$out[] = $record;
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------ */

	/**
	 * Validate an endpoint.
	 *
	 * HTTPS is required. §15 asks for HTTPS validation, and the reason it is a hard
	 * requirement rather than a warning is that a signing secret sent over plain HTTP is not
	 * a secret: it is a string the receiver already published.
	 *
	 * @param mixed $value Candidate.
	 * @return string|\WP_Error
	 */
	private function clean_endpoint( $value ) {
		$url = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $url ) {
			return new \WP_Error( 'webhook_endpoint_required', __( 'A webhook needs an endpoint address.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		if ( strlen( $url ) > 2048 ) {
			return new \WP_Error( 'webhook_endpoint_too_long', __( 'That endpoint address is too long.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		/* The SSRF boundary. A webhook endpoint is an address ReplicaForge will fetch on a
		 * schedule, so an internal target has to be refused at registration — and again at
		 * delivery, because DNS can change in between. */
		if ( ! Security::is_safe_public_reference( $url ) ) {
			return new \WP_Error(
				'webhook_endpoint_unsafe',
				__( 'That endpoint is not a public web address. An internal or non-web address cannot receive webhook deliveries.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$normalised = (string) Security::normalize_http_url( $url );

		if ( 'https' !== strtolower( (string) wp_parse_url( $normalised, PHP_URL_SCHEME ) ) ) {
			return new \WP_Error(
				'webhook_endpoint_not_https',
				__( 'A webhook endpoint must use HTTPS. The signing secret is sent with every delivery, and over plain HTTP it would arrive in readable form.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		return $normalised;
	}

	/**
	 * Validate a subscription's event list.
	 *
	 * Only *public* events can be subscribed to. A non-public event exists for the console
	 * and for automations inside the workspace; delivering it to a third party would put
	 * internal failure detail on the open internet, so the option is not offered rather than
	 * offered and refused later.
	 *
	 * @param mixed $value Candidate.
	 * @return array<int, string>
	 */
	private function clean_events( $value ) {
		$value = is_array( $value ) ? $value : array( $value );
		$out   = array();

		foreach ( $value as $event ) {
			$event = is_string( $event ) ? trim( $event ) : '';

			if ( ! Platform_Limits::is_event( $event ) || ! Platform_Limits::is_public_event( $event ) ) {
				continue;
			}

			if ( ! in_array( $event, $out, true ) ) {
				$out[] = $event;
			}
		}

		sort( $out );

		return $out;
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_workspace( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_public_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}
}
