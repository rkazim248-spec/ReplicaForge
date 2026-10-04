# ReplicaForge

ReplicaForge analyzes a **public website frontend**, builds a deterministic design
representation, optionally asks a configured AI provider to interpret it, converts
the validated reconstruction into an **editable Elementor draft**, **measures the
differences** between the source and the draft, and then **corrects the measurable
ones** under human review.

**Current version:** `0.9.0` - AI reliability, cost control, and job orchestration,
on top of plans, entitlements, usage accounting, licensing, and production hardening.

```text
Public URL
   ↓
Secure fetch and Phase 1 normalization
   ↓
DOM understanding
   ↓
Sections, components, layout, styles, responsive evidence
   ↓
Design Representation 2.0
   ↓
Optional user-triggered AI Design Understanding
   ↓
Validated Reconstruction Specification 3.0
   ↓
Deterministic Elementor generation
   ↓
WordPress draft (never published automatically)
   ↓
Normalized source and generated representations
   ↓
Comparison, difference detection, and a validation report
   ↓
Reviewed correction plan
   ↓
Targeted, snapshotted Elementor corrections
   ↓
Re-validation, regression detection, and a correction report
```

The output is a real, editable Elementor page built from containers and native
widgets. ReplicaForge never produces a screenshot, an iframe, one giant HTML widget,
a shortcode, or copied source code.

- **Requires PHP:** 7.4+
- **Requires WordPress:** 6.2+
- **Elementor:** required for draft generation, not for analysis
- **License:** GPL-2.0-or-later

---

## Getting started

1. Install ReplicaForge from Plugins → Add New, and activate it.
2. Open **ReplicaForge** in the admin menu.
3. Read **System Status**. It tells you whether this install can run ReplicaForge,
   and if not, exactly what to do about it.
4. Click **Create New Replica**.
5. Enter the address of a **public webpage**.
6. **Analyze.** ReplicaForge fetches the page within strict limits and reports what
   it found.
7. **Review the design understanding** — sections, components, design tokens,
   typography, layout, and responsive evidence.
8. *Optional:* configure an AI provider in **Settings**, then run the AI stage. It
   improves the reconstruction plan. It is not required, and the deterministic plan
   is available without it.
9. **Generate Elementor draft.** You get a draft. It is never published.
10. Open **Validation** and **Run Validation** to measure how closely the draft
    matches the source.
11. **Review the corrections.** Each one states what would change, on which element,
    at which viewport, with what confidence, and why. Content, section removal,
    column count, and navigation are never applied automatically.
12. **Apply the corrections you approved.** A snapshot is taken first, the document
    is written once, and the result is re-validated.
13. **Open the draft in Elementor** and continue from there.

---

## What ReplicaForge does

| Phase | What it does |
|---|---|
| **1. Secure analysis** | Validates the URL, fetches the page within strict limits, and normalizes the HTML into a structure. |
| **2. Design intelligence** | Extracts sections, components, design tokens, typography, layout ratios, and responsive evidence. |
| **3. AI understanding** | Optional. Asks a configured provider to interpret the representation as a structured specification. Website content is treated as untrusted data. |
| **4. Elementor generation** | Builds a real document from containers and native widgets, creates a draft, re-reads it, and records a hash. |
| **5. Validation** | Reads the draft and its generated stylesheet, pairs components, and compares structure, layout, typography, colour, spacing, assets, and responsive rules. |
| **6. Corrections** | Plans measurable corrections, presents them for review, applies only what was approved, detects regressions, and offers rollback. |
| **7. Production hardening** | Central error handling, logging, background jobs, migrations, cleanup, admin UX, accessibility, and a release checklist. |
| **8. Advanced reconstruction** | Six engines for CSS grid and flexbox, positioning and overlap, gradients, shadows, borders, backgrounds, SVG safety, design tokens, and project versioning. **Built and tested; not yet wired into the pipeline.** See below. |
| **9. Source synchronisation** | Component identity that survives an edit, change detection on the normalized representation, severity and risk classification, and three-way conflict detection that protects your manual Elementor edits. **Built and tested; nothing is reachable and nothing is applied.** See below. |

---

## Phase 8 status — read this before expecting new features

Phase 8 added **six complete, tested engines and connected none of them to the
pipeline.** Installing this plugin gives you exactly the Phase 7 behaviour. Nothing
regresses, and nothing new is reachable from the admin screens or the REST API.

This is stated plainly here rather than buried in a changelog because the alternative —
a README that implies working features — is how a user loses an afternoon.

| Engine | Status |
|---|---|
| CSS value parser | Built, 95 assertions. Not called. |
| Layout relationship engine (grid, flexbox, positioning, overlap) | Built, 157 assertions. Not called. |
| Visual effects (gradients, shadows, borders, backgrounds) | Built, 149 assertions. Not called. |
| SVG sanitizer | Built, 112 assertions. Not called. |
| Design token engine | Built, tested. Not called. |
| Project system | Built, 135 assertions. No pipeline populates it, no screen shows it. |

The engines found **26 defects in their own code** when executed, several of which
would have produced quietly wrong reconstructions rather than an error. They are
documented in [`docs/PHASE-8-ADVANCED-RECONSTRUCTION.md`](docs/PHASE-8-ADVANCED-RECONSTRUCTION.md)
and summarised in the completion report, with 4 of 27 acceptance criteria honestly
marked as met.

**The project system is the closest to complete.** It works end to end — create, list,
version, track drafts, detect duplicates, delete safely — and only needs the pipeline
and one screen to be useful.

---

## What ReplicaForge will not do

- **Never publishes.** A generated page is a draft. Publishing is a manual decision.
- **Never overwrites a published page.** Corrections apply only to drafts ReplicaForge
  generated.
- **Never overwrites your manual edits silently.** A property you changed by hand is
  flagged for review rather than replaced.
- **Never executes website code.** A website's JavaScript is never run.
- **Never executes AI output.** A model produces validated structured data, nothing
  more.
- **Never accepts a document from the browser.** The browser sends a plan id and a
  list of correction ids. A whitelist of 24 properties is the only path to a write.
- **Never claims a percentage it did not measure.** The metrics are deterministic
  comparisons, not an accuracy score.
- **Never sends telemetry.** There is none, and Phase 7 added none.
- **Never fakes progress.** A job's percentage is derived from the stage it reached.
- **Never ignores a security refusal.** There is no override.

---

## Security

| Area | How |
|---|---|
| SSRF | Scheme, credentials, port, hostname, and **every** resolved IP are checked. Redirects are followed one hop at a time, and each hop is re-validated. |
| Request limits | Timeout, redirect cap, response size cap, content-type check, on every request. |
| Authorization | `manage_options` plus a REST nonce on every privileged operation. |
| Injection | No query is built by concatenation. No unescaped output. No code execution. |
| Secrets | Redacted from every log entry, job record, AI payload, and diagnostic export. |
| Uninstall | Removes ReplicaForge's data and nothing else. |

Full detail in [`docs/SECURITY.md`](docs/SECURITY.md).

---

## Documentation

| Document | About |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | The pipeline, the service graph, where state lives |
| [`docs/SECURITY.md`](docs/SECURITY.md) | The threat model, every control, the audit checklist |
| [`docs/API.md`](docs/API.md) | Every REST route, argument, and response |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Storage, versions, migrations, cleanup, uninstall |
| [`docs/AI.md`](docs/AI.md) | The AI trust boundary and prompt injection defence |
| [`docs/ELEMENTOR.md`](docs/ELEMENTOR.md) | The compatibility layer and responsive storage |
| [`docs/JOBS.md`](docs/JOBS.md) | Background processing, idempotency, recovery, retry |
| [`docs/VALIDATION.md`](docs/VALIDATION.md) | Phase 5 in detail |
| [`docs/CORRECTIONS.md`](docs/CORRECTIONS.md) | Phase 6 in detail |
| [`docs/PERFORMANCE.md`](docs/PERFORMANCE.md) | Measured costs, limits, caching |
| [`docs/PRIVACY.md`](docs/PRIVACY.md) | What leaves the site, and what never does |
| [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md) | Supported versions and what is untested |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | What to check when something does not work |
| [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) | Running and writing the tests |
| [`docs/RELEASE-CHECKLIST.md`](docs/RELEASE-CHECKLIST.md) | The pre-release gate |
| [`docs/PHASE-7-AUDIT.md`](docs/PHASE-7-AUDIT.md) | The audit that produced the 0.7.0 work |
| [`docs/PHASE-7-COMPLETION-REPORT.md`](docs/PHASE-7-COMPLETION-REPORT.md) | What changed, what was fixed, what is untested |

Phase 8 documents. **Five of the seven are decision records, not descriptions of
shipped behaviour**, and each says so on its first line.

| Document | About |
|---|---|
| [`docs/PHASE-8-ADVANCED-RECONSTRUCTION.md`](docs/PHASE-8-ADVANCED-RECONSTRUCTION.md) | The six engines, how each works, the 26 defects found, and what is missing |
| [`docs/PHASE-8-COMPLETION-REPORT.md`](docs/PHASE-8-COMPLETION-REPORT.md) | The full Phase 8 report, with 4 of 27 acceptance criteria met |
| [`docs/PROJECTS.md`](docs/PROJECTS.md) | **Implemented.** The project system, versioning, and safe deletion |
| [`docs/ASSET-POLICY.md`](docs/ASSET-POLICY.md) | **Decision record.** Asset modes and deduplication; the SVG sanitizer section is implemented |
| [`docs/INTERACTIONS.md`](docs/INTERACTIONS.md) | **Decision record.** Interaction detection, and the never-execute boundary |
| [`docs/THEME-COMPATIBILITY.md`](docs/THEME-COMPATIBILITY.md) | **Decision record.** Theme conflicts and Elementor global protection |
| [`docs/PHASE-9-SYNC.md`](docs/PHASE-9-SYNC.md) | The whole Phase 9, with the not-built boundary |
| [`docs/SOURCE-CHANGE-DETECTION.md`](docs/SOURCE-CHANGE-DETECTION.md) | **Rewritten in Phase 9.** Identity, matching, and the diff, now implemented |
| [`docs/CONFLICTS.md`](docs/CONFLICTS.md) | The three-way merge that protects your manual edits |
| [`docs/SYNC-SECURITY.md`](docs/SYNC-SECURITY.md) | The boundaries Phase 9 sits behind |
| [`docs/MONITORING.md`](docs/MONITORING.md) | **Decision record.** Scheduling, opt-in, and reachability |
| [`docs/PROJECT-VERSIONS.md`](docs/PROJECT-VERSIONS.md) | **Decision record.** Generation history and restore |

---

## Admin screens

| Screen | What it answers |
|---|---|
| **Dashboard** | Can I run it, what did I build, what is it doing now |
| **Replica** | The guided workflow: analyze, generate, validate, correct |
| **History** | Every job, with resume, retry, cancel, and delete |
| **Validation** | How closely a draft matches its source |
| **Corrections** | Review, apply, and roll back corrections |
| **Settings** | AI provider, render provider, retention |
| **System Status** | 14 checks, each naming the action that fixes a failure |
| **Logs** | Structured, redacted, filterable, exportable |

---

## Limitations

These are real, and ReplicaForge does not hide them.

- **A measured improvement is not a guarantee of visual quality.** The metrics are
  deterministic comparisons. They do not predict how a page will look to a person.
- **Rendered comparison needs a render provider.** Without one, validation compares
  the document and stylesheet only, and says so in the report.
- **Very large pages are analyzed partially.** Limits are reported, never silently
  applied.
- **A manual edit made before the first correction plan** is detectable only as a
  document hash change, not per property.
- **The AI stage is optional and its provider is untested against a live service** in
  the automated suite. The request and response boundary is tested; the provider
  round trip is not.
- **A queued job on a quiet site waits**, because WP-Cron only fires on traffic.
- **The generated draft has not been opened in the Elementor editor** during
  automated verification. The stored document and its hash are verified; visual
  confirmation is on the release checklist.
- **Phase 8's engines are not wired into the pipeline.** CSS grid, flexbox,
  positioning, overlap, gradients, shadows, borders, backgrounds, SVG safety, and
  design tokens are all implemented and tested, and none of them is called during
  analysis. Accuracy today is Phase 7 accuracy.
- **Reconstruction modes and priorities are stored but do nothing yet.** A project
  records `mode`, `priority`, and `asset_policy`; no code reads them. Expect this to
  change nothing about the output.
- **Image classification, responsive images, navigation hierarchy, interactions,
  product and blog structures, theme conflict detection, source change detection,
  and incremental regeneration are not implemented.** Five of them have decision
  records in `docs/`; two have none.
- **Phase 9 detects changes and refuses to overwrite your work, but applies
  nothing.** There is no sync plan, no apply, no rollback, and no screen. The
  decision *whether* to write is made; nothing carries it out.
- **Source monitoring does not exist.** No cron event, no opt-in, no way to check a
  page for changes on a schedule. The safety rules are in place and tested; nothing
  uses them.

---

## Content, rights, and what this tool is

ReplicaForge is a **frontend analysis and editable reconstruction platform**. It
analyzes what a browser can see and builds editable structures from it.

It is not a tool for bypassing authentication, accessing private systems, copying
backend code, or executing third-party website code.

**Public accessibility does not automatically grant reproduction rights.** Logos,
images, text, product descriptions, and other assets on a site may be protected by
copyright, trademark, or other law.

**You are responsible for ensuring you have permission to reuse any content or asset
you import.** Asset import is off by default for this reason, and the generation
report lists what was imported, what was referenced rather than copied, and what
was blocked.

ReplicaForge does not provide legal advice about any individual site. If you are
unsure whether a use is permitted, ask someone qualified to advise you before
publishing. See [`docs/PRIVACY.md`](docs/PRIVACY.md).

---

## Verification

```text
php -l                            131 files, 0 failures
Boot check                        111 types, 10 services, 20 routes
Contract suites                   17 suites, 1934 assertions, 0 failures
  Phase 1–7 regression            9 suites, 908 assertions, 0 failures
  Phase 8 engines                 5 suites, 648 assertions, 0 failures
  Phase 9 reasoning               3 suites, 378 assertions, 0 failures
REST argument coverage            104 arguments, 0 unvalidated
Unescaped variable echo           0
Translation calls, wrong domain   0
```

Run them with [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md).

---

## Development

Plain PHP. No build step, no package manager, no third-party runtime dependency.

```text
replicaforge.php     bootstrap
uninstall.php        uninstall policy
includes/            Phase 1–2 services, ai/, elementor/, validation/, corrections/, jobs/
                     Phase 8: css/, layout/, visual/, security/, tokens/, projects/
                     Phase 9: sync/
admin/               css and js
tests/               executable contract tests
docs/                documentation
languages/           translation files
```

Phase 7 added, in dependency order: `Error_Catalog`, `Request_Context`,
`Data_Redactor`, `Logger`, `Feature_Flags`, `Schema`, `Migrator`, `Job_Limits`,
`Job_Repository`, `Job_Queue`, `Job_Runner`, `Job_Api`, `Maintenance`,
`System_Status`, and `uninstall.php`.

---

## Privacy

Nothing leaves the site by default. When a person configures an AI provider and runs
the AI stage, a redacted design representation and the source URL are sent to that
provider, and to nobody else. The API key never reaches a browser, a log, or an
export. See [`docs/PRIVACY.md`](docs/PRIVACY.md).

---

## Phase 10: plans, usage, licensing, and capabilities

Version `0.8.0` adds a commercial layer. It is a real WordPress plugin layer, not a
SaaS dashboard, and the most important thing to know about it is what it is not:

> **There is no payment processing, no license key validation, no simulated
> activation, and no billing provider implementation.** A site with nothing
> connected runs fully offline on the free plan, and every screen that would
> otherwise offer a purchase says plainly that billing is not configured.

What exists is the machinery a real product needs — centralized plan definitions,
server-side entitlement enforcement, per-user usage accounting that charges only
for work that succeeded, a provider-independent licensing abstraction, a bounded
audit log, and first-run onboarding state — wired to a plan the site
administrator configures locally.

```text
BillingProvider          (optional — no implementation ships)
      ↓
LicenseProvider          (Local_License_Provider ships)
      ↓
License_State            is this site entitled to a paid plan?
      ↓
Plan_Manager             which plan, and why
      ↓
Entitlement_Manager      may this user do this, right now
      ↓
Usage_Manager            has any of it left
```

### Five new capabilities

The plugin had **no capabilities of its own** before this phase: every check was
`manage_options` or `edit_pages`, which is why it was administrator-only by
accident rather than by decision, and why the multi-user requirement could not be
satisfied.

| Capability | Administrator | Editor |
|---|---|---|
| `replicaforge_use` | ✓ | ✓ |
| `replicaforge_generate` | ✓ | ✓ |
| `replicaforge_manage_projects` | ✓ | |
| `replicaforge_manage_settings` | ✓ | |
| `replicaforge_manage_plans` | ✓ | |

Authors and contributors get nothing. Grants are additive to **roles**, applied on
activation and on the `10.0.0` migration, and idempotent.

The Phase 1–9 checks are untouched, which is what keeps those phases working.

### Usage is charged for completed work, not for clicks

```php
$begin = $entitlements->begin( 'generation', $user_id, array( 'project_id' => $id ) );
if ( empty( $begin['allowed'] ) ) { /* refused: capability, plan, or limit */ }

// ... run it, which may fail ...

$succeeded ? $entitlements->settle( $user_id, $begin['reservation'] )
           : $entitlements->fail( $user_id, $begin['reservation'], 'elementor_missing' );
```

Ten simultaneous requests against a limit of two cannot start ten generations:
every counter read-modify-write happens inside a lock taken with `add_option()`,
which is a single `INSERT` against a unique column. A reservation that is never
settled expires after two hours and is swept daily, so a crashed job does not cost
a user the rest of the month.

### 13 new REST routes, one response envelope

`success` / `data` / `meta.request_id`, or `success: false` with a stable `error`
code. See `API.md`.

Nothing in the new surface accepts a plan, a usage count, a license state, or
Elementor data. A plan id may be *named* and is looked up in the compiled set; a
usage counter may never be sent; a license state may only be set through the local
provider, and only while that provider is active.

### What is not built

- **No admin screens.** The services, routes, gates, and refusal text exist and are
  tested. The visual screens that render them do not. This is the largest gap and
  it is Phase 11 work.
- **No enforcement on the Phase 1–9 endpoints.** `Entitlement_Manager` is not
  called from `Rest_Api`, so those operations are not metered yet.
- **`Project_Repository` is still not wired to the admin or REST layers.** It was
  built in Phase 8 and is tested, but nothing calls it except tests — so
  `Project_Access` has no live endpoint to enforce ownership at yet.
- **No demo mode.** The welcome screen offers a Demo action and reports it
  unavailable, rather than linking to a page that does not exist.
- **No telemetry, no analytics, no crash reporting.** Nothing leaves the site.

### Phase 10 documentation

| Document | Covers |
|---|---|
| [`docs/PLANS-AND-USAGE.md`](docs/PLANS-AND-USAGE.md) | The plan system, the gate, usage accounting, concurrency, refusals. |
| [`docs/LICENSING.md`](docs/LICENSING.md) | The licensing abstraction, the eight states, trials, connecting a provider. |
| [`docs/API.md`](docs/API.md) | The 13 new routes, the envelope, the error codes, the hooks. |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | The new classes, the storage keys, the cross-layer rules. |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Capabilities, ownership, what the new layer does and does not enforce. |
| [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md) | Capability checks, roles, schema version, multisite limits. |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | Plan, usage, trial, lock, and import problems. |
| [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) | Every Phase 10 hook, with working examples. |
| [`docs/PRIVACY.md`](docs/PRIVACY.md) | What is stored, the audit ring's bounds, no telemetry. |
| [`docs/PHASE-10-COMPLETION-REPORT.md`](docs/PHASE-10-COMPLETION-REPORT.md) | What was built, what was not, the defects found, and how to test it. |
| [`docs/AI-SYSTEM.md`](docs/AI-SYSTEM.md) | Provider capabilities, context budgeting, cost estimation, failure classification. |
| [`docs/RELIABILITY.md`](docs/RELIABILITY.md) | Job states, locks, checkpoints, retries, recovery, cancellation, crash recovery. |

---

## Phase 14: content and data intelligence

Phases 1-13 reconstruct a page. Phase 14 decides what should go *into* it.

ReplicaForge can now read what a page contains, read what your own WordPress and
WooCommerce installation contains, and propose - with confidence, risk, ownership and
evidence - which of the first should draw from the second.

**Structure, source content, user content, mapping and rendering are five separate
things.** Keeping them apart is the whole design: collapse structure and content and you
reproduce the wrong site's copy; collapse user content and mapping and you overwrite a
customer's price with a competitor's.

### What it does

- **A normalised content model (`14.0`)** where every value either came from the source
  page or is one of four declared markers for "not detected". Nothing is ever invented,
  and the model publishes that policy in a `fabrication` field.
- **31 semantic roles** detected from Phase 2's components, card fields and card groups
  plus structured data - `product_name`, `product_price`, `blog_title`, `hero_cta`,
  `review_author` and the rest - each with a confidence and readable evidence. A role it
  cannot justify is left `unclassified` rather than guessed.
- **Structured data read as a claim, never a fact.** JSON-LD is author-controlled and the
  most abused injection surface on the web, so it is parsed as bounded data, its URLs are
  validated, its secret-shaped keys are dropped, and every entity carries
  `verified: false`.
- **Destination providers behind a read-only contract.** The contract has eight methods
  and every one reads - there is no `update`, no `set_price`, no `save`. Writes go through
  exactly one path, which is what makes "AI must not modify products, prices, inventory
  or orders" structurally true rather than a promise.
- **Four structural mapping gates.** A source `product_price` cannot reach a title field
  even though both are strings, because the data types are checked. Name-only mapping is
  not merely discouraged, it is impossible.
- **Bulk entity matching** whose reported percentages are the ones the specification
  promised: 92% for a SKU match, 87% for title plus category, 64% for image similarity
  alone. A photograph can never produce an automatic match, because two products routinely
  share one. Ambiguity is an outcome, not a coin toss.
- **Mapping plans with a seal.** Every plan carries a digest over its source values and
  the validator recomputes it, so a value edited after planning - which the REST API
  would otherwise accept, because it accepts inline plans - is refused.
- **Apply with snapshot, verify and rollback.** Existing content is reported rather than
  overwritten, and a conflict fails the stage with the choices left to you.
- **Provenance and ownership** recorded as part of the apply, so a future sync knows a
  price is yours and must not be overwritten.

### What it does not do

No products are created, no prices or inventory are modified, no orders or customers are
read, nothing is published automatically, and no existing content is replaced without
your say-so. There is no admin UI yet - the REST layer exists and nothing renders it.

WooCommerce is optional. Without it, content still maps into WordPress posts and pages,
and the mapping screen says *why* rather than showing an empty list.

### Phase 14 documentation

| Document | Covers |
|---|---|
| [`docs/CONTENT-INTELLIGENCE.md`](docs/CONTENT-INTELLIGENCE.md) | The design record: every judgement call, the four gates, the anti-hallucination control, and the defects found. |
| [`docs/PHASE-14-COMPLETION-REPORT.md`](docs/PHASE-14-COMPLETION-REPORT.md) | What was built, the 21 defects execution found, acceptance criteria, and known limitations. |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | The dependency graph, and the existing systems Phase 14 reuses rather than duplicates. |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Untrusted structured data, the read-only contract, the source seal, and the AI-boundary redaction. |
| [`docs/API.md`](docs/API.md) | The 13 content routes, and why no route can name a destination. |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Why there are no new tables, and what is stored. |
| [`docs/JOBS.md`](docs/JOBS.md) | The six declared job types, and that they are not yet enqueued. |
| [`docs/PERFORMANCE.md`](docs/PERFORMANCE.md) | The bounds, and how 10,000+ products are handled without loading them. |