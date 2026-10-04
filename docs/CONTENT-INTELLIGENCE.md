# Content Intelligence — Phase 14

ReplicaForge can now tell *what* a page contains and *where that content should come
from* in the user's own WordPress and WooCommerce installation.

This document is the design record. It explains what the content layer decides, why it
decides it that way, and — as importantly — what it deliberately refuses to decide.

---

## 1. The problem Phase 14 solves

Phases 1–13 could reproduce a website's *structure*: its sections, components, layout,
responsive behaviour, design system, and how faithfully an Elementor draft matched the
original. What they could not answer was the question a person actually has when they
point ReplicaForge at a competitor's shop:

> "I like that product card. I have my own products. Put my products in it."

Answering it means distinguishing five things that look identical from the outside:

| | Question it answers |
|---|---|
| **A. Structure** | What does the page look like? |
| **B. Source content** | What does the *original site* say? |
| **C. User content** | What exists in *this* WordPress install? |
| **D. Mapping** | Which of B belongs in which field of C? |
| **E. Rendering** | How should the mapped value appear? |

Collapsing any two of these produces a specific, recognisable failure. Collapse A and B
and you get a replica of the wrong site's *copy*. Collapse B and C and you overwrite a
customer's price with a competitor's. Collapse C and D and you have no destination at
all. Phase 14 keeps all five separate, in five separate classes.

---

## 2. The pipeline

```
Source Website
      ↓
Phase 1  Secure Analysis          SSRF, URL, size, MIME
      ↓
Phase 2  Structural Intelligence  sections, components, card groups
      ↓
Phase 3  Reconstruction Spec
      ↓
Phase 12 Multi-Page Architecture  page types, shared components
      ↓
Phase 13 Visual Intelligence      geometry, grids, relationships
      ↓
Phase 14 Content Intelligence  ←── this document
      ↓
Source Content Model          what the source page contains
User Content Model            what this site contains
Content Mapping Engine        which source field maps to which destination field
Dynamic Data Mapping Plan      a validated, reviewable proposal
      ↓
Human Review                   accept / reject / change
      ↓
Apply Mapping                 snapshot → write → verify → commit
      ↓
Elementor / WooCommerce       real, editable content
      ↓
Phase 5 Visual Validation     content vs design differences, distinguished
```

The order is not a convenience. `Structured_Data` must be read before roles are
detected, because roles are refined by structured-data claims. Roles must be detected
before a model is built, because the model *is* the roles. A model must exist before it
is mapped. A mapping must be planned before it is validated. Only a validated plan is
applicable. `Content_Service` expresses that order once so no caller can get it wrong.

---

## 3. Files

| File | Responsibility |
|---|---|
| `includes/content/class-replicaforge-content-limits.php` | The vocabulary and every bound |
| `includes/content/interface-replicaforge-content-provider.php` | The destination provider contract (read-only) |
| `includes/content/class-replicaforge-structured-data.php` | JSON-LD / OpenGraph / Twitter, as untrusted data |
| `includes/content/class-replicaforge-content-role-detector.php` | §3 semantic roles, on top of Phase 2 |
| `includes/content/class-replicaforge-content-fingerprint.php` | Identity keys, and the §20 matching keys |
| `includes/content/class-replicaforge-source-content-model.php` | The `14.0` model, and the §13 check |
| `includes/content/class-replicaforge-wordpress-content-provider.php` | §7 destination WordPress |
| `includes/content/class-replicaforge-woocommerce-provider.php` | §8 destination WooCommerce |
| `includes/content/class-replicaforge-content-mapper.php` | §10/§11/§16 the four mapping gates |
| `includes/content/class-replicaforge-entity-matcher.php` | §20 bulk matching |
| `includes/content/class-replicaforge-content-validator.php` | §15 plans, §13 enforcement, the source seal |
| `includes/content/class-replicaforge-content-cache.php` | §33 caching, §14 provenance, field snapshots |
| `includes/content/class-replicaforge-content-preview.php` | §21 preview, §30 redaction |
| `includes/content/class-replicaforge-content-applier.php` | §22 apply, §23 protection, rollback |
| `includes/content/class-replicaforge-content-service.php` | The facade, and §20→§15 binding |
| `includes/content/class-replicaforge-content-api.php` | §31 REST |

---

## 4. Vocabulary is read, never restated

The single most important structural decision in the phase.

**Ownership** — `source_controlled`, `user_controlled`, `mixed`, `unknown` — already
existed as `Sync_Conflict_Detector::OWNERSHIP` from Phase 9, and Phase 9's conflict
resolution is written against it. `Content_Limits::ownership_states()` **reads** it.

If Phase 14 had declared its own copy, a mapping could be `user_controlled` in the
mapper and `source_controlled` in the sync layer, and the second one would win when a
source page changed — overwriting the user's own price with the competitor's. The test
asserts the two lists are the *same array*, not merely equal in length.

The same rule applies elsewhere: difference categories and severities come from Phase 5.
Every bound is declared once, in `Content_Limits`.

---

## 5. Semantic role detection

### Why roles at all

An `<h1>` is not a hero heading. On most pages it is; on the rest it is a page title in
a breadcrumb, a modal, or a footer. An `<img>` is not a product image — it may be a
logo, a spacer, a background, or the photo the product is sold with.

The tag says what the markup *was*. The role says what the content is *for*, and only
the role can be mapped to a destination field.

### What Phase 2 already knew

Phase 2's `Component_Detector` already classifies every element, already extracts card
fields (`image`, `title`, `price`, `sale_price`, `rating`, `badge`, `button`, `link`),
and already groups repeated cards into a `card_group`.

The first draft of `Content_Role_Detector` re-derived all three — its own price regex,
its own repeated-block bucketing, its own heading heuristics. It would have disagreed
with Phase 2 wherever the two differed, which is the specific way a content model
becomes wrong while looking complete.

So this class **consumes** Phase 2 and adds only what Phase 2 structurally cannot:

1. the §3 semantic vocabulary;
2. the §6 dynamism class, which depends on the *mapping* destination and so cannot be
   decided at analysis time;
3. value signals Phase 2 does not extract — dates, emails, telephone numbers, author
   lines, "out of 5" ratings;
4. structured-data and Phase 13 visual context as *refinements*.

### Card context comes from the card itself, not only from a group

A product page's main product card usually has **no siblings**, so it belongs to no
`card_group`. The first draft gated card context on group membership, so the single most
important card on the most important kind of page scored nothing from its own type and
fell through to `unclassified` — a product page with one product produced no
`product_name` and therefore no mapping for its headline field.

Card type is now the group's when the component is a member, and the component's own
type otherwise. The evidence line records which, because "this is a product card" and
"this is one of four product cards" are different evidence.

### A card is composite

Phase 2 stored five fields on the *one* component. §2's model has a bucket per role, so a
card is expanded into one item per field it actually has. The fields it does *not* have
are simply absent — never placeholders. A card with no rating produces no
`product_rating` item, and that absence is the finding.

The first draft returned only the first expanded item, silently dropping the other four
fields of every product card on the page.

### `unclassified` is a real answer

An icon, a badge, a divider — genuinely no mappable role. Inventing one would be exactly
what §13 forbids. A wrong role is worse than no role, because a wrong role maps a
paragraph into a price field.

### Confidence is capped at 0.9

A role detected from markup is never a certainty: the source may be using its class
names for something other than what they say. A confidence of 1.0 would let a mapping
auto-apply on the strength of a guess.

---

## 6. Structured data is a claim, never a fact

JSON-LD is author-controlled content on a site ReplicaForge has not authenticated
against. A page can put anything in it, including a `@type` of `Product` on a page with
no products, an `offers.price` of `"0"`, a `url` of `http://169.254.169.254/`, or a
500-deep `@graph`.

So `Structured_Data` treats every value as **unverified**:

- JSON-LD is parsed with `json_decode( …, true, 12 )` — data only, bounded depth, no
  object instantiation, no `$ref` resolution, no `eval`, no `unserialize`.
- Size is checked *before* decode, because `json_decode` on a 50 MB string is a memory
  exhaustion and the bound has to be the length check.
- Every URL goes through `Url_Validator`. A metadata-endpoint or loopback URL is
  **dropped and recorded**, never stored — storing an address ReplicaForge has just
  refused is how a blocked target becomes a stored one.
- Any key that looks like a secret is dropped, so a hostile page cannot plant something
  in ReplicaForge's own records.
- Malformed JSON-LD is a **warning with a reason**, never treated as absence of data.
  Templates emit trailing commas constantly; "we could not parse it" and "there was
  nothing there" are different facts.
- Every result carries `trust: 'unverified_source_claim'` and a plain-language note.

Every entity is `verified => false`. `Source_Content_Model::validate()` treats an entity
claiming verification as an **error**, because verification is a destination-side concept
and a source page cannot supply one.

### Two bugs worth recording

The type matcher failed to recognise **any** schema.org type at first, and the symptom
looked like "this site publishes no structured data":

1. `substr( $x, strrpos( '/' . $x, '/' ) + 1 )` returns an index into the *prepended*
   string while slicing the *original*, so `Product` became `roduct`.
2. `strtolower()` applied *after* `preg_replace( '/[^a-z0-9]/', … )` let the filter run
   against mixed case, deleting every capital letter — so `Product` became `roduct`
   again, and `Offer`, `Article` and `Person` all failed the same way.

And `page_type()` was handed the `read_meta()` **wrapper** rather than its `values`,
so the `og:type` lookup was one level too high and a perfectly good `og:type="product"`
was reported as `unknown` with confidence 0. A wrong nesting level fails silently every
time, because nothing about it looks wrong.

---

## 7. Destination providers, and why the contract has no write method

`Content_Provider_Contract` has eight methods and every one of them reads.

There is no `update()`, no `set_price()`, no `save()`. §12 and §48 forbid AI and mapping
logic from modifying products, prices, inventory, orders or users — and the way to make
that *structurally* true is for the object that holds the database credentials to have
no method that could use them to write. The test asserts the WooCommerce provider's
public surface is exactly the contract plus a constructor and the documented
`set_api()` seam, and that no method name contains `order`, `customer`, `payment` or
`stock`.

### WooCommerce is optional, and its absence is a reported state

`is_available()` detects the real thing. An unavailable provider reports
`woocommerce_not_active` with a plain explanation, and its entity page returns
`empty => false` so **"no store" can never be read as "you have no products"**.

### The permission check distinguishes *absent* from *denied*

A first draft applied `current_user_can( 'edit_products' )` unconditionally. On a site
without WooCommerce those capabilities do not exist for anybody, so every user was
reported as "not allowed to read products" — a false accusation that also made any
injected or future provider look broken.

`user_may_read()` now has three outcomes: no role on the site holds the capability, so
the question does not apply; the user holds one; or the capabilities exist and the user
holds none.

### Custom fields are detected, never enumerated

§39 permits detecting a *structure*. This provider reads an injected report and never
calls `get_post_meta( $id, '', true )`, because that would pull the *values* of every
meta key on a post into memory — and a meta key on a real site can be a licence key, a
payment token, or a customer's private note. A detected custom field is offered at
confidence 0.4 with `requires_review => true`, because a key called `price` on a
`property` CPT means something entirely different from `price` on a `product`.

### `_stock` is listed but not writable

`fields_for( 'product' )` includes `_stock` so a user can see it exists, and marks
`editable => false`. `Content_Mapper` refuses a mapping onto a non-editable field, so
§8's "do not modify inventory" is enforced by the data rather than by anyone
remembering to check.

---

## 8. The four mapping gates

§10 says *do not map fields purely by name*. Name matching is the obvious
implementation and it is wrong in a way that costs money: a source `product_price` is a
`text` field as far as a name comparison is concerned, so it maps happily onto
`post_title` — and the replica then shows `$49.99` where a product's name belongs.

Every mapping must pass four structural gates.

**Gate 1 — role.** The source role must be in the rule's accepted set.

**Gate 2 — data type.** `Content_Limits::types_compatible()` must hold, from §10's own
table. This is what refuses `product_price → post_title`, because `currency` does not fit
`text` in that direction. A `text` value can never reach a `currency` field whatever the
names say.

**Gate 3 — availability and editability.** The provider must be registered *and*
available, and the field must say it can be written. An unregistered provider is a
different fault from an inactive one, and it is reported separately: the first draft
skipped it with `return null`, which meant a rule set that drifted out of sync with the
registry lost those mappings with no trace at all — the same silence as "this page has
no products", for a reason entirely within ReplicaForge's control.

**Gate 4 — ownership.** A `user_controlled` destination is never auto-applied, and a
price is `high` risk however confident the mapping is. Risk and confidence are
independent axes: a mapping can be certain and dangerous (an exact role match onto a
price), and uncertain and harmless.

### Confidence is reproducible from its evidence

Every weight is in `Content_Mapper::WEIGHTS` and sums to 1.0, so a confidence is
readable as "how much of the case is made", and a user who disputes it can be shown
exactly which term is small. A mapping with no evidence scores 0 and is rejected.

---

## 9. §13 as a control, not a promise

> The system must never invent a product, price, SKU, review, author, address, URL or
> phone number.

A prohibition in prose is not a control. `Content_Validator` turns it into checks that
run on **every** plan, and a plan that fails them is refused rather than applied.

**Traceability.** A mapping's value must be resolvable in the source model, or be a
declared marker. A value that differs from the model's is a value that came from
somewhere else.

**The source seal.** `validate()` — which is what the applier and the REST layer
actually call — recomputes a digest over the plan's `content_id → value` pairs and
compares it with the digest recorded when the plan was built. A plan with **no** digest
is refused, because "no proof it was checked" is not "no problem".

This closes a real hole. The first draft had `validate()` trust the `applicable` flag
that `plan()` had written — and that flag is a claim *about* the plan, not a check *of*
it. Since the REST API accepts an **inline** plan, a caller could take a valid plan,
change one mapping's value to a price of their choosing, leave `applicable` at `true`,
and have it written.

Binding a destination `entity_id` does **not** break the seal. Choosing which record to
write to is a decision a user may change; inventing a source value is not.

**One field, one value.** The mapper legitimately emits several candidates for one
content id. They must all carry the same source value. Two mappings claiming different
values for one source field is refused, not resolved.

**Structurally impossible actions.** There are four: `map`, `unmap`, `ignore`,
`review`. An action outside that set cannot be expressed, which is how "arbitrary
database operation" is prevented rather than discouraged.

**Coherence, re-derived.** `validate()` independently enforces that high-risk mappings
require review and that a `map` below the auto-apply floor requires review. The first
draft enforced both only in `plan()`, so a caller editing a plan could flip
`requires_review` to `false` and bypass the review the planner asked for.

### The absence vocabulary

`null`, `not_detected`, `unmapped`, `unknown` are the only correct answers for missing
data. They are distinguished on purpose: `null` is "the field exists and is empty",
`not_detected` is "ReplicaForge looked and it was not there". Collapsing them loses the
difference between an empty price and an undetected price field — the difference between
rendering `$0.00` and rendering nothing.

A `'0'` is **not** a marker. A real `$0.00` is a value, and treating it as absent would
be a way to lose one.

Fingerprints *do* collapse every marker to one "missing" key per role, because a
fingerprint is an identity key and "no identity" is no identity whichever marker records
it. The distinction is preserved where it matters: in the model's `value`, which stores
the marker verbatim and reports absences as a separate count.

---

## 10. §20 matching, and the numbers it produces

§20 gives three worked examples: **92% for a SKU match**, **87% for title plus
category**, **64% for image similarity alone**. The weights are set so that those are the
numbers a user actually sees.

| Signal | Weight | Why |
|---|---|---|
| `sku` | 0.92 | A merchant's own identifier for one specific variant. Two products sharing one are the same product *by definition* — evidence of identity, not similarity. Clears the accept band alone. |
| `title` | 0.55 | Suggestive, not proof. Alone it stays below the band, because two shops may both list a "Wireless Headphones" meaning different things. |
| `category` | 0.32 | Corroborating. Cannot identify a product, only narrow candidates. With a title it reaches 0.87 and matches. |
| `slug` | 0.30 | A URL convenience; two products can share one across categories. |
| `url` | 0.20 | Near-useless across two sites with different address schemes. Recorded because §20 asks for it, and because it is *nearly* useless, which is worth stating. |
| `brand` | 0.20 | Corroborating. |
| `image` | 0.64 | The highest non-identifying score — high enough to be *offered*, capped so it is never applied. |

The first draft weighted SKU at 0.40, which meant a *pure* SKU match could never reach
the 0.72 accept band and a merchant's own assertion was downgraded to "review" — the
signal the specification calls decisive, treated as the weakest.

### Image similarity is offered, never applied

Two stores importing the same catalogue routinely use the same manufacturer's photo for
several variants, so a photo match is very often *correct* and completely useless: it
points at a family of products, not a product.

Three things enforce this:

- `image_only` is set when no non-photographic signal fired;
- the score is capped at `IMAGE_ONLY_CEILING` (0.55), which sits *below* the accept band;
- the status rule names the condition directly, so raising the ceiling later can never
  quietly turn image matching into automatic matching.

With one candidate the result is `review`; with two products on one photograph it is
`ambiguous` — which is the honest answer, because the photo points at a family.

### Ambiguity is a first-class outcome

Two candidates within 0.05 of each other are `ambiguous`, with both listed and **no
winner named**. Picking one is a coin toss dressed as a match, and a coin toss that
moves product data is worse than an unanswered question. A SKU separating them is
exactly why SKU is worth 0.92.

### The index bug that made matching impossible

`index_destinations()` keyed its indexes by **entity id** and then looked them up with
`$destinations[ $id ]` — but `$destinations` is a list keyed `0, 1, 2, …`, so a lookup of
product `182` missed and returned an empty record. Every signal scored 0, every page
reported `unmatched`, and the matcher looked like it was *refusing* to match rather than
being unable to read its own candidates. It only appeared to work for the SKU
short-circuit, which does not score at all — exactly the kind of bug that survives a
passing test.

Indexes now store **positions**, with an `at` map to convert back, and the reported
entity id always comes from the record.

### The image path was unreachable

The "nothing to match on" guard listed only SKU, title and slug, so an entity carrying
nothing but a product photo was rejected **before the image index was consulted** — and
the entire image-only ceiling was dead code. Image fingerprint is a matching signal
precisely for the case where a product has no SKU and no distinctive title.

---

## 11. From matching to mapping

A mapping says *which field of which kind of entity* a source value belongs in. It
cannot say *which product* — that is a per-project fact discovered by matching.

So plans are built with `entity_id` at zero, and `Content_Service::resolve_destinations()`
fills it in. Until it runs, every mapping is a field mapping with no entity and
`eligible_for_apply()` correctly withholds all of them with
`destination_entity_not_resolved`. That withholding is the honest state: ReplicaForge
does not know which of ten thousand products a price belongs to until a human has had a
chance to say.

With **several** matched products — a product archive with four on it — the mappings are
*not* spread across them by index. "The price on the page goes to whichever product the
matcher happened to list first" is an invention. The caller names the record, or the
report says why it did not.

This is the link that makes §41 work end to end: analyse the card design, match the
source products to the store's, bind the match, and the same mappings that were
withheld a moment ago become applicable — against the user's own data, with the
source's structure untouched.

---

## 12. §22 apply, and what rollback actually restores

```
Prepare → Validate → Snapshot → Apply → Verify → Commit
                                     ↓ on any failure
                                  Rollback
```

WordPress has no transactions, so atomicity comes from two things:

**A snapshot before the first write.** Phase 6's `Correction_Snapshot` snapshots an
*Elementor document* — its element tree and responsive overrides — and `create()` returns
an error for a post with no Elementor data. That is most WordPress posts, and every
product with no built layout. Reusing it alone meant that in exactly the case that
needed a snapshot, **none was taken and rollback had nothing to restore**.

So `Content_Cache::store_snapshot()` records the *field values* about to change, once
per destination record. Four mappings onto one product produce one before-image holding
all four old values, not four snapshots each stale for the other three. The two
mechanisms are complementary, not duplicate: this stores values, Phase 6 stores
documents.

**No partial success.** A write that cannot land is recorded and the stage returns
`failed`. §22's requirement that ReplicaForge never leave partially corrupted documents
is met by never reporting success while holding a partial result.

**Write, then read back.** A write that reports success and does not land — a sanitiser
that stripped it, a hook that overwrote it — is caught by re-reading. The comparison is
against the *sanitised* incoming value, or every field with markup would report a false
failure.

**One write path.** Apply and rollback both go through `write_field()`. A restore that
sanitised differently from the write would quietly leave a third value behind. A `null`
in a snapshot means "the field was not set", and restoring it *removes* the meta rather
than writing an empty string — a blank where there had been no value is a different
state, and a confusing one to debug.

---

## 13. §23 — existing content is never silently overwritten

`resolve_existing()` reports four states:

| State | Meaning | Action |
|---|---|---|
| `empty` | Nothing there | write |
| `unchanged` | Same value | skip — nothing changes, so nothing is at risk |
| `conflict` | Different value, default policy | **skip, and report** |
| `overwritable` | Different value, caller asked for overwrite | write |

The default is `review`, so a destination that already holds a different value produces a
**conflict** and the whole stage fails with the conflicts listed. §37 is explicit that a
conflict is never decided automatically, and `rollback_required` says in its message
that ReplicaForge put the change back — because "something went wrong" during a write is
exactly when a user needs to know their data is intact.

---

## 14. §18 modes, and why the default forces review

| Mode | Behaviour | Confirmation |
|---|---|---|
| `static_replica` | Reconstructs the source content as captured | not required |
| `dynamic_replica` | Uses the user's own content | **required** |
| `hybrid_replica` | User data for anything that changes, approved source copy for the rest | **required** |

The default is `hybrid_replica`, written down by the migration so the default is
*visible* in the site's options rather than existing only as a fallback in code. The
confirmation rule is **derived** from `Content_Limits::mode_requires_confirmation()`
rather than stored, so the two can never disagree.

A mode that replaces source content forces `requires_review` on every mapping inside it.
That is why, in the default mode, nothing applies automatically — and it is the correct
outcome, not a limitation.

---

## 15. §30 privacy, applied at the boundary

Redaction happens on the way *out*, not in storage. A product description is content the
user owns; redacting it in the database would corrupt their data.

`Content_Preview::for_ai()` does three things in order:

1. **Removes** personal and contact items entirely — `blog_author`, `review_author`,
   `phone`, `email`, `address`. A person's name is not a secret, but it is not needed to
   classify a layout either, and sending one would be a privacy failure for nothing.
2. **Redacts** secrets from what remains, via the existing reviewed
   `Data_Redactor::structure()`.
3. **Truncates** to 200 items and 500 characters each.

The removals are reported in `dropped`, so the omission is visible rather than silent.
Entity *values* are never sent — only entity *types* — because a JSON-LD block is
author-controlled and may contain anything.

---

## 16. §31 REST

Thirteen paths, all authenticated, capability-checked, and project-ownership-resolved.
Content mapping is the first ReplicaForge capability that can **write to the user's
data**, so the boundary is tighter than for any earlier phase.

**No route accepts a mapping, a field, or a destination.** A caller names a *plan* — an
opaque id the validator already checked — and a list of *mapping ids within that plan*
to approve. There is no route at which a caller can name where a value goes. Approved
ids are pattern-checked (`/^map_[a-f0-9]{8,32}$/`) before they reach a comparison or an
option name, and the test feeds a batch of hostile values through the real method
rather than regex-matching the source.

**One message for "missing" and "not yours"**, so a project id cannot be probed.

**Writes need a stronger capability than reads.** `apply` requires
`replicaforge_manage_plans`, not `replicaforge_use`.

**Stored plans are re-read from the store**, never trusted from the request, so a caller
cannot apply a plan the validator never saw. An inline plan is accepted — §31's endpoints
are shaped around a plan document — but it is validated identically and its `project_id`
is *replaced*, not honoured.

---

## 17. Metering

Three new Phase 10 operations, because content mapping has three genuinely different
costs and one combined counter would price them wrongly:

| Operation | Cost |
|---|---|
| `content_analysis` | Reading a page's content — cheap |
| `content_mapping` | Building a plan — moderate |
| `content_apply` | **Writing into the user's own store** — metered most tightly |

`content_apply` is the only operation in the plugin that changes a record the user cares
about, as opposed to a draft ReplicaForge owns. All three gate on the
`content_mapping` feature, because there is no coherent product in which a user may map
content but not see what the mapping says.

---

## 18. Caching

The key folds in: project, source content hash, destination schema hash, **mapping
engine version**, schema version, mode, view, provider versions — and the AI model and
prompt version *only* for an AI-assisted plan.

Every one of those is there because omitting it returns a plausible wrong answer rather
than a miss. Omitting the engine version means a cache entry survives an engine upgrade
that changed the rules — and for a *mapping* that means a source price written to the
wrong product. Omitting the destination schema hash means a plan naming fields that no
longer exist, and the first thing a user sees is a mapping screen full of errors.

Including the AI model unconditionally would be the mirror mistake: it discards every
valid cache entry the moment a provider key changes, even though a deterministic plan's
result cannot depend on which model is configured.

Provenance is the one thing that must **outlive** the cache. It is the evidence that a
user's price was not overwritten, so a re-analysis must never erase it.

---

## 19. §21 preview

`SOURCE → DESTINATION → ELEMENTOR`, each stage shown because each can fail differently.
A source value can be absent, a destination field can refuse it, and a widget may have
no dynamic tag for it. A preview showing only the first two would say "mapped" for a
value that then rendered as an empty widget.

A preview reads. It never writes, never reserves usage, and never enqueues a job — §19's
"never make destructive changes from the mapping preview", enforced by the fact that
`Content_Preview` has no path to `Content_Applier`.

§17's Elementor dynamic tags are tag *names* (`post-title`, `woocommerce-product-price`),
never PHP, never a shortcode. Where no tag exists the fallback to static content is
**offered** with `fallback_approved => false` — taking it silently would change what the
page shows.

---

## 20. Migration

`13.0.0 → 14.0.0` creates two options and nothing else:

- `replicaforge_content_cache` — an empty array rather than absent, so a user can see
  that content caching exists and is empty rather than wondering why analysis is failing;
- `replicaforge_content_mode` — every decision visible at once, rather than only the
  ones that differ from a default.

No table, no project, job or user structure, no store connected, no product, price or
review created. Idempotent by construction: every write is `add_option()`.

`REPLICAFORGE_VERSION` 1.1.0 → 1.2.0. `DB_SCHEMA_VERSION` 13.0.0 → 14.0.0.
`JOB_SCHEMA_VERSION` **stays 11.0** — the job table's shape is unchanged, and changing a
version that describes no change would force a pointless migration.

---

## 21. Testing

`tests/phase14-content-test.php` — 17 sections, **504 assertions**, with a shutdown
cleanup that removes the created user as well as the options, so an aborted run cannot
leave state that makes the next run pass for the wrong reason.

Notable assertions, and what they protect:

- `ownership_states()` is the *same array* as `Sync_Conflict_Detector::OWNERSHIP`.
- `IMAGE_ONLY_CEILING < ACCEPT_MATCH`, so an image-only match can never auto-apply.
- An image-only match is capped, flagged, and `review` or `ambiguous` — never `matched`.
- A pure SKU match scores 0.92 and matches; a title alone does not; title + category
  scores 0.87 and does.
- A value edited after planning is refused (`source_digest_mismatch`); a plan with no
  digest is refused (`missing_source_digest`); changing a *destination* is not.
- `currency` never reaches `text`; `text` never reaches `currency`.
- A read-only field is not written even when a hand-edited plan names it.
- `current_user_can( 'read_post' )` gates a read — and a published page is **not**
  readable by a signed-out caller.
- No provider has a method outside the contract; the contract has no `order`, `customer`,
  `payment` or `stock` method.
- Both WordPress providers use no direct SQL, with comments stripped before the scan
  (the WordPress provider's docblock *explains* why it does not use `$wpdb`, and the
  first scan failed it for that).

Two pre-existing suites were **strengthened, not loosened**:

- Phase 13's "the newest declared migration" asserted a *position*, and broke the moment
  Phase 14 declared its own — the exact mistake the Phase 12 notes warned about,
  repeated. Replaced with: still declared *by target version*, has a summary, and the
  whole chain is strictly increasing with nothing skipped. Phase 13 went 545 → 576.
- Phase 10's `count( OPERATIONS ) === 8` and `count( FEATURES ) === 10` were replaced with
  membership: the original operations are still declared, nothing is duplicated, and
  the counts are `>=` with the actual number reported. Phase 10 went 610 assertions.

---

## 22. Known limitations

Stated plainly, because a limitation discovered later is worse than one disclosed now.

1. **No real WooCommerce was exercised.** WooCommerce is not installed in this
   environment. The provider's field vocabulary, price parsing, product-type handling and
   matching logic are tested through an injectable API seam; a read against a real
   `WC_Product` is **not** tested, because no store exists here. Every WooCommerce code
   path was run against an in-memory store, and the report says so rather than implying
   otherwise.

2. **No live AI provider is exercised.** §12's AI-assisted mapping is not implemented;
   the deterministic mapper is, and it is the only path that can currently produce a
   plan. The cache key already carries `ai_model` and `prompt_version` so that adding it
   does not invalidate existing entries.

3. **No admin UI.** REST responses exist; nothing renders them. This is true of Phases
   10–13 as well.

4. **No job wiring.** §32's job types (`content_analysis`, `entity_detection`,
   `bulk_matching`, `mapping_validation`, `mapping_application`) are not enqueued.
   `Content_Entity_Matcher::match()` is paged and reports `partial`, so it is shaped for
   it, but nothing calls it from a runner.

5. **The destination model is read-only in practice.** `Content_Applier` writes
   `post_title`, `post_content`, `post_excerpt`, `post_date`, `post_status` and product
   meta. It does not write Elementor documents, and it does not create products,
   categories, posts or terms.

6. **No provenance for changes made outside ReplicaForge.** The §14 record is written by
   the applier. A user who edits a product's title directly in wp-admin leaves the
   record stale until Phase 9's conflict detection compares it — which is Phase 9's job
   and works from the *baseline*, not from this record.

7. **`Source_Content_Model` is per page.** §24's cross-page reuse is not built: a
   `product_card` component on five pages produces five independent mappings rather than
   one website-level mapping. The `product_card` / `blog_card` component types are
   already the right hook for it.

8. **Multisite is untested**, as it is for Phases 1–13. The option prefix is not
   network-scoped, so on multisite every site shares one cache and provenance store.

9. **No rollback of a *committed* change.** `rollback()` undoes an in-flight apply.
   Reverting a mapping that was applied successfully in the past is a different operation
   and is not implemented.

10. **A destination entity that disappears between planning and applying** is refused,
    which is correct, but the plan is then unusable rather than re-planned. The user has
    to re-run matching.

---

## 23. Non-goals, and how each is prevented

§48 lists them. For each, the mechanism — not the intention:

| Not done | How it is prevented |
|---|---|
| Payment processing | No provider method takes an order, customer or payment field. |
| Order management | Same. No `order` method exists on the contract. |
| Customer CRM | Same. No `customer` method. |
| Email marketing | No mail function is called anywhere in `includes/content/`. |
| Inventory management | `_stock` is `editable => false`; the mapper refuses it; the applier re-checks it against the provider rather than trusting the plan. |
| Arbitrary database editing | Four declared actions; no route accepts a field name or destination; no provider has a write method. |
| Mass product creation | No provider creates records; `Content_Applier` writes fields of existing records only. |
| Mass deletion | No `wp_delete_post`, no `delete_post`, no `trash_post` anywhere in the phase. |
| Source website login | No authentication of any kind is attempted; `Url_Validator` refuses credentials in a URL. |
| Private website crawling | Only public post types (`public` **and** `publicly_queryable`); `read_post` capability; password-protected posts excluded. |
| Source JavaScript execution | No JS engine, no `eval`, no `shell_exec`, no `proc_open` anywhere in the phase. |
| Source PHP execution | No `include` of remote content; structured data is `json_decode`d as data with bounded depth. |
| AI-generated code execution | AI output cannot become a plan without passing `Content_Validator`, and a plan cannot name a field — only a provider-declared field. |
| Automatic publishing | Nothing calls `wp_publish_post`; `post_status` is only ever read. |
| Automatic destructive replacement | §23's default is `review`; a conflict fails the stage. |
