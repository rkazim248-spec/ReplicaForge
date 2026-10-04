<?php
/**
 * Phase 7: job storage.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes jobs.
 *
 * Jobs live in a single bounded option rather than a custom table. A site is not
 * expected to hold thousands of jobs, the read pattern is always "the last N", and
 * one row keeps the schema surface small, which matters more than query
 * flexibility at this scale.
 */
final class Job_Repository {

	/**
	 * Option holding the jobs.
	 */
	const OPTION = 'replicaforge_jobs';

	/**
	 * Transient prefix for bulky stage payloads, which are kept out of the job
	 * record so the option stays small.
	 */
	const PAYLOAD_PREFIX = 'replicaforge_job_payload_';

	/**
	 * Seconds a stage payload is retained. A job that resumes days later re-runs
	 * its earlier stage rather than rebuilding a draft from a stale payload.
	 */
	const PAYLOAD_TTL = DAY_IN_SECONDS;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Optional logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Return every stored job, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$jobs = array();
		foreach ( $stored as $job ) {
			if ( is_array( $job ) && isset( $job['job_id'] ) ) {
				$jobs[] = $job;
			}
		}
		return $jobs;
	}

	/**
	 * Return one job, or null.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>|null
	 */
	public function find( $job_id ) {
		if ( ! is_string( $job_id ) || '' === $job_id ) {
			return null;
		}
		foreach ( $this->all() as $job ) {
			if ( (string) $job['job_id'] === $job_id ) {
				return $job;
			}
		}
		return null;
	}

	/**
	 * Create a job.
	 *
	 * @param string               $type   Job type.
	 * @param array<string, mixed> $params Bounded parameters.
	 * @param string               $stage  Starting stage.
	 * @return array<string, mixed> The created job.
	 */
	public function create( $type, array $params = array(), $stage = 'queued' ) {
		$type = isset( Job_Limits::TYPES[ $type ] ) ? $type : 'replica';
		$now  = gmdate( 'c' );

		$job = array(
			'job_id'      => Request_Context::make_id( 'job', 12 ),
			'type'        => $type,
			'status'      => Job_Limits::STATUSES['queued'],
			'stage'       => in_array( $stage, Job_Limits::STAGES, true ) ? $stage : 'queued',
			'progress'    => Job_Limits::stage_progress( $stage ),
			'params'      => Data_Redactor::payload( $params ),
			'result'      => null,
			'error'       => null,
			'warnings'    => array(),
			'attempts'    => 0,
			'checkpoint'  => array(),
			'lease_until' => 0,
			'next_attempt' => 0,
			'created_by'  => get_current_user_id(),
			'created_at'  => $now,
			'updated_at'  => $now,
			'started_at'  => '',
			'finished_at' => '',
		);

		$jobs   = $this->all();
		$jobs[] = $job;
		$this->store( $jobs );

		Request_Context::set( 'job', (string) $job['job_id'] );
		$this->logger->info(
			'job_created',
			'Created a ' . $type . ' job.',
			array( 'type' => $type, 'stage' => $job['stage'] ),
			'job'
		);

		return $job;
	}

	/**
	 * Update a job and persist it.
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed>|null The updated job, or null when not found.
	 */
	public function update( $job_id, array $changes ) {
		$jobs  = $this->all();
		$found = null;

		foreach ( $jobs as $index => $job ) {
			if ( (string) $job['job_id'] !== (string) $job_id ) {
				continue;
			}
			foreach ( $changes as $key => $value ) {
				$job[ $key ] = $value;
			}
			$job['updated_at'] = gmdate( 'c' );
			$jobs[ $index ]    = $job;
			$found             = $job;
			break;
		}

		if ( null === $found ) {
			return null;
		}
		$this->store( $jobs );
		return $found;
	}

	/**
	 * Delete a job.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public function delete( $job_id ) {
		$jobs = $this->all();
		$kept = array();
		$hit  = false;
		foreach ( $jobs as $job ) {
			if ( (string) $job['job_id'] === (string) $job_id ) {
				$hit = true;
				continue;
			}
			$kept[] = $job;
		}
		if ( ! $hit ) {
			return false;
		}
		$this->delete_payloads( (string) $job_id );
		$this->store( $kept );
		return true;
	}

	/**
	 * Store a bulky stage payload outside the job record.
	 *
	 * A checkpoint needs to survive between stages, and some of what it carries is
	 * large: a Phase 2 representation is bigger than a job list should ever be. The
	 * value is therefore kept in its own bounded, expiring transient and the
	 * checkpoint holds only a reference. The job option stays small enough to read
	 * in one query, and an abandoned payload expires on its own instead of
	 * accumulating for the life of the site.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $key    Payload name.
	 * @param mixed  $value  Payload value.
	 * @return string Reference token, or an empty string when the value is unusable.
	 */
	public function set_payload( $job_id, $key, $value ) {
		$job_id = is_string( $job_id ) ? $job_id : '';
		$key    = is_string( $key ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( $key ) ) : '';
		if ( '' === $job_id || '' === $key ) {
			return '';
		}
		$encoded = wp_json_encode( $value );
		if ( ! is_string( $encoded ) ) {
			return '';
		}
		$token = substr( hash( 'sha256', $job_id . '|' . $key . '|' . $encoded ), 0, 16 );
		set_transient( self::PAYLOAD_PREFIX . $job_id . '_' . $key . '_' . $token, $value, self::PAYLOAD_TTL );
		return $token;
	}

	/**
	 * Read a stage payload back.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $key    Payload name.
	 * @param string $token  Reference token.
	 * @return mixed Null when the payload has expired or the token does not match.
	 */
	public function get_payload( $job_id, $key, $token ) {
		$job_id = is_string( $job_id ) ? $job_id : '';
		$key    = is_string( $key ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( $key ) ) : '';
		$token  = is_string( $token ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( $token ) ) : '';
		if ( '' === $job_id || '' === $key || '' === $token ) {
			return null;
		}
		$stored = get_transient( self::PAYLOAD_PREFIX . $job_id . '_' . $key . '_' . $token );
		return false === $stored ? null : $stored;
	}

	/**
	 * Remove a job's stage payloads.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public function delete_payloads( $job_id ) {
		global $wpdb;

		$job_id = is_string( $job_id ) && '' !== $job_id ? $job_id : '';
		if ( '' === $job_id ) {
			return;
		}
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::PAYLOAD_PREFIX . $job_id . '_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::PAYLOAD_PREFIX . $job_id . '_' ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			$name = (string) $name;
			if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
				delete_option( $name );
				continue;
			}
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
	}

	/**
	 * Return jobs, newest first, optionally filtered.
	 *
	 * @param array<string, mixed> $filters Optional `type`, `status`, `source_host`.
	 * @param int                  $limit   Maximum jobs.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( array $filters = array(), $limit = 25 ) {
		$jobs   = array_reverse( $this->all() );
		$type   = isset( $filters['type'] ) ? (string) $filters['type'] : '';
		$status = isset( $filters['status'] ) ? (string) $filters['status'] : '';
		$host   = isset( $filters['source_host'] ) ? strtolower( trim( (string) $filters['source_host'] ) ) : '';

		$filtered = array();
		foreach ( $jobs as $job ) {
			if ( '' !== $type && (string) ( $job['type'] ?? '' ) !== $type ) {
				continue;
			}
			if ( '' !== $status && (string) ( $job['status'] ?? '' ) !== $status ) {
				continue;
			}
			if ( '' !== $host ) {
				$job_host = strtolower( (string) ( $job['params']['source_host'] ?? $job['params']['source_url'] ?? '' ) );
				if ( false === strpos( $job_host, $host ) ) {
					continue;
				}
			}
			$filtered[] = $this->present( $job );
			if ( count( $filtered ) >= max( 1, (int) $limit ) ) {
				break;
			}
		}
		return $filtered;
	}

	/**
	 * Return jobs eligible for a worker to claim.
	 *
	 * A `running` job whose lease has expired is included, because that is how a
	 * job whose worker was killed becomes recoverable.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function claimable() {
		$now       = time();
		$claimable = array();

		foreach ( $this->all() as $job ) {
			$status = (string) ( $job['status'] ?? '' );

			// `queued` is the plain waiting state; `retrying` and `waiting` are the
			// same thing with a reason attached, and all three honour `next_attempt`.
			// They share one branch because a job waiting out a backoff, a job waiting
			// for a provider, and a job that has never run are all simply "not yet".
			if ( Job_States::is_scheduled( $status ) ) {
				if ( (int) ( $job['next_attempt'] ?? 0 ) > $now ) {
					continue;
				}
				$claimable[] = $job;
				continue;
			}

			// An active job whose lease has run out is claimable again, which is how a
			// crashed worker's job is picked up. `Job_Recovery` decides whether doing
			// so is safe; the queue only knows the job is idle.
			if ( Job_States::is_active( $status ) && (int) ( $job['lease_until'] ?? 0 ) < $now ) {
				$claimable[] = $job;
			}
		}

		/*
		 * Order by `next_attempt`, then by creation time.
		 *
		 * The list used to come back in whatever order `all()` produced, and
		 * `Job_Runner::tick()` takes the first N. That starves retries: a job backing off for
		 * a minute sits behind every freshly queued job, so each tick spends its whole budget
		 * on new work and the retry - the one that was already half finished, and the one a
		 * transient provider outage would clear - is never reached.
		 *
		 * Earliest due first is the obvious order and the one a queue is expected to use. The
		 * tiebreak on `created_at` keeps it deterministic, so two workers computing the same
		 * claimable set agree on which job is first.
		 *
		 * Recovered jobs (lease expired) carry `next_attempt` 0, so they sort ahead of work
		 * that is deliberately waiting - which is right, because a worker crash is a fault to
		 * clear rather than a delay to respect.
		 */
		$position = 0;

		foreach ( $claimable as $index => $job ) {
			$claimable[ $index ]['__order'] = $position;
			$position++;

			$claimable[ $index ]['__due'] = min(
				(int) ( $job['next_attempt'] ?? 0 ),
				(int) ( $job['lease_until'] ?? 0 )
			);
		}

		usort(
			$claimable,
			static function ( $left, $right ) {
				if ( $left['__due'] !== $right['__due'] ) {
					return ( (int) $left['__due'] ) <=> ( (int) $right['__due'] );
				}

				$created = strcmp( (string) ( $left['created_at'] ?? '' ), (string) ( $right['created_at'] ?? '' ) );

				if ( 0 !== $created ) {
					return $created;
				}

				return ( (int) $left['__order'] ) <=> ( (int) $right['__order'] );
			}
		);

		// The sort keys are internal and must not reach a caller.
		foreach ( $claimable as $index => $job ) {
			unset( $claimable[ $index ]['__order'], $claimable[ $index ]['__due'] );
		}

		return $claimable;
	}

	/**
	 * Return a job in the shape the UI and the REST layer use.
	 *
	 * @param array<string, mixed> $job Stored job.
	 * @return array<string, mixed>
	 */
	public function present( array $job ) {
		$stage    = (string) ( $job['stage'] ?? 'queued' );
		$status   = (string) ( $job['status'] ?? 'queued' );
		$progress = $this->progress_for( $job );

		return array(
			'job_id'       => (string) ( $job['job_id'] ?? '' ),
			'type'         => (string) ( $job['type'] ?? '' ),
			'status'       => $status,
			'stage'        => $stage,
			'stages'       => Job_Limits::STAGES,
			'progress'     => $progress,
			'source_url'   => (string) ( $job['params']['source_url'] ?? '' ),
			'source_host'  => (string) ( $job['params']['source_host'] ?? '' ),
			'result'       => $this->present_result( $job ),
			'error'        => $this->present_error( $job ),
			'warnings'     => array_slice( array_values( array_filter( array_map( 'strval', (array) ( $job['warnings'] ?? array() ) ) ) ), 0, 20 ),
			'attempts'     => (int) ( $job['attempts'] ?? 0 ),
			'max_attempts' => Job_Limits::MAX_ATTEMPTS,
			'can_resume'   => $this->can_resume_job( $job ),
			'can_retry'    => Job_Limits::is_terminal( $status ) && (int) ( $job['attempts'] ?? 0 ) < Job_Limits::MAX_ATTEMPTS,
			'can_cancel'   => ! Job_Limits::is_terminal( $status ),
			'created_by'   => isset( $job['created_by'] ) ? (int) $job['created_by'] : 0,
			'created_at'   => (string) ( $job['created_at'] ?? '' ),
			'updated_at'   => (string) ( $job['updated_at'] ?? '' ),
			'started_at'   => (string) ( $job['started_at'] ?? '' ),
			'finished_at'  => (string) ( $job['finished_at'] ?? '' ),
			'duration_ms'  => $this->duration_ms( $job ),
			'next_attempt' => (int) ( $job['next_attempt'] ?? 0 ),
		);
	}

	/**
	 * Return whether a job can be resumed from where it stopped.
	 *
	 * A paused job can always be resumed: it was stopped on purpose and has used no
	 * attempts. A failed job can be resumed only while it has attempts left, which
	 * is the same condition `Job_Queue::resume()` enforces, so the screen never
	 * offers an action the API will refuse.
	 *
	 * @param array<string, mixed> $job Stored job.
	 * @return bool
	 */
	private function can_resume_job( array $job ) {
		$status = (string) ( $job['status'] ?? '' );
		if ( Job_Limits::STATUSES['paused'] === $status ) {
			return true;
		}
		if ( Job_Limits::STATUSES['failed'] === $status ) {
			return (int) ( $job['attempts'] ?? 0 ) < Job_Limits::MAX_ATTEMPTS;
		}
		return false;
	}

	/**
	 * Return the percentage a job has reached.
	 *
	 * A terminal job is 100 whatever its stage, because the work is over. Anything
	 * else reports the stage it has actually reached.
	 *
	 * @param array<string, mixed> $job Stored job.
	 * @return int
	 */
	private function progress_for( array $job ) {
		$status = (string) ( $job['status'] ?? '' );
		if ( Job_Limits::is_terminal( $status ) ) {
			return Job_Limits::STATUSES['cancelled'] === $status ? 100 : 100;
		}
		return Job_Limits::stage_progress( (string) ( $job['stage'] ?? 'queued' ) );
	}

	/**
	 * Return a job's result, reduced to what is safe to show.
	 *
	 * @param array<string, mixed> $job Stored job.
	 * @return array<string, mixed>
	 */
	private function present_result( array $job ) {
		$result = isset( $job['result'] ) && is_array( $job['result'] ) ? $job['result'] : array();
		$out    = array();
		foreach ( array( 'draft_id', 'generation_id', 'validation_id', 'plan_id', 'score', 'element_count', 'applied' ) as $key ) {
			if ( array_key_exists( $key, $result ) ) {
				$out[ $key ] = $result[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Return a job's error as a user-facing envelope.
	 *
	 * @param array<string, mixed> $job Stored job.
	 * @return array<string, mixed>
	 */
	private function present_error( array $job ) {
		$error = isset( $job['error'] ) && is_array( $job['error'] ) ? $job['error'] : null;
		if ( null === $error ) {
			return array();
		}
		return array(
			'code'      => (string) ( $error['code'] ?? 'internal_error' ),
			'message'   => (string) ( $error['message'] ?? '' ),
			'severity'  => (string) ( $error['severity'] ?? 'error' ),
			'retryable' => ! empty( $error['retryable'] ),
			'category'  => (string) ( $error['category'] ?? 'SYSTEM' ),
		);
	}

	/**
	 * Return how long a job took, in milliseconds.
	 *
	 * @param array<string, mixed> $job Stored job.
	 * @return int
	 */
	private function duration_ms( array $job ) {
		$started = (string) ( $job['started_at'] ?? '' );
		$end     = (string) ( $job['finished_at'] ?? '' );
		if ( '' === $started ) {
			return 0;
		}
		$from = strtotime( $started );
		$to   = '' !== $end ? strtotime( $end ) : time();
		if ( false === $from || false === $to ) {
			return 0;
		}
		return max( 0, ( $to - $from ) * 1000 );
	}

	/**
	 * Persist the job list, pruning finished jobs past the retention limit.
	 *
	 * @param array<int, array<string, mixed>> $jobs Jobs.
	 * @return void
	 */
	private function store( array $jobs ) {
		if ( count( $jobs ) > Job_Limits::MAX_JOBS ) {
			$keep   = array_slice( $jobs, -Job_Limits::MAX_JOBS );
			$active = array();
			$oldest = array();
			foreach ( $keep as $job ) {
				if ( Job_Limits::is_terminal( (string) ( $job['status'] ?? '' ) ) ) {
					$oldest[] = $job;
					continue;
				}
				$active[] = $job;
			}
			// Active jobs are never dropped: losing one would lose the user's work.
			$room  = max( 0, Job_Limits::MAX_JOBS - count( $active ) );
			$jobs  = array_merge( $active, array_slice( $oldest, -$room ) );
			usort(
				$jobs,
				static function ( $left, $right ) {
					return strcmp( (string) ( $left['created_at'] ?? '' ), (string) ( $right['created_at'] ?? '' ) );
				}
			);
		}
		update_option( self::OPTION, array_values( $jobs ), false );
	}

	/**
	 * Count jobs by status, for the dashboard.
	 *
	 * @return array<string, int>
	 */
	public function counts() {
		$counts = array_fill_keys( array_keys( Job_Limits::STATUSES ), 0 );
		foreach ( $this->all() as $job ) {
			$status = (string) ( $job['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
		}
		return $counts;
	}

	/**
	 * Remove finished jobs older than a cutoff.
	 *
	 * @param int $days Retention in days.
	 * @return int Number removed.
	 */
	public function prune( $days = 14 ) {
		$days       = max( 1, (int) $days );
		$cutoff     = time() - ( $days * DAY_IN_SECONDS );
		$jobs       = $this->all();
		$kept       = array();
		$removed    = 0;
		$removed_ids = array();

		foreach ( $jobs as $job ) {
			$status = (string) ( $job['status'] ?? '' );
			if ( Job_Limits::is_terminal( $status ) ) {
				$finished = strtotime( (string) ( $job['finished_at'] ?? $job['updated_at'] ?? '' ) );
				if ( false !== $finished && $finished < $cutoff ) {
					$removed++;
					$removed_ids[] = (string) ( $job['job_id'] ?? '' );
					continue;
				}
			}
			$kept[] = $job;
		}

		foreach ( $removed_ids as $pruned_id ) {
			$this->delete_payloads( $pruned_id );
		}
		if ( $removed > 0 ) {
			$this->store( $kept );
		}
		return $removed;
	}
}
