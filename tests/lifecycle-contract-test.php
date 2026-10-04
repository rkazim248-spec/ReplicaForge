<?php
/**
 * Phase 7 lifecycle: activate, deactivate, uninstall.
 *
 * The uninstall routine is the one place that deletes data, so it is exercised
 * against real rows and checked for what it must NOT touch.
 */
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
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

$assertions = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   What was checked.
 * @return void
 */
function check( $condition, $message ) {
	global $assertions;
	$assertions++;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	if ( ! $condition ) {
		throw new RuntimeException( 'FAILED: ' . $message );
	}
}

global $wpdb;

echo "--- 1. Activation ---\n";

// A known starting state.
wp_clear_scheduled_hook( \ReplicaForge\Maintenance::JOB_HOOK );
wp_clear_scheduled_hook( \ReplicaForge\Maintenance::DAILY_HOOK );
delete_option( \ReplicaForge\Schema::VERSION_OPTION );
delete_option( 'replicaforge_version' );

check( false === wp_next_scheduled( \ReplicaForge\Maintenance::JOB_HOOK ), 'No job schedule exists to begin with.' );

\ReplicaForge\Plugin::activate();

check( REPLICAFORGE_VERSION === (string) get_option( 'replicaforge_version' ), 'Activation records the plugin version.' );

// The version exists in two places that can drift: the plugin header WordPress
// reads, and the constant the rest of the code uses. Asserting against the
// constant above is correct but cannot catch the two disagreeing, and a
// disagreement is what makes WordPress offer an update that the running code does
// not believe it is. This is the assertion that catches it.
$header = get_file_data( REPLICAFORGE_FILE, array( 'Version' => 'Version' ) );
check(
	is_array( $header ) && REPLICAFORGE_VERSION === (string) ( $header['Version'] ?? '' ),
	'The plugin header version matches the REPLICAFORGE_VERSION constant.'
);
check( false !== wp_next_scheduled( \ReplicaForge\Maintenance::JOB_HOOK ), 'Activation schedules job processing.' );
check( false !== wp_next_scheduled( \ReplicaForge\Maintenance::DAILY_HOOK ), 'Activation schedules daily maintenance.' );
check(
	\ReplicaForge\Schema::DB_SCHEMA_VERSION === \ReplicaForge\Schema::installed(),
	'Activation runs migrations, so the schema version is recorded.'
);

$before = wp_next_scheduled( \ReplicaForge\Maintenance::JOB_HOOK );
\ReplicaForge\Plugin::activate();
check( wp_next_scheduled( \ReplicaForge\Maintenance::JOB_HOOK ) === $before, 'Activating twice does not move the existing schedule.' );

echo "--- 2. Deactivation ---\n";

\ReplicaForge\Plugin::deactivate();

check( false === wp_next_scheduled( \ReplicaForge\Maintenance::JOB_HOOK ), 'Deactivation unschedules job processing.' );
check( false === wp_next_scheduled( \ReplicaForge\Maintenance::DAILY_HOOK ), 'Deactivation unschedules daily maintenance.' );

// Stored data must survive: deactivating is usually temporary, and destroying a
// user's history because they paused a plugin loses their work for no reason.
$logger = new \ReplicaForge\Logger( 200, 'debug' );
$logger->info( 'lifecycle_test', 'Written before deactivation.', array(), 'system' );

$survivor = null;
foreach ( $logger->all() as $entry ) {
	if ( 'lifecycle_test' === ( $entry['event'] ?? '' ) ) {
		$survivor = $entry;
	}
}
check( null !== $survivor, 'A log entry written before deactivation is present.' );

\ReplicaForge\Plugin::deactivate();

$still_there = null;
foreach ( $logger->all() as $entry ) {
	if ( 'lifecycle_test' === ( $entry['event'] ?? '' ) ) {
		$still_there = $entry;
	}
}
check( null !== $still_there, 'Deactivation keeps stored data rather than destroying it.' );
check(
	$survivor === $still_there,
	'The surviving entry is unchanged, not merely re-created.'
);
check(
	\ReplicaForge\Schema::DB_SCHEMA_VERSION === \ReplicaForge\Schema::installed(),
	'Deactivation keeps the recorded schema version.'
);

// A job record is the user's work, so it must survive too.
$queue = new \ReplicaForge\Job_Queue( new \ReplicaForge\Job_Repository( $logger ), $logger );
$kept  = $queue->enqueue( 'replica', array( 'source_url' => 'https://deactivate.test/' ) );
$kept_id = (string) $kept['job']['job_id'];
\ReplicaForge\Plugin::deactivate();
check(
	null !== ( new \ReplicaForge\Job_Repository( $logger ) )->find( $kept_id ),
	'Deactivation keeps a queued job, because it represents work the user started.'
);

\ReplicaForge\Plugin::activate();
check( false !== wp_next_scheduled( \ReplicaForge\Maintenance::JOB_HOOK ), 'Reactivation restores the schedule.' );

echo "--- 3. Uninstall scope, against real rows ---\n";

// Real rows of each kind, including content that must survive.
$logger->clear();
$logger->error( 'uninstall_test', 'A log entry that must be removed.', array(), 'system' );
\ReplicaForge\Schema::mark_installed( \ReplicaForge\Schema::DB_SCHEMA_VERSION );

$queue = new \ReplicaForge\Job_Queue( new \ReplicaForge\Job_Repository( $logger ), $logger );
$job   = $queue->enqueue( 'replica', array( 'source_url' => 'https://uninstall-probe.test/' ) );
$job_id = (string) $job['job']['job_id'];

$flags = new \ReplicaForge\Feature_Flags();
$flags->set( 'ai_enabled', false );

$settings = new \ReplicaForge\Maintenance( $logger );
$settings->set_setting( 'log_days', 7 );

set_transient( 'replicaforge_uninstall_probe', 'value', HOUR_IN_SECONDS );

// A draft, with a real Elementor document, that uninstall must not touch.
$draft_id = wp_insert_post(
	array(
		'post_title'   => 'ReplicaForge uninstall probe draft',
		'post_status'  => 'draft',
		'post_type'    => 'page',
		'post_content' => '',
	),
	true
);
if ( is_wp_error( $draft_id ) ) {
	throw new RuntimeException( 'FAILED: could not create a probe draft: ' . $draft_id->get_error_message() );
}
$document = wp_json_encode(
	array(
		array(
			'id'       => 'probe001',
			'elType'   => 'heading',
			'settings' => array( 'title' => 'A heading the user owns' ),
		),
	)
);
update_post_meta( $draft_id, '_elementor_data', wp_slash( $document ) );
update_post_meta( $draft_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $draft_id, '_elementor_version', '4.3.2' );
update_post_meta( $draft_id, 'replicaforge_snapshots', wp_slash( wp_json_encode( array( array( 'snapshot_id' => 'snap_probe' ) ) ) ) );
update_post_meta( $draft_id, 'replicaforge_generation_hash', 'probe' );

// A media attachment, which uninstall must not touch either.
$attachment_id = wp_insert_post(
	array(
		'post_title'  => 'ReplicaForge uninstall probe media',
		'post_status' => 'inherit',
		'post_type'   => 'attachment',
	),
	true
);

// An unrelated option and an unrelated post, which must both survive.
update_option( 'some_other_plugin_option', 'must survive', false );
$other_post_id = wp_insert_post(
	array(
		'post_title'   => 'An unrelated post',
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_content' => 'User content.',
	),
	true
);

check( ! empty( $logger->recent( array( 'event' => 'uninstall_test' ), 5 ) ), 'A log entry exists before the uninstall.' );
check( null !== ( new \ReplicaForge\Job_Repository( $logger ) )->find( $job_id ), 'A job record exists before the uninstall.' );
check( false !== get_transient( 'replicaforge_uninstall_probe' ), 'A transient exists before the uninstall.' );
check( '' !== (string) get_post_meta( $draft_id, 'replicaforge_snapshots', true ), 'ReplicaForge post meta exists before the uninstall.' );
check( '' !== (string) get_post_meta( $draft_id, 'replicaforge_generation_hash', true ), 'ReplicaForge generation state exists before the uninstall.' );

// Run the real routine.
define( 'WP_UNINSTALL_PLUGIN', 'replicaforge/uninstall.php' );
require_once WP_PLUGIN_DIR . '/replicaforge/uninstall.php';
$removed = replicaforge_uninstall();

check( is_array( $removed ), 'The uninstall routine reports what it removed.' );
check( isset( $removed['options'], $removed['transients'], $removed['post_meta'] ), 'The report covers options, transients, and post meta.' );

echo "--- 4. What uninstall removed ---\n";

check( empty( $logger->recent( array( 'event' => 'uninstall_test' ), 5 ) ), 'The log is removed.' );
check( 0 === count( $logger->all() ), 'No log entry of any kind survives the uninstall.' );
check( false === get_option( 'replicaforge_version' ), 'The plugin version option is removed.' );
check( false === get_option( \ReplicaForge\Schema::VERSION_OPTION ), 'The schema version option is removed.' );
check( false === get_option( \ReplicaForge\Logger::OPTION, false ), 'The log option is removed.' );
check( null === ( new \ReplicaForge\Job_Repository( $logger ) )->find( $job_id ), 'The job record is removed.' );
check( false === get_transient( 'replicaforge_uninstall_probe' ), 'A ReplicaForge transient is removed.' );
check( false === get_option( \ReplicaForge\Job_Repository::OPTION, false ), 'The jobs option is removed.' );
check( false === get_option( \ReplicaForge\Job_Queue::OPTION, false ), 'The idempotency option is removed.' );
check( false === get_option( \ReplicaForge\Feature_Flags::OPTION, false ), 'The feature flag overrides are removed.' );
check( ! get_option( 'replicaforge_ai_settings' ), 'The AI settings, including the stored key, are removed.' );
check( '' === (string) get_post_meta( $draft_id, 'replicaforge_snapshots', true ), 'ReplicaForge post meta is removed.' );
check( '' === (string) get_post_meta( $draft_id, 'replicaforge_generation_hash', true ), 'ReplicaForge generation state is removed.' );

// The rows are confirmed gone from storage, not merely from the object cache. A raw
// delete would satisfy a cache read while leaving the row in place.
$rows_left = (int) $GLOBALS['wpdb']->get_var(
	$GLOBALS['wpdb']->prepare(
		"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
		$draft_id,
		$GLOBALS['wpdb']->esc_like( 'replicaforge_' ) . '%'
	)
);
check( 0 === $rows_left, 'No ReplicaForge meta row survives in storage for the draft.' );

// The AI key must be gone, not merely unreadable.
$ai = get_option( 'replicaforge_ai_settings' );
check( ! is_array( $ai ) || ! isset( $ai['api_key'] ), 'No API key survives the uninstall.' );

echo "--- 5. What uninstall must NOT have removed ---\n";

check( 'page' === get_post_type( $draft_id ) && 'draft' === get_post_status( $draft_id ), 'The generated draft still exists.' );
check(
	(string) get_post_meta( $draft_id, '_elementor_data', true ) === $document,
	'The Elementor document is byte-identical after the uninstall.'
);
check( 'builder' === get_post_meta( $draft_id, '_elementor_edit_mode', true ), 'Elementor edit mode is untouched.' );
check( '4.3.2' === get_post_meta( $draft_id, '_elementor_version', true ), 'The Elementor version meta is untouched.' );
check( '4.3.2' === get_post_meta( $draft_id, '_elementor_version', true ), 'The Elementor version meta is untouched.' );
check( ! is_wp_error( $attachment_id ) && 'attachment' === get_post_type( $attachment_id ), 'A media attachment still exists.' );
check( 'must survive' === get_option( 'some_other_plugin_option' ), 'An unrelated option survives.' );
check( ! is_wp_error( $other_post_id ) && 'publish' === get_post_status( $other_post_id ), 'An unrelated published post survives.' );
check( 'User content.' === get_post( $other_post_id )->post_content, 'Unrelated post content is unmodified.' );

// The document must still read back as a valid Elementor tree, which is what
// matters to the person who owns it.
$read_back = json_decode( (string) get_post_meta( $draft_id, '_elementor_data', true ), true );
check( is_array( $read_back ) && 'heading' === ( $read_back[0]['elType'] ?? '' ), 'The document still parses as an Elementor tree after the uninstall.' );

echo "--- 6. Cleanup of the probe rows ---\n";

wp_delete_post( $draft_id, true );
if ( ! is_wp_error( $attachment_id ) ) {
	wp_delete_post( $attachment_id, true );
}
wp_delete_post( $other_post_id, true );
delete_option( 'some_other_plugin_option' );

check( null === get_post( $draft_id ), 'The probe draft is gone after the check.' );

// Leave the install in the state activation left it.
\ReplicaForge\Plugin::activate();

echo "\nLifecycle contract test passed. Assertions: {$assertions}\n";
