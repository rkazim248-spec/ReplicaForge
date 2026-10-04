<?php
/**
 * Phase 7: job queueing and idempotency.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts work and guarantees it is only accepted once.
 *
 * The duplicate-draft problem is solved here rather than in the draft service,
 * because the queue is the only place that knows a request has already been
 * accepted. Two clicks produce one job; the second returns the first.
 */
final class Job_Queue {

	/**
	 * Option holding idempotency records.
	 */
	const OPTION = 'replicaforge_idempotency';

	/**
	 * Job repository.
	 *
	 * @var Job_Repository
	 */
	private $jobs;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Job_Repository|null $jobs   Optional repository.
	 * @param Logger|null         $logger Optional logger.
	 */
	public function __construct( $jobs = null, $logger = null ) {
		$this->jobs   = $jobs instanceof Job_Repository ? $jobs : new Job_Repository( $logger );
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Return the key a request is deduplicated by.
	 *
	 * The key is derived from the job type and the work itself, so the same
	 * request replayed produces the same key, while a genuinely different request
	 * does not collide with it.
	 *
	 * @param string               $type       Job type.
	 * @param array<string, mixed> $params     Job parameters.
	 * @param string               $idempotency Optional client-supplied key.
	 * @return string
	 */
	public function key_for( $type, array $params, $idempotency = '' ) {
		$idempotency = is_string( $idempotency ) ? trim( $idempotency ) : '';
		if ( '' !== $idempotency ) {
			// A client-supplied key is namespaced by type so two different workflows
			// cannot collide by reusing a key.
			return 'k_' . hash( 'sha256', $type . '|' . $idempotency );
		}
		$material = array(
			'type'        => (string) $type,
			'source_url'  => isset( $params['source_url'] ) ? (string) $params['source_url'] : '',
			'draft_id'    => isset( $params['draft_id'] ) ? (int) $params['draft_id'] : 0,
			'validation'  => isset( $params['validation_id'] ) ? (string) $params['validation_id'] : '',
			'spec_id'     => isset( $params['specification_id'] ) ? (string) $params['specification_id'] : '',
		);
		return 'k_' . hash( 'sha256', wp_json_encode( $material ) );
	}

	/**
	 * Queue a job, or return the job an identical request already created.
	 *
	 * @param string               $type        Job type.
	 * @param array<string, mixed> $params      Job parameters.
	 * @param array<string, mixed> $options     Optional `idempotency_key`, `start`.
	 * @return array<string, mixed>
	 */
	public function enqueue( $type, array $params, array $options = array() ) {
		$key = $this->key_for( $type, $params, isset( $options['idempotency_key'] ) ? (string) $options['idempotency_key'] : '' );

		$existing = $this->resolve( $key );
		if ( null !== $existing ) {
			$this->logger->info(
				'job_deduplicated',
				'An identical request was already queued, so the existing job was returned.',
				array( 'job_id' => (string) $existing['job_id'] ),
				'job'
			);
			return array(
				'job'       => $existing,
				'duplicate' => true,
			);
		}

		$job = $this->jobs->create( $type, $params, 'queued' );
		$this->remember( $key, (string) $job['job_id'] );

		if ( ! empty( $options['start'] ) ) {
			$job = $this->start( (string) $job['job_id'] );
		}

		return array(
			'job'       => $job,
			'duplicate' => false,
		);
	}

	/**
	 * Return the job an idempotency key points at, when it is still usable.
	 *
	 * A finished job is not reused: the user asked for new work, and returning the
	 * previous result would silently do nothing.
	 *
	 * @param string $key Idempotency key.
	 * @return array<string, mixed>|null
	 */
	public function resolve( $key ) {
		$key = is_string( $key ) ? $key : '';
		if ( '' === $key ) {
			return null;
		}
		$records = $this->records();
		if ( ! isset( $records[ $key ] ) ) {
			return null;
		}
		$record = $records[ $key ];
		if ( (int) ( $record['expires'] ?? 0 ) < time() ) {
			unset( $records[ $key ] );
			$this->save_records( $records );
			return null;
		}
		$job = $this->jobs->find( (string) ( $record['job_id'] ?? '' ) );
		if ( null === $job ) {
			unset( $records[ $key ] );
			$this->save_records( $records );
			return null;
		}
		if ( Job_Limits::is_terminal( (string) ( $job['status'] ?? '' ) ) ) {
			return null;
		}
		return $job;
	}

	/**
	 * Claim a job for a worker, taking its lease.
	 *
	 * The lease is what makes a crashed job recoverable: a worker that dies never
	 * releases it, so it expires and another worker may take over.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>|null
	 */
	public function claim( $job_id ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return null;
		}
		$status = (string) ( $job['status'] ?? '' );
		if ( Job_Limits::is_terminal( $status ) ) {
			return null;
		}

		$now  = time();
		$held = (int) ( $job['lease_until'] ?? 0 );
		if ( Job_Limits::STATUSES['running'] === $status && $held > $now ) {
			// Another worker still holds the lease.
			return null;
		}

		$updated = $this->jobs->update(
			$job_id,
			array(
				'status'       => Job_Limits::STATUSES['running'],
				'lease_until'  => $now + Job_Limits::LEASE_SECONDS,
				'attempts'     => (int) ( $job['attempts'] ?? 0 ) + 1,
				'started_at'   => '' === (string) ( $job['started_at'] ?? '' ) ? gmdate( 'c' ) : (string) $job['started_at'],
				'next_attempt' => 0,
			)
		);

		Request_Context::set( 'job', (string) $job_id );
		return $updated;
	}

	/**
	 * Extend a job's lease so a long stage is not stolen mid-flight.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public function heartbeat( $job_id ) {
		$this->jobs->update(
			$job_id,
			array( 'lease_until' => time() + Job_Limits::LEASE_SECONDS )
		);
	}

	/**
	 * Move a job to its next stage.
	 *
	 * @param string               $job_id   Job identifier.
	 * @param string|null          $stage    Explicit next stage, or the declared successor.
	 * @param array<string, mixed> $checkpoint Checkpoint data to store with the stage.
	 * @return array<string, mixed>|null
	 */
	public function advance( $job_id, $stage = null, array $checkpoint = array() ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return null;
		}
		$current = (string) ( $job['stage'] ?? 'queued' );
		$next    = is_string( $stage ) && in_array( $stage, Job_Limits::STAGES, true )
			? $stage
			: Job_Limits::next_stage( $current );

		if ( '' === $next ) {
			return $this->complete( $job_id );
		}

		$merged = is_array( $job['checkpoint'] ?? null ) ? $job['checkpoint'] : array();
		if ( ! empty( $checkpoint ) ) {
			$merged[ $current ] = $checkpoint;
		}

		return $this->jobs->update(
			$job_id,
			array(
				'stage'       => $next,
				'progress'    => Job_Limits::stage_progress( $next ),
				'checkpoint'  => $this->bound_checkpoint( $merged ),
				'lease_until' => time() + Job_Limits::LEASE_SECONDS,
				'updated_at'  => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Mark a job completed.
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string, mixed> $result Bounded result.
	 * @return array<string, mixed>|null
	 */
	public function complete( $job_id, array $result = array() ) {
		$updated = $this->jobs->update(
			$job_id,
			array(
				'status'      => Job_Limits::STATUSES['completed'],
				'stage'       => 'finalize',
				'progress'    => 100,
				'result'      => Data_Redactor::payload( $result ),
				'error'       => null,
				'lease_until' => 0,
				'finished_at' => gmdate( 'c' ),
			)
		);
		if ( null !== $updated ) {
			$this->logger->info( 'job_completed', 'The job finished successfully.', array( 'job_id' => (string) $job_id ), 'job' );
		}
		return $updated;
	}

	/**
	 * Mark a job failed.
	 *
	 * A retryable failure is rescheduled with a backoff rather than retried
	 * immediately, so a provider outage does not turn into a tight loop.
	 *
	 * @param string               $job_id Job identifier.
	 * @param string               $code   Error code.
	 * @param string               $message User-facing message.
	 * @param array<string, mixed> $context Internal context.
	 * @return array<string, mixed>|null
	 */
	public function fail( $job_id, $code, $message = '', array $context = array() ) {
		$job    = $this->jobs->find( $job_id );
		$detail = Error_Catalog::describe( $code, $message );
		if ( null === $job ) {
			return null;
		}

		$attempts = (int) ( $job['attempts'] ?? 0 );
		$will_retry = ! empty( $detail['retryable'] ) && $attempts < Job_Limits::MAX_ATTEMPTS;

		$changes = array(
			// A job that will be tried again is `retrying`, not `queued`. Both were
			// the same thing before Phase 11, which meant a job waiting fifteen
			// minutes looked identical to a job that had never run and a dashboard
			// could not answer "why is nothing happening".
			'status'      => $will_retry ? Job_States::RETRYING : Job_States::FAILED,
			'error'       => $detail,
			'lease_until' => 0,
		);
		if ( $will_retry ) {
			// A provider that told us how long to wait is obeyed exactly. Our own
			// backoff applies only when the provider did not say. Honouring our own
			// schedule over an explicit request is how a rate limit turns into an
			// outage, and ignoring the request is how a provider gets hammered.
			$retry_after = isset( $context['retry_after'] ) ? (int) $context['retry_after'] : 0;
			if ( $retry_after > 0 ) {
				$wait                        = min( Ai_Failures::MAX_RETRY_AFTER, $retry_after );
				$changes['next_attempt']     = time() + $wait;
				$changes['retry_after']      = $wait;
			} else {
				$wait                        = Job_Limits::backoff_seconds( $attempts, (string) $job_id );
				$changes['next_attempt']     = time() + $wait;
				$changes['retry_after']      = 0;
			}
		} else {
			$changes['finished_at'] = gmdate( 'c' );
		}

		$updated = $this->jobs->update( $job_id, $changes );
		$this->logger->log(
			'critical' === (string) $detail['severity'] ? 'critical' : 'error',
			$will_retry ? 'job_retry_scheduled' : 'job_failed',
			(string) $detail['message'],
			array_merge( array( 'job_id' => (string) $job_id, 'code' => (string) $detail['internal_code'], 'attempt' => $attempts ), $context ),
			(string) $detail['category']
		);

		return $updated;
	}

	/**
	 * Pause a job at its current stage.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>|null
	 */
	public function pause( $job_id ) {
		return $this->jobs->update(
			$job_id,
			array(
				'status'      => Job_Limits::STATUSES['paused'],
				'lease_until' => 0,
			)
		);
	}

	/**
	 * Resume a paused or failed job from its checkpoint.
	 *
	 * The job restarts at the stage it reached, not from the beginning, so an
	 * expensive analysis is not repeated after a late failure.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>
	 */
	public function resume( $job_id ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return array( 'success' => false, 'error' => Error_Catalog::describe( 'job_not_found' ) );
		}
		$status = (string) ( $job['status'] ?? '' );
		if ( ! in_array( $status, array( Job_Limits::STATUSES['paused'], Job_Limits::STATUSES['failed'], Job_Limits::STATUSES['cancelled'] ), true ) ) {
			return array(
				'success' => false,
				'error'   => Error_Catalog::describe( 'job_not_resumable' ),
			);
		}
		if ( (int) ( $job['attempts'] ?? 0 ) >= Job_Limits::MAX_ATTEMPTS ) {
			return array( 'success' => false, 'error' => Error_Catalog::describe( 'job_not_resumable' ) );
		}

		$updated = $this->jobs->update(
			$job_id,
			array(
				'status'       => Job_Limits::STATUSES['queued'],
				'error'        => null,
				'next_attempt' => 0,
				'lease_until'  => 0,
			)
		);

		$this->logger->info(
			'job_resumed',
			'The job was resumed from the stage it had reached.',
			array( 'job_id' => (string) $job_id, 'stage' => (string) ( $job['stage'] ?? 'queued' ) ),
			'job'
		);

		return array( 'success' => true, 'job' => $updated );
	}

	/**
	 * Cancel a job.
	 *
	 * A running job is marked cancelled; the worker checks the status between
	 * stages and stops. Cancelling never deletes a draft that already exists,
	 * because the user's work is not ReplicaForge's to discard.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>
	 */
	public function cancel( $job_id ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return array( 'success' => false, 'error' => Error_Catalog::describe( 'job_not_found' ) );
		}
		if ( Job_Limits::is_terminal( (string) ( $job['status'] ?? '' ) ) ) {
			return array( 'success' => false, 'error' => Error_Catalog::describe( 'job_cancelled' ) );
		}
		$updated = $this->jobs->update(
			$job_id,
			array(
				'status'      => Job_Limits::STATUSES['cancelled'],
				'lease_until' => 0,
				'finished_at' => gmdate( 'c' ),
			)
		);
		$this->logger->info( 'job_cancelled', 'The job was cancelled.', array( 'job_id' => (string) $job_id ), 'job' );
		return array( 'success' => true, 'job' => $updated );
	}

	/**
	 * Start a job, which for ReplicaForge means making it claimable.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>|null
	 */
	public function start( $job_id ) {
		return $this->jobs->update( $job_id, array( 'next_attempt' => 0 ) );
	}

	/**
	 * Append a warning to a job without failing it.
	 *
	 * @param string $job_id  Job identifier.
	 * @param string $message Warning text.
	 * @return void
	 */
	public function warn( $job_id, $message ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return;
		}
		$warnings   = (array) ( $job['warnings'] ?? array() );
		$warnings[] = Data_Redactor::text( $message );
		$this->jobs->update( $job_id, array( 'warnings' => array_slice( $warnings, -20 ) ) );
	}

	/**
	 * Forget idempotency records that have expired.
	 *
	 * @return int Number removed.
	 */
	public function prune_records() {
		$now     = time();
		$records = $this->records();
		$kept    = array();
		$removed = 0;
		foreach ( $records as $key => $record ) {
			if ( (int) ( $record['expires'] ?? 0 ) < $now ) {
				$removed++;
				continue;
			}
			$kept[ $key ] = $record;
		}
		if ( $removed > 0 ) {
			$this->save_records( $kept );
		}
		return $removed;
	}

	/**
	 * Return the stored idempotency records.
	 *
	 * @return array<string, array<string, int>>
	 */
	private function records() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Persist idempotency records.
	 *
	 * @param array<string, array<string, int>> $records Records.
	 * @return void
	 */
	private function save_records( array $records ) {
		if ( count( $records ) > 500 ) {
			uasort(
				$records,
				static function ( $left, $right ) {
					return (int) ( $left['expires'] ?? 0 ) <=> (int) ( $right['expires'] ?? 0 );
				}
			);
			$records = array_slice( $records, -500, null, true );
		}
		update_option( self::OPTION, $records, false );
	}

	/**
	 * Record that a key maps to a job.
	 *
	 * @param string $key    Idempotency key.
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private function remember( $key, $job_id ) {
		$records = $this->records();
		$records[ $key ] = array(
			'job_id'  => (string) $job_id,
			'expires' => time() + Job_Limits::IDEMPOTENCY_TTL,
		);
		$this->save_records( $records );
	}

	/**
	 * Bound the size of a checkpoint.
	 *
	 * Checkpoints hold results of earlier stages. They are kept small so a job
	 * record never becomes a place large documents accumulate.
	 *
	 * @param array<string, mixed> $checkpoint Checkpoint.
	 * @return array<string, mixed>
	 */
	private function bound_checkpoint( array $checkpoint ) {
		$out = array();
		foreach ( $checkpoint as $stage => $data ) {
			if ( ! is_string( $stage ) ) {
				continue;
			}
			$out[ $stage ] = Data_Redactor::structure( $data );
		}
		if ( count( $out ) > 20 ) {
			$out = array_slice( $out, -20, null, true );
		}
		return $out;
	}
}
