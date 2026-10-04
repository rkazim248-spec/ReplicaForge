# ReplicaForge Phase 4 — Elementor Generation

Phase 4 converts the validated **Reconstruction Specification 3.0** produced by Phase 3 into a real, editable Elementor document and saves it as a new WordPress **draft**.

```
Phase 1  Secure data collection
Phase 2  Frontend structure + design intelligence
Phase 3  AI understanding + reconstruction planning
         │
         ▼
VALIDATED RECONSTRUCTION SPECIFICATION 3.0
         │
         ▼
PHASE 4  Deterministic Elementor generation
         │
         ▼
ELEMENTOR DOCUMENT  →  WORDPRESS DRAFT  →  USER EDITS IN ELEMENTOR
```

Phase 4 does not crawl, does not re-parse HTML, does not call an AI provider, and does not interpret the source website again. It is a builder.

---

## 1. Service map

| File | Responsibility |
| --- | --- |
| `includes/elementor/class-replicaforge-elementor-limits.php` | Every bound used by Phase 4 (elements, depth, assets, bytes, timeouts). |
| `includes/elementor/class-replicaforge-elementor-values.php` | The single value policy for colors, lengths, fonts, executable content, and Elementor control shapes. |
| `includes/elementor/class-replicaforge-elementor-compatibility.php` | Runtime detection of Elementor, its version, registered element/widget types, active breakpoints, and the document API. |
| `includes/elementor/class-replicaforge-elementor-spec-validator.php` | Phase 4 input validation and normalization of the specification into a builder-neutral plan. |
| `includes/elementor/class-replicaforge-elementor-widget-registry.php` | `ReplicaForge component → Elementor widget` resolution with availability fallbacks. |
| `includes/elementor/class-replicaforge-elementor-mapper.php` | Plan → element tree, layout, styles, colors, typography, and the shared value sanitizers. |
| `includes/elementor/class-replicaforge-elementor-responsive.php` | Evidence-backed Desktop/Tablet/Mobile plan. |
| `includes/elementor/class-replicaforge-elementor-document-builder.php` | Element identifiers, hierarchy, final scrubbing, and serialization. |
| `includes/elementor/class-replicaforge-elementor-assets.php` | Asset validation, optional bounded import, and provenance. |
| `includes/elementor/class-replicaforge-elementor-validator.php` | Pre-save and post-save document validation. |
| `includes/elementor/class-replicaforge-elementor-draft-service.php` | Draft creation, Elementor save, metadata, and rollback. |
| `includes/elementor/class-replicaforge-elementor-repository.php` | Bounded generation records and short-lived specification references. |
| `includes/elementor/class-replicaforge-elementor-generator.php` | Orchestration and reporting. |

---

## 2. Pipeline

```
design_representation (Phase 2, validated)
        │
        ├─ Design_Representation::is_valid()
        ├─ Elementor_Compatibility::is_available()      → elementor_not_available
        ▼
specification resolution
        ├─ stored specification_id (24 h transient), or
        ├─ supplied reconstruction_specification, or
        └─ deterministic Ai_Reconstruction_Planner::build()   (Phase 4 never calls AI)
        │
        ├─ Ai_Output_Validator (source consistency vs. Phase 2)
        ├─ Elementor_Spec_Validator (schema, references, URLs, style values, no builder keys)
        ▼
asset resolution
        ├─ Elementor_Assets::resolve()  → imported | pending | blocked | failed | unavailable
        ▼
responsive plan  →  mapping  →  document build  →  document validation
        │
        ▼
draft creation  →  save via Elementor Document API  →  post-creation validation
        │
        ├─ failure at any point after insert → wp_delete_post( force ) + log
        ▼
generation result + report
```

`POST /replicaforge/v1/generate` accepts `mode=preview` to run everything except the draft creation, so the admin screen can show what will be generated first.

---

## 3. Component mapping

| ReplicaForge reconstruction type | Elementor output | Notes |
| --- | --- | --- |
| `heading` | `heading` widget | `header_size` from the detected source tag, otherwise from the semantic role. |
| `paragraph`, `label`, `list`, `unknown` | `text-editor` widget | Source text, unmodified, as plain text. No HTML is injected. |
| `button`, `link`, `navigation_link` | `button` widget | Target is written only when it is a verified public URL. |
| `image`, `logo` | `image` widget | Resolved asset, or the Elementor placeholder with a warning. |
| `divider` | `divider` widget | — |
| `spacer` | `spacer` widget | Warns that the size was not part of the specification. |
| `icon` | not generated | The source icon identity cannot be verified, so no icon is invented. |
| `background_image` | parent container background | Never a separate widget. |
| `card`, `product_card`, `feature_card`, `portfolio_card`, `blog_card`, `team_card`, `pricing_card`, `testimonial` | container with independent children | Each part (image, title, body, price, badge, button) is its own editable widget. |
| `repeated_card_group` | row container with one container per member card | Every card stays separately editable. |
| `gallery` | wrapping row container of `image` widgets | — |
| `form` | container with a heading | Structure only. No endpoint, field name, token, or cookie is copied. |
| `form_field` | `text-editor` widget | Read-only text. No input element is created. |
| `accordion`, `tabs`, `tab` | container with detected text | Warns that interaction was not reproduced. |
| anything else / unavailable widget | safe fallback or skipped | The failure is counted and reported. |

Widget availability is resolved at runtime. If `heading` is missing, the registry falls back to `text-editor`; if that is missing too, the component is skipped and the reason is recorded. `html`, `shortcode`, `embed`, and `custom-html` widgets are never emitted.

---

## 4. Layout and containers

Each Phase 3 section becomes a top-level `container` element. The Phase 2 layout evidence maps to real Elementor controls:

| Phase 3 layout | Elementor container setting |
| --- | --- |
| `direction` | `flex_direction` |
| `gap` | `flex_gap` (`{row, column, unit, isLinked}`) |
| `alignment.horizontal` | `flex_justify_content` |
| `alignment.vertical` | `flex_align_items` |
| `wrap` | `flex_wrap` |
| `container.max_width` | `content_width: boxed` + `boxed_width` |
| `padding` / `margin` / `min_height` (when present) | container `padding`, `margin`, `min_height` |
| `columns[]` width ratios | child `width` (`%`) + `_flex_size: shrink` |
| equal columns without ratios | child `_flex_size: grow` |
| `source.tag` | `html_tag` when it is a safe element name |

Measured ratios are preserved. A 2/3 + 1/3 section does not become 50/50 unless the specification records no ratios. Because the specification does not record *which* component belongs to which column, components are distributed evenly and a warning tells the user to review the column order.

Top-level elements are always containers. Elementor does not accept a bare widget at the document root in a container-based document, and a bare widget would also be impossible to style consistently.

---

## 5. Responsive generation

`responsive_strategy` and each section's `responsive` block are converted into Elementor device settings.

* **Desktop** is built from the measured Phase 2 layout and is always applied.
* **Tablet / Mobile** are only emitted when the specification contains a matching responsive value or responsive evidence (`layout_change`, `grid_columns_change`, `flex_direction_change`, `display_change`).
* An `unknown` responsive value is never treated as "mobile stacks". No stacking is invented.

When evidence supports a change, a multi-column section receives:

```
container:   flex_direction_tablet|mobile = column
column:      width_tablet|mobile = 100%
```

and the report records `applied_from_evidence`, together with an explicit warning that the stacking is an inference from detected evidence rather than a measurement. Source JavaScript behaviour — including mobile navigation interaction — is not reproduced; a warning is recorded instead of pretending it was.

---

## 6. Assets and provenance

Importing is **off by default**, because a publicly reachable image is not automatically licensed for reuse.

| State | Meaning |
| --- | --- |
| `imported` | Copied into the media library after a bounded, SSRF-validated, MIME-verified download. |
| `pending` | Referenced from the source website. Nothing was copied. |
| `blocked` | Unsafe URL, or a type that cannot be verified as safe media (SVG, PHP, JS, fonts, archives, …). |
| `failed` | The bounded download failed. The image stays referenced and the reason is reported. |
| `unavailable` | The specification recorded no safe source. |

Limits: 40 assets considered, 24 imported, 3 MB per file, 20 MB total, 10 second timeout, 2 redirects, JPEG/PNG/GIF/WebP only, signature verified before insertion. SVG is never imported and never referenced. Downloaded bytes are written through `wp_upload_bits` + `wp_insert_attachment`; nothing is executed, and the file type is re-checked with `wp_check_filetype` before the attachment is created.

Every imported attachment stores provenance:

```json
{
  "source_url": "https://example.com/one.jpg",
  "source_type": "remote",
  "import_status": "imported",
  "provenance": "source_website",
  "generation_id": "rf_20260925T101500_1a2b3c4d5e6f",
  "imported_at": "2026-09-25T10:15:00+00:00",
  "plugin": "replicaforge"
}
```

The same provenance is stored on the draft as `replicaforge_provenance`. ReplicaForge never claims ownership or licensing.

---

## 7. Draft creation and rollback

* A **new** `page` is inserted with `post_status = draft`. Existing pages, templates, menus, widgets, and WooCommerce records are never touched.
* `_elementor_edit_mode` is set to `builder` and `_elementor_template_type` to `wp-page`.
* The document is written through Elementor's own API (`Plugin::instance()->documents->get( $id, false )->save( array( 'elements' => … ) )`), so the stored data is the same shape the editor writes.
* Stored data is re-read and re-validated. If validation fails, the draft is force-deleted, the failure is logged, and the API returns a human-readable error. No partial draft survives.
* Publishing never happens automatically.

Draft metadata (`replicaforge_` prefix):

```
replicaforge_source_url         replicaforge_generation_id
replicaforge_schema_version     replicaforge_generated_at
replicaforge_generation_version replicaforge_phase
replicaforge_elementor_version  replicaforge_ai_used
replicaforge_specification_id   replicaforge_id_map
replicaforge_report             replicaforge_provenance
```

`replicaforge_id_map` is the `ReplicaForge component → Elementor element id` map that future regeneration and section-level regeneration will need.

---

## 8. Security

* **Authorization** — the REST route requires a logged-in user, `manage_options`, and a valid `wp_rest` nonce, exactly like the analysis routes. Draft creation additionally requires `edit_pages`.
* **No builder data from the browser** — the route never accepts `elType`, `widgetType`, `_elementor_data`, shortcodes, or HTML. It receives a Phase 2 representation and, optionally, a Phase 3 specification that is re-validated against that representation.
* **Hallucination control** — the Phase 3 output validator re-checks every section, component, asset, and content record against the Phase 2 source before mapping. Invented prices, text, links, or assets cause a hard rejection before any draft exists.
* **Stored XSS** — text is stripped of markup, URLs must pass the public-reference policy, and CSS-like values must match strict color/length/font patterns. The document builder performs a final recursive scrub, and the validator rejects executable markup, event handlers, and unsafe schemes.
* **Executable output** — no PHP, JavaScript, shell, SQL, shortcode, `html` widget, or copied source script is ever written. Elementor settings are only Elementor settings.
* **SSRF** — asset downloads reuse `Url_Validator` + `Http_Client::fetch_media()`, so DNS/IP checks, port restrictions, forbidden paths, credential rejection, redirect re-validation, size limits, and timeouts are identical to Phase 1. No weaker validator exists in Phase 4.
* **File safety** — only four raster MIME types, verified by response header and magic bytes, and re-verified with `wp_check_filetype` before insertion.
* **Logging** — only allow-listed scalar fields (`host`, `status`, `code`, `duration_ms`, `count`, `redirects`, `reason`) are logged, and only when `WP_DEBUG` and `WP_DEBUG_LOG` are enabled. API keys, cookies, tokens, and document content are never logged.

---

## 9. Reporting

Every run returns a structured report:

```json
{
  "generation_id": "rf_20260925T101500_1a2b3c4d5e6f",
  "source": { "url": "…", "host": "…", "title": "…", "type": "…", "ai_used": false },
  "sections":   { "detected": 2, "generated": 2, "omitted": 0 },
  "components": { "detected": 5, "generated": 5, "widgets": 7, "containers": 4, "fallbacks": 0, "skipped": 0, "low_confidence": 0 },
  "elements":   { "generated": 13, "max_depth": 3 },
  "assets":     { "detected": 0, "imported": 0, "referenced": 0, "blocked": 0, "failed": 0, "unavailable": 0, "bytes": 0, "roles": {} },
  "responsive": { "desktop": "applied", "tablet": "not_detected", "mobile": "applied_from_evidence" },
  "confidence": { "overall": 0.8 },
  "limitations": [ "…" ],
  "elementor": { "available": true, "version": "4.3.2", "containers": true, "phase": "4.0" },
  "visual_validation": { "supported": false, "reason": "…" }
}
```

`confidence` is an inference score from Phase 3. It is not an accuracy percentage, and no visual accuracy claim is made. `visual_validation.supported` is `false` on purpose: Phase 4 exposes the metadata a future screenshot comparison system would need, but it does not capture or compare screenshots.

Confidence also changes how a run is reported. When the overall reconstruction confidence is below `0.5`, or when individual components are below `0.5`, the run is still generated but a warning states that the result is a best-effort approximation that must be reviewed before publishing. Low confidence never silently becomes high confidence.

---

## 10. Known limitations

1. **No pixel-perfection claim.** ReplicaForge maps detected structure and design values. Rendered output is not measured or compared.
2. **No interaction.** Carousels, sliders, filters, tabs, accordions, sticky behaviour, and mobile navigation menus are not reproduced.
3. **No backend.** Forms become safe structural text. No source endpoint, field name, token, or credential is copied, and nothing is ever submitted to the source website.
4. **No font invention.** A font family is applied only when Phase 2 detected it.
5. **No global kit changes.** Colours are written per element so the site kit, theme, and existing pages are untouched.
6. **Column membership is inferred.** The specification records a column count, not which component belongs to which column.
7. **Per-section visual styles are not available.** The specification carries global design tokens, not per-section background or border records, so per-section decoration is only applied when a `background_image` component exists.
8. **Assets are referenced, not copied, by default.** Licensing of source imagery is the site owner's responsibility.
9. **Icon identity is not verifiable** from the specification, so icons are skipped rather than substituted.
10. **Regeneration is designed for, not implemented.** Stable element identifiers and generation metadata are stored, but section-level and partial regeneration are Phase 5 work.
