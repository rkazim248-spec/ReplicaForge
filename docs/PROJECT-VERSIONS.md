# Project Versions

> **Phase 9 status: NOT IMPLEMENTED.**
> No generation versioning, no comparison between generations, no restore, no
> archive. The Phase 8 project system stores *analysis* versions and references drafts;
> this file is about versioning the *generated replicas*, which is a different thing and
> is not built.
>
> The distinction is stated precisely at the end, because getting it wrong is how a
> "restore" ends up restoring the wrong thing.

## What "version" means here, and the two that are easy to confuse

| | Exists | Holds |
|---|---|---|
| **Source version** | No | One analysis of the source at a moment in time, with its hash |
| **Project version** | **Yes**, Phase 8 | One analysis plus, if generated, the draft it produced — a reference to a draft, never the draft itself |
| **Generation version** | No | One built Elementor document, with its own history |

The Phase 8 `Project_Repository::add_version()` is a *project* version. It records which
analysis, which specification, and which draft reference belong together. It is not a
generation history and cannot be used as one: it stores a `draft_id`, not a document.

## Why generations need their own history

Three reasons, and the first is the one that matters:

1. **A draft is mutable and a version is not.** A person opens a generated draft in the
   Elementor editor and changes it. The document is now theirs. Re-generating must not
   overwrite that, so a new generation is a *new draft*, and the old one stays.
2. **A generation carries decisions a version list cannot.** Which mode produced it,
   which changes were applied, which conflicts were resolved in which direction. Those
   are the things a person asks about when they ask "why does this look like that".
3. **Rollback needs a target.** §35 requires every synchronisation to be reversible. That
   needs a document state to return to, and a draft id is not one.

## What a generation would hold

```php
array(
  'generation'      => 3,              // monotonic, never reused
  'generation_id'   => 'gen_a1b2c3d4',
  'project_id'      => 'proj_...',
  'draft_id'        => 412,
  'created_at'      => '...',
  'created_by'      => 'sync' | 'manual' | 'full_regeneration',
  'document_hash'   => 'sha256:...',   // the Phase 4 generation hash
  'design_hash'     => '...',          // the representation it was built from
  'source_version'  => 'src_004',      // what it was built from, when known
  'mode'            => 'balanced',
  'priority'        => array( ... ),
  'changes_applied' => array( 'sync_change_001', ... ),
  'changes_skipped' => array( ... ),
  'conflicts'       => array( ... ),
  'validation'      => array( 'passed' => true, 'validation_id' => '...' ),
  'snapshot_id'     => 'snap_...',     // what to restore to
)
```

`document_hash` is the key field and it is **not new**: Phase 4 already writes
`replicaforge_generation_hash` and Phase 6 keeps it current. A generation record is
mostly bookkeeping around a hash that already exists.

## The rule that governs all of it

**A generation is never overwritten, and a generation is never silently deleted.**

The same rule the plugin has held since Phase 1 about manual edits, applied to a
generation. Regenerating creates generation *n+1*; it does not rewrite generation *n*.

This is why `Project_Repository` was built to append rather than replace, and why
version numbers there come from the highest version retained rather than from how many
are retained — a defect the test found, where two versions were both numbered 21 once
the cap trimmed the list. The same bug in a generation list would make a stored
reference to "generation 3" ambiguous.

## Compare, restore, open, duplicate, archive

§34 asks for five operations.

| Operation | Feasible today? | Note |
|---|---|---|
| **Compare** | Partly | Two generation records can be diffed on `document_hash`, `design_hash`, and the applied-change list. A *visual* comparison needs a render provider, which is untested |
| **Restore** | No | Needs a stored document body. `Correction_Snapshot` stores one per draft, bounded at `MAX_SNAPSHOTS`, but a *generation* history is not the same as a per-draft rollback history |
| **Open** | **Yes** | `Elementor_Map` and `Project_Repository` both hold a `draft_id` and an edit URL |
| **Duplicate** | No | Straightforward once generations exist: create a new draft from an old document |
| **Archive** | No | A status field, plus the rule that an archived generation is not a deletion target |

## Storage: post meta, not a table

Consistent with Phase 7's decision, a project's generations belong in the project record
in the `replicaforge_projects` option, or as a bounded per-project list. The bounds
would be `Sync_Limits::MAX_GENERATIONS` (10) and `Sync_Limits::MAX_SYNC_REPORTS` (50),
both of which are already declared.

A generation does **not** store a document body. It stores a draft reference and a
hash. Storing bodies would make the option row enormous and would duplicate
`_elementor_data`, which is the document's home.

## The stable-identity problem, restated honestly

`SOURCE-CHANGE-DETECTION.md` describes how component identity survives an edit. For
*incremental regeneration* — updating one section rather than the page — that identity
has to be stable enough to address a section across analyses.

`Component_Matcher` gets a component's identity right. It does **not** yet give a
section a stable identity across a structural change, because a section's identity is
currently its `id`, and inserting a section above renumbers the ones below it.

So the plan half's decision — "update this section" — cannot be trusted yet, and a first
implementation should compare *document hashes* and fall back to a full regeneration
rather than guess which section moved. Guessing would be the exact silent overwrite the
plugin exists to avoid.

## What is not built

- Generation records of any kind.
- Comparison between generations, visual or structural.
- Restore, duplicate, archive.
- A project screen showing any of it — the Phase 8 admin has no project page at all.
- Project export and import (§53, §54), which would include generation history.

## Related

- [`PROJECTS.md`](PROJECTS.md) — the project system that **is** built, and the
  distinction between its versions and a generation.
- [`SOURCE-CHANGE-DETECTION.md`](SOURCE-CHANGE-DETECTION.md) — where a change would be
  detected.
- [`../DATABASE.md`](../DATABASE.md) — why this is options and not a table.
