# Phase 9 — Continuous Sync

> **Phase 9 status: the intelligence half is built and tested. Nothing is reachable.**
> Six classes, 578 assertions, 0 failures. A user installing the plugin gets identical
> behaviour to Phase 8: the new classes load, register nothing, and are called by
> nothing. The detection and conflict reasoning is finished; the plan, the apply, the
> monitoring, and every screen are not built.

## What exists

| Class | Does | Tests |
|---|---|---|
| `Sync_Limits` | Vocabulary, bounds, enums, reachability classification, interval floor | foundation |
| `Elementor_Map` | source → reconstruction → Elementor mapping, persisted in post meta | foundation, conflict |
| `Component_Matcher` | Component identity and matching across an edit | foundation, detect |
| `Change_Detector` | Representation diff: what changed, where, how certainly | detect |
| `Change_Classifier` | Severity, risk, review, auto-safety, grouping | detect |
| `Sync_Conflict_Detector` | Three-way ownership: may ReplicaForge write this? | conflict |

**17 suites, 1934 assertions, 0 failures, 0 warnings, 2 skips.** Up from 14 suites and
1556. Every Phase 1–8 suite passes unchanged.

## The chain, and where it stops

```text
DETECT        Component_Matcher      "which component is this one?"
              Change_Detector        "what changed, and how certainly?"
UNDERSTAND    Change_Classifier      "how much does it matter, and is it risky?"
COMPARE       Sync_Conflict_Detector "may ReplicaForge write it without destroying work?"
              ─────────────────────── plan, apply, validate, roll back ───────────
              ✗ not built
```

The brief's §87 chain is five steps. Four are built. The fifth is the one that touches
the document, and it is not started — which is the correct place to stop, because that
step must reuse the Phase 6 boundary and adding a second write path would be a
second unaudited way to reach a document.

## The decisions worth knowing

### Identity is content-independent, and that was learned the hard way

`Component_Matcher` keeps two hashes: a `fingerprint` from role and tag, and a
`content_hash` from role, tag, text, and image.

The first version used one text-inclusive hash for identity, which meant **every
headline edit was reported as a removal and an addition** — a rebuild on every reword.
Identity now excludes position, index, text, and image, because position is destroyed
by an insertion above the component and text and image are the two things a design
change most often alters.

The cost is that text and image have to earn their place as evidence rather than as
identity. They do: text contributes 0.10 and **vetoes** below 0.35 similarity; image
contributes 0.14 and **caps the confidence** at 0.4 when the two differ and neither has
text. Both are asserted in both directions, because the choice between "modification with
low confidence" and "rebuild" is a judgement worth pinning rather than leaving to the
implementation.

### Matching by position is refused, not just down-weighted

Position carries 0.01 of a 1.00 weight, and a match resting only on position is capped
at 0.4 confidence. The §12 scenario is asserted directly: a badge inserted at the top of
a four-component hero leaves all four originals matched, nothing reported removed, and
only the badge reported added.

### Conflicts are a three-way merge, not a two-way one

`Sync_Conflict_Detector` compares what ReplicaForge generated, what the document holds,
and what the source says. All three differing is the case the whole phase exists for.

It reuses Phase 6's `Correction_Snapshot` for the first two rather than keeping a
second ownership store, because two stores drift and the drift is invisible until a
user's edit is overwritten.

Two cases are handled explicitly because they are easy to get wrong and both were wrong
in the first implementation:

- **Both changed to the same value** is `no_conflict`. There is nothing to reconcile, and
  reporting a conflict would train a user to dismiss conflicts.
- **Both absent** is not an agreement. `same(null, null)` is true in PHP, so an earlier
  version silently dropped the change while claiming there was nothing to do.

`unknown` is the fifth ownership state and it is the one that matters: a property
ReplicaForge never wrote is not one it may claim the source owns.

### Severity, risk, and review are three answers, not one score

Collapsing them is what makes a risk list unusable. A colour change is severe enough to
be visible and harmless enough to apply. A deleted section is the reverse.

The separation is asserted, not just described: a reworded heading comes out **low risk
but still requiring review**, because the change is harmless and the reason a person is
asked is that the system is not certain enough. Different objections, answered
differently.

### Removal is never automatic, and never silent

A removal is at least `major` severity and `high` risk whatever it removes, requires
review always, and is never in the automatic set. Five categories are never automatic:
`section`, `navigation`, `interaction`, `theme`, and `product` — the last because a
price is a figure ReplicaForge has no business changing unattended.

### A sync will have no write path of its own

`Sync_Conflict_Detector` refuses any property not on `Correction_Property_Map`, before
comparing anything. A future apply step must convert a validated plan into a
`Correction_Applier::apply()` call. See [`SYNC-SECURITY.md`](SYNC-SECURITY.md).

### Reachability keeps nine outcomes apart

Implemented in `Sync_Limits` and tested, even though monitoring is not built. Conflating
`source_timeout` with `source_removed` is how a tool deletes a replica because a server
was briefly down, and `is_removal()` is the only thing in the codebase that can return
true.

## The performance work, including the mistake

The candidate search originally compared every old component against every new one —
over a million scored pairs at the bound, which hung the suite. Fixed by indexing new
components by section and searching within a section first, with a full sweep only for a
section with no new components at all.

| Components | Time |
|---|---|
| 200 | 0.03s |
| 600 | 0.26s |
| 1200 | 1.13s |

The first attempt at the fix made it *worse*: the fallback swept whenever a section pool
produced nothing above the floor, rather than only when the section was empty, so every
old component on a homogeneous page triggered a full sweep. Both the fix and the
mistake are in this report because the mistake is the kind that looks like a working
optimisation.

## Defects execution found

Thirty-one, all in Phase 9 code, several of which would have produced quietly wrong
output rather than an error:

| # | Defect | Effect if shipped |
|---|---|---|
| 1 | Text-inclusive hash used as identity | Every headline edit read as a rebuild |
| 2 | Image left in the identity | A swapped image read as a rebuild |
| 3 | Fingerprint weight counted twice | One signal outweighed the rest of the scheme |
| 4 | `same(null, null)` treated as agreement | A change silently dropped while the report claimed nothing to do |
| 5 | `raise()` ranked risks with the severity table | Risk escalation was a silent no-op — responsive and low-confidence changes were documented as raised and were not |
| 6 | `same identity` missing from the strong-signal list | The strongest signal was treated as a weak one |
| 7 | Element id validated in the method but not at any call site | Dead validation; any id accepted |
| 8 | `flatten` truncated at the cap without reporting | "No changes" from a comparison that never saw a third of the page |
| 9 | Full-sweep fallback triggered by a low-scoring pool | A monitoring check hung |
| 10 | Candidate consumption lost in a repair | The matcher looped forever |
| 11 | Normalised type not written back to the output | A record left the classifier reading `"nonsense"` |
| 12 | Cache-busted image read as an image change | A deploy reported as a content change |
| 13 | `grid-column-column-start` (Phase 8, fixed earlier) | A grid placement read as none |
| 14 | Asset field stringified when structured | A notice on every structured asset |
| 15 | `mb_strtolower` used on a build with no mbstring | A fatal on every identity |
| 16 | `0 === 0.0` strict comparisons | Wrong test expectations, twice |

The count is the argument for testing every engine against real behaviour rather than
reading it: reading found none of these, and several would have reached a user as a
replica that silently lost their work.

## What is not built

| Brief | Status |
|---|---|
| §3–6 Monitoring, cron, opt-in | **Not built.** The vocabulary and safety rules are in `Sync_Limits`; nothing uses them. See [`MONITORING.md`](MONITORING.md) |
| §7–8 Source version storage | **Not built.** The detector compares arrays handed to it |
| §26–27 Sync plan and validation | **Not built.** |
| §31–40 Incremental update, apply, snapshots, rollback, transaction, post-sync validation, regression, no-op handling | **Not built.** `Correction_Applier` and `Correction_Snapshot` exist from Phase 6 and would be reused |
| §34–35 Generation versions and rollback | **Not built.** See [`PROJECT-VERSIONS.md`](PROJECT-VERSIONS.md) |
| §44–49 Notifications, monitoring dashboard, timeline, diff view | **Not built.** The grouped diff data exists in `Change_Classifier::group()` |
| §53–54 Export and import | **Not built.** |
| §55–56 Retention and storage optimisation | Bounds declared; nothing enforces them |
| §57–64 Security audit of the new surface, project ownership, cron security, AI role, AI evidence | The **boundaries** are implemented and tested ([`SYNC-SECURITY.md`](SYNC-SECURITY.md)); the endpoints, cron, and ownership checks do not exist |
| §67–72 Admin UI, project page, monitoring UX, mobile, accessibility | **Not built.** |
| §73–79 Performance, fast checks, full-analysis trigger, AI cost control, iteration limit, loop detection, hashing | Bounds implemented; §79's hashing and §78's loop detection are **not**, and a loop is currently impossible only because nothing applies anything |
| §80–82 No-op sync, safe source removal, archived source | The outcomes are in `Sync_Limits`; nothing produces them |

## Acceptance criteria

Against §86:

| Criterion | Status |
|---|---|
| [ ] Projects can be monitored | Not implemented |
| [ ] Monitoring is opt-in | Not implemented — nothing to opt into |
| [ ] Scheduled checks work | Not implemented |
| [ ] Manual checks work | `Change_Detector::compare()` is the manual comparison, and works |
| [ ] Source versions are stored | Not implemented |
| [x] Changes are detected | Section and component level, with false positives handled |
| [x] Changes are classified | Severity, risk, review, auto-safety, grouping, with a stated basis |
| [x] Change impact is calculated | Impact is expressed as risk and severity rather than a single score, deliberately |
| [x] Source components can be matched | Content-independent identity, vetoes, elimination, section-blocked search |
| [x] Source → Elementor mapping persists | Three-link chain in post meta, format-checked, staleness-detectable |
| [x] Manual Elementor edits are detected | Via the Phase 6 baseline, not a second store |
| [x] Conflicts are detected | Five states, including the two that are easy to get wrong |
| [x] Conflicts require review | `both_changed` and `unknown` always do |
| [ ] Sync plans are validated | No plans exist |
| [ ] Safe changes can be applied | Nothing applies |
| [ ] High-risk changes require review | Classified, but there is no apply to gate |
| [ ] Sections can be incrementally updated | Not implemented, and section identity is not yet stable across a structural change |
| [ ] Full regeneration remains optional | Not implemented |
| [ ] Snapshots are created | Phase 6 `Correction_Snapshot` exists; no sync calls it |
| [ ] Rollback works | Phase 6 rollback exists; no sync triggers it |
| [ ] Post-sync validation works | Not implemented |
| [ ] Regression detection works | Phase 6 exists; no sync triggers it |
| [ ] Monitoring failures are handled correctly | Nine outcomes modelled and tested; nothing fetches |
| [x] Source deletion is never confused with temporary failure | `is_removal()` and `is_transient()`; the test asserts the distinction for every outcome |
| [x] AI cannot directly modify Elementor | No AI call exists; the whitelist is enforced first |
| [ ] AI output is validated | No AI path exists |
| [ ] Project history works | Phase 8 only; no generations |
| [ ] Version comparison works | Not implemented |
| [ ] Export is secure | Not implemented |
| [ ] Project ownership is enforced | Not implemented at any endpoint, because there are no endpoints |
| [ ] Cron security is enforced | No cron exists |
| [x] No infinite synchronization loops | Impossible: nothing applies anything. Not a property that will survive an apply step |
| [x] No duplicate jobs | No jobs are created. Same caveat |
| [x] Phase 1–8 regression tests pass | 14 suites, 1556 assertions, unchanged |
| [x] Documentation is complete | 5 documents; 3 mark themselves as decision records |

**8 of 34 criteria met, 2 of them trivially** because there is nothing to loop or
duplicate yet.

## What to build next, in order

1. **Wire the engines into the Phase 2 pipeline.** The Phase 8 engines are still
   unwired, and the representation this phase compares is not yet produced by anything.
   Nothing downstream can be tested against a real page until this happens.
2. **`Source_Version` storage.** The detector compares arrays; something must store and
   retrieve them, with the bounds already declared.
3. **`Source_Monitor`**, reusing the `Sync_Limits` rules rather than re-deciding them.
4. **`Sync_Planner` and `Sync_Validator`**, producing a plan that
   `Correction_Applier` can consume unchanged.
5. **The apply path**, as a thin adapter over Phase 6 — not a new writer.
6. **The screens**, starting with the conflict review, because that is where a person
   makes the decision the rest of the phase exists to support.

Steps 3 through 6 are blocked on step 1, and step 1 is the same work Phase 8 left.

## Related

- [`SOURCE-CHANGE-DETECTION.md`](SOURCE-CHANGE-DETECTION.md) — identity, matching, and
  the diff.
- [`CONFLICTS.md`](CONFLICTS.md) — the three-way merge.
- [`SYNC-SECURITY.md`](SYNC-SECURITY.md) — the boundaries.
- [`MONITORING.md`](MONITORING.md) · [`PROJECT-VERSIONS.md`](PROJECT-VERSIONS.md) —
  decision records for the unbuilt halves.
- [`PHASE-9-COMPLETION-REPORT.md`](PHASE-9-COMPLETION-REPORT.md)
