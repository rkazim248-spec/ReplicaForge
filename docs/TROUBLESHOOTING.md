# ReplicaForge Troubleshooting

Start at **ReplicaForge → System Status**. It answers most of these questions
directly, and every failing check names the action that fixes it.

## 1. Nothing happens when I click a button

The request is probably still running. A guided operation can take a while on a
large page.

1. Open the browser's network panel and look for the request to
   `/wp-json/replicaforge/v1/...`.
2. If it is pending, the phase is still working. Wait.
3. If it returned an error, the `message` is written for a person. Read it.
4. Check **System Status → Logs** and filter by the level shown.

If the button is disabled, another request is in flight. The UI disables duplicate
actions deliberately, because two concurrent generations produce two drafts.

## 2. "Elementor is required to generate a draft"

`elementor_missing` or `elementor_inactive`. Check **System Status → Elementor**.

| Message | Action |
|---|---|
| Elementor not installed | Install and activate Elementor. |
| Elementor installed but not active | Activate it. |

Analysis, AI planning, and the correction engine's read paths work without
Elementor. Only generation needs it.

## 3. A job sits in `queued` and never starts

WP-Cron only fires on traffic. A quiet site may not tick it.

1. **System Status → Background jobs** shows the next scheduled run.
2. If `DISABLE_WP_CRON` is set, the screen warns and explains. Set up a real cron
   calling `wp-cron.php`, or run the job directly.
3. To run it now: `wp cron event run replicaforge_process_jobs`, or visit the site
   to trigger a tick.

**Resume** on the job restarts it at the stage it reached. It does not begin again
from the beginning.

## 4. A job failed with a network error

Retryable codes are timeouts, rate limits, and temporary provider errors. The job is
rescheduled automatically with exponential backoff, up to three attempts.

- **Resume / Retry** after confirming the destination is reachable.
- A source site that is genuinely down will fail three times. The message says so.

Codes that are **not** retried, because they will fail identically every time:
`invalid_url`, `blocked_destination`, `forbidden`, `ai_output_rejected`,
`specification_invalid`, `elementor_data_invalid`.

## 5. A job failed with a security refusal

`blocked_destination` and its relatives mean the address is not a public webpage.
ReplicaForge refuses loopback, private ranges, link-local, the cloud metadata
endpoint, credentials in a URL, non-standard ports, and administrative paths.

This is working as designed. There is no override, by design: an analyzer that can
be pointed at an internal address is a liability.

## 6. Corrections say the draft changed after the plan was created

`plan_document_changed`. The draft was edited after the plan was made, so the
reviewed corrections no longer describe it. Re-plan them.

This is the manual-edit protection. It exists so a correction is never applied to a
document the person did not review.

## 7. A property is listed as `blocked`

Either it is outside the writable whitelist, or the resolved element type has no
control for it.

`blocked` means ReplicaForge will not offer it, and the apply step would refuse it.
Offering a change that cannot be delivered is worse than saying so.

`requires_review` is the state where a change is possible but needs a person to
approve it. Content, section removal, column count, and navigation are never
auto-fixed and are always at least `requires_review`.

## 8. No corrections were planned

Possible causes, in the order to check:

1. **No differences were measured.** Check the validation report. A high score means
   little to correct.
2. **The validation result expired.** Validation records are transient. Re-run
   validation.
3. **`auto_correction_enabled` is off.** Check the feature flags in the status
   response.
4. **The plan is empty.** The plan report lists why each candidate was excluded.

## 9. A correction was applied but nothing changed

Check the run summary. A run that wrote nothing reports `no_change`, not
`completed`, and the closing sentence says so.

If a correction reports `failed`, the reason is in the run record. The writer now
records the property, element, and control on a failure, so the reason is specific
rather than generic.

## 10. A rollback did not restore everything

Check whether the snapshot is still restorable. Snapshots live in a transient that
expires. A summary that points at an expired transient is marked `legacy` and
removed by the cleanup.

**System Status → Stored data** shows the snapshot count.

A rollback restores `_elementor_data` **and** `_elementor_responsive`, including
deleting the responsive map when the snapshot had none. The restored document hash
is compared with the snapshot hash and both are shown.

## 11. A device correction is not visible in the editor

A device override is stored in `_elementor_responsive` keyed by element id, not in
the element's settings. If you inspect only `_elementor_data` you will not see it.

1. Open the document in the Elementor editor.
2. Select the element.
3. Switch to the **Tablet** or **Mobile** tab at the bottom of the editor.

If it is still absent, the correction's `viewport` may have been `desktop`, which
writes to `settings`. The run record shows the viewport for each correction.

## 12. The admin screens look wrong

1. Confirm the stylesheet loaded. **System Status → Plugin files** reports whether
   the directory is readable.
2. Hard-reload the admin page.
3. Check for a CSS conflict. ReplicaForge's styles are scoped to
   `.replicaforge-admin` and load only on ReplicaForge screens.
4. An unusual admin colour scheme may not provide the custom properties. The
   literals are fallbacks that match the default WordPress admin.

## 13. The logs are empty

The minimum level is `info` unless `WP_DEBUG` is true, in which case it is `debug`.
Set `log_level` in `replicaforge_settings` to `debug` while investigating.

The log holds 500 entries by default and 5000 maximum. A busy site can rotate past
the entry you want, so clear the log and reproduce the problem to capture it.

**Export log** produces a redacted file suitable for a support request.

## 14. Analysis returns partial results

A limit was reached. The report names the limit and says what was skipped.

| Limit | Meaning |
|---|---|
| HTML size | The page is very large. A smaller page works. |
| Stylesheet count or size | Some stylesheets were skipped. |
| DOM depth | Parsing stopped descending. |
| Image or link count | Collection stopped at the cap. |
| Memory | The server limit was reached. Raise it, or use a smaller page. |

Every one of these reports partial analysis plus a warning. None of them fails
silently.

## 15. Storage keeps growing

Run **System Status → Run cleanup now**, or read the counts under **Stored data**.

The scheduled task runs daily, so growth between runs is expected and bounded. If it
is not being cleaned automatically, check the schedule:

- **System Status → Background jobs** shows whether the schedule exists.
- `Plugin::ensure_scheduled()` repairs a missing schedule on every load.

Retention values are configurable on the same screen within range-checked bounds.

## 16. I need to see what is actually stored

| Looking for | Where |
|---|---|
| A job | **History**, or `replicaforge_jobs` |
| A log entry | **Logs**, or `replicaforge_log` |
| A validation record | The transient `replicaforge_validation_result_*` |
| A correction plan | The transient `replicaforge_correction_plan_*` |
| A generated document | `_elementor_data` post meta |
| A device override | `_elementor_responsive` post meta |
| The AI key | `replicaforge_ai_settings`. Do not paste it anywhere. |

## 17. Reporting a problem

Include:

1. The `request_id` from the log entry.
2. The ReplicaForge version, from **System Status**.
3. WordPress, PHP, and Elementor versions, from the same screen.
4. **System Status → Logs → Export log**. It is redacted.
5. What you did, what you expected, and what happened instead.

The `request_id` ties the HTTP request, the job, the generation, the validation, and
the log lines together, so it usually makes the difference between a fixable report
and an unreproducible one.

---

## Phase 10: plans, usage, and licensing

### "It says I have no plan, but the setting says Pro"

This is the licensing gate doing its job, and it surprises people every time.

The configured plan is what an administrator *set*. The licensed plan is what the
license *grants*. With no local licensing record, the license is `inactive`, and
**an inactive license resolves to the free plan regardless of the configured
plan.**

That is deliberate: if the configured plan were honoured regardless, the license
would be decorative — an administrator who set "Pro" would get Pro whether or not
any license existed, and the whole feature would be a label on a settings screen.

Check what each side says:

```php
$licenses = new \ReplicaForge\License_Manager();
print_r( $licenses->diagnostics() );
// configured_plan => pro
// licensed_plan   => free
// state           => inactive
```

To run the paid plan, set the local license state to a granting state:

```php
( new \ReplicaForge\Local_License_Provider() )->store(
    array( 'state' => \ReplicaForge\License_State::ACTIVE )
);
```

### "My plan changed but the screen still shows the old one"

The compiled plan set is cached in the object cache for 300 seconds, and **a cache
does not watch for a filter being added.** If an installation installs
`replicaforge_plan_definitions` at boot, it must flush once after registering:

```php
\ReplicaForge\Plan_Storage::flush_cache();
```

`Plan_Storage::store()` and `Plan_Storage::reset()` flush on their own. Only a
filter needs the manual call, and only once.

### "A failed operation still used my allowance"

It should not, and if it did that is a bug. Check the usage tail:

```php
print_r( ( new \ReplicaForge\Usage_Manager() )->recent( $user_id, 10 ) );
```

Each record carries a `metadata.outcome` of:

| Outcome | Meaning | Charged |
|---|---|---|
| `committed` | The work completed. | Yes, `quantity`. |
| `released` | It failed or was cancelled. | No, `quantity` is `0`. |
| `expired` | Nobody settled it within two hours. | No, `quantity` is `0`. |

A `released` or `expired` record is written **on purpose**. A user seeing "1 / 2
used" with no explanation of the second attempt cannot tell a working meter from a
broken one.

If a `committed` record exists for an operation you believe failed, the question is
whether the operation really failed. A generation that wrote its draft and then
errored on the response *did* do the work, and under-charging it is the
deliberate bias — see `PLANS-AND-USAGE.md` §5.

### "Another ReplicaForge operation is still finishing"

A `409 usage_lock_held`. The user has **not** run out of allowance; two requests
arrived at the same moment and one of them is inside the critical section. Retrying
in a moment works.

Locks are held for at most 30 seconds. If this persists, something is looping
inside a usage critical section — check the log for `usage_critical_section_failed`.

### "My trial did not apply"

Four reasons, in the order to check them:

1. **Trials are off.** `Plan_Storage::trial_settings()['enabled']` is `false` by
   default, and a development installation should not hand out paid entitlements on
   its own.
2. **The trial is available but not started.** An *available* trial grants nothing.
   A user must call `License_Manager::start_trial()`.
3. **The site is not on the free plan.** A trial only ever upgrades the free plan.
   A site already on a paid plan stays there, because applying a trial would be
   either a downgrade or a no-op.
4. **The trial plan does not exist.** If the configured trial plan was removed from
   the plan set, no trial starts. A trial pointing at a plan that no longer exists
   must not grant anything.

```php
print_r( ( new \ReplicaForge\License_Manager() )->trial( $user_id ) );
// 'reason' is one of: disabled, no_user, available, running, expired,
//                     consumed, trial_plan_missing, trials_disabled_after_use
```

### "Everyone is refused with a capability error"

Check for orphaned capabilities:

```php
print_r( \ReplicaForge\Capabilities::orphan_check() );
// array( 'ok' => false, 'orphaned' => array( 'replicaforge_use' ) )
```

A capability no role holds is a capability nobody can pass, and the symptom is
every user being refused with no explanation. Fix:

```php
\ReplicaForge\Capabilities::grant_default_roles();
```

This is usually an install that was copied from a database, or a multisite network
where the activation hook only ran on the main site.

### "There is no upgrade button"

**That is correct.** No billing provider is connected, so there is nowhere to
purchase, and ReplicaForge does not pretend otherwise. Every locked action links
to the plan details page, which says:

> This installation has no billing provider connected. Plans are a local
> configuration: an administrator can change the plan for this site, and
> ReplicaForge enforces it. No payment is taken and no purchase is simulated.

If you *are* selling plans, implement `Billing_Provider_Contract` and add it with
`add_filter( 'replicaforge_billing_provider', ... )`. The prompts will then say
that a provider is connected. Nothing in the plan system needs to change.

### "The plan matrix does not show my custom plan"

Flush the cache (§2 above), and check that the filter returns an array with the
plan keyed by a valid id:

```php
$plans['studio'] = array( 'name' => 'Studio', 'limits' => array(), 'features' => array() );
```

A filter that returns nothing at all is **ignored**, and the shipped set is used
instead. A plugin with no plans cannot do anything, and a filter that empties the
set is almost certainly a mistake.

### "An imported plan configuration was rejected"

The importer reports both what it stored and what it rejected. The usual causes:

- A plan id containing anything other than `a-z`, `0-9`, and `_`.
- A limit name that is not in `Plan_Limits::LIMIT_NAMES` — it is dropped, and a
  plan missing a limit reads that limit as *unlimited*.
- A feature name that is not in `Plan_Limits::FEATURES` — it is dropped, and a
  feature the plan does not mention is *withheld*.

Keys that are not part of a plan are dropped rather than stored. That is what makes
an import safe: there is no path from an imported value to execution.

### "Where do I see the audit log?"

`GET /replicaforge/v1/audit` (administrator only), or:

```php
print_r( \ReplicaForge\Audit_Log::recent( array( 'event' => 'plan_changed' ), 20 ) );
print_r( \ReplicaForge\Audit_Log::summary() );
```

The ring holds the last 500 entries. A burst of denials can push an earlier event
out — it is a recent-history record, not a compliance archive.

---

## Phase 11: jobs and the AI provider

### "My job says Waiting and nothing is happening"

That state is doing its job. `waiting` means the job is waiting on something
outside ReplicaForge — almost always a provider rate limit — and the job is scheduled
to be retried at the moment the provider asked for.

Check the wait:

```php
print_r( \ReplicaForge\Job_Manager::settings() );
$job = ( new \ReplicaForge\Job_Repository() )->find( $job_id );
echo gmdate( 'c', (int) $job['next_attempt'] ), "\n";
```

Nothing is wrong. A `retrying` job, by contrast, is waiting out ReplicaForge's own
backoff, which is a jittered exponential delay after a failure.

### "My job says Expired"

The worker running it went away — a PHP timeout, a killed process, a host restart —
and it had already begun writing, so ReplicaForge did **not** retry it automatically.
Re-running it could apply a second set of changes to a half-written Elementor draft.

Retry it deliberately:

```php
( \ReplicaForge\Plugin::instance() )->orchestrator()->resume( $job_id );
```

This is logged as `job_resumed_after_write`, so the record shows it was a manual
decision. Before you do, open the draft in Elementor and check its state.

### "My job is stuck on a lock"

```php
print_r( ( new \ReplicaForge\Job_Lock() )->report() );
```

`live` is the number currently held, `stale` is the number past expiry, and `stuck`
is the number taken over three or more times — which means a worker is being killed
mid-stage rather than crashing once.

A job refused a lock is **deferred, not failed**. Nothing went wrong; the tick moves
on and the job is picked up later. If a lock is never released, a shutdown handler
should have released it, and its `expires_at` is the backstop.

### "Two jobs on the same project ran at once"

They should not have. A project lock is taken before a job's first stage and held
across them, and released on every exit including a fatal one.

Check whether the lock was released or expired:

```php
$lock = ( new \ReplicaForge\Job_Lock() )->inspect( 'project_proj_abc123' );
print_r( $lock );
```

If `live` is `false` and `takeovers` is high, a worker is dying mid-stage. The
message in the log for `job_lock_takeover_repeated` names the resource.

### "The AI provider is being rate limited and my jobs keep waiting"

Correct behaviour, and it is the provider's request being honoured exactly:

```php
$job = ( new \ReplicaForge\Job_Repository() )->find( $job_id );
echo 'waited: ', (int) $job['retry_after'], "s\n";
```

A `429` whose body mentions a quota, credit, or billing is classified
`AI_QUOTA_EXCEEDED` instead — that one is **not** retried, because waiting does not
refill an account, and not fallback-worthy, because a fallback shares the account.

If you are hitting rate limits repeatedly, the relevant setting is `ai_concurrency`
(default 2), not `tick_jobs`. A job needs a slot only when its **next** stage is
`ai`, so the limit applies to AI work rather than to the whole queue.

### "The AI response was rejected for containing a secret"

`AI_INVALID_RESPONSE`, deliberately not retried. A response that looked like it
carried a secret is discarded rather than parsed, because a model that echoes a
credential is not a model whose next attempt will behave.

Nothing is written, and the user message says so without naming the provider or
quoting anything.

### "The page was too large for the model"

`AI_CONTEXT_TOO_LARGE`, and it is not retried — the same request is the same size.

The context is reduced structurally first, and the design system is never dropped. If
it still does not fit, the options are, in order of preference:

1. A model with a larger context. Check what the site thinks it is running:
   `Ai_Capabilities::context_budget_bytes( 'openai', $model )`.
2. Analyze fewer sections.
3. Let it chunk: `Ai_Context_Budget::chunk()` splits by section, every chunk carrying
   the global design system. The per-chunk payloads exist; no orchestrator drives
   them yet, so this is not automatic.

An undeclared model resolves to a **16,000-token floor** rather than a guess, which is
usually the cause: a typo in the model name quietly reduces your context to a
sixteenth of what you had. `Ai_Capabilities::resolve( $provider, $model )['known']`
tells you.

### "My AI calls are more numerous than I expected"

```php
print_r( \ReplicaForge\Ai_Usage_Audit::summary() );
print_r( \ReplicaForge\Ai_Usage_Audit::recent( 20 ) );
```

`by_model` and `by_operation` show where the calls went. A large page is chunked into
several requests plus possible repairs, and `cached` calls did not cost a provider
request.

Estimate before you run, without sending anything:

```php
print_r( \ReplicaForge\Ai_Cost_Estimator::estimate( $context, 'openai', 'gpt-4o', 'analysis' ) );
```

It reports the band, the planned calls, the possible repairs, and a cost **range**.
The cost is an approximation for planning, not a billing amount — ReplicaForge does
not charge for AI usage; the provider does, on their terms.

### "A refused admission took my usage"

It should not, and the Phase 11 suite asserts that it does not. A duplicate or a
job-limit refusal **releases** its reservation. If you are seeing a charge for a job
that never ran, that is a bug worth reporting with the job id — the audit log and
`Ai_Usage_Audit` will show the sequence.
