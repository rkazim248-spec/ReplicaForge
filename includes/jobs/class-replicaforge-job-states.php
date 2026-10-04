<?php
/**
 * Phase 11: the job state machine.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * One definition of what a job's status means and which move is legal.
 *
 * Phase 7 declared six statuses and relied on `Job_Queue` to move between them
 * correctly. That worked, but it meant the legal moves lived in the code that
 * performed them, so the set of reachable states was only discoverable by reading
 * every method. §2 asks for centralized transitions, and the reason is the same
 * reason Phase 10 centralized the plan vocabulary: a rename or an addition that
 * has to be mirrored in five methods is a rename or an addition that will be
 * missed in one of them.
 *
 * Five states are added, and each one exists because a situation genuinely occurs
 * that the six could not describe:
 *
 * - `retrying` — the job failed and is waiting out a backoff. The old model called
 *   this `queued`, which meant a job waiting 15 minutes looked identical to a job
 *   that had never run, so a dashboard could not answer "why is nothing
 *   happening".
 * - `waiting` — the job is waiting on something outside itself, most often a
 *   provider's rate limit. It is not delayed by our own policy, and conflating
 *   that with a backoff hides the reason a job is stuck.
 * - `expired` — a lease died and the work was not resumable, or the attempt window
 *   closed. Distinct from `failed` because nothing went wrong with the work; the
 *   worker went away.
 * - `blocked` — the job cannot proceed and retrying will not help until something
 *   external changes, such as a plan limit or a missing dependency.
 * - `reserved` — the job is claimed and its resource locks are held, but execution
 *   has not begun. This is the window in which a second worker must not start.
 *
 * `blocked` and `waiting` are **not** failures and are **not** retried on the same
 * clock. A blocked job that retried would burn attempts against a condition it
 * cannot fix.
 */
final class Job_States {

	/**
	 * Waiting to be claimed. No attempt is in progress.
	 */
	const QUEUED = 'queued';

	/**
	 * Claimed, resource locks held, execution not yet begun.
	 */
	const RESERVED = 'reserved';

	/**
	 * Executing a stage under a live lease.
	 */
	const RUNNING = 'running';

	/**
	 * Waiting on an external signal, most often a provider rate limit.
	 */
	const WAITING = 'waiting';

	/**
	 * Waiting out a retry backoff after a failure.
	 */
	const RETRYING = 'retrying';

	/**
	 * Stopped deliberately by a user or an administrator, resumable.
	 */
	const PAUSED = 'paused';

	/**
	 * Finished successfully.
	 */
	const COMPLETED = 'completed';

	/**
	 * Finished unsuccessfully after exhausting its attempts.
	 */
	const FAILED = 'failed';

	/**
	 * Stopped deliberately and not resumable.
	 */
	const CANCELLED = 'cancelled';

	/**
	 * The attempt window closed, usually because a lease died.
	 */
	const EXPIRED = 'expired';

	/**
	 * Cannot proceed until something external changes.
	 */
	const BLOCKED = 'blocked';

	/**
	 * Every state, in the order a healthy job passes through them.
	 *
	 * @var array<int, string>
	 */
	const ALL = array(
		self::QUEUED,
		self::RESERVED,
		self::RUNNING,
		self::WAITING,
		self::RETRYING,
		self::PAUSED,
		self::COMPLETED,
		self::FAILED,
		self::CANCELLED,
		self::EXPIRED,
		self::BLOCKED,
	);

	/**
	 * States from which no further work happens.
	 *
	 * `expired` is terminal, and that is a decision rather than an oversight. A job
	 * whose lease died may have already modified an Elementor document, and
	 * re-running it could apply a second set of changes to a half-written draft.
	 * Expiry therefore ends the attempt and hands the decision to a person or to an
	 * explicit retry, which is a different operation with a different risk.
	 *
	 * @var array<int, string>
	 */
	const TERMINAL = array(
		self::COMPLETED,
		self::FAILED,
		self::CANCELLED,
		self::EXPIRED,
	);

	/**
	 * States that mean an attempt is in progress or about to be.
	 *
	 * @var array<int, string>
	 */
	const ACTIVE = array(
		self::RESERVED,
		self::RUNNING,
	);

	/**
	 * States a worker will pick up when it is ready.
	 *
	 * @var array<int, string>
	 */
	const SCHEDULED = array(
		self::QUEUED,
		self::RETRYING,
		self::WAITING,
	);

	/**
	 * The allowed transitions.
	 *
	 * A map, because "is this move legal" needs both sides. The interesting cases
	 * are the absent ones: `running` cannot go straight to `queued` without passing
	 * through a state that records what happened, because that is exactly how a
	 * failed job comes to look like a fresh one.
	 *
	 * @var array<string, array<int, string>>
	 */
	const TRANSITIONS = array(
		self::QUEUED    => array( self::RESERVED, self::CANCELLED, self::BLOCKED, self::PAUSED ),
		self::RESERVED  => array( self::RUNNING, self::QUEUED, self::RETRYING, self::WAITING, self::FAILED, self::CANCELLED, self::EXPIRED, self::BLOCKED ),
		self::RUNNING   => array( self::RUNNING, self::QUEUED, self::WAITING, self::RETRYING, self::PAUSED, self::COMPLETED, self::FAILED, self::CANCELLED, self::EXPIRED, self::BLOCKED ),
		self::WAITING   => array( self::RESERVED, self::WAITING, self::RETRYING, self::QUEUED, self::FAILED, self::CANCELLED, self::EXPIRED, self::BLOCKED ),
		self::RETRYING  => array( self::RESERVED, self::QUEUED, self::WAITING, self::FAILED, self::CANCELLED, self::EXPIRED, self::BLOCKED ),
		self::PAUSED    => array( self::QUEUED, self::RESERVED, self::CANCELLED, self::EXPIRED, self::BLOCKED ),
		self::COMPLETED => array(),
		self::FAILED    => array( self::QUEUED, self::CANCELLED ),
		self::CANCELLED => array(),
		self::EXPIRED   => array( self::QUEUED, self::CANCELLED ),
		self::BLOCKED   => array( self::QUEUED, self::CANCELLED, self::EXPIRED ),
	);

	/**
	 * Return whether a value is a declared state.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		return is_string( $value ) && in_array( $value, self::ALL, true );
	}

	/**
	 * Return whether a state means no further work will happen.
	 *
	 * @param mixed $status Candidate.
	 * @return bool
	 */
	public static function is_terminal( $status ) {
		return is_string( $status ) && in_array( $status, self::TERMINAL, true );
	}

	/**
	 * Return whether a state means an attempt is in progress.
	 *
	 * @param mixed $status Candidate.
	 * @return bool
	 */
	public static function is_active( $status ) {
		return is_string( $status ) && in_array( $status, self::ACTIVE, true );
	}

	/**
	 * Return whether a worker should consider a job in this state.
	 *
	 * @param mixed $status Candidate.
	 * @return bool
	 */
	public static function is_scheduled( $status ) {
		return is_string( $status ) && in_array( $status, self::SCHEDULED, true );
	}

	/**
	 * Return whether a transition is allowed.
	 *
	 * A transition to the same state is always allowed. A workflow re-asserts the
	 * state it is already in constantly — a stage that runs twice, a progress
	 * update mid-stage — and refusing that would force a special case at every call
	 * site rather than one here.
	 *
	 * @param mixed $from Current state.
	 * @param mixed $to   Target state.
	 * @return bool
	 */
	public static function can_transition( $from, $to ) {
		if ( ! self::is_valid( $from ) || ! self::is_valid( $to ) ) {
			return false;
		}
		if ( $from === $to ) {
			return true;
		}
		$allowed = isset( self::TRANSITIONS[ $from ] ) ? self::TRANSITIONS[ $from ] : array();
		return in_array( $to, $allowed, true );
	}

	/**
	 * Return a structured refusal for an illegal transition.
	 *
	 * @param mixed  $from   Current state.
	 * @param mixed  $to     Target state.
	 * @param string $job_id  Job identifier.
	 * @return array<string, mixed>
	 */
	public static function refusal( $from, $to, $job_id = '' ) {
		return array(
			'success'  => false,
			'code'     => 'invalid_job_transition',
			'message'  => sprintf(
				/* translators: 1: current state, 2: requested state. */
				__( 'A job cannot move from %1$s to %2$s.', 'replicaforge' ),
				self::is_valid( $from ) ? $from : (string) $from,
				self::is_valid( $to ) ? $to : (string) $to
			),
			'status'   => 409,
			'details'  => array(
				'from'      => (string) $from,
				'to'        => (string) $to,
				'job_id'    => (string) $job_id,
				'allowed'   => self::allowed_from( $from ),
			),
		);
	}

	/**
	 * Return the states reachable from a state.
	 *
	 * @param mixed $status Current state.
	 * @return array<int, string>
	 */
	public static function allowed_from( $status ) {
		if ( ! self::is_valid( $status ) ) {
			return array();
		}
		$allowed = isset( self::TRANSITIONS[ $status ] ) ? self::TRANSITIONS[ $status ] : array();
		$out     = array( (string) $status );
		foreach ( $allowed as $target ) {
			if ( ! in_array( $target, $out, true ) ) {
				$out[] = $target;
			}
		}
		return $out;
	}

	/**
	 * Return a state label.
	 *
	 * @param string $status State name.
	 * @return string
	 */
	public static function label( $status ) {
		$labels = array(
			self::QUEUED    => __( 'Queued', 'replicaforge' ),
			self::RESERVED  => __( 'Reserved', 'replicaforge' ),
			self::RUNNING   => __( 'Running', 'replicaforge' ),
			self::WAITING   => __( 'Waiting', 'replicaforge' ),
			self::RETRYING  => __( 'Retrying', 'replicaforge' ),
			self::PAUSED    => __( 'Paused', 'replicaforge' ),
			self::COMPLETED => __( 'Completed', 'replicaforge' ),
			self::FAILED    => __( 'Failed', 'replicaforge' ),
			self::CANCELLED => __( 'Cancelled', 'replicaforge' ),
			self::EXPIRED   => __( 'Expired', 'replicaforge' ),
			self::BLOCKED   => __( 'Blocked', 'replicaforge' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Return a translated explanation of a state.
	 *
	 * These are what a user reads when a job is not moving, so they say what is
	 * happening rather than naming the state.
	 *
	 * @param string $status State name.
	 * @return string
	 */
	public static function explain( $status ) {
		$texts = array(
			self::QUEUED    => __( 'Waiting to start.', 'replicaforge' ),
			self::RESERVED  => __( 'Claimed and about to start.', 'replicaforge' ),
			self::RUNNING   => __( 'In progress.', 'replicaforge' ),
			self::WAITING   => __( 'Waiting for the AI provider to allow more requests.', 'replicaforge' ),
			self::RETRYING  => __( 'A step failed and is being retried shortly.', 'replicaforge' ),
			self::PAUSED    => __( 'Paused. It can be resumed.', 'replicaforge' ),
			self::COMPLETED => __( 'Finished successfully.', 'replicaforge' ),
			self::FAILED    => __( 'Failed after several attempts.', 'replicaforge' ),
			self::CANCELLED => __( 'Cancelled.', 'replicaforge' ),
			self::EXPIRED   => __( 'Stopped because the worker running it went away. It can be retried.', 'replicaforge' ),
			self::BLOCKED   => __( 'Cannot continue until something on the site changes.', 'replicaforge' ),
		);

		return isset( $texts[ $status ] ) ? $texts[ $status ] : '';
	}

	/**
	 * Return the whole vocabulary, for a screen or a filter.
	 *
	 * @return array<string, array{label: string, terminal: bool, active: bool, scheduled: bool, allowed: array<int, string>}>
	 */
	public static function vocabulary() {
		$out = array();
		foreach ( self::ALL as $status ) {
			$out[ $status ] = array(
				'label'     => self::label( $status ),
				'explain'   => self::explain( $status ),
				'terminal'  => self::is_terminal( $status ),
				'active'    => self::is_active( $status ),
				'scheduled' => self::is_scheduled( $status ),
				'allowed'   => self::allowed_from( $status ),
			);
		}
		return $out;
	}
}
