# Phase 8 — Completion Report

**Status: engines complete, integration not started.**
**Release recommendation: do not ship Phase 8 as a feature release.** A user installing
the plugin gets exactly Phase 7 behaviour. Nothing regresses; nothing new is reachable.

---

## 1. The one-paragraph summary

Phase 8 built six engines for the CSS semantics, asset safety, design-token, and
project-recordkeeping problems that limit reconstruction accuracy: a shared CSS value
parser, a layout relationship engine, a visual effects extractor, an SVG sanitizer, a
design token engine, and a project system. All six are complete, wired into the plugin
bootstrap, and covered by 648 new assertions — 1556 in total across 14 suites, 0
failures. Execution found and fixed **26 defects in the new code**, several of which
would have produced quietly wrong output rather than an error. The pipeline step that
invokes these engines from the Phase 2 analyzers and propagates the result to the
Elementor generator was not built, so none of the new capability reaches a user. The
project system is the one engine that is complete end to end, and it is complete
*except* for the screens and the pipeline that would populate it.

---

## 2. Numbers

| | Before Phase 8 | After Phase 8 |
| --- | --- | --- |
| PHP files | 111 | 122 |
| Classes loaded at boot | 99 | 105 |
| REST routes | 20 | 20 |
| Test suites | 9 | 14 |
| Assertions | 908 | **1556** |
| Failures | 0 | 0 |
| Skips | 2 | 2 |
| `php -l` failures | 0 | 0 |
| New defects found by execution | — | 26 |
| Translation calls | 921 | 921 |

Every Phase 1–7 suite still passes unchanged. No existing file was modified except the
bootstrap `require` list.

---

## 3. Implemented features

### 3.1 CSS value parser — `includes/css/`

One parser for every CSS value grammar, so four engines cannot disagree about what
`12pt` means. Top-level splitting that respects function nesting and quoting; explicit
separators per grammar; pixel conversion that returns `pixels => null` when conversion
needs a context it does not have; full colour support across six syntaxes; comparable
keys so `#FFF`, `#ffffff`, `white`, and `rgb(255,255,255)` are one value. `calc()` is
recognized and explicitly not evaluated.

### 3.2 Layout relationship engine — `includes/layout/`

CSS Grid including `auto-fit`/`auto-fill`/`minmax`/`fr`/named areas; Flexbox including
wrap, alignment, and per-child grow/shrink/basis; positioning including sticky, z-index,
transform, and signed margins; container boundary classification; child participation
with the three spellings of a grid span. Nine relationship types, each carrying a
confidence and its evidence, where the confidence is load-bearing — alignment in a
block container is 0.45 because it is a coincidence of widths, and anchoring without a
positioned ancestor is 0.6 with a limitation saying the containing block is the page.

### 3.3 Visual effects — `includes/visual/`

Linear, radial, and conic gradients with explicit and interpolated stops;
repeating and vendor-prefixed gradients recognized; box and text shadows including
layered and `calc()`-containing; borders with per-side and per-corner detail, the
one-to-four-value radius shorthand, elliptical radii, and pill recognition; backgrounds
classified as hero, section, card, decorative, or none, so a texture behind a card is
not imported as a content image.

Two decisions matter more than the parsing. **Overlay strength is read from the topmost
translucent layer**, so the common translucent-gradient-over-opaque-colour hero is
correctly reported as a contrast risk. **The CSS default border width is 3px** (`medium`),
not 1px, so a card that sets a colour and a style and nothing else is not a third
thickness.

### 3.4 SVG sanitizer — `includes/security/`

Allow-list sanitizer for a document format. Payload-bearing elements are removed with
their contents; wrapper elements are removed with their contents kept; unrecognized
elements have their drawing lifted rather than discarded; every `on*` attribute is
removed regardless of spelling; only same-document fragments are allowed in a
reference. Doctypes, oversized documents, over-complex documents, and unparsable
documents are refused outright. Every removal is reported. The output is verified
well-formed and stable under re-sanitization.

### 3.5 Design token engine — `includes/tokens/`

Seven families with per-family caps. **Every name is evidence-derived**, and a token
with no distinguishing evidence keeps an ordinal name and says so in its basis. A
colour named `text` records the measured contrast ratio that supports the name. A
translucent colour is not the same token as the opaque one. Typography roles prefer the
semantic element over a size comparison. Negative margins stay out of the spacing
scale.

### 3.6 Project system — `includes/projects/`

A project references drafts; it never contains them. Deleting a project leaves the page
alone by default, and even with an explicit request deletes a page only if it carries
the generation hash **and** is still a draft. Duplicate detection normalizes trailing
slashes, fragments, host case, and query order, while treating a different path, host,
scheme, or query value as a different page. Version numbers are monotonic across
trimming. Corrupted options recover rather than becoming sticky.

---

## 4. Architecture changes

Six new classes in four new directories, all loaded from the existing bootstrap. No new
service was registered and no existing service was modified, because none of the new
engines has a caller yet.

```
includes/
  css/       class-replicaforge-css-value-parser.php
  layout/    class-replicaforge-layout-engine.php
  visual/    class-replicaforge-visual-effects.php
  security/  class-replicaforge-svg-sanitizer.php
  tokens/    class-replicaforge-token-engine.php
  projects/  class-replicaforge-project-repository.php
```

**Design decisions worth recording:**

- **One CSS value parser, not six.** The engines all need the same value grammars.
  Separate parsers is how four slightly different answers to the same question end up
  in one document, which is a class of bug that is very hard to see and very visible
  on screen.
- **A total parser that reports non-understanding.** An unparsable value returns a
  `supported => false` result rather than a guess. A wrong parse that looks right is
  worse than an honest gap the caller can record as a limitation.
- **An approximation is data.** Every lossy mapping carries a `limitation` string. A
  caller surfaces it without inferring it from a missing field.
- **A claim carries evidence.** Relationship confidences are derived from the layout
  mode, token names from usage and measurement, defaults from an explicit
  `source: default`.
- **A sanitizer, not a parser.** The SVG sanitizer reduces to a known set of drawing
  elements and reports what it removed. It does not claim safety by construction; it
  reports so the claim can be audited.
- **A project is a record, not a container.** Bulky analysis data stays where Phase 2
  put it and the project points at it, which is what keeps 60 projects in one option
  row.

**Architectural decisions deliberately not made:** no new database tables (Phase 7's
decision stands); no service registration for an engine with no caller; no modification
to any Phase 1–7 service.

---

## 5. New schemas

**No database schema change.** `Schema::DB_SCHEMA_VERSION` remains `1.0.0` and no
migration was added. The plugin version is `0.7.0`, unchanged, because nothing user-
reachable was added.

One new *representation* version: the token set declares `version: '8.0'`, a dotted
string consistent with every other schema version in the plugin.

New stored options, both bounded, both recovering from corruption:

| Option | Contents | Bound |
| --- | --- | --- |
| `replicaforge_projects` | Array of projects | 60 projects, 20 versions each |
| `replicaforge_preferences` | User reconstruction preferences | Small key/value set |

---

## 6. Security changes

**One component, and it is a new one.** The SVG sanitizer is the only new security
surface, and it was the most heavily tested.

The reasoning: an SVG is a document that can carry script, event handlers,
`foreignObject`, external references, and a doctype with entity declarations, and a
browser renders it with the same privileges as the page. Importing one from a site
being analyzed is importing an attack the analyst did not write.

Controls: an element allow list; payload elements removed with their contents; wrapper
elements removed with their drawing kept; every `on*` attribute removed by prefix rather
than by name; only same-document fragments permitted in any reference; inline `style`
attributes removed; attribute values containing markup removed; comment and processing-
instruction nodes removed; text node content re-encoded; doctype and entity declarations
refused; size and element-count caps; a depth bound; refusal of anything unparsable;
`LIBXML_NONET` and no entity flags during parsing.

Verified by 112 assertions including a mechanical sweep of the output for surviving
`<script>`, `<foreignobject>`, `<iframe>`, `<embed>`, `<object>`, `<animate>`, `<set>`,
any `on*=` attribute, and any external `href`/`src`, plus an idempotence check.

**No existing security control was changed.** The SSRF policy, the URL validation, the
redactor, the capability checks, the argument validation (104 arguments, 0 unvalidated)
all carry forward untouched.

**Security rules held:**

| Rule | How it is held |
| --- | --- |
| Never execute source JavaScript | No execution path exists. No engine evaluates a script, ever |
| Never bypass SSRF protection | No new network path. The sanitizer's `safe_url` is *stricter* than SSRF validation — it allows only same-document fragments, because an SVG's references have no legitimate reason to leave the document |
| Never import unsafe files | The sanitizer is the import path, and it is not yet called |
| Never overwrite unrelated content | Enforced and tested: a page without the generation hash and a published page both survive an explicit delete request |
| Never expose secrets | No new option, log entry, or REST field carries one. The redactor is unchanged |
| Never silently lose user data | Version trimming drops history, never a draft; draft references are kept from every version, including trimmed ones; corrupted options recover |

**Known security limitation, unchanged from Phase 7:** the DNS rebinding window
between validation and fetch is documented, not closed, because pinning the resolved
address breaks TLS hostname verification.

---

## 7. Performance changes

Every new engine is bounded, and the bounds are per-engine so that a page large in one
dimension costs that dimension a number rather than the whole representation.

| Bound | Value | Purpose |
| --- | --- | --- |
| `Css_Value_Parser::MAX_VALUE_LENGTH` | 4096 | A hostile value cannot make the parser do unbounded work |
| `Css_Value_Parser::MAX_ITEMS` | 64 | A long list is truncated |
| `Css_Value_Parser::MAX_DEPTH` | 8 | Function nesting is bounded |
| `Layout_Engine::MAX_RELATIONS_PER_NODE` | 32 | Relationship list is bounded per node |
| `Svg_Sanitizer::MAX_BYTES` | 256KB | An oversized icon is refused, not parsed |
| `Svg_Sanitizer::MAX_ELEMENTS` | 2000 | An over-complex icon is refused |
| `Token_Engine::MAX_PER_FAMILY` | 100/100/100/40/40/40/20 | One pathological value per node cannot grow the token set |
| `Project_Repository::MAX_PROJECTS` | 60 | One option row stays a reasonable size |
| `Project_Repository::MAX_VERSIONS` | 20 | Per-project history is bounded |
| `Analysis_Limits::MAX_DOM_DEPTH` | (existing) | Reused for SVG recursion depth |

All are tested. A 300-node page, a 40 000-element SVG, a 200-deep nesting, and a
500-distinct-radius page are each exercised and each bounded.

**No performance claim is made about the integrated system**, because there is no
integrated system. The analysis cost of the new engines on a real page is unmeasured,
because they are not called on one.

---

## 8. Test results

```
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

suites: 14, failed: 0, assertions passed: 1556, skipped: 2
```

`php -l`: 122 files, 0 failures. Boot check: 105 types, 0 failures, 10 services, 20
routes.

The two skips are pre-existing Phase 5 and Phase 7 skips, unchanged.

**What testing found, and what that says about reading code.** Twenty-six defects were
found by executing the new code, several of which reading the code did not surface:

- A grid span computed as `end - start + 1` instead of `end - start`, making every
  spanning child a column too wide. The formula looks plausible and is wrong in a way
  that only shows on a real grid.
- The bare `gap` shorthand never read, so every grid and flex row lost its spacing.
- `background-image` as a longhand never read, so most backgrounds reported as absent.
- A loop variable named the same as the `$version` parameter, so a new version's
  analysis, design, draft, and change were all read from the previously stored version.
- A token's alpha re-parsed from a composite comparison key that is not a colour, so
  every translucent token reported fully opaque.
- `self::` written as `self->` in the sanitizer, a fatal on every SVG.
- camelCase allow-list keys checked against a lowercased lookup, removing every
  gradient, clip path, and mask from every icon.

None of these would have thrown. All of them would have made a reconstruction subtly
wrong, which is the failure mode most likely to reach a user and least likely to be
noticed.

---

## 9. Compatibility results

| Area | Result |
| --- | --- |
| WordPress 7.1.2 | Boots clean. 105 types, 0 failures |
| PHP 8.4.25 | `php -l` clean on 122 files. No deprecations at runtime |
| Elementor 4.3.2 | Untouched. No Elementor API is called by new code |
| Existing Elementor documents | Untouched. No existing post, meta, or document is written |
| Phase 1–7 suites | All 9 pass, unchanged |
| Database | Unchanged. No migration, no new table |
| REST API | Unchanged. 20 routes, 104 arguments, 0 unvalidated |
| Multisite | Structurally supported, still untested (carried from Phase 7) |
| Non-English locales | Untested (carried from Phase 7). No new translatable strings were added, so the 921 translation calls are unchanged |
| Browser rendering | **Untested.** No new code path is reachable from the browser |

---

## 10. Known limitations

### 10.1 The limitations that matter most

**The engines are not wired.** This is the defining limitation of Phase 8. A user
installing the plugin gets identical behaviour to Phase 7. Every engine described in
this report is finished, tested, and unreachable.

**No new capability is reachable from the UI or the REST API.** No admin screen reads a
project or a token set. No REST route exposes one. The 20 routes are unchanged from
Phase 7.

**The project system is complete but empty.** Creating, finding, listing, filtering,
updating, versioning, tracking drafts, deleting, duplicate detection, settings
validation, and preferences all work and are all tested. Nothing in the plugin calls
them, so a project is only created when a test or a direct call creates one. The
`mode`, `priority`, `asset_policy`, `visual_evidence`, `ai_enabled`, and `auto_correct`
settings are stored and consumed by nothing.

**The token set and the representation carry nothing.** A caller has to assemble the
observation array the token engine expects. No analyzer produces it.

### 10.2 Feature gaps, by brief section

Not implemented, with a decision record where one exists:

- §20–23 Image classification and responsive image analysis — no code, no record
- §24–28 Content, product, blog, navigation intelligence — no code, no record
- §29–33 Mobile navigation, interactions, scroll effects — [`INTERACTIONS.md`](INTERACTIONS.md)
- §41–43 Asset modes, deduplication, failure policy — [`ASSET-POLICY.md`](ASSET-POLICY.md)
- §37–40 Elementor global protection, theme compatibility — [`THEME-COMPATIBILITY.md`](THEME-COMPATIBILITY.md)
- §44–49 Reconstruction modes, priorities, structured AI context, AI confidence, evidence references, explainability — no code. Modes and priorities are *stored* and consumed by nothing
- §50–55 Advanced validation, comparison normalization, responsive validation — no code
- §56 Correction dependency ordering — no code
- §58 Rejected-correction memory — no code
- §60–68 Project UI, duplicate prompt UX, safe regeneration, change detection, incremental regeneration — [`PROJECTS.md`](PROJECTS.md) and [`SOURCE-CHANGE-DETECTION.md`](SOURCE-CHANGE-DETECTION.md)

The 20-website-type list in §3 is not separately implemented. It is a claim about what
the system can analyse, and the honest statement is that the analysis pipeline has not
been re-validated against a representative sample of those twenty categories. Doing so
is part of the integration work, and it belongs in a test rather than a document.

### 10.3 Carried forward from Phase 7, unchanged

- No browser session, so the generated draft has never been opened in the Elementor
  editor. The `_elementor_responsive` write is verified only by stored document and
  read-back hash. **Still the most important unticked box on the release checklist.**
- No live AI provider request. The prompt-injection boundary and the redactor are
  contract-tested; the provider round trip is not.
- No render provider exercised. Rendered visual comparison is unverified.
- WP-Cron is traffic-driven, so a queued job on a quiet site waits.
- Multisite structurally supported but untested.
- Non-English locales untested.
- DNS rebinding window documented, not closed.
- Three known long methods retained as deliberate: `render_page()` (332 lines),
  `enqueue_assets()` (284 lines), `register_correction_routes()` (193 lines).
- Ten "no control on a container" blocked entries in correction planning are noisy
  because Phase 5 pairs section-level source components with containers. Truthful output,
  Phase 5 design issue, deliberately untouched.
- All work is uncommitted. There is no Git repository.

### 10.4 New limitations introduced by Phase 8

- **A maintenance surface with no user value yet.** Six classes that must be kept
  correct and that currently do nothing for a user. This is a real cost and the reason
  the integration is the next job rather than an optional extra.
- **The `advanced` and `editable` reconstruction modes are stored but meaningless.**
  A user reading a project setting would reasonably expect it to change something. It
  does not.
- **The SVG sanitizer is a finished component with no caller.** It is correct and
  tested, and it is dead code until the asset pipeline invokes it.

---

## 11. Future recommendations

Ordered by what unblocks the most, not by what is most interesting.

### 11.1 Wire the engines into the analysis pipeline — the whole point

This is not one task. It is the task. The order matters, because each step's output is
the next step's input.

1. **Extend `Style_Analyzer` to expose declaration records per node in the shape the
   engines expect.** Everything downstream needs computed declarations; today
   `get_declarations()` exists but the observation array the token engine and the
   visual engine want does not. This is the smallest step and unblocks the rest.
2. **Call `Layout_Engine::analyze()` from `Layout_Analyzer`, storing its output beside
   the existing layout data.** Additive: the existing `page`/`sections` shape is kept so
   Phase 4 does not change. Verify the Phase 4 and Phase 5 suites still pass unchanged.
3. **Call `Visual_Effects` per node.** The background classification in particular
   prevents decorative textures becoming content images, which is a correctness win
   independent of fidelity.
4. **Assemble the observation array and call `Token_Engine::build()`.** Store the token
   set in the Phase 2 representation under a new key, and let the Phase 6 spec
   validator accept and validate it. **Add a validator for it**, or the token set enters
   the specification unvalidated, which is the mistake Phase 4 made with `elType`.
5. **Call `Project_Repository::create()` and `add_version()` from the analysis and
   generation job stages.** The job runner calls the same service methods the REST
   handlers call, so a project version is added in one place and both paths get it.
6. **Register the project and token REST routes** with the Phase 7 envelope, with
   `validate_callback` on every argument as the 104 existing arguments do.
7. **Add a project screen** to the existing admin menu, reading `recent()`.

Each step ends with all 14 suites passing. A step that cannot achieve that is not
finished.

### 11.2 Then, in value order

- **Wire `Svg_Sanitizer` into the asset path** the moment assets are imported. It is
  finished and the moment it becomes reachable is the moment it matters.
- **Re-validate the analysis against a representative sample of the twenty website
  types** in §3, and put the sample in the test suite rather than in a document. The
  claim is currently unsupported.
- **Image classification and responsive image analysis** (§20–23), which is the largest
  remaining gap in reconstruction accuracy.
- **Interaction detection** per [`INTERACTIONS.md`](INTERACTIONS.md), with the
  never-execute boundary preserved absolutely.
- **Source change detection and incremental regeneration** per
  [`SOURCE-CHANGE-DETECTION.md`](SOURCE-CHANGE-DETECTION.md). The project storage is
  ready; the stable section identity is the hard problem and should be solved before
  anything else in that document.
- **Theme compatibility and Elementor global protection** per
  [`THEME-COMPATIBILITY.md`](THEME-COMPATIBILITY.md). Writing to globals should stay
  forbidden; the prefixed-kit opt-in is the acceptable form.
- **Advanced validation and correction dependency ordering** (§50, §56), which depends
  on the new evidence existing first.

### 11.3 Do not do

- **Do not lower the SVG allow list** to make an icon import. A missing path is
  cosmetic; a handler is a compromise.
- **Do not wire the engines in one step.** Six data-shape changes through the Phase 2
  representation at once would be very hard to attribute a regression to.
- **Do not implement Phase 9.** Phase 8 is not integrated.

---

## 12. Documentation delivered

| File | Status |
| --- | --- |
| `docs/PHASE-8-ADVANCED-RECONSTRUCTION.md` | New. Full description of the six engines, the 26 defects, and the gaps |
| `docs/PROJECTS.md` | New. The project system, in full, with the not-wired boundary stated |
| `docs/ASSET-POLICY.md` | New. **Decision record, not implemented.** SVG sanitizer section is implemented |
| `docs/INTERACTIONS.md` | New. **Decision record, not implemented.** The never-execute boundary is current |
| `docs/THEME-COMPATIBILITY.md` | New. **Decision record, not implemented** |
| `docs/SOURCE-CHANGE-DETECTION.md` | New. **Decision record, not implemented** |
| `docs/PHASE-8-COMPLETION-REPORT.md` | This document |
| `README.md` | Updated |

Five of the seven new documents describe decisions rather than code, and each says so
in its first line. A design note that reads like a manual is worse than no document,
because it produces exactly the false confidence this report exists to prevent.

---

## 13. Final acceptance criteria

Against §73, honestly:

| Criterion | Status |
| --- | --- |
| [ ] Complex nested layouts are detected | **Engine built and tested. Not wired.** |
| [ ] Flexbox is understood | **Engine built and tested. Not wired.** |
| [ ] CSS Grid is understood | **Engine built and tested. Not wired.** |
| [ ] Backgrounds are understood | **Engine built and tested. Not wired.** |
| [ ] Gradients are supported | **Engine built and tested. Not wired.** |
| [ ] Shadows are supported | **Engine built and tested. Not wired.** |
| [ ] Typography roles are detected | **Token engine built and tested. Not wired.** |
| [ ] Images are intelligently classified | **Not implemented.** |
| [ ] Responsive images are handled | **Not implemented.** |
| [ ] Navigation hierarchy is detected | **Not implemented.** |
| [ ] Common interactions are recognized | **Not implemented.** Decision record only |
| [ ] Source JavaScript is never executed | **Held.** No execution path exists |
| [ ] Product structures are detected | **Not implemented.** |
| [ ] Blog structures are detected | **Not implemented.** |
| [ ] Design tokens are generated | **Engine built and tested. Not wired.** |
| [ ] Elementor mapping uses improved structure | **Not implemented.** Requires the engines to be wired first |
| [ ] Existing Elementor globals are protected | **Held by not touching them.** No detection built. Decision record only |
| [ ] Theme conflicts are detected | **Not implemented.** Decision record only |
| [ ] Advanced validation works | **Not implemented.** |
| [ ] Advanced correction works | **Not implemented.** |
| [ ] Project system works | **Repository built and tested. No pipeline populates it and no screen displays it.** |
| [ ] Generation versions work | **Storage built and tested. Nothing generates a version in production.** |
| [ ] Source changes can be detected | **Not implemented.** Decision record only |
| [ ] Incremental regeneration works | **Not implemented.** Decision record only |
| [x] Security tests pass | 112 SVG assertions, plus 132 existing. 0 failures |
| [x] Performance limits work | Every bound tested. 648 new assertions |
| [x] Documentation is complete | 7 documents, five explicitly marked as decision records |
| [x] Phase 1–7 regression tests pass | 9 suites, 908 assertions, 0 failures, unchanged |

**4 of 27 criteria met.** The three marked with a partial note have their engine built
and verified; the engine exists and is unreachable, which is not the same as the
criterion being met.

Phase 8 is not complete. It is a well-tested foundation for the integration that would
make it complete, delivered with an accurate account of where the line falls.
