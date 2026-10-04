# Reliability

How a ReplicaForge job behaves when things go wrong. Most of this document is
about failure, because that is the part that is not obvious from reading working
code.

---

## 1. What was reused

Phase 7 built a real job system and Phase 11 did not replace any of it.

| Phase 7 | Still owns | Phase 11 added beside it |
|---|---|---|
| `Job_Repository` | Records, payloads, `claimable()`, `counts()` | `claimable()` taught the five new states |
| `Job_Queue` | Lifecycle, idempotency, lease, heartbeat, pause, resume, cancel | `fail()` gained `retry_after` and `retrying` |
| `Job_Runner` | The eight stages | — |
| `Job_Limits` | Stages, weights, backoff, lease, idempotency TTL | `STATUSES` now delegates to `Job_States`; backoff gained jitter |
| `Job_Api` | REST routes and the admin screen | — |

There is **one** queue. `Job_Manager` is an orchestrator *around* it, not a second
one: it decides whether a stage may start, it holds the locks, and it enforces the
time budget. The queue still owns every job record.

---

## 2. States

Eleven, in `Job_States`. The six Phase 7 states keep their original meanings.

| State | Meaning |
|---|---|
| `queued` | Waiting to be claimed. Has never run. |
| `reserved` | Claimed, locks held, execution not yet begun. |
| `running` | Executing a stage under a live lease. |
| `waiting` | Waiting on something outside itself — usually a provider rate limit. |
| `retrying` | Waiting out a backoff after a failure. |
| `paused` | Stopped deliberately, resumable. |
| `completed` | Finished successfully. |
| `failed` | Finished unsuccessfully after exhausting its attempts. |
| `cancelled` | Stopped deliberately, not resumable. |
| `expired` | The attempt window closed, usually because a lease died. |
| `blocked` | Cannot proceed until something external changes. |

The five additions each exist because a situation genuinely occurs that the six
could not describe. `retrying` and `queued` were the same thing, which meant a job
waiting fifteen minutes looked identical to a job that had never run — so a
dashboard could not answer *why is nothing happening*.

**`Job_Limits::STATUSES` is built from `Job_States::ALL`**, not restated. Two
definitions of the same list drift, and the drift shows up as a job state that one
class accepts and another refuses.

### `expired` is terminal, and that is the decision

A job whose lease died may already have modified an Elementor document. Re-running
it could generate a second draft, or apply a second set of changes to the first.
So expiry **ends the attempt** and hands the decision to a person. `resume()` will
still do it, and the record notes that it was resumed by hand.

The five states `Job_Recovery` treats as unsafe to re-run automatically: reaching
`generate`, `validate`, or `correct` means something may have been written.

---

## 3. Locks

A job lease answers *is this job still alive?*. A lock answers *is anybody else in
here?*. They are different questions.

Without a project lock, a generation and a correction on the same project both write
to the same Elementor document. Phase 6 protects a document by snapshotting before a
write — which means the second writer snapshots the first writer's half-finished
work. **A snapshot of a corrupt document is still a corrupt document.**

`Job_Lock` uses `add_option()`: a single `INSERT` against a unique column, so
exactly one concurrent process can create the row. (`update_option()` is a read then
a write; two processes doing that at once means one write is lost.)

| Requirement | How |
|---|---|
| Expires safely | Every lock carries an expiry; a past one is takeover-able. |
| Recoverable | A takeover records `took_over_from` and increments `takeovers`. |
| No stale permanent locks | A lock is honoured only while live *and* token-matched, so a process that lost its lock finds out. |
| Includes ownership | Records the owner, the process id, and when it was acquired. |

### Re-entrancy requires the token *and* the owner

A holder may re-acquire its own lock — two stages of one job legitimately do. But
matching the **token alone** is a bug: two different jobs in one request share this
process, so a token check would treat the second job as the first re-entering. That
is precisely the collision the lock exists to prevent, and it is invisible because
both jobs are in the same process. Found by the Phase 11 suite.

A re-acquisition **updates** the existing row. Calling `add_option()` on an existing
name always fails, so a re-acquisition would be reported as a contention and a job
would deadlock against itself between two of its own stages. Also found by the suite.

Locks are released by a shutdown handler, so a fatal error cannot leave one held
until its TTL.

---

## 4. Checkpoints

Phase 7's `advance()` already accepted a checkpoint. What did not exist was a
**shape** — and a shape is the whole point, because a checkpoint nobody can read is
one that cannot be resumed from.

Three rules make it trustworthy:

1. **Only a declared stage may be recorded.** An unrecognised stage is dropped, so a
   typo cannot produce a record claiming a job finished something it never started.
2. **Monotonic.** A checkpoint never moves backwards. A late write from a superseded
   stage must not roll a job back, or the job loops between two stages for ever.
3. **Bounded.** 8 KB, 200 list entries, 200 characters per scalar.

The list of completed stages is **not** padded with the stages between reported ones.
A checkpoint is a record of what a stage said it had done; inventing the
intermediate stages would let a bug that skipped `design` look like a checkpoint
that had passed through it.

A checkpoint is a **pointer** to intermediate data, not the data. A design
representation has its own store with its own TTL; the checkpoint records where it
is.

Progress is derived from the stage list and never invented, so **a stalled job shows
a stalled bar** rather than one that advances on a timer.

---

## 5. Retries

Exponential, capped, **and jittered**.

```
attempt 1  →   30s   + 0…15s
attempt 2  →   60s   + 0…30s
attempt 3  →  120s   + 0…60s
      …    →  900s ceiling
```

Jitter was the missing piece and it is not a refinement. Without it every job that
failed at the same moment retries at the same moment: a provider goes down at 14:00,
forty jobs fail at 14:00, and all forty come back at 14:00:30 to find it still down.
A brief provider outage becomes a sustained one, and that is a property of the
*plugin*, not the provider.

Two properties of the window:

- It is the **upper** half, so a delay is never shorter than the computed backoff.
  Full jitter would be wrong: the backoff exists because the provider asked for
  distance, and a jitter that sometimes removes the distance defeats it.
- The result is **clamped to `RETRY_MAX_SECONDS` afterwards**, so the constant stays
  the longest ReplicaForge will ever wait. At the ceiling the jitter is a no-op,
  which is correct — a user who has waited fifteen minutes is not helped by sixteen.

The value is derived from the seed, attempt, process, and clock rather than a random
number, so two jobs on one site do not synchronise while a given job's schedule stays
reproducible in a log.

Only `Error_Catalog::retryable` failures retry. A missing API key, an invalid
request, and an unsupported model are the same request failing the same way again.

---

## 6. Recovery

`Job_Recovery::sweep()` runs **first** in every tick, so the first person to look at
the queue sees the real state rather than one tick of a stuck job looking normal.

A job is stuck when it is active, its lease is more than `STUCK_GRACE` (300s) past
due, and nothing has updated it. One lease period of grace means a slow worker is
not declared dead.

| Checkpoint reached | Action |
|---|---|
| `queued` … `ai` | `retrying`, with jittered backoff, lease cleared |
| `generate`, `validate`, `correct` | `expired`, terminal, awaiting a person |

The daily maintenance pass also runs it, so a job left by a crash between ticks is
resolved on a schedule rather than at the next page load.

---

## 7. Cancellation

Cooperative, because PHP has no safe way to stop a function mid-execution.

1. `cancel()` records a request and returns. A **queued** job has no worker to tell,
   so it is settled immediately.
2. A **running** job keeps its lease and stops at its next stage boundary.
3. The worker then releases its locks and marks the job `cancelled`, so the project
   is available to the next job immediately.

The pairing with a lock and a checkpoint is what makes it safe: a write stage checks
for cancellation *before* it writes, so a cancellation is never honoured halfway
through an Elementor write. A stage that had already written does **not** roll back
here — that is `Correction_Applier`'s job and it already does it. Cancellation
reports what happened; it does not invent a second undo path.

Requests expire after a day, and a job that is settled clears its request — so a
stale flag cannot cancel a job retried under a new attempt.

---

## 8. Time budgets and concurrency

### Why a tick has a budget

WP-Cron fires inside a normal page request, on a host with a shared time limit and no
say over how many workers exist. A tick that starts three jobs at 25 seconds each
hits the limit and is killed partway through the third, leaving a job that says it is
running, a half-written draft, and a lock held until its TTL. Measuring the
remaining budget and stopping cleanly at a stage boundary converts all three into a
job that is simply not finished yet.

A tick uses `TIME_SHARE` (half) of `max_execution_time`, leaving room for the
shutdown work — releasing locks, writing the lease — that makes it recoverable.

### AI concurrency

`ai_concurrency` defaults to **2**. That is not a guess about a server; it is a guess
about a plan. Most self-hosted sites share one address with several other sites, and
a provider that sees one account issuing a burst from one address will rate limit it
regardless of how many workers the host has.

A job needs a slot when its **next** stage is `ai`. Judging by whether its *current*
stage is `ai` is wrong in both directions: a job sitting in `ai` holds a slot while
doing nothing, and a job about to enter `ai` is admitted only to find it has no slot
after claiming a worker and a lock.

### Per-user job limit

`user_jobs` (default 5) is separate from the Phase 10 usage limits, and both are
enforced. A plan can allow a hundred generations a month and still be right to refuse
five at once.

A refused admission **releases its usage reservation** rather than settling it —
charging a user for a job that was never queued is exactly the accounting bug §38 of
Phase 10 is about. The Phase 11 suite asserts a refused admission charges nothing.

---

## 9. Crash recovery, end to end

```
job running
  → PHP process dies
  → the lease is not renewed
  → the lock's expiry passes
  → a later tick sees the lease is past due
  → Job_Recovery runs first in that tick
      → resumable: status becomes retrying, lease cleared, jittered backoff
      → had written: status becomes expired, terminal, a person decides
```

The project stays valid in both cases, because the only thing that writes a document
is Phase 6, and Phase 6 snapshots before it writes and rolls back if anything fails.
Phase 11 has **no write path at all**, which the test suite asserts by reading the
Phase 11 sources with comments stripped.

---

## 10. What was not built

- **`Source_Monitor` does not exist.** Phase 9 built detection and classification
  but no monitor, so no cron event watches a source page and a `source_monitoring`
  job type has nothing to run. The vocabulary is ready for it.
- **The admin screens do not exist.** `Job_Api` has REST routes; there is no
  diagnostics page, no jobs dashboard, and no progress UI beyond the REST payload.
- **`Job_Runner::process()` does not accept a time budget.** `Job_Manager` passes
  one and `Job_Runner` ignores it, so the budget currently stops the tick between
  jobs rather than between stages. This is the most important integration gap.
- **No multi-stage AI reconstruction.** The budget chunks a context; nothing drives
  the stages.
- **No partial-failure accounting.** A job either completes or fails; there is no
  "8 of 10 sections succeeded" state.
- **No profiling in debug mode.** §51 asks for it; `Feature_Flags` has no
  `profiling_enabled` flag and nothing measures stage timings.
