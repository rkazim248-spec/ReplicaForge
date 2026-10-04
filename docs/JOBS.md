# ReplicaForge Jobs and Background Processing

Long work used to be tied to a single browser request. Closing the tab lost it, a
time limit left a partially generated document with no record, and two rapid clicks
produced two drafts. The job system fixes all three.

## 1. What changed

Before: `POST /analyze`, `/ai/analyze`, `/generate`, `/validate`, and
`/corrections/plan` each ran to completion inside the HTTP request.

After: `POST /replicas` queues a job and returns **202** immediately with a job id.
A worker driven by WP-Cron processes one stage per tick. The browser polls
`GET /jobs/{id}` for progress.

There is one implementation of each stage. `Job_Runner` calls
`Analyzer::analyze_url()`, `Ai_Manager::analyze()`,
`Elementor_Generator::generate()`, `Validation_Engine::validate()`, and
`Correction_Engine::plan()` — the same methods the REST handlers call. A job and a
direct request cannot diverge, and a fix to a stage fixes both.

## 2. Statuses

| Status | Meaning | Terminal |
|---|---|---|
| `queued` | Accepted, waiting for a worker. | no |
| `running` | Claimed by a worker with a live lease. | no |
| `paused` | Stopped on purpose at a checkpoint. | no |
| `completed` | Finished successfully. | yes |
| `failed` | Finished unsuccessfully. | yes |
| `cancelled` | Stopped on request. | yes |

A `paused` job is not terminal, which is what makes a resume possible.

## 3. Stages and progress

| Stage | Weight | Cumulative |
|---|---|---|
| `queued` | 0 | 0% |
| `analyze` | 20 | 20% |
| `design` | 15 | 35% |
| `ai` | 20 | 55% |
| `generate` | 20 | 75% |
| `validate` | 15 | 90% |
| `correct` | 8 | 98% |
| `finalize` | 2 | 100% |

Progress is **derived from the stage the job has actually reached**, weighted by
real cost. Nothing is estimated and nothing is animated. A queued job is 0%. A
finished job is 100%. A stalled job shows a stalled bar.

The test asserts progress is monotonic across stages and that the last stage is not
reported as complete before it finishes.

## 4. Idempotency

Three rapid clicks on *Generate* produce one job, not three drafts.

The key is derived from the job type and the work itself — the source URL, draft id,
validation id, or specification id — so a replayed request produces the same key
while a genuinely different request does not collide with it. A client may supply
its own key, which is namespaced by type so one workflow cannot suppress another.

A key is honoured for 15 minutes. A **finished** job is not reused: the user asked
for new work, and returning the previous result would silently do nothing.

## 5. Leases and crash recovery

A worker claims a job by taking a lease valid for 300 seconds.

A worker killed by a PHP time limit never releases its lease. It does not need to:
the lease expires and the next tick takes over. This is what makes a crashed job
recoverable rather than permanently stuck in `running`. The test claims a job,
simulates a dead worker by expiring the lease, and confirms another claim succeeds
and counts as another attempt.

A `heartbeat()` extends the lease so a long stage is not stolen mid-flight.

## 6. Checkpoints and resume

A checkpoint records what each completed stage produced, keyed by the stage that
produced it. On resume, the job restarts at the stage it reached, not from the
beginning — requirement 16, and the reason an expensive analysis is not repeated
after a late failure.

```
queued ──advance──▶ analyze ──advance──▶ design ──▶ ai ──▶ generate ──▶ ...
                    │                     │
checkpoint[queued]   checkpoint[analyze]  checkpoint[design] ...
```

A bulky value is **not** stored in the job record. The Phase 2 representation is
written to a bounded, one-day transient and the checkpoint holds a 16-character
reference. The job list therefore stays small enough to read in one query, and a
hundred retained jobs do not mean a hundred retained analyses. The value reads back
intact, so a resumed stage still has its data.

Deleting a job deletes its payloads. Pruning a finished job does too.

## 7. Retry

Only a transient failure is retried. Retryable codes are timeouts, rate limits,
temporary provider errors, and network failures. **Not** retried: an invalid URL, a
rejected AI response, a permission failure, a security block, an invalid Elementor
specification, or an unrecognised code.

Backoff is exponential from 30 seconds, capped at 900. Three attempts maximum.
After that the job is `failed` and waits for a person.

A retryable failure returns the job to `queued` with `next_attempt` in the future —
never an immediate re-run, so a provider outage does not become a tight loop.

## 8. Recovery

| Situation | What the user sees | Available actions |
|---|---|---|
| Browser tab closed | Job continues on cron | — |
| Worker killed | Lease expires, next tick resumes | — |
| Retryable failure | Retried with backoff, then `failed` | Resume / Retry |
| Permanent failure | `failed` with a message | Resume (if attempts remain) / Delete |
| Too many attempts | `failed` | Delete only |
| Queued or running | Live progress | Cancel |
| Paused | Stopped at a stage | Resume / Cancel / Delete |
| Cancel | `cancelled`, record kept | Delete |

`cancel` never deletes a draft the job already produced. The draft is the user's
content, and a job record is not the place to decide what that means.

`can_resume` respects the attempt budget, so the screen never offers an action the
API will refuse. That consistency is asserted by the test.

## 9. Scheduling

Two scheduled hooks:

| Hook | Schedule | Work |
|---|---|---|
| `replicaforge_daily_maintenance` | Daily | Expired transients, finished jobs, old log entries, expired idempotency records |
| `replicaforge_process_jobs` | Every minute | Up to 2 jobs, one stage each |

A custom `replicaforge_minute` schedule is registered on the `cron_schedules`
filter.

WP-Cron only fires on traffic, so the one-minute interval is a **ceiling on
latency, not a guarantee**. A quiet site may wait. A site with real cron should call
`wp-cron.php` or the hook directly.

`Plugin::ensure_scheduled()` checks on every load and repairs a missing schedule. A
schedule is lost when a plugin is deactivated and reactivated without its
activation hook, or when an update replaces the files. An unscheduled job queue
would silently stop processing, so it is repaired rather than assumed.

The **System Status** screen shows the next run and warns when `DISABLE_WP_CRON`
is set.

## 10. The feature flag

`background_jobs_enabled` (default on) turns processing off without uninstalling.
With it off, `process_jobs()` returns `skipped: background_jobs_disabled` and logs
why, rather than silently doing nothing.

## 11. Retention

- 100 job records. Active jobs are never dropped; finished ones are pruned first.
- Finished jobs older than 14 days are removed. Unfinished jobs are kept
  indefinitely, because dropping one loses the user's work.
- Idempotency records expire after 15 minutes, capped at 500.
- Stage payloads expire after 1 day.

All four are configurable on **System Status** within range-checked bounds.

## 12. What the user sees

**History** lists every job: source host, status, stage, progress, result link,
start time, duration, and actions. Filters narrow by type and status. The screen
becomes a card list below 782px.

Progress is a real `role="progressbar"` with `aria-valuenow` and an accessible name
that includes the status, so a percentage is never the only signal. A finished bar
differs in colour as well as length.

**Dashboard** shows recent jobs, counts by status, and the system status.

Job actions post a nonce-checked form rather than following a bare link, so an
action cannot be triggered by a prefetch or an image tag on another site.

## 13. Known limitations

- A job on a site with no traffic waits, because WP-Cron is traffic-driven.
- A stage that runs longer than 300 seconds without a heartbeat can be taken by a
  second worker. The heartbeat exists; the current stages complete well inside the
  lease on a measured page.
- A job that resumes after a day re-runs its analyze stage, because the payload has
  expired. It does not re-run completed generation.
- Two workers racing on the same job are prevented by the lease, not by a database
  lock. With a single-site install that is sufficient.

---

## Phase 11: job reliability

`Job_States` declares eleven states and the legal moves between them. `Job_Limits`
builds its `STATUSES` from that list, so the Phase 7 and Phase 11 vocabularies are
one list rather than two that can drift.

| State | Meaning |
|---|---|
| `queued` | Waiting to be claimed. |
| `reserved` | Claimed, locks held, not yet executing. |
| `running` | Executing a stage under a live lease. |
| `waiting` | Waiting on something outside itself, usually a provider rate limit. |
| `retrying` | Waiting out a backoff. |
| `paused` | Stopped deliberately, resumable. |
| `completed` / `failed` / `cancelled` | Terminal. |
| `expired` | The attempt window closed because a lease died. |
| `blocked` | Cannot proceed until something external changes. |

`expired` is terminal **by decision**, not oversight: a job whose worker died may
already have modified an Elementor document, and re-running it could apply a second
set of changes. Resuming is an explicit, separately audited act.

`Job_Repository::claimable()` now handles `queued`, `retrying`, and `waiting` in one
branch, because all three mean "not yet" and all three honour `next_attempt`.
Previously a job waiting fifteen minutes was indistinguishable from a job that had
never run.

Full detail in `RELIABILITY.md`.

---

## Phase 12: multi-page generation plans

Phase 12 does **not** add a queue, a runner, or a scheduler. It produces an ordered,
isolated plan that Phase 11's existing orchestration is intended to execute.

### Ordering (`Site_Limits::generation_order()`)

```
global_design ? assets ? header ? footer ? shared_components ? templates
              ? pages_primary ? pages_secondary ? pages_collection
              ? navigation ? responsive ? validation
```

The dependencies come first so a failure part-way through leaves a usable site rather
than pages referencing a design system that was never built.

### Every step is a draft

`draft => [ 'status' => 'draft', 'publish' => false ]` on every step, and no code path
in `includes/multipage/` sets a post status to `publish`. "We never publish" is
guaranteed by a test that scans the Phase 12 directory with comments stripped.

### Failure isolation is structural

One step per page, each with its own `status` and `attempts`. No step's failure can
change another's, a failed page is retried alone, and nothing deletes a draft because
a sibling failed. `Cross_Page_Validator` reports a failure as a finding that explicitly
names how many *other* pages are unaffected § the isolation requirement is stated in
the output, not just implemented.

### Sync scope is computed, not guessed

`Multi_Page_Planner::sync_scope()` walks the registry: a page in no shared structure
returns `page_only` with an empty affected set; a page in a shared header pulls in
every other page using it and names both `changed_on` and `also_on`. A design-system
change is only claimed against a **known baseline** § with no recorded baseline the
answer is "not shown to have changed", because rebuilding everything on an unknown
baseline is the opposite of §58.

### Known gap

**The wiring that turns a plan step into a `Job_Manager` job is not written.** The
plan is correct, ordered, and isolated; nothing executes it. See
`docs/PHASE-12-COMPLETION-REPORT.md` §25.2.
## Phase 13: visual render jobs

Render jobs use the **Phase 11 queue**. No second queue, no second set of states, no
second recovery path. `Render_Job` produces a payload in the vocabulary `Job_Repository`
and `Job_Checkpoint` already understand, and the existing runner executes it.

| Concern | Decision |
| --- | --- |
| Stages | Reuse `Job_Limits::STAGES[0..3]` rather than appending, because appending changes the `stage_index()` arithmetic recovery depends on. |
| Checkpoint | `viewport_index`, so a killed render job resumes at the viewport it reached. |
| Bound | `Visual_Limits::MAX_RENDERS_PER_JOB` = 75. Exceeding it **reduces the page count and reports it**, never silently. |
| Cancellation | Supported by the existing queue. |
| Planning | A plan only. The wiring that executes a plan step is unwritten; see the Phase 13 report. |

### Stabilization is a request, not a guarantee

```
load -> dom -> network_idle -> images -> lazy_load -> animations_off -> capture
```

`Render_Job::stabilization()` returns that plan, the requested waits (bounded at 10 s in
the renderer, because an unbounded wait is a denial-of-service lever aimed at whoever hosts
the renderer), and a `guarantees` string stating that a page which never settles cannot be
made stable. When the wait expires the capture is taken anyway and the region is marked
dynamic rather than reported as a reconstruction fault.

### Partial failure is normal

One viewport failing is not a job failure. A failed capture returns `degraded: true` with a
reason, the other viewports proceed, and the dashboard shows the failed viewport as
`unavailable` -- never as a pass.

---

## Phase 14 â€” Content and Data Intelligence

### No new queue

Phase 14 uses Phase 11's job system. It creates no queue, no table, and no runner.

### Declared job types, not yet enqueued

| Type | Would run |
|---|---|
| `content_analysis` | Role detection and model building for one page |
| `entity_detection` | Source entity detection |
| `bulk_matching` | Â§20 matching across a store |
| `mapping_validation` | Plan validation |
| `mapping_application` | Â§22 apply with snapshot and rollback |

`Content_Entity_Matcher::match()` is **paged** and reports `partial: true` with the true
total when it processes a subset, so it is already shaped for a background job. Nothing
calls it from a runner yet â€” a single REST call processes one page of sources against a
bounded batch of destinations.

This is a known limitation, recorded in `docs/CONTENT-INTELLIGENCE.md` Â§22 and repeated
here so the two documents cannot disagree.

### Metering

Three new `Plan_Limits` operations, because content mapping has three different costs:

- `content_analysis` â€” reading a page's content
- `content_mapping` â€” building a plan
- `content_apply` â€” **writing into the user's own store**

`content_apply` is metered most tightly: it is the only operation in the plugin that
changes a record the user cares about, as opposed to a draft ReplicaForge owns. All three
gate on the `content_mapping` feature.