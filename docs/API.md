# ReplicaForge API

All routes are under `/wp-json/replicaforge/v1`. Every route requires
`is_user_logged_in()`, `current_user_can( 'manage_options' )`, and a valid
`wp_rest` nonce in the `X-WP-Nonce` header. There is no unauthenticated
functional route.

## 1. Response envelope

Success:

```json
{
  "success": true,
  "data": { },
  "meta": {
    "request_id": "req_4f2a1b8c9d0e1f20",
    "job_id": "job_9c1a...",
    "schema_version": "1.0.0"
  }
}
```

Failure:

```json
{
  "success": false,
  "error": {
    "code": "blocked_destination",
    "message": "This address cannot be analyzed. ReplicaForge only connects to public websites, not to private networks or local services.",
    "retryable": false
  },
  "meta": {
    "request_id": "req_4f2a1b8c9d0e1f20",
    "schema_version": "1.0.0"
  }
}
```

A failure never includes a stack trace, a filesystem path, or an internal code. The
internal code, the category, and the technical detail go to the log, keyed by
`request_id`.

`request_id` appears on every response and on every log entry for the same request,
so one identifier ties the HTTP call to the job, the generation, the validation,
and the log lines in between.

### Phase 1–6 responses

The routes built in Phases 1 through 6 predate the envelope and return their
payload directly, with `success: true` on success and a `WP_Error` on failure. Their
contract is unchanged and documented in `docs/VALIDATION.md` and
`docs/CORRECTIONS.md`. The Phase 7 routes use the envelope. The internal
`job_id`, `generation_id`, `validation_id`, and `plan_id` a Phase 1–6 response
carries are recorded in `Request_Context` and appear in Phase 7 log entries, so
the two are still correlatable.

## 2. Error codes

Codes are defined in `Error_Catalog`. Each has a category, a severity, a
retryable flag, and a message written for a person.

| Category | Meaning | Examples |
|---|---|---|
| `SECURITY` | Refused by policy. Never retried. | `blocked_destination`, `credentials_not_allowed` |
| `NETWORK` | The destination could not be reached. Retryable. | `request_timeout`, `request_failed` |
| `SOURCE_WEBSITE` | The page was reached but is not usable. | `too_many_redirects`, `unsupported_content_type` |
| `AI_PROVIDER` | The provider refused or misbehaved. | `ai_rate_limited`, `ai_output_rejected` |
| `ELEMENTOR` | Elementor is absent, inactive, or refused the document. | `elementor_missing`, `document_save_failed` |
| `VALIDATION` | The comparison could not run. | `validation_not_found` |
| `CORRECTION` | A plan is unusable. | `plan_document_changed`, `regression_detected` |
| `PERMISSION` | The request was refused. Never retried. | `forbidden`, `invalid_nonce` |
| `DATABASE` | Storage could not be read or written. Retryable. | `database_error` |
| `SYSTEM` | An internal failure. | `internal_error` |
| `USER_INPUT` | The submitted value is not usable. | `invalid_url` |
| `JOB` | A job could not be queued, resumed, or cancelled. | `job_not_found`, `job_not_resumable` |
| `RESOURCE` | A documented limit was reached. | `response_too_large`, `memory_limit_reached` |

### Retry policy

Only a transient condition is retryable. An invalid address, a rejected value, a
permission failure, and a security block are **not** retried: they will fail
identically every time.

An unrecognised code resolves to `internal_error` for its message but is reported
as **not** retryable. Nobody has classified an unmapped condition as transient, so
retrying it blindly would spend the attempt budget on something that cannot succeed.

## 3. Phase 1–6 routes

### `POST /analyze`

Analyze a public frontend page.

| Argument | Type | Required | Notes |
|---|---|---|---|
| `url` | string | yes | ≤ 2048 chars. Validated and sanitized. |

### `POST /ai/analyze`

Optional AI interpretation of a Phase 2 representation.

| Argument | Type | Required | Notes |
|---|---|---|---|
| `design_representation` | object | yes | ≤ 2 MB. Not sanitized: a text sanitizer would destroy the structure. |
| `source_url` | string | no | ≤ 2048 chars. |
| `mode` | enum | no | `plan` only. |

### `POST /generate`

Build an editable Elementor draft.

| Argument | Type | Required | Notes |
|---|---|---|---|
| `design_representation` | object | yes | ≤ 2 MB. |
| `reconstruction_specification` | object | no | ≤ 2 MB. Re-validated server-side against the representation. |
| `specification_id` | string | no | Must match `spec_` + 32 hex. |
| `source_url` | string | no | Provenance only. |
| `import_assets` | boolean | no | Default `false`. |
| `mode` | enum | no | `preview` or `generate`. |

The browser never submits an Elementor document. `preview` describes what would be
generated without creating a draft.

### `POST /validate`

Measure how closely a draft matches the source.

| Argument | Type | Required | Notes |
|---|---|---|---|
| `design_representation` | object | yes | ≤ 2 MB. |
| `draft_id` | integer | yes | ≥ 1. |
| `visual` | boolean | no | Needs a configured render provider. |
| `ai` | boolean | no | |
| `force` | boolean | no | Ignore the cached result. |
| `viewports` | object | no | Known viewport names, each with `width` and `height` in 240–4096. |

### `GET /validate/{validation_id}`

`validation_id` must match `val_` + 24 hex.

### `GET /validate/{validation_id}/export`

`format` is `json` or `csv`. The export is redacted.

### `POST /corrections/plan`

| Argument | Type | Required | Notes |
|---|---|---|---|
| `validation_id` | string | yes | `val_` + 24 hex. |
| `draft_id` | integer | yes | ≥ 1. |
| `design_representation` | object | no | Used to re-measure after a correction. |
| `ai` | boolean | no | Optional AI ordering pass. |

### `POST /corrections/apply`

| Argument | Type | Required | Notes |
|---|---|---|---|
| `plan_id` | string | yes | `plan_` + 20 hex. |
| `selected` | array | yes | Up to 200 `cor_` + 20 hex identifiers. |
| `approved_structural` | boolean | no | Required before a structural correction applies. |
| `revalidate` | boolean | no | Default `true`. |
| `iterations` | integer | no | 1 to the Phase 6 maximum. |
| `design_representation` | object | no | |

### `POST /corrections/rollback`

`draft_id` (≥ 1) and `correction_id` (`cor_` + 20 hex).

### `GET /corrections/history`

`draft_id` (≥ 1).

### `POST /corrections/export`

`kind` is `plan` or `run`. `format` is `json` or `csv`. `plan_id` and
`correction_id` are shape-checked when supplied.

## 4. Phase 7 routes

### `POST /replicas`

Queue a full replica job. The URL is validated **before** the job is queued, so a
request that could never succeed is refused immediately.

| Argument | Type | Required | Notes |
|---|---|---|---|
| `url` | string | yes | Validated against the full SSRF policy. |
| `use_ai` | boolean | no | Default `false`. |
| `visual` | boolean | no | Default `false`. |
| `import_assets` | boolean | no | Default `false`. |
| `idempotency_key` | string | no | Client-supplied deduplication key. |

Returns **202** for a new job and **200** for a deduplicated one, with
`data.duplicate` telling the two apart. Two rapid clicks produce one job, not two
drafts.

### `GET /jobs`

| Argument | Type | Notes |
|---|---|---|
| `type` | enum | `replica`, `generation`, `validation`, `correction` |
| `status` | enum | `queued`, `running`, `paused`, `completed`, `failed`, `cancelled` |
| `limit` | integer | 1–100, default 25 |

### `GET /jobs/{job_id}`

`job_id` must match `job_` + 24 hex. A presented job carries `progress`, `stages`,
`can_resume`, `can_retry`, `can_cancel`, `duration_ms`, and a reduced `result` that
never contains a document.

### `POST /jobs/{job_id}/{action}`

`action` is `resume`, `retry`, `cancel`, or `delete`.

- `resume` restarts at the stage the job reached, not from the beginning, and is
  refused when the attempt budget is exhausted.
- `retry` is `resume` followed by making the job claimable immediately.
- `cancel` marks the job cancelled. It never deletes a draft the job already
  produced.
- `delete` removes the job record and its stage payloads.

### `GET /status`

The full system status report, the feature flags, and the migration state.

### `GET /logs`

| Argument | Type | Notes |
|---|---|---|
| `level` | enum | `debug`, `info`, `warning`, `error`, `critical` |
| `search` | string | ≤ 100 chars, no control characters |
| `limit` | integer | 1–500, default 100 |

### `GET /logs/export`

Newline-delimited JSON. Redacted again on the way out. Sent as a file attachment.

### `POST /maintenance/run`

Runs the cleanup on demand and returns what it removed.

## 5. Argument validation

Every one of the 104 declared arguments is validated. This is verified against the
running plugin, not by reading the declarations.

| Rule | Reason |
|---|---|
| A scalar argument has a validator, a sanitizer, or both | WordPress must reject a wrong-typed value before the handler runs |
| A structured argument has a validator but no text sanitizer | A text sanitizer would destroy the structure the phase needs to read |
| An identifier has a shape validator | A malformed identifier would let a caller probe storage with arbitrary keys |
| A post identifier must be a positive integer | `is_numeric( 1.5 )` is true, and truncating it would address a different post than the one named |
| A boolean accepts only recognized spellings | WordPress coerces `"maybe"` to true, so a flag the user did not clearly set would switch on |
| A list argument has a purpose-built sanitizer | A generic one would flatten the list the handler needs |

## 6. Calling the API

```bash
# From wp-admin, a nonce is available as ReplicaForgeAdmin.nonce
curl -s -X POST 'https://example.com/wp-json/replicaforge/v1/replicas' \
  -H 'Content-Type: application/json' \
  -H "X-WP-Nonce: $NONCE" \
  -d '{"url":"https://example.com/pricing","use_ai":false}'
```

`curl` cannot be used from outside an authenticated admin session, because the
nonce is tied to the session. This API is not a public integration surface.

---

## Phase 9: no API change

**The REST surface is unchanged: 20 routes, 104 declared arguments, 0 unvalidated.**
Phase 9 added no routes, because it added no reachable capability.

What exists is internal and takes an `Elementor_Document_Reader` and a post identifier,
not a request:

| Class | Entry point | Notes |
|---|---|---|
| `Component_Matcher` | `match( $old, $new )` | Takes component arrays. Returns matches with confidence and reasons |
| `Change_Detector` | `compare( $previous, $current, $options )` | Takes two representations |
| `Change_Classifier` | `classify( $change )`, `classify_all()` | Takes detected changes |
| `Sync_Conflict_Detector` | `resolve( $reader, $post_id, $element_id, $property, $source_value )` | Three-way merge |
| `Elementor_Map` | `set()`, `get()`, `coverage()`, `stale()` | Per draft, post meta |

### What a future sync route would have to do

Not implemented, recorded so the requirements are decided rather than discovered:

- Every argument gets a `validate_callback`, as the 104 existing ones do.
- A project or draft id in a request is **never trusted**. It is validated,
  type-checked, existence-checked, and ownership-checked before use.
- A request may carry a **plan id and a list of change ids** § the same shape Phase 6
  already uses. It may not carry a property name and a value, and there is no route
  that accepts "set this Elementor property".
- Ownership is enforced at the route. `Project_Repository` carries `user_id` and the
  capability model is Phase 7's, but no route uses either yet.
- Responses carry the Phase 7 envelope with `request_id` in `meta`.
- A cron task resolves its work from a stored record and accepts no parameters.

---

## Phase 10: plans, usage, licensing, onboarding

Thirteen routes were added under the same namespace. The response envelope
follows the brief's standard: `success`, `data`, and `meta.request_id` on
success; `success: false` and `error` with a stable `code` on failure.

Note that the Phase 1–8 routes still use the older envelope, which has no
`meta`. Standardising them is recorded as technical debt in
`PLANS-AND-USAGE.md`. Changing that shape would touch every existing test for no
user-visible gain, and two shapes forever is not the answer either.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/plans` | `replicaforge_use` | The plan matrix and the caller's own plan. |
| GET | `/usage` | `replicaforge_use` | The caller's usage meter and recent tail. |
| GET | `/license` | signed in | The license state and diagnostics. |
| POST | `/license` | `replicaforge_manage_plans` | Store a local licensing record. |
| GET | `/capabilities` | `replicaforge_manage_plans` | The role and capability report. |
| GET | `/audit` | `replicaforge_manage_plans` | Recent commercial audit entries. |
| GET | `/onboarding` | signed in | Onboarding state, welcome content, active tours. |
| POST | `/onboarding` | signed in | `seen`, `complete`, `skip`, `dismiss_tour`, `restore_tour`. |
| POST | `/plans/site` | `replicaforge_manage_plans` | Set the plan this site runs. |
| POST | `/plans/trial` | `replicaforge_manage_plans` | Configure trials. |
| POST | `/trial` | `replicaforge_use` | Start the caller's own trial. |
| GET | `/plans/export` | `replicaforge_manage_plans` | Export the plan configuration. |
| POST | `/plans/import` | `replicaforge_manage_plans` | Import a plan configuration. |

### What a request may never supply

This is the property the whole controller exists to guarantee:

- **A plan id may be named, never a plan.** `POST /plans/site` takes a `plan_id`
  and looks it up in the compiled set. The entitlements that follow come from
  `Plan_Manager`, never from the request body.
- **A usage counter may never be sent.** There is no argument that sets usage. The
  only way usage changes is `Usage_Manager::charge()`, called server-side after
  work completed.
- **A license state may only be set through the local provider.** With a remote
  provider connected, `POST /license` returns `409 license_not_configurable` — a
  provider that answers from a server cannot also accept an answer from a
  browser.
- **No Elementor data.** No route in this controller takes a property name and a
  value. The test suite asserts this by reading the Phase 10 source files and
  failing if any of them mentions `Elementor_Document_Writer`,
  `Elementor_Document_Reader`, `Correction_Applier`, `_elementor_data`,
  `update_post_meta`, or `wp_update_post`.

### Response shape

Success:

```json
{ "success": true, "data": {}, "meta": { "request_id": "req_..." } }
```

Failure:

```json
{
  "success": false,
  "error": {
    "code": "usage_limit_reached",
    "message": "...",
    "details": {}
  },
  "meta": { "request_id": "req_..." }
}
```

A status outside 400–499 is forced to 500 **and its message is replaced** with a
generic one, with the detail going to the log instead. That is the guard against a
PHP notice leaking server information into an error body.

### Error codes added in Phase 10

`feature_not_in_plan`, `usage_limit_reached`, `entity_limit_reached`,
`usage_lock_held`, `usage_too_many_open_operations`, `usage_invalid_request`,
`usage_reservation_not_found`, `unknown_operation`, `unknown_limit`,
`capability_missing`, `project_not_available`, `project_id_required`,
`commercial_plan_not_found`, `plan_import_invalid`, `trial_settings_invalid`,
`trial_unavailable`, `license_state_invalid`, `license_not_configurable`,
`invalid_action`.

A new `PLANS` category was added to `Error_Catalog::CATEGORIES` with default
severity `info` — a plan refusal is information about the account, not a fault.

Note that `commercial_plan_not_found` is deliberately **not** the existing
`plan_not_found`, which is a Phase 6 *correction* plan. One code meaning two
things across two phases is a trap for whoever reads the catalog next.

### Hooks added in Phase 10

| Hook | Type | Purpose |
|---|---|---|
| `replicaforge_plan_definitions` | filter | Replace the plan set. |
| `replicaforge_resolved_plan` | filter | Replace the plan a user resolves to. |
| `replicaforge_feature_entitlement` | filter | Override whether a plan grants a feature. |
| `replicaforge_license_provider` | filter | Install a licensing provider. |
| `replicaforge_billing_provider` | filter | Install a billing provider. |
| `replicaforge_usage_recorded` | action | Fires after usage is charged. |
| `replicaforge_audit_entry` | filter | Modify a prepared audit entry. |

---

## Phase 19 — Templates & Design System

Namespace `replicaforge/v1`, prefix `/templates`. Nineteen paths.

Full reference: [TEMPLATES.md](TEMPLATES.md) section 16. Summary:

| Method | Path | Capability |
|---|---|---|
| `GET` | `/templates/library` | `templates.view` |
| `POST` | `/templates/extract` | `templates.create` |
| `GET` | `/templates/{id}` | `templates.view` |
| `POST` | `/templates/{id}` | `templates.edit` |
| `DELETE` | `/templates/{id}` | `templates.delete` |
| `POST` | `/templates/{id}/validate` | `templates.view` |
| `GET` | `/templates/{id}/versions` | `templates.view` |
| `GET` | `/templates/{id}/compare?from=&to=` | `templates.view` |
| `GET` | `/templates/{id}/export` | `templates.export` |
| `POST` | `/templates/{id}/archive` | `templates.edit` |
| `POST` | `/templates/{id}/restore` | `templates.edit` |
| `POST` | `/templates/{id}/share` | `templates.share` |
| `POST` | `/templates/import/inspect` | `templates.import` |
| `POST` | `/templates/import/install` | `templates.import` |
| `GET` | `/templates/components` | `templates.view` |
| `GET` | `/templates/components/{id}/update-safety` | `templates.edit` |
| `GET` | `/templates/tokens` | `design_systems.manage` |
| `POST` | `/templates/tokens` | `design_systems.manage` |
| `GET` | `/templates/tokens/{id}/impact` | `design_systems.manage` |

### Refusals are shaped to avoid mapping the id space

`401` unauthenticated, then `404` for everything else — wrong workspace, another user's
private template, a template that does not exist. A `403` would confirm the id exists and make
the id space enumerable. See [TEMPLATES.md](TEMPLATES.md) section 9.1.

### `POST /tokens` needs two requests

Changing a global design token requires confirmation. The first request returns the impact
report and changes nothing:

```
POST /tokens  { workspace, token_id, value }
  -> 200 { changed: false, requires_confirmation: true, impact: {...} }
```

## Phase 14 â€” Content Intelligence

Namespace `replicaforge/v1`, prefix `/content`. Thirteen paths, all authenticated
(`replicaforge_use`) and all project-ownership-resolved. `mapping/apply` additionally
requires `replicaforge_manage_plans`.

| Method | Path | Capability |
|---|---|---|
| `GET`  | `/content/providers` | `replicaforge_use` |
| `GET`  | `/content/destination` | `replicaforge_use` |
| `GET`  | `/content/limits` | `replicaforge_use` |
| `GET`  | `/content/modes` | `replicaforge_use` |
| `POST` | `/content/analyze` | `replicaforge_use` + `content_analysis` |
| `POST` | `/content/mapping/plan` | `replicaforge_use` + `content_mapping` |
| `POST` | `/content/mapping/preview` | `replicaforge_use` |
| `POST` | `/content/mapping/validate` | `replicaforge_use` |
| `POST` | `/content/mapping/apply` | `replicaforge_manage_plans` + `content_apply` |
| `GET`  | `/content/mapping/{project_id}/{plan_id}` | `replicaforge_use` |
| `POST` | `/content/mapping/review` | `replicaforge_use` |
| `POST` | `/content/mapping/entities/match` | `replicaforge_use` |
| `GET`  | `/content/provenance/{project_id}` | `replicaforge_use` |

### No route accepts a mapping, a field, or a destination

A caller names a **plan** and a list of **mapping ids within that plan** to approve.
There is no parameter anywhere in this controller that is used as a field name, a meta
key, a table, or a callable. That is how "do not expose arbitrary mapping execution" is
made true rather than promised.

Accepted `approved` values must match `/^map_[a-f0-9]{8,32}$/`. Anything else is dropped
before it reaches a comparison or an option name.

`apply` accepts a `plan_id` (re-read from the store, never trusted from the request) or
an inline `plan` (validated identically, `project_id` replaced not honoured).

### Response codes

`404 project_not_found` for both a missing and a not-yours project, so an id cannot be
probed. `409 confirmation_required` when the mode replaces source content and the caller
did not confirm. `409 not_analysed` when a page has no stored representation.
`429 limit_reached` from Phase 10. `200` responses always carry `data`; failures always
carry `error.code` and `error.message`, and a 5xx never echoes internal detail.

### Provider availability is explicit

An unavailable provider returns `available: false` and `empty: false` with a code and a
plain-language reason. `empty: false` matters: it means "no store" can never be read as
"you have no products".