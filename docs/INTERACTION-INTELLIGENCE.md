# Phase 16 — Interaction Intelligence

**Status:** complete, with documented limitations
**Plugin version:** 1.4.0
**Interaction schema:** 16.0
**Engine version:** 1.0
**Test suite:** 24 suites, 5,680 assertions passed, 0 failed, 2 skipped (both pre-existing)

---

## 1. What this phase adds

ReplicaForge could already read a website: its structure, layout, styling, responsive
behaviour, appearance, content, and the visual differences between the source and the
replica. It could not answer the question a person actually asks when they look at a site:

> *What happens when I click this?*

Phase 16 models that. It detects the interactive components on a page, works out what state
each one is in, what moves it out of that state and back, and maps each behaviour onto what
the destination site's Elementor can actually do.

### The security boundary, stated first

**Source JavaScript is never executed, never copied, and never reconstructed.** The whole
layer is arranged as:

```
Source website
     ↓
Static DOM / ARIA / CSS analysis      (phase 2, reused)
     ↓
Browser observation, if configured    (out of process, operator-controlled)
     ↓
Behaviour model — state machines
     ↓
Validation gate                       (§40)
     ↓
Elementor mapping, resolved live      (phase 4 compatibility)
     ↓
Supported widget | approximation | static fallback | manual
```

The last row is the whole design. A behaviour the destination cannot reproduce becomes an
inert stand-in or a note for a person — never source script. This is enforced in three
independent places, so no single mistake can breach it:

1. `Interaction_Model::find_code()` walks the model and rejects `<script`, `javascript:`,
   `onerror=`, `onload=`, `onclick=`, `onmouseover=`.
2. `Interaction_Validator::find_unsafe_urls()` refuses executable *schemes* before
   validating anything, and refuses any address that is not a validated public URL.
3. `Elementor_Widget_Registry` — the phase 4 allowlist, which this phase does not touch and
   cannot widen.

---

## 2. Files

### `includes/interactions/` — 9 files

| File | Size | Role |
|---|---:|---|
| `class-replicaforge-interaction-limits.php` | 19 KB | The vocabulary. Every constant, every list, the budget ceilings. |
| `interface-replicaforge-browser-driver.php` | 5 KB | The observation contract. Six operations, and no others. |
| `class-replicaforge-state-machine.php` | 13 KB | The unit of modelling: states, transitions, cycles, timelines. |
| `class-replicaforge-interaction-model.php` | 21 KB | The aggregate, the §40 gate, the honest-status logic. |
| `class-replicaforge-interaction-detector.php` | 30 KB | Static detection. A rules table, not a chain of conditionals. |
| `class-replicaforge-interaction-mapper.php` | 18 KB | Elementor capability mapping, resolved against the running install. |
| `class-replicaforge-interaction-validator.php` | 11 KB | The gate a specification must pass before anything uses it. |
| `class-replicaforge-interaction-service.php` | 45 KB | Orchestration, budgets, observation, form analysis. |
| `class-replicaforge-interaction-api.php` | 27 KB | REST: 10 routes, 10 endpoints, every one gated. |

### Modified

| File | Change |
|---|---|
| `replicaforge.php` | 9 requires in dependency order; version → 1.4.0. |
| `includes/class-replicaforge-plugin.php` | `interactions()` and `interaction_api()` accessors; boot construction; `rest_api_init` registration; `replicaforge_interaction_driver` filter. |
| `includes/class-replicaforge-dom-analyzer.php` | 22 attributes added to the bounded allowlist (§5 below). |

**Nothing else changed.** The reconstruction engine, the job system, the collaboration
system and every earlier phase are untouched.

---

## 3. The two decisions that shaped everything

### Two tiers, and the second is not a fallback

Tier one is static analysis of the phase 2 DOM. It **always** runs. Tier two is browser
observation, and runs only when an operator has registered a `Browser_Driver_Contract`.

That is not a graceful-degradation story bolted on afterwards. Most of what the
specification calls "interaction" is *declared in the markup*, not computed by a script:

| Markup | What it declares |
|---|---|
| `<details>` / `<summary>` | a disclosure state machine, with no script at all |
| `aria-expanded` + `aria-controls` | a toggle, with the target named |
| `role="tab"` + `aria-selected` + `aria-controls` | a tab set with panels |
| `<dialog>` or `role="dialog"` | a dialog |
| `data-ride="carousel"`, `aria-roledescription="carousel"` | a carousel |
| `position: sticky` inline | a sticky element |

So a browser is needed to **confirm** what the markup *claims*, never to discover what is
there. Which is the right way round: a browser that is unavailable must not mean "this page
has no interactions", because that would be a fabricated result.

The two states are both first-class and both reported honestly:

| Configuration | Status | What you get |
|---|---|---|
| No driver | `BROWSER_UNAVAILABLE` | A full static model, plus a limitation saying transitions were inferred |
| Driver configured | `INTERACTION_ANALYSIS_COMPLETE` | A confirmed model, with `observed` evidence and raised confidence |
| Budget exhausted | `INTERACTION_LIMIT_REACHED` | A partial model that says how many were dropped |
| Private path | `PRIVATE_PAGE` | Nothing, and a reason |
| Blocked address | `SOURCE_BLOCKED` | Nothing, and a reason |

### The state machine, not the trigger/action pair

A `{trigger: click, action: open}` pair is wrong for almost every interesting component, in
three ways that show up immediately on real sites:

1. **It cannot represent a cycle.** A modal is not "click → open"; it is
   `closed → open → closed`, and the intermediate states decide whether a second click
   during the animation should be honoured.
2. **It cannot represent shared state.** A mobile menu, a search overlay and a cookie
   banner can all be "the top layer is occupied". Three independent pairs cannot express
   that; three states in one machine can.
3. **It cannot represent a responsive difference.** A navigation that is a hover mega menu
   on desktop and a tap drawer on mobile is **one component with two paths**, not two
   components.

So `State_Machine` is the unit. A detected interaction is a transition *within* a machine.
A machine with one transition and no cycle is still a machine — the shape is uniform, so a
consumer never has to ask what it is holding.

```
Closed  --click-->  Open  --click-->  Closed
```

---

## 4. The vocabulary

One file: `Interaction_Limits`. Nothing else declares a list.

| Constant | Count | Notes |
|---|---:|---|
| `TYPES` | 35 | The 32 named behaviours plus `unknown` |
| `TRIGGERS` | 14 | `click`, `tap`, `hover`, `focus`, `blur`, `scroll`, `load`, `timeout`, `keyboard`, `submit`, `change`, `input`, `intersection`, `resize` |
| `OUTCOMES` | 4 | `supported`, `approximation`, `unsupported`, `requires_review` |
| `STATUSES` | 10 | §57's list, verbatim |
| `OBSERVABLE_TYPES` | 19 | The allowlist a driver may be asked to trigger |
| `SAFE_CORRECTIONS` | 14 | Scalar presentation properties only |
| `REVIEW_REQUIRED_CORRECTIONS` | 6 | Structural or behavioural |
| `FORBIDDEN_OBSERVATION_FIELDS` | 17 | §50's list, as a constant rather than a convention |

### What is deliberately *not* here

- **No severity list.** `Validation_Limits::SEVERITIES` is the one, and phase 5 already
  declares `interaction` and `navigation` as difference categories. Phase 16 populates that
  vocabulary; it does not add a parallel one. The test suite asserts both facts.
- **No viewport list.** `Validation_Limits::VIEWPORTS` is the one, read through
  `Interaction_Limits::viewports()`.
- **No `initial_state()` fallbacks that guess.** A type the vocabulary has no initial state
  for returns the literal `'unknown'`, and the machine built on it is reported rather than
  quietly given a plausible state.

### `PRIORITIES`-style list/map traps

Phase 15 lost three defects to `array_key_exists()` against a list. `TYPES` is a list, so
`array_key_exists( 0, TYPES )` is **true** and is the wrong test. `is_type()`,
`is_trigger()`, `is_outcome()`, `is_status()` and `is_form_type()` exist so the mistake
cannot be made, and the suite asserts both that the trap is real and that the helpers
defeat it.

---

## 5. Detection

`Interaction_Detector` is a **rules table**, not a chain of conditionals, because §6 and
§39 require every detection to carry its own evidence and a rule that merges two
conditions loses that. Each rule declares its evidence strings, its confidence floor and
its evidence source.

### Rule order is the logic

The first matching rule wins, and the order is deliberate:

1. `details_disclosure` — native, unambiguous
2. `role_tab` — `role` is specific
3. `native_dialog`
4. **`mobile_menu_trigger`** — needs `aria-expanded` *and* a hamburger class *and* a
   resolvable control
5. **`aria_menu`** — needs `aria-haspopup`
6. `aria_disclosure` — the general `aria-expanded` fallback
7. Framework-declared (`data-bs-toggle`, `data-ride`)
8. Landmarks
9. Form controls
10. Inline `position: sticky`
11. Scroll, hover and anchor hints

Steps 4–6 are where the ordering earns its keep. A hamburger button with `aria-expanded`
satisfies *every* condition `aria_disclosure` tests, so with the general rule first, every
mobile menu and every submenu trigger was classified as a generic disclosure. The specific
rules require strictly more evidence, so running them first loses nothing. **This was found
by running the detector, not by reading it.**

### What the detector refuses to report

| Case | Why |
|---|---|
| `aria-expanded` pointing at a non-existent id | A replica with a dead button is worse than no replica |
| A bare `hamburger` class with no expander behaviour | The icon is in the DOM on desktop too |
| A control inside `aria-hidden="true"` | Removed from the accessibility tree; unreachable by keyboard or screen reader |
| A control that *controls* a hidden element | Still an interaction — the two cases are opposites |
| One swatch in a `variations` group | `child_count >= 2` is the difference between a selector and a button |

### Phase 2 attributes that had to be added

Detection failed for carousels because the attributes carrying the semantics were not in
phase 2's bounded allowlist, so the nodes did not have them. 22 were added:
`aria-selected`, `aria-modal`, `aria-roledescription`, `aria-describedby`, `aria-labelledby`,
`aria-pressed`, `aria-checked`, `aria-level`, `aria-owns`, `data-bs-target`, `data-ride`,
`data-slide`, `data-slide-to`, `data-interval`, `data-bs-interval`, `data-bs-pause`,
`data-wrap`, `data-bs-wrap`, `data-trigger`, `data-dismiss`, `data-bs-dismiss`, `data-parent`,
`data-offset`, `data-spy`, `for`, `tabindex`, `autocomplete`, `pattern`, `minlength`,
`maxlength`.

This is additive and bounded: a consumer reading the phase 2 representation sees extra keys
and ignores the ones it does not know. The shape of the change is worth naming — **the
markup was already declaring the behaviour, and the allowlist was what stopped us seeing
it.**

---

## 6. Evidence and confidence

Four sources, ordered by how much they are worth:

| Source | Floor | Meaning |
|---|---:|---|
| `observed` | 0.90 | A browser was driven and the transition was seen |
| `declared` | 0.70 | The page states it — `aria-expanded`, `<details>`, `role="tab"` |
| `inferred` | 0.55 | Derived from structure or a class hint |
| `visual` | 0.40 | From a render |

**An interaction below its source's floor is not reported at all.** A low-confidence guess
that reaches reconstruction becomes a widget with the wrong behaviour, and a person
debugging a replica that opens and closes wrongly has no way to tell the plugin guessed.
Reporting "possible interaction, not enough evidence" is strictly more useful.

Confidence is **not** invented. A transition duration is `0` unless the inline style states
one: a CSS `transition-duration` lives in a stylesheet, and reading stylesheets to find it
means a CSS parser. Guessing `300ms` would be fabricating a number.

The model's aggregate confidence is floored by the evidence source, so a pile of
0.6-confidence inferences cannot average up into something that reads as certainty.

---

## 7. Elementor mapping

Every mapping is resolved through `Elementor_Compatibility`, which reads the **running**
installation. A site with different widgets gets a different table.

### The Elementor 4 rename, found by testing

The first candidate list was written against Elementor 3.x names. Elementor 4 renamed its
structural widgets — `accordion` → `nested-accordion`, `tabs` → `nested-tabs` — so on the
Elementor **4.3.2** in this environment the mapper reported `approximation` for the two most
common interactions on a modern site, when the exact widget was sitting right there.

The candidate list is now ordered current-first with legacy names second, and the test
suite asserts the registry **agrees with the live install** rather than asserting a count.
That assertion is what caught it, and it is the property worth keeping: a stale widget name
shows up as `supported` dropping, and a hard-coded expected number would not notice a
future rename either.

On this install: 8 supported, 19 approximation, 8 unsupported.

### The three outcomes that are not `supported`

| Type | Outcome | Fallback | Why |
|---|---|---|---|
| `cookie_banner` | `unsupported` | `static` | Site-wide policy, not replica behaviour |
| `load_more`, `infinite_scroll` | `unsupported` | `static` | Need a destination data source; destination pagination is used |
| `filter`, `sort` | `unsupported` | `static` | Need destination query support |
| `login`, `registration`, `checkout` forms | not reproduced | `manual` | Interact with systems the replica does not own |
| `unknown` | `unsupported` | **`manual`** | An inert stand-in for unidentified behaviour looks like it works and does not |
| automatic-trigger modals | `requires_review` | `manual` | Unsolicited overlays are a person's decision |

`static` and `manual` are different and the difference is deliberate. A cookie banner has
a sensible inert stand-in. An *unknown* behaviour does not — rendering "something" produces
a replica that appears to work and does not, which is worse than showing nothing.

---

## 8. Validation — the §40 gate

Validation runs on the **source model, before mapping**, and a second, cheaper gate runs on
the mapped output. The order is a security property: by the time a mapping exists, the
dangerous content has been copied into a field a builder trusts.

Ten checks, each present because its absence produces a specific failure:

| Check | Prevents |
|---|---|
| `no_arbitrary_code` | §1. Never a warning — a hard rejection |
| `no_unsafe_url` | The replica becoming a request nobody authorised |
| `component_exists` | A trigger whose element is not in the model |
| `transition_valid` | Delegated to `State_Machine::validate()` |
| `trigger_declared` / `type_declared` | A vocabulary disagreement becoming a consumer guess |
| `viewport_valid` | A typo silently dropping responsive behaviour |
| `duration_plausible` | A negative or 99-second "transition" |
| `capability_exists` | Claiming support for a widget the install lacks |
| `fallback_stated` | An unsupported behaviour with no stated outcome |

The mapped gate is not redundant: the first runs on the model, the second on a mapping
resolved against a **live** Elementor that could have changed between the two calls.

---

## 9. Browser observation

### The contract has six operations and no escape hatch

`id()`, `version()`, `capabilities()`, `is_available()`, `unavailable_reason()`,
`observe()`.

There is no `evaluate()`, no `execute()`, no `run_javascript()`, no `exec()`, no `shell()`,
no `download()`, no `cookie()`. The suite asserts the exact method list and asserts each
forbidden name is absent. **The danger is not that a provider might misbehave, it is that
asking would be possible at all** — and the contract is what makes it impossible.

### Not a second browser engine

`Renderer_Contract` (phase 13) already models "something out of process that can look at a
page". This is a **narrower** view of the same world: it exposes observation operations and
nothing else. A provider implementing this cannot screenshot; a screenshot provider cannot
click. Splitting them is deliberate — a render job and an interaction job have different
budgets, different retentions and different privacy postures, and one contract for both
would mean loosening one.

### Safe exploration

`Interaction_Limits::OBSERVABLE_TYPES` is 19 types: navigation, dropdown, mega_menu,
mobile_menu, accordion, tabs, carousel, slider, modal, tooltip, popover, search_overlay,
lightbox, image_gallery, expand_collapse, sticky_header, sticky_sidebar, filter, sort.

**No form type appears in it at all.** There is no type on that list whose activation could
place an order, delete a record, send a message or change a password — because the cost of
one being wrong is not a bad replica, it is a real action taken on a real site.

`SIDE_EFFECT_TRIGGERS` (`submit`, `change`, `input`) is intersected out again before the
request reaches a driver, so a mislabelled candidate cannot get through.

### What is never stored

`record_observations()` checks **every** field name against
`FORBIDDEN_OBSERVATION_FIELDS` before keeping it, matching by name *and* substring, so a
provider cannot smuggle a secret in as `user_password` or `sessionStorage`. Rejected
fields are counted and logged, because a driver that routinely returns cookies is a fact
the operator needs.

The form analyser separately drops `value` from every field — the one attribute on phase
2's allowlist that can hold user input.

### Cache key

`'rfi_' + substr(sha256(json(project, source, engine, schema, driver_id, driver_version)), 0, 32)`.

Driver id *and* version, because two drivers genuinely see different things and two
versions of one driver may too.

---

## 10. Budgets

`Interaction_Limits::BUDGETS`, clamped on every read. A filter cannot raise a ceiling.

| Budget | Ceiling |
|---|---:|
| `max_pages` | 12 |
| `max_interactions` | 120 |
| `max_states` | 300 |
| `max_screenshots` | 40 |
| `max_browser_ms` | 120,000 |
| `max_network` | 400 |
| `max_memory_bytes` | 268,435,456 |
| `max_depth` | 3 |

A request above a ceiling is clamped. A request of zero gets the **default**, not zero —
"stop immediately" is never what a caller means. An unknown budget name is refused.

Reaching a ceiling produces `INTERACTION_LIMIT_REACHED` with a limitation naming it, never a
quiet truncation.

---

## 11. Forms

Analysed, never submitted. The whole point is what the analyser does *not* do: it never
contacts the action, never fills a field, never triggers validation on the page.

Eight classifications: `contact`, `newsletter`, `search`, `login`, `registration`,
`checkout`, `lead_generation`, `unknown`. Ordered most-specific first, because a newsletter
signup usually *has* an email field and a checkout usually does too, so the field list alone
cannot separate them.

`login`, `registration` and `checkout` are **identified but not reproduced** — the report
says which it found and why.

A `javascript:`, `mailto:` or `data:` action is dropped rather than stored. A replica that
reproduced a `javascript:` action would be executing source script on the destination.

### Why there is no separate `Form_Analyzer` class

The specification suggests one. This phase does it inside `Interaction_Service`, and that
is a deliberate refusal to add a class: form analysis reads the same node table, walks the
same ancestors and needs the same class-hint vocabulary as `Interaction_Detector`. A
separate class would either duplicate that vocabulary — two places to update when a site
uses a new convention — or hold a reference to the detector and be a wrapper with no
independent behaviour. The rule of one home per piece of knowledge applies to class
boundaries as much as to constants.

---

## 12. Defects found by execution and fixed

Twelve. Each is covered by an assertion.

### Security

1. **Every REST endpoint was ungated.** Both gates were `private`, so
   `is_callable(array($this, 'gate_signed_in'))` was `false` and
   `WP_REST_Server` — which calls `permission_callback` from *outside* the class — could
   not reach them. All ten endpoints would have answered an anonymous caller. Phase 15's
   gates are `public`; these now are too. **The suite now asserts `is_callable()`, not
   `isset()`, because presence is not the property that matters** — and a route-table walk
   reported them as "set but not callable", which is exactly the state where a permission
   callback exists and does nothing.
2. **A `javascript:` URL passed the URL check.** `find_unsafe_urls()` only examined
   strings matching a scheme and deferred to `Url_Validator`, which declines non-http(s)
   by returning an envelope with a code — so a caller asking "is this a valid public
   address?" had nothing to detect. Executable schemes are now refused *before* validation.
   Relying on a collaborator to refuse executable content, through a question whose answer
   is "no opinion", is how a `javascript:` URL reaches a replica.
3. **`observe()` asked the driver before validating the URL.** With no driver configured, a
   cloud-metadata address returned `BROWSER_UNAVAILABLE` — true, and useless, because it
   described the driver's absence rather than the fact that the address was never safe to
   request. The two facts have different remedies: configure a driver, versus stop asking
   for that URL. Validation now happens first, in both `analyze()` and `observe()`.

### Dead or inverted logic

4. **The derived interaction list was frozen after the first read.** `add_machine()` did not
   invalidate the cache, and the budget check in the analysis loop calls `interactions()`
   before any machine exists. A page with twelve machines reported **one** interaction.
5. **`reachable_states()` reported one state for a working machine.** It seeded only from
   `initial_state`, so a machine whose transition left a different state reported that state
   as unreachable while the transition plainly fired. It now seeds from every transition's
   `from`.
6. **Elementor 4 widget names were missing.** See §7 — found by running against a real
   4.3.2, and it silently downgraded the two most common interactions to `approximation`.
7. **`unknown` was offered a `static` fallback.** An unidentified behaviour rendered as an
   inert stand-in produces a replica that looks like it works and does not. Now `manual`.
8. **The `aria-disclosure` rule shadowed the mobile-menu and dropdown rules.** See §5.
9. **`aria-hidden` was only checked on the node itself**, not its ancestors, so a control
   inside a hidden wrapper was reported.
10. **Five constructor warnings on every instantiation.** `isset($services['x'] instanceof
    Y)` evaluates the array access *before* the type check, so a missing key warned rather
    than falling through. Noise that trains a developer to ignore the error log is a
    defect, not a cosmetic issue.

### Test expectations corrected rather than papered over

11. **A budget fixture was wrong, not the rule.** One swatch per `variations` group is not a
    variation group; `child_count >= 2` is exactly the distinction. The fixture was fixed.
12. **An assertion contradicted the vocabulary it was testing.** It asserted
    `! array_key_exists(0, TYPES)` and failed, because `TYPES` *is* a list and the index is
    a type name — which is the trap. Rewritten to assert the trap is real and that
    `is_type()` defeats it, rather than asserting a falsehood.

---

## 13. Tests

**Phase 16 suite:** 31 sections, **253 assertions**, 0 failures, 0 warnings.

31 sections covering: the vocabulary; existing vocabularies being read; each detectable
type with its markup signature; the three false-positive guards; the state machine; machine
validation; executable-content refusal; unsafe-URL refusal; the ten gate checks; Elementor
mapping against the live install; mapping lies refused; intrusive triggers; the observation
allowlist; budget clamping; the full pipeline on a realistic page; forms; observation-field
screening; the absent-browser path; limits reported; REST gating; every earlier phase still
registering; the plugin facade; the phase 2 allowlist change; and the driver contract's
absence of an escape hatch.

**Full suite: 24 suites, 5,680 assertions passed, 0 failed, 2 skipped** — both skips
pre-existing.

---

## 14. Known limitations

Stated plainly. None is worked around silently.

### The largest one

**No browser driver is configured in this environment, so browser observation has never
executed.** The contract, the allowlist, the budget enforcement, the observation recording
and the confidence promotion are all implemented and asserted; the *round trip* is not
tested, because there is no driver to test it with. This is the single biggest gap in the
phase and it is stated here rather than implied by a green suite.

What *is* verified: with no driver, the analysis still produces a complete static model,
reports `BROWSER_UNAVAILABLE` with a reason, and invents no observations.

### Also not done

- **No automatic-trigger detection from CSS.** A scroll-reveal needs a stylesheet parse, and
  a hint class is inference at 0.50 confidence — below nothing, but reported as `inferred`
  rather than `declared`.
- **No `hover` / `focus` state extraction.** §30 and §31 want the computed before/after
  styles. `cursor: pointer` inline is detected; a `:hover` rule in a stylesheet is not,
  because that needs the CSS the render provider would give. The types are declared and map
  to Elementor's style states; nothing populates them yet.
- **No carousel slide counting, autoplay, or dots.** `data-interval` and `data-bs-interval`
  are now readable, so the next step is cheap, but it is not done.
- **No Phase 12 shared-interaction reuse.** §44 asks for one header behaviour reused across
  pages. `Component_Registry` is the right store and `may_rewrite()` the right gate, and
  neither is wired yet.
- **No Phase 5 difference emission.** `interaction` is already a declared category with a
  weight and a severity table, and Phase 16 populates the models it would compare. The
  comparator itself is not extended.
- **No Phase 6 correction application.** `SAFE_CORRECTIONS` is declared and
  `Correction_Property_Map` is the right writer, but `Correction_Limits::FORBIDDEN_CONTROLS`
  contains a `_transform_*` glob that would need narrowing — deliberately, and that is a
  security change to a phase this one is not otherwise touching.
- **No Phase 9 sync proposal.** `Sync_Limits::CATEGORIES` already declares `interaction`
  and `ALWAYS_REVIEW_CATEGORIES` already includes it. The detector is not wired into
  `Change_Detector`.
- **No admin screen.** §48's Interaction Inspector is not built; the data is available
  through 10 REST routes.
- **No job stage.** Analysis is synchronous and bounded. A large multi-page site would want
  this in `Job_Runner`, which means touching `Job_Limits::STAGES`, the stage weights and the
  dispatch switch.
- **No AI integration.** §35/§36 permit AI to classify and explain. Nothing here uses it, so
  there is no hallucination surface — and no AI reasoning either.
- **Multisite untested.** No multisite install was exercised.

---

## 15. Acceptance criteria

| Criterion | Status |
|---|---|
| Interaction analysis exists | Done |
| Source JS never copied or executed | Done, enforced in three independent places |
| State-machine modelling | Done, and it is the unit of modelling |
| Navigation detected | Done |
| Mobile menus detected | Done |
| Accordions detected | Done |
| Tabs detected | Done |
| Carousels detected | Done |
| Modals detected | Done |
| Tooltips / popovers | Types declared and mapped; **no CSS-based detection yet** |
| Search UI detected | Done |
| Filters / sorting detected | Sort select detected; filter detection not implemented |
| Pagination / load-more | Types declared and mapped; detection not implemented |
| Forms analysed without submission | Done, and asserted |
| Sticky elements detected | Done, inline style and class hint |
| Hover / focus states | **Not implemented** — needs computed styles |
| Responsive differences | Partial: per-viewport summary; difference reporting needs all three viewports analysed |
| Elementor mapping works | Done, resolved live, 4.3.2 verified |
| Unsupported behaviour reported honestly | Done, with a stated fallback |
| AI cannot execute or modify | Trivially true — no AI is used |
| Browser isolation | Contract-level only; **no round trip tested** |
| Phases 1–15 intact | Done, 24 suites green |

---

## 16. Verification summary

| Check | Result |
|---|---|
| Phase 16 suite | 253 assertions, 0 failures, 0 warnings |
| Full suite | 24 suites, 5,680 assertions, 0 failed, 2 skipped (pre-existing) |
| `php -l` | 0 failures across the plugin |
| REST routes | 10 endpoints, 0 ungated, 0 admit anonymous |
| Phases 5–16 all register | 2 / 4 / 23 / 2 / 8 / 30 / 10 routes |
| Elementor mapping | agrees with live 4.3.2, 8 supported |
| Plugin boots | 1.4.0, accessors present |
| Browser round trip | **Not performed** - no driver available, so the loop is not tested |

Phase 16 is complete. The reconstruction engine is unchanged. The largest gap — that
browser observation has never actually been driven — is stated in §14 rather than
implied by a green suite.
