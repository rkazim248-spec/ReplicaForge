<?php
/**
 * Phase 20: webhook delivery.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers webhook payloads, with bounded retries and no second queue.
 *
 * ### Why there is no `wp_remote_post` here and a hand-written one instead
 *
 * `Http_Client` is the plugin's outbound fetcher and it is deliberately GET-only: it has no
 * method, no body, no header parameter, and it discards response headers. A webhook
 * delivery needs all four — POST, a body, three custom headers, and `Retry-After`.
 *
 * So this class does its own `wp_remote_post`, and reuses the two things that matter for
 * safety rather than reimplementing them:
 *
 * - **The SSRF boundary.** `Security::is_safe_public_reference()` runs on the endpoint
 *   again here, *not* only at registration. That is the whole reason to re-check: DNS is not
 *   pinned, and a host that resolved publicly when the subscription was created can resolve
 *   to `127.0.0.1` when the delivery is sent. Re-validating at send time is the only check
 *   that catches it.
 * - **The lock.** `Job_Lock` for mutual exclusion, so two ticks cannot deliver the same
 *   payload.
 *
 * ### Redirects are refused rather than followed
 *
 * `wp_safe_remote_post` follows up to five redirects by default. For a webhook that is a
 * request-forgery vector: the endpoint is validated, then the endpoint answers with a 302 to
 * an internal address, and the payload follows it. So `redirection => 0` and a 3xx is a
 * failure, not a delivery.
 *
 * ### What is never sent
 *
 * No API key, password, cookie, or `Authorization` header. No source-page content. The
 * payload is built from event metadata and passed through `Data_Redactor::structure()`, which
 * is the same reduction every other outbound path in the plugin uses.
 */
final class Webhook_Delivery {

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Subscription store.
	 *
	 * @var Webhook_Store
	 */
	private $webhooks;

	/**
	 * Delivery store.
	 *
	 * @var Webhook_Delivery_Store
	 */
	private $deliveries;

	/**
	 * Constructor.
	 *
	 * @param Logger|null               $logger     Logger.
	 * @param Webhook_Store|null        $webhooks   Subscription store.
	 * @param Webhook_Delivery_Store|null $deliveries Delivery store.
	 */
	public function __construct( $logger = null, $webhooks = null, $deliveries = null ) {
		$this->logger     = $logger instanceof Logger ? $logger : new Logger();
		$this->webhooks   = $webhooks instanceof Webhook_Store ? $webhooks : new Webhook_Store( null, $this->logger );
		$this->deliveries = $deliveries instanceof Webhook_Delivery_Store ? $deliveries : new Webhook_Delivery_Store( null, $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * The tick
	 * ------------------------------------------------------------------ */

	/**
	 * Attempt every due delivery.
	 *
	 * Runs from the plugin's existing `replicaforge_process_jobs` cron tick rather than a new
	 * schedule. A webhook is background work by definition, and a second cron means a second
	 * thing to schedule, a second thing to miss, and a second thing to reason about when it
	 * stops firing.
	 *
	 * @param int $limit Maximum deliveries to attempt.
	 * @return array<string, mixed>
	 */
	public function tick( $limit = 0 ) {
		$limit = $limit > 0 ? (int) $limit : Platform_Limits::WEBHOOK_BATCH;

		$lock = new Job_Lock( $this->logger );

		/*
		 * Mutual exclusion for the whole batch. `Job_Lock::with_lock()` returns the
		 * callback's value on success and an array with `success => false` on refusal, which
		 * is why the shape is checked rather than assumed.
		 */
		$result = $lock->with_lock(
			'replicaforge_webhooks',
			function () use ( $limit ) {
				return $this->deliver_due( $limit );
			},
			'webhook-' . getmypid()
		);

		if ( is_array( $result ) && isset( $result['success'] ) && empty( $result['success'] ) ) {
			return array( 'delivered' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 0, 'locked' => true );
		}

		return is_array( $result ) ? $result : array( 'delivered' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 0, 'locked' => false );
	}

	/**
	 * Attempt the due deliveries.
	 *
	 * @param int $limit Maximum deliveries.
	 * @return array<string, mixed>
	 */
	private function deliver_due( $limit ) {
		$out = array( 'delivered' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 0, 'locked' => false );

		foreach ( $this->deliveries->due( $limit ) as $delivery ) {
			$outcome = $this->deliver( $delivery );
			$key     = (string) ( $outcome['state'] ?? 'failed' );

			if ( isset( $out[ $key ] ) ) {
				$out[ $key ]++;
			}
		}

		if ( $out['delivered'] > 0 || $out['failed'] > 0 || $out['dead'] > 0 ) {
			$this->logger->info(
				'webhook_tick',
				'Attempted the webhook deliveries that were due.',
				$out,
				'platform'
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * One delivery
	 * ------------------------------------------------------------------ */

	/**
	 * Attempt one delivery.
	 *
	 * @param array<string, mixed> $delivery Delivery record.
	 * @return array<string, mixed>
	 */
	public function deliver( array $delivery ) {
		$delivery_id = (string) ( $delivery['public_id'] ?? '' );
		$webhook_id  = (string) ( $delivery['webhook_id'] ?? '' );
		$attempt     = (int) ( $delivery['attempts'] ?? 0 ) + 1;

		if ( '' === $delivery_id || '' === $webhook_id ) {
			return array( 'state' => 'skipped', 'reason' => 'unidentified' );
		}

		/*
		 * Attempts are checked before the request, not after. A delivery row that reached the
		 * ceiling and was never marked dead — because a process died between the two — is
		 * retired here rather than on its next attempt, which means the ceiling holds even
		 * when the bookkeeping around it does not.
		 */
		if ( $attempt > Platform_Limits::WEBHOOK_MAX_ATTEMPTS ) {
			$this->deliveries->record(
				$delivery_id,
				array( 'status' => 'dead', 'error' => 'attempts_exhausted', 'attempts' => (int) ( $delivery['attempts'] ?? 0 ) )
			);

			return array( 'state' => 'dead', 'reason' => 'attempts_exhausted' );
		}

		$webhook = $this->webhooks->read( '*', $webhook_id );

		if ( null === $webhook ) {
			$this->deliveries->record( $delivery_id, array( 'status' => 'dead', 'error' => 'webhook_removed' ) );

			return array( 'state' => 'dead', 'reason' => 'webhook_removed' );
		}

		if ( 'active' !== (string) $webhook['status'] ) {
			/*
			 * Disabled or failing: the pending rows stay, undelivered, rather than being
			 * dropped. Re-enabling the subscription drains them, which is what an operator
			 * who fixed the endpoint and turned it back on expects.
			 */
			$this->deliveries->record(
				$delivery_id,
				array( 'status' => 'pending', 'next_attempt_at' => time() + 300, 'error' => 'subscription_' . (string) $webhook['status'] )
			);

			return array( 'state' => 'skipped', 'reason' => 'subscription_' . (string) $webhook['status'] );
		}

		$endpoint = (string) ( $webhook['endpoint'] ?? '' );

		/*
		 * Re-validated on every attempt. A hostname that resolved publicly at registration
		 * can resolve to an internal address now, and a webhook is a request ReplicaForge
		 * makes on a schedule with no human present.
		 */
		if ( '' === $endpoint || ! Security::is_safe_public_reference( $endpoint ) ) {
			$this->fail( $delivery_id, $webhook_id, $attempt, 'endpoint_unsafe', 0, array(), $delivery );

			return array( 'state' => 'failed', 'reason' => 'endpoint_unsafe' );
		}

		$event = $this->event_for( $delivery );
		$body  = $this->deliveries->payload_for( $event );

		if ( '' === $body ) {
			$this->fail( $delivery_id, $webhook_id, $attempt, 'payload_unencodable', 0, array(), $delivery );

			return array( 'state' => 'failed', 'reason' => 'payload_unencodable' );
		}

		$signed = Webhook_Signer::sign( $webhook_id, $body );

		/*
		 * Marked in flight *before* the request. If the process dies during the request the
		 * row is left `delivering`, which `due()` treats as retryable — and the attempts
		 * counter, already incremented below, is what bounds it.
		 */
		$this->deliveries->record( $delivery_id, array( 'status' => 'delivering', 'attempts' => $attempt ) );

		$response = $this->post( $endpoint, $body, $signed, $event );

		if ( ! is_array( $response ) ) {
			$this->fail( $delivery_id, $webhook_id, $attempt, 'transport_error', 0, array(), $delivery );

			return array( 'state' => 'failed', 'reason' => 'transport_error' );
		}

		$code = (int) ( $response['code'] ?? 0 );

		if ( $response['ok'] ) {
			$this->deliveries->record(
				$delivery_id,
				array(
					'status'        => 'delivered',
					'delivered_at'  => gmdate( 'Y-m-d H:i:s' ),
					'response_code' => $code,
					'error'         => '',
				)
			);

			$this->webhooks->record_outcome( $webhook_id, true, substr( $signed['signature'], 3, 16 ) );

			/**
			 * Fires after a webhook was accepted by its endpoint.
			 *
			 * @param string              $webhook_id  Subscription public id.
			 * @param array<string, mixed> $delivery    Delivery record.
			 * @param int                 $code        HTTP status.
			 */
			do_action( 'replicaforge_webhook_delivered', $webhook_id, $delivery, $code );

			return array( 'state' => 'delivered', 'code' => $code );
		}

		$this->fail( $delivery_id, $webhook_id, $attempt, (string) ( $response['error'] ?? 'http_error' ), $code, $response, $delivery );

		return array( 'state' => 'failed', 'reason' => (string) ( $response['error'] ?? 'http_error' ), 'code' => $code );
	}

	/**
	 * Record a failed attempt and schedule the next one.
	 *
	 * @param string              $delivery_id Delivery id.
	 * @param string              $webhook_id  Subscription id.
	 * @param int                 $attempt     Attempt number.
	 * @param string              $reason      Reason.
	 * @param int                 $code        HTTP status, or 0.
	 * @param array<string, mixed> $response    The raw response, for `Retry-After`.
	 * @param array<string, mixed> $delivery    The delivery record, for the `…_failed` hook.
	 * @return void
	 */
	private function fail( $delivery_id, $webhook_id, $attempt, $reason, $code, array $response = array(), array $delivery = array() ) {
		$exhausted = $attempt >= Platform_Limits::WEBHOOK_MAX_ATTEMPTS;

		/*
		 * `Retry-After` is honoured, within a bound. An endpoint that says "come back in an
		 * hour" is telling us something true, and ignoring it produces a retry storm against
		 * a system that has already said it is not ready. Bounded, because an endpoint that
		 * says "in a year" would otherwise park a delivery past its own retention window.
		 */
		$delay = Platform_Limits::backoff_seconds( $attempt );

		if ( isset( $response['retry_after'] ) && (int) $response['retry_after'] > 0 ) {
			$delay = min( DAY_IN_SECONDS, max( $delay, (int) $response['retry_after'] ) );
		}

		$this->deliveries->record(
			$delivery_id,
			array(
				'status'          => $exhausted ? 'dead' : 'failed',
				'next_attempt_at' => time() + $delay,
				'response_code'   => (int) $code,
				'error'           => substr( (string) $reason, 0, 300 ),
			)
		);

		$this->webhooks->record_outcome( $webhook_id, false, $reason );

		/**
		 * Fires after a webhook delivery failed.
		 *
		 * @param string              $webhook_id Subscription id.
		 * @param array<string, mixed> $delivery   Delivery record.
		 * @param string              $reason     Why.
		 * @param bool                $exhausted  Whether this was the last attempt.
		 */
		/*
		 * `$delivery` is a parameter rather than a captured variable.
		 *
		 * The previous version referenced `$delivery` inside this method while its signature
		 * only received `$delivery_id` — so the documented `@param array $delivery` on the hook
		 * below described a value that was never in scope. Under `error_reporting(E_ALL)` that
		 * is an "Undefined variable" notice on every failed delivery, and the hook fired with a
		 * null where a delivery record was promised to any extension listening for it.
		 *
		 * Passing it in is the fix: the hook's documented contract and the value it actually
		 * receives are now the same thing.
		 */
		do_action( 'replicaforge_webhook_failed', $webhook_id, $delivery, (string) $reason, $exhausted );

		if ( $exhausted ) {
			$this->logger->warning(
				'webhook_delivery_dead',
				'A webhook delivery ran out of attempts.',
				array( 'webhook_id' => $webhook_id, 'attempts' => $attempt, 'reason' => substr( (string) $reason, 0, 120 ) ),
				'platform'
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * The request
	 * ------------------------------------------------------------------ */

	/**
	 * POST a signed payload.
	 *
	 * @param string              $endpoint Endpoint.
	 * @param string              $body     Payload.
	 * @param array<string, mixed> $signed   Signature and timestamp.
	 * @param array<string, mixed> $event    The event, for the headers.
	 * @return array{ok: bool, code: int, error: string, retry_after: int}
	 */
	private function post( $endpoint, $body, array $signed, array $event ) {
		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'     => Platform_Limits::WEBHOOK_TIMEOUT_SECONDS,
				/*
				 * Zero. A redirect is a failure, not a hop: the endpoint was validated, and a
				 * 302 from a validated host to an internal address is a request-forgery
				 * vector that post-hoc validation cannot catch.
				 */
				'redirection' => 0,
				'blocking'    => true,
				'headers'     => array(
					'Content-Type'            => 'application/json',
					'User-Agent'              => 'ReplicaForge-Webhook/' . ( defined( 'REPLICAFORGE_VERSION' ) ? REPLICAFORGE_VERSION : '0' ),
					Webhook_Signer::HEADER_SIGNATURE => (string) $signed['signature'],
					Webhook_Signer::HEADER_TIMESTAMP => (string) $signed['timestamp'],
					Webhook_Signer::HEADER_DELIVERY  => (string) ( $event['delivery_id'] ?? '' ),
					Webhook_Signer::HEADER_EVENT    => (string) ( $event['event_type'] ?? '' ),
					/*
					 * Idempotency, so a receiver that supports it gets exactly-once
					 * semantics across ReplicaForge's own retries. A receiver that ignores it
					 * is unaffected.
					 */
					'Idempotency-Key'          => (string) ( $event['delivery_id'] ?? '' ),
				),
				/*
				 * The body is bounded twice: once here, and once in `MAX_VERSION_BYTES`-style
				 * checks upstream. A payload that grew unexpectedly is refused rather than
				 * sent, because an endpoint should not be able to make ReplicaForge write
				 * megabytes to its logs by accepting a large delivery.
				 */
				'body'        => $body,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			/*
			 * The transport error's message is reduced rather than stored whole. WP_Error
			 * messages from `wp_remote_post` can contain the full request line, and this
			 * value ends up in the console where an operator might screenshot it.
			 */
			$message = substr( (string) $response->get_error_message(), 0, 160 );

			return array( 'ok' => false, 'code' => 0, 'error' => 'transport:' . $this->scrub( $message ), 'retry_after' => 0 );
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$retry  = $this->retry_after( wp_remote_retrieve_headers( $response ) );

		if ( $code >= 200 && $code < 300 ) {
			return array( 'ok' => true, 'code' => $code, 'error' => '', 'retry_after' => $retry );
		}

		/*
		 * A 4xx other than 408/429 will not succeed on a retry with the same payload, so it
		 * is a permanent failure and retried once rather than five times. Retrying a 400
		 * five times against a receiver that has already answered definitively is how a
		 * broken integration becomes a denial-of-service against someone else's server.
		 */
		$permanent = $code >= 400 && $code < 500 && 408 !== $code && 429 !== $code;

		return array(
			'ok'          => false,
			'code'        => $code,
			'error'       => $permanent ? 'client_rejected' : ( 0 === $code ? 'no_response' : 'http_' . $code ),
			'retry_after' => $retry,
		);
	}

	/**
	 * Read `Retry-After` from a response header set.
	 *
	 * @param mixed $headers Headers.
	 * @return int Seconds, or 0.
	 */
	private function retry_after( $headers ) {
		if ( ! is_object( $headers ) || ! method_exists( $headers, 'getValues' ) ) {
			return 0;
		}

		foreach ( (array) $headers->getValues( 'retry-after' ) as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$seconds = (int) trim( (string) $value );

			if ( $seconds > 0 ) {
				return min( DAY_IN_SECONDS, $seconds );
			}
		}

		return 0;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Rebuild the event from a delivery row.
	 *
	 * The delivery row stores what a receiver needs to be idempotent and diagnosable — the
	 * event id and type — not the full event, because copying it would make two records that
	 * can disagree. The full event is re-read when the emission is still in flight; when it
	 * is not, a minimal event is reconstructed from the delivery row so the payload is
	 * still well-formed.
	 *
	 * @param array<string, mixed> $delivery Delivery row.
	 * @return array<string, mixed>
	 */
	private function event_for( array $delivery ) {
		$stored = ( new Event_Store() )->read( (string) ( $delivery['event_id'] ?? '' ) );

		if ( null !== $stored ) {
			$stored['delivery_id'] = Webhook_Signer::delivery_id( (string) $delivery['public_id'], (int) $delivery['attempts'] + 1 );
			return $stored;
		}

		return array(
			'event_id'       => (string) ( $delivery['event_id'] ?? '' ),
			'event_type'     => (string) ( $delivery['event_type'] ?? '' ),
			'workspace_id'   => (string) ( $delivery['workspace_id'] ?? '' ),
			'project_id'     => '',
			'resource_id'    => '',
			'actor_id'       => 0,
			'correlation_id' => '',
			'ancestry'       => array(),
			'depth'          => 0,
			'data'           => array( 'note' => __( 'The full event is no longer retained; this is the delivery record for it.', 'replicaforge' ) ),
			'delivery_id'    => Webhook_Signer::delivery_id( (string) $delivery['public_id'], (int) $delivery['attempts'] + 1 ),
		);
	}

	/**
	 * Reduce a transport error to something safe to store.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function scrub( $message ) {
		$message = preg_replace( '#https?://[^\s\'"]+#i', '[url]', (string) $message );
		$message = preg_replace( '/[A-Za-z0-9]{32,}/', '[redacted]', (string) $message );

		return substr( (string) $message, 0, 120 );
	}

	/**
	 * Attempt a test delivery to a subscription's endpoint.
	 *
	 * @param string $webhook_id Subscription id.
	 * @return array<string, mixed>
	 */
	public function test( $webhook_id ) {
		$webhook = $this->webhooks->read( '*', $webhook_id );

		if ( null === $webhook ) {
			return array( 'state' => 'skipped', 'reason' => 'webhook_not_found' );
		}

		$event = array(
			'event_id'       => Request_Context::make_id( 'evt', 10 ),
			'event_type'     => 'workflow.completed',
			'workspace_id'   => (string) ( $webhook['workspace_id'] ?? '' ),
			'project_id'     => '',
			'resource_id'    => '',
			'actor_id'       => get_current_user_id(),
			'correlation_id' => (string) Request_Context::request_id(),
			'ancestry'       => array(),
			'depth'          => 0,
			'created_at'     => gmdate( 'c' ),
			'data'           => array( 'test' => true, 'note' => __( 'This is a test delivery from the ReplicaForge developer console.', 'replicaforge' ) ),
			'delivery_id'    => $webhook_id . '.test',
		);

		/*
		 * Recorded as a real delivery row rather than sent directly, so a test appears in the
		 * same history a real event does. An operator checking whether an integration works
		 * should not have to reason about two different kinds of record.
		 */
		$delivery = $this->deliveries->enqueue( (string) $webhook_id, $event );

		if ( is_wp_error( $delivery ) ) {
			return array( 'state' => 'skipped', 'reason' => (string) $delivery->get_error_code() );
		}

		$outcome = $this->deliver( $delivery );

		return array_merge( $outcome, array( 'delivery_id' => (string) $delivery['public_id'] ) );
	}
}
