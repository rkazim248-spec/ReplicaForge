# Conflicts

> **Phase 9 status: IMPLEMENTED AND TESTED.**
> `Sync_Conflict_Detector` is complete and covered by 89 assertions in
> `tests/phase9-conflict-test.php`, including the exact scenario the brief calls
> critical. What is not built is the UI that presents a conflict and the apply path that
> would resolve one.

## Why this is the important part

Conflict detection is the reason synchronisation is safe enough to exist. The situation
it exists for:

```text
ReplicaForge generated:   font-size = 48px
The user changed it to:   font-size = 56px
The source now says:      font-size = 52px
```

A naive synchronisation writes 52px and the user loses their 56px without ever being
told. A worse one writes 48px. Both are silent, and the first is why people do not
trust automated tools with their own pages.

So every proposed write is resolved by comparing **three** values rather than two: what
ReplicaForge generated, what is in the document now, and what the source now says. That
is a three-way merge, and the interesting case is where all three differ.

## It reuses the Phase 6 record rather than inventing a second one

This is not new logic. Phase 6 already records what ReplicaForge wrote in
`Correction_Snapshot`, and already answers "did a human change this?". Phase 9 composes
those two answers with the source value, which is the only genuinely new step.

Reusing them means **one** record of what ReplicaForge wrote, not two that can
disagree. A second ownership store would drift from the first, and the drift would be
invisible until a user's edit was overwritten.

| Existing Phase 6 method | Used for |
|---|---|
| `baseline_value()` | What ReplicaForge generated |
| `current_value()` | What the document holds now |
| `manual_change()` | Whether a human changed it |
| `record_written()` | Advancing the baseline after a sync write, without absorbing a manual edit |

## The four ownership states, and a fifth

§21 names four. The fifth is the one that matters most:

| Ownership | Meaning |
|---|---|
| `source_controlled` | ReplicaForge generated it and the user has not touched it |
| `user_controlled` | The user changed it; the source is not applied |
| `mixed` | Both changed |
| `unknown` | ReplicaForge never wrote this property |

`unknown` is the important one. A property ReplicaForge has never written is not one it
may claim the source owns. It has no standing to say the user's value is wrong, and an
unknown is never treated as permission.

## The five conflict states

| State | Source | Replica | Writable | Review |
|---|---|---|---|---|
| `no_conflict` | unchanged | unchanged | no | no |
| `no_conflict` | changed | unchanged, **and the source now equals the current value** | no | no |
| `source_only` | changed | unchanged | **yes** | no |
| `user_only` | unchanged | changed | no | no |
| `both_changed` | changed | changed, to a **different** value | no | **yes** |
| `unknown` | — | no baseline | no | **yes** |

### The two cases that are easy to get wrong

**Both changed, to the same value.** If the user and the source independently arrived at
56px, there is nothing to reconcile: applying the source would change nothing, and
reporting a conflict would train a user to dismiss conflicts. The state is `no_conflict`
with the reason saying so. This is asserted separately from every other state because
it is the case most likely to be collapsed into the wrong one.

**Both absent.** `same(null, null)` is true in PHP, so an earlier version reported a
source that declared no value and a replica that held no value as *already agreeing* —
and the change was silently dropped while the report claimed there was nothing to do.
The agreement shortcut now requires a value to agree on. Two absences are reported as
"neither side holds a value, so there is nothing to write", which is the true statement,
and the source is still recorded as having changed relative to its recorded baseline.

## The whitelist is checked first

Before anything is compared, the property is checked against
`Correction_Property_Map` — the same whitelist every correction goes through. A property
outside it is `unknown` with the reason naming the whitelist, so the failure is legible
rather than a mystery.

This is §61's requirement: the backend decides what may be written, never the caller. A
browser cannot say "set this Elementor property" because there is no path from a
submitted value to a write that does not pass through this check.

## The gate, and why a conflict does not block the safe work

`gate()` marks a plan as requiring review when anything is `both_changed` or `unknown`,
and returns the blocked items.

It does **not** refuse the whole plan. A person may well want the ten safe changes and
to decide separately about the one conflict, and a tool that refuses everything because
one item is contentious gets used less. The blocked items are marked, and the rest are
offered.

## The review screen's vocabulary

`summary()` buckets changes as **safe / review / conflict / blocked** rather than in
technical terms, which is §70's requirement answered by the data:

- `safe` — can be applied with no decision
- `review` — a person can decide (an unknown whose element is missing, for instance)
- `conflict` — both sides changed
- `blocked` — no decision a person makes can make it writable (a property off the
  whitelist)

The distinction in the last two is real: a missing element is something a user can
resolve by adding the element or skipping the change; a property outside the whitelist
is not something any decision can reach.

`blocking_state()` reports the one state most likely to need action first, with
`both_changed` outranking `unknown`.

## What is not built

- **The conflict UI.** No screen presents a conflict. The data is there — source value,
  original replica value, current replica value, state, reason — and nothing renders it.
- **Resolution.** A person cannot yet choose "keep replica" or "use source", because
  there is no apply path to route the choice into.
- **Persistence of a resolution.** A rejection is not remembered, so §58's "do not
  repeatedly suggest the same correction" is not implemented.

## Related

- [`SOURCE-CHANGE-DETECTION.md`](SOURCE-CHANGE-DETECTION.md) — where the source value
  comes from.
- [`SYNC-SECURITY.md`](SYNC-SECURITY.md) — the boundaries this sits behind.
- [`../CORRECTIONS.md`](../CORRECTIONS.md) — the Phase 6 machinery being reused.
