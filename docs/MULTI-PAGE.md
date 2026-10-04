# Multi-Page Reconstruction (Phase 12)

Phase 12 changes ReplicaForge from a single-page tool into a website tool. The
question it answers is not "what is on this page" but "what is this website, and
what do all of its pages have in common".

Everything here is built on Phases 1–11. No existing system was replaced and no
second queue, project store, AI provider, validation engine, or security layer
was created.

---

## 1. The one decision everything else follows from

A single-page reconstruction can make every decision locally: the colours on this
page are this page's colours, the footer here is this page's footer. A website
cannot. The same header appears on forty pages, and if ReplicaForge reconstructs it
forty times then a user who edits one of them has edited a page that silently
disagrees with the other thirty-nine — and nobody finds out until the site looks
broken.

So Phase 12 introduces one concept that did not exist before: **a structure that is
built once and used in many places**. A shared component. A template. A design
token. Each of these has an identity, a set of pages that use it, and a rule about
what may be written to it.

Almost every other decision in this phase follows from taking that seriously.

---

## 2. What was reused, and what was added

| Concern | Phase 12 uses | Phase 12 adds |
|---|---|---|
| Token extraction | `Token_Engine` (Phase 8) | `Site_Design_System` — agreement and conflict only |
| Page analysis | `Analyzer`, `Design_Representation` (Phase 1–2) | `Page_Classifier` — website-level classification |
| HTTP safety | `Http_Client`, `Url_Validator` (Phase 1) | `Page_Discovery` — scope, not safety |
| Project ownership | `Project_Access` (Phase 10) | — (reused unchanged) |
| Entitlements | `Entitlement_Manager` (Phase 10) | `Site_Limits::page_limit_for()` reading plan rank |
| Elementor capabilities | `Elementor_Compatibility` (Phase 4) | `Site_Compatibility` — probing + ownership |
| Colour comparison | `Css_Value_Parser` (Phase 8) | `Cross_Page_Validator::same_value()` |
| Generation | Phase 4 writer, Phase 11 jobs | `Multi_Page_Planner` — ordering and isolation |
| Revision safety | WordPress revisions | `Multi_Page_Planner::snapshot()` — references only |

The design system is the clearest case. Phase 8's `Token_Engine` already takes a
flat list of per-element observations and produces deduplicated token sets with a
minimum-occurrence threshold of three — "one occurrence is a value, three is a
pattern". Feeding it observations from *every* page therefore produces the global
token set for free, and the threshold becomes exactly the right test: a value used
at least three times across the whole website.

So `Site_Design_System` does not reimplement token extraction. It adds the two
things that only exist above the page level: **agreement** (is this value genuinely
website-wide?) and **conflict classification**.

---

## 3. Page discovery is a refusal engine

A crawler is a request amplifier pointed at someone else's server, and the pages it
fetches are attacker-controlled input that arrives *after* the crawl has started.
Two distinct attacks follow, and `Page_Discovery` is built around both.

**The site points the crawler elsewhere.** A sitemap naming `169.254.169.254/`, a
link to `/admin/`, a redirect to a payment gateway. Every discovered URL is
therefore re-validated through `Url_Validator` *before it is queued* — validating at
fetch time would mean the frontier had already been poisoned by a URL pointing at a
private address.

**The site makes the crawler expensive.** Ten thousand links, a calendar, a tag
cloud. `MAX_LINKS_PER_PAGE` and `MAX_FRONTIER` are applied at *discovery*, not at
the end, so a hostile site cannot make ReplicaForge allocate its way through a list
it should never have collected.

### What is never crawled

| Refused | Why it is refused by path, not just by origin |
|---|---|
| `/wp-admin`, `/admin.php`, `/admin/*` | A site's own `/wp-admin` is same-origin and passes a pure origin check. |
| `/wp-login`, `/login`, `/register` | Authentication surfaces. |
| `/cart`, `/checkout`, `/payment` | §4 names checkout payment systems explicitly. |
| `/my-account`, `/dashboard`, `/settings` | Private areas and account management. |
| Social networks (as hosts) | A site whose homepage is a social profile is analysed as one page, not mirrored. |
| Any external domain | Except when `include_subdomains` is explicitly enabled. |

### The segment rule, and the one deliberate exception

`is_never_path()` matches **whole path segments**, not string prefixes. This is not
cosmetic. A `strpos()` prefix test on `/admin` also refuses `/admiralty`,
`/logistics`, `/accounting`, `/setting-up`, and `/cartography` — real pages on a
maritime museum's, a courier's, and an accountant's website, each dropped for a
reason that has nothing to do with security.

`/administration` **is** refused, even though universities, government sites, and
clubs all have a content page there. That is a deliberate choice of the worse
failure: a user who wants that page can add the address by hand, whereas a site
whose admin lives at `/administration/` would otherwise be crawled unauthenticated
and the user would have no way to know.

### Other discovery rules worth stating

- **Subdomains are off by default** and cover one additional label, so `www.` and
  `shop.` are included but a hosting provider's other customers are not.
- **Fragments are dropped** (they never reach a server) and **known tracking
  parameters are dropped**. Unknown query parameters are *kept*, because a query can
  select the page's content and treating `?p=2` as `?p=1` would analyse the wrong
  thing.
- **Sitemaps are read first** because they cost one request instead of a crawl, but
  they are only *suggestions* — every URL in one still goes through `accepts()`.
- **XXE is refused before parsing**: a doctype or entity declaration in a sitemap is
  never legitimate and is the shape an XXE takes. Locations are extracted with a
  textual scan, not an XML parser.

---

## 4. Classification is deterministic first

§8 allows AI to assist. `Page_Classifier` uses it for exactly one thing, and in
Phase 12 it does not use it at all — the deterministic path alone is implemented.

The order matters, because a URL path is evidence. `https://example.com/about/team/`
is a team page whether or not a model agrees, and asking a model first would mean
paying for an answer obtainable for free and *trusting* it more, since a model
cannot be shown its own reasoning afterwards.

### Exact roots versus prefixes

The table is split in two, and the split *is* the rule:

- `EXACT_ROOTS` matches only when the whole path equals the root. `/blog` is the
  archive; `/services` is the services index.
- `PREFIX_ROOTS` matches when the path is the root *or anything under it*.
  `/blog/how-to-x` is therefore a post and `/services/web` is a service.

Types appear in exactly one list, so no entry can shadow another, and adding a type
cannot change the behaviour of an existing one. (The first draft used one
prefix-matched list for both, which made the distinction depend on ordering — and a
pattern written `/blog/` against an already-trimmed path could never match at all.)

### `custom` is a real answer

A site with `/capabilities/` is not misclassified by being called `custom`; it is
misclassified by being called `about`. `custom` is what a page the evidence does not
identify gets, at 0.30 confidence, with the reason recorded. A wrong confident
answer is worse than an honest uncertain one, because the wrong one is applied to
every page of that type without anybody looking.

### Disagreement is reported, not resolved

When a path signal and a content signal disagree:

- content overturns a **weak** signal (path said `custom`) → content wins, and
  `overridden` is recorded;
- path survives a **strong** signal → the path is kept, and `conflict` names what
  the content suggested.

Picking one silently is how a misclassification becomes invisible.

### Never reconstructed by default

`legal`, `search`, and `category` are found but not selected. A site's terms and
privacy policy are *authored*, not reconstructed, and a generated copy is worse than
none; a static reconstruction of a search page or a category archive looks broken.

---

## 5. The design system reports disagreement instead of hiding it

This is the part of Phase 12 that most deserves reading.

Two pages disagreeing about a value is **not** a bug to be resolved by picking one.
Home at `#123456` and About at `#654321` might be a deliberate accent variation, a
genuinely different theme section, two component variants, or an extraction error —
and picking the majority answer in all four cases produces a design system that is
wrong somewhere and lies about it everywhere.

So:

- a value used on **every** page is a property of the website;
- a value used on **≥80%** of pages (`Site_Limits::GLOBAL_AGREEMENT`) is a strong
  candidate and is reported as `global`;
- anything less is recorded as a **conflict with evidence**: the pages on each side
  are named, `resolution` is empty, and `kind` is classified as far as arithmetic
  allows.

`kind` is deliberately conservative. Most cases resolve to `conflict` — "these pages
disagree and the data cannot say why". Only three are decided:

| `kind` | Decided by |
|---|---|
| `page_variation` | Exactly one page differs and at least three agree. |
| `component_variation` | A near-even split at 4+ pages — the signature of two genuine variants. |
| `conflict` | Everything else. |

A disputed role is **not enforced** on any page. Applying one side of an unresolved
conflict as the truth is exactly what §12 says not to do, so `design_view()` marks it
`disputed` and `Cross_Page_Validator` reports it for review rather than as a failure.

### Responsive: the intersection is the fact

Shared breakpoints are the *intersection* of what every page honours. A page that
breaks at a different width is a page-specific exception and is preserved as one.

---

## 6. Shared components are identified by structure, never by class names

§14 is explicit that HTML class names must not be the basis, and it is right for a
reason that shows up immediately: two pages from the same site routinely use
*different* class names for the same footer, because the footer is rendered by a
different partial, a page builder, or a cached fragment. A class-based matcher
reports one footer on a two-page site and then, on a five-page site, reports three.

A fingerprint is built from four things, in descending order of reliability:

1. **Role** — an element in `<header>` or with a landmark role is a header on every
   site that has one.
2. **Structure** — the shape of the tree beneath it: how many children, how deep,
   which types. A footer is a link list plus a paragraph, and that holds whether the
   class names match.
3. **Style signature** — the sorted set of declared visual properties.
4. **Content role** — what kind of thing it contains.

`canonical()` is order-independent *by construction*: a list element canonicalises to
its sorted values, not to its index. Order-independence is a property of the
function rather than a convention every caller has to remember.

### A shared component carries structure, never content

A registry entry stores the structure and the slot *positions*. The text lives with
the page. This is not tidiness: if shared components carried their text, correcting a
shared footer would rewrite every page's footer copy, and a user's edit to one page
would appear on four others. `Site_Representation::validate()` warns when a component
carries content, and a test asserts no slot carries a value.

`MIN_SHARED_PAGES` is two. A component on one page is that page's content, and
promoting it to a shared structure would mean editing a card on one page changing it
on another.

---

## 7. Templates and content slots

A template is a **section signature** shared by several pages of one type: five
service pages with hero, intro, features, CTA, footer are one template and five
instances of it.

The signature is the ordered list of section types, normalised. Content is excluded
on purpose — §25 says a template must not carry accidental page content, and a
signature containing text would guarantee it did.

Slots are derived from the **page type**, not from the first page found. A hero
title on service A and a hero title on service B are the *same slot* because they
have the same job; deriving slots per instance would produce a template with one
slot that happens to hold service A's text.

`MIN_TEMPLATE_SECTIONS` is three. Below that, two pages having the same shape is
coincidence rather than a pattern, and a template that cannot hold a real page is
worse than no template.

A template is only shown as generated when a real Elementor template exists (§64).
`Component_Registry::mark_generated()` is the only thing that sets that flag.

---

## 8. Navigation: three outcomes, and never a fourth

§22 says do not preserve the source domain in internal replica links. §23 says do
not invent a replacement for a page that was not reconstructed. Together they give
exactly three outcomes, and all three are implemented:

| Case | Result |
|---|---|
| Target was reconstructed | Rewritten to the local path. |
| Target was not reconstructed | Left pointing at the source and **flagged for review**. |
| Target is genuinely external | Left external. |

The tempting fourth option — rewriting an unmapped internal link to `/` or to a
search — is the one that produces a site full of links that go somewhere
meaningless. It is not offered.

### Relative addresses are resolved, not rejected

Real navigation is full of `href="/about/"` and `href="services/web/"`. Those are
resolved against the origin and then re-enter `map()` so a relative link and the
absolute link it denotes map identically and dedupe against each other.

`resolve()` refuses rather than guesses: a `..` that would climb above the origin
returns empty, and every segment must match `/^[A-Za-z0-9._~%!$&'()*+,;=:@-]+$/`.
Without that check, `'not a url'` resolves to `https://example.com/not a url` — a
link invented from a value that was not a link at all.

`javascript:` is dropped, not copied. `mailto:`, `tel:`, `sms:`, and `fax:` are kept
as supplied, because they address an application rather than a page. (The scheme is
resolved *before* the host is required — the schemes that need handling are exactly
the ones with no host, so a host-first order rejects every contact link.)

---

## 9. Assets: reference by default

`reference` is the default mode, and the reason is a legal and a practical point as
much as a technical one: importing an image copies it into the media library, which
means the replica now contains a copy of somebody else's asset. A user who wants
that can ask for it per asset. A user who did not should not silently accumulate
other people's images.

### Deduplication is by key, not by URL

A URL-keyed registry catches `logo.png` referenced from three pages and misses
`logo.png?v=2`. The key strips known cache-busting parameters (`v`, `ver`, `rev`, …)
and keeps everything else, because a parameter that is not on the known-boring list
might select the resource — merging `?id=4` and `?id=5` would collapse two products
into one asset.

Only *declared* dimensions are kept. A dimension guessed from the layout is not a
dimension, and a validator that treats it as one reports a mismatch that does not
exist.

`provenance` is recorded at **discovery**, not at import. An asset that was never
imported still has a source, and "where did this image come from" is a question a
user asks about the replica, not only about the import.

---

## 10. Global style ownership defaults to `unknown`

§17: *if ownership is uncertain, do not overwrite*. The whole design is one line, and
it has a consequence that is easy to get wrong — **uncertainty must be the default**.

A detector that guesses `theme_controlled` from "the colours look like the theme's"
will one day be right, and the day it is wrong a user's brand colours are overwritten
with what the detector thought they were. There is no undo in Elementor's global
settings, so the asymmetry is stark: refusing to write costs a settings toggle, and a
wrong write costs the design.

So ownership is **proved by ReplicaForge having written it before**, recorded in an
option. It is not inferred. If the option is absent the answer is `unknown`, and
`unknown` means do not write. There is deliberately no "adopt" shortcut: a user who
wants ReplicaForge to own their settings says so through `record_ownership()`, which
is a deliberate act with a deliberate state.

### Theme Builder is detected, never assumed

§48 and §50. `builder_locations()` asks Elementor Pro's public location manager and
returns what it says. A Theme Builder template is only offered when the location
actually exists, because generating a header template for a location the version does
not have produces a template that is invisible — it exists, it is stored, and it never
renders. A `try`/`catch` around it means a signature change degrades to page sections
rather than failing.

Every capability is asked of `Elementor_Compatibility` rather than derived from a
version number. A version comparison would also be wrong in the other direction: a
fork can add a feature without a version bump.

### Header and footer strategies

`theme` wins whenever the theme supplies one. Replacing a theme header silently
removes the menu the user already has, which is data loss rather than a style
difference. The choice is always accompanied by a reason, and the duplication warning
is shown when the theme already has one.

---

## 11. The website specification is a gate, not a report

`Site_Representation` is a distinct schema at `Site_Limits::SCHEMA_VERSION` (`12.0`),
not a page representation with extra keys. Reusing one version number for both would
make a cache key ambiguous and would make "is this stale?" unanswerable — a design
system change invalidates pages that have not themselves changed.

`validate()` returns a verdict, and `Multi_Page_Planner::plan()` refuses to produce
steps when the verdict is not valid. A closed gate means *no steps*, not a warning
attached to a plan.

**Errors** (gate closed): a page record without a source URL; a duplicate page id; a
component or template naming a page that is not in the project; a page on a private
or metadata address; a page type or status outside the declared vocabulary; a URL
mapping to an empty target.

**Warnings** (gate open): a design system that produced no tokens; an unresolved
conflict; a component carrying content; a shared component on one page.

The last one is worth naming: a design system that *cannot* be built is a warning,
not an error. A website whose pages produce no extractable tokens is a real website,
and refusing to generate it would be worse than generating it with page-level styling
only.

---

## 12. Generation: order, drafts, isolation

### Order (§39)

```
global_design → assets → header → footer → shared_components → templates
              → pages_primary → pages_secondary → pages_collection
              → navigation → responsive → validation
```

The reasoning is the same as the list's shape: the things everything else depends on
come first, so a failure part-way through leaves a usable site rather than three
pages that reference a design system that was never built.

### Everything is a draft, always

Enforced in one place — the `draft` flag on every step — and there is no code path
in `includes/multipage/` that sets a post status to `publish` or calls
`wp_insert_post()`. A test scans the Phase 12 directory with comments stripped and
asserts the absence, because "we never publish" is the kind of guarantee that erodes
one careless commit at a time. Generation is *planned* here and *written* by the
Phase 4 boundary.

### Failure isolation is structural

The plan is a list of independent steps, each with its own status. No step's failure
can change another's, a failed page is retried alone, and nothing here deletes a
draft because a sibling failed — which is the specific way §42 is usually
half-implemented.

---

## 13. Incremental sync: the scope is computed, not guessed

§58 requires that a change to one page must not rebuild the website.

`Multi_Page_Planner::sync_scope()` walks the registry — which components and
templates a changed page is part of, and what *else* uses them. A page in no shared
structure stays `page_only` and nothing else is listed as affected. A page that is
part of a shared header pulls in every other page using that header, and the finding
names both `changed_on` and `also_on`.

A design-system change is only claimed when there is a **known baseline** to compare
against. Rebuilding every page on an unknown baseline would be the exact opposite of
§58, so with no recorded `design_hash_known` the answer is "not shown to have
changed".

`POST /websites/{id}/sync` filters the submitted page list against the project's own
pages before the planner sees it, so a caller cannot name a page that is not in the
project and force a website-wide change.

---

## 14. Snapshots reference; they do not copy

§60 says to avoid unnecessary binary duplication where the storage system can
reference immutable versions. WordPress already keeps revisions, so a snapshot
records post ids, titles, statuses, content hashes, and the design-system hash — and
a rollback trashes pages, from which they are restorable.

Copying every Elementor document into a second store would double a project's size to
guard against a mistake that a post deletion already handles. History is bounded at
ten snapshots per project.

`rollback_plan()` returns a **plan, not an action**, and sets
`confirmation_required`. A rollback trashes pages, and trashing pages is not
something a POST should do before the user has read what it affects. §51's
"never overwrite" is enforced by `existing_conflict()`, which *reports* a page at an
occupied address rather than creating and hoping.

---

## 15. Content and structure are kept apart

`content_mapping` in the specification is the machine-readable form of §66.

**Structure** — sections, layout, components, spacing, styles, and which shared
components a page uses. **Content** — headings, body text, links, images, prices,
and the rights note. They are separate sections with separate purposes, which is
what makes §46 possible: correcting a shared structure never rewrites a page's copy.

§67's provenance requirement is met in the output a user actually reads: the asset
registry carries a `rights` note stating that a public address is not permission to
reuse, and the specification's content section says the same.

---

## 16. Security

Everything from Phases 1–11 is intact; §68 is a preservation requirement and the
suite asserts it. What Phase 12 adds:

| Property | Where |
|---|---|
| Discovery re-validates every URL before queueing | `Page_Discovery::accepts()` |
| Private areas refused by *path segment*, not prefix | `Page_Discovery::is_never_path()` |
| XXE refused before any sitemap parsing | `Page_Discovery::parse_sitemap()` |
| Link/frontier/page/time bounds at discovery | `Site_Limits` |
| No route accepts Elementor data or a document tree | asserted by source scan |
| Page ids are **derived** from the URL, never trusted | `Multi_Page_Api::save_pages()` |
| A submitted id that disagrees with the derived one is refused, not resolved | same |
| Project ownership resolved before the store is read | `Multi_Page_Api::owned()` |
| One message for "missing" and "not yours" | same — ids cannot be probed |
| Assets re-validated through `Url_Validator` | `Asset_Registry::inspect()` |
| `javascript:` links dropped, not copied | `Navigation_Mapper::map()` |
| All pages drafts; no publish path | asserted by source scan |
| Page limit from plan rank, clamped to the hard ceiling | `Site_Limits::page_limit_for()` |

`Multi_Page_Api` is a separate controller rather than more routes on `Rest_Api`,
because its authorisation shape is different: every route resolves a project first
and then asks whether the caller owns it.

---

## 17. Honest reporting

Three places where a flattering number would have been easy:

- **`confidence`** is arithmetic and stated, not a single score. A user deciding
  whether to trust a 40-page reconstruction needs to know *which* part is uncertain,
  and an average hides that.
- **The usage impact** returned by `POST /plan` is labelled `'estimate'` and carries
  `disclosure`: "This is an estimate based on the specification, not a measured cost,
  and it is not a billing amount."
- **A stage that produced nothing says so.** `stages.shared_components.reason`,
  `stages.design_system.reason`, and the registry's `note` all state the reason, so
  an empty list is never indistinguishable from a bug.

---

## 18. Known limitations

These are real and are not defects to be quietly fixed. They are recorded in
`docs/PHASE-12-COMPLETION-REPORT.md` §25 with the same detail.

1. **No admin UI.** Every screen §6, §44, §62–§65 asks for is unbuilt. The
   specification, the map, the design view, and the validation report are all
   available as REST responses; nothing renders them.
2. **Generation is planned, not executed.** `Multi_Page_Planner` produces the plan
   and `Job_Manager` is ready to run it, but the wiring that turns a plan step into a
   Phase 11 job is not written. No page has been generated by Phase 12 code.
3. **No AI is used anywhere in Phase 12.** §36 permits it; nothing implements it.
   Classification, component detection, and token interpretation are deterministic.
4. **`Source_Monitor` still does not exist** (Phase 9 built detection only), so
   `sync_scope()` has nothing to feed it. It is exercised directly.
5. **No website-level cache.** §57 asks for one; `Page_Discovery` and the analyzer
   are uncached, so a re-analysis refetches.
6. **Section identity across a structural change is unresolved.** A shared
   component's fingerprint changes if a section is inserted, so it becomes a new
   component. This is the prerequisite for meaningful incremental sync.
7. **No live provider is exercised.** Nothing here calls an AI provider, so no test
   covers a real response.
8. **Multisite is untested**, and uninstall does not sweep the four Phase 12 options.
