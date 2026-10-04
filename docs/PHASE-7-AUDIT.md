# Phase 7 Audit — Production Hardening

**Audit date:** 2026-09-26
**Audited version:** 0.6.0
**Method:** Every finding below was confirmed by executing the running plugin
through WordPress Studio's PHP 8.4.25, or by reading the exact code path named.
Nothing here is inferred from a file listing alone.

Environment used for the audit:

| Component | Value |
|---|---|
| WordPress | 7.1.2 |
| PHP | 8.4.25 |
| Elementor | 4.3.2 |
| `memory_limit` | 128M |
| `max_execution_time` | 0 (unlimited) |
| `WP_DEBUG` | false |
| Database | SQLite (drop-in) |

---

## 1. Architecture as it stands

91 PHP files, 12 REST routes, no custom database tables. Services are wired by
constructor injection from `Plugin::__construct()` and reach the database only
through WordPress options and transients.

```
replicaforge.php            bootstrap, 92 require_once calls
includes/
  class-replicaforge-security.php          shared policy, log_event()
  class-replicaforge-url-validator.php    SSRF policy
  class-replicaforge-http-client.php      bounded fetch
  class-replicaforge-analyzer.php         Phase 1 facade
  class-replicaforge-design-analyzer.php  Phase 2
  ai/                                     Phase 3
  elementor/                              Phase 4
  validation/                             Phase 5
  corrections/                            Phase 6
  class-replicaforge-rest-api.php         12 routes
  class-replicaforge-admin.php            admin screens
  class-replicaforge-plugin.php           wiring
admin/css, admin/js                       admin UI
tests/                                    4 contract suites, 418 assertions
docs/                                     6 documents
```

This is a good foundation and Phase 7 preserves it. The service graph is explicit,
there are no god classes, and no phase reaches into another's internals.

---

## 2. What is already strong

These were checked and are sound. Phase 7 does not rewrite them.

| Area | Evidence |
|---|---|
| SSRF policy | `Url_Validator` validates scheme, credentials, port (80/443 only), legacy IPv4 notations, dangerous hostnames, forbidden paths, and **every** resolved A/AAAA record. Redirects are followed manually so every hop is re-validated. |
| HTTP bounds | `Http_Client` sets `timeout`, `redirection => 0`, `limit_response_size`, `reject_unsafe_urls`, `sslverify`, `Accept-Encoding: identity`, and enforces `Content-Length` and `Content-Type`. |
| REST authentication | All 11 functional routes share `can_analyze()`: `is_user_logged_in()` **and** `current_user_can( 'manage_options' )` **and** `wp_verify_nonce( …, 'wp_rest' )`. |
| Output escaping | 0 unescaped variable echoes found by token-level scan. |
| Text domain | 699 translation calls, 0 with a wrong domain. |
| Secret handling | Phase 5 export tests assert no `Authorization` header and no `_elementor_data`; Phase 6 asserts no `Bearer` and no document body. |
| Prompt injection | Website content is wrapped in an explicit untrusted envelope with system instructions asserting no-invention; asserted by contract test. |
| Correction whitelist | `Correction_Property_Map` is the only path to a write; the browser sends ids, never values. |
| Draft safety | Generation and correction both refuse non-drafts; no code path publishes. |

---

## 3. Confirmed findings

Severity is judged by what a user would experience and what an attacker would gain.

### F-01 — Orphaned transients accumulate with no cleanup · **High**

252 `replicaforge_*` options on this site, 104 of them validation results:

```
replicaforge_validation_result_<id>   104
replicaforge_correction_plan_plan_*    47
replicaforge_correction_plan_snap_*    33
replicaforge_spec_spec_*                6
replicaforge_version                    1
```

**Zero** scheduled events exist and there is no uninstall routine, so nothing
removes them. Transients are only reaped by `delete_expired_transients` on cron or
on read. A site that never reads them again keeps them indefinitely. On a busy
install each entry holds a full validation record or document snapshot.

*Fix:* a scheduled maintenance task plus an explicit purge, both bounded.

### F-02 — No job abstraction; long work is bound to one HTTP request · **High**

Analysis, AI reconstruction, generation, and validation all run synchronously
inside the REST call. Measured in this environment, a real generate + validate +
apply round trip reaches 219 ms of applier time on a small fixture, but the same
path on a real site with 40 stylesheets and a render provider is unbounded.

Consequences today:
- closing the tab loses the work with no record that it ever started
- a PHP time limit mid-run leaves a partially generated document with no trace
- `MAX_RECORDS` style guards exist per phase but there is no job-level checkpoint

*Fix:* a job repository, a queue, a runner driven by WP-Cron, stage checkpoints,
idempotency keys, and bounded retry on transient failure only.

### F-03 — No idempotency; repeated clicks can create duplicate drafts · **High**

`Elementor_Draft_Service::create()` calls `wp_insert_post()` unconditionally.
Two rapid clicks on *Generate* produce two drafts. The REST layer has no lock and
no idempotency key.

*Fix:* an idempotency key derived from the request, held with a short TTL, plus a
per-post in-progress guard.

### F-04 — `POST /replicaforge/v1` namespace index has no permission callback · **Not a defect**

Registered with a `permission_callback` of `null`, so the route list is readable by
an unauthenticated visitor.

**This was investigated and found to be WordPress core behaviour, not a ReplicaForge
issue.** The index is registered by `WP_REST_Server::get_namespace_index()` for every
namespace that has routes. Verified by registering a namespace ReplicaForge knows
nothing about and observing the same result, and by confirming that `/wp/v2`,
`/wp-site-health/v1`, and `/oembed/1.0` behave identically:

```text
/replicaforge/v1        perm=NONE  callback=WP_REST_Server::get_namespace_index
/probe-namespace/v9     perm=NONE  callback=WP_REST_Server::get_namespace_index
/wp/v2                  perm=NONE  handler=WP_REST_Server::get_namespace_index
/oembed/1.0             perm=NONE  handler=WP_REST_Server::get_namespace_index
```

No plugin can attach a permission callback to it. The route discloses the list of
ReplicaForge route names, exactly as `/wp/v2` discloses WordPress's.

*Outcome:* no change made. Recorded in `docs/SECURITY.md` so it is not
rediscovered as a vulnerability.

### F-05 — 15 of 30 REST arguments have no `validate_callback` · **Medium**

The `args` schemas are declared but only sanitized, never validated. WordPress
cannot therefore reject a wrong-typed or out-of-range value before the callback
runs, so each callback must defend itself:

```
analyze          url!                     validate missing
ai/analyze       design_representation-   sanitize missing
generate         design_representation-  reconstruction_specification-  ...
validate         draft_id!-  visual!-  ai!-  force!-  viewports!-
corrections/*    selected-  design_representation!-  ...
```

`!` = no `validate_callback`, `-` = no `sanitize_callback`.

*Fix:* add `validate_callback` and `sanitize_callback` to every argument.

### F-06 — DNS rebinding window between validation and request · **Medium**

`Url_Validator` resolves DNS and confirms every record is public, then
`Http_Client` calls `wp_safe_remote_get()`, which resolves DNS again. A hostname
with a short TTL can answer public during validation and private during the
request. `reject_unsafe_urls` gives a second check inside WordPress, so this is
defence in depth rather than an open hole, but the window is real.

*Fix:* re-resolve and re-check immediately before each request through a
`pre_http_request` guard, and document the residual risk.

### F-07 — No uninstall routine · **Medium**

Nothing is registered, so deleting the plugin leaves every option and transient in
`wp_options`. Not a data-safety problem, but it is an unbounded-growth problem and
it leaves a mess.

*Fix:* `uninstall.php` that removes ReplicaForge options, transients, jobs, logs,
and caches, and explicitly never touches Elementor pages or user media.

### F-08 — No logging screen, no rotation · **Medium**

`Security::log_event()` writes through WordPress's own logger. There is no
ReplicaForge-visible log, no filtering, no export, and no rotation. A support
request cannot be answered without database access.

*Fix:* a bounded structured log with levels, categories, redaction, a viewer with
filter and export, and automatic rotation.

### F-09 — Errors reach the browser as technical codes · **Medium**

Callbacks return raw envelopes such as `request_timeout`, `http_request_failed`,
`plan_document_changed`. The admin UI maps some of them, but not all, and the REST
layer has no error classification at all.

*Fix:* a central catalog giving every code a category, severity, retryable flag,
and a message written for a person. Internal codes stay in the log.

### F-10 — No request correlation id · **Low**

Nothing ties a REST call to a validation, a generation, a correction, and a log
line together. Support has to match on timestamps.

*Fix:* a request id generated per REST call, attached to jobs and log entries and
returned in the response `meta`.

### F-11 — No centralized redaction before AI submission · **Medium**

Redaction is implemented (the Phase 3 test asserts secret-like text is redacted)
but it lives inside the AI context builder rather than in a shared service, so
there is no single place to extend when a new secret shape appears.

*Fix:* `class-replicaforge-data-redactor.php` as the only redactor, used by every
outbound path.

### F-12 — No feature flags · **Low**

`ai_enabled`-style switches are implied by settings but there is no controlled
mechanism for turning an unstable capability off in production.

*Fix:* a small flag service with defaults, a persisted override, and a
`WP_DEBUG`-gated developer view.

### F-13 — No schema or migration versioning · **Medium**

Only `replicaforge_version` is stored. Every phase schema version is a class
constant (`6.0`, `5.0`, `3.0`), so there is no way to detect that stored data was
written by an incompatible build and migrate it.

*Fix:* a central schema class, a version option, and a migrator that runs on
`admin_init` and logs what it did.

### F-14 — Admin surface has no dashboard, history, status, or log screen · **Medium**

The plugin exposes analysis, validation, and corrections. There is no dashboard, no
project history, no system status, and no log viewer, so a user cannot answer
"what did I build, and is this install healthy?".

*Fix:* add Dashboard, History, System Status, and Logs screens. Do not duplicate
functionality the existing screens already provide.

### F-15 — Unescaped admin output is possible in principle · **Low**

`esc_html` 30, `esc_attr` 10, `wp_kses` 1. The token scan found no unescaped
`echo $var`, so nothing is currently wrong, but attribute output is thinly covered
and the count suggests hand-written escaping rather than a convention.

*Fix:* audit the admin templates for attribute contexts and normalize.

### F-16 — Accessibility and mobile admin are unverified · **Medium**

The CSS is 18 KB and there is no evidence of focus states, ARIA, reduced-motion
support, or a small-screen layout for the correction tables.

*Fix:* audit and add focus-visible styles, ARIA on the progress and table markup,
a reduced-motion block, and a card layout below 782px.

---

## 4. Non-findings

Checked and deliberately not changed:

- **No custom database tables.** Correct for this plugin; everything is bounded
  option and transient data. Introducing tables would add migration risk for no
  benefit at this scale.
- **No telemetry.** Confirmed absent. Phase 7 introduces none.
- **Synchronous analysis is not removed.** It becomes a job, but the code path is
  the same, so there is one implementation to reason about rather than two.
- **`render_page()` (332 lines) and `enqueue_assets()` (284 lines)** stay. They are
  declarative markup and asset tables, matching the established pattern.

---

## 5. Work order for Phase 7

Ordered so each step is verifiable before the next depends on it.

1. Error catalog, request context, logger, redactor — the shared foundation.
2. Schema version and migrator.
3. Job repository, queue, runner, idempotency.
4. Scheduled maintenance and the cleanup that fixes F-01.
5. Uninstall routine.
6. REST: argument validation, error envelopes, request id, job routes.
7. System status, feature flags.
8. Admin: dashboard, history, status, logs, progress, accessibility, responsive.
9. Security test suite covering the SSRF, XSS, SQL, capability, and nonce vectors.
10. Documentation, release checklist, completion report.

---

## 6. Verification method for Phase 7

The contract suites are executable. Phase 7 keeps that property and adds to it.

```
php -l                                111 files, 0 failures
tests/phase3-contract-test.php        13 assertions
tests/phase4-contract-test.php        58 assertions
tests/phase5-contract-test.php      146 assertions
tests/phase6-contract-test.php      201 assertions
tests/security-contract-test.php     132 assertions
tests/jobs-contract-test.php         122 assertions
tests/maintenance-contract-test.php  123 assertions
tests/admin-contract-test.php         66 assertions
tests/lifecycle-contract-test.php      47 assertions
```

Total: **9 suites, 908 assertions, 0 failures.**

A Phase 7 claim is only made if a test asserts it. Findings F-01 through F-16 each
gain a test or a status check that fails if the problem returns. The outcomes are
tabulated in `docs/PHASE-7-COMPLETION-REPORT.md`.

### What the verification method did not catch

`php -l` accepts a call that passes a literal to a by-reference parameter, which is
a runtime fatal. It also accepts a logic error in a validator or a wrong key in a
loop. The Phase 7 defects listed in the completion report — the cookie redaction
gap, the unknown error code inheriting a retryable flag, `validate_post_id_param`
accepting a float, the payload delete query missing its prefix — were all found by
**executing** the code, not by reading it.
