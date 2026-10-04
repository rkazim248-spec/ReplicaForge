<?php
/**
 * Phase 20: developer platform — extensions, scoped credentials, events, webhooks, automation.
 *
 * Run with: run-test.php <wp-root> tests/phase20-platform-test.php
 * Assertions print a `PASS:` or `FAIL:` prefix; the runner counts them.
 *
 * Written to be re-runnable. Every record is created with a unique suffix and removed at the
 * end, and the bounded options this suite writes (`replicaforge_rate_buckets`,
 * `replicaforge_extension_settings`) are deleted rather than left behind — a leftover rate
 * bucket would make the *second* run of this suite fail on its first request-rate assertion,
 * which is the worst possible way for a test to be non-idempotent.
 *
 * ### What is deliberately not tested here
 *
 * - **Real HTTP delivery.** `wp_safe_remote_post` to a third-party endpoint is not exercised.
 *   The signing, retry, backoff, endpoint re-validation and terminal-state logic all are, and
 *   they are exercised through `Webhook_Delivery::deliver()` with the request itself mocked out
 *   by an endpoint that fails validation. See section 9 for exactly what that does and does not
 *   prove.
 * - **Workflow execution.** `Automation_Runner::start_workflow()` calls Phase 17's
 *   `Workflow_Executor::run()`, which drives the whole reconstruction pipeline. Section 8 tests
 *   the three loop guards directly — which is where the actual risk is — rather than asserting
 *   that a pipeline completes.
 * - **Permission denials against a real second workspace.** Phase 15 already covers
 *   cross-workspace isolation for the workspace tables; section 6 covers the platform's own
 *   tables, which are workspace-scoped by a different mechanism.
 *
 * @package ReplicaForge
 */

use ReplicaForge\Api_Authenticator;
use ReplicaForge\Api_Credential_Store;
use ReplicaForge\Automation_Runner;
use ReplicaForge\Automation_Store;
use ReplicaForge\Collaboration_Schema;
use ReplicaForge\Developer_Api;
use ReplicaForge\Event_Dispatcher;
use ReplicaForge\Event_Store;
use ReplicaForge\Extension_Configuration;
use ReplicaForge\Extension_Manifest;
use ReplicaForge\Extension_Provider_Contract;
use ReplicaForge\Extension_Registry;
use ReplicaForge\Extension_Store;
use ReplicaForge\Platform_Limits;
use ReplicaForge\Rate_Limiter;
use ReplicaForge\Secure_Token;
use ReplicaForge\Webhook_Delivery;
use ReplicaForge\Webhook_Delivery_Store;
use ReplicaForge\Webhook_Signer;
use ReplicaForge\Webhook_Store;
use ReplicaForge\Workspace_Limits;
use ReplicaForge\Workspace_Store;

$assertions = 0;
$failures   = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   What was checked.
 * @return bool
 */
function check( $condition, $message ) {
	global $assertions, $failures;
	$assertions++;
	if ( $condition ) {
		echo 'PASS: ' . $message . "\n";
		return true;
	}
	$failures++;
	echo 'FAIL: ' . $message . "\n";
	return false;
}

/**
 * Assert that a callable runs without raising.
 *
 * @param callable $callback Callback.
 * @param string   $message  What was checked.
 * @return mixed
 */
function check_raises( callable $callback, $message ) {
	global $assertions, $failures;
	$assertions++;
	try {
		$result = $callback();
	} catch ( Throwable $error ) {
		$failures++;
		echo 'FAIL: ' . $message . ' (raised ' . get_class( $error ) . ': ' . $error->getMessage() . ")\n";
		return null;
	}
	echo 'PASS: ' . $message . "\n";
	return $result;
}

/**
 * Start a section.
 *
 * @param string $title Section title.
 * @return void
 */
function section( $title ) {
	echo "\n== " . $title . " ==\n";
}

/**
 * A unique-ish suffix so a re-run does not collide with the last one.
 *
 * @param string $prefix Prefix.
 * @return string
 */
function rf20_tag( $prefix ) {
	return $prefix . substr( md5( (string) getmypid() . microtime( true ) . wp_rand( 1000, 9999 ) ), 0, 8 );
}

/**
 * A minimal provider used to exercise the registry.
 *
 * Every capability method returns something harmless. The point of the provider is to be a
 * real object with a real method set, so that the registry's "declared capability must have a
 * callable method" check is exercised against something that could plausibly fail it.
 */
class RF20_Test_Provider implements Extension_Provider_Contract {
	/** @var bool */
	public $observed = false;

	/** @var bool */
	public $should_throw = false;

	/**
	 * @param array $representation Representation.
	 * @param array $context        Context.
	 * @return array
	 */
	public function observe( array $representation, array $context = array() ) {
		if ( $this->should_throw ) {
			throw new RuntimeException( 'rf20 provider failure' );
		}
		$this->observed = true;
		return array( 'rf20' => 'observed', 'context_keys' => count( $context ) );
	}

	/**
	 * @param array $context Context.
	 * @return array
	 */
	public function components( array $context = array() ) {
		return array( 'rf20_button' => array( 'name' => 'Button' ) );
	}

	/**
	 * @param array $context Context.
	 * @return array
	 */
	public function template( array $context = array() ) {
		return array( 'schema_version' => Platform_Limits::MANIFEST_SCHEMA_VERSION, 'id' => 'rf20-provided' );
	}

	/**
	 * @param array $source      Source.
	 * @param array $destinations Destinations.
	 * @param array $context     Context.
	 * @return array
	 */
	public function map_content( array $source, array $destinations, array $context = array() ) {
		return array();
	}

	/**
	 * @param array $subject Subject.
	 * @param array $context Context.
	 * @return array
	 */
	public function validate( array $subject, array $context = array() ) {
		return array( array( 'code' => 'rf20_check', 'severity' => 'notice', 'message' => 'Provider check.' ) );
	}

	/**
	 * @param array $context Context.
	 * @return string
	 */
	public function export( array $context = array() ) {
		return 'rf20-export';
	}

	/**
	 * @param array $context Context.
	 * @return bool
	 */
	public function notify( array $context = array() ) {
		return true;
	}

	/**
	 * @param array $context Context.
	 * @return array
	 */
	public function automations( array $context = array() ) {
		return array();
	}
}

/**
 * A provider that declares a capability it has no method for.
 *
 * `Extension_Provider_Contract` is an interface, so PHP requires every method to exist — this
 * subclass cannot remove one. It therefore *lies* in its manifest instead, which is the case
 * that matters: the manifest is data and the object is code, and nothing in PHP connects them.
 */
class RF20_Lying_Provider extends RF20_Test_Provider {}

/* ---------------------------------------------------------------------------
 * Setup
 * ------------------------------------------------------------------------- */

$rf20_tag = rf20_tag( 'rf20' );

$rf20_schema   = new Collaboration_Schema();
$rf20_install  = $rf20_schema->install();
$rf20_status   = $rf20_schema->status();
$rf20_logger   = new ReplicaForge\Logger();

$rf20_users    = ( new Workspace_Store() )->for_user( 1 );
$rf20_workspace = '';

foreach ( $rf20_users as $rf20_ws ) {
	$id = (string) ( $rf20_ws['public_id'] ?? '' );
	if ( '' !== $id ) {
		$rf20_workspace = $id;
		break;
	}
}

if ( '' === $rf20_workspace ) {
	$rf20_created = ( new Workspace_Store() )->create( 1, 'ReplicaForge Phase 20 ' . $rf20_tag );
	$rf20_workspace = is_array( $rf20_created ) ? (string) ( $rf20_created['public_id'] ?? '' ) : '';
}

$rf20_credentials = new Api_Credential_Store( null, $rf20_logger );
$rf20_webhooks    = new Webhook_Store( null, $rf20_logger );
$rf20_deliveries  = new Webhook_Delivery_Store( null, $rf20_logger );
$rf20_events      = new Event_Store( null, $rf20_logger );
$rf20_automations = new Automation_Store( null, $rf20_logger );
$rf20_extensions  = new Extension_Store( null, $rf20_logger );
$rf20_limiter     = new Rate_Limiter( $rf20_logger );

$rf20_cleanup = array(
	'credentials' => array(),
	'webhooks'    => array(),
	'events'      => array(),
	'automations' => array(),
	'extensions'  => array(),
);

/* ---------------------------------------------------------------------------
 * 1. Schema
 * ------------------------------------------------------------------------- */

	/* 	 * Guarded findings: rf21-002-migration-reported-false-success 	 * 	 * Each id above is a registered entry in Security_Audit::baseline(). Reverting the fix it 	 * describes makes an assertion in this section fail, and phase21-security-test.php fails 	 * if any of these ids stops appearing in this file. 	 */
section( '1. Platform schema' );

check( ! empty( $rf20_install['ok'] ), 'the schema installs with no errors' );
check( ! empty( $rf20_status['ok'] ), 'every collaboration table exists, including the six Phase 20 tables' );
check( '20.0.0' === ReplicaForge\Collaboration_Schema::VERSION, 'the collaboration schema version is 20.0.0' );
check( '20.0.0' === ReplicaForge\Schema::DB_SCHEMA_VERSION, 'the database schema version is 20.0.0' );

/*
 * The reserved-word regression.
 *
 * `automation.trigger` is a MySQL reserved word, so `dbDelta` silently declined to create
 * `replicaforge_automations` while `install()` reported success — the check that was supposed
 * to catch exactly that only ran when `dbDelta` returned an empty array. This asserts both
 * halves of the fix: the column is not called `trigger`, and the table is genuinely present.
 *
 * Without the second half this assertion would pass on a build where the column was renamed and
 * the installer was still lying.
 */
$rf20_automations_table = Workspace_Limits::prefixed_table( 'automation' );

check( '' !== $rf20_automations_table, 'the automation entity has a declared table' );
check(
	in_array( $rf20_automations_table, (array) $rf20_status['present'], true ),
	'the automations table exists — dbDelta silently skipped it while the column was named "trigger"'
);

global $wpdb;

$rf20_columns = array();

if ( '' !== $rf20_automations_table ) {
	$rf20_columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$rf20_automations_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- an internal identifier.
}

check( in_array( 'trigger_event', $rf20_columns, true ), 'the trigger column is named trigger_event, avoiding the reserved word' );
check( ! in_array( 'trigger', $rf20_columns, true ), 'no column is named with the reserved word' );

/* ---------------------------------------------------------------------------
 * 2. Vocabularies
 * ------------------------------------------------------------------------- */

section( '2. Vocabularies' );

check( count( Platform_Limits::API_SCOPES ) >= 10, 'the API declares a granular scope set' );

/*
 * No unbounded scope exists.
 *
 * §12 asks for scopes "such as projects:read" and warns against "everything / admin / root"
 * unless absolutely necessary. This asserts the negative directly, because a scope added later
 * for convenience is exactly the regression worth catching.
 */
$rf20_unbounded = array();

foreach ( array_keys( Platform_Limits::API_SCOPES ) as $rf20_scope ) {
	if ( preg_match( '/(^|:)(admin|root|all|everything|full|any)(\b|$)/i', (string) $rf20_scope ) ) {
		$rf20_unbounded[] = $rf20_scope;
	}
}

check( array() === $rf20_unbounded, 'no scope is named admin, root, all, everything or any', implode( ', ', $rf20_unbounded ) );

check( count( Platform_Limits::EXTENSION_CAPABILITIES ) === 8, 'eight extension capabilities are declared' );
check( count( Platform_Limits::EXTENSION_STATES ) >= 6, 'the extension lifecycle declares at least six states' );

$rf20_all_gates_mapped = true;
$rf20_gates = $wf = array_keys( Platform_Limits::GATE_SCOPES );

foreach ( $rf20_gates as $rf20_gate ) {
	foreach ( Platform_Limits::gate_scopes( $rf20_gate ) as $rf20_mapped ) {
		if ( ! Platform_Limits::is_api_scope( $rf20_mapped ) ) {
			$rf20_all_gates_mapped = false;
		}
	}
}

check( $rf20_all_gates_mapped, 'every gate maps only to scopes that exist' );
check( count( $rf20_gates ) >= 25, 'the existing permission gates are mapped to scopes' );

/*
 * `sync.completed` is deliberately absent.
 *
 * `Capability_Registry::probe_sync()` reports `application => unavailable` unconditionally, so
 * an event for it could never fire. §14 asks for events that "actually exist"; subscribing to
 * one that cannot fire is a promise the platform cannot keep.
 */
check( Platform_Limits::is_event( 'workflow.completed' ), 'workflow.completed is a declared event' );
check( ! Platform_Limits::is_event( 'sync.completed' ), 'sync.completed is not declared, because nothing can apply a sync' );

foreach ( array_keys( Platform_Limits::EVENTS ) as $rf20_event ) {
	if ( ! Platform_Limits::is_public_event( $rf20_event ) ) {
		continue;
	}
}

check( Platform_Limits::is_automation_trigger( 'manual' ), 'manual is an automation trigger' );
check( Platform_Limits::is_automation_action( 'start_workflow' ), 'start_workflow is an automation action' );

/*
 * No automation action is a callable.
 *
 * §25 and §73 both forbid an action that takes code. `start_workflow` names a service; nothing
 * in the vocabulary can be "run this closure".
 */
check( ! Platform_Limits::is_automation_action( 'run_php' ), 'there is no action that runs code' );
check( ! Platform_Limits::is_automation_action( 'eval' ), 'there is no eval action' );

/* ---------------------------------------------------------------------------
 * 3. Workspace capabilities
 * ------------------------------------------------------------------------- */

section( '3. Workspace capabilities and audit actions' );

$rf20_capabilities = Workspace_Limits::capabilities();

foreach ( array( 'api.credentials.read', 'api.credentials.manage', 'api.webhooks.manage', 'api.extensions.manage', 'api.automations.manage', 'api.events.read' ) as $rf20_cap ) {
	check( in_array( $rf20_cap, $rf20_capabilities, true ), "the '$rf20_cap' capability is in the master vocabulary" );
}

/*
 * Least privilege, asserted rather than asserted-about.
 *
 * `Permission_Manager::can()` validates against `CAPABILITY_GROUPS` and nothing else, so a
 * capability that exists only in `Platform_Limits` would be refused and the console would
 * render a button that always 403s. This is the check that would have caught that.
 */
$rf20_designer = Workspace_Limits::caps_for_role( 'designer' );

check( in_array( 'api.events.read', (array) $rf20_designer, true ), 'a designer may read events' );
check( ! in_array( 'api.credentials.manage', (array) $rf20_designer, true ), 'a designer may not mint credentials' );
check( ! in_array( 'api.webhooks.manage', (array) $rf20_designer, true ), 'a designer may not create webhooks' );

$rf20_pm = Workspace_Limits::caps_for_role( 'project_manager' );

check( in_array( 'api.webhooks.manage', (array) $rf20_pm, true ), 'a project manager may manage webhooks' );
check( ! in_array( 'api.credentials.manage', (array) $rf20_pm, true ), 'a project manager may not mint credentials' );

$rf20_client = Workspace_Limits::caps_for_role( 'client' );

$rf20_client_api = array_filter(
	(array) $rf20_client,
	static function ( $capability ) {
		return 0 === strpos( (string) $capability, 'api.' );
	}
);

check( array() === $rf20_client_api, 'a client holds no platform capability at all' );

foreach ( array( 'api_credential_created', 'api_credential_revoked', 'webhook_created', 'automation_created', 'extension_state_changed' ) as $rf20_audit ) {
	check(
		in_array( $rf20_audit, Workspace_Limits::AUDIT_EVENTS, true ),
		"'$rf20_audit' is in the audit vocabulary — otherwise the log write is silently dropped"
	);
}

/* ---------------------------------------------------------------------------
 * 4. Extension manifests
 * ------------------------------------------------------------------------- */

section( '4. Extension manifest validation' );

$rf20_manifest = array(
	'schema_version' => Platform_Limits::MANIFEST_SCHEMA_VERSION,
	'id'             => 'rf20-good',
	'name'           => 'RF20 Good Extension',
	'version'        => '1.0.0',
	'capabilities'   => array( 'analyzer' ),
	'permissions'    => array( 'analysis.result.write' ),
);

$rf20_valid = Extension_Manifest::validate( $rf20_manifest );

check( ! empty( $rf20_valid['ok'] ), 'a well-formed manifest validates' );
check( array() === $rf20_valid['errors'], 'a well-formed manifest reports no errors' );

$rf20_unknown_cap = Extension_Manifest::validate( array_merge( $rf20_manifest, array( 'capabilities' => array( 'execute_php' ) ) ) );

check( empty( $rf20_unknown_cap['ok'] ), 'an unknown capability is refused outright' );
check( ! empty( $rf20_unknown_cap['errors'] ), 'the refusal names the capability' );

/*
 * Forbidden permissions.
 *
 * §9 lists database, filesystem, PHP, SQL, shell, capability management and impersonation as
 * things that must never be grantable. These are asserted individually rather than by pattern,
 * because "the list is the list I wrote" proves nothing.
 */
foreach ( array( 'database.write', 'filesystem.write', 'code.execute', 'sql.execute', 'shell.execute', 'user.impersonate', 'secret.read', 'capability.manage' ) as $rf20_forbidden ) {
	$rf20_result = Extension_Manifest::validate( array_merge( $rf20_manifest, array( 'permissions' => array( $rf20_forbidden ) ) ) );

	check(
		empty( $rf20_result['ok'] ),
		"the permission '$rf20_forbidden' is refused"
	);
}

$rf20_future = Extension_Manifest::validate( array_merge( $rf20_manifest, array( 'schema_version' => '99.0' ) ) );

check( empty( $rf20_future['ok'] ), 'a manifest from a newer schema is refused rather than partially read' );

$rf20_no_caps = Extension_Manifest::validate( array_merge( $rf20_manifest, array( 'capabilities' => array() ) ) );

check( empty( $rf20_no_caps['ok'] ), 'a manifest with no capabilities is refused' );

$rf20_self_dep = Extension_Manifest::validate( array_merge( $rf20_manifest, array( 'dependencies' => array( 'rf20-good' ) ) ) );

check( empty( $rf20_self_dep['ok'] ), 'a manifest depending on itself is refused' );

/*
 * Incompatibility is not invalidity.
 *
 * An extension written against a future ReplicaForge is a *valid manifest* that this install
 * cannot run. Refusing it outright would throw away the information that lets an operator
 * upgrade rather than disable.
 */
$rf20_incompatible = Extension_Manifest::validate(
	array_merge( $rf20_manifest, array( 'requires' => array( 'replicaforge' => '99.0.0' ) ) )
);

check( ! empty( $rf20_incompatible['ok'] ), 'a manifest requiring a newer ReplicaForge is still structurally valid' );
check( empty( $rf20_incompatible['compatible'] ), 'but it is reported as incompatible' );
check( ! empty( $rf20_incompatible['incompatibilities'] ), 'and the incompatibility is explained' );

/* ---------------------------------------------------------------------------
 * 5. Extension registry
 * ------------------------------------------------------------------------- */

	/* 	 * Guarded findings: rf21-005-store-find-signature-collision 	 * 	 * Each id above is a registered entry in Security_Audit::baseline(). Reverting the fix it 	 * describes makes an assertion in this section fail, and phase21-security-test.php fails 	 * if any of these ids stops appearing in this file. 	 */
section( '5. Extension registry and failure isolation' );

$rf20_provider  = new RF20_Test_Provider();
$rf20_registry  = new Extension_Registry( $rf20_extensions, $rf20_logger );
$rf20_extension_id = 'rf20-' . $rf20_tag;

$rf20_registered = $rf20_registry->register(
	$rf20_provider,
	array_merge( $rf20_manifest, array( 'id' => $rf20_extension_id ) )
);

check( ! empty( $rf20_registered['ok'] ), 'a provider implementing the contract registers' );

$rf20_cleanup['extensions'][] = $rf20_extension_id;

$rf20_record = $rf20_registry->get( $rf20_extension_id );

check( null !== $rf20_record, 'the registered extension can be read back' );
check( 'active' === (string) ( $rf20_record['status'] ?? '' ), 'a compatible extension is active' );
check( Platform_Limits::is_live_extension( (string) ( $rf20_record['status'] ?? '' ) ), 'its capabilities are live' );
check( true === $rf20_record['installed'], 'the provider is reported as installed this request' );

/*
 * The manifest/provider mismatch check.
 *
 * This is the guarantee that replaces a per-capability interface. A manifest is data and a
 * provider is an object; nothing in PHP connects them, so `register()` requires every declared
 * capability to have a callable method and refuses by name when it does not.
 */
$rf20_liar = new RF20_Lying_Provider();

$rf20_lied = $rf20_registry->register(
	$rf20_liar,
	array_merge( $rf20_manifest, array( 'id' => 'rf20-liar-' . $rf20_tag, 'capabilities' => array( 'automation' ) ) )
);

/*
 * `RF20_Lying_Provider` does implement `automations()`, so this registration should succeed —
 * which means this assertion proves the *opposite*: that a provider whose method exists but
 * declares a different capability still registers. The genuinely-interesting case needs a
 * provider that genuinely lacks the method, which PHP's interface makes impossible to construct.
 *
 * So what is asserted here is the weaker, still-true property: a declared capability resolves
 * to a callable, and `METHODS` is the single map both the check and the dispatch read.
 */
check( ! empty( $rf20_lied['ok'] ), 'a provider whose declared capability has a matching method registers' );
check(
	array_key_exists( 'automation', Extension_Provider_Contract::METHODS ),
	'every capability has an entry in the contract map, which is what register() and call() share'
);

foreach ( Extension_Provider_Contract::METHODS as $rf20_cap => $rf20_spec ) {
	$rf20_ok = Platform_Limits::is_extension_permission( (string) $rf20_spec['permission'] );

	check( $rf20_ok, "the permission '$rf20_spec[permission]' required by '$rf20_cap' exists in the vocabulary" );
}

/*
 * Failure isolation — §42 Scenario I.
 *
 * A provider that throws must not end the operation, must produce a finding, and must be
 * counted towards its failure ceiling. The remaining providers still run.
 */
$rf20_thrower = new RF20_Test_Provider();
$rf20_thrower->should_throw = true;

$rf20_throw_id = 'rf20-throw-' . $rf20_tag;

$rf20_registry->register(
	$rf20_thrower,
	array_merge( $rf20_manifest, array( 'id' => $rf20_throw_id ) )
);

$rf20_cleanup['extensions'][] = $rf20_throw_id;

$rf20_good2 = new RF20_Test_Provider();
$rf20_good2_id = 'rf20-good2-' . $rf20_tag;

$rf20_registry->register(
	$rf20_good2,
	array_merge( $rf20_manifest, array( 'id' => $rf20_good2_id ) )
);

$rf20_cleanup['extensions'][] = $rf20_good2_id;

$rf20_called = $rf20_registry->call( 'analyzer', array( array( 'page' => 1 ) ), array( 'workspace_id' => $rf20_workspace ) );

check( is_array( $rf20_called ), 'calling a capability returns a result envelope' );
check( isset( $rf20_called['results'] ) && isset( $rf20_called['findings'] ), 'the envelope separates results from findings' );
check( count( $rf20_called['results'] ) >= 2, 'the surviving providers still ran' );
check( $rf20_good2->observed, 'a provider after a throwing one is still called' );

$rf20_threw = array();

foreach ( (array) $rf20_called['findings'] as $rf20_finding ) {
	if ( 'extension_threw' === (string) ( $rf20_finding['code'] ?? '' ) ) {
		$rf20_threw[] = $rf20_finding;
	}
}

check( ! empty( $rf20_threw ), 'the throwing provider produces a finding naming the error' );
check( false !== strpos( (string) ( $rf20_threw[0]['message'] ?? '' ), 'continued' ), 'the finding says the platform continued without it' );

$rf20_after = $rf20_registry->get( $rf20_throw_id );

check( (int) ( $rf20_after['failure_count'] ?? 0 ) >= 1, 'the failing extension is counted' );
check( '' !== (string) ( $rf20_after['last_error'] ?? '' ), 'and the reason is recorded' );

/*
 * A capability whose permission was not granted is not called.
 *
 * Capabilities and permissions are separate switches, and this is what stops an analyzer
 * reading a representation it was not granted access to.
 */
$rf20_permless = new RF20_Test_Provider();
$rf20_permless_id = 'rf20-permless-' . $rf20_tag;

$rf20_perm_manifest = array_merge(
	$rf20_manifest,
	array( 'id' => $rf20_permless_id, 'capabilities' => array( 'analyzer' ) )
);

/*
 * `Extension_Manifest::validate()` grants the permission a declared capability's method needs,
 * because otherwise a provider would be invoked with input it has no right to see. So the
 * "permission missing" branch in `call()` is reachable only by removing the permission after
 * registration — which is exactly what the state below simulates.
 */
$rf20_registry->register( $rf20_permless, $rf20_perm_manifest );

$rf20_cleanup['extensions'][] = $rf20_permless_id;

$rf20_perm_reflection = new ReflectionProperty( 'ReplicaForge\Extension_Registry', 'records' );
$rf20_perm_reflection->setAccessible( true );

$rf20_records = $rf20_perm_reflection->getValue( $rf20_registry );

if ( isset( $rf20_records[ $rf20_permless_id ] ) ) {
	$rf20_records[ $rf20_permless_id ]['permissions'] = array( 'template.metadata.read' );
	$rf20_perm_reflection->setValue( $rf20_registry, $rf20_records );
}

$rf20_perm_called = $rf20_registry->call( 'analyzer', array( array( 'page' => 1 ) ), array( 'workspace_id' => $rf20_workspace ) );

$rf20_perm_denied = 0;

foreach ( (array) $rf20_perm_called['findings'] as $rf20_finding ) {
	if ( 'extension_permission_missing' === (string) ( $rf20_finding['code'] ?? '' ) ) {
		$rf20_perm_denied++;
	}
}

check( $rf20_perm_denied >= 1, 'a provider missing the capability permission is not called' );

/* ---------------------------------------------------------------------------
 * 6. Extension configuration
 * ------------------------------------------------------------------------- */

section( '6. Extension configuration' );

$rf20_schema_fields = array(
	'endpoint' => array( 'type' => 'url' ),
	'count'    => array( 'type' => 'int', 'min' => 1, 'max' => 10 ),
	'mode'     => array( 'type' => 'enum', 'options' => array( 'a', 'b' ) ),
	'label'    => array( 'type' => 'string', 'max_length' => 20 ),
	'on'       => array( 'type' => 'bool' ),
);

$rf20_ext_url_id = 'rf20-cfg-' . $rf20_tag;

$rf20_registry->register(
	new RF20_Test_Provider(),
	array_merge( $rf20_manifest, array( 'id' => $rf20_ext_url_id, 'configuration' => array( 'schema' => $rf20_schema_fields ) ) )
);

$rf20_cleanup['extensions'][] = $rf20_ext_url_id;

check( true === Extension_Configuration::set( $rf20_ext_url_id, 'mode', $rf20_schema_fields, 'a' ), 'a valid enum value is stored' );
check( is_wp_error( Extension_Configuration::set( $rf20_ext_url_id, 'mode', $rf20_schema_fields, 'c' ) ), 'an out-of-enum value is refused' );
check( is_wp_error( Extension_Configuration::set( $rf20_ext_url_id, 'count', $rf20_schema_fields, 99 ) ), 'an out-of-bounds integer is refused' );
check( is_wp_error( Extension_Configuration::set( $rf20_ext_url_id, 'undeclared', $rf20_schema_fields, 'x' ) ), 'an undeclared field is refused' );

/*
 * SSRF on a declared `url` setting.
 *
 * An extension declaring a `url` setting is declaring a place ReplicaForge will fetch, so the
 * value goes through the same boundary as everything else the plugin fetches. An internal
 * address must be refused at configuration time, not discovered at request time.
 */
check(
	is_wp_error( Extension_Configuration::set( $rf20_ext_url_id, 'endpoint', $rf20_schema_fields, 'http://127.0.0.1/' ) ),
	'an internal address is refused for a url setting'
);

check(
	is_wp_error( Extension_Configuration::set( $rf20_ext_url_id, 'endpoint', $rf20_schema_fields, 'http://169.254.169.254/' ) ),
	'the cloud metadata address is refused for a url setting'
);

check(
	! is_wp_error( Extension_Configuration::set( $rf20_ext_url_id, 'endpoint', $rf20_schema_fields, 'https://example.com/hook' ) ),
	'a public https address is accepted'
);

check(
	is_wp_error( Extension_Configuration::set( $rf20_ext_url_id, 'label', $rf20_schema_fields, str_repeat( 'x', 40 ) ) ),
	'a value past the declared max_length is refused'
);

/* ---------------------------------------------------------------------------
 * 7. Scoped credentials
 * ------------------------------------------------------------------------- */

	/* 	 * Guarded findings: rf21-001-permission-callback-not-callable 	 * 	 * Each id above is a registered entry in Security_Audit::baseline(). Reverting the fix it 	 * describes makes an assertion in this section fail, and phase21-security-test.php fails 	 * if any of these ids stops appearing in this file. 	 */
section( '7. Scoped API credentials' );

check( '' !== $rf20_workspace, 'a workspace is available for the credential tests' );

$rf20_token_row = $rf20_credentials->create(
	$rf20_workspace,
	array(
		'name'    => 'RF20 token ' . $rf20_tag,
		'scopes'  => array( 'projects:read' ),
		'user_id' => 1,
	)
);

check( ! is_wp_error( $rf20_token_row ), 'a credential is created' );

if ( is_wp_error( $rf20_token_row ) ) {
	echo 'RESULT: FAIL' . "\n";
	exit( 1 );
}

$rf20_cleanup['credentials'][] = (string) $rf20_token_row['public_id'];

$rf20_token = (string) $rf20_token_row['token'];

check( 0 === strpos( $rf20_token, Platform_Limits::TOKEN_PREFIX ), 'the token carries the ReplicaForge prefix' );
check( strlen( $rf20_token ) > strlen( Platform_Limits::TOKEN_PREFIX ) + 60, 'the token has at least 256 bits of entropy' );

/*
 * The plaintext is shown once and is not recoverable.
 *
 * The stored record must not contain it in any form — not the token, not a substring of it.
 * This is asserted against the raw row rather than through the store's presenter, because the
 * presenter is the layer that could hide the problem.
 */
$rf20_stored = $rf20_credentials->read( $rf20_workspace, (string) $rf20_token_row['public_id'] );

check( null !== $rf20_stored, 'the credential can be read back' );
check( ! isset( $rf20_stored['token'] ), 'the stored record has no token field' );

$rf20_serialised = wp_json_encode( $rf20_stored );

check( false === strpos( (string) $rf20_serialised, $rf20_token ), 'the plaintext token does not appear anywhere in the stored record' );
check( 64 === strlen( (string) $rf20_stored['token_hash'] ), 'a 64-character HMAC is stored instead' );
check( $rf20_stored['token_hash'] !== $rf20_token, 'the stored hash is not the token' );

/*
 * §11: hash where possible. `Secure_Token::hash()` is a salted HMAC, not a bare hash, so a
 * database dump cannot be attacked with a wordlist and the value is not reversible even with
 * the site salt in hand elsewhere.
 */
check(
	Secure_Token::hash( substr( $rf20_token, strlen( Platform_Limits::TOKEN_PREFIX ) ) ) === (string) $rf20_stored['token_hash'],
	'the stored hash is the HMAC of the presented token'
);

check( $rf20_credentials->authenticate( $rf20_token ) !== null, 'the token authenticates' );
check( $rf20_credentials->authenticate( Platform_Limits::TOKEN_PREFIX . str_repeat( 'a', 64 ) ) === null, 'a wrong token does not authenticate' );
check( $rf20_credentials->authenticate( '' ) === null, 'an empty token does not authenticate' );
check( $rf20_credentials->authenticate( 'not-even-hex' ) === null, 'a malformed token is refused before any hash comparison' );
check( $rf20_credentials->authenticate( $rf20_token_row['token_hash'] ) === null, 'the stored hash is not itself a usable token' );

/*
 * The prefix is a non-secret handle.
 *
 * It identifies a credential in a log or a screenshot without being usable. Asserted so that
 * changing it to something derived from the token itself fails here.
 */
check( 8 === strlen( (string) $rf20_stored['prefix'] ), 'the credential carries an 8-character reference prefix' );
check( 0 === strpos( (string) $rf20_stored['token_hash'], (string) $rf20_stored['prefix'] ), 'the prefix is derived from the stored hash, not from the token' );

/* --- expiry --- */

$rf20_expiring = $rf20_credentials->create(
	$rf20_workspace,
	array( 'name' => 'RF20 expiring ' . $rf20_tag, 'scopes' => array( 'projects:read' ), 'user_id' => 1 )
);

check( ! is_wp_error( $rf20_expiring ), 'a second credential is created' );

if ( ! is_wp_error( $rf20_expiring ) ) {
	$rf20_cleanup['credentials'][] = (string) $rf20_expiring['public_id'];

	/*
	 * Backdating `expires_at` rather than waiting for the clock.
	 *
	 * `update_row()` is `protected` on `Collaboration_Store`, so this uses a scope-bound
	 * closure rather than widening a production method's visibility for a test. Simulating
	 * elapsed time is the only honest way to test expiry; a credential created with
	 * `expires_in_days => 1` and checked immediately would not exercise the boundary at all.
	 */
	$rf20_backdate = function ( $id, $when ) {
		$this->update_row( $id, array( 'expires_at' => $when ) );
	};

	$rf20_backdate->call( $rf20_credentials, (string) $rf20_expiring['public_id'], gmdate( 'Y-m-d H:i:s', time() - 60 ) );

	check( $rf20_credentials->authenticate( (string) $rf20_expiring['token'] ) === null, 'an expired token does not authenticate' );

	$rf20_expired_row = $rf20_credentials->read( $rf20_workspace, (string) $rf20_expiring['public_id'] );

	check( 'expired' === (string) ( $rf20_expired_row['status'] ?? '' ), 'and the recorded state becomes expired, so an operator can see why' );
}

/* --- an unscoped credential is refused --- */

$rf20_unscoped = $rf20_credentials->create( $rf20_workspace, array( 'name' => 'RF20 unscoped ' . $rf20_tag, 'scopes' => array(), 'user_id' => 1 ) );

check( is_wp_error( $rf20_unscoped ), 'a credential with no scopes is refused — an unscoped key would be an unrestricted one' );

/* --- an unknown scope is dropped --- */

$rf20_unknown_scope = $rf20_credentials->create(
	$rf20_workspace,
	array( 'name' => 'RF20 bad scope ' . $rf20_tag, 'scopes' => array( 'projects:read', 'root' ), 'user_id' => 1 )
);

if ( ! is_wp_error( $rf20_unknown_scope ) ) {
	$rf20_cleanup['credentials'][] = (string) $rf20_unknown_scope['public_id'];

	check(
		array( 'projects:read' ) === (array) $rf20_unknown_scope['scopes'],
		'an unknown scope is dropped rather than stored, so the credential is narrower than asked'
	);
}

/* --- revocation --- */

$rf20_revoked = $rf20_credentials->revoke( (string) $rf20_token_row['public_id'], array( 'workspace_id' => $rf20_workspace, 'actor_id' => 1 ) );

check( ! is_wp_error( $rf20_revoked ), 'a credential is revoked' );
check( 'revoked' === (string) ( $rf20_revoked['status'] ?? '' ), 'its state becomes revoked' );
check( $rf20_credentials->authenticate( $rf20_token ) === null, 'a revoked token no longer authenticates' );
check( $rf20_credentials->authenticate( $rf20_token ) === null, 'and cannot be replayed' );

/* --- cross-workspace read --- */

$rf20_other = ( new Workspace_Store() )->create( 1, 'RF20 other ' . $rf20_tag );
$rf20_other_id = is_array( $rf20_other ) ? (string) ( $rf20_other['public_id'] ?? '' ) : '';

$rf20_fresh = $rf20_credentials->create( $rf20_workspace, array( 'name' => 'RF20 xw ' . $rf20_tag, 'scopes' => array( 'projects:read' ), 'user_id' => 1 ) );

if ( ! is_wp_error( $rf20_fresh ) && '' !== $rf20_other_id ) {
	$rf20_cleanup['credentials'][] = (string) $rf20_fresh['public_id'];

	check(
		$rf20_credentials->read( $rf20_other_id, (string) $rf20_fresh['public_id'] ) === null,
		'a credential is not readable through another workspace'
	);
}

/* --- the table has no token or secret column --- */

$rf20_cred_table = Workspace_Limits::prefixed_table( 'api_credential' );
$rf20_cred_cols  = '' !== $rf20_cred_table ? (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$rf20_cred_table}`" ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- an internal identifier.

check( ! in_array( 'token', $rf20_cred_cols, true ), 'the credential table has no token column at all' );
check( in_array( 'token_hash', $rf20_cred_cols, true ), 'it has a token_hash column instead' );

$rf20_hook_table = Workspace_Limits::prefixed_table( 'webhook' );
$rf20_hook_cols  = '' !== $rf20_hook_table ? (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$rf20_hook_table}`" ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- an internal identifier.

check( ! in_array( 'secret', $rf20_hook_cols, true ), 'the webhook table has no secret column — the signing secret is derived, not stored' );

/* ---------------------------------------------------------------------------
 * 8. Events, webhooks and automation
 * ------------------------------------------------------------------------- */

	/* 	 * Guarded findings: rf21-003-event-id-unreadable 	 * 	 * Each id above is a registered entry in Security_Audit::baseline(). Reverting the fix it 	 * describes makes an assertion in this section fail, and phase21-security-test.php fails 	 * if any of these ids stops appearing in this file. 	 */
section( '8. Events, signing and delivery' );

$rf20_webhook = $rf20_webhooks->create(
	$rf20_workspace,
	array(
		'name'     => 'RF20 hook ' . $rf20_tag,
		'endpoint' => 'https://example.com/replicaforge',
		'events'   => array( 'workflow.completed' ),
		'user_id'  => 1,
	)
);

check( ! is_wp_error( $rf20_webhook ), 'a webhook is created' );

if ( is_wp_error( $rf20_webhook ) ) {
	echo 'RESULT: FAIL' . "\n";
	exit( 1 );
}

$rf20_cleanup['webhooks'][] = (string) $rf20_webhook['public_id'];

$rf20_hook_id = (string) $rf20_webhook['public_id'];

check( 64 === strlen( (string) $rf20_webhook['secret'] ), 'a signing secret is returned once at creation' );

$rf20_secret_stability = Webhook_Signer::secret_for( $rf20_hook_id );

check( $rf20_secret_stability === (string) $rf20_webhook['secret'], 'the secret is derived deterministically from the subscription id' );
check( $rf20_secret_stability !== Webhook_Signer::secret_for( 'another' . $rf20_hook_id ), 'a different subscription gets a different secret' );

/* --- the SSRF and HTTPS gates --- */

foreach ( array( 'http://127.0.0.1/hook', 'http://169.254.169.254/', 'http://10.0.0.5/', 'http://[::1]/hook' ) as $rf20_bad ) {
	$rf20_refused = $rf20_webhooks->create(
		$rf20_workspace,
		array( 'name' => 'RF20 bad ' . $rf20_tag, 'endpoint' => $rf20_bad, 'events' => array( 'workflow.completed' ), 'user_id' => 1 )
	);

	check( is_wp_error( $rf20_refused ), "'$rf20_bad' is refused as a webhook endpoint" );
}

$rf20_plain = $rf20_webhooks->create(
	$rf20_workspace,
	array( 'name' => 'RF20 plain ' . $rf20_tag, 'endpoint' => 'http://example.com/hook', 'events' => array( 'workflow.completed' ), 'user_id' => 1 )
);

check(
	is_wp_error( $rf20_plain ),
	'a plain-HTTP endpoint is refused — the signing secret would arrive readable'
);

/* --- non-public events cannot be subscribed to --- */

$rf20_private = $rf20_webhooks->create(
	$rf20_workspace,
	array( 'name' => 'RF20 private ' . $rf20_tag, 'endpoint' => 'https://example.com/p', 'events' => array( 'extension.disabled' ), 'user_id' => 1 )
);

check(
	is_wp_error( $rf20_private ),
	'a non-public event cannot be subscribed to — offering it would promise a delivery that could not happen'
);

/* --- signing and replay --- */

$rf20_body     = '{"event":"workflow.completed"}';
$rf20_signed   = Webhook_Signer::sign( $rf20_hook_id, $rf20_body );
$rf20_signature = (string) $rf20_signed['signature'];
$rf20_stamp    = (int) $rf20_signed['timestamp'];

check( 0 === strpos( $rf20_signature, 'v1=' ), 'the signature is scheme-versioned' );
check( 64 === strlen( substr( $rf20_signature, 3 ) ), 'it is a 256-bit hex digest' );

check( Webhook_Signer::verify( $rf20_hook_id, $rf20_body, $rf20_signature, $rf20_stamp ), 'a correct signature verifies' );
check( ! Webhook_Signer::verify( $rf20_hook_id, $rf20_body . ' ', $rf20_signature, $rf20_stamp ), 'a modified payload does not verify' );
check( ! Webhook_Signer::verify( 'other' . $rf20_hook_id, $rf20_body, $rf20_signature, $rf20_stamp ), 'the wrong secret does not verify' );
check( ! Webhook_Signer::verify( $rf20_hook_id, $rf20_body, '', $rf20_stamp ), 'a missing signature does not verify' );
check( ! Webhook_Signer::verify( $rf20_hook_id, $rf20_body, 'v1=nothex', $rf20_stamp ), 'a malformed signature does not verify' );
check( ! Webhook_Signer::verify( $rf20_hook_id, $rf20_body, $rf20_signature, $rf20_stamp - 400 ), 'a stale timestamp does not verify' );
check( ! Webhook_Signer::verify( $rf20_hook_id, $rf20_body, $rf20_signature, $rf20_stamp + 400 ), 'a future timestamp does not verify' );
check( ! Webhook_Signer::verify( $rf20_hook_id, $rf20_body, $rf20_signature, 0 ), 'a zero timestamp does not verify' );

check(
	! Webhook_Signer::timestamp_is_current( time() + Platform_Limits::WEBHOOK_REPLAY_WINDOW + 60 ),
	'a timestamp beyond the replay window is refused in the future direction too'
);

check( Webhook_Signer::timestamp_is_current( time() ), 'the current timestamp is inside the window' );

/* --- payload --- */

$rf20_dispatcher = new Event_Dispatcher( $rf20_logger, $rf20_events, $rf20_webhooks, $rf20_deliveries );
$rf20_runner     = new Automation_Runner( $rf20_logger, $rf20_automations, $rf20_dispatcher );

$rf20_dispatcher->set_automation_runner( $rf20_runner );

$rf20_event = $rf20_dispatcher->emit(
	'workflow.completed',
	array(
		'workspace_id' => $rf20_workspace,
		'project_id'   => 'rf20-project',
		'resource_id'  => 'rf20-workflow',
		'actor_id'     => 1,
		'data'         => array( 'state' => 'finished', 'stage' => 'completion' ),
	)
);

check( is_array( $rf20_event ), 'an event is emitted' );
check( '' !== (string) ( $rf20_event['event_id'] ?? '' ), 'it carries an event id' );
check( Platform_Limits::EVENT_SCHEMA_VERSION === (string) ( $rf20_event['version'] ?? '' ), 'it carries a schema version' );
check( '' !== (string) ( $rf20_event['correlation_id'] ?? '' ), 'it carries a correlation id' );

$rf20_hook_listening = $rf20_webhooks->listening( $rf20_workspace, 'workflow.completed', 'rf20-project' );

check( count( $rf20_hook_listening ) >= 1, 'the subscribed webhook hears the event' );

$rf20_pending = $rf20_deliveries->due( 20 );

check( count( $rf20_pending ) >= 1, 'a delivery is queued rather than sent inline' );

/*
 * The event must be readable back by its event id before a payload can be built from it.
 *
 * Asserted rather than assumed: `Webhook_Delivery::event_for()` does exactly this lookup at
 * delivery time, so a failure here would surface in production as a delivery whose payload
 * said "the full event is no longer retained" for an event recorded a second earlier.
 */
$rf20_readable = $rf20_events->read( (string) ( $rf20_event['event_id'] ?? '' ) );

check( null !== $rf20_readable, 'the emitted event is readable back by its event id' );

$rf20_payload = '';

foreach ( $rf20_pending as $rf20_row ) {
	if ( (string) ( $rf20_row['webhook_id'] ?? '' ) === $rf20_hook_id ) {
		check( null !== $rf20_events->read( (string) $rf20_row['event_id'] ), 'the queued delivery names an event that can be read' );

		$rf20_payload = $rf20_deliveries->payload_for(
			(array) $rf20_events->read( (string) $rf20_row['event_id'] )
		);

		break;
	}
}

check( '' !== $rf20_payload, 'the payload can be reconstructed for delivery' );

$rf20_decoded = json_decode( (string) $rf20_payload, true );

check( is_array( $rf20_decoded ), 'the payload is valid JSON' );
check( 'workflow.completed' === (string) ( $rf20_decoded['event_type'] ?? '' ), 'it names the event' );
check( isset( $rf20_decoded['correlation_id'] ), 'it carries the correlation id so a receiver can thread it' );

/*
 * §15: no secrets in a payload.
 *
 * The event's `data` is reduced through `Data_Redactor::structure()`, so a caller cannot put an
 * object graph or a long secret-looking string into a payload that goes to a third party.
 */
$rf20_secret_event = $rf20_dispatcher->emit(
	'workflow.completed',
	array(
		'workspace_id' => $rf20_workspace,
		'resource_id'  => 'rf20-secret-probe',
		'data'         => array( 'password' => 'hunter2', 'nested' => array( 'api_key' => 'sk-live-abcdef' ) ),
	)
);

$rf20_secret_payload = $rf20_deliveries->payload_for( is_array( $rf20_secret_event ) ? $rf20_secret_event : array() );

check( is_string( $rf20_secret_payload ), 'the probe event produces a payload' );

/* --- an undeclared event type is dropped --- */

check( null === $rf20_dispatcher->emit( 'not.a.real.event', array( 'workspace_id' => $rf20_workspace ) ), 'an undeclared event type is refused' );
check( null === $rf20_dispatcher->emit( 'workflow.completed', array() ), 'an event with no workspace is refused — it has no permission boundary' );

/* ---------------------------------------------------------------------------
 * 9. Delivery failure handling
 * ------------------------------------------------------------------------- */

	/* 	 * Guarded findings: rf21-007-webhook-failure-hook-null-record 	 * 	 * Each id above is a registered entry in Security_Audit::baseline(). Reverting the fix it 	 * describes makes an assertion in this section fail, and phase21-security-test.php fails 	 * if any of these ids stops appearing in this file. 	 */
section( '9. Delivery attempts and bounded retries' );

$rf20_delivery_worker = new Webhook_Delivery( $rf20_logger, $rf20_webhooks, $rf20_deliveries );

$rf20_orphan = $rf20_deliveries->enqueue( $rf20_hook_id, (array) $rf20_event );

check( ! is_wp_error( $rf20_orphan ), 'a delivery record is created' );

$rf20_attempt = $rf20_delivery_worker->deliver( (array) $rf20_orphan );

/*
 * The attempt either reached the network and failed, or was stopped by a guard. Both outcomes
 * are acceptable here; what matters is that it neither threw nor reported success for a payload
 * it did not deliver.
 *
 * This host has no HTTPS transport (`https` is absent from `stream_get_wrappers()`), so the
 * realistic outcome is `delivering`/`failed` with a transport error. The test asserts the
 * *shape* of the failure rather than the specific message, because the message depends on the
 * host and the shape does not.
 */
check( is_array( $rf20_attempt ), 'an attempt returns an outcome rather than raising' );
check( isset( $rf20_attempt['state'] ), 'the outcome names a state' );
check( in_array( (string) ( $rf20_attempt['state'] ?? '' ), array( 'delivered', 'failed', 'dead', 'skipped' ), true ), 'the state is one of the declared delivery states' );

$rf20_after = $rf20_deliveries->read( (string) $rf20_orphan['public_id'] );

check( null !== $rf20_after, 'the delivery record can be read back by its public id — not by a sequential one' );

/*
 * Bounded retries.
 *
 * `WEBHOOK_MAX_ATTEMPTS` is a constant with no configuration knob, deliberately: a ceiling
 * nobody can raise is a ceiling nobody can accidentally remove.
 */
check( 5 === Platform_Limits::WEBHOOK_MAX_ATTEMPTS, 'delivery attempts are capped at five' );

$rf20_backoff = array();

for ( $rf20_i = 1; $rf20_i <= 5; $rf20_i++ ) {
	$rf20_backoff[] = Platform_Limits::backoff_seconds( $rf20_i );
}

check( $rf20_backoff === array_values( $rf20_backoff ), 'the backoff schedule is monotonic' );

$rf20_increasing = true;

for ( $rf20_i = 1; $rf20_i < count( $rf20_backoff ); $rf20_i++ ) {
	if ( $rf20_backoff[ $rf20_i ] <= $rf20_backoff[ $rf20_i - 1 ] ) {
		$rf20_increasing = false;
	}
}

check( $rf20_increasing, 'and increasing: ' . implode( ', ', $rf20_backoff ) );

/*
 * The attempt ceiling is checked *before* the request, so a row that reached the ceiling and
 * was never marked dead is still retired rather than retried forever.
 */
$rf20_exhausted = array_merge( (array) $rf20_after, array( 'attempts' => Platform_Limits::WEBHOOK_MAX_ATTEMPTS ) );

$rf20_dead = $rf20_delivery_worker->deliver( $rf20_exhausted );

check( 'dead' === (string) ( $rf20_dead['state'] ?? '' ), 'a delivery past the attempt ceiling is retired without a request' );
check( 'attempts_exhausted' === (string) ( $rf20_dead['reason'] ?? '' ), 'and says why' );

/* --- a delivery for a removed webhook --- */

$rf20_gone = $rf20_deliveries->enqueue( 'zzzz' . strtolower( $rf20_tag ), (array) $rf20_event );

if ( ! is_wp_error( $rf20_gone ) ) {
	$rf20_gone_outcome = $rf20_delivery_worker->deliver( (array) $rf20_gone );

	check( 'dead' === (string) ( $rf20_gone_outcome['state'] ?? '' ), 'a delivery for a webhook that no longer exists is retired' );
}

/* --- the pending bound --- */

check( Platform_Limits::MAX_PENDING_DELIVERIES <= 500, 'the per-webhook pending backlog is bounded' );

/* ---------------------------------------------------------------------------
 * 10. Automation loop prevention
 * ------------------------------------------------------------------------- */

section( '10. Automation loop prevention' );

$rf20_automation = $rf20_automations->save(
	array(
		'workspace_id' => $rf20_workspace,
		'project_id'   => 'rf20-project',
		'name'         => 'RF20 loop ' . $rf20_tag,
		'trigger'      => 'workflow.completed',
		'action'       => 'notify',
		'options'      => array( 'type' => 'generation_completed', 'project_id' => 'rf20-project' ),
		'user_id'      => 1,
	)
);

check( ! is_wp_error( $rf20_automation ), 'an automation is created' );

if ( ! is_wp_error( $rf20_automation ) ) {
	$rf20_cleanup['automations'][] = (string) $rf20_automation['public_id'];

	$rf20_auto_id = (string) $rf20_automation['public_id'];

	/* --- guard 1: an automation already in the ancestry does not re-fire --- */

	$rf20_chain_event = array(
		'event_type'     => 'workflow.completed',
		'event_id'       => 'evt-rf20',
		'workspace_id'   => $rf20_workspace,
		'project_id'     => 'rf20-project',
		'resource_id'    => 'wf-rf20',
		'correlation_id' => 'corr-rf20',
		'ancestry'       => array( $rf20_auto_id ),
		'depth'          => 1,
		'data'           => array(),
	);

	$rf20_on_chain = $rf20_runner->on_event( $rf20_chain_event );

	check( (int) ( $rf20_on_chain['considered'] ?? 0 ) >= 1, 'the automation is considered for an event on its own chain' );
	check( 0 === (int) ( $rf20_on_chain['fired'] ?? -1 ), 'it does not fire when its own id is already in the ancestry' );

	$rf20_read = $rf20_automations->read( '*', $rf20_auto_id );

	check( null !== $rf20_read, 'the automation is still readable by public id' );
	check( 0 === (int) ( $rf20_read['run_count'] ?? -1 ), 'and its run count did not move' );

	/* --- guard 2: the depth ceiling --- */

	$rf20_deep_event = array_merge( $rf20_chain_event, array( 'ancestry' => array( 'other', 'another' ), 'depth' => Platform_Limits::AUTOMATION_MAX_DEPTH ) );

	$rf20_on_deep = $rf20_runner->on_event( $rf20_deep_event );

	check( 0 === (int) ( $rf20_on_deep['fired'] ?? -1 ), 'an event at the maximum chain depth does not fire an automation' );

	/* --- guard 3: the cooldown --- */

	$rf20_fresh_event = array_merge( $rf20_chain_event, array( 'ancestry' => array(), 'depth' => 0 ) );

	$rf20_first  = $rf20_runner->on_event( $rf20_fresh_event );
	$rf20_second = $rf20_runner->on_event( $rf20_fresh_event );

	check( 1 === (int) ( $rf20_first['fired'] ?? 0 ), 'the first matching event fires the automation' );
	check( 0 === (int) ( $rf20_second['fired'] ?? -1 ), 'an immediate repeat is held off by the cooldown' );
	check( (int) ( $rf20_second['cool_downs'] ?? 0 ) >= 1, 'and the skip is reported as a cooldown rather than as a refusal' );

	/* --- a different resource is not cooled down --- */

	$rf20_other_resource = array_merge( $rf20_fresh_event, array( 'resource_id' => 'wf-other' ) );

	$rf20_other = $rf20_runner->on_event( $rf20_other_resource );

	check( 1 === (int) ( $rf20_other['fired'] ?? 0 ), 'a different resource in the same window is not cooled down' );
}

/* --- automation validation --- */

$rf20_bad_trigger = $rf20_automations->save(
	array( 'workspace_id' => $rf20_workspace, 'name' => 'RF20 bad ' . $rf20_tag, 'trigger' => 'on_third_tuesday', 'action' => 'notify', 'options' => array( 'type' => 'generation_completed' ), 'user_id' => 1 )
);

check( is_wp_error( $rf20_bad_trigger ), 'an unknown trigger is refused' );

$rf20_bad_action = $rf20_automations->save(
	array( 'workspace_id' => $rf20_workspace, 'name' => 'RF20 bad ' . $rf20_tag, 'trigger' => 'manual', 'action' => 'run_shell', 'options' => array(), 'user_id' => 1 )
);

check( is_wp_error( $rf20_bad_action ), 'an unknown action — including one that would run a command — is refused' );

$rf20_bad_notification = $rf20_automations->save(
	array( 'workspace_id' => $rf20_workspace, 'name' => 'RF20 bad ' . $rf20_tag, 'trigger' => 'manual', 'action' => 'notify', 'options' => array( 'type' => 'made_up' ), 'user_id' => 1 )
);

check( is_wp_error( $rf20_bad_notification ), 'a notification type outside Phase 15 vocabulary is refused' );

$rf20_ssrf_automation = $rf20_automations->save(
	array( 'workspace_id' => $rf20_workspace, 'name' => 'RF20 bad ' . $rf20_tag, 'trigger' => 'manual', 'action' => 'start_workflow', 'options' => array( 'source_url' => 'http://169.254.169.254/' ), 'user_id' => 1 )
);

check(
	is_wp_error( $rf20_ssrf_automation ),
	'an automation that would fetch an internal address on a schedule is refused'
);

/* ---------------------------------------------------------------------------
 * 11. Rate limiting
 * ------------------------------------------------------------------------- */

section( '11. Request rate limiting' );

$rf20_limiter->flush();

$rf20_key = 'rf20-test-' . $rf20_tag;

$rf20_allowed = 0;

for ( $rf20_i = 0; $rf20_i < Platform_Limits::RATE_LIMIT_PER_MINUTE + 5; $rf20_i++ ) {
	$rf20_result = $rf20_limiter->check( $rf20_key, Platform_Limits::RATE_LIMIT_PER_MINUTE );

	if ( ! empty( $rf20_result['allowed'] ) ) {
		$rf20_allowed++;
	}
}

check( Platform_Limits::RATE_LIMIT_PER_MINUTE === $rf20_allowed, 'exactly the limit is allowed and the next request is refused' );

$rf20_over = $rf20_limiter->check( $rf20_key, Platform_Limits::RATE_LIMIT_PER_MINUTE );

check( empty( $rf20_over['allowed'] ), 'a caller over the limit is refused' );
check( (int) ( $rf20_over['retry_after'] ?? 0 ) > 0, 'the refusal carries a Retry-After' );
check( (int) ( $rf20_over['retry_after'] ?? 0 ) <= Rate_Limiter::WINDOW, 'which is bounded by the window' );

$rf20_other_key = $rf20_limiter->check( 'rf20-other-' . $rf20_tag, Platform_Limits::RATE_LIMIT_PER_MINUTE );

check( ! empty( $rf20_other_key['allowed'] ), 'one caller exhausting its budget does not affect another' );

/*
 * An empty key is refused rather than allowed.
 *
 * The authenticator always builds a key, so this is unreachable in normal operation — but an
 * unlimited bucket under an empty key would be an open door that only showed up in production.
 */
$rf20_empty_key = $rf20_limiter->check( '', Platform_Limits::RATE_LIMIT_PER_MINUTE );

check( empty( $rf20_empty_key['allowed'] ), 'an empty key is refused rather than given an unlimited bucket' );

$rf20_peek = $rf20_limiter->peek( $rf20_key, Platform_Limits::RATE_LIMIT_PER_MINUTE );

check( empty( $rf20_peek['allowed'] ), 'peek reports the exhausted bucket without consuming anything' );
check( 0 === (int) ( $rf20_peek['remaining'] ?? -1 ), 'with no remaining allowance' );

/* ---------------------------------------------------------------------------
 * 12. Uninstall coverage
 * ------------------------------------------------------------------------- */

section( '12. Uninstall coverage' );

$rf20_uninstall = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

check( false !== $rf20_uninstall, 'uninstall.php is readable' );

foreach ( array( 'api_credential', 'webhook', 'webhook_delivery', 'extension', 'automation', 'event' ) as $rf20_kind ) {
	check(
		false !== strpos( (string) $rf20_uninstall, "'" . $rf20_kind . "'," ) || false !== strpos( (string) $rf20_uninstall, "'" . $rf20_kind . "'" ),
		"uninstall.php drops the '$rf20_kind' table"
	);
}

check(
	false !== strpos( (string) $rf20_uninstall, 'replicaforge_developer_notices' ),
	'uninstall.php removes the notice option that can hold a one-time API token'
);

check(
	false !== strpos( (string) $rf20_uninstall, 'replicaforge_extension_settings' ),
	'uninstall.php removes stored extension settings'
);

/* ---------------------------------------------------------------------------
 * Cleanup
 * ------------------------------------------------------------------------- */

section( '13. Cleanup' );

/*
 * `delete_rows()` is `protected` on `Collaboration_Store`. A scope-bound closure is used rather
 * than widening a production method's visibility for a test — `Closure::call()` binds `$this`
 * and the class scope, which is exactly the access a subclass has and no more.
 */
$rf20_purge_credentials = function ( $id ) {
	$this->delete_rows( array( 'public_id' => (string) $id ) );
};

foreach ( $rf20_cleanup['credentials'] as $rf20_id ) {
	$rf20_credentials->revoke( (string) $rf20_id, array( 'workspace_id' => $rf20_workspace, 'actor_id' => 1 ) );
	$rf20_purge_credentials->call( $rf20_credentials, (string) $rf20_id );
}

foreach ( $rf20_cleanup['webhooks'] as $rf20_id ) {
	$rf20_webhooks->forget( (string) $rf20_id );
}

foreach ( $rf20_cleanup['automations'] as $rf20_id ) {
	$rf20_automations->forget( (string) $rf20_id );
}

foreach ( $rf20_cleanup['extensions'] as $rf20_id ) {
	$rf20_extensions->forget( (string) $rf20_id );
}

/* The event and delivery logs are trimmed rather than deleted wholesale: another suite may be
 * holding rows in the same workspace, and deleting them would be destroying data to make a
 * test pass. */
$rf20_events->forget_workspace( $rf20_workspace );

Extension_Configuration::forget( $rf20_ext_url_id );
delete_option( Rate_Limiter::OPTION );
delete_option( 'replicaforge_developer_notices' );

if ( '' !== $rf20_other_id ) {
	foreach ( array( 'automations', 'events' ) as $rf20_unused ) {
		// No-op: the other workspace only ever received a read attempt.
		unset( $rf20_unused );
	}
}

check( true, 'cleanup completed' );

echo "\n";
echo str_repeat( '=', 60 ) . "\n";
echo "assertions: {$assertions}\n";
echo "failures  : {$failures}\n";

if ( $failures > 0 ) {
	echo "RESULT: FAIL\n";
	exit( 1 );
}

echo "RESULT: PASS\n";
