# ReplicaForge Security

This document describes what ReplicaForge defends against, how, and where the
limits are. Every claim here is asserted by a test in `tests/security-contract-test.php`
or by executing the code path against the running plugin.

## 1. The trust model

ReplicaForge treats three kinds of input as hostile, and each has its own boundary.

| Input | Trust | Boundary |
|---|---|---|
| A website's HTML, CSS, and headers | Untrusted | Never executed. Parsed only. |
| An AI provider's response | Untrusted | Schema-validated. Never executed, never stored unvalidated. |
| The browser | Authenticated but untrusted | Sends identifiers, never document data. |
| The site administrator | Trusted | `manage_options` plus a nonce. |

## 2. SSRF

The analyzer fetches URLs supplied by a user, which is the classic server-side
request forgery surface. `Url_Validator` refuses a destination unless every check
passes.

**Scheme.** Only `http` and `https`. `file:`, `ftp:`, `gopher:`, `javascript:`,
`data:`, and everything else are refused.

**Credentials.** A URL containing a username or password is refused, because
sending them to a third party discloses them.

**Port.** 80 and 443 only. A default port is normalized away so the same page has
one canonical form.

**Host.** Dangerous hostnames are refused by name: `localhost`,
`metadata.google.internal`, and anything ending in `.internal`, `.local`, or
`.localdomain`.

**IP.** Every resolved A and AAAA record is checked, and one private record fails
the whole resolution. Refused ranges include loopback, `0.0.0.0`, RFC 1918,
carrier-grade NAT, link-local (including `169.254.169.254`), unique-local and
link-local IPv6, IPv4-mapped and IPv4-compatible IPv6, multicast, reserved, and
`240.0.0.0/4`. Legacy notations are normalized first, so `2130706433`,
`0x7f000001`, and `0177.0.0.1` are all recognized as `127.0.0.1` and refused.

**Path.** Administrative paths are refused, so a URL that is nominally a webpage
cannot be used to probe a control panel.

**Redirects.** `Http_Client` follows redirects itself, one hop at a time, with
`redirection => 0` passed to the HTTP API. Every hop is re-validated in full. A
redirect count, a loop, and a malformed `Location` header are each a distinct
refusal. A redirect to a private address stops the fetch.

**Assertion.** 27 destinations in `security-contract-test.php` are confirmed
refused, and 7 redirect targets are confirmed blocked by the destination policy
after resolution.

### Residual risk: DNS rebinding

`Url_Validator` resolves DNS and confirms every record is public.
`wp_safe_remote_get()` then resolves DNS again. A hostname with a very short TTL
could answer publicly during validation and privately during the request.

WordPress's `reject_unsafe_urls` re-validates inside the HTTP API, so there are
two independent checks. The window between them is not zero.

Closing it fully would require pinning the resolved address, which breaks TLS
hostname verification. ReplicaForge does not do that. This risk is recorded rather
than claimed away.

## 3. Request bounds

Every outbound request sets `timeout`, `redirection => 0`,
`limit_response_size`, `reject_unsafe_urls`, `sslverify`, and
`Accept-Encoding: identity`. A shared deadline is enforced across all hops, so a
chain of redirects cannot multiply the time budget.

`Content-Length` is checked against the cap before the body is read, and the
actual body length is checked again afterwards. A `Content-Type` outside the
accepted set is refused. Extensions are never trusted; the response type is.

## 4. Injection

**PHP, JavaScript, and shell.** ReplicaForge never executes website content, AI
output, or any submitted string. There is no `eval`, no `unserialize` of external
data, no `shell_exec`, no `include` of a user-controlled path. The security test
scans the source for a query built by concatenating a value and finds none.

**SQL.** ReplicaForge has no custom tables and issues no queries with interpolated
values. The three queries that exist (transient and post-meta scans) use
`$wpdb->prepare()` with `$wpdb->esc_like()`. The test scans every PHP file for the
concatenation shape and for a placeholder used without `prepare`.

**HTML.** A token-level scan of every `echo` found no unescaped variable. The
admin test then renders each new screen with a hostile job record and confirms no
executable markup reaches the output. Escaping happens twice, independently:
`Data_Redactor` removes markup when a record is written, and the output layer
escapes again.

**Prompt injection.** Website content is wrapped in an explicit untrusted envelope
with system instructions asserting that it is data. It is never concatenated into
an instruction position. See `docs/AI.md`.

## 5. Authorization

Every functional REST route requires all three of:

1. `is_user_logged_in()`
2. `current_user_can( 'manage_options' )`
3. `wp_verify_nonce( $nonce, 'wp_rest' )`

The Phase 7 routes use an equivalent `can_manage()`. Every `admin_post` handler
requires `current_user_can( 'manage_options' )` and `check_admin_referer()`.

The permission check is a capability, never authentication alone. A subscriber with
a valid session and a valid nonce is refused, which the test asserts.

### The namespace index

WordPress registers an index route for every REST namespace with a `null`
permission callback, including core namespaces. ReplicaForge's index behaves
exactly as `/wp/v2` does, and no plugin can attach a callback to it. It discloses
the list of ReplicaForge route names to an unauthenticated visitor. This is core
behaviour, not a ReplicaForge defect, and it is verified by registering a
namespace the plugin knows nothing about and observing the same result.

## 6. The correction write path

The most sensitive operation in the plugin is writing an Elementor document.

- The browser submits a **plan id** and a list of **correction ids**. Never values,
  never a document, never Elementor JSON.
- `Correction_Property_Map` declares 24 writable properties. Each entry names the
  control, the value shape, the device policy, the level, and the batch. Nothing
  outside that map is writable.
- Every value is re-derived through `Elementor_Values`. A value that cannot be
  coerced is refused rather than approximated.
- A property with no control on the resolved element type is `blocked`, never
  `requires_review`. Offering a change the apply step can only refuse is promising
  and not delivering.
- Device corrections resolve to Elementor's real storage: desktop in `settings`,
  device overrides in the `_elementor_responsive` post meta keyed by element id.
  `read_control()` and `write_control()` are the only code that decides which map a
  value belongs in.

## 7. Secrets

An API key must never appear in frontend JavaScript, HTML, a REST response, browser
storage, a cookie, post content, a log, or an error message.

| Channel | Control |
|---|---|
| Storage | `Ai_Settings` stores the key in an option; only a boolean "is configured" reaches the client. |
| Frontend | `get_public_settings()` returns provider and model, never the key. |
| REST | No route returns configuration values. |
| JavaScript | The admin config is encoded with `JSON_HEX_TAG`, `JSON_HEX_AMP`, `JSON_HEX_APOS`, and `JSON_HEX_QUOT`. |
| Log | Every entry passes through `Data_Redactor` before it is written, and again on export. |
| Jobs | Job parameters and checkpoints are redacted at rest. |
| Errors | The catalog returns a message and a code. The internal code, category, and technical detail stay in the log. |
| Diagnostics | The export endpoint redacts again on the way out, so an entry captured before the redactor existed is still not exported. |

The redactor handles authorization and proxy headers, bearer and basic tokens,
OpenAI / Anthropic / Google / GitHub / AWS / Slack / GitLab key shapes, generic
`key = value` assignments for secret names, WordPress and session cookies,
credentials embedded in a URL, private key blocks, server filesystem paths, and
database DSNs. A cookie name with a hash suffix (`wordpress_logged_in_abc123=`)
is matched, which the bare-word form was not.

## 8. Resource exhaustion

| Vector | Control |
|---|---|
| Huge HTML | Byte cap on the response, enforced before and after the read |
| Huge CSS | Per-stylesheet cap, stylesheet count cap |
| Deep DOM | Depth cap; parsing stops and reports truncation |
| Many elements | Per-phase maximums; a limit reports partial analysis rather than fataling |
| Oversized REST body | 2 MB cap on the representation and specification arguments |
| Viewport abuse | Dimensions bounded to 240–4096, so an override cannot make a renderer allocate an enormous image |
| Viewport count | Capped at the number of known viewports |
| Correction list | Capped at 200 identifiers |
| Log growth | 500 entries default, 5000 hard ceiling, age-based pruning |
| Job growth | 100 records; active jobs are never dropped |
| Checkpoint growth | Bulky values live in a bounded transient, not in the job record |
| Redaction cost | Depth and node budget, so a hostile structure cannot turn redaction into a denial of service |
| Log payload | Message and context truncated to 2000 characters |

## 9. Audit checklist

| Item | Status | Evidence |
|---|---|---|
| SQL injection | Not present | No concatenated query; source scan in the security test |
| XSS | Not present | Token scan plus rendered-screen assertions |
| CSRF | Not present | Nonce on every state-changing route and admin action |
| SSRF | Mitigated | 27 destinations, 7 redirects, manual redirect following |
| Privilege escalation | Not present | `manage_options` on every privileged path; subscriber refused |
| Broken access control | Not present | Per-job nonce; job id shape validated |
| Unsafe deserialization | Not present | No `unserialize` of external data |
| Arbitrary file upload | Not present | Asset import is off by default and fetches only validated image types |
| Path traversal | Not present | No filesystem path comes from a request |
| Command injection | Not present | No process execution |
| PHP code execution | Not present | Website and AI content is never executed |
| JavaScript injection | Not present | Server data is escaped or hex-encoded; never inserted as HTML |
| HTML injection | Not present | Output escaping, plus markup neutralization at rest |
| XXE | Not applicable | No XML parser |
| Remote file inclusion | Not present | Every include is a literal path in the bootstrap |
| Unsafe redirects | Mitigated | Each hop re-validated; the HTTP API is told not to follow redirects |
| Secret leakage | Not present | Redactor on every channel; asserted by test |
| API key leakage | Not present | Never leaves the server |
| Prompt injection | Mitigated | Content is data in an explicit envelope; see `docs/AI.md` |
| Malicious AI output | Mitigated | Schema-validated; never executed; discarded on mismatch |
| Race conditions | Mitigated | Job leases with expiry; idempotency keys |
| Resource exhaustion | Mitigated | Documented caps, all asserted |
| Denial of service | Partially mitigated | Caps bound a single request; a determined client can still spend server CPU. Rate limiting at the server or WAF level is the deployment's responsibility. |

## 10. Reporting

A security problem should be reported to the plugin author with the request id from
the log entry, the ReplicaForge version from **System Status**, and the WordPress,
PHP, and Elementor versions. **System Status → Logs → Export log** produces a
redacted diagnostic file suitable for attaching.

---

## Phase 10: the commercial security layer

Phase 10 introduced commercially sensitive functionality, so this section covers
what the new layer enforces and — more usefully — what it deliberately does not.

### Five capabilities, and why they are new

Before Phase 10 the plugin had **no capabilities of its own**. Every check was
either `manage_options` (administrator only, so the plugin was administrator-only
by accident rather than by decision) or `edit_pages` / `edit_post` (which says
nothing about whether a person may run a reconstruction). There was no way to
express "may use ReplicaForge" as distinct from "may administer WordPress", which
is why the multi-user requirement could not be satisfied before this phase.

| Capability | Administrator | Editor | Grants |
|---|---|---|---|
| `replicaforge_use` | ✓ | ✓ | Analyses, AI, validation, sync, export. |
| `replicaforge_generate` | ✓ | ✓ | Generation, corrections, import. |
| `replicaforge_manage_projects` | ✓ | | Any project, not only their own. |
| `replicaforge_manage_settings` | ✓ | | Settings. |
| `replicaforge_manage_plans` | ✓ | | Plans, licensing, trials. |

Generation is separate from use on purpose: a site owner may want editors to
analyse and validate while an experienced person handles the step that writes an
Elementor document.

**Authors and contributors get nothing.** A replica is an Elementor page, and an
author who cannot edit pages cannot usefully generate one.

Grants are applied to **roles**, not users, because roles are what an installation
already administers. Granting to individual users would leave a set of users
holding a capability no role explains, and a capability that appears in nobody's
role list cannot be audited in the WordPress UI.

### Ownership

`Project_Access` is the rule: the owner sees a project, and a user holding
`replicaforge_manage_projects` sees it because they are responsible for the site.
Nobody else does — not other authors, not other editors, and not a user with a
forged project id.

Every method takes the user id **explicitly** rather than reading
`get_current_user_id()`. A check that reads the ambient user cannot be tested for a
*different* user, and testing "user B cannot read user A's project" is the whole
point of having the class.

The refusal does not distinguish "not yours" from "does not exist". Distinguishing
them turns the endpoint into an oracle for enumerating project ids, and project ids
are short enough to enumerate. Both produce `404 project_not_available` with the
same wording.

### The gate's order is a security property

`Entitlement_Manager::check()` runs authentication → capability → ownership →
feature → limit, in that order, and the order is not a style choice:

- **Ownership before entitlement** so a user cannot learn anything about a plan by
  asking about somebody else's project.
- **Entitlement before the limit** so a user whose plan does not include a feature
  is told the feature is unavailable, rather than being sent to an upgrade page
  when no upgrade would help.

An unrecognised operation is **refused**, not allowed. A typo in a call site must
fail closed, or a new endpoint would be usable by default until somebody noticed it
had no gate.

### Usage cannot be forged

There is no REST argument, and no code path, that sets a usage count. The only way
usage changes is `Usage_Manager::charge()` or `Usage_Manager::commit()`, both called
server-side after work completed. A request that includes `used`, `limit`, or
`plan_id` in a body is simply not read.

A user is charged for work that **succeeded**. A button click charges nothing, and
a failed run charges nothing. A reservation that is never settled expires on its
own after two hours and is swept daily, so a job whose worker died does not
consume the rest of the month.

### Concurrency

Ten simultaneous requests against a limit of two must not start ten generations.
Every read-modify-write of a counter happens inside a lock taken with
`add_option()`, which is a single `INSERT` against a unique column. A lock that
cannot be taken is a `409`, not a limit error — the user has not run out of
allowance, they asked at the same moment as somebody else.

**Known limitation:** takeover of an expired lock is a get/delete/add sequence, so
two processes arriving together can both attempt it and one loses. The consequence
is a counter overdrawn by one, which is the safe direction. A single atomic
statement would need a table.

### No arbitrary Elementor data from a browser

No Phase 10 route takes a property name and a value. No Phase 10 file mentions
`Elementor_Document_Writer`, `Elementor_Document_Reader`, `Correction_Applier`,
`_elementor_data`, `update_post_meta`, or `wp_update_post` — asserted by the test
suite, which reads the Phase 10 sources and fails if any of them does.

Phase 6 remains the only writer of an Elementor document, and its snapshot,
ownership, and manual-edit protections are untouched.

### Imported configuration cannot execute

An exported plan document carries five keys per plan. **Every imported value is
rebuilt through `Plan_Definition`, which drops unknown keys.** There is no
`eval`, no `include`, no `call_user_func` on imported data, and no template
rendering of it. A whitelist of keys is stronger than trying to sanitise an unknown
shape.

### The audit log cannot leak

`Audit_Log::EVENTS` is a closed list. An undeclared event is dropped **and logged
as a warning**, because a typo'd event name silently disappearing would be exactly
the failure the class exists to prevent.

Per event, the context keys are declared. Values that are not scalar are dropped —
a nested structure is where a large value would hide. A value containing markup is
dropped rather than escaped, because markup in an audit entry means the value came
from something the plugin did not generate. Keys matching `pass`, `secret`,
`token`, `key`, `auth`, `cookie`, `credential`, `signature`, `nonce`, `html`,
`body`, `prompt`, or `response` are never recorded, whatever the caller passes.

### No fake commerce

- No payment processing, and no path to any. `Billing_Provider_Contract` has no
  method with a side effect.
- No license key validation and no simulated activation.
- With no billing provider connected, every upgrade prompt **says so** and links
  to the plan details page rather than to a purchase button that goes nowhere.
- No fake scarcity, no fake countdowns, and no invented subscription status. The
  test suite asserts the absence of those strings in the lock notice.
- The local licensing provider records `verification: none_local_record` and the
  diagnostics report `verified: false`, so an administrator can always tell "a
  licensing server said this" from "somebody typed this".

### What Phase 10 does **not** enforce

Being explicit, because a security document that only lists wins is not useful:

- **The Phase 1–9 REST endpoints are not gated by `EntitlementManager`.** They keep
  their `manage_options` / `edit_pages` checks. Calling `begin()` and `settle()`
  from those endpoints is Phase 11 work; doing it halfway would be worse than not
  doing it, because a partially metered plugin has a quota users cannot reason
  about.
- **`Project_Repository` is still not wired to the admin or REST layers.** It was
  built in Phase 8 and is tested, but nothing calls it except tests. `Project_Access`
  therefore has no live endpoint to enforce at yet. This is the single most
  important thing to fix next.
- **No admin screens exist** to render the gates, so there is nothing a user could
  click that the server would refuse. The server-side gate is complete; the
  presentation is not.
- **The lock mechanism is single-site.** On multisite, `add_option()` is
  per-blog, so two blogs of a network can each grant the same reservation for the
  same user. Multisite is untested throughout Phases 1–10.

---

## Phase 11: reliability and AI cost control

### Nothing was weakened

Nothing in Phase 11 touches a security boundary. It is asserted, not claimed:

- **No Phase 11 file calls a post write, an Elementor document write, or the Phase 6
  applier.** The test reads all eleven new sources with comments stripped and fails on
  `Elementor_Document_Writer`, `_elementor_data`, `wp_update_post`, `update_post_meta`,
  or `Correction_Applier`. Phase 6 is still the only writer of a document.
- **SSRF is unchanged.** `Url_Validator::validate()` is asserted directly for loopback,
  the cloud metadata address, two private ranges, and a non-http scheme — and for an
  accepted public page, so the refusals are refusals rather than a broken validator.
- **The resource lock is the one new mechanism, and it is not attacker-controlled.**
  The resource name is reduced to `[a-z0-9_:-]` and length-capped before it becomes an
  option name, and the owner is reduced to `[A-Za-z0-9_:-]`. A job id is not a URL, and
  a browser never supplies a lock name.

### Job payload security (§57)

`Job_Manager::admit()` takes a job type, a user id, and a project id. Everything else
in the job's parameters comes from the server. The queue's idempotency key is derived
server-side from the type and the parameters, and `Job_Repository` never accepts a
job record from a request body.

Ownership is enforced at **admission**, not only at execution:

```php
$access = $this->entitlements->access()->refusal( $user_id, $project_id );
```

so a user cannot queue work against a project they do not own and discover the
project's existence from the eventual failure. The refusal does not distinguish
"not yours" from "does not exist", so the endpoint is not an oracle for enumerating
project ids.

### Usage cannot be double-charged

A duplicate admission releases its reservation rather than settling it. The work is
already accounted to the request that started it, and charging twice for one job is a
bug a user would notice immediately.

A **refused** admission — too many active jobs — also releases its reservation. The
suite asserts a refused admission charges nothing, because charging a user for a job
that was never queued is exactly the accounting bug this is guarding against.

### Provider secrets

Three properties, all asserted against a fixture containing
`Authorization: Bearer sk-live-AAAABBBBCCCCDDDDEEEEFFFF`:

- the **raw provider body is never carried** in a failure record — it is used only to
  choose a code, never to build a sentence;
- a key-shaped string or a 32+ character token is redacted before it can reach a
  technical message, and therefore before it can reach a log file;
- a **user-facing** message names neither the provider nor the endpoint, and does not
  quote the body.

### Context is data, never instruction

Injected text is carried through as text — a reconstruction needs the page's real
content — but it lands **inside** an explicit `<REPLICAForge_DATA>` region, and there
is no code path that treats context text as an instruction. The safety property is
structural: no `eval`, no `include`, no shell, no template interpolation.

A repair prompt quotes the model's own previous output back to it, which is the one
place ReplicaForge sends text it did not build. It is redacted, and visibly so.

### Locks are not a denial-of-service surface

A lock is created with `add_option()`, so exactly one concurrent process can take it.
A failed acquisition is a **409**, not a limit error — a user has not run out of
allowance, they asked at the same moment as somebody else, and reporting that as a
limit would send them to an upgrade page for no reason.

A lock whose holder died is taken over once it expires, so one crashed worker cannot
stop a project. A takeover records the previous holder, and three takeovers on one
resource is logged as a warning, because that pattern means a worker is being killed
mid-stage rather than a single crash.

### What Phase 11 does *not* enforce

- **No admin screens**, so there is nothing a user could click that the server would
  refuse. The server-side gate is complete; the presentation is not.
- **The Phase 1–9 REST endpoints are not metered.** `Job_Manager::admit()` gates
  admission to the *queue*; a direct call to `/analyze` or `/generate` bypasses it.
  Wiring `Entitlement_Manager` into those endpoints is the next integration step, and
  doing it halfway would be worse than not doing it.
- **`Job_Runner::process()` ignores the time budget** it is given, so the budget
  currently stops a tick between jobs rather than between stages.
- **No provider fallback.** `fallback_worthy` classifies when a fallback would be
  justified and nothing acts on it — building a switcher now would mean shipping a
  code path that can silently move a request to a different vendor, which §39 forbids
  by default.
- **Multisite is untested.** The lock is a per-blog option, so two blogs of a network
  can each hold a lock for the same user.

---

## Phase 12: crawl scope and website ownership

### The threat model for discovery

A crawler is a request amplifier pointed at someone else's server, and the pages it
fetches are attacker-controlled input arriving *after* the crawl has started. Two
distinct attacks follow.

1. **The site points the crawler elsewhere.** A sitemap naming `169.254.169.254/`, a
   link to `/admin/`, a redirect to a gateway. Every discovered URL is re-validated
   through `Url_Validator` **before it is queued** § validating at fetch time would
   mean the frontier had already been poisoned.
2. **The site makes the crawler expensive.** Ten thousand links, a calendar, a tag
   cloud. `MAX_LINKS_PER_PAGE` and `MAX_FRONTIER` are applied at *discovery*, not at
   the end.

### Never crawled

Refused by **whole path segment**, not string prefix:

| Refused | Note |
|---|---|
| `/wp-admin`, `/admin.php`, `/admin/*` | Same-origin, so an origin check alone would let it through |
| `/wp-login`, `/login`, `/register` | Authentication surfaces |
| `/cart`, `/checkout`, `/payment`, `/billing` | §4 names checkout payment systems |
| `/my-account`, `/dashboard`, `/settings`, `/private` | Private areas |
| `data:` URIs | Inline content, often a base64 payload |
| Social networks as hosts | A profile page is analysed as one page, not mirrored |
| Any external domain | Unless `include_subdomains` is explicitly enabled (off by default) |
| Loopback, private ranges, `169.254.x`, non-http schemes | Via the existing `Url_Validator` |

**Segment matching is load-bearing.** A `strpos()` prefix test on `/admin` also refuses
`/admiralty`, `/logistics`, `/accounting`, `/setting-up`, and `/cartography`. A test
asserts each of those is permitted.

**One deliberate exception:** `/administration` *is* refused, even though universities
and government sites have a content page there. The worse failure is chosen: a user can
add the address by hand, whereas a site whose admin lives at `/administration/` would
otherwise be crawled unauthenticated with no way for the user to know.

### XXE is refused before parsing

A `<!DOCTYPE` or `<!ENTITY` in a sitemap is never legitimate and is the shape an XXE
takes, so it is refused before any parsing. Locations are then extracted with a textual
scan, not an XML parser § and every extracted URL still goes through the full
`accepts()` filter, because a sitemap is a *suggestion*, not a command.

### Global style ownership defaults to `unknown`

§17: if ownership is uncertain, do not overwrite. The whole design is that
**uncertainty must be the default**.

Ownership is **proved by ReplicaForge having written it before**, recorded in
`replicaforge_global_style_ownership`. It is never inferred § a detector that guesses
`theme_controlled` from "the colours look like the theme's" will one day be wrong, and
there is no undo in Elementor's global settings. Refusing to write costs a settings
toggle; a wrong write costs the design.

`record_ownership()` is the only way to become writable, and it takes an explicit
state. There is no "adopt" shortcut.

### Theme Builder is detected, never assumed

`builder_locations()` asks Elementor Pro's public location manager inside a
`try`/`catch`. A Theme Builder template is only offered when the location exists,
because generating one for a location the version lacks produces a template that is
invisible § it exists, it is stored, and it never renders. Every capability is *asked*
of `Elementor_Compatibility`; no version number is ever compared.

### Project ids cannot be enumerated

`Multi_Page_Api::owned()` returns one message for "does not exist" and "belongs to
another account", so a project id cannot be probed by comparing responses. A submitted
`page_id` is **recomputed** from its URL and compared; a disagreement is refused rather
than resolved, so a caller cannot name a page they did not submit.

### No route accepts Elementor data

Verified by source scan in the test suite. There is no route that takes a property name
and a value, and none that takes a document tree. `POST /analyze` reads representations
from stored post meta, so this handler cannot be given a document to write.

### Copyright and provenance

`reference` is the default asset mode: importing copies somebody else's file into the
media library, and a user who wants that can ask per asset. Provenance is recorded at
**discovery**, not import, and the output states in the rights note that a public
address is not permission to reuse.
## Phase 13: the rendering boundary

Rendering untrusted websites is the most dangerous capability in ReplicaForge, because it
involves fetching and storing a rendering of somebody else's page. Five rules hold.

### 1. Rendering is out of process, always

A WordPress plugin cannot ship a headless browser, and rendering the analyzed page
**inside the WordPress process** would execute untrusted site content in the same
interpreter that holds the site's database credentials. Rendering therefore happens at an
endpoint **the operator configures**, or not at all.

`Endpoint_Renderer::capabilities()` reports `javascript: false`, and the reason is in the
code: the provider runs scripts in its own sandbox, and this process must never be able to.

### 2. No route accepts a URL to render

Discovery is the only path that produces URLs, and every one it produced has been through
`Url_Validator`. A caller that wants a page rendered names a **project page**, not an
address. The visual controller therefore cannot be used to ask the render provider to fetch
an internal address, even by accident.

Asserted by a comment-stripped source scan for a bare url parameter, and by a test that a
loopback address is refused *before* the provider is asked, verified by a call counter that
stays at zero.

### 3. No filesystem path is exposed

Captures are addressed by an opaque cache key and the response carries bytes and
dimensions only. A leaked `wp-content/uploads/...` path is a reconnaissance gift.

Asserted by scanning the visual API for `uploads` and `wp_get_upload_dir`. Captures are
served `nosniff`, `private, max-age=0, no-store`, with a `Content-Disposition`, so a
rendered third-party page is not re-interpreted by the browser or cached by an intermediary.

### 4. Ownership is resolved before the store is read

One message for "missing" and "not yours", so a project id cannot be probed. The owning
project is recorded **in the cache index**, not derived from the key, and compared before
any capture bytes are returned.

### 5. Source JavaScript never executes in WordPress

Asserted by scanning the visual API for `eval(`, `shell_exec`, `proc_open`, and `popen`.
There is deliberately no local-browser fallback: a local browser would have to execute
untrusted pages, and no sandboxing story is good enough to justify that inside a WordPress
request.

### What was not weakened

Phase 1's `Url_Validator` (SSRF, private ranges, loopback, cloud metadata) guards every
render target. Phase 5's `Visual_Renderer` still blocks scripts and media, still refuses
unsafe URLs at the transport layer, and still caps the response size. The Phase 13 change
to that class is additive: one optional `stabilize` payload key, absent unless a caller asks.

### A pre-existing defect found and fixed here

`Component_Registry` did not track which project it held. `put_shared()` read the bucket
for the **empty** project id while writing the real one, so a user override was discarded
by a re-analysis, and `mark_overridden()` wrote to the shared empty bucket -- meaning one
project's user edit permanently marked another project's identically-named component as
user-owned, blocking correction of it there. Both are fixed by recording the project on
load and on save.

---

## Phase 14 â€” Content and Data Intelligence

Content mapping is the first ReplicaForge capability that can **write to the user's own
data**, so its boundary is tighter than any earlier phase.

### Structured data is untrusted input

JSON-LD is author-controlled content on a site ReplicaForge has not authenticated
against. It is treated as a *claim*, never a fact:

- parsed with `json_decode( â€¦, true, 12 )` â€” data only, bounded depth, no object
  instantiation, no `$ref` resolution, no `eval`, no `unserialize`;
- size-checked **before** decode, because decoding a 50 MB string is a memory exhaustion;
- every URL validated through `Url_Validator`; a metadata-endpoint or loopback URL is
  dropped and recorded, never stored;
- secret-shaped keys dropped, so a hostile page cannot plant data in ReplicaForge's
  records;
- malformed JSON-LD recorded as a warning with a reason, never as absence of data;
- every entity `verified => false`. `Source_Content_Model::validate()` treats an entity
  claiming verification as an **error**.

There is no path from this phase to a network request, a shell, a process, or `eval`.

### No route can name a destination

Thirteen authenticated routes. A caller names a *plan* â€” an opaque id the validator
already checked â€” and a list of *mapping ids within that plan* to approve. No parameter
of `Content_Api` is ever used as a field name, a meta key, a table, or a callable.

- Approved ids are pattern-checked (`/^map_[a-f0-9]{8,32}$/`) before reaching a
  comparison or an option name.
- Stored plans are re-read from the store, never trusted from the request, so a caller
  cannot apply a plan the validator never saw.
- An inline plan has its `project_id` **replaced**, not honoured.
- Ownership is resolved before the store is read, with one message for "missing" and
  "not yours" so a project id cannot be probed.
- `apply` requires `replicaforge_manage_plans`, not `replicaforge_use`.

### The source seal

`Content_Validator::validate()` recomputes a digest over every mapping's
`content_id â†’ value` and compares it with the digest recorded at plan time. A plan with
no digest is **refused** â€” "no proof it was checked" is not "no problem".

This closes a real hole: the first draft had `validate()` trust the `applicable` flag
that `plan()` wrote, and since the API accepts an inline plan, a caller could change one
value and have it written.

Binding a destination `entity_id` does not break the seal. Choosing which record to
write to is a user decision; inventing a source value is not.

### Read-only fields are re-checked at apply

`_stock` is `editable => false` in the provider. `Content_Mapper` refuses a mapping onto
it, and `Content_Applier` re-resolves the field against the provider at apply time rather
than trusting the plan â€” because a plan can be hand-edited between validation and apply.

### No direct SQL in the destination providers

`WordPress_Content_Provider` uses `get_posts`, `get_terms`, `get_post_types` and
`wp_get_attachment_url`, never `$wpdb`. A direct query bypasses `posts_where`, capability
checks and multisite. It asks `current_user_can( 'read_post', $id )` rather than
reimplementing the answer, and excludes password-protected posts and non-publicly-
queryable post types.

### Meta keys are never enumerated

`get_post_meta( $id, '', true )` would pull the *values* of every meta key on a post into
memory â€” and a meta key on a real site can be a licence key, a payment token, or a
customer's private note. Custom-field support reads an injected *structure* report, and
secret-shaped keys are filtered out before a field is even offered.

### Privacy at the AI boundary

Redaction is applied on the way *out*, not in storage â€” redacting the user's own product
copy in the database would corrupt their data. `Content_Preview::for_ai()` removes
personal and contact items entirely, redacts secrets from what remains, and truncates.
The removals are reported, so the omission is visible rather than silent.

### The provider contract cannot write

The contract's eight methods are all reads, and no method name contains `order`,
`customer`, `payment` or `stock`. This is asserted against the reflection method list,
not by scanning source text for the words â€” the earlier scan matched `'order' => 'ASC'`
and a comment explaining that `$wpdb` is not used.