# ReplicaForge Compatibility

## 1. Supported versions

| Component | Minimum | Verified against |
|---|---|---|
| WordPress | 6.2 | 7.1.2 |
| PHP | 7.4 | 8.4.25 |
| Elementor | Per `Elementor_Limits` | 4.3.2 |
| MySQL / MariaDB | 5.7 / 10.3 | SQLite drop-in also verified |
| Browsers | Any current desktop or mobile browser | — |

`Requires at least: 6.2` and `Requires PHP: 7.4` are declared in the plugin header,
so WordPress refuses to activate on an unsupported version rather than activating and
failing at an arbitrary later point.

## 2. Elementor

ReplicaForge detects rather than assumes. `Elementor_Generator::status()` reports
availability, reason, version, and container support.

| Situation | Behaviour |
|---|---|
| Elementor not installed | `elementor_missing`. Analysis, AI planning, and validation configuration work. Generation refuses with an action. |
| Elementor installed but inactive | `elementor_inactive`. Same, with a different action. |
| Elementor without container support | Falls back to sections and columns, and says so in the report. |
| A widget the version does not provide | Not emitted. The check is at the single choke point, so there is no path around it. |
| A control the version does not expose | The property is `blocked` with a reason, never `requires_review`. |

A version-specific assumption is a bug. The test suite covers the container fallback
and widget availability.

## 3. PHP

| Extension | Needed for | If missing |
|---|---|---|
| `json` | Encoding representations | Blocking |
| `mbstring` | Text handling | Warning; reduced text handling |

**System Status** checks the version, the extensions, the memory limit, the time
limit, and the uploads directory.

## 4. Database

| Engine | Status |
|---|---|
| MySQL 5.7+ | Supported |
| MariaDB 10.3+ | Supported |
| SQLite drop-in | Verified on this site |

The plugin issues no queries with interpolated values and uses `$wpdb->prepare()` with
`$wpdb->esc_like()` for the three queries it does make. It creates no tables, so there
is no `dbDelta` and no engine-specific DDL.

## 5. WordPress configuration

| Setting | Effect |
|---|---|
| `WP_DEBUG` true | Logger records `debug` level. More detail in diagnostics. Users still get safe messages. |
| `DISABLE_WP_CRON` true | Background jobs do not run automatically. **System Status** warns and explains. Analyze and generate still work when run directly. |
| `memory_limit` below 128M | Warning; large pages may stop early. |
| `max_execution_time` at 0 | Unlimited. |
| Object cache present | Caches behave better. Not required. |

## 6. Multisite

ReplicaForge stores per-site options and does not use network-wide tables, so it
works on a multisite install with each site behaving independently. Activation and
scheduling are per site.

Multisite is not a tested configuration. The plugin makes no claim beyond the above
being structurally true.

## 7. Themes

ReplicaForge reads the analyzed website's frontend, not the local theme. The only
local-theme interaction is the generated Elementor document, which inherits the local
theme's Elementor styles.

The admin CSS is scoped to `.replicaforge-admin` and loaded only on ReplicaForge
screens, verified by asserting that an unrelated admin page loads no ReplicaForge
asset.

## 8. Other plugins

| Interaction | Handling |
|---|---|
| Another admin theme | Colours come from CSS custom properties with literal fallbacks, so the screens follow the active scheme. A `prefers-color-scheme: dark` block covers a dark scheme. |
| A security plugin | The REST nonces and capability checks are standard, so a plugin checking nonces sees them. |
| A caching plugin | The REST routes are not cached. Cached admin pages could show a stale job list; the screens are not page-cacheable by default. |
| A plugin that filters `cron_schedules` | The custom schedule is added only if absent, so a conflicting definition wins. |
| A translation plugin | All strings use the `replicaforge` text domain and are translatable. |

## 9. Language and direction

The admin CSS uses logical alignment where it matters and does not assume
left-to-right. The interface is translation-ready: every user-facing string uses
`__()`, `_e()`, `esc_html__()`, or `esc_attr__()` with the `replicaforge` text
domain, and the `languages/` directory is declared via `Domain Path`.

## 10. Browser support

| Browser | Version |
|---|---|
| Chrome, Edge, Firefox | Current and previous major |
| Safari | 16 and later |
| iOS Safari | 16 and later |
| Android Chrome | Current |

The admin UI degrades to a card layout below 782px, which is where WordPress
collapses its own layout. It is usable on a phone, which is why a horizontally
scrolled correction table was replaced rather than merely made scrollable.

## 11. Accessibility

| Requirement | Implementation |
|---|---|
| Keyboard navigation | Every interactive element is focusable; the scrollable table region has `tabindex="0"` |
| Focus visible | A 2px focus ring on links, buttons, inputs, and `[tabindex]` elements |
| ARIA | `role="progressbar"` with value and accessible name; `role="region"` with a label; `aria-hidden` on decorative marks |
| Status not by colour alone | A word and a glyph accompany every colour |
| Semantic buttons | Actions are `<button type="submit">` inside forms, not styled links |
| Screen readers | Table headers are scoped; cells carry `data-label`; decorative marks are hidden |
| Reduced motion | A `prefers-reduced-motion` block removes transitions |
| High contrast | A `prefers-contrast: more` block strengthens borders |
| Contrast | Text uses admin foreground colours rather than hard-coded values |

The admin test renders each screen and asserts the ARIA attributes, the scoped
headers, the nonces, and the keyboard affordances are present.

## 12. Verification

| Check | Result |
|---|---|
| `php -l` on every file | 111 files, 0 failures |
| Boot check | 99 types load, 10 services construct, 20 routes register |
| Contract suites | 9 suites, 908 assertions, 0 failures |
| Unescaped variable echo | 0, by token-level scan |
| Translation calls with a wrong domain | 0 |
| REST arguments with no validation | 0, verified against the running plugin |
| Functional routes with no permission callback | 0 |
| Query built by concatenation | 0, by source scan |

## 13. Not verified

Recorded so these are not read as claims:

- The generated draft has not been opened in the Elementor editor UI. The
  `_elementor_responsive` write is verified by the stored document and the
  read-back hash. Visual confirmation is on the release checklist.
- No live AI provider request has been made. The AI boundary is verified by contract
  tests over the prompt builder and the redactor, not by a real provider call.
- No render provider has been exercised.
- Multisite has not been tested.
- Non-English locales have not been tested.

---

## Phase 10: commercial compatibility checks

### New capabilities and how to check them

The system status screen's existing checks do not cover the Phase 10 permission
model, because the permission model did not exist before this phase. Two new
questions are worth asking, and both have an answer in code rather than in a
document:

**Is every declared capability held by at least one role?**

`Capabilities::orphan_check()` returns `ok` and a list of `orphaned` capabilities.
A capability no role holds is a capability nobody can pass, and the symptom is
every user being refused with no explanation. It is exposed at
`GET /replicaforge/v1/capabilities` and can be run directly:

```php
print_r( \ReplicaForge\Capabilities::orphan_check() );
// array( 'ok' => true, 'orphaned' => array() )
```

If `orphaned` is not empty, run `Capabilities::grant_default_roles()`. It is
idempotent and only ever adds.

**What does a role actually hold?**

```php
print_r( \ReplicaForge\Capabilities::role_report() );
```

This is the real state rather than the intended state, which is the point — an
administrator who removed a capability from a role needs to see that.

### Roles this phase touches, and roles it does not

| Role | Effect |
|---|---|
| `administrator` | Gains five capabilities. Nothing is removed. |
| `editor` | Gains two. Nothing is removed. |
| `author` | Nothing. |
| `contributor` | Nothing. |
| `subscriber` | Nothing — and the test suite uses one to verify a refusal. |

Grants are **additive only**. `Capabilities::revoke_all_roles()` exists and is
called on uninstall; it is deliberately *not* called on deactivation, because
deactivating and reactivating a plugin should not quietly rewrite who can do what
in the database.

A role that does not exist on a site is skipped, which is what makes the grant
correct on a site with a custom role set and on a network where the administrator
role has been renamed.

### The one lockout escape hatch

`Plans_Api::can_manage()` accepts `replicaforge_manage_plans` **or**
`manage_options`.

An installation that predates the capability grants, or a site where the
activation hook never ran, would otherwise lock its own administrator out of the
plan settings — and a lockout there is unrecoverable without a database edit, which
is a worse outcome than an administrator seeing a settings screen they should not
see. `manage_options` is already the plugin's Phase 1–8 gate for the same screens,
so this widens nothing that was not already open.

### No billing provider is a supported state, not a degraded one

`License_Manager::billing()` returning `null` is the normal, fully supported
configuration. With no provider:

- the plan screen reports that billing is not configured;
- every upgrade prompt says so and links to the plan details page;
- `POST /license` still works, because the local licensing provider is active;
- nothing anywhere attempts a network call.

There is no state in which ReplicaForge behaves differently because a billing
provider is missing. That is the property to test for, and it is what
`Onboarding::product_notice()` exists to state to the user.

### Schema version

| | Before | After |
|---|---|---|
| `Schema::DB_SCHEMA_VERSION` | `1.0.0` | `10.0.0` |
| `Schema::PLAN_SCHEMA_VERSION` | — | `9.0` |

The `10.0.0` migration is idempotent, destroys nothing, and has no call into any
Phase 1–9 service. It grants the capabilities, records the site plan as `free` if
it was never set, and records the trial configuration as disabled if it was never
set. It deliberately does **not** write the plan definitions, so a site that
overrode a limit keeps its override across a plan release.

`Schema::PLAN_SCHEMA_VERSION` is separate from the data schema version so an
exported plan document carries the shape it was written in. An import carrying a
newer plan schema is refused rather than half-read, because a plan document with a
key this version does not understand is a document whose limits might be
incomplete — and an incomplete limit is read as unlimited.

### Multi-user and multisite

Multi-user is covered: five capabilities, ownership enforced by `Project_Access`,
usage metered per user, the audit log recording the acting user.

**Multisite is untested in Phases 1 through 10.** Specifically and concretely:

- The usage lock uses `add_option()`, which is per-blog. Two blogs of a network can
  each grant the same reservation for the same user, so a network-wide quota does
  not hold. A per-site quota does.
- Plan definitions, the site plan, the license record, and the audit log are all
  per-blog options. That is the right granularity for a site license and the wrong
  one for a network licence.
- Capabilities are granted to the roles of the current blog only.
