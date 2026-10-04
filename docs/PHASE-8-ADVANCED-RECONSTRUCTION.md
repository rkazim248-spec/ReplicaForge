# Phase 8 — Advanced Reconstruction

This document describes what Phase 8 built, how each piece works, and — just as
importantly — what it did not reach. The six "not implemented" documents in this
directory say so at the top of their first line, and this one does the same for the
parts of the brief that are not code.

## Read this first: the honest state of Phase 8

Phase 8 produced **six new engines, all tested, all correct, and none of them wired
into the analysis pipeline.**

That is the single most important sentence in this document. Every engine described
below is finished and verified. What does not exist is the step that calls them from
the Phase 2 analyzers, feeds their output into the representation, and propagates the
result to the Elementor generator. A user installing the plugin today gets
**identical behaviour to Phase 7**. Nothing regresses, and nothing new is reachable
from the UI or the REST API.

This was a scope decision, not an accident. Each engine is a substantial piece of CSS
semantics — grid and flexbox and positioning, then gradients and shadows and borders
and backgrounds, then an XML sanitizer, then a token system, then a versioned record —
and each one, when tested, found real bugs in itself. Shipping six tested engines
honestly is better than shipping six half-wired ones that produce a subtly wrong
document. The wiring is the next phase's first job.

## What was built

| Engine | File | Lines | Tests |
| --- | --- | --- | --- |
| CSS value parser | `includes/css/class-replicaforge-css-value-parser.php` | — | 95 assertions |
| Layout relationship engine | `includes/layout/class-replicaforge-layout-engine.php` | — | 157 assertions |
| Visual effects | `includes/visual/class-replicaforge-visual-effects.php` | — | 149 assertions |
| SVG sanitizer | `includes/security/class-replicaforge-svg-sanitizer.php` | — | 112 assertions |
| Design token engine | `includes/tokens/class-replicaforge-token-engine.php` | — | (same suite) |
| Project repository | `includes/projects/class-replicaforge-project-repository.php` | — | 135 assertions |

**Total: 1556 assertions across 14 suites, 0 failures, 2 skips.** Up from 908 across
9. Every Phase 1–7 suite still passes.

---

## 1. The CSS value parser

`includes/css/class-replicaforge-css-value-parser.php`

Everything in Phase 8 that reads a CSS value reads it through this one class. That is
not tidiness — it is the point. The layout engine, the gradient reader, the shadow
reader, and the border reader all need to answer the same questions about the same
text, and four parsers answering "what is 12pt" four different ways is how a
reconstruction ends up internally inconsistent in a way nobody can point at.

The design rule: **every parser is total, and an unparsable value returns
`supported => false` rather than a guess.** A wrong parse that looks right is worse
than an honest "not understood" the caller can record as a limitation.

### What it provides

- `split()` — splits on any single separator character, ignoring separators inside
  functions and quoted strings. `rgba(0,0,0,0.5)` is one token; `10px / 20px` is two.
- `function_args()` — with an **explicit** separator parameter. This is deliberate:
  `linear-gradient()` separates stops with commas and `translate()` with spaces, and
  `linear-gradient(90deg, #fff, #000)` splits into the same number of items either
  way, so inferring the separator from the item count cannot work.
- `first_function()` — separates a function from the bare keywords around it, for
  `background: linear-gradient(…) no-repeat center / cover`.
- `length()` — converts to pixels, with a **relative** result for `%`, `em`, `vw` and a
  `calc` result that is explicitly *not* evaluated. It returns `pixels => null` when
  conversion needs a context that is not available, rather than fabricating a number.
- `color()` — hex (3, 4, 6, 8 digits), `rgb()`, `rgba()`, `hsl()`, `hsla()`, `currentColor`,
  `transparent`, and 140 named colors. Returns hex, rgb, and hsl together.
- `color_key()` / `length_key()` — comparable keys, so `#FFF`, `#ffffff`, `white`, and
  `rgb(255,255,255)` collapse to one value. This is what makes token detection work.
- `parse_angle()` — degrees, gradians, radians, turns, and the four direction keywords.
- 140 named colors, deliberately: an unknown name returns null rather than a guess.

### Bounds

`MAX_VALUE_LENGTH` 4096, `MAX_ITEMS` 64, `MAX_DEPTH` 8. A hostile stylesheet cannot
make the parser do unbounded work, and a truncated value will not match any grammar, so
the result is an honest "not understood".

---

## 2. The layout relationship engine

`includes/layout/class-replicaforge-layout-engine.php`

Phase 2's `Layout_Analyzer` reads a display type and a column count. That is enough to
say "this is a three-column row" and not enough to reproduce one. This engine reads the
whole layout grammar and reports what it found, the closest Elementor equivalent, and
**the cost of the approximation**.

### What it reads

**CSS Grid** — `grid-template-columns` and `-rows`, `repeat(n, …)` and
`repeat(auto-fit|auto-fill, …)`, `minmax()`, `fr`/length/auto tracks, `grid-gap` in
all three spellings, `grid-template-areas`, `column_count`, `row_count`, `auto_flow`.

**Flexbox** — `flex-direction`, `flex-wrap`, `gap`, `justify-content`, `align-items`,
`align-content`, `align-self`, `order`, and a child's `flex-grow` / `flex-shrink` /
`flex-basis`.

**Placement** — `position` including `sticky` with its insets, `inset`, `z-index`,
`transform`, `float`, `clear`, and margins with **their signs preserved** (a negative
bottom margin is the evidence for an overlap and must survive).

**Box** — width, max-width, min-width, height, `box-sizing`, auto margins, and the
classification into `full_width` / `boxed` / `centered` / `fixed` / `auto`.

**Child participation** — `grid-column` / `grid-row` as a range, as `span n`, and as a
longhand, plus the flex grow/shrink/basis.

### Relationships

`relate()` derives the relations that survive into an editable document:

`contains` · `sibling` · `aligned_with` · `stacked_with` · `overlaps` · `anchored_to` ·
`centered_in` · `constrained_by` · `full_width`

Every relationship carries a **confidence** and its **evidence**, because a claim
without them is an assertion. The confidence is load-bearing rather than decorative:

- `aligned_with` is **0.9** in a grid and **0.85** in a flex row, but **0.45** in a
  plain block — where alignment is a coincidence of widths, not a decision.
- `anchored_to` is **0.95** when a positioned ancestor exists and **0.6** when one does
  not, and the 0.6 case carries a limitation explaining that the source containing
  block is the page.
- `overlaps` is **0.8** with two independent reasons and **0.5** with one.

### Honest approximations

Every place the source is not exactly reproducible says so, in data:

- A responsive `repeat(auto-fit, …)` has no fixed column count, so a fixed-column
  container is the closest equivalent and the limitation says the source will reflow
  where the replica will not.
- A grid of more than six columns is clamped to six, and the limitation states the
  count that was lost.
- A wrapping flex row breaks at one width in Elementor rather than continuously, and
  says so.
- A pixel `max-width` becomes the nearest percentage of the viewport, because
  Elementor states a content width as a percentage. **A percentage max-width is
  already the unit Elementor uses, so it is an exact translation, not an
  approximation** — the two are distinguished, and tested.
- An absent `align-items` is reported as `source: default` with a limitation naming the
  CSS default of `stretch`, rather than presented as an observation.

---

## 3. Visual effects

`includes/visual/class-replicaforge-visual-effects.php`

Gradients, shadows, borders, and backgrounds, grouped because they share a failure
mode: a shorthand hides several declarations, a page may use any of three spellings for
the same effect, and the interesting part is usually in the longhand.

### Gradients

Linear, radial, conic. Angle or direction keyword. Explicit and interpolated stop
positions — an interpolated position is labelled `position_source: interpolated` so a
reader knows it was derived. `transparent` is a color, not an absence, so a
transparent-to-opaque gradient is a gradient.

**Conic is recognized and reported unsupported**, with a limitation explaining that it
sweeps by angle from a center and is reproduced as a flat color from its first stop.
**Repeating gradients are recognized too**, with the tiling difference recorded.

### Shadows

Box and text, with multiple layers. Lengths, color, and `inset` are classified by kind
rather than assigned by position. A shadow containing `calc()` is reported as *present
but not complete*, with a limitation saying the calculation was not evaluated.

Elementor applies one shadow per control, so a layered shadow uses the outermost and
the loss is reported with the count.

### Borders

Per-side and uniform, with per-corner radius including the one-to-four-value shorthand
and the elliptical `10px / 20px` form. A pill is recognized as a shape, because a pill
written as `9999px` and a pill written as `50%` look identical and are not the same
declaration.

**The CSS default width is `medium` = 3px**, not 1px, and applies whether or not a
style is declared. Getting this wrong made every card outline a third of its real
thickness.

### Backgrounds — and the decision that matters

The important part is not the parsing. It is **not confusing a decorative background
with a content image**. A page uses `background-image` for the visible art of a hero
*and* for a texture behind a card. Treating the second as a content image would import
a file that is not content into a widget with no background for it.

So backgrounds are classified: `hero` · `section` · `card` · `decorative` · `none`,
using the element's role, whether it has text, and its area. A small background with no
text is decorative.

**Overlay strength is read from the topmost translucent layer, not from the colour
beneath it.** A hero written as a translucent gradient over an opaque dark colour is
the most common pattern there is, and reading the colour reported a fully opaque
background — so the contrast risk the gradient creates was never reported. A
translucent background behind text is now surfaced as `low_contrast_risk`, because it
is the most common reason a reconstruction is unreadable.

---

## 4. The SVG sanitizer

`includes/security/class-replicaforge-svg-sanitizer.php`

Security-critical, and the most heavily tested new component at 112 assertions.

An SVG is not an image format. It is a document that can carry script, event handlers,
external references, and embedded HTML, and a browser renders it with the same
privileges as the page it sits on. Importing one from a site being analyzed would be
importing an attack the analyst did not write.

**The approach is an allow list.** It keeps a fixed set of drawing elements and removes
everything it does not positively recognize, because an icon that loses a decorative
path is cosmetic while an icon that keeps a handler is a compromise.

Two distinctions the tests forced, both of which were wrong in the first implementation:

- **Payload elements vs. wrapper elements.** The contents of a `<script>` or a
  `<style>` *are* the payload, so removing the element must take them with it.
  Otherwise the code survives as loose text. But the contents of a `<a>` are ordinary
  drawing, and removing the link with them turns a usable icon into nothing. The two
  are now separate declarations.
- **An unrecognized wrapper's children are lifted, not discarded.** A `<filter>` or a
  vendor element wraps drawing that is still valid, so the children are cleaned on
  their own merits and wrapped in a group.

Every `on*` attribute is removed **regardless of spelling**, because the check is on
the prefix rather than on a list of names. Only same-document fragments are allowed in
a reference; a relative path, an absolute URL, a protocol-relative URL, a `data:` URI,
and a `javascript:` URI are all removed.

Refused outright: a doctype or entity declaration, anything over 256KB, anything over
2000 elements, and **anything that does not parse** — because repairing malformed
markup by guessing produces a document that is not what was written.

The result is verified to be stable: sanitizing the output again changes nothing, and
a mechanical sweep asserts that no `<script>`, `<foreignobject>`, `<iframe>`,
`<embed>`, `<object>`, `<animate>`, or `<set>` survives, no `on*=` attribute survives,
and no external `href`/`src` survives.

---

## 5. The design token engine

`includes/tokens/class-replicaforge-token-engine.php`

Seven families: `colors` · `typography` · `spacing` · `radius` · `shadows` ·
`containers` · `breakpoints`.

The design rule is the brief's §35 — *"do not invent token names without evidence"* —
made mechanical. **Every name is derived from something observable**, and a token with
no evidence-based name keeps an ordinal name that says nothing the evidence cannot
support:

- A colour used by the largest text on the page is `display`.
- A colour used on text against a contrasting page background is `text` — and the basis
  records the **measured contrast ratio**, because the name then rests on a measurement
  rather than on the usage alone.
- A colour the caller reported as the page background is `background`. Without that
  fact, the honest name is `surface`, because a section background is not an assertion
  about the page.
- A colour used only on a border is `border`.
- A colour with no distinguishing usage is `color_a1b2c3`, with
  `name_basis: "no semantic name is claimed"`. The token set reports how many names are
  evidence-based, so a reader can tell a design system from a de-duplication.

Typography roles are named from the **semantic element** where one exists, because a
page may style its own heading to look like body text, and a size comparison is weaker
evidence than the element. Only where there is no element role does size decide:
`display`, `heading`, `body`, `small`.

A translucent colour is **not** the same token as the opaque one — otherwise a 50%
overlay collapses into the black behind it, which is a real visual difference.

Shadows are named by blur (`shadow_tight` / `shadow_raised` / `shadow_floating`),
because blur is what a designer means by depth. A negative margin stays out of the
spacing scale, because it is a layout technique rather than a scale value. Every family
is capped.

---

## 6. The project system

`includes/projects/class-replicaforge-project-repository.php`

Fully documented in [`PROJECTS.md`](PROJECTS.md). The two behaviours worth repeating
here:

- **A project never contains a draft; it references one.** Deleting a project removes
  the record and leaves the Elementor page alone, by default and — even when drafts are
  explicitly requested — unless the page carries the generation hash *and* is still a
  draft. Both refusals are tested.
- **Version numbers are monotonic, not positional.** They come from the highest version
  retained, not from how many are retained. Counting would hand the same number to two
  versions once the cap trims the list, and make a stored reference ambiguous. This was
  a real bug the test found.

---

## Bugs the tests found

Execution found more than reading did, in the same way it did in Phase 7. Every one of
these was in code written for Phase 8, and every one would have produced a subtly wrong
reconstruction rather than an error:

| # | Defect | Effect if shipped |
| --- | --- | --- |
| 1 | `pt` and `pc` used the root font size as their multiplier | 12pt read as 2.67px instead of 16px — every value in points at a sixth of its size |
| 2 | `rgb()` percentage channels stripped the `%` before classifying | `rgb(100%,0%,0%)` read as 100/255 instead of 255 — every percentage colour far too dark |
| 3 | `rgb(none,none,none)` tested for `none` *after* stripping the letters | The check compared against an empty string and could never match |
| 4 | `flex_alignment()` ignored its argument and always returned `flex-start` | Every flex container claimed top alignment regardless of the source |
| 5 | The bare `gap` shorthand was never read | Every grid and flex row lost its spacing |
| 6 | `box` could not recognise `width: 100%` | Every full-bleed row looked like an auto-width block |
| 7 | `grid-column-column-start` — the axis was appended twice | A `1 / 3` placement read as no placement at all |
| 8 | Grid span was `end - start + 1` | Every spanning child a column too wide |
| 9 | The gradient separator was inferred from the item count | `linear-gradient(90deg, #fff, #000)` returned the angle as a stop |
| 10 | `position_source` set only on interpolated stops | A declared position and an absent one were indistinguishable |
| 11 | The bare `border` shorthand was never consulted | `border: 1px solid #ccc` reported no border at all |
| 12 | `shorthand_border` returned a hex string where a parsed colour was expected | A fatal on every bordered element |
| 13 | The `border-radius` shorthand was not expanded | No radius on any page that used the common form |
| 14 | Bare `border-style`/`width`/`colour` longhands not read | A border with no width appeared to draw nothing |
| 15 | Default border width taken as 1px | Should be 3px (`medium`) — every card outline a third thickness |
| 16 | `split()` handled only comma and space | A slash returned one token where two were expected |
| 17 | `background-image` longhand never read | Most backgrounds were reported as absent |
| 18 | Background layers not classified per part | A gradient beneath an image was lost |
| 19 | Overlay read the colour beneath the gradient | A translucent hero over an opaque colour reported no contrast risk |
| 20 | `self::` written as `self->` in the SVG sanitizer | A fatal on every SVG |
| 21 | The document was passed instead of its root element | Every SVG rejected, however clean |
| 22 | Rejected elements discarded safe children | A link-wrapped icon became nothing |
| 23 | camelCase allow-list keys against a lowercased lookup | Every gradient, clip path, and mask removed as unrecognized |
| 24 | Token alpha re-parsed from a composite key | Every translucent token reported fully opaque |
| 25 | Version number from the list count | Two versions with the same number once the cap trimmed |
| 26 | Loop variable shadowed the `$version` parameter | A new version's analysis, design, draft, and change were read from the previously stored version |

Twenty-six defects, in six new files, before any of them was connected to anything.
That is the strongest argument in this document for why the wiring is worth doing
carefully rather than quickly.

---

## What is not built

Named here so nobody has to infer it from an absence.

**Not implemented at all** — each has a decision record, none has code:

| Brief | Document |
| --- | --- |
| §20–23 Image and responsive-image intelligence | — |
| §24–28 Content, product, blog, navigation intelligence | — |
| §29–33 Mobile navigation, interactions, sticky, scroll effects | [`INTERACTIONS.md`](INTERACTIONS.md) |
| §41–43 Asset modes, deduplication, failure policy | [`ASSET-POLICY.md`](ASSET-POLICY.md) |
| §37–40 Elementor global protection, theme compatibility, header/footer strategy | [`THEME-COMPATIBILITY.md`](THEME-COMPATIBILITY.md) |
| §44–49 Reconstruction modes, priorities, structured AI context, confidence, evidence references, explainability | — (modes and priorities are *stored* in project settings and consumed by nothing) |
| §50–55 Advanced validation, comparison normalization, responsive validation | — |
| §56 Correction dependency ordering | — |
| §58 Rejected-correction memory | — |
| §60–68 Project UI, duplicate prompt UX, versioning UI, safe regeneration, change detection, incremental regeneration | [`PROJECTS.md`](PROJECTS.md) (the storage is built; the UI and the pipeline are not) |

**Built but not wired** — the six engines above. The step that calls them from the
Phase 2 analyzers, feeds their output into the representation, and propagates the
result to the Elementor generator does not exist.

---

## The architectural rules Phase 8 kept

These are the constraints from the brief, restated as the specific decisions Phase 8
made about them. Every one is enforced by a test rather than by intention.

- **Source JavaScript is never executed.** Not for interaction detection, not for
  layout measurement, not to resolve a computed value. A wrong reading costs accuracy;
  executing code from an analyzed site costs the install.
- **Nothing is invented to fill a gap.** A percentage is not converted to a pixel.
  A `calc()` is not evaluated. A conic gradient is reported unsupported rather than
  approximated. An unknown color name is not guessed. A token with no evidence gets an
  ordinal name, not a semantic one. Every one of these is a test.
- **An approximation is data, not a comment.** Every lossy mapping carries a
  `limitation` string that says what was lost. A caller can therefore surface it
  without inferring it from a missing field.
- **A claim carries its evidence.** Every token name has a `name_basis`, every
  relationship has a `confidence` and an `evidence` list, every default has a
  `source: default` marker.
- **Removals are reported.** The SVG sanitizer returns everything it removed, so an
  icon that arrived clean is distinguishable from one that arrived hostile.
- **Resource bounds are per-family, not global.** `Analysis_Limits` for the DOM and CSS,
  `MAX_ELEMENTS` and `MAX_BYTES` for SVG, `MAX_PER_FAMILY` for tokens, `MAX_PROJECTS`
  and `MAX_VERSIONS` for projects. A page engineered to make any one of them large
  costs that one a number, not the whole representation.

## Related

- [`PROJECTS.md`](PROJECTS.md) — the project system in full.
- [`ASSET-POLICY.md`](ASSET-POLICY.md) — asset modes and the SVG sanitizer.
- [`INTERACTIONS.md`](INTERACTIONS.md) — the never-execute boundary.
- [`THEME-COMPATIBILITY.md`](THEME-COMPATIBILITY.md) — global style protection.
- [`SOURCE-CHANGE-DETECTION.md`](SOURCE-CHANGE-DETECTION.md) — regeneration safety.
- [`../ARCHITECTURE.md`](../ARCHITECTURE.md) — the Phase 1–7 architecture this extends.
- [`PHASE-8-COMPLETION-REPORT.md`](PHASE-8-COMPLETION-REPORT.md) — the full report.
