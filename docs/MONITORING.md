# Monitoring

> **Phase 9 status: NOT IMPLEMENTED.**
> There is no `Source_Monitor`, no cron event, no monitoring record, and no way to
> enable monitoring. This is a decision record. **The safety rules it depends on are
> already implemented** in `Sync_Limits`, and that is the part that must not be
> renegotiated later — see the end of this document.

## What exists today

`Sync_Limits` carries the vocabulary and the arithmetic that monitoring would be built
on, and every rule in it is tested. Nothing calls it, because there is nothing to call.

| Implemented | Constant or method | Test |
|---|---|---|
| Five frequencies | `FREQUENCIES` — `manual`, `daily`, `weekly`, `biweekly`, `monthly` | foundation §1 |
| A minimum interval floor | `MIN_INTERVAL_SECONDS` (6 hours), applied inside `interval_for()` | foundation §2 |
| Removal confirmation count | `REMOVAL_CONFIRMATIONS` (3) | foundation §3 |
| Nine reachability outcomes | `REACHABILITY` | foundation §3 |
| Removal vs. transient | `is_removal()`, `is_transient()` | foundation §3 |
| A redirect is neither | `source_moved` is not a removal and not transient | foundation §3 |
| Per-pass and per-monitor bounds | `MONITORS_PER_PASS`, `MAX_MONITORS`, `LOCK_TTL` | — |

`manual` is a real frequency rather than an absence, because a project can be monitored
for changes without ever being checked automatically, and "no frequency" would be
indistinguishable from "never set up".

## The floor is applied inside the accessor, not at the call sites

```php
public static function interval_for( $frequency ) {
    // ...
    return max( self::MIN_INTERVAL_SECONDS, $interval );
}
```

This is deliberate. A floor enforced by each call site is a floor that one call site
forgets, and a monitor left on `daily` on a site with several projects is a burst of
requests to the same host — which is a way to be a bad citizen of someone else's
server. Applying it in one place means there is no way to schedule around it.

## Removal is not failure, and the distinction is the whole point

`REACHABILITY` names nine outcomes and they are kept apart on purpose:

| Outcome | Meaning | Removal? | Transient? |
|---|---|---|---|
| `reachable` | The check succeeded | no | no |
| `source_unreachable` | Could not connect | no | **yes** |
| `source_timeout` | Connected, no answer in time | no | **yes** |
| `source_blocked` | Refused, or blocked by SSRF policy | no | **yes** |
| `source_http_error` | 5xx | no | **yes** |
| `source_rate_limited` | 429 or equivalent | no | **yes** |
| `source_removed` | Confirmed gone | **yes** | no |
| `source_moved` | Redirect followed to a new address | no | no |
| `invalid_url` | The recorded URL is not usable | no | no |

Conflating `source_timeout` with `source_removed` is how a monitoring tool deletes a
replica because a source server was briefly down, and it is the single worst failure
this feature has. §41 asks for the distinction and `is_removal()` is the only thing in
the codebase that can return true, so the distinction cannot be lost downstream.

`REMOVAL_CONFIRMATIONS = 3` states the other half: a source is only called removed after
**three separate checks agree**, because a single 404 is a mistyped URL, a maintenance
page, or a temporary routing fault far more often than it is a deletion.

`source_moved` is neither a removal nor a failure. The page is still there at a new
address, so re-checking will not change where it is and there is nothing to delete — it
is an address change to record.

## The model that would be stored

From §6, with the fields the code above actually needs:

```json
{
  "monitor_id":    "monitor_123",
  "project_id":    "project_123",
  "source_url":    "https://example.com",
  "frequency":     "weekly",
  "status":        "active",
  "last_checked_at": "",
  "last_changed_at": "",
  "next_check_at":  "",
  "last_error":     null,
  "consecutive_failures": 0,
  "removal_observations": 0
}
```

The last two are additions to §6 and they are not optional. Without a consecutive
failure count a transient outage trips the failure threshold at the wrong moment, and
without a removal-observation count a single 404 looks like a deletion.

Statuses are the brief's four: `active`, `paused`, `failed`, `disabled`.

## Opt-in is a product decision, not an implementation detail

Monitoring must never be enabled automatically, and the reason is not politeness.
A user who did not ask to be re-analysed has not consented to their replica changing,
and a feature that quietly begins acting on their page is one they will disable
permanently rather than configure.

The copy the brief supplies is the right copy and it should be kept verbatim:

> ReplicaForge periodically checks the public source page for changes. It does not
> access private or authenticated areas.

That second sentence is not reassurance, it is a statement of what the SSRF policy
already enforces. `Security::validate_url()` refuses private and link-local addresses,
and a monitoring check goes through the same path as a manual analysis. Saying so is
accurate.

## Scheduling

WordPress cron, named, with the following properties — all required by §5 and none built:

- **No duplicate events.** `wp_next_scheduled()` is checked before scheduling, and the
  event carries the monitor id as its only argument.
- **No overlapping jobs.** A transient lock per monitor, `LOCK_TTL` 300 seconds. Longer
  than the fetch timeout so a slow check is not declared dead while it is still running,
  and short enough that a crashed worker's lock expires without an operator.
- **Bounded per pass.** `MONITORS_PER_PASS = 5`. A pass that tries to do everything is a
  pass that times out, and a timed-out pass holds locks that stop the next one.
- **No external dependency.** If WP-Cron is unreliable, that is reported as a
  limitation. ReplicaForge does not introduce a server to make its own scheduling work.
- **A failure threshold.** `MAX_CONSECUTIVE_FAILURES = 3`, after which the status is
  `failed` rather than `active`, so a monitor does not keep retrying a source that is
  genuinely gone and a monitor does not give up on one that is briefly down.

## What is deliberately not built yet

| Brief | Why it is not started |
|---|---|
| `Source_Monitor` | Needs the reachability fetch path, which needs a decision on how a check reports an HTTP status to the stored record |
| Cron registration | A cron event that fires against a record format nothing writes is worse than no event |
| Staged fast checks (§74) | `ETag` and `Last-Modified` need the HTTP client to surface them, and the source version store to compare against |
| Notifications (§44–46) | Needs the record to notify about |
| Monitoring dashboard (§47) | Needs the record |

## Open questions carried forward

- **How is a 404 that is actually a soft-404 page handled?** Plenty of sites return 200
  for a removed product. A removal detector that trusts the status code will call a page
  gone while it is serving a "not found" page. Comparing the *representation* is the
  only reliable signal, and that is expensive enough to want the fast checks first.
- **What happens to a monitor when its project is deleted?** The obvious answer is that
  it is disabled, not deleted, so the record of having monitored something survives.
- **A source that redirects is a `source_moved` outcome — does the monitor follow and
  update the stored URL, or record the move and keep checking the old one?** Following
  silently would make ReplicaForge depend on a host it was not configured for.

## Related

- [`SOURCE-CHANGE-DETECTION.md`](SOURCE-CHANGE-DETECTION.md) — the detection half that
  is built.
- [`SYNC-SECURITY.md`](SYNC-SECURITY.md) — the cron and ownership rules.
- [`../JOBS.md`](../JOBS.md) — the existing job system monitoring would reuse.
