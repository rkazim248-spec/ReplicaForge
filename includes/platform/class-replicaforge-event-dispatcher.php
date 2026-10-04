<?php
/**
 * Phase 20: the event dispatcher.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Records events and hands them to two subscribers: automations and webhooks.
 *
 * ### The dispatcher owns no business logic
 *
 * That is the structural point. §17 says "do not place business logic directly into every
 * webhook handler", and the way that is honoured here is that the dispatcher does exactly
 * three things — validate, record, fan out — and both subscribers are separate classes that
 * can be read, tested and reasoned about without reference to each other.
 *
 * ```
 * emit()
 *   ├── validate and refuse (unknown type, no workspace, secret-shaped data)
 *   ├── record to replicaforge_events
 *   ├── Automation_Runner::on_event()      — in-process, synchronous, bounded
 *   └── queue a delivery row per subscriber  — cron, bounded, retried
 * ```
 *
 * ### Correlation and ancestry are carried, not recomputed
 *
 * Every event carries the `correlation_id` of the thread it belongs to and the `ancestry` of
 * automations that have already fired on it. A caller that starts a workflow inside an
 * automation passes its own values down, so the chain is *inherited* rather than guessed —
 * which means the depth an event arrives with is a fact, not an estimate.
 *
 * ### Emitting never throws
 *
 * An event that cannot be recorded is logged and dropped. Nothing in a pipeline should fail
 * because a notification could not be queued, and the alternative — letting a subscriber's
 * problem propagate back into the caller — is how a webhook endpoint turns into an outage.
 */
final class Event_Dispatcher {

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Event store.
	 *
	 * @var Event_Store
	 */
	private $events;

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
	 * Automation runner.
	 *
	 * @var Automation_Runner|null
	 */
	private $automations;

	/**
	 * Events emitted this request, for the request id and for diagnostics.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $emitted = array();

	/**
	 * Constructor.
	 *
	 * @param Logger|null                $logger      Logger.
	 * @param Event_Store|null           $events      Event store.
	 * @param Webhook_Store|null         $webhooks    Subscription store.
	 * @param Webhook_Delivery_Store|null $deliveries  Delivery store.
	 */
	public function __construct( $logger = null, $events = null, $webhooks = null, $deliveries = null ) {
		$this->logger     = $logger instanceof Logger ? $logger : new Logger();
		$this->events     = $events instanceof Event_Store ? $events : new Event_Store( null, $this->logger );
		$this->webhooks   = $webhooks instanceof Webhook_Store ? $webhooks : new Webhook_Store( null, $this->logger );
		$this->deliveries = $deliveries instanceof Webhook_Delivery_Store ? $deliveries : new Webhook_Delivery_Store( null, $this->logger );
	}

	/**
	 * Set the automation runner.
	 *
	 * Injected rather than constructed, because `Automation_Runner` needs the dispatcher and
	 * a circular constructor would be a sign the two are not separate. It is optional: a
	 * dispatcher with no runner records and delivers events and runs no automations, which
	 * is the correct behaviour for a site that has not configured any.
	 *
	 * @param Automation_Runner $runner Runner.
	 * @return void
	 */
	public function set_automation_runner( $runner ) {
		$this->automations = $runner instanceof Automation_Runner ? $runner : null;
	}

	/* ---------------------------------------------------------------------
	 * Emission
	 * ------------------------------------------------------------------ */

	/**
	 * Emit an event.
	 *
	 * @param string              $type    Event type.
	 * @param array<string, mixed> $payload `workspace_id`, `project_id`, `resource_id`,
	 *                                       `data`, `actor_id`, `correlation_id`,
	 *                                       `ancestry`, `depth`.
	 * @return array<string, mixed>|null The stored event, or null when refused.
	 */
	public function emit( $type, array $payload = array() ) {
		$type = is_string( $type ) ? trim( $type ) : '';

		if ( ! Platform_Limits::is_event( $type ) ) {
			/*
			 * Refused and logged. An undeclared event type is a bug at the call site, and
			 * the only question is whether it is visible. Silently dropping it would make
			 * "my webhook never fires" indistinguishable from "my webhook is broken".
			 */
			$this->logger->warning(
				'event_type_undeclared',
				'An event was emitted that is not in the declared list, and was dropped.',
				array( 'event_type' => substr( $type, 0, 60 ) ),
				'platform'
			);

			return null;
		}

		$workspace_id = $this->clean_id( $payload['workspace_id'] ?? '' );

		/*
		 * A workspace is required. An event without one has no permission boundary: nothing
		 * downstream could decide whether the actor may see the resource it names. That is
		 * not a convenience — it is the property that keeps events out of a cross-workspace
		 * leak.
		 */
		if ( '' === $workspace_id ) {
			$this->logger->warning(
				'event_workspace_required',
				'An event was emitted without a workspace and was dropped.',
				array( 'event_type' => $type ),
				'platform'
			);

			return null;
		}

		$depth    = max( 0, (int) ( $payload['depth'] ?? 0 ) );
		$ancestry = array_slice( array_values( array_filter( array_map( 'strval', (array) ( $payload['ancestry'] ?? array() ) ) ) ), 0, 8 );

		/*
		 * The depth ceiling is enforced here as well as in the automation runner.
		 *
		 * Checking at emission means a chain cannot even *record* a fourth link, so the
		 * refusal is in the event log rather than only in the automation's failure count. An
		 * operator debugging a runaway chain can then see exactly where it stopped.
		 */
		if ( $depth >= Platform_Limits::AUTOMATION_MAX_DEPTH ) {
			$this->logger->warning(
				'automation_depth_exceeded',
				'An event reached the maximum automation chain depth and was recorded but not fanned out.',
				array( 'event_type' => $type, 'depth' => $depth, 'limit' => Platform_Limits::AUTOMATION_MAX_DEPTH ),
				'platform'
			);

			$event = $this->events->record(
				array(
					'event_type'     => $type,
					'event_id'       => $this->event_id(),
					'workspace_id'   => $workspace_id,
					'project_id'     => $payload['project_id'] ?? '',
					'resource_id'    => $payload['resource_id'] ?? '',
					'actor_id'       => (int) ( $payload['actor_id'] ?? get_current_user_id() ),
            'correlation_id' => $this->correlation_id( $payload ),
					'ancestry'       => $ancestry,
					'depth'          => $depth,
					'status'         => 'depth_exceeded',
					'data'           => is_array( $payload['data'] ?? null ) ? $payload['data'] : array(),
				)
			);

			$this->remember( $event );

			return $event;
		}

		$event = $this->events->record(
			array(
				'event_type'     => $type,
				'event_id'       => $this->event_id(),
				'workspace_id'   => $workspace_id,
				'project_id'     => $payload['project_id'] ?? '',
				'resource_id'    => $payload['resource_id'] ?? '',
				'actor_id'       => (int) ( $payload['actor_id'] ?? get_current_user_id() ),
            'correlation_id' => $this->correlation_id( $payload ),
				'ancestry'       => $ancestry,
				'depth'          => $depth,
				'status'         => 'recorded',
				'data'           => is_array( $payload['data'] ?? null ) ? $payload['data'] : array(),
			)
		);

		if ( is_wp_error( $event ) ) {
			$this->logger->warning(
				'event_not_recorded',
				'An event could not be recorded.',
				array( 'event_type' => $type, 'reason' => $event->get_error_code() ),
				'platform'
			);

			return null;
		}

		$this->remember( $event );

		$this->fan_out( $event );

		return $event;
	}

	/**
	 * Hand a recorded event to automations and subscribers.
	 *
	 * @param array<string, mixed> $event Stored event.
	 * @return void
	 */
	private function fan_out( array $event ) {
		$type = (string) ( $event['event_type'] ?? '' );

		// Automations first, and in-process: an automation that starts a workflow should do
		// so before the webhook announcing the original event is queued, or a receiver
		// watching `workflow.completed` could see the follow-up before the cause.
		if ( $this->automations instanceof Automation_Runner ) {
			$this->automations->on_event( $event );
		}

		if ( ! Platform_Limits::is_public_event( $type ) ) {
			/*
			 * A non-public event is recorded and can run automations, but it is never handed
			 * to a third party. Internal failure detail should not reach an open internet
			 * endpoint, and offering the subscription would only create a promise that could
			 * not be kept.
			 */
			return;
		}

		$subscriptions = $this->webhooks->listening( (string) $event['workspace_id'], $type, (string) $event['project_id'] );

		if ( array() === $subscriptions ) {
			return;
		}

		foreach ( $subscriptions as $subscription ) {
			$queued = $this->deliveries->enqueue( (string) $subscription['public_id'], $event );

			if ( is_wp_error( $queued ) ) {
				$this->logger->warning(
					'webhook_not_queued',
					'A webhook delivery could not be queued.',
					array( 'webhook_id' => (string) $subscription['public_id'], 'event_type' => $type ),
					'platform'
				);
			}
		}

		/**
		 * Fires after an event has been recorded and fanned out.
		 *
		 * This is the hook an integration uses when a webhook is not appropriate — a plugin
		 * in the same process that wants to react without an HTTP round trip.
		 *
		 * @param array<string, mixed> $event The stored event.
		 */
		do_action( 'replicaforge_event_emitted', $event );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return the events emitted this request.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function emitted() {
		return $this->emitted;
	}

	/**
	 * Return the most recent event's correlation id, for a caller continuing a chain.
	 *
	 * @return string
	 */
	public function current_correlation() {
		$last = end( $this->emitted );

		return is_array( $last ) ? (string) $last['correlation_id'] : $this->correlation_id( array() );
	}

	/**
	 * Return the most recent event's ancestry, for a caller continuing a chain.
	 *
	 * @return array<int, string>
	 */
	public function current_ancestry() {
		$last = end( $this->emitted );

		return is_array( $last ) ? (array) ( $last['ancestry'] ?? array() ) : array();
	}

	/**
	 * Return a summary for the console.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, mixed>
	 */
	public function summary( $workspace_id ) {
		return array(
			'counts' => $this->events->counts( (string) $workspace_id ),
			'emitted_this_request' => count( $this->emitted ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Build an event id.
	 *
	 * @return string
	 */
	private function event_id() {
		return Request_Context::make_id( 'evt', 10 );
	}

	/**
	 * Return the correlation id for an event.
	 *
	 * ### Why this is the request id
	 *
	 * §38 asks for one identifier shared by the API request, the workflow, the job, the
	 * webhook, the logs and the error report. `Request_Context::request_id()` already *is*
	 * that identifier for everything else in the plugin — it is what `Logger` stamps on
	 * entries and what `Logger::current_context()` reports — so reusing it means a developer
	 * can paste one value from a failed webhook delivery and find the request that caused it.
	 *
	 * A caller inside an automation passes its own value, which is what keeps a chain's
	 * events on one thread rather than on the id of whichever PHP process happened to run
	 * the next stage.
	 *
	 * @param array<string, mixed> $payload Event payload.
	 * @return string
	 */
	private function correlation_id( array $payload ) {
		$supplied = isset( $payload['correlation_id'] ) && is_scalar( $payload['correlation_id'] )
			? substr( (string) $payload['correlation_id'], 0, 64 )
			: '';

		if ( '' !== $supplied ) {
			return $supplied;
		}

		return (string) Request_Context::request_id();
	}

	/**
	 * Remember an emitted event.
	 *
	 * @param mixed $event Event.
	 * @return void
	 */
	private function remember( $event ) {
		if ( is_array( $event ) && isset( $event['public_id'] ) ) {
			$this->emitted[] = $event;
		}

		if ( count( $this->emitted ) > 50 ) {
			$this->emitted = array_slice( $this->emitted, -50 );
		}
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}
}
