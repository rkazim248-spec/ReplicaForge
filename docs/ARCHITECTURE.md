# ReplicaForge Architecture

ReplicaForge turns a public website frontend into an editable Elementor draft, then
measures the difference and corrects what it can measure. This document describes
the system as it is implemented.

## 1. The pipeline

```
Public website URL
        │
        ▼
Phase 1  Secure Frontend Analyzer
         url validation → bounded fetch → HTML parse → normalized structure
        │
        ▼
Phase 2  Frontend Structure & Design Intelligence
         sections, components, design tokens, typography, layout, responsive evidence
        │
        ▼
         Validated Design Representation 2.0
        │
        ▼
Phase 3  AI Design Understanding            (optional, user-triggered)
         structured reconstruction specification 3.0
        │
        ▼
Phase 4  Elementor Generator
         compatibility layer → document build → draft insert → re-read → hash
        │
        ▼
         Editable Elementor draft
        │
        ▼
Phase 5  Visual Validation & Comparison
         read document → read generated stylesheet → pair components → compare
        │
        ▼
         Difference report + metrics
        │
        ▼
Phase 6  Automatic Correction Engine
         plan (read-only) → human review → apply (snapshot, write, re-validate)
        │
        ▼
         Final editable Elementor draft
```

Nothing in the chain publishes, and nothing overwrites a published page.

## 2. Phase 7 additions

Phase 7 did not change the pipeline. It added the infrastructure that makes it
survive real use.

| Component | File | Responsibility |
|---|---|---|
| `Error_Catalog` | `includes/class-replicaforge-error-catalog.php` | Turns an internal code into a category, severity, retryable flag, and a message for a person. |
| `Request_Context` | `includes/class-replicaforge-request-context.php` | One correlation id across a REST call, its job, and every log line. |
| `Data_Redactor` | `includes/class-replicaforge-data-redactor.php` | The only place secrets, private network detail, and executable markup are removed. |
| `Logger` | `includes/class-replicaforge-logger.php` | Structured, bounded, redacted log with filtering and export. |
| `Schema` | `includes/class-replicaforge-schema.php` | Every data version in one place, separate from the plugin version. |
| `Migrator` | `includes/class-replicaforge-migrator.php` | Brings stored data forward. Never discards user data. |
| `Job_Limits` | `includes/jobs/class-replicaforge-job-limits.php` | Statuses, stages, weights, backoff, lease. |
| `Job_Repository` | `includes/jobs/class-replicaforge-job-repository.php` | Job storage, plus bounded stage payloads kept out of the job record. |
| `Job_Queue` | `includes/jobs/class-replicaforge-job-queue.php` | Idempotency, leases, checkpoints, retry, cancel. |
| `Job_Runner` | `includes/jobs/class-replicaforge-job-runner.php` | Executes one stage at a time against the real Phase 1–6 services. |
| `Job_Api` | `includes/jobs/class-replicaforge-job-api.php` | Job, replica, status, and log REST routes. |
| `Maintenance` | `includes/class-replicaforge-maintenance.php` | Scheduled cleanup and the storage report. |
| `System_Status` | `includes/class-replicaforge-system-status.php` | Fourteen checks, each with the action that fixes a failure. |
| `Feature_Flags` | `includes/class-replicaforge-feature-flags.php` | Controlled on/off for optional capabilities. |
| `uninstall.php` | `uninstall.php` | Removes ReplicaForge's data and nothing else. |

## 3. Service graph

Construction is explicit. `Plugin::__construct()` builds every service and injects
it; no service reaches for a global.

```
Plugin
├── Security, Url_Validator, Http_Client, Html_Parser      (Phase 1)
├── Design_Analyzer and its eight collaborators             (Phase 2)
├── Analyzer (Phase 1 facade, wraps the two above)
├── Ai_Manager → Ai_Settings, Ai_Transient_Cache,
│                Ai_Provider_Http → OpenAI | Gemini
├── Elementor_Generator → compatibility, repository, widget registry,
│                         document builder, mapper, asset importer
├── Validation_Engine → cache, comparators, renderer, report
├── Correction_Engine → planner, validator, applier, snapshot,
│                       detector, property map, report, history
├── Rest_Api (Phases 1–6 routes)  +  Job_Api (Phase 7 routes)
├── Admin (screens and assets)
└── Phase 7: Logger, Migrator, Maintenance, System_Status,
            Job_Queue → Job_Runner
```

Every collaborator is optional in the constructor and self-constructs, so a partial
install — Elementor absent, for instance — constructs cleanly and reports the
missing capability rather than failing.

## 4. Where state lives

No custom database tables. Everything is in `wp_options`, `wp_postmeta`, and
transients.

| Option | Contents | Bound |
|---|---|---|
| `replicaforge_jobs` | Job records, oldest first | 100 records; active jobs are never dropped |
| `replicaforge_idempotency` | Key → job id | 500 records, 15-minute TTL |
| `replicaforge_log` | Structured log entries | 500 entries default, 5000 ceiling |
| `replicaforge_settings` | Retention settings | 5 values, each range-checked |
| `replicaforge_feature_flags` | Flag overrides | 5 declared flags |
| `replicaforge_schema_version` | Installed data schema | one value |
| `replicaforge_version` | Installed plugin version | one value |

| Transient | Contents | TTL |
|---|---|---|
| `replicaforge_validation_result_*` | A validation record | Phase 5 limit |
| `replicaforge_correction_plan_*` | A correction plan | Phase 6 limit |
| `replicaforge_job_payload_*` | A bulky stage result | 1 day |
| `replicaforge_spec_*` | A stored specification | Phase 3 limit |

| Post meta | Contents |
|---|---|
| `replicaforge_snapshots` | Snapshot summaries for a draft |
| `replicaforge_generation_hash` | Hash of the document as generated |
| `replicaforge_last_validation_hash` | Hash at the last validation |
| `replicaforge_last_correction_hash` | Hash after the last correction |

The Elementor document itself is stored under Elementor's own keys and is never
treated as ReplicaForge data.

## 5. The job lifecycle

```
enqueue ──▶ queued ──claim──▶ running ──advance──▶ running ──▶ completed
              ▲                  │                                  ▲
              │                  ├──fail (retryable) ──backoff──────┘
              │                  │
              │                  ├──fail (permanent) ──▶ failed ──resume──┐
              │                  │                          │            │
              │                  ├──cancel──▶ cancelled ────┼────────────┘
              │                  │                          │
              │                  └──crash──▶ lease expires ──┘
              │
              └── resume / retry / cancel / delete
```

A worker that is killed never releases its lease. The lease expires and the next
tick takes over, which is what makes a crash recoverable rather than a job stuck
in `running` forever.

Progress is derived from the stage the job has actually reached, weighted by real
cost. A queued job is 0%. Nothing is animated or estimated.

## 6. Security boundaries

| Boundary | Enforced by |
|---|---|
| Outbound requests | `Url_Validator` (scheme, credentials, port, hostname, every resolved IP) then `Http_Client` (timeout, no redirects followed blindly, size cap, content-type check) |
| Browser → server | `Correction_Property_Map` is the only path to a document write. The browser sends a plan id and correction ids. |
| Correction values | Re-derived through `Elementor_Values`. A value that cannot be coerced is refused, not approximated. |
| REST | `is_user_logged_in()` + `current_user_can( 'manage_options' )` + `wp_verify_nonce( …, 'wp_rest' )` on every functional route |
| Outbound payloads | `Data_Redactor` on every log entry, job parameter, checkpoint, and export |
| Admin actions | `current_user_can()` + `check_admin_referer()` on every `admin_post` handler |
| Uninstall | ReplicaForge keys only. Never a post, an attachment, or Elementor's document. |

## 7. Honest limitations

- A measured improvement is never a guarantee of visual quality. The metrics are
  deterministic comparisons, not a similarity score.
- A manual edit made before the first Phase 6 plan is not distinguishable per
  property, only as a document hash change.
- Rendered comparison needs an external render provider. Without one, validation
  compares the document and stylesheet only, and says so.
- Ten cross-kind comparisons are reported `blocked` because Phase 5 pairs
  section-level source components with containers. The output is truthful;
  suppressing the noise is a Phase 5 design change, not a bug fix.
- A DNS rebinding window remains between validation and the request. See
  `docs/SECURITY.md`.

## 8. Further reading

- `docs/SECURITY.md` — the security model and the audit checklist
- `docs/API.md` — every REST route, argument, and response
- `docs/JOBS.md` — the job system
- `docs/DATABASE.md` — storage, versions, and migrations
- `docs/ELEMENTOR.md` — the compatibility layer
- `docs/VALIDATION.md` and `docs/CORRECTIONS.md` — Phases 5 and 6
- `docs/AI.md` — the AI provider and its trust boundary
- `docs/PERFORMANCE.md` — measured costs and limits
- `docs/TROUBLESHOOTING.md` — what to check when something does not work
- `docs/COMPATIBILITY.md` — supported versions
- `docs/PRIVACY.md` — what leaves the site
- `docs/DEVELOPMENT.md` — running the tests
- `docs/RELEASE-CHECKLIST.md` — the pre-release gate
- `docs/PHASE-7-AUDIT.md` — the audit that produced this work

---

## Phase 9 addition: the synchronisation reasoning layer

Phase 9 added `includes/sync/`, six classes that decide **what changed on the source
and whether ReplicaForge may write anything in response.** None of them writes to an
Elementor document, and none of them is called by the plugin today.

```text
Source_Monitor        (not built)
    ?
Source_Version        (not built)  § stores one normalized representation per check
    ?
Component_Matcher     § "is this the same component as last time?"
    ?
Change_Detector       § "what changed?"
    ?
Change_Classifier     § "how much does it matter, and is it risky?"
    ?
Sync_Conflict_Detector § "may ReplicaForge write it, or would that destroy work?"
    ?
Sync_Planner / Sync_Validator / Sync_Service   (not built)
    ?
Correction_Applier    (Phase 6 § the only write path, and the one a future apply
                         step must reuse rather than replace)
```

### The rule the design turns on

**Synchronisation will have no write path of its own.** `Sync_Conflict_Detector`
refuses any property not on `Correction_Property_Map` before it compares anything, and
a future apply step must convert a validated plan into a `Correction_Applier::apply()`
call. A second writer would be a second, unaudited route to a document.

### Reuse rather than duplication

Conflict detection composes three existing answers rather than adding a fourth store:
`Correction_Snapshot::baseline_value()` for what ReplicaForge generated,
`current_value()` for what the document holds, and `manual_change()` for whether a
human changed it. Two stores of the same fact would drift, and the drift would be
invisible until a user's edit was overwritten.

### The data a change flows through

`Component_Matcher` keeps two hashes. `fingerprint` (role and tag) is **identity** and
excludes position, index, text, and image, because those are the three things a design
change alters. `content_hash` (everything) is the **sufficient condition** for a match.
Using one text-inclusive hash for identity makes every headline edit read as a rebuild,
which was the first implementation and the reason the split exists.

### Not in the service graph

No new service is registered. A service with no caller is a maintenance cost with no
benefit, and registering one would imply a reachable feature. See
[`PHASE-9-COMPLETION-REPORT.md`](PHASE-9-COMPLETION-REPORT.md).

---

## Phase 10: plans, entitlements, usage, and licensing

Eleven classes were added, in two new directories.

### `includes/plans/`

| Class | Responsibility |
|---|---|
| `Plan_Limits` | The vocabulary. Every operation, feature, limit name, capability, and period rule. Nothing else invents a name. |
| `Plan_Definition` | One plan, as an inert value object. Answers questions about a description; decides nothing. |
| `Plan_Storage` | Holds the plan set and the trial configuration. Option-backed, filterable, cached. |
| `Usage_Manager` | Usage accounting, reservations, and the mutual-exclusion lock. |
| `Plan_Manager` | The one place that answers "what plan is this user on, and why". |
| `Entitlement_Manager` | The one gate. Five checks, in a fixed order. |
| `Feature_Gate` | A presentation layer over the gate. No logic of its own. |
| `Audit_Log` | A bounded ring of security-relevant commercial events. A closed list of event names. |
| `Onboarding` | Welcome and tour state. Triggers nothing. |
| `Plans_Api` | The Phase 10 REST surface. |

### `includes/licensing/`

| Class | Responsibility |
|---|---|
| `License_Provider_Contract` | What a licensing provider must be able to do. No `activate()`. |
| `Billing_Provider_Contract` | What a billing provider must be able to do. Every method is a read. **No implementation ships.** |
| `License_State` | An immutable answer. A fact, never a plan. |
| `Local_License_Provider` | The only implementation. No network, no key, no activation. |
| `License_Manager` | Resolves license → plan → trial. |

### `includes/`

| Class | Responsibility |
|---|---|
| `Capabilities` | The five custom capabilities, their role grants, and a role report. |
| `Project_Access` | Who may read and change a project. Takes the user id explicitly. |
| `Project_Status` | One status vocabulary, the transition map, health, quality, and timeline. |

### The rule the layer enforces

**`Entitlement_Manager` is the only gate, and `Feature_Gate` has no logic of its
own.** A UI gate with its own rules is the most common way an entitlement system
starts lying: the screen decides a feature is available, the endpoint decides it
is not, and the user gets a button that produces an error. Keeping the gate to a
translator over one decision means the two cannot disagree.

`Project_Status::health()` is a **precedence chain**, most urgent first, not a pile
of independent checks. A project that failed to generate and also has pending
corrections reports the failure, because the corrections are about a document that
does not exist.

### Storage: no new table

Phase 10 introduces **no database table**, because the plugin has none and did not
need one. Everything is options and user meta:

| Key | Kind | Holds |
|---|---|---|
| `replicaforge_plan_definitions` | option | Plan overrides. |
| `replicaforge_trial_settings` | option | Trial configuration. |
| `replicaforge_site_plan` | option | The plan this site runs. |
| `replicaforge_license_local` | option | The local licensing record. |
| `replicaforge_audit_log` | option | A bounded ring, 500 entries. |
| `replicaforge_onboarding` | option | Site-level onboarding state. |
| `replicaforge_usage_{YYYY-MM}` | user meta | Committed counts. |
| `replicaforge_usage_reserved_{YYYY-MM}` | user meta | Open reservations. |
| `replicaforge_usage_recent_{YYYY-MM}` | user meta | The recent tail, 500 entries. |
| `replicaforge_onboarding` | user meta | Per-user acknowledgement and tour dismissal. |
| `replicaforge_trial_{started_at,expires_at,plan}` | user meta | A user's trial. |

The plan set is compiled and cached in the object cache for 300 seconds under
`replicaforge` / `replicaforge_plans_set`. A cache does not watch for a filter
being added, so an installation that installs `replicaforge_plan_definitions` at
boot must call `Plan_Storage::flush_cache()` once after registering it.
`Plan_Storage::store()` and `Plan_Storage::reset()` flush automatically.

### Cross-layer rules Phase 10 must not break

- **Phase 6 remains the only writer of an Elementor document.** Phase 10 has no
  write path at all, and the test suite asserts this by reading the Phase 10
  sources.
- **Phase 9's sync rules are unchanged.** `MIN_INTERVAL_SECONDS` is still 21600
  and `REMOVAL_CONFIRMATIONS` is still 3, asserted from the Phase 10 suite so a
  future change to make a Phase 10 test pass cannot quietly relax them.
- **Phase 1–8's `manage_options` and `edit_pages` checks are untouched.** The new
  capabilities are an additional, narrower layer applied at the Phase 10 routes.
  Rewriting the older checks would be a Phase 11 change with its own regression
  risk, and leaving them alone is what keeps Phases 1–9 working.

---

## Phase 11: AI reliability, cost control, and job orchestration

Eleven classes were added. The AI set sits between Phase 3's context builder and
Phase 3's manager; the job set sits beside Phase 7's queue rather than replacing it.

| Class | Responsibility |
|---|---|
| `ai/class-replicaforge-ai-capabilities.php` | Provider and model capability registry. |
| `ai/class-replicaforge-ai-failures.php` | Failure classification: retryable, `retry_after`, three message layers. |
| `ai/class-replicaforge-ai-context-budget.php` | Measure, prioritise, reduce structurally, chunk by section. |
| `ai/class-replicaforge-ai-cost-estimator.php` | Complexity labels and the confirmation decision. |
| `ai/class-replicaforge-ai-usage-audit.php` | Per-call audit of **shape**, never content. |
| `jobs/class-replicaforge-job-states.php` | The eleven states and the transition map. |
| `jobs/class-replicaforge-job-lock.php` | Per-resource locks with ownership, expiry, and takeover. |
| `jobs/class-replicaforge-job-checkpoint.php` | The checkpoint shape, monotonicity, and derived progress. |
| `jobs/class-replicaforge-job-recovery.php` | Stuck detection, and whether re-running is safe. |
| `jobs/class-replicaforge-job-cancellation.php` | Cooperative cancellation requests. |
| `jobs/class-replicaforge-job-manager.php` | The orchestrator: admission, locks, tick budget, pause, cancel, report. |

### The one rule

**There is still one queue.** `Job_Repository` stores the records, `Job_Queue` owns
the lifecycle, `Job_Runner` runs the stages. `Job_Manager` decides whether a stage
may *start*, holds the locks, and enforces the time budget. It adds no second store
and no second lifecycle.

`Job_Limits::STATUSES` is now **built from** `Job_States::ALL` rather than restating
it, so the Phase 7 and Phase 11 vocabularies are one list that cannot drift.

### Storage: still no database table

| Key | Kind | Holds |
|---|---|---|
| `replicaforge_job_settings` | option | The orchestrator configuration. |
| `replicaforge_job_cancellations` | option | Pending cancellation requests, pruned to one day. |
| `replicaforge_lock_{resource}` | option | One lock record. Written with `add_option()` as the atomic primitive. |
| `replicaforge_ai_usage` | option | Per-period AI counters. Two periods kept. |
| `replicaforge_ai_usage_recent` | option | The last 100 AI call records. |
| `replicaforge_ai_estimator` | option | The cost estimator configuration. |

The job **record** shape gains `checkpoint`, `user_id`, `project_id`, `operation`,
and `queue_state`; `Schema::JOB_SCHEMA_VERSION` is `11.0`.

### The `11.0.0` migration

Backfills the five new fields onto job records written by a pre-Phase-11 version, and
releases any job that was `running` when the plugin was upgraded — its worker no
longer exists.

The backfill is **conservative about the one thing that matters**: a job recorded at
`generate` gets a checkpoint naming `generate` as the stage it *reached*, and does
**not** get `generate` in its completed list. Claiming it finished would make recovery
treat a half-written Elementor draft as safe to re-run.

The backfill is genuinely idempotent — a job that already carries the Phase 11 fields
is skipped, so a re-applied migration performs no writes and reports zero. Re-writing
every record on every run is not idempotency, it is repeated work, and a report saying
"100 backfilled" on the second run would be indistinguishable from having done nothing
the first time. Found by the Phase 11 suite.

### Cross-layer rules Phase 11 must not break

- **Phase 6 remains the only writer of an Elementor document.** Phase 11 has no
  write path at all, asserted by reading the Phase 11 sources with comments stripped.
- **Phase 9's sync rules are unchanged**, asserted from the Phase 11 suite.
- **Phase 1's SSRF boundary is unchanged**, asserted directly against
  `Url_Validator::validate()` for loopback, link-local, two private ranges, and a
  non-http scheme — plus an accepted public page, so the refusals are refusals rather
  than a broken validator.
- **Phase 10's entitlements are reused, not reimplemented.** `Job_Manager::admit()`
  calls `Entitlement_Manager::begin()` and settles the reservation, so a queued job
  costs one unit and a refused one costs nothing.

---

## Phase 12: multi-page reconstruction

Phase 12 adds one concept above the page level § **a structure built once and used in
many places** § and composes it from existing systems rather than alongside them.

### New: `includes/multipage/` (15 classes)

| Class | Owns |
|---|---|
| `Site_Limits` | The vocabulary: page types, shared roles, template types, ownership states, reconstruction modes, change scopes, and every bound |
| `Page_Discovery` | Same-origin discovery, sitemap reading, and the refusal engine |
| `Page_Classifier` | Exact-root and prefix-root classification with recorded confidence |
| `Site_Design_System` | Global tokens, page agreement, and conflict classification |
| `Shared_Component_Detector` | Four-part structure fingerprints (never class names) |
| `Component_Registry` | Stable component and template identities; override protection |
| `Navigation_Mapper` | Navigation reconstruction, relative resolution, source-to-replica URL map |
| `Asset_Registry` | Website assets, deduplication by key, provenance; `reference` by default |
| `Site_Representation` | The canonical website specification, and the gate that refuses an invalid one |
| `Site_Compatibility` | Theme probe, Elementor capability probe, global style ownership, header/footer strategy |
| `Cross_Page_Validator` | Cross-page findings and correction eligibility |
| `Multi_Page_Planner` | Generation order, failure isolation, sync scope, snapshots, rollback |
| `Website_Repository` | The page store, with ids derived from URLs and monotone status |
| `Site_Analyzer` | Orchestration, the website map, the design view |
| `Multi_Page_Api` | 15 REST paths / 36 methods, all owner-gated |

### Reused, not reimplemented

- **`Token_Engine` (Phase 8)** produces the global token set when fed observations from
  every page. `Site_Limits::families()` reads `Token_Engine::FAMILIES` at runtime so
  the two lists cannot drift.
- **`Project_Access` and `Entitlement_Manager` (Phase 10)** resolve ownership and page
  limits. `Multi_Page_Api` is constructed with the same access object.
- **`Elementor_Compatibility` (Phase 4)** is *asked* what is supported. No version
  number is ever compared, because a fork can add a feature without a version bump.
- **`Url_Validator` (Phase 1)** re-validates every discovered URL *at discovery*, so
  the frontier is never poisoned.
- **No second queue, project store, AI provider, validation engine, or Elementor
  compatibility layer was created. No database table was added.**

### The one concept

A single-page reconstruction decides everything locally. A website cannot: a header on
forty pages that is reconstructed forty times means editing one page silently
disagrees with the other thirty-nine. So a shared component has an identity, a set of
pages, and a rule about what may be written to it § and a template holds structure
while its text lives with each page.

### Cross-layer rules Phase 12 must not break

- **Phase 4 remains the only writer of an Elementor document.** Phase 12 *plans*;
  nothing in `includes/multipage/` sets a post status or calls `wp_insert_post()`.
  Asserted by reading the Phase 12 sources with comments stripped.
- **Every generated page is a draft.** `draft.publish` is `false` on every step, and
  there is no option that changes it.
- **Phase 1's SSRF boundary is extended, not bypassed.** Discovery applies the same
  validator before queueing, and refuses private areas by whole path *segment* so
  `/admiralty` is not caught by the `/admin` rule.
- **Phase 10's plan limits are the source of page allowances.** `page_limit_for()`
  reads plan rank and clamps to a hard ceiling, so no plan makes a crawl unbounded.
- **Phase 6 remains the only corrector.** `Cross_Page_Validator` finds differences and
  decides eligibility; it never writes.

### Storage

Four options (`replicaforge_websites`, `replicaforge_site_registry`,
`replicaforge_site_snapshots`, `replicaforge_global_style_ownership`), all bounded, no
table. Snapshots reference posts and their hashes rather than copying content, so they
cost almost nothing.
## Phase 13: visual intelligence

The visual layer is a *sibling* of the structural layer, not a replacement for it. Both
feed one unified model; neither is allowed to rewrite the other's conclusions.

```
Source -> Secure fetch -> DOM/CSS analysis --+
                                            +-> Unified visual model -> AI reasoning
         -> Rendered viewport capture ------+           v
                                        Reconstruction planning
                                                  |
                                          Elementor generator
                                                  |
                                              Render replica
                                                  |
                                       Comparison + validation
                                                  |
                                          Correction engine
                                                  |
                                                Re-render
```

**Fifteen classes in `includes/visual/`**, alongside Phase 8's `Visual_Effects` rather
than in a new top-level directory, because a visual *value* parser already lived there and
a second directory would suggest two unrelated visual layers.

| Layer | Classes |
| --- | --- |
| Contract | `Renderer_Contract`, `Image_Reader_Contract` |
| Rendering | `Renderer_Manager`, `Endpoint_Renderer` (adapts Phase 5's `Visual_Renderer`) |
| Analysis | `Visual_Analyzer`, `Visual_Features`, `Dynamic_Detector` |
| Representation | `Visual_Representation` (schema `13.0`) |
| Comparison | `Visual_Comparator`, `Render_Cache`, `Viewport_Manager` |
| Correction | `Visual_Corrector` |
| AI | `Visual_AI` |
| Orchestration | `Render_Job`, `Visual_Api` |

**Two sources of truth, and no winner.** Computed CSS says what the browser was
instructed to do; rendered geometry says what actually happened. `Visual_Analyzer` stores
both, labels them, and records a `measurement_conflict` when they disagree beyond
tolerance. It does not resolve, because whether CSS or the render will be reproduced
depends on where the value is going: a container width going into an Elementor setting is
CSS-reproducible, and the same width going into a pixel comparison is not.

**Derived geometry is always marked derived.** A box summed from declared heights is a
claim about a layout that has not happened: `derived: true`, confidence 0.55. With no
render at all, the representation's confidence is **capped at 0.6** and the cap is
explained. Grids and overlaps ignore derived boxes entirely, because a summed-height
estimate is right for a stack and wrong for a grid.

**Degradation is an output, not a fallback.** `Renderer_Manager::capture()` returns
`degraded: true` with a stated reason and a description of what still works. Nothing
synthesises a "visual estimate" from the DOM, because that would be a fabrication with a
confidence score attached, and a confidence score is what makes a fabrication read as a
measurement.

**The `13.0` visual representation is independent of Elementor** by requirement and by
test. `Visual_Effects` returns an `elementor` sub-array beside every shadow and border;
those keys are removed recursively and the loss they represented is recorded under
`downstream_limitation`, under a name that does not smuggle the coupling back in.

See `docs/VISUAL-INTELLIGENCE.md` for the design rationale and
`docs/PHASE-13-COMPLETION-REPORT.md` for the verified status.

---

## Phase 14 â€” Content and Data Intelligence

Phases 1â€“13 reconstruct a page. Phase 14 decides what should go *into* it.

### The five separations

The phase rests on keeping five things apart that are indistinguishable from the
outside:

| | Question |
|---|---|
| **Structure** | What does the page look like? |
| **Source content** | What does the *original site* say? |
| **User content** | What exists in *this* install? |
| **Mapping** | Which source field belongs in which destination field? |
| **Rendering** | How should the mapped value appear? |

Each has its own class, and `Content_Service` enforces the order so no caller can
reconstruct a mapping without a model, or apply a plan without validation.

### The dependency graph

```
Structured_Data â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
                             v
Phase 2 representation â”€â”€> Content_Role_Detector â”€â”€> Source_Content_Model
                                                       â”‚
Phase 13 visual â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”¤
                                                       v
Content_Fingerprint                              Content_Mapper
                                                       â”‚
WordPress_Content_Provider / WooCommerce_Provider â”€â”€â”€â”€â”€â”¤
  (Content_Provider_Contract, read-only)                v
                                              Content_Validator
                                                       â”‚
                                    Content_Cache <â”€â”€â”€â”€â”€â”¤
                                    (provenance,        â”‚
                                     field snapshots)   v
                                              Content_Preview â”€â”€> Content_Api
                                                       â”‚
                                                       v
                                              Content_Applier
```

### Reuse, not duplication

| Phase 14 needs | Existing system used |
|---|---|
| Ownership states (Â§16) | `Sync_Conflict_Detector::OWNERSHIP` â€” **read**, not restated |
| Difference categories | `Validation_Limits::CATEGORIES` |
| Component types, card fields, card groups | Phase 2's `Component_Detector` â€” **consumed** |
| Visual context | Phase 13's representation |
| Rollback | Phase 6's `Correction_Snapshot` for *documents*, plus a new field-value snapshot for *content* |
| Redaction (Â§30) | `Data_Redactor::structure()` |
| SSRF / URL policy | `Url_Validator` |
| Elementor dynamic tags (Â§17) | `Elementor_Compatibility` |
| Error codes (Â§43) | `Error_Catalog::CODES`, extended in place |
| Metering (Â§44) | `Plan_Limits::OPERATIONS`, three new operations |
| Logging | `Logger` |
| Post readability (Â§7) | `current_user_can( 'read_post' )` |

No second queue, table, provider abstraction, error catalogue, or validation engine was
created.

### The read/write boundary

`Content_Provider_Contract` has **eight methods and every one reads**. There is no
`update()`, no `set_price()`, no `save()`. Writes go through exactly one path â€”
`Content_Applier`, which takes a validated plan and an approved subset, and which is
where snapshots, ownership checks and rollback live.

The way to make "AI and mapping logic must not modify products, prices, inventory,
orders or users" structurally true is for the object holding the database credentials to
have no method that could use them to write.