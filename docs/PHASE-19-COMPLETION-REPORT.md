# Phase 19 — Implementation Report

**Advanced Template, Design System & Reusable Asset Ecosystem**

Plugin `1.5.0` · Template schema `19.0` · Engine `1.0` · DB schema `19.0.0`

---

## 1. Phase 19 summary

Phase 19 turns ReplicaForge's reconstruction output into a reusable design-asset library:
templates, a design-token registry, a versioned component registry, a portable package format,
and a controlled design-system merge.

The organising constraint, taken from the brief and enforced in code rather than stated in
prose: **create reusable editable design assets from your own projects and legally usable
sources.** A template may carry structure freely. It may not redistribute someone else's
photography, and the single line
`REDISTRIBUTABLE_PROVENANCE = ['user_owned', 'licensed', 'placeholder']` is what stops it.

Sixteen new classes in `includes/templates/`, three new tables, nine new capabilities, nineteen
REST routes, two admin screens, one test suite, one reference document.

**Reuse over duplication was the hardest constraint and the most consequential decision.** The
audit that preceded implementation found Phase 12 already shipping a `Component_Registry`,
`Token_Engine`, `Site_Design_System` and `Asset_Registry`, and Phase 4/6 already shipping the
only safe document build path, the only document read path, and a CSS-value policy. Every one of
those is used. Nothing in Phase 19 re-derives a token, rebuilds a document, or validates a CSS
value by its own rules.

---

## 2. Architecture changes

```
Workspace (Phase 15, reused)
  └── Project (Phase 1, reused)
        └── Generated page (Phase 4, reused)
              │
              │  Template_Extractor reads what ReplicaForge already stored.
              │  Fetches nothing. Invents nothing.
              ▼
        Template_Sanitizer      ← the only way in
              ▼
        Template_Validator      ← 5 states × 8 measured dimensions
              ▼
        Template_Conflicts      ← read-only; detects before it writes
              ▼
        Template_Installer      ← journal → rollback → post-install verify
              │
              ├── Template_Store            replicaforge_templates
              ├── Template_Version_Store    replicaforge_template_versions
              └── Template_Component_Store  replicaforge_template_components
```

Three shared-layer changes were needed, each additive to an existing mechanism rather than a
parallel one:

| Change | File | Nature |
|---|---|---|
| 9 capabilities + 3 table kinds | `Workspace_Limits` | Added to the existing closed vocabulary |
| 3 table definitions, `VERSION` → `19.0.0` | `Collaboration_Schema` | `dbDelta` additions |
| Generalised "one of several" filters, 5 new `order_by` columns | `Collaboration_Store` | Replaces overriding `query()` three times |
| `widget_vocabulary()` accessor | `Elementor_Widget_Registry` | Exposes the existing map rather than reflecting into it |

---

## 3. Files created

### `includes/templates/` — 16 classes + 1 stylesheet, 13,025 lines

| File | Lines | Responsibility |
|---|---|---|
| `class-replicaforge-template-limits.php` | 1036 | Every closed vocabulary and ceiling |
| `class-replicaforge-design-token-registry.php` | 931 | Token identity, ownership, merge, impact |
| `class-replicaforge-template-admin.php` | 1552 | Library + detail screens |
| `class-replicaforge-template-api.php` | 1770 | REST |
| `class-replicaforge-template-installer.php` | 759 | Transactional 7-step install |
| `class-replicaforge-template-sanitizer.php` | 753 | Security gate |
| `class-replicaforge-template-store.php` | 754 | `replicaforge_templates` |
| `class-replicaforge-template-package.php` | 731 | Export/import, redaction |
| `class-replicaforge-template-extractor.php` | 713 | The §17 pipeline |
| `class-replicaforge-template-component-store.php` | 641 | `replicaforge_template_components` |
| `class-replicaforge-template-validator.php` | 610 | 5 states, 8 dimensions |
| `class-replicaforge-template-quality.php` | 579 | 8 indicators, no total |
| `class-replicaforge-template-version-store.php` | 571 | Append-only snapshots |
| `class-replicaforge-template-dependencies.php` | 473 | Requirements vs environment |
| `class-replicaforge-template-conflicts.php` | 435 | Detection + planning |
| `class-replicaforge-content-slot-registry.php` | 523 | Slots, defaults, fill validation |
| `css/templates-admin.css` | 194 | Screen styles |

### Other

| File | Lines |
|---|---|
| `tests/phase19-templates-test.php` | 982 |
| `docs/TEMPLATES.md` | 1174 |

---

## 4. Files modified

| File | Change |
|---|---|
| `replicaforge.php` | 16 requires, placed after the workspace block because the stores `extend Collaboration_Store` |
| `includes/class-replicaforge-plugin.php` | `template_api` + `template_admin` properties, wiring, `template_api()` accessor |
| `includes/class-replicaforge-schema.php` | `DB_SCHEMA_VERSION` → `19.0.0` |
| `includes/class-replicaforge-migrator.php` | `15.0.0 → 19.0.0` entry + `migrate_templates()` |
| `includes/workspace/class-replicaforge-workspace-limits.php` | `templates` capability group; 3 table kinds |
| `includes/workspace/class-replicaforge-collaboration-schema.php` | 3 table definitions; `VERSION` → `19.0.0` |
| `includes/workspace/class-replicaforge-collaboration-store.php` | generalised `in`-filters; 5 new `order_by` columns |
| `includes/elementor/class-replicaforge-elementor-widget-registry.php` | `widget_vocabulary()` |
| `uninstall.php` | 3 new tables in both the literal list and the drop loop |
| `tests/phase15-collaboration-test.php` | table-count assertions replaced with per-table assertions |
| `docs/API.md`, `docs/DATABASE.md` | Phase 19 sections + migration chain |

---

## 5. Database changes

Three additive tables. `install()` re-runs `dbDelta` over every definition, so an existing
install gains them and the thirteen Phase 15 tables are untouched.

| Table | Holds | Key indexes |
|---|---|---|
| `replicaforge_templates` | Metadata only. **No document.** | `(workspace_id, status, updated_at)`, `(project_id, status)`, `(workspace_id, type)`, `user_id` |
| `replicaforge_template_versions` | The snapshot. Append-only. | `UNIQUE (template_id, version)`, `(template_id, created_at)`, `user_id` |
| `replicaforge_template_components` | Components + bounded changelog | `(workspace_id, type)`, `(workspace_id, component_id)` |

The document is deliberately not in `templates`: the library list renders one row per template,
so a document there would make *listing* templates mean loading every document.

One option added: `replicaforge_design_tokens`, the live per-workspace token registry, bounded at
50 design systems.

Verified on this site: 16 tables present, 0 missing.

---

## 6. Schema versions

| | Before | After |
|---|---|---|
| `Collaboration_Schema::VERSION` | `15.0.0` | **`19.0.0`** |
| `Schema::DB_SCHEMA_VERSION` | `15.0.0` | **`19.0.0`** |
| `Template_Limits::SCHEMA_VERSION` | — | **`19.0`** |
| `Template_Limits::ENGINE_VERSION` | — | **`1.0`** |
| Plugin version | `1.5.0` | `1.5.0` (unchanged) |

`15.0.0 → 19.0.0` is one migration, not three. Phases 16–18 shipped without a schema change, so
there is no 16/17/18 schema to step through; adding empty migrations for phases that changed
nothing would put three permanent no-ops in the chain.

---

## 7. New REST endpoints

Base `replicaforge/v1/templates`. Nineteen routes.

| Method | Path | Capability |
|---|---|---|
| `GET` | `/library` | `templates.view` |
| `POST` | `/extract` | `templates.create` |
| `GET` | `/{id}` | `templates.view` |
| `POST` | `/{id}` | `templates.edit` |
| `DELETE` | `/{id}` | `templates.delete` |
| `POST` | `/{id}/validate` | `templates.view` |
| `GET` | `/{id}/versions` | `templates.view` |
| `GET` | `/{id}/compare?from=&to=` | `templates.view` |
| `GET` | `/{id}/export` | `templates.export` |
| `POST` | `/{id}/archive` | `templates.edit` |
| `POST` | `/{id}/restore` | `templates.edit` |
| `POST` | `/{id}/share` | `templates.share` |
| `POST` | `/import/inspect` | `templates.import` |
| `POST` | `/import/install` | `templates.import` |
| `GET` | `/components` | `templates.view` |
| `GET` | `/components/{id}/update-safety` | `templates.edit` |
| `GET` | `/tokens` | `design_systems.manage` |
| `POST` | `/tokens` | `design_systems.manage` |
| `GET` | `/tokens/{id}/impact` | `design_systems.manage` |

---

## 8. Template types implemented

**47 types** across four groups:

| Group | Count | Examples |
|---|---|---|
| `page` | 15 | homepage, about, services, service_detail, portfolio, project_detail, blog, blog_archive, blog_post, contact, faq, pricing, landing, documentation, custom |
| `theme` | 8 | header, footer, single_post, archive, product_archive, single_product, search_results, error_404 |
| `section` | 14 | hero, features, services_section, testimonials, pricing_section, cta, newsletter, contact_section, team, portfolio_grid, product_grid, blog_grid, faq_section, stats |
| `component` | 10 | button, card, product_card, blog_card, testimonial_card, pricing_card, nav_item, badge, form_field, social_links |

Type → group is a pure function (`Template_Limits::template_group()`), so no group is stored and
none can drift from its type.

---

## 9. Design-system features implemented

* **47 template types**, all validated against one closed vocabulary.
* **21 semantic roles**, assigned **from evidence only**. `color.primary` and `color.secondary`
  are **deliberately not inferred** — there is no reliable "primary" signal in a rendered page,
  and guessing re-points the most on a site when someone edits the token later.
* **14 slot types**, 15 dynamic content sources, 6 asset provenance classes.
* **Token identity** (`token_id` = `category.name`, derived from name and category, never the
  value) — which is what makes §8's "identify affected templates" joinable at all.
* **Ownership** (`replicaforge_controlled` / `user_controlled` / `theme_controlled` / `mixed` /
  `unknown`) — the mechanism by which re-analysis cannot undo a deliberate choice.
* **A real `confidence`.** Phase 8 read `$token['confidence']` and never wrote one, so every
  confidence in the existing design system silently fell back to a hard-coded `0.6`. Now computed
  from occurrences, evidence count and Phase 12's dispute flag, with the arithmetic documented in
  `TEMPLATES.md` §3.3. The claim is only that *higher means more evidence*.
* **Impact analysis + confirmation gate.** `POST /tokens` returns the affected templates and
  components and changes nothing; the change needs a second request with
  `impact_confirmed: true`.
* **Measured merge.** `merge()` never overwrites `user_controlled` or `theme_controlled` tokens;
  it refreshes the *evidence* while keeping the value and recording what the source now uses.
* 9 categories, unit detection that never strips the unit from the value, bounded at 400 tokens.

---

## 10. Component registry features

* Component ID, name, type, description, status, owner.
* Its **own document subtree** — extracted by claimed element ids with the ancestor chain, not the
  whole page. A component claiming absent ids is refused, not stored empty.
* Content slots, tokens, assets, dependencies, compatibility, provenance, validation state.
* **Versioning** with a bounded changelog recording dependency and compatibility deltas per
  version, including whether the minimum was raised.
* **Identical re-registration is not a version.** Installing two templates containing the same
  component no longer produces two versions — otherwise the version number is a count of
  references rather than a record of changes, and §10 ties a version to an *update*.
* **Update safety** (`update_safety()`): refuses to report an update as safe when any template
  uses the component, lists the consumers, offers `update_all` / `update_selected` /
  `keep_existing`, and **refuses downgrades**. `manual_edits_detectable` is always `false`,
  because that is the honest answer.
* **No `overwrite` strategy exists** in the vocabulary, so it cannot be requested.
* Usage counts, per-template consumer lists (the index that stops §8's impact scan being a scan).

---

## 11. Import / export functionality

**Export** produces a JSON document — **not a ZIP**. A single JSON object has no filenames, so
there is no `../` to traverse, no symlink to follow, and no compression-ratio trick. §39's "ZIP
traversal if package files are used" is answered by not using an archive.

Both directions run a redaction pass: exact credential keys, plus **fragments** anywhere in a key
(a denylist of exact names is trivially defeated by a rename), plus Elementor's raw post-meta
keys. Removals are **reported**, so a user sees what was stripped rather than finding a broken
template.

**Import** is nine steps (`Template_Limits::IMPORT_STEPS`). `inspect()` runs seven and names
conflict detection as next. It writes nothing. Schema version is checked with `version_compare`,
and a package from a newer schema is refused — a document with a key this version does not
understand may have limits it cannot see, and an unrecognised limit reads as unlimited.

**Conflicts** are detected before anything is written, then re-detected against a **fresh** read
when the plan is made — between the two requests a colleague may have created a template with the
same name, and acting on the stale list would overwrite it.

Five strategies. **There is no "overwrite".** A `token_*` conflict is additionally forced from
`new_version` to `keep_existing`, because a design token has no versions and accepting a strategy
with no meaning would make the plan report success while changing nothing.

**Installation is transactional**, seven named steps, every write journalled before it happens
and undone in reverse. The version row is written before the template row points at it, because
the reverse leaves a template whose `current_version` names a row that does not exist — the one
inconsistency the journal cannot undo cleanly.

Rollback removes ReplicaForge's own orphan draft and **never deletes a draft the user has been
shown**. An orphan draft per failed install is its own unbounded-growth path, so that one is
removed; a half-built draft the user can inspect is more useful than one silently gone.

---

## 12. Security protections

| Protection | Detail |
|---|---|
| **Widget allowlist** | `container, heading, text-editor, button, image, divider, spacer, icon` — read from `Elementor_Widget_Registry::widget_vocabulary()`, so the import allowlist and the generation vocabulary cannot drift. A widget outside it is refused **even if registered on the target site**, and the refusal names it. |
| **Setting allowlist** | 85 allowed controls, read as ReplicaForge's own vocabulary. 27 explicitly forbidden, reported differently so *hostile* is distinguishable from *unrecognised*. |
| **SSRF** | URL-bearing settings identified **by setting name, not value shape** (an `image` setting is sometimes a string, sometimes a list, sometimes objects with a `url`; shape-sniffing is how a nested `javascript:` gets missed). Every string then validated through `Security::is_safe_public_reference()`. |
| **Redistribution** | Non-redistributable assets downgraded from `import` to `reference` on both extraction and import. Unknown provenance defaults to `third_party`, so a malformed file must *defeat* the default to embed anything. |
| **Size bounds** | Version 512 KB, package 2 MB, depth 8, 2000 elements. Package size checked **before parsing**, because the encoded size is the bound that actually bounds memory. |
| **Schema downgrade** | `version_compare` refusal for a newer package. |
| **IDOR** | `authorise_post()` requires a `replicaforge_generation_id`, the project to belong to the workspace, and view permission — a post id alone is a guessable integer. |
| **Id-space enumeration** | `401` unauthenticated, then `404` for wrong workspace, another user's private template, and non-existent alike. |
| **No code execution** | No `eval`, `unserialize`, `call_user_func` on input, or shell anywhere in `includes/templates/`. Imported packages cannot execute PHP, JS, SQL or shell. |
| **Privilege separation** | A client gets no template capability. `client_review` is readable by a client and writable only by its owner. |

---

## 13. Permission integration

Nine capabilities, added to `Workspace_Limits::CAPABILITY_GROUPS` — **not** to a private list.
`Permission_Manager::can()` validates against that one closed vocabulary, so a capability living
only in `Template_Limits` would be refused by the manager: the button would render and always 403.

| Role | Template capabilities |
|---|---|
| `admin`, `project_manager` | all 9 |
| `designer`, `developer` | view, create, edit, export, install |
| `reviewer` | view |
| `client` | **none** |

Designers and developers are deliberately denied `templates.import` (admitting untrusted input
is not a design task), `templates.share` (publishing workspace-wide needs someone accountable),
`templates.delete` (destructive), and `design_systems.manage` (re-points every template at once).

Master vocabulary: 37 → **46**.

Audit events go to two places deliberately: `Collaboration_Log::audit()` for the **workspace**
trail an administrator of that workspace actually reads, and `Audit_Log::record()` for the
site-wide commercial log — with a generic action rather than inventing new vocabulary into
Phase 10's contract.

---

## 14. Tests executed

1. `php -l` over all 272 plugin files.
2. `tests/phase19-templates-test.php` — the Phase 19 suite, 19 sections.
3. A standalone targeted E2E probe covering the twelve §40 scenarios plus the §39 security
   cases, run during development.
4. The full 26-suite regression suite, twice.
5. The lifecycle contract test, re-run after the `uninstall.php` change.

---

## 15. Actual test results

```
php -l                       272 files, 0 failures

Phase 19 suite               265 assertions, 0 failed, 0 skipped, 0 warnings
                             re-runnable: two consecutive runs, identical result

Full regression suite        26 suites, 6340 assertions passed, 0 failed, 2 skipped
```

The 2 skips are pre-existing and unrelated (phase5 contract, admin contract).

Every one of the twelve §40 end-to-end scenarios and all twenty-one §39 security cases was
executed and passed. `TEMPLATES.md` §21 lists them case by case.

### Defects found and fixed during this phase

Each was reproduced before being fixed.

| # | Defect | Consequence had it shipped |
|---|---|---|
| 1 | `Template_Package::redact()` lowercased keys before blocklist comparison, and the list contained `eltype`/`widgettype` | **Stripped `elType` and `widgetType` from every element of every exported package.** Export reported success, the file looked complete, and the importing side rejected it as `no_elements_survived` — which read as a scanner false positive rather than a destroyed document. |
| 2 | `Template_Package::export()` omitted the `document` key entirely | Exported packages installed as **blank pages** while carrying complete-looking metadata. |
| 3 | The version hash was taken before `Data_Redactor::structure()` ran | `verify()` could never match for any template containing rich text — most of them — and named corruption as the cause when the file was intact. |
| 4 | `clean_scalar()` never validated URLs | `169.254.169.254` and `10.0.0.5` passed the entire pipeline. With Phase 13 rendering previews, an imported template could make the server request an internal address. |
| 5 | `Template_Conflicts::plan()` downgraded `new_version` only for `token_value` | A `token_name` conflict (the `user_controlled` case) accepted a strategy that does nothing, reporting success while changing nothing. |
| 6 | `Template_Component_Store::register()` versioned on every registration | The version number became a count of references rather than a record of changes. |
| 7 | `is_string( $options['visibility'] ?? '' )` | The `??` default is a string, so `is_string('')` is **true** — the true branch then re-read the key *without* the coalesce. A warning on every install. |
| 8 | `$validation['blocking']` read in `inspect()`; the validator reports `installable` | `installable` was decided by the dependency result alone, so a template the validator marked `needs_review` could present as installable. |
| 9 | `Template_Conflicts` read name/type from `provenance` | Provenance is deliberately reduced to origin/author/url/date/licence, so **name collisions were never detected**. |
| 10 | `Template_Api::compare_documents()` used a recursive closure without self-binding | Fatal — `Value of type null is not callable` on any version comparison. |
| 11 | `$content_slots` read from the template row rather than the version row | Slot summaries were always empty. |
| 12 | Same `??`-then-bare-read shape in `Template_Store::create()` | Same latent warning as #7. |
| 13 | `uninstall.php` did not drop the three new tables | Real content with no owner after removal — inconsistent with the file's own stated policy. |
| 14 | `Template_Sanitizer` reflection into `Elementor_Widget_Registry::$widget_map` | `ReflectionException` if the class were renamed or lazily loaded, plus a second registry instance per call. Replaced with a real accessor. |

Two carried-forward findings from earlier phases were also corrected: `Project_Repository::create()`
**does** record an owner (line 109) — the earlier claim that it wrote `user_id => 0` was wrong —
and `Project_Repository::add_version()` genuinely had no caller, which is why Phase 15's approval
gates always read `status = none`. It is now wired.

---

## 16. Performance observations

* **No unbounded query.** The library list reads one narrow metadata row per template; documents
  live in the version table and are read only when a template is opened. `MAX_TEMPLATES = 500`
  could not be served from an option without loading every template on every view.
* **Three stores extend `Collaboration_Store`** rather than reimplementing it, so pagination,
  cursor handling, the search clause, type coercion and the `prepare()` boundary are inherited.
  This was the reason for generalising the base's "one of several" filter rather than
  overriding `query()` three times — an override would have had to be kept in step with
  pagination and search forever.
* **Version numbers from `MAX(version)`, not from a count**, matching
  `Project_Repository::add_version()`. Once a list is trimmed the two stop agreeing, and counting
  would eventually hand one number to two versions.
* **Install counts are `UPDATE … SET n = n + 1`**, an expression rather than a read-modify-write,
  so concurrent installs cannot lose one another's count.
* **Retention trim** drops the oldest versions; a version is a record rather than content, and the
  template is never left pointing at a removed one.
* **`install_count = increment` and a hash on write** add one indexed `UPDATE` and one `SHA-256`
  over ≤512 KB per version. Measured as unremarkable.
* **Token registry is cached per request** in `Design_Token_Registry::$cache`.
* **No rendering on save.** Validation and quality measurement read stored data only.

---

## 17. Known limitations

Stated plainly, because the alternative is a report that overstates the work.

1. **`replicaforge_analysis` is still never written.** The extractor reads it from three durable
   homes in priority order and **reports which one it used**, so extraction works — but the four
   Phase 12/13/14 consumers reading that post meta are still reading an empty array. Fixing it
   means writing the representation at generation time, which is Phase 4's change, not this
   phase's. **Recorded, not fixed.**
2. **Section templates cannot be extracted at sub-page granularity.** A template is extracted
   from a whole generated page. `template_type` says *what* it is; there is no "extract this one
   section" affordance, which the §17 workflow implies.
3. **Component updates are reported, not applied.** `update_safety()` names affected consumers and
   offers strategies; there is no `apply_component_update()`. Applying one would rewrite consumer
   documents, and §30 forbids overwriting manual edits — which cannot be verified from stored
   data.
4. **Token changes do not yet re-point installed templates.** Impact analysis, ownership,
   confirmation and merge are all in place, but extracted templates still carry literal hex values
   rather than `{{token}}` bindings, so `token_coverage` is near zero for real extractions and a
   global token change currently does not move them. **This is the largest gap between what §8
   describes and what ships.** The registry is the missing half and it is built.
5. **Design-system merge writes the live registry only** — not Elementor global colours, not
   already-installed template documents.
6. **No visual preview render.** The API returns the three viewports and the document; Phase 13's
   `Visual_Renderer` is not wired to templates. No interactive preview, hover state, accordion or
   carousel preview.
7. **Multi-site is untested.** Every Phase 15 store is workspace-scoped; the per-site table prefix
   would need verifying.
8. **Three conflict strategies are plannable, not applied.** `plan()` reports `new_version`,
   `merge_tokens` and `manual`; only `keep_existing`, `rename` and manual review have an
   implemented effect.

---

## 18. Unsupported Elementor features

| Not supported | Why |
|---|---|
| Third-party widgets | Outside the allowlist. A widget registered on the target site still does not make it acceptable in an imported template. |
| `html`, `shortcode`, `embed`, `custom-html`, `html-tag` | Executable content; refused on generation *and* import. |
| Theme locations (header/footer build) | Types are declared and validated; the theme-builder install path is not implemented. |
| `template_type` co-existence rules | Not checked — two templates declaring the same Elementor location is not detected. |
| Dynamic tags | Only the 15 declared sources, each gated on the destination actually having the provider. |
| Motion/interaction behaviour in packages | Stored as a *reference* to Phase 16's model, never as code. A package never carries behaviour. |

Verified on this site: Elementor 4.3.2, so the `containers`, `flexbox`, `grid`, `global_colors`,
`global_fonts`, `responsive` and `theme_builder` requirements resolve against a real probe, and
every live capability is in the declared vocabulary.

---

## 19. Manual configuration steps

**None required.** The feature is active on activation.

The migration runs automatically. Verified on this site: DB schema at `19.0.0`, 16 tables
present, 0 missing, 9 capabilities resolvable.

Two optional things a site owner may want to do:

1. **Set the semantic roles that are deliberately not inferred.** `color.primary` and
   `color.secondary` are reported as unassigned rather than guessed. Set them by hand in the
   library's design-system panel, or leave them — an unassigned role is safe, a wrongly assigned
   one re-points every template bound to it.
2. **Grant template capabilities explicitly if roles were customised.** The workspace roles are
   read from `Workspace_Limits::ROLE_CAPS` at permission-check time, so there is no stored row to
   backfill and nothing to migrate. A site that stored custom role grants directly in the database
   would need the same grants re-applied through the Team screen.

---

## 20. Documentation updated

| Document | Change |
|---|---|
| `docs/TEMPLATES.md` | **New**, 1174 lines — architecture, token model, slots, components, assets, security, import/export, conflicts, versioning, compatibility, validation, quality, REST, storage, migration, marketplace boundary, verification, limitations, extension points |
| `docs/API.md` | Phase 19 route table, refusal shaping, the two-request token gate |
| `docs/DATABASE.md` | Migration chain with the `15.0.0 → 19.0.0` reasoning, the three tables, and why the migration creates nothing |

The extension-points rule is one sentence: **add vocabularies to `Template_Limits`, not to the
class that consumes them.** Every consumer reads the one list, so a value added anywhere is
checkable everywhere.

---

## 21. Implementation status

**Implemented and tested**

Templates (47 types) · extraction · sanitisation with widget/setting allowlists and SSRF
validation · 5-state validation across 8 dimensions · 8 quality indicators with no aggregate ·
design-token registry with identity, computed confidence, ownership and impact analysis ·
21 semantic roles assigned from evidence · content slots with no fabricated defaults ·
versioned component registry with changelog and update safety · transactional install with
rollback and post-install integrity verification · conflict detection and planning with no
overwrite strategy · JSON package export/import with redaction and size bounds · design-system
merge preserving hand-set values · 9 capabilities integrated into the existing permission
vocabulary · 19 REST routes · two admin screens · three tables · one migration ·
uninstall coverage.

**Implemented, not wired to a visible surface**

* `merge_tokens` installs a design system when explicitly requested.
* `Template_Conflicts::plan()` emits actions for strategies that have no automated effect.

**Partially implemented**

* Component updates are *analysed*, not applied (§17.3).
* Token changes are *tracked*, not propagated into installed templates (§17.4).

**Not implemented**

* Public marketplace, payments, seller accounts, revenue sharing — metadata and licence fields
  exist; no route makes anything public.
* Browser-rendered or interactive template preview.
* Sub-page section extraction.
* AI assistance. No AI is called anywhere in Phase 19. §32 permitted it; wiring it would have
  meant inventing token values or content defaults, which the more important rule forbids. The
  two validation doors such output would need already exist.
* Multi-site verification.
* A second job queue, permission system, rendering engine or versioning system.

---

## 22. Stop conditions

| Condition | Status |
|---|---|
| Implement Phase 19 only | Yes. Phase 20 not started. |
| No public marketplace | Yes. Metadata only; no public route. |
| No payments or seller accounts | Yes. |
| No second versioning system | Yes. `Project_Repository::add_version()` wired; template versions are a distinct entity with a distinct lifecycle. |
| No second permission system | Yes. Nine capabilities in the existing closed vocabulary. |
| No second rendering system | Yes. Phase 13 is untouched. |
| No second job/orchestration system | Yes. No new queue. |
| No execution of imported PHP/JS/SQL/shell | Yes. Zero such calls in `includes/templates/`. |
| No silent redistribution of third-party assets | Yes. Enforced in `Template_Sanitizer` and `Template_Package`, not the UI. |
| No invented source content or design values | Yes. Slot defaults are empty; confidence is computed and documented. |
| No overwriting user templates or pages without authorization | Yes. No overwrite strategy exists. |
| Existing security not weakened | Yes. The SSRF boundary was *extended* to template settings. |
| No existing project data deleted | Yes. Rollback never deletes a user-visible draft. |
| Compatibility with Phases 1–18 preserved | Yes. 26 suites, 6340 assertions, 0 failures. |
| Finish with the implementation report | This document. |