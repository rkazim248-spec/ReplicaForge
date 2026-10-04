# Phase 13 Completion Report — Advanced Visual Intelligence

**Scope:** rendering abstraction, visual analysis pipeline, visual design representation,
multi-signal comparison, bounded correction with cross-viewport regression protection, and
provider-aware AI vision.

**Status:** implemented and tested. Rendering is built, detected, and degrades honestly.
**No screenshot was captured in this environment and no pixel-accuracy claim is made.**

---

## 1. Files created

All under `includes/visual/`, which already contained Phase 8's `Visual_Effects`:

| File | Lines | Purpose |
| --- | --- | --- |
| `interface-replicaforge-renderer.php` | 101 | `Renderer_Contract` — the seam that keeps one browser technology from becoming permanent |
| `interface-replicaforge-image-reader.php` | 68 | `Image_Reader_Contract` — the same argument for the pixel layer |
| `class-replicaforge-visual-limits.php` | 433 | §1 vocabulary, relationships, bounds, `13.0` schema |
| `class-replicaforge-image-readers.php` | 379 | `Image_Readers`, `Imagick_Image_Reader`, `Gd_Image_Reader`, `Synthetic_Image_Reader`, `Null_Image_Reader` |
| `class-replicaforge-renderer-manager.php` | 383 | `Renderer_Manager`, `Endpoint_Renderer` (adapts Phase 5's `Visual_Renderer`) |
| `class-replicaforge-visual-analyzer.php` | 690 | DOM↔visual mapping, boxes, relationships, overlap, containers, grids, section boundaries |
| `class-replicaforge-visual-features.php` | 856 | colours, gradients, shadows, borders, radii, images, typography, buttons, cards, icons, density, whitespace |
| `class-replicaforge-dynamic-detector.php` | 580 | dynamic content, transient UI, animations, carousels, video, sticky, fixed, mobile nav, responsive |
| `class-replicaforge-visual-representation.php` | 494 | the §49 `13.0` schema, evidence and conflict rules, validation |
| `class-replicaforge-visual-comparator.php` | 846 | six signals, masking, cross-check, regions, heatmap, overlays |
| `class-replicaforge-render-cache.php` | 448 | `Render_Cache`, `Viewport_Manager` |
| `class-replicaforge-visual-corrector.php` | 496 | §67 priority, §68 regression, bounded loop, before/after |
| `class-replicaforge-visual-ai.php` | 543 | five vision gates, budget integration, estimation |
| `class-replicaforge-render-job.php` | 375 | Phase 11 render job payload, §71 dashboard |
| `class-replicaforge-visual-api.php` | 866 | REST controller |
| `tests/phase13-visual-test.php` | 1,300 | 20 sections, **573 assertions** |
| `docs/VISUAL-INTELLIGENCE.md` | — | design rationale for every judgement call |
| `docs/PHASE-13-COMPLETION-REPORT.md` | — | this file |

## 2. Files modified

| File | Change |
| --- | --- |
| `replicaforge.php` | 15 `require_once` lines; version `1.0.0` → `1.1.0` |
| `includes/class-replicaforge-plugin.php` | `$visual_api`, `visual_api()`, route registration on `rest_api_init` |
| `includes/class-replicaforge-schema.php` | `DB_SCHEMA_VERSION` `12.0.0` → `13.0.0` |
| `includes/class-replicaforge-migrator.php` | `12.0.0 → 13.0.0` declaration and `migrate_visual()` |
| `includes/validation/class-replicaforge-validation-limits.php` | 5 categories **added**, 2 metric groups extended |
| `includes/validation/class-replicaforge-visual-renderer.php` | additive `stabilize` payload forwarding |
| `includes/multipage/class-replicaforge-component-registry.php` | **bug fix**, see §20 |
| `tests/phase12-multipage-test.php` | one assertion strengthened, 3 added |
| `docs/ARCHITECTURE.md`, `JOBS.md`, `SECURITY.md`, `DATABASE.md`, `PERFORMANCE.md` | Phase 13 sections appended |

## 3. Existing systems reused

**No second screenshot system, no second queue, no second validation engine, no second AI
provider, no new table.**

| Reused | How |
| --- | --- |
| `Visual_Renderer` (Phase 5) | `Endpoint_Renderer` **wraps** it. One capture path, one SSRF boundary. |
| `Image_Differ` (Phase 5) | the cross-check, not the primary walk |
| `Image_Differ` region detection | regions extended, not reimplemented |
| `Validation_Limits` | viewports, severities, pixel bands, tolerances, categories, metric groups |
| `Visual_Effects` (Phase 8) | gradient, shadow, border parsing — **not** reimplemented |
| `Job_Limits`, `Job_Checkpoint` | render job stages reuse existing slots |
| `Ai_Capabilities` | `supports( 'vision', … )` — capability detection, not guessing |
| `Ai_Context_Budget` | screenshot bytes counted against the same limit as text |
| `Entitlement_Manager`, `Project_Access` | gates and ownership |
| `Url_Validator` | every target URL, before any render request |
| `Logger`, `Security::log_event` | observability, refused renders |
| `Component_Registry` | shared-component scope and user-override protection |
| `Token_Engine` (Phase 8) | untouched; Phase 13 reads colour data, does not re-derive it |

## 4. Rendering architecture

```
Visual_Api
    → Renderer_Manager
        → capabilities()   §4 detection, every key a boolean
        → capture()        Url_Validator → provider → DPR normalisation
            → Endpoint_Renderer (adapter)
                → Visual_Renderer (Phase 5, unchanged apart from stabilize)
                    → operator's render endpoint
```

**Degradation** returns `success: false, degraded: true` with a stated fallback — never a
synthesised estimate. `Renderer_Manager::warnings()` is a first-class output naming both
the missing renderer and the missing image library, because they have different
consequences.

## 5. Visual analysis architecture

`Visual_Analyzer::analyze( representation, render, viewport )` → geometry, relationships,
overlaps, section boundaries, measurement conflicts, limitations. Works with **no render
at all**, producing a CSS-derived model with a confidence cap of 0.6.

`Visual_Features::extract()` walks sections, top-level components, **and components nested
inside sections** — the third group is where images, buttons, and headings actually live,
and omitting it analysed almost nothing while looking like it had analysed the page.

`Dynamic_Detector::detect()` runs first, producing two separate verdicts per element:
excluded from reconstruction, and masked from comparison.

## 6. Visual representation schema — `13.0`

Sections: `schema_version`, `analyzer_version`, `viewport`, `sections`, `components`,
`geometry` (boxes, containers, grids, overlaps), `visual_relationships`, `colors`,
`typography`, `backgrounds`, `images`, `gradients`, `shadows`, `borders`, `radius`,
`buttons`, `cards`, `icons`, `density`, `whitespace`, `responsive`, `dynamic_elements`,
`excluded_from_build`, `masked_from_compare`, `sticky`, `fixed`, `animations`, `carousel`,
`video`, `mobile_navigation`, `evidence`, `measurement_conflicts`, `confidence`,
`limitations`, `warnings`, `built_at`, `elementor_independent`.

**Versioning is separate from `2.0` (page) and `12.0` (website)** because the three answer
different questions and change for different reasons; reusing one number would make a cache
key ambiguous.

## 7. DOM-to-visual mapping

`DOM component ↕ visual region ↕ design representation component ↕ reconstruction
component ↕ Elementor element`, keyed by the representation's own ids, with rendered boxes
keyed by the same ids. A rendered box that also has a derived counterpart keeps the derived
one as `dom_estimate` with its source recorded.

## 8. Screenshot system

- Captured only by Phase 5's `Visual_Renderer`, at an operator-configured endpoint.
- Served by opaque key; **no filesystem path in or out** (asserted by source scan).
- Cached with a key containing source hash, viewport, DPR, renderer version, analyzer
  version, and normalisation.
- Retention classes `temporary` / `project` / `snapshot` / `deleted`, default 7 days.
- **No screenshot was captured in this environment.**

## 9. Renderer compatibility

Any provider implementing `Renderer_Contract`: Playwright, Puppeteer, Browserless, cloud
browser, or local Chromium. `javascript` is reported **false** for the in-process
implementation and the reason is in the code. The image layer is a separate interface with
Imagick, GD, synthetic, and null implementations.

## 10. AI vision integration

Five gates (user setting, provider capability via `Ai_Capabilities`, context budget, size,
content). Off by default. Fallback is a **structured representation**, not a message.
`estimate()` reports `unit: 'image'` with an explicit "not a billing amount" disclosure.

## 11. Visual comparison improvements

Six independent signals with declared weights; masking applied **before** any ratio;
two-sided structure; palette **overlap** (so a simpler replica is distinguishable from a
mismatched one); per-viewport regression band of ±0.01; `signal_weight` reported so a
two-signal score is not mistaken for a six-signal one; Phase 5 cross-check when unmasked.

## 12. Correction integration

`Visual_Corrector` plans, refuses, and iterates. It does **not** write — the caller
applies. Structure-first ordering, shared-component scope preferred, three refusal rules,
and a loop that clamps and stops on the first non-improving iteration.

## 13. Multi-page integration

`GET /websites/{id}/visual/consistency` compares a shared component across pages by a
signature that **excludes geometry** — two pages are different lengths, so a hero's
y-position legitimately differs and including it would report every shared component as
inconsistent. A page with no representation is `not_analysed`, never assumed consistent.

## 14. Cache changes

`Render_Cache` — 120 entries max, index + body, pruned on read and on write, project id
recorded in the index for the access check, dangling index entries removed on read.
New options: `replicaforge_visual_ai`, `replicaforge_visual_cache_index`,
`replicaforge_visual_entry_{key}`, `replicaforge_visual_repr_{hash}`,
`replicaforge_visual_reports_{hash}`.

## 15. Storage changes

**No new database table.** Four new option families above, all bounded.

## 16. REST endpoints — 15 paths, 32 methods, all owner-gated

`GET|POST /visual/capabilities`, `/visual/viewports`, `/visual/settings`, `/visual/cache`,
`/visual/cache/prune`; `GET /websites/{id}/visual/{capabilities,representation,dynamic,dashboard,consistency}`;
`POST /websites/{id}/visual/{analyze,plan,compare,corrections}`; `GET /visual/captures/{id}`.
Verified: **0 ungated routes.**

## 17. Hooks and filters

`replicaforge_visual_dpr`, `replicaforge_visual_viewports` (both clamped). Plus the
existing `rest_api_init` registration. No new action.

## 18. Security changes

No security was weakened and no new attack surface was added. Rendering is out of process;
no route accepts a URL; no path is exposed; ownership is checked before the store is read;
`Url_Validator` guards every target. §5's SSRF, private-IP, and metadata protections are
untouched.

## 19. Performance

Not measured. The §84 sweep (1/5/10/25/50 pages × 3 viewports) cannot be run here: no
render provider exists in this environment, so render time is unmeasurable and any number
would be invented. The bounds that *are* enforced — `MAX_RENDERS_PER_JOB` 75,
`MAX_SAMPLED_PIXELS` 40,000, `MAX_CORRECTION_ITERATIONS` 3, 120 cache entries, 400 stored
representations, 40 stored reports, 5,000 px compressed-DPR limit — are all asserted in
tests.

## 20. Defects found by execution and fixed

**19 in Phase 13 code, all found by running the suite:**

1. `Visual_Effects::shadow()` returns a non-empty array with `present: false` — `empty( $parsed )` accepted every element as having a shadow.
2. `Visual_Effects::border()` returns `{sides, uniform, present, radius, elementor}` with no top-level `width`/`style`/`color` — reading the top level read `null` for all three, so **every** border was reported absent.
3. `declarations()` did not normalise `border_width` → `border-width`, so the CSS parser looked for a key that was never there.
4. `extract()` walked only top-level components, missing the components nested inside sections — where images and buttons actually live.
5. `merge_boxes()` did `$merged = $rendered` then mutated `$rendered`. PHP arrays are value types: **the entire merge was discarded**, `dom_estimate` never reached the output, and it type-checked and returned a plausible array while doing nothing.
6. `overlaps` (list) and `overlapping` (vocabulary) were two spellings for one fact. A consumer filtering on one silently missed the other. Now one name, and `validate()` raises `unknown_relationship` for the other.
7. Grid inference included derived boxes, reporting a **6**-column grid for a 4-card row. Derived boxes are now excluded with a stated limitation.
8. `width` was used as a *natural* dimension fallback for images, so an image compared its displayed width against itself and every image was reported uncropped.
9. `fit_from_ratios()` returned `cover` for a changed aspect ratio. A bounding box **cannot** distinguish `cover` from `fill`. Now `cropped` with `fit_ambiguous: true` and a note.
10. `font_fallback()` treated `Playfair Display` as a display *family* and would substitute Impact for a serif. Family and role separated; an unsignalled name is `unknown` with no stack and unknown impact.
11. `Visual_Representation::default_confidence()` derived the confidence but left `source: unknown` on an evidence record carrying a rendered bounding box — self-contradictory.
12. The Elementor scrub's replacement key was `elementor_limitation`, which re-introduced the coupling the scrub removed.
13. `Render_Cache::put()` never recorded `project_id` in the index, so the ownership check in `get_capture()` failed for **every** capture — presenting as a permissions bug, caused by storage.
14. `Visual_Api::store_representation()` rewrote the Phase 12 page list and then re-saved the specification, which would have **clobbered a Phase 12 specification with an empty array**.
15. `Visual_Api::__construct()` read `$services['entitlements']` without `isset`, emitting a notice on every partially-populated service array.
16. `Ai_Context_Budget` requires a provider id; `new Ai_Context_Budget()` was a fatal. Now constructed per request.
17. `budget_for()` was passed `prepare_images()`'s whole return rather than its `images` key, so it measured **zero** image bytes — and a request over the limit would have been sent anyway, silently.
18. `strlen( (string) $bytes )` on a non-string returned `null` → 0, **under**-counting the budget in the direction that overspends.
19. `Renderer_Manager::capture()` passed the provider's viewport record through, so a provider reporting no DPR produced a capture with no DPR — breaking §7 normalisation.

**1 pre-existing Phase 12 bug, found by Phase 13 and fixed:**

20. `Component_Registry` did not track which project it held. `put_shared()` read the bucket for the **empty** project id while writing the real one, so (a) a user override was **discarded by a re-analysis** — defeating §47 — and (b) `mark_overridden()` wrote to the shared `''` bucket, so one project's user edit **permanently marked another project's identically-named component as user-owned**. Both were the same mistake and are fixed by one change: `load()`/`save()` set `$project_id`, and writers read the project they write. `delete()` also now resets the loaded state as a unit, so deleting a project cannot be undone by the next write.

## 21. Test expectations corrected rather than papered over

- `Visual_Limits::TRANSFORMATIONS` is **11**, not 10 — §41 lists 11. The code was right.
- A shadow on a node typed `card` belongs to the **card**, not the container. The code was right.
- `regression_check` returns **mixed**, not `regressed`, when one viewport improves and another regresses — more precise than my label.
- §41 spacing priority asserted on a *correctable* spacing difference, since the fixture's is informational and legitimately absent from the eligible list.
- "Default" AI settings asserted from **source** and from an instance with the option **absent**, not from the migrated value.
- A vision-capable provider is now asserted as a **positive** case (`use_vision: true`) plus an oversized-image refusal, rather than another "did not send" — a suite of refusals would pass an implementation that refuses everything.
- Phase 12's "newest migration" assertion replaced with "declared, chain gapless, target ≤ current schema" — the same mistake Phase 11's notes warned about, caught in the act.

## 22. Tests performed

- `tests/phase13-visual-test.php` — 20 sections, 573 assertions, 0 failed, 0 notices.
- Full suite: **21 suites, 4010 assertions passed, 0 failed, 2 skipped** (pre-existing).
- `php -l`: **194 files, 0 failures**.
- Type load: **171 declared, 171 loaded, 0 missing**.
- Migration `13.0.0` applied, 0 pending, idempotent.
- REST: 15 paths, 32 handlers, **0 ungated**.
- `Plugin::instance()->visual_api()` live.
- Component-registry fix verified directly across 2 projects, 2 re-analyses, 1 delete.

## 23. Acceptance criteria

**Met (54):** rendering abstraction; capability detection; secure rendering boundary;
standard viewports; render stabilization; dynamic content detection; popup/cookie
detection; bounding boxes; spatial relationships; section boundaries; container geometry;
grid analysis; overlap detection; layering; backgrounds; gradients; colours; shadows;
borders; radius; image crop; typography; text wrapping; icons; buttons; cards; whitespace;
responsive analysis; sticky/fixed; animation normalization; carousel/video; the `13.0`
schema; DOM↔visual mapping; evidence tracking; conflict detection; provider-aware AI;
optional vision; context budgets; screenshot caching; multi-signal comparison; dynamic
masking; region comparison; heatmap; overlay modes; before/after; bounded loop;
regression protection; multi-page consistency; warnings; plan limits; usage accounting;
Phase 11 orchestration; security intact; Phases 1–12 working.

**Partial (5):** flex analysis (geometry-driven, not a separate detector); design-system
visual validation (exposed via consistency and palette overlap, no dedicated validator);
admin dashboard (data assembled, no screen); export (§82, not extended); hover evidence
(reads a *declared* `:hover`, since a screenshot cannot hover).

**Not met (3), with reasons:**

- **No admin UI.** REST responses exist; nothing renders them. Consistent with Phases 10–12.
- **No pixel-accuracy measurement.** No render provider and no image library in this
  environment. The comparison arithmetic is logic-tested against synthetic pixel surfaces;
  the PNG decode path is not exercised, and no accuracy figure is claimed.
- **§84 performance sweep not run.** Render time is unmeasurable without a renderer.

## 24. Known limitations

1. No screenshot has ever been captured by this code. The renderer path is exercised only through an injected provider in tests.
2. `Image_Differ::is_available()` is false here (no GD, no Imagick). The cross-check has therefore never run against real pixels.
3. The comparison arithmetic is verified with synthetic surfaces. Real PNG decode, resampling, and DPR-2 normalisation are unexercised.
4. `Render_Job` produces a plan; the wiring that turns a plan step into an executed Phase 11 job is unwritten. **No page has been rendered.**
5. No admin screen exists for Phase 13, or for Phases 10–12.
6. `Source_Monitor` still does not exist, so incremental visual sync is unbuilt.
7. Stable section identity across a structural change is unresolved — a fingerprint includes section order, so inserting a section yields a new component.
8. `Job_Runner::process()` still ignores its time budget (carried from Phase 11).
9. `Ai_Manager` still does not call `Ai_Context_Budget` or `Ai_Cost_Estimator` (carried from Phase 11).
10. Phase 1–9 REST endpoints are still unmetered (carried from Phase 11).
11. `Project_Repository` is still referenced by no class except tests.
12. Multisite is untested for Phases 1–13; uninstall does not sweep the Phase 13 options.
13. No live AI provider or render provider is exercised by any test.
14. **Pre-existing encoding damage** in `includes/elementor/class-replicaforge-elementor-values.php` (Phase 8): 2 mojibake characters in a comment. Found and reported, not fixed, because it is outside this phase and repairing unrelated files is how a phase stops being reviewable.
15. All work is uncommitted; there is no Git repository.

## 25. Manual test instructions

**A. Confirm the honest degradation (no configuration needed)**

```bash
wp eval 'var_dump( \ReplicaForge\Plugin::instance()->visual_api() !== null );'
wp eval 'print_r( ( new \ReplicaForge\Renderer_Manager() )->capabilities() );'
wp eval 'print_r( \ReplicaForge\Renderer_Manager::warnings() );'
```
Expect: `true`; `available => false` with a reason; two warnings.

**B. Visual analysis with no renderer**

```bash
wp eval '
$p = \ReplicaForge\Plugin::instance();
$a = new \ReplicaForge\Visual_Analyzer();
$r = $a->analyze( array( "sections" => array(
    array( "id" => "s1", "type" => "hero", "height" => 500, "background" => "#101820" ) ) ),
  array( "succeeded" => false ),
  array( "name" => "desktop", "width" => 1440, "height" => 900, "device_pixel_ratio" => 1 ) );
print_r( array( "rendered" => $r["rendered"], "derived" => $r["geometry"]["derived_boxes"], "limitations" => $r["limitations"] ) );
'
```
Expect: `rendered => false`, boxes marked `derived`, and a limitation saying overlap and grid detection is unreliable.

**C. Renderer capability detection with no provider**

```bash
wp eval 'var_dump( ( new \ReplicaForge\Renderer_Manager() )->is_available() );'
```

**D. Viewports and DPR normalisation**

```bash
wp eval 'print_r( ( new \ReplicaForge\Viewport_Manager() )->profiles() );'
wp eval 'print_r( ( new \ReplicaForge\Viewport_Manager() )->normalisation(
  array( "width" => 2880, "height" => 1800, "rendered_dpr" => 2.0 ),
  array( "width" => 1440, "height" => 900,  "rendered_dpr" => 1.0 ) ) );'
```
Expect: three profiles; a DPR mismatch and a caveat about resampled ratios.

**E. Dynamic content detection**

```bash
wp eval '
$d = new \ReplicaForge\Dynamic_Detector();
print_r( array_map( function( $e ) { return array( $e["id"], $e["reason"], $e["exclude_from_reconstruction"], $e["mask_from_comparison"] ); },
  $d->detect( array( "sections" => array(
    array( "id" => "b", "type" => "div", "class" => "cookie-banner" ),
    array( "id" => "v", "type" => "video" ),
    array( "id" => "p", "type" => "p", "text" => "A real paragraph." ) ) ) )["elements"] ) );
'
```
Expect: the banner excluded **and** masked; the video masked but not excluded; the paragraph absent.

**F. Comparison with a masked dynamic region**

```bash
wp eval '
$c = new \ReplicaForge\Visual_Comparator( new \ReplicaForge\Synthetic_Image_Reader() );
$w = \ReplicaForge\Synthetic_Image_Reader::blank( 40, 40, array(255,255,255) );
$b = \ReplicaForge\Synthetic_Image_Reader::blank( 40, 40, array(0,0,0) );
$b2 = ( new \ReplicaForge\Visual_Comparator( new \ReplicaForge\Synthetic_Image_Reader() ) )
  ->compare( array( "source_image" => "x", "replica_image" => "x",
    "surfaces" => array( "source" => $w, "replica" => $b ) ) );
echo "unmasked: " . $b2["signals"]["pixel"]["ratio"] . "\n";
$m = ( new \ReplicaForge\Visual_Comparator( new \ReplicaForge\Synthetic_Image_Reader() ) )
  ->compare( array( "source_image" => "x", "replica_image" => "x",
    "surfaces" => array( "source" => $w, "replica" => $b ),
    "masks" => array( array( "x" => 0, "y" => 0, "width" => 40, "height" => 40, "reason" => "video" ) ) ) );
echo "masked:   " . $m["signals"]["pixel"]["ratio"] . "\n";
'
```
Expect `1` then `0` — proving the mask is applied **before** the ratio.

**G. Correction refusal and regression protection**

```bash
wp eval '
delete_option( \ReplicaForge\Component_Registry::OPTION );
$r = new \ReplicaForge\Component_Registry();
$r->put_shared( "manual_demo", array( array( "component_id" => "shared_header", "role" => "header", "pages" => array(1,2) ) ) );
$k = new \ReplicaForge\Visual_Corrector( $r );
$diff = array( array( "category" => "color", "severity" => "major", "code" => "x", "message" => "x",
                      "evidence" => array( "component_id" => "shared_header" ) ) );
print_r( $k->plan( array( "differences" => $diff ) )["eligible"][0]["scope"] );  // shared_component
$r->mark_overridden( "shared_header", array( "note" => "mine" ) );
print_r( $k->plan( array( "differences" => $diff ) )["refused"][0]["refused_because"] );
print_r( $k->regression_check(
  array( "desktop" => array("score"=>0.6), "mobile" => array("score"=>0.8) ),
  array( "desktop" => array("score"=>0.95), "mobile" => array("score"=>0.4) ) ) );
delete_option( \ReplicaForge\Component_Registry::OPTION );
'
```
Expect `shared_component`, a "customized" refusal, and `mixed` with `apply => no`.

**H. AI vision gates**

```bash
wp eval '
$ai = new \ReplicaForge\Visual_AI();
print_r( $ai->public_settings()["vision_enabled"] );  // false
print_r( $ai->prepare( array( "provider_id" => "nope", "model_id" => "nope",
  "images" => array( "x" ), "representation" => array( "a" => 1 ) ) )["blocked_because"] );
print_r( $ai->estimate( array( "provider_id" => "openai", "model_id" => "gpt-4o",
  "images" => array( "x" ) ) )["unit"] );  // image
'
```

**I. Real rendering (requires you to provide an endpoint)**

Configure a render endpoint in the existing Phase 5 validation settings, enable screenshot
validation, then:
```bash
wp eval 'print_r( ( new \ReplicaForge\Renderer_Manager() )->capabilities() );'
```
`available => true` with the provider's version. Only then does rendered geometry, overlap,
pixel difference, region comparison, and the heatmap become measurable — and **only then**
should any accuracy claim be made.

## 26. What was deliberately not done

- **No screenshot became a page.** Asserted by a comment-stripped scan of `includes/visual/`.
- **No source JavaScript executed in WordPress.** Asserted by scanning for `eval(`, `shell_exec`, `proc_open`, `popen`.
- **No renderer shipped, required, or paid for.**
- **No second queue, table, AI provider, validation engine, or compatibility layer.**
- **No new difference vocabulary in a new place** — the five new categories were added to Phase 5's list in its own file, and given metric groups, or they would be stored and never scored.
- **No image library required** for anything but decoding, and its absence is reported as a reason code.
- **No Phase 14.**

## 27. Final state

21 suites, 4010 assertions, 0 failures, 2 skipped (pre-existing). 194 files lint clean. 171
types declared and loaded. Migration `13.0.0` applied and idempotent. 15 REST paths, 32
handlers, 0 ungated. Version `1.1.0`, schema `13.0.0`.

Phase 13 is delivered: the visual intelligence layer exists, degrades honestly, and claims
nothing it did not measure. What it has **not** done is render anything — that needs an
operator-provided endpoint, and until one exists the correct answer to "how close is the
replica?" is still the one the system gives: rendered comparison is unavailable.
