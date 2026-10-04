# Source Change Detection

> **Phase 9 status: the detection half is IMPLEMENTED AND TESTED. The apply half is not.**
> `Component_Matcher` and `Change_Detector` are complete and covered by 289
> assertions. `Sync_Planner`, `Sync_Validator`, and `Sync_Service` are not built, so a
> detected change currently goes nowhere. That boundary is stated again at the end of
> this document and in the completion report.
>
> The Phase 8 version of this file was a decision record with no code behind it. This
> one describes what exists.

## The problem this solves

A replica is a snapshot and the source keeps moving. Comparing raw HTML is useless: a
timestamp in a footer, a rotating testimonial, or a cache-busted asset URL reports a
change on every visit. A detector that cries wolf trains a user to ignore it, which is
worse than having no detector.

So the comparison runs on the **normalized representation**, never on source bytes, and
only on values that mean something visually.

## Identity comes before comparison

`Component_Matcher` exists because comparison is meaningless without it. It has to
answer "is this the same heading?" before anything can ask "did its font size change?".

The class that failure is built to prevent is **matching by position**. If identity is
the index in a list, then inserting a badge above a hero heading renumbers everything
below it, and a change detector comparing two analyses would report that the heading
was removed and a new, different heading was added. The user would be shown a
delete-and-recreate for a heading that never moved.

That is asserted directly, in `tests/phase9-foundation-test.php` section 14: a badge is
inserted at the top of a four-component hero, and all four originals match, nothing is
reported removed, and only the badge is reported added.

### Two hashes, not one

| Hash | Built from | Used for |
|---|---|---|
| `fingerprint` | role, tag | **Identity.** Deliberately excludes position, index, text, and image. |
| `content_hash` | role, tag, text, image | **Certainty.** Two components with identical content are certainly the same one. |

The split is the single most important design decision in Phase 9, and it was arrived at
by getting it wrong first. An earlier version used one text-inclusive fingerprint as
identity, which meant **every headline edit was reported as a removal and an addition** —
the exact opposite of what incremental synchronisation is for. Text and image are the
two things a design change most often alters, so neither can be part of identity.

The consequence is that text and image have to earn their place as *evidence* rather
than as identity, and they earn it in two different ways:

- **Text** contributes 0.10 of similarity, and acts as a **veto** below 0.35. Two
  components whose text is entirely different are not the same component however much
  else they share — a hero with two paragraphs would otherwise be paired arbitrarily.
- **Image** contributes 0.14, and caps the match confidence at 0.4 when the two
  differ and neither has text. It does *not* veto, because a replaced hero image is a
  modification of a component that is probably still the same component, and reporting
  it as a removal plus an addition would ask a person to decide about two changes
  instead of one. The uncertainty is carried as a low confidence, which sends it to a
  person.

Both are asserted in both directions — with and without alt text — because the choice
between "modification with low confidence" and "rebuild" is a judgement worth pinning.

### The weights

| Signal | Weight | Why |
|---|---|---|
| `fingerprint` | 0.34 | The identity. Strongest single signal. |
| `role` | 0.20 | A heading is a heading. |
| `section` | 0.18 | Almost always matches within its own section. |
| `image` | 0.14 | Strong evidence, and the thing a design change alters. |
| `text` | 0.10 | Corroboration, and a veto when it disagrees. |
| `structure` | 0.03 | Tag and child count. |
| `position` | 0.01 | **Last resort.** The one signal an insertion destroys. |

A match resting only on position is capped at 0.4 confidence, which is the brief's §16
requirement stated as a number.

### Acceptance by elimination

A component whose only evidence is a shared role and section scores around 0.24 —
below the 0.72 acceptance threshold. An untitled hero image whose file was replaced
falls into exactly that case.

The score is right and the outcome was wrong, so one more rule was added: **when an old
component has exactly one candidate above the floor, that candidate is accepted
whatever the direct evidence scored.** Nothing else on the page could plausibly be that
component, and that is a stronger signal than a shared role. It is not a lowered
threshold — a component with two or more candidates is still not matched.

The match keeps its low confidence, so the result is reported as a modification of a
probably-still-the-same component and goes to a person, rather than being claimed as
certain or dressed up as a rebuild.

### Performance: the search is blocked by section

The candidate search originally compared every old component against every new one. On
a page at the 1200-component bound that is over a million scored pairs, each running a
string comparison — and a monitoring check that takes minutes is the opposite of §73's
requirement that a check be cheap.

New components are now indexed by section, and each old component is scored against its
own section first. The full sweep is the fallback for a section with **no** new
components at all, which is where a genuine section move lands. Measured:

| Components | Before | After |
|---|---|---|
| 200 | 0.03s | 0.03s |
| 600 | 0.26s | 0.26s |
| 1200 | 1.13s | 1.13s |

The important part is that an earlier attempt at this fix made it *worse* and hung the
suite: the fallback swept whenever a section pool produced nothing above the floor,
rather than only when the section was empty. On a page whose components all share a
role and never clear the floor, every old component triggered a full sweep. A pool that
exists but yields nothing means the section is accounted for.

## Comparison

`Change_Detector::compare( $previous, $current, $options )` returns a structured
report, not a flat list. Section-level and component-level comparison are separate
because they answer different questions: "is the hero still there" is a section
question, "did the heading's font size change" is a component question.

### What is compared

37 component fields and 8 section fields, each mapped to one of the brief's 17
categories. A field is either compared or not; there is no third state, because a field
that is sometimes compared produces reports whose contents depend on which fields
happened to be present.

### What produces no change, and why

| Case | Why it is not a change |
|---|---|
| Collapsed whitespace, trailing newline | A source that reformats its own markup should not report a content change on every comparison |
| `hero.jpg?v=8f2a91` vs `hero.jpg` | A deploy appending a cache-buster is not a content change. Asset fields are compared through a shared normaliser that resolves dot segments and drops the query |
| `/a/../b.jpg` vs `/b.jpg` | A server resolves them to the same file |
| A field not on the comparison list | Comparing a field nobody acts on adds noise |
| An ignored field | And an ignored field is reported in `ignored_fields`, so a filtered report says it was filtered |

A field that **disappeared** upstream *is* a change when it is a compared field, because
dropping a style is visible. The new value is `null`, which is a difference and not a
zero.

### The report

```php
array(
  'changes'          => array( /* each with type, category, source_component_id,
                                    field, detail, confidence, evidence */ ),
  'count'            => int,
  'by_category'      => array( /* all 17 categories, including the zeroes */ ),
  'by_type'          => array( /* added, removed, modified, moved, unchanged */ ),
  'sections'         => array( /* per section: state, positions, field change count */ ),
  'components'       => array( 'previous', 'current', 'matched', 'bounded', 'flatten_truncated' ),
  'identical'        => bool,
  'truncated'        => bool,
)
```

Two bounds are reported **separately**, because they are different problems and a
"no changes" result has to say which one it is:

- `components.bounded` — the matcher bounded its own comparison.
- `components.flatten_truncated` — the representation held more components than the
  flattening cap retained, so part of the page was never examined.

`flatten_truncated` did not exist until a test caught it. The flatten cap silently
truncated a 1500-component page to 1200, the comparison covered that 1200 in full, and
`truncated` came back **false**. A caller would have read "no changes" off a comparison
that never looked at a third of the page.

## Classification

`Change_Classifier` answers three separate questions, deliberately not collapsed into
one score:

| Answer | Question |
|---|---|
| `severity` | How large is the difference on the source page? |
| `risk` | How much damage could applying it do? |
| `review` | Must a person decide? |

Collapsing them is what makes a risk list unusable. A colour change is severe enough to
be visible and harmless enough to apply. A deleted section is the reverse.

The three-way separation is asserted, not just described: a reworded heading comes out
**low risk but still requiring review**, because the change is harmless and the reason
a person is asked is that the system is not certain enough. Those are different
objections and they are answered differently.

### Rules

- A field weight is the most specific evidence, so it wins over the category and type.
- A **removal** is at least `major` severity and `high` risk whatever it removes. A
  deleted paragraph and a deleted section are not the same size of loss, and both are
  beyond minor.
- A **responsive** change is raised to at least medium risk, because it is invisible in
  a single-viewport check.
- A confidence below 0.75 raises the risk, because applying the change might address
  the wrong component entirely.
- Five categories are **never** automatic: `section`, `navigation`, `interaction`,
  `theme`, and `product`. A price is in that list because it is a figure ReplicaForge
  has no business changing unattended. Their risk is `high` because the harm is not
  expressible as a number — a restructured navigation is not "high risk" in a way a
  number conveys; it is a category a person owns.
- Not knowing what was matched is not a reason to act: below `MIN_AUTO_CONFIDENCE`
  (0.9) a change requires review.

Every classification carries a `basis` array naming the rules that produced it, because
§11 says these are classifications and not assumptions about user preference — a
severity with no stated basis is an opinion.

### The automatic path, honestly

`is_auto_safe()` requires **all** of: not a removal, the category is on the
`AUTO_SAFE_CATEGORIES` list (which is `content` alone), risk is at or below the
ceiling, confidence is at or above the threshold, and review is not required. Every
condition is checked, so adding one can only reduce what is automatic.

In practice a change to a compared field **cannot** reach the threshold, because the
field is precisely what differs and the match can never be certain. So the automatic
path is currently reachable only for a change whose field was not part of the match
evidence. That is asserted both ways rather than tuned to make the path look busy.

### Grouping

`group()` collapses related changes per component and category, so three typography
changes on one heading become one row with three fields inside it — §50's requirement
answered by the data rather than by the screen. Groups carry the highest severity and
risk of their members and are ordered by risk, so the thing needing a person is first.

## What is not built

- **Source version storage.** `Source_Version` does not exist. The detector compares two
  representation arrays handed to it; nothing stores or retrieves them.
- **Monitoring.** No `Source_Monitor`, no cron, no opt-in, no reachability handling.
  See [`MONITORING.md`](MONITORING.md).
- **The plan and apply half.** `Sync_Planner`, `Sync_Validator`, and `Sync_Service` do
  not exist, so a detected change is currently not proposed, not validated, not
  applied, and not rolled back.
- **Fast pre-checks.** §74's staged detection (ETag, `Last-Modified`, content hash
  before full analysis) is not implemented; the detector always runs a full comparison.

## Related

- [`CONFLICTS.md`](CONFLICTS.md) — the ownership model the plan half would use.
- [`PHASE-9-SYNC.md`](PHASE-9-SYNC.md) — the whole phase.
- [`PHASE-9-COMPLETION-REPORT.md`](PHASE-9-COMPLETION-REPORT.md)
