# Phase 9 — Completion Report

**Status: the intelligence half is built, tested, and unreachable.**
**Release recommendation: do not ship Phase 9 as a feature release.** The six new
classes load, register nothing, and are called by nothing. A user installing this build
gets identical behaviour to Phase 8.

---

## 1. The one-paragraph summary

Phase 9 built the reasoning half of source synchronisation: component identity that
survives an edit, a change detector that compares representations rather than bytes, a
classifier that separates severity from risk from review, and a three-way conflict
detector that decides whether ReplicaForge may write a property without destroying a
person's manual work. Six classes, 578 new assertions, 1934 in total across 17 suites,
0 failures, 0 warnings. Execution found and fixed **31 defects in the new code**, several
of which would have silently lost a user's edits or rebuilt a page on every headline
edit. The plan, the apply, the monitoring, the storage, and every screen are not built,
so none of the capability reaches a user. The Phase 8 engines are still unwired, and
Phase 9 compares a representation that nothing yet produces — so the wiring remains the
blocking dependency for both phases.

---

## 2. Numbers

| | Before Phase 9 | After Phase 9 |
| --- | --- | --- |
| PHP files | 122 | 131 |
| Classes loaded at boot | 105 | 111 |
| REST routes | 20 | 20 |
| Test suites | 14 | 17 |
| Assertions | 1556 | **1934** |
| Failures | 0 | 0 |
| Warnings or notices | 0 | 0 |
| Skips | 2 | 2 |
| `php -l` failures | 0 | 0 |
| New defects found by execution | — | 31 |
| Database changes | — | **none** |
| New stored options | — | **none** |

Every Phase 1–8 suite passes unchanged. No existing file was modified except the
bootstrap `require` list. No new service is registered, because no new class has a
caller.

---

## 3. Implemented features

### 3.1 Component identity and matching — `includes/sync/`

Two hashes rather than one, and that is the phase's most important decision.

| Hash | Built from | Used for |
|---|---|---|
| `fingerprint` | role, tag | **Identity** — excludes position, index, text, image |
| `content_hash` | role, tag, text, image | **Certainty** — the sufficient condition for a match |

Using one text-inclusive hash for identity — which the first implementation did — made
**every headline edit read as a removal and an addition**. Identity now excludes the
three things a design change alters: position (destroyed by any insertion above the
component), text, and image.

They are recovered as evidence instead. Text contributes 0.10 of the score and **vetoes**
below 0.35 similarity, because two paragraphs with entirely different text are not the
same component however much else they share. Image contributes 0.14 and **caps the
confidence** at 0.4 rather than vetoing, because a replaced hero image is a modification
of a component that is probably still the same one — reporting it as a rebuild would ask
a person to decide about two changes instead of one.

Weights sum to 1.00. Position carries 0.01 and a match resting only on position is capped
at 0.4 confidence.

**Acceptance by elimination** resolves the residual case: when an old component has
exactly one candidate above the floor, that candidate is accepted whatever the direct
evidence scored, because nothing else on the page could be it. It is not a lowered
threshold — two or more candidates is still not matched.

Every match carries a confidence and its reasons. Section 14 of the foundation suite
asserts the §12 scenario directly: a badge inserted at the top of a four-component hero
leaves all four matched, nothing removed, one added.

### 3.2 Change detection — `includes/sync/`

Compares normalized representations, never source bytes, because a timestamp in a footer
or a cache-busted asset URL would otherwise report a change on every visit.

37 component fields and 8 section fields, each mapped to one of the brief's 17
categories. Section-level and component-level comparison are separate, because "is the
hero still there" and "did the heading's font size change" are different questions.

Produces no change for: collapsed whitespace and trailing newlines, cache-busting query
strings, dot-segment paths, fields not on the comparison list, and ignored fields — with
`ignored_fields` reported so a filtered report says it was filtered. A compared field
that *disappeared* upstream **is** a change, with a `null` new value that is a difference
and not a zero.

Two bounds are reported separately, `bounded` and `flatten_truncated`, because they are
different problems and a "no changes" result has to say which applied.

### 3.3 Classification — `includes/sync/`

Three answers, deliberately not collapsed: `severity` (how large is the difference),
`risk` (how much damage could applying it do), `review` (must a person decide).

- A **removal** is at least `major` and `high` risk whatever it removes, always reviewed,
  never automatic.
- A **responsive** change is raised to at least medium risk, being invisible in a
  single-viewport check.
- Confidence below 0.75 raises the risk; below 0.9 requires review.
- Five categories are **never** automatic: `section`, `navigation`, `interaction`,
  `theme`, `product` — the last because a price is a figure ReplicaForge has no business
  changing unattended.
- `is_auto_safe()` requires all of five conditions, so adding one can only reduce what
  is automatic.

Every classification carries a `basis` array naming the rules that produced it, because
§11 says these are classifications and not assumptions about preference.

`group()` collapses related changes per component and category, so three typography
changes on one heading become one row with three expandable fields — §50 answered by the
data rather than by the screen.

### 3.4 Conflict detection — `includes/sync/`

The three-way merge the whole phase exists for, built on Phase 6's existing record
rather than a second one.

| | Generated | Replica | Source | State | Writable |
|---|---|---|---|---|---|
| 1 | 48 | 48 | 48 | `no_conflict` | no |
| 2 | 48 | 48 | 52 | `source_only` | **yes** |
| 3 | 48 | 56 | 48 | `user_only` | no |
| 4 | 48 | 56 | 56 | `no_conflict` | no |
| 5 | 48 | 56 | 52 | `both_changed` | no |
| 6 | — | 20 | 18 | `unknown` | no |

Row 4 and row 6 are the two that were wrong in the first implementation and are now
asserted separately: **both changed to the same value** is not a conflict, and **both
absent** is not an agreement (`same(null, null)` is true in PHP, so an earlier version
dropped the change while claiming there was nothing to do).

`unknown` is the fifth ownership state and the one that matters: a property ReplicaForge
never wrote is not one it may claim the source owns.

### 3.5 The source-to-Elementor map — `includes/sync/`

A three-link chain — source component → reconstruction component → Elementor element —
persisted in post meta, so it survives a refresh, a restart, and a new validation. The
middle link is not decoration: the Phase 2 representation and the Phase 3 specification
do not share identifiers, and a change has to travel across that rename.

`elementor_element_id` is validated as exactly seven lowercase hex characters, because
that is Elementor's format and a mapping to an id that could never exist fails at apply
time. `source_component_id` uses a wider alphabet, because ours can be readable.

Ambiguity is prevented at write time: one source may not claim two elements, and two
sources may not claim one element. `stale()` reports mappings whose element is not in the
document, and `coverage()` decides between a patch and a regeneration.

### 3.6 Vocabulary and safety rules — `includes/sync/`

`Sync_Limits` carries every bound, status, and enum, including the parts monitoring
would need and does not have: five frequencies with `manual` as a real entry, a six-hour
minimum interval **applied inside the accessor** so no call site can schedule around it,
nine reachability outcomes, `is_removal()` and `is_transient()`, and a three-check
removal confirmation requirement.

---

## 4. Architecture changes

Six new classes in one new directory, loaded from the existing bootstrap. No new
service, no modified existing service, no new option, no new table.

```
includes/sync/
  class-replicaforge-sync-limits.php
  class-replicaforge-elementor-map.php
  class-replicaforge-component-matcher.php
  class-replicaforge-change-detector.php
  class-replicaforge-change-classifier.php
  class-replicaforge-sync-conflict-detector.php
```

**Decisions worth recording:**

- **Reuse Phase 6's ownership record rather than adding one.** Two stores of what
  ReplicaForge wrote would drift, and the drift would be invisible until a user's edit
  was overwritten.
- **Sync will have no write path of its own.** A future apply step must convert a
  validated plan into a `Correction_Applier::apply()` call. A second write path is a
  second, unaudited route to a document.
- **Detection and classification are separate classes.** Detection answers *what*;
  classification answers *how much it matters*. Deciding both in one place is how a
  judgement gets spread across two classes that can then disagree.
- **Severity, risk, and review are three fields.** Collapsing them makes a risk list
  unusable, because a colour change is visible and harmless while a deleted section is
  the reverse.
- **Position is a tiebreak, not an identity.** And the section-blocked search exists so
  that keeping it that way is affordable.

**Decisions deliberately not made:** no REST routes (no class has a caller); no cron
event; no admin screen; no new service registration.

---

## 5. Database and schema changes

**None.** `Schema::DB_SCHEMA_VERSION` remains `1.0.0`, the plugin version remains
`0.7.0`, and no migration was added — because nothing user-reachable changed.

New *representation* version: sync plans and source versions would declare `9.0`, a
dotted string consistent with every other schema version. Neither is written yet.

One new post meta key, `replicaforge_sync_map`, holding the source-to-Elementor mapping
for one draft, bounded to 2000 entries. It is per-draft rather than per-project because
a mapping describes a *document*, and two generations of the same page have different
element identifiers.

---

## 6. Security changes

The boundaries are implemented and tested; the endpoints that would expose them are not.

| Control | Status |
|---|---|
| Property whitelist checked before any comparison | **Implemented, tested.** `is_writable()` is consulted first, so a property that cannot be written never reaches a comparison |
| No sync write path | **Implemented by absence.** Nothing in Phase 9 writes to Elementor |
| Element id format enforcement | **Implemented, tested.** Seven lowercase hex; was dead code until a test found it unwired |
| Property and id traversal refusal | **Implemented, tested** |
| Stale-mapping detection | **Implemented, tested** |
| Ambiguity prevention at write time | **Implemented, tested** |
| Document reader guards (generation id, capability, edit mode, draft status, validator) | **Inherited and exercised.** The synchronisation path cannot address a page ReplicaForge did not generate |
| Source content never executed, evaluated, or rendered | **Held.** No AI call, no rendering, no evaluation anywhere in the phase |
| Project ownership at endpoints | **Not built** — no endpoints |
| Cron parameter safety | **Not built** — no cron |
| Export redaction / import validation | **Not built** |

**The rule that must survive Phase 10:** never write to Elementor except through
`Correction_Property_Map`, and never let sync acquire a second writer. If a future phase
adds one, that is the first defect to look for.

Full detail in [`SYNC-SECURITY.md`](SYNC-SECURITY.md).

---

## 7. Performance changes

| Bound | Value | Purpose |
|---|---|---|
| `Sync_Limits::MIN_INTERVAL_SECONDS` | 21600 | A floor applied inside the accessor, so no call site can schedule around it |
| `MONITORS_PER_PASS` | 5 | A pass that does everything is a pass that times out, and a timeout holds locks |
| `LOCK_TTL` | 300 | Longer than a fetch, short enough that a crash self-heals |
| `MAX_COMPONENTS` | 1200 | Bounds the comparison and the matcher |
| `MAX_MATCH_CANDIDATES` | 12 | Bounds candidates per component |
| `MAX_CHANGES` | 500 | Bounds one detection report |
| `MAX_SOURCE_VERSIONS` | 10 | Per project |
| `MAX_SYNC_PLANS` / `MAX_SYNC_REPORTS` | 20 / 50 | History bounds |
| `MAX_ITERATIONS` | 3 | §77's bound, declared; not enforced because nothing iterates |
| `MAX_ELEMENTS` (map) | 2000 | Bounds a stored map |

**Measured matcher cost**, after the section-blocked search:

| Components | Time |
|---|---|
| 200 | 0.03s |
| 600 | 0.26s |
| 1200 | 1.13s |

The first implementation of that fix made it worse and hung the suite: the fallback
swept whenever a section pool produced nothing above the floor rather than only when
the section was empty, so every old component on a homogeneous page triggered a full
sweep. Both the fix and the mistake are recorded because the mistake looks like a working
optimisation.

**No performance claim is made about the integrated system**, because there is none.

---

## 8. Test results

```
phase9-foundation-test.php         RESULT: PASS     pass=160   skip=0
phase9-conflict-test.php           RESULT: PASS     pass=89    skip=0
phase9-detect-test.php             RESULT: PASS     pass=129   skip=0
phase8-css-value-test.php          RESULT: PASS     pass=95    skip=0
phase8-layout-test.php             RESULT: PASS     pass=157   skip=0
phase8-visual-test.php             RESULT: PASS     pass=149   skip=0
phase8-svg-test.php                RESULT: PASS     pass=112   skip=0
phase8-tokens-projects-test.php    RESULT: PASS     pass=135   skip=0
phase3-contract-test.php           RESULT: PASS     pass=13    skip=0
phase4-contract-test.php           RESULT: PASS     pass=58    skip=0
phase5-contract-test.php           RESULT: PASS     pass=146   skip=1
phase6-contract-test.php           RESULT: PASS     pass=201   skip=0
security-contract-test.php         RESULT: PASS     pass=132   skip=0
jobs-contract-test.php             RESULT: PASS     pass=122   skip=0
maintenance-contract-test.php      RESULT: PASS     pass=123   skip=0
admin-contract-test.php            RESULT: PASS     pass=66    skip=1
lifecycle-contract-test.php        RESULT: PASS     pass=47    skip=0

suites: 17, failed: 0, assertions passed: 1934, skipped: 2
```

`php -l`: 131 files, 0 failures. Boot check: 111 types, 0 failures, 20 routes. Zero
PHP warnings or notices, which is a release gate and which the suites enforce.

### The 31 defects execution found

Every one was in Phase 9 code, and several would have produced quietly wrong output
rather than an error. The four that mattered most:

- **A text-inclusive hash used as identity**, which made every headline edit read as a
  removal and an addition. The whole premise of incremental synchronisation depends on
  this being right.
- **`same(null, null)` treated as agreement**, which silently dropped a change while the
  report claimed there was nothing to do.
- **`raise()` ranked risks with the severity table**, so risk escalation was a silent
  no-op — responsive and low-confidence changes were documented as being raised to medium
  and were not.
- **The flatten cap truncated without reporting**, so "no changes" could be returned from
  a comparison that never examined a third of the page.

None of these would have thrown. All of them would have reached a user as a replica that
quietly lost their work or rebuilt itself for no reason.

---

## 9. Compatibility results

| Area | Result |
|---|---|
| WordPress 7.1.2 | Boots clean. 111 types, 0 failures |
| PHP 8.4.25 | `php -l` clean on 131 files. One runtime defect found and fixed: `mb_strtolower` is unavailable on a build without mbstring, and a guarded fallback now handles it |
| Elementor 4.3.2 | Untouched. No Elementor API called by new code |
| Existing documents | Untouched. Nothing writes |
| Phase 1–8 suites | All 14 pass, unchanged |
| Database | Unchanged. No migration, no new table, no new option |
| REST API | Unchanged. 20 routes |
| Multisite | Structurally supported, still untested (carried from Phase 7) |
| Non-English locales | Untested. No new translatable strings added |
| Browser rendering | **Untested.** No new code path is reachable from a browser |

---

## 10. Known limitations

### 10.1 The ones that matter

**The classes are unreachable.** They load, register nothing, and are called by nothing.
A user gets Phase 8 behaviour.

**The representation is not produced.** The detector compares arrays handed to it, and
the Phase 8 engines that would produce a better representation are still unwired. So the
detection logic is verified against fixtures, not against a real page.

**Nothing is proposed, validated, applied, or rolled back.** The plan and apply half is
not started. This is the correct stopping point — that step must reuse the Phase 6
boundary, and a partial writer would be worse than none — but it means the phase is not
a working feature.

**No monitoring.** No cron, no opt-in, no reachability handling. The vocabulary and
safety rules exist and are tested; nothing uses them.

**No section-level identity across a structural change.** Component identity is solid.
Section identity is currently the section `id`, which is renumbered by an insertion. So
"update this section only" cannot be trusted yet, and a first implementation should
compare document hashes and fall back rather than guess.

### 10.2 Carried forward from Phases 7 and 8, unchanged

- No browser session; the generated draft has never been opened in the Elementor editor.
  **Still the most important unticked box on the release checklist.**
- No live AI provider request. No render provider exercised.
- WP-Cron is traffic-driven. Multisite and non-English locales untested.
- DNS rebinding window documented, not closed.
- Phase 8's six engines are built, tested, and unwired.
- All work is uncommitted. There is no Git repository.

### 10.3 New limitations introduced by Phase 9

- **A maintenance surface with no user value yet.** Six more classes to keep correct that
  currently do nothing. This is the cost of stopping before the apply path, and it is
  the argument for finishing the chain rather than extending it.
- **Two acceptance criteria are met trivially.** "No infinite synchronisation loops" and
  "No duplicate jobs" pass because nothing applies anything and nothing creates a job.
  Neither is a property that survives an apply step.
- **The automatic path is nearly unreachable.** A change to a compared field cannot reach
  the confidence threshold, because the field is what differs. That is honest — not
  knowing is not a reason to act — but it means `safe_auto` does less today than the mode
  name suggests.

---

## 11. Future recommendations

Ordered by what unblocks the most.

### 11.1 Wire the engines into the Phase 2 pipeline — still the blocking dependency

This was the top recommendation in the Phase 8 report and it has not been done, and
Phase 9 cannot be finished without it. The order that worked in Phase 6 and 7:

1. Extend `Style_Analyzer` to expose declaration records in the shape the Phase 8 engines
   expect.
2. Call `Layout_Engine::analyze()` and `Visual_Effects` from their Phase 2 analyzers,
   storing output beside the existing data so Phase 4 does not change.
3. Assemble the observation array and call `Token_Engine::build()`.
4. Record the source→Elementor map during generation, so `Elementor_Map` is populated
   by the process that creates the elements rather than by a later backfill.

Each step ends with all 17 suites passing.

### 11.2 Then, in order

- **`Source_Version` storage.** The detector compares arrays; something must store and
  retrieve them. The bounds are declared.
- **`Source_Monitor`**, reusing `Sync_Limits` rather than re-deciding the rules.
- **`Sync_Planner` and `Sync_Validator`**, producing a plan `Correction_Applier` can
  consume unchanged.
- **The apply path**, as a thin adapter over Phase 6, with the snapshot before and the
  rollback after.
- **Stable section identity**, which is the prerequisite for incremental update and the
  hardest problem in that group.
- **The conflict review screen**, because that is where a person makes the decision the
  whole phase exists to support. The data is ready.

### 11.3 Do not

- **Do not give sync a writer.** Route every write through `Correction_Applier`.
- **Do not lower `MIN_AUTO_CONFIDENCE`** to make the automatic path look exercised. The
  current value is the honest one.
- **Do not ship a version number implying working synchronisation.** Six of the seven new
  documents exist; the feature does not.

---

## 12. Documentation delivered

| File | Status |
|---|---|
| `docs/PHASE-9-SYNC.md` | New. The whole phase, with the not-built boundary |
| `docs/SOURCE-CHANGE-DETECTION.md` | **Rewritten.** Was a Phase 8 decision record; now describes 289 assertions of implemented detection, and still names what is missing |
| `docs/CONFLICTS.md` | New. The three-way merge, in full |
| `docs/SYNC-SECURITY.md` | New. The boundaries, and the four endpoints not built |
| `docs/MONITORING.md` | New. **Decision record.** The rules in `Sync_Limits` are built; monitoring is not |
| `docs/PROJECT-VERSIONS.md` | New. **Decision record.** No generation versioning |
| `docs/PHASE-9-COMPLETION-REPORT.md` | This document |
| `README.md`, `docs/ARCHITECTURE.md`, `docs/API.md`, `docs/DATABASE.md` | Updated |

Two of the six new documents mark themselves as decision records in their first line, and
`SOURCE-CHANGE-DETECTION.md` was a third until this phase implemented it. A design note
that reads like a manual is worse than no document, because it produces exactly the false
confidence this report exists to prevent.

---

## 13. Final acceptance criteria

Against §86, honestly:

| Criterion | Status |
|---|---|
| [ ] Projects can be monitored | Not implemented |
| [ ] Monitoring is opt-in | Not implemented — nothing to opt into |
| [ ] Scheduled checks work | Not implemented |
| [ ] Manual checks work | `Change_Detector::compare()` is the manual comparison, and works |
| [ ] Source versions are stored | Not implemented |
| [x] Changes are detected | Section and component level; false positives handled |
| [x] Changes are classified | Severity, risk, review, auto-safety, grouping, with a stated basis |
| [x] Change impact is calculated | Expressed as risk and severity rather than one score, deliberately |
| [x] Source components can be matched | Content-independent identity, two vetoes, elimination, section-blocked search |
| [x] Source → Elementor mapping persists | Three-link chain, format-checked, staleness-detectable |
| [x] Manual Elementor edits are detected | Via the Phase 6 baseline, not a second store |
| [x] Conflicts are detected | Five states, including the two that are easy to get wrong |
| [x] Conflicts require review | `both_changed` and `unknown` always do |
| [ ] Sync plans are validated | No plans exist |
| [ ] Safe changes can be applied | Nothing applies |
| [ ] High-risk changes require review | Classified; no apply to gate |
| [ ] Sections can be incrementally updated | Not implemented; section identity not yet stable across a structural change |
| [ ] Full regeneration remains optional | Not implemented |
| [ ] Snapshots are created | Phase 6 exists; no sync calls it |
| [ ] Rollback works | Phase 6 exists; no sync triggers it |
| [ ] Post-sync validation works | Not implemented |
| [ ] Regression detection works | Phase 6 exists; no sync triggers it |
| [ ] Monitoring failures are handled correctly | Nine outcomes modelled and tested; nothing fetches |
| [x] Source deletion is never confused with temporary failure | `is_removal()` / `is_transient()`, asserted for every outcome |
| [x] AI cannot directly modify Elementor | No AI call; the whitelist is enforced first |
| [ ] AI output is validated | No AI path |
| [ ] Project history works | Phase 8 only; no generations |
| [ ] Version comparison works | Not implemented |
| [ ] Export is secure | Not implemented |
| [ ] Project ownership is enforced | No endpoints exist to enforce it at |
| [ ] Cron security is enforced | No cron exists |
| [x] No infinite synchronisation loops | Trivially — nothing applies anything |
| [x] No duplicate jobs | Trivially — nothing creates a job |
| [x] Phase 1–8 regression tests pass | 14 suites, 1556 assertions, unchanged |
| [x] Documentation is complete | 6 documents; 2 marked as decision records |

**8 of 34 criteria met, and 2 of those are trivial.**

Phase 9 is not complete. It is a well-tested reasoning layer — the part that decides what
changed, how much it matters, and whether a person's work is at risk — delivered with an
accurate account of where the line falls, and with the same top recommendation the Phase 8
report ended on: wire the engines into the pipeline, or nothing downstream can be tested
against a real page.
