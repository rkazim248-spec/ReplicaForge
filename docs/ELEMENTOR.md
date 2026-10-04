# ReplicaForge and Elementor

## 1. Principle

ReplicaForge does not hard-code assumptions about one Elementor version. Everything
version-specific goes through a compatibility layer that detects what is installed
and adapts, and **disables a capability it cannot perform** rather than producing
something wrong.

## 2. What is detected

`Elementor_Generator::status()` reports:

| Field | Meaning |
|---|---|
| `available` | Elementor is installed and active |
| `reason` | `elementor_missing` or `elementor_inactive` when unavailable |
| `version` | Detected Elementor version |
| `containers` | Whether the container element is supported |

`Elementor_Limits` records the minimum supported version and the phase of the
integration.

Container support is detected rather than assumed. On an Elementor version without
containers, ReplicaForge falls back to sections and columns and says so in the
generation report, because a report that claimed a structure it did not build would
be worse than one that says what it did.

## 3. Widget availability

`Elementor_Widget_Registry` knows which widget types the installed version
provides. A widget that is not available is not emitted.

The check lives in the single choke point `widget_node()`, not at the call sites.
An earlier version had a call site that bypassed it, which meant a widget the
installed Elementor did not provide could reach the document. Putting the check at
the choke point means there is no second path around it.

## 4. Document shape

The generated document is stored in `_elementor_data` post meta, an array of
element nodes:

```
[ container
    [ heading      settings: { title, header_size }
    [ text-editor  settings: { editor }
    [ image        settings: { image: { id, url } }
    [ button       settings: { text, link: { url } }
]
```

Every value is produced by `Elementor_Values`, which is the single place a value
enters a document. It coerces, bounds, and refuses.

## 5. Responsive storage

This is the detail most likely to be got wrong, and it was.

Elementor stores a desktop value in the element's `settings` map and a **device
override** in a separate `_elementor_responsive` post meta keyed by element id:

```
_elementor_data:            { "abc123": { elType: "heading", settings: { title: "Hi" } } }
_elementor_responsive:      { "abc123": { tablet: { title: "Hello" } } }
```

Two things do not work:

- Nesting a device block under the control name inside `settings` collides with
  the desktop value.
- A flat key inside `settings` is silently ignored by the editor.

`Correction_Property_Map::read_control()` and `write_control()` are the only code
that decides which map a value belongs in. A caller never chooses.

`_elementor_responsive` is treated as part of the draft's state. It is captured in
every snapshot and restored on rollback, **including deleting it when the snapshot
had none**. A rollback that restored the document but left a stale responsive map
would leave the draft in a state the user never saved.

## 6. Draft safety

| Rule | Enforcement |
|---|---|
| Only a draft is written | `Correction_Validator` refuses a published page |
| A page ReplicaForge did not generate is never corrected | `draft_not_correctionable` |
| A document is saved once | One `wp_update_post()` per apply, not one per correction |
| The saved document is read back and hashed | `generation_hash` after every save |
| Nothing is published | No code path calls `wp_publish_post()` |
| A failed write leaves the document unchanged | Validation before write, and a restore on failure |

A generated page is a **draft**. Publishing is a manual decision in WordPress or
Elementor. There is no setting that changes this.

## 7. Compatibility with the correction engine

The correction engine writes through `Elementor_Document_Writer`, which resolves an
element by id, applies a whitelisted property through
`Correction_Property_Map`, and saves. It does not use Elementor's own API, because
Elementor's editor-side API is not available outside the editor and relying on it
would make corrections impossible from the admin screen.

The consequence is that ReplicaForge must match Elementor's storage shape exactly.
The read-back-and-hash step after every write is what catches a mismatch: a document
that does not read back as written is a failed save, not a successful one.

## 8. Version support

| Component | Minimum | Notes |
|---|---|---|
| WordPress | 6.2 | Checked on **System Status** |
| PHP | 7.4 | Checked on **System Status** |
| Elementor | Per `Elementor_Limits` | Reported by `status()` |
| PHP extensions | `json`, `mbstring` | A missing one is a warning, not a block |

When a requirement is unmet, **System Status** names the missing piece and the action
that fixes it. It does not fail silently and it does not crash.

## 9. Testing

`tests/phase4-contract-test.php` covers container fallback, widget availability,
invalid element ids, invalid properties, responsive settings, draft creation, and
draft validation.

`tests/phase6-contract-test.php` includes an end-to-end test that writes a wrong font
size, validates, plans, applies, and rolls back — asserting on the **stored
`_elementor_data` and `_elementor_responsive`**, not on what the run reported. A test
that asserts on the run's own summary would pass even if nothing was written.

## 10. Known limitations

- A container whose child count exceeds the installed Elementor's limit is refused
  rather than silently truncated.
- A widget that exists but whose controls have changed between Elementor versions
  is reported as an unavailable control, and the property becomes `blocked` with a
  reason. It is not attempted.
- Ten cross-kind comparisons are reported `blocked` because Phase 5 pairs
  section-level source components with containers. The output is truthful;
  suppressing the noise is a Phase 5 design change rather than a bug fix.
- The generated draft has not been opened in the Elementor editor in this
  environment, so the `_elementor_responsive` write is verified by the stored
  document and the read-back hash rather than visually. Manual QA in the editor is
  on the release checklist.
