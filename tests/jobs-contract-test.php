<?php
/**
 * ReplicaForge job contract test.
 *
 * The job system is the answer to "my work disappeared because I closed the
 * tab", so the properties that matter are that a job survives, that a repeated
 * click does not duplicate work, that a crash is recoverable, and that a failure
 * is reported honestly rather than as a success.
 *
 * Usage: php jobs-contract-test.php <wp-root>
 *
 * @package ReplicaForge
 */

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php jobs-contract-test.php <wp-root>\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['SERVER_PORT']    = '80';
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}
require_once $root . '/wp-load.php';

use ReplicaForge\Data_Redactor;
use ReplicaForge\Error_Catalog;
use ReplicaForge\Feature_Flags;
use ReplicaForge\Job_Limits;
use ReplicaForge\Job_Queue;
use ReplicaForge\Job_Repository;
use ReplicaForge\Job_Runner;
use ReplicaForge\Job_States;
use ReplicaForge\Logger;
use ReplicaForge\Maintenance;
use ReplicaForge\Migrator;
use ReplicaForge\Request_Context;
use ReplicaForge\Schema;
use ReplicaForge\System_Status;
$assertions = 0;

/**
 * Assert a condition and print the outcome.
 *
 * @param bool   $condition Condition.
 * @param string $message   What was checked.
 * @return void
 */
function check( $condition, $message ) {
	global $assertions;
	$assertions++;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	echo "FAIL: {$message}\n";
	throw new RuntimeException( 'FAILED: ' . $message );
}

$administrators = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( empty( $administrators ) ) {
	throw new RuntimeException( 'FAILED: an administrator account is required.' );
}
wp_set_current_user( (int) $administrators[0]->ID );

// A known starting state. Both options are ReplicaForge's own, so clearing them
// affects nothing a user owns.
delete_option( \ReplicaForge\Job_Repository::OPTION );
delete_option( \ReplicaForge\Job_Queue::OPTION );
delete_option( \ReplicaForge\Logger::OPTION );
\ReplicaForge\Request_Context::reset();

$logger      = new \ReplicaForge\Logger( 200, 'debug' );
$repository  = new \ReplicaForge\Job_Repository( $logger );
$queue       = new \ReplicaForge\Job_Queue( $repository, $logger );

/* ------------------------------------------------------------------ */
/* 1. The job vocabulary is complete and self-consistent.               */
/* ------------------------------------------------------------------ */

echo "--- 1. Job vocabulary ---\n";

// Phase 7 declared six statuses. Phase 11 added five more, and the vocabulary is
// now built from `Job_States` so there is one list rather than two that can drift.
// The six originals are still here with their original meanings; the assertion
// below is on the whole set, and the per-state assertions are unchanged.
check( count( Job_Limits::STATUSES ) === 11, 'Eleven job statuses are declared.' );
check( count( Job_Limits::STATUSES ) === count( Job_States::ALL ), 'The Phase 7 vocabulary and the Phase 11 vocabulary are the same list, not two lists that could drift.' );
foreach ( array( 'queued', 'running', 'paused', 'completed', 'failed', 'cancelled' ) as $status ) {
	check( isset( Job_Limits::STATUSES[ $status ] ), "Status declared: {$status}" );
}
foreach ( array( 'reserved', 'waiting', 'retrying', 'expired', 'blocked' ) as $status ) {
	check( isset( Job_Limits::STATUSES[ $status ] ), "Phase 11 status declared: {$status}" );
}
check( Job_Limits::is_terminal( 'completed' ), 'completed is terminal.' );
check( Job_Limits::is_terminal( 'failed' ), 'failed is terminal.' );
check( Job_Limits::is_terminal( 'cancelled' ), 'cancelled is terminal.' );
// A job whose worker died after it had begun writing is terminal, because
// re-running it automatically could apply a second set of changes to a
// half-written Elementor document. Resuming it is an explicit decision.
check( Job_Limits::is_terminal( 'expired' ), 'expired is terminal, so a job that had begun writing is not re-run automatically.' );
check( ! Job_Limits::is_terminal( 'queued' ), 'queued is not terminal.' );
check( ! Job_Limits::is_terminal( 'paused' ), 'paused is not terminal, so a resume is possible.' );
check( ! Job_Limits::is_terminal( 'running' ), 'running is not terminal.' );
check( ! Job_Limits::is_terminal( 'retrying' ), 'retrying is not terminal, because it is going to try again.' );
check( ! Job_Limits::is_terminal( 'waiting' ), 'waiting is not terminal.' );

/* Progress must be derived from real stages, never invented. */
check( 0 === Job_Limits::stage_progress( 'queued' ), 'A queued job is at zero percent.' );
$monotonic = true;
$previous  = -1;
foreach ( Job_Limits::STAGES as $stage ) {
	$value = Job_Limits::stage_progress( $stage );
	if ( $value < $previous ) {
		$monotonic = false;
	}
	$previous = $value;
}
check( $monotonic, 'Progress never goes backwards as stages advance.' );
check( Job_Limits::stage_progress( 'finalize' ) < 100, 'The last stage is not reported as complete before it finishes.' );
check( '' === Job_Limits::next_stage( 'finalize' ), 'There is no stage after the last one.' );
check( 'analyze' === Job_Limits::next_stage( 'queued' ), 'A queued job advances to analyze.' );

/* Backoff must grow and stay capped. */
check( Job_Limits::backoff_seconds( 1 ) < Job_Limits::backoff_seconds( 2 ), 'Backoff grows with the attempt number.' );
check( Job_Limits::backoff_seconds( 10 ) <= Job_Limits::RETRY_MAX_SECONDS, 'Backoff is capped.' );
check( Job_Limits::backoff_seconds( 1 ) > 0, 'Backoff is never zero, which would be a tight loop.' );

/* ------------------------------------------------------------------ */
/* 2. A queued job is durable and readable.                            */
/* ------------------------------------------------------------------ */

echo "--- 2. Queueing and durability ---\n";

$queued = $queue->enqueue(
	'replica',
	array( 'source_url' => 'https://example.com/', 'source_host' => 'example.com' )
);
$job_id = (string) $queued['job']['job_id'];

check( false === $queued['duplicate'], 'The first request is not a duplicate.' );
check( 1 === preg_match( '/^job_[a-f0-9]{24}$/', $job_id ), 'A job id has the expected shape: ' . $job_id );
check( 'queued' === (string) $queued['job']['status'], 'A new job starts queued.' );
check( 0 === (int) $queued['job']['progress'], 'A queued job reports zero progress.' );

// Durability: a fresh repository instance reads it back, which is what a new HTTP
// request will do.
$reader = new \ReplicaForge\Job_Repository( $logger );
$stored = $reader->find( $job_id );
check( null !== $stored, 'A queued job survives in storage.' );
check( $job_id === (string) $stored['job_id'], 'The stored job is the same job.' );
check( 'https://example.com/' === (string) $stored['params']['source_url'], 'The source URL is stored.' );

$api_argument = new ReflectionMethod( '\ReplicaForge\Job_Api', 'job_id_arg' );
$api_argument->setAccessible( true );
$job_api     = new \ReplicaForge\Job_Api( new Job_Runner(), $logger, new System_Status( $logger ), new Maintenance( $logger ) );
$api_valid   = $api_argument->invoke( $job_api );
$api_checker = isset( $api_valid['validate_callback'] ) && is_callable( $api_valid['validate_callback'] )
	? $api_valid['validate_callback']
	: null;
check( null !== $api_checker, 'The API declares a job id validator.' );
check(
	true === call_user_func( $api_checker, $job_id ),
	'The API accepts the job id the repository produces, so a real job is reachable.'
);
check(
	false === call_user_func( $api_checker, 'not-a-job-id' ),
	'The API refuses an identifier that is not a job id.'
);
check(
	false === call_user_func( $api_checker, '../../etc/passwd' ),
	'The API refuses a path traversal attempt in a job id.'
);

$presented = $reader->present( $stored );
check( isset( $presented['stages'] ) && is_array( $presented['stages'] ), 'The presented job carries the stage list.' );
check( count( $presented['stages'] ) === count( Job_Limits::STAGES ), 'Every stage is presented for the progress UI.' );
check( true === $presented['can_cancel'], 'A queued job can be cancelled.' );
check( false === $presented['can_resume'], 'A queued job cannot be resumed, because it has not failed.' );

/* ------------------------------------------------------------------ */
/* 3. Idempotency.                                                      */
/* ------------------------------------------------------------------ */

echo "--- 3. Idempotency ---\n";

$again = $queue->enqueue( 'replica', array( 'source_url' => 'https://example.com/', 'source_host' => 'example.com' ) );
check( true === $again['duplicate'], 'A repeated identical request is recognized.' );
check( $job_id === (string) $again['job']['job_id'], 'A repeated request returns the original job.' );
check( 1 === count( $reader->all() ), 'A repeated request created no second job.' );

$with_key = $queue->enqueue(
	'generation',
	array( 'source_url' => 'https://example.com/' ),
	array( 'idempotency_key' => 'client-supplied-1' )
);
$with_key_again = $queue->enqueue(
	'generation',
	array( 'source_url' => 'https://example.com/' ),
	array( 'idempotency_key' => 'client-supplied-1' )
);
check( $with_key_again['duplicate'], 'A client-supplied idempotency key deduplicates.' );
check( 2 === count( $reader->all() ), 'The keyed request created exactly one job.' );

/* The same key under a different type must not collide, or one workflow could
   suppress another. */
$cross = $queue->enqueue(
	'validation',
	array( 'source_url' => 'https://example.com/' ),
	array( 'idempotency_key' => 'client-supplied-1' )
);
check( false === $cross['duplicate'], 'The same key under a different type is a different request.' );

/* ------------------------------------------------------------------ */
/* 4. Leases, crashes, and recovery.                                   */
/* ------------------------------------------------------------------ */

echo "--- 4. Leases and crash recovery ---\n";

$claimed = $queue->claim( $job_id );
check( null !== $claimed, 'A queued job can be claimed.' );
check( 'running' === (string) $claimed['status'], 'A claimed job is running.' );
check( 1 === (int) $claimed['attempts'], 'Claiming counts as an attempt.' );
check( (int) $claimed['lease_until'] > time(), 'A claimed job holds a lease.' );

$second_claim = $queue->claim( $job_id );
check( null === $second_claim, 'A leased job cannot be claimed by a second worker.' );

/* Simulate a worker killed by a time limit: the lease is still held, the job is
   still "running", and nobody released it. Recovery must not depend on the dead
   worker tidying up. */
$repository->update( $job_id, array( 'lease_until' => time() - 1 ) );
$recovered = $queue->claim( $job_id );
check( null !== $recovered, 'A job whose worker died is reclaimable once the lease expires.' );
check( 2 === (int) $recovered['attempts'], 'Recovery counts as another attempt.' );

$queue->heartbeat( $job_id );
$after_heartbeat = $repository->find( $job_id );
check( (int) $after_heartbeat['lease_until'] > time(), 'A heartbeat extends the lease.' );

/* A terminal job is never reclaimed. */
$finished = $queue->complete( $job_id, array( 'draft_id' => 1 ) );
check( null !== $finished, 'A job can be completed.' );
check( 'completed' === (string) $finished['status'], 'A completed job reports completed.' );
check( 100 === (int) $finished['progress'], 'A completed job is at one hundred percent.' );
check( null === $queue->claim( $job_id ), 'A completed job is never reclaimed.' );

/* ------------------------------------------------------------------ */
/* 5. Stage advance and checkpoints.                                   */
/* ------------------------------------------------------------------ */

echo "--- 5. Stages and checkpoints ---\n";

$stage_job = $queue->enqueue( 'replica', array( 'source_url' => 'https://stages.test/' ) );
$stage_id  = (string) $stage_job['job']['job_id'];

$queue->claim( $stage_id );
$advanced = $queue->advance( $stage_id, null, array( 'result' => 'kept' ) );
check( null !== $advanced, 'A job advances a stage.' );
check( 'analyze' === (string) $advanced['stage'], 'A queued job advances to analyze.' );
check( Job_Limits::stage_progress( 'analyze' ) === (int) $advanced['progress'], 'Progress follows the stage.' );
check( isset( $advanced['checkpoint']['queued'] ), 'The checkpoint from the finished stage is retained.' );

$queue->advance( $stage_id, 'generate', array( 'draft_id' => 7 ) );
$at_generate = $repository->find( $stage_id );
check( 'generate' === (string) $at_generate['stage'], 'A job can be moved to a named stage.' );
check( isset( $at_generate['checkpoint']['analyze'] ), 'An earlier checkpoint is not discarded by a later stage.' );

/* A bulky value a stage needs is held in a bounded payload rather than
   inside the shared job record, so the job list stays small enough to read in
   one query. This is the property that keeps a hundred retained jobs from
   meaning a hundred retained analyses. */
$bulky    = array( 'payload' => str_repeat( 'x', 200000 ) );
$token    = $repository->set_payload( $stage_id, 'bulky', $bulky );
check( 1 === preg_match( '/^[a-f0-9]{16}$/', $token ), 'A payload reference is a bounded token.' );

$read_back = $repository->get_payload( $stage_id, 'bulky', $token );
check( is_array( $read_back ) && 200000 === strlen( (string) $read_back['payload'] ), 'The payload reads back intact, so a resumed stage still has its data.' );

$job_size_before = strlen( (string) wp_json_encode( $repository->find( $stage_id ) ) );
$queue->advance( $stage_id, 'validate', array( 'bulky_ref' => $token ) );
$job_size_after = strlen( (string) wp_json_encode( $repository->find( $stage_id ) ) );
check(
	$job_size_after < 2000 && $job_size_after - $job_size_before < 500,
	'A 200 KB value does not grow the job record, which is what keeps the job option bounded.'
);

check( null === $repository->get_payload( $stage_id, 'bulky', 'not-a-real-token' ), 'A wrong payload token reads as absent rather than returning something.' );
check( null === $repository->get_payload( $stage_id, 'bulky', '' ), 'An empty payload token reads as absent.' );
check( null === $repository->get_payload( $stage_id, 'unknown-key', $token ), 'A payload under an unknown name reads as absent.' );

// Deleting a job must take its payloads with it, or they outlive the work.
$with_payload = $queue->enqueue( 'replica', array( 'source_url' => 'https://payload.test/' ) );
$with_payload_id = (string) $with_payload['job']['job_id'];
$payload_token   = $repository->set_payload( $with_payload_id, 'temporary', array( 'x' => 1 ) );
check( null !== $repository->get_payload( $with_payload_id, 'temporary', $payload_token ), 'The payload is stored for the job.' );
$repository->delete( $with_payload_id );
check( null === $repository->get_payload( $with_payload_id, 'temporary', $payload_token ), 'Deleting a job deletes its payloads, so they cannot accumulate.' );

/* ------------------------------------------------------------------ */
/* 6. Failure reporting is honest.                                     */
/* ------------------------------------------------------------------ */

echo "--- 6. Failure and retry ---\n";

$fail_job = $queue->enqueue( 'replica', array( 'source_url' => 'https://retry.test/' ) );
$fail_id  = (string) $fail_job['job']['job_id'];

$queue->claim( $fail_id );
$queue->fail( $fail_id, 'request_timeout' );
$after_timeout = $repository->find( $fail_id );
// Phase 7 returned a retryable failure to `queued`. Phase 11 returns it to
// `retrying`, which is the same scheduling behaviour with the reason attached —
// previously a job waiting fifteen minutes was indistinguishable from one that had
// never run. What the assertion below checks is the property that actually
// matters, and it is stronger than the one it replaces: the job is *not* claimable
// until its backoff elapses, and *is* claimable afterwards.
check(
	Job_States::is_scheduled( (string) $after_timeout['status'] ),
	'A retryable failure returns the job to a scheduled state.'
);
check( 'retrying' === (string) $after_timeout['status'], 'That state is `retrying`, so a dashboard can tell a backed-off job from a fresh one.' );
$claimable_ids = wp_list_pluck( $repository->claimable(), 'job_id' );
check( ! in_array( $fail_id, $claimable_ids, true ), 'A job waiting out its backoff is not claimable yet.' );
$repository->update( $fail_id, array( 'next_attempt' => time() - 1 ) );
$claimable_ids = wp_list_pluck( $repository->claimable(), 'job_id' );
check( in_array( $fail_id, $claimable_ids, true ), 'Once the backoff has passed, the job is claimable again.' );
$repository->update( $fail_id, array( 'next_attempt' => time() + Job_Limits::backoff_seconds( 1, $fail_id ) ) );
check( (int) $after_timeout['next_attempt'] > time(), 'A retry is scheduled in the future, not immediately.' );
check( ! empty( $after_timeout['error'] ), 'The failure reason is recorded.' );
check( 'request_timeout' === (string) $after_timeout['error']['code'], 'The error code is recorded.' );
check( '' !== (string) $after_timeout['error']['message'], 'A user-facing message is recorded.' );
check( true === (bool) $after_timeout['error']['retryable'], 'The failure is marked retryable.' );

/* A permanent failure must not be retried. */
$queue->claim( $fail_id );
$repository->update( $fail_id, array( 'next_attempt' => 0 ) );
$queue->fail( $fail_id, 'invalid_url' );
$after_permanent = $repository->find( $fail_id );
check( 'failed' === (string) $after_permanent['status'], 'A permanent failure is not retried.' );
check( '' !== (string) $after_permanent['finished_at'], 'A permanently failed job records when it stopped.' );

/* The attempt budget is enforced. */
$budget_job = $queue->enqueue( 'replica', array( 'source_url' => 'https://budget.test/' ) );
$budget_id  = (string) $budget_job['job']['job_id'];
for ( $i = 0; $i < Job_Limits::MAX_ATTEMPTS; $i++ ) {
	$repository->update( $budget_id, array( 'next_attempt' => 0 ) );
	$queue->claim( $budget_id );
	$queue->fail( $budget_id, 'request_timeout' );
}
$exhausted = $repository->find( $budget_id );
check( 'failed' === (string) $exhausted['status'], 'A job stops retrying at the attempt limit.' );
check( Job_Limits::MAX_ATTEMPTS === (int) $exhausted['attempts'], 'The attempt count equals the limit.' );

$presented_budget = $repository->present( $exhausted );
check( false === $presented_budget['can_retry'], 'An exhausted job cannot be retried.' );
check( false === $presented_budget['can_resume'], 'An exhausted job cannot be resumed.' );

/* ------------------------------------------------------------------ */
/* 7. Resume, cancel, and delete.                                      */
/* ------------------------------------------------------------------ */

echo "--- 7. Resume, cancel, delete ---\n";

$resume_job = $queue->enqueue( 'replica', array( 'source_url' => 'https://resume.test/' ) );
$resume_id  = (string) $resume_job['job']['job_id'];

// Advance past the expensive stages first. Resuming a job that has already done
// this work must not send it back to the beginning, which is the whole point of
// storing checkpoints.
// Each advance records what the stage it leaves behind produced, so a
// checkpoint is keyed by the stage that produced the data.
$queue->claim( $resume_id );
$queue->advance( $resume_id, 'analyze' );
$queue->advance( $resume_id, 'design', array( 'note' => 'analysis done' ) );
$queue->advance( $resume_id, 'ai' );
$queue->advance( $resume_id, 'generate' );
$queue->advance( $resume_id, 'validate', array( 'draft_id' => 99, 'validation_id' => 'val_x' ) );

$advanced_stage = (string) ( $repository->find( $resume_id )['stage'] ?? '' );
check( 'validate' === $advanced_stage, 'The job reached the validate stage.' );

$queue->pause( $resume_id );
$paused = $repository->find( $resume_id );
check( 'paused' === (string) $paused['status'], 'A job can be paused.' );
check( 'validate' === (string) $paused['stage'], 'Pausing does not rewind the stage.' );
check(
	99 === (int) ( $paused['checkpoint']['generate']['draft_id'] ?? 0 ),
	'A checkpoint is keyed by the stage that produced the data, which is what the runner reads.'
);
check(
	'analysis done' === (string) ( $paused['checkpoint']['analyze']['note'] ?? '' ),
	'An earlier checkpoint is still present several stages later.'
);
check(
	99 === (int) ( $paused['checkpoint']['generate']['draft_id'] ?? 0 ),
	'A later stage adds its own checkpoint without discarding the earlier one.'
);

$resumed = $queue->resume( $resume_id );
check( true === $resumed['success'], 'A paused job can be resumed.' );
$after_resume = $repository->find( $resume_id );
check( 'queued' === (string) $after_resume['status'], 'A resumed job is queued again.' );
check(
	'validate' === (string) $after_resume['stage'],
	'A resumed job restarts at the stage it reached, not from the beginning.'
);
check( null === $after_resume['error'], 'The previous error is cleared on resume.' );
check(
	99 === (int) ( $after_resume['checkpoint']['generate']['draft_id'] ?? 0 ),
	'Work completed before the pause is still there after the resume.'
);

$not_resumable = $queue->resume( $resume_id );
check( false === $not_resumable['success'], 'A job that is merely queued is not "resumed".' );

// A failed job resumes from its stage too, not from the start.
$failed_midway = $queue->enqueue( 'replica', array( 'source_url' => 'https://midway.test/' ) );
$failed_midway_id = (string) $failed_midway['job']['job_id'];
$queue->claim( $failed_midway_id );
$queue->advance( $failed_midway_id, 'analyze' );
$queue->advance( $failed_midway_id, 'design' );
$queue->fail( $failed_midway_id, 'request_timeout' );
$queue->resume( $failed_midway_id );
$after_failed_resume = $repository->find( $failed_midway_id );
check(
	'design' === (string) $after_failed_resume['stage'],
	'A job that failed partway resumes at the stage it failed on.'
);

$cancel_job = $queue->enqueue( 'replica', array( 'source_url' => 'https://cancel.test/' ) );
$cancel_id  = (string) $cancel_job['job']['job_id'];
$cancelled  = $queue->cancel( $cancel_id );
check( true === $cancelled['success'], 'A queued job can be cancelled.' );
$after_cancel = $repository->find( $cancel_id );
check( 'cancelled' === (string) $after_cancel['status'], 'A cancelled job reports cancelled.' );
check( '' !== (string) $after_cancel['finished_at'], 'A cancelled job records when it stopped.' );
check(
	false === (bool) $queue->cancel( $cancel_id )['success'],
	'A job cannot be cancelled twice, and the refusal says so rather than claiming success.'
);

$presented_cancel = $repository->present( $after_cancel );
check( false === $presented_cancel['can_cancel'], 'A cancelled job offers no cancel action.' );

/* Cancelling must not remove a draft the job already produced. The job is
   deleted, the user's content is not. */
$produced = $queue->enqueue( 'generation', array( 'source_url' => 'https://kept.test/' ) );
$produced_id = (string) $produced['job']['job_id'];
$queue->claim( $produced_id );
$queue->advance( $produced_id, 'analyze' );
$queue->advance( $produced_id, 'design' );
$queue->advance( $produced_id, 'ai' );
$queue->advance( $produced_id, 'generate' );
$queue->advance( $produced_id, 'validate', array( 'draft_id' => 4242 ) );
$queue->cancel( $produced_id );
$kept = $repository->find( $produced_id );
check(
	4242 === (int) ( $kept['checkpoint']['generate']['draft_id'] ?? 0 ),
	'Cancelling a job keeps the record of the draft it produced, so the user can still find it.'
);
check(
	'cancelled' === (string) $kept['status'],
	'The cancelled job is marked cancelled rather than deleted.'
);
check( null !== $repository->find( $produced_id ), 'Cancelling a job does not delete its record.' );

check( true === $repository->delete( $produced_id ), 'A job record can be deleted.' );
check( null === $repository->find( $produced_id ), 'A deleted job is gone from the list.' );
check( false === $repository->delete( 'job_000000000000' ), 'Deleting an unknown job reports failure rather than claiming success.' );

/* ------------------------------------------------------------------ */
/* 8. Retention keeps active work.                                     */
/* ------------------------------------------------------------------ */

echo "--- 8. Retention ---\n";

$counts = $repository->counts();
check( isset( $counts['queued'] ) && isset( $counts['completed'] ) && isset( $counts['cancelled'] ), 'Counts are reported per status.' );
check( $counts['completed'] >= 1, 'A completed job is counted.' );

// An old finished job is pruned; an old unfinished one is not, because dropping it
// would lose the user's work.
$old_done = $queue->enqueue( 'replica', array( 'source_url' => 'https://old-done.test/' ) );
$old_done_id = (string) $old_done['job']['job_id'];
$repository->update(
	$old_done_id,
	array( 'status' => 'completed', 'finished_at' => gmdate( 'c', time() - ( 60 * DAY_IN_SECONDS ) ) )
);

$old_active = $queue->enqueue( 'replica', array( 'source_url' => 'https://old-active.test/' ) );
$old_active_id = (string) $old_active['job']['job_id'];
$repository->update(
	$old_active_id,
	array( 'status' => 'running', 'lease_until' => time() - 60, 'updated_at' => gmdate( 'c', time() - ( 60 * DAY_IN_SECONDS ) ) )
);

$removed = $repository->prune( 14 );
check( $removed >= 1, 'An old finished job is pruned.' );
check( null === $repository->find( $old_done_id ), 'The old finished job is gone.' );
check( null !== $repository->find( $old_active_id ), 'An old unfinished job is kept, because it still represents real work.' );

/* ------------------------------------------------------------------ */
/* 9. The runner reports a stage failure rather than throwing.          */
/* ------------------------------------------------------------------ */

echo "--- 9. Runner safety ---\n";

$runner  = new \ReplicaForge\Job_Runner( array( 'queue' => $queue, 'logger' => $logger ) );
$missing = $runner->process( 'job_000000000000' );
check( true === (bool) $missing['skipped'], 'Processing an unknown job is skipped rather than fatal.' );
check( 'not_claimable' === (string) $missing['reason'], 'The skip reason is recorded.' );

// A queued job is claimable, so a tick legitimately runs it.
$claimable = $runner->process( $resume_id );
check( false === (bool) ( $claimable['skipped'] ?? false ), 'A tick processes a queued job rather than skipping it.' );

// A job with a live lease belongs to its worker and must not be taken.
$leased = $queue->enqueue( 'replica', array( 'source_url' => 'https://leased.test/' ) );
$leased_id = (string) $leased['job']['job_id'];
$queue->claim( $leased_id );
$claimed_already_worked = $runner->process( $leased_id );
check( true === (bool) $claimed_already_worked['skipped'], 'A job another worker is processing is not stolen by a second tick.' );
check( 'not_claimable' === (string) $claimed_already_worked['reason'], 'The skip names the reason, so it is diagnosable.' );

// Once the lease expires, recovery works.
$repository->update( $leased_id, array( 'lease_until' => time() - 1 ) );
$after_expiry = $runner->process( $leased_id );
check( false === (bool) ( $after_expiry['skipped'] ?? false ), 'An expired lease lets the next tick take over.' );

// A tick processes several claimable jobs without exceeding its budget.
$tick = $runner->tick( 2 );
check( isset( $tick['count'] ) && $tick['count'] <= 2, 'A tick never processes more jobs than its budget allows.' );

/* A job at the final stage completes. The job is advanced without being claimed
   first, so it is still claimable when the tick reaches it. */
$finish_job = $queue->enqueue( 'replica', array( 'source_url' => 'https://finish.test/' ) );
$finish_id  = (string) $finish_job['job']['job_id'];
$queue->advance( $finish_id, 'finalize' );
$finished_run = $runner->process( $finish_id );
check( true === (bool) ( $finished_run['ok'] ?? false ), 'A job at the final stage completes.' );
check( 'completed' === (string) ( $repository->find( $finish_id )['status'] ?? '' ), 'The job is recorded as completed.' );
check( 100 === (int) ( $repository->find( $finish_id )['progress'] ?? 0 ), 'A completed job reports one hundred percent.' );

/* ------------------------------------------------------------------ */
/* 10. Nothing in a job record is a secret.                            */
/* ------------------------------------------------------------------ */

echo "--- 10. Job record hygiene ---\n";

$secret_job = $queue->enqueue(
	'replica',
	array(
		'source_url' => 'https://example.com/',
		'api_key'    => 'sk-abcdefghijklmnopqrstuvwxyz1234',
		'notes'      => 'Authorization: Bearer abcdefghijklmnopqrst',
	)
);
$stored_secret = $repository->find( (string) $secret_job['job']['job_id'] );
$encoded = (string) wp_json_encode( $stored_secret );
check( false === strpos( $encoded, 'sk-abcdefghijklmnopqrstuvwxyz1234' ), 'A secret in job parameters is redacted at rest.' );
check( false === strpos( $encoded, 'abcdefghijklmnopqrst' ), 'An authorization value in job parameters is redacted at rest.' );
check( 'https://example.com/' === (string) $stored_secret['params']['source_url'], 'The source URL is kept, because it is the work.' );

/* ------------------------------------------------------------------ */

delete_option( \ReplicaForge\Job_Repository::OPTION );
delete_option( \ReplicaForge\Job_Queue::OPTION );

echo "\nJob contract test passed. Assertions: {$assertions}\n";
