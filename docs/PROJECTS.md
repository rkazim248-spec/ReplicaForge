# Projects

> **Phase 8 status: IMPLEMENTED AND TESTED.**
> `Project_Repository` is complete, wired into the plugin bootstrap, and covered by 135
> assertions in `tests/phase8-tokens-projects-test.php`. What is *not* built is the
> pipeline that populates a project automatically, and the admin screens that display
> one. Those are called out at the end.

## What a project is

A project is the record of one replica: its source, its generations, and its settings.

Before Phase 8, that record was scattered across job rows, transients, and post meta.
The history screen could *list* work, but nothing said which analyses and which
generated drafts belonged together. Regenerating a page meant starting over, because
nothing tied the second attempt to the first.

A project is a single option row, `replicaforge_projects`, holding a bounded list of
projects. It is not a custom database table, consistent with the Phase 7 decision to
stay on options, post meta, and transients: one row, one read.

## Shape

```php
array(
  'project_id'    => 'proj_a1b2c3d4e5',   // stable identifier
  'name'          => 'example.com — Pricing',
  'source_url'    => 'https://example.com/pricing',
  'source_host'   => 'example.com',      // separate, so a list can filter by site
  'source_key'    => 'https://example.com/pricing',  // identity for duplicate detection
  'created_at'    => '2026-09-26T10:00:00+00:00',
  'updated_at'    => '2026-09-26T10:12:00+00:00',
  'status'        => 'analyzed' | 'draft_created',
  'versions'      => array( /* see below */ ),
  'drafts'        => array( /* see below */ ),
  'analysis'      => 'analysis token',   // pointers to the latest version's work
  'design'        => 'design token',
  'specification' => 'specification token',
  'validation'    => 'validation token',
  'corrections'   => 'correction token',
  'settings'      => array( /* see below */ ),
  'warnings'      => array(),
  'user_id'       => 1,
)
```

A project holds **references**, not content. The bulky analysis and design data stays
where Phase 2 put it, and the project points at it. That keeps a project small enough
to hold 60 of them in one option.

### Versions

```php
array(
  'version'       => 2,                  // monotonic, never reused
  'version_id'    => 'ver_1a2b3c4d',
  'created_at'    => '...',
  'source_hash'   => 'def456',
  'analysis'      => 'analysis token',
  'design'        => 'design token',
  'specification' => 'specification token',
  'draft_id'      => 412,                // 0 when nothing was generated
  'generation_id' => 'gen_...',
  'validation_id' => 'val_...',
  'validation'    => 'validation token',
  'corrections'   => 'correction token',
  'change'        => 'initial' | 'color_change' | ...,
  'impact'        => 'low' | 'medium' | 'high' | 'unknown',
  'warnings'      => array(),
  'note'          => 'free text, bounded to 240 characters',
)
```

**Adding a version never changes an earlier one.** That is the whole point: a
comparison between two versions is only meaningful if the earlier one is still there.

Two details that turned out to matter and are covered by tests:

- **Version numbers are monotonic, not positional.** The next number comes from the
  highest version *retained*, not from how many are retained. Once the 20-version cap
  trims the list, the two stop agreeing — counting would hand the same number to two
  different versions and make a stored reference to "version 3" ambiguous.
- **Trimming does not renumber.** After 28 versions the retained list holds versions
  9 through 28, and version 9 is still called 9. The list has no gap in it.

### Drafts

A project references drafts; it never contains them.

```php
array(
  'draft_id'   => 412,
  'version'    => 2,
  'created_at' => '...',
  'ownership'  => 'replicaforge' | 'user',
  'exists'     => true,       // checked, not assumed
  'edit_url'   => '...',
)
```

`ownership` is decided by the presence of the `replicaforge_generation_hash` post meta
that Phase 4 writes. A page without it was not made here, whatever its title suggests.
This is the single fact that makes the deletion rules below safe.

Drafts are listed from *every* version, not just the retained ones, because a draft
reference is the one thing in a version a person would be annoyed to lose, and the
version list is bounded.

## Deleting a project does not delete the page

`delete( $project_id, $delete_drafts = false )`.

A project is ReplicaForge's bookkeeping. An Elementor page is the user's content. The
default removes the record and leaves the page alone, and reports how many drafts were
kept so the caller can say so.

Even when `$delete_drafts` is `true`, a draft is deleted only if **all three** hold:

1. It carries the generation hash, so ReplicaForge made it.
2. It is still `post_status => 'draft'`, so it was never published.
3. The deletion was explicitly requested, and the request named the draft.

All three are tested. A user's own page and a published replica both survive a
deletion that explicitly asked for drafts to be removed.

## Duplicate detection

`find_by_source( $url )` and `duplicates_of( $url )` exist so a repeat analysis of the
same page can offer **Open existing / Create new / Update analysis** rather than
silently overwriting work.

The key normalizes away what does not change the request:

| Change | Same project? | Why |
| --- | --- | --- |
| Trailing slash | Yes | The server sees the same path |
| Fragment | Yes | The fragment is not sent to the server |
| Host case | Yes | DNS is case-insensitive |
| Query parameter order | Yes | Two orderings are one request |
| A different path | No | A different page |
| A different host | No | A different site |
| A different scheme | No | http and https are genuinely two pages |
| A different query **value** | No | A filtered listing is a different page |
| A query at all, vs. no query | No | The base page is not the filtered one |

That last row is a deliberate choice. It would be convenient to treat a filtered
listing as the same project, but then re-analyzing a filtered URL would offer to
overwrite the unfiltered work, which is exactly the silent overwrite the design rules
out.

## Settings and preferences

A project's settings default to:

```php
array(
  'mode'         => 'balanced',   // visual | editable | balanced
  'priority'     => array( 'visual' => 5, 'content' => 5, 'responsive' => 5,
                           'editability' => 5, 'performance' => 3 ),
  'asset_policy' => 'reference',  // reference | import | skip
  'visual_evidence' => true,
  'ai_enabled'   => false,
  'auto_correct' => false,
)
```

- **`balanced`** is the default because it is the one that produces a draft a person
  can edit, which is the product. See §44 of the brief.
- **`reference`** is the default asset policy because it is the only mode that cannot
  make a legal claim on the user's behalf. See [`ASSET-POLICY.md`](ASSET-POLICY.md).
- **`auto_correct` is off**, because a Phase 6 correction still needs human review and
  the brief's §58 asks for a rejection to stick.

An unknown `mode` or `asset_policy` is **refused and the default kept**, rather than
being stored as a value no reader understands. Both are tested.

Separately, `set_preference()` stores project-level user preferences in
`replicaforge_preferences`: the choices a person makes once and expects to keep.
Nothing identifying is stored beyond the user identifier the project already carries.

## Bounds

| Bound | Value | Why |
| --- | --- | --- |
| Projects | 60 | One option row has to stay a reasonable size |
| Versions per project | 20 | Older versions are a record, not content |
| Name length | 120 characters | Derived from a URL, which can be arbitrarily long |
| Note length | 240 characters | A note, not a description |
| Warnings per version | 20 | Enough to act on |
| Warnings in a listing | 10 | Enough to see, not enough to overflow a row |

When a project is dropped by the 60-project cap, what is lost is the *history*. The
Elementor pages it referenced stay reachable through WordPress, which is why the drafts
are recorded separately and the projects are not treated as the container of the work.

## Robustness

Both options are treated as untrusted on read, because an option can be corrupted by a
failed migration, a partial import, or a restore:

- A `replicaforge_projects` option that is not an array reads as **no projects**, and
  a new project can still be created, so the corruption is recoverable rather than
  sticky.
- A `replicaforge_preferences` option that is not an array reads as the **real
  defaults**, not as an empty array that would leave every mode unset.

Both are tested.

## What is built versus what is wired

**Built and tested** — everything in this document. Creating, finding, listing,
filtering, updating, versioning, draft tracking, deletion, duplicate detection,
settings validation, preferences, and every bound above.

**Not built** — and this is the honest boundary:

- **Nothing populates a project automatically.** The REST routes for analysis and
  generation do not create a version. A project is created and versioned by calling
  `Project_Repository` directly, which today only the test suite does.
- **No REST routes** for projects. The 20 registered routes are unchanged from Phase 7.
- **No admin screen.** The dashboard and history screens do not read a project.
- **The settings are stored and nothing consumes them.** `mode`, `priority`,
  `asset_policy`, `visual_evidence`, `ai_enabled`, and `auto_correct` have no effect on
  generation, because there is no generation path that reads a project.

That last one is the important caveat. The project system is a complete, tested
record-keeping layer. The workflow that fills it — analyze, generate, validate, correct,
detect change, regenerate — is not connected to it yet. Connecting it is the first job
of the next phase, and the reason this document ends rather than continuing into a
user guide.

## Related

- [`SOURCE-CHANGE-DETECTION.md`](SOURCE-CHANGE-DETECTION.md) — the feature that needs
  this storage and the version semantics above.
- [`../JOBS.md`](../JOBS.md) — the job system that a pipeline populating a project
  would run inside.
- [`../DATABASE.md`](../DATABASE.md) — why this is an option rather than a table.
