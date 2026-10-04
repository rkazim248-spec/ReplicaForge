# ReplicaForge — Orchestration (Phase 17)

**Schema** `17.0` · **Engine** `1.0` · **Plugin** `1.5.0`

This is the reference for Phase 17. It describes what was built, what was deliberately not
built, and what is verified versus not verified. Every figure below was read back out of the
code rather than typed from memory.

---

## 1. What this phase is for

Phases 1–16 each solve one problem well. None of them decides *what should happen next*, and
several of them — Phases 12, 13 and 14 most obviously — are complete implementations sitting
behind a wiring gap that nothing closes.

Phase 17 is the coordinating layer. It plans, sequences, gates, measures, reports, and above
all it decides **what this install can actually do** before spending anything.

The hard part is not the sequencing. It is that almost every subsystem in this plugin is
reachable in principle and reachable in practice on very few installs, and an orchestrator
that assumes availability produces confident nonsense.

---

## 2. The decision that shapes everything else

### Capabilities are probed, not assumed

`Capability_Registry` asks fourteen questions, each answered by a probe that has to *reach*
the feature the way a workflow would reach it.

A capability here is not a class list. The concrete reason:

> Phase 9 ships five classes in `includes/sync/`. Three are fully implemented, two have
> passing test suites, and **not one is constructed anywhere outside `tests/`**. There is no
> service, no REST route, no admin screen, no cron, no job type. Four constants are declared for
> options nothing writes, and the capability `sync.approve` is granted to two roles for a
> feature that does not exist.

A `class_exists()` probe reports synchronisation as available. An incremental workflow selects
the sync stage, and the stage has nothing to call. So `probe_sync()` asks whether anything can
actually *perform* a synchronisation, and answers `unavailable` — while reporting detection as
available and application as unavailable, because those really are two different facts.

### Three answers, and the third is the point

| Status | Meaning |
|---|---|
| `available` | The probe reached it and it works. |
| `degraded` | The probe reached it and it works in a reduced form. |
| `unavailable` | The probe could not reach it. Accompanied by a reason. |

`degraded` exists because Elementor without a render provider is the common case: generation
succeeds, visual validation cannot. Reporting that as `unavailable` would be wrong (the work
happens) and reporting it as `available` would be worse (a user is told visual validation ran
when it did not).

`degraded` counts as *usable* — a workflow that refused to run on a degraded capability would
refuse to run at all on most installs — but is **not** reported as fully available, and is named
in every report. See `can()` versus `is_full()`.

### What this install measures

```
=== what this install actually is ===
  core          AVAILABLE
  fetch         AVAILABLE
  analysis      AVAILABLE
  planning      DEGRADED   The deterministic reconstruction plan will be used...
  elementor     AVAILABLE
  rendering     UNAVAILABLE  No rendering service is configured...
  validation    DEGRADED   ...Visual comparison is unavailable...
  corrections   AVAILABLE
  interactions  AVAILABLE
  content       AVAILABLE
  multipage     AVAILABLE
  sync          UNAVAILABLE  Source changes can be detected but not applied...
  ai            UNAVAILABLE  No AI provider is configured...
  browser       UNAVAILABLE  No browser observation driver is registered...
```

Measured on PHP 8.4.25, WordPress 7.1.2, Elementor 4.3.2, `memory_limit` 128M. 8 available,
2 degraded, 4 unavailable. Nothing required is missing, so a workflow can run — with four
limitations recorded rather than hidden.

---

## 3. A re-entrancy bug worth knowing about

`probe_validation()` needs to know whether `rendering` is available. It therefore reads a
sibling capability, which reads the memo — and the first version of the memo was filled only
*after* the whole loop finished.

The result: a nested read re-entered the loop, re-running every probe, one of which nested
further. Each level re-ran the Elementor probe, which costs about **6 MB** on a real install.
WordPress with Elementor and this plugin already occupies ~68 MB of a 128 MB limit, leaving
roughly ten levels of headroom.

**The orchestrator's own capability report was the thing that killed the process** — before it
had decided a single thing. Peak memory after the fix: 74 MB.

The fix is at the root rather than in the caller: `evaluate()` memoises per capability as each
probe finishes, so a nested read of a completed capability is a lookup, and a nested read of an
unfinished one evaluates only that one. A `probing` set breaks genuine cycles into a reported
unavailability instead of unbounded recursion.

The suite asserts that probing twice does not exhaust memory.

---

## 4. The pipeline

```
preflight → planning → analysis → site_architecture → sync → intelligence
  → specification → approval_plan → approval_draft → generation
  → render → validation → interactions → corrections → regression
  → final_review → completion
```

Seventeen stages. A graph, not a list — `interactions` depends on `generation` but not on
`render`, which a list gets wrong for no reason.

### Types decide eligibility, never existence

Every type runs every declared stage. A type marks stages *ineligible*, recorded with a reason.

`single_page` skips `site_architecture` (no site-wide analysis for one page).
`incremental` skips it too and appends `sync`.

The first version synthesised a stage list per type. Both injections were bugs waiting to
happen, and the incremental one was already broken: `sync` was appended but never added to
`STAGES`, so **every `incremental` workflow failed validation** with `orchestrator_unknown_stage`.
The graph is now the single source of truth.

### Four ways a stage does not run, kept apart

| Verdict | Meaning |
|---|---|
| `run` | Prerequisites succeeded, capability present, gate satisfied. |
| `skip` | The type does not need it. **A decision.** |
| `blocked` | A prerequisite did not succeed, or the capability is absent. **A circumstance.** |
| `wait` | An approval gate is unsatisfied. **A human is needed.** |

Collapsing these into one "not run" would leave a report unable to say whether the workflow
chose not to do something or was unable to.

**A skipped prerequisite does not satisfy a dependent stage.** `skipped` is not `succeeded`. If
`analysis` did not run, `intelligence` is blocked with a reason naming the prerequisite — and
the message deliberately does not use the word "skipped", because the point is that a stage
which did not run has not succeeded.

### A stage is not successful because a service did not error

`Workflow_Definition::expected_outputs()` declares what each stage produces.
`verify_outputs()` checks it after the call.

A fetch that returns an error page, a generation that produces zero elements, an analysis that
yields no sections — all return normally, and all would otherwise be recorded as success. A
draft with no elements is recorded as a *failure*, so the workflow does not go on to validate an
empty page and report it as a faithful copy of an empty source.

A declared output of `"0"` counts as produced. An empty string does not.

---

## 5. Preflight

Nine checks, all cheap, all before any expensive work:

`platform` · `memory` · `elementor` · `source` · `ownership` · `capabilities` · `budget` ·
`conflicts` · `type`

Preflight runs first because the value of checking the environment is lost if the check happens
after the cost. Elementor being absent is discovered in a millisecond here and would be
discovered after a fetch, an analysis and a plan.

The memory check is not hypothetical. A workflow runs a DOM analysis, an Elementor generation
and a validation pass in one request, and this install already sits at ~68 MB of 128 MB. Under
32 MB free is a failure; under 64 MB is a warning.

### The estimate knows counts and refuses magnitudes

`estimated_resources` gives stage counts, a page maximum and the correction ceiling. It gives
**no duration, no cost, and no accuracy figure** — none is derivable before execution on a site
whose capabilities are this variable, and an estimate that reads as a measurement is worse than
no estimate.

### The mode recommendation never overrides

`recommend_mode()` computes a recommendation and returns it. The definition always uses the mode
the user asked for. The plan records `requested`, `recommended` and `applied`, and `applied` is
always the request.

On this install it recommends `editable_structure` over `visual_accuracy` — recommending visual
accuracy on a site with no renderer would be recommending a mode whose headline benefit cannot be
delivered.

### Disclosure

A preflight report is the most widely-shared artefact this plugin produces: it is shown to a
user who may not have the capability to run the workflow, it appears in a REST response, and it
is the first thing pasted into a support ticket. Every check therefore reports a *conclusion*
and a *remedy*; the raw evidence is discarded. No secret, endpoint, filesystem path or private
address appears. The suite asserts this on the encoded JSON.

---

## 6. Artifacts — the storage that had to exist

`Workflow_Artifacts`, per workflow, bounded: 40 artifacts, 512 KB each, 2 MB total.

### Why this class had to be written

Every stage needs the previous stage's output, and a job may be interrupted between any two of
them. The existing plugin has nowhere to put that:

- the phase 2 representation lives in a job-scoped transient that expires;
- a specification lives in a 24-hour transient;
- the `replicaforge_analysis` post meta that Phases 12, 13 and 14 all read **is never written by
  anything**;
- `replicaforge_analyses`, which Phase 16 reads for stored HTML, is never written either.

A pipeline built on those would have stages that cannot see each other's output after a resume.
So the orchestration layer supplies the missing glue — without becoming a second copy of every
phase's storage. A specification still lives in `Elementor_Repository`, a validation result in
Phase 5's cache; this holds *references*.

### A reference cannot smuggle a payload

`REFERENCE_KINDS` accepts only scalar identifiers. An array inside a reference is refused with
`orchestrator_reference_not_scalar`. That check is the whole point: it is what stops a large
dataset being copied into a workflow record "just as a reference".

### Read-time validation

`get()` re-validates three things before returning:

1. **Schema** — an artifact written against an older schema is an error, not data. Consuming a
   2.0 representation as 2.1 produces a subtly wrong draft no later stage can detect.
2. **Hash** — a tampered artifact is `orchestrator_artifact_corrupt`.
3. **Kind** — asking for the wrong kind is an error.

An oversized artifact is **refused, never truncated**. A truncated representation consumed as
though complete would produce a draft missing whatever fell off the end, with nothing in the
record to say so.

### A bug this phase introduced and fixed

`Workflow_Repository::delete()` purged artifacts under a lowercased workflow id while the store
had written them under the id as generated. Workflow ids contain an uppercase `T` from their
timestamp (`wf_20260930T101500_ab12`), so **every deleted workflow leaked its artifacts** —
including a copy of the source page's structural representation — into the options table
forever.

The fix is one shared sanitiser, `Orchestrator_Limits::sanitize_workflow_id()`, called by both,
with a comment recording what the disagreement cost.

---

## 7. State machine

Thirteen states, four terminal, transitions in one table.

`failed` is terminal **and** has an exit to `queued`. That looks contradictory and is not — it
is exactly Phase 11's shape: `Job_States::TERMINAL` includes `FAILED` while
`Job_States::TRANSITIONS[FAILED]` includes `QUEUED`. A terminal state generates no further work
*by itself*; a re-queue is a new attempt with a new owner, and it goes through the same
permission and budget checks as starting one.

`RETRYABLE_STATES` is declared rather than derived, so a caller asking "can this be retried?"
reads one list instead of re-deriving the rule and getting it subtly different.

### One writer

`Workflow_Repository::transition()` is the only thing that writes a state. If a REST handler, an
admin screen and a job tick each wrote state directly, three of them would re-derive "is this
transition legal", and the one that forgot would be the one a user could reach. It reads
legality from the same table the test reads.

A terminal workflow that is not `failed` is refused with `workflow_finished`. A cancelled
workflow cannot restart, and re-requesting one is refused as `workflow_illegal_transition` —
the more precise of the two answers.

---

## 8. Approvals

### Why the approval record lives in the orchestrator

This is a stated limitation of the existing plugin, not a preference. Phase 15's review system is
complete and correct, and it cannot currently record anything:

```
Project_Repository::add_version()      → no caller in includes/
  → replicaforge_projects.versions[]   → always empty
  → Review_Store::create()             → resolve_version() returns null → null
  → Review_Store::create() returns null → no review row ever exists
  → Project_Context_Store::gates()     → status 'none', completable false, forever
```

Writing `add_version()` from here would be a change to Phase 15's data model, and a wrong one: a
version list is a record of what a *project* has produced across its whole life, and an
orchestrator writing to it would make a project's history depend on which tool happened to run.

So the approval record is kept here, scoped to the workflow. **Authorization still comes from
Phase 15** — a decision is only accepted from a user that `Permission_Manager` grants the gate's
capability to. This layer stores the decision; it does not decide who may make one.

### The hash is the point

Each decision records the hash of the thing approved, bound to the plan id. `gate_satisfied()`
recomputes it and reports the approval **stale** when they differ. A reviewer who approved a
plan, and then the plan materially changed, has not approved the new plan.

### Gates, and who may decide

| Gate | Stage | Requires |
|---|---|---|
| `draft_creation` | `approval_draft` | `generation.run` |
| `content_mapping` | `approval_plan` | `content.apply` |
| `commerce_mutation` | `approval_draft` | `content.apply` |
| `destructive_correction` | `corrections` | `correction.apply` |
| `user_component` | `corrections` | `correction.review` |
| `multi_page_update` | `generation` | `projects.edit` |
| `sync_apply` | `final_review` | `sync.approve` |
| `finalization` | `final_review` | `reviews.approve` |

Every capability is read from `Workspace_Limits::CAPABILITY_GROUPS`, asserted in the suite. A
gate requiring a capability the permission system does not have would deny everyone, including
the owner.

Gates are **chosen per type**, not all eight every time. A single page needs three. A planner
that requested all eight always would train users to approve without reading, which is worse than
having no gates at all. The two conditional correction gates are not decided at plan time —
they depend on what a correction *would* do to a specific draft, so the corrections stage
requests them.

---

## 9. Budgets

`BUDGETS` — `max_stages` 40, `max_attempts` 3, `max_corrections` 3, `max_validation_pass` 4,
`max_pages` 12, `max_seconds` 900.

These are ceilings the orchestrator enforces on **its own** orchestration. They are not billing
and they do not replace Phase 10's plan enforcement — a free-plan user is still stopped by
Phase 10, and this is a second, coarser guard that stops one workflow consuming a whole
allowance in a single run.

`spend()` refuses past a ceiling with a 402 and reports used-against-limit. A budget stop is a
*clean stop*, not a failure: the corrections stage returns `succeeded` with
`stopped => budget`.

### Plan usage, and why the executor constructs its own entitlement manager

`Workflow_Executor` reserves via `Entitlement_Manager::begin()`, commits via `settle()` and
releases via `fail()` — Phase 10's service, not a second accounting system.

Two things about this are deliberate:

**The operation names are exact.** `Plan_Limits::OPERATIONS` contains `analysis` and
`generation`, not `analyze` and `generate`. A name outside that list is refused with a 400, which
reads as "no plan has this" — so a typo would silently make every plan look like the free one.
This is not hypothetical: Phases 12 and 13 pass job-type names in seven places and every one of
those routes returns 400 on every request. See §14.

**The manager is constructed when the caller does not supply one.** `Job_Manager::admit()` — the
only other thing in the plugin that reserves usage — has no caller in `includes/`, and the
phase 1–6 REST routes never consult an entitlement manager at all. Without this default, a
reconstruction would be entirely unmetered on a real install.

A reservation is **released on failure** and **settled only when work was produced**. The user id
is remembered, because an earlier version passed `0` to `release_all()`, which the entitlement
manager reads as "not authenticated" — the release would be refused and the reservation would sit
open for two hours, counting against the user's quota.

---

## 10. The correction loop

Bounded at three iterations, and the ceiling is a hard stop rather than "until nothing is left",
because a correction pass can always find something new to propose and a loop that only ends when
differences run out is a loop that may not end.

Stop conditions, all recorded:

| `stopped` | Meaning |
|---|---|
| `none_eligible` | The planner proposed nothing. |
| `all_require_approval` | Everything left needs a human. |
| `iteration_ceiling` | Three iterations reached. |
| `budget` | The iteration budget was exhausted. |
| `quota` | The plan does not allow further corrections. |

Only corrections that do **not** require approval are selected automatically. §11's preference
order puts safe deterministic corrections first, and §17 forbids letting a model drive a
modification — so nothing selected here came from an AI proposal.

A `no_plan` or `draft_not_correctionable` result is a permanent stop for the stage, not a retry.

---

## 11. Quality — and why there is no score

Nine dimensions, each evaluated on its own: `structure`, `visual`, `responsive`, `interaction`,
`content`, `assets`, `editability`, `consistency`, `security`.

**There is no overall score anywhere in this layer.** §11 forbids an unexplained combined number
and the suite asserts the encoded result contains no `score`, `percentage` or `%`. A replica can
be pixel-perfect and structurally unusable, and one figure could not express that even if it
existed.

A dimension that could not be measured is `unavailable` with a reason. It is never given a
number, because a number there would be indistinguishable downstream from a measured one.

### Execution success and reconstruction quality are different questions

| Status | Meaning |
|---|---|
| `passed` | The work is done and the evidence supports it. |
| `passed_with_warnings` | The work is done, and something measurable was incomplete. |
| `needs_review` | The work is done, and a person should look at it. |
| `blocked` | The work could not proceed. |
| `failed` | The work did not finish. |

The distinction that matters most is `passed_with_warnings` versus `needs_review`. A missing
renderer produces the first: validation ran, it just could not compare pixels. A critical
difference the engine could not classify produces the second: someone has to decide.

### Blocking checks

All required pages processed · drafts exist · drafts are an editable Elementor document ·
security checks passed · approvals in place · no critical stage incomplete.

A **published** draft is a blocking failure, not a pass: it would mean something in the pipeline
published without going through the finalisation gate.

An approval is only *required* if the stage it sits at actually ran. A gate at a blocked stage is
not outstanding — nothing was waiting to be approved, and demanding an approval for work that did
not happen would be theatre.

---

## 12. The report

Every §12 section is present, and every number in it came from a record. Absent where not
derivable: no estimated duration for a future run, no accuracy claim, no progress percentage, no
stack trace, no API key, no filesystem path, no renderer token.

`resources` is real accounting: stage counts, artifact count and bytes, budget counters against
their declared ceilings, and `elapsed_seconds` — a measured fact about *this* workflow, which is
different from an estimate of how long a future run would take.

`manual_changes` states the user-control policy and reports that nothing was touched when no
content mapping ran. It does not claim a preservation it did not perform.

`next_actions` is derived from the actual state, so it is never a generic suggestion list.

---

## 13. REST and admin

### Nine endpoints under `/replicaforge/v1/orchestrator`

```
GET    /capabilities
GET    /workflows                              POST /workflows
GET    /workflows/{id}
POST   /workflows/{id}/preflight
POST   /workflows/{id}/run
POST   /workflows/{id}/state                   (paused | queued | cancelled)
POST   /workflows/{id}/approvals
POST   /workflows/{id}/retry
GET    /workflows/{id}/report
```

### Every permission callback is `public`, and that is load-bearing

Phase 16 shipped ten routes whose callbacks were declared `private`. Inside the class
`is_callable( array( $this, 'gate_signed_in' ) )` is **false** for a private method, so WordPress
could not reach the check from outside — and a permission callback that cannot be called is not a
check. All ten endpoints answered anonymous callers until the suite started asserting
`is_callable()` rather than `isset()`.

So every callback here is `public`, and the suite asserts `is_callable()` on each one, plus
`is_callable()` on every callback the REST server actually holds for a registered route. That is
the only assertion that would catch the same mistake.

### Ownership comes from the record, never the request

The id in the URL selects a workflow; the authorization decision then comes from that workflow's
own `created_by` and `project_id`. The listing applies the same check per row — a listing is as
much an IDOR surface as a single fetch.

A missing workflow and someone else's workflow are refused identically, so the route is not a way
to discover which ids are real.

**`reviewer_id` is taken from the session**, never from the body. Accepting one in the request
would let any caller record a decision in someone else's name.

**The repository also checks ownership**, not only the API handler. A defence that exists in one
caller is not a defence.

### The admin screen

Dashboard and detail pages, grouped by what a user needs to do: running, waiting for a decision,
paused and failed, finished.

No progress bar, no percentage, no estimated time. §18 forbids them and the honest substitute is
already available: **N of M stages** and **the name of the stage in flight**. A user can do
arithmetic; what they cannot do is detect a number this plugin made up.

A disabled action is rendered *disabled with a reason*, not omitted — a user who cannot see a
control cannot tell whether it does not exist, does not apply yet, or is forbidden to them, and
the third is worth saying out loud.

The menu registers with `replicaforge_use` and refuses inside the render method, following
`Workspace_Admin`'s documented pattern. Registering with `manage_options` would hide the dashboard
from the designers and reviewers who most need to see it. Neither is the security boundary; the
REST API re-checks every action.

The capability banner is shown whenever anything is missing and names the missing thing, because
the most useful thing the screen can tell someone about to spend money on a reconstruction is that
their site cannot do half of it.

---

## 14. Defects found and fixed during Phase 17

| # | Defect | Consequence |
|---|---|---|
| 1 | `Capability_Registry` memo written after the loop, not per probe | **Out-of-memory fatal.** A probe reading a sibling capability recursed through all 14; 6 MB per level against ~60 MB headroom. The capability report killed the process. |
| 2 | `new WP_Error` inside `namespace ReplicaForge` | Resolves to `ReplicaForge\WP_Error`. Fatal at runtime in 21 places. |
| 3 | `stages_for('incremental')` appended a `sync` stage not in `STAGES` | **Every `incremental` workflow failed validation.** |
| 4 | Two copies of the workflow-id sanitiser, disagreeing on case | **Every deleted workflow leaked its artifacts** into the options table forever. |
| 5 | `delete()` lost the `purge()` call during an edit | Artifacts never purged at all. |
| 6 | `Capabilities::MENU_SLUG` invented | `admin-contract-test.php` fatal. The class has no such constant. |
| 7 | A `Plugin::boot()` edit consumed Phase 16's route registration | **All 10 interaction endpoints vanished.** Caught by the full suite, not by Phase 17's own. |
| 8 | `$definition = new Workflow_Definition(); new ReflectionClass(...)` left in `stage_planning()` | Dead code. |
| 9 | `$this->release( 0, ... )` in `release_all()` | Reservation release refused as "unauthenticated"; quota consumed for two hours. |
| 10 | `gate_status()` omitted `artifact_hash` | A reader could not see which version they approved. |
| 11 | The blocked-prerequisite reason did not name the prerequisite | A reader could not tell which dependency failed. |
| 12 | `Project_Repository::create()` records no owner, and the repository did not check ownership | Workflow creation relied entirely on one API handler. |

Three of these (1, 4, 7) were data-loss or outage class. Two more (3, 6) made a whole workflow
type or screen non-functional. That is the value of running the full suite rather than only the
new one.

### A change to an earlier suite

`phase15-collaboration-test.php` excluded WordPress's namespace descriptor by requiring fewer than
three slashes in the path. That handles a two-segment sub-namespace but not a three-segment one,
so Phase 17's `/replicaforge/v1/orchestrator` marker was counted as an ungated endpoint.

The fix identifies the descriptor by **what it is** — its callback is
`WP_REST_Server::get_namespace_index` — which works at any depth. This strengthens the assertion
rather than weakening it: every real endpoint still must have a callable permission callback.

---

## 15. What is NOT verified

The suite's section 16 is a permanent record, and each entry fails if the excuse stops being
true.

### Never executed

- **No end-to-end run.** There is no reachable public URL in this environment, so specification
  scenarios A–J are **not covered**.
- **No browser driver**, so no interaction state machine has been observed and Phase 16's
  observation is untested through the orchestrator.
- **No render provider**, so no screenshot, visual comparison, responsive sweep or regression
  detection has run. The visual, responsive and regression dimensions are `unavailable` and stay
  that way.
- **No AI provider**, so no AI-assisted planning, ambiguous classification or correction
  prioritisation.
- **No synchronisation service**, so the incremental type cannot apply anything.
- **No WooCommerce**, so e-commerce orchestration and the `commerce_mutation` gate are untested.

### Implemented but not driven

- Plan quota reservation and settlement. `begin`/`settle`/`fail` are called with the exact
  operation names; the arithmetic is not exercised because no plan was charged.
- Multi-page orchestration. A multi-page workflow validates and plans, but no page was generated,
  so "a failed page does not invalidate a successful one" is **untested**.
- Resume after interruption. Checkpoint creation and validation are asserted; actually
  interrupting and resuming is not.
- Concurrent execution. The lock is Phase 11's `Job_Lock`; two simultaneous runs were not
  attempted.
- Cancellation mid-stage. Checked *between* stages; a cancellation during a long stage is not
  tested.
- The REST routes over HTTP. Registered and their callbacks asserted callable, but no request has
  been made through the REST server.
- The admin screens in a browser. The markup is asserted by reading the source; nothing has been
  loaded, clicked or screenshotted.
- The correction loop against a real draft. The iteration ceiling and the no-eligible-corrections
  stop are asserted through the budget; the apply path is not.

### Carried from earlier phases, not introduced here

- **`Project_Repository::add_version()` has no caller**, so Phase 15 can never record a review and
  its own approval gates always read "none". Phase 17 keeps its own approval record and takes
  authorisation from Phase 15, but Phase 15's gate remains unusable.
- **`Job_Manager::admit()` has no caller** and the phase 1–6 REST routes never consult an
  entitlement manager, so analysis, generation, validation and correction outside a workflow are
  unmetered.
- **Phases 12 and 13 pass job-type names where operation names belong** — `check('analyze')` and
  `check('generate')` instead of `'analysis'` and `'generation'` — in seven places. Every one of
  those routes returns HTTP 400 for every user on every plan. Phase 17 does not use those routes
  and does not copy the bug.
- **The phase 2 representation has no durable home** in the existing plugin. Phase 17's artifact
  store supplies one for its own workflows; it does not fix the other phases' routes.
- **`uninstall.php` removes a renderer option name nothing writes**, so the renderer endpoint and
  bearer token survive uninstall.
- **Multi-site is untested** across every phase; preflight warns rather than claiming coverage.

---

## 16. Files

### Created — `includes/orchestrator/`

| File | Role |
|---|---|
| `class-replicaforge-orchestrator-limits.php` | Every constant: 17 stages, dependencies, capabilities, 13 states, transitions, 8 gates, budgets, the shared id sanitiser. |
| `class-replicaforge-capability-registry.php` | 14 probes, three statuses, per-capability memo, re-entrancy guard. |
| `class-replicaforge-workflow-definition.php` | The validated graph, gate selection, plan document, quality targets. |
| `class-replicaforge-workflow-artifacts.php` | Bounded per-workflow storage, schema and hash validation on read, references that cannot smuggle payloads. |
| `class-replicaforge-workflow-repository.php` | Persistence, the state machine, approvals, checkpoints, budgets, `Job_Lock` reuse. |
| `class-replicaforge-preflight.php` | Nine checks, the disclosure-safe report, the mode recommendation. |
| `class-replicaforge-workflow-executor.php` | The run loop, eligibility, per-stage bodies, output verification, plan reservations. |
| `class-replicaforge-quality-gate.php` | Five statuses, blocking checks, nine dimensions. |
| `class-replicaforge-workflow-report.php` | The final report, built from records. |
| `class-replicaforge-orchestrator-api.php` | Nine routes, three public gates. |
| `class-replicaforge-orchestrator-admin.php` | Dashboard and detail screens. |
| `css/orchestrator-admin.css` | Status colours, layout, narrow-screen rules. |

### Created — elsewhere

- `tests/phase17-orchestrator-test.php` — 16 sections, **328 assertions**.
- `docs/ORCHESTRATION.md` — this file.

### Modified

- `replicaforge.php` — 12 requires, version 1.4.0 → 1.5.0.
- `includes/class-replicaforge-plugin.php` — 5 properties, 4 accessors, boot construction, admin
  registration.
- `tests/phase15-collaboration-test.php` — namespace-descriptor discrimination (§14).

### Untouched

No new database tables and **no migration**. `Schema::DB_SCHEMA_VERSION` stays `15.0.0` and
`JOB_SCHEMA_VERSION` stays `11.0`. Workflow records are options, matching the rest of the plugin.

---

## 17. Verification

```
php -l                 255 PHP files, 0 failures
phase17 suite          328 assertions, 0 failures
full suite             25 suites, 6008 assertions passed, 0 failed, 2 skipped
```

Both skipped tests are pre-existing. The full suite went from 24 suites to 25; the assertion
total went from 5680 to 6008, and the +328 is exactly the Phase 17 suite.

Phase 17's own suite is written to the `PASS:` / `FAIL:` convention every other suite uses —
`run-all-tests.ps1` counts assertions by matching that prefix, and a differently-formatted suite
is not *failed*, it is silently *not counted*, which would have made the summary report a total
that quietly omitted everything this phase verified.

### Running it

```powershell
# the whole plugin
powershell -ExecutionPolicy Bypass -File C:\Users\dell\AppData\Local\Temp\opencode\run-all-tests.ps1

# phase 17 alone
php run-test.php <wp-root> wp-content\plugins\replicaforge\tests\phase17-orchestrator-test.php
```

### The assertion that matters most

```php
$this->ok( $callback . '() is PUBLIC, so WordPress can reach it', $method->isPublic() );
$this->ok( $callback . '() is reachable on an instance', is_callable( array( $api, $callback ) ) );
```

Not `isset()`. A permission callback that is set and unreachable does nothing at all, and that
is exactly how Phase 16 shipped ten open endpoints.

---

## 18. Manual configuration

None. Phase 17 has no settings, no schema version to migrate and no activation step. It is
constructed in `Plugin::boot()` and registers routes on `rest_api_init`.

To see it: **Workflows** in the admin menu. To exercise it in this environment you need a
public source URL and a project — `example.com` resolves, so a workflow can be created and
preflighted, but `analysis` will fail on the real fetch, which is the honest outcome and the one
the report will state.

### Manual steps

None required.
