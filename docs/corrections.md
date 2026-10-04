# Phase 6 — Automatic Correction & Regeneration Engine

Phase 6 turns the differences Phase 5 measured into reviewed, reversible, real
Elementor property changes.

```
PHASE 5 Validation Result
        ↓
Difference Filter + Eligibility Check
        ↓
Correction Plan  (read-only, human review required)
        ↓
[Review Corrections]  →  [Apply Corrections]
        ↓
Snapshot → Apply batch → Validate → Save once
        ↓
Phase 5 re-validation
        ↓
Regression detection → keep, or restore the snapshot
        ↓
Correction Report + History
```

**The central principle:** ReplicaForge improves a page through small, explainable,
reversible Elementor changes. It does not delete and rebuild. Targeted correction of
one property is always preferred over regenerating a section, and full regeneration
does not exist in Phase 6 at all.

---

## 1. Correction levels

| Level | Name | Properties |
|---|---|---|
| 1 | `safe_deterministic` | colours, borders, radius, shadow, background |
| 2 | `structured_component` | font size, family, weight, line height, letter spacing, transform, text colour |
| 3 | `layout` | container max width, width, minimum height, padding, margin, gap, flex direction, alignment, justification, wrap |
| 4 | `responsive` | the same level 2 and 3 properties, written to a device-specific control |
| 5 | `ai_assisted_planning` | optional reordering of already-measured corrections |
| 6 | `section_regeneration` | declared, and never reachable from a validation result |

Level 6 is intentionally unreachable. A `validation` never produces a
section-regeneration correction, and there is no code path from a correction plan to
a full regeneration. Section *insertions* do exist, but only from the stored
reconstruction specification and only after explicit approval.

---

## 2. The property whitelist is the security boundary

Every writable path goes through `Correction_Property_Map`. A property that is not in
the table has no Elementor control and therefore cannot be written.

| Group | Properties | Control | Value shape | Per device |
|---|---|---|---|---|
| Typography | `font_size`, `letter_spacing` | `typography_font_size`, `typography_letter_spacing` | slider | yes |
| | `font_family` | `typography_font_family` | font stack | no |
| | `font_weight` | `typography_font_weight` | weight | no |
| | `line_height` | `typography_line_height` | em slider | no |
| | `text_transform` | `typography_text_transform` | closed enum | no |
| | `text_color` | `typography_color` | colour | yes |
| Colours | `background_color`, `border_color` | same names | colour | yes |
| Border | `border_width`, `border_radius` | same names | dimensions | yes |
| Shadow | `box_shadow` | `box_shadow` | shadow | no |
| Spacing | `section_gap` | `flex_gap` | gaps | yes |
| | `padding_top`, `padding_bottom` | `padding` (one side) | dimensions | yes |
| | `margin_top`, `margin_bottom` | `margin` (one side) | dimensions | yes |
| Dimensions | `max_width` | `boxed_width` + `content_width` | slider | no |
| | `width` | `width` | percent slider | yes |
| | `min_height` | `min_height` | slider | yes |
| Layout | `flex_direction` | `flex_direction` | closed enum | yes |
| | `align_items`, `justify_content`, `flex_wrap` | `flex_*` | closed enum | yes |

Three rules make this airtight:

1. **The browser never supplies a control name.** The request carries a plan
   identifier and a list of correction identifiers. The writer resolves the control
   from the whitelist, and `Correction_Validator` refuses a correction whose
   resolved control is on the never-written list (`html`, `editor`, `title`, `link`,
   `html_tag`, `background_image`, `custom_css`, `_transform_*`).
2. **Every value is re-derived.** A value in the request is never stored. It is passed
   through `Elementor_Values` — the same policy Phase 4 used to create the value —
   and a value that cannot be coerced is refused, not approximated.
3. **A device correction writes a device control.** `font_size` at tablet resolves to
   `typography_font_size_tablet`, so the desktop value is structurally out of reach.

### Side properties

`padding_top` and `padding_bottom` merge into the existing dimensions value and touch
only that side. The other three sides, and the unit, are preserved:

```
before  { top: 64, right: 0, bottom: 64, left: 0, unit: px }
after   { top: 80, right: 0, bottom: 64, left: 0, unit: px }
```

### `max_width` has a companion requirement

Writing `boxed_width` on a container that is not already `boxed` would silently change
the section from full width to a fixed width. That is a design decision, not a fix, so
the eligibility check reports `companion_control_not_satisfied` and the correction is
refused by the validator.

### Where a value is stored

A desktop control lives in the element's own settings:

```
settings.padding          = { top: '64px', unit: 'px', … }
```

A device override does **not**. Elementor keeps responsive overrides in a separate
post-level map keyed by element id, and the editor reads them from there, so that is
where ReplicaForge writes them:

```
_elementor_responsive[ element_id ][ 'padding_tablet' ] = { … }
```

Nesting a device value under the control name inside `settings` would collide with the
control's own desktop value, and writing it as a flat `padding_tablet` key inside
`settings` would produce a document the editor silently ignores while ReplicaForge
reported the correction as applied. `Correction_Property_Map::read_control()` and
`write_control()` are the only two places that decide which map a value belongs in, so
the reader and the writer cannot disagree about it.

The responsive map is part of the draft's state, so it is captured in every snapshot
and restored on rollback. A rollback that restored only `_elementor_data` would leave a
device override behind, which is not the state the user was looking at.

### Coercion is idempotent

The validator coerces a value once, then the writer coerces the result again before
writing. A value already in the control's own shape is therefore accepted as it stands,
otherwise the second pass would refuse exactly what the first pass produced. The guard
accepts only the structure the coercer emits, so it is not a way around the range,
executable-content, or enumeration checks.

---

## 3. Eligibility

`Correction_Eligibility` is the human-control boundary. A correction is `safe` only
when **all** of the following hold. The checks run in order and the first failure
decides the outcome.

| # | Check | Outcome when it fails |
|---|---|---|
| 1 | Property is not on the never-correct list | `blocked` |
| 2 | Action is not structural | `requires_review` |
| 3 | Property has an Elementor control | `blocked` |
| 4 | A value was measured and it is inside the writable range | `blocked` |
| 5 | An Elementor element is mapped, or the map resolves it | `requires_review` |
| 6 | The property was not changed by a human after generation | `requires_review` |
| 7 | The property is currently set on the element | `requires_review` |
| 8 | Confidence ≥ 0.6 | `requires_review` |
| 9 | The difference category is in the automatic set | `requires_review` |

### A property with no control on that element is blocked, not queued for review

`font_size` is a widget-only control. When a difference resolves to a section
container, there is no control to write, so the planner reports it as `blocked` with
the reason rather than offering it for review. Offering it would promise the user a
change that the apply step could only refuse, which is the same as promising something
and then not doing it.

The same rule applies when the document already holds the source value. ReplicaForge
reports `The document already holds the source value for this property, so nothing
needs to change` and writes nothing, rather than rewriting an identical value.

### A font family is compared without regard to case

CSS matches family names case-insensitively, and the source side stores a comparable,
lowercased family. An exact comparison would see `Inter` and `inter` as different and
propose a correction that changes the document without changing how it renders, so a
case-only difference is treated as already matching.

### Never corrected automatically

`section_present`, `section_order`, `component_present`, `text`, `image_count`,
`image_present`, `aspect_ratio`, `column_count`, `column_progression`,
`mobile_navigation`, `rendered_comparison`, `rendered_difference`.

- **Structure** (add, remove, reorder) is proposed and requires explicit approval.
- **Content** is never rewritten. ReplicaForge shows both texts and lets the user edit.
- **Column count and responsive progression** change the page structure at every
  device at once, so they are reported rather than applied.
- **Rendered differences** are observations about pixels, not properties, so they
  cannot be expressed as a control write.
- **Assets** are never replaced automatically. Copying a file from the source website
  is a separate, explicit decision that Phase 6 does not make.

---

## 4. Manual edit protection

This is the part that makes a correction safe on a page a person has been working on.

`Correction_Snapshot` maintains two per-draft maps in post meta:

| Key | Contents |
|---|---|
| `replicaforge_correction_baseline` | element → property → device → value at the moment the baseline was established |
| `replicaforge_correction_state` | element → property → device → the value ReplicaForge last wrote |

A property is a **manual conflict** when the state map holds a value for it and the
document no longer holds that value. In that case the eligibility check returns
`requires_review` and the review screen shows the recorded value next to the current
value next to the source value.

Three rules keep this honest:

- The baseline is written **once**, on the first plan, and never overwritten for a
  property that already has an entry. A manual edit is therefore never absorbed into
  the baseline.
- The state map is updated **only** when ReplicaForge writes the value.
- A property with no record is not a conflict; it is simply unknown, and a
  `has_record: false` is reported as such.

Phase 4 now stores `replicaforge_generation_hash` — the hash of the document at
generation time — so a draft that was edited before the first correction run is
detectable as "edited since generation" even though individual properties are unknown.

### Known limitation

A manual edit made **before** the first Phase 6 plan cannot be distinguished from
generation output for that property. The baseline is established at that moment, so
every later change *is* detected exactly. Phase 5 re-validation plus the review screen
still show every difference, so nothing is hidden; the limitation is only that the
first-run conflict flag is not available.

---

## 5. Plan schema 6.0

```json
{
  "schema_version": "6.0",
  "plan_id": "plan_0123456789abcdef0123",
  "post_id": 123,
  "validation_id": "val_0123456789abcdef01234567",
  "validation_score": 87.0,
  "document_hash": "…",
  "counts": { "safe": 12, "requires_review": 5, "blocked": 2 },
  "levels_used": 4,
  "human_review_required": true,
  "applied": false,
  "read_only": true,
  "batches": [ { "batch": "typography", "safe": 4, "review": 0, "total": 4, "ids": [] } ],
  "corrections": [
    {
      "correction_id": "correction_001_9f2a1c7b",
      "difference_id": "diff_0003_9f2a1c7b",
      "category": "typography",
      "property": "font_size",
      "property_label": "Font size",
      "control": "typography_font_size",
      "action": "update",
      "level": 2,
      "batch": "typography",
      "viewport": "desktop",
      "severity": "moderate",
      "confidence": 0.96,
      "eligibility": "safe",
      "auto": true,
      "current_label": "56px",
      "expected_label": "64px",
      "difference": -8,
      "target": {
        "source_component_id": "hero_title",
        "source_section_id": "section_001",
        "elementor_element_id": "a82f31c"
      },
      "manually_modified": false,
      "manual_change": { "modified": false, "recorded_value": "—", "current_value": "—" },
      "reason": "Font size is a whitelisted property with a measured value, so it can be corrected at level 2."
    }
  ],
  "blocked": []
}
```

`plan_id` is `plan_` plus a truncated hash of the validation ID, the document hash, and
the fingerprints of every correction, so the same inputs always produce the same plan
ID and a different document produces a different one.

### Element mapping is never guessed

The target element is resolved in this order:

1. The `elementor_element_id` Phase 5 recorded, **if that element still exists**.
2. The Phase 4 identity map lookup for the source component, which is the
   authoritative link.
3. Otherwise nothing. The correction is `requires_review` with "No Elementor element is
   mapped to this difference, so ReplicaForge will not guess a target."

If Phase 5 recorded an element that the user has since deleted, the correction is
reported as `requires_review` with "The Elementor element this difference referred to no
longer exists in the draft."

---

## 6. Batching, order, and optimization

### Dependency order

```
structure → dimensions → direction → widths → spacing → typography → images → colors → responsive → finetune
```

A parent container dimension is corrected before a child width, and layout direction
before spacing, because a child width measured against a wrong parent is meaningless.

### Optimization

A container dimension change is what actually fixes the positions of everything inside
it. When the same source component already has a `max_width`, `width`, or `section_gap`
desktop correction, the component's `padding_top`, `padding_bottom`, `margin_top`,
`margin_bottom`, and `min_height` corrections for that component are dropped, because
correcting the parent is the smaller change.

### Limits

A plan is capped at 300 corrections and 400 distinct target elements. Anything dropped
is reported as a `blocked` entry with the reason, so a capped plan never looks
complete. A single apply request is capped at 200 corrections, 60 corrections per
iteration, and a 45 second wall-clock budget; corrections beyond that are recorded with
status `skipped` and reason `iteration_limit`.

---

## 7. Applying a batch

```
validate plan against current document   → refuse the whole plan on any fatal error
establish baseline
validate each selected correction        → refuse individually, keep the rest
create snapshot
cap to iteration budget
apply to the in-memory element tree
validate the document                     → refuse if invalid
save once through Elementor
re-read and validate the stored document  → refuse if invalid
record written values in the state map
run Phase 5 again
compare before and after
restore the snapshot if a regression was found
record the run in the history
```

**One save per batch.** A batch of any size is applied in memory, validated, and
persisted in a single `Document::save()`. A failure therefore cannot leave half a batch
written.

**The plan is bound to its document.** If the draft changed after the plan was created,
the apply request is refused with `plan_document_changed` and the user is asked to plan
again. This is checked before anything is validated or written.

### Structural corrections

A structural correction is refused by `Correction_Validator` unless the request carries
`approved_structural: true`, which the admin only sends when the user ticks the
explicit approval box. Once approved:

- **Reorder** rearranges the top-level elements. The order must contain exactly the
  current top-level identifiers, so a reorder cannot insert or drop a section.
- **Remove** deletes one top-level element, and is refused if it would leave the
  document empty.
- **Insert** rebuilds one section through the **Phase 4 mapper and builder** from the
  stored reconstruction specification. Phase 6 authors no Elementor structure of its
  own. When the specification is no longer available the insert is refused with
  `insert_specification_unavailable`.

---

## 8. Regression detection and rollback

`Regression_Detector` compares the validation before and after across four scopes:

| Scope | Threshold |
|---|---|
| overall | 2.0 points |
| each viewport | 3.0 points |
| each metric group | 3.0 points |

A scope that fell further from the source than its threshold is a regression. **An
overall gain never hides a per-viewport or per-group loss.** A correction that improved
desktop from 90 to 94 while mobile fell from 82 to 69 is reported as a regression, the
previous document is restored automatically, and the run is recorded with status
`rolled_back`.

### Snapshots

A snapshot stores the exact `_elementor_data` string plus the generation and
correction state maps. The body is kept in a bounded transient; the draft meta holds
only the summary list. A restore validates the snapshot document before writing it, so
a corrupt snapshot can never replace a working document, and it re-checks
`edit_post`. The most recent 5 snapshots are retained per draft.

`Correction_Report::snapshots()` lists them, and the Corrections screen shows them
with their hashes and sizes.

---

## 9. Iteration loop and stop conditions

A run reports why it stopped. It never loops silently.

| Stop condition | Meaning |
|---|---|
| `applied` | The batch applied and the re-validation measured an improvement. Plan again to see what is left. |
| `no_applicable_corrections` | None of the reviewed corrections could be applied, so nothing was written. |
| `improvement_below_limit` | The measured improvement was under 0.5 points, so correcting again would be chasing noise. |
| `max_iterations_reached` | The configured maximum of 3 iterations was reached. |
| `regression` | A regression was detected, so the loop stopped. |
| `rolled_back` | The previous document was restored, so the loop stopped. |

The improvement is reported as a **measured change**, never as a guarantee. Every run
report carries: *"These are the internal validation measurements described in the
validation report. An improvement is a measured change against the source analysis. It
is not a guarantee of visual quality and no visual accuracy is claimed."*

---

## 10. AI correction planning (Level 5)

Optional and off by default. When enabled, the model receives only the measured
corrections and may **only reorder them**.

The prompt states: *"Never change what a correction sets. Only propose the order it is
applied in."* Then the code enforces it:

- A recommendation for a `correction_id` not in the plan is **discarded** and counted.
- A dependency on an unknown correction, or a self-dependency, is dropped.
- The model's `order` becomes a bounded nudge of at most ±999 against a base weight of
  `index × 1000`, so a correction can move **within** its batch but never across
  batches. The deterministic batch order always dominates.

A run that discards recommendations reports how many were discarded and why. With no
AI available, the deterministic plan is complete on its own.

---

## 11. Correction history

Every run is recorded in the `replicaforge_corrections` option, bounded to 25 runs,
following the Phase 4 repository pattern rather than introducing a new table.

```json
{
  "correction_id": "cor_0123456789abcdef0123",
  "schema_version": "6.0",
  "engine_version": "1.0",
  "post_id": 123,
  "plan_id": "plan_…",
  "validation_id": "val_…",
  "generation_id": "gen_…",
  "snapshot_id": "snap_…",
  "validation_before": 87.0,
  "validation_after": 93.0,
  "improvement": 6.0,
  "iterations": 1,
  "regressions": 0,
  "status": "completed",
  "stop_reason": "applied",
  "counts": { "applied": 14, "rejected": 3, "blocked": 2, "failed": 0, "skipped": 0, "rolled_back": 0 },
  "changes": [
    {
      "correction_id": "correction_001_9f2a1c7b",
      "element_id": "a82f31c",
      "property": "font_size",
      "control": "typography_font_size",
      "viewport": "desktop",
      "action": "update",
      "old_value": "{\"size\":56,\"unit\":\"px\"}",
      "new_value": "{\"size\":64,\"unit\":\"px\"}",
      "status": "applied"
    }
  ],
  "created_by": 1,
  "created_at": "2026-09-25T12:00:00+00:00"
}
```

Per-correction statuses: `applied`, `rejected`, `blocked`, `failed`, `skipped`,
`rolled_back`. A status outside that vocabulary is normalized to `skipped`. No source
content, no credentials, and no document body is stored.

The run-level `status` is separate: `completed`, `no_change`, `rolled_back`, or
`failed`. A run that wrote nothing is recorded as `no_change`, never as `completed`,
because the review screen and the history both read `completed` as "the document now
matches the source better". For the same reason the run's closing sentence reports the
number of corrections actually written, and says the document is unchanged when it is.
A refused correction is stored with its property, control, viewport, and element, so
the history can say which value was refused rather than only why.

Tokens and sentences are sanitized differently. Identifiers, statuses, and property
names go through a slug sanitizer, which is correct for them. A warning is a sentence
a person reads, so it goes through a text sanitizer instead; running a sentence
through the slug sanitizer removes its spaces and produces unreadable output.

---

## 12. Security

Phase 6 adds no new validator and no new network access.

| Requirement | Implementation |
|---|---|
| Authentication | `manage_options` for every screen; `is_user_logged_in` and the REST nonce through the existing `can_analyze` callback |
| Authorization | `edit_pages` plus `edit_post` for the target draft, checked in the engine, the applier, the reader, and again on rollback and export |
| CSRF | REST nonce on every route; `check_admin_referer()` on admin forms |
| Revision safety | the reader refuses a post with no `replicaforge_generation_id`, a non-`builder` edit mode, a non-Elementor document, a non-draft status, or no `edit_post` capability |
| No arbitrary settings | the writer never reads a control name from the request; the whitelist resolves it |
| No arbitrary values | every value is re-derived through `Elementor_Values`; the validator re-checks the coerced result for executable content |
| No arbitrary structure | structural actions require explicit approval; an insert rebuilds through the Phase 4 mapper from a validated specification |
| Document integrity | validate before save, validate the stored document after save, refuse on either |
| Editability | only real Elementor controls are written. No screenshot, flattened image, iframe, HTML blob, shortcode, custom-code widget, PHP, or JavaScript is ever produced |
| No publishing | the reader refuses a non-draft, and the applier has no publishing call path |
| Credentials | the render token is never returned; exports contain no document data and no authorization material |

### Logging

`correction_started`, `correction_reviewed`, `correction_applied`,
`correction_rejected`, `correction_failed`, `rollback_started`,
`rollback_completed`, `regression_detected`, `correction_completed`,
`correction_planning_failed`. All through `Security::log_event()`, allow-listed scalars
only, `WP_DEBUG` only.

---

## 13. REST

All routes reuse `can_analyze`.

### `POST /wp-json/replicaforge/v1/corrections/plan`

| Param | Type | Notes |
|---|---|---|
| `validation_id` | string | a stored Phase 5 result; loaded server-side, never supplied by the browser |
| `draft_id` | integer | the generated draft |
| `design_representation` | object | optional, used only to re-measure later |
| `ai` | boolean | request the optional ordering pass |

### `POST /wp-json/replicaforge/v1/corrections/apply`

| Param | Type | Notes |
|---|---|---|
| `plan_id` | string | the reviewed plan |
| `selected` | array of string | the correction identifiers the user approved, pattern-checked |
| `approved_structural` | boolean | explicit approval for insert, remove, or reorder |
| `revalidate` | boolean | run Phase 5 again, default true |
| `iterations` | integer | 1–3, default 1 |
| `design_representation` | object | optional, for re-validation |

### `POST /wp-json/replicaforge/v1/corrections/rollback`

`draft_id` + `correction_id`. Restores the exact snapshot after validating it.

### `GET /wp-json/replicaforge/v1/corrections/history`

`draft_id`. The runs recorded for a draft the caller can edit.

### `POST /wp-json/replicaforge/v1/corrections/export`

`kind` (`plan`|`run`), `format` (`json`|`csv`), plus `plan_id`, `correction_id`, and
`draft_id`. No secrets, no document data.

---

## 14. Admin

**Analyzer screen → Phase 6 panel.** Appears once a draft exists. *Review Corrections*
opens the review screen showing safe / review-required / blocked counts, per-group
correction cards with current value, source value, difference, viewport, confidence,
Elementor element, target control, level, and reason, a *manually changed* warning where
a conflict exists, and selection checkboxes.

Buttons: *Select all safe*, *Clear selection*, *Apply Safe Corrections*, *Apply
Selected*, *Export Plan JSON*, *Export Plan CSV*. Structural corrections require a
separate explicit approval tick and an `window.confirm` that states what will happen.

The result screen shows before, after, the measured change, applied / rejected / failed
counts, regressions, the change table, the stop reason, and links to Elementor, the
preview, *Validate Again*, and the report export.

**ReplicaForge → Corrections.** A draft selector, the correction history table, the
snapshot list with hashes and sizes, the six correction levels, and the full writable
property table with its Elementor control, level, batch, and per-device flag.

Every remote value is inserted with `textContent`. The admin document never receives
remote markup.

---

## 15. Testing

```
wp eval-file wp-content/plugins/replicaforge/tests/phase6-contract-test.php
```

The test is deterministic and never calls an AI provider. It covers:

1. **Typography** — one selected correction changes one property; an unrelated colour on
   the same element and a different element are both unchanged.
2. **Colour** — the corrected colour becomes the source value; no other colour moves.
3. **Spacing** — a `padding_top` correction changes only the top side; bottom, left,
   and right are unchanged.
4. **Responsive** — a tablet correction writes `flex_direction_tablet`; the desktop
   value and any mobile rule are untouched.
5. **Missing section** — proposed as an insert and `requires_review`, never applied.
6. **Extra section** — proposed as a removal and refused by the validator without
   explicit approval.
7. **Manual Elementor edit** — detected as a conflict and downgraded to review.
8. **Rollback** — the restored document hash equals the snapshot hash; an unknown
   snapshot and a cross-draft snapshot are refused.
9. **Regression** — an overall gain that damages mobile and a metric group is reported.
10. **Invalid correction** — an unknown control, an executable value, a scheme value,
    an unknown element, and an unapproved structural action are all refused with a
    reason.
11. **Unauthorized request** — plan and apply are both refused with no user.
12. **Published page** — refused with `page_is_not_a_draft`, and the workflow never
    publishes.
13. **The whole loop, end to end** — a deliberately wrong font size is written into the
    draft, then validate → plan → apply → roll back. The test asserts the correction is
    proposed for that element, that the stored document really holds the source value
    after the apply, and that the rollback brings the wrong value back. This is the only
    test that drives the engine the way the review screen does, so it is the one that
    catches a defect in the planner, the validator, or the applier rather than only in
    the writer.

Every correction in that loop is asserted on the stored document, not on what the run
reported. A run that reports success while changing nothing is the failure mode this
phase must never have, so the checks read `_elementor_data` back.

### Manual QA

1. Analyze → generate the draft → validate it.
2. *Review Corrections*. Confirm the counts, read a few reasons, check that a manually
   changed property is flagged.
3. Tick *Select all safe* → *Apply Safe Corrections* → confirm. Read the before/after and
   the change table.
4. Open the draft in Elementor. Confirm the changes are real, editable properties and
   that the page still renders as containers and widgets.
5. Change a property by hand, re-validate, and plan again. Confirm that property is now
   flagged as a manual change.
6. Roll back from **ReplicaForge → Corrections** and confirm the document returns
   exactly to the snapshot hash.
7. Delete the test draft.

---

## 16. Known limitations

- No full-page regeneration exists. Section insertion requires a stored specification
  that expires after 24 hours.
- Column-count and responsive-progression changes are reported, never applied, because
  they change the structure at every device at once.
- A manual edit made before the first Phase 6 plan is not distinguishable per property.
- Spacing beyond the whitelist, and the Phase 2 representation's lack of per-component
  padding, margin, and minimum height, mean some measured differences have nothing to
  correct.
- AI planning can reorder within a batch but never across batches.
- Snapshots are time-limited and only the 5 most recent per draft are retained.
- A correction that improves the measurement is not a guarantee of visual quality, and
  no visual accuracy is claimed.

---

## 17. Phase 6 boundary

Phase 6 reads, compares, reviews, applies reviewed property changes, and reports. It
does not regenerate a page, does not publish, does not rewrite content, does not
schedule anything, and does not touch anything outside the drafts ReplicaForge
generated.

```
PHASE 4  Generate Editable Elementor Draft
PHASE 5  Measure + Compare + Explain Differences
PHASE 6  Review + Apply Safe Corrections + Re-validate + Roll back
PHASE 7  Not implemented
```

Not implemented, by design: continuous synchronization, scheduled re-analysis,
multi-site sync, SaaS accounts, subscriptions, billing, team management, agency
collaboration, template marketplaces, cloud crawler infrastructure, and automatic
publishing.
