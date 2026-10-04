<?php
/**
 * Phase 11: cooperative cancellation.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a user stop a job without corrupting what it was doing.
 *
 * The temptation is to make cancellation a flag on the job record and check it
 * between stages. That is what this does, and the whole difficulty is in *where*
 * the check goes and what happens on either side of it.
 *
 * A running stage cannot be interrupted — PHP has no safe way to stop a function
 * mid-execution, and killing the process is what a crashed worker already looks
 * like. So cancellation is **cooperative**: the request is recorded, and the
 * worker notices at its next checkpoint. §29 asks for exactly this.
 *
 * What makes it safe is the pairing with a lock and a checkpoint:
 *
 * - A **write** stage checks for cancellation *before* it writes, and if a cancel
 *   arrived it declines to start writing at all. There is no state in which a
 *   cancellation is honoured halfway through an Elementor write.
 * - The worker then **releases its locks** and marks the job `cancelled`, so the
 *   project is immediately available to the next job rather than staying locked
 *   until the TTL.
 * - A stage that had already written does not roll back here. Rolling back is
 *   {@see Correction_Applier}'s job, and it already does it. Cancellation reports
 *   what happened; it does not invent a second undo path.
 *
 * The flag lives in its own option rather than on the job record, because the flag
 * has a different lifetime: it must survive the job being re-read into memory, and
 * it has to be readable by a worker that has the job but not the repository.
 */
final class Job_Cancellation {

	/**
	 * Option holding cancellation requests.
	 *
	 * A single option, pruned aggressively. A cancellation is only interesting
	 * while the job is alive, and a job that is dead cannot be cancelled, so
	 * anything older than a day is meaningless.
	 */
	const OPTION = 'replicaforge_job_cancellations';

	/**
	 * Maximum age of a cancellation request, in seconds.
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * Maximum requests kept at once.
	 */
	const MAX_REQUESTS = 100;

	/**
	 * Record a cancellation request.
	 *
	 * Recording is separate from acting, and the separation is the point: a request
	 * that arrives while a write stage is mid-flight is recorded, and the stage
	 * picks it up at its next boundary. Marking the job `cancelled` immediately
	 * would produce a job that says it is cancelled while its worker is still
	 * writing.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $by     Who asked.
	 * @return bool
	 */
	public function request( $job_id, $by = '' ) {
		$job_id = $this->clean_job_id( $job_id );
		if ( '' === $job_id ) {
			return false;
		}

		$requests          = $this->requests();
		$requests[ $job_id ] = array(
			'requested_at' => time(),
			'requested_by' => ( is_scalar( $by ) && '' !== trim( (string) $by ) ) ? (int) $by : 0,
		);

		// Oldest first, so the trim drops the oldest.
		asort( $requests );

		$this->store( array_slice( $requests, -self::MAX_REQUESTS, null, true ) );

		/**
		 * Fires when a cancellation is requested.
		 *
		 * @param string $job_id Job identifier.
		 * @param int    $by     Requesting user, or 0.
		 */
		do_action( 'replicaforge_job_cancel_requested', $job_id, (int) $by );

		return true;
	}

	/**
	 * Return whether a cancellation has been requested for a job.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool
	 */
	public function is_requested( $job_id ) {
		$requests = $this->requests();
		return isset( $requests[ $this->clean_job_id( $job_id ) ] );
	}

	/**
	 * Return the request record for a job.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>|null
	 */
	public function request_record( $job_id ) {
		$requests = $this->requests();
		$job_id   = $this->clean_job_id( $job_id );
		return isset( $requests[ $job_id ] ) ? $requests[ $job_id ] : null;
	}

	/**
	 * Return whether a stage may proceed, given a pending cancellation.
	 *
	 * This is the call a worker makes at a stage boundary. It reads the request
	 * rather than the job's status, because the job's status is only updated once
	 * the worker has actually stopped — and a worker deciding whether to stop must
	 * not depend on itself already having stopped.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True when the stage may proceed.
	 */
	public function may_proceed( $job_id ) {
		return ! $this->is_requested( $job_id );
	}

	/**
	 * Clear a cancellation request.
	 *
	 * Called when a job completes or is resumed, so a stale request does not cancel
	 * a job that has since been retried under a new attempt.
	 *
	 * @param string $job_id Job identifier.
	 * @return bool Whether a request was removed.
	 */
	public function clear( $job_id ) {
		$job_id = $this->clean_job_id( $job_id );
		if ( '' === $job_id ) {
			return false;
		}

		$requests = $this->requests();
		if ( ! isset( $requests[ $job_id ] ) ) {
			return false;
		}

		unset( $requests[ $job_id ] );
		$this->store( $requests );

		return true;
	}

	/**
	 * Return every pending request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all() {
		return $this->requests();
	}

	/**
	 * Drop requests older than the TTL.
	 *
	 * @return int Number removed.
	 */
	public function prune() {
		$requests = $this->requests();
		$now      = time();
		$removed  = 0;

		foreach ( $requests as $job_id => $record ) {
			if ( (int) ( $record['requested_at'] ?? 0 ) < ( $now - self::TTL ) ) {
				unset( $requests[ $job_id ] );
				$removed++;
			}
		}

		if ( $removed > 0 ) {
			$this->store( $requests );
		}

		return $removed;
	}

	/**
	 * Return the stored requests, dropping anything expired.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function requests() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$now   = time();
		$clean = array();
		foreach ( $stored as $job_id => $record ) {
			$job_id = $this->clean_job_id( (string) $job_id );
			if ( '' === $job_id || ! is_array( $record ) ) {
				continue;
			}
			if ( (int) ( $record['requested_at'] ?? 0 ) < ( $now - self::TTL ) ) {
				continue;
			}
			$clean[ $job_id ] = array(
				'requested_at' => (int) ( $record['requested_at'] ?? 0 ),
				'requested_by' => (int) ( $record['requested_by'] ?? 0 ),
			);
		}

		return $clean;
	}

	/**
	 * Store the request map.
	 *
	 * @param array<string, array<string, mixed>> $requests Requests.
	 * @return void
	 */
	private function store( array $requests ) {
		if ( array() === $requests ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $requests, false );
	}

	/**
	 * Reduce a job identifier to something storable.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_job_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 40 ) {
			return '';
		}
		return preg_match( '/^[A-Za-z0-9_]+$/', $value ) ? $value : '';
	}
}
