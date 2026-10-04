# Asset Policy

> **Phase 8 status: NOT IMPLEMENTED.**
> This document is a design record, not a description of shipped behaviour.
> Nothing in this file is executed by the plugin. The one asset-related code that
> exists is `Svg_Sanitizer`, which is described in its own section below and *is*
> implemented and tested.
>
> Read this as a decision log, not as a manual.

## Why this document exists

The brief asks for explicit asset modes, deduplication, and failure handling. Phase 8
did not reach them. Writing them down records the decision so that the next phase
starts from a decided position rather than from a blank one, and so that nobody reads
a plan in this file and believes it is already running.

## Decisions taken

### Three modes, defaulting to the conservative one

| Mode | Behaviour | Risk |
| --- | --- | --- |
| `reference` | The replica points at the source URL. Nothing is downloaded. | The replica depends on the source staying online, and the source can change or remove the file. |
| `import` | The asset is downloaded, sanitized where relevant, and stored in the media library. | Reuse rights are unclear; a copyright holder may object. |
| `skip` | The asset is omitted and the element falls back to a placeholder. | The replica looks visibly incomplete. |

`Project_Repository::default_settings()` sets `asset_policy` to `reference`. This is
the default because it is the only mode that cannot make a legal claim on the user's
behalf. Importing an image from a third-party site into a public WordPress install is
a distribution of that image; ReplicaForge should not do that without the user asking
for it in a way that shows they understood.

This default is implemented, stored, and tested. **Nothing consumes it yet.** The
generator still uses the Phase 4 asset behaviour.

### Failure never produces a broken image

A failed asset must not become an `<img>` pointing at nothing. The three outcomes are
a placeholder, the original external reference, or omission, chosen by policy. Which
policy applies to which failure is not yet decided in code.

### Deduplication keys

An asset should be imported once. The candidate keys are, in order of preference:

1. **Content hash.** Correct, and requires the bytes, so it only works for imports.
2. **Normalized URL.** Strip the fragment, normalize the host case, drop a default
   port, sort query parameters. Correct for references, because a reference is by
   definition the same URL.
3. **Source URL as written.** The weakest key. Two different query strings are two
   different images, and treating them as one would show the wrong picture.

The risk this is solving: a page that references the same logo six times produces six
imports and six media-library entries, and the media library becomes unusable.

### What an SVG needs that a raster image does not

An SVG is a document. It can carry `<script>`, event handlers, `<foreignObject>`,
external references, and a `<!DOCTYPE>` with entity declarations. A browser renders it
with the same privileges as the page. So an SVG imported from a site being analyzed is
an attack the analyst did not write.

**This part is implemented.** `Svg_Sanitizer` (see `includes/security/`) is an allow
list. It keeps a fixed set of drawing elements, removes payload-bearing elements
together with their contents, removes the wrapper but keeps the contents for elements
that merely wrap drawing, removes every `on*` attribute regardless of spelling,
removes every attribute whose value is not a same-document fragment, and refuses
outright any document with a doctype, any document that is too large, any document
with too many elements, and any document that does not parse. It reports everything it
removed, so a caller can tell an icon that arrived clean from one that arrived hostile.

Covered by `tests/phase8-svg-test.php` — 112 assertions, including a mechanical sweep
of the cleaned output for any surviving `<script`, `<foreignobject`, `<animate`, any
`on*=` attribute, and any external `href`/`src`, plus an idempotence check that
sanitizing the output again changes nothing.

**What is not implemented:** calling the sanitizer from the asset pipeline. There is no
import step, so nothing invokes it. It is a finished, tested component with no caller,
which is the honest state of it.

### Fonts

Fonts are a special case of "restricted asset" and are treated as one.

**Not implemented.** The decision recorded here is that ReplicaForge will not
automatically download a font file. It will record the family name, classify the
source (system, Google Fonts, a remote file, or a CSS declaration), and either map to
a font Elementor already supports or leave the text in a safe fallback stack.

The reason to write it down now: downloading a font from a site and serving it from
the replica is redistribution, and a substituted font changes every measurement in the
page because the metrics differ.

## Open questions carried forward

- What placeholder does a skipped image use? An Elementor placeholder, a neutral
  block matching the recorded intrinsic size, or nothing at all.
- When a reference asset 404s at render time, is that ReplicaForge's problem or the
  site's? The answer matters for the failure policy, and the answer is currently
  "the site's", which means a reference-mode replica degrades over time.
- Should a content hash be computed for referenced assets, to warn the user that two
  different URLs are the same picture? That is a nice-to-have, not a correctness need.

## Related

- [`PHASE-8-ADVANCED-RECONSTRUCTION.md`](PHASE-8-ADVANCED-RECONSTRUCTION.md) — the
  engines that are built, and how this file fits beside them.
- [`../SECURITY.md`](../SECURITY.md) — the security architecture the sanitizer
  belongs to.
