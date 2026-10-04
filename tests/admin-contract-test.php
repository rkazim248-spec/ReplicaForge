<?php
/**
 * Phase 7 admin verification: the new screens must render, escape, and meet the
 * accessibility requirements they claim to.
 *
 * Rendering is exercised rather than read, because a screen that is described in a
 * comment and never executed is how an escaping mistake survives review.
 */
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/wp-admin/admin.php?page=replicaforge';
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

$administrators = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( empty( $administrators ) ) {
	fwrite( STDERR, "an administrator account is required\n" );
	exit( 2 );
}
wp_set_current_user( (int) $administrators[0]->ID );

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

/* A known starting state, so the screens have something to render. */
delete_option( \ReplicaForge\Job_Repository::OPTION );
delete_option( \ReplicaForge\Job_Queue::OPTION );
delete_option( \ReplicaForge\Logger::OPTION );
\ReplicaForge\Request_Context::reset();

// Every parameter is optional and self-constructing, so the default wiring is
// used. That also exercises the path an install with one phase unavailable would
// take, rather than reaching into the plugin's private services.
$admin  = new \ReplicaForge\Admin();
$plugin = \ReplicaForge\Plugin::instance();

/* Seed one job of each interesting state so the screens have real rows. */
$logger = new \ReplicaForge\Logger( 200, 'debug' );
$queue  = new \ReplicaForge\Job_Queue( new \ReplicaForge\Job_Repository( $logger ), $logger );

$queued = $queue->enqueue( 'replica', array( 'source_url' => 'https://queued.example/page', 'source_host' => 'queued.example' ) );
$queue->claim( (string) $queued['job']['job_id'] );

$running = $queue->enqueue( 'replica', array( 'source_url' => 'https://running.example/page', 'source_host' => 'running.example' ) );
$queue->claim( (string) $running['job']['job_id'] );
$queue->advance( (string) $running['job']['job_id'], 'generate', array( 'draft_id' => 4242 ) );

$done = $queue->enqueue( 'generation', array( 'source_url' => 'https://done.example/', 'source_host' => 'done.example' ) );
$queue->claim( (string) $done['job']['job_id'] );
$queue->complete( (string) $done['job']['job_id'], array( 'draft_id' => 99, 'generation_id' => 'gen_1' ) );

$logger->info( 'demo_event', 'A demonstration log entry.', array( 'note' => 'rendered for the check' ), 'system' );

/**
 * Render one admin screen and capture its markup.
 *
 * @param string   $method Method to call.
 * @param callable $admin  Admin controller.
 * @return string
 */
function render( $method, $admin ) {
	ob_start();
	$admin->$method();
	return (string) ob_get_clean();
}

echo "--- 1. Dashboard renders ---\n";
$dashboard = render( 'render_dashboard', $admin );
check( strlen( $dashboard ) > 500, 'The dashboard produced markup.' );
check( false !== strpos( $dashboard, 'replicaforge-dashboard' ), 'The dashboard layout is present.' );
check( false !== strpos( $dashboard, 'Create New Replica' ), 'The primary call to action is present.' );
check( false !== strpos( $dashboard, 'Recent jobs' ), 'Recent jobs are shown on the dashboard.' );
check( false !== strpos( $dashboard, 'System status' ), 'System status is shown on the dashboard.' );

echo "--- 2. History renders with real rows ---\n";
$history = render( 'render_history_page', $admin );
check( false !== strpos( $history, 'queued.example' ), 'A queued job appears in the history.' );
check( false !== strpos( $history, 'running.example' ), 'A running job appears in the history.' );
check( false !== strpos( $history, 'Draft #99' ), 'A completed job links to the draft it produced.' );
check( false !== strpos( $history, 'role="region"' ), 'The scrollable table is a labelled region for keyboard use.' );
check( false !== strpos( $history, 'tabindex="0"' ), 'The scrollable table can be reached with the keyboard.' );
check( false !== strpos( $history, '<th scope="col"' ), 'Table headers are scoped to their column.' );
check( false !== strpos( $history, 'data-label=' ), 'Cells carry their column name for the card layout.' );

echo "--- 3. Progress is truthful and accessible ---\n";
check( false !== strpos( $history, 'role="progressbar"' ), 'Progress is exposed as a progressbar.' );
check( false !== strpos( $history, 'aria-valuenow=' ), 'Progress reports its value.' );
check( false !== strpos( $history, 'aria-valuemin="0"' ) && false !== strpos( $history, 'aria-valuemax="100"' ), 'Progress declares its range.' );
check( false !== strpos( $history, 'aria-label="Job progress:' ), 'Progress has an accessible name that includes the status.' );
check( false !== strpos( $history, 'is-complete' ), 'A finished bar is distinguishable by more than its width.' );

/* A queued job must not claim to be part-way through. */
$repository = new \ReplicaForge\Job_Repository( $logger );
$queued_job = $repository->find( (string) $queued['job']['job_id'] );
check( 0 === (int) $queued_job['progress'], 'A job that has not started reports zero, not an invented number.' );
$done_job = $repository->find( (string) $done['job']['job_id'] );
check( 100 === (int) $done_job['progress'], 'A completed job reports one hundred percent.' );

echo "--- 4. Status renders with actionable failures ---\n";
$status = render( 'render_status_page', $admin );
check( false !== strpos( $status, 'replicaforge-status' ), 'The status list is present.' );
check( false !== strpos( $status, 'Data schema' ), 'The schema version is shown.' );
check( false !== strpos( $status, 'Stored data' ), 'Stored data is reported.' );
check( false !== strpos( $status, 'replicaforge_run_maintenance' ), 'The cleanup can be run from the screen.' );
check( false !== strpos( $status, 'replicaforge_run_migration' ), 'A migration can be run from the screen.' );
check( false !== strpos( $status, '_wpnonce' ), 'Every action form carries a nonce.' );
check( false !== strpos( $status, 'screen-reader-text' ), 'Status marks have a text equivalent for screen readers.' );

$report = ( new \ReplicaForge\System_Status() )->report();
foreach ( $report['checks'] as $name => $one ) {
	if ( 'fail' === $one['state'] ) {
		check( '' !== (string) $one['action'], "A failing check names the action: {$name}" );
	}
}

echo "--- 5. Logs render, filter, and can be cleared ---\n";
$logs = render( 'render_logs_page', $admin );
check( false !== strpos( $logs, 'demo_event' ), 'A log entry appears on the logs screen.' );
check( false !== strpos( $logs, 'replicaforge_clear_log' ), 'The log can be cleared from the screen.' );
check( false !== strpos( $logs, '_wpnonce' ), 'The clear action carries a nonce.' );
check( false !== strpos( $logs, 'Export log' ), 'The log can be exported from the screen.' );
check( false !== strpos( $logs, 'replicaforge/v1/logs/export' ), 'The export link points at the redacting export route.' );

echo "--- 6. Two independent escaping layers ---\n";

/* First layer: markup is neutralized when the record is written, so no screen
   ever has markup to render. */
$hostile = array(
	'source_url'  => 'https://hostile.example/"><script>alert(1)</script>',
	'source_host' => '<img src=x onerror=alert(1)>',
	'notes'       => '<svg onload=alert(2)>',
);
$hostile_job = $queue->enqueue( 'replica', $hostile );
$stored      = $repository->find( (string) $hostile_job['job']['job_id'] );
$stored_json = (string) wp_json_encode( $stored );

check( false === strpos( $stored_json, '<script' ), 'Markup is removed from a job record when it is written, not only at output.' );
check( false === strpos( $stored_json, '<img' ), 'An event handler is removed from a job record when it is written.' );
check( false === strpos( $stored_json, '<svg' ), 'An svg payload is removed from a job record when it is written.' );
check( false !== strpos( $stored_json, 'hostile.example' ), 'The part of the record that is not markup is still kept, so the row is not blanked.' );

$after = render( 'render_history_page', $admin );
check( false === strpos( $after, '<script>alert(1)' ), 'A script tag in a job record is not emitted as markup.' );
check( false === strpos( $after, '<img src=x' ), 'An event handler in a job record is not emitted as markup.' );
check( false === strpos( $after, '<svg onload' ), 'An svg payload in a job record is not emitted as markup.' );

$logs_after = render( 'render_logs_page', $admin );
check( false === strpos( $logs_after, '<script>' ), 'A log entry cannot inject markup into the logs screen.' );

/* Second layer: a value that needs escaping but contains no markup must be
   escaped rather than dropped. This is what proves the screen is not merely
   relying on the redactor to save it. */
$escaped = array(
	'source_url'  => 'https://amp.example/page',
	'source_host' => 'Tom & Jerry\'s "quoted" page',
);
$escaped_job = $queue->enqueue( 'replica', $escaped );
$kept        = $repository->find( (string) $escaped_job['job']['job_id'] );
check(
	false !== strpos( (string) ( $kept['params']['source_host'] ?? '' ), 'Tom & Jerry' ),
	'A value that needs escaping survives redaction, so the second layer has something to escape.'
);

$after_escaped = render( 'render_history_page', $admin );
check( false !== strpos( $after_escaped, 'Tom &amp; Jerry' ), 'An ampersand in a job record is escaped on output.' );
check( false === strpos( $after_escaped, 'Jerry\'s "quoted"' ), 'A quote in a job record cannot break out of its attribute.' );

echo "--- 7. A non-administrator is refused ---\n";

// `wp_die()` ends the request with `die()`, which a CLI test cannot catch with a
// try/catch — the process simply stops, and the suite is reported as failing for
// a reason that has nothing to do with what it was testing. The handler is
// therefore replaced with one that throws, which is what makes this section able
// to assert anything at all.
//
// This section also silently did nothing until Phase 10 introduced a test that
// creates a subscriber account: with no subscriber on the site, the `if` below
// took the skip branch and the untested `wp_die` path was never reached. It is
// worth stating plainly that a test which passes because its subject is absent
// is not a test.
$rf_throwing_die_handler = static function () {
	return static function ( $message, $title = '', $args = array() ) {
		throw new RuntimeException(
			is_string( $message ) ? $message : 'wp_die: ' . wp_strip_all_tags( (string) $title )
		);
	};
};
add_filter( 'wp_die_handler', $rf_throwing_die_handler );

$subscribers = get_users( array( 'role' => 'subscriber', 'number' => 1 ) );
if ( ! empty( $subscribers ) ) {
	wp_set_current_user( (int) $subscribers[0]->ID );
	foreach ( array( 'render_dashboard', 'render_history_page', 'render_status_page', 'render_logs_page' ) as $method ) {
		$threw = false;
		ob_start();
		try {
			$admin->$method();
		} catch ( \Throwable $exception ) {
			$threw = true;
		}
		$output = (string) ob_get_clean();
		check( $threw || false !== strpos( $output, 'do not have permission' ), "A subscriber is refused: {$method}" );
	}
	wp_set_current_user( (int) $administrators[0]->ID );
} else {
	echo "SKIP: no subscriber account to test with\n";
}

remove_filter( 'wp_die_handler', $rf_throwing_die_handler );

echo "--- 8. Empty states ---\n";
delete_option( \ReplicaForge\Job_Repository::OPTION );
$empty = render( 'render_history_page', $admin );
check( false !== strpos( $empty, 'No jobs have been run yet' ), 'An empty history explains itself rather than showing a blank table.' );
check( false === strpos( $empty, '<table' ), 'No empty table is rendered.' );

// Rendering the screens above legitimately logged activity, so the log is
// cleared first. Without that the empty-state branch is never reached and the
// check would pass only by accident.
( new \ReplicaForge\Logger() )->clear();
$no_logs = render( 'render_logs_page', $admin );
check( false !== strpos( $no_logs, 'No log entries match' ), 'An empty log explains itself.' );
check( false === strpos( $no_logs, '<table' ), 'No empty log table is rendered.' );

echo "--- 9. Assets are scoped to ReplicaForge screens ---\n";

// WordPress decides the hook suffixes when the menu is registered, so the real
// ones are read back rather than guessed at.
// Firing the enqueue hook makes WordPress ask for the current screen, which
// lives in the admin includes rather than in the front-end bootstrap.
if ( ! class_exists( 'WP_Screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
}
if ( ! function_exists( 'convert_to_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}
if ( ! function_exists( 'get_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/template.php';
}
set_current_screen( 'toplevel_page_replicaforge' );

$admin->register();
do_action( 'admin_menu' );

$reflection = new \ReflectionClass( \ReplicaForge\Admin::class );
$properties = array(
	'page_hook',
	'history_page_hook',
	'settings_page_hook',
	'validation_page_hook',
	'corrections_page_hook',
	'status_page_hook',
	'logs_page_hook',
);

$real_hooks = array();
foreach ( $properties as $name ) {
	$field = $reflection->getProperty( $name );
	$field->setAccessible( true );
	$value = (string) $field->getValue( $admin );
	if ( '' === $value ) {
		throw new RuntimeException( 'FAILED: the ' . $name . ' suffix was never assigned, so the menu is not registered.' );
	}
	$real_hooks[ $name ] = $value;
}
check( 7 === count( array_unique( $real_hooks ) ), 'Every ReplicaForge screen has its own menu hook suffix.' );

$method = new \ReflectionMethod( \ReplicaForge\Admin::class, 'enqueue_assets' );
$method->setAccessible( true );

/**
 * Return the handle ReplicaForge would enqueue for a given admin page.
 *
 * @param \ReflectionMethod $method   enqueue_assets().
 * @param object            $admin    Controller.
 * @param string            $hook     Admin page hook suffix.
 * @return array<int, string>
 */
function enqueued_handles( $method, $admin, $hook ) {
	// The registries are reset so each call is observed on its own rather than
	// inheriting what a previous call left registered.
	$GLOBALS['wp_styles']  = new \WP_Styles();
	$GLOBALS['wp_scripts'] = new \WP_Scripts();

	$method->invoke( $admin, $hook );
	do_action( 'admin_enqueue_scripts', $hook );

	$handles = array_keys( $GLOBALS['wp_styles']->registered );
	$handles = array_merge( $handles, array_keys( $GLOBALS['wp_scripts']->registered ) );

	return $handles;
}

// An unrelated admin page must not pull in ReplicaForge's assets, because its
// selectors can collide with another plugin's.
foreach ( array( 'index.php', 'edit.php', 'upload.php', 'plugins.php', 'toplevel_page_woocommerce' ) as $unrelated ) {
	$handles = enqueued_handles( $method, $admin, $unrelated );
	check(
		! in_array( 'replicaforge-admin', $handles, true ),
		'An unrelated admin screen does not load ReplicaForge assets: ' . $unrelated
	);
}

// Every ReplicaForge screen must load the stylesheet, or a new screen would render
// unstyled.
foreach ( $real_hooks as $name => $hook ) {
	$handles = enqueued_handles( $method, $admin, $hook );
	check(
		in_array( 'replicaforge-admin', $handles, true ),
		'A ReplicaForge screen loads the stylesheet: ' . $name
	);
}

// The workflow script is only needed where the guided screens are.
$workflow_screens = array( 'page_hook', 'validation_page_hook', 'corrections_page_hook' );
foreach ( $real_hooks as $name => $hook ) {
	$handles = enqueued_handles( $method, $admin, $hook );
	$expects_script = in_array( $name, $workflow_screens, true );
	$has_script     = in_array( 'replicaforge-admin', $handles, true ) && isset( $GLOBALS['wp_scripts']->registered['replicaforge-admin'] );
	check(
		$has_script === $expects_script,
		( $expects_script ? 'A guided screen loads the workflow script: ' : 'A server-rendered screen does not load the workflow script: ' ) . $name
	);
}
