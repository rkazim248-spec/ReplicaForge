# Visual Intelligence (Phase 13)

How ReplicaForge understands what a page *looks like*, why it refuses to fake that
understanding, and where the line sits between measuring a page and rebuilding it.

---

## 1. The one-sentence version

Phase 13 adds a rendering abstraction, a visual analysis pipeline, a multi-signal
comparison engine, and a bounded correction loop — all of which are **optional**, all of
which **degrade to DOM/CSS analysis** when no renderer is configured, and none of which
ever turn a screenshot into the generated page.

---

## 2. What "visual intelligence" means here, and what it does not

Rea two sources of truth about a page's geometry, and they are not equally reliable:

| Source | What it tells you | Availability |
| --- | --- | --- |
| Computed CSS | what the browser was *instructed* to do | always, with no renderer |
| Rendered geometry | what *actually happened* after layout, after a late font, after a media query | only with a renderer |

The instinct on finding two numbers is to prefer the rendered one, because it is "real".
That is usually right, and it is **wrong in the specific case that matters most** for
reconstruction: the replica will be rendered by Elementor, on the user's host, with a
different font stack and a different width. Following rendered geometry pixel-for-pixel
into a document that will be re-laid-out bakes in measurements that will not survive the
round trip.

So `Visual_Analyzer` **picks no winner**. It stores both, labels them, and records a
`measurement_conflict` when they disagree by more than the tolerance (§14). A consumer
that needs CSS-reproducible numbers reads `computed`; one that needs what a visitor saw
reads `rendered`. Neither is discarded and neither is silently preferred.

### The three things Phase 13 will not do

1. **Fabricate a measurement.** A derived bounding box is a *claim* about a layout that
   has not happened. It carries `derived: true` and a confidence of 0.55 — not 0.9. With
   no render, the whole representation's confidence is **capped at 0.6**, and the cap is
   stated with a reason.
2. **Invent a container value.** A measured width of 1198 is not a container a designer
   chose; it is a viewport minus two margins. `max_width` is only reported when the
   width is a value someone would have picked.
3. **Guess from a name.** `Playfair Display` is a serif and its *name* says nothing
   about that. A name cannot determine a family, so `font_fallback()` returns
   `family: unknown` with no substitute stack and `impact: unknown` — rather than
   defaulting to `sans-serif`, which is the substitute most likely to be wrong.

---

## 3. The rendering boundary

### Why rendering is out of process, always

ReplicaForge cannot ship a headless browser, and rendering the analyzed page **inside the
WordPress process** would execute untrusted site content in the same interpreter that
holds the site's database credentials. That is not a risk worth taking for a screenshot.

So:

- Rendering happens **out of process**, at an endpoint **you** configure, or not at all.
- Source JavaScript **never** executes inside WordPress. `Endpoint_Renderer::capabilities()`
  reports `javascript: false` and the comment says why: the provider runs scripts in its
  own sandbox, and this process must never be able to.
- `Visual_Renderer` (Phase 5) is **wrapped, not replaced**. It already does the hard part —
  validated endpoint, bounded HTML, scripts and media blocked, size cap, time limit.
  `Endpoint_Renderer` translates the contract's request shape into the shape Phase 5
  already accepts. There is exactly one place a screenshot is captured and exactly one
  place an SSRF mistake could hide.

### What the abstraction looks like

```
includes/visual/
  interface-replicaforge-renderer.php       Renderer_Contract
  class-replicaforge-renderer-manager.php   Renderer_Manager + Endpoint_Renderer
```

A future Playwright, Puppeteer, Browserless, or local-Chromium provider implements
`Renderer_Contract` and needs no change anywhere else. `Renderer_Manager::capabilities()`
returns the §4 detection table with **every key present as a boolean** — a provider that
omits a key is treated as not supporting it, so omitting is always the safe direction.

### Degradation is a first-class output, not a fallback

With no provider configured:

```php
$manager->capture( $request );
// => array(
//   'success'  => false,
//   'degraded' => true,
//   'error'    => array( 'code' => 'renderer_unavailable', 'message' => … ),
//   'fallback' => 'Continuing with DOM and CSS analysis. Rendered geometry, overlap, and
//                 pixel difference are unavailable and are reported as unknown rather
//                 than estimated.',
// )
```

The tempting shortcut is to synthesise a "visual estimate" from the DOM so downstream
stages have something to consume. That is a fabrication with a confidence score attached,
and a confidence score is exactly what makes a fabrication dangerous — it reads as a
measurement.

---

## 4. Viewports, DPR, and normalisation

`Visual_Limits::viewports()` **reads** `Validation_Limits::VIEWPORTS` rather than
restating it, so a Phase 5 report and a Phase 13 report cannot disagree about what
"tablet" means.

| Profile | Size |
| --- | --- |
| desktop | 1440 × 900 |
| tablet | 768 × 1024 |
| mobile | 390 × 844 |

A fourth profile is filterable via `replicaforge_visual_viewports`, and any profile a
filter supplies is clamped to 320–3840 wide so a filter cannot request a memory
exhaustion.

**Device pixel ratio defaults to 1.** A DPR above one multiplies every pixel by its
square — a 1440-wide page at DPR 2 is 2880 pixels and four times the memory — and a
comparison between a DPR-1 source and a DPR-2 replica is not a comparison of the same
thing. When a provider does not report a DPR, `Renderer_Manager::capture()` records
`rendered_dpr: null` and `dpr_known: false` rather than asserting `1.0`, which would be a
claim about a render nobody measured. `Viewport_Manager::normalisation()` then reports
`resample_to_css_pixels` **and a caveat**, because a resampled difference ratio is a
statement about the images, not only about the design.

---

## 5. Render stabilization

§8's steps are a **request** to the provider, and the request is recorded as a request:

```
load → dom → network_idle → images → lazy_load → animations_off → capture
```

Forwarded to the Phase 5 renderer as an additive `stabilize` payload key, absent unless a
caller asks — so a Phase 5 validation run sends exactly the payload it sent before.

`wait_ms` is bounded at 10 s **in the renderer**, because an unbounded wait is a
denial-of-service lever aimed at whoever is hosting the renderer, and a provider that
waits minutes on an animation that never ends is worse than one that captures early.

And the honest part: `Render_Job::stabilization()` returns a `guarantees` string saying
**a page that never settles cannot be made stable**. When the wait expires the capture is
taken anyway and the region is marked dynamic rather than reported as a reconstruction
fault.

---

## 6. Dynamic content and transient UI

This class runs *before* reconstruction, and its output has two deliberately separate
effects:

| Effect | Meaning |
| --- | --- |
| `exclude_from_reconstruction` | transient UI; must not become content |
| `mask_from_comparison` | dynamic; a difference there is not evidence of a fault |

Keeping them separate matters. A **permanent** newsletter signup is excluded from neither:
it should be reconstructed, and a difference in it should be reported.

Why a cookie banner is excluded by default:

> A replica that shows a cookie banner for cookies it does not set is a **privacy defect**,
> not a fidelity win.

| Detected | Excluded | Masked |
| --- | --- | --- |
| cookie / consent UI | yes | yes |
| chat widget, floating button | yes | yes |
| advertisement | yes | yes |
| popup, modal, exit-intent | yes | yes |
| `<video>`, `<audio>`, `<canvas>`, `<iframe>` | no | yes |
| timestamp, live counter, carousel | no | yes |
| animations | no | yes |

A genuine paragraph is none of these — and a detector that returned `unknown` for
everything would be indistinguishable from one that detects nothing, so a node with no
signal returns `null`, not a guess.

Class-name matches carry a **confidence of 0.65**; a regex match on visible *text* carries
**0.90**, because the text is what makes the thing dynamic. The user may override any of
it.

### Carousels and video

A carousel becomes a **static representative layout**, marked
`dynamic_behavior_approximation` — the phrase is in the data so no report can present it
as a working carousel.

A video is **referenced, never downloaded**, `action: preserve_reference`. Reconstructing
the layout around a licensed asset is safe; reproducing it is not ReplicaForge's call.

### Sticky, fixed, and mobile navigation

Sticky and fixed elements are detected from declared `position`, and both are marked
`no_script: true`. Elementor's sticky behaviour is limited, so the record says what the
element *is* and the planner decides how to express it. There is no scroll script and no
infinite scroll, ever.

Mobile navigation is inferred from **observable structure** only — a `nav` element plus a
`menu-toggle`/`hamburger` control — because §42 forbids executing source JavaScript. The
replica's mobile menu is an Elementor-native collapse or it is not there.

---

## 7. Grids: why derived boxes are excluded

§18 asks for a visual grid even when the CSS differs. A four-card row is detected from
geometry: equal widths, equal gaps, `columns = 4`, `gap = 20`.

The important filter is that **derived boxes never participate**:

> A derived box sums declared heights down the page, which is exactly right for a stack
> and exactly wrong for a grid. It would place four cards side by side in the same "row"
> and report a **six**-column grid where there are four.

A grid inferred from an estimate is not a weaker grid, it is a fabricated one, so the
estimate is dropped rather than downweighted. When the only candidates are derived, the
result is a `limitation` string saying so and naming the count.

A grid inferred from geometry is a **hypothesis about the CSS**, and it says so:
`confirmed_by_css: false` and `confidence: 0.55` unless the representation also declares
a grid or flex container for the same items. A grid with equal widths *and* equal gaps
gets 0.8.

---

## 8. Evidence, and why conflicts are never resolved

§51 requires evidence on every important inference. That is enforced by
`Visual_Representation::record()`, which **refuses** an inference with no evidence rather
than storing an empty `evidence` key — a stored inference with no basis is
indistinguishable from a measured one at read time.

Confidence is a **mapping from the kind of evidence**, not a per-caller number, so two
callers producing the same kind of evidence cannot disagree about its strength:

| Evidence | Confidence | Source |
| --- | --- | --- |
| computed + rendered | 0.95 | `computed_css_and_render` |
| rendered only | 0.85 | `render` |
| computed only | 0.75 | `computed_css` |
| neither recognised | 0.50 | `unclassified` |

§52 requires both sides of a disagreement. `record_conflict()` bands the delta against
Phase 5's own length tolerances and returns `exact` / `approximate` / `conflict` /
`unknown` — and there is deliberately **no `resolved`**, because whether CSS or the render
will be reproduced depends on where the value is going. A container width going into an
Elementor setting is CSS-reproducible; a container width going into a pixel comparison is
not. The representation cannot know which, so it refuses to decide.

`validate()` treats a conflict missing a side as an **error**, not a warning. §52 is
enforced, not just documented.

---

## 9. Independence from Elementor

§49 requires the representation to be independent from Elementor, and the reason is that
a representation which knows about containers and widgets can only be checked by a machine
that also knows about containers and widgets. Then "the replica is correct" and "Elementor
agrees" become the same claim, and a bug in the Elementor layer becomes undetectable.

So nothing in the schema mentions a widget, an element type, or a control. This was not
free — `Visual_Effects` returns an `elementor` sub-array beside every shadow and border,
and passing those through put Elementor's own structure inside the document. Those keys
are now removed recursively, and the **loss** they represented is recorded in plain words
under `downstream_limitation`.

The replacement key is deliberately *not* named after the consumer. A key called
`elementor_limitation` would satisfy "the structure is gone" while keeping Elementor's
name inside a document that is supposed to be checkable without knowing about it.

---

## 10. Comparison: six signals, and why one is not enough

Two failures can produce identical differing-pixel ratios and mean very different things:

- a heading 40 px to the left of where it should be — a visible layout error
- a video frame differing — a frame of somebody else's video that will differ *forever*
  and can never be fixed

Ranking those identically is how a validation report becomes noise. So the signals are
computed separately and only then combined:

| Signal | Weight | What it catches |
| --- | --- | --- |
| pixel | 0.35 | colour and texture change anywhere |
| geometry | 0.30 | elements displaced or resized |
| structure | 0.20 | elements missing **or** extra |
| color | 0.08 | palette divergence |
| typography | 0.04 | size / family / weight |
| spacing | 0.03 | vertical rhythm |

Structure is deliberately **two-sided**: an element in the replica that the source does
not have is a difference too, and a comparator that only counted what was missing would
call a replica with a duplicated footer a better match.

The verdict reports the **weight actually accumulated**. A score computed from two
signals is a different claim from one computed from six, and a consumer needs to know
which it is looking at.

### Cross-check

When nothing is masked, Phase 13's pixel walk and Phase 5's reviewed `Image_Differ` both
walk the same two images, and their ratios are compared. If they disagree by more than
5%, that is **recorded, not resolved** — two different walks disagreeing means at least
one has a bug, and a report that quietly prefers the newer one would hide that. The
cross-check is skipped when anything is masked, because Phase 5's differ has no mask
parameter and comparing unmasked against masked would be comparing two different
measurements.

### Masking happens before the ratio

A masked region contributes to **no signal at all** — it is not subtracted afterwards.
This is asserted behaviourally: a page that differs in *every* pixel but is entirely masked
reads as a zero ratio, and masking exactly the differing half of a page also reads as
zero, which is only possible if the mask is applied first.

`Dynamic_Detector` is the source of masks, and `Visual_Limits::MASK_REASONS` is a closed
list. An unrecognised reason does **not** mask, so masking can never hide a real fault by
accident.

### Regions, heatmap, overlays

- **Regions** (§62) compare named page areas, not just the whole page. Severity is based
  on the *share of that region* that differs, not the page score: a footer differing on
  60% of its own pixels is a real problem even when it is 2% of the page. A region with
  fewer than 100 compared pixels is `informational`, because a 1% difference in a
  40-pixel region is one pixel.
- **Heatmap** (§63) is real computed cell values, normalised against the **worst cell**
  rather than against 1.0 — a heatmap's job is showing *where* differences concentrate, and
  a scale pinned to 1.0 makes a 4%-worst page look uniformly cool.
- **Overlays** (§64) are declared as four modes (source, replica, 50/50, difference) with
  their blend rules. The images are produced by the admin screen from the two captures
  rather than generated on every comparison, which would double storage for a view read
  occasionally.

---

## 11. The correction loop is bounded by construction

`Visual_Limits::MAX_CORRECTION_ITERATIONS` is the only source of the number, and
`Visual_Corrector::iterate()` **clamps whatever it is given**. A caller asking for ten
iterations gets three, and is told it got three.

The loop also **stops on the first iteration that does not improve the comparison**.
Continuing past one means the loop is now making things worse and calling it optimisation.

`iterate()` measures, decides, and reports. It **does not write** — the caller applies. A
corrector that both measures and writes cannot be reasoned about when an iteration goes
wrong.

### Priority

Structure → layout → size → position → alignment → layering → responsive → images →
typography → spacing → colour → background → shadow → border → radius.

Structure first and shadows last, because a corrected container width that then gets a
corrected radius will be corrected *again* by the width fix, and a loop that alternates
between two properties never converges.

### Three things are refused, each for a stated reason

| Refused | Why |
| --- | --- |
| a difference inside a masked region | it is not evidence of a fault |
| `capture_size_mismatch` | correcting a replica to satisfy two differently-sized images would corrupt the design |
| a component the user has edited | Phase 6's existing rule, read from `Component_Registry` rather than reimplemented |

### Regression protection is the reason the class exists

The obvious way to fix a desktop difference — widen a container, change a margin, hide a
section — very often makes mobile worse, because a fixed width that happened to fit at
1440 is what was keeping a 390 px layout from collapsing. Correcting at desktop only is
how a replica ends up pixel-accurate on the screenshot and broken on the phone.

| Before → after | Verdict | Applied |
| --- | --- | --- |
| all up | `improved` | yes |
| some up, some down | `mixed` | **no** |
| nothing up, something down | `regressed` | **no** |
| no change beyond noise (±0.01) | `neutral` | yes |
| a viewport with no after-report | that viewport is `unknown` | — |

The ±0.01 band exists because reporting a 0.001 improvement as a win is how a loop
convinces itself it is working. A viewport that could not be measured is **unknown, not
passing**.

---

## 12. AI vision: five gates, and a structural fallback

§53 and §54 put real constraints on visual AI, and each is a gate that **refuses** rather
than a warning that is logged:

1. **The user turned it on.** A screenshot can contain anything the page displayed,
   including a logged-in name. Sending one is not a default.
2. **The provider declares image input**, read from Phase 11's existing
   `Ai_Capabilities::supports( 'vision', … )`. A model that *might* take images is not a
   model that takes images, and a detection that guesses from a model name is not a
   detection.
3. **The budget allows it**, using `Ai_Context_Budget` against the same limit as text.
4. **The screenshot is small enough** (≤ 256 KB, ≤ 900 px edge). An oversized image is
   *dropped* rather than sent over budget and rejected after a reservation was spent.
5. **There is something to look at.**

When any gate refuses, the caller gets a **structured visual representation** and the
reason. The fallback is structural, not a message: for the questions §36 actually asks —
hierarchy, tokens, relationships — the representation is often the *better* input,
because it is exact where a picture is ambiguous. The refusal is not framed as a loss.

### Usage is never billed in tokens

`estimate()` returns `unit: 'image'`, with a `disclosure` saying in those words that this
is a count of images and their size, **not a billing amount**. An image is not a token,
and calling it one would be a fabricated number. Actual provider cost is not visible to
ReplicaForge and the response says so.

---

## 13. Cache keys say how the answer was produced

Every entry in the key is there because omitting it produces a *plausible wrong answer*
rather than a cache miss:

| Part | Omitted, what goes wrong |
| --- | --- |
| `source_hash` | the page actually changed |
| `generated_hash` | the replica actually changed |
| `viewport` + size + DPR | a different render is served |
| `renderer_version` | a renderer upgrade serves a stale capture that looks fresh |
| `ANALYZER_VERSION` | a better analysis is never used; this is why the constant must be bumped |
| `normalization` | a DPR or masking change alters the numbers |
| `masked` | a masked comparison shares a key with an unmasked one |

A key missing any of these returns a stale answer **with a current timestamp**, which is
the one thing a cache must never do.

Entries are bounded (120), carry a TTL, are pruned **on read as well as on write** so a
cache nobody reads still does not grow, and an index entry whose body is gone is removed
on read — a dangling index would report a hit that returns nothing, which is worse than a
miss.

### Retention

`temporary` | `project` | `snapshot` | `deleted`, defaulting to seven days — matching
Phase 5's `RESULT_TTL`. A screenshot is evidence for a *specific* finding; evidence for a
finding from three weeks ago is not what a user opens the report to see.

---

## 14. Render jobs reuse the Phase 11 queue

§76 says to use Phase 11 orchestration, and the point is not politeness. A second queue
would mean a second place where a lock can be taken twice, a second recovery path, and a
second set of states — and Phase 11's own notes record how much of its design exists to
prevent exactly one of those.

So `Render_Job` produces a **job payload in the vocabulary `Job_Repository` and
`Job_Checkpoint` already understand**, and the existing runner executes it. The visual
stages reuse Phase 11's existing stage slots rather than appending new ones, because
appending would change the `stage_index()` arithmetic that recovery depends on.

`MAX_RENDERS_PER_JOB` is 75. A 25-page project at three viewports is 75 renders — a plan
limit rather than a technical one, but bounded here as well, because a technical bound is
the one that holds when a plan changes. Exceeding it **reduces the page count and reports
it**, rather than silently applying the bound.

---

## 15. Security posture

Stated once, because it holds across every route:

1. **No route accepts a URL to render.** Discovery is the only path that produces URLs,
   and every one it produced has been through `Url_Validator`. A caller that wants a page
   rendered names a *project page*, not an address — so the visual controller cannot be
   used to ask the render provider to fetch an internal address, even by accident. This is
   asserted by a comment-stripped source scan for `get_param( 'url' )`.
2. **No route returns a filesystem path.** Captures are addressed by opaque key and the
   response carries bytes and dimensions only. A leaked `wp-content/uploads/...` path is a
   reconnaissance gift. Asserted by scanning for `uploads` and `wp_get_upload_dir`.
3. **Ownership is resolved before the store is read**, with one message for "missing" and
   "not yours", so a project id cannot be enumerated.
4. **The owning project is recorded in the cache index**, not derived from the key, and
   compared before any capture bytes are returned.
5. Captures are served `nosniff`, `private, max-age=0, no-store`, with a
   `Content-Disposition` — a rendered third-party page must not be re-interpreted by the
   browser or cached by an intermediary.

Source JavaScript never executes in WordPress. Asserted by scanning the visual API for
`eval(`, `shell_exec`, `proc_open`, and `popen`.

---

## 16. What is deliberately absent

- **No new database table.** Four bounded options, no migrations for data.
- **No second queue, table, AI provider, validation engine, or compatibility layer.**
- **No screenshot becomes a page.** A comment-stripped scan of `includes/visual/` asserts
  that nothing turns a screenshot into a page background or an image widget.
- **No admin UI.** REST responses exist; nothing renders them.
- **No renderer is shipped.** The abstraction, the detection, and the degradation are
  built and tested; the browser is the operator's to provide.

---

## 17. Honest limits

- **No screenshot was ever captured in this environment.** There is no render provider and
  no headless browser available here, so the pixel, region, and heatmap code paths were
  **logic-tested against synthetic pixel surfaces** — real pixel data, with only the
  *decoding* of PNG bytes unavailable. The arithmetic is verified; the decode is not
  exercised.
- **No image library is loaded here** (no GD, no Imagick), so `Image_Differ::is_available()`
  is false in this environment. That is why the surface-input seam exists: it is what
  allows the comparison arithmetic to be tested on exactly the host class where it is
  least likely to have been exercised.
- **No pixel-level accuracy claim has been measured.** Any statement about how close a
  replica is to its source has not been tested here and is not made.
