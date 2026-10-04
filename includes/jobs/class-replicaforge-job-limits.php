<?php
/**
 * Phase 7: job vocabulary.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The statuses, stages, and job types ReplicaForge uses.
 *
 * Declared in one place so a job, the progress UI, the REST contract, and the
 * history screen cannot disagree about what a status means.
 */
final class Job_Limits {

	/**
	 * Job statuses.
	 *
	 * Built from {@see Job_States} rather than restated, because Phase 11 added five
	 * states and two definitions of the same list would drift. The drift would not
	 * be visible until a job reached a state that one class accepted and another
	 * refused — a `retrying` job that the REST layer treats as an invalid filter,
	 * say.
	 *
	 * The six Phase 7 statuses are unchanged and keep their meaning. The five added
	 * ones are described in `Job_States`.
	 *
	 * @var array<string, string>
	 */
	const STATUSES = array(
		Job_States::QUEUED    => Job_States::QUEUED,
		Job_States::RESERVED  => Job_States::RESERVED,
		Job_States::RUNNING   => Job_States::RUNNING,
		Job_States::WAITING   => Job_States::WAITING,
		Job_States::RETRYING  => Job_States::RETRYING,
		Job_States::PAUSED    => Job_States::PAUSED,
		Job_States::COMPLETED => Job_States::COMPLETED,
		Job_States::FAILED    => Job_States::FAILED,
		Job_States::CANCELLED => Job_States::CANCELLED,
		Job_States::EXPIRED   => Job_States::EXPIRED,
		Job_States::BLOCKED   => Job_States::BLOCKED,
	);

	/**
	 * Statuses from which no further work happens.
	 *
	 * `expired` is terminal for a reason worth restating, because it is the one that
	 * looks like an oversight: a job whose lease died may already have modified an
	 * Elementor document, so re-running it automatically could apply a second set of
	 * changes to a half-written draft. Expiry ends the attempt; resuming is a
	 * separate, explicit, separately audited decision.
	 *
	 * @var array<int, string>
	 */
	const TERMINAL = array(
		Job_States::COMPLETED,
		Job_States::FAILED,
		Job_States::CANCELLED,
		Job_States::EXPIRED,
	);

	/**
	 * Stages a full replica job moves through, in order.
	 *
	 * Progress is derived from which stage a job has reached, never invented, so a
	 * stalled job shows a stalled bar rather than a moving one.
	 *
	 * @var array<int, string>
	 */
	const STAGES = array(
		'queued',
		'analyze',
		'design',
		'ai',
		'generate',
		'validate',
		'correct',
		'finalize',
	);

	/**
	 * Job types.
	 *
	 * @var array<string, string>
	 */
	const TYPES = array(
		'replica'      => 'replica',
		'generation'   => 'generation',
		'validation'   => 'validation',
		'correction'   => 'correction',
	);

	/**
	 * Attempts allowed before a job is marked failed.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Base seconds for retry backoff. Each attempt multiplies this.
	 */
	const RETRY_BASE_SECONDS = 30;

	/**
	 * Maximum backoff between attempts, so a long outage does not push a retry
	 * hours into the future.
	 */
	const RETRY_MAX_SECONDS = 900;

	/**
	 * Seconds a running job may hold its lease before another worker may take it.
	 *
	 * A worker killed by a time limit never releases the lease, so this is what
	 * makes a crashed job recoverable rather than permanently stuck.
	 */
	const LEASE_SECONDS = 300;

	/**
	 * Jobs retained per site before the oldest finished ones are pruned.
	 */
	const MAX_JOBS = 100;

	/**
	 * Seconds an idempotency key is honoured, so a double click reuses the first
	 * job instead of starting a second one.
	 */
	const IDEMPOTENCY_TTL = 900;

	/**
 * Return the weight of each stage, for a truthful progress percentage.
	 *
	 * Later stages cost more, so the bar advances in proportion to real work rather
	 * than by one equal step per stage.
	 *
	 * @return array<string, int>
	 */
	public static function stage_weights() {
		return array(
			'queued'    => 0,
			'analyze'   => 20,
			'design'    => 15,
			'ai'        => 20,
			'generate'  => 20,
			'validate'  => 15,
			'correct'   => 8,
			'finalize'  => 2,
		);
	}

	/**
	 * Return the percentage a job has reached from its stage alone.
	 *
	 * @param string $stage Current stage.
	 * @return int
	 */
	public static function stage_progress( $stage ) {
		$weights = self::stage_weights();
		$done    = 0;
		foreach ( $weights as $name => $weight ) {
			if ( $name === (string) $stage ) {
				break;
			}
			$done += (int) $weight;
		}
		return max( 0, min( 100, (int) $done ) );
	}

	/**
	 * Return the index of a stage, or -1.
	 *
	 * @param string $stage Stage name.
	 * @return int
	 */
	public static function stage_index( $stage ) {
		$index = array_search( (string) $stage, self::STAGES, true );
		return false === $index ? -1 : (int) $index;
	}

	/**
	 * Return the stage that follows one, or an empty string at the end.
	 *
	 * @param string $stage Current stage.
	 * @return string
	 */
	public static function next_stage( $stage ) {
		$index = self::stage_index( $stage );
		if ( $index < 0 || $index >= count( self::STAGES ) - 1 ) {
			return '';
		}
		return (string) self::STAGES[ $index + 1 ];
	}

	/**
	 * Return the seconds to wait before the next attempt.
	 *
	 * Exponential backoff, capped, so a provider outage does not turn into a tight
	 * retry loop that makes the outage worse.
	 *
	 * @param int $attempt Attempt number, starting at 1.
	 * @return int
	 */
	public static function backoff_seconds( $attempt, $seed = '' ) {
		$attempt = max( 1, (int) $attempt );
		$wait    = self::RETRY_BASE_SECONDS * ( 2 ** ( $attempt - 1 ) );
		$wait    = (int) min( self::RETRY_MAX_SECONDS, $wait );

		return self::apply_jitter( $wait, (string) $seed . '|' . (string) $attempt );
	}

	/**
	 * Return a backoff delay with jitter applied, still inside the cap.
	 *
	 * The jitter is the part that matters, and it is the part that was missing.
	 * Without it every job that failed at the same moment retries at the same
	 * moment, forever: a provider goes down at 14:00, forty jobs fail at 14:00, and
	 * all forty come back at 14:00:30 to find it still down. That is how a brief
	 * provider outage turns into a sustained one, and it is a property of the
	 * *plugin*, not of the provider.
	 *
	 * Two properties of the window matter:
	 *
	 * It is the **upper** half of the interval, so a delay is never shorter than
	 * the computed backoff. Full jitter — retrying immediately half the time — would
	 * be wrong here, because the backoff exists because the provider asked for
	 * distance and a jitter that sometimes removes the distance defeats it.
	 *
	 * And the result is **clamped to `RETRY_MAX_SECONDS` afterwards**, so the
	 * constant remains the longest ReplicaForge will ever wait. At the ceiling the
	 * jitter becomes a no-op, which is correct: a job already waiting the maximum
	 * should not be made to wait longer, and a user who has waited fifteen minutes
	 * for a retry is not helped by sixteen.
	 *
	 * The value is derived from the seed, the attempt, the process, and the clock
	 * rather than from a random number. Two jobs on one site therefore do not
	 * synchronise, while a given job's schedule stays reproducible in a log — which
	 * is the property that makes an incident diagnosable.
	 *
	 * @param int    $seconds Computed backoff.
	 * @param string $seed    Per-job discriminator.
	 * @return int
	 */
	public static function apply_jitter( $seconds, $seed = '' ) {
		$seconds = max( 0, (int) $seconds );
		if ( $seconds < 2 ) {
			return min( $seconds, self::RETRY_MAX_SECONDS );
		}

		$spread = (int) floor( $seconds / 2 );
		if ( $spread < 1 ) {
			return min( $seconds, self::RETRY_MAX_SECONDS );
		}

		$material = (string) $seed . '|' . (int) getmypid() . '|' . (int) floor( microtime( true ) * 1000 );
		$hash     = crc32( $material );

		return min( self::RETRY_MAX_SECONDS, $seconds + (int) ( $hash % ( $spread + 1 ) ) );
	}

	/**
	 * Return whether a status can still change.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_terminal( $status ) {
		return Job_States::is_terminal( $status );
	}
}
