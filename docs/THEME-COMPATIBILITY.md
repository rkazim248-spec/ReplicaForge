# Theme Compatibility

> **Phase 8 status: NOT IMPLEMENTED.**
> There is no theme analysis, no conflict detection, and no Elementor global
> protection in the plugin. This document records the decisions that should govern it
> when it is built.
>
> What *is* true today: ReplicaForge writes local Elementor styles and never touches a
> theme file, a global style, or a template. That is a property of the existing
> generator, not a feature that was added in Phase 8.

## The two problems

A generated replica can be visually correct and still be wrong on the site, because of
two sets of rules it does not control:

1. **The theme's rules.** Container widths, default typography, button styling,
   global colours, and header/footer behaviour. A theme that styles `button` globally
   will restyle every button the replica generates.
2. **Elementor's existing design system.** Global colours, global fonts, kit
   settings, and saved templates. Overwriting these would damage every other page on
   the site.

The second is the more dangerous of the two, because the damage is to content ReplicaForge
has nothing to do with.

## Decision: never write a user's Elementor globals

**A generated replica uses local styles. Always.** It does not create, modify, or
overwrite Elementor global colours, global typography, or kits.

The reasoning is not caution, it is ownership. An Elementor global is a site-wide
setting. A replica of one page is a page-level artifact. Letting a page-level artifact
write a site-wide setting is a privilege escalation dressed as a convenience.

The opt-in the brief asks for — "create replica-specific global styles" — should be
implemented as **naming-prefixed globals in a dedicated kit**, never as edits to the
active kit. If a user wants replica tokens in Elementor globals, the tokens get their
own kit with a ReplicaForge prefix, and the active kit is untouched. That is the
difference between an opt-in and a hazard.

## What conflict detection would report

Each of these is a real, common conflict, and each is detectable from things WordPress
already knows:

| Conflict | How it is detected | Severity |
| --- | --- | --- |
| Theme sets a global `button` style | The theme stylesheet has a `button` or `.btn` rule, or the theme declares button typography | High — every generated button is affected |
| Theme container width differs from the source | The theme's content width is known and the source's is recorded by the layout engine | Medium — the replica is inside a narrower band than the source |
| Theme resets list and heading margins | The theme stylesheet has bare `h1`–`h6` or `ul` rules | Medium — spacing is wrong in a way that is hard to see in a screenshot |
| Theme typography is not the source's | The theme's font family differs from the recorded source family | Medium |
| Active kit is in use elsewhere | The kit is active and other pages use it | **Blocking for any global write** — the reason globals are never written |
| Theme outputs its own header/footer | The theme has a header or footer hooked | Low for a draft, since a draft renders under a template |

Severity drives the workflow, not just the report: a high-severity conflict should be
visible before generation finishes, not discovered in the editor.

## Why this is not a small piece of work

The theme stylesheet has to be read and understood in the same way the *source* page's
stylesheet is read, and that means the Phase 2 style analyzer has to be able to point
at an arbitrary stylesheet rather than a fetched one. The theme's own stylesheet also
pulls in parent theme styles, and often a build step. Reading the active theme's
effective rules is a larger problem than it looks, which is part of why it is not
started rather than half-done.

## What already limits the damage

Two existing behaviours reduce the blast radius, and both were deliberate:

- **The replica is a draft.** It is not published, so a theme conflict cannot reach a
  visitor until a person publishes it, at which point they can see it.
- **Styles are written per element**, not by injecting a stylesheet. A theme rule that
  wins a specificity contest is a visible problem in the editor, not a silent one in
  production.

Neither is a fix. They are why the absence of conflict detection has not caused damage
yet.

## Open questions carried forward

- The theme stylesheet is not the only source of theme rules. A block theme puts them
  in `theme.json`, and a page builder theme may add its own. Which are read first?
- Should conflict detection be a hard block (refuse to generate) or a warning? A hard
  block on a high-severity conflict is defensible, but it means a user cannot produce
  a draft to look at, which is often what they need in order to decide.
- Detecting that another page *uses* the active kit is not something WordPress exposes
  directly. If a global write is ever implemented, this is the hard part, and it argues
  again for never writing globals.

## Related

- [`ELEMENTOR.md`](ELEMENTOR.md) — how the generator writes styles today.
- [`../ARCHITECTURE.md`](../ARCHITECTURE.md) — the rule that a draft is never
  published automatically.
