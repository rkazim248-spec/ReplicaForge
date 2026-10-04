# Sync Security

> **Phase 9 status: the boundaries are implemented. The endpoints are not.**
> `Sync_Conflict_Detector` and `Elementor_Map` enforce the ownership and
> identification rules below, and both are tested. The REST routes, the cron event,
> the import path, and the export path are **not built**, so none of this is reachable
> from outside the plugin.

## Phase 9's new surface area, and what it does not add

Phase 9's distinctive risk is not that it writes to Elementor. It does not, and it is
not permitted to: there is no write path in Phase 9, and every write that a future
apply step performs must go through the Phase 6 boundary that already exists.

| Surface | Risk | Status |
|---|---|---|
| A sync plan naming properties | A caller could ask for an arbitrary write | **Contained.** No plan is built, and `Sync_Conflict_Detector` refuses any property not on `Correction_Property_Map` before comparing anything |
| Elementor element ids from a stored map | A plan could address an element that is not there | **Contained.** `Elementor_Map::clean_element_id()` enforces the seven-lowercase-hex format, and `stale()` reports mappings whose element is not in the document |
| Source version data | A hostile page could smuggle instructions into a sync plan | **Contained.** Nothing in the detection path executes, evaluates, or renders source text, and no source value reaches a write without passing the whitelist |
| Cron | A scheduled task taking user-controlled parameters | **Not built.** When built, the requirement is that a cron task resolves its work from a stored trusted record and accepts no parameters (§60) |
| Project ownership | One user reading another's project | **Not built.** The repository that will need it exists from Phase 8; the REST layer that would need to enforce it does not exist |
| Import | Imported project JSON | **Not built.** §54's requirement stands: validate schema, ids, references, URLs, and permissions, and never execute imported data |

## The single most important security decision in Phase 9

**Sync has no write path of its own, and must never acquire one.**

A future apply step must convert a validated sync plan into a
`Correction_Applier::apply()` call. That is not a style preference. The Phase 6 applier
is the audited boundary: it takes a plan and a set of selected ids, routes every
property through `Correction_Property_Map`, snapshots before writing, re-validates
after, and rolls back on regression. It has been tested against a plan that tried to
write a property it should not.

A second write path would be a second, unaudited way to reach a document. If a future
phase adds one, that is the defect to look for first — and `Correction_Property_Map`
should never be bypassed, extended by reference, or given a "sync" allowance.

## The whitelist is checked before anything is compared

```php
if ( '' === $property || ! $this->properties->is_writable( $property ) ) {
    $out['reason'] = 'The property is not on the write whitelist, so no value of it can ever be written.';
    return $out;
}
```

Checking first rather than last matters: a property that cannot be written has no
conflict state to resolve, and a caller passing one learns why immediately instead of
receiving a comparison of values that will never be used.

A property name shaped like a traversal (`../../evil`) is rejected by the property
cleaner before the whitelist is consulted, and an element id likewise.

## Elementor element ids are format-checked

An Elementor element id is exactly **seven lowercase hexadecimal characters** — the
Phase 4 document validator enforces that, and it is why a readable id like `hdg001` is
rejected by the document validator.

`Elementor_Map` therefore validates the `elementor_element_id` specifically, and more
strictly than the source component id:

| Field | Rule | Why the difference |
|---|---|---|
| `source_component_id` | `[a-z0-9_.-]`, ≤ 120 chars, no `..` | Ours. A readable name like `hero_heading` is correct and useful |
| `elementor_element_id` | Exactly `[a-f0-9]{7}` | Elementor's. A mapping to an id that could never exist is a mapping that fails at apply time |

Both call sites and the staleness check use the strict reader. It was added as dead
code first — the method existed and nothing called it — which is why the wiring is now
asserted rather than assumed.

**Upper case is normalized, not refused.** `A1B2C3D` becomes `a1b2c3d`. Refusing it
would help nobody, and a lookup that did not normalize would miss on a caller mistake
for no benefit.

## Stale mappings are detectable, and that matters

A mapping pointing at an element that is not in the document is **worse than no
mapping**: a plan built on it would address an element that is not there, and the write
would either fail at apply time or, worse, land on a reused id.

`stale( $live_element_ids )` reports every mapping whose element is absent, and
`is_complete_against()` answers whether the map fully describes the current document.
`coverage()` is what decides between an incremental patch and a full regeneration:
below full coverage the plan is proposing writes it cannot address.

## Ambiguity is prevented at write time

One source component may not claim two elements, and two source components may not
claim one element. Both are prevented in `Elementor_Map::set()` and `set_many()`,
because two live mappings would make every lookup ambiguous and the ambiguity would
resolve silently to whichever was read first.

`set()` **replaces** an entry rather than merging into it. A mapping describes the
current relationship between two things, and merging would leave a field from a previous
relationship describing a relationship that no longer holds.

## The document reader's own guards apply

`Sync_Conflict_Detector` takes an `Elementor_Document_Reader`, and that reader refuses a
document unless **all** of the following hold. Each was found by a test, not by reading
the code:

1. The post exists.
2. It carries `replicaforge_generation_id` — a ReplicaForge-generated draft.
3. The current user can `edit_post` **and** `edit_pages`.
4. `_elementor_edit_mode` is `builder`.
5. It is still a draft.
6. `_elementor_data` is present, parseable, and passes the Phase 4 document validator.

This is why the conflict detector can never address a page ReplicaForge did not
generate, and it is the same guard the REST layer applies. The capability check is not
bypassed for the synchronisation path.

## Object id validation

Every identifier Phase 9 handles is validated, type-checked, and existence-checked:

| Identifier | Validation |
|---|---|
| Element id | `[a-f0-9]{7}`; existence checked against the loaded document |
| Property | `[a-z0-9-]`, ≤ 60 chars, then the whitelist |
| Source component id | `[a-z0-9_.-]`, ≤ 120 chars, `..` refused |
| Device | Normalized through `Correction_Property_Map::device()` |
| Monitor id (planned) | Must resolve from a stored record, never from a request |

No path traversal, no SQL fragment, no serialized object, and no arbitrary filesystem
path reaches storage. Identifiers are stored keys and end up in meta keys elsewhere in
the plugin, so the accepted alphabet is narrow and enforced at the storage boundary
rather than at each use.

## Source content is never trusted, executed, or rendered

A change detector reads a normalized representation. It does not execute, evaluate, or
render anything from the source page, and no source value reaches a write without first
passing the whitelist.

The subtle risk is prompt injection: a hostile page could put text into a field that
later reaches an AI provider. Phase 9 adds no AI call, so this is not a new surface
here — but a future phase that summarises a change report through AI must send it as
data with the same boundary `Ai_Manager` already uses, and the report must not be able
to instruct anything.

## Notification and export are not built

Both are untrusted-input surfaces and both are listed here so the requirements are
decided rather than discovered:

- **Export** (§53) must exclude API keys, passwords, cookies, and authentication headers.
  The `Project_Repository` export surface contains none of those today, and a change
  detector's report contains only observed values — but an exported `source_url` is a
  user-controlled string and belongs in the export only after being sanitized.
- **Import** (§54) must validate schema, ids, references, URLs, and permissions, and
  must never execute imported data. The `Elementor_Map` id cleaners are the right model:
  a narrow alphabet enforced at the boundary.

## Cron security

Not built. The requirement when it is: a scheduled task resolves its work from a stored
trusted record and accepts no user-controlled parameters (§60). The lock transient is
keyed by a monitor id from storage, never from a request.

## The three rules that must survive

1. **Never write to Elementor except through `Correction_Property_Map`.**
2. **Never trust an identifier from a browser — validate, type-check, existence-check,
   ownership-check.**
3. **Never let source content become an instruction.** It is data, always.

## Related

- [`../SECURITY.md`](../SECURITY.md) — the full threat model.
- [`CONFLICTS.md`](CONFLICTS.md) — the ownership model.
- [`../CORRECTIONS.md`](../CORRECTIONS.md) — the boundary being reused.
