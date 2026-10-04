# Phase 5 — Visual Validation & Accuracy Engine

Phase 5 answers one question:

> How closely does the generated Elementor draft match the analyzed source page?

It **reads, compares, and reports**. It never modifies the Elementor document, never
publishes, and never applies a correction. The machine-readable correction plan it
produces is data for a future phase.

```
Source Website
      ↓
Phase 1 / Phase 2 Analysis
      ↓
Source Design Representation
      ↓
Phase 4 Elementor Draft
      ↓
Generated Design Representation
      ↓
Comparison Engine
      ↓
Difference Detection
      ↓
Validation Report
      ↓
Machine-Readable Correction Plan
```

---

## 1. The two-sided representation

Elementor document data and a remote page's DOM/CSS are different systems. They are
never compared directly. Each side is normalized into the same schema first, and only
normalized records are compared.

```
Source HTML + CSS  ──Phase 2──▶  Design Representation 2.0  ──▶  Source Adapter   ──┐
                                                                                     ├─▶ Comparison Schema 5.0
Elementor document + post CSS  ──Generated Page Analyzer──▶  Generated Side  ───────┘
```

### Schema 5.0 (a normalized side)

```json
{
  "schema_version": "5.0",
  "side": "source | generated",
  "page":       { "url": "", "title": "", "type": "", "container_max_width": null, "container_centered": null },
  "sections":   [ { "key": "", "order": 0, "type": "", "column_count": 0, "column_ratios": [],
                    "direction": "", "gap": null, "wrap": "", "align": {}, "max_width": null,
                    "members": [], "heading": "", "tag": "", "confidence": 0 } ],
  "components": [ { "key": "", "order": 0, "type": "", "tag": "", "section_key": "", "is_card": false,
                    "text": "", "link": "", "fields": {}, "image": {}, "typography": {}, "confidence": 0 } ],
  "design_system": { "colors": {}, "color_confidence": {}, "typography": {}, "spacing": [], "radius": [], "shadows": [] },
  "viewports":  { "desktop": {}, "tablet": {}, "mobile": {} },
  "navigation": { "mobile_detected": null, "behavior": "", "confidence": 0 },
  "mapping":    { "elementor_element_id": "source_component_id" },
  "counters":   {},
  "warnings":   []
}
```

### The generated side reads two real sources of truth

| Source | What it provides |
| --- | --- |
| `_elementor_data` | element tree, hierarchy, widget identity, settings (intent) |
| the stylesheet Elementor generated for the document | **effective** values per device, including tablet and mobile overrides |

The stylesheet is read from the `_elementor_css` post meta, or from
`{uploads}/elementor/css/post-{id}.css` via `Elementor\Core\Files\CSS\Post`. Its rules
are attributed to a device by their media query (`@media(max-width: 1024px)` → tablet,
`@media(max-width: 767px)` → mobile, no query → desktop), then indexed per element ID.
Container custom properties are mapped to comparable layout properties through a
fixed, declared table (`--flex-direction` → `flex-direction`, `--gap` → `gap`, …).

This is why responsive comparison is real rather than assumed: the generated side
supplies actual device values.

---

## 2. Pairing before comparison

Pairing runs once and is shared by every comparator (`Comparison_Context`).

1. **Identity map first.** A generated element whose `source_id` came from the Phase 4
   `replicaforge_id_map` post meta is paired with that source component. This is exact.
2. **Deterministic fallback.** Remaining generated elements are matched to remaining
   source components of the same comparable type, in document order.
3. **Remainders.** Source records with no counterpart are `missing`; generated records
   with no counterpart are `extra`.

Because the fallback is type-then-order, two runs over the same inputs always pair the
same records. A `type_and_order` pair carries confidence `0.6`; an `identity_map` pair
carries `1.0`, and that confidence flows into every difference the pair produces.

Sections pair by type bucket first, then by position. Top-level containers in the
generated document are sections, which is exactly how Elementor structures a document.

---

## 3. Comparators

| Class | Categories | What it compares |
| --- | --- | --- |
| `Structural_Comparator` | `structure`, `section_order`, `component`, `content`, `link` | section presence, section order, per-type component counts, component presence, text, link destinations |
| `Layout_Comparator` | `layout`, `spacing` | column count, column ratios, flex direction, container max width, minimum height, section gap |
| `Typography_Comparator` | `typography` | font family, size, weight, line height, letter spacing, transform, text colour, and the detected scale |
| `Color_Comparator` | `color`, `background`, `border`, `shadow` | semantic colour roles, box colour, border radius, border width, box shadow |
| `Spacing_Comparator` | `spacing` | section gap, and the detected spacing scale matched against the nearest generated value |
| `Asset_Comparator` | `asset`, `image` | image presence, image count, aspect ratio, and asset availability notices |
| `Responsive_Comparator` | `responsive`, `visibility`, `navigation` | per-device evidence, device rules, column count, hidden elements, column progression regression, mobile navigation state |

Every comparator returns **checks**. A check is:

```json
{
  "category": "typography",
  "target": "component:component_001",
  "property": "font_size",
  "expected": 64,
  "actual": 56,
  "difference": -8,
  "state": "pass | partial | fail | missing | extra | unknown",
  "tolerance_type": "font_size",
  "message": "The heading size differs: 64px detected, 56px generated (8px).",
  "confidence": 0.85,
  "source_reference":    { "source_component_id": "component_001" },
  "generated_reference": { "elementor_element_id": "aaa1111" }
}
```

`pass` and `unknown` are kept for the metric denominators and are **never** reported as
differences. This is the single most important honesty rule in Phase 5: **a source value
that was never detected is never treated as a difference and never as a match.**

---

## 4. The difference engine

Comparators never decide severity or identifiers. `Difference_Engine` applies the
declared severity table, assigns stable IDs, deduplicates, and bounds the result.

| Category | partial | fail | missing | extra |
| --- | --- | --- | --- | --- |
| `structure` | major | major | **critical** | minor |
| `section_order` | major | major | major | informational |
| `component` | moderate | moderate | major | minor |
| `layout` | minor | moderate | moderate | informational |
| `spacing` | minor | moderate | minor | informational |
| `typography` | minor | moderate | moderate | informational |
| `color` | minor | moderate | moderate | informational |
| `background` | minor | moderate | minor | informational |
| `border` | minor | minor | informational | informational |
| `shadow` | minor | minor | informational | informational |
| `image` | minor | moderate | moderate | minor |
| `asset` | minor | moderate | moderate | informational |
| `content` | moderate | major | major | minor |
| `link` | moderate | moderate | moderate | informational |
| `responsive` | minor | moderate | moderate | informational |
| `navigation` | minor | moderate | moderate | informational |
| `visibility` | minor | moderate | minor | informational |
| `interaction` | informational | moderate | informational | informational |

A difference record:

```json
{
  "id": "diff_0003_9f2a1c7b",
  "category": "typography",
  "target": "component:component_001",
  "property": "font_size",
  "expected": 64,
  "actual": 56,
  "difference": -8,
  "state": "fail",
  "tolerance_type": "font_size",
  "severity": "moderate",
  "message": "The heading size differs: 64px detected, 56px generated (8px).",
  "confidence": 0.85,
  "viewport": "desktop",
  "source_reference":    { "source_component_id": "component_001" },
  "generated_reference": { "elementor_element_id": "aaa1111" }
}
```

IDs are `diff_` + a zero-padded ordinal + a truncated hash of
`category|target|property|viewport|state`, so they are stable for the same inputs.
Differences are capped at 400 per run.

---

## 5. Tolerances and the measurement formula

Declared in `Validation_Limits::TOLERANCES` and published with every result.

| Property type | small (pass) | medium (partial) | weight |
| --- | --- | --- | --- |
| `length` | ≤ 4 px | ≤ 12 px | 1.0 |
| `font_size` | ≤ 2 px | ≤ 6 px | 1.5 |
| `ratio` | ≤ 0.02 | ≤ 0.08 | 1.5 |
| `color` | ≤ 12 | ≤ 40 | 1.0 |
| `line_height` | ≤ 0.05 | ≤ 0.2 | 1.0 |
| `weight_value` | exact | — | 1.0 |

Colour distance is a luminance-weighted sRGB distance on a 0 (identical) to 441.67
(black versus white) scale. An alpha difference greater than 0.01 adds a fixed 25 so a
transparent and an opaque colour never compare as identical.

### Category score

```
category = round( 100 * (passed + 0.5 * partial) / (passed + partial + failed), 1 )
```

A check inside the small band counts fully, a check inside the medium band counts
half, a check beyond it counts zero. Checks with no comparable evidence on both sides
are excluded from the denominator and reported separately as `not_comparable`.

### Group and overall score

Groups: `structure`, `layout`, `spacing`, `typography`, `colors`, `assets`, `content`,
`responsive`, `detail`. A group score uses the same formula over its member categories.

`overall` is the weighted mean of the group scores that had at least one comparable
check, using `CATEGORY_WEIGHTS`, and excluding `detail`. Its weights are published in
the result.

**This is an internal validation measurement, not an accuracy claim.** The plugin never
displays "pixel perfect" or "guarantees N%". The result carries the formula, the
tolerance table, and the weights so any number can be reproduced by hand.

---

## 6. Validation levels and graceful degradation

| Level | Name | Availability |
| --- | --- | --- |
| 1 | `structural_comparison` | always |
| 2 | `design_system_comparison` | always |
| 3 | `rendered_visual_comparison` | only with a configured render provider **and** GD or Imagick |
| 4 | `ai_explanation` | only with a configured AI provider |

A level that cannot run produces a warning, never a failure:

- *Source rendering is unavailable. Structural validation remains available.*
- *The generated draft could not be rendered. Elementor structural validation remains available.*
- *Visual image comparison is unavailable. Structural comparison completed successfully.*

The `levels` map in the result lists only the levels that actually ran, so a run without
a renderer never implies a rendered comparison happened.

---

## 7. Rendered comparison (Level 3)

A WordPress plugin cannot ship a headless browser, and rendering an untrusted page
inside the WordPress process would execute remote content. Rendered comparison is
therefore delegated to an **optional, explicitly configured external render provider**,
disabled by default.

### Configuration (ReplicaForge → Visual Validation)

| Field | Notes |
| --- | --- |
| Enable rendered comparison | off by default |
| Render endpoint URL | must be a public **HTTPS** URL; validated with the Phase 1 `Url_Validator`, so loopback, private ranges, and non-standard ports are refused |
| Bearer token (optional) | stored only if supplied; an empty field keeps the stored value |
| Request timeout | 5–60 s, default 30 |
| Maximum capture width | `0` uses the viewport width |

### What is sent

For the **source** page, the validated public URL is sent. For the **generated draft**,
the document's own rendered markup plus its stylesheet URLs are sent. The draft is
therefore never published and never needs a public preview URL.

```json
{
  "url": "https://example.com/",
  "html": "<div class=\"elementor\">…</div>",
  "css_urls": ["https://site.example/…/post-123.css", "https://site.example/…/elementor-frontend.min.css"],
  "viewport": { "width": 1440, "height": 900, "deviceScaleFactor": 1 },
  "output":  { "format": "png", "fullPage": false },
  "block":   { "scripts": true, "media": true }
}
```

`<script>` and `<noscript>` blocks are stripped from the markup before it leaves the
site, scripts and media are requested to be blocked, redirects are refused
(`redirection: 0`), the response is size-capped at 8 MiB, and the PNG signature is
verified before any comparison runs.

**Deployment note.** The render provider is a trusted-by-configuration third party. The
markup of the generated draft is sent to it. Treat the endpoint as part of your own
infrastructure and keep it disabled unless you accept that.

### Image difference

`Image_Differ` runs a single-pass pixel comparison on the overlap of the two captures.

- A per-pixel channel delta of `(|Δr| + |Δg| + |Δb|) / 765` at or below **0.08** counts
  as equal, so font rasterization and sub-pixel rendering are not design differences.
- `differing_ratio` = differing pixels / compared pixels, plus a fixed 0.1 when the two
  captures differ in size (a size mismatch is itself a measurable difference).
- `similarity` = `1 - differing_ratio`.
- Regions are localized with three band widths (16 px, 8 px, 4 px) so a shifted section
  or a resized image is visible.
- Bands: `≤ 0.02` pass, `≤ 0.12` partial, `≤ 0.45` fail, above that major.

A size mismatch is reported explicitly rather than being hidden behind the ratio.

---

## 8. Responsive validation

Validated separately per device. Baseline viewports (`Validation_Limits::VIEWPORTS`):

| Device | Width | Height |
| --- | --- | --- |
| Desktop | 1440 | 900 |
| Tablet | 768 | 1024 |
| Mobile | 390 | 844 |

Per device the comparator reports: whether the source has responsive evidence at all,
whether the draft has device rules, the effective column count, and the count of hidden
elements.

**Responsive regression detection** compares the whole progression:

```
Source:    3 → 2 → 1
Generated: 3 → 3 → 1
Result:    "Responsive progression mismatch: the source changes 3 > 2 > 1
            while the draft changes 3 > 3 > 1."
```

**Mobile navigation** is reported as one of `correct`, `partially_reproduced`,
`not_reproduced`, or `unknown`, and ReplicaForge **never claims an interaction works
unless it was tested**. Because Phase 4 does not reproduce menu interaction, a detected
source mobile navigation is always reported as `partial` or `missing` with a message
telling the user to verify the behaviour in a browser.

When the source analysis has no evidence for a device, that device's checks are
`unknown` and are excluded from the denominator. `unknown` is never read as
"mobile stacking was reproduced".

---

## 9. The machine-readable result

```json
{
  "schema_version": "5.0",
  "phase": "5.0",
  "validation_id": "val_0123456789abcdef01234567",
  "created_at": "2026-09-25T12:00:00+00:00",
  "duration_ms": 412,
  "cache": "miss | hit",
  "engine_version": "1.0",
  "status": "completed",
  "cache_key": "v_…",
  "source_hash": "…",
  "generated_hash": "…",
  "levels": { "1": "structural_comparison", "2": "design_system_comparison" },
  "source":    { "url": "…", "title": "…", "type": "…", "side": "source" },
  "generated": { "draft_id": 123, "title": "…", "generation_id": "…", "elementor_version": "4.3.2", "side": "generated" },
  "viewports": { "desktop": {}, "tablet": {}, "mobile": {} },
  "differences": [],
  "metrics": {},
  "visual": {},
  "ai": {},
  "correction_plan": {},
  "counts": { "differences": 0, "by_severity": {}, "by_category": {}, "by_viewport": {}, "warnings": 0 },
  "summary": {},
  "warnings": [],
  "limitations": [],
  "read_only": true
}
```

### Correction plan (Phase 6 input, never applied)

```json
{
  "schema_version": "5.0",
  "generated_at": "2026-09-25T12:00:00+00:00",
  "applied": false,
  "applied_at": null,
  "note": "This plan is data only. ReplicaForge does not apply it, and no Elementor page is modified by validation.",
  "corrections": [
    {
      "target": "component:component_001",
      "category": "typography",
      "property": "font_size",
      "viewport": "desktop",
      "from": 56,
      "to": 64,
      "reason": "The heading size differs: 64px detected, 56px generated (8px).",
      "severity": "moderate",
      "confidence": 0.85,
      "difference_id": "diff_0003_9f2a1c7b",
      "source_reference":    { "source_component_id": "component_001" },
      "generated_reference": { "elementor_element_id": "aaa1111" }
    }
  ],
  "correction_count": 1,
  "skipped_count": 12
}
```

Only `typography`, `color`, `background`, `layout`, `spacing`, `border`, `shadow`,
`responsive`, and `link` differences with both `expected` and `actual` present become
corrections. Structural and content differences are counted as skipped, because there is
no single measured value a correction engine could apply.

---

## 10. AI explanation (Level 4)

The model receives **only** measured values and the difference records the comparators
already produced. Its output is constrained to a short explanation and a list of
restatements. Every returned recommendation must carry a `difference_id` that exists in
the measured list; anything else is **discarded, not reported**.

`Ai_Validation_Explainer` re-checks the model output against the measured differences
and rebuilds each verified recommendation from the *measured* record, so the model can
never introduce a value. If nothing verifies, the explanation is dropped and a warning is
recorded. The deterministic result is unaffected either way.

The model never receives write access, and its output is stored as text.

---

## 11. Caching

The cache key is a SHA-256 over:

```
source hash + generated document hash + engine version + schema version
+ viewport configuration + rendered flag + tolerance table + category weights
```

A change to any of them produces a new key and therefore a fresh validation. This
covers all four invalidation requirements: changed analysis, changed document, changed
engine, and changed viewports. Results are stored in a transient for 7 days; summaries go
into the existing `replicaforge_validations` option, bounded to 25 records, reusing the
Phase 4 repository pattern rather than creating a new table.

The draft is only ever read. Validation writes a cache entry and a summary record, never
post data.

---

## 12. Security

Phase 5 reuses the Phase 1 security services. It adds no second validator.

| Requirement | Implementation |
| --- | --- |
| Capabilities | `manage_options` for the screens; `current_user_can( 'edit_post', $draft_id )` inside the engine and again on every stored-result read and export |
| Nonce | `wp_rest` nonce on the REST route; `check_admin_referer()` on the renderer settings form |
| SSRF | the render endpoint is validated by `Url_Validator` before it is stored and again before every request; the source URL is re-checked with `Security::is_safe_public_reference()` |
| No redirect following | `redirection: 0` on the render request |
| No code execution | the document is read, never rendered in-process; `<script>`/`<noscript>` stripped from outgoing markup; `Elementor_Values::is_executable()` applied to every CSS value, message, target, and comparison value |
| No private network access | the existing public-IP policy is reused verbatim for the endpoint |
| No source-site execution | source JavaScript is never fetched or run; scripts and media are requested to be blocked by the renderer |
| No source form submission | nothing is submitted to the source site; only a screenshot request is made by the render provider |
| No credential exposure | the render token is never returned to the browser; the AI block is excluded from exports; exports carry no raw document data |
| Bounded input | 120 sections, 600 components, 1500 elements, 4 MiB stylesheet, 20 000 CSS rules, 8 MiB screenshot, 6000 px edge, 30 s render timeout, 400 differences, 200 corrections |
| Bounded run | 20 000 checks and a 20 second comparison budget; when either is reached the run stops and records a warning that the result is a partial comparison |
| No modification | the engine has no write path to the document; `read_only` is `true` in every result |

### Logging

All through `Security::log_event()`, which allow-lists scalar fields and writes only
under `WP_DEBUG`:

`validation_started`, `source_render_started`, `generated_render_started`,
`validation_completed`, `validation_failed`, `validation_explanation_failed`,
`validation_render_failed`, `validation_render_settings_saved`,
`validation_render_settings_rejected`.

No API keys, passwords, cookies, or authorization headers are ever logged.

---

## 13. REST

All routes reuse the existing `can_analyze` permission callback.

### `POST /wp-json/replicaforge/v1/validate`

| Param | Type | Notes |
| --- | --- | --- |
| `design_representation` | object, required | validated as Design Representation 2.0 before use |
| `draft_id` | integer, required | must be an Elementor document the current user can edit |
| `visual` | boolean | request rendered comparison; ignored when no provider is configured |
| `ai` | boolean | request an AI explanation of the measured differences |
| `force` | boolean | ignore a cached result |
| `viewports` | object | optional per-device width/height overrides, clamped to 320–4000 |

The route accepts a Phase 2 representation. It never accepts an Elementor document, so
a caller cannot smuggle a document into validation.

### `GET /wp-json/replicaforge/v1/validate/{validation_id}`

Returns a stored report. Re-checks `edit_post` on the draft.

### `GET /wp-json/replicaforge/v1/validate/{validation_id}/export?format=json|csv`

JSON export (schema, source, generated, viewports, metrics, differences, visual summary,
correction plan, counts, warnings, limitations, `read_only`) or a CSV difference list.
The AI block is excluded. No secrets, no document markup.

---

## 14. Admin

**Analyzer screen → Phase 5 panel.** Appears once a representation exists. The Run
button stays disabled until a draft has been generated, then the request pairs the live
Phase 2 representation with the draft ID returned by Phase 4.

**ReplicaForge → Visual Validation.** Comparison-level availability, the render provider
form, a draft count, and the recent validation history table.

**Report tabs:** Overview, Desktop, Tablet, Mobile, Structure, Typography, Colors,
Spacing, Assets, Content, Responsive, Warnings. Tabs use `role="tab"` /
`aria-selected` / `aria-controls`; differences are listed as severity badge + category +
message + `expected → actual (Δ)` rather than colour alone. Export JSON and Export CSV
buttons appear under the report.

All remote values are inserted with `textContent`. No remote HTML, markup, or
executable content ever enters the admin document.

---

## 15. Limitations

- Phase 2 records no per-component padding, margin, or minimum height, so those
  properties are reported as `unknown` rather than compared.
- A source value never detected is `unknown`, never a difference and never a match.
- Fonts, colours, and images present only in the browser are outside static analysis.
- Interactive behaviour, animations, and JavaScript-driven layout changes are not
  validated.
- Menu interaction is not reproduced, so mobile navigation is reported honestly rather
  than claimed correct.
- Without a render provider there is no pixel-level comparison, and the result says so.
- The measurement is a comparison metric, not a visual accuracy score.

---

## 16. Testing

```
wp eval-file wp-content/plugins/replicaforge/tests/phase5-contract-test.php
```

The test is deterministic: it never contacts the analyzed website, never calls an AI
provider, and never renders a page. It covers the value normalizer, the source adapter,
the stylesheet parser, pairing, the difference engine, the correction plan, the metrics
formula, every comparator, the image differ, cache invalidation, render provider
rejection, and the export payloads.

When Elementor is available it creates a real draft, validates it, asserts the document
data is byte-identical afterwards and the status is still `draft`, edits a heading the way
a user would in the Elementor editor, proves the next run detects the change, verifies
the cache was invalidated, and deletes the draft.

### Manual QA

1. Analyze a public URL → run Phase 4 → generate the draft.
2. Run Phase 5 and read the metrics, differences, warnings, and limitations.
3. Open the draft with **Edit with Elementor**. Change a heading, change spacing, change
   a colour, change an image, and save.
4. Run Phase 5 again with *Ignore cached result* ticked. Confirm each change appears as
   the matching difference, with the new value on the generated side.
5. Delete the test draft.

### Responsive QA

Repeat step 2 and read the Desktop, Tablet, and Mobile tabs. Confirm the device rules
count, the column progression, and the mobile navigation wording. If a device reports
"no device rules" while the source has evidence for it, that is a real responsive
difference — fix it in Elementor, save, and validate again.

### If styling does not appear on the generated page

Suspect a wrong Elementor control name in the Phase 4 mapper. Elementor preserves unknown
settings in `_elementor_data` and ignores them, so a wrong name is a silent style loss,
not a corrupt document.
