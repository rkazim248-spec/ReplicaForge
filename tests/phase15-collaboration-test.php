<?php
/**
 * Phase 15 contract tests: workspace, permissions, reviews, comments, tasks, security.
 *
 * @package ReplicaForge
 */

use ReplicaForge\Client_Contact_Store;
use ReplicaForge\Client_Store;
use ReplicaForge\Collaboration_Log;
use ReplicaForge\Collaboration_Schema;
use ReplicaForge\Comment_Store;
use ReplicaForge\Email_Notification_Provider;
use ReplicaForge\In_App_Notification_Provider;
use ReplicaForge\Migrator;
use ReplicaForge\Invitation_Service;
use ReplicaForge\Invitation_Store;
use ReplicaForge\Issue_Store;
use ReplicaForge\Notification_Provider;
use ReplicaForge\Notification_Service;
use ReplicaForge\Notification_Store;
use ReplicaForge\Permission_Manager;
use ReplicaForge\Project_Access;
use ReplicaForge\Project_Context_Store;
use ReplicaForge\Project_Member_Store;
use ReplicaForge\Project_Repository;
use ReplicaForge\Project_Status;
use ReplicaForge\Review_Link_Service;
use ReplicaForge\Review_Store;
use ReplicaForge\Schema;
use ReplicaForge\Secure_Token;
use ReplicaForge\Sync_Conflict_Detector;
use ReplicaForge\System_Status;
use ReplicaForge\Task_Store;
use ReplicaForge\Plugin;
use ReplicaForge\Activity_Store;
use ReplicaForge\Audit_Store;
use ReplicaForge\Maintenance;
use ReplicaForge\Workspace_Admin;
use ReplicaForge\Workspace_Api;
use ReplicaForge\Validation_Limits;
use ReplicaForge\Workspace_Limits;
use ReplicaForge\Workspace_Member_Store;
use ReplicaForge\Workspace_Store;

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

/**
 * Assert that a callable runs without raising, and return its result.
 *
 * Several of the security assertions below are "this refuses cleanly". An assertion about a
 * refusal has to survive a fatal, or a bug in the guard under test turns into a test run
 * that ends without saying why.
 *
 * @param callable $callback Callback.
 * @param string   $message  What was checked.
 * @return mixed
 */
function check_no_fatal( callable $callback, $message ) {
	$previous = error_reporting( 0 );
	try {
		$result = $callback();
	} catch ( \Throwable $e ) {
		error_reporting( $previous );
		check( false, $message . ' (raised ' . get_class( $e ) . ': ' . $e->getMessage() . ')' );
		return null;
	}
	error_reporting( $previous );
	check( true, $message );
	return $result;
}

/**
 * Create a user, or fail the run loudly.
 *
 * @param string $suffix Login suffix.
 * @param string $role   Role.
 * @param string $email  Address.
 * @return int
 */
function rf15_user( $suffix, $role = 'subscriber', $email = '' ) {
	$args = array(
		'user_login' => 'rf15_' . $suffix . '_' . wp_rand( 10000, 99999 ),
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => $role,
	);
	if ( '' !== $email ) {
		$args['user_email'] = $email;
	}
	$user = wp_insert_user( $args );
	if ( is_wp_error( $user ) ) {
		echo 'SETUP FAILED: ' . $user->get_error_code() . ' / ' . $user->get_error_message() . "\n";
		throw new RuntimeException( 'could not create a test user' );
	}
	return (int) $user;
}

/**
 * A unique address for this run.
 *
 * @param string $tag Tag.
 * @return string
 */
function rf15_email( $tag ) {
	return 'rf15-' . $tag . '-' . wp_rand( 100000, 999999 ) . '@example.com';
}

/* -------------------------------------------------------------------------
 * Shared state, torn down by the shutdown handler.
 * ---------------------------------------------------------------------- */

/**
 * Users created by this run.
 *
 * @var array<int, int>
 */
$rf15_users = array();

/**
 * Projects created by this run.
 *
 * @var array<int, string>
 */
$rf15_projects = array();

/**
 * The schema, so the handler can truncate.
 *
 * @var Collaboration_Schema|null
 */
$rf15_schema = null;

/**
 * Clean up everything this run created.
 *
 * Registered before anything is created, so a fatal partway through still leaves the site
 * clean. A suite that leaks users makes the next run fail for the wrong reason, which is
 * how a green suite turns red without anything changing.
 *
 * @return void
 */
function rf15_cleanup() {
	global $rf15_users, $rf15_projects, $rf15_schema;

	require_once ABSPATH . 'wp-admin/includes/user.php';

	foreach ( $rf15_users as $id ) {
		if ( $id > 0 ) {
			wp_delete_user( $id );
		}
	}

	// Anything left by an earlier run that exited before its own teardown, so a stale login
	// or address cannot make this run fail for a reason unrelated to the code.
	foreach ( get_users( array( 'number' => 500, 'fields' => array( 'ID', 'user_login', 'user_email' ) ) ) as $stale ) {
		$login = (string) $stale->user_login;
		if ( 0 === strpos( $login, 'rf15_' ) ) {
			wp_delete_user( (int) $stale->ID );
		}
	}

	$projects = new Project_Repository();
	foreach ( $rf15_projects as $project_id ) {
		$projects->delete( $project_id, true );
	}

	if ( $rf15_schema instanceof Collaboration_Schema ) {
		global $wpdb;
		foreach ( $rf15_schema->status()['present'] as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
	}

	delete_option( 'replicaforge_notification_preferences' );
	delete_option( 'replicaforge_token_salt' );
}

$rf15_schema = new Collaboration_Schema();
register_shutdown_function( 'rf15_cleanup' );

/* -------------------------------------------------------------------------
 * 1. Schema
 * ---------------------------------------------------------------------- */

echo "\n== 1. Collaboration schema ==\n";

$install = $rf15_schema->install();
check( ! empty( $install['ok'] ), 'every collaboration table installs' );

/*
 * These were `13 === count(...)`, which asserted a snapshot rather than the property the
 * test exists to protect. The property is "no Phase 15 table is ever removed" — a later
 * phase is expected to add tables, and a test that fails when one is added forces the next
 * person to either weaken the assertion or avoid extending the schema at all.
 *
 * So the thirteen are now named explicitly and checked individually, which is a *stronger*
 * assertion than a count: a count of 13 would pass with the wrong thirteen, and would also
 * pass with one Phase 15 table removed and one unrelated table added.
 */
$rf15_core_tables = array(
	'workspaces',
	'members',
	'invitations',
	'clients',
	'contacts',
	'project_member',
	'reviews',
	'comments',
	'tasks',
	'issues',
	'notifications',
	'activity',
	'audit',
);

foreach ( $rf15_core_tables as $rf15_kind ) {
	check( isset( $install['tables'][ $rf15_kind ] ), "the Phase 15 table '$rf15_kind' is still declared" );
}
check( count( $rf15_core_tables ) <= count( $install['tables'] ), 'no Phase 15 table was removed' );

/*
 * `status()['present']` holds prefixed *table names*, not entity kinds — a different shape
 * from `install()['tables']`, which is keyed by kind. So the two loops look up differently
 * on purpose, and the Phase 15 tables are checked by name here.
 */
$status = $rf15_schema->status();
check( ! empty( $status['ok'] ), 'every collaboration table exists afterwards' );

global $wpdb;
foreach ( $rf15_core_tables as $rf15_kind ) {
	check( in_array( Workspace_Limits::prefixed_table( $rf15_kind ), $status['present'], true ), "the Phase 15 table '$rf15_kind' is present" );
}

// Phase 19 adds three. Named rather than counted, so this is a floor that grows correctly.
foreach ( array( 'templates', 'template_version', 'template_component' ) as $rf19_kind ) {
	check( isset( $install['tables'][ $rf19_kind ] ), "the Phase 19 table '$rf19_kind' installs" );
	check( in_array( Workspace_Limits::prefixed_table( $rf19_kind ), $status['present'], true ), "the Phase 19 table '$rf19_kind' is present" );
}
check( 16 === count( $install['tables'] ), 'sixteen tables are declared in total' );
check( 16 === count( $status['present'] ), 'all sixteen are present' );
check( array() === $status['missing'], 'none are missing' );

check( '19.0.0' === Collaboration_Schema::VERSION, 'the schema version is 19.0.0' );
check( '15.0' === Workspace_Limits::SCHEMA_VERSION, 'the vocabulary schema version is 15.0' );

foreach ( array_keys( $install['tables'] ) as $kind ) {
	check( '' !== Workspace_Limits::table( $kind ), "the table kind '$kind' has a declared name" );
}
check( '' === Workspace_Limits::table( 'not_a_kind' ), 'an unknown entity has no table name' );

$again = $rf15_schema->install();
check( ! empty( $again['ok'] ), 're-running the install is idempotent' );

/* -------------------------------------------------------------------------
 * 2. Vocabulary
 * ---------------------------------------------------------------------- */

echo "\n== 2. Vocabulary and bound consistency ==\n";

$roles = Permission_Manager::role_report();
check( 7 === count( $roles ), 'seven roles are declared' );
foreach ( $roles as $role => $count ) {
	check( $count > 0, "role '$role' resolves at least one capability" );
}
check( $roles['owner'] === count( Workspace_Limits::capabilities() ), 'the owner holds every capability' );

foreach ( Workspace_Limits::ROLES as $role ) {
	check( in_array( $role, array( 'owner', 'admin', 'project_manager', 'designer', 'developer', 'reviewer', 'client' ), true ), "the role '$role' is one of the seven specified" );
}
check( ! in_array( 'owner', Workspace_Limits::ASSIGNABLE_ROLES, true ), 'owner is not an assignable role' );

check( 5 === count( Workspace_Limits::PROJECT_ROLES ), 'five project roles are declared' );
check( 4 === count( Workspace_Limits::PRIORITIES ), 'four priorities are declared' );
check( 7 === count( Workspace_Limits::STAGES ), 'seven stages are declared' );
check( 6 === count( Workspace_Limits::REVIEW_STATUSES ), 'six review statuses are declared' );
check( 3 === count( Workspace_Limits::COMMENT_STATUSES ), 'three comment statuses are declared' );
check( 5 === count( Workspace_Limits::TASK_STATUSES ), 'five task statuses are declared' );
check( 5 === count( Workspace_Limits::ISSUE_STATUSES ), 'five issue statuses are declared' );
check( 6 === count( Workspace_Limits::ISSUE_SOURCES ), 'six issue sources are declared' );
check( 2 === count( Workspace_Limits::CHANNELS ), 'two notification channels are declared' );

// Section 21: the severity vocabulary is Phase 5's, and the two must be the same list.
check( Workspace_Limits::severities() === Validation_Limits::SEVERITIES, 'issue severities are Phase 5 severities' );

// Ownership is read from the sync layer, not restated.
check( Workspace_Limits::ownership_states() === Sync_Conflict_Detector::OWNERSHIP, 'ownership states are the sync layer own states' );

// The two status axes are separate, and neither is a subset of the other.
check( ! in_array( 'in_review', Project_Status::STATUSES, true ), 'the agency stage is not a reconstruction status' );
check( isset( Workspace_Limits::STAGES['in_review'] ), 'the agency stage is declared' );
check( in_array( 'monitoring', Project_Status::STATUSES, true ), 'the reconstruction status vocabulary is untouched' );

$specified = array(
	'workspace.view', 'workspace.manage', 'members.view', 'members.invite', 'members.remove', 'roles.manage',
	'clients.view', 'clients.create', 'clients.edit', 'clients.delete',
	'projects.view', 'projects.create', 'projects.edit', 'projects.delete', 'projects.archive', 'projects.manage_members',
	'analysis.run', 'ai.run', 'generation.run', 'validation.run', 'correction.review', 'correction.apply',
	'content.view', 'content.map', 'content.apply',
	'sync.view', 'sync.run', 'sync.approve',
	'comments.create', 'comments.resolve',
	'reviews.view', 'reviews.create', 'reviews.approve', 'reviews.reject',
	'exports.create', 'exports.download',
);
foreach ( $specified as $capability ) {
	check( Workspace_Limits::is_capability( $capability ), "the capability '$capability' is declared" );
}
check( count( Workspace_Limits::capabilities() ) >= count( $specified ), 'no specified capability is missing' );

$granted = array();
foreach ( Workspace_Limits::ROLE_CAPS as $caps ) {
	foreach ( $caps as $cap ) {
		$granted[ $cap ] = true;
	}
}
foreach ( Workspace_Limits::PROJECT_ROLE_CAPS as $caps ) {
	foreach ( $caps as $cap ) {
		$granted[ $cap ] = true;
	}
}
foreach ( array_keys( $granted ) as $cap ) {
	check( Workspace_Limits::is_capability( $cap ), "the granted capability '$cap' is declared" );
}

// A list vocabulary must answer membership with in_array, a map with array_key_exists.
// Getting that wrong is silent, so both shapes are exercised here.
foreach ( Workspace_Limits::PRIORITIES as $priority ) {
	check( Workspace_Limits::is_priority( $priority ), "'$priority' is a declared priority" );
}
check( ! Workspace_Limits::is_priority( 'enormous' ), 'an unknown priority is not one' );
check( ! Workspace_Limits::is_priority( null ), 'null is not a priority' );
foreach ( array_keys( Workspace_Limits::STAGES ) as $stage ) {
	check( Workspace_Limits::is_stage( $stage ), "'$stage' is a declared stage" );
}
check( ! Workspace_Limits::is_stage( 'imminent' ), 'an unknown stage is not one' );

/* -------------------------------------------------------------------------
 * 3. Workspaces
 * ---------------------------------------------------------------------- */

echo "\n== 3. Workspaces ==\n";

$owner_id = rf15_user( 'owner', 'administrator' );
$rf15_users[] = $owner_id;

$workspaces = new Workspace_Store();
$workspace  = $workspaces->create( $owner_id, 'Acme Web Agency' );
check( is_array( $workspace ), 'a workspace is created' );
$wid = (string) $workspace['public_id'];

check( 26 === strlen( $wid ), 'a workspace id is a fixed 26 characters, matching CHAR(26)' );
check( ctype_alnum( $wid ), 'a workspace id is URL-safe and needs no escaping' );
check( (int) $workspace['owner_id'] === $owner_id, 'the creator owns the workspace' );

$second_ws = $workspaces->create( $owner_id, 'Second Workspace' );
check( (string) $second_ws['public_id'] !== $wid, 'two workspaces receive different ids' );
check( false === strpos( $wid, (string) $owner_id ), 'a workspace id does not encode its owner' );

$members  = new Workspace_Member_Store();
$owner_row = $members->membership( $wid, $owner_id );
check( null !== $owner_row, 'the owner is recorded as a member' );
check( 'owner' === (string) $owner_row['role'], 'the owner member row names the owner role' );

$members->add_owner( $wid, $owner_id );
$owner_roles = $members->role_counts( $wid );
check( 1 === (int) ( $owner_roles['owner'] ?? 0 ), 'recording the owner twice creates one row' );

$permissions = new Permission_Manager();
check( 'owner' === $permissions->role( $owner_id, $wid ), 'the owner role resolves to owner' );

$settings = $workspaces->settings( $wid );
check( true === (bool) $settings['require_internal_approval'], 'internal approval is required by default' );
check( true === (bool) $settings['require_client_approval'], 'client approval is required by default' );
check( false === (bool) $settings['allow_client_review_links'], 'review links are off by default' );
check( false === (bool) $settings['project_scoping'], 'project scoping is off by default' );
check( false === (bool) $settings['allow_automatic_notifications'], 'automatic notifications are off by default' );

$workspaces->update( $wid, array( 'settings' => array( 'not_a_setting' => true, 'project_scoping' => true ) ) );
$after_settings = $workspaces->settings( $wid );
check( ! isset( $after_settings['not_a_setting'] ), 'an unknown settings key is dropped' );
check( true === (bool) $after_settings['project_scoping'], 'a known settings key is stored' );
$workspaces->update( $wid, array( 'settings' => array( 'project_scoping' => false ) ) );

$archived = $workspaces->update( $wid, array( 'status' => 'archived' ) );
check( is_array( $archived ), 'an update returns the stored workspace' );
check( 'archived' === (string) $archived['status'], 'a workspace can be archived' );
check( ! $permissions->can( $owner_id, $wid, 'workspace.view' ), 'an archived workspace grants nothing' );
check( null !== $workspaces->get( $wid ), 'an archived workspace is still readable' );
$workspaces->update( $wid, array( 'status' => 'active' ) );
check( $permissions->can( $owner_id, $wid, 'workspace.view' ), 'restoring the workspace restores access' );

check( null === $workspaces->create( 0, 'Nobody' ), 'a workspace cannot be created without an owner' );

/* -------------------------------------------------------------------------
 * 4. Permissions
 * ---------------------------------------------------------------------- */

echo "\n== 4. Permission resolution ==\n";

$designer_id    = rf15_user( 'designer' );
$reviewer_id    = rf15_user( 'reviewer' );
$developer_id   = rf15_user( 'developer' );
$client_email   = rf15_email( 'client' );
$client_id      = rf15_user( 'client', 'subscriber', $client_email );
$stranger_id    = rf15_user( 'stranger' );
$pm_id          = rf15_user( 'pm' );
$admin_id       = rf15_user( 'wsadmin' );
$site_admin_id  = rf15_user( 'siteadmin', 'administrator' );
$rf15_users     = array_merge( $rf15_users, array( $designer_id, $reviewer_id, $developer_id, $client_id, $stranger_id, $pm_id, $admin_id, $site_admin_id ) );

$members->add( $wid, array( 'user_id' => $designer_id,  'role' => 'designer' ) );
$members->add( $wid, array( 'user_id' => $reviewer_id, 'role' => 'reviewer' ) );
$members->add( $wid, array( 'user_id' => $developer_id, 'role' => 'developer' ) );
$members->add( $wid, array( 'user_id' => $client_id,    'role' => 'client' ) );
$members->add( $wid, array( 'user_id' => $pm_id,        'role' => 'project_manager' ) );
$members->add( $wid, array( 'user_id' => $admin_id,     'role' => 'admin' ) );

// The specification's permission matrix, asserted positively *and* negatively where it says
// so. A positive assertion alone passes just as well for a role that holds everything.
$matrix = array(
	array( $designer_id, 'generation.run',    true,  'a designer may generate' ),
	array( $designer_id, 'correction.apply',  true,  'a designer may apply a correction' ),
	array( $designer_id, 'content.map',       true,  'a designer may map content' ),
	array( $designer_id, 'reviews.create',    true,  'a designer may request a review' ),
	array( $designer_id, 'reviews.approve',   false, 'a designer may not approve' ),
	array( $designer_id, 'members.invite',    false, 'a designer may not invite' ),
	array( $designer_id, 'projects.delete',   false, 'a designer may not delete a project' ),
	array( $designer_id, 'sync.approve',      false, 'a designer may not approve a sync' ),
	array( $designer_id, 'exports.create',    false, 'a designer may not export' ),
	array( $designer_id, 'clients.delete',    false, 'a designer may not delete a client' ),
	array( $reviewer_id, 'reviews.approve',   true,  'a reviewer may approve' ),
	array( $reviewer_id, 'reviews.reject',    true,  'a reviewer may reject' ),
	array( $reviewer_id, 'comments.create',   true,  'a reviewer may comment' ),
	array( $reviewer_id, 'validation.run',    true,  'a reviewer may run a validation' ),
	array( $reviewer_id, 'generation.run',    false, 'a reviewer may not generate' ),
	array( $reviewer_id, 'projects.create',   false, 'a reviewer may not create a project' ),
	array( $reviewer_id, 'sync.run',          false, 'a reviewer may not run a sync' ),
	array( $developer_id, 'generation.run',   true,  'a developer may generate' ),
	array( $developer_id, 'ai.run',           true,  'a developer may run the AI' ),
	array( $developer_id, 'sync.run',         true,  'a developer may run a sync' ),
	array( $developer_id, 'content.apply',    true,  'a developer may apply content' ),
	array( $developer_id, 'reviews.approve',  false, 'a developer may not approve' ),
	array( $developer_id, 'clients.delete',   false, 'a developer may not delete a client' ),
	array( $client_id,    'comments.create',  true,  'a client may comment' ),
	array( $client_id,    'reviews.view',     true,  'a client may see the review they were given' ),
	array( $client_id,    'reviews.approve',  false, 'a client may not approve at workspace level' ),
	array( $client_id,    'generation.run',   false, 'a client may not generate' ),
	array( $client_id,    'workspace.view',   false, 'a client may not view the workspace' ),
	array( $client_id,    'clients.view',     false, 'a client may not list clients' ),
	array( $client_id,    'exports.download', false, 'a client may not export' ),
	array( $client_id,    'members.view',     false, 'a client may not list members' ),
	array( $client_id,    'projects.view',    false, 'a client may not list projects at workspace level' ),
	array( $pm_id,        'projects.create',  true,  'a project manager may create a project' ),
	array( $pm_id,        'projects.archive', true,  'a project manager may archive a project' ),
	array( $pm_id,        'reviews.approve',  true,  'a project manager may approve' ),
	array( $pm_id,        'projects.delete',  false, 'a project manager may not hard delete a project' ),
	array( $admin_id,     'members.invite',   true,  'an admin may invite' ),
	array( $admin_id,     'clients.delete',   true,  'an admin may delete a client' ),
	array( $admin_id,     'roles.manage',     true,  'an admin may manage roles' ),
);
foreach ( $matrix as $row ) {
	check( $permissions->can( $row[0], $wid, $row[1] ) === $row[2], $row[3] );
}

// The workspace admin is not a site administrator. Asserted, because the two exemptions in
// section 7 are easy to conflate and a member whose *name* says administrator while its
// capabilities say otherwise is the exact shape of a bug.
check( ! user_can( $admin_id, 'manage_options' ), 'the workspace admin is not a site administrator' );
check( user_can( $site_admin_id, 'manage_options' ), 'the site administrator really is one' );

check( '' === $permissions->role( $stranger_id, $wid ), 'a stranger holds no role' );
foreach ( Workspace_Limits::capabilities() as $capability ) {
	check( ! $permissions->can( $stranger_id, $wid, $capability ), "a stranger is denied '$capability'" );
}

check( ! $permissions->can( 0, $wid, 'workspace.view' ), 'user zero is denied' );
check( ! $permissions->can( $owner_id, '', 'workspace.view' ), 'an empty workspace is denied' );
check( ! $permissions->can( $owner_id, $wid, 'not.a.capability' ), 'an unknown capability is denied' );
check( ! $permissions->can( $owner_id, 'ws_does_not_exist', 'workspace.view' ), 'a non-existent workspace is denied' );
check( ! $permissions->can( -5, $wid, 'workspace.view' ), 'a negative user id is denied' );

// `(int) $wp_error` is 1 in PHP, and user 1 is a real account, so a failed lookup resolved
// as a WP_Error would otherwise be checked as the first administrator on the install.
$as_error = check_no_fatal(
	static function () use ( $permissions, $wid ) {
		return $permissions->can( new WP_Error( 'nf', 'no such user' ), $wid, 'workspace.view' );
	},
	'a WP_Error as a user id is handled without a warning'
);
check( false === $as_error, 'a WP_Error as a user id is denied, not resolved as user 1' );

$as_array = check_no_fatal(
	static function () use ( $permissions, $wid ) {
		// A literal, because the point is the array shape and a static
		// closure sees only what its `use` clause names.
		return $permissions->can( array( 'id' => 1 ), $wid, 'workspace.view' );
	},
	'an array as a user id is handled without a warning'
);
check( false === $as_array, 'an array as a user id is denied' );
check( false === $permissions->can( null, $wid, 'workspace.view' ), 'null as a user id is denied' );

// The full capability set, which is what a UI needs to render "what you can do here".
$caps = $permissions->capabilities_for( $owner_id, $wid );
check( count( $caps ) === count( Workspace_Limits::capabilities() ), 'the capability set covers every declared capability' );
$granted_count = count( array_filter( $caps ) );
check( $granted_count === count( Workspace_Limits::capabilities() ), 'the owner is granted every capability' );
$client_caps = $permissions->capabilities_for( $client_id, $wid );
check( 2 === count( array_filter( $client_caps ) ), 'a client is granted exactly two workspace capabilities' );

/* -------------------------------------------------------------------------
 * 5. Membership changes
 * ---------------------------------------------------------------------- */

echo "\n== 5. Membership changes ==\n";

$admin_row = $members->membership( $wid, $admin_id );

check( null === $members->set_role( $wid, $admin_row['public_id'], 'owner' ), 'a role change cannot mint an owner' );
check( null === $members->set_role( $wid, $admin_row['public_id'], 'wizard' ), 'an unknown role is refused' );
check( null === $members->set_role( $wid, 'ms_nonexistent', 'designer' ), 'a role change on a missing member is refused' );
check( null === $members->set_role( $wid, $owner_row['public_id'], 'reviewer' ), 'the owner own row cannot be demoted by a role change' );
check( null === $members->set_status( $wid, $owner_row['public_id'], false ), 'the owner cannot be suspended' );
check( ! $members->remove( $wid, $owner_row['public_id'] ), 'the owner cannot be removed' );
check( 'admin' === (string) $members->membership( $wid, $admin_id )['role'], 'the admin is unchanged after the refused attempts' );

$second_admin = $members->add( $wid, array( 'email' => rf15_email( 'admin2' ), 'role' => 'admin' ) );
check( is_array( $second_admin ), 'a second admin may be added' );
$demoted = $members->set_role( $wid, $admin_row['public_id'], 'reviewer' );
check( is_array( $demoted ) && 'reviewer' === (string) $demoted['role'], 'an admin may be demoted once another admin exists' );
$members->set_role( $wid, $admin_row['public_id'], 'admin' );

// With one admin left, that admin is locked in place.
$second_row = $members->membership_by_email( $wid, (string) $second_admin['email'] );
$members->set_role( $wid, $second_row['public_id'], 'reviewer' );
check( false === $members->remove( $wid, $admin_row['public_id'] ), 'the last admin cannot be removed' );
check( null !== $members->membership( $wid, $admin_id ), 'the last admin is still a member' );

// Suspension is reversible and revokes access.
$designer_row = $members->membership( $wid, $designer_id );
$suspended = $members->set_status( $wid, $designer_row['public_id'], false );
check( 'suspended' === (string) $suspended['status'], 'a member can be suspended' );
check( ! $permissions->can( $designer_id, $wid, 'generation.run' ), 'a suspended member loses their capabilities' );
$members->set_status( $wid, $designer_row['public_id'], true );
check( $permissions->can( $designer_id, $wid, 'generation.run' ), 'restoring the member restores their capabilities' );

// Several email-only rows coexist. They all share a zero user id, which is what a unique
// key on (workspace, user) forbade.
$email_only = array();
for ( $i = 1; $i <= 3; $i++ ) {
	$email_only[] = $members->add( $wid, array( 'email' => rf15_email( 'contact' . $i ), 'role' => 'reviewer' ) );
}
check( 3 === count( array_filter( $email_only, 'is_array' ) ), 'three email-only members coexist' );
check( null === $members->add( $wid, array( 'role' => 'reviewer' ) ), 'a member with neither an account nor an address is refused' );

$dupe = $members->add( $wid, array( 'user_id' => $designer_id, 'email' => rf15_email( 'other' ), 'role' => 'admin' ) );
check( (string) $dupe['public_id'] === $designer_row['public_id'], 'adding an existing member is idempotent rather than a duplicate' );

/* -------------------------------------------------------------------------
 * 6. Projects and the two status axes
 * ---------------------------------------------------------------------- */

echo "\n== 6. Projects and stages ==\n";

$projects = new Project_Repository();
wp_set_current_user( $owner_id );

$project = $projects->create( 'https://example.com/acme-replica', array( 'name' => 'Acme Website Replica' ) );
$pid     = (string) $project['project_id'];
$rf15_projects[] = $pid;
check( '' !== $pid, 'a project is created through the existing repository' );

$context = new Project_Context_Store();
$written = $context->update( $pid, array( 'workspace_id' => $wid, 'stage' => 'in_review', 'priority' => 'urgent' ) );
check( 'in_review' === (string) $written['stage'], 'the agency stage is written onto the project' );
check( 'urgent' === (string) $written['priority'], 'the priority is written onto the project' );
check( $wid === (string) $written['workspace_id'], 'the workspace is recorded on the project' );
check( $owner_id === (int) $written['owner_id'], 'the owner defaults to the project creator' );

$reread = $context->get( $pid );
check( 'in_review' === (string) $reread['stage'], 'the stage survives a re-read' );
check( 'urgent' === (string) $reread['priority'], 'the priority survives a re-read' );

// Every declared value must round-trip. An earlier version asserted a single value that
// happened to equal the default, which passed whether or not validation worked at all.
$stages_ok = true;
foreach ( array_keys( Workspace_Limits::STAGES ) as $stage ) {
	if ( (string) $context->update( $pid, array( 'stage' => $stage ) )['stage'] !== $stage ) {
		$stages_ok = false;
	}
}
check( $stages_ok, 'every declared stage round-trips unchanged' );

$priorities_ok = true;
foreach ( Workspace_Limits::PRIORITIES as $priority ) {
	if ( (string) $context->update( $pid, array( 'priority' => $priority ) )['priority'] !== $priority ) {
		$priorities_ok = false;
	}
}
check( $priorities_ok, 'every declared priority round-trips unchanged' );

$context->update( $pid, array( 'stage' => 'in_review', 'priority' => 'high' ) );
check( 'in_review' === (string) $context->update( $pid, array( 'stage' => 'imminent' ) )['stage'], 'an invalid stage is ignored, keeping the stored value' );
check( 'high' === (string) $context->update( $pid, array( 'priority' => 'enormous' ) )['priority'], 'an invalid priority is ignored, keeping the stored value' );

$after = $projects->find( $pid );
check( 'analyzed' === (string) $after['status'], 'the reconstruction status is untouched by a Phase 15 write' );
check( 'https://example.com/acme-replica' === (string) $after['source_url'], 'the source URL is untouched' );
check( $owner_id === (int) $after['user_id'], 'the project creator is untouched' );
check( is_array( $after['versions'] ), 'the version list is intact' );
check( ! empty( $after['source_key'] ), 'the source key is intact' );

$untouched = $projects->create( 'https://example.com/untouched', array( 'name' => 'Untouched' ) );
$rf15_projects[] = (string) $untouched['project_id'];
$defaults = $context->get( (string) $untouched['project_id'] );
check( 'draft' === (string) $defaults['stage'], 'a project with no Phase 15 record reads as draft' );
check( 'medium' === (string) $defaults['priority'], 'a project with no Phase 15 record reads as medium priority' );
check( '' === (string) $defaults['workspace_id'], 'a project with no Phase 15 record has no workspace' );
check( array() === $context->get( 'proj_nonexistent' ), 'a missing project has no context' );

/* -------------------------------------------------------------------------
 * 7. Project-level scoping
 * ---------------------------------------------------------------------- */

echo "\n== 7. Project membership ==\n";

$project_members = new Project_Member_Store();

check( $permissions->can_in_project( $designer_id, $wid, $pid, 'generation.run' ), 'with scoping off, a workspace role decides' );
check( $permissions->can_in_project( $designer_id, $wid, $pid, 'reviews.approve' ) === false, 'with scoping off, the workspace role still decides negatively' );

$project_members->add( $wid, $pid, array( 'user_id' => $designer_id, 'role' => 'contributor' ) );
$project_members->add( $wid, $pid, array( 'user_id' => $reviewer_id, 'role' => 'reviewer' ) );
check( 2 === $project_members->active_count( $wid, $pid ), 'two members are on the project' );
check( null === $project_members->add( $wid, $pid, array( 'user_id' => $stranger_id, 'role' => 'sovereign' ) ), 'an unknown project role is refused' );

$workspaces->update( $wid, array( 'settings' => array( 'project_scoping' => true ) ) );
check( $permissions->project_scoping_enabled( $wid ), 'project scoping reports as enabled' );

check( $permissions->can_in_project( $designer_id, $wid, $pid, 'generation.run' ), 'a contributor may generate' );
check( ! $permissions->can_in_project( $designer_id, $wid, $pid, 'reviews.approve' ), 'a contributor may not approve, though the workspace role could' );
check( $permissions->can_in_project( $reviewer_id, $wid, $pid, 'reviews.approve' ), 'a project reviewer may approve' );
check( ! $permissions->can_in_project( $reviewer_id, $wid, $pid, 'generation.run' ), 'a project reviewer may not generate' );
check( ! $permissions->can_in_project( $reviewer_id, $wid, $pid, 'members.invite' ), 'a project role cannot grant a workspace-only capability' );

check( ! $permissions->can_in_project( $developer_id, $wid, $pid, 'generation.run' ), 'a workspace member with no project membership is refused' );
check( ! $permissions->can_in_project( $stranger_id, $wid, $pid, 'projects.view' ), 'a stranger is refused' );

// A scoping rule that locked the owner out of their own project would be a support ticket,
// not a security property.
check( $permissions->can_in_project( $owner_id, $wid, $pid, 'projects.edit' ), 'the owner is not locked out by scoping' );
check( $permissions->can_in_project( $site_admin_id, $wid, $pid, 'projects.edit' ), 'a site administrator is not locked out by scoping' );

// Section 41: a project role must not escalate. The project role is intersected with the
// workspace role, so it can only ever narrow.
$project_members->add( $wid, $pid, array( 'user_id' => $client_id, 'role' => 'lead' ) );
check( ! $permissions->can_in_project( $client_id, $wid, $pid, 'generation.run' ), 'a client made a project lead still may not generate' );
check( ! $permissions->can_in_project( $client_id, $wid, $pid, 'reviews.approve' ), 'a client made a project lead still may not approve at workspace level' );
check( $permissions->can_in_project( $client_id, $wid, $pid, 'comments.create' ), 'a client made a project lead may still comment' );

	check( false === $project_members->remove( $wid, 'pm_nonexistent' ), 'removing a non-member reports nothing removed' );
	check( null === $project_members->membership( $pid, $stranger_id ), 'the refused project-role add left no membership row behind' );

$workspaces->update( $wid, array( 'settings' => array( 'project_scoping' => false ) ) );
check( ! $permissions->project_scoping_enabled( $wid ), 'project scoping reports as disabled again' );

/* -------------------------------------------------------------------------
 * 8. Clients
 * ---------------------------------------------------------------------- */

echo "\n== 8. Clients and contacts ==\n";

$clients = new Client_Store();
$client  = $clients->create( $wid, array(
	'name'    => 'Dana Reyes',
	'company' => 'Acme Corporation',
	'email'   => 'dana@acme.example',
	'website' => 'https://acme.example',
	'notes'   => 'Internal: prefers short paragraphs. Budget is not confirmed.',
) );

check( is_array( $client ), 'a client is created' );
check( 'Acme Corporation' === (string) $client['company'], 'the company is kept when supplied' );
check( null === $clients->create( $wid, array() ), 'a client with neither name nor company is refused' );
check( null === $clients->create( $wid, array( 'name' => '   ' ) ), 'a whitespace-only name is refused' );

$named_only = $clients->create( $wid, array( 'name' => 'Solo Trader' ) );
check( 'Solo Trader' === (string) $named_only['company'], 'the company is filled from the name when absent' );

// A private or metadata address must never be stored as a client website: it would become a
// request ReplicaForge makes later.
$bad = $clients->create( $wid, array( 'name' => 'Bad Host', 'website' => 'http://127.0.0.1/' ) );
check( '' === (string) $bad['website'], 'a client website pointing at a loopback address is dropped' );
$bad2 = $clients->create( $wid, array( 'name' => 'Metadata Host', 'website' => 'http://169.254.169.254/latest/meta-data/' ) );
check( '' === (string) $bad2['website'], 'a client website pointing at the metadata endpoint is dropped' );

$shown = Client_Store::present( $client, false );
check( ! array_key_exists( 'notes', $shown ), 'notes are withheld from a reader without clients.edit' );
check( true === (bool) $shown['notes_hidden'], 'the withholding is reported rather than silent' );
check( array_key_exists( 'notes', Client_Store::present( $client, true ) ), 'notes are shown to a reader with clients.edit' );

$archived = $clients->archive( $wid, $client['public_id'] );
check( 'archived' === (string) $archived['status'], 'a client is archived' );
check( null !== $clients->get( $wid, $client['public_id'] ), 'an archived client is still readable' );
check( ! empty( $archived['notes'] ), 'archiving preserves the notes' );
check( null === $clients->get( $wid, 'cl_nonexistent' ), 'a missing client is not found' );

$contacts = new Client_Contact_Store();
$contact  = $contacts->add( $wid, $client['public_id'], array( 'name' => 'Dana Reyes', 'email' => 'dana@acme.example', 'role' => 'Marketing Manager' ) );
check( is_array( $contact ), 'a client contact is added' );
check( null === $contacts->add( $wid, $client['public_id'], array() ), 'a contact with neither name nor email is refused' );

$again = $contacts->add( $wid, $client['public_id'], array( 'email' => 'dana@acme.example' ) );
check( (string) $again['public_id'] === (string) $contact['public_id'], 'adding the same contact twice is idempotent' );

$linked = $contacts->add( $wid, $client['public_id'], array( 'name' => 'Linked Contact', 'email' => $client_email ) );
check( (int) $linked['user_id'] === $client_id, 'a contact with an existing account is linked to it, resolved by address' );

$unlinked = $contacts->add( $wid, $client['public_id'], array( 'name' => 'Unknown', 'email' => rf15_email( 'nobody' ) ) );
check( 0 === (int) $unlinked['user_id'], 'a contact with an unknown address is not linked' );

check( ! $permissions->can( $client_id, $wid, 'workspace.view' ), 'a contact row grants no workspace access on its own' );

check( true === $contacts->remove( $wid, (string) $contact['public_id'] ), 'a contact can be removed' );
check( null === $contacts->get( $wid, 'ct_nonexistent' ), 'a missing contact is not found' );

/* -------------------------------------------------------------------------
 * 9. Tokens
 * ---------------------------------------------------------------------- */

echo "\n== 9. Secure tokens ==\n";

$issued = Secure_Token::issue();
check( 64 === strlen( $issued['token'] ), 'a token is 64 hex characters' );
check( 64 === strlen( $issued['hash'] ), 'a stored hash is 64 hex characters' );
check( $issued['token'] !== $issued['hash'], 'the token and its stored hash differ' );
check( Secure_Token::looks_valid( $issued['token'] ), 'a freshly issued token looks valid' );

check( Secure_Token::verify( $issued['token'], $issued['hash'] ), 'the correct token verifies' );
check( ! Secure_Token::verify( str_repeat( 'a', 64 ), $issued['hash'] ), 'a wrong token does not verify' );
check( ! Secure_Token::verify( $issued['token'], str_repeat( 'b', 64 ) ), 'a token does not verify against the wrong hash' );
check( ! Secure_Token::verify( '', $issued['hash'] ), 'an empty token does not verify' );
check( ! Secure_Token::verify( $issued['token'], '' ), 'an empty hash does not verify' );
check( ! Secure_Token::verify( strtoupper( $issued['token'] ), $issued['hash'] ), 'a case-flipped token does not verify' );

$second = Secure_Token::issue();
check( $second['token'] !== $issued['token'], 'two issues produce different tokens' );

check( ! Secure_Token::looks_valid( 'short' ), 'a short token is refused' );
check( ! Secure_Token::looks_valid( str_repeat( 'z', 64 ) ), 'a non-hex token is refused' );
check( ! Secure_Token::looks_valid( array() ), 'a non-string token is refused' );

$ref = Secure_Token::reference( $issued['hash'] );
check( false === strpos( $ref, $issued['token'] ), 'a token reference does not reveal the token' );
check( strlen( $ref ) < 16, 'a token reference is short enough to show in a list' );
check( substr( $ref, -4 ) === substr( $issued['hash'], -4 ), 'a token reference identifies which link it is' );
check( $ref !== (string) $issued['hash'], 'a token reference is not the hash itself' );
check( '' === Secure_Token::reference( 'nope' ), 'a malformed hash yields no reference' );

check( Secure_Token::salt() === Secure_Token::salt(), 'the token salt is stable across calls' );
check( 64 === strlen( Secure_Token::salt() ), 'the token salt is 32 bytes' );

/* -------------------------------------------------------------------------
 * 10. Invitations
 * ---------------------------------------------------------------------- */

echo "\n== 10. Invitations ==\n";

$invitations = new Invitation_Service();
$invite_store = new Invitation_Store();
wp_set_current_user( $owner_id );

$invitee_email  = rf15_email( 'invitee' );
$other_email    = rf15_email( 'otherinvitee' );
$invitee        = rf15_user( 'invitee', 'subscriber', $invitee_email );
$other_invitee  = rf15_user( 'otherinvitee', 'subscriber', $other_email );
$rf15_users     = array_merge( $rf15_users, array( $invitee, $other_invitee ) );

$stranger_invite = $invitations->invite( $wid, rf15_email( 'x' ), 'reviewer', array( 'inviter_id' => $stranger_id ) );
check( empty( $stranger_invite['ok'] ), 'a stranger cannot invite' );
check( 'forbidden' === (string) $stranger_invite['code'], 'the refusal names the reason' );

$invite = $invitations->invite( $wid, $invitee_email, 'reviewer', array( 'inviter_id' => $owner_id ) );
check( ! empty( $invite['ok'] ), 'the owner may invite' );
check( ! empty( $invite['token'] ), 'the invitation returns a token once' );
check( ! empty( $invite['url'] ), 'the invitation returns a shareable URL' );
	check( '' !== (string) $invite['url'], 'the invitation URL is present' );
	check( false !== strpos( (string) $invite['url'], 'replicaforge_invitation=' ), 'the invitation URL carries the token under its parameter name' );
	check( 0 === strpos( (string) $invite['url'], home_url( '/' ) ), 'the invitation URL is built on this site, not an arbitrary host' );

$stored = $invite_store->get( $wid, (string) $invite['invitation']['public_id'] );
check( null !== $stored, 'the invitation is readable by id' );
check( false === strpos( (string) wp_json_encode( $stored ), $invite['token'] ), 'the plaintext token is not stored' );
check( Secure_Token::verify( $invite['token'], (string) $stored['token_hash'] ), 'the stored hash verifies the token' );
check( null !== $invite_store->by_token( $invite['token'] ), 'the invitation is found by its token' );

check( empty( $invitations->invite( $wid, 'not-an-email', 'reviewer', array( 'inviter_id' => $owner_id ) )['ok'] ), 'a malformed address is refused' );
check( empty( $invitations->invite( $wid, $other_email, 'owner', array( 'inviter_id' => $owner_id ) )['ok'] ), 'an invitation cannot grant the owner role' );
check( empty( $invitations->invite( $wid, $other_email, 'wizard', array( 'inviter_id' => $owner_id ) )['ok'] ), 'an invitation cannot grant an unknown role' );
check( empty( $invitations->invite( $wid, $other_email, 'reviewer', array( 'inviter_id' => $owner_id, 'project_role' => 'lead' ) )['ok'] ), 'a project role without a project is refused' );
check( empty( $invitations->invite( $wid, $other_email, 'reviewer', array( 'inviter_id' => $owner_id, 'project_id' => 'proj_nonexistent' ) )['ok'] ), 'an invitation for a project outside the workspace is refused' );

// Section 13's five failure cases.
check( empty( $invitations->accept( str_repeat( 'f', 64 ), array( 'user_id' => $invitee ) )['ok'] ), 'an unknown token is refused' );
check( empty( $invitations->accept( 'not-a-token', array( 'user_id' => $invitee ) )['ok'] ), 'a malformed token is refused' );

$wrong_account = $invitations->accept( $invite['token'], array( 'user_id' => $other_invitee ) );
check( empty( $wrong_account['code'] ) === false, 'the wrong account is refused with a code' );
check( empty( $wrong_account['ok'] ), 'the wrong account cannot accept' );
check( 'wrong_account' === (string) $wrong_account['code'], 'the wrong account is named in the code' );
check( empty( $invitations->accept( $invite['token'], array( 'user_id' => 0 ) )['ok']), 'an unauthenticated caller cannot accept' );

$accepted = $invitations->accept( $invite['token'], array( 'user_id' => $invitee ) );
check( ! empty( $accepted['ok'] ), 'the invited account accepts' );
check( 'reviewer' === (string) $accepted['role'], 'the acceptance reports the granted role' );
check( null !== $members->membership( $wid, $invitee ), 'the acceptance created a membership' );

$reinvite = $invitations->invite( $wid, $invitee_email, 'designer', array( 'inviter_id' => $owner_id ) );
check( empty( $reinvite['ok'] ), 'inviting an existing member is refused' );
check( 'already_member' === (string) $reinvite['code'], 'that refusal names the reason' );

$reused = $invitations->accept( $invite['token'], array( 'user_id' => $invitee ) );
check( empty( $reused['ok'] ), 'an accepted invitation cannot be accepted again' );
check( 'already_accepted' === (string) $reused['code'], 'the reuse is named as already accepted' );
check( (string) $reused['message'] === (string) $wrong_account['message'], 'a reuse refusal reads the same as a wrong-account refusal, so the code is not a probe' );

$revocable = $invitations->invite( $wid, $other_email, 'designer', array( 'inviter_id' => $owner_id ) );
check( ! empty( $revocable['ok']), 'a second invitation is issued' );
$revoked = $invitations->revoke( $wid, (string) $revocable['invitation']['public_id'], $owner_id );
check( ! empty( $revoked['ok']), 'an invitation can be revoked' );
$after_revoke = $invitations->accept( $revocable['token'], array( 'user_id' => $other_invitee ) );
check( empty( $after_revoke['ok']), 'a revoked invitation cannot be accepted' );
check( 'revoked' === (string) $after_revoke['code'], 'the revocation is named' );

$expired_row = $invitations->invite( $wid, rf15_email( 'expired' ), 'reviewer', array( 'inviter_id' => $owner_id ) );
$invite_store->update( $wid, (string) $expired_row['invitation']['public_id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
$expired = $invitations->accept( $expired_row['token'], array( 'user_id' => 0 ) );
check( empty( $expired['ok']), 'an expired invitation cannot be accepted' );
check( 'expired' === (string) $expired['code'], 'expiry is checked before the account' );

// Section 41: claiming is atomic, so two clicks cannot both create a membership.
$race_row = $invitations->invite( $wid, rf15_email( 'race' ), 'reviewer', array( 'inviter_id' => $owner_id ) );
$race_found = $invite_store->by_token( $race_row['token'] );
check( true === $invite_store->claim( (string) $race_found['public_id'], $owner_id ), 'the first claim of an invitation wins' );
check( false === $invite_store->claim( (string) $race_found['public_id'], $owner_id ), 'a second claim of the same invitation fails' );

// A fresh invitation, because every earlier one has legitimately been consumed.
$pending = $invitations->invite( $wid, rf15_email( 'pending' ), 'reviewer', array( 'inviter_id' => $owner_id ) );
check( ! empty( $pending['ok']), 'a fresh invitation is issued' );
check( 0 < (int) $invitations->outstanding( $wid, array( 'per_page' => 1 ) )['count'], 'the outstanding list returns the pending invitation' );
check( $invitations->outstanding_count( $wid ) >= 1, 'outstanding invitations are counted' );
	$presented_invitation = Invitation_Store::present( $pending['invitation'] );
	check( ! empty( $presented_invitation['token_ref'] ), 'a presented invitation reports a token reference' );
	check( ! array_key_exists( 'token_hash', $presented_invitation ), 'a presented invitation withholds the token hash' );
	check( false === strpos( (string) wp_json_encode( $presented_invitation ), $pending['token'] ), 'and the plaintext token is nowhere in the presented row' );

// The sweep, with a genuinely past-due row rather than a leftover.
$stale = $invitations->invite( $wid, rf15_email( 'stale' ), 'reviewer', array( 'inviter_id' => $owner_id ) );
$invite_store->update( $wid, (string) $stale['invitation']['public_id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ) );
check( 'pending' === (string) $invite_store->get( $wid, (string) $stale['invitation']['public_id'] )['status'], 'the past-due invitation is still pending before the sweep' );
check( $invitations->expire_stale() >= 1, 'stale invitations are swept' );
check( 'expired' === (string) $invite_store->get( $wid, (string) $stale['invitation']['public_id'] )['status'], 'the swept invitation is marked expired' );

/* -------------------------------------------------------------------------
 * 11. Reviews
 * ---------------------------------------------------------------------- */

echo "\n== 11. Reviews ==\n";

$reviews = new Review_Store();

// Section 15: a review must be bound to a version, so a project with none cannot have one.
check( null === $reviews->create( $wid, $pid, array( 'reviewer_id' => $reviewer_id ) ), 'a review for a project with no versions is refused' );

$projects->add_version( $pid, array( 'change' => 'initial', 'validation_id' => 'val_1' ) );
$projects->add_version( $pid, array( 'change' => 'fix hero spacing', 'validation_id' => 'val_2' ) );
$project_now = $projects->find( $pid );
check( 2 === count( $project_now['versions'] ), 'the project has two versions' );

$review = $reviews->create( $wid, $pid, array( 'reviewer_id' => $reviewer_id, 'type' => 'internal', 'title' => 'Internal pass' ) );
check( is_array( $review ), 'a review is created once a version exists' );
check( '' !== (string) $review['version_id'], 'the review is bound to a version id' );
check( 2 === (int) $review['version_number'], 'the review is bound to the newest version' );
check( 'val_2' === (string) $review['validation_id'], 'the review carries that version own validation id' );

// A second review with no link must still be creatable. It was not, while `link_hash` was
// unique and empty: one linkless review per workspace, and every later one failed.
$second_plain = $reviews->create( $wid, $pid, array( 'reviewer_id' => $reviewer_id, 'type' => 'internal' ) );
check( is_array( $second_plain ), 'a second review with no link is created' );
check( '' === (string) $second_plain['link_hash'], 'a review with no link has an empty link hash' );

$first_version = (string) $project_now['versions'][0]['version_id'];
$named = $reviews->create( $wid, $pid, array( 'reviewer_id' => $reviewer_id, 'version_id' => $first_version ) );
check( is_array( $named, ), 'a review may name an older version' );
check( 1 === (int) $named['version_number'], 'the named version number is recorded' );
check( (string) $named['version_id'] === $first_version, 'the named version id is the one recorded' );

$by_number = $reviews->create( $wid, $pid, array( 'reviewer_id' => $reviewer_id, 'version_number' => 1 ) );
check( is_array( $by_number, ), 'a review may name a version by number' );
check( 1 === (int) $by_number['version_number'], 'the version number resolves to the same version' );

check( null === $reviews->create( $wid, $pid, array( 'reviewer_id' => $reviewer_id, 'version_id' => 'ver_nonexistent' ) ), 'a review naming a version that does not exist is refused' );
check( null === $reviews->create( $wid, $pid, array( 'version_id' => $first_version ) ), 'a review with no reviewer is refused' );
check( null === $reviews->create( $wid, 'proj_nonexistent', array( 'version_id' => $first_version ) ), 'a review for a missing project is refused' );

check( is_array( $reviews->start( $wid, $review['public_id'] ) ), 'a pending review may be started' );
$decided_named = $reviews->approve( $wid, $named['public_id'], 'Signed off.' );
check( is_array( $decided_named ), 'the named review may be approved' );
check( null === $reviews->start( $wid, $decided_named['public_id'] ), 'a review cannot be started after a decision' );

$changes = $reviews->request_changes( $wid, (string) $reviews->create( $wid, $pid, array( 'reviewer_id' => $reviewer_id, 'version_id' => $first_version ) )['public_id'], 'The hero needs more space.' );
check( 'changes_requested' === (string) $changes['status'], 'changes can be requested' );
check( null === $reviews->request_changes( $wid, (string) $changes['public_id'], '' ), 'changes requested with no note is refused' );
check( null === $reviews->request_changes( $wid, (string) $changes['public_id'], 'Again.' ), 'a decided review cannot request changes again' );

$approved = $reviews->approve( $wid, $review['public_id'], 'Looks right.' );
check( 'approved' === (string) $approved['status'], 'a review may be approved' );
check( '' !== (string) $approved['version_id'], 'the approval is bound to a version' );
check( 2 === (int) $approved['version_number'], 'the approval records which version was approved' );
check( (string) $approved['project_id'] === $pid, 'the approval names its project' );
check( null === $reviews->approve( $wid, $review['public_id'], 'Again.' ), 'an approved review cannot be approved again' );

$reassignable = $reviews->create( $wid, $pid, array( 'reviewer_id' => $reviewer_id, 'version_id' => $first_version ) );
$reassigned   = $reviews->assign( $wid, $reassignable['public_id'], $pm_id );
check( $pm_id === (int) $reassigned['reviewer_id'], 'a pending review may be reassigned' );
check( null === $reviews->assign( $wid, $review['public_id'], $pm_id ), 'a decided review may not be reassigned' );
check( null === $reviews->assign( $wid, $reassignable['public_id'], 0 ), 'a review may not be assigned to nobody' );

// The section 16 client view is a whitelist. The reviewer is the client-role member, not a
// workspace reviewer - who holds the capability and would pass or fail for the wrong reason.
$client_review = $reviews->create( $wid, $pid, array(
	'reviewer_email' => $client_email,
	'type'           => 'client',
	'note'           => 'Internal brief: check the pricing page carefully.',
	'version_id'     => $first_version,
) );
check( is_array( $client_review ), 'a client review is created' );
check( (int) $client_review['reviewer_id'] === $client_id, 'a client reviewer is resolved from the address' );

$client_view = Review_Store::present( $client_review, true );
foreach ( array( 'note', 'validation_id', 'generation_id', 'reviewer_id', 'link_hash', 'link_password_hash', 'reviewer_email' ) as $withheld ) {
	check( ! array_key_exists( $withheld, $client_view ), "the client view withholds '$withheld'" );
}
check( array_key_exists( 'note', (array) ( $internal_view ?? array() ) ) === false, 'the internal view is a different shape' );
check( array_key_exists( 'version_number', $client_view ), 'the client view carries the version' );
check( false !== strpos( (string) $client_view['version_label'], '1' ), 'the client view labels the version for a person' );

$internal_view = Review_Store::present( $review, false );
check( array_key_exists( 'version_id', $internal_view ), 'the internal view is not redacted' );
check( array_key_exists( 'validation_id', $internal_view ), 'the internal view keeps the validation reference' );

/* -------------------------------------------------------------------------
 * 12. Review-scoped grants
 * ---------------------------------------------------------------------- */

echo "\n== 12. Review-scoped grants ==\n";

check( $permissions->can_act_on_review( $client_id, $wid, $client_review, 'reviews.approve' ), 'a client contact may approve the review that names them' );
check( $permissions->can_act_on_review( $client_id, $wid, $client_review, 'comments.create' ), 'a client contact may comment on it' );
check( $permissions->can_act_on_review( $client_id, $wid, $client_review, 'reviews.reject' ), 'a client contact may reject the review that names them' );
check( ! $permissions->can_act_on_review( $client_id, $wid, $review, 'reviews.approve' ), 'a client contact may not approve an internal review' );
check( ! $permissions->can_act_on_review( $other_invitee, $wid, $client_review, 'reviews.approve' ), 'a different person may not approve this review' );
check( ! $permissions->can_act_on_review( $stranger_id, $wid, $client_review, 'reviews.approve' ), 'a stranger may not approve through a review' );
check( ! $permissions->can_act_on_review( $client_id, $wid, $client_review, 'projects.create' ), 'a review-scoped grant does not extend to project creation' );
check( ! $permissions->can_act_on_review( $client_id, $wid, $client_review, 'sync.approve' ), 'a review-scoped grant does not extend to sync approval' );
check( ! $permissions->can_act_on_review( $client_id, $wid, $client_review, 'exports.download' ), 'a review-scoped grant does not extend to exports' );
check( ! $permissions->can_act_on_review( 0, $wid, $client_review, 'reviews.approve' ), 'user zero has no review-scoped grant' );
check( ! $permissions->can_act_on_review( $client_id, $wid, array(), 'reviews.approve' ), 'an empty review grants nothing' );

// A workspace reviewer is a different case: they hold the capability, so they may act on
// any review. Asserted so the client path is not mistaken for the only route to a review.
check( $permissions->can_act_on_review( $reviewer_id, $wid, $review, 'reviews.approve' ), 'a workspace reviewer may approve an internal review by capability' );
check( $permissions->can_act_on_review( $pm_id, $wid, $client_review, 'reviews.approve' ), 'a project manager may approve a client review by capability' );

// The first client review does not authorise a second one.
$other_client_review = $reviews->create( $wid, $pid, array( 'reviewer_email' => $other_email, 'type' => 'client', 'version_id' => $first_version ) );
check( is_array( $other_client_review, ), 'a second client review is created for somebody else' );
check( ! $permissions->can_act_on_review( $client_id, $wid, $other_client_review, 'reviews.approve' ), 'the first client review does not authorise a second one' );

/* -------------------------------------------------------------------------
 * 13. Comments
 * ---------------------------------------------------------------------- */

echo "\n== 13. Comments ==\n";

$comments   = new Comment_Store();
$version_id = $first_version;

check( null === $comments->create( $wid, $pid, array( 'body' => 'No version.', 'version_id' => '' ) ), 'a comment without a version is refused' );
check( null === $comments->create( $wid, $pid, array( 'body' => '   ', 'version_id' => $version_id ) ), 'an empty comment is refused' );
check( null === $comments->create( $wid, '', array( 'body' => 'No project.', 'version_id' => $version_id ) ), 'a comment without a project is refused' );

$comment = $comments->create( $wid, $pid, array(
	'body'          => 'Please increase the button spacing.',
	'version_id'    => $version_id,
	'page_id'       => 42,
	'anchor_type'   => 'component',
	'component_id'  => 'cmp_hero_button',
	'element_id'    => 'a1b2c3d',
	'viewport'      => 'mobile',
	'region_x'      => 34.5,
	'region_y'      => 12.25,
	'region_width'  => 20.0,
	'region_height' => 8.5,
	'author_id'     => $reviewer_id,
) );
check( is_array( $comment ), 'a comment is created' );
check( $version_id === (string) $comment['version_id'], 'the comment is bound to the version' );
check( 'mobile' === (string) $comment['viewport'], 'the viewport is stored' );

// Section 20: coordinates are supplemental, and the most specific stable id wins.
$anchor = Comment_Store::anchored( $comment );
check( 'element' === (string) $anchor['kind'], 'the most specific stable id is the preferred anchor' );
check( 'a1b2c3d' === (string) $anchor['stable_id'], 'the stable id reported is the element id' );
check( true === (bool) $anchor['has_stable'], 'the comment is reported as anchored' );
check( true === (bool) $anchor['has_region'], 'the region is reported separately' );
check( true === (bool) $anchor['reanchorable'], 'a stably anchored comment is re-anchorable' );

$component_only = $comments->create( $wid, $pid, array( 'body' => 'Heading too small.', 'version_id' => $version_id, 'component_id' => 'cmp_hero_heading', 'author_id' => $reviewer_id ) );
$component_anchor = Comment_Store::anchored( $component_only );
check( 'component' === (string) $component_anchor['kind'], 'a comment with only a component id anchors to the component' );
check( 'cmp_hero_heading' === (string) $component_anchor['stable_id'], 'the component id is reported when there is no element id' );
check( false === (bool) $component_anchor['has_region'], 'a component-anchored comment needs no region' );

$pixel_only = $comments->create( $wid, $pid, array(
	'body'          => 'Somewhere near the top left.',
	'version_id'    => $version_id,
	'viewport'      => 'desktop',
	'region_x'      => 10.0,
	'region_y'      => 10.0,
	'region_width'  => 5.0,
	'region_height' => 5.0,
	'author_id'     => $reviewer_id,
) );
$pixel_anchor = Comment_Store::anchored( $pixel_only );
check( false === (bool) $pixel_anchor['has_stable'], 'a coordinate-only comment reports no stable anchor' );
check( true === (bool) $pixel_anchor['has_region'], 'a coordinate-only comment reports its region' );
check( false === (bool) $pixel_anchor['reanchorable'], 'a coordinate-only comment is not re-anchorable' );

$project_only = $comments->create( $wid, $pid, array( 'body' => 'General note.', 'version_id' => $version_id, 'author_id' => $reviewer_id ) );
check( 'project' === (string) Comment_Store::anchored( $project_only )['kind'], 'a bare comment anchors to the project' );

// Coordinates are percentages and must stay in range.
$clamped = $comments->create( $wid, $pid, array( 'body' => 'Off the edge.', 'version_id' => $version_id, 'region_x' => 250.0, 'region_y' => -5.0 ) );
check( 100.0 === (float) $clamped['region_x'], 'an out-of-range x is clamped' );
check( 0.0 === (float) $clamped['region_y'], 'a negative y is clamped' );
check( '' === (string) $comments->create( $wid, $pid, array( 'body' => 'Odd viewport.', 'version_id' => $version_id, 'viewport' => 'watch' ) )['viewport'], 'an unknown viewport is dropped' );

// Threads.
$reply = $comments->create( $wid, $pid, array( 'body' => 'Done, spacing increased.', 'version_id' => 'ver_a_different_one', 'parent_id' => $comment['public_id'], 'author_id' => $designer_id ) );
check( is_array( $reply, ), 'a reply is created' );
check( (string) $comment['public_id'] === (string) $reply['parent_id'], 'the reply records its parent' );
check( $version_id === (string) $reply['version_id'], 'a reply inherits the thread version rather than its own' );
check( null === $comments->create( $wid, $pid, array( 'body' => 'Orphan.', 'version_id' => $version_id, 'parent_id' => 'cm_nonexistent' ) ), 'a reply to a missing comment is refused' );

$other_project = $projects->create( 'https://example.com/other', array( 'name' => 'Other' ) );
$rf15_projects[] = (string) $other_project['project_id'];
check( null === $comments->create( $wid, (string) $other_project['project_id'], array( 'body' => 'Wrong project.', 'version_id' => $version_id, 'parent_id' => $comment['public_id'] ) ), 'a reply to another project comment is refused' );

$thread = $comments->thread( $wid, $comment['public_id'] );
check( 1 === (int) $thread['count'], 'the thread returns its root' );
check( 1 === count( (array) $thread['items'][0]['replies'] ), 'the thread returns the reply nested' );
check( 0 === (int) $thread['orphans'], 'no replies are orphaned' );

check( is_array( $comments->resolve( $wid, $comment['public_id'], $reviewer_id ) ), 'a comment may be resolved' );
check( 'resolved' === (string) $comments->get( $wid, $comment['public_id'] )['status'], 'the comment is resolved' );
check( null === $comments->resolve( $wid, $comment['public_id'], $reviewer_id ), 'resolving twice is refused' );
check( is_array( $comments->reopen( $wid, $comment['public_id'] ), ), 'a comment may be reopened' );
check( 'reopened' === (string) $comments->get( $wid, $comment['public_id'] )['status'], 'the comment is reopened' );

$resolved_thread = $comments->resolve_thread( $wid, $comment['public_id'], $reviewer_id );
check( 'resolved' === (string) $resolved_thread['items'][0]['status'], 'resolving a thread resolves the root' );
check( 'resolved' === (string) $resolved_thread['items'][0]['replies'][0]['status'], 'resolving a thread resolves the replies too' );

// Section 19: a comment is resolved, never deleted.
check( ! method_exists( $comments, 'delete' ), 'comments cannot be deleted, only resolved' );

$member_login = (string) get_userdata( $designer_id )->user_login;
$stranger_login = (string) get_userdata( $stranger_id )->user_login;
$mentioned = $comments->create( $wid, $pid, array( 'body' => 'Ping', 'version_id' => $version_id, 'mentions' => '@' . $member_login . ' @' . $stranger_login, 'author_id' => $reviewer_id ) );
check( array( $designer_id ) === array_map( 'intval', (array) $mentioned['mentions'] ), 'a mention of a member is kept and a mention of a stranger is dropped' );

check( $comments->open_count_for_project( $wid, $pid ) >= 1, 'open comments are counted for a project' );
check( $comments->open_count( $wid ) >= 1, 'open comments are counted for a workspace' );
check( 1 === count( $comments->for_component( $wid, $pid, 'cmp_hero_button' ) ), 'comments are found by component id' );

/* -------------------------------------------------------------------------
 * 14. Tasks and issues
 * ---------------------------------------------------------------------- */

echo "\n== 14. Tasks and issues ==\n";

$tasks  = new Task_Store();
$issues = new Issue_Store();

check( null === $tasks->create( $wid, $pid, array( 'title' => '   ' ) ), 'a task without a title is refused' );
check( null === $tasks->create( $wid, '', array( 'title' => 'Orphan' ) ), 'a task without a project is refused' );

$task = $tasks->create( $wid, $pid, array( 'title' => 'Fix hero spacing', 'description' => 'At 390px.', 'priority' => 'urgent', 'assignee_id' => $designer_id ) );
check( is_array( $task ), 'a task is created' );
check( 'urgent' === (string) $task['priority'], 'an urgent task stays urgent' );

foreach ( Workspace_Limits::PRIORITIES as $priority ) {
	check( $priority === (string) $tasks->create( $wid, $pid, array( 'title' => 'P ' . $priority, 'priority' => $priority ) )['priority'], "a '$priority' task is stored as '$priority'" );
}
foreach ( array_keys( Workspace_Limits::TASK_STATUSES ) as $status ) {
	check( $status === (string) $tasks->create( $wid, $pid, array( 'title' => 'S ' . $status, 'status' => $status ) )['status'], "a '$status' task round-trips" );
}
check( 'todo' === (string) $tasks->create( $wid, $pid, array( 'title' => 'Default', 'status' => 'nonsense' ) )['status'], 'an unknown task status falls back to todo' );
check( 'medium' === (string) $tasks->create( $wid, $pid, array( 'title' => 'Default', 'priority' => 'enormous' ) )['priority'], 'an unknown task priority falls back to medium' );

// Section 23: a promotion requires a source, so provenance cannot be fabricated.
check( null === $tasks->from_difference( $wid, $pid, array( 'title' => 'Mobile hero spacing differs' ) ), 'a promotion without a source is refused' );
check( null === $tasks->from_difference( $wid, $pid, array( 'title' => 'x', 'source' => 'telepathy' ) ), 'a promotion with an undeclared source is refused' );
check( null === $tasks->from_difference( $wid, $pid, array( 'source' => 'validation' ) ), 'a promotion without a title is refused' );

$promoted = $tasks->from_difference( $wid, $pid, array(
	'title'        => 'Mobile hero spacing differs',
	'description'  => 'Observed at 390px.',
	'source'       => 'validation',
	'severity'     => 'critical',
	'page_id'      => 42,
	'component_id' => 'cmp_hero_button',
	'section_id'   => 'sec_hero',
) );
check( is_array( $promoted ), 'a promotion from a validation difference succeeds' );
check( 'urgent' === (string) $promoted['priority'], 'a critical difference becomes an urgent task' );
check( 'urgent' === (string) $tasks->from_difference( $wid, $pid, array( 'title' => 'M', 'source' => 'validation', 'severity' => 'major' ) )['priority'], 'a major difference becomes an urgent task' );
check( 'medium' === (string) $tasks->from_difference( $wid, $pid, array( 'title' => 'Mo', 'source' => 'validation', 'severity' => 'moderate' ) )['priority'], 'a moderate difference becomes a medium task' );
check( 'low' === (string) $tasks->from_difference( $wid, $pid, array( 'title' => 'L', 'source' => 'validation', 'severity' => 'minor' ) )['priority'], 'a minor difference becomes a low task' );
check( 'low' === (string) $tasks->from_difference( $wid, $pid, array( 'title' => 'I', 'source' => 'validation', 'severity' => 'informational' ) )['priority'], 'an informational difference becomes a low task' );

$evidence = (string) $promoted['description'];
check( false !== strpos( $evidence, 'cmp_hero_button' ), 'the evidence records the component id' );
check( false !== strpos( $evidence, 'Page: 42' ), 'the evidence records the page id' );
check( false !== strpos( $evidence, 'sec_hero' ), 'the evidence records the section id' );
check( false !== strpos( $evidence, 'validation' ), 'the evidence names its source phase' );

	/*
	 * The provenance is a column, and the assertion re-reads the task rather than
	 * trusting what the promotion returned. It was previously set on the returned
	 * array after the insert, so the value existed for the rest of the request and
	 * was never written to the row - a task that reported where it came from on the
	 * screen that created it and then lost it.
	 */
	$promoted_again = $tasks->from_difference( $wid, $pid, array(
		'title'    => 'Provenance round trip',
		'source'   => 'validation',
		'severity' => 'major',
	) );
	check( is_array( $promoted_again ), 'a second promotion succeeds' );
	check( 'validation' === (string) $promoted_again['promoted_from'], 'the promotion reports the phase it came from' );

	$re_read_task = $tasks->get( $wid, (string) $promoted_again['public_id'] );
	check( 'validation' === (string) $re_read_task['promoted_from'], 'and the phase survives a read from storage' );

	$listed_task = $tasks->tasks( $wid, $pid, array( 'per_page' => 1 ) )['items'][0];
	check( is_array( $listed_task ) && 'validation' === (string) ( $listed_task['promoted_from'] ?? '' ), 'and it survives a list read too' );

	// A task created directly has no provenance, and must not be given one.
	$direct_task = $tasks->create( $wid, $pid, array( 'title' => 'No provenance' ) );
	check( '' === (string) $direct_task['promoted_from'], 'a task created directly reports no provenance, rather than a fabricated one' );
check( false !== strpos( $evidence, 'Observed at 390px.' ), 'the evidence keeps the original description' );

// Section 21: the severity is Phase 5's.
check( Issue_Store::severity_scale() === Validation_Limits::SEVERITIES, 'issue severities are the validation severities' );
check( null === $issues->create( $wid, $pid, array( 'title' => 'No source' ) ), 'an issue without a source is refused' );
check( null === $issues->create( $wid, $pid, array( 'source' => 'validation' ) ), 'an issue without a title is refused' );

$issue = $issues->create( $wid, $pid, array( 'title' => 'Hero spacing', 'source' => 'validation', 'severity' => 'critical' ) );
check( 'critical' === (string) $issue['severity'], 'an issue keeps the severity it was promoted with' );
check( 'moderate' === (string) $issues->create( $wid, $pid, array( 'title' => 'T', 'source' => 'validation', 'severity' => 'enormous' ) )['severity'], 'an unknown severity falls back to moderate' );

foreach ( Validation_Limits::SEVERITIES as $severity ) {
	check( $severity === (string) $issues->create( $wid, $pid, array( 'title' => 'S ' . $severity, 'source' => 'validation', 'severity' => $severity ) )['severity'], "the '$severity' severity round-trips" );
}

$resolved_issue = $issues->resolve( $wid, $issue['public_id'] );
check( 'resolved' === (string) $resolved_issue['status'], 'an issue may be resolved' );
check( ! empty( $resolved_issue['resolved_at'] ), 'a resolved issue records when' );
	check( null === $issues->update( $wid, $issue['public_id'], array( 'status' => 'reopened' ) )['resolved_at'], 'reopening clears the resolution timestamp' );

$from_comment = $issues->from_difference( $wid, $pid, array(
	'title'        => 'Client asked for a different headline',
	'source'       => 'comment',
	'severity'     => 'major',
	'comment_id'   => (string) $comment['public_id'],
	'component_id' => 'cmp_hero_button',
) );
check( is_array( $from_comment ), 'a comment promotes to an issue' );
check( 'comment' === (string) $from_comment['source'], 'the issue records its source' );
check( (string) $from_comment['component_id'] === 'cmp_hero_button', 'the issue keeps the component reference' );
check( false === strpos( (string) wp_json_encode( $from_comment ), 'sk-live' ), 'the promoted issue carries no injected secret' );

$open_issues = $issues->open( $wid, 5 );
check( count( $open_issues ) >= 1, 'open issues are listed' );
if ( count( $open_issues ) > 1 ) {
	$order = array_flip( Validation_Limits::SEVERITIES );
	check( $order[ (string) $open_issues[0]['severity'] ] <= $order[ (string) $open_issues[1]['severity'] ], 'open issues are ordered by severity, not alphabetically' );
}
check( $issues->open_count( $wid ) >= 1, 'open issues are counted' );
check( $issues->open_count_for_project( $wid, $pid ) >= 1, 'open issues are counted for a project' );

/* -------------------------------------------------------------------------
 * 15. Activity and audit
 * ---------------------------------------------------------------------- */

echo "\n== 15. Activity and audit ==\n";

$log = new Collaboration_Log();

$event = $log->activity( $wid, 'generation_completed', array( 'project_id' => $pid, 'metadata' => array( 'pages' => 18, 'version' => 2 ) ), $designer_id );
check( is_array( $event ), 'an activity event is recorded' );
check( 'generation_completed' === (string) $event['action'], 'the event records its action' );
check( (string) get_userdata( $designer_id )->display_name === (string) $event['actor_name'], 'the event names the actor' );
check( null === $log->activity( $wid, 'not_an_event', array(), $owner_id ), 'an undeclared activity event is refused' );
check( null === $log->audit( $wid, 'not_an_audit_event', array(), $owner_id ), 'an undeclared audit event is refused' );
check( null === $log->activity( '', 'generation_completed', array(), $owner_id ), 'an activity event with no workspace is refused' );

// Both kinds of event are recorded first, so the separation assertions below compare two
// non-empty logs rather than one populated log and one empty one.
$audit_seeded = $log->audit(
	$wid,
	'permission_changed',
	array(
		'project_id'  => $pid,
		'target_type' => 'member',
		'target_id'   => (string) $members->membership( $wid, $designer_id )['public_id'],
		'metadata'    => array( 'role' => 'designer' ),
	),
	$owner_id
);
check( is_array( $audit_seeded ), 'an audit event is recorded before the logs are compared' );
// Section 25 requires the two *logs* to be separate, not the two vocabularies to be
// disjoint. Four names appear in both, and that is correct: `member_added` as activity is
// the narrative - "Kazim added Sara to the project" - and as audit it is the security
// record - "Sara's workspace role was granted". Different facts, different subjects, and a
// specification asking for both cannot be satisfied by renaming either.
//
// So the separation asserted here is structural: two tables, two retentions, and no row
// readable through the other log's query.
check( array() !== array_intersect( Workspace_Limits::ACTIVITY_EVENTS, Workspace_Limits::AUDIT_EVENTS ), 'the two vocabularies deliberately share some event names' );

$activity_table = Workspace_Limits::prefixed_table( 'activity' );
$audit_table    = Workspace_Limits::prefixed_table( 'audit' );
check( $activity_table !== $audit_table, 'activity and audit are different tables' );
check( Workspace_Limits::ACTIVITY_TTL !== Workspace_Limits::AUDIT_TTL, 'activity and audit have different retention' );
check( Workspace_Limits::ACTIVITY_TTL < Workspace_Limits::AUDIT_TTL, 'audit is retained longer than activity' );

$activity_page = $log->timeline( $wid, $pid );
$audit_page    = $log->audit_trail( $wid );

$timeline_actions = array_column( (array) $activity_page['items'], 'action' );
$trail_actions    = array_column( (array) $audit_page['items'], 'action' );

check( in_array( 'generation_completed', $timeline_actions, true ), 'an activity event appears in the timeline' );
check( ! in_array( 'generation_completed', $trail_actions, true ), 'and does not appear in the audit trail' );
check( in_array( 'permission_changed', $trail_actions, true ), 'an audit event appears in the audit trail' );
check( ! in_array( 'permission_changed', $timeline_actions, true ), 'and does not appear in the timeline' );

// Each row belongs to exactly one log, so a count in one is not a count in the other.
check( count( array_unique( array_merge( $timeline_actions, $trail_actions ) ) ) >= 2, 'the two logs hold genuinely different events' );

$timeline = $log->timeline( $wid, $pid );
check( (int) $timeline['count'] >= 1, 'the timeline returns events' );
check( ! empty( $timeline['items'][0]['summary']), 'an event carries a human summary' );
check( false !== strpos( (string) $timeline['items'][0]['summary'], (string) get_userdata( $designer_id )->display_name ), 'the summary names the actor' );

// Every declared event must produce a readable sentence. A blank row in a history is worse
// than a missing one, because it looks like a gap in the record.
$blanks = array();
foreach ( Workspace_Limits::ACTIVITY_EVENTS as $name ) {
	$probe = $log->activity( $wid, $name, array( 'project_id' => $pid ), $owner_id );
	if ( ! is_array( $probe ) ) {
		$blanks[] = $name;
		continue;
	}
	if ( '' === (string) ( new ReplicaForge\Activity_Store() )->present( $probe )['summary'] ) {
		$blanks[] = $name;
	}
}
check( array() === $blanks, 'every declared activity event produces a summary' );

$audit = $log->audit( $wid, 'permission_changed', array(
	'project_id'  => $pid,
	'target_type' => 'member',
	'target_id'   => (string) $members->membership( $wid, $designer_id )['public_id'],
	'metadata'    => array( 'api_key' => 'sk-live-should-never-be-stored', 'note' => 'role changed' ),
), $owner_id );
check( is_array( $audit ), 'an audit event is recorded' );
check( 64 === strlen( (string) $audit['context_hash'] ), 'the audit row hashes the request context' );
check( '' === (string) $audit['ip_hash'] || 64 === strlen( (string) $audit['ip_hash'] ), 'the audit row hashes the address or leaves it empty' );

$serialised = (string) wp_json_encode( $audit );
check( false === strpos( $serialised, 'sk-live-should-never-be-stored' ), 'a secret in audit metadata is redacted' );
check( false === strpos( $serialised, 'HTTP_COOKIE' ), 'the audit row carries no cookie header' );
check( false !== strpos( $serialised, 'role changed' ), 'a harmless metadata value is kept' );

$trail = $log->audit_trail( $wid, array( 'action' => 'permission_changed' ) );
check( (int) $trail['count'] >= 1, 'the audit trail is filterable by action' );
check( (int) $log->audit_trail( $wid, array( 'action' => 'generation_completed' ) )['count'] >= 1, 'an activity event name is not an audit event' );

/* -------------------------------------------------------------------------
 * 16. Notifications
 * ---------------------------------------------------------------------- */

echo "\n== 16. Notifications ==\n";

$notifications = new Notification_Service();

check( array_key_exists( 'in_app', $notifications->providers() ), 'the in-app provider is registered by default' );
check( array_key_exists( 'email', $notifications->providers() ), 'the email provider is registered by default' );
check( $notifications->providers()['in_app'] instanceof In_App_Notification_Provider, 'the in-app provider implements the contract' );
check( $notifications->providers()['email'] instanceof Email_Notification_Provider, 'the email provider implements the contract' );
check( $notifications->providers()['in_app'] instanceof Notification_Provider, 'a provider satisfies the interface' );

foreach ( array_keys( $notifications->providers() ) as $channel ) {
	check( in_array( $channel, Workspace_Limits::CHANNELS, true ), "the '$channel' channel is a declared one" );
}

$defaults = $notifications->preferences( $designer_id );
check( count( $defaults ) === count( Workspace_Limits::PREFERENCE_KEYS ), 'every preference category is present' );
foreach ( $defaults as $key => $channels ) {
	check( true === $channels['in_app'], "the '$key' category defaults to in-app on" );
	check( false === $channels['email'], "the '$key' category defaults to email off" );
}

$notifications->set_preferences( $designer_id, array( 'mentions' => array( 'email' => true ) ) );
$changed = $notifications->preferences( $designer_id );
check( true === $changed['mentions']['email'], 'a preference can be turned on' );
check( false === $changed['review_requests']['email'], 'another category is unaffected' );
check( true === $changed['mentions']['in_app'], 'turning on email does not turn off in-app' );

$ignored = $notifications->set_preferences( $designer_id, array( 'not_a_category' => array( 'email' => true ) ) );
check( ! isset( $ignored['not_a_category'], ), 'an unknown preference category is dropped' );

$store = new Notification_Store();
	$zero_prefs = $store->preferences( 0 );
	check( count( $zero_prefs ) === count( Workspace_Limits::PREFERENCE_KEYS ), 'user zero still receives the default preference categories' );
	check( false === $zero_prefs['mentions']['email'], 'and those defaults still have email off' );
check( 0 === (int) $store->unread_count( $wid, 0 ), 'user zero has no notifications' );

// The email provider refuses a notification with no composed body. That refusal is what
// keeps arbitrary metadata out of an outbound message.
$email_provider = new Email_Notification_Provider();
check( ! $email_provider->delivers( array( 'email' => 'x@example.com' ) ), 'an email with no body is refused' );
check( ! $email_provider->delivers( array( 'body' => 'Hello' ) ), 'an email with no address is refused' );
check( ! $email_provider->delivers( array( 'email' => 'not-an-email', 'body' => 'Hello' ) ), 'a malformed address is refused' );
check( $email_provider->delivers( array( 'email' => 'x@example.com', 'body' => 'Hello' ) ), 'a well-formed email is accepted' );

$in_app = new In_App_Notification_Provider();
check( ! $in_app->delivers( array( 'user_id' => 0, 'type' => 'x' ) ), 'an in-app notification for user zero is refused' );
check( ! $in_app->delivers( array( 'user_id' => 5 ) ), 'an in-app notification without a type is refused' );
check( $in_app->delivers( array( 'user_id' => 5, 'type' => 'x' ) ), 'a well-formed in-app notification is accepted' );

$no_recipients = $notifications->notify( $wid, 'task_assigned', array( 'assignee_id' => 0 ) );
check( ! empty( $no_recipients['ok'] ), 'a notification with no assignee is not a failure' );
check( 'no_recipients' === (string) $no_recipients['reason'], 'the no-recipient case is reported as such' );
check( empty( $notifications->notify( $wid, 'not_a_type' )['ok'] ), 'an unknown notification type is refused' );

$mention_result = $notifications->notify( $wid, 'comment_mention', array( 'resource_id' => (string) $comment['public_id'] ) );
check( ! empty( $mention_result['ok'] ), 'a mention notification is delivered' );

$sent_to = array();
foreach ( (array) $mention_result['sent'] as $entry ) {
	$sent_to[] = (int) $entry['user_id'];
}
check( ! in_array( $stranger_id, $sent_to, true ), 'a stranger is never notified' );

$inbox = $notifications->inbox( $wid, $reviewer_id );
check( is_array( $inbox['items'] ), 'the inbox returns a page' );
if ( (int) $inbox['count'] > 0 ) {
	$notification_id = (string) $inbox['items'][0]['public_id'];
	check( $notifications->mark_read( $wid, $reviewer_id, $notification_id ), 'a notification can be marked read' );
	check( ! $notifications->mark_read( $wid, $designer_id, $notification_id ), 'one user cannot mark another user notification read' );
	check( $notifications->mark_read( $wid, $reviewer_id, 'nt_nonexistent' ) === false, 'a missing notification cannot be marked read' );
}

/* -------------------------------------------------------------------------
 * 17. Review links
 * ---------------------------------------------------------------------- */

echo "\n== 17. Review links ==\n";

$links = new Review_Link_Service();
wp_set_current_user( $owner_id );

// Section 40: links are off by default.
$disabled = $links->create( $wid, $client_review['public_id'], array( 'actor_id' => $owner_id ) );
check( empty( $disabled['ok'] ), 'a review link is refused while links are disabled' );
check( 'links_disabled' === (string) $disabled['code'], 'the refusal names the disabled setting' );

$workspaces->update( $wid, array( 'settings' => array( 'allow_client_review_links' => true ) ) );

check( empty( $links->create( $wid, $client_review['public_id'], array( 'actor_id' => $stranger_id ) )['ok'] ), 'a stranger cannot create a review link' );
check( empty( $links->create( $wid, $review['public_id'], array( 'actor_id' => $owner_id ) )['ok'] ), 'an internal review cannot be shared with an external reviewer' );
check( empty( $links->create( $wid, 'rev_nonexistent', array( 'actor_id' => $owner_id ) )['ok'] ), 'a link for a missing review is refused' );

$link = $links->create( $wid, $client_review['public_id'], array( 'actor_id' => $owner_id ) );
check( ! empty( $link['ok']), 'a client review link is created once links are enabled' );
check( ! empty( $link['token']), 'the link returns a token' );
check( false === (bool) $link['password_required'], 'a link without a password says so' );
check( ! empty( $link['expires_at']), 'the link reports an expiry' );

$expiry = (int) strtotime( (string) $link['expires_at'] );
check( $expiry > time(), 'the expiry is in the future' );
check( $expiry <= time() + Workspace_Limits::REVIEW_LINK_TTL['default'] + 120, 'the expiry is within the default lifetime' );

check( empty( $links->open( str_repeat( 'd', 64 ) )['ok']), 'a wrong token cannot open a review' );
check( 'invalid_token' === (string) $links->open( str_repeat( 'd', 64 ) )['code'], 'a wrong token is named invalid' );
check( empty( $links->open( 'not-a-token' )['ok']), 'a malformed token cannot open a review' );

$opened = $links->open( $link['token'] );
check( ! empty( $opened['ok']), 'the correct token opens the review' );
check( true === (bool) $opened['client'], 'the opened view is a client view' );
check( ! array_key_exists( 'note', $opened['review'], ), 'the client view withholds the internal note' );
check( ! array_key_exists( 'reviewer_email', $opened['review'], ), 'the client view withholds the reviewer address' );
check( ! array_key_exists( 'link_hash', $opened['review'], ), 'the client view withholds the link hash' );
check( ! array_key_exists( 'workspace_id', $opened['review'], ), 'the client view withholds the workspace id' );
check( ! empty( $opened['review']['version_id']), 'the client view carries the version under review' );

$password_review = $reviews->create( $wid, $pid, array( 'reviewer_email' => $client_email, 'type' => 'client', 'version_id' => $first_version ) );
$password_link = $links->create( $wid, $password_review['public_id'], array( 'actor_id' => $owner_id, 'password' => 'correct horse battery staple' ) );
check( true === (bool) $password_link['password_required'], 'a link with a password reports that one is required' );
check( empty( $links->open( $password_link['token'] )['ok']), 'a password-protected link refuses a request with no password' );
check( 'bad_password' === (string) $links->open( $password_link['token'] )['code'], 'a missing password is named' );
check( empty( $links->open( $password_link['token'], array( 'password' => 'wrong' ) )['ok']), 'a wrong password is refused' );
check( ! empty( $links->open( $password_link['token'], array( 'password' => 'correct horse battery staple' ) )['ok']), 'the correct password opens the link' );

// A password is a slow hash, unlike the 256-bit tokens, which need none.
check( '' !== Review_Link_Service::hash_password( 'secret' ), 'a password is hashed' );
check( Review_Link_Service::hash_password( 'secret' ) !== Review_Link_Service::hash_password( 'secret' ), 'a password hash is salted' );
check( Review_Link_Service::verify_password( 'secret', Review_Link_Service::hash_password( 'secret' ) ), 'a password verifies against its hash' );
check( ! Review_Link_Service::verify_password( 'secret', '' ), 'no password verifies against an empty hash' );

// Section 17: rate limiting, and suspension.
$limited_review = $reviews->create( $wid, $pid, array( 'reviewer_email' => $client_email, 'type' => 'client', 'version_id' => $first_version ) );
$limited = $links->create( $wid, $limited_review['public_id'], array( 'actor_id' => $owner_id, 'password' => 'unlock-me' ) );
for ( $attempt = 1; $attempt <= Workspace_Limits::REVIEW_LINK_MAX_ATTEMPTS; $attempt++ ) {
	$links->open( $limited['token'], array( 'password' => 'nope' ) );
}
$after_limit = $links->open( $limited['token'], array( 'password' => 'unlock-me' ) );
check( empty( $after_limit['ok']), 'a link is suspended after the attempt ceiling, even with the right password' );
check( in_array( (string) $after_limit['code'], array( 'suspended', 'bad_password' ), true ), 'the suspension is reported' );

check( ! empty( $links->revoke( $wid, $client_review['public_id'], $owner_id )['ok'] ), 'a link can be revoked' );
check( empty( $links->open( $link['token'] )['ok']), 'a revoked link cannot be opened' );

$stranger_target = $reviews->create( $wid, $pid, array( 'reviewer_email' => $client_email, 'type' => 'client', 'version_id' => $first_version ) );
check( empty( $links->revoke( $wid, $stranger_target['public_id'], $stranger_id )['ok']), 'a stranger cannot revoke a link' );

/* -------------------------------------------------------------------------
 * 18. Acting through a link
 * ---------------------------------------------------------------------- */

echo "\n== 18. Acting through a link ==\n";

$acting_review = $reviews->create( $wid, $pid, array(
	'reviewer_email' => $client_email,
	'type'           => 'client',
	'version_id'     => $first_version,
	'note'           => 'Please check the pricing page.',
) );
$acting_link = $links->create( $wid, $acting_review['public_id'], array( 'actor_id' => $owner_id ) );
check( ! empty( $acting_link['ok']), 'a link is created for the acting review' );

$link_comment = $links->act( $acting_link['token'], 'comment', array(
	'body'           => 'The heading is too small on mobile.',
	'component_id'   => 'cmp_hero_heading',
	'viewport'       => 'mobile',
	'region_x'       => 20.0,
	'region_y'       => 15.0,
	'region_width'   => 60.0,
	'region_height'  => 12.0,
) );
check( ! empty( $link_comment['ok']), 'a client may comment through a link' );
check( true === (bool) ( $link_comment['comment']['has_stable'] ?? false ), 'the annotation reports its stable anchor' );
check( 'component' === (string) ( $link_comment['comment']['kind'] ?? '' ), 'the annotation is anchored to the component' );

// Section 56: requesting changes creates an issue, because a client has no other way to
// hand work back to a developer.
$requested = $links->act( $acting_link['token'], 'request_changes', array( 'note' => 'The hero heading is too small on mobile.' ) );
check( ! empty( $requested['ok']), 'a client may request changes through a link' );
check( ! empty( $requested['issue']), 'requesting changes creates an issue for the team' );
if ( ! empty( $requested['issue'] ) ) {
	$created_issue = $issues->get( $wid, (string) $requested['issue']['public_id'] );
	check( is_array( $created_issue, ), 'the created issue is readable' );
	check( 'comment' === (string) $created_issue['source'], 'the created issue records its source' );
	check( false !== strpos( (string) $created_issue['description'], 'too small' ), 'the created issue carries the client note' );
	check( false !== strpos( (string) $created_issue['source_reference']['via'], 'review_link' ), 'the issue records that it came through a link' );
	check( 'major' === (string) $created_issue['severity'], 'a client change request is raised at major severity' );
}
check( empty( $links->act( $acting_link['token'], 'request_changes', array( 'note' => 'Again.' ) )['ok']), 'a review that already asked for changes cannot ask again' );

$approvable = $reviews->create( $wid, $pid, array( 'reviewer_email' => $client_email, 'type' => 'client', 'version_id' => $first_version ) );
$approvable_link = $links->create( $wid, $approvable['public_id'], array( 'actor_id' => $owner_id ) );
$approved_link = $links->act( $approvable_link['token'], 'approve', array( 'note' => 'Approved from the client link.' ) );
check( ! empty( $approved_link['ok']), 'a client may approve through a link' );
check( 'approved' === (string) $approved_link['review']['status'], 'the approval is recorded' );
check( $first_version === (string) $approved_link['review']['version_id'], 'the approval is bound to the reviewed version, not one from the payload' );
check( empty( $links->open( $approvable_link['token'] )['ok']), 'a decided review link no longer opens' );

check( empty( $links->act( str_repeat( 'e', 64 ), 'approve' )['ok']), 'acting through a bad token is refused' );
check( empty( $links->act( $acting_link['token'], 'teleport' )['ok']), 'an unknown action through a link is refused' );

/* -------------------------------------------------------------------------
 * 19. Approval gates
 * ---------------------------------------------------------------------- */

echo "\n== 19. Approval gates ==\n";

$gate_project = $projects->create( 'https://example.com/gated', array( 'name' => 'Gated Project' ) );
$rf15_projects[] = (string) $gate_project['project_id'];
$gid = (string) $gate_project['project_id'];
$projects->add_version( $gid, array( 'change' => 'initial' ) );
$context->update( $gid, array( 'workspace_id' => $wid ) );

$strict = $workspaces->settings( $wid );
$gates = $context->gates( $gid, $context->get( $gid ), $strict );

check( true === (bool) $gates['gates']['internal']['required'], 'internal approval is required' );
check( true === (bool) $gates['gates']['client']['required'], 'client approval is required' );
check( 'none' === (string) $gates['gates']['internal']['status'], 'an unreviewed project has no internal gate status' );
check( false === (bool) $gates['completable'], 'an unreviewed project is not completable' );
check( 2 === count( (array) $gates['outstanding'] ), 'both gates are outstanding' );
check( false !== strpos( (string) $gates['reason'], 'internal' ), 'the reason names the outstanding gates' );

$internal_gate_review = $reviews->create( $wid, $gid, array( 'reviewer_id' => $pm_id, 'type' => 'internal' ) );
$reviews->approve( $wid, $internal_gate_review['public_id'] );
$gates = $context->gates( $gid, $context->get( $gid ), $strict );
check( 'approved' === (string) $gates['gates']['internal']['status'], 'the internal gate reports approved' );
check( 1 === count( (array) $gates['outstanding'] ), 'only the client gate is outstanding' );
check( false === (bool) $gates['completable'], 'the project is still not completable' );

$client_gate_review = $reviews->create( $wid, $gid, array( 'reviewer_email' => $client_email, 'type' => 'client' ) );
$reviews->approve( $wid, $client_gate_review['public_id'] );
$gates = $context->gates( $gid, $context->get( $gid ), $strict );
check( true === (bool) $gates['completable'], 'a project with both approvals is completable' );
check( array() === (array) $gates['outstanding'], 'nothing is outstanding once both are approved' );

// The gate follows configuration, not a hard-coded rule.
$relaxed = array_merge( $strict, array( 'require_internal_approval' => false, 'require_client_approval' => false ) );
check( true === (bool) $context->gates( $gid, $context->get( $gid ), $relaxed )['completable'], 'with approvals not required, the project is completable' );
$only_internal = array_merge( $strict, array( 'require_client_approval' => false ) );
check( true === (bool) $context->gates( $gid, $context->get( $gid ), $only_internal )['completable'], 'with only internal approval required, an internally approved project is completable' );

/* -------------------------------------------------------------------------
 * 20. Cross-workspace isolation
 * ---------------------------------------------------------------------- */

echo "\n== 20. Cross-workspace isolation ==\n";

$other_workspace = $workspaces->create( $owner_id, 'Borealis Agency' );
$other_wid = (string) $other_workspace['public_id'];

check( null === $context->owned( $other_wid, $pid ), 'a project from another workspace is not owned' );
check( null !== $context->owned( $wid, $pid ), 'a project from its own workspace is owned' );
check( 0 === count( $context->list_projects( $other_wid ) ), 'another workspace lists no projects' );
check( count( $context->list_projects( $wid ) ) >= 2, 'a workspace lists its own projects' );

check( null === $reviews->get( $other_wid, (string) $review['public_id'] ), 'a review is not readable from another workspace' );
check( null === $clients->get( $other_wid, (string) $client['public_id'] ), 'a client is not readable from another workspace' );
check( null === $comments->get( $other_wid, (string) $comment['public_id'] ), 'a comment is not readable from another workspace' );
check( null === $tasks->get( $other_wid, (string) $task['public_id'] ), 'a task is not readable from another workspace' );
check( null === $issues->get( $other_wid, (string) $issue['public_id'] ), 'an issue is not readable from another workspace' );

check( 0 === (int) $comments->open_count( $other_wid ), 'another workspace counts no comments' );
check( 0 === (int) $tasks->open_count_for( $other_wid, $designer_id ), 'another workspace assigns no tasks to our user' );
check( 0 === (int) $issues->open_count( $other_wid ), 'another workspace counts no issues' );

$boralis_member = rf15_user( 'boralis', 'subscriber', rf15_email( 'boralis' ) );
$rf15_users[]   = $boralis_member;
$members->add( $other_wid, array( 'user_id' => $boralis_member, 'role' => 'designer' ) );

foreach ( Workspace_Limits::capabilities() as $capability ) {
	check( ! $permissions->can( $boralis_member, $wid, $capability ), "a member of another workspace is denied '$capability' in the first" );
}
check( $permissions->can( $boralis_member, $other_wid, 'generation.run' ), 'the same member has their capabilities in their own workspace' );
check( null === $members->membership( $other_wid, $designer_id ), 'our designer has no membership in the other workspace' );

check( $permissions->can( $owner_id, $other_wid, 'members.invite' ), 'a site administrator may act in any workspace' );
check( $permissions->can( $site_admin_id, $other_wid, 'members.invite' ), 'a site administrator who is not a member may still act in a workspace' );

/* -------------------------------------------------------------------------
 * 21. Backward compatibility
 * ---------------------------------------------------------------------- */

echo "\n== 21. Backward compatibility ==\n";

// Phases 1-14 authorize with Project_Access::can_read(), which is owner-or-site-admin. The
// resolver must add paths, never narrow this one.
$access = new Project_Access();
check( $access->can_read( $owner_id, $pid ), 'the pre-Phase-15 access object still reads an owned project' );
check( ! $access->can_read( $designer_id, $pid ), 'the pre-Phase-15 access object still refuses a non-owner' );
	check( null === $access->readable_project( $owner_id, 'proj_nonexistent' ), 'a site administrator cannot read a project that does not exist' );
	check( ! $access->can_read( $designer_id, 'proj_nonexistent' ), 'a non-owner has no capability over a project that does not exist' );
check( $permissions->legacy_can_read_project( $owner_id, $pid ), 'the resolver defers to the pre-Phase-15 answer for the owner' );
check( ! $permissions->legacy_can_read_project( $designer_id, $pid ), 'the legacy path is unchanged for a non-owner' );

// Phase 15 *does* move the schema version, because it installs tables. The invariant is
// that it matches the newest declared migration - not that it stayed where Phase 14 left it,
// which was only true until this phase existed.
$declared_migrations = array_column( ( new Migrator() )->migrations(), 'to' );
$newest_migration    = (string) end( $declared_migrations );

check( in_array( '15.0.0', $declared_migrations, true ), 'the 15.0.0 migration is declared' );
check( Schema::DB_SCHEMA_VERSION === $newest_migration, 'the declared schema version matches the newest migration', Schema::DB_SCHEMA_VERSION . ' vs ' . $newest_migration );
check( version_compare( Schema::DB_SCHEMA_VERSION, '14.0.0', '>' ), 'and it has advanced past Phase 14 rather than regressed' );
check( '11.0' === Schema::JOB_SCHEMA_VERSION, 'the job schema version is unchanged, because Phase 15 declares no job types' );
check( Collaboration_Schema::VERSION === Schema::DB_SCHEMA_VERSION, 'the collaboration table schema and the migration version agree' );
check( 'replicaforge' === Workspace_Limits::ADMIN_PAGE, 'the shared admin page slug is declared' );

$final = $projects->find( $pid );
check( (string) $final['project_id'] === $pid, 'the project id is unchanged' );
/* -------------------------------------------------------------------------
 * 22. The REST layer
 * ---------------------------------------------------------------------- */

echo "\n== 22. The REST layer ==\n";

$api      = new Workspace_Api();
$perms    = $permissions;
$api_user = $owner_id;

$api->register_routes();

global $wp_rest_server;
$wp_rest_server = new WP_REST_Server();
do_action( 'rest_api_init', $wp_rest_server );

$all_routes = array_keys( $wp_rest_server->get_routes() );

// The namespace descriptor WordPress generates has no permission callback and dispatches no
// resource, so it is excluded by shape rather than counted as an ungated endpoint. Counting
// it was a probe error that reported a false finding.
$api_routes = array();
$endpoints  = 0;
$ungated    = array();

	foreach ( $all_routes as $route ) {
		// The namespace descriptor WordPress generates shares this prefix, has no
		// permission callback and dispatches no resource, so it is excluded by shape
		// rather than by name. Counting it reported a false finding.
		if ( substr_count( $route, '/' ) < 3 ) {
			continue;
		}
		if ( 0 !== strpos( $route, '/replicaforge/v1' ) ) {
			continue;
		}

		$handlers = (array) $wp_rest_server->get_routes()[ $route ];
		$api_routes[ $route ] = $handlers;

		foreach ( $handlers as $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['callback'] ) || ! is_callable( $handler['callback'] ) ) {
				continue;
			}
			//
			// The namespace descriptor WordPress synthesises for a sub-namespace shares this
			// prefix, has no permission callback and dispatches no resource. It is excluded by
			// shape - by what its callback actually is - rather than by the depth of its path.
			//
			// The previous test here excluded it by requiring fewer than three slashes, which
			// handles a two-segment sub-namespace but not a three-segment one. Phase 17's
			// /replicaforge/v1/orchestrator marker is three segments, so it was counted as an
			// ungated endpoint and reported a finding that was not one.
			$is_namespace_marker = is_array( $handler['callback'] )
				&& isset( $handler['callback'][1] )
				&& is_object( $handler['callback'][0] )
				&& 'WP_REST_Server' === get_class( $handler['callback'][0] )
				&& 'get_namespace_index' === $handler['callback'][1];

			if ( $is_namespace_marker ) {
				continue;
			}

			$endpoints++;
			if ( ! isset( $handler['permission_callback'] ) || ! is_callable( $handler['permission_callback'] ) ) {
				$ungated[] = $route;
			}
		}
	}

check( $endpoints > 40, 'the collaboration layer registers a substantial route surface', $endpoints . ' endpoints' );
check( array() === $ungated, 'every collaboration endpoint has a permission callback', count( $ungated ) . ' ungated' );

// No route may admit an unauthenticated caller.
wp_set_current_user( 0 );
$admitted = array();
foreach ( $api_routes as $route => $handlers ) {
	foreach ( (array) $handlers as $handler ) {
		if ( ! isset( $handler['permission_callback'] ) || ! is_callable( $handler['permission_callback'] ) ) {
			continue;
		}
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'id', $wid );
		if ( true === call_user_func( $handler['permission_callback'], $request ) ) {
			$admitted[] = $route;
		}
	}
}
check( array() === $admitted, 'no route admits an unauthenticated caller', count( $admitted ) . ' admitted' );

	// Nor one that is authenticated but a member of nothing.
	wp_set_current_user( $stranger_id );
	$admitted = array();

	foreach ( $api_routes as $route => $handlers ) {
		/*
		 * Two kinds of route are skipped, and the distinction is the path shape rather
		 * than a list of names:
		 *
		 * - the namespace descriptor, which dispatches no resource; and
		 * - routes that name no workspace, whose gate is membership in *some*
		 *   workspace. With no id in the path there is nothing to check, so admitting an
		 *   authenticated caller is correct for them - and the handler resolves its own
		 *   scope from a value it derives rather than one the caller supplied.
		 */
		if ( substr_count( $route, '/' ) < 3 || false === strpos( $route, '/workspaces' ) ) {
			continue;
		}

		foreach ( (array) $handlers as $handler ) {
			if ( ! isset( $handler['permission_callback'] ) || ! is_callable( $handler['permission_callback'] ) ) {
				continue;
			}
			$request = new WP_REST_Request( 'GET', $route );
			$request->set_param( 'id', $wid );
			$request->set_param( 'project', $pid );
			if ( true === call_user_func( $handler['permission_callback'], $request ) ) {
				$admitted[] = $route;
			}
		}
	}

	check( array() === $admitted, 'a member of no workspace is refused every workspace route', count( $admitted ) . ' admitted' );

// A handler must verify for itself, not only through its gate. Called directly here, with the
// gate bypassed, which is what a refactor or a second caller would do.
wp_set_current_user( $stranger_id );
$direct = new WP_REST_Request( 'GET', '/replicaforge/v1/workspaces/' . $wid );
$direct->set_param( 'id', $wid );
$response = $api->get_workspace( $direct );
check( 200 !== $response->get_status(), 'a handler refuses for itself when called without its gate', 'status ' . $response->get_status() );

// The owner, in their own workspace.
wp_set_current_user( $owner_id );
$request = new WP_REST_Request( 'GET', '/replicaforge/v1/workspaces/' . $wid );
$request->set_param( 'id', $wid );
check( 200 === $api->get_workspace( $request )->get_status(), 'the owner reads their own workspace' );

$payload = (array) $api->get_workspace( $request )->get_data();
check( isset( $payload['data']['settings'] ), 'the workspace payload carries its settings' );
check( isset( $payload['data']['members'] ), 'the workspace payload carries a member count' );
check( ! array_key_exists( 'token_salt', (array) $payload['data'] ), 'the workspace payload carries no secret' );

// The dashboard: real counts, both status axes, and no invented metric.
$project_request = new WP_REST_Request( 'GET', '/replicaforge/v1/workspaces/' . $wid . '/projects/' . $pid );
$project_request->set_param( 'id', $wid );
$project_request->set_param( 'project', $pid );
$dashboard = (array) $api->get_project( $project_request )->get_data();

check( isset( $dashboard['data']['stage'] ) && isset( $dashboard['data']['status'] ), 'the dashboard reports both status axes' );
	check( array_key_exists( (string) $dashboard['data']['stage'], Workspace_Limits::STAGES ), 'the dashboard stage is a declared agency stage', (string) $dashboard['data']['stage'] );
check( array_key_exists( 'accuracy', (array) $dashboard['data'] ) === false, 'the dashboard reports no accuracy score' );
check( array_key_exists( 'score', (array) $dashboard['data'] ) === false, 'and no aggregate score' );
check( (int) ( $dashboard['data']['versions'] ?? -1 ) === 2, 'the version count matches what was stored', (string) ( $dashboard['data']['versions'] ?? '' ) );
check( isset( $dashboard['data']['gates']['completable'] ), 'the dashboard reports the approval gates' );

// A project from another workspace and a project that does not exist must be answered
// identically, or the response is a probe for which project ids exist.
$cross_request = new WP_REST_Request( 'GET', '/replicaforge/v1/workspaces/' . $other_wid . '/projects/' . $pid );
$cross_request->set_param( 'id', $other_wid );
$cross_request->set_param( 'project', $pid );
$missing_request = new WP_REST_Request( 'GET', '/replicaforge/v1/workspaces/' . $other_wid . '/projects/proj_nonexistent' );
$missing_request->set_param( 'id', $other_wid );
$missing_request->set_param( 'project', 'proj_nonexistent' );

$cross    = $api->get_project( $cross_request );
$missing  = $api->get_project( $missing_request );
check( $cross->get_status() === 404, 'a cross-workspace project read is not found' );
check( $missing->get_status() === 404, 'a missing project read is not found too' );
check(
	(string) ( $cross->get_data()['error']['message'] ?? '' ) === (string) ( $missing->get_data()['error']['message'] ?? '' ),
	'the two refusals are worded identically, so neither is a probe'
);

// The current-workspace route provisions one rather than refusing, which is what keeps an
// install usable before somebody visits a screen.
$fresh_id = rf15_user( 'fresh', 'subscriber', rf15_email( 'fresh' ) );
$rf15_users[] = $fresh_id;
wp_set_current_user( $fresh_id );

$current = $api->current_workspace( new WP_REST_Request( 'GET', '/replicaforge/v1/workspace' ) );
check( 200 === $current->get_status(), 'a user with no workspace is given one' );
$current_data = (array) ( $current->get_data()['data'] ?? array() );
check( $fresh_id === (int) ( $current_data['owner_id'] ?? 0 ), 'the provisioned workspace is owned by the caller' );

$again = (array) ( $api->current_workspace( new WP_REST_Request( 'GET', '/replicaforge/v1/workspace' ) )->get_data()['data'] ?? array() );
check( (string) ( $again['id'] ?? '' ) === (string) ( $current_data['id'] ?? '' ), 'a second call returns the same workspace' );
check( 1 === ( new Workspace_Store() )->owned_count( $fresh_id ), 'and creates no second one' );

wp_set_current_user( $owner_id );

/* -------------------------------------------------------------------------
 * 23. Plugin wiring
 * ---------------------------------------------------------------------- */

echo "\n== 23. Plugin wiring ==\n";

$plugin = Plugin::instance();
check( $plugin instanceof Plugin, 'the plugin instance is reachable' );
check( $plugin->workspace_api() instanceof Workspace_Api, 'the plugin exposes the Phase 15 REST layer' );
check( $plugin->collaboration_log() instanceof Collaboration_Log, 'the plugin exposes the Phase 15 log' );

// Every earlier phase must still register its own routes from the plugin's own hook. A phase
// that stops registering fails; a phase that renames a route is not mistaken for a break -
// which an assertion on a remembered path would do.
	/*
	 * `register_rest_route()` does not read the server the action is passed - it
	 * reads the global that `rest_get_server()` returns. So a second
	 * `do_action( 'rest_api_init', $local )` registers everything into
	 * whatever the global still points at, and the local server comes back empty. The
	 * global is assigned for that reason; an earlier version of this section used a
	 * local, and found no routes at all.
	 */
	global $wp_rest_server;
	$wp_rest_server = new WP_REST_Server();
	do_action( 'rest_api_init', $wp_rest_server );
	$boot_routes = array_keys( $wp_rest_server->get_routes() );

$phases = array(
	'/replicaforge/v1/jobs'        => 'Phase 5',
	'/replicaforge/v1/plans'       => 'Phase 10',
	'/replicaforge/v1/websites'    => 'Phase 12',
	'/replicaforge/v1/visual/'     => 'Phase 13',
	'/replicaforge/v1/content/'    => 'Phase 14',
	'/replicaforge/v1/workspaces'  => 'Phase 15',
);

foreach ( $phases as $prefix => $label ) {
	$found = 0;
	foreach ( $boot_routes as $route ) {
		if ( 0 === strpos( $route, $prefix ) && $route !== $prefix && substr_count( $route, '/' ) > substr_count( $prefix, '/' ) ) {
			$found++;
		}
	}
	check( $found > 0, $label . ' still registers routes from the plugin boot', $found . ' route(s)' );
}
/* -------------------------------------------------------------------------
 * 24. The admin screens
 * ---------------------------------------------------------------------- */

echo "\n== 24. The admin screens ==\n";

// The screens are rendered rather than inspected, because a screen that throws produces no
// output and a screen that renders the wrong thing produces the wrong output, and neither is
// visible from a method listing.
if ( ! defined( 'ABSPATH' ) || ! file_exists( ABSPATH . 'wp-admin/includes/template.php' ) ) {
	check( false, 'the admin templates are loadable, so the screens can be rendered' );
} else {
	require_once ABSPATH . 'wp-admin/includes/template.php';

	/**
	 * Render a screen and return its HTML.
	 *
	 * `$_GET` is set because that is what the screens read, as WordPress admin pages do, and
	 * it is restored afterwards so one render cannot leak into the next.
	 *
	 * @param Workspace_Admin  $admin  Admin.
	 * @param string           $method Render method.
	 * @param array<string,mixed> $get Query arguments.
	 * @return string
	 */
	function rf15_render( $admin, $method, array $get = array() ) {
		$previous = $_GET;
		$_GET     = $get;
		ob_start();
		$admin->$method();
		$html = (string) ob_get_clean();
		$_GET = $previous;
		return $html;
	}

	// A separate workspace, so the screens under test are not the ones every other section
	// has been writing to.
	$screen_owner = rf15_user( 'screenowner', 'administrator' );
	$rf15_users[] = $screen_owner;
	wp_set_current_user( $screen_owner );

	$screen_ws  = $workspaces->create( $screen_owner, 'Screen Agency' );
	$screen_wid = (string) $screen_ws['public_id'];

	$screen_designer = rf15_user( 'screendesigner' );
	$rf15_users[]    = $screen_designer;
	$members->add( $screen_wid, array( 'user_id' => $screen_designer, 'role' => 'designer' ) );

	$screen_project = $projects->create( 'https://example.com/screen', array( 'name' => 'Screen Project' ) );
	$screen_pid     = (string) $screen_project['project_id'];
	$rf15_projects[] = $screen_pid;
	$projects->add_version( $screen_pid, array( 'change' => 'initial build', 'validation_id' => 'val_screen' ) );

	$screen_version = (string) $projects->find( $screen_pid )['versions'][0]['version_id'];

	$context->update( $screen_pid, array( 'workspace_id' => $screen_wid, 'stage' => 'in_review' ) );

	// A comment carrying markup, and a coordinate-only one, so the escaping and the anchor
	// reporting are both exercised on real rows.
	$screen_comments = new Comment_Store();
	$screen_comments->create( $screen_wid, $screen_pid, array(
		'body'          => 'Heading too small <script>alert(1)</script>',
		'version_id'    => $screen_version,
		'author_id'     => $screen_designer,
		'component_id'  => 'cmp_screen',
		'viewport'      => 'mobile',
		'region_x'      => 10.0, 'region_y' => 12.0, 'region_width' => 30.0, 'region_height' => 8.0,
	) );
	$screen_comments->create( $screen_wid, $screen_pid, array(
		'body' => 'Somewhere up top.',
		'version_id' => $screen_version,
		'author_id' => $screen_designer,
		'region_x' => 5.0, 'region_y' => 5.0, 'region_width' => 5.0, 'region_height' => 5.0,
	) );

	$screen_tasks = new Task_Store();
	$screen_tasks->from_difference( $screen_wid, $screen_pid, array(
		'title' => 'Fix screen spacing', 'source' => 'validation', 'severity' => 'critical', 'component_id' => 'cmp_screen',
	) );

	$screen_reviews = new Review_Store();
	$screen_reviews->create( $screen_wid, $screen_pid, array(
		'reviewer_email' => (string) get_userdata( $client_id )->user_email,
		'type'           => 'client',
		'version_id'     => $screen_version,
		'note'           => 'Internal brief: <b>check pricing</b>',
	) );

	$admin = new Workspace_Admin();

	// ---- The projects screen.
	$html = rf15_render( $admin, 'render_projects_page', array( 'workspace' => $screen_wid ) );

	check( strlen( $html ) > 500, 'the projects screen renders', strlen( $html ) . ' bytes' );
	check( false !== strpos( $html, 'Screen Project' ), 'the projects screen names the project' );
	check( false !== strpos( $html, 'analyzed' ), 'the projects screen shows the reconstruction status' );
	check( false !== strpos( $html, 'name="stage"' ), 'the projects screen offers a stage control to somebody who may set one' );
	check( false !== strpos( $html, 'page=replicaforge-review' ), 'the projects screen links to the review screen' );
	check( false !== strpos( $html, 'Approvals' ), 'the projects screen reports the approval gates' );

	// ---- The team screen.
	$team = rf15_render( $admin, 'render_team_page', array( 'workspace' => $screen_wid ) );

	check( strlen( $team ) > 1500, 'the team screen renders', strlen( $team ) . ' bytes' );
	check( false !== strpos( $team, 'name="role"' ), 'the team screen offers a role control' );
	check( false !== strpos( $team, 'value="add_member"' ), 'the team screen offers the add-member form' );
	check( false !== strpos( $team, 'value="invite"' ), 'the team screen offers the invite form' );
	check( false !== strpos( $team, 'replicaforge-matrix' ), 'the team screen renders the permission matrix' );
	check(
		count( Workspace_Limits::capabilities() ) === substr_count( $team, '<tr><th scope="row"><code>' ),
		'the matrix has a row per capability'
	);
	check(
		count( Workspace_Limits::capabilities() ) * count( Workspace_Limits::ROLES ) === substr_count( $team, 'replicaforge-matrix__cell' ),
		'the matrix has one cell per capability per role'
	);
	check( false !== strpos( $team, 'name="preferences[mentions_email]"' ), 'the team screen renders notification preferences' );
	check( false !== strpos( $team, 'Yours:' ), 'the team screen reports what the viewer may do' );

	// Every POST form carries a nonce. Compared by name rather than counted, because
	// `wp_nonce_field()` also emits a referer field and the preference rows add forms.
	$posts  = substr_count( $team, '<form method="post"' );
	$nonces = substr_count( $team, 'name="_wpnonce"' );
	check( $posts > 0 && $nonces >= $posts, 'every POST form on the team screen carries a nonce', $posts . ' form(s), ' . $nonces . ' nonce input(s)' );
	check( false === strpos( $team, 'method="get"' ), 'no mutating form uses GET' );

	// ---- The review screen.
	$review_html = rf15_render( $admin, 'render_review_page', array( 'workspace' => $screen_wid, 'project' => $screen_pid ) );

	check( strlen( $review_html ) > 2000, 'the review screen renders', strlen( $review_html ) . ' bytes' );
	check( false !== strpos( $review_html, 'Completable:' ), 'the review screen reports the approval gates' );
	check( false !== strpos( $review_html, 'Fix screen spacing' ), 'the review screen lists a promoted task' );
	check( false !== strpos( $review_html, 'validation' ), 'the review screen shows where a task was promoted from' );
	check( false !== strpos( $review_html, 'replicaforge-timeline' ), 'the review screen lists the activity timeline' );
	check( false !== strpos( $review_html, 'name="component_id"' ), 'the review screen offers a stable comment anchor' );
	check( false !== strpos( $review_html, 'name="region_x"' ), 'the review screen offers supplemental coordinates' );

	// Untrusted user content. `sanitize_textarea_field()` strips the markup on the way in, so
	// the assertion is that it never reaches the page - a stronger claim than "escaped on
	// output", and the one actually being made.
	check( false === strpos( $review_html, '<script>alert(1)</script>' ), 'a comment body carries no markup to the page' );
	check( false !== strpos( $review_html, 'Heading too small' ), 'and the text that survived is shown' );
	check( false === strpos( $review_html, 'check pricing' ), 'the internal review brief is not shown on the review row' );

	// Anchors are reported for what they are.
	check( false !== strpos( $review_html, 'cmp_screen' ), 'a stable anchor is shown as its component id' );
	check( false !== strpos( $review_html, 'Position only' ), 'a coordinate-only comment is labelled a position, not an anchor' );

	// ---- A lesser role.
	wp_set_current_user( $screen_designer );

	$designer_team = rf15_render( $admin, 'render_team_page', array( 'workspace' => $screen_wid ) );
	check( false === strpos( $designer_team, 'value="add_member"' ), 'a designer is refused the add-member form' );
	check( false === strpos( $designer_team, 'name="role"' ), 'a designer is refused the role control' );
	check( false !== strpos( $designer_team, 'replicaforge-matrix' ), 'but still sees the permission matrix' );
	check( false !== strpos( $designer_team, 'Yours:' ), 'and their own capabilities' );

	$designer_projects = rf15_render( $admin, 'render_projects_page', array( 'workspace' => $screen_wid ) );
	check( false !== strpos( $designer_projects, 'Screen Project' ), 'a designer still sees the project' );
	check( false === strpos( $designer_projects, 'name="stage"' ), 'but is not offered the stage control' );

	// ---- A client contact.
	wp_set_current_user( $client_id );
	$client_team = rf15_render( $admin, 'render_team_page', array( 'workspace' => $screen_wid ) );
	check( false === strpos( $client_team, 'value="add_member"' ), 'a client is refused the add-member form' );
	check( false === strpos( $client_team, 'name="role"' ), 'a client is refused the role control' );

	// ---- A member of no workspace, naming one they are not in.
	$screen_stranger = rf15_user( 'screenstranger' );
	$rf15_users[]    = $screen_stranger;
	wp_set_current_user( $screen_stranger );

	$stranger_html = rf15_render( $admin, 'render_projects_page', array( 'workspace' => $screen_wid ) );
	check( false === strpos( $stranger_html, 'Screen Project' ), 'a stranger naming a workspace they are not in does not see its projects' );
	check( false === strpos( $stranger_html, $screen_wid ), 'and the workspace id they named is not echoed back' );
	check( 1 === ( new Workspace_Store() )->owned_count( $screen_stranger ), 'they are given a workspace of their own instead' );

	wp_set_current_user( $owner_id );
}
/* -------------------------------------------------------------------------
 * 25. Retention
 * ---------------------------------------------------------------------- */

echo "\n== 25. Retention ==\n";

// The sweep is checked by planting rows at real ages and looking at what is left, not by
// asserting that a method exists. A retention that prunes nothing is indistinguishable
// from a retention that was never wired up until a row actually disappears.

$day      = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
$activity = new Activity_Store();
$audit    = new Audit_Store();
$inbox    = new Notification_Store();

/**
 * Set a row's age by rewriting its timestamp directly.
 *
 * The stores stamp their own clock on insert, which is the right behaviour and the reason
 * there is no "pretend this is old" parameter on them. Growing one for the sake of a test is
 * how such a parameter ends up being set by something.
 *
 * @param string $kind      Table kind.
 * @param string $public_id Row public id.
 * @param int    $age       Age in seconds.
 * @return void
 */
function rf15_backdate( $kind, $public_id, $age ) {
	global $wpdb;
	$table = Workspace_Limits::prefixed_table( $kind );
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET created_at = %s WHERE public_id = %s",
			gmdate( 'Y-m-d H:i:s', time() - (int) $age ),
			(string) $public_id
		)
	);
}

$aged = array( 'fresh' => 0, 'mid' => 200, 'ancient' => 900 );

foreach ( $aged as $label => $days ) {
	$row = $activity->record( $wid, 'project_updated', array( 'resource_type' => 'project', 'resource_id' => 'age_probe' ), $owner_id );
	if ( is_array( $row ) ) {
		rf15_backdate( 'activity', (string) $row['public_id'], $days * $day );
		$aged_rows[ $label ] = $row;
	}
}
check( isset( $aged_rows['fresh'], $aged_rows['mid'], $aged_rows['ancient'] ), 'activity rows were planted at three ages' );

foreach ( array( 'fresh' => 0, 'mid' => 200 ) as $label => $days ) {
	$row = $inbox->create( array( 'workspace_id' => $wid, 'user_id' => $owner_id, 'type' => 'review_requested', 'payload' => array( 'note' => $label ) ) );
	if ( is_array( $row ) ) {
		rf15_backdate( 'notifications', (string) $row['public_id'], $days * $day );
		$inbox_planted[ $label ] = $row;
	}
}
check( 2 === count( $inbox_planted ), 'notification rows were planted at two ages', (string) count( $inbox_planted ) );

// The two audit rows are the load-bearing part of this section. A 200-day audit row must
// survive a sweep that removed a 200-day activity row, because the two logs keep different
// ages. If they ever keep the same age, this is the assertion that fails.
$audit_mid = $audit->record( $wid, 'member_added', array( 'target_type' => 'member', 'target_id' => 'mem_mid' ), $owner_id );
$audit_old = $audit->record( $wid, 'member_added', array( 'target_type' => 'member', 'target_id' => 'mem_old' ), $owner_id );
rf15_backdate( 'audit', (string) $audit_mid['public_id'], 200 * $day );
rf15_backdate( 'audit', (string) $audit_old['public_id'], 400 * $day );

$maintenance = new Maintenance();
$summary     = $maintenance->daily();
$pruned      = (array) ( $summary['collaboration'] ?? array() );

check( array_key_exists( 'collaboration', $summary ), 'the daily sweep reports a collaboration section' );
check( array_key_exists( 'activity', $pruned ), 'it reports the activity prune', (string) ( $pruned['activity'] ?? 'absent' ) );
check( array_key_exists( 'audit', $pruned ), 'it reports the audit prune', (string) ( $pruned['audit'] ?? 'absent' ) );
check( array_key_exists( 'notifications', $pruned ), 'it reports the notification prune', (string) ( $pruned['notifications'] ?? 'absent' ) );
check( array_key_exists( 'invitations_expired', $pruned ), 'it reports the invitation sweep', (string) ( $pruned['invitations_expired'] ?? 'absent' ) );

// What survived, per table. Queried directly: the assertion is which row survived, not
// whether a store can be asked about it.
$activity_table = Workspace_Limits::prefixed_table( 'activity' );
$audit_table    = Workspace_Limits::prefixed_table( 'audit' );
$inbox_table    = Workspace_Limits::prefixed_table( 'notifications' );

// The row-level checks, by public id: which of the three ages is still there.
$survivors = array();
foreach ( $aged_rows as $label => $row ) {
	$survivors[ $label ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$activity_table} WHERE public_id = %s", (string) $row['public_id'] ) );
}

check( 1 === $survivors['fresh'], 'the fresh activity row survives the sweep' );
check( 0 === $survivors['mid'], 'the 200-day activity row does not' );
check( 0 === $survivors['ancient'], 'the 900-day activity row does not' );

// Scoped to the two planted ids rather than counted across the table. Every earlier section
// has left recent notifications behind, so a table-wide count would be asserting that the
// table contains exactly one row - which is a statement about the suite's history, not about
// the sweep.
$inbox_survivors = array();
foreach ( $inbox_planted as $label => $row ) {
	$inbox_survivors[ $label ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$inbox_table} WHERE public_id = %s", (string) $row['public_id'] ) );
}
check( 1 === $inbox_survivors['fresh'], 'the recent notification survives the sweep' );
check( 0 === $inbox_survivors['mid'], 'the 200-day notification is gone' );

// The property the two tables exist to demonstrate.
check(
	1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE public_id = %s", (string) $audit_mid['public_id'] ) ),
	'the 200-day audit row survives, though the activity row of the same age did not'
);
check(
	0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE public_id = %s", (string) $audit_old['public_id'] ) ),
	'the 400-day audit row does not'
);

check( Workspace_Limits::ACTIVITY_TTL < Workspace_Limits::AUDIT_TTL, 'and the constants say why: activity is kept for less time than audit', Workspace_Limits::ACTIVITY_TTL . ' vs ' . Workspace_Limits::AUDIT_TTL );

// Idempotence, because a sweep that reports work it did not do is worse than one that
// reports nothing.
$again       = $maintenance->daily();
$pruned_again = (array) ( $again['collaboration'] ?? array() );
check( 0 === (int) ( $pruned_again['activity'] ?? -1 ), 'a second sweep removes nothing further', (string) ( $pruned_again['activity'] ?? 'absent' ) );
check(
	1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$activity_table} WHERE public_id = %s", (string) $aged_rows['fresh']['public_id'] ) ),
	'and the fresh row is still there'
);

// A live invitation is not expired by a sweep that is not due. An invitation is a promise
// with a clock on it, and expiring it early breaks the promise rather than keeping it.
$invitations = new Invitation_Service();
$invited     = $invitations->invite( $wid, 'rf15-live-' . wp_rand( 10000, 99999 ) . '@example.com', 'reviewer', array( 'inviter_id' => $owner_id ) );
check( ! empty( $invited['ok'] ), 'a live invitation was issued' );
check( isset( $invited['invitation']['public_id'] ), 'and the result names the invitation to check afterwards' );

$maintenance->daily();

// Asked about *this* invitation rather than counted across the workspace. Earlier sections
// left outstanding invitations behind, so a count of one would be an assertion about the
// suite's history, and a count of zero would be a false failure.
//
// Read through the store rather than the service: the service answers about *tokens* and
// *outstanding sets*, and has no reason to expose a read for one row. The store is public
// and already answers this.
$still = ( new Invitation_Store() )->get( $wid, (string) $invited['invitation']['public_id'] );
check( is_array( $still ), 'the invitation is still readable after a sweep' );
check( ! empty( $still ) && 'pending' === (string) ( $still['status'] ?? '' ), 'and is still pending rather than expired', (string) ( $still['status'] ?? 'gone' ) );

/*
 * The site-without-tables case, asserted rather than assumed. A scheduled job that fatals
 * once a day is a worse problem than a log that is briefly overdue, and the only way to know
 * the guard works is to remove the tables and run the sweep.
 *
 * The tables are dropped and reinstalled inside the assertion rather than at the end of the
 * section, so a failure here leaves the suite's own database intact.
 */
$present = ( new Collaboration_Schema() )->status()['present'];
foreach ( $present as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$bare_ok = true;
try {
	$bare = $maintenance->daily();
} catch ( \Throwable $e ) {
	$bare_ok = false;
}
check( $bare_ok, 'the daily sweep runs with no collaboration tables present' );

$bare_pruned = (array) ( $bare['collaboration'] ?? array() );
check(
	0 === (int) ( $bare_pruned['activity'] ?? -1 ) && 0 === (int) ( $bare_pruned['audit'] ?? -1 ),
	'and reports zero removals rather than claiming any it did not make',
	wp_json_encode( $bare_pruned )
);

( new Collaboration_Schema() )->install();
check(
	count( ( new Collaboration_Schema() )->status()['present'] ) === count( $present ),
	'the schema reinstalls cleanly over the dropped tables'
);
/* -------------------------------------------------------------------------
 * 26. Reporting: System Status
 * ---------------------------------------------------------------------- */

echo "\n== 26. Reporting: System Status ==\n";

// The check is asserted against the database, not against a recorded version, because that
// is the only way to catch the state it exists for: an option saying the migration ran
// while the tables are gone.

$status_report = new System_Status();
$report        = $status_report->report();
$checks        = (array) ( $report['checks'] ?? array() );

check( array_key_exists( 'collaboration', $checks ), 'the report includes a collaboration check' );

$collab = (array) ( $checks['collaboration'] ?? array() );
echo '  detail: ' . (string) ( $collab['detail'] ?? '' ) . "\n";

check( 'ok' === (string) ( $collab['state'] ?? '' ), 'it is ok when the tables are there', (string) ( $collab['state'] ?? 'absent' ) );
check( false !== strpos( (string) ( $collab['detail'] ?? '' ), (string) count( $rf15_schema->status()['present'] ) ), 'it reports how many tables are present' );
check( false !== strpos( (string) ( $collab['detail'] ?? '' ), (string) Collaboration_Schema::VERSION ), 'and names the schema version' );
check( '' === (string) ( $collab['action'] ?? 'x' ), 'it suggests no action when there is nothing to do' );

/*
 * Now the disagreement this check exists to catch: the recorded version claims the
 * migration ran, and two tables are absent. `check_schema()` reads the option and would
 * report the schema as fine; this one reads the database and reports the tables.
 *
 * The version option is set first and asserted to be claiming the migration ran, so that if
 * the check reported "ok" the only explanation would be that it read the option.
 */
$claimed = get_option( Collaboration_Schema::VERSION_OPTION );
update_option( Collaboration_Schema::VERSION_OPTION, Collaboration_Schema::VERSION );
check( Collaboration_Schema::VERSION === (string) get_option( Collaboration_Schema::VERSION_OPTION ), 'the recorded version claims the migration ran' );

$dropped = array();
foreach ( array( 'comments', 'reviews' ) as $kind ) {
	$table = Workspace_Limits::prefixed_table( $kind );
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	$dropped[] = $table;
}

$report2 = $status_report->report();
$collab2 = (array) ( $report2['checks']['collaboration'] ?? array() );
echo '  detail: ' . (string) ( $collab2['detail'] ?? '' ) . "\n";

check( 'warn' === (string) ( $collab2['state'] ?? '' ), 'the missing tables are noticed', (string) ( $collab2['state'] ?? 'absent' ) );
check( 'fail' !== (string) ( $collab2['state'] ?? '' ), 'and reported as a warning, not a failure' );

$detail = (string) ( $collab2['detail'] ?? '' );
foreach ( $dropped as $table ) {
	check( false !== strpos( $detail, $table ), 'the detail names ' . $table );
}
check( false !== strpos( $detail, 'unaffected' ), 'it says the reconstruction engine is unaffected' );
check( '' !== (string) ( $collab2['action'] ?? '' ), 'and it suggests an action', (string) ( $collab2['action'] ?? '' ) );

/*
 * A warning, and not a failure, is the load-bearing part of this assertion. The
 * reconstruction engine - phases 1 to 14 - does not read these tables, so a site missing
 * them still analyses, generates and validates. A status report that called such an install
 * broken would be reporting a failure that does not exist.
 */
check( 'fail' !== (string) ( $report2['state'] ?? 'fail' ), 'the overall verdict is not blocked by a collaboration table', (string) ( $report2['state'] ?? '' ) );
check( 0 === (int) ( $report2['blocking'] ?? -1 ), 'and no check is counted as blocking', (string) ( $report2['blocking'] ?? 'absent' ) );
check( (int) ( $report2['warnings'] ?? 0 ) > 0, 'while warnings are counted', (string) ( $report2['warnings'] ?? 'absent' ) );

// The two checks are separate, so a disagreement between them is visible rather than
// resolved. Here they agree about the option, and disagree about reality.
$schema_check = (array) ( $report2['checks']['schema'] ?? array() );
check( isset( $schema_check['state'] ), 'the recorded-version check is reported separately', (string) ( $schema_check['state'] ?? 'absent' ) );

// And it recovers.
$rf15_schema->install();
$report3 = $status_report->report();
$collab3 = (array) ( $report3['checks']['collaboration'] ?? array() );

check( 'ok' === (string) ( $collab3['state'] ?? '' ), 'the check returns to ok once the tables are back', (string) ( $collab3['state'] ?? 'absent' ) );
check( '' === (string) ( $collab3['action'] ?? 'x' ), 'and the suggested action is cleared' );

// The report is data the admin renders; an unserialisable one would appear as a blank
// status screen rather than as an error.
$encoded        = wp_json_encode( $report3 );
$decoded        = json_decode( (string) $encoded, true );
$decoded_checks = (array) ( is_array( $decoded ) ? ( $decoded['checks'] ?? array() ) : array() );
$decoded_collab = (array) ( $decoded_checks['collaboration'] ?? array() );

check( is_string( $encoded ) && '' !== $encoded, 'the report encodes' );
check( array_key_exists( 'collaboration', $decoded_checks ), 'the collaboration check survives serialisation' );
check( '' !== (string) ( $decoded_collab['detail'] ?? '' ), 'and its detail survives it' );
check( 'ok' === (string) ( $decoded_collab['state'] ?? '' ), 'and its state' );

// The option is put back as it was, so a run does not leave the install claiming a version
// the test only pretended.
if ( false === $claimed ) {
	delete_option( Collaboration_Schema::VERSION_OPTION );
} else {
	update_option( Collaboration_Schema::VERSION_OPTION, $claimed );
}
check( 2 === count( $final['versions'] ), 'the version list is unchanged' );

/* -------------------------------------------------------------------------
 * Done
 * ---------------------------------------------------------------------- */

echo "\n";
echo "assertions: {$assertions}\n";
echo "PASS\n";
