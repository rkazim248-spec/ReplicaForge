<?php
/**
 * Phase 11: job resource locks.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents two workers touching the same thing at the same time.
 *
 * A job lease is not enough. `Job_Queue::claim()` leases a *job*, so a second
 * worker cannot run the same job twice. It says nothing about two **different**
 * jobs on the same project — a generation and a correction, or two runs of a
 * pipeline the user started twice from two tabs — and both of those write to the
 * same Elementor document. Phase 6 protects a document by snapshotting before a
 * write, which means the second writer produces a snapshot of the first writer's
 * half-finished work. A snapshot of a corrupt document is still a corrupt
 * document.
 *
 * So a lease answers "is this job still alive?" and a lock answers "is anybody
 * else in here?". They are different questions and they need different primitives.
 *
 * The primitive is `add_option()`, the same one Phase 10's usage accounting uses,
 * for the same reason: it is a single `INSERT` against a unique column, so exactly
 * one concurrent process can create the row. `update_option()` is a read followed
 * by a write, and two processes doing that at once means one write is lost.
 *
 * Four properties §5 requires, and how each is met:
 *
 * - **Expire safely.** Every lock carries an expiry. A lock past its expiry is
 *   takeover-able, because a worker that died holding one must not block the site
 *   permanently.
 * - **Are recoverable.** A takeover records the previous holder, so a lock that
 *   keeps being taken over is visible rather than silent.
 * - **Prevent stale permanent locks.** A lock is only ever honoured while it is
 *   live *and* its token matches, so a process cannot lose its lock and keep
 *   working on the assumption that it still holds it.
 * - **Include ownership.** A lock records who took it and which job, so an
 *   administrator can tell a stuck job from a stolen lock.
 */
final class Job_Lock {

	/**
	 * Option prefix for a lock record.
	 */
	const PREFIX = 'replicaforge_lock_';

	/**
	 * Group used for cache invalidation.
	 */
	const CACHE_GROUP = 'replicaforge';

	/**
	 * Default lock lifetime, in seconds.
	 *
	 * Longer than the Phase 7 job lease (300s) because a lock is taken before the
	 * lease is confirmed and released after it, so it has to outlive the whole
	 * attempt rather than a slice of it. Short enough that a crashed worker's
	 * project is usable again within a few minutes.
	 */
	const DEFAULT_TTL = 420;

	/**
	 * Maximum locks held in one process at once.
	 *
	 * A bound rather than a rate. A caller that acquires in a loop without
	 * releasing must not be able to fill the options table.
	 */
	const MAX_HELD = 12;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Locks this process holds, keyed by resource.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $held = array();

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Optional logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/* ---------------------------------------------------------------------
	 * Acquiring and releasing
	 * ------------------------------------------------------------------ */

	/**
	 * Try to take a lock on a resource.
	 *
	 * Returns a refusal rather than waiting. Waiting would hold a PHP worker for a
	 * request-length time on the assumption that the holder is still alive, and the
	 * job queue already has a mechanism for "not yet" — the job goes back to
	 * `queued` and is picked up on a later tick. Blocking here would duplicate that
	 * and do it worse.
	 *
	 * @param string $resource Resource key, for example `project:proj_abc123`.
	 * @param string $owner    Who is taking it, usually a job id.
	 * @param int    $ttl      Lifetime in seconds.
	 * @return array<string, mixed>
	 */
	public function acquire( $resource, $owner = '', $ttl = 0 ) {
		$resource = $this->clean_resource( $resource );
		if ( '' === $resource ) {
			return $this->refusal( 'invalid_lock_resource', __( 'That lock name is not usable.', 'replicaforge' ), 400 );
		}

		if ( count( $this->held ) >= self::MAX_HELD ) {
			return $this->refusal(
				'too_many_locks_held',
				__( 'This operation already holds too many locks.', 'replicaforge' ),
				409
			);
		}

		$owner  = $this->clean_owner( $owner );
		$ttl    = $this->clean_ttl( $ttl );
		$option = self::PREFIX . $resource;
		$now    = time();

		$record = array(
			'token'       => Request_Context::make_id( 'jlock', 12 ),
			'resource'    => $resource,
			'owner'       => $owner,
			'pid'         => function_exists( 'getmypid' ) ? (int) getmypid() : 0,
			'acquired_at' => $now,
			'heartbeat_at' => $now,
			'expires_at'  => $now + $ttl,
		);

		// Re-entrancy: a process that already holds this lock gets a new token and
		// an extended expiry, and says so. Two stages of one job legitimately
		// re-acquire, and making the second acquisition fail would deadlock the job
		// against itself.
		$existing = get_option( $option, null );
		if ( is_array( $existing ) && ! $this->is_live( $existing, $now ) ) {
			// Dead or stale. Take it over, remembering who had it so a lock that
			// keeps being taken over is visible.
			delete_option( $option );
			$record['took_over_from'] = (string) ( $existing['owner'] ?? '' );
			$record['takeovers']      = (int) ( $existing['takeovers'] ?? 0 ) + 1;

			if ( (int) ( $existing['takeovers'] ?? 0 ) >= 3 ) {
				$this->logger->warning(
					'job_lock_takeover_repeated',
					'A resource lock has been taken over repeatedly, which usually means a worker is being killed mid-stage.',
					array(
						'resource' => $resource,
						'owner'    => (string) ( $existing['owner'] ?? '' ),
					),
					'job'
				);
			}
		}

		if ( null !== $existing && $this->is_live( $existing, $now ) ) {
			// Re-entrancy requires the token *and* the owner to match, not just the
			// token. Matching the token alone is a bug: two different jobs running in
			// one request share this process, so a token check would treat the second
			// job as the first one re-entering its own lock — which is precisely the
			// collision §5 exists to prevent, and it would be invisible because both
			// jobs are in the same process.
			$mine = isset( $this->held[ $resource ] )
				&& (string) $this->held[ $resource ]['token'] === (string) ( $existing['token'] ?? '' )
				&& (string) $this->held[ $resource ]['owner'] === $owner;

			if ( ! $mine ) {
				$this->logger->info(
					'job_lock_contended',
					'A resource lock is already held by another worker, so this one did not start.',
					array(
						'resource' => $resource,
						'holder'   => (string) ( $existing['owner'] ?? '' ),
					),
					'job'
				);
				return $this->refusal(
					'resource_locked',
					__( 'Another ReplicaForge operation is already working on this. It will be picked up in a moment.', 'replicaforge' ),
					409,
					array(
						'resource' => $resource,
						'holder'   => (string) ( $existing['owner'] ?? '' ),
						'expires_at' => (int) ( $existing['expires_at'] ?? 0 ),
					)
				);
			}

			// Re-entrant: extend the existing row and return the same token. This
			// updates rather than creates, because the row is already there —
			// calling `add_option()` on an existing name always fails, so a
			// re-acquisition would be reported as a contention and a job would
			// deadlock against itself between two of its own stages.
			$record['token']       = (string) $existing['token'];
			$record['acquired_at'] = (int) ( $existing['acquired_at'] ?? $now );

			update_option( $option, $record, false );
			$this->held[ $resource ] = $record;

			return array(
				'success'    => true,
				'code'       => '',
				'message'    => '',
				'status'     => 200,
				'resource'   => $resource,
				'token'      => $record['token'],
				'owner'      => $record['owner'],
				'expires_at' => $record['expires_at'],
				'reentrant'  => true,
			);
		}

		if ( ! add_option( $option, $record, '', 'no' ) ) {
			// Two processes created the row at the same instant and one lost. That
			// is the correct outcome for a contended lock: somebody is in here.
			return $this->refusal(
				'resource_locked',
				__( 'Another ReplicaForge operation is already working on this. It will be picked up in a moment.', 'replicaforge' ),
				409,
				array( 'resource' => $resource )
			);
		}

		$this->held[ $resource ] = $record;

		/**
		 * Fires after a resource lock is taken.
		 *
		 * @param string $resource Resource key.
		 * @param string $owner    The job or process that took it.
		 */
		do_action( 'replicaforge_job_lock_acquired', $resource, $record['owner'] );

		return array(
			'success'  => true,
			'code'     => '',
			'message'  => '',
			'status'   => 200,
			'resource' => $resource,
			'token'    => $record['token'],
			'owner'    => $record['owner'],
			'expires_at' => $record['expires_at'],
		);
	}

	/**
	 * Extend a lock this process holds.
	 *
	 * A long stage calls this so its lock does not expire mid-stage. A heartbeat
	 * for a lock this process does not hold is refused rather than silently
	 * ignored: it means the lock was taken over, and the caller needs to know
	 * because it may be about to write to a resource somebody else now owns.
	 *
	 * @param string $resource Resource key.
	 * @param int    $ttl      Lifetime in seconds.
	 * @return bool
	 */
	public function heartbeat( $resource, $ttl = 0 ) {
		$resource = $this->clean_resource( $resource );
		if ( '' === $resource || ! isset( $this->held[ $resource ] ) ) {
			return false;
		}

		$option = self::PREFIX . $resource;
		$stored = get_option( $option, null );
		if ( ! is_array( $stored ) ) {
			unset( $this->held[ $resource ] );
			return false;
		}

		if ( (string) ( $stored['token'] ?? '' ) !== (string) $this->held[ $resource ]['token'] ) {
			$this->logger->warning(
				'job_lock_lost',
				'A resource lock was taken over by another worker while this one was still working.',
				array(
					'resource' => $resource,
					'owner'    => (string) $this->held[ $resource ]['owner'],
				),
				'job'
			);
			unset( $this->held[ $resource ] );
			return false;
		}

		$now                        = time();
		$stored['heartbeat_at']     = $now;
		$stored['expires_at']       = $now + $this->clean_ttl( $ttl );
		update_option( $option, $stored, false );

		$this->held[ $resource ]['expires_at']  = (int) $stored['expires_at'];
		$this->held[ $resource ]['heartbeat_at'] = $now;

		return true;
	}

	/**
	 * Release a lock.
	 *
	 * Release is idempotent and only removes a lock this process still holds. A
	 * release with a stale token is refused, because releasing a lock somebody else
	 * took over would let two workers believe they are inside.
	 *
	 * @param string $resource Resource key.
	 * @param string $token    Optional token, to prove ownership.
	 * @return bool Whether a lock was removed.
	 */
	public function release( $resource, $token = '' ) {
		$resource = $this->clean_resource( $resource );
		if ( '' === $resource ) {
			return false;
		}

		$option = self::PREFIX . $resource;
		$stored = get_option( $option, null );

		$expected = ( '' !== $token ) ? (string) $token : (string) ( $this->held[ $resource ]['token'] ?? '' );
		if ( '' === $expected ) {
			// Nothing to prove. Removing an unknown lock is refused: a caller with
			// no record of holding a lock has no business releasing it.
			return false;
		}

		if ( is_array( $stored ) && (string) ( $stored['token'] ?? '' ) !== $expected ) {
			return false;
		}

		$removed = delete_option( $option );
		unset( $this->held[ $resource ] );

		if ( $removed ) {
			/**
			 * Fires after a resource lock is released.
			 *
			 * @param string $resource Resource key.
			 */
			do_action( 'replicaforge_job_lock_released', $resource );
		}

		return (bool) $removed;
	}

	/**
	 * Run a callback while holding a lock, releasing it whatever happens.
	 *
	 * @param string   $resource Resource key.
	 * @param callable $callback Critical section.
	 * @param string   $owner    Owner description.
	 * @param int      $ttl      Lifetime in seconds.
	 * @return mixed The callback's result, or the acquisition refusal.
	 */
	public function with_lock( $resource, callable $callback, $owner = '', $ttl = 0 ) {
		$acquired = $this->acquire( $resource, $owner, $ttl );
		if ( empty( $acquired['success'] ) ) {
			return $acquired;
		}

		try {
			$result = call_user_func( $callback, $acquired );
		} catch ( \Throwable $error ) {
			$this->release( $resource, (string) $acquired['token'] );
			throw $error;
		}

		$this->release( $resource, (string) $acquired['token'] );

		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether a resource is locked right now.
	 *
	 * @param string $resource Resource key.
	 * @return bool
	 */
	public function is_locked( $resource ) {
		$stored = get_option( self::PREFIX . $this->clean_resource( $resource ), null );
		return is_array( $stored ) && $this->is_live( $stored, time() );
	}

	/**
	 * Return the lock record for a resource.
	 *
	 * @param string $resource Resource key.
	 * @return array<string, mixed>|null
	 */
	public function inspect( $resource ) {
		$stored = get_option( self::PREFIX . $this->clean_resource( $resource ), null );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		$stored['live']     = $this->is_live( $stored, time() );
		$stored['resource'] = $this->clean_resource( $resource );
		return $stored;
	}

	/**
	 * Return every lock this site holds.
	 *
	 * Used by the diagnostics screen. Reads the option-name prefix rather than
	 * keeping an index, because an index is a second thing that can drift out of
	 * step with the locks themselves.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		global $wpdb;

		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::PREFIX ) . '%'
			)
		);

		$out = array();
		$now = time();
		foreach ( (array) $names as $name ) {
			$stored = get_option( (string) $name, null );
			if ( ! is_array( $stored ) ) {
				continue;
			}
			$stored['live']      = $this->is_live( $stored, $now );
			$stored['option']    = (string) $name;
			$stored['remaining'] = max( 0, (int) ( $stored['expires_at'] ?? 0 ) - $now );
			$out[]                = $stored;
		}

		usort(
			$out,
			static function ( $left, $right ) {
				return (int) ( $right['expires_at'] ?? 0 ) <=> (int) ( $left['expires_at'] ?? 0 );
			}
		);

		return $out;
	}

	/**
	 * Return a report of the lock situation, for diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	public function report() {
		$locks = $this->all();
		$live  = array();
		$stale = array();

		foreach ( $locks as $lock ) {
			if ( ! empty( $lock['live'] ) ) {
				$live[] = $lock;
			} else {
				$stale[] = $lock;
			}
		}

		return array(
			'total'     => count( $locks ),
			'live'      => count( $live ),
			'stale'     => count( $stale ),
			'held_here' => count( $this->held ),
			'stuck'     => array_values(
				array_filter(
					$live,
					static function ( $lock ) {
						// Three takeovers means a worker is being killed mid-stage
						// repeatedly, which is a different problem from a lock that is
						// simply long-lived.
						return (int) ( $lock['takeovers'] ?? 0 ) >= 3;
					}
				)
			),
		);
	}

	/**
	 * Remove locks that have expired.
	 *
	 * Called by the maintenance pass. An expired lock is not harmful — the next
	 * `acquire()` takes it over — so this is tidiness rather than correctness, and
	 * it is kept because an options table that grows one row per crashed job is
	 * worse than one that does not.
	 *
	 * @return int Number removed.
	 */
	public function prune() {
		$removed = 0;
		$now     = time();
		foreach ( $this->all() as $lock ) {
			if ( empty( $lock['live'] ) ) {
				if ( delete_option( (string) $lock['option'] ) ) {
					$removed++;
				}
			}
		}
		return $removed;
	}

	/**
	 * Release every lock this process holds.
	 *
	 * Registered as a shutdown handler by {@see Job_Manager}, so a fatal error
	 * cannot leave a lock held until its TTL expires. A shutdown function is the
	 * only cleanup that runs on the failure path.
	 *
	 * @return int Number released.
	 */
	public function release_all() {
		$released = 0;
		foreach ( array_keys( $this->held ) as $resource ) {
			if ( $this->release( $resource ) ) {
				$released++;
			}
		}
		return $released;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether a lock record is still live.
	 *
	 * @param array<string, mixed> $record Lock record.
	 * @param int                  $now    Current time.
	 * @return bool
	 */
	private function is_live( array $record, $now ) {
		return (int) ( $record['expires_at'] ?? 0 ) > $now;
	}

	/**
	 * Build a structured refusal.
	 *
	 * @param string               $code    Error code.
	 * @param string               $message User-facing message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $details Extra details.
	 * @return array<string, mixed>
	 */
	private function refusal( $code, $message, $status, array $details = array() ) {
		return array(
			'success' => false,
			'code'    => (string) $code,
			'message' => (string) $message,
			'status'  => (int) $status,
			'details' => $details,
		);
	}

	/**
	 * Reduce a resource key to something storable.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_resource( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value || strlen( $value ) > 120 ) {
			return '';
		}
		return preg_match( '/^[a-z0-9_:\-]+$/', $value ) ? $value : '';
	}

	/**
	 * Reduce an owner description to something storable.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_owner( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		return substr( preg_replace( '/[^A-Za-z0-9_:\-]/', '', $value ) ?? '', 0, 64 );
	}

	/**
	 * Clamp a requested lifetime.
	 *
	 * @param int $ttl Requested seconds.
	 * @return int
	 */
	private function clean_ttl( $ttl ) {
		$ttl = (int) $ttl;
		if ( $ttl < 5 ) {
			return self::DEFAULT_TTL;
		}
		return min( 3600, $ttl );
	}
}
