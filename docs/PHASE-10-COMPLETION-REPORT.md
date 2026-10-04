# Phase 10 completion report — commercial productization, plans, usage, and professional UX

Plugin version `0.7.0` → `0.8.0`. Data schema `1.0.0` → `10.0.0`.

This report states what was built, what was deliberately not built, what was
found broken along the way, and exactly how to test it. Where a requirement was
not met, it says so and says why.

---

## 0. Final verification

Every number below was produced by running the code, not by reading it.

| Check | Result |
|---|---|
| Test suites | **18**, 0 failed (was 17) |
| Assertions passed | **2517** (was 1935) |
| Assertions failed | **0** |
| Skipped | 2 (unchanged) |
| `php -l` | **150 files, 0 failures** |
| Types load | **130 declared, 0 missing** |
| REST routes registered | **31** (was 20) |
| Schema migration | `10.0.0` applied, 0 pending |
| Orphaned capabilities | none |

`Phase 1–9 continue working`: all 17 pre-existing suites pass unchanged. Two of
them were *fixed*, not weakened — see §7 defects 5 and 6.

---

## 1. Files created — 19

| File | Lines | Purpose |
|---|---|---|
| `includes/plans/class-replicaforge-plan-limits.php` | 331 | The vocabulary: operations, features, limits, capabilities, period rules. |
| `includes/plans/class-replicaforge-plan-definition.php` | 305 | One plan as an inert value object. |
| `includes/plans/class-replicaforge-plan-storage.php` | 498 | Plan set and trial configuration. Option-backed, filterable, cached. |
| `includes/plans/class-replicaforge-usage-manager.php` | 1041 | Usage accounting, reservations, the mutual-exclusion lock. |
| `includes/plans/class-replicaforge-plan-manager.php` | 418 | The one answer to "what plan is this user on, and why". |
| `includes/plans/class-replicaforge-entitlement-manager.php` | 624 | The one gate. Five checks, fixed order. |
| `includes/plans/class-replicaforge-feature-gate.php` | 217 | Presentation layer over the gate. No logic. |
| `includes/plans/class-replicaforge-audit-log.php` | 354 | Bounded ring of security-relevant commercial events. |
| `includes/plans/class-replicaforge-onboarding.php` | 505 | Welcome and tour state. Triggers nothing. |
| `includes/plans/class-replicaforge-plans-api.php` | 934 | The Phase 10 REST surface. |
| `includes/licensing/interface-replicaforge-license-provider.php` | 77 | The licensing contract. No `activate()`. |
| `includes/licensing/interface-replicaforge-billing-provider.php` | 84 | The billing contract. **No implementation.** |
| `includes/licensing/class-replicaforge-license-state.php` | 322 | An immutable answer. A fact, never a plan. |
| `includes/licensing/class-replicaforge-local-license-provider.php` | 207 | The only provider. No network, no key, no activation. |
| `includes/licensing/class-replicaforge-license-manager.php` | 561 | License → plan → trial resolution. |
| `includes/class-replicaforge-capabilities.php` | 284 | Five capabilities, role grants, role report, orphan check. |
| `includes/class-replicaforge-project-access.php` | 295 | Who may read and change a project. |
| `includes/class-replicaforge-project-status.php` | 936 | One status vocabulary, transitions, health, quality, timeline. |
| `tests/phase10-plans-test.php` | 1459 | 582 assertions. |

Plus `docs/PLANS-AND-USAGE.md` and `docs/LICENSING.md`.

## 2. Files modified — 14

| File | Change |
|---|---|
| `replicaforge.php` | 18 new `require_once` lines; version → `0.8.0` (header and constant). |
| `includes/class-replicaforge-schema.php` | `DB_SCHEMA_VERSION` → `10.0.0`; new `PLAN_SCHEMA_VERSION`; reported in `all()`. |
| `includes/class-replicaforge-migrator.php` | The `1.0.0 → 10.0.0` migration and `migrate_commercial()`. |
| `includes/class-replicaforge-plugin.php` | Activation grants capabilities; `boot()` registers `Plans_Api`. |
| `includes/class-replicaforge-maintenance.php` | `daily()` sweeps expired usage reservations. |
| `includes/class-replicaforge-error-catalog.php` | New `PLANS` category; 19 new codes; `usage_lock_held` → 409. |
| `tests/lifecycle-contract-test.php` | Version assertion fixed; added a header/constant consistency assertion. |
| `tests/admin-contract-test.php` | Section 7 made survivable (§7 defect 5). |
| `README.md` | Version line; a Phase 10 section and document index. |
| `docs/API.md` | 13 routes, the envelope, error codes, hooks. |
| `docs/ARCHITECTURE.md` | The new classes, storage keys, cross-layer rules. |
| `docs/SECURITY.md` | The commercial security layer, and what it does not enforce. |
| `docs/COMPATIBILITY.md` | Capability checks, roles, schema version, multisite limits. |
| `docs/DATABASE.md` | The ten Phase 10 storage keys and the three storage decisions. |
| `docs/TROUBLESHOOTING.md` | Nine new failure modes with diagnostics. |
| `docs/DEVELOPMENT.md` | Every Phase 10 hook with working examples. |
| `docs/PRIVACY.md` | What is stored, the audit ring's bounds, no telemetry. |

The test harness at `%TEMP%\opencode\run-test.php` was also fixed (§7 defect 7).

## 3. Database / storage changes

**No database table.** The plugin has none and Phase 10 did not add one — usage
accounting looked like the case that would change that and did not. Ten keys:

| Key | Kind | Holds |
|---|---|---|
| `replicaforge_plan_definitions` | option | Plan overrides (the difference from the shipped set). |
| `replicaforge_trial_settings` | option | Trial configuration. |
| `replicaforge_site_plan` | option | The plan this site runs. |
| `replicaforge_license_local` | option | The local licensing record. |
| `replicaforge_audit_log` | option | A bounded ring, 500 entries. |
| `replicaforge_onboarding` | option | Site-level onboarding state. |
| `replicaforge_usage_{YYYY-MM}` | user meta | Committed counts. |
| `replicaforge_usage_reserved_{YYYY-MM}` | user meta | Open reservations. |
| `replicaforge_usage_recent_{YYYY-MM}` | user meta | The recent tail. |
| `replicaforge_onboarding` | user meta | Per-user acknowledgement and tour dismissal. |
| `replicaforge_trial_{started_at,expires_at,plan}` | user meta | A user's trial. |

The plan set is compiled and cached for 300s under `replicaforge` /
`replicaforge_plans_set`.

### The `10.0.0` migration

Idempotent, destroys nothing, and has **no call into any Phase 1–9 service** — it
cannot read project data, analysis, drafts, or validations. It:

1. Grants the five capabilities to the roles that should hold them.
2. Records the site plan as `free` if it was never set.
3. Records the trial configuration as disabled if it was never set.
4. Deliberately does **not** write the plan definitions, so a site that overrode a
   limit keeps its override across a plan release.

## 4. New REST endpoints — 13

`GET /plans`, `GET /usage`, `GET /license`, `POST /license`, `GET /capabilities`,
`GET /audit`, `GET /onboarding`, `POST /onboarding`, `POST /plans/site`,
`POST /plans/trial`, `POST /trial`, `GET /plans/export`, `POST /plans/import`.

Full table, permission callbacks, and response shapes in `docs/API.md`.

## 5. New hooks — 7

`replicaforge_plan_definitions` (filter) · `replicaforge_resolved_plan` (filter) ·
`replicaforge_feature_entitlement` (filter) · `replicaforge_license_provider`
(filter) · `replicaforge_billing_provider` (filter) ·
`replicaforge_usage_recorded` (action) · `replicaforge_audit_entry` (filter).

All documented with signatures and examples in `docs/DEVELOPMENT.md`.

## 6. New capabilities — 5

`replicaforge_use`, `replicaforge_generate`, `replicaforge_manage_projects`,
`replicaforge_manage_settings`, `replicaforge_manage_plans`.

Administrator gets all five; editor gets the two operational ones; author,
contributor, and subscriber get nothing. Grants are additive to **roles**,
applied on activation and on migration. `Capabilities::orphan_check()` reports a
capability no role holds — currently none.

## 7. New plan / entitlement system

Four plans (`free`, `starter`, `pro`, `agency`) from
`Plan_Storage::defaults()`, filterable, overridable in settings, exportable and
importable. `Entitlement_Manager::check()` is the single gate and runs
authentication → capability → ownership → feature → limit, in that order.

`Feature_Gate` is a presentation layer over it with **no logic of its own** — the
rule that keeps a screen and an endpoint from disagreeing.

## 8. Usage accounting

`begin()` / `settle()` / `fail()`. A user is charged for work that **succeeded**.

- Three numbers kept separate: `used` (charged), `held` (in flight), `total` (the
  bar figure).
- Committing converts a hold into a charge, so `total` does not fall. Releasing is
  what frees a slot.
- A failed run and an expired reservation are both recorded with `quantity => 0`
  and an outcome of `released` / `expired`, so a user can tell a working meter from
  a broken one.
- Concurrency: every counter read-modify-write is inside a lock taken with
  `add_option()` — a single `INSERT` against a unique column. A contention is a
  `409`, not a limit error.
- A reservation expires after 2h and is swept daily, so a crashed job does not
  cost the user the month.

## 9. Licensing architecture

`License_Provider_Contract` has no `activate()` and a provider returns a
`License_State`, never a plan id. `Billing_Provider_Contract` has **no
implementation** and no method with a side effect.

Eight states; `unknown` is **not permission**; an inactive license resolves to the
free plan regardless of the configured plan, so the license means something.
Expiry is evaluated on read, because a state built a second ago can become false
without anything changing it.

## 10. Security improvements

Five capabilities where there were none. Ownership enforced by `Project_Access`,
whose refusal does not distinguish "not yours" from "does not exist". Usage cannot
be forged — no route accepts a counter. No Phase 10 route takes a property name
and a value, and the suite asserts no Phase 10 file mentions
`Elementor_Document_Writer`, `_elementor_data`, `wp_update_post`, or
`Correction_Applier`. Imported plan configuration is rebuilt through
`Plan_Definition`, which drops unknown keys — no path from an imported value to
execution. The audit log has a **closed** event list with per-event key
declarations, drops non-scalars, drops values containing markup, and never records
a key matching `pass`/`secret`/`token`/`key`/`auth`/`cookie`/… . The client marker
is a salted HMAC, not an IP address.

## 11. UI improvements

**None, and this is the largest gap.** No admin screen was built. The services,
routes, gates, refusal text, lock notices, welcome content, and tour copy all
exist and are tested; nothing renders them.

Rationale, stated plainly: the existing `Admin` class is 2295 lines of Phase 1–9
screens, and §§15, 20, 21, 40, 41, 43, and 46 describe a full redesign of it. Doing
that badly would have been worse than not doing it, and doing it properly is a
separate piece of work. §14 below records it as unmet.

## 12. Tests executed

`powershell -ExecutionPolicy Bypass -File %TEMP%\opencode\run-all-tests.ps1`

## 13. Tests passed / failed

**18 suites, 2517 passed, 0 failed, 2 skipped.** The new suite contributes 582
assertions across 28 sections.

The three most important cases, because they are the ones the phase exists to get
right:

- A failed operation consumes **no** allowance, and the refusal is still recorded.
- Ten concurrent reservations against a limit of two: the first two succeed, the
  third is refused reporting `used=0, held=2, total=2, limit=2`.
- A reservation that is never settled expires on its own and stops consuming.

## 14. Known limitations

1. **No admin UI.** §15, 20, 21, 40, 41, 43, 46 unmet. Nothing renders the gate.
2. **No enforcement on the Phase 1–9 endpoints.** `Entitlement_Manager` is not
   called from `Rest_Api`, so analysis, generation, validation, and correction are
   **not metered**. `begin()`/`settle()` are built and tested but not yet wired
   into the pipeline. Doing it halfway would be worse than not doing it: a
   partially metered plugin has a quota users cannot reason about.
3. **`Project_Repository` is still not wired to the admin or REST layers.** It was
   built in Phase 8, is tested, and is referenced by **no other class**. So
   `Project_Access` has no live endpoint to enforce ownership at. This is the
   single most important thing to fix next.
4. **Demo mode not built** (§47). The welcome screen offers a Demo action and
   reports it unavailable, rather than linking to a page that does not exist.
5. **No per-plan pricing.** A plan has no price and there is nowhere to charge one.
6. **Two response envelopes.** The Phase 1–8 routes have no `meta.request_id`.
   Standardising them means touching every existing test; recorded as debt.
7. **The audit ring is a recent-history record**, not a compliance archive. 500
   entries; a denial burst can push an earlier event out.
8. **Uninstall does not sweep the Phase 10 user-meta keys.** The existing
   `uninstall.php` covers options and post meta. The residue is counts and
   acknowledgement flags, not content.
9. **Multisite is untested in Phases 1–10.** Concretely: the usage lock is
   per-blog, so two blogs can each grant the same reservation for the same user; a
   per-site quota holds, a network-wide one does not.
10. **The plan cache does not invalidate on filter registration.** An installation
    that adds `replicaforge_plan_definitions` must call
    `Plan_Storage::flush_cache()` once. Documented, and shown in
    `DEVELOPMENT.md`.
11. **Expired-lock takeover is not atomic.** Two processes arriving together can
    both attempt it and one loses; the counter may be overdrawn by one. The safe
    direction. A single atomic statement would need a table.
12. **`Plans_Api` is 934 lines.** Within the codebase's range (`Admin` is 2295) and
    free of duplication, but it is a controller for 13 routes and could reasonably
    be split into a read and a write controller.
13. **No browser session.** The new admin screens do not exist, so there is nothing
    to open in a browser. Phase 8's unticked release-checklist box — a generated
    draft in the Elementor editor — is still unticked.
14. **No live AI provider request and no render provider exercised** in Phase 10.
    Untested throughout Phases 1–9 as well.

## 15. Remaining technical debt

1. Wire `Entitlement_Manager::begin()`/`settle()`/`fail()` into the Phase 1–9
   pipeline, operation by operation.
2. Wire `Project_Repository` and `Project_Access` into the admin and REST layers.
   This is the ownership boundary and it currently has nothing to enforce at.
3. Build the admin screens: dashboard, projects, plans, usage, system status,
   onboarding, empty states, error states, mobile, accessibility.
4. Standardise the Phase 1–8 response envelope and update its tests.
5. Sweep the Phase 10 user-meta keys on uninstall.
6. Decide whether the usage lock needs to be network-wide, and if so introduce the
   table that would make it atomic.
7. Split `Plans_Api`.

## 16. Defects found by execution — 16

Every one was found by running the code, not by reading it. Six were in the
plugin; the rest were in the test infrastructure, and are listed because a suite
that lies about what it covers is worse than a missing one.

| # | Where | Defect | Consequence |
|---|---|---|---|
| 1 | `Project_Status::validation_signal()` | Compared a group score against `Validation_Limits::SCORE_FLOOR`, a constant that **does not exist** (verified: zero occurrences anywhere in the plugin), on an invented 0–1 scale. | **Fatal** on any project with a stored validation containing `metrics.groups`. Not hypothetical: `phase6-contract-test.php:267` is a real Phase 6 fixture with exactly that shape, and its values are on a 0–100 scale (`82.0`, `94.0`), so the invented floor would also have been wrong by two orders of magnitude. The most serious defect found. |
| 2 | `Project_Status::validation_signal()` | Returned `'issues'`; `health()` compared against `'validation_issues'`. | Health silently reported **healthy** for a project with a `major` difference. Caught by a test. |
| 3 | `License_State::grants()` | Answered from the state as declared, ignoring expiry, while `effective_name()` honoured it. | A license read as **granting an hour after it lapsed**. The most dangerous single method in the file. |
| 4 | `Usage_Manager::reserve()` | Reported `committed + held` as `used`. | Two different numbers collapsed into one; a user would be told they had used quota they had not. |
| 5 | `tests/admin-contract-test.php` §7 | `wp_die()` calls `die()`, which a CLI test cannot catch. The `try`/`catch` could not survive it. | The section only "passed" because **no subscriber account existed**. A test that passes because its subject is absent is not a test. Fixed with a throwing `wp_die_handler`. |
| 6 | `tests/phase10-plans-test.php` | No failure-path cleanup. | 35 users were left in the site database across aborted runs, and the 6 leftover **subscribers** are what exposed defect 5. Fixed with a shutdown handler. |
| 7 | `%TEMP%\opencode\run-test.php` | Shared variable scope with the test file, so an included file's `$warnings` was reported as ReplicaForge's, then crashed the report with "Array to string conversion". | Two suites reported failures that did not exist, and a real failure would have been buried in noise. Renamed the variable and made the report carry `file:line`. |
| 8 | `tests/lifecycle-contract-test.php` | Hard-coded `'0.7.0'`. | A legitimate version bump failed a test. Replaced with the constant, plus a **new** assertion that the header and the constant agree — which is the drift that actually mattered. |
| 9 | `Plan_Manager` | Used the filter name `replicaforge_feature_entitlement` for **plan resolution**. | A filter added to grant a feature would have silently replaced plans instead. Split into `replicaforge_resolved_plan`. |
| 10 | `Plans_Api` | A global `ReplicaForge\settings_days()` in the plugin namespace. | Namespace pollution. Replaced with a private method. |
| 11 | `Feature_Gate::lock_notice()` | Two vestigial `sprintf` calls with a `translators` comment and an empty substitution. | Dead code implying a substitution that did not exist. |
| 12 | `Project_Status::quality()` | `combined_explained` assigned twice, the first with a no-op `sprintf`. | Duplicated assignment. |
| 13 | Licensing interfaces | Named `License_Provider` / `BillingProvider`. | Did not match the codebase's `*_Contract` interface convention (`Ai_Provider_Contract`, `Ai_Cache_Contract`). Renamed. |
| 14 | `Plans_Api::post_site_plan()` | Used the code `plan_not_found`. | That code already meant a Phase 6 **correction** plan. One code meaning two things across two phases. Introduced `commercial_plan_not_found`. |
| 15 | `Entitlement_Manager::begin()` | A lock contention mapped to `402`. | Sent a user to an upgrade page when they had not run out of allowance. Now `409`. |
| 16 | Six test expectations | Wrong, in ways worth recording because each hid something real. | (a) Asserted a store of *only* a bad entry would succeed. (b) Asserted `commit` "frees a slot" — it converts a hold into a charge, and asserting otherwise would have enshrined a limit that is not a limit. (c) Asserted a `!` against its own stated intent. (d) Asserted `starting` had zero active tours, when it has its own. (e) Compared `(int)` to a string code. (f) Checked `strpos` case-sensitively against `"No payment is taken"`. |

Defects 2, 3, 4, 5, and 9 were each found by a test asserting the property rather
than the implementation. Defect 1 was found by reading a constant name while
writing the test that would exercise it — and the fixture that would have hit it
was already in the repository, in a Phase 6 suite, with a scale two orders of
magnitude away from the one the code assumed.

## 17. Exactly how to test Phase 10

### The full regression

```powershell
powershell -ExecutionPolicy Bypass -File C:\Users\dell\AppData\Local\Temp\opencode\run-all-tests.ps1
```

Expected: `suites: 18, failed: 0, assertions passed: 2517, skipped: 2`.

### The Phase 10 suite alone

```powershell
$bin = 'C:\Users\dell\AppData\Local\studio_app\app-1.22.0\resources\php-bin\8.4.25-studio-1'
& "$bin\php.exe" `
  -d "extension_dir=$bin\ext" -d extension=php_pdo_sqlite -d extension=php_sqlite3 `
  -d error_reporting=E_ALL -d display_errors=1 `
  "C:\Users\dell\Desktop\Wordpress Website\ReplicaForge\wp-content\plugins\replicaforge\tests\phase10-plans-test.php" `
  "C:\Users\dell\Desktop\Wordpress Website\ReplicaForge"
```

Expected: `phase10-plans-test: 582 assertions` and exit `0`.

### By hand, in `wp eval-file`

```php
use ReplicaForge\Entitlement_Manager;
use ReplicaForge\Usage_Manager;
use ReplicaForge\Plan_Storage;
use ReplicaForge\Capabilities;
use ReplicaForge\License_Manager;
use ReplicaForge\Project_Access;
use ReplicaForge\Project_Status;

$user = get_current_user_id();

// 1. No fake commerce.
var_dump( null === ( new License_Manager() )->billing() );          // true

// 2. Capability model.
print_r( Capabilities::orphan_check() );                            // ok => true
print_r( Capabilities::role_report() );

// 3. The gate, in order.
$e = new Entitlement_Manager();
print_r( $e->check( 'analysis', 0 ) );                             // authentication_required, 401
print_r( $e->check( 'not_an_operation', $user ) );                  // unknown_operation, 400
print_r( $e->check( 'correction', $user ) );                        // feature_not_in_plan, 402

// 4. A failed operation must not consume allowance.
$usage = new Usage_Manager();
$begin = $e->begin( 'analysis', $user );
$usage->used( $user, 'analysis' );                                  // 0 - begin() does not charge
$e->fail( $user, $begin['reservation'], 'request_failed' );
$usage->used( $user, 'analysis' );                                  // still 0

// 5. Concurrency: two against a limit of two.
$plan = Plan_Storage::get( 'free' );
$usage->forget( $user );
$a = $usage->reserve( $user, 'generation', 1, $plan );
$b = $usage->reserve( $user, 'generation', 1, $plan );
$c = $usage->reserve( $user, 'generation', 1, $plan );             // refused
print_r( $c );                                                      // used=0 held=2 total=2 limit=2

// 6. Ownership.
$projects = new \ReplicaForge\Project_Repository();
$access   = new Project_Access( $projects );
$other    = get_users( array( 'role' => 'subscriber', 'number' => 1 ) )[0]->ID;
print_r( $access->refusal( $other, 'anyproject' ) );               // 404, "not available"
print_r( $access->refusal( $user,   'nonexistent' ) );             // identical - no oracle

// 7. Health is derived, not invented.
print_r( Project_Status::health( array( 'status' => 'generated' ) ) );                    // healthy
print_r( Project_Status::health( array( 'status' => 'generated',
    'validation' => array( 'differences' => array( array( 'severity' => 'major' ) ) ) ) ) ); // validation_issues

// 8. The audit log is a closed list.
var_dump( \ReplicaForge\Audit_Log::record( 'invented_event', array() ) );  // false
print_r( \ReplicaForge\Audit_Log::events() );
```

### Through REST

With the site on a real WordPress install, as an administrator:

```bash
curl -s "$SITE/wp-json/replicaforge/v1/plans"      -H "X-WP-Nonce: $NONCE" | jq .data.matrix
curl -s "$SITE/wp-json/replicaforge/v1/usage"      -H "X-WP-Nonce: $NONCE" | jq .data.usage
curl -s "$SITE/wp-json/replicaforge/v1/license"    -H "X-WP-Nonce: $NONCE" | jq .data.diagnostics
curl -s "$SITE/wp-json/replicaforge/v1/capabilities" -H "X-WP-Nonce: $NONCE" | jq .data
curl -s "$SITE/wp-json/replicaforge/v1/audit"      -H "X-WP-Nonce: $NONCE" | jq .data.summary
```

Without a nonce, every route returns `401 authentication_required`.

### The §53 walkthrough, honestly

| Step | Status |
|---|---|
| Fresh installation | ✓ activation grants capabilities, migration applies |
| Activation | ✓ and it does **not** analyze or generate anything |
| Onboarding | ✓ state, welcome content, tours, dismissal |
| Create project | ✓ `Project_Repository` — but not wired to any screen |
| Analyze website | ✓ Phase 1 — **not metered** (limitation 2) |
| Design intelligence | ✓ Phase 2 |
| AI reconstruction | ✓ Phase 3, not exercised against a live provider |
| Generate Elementor draft | ✓ Phase 4 — **not metered** |
| Validate | ✓ Phase 5 — **not metered** |
| Correct | ✓ Phase 6 — **not metered** |
| Enable monitoring | ✗ **Phase 9 built detection, not a monitor** |
| Detect source change | ✓ Phase 9 detection, verified against fixtures only |
| Review sync | ✗ no monitor, no plan, no apply path |
| Apply safe update | ✗ not built |
| Revalidate | ✓ Phase 5 |
| Usage accounting | ✓ built and tested; not yet called by the pipeline |
| Plan enforcement | ✓ at the Phase 10 routes; not at the Phase 1–9 routes |
| History | ✓ `Project_Status::timeline()`, from the record that exists |
| Rollback | ✓ Phase 6 correction rollback |

Failure cases (§53, second list):

| Case | Status |
|---|---|
| Unauthenticated request | ✓ asserted |
| Unauthorized user | ✓ asserted |
| Wrong project owner | ✓ asserted |
| Limit reached | ✓ asserted, including the three-figure refusal |
| Concurrent requests | ✓ asserted |
| AI unavailable | ✓ Phase 3 |
| Elementor unavailable | ✓ Phase 4 |
| Cron unavailable | ✓ Phase 7 |
| Source website unavailable | ✓ Phase 1 |
| Invalid source URL | ✓ Phase 1 |
| Failed generation | ✓ Phase 4 |
| Failed sync | ✗ sync does not exist yet |
| Failed migration | ✓ `Migrator` records the failure and does not mark it applied |

## 18. Phases 1–9 remain functional

**Yes**, and this is asserted rather than asserted-to:

- All 17 pre-existing suites pass: 1935 of the 2517 assertions.
- All 130 types load; 9 of them are Phase 1–9 classes asserted by name in the
  Phase 10 suite.
- All 18 pre-existing REST routes still register.
- Phase 9's `MIN_INTERVAL_SECONDS` (21600) and `REMOVAL_CONFIRMATIONS` (3) are
  asserted from the Phase 10 suite, so a future change cannot quietly relax them to
  make a Phase 10 test pass.
- Phase 6 remains the only writer of an Elementor document, asserted by reading
  the Phase 10 sources.
- The Phase 1–9 `manage_options` and `edit_pages` checks are **untouched**. The
  new capabilities are an additional, narrower layer at the Phase 10 routes.

---

## 19. What the next phase should do first

1. **Wire `Project_Repository` and `Project_Access` into the admin and REST
   layers.** The ownership boundary exists and has nothing to enforce at. This is
   a multi-user security gap, and it is the most important item here.
2. **Wire `begin()` / `settle()` / `fail()` into the Phase 1–9 pipeline**, one
   operation at a time, so a plan means something for the operations people
   actually use.
3. **Then the admin screens**, because everything above is invisible without them
   and §14 item 1 is the largest unmet requirement in this report.

And the two things that were true at the end of Phase 9 and are still true:
`Source_Monitor` does not exist, so nothing watches a source page; and the section
identity problem that a first incremental sync implementation would hit is still
unresolved.
