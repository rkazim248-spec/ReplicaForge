# Plans, entitlements, and usage

Phase 10 added a plan system, a licensing abstraction, and per-user usage
accounting. This document describes what is built, what is enforced, and — as
importantly — what a plan in this plugin can and cannot do.

There is **no payment processing in ReplicaForge.** There is no billing provider
implementation, no card form, no checkout, and no simulated subscription. What
exists is the machinery that a real product would need, wired to a plan the site
administrator configures locally, and enforcing that plan server-side. If no
licensing or billing provider is connected — the default — the plugin works
completely offline on the free plan.

---

## 1. The chain

```
BillingProvider        (optional, no implementation ships)
      ↓
LicenseProvider        (Local_License_Provider ships; others connect by filter)
      ↓
License_State          a fact: is this site entitled to a paid plan?
      ↓
Plan_Manager           which plan, and why
      ↓
Entitlement_Manager    may this user do this, right now
      ↓
Usage_Manager          has any of it left
```

Each link is local. Nothing in this chain reaches the network, and nothing in it
reads a value a browser supplied.

---

## 2. The shipped plans

Four plans ship, defined in `Plan_Storage::defaults()`. **The numbers are an
example, not a price list.** They are deliberately modest on the free plan so a
new installation cannot burn a quota in an afternoon, and deliberately present on
the paid plans so a paying installation is not artificially constrained.

| | Free | Starter | Pro | Agency |
|---|---|---|---|---|
| Analyses / month | 5 | 50 | Unlimited | Unlimited |
| AI analyses / month | 5 | 50 | Unlimited | Unlimited |
| Generations / month | 2 | 20 | 100 | Unlimited |
| Validations / month | 20 | 200 | Unlimited | Unlimited |
| Corrections / month | 5 | 50 | 500 | Unlimited |
| Sync operations / month | — | 20 | 200 | Unlimited |
| Monitored projects | — | 2 | 20 | Unlimited |
| Projects in history | 5 | 25 | 100 | Unlimited |
| Automatic correction | — | ✓ | ✓ | ✓ |
| Source monitoring | — | — | ✓ | ✓ |
| Source sync | — | — | ✓ | ✓ |
| Advanced reconstruction | — | — | ✓ | ✓ |
| Project import | — | ✓ | ✓ | ✓ |

A `—` in the limits table is a **zero**, not an absence. A limit of zero and a
withheld feature are different statements, and both are used deliberately.

### Changing the plans

Three ways, in increasing order of permanence:

```php
// 1. A filter, for an installation that sells different plans.
add_filter( 'replicaforge_plan_definitions', function ( array $plans ): array {
    $plans['studio'] = array(
        'name'     => 'Studio',
        'limits'   => array( 'generation_per_period' => 30 ),
        'features' => array( 'elementor_generation' => true ),
    );
    return $plans;
} );
Plan_Storage::flush_cache();   // required: a cache does not watch for filters.
```

2. **Settings** — the Plans screen writes an override to the
   `replicaforge_plan_definitions` option. Only the difference from the shipped
   set is stored, so a plan release does not overwrite a deliberate change.

3. **Export and import** — an administrator can export the plan configuration as
   JSON and import it on another site. See §8.

An override **replaces** a plan; it does not merge with it. A merge would keep
limits the new definition meant to remove, and an administrator who halves a quota
would silently keep the old higher value for every key they did not mention.

---

## 3. Vocabulary

`Plan_Limits` declares every name the system uses, and nothing else may invent
one. A limit of `generation_per_period` appears in the plan definition, the usage
meter, the REST argument validator, and the matrix screen — spelled from the same
declaration each time, so a rename cannot become a silent behaviour change.

**Operations** (metered): `analysis`, `ai_analysis`, `generation`, `validation`,
`correction`, `sync_operation`, `export`, `import`.

**Features** (granted or withheld): `basic_analysis`, `ai_understanding`,
`elementor_generation`, `visual_validation`, `automatic_correction`,
`monitoring`, `source_sync`, `advanced_reconstruction`, `project_export`,
`project_import`.

**Limits** (counts within a period): one `<operation>_per_period` per operation,
plus two entity limits — `monitored_projects` and `history_projects`.

Every operation names the feature that gates it. An operation with no feature is
refused, because it would be a limit nobody can reach and a feature nobody can
withhold.

### The two asymmetries, and why they differ

**An absent limit is unlimited. An absent feature is withheld.**

These are opposite on purpose and both fail in the safe direction. A plan that
does not mention a limit has not finished thinking about it, and reading that as
zero would break every incomplete plan. A plan that does not mention a feature
should not hand it out, because forgetting to grant something and accidentally
granting it are not the same mistake.

A negative limit that is not the declared `UNLIMITED` marker (`-1`) becomes
**zero**, not unlimited. Treating `-99` as unlimited would grant more than the
plan says; treating it as zero fails toward less access.

**Entity limits are counted, not metered.** Incrementing a counter when a
monitored project is added would need a matching decrement when it is removed, and
a missed decrement — a delete, an uninstall, a restore — leaves the user
permanently short of allowance with no way to recover. Counting the live set
cannot drift.

---

## 4. The gate

`Entitlement_Manager::check()` runs five checks in a fixed order. The order is a
security property, not a style choice:

1. **Authentication.** No user, no operation.
2. **Capability.** `replicaforge_use` or `replicaforge_generate`, per operation.
3. **Ownership.** Does the named project belong to them.
4. **Feature entitlement.** Does the plan grant the gating feature.
5. **Usage limit.** Is there room, counting what is already held.

Ownership comes before entitlement so a user cannot learn anything about a plan by
asking about somebody else's project. Entitlement comes before the limit so a user
whose plan does not include a feature is told the feature is unavailable, rather
than being sent to an upgrade page when no upgrade would help.

What this class deliberately does **not** check: project state, Elementor
availability, request validity. Those need services it does not own and are not
commercial concerns; the REST layer runs them after this returns an approval.

Every refusal is a structured array with a stable code, a user-facing message, an
HTTP status, and details. Nothing returns a bare boolean, because a boolean cannot
be turned into a correct error message.

### `begin()` and `settle()`

`check()` is for a screen deciding whether to offer a button. It is not for
deciding whether to run something, because its answer can go stale between the
check and the execution — which is exactly the concurrency problem.

```php
$begin = $entitlements->begin( 'generation', $user_id, array( 'project_id' => $project_id ) );
if ( empty( $begin['allowed'] ) ) {
    return new WP_Error( $begin['code'], $begin['message'], array( 'status' => $begin['status'] ) );
}

// ... run the generation, which may fail ...

if ( $succeeded ) {
    $entitlements->settle( $user_id, $begin['reservation'] );
} else {
    $entitlements->fail( $user_id, $begin['reservation'], 'elementor_missing' );
}
```

`begin()` reserves, `settle()` charges, `fail()` charges nothing. A caller that
calls `begin()` and forgets the second half still cannot over-charge a user,
because the reservation expires on its own (§6).

**`Feature_Gate` is not a second gate.** It is a presentation layer over
`Entitlement_Manager` with no logic of its own. A UI gate with its own rules is
the most common way an entitlement system starts lying: the screen decides a
feature is available, the endpoint decides it is not, and the user is shown a
button that produces an error.

---

## 5. Usage accounting

**A user is charged for work that succeeded, not for a button they pressed.**

```
Request → Validation → Authorization → Entitlement → Execution → Success → Commit
                                                                   ↓
                                                        (on failure: Release)
```

Three numbers, kept separate because collapsing them loses the thing a user is
actually asking:

| Field | Meaning |
|---|---|
| `used` | Charged. Work that completed. |
| `held` | In flight. Reserved but not settled. |
| `total` | `used + held`. This is the figure that fills the meter. |

Committing converts a hold into a charge, so `total` does **not** fall. Treating
a commit as "frees a slot" would let a user start an operation for every one they
finish, which is the limit not being a limit. Releasing is what frees a slot.

A release is still recorded in the usage tail, with `quantity => 0` and an outcome
of `released`. A user seeing "1 / 2 used" with no explanation of the second
attempt cannot tell a working meter from a broken one.

### Storage

Per user, per period, in user meta:

- `replicaforge_usage_{YYYY-MM}` — the committed counts
- `replicaforge_usage_reserved_{YYYY-MM}` — open reservations
- `replicaforge_usage_recent_{YYYY-MM}` — a bounded tail (500 entries)

A period is a calendar month in **UTC**, so a user's quota resets at a moment they
can predict and two sites in different timezones reset at the same instant. A
rolling thirty-day window would be easier to implement and impossible to explain
on a screen.

**No database table is used.** The plugin has none, and four integers per user per
month does not justify a migration and a query. A period that falls out of use
leaves a small orphaned key, which the existing retention pass already prunes.

---

## 6. Concurrency

`Usage_Manager::with_lock()` uses `add_option()` as its mutual-exclusion
primitive, because that is a single `INSERT` against a unique column: exactly one
concurrent process can create the row. `update_option()` would not do — it is a
read followed by a write, and two processes doing that at once means one write is
lost.

Every read-modify-write of a counter happens inside that lock, so ten simultaneous
requests against a limit of two cannot start ten generations. The second and third
reservations see the first.

- A lock is held for at most **30 seconds**. A process killed mid-write does not
  block the user until tomorrow.
- A lock that cannot be taken is a **409**, not a 402. The user has not run out of
  allowance; they asked at the same moment as somebody else. Reporting that as a
  limit would send them to an upgrade page for no reason.
- A reservation expires after **2 hours**. Longer than any generation
  (`GENERATION_TIME_BUDGET` 25s) or correction batch (`APPLY_TIME_BUDGET` 45s) with
  headroom, and short enough that a user who loses a job does not wait a day.
  Expiry is swept by the daily maintenance pass, so a crashed job does not cost
  the user the rest of the month.
- At most **20 open reservations** per user. A bound rather than a rate: a client
  that opens thousands of reservations without executing anything must not be able
  to lock a user out of their own quota.

**Known limitation:** takeover of an expired lock is a `get_option` /
`delete_option` / `add_option` sequence, so two processes arriving together can
both attempt the takeover and one loses. The consequence is a counter that may be
overdrawn by one, which is the safe direction. Making the takeover a single atomic
statement would need a table, which is not worth it for this.

---

## 7. Refusals

| Code | Status | Meaning |
|---|---|---|
| `authentication_required` | 401 | Not signed in. |
| `capability_missing` | 403 | The account may not run this operation. |
| `project_not_available` | 404 | Not yours, or does not exist — deliberately the same answer. |
| `feature_not_in_plan` | 402 | The plan does not include the feature. |
| `usage_limit_reached` | 402 | The allowance is spent. |
| `entity_limit_reached` | 402 | No room for another of those. |
| `usage_lock_held` | 409 | Someone else's operation is still finishing. |
| `unknown_operation` | 400 | Not a declared operation. Refused, not allowed. |

`402 Payment Required` is used for the two commercial refusals because it is the
status that tells a client an upgrade is what would change the answer.

The project refusal does not distinguish "not yours" from "does not exist".
Distinguishing them turns the endpoint into an oracle for enumerating project ids,
and project ids are short enough to enumerate.

The limit refusal text is assembled in `limit_notice()` rather than in a
template, so the REST error and the admin screen cannot word the same refusal
differently. It reports the plan, the used count, the limit, the reset time, and
`billing_configured`. **When no billing provider is connected it says so**, and it
links to the plan details page rather than to a purchase button that goes nowhere.

---

## 8. Export and import

An administrator can export the plan configuration to JSON and import it on
another site. The exported document carries only five keys per plan — `plan_id`,
`name`, `description`, `limits`, `features` — plus the trial settings and a
`schema` marker.

**Every imported value is rebuilt through `Plan_Definition`, which drops unknown
keys.** There is no code path from an imported value to execution: no `eval`, no
`include`, no `call_user_func` on imported data, no template rendering of it. An
entry with an unrecognised key loses that key; an entry with an unrecognised plan
id is rejected and reported by name.

An import reports both what it stored and what it rejected, and a partial import
succeeds — one malformed plan id does not fail the whole document.

---

## 9. Upgrades

Locked actions are **informative, not aggressive**. `Feature_Gate::lock_notice()`
returns structured text containing only numbers read from the plan definitions. It
contains no countdown, no "spots left", no "hurry", and no claim that a plan is
popular. The test suite asserts the absence of those strings, because the
temptation to add them is exactly the kind of thing that gets added later without
anyone noticing.

A locked action is styled differently but stays focusable and stays in the
accessibility tree, with `aria-disabled` and `aria-describedby` pointing at its
explanation. Functionality is never signalled by colour alone.

---

## 10. What is not built

- **No payment processing, and no path to any.** `BillingProvider` is an
  interface with no implementation. Its methods are all reads; there is no
  `charge()`, no `create_customer()`, no `cancel_immediately()`.
- **No admin screens.** The services, REST routes, gates, and refusal text exist
  and are tested. The visual screens that render them do not — see
  `PHASE-10-COMPLETION-REPORT.md` §6.
- **No enforcement on the Phase 1–9 endpoints.** `Entitlement_Manager` is not
  called from `Rest_Api`. Wiring it in is Phase 11 work, and doing it halfway
  would be worse than not doing it: a partially metered plugin is a plugin whose
  quota users cannot reason about.
- **No per-plan pricing.** A plan has no price, and a price would have nowhere to
  be charged.
