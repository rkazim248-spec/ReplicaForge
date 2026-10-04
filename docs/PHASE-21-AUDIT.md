# Phase 21 Audit — Findings, Method, and Limits

**Status: partial.** This document records the first slice of Phase 21: the audit
framework, the whole-tree static analysis, the findings registry, and the release gate. It
does **not** claim Phase 21 is complete. Section 6 lists what has not been done.

Plugin version 1.5.0 · WordPress 7.1.2 · PHP 8.4.25 · MySQL 8.0.38 · single site

---

## 1. What this audit is

Phase 7's audit was a document. This one is a registry, and the difference matters.

A prose audit goes stale the day after it is written and nobody notices until a regression
reopens a finding. `Security_Audit` stores findings as data, and §72 of Phase 21 requires
every fixed finding to name a test that would fail if the fix were reverted. The registry
enforces that at write time:

- a finding cannot be marked `fixed` without naming a test;
- a finding cannot be marked `accepted` without a written reason;
- `tests/phase21-security-test.php` fails if any `fixed` finding names a test file that does
  not exist, **or** a test file that never mentions the finding.

That last rule is the one that closes the loophole. A test that exists but does not refer to
the finding satisfies §72 on paper — and a later refactor of the suite could drop the
assertion that guarded it without anything noticing.

## 2. Method

Every conclusion below is one of two things:

1. **Measured.** Behaviour was executed and the output recorded. RF21-001 below is an example
   where measurement contradicted the reading of the source.
2. **Verified by reading.** The code was traced to its origin by hand. Section 4 names what
   was traced and how.

Nothing in this document is inferred from a pattern match alone. Where a scanner said
something, the finding was confirmed by reading the code before it was filed.

The static analysis walks **every** non-test PHP file in the plugin — 266 files — with
comments and string literals stripped first. Stripping matters: without it a docblock saying
"never call `eval`" reads as a call to `eval`, and a check that cries wolf gets ignored.

## 3. Findings

Eleven findings are registered in `Security_Audit::baseline()`.

| ID | Severity | Status | Summary |
|---|---|---|---|
| RF21-001 | High | fixed | A scope string was passed as `permission_callback`, so no scope check ran |
| RF21-002 | Medium | fixed | A migration reported success for a table it never created |
| RF21-003 | Medium | fixed | Recorded events could not be read back |
| RF21-004 | Low | fixed | Identified columns are interpolated into SQL without backticks |
| RF21-005 | High | fixed | A child store method silently changed the meaning of its parent |
| RF21-006 | High | fixed | Seven assertions across six suites were short-circuited with `true` |
| RF21-007 | Low | fixed | The webhook failure hook passed `null` where it promised a delivery |
| RF21-008 | Informational | accepted | No code-execution primitive is reachable from plugin code |
| RF21-009 | Informational | accepted | No table stores a credential or a signing secret |
| RF21-010 | Informational | accepted | No variable callable is taken from a request |
| RF21-011 | Medium | accepted | The AI provider key is stored in plaintext in a WordPress option |

### 3.1 RF21-001 — where measurement contradicted the obvious reading

Phase 20 registered 22 developer-API routes with the route's scope — a string such as
`replicaforge:read` — supplied as the `permission_callback`. Read at a glance this looks like
an authorization bypass: 22 routes with no scope check.

It is not a bypass. Measured against WordPress 7.1.2:

```
Fatal error: Uncaught TypeError: call_user_func(): Argument #1 ($callback) must be a valid
callback, function "replicaforge:read" not found or invalid function name
  wp-includes/rest-api/class-wp-rest-server.php:1260
```

`WP_REST_Server` calls `call_user_func()` on the handler **unconditionally** — there is no
`is_callable()` guard at `class-wp-rest-server.php:1260`. A non-callable string therefore
raises a `TypeError` and the request fatals.

The routes were never open. The actual impact is an unauthenticated denial of service on
those endpoints, plus disclosure of absolute file paths in the stack trace whenever
`display_errors` is on. Filed as high for availability, which is what it is, rather than as a
bypass, which it is not.

This is the clearest argument in the audit for measuring. The plausible reading of the source
was wrong, and had it been filed as filed-as-severity, the record would have overstated the
defect by an order of magnitude.

### 3.2 RF21-002 — a control that reported success without checking

`Collaboration_Schema::install()` treated a non-empty `dbDelta()` return as proof the schema
was created, and only checked table existence when `dbDelta` returned nothing.

`dbDelta` reports success in its return value while silently skipping a table it cannot
reconcile. The automations table was skipped because its column was named `trigger`, a MySQL
reserved word — and `install()` returned true anyway.

So the branch that would have caught the failure was the branch that did not run. This is the
finding behind RF21-002, not the reserved word itself: a reserved word is a naming mistake,
but an integrity control giving a false answer is what makes it invisible.

### 3.3 RF21-006 — a passing suite that could not fail

Seven assertions across phases 11, 12, 13, 14, 16, 17 and 19 ended in `|| true`, which makes
them unconditionally true. They were reported as passing coverage while testing nothing.

Removing the short-circuits immediately produced two failures. **Neither was a product
defect**, and both are worth recording:

- **phase14** asserted `Content_Validator` would refuse a plan naming an unknown destination
  field. Reading the validator, it never inspects `destination.field` at all — and correctly
  so, because it holds no provider instance and *cannot* know whether `meta:api_key` exists.
  Only `Content_Applier`, which holds the provider, can decide. The assertion was aimed at a
  layer that architecturally cannot enforce it.
- **phase16** asserted six attributes were harvested from a fixture containing **none** of
  them. The markup had `aria-selected`, `data-ride` and `tabindex`; the assertions checked for
  `aria-expanded`, `role`, `data-toggle`, `required`, `aria-controls` and `aria-haspopup`.

So the short-circuits were hiding broken tests rather than broken code. That is still the
finding: a suite that cannot fail cannot notice a security regression in the areas those
assertions covered, which is the same as an unfixed vulnerability. Both assertions were
rewritten to test the claim actually enforced, and section 1 of the Phase 21 suite now fails
if any suite reintroduces a short-circuit.

The check proves its own pattern is reachable — it asserts it matches a bare `true` and
declines to match `true === $x`, including when the comparison wraps onto the next line.

### 3.4 RF21-011 — accepted with a reason

The AI provider key is held in `replicaforge_ai_settings` as plaintext.

This is recorded as `accepted` rather than `open` because the exposure is inherent to the
host platform, not a defect in this code. WordPress offers nowhere else to put a secret that
a REST surface must read on every request, and encrypting it with a key stored in the same
database would make it no safer. `uninstall.php` deletes the option, so the key does not
outlive the plugin.

The honest options are a WordPress-native secret abstraction, which this environment does not
provide, or a defined constant in `wp-config.php`, which moves the problem rather than solving
it. Both are worse than the current state for most operators, and neither is this phase's
decision to make. An operator who wants the key out of the database should use a `wp-config`
constant, and the plugin reads that first.

## 4. Verified controls

Recorded as `informational` on purpose. An audit that only lists problems cannot be used to
argue that something is safe, and the next audit would have to re-derive all of it.

### 4.1 No code-execution surface

A whole-tree scan of 266 files finds **no** call to: `eval`, `assert`, `exec`, `shell_exec`,
`system`, `passthru`, `proc_open`, `popen`, `proc_close`, `create_function`, `unserialize`,
`maybe_unserialize`, or `extract()`. No `preg_replace` uses the removed `/e` modifier. No
`include` or `require` takes a filename from a variable.

One caution about the `/e` check, because it is easy to get wrong. A naive pattern such as
`preg_replace\(\s*['"]/[a-z]*e` false-positives on `'/expression\s*\([^)]*\)/i'` — which is a
*sanitiser*, stripping CSS `expression()`. The check anchors on the closing quote so that `e`
must be the last modifier, which is the only position that evaluates.

Likewise, a naive `extract\s*\(` reports eleven hits, all of them the plugin's own methods
named `extract` — `Design_Extractor::extract()`, `Template_Extractor::extract()`,
`Visual_Features::extract()`, `Project_Context_Store::extract()` and calls to them. A method
declaration is preceded by a **space**, so a lookbehind excluding `[$>_]` never applied to the
case that most needed it.

Both of these were caught because the test asserted that its own pattern could match and could
decline to match. A static check that cannot fail is not a control.

### 4.2 No request-sourced callable

`call_user_func()` with a request-supplied callable would be remote code execution. There are
11 sites across 7 files, and each was traced to its origin:

| File | Site | Why it is safe |
|---|---|---|
| `migrator.php` | `$migration['run']` | internal migration table, `is_callable()` guarded |
| `woocommerce-provider.php` | `$active`, `$version`, `$products`, `$terms` | injected map, `is_callable()` guarded |
| `interaction-detector.php` | `$rule['test']` | internal rule table, wrapped in `try/catch` |
| `job-lock.php` | `$callback` | `callable` parameter type |
| `capability-registry.php` | `$probe` | internal probe map, `callable` type |
| `workflow-executor.php` | `$factory` | `$this->services`, built in the constructor |
| `usage-manager.php` | `$callback` | `callable` parameter type |

The two largest groups were traced furthest:

- **`WooCommerce_Provider`** — its `api` map is populated only from `Content_Service:129`, and
  `set_api()` accepts five named keys behind `is_callable()`. `Content_Service` is constructed
  in exactly two places: the plugin's own service graph, and a hardcoded literal in
  `Content_Api`. Neither passes `woocommerce_api` from a request.
- **`Interaction_Detector`** — its rule table is built in code, and the dispatch is wrapped in
  `try/catch`, so a throwing rule is contained.

An earlier version of this check asserted every such file contains `is_callable()`. Five do
not, and all five are correct — a `callable` parameter type is a *stronger* guarantee than an
`is_callable()` test, because PHP enforces it at call time. The assertion now accepts either,
and the suite prints the inventory so the justification for each site is visible rather than
assumed.

### 4.3 No secret in any column

`api_credentials` has no `token` column. An API token is stored as a SHA-256 hash, which is
what the authenticator compares against. `webhooks` has no `secret` column at all — the
signing secret is derived on demand as
`HMAC( Secure_Token::salt(), 'replicaforge-webhook|' . public_id )`.

A database read — by an operator, a backup, or an attacker who reaches the database — yields
nothing that can be replayed.

This is asserted against the **live** column list read from `information_schema`, not by
grepping the schema class, because the schema is the thing that matters: a column added by a
later migration would not show up in a source grep.

The dependency worth recording: the derivation depends on `Secure_Token::salt()`. If that value
is ever rotated, every webhook signature changes and every endpoint must be re-registered.

## 5. The release gate

`Security_Audit::gate()` implements §53. The decision most likely to annoy somebody is
deliberate:

> **A gate nobody ran is not a pass.** It reports `incomplete`.

A release that never ran the SSRF suite has demonstrated nothing about SSRF. A gate that
treats "not run" as "passed" is decoration. Three outcomes are distinguished: `pass`,
`fail`, and `incomplete`, and only the first releases the build.

A release also fails on any unresolved `critical` or `high` finding, or on any of the eight
named gates reported failing by a test run.

## 6. What this audit has not done

Phase 21 is not complete. Not started:

- **SSRF, XSS and authorization deep dives.** Section 4.2 traced every callable and section
  4.3 checked the secret columns, but that is not a review of the request path, the escaping
  path, or the authorization decision on each route.
- **The fixture library** (§– shared hostile fixtures) and the **E2E suite**.
- **The security dashboard** (§52). The registry exists; no screen reads it.
- **The threat model** (§76) and the **privacy inventory** across 20 phases of code.
- **Compliance documentation.**

The threat model in particular cannot be written honestly from what exists. It requires
reading how every phase handles data — what is stored, what is sent to a model, what is
written to a log, what is retained and for how long. That is a multi-session effort across
the whole codebase, and pretending otherwise would produce a document that reads complete and
is not.

## 7. Verification

```
suites: 28, failed: 0, assertions passed: 6697, skipped: 2
```

The two skips are pre-existing (`phase5` and `admin`), not introduced by this work.

`phase21-security-test.php` contributes 64 of those assertions across ten sections. Every
number in this document comes from a run. No finding is described from inference about the
code that was not confirmed by executing it or by reading it to its origin.