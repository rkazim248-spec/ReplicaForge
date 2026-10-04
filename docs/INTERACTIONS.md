# Interactions

> **Phase 8 status: NOT IMPLEMENTED.**
> Nothing in this file is executed by the plugin. There is no interaction detector,
> no interaction representation, and no interaction reconstruction. This is a design
> record.
>
> One thing is true today and worth stating plainly: **ReplicaForge never executes
> source JavaScript, and Phase 8 did not change that.** It remains a hard boundary, not
> a setting.

## Why this file says "not implemented" at the top

The brief asks for interaction detection, interaction reconstruction, mobile
navigation handling, sticky elements, and scroll effects. None of that reached code in
Phase 8. The reason is scope: the layout, visual, token, and project work consumed the
phase, and writing a plausible-looking interaction detector that nothing validates
would have been worse than not writing one. The decision log below is what the next
phase starts from.

## The boundary that is not negotiable

Source JavaScript is **detected, never executed.** This is not a preference.

A script from the site being analyzed is arbitrary code that arrived over the network
and would run inside the analyzing WordPress install if executed. There is no sandbox
worth trusting for that, and no analysis benefit that justifies it. The design is
therefore:

- Evidence of an interaction is read from **markup, class names, attributes, and
  ARIA** — the parts a page declares about itself.
- The evidence is recorded as a *claim about the source*, in the source's own terms.
- The reconstruction is a **static, editable Elementor equivalent**, chosen from
  widgets that already exist.
- Where no equivalent exists, the replica shows the visible state and records the
  limitation. It never carries the source's script across.

## What would be detected

For each, the detection signal and the honest Elementor equivalent:

| Interaction | Evidence | Elementor equivalent | Honest limit |
| --- | --- | --- | --- |
| Accordion | `aria-expanded`, a class containing `accordion`/`collapse`/`toggle`, a heading wrapping a button | Accordion widget | Content and trigger match; the source's animation does not |
| Tabs | `role="tablist"`, `aria-selected`, a class containing `tab` | Tabs widget | Panel order and labels match; deep-linking does not |
| Carousel / slider | A class containing `carousel`/`slider`/`swiper`, `data-*` slide attributes, repeated sibling images | Image Carousel | The user can add slides; the source's per-slide content and autoplay behaviour do not carry |
| Modal | `role="dialog"`, a trigger with `aria-haspopup` | A CTA to a separate page, or a Popup widget | **The strongest divergence.** A modal is a state change; Elementor popups are a different system. Record the limitation rather than pretending. |
| Dropdown (menu) | `aria-haspopup="true"`, `aria-expanded` on a nav item | Elementor Dropdown menu | A mega menu with nested columns has no direct equivalent and must be approximated |
| Sticky header | `position: sticky` or `position: fixed` | Elementor's built-in sticky container setting | Reproducible, and the layout engine already reports the evidence (§10) |
| Mobile menu | A hamburger element plus a hidden navigation subtree | Elementor Nav with a hamburger | The drawer's transition and overlay behaviour differ; the structure can match |
| Filter UI | A form with `select`/`button` children inside a repeated group | Nothing interactive; a static representation plus recorded limitation | A working filter needs a server-side behaviour ReplicaForge must not reproduce |
| Counter | Text that looks numeric with a class containing `count`/`number`/`stat` | A heading with the observed value | A live counter is server state. The replica shows the observed value and says so. |

## Detection is confidence-scored, because these signals are weak

A class name is a hint, not proof. `class="tabs"` on a div is not an ARIA tab
implementation, and treating it as certain would put a Tabs widget into a document
where the source has something else.

Every detection would carry:

- `interaction_type`
- `trigger` — what the user does
- `state` — the visible state observed in the static HTML
- `responsive_behaviour` — whether a media query hides or reshapes it
- `confidence` — 0–1
- `evidence` — the exact attribute or class that drove the detection
- `elementor_widget` — the equivalent chosen, or null
- `limitation` — what does not survive, or null

A low-confidence detection becomes a warning, not a widget. The rule from the brief,
kept: low confidence should surface as a warning rather than silently becoming a hard
assumption.

## The rule that prevents the worst outcome

**No interaction may be reconstructed by copying source script, and no source script
may be carried into the Elementor document in any form** — inline, in a data
attribute, or in a `javascript:` URL.

`Data_Redactor::text()` (Phase 7) already neutralizes executable markup in anything
that reaches a log or a stored string, and `Correction_Property_Map` (Phase 6) is the
single boundary through which every property change passes. An interaction
reconstruction would have to go through the same boundary. If a future phase adds
interaction support and it does not route through `Correction_Property_Map`, that is
the bug to look for first.

## What the layout engine already gives this, for free

Two things are already built and tested, and are the natural inputs here:

- **Sticky evidence.** `Layout_Engine::analyze()` reports `placement.position` and a
  `placement.sticky` block for `position: sticky` and `position: fixed`, including the
  insets and the z-index. Elementor can reproduce this, so a sticky header is close to
  a mechanical mapping rather than a design problem.
- **Overlap evidence.** An out-of-flow element, a negative margin, or a transform
  produces an `overlaps` relationship with a confidence and its reasons, plus a
  limitation. A floating badge is the classic case, and the limitation text is already
  the honest statement that Elementor cannot always express it.

## Open questions carried forward

- Should a modal source become an Elementor Popup, or a link? A popup needs a target
  and a template, and generating one is a bigger step than mapping a widget.
- A carousel's per-slide content can be rich (a price, a button, a badge). Elementor
  slides are images. The honest answer is probably that a product carousel becomes a
  card grid, and the limitation says so.
- Sticky is reproducible; sticky *plus* an overlapping badge is not. Which wins?

## Related

- [`PHASE-8-ADVANCED-RECONSTRUCTION.md`](PHASE-8-ADVANCED-RECONSTRUCTION.md) — §10 and
  §32 of the brief, for the sticky and overlap evidence that is built.
- [`../SECURITY.md`](../SECURITY.md) — the never-execute boundary.
