# ReplicaForge Data, Versions, and Migrations

## 1. Storage decisions

ReplicaForge uses **no custom database tables**. Everything lives in `wp_options`,
`wp_postmeta`, and transients.

This is a deliberate choice, not an oversight. A ReplicaForge install is not
expected to hold thousands of jobs, the read pattern is always "the most recent N",
and one row per collection keeps the schema surface small. A custom table would add
a `dbDelta` dependency, a migration path, and a set of queries that have to be
right on the first release, for flexibility the plugin does not use.

The test suite asserts this. If a custom table is ever introduced, the "no query is
built by concatenating a value" scan and the storage report in
`tests/maintenance-contract-test.php` are the places to update.

## 2. Options

| Option | Contents | Bound | Read by |
|---|---|---|---|
| `replicaforge_version` | Installed plugin version | 1 value | System Status |
| `replicaforge_schema_version` | Installed data schema | 1 value | Migrator, cache keys |
| `replicaforge_migration_state` | Last failure, if any | 1 value | System Status |
| `replicaforge_jobs` | Job records, oldest first | 100 records | History, Dashboard, Runner |
| `replicaforge_idempotency` | Key → job id | 500 records, 15 min | Job queue |
| `replicaforge_log` | Structured log entries | 500 default, 5000 ceiling | Logs, Dashboard |
| `replicaforge_settings` | Retention settings | 5 range-checked values | Maintenance |
| `replicaforge_feature_flags` | Flag overrides | 5 declared flags | Runner, System Status |
| `replicaforge_ai_settings` | AI provider configuration including the key | — | AI manager only |

The log and the job list are written whole on each entry. That is a deliberate
trade: the collections are bounded, and a single row means one query rather than a
table scan, an index, and a set of queries that have to be right on the first
release.

## 3. Transients

| Prefix | Contents | TTL |
|---|---|---|
| `replicaforge_validation_result_*` | A validation record | Phase 5 limit |
| `replicaforge_correction_plan_*` | A correction plan | Phase 6 limit |
| `replicaforge_job_payload_*` | A bulky stage result | 1 day |
| `replicaforge_spec_*` | A stored specification | Phase 3 limit |

A transient is stored as **two** rows: `_transient_<name>` and
`_transient_timeout_<name>`. The System Status screen counts only the value rows,
because counting both would report every transient twice.

A plan or snapshot transient is deliberately **not** removed by the cleanup even
after its timeout passes. Its value row is what a pending rollback reads, and a
rollback in progress must never be broken by a cleanup. It expires on its own
schedule instead.

## 4. Post meta

| Key | Contents | Written by |
|---|---|---|
| `replicaforge_snapshots` | Snapshot summaries for a draft | Phase 6 |
| `replicaforge_generation_hash` | Hash of the document as generated | Phase 4 |
| `replicaforge_last_validation_hash` | Hash at the last validation | Phase 5 |
| `replicaforge_last_correction_hash` | Hash after the last correction | Phase 6 |

The three hashes are the manual-edit protection. If a hash no longer matches, the
document changed after ReplicaForge last wrote it, and a correction is flagged for
review instead of being applied.

The Elementor document itself is stored under Elementor's own keys. It is not
ReplicaForge data and is never removed by ReplicaForge.

## 5. Versions

The plugin version and the data schema version are **different things**. A patch
release that changes no stored shape needs no migration, and a data change that
ships with a minor bump still does. Keeping them apart is what makes "is stored
data readable?" a question with an answer.

| Constant | Value | Meaning |
|---|---|---|
| `REPLICAFORGE_VERSION` | `0.7.0` | The plugin release |
| `Schema::DB_SCHEMA_VERSION` | `1.0.0` | The shape of what ReplicaForge stores |
| `Schema::ANALYSIS_SCHEMA_VERSION` | `2.0` | Phase 1 representation |
| `Schema::RECONSTRUCTION_SCHEMA_VERSION` | `3.0` | Phase 3 specification |
| `Schema::ELEMENTOR_SCHEMA_VERSION` | `4.0` | Phase 4 document format |
| `Schema::VALIDATION_SCHEMA_VERSION` | `5.0` | Phase 5 record |
| `Schema::CORRECTION_SCHEMA_VERSION` | `6.0` | Phase 6 plan |
| `Schema::PROMPT_VERSION` | `3` | The AI prompt |

Bump `DB_SCHEMA_VERSION` **only** when the shape of something stored changes.

`Schema::cache_token( $phase )` folds the relevant version into a cache key, so a
schema change invalidates caches instead of serving a stale result. The
reconstruction token includes the prompt version, because a cached AI response
built from an older prompt must not be reused.

The test asserts the plugin version and the data schema version are separate
values, which is the property that keeps a patch release from implying a migration.

## 6. Migrations

A migration is a declared entry with a target version, a callable, and a summary.
Adding a migration is appending an entry; nothing else changes.

```php
array(
    'from'    => '1.0.0',
    'to'      => '1.1.0',
    'run'     => array( $this, 'migrate_something' ),
    'summary' => 'One line describing what changed, which is what the log shows.',
),
```

The runner:

1. Compares the recorded version against each target.
2. Runs only the migrations the install has not had.
3. Records the new version **after** a successful run, so a failure does not mark
   itself applied.
4. Catches a throw, records the failed version and time, and returns a failure. A
   failed migration never stops the site from loading.
5. Marks the current version even when nothing ran, so a fresh install with no
   recorded version does not re-run the first migration on every request.

It runs on `init` at priority 5, gated by a single option read, so the common case
costs almost nothing. It also runs on activation, and can be forced from
**System Status**.

### The first migration

`1.0.0` records the schema version and clears data that is by definition unreadable:
expired transients and finished jobs. Both are safe to remove — a transient past
its own expiry cannot be read, and a finished job is a record, not content.

### The migration chain

```
('')     -> 1.0.0   initial
1.0.0    -> 10.0.0  commercial
10.0.0   -> 11.0.0  reliability
11.0.0   -> 12.0.0  multi-page
12.0.0   -> 13.0.0  visual intelligence
13.0.0   -> 14.0.0  content intelligence
14.0.0   -> 15.0.0  collaboration tables
15.0.0   -> 19.0.0  template library
```

**Why `15.0.0 -> 19.0.0` and not three steps.** Phases 16, 17 and 18 shipped without a schema
change, so there is no 16, 17 or 18 schema to step through. The runner fires any migration whose
target exceeds the recorded version, so a site at `15.0.0` and a fresh install both arrive at
`19.0.0` by one step. Adding empty migrations for phases that changed nothing would put three
no-op entries in the chain forever.

`migrate_templates()` re-runs `Collaboration_Schema::install()`, which is `dbDelta` over every
definition -- so it *adds* `replicaforge_templates`, `replicaforge_template_versions` and
`replicaforge_template_components` and leaves the thirteen Phase 15 tables untouched.

It creates **no templates** and extracts **nothing**. A migration that invented templates would
put content in a user's library they did not ask for. The migration report in
`replicaforge_template_migration` says so explicitly. A failed install **throws**, so the
version does not advance and the next request retries.

See [TEMPLATES.md](TEMPLATES.md) sections 17 and 18 for the table definitions and the
capability changes.

### Adding one

1. Bump `Schema::DB_SCHEMA_VERSION`.
2. Append an entry to `Migrator::migrations()` with `from`, `to`, `run`, and
   `summary`.
3. Make `run()` safe to call twice. It will be, if a migration is idempotent.
4. Carry old data forward. If a shape cannot be migrated, leave the record readable
   as it was and report it — an upgrade should degrade to "some history is not
   shown", never to "history is gone".

## 7. Cleanup

The daily task removes:

| What | Rule | Never removed |
|---|---|---|
| Expired transients | Past their own timeout, and not a plan or snapshot | A plan or snapshot backing a pending rollback |
| Orphaned value rows | No timeout row — nothing can read them | A live transient |
| Finished jobs | Older than `job_days` (default 14) | Any unfinished job |
| Log entries | Older than `log_days` (default 30) | — |
| Idempotency records | Past their 15-minute TTL | A key for a job that is still running |
| Stage payloads | Expire on their own after 1 day | — |
| Snapshot summaries | Not restorable, or past retention | A snapshot whose transient still exists |

A snapshot summary is kept while its transient is still restorable, so the screen
never promises a rollback that is about to become unavailable. Removing the summary
before the value would leave a record that promises something untrue.

Every retention value is range-checked on write: 1–365 days, and a log limit capped
at 5000 entries. A setting cannot raise retention without bound.

## 8. Uninstall

`uninstall.php` removes only ReplicaForge's own data:

- The options listed in section 2, except the AI settings' API key, which is
  removed with the rest of the option.
- Every `replicaforge_*` transient and its timeout row.
- Every `replicaforge_*` post meta key.

It never calls `wp_delete_post`, `wp_delete_attachment`, or touches
`_elementor_data` or `_elementor_edit_mode`. The security test asserts the absence
of all four by reading the source, because a test that could be satisfied by a
change elsewhere would not be worth having.

**Deactivation does not delete anything.** Deactivating is usually temporary, and
destroying a user's history because they paused a plugin loses their work for no
reason. Deactivation only unschedules the background work. The uninstall routine is
the documented way to remove data.

## 9. Backward compatibility

A record written by an earlier ReplicaForge stays readable where that is
technically possible. Where a shape has changed, the migration carries the value
forward or leaves the record readable as it was and reports it.

Phase 6 accepts a snapshot written by an earlier build, marked `legacy` when the
transient behind it is gone, and the cleanup removes it rather than leaving a
summary that points at nothing.

An older `DB_SCHEMA_VERSION` is detected on every load and migrated. An install
never simply fails because its data is from an earlier version.

---

## Phase 9: one post meta key, no schema change

`Schema::DB_SCHEMA_VERSION` remains **`1.0.0`**. The plugin version remains **`0.7.0`**.
No migration was added, because no stored shape that an existing install reads changed.

| Key | Scope | Contents | Bound |
|---|---|---|---|
| `replicaforge_sync_map` | One draft | Source-to-Elementor mapping | 2000 entries |

### Why the map is per draft, not per project

A mapping describes a **document**. Two generations of the same source page have
different Elementor element identifiers, because the elements are built afresh. A
per-project map would have to be versioned alongside the drafts it describes, and the
per-draft key gets that for free by being replaced when the draft is.

### Why it is post meta and not a table

Consistent with the Phase 7 decision to stay on options, post meta, and transients. The
map is read and written with the draft it describes, is always fetched with the draft,
and is deleted with it. A table would buy querying that nothing performs and would add
a migration to a schema that is otherwise unchanged.

### Integrity rules enforced at the boundary

- An `elementor_element_id` must be exactly seven lowercase hexadecimal characters.
  A mapping to an id that could never exist fails at apply time, so it is refused at
  write time.
- A `source_component_id` may be readable (`hero_heading`), which is why the two
  fields have different alphabets.
- One source component may not claim two elements, and two sources may not claim one
  element. Both would make every lookup ambiguous, and the ambiguity would resolve
  silently to whichever was read first.
- `set()` replaces rather than merges. A mapping describes a current relationship, and
  merging would leave a field from a previous relationship describing one that no
  longer holds.
- A corrupted value reads as an empty map rather than producing an error, so a bad
  write is recoverable rather than sticky.

### Declared but unwritten

`Sync_Limits` declares the bounds a source version store and a sync report store would
use § `MAX_SOURCE_VERSIONS` 10, `MAX_SYNC_PLANS` 20, `MAX_SYNC_REPORTS` 50,
`MAX_GENERATIONS` 10. **None of them is enforced, because nothing writes those records.**

---

## Phase 10: commercial storage

Phase 10 adds **no database table.** The plugin has never had one and did not need
one; usage accounting looked like the case that would change that and did not. What
the meter needs is four integers per user per month plus a short bounded tail,
which is options and user meta.

| Option | Holds | Shape |
|---|---|---|
| `replicaforge_plan_definitions` | Plan overrides — only the difference from the shipped set. | `array<string, array{name, description, limits, features}>` |
| `replicaforge_trial_settings` | Trial configuration. | `array{enabled, days, plan, allow_reentry}` |
| `replicaforge_site_plan` | The plan this site runs. | `string` (a plan id) |
| `replicaforge_license_local` | The local licensing record. | `array{state, expires_at, reference, updated_at, updated_by, verification}` |
| `replicaforge_audit_log` | A bounded ring of commercial events. | `array<int, array{event, timestamp, user_id, ip_hash, context}>` |
| `replicaforge_onboarding` | Site-level onboarding state. | `array{version, completed_at, completed_by, skipped_at}` |

| User meta | Holds | Shape |
|---|---|---|
| `replicaforge_usage_{YYYY-MM}` | Committed counts. | `array<string, int>` |
| `replicaforge_usage_reserved_{YYYY-MM}` | Open reservations. | `array<string, array{operation, quantity, project_id, plan_id, created_at, expires_at, metadata}>` |
| `replicaforge_usage_recent_{YYYY-MM}` | The recent tail, capped at 500. | `array<int, array{usage_id, user_id, project_id, operation, quantity, plan_id, timestamp, metadata, period_key}>` |
| `replicaforge_onboarding` | Per-user acknowledgement and tour dismissal. | `array{seen, seen_at, dismissed}` |
| `replicaforge_trial_{started_at,expires_at,plan}` | A user's trial. | `int`, `int`, `string` |

### Three decisions worth recording

**Period keys are UTC calendar months.** A user's quota resets at a moment they can
predict, and two sites in different timezones reset at the same instant. A rolling
thirty-day window would be easier to implement and impossible to explain on a
screen.

**The audit ring is bounded at 500 entries.** A hostile client that triggers a
denial on every request must not be able to grow an option without limit. The
consequence — that a denial burst can push an earlier event out — is a real
limitation, recorded in `PHASE-10-COMPLETION-REPORT.md` §14.

**An expired period leaves an orphaned key.** That is the cost of not having a
table, and it is small: the key is one small array that nothing reads again. The
existing retention pass in `Maintenance` prunes ReplicaForge data by prefix and
covers it.

### The `10.0.0` migration

`Migrator::migrate_commercial()` is idempotent, destroys nothing, and has no call
into any Phase 1–9 service — it cannot read a project, an analysis, a draft, or a
validation. See `ARCHITECTURE.md` for what it writes.

`Schema::PLAN_SCHEMA_VERSION` (`9.0`) is declared separately from
`DB_SCHEMA_VERSION` so an exported plan document carries the shape it was written
in. An import carrying a newer plan schema is refused rather than half-read,
because a plan document with a key this version does not understand is a document
whose limits might be incomplete — and an incomplete limit reads as **unlimited**.

### Retention and deletion

`Usage_Manager::forget( $user_id )` removes every usage record for a user. It is
used by the test suite and exposed for the uninstall path.

**Known gap:** a full uninstall does not sweep the Phase 10 user-meta keys. The
existing `uninstall.php` covers options and post meta by prefix; the user-meta
keys are not covered. The residue is counts and acknowledgement flags, not
content, and it is recorded in `PHASE-10-COMPLETION-REPORT.md` §14.

---

## Phase 12: the multi-page store

Phase 12 adds **four options and no database table.** The plugin has never had a table
and Phase 12 does not start one: a website is a bounded set of pages (at most
`Site_Limits::MAX_PAGES`, 100) and one option per project keeps that visible and
inspectable.

### The options

| Option | Contents | Bound |
|---|---|---|
| `replicaforge_websites` | Projects, page records, and the website specification | 30 projects § 100 pages |
| `replicaforge_site_registry` | Shared components and templates, with override state | 40 projects, 60 components, 20 templates |
| `replicaforge_site_snapshots` | Rollback snapshot history | 10 per project |
| `replicaforge_global_style_ownership` | What ReplicaForge has actually written to global styles | 1 per project |

The Phase 12 migration creates all four with their defaults so the settings are
*visible* rather than implied by a `get_option( $name, array() )` at read time. It is
idempotent by construction § the content is a constant, so a re-run writes the same
bytes.

### The cost, stated

This is not a query language. Finding every page of type `product` scans the project.
At a hundred pages that is a few thousand array reads, which is nothing; and if a
project ever needed thousands of pages, the plan limits would have stopped it long
before. The trade is real and is recorded here rather than discovered later.

### Page ids are derived, never stored as an input

`page_id` is computed from the canonical source URL by
`Website_Repository::page_id_for()`. Storing both `page_id` and `source_url` as
independent inputs invites them to disagree, and a page whose id disagrees with its own
address is ambiguous everywhere it is referenced. So the id is a function of the URL,
and `Multi_Page_Api` recomputes it and refuses a submitted id that disagrees.

A trailing slash does not change the id, the host is part of it, and a query string is
part of it § because a query can select the page.

### Page status is monotone

`pending ? analyzed ? selected ? generating ? generated ? validated`

A re-analysis cannot move a generated page back to `analyzed`, or a user re-running
discovery would silently un-generate work they had done. `failed` and `needs_review`
are deliberate states and are only left by another explicit change.

### Snapshots reference; they do not copy

A snapshot records post ids, titles, statuses, content hashes, and the design hash. It
does **not** copy page or Elementor content, because WordPress already keeps revisions
and a rollback trashes posts § from which they are restorable. Copying every document
into a second store would double a project's size to guard against a mistake a post
deletion already handles.

### Global style ownership

`replicaforge_global_style_ownership` records what ReplicaForge has written. It is
created as an **empty array**, which means `unknown` § and `unknown` means ReplicaForge
writes nothing. It is not a missing value that a future version might interpret
optimistically; it is a recorded decision.
## Phase 13: visual storage

**No new database table.** Five bounded option families, all fixed-size or pruned on read
as well as on write.

| Option | Contents | Bound |
| --- | --- | --- |
| `replicaforge_visual_ai` | The five user switches plus the image limits. Every switch visible rather than only the one that differs from a default. | 1 row |
| `replicaforge_visual_cache_index` | key -> kind, bytes, stored_at, ttl, project_id, retention | 120 entries, oldest evicted |
| `replicaforge_visual_entry_{key}` | The body: a PNG, or an analysis document. | One per index entry; removed with it |
| `replicaforge_visual_repr_{hash}` | page_id::viewport -> the 13.0 representation | 400 per project |
| `replicaforge_visual_reports_{hash}` | Comparison reports for the dashboard | 40 per project |

### Three properties that matter more than the bounds

- **A dangling index entry is removed on read.** An index that outlives its body would
  report a cache hit that returns nothing, which is worse than a miss.
- **Expiry is enforced on read, not only by the maintenance sweep.** A cache nobody reads
  still does not grow without bound.
- **`project_id` is stored in the index, not derived from the key.** The key is a hash of
  project, source, viewport, and versions; deriving ownership from it would mean trusting
  that a caller cannot construct one.

### The migration

`12.0.0 -> 13.0.0` creates the settings option (all switches off), creates the cache index
as an **empty array rather than an absent option** (so a user can see the cache exists and
is empty rather than wondering whether rendering is silently failing), and records the five
new difference categories as a reported fact. Idempotent by construction: every write is
`add_option()`, which is a no-op when the option exists.

`Schema::DB_SCHEMA_VERSION` is `13.0.0`. `Schema::JOB_SCHEMA_VERSION` stays `11.0` -- the
job table's shape did not change, because render jobs reuse the existing columns.

### The difference vocabulary was extended, not duplicated

Five categories were **added to `Validation_Limits::CATEGORIES` in its own file**:
`position`, `size`, `radius`, `layering`, `alignment`. A second list in a Phase 13 class
would have meant two sources of truth for one vocabulary, and drift is the specific way an
"extended" vocabulary fails to be used. Two metric groups were extended to match, because a
category with no metric group is classified, stored, and then silently excluded from every
score.

---

## Phase 14 â€” Content and Data Intelligence

### No new tables

Phase 14 creates **no** table. Â§34 forbids duplicating project, job or user structures,
and nothing in the phase needs one: content models are derived from representations that
Phases 1â€“3 already store, and destinations are read live through WordPress APIs.

### Options added by `13.0.0 â†’ 14.0.0`

| Option | Purpose |
|---|---|
| `replicaforge_content_cache` | Analysis cache index. Created as an empty array rather than left absent, so its existence is visible. |
| `replicaforge_content_mode` | The reconstruction mode plus every related decision, so the default is visible rather than implicit. |

Both are created with `add_option()`, so the migration is idempotent.

### Options written at runtime

| Option | Purpose |
|---|---|
| `rfc_entry_{key}` | One cached content analysis. |
| `replicaforge_content_provenance_{hash}` | Â§14 provenance for one project â€” the evidence that a user's price was not overwritten. |
| `replicaforge_content_snapshot_{hash}` | Field-value before-images for rollback. |
| `replicaforge_content_plan_{hash}` | Up to 20 stored mapping plans per project. |

### What is stored, and what is not

**Provenance outlives the cache.** It is the evidence Phase 9 needs to know a field is
`user_controlled` and must never be overwritten, so a re-analysis must not erase it. It
carries a TTL (30 days) but is retained across analysis-cache expiry.

**Snapshots hold field values, not documents.** A content snapshot is a before-image of
two or three scalars for one destination record. Document snapshots remain Phase 6's
job, and reimplementing them here would be a second copy of a system that already stores
documents properly.

**The cache key is defensive by construction.** It folds in the source content hash, the
destination schema hash, the **mapping engine version**, the schema version, the mode,
the view, and each provider's version. Omitting the engine version would let a cache
entry survive an engine upgrade that changed the rules â€” and for a mapping that means a
source price written to the wrong product.

**No customer data is stored.** No provider method returns an order, a customer, a
billing or shipping address, or a payment record, so there is no path by which one can be
persisted.

### Multisite

The option prefixes are not network-scoped, so on multisite every site shares one cache
and provenance store. This is the same position as Phases 1â€“13 and is stated rather than
fixed.