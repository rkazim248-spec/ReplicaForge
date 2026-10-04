<?php
/**
 * Phase 11: stuck job detection and recovery.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Finds jobs whose worker died, and decides what should happen to each.
 *
 * Phase 7 handles a dead worker correctly for the *common* case: `claimable()`
 * treats a `running` job with an expired lease as claimable again, so the work is
 * picked up. That is right for a job that had not yet written anything.
 *
 * It is wrong for a job that had. §28 is explicit that a job must not be restarted
 * automatically once it may have modified an Elementor draft, and the
 * lease-expiry path has no way to know whether it did. So this class introduces
 * the missing question: **is it safe to run this again?**
 *
 * The answer comes from what the job's checkpoint says it has done, because that
 * is the only durable record of how far it got:
 *
 * | Checkpoint says | Safe to re-run? | Action |
 * |---|---|---|
 * | Nothing beyond `analyze` | Yes | `retrying`, with backoff |
 * | `generate` reached | No | `expired`, awaiting an explicit retry |
 * | `correct` reached | No | `expired`, awaiting an explicit retry |
 *
 * A job that reached `generate` may have created or partly written a draft. Re-running
 * it would generate a second draft, or apply a second set of changes to the first.
 * That is a decision for a person, and the state says so.
 *
 * `expired` is therefore terminal, and moving out of it requires an explicit
 * `retry()`, which is a different operation with a different risk and a different
 * audit trail.
 */
final class Job_Recovery {

	/**
	 * Stages beyond which re-running a job is not automatically safe.
	 *
	 * `generate` is the first, because it is the first stage that writes to a
	 * document. `validate` and `correct` are included because a validation writes
	 * results and a correction writes to the same document `generate` does.
	 *
	 * @var array<int, string>
	 */
	const UNSAFE_FROM = array( 'generate', 'validate', 'correct' );

	/**
	 * How long a lease may be overdue before a job is considered stuck, in seconds.
	 *
	 * The Phase 7 lease is 300 seconds. One lease period of grace means a worker
	 * that is merely slow is not declared dead, while a worker that is gone is
	 * found within five minutes rather than at the next manual look.
	 */
	const STUCK_GRACE = 300;

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
	 * @param Job_Repository|null $jobs  Optional repository.
	 * @param Logger|null         $logger Optional logger.
	 */
	public function __construct( $jobs = null, $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
		$this->jobs   = $jobs instanceof Job_Repository ? $jobs : new Job_Repository( $this->logger );
	}

	/**
	 * Return whether a job's work may be safely attempted again.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return bool
	 */
	public function is_resumable( array $job ) {
		return ! in_array( $this->reached_stage( $job ), self::UNSAFE_FROM, true );
	}

	/**
	 * Return how far a job actually got.
	 *
	 * ### Why this reads the job, not the checkpoint
	 *
	 * `is_resumable()` used to read `Job_Checkpoint::sanitize( $job['checkpoint'] )['stage']`.
	 * That key is never written.
	 *
	 * `Job_Queue::advance()` stores a stage's data *under that stage's name* -
	 * `$merged[ $current ] = $checkpoint` - so the persisted shape is
	 * `array( 'analyze' => array( ... ), 'generate' => array( ... ) )`. It never produces a
	 * top-level `stage`. `sanitize()` therefore always fell through to its default,
	 * `Job_Limits::STAGES[0]`, which is `queued` - a stage that is not in `UNSAFE_FROM`.
	 *
	 * So the guard was always true. A job killed during `generate` - the stage that creates
	 * an Elementor draft - was declared safe to replay and was replayed automatically, which
	 * is precisely the case `UNSAFE_FROM` exists to stop. A job interrupted during `validate`
	 * or `correct` was replayed the same way.
	 *
	 * The job's own `stage` column is the authoritative record of how far it got: `claim()`
	 * only hands out a job whose stage is current, and `advance()` moves it forward after
	 * each stage completes. So that is what is read now, with the checkpoint consulted only
	 * for a stage it genuinely carries.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return string
	 */
	private function reached_stage( array $job ) {
		$stages    = Job_Limits::STAGES;
		$positions = array_flip( $stages );

		$from_job = (string) ( $job['stage'] ?? '' );
		$checkpoint = (array) ( $job['checkpoint'] ?? array() );
		$from_checkpoint = (string) ( $checkpoint['stage'] ?? '' );

		$job_at        = isset( $positions[ $from_job ] ) ? (int) $positions[ $from_job ] : -1;
		$checkpoint_at = isset( $positions[ $from_checkpoint ] ) ? (int) $positions[ $from_checkpoint ] : -1;

		/*
		 * The *furthest* of the two, not simply the job's own column.
		 *
		 * The job's `stage` column is authoritative in normal operation, and it is normally
		 * the furthest point reached. But a job record written by an older version - or one
		 * whose checkpoint outlived a stage reset - can carry a `stage` that is behind the
		 * checkpoint. Taking the furthest means the guard errs towards "needs review", which
		 * is the safe direction: a false refusal costs one manual decision, a false "resumable"
		 * costs a replayed draft generation.
		 */
		if ( $checkpoint_at > $job_at ) {
			return $from_checkpoint;
		}

		if ( $job_at >= 0 ) {
			return $from_job;
		}

		return Job_Limits::STAGES[0];
	}

	/**
	 * Return why a job is or is not resumable, in words.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return array<string, mixed>
	 */
	public function explain( array $job ) {
		$resumable = $this->is_resumable( $job );
		$stage     = $this->reached_stage( $job );

		if ( $resumable ) {
			return array(
				'resumable' => true,
				'code'      => 'resumable',
				'message'   => __( 'This job can be picked up again where it stopped.', 'replicaforge' ),
			);
		}

		return array(
			'resumable' => false,
			'code'      => 'manual_review_required',
			'message'   => sprintf(
				/* translators: %s: the stage the job had reached. */
				__( 'This job reached %s, which may have changed the draft. Retrying it is a manual decision.', 'replicaforge' ),
				Job_Checkpoint::stage_label( $stage )
			),
		);
	}

	/**
	 * Find jobs that look stuck.
	 *
	 * A job is stuck when it is in an active state, its lease is well past due, and
	 * nothing has updated it since. The grace period is what stops a slow worker
	 * being declared dead.
	 *
	 * @param int $now Optional current time.
	 * @return array<int, array<string, mixed>>
	 */
	public function stuck( $now = 0 ) {
		$now  = ( 0 === (int) $now ) ? time() : (int) $now;
		$out  = array();

		foreach ( $this->jobs->all() as $job ) {
			$status = (string) ( $job['status'] ?? '' );
			if ( ! Job_States::is_active( $status ) ) {
				continue;
			}

			$lease = (int) ( $job['lease_until'] ?? 0 );
			if ( $lease <= 0 || $lease >= ( $now - self::STUCK_GRACE ) ) {
				continue;
			}

			$out[] = array(
				'job_id'      => (string) $job['job_id'],
				'type'        => (string) ( $job['type'] ?? '' ),
				'status'      => $status,
				'stage'       => (string) ( Job_Checkpoint::sanitize( (array) ( $job['checkpoint'] ?? array() ) )['stage'] ),
				'lease_until' => $lease,
				'overdue_for' => max( 0, $now - $lease ),
				'resumable'   => $this->is_resumable( $job ),
			);
		}

		return $out;
	}

	/**
	 * Act on stuck jobs.
	 *
	 * Resumable jobs go to `retrying` with a backoff, so the next tick picks them
	 * up. Everything else goes to `expired`, which is terminal and waits for a
	 * person.
	 *
	 * @param int $max Maximum jobs to touch in one pass.
	 * @return array{scanned: int, retried: int, expired: int, job_ids: array<int, string>}
	 */
	public function sweep( $max = 25 ) {
		$max   = max( 1, min( 200, (int) $max ) );
		$stuck = $this->stuck();
		$out   = array(
			'scanned' => count( $stuck ),
			'retried' => 0,
			'expired' => 0,
			'job_ids' => array(),
		);

		foreach ( array_slice( $stuck, 0, $max ) as $entry ) {
			$job_id = (string) $entry['job_id'];

			if ( ! empty( $entry['resumable'] ) ) {
				$attempts = (int) ( ( $this->jobs->find( $job_id ) )['attempts'] ?? 1 );
				$updated  = $this->jobs->update(
					$job_id,
					array(
						'status'       => Job_States::RETRYING,
						'lease_until'  => 0,
						'next_attempt' => time() + Job_Limits::backoff_seconds( $attempts ),
						'recovered_at' => gmdate( 'c' ),
					)
				);
				if ( null !== $updated ) {
					$out['retried']++;
					$out['job_ids'][] = $job_id;

					$this->logger->warning(
						'job_worker_lost',
						'A job was left running by a worker that stopped. It will be picked up again.',
						array(
							'job_id'  => $job_id,
							'stage'   => (string) $entry['stage'],
							'overdue' => (int) $entry['overdue_for'],
						),
						'job'
					);
				}
				continue;
			}

			$updated = $this->jobs->update(
				$job_id,
				array(
					'status'       => Job_States::EXPIRED,
					'lease_until'  => 0,
					'finished_at'  => gmdate( 'c' ),
					'recovered_at' => gmdate( 'c' ),
				)
			);
			if ( null !== $updated ) {
				$out['expired']++;
				$out['job_ids'][] = $job_id;

				$this->logger->error(
					'job_expired_after_write',
					'A job stopped after it had begun writing, so it was expired rather than retried. Retrying it is a manual decision.',
					array(
						'job_id' => $job_id,
						'stage'  => (string) $entry['stage'],
					),
					'job'
				);

				/**
				 * Fires after a job is expired because a worker died mid-write.
				 *
				 * @param string               $job_id Job identifier.
				 * @param array<string, mixed> $entry What was known about the job.
				 */
				do_action( 'replicaforge_job_expired', $job_id, $entry );
			}
		}

		return $out;
	}

	/**
	 * Return a report of the recovery situation, for diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	public function report() {
		$stuck = $this->stuck();

		$by_stage = array();
		foreach ( $stuck as $entry ) {
			$stage          = (string) $entry['stage'];
			$by_stage[ $stage ] = ( $by_stage[ $stage ] ?? 0 ) + 1;
		}
		ksort( $by_stage );

		return array(
			'stuck_total'    => count( $stuck ),
			'stuck_resumable' => count(
				array_filter(
					$stuck,
					static function ( $entry ) {
						return ! empty( $entry['resumable'] );
					}
				)
			),
			'by_stage'       => $by_stage,
			'job_ids'        => array_values( array_map( 'strval', wp_list_pluck( $stuck, 'job_id' ) ) ),
		);
	}
}
