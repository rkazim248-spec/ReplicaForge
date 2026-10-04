# ReplicaForge Development

## 1. Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | 7.4+ | 8.4.25 used for verification |
| WordPress | 6.2+ | 7.1.2 used for verification |
| Elementor | Per `Elementor_Limits` | Optional; the plugin runs without it |
| A database | MySQL 5.7+ or the SQLite drop-in | |

No build step, no package manager, no third-party runtime dependency. The plugin is
plain PHP and one admin script.

## 2. Layout

```
replicaforge.php                 bootstrap, require_once list
uninstall.php                    uninstall policy
includes/
  class-replicaforge-*.php       shared services, Phase 1–2
  ai/                            Phase 3
  elementor/                     Phase 4
  validation/                    Phase 5
  corrections/                   Phase 6
  jobs/                          Phase 7
admin/
  css/admin.css                  all admin styles
  js/admin.js                    the guided workflow
tests/
  *-contract-test.php            executable contract tests
docs/                            documentation
languages/                       translation files
```

## 3. Running the tests

The contract suites are executable PHP that boot the real WordPress through
`wp-load.php`. They are not unit tests with mocks: they exercise the plugin the way
the site runs it.

```powershell
# All suites
powershell -ExecutionPolicy Bypass -File run-all-tests.ps1

# One suite
php -d "extension_dir=<bin>\ext" `
    -d extension=php_pdo_sqlite `
    -d extension=php_sqlite3 `
    -d display_errors=1 `
    run-test.php <wp-root> <wp-root>\wp-content\plugins\replicaforge\tests\<suite>.php

# Syntax check
php -l <file>
```

Each suite prints `PASS:` per assertion and a final `RESULT: PASS`. A failure throws
immediately, so the first failure is the one to read.

| Suite | Assertions | Covers |
|---|---|---|
| `phase3-contract-test.php` | 13 | AI prompt, schema, redaction, untrusted envelope |
| `phase4-contract-test.php` | 58 | Elementor compatibility, widgets, document, draft |
| `phase5-contract-test.php` | 146 | Comparators, metrics, validation, export |
| `phase6-contract-test.php` | 201 | Property map, plan, apply, rollback, regression |
| `security-contract-test.php` | 132 | SSRF, redaction, injection, authorization, uninstall |
| `jobs-contract-test.php` | 122 | Statuses, idempotency, leases, checkpoints, retry |
| `maintenance-contract-test.php` | 123 | Scheduling, retention, migrations, status |
| `admin-contract-test.php` | 66 | Rendering, escaping, ARIA, asset scoping |
| `lifecycle-contract-test.php` | 47 | Activation, deactivation, uninstall scope |

## 4. Writing a test

A test is a PHP file that boots WordPress and asserts against the running plugin.

```php
<?php
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
    fwrite( STDERR, "usage: php my-test.php <wp-root>\n" );
    exit( 2 );
}

$_SERVER['HTTP_HOST'] = 'localhost';
// ... other $_SERVER values
if ( ! defined( 'WP_ADMIN' ) ) {
    define( 'WP_ADMIN', true );
}
require_once $root . '/wp-load.php';

$assertions = 0;

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

// ... assertions
```

### Rules that matter

**Assert on stored state, not on what a run reported.** A run that says "applied" is
a claim. `_elementor_data` is the fact. The Phase 6 end-to-end test was rewritten
because the original asserted on the run summary and would have passed even if
nothing was written.

**Make the test independent of run order.** Clear the options the test writes to at
the start. A live idempotency record from a previous run is correct product
behaviour, not a test failure; the test clears it rather than weakening the
assertion.

**Assert the property, not an implementation detail.** The resume test originally
claimed to check that a job resumes at its stage, using a job that had never
advanced — so it proved nothing. It now advances a job to a real stage first.

**Let a failing assertion stop the run.** A suite that reports forty failures after
the first real problem wastes the reader's attention.

**Describe what the assertion protects, in the failure message.** "A resumed job
restarts at the stage it reached" is useful. "assertion 47" is not.

## 5. Coding standards

- WordPress Coding Standards.
- Tabs for indentation, spaces inside parentheses.
- Every file starts with a file docblock, a `defined( 'ABSPATH' ) || exit;` guard,
  and a `namespace` declaration.
- One class per file, named to match the file.
- `final` on classes that are not designed for extension.
- Type hints in docblocks; scalar hints where PHP 7.4 allows.
- Escape on output, sanitize on input, and never rely on one to substitute for the
  other. `Data_Redactor` removes markup at rest **and** the output layer escapes.
- Comments explain *why*. A comment restating the code is noise.

## 6. Adding a REST argument

1. Declare `type`.
2. Add a `validate_callback` for a scalar, or a size bound for a structure.
3. Add a `sanitize_callback` for a scalar. **Not** for a structure: a text sanitizer
   would destroy what the phase needs to read.
4. For a list, add a purpose-built sanitizer that preserves the shape.
5. For an identifier, add a shape validator so a malformed value cannot probe
   storage.

Then verify, rather than assume:

```php
php verify-rest-args.php <wp-root>
```

It reports any argument with no validation of any kind.

## 7. Adding a writable property

`Correction_Property_Map` is the security boundary. A property that is not in it
cannot be written, and the browser cannot add one.

An entry declares: the property name, the control, the value shape, the device
policy, the level, and the batch. `read_control()` and `write_control()` decide
whether a value belongs in `settings` or in `_elementor_responsive`, and no caller
chooses.

After adding one, the property appears in the plan, the review table, and the
history. It is `blocked` if the resolved element type has no control for it, which
is enforced rather than left to the apply step to refuse.

## 8. Adding a migration

1. Bump `Schema::DB_SCHEMA_VERSION`.
2. Append an entry to `Migrator::migrations()` with `from`, `to`, `run`, `summary`.
3. Make `run()` idempotent. It will be called again.
4. Carry old data forward, or leave the record readable as it was and report it.
5. Add an assertion in `maintenance-contract-test.php`.

A migration must never discard user data, and it must never block site load. A
throw is caught, recorded, and reported.

## 9. Adding a job stage

1. Add the name to `Job_Limits::STAGES` in order.
2. Add a weight to `Job_Limits::stage_weights()`. The weights should total 100.
3. Add a `case` in `Job_Runner::process()` and a `stage_*` method.
4. Store bulky results with `Job_Repository::set_payload()` and read them with
   `get_payload()`. Do not put a large value in the checkpoint.
5. Advance with `Job_Queue::advance()`, passing the result of the stage being
   **left**, because a checkpoint is keyed by the stage that produced it.

## 10. Verification before claiming anything

```powershell
# 1. Syntax
Get-ChildItem -Filter *.php -Recurse | ForEach-Object { php -l $_.FullName }

# 2. Boot
php boot-check.php <wp-root>

# 3. Every suite
powershell -ExecutionPolicy Bypass -File run-all-tests.ps1

# 4. REST argument coverage
php verify-rest-args.php <wp-root>

# 5. Cleanup behaviour
php report-cleanup.php <wp-root>

# 6. Storage footprint
php report-ttls.php <wp-root>
```

A claim is not made unless a test asserts it, or the code path was executed and the
output read.

## 11. Debugging

`WP_DEBUG` true records `debug` level in the log. Set `log_level` in
`replicaforge_settings` to `debug` for a specific investigation.

Every log entry carries a `request_id`, and `Request_Context` propagates job,
generation, and validation ids into it. Filter by that id to see everything one
request did.

`Request_Context::reset()` clears it between jobs and in tests.

---

## Phase 10: extending the commercial layer

Everything below is a public extension point. None of them exposes an internal
implementation detail that a third party would need to reach past.

### Changing the plan set

```php
add_filter( 'replicaforge_plan_definitions', function ( array $plans ): array {
    $plans['studio'] = array(
        'name'        => 'Studio',
        'description' => 'For a small studio.',
        'limits'      => array(
            'analysis_per_period'  => 200,
            'generation_per_period' => 50,
            'monitored_projects'   => 10,
        ),
        'features'    => array(
            'basic_analysis'       => true,
            'elementor_generation' => true,
            'visual_validation'    => true,
            'monitoring'           => true,
        ),
    );
    return $plans;
} );

// Required. A cache does not watch for a filter being added, and this runs at boot
// so the flush happens before anything reads the set.
add_action( 'init', array( '\ReplicaForge\Plan_Storage', 'flush_cache' ), 1 );
```

**Only the five editable keys are read**: `name`, `description`, `limits`,
`features`, and the plan id as the array key. Anything else is dropped.

A limit name that is not in `Plan_Limits::LIMIT_NAMES` is dropped, and a plan
missing a limit reads that limit as **unlimited** — check the names against the
constant rather than assuming.

An override **replaces** the shipped plan, it does not merge with it. Restate every
limit you want to keep.

### Assigning plans by your own rules

```php
add_filter( 'replicaforge_resolved_plan', function (
    \ReplicaForge\Plan_Definition $plan,
    int $user_id,
    \ReplicaForge\License_State $state
): \ReplicaForge\Plan_Definition {
    if ( user_is_in_studio( $user_id ) ) {
        return \ReplicaForge\Plan_Storage::get( 'studio' );
    }
    return $plan;
}, 10, 3 );
```

Returning a `Plan_Definition` replaces the resolved plan; returning anything else
leaves the resolution alone.

This is deliberately **not** `replicaforge_feature_entitlement`. One name meaning
two different things across two layers is a trap: a filter added to grant a feature
would silently replace plans instead.

### Granting or withholding a single feature

```php
add_filter( 'replicaforge_feature_entitlement', function (
    bool $granted,
    string $feature,
    \ReplicaForge\Plan_Definition $plan,
    int $user_id
): bool {
    if ( 'monitoring' === $feature && current_user_can( 'manage_network' ) ) {
        return true;
    }
    return $granted;
}, 10, 4 );
```

Returning a boolean replaces the answer; returning anything else leaves the plan's
own answer in place.

### Charging usage yourself

If you add an operation that consumes allowance, add its name to
`Plan_Limits::OPERATIONS` and to `OPERATION_FEATURES`, and use the lifecycle:

```php
$begin = $entitlements->begin( 'my_operation', $user_id, array(
    'project_id' => $project_id,
    'metadata'   => array( 'result' => 'ok' ),
) );

if ( empty( $begin['allowed'] ) ) {
    return new WP_Error( $begin['code'], $begin['message'], array( 'status' => $begin['status'] ) );
}

// ... do the work, which may throw or return early ...

if ( $succeeded ) {
    $entitlements->settle( $user_id, $begin['reservation'] );
} else {
    $entitlements->fail( $user_id, $begin['reservation'], 'upstream_timeout' );
}
```

The metadata allowlist is four keys: `outcome`, `result`, `reason`, `source`. Any
other key is dropped, and every value is truncated to 80 characters. Do not put a
URL, a prompt, or a response in there — the allowlist will not stop you from trying
and the retention will.

`Entitlement_Manager::check()` — without the reservation — is for deciding whether
to *show* an action. Do not use it to decide whether to *run* one: its answer can
go stale, which is the race `begin()` exists to close.

### Listening for usage

```php
add_action( 'replicaforge_usage_recorded', function (
    int $user_id,
    string $operation,
    int $quantity,
    string $plan_id
): void {
    // Your own accounting. The plugin's own counters are already updated.
}, 10, 4 );
```

### Connecting a licensing provider

```php
final class My_Licensing_Provider implements \ReplicaForge\License_Provider_Contract {
    public function id()   { return 'my_server'; }
    public function label(){ return 'My licensing server'; }
    public function is_remote() { return true; }
    public function state() {
        // Must not throw. A provider that throws is treated as `unknown`, which
        // resolves to the free plan.
        return \ReplicaForge\License_State::make(
            \ReplicaForge\License_State::ACTIVE,
            'verified',
            $expires_at,
            $reference
        );
    }
}
add_filter( 'replicaforge_license_provider', fn() => new My_Licensing_Provider() );
```

Two rules:

- **Never return a plan id.** Return a state. The interface has no method that
  could carry one, and adding one would break the property the plan system rests
  on.
- **Never throw.** Catch and return `License_State::unknown()`. A licensing
  outage must not take generation down with it.

With a remote provider active, `POST /license` returns `409
license_not_configurable` — a provider that answers from a server cannot also
accept an answer from a browser.

Cache the state. `state()` is called several times per dashboard render.

### Connecting a billing provider

```php
final class My_Billing_Provider implements \ReplicaForge\Billing_Provider_Contract {
    public function id()    { return 'my_billing'; }
    public function label() { return 'My billing service'; }
    public function is_configured() { return (bool) get_option( 'my_billing_key' ); }
    public function is_remote()     { return true; }
    public function diagnostics()   { return array( 'reachable' => true ); }
    public function sellable_plan_ids() { return array( 'starter', 'pro', 'agency' ); }
}
add_filter( 'replicaforge_billing_provider', fn() => new My_Billing_Provider() );
```

With a provider present, every upgrade prompt stops saying that billing is not
configured. Nothing else changes.

**Write side effects belong in a separate class.** The interface deliberately has
no `charge()`, `create_customer()`, or `cancel_immediately()`. A provider reads; a
separate, later, separately reviewed module would write. Keep it that way and the
interface stays safe to ship before there is anything real to talk to.

### Extending the audit log

Add the event to `Audit_Log::EVENTS` with the context keys it may carry:

```php
const EVENTS = array(
    // ...
    'bulk_plan_assigned' => array( 'plan_id', 'count', 'by' ),
);
```

An event that is not declared is dropped **and logged as a warning**, so a typo is
visible rather than silent. Declaring the keys is what stops a caller smuggling a
value into a declared event under an innocent key.

To add a field of your own, use the filter — the entry is already sanitised by the
time it arrives:

```php
add_filter( 'replicaforge_audit_entry', function ( array $entry, string $event ): array {
    $entry['request_id'] = \ReplicaForge\Request_Context::request_id();
    return $entry;
}, 10, 2 );
```

### Gating your own code

```php
$entitlements = new \ReplicaForge\Entitlement_Manager();
$check        = $entitlements->check( 'generation', $user_id, array( 'project_id' => $project_id ) );

if ( empty( $check['allowed'] ) ) {
    return new WP_Error( $check['code'], $check['message'], array( 'status' => $check['status'] ) );
}
```

`Entitlement_Manager` is constructed rather than fetched: there is no singleton,
because the phase's rule is that a caller passes the user id explicitly, and a
singleton that read `get_current_user_id()` internally would be untestable for a
different user. Wire it through your own service container if you have one.

If you are writing a screen rather than an endpoint, use `Feature_Gate::state()`,
which returns the decision plus the wording to show. Do not add checks to
`Feature_Gate` — it is a presentation layer, and logic added there is logic that
will disagree with the endpoint.

### Project status and health

```php
\ReplicaForge\Project_Status::of( $project );        // current status
\ReplicaForge\Project_Status::health( $project );    // derived health + reasons
\ReplicaForge\Project_Status::quality( $project );   // per-group metrics
\ReplicaForge\Project_Status::timeline( $project );  // from the record that exists
\ReplicaForge\Project_Status::actions( $project, $gate, $user_id );
```

`health()` is a precedence chain, most urgent first. A project that failed to
generate and also has pending corrections reports the failure, because the
corrections are about a document that does not exist.

If you write a signal that health should read, add it to the relevant `*_signal()`
method. Note that `sync_signal()`, `unreachable_signal()`, and
`manual_change_signal()` already read signals that **nothing writes yet**, because
Phase 9 built detection and classification but no monitor. They are wired so that
when a monitor arrives the health rule is already correct, rather than needing the
rule written afterwards.

Do not add a numeric threshold to `health()`. Phase 5 scores with *bands*
(`Validation_Limits::TOLERANCES`), not with a value on a known scale, so there is no
declared floor to compare against. Inventing one would mark healthy replicas as
unhealthy on a different installation's numbers. The only health signal derived
from a score is the presence of a `critical` or `major` difference, both of which
Phase 5 declares.

### Onboarding

```php
\ReplicaForge\Onboarding::tours();                    // add a tour here
\ReplicaForge\Onboarding::active_tours( $moment, $user_id );
\ReplicaForge\Onboarding::moment_for( $project );
```

A tour belongs to exactly one moment, and a dismissed tour does not come back at
that moment again.

**Do not add a call into the analysis or generation pipeline from this class.** §3
of the brief requires that a fresh activation does not analyze or generate
anything, and the strongest way to honour that is for the onboarding code to
contain no such call at all — so adding one has to be a deliberate act in a file
whose whole purpose is to not do it.

---

## Phase 11: extending the reliability and AI layers

### Declaring what your provider and model can do

```php
// Whole plan set, in one filter.
add_filter( 'replicaforge_model_capabilities', function (
    array $caps, string $provider_id, string $model
): array {
    if ( 'openai' === $provider_id && 'my-private-model' === $model ) {
        $caps['context']            = 400000;
        $caps['output']             = 64000;
        $caps['structured_output']  = true;
        $caps['json_schema']        = true;
        $caps['vision']             = false;
        $caps['cost_in']            = 3.00;   // an estimate, for planning only
        $caps['cost_out']           = 15.00;
    }
    return $caps;
}, 10, 3 );
```

The return is rebuilt through the same clamp, so a filter cannot produce a context of
zero or a negative cost. It also cannot raise the **requestable** context past
`Ai_Limits::MAX_CONTEXT_BYTES` — that ceiling protects the site rather than describing
the model.

### Writing a provider adapter

```php
final class My_Provider extends \ReplicaForge\Ai_Provider_HTTP {
    public function get_id() { return 'my_provider'; }

    // Inherited from the base. Override only the key you differ on.
    public function capabilities() {
        $caps = parent::capabilities();
        $caps['batch'] = false;
        return $caps;
    }
}
```

Then declare what it supports in `Ai_Capabilities::providers()` — through a filter if
you cannot edit the plugin.

**Never return a plan id or a capability from `analyze()`.** Return content or an
error, and let `Ai_Failures` classify the error. A provider that decides its own
failure semantics is a provider whose failures the scheduler cannot reason about.

### Classifying a failure in your own code

```php
use ReplicaForge\Ai_Failures;

$failure = Ai_Failures::from_http( $status, $body, $headers, $transport_error, 'my_provider' );

// Never compose a message from $body. Use the three layers it returned.
return array(
    'success' => false,
    'code'    => $failure['code'],
    'message' => $failure['user_message'],          // to the user
    'retry_after' => $failure['retry_after'],      // to the scheduler
    'log'     => $failure['technical_message'],    // to the log
);
```

If you need a new classification, add it to `Ai_Failures::CODES` **and** to
`RETRYABLE`, and to `FALLBACK_WORTHY` if a fallback would actually help. An undeclared
code is treated as not retryable, so a new code defaults to the safe answer.

### Adding a metered operation

```php
use ReplicaForge\Plan_Limits;

const OPERATIONS = array( /* ... */, 'my_operation' );
const OPERATION_FEATURES = array( /* ... */, 'my_operation' => 'some_feature' );
const LIMIT_NAMES = array( /* ... */, 'my_operation_per_period' );
const FEATURES = array( /* ... */, 'some_feature' );
```

Then charge it through the lifecycle, so a failed run costs nothing:

```php
$begin = $entitlements->begin( 'my_operation', $user_id, array( 'project_id' => $project_id ) );
if ( empty( $begin['allowed'] ) ) { /* refused */ }

$succeeded ? $entitlements->settle( $user_id, $begin['reservation'] )
           : $entitlements->fail( $user_id, $begin['reservation'], 'my_failure' );
```

### Locking a resource of your own

```php
$locks = new \ReplicaForge\Job_Lock();

$lock = $locks->acquire( 'report_daily_' . $user_id, $owner );
if ( empty( $lock['success'] ) ) {
    // Somebody else is in here. Do not wait; defer.
}

// ... work ...

$locks->release( 'report_daily_' . $user_id, $lock['token'] );
```

Or use `with_lock()`, which releases on every exit including an exception:

```php
$result = $locks->with_lock( 'report_daily_' . $user_id, function () use ( $data ) {
    return $this->build( $data );
}, $owner );
```

**Take a lock when the resource is per-job, and use the job id as the owner.** A lock
whose owner is the same process but a different job is refused, which is what stops
two jobs in one request from both believing they are inside.

### Writing a checkpoint

```php
$checkpoint = Job_Checkpoint::merge( $stored, array(
    'stage'            => 'generate',
    'completed_stages' => array( 'analyze', 'design', 'ai' ),
    'draft_id'         => $draft_id,
    'design_hash'      => $hash,
) );
```

Three rules are enforced for you: only a declared stage is recorded, the stage never
moves backwards, and the result is bounded. Report **only** stages that actually
completed — do not add the stages in between, because a checkpoint that claims a
skipped stage finished is a checkpoint that will make recovery treat a broken run as
a good one.

### Being cancellable

Check at a stage boundary, not mid-write:

```php
if ( ! $cancellations->may_proceed( $job_id ) ) {
    $queue->cancel( $job_id );
    $locks->release( $lock_key, $lock['token'] );
    return;
}
```

Never check cancellation inside an Elementor write. A cancellation honoured halfway
through produces the half-written document this whole layer exists to prevent.

### Recording an AI call

```php
Ai_Usage_Audit::record( $provider_id, $model, 'analysis', 'success', array(
    'user_id'      => $user_id,
    'project_id'   => $project_id,
    'job_id'       => $job_id,
    'input_bytes'  => $bytes,
    'output_bytes' => $bytes,
    'attempts'     => $attempts,
) );
```

**Do not put the prompt, the context, or the response in the context array.** The
allowlist would drop most of it and the ones it admits are not where a prompt belongs.
The context already lives in the project's own data with its own retention.

### Hooks

| Hook | Type | Fires |
|---|---|---|
| `replicaforge_model_capabilities` | filter | After a provider and model resolve. |
| `replicaforge_job_lock_acquired` | action | A resource lock is taken. |
| `replicaforge_job_lock_released` | action | A resource lock is released. |
| `replicaforge_job_cancel_requested` | action | A cancellation is recorded. |
| `replicaforge_job_expired` | action | A job is expired after a worker died mid-write. |
| `replicaforge_ai_usage_recorded` | action | An AI call is recorded. |
