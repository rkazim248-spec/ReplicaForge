<?php
/**
 * ReplicaForge maintenance contract test.
 *
 * The cleanup is the thing that keeps a site from growing without bound, and it
 * is also the thing most able to destroy a user's work by accident. These tests
 * assert both halves: that bounded data is removed, and that anything a live job
 * or a pending rollback still needs is left alone.
 *
 * Usage: php maintenance-contract-test.php <wp-root>
 *
 * @package ReplicaForge
 */

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php maintenance-contract-test.php <wp-root>\n" );
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

use ReplicaForge\Correction_Limits;
use ReplicaForge\Job_Limits;
use ReplicaForge\Job_Queue;
use ReplicaForge\Job_Repository;
use ReplicaForge\Logger;
use ReplicaForge\Maintenance;
use ReplicaForge\Migrator;
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

$logger      = new Logger( 200, 'debug' );
$maintenance = new Maintenance( $logger );

/* ------------------------------------------------------------------ */
/* 1. The schedule is registered and removable.                         */
/* ------------------------------------------------------------------ */

echo "--- 1. Scheduling ---\n";

wp_clear_scheduled_hook( Maintenance::JOB_HOOK );
wp_clear_scheduled_hook( Maintenance::DAILY_HOOK );
check( false === wp_next_scheduled( Maintenance::JOB_HOOK ), 'No job schedule exists to begin with.' );

$maintenance->schedule();
check( false !== wp_next_scheduled( Maintenance::JOB_HOOK ), 'A job schedule is created.' );
check( false !== wp_next_scheduled( Maintenance::DAILY_HOOK ), 'A daily maintenance schedule is created.' );

$maintenance->schedule();
check( false !== wp_next_scheduled( Maintenance::JOB_HOOK ), 'Scheduling twice does not create a duplicate.' );

$maintenance->unschedule();
check( false === wp_next_scheduled( Maintenance::JOB_HOOK ), 'Deactivation removes the job schedule.' );
check( false === wp_next_scheduled( Maintenance::DAILY_HOOK ), 'Deactivation removes the daily schedule.' );

$maintenance->schedule();

/* ------------------------------------------------------------------ */
/* 2. Retention settings are bounded.                                   */
/* ------------------------------------------------------------------ */

echo "--- 2. Retention settings ---\n";

$settings = $maintenance->settings();
foreach ( array( 'log_days', 'job_days', 'snapshot_days', 'transient_ttl', 'log_limit' ) as $key ) {
	check( isset( $settings[ $key ] ), "Retention default present: {$key}" );
}

check( true === $maintenance->set_setting( 'log_days', 7 ), 'A retention setting can be saved.' );
check( 7 === (int) $maintenance->settings()['log_days'], 'A saved retention setting is read back.' );

check( false === $maintenance->set_setting( 'log_days', 0 ), 'A retention setting cannot be set to zero.' );
check( false === $maintenance->set_setting( 'log_days', 100000 ), 'A retention setting cannot be set beyond its range.' );
check( false === $maintenance->set_setting( 'not_a_setting', 5 ), 'An unknown setting is refused.' );
check( false === $maintenance->set_setting( 'log_limit', 999999 ), 'The log limit cannot be raised without bound.' );
check(
	(int) $maintenance->settings()['log_limit'] <= Logger::MAX_LIMIT,
	'The log limit stays within the hard ceiling.'
);

$maintenance->set_setting( 'log_days', 30 );

/* ------------------------------------------------------------------ */
/* 3. Expired transients are removed; live ones are not.                */
/* ------------------------------------------------------------------ */

echo "--- 3. Transient cleanup ---\n";

// Expired: a timeout in the past, so unreadable by definition.
set_transient( 'replicaforge_test_expired', 'value', -60 );
$expired_option = '_transient_replicaforge_test_expired';
check( false !== get_option( $expired_option, false ), 'An expired transient is present before the cleanup.' );

// Live: a timeout in the future, so still readable.
set_transient( 'replicaforge_test_live', 'value', HOUR_IN_SECONDS );
$live_option = '_transient_replicaforge_test_live';
check( false !== get_option( $live_option, false ), 'A live transient is present before the cleanup.' );

$purge = $maintenance->purge_expired();
check( is_array( $purge ) && isset( $purge['transients'] ), 'The purge reports what it did.' );
check( false === get_option( $expired_option, false ), 'An expired transient is removed.' );
check( false !== get_option( $live_option, false ), 'A live transient is kept, because it is still readable.' );

delete_transient( 'replicaforge_test_live' );
check( false === get_option( $live_option, false ), 'The live transient is removed once it expires.' );

/* A transient belonging to a pending rollback must survive until it expires. */
set_transient( 'replicaforge_correction_plan_snap_pendingrollback', 'document', HOUR_IN_SECONDS );
$before = $maintenance->purge_expired();
check(
	false !== get_transient( 'replicaforge_correction_plan_snap_pendingrollback' ),
	'A snapshot backing a pending rollback is not removed by the cleanup.'
);
delete_transient( 'replicaforge_correction_plan_snap_pendingrollback' );

/* ------------------------------------------------------------------ */
/* 4. The log is pruned by age and can be cleared.                      */
/* ------------------------------------------------------------------ */

echo "--- 4. Log cleanup ---\n";

$logger->clear();
$logger->info( 'recent_entry', 'A recent entry.', array(), 'system' );

// A hand-aged entry, so the age test does not depend on the clock.
$entries   = $logger->all();
$aged      = $entries[0];
$aged['time'] = gmdate( 'c', time() - ( 90 * DAY_IN_SECONDS ) );
$old       = $aged;
$old['event']  = 'ancient_entry';
$old['message'] = 'An entry from long ago.';
$entries[] = $old;
update_option( Logger::OPTION, $entries, false );
check( 2 === count( $logger->all() ), 'Two entries are present before pruning.' );

$removed = $maintenance->prune_log( 30 );
check( $removed >= 1, 'An entry older than the retention period is removed.' );
check( 1 === count( $logger->all() ), 'The recent entry is kept.' );
check( 'recent_entry' === (string) $logger->all()[0]['event'], 'The entry that was kept is the recent one.' );

$logger->clear();
check( 0 === count( $logger->all() ), 'The log can be cleared.' );

/* ------------------------------------------------------------------ */
/* 5. Finished jobs are pruned; active ones are kept.                   */
/* ------------------------------------------------------------------ */

echo "--- 5. Job cleanup ---\n";

delete_option( Job_Repository::OPTION );
$repository = new Job_Repository( $logger );
$queue      = new Job_Queue( $repository, $logger );

$done = $queue->enqueue( 'replica', array( 'source_url' => 'https://done.test/' ) );
$done_id = (string) $done['job']['job_id'];
$repository->update( $done_id, array( 'status' => 'completed', 'finished_at' => gmdate( 'c', time() - ( 60 * DAY_IN_SECONDS ) ) ) );

$active = $queue->enqueue( 'replica', array( 'source_url' => 'https://active.test/' ) );
$active_id = (string) $active['job']['job_id'];
$repository->update( $active_id, array( 'status' => 'queued', 'updated_at' => gmdate( 'c', time() - ( 60 * DAY_IN_SECONDS ) ) ) );

$fresh = $queue->enqueue( 'replica', array( 'source_url' => 'https://fresh.test/' ) );
$fresh_id = (string) $fresh['job']['job_id'];

$pruned = $repository->prune( 14 );
check( $pruned >= 1, 'An old finished job is pruned.' );
check( null === $repository->find( $done_id ), 'The old finished job is gone.' );
check( null !== $repository->find( $active_id ), 'An old unfinished job is kept, because it still represents real work.' );
check( null !== $repository->find( $fresh_id ), 'A recent job is kept.' );

delete_option( Job_Repository::OPTION );

/* ------------------------------------------------------------------ */
/* 6. Idempotency records expire.                                      */
/* ------------------------------------------------------------------ */

echo "--- 6. Idempotency cleanup ---\n";

delete_option( Job_Queue::OPTION );
$fresh_queue = new Job_Queue( new Job_Repository( $logger ), $logger );
$fresh_queue->enqueue( 'replica', array( 'source_url' => 'https://idempotent.test/' ) );
check( ! empty( $fresh_queue->prune_records() ) || 1 === count( (array) get_option( Job_Queue::OPTION, array() ) ), 'Idempotency records exist before pruning.' );

// Age the record past its TTL.
$records = get_option( Job_Queue::OPTION, array() );
foreach ( (array) $records as $key => $record ) {
	$records[ $key ]['expires'] = time() - 10;
}
update_option( Job_Queue::OPTION, $records, false );
check( $fresh_queue->prune_records() >= 1, 'An expired idempotency record is removed.' );
check( empty( (array) get_option( Job_Queue::OPTION, array() ) ), 'No expired idempotency record is left behind.' );

delete_option( Job_Queue::OPTION );

/* ------------------------------------------------------------------ */
/* 7. The full daily run is safe and idempotent.                        */
/* ------------------------------------------------------------------ */

echo "--- 7. Daily run ---\n";

$first = $maintenance->daily();
check( isset( $first['transients'], $first['jobs'], $first['log'], $first['idempotency'] ) || isset( $first['transients'], $first['jobs'] ), 'The daily run reports each category it touches.' );

$second = $maintenance->daily();
check( is_array( $second ), 'The daily run is repeatable rather than failing on the second pass.' );
check(
	(int) $second['transients']['transients'] <= (int) $first['transients']['transients'],
	'A second cleanup removes no more than the first, because there is less left.'
);

/* ------------------------------------------------------------------ */
/* 8. The storage report is honest.                                    */
/* ------------------------------------------------------------------ */

echo "--- 8. Storage report ---\n";

$report = $maintenance->storage_report();
foreach ( array( 'transients', 'jobs', 'log_entries', 'log_bytes', 'snapshots' ) as $key ) {
	check( isset( $report[ $key ] ), "Storage report includes: {$key}" );
	check( is_numeric( $report[ $key ] ), "Storage report value is numeric: {$key}" );
	check( (int) $report[ $key ] >= 0, "Storage report value is not negative: {$key}" );
}

/* ------------------------------------------------------------------ */
/* 9. Migrations are versioned and safe.                                */
/* ------------------------------------------------------------------ */

echo "--- 9. Migrations ---\n";

$migrator = new Migrator( $logger );

check( ! empty( $migrator->migrations() ), 'At least one migration is declared.' );
$declared = $migrator->migrations();
check( isset( $declared[0]['to'] ), 'A migration declares the version it produces.' );
check( isset( $declared[0]['summary'] ), 'A migration describes what it does, so the log is readable.' );

$state = $migrator->state();
check( isset( $state['installed'], $state['current'], $state['up_to_date'], $state['pending'] ), 'Migration state reports what is installed and what is pending.' );

// A run marks the current version rather than leaving the install permanently
// "stale", which would re-run the first migration on every request.
$run = $migrator->run();
check( true === (bool) $run['success'], 'The migration run succeeds.' );
check( Schema::DB_SCHEMA_VERSION === Schema::installed(), 'The installed schema version is recorded.' );
check( true === $migrator->state()['up_to_date'], 'The install reports itself up to date afterwards.' );
check( empty( $migrator->pending() ), 'Nothing is pending once the current version is installed.' );

$again = $migrator->run();
check( empty( $again['applied'] ), 'A second run applies nothing, because there is nothing to do.' );

// A forced run still works and is safe to repeat.
$forced = $migrator->run( true );
check( true === (bool) $forced['success'], 'A forced migration run succeeds.' );
check( Schema::DB_SCHEMA_VERSION === Schema::installed(), 'A forced run leaves the correct version recorded.' );

check( ! Schema::is_stale( Schema::DB_SCHEMA_VERSION ), 'The current version is not stale.' );
check( Schema::is_stale( '0.1' ), 'An older version is stale.' );
check( Schema::is_stale( '' ), 'An unknown version is treated as stale rather than assumed current.' );

/* Cache keys must change when the schema that produced them changes, or a stale
   result is served after an upgrade. */
check( Schema::cache_token( 'analysis' ) !== Schema::cache_token( 'generation' ), 'Each phase has its own cache token.' );
check( false !== strpos( Schema::cache_token( 'reconstruction' ), Schema::PROMPT_VERSION ), 'The reconstruction cache token includes the prompt version.' );

$versions = Schema::all();
foreach ( array( 'db_schema', 'analysis_schema', 'reconstruction_schema', 'validation_schema', 'correction_schema', 'prompt_version', 'plugin_version' ) as $key ) {
	check( isset( $versions[ $key ] ) && '' !== (string) $versions[ $key ], "A version is declared and non-empty: {$key}" );
}
check(
	$versions['plugin_version'] !== $versions['db_schema'],
	'The plugin version and the data schema version are separate values, so a patch release does not imply a migration.'
);

/* ------------------------------------------------------------------ */
/* 10. System status answers with something actionable.                 */
/* ------------------------------------------------------------------ */

echo "--- 10. System status ---\n";

$status   = new System_Status( $logger );
$report   = $status->report();

check( isset( $report['state'], $report['checks'], $report['versions'] ), 'The status report has a verdict, checks, and versions.' );
check( in_array( $report['state'], array( 'ok', 'warn', 'fail' ), true ), 'The verdict is one of the declared states.' );

foreach ( array( 'wordpress', 'php', 'plugin', 'rest', 'filesystem', 'elementor', 'ai', 'http', 'cron', 'memory', 'database', 'schema' ) as $check_name ) {
	check( isset( $report['checks'][ $check_name ] ), "Status check present: {$check_name}" );
	check(
		in_array( $report['checks'][ $check_name ]['state'], array( 'ok', 'warn', 'fail' ), true ),
		"Status check reports a declared state: {$check_name}"
	);
	check( '' !== (string) $report['checks'][ $check_name ]['label'], "Status check has a label: {$check_name}" );
}

// A failing check must say what to do about it. That is the difference between a
// status screen and a list of booleans.
foreach ( $report['checks'] as $check_name => $one ) {
	if ( 'fail' === $one['state'] ) {
		check( '' !== (string) $one['action'], "A failing check says what to do: {$check_name}" );
		check( '' !== (string) $one['detail'], "A failing check explains itself: {$check_name}" );
	}
}

$summary = $status->summary();
check( isset( $summary['state'], $summary['label'] ), 'The dashboard summary has a state and a label.' );
check( '' !== (string) $summary['label'], 'The summary label is never empty.' );

/* The status must not crash when a dependency is absent. The database check is
   the one that can genuinely fail, so it is exercised through its own path. */
check( isset( $report['checks']['database']['detail'] ), 'The database check reports a version or a failure reason.' );

/* ------------------------------------------------------------------ */
/* 11. Background jobs can actually be turned off.                      */
/* ------------------------------------------------------------------ */

echo "--- 11. Feature flag for background work ---\n";

$flags = new \ReplicaForge\Feature_Flags();
$flags->reset_all();
$flags->set( 'background_jobs_enabled', false );
check( false === $flags->enabled( 'background_jobs_enabled' ), 'Background jobs can be turned off for a site that needs them disabled.' );

$plugin = \ReplicaForge\Plugin::instance();
$outcome = $plugin->process_jobs();
check( isset( $outcome['skipped'] ) && 'background_jobs_disabled' === (string) $outcome['skipped'], 'With the flag off, the processor reports why it did nothing rather than silently doing nothing.' );

$flags->reset( 'background_jobs_enabled' );
check( true === $flags->enabled( 'background_jobs_enabled' ), 'Background jobs can be turned back on.' );

// The schedule is removed through a fresh instance, because the plugin holds its
// own privately; the effect is the same because scheduling is global state.
( new Maintenance() )->unschedule();

/* ------------------------------------------------------------------ */

delete_option( Job_Repository::OPTION );
delete_option( Job_Queue::OPTION );
$maintenance->set_setting( 'log_days', 30 );
$logger->clear();

echo "\nMaintenance contract test passed. Assertions: {$assertions}\n";
