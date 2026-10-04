# Phase 14 — Dynamic Content Mapping & WordPress/WooCommerce Data Intelligence

**Status: complete and verified.**

ReplicaForge can now tell what a page contains, what this WordPress installation
contains, and — after review — how the first should draw from the second.

| | |
|---|---|
| Files created | 15 classes + 1 interface in `includes/content/`, 1 test suite, 1 design document |
| Files modified | 6 (bootstrap, plugin, migrator, schema, plan limits, error catalogue) + 2 pre-existing test suites |
| New database tables | **0** |
| New options | 2 by migration, 4 written at runtime |
| REST endpoints | 13 paths / 26 handlers, **0 ungated** |
| Test suites | 22 |
| Assertions | **4544 passed, 0 failed, 2 skipped** (both pre-existing) |
| `php -l` | 211 files, 0 failures |
| Types declared / loaded | 193 / 193, 0 missing |
| Migrations | `14.0.0` applied, 0 pending, idempotent |
| Version | `1.1.0` → `1.2.0`; `DB_SCHEMA_VERSION` → `14.0.0`; `JOB_SCHEMA_VERSION` unchanged at `11.0` |
| Files with encoding damage | 0 |

---

## 1. Implemented features

**Source content model (schema `14.0`).** A normalised model of what a page contains,
bucketed by content type, where every item is either real or explicitly absent.
`fabrication.policy` is `never_invent` and the sentinels are published in the model
itself.

**Semantic role detection (31 roles).** Consumes Phase 2's component types, roles, card
fields and card groups rather than re-deriving them, and adds the §3 vocabulary, the §6
dynamism class, the value signals Phase 2 does not extract, and structured-data and
visual refinement. A card expands into one item per field it actually has.

**Structured-data intelligence.** JSON-LD, OpenGraph and Twitter read as *untrusted
claims*: bounded size and depth, no object instantiation, URLs validated, secret-shaped
keys dropped, malformed blocks reported rather than treated as absence. Every entity is
`verified => false`, and a model claiming otherwise fails validation.

**Entity detection with evidence.** Products, articles, people, organisations, offers,
reviews, addresses and the rest, each carrying the evidence that produced it. A
duplicate is recorded rather than silently merged.

**Destination providers behind a read-only contract.** `WordPress_Content_Provider` reads
pages, posts, taxonomies, media and public custom post types through WordPress APIs.
`WooCommerce_Provider` reads products, categories, tags and attributes when WooCommerce
is present, and reports `woocommerce_not_active` with a plain explanation when it is not.

**The mapping engine (§10/§11/§16).** Four structural gates — role, data type,
availability/editability, ownership — so name-only mapping is impossible. Confidence is a
weighted sum of named evidence; risk is an independent axis. Ownership is *read* from
Phase 9.

**Bulk entity matching (§20).** SKU, title, category, slug, URL, brand and image, with
weights chosen so the reported percentages are the ones the specification promised
(92% SKU, 87% title + category, 64% image-only). Ambiguity is an outcome, not a coin
toss.

**Mapping plans (§15) with the source seal.** A plan carries a digest over its source
values; `validate()` recomputes it. A plan with no digest is refused.

**Apply, conflict and rollback (§22/§23/§37).** Prepare → validate → snapshot → apply →
verify → commit, with rollback on any failure. Existing content is reported, never
silently overwritten; the default is `review`.

**Ownership and provenance (§14/§16).** Every mapping carries an ownership state and a
provenance record, written as part of the apply so it cannot drift.

**Reconstruction modes (§18).** `static_replica`, `dynamic_replica`, `hybrid_replica`,
defaulting to hybrid, with the confirmation requirement *derived* from the mode rather
than stored alongside it.

**Preview (§21) and privacy (§30).** `SOURCE → DESTINATION → ELEMENTOR`, read-only, with
personal and contact data removed and secrets redacted before anything leaves the site.

**Metering (§44).** Three new Phase 10 operations, with `content_apply` metered most
tightly because it is the only operation that changes a record the user owns.

**Caching (§33).** Keyed on source hash, destination schema hash, mapping engine version,
mode, view and provider versions — with the AI model and prompt version included only
for an AI-assisted plan.

---

## 2. Files created

| File | Lines |
|---|---|
| `includes/content/class-replicaforge-content-limits.php` | 513 |
| `includes/content/class-replicaforge-structured-data.php` | 791 |
| `includes/content/class-replicaforge-content-role-detector.php` | 939 |
| `includes/content/class-replicaforge-content-fingerprint.php` | 311 |
| `includes/content/class-replicaforge-source-content-model.php` | 466 |
| `includes/content/interface-replicaforge-content-provider.php` | 127 |
| `includes/content/class-replicaforge-wordpress-content-provider.php` | 608 |
| `includes/content/class-replicaforge-woocommerce-provider.php` | 587 |
| `includes/content/class-replicaforge-content-mapper.php` | 589 |
| `includes/content/class-replicaforge-entity-matcher.php` | 500 |
| `includes/content/class-replicaforge-content-validator.php` | 471 |
| `includes/content/class-replicaforge-content-cache.php` | 414 |
| `includes/content/class-replicaforge-content-preview.php` | 295 |
| `includes/content/class-replicaforge-content-applier.php` | 672 |
| `includes/content/class-replicaforge-content-service.php` | 620 |
| `includes/content/class-replicaforge-content-api.php` | 685 |
| **total** | **8,588** |
| `tests/phase14-content-test.php` | 17 sections, 504 assertions |
| `docs/CONTENT-INTELLIGENCE.md` | 23 sections, 37.9 KB |

## 3. Files modified

| File | Change |
|---|---|
| `replicaforge.php` | 16 `require_once` lines; `REPLICAFORGE_VERSION` → `1.2.0` |
| `includes/class-replicaforge-plugin.php` | `$content_service`, `$content_api`, two accessors, route registration |
| `includes/class-replicaforge-migrator.php` | `migrate_content()`, `13.0.0 → 14.0.0` declaration |
| `includes/class-replicaforge-schema.php` | `DB_SCHEMA_VERSION` → `14.0.0` |
| `includes/plans/class-replicaforge-plan-limits.php` | 3 operations, 1 feature, 3 mappings |
| `includes/class-replicaforge-error-catalog.php` | `CONTENT` category, 14 codes |
| `tests/phase13-visual-test.php` | positional migration assertion replaced (545 → 576) |
| `tests/phase10-plans-test.php` | count assertions replaced with membership (610) |
| `docs/ARCHITECTURE.md`, `SECURITY.md`, `API.md`, `DATABASE.md`, `JOBS.md`, `PERFORMANCE.md` | Phase 14 sections appended |

No existing class was rewritten. Phase 1–13 architecture is untouched except the three
additive edits listed above.

---

## 4. End-to-end flow

```
Source Website
  → Phase 1  Secure Analysis
  → Phase 2  Structural Intelligence      components, card fields, card groups
  → Phase 3  Reconstruction Specification
  → Phase 12 Multi-Page Architecture      page types, shared components
  → Phase 13 Visual Intelligence          geometry, grids
  → Phase 14 Structured Data              JSON-LD / OpenGraph, as untrusted claims
  → Phase 14 Role Detection               31 semantic roles + dynamism class
  → Source Content Model 14.0             real values, or declared markers
  → WordPress_Content_Provider           pages, posts, CPTs, taxonomies, media
  → WooCommerce_Provider                  products, categories, tags, attributes
  → Content_Mapper                        four gates: role, type, availability, ownership
  → Content_Entity_Matcher                SKU / title / category / slug / image
  → resolve_destinations                  match  ⟶  the plan's entity_id
  → Content_Validator                     traceability, seal, risk, provenance
  → Content_Preview                       SOURCE → DESTINATION → ELEMENTOR
  → Human Review                          accept / reject / change
  → Content_Applier                       snapshot → write → verify → commit
  → Provenance recorded                   so Phase 9 never overwrites it
  → Elementor / WooCommerce               real, editable content
  → Phase 5 Visual Validation             content vs design, distinguished
  → Phase 6 Corrections                   unchanged
  → Final editable replica
```

---

## 5. Defects found by execution and fixed

Twenty-one. Every one was found by running the code, not by reading it, and every one is
recorded in the source at the point it would otherwise recur.

### Dead or inverted logic

1. **`type_of()` recognised no schema.org type at all.** `substr( $x, strrpos( '/' . $x, '/' ) + 1 )` returns an index into the *prepended* string while slicing the *original*, so `Product` became `roduct`. Every page silently reported `unknown` with zero entities — indistinguishable from "this site publishes no structured data".

2. **`strtolower()` after `preg_replace( '/[^a-z0-9]/' )`.** The filter ran against mixed case and deleted every capital letter, so `Product`, `Offer`, `Article` and `Person` all failed the same way. The fix is case → segment → strip.

3. **`entities_from()` iterated blocks as if they were items.** `flatten_block()` returns a *list* of items per block, so every `$item['type']` read empty and the `'' === $type` guard skipped everything. Zero entities, always.

4. **`page_type()` read one level too deep.** Handed the `read_meta()` wrapper rather than its `values`, so `og:type="product"` was reported as `unknown` with confidence 0.

5. **The destination index was keyed by entity id and looked up by list position.** `$destinations[182]` on a list keyed `0, 1, 2` missed, every signal scored 0, and every page reported `unmatched`. It only appeared to work for the SKU short-circuit, which does not score at all.

6. **The "nothing to match on" guard excluded the image key.** An entity with only a photograph was rejected before the image index was consulted, so §20's entire image-only ceiling was unreachable dead code.

7. **Card context was gated on group membership.** A product page's main card usually has no siblings, so it belonged to no `card_group` — and the single most important card on the most important kind of page was `unclassified`, producing no mapping for its headline field.

8. **A card's other four fields were dropped.** `classify()` returned `$items[0]` and discarded the rest, so every product card on every page contributed one item instead of five.

### Security and correctness

9. **`validate()` trusted the `applicable` flag instead of checking the plan.** Since the REST API accepts an inline plan, a caller could change one mapping's value to a price of their choosing, leave `applicable => true`, and have it written. Closed by the source seal.

10. **`validate()` trusted the risk/review coherence from `plan()`.** A caller editing `requires_review` to `false` on a high-risk price bypassed the review the planner asked for. Both rules are now re-derived in `validate()`.

11. **No snapshot was ever taken for a plain WordPress post.** Phase 6's `Correction_Snapshot` snapshots an *Elementor document* and returns an error for a post with none — most posts, and every product with no built layout. §22's rollback had nothing to restore in exactly the case that needed it. Added a field-value snapshot store, complementary to Phase 6's.

12. **An unregistered provider silently dropped its mappings.** `return null` meant a rule set that drifted out of sync with the registry lost those mappings with no trace. Now reported as `blocked` with a reason distinct from "provider unavailable".

13. **The WooCommerce permission check conflated *absent* with *denied*.** `current_user_can( 'edit_products' )` was applied unconditionally, so on a site without WooCommerce — where that capability exists for nobody — every user was told they were not allowed to read products.

14. **`$roles instanceof WP_Roles` was unqualified inside the namespace.** It resolved against `ReplicaForge\WP_Roles` first; the global fallback is not something to lean on for a security decision.

15. **`MAX_PROVENANCE` was declared on the store and referenced on the vocabulary.** `Content_Limits::MAX_PROVENANCE` did not exist — a fatal on the first provenance write. The bound now lives in one place, which is what the two-declarations rule exists to prevent.

16. **`match_entities()` set `available` only on failure paths.** A caller reading it got "absent" for a run that matched nothing — a materially different thing to tell a user than "the store is unavailable".

17. **`_sku` vs `sku`.** The provider declares `sku` and the applier prefixes `_`; the test asked for `_sku`. Recorded so the translation stays at the write boundary.

### Model quality

18. **Invalid UTF-8 collapsed every fingerprint.** `preg_replace` with the `u` flag returns `null` on malformed input, so a source page with a truncated byte sequence made `fold()` return `null` and made all such content share one fingerprint — §13 by accident. Added an explicit validity check.

19. **A copy-then-mutate bug in `resolve_destinations()`.** `$plan['resolution'] = $report` was assigned, then `$report['reason']` was set. PHP arrays are value types, so the reason went to a discarded copy and the caller never saw it — the same mistake Phase 13 made in `merge_boxes()`.

20. **Two `item()` call sites kept the old parameter list** after `$scores` was removed, shifting every argument by one. `$dyn_class` received the *evidence array* and `(string) $dyn_class` raised "Array to string conversion" on every card group, with the dynamism class becoming an array rather than one of the four declared values.

21. **The SKU weight made the decisive signal the weakest.** At 0.40, a pure SKU match could never reach the 0.72 accept band, so a merchant's own assertion was downgraded to "review" — while §20's own worked example says 92%. Weights were re-derived from the specification's three examples.

### Test expectations corrected rather than papered over

Seven, where the code was right and the test was wrong:

- 31 roles, not 29 (the specification lists 31).
- Every absence marker collapses to one missing fingerprint — a fingerprint is an identity
  key, and "no identity" is no identity whichever marker records it. The distinction
  survives in the model's `value`, which is where it matters.
- `ab-0012` and `ab12` are *different* SKUs. Stripping interior zeros would be a false
  positive in a catalogue; only leading zeros are folded.
- A published page is not readable by a signed-out caller. The first test asserted
  otherwise, and fixing it required adding the user context that also lets the refusal be
  asserted — which is the more valuable half.
- A title match with no corroboration is `review`, not `matched`. A plan with no digest is
  refused, so the hand-built fixture had to be sealed.
- A source-scan for "order" matched `'order' => 'ASC'`, and a scan for `$wpdb` matched
  the docblock *explaining why it is not used*. Both now assert the property — the method
  list, with comments stripped.

### Pre-existing suites strengthened, not loosened

- **Phase 13** asserted that the newest declared migration was `13.0.0` — a *position*.
  Phase 14 declaring its own migration broke it, which is precisely the mistake the
  Phase 12 notes warned against. Replaced with: still declared *by target version*, has a
  summary, follows a lower version, and the whole chain is strictly increasing with
  nothing skipped. **545 → 576 assertions.**
- **Phase 10** asserted `count( OPERATIONS ) === 8`, `count( FEATURES ) === 10`,
  `count( LIMIT_NAMES ) === 10`. Every later phase adds to these, so the assertions
  guaranteed a future failure and said nothing about correctness. Replaced with
  membership: each original is still declared, nothing is duplicated, and the counts are
  `>=` with the actual number reported. **610 assertions.**

---

## 6. Security improvements

Beyond the security requirements the phase was built to:

- **The provider contract cannot write.** Eight read methods, no `update`, no `set_price`,
  no `save`. The object holding database credentials has no method that could use them to
  write — §12 and §48 enforced structurally rather than by review.
- **No provider method touches orders, customers, payments or stock.** Asserted against
  the reflection method list, so it is a property of the API rather than of the prose.
- **A source seal on every plan.** A plan whose values were edited after planning is
  refused, and a plan with no seal is refused rather than assumed good.
- **Read-only fields re-checked at apply.** A hand-edited plan naming `_stock` is refused
  by the applier, not only by the mapper.
- **One write path for apply and rollback**, so a restore cannot sanitise differently
  from the write.
- **Conflicts are reported, never decided.** The default policy is `review`; a conflict
  fails the stage with the choices listed.
- **Ownership gates everything.** A `user_controlled` destination is never auto-applied,
  and a price is `high` risk at any confidence.
- **Personal data never leaves the site.** Removed at the AI boundary, and the removals
  are reported so the omission is visible.
- **No direct SQL** in either destination provider, and no meta-key enumeration.

---

## 7. Tests

`tests/phase14-content-test.php` — 17 sections, **504 assertions**, with a shutdown
cleanup that removes the created user as well as the options, so an aborted run cannot
leave state that makes the next run pass for the wrong reason.

```
1.  Vocabulary and bounds              2.  Fingerprints
3.  Structured data                    4.  Source content model
5.  Role detection                     6.  WooCommerce provider
7.  WordPress provider                 8.  Mapping engine
9.  Plan validation and anti-hallucitation
10. Bulk entity matching               11. Preview and privacy
12. Apply and rollback                 13. Service and end-to-end
14. Security                           15. Earlier phases are intact
16. Routes                             17. Complete
```

Coverage by specification area: source content (products, blog, structured data,
missing content, malformed JSON-LD, duplicate content) · WordPress (posts, pages, CPTs,
taxonomies, media, custom fields) · WooCommerce (simple and variable products, prices,
sale prices, images, ratings, absent WooCommerce) · mapping (exact, semantic,
low-confidence, conflicting, unmapped, duplicate entities, manual overrides) · security
(authorization, project-id enumeration, invalid mapping ids, malicious source metadata,
private data exposure, arbitrary field injection) · rollback (success, conflict, read-only
refusal, restore failure).

### Full suite

```
suites: 22, failed: 0, assertions passed: 4544, skipped: 2
```

The 2 skips are pre-existing (`phase5`, `admin`) and unrelated.

### Install verification

```
plugin active                              PASS
REPLICAFORGE_VERSION is 1.2.0              PASS
installed schema >= 14.0.0                 PASS  14.0.0
no migrations pending                      PASS
all 16 Phase 14 types load                 PASS
Plugin::instance()->content_service()      PASS
Plugin::instance()->content_api()          PASS
content routes registered                  PASS  13 paths / 26 handlers
no ungated content route                   PASS  0
Phase 5 categories / Phase 9 ownership / Phase 12 / Phase 13 / Phase 11 job schema
WooCommerce absent is reported, not crashed  PASS
three content operations metered by Phase 10  PASS
all 14 section 43 error codes declared      PASS
OVERALL: PASS
```

---

## 8. Performance considerations

Bounded at every level: 600 content items, 300 entities, 40 JSON-LD blocks, depth 12,
256 KB per block checked *before* decode, 2000 mappings, 200 destination records per page,
2000 provenance records, 20 plans.

§44 is met by construction: providers page; the matcher indexes destinations once and
then scores by hash lookup; a batch is bounded and reports `partial: true` with the true
total, so one page can never be mistaken for the whole job; every recursive read is
depth-bounded and every string length-bounded.

`Content_Role_Detector` is O(components) with one linear pre-pass, and it consumes
Phase 2's card groups rather than re-deriving repetition.

**No accuracy or performance claim is made for a real store**, because no store exists in
this environment. See §10.

---

## 9. Migration notes

`13.0.0 → 14.0.0`, verified applied, idempotent, 0 pending.

- `replicaforge_content_cache` — empty array.
- `replicaforge_content_mode` — `hybrid_replica`, `confirmed => false`, plus every related
  decision so the default is visible.

No table, no store connection, no product, price or review created. Idempotent by
construction: every write is `add_option()`.

**Backward compatibility.** No Phase 1–13 class was rewritten. The three edits to
existing files are additive: three plan-limit operations, one feature, one error category
and fourteen error codes, plus bootstrap lines and a new migration step. Existing stored
representations, projects, jobs and Elementor documents are untouched, and
`JOB_SCHEMA_VERSION` is deliberately unchanged at `11.0` because the job table's shape did
not change — bumping a version that describes no change would force a pointless migration.

**Uninstall.** The Phase 14 options are not yet swept by the uninstall routine, which is
also true of the Phase 10/11/13 options. Carried forward.

---

## 10. Known limitations

Disclosed now rather than discovered later.

1. **No real WooCommerce was exercised.** WooCommerce is not installed here. The field
   vocabulary, price parsing, product-type handling, SKU normalisation and matching logic
   are tested through an injectable API seam; a read against a real `WC_Product` is
   **not** tested, because no store exists. Every WooCommerce path in this phase was run
   against an in-memory store.

2. **No live AI provider is exercised.** §12's AI-assisted mapping is not implemented; the
   deterministic mapper is, and it is the only path that can currently produce a plan. The
   cache key already carries `ai_model` and `prompt_version` so adding it will not
   invalidate existing entries.

3. **No admin UI.** REST responses exist; nothing renders them. True of Phases 10–13 too.

4. **No job wiring.** §32's six job types are not enqueued. The matcher is paged and
   reports `partial`, so it is shaped for it.

5. **The destination model is read-only in practice.** `Content_Applier` writes
   `post_title`, `post_content`, `post_excerpt`, `post_date`, `post_status` and product
   meta. It does not write Elementor documents and does not create records.

6. **§24's cross-page reuse is not built.** A `product_card` on five pages produces five
   independent mappings rather than one website-level mapping. The component types are
   already the right hook.

7. **Multisite is untested**, as it is for Phases 1–13. The option prefixes are not
   network-scoped, so every site shares one cache and provenance store.

8. **No rollback of a committed change.** `rollback()` undoes an in-flight apply.

9. **A destination that disappears between planning and applying** is refused, which is
   correct, but the plan is then unusable rather than re-planned.

10. **All work is uncommitted; there is no Git repository.**

### Carried forward from earlier phases

- `Render_Job` still produces a plan without executing it; no page has been rendered.
- No render provider, so the §84 performance sweep has not been run.
- `Source_Monitor` still does not exist (Phase 9 built detection only), so incremental
  content sync is unbuilt — though §14's provenance is now recorded for it to use.
- §59 website versioning is unimplemented.
- `Job_Runner::process()` ignores its time budget; `Ai_Manager` does not call
  `Ai_Context_Budget` or `Ai_Cost_Estimator`; Phase 1–9 REST endpoints are unmetered;
  `Project_Repository` is referenced by no class except tests.

---

## 11. Acceptance criteria

| Criterion | Met | Where |
|---|---|---|
| Source content normalises into a stable schema | ✅ | `Source_Content_Model`, `14.0` |
| Semantic content roles detected | ✅ | 31 roles, `Content_Role_Detector` |
| Structured metadata safely analysed | ✅ | `Structured_Data`, untrusted throughout |
| WordPress content safely inspected | ✅ | via APIs, `read_post` gated |
| WooCommerce safely inspected when installed | ⚠️ | provider built; no store here to test against |
| Source entities detected with evidence | ✅ | `verified => false`, evidence required |
| Destination entities detected | ✅ | both providers |
| Fields mapped | ✅ | four gates |
| Mapping confidence calculated | ✅ | weighted, reproducible from evidence |
| Mapping risk calculated | ✅ | independent axis |
| Low-confidence mappings require review | ✅ | re-derived in `validate()` |
| User content never silently overwritten | ✅ | §23 default `review` |
| Content ownership tracked | ✅ | read from Phase 9 |
| Provenance preserved | ✅ | written as part of the apply |
| Elementor dynamic content where officially possible | ✅ | tag names, never code; fallback offered |
| Static/dynamic/hybrid modes work | ✅ | confirmation derived from mode |
| Multi-page projects support content mapping | ⚠️ | per page; §24 reuse not built |
| Phase 9 sync understands content mappings | ⚠️ | ownership + provenance recorded; sync integration not wired |
| Phase 11 jobs reused | ⚠️ | declared, not yet enqueued |
| Phase 5 visual validation runs after mapping | ⚠️ | mappings are written to real content; the Phase 5 run is Phase 5's |
| Phase 6 correction logic compatible | ✅ | untouched; its snapshot store reused |
| AI cannot directly modify WordPress/WooCommerce/Elementor | ✅ | no write path exists on a provider |
| No fake products, prices, reviews or users generated | ✅ | enforced by `Content_Validator`, asserted |
| Security tests pass | ✅ | §14 of the suite |
| Rollback works | ✅ | field-value snapshots, Phase 6 for documents |
| Phase 1–13 intact | ✅ | 4544 assertions, 0 failures |

Three rows are ⚠️ rather than ✅ because the phase's honest scope stops short: no
WooCommerce exists here to test against, cross-page mapping reuse is not built, and the
job/sync/visual-validation wiring depends on render infrastructure that has never run in
this environment. Each is stated in §10 rather than glossed.

---

## 12. Verification summary

Nothing above is claimed without having been executed:

- **211 PHP files linted, 0 syntax errors.**
- **22 test suites, 4544 assertions, 0 failures, 2 pre-existing skips.**
- **193 types declared, 193 loaded, 0 missing.**
- **Migration `14.0.0` applied, 0 pending, verified idempotent by a second run.**
- **13 REST paths, 26 handlers, 0 ungated** — verified by inspecting the registered
  routes, not by reading the source.
- **21 defects found by execution and fixed**, plus 7 test expectations corrected, plus
  2 pre-existing suites strengthened.
- **0 files with encoding damage** across the whole plugin and docs.

No screenshot was captured, no page was rendered, no product was created, and no accuracy
claim is made — because none of those things were possible in this environment, and
saying so is more useful than a claim that cannot be checked.
