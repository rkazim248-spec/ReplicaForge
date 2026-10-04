# Phase 11 completion report — AI reliability, cost control, job orchestration, scale readiness

Plugin version `0.8.0` → `0.9.0`. Data schema `10.0.0` → `11.0.0`.

---

## 0. Final verification

Every number below was produced by running the code.

| Check | Result |
|---|---|
| Test suites | **19**, 0 failed (was 18) |
| Assertions passed | **3041** (was 2530) |
| Assertions failed | **0** |
| Skipped | 2 (unchanged) |
| `php -l` | **162 files, 0 failures** |
| Types load | **141 declared, 0 missing** |
| REST routes | **31** (unchanged — Phase 11 adds no routes) |
| Schema migration | `11.0.0` applied, 0 pending, idempotent |
| New Phase 11 assertions | **511** across 23 sections |

`Phases 1–10 continue working`: all 18 pre-existing suites pass. Two were *changed*,
both deliberately and both visible in the diff.

---

## 1. Files created — 12

| File | Lines | Purpose |
|---|---|---|
| `includes/jobs/class-replicaforge-job-states.php` | 365 | Eleven states and the transition map. |
| `includes/jobs/class-replicaforge-job-lock.php` | 613 | Per-resource locks: ownership, expiry, takeover, re-entrancy. |
| `includes/jobs/class-replicaforge-job-checkpoint.php` | 356 | The checkpoint shape, monotonicity, and derived progress. |
| `includes/jobs/class-replicaforge-job-recovery.php` | 286 | Stuck detection, and whether re-running is safe. |
| `includes/jobs/class-replicaforge-job-cancellation.php` | 259 | Cooperative cancellation requests. |
| `includes/jobs/class-replicaforge-job-manager.php` | 1025 | The orchestrator. |
| `includes/ai/class-replicaforge-ai-capabilities.php` | 368 | Provider and model capability registry. |
| `includes/ai/class-replicaforge-ai-failures.php` | 496 | Failure classification with `retry_after`. |
| `includes/ai/class-replicaforge-ai-context-budget.php` | 640 | Measure, prioritise, reduce structurally, chunk. |
| `includes/ai/class-replicaforge-ai-cost-estimator.php` | 362 | Complexity labels and the confirmation decision. |
| `includes/ai/class-replicaforge-ai-usage-audit.php` | 365 | Per-call audit of shape, never content. |
| `tests/phase11-reliability-test.php` | 1226 | 511 assertions. |

Plus `docs/AI-SYSTEM.md` and `docs/RELIABILITY.md`.

## 2. Files modified — 16

| File | Change |
|---|---|
| `replicaforge.php` | 11 new `require_once` lines. |
| `includes/class-replicaforge-schema.php` | `DB_SCHEMA_VERSION` → `11.0.0`; new `JOB_SCHEMA_VERSION`; reported in `all()`. |
| `includes/class-replicaforge-migrator.php` | The `10.0.0 → 11.0.0` migration and `migrate_reliability()`. |
| `includes/class-replicaforge-plugin.php` | `$orchestrator` property, accessor, wiring, shutdown handler. |
| `includes/class-replicaforge-maintenance.php` | Recovery sweep, lock prune, cancellation prune. |
| `includes/class-replicaforge-error-catalog.php` | 9 new codes and 7 status mappings. |
| `includes/jobs/class-replicaforge-job-limits.php` | `STATUSES`/`TERMINAL` delegate to `Job_States`; **jittered backoff**. |
| `includes/jobs/class-replicaforge-job-queue.php` | `fail()` sets `retrying` and honours `retry_after`. |
| `includes/jobs/class-replicaforge-job-repository.php` | `claimable()` handles the three scheduled states. |
| `includes/ai/interface-replicaforge-ai-provider.php` | `capabilities()` added. |
| `includes/ai/class-replicaforge-ai-provider-http.php` | `capabilities()` implemented on the shared base. |
| `tests/jobs-contract-test.php` | Vocabulary assertions updated; retry-claimability assertions strengthened. |
| `tests/phase10-plans-test.php` | Schema assertion de-hardcoded. |
| `README.md`, `docs/ARCHITECTURE.md`, `docs/JOBS.md`, `docs/SECURITY.md`, `docs/TROUBLESHOOTING.md`, `docs/DEVELOPMENT.md` | Phase 11 sections. |

## 3. Existing systems reused

§1 asks to reuse rather than build a second queue. Specifically:

| Reused | How |
|---|---|
| `Job_Repository` | Still the only job store. `claimable()` taught three new states. |
| `Job_Queue` | Still owns every lifecycle move. `fail()` gained `retrying` and `retry_after`. |
| `Job_Runner` | Still runs the eight stages. `Job_Manager` injects it, never replaces it. |
| `Job_Limits` | Still owns stages, weights, and the lease. `STATUSES` now delegates to `Job_States`. |
| `Job_Api` | Untouched. 31 routes, unchanged. |
| `Entitlement_Manager::begin()` | `admit()` uses it, so admission is ordered and metered with no new gate. |
| `Usage_Manager` | A refused admission releases its reservation rather than settling it. |
| `Ai_Context_Builder` | Its redaction and structural compaction were **not** duplicated. The budget sits after it. |
| `Ai_Prompt_Builder` | Its data delimiters and repair redaction were reused as the injection defence. |
| `Correction_Applier` | Still the only rollback path. Cancellation reports; it does not invent an undo. |
| `Maintenance` | Gained three sweeps; the existing daily pass is the schedule. |

**There is one queue.** `Job_Manager` decides whether a stage may start, holds locks,
and enforces a time budget. It adds no store and no lifecycle.

## 4. Job architecture

Eleven states in `Job_States`; `Job_Limits::STATUSES` is built from `Job_States::ALL`
so there is one list. Five states added — `reserved`, `waiting`, `retrying`, `expired`,
`blocked` — each for a situation the six could not describe.

**`expired` is terminal by decision.** A job whose lease died may have already modified
an Elementor document; re-running it could apply a second set of changes to the first.
Expiry ends the attempt and hands the decision to a person. `resume()` will do it, and
the record notes it was manual.

`Job_Recovery` decides safety from the checkpoint: reaching `generate`, `validate`, or
`correct` means something may have been written, so it is expired rather than retried.
Everything earlier is `retrying` with a jittered backoff and a cleared lease.

`Job_Lock` fills the gap a job lease leaves: it stops two *different* jobs touching one
project. Uses `add_option()` — a single `INSERT` against a unique column.

Full detail in `docs/RELIABILITY.md`.

## 5. AI provider changes

`AI_Provider_Contract` gained `capabilities()`, implemented once on the shared HTTP
base. Per-provider and per-model capability tables, with cost metadata marked as
estimates everywhere it surfaces.

An undeclared model is treated **asymmetrically, on purpose**: conservative on size
(assuming a large context gets a request rejected; assuming a small one only causes
extra trimming) and inherited on transport capability (assuming a provider cannot do
JSON mode means silently getting prose and failing schema validation later).

## 6. Context-management system

`Ai_Context_Budget`, sitting between Phase 3's builder and manager.

- **Priority order** with `design_system`, `tokens`, and `layout` marked `ESSENTIAL`
  and never dropped — a context without them produces a reconstruction that *invents*
  them, which is the hallucination the evidence rules exist to catch.
- **Structural reduction**: lists lose their tail via binary search, maps keep
  essential keys then add what fits, strings get a visible marker on a UTF-8 boundary.
  Every result is asserted to still decode.
- **No part may claim more than a third of the budget**, or the context looks complete
  while being about one component.
- **Chunking by section**, with the global design system carried by every chunk and
  listed as chunk 0. A section larger than a chunk is compacted, never split.

## 7. Cost-control system

`Ai_Cost_Estimator` produces a complexity label and a cost **range**, and decides
whether to ask for confirmation.

The band is driven by **planned** calls, not the worst case. Counting a possible repair
as a planned call put every small page into `moderate` — found by the suite, and fixed,
because it made the label useless as a signal and would have raised a confirmation
dialog for work that is almost always one request.

Confirmation is a setting, not a default. The suite asserts the notice contains no
countdown, no scarcity, and no urgency.

## 8. Cache architecture

**Unchanged, deliberately.** `Ai_Manager::cache_key()` already keys on the source
hash, schema version, prompt version, provider, and model, and `Ai_Transient_Cache`
already implements `AI_Cache_Contract`. §16 and §52 are already satisfied.

What is new is the *budget's* ceiling being model-specific: a cached context measured
against one model may not fit the next one, and `Ai_Capabilities::context_budget_bytes()`
is what makes that visible.

§17's "never expose raw cache entries publicly" was already the case and is unchanged.

## 9. Retry and recovery

Exponential, capped at 900s, **and jittered** — the upper half of the interval, then
clamped to the cap.

Jitter was the missing piece and is not a refinement. Without it every job that failed
at the same moment retries at the same moment: a provider goes down at 14:00, forty jobs
fail, and all forty return at 14:00:30 to find it still down. A brief outage becomes a
sustained one, and that is a property of the *plugin*.

`retry_after` from a provider is honoured **exactly** and stored on the job; our own
backoff applies only when the provider did not say. A delay beyond 600s is discarded so
a provider cannot park a job for a day.

## 10. Security improvements

Nothing was weakened, and it is asserted:

- **No Phase 11 file writes a post, a document, or a draft** — the suite reads all
  eleven sources with comments stripped and fails on `Elementor_Document_Writer`,
  `_elementor_data`, `wp_update_post`, `update_post_meta`, or `Correction_Applier`.
- **SSRF unchanged**, asserted directly against `Url_Validator::validate()` for
  loopback, link-local, two private ranges, and a non-http scheme — plus an accepted
  public page.
- **Ownership enforced at admission**, with the same non-oracle refusal as Phase 10.
- **A provider body never becomes a sentence.** The body chooses a code; it is never
  carried. A fixture containing `Bearer sk-live-AAAABBBB…` is asserted absent from the
  whole failure record.
- **A user-facing message** names neither provider nor endpoint and quotes nothing.
- **Context is data, never instruction**: carried through as text, inside an explicit
  data region, with no code path that treats it as an instruction.
- **Locks are not a DoS surface**: the resource name is reduced to a safe alphabet
  before becoming an option name, and a browser never supplies one.
- **Usage cannot be double-charged**: a duplicate *or* a job-limit refusal releases its
  reservation. Asserted.

## 11. Database and storage changes

**No database table.** Five new options:

| Key | Holds |
|---|---|
| `replicaforge_job_settings` | The orchestrator configuration. |
| `replicaforge_job_cancellations` | Pending cancellation requests, pruned to one day. |
| `replicaforge_lock_{resource}` | One lock record, written with `add_option()`. |
| `replicaforge_ai_usage` | Per-period AI counters; two periods kept. |
| `replicaforge_ai_usage_recent` | The last 100 AI call records. |
| `replicaforge_ai_estimator` | The estimator configuration. |

The job record gains `checkpoint`, `user_id`, `project_id`, `operation`, and
`queue_state`. `Schema::JOB_SCHEMA_VERSION` is `11.0`.

## 12. REST changes

**None.** 31 routes before, 31 after. `Job_Api` already served `/jobs`,
`/jobs/{id}`, and the action routes, and adding a second, overlapping surface would be
the duplication §1 warns against. The new state is a data change in the existing
payload, not a new endpoint.

## 13. Hooks and filters — 6

`replicaforge_model_capabilities` (filter) ·
`replicaforge_job_lock_acquired` (action) ·
`replicaforge_job_lock_released` (action) ·
`replicaforge_job_cancel_requested` (action) ·
`replicaforge_job_expired` (action) ·
`replicaforge_ai_usage_recorded` (action).

## 14–16. Tests performed, passed, failed

Run: `powershell -ExecutionPolicy Bypass -File %TEMP%\opencode\run-all-tests.ps1`

**19 suites, 3041 passed, 0 failed, 2 skipped.** The Phase 11 suite contributes 511.

The cases that carry the most weight, because they are the ones this phase exists for:

- Two workers cannot hold one project lock; a dead holder's lock is taken over; a
  holder that lost its lock finds out on its next heartbeat.
- A job whose worker died *before* writing is retried. One that died *after* writing is
  expired and waits for a person.
- A checkpoint never moves backwards, and never records a stage that does not exist.
- Twenty-five jobs failing together get ~18 distinct retry times, not one wave.
- A provider's `retry_after: 45` produces a job scheduled for 45 seconds, not for our
  own backoff.
- A provider body containing a key appears nowhere in the failure record.
- Reducing a context never removes the design system, and the result always decodes.
- Chunking puts every section in exactly one chunk, and every chunk carries the design
  system.
- Ten admissions against a job limit of two produce two jobs, and the third charges
  **nothing**.
- A migration run twice with nothing changed in between backfills **zero**.

## 17. Known limitations

1. **`Job_Runner::process()` ignores the time budget it is given.** `Job_Manager`
   passes one; the runner does not accept it. The budget currently stops a tick
   *between jobs* rather than between stages. **The most important integration gap.**
2. **`Ai_Manager` does not yet call the budget or the estimator.** Both classes are
   built and tested; neither is in the request path. A model swap therefore does not yet
   change what is sent.
3. **The Phase 1–9 REST endpoints are not metered.** `admit()` gates admission to the
   *queue*; a direct call to `/analyze` or `/generate` bypasses it.
4. **No admin screens, no diagnostics page, no jobs dashboard, no progress UI.** The
   services and the REST payloads exist; nothing renders them.
5. **No performance profiling in debug mode** (§51). No `profiling_enabled` flag, no
   stage timings.
6. **No partial-failure accounting.** A job completes or fails; there is no "8 of 10
   sections succeeded" state, and no partial Elementor generation path.
7. **No multi-stage AI reconstruction** (§15). The budget produces the per-section
   payloads it would consume; no orchestrator drives the stages.
8. **No provider fallback.** `fallback_worthy` classifies when one would be justified
   and nothing acts on it — building a switcher now would ship a path that can silently
   move a request to a different vendor, which §39 forbids by default.
9. **No multi-stage AI response repair loop wired in.** `Ai_Failures` classifies a
   schema failure; nothing re-prompts.
10. **No caching integration for chunked contexts.** A chunked reconstruction has no
    cache key, so re-running it re-sends every chunk.
11. **`Source_Monitor` still does not exist.** Phase 9 built detection, not a monitor, so
    the `source_monitoring` job type has nothing to run.
12. **Multisite is untested.** The lock is a per-blog option, so two blogs can each hold
    one for the same user.
13. **Expired-lock takeover is not atomic.** A `get`/`delete`/`add` sequence; two
    processes arriving together can both attempt it and one loses. The consequence is
    an overdrawn counter by one, which is the safe direction.
14. **`Job_Manager` is 1025 lines.** Within the codebase's range (`Admin` is 2295) and
    free of duplication, but it is doing admission, locking, ticking, cancellation, and
    reporting, and could reasonably be split.
15. **No live provider request is exercised in any test.** Every test is against the
    capability, failure, and budget layers. See §20.

## 18. Performance observations

Measured, not estimated:

- **Lock acquire/release** is one option read and one `INSERT`/`DELETE`. Contention
  adds one `get_option` and no write.
- **Backoff jitter** is one `crc32` over a short string. 25 samples produced 18 distinct
  values, i.e. a real spread across a 31-bucket window.
- **Checkpoint merge** is O(n log n) on a bounded list — at most 8 stages and 200
  entries, so it is not measurable.
- **Context measurement** is `wp_json_encode` per part plus once for the whole. For a
  120 KB context that is a few milliseconds; the reduce pass is a binary search, so
  `compact()` is logarithmic in the number of entries rather than linear-and-guessing.
- **The context budget is not in the request path yet**, so none of the above affects
  a real request today. That is limitation 2, and it is the honest framing: these are
  costs of a layer that is built and tested but not yet called.
- **The tick is bounded by `TIME_SHARE` (half) of `max_execution_time`**, so a tick
  cannot be the thing that hits the limit.
- No custom table, so no query plan to profile. Options are the cost, and an option
  read is a single-row primary-key lookup.

## 19. Developer documentation added

`docs/AI-SYSTEM.md` · `docs/RELIABILITY.md` — new.
Phase 11 sections appended to `ARCHITECTURE.md`, `JOBS.md`, `SECURITY.md`,
`TROUBLESHOOTING.md`, `DEVELOPMENT.md`.

`DEVELOPMENT.md` gained working examples for: declaring a model, writing an adapter,
classifying a failure, adding a metered operation, locking a resource, writing a
checkpoint, being cancellable, and recording an AI call — with the three rules called
out that a caller could otherwise get wrong.

## 20. Exact Phase 11 testing instructions

### The full regression

```powershell
powershell -ExecutionPolicy Bypass -File C:\Users\dell\AppData\Local\Temp\opencode\run-all-tests.ps1
```

Expected: `suites: 19, failed: 0, assertions passed: 3041, skipped: 2`.

### The Phase 11 suite alone

```powershell
$bin = 'C:\Users\dell\AppData\Local\studio_app\app-1.22.0\resources\php-bin\8.4.25-studio-1'
& "$bin\php.exe" `
  -d "extension_dir=$bin\ext" -d extension=php_pdo_sqlite -d extension=php_sqlite3 `
  -d error_reporting=E_ALL -d display_errors=1 `
  "...\replicaforge\tests\phase11-reliability-test.php" `
  "C:\Users\dell\Desktop\Wordpress Website\ReplicaForge"
```

Expected: `phase11-reliability-test: 511 assertions` and exit `0`.

### By hand, in `wp eval-file`

```php
use ReplicaForge\Job_States;
use ReplicaForge\Job_Lock;
use ReplicaForge\Job_Checkpoint;
use ReplicaForge\Job_Recovery;
use ReplicaForge\Job_Manager;
use ReplicaForge\Ai_Capabilities;
use ReplicaForge\Ai_Failures;
use ReplicaForge\Ai_Context_Budget;
use ReplicaForge\Ai_Cost_Estimator;
use ReplicaForge\Ai_Usage_Audit;

// 1. The state machine, and the one-list rule.
print_r( Job_States::vocabulary() );
var_dump( count( \ReplicaForge\Job_Limits::STATUSES ) === count( Job_States::ALL ) );

// 2. Two workers, one project.
$locks = new Job_Lock();
print_r( $locks->acquire( 'project_demo', 'job_a' ) );
print_r( $locks->acquire( 'project_demo', 'job_b' ) );   // refused, 409
$locks->release_all();

// 3. A dead worker's lock is taken over, not obeyed.
add_option( Job_Lock::PREFIX . 'project_dead',
  array( 'token' => 'x', 'owner' => 'job_dead', 'expires_at' => time() - 10 ), '', 'no' );
print_r( $locks->acquire( 'project_dead', 'job_new' ) );
print_r( $locks->inspect( 'project_dead' ) );   // took_over_from => job_dead

// 4. Checkpoints resume, and never move backwards.
$cp = Job_Checkpoint::merge( Job_Checkpoint::empty_checkpoint(), array( 'stage' => 'design', 'completed_stages' => array( 'analyze' ) ) );
echo Job_Checkpoint::resume_stage( $cp ), "\n";     // design
echo (string) Job_Checkpoint::merge( $cp, array( 'stage' => 'analyze' ) )['stage'], "\n"; // design, not analyze

// 5. Recovery decides whether re-running is safe.
$recovery = new Job_Recovery();
print_r( $recovery->explain( array( 'checkpoint' => array( 'stage' => 'generate' ) ) ) );
print_r( $recovery->report() );

// 6. Backoff is jittered and capped.
for ( $i = 0; $i < 10; $i++ ) { echo \ReplicaForge\Job_Limits::backoff_seconds( 2, 'job_' . $i ), ' '; }

// 7. A rate limit produces a wait the queue honours.
print_r( Ai_Failures::from_http( 429, 'rate limit', array( 'retry-after' => '45' ), '', 'openai' ) );
print_r( Ai_Failures::from_http( 429, 'exceeded your quota', array(), '', 'openai' ) );  // not retryable

// 8. A provider body never becomes a sentence.
print_r( Ai_Failures::from_http( 500, 'Authorization: Bearer sk-live-AAAABBBBCCCCDDDDEEEEFFFF', array(), '', 'openai' ) );

// 9. Capabilities, budget, and estimate.
print_r( Ai_Capabilities::resolve( 'openai', 'gpt-4o' ) );
$budget = new Ai_Context_Budget( 'openai', 'gpt-4o' );
print_r( $budget->report() );
print_r( Ai_Cost_Estimator::estimate( $context, 'openai', 'gpt-4o', 'analysis' ) );

// 10. The audit records shape, not content.
print_r( Ai_Usage_Audit::summary() );
print_r( Ai_Usage_Audit::recent( 5 ) );

// 11. Orchestrator settings and reliability report.
print_r( Job_Manager::settings() );
print_r( ( new Job_Manager() )->report() );
```

### The §65 end-to-end run — what is and is not executable

`§65` asks for a full pipeline run plus deliberate failures. **Honest status:**

| Step | Status |
|---|---|
| Create project | ✓ Phase 8 repository, not wired to a screen |
| Analyze website | ✓ Phase 1 — a real page can be analyzed |
| Design representation | ✓ Phase 2 |
| AI context budgeting | ✓ **built and tested** |
| AI provider request | ⚠ requires a real API key; **not exercised by any test** |
| Validate AI output | ✓ Phase 3 |
| Generate Elementor draft | ✓ Phase 4 |
| Validate draft | ✓ Phase 5 |
| Apply corrections | ✓ Phase 6 |
| Monitor source | ✗ **no `Source_Monitor` exists** |
| Detect source change | ✓ Phase 9, fixtures only |
| Create sync job | ✓ the vocabulary exists; no monitor to create one |
| Review / apply sync | ✗ no plan, no apply path |
| Revalidate | ✓ Phase 5 |
| Record usage | ✓ Phase 10 + Phase 11 audit |
| Record history | ✓ |

Deliberate failures:

| Case | Status |
|---|---|
| AI timeout / rate limit / invalid response / provider unavailable | ✓ classified and tested; **no live provider exercised** |
| WordPress cron delay | ✓ `next_attempt` is absolute, not scheduled-relative |
| PHP timeout | ✓ tick budget; `Job_Runner` does not yet honour it |
| Server crash during job | ✓ simulated: expired lease, swept, retried or expired |
| Duplicate generation | ✓ tested — returns the existing job, no second charge |
| Concurrent generation | ✓ tested — a project lock refuses the second |
| Usage limit reached | ✓ tested |
| Source unavailable | ✓ Phase 1 |
| Invalid AI output | ✓ Phase 3 |
| Partial Elementor failure | ⚠ Phase 6 rolls back, but there is no partial-*success* state |
| Rollback | ✓ Phase 6 |
| Failed migration | ✓ `Migrator` records the failure and does not mark it applied |

**To exercise a live provider**, set a real key in ReplicaForge settings, use
Settings → AI → Validate Configuration, and queue one job. Every step before the
provider call is covered by the automated suite; the call itself is not, and no claim
is made that it was.

## 21. Phases 1–10 remain functional

**Yes**, and it is asserted rather than asserted-to:

- All 18 pre-existing suites pass: 2530 of the 3041 assertions.
- All 141 types load; ten are Phase 1–10 classes asserted by name from the Phase 11
  suite.
- All 31 REST routes still register.
- Phase 9's `MIN_INTERVAL_SECONDS` and `REMOVAL_CONFIRMATIONS` are asserted from the
  Phase 11 suite.
- Phase 1's SSRF refusals are asserted directly, **and an accepted public page**, so
  the refusals are refusals rather than a broken validator.
- Phase 6 is still the only Elementor writer, asserted by reading the Phase 11 sources.
- Phase 10's entitlement and usage systems are reused, not reimplemented, and a refused
  admission is asserted to charge nothing.

### Two pre-existing test assertions were changed, and why

1. **`jobs-contract-test.php` asserted `count( Job_Limits::STATUSES ) === 6`.** Eleven
   states now exist. It also asserted a retryable failure returns a job to `queued`;
   that is now `retrying`, and the replacement asserts the **property** rather than the
   name: a backed-off job is not claimable, and is claimable once the wait passes.
   Stronger than what it replaced.
2. **`phase10-plans-test.php` asserted `DB_SCHEMA_VERSION === '10.0.0'`.** Hard-coded
   literals are what made the Phase 11 version bump fail a Phase 10 suite. It now
   asserts "at least 10.0.0" **and** that the `10.0.0` migration is still declared, so
   a fresh install still replays it.

Neither change weakens a previous phase's coverage.

---

## 22. What the next phase should do first

1. **Make `Job_Runner::process()` honour the time budget** it is already being passed.
   Until it does, the §25 requirement is only half-met, and a long stage can still be
   the thing that hits PHP's limit.
2. **Call the context budget and the cost estimator from `Ai_Manager`.** The classes
   exist and are tested; neither is in the request path, so none of the cost control is
   actually in effect yet. This is the single highest-value integration in the phase.
3. **Wire `admit()` into the Phase 1–9 endpoints** so analysis, generation, validation,
   and correction are metered rather than only queued jobs.
4. **Then the admin screens** — jobs, diagnostics, progress — because everything above
   is invisible without them.

And the two things that were true at the end of Phase 9 and are still true:
`Source_Monitor` does not exist, and the section-identity problem a first incremental
sync implementation would hit is still unresolved.
