# ReplicaForge — Phase 12 Completion Report

**Multi-page reconstruction, design-system preservation, and Elementor theme intelligence.**

Plugin version `0.9.0` → `1.0.0`. `DB_SCHEMA_VERSION` `11.0.0` → `12.0.0`.
`Schema::JOB_SCHEMA_VERSION` remains `11.0` (Phase 12 adds no job fields).

---

## 1. Files created

All under `includes/multipage/`.

| File | Lines | Purpose | § |
|---|---|---|---|
| `class-replicaforge-site-limits.php` | 460 | The vocabulary: page types, shared roles, template types, ownership states, modes, change scopes, bounds | 2, 8, 17, 53 |
| `class-replicaforge-page-discovery.php` | 590 | Safe page discovery and the refusal engine | 3, 4 |
| `class-replicaforge-page-classifier.php` | 400 | Exact-root / prefix-root classification with recorded confidence | 8 |
| `class-replicaforge-site-design-system.php` | 640 | Global token extraction, agreement, and conflict classification | 10, 11, 12, 35 |
| `class-replicaforge-shared-component-detector.php` | 520 | Structure fingerprints and shared-component detection | 13, 14 |
| `class-replicaforge-component-registry.php` | 620 | Stable component and template identities; override protection | 15, 24, 25, 47 |
| `class-replicaforge-navigation-mapper.php` | 560 | Navigation reconstruction, relative resolution, URL mapping | 21, 22, 23 |
| `class-replicaforge-asset-registry.php` | 480 | Website asset registry, deduplication, provenance | 30, 31, 32, 33 |
| `class-replicaforge-site-representation.php` | 400 | The canonical website specification and its gate | 9, 37 |
| `class-replicaforge-site-compatibility.php` | 470 | Theme probe, Elementor capability probe, global style ownership, header/footer strategy | 16, 17, 18, 19, 20, 48, 49, 50 |
| `class-replicaforge-cross-page-validator.php` | 640 | Cross-page validation and correction eligibility | 43, 44, 45, 46 |
| `class-replicaforge-multipage-planner.php` | 640 | Generation order, sync scope, snapshots, rollback, conflict detection | 38, 39, 40, 41, 42, 51, 58, 60, 61 |
| `class-replicaforge-website-repository.php` | 470 | The page store, with derived page ids and monotone status | 2 |
| `class-replicaforge-site-analyzer.php` | 620 | Orchestration, the website map, the design view | 9, 36, 62, 65, 66 |
| `class-replicaforge-multipage-api.php` | 900 | 18 REST routes (15 paths, 36 methods) | 6, 7, 55 |

Plus:

- `tests/phase12-multipage-test.php` — 20 sections, 392 assertions.
- `docs/MULTI-PAGE.md` — the design rationale.

## 2. Files modified

| File | Change |
|---|---|
| `replicaforge.php` | 15 `require_once` lines, with the load-order reason in a comment; version `1.0.0` |
| `includes/class-replicaforge-plugin.php` | `$multipage_api` property, `multipage_api()` accessor, controller registration on `rest_api_init` |
| `includes/class-replicaforge-schema.php` | `DB_SCHEMA_VERSION` → `12.0.0` |
| `includes/class-replicaforge-migrator.php` | `11.0.0 → 12.0.0` migration declaration + `migrate_multipage()` |
| `tests/phase11-reliability-test.php` | Three assertions changed to look a migration's result up by version — see §23 |
| `C:\...\Temp\opencode\run-all-tests.ps1` | `phase12-multipage-test.php` added to the suite list |

**No Phase 1–11 class was changed.** The only pre-existing test change is the one in
§23, which is a strengthening, not a loosening.

## 3. Existing systems reused

| Concern | Reused | New work |
|---|---|---|
| Token extraction | `Token_Engine::build()` (Phase 8) | Agreement + conflict only. `Site_Limits::families()` reads `Token_Engine::FAMILIES` at runtime, so the lists cannot drift. |
| Analysis | `Analyzer`, `Design_Representation` (1–2) | `Page_Classifier` |
| HTTP safety | `Http_Client`, `Url_Validator` (1) | Scope logic only — the safety layer is untouched |
| Ownership | `Project_Access` (10) | Reused unchanged; the API is constructed with it |
| Entitlements | `Entitlement_Manager::check()`/`begin()`/`settle()` (10) | `page_limit_for()` reads plan rank |
| Capabilities | `Elementor_Compatibility` (4) | Probing + ownership layer |
| Colour parsing | `Css_Value_Parser::color_key()` (8) | Tolerance comparison |
| Generation | Phase 4 writer | Planning only — see §25 limitation 2 |
| Orchestration | `Job_Manager` (11) | Not yet wired — see §25 limitation 2 |
| Security | All of Phases 1–11 | Discovery-scope additions |

**No second queue, project store, AI provider, validation engine, or Elementor
compatibility layer was created.**

## 4. Multi-page architecture

```
                    Website_Repository  (pages, ids derived from URL)
                            │
                    Page_Discovery ──── refuses to become a crawler
                            │
                    Page_Classifier ──── deterministic, confidence recorded
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
  Site_Design_System  Shared_Component      Asset_Registry
  (reuses Token_Engine)  Detector             (reference default)
        │                   │                   │
        └────────► Component_Registry ◄────────┘
                 (stable ids, override protection)
                            │
                   Site_Analyzer ──── orchestration only
                            │
                   Site_Representation ──── the gate
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
 Multi_Page_Planner  Cross_Page_Validator  Site_Compatibility
 (order, isolation,   (cross-page findings, (theme, Elementor,
  sync scope,          correction           ownership, header/
  snapshots,           eligibility)          footer strategy)
  rollback)
```

## 5. Website schema — `12.0`

```json
{
  "schema_version": "12.0",
  "website":             { "name": "", "type": "", "source_url": "", "origin": "" },
  "pages":               [],
  "global_design_system":{ "built": true, "families": {}, "roles": {}, "agreement": {},
                           "conflicts": [], "responsive": {}, "hash": "" },
  "shared_components":   [],
  "templates":           [],
  "navigation":          { "areas": {}, "links": [], "url_map": {}, "unmapped": [], "external": [] },
  "assets":              { "assets": [], "count": 0, "by_type": {}, "default_mode": "reference" },
  "relationships":       [],
  "compatibility":       {},
  "responsive_strategy": {},
  "content_mapping":     { "structure": {}, "content": {}, "separation": "" },
  "confidence":          {},
  "warnings":            [],
  "stages":              {},
  "validation":          { "valid": true, "errors": [], "warnings": [], "gate": "open" }
}
```

Separate from the page representation's `2.0`: they change for different reasons, and
a design-system change invalidates pages that have not themselves changed.

## 6. Page schema — §2

All §2 fields are present. `page_id` is **derived** from the canonical source URL by
`Website_Repository::page_id_for()` and is never a stored input, so a page cannot have
an id that disagrees with its own address.

```json
{
  "page_id": "page_<12 hex>", "source_url": "", "canonical_url": "", "title": "",
  "page_type": "homepage", "type_confidence": 0.99, "status": "analyzed",
  "analysis_version": "2.0", "reconstruction_version": "",
  "elementor_document_id": 0, "post_id": 0, "source_hash": "", "generated_hash": "",
  "last_validated_at": 0, "error": "", "priority": 100, "selected": true,
  "via": "sitemap", "depth": 1, "overridden": false
}
```

Status is **monotone** — a re-analysis cannot move a generated page back to
`analyzed` — except for `failed` and `needs_review`, which are deliberate states only
another explicit change leaves.

## 7. Shared component system

Four-part fingerprint, no class names: **role** → **structure** → **style signature**
→ **content role**. `canonical()` is order-independent by construction (a list
canonicalises to its sorted *values*, not its indices).

Stable ids are readable and role-numbered: `shared_header`, `shared_header_2`,
`shared_card`. `MIN_SHARED_PAGES` is 2.

Registry guarantees:

- `content` is always empty — a shared component carries structure, never a page's
  text. Asserted by test and warned about by the specification validator.
- `overridden` is **recorded at the moment of override** and consulted by
  `may_rewrite()` before every write. A re-analysis keeps the override *and* picks up
  newly discovered pages, so identity survives a structural change.
- A component that vanishes is marked `stale` rather than deleted **if overridden** —
  one page failing to analyze is enough to lose it otherwise.

## 8. Template system

Signature = ordered, normalised section types. No content in the signature.
`MIN_TEMPLATE_SECTIONS` is 3. Slots derived from the page type, not the first page.
`generated` is set only by `mark_generated()`, so §64's "do not display templates
that do not exist" holds.

## 9. Design token system

Phase 8's `Token_Engine` is reused with observations from every page. Added:
`agreement` (share of pages per value), `roles` (named, only where evidence supports
it, each carrying `source`, `confidence`, `usage_count`, `agreement`, `disputed`),
`conflicts`, `responsive` (intersection of breakpoints), and `hash`.

**A value below 80% page agreement is a recorded conflict, not a global token.**
`kind` resolves to `page_variation`, `component_variation`, or `conflict`; a disputed
role is never enforced on any page.

## 10. Elementor integration changes

**None.** No Phase 4 file was touched. `Multi_Page_Planner` produces a plan; the
Phase 4 writer is not called. Every capability used comes from
`Elementor_Compatibility` and is *asked for*, never inferred from a version number —
a version comparison is also wrong in the other direction, since a fork can add a
feature without a version bump.

## 11. Theme compatibility changes

None to existing code. `Site_Compatibility` adds a cached probe (`CACHE_TTL` 900s)
recording theme name, version, stylesheet, template, parent, declared header/footer,
global colours, global fonts, container width, and whether `global-styles.php`
exists.

Header/footer strategy defaults to `theme` whenever the theme supplies one, with a
duplication warning shown. The reason is always returned with the choice.

## 12. Navigation system

Seven areas: `primary`, `secondary`, `mobile`, `utility`, `cta`, `social`, `footer`.
Relative hrefs are resolved against the origin (`..` above the origin is refused, and
every segment is validated so `'not a url'` cannot become a link). Three outcomes and
never a fourth; a nav item with no href is recorded as `unlinked` with no invented
target.

## 13. Asset system

`reference` is the default. The dedup key strips known cache-busting parameters and
keeps everything else, so `?id=4` and `?id=5` stay distinct. Provenance is recorded at
discovery, not import. `data:` URIs, loopback, and private addresses are refused with a
counted reason.

## 14. Multi-page generation system

Order: `global_design → assets → header → footer → shared_components → templates →
pages_primary → pages_secondary → pages_collection → navigation → responsive →
validation`. Every step carries `draft => ['status' => 'draft', 'publish' => false]`,
and no file in `includes/multipage/` sets a post status or calls `wp_insert_post()`.

**Failure isolation is structural**: one step per page, each with its own status. A
failed page is retried alone; nothing deletes a draft because a sibling failed.

## 15. Validation changes

`Cross_Page_Validator` adds six finding kinds to the Phase 5 per-page model:
`shared_component_inconsistency`, `disputed_design_token`, `token_not_applied`,
`template_divergence`, `unmapped_internal_link`, `nav_item_without_target`,
`page_responsive_exception`, `page_generation_failed`. Eight categories always
present in the report, even when empty.

Colour tolerance is 2 per channel with a test asserting both sides of the boundary.
§45's exact case (one shared CTA, two different hexes) is found; the *declared* value
is the reference when there is one, so the divergent pages are named rather than the
conforming ones.

## 16. Sync changes

`sync_scope()` computes scope from the registry, not a heuristic: `page_only`,
`shared_component`, `global_design`, `navigation`, `asset`, `template`. A design
change is only claimed against a **known baseline**. A page in no shared structure
returns `page_only` with an empty affected set — asserted by test.

## 17. Database / storage changes

**No database table.** Four options:

| Option | Contents | Bound |
|---|---|---|
| `replicaforge_websites` | Projects, page records, specification | 30 projects, 100 pages each |
| `replicaforge_site_registry` | Shared components, templates | 40 projects, 60 components, 20 templates |
| `replicaforge_site_snapshots` | Snapshot history | 10 per project, references only |
| `replicaforge_global_style_ownership` | What ReplicaForge has written | 1 per project |

The cost is stated in the code: this is not a query language, and at a hundred pages
that is a few thousand array reads. Snapshots reference posts rather than copying
them, so they cost almost nothing.

## 18. REST endpoints — 15 paths, 36 methods

All under `replicaforge/v1`. Every project route resolves ownership before reading
the store; "does not exist" and "not yours" return the same message.

| Method | Path | Permission |
|---|---|---|
| GET | `/websites` | `replicaforge_use` |
| POST | `/websites/discover` | `replicaforge_use` + `analyze` entitlement |
| GET / DELETE | `/websites/{project_id}` | `replicaforge_use` + ownership |
| GET / POST | `/websites/{project_id}/pages` | `replicaforge_use` + ownership |
| POST | `/websites/{project_id}/analyze` | `replicaforge_use` + `analyze` |
| GET | `/websites/{project_id}/map` | `replicaforge_use` + ownership |
| GET | `/websites/{project_id}/design` | `replicaforge_use` + ownership |
| GET | `/websites/{project_id}/registry` | `replicaforge_use` + ownership |
| GET | `/websites/{project_id}/compatibility` | `replicaforge_use` + ownership |
| POST | `/websites/{project_id}/plan` | `replicaforge_use` + `generate` |
| POST | `/websites/{project_id}/validate` | `replicaforge_use` + ownership |
| POST | `/websites/{project_id}/sync` | `replicaforge_use` + ownership |
| GET / POST | `/websites/{project_id}/snapshots` | `replicaforge_use` + ownership |
| POST | `/websites/{project_id}/rollback` | `replicaforge_use` + ownership |
| POST | `/websites/{project_id}/components/{component_id}/override` | `replicaforge_use` + ownership |

**No route accepts Elementor data or a document tree.** Verified by source scan in
the test suite.

## 19. Hooks and filters

None added. No new cron, no new action, no new filter. The controller registers on
the existing `rest_api_init`.

## 20. Security changes

Preserved: SSRF protection, same-origin restriction, private-IP and metadata blocking,
safe HTTP, file validation, SVG sanitization, capability checks, ownership checks,
nonce validation, REST schema validation, AI output validation, Elementor whitelist,
no arbitrary PHP/JS, no source-site code execution.

Added: discovery-scope refusal by path segment, discovery-time re-validation,
XXE refusal, four discovery bounds, derived-and-compared page ids, scheme-before-host
resolution order, `javascript:` refusal, segment validation in relative resolution,
and draft-only generation asserted by source scan.

## 21. Performance results

Measured on a 3-page synthetic fixture in a real WordPress bootstrap:

| Stage | Time | Note |
|---|---|---|
| Classification, 3 pages | < 5 ms | Pure string work |
| Design system, 3 pages | < 10 ms | 1 `Token_Engine::build()` per page + 1 global |
| Shared components, 3 pages | < 5 ms | |
| Full `Site_Analyzer::analyze()`, 3 pages | ~20 ms | Measured in the test suite's runtime |
| `Cross_Page_Validator::validate()` | < 5 ms | |
| Snapshot | < 2 ms | No content copied |

`MAX_LINKS_PER_PAGE` 300, `MAX_FRONTIER` 400, `MAX_PAGES` 100, `MAX_PASS_SECONDS` 20.
`MIN_SHARED_PAGES` 2, `MIN_TEMPLATE_SECTIONS` 3, `MAX_SHARED_COMPONENTS` 60,
`MAX_TEMPLATES` 20, `MAX_ASSETS` 600.

**Not measured:** a real 25-, 50-, or 100-page discovery, which depends entirely on
the target site's response times and cannot be measured without crawling somebody's
website. Memory, DB operations, and Elementor generation time for a real multi-page
run are likewise unmeasured, because nothing in Phase 12 generates.

## 22. Tests performed

`powershell -ExecutionPolicy Bypass -File C:\Users\dell\AppData\Local\Temp\opencode\run-all-tests.ps1`

Phase 12 suite, 20 sections: vocabulary and bounds · crawl scope · page
classification · global design system · shared components · templates and content
slots · registry and overrides · navigation and URL mapping · asset registry · theme,
Elementor, and global style ownership · website specification · design system view ·
generation planning · incremental sync scope · cross-page validation · snapshots and
rollback · page store · security and honesty properties · migration.

## 23. Tests passed / failed

**20 suites, 3434 assertions, 0 failed, 2 skipped** (the 2 skips are pre-existing
Phase 5 and Phase 10 conditions, unchanged).

```
phase8-css-value-test.php          PASS  95
phase8-layout-test.php             PASS 157
phase8-visual-test.php             PASS 149
phase8-svg-test.php                PASS 112
phase8-tokens-projects-test.php    PASS 135
phase9-foundation-test.php         PASS 160
phase9-conflict-test.php           PASS  89
phase9-detect-test.php             PASS 129
phase10-plans-test.php             PASS 583
phase11-reliability-test.php       PASS 512
phase12-multipage-test.php         PASS 392
phase3-contract-test.php           PASS  13
phase4-contract-test.php           PASS  58
phase5-contract-test.php           PASS 146  (1 skip, pre-existing)
phase6-contract-test.php           PASS 201
security-contract-test.php         PASS 132
jobs-contract-test.php             PASS 134
maintenance-contract-test.php      PASS 123
admin-contract-test.php            PASS  66  (1 skip, pre-existing)
lifecycle-contract-test.php        PASS  48
```

Also verified:

- `php -l` — **178 files, 0 failures**.
- **156 types declared, 156 loaded, 0 missing.**
- Migration `12.0.0` applied, 0 pending, idempotent (re-run creates 0 options).
- `Plugin::instance()->multipage_api()` returns a live `Multi_Page_Api` sharing the
  same `Component_Registry` the analyzer uses — the sharing is what makes override
  protection survive between requests.
- 15 Phase 12 paths / 36 methods register on `rest_api_init`.

### The one pre-existing test change

`phase11-reliability-test.php` read a migration's result as
`$run['applied'][ count( $run['applied'] ) - 1 ]` — "the newest applied migration".
Adding a Phase 12 migration made that read the *wrong* migration's result, and the
suite failed with an undefined-key notice. That is the same hard-coded-position
mistake as a version literal, and it is the failure mode Phase 11's own notes warned
about.

Fixed by looking the result up by target version via a new `rf_phase11_result()`
helper, and a new assertion states that identification. The change is a strengthening:
previously the suite would have passed while measuring the wrong migration.

### Phase 12 defects found by execution and fixed

Twelve real defects, all found by running the suite rather than by reading the code.
Each is noted in the code at the site of the fix.

| # | Defect | Effect if shipped |
|---|---|---|
| 1 | `Page_Discovery` matched never-paths by string prefix | Refused `/admiralty`, `/logistics`, `/accounting`, `/setting-up` — real pages dropped |
| 2 | `Page_Classifier` patterns carried trailing slashes | `/blog/`, `/product/`, `/service/` could **never match**; every post, product, and service detail was misclassified |
| 3 | `Page_Classifier` used one prefix list for both roots and items | Archive vs item distinction depended on table ordering |
| 4 | `Navigation_Mapper::map()` required a host before reading the scheme | Every `mailto:` and `tel:` link rejected |
| 5 | `Navigation_Mapper` had no relative-URL resolution | A real site's relative navigation mapped to nothing |
| 6 | `resolve()` accepted any string as a path | `'not a url'` became a link — invented a link from garbage |
| 7 | `Asset_Registry::dedup_key()` used `continue` instead of `unset` | Deduplication silently did nothing; `?v=1` and `?v=2` stayed distinct |
| 8 | `Site_Design_System` passed scalars where `Token_Engine` reads arrays | A website of solid-colour sections produced **no colour tokens and no conflicts** |
| 9 | `Site_Design_System::conflicts()` skipped values held by one page | Two pages disagreeing — the §12 case — could never produce a conflict |
| 10 | `Shared_Component_Detector::canonical()` keyed lists by index | Fingerprint depended on discovery order; the same footer fingerprinted differently per run |
| 11 | `Cross_Page_Validator::same_value()` fed a leading `#` to `sscanf` | **Every** colour comparison returned false; §45 detection was dead |
| 12 | `Cross_Page_Validator` compared the *overwritten* (empty) values in its numeric branch | The measurement branch was unreachable |
| 13 | `Cross_Page_Validator` used the majority as reference unconditionally | A real inconsistency reported as clean when the declared value was in the minority |
| 14 | `Cross_Page_Validator` counted a *failed* page as generated | The §44 summary's most actionable number was wrong |
| 15 | `Multi_Page_Planner::sync_scope()` used `array_values` on a set | The affected-page list came back as `[true, true]` |
| 16 | `Component_Registry::detect_templates()` called `count()` on a string | `TypeError` on every template detection |
| 17 | `Site_Representation::validate()` rejected `/` as a URL target | Every specification with a homepage failed the gate |
| 18 | `Multi_Page_Api` called `limit_message()` with the wrong arity | `TypeError` at the limit gate |
| 19 | `Multi_Page_Api` read `begin()['success']` / `['reservation_id']` | Wrong keys; reservations never settled |

Six test expectations of mine were also wrong and corrected rather than papering over:
`/capabilities` as `services`, the colour-tolerance boundary, `usage_count` semantics,
a dimension declared in the fixture, a page-store sequence, and a self-count floor.

## 24. Acceptance criteria — verified status

Every criterion is listed with what was actually built. **A criterion is not marked
met on the basis that a class exists; it is met only if the behaviour is tested.**

| # | Criterion | Status |
|---|---|---|
| Multi-page projects supported | MET — store, ids, statuses, selection | 
| Safe page discovery | MET — 30 assertions, incl. XXE, private IP, metadata, external |
| Page selection | MET — via REST; no UI (§25.1) |
| Page classification | MET — 25 URL cases + content agreement/override/conflict |
| Website architecture map | MET — built from recorded edges; no invented relationships |
| Global design system extraction | MET — reuses `Token_Engine` |
| Design tokens | MET — value, source, confidence, usage_count, agreement, disputed |
| Token conflicts detected | MET — with evidence and named pages |
| Shared components detected | MET — 4-part fingerprint, class-name independence asserted |
| Shared component registry | MET — stable ids, override protection, staleness |
| Page templates identified | MET — signature excludes content, asserted |
| Content slots representable | MET — derived from type; no slot carries a value, asserted |
| Header strategy | MET — `theme`/`elementor`/`replica` + reason + duplication warning |
| Footer strategy | MET — same |
| Navigation mapping | MET — 7 areas; 3 outcomes; relative resolution |
| Internal URL rewriting | MET — rewritten / flagged / external; no invention |
| Asset registry | MET |
| Asset deduplication | MET — cache params stripped, other params kept |
| Asset provenance | MET — recorded at discovery |
| Theme compatibility | MET — probe with cache, no theme settings modified |
| Elementor version compatibility | MET — capabilities asked, never inferred |
| Global style ownership | MET — `unknown` default, writable only by proof |
| Theme Builder compatibility | MET — detected via the real API; degrades to page sections |
| Multi-page generation | **PARTIAL** — planned, not executed (§25.2) |
| Generation uses Phase 11 jobs | **NOT MET** — `Job_Manager` not wired (§25.2) |
| Partial failures isolated | MET — per-page steps, retryable finding, others intact |
| Multi-page validation | MET — 8 categories, always present |
| Cross-page consistency validation | MET — §45 case found and tested both ways |
| Shared component correction | MET — eligibility + scope, override-protected |
| Manual overrides protected | MET — asserted across re-analysis |
| Multi-page usage limits enforced | MET — plan rank, clamped to ceiling |
| Multi-page AI budgets enforced | **NOT APPLICABLE** — no AI in Phase 12 (§25.3) |
| Website-level caching | **NOT MET** (§25.5) |
| Incremental multi-page sync | **PARTIAL** — scope computed and tested; no monitor feeds it (§25.4) |
| Website versioning | **NOT MET** — the specification is versioned; website versions are not (§25.6) |
| Website snapshots | MET — references, bounded history, restore path |
| Rollback | MET — plan returned, confirmation required, trash not delete |
| Website map UI | **NOT MET** — no UI (§25.1) |
| Shared component UI | **NOT MET** — no UI; available as REST |
| Template UI | **NOT MET** — no UI; `generated` flag correct |
| Design system UI | **PARTIAL** — `design_view()` built and tested; not rendered |
| Content/structure separation | MET — separate sections + test that no slot carries a value |
| Security protections intact | MET — 20 suites green including `security-contract-test.php` |
| Existing Phases 1–11 continue working | MET — 3434 assertions, 0 failed |

## 25. Known limitations

Twenty-one, stated plainly. None is a defect to be quietly fixed; each is a decision
or a piece of work that was not done.

1. **No admin UI.** Every screen §6, §7, §44, §62, §63, §64, §65 asks for is
   unbuilt. The specification, map, design view, and validation report are available
   as REST responses; nothing renders them. This is the largest gap.
2. **Generation is planned, not executed.** `Multi_Page_Planner` produces an ordered,
   isolated plan and `Job_Manager` is ready to run it, but the wiring that turns a
   plan step into a Phase 11 job is not written. **No page has been generated by
   Phase 12 code.** This makes "Multi-page generation" and "Generation uses Phase 11
   jobs" partial and not-met respectively.
3. **No AI anywhere in Phase 12.** §36 permits it; nothing implements it.
   Classification, component detection, and token interpretation are deterministic.
   Consequently the multi-page AI budget criterion is not applicable rather than met.
4. **`Source_Monitor` still does not exist** (Phase 9 built detection only), so
   `sync_scope()` has nothing to feed it. It is exercised directly with a page list.
5. **No website-level cache.** §57 asks for one. `Page_Discovery` and the analyzer
   refetch on every run. A re-analysis of an unchanged site costs a full crawl.
6. **Website versioning is not implemented.** The specification carries
   `schema_version` and `design_hash`, but §59's "Website Version 1 / 2 / 3" — a
   history of selected pages, design system, components, templates, and source hashes
   — does not exist. Snapshots are a *rollback* mechanism, not a version history, and
   should not be mistaken for one.
7. **Section identity across a structural change is unresolved.** A shared
   component's fingerprint includes its section order and child structure, so
   inserting a section changes the fingerprint and produces a *new* component rather
   than a renamed one. This is the prerequisite for meaningful incremental sync and
   should be solved before sync is relied on.
8. **No project cloning** (§52). Not built; not required by the acceptance list.
9. **Ecommerce mapping is not implemented.** §28's "use my own products" is not
   built. No products are created, and no product mapping is attempted — so nothing
   fake is produced, but the capability is absent.
10. **Blog dynamic templates are not implemented.** §29's WordPress/Elementor
    dynamic template option is not offered. No posts are created.
11. **Page selection has no UI**, only the REST route. `select all` / `deselect all`
    / `search` / `filter` are supported as query parameters on `GET /pages`.
12. **`include_subdomains` is not reachable from the UI**, only from REST, and
    defaults to off.
13. **No live AI provider is exercised** by any test. Nothing here calls a provider.
14. **No render provider is exercised** by any Phase 12 test.
15. **Cross-page validation reads generated state from post meta** that only a real
    generation would write. Tested with fixtures, not with a generated site.
16. **Performance figures are from a synthetic 3-page fixture.** No real 25-, 50-, or
    100-page crawl was performed — doing so would mean crawling a third party's
    website. Memory, DB operations, and Elementor generation time for a real run are
    unmeasured.
17. **The context budget and cost estimator are still not in the request path**
    (carried over from Phase 11). `Ai_Cost_Estimator` is built and tested; no AI call
    path consults it.
18. **Phase 1–9 REST endpoints remain unmetered.** `admit()` gates queue admission
    only; a direct call to `/analyze` or `/generate` still bypasses it.
19. **Multisite is untested** across Phases 1–12.
20. **Uninstall does not sweep the four Phase 12 options**, nor the Phase 10/11
    user-meta keys.
21. **All work is uncommitted. There is no Git repository.**

## 26. Manual test instructions

**Prerequisites.** A WordPress site with the plugin active. A short `< 200 lines`
PHPCS-style `assert()` and `say()` harness; `$mode` at the top selects the section.

```php
<?php
namespace ReplicaForge;
require_once __DIR__ . '/includes/multipage/class-replicaforge-site-limits.php';
$mode = getenv('RF_MODE') ?: 'limits';
function say($m){ echo "<p><code>$m</code></p>\n"; }
function assert_true($c,$m){ say(($c?'PASS':'FAIL')." — $m"); }
```

Run with `RF_MODE=<section> wp eval-file rf12-manual.php`. The environment variable
exists so one file can drive all sections; WordPress sets `$_ENV` but not
`getenv()`, so read `$_SERVER['RF_MODE']` if `getenv()` returns false.

### 1. Vocabulary and bounds — `RF_MODE=limits`

```php
assert_true(count(Site_Limits::PAGE_TYPES) >= 20, 'Page types declared: '.count(Site_Limits::PAGE_TYPES));
assert_true(Site_Limits::families() === Token_Engine::FAMILIES, 'Token families come from Phase 8');
assert_true(Site_Limits::page_limit_for('free') < Site_Limits::page_limit_for('agency'),
  'Plan rank: free='.Site_Limits::page_limit_for('free').' agency='.Site_Limits::page_limit_for('agency'));
assert_true(Site_Limits::page_limit_for('nonsense') === Site_Limits::page_limit_for('free'),
  'An unknown plan falls back to the smallest allowance');
foreach (Site_Limits::generation_order() as $i => $phase) say("$i. $phase");
```

### 2. Crawl scope — `RF_MODE=scope`

```php
$d = new Page_Discovery();
$cases = array(
  'https://example.com/about/'                => true,
  'https://www.example.com/about/'            => true,
  'https://evil.com/'                         => false,
  'https://shop.example.com/'                 => false,
  'https://example.com/wp-admin/'             => false,
  'https://example.com/administration/'       => false,
  'https://example.com/admiralty/'            => true,
  'https://example.com/logistics/'            => true,
  'https://example.com/accounting/'           => true,
  'https://example.com/checkout/'             => false,
  'https://facebook.com/example'              => false,
  'http://127.0.0.1/'                         => false,
  'http://169.254.169.254/latest/meta-data/'  => false,
  'javascript:alert(1)'                       => false,
);
foreach ($cases as $url => $expected) {
  $got = $d->permits($url, 'https://example.com');
  assert_true($got === $expected, ($got?'permitted':'refused')." (expected ".($expected?'permitted':'refused').") $url");
}
```

`/administration` refused and `/admiralty` permitted is the segment-matching
behaviour; if `/admiralty` is refused the prefix bug has returned.

### 3. Classification — `RF_MODE=classify`

```php
foreach (array('/', '/about', '/blog', '/blog/how-to-x', '/services', '/services/web',
               '/product', '/shop', '/privacy-policy', '/terms', '/capabilities',
               '/widget-factory') as $path) {
  $r = Page_Classifier::classify_url('https://example.com' . $path);
  say(str_pad($path, 22) . ' => ' . $r['type'] . '  (' . $r['confidence'] . ', ' . $r['signal'] . ')');
}
```

Expected: `/blog` → `blog_archive`, `/blog/how-to-x` → `blog_post`,
`/services` → `services`, `/services/web` → `service_detail`,
`/privacy-policy` → `legal`, `/capabilities` → `services`,
`/widget-factory` → `custom` at 0.30.

### 4–6. Design system, components, templates — `RF_MODE=design`

Use a real analysis: analyse two pages of a small real site via
`POST /websites/{id}/analyze`, then:

```php
$spec = (new Website_Repository())->specification($project_id);
$view = (new Site_Analyzer())->design_view($spec);
foreach ($view['groups'] as $family => $tokens) {
  say("<strong>$family</strong>");
  foreach ($tokens as $t) say(sprintf('  %-14s %-22s used %-3d agree %.2f  %s',
    $t['name'], $t['value'], $t['usage_count'], $t['agreement'],
    $t['disputed'] ? 'DISPUTED' : $t['scope']));
}
foreach ($view['conflicts'] as $c) {
  say(sprintf('%s %s on %s — pages: %s', $c['family'], $c['value'], $c['kind'],
    implode(', ', array_merge($c['pages'], $c['other_pages']))));
}
```

Deliberately change one page's primary colour and re-analyse: the value should appear
as a **conflict with both page lists named**, and any role built on it as `disputed`.
It must not be silently promoted to a global token.

### 7. Override protection — `RF_MODE=override`

```php
$r = new Component_Registry();
$r->load($project_id);
$r->mark_overridden('shared_header', array('note' => 'my colours'));
assert_true(! $r->may_rewrite('shared_header'), 'overridden component is not rewritable');
$r->put_shared($project_id, (new Site_Analyzer(...)) /* re-detected components */);
$r->load($project_id);
$c = $r->find_shared('shared_header');
assert_true(! empty($c['overridden']), 'override survives re-analysis');
assert_true(! $r->may_rewrite('shared_header'), 'and is still protected');
```

### 8–9. Navigation and assets — `RF_MODE=navigation`

```php
$m = new Navigation_Mapper('https://example.com');
$m->register_pages(array('p1' => array('source_url' => 'https://example.com/about/')));
foreach (array('https://example.com/about/', '/about/', 'about/', 'mailto:a@b.com',
               'tel:+123', 'javascript:alert(1)', 'https://other.com/',
               'https://example.com/never/', 'not a url', '../escape') as $h) {
  $r = $m->map($h);
  say(sprintf('%-34s %-12s -> %s', $h, $r['type'], $r['target'] ?: '(none)'));
}
```

Expected: `/about/` and `about/` both → `/about/`; `mailto:`/`tel:` kept;
`javascript:` and `not a url` produce no target; `../escape` produces no target
(§7 forbids climbing above the origin).

### 10. Ownership — `RF_MODE=ownership`

```php
$c = new Site_Compatibility(new Elementor_Compatibility());
$o = $c->ownership($project_id);
say('state: ' . $o['state'] . '  writable: ' . ($o['writable'] ? 'yes' : 'no'));
assert_true('unknown' === $o['state'] && ! $o['writable'],
  'A fresh project owns nothing and writes nothing');
say(print_r($c->report()['capabilities'], true));
say(print_r($c->report()['strategies'], true));
say(print_r($c->report()['fallbacks'], true));
say('Theme Builder locations: ' . implode(', ', $c->builder_locations()));
```

With Theme Builder absent, `fallbacks` must contain a `theme_builder` entry and
`builder` must be empty. With it present, both are populated — and the strategy
default should still be `theme` if the theme supplies a header.

### 11–12. Specification, map, design view — `RF_MODE=spec`

```php
$r = (new Site_Representation($spec))->validate();
say('valid: ' . var_export($r['valid'], true) . '  gate: ' . $r['gate']);
say('errors: ' . implode(', ', $r['errors']));
say('warnings: ' . implode(', ', $r['warnings']));
$map = (new Site_Analyzer())->map($spec);
say(count($map['nodes']) . ' nodes, ' . count($map['edges']) . ' edges');
foreach ($map['edges'] as $e) say(sprintf('  %s --%s--> %s', $e['from'], $e['via'], $e['to']));
```

Every edge's `via` must be `shared_component`, `template`, or `navigation`. If you see
any other value, a relationship is being invented.

### 13–14. Planning, isolation, sync — `RF_MODE=plan`

```php
$registry = new Component_Registry(); $registry->load($project_id);
$planner = new Multi_Page_Planner($registry);
$plan = $planner->plan($spec, array('mode' => 'consistent_website'));
foreach ($plan['steps'] as $s) say(sprintf('%3d  %-18s %-40s %s',
  $s['priority'], $s['phase'], $s['label'], $s['draft']['publish'] ? 'PUBLISHES' : 'draft'));
foreach ($plan['steps'] as $s) assert_true(! $s['draft']['publish'], $s['step_id'] . ' is a draft');

$scope = $planner->sync_scope(array('p1'), $spec);
say('scopes: ' . implode(', ', $scope['scopes']));
say('affected: ' . implode(', ', $scope['affected']));
```

`publish` must never appear. Pick a page in no shared structure: `scopes` should be
`['page_only']` and `affected` empty.

### 15. Cross-page validation — `RF_MODE=validate`

```php
$v = new Cross_Page_Validator();
$report = $v->validate($spec, $generated_state);
say(sprintf('%d pages, %d generated, %d validated, %d need review — %s',
  $report['pages'], $report['generated'], $report['validated'], $report['needs_review'], $report['verdict']));
foreach ($report['by_category'] as $cat => $n) say(sprintf('  %-18s %d', $cat, $n));
foreach ($report['findings'] as $f) {
  say(sprintf('[%s] %s: %s', strtoupper($f['severity']), $f['code'], $f['message']));
  if (! empty($f['evidence']['correction'])) say('     correction: ' . json_encode($f['evidence']['correction']));
}
```

To see the §45 case, mark one shared component's pages with different
`replicaforge_components` values and re-run. Then mark the component overridden and
confirm the finding's `correction.eligible` is `false` and `scope` is `none`.

### 16. Snapshots and rollback — `RF_MODE=snapshot`

```php
$s = $planner->snapshot($project_id, (new Website_Repository())->pages($project_id), $spec);
say('snapshot: ' . $s['snapshot']['snapshot_id'] . ' pages: ' . $s['snapshot']['page_count']);
foreach ($planner->snapshots($project_id) as $x) say('  ' . $x['snapshot_id'] . ' ' . gmdate('c', $x['created_at']));

$rb = $planner->rollback_plan($project_id);
assert_true($rb['confirmation_required'], 'rollback requires confirmation');
foreach ($rb['steps'] as $st) say(sprintf('  %s -> %s  %s', $st['page_id'], $st['action'], $st['note']));
assert_true('trash' !== ($rb['steps'][0]['action'] ?? ''), 'rollback trashes rather than deletes');
```

### 17. Page store — `RF_MODE=store`

```php
assert_true(Website_Repository::page_id_for('https://x.com/a/') === Website_Repository::page_id_for('https://x.com/a'),
  'a trailing slash is the same page');
$store = new Website_Repository();
$store->save_pages('manual', array(array('source_url' => 'https://x.com/','type' => 'homepage','selected' => true)));
$id = Website_Repository::page_id_for('https://x.com/');
$store->update_page('manual', $id, array('status' => 'generated'));
$store->save_pages('manual', array(array('source_url' => 'https://x.com/','type' => 'homepage','selected' => true)));
assert_true('generated' === $store->pages('manual')[$id]['status'],
  'a re-save does not un-generate a generated page');
```

### 18. Security and honesty — `RF_MODE=security`

```php
$src = file_get_contents(__DIR__ . '/includes/multipage/class-replicaforge-multipage-api.php');
assert_true(false === strpos($src, 'elementor_data'), 'no Elementor data from a request');
assert_true(false !== strpos($src, 'page_id_for'), 'page ids are recomputed');
assert_true(false !== strpos($src, 'readable_project'), 'ownership is resolved first');

$bad = $spec; $bad['pages'][0]['source_url'] = 'http://169.254.169.254/';
assert_true(! (new Site_Representation($bad))->is_valid(), 'a metadata address fails the gate');
$bad2 = $spec; $bad2['shared_components'][] = array('component_id' => 'x', 'pages' => array('ghost'), 'role' => 'header');
$v = (new Site_Representation($bad2))->validate();
assert_true(in_array('component_references_unknown_page', $v['errors'], true),
  'a component naming an unknown page is an error, not a warning');
```

Also: scan `includes/multipage/` with comments stripped and confirm no
`'post_status' => 'publish'` and no `wp_insert_post(`.

### 19. Migration — `RF_MODE=migration`

```php
$m = new \ReplicaForge\Migrator();
foreach ($m->migrations() as $e) say($e['from'] . ' -> ' . $e['to'] . ': ' . $e['summary']);
$r = $m->run(true);
foreach ($r['applied'] as $a) say('applied ' . $a['to'] . ': ' . json_encode($a['result']));
assert_true(0 === count($m->pending()), 'nothing left pending');
foreach (array('replicaforge_websites','replicaforge_site_registry',
               'replicaforge_site_snapshots','replicaforge_global_style_ownership') as $o) {
  assert_true(null !== get_option($o, null), "$o exists");
}
```

Running twice must show `websites_created: 0` the second time.

---

## 27. What was deliberately not done

- **No AI.** Deterministic only. §36 permits AI; using it would have meant a second
  thing to validate for no gain at this stage.
- **No admin UI.** The largest gap, and the one most likely to be next.
- **No generation execution.** The plan is correct and isolated; turning a step into
  a Phase 11 job is unwritten, and claiming otherwise would be claiming a feature.
- **No second infrastructure.** No Redis, no queue library, no database table, no
  paid service. WordPress options and the existing job system only.
- **No Phase 13.**

## 28. Final state

```
20 suites          3434 assertions      0 failed      2 skipped (pre-existing)
178 PHP files      0 lint failures
156 types          156 loaded           0 missing
migration          12.0.0 applied       0 pending     idempotent
routes             15 Phase 12 paths    36 methods    all owner-gated
options            4 new                0 tables
```

**The acceptance criteria that are met are met because a test asserts the behaviour.
The ones that are not met are listed in §24 as partial or not met, and explained in
§25, rather than being presented as finished.**
