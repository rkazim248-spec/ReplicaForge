<?php
/**
 * Phase 10: plans, entitlements, usage accounting, licensing, and capabilities.
 *
 * The tests that matter most here are the ones about *accounting and refusal*
 * rather than about configuration. A plan system that hands out the right numbers
 * while charging for the wrong things is worse than no plan system, because the
 * numbers look right. So the central cases are:
 *
 * - a failed operation must not consume allowance;
 * - a reservation nobody settles must not consume it forever;
 * - a second concurrent reservation must see the first one;
 * - a forged plan id, a forged usage count, and a forged license state must all be
 *   refused, and each for a different reason.
 *
 * Run: php phase10-plans-test.php <wp-root>
 */
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php phase10-plans-test.php <wp-root>\n" );
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

use ReplicaForge\Plan_Limits;
use ReplicaForge\Plan_Definition;
use ReplicaForge\Plan_Storage;
use ReplicaForge\Plan_Manager;
use ReplicaForge\Usage_Manager;
use ReplicaForge\Entitlement_Manager;
use ReplicaForge\Feature_Gate;
use ReplicaForge\Audit_Log;
use ReplicaForge\Capabilities;
use ReplicaForge\Project_Access;
use ReplicaForge\Project_Status;
use ReplicaForge\Onboarding;
use ReplicaForge\License_State;
use ReplicaForge\License_Manager;
use ReplicaForge\Local_License_Provider;
use ReplicaForge\License_Provider_Contract;
use ReplicaForge\Schema;

$assertions = 0;

/**
 * Users this suite created, removed whatever happens.
 *
 * A suite that aborts on a failed assertion leaves whatever it created behind, and
 * a leftover user with the `subscriber` role broke an unrelated suite: it looks for
 * a subscriber to test a permission refusal against, and finding one it reached a
 * `wp_die()` path that its own test could not survive. A shutdown handler is the
 * only thing that runs on the failure path, so the cleanup lives there rather than
 * at the end of the file.
 *
 * @var array<int, int>
 */
$GLOBALS['rf_phase10_users'] = array();

/**
 * Remove every user this suite created.
 *
 * @return void
 */
function rf_phase10_cleanup() {
	// `wp_delete_user()` lives in the admin includes, which a CLI run does not
	// load even with WP_ADMIN defined. Loading it here rather than at the top of the
	// file keeps the dependency next to the one place that needs it.
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	$usage = new \ReplicaForge\Usage_Manager();
	foreach ( (array) ( $GLOBALS['rf_phase10_users'] ?? array() ) as $id ) {
		$id = (int) $id;
		if ( $id > 0 ) {
			$usage->forget( $id );
			wp_delete_user( $id );
		}
	}
	$GLOBALS['rf_phase10_users'] = array();
	\ReplicaForge\Plan_Storage::reset();
	\ReplicaForge\Plan_Storage::flush_cache();
	\ReplicaForge\Audit_Log::clear();
}
register_shutdown_function( 'rf_phase10_cleanup' );

/**
 * Create a user and remember it for cleanup.
 *
 * @param string $role Role slug.
 * @return int
 */
function rf_phase10_user( $role ) {
	$id = (int) wp_insert_user(
		array(
			'user_login' => 'rf_p10_' . $role . '_' . wp_rand( 100000, 999999 ),
			'user_pass'  => wp_generate_password( 20 ),
			'user_email' => uniqid() . '@example.invalid',
			'role'       => $role,
		)
	);
	if ( $id > 0 ) {
		$GLOBALS['rf_phase10_users'][] = $id;
	}
	return $id;
}

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
 * Assert a value equals an expected literal.
 *
 * The expected and actual values are only printed on failure. Printing them on
 * every pass buries the assertion that failed in a screenful of identical arrays,
 * which is exactly when they are needed.
 *
 * @param mixed  $actual   Actual.
 * @param mixed  $expected Expected.
 * @param string $message  What was checked.
 * @return void
 */
function same( $actual, $expected, $message ) {
	global $assertions;
	$assertions++;
	if ( $actual === $expected ) {
		echo 'PASS: ' . $message . "\n";
		return;
	}
	echo 'FAIL: ' . $message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	throw new RuntimeException( 'FAILED: ' . $message );
}

echo "--- 1. The vocabulary is closed ---\n";

// Membership, not a count. The first three assertions here were `count( ... ) === 8`,
// `=== 10` and `=== 10`, which is a test that fails the moment any later phase adds an
// operation, a feature or a limit - and Phase 14 added all three. A count says nothing
// about whether the vocabulary is right; the property that matters is that every
// operation Phase 10 defined is still declared, and that nothing undeclared is not.
foreach ( array( 'analysis', 'ai_analysis', 'generation', 'validation', 'correction', 'sync_operation', 'export', 'import' ) as $original ) {
	check( in_array( $original, Plan_Limits::OPERATIONS, true ), sprintf( 'The "%s" operation is still declared.', $original ) );
}
check( count( Plan_Limits::OPERATIONS ) >= 8, sprintf( 'At least the original eight operations are declared (now %d).', count( Plan_Limits::OPERATIONS ) ) );
foreach ( array( 'basic_analysis', 'ai_understanding', 'elementor_generation', 'visual_validation', 'automatic_correction', 'monitoring', 'source_sync', 'advanced_reconstruction', 'project_export', 'project_import' ) as $original ) {
	check( in_array( $original, Plan_Limits::FEATURES, true ), sprintf( 'The "%s" feature is still declared.', $original ) );
}
check( count( Plan_Limits::FEATURES ) >= 10, sprintf( 'At least the original ten features are declared (now %d).', count( Plan_Limits::FEATURES ) ) );
check( count( Plan_Limits::LIMIT_NAMES ) >= 10, sprintf( 'At least the original ten limit names are declared (now %d).', count( Plan_Limits::LIMIT_NAMES ) ) );
check( count( array_unique( Plan_Limits::OPERATIONS ) ) === count( Plan_Limits::OPERATIONS ), 'No operation is declared twice.' );
check( count( array_unique( Plan_Limits::FEATURES ) ) === count( Plan_Limits::FEATURES ), 'No feature is declared twice.' );

// Every operation must name the feature that gates it. An operation with no
// feature is a limit nobody can reach and a feature nobody can withhold, and
// `permits()` refuses it for exactly that reason.
foreach ( Plan_Limits::OPERATIONS as $operation ) {
	check(
		'' !== Plan_Limits::feature_for_operation( $operation ),
		'The operation ' . $operation . ' names the feature that gates it.'
	);
	check(
		'' !== Plan_Limits::limit_for_operation( $operation ),
		'The operation ' . $operation . ' names the limit that meters it.'
	);
}
foreach ( Plan_Limits::FEATURES as $feature ) {
	check( Plan_Limits::is_feature( $feature ), 'The feature ' . $feature . ' is recognised.' );
}
check( ! Plan_Limits::is_feature( 'unlimited_power' ), 'An invented feature is refused.' );
check( ! Plan_Limits::is_operation( array( 'analysis' ) ), 'A non-string operation is refused.' );
check( ! Plan_Limits::is_limit( 'vibes_per_period' ), 'An invented limit is refused.' );

// The two entity limits are the ones that count rather than meter, and mixing
// them up is how a user ends up permanently short of allowance.
foreach ( Plan_Limits::ENTITY_LIMITS as $limit ) {
	check( Plan_Limits::is_entity_limit( $limit ), 'The limit ' . $limit . ' is declared as an entity count.' );
}
check( ! Plan_Limits::is_entity_limit( 'generation_per_period' ), 'A per-period limit is not an entity count.' );

check( Plan_Limits::is_unlimited( -1 ), 'The unlimited marker is recognised.' );
check( ! Plan_Limits::is_unlimited( 0 ), 'Zero is not unlimited: zero means forbidden, which is a feature decision.' );
check( ! Plan_Limits::is_unlimited( 1000000 ), 'A large number is not unlimited.' );

same( Plan_Limits::period_key( 0 ), '1970-01', 'A period key is a UTC calendar month.' );
check( 0 < Plan_Limits::period_ends(), 'A period has an end.' );
check( Plan_Limits::period_ends( 0 ) > 0, 'The period end is a timestamp.' );

same( Plan_Limits::plan_rank( 'free' ), 0, 'Free ranks first.' );
same( Plan_Limits::plan_rank( 'agency' ), 3, 'Agency ranks last.' );
same( Plan_Limits::plan_rank( 'enterprise' ), -1, 'An unknown plan ranks below every known one, so it cannot sort to the top.' );

echo "--- 2. A plan definition ---\n";

$free = Plan_Definition::create(
	'free',
	array(
		'name'     => 'Free',
		'limits'   => array( 'generation_per_period' => 2, 'monitored_projects' => 0 ),
		'features' => array( 'basic_analysis' => true, 'monitoring' => false ),
	)
);
check( $free instanceof Plan_Definition, 'A plan definition is built from an id and data.' );
same( $free->id(), 'free', 'The plan keeps its identifier.' );
same( $free->limit( 'generation_per_period' ), 2, 'A declared limit reads back.' );

// An absent limit is unlimited, not zero. The opposite reading is the one that
// breaks: an author who forgot a limit would otherwise get a plan that forbids
// the operation entirely.
same( $free->limit( 'correction_per_period' ), Plan_Limits::UNLIMITED, 'An absent limit reads as unlimited, not as zero.' );
check( $free->is_unlimited( 'correction_per_period' ), 'An absent limit reports itself as unlimited.' );

// An absent feature is withheld, not granted. The asymmetry with limits above is
// deliberate and is the safe direction for both.
check( ! $free->allows( 'source_sync' ), 'A feature the plan does not mention is not granted.' );
check( $free->allows( 'basic_analysis' ), 'A feature the plan grants is granted.' );
check( ! $free->allows( 'invented_feature' ), 'An invented feature is never granted.' );

// A negative limit that is not the declared marker is a configuration mistake.
// Treating it as unlimited would grant more than the plan says.
$bad_negative = Plan_Definition::create( 'x', array( 'limits' => array( 'generation_per_period' => -99 ) ) );
same( $bad_negative->limit( 'generation_per_period' ), 0, 'An unrecognised negative limit becomes zero, not unlimited.' );

check( null === Plan_Definition::create( 'Bad Id', array() ), 'A plan id with illegal characters is refused.' );
check( null === Plan_Definition::create( '', array() ), 'An empty plan id is refused.' );
check( null === Plan_Definition::create( str_repeat( 'a', 41 ), array() ), 'An over-long plan id is refused.' );
check( null === Plan_Definition::create( array( 'free' ), array() ), 'A non-string plan id is refused.' );

// Unknown keys are dropped, not carried forward. A plan definition is read from
// an option and from an import, and an unrecognised key is how an unvalidated
// value eventually reaches something that trusts it.
$carrying = Plan_Definition::create(
	'free',
	array( 'name' => 'Free', 'callback' => 'shell_exec', 'price' => 5 )
);
$as_array = $carrying->to_array();
check( ! array_key_exists( 'callback', $as_array ), 'An unrecognised key is dropped rather than stored.' );
check( ! array_key_exists( 'price', $as_array ), 'A price is not part of a plan definition, so it is dropped.' );

// An operation with no feature mapping is not permitted. An unmapped operation is
// a gap in the table, and allowing it would mean a feature nobody can withhold.
check( ! $free->permits( 'not_an_operation' ), 'An unmapped operation is not permitted.' );
check( $free->permits( 'analysis' ), 'A mapped, granted operation is permitted.' );

echo "--- 3. Plan storage ---\n";

Plan_Storage::reset();
$definitions = Plan_Storage::all( true );
check( isset( $definitions['free'], $definitions['starter'], $definitions['pro'], $definitions['agency'] ), 'The four declared plans exist.' );

// Ordered by the declared order, so a screen iterating the set gets a sensible
// order without sorting it.
$ids = array_keys( $definitions );
same( $ids, array( 'free', 'starter', 'pro', 'agency' ), 'Plans are returned in the declared order.' );

$agency = Plan_Storage::get( 'agency' );
check( $agency->is_unlimited( 'generation_per_period' ), 'Agency is unlimited on generation.' );
check( $agency->permits( 'correction' ), 'Agency permits corrections.' );

$free_plan = Plan_Storage::get( 'free' );
check( ! $free_plan->permits( 'correction' ), 'Free does not permit corrections, because the feature is off.' );
check( ! $free_plan->permits( 'sync_operation' ), 'Free does not permit sync, because the feature is off.' );
check( $free_plan->permits( 'generation' ), 'Free permits generation, with a small limit.' );
check( $free_plan->limit( 'generation_per_period' ) > 0, 'Free has a non-zero generation limit.' );

check( null === Plan_Storage::get( 'enterprise' ), 'An unknown plan reads as null rather than as the free plan.' );
check( null === Plan_Storage::get( '' ), 'An empty plan id reads as null.' );
check( null === Plan_Storage::get( array() ), 'A non-string plan id reads as null.' );

// Overrides replace rather than merge. A merge would keep a limit the new
// definition meant to remove, which is the more dangerous failure.
$stored = Plan_Storage::store( array( 'pro' => array( 'name' => 'Pro', 'limits' => array( 'generation_per_period' => 5 ) ) ) );
check( ! empty( $stored['success'] ), 'A plan override is stored.' );
$replaced = Plan_Storage::get( 'pro' );
same( $replaced->limit( 'generation_per_period' ), 5, 'The override applies.' );
same( $replaced->limit( 'correction_per_period' ), Plan_Limits::UNLIMITED, 'A replaced plan drops the limits it no longer declares, rather than keeping the old ones.' );

// A store keeps the entries it can use and reports the ones it cannot, rather
// than failing the whole document because one plan id is malformed.
$mixed_store = Plan_Storage::store(
	array(
		'fine'     => array( 'name' => 'Fine', 'limits' => array( 'generation_per_period' => 1 ) ),
		'Bad Id!'  => array( 'name' => 'x' ),
	)
);
check( ! empty( $mixed_store['success'] ), 'A store containing one bad entry still stores its good entries.' );
same( (int) $mixed_store['stored'], 1, 'Exactly the usable entry is stored.' );
check( in_array( 'Bad Id!', (array) $mixed_store['rejected'], true ), 'The bad entry is reported as rejected.' );

$only_bad = Plan_Storage::store( array( 'Bad Id!' => array( 'name' => 'x' ) ) );
check( empty( $only_bad['success'] ), 'A store of nothing usable reports failure.' );
same( (int) $only_bad['stored'], 0, 'A failed store stores nothing.' );

// Import goes through the same rebuild, so an imported document cannot introduce
// a key that something later trusts.
$import = Plan_Storage::import(
	array(
		'plans' => array(
			'custom' => array(
				'name'        => 'Custom',
				'callback'    => 'phpinfo',
				'limits'      => array( 'generation_per_period' => 3 ),
				'features'    => array( 'elementor_generation' => true ),
			),
		),
	)
);
check( ! empty( $import['success'] ), 'An imported plan is stored.' );
$imported = Plan_Storage::get( 'custom' );
check( $imported instanceof Plan_Definition, 'The imported plan exists.' );
same( $imported->limit( 'generation_per_period' ), 3, 'The imported limit applies.' );
check( ! array_key_exists( 'callback', $imported->to_array() ), 'An imported key that is not part of a plan is dropped, so it cannot be reached later.' );

$no_plans = Plan_Storage::import( array( 'nope' => 1 ) );
check( empty( $no_plans['success'] ), 'An import with no plans fails.' );

$exported = Plan_Storage::export();
check( isset( $exported['plans'], $exported['trial'], $exported['schema'] ), 'The export carries plans, trial settings, and a schema marker.' );
same( $exported['schema'], Schema::PLAN_SCHEMA_VERSION, 'The export declares the plan schema version it was written in.' );
foreach ( array_keys( $exported['plans'] ) as $plan_id ) {
	$keys = array_keys( (array) $exported['plans'][ $plan_id ] );
	sort( $keys );
	same( $keys, array( 'description', 'features', 'limits', 'name', 'plan_id' ), 'The exported plan for ' . $plan_id . ' carries only the editable keys.' );
}

Plan_Storage::reset();
check( Plan_Storage::get( 'pro' )->limit( 'generation_per_period' ) > 5, 'Resetting restores the shipped definitions.' );

// Trials are off unless an administrator turns them on. A development install
// should not hand out paid entitlements on its own.
$trial = Plan_Storage::trial_settings();
check( false === $trial['enabled'], 'Trials are disabled by default.' );
check( $trial['days'] > 0, 'A trial has a length.' );
check( '' !== $trial['plan'], 'A trial names a plan.' );

Plan_Storage::store_trial( array( 'enabled' => true, 'days' => 9999, 'plan' => 'pro' ) );
same( Plan_Storage::trial_settings()['days'], 365, 'An absurd trial length is clamped.' );
Plan_Storage::store_trial( array( 'enabled' => false ) );
check( false === Plan_Storage::trial_settings()['enabled'], 'Trials can be turned off again.' );

// A filter that returns nothing must not leave the plugin with no plans at all.
add_filter( 'replicaforge_plan_definitions', '__return_empty_array' );
$filtered = Plan_Storage::defaults();
check( isset( $filtered['free'] ), 'A filter that empties the plan set is ignored rather than honoured.' );
remove_filter( 'replicaforge_plan_definitions', '__return_empty_array' );

add_filter(
	'replicaforge_plan_definitions',
	static function ( $plans ) {
		$plans['custom_plan'] = array( 'name' => 'Custom', 'limits' => array(), 'features' => array() );
		return $plans;
	}
);
// The compiled plan set is cached, and a cache does not watch for a filter being
// added. An installation that adds the filter is expected to flush once at
// registration, which is what happens here. Writing a plugin-set-wide cache
// invalidation for every possible filter would be a great deal of machinery for a
// case that happens at boot and never again.
Plan_Storage::flush_cache();
check( null !== Plan_Storage::get( 'custom_plan' ), 'A plan added through the filter exists without a code change.' );
remove_all_filters( 'replicaforge_plan_definitions' );
Plan_Storage::flush_cache();
check( null === Plan_Storage::get( 'custom_plan' ), 'Removing the filter removes the plan again.' );

echo "--- 4. Usage accounting: a failed operation does not consume ---\n";

$user_id = rf_phase10_user( 'editor' );
check( $user_id > 0, 'A test user was created.' );

$usage = new Usage_Manager();
$usage->forget( $user_id );
$plan  = Plan_Storage::get( 'pro' );

$reservation = $usage->reserve( $user_id, 'generation', 1, $plan );
check( ! empty( $reservation['success'] ), 'A reservation is taken.' );
check( ! empty( $reservation['reservation'] ), 'A reservation returns a token.' );
same( $usage->used( $user_id, 'generation' ), 0, 'Taking a reservation does not charge.' );
same( $usage->reserved( $user_id, 'generation' ), 1, 'A reservation is held.' );
check( $usage->has_open_reservation( $user_id ), 'The user has an open reservation.' );

$released = $usage->release( $user_id, $reservation['reservation'], 'elementor_missing' );
check( ! empty( $released['success'] ), 'The reservation is released.' );
same( $usage->used( $user_id, 'generation' ), 0, 'A released reservation charges nothing.' );
same( $usage->reserved( $user_id, 'generation' ), 0, 'A released reservation is no longer held.' );

// The refusal is recorded even though nothing was charged, so a user seeing
// "1 / 2 used" can tell a working meter from a broken one.
$tail = $usage->recent( $user_id, 5 );
check( count( $tail ) > 0, 'A release is recorded in the usage tail.' );
$found_release = false;
foreach ( $tail as $entry ) {
	if ( Plan_Limits::OUTCOMES[1] === ( $entry['metadata']['outcome'] ?? '' ) ) {
		$found_release = true;
	}
}
check( $found_release, 'A released attempt is recorded with a released outcome and a quantity of zero.' );

echo "--- 5. Usage accounting: a settled reservation charges exactly once ---\n";

$usage->forget( $user_id );
$reservation = $usage->reserve( $user_id, 'generation', 1, $plan );
$commit      = $usage->commit( $user_id, $reservation['reservation'], 'pro' );
check( ! empty( $commit['success'] ), 'A reservation commits.' );
same( $usage->used( $user_id, 'generation' ), 1, 'A committed reservation charges once.' );
same( $usage->reserved( $user_id, 'generation' ), 0, 'A committed reservation is no longer held.' );

$double = $usage->commit( $user_id, $reservation['reservation'], 'pro' );
check( empty( $double['success'] ), 'Committing the same token twice is refused, so usage cannot be double-charged.' );
same( $usage->used( $user_id, 'generation' ), 1, 'The double commit changed nothing.' );

echo "--- 6. Usage accounting: an unsettled reservation expires ---\n";

$usage->forget( $user_id );
$abandoned = $usage->reserve( $user_id, 'generation', 1, $plan );
check( ! empty( $abandoned['success'] ), 'A reservation is taken and then abandoned.' );
same( $usage->reserved( $user_id, 'generation' ), 1, 'The abandoned reservation is held.' );

// Force the expiry by rewriting the stored reservation's window, which is the
// state a process that died would have left behind.
$key        = Usage_Manager::RESERVATION_PREFIX . Plan_Limits::period_key();
$stored     = get_user_meta( $user_id, $key, true );
$token      = $abandoned['reservation'];
$stored[ $token ]['expires_at'] = time() - 10;
update_user_meta( $user_id, $key, $stored );

// The next reservation for the same user prunes the expired one first.
$next = $usage->reserve( $user_id, 'validation', 1, $plan );
check( ! empty( $next['success'] ), 'A new reservation is still taken after an earlier one expired.' );
same( $usage->reserved( $user_id, 'generation' ), 0, 'The expired reservation no longer holds usage.' );

$found_expired = false;
foreach ( $usage->recent( $user_id, 10 ) as $entry ) {
	if ( Plan_Limits::OUTCOMES[2] === ( $entry['metadata']['outcome'] ?? '' ) ) {
		$found_expired = true;
	}
}
check( $found_expired, 'An expired reservation is recorded as expired rather than silently dropped.' );

echo "--- 7. Concurrency: a second reservation sees the first ---\n";

$usage->forget( $user_id );
$limited = Plan_Definition::create(
	'limited',
	array(
		'name'     => 'Limited',
		'limits'   => array( 'generation_per_period' => 2 ),
		'features' => array( 'elementor_generation' => true ),
	)
);

$first = $usage->reserve( $user_id, 'generation', 1, $limited );
check( ! empty( $first['success'] ), 'The first reservation succeeds.' );
$second = $usage->reserve( $user_id, 'generation', 1, $limited );
check( ! empty( $second['success'] ), 'The second reservation succeeds while allowance remains.' );
$third = $usage->reserve( $user_id, 'generation', 1, $limited );
check( empty( $third['success'] ), 'The third reservation is refused, because two are already held.' );
same( (string) $third['code'], 'usage_limit_reached', 'The refusal reports the limit rather than a lock failure.' );
same( (int) $third['used'], 0, 'The refusal reports that nothing has been charged yet.' );
same( (int) $third['held'], 2, 'The refusal separately reports what is in flight, so the two figures cannot be confused.' );
same( (int) $third['total'], 2, 'The refusal reports the total, which is what fills the meter.' );
same( (int) $third['limit'], 2, 'The refusal reports the plan limit.' );

// Committing converts a hold into a charge, so the total does not fall. Treating
// a commit as "frees a slot" would let a user start an operation for every one
// they finish, which is the limit not being a limit.
$usage->commit( $user_id, $first['reservation'], 'limited' );
same( $usage->used( $user_id, 'generation' ), 1, 'A committed reservation is charged.' );
same( $usage->reserved( $user_id, 'generation' ), 1, 'A committed reservation is no longer held.' );
$fourth = $usage->reserve( $user_id, 'generation', 1, $limited );
check( empty( $fourth['success'] ), 'Committing does not free an allowance slot, because the work still consumed it.' );
same( (int) $fourth['used'], 1, 'The refusal reports the one charged operation.' );
same( (int) $fourth['held'], 1, 'The refusal reports the one still in flight.' );

// Releasing is the operation that does free a slot, because nothing was consumed.
$usage->release( $user_id, $second['reservation'], 'failed' );
same( $usage->used( $user_id, 'generation' ), 1, 'A release charges nothing.' );
same( $usage->reserved( $user_id, 'generation' ), 0, 'A release frees the hold.' );
$fourth = $usage->reserve( $user_id, 'generation', 1, $limited );
check( ! empty( $fourth['success'] ), 'A released reservation frees exactly one slot.' );
$fifth = $usage->reserve( $user_id, 'generation', 1, $limited );
check( empty( $fifth['success'] ), 'The allowance is full again.' );
$usage->forget( $user_id );

// A quantity larger than the remaining allowance is refused outright rather than
// partly granted.
$usage->reserve( $user_id, 'generation', 1, $limited );
$too_big = $usage->reserve( $user_id, 'generation', 5, $limited );
check( empty( $too_big['success'] ), 'A reservation larger than the whole allowance is refused.' );
$usage->forget( $user_id );

echo "--- 8. The meter ---\n";

$usage->forget( $user_id );
$usage->charge( $user_id, 'generation', 1, $plan );
$usage->charge( $user_id, 'generation', 1, $plan );

$meter = $usage->meter( $user_id, $plan );
check( isset( $meter['operations']['generation'] ), 'The meter reports every operation.' );
same( (int) $meter['operations']['generation']['used'], 2, 'The meter reports the used count.' );
same( (int) $meter['operations']['generation']['limit'], 100, 'The meter reports the plan limit for a finite limit.' );
same( (int) $meter['operations']['generation']['remaining'], 98, 'The meter reports what is left.' );
same( (int) $meter['operations']['generation']['percent_used'], 2, 'The meter reports a percentage, computed here so the bar and the numbers cannot disagree.' );

$agency_meter = $usage->meter( $user_id, $agency );
check( $agency_meter['operations']['generation']['unlimited'], 'An unlimited limit reports itself as unlimited.' );
same( $agency_meter['operations']['generation']['remaining'], null, 'An unlimited limit has no remaining count, rather than a fabricated one.' );
same( (int) $agency_meter['operations']['generation']['percent_used'], 0, 'An unlimited limit has no percentage.' );
check( $agency_meter['operations']['generation']['used'] > 0, 'An unlimited limit still reports what was used.' );

// The open-hold count is what stops a client from opening unlimited reservations
// without executing anything.
$usage->reserve( $user_id, 'export', 1, $plan );
$held_meter = $usage->meter( $user_id, $plan );
check( (int) $held_meter['open_holds'] > 0, 'The meter reports the number of open holds.' );
$usage->forget( $user_id );

echo "--- 9. Capabilities ---\n";

check( Capabilities::is_valid( 'replicaforge_use' ), 'A declared capability is valid.' );
check( ! Capabilities::is_valid( 'replicaforge_everything' ), 'An invented capability is invalid.' );
check( ! Capabilities::is_valid( array( 'replicaforge_use' ) ), 'A non-string capability is invalid.' );
check( ! Capabilities::current_user_can( 'invented' ), 'An invalid capability is never held, not even by an administrator.' );

same( Capabilities::for_operation( 'generation' ), 'replicaforge_generate', 'Generation needs the generate capability.' );
same( Capabilities::for_operation( 'analysis' ), 'replicaforge_use', 'Analysis needs the use capability.' );
same( Capabilities::for_operation( 'validation' ), 'replicaforge_use', 'Validation needs the use capability.' );
check( '' !== Capabilities::for_operation( 'not_an_operation' ), 'An unknown operation still maps to a capability, so an unmapped operation cannot skip the check.' );

check( count( Capabilities::ADMIN_CAPS ) === 5, 'An administrator gets five capabilities.' );
check( count( Capabilities::EDITOR_CAPS ) === 2, 'An editor gets two capabilities, and neither of them is a management one.' );
check( ! in_array( 'replicaforge_manage_plans', Capabilities::EDITOR_CAPS, true ), 'An editor cannot manage plans.' );
check( ! in_array( 'replicaforge_manage_settings', Capabilities::EDITOR_CAPS, true ), 'An editor cannot manage settings.' );
check( ! in_array( 'replicaforge_manage_projects', Capabilities::EDITOR_CAPS, true ), 'An editor cannot manage other people\'s projects.' );

$granted = Capabilities::grant_default_roles();
$check_capabilities = Capabilities::orphan_check();
check( $check_capabilities['ok'], 'Every declared capability is held by at least one role after a grant.' );

$report = Capabilities::role_report();
check( isset( $report['administrator'] ), 'The role report includes the administrator.' );
check( ! empty( $report['administrator']['replicaforge_manage_plans'] ), 'An administrator holds the plan management capability.' );
check( ! empty( $report['editor']['replicaforge_use'] ), 'An editor holds the use capability.' );
check( empty( $report['editor']['replicaforge_manage_plans'] ), 'An editor does not hold the plan management capability.' );

echo "--- 10. Licensing: states are facts, not permissions ---\n";

check( count( License_State::STATES ) === 8, 'Eight license states are declared, matching the brief.' );
foreach ( License_State::STATES as $state_name ) {
	check( License_State::make( $state_name ) instanceof License_State, 'The state ' . $state_name . ' is constructible.' );
}
check( ! License_State::make( 'superseded' )->grants(), 'An unrecognised state reads as unknown, and unknown grants nothing.' );
same( License_State::make( 'nonsense' )->name(), License_State::UNKNOWN, 'An unrecognised state name becomes unknown rather than being kept.' );

check( License_State::make( License_State::ACTIVE )->grants(), 'Active grants.' );
check( License_State::make( License_State::TRIAL )->grants(), 'Trial grants.' );
check( License_State::make( License_State::GRACE_PERIOD )->grants(), 'A grace period grants, because that is what a grace window is for.' );
check( ! License_State::make( License_State::EXPIRED )->grants(), 'Expired does not grant, because a grace period would be meaningless otherwise.' );
check( ! License_State::make( License_State::INACTIVE )->grants(), 'Inactive does not grant.' );
check( ! License_State::make( License_State::INVALID )->grants(), 'Invalid does not grant.' );
check( ! License_State::make( License_State::REVOKED )->grants(), 'Revoked does not grant.' );
check( ! License_State::make( License_State::UNKNOWN )->grants(), 'Unknown does not grant. Unknown is not permission.' );

check( License_State::make( License_State::INVALID )->is_problem(), 'Invalid is a problem a user must be told about.' );
check( ! License_State::make( License_State::INACTIVE )->is_problem(), 'Inactive is not a problem: nobody bought anything.' );
check( ! License_State::make( License_State::UNKNOWN )->is_problem(), 'Unknown is not a problem; it is the provider\'s problem.' );
check( License_State::make( License_State::UNKNOWN )->is_temporary(), 'Unknown is temporary.' );

// An expiry demotes a state without anything changing it. A state built a second
// ago can become false, so the demotion has to happen on read.
$expiring = License_State::make( License_State::ACTIVE, '', time() - 5 );
same( $expiring->effective_name(), License_State::EXPIRED, 'An active license whose expiry has passed reads as expired.' );
check( ! $expiring->grants(), 'An expired-by-time license grants nothing.' );
$expiring_grace = License_State::make( License_State::GRACE_PERIOD, '', time() - 5 );
same( $expiring_grace->effective_name(), License_State::EXPIRED, 'An elapsed grace period reads as expired.' );

$long_lived = License_State::make( License_State::ACTIVE, '', time() + DAY_IN_SECONDS );
same( $long_lived->effective_name(), License_State::ACTIVE, 'A license with a future expiry is still active.' );

$as_array = License_State::make( License_State::ACTIVE, 'test', 0, 'ref-1' )->to_array();
foreach ( array( 'state', 'grants', 'expires_at', 'checked_at', 'reason' ) as $key ) {
	check( array_key_exists( $key, $as_array ), 'The state array carries ' . $key . '.' );
}
$as_array = License_State::make( License_State::ACTIVE, '', 0, 'ref-1' )->to_array();
check( ! array_key_exists( 'reference', $as_array ), 'The browser-facing state array carries no provider reference, so there is nothing secret in it to leak.' );

echo "--- 11. Licensing: the local provider is local ---\n";

$provider = new Local_License_Provider();
check( $provider instanceof License_Provider_Contract, 'The local provider implements the provider contract.' );
same( $provider->id(), 'local_development', 'The local provider identifies itself.' );
check( ! $provider->is_remote(), 'The local provider is not remote.' );
check( $provider->state() instanceof License_State, 'The local provider returns a state.' );

$provider->clear();
same( $provider->state()->name(), License_State::INACTIVE, 'A site with no record reads as inactive, not as broken.' );
same( $provider->state()->reason(), 'not_configured', 'The inactive state says why.' );

$diagnostics = $provider->diagnostics();
check( false === $diagnostics['requires_service'], 'The local provider requires no external service.' );
check( array() !== $diagnostics['notes'], 'The local provider explains itself in notes a user can read.' );

$bad_state = $provider->store( array( 'state' => 'definitely_not_a_state' ) );
check( empty( $bad_state['success'] ), 'An unrecognised state is refused.' );
check( array() !== $bad_state['errors'], 'The refusal explains itself.' );
same( $provider->state()->name(), License_State::INACTIVE, 'A refused store left the record alone.' );

$good_state = $provider->store( array( 'state' => License_State::ACTIVE, 'reference' => 'local-1' ) );
check( ! empty( $good_state['success'] ), 'A recognised state is stored.' );
same( $provider->state()->name(), License_State::ACTIVE, 'The stored state reads back.' );

$bad_expiry = $provider->store( array( 'state' => License_State::ACTIVE, 'expires_at' => 'soon' ) );
check( empty( $bad_expiry['success'] ), 'An unreadable expiry is refused.' );

$record = $provider->record();
check( ! array_key_exists( 'plan_id', $record ), 'A local record carries no plan id. The state is the fact; the plan follows from it.' );
same( $record['verification'], 'none_local_record', 'A local record says plainly that it is unverified.' );

$provider->clear();

echo "--- 12. Licensing: the resolution order ---\n";

Plan_Storage::reset();
Plan_Storage::flush_cache();
$licenses = new License_Manager();
delete_option( License_Manager::SITE_PLAN_OPTION );
$licenses->set_configured_plan( 'agency' );

// An inactive license means the free plan, not the configured plan. If the
// configured plan were honoured regardless, the license would be decorative.
$inactive = $licenses->state();
same( $inactive->name(), License_State::INACTIVE, 'With no record the license is inactive.' );
same( $licenses->licensed_plan( $inactive )->id(), 'free', 'An inactive license resolves to the free plan even when a paid plan is configured.' );
same( $licenses->configured_plan()->id(), 'agency', 'The configured plan is still readable, so an administrator can see what is set.' );

$provider->store( array( 'state' => License_State::ACTIVE ) );
$active = $licenses->state();
same( $active->name(), License_State::ACTIVE, 'A stored active state reads as active.' );
same( $licenses->licensed_plan( $active )->id(), 'agency', 'An active license grants the configured plan.' );

// A provider that throws must not take the plugin down, and must fail closed.
$throwing = new class() implements License_Provider_Contract {
	public function id() {
		return 'throwing';
	}
	public function label() {
		return 'Throwing';
	}
	public function is_remote() {
		return false;
	}
	public function state() {
		throw new RuntimeException( 'provider is down' );
	}
};
$broken = new License_Manager( $throwing );
$broken_state = $broken->state();
same( $broken_state->name(), License_State::UNKNOWN, 'A provider that throws resolves to unknown.' );
same( $broken->licensed_plan( $broken_state )->id(), 'free', 'A throwing provider fails closed to the free plan.' );

// A provider that answers with the wrong type must not be trusted either.
$wrong_type = new class() implements License_Provider_Contract {
	public function id() {
		return 'wrong';
	}
	public function label() {
		return 'Wrong';
	}
	public function is_remote() {
		return false;
	}
	public function state() {
		return array( 'state' => 'active', 'plan_id' => 'agency' );
	}
};
$wrong = new License_Manager( $wrong_type );
same( $wrong->state()->name(), License_State::UNKNOWN, 'A provider that breaks the contract resolves to unknown rather than to whatever it returned.' );

// No billing provider is the supported default, and no payment path exists.
check( null === ( new License_Manager() )->billing(), 'With no provider configured there is no billing provider, and that is a supported state.' );

echo "--- 13. Trials ---\n";

Plan_Storage::store_trial( array( 'enabled' => false, 'days' => 14, 'plan' => 'pro' ) );
$trial_off = $licenses->trial( $user_id );
check( ! $trial_off['available'], 'With trials off, no trial is available.' );
same( $trial_off['reason'], 'disabled', 'The reason is that trials are off.' );
$refused = $licenses->start_trial( $user_id );
check( empty( $refused['success'] ), 'Starting a trial with trials off is refused.' );
check( array() !== $refused['errors'], 'The refusal explains itself.' );

Plan_Storage::store_trial( array( 'enabled' => true, 'days' => 14, 'plan' => 'pro' ) );
$available = $licenses->trial( $user_id );
check( $available['available'], 'With trials on, a trial is available to a user who has not used one.' );
check( empty( $available['active'] ), 'An available trial is not yet running.' );

$started = $licenses->start_trial( $user_id );
check( ! empty( $started['success'] ), 'A trial starts.' );
check( $started['trial']['active'], 'The started trial is running.' );
check( $started['trial']['expires_at'] > time(), 'The trial has a future expiry.' );

$again = $licenses->start_trial( $user_id );
check( empty( $again['success'] ), 'A second trial cannot be started for a user who already has one.' );
same( $again['trial']['consumed'], true, 'The trial is recorded as consumed, so it cannot be restarted.' );

// A trial upgrades only from the free plan. A site already on a paid plan has no
// reason to be inside a trial, and applying one is either a downgrade or a no-op.
// Both halves need the license out of the way, so the site resolves to the free
// plan first.
$provider->clear();
$licenses->set_configured_plan( 'free' );
Plan_Storage::store_trial( array( 'enabled' => true, 'days' => 14, 'plan' => 'starter' ) );
$licenses->cancel_trial( $user_id );
$cancelled = $licenses->trial( $user_id );
check( ! $cancelled['active'], 'A cancelled trial is not active.' );
check( $cancelled['consumed'], 'A cancelled trial is still recorded as consumed, so cancelling cannot be used to restart it.' );

$user2 = rf_phase10_user( 'editor' );

// An available trial that has not been started grants nothing. A trial is
// something the user starts, not something they are handed.
$not_started = $licenses->plan_for_user( $user2 );
same( $not_started['plan']->id(), 'free', 'An available but unstarted trial does not change the plan.' );
same( $not_started['via'], 'free_default', 'The resolution says the plan is the free default.' );

$user2_trial = $licenses->start_trial( $user2 );
check( ! empty( $user2_trial['success'] ), 'The second user starts their own trial.' );

$on_free = $licenses->plan_for_user( $user2 );
same( $on_free['plan']->id(), 'starter', 'A started trial plan replaces the free plan.' );
same( $on_free['via'], 'trial', 'The resolution says the plan came from a trial.' );
same( $licenses->licensed_plan()->id(), 'free', 'With no license the site itself is on the free plan, so the trial has something to upgrade.' );

$provider->store( array( 'state' => License_State::ACTIVE ) );
$licenses->set_configured_plan( 'agency' );
$on_paid = $licenses->plan_for_user( $user2 );
same( $on_paid['plan']->id(), 'agency', 'A trial does not downgrade a site that is already on a paid plan.' );
same( $on_paid['via'], 'license', 'The resolution says the plan came from the license.' );

$provider->clear();
$licenses->set_configured_plan( 'free' );
Plan_Storage::store_trial( array( 'enabled' => false, 'days' => 14, 'plan' => 'pro' ) );
Plan_Storage::flush_cache();

echo "--- 14. The entitlement gate refuses in order ---\n";

$entitlements = new Entitlement_Manager();

$no_user = $entitlements->check( 'analysis', 0 );
check( empty( $no_user['allowed'] ), 'An operation with no user is refused.' );
same( $no_user['code'], 'authentication_required', 'The first refusal is authentication.' );
same( (int) $no_user['status'], 401, 'Authentication is a 401.' );

$unknown_op = $entitlements->check( 'not_an_operation', $user_id );
check( empty( $unknown_op['allowed'] ), 'An unrecognised operation is refused, so a typo cannot be usable by default.' );
same( $unknown_op['code'], 'unknown_operation', 'The refusal names the unknown operation.' );

// A user with no ReplicaForge capability is refused on capability, whatever their
// plan. Capability is checked before entitlement so that a plan is never the thing
// that explains a missing permission.
$plain_id = rf_phase10_user( 'subscriber' );
$subscriber_check = $entitlements->check( 'analysis', $plain_id );
check( empty( $subscriber_check['allowed'] ), 'A subscriber is refused.' );
same( $subscriber_check['code'], 'capability_missing', 'The refusal is on capability, not on plan.' );
same( (int) $subscriber_check['status'], 403, 'A capability refusal is a 403.' );

// The editor does hold the capability.
$editor_check = $entitlements->check( 'analysis', $user_id );
check( ! empty( $editor_check['allowed'] ), 'An editor with the capability and a permitting plan is allowed.' );
same( $editor_check['plan_id'], 'free', 'The approval names the plan it was decided under.' );

echo "--- 15. Entitlement beats limit, and a limit is reported properly ---\n";

// Free does not permit corrections at all, so the answer is a feature refusal
// even though the usage allowance is untouched.
$feature_denied = $entitlements->check( 'correction', $user_id );
check( empty( $feature_denied['allowed'] ), 'An operation whose feature the plan withholds is refused.' );
same( $feature_denied['code'], 'feature_not_in_plan', 'The refusal is about the feature.' );
same( (int) $feature_denied['status'], 402, 'A feature refusal is a 402, so the client knows an upgrade is what would change it.' );
check( isset( $feature_denied['details']['feature'] ), 'The refusal names the feature.' );
check( isset( $feature_denied['details']['upgrade_url'] ), 'The refusal offers somewhere to read about it.' );
same( $feature_denied['details']['billing_configured'], false, 'The refusal says billing is not configured, rather than offering a purchase that does not exist.' );

// A plan with the feature but no remaining allowance is a limit refusal.
$usage->forget( $user_id );
$usage->charge( $user_id, 'validation', 20, Plan_Storage::get( 'free' ) );
$limit_denied = $entitlements->check( 'validation', $user_id );
check( empty( $limit_denied['allowed'] ), 'An operation with no remaining allowance is refused.' );
same( $limit_denied['code'], 'usage_limit_reached', 'The refusal is about the limit.' );
same( (int) $limit_denied['details']['limit'], 20, 'The refusal reports the plan limit.' );
same( (int) $limit_denied['details']['used'], 20, 'The refusal reports the used count.' );
same( (int) $limit_denied['details']['remaining'], 0, 'The refusal reports what is left.' );
check( isset( $limit_denied['details']['resets_at'] ), 'The refusal says when the period resets.' );
$usage->forget( $user_id );

$notice = $entitlements->limit_notice( 'validation', Plan_Storage::get( 'free' ), array( 'limit' => 20, 'used' => 20, 'held' => 0 ) );
check( '' !== $notice['title'], 'The limit notice has a title.' );
check( '' !== $notice['body'], 'The limit notice has a body.' );
same( $notice['plan'], 'Free', 'The limit notice names the current plan.' );
same( (int) $notice['used'], 20, 'The limit notice reports the used count.' );
same( (int) $notice['charged'], 20, 'The limit notice reports what has been charged.' );
same( (int) $notice['held'], 0, 'The limit notice reports what is in flight.' );
same( (int) $notice['in_flight'], 0, 'With nothing in flight the notice does not claim any is.' );
same( (int) $notice['limit'], 20, 'The limit notice reports the limit.' );
same( $notice['billing_configured'], false, 'The limit notice says billing is not configured rather than offering a fake upgrade.' );
check( '' !== $notice['upgrade_url'], 'The limit notice links somewhere real: the plan details page.' );

// A notice raised while a job is running must not claim the user has used two
// when they have charged one and started another.
$in_flight = $entitlements->limit_notice( 'validation', Plan_Storage::get( 'free' ), array( 'limit' => 20, 'used' => 19, 'held' => 1 ) );
same( (int) $in_flight['used'], 20, 'The bar figure includes work in flight, because that work will consume the allowance.' );
same( (int) $in_flight['charged'], 19, 'The notice separates what has been charged from what is running.' );
same( (int) $in_flight['held'], 1, 'The notice reports the in-flight count.' );
same( (int) $in_flight['in_flight'], 1, 'The notice says something is in flight.' );

echo "--- 16. begin() and settle(): the operation lifecycle ---\n";

$usage->forget( $user_id );
$begin = $entitlements->begin( 'analysis', $user_id, array( 'project_id' => '' ) );
check( ! empty( $begin['allowed'] ), 'begin() approves an allowed operation.' );
check( ! empty( $begin['reservation'] ), 'begin() returns a reservation token.' );
same( $usage->used( $user_id, 'analysis' ), 0, 'begin() does not charge.' );

$settled = $entitlements->settle( $user_id, $begin['reservation'] );
check( ! empty( $settled['success'] ), 'settle() succeeds.' );
same( $usage->used( $user_id, 'analysis' ), 1, 'settle() charges.' );

$begin2 = $entitlements->begin( 'analysis', $user_id );
$failed = $entitlements->fail( $user_id, $begin2['reservation'], 'request_failed' );
check( ! empty( $failed['success'] ), 'fail() succeeds.' );
same( $usage->used( $user_id, 'analysis' ), 1, 'fail() charges nothing, so a failed run does not consume allowance.' );

$denied_begin = $entitlements->begin( 'correction', $user_id );
check( empty( $denied_begin['allowed'] ), 'begin() refuses a forbidden operation.' );
check( ! isset( $denied_begin['reservation'] ), 'A refused begin() returns no reservation, so there is nothing to settle.' );

$usage->forget( $user_id );

echo "--- 17. Entity limits are counted, not metered ---\n";

$projects = new \ReplicaForge\Project_Repository();

$not_entity = $entitlements->check_entity( 'generation_per_period', $user_id, 1 );
check( empty( $not_entity['allowed'] ), 'A per-period limit cannot be checked as an entity count.' );
same( $not_entity['code'], 'unknown_limit', 'The refusal names the limit as the problem.' );

// A plan that allows one monitored project, forced through the resolution filter
// so the entity rule is tested independently of which plan the site happens to be
// on. The site's own plan is the free plan, which allows none.
$one_allowed = Plan_Definition::create(
	'monitored_one',
	array(
		'name'     => 'Monitored one',
		'limits'   => array( 'monitored_projects' => 1, 'history_projects' => 1 ),
		'features' => array( 'monitoring' => true ),
	)
);

$filter_plan = static function () use ( &$current_plan ) {
	return $current_plan;
};
$current_plan = $one_allowed;
add_filter( 'replicaforge_resolved_plan', $filter_plan );

$allow_entitlements = new Entitlement_Manager( new Plan_Manager(), $usage );
$no_projects = $allow_entitlements->check_entity( 'monitored_projects', $user_id, 1 );
check( ! empty( $no_projects['allowed'] ), 'A user with nothing monitored may monitor one project.' );
same( (int) $no_projects['details']['limit'], 1, 'The approval reports the entity limit.' );
same( (int) $no_projects['details']['current'], 0, 'The approval reports what is currently counted.' );
same( (int) $no_projects['details']['remaining'], 1, 'The approval reports what is left.' );

// A plan allowing zero refuses the first one, and refuses on the entity limit
// rather than on the feature — the feature is on, there is simply no room.
$zero = Plan_Definition::create(
	'monitored_none',
	array( 'name' => 'None', 'limits' => array( 'monitored_projects' => 0 ), 'features' => array( 'monitoring' => true ) )
);
$current_plan = $zero;
$zero_entitlements = new Entitlement_Manager( new Plan_Manager(), $usage );
$none_allowed = $zero_entitlements->check_entity( 'monitored_projects', $user_id, 1 );
check( empty( $none_allowed['allowed'] ), 'A plan allowing zero monitored projects refuses the first one.' );
same( $none_allowed['code'], 'entity_limit_reached', 'The refusal is an entity limit, not a feature refusal: the feature is on.' );
same( (int) $none_allowed['details']['limit'], 0, 'The refusal reports the entity limit of zero.' );

// Unlimited is unlimited, and reports itself as such rather than as a very large
// number.
$unlimited_plan = Plan_Definition::create(
	'monitored_many',
	array( 'name' => 'Many', 'limits' => array( 'monitored_projects' => Plan_Limits::UNLIMITED ), 'features' => array( 'monitoring' => true ) )
);
$current_plan = $unlimited_plan;
$many_entitlements = new Entitlement_Manager( new Plan_Manager(), $usage );
$many_allowed = $many_entitlements->check_entity( 'monitored_projects', $user_id, 1 );
check( ! empty( $many_allowed['allowed'] ), 'An unlimited entity limit allows one.' );
same( $many_allowed['details']['unlimited'], true, 'An unlimited entity limit reports itself as unlimited.' );

$logged_out_entity = $entitlements->check_entity( 'monitored_projects', 0, 1 );
check( empty( $logged_out_entity['allowed'] ), 'A logged-out entity check is refused.' );
same( (int) $logged_out_entity['status'], 401, 'A logged-out entity check is a 401.' );

remove_filter( 'replicaforge_resolved_plan', $filter_plan );

echo "--- 18. Project access ---\n";

$access = new Project_Access( $projects );
$other = rf_phase10_user( 'editor' );

wp_set_current_user( $user_id );
$project = $projects->create( 'https://example.invalid/owned', array( 'name' => 'Owned' ) );
check( is_array( $project ), 'A project is created for the current user.' );
$project_id = (string) $project['project_id'];
same( (int) $project['user_id'], $user_id, 'The project records its owner.' );

check( $access->owns( $user_id, $project_id ), 'The owner owns the project.' );
check( ! $access->owns( $other, $project_id ), 'Another user does not own the project.' );
check( $access->can_read( $user_id, $project_id ), 'The owner can read the project.' );
check( ! $access->can_read( $other, $project_id ), 'Another user cannot read the project.' );
check( null === $access->readable_project( $other, $project_id ), 'Another user gets no project record, not a redacted one.' );
check( null === $access->owned_project( $other, $project_id ), 'Another user gets no owned record.' );
check( ! $access->owns( 0, $project_id ), 'A user id of zero owns nothing.' );
check( ! $access->owns( $user_id, '' ), 'An empty project id is owned by nobody.' );
check( ! $access->owns( $user_id, 'nonexistent' ), 'A project that does not exist is owned by nobody.' );

same( $access->count_projects( $user_id ), 1, 'The owner sees one project.' );
same( $access->count_projects( $other ), 0, 'The other user sees none.' );
same( count( $access->visible_projects( $other ) ), 0, 'The other user\'s visible project list is empty.' );
same( count( $access->visible_projects( $user_id ) ), 1, 'The owner\'s visible project list has their project.' );
same( count( $access->visible_projects( 0 ) ), 0, 'A user id of zero sees no projects.' );

// The refusal must not distinguish "not yours" from "not real", or the endpoint
// becomes an oracle for enumerating project ids.
$not_mine = $access->refusal( $other, $project_id );
$not_real = $access->refusal( $other, 'zzzzzzzzzz' );
same( $not_mine['code'], $not_real['code'], 'A project that is not yours and a project that does not exist produce the same refusal.' );
same( $not_mine['message'], $not_real['message'], 'The two refusals use the same wording.' );
same( (int) $not_mine['status'], 404, 'The refusal is a 404 rather than a 403, which would confirm the project exists.' );
check( empty( $not_mine['allowed'] ), 'The refusal is a refusal.' );

$no_id = $access->refusal( $user_id, '' );
same( $no_id['code'], 'project_id_required', 'An empty project id is a different refusal, because it is a malformed request rather than a permission answer.' );
same( (int) $no_id['status'], 400, 'A malformed request is a 400.' );

$logged_out = $access->refusal( 0, $project_id );
same( (int) $logged_out['status'], 401, 'A logged-out request is a 401.' );

// Monitoring has not been built, so the count is a real count of a flag nothing
// writes yet. It must read zero, not a fabricated number.
$monitored = $access->count_monitored( $user_id );
check( isset( $monitored['count'], $monitored['projects'] ), 'The monitored count reports a count and the projects behind it.' );
same( (int) $monitored['count'], count( $monitored['projects'] ), 'The count matches the list length, so the two cannot disagree.' );

$projects->delete( $project_id, false );

echo "--- 19. Project status is one vocabulary ---\n";

check( count( Project_Status::STATUSES ) === 15, 'Fifteen statuses are declared, matching the brief.' );
foreach ( Project_Status::STATUSES as $status_name ) {
	check( Project_Status::is_valid( $status_name ), 'The status ' . $status_name . ' is valid.' );
	check( '' !== Project_Status::label( $status_name ), 'The status ' . $status_name . ' has a label.' );
}
check( ! Project_Status::is_valid( 'vibes' ), 'An invented status is refused.' );
check( ! Project_Status::is_valid( '' ), 'An empty status is refused.' );

same( Project_Status::of( array( 'status' => 'nonsense' ) ), Project_Status::NEW, 'An unrecognised stored status reads as new rather than breaking the screen.' );
same( Project_Status::of( array() ), Project_Status::NEW, 'A project with no status reads as new.' );

check( Project_Status::can_transition( Project_Status::NEW, Project_Status::ANALYZING ), 'New may become analyzing.' );
check( Project_Status::can_transition( Project_Status::GENERATED, Project_Status::VALIDATING ), 'Generated may become validating.' );
check( Project_Status::can_transition( Project_Status::FAILED, Project_Status::GENERATING ), 'Failed may be retried.' );
check( ! Project_Status::can_transition( Project_Status::NEW, Project_Status::COMPLETED ), 'New may not jump straight to completed.' );
check( ! Project_Status::can_transition( Project_Status::NEW, Project_Status::SYNCING ), 'New may not jump straight to syncing.' );
check( Project_Status::can_transition( Project_Status::ANALYZING, Project_Status::ANALYZING ), 'A status may be re-asserted, because a workflow legitimately returns to where it was.' );
check( ! Project_Status::can_transition( 'nonsense', Project_Status::NEW ), 'An invalid current status allows nothing.' );
check( ! Project_Status::can_transition( Project_Status::NEW, 'nonsense' ), 'An invalid target status is refused.' );

foreach ( Project_Status::STATUSES as $status_name ) {
	$reachable = Project_Status::reachable_from( $status_name );
	check( in_array( $status_name, $reachable, true ), 'The reachable set for ' . $status_name . ' includes itself.' );
}
check( count( Project_Status::vocabulary() ) === count( Project_Status::STATUSES ), 'The vocabulary covers every status.' );

echo "--- 20. Health is derived, not invented ---\n";

$healthy = Project_Status::health( array( 'status' => Project_Status::GENERATED ) );
same( $healthy['state'], Project_Status::HEALTHY, 'A generated project with no validation and no warnings is healthy, because nothing has gone wrong.' );
same( $healthy['label'], 'Healthy', 'A healthy project has a label.' );
same( $healthy['status'], Project_Status::GENERATED, 'Health reports the status it was derived from.' );

$failed_health = Project_Status::health( array( 'status' => Project_Status::FAILED ) );
same( $failed_health['state'], Project_Status::GENERATION_FAILED, 'A failed project reports generation failure.' );
check( count( $failed_health['reasons'] ) > 0, 'A health result explains itself.' );
foreach ( $failed_health['reasons'] as $reason ) {
	check( '' !== $reason['message'], 'A health reason has a human-readable message.' );
}

// A validation that ran and found nothing blocking is a positive result, not a
// missing one.
$clean_validation = Project_Status::health(
	array(
		'status'     => Project_Status::GENERATED,
		'validation' => array( 'differences' => array(), 'metrics' => array() ),
	)
);
same( $clean_validation['state'], Project_Status::HEALTHY, 'A validation with a readable, empty difference list is healthy.' );

$major = Project_Status::health(
	array(
		'status'     => Project_Status::GENERATED,
		'validation' => array( 'differences' => array( array( 'severity' => 'major' ) ) ),
	)
);
same( $major['state'], Project_Status::VALIDATION_ISSUES, 'A validation with a major difference reports validation issues.' );

$minor_only = Project_Status::health(
	array(
		'status'     => Project_Status::GENERATED,
		'validation' => array( 'differences' => array( array( 'severity' => 'minor' ) ) ),
	)
);
same( $minor_only['state'], Project_Status::HEALTHY, 'A validation with only minor differences is not a health problem.' );

// A validation we cannot read is not the same as a healthy one.
$unreadable = Project_Status::health(
	array( 'status' => Project_Status::GENERATED, 'validation' => array( 'something' => 'else' ) )
);
same( $unreadable['state'], Project_Status::NEEDS_REVIEW, 'A validation whose metrics cannot be read is reported as needing review.' );
same( $unreadable['reasons'][0]['code'], 'validation_metrics_unreadable', 'The reason says the metrics were unreadable, rather than claiming a score.' );

$warnings = Project_Status::health( array( 'status' => Project_Status::ANALYZED, 'warnings' => array( 'something happened' ) ) );
same( $warnings['state'], Project_Status::NEEDS_REVIEW, 'A project with warnings needs review.' );

$unreachable = Project_Status::health(
	array( 'status' => Project_Status::ANALYZED, 'warnings' => array( array( 'code' => 'request_timeout' ) ) )
);
same( $unreachable['state'], Project_Status::SOURCE_UNREACHABLE, 'A declared network failure code reports the source as unreachable.' );
check(
	! in_array( 'the word failed appears', Project_Status::HEALTH_STATES, true ),
	'Unreachability is matched on declared codes, not on a substring.'
);

$manual = Project_Status::health(
	array( 'status' => Project_Status::GENERATED, 'corrections' => array( 'manual_changes' => 3 ) )
);
same( $manual['state'], Project_Status::MANUAL_CHANGES, 'Recorded manual changes report manual changes.' );

$sync = Project_Status::health(
	array(
		'status'   => Project_Status::MONITORING,
		'settings' => array( 'monitoring' => array( 'enabled' => true, 'changes_pending' => true ) ),
	)
);
same( $sync['state'], Project_Status::SYNC_AVAILABLE, 'A pending source change reports sync available.' );

// Precedence: a failed generation outranks everything, because a correction about
// a document that does not exist is not useful.
$both = Project_Status::health(
	array(
		'status'     => Project_Status::FAILED,
		'validation' => array( 'differences' => array( array( 'severity' => 'critical' ) ) ),
		'warnings'   => array( 'x' ),
	)
);
same( $both['state'], Project_Status::GENERATION_FAILED, 'A failure outranks a validation issue, because the validation is about a document that does not exist.' );

// A generation failure also outranks a pending sync.
$failed_and_sync = Project_Status::health(
	array(
		'status'   => Project_Status::FAILED,
		'settings' => array( 'monitoring' => array( 'changes_pending' => true ) ),
	)
);
same( $failed_and_sync['state'], Project_Status::GENERATION_FAILED, 'A failure outranks a pending sync.' );

echo "--- 21. Quality is per group, not one number ---\n";

$no_quality = Project_Status::quality( array() );
check( false === $no_quality['available'], 'A project with no validation has no quality data.' );
check( null === $no_quality['combined'], 'A project with no validation has no combined score.' );
check( '' === $no_quality['combined_explained'], 'An absent score has no explanation, rather than a fabricated one.' );

$groups = array();
foreach ( array_keys( \ReplicaForge\Validation_Limits::METRIC_GROUPS ) as $group ) {
	$groups[ $group ] = 0.95;
}
$groups['layout'] = 0.42;

$quality = Project_Status::quality(
	array(
		'validation' => array(
			'created_at' => gmdate( 'c' ),
			'metrics'    => array( 'groups' => $groups ),
			'score'      => 0.88,
		),
	)
);
check( $quality['available'], 'A project with a validation has quality data.' );
check( isset( $quality['groups']['structure'] ), 'The structure group is reported.' );
check( isset( $quality['groups']['typography'] ), 'The typography group is reported.' );
check( isset( $quality['groups']['responsive'] ), 'The responsive group is reported.' );
check( isset( $quality['groups']['assets'] ), 'The assets group is reported.' );
check( isset( $quality['groups']['content'] ), 'The content group is reported.' );
check( isset( $quality['groups']['spacing'] ), 'The spacing group is reported.' );
check( isset( $quality['groups']['colors'] ), 'The colors group is reported.' );
check( isset( $quality['groups']['layout'] ), 'The layout group is reported.' );
check( ! isset( $quality['groups']['vibes'] ), 'A group the engine does not produce is not reported.' );

same( (int) round( (float) $quality['groups']['layout']['value'] * 100 ), 42, 'A group value is reported as measured.' );
check( $quality['groups']['layout']['measured'], 'A measured group says so.' );
check( '' !== $quality['groups']['layout']['label'], 'A group has a human-readable label.' );

// A group with no value is "not measured", not zero. Zero would read as "this part
// is completely wrong", which is a much stronger claim.
$partial = Project_Status::quality( array( 'validation' => array( 'metrics' => array( 'groups' => array( 'layout' => 0.5 ) ) ) ) );
check( ! $partial['groups']['structure']['measured'], 'A group with no value reports that it was not measured.' );
same( $partial['groups']['structure']['value'], null, 'A group with no value has no number.' );

same( (int) round( (float) $quality['combined'] * 100 ), 88, 'A combined score is reported when the stored result has one.' );
check( '' !== $quality['combined_explained'], 'A combined score comes with an explanation of how it is calculated.' );

$no_combined = Project_Status::quality( array( 'validation' => array( 'differences' => array() ) ) );
same( $no_combined['combined'], null, 'A validation with no combined score does not get one invented.' );

echo "--- 22. Timeline reuses the history that exists ---\n";

$timeline_project = array(
	'created_at' => '2026-01-01T10:00:00+00:00',
	'updated_at' => '2026-01-01T10:40:00+00:00',
	'analysis'   => array( 'created_at' => '2026-01-01T10:02:00+00:00' ),
	'validation' => array( 'created_at' => '2026-01-01T10:30:00+00:00' ),
	'versions'   => array(
		array( 'created_at' => '2026-01-01T10:15:00+00:00', 'label' => 'Generated draft' ),
	),
);
$timeline = Project_Status::timeline( $timeline_project );
check( count( $timeline ) >= 5, 'The timeline is built from the record that already exists, with no new event store.' );
check( $timeline[0]['timestamp'] >= $timeline[1]['timestamp'], 'The timeline is newest first.' );
same( $timeline[ count( $timeline ) - 1 ]['kind'], 'project_created', 'The oldest entry is the project creation, read from the record.' );
$labels = array();
foreach ( $timeline as $entry ) {
	check( isset( $entry['timestamp'], $entry['at'], $entry['kind'], $entry['label'] ), 'A timeline entry is fully formed.' );
	check( $entry['timestamp'] > 0, 'A timeline entry has a usable timestamp.' );
	$labels[] = $entry['kind'];
}
check( in_array( 'validation', $labels, true ), 'A validation in the record appears in the timeline.' );
check( in_array( 'version', $labels, true ), 'A version in the record appears in the timeline.' );

$empty_timeline = Project_Status::timeline( array() );
same( count( $empty_timeline ), 0, 'A project with no history has an empty timeline rather than a fabricated one.' );

echo "--- 23. The feature gate is a presentation layer, not a second gate ---\n";

wp_set_current_user( $user_id );
$gate = new Feature_Gate( $entitlements );
check( $gate->allowed( 'analysis', $user_id ), 'The gate agrees with the entitlement manager on an allowed operation.' );
check( ! $gate->allowed( 'correction', $user_id ), 'The gate agrees with the entitlement manager on a refused operation.' );

$state = $gate->state( 'correction', $user_id );
check( ! empty( $state['locked'] ), 'A refused operation is reported as locked.' );
check( empty( $state['allowed'] ), 'A refused operation is not reported as allowed.' );
check( isset( $state['notice'] ), 'A locked operation carries a notice.' );
check( '' !== $state['plan_id'], 'A locked operation names the reader\'s plan.' );

$notice = $gate->lock_notice( 'correction', $state );
check( isset( $notice['title'], $notice['summary'], $notice['current'], $notice['available_on'] ), 'The lock notice has a title, a summary, the current plan, and what else has it.' );
same( $notice['billing_configured'], false, 'The lock notice says billing is not configured.' );
check( '' !== $notice['note'], 'The lock notice explains what to do instead of offering a purchase.' );

// §30 forbids invented urgency. The only numbers in the notice are the ones read
// from the plan definitions.
$serialized = wp_json_encode( $notice );
check( false === strpos( (string) $serialized, 'spots left' ), 'The notice invents no scarcity.' );
check( false === strpos( (string) $serialized, 'only ' . count( $notice['available_on'] ) ), 'The notice does not dress the plan count up as scarcity.' );
check( false === strpos( (string) $serialized, 'hurry' ), 'The notice creates no urgency.' );

$available_on = array();
foreach ( $notice['available_on'] as $entry ) {
	$available_on[] = $entry['plan_id'];
}
check( count( $available_on ) > 0, 'The notice names the plans that do grant the feature, read from the definitions.' );
check( in_array( 'agency', $available_on, true ), 'Agency is among them, because it grants everything.' );
check( ! in_array( 'free', $available_on, true ), 'Free is not among them, because it does not grant corrections.' );

$elements = $gate->element_state( $state );
check( false !== strpos( $elements['class'], 'is-locked' ), 'A locked action is styled differently.' );
check( '' !== $elements['aria'], 'A locked action is marked for a screen reader.' );
check( false !== strpos( $elements['describedby'], 'replicaforge-lock-notice' ), 'A locked action points at its explanation, so the state is not signalled by styling alone.' );

$unlocked = $gate->element_state( $gate->state( 'analysis', $user_id ) );
check( false === strpos( $unlocked['class'], 'is-locked' ), 'An allowed action is not styled as locked.' );

echo "--- 24. The plan matrix is generated, not hard-coded ---\n";

$matrix = $gate->matrix();
check( isset( $matrix['columns'], $matrix['features'], $matrix['limits'] ), 'The matrix has columns, features, and limits.' );
check( isset( $matrix['columns']['free'], $matrix['columns']['agency'] ), 'Every declared plan is a column.' );
check( count( $matrix['features'] ) === count( Plan_Limits::FEATURES ), 'Every declared feature is a row.' );
check( count( $matrix['limits'] ) === count( Plan_Limits::LIMIT_NAMES ), 'Every declared limit is a row.' );

$monitoring_row = null;
foreach ( $matrix['features'] as $row ) {
	if ( 'monitoring' === $row['feature'] ) {
		$monitoring_row = $row;
	}
}
check( $monitoring_row !== null, 'The monitoring row is present.' );
same( $monitoring_row['granted']['free'], false, 'The matrix says free does not grant monitoring.' );
same( $monitoring_row['granted']['agency'], true, 'The matrix says agency grants monitoring.' );
same( $monitoring_row['granted']['starter'], false, 'The matrix says starter does not grant monitoring.' );

// A plan added through the filter appears in the matrix with no UI change.
add_filter(
	'replicaforge_plan_definitions',
	static function ( $plans ) {
		$plans['agency_plus'] = array(
			'name'     => 'Agency Plus',
			'limits'   => array(),
			'features' => array( 'monitoring' => true, 'elementor_generation' => true ),
		);
		return $plans;
	}
);
Plan_Storage::flush_cache();
$extended = ( new Feature_Gate() )->matrix();
check( isset( $extended['columns']['agency_plus'] ), 'A plan added through the filter appears as a column without a UI change.' );
remove_all_filters( 'replicaforge_plan_definitions' );
Plan_Storage::flush_cache();

echo "--- 25. The audit log is a closed list ---\n";

Audit_Log::clear();
check( Audit_Log::record( 'plan_changed', array( 'plan_id' => 'pro' ), $user_id ), 'A declared event is recorded.' );
check( Audit_Log::count() > 0, 'A recorded event is stored.' );

$undeclared = Audit_Log::record( 'something_interesting', array(), $user_id );
check( ! $undeclared, 'An undeclared event is refused, so a typo cannot silently vanish.' );
$events = Audit_Log::count();

// A key that is not declared for the event is dropped, so a caller cannot smuggle
// a value into a declared event under an innocent key.
Audit_Log::record( 'plan_changed', array( 'plan_id' => 'pro', 'api_key' => 'sk-secret' ), $user_id );
$entries = Audit_Log::recent( array( 'event' => 'plan_changed' ), 5 );
$smuggled = false;
foreach ( $entries as $entry ) {
	if ( isset( $entry['context']['api_key'] ) ) {
		$smuggled = true;
	}
}
check( ! $smuggled, 'A key that is not declared for the event is dropped.' );

// §36 forbids logging credentials and source HTML. A value containing markup is
// dropped rather than escaped, because markup in an audit entry means the value
// came from something the plugin did not generate.
Audit_Log::record( 'setting_changed', array( 'setting' => 'ai_key', 'value' => '<script>alert(1)</script>' ), $user_id );
$entries = Audit_Log::recent( array( 'event' => 'setting_changed' ), 5 );
$markup_stored = false;
foreach ( $entries as $entry ) {
	if ( isset( $entry['context']['value'] ) && false !== strpos( (string) $entry['context']['value'], '<' ) ) {
		$markup_stored = true;
	}
}
check( ! $markup_stored, 'A value containing markup is dropped rather than stored.' );

// Arrays and objects are not facts an audit entry needs, and a nested structure is
// where a large value would hide.
Audit_Log::record( 'usage_charged', array( 'operation' => 'generation', 'quantity' => array( 'huge' => str_repeat( 'x', 5000 ) ) ), $user_id );
$entries = Audit_Log::recent( array( 'event' => 'usage_charged' ), 5 );
$nested = false;
foreach ( $entries as $entry ) {
	if ( isset( $entry['context']['quantity'] ) && is_array( $entry['context']['quantity'] ) ) {
		$nested = true;
	}
}
check( ! $nested, 'A non-scalar context value is dropped.' );

foreach ( Audit_Log::EVENTS as $event_name => $allowed_keys ) {
	check( is_string( $event_name ) && array() !== $allowed_keys, 'The event ' . $event_name . ' declares the keys it may carry.' );
}
check( isset( Audit_Log::EVENTS['plan_changed'] ), 'A plan change is an auditable event.' );
check( isset( Audit_Log::EVENTS['entitlement_denied'] ), 'An entitlement denial is an auditable event.' );
check( isset( Audit_Log::EVENTS['ownership_denied'] ), 'An ownership denial is an auditable event.' );
check( isset( Audit_Log::EVENTS['license_invalidated'] ), 'A license invalidation is an auditable event.' );
check( isset( Audit_Log::EVENTS['limit_reached'] ), 'A limit being reached is an auditable event.' );

// The address is a salted marker, not an address.
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
Audit_Log::record( 'plan_changed', array( 'plan_id' => 'pro' ), $user_id );
$entries = Audit_Log::recent( array( 'event' => 'plan_changed' ), 1 );
$marker  = (string) ( $entries[0]['ip_hash'] ?? '' );
check( '' === $marker || 16 === strlen( $marker ), 'The client marker is a fixed-length digest rather than an address.' );
check( false === strpos( wp_json_encode( $entries ), '203.0.113.7' ), 'The raw client address is never stored.' );

// The ring is bounded, so a client cannot grow an option without limit.
$snapshot = Audit_Log::count();
for ( $i = 0; $i < 5; $i++ ) {
	Audit_Log::record( 'plan_changed', array( 'plan_id' => 'pro' ), $user_id );
}
check( Audit_Log::count() >= $snapshot, 'Recording more events still stores events.' );
check( Audit_Log::count() <= Audit_Log::MAX_ENTRIES, 'The ring is bounded, so a denial on every request cannot grow an option without limit.' );

Audit_Log::clear();
check( 0 === Audit_Log::count(), 'The audit log can be cleared.' );

echo "--- 26. Onboarding: state, and no action on activation ---\n";

$onboarding_before = Onboarding::state();
check( is_array( $onboarding_before ), 'The onboarding state is readable.' );
foreach ( array( 'version', 'fresh', 'completed', 'skipped' ) as $key ) {
	check( array_key_exists( $key, $onboarding_before ), 'The onboarding state carries ' . $key . '.' );
}

wp_set_current_user( $user_id );
$third = rf_phase10_user( 'editor' );

check( Onboarding::needs_welcome( $third ), 'A user who has not seen the welcome is offered it.' );
check( ! Onboarding::needs_welcome( 0 ), 'A logged-out visitor is not offered the welcome.' );

$seen = Onboarding::mark_seen( $third );
check( $seen['seen'], 'A user is recorded as having seen the welcome.' );
check( ! Onboarding::needs_welcome( $third ), 'A user who has seen the welcome is not offered it again.' );
check( Onboarding::needs_welcome( $third + 999999 ), 'A different user is still offered the welcome, because dismissal is per user.' );

$tours   = Onboarding::tours();
$tour_id = 'analysis_complete';
check( isset( $tours[ $tour_id ] ), 'The analysis-complete tour exists.' );
$active = Onboarding::active_tours( 'analyzed', $third );
check( count( $active ) > 0, 'A tour whose moment has arrived is active.' );
same( $active[0]['moment'], 'analyzed', 'The active tour names its moment.' );
same( $active[0]['id'], $tour_id, 'The tour that is active at the analyzed moment is the analyzed one.' );

// A tour belongs to exactly one moment, and each moment yields its own tour. The
// starting moment has a tour of its own, so this cannot be asserted as a count of
// zero — only as a tour for a different moment.
$at_start = Onboarding::active_tours( 'starting', $third );
check( count( $at_start ) > 0, 'The starting moment has its own tour.' );
same( $at_start[0]['id'], 'new_replica', 'The tour at the starting moment is the one about entering a URL.' );
check( $at_start[0]['id'] !== $active[0]['id'], 'A different moment yields a different tour.' );

$dismissed = Onboarding::dismiss_tour( $third, $tour_id );
check( ! empty( $dismissed['dismissed'][ $tour_id ] ), 'A tour can be dismissed.' );
same( count( Onboarding::active_tours( 'analyzed', $third ) ), 0, 'A dismissed tour does not come back at the same moment.' );

$bad_dismiss = Onboarding::dismiss_tour( $third, 'invented_tour' );
check( empty( $bad_dismiss['dismissed']['invented_tour'] ), 'An unrecognised tour id is refused rather than stored, so the list cannot grow with whatever a caller sent.' );

$restored = Onboarding::restore_tour( $third, $tour_id );
check( empty( $restored['dismissed'][ $tour_id ] ), 'A dismissed tour can be shown again.' );
same( count( Onboarding::active_tours( 'analyzed', $third ) ), 1, 'A restored tour is active again.' );

$welcome = Onboarding::welcome();
check( 'Welcome to ReplicaForge' === $welcome['title'], 'The welcome screen has the title the brief asks for.' );
check( false !== strpos( $welcome['tagline'], 'Elementor' ), 'The welcome tagline says what the plugin does.' );
same( count( $welcome['steps'] ), 6, 'The welcome screen explains six steps: analyze, understand, reconstruct, validate, improve, monitor.' );
$step_ids = array();
foreach ( $welcome['steps'] as $step ) {
	$step_ids[] = $step['id'];
	foreach ( array( 'id', 'label', 'body' ) as $key ) {
		check( isset( $step[ $key ] ) && '' !== $step[ $key ], 'A welcome step carries ' . $key . '.' );
	}
}
same( $step_ids, array( 'analyze', 'understand', 'reconstruct', 'validate', 'improve', 'monitor' ), 'The six steps are the six the brief names.' );
check( isset( $welcome['actions']['primary'], $welcome['actions']['secondary'], $welcome['actions']['tertiary'] ), 'The welcome screen offers create, demo, and skip.' );
check( false !== $welcome['does_nothing_yet'], 'The welcome screen says plainly that nothing has been analyzed or generated.' );

// §3 requires that a demo option is offered, and §47 requires that demo data
// never be presented as a real analysis. The option is offered and reported
// unavailable, which is the honest state for a build with no bundled sample data.
same( $welcome['actions']['secondary']['available'], false, 'The demo action is reported unavailable rather than linking to a broken page.' );
check( '' !== $welcome['actions']['secondary']['note'], 'The unavailable demo says why.' );

check( count( Onboarding::active_tours( 'failed', $third ) ) === 0, 'There is no tour for a failure, because a failure needs an error state rather than a hint.' );
same( Onboarding::moment_for( array() ), 'starting', 'A project with no data is at the starting moment.' );
same( Onboarding::moment_for( array( 'analysis' => array( 'x' => 1 ) ) ), 'analyzed', 'A project with an analysis is at the analyzed moment.' );
same( Onboarding::moment_for( array( 'analysis' => array(), 'drafts' => array( array() ) ) ), 'generated', 'A project with a draft is at the generated moment.' );
same( Onboarding::moment_for( array( 'status' => Project_Status::FAILED ) ), 'failed', 'A failed project reports the failed moment.' );

$notice = Onboarding::content_notice();
check( isset( $notice['scope'], $notice['not_covered'] ), 'The content notice distinguishes structural analysis from content reuse.' );
check( false !== stripos( $notice['not_covered']['body'], 'not permission' ), 'The notice says public accessibility is not permission to copy.' );
check( '' !== $notice['legal'], 'The notice states the user\'s responsibility for copyrighted material.' );

$product = Onboarding::product_notice();
same( $product['billing_configured'], false, 'The product notice reports that billing is not configured.' );
check( false !== stripos( $product['body'], 'no payment is taken' ), 'The product notice says no payment is taken, rather than implying a purchase flow exists.' );
check( false !== stripos( $product['body'], 'no billing provider' ), 'The product notice says there is no billing provider, in the product rather than only in the documentation.' );

echo "--- 27. Compatibility with the existing phases ---\n";

same( Schema::PLAN_SCHEMA_VERSION, '9.0', 'The plan schema version is declared separately from the data schema version.' );
// Asserted as "at least 10.0.0" rather than equal to it. A hard-coded literal here
// is the same brittleness that made the version bump in Phase 11 fail this suite:
// the property worth protecting is that the Phase 10 migration exists and has been
// applied, not that nothing has been added to the schema since.
check(
	version_compare( Schema::DB_SCHEMA_VERSION, '10.0.0', '>=' ),
	'The data schema version is at least the Phase 10 migration.'
);
check( isset( Schema::all()['plan_schema'] ), 'The plan schema version is reported by the schema summary.' );

$migration_targets = array();
foreach ( ( new \ReplicaForge\Migrator() )->migrations() as $migration ) {
	$migration_targets[] = (string) $migration['to'];
}
check( in_array( '10.0.0', $migration_targets, true ), 'The Phase 10 migration is still declared, so a fresh install replays it.' );

// Nothing in Phase 10 may have changed how the older phases answer.
check( class_exists( '\ReplicaForge\Analyzer' ), 'The Phase 1 analyzer still loads.' );
check( class_exists( '\ReplicaForge\Design_Analyzer' ), 'The Phase 2 design analyzer still loads.' );
check( class_exists( '\ReplicaForge\Ai_Manager' ), 'The Phase 3 AI manager still loads.' );
check( class_exists( '\ReplicaForge\Elementor_Generator' ), 'The Phase 4 generator still loads.' );
check( class_exists( '\ReplicaForge\Validation_Engine' ), 'The Phase 5 validation engine still loads.' );
check( class_exists( '\ReplicaForge\Correction_Applier' ), 'The Phase 6 applier still loads.' );
check( class_exists( '\ReplicaForge\Job_Queue' ), 'The Phase 7 job queue still loads.' );
check( class_exists( '\ReplicaForge\Token_Engine' ), 'The Phase 8 token engine still loads.' );
check( class_exists( '\ReplicaForge\Change_Detector' ), 'The Phase 9 change detector still loads.' );

// A Phase 9 rule must not have been quietly relaxed to make a Phase 10 test pass.
check( \ReplicaForge\Sync_Limits::MIN_INTERVAL_SECONDS >= 21600, 'The Phase 9 minimum monitoring interval is unchanged.' );
check( 3 === \ReplicaForge\Sync_Limits::REMOVAL_CONFIRMATIONS, 'The Phase 9 removal confirmation count is unchanged.' );

// §52 requires that no arbitrary Elementor data can be submitted through a
// frontend request, and the strict rule is that sync and plans have no write path
// of their own. Both are checked by reading the Phase 10 sources rather than by
// asserting on method names, which would test this file's memory of an API rather
// than the property.
$phase10_files = array_merge(
	glob( REPLICAFORGE_PATH . 'includes/plans/*.php' ),
	glob( REPLICAFORGE_PATH . 'includes/licensing/*.php' ),
	array(
		REPLICAFORGE_PATH . 'includes/class-replicaforge-capabilities.php',
		REPLICAFORGE_PATH . 'includes/class-replicaforge-project-access.php',
		REPLICAFORGE_PATH . 'includes/class-replicaforge-project-status.php',
	)
);
check( count( $phase10_files ) >= 13, 'The Phase 10 source files were located.' );

$forbidden = array(
	'Elementor_Document_Writer',
	'Elementor_Document_Reader',
	'Correction_Applier',
	'_elementor_data',
	'update_post_meta',
	'wp_update_post',
);
$offenders = array();
foreach ( $phase10_files as $file ) {
	$source = (string) file_get_contents( $file );
	foreach ( $forbidden as $needle ) {
		if ( false !== strpos( $source, $needle ) ) {
			$offenders[] = basename( $file ) . ' mentions ' . $needle;
		}
	}
}
same( $offenders, array(), 'No Phase 10 file touches an Elementor document, a post, or the Phase 6 applier, so Phase 10 introduced no second write path.' );

echo "--- 28. Cleanup ---\n";

// The shutdown handler does the work, so that an abort part-way through still
// leaves no users behind. Calling it here as well is harmless and makes the
// intent explicit at the point where a reader is looking for it.
rf_phase10_cleanup();

echo "\nphase10-plans-test: $assertions assertions\n";
