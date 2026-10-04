# ReplicaForge — Templates & Design System (Phase 19)

**Schema** `19.0` · **Engine** `1.0` · **DB** `19.0.0` · **Plugin** `1.5.0`

This is the reference for Phase 19. It describes what was built, what was deliberately not
built, and what is verified versus not verified. Every figure below was read back out of the
code or measured on the running site, not typed from memory.

---

## 1. What this phase is for

Phases 1–18 reconstruct a website and hand you an editable Elementor page. What none of them
can do is *reuse* the result. A hero you spent forty minutes reconstructing is a fact about
one page, not a reusable design asset — and the second page that needs the same hero starts
from nothing.

Phase 19 turns reconstructed output into a library:

* **Templates** — reusable structure, versioned, workspace-scoped, installable.
* **A design-token registry** — the values a page used, made addressable and ownable.
* **A component registry** — reusable pieces, versioned, with update-safety rules.
* **A package format** — portable, importable, and safe against hostile input.
* **A design-system merge** — the thing §28 of the brief demands and most importers get wrong.

The organising principle is the brief's: **create reusable editable design assets from your
own projects and legally usable sources.**

That principle is enforced, not merely stated. See §6.

---

## 2. Architecture

```
ReplicaForge
  └── Workspace                      Phase 15, reused
        └── Project                  Phase 1, reused
              └── Generated page     Phase 4, reused
                    │
                    │  Template_Extractor reads what ReplicaForge already stored.
                    │  It fetches nothing and invents nothing.
                    ▼
              Template_Sanitizer    ← the only way in. Allowlist, not blocklist.
                    │
                    ▼
              Template_Validator     ← 5 states, 8 measured dimensions
                    │
                    ▼
              Template_Conflicts     ← detects before it writes; never overwrites
                    │
                    ▼
              Template_Installer     ← journal, rollback, post-install verify
                    │
                    ├── Template_Store             replicaforge_templates
                    ├── Template_Version_Store     replicaforge_template_versions
                    └── Template_Component_Store   replicaforge_template_components
```

### What was reused rather than rebuilt

| Concern | Existing system | How Phase 19 uses it |
|---|---|---|
| Storage | `Collaboration_Store` | The three stores *extend* it. Pagination, cursor, search, type coercion, public-id generation and the `prepare()` boundary are inherited, not reimplemented. |
| Tables | `Collaboration_Schema` | Three new `dbDelta` definitions, `VERSION` bumped to `19.0.0`. No new table-naming convention. |
| Permissions | `Permission_Manager`, `Workspace_Limits` | 9 new capabilities added to the existing closed vocabulary. No second permission path. |
| Tokens | `Token_Engine`, `Site_Design_System` (Phase 8/12) | `Design_Token_Registry` **normalises** their output. It re-derives nothing. |
| Structure | `Elementor_Document_Reader` (Phase 6) | Reads `_elementor_data` and `_elementor_responsive` out of an existing post. |
| Structure | `Elementor_Document_Builder` (Phase 4) | The only way a document is ever written. |
| CSS values | `Elementor_Values` | Every token value and setting value goes through it. |
| SSRF | `Security::is_safe_public_reference()` | Every URL in a template. See §7.2. |
| Elementor | `Elementor_Compatibility`, `Site_Compatibility` | Capability probing. Same seven features, no second detector. |
| Project versions | `Project_Repository::add_version()` | Wired up. It had no caller since Phase 1 — see §11. |
| Audit | `Collaboration_Log::audit()`, `Audit_Log::record()` | Both, for different audiences. See §9. |

### What is genuinely new

`includes/templates/` — 16 PHP files, one stylesheet:

| File | Responsibility |
|---|---|
| `class-replicaforge-template-limits.php` | Every closed vocabulary and ceiling. |
| `class-replicaforge-design-token-registry.php` | Token identity, ownership, merge, impact. |
| `class-replicaforge-content-slot-registry.php` | Slot definitions, defaults, fill validation. |
| `class-replicaforge-template-sanitizer.php` | The security gate. Allowlist widgets, allowlist settings, SSRF, redaction. |
| `class-replicaforge-template-dependencies.php` | Declared requirements resolved against this site. |
| `class-replicaforge-template-store.php` | `replicaforge_templates`. |
| `class-replicaforge-template-version-store.php` | `replicaforge_template_versions`. Append-only. |
| `class-replicaforge-template-component-store.php` | `replicaforge_template_components`. |
| `class-replicaforge-template-validator.php` | 5 states × 8 dimensions. |
| `class-replicaforge-template-extractor.php` | The §17 pipeline, composed from existing parts. |
| `class-replicaforge-template-conflicts.php` | Read-only detection + a plan. |
| `class-replicaforge-template-package.php` | Export/import, redaction, size bounds. |
| `class-replicaforge-template-installer.php` | The 7-step transactional install. |
| `class-replicaforge-template-quality.php` | 8 separate indicators. No aggregate score. |
| `class-replicaforge-template-api.php` | REST. |
| `class-replicaforge-template-admin.php` | Library + detail screens. |
| `css/templates-admin.css` | Screen styles, following the Phase 15/17 conventions. |

---

## 3. Design tokens

### 3.1 Why Phase 8 was not enough

`Token_Engine` (Phase 8) is excellent at *discovering* values — it walks a representation and
reports every colour, spacing step and shadow it can prove is in use, with the evidence. That
is the hard part and it was done.

A template ecosystem needs three things Phase 8 does not provide:

| Gap | Consequence without it |
|---|---|
| **No identity.** A token is keyed by position in a list. | §8's "if the user changes a global token, identify affected templates" has no key to join on. |
| **No ownership.** Nothing marks a value a person set. | A re-extraction would overwrite a deliberate choice. |
| **A dead `confidence`.** `Site_Design_System::pick_named()` reads `$token['confidence']`; `Token_Engine` never writes one, so every confidence silently fell back to a hard-coded `0.6`. | A confidence number that means nothing. |

`Design_Token_Registry` supplies those three and nothing else.

### 3.2 The token record

```json
{
  "token_id":    "colors.primary",
  "name":        "primary",
  "label":       "Primary",
  "category":    "colors",
  "value":       "#6C63FF",
  "unit":        "",
  "source":      "source_analysis",
  "confidence":  0.86,
  "scope":       "global",
  "version":     "19.0",
  "usage_count": 14,
  "ownership":   "user_controlled",
  "editable":    true,
  "disputed":    false,
  "evidence":    ["n4f2a", "n4f2b"],
  "role":        "color.primary",
  "modified_at": "2026-10-01T09:14:00+00:00",
  "modified_by": 1,
  "previous_value": "#101820"
}
```

`token_id` is `category.name`, derived from **name and category, never the value**. A design
system whose ids change when a colour changes cannot be referenced by anything.

### 3.3 Confidence is computed, and here is how

There is no model and no calibration beyond the arithmetic. Given:

* `$occurrences` — from the Phase 8 token
* `$evidence` — up to five distinct node ids
* `$disputed` — Phase 12 recorded pages disagreeing

```
base   = min(1.0, occurrences / 8)                  // saturating at 8 uses
base   = min(1.0, base + min(count(evidence),5)/5 * 0.2)
value  = 0.2 + base * 0.78
if disputed: value = max(0.5, value - 0.3)
```

A token seen once with no cross-page agreement is `0.2`. One seen many times across pages
approaches `0.98`. A contested one is floored at `0.5` and flagged.

**The claim this makes is only that a higher number means more evidence.** That is true by
construction. It never claims a token is correct.

### 3.4 Semantic roles are assigned from evidence, and `primary` is not

A design system whose tokens are only `color_a3f19b` is a swatch list. So tokens are bound to
roles, and the assignment rules are deliberately conservative:

* `color.background` and `color.text` — only where Phase 12 **already decided** them and the
  values agree. Phase 12 is the authority; Phase 19 does not second-guess it.
* `color.text`, `color.border`, `color.accent` — filled from remaining colours by usage, in
  that order, skipping any that is disputed.
* `shape.radius`, `spacing.section`, `layout.container`, `font.size_base` — the most-used token
  of each family.

**`color.primary` and `color.secondary` are not inferred.** There is no reliable signal for
"primary" from a rendered page — a site can have one brand colour used twice, or three equally
ranked accents. Guessing here is the single change that would re-point the most on a site when
someone edits the token later. They are reported as unassigned and set by hand.

### 3.5 Ownership is the mechanism, not metadata

`ownership` exists for one reason:

```
merge( workspace, tokens )
  user_controlled      → prior value KEPT, evidence refreshed, observed_value recorded
  theme_controlled     → prior value KEPT
  replicaforge_controlled → refreshed from the extraction
```

A user's chosen value is never undone by re-analysis. It also survives an import — see §8.3.

---

## 4. Content slots

### 4.1 The rule

**A template carries structure and slots. It does not carry the text and images that happened
to be on the page it came from.**

Three things break without it:

1. **Licensing.** §3 of the brief is explicit that images, logos, text and trademarks are not
   the user's to redistribute. A template embedding the source page's photography is a
   redistribution mechanism whatever the licence of the code around it.
2. **Reusability.** A hero reading "Acme Corporation — Established 1987" is not a reusable
   hero. It is one page.
3. **Honesty.** A template rendering "Lorem ipsum" because the slot was empty shows the user
   something that is not real, in a product whose entire value is that its output is real.

### 4.2 A source value is *recorded*, never adopted

```php
$slots = $registry->build(
    array( array( 'role' => 'heading', 'value' => 'Real heading from the source' ) ),
    array( 'keep_content' => false )
);

$slots['slots'][0]['default']        === ''                     // never auto-filled
$slots['slots'][0]['observed_value'] === 'Real heading from the source'   // recorded
$slots['slots'][0]['observed']       === true
```

This holds **even with `keep_content => true`**. The flag controls whether the source value is
carried as an observation, not whether it becomes a default. A default must come from the user,
from an imported package with its provenance, or from a dynamic binding.

### 4.3 The slot record

`slot_id`, `type`, `label`, `role`, `required`, `repeatable`, `kind` (`static` / `dynamic` /
`either`), `default`, `observed_value`, `observed`, `carried`, `dynamic_source`, `validation`,
`provenance`.

Phase 12's `slots` shape (`slot_id`, `filled`, `kind`, `label`, `repeatable`) is **consumed and
extended**, not replaced — the Phase 12 keys are all present and mean the same thing.

### 4.4 Dynamic content never substitutes

`Content_Slot_Registry::dynamic_availability()` is the only place availability is decided, so
the compatibility check and the installer cannot disagree.

| Source | Needs | If missing |
|---|---|---|
| `post_title`, `featured_image`, `post_author`, … | WordPress core | Always available |
| `product_price`, `product_image`, … | WooCommerce | Slot left empty. **Warned, never filled with something else.** |

Fifteen sources are declared; the six product ones are marked as requiring WooCommerce so the
compatibility check can say *"this template needs WooCommerce"* **before** install rather than
after.

### 4.5 Fill validation

`validate_fill()` enforces per-type rules: length caps, tag stripping, single-line collapsing,
`public_http` (which rejects `javascript:`, `data:`, and internal addresses), and image
extensions restricted to `jpg|jpeg|png|gif|webp`. A required slot cannot be left empty.

---

## 5. Components

### 5.1 Two different things, kept apart

| | Phase 12 `Component_Registry` | Phase 19 `Template_Component_Store` |
|---|---|---|
| Scope | per **project** | per **workspace** |
| Meaning | "these three pages share a header with this fingerprint" | a versioned, shareable, installable design asset |
| Lifetime | rewritten on every analysis | append-only versions |
| Edited by | analysis | a person |

Merging them would let a re-analysis silently rewrite a reusable asset — the exact failure §30
exists to prevent. The bridge is one-directional: `Template_Extractor` promotes a detection
into a component; nothing promotes it back.

### 5.2 A component stores its own subtree

The installer pulls the elements the component *claims* by id and stores those, with the
ancestor chain needed to reach them. Storing the whole page would produce a component that
installs cleanly, renders, and is completely wrong.

A component claiming element ids that are absent is **refused**, not stored empty.

Verified: a component claiming one button stores `container(button)` — not the heading, not the
text block from the same page.

### 5.3 Identical re-registration is not a version

```
register( workspace, payload )          → version 2
register( workspace, payload )          → unchanged: true, version stays 2
register( workspace, changed_payload )  → version 3, changelog entry
```

Without this, installing two templates containing the same component produced two versions, and
the version number became a count of references rather than a record of changes. §10 ties a
version to an update; nothing was updated.

The comparison is on the stored content hash, which covers document, tokens, slots, assets,
dependencies and compatibility — deliberately **not** the name or description, since renaming
is metadata and §10's changelog records dependency and compatibility deltas.

### 5.4 Update safety

`update_safety()` refuses to report a component update as safe when anything uses it:

```json
{
  "safe": false,
  "reason": "2 template(s) use this component. ReplicaForge cannot tell whether any of them
             were edited by hand since they were built, so choose which ones to update rather
             than applying this everywhere.",
  "manual_edits_detectable": false,
  "strategies": {
    "update_all": "...", "update_selected": "...", "keep_existing": "..."
  }
}
```

* `manual_edits_detectable` is **always false**. That is the honest answer, not a stub.
* **No `overwrite` strategy exists in the vocabulary**, so it cannot be requested.
* A downgrade is refused.

---

## 6. Assets and provenance — the line the whole phase turns on

### 6.1 Classes

| Class | Meaning | May be packaged? |
|---|---|---|
| `user_owned` | Created or licensed by this site | **Yes** |
| `licensed` | Licensed for redistribution, licence recorded | **Yes** |
| `placeholder` | Generated placeholder, no third-party content | **Yes** |
| `source_derived` | Taken from the analysed source website | **No** |
| `third_party` | Third party, rights unknown | **No** |
| `restricted` | Known to be restricted | **No** |

```php
const REDISTRIBUTABLE_PROVENANCE = array( 'user_owned', 'licensed', 'placeholder' );
```

This is the single most consequential line in Phase 19. It is enforced in
`Template_Sanitizer::scan_snapshot()` and `Template_Package::select_assets()` — **not in the
UI**.

### 6.2 Two independent downgrades, both fail-closed

1. **Extraction.** A source-site image is classified `source_derived` and its mode is forced to
   `reference`. The only way an image becomes `user_owned` is for it to already be in this site's
   own media library.
2. **Import.** A package claiming `mode: import` for a non-redistributable asset is downgraded
   to `reference` and the downgrade is reported.

### 6.3 Unknown rights means no rights

An asset arriving with no `provenance_class` is given `third_party` — not `user_owned`. A
malformed file therefore has to *defeat* the default to embed anything.

### 6.4 A template stays usable when assets are excluded

Referencing is how it stays usable. An export writes:

```json
"asset_hero": {
  "url": "https://example.com/hero.jpg",
  "provenance_class": "source_derived",
  "mode": "reference",
  "redistributable": false,
  "included": false,
  "note": "This image comes from the analysed website, so it is referenced rather than
           packaged. Replace it with your own media to make the template self-contained."
}
```

The template installs and works. It points at an image it does not own, and says so.

---

## 7. Security

### 7.1 Widgets: an allowlist, not a blocklist

`Elementor_Widget_Registry::is_allowed_widget()` is a **blocklist plus a live registration
check**:

```php
return is_string( $widget_name )
    && '' !== $widget_name
    && ! in_array( $widget_name, $this->forbidden_widgets, true )   // html, shortcode, embed, html-tag, custom-html
    && $this->compatibility->has_widget( $widget_name );
```

That is the right rule for **generation**, where ReplicaForge chooses every widget from its own
map. It is the wrong rule for **import**, where the widget name arrives from a file. Because it
is a blocklist, a template naming any widget that happens to be registered on the target site
passes.

`Template_Sanitizer` therefore imposes an allowlist, read from the registry's own
`widget_vocabulary()` so the two cannot drift:

```
container, heading, text-editor, button, image, divider, spacer, icon
```

A widget outside it is refused **even if the target site has it installed**, and the refusal
names which widget so the user learns what was lost rather than finding a blank space later.

### 7.2 Settings: an allowlist too

`Elementor_Document_Builder::scrub()` filters setting keys by *shape*
(`/^[a-z0-9_\-\[\]]{1,60}$/i`) and drops executable values. Shape-filtering is right when
ReplicaForge built the settings and knows the names. On import, a shape-valid key is not enough:
`_elementor_custom` or a plugin's own control could carry a script.

So the setting allowlist is the set of controls ReplicaForge itself writes, plus a separate
explicit `forbidden_settings()` list so that *hostile* and merely *unrecognised* are reported
differently — one tells the user whether the template was hostile or just from another tool.

### 7.3 URLs: the SSRF boundary

`is_executable()` catches `javascript:` and `data:text/html`; the path check catches traversal.
**Neither knows anything about `http://169.254.169.254/latest/meta-data/`**, which is a
perfectly ordinary http URL that reaches a cloud metadata service, or `http://10.0.0.5/`, which
reaches inside the network. `Elementor_Validator::scan_settings()` only rejects a handful of
*schemes*, so neither was stopped anywhere in the pipeline.

`Template_Sanitizer::is_url_setting()` identifies URL-bearing settings **by the setting name,
not by sniffing the value shape** — `image` is sometimes a string, sometimes a list, sometimes an
array of objects each with a `url`, and shape-sniffing is how a `javascript:` link inside a
nested structure gets missed. Every string in those values is then validated through
`Security::is_safe_public_reference()`, the existing SSRF boundary.

Verified removed: `169.254.169.254`, `10.0.0.5`, `127.0.0.1`, `data:image/svg+xml;base64,…`,
`../../../wp-config.php`, `javascript:alert(1)`, `<script>…</script>`.

### 7.4 A hostile *value* is dropped; the element is not

```
input : widget(button) { text: "Call to action", link: { url: "javascript:alert(1)" } }
output: widget(button) { }
        removals: [ { kind: "setting", key: "link", reason: "unsafe_url" } ]
```

Destroying the element would turn a template into a shorter template with holes. Dropping the
field gives a shorter template. And a setting that had content and now has none is **removed
rather than left as an empty shell**, because `image => array()` renders as a broken-image icon
in the editor and is indistinguishable from a genuine empty setting.

### 7.5 Redaction

Both export and import run a redaction pass:

* exact keys (`api_key`, `authorization`, `password`, `cookie`, `private_key`, …)
* **fragments** anywhere in a key (`api_secret`, `credential`, `bearer`, …) — a denylist of
  exact names is trivially defeated by a rename
* Elementor's raw post-meta keys (`_elementor_data`, `_elementor_controls`, …)

Everything removed is **reported**, so a user can see what was stripped rather than
discovering a broken template on the far side.

### 7.6 No archive, therefore no traversal

A package is **JSON, not ZIP**. §39 lists "ZIP/package traversal if package files are used" as
something to test; the honest answer is that the design removes the attack surface rather than
testing for it. A single JSON object has no filenames, so there is no `../` to traverse, no
symlink to follow, and no compression-ratio trick. A `PK`-prefixed payload does not crash the
reader (asserted).

Size is checked **before parsing**, because a small document with deeply repeated keys can
decode into something much larger — the encoded size is the bound that actually bounds memory.

### 7.7 What never happens

| Never | Enforced by |
|---|---|
| Imported PHP, JS, SQL or shell runs | No `eval`, no `unserialize`, no `call_user_func` on input, no shell. Zero occurrences in `includes/templates/`. |
| A script URL survives | `Elementor_Values::is_executable()` + §7.3 |
| An internal address survives | §7.3 |
| A forbidden widget installs | §7.1 |
| A newer-schema package installs | `version_compare` against `Template_Limits::SCHEMA_VERSION` |
| An existing template is silently overwritten | §8 |
| A `user_controlled` token is changed by an import | §8.3 |
| A user's page or draft is deleted | Rollback removes only ReplicaForge's own orphan draft |

---

## 8. Import, export, conflicts

### 8.1 A package

```json
{
  "schema_version": "19.0",
  "engine_version": "1.0",
  "exported_at": "2026-10-01T09:14:00+00:00",
  "exported_by": 1,
  "template": {
    "name": "Product hero",
    "description": "A reusable hero template.",
    "type": "hero",
    "version": 3,
    "version_id": "01JQ8Z…",
    "tags": ["hero", "product"]
  },
  "document": {
    "elements": [
      {
        "id": "aaaaaa1",
        "elType": "container",
        "isInner": false,
        "settings": { "background_background": "classic", "background_color": "#101820" },
        "elements": [
          { "id": "aaaaaa2", "elType": "widget", "widgetType": "heading",
            "settings": { "title": "", "header_size": "h2", "align": "center" },
            "elements": [] },
          { "id": "aaaaaa3", "elType": "widget", "widgetType": "text-editor",
            "settings": { "editor": "" }, "elements": [] },
          { "id": "aaaaaa4", "elType": "widget", "widgetType": "button",
            "settings": { "text": "", "link": { "url": "", "is_external": "" } },
            "elements": [] }
        ]
      }
    ]
  },
  "responsive":  { "devices": ["desktop","tablet","mobile"], "sections": { "aaaaaa1": { "tablet": {}, "mobile": {} } } },
  "interactions": { "reference": true, "model_id": "int_9f2a", "total": 4, "mapped": 4 },
  "design_system": { "families": { "colors": [] }, "roles": {} },
  "tokens": {
    "colors.primary": { "token_id": "colors.primary", "name": "primary", "category": "colors",
      "value": "#6C63FF", "unit": "", "source": "project_design_system", "confidence": 0.86,
      "scope": "global", "ownership": "user_controlled", "editable": true,
      "usage_count": 14, "role": "color.primary" }
  },
  "components": { "product_cta": { "component_id": "product_cta", "type": "cta", "element_ids": ["aaaaaa4"] } },
  "content_slots": [
    { "slot_id": "heading_1", "type": "heading", "label": "Heading", "required": true,
      "kind": "dynamic", "dynamic_source": "post_title", "default": null,
      "observed_value": "Acme — Established 1987", "observed": true,
      "validation": { "max_length": 160, "strip_tags": true, "single_line": true },
      "provenance": { "origin": "source_page", "url": "https://example.com/" } }
  ],
  "assets": { "asset_hero": { "url": "https://example.com/hero.jpg",
    "type": "image", "provenance_class": "source_derived", "mode": "reference",
    "redistributable": false, "included": false } },
  "dependencies": {},
  "compatibility": {
    "wordpress": { "minimum": "6.2", "found": "7.1.2" },
    "php": { "minimum": "7.4", "found": "8.4.25" },
    "elementor": { "minimum": "3.16", "tested": "4.3.2", "available": true },
    "woocommerce": { "required": false, "found": false },
    "features": ["containers", "flexbox", "global_colors", "responsive"],
    "maker": { "plugin": "1.5.0", "phase": "19.0" }
  },
  "provenance": {
    "origin": "exported", "author": "Site Owner", "url": "https://example.com/",
    "created_at": "2026-10-01T09:14:00+00:00", "licence": "All rights reserved",
    "maker": { "plugin": "1.5.0", "phase": "19.0" }
  },
  "validation": { "state": "valid", "dimensions": {} }
}
```

Note `document` is **required**. An earlier version omitted it and exported everything else,
producing a plausible-looking complete package that installed as an empty page; `inspect()`
now rejects a package with no `document` as malformed rather than letting it surface later as a
security-scan failure.

### 8.2 The nine import steps

`Template_Package::inspect()` runs seven and names the eighth:

| # | Step | On failure |
|---|---|---|
| 1 | `validate_package` | malformed / unversioned / from the future → refuse |
| 2 | `security_scan` | anything fatal → refuse, nothing installed |
| 3 | `schema_validation` | |
| 4 | `compatibility_check` | reported; blocking only if unmet |
| 5 | `dependency_resolution` | reported |
| 6 | `asset_review` | rights noted per asset |
| 7 | `conflict_detection` | workspace-scoped, so the caller's step |
| 8 | `user_confirmation` | |
| 9 | `install` | transactional |

### 8.3 Conflicts: detected before, planned against a fresh read

`Template_Conflicts` is read-only. It has no write path.

| Kind | Severity | Notes |
|---|---|---|
| `template_name` | review | Never an error on its own. Nothing is replaced. |
| `component_id` | review | "Already at version N and used by existing templates." |
| `token_value` | warning | Value differs and nobody set it by hand. |
| `token_name` | **review** | Value differs **and** it is `user_controlled`. A value someone chose is not replaced by an import. |
| `capability` | **error** | Blocking. |

Strategies — and note what is **absent**:

```
keep_existing · new_version · rename · merge_tokens · manual
```

There is **no "overwrite"**, in the vocabulary or in the resolver. §27 and the Phase 19 stop
conditions both forbid silently overwriting an existing reusable asset, and a strategy that can
be requested is a strategy that will eventually be requested.

A `token_*` conflict is additionally forced from `new_version` to `keep_existing`, because a
design token has no versions. Accepting a strategy that has no meaning for that kind would make
the plan report success while changing nothing.

The plan is computed from a **fresh** detection, not the list the user saw — between the two
requests a colleague may have created a template with the same name.

### 8.4 Design-system merge is never a side effect

Installing a template does **not** merge its tokens into the workspace design system.

```php
$installer->install( $workspace, $snapshot, array( 'merge_tokens' => false ) );
// → "This template carries 3 design tokens, which were not added to the site design system."
```

Merging re-points every other template on the site, which §8 and §28 both forbid happening as a
by-product. When merging *is* requested, `user_controlled` tokens in the destination are still
preserved by `merge()`.

---

## 9. Permissions

Nine capabilities, added to `Workspace_Limits::CAPABILITY_GROUPS` — **not** to a private list.
`Permission_Manager::can()` validates against that one closed vocabulary, so a capability
existing only in `Template_Limits` would be refused by the manager: the button would show and
always 403.

```
templates.view  templates.create  templates.edit   templates.delete
templates.export  templates.import  templates.share  templates.install
design_systems.manage
```

| Role | Grants |
|---|---|
| `admin`, `project_manager` | all nine |
| `designer`, `developer` | view, create, edit, export, install |
| `reviewer` | view |
| `client` | **none** |

Designers and developers are deliberately **not** granted:

* `templates.import` — an imported package is untrusted input, and admitting untrusted input is
  not a design task.
* `templates.share` — publishing to the whole workspace is a decision for someone accountable.
* `templates.delete` — destructive.
* `design_systems.manage` — re-points every template at once.

A client gets no template capability at all. `client_review` visibility exists for the *pages* a
client reviews, not for the template library.

### 9.1 Refusals are shaped to avoid mapping the id space

```
unauthenticated          → 401
wrong workspace          → 404, not 403
another user's private  → 404, not 403
```

A 403 confirms the template exists; the id space is then enumerable. Every refusal in
`Template_Api` and `Template_Admin` says "not available".

A `client_review` template is readable by a client and writable by **nobody but its owner**.

### 9.2 Two audit destinations, deliberately

| Destination | Why |
|---|---|
| `Collaboration_Log::audit()` | The **workspace** audit trail, in `replicaforge_audit`, read by whoever administers that workspace. "Who shared this template" is only meaningful relative to the workspace it was shared into. |
| `Audit_Log::record()` | The **site-wide** commercial log, with its own vocabulary. A template event outside that vocabulary is recorded under a generic action rather than invented into Phase 10's contract. |

---

## 10. Versioning

### 10.1 Stored versions are immutable

`Template_Version_Store` has **no public update method**. `append()` only inserts. The version
number comes from the highest *stored* version, not from a count — the same rule
`Project_Repository::add_version()` uses, for the same reason: once the list is trimmed the two
stop agreeing, and counting would eventually hand the same number to two versions.

Retention is `MAX_VERSIONS = 20`, matching `Project_Repository::MAX_VERSIONS` deliberately. A
template and a project version are different entities with the same retention policy, and two
different numbers would mean the same user-facing question has two answers.

### 10.2 Integrity

Every snapshot is hashed on write and re-checked on read, following `Workflow_Artifacts`.

**The hash covers what was actually stored.** This took a fix: `prepare_row()` →
`coerce()` runs every JSON section through `Data_Redactor::structure()`, which is a *sanitiser*,
so `<p>text</p>` is stored as `text`. Hashing the pre-sanitiser snapshot meant `verify()`
could never match a hash taken before the sanitiser ran — which refused every template
containing rich text, and named corruption as the cause when the file was intact. The row is now
prepared before the hash is taken.

Consequence, stated plainly: the hash detects a partial write or a hand-edited **row**, which is
what it is for. It does not detect a sanitiser that changed the payload, because the sanitiser
is part of the write and its output is the intended content.

### 10.3 Archiving is reversible

`archived` is a soft delete. Versions are kept. A template that was superseded is different from
one that was abandoned, and destroying its history because someone tidied up is not recoverable.

Deleting a template purges its versions — called only from an explicit, already-authorised
delete. Pages already built from it are untouched.

---

## 11. Versioning integration

§11 asks to integrate with existing Phase 17/18 versioning rather than build a second system.

`Project_Repository::add_version()` had **no production caller since Phase 1** — which is why
Phase 15's approval gates always read `status = none`. Phase 19 wires it:

```php
$repository->add_version( $project_id, array(
    'change'  => 'template_extracted',
    'impact'  => 'none',
    'note'    => 'Extracted a reusable template from the generated page (128 elements).',
    'analysis' => $design_system,
) );
```

Extracting a template is a real change to a project, so it is recorded as one — in the existing
project version history, not a parallel one.

Template versions are a *different entity* with their own lifecycle, so they get their own
table. That is not a duplicate versioning system; a duplicate would be two ways to answer "what
did this template look like on the 3rd", and there is exactly one.

---

## 12. Compatibility

`Template_Dependencies` answers "what does this need" and "what does this site have" in **one
pass**, because §19 and §20 are the same question from two sides and answering them separately
produces two answers that can disagree.

Four states per requirement: `available`, `missing`, `incompatible`, `optional`.

| Requirement | Blocked when unmet? |
|---|---|
| WordPress / PHP minimum | Yes |
| Elementor unavailable | Yes |
| Elementor too old | Yes |
| WooCommerce required and absent | Yes |
| Unknown Elementor feature | **Yes** — reported as unmet, not assumed |
| Widget outside the allowlist | Yes (the security check removes it) |
| Widget not registered here | Yes |
| `responsive` capability | **No** — degrades. A template with no tablet/mobile overrides still renders, at desktop layout. |

**It never installs anything.** There is no code path from a "missing" finding to a download.
The strongest thing it will ever do is *block* an install and say why.

Compatibility is **declared at extraction time from the environment that produced it** — the
only point at which the values are true.

---

## 13. Validation

Five states, not two, because the reason a template is *incompatible* is materially different
from the reason it is *invalid*, and an operator debugging a failed install needs to be told which
they are looking at.

| State | Meaning | Installable |
|---|---|---|
| `valid` | Nothing found | Yes |
| `valid_warnings` | Information only | Yes |
| `needs_review` | A person must decide | **No** |
| `incompatible` | Wrong site | No |
| `invalid` | Broken or hostile | No |

`needs_review` is separate from `valid_warnings` because a warning is information ("three assets
are referenced but not included" is fine) and a review demand is a question ("the primary colour
is disputed across the pages this came from" means somebody has to decide).

**`incompatible` is checked before `invalid`.** A template that cannot run here *and* has a
problem is an incompatible template on this site; telling someone their file is corrupt when the
file is fine sends them to fix the wrong thing.

Eight dimensions, each measured or explicitly `not_available`: `structure`, `design`,
`responsive`, `interaction`, `assets`, `security`, `compatibility`, `elementor`.

**It never repairs.** Cleaning is the sanitizer's job and it runs *before* this. A validator
that fixed what it found would report success on a template it had quietly changed.

---

## 14. Quality indicators — eight, and no total

§33 forbids a single score, and the reason is that one invites the comparison it prevents.
"A scores 82, B scores 61" reads as *A is better* and invites choosing on the number. It also
hides which dimension is weak: excellent structure with no design tokens scores the same as a
full design system with thin structure.

| Indicator | Measured from |
|---|---|
| `structural_completeness` | top-level sections containing elements |
| `responsive_completeness` | sections carrying a device override |
| `component_reuse` | component-claimed elements ÷ total elements |
| `token_coverage` | colour/type settings referring to a token ÷ settings that could |
| `interaction_coverage` | mapped ÷ detected interactions |
| `asset_validity` | safe ÷ total asset references |
| `elementor_compatibility` | declared minimum vs installed |
| `content_separation` | dynamic ÷ fillable slots |

Each is a real number derived from stored records, or `not_available`. **"Not available" is not
a failure and not a zero** — it means the question could not be answered from what this site
holds. A fabricated `0%` looks like a finding; an absent measurement does not.

There is no aggregate. `measured` and `unmeasured` counts are reported, and a note explains why
they are not combined.

---

## 15. Transactional install

`Template_Limits::INSTALL_STEPS`, run in order, each reporting:

```
prepare → validate → resolve_dependencies → create_snapshot → install → post_validate → commit
```

Every write is journalled *before* it happens and undone in reverse if a later step fails.

The version row is written **before** the template row points at it. The reverse order would
leave a template whose `current_version` names a row that does not exist — the one inconsistency
the journal cannot undo cleanly, because the undo would have to decide which of the two was the
mistake.

### What rollback does and does not undo

| Undo | Applies to |
|---|---|
| `delete_template` | A template row + its versions |
| `archive_component` | A component this install created |
| `restore_tokens` | A design-system merge |
| `delete_draft` | **Only** an orphan draft created in this install and never returned to the user |

**User content is never deleted on a rollback path.** A draft a user has been shown is theirs;
deleting a post on a rollback is how a plugin becomes terrifying. The exception is a draft
ReplicaForge created in *this* install and never surfaced — that is ReplicaForge's own debris,
and an orphan draft per failed install is its own unbounded-growth path.

A failed undo is reported loudly: *"An installation was rolled back, but some writes could not
be undone."*

Verified: a refused incompatible install leaves the library count **unchanged**; a journalled
write is undone with its versions; a token merge is reverted.

---

## 16. REST API

Base `replicaforge/v1/templates`. Every route: authenticate, verify the workspace capability,
verify template ownership, validate ids, validate schemas, sanitise input, escape output,
enforce limits, prevent IDOR, audit.

| Method | Route | Capability |
|---|---|---|
| `GET` | `/library` | `templates.view` |
| `POST` | `/extract` | `templates.create` |
| `GET` | `/(?P<id>[A-Za-z0-9]{4,26})` | `templates.view` |
| `POST` | `/(?P<id>…)` | `templates.edit` |
| `DELETE` | `/(?P<id>…)` | `templates.delete` |
| `POST` | `/(?P<id>…)/validate` | `templates.view` |
| `GET` | `/(?P<id>…)/versions` | `templates.view` |
| `GET` | `/(?P<id>…)/compare?from=&to=` | `templates.view` |
| `GET` | `/(?P<id>…)/export` | `templates.export` |
| `POST` | `/(?P<id>…)/archive` | `templates.edit` |
| `POST` | `/(?P<id>…)/restore` | `templates.edit` |
| `POST` | `/(?P<id>…)/share` | `templates.share` |
| `POST` | `/import/inspect` | `templates.import` |
| `POST` | `/import/install` | `templates.import` |
| `GET` | `/components` | `templates.view` |
| `GET` | `/components/(?P<id>[a-z0-9_]{2,64})/update-safety` | `templates.edit` |
| `GET` | `/tokens` | `design_systems.manage` |
| `POST` | `/tokens` | `design_systems.manage` |
| `GET` | `/tokens/(?P<id>…)/impact` | `design_systems.manage` |

### `POST /tokens` requires a second request

§8 requires the affected resources be shown and confirmation required. So the route does **not**
change anything on its own:

```
POST /tokens  { workspace, token_id, value }
  → 200 { changed: false, requires_confirmation: true, impact: { templates: […], components: […], manual_edits_possible: true, note: "…" } }

POST /tokens  { workspace, token_id, value, impact_confirmed: true }
  → 200 { changed: true, … }
```

A caller that skips the second request gets the answer, not the change.

### `/extract` requires provenance

Extraction is refused from a page ReplicaForge did not generate:

```
409 template_not_generated
"That page was not generated by ReplicaForge, so it has no design provenance to extract from."
```

A post id alone is a guessable integer, so extraction from an arbitrary page is an IDOR until
`authorise_post()` checks the `replicaforge_generation_id`, that the project belongs to this
workspace, and that the user may view it.

`save: false` is a **preview** — nothing is written, so no journal is needed.

### `/compare`

Reports `added` / `removed` / `modified` / `unchanged` across `layout`, `components`, `tokens`,
`typography`, `responsive`, `interactions`, `assets`, `content_slots`.

Not delegated to Phase 5's comparators: those compare *source vs generated page*, and translating
a template structure into a page representation first would lose exactly the detail §22 asks
about.

---

## 17. Storage

Three tables, `VERSION = 19.0.0`. Additive — `install()` re-runs `dbDelta` over every definition,
so an existing install gains the three new tables and the thirteen existing ones are untouched.

| Table | Holds | Key indexes |
|---|---|---|
| `replicaforge_templates` | Metadata. **No document** — that is in the version row. | `(workspace_id, status, updated_at)`, `(project_id, status)`, `(workspace_id, type)`, `user_id` |
| `replicaforge_template_versions` | The snapshot. Append-only. | `UNIQUE (template_id, version)`, `(template_id, created_at)`, `user_id` |
| `replicaforge_template_components` | Reusable components + changelog | `(workspace_id, type)`, `(workspace_id, component_id)` |

The document is deliberately **not** in `templates`. A library list renders a row per template;
a document there would make listing templates mean loading every document — the §41 requirement
to not load every template into memory.

`UNIQUE (template_id, version)` is on the **pair**, not on `template_id` alone, so trimming old
versions cannot collide.

### Live token store

`replicaforge_design_tokens` option, keyed by workspace then token id, bounded at
`MAX_DESIGN_SYSTEMS = 50`. It holds the *current* token set so "what is the primary colour right
now" is answerable without loading every template, and so a user's override persists even if no
template is re-extracted.

### Ceilings

| Limit | Value | Source |
|---|---|---|
| Templates per workspace | 500 | `MAX_TEMPLATES` |
| Versions per template | 20 | `MAX_VERSIONS` (matches project) |
| Components per workspace | 600 | `MAX_COMPONENTS` |
| Component versions | 10 | `MAX_COMPONENT_VERSIONS` |
| One version | 512 KB | `MAX_VERSION_BYTES` (matches `Workflow_Artifacts`) |
| One package | 2 MB | `MAX_PACKAGE_BYTES` |
| Tokens per design system | 400 | `MAX_TOKENS` |
| Element depth | 8 | `Template_Sanitizer::MAX_DEPTH` |
| Elements per document | 2000 | `Template_Sanitizer::MAX_ELEMENTS` |

---

## 18. Migration

`Migrator::run()`: `15.0.0 → 19.0.0`.

Phases 16–18 shipped without a schema change, so there is no 16/17/18 schema to step through
and a site at 15.0.0 and a fresh install both arrive by one step.

`migrate_templates()` does exactly one thing with side effects: re-runs
`Collaboration_Schema::install()`. It then re-grants the plugin's WordPress roles (idempotent)
and records the migration report in `replicaforge_template_migration`.

**It creates no templates and extracts nothing.** A migration that invented templates would put
content in a user's library they did not ask for, and §17 and §33 both forbid fabricating a
design asset. The report says so: *"The template library is empty. Templates are created from
your own projects."*

A failed install **throws**, so `Migrator::run()` does not advance the version and the next
request retries.

The workspace template capabilities need no backfill: they are granted by role at
permission-check time from `Workspace_Limits::ROLE_CAPS`, so there is no stored row. The
migration proves that rather than asserting it.

---

## 19. Marketplace foundation — metadata only

§31 asks for architecture that could support a marketplace and forbids implementing one.

Present: template metadata, author metadata, version metadata, compatibility metadata, package
validation, provenance, **licence metadata**, and `maker: { plugin, phase }`.

Absent: payments, public listings, revenue sharing, seller accounts. **There is no route through
which a template becomes public.** `licence` records what the *exporter* asserts; it grants
nothing.

---

## 20. AI

§32's boundary is respected by omission: no AI is called anywhere in Phase 19. Naming,
categorisation, grouping and slot suggestion were specified as *may*; a suggestion that invented
a token value or a content default would violate the more important rule, so nothing was wired.

If added later, the constraint is already in the model: `Design_Token_Registry::clean_value()`
and `Content_Slot_Registry::validate_fill()` are the only two doors a value enters through, and
both reject anything unprovable.

---

## 21. Verification

### What was run

```
php -l  272 files, 0 failures

Phase 19 suite:  tests/phase19-templates-test.php
                 265 assertions, 0 failed, 0 warnings
                 verified re-runnable: two consecutive runs, same result

Targeted E2E probe (12 §40 scenarios + security cases)
                 248 assertions, 0 failed, 0 warnings

Full regression suite: 26 suites, 6340 assertions passed, 0 failed, 2 skipped
                        (the 2 skips are pre-existing and unrelated)
```

### The twelve §40 scenarios

| # | Scenario | Result |
|---|---|---|
| 1 | Create a section template from an existing page | Pass |
| 2 | Create a complete page template | Pass |
| 3 | Extract reusable design tokens | Pass |
| 4 | Reuse a component on another page | Pass |
| 5 | Export a template package | Pass |
| 6 | Import the package into another project | Pass |
| 7 | Handle a missing Elementor capability | Pass |
| 8 | Handle a token conflict | Pass |
| 9 | Update a component, preserving manual edits | Pass |
| 10 | Unauthorized cross-workspace access | Pass |
| 11 | Import malicious template data | Pass |
| 12 | Roll back a failed installation | Pass |

### §39 security cases

| Case | Result |
|---|---|
| Malicious template JSON | Refused — forbidden widget only |
| XSS (`<script>` in a setting) | Value dropped, element kept |
| `javascript:` URL | Dropped |
| Link-local metadata address | Dropped |
| Internal private address | Dropped |
| `data:` URL | Dropped |
| Path traversal | Dropped |
| Credential keys in a package | Redacted, reported |
| Cross-workspace read | 404, not 403 |
| Unauthorized export | 404 |
| Unauthorized import | Refused |
| SVG risk | Via `data:` and extension rules |
| Arbitrary Elementor configuration | Setting allowlist |
| Malicious dynamic tags | Source allowlist |
| Zip/package traversal | N/A — JSON, not an archive; `PK` payload asserted harmless |
| Oversized package | Refused **before parsing** |
| Resource exhaustion | Depth 8, 2000 elements, 512 KB / 2 MB |
| Dependency spoofing | Unknown feature treated as unmet |
| Version downgrade | `version_compare` refusal |
| Stale permissions | Re-detected conflicts; live permission checks |
| Template-sharing abuse | Client has no capability; `client_review` is owner-writable only |

### 21.2 Known limitations

Stated plainly, because the alternative is a report that overstates the work.

1. **`replicaforge_analysis` is still never written.** Phase 19's extractor reads it from three
   durable homes in priority order and **reports which one it used**, so extraction works — but
   the four Phase 12/13/14 consumers that read that post meta are still reading an empty array.
   Fixing it means writing the representation at generation time, which is Phase 4's change, not
   this phase's. Recorded, not fixed.
2. **Section templates cannot be extracted at a sub-page granularity.** A template is extracted
   from a whole generated page. `template_type` says *what* it is; there is no "extract this one
   section" affordance yet, which the brief's §17 workflow implies.
3. **Component updates are reported, not applied.** `update_safety()` names affected consumers
   and offers strategies; there is no `apply_component_update()`. Applying one would require
   rewriting consumer documents, and §30 requires never overwriting manual edits — which cannot
   be verified from stored data.
4. **Token change impact is computed from the registry, not from rendered pages.** The count is
   of *known* consumers. A template's settings still carry literal hex values, not `{{token}}`
   references, so `token_coverage` is near zero for real extractions and a global token change
   does **not** currently repoint installed templates. The registry, ownership rules, impact
   report and confirmation gate are all in place; the binding step is not. This is the largest
   gap between what §8 describes and what ships.
5. **Design-system merge writes the live registry only.** It does not push values into Elementor
   global colours or into already-installed template documents.
6. **No visual preview render.** `Template_Api` returns the three viewports in the preview
   payload and the document itself; rendering is Phase 13's `Visual_Renderer` and is not wired
   to templates. There is no interactive preview, hover state, accordion or carousel preview.
7. **Multi-site is untested.** Every Phase 15 store is workspace-scoped and would need the
   per-site table prefix verified.
8. **`uninstall.php` drops the three new tables.** Verified, not assumed. Phase 18's policy
   preserves `replicaforge_projects` on uninstall because generated Elementor drafts are real
   posts that outlive the plugin — but a template version is stored *inside* this plugin's own
   tables, so leaving them behind means a library with no owner. `uninstall.php` keeps a literal
   copy of the table vocabulary so the drop still works when the classes are already gone, and
   `tests/phase19-templates-test.php` asserts the literal and `Workspace_Limits::table()` agree
   for all three, so a future table cannot be added to one and forgotten in the other.
9. **The three `new_version`/`merge_tokens` strategies are plannable, not applied.** `plan()`
   reports them; only `keep_existing`, `rename` and manual review have an implemented effect.

### 21.3 Corrections to carried-forward findings

Two claims from earlier phases were wrong and are corrected here:

* **`Project_Repository::create()` DOES record an owner** — `'user_id' => get_current_user_id()`
  at `class-replicaforge-project-repository.php:109`. The earlier carried-forward claim that it
  writes `user_id => 0` was wrong.
* **`Project_Repository::add_version()` had no caller.** Confirmed and now wired (§11).

---

## 22. Extension points

| Hook | Where |
|---|---|
| New template type | `Template_Limits::TEMPLATE_TYPES` — one place, read by every predicate |
| New slot type | `Template_Limits::SLOT_TYPES` + `Content_Slot_Registry::role_to_slot()` |
| New semantic role | `Template_Limits::SEMANTIC_ROLES` |
| New asset class | `Template_Limits::ASSET_PROVENANCE` **and** decide it in `REDISTRIBUTABLE_PROVENANCE` |
| New Elementor feature | `Template_Limits::ELEMENTOR_FEATURES` (must match `Site_Compatibility`) |
| New capability | `Workspace_Limits::CAPABILITY_GROUPS` **and** `ROLE_CAPS` — not `Template_Limits` alone |
| New conflict strategy | `Template_Limits::CONFLICT_STRATEGIES` |
| Widening the import allowlist | `Elementor_Widget_Registry::widget_map()` — Phase 19 reads it, so widening there widens both |
| Custom extraction | `Template_Extractor::extract()` returns a snapshot; anything that produces a snapshot can be installed by `Template_Installer` |
| Custom validation | `Template_Validator::validate()` returns a report; `Template_Quality::measure()` reads stored data |

The one rule: **add vocabularies to `Template_Limits`, not to the class that consumes them.**
Every consumer reads the one list, so a value added anywhere is checkable everywhere.

---

## 23. Not implemented

Named so nothing here is mistaken for shipped:

* Public marketplace, payments, seller accounts, revenue sharing.
* Browser-rendered template preview; interactive/hover/accordion/carousel preview.
* Applying a component update to consuming templates.
* Pushing a token change into installed templates or Elementor global styles.
* Sub-page section extraction.
* Automatic design-system merge on template install.
* A second job queue, permission system, rendering engine or versioning system.
* Multi-site verification.