# AI system: reliability, budgeting, and cost control

Phase 11 added the layer that decides *whether* an AI request should be made, *how
big* it may be, and *what to do* when it fails. Phase 3 built the request itself;
this is everything around it.

```
Ai_Context_Builder      builds and redacts a context      (Phase 3, unchanged)
        ↓
Ai_Context_Budget       measures, prioritises, reduces, chunks
        ↓
Ai_Cost_Estimator       labels complexity, decides whether to ask
        ↓
Ai_Manager              sends, caches, validates           (Phase 3, unchanged)
        ↓
Ai_Provider_Contract    a transport
        ↓
Ai_Failures             classifies what came back
        ↓
Ai_Usage_Audit          records the shape of the call
```

---

## 1. Provider capabilities

`Ai_Provider_Contract` gained one method:

```php
public function capabilities();
```

The problem it solves: the plugin used to send a JSON-mode flag to every provider
and enforce one context ceiling for every model. Both shipped models happen to
accept both, so it worked — by coincidence. A provider that ignores the flag
returns prose, and the failure surfaces three layers away as a schema error with
nothing pointing at the cause.

| | OpenAI | Gemini |
|---|---|---|
| structured_output | ✓ | ✓ |
| json_schema | ✓ | — |
| vision | ✓ | ✓ |
| streaming | ✓ | ✓ |
| function_calling | ✓ | ✓ |
| batch | ✓ | — |
| seed | ✓ | — |

Per-model: context capacity, output ceiling, and **cost estimates**.

### An unknown model is treated asymmetrically, on purpose

| | Assumption | Why |
|---|---|---|
| **Context / output size** | Conservative floor (16k in, 8k out) | Assuming a large context produces a request the provider **rejects**. Assuming a small one produces extra trimming, which is cheap and recoverable. |
| **Transport capability** | Inherited from the provider | Assuming a provider cannot do JSON mode means silently receiving prose and failing schema validation later. The provider is a real constraint on the transport; the model entry is an optimisation on top of it. |

A site that knows its real numbers supplies them through
`replicaforge_model_capabilities`. A filter cannot push the *requestable* context
past `Ai_Limits::MAX_CONTEXT_BYTES`, because that ceiling protects the site rather
than describing the model.

### Cost metadata is not a price list

The numbers in `Ai_Capabilities::models()` are **estimates for a pre-flight
warning**. They are never a charge, because ReplicaForge does not charge for AI
tokens — the Phase 10 per-operation limits do that. Every place an estimate
surfaces carries its disclaimer with it, and an unpriced model reports
`cost_estimate.known => false` rather than reporting zero as though it were free.

---

## 2. Context budgeting

`Ai_Context_Builder` already redacts secrets and compacts structurally. What it did
not know is **which model is going to read the result**, so it enforced one
constant for every model. `Ai_Context_Budget` sits after it and before the provider.

```php
$budget = new Ai_Context_Budget( 'openai', 'gpt-4o' );
$measured = $budget->measure( $context );   // bytes, per-part sizes, is it over
$reduced  = $budget->budget( $context );     // reduce structurally if it is
$chunks   = $budget->chunk( $context );      // split by section if it still is not
```

### Priority order (§13)

Highest first, and the top of the list is **never** dropped:

| Part | Priority |
|---|---|
| `design_system` | 100 |
| `tokens` | 95 |
| `typography` | 90 |
| `layout` | 85 |
| `responsive` | 80 |
| `navigation` | 70 |
| `assets` | 65 |
| `hierarchy` | 60 |
| `sections` | 55 |
| `components` | 50 |
| `fields` | 30 |
| `source` | 20 |
| `raw_html` | 10 |
| `raw_css` | 5 |

`design_system`, `tokens`, and `layout` are `ESSENTIAL`. A context that has lost its
design tokens is worse than no context, because the reconstruction will then
**invent** them — and an invented token is exactly what the evidence rules exist to
catch. Reduction removes detail from the bottom of the list upward and stops before
it would touch structure.

No part may claim more than `MAX_SHARE` (a third) of the budget. Without that, one
large part consumes the window and the context looks complete while being about one
component.

### Compaction is structural, never positional (§13)

| Input | Behaviour |
|---|---|
| List | Loses its tail, found by binary search on how many entries fit. Gets a `_truncated` / `_omitted` / `_of` marker. |
| Map | Keeps essential keys, then adds others while they fit; a long value is compacted before its key is dropped. Gets `_omitted_keys`. |
| String | Truncated with a visible `[...]` marker, on a UTF-8 boundary. |
| Anything else | Returned unchanged. |

A JSON document truncated at an arbitrary offset is not a smaller document — it is a
broken one. Every compaction is asserted to still decode.

A truncated string gets a marker because a silently shortened string is
indistinguishable from a complete one, and a model asked to reconstruct from a
truncated value will fill the gap with something plausible.

### Chunking preserves relationships (§14)

Splitting on a character count is wrong: two half-sections cannot be interpreted.
So the split is by **section**, and:

- **chunk 0** is the global design system alone, so a reader can see the shared
  context;
- **every chunk** carries that same global system, so it can be interpreted alone;
- a section larger than one chunk is **compacted, not split** — splitting one
  section breaks the parent-child relationship that makes it interpretable;
- each chunk records the `section_ids` it contains, and the test asserts the count
  matches the sections actually present, so a partial chunk is never mistaken for a
  whole one.

If the global system alone exceeds half the budget, chunking cannot help and that
is reported as `global_context_too_large` — the user is told to use a larger model
or analyze fewer sections, rather than being left to retry.

---

## 3. Cost estimation is a label, not a bill

```
low        one request, small page
moderate   several requests, or a page that did not fit
high       five or more requests, or forty or more sections
```

The band is driven by the **planned** calls, not the worst case. A repair is a
possibility, not a plan, and counting it would put every small page into
`moderate` — making the label useless as a signal and making a confirmation dialog
appear for work that is almost always one request.

`total_calls` still reports planned + possible repair, so a user being asked to
confirm sees the upper bound without being told the work is certain.

Confirmation is a **setting**, not a default. Forcing it on every reconstruction
would train people to click through dialogs without reading them, which is the
opposite of what a confirmation is for. The test suite asserts the notice contains
no countdown, no "spots left", and no urgency.

---

## 4. Failure classification

| Code | Retryable | Fallback-worthy |
|---|---|---|
| `AI_AUTH_ERROR` | — | — |
| `AI_RATE_LIMIT` | ✓ | ✓ |
| `AI_TIMEOUT` | ✓ | ✓ |
| `AI_UNAVAILABLE` | ✓ | ✓ |
| `AI_PROVIDER_ERROR` | ✓ | ✓ |
| `AI_NETWORK_BLOCKED` | ✓ | ✓ |
| `AI_INVALID_RESPONSE` | — | — |
| `AI_SCHEMA_ERROR` | — | — |
| `AI_CONTEXT_TOO_LARGE` | — | — |
| `AI_QUOTA_EXCEEDED` | — | — |
| `AI_CONFIGURATION_ERROR` | — | — |
| `AI_CANCELLED` | — | — |

**An undeclared code is not retryable.** A new failure defaults to the safe answer.

Two distinctions that are easy to get wrong and are asserted directly:

- **A 429 rate limit and a 429 exhausted quota are different.** The body is
  consulted for "quota", "credit", or "billing". A rate limit is retryable; an
  exhausted quota is not, because waiting does not refill it, and it is not
  fallback-worthy because the fallback shares the same account.
- **A response failure is never retryable.** Sending the same request again
  produces the same bad response.

### `retry_after`

When a provider reports a delay, the queue honours **exactly** it and stores it on
the job. Our own backoff applies only when the provider did not say. Honoring our
own schedule over an explicit request is how a rate limit becomes an outage; ignoring
the request is how a provider gets hammered.

A delay longer than `MAX_RETRY_AFTER` (600s) is **discarded**, so a provider cannot
park a job for a day. An `x-ratelimit-reset` header expressed as an epoch is
converted to a delay rather than read as one.

### Secrets never reach a message

Three message layers, and the separation is the point:

- `user_message` — never names the provider, the model, the endpoint, or quotes the body.
- `technical_message` — may name the provider and the status. Goes to the log.
- the **raw body is never carried at all** — it is used only to *choose a code*.

A body containing `Bearer sk-live-…` or a 32-character token is redacted, and the
test asserts the key-shaped string appears nowhere in the resulting record.

---

## 5. Usage audit: shape, not content

The instinct on seeing "audit the AI usage" is to log the request. That is the one
thing to avoid: a request contains the page — its text, image URLs, product names,
prices — and logging it retains a copy of somebody's website inside ReplicaForge's
logs, for a period nobody chose.

Recorded: provider, model, operation, result, attempt count, **byte and token
estimates**, a failure code, and the acting user / project / job.

Never recorded: the prompt, the context, the response, or the cache key's
pre-image. The context already lives in the project's own data with its own TTL.

Records are counted per period and summarised. A **bounded window of the last 100**
individual records is kept — enough to diagnose "why did this job make eleven AI
requests", and cheap to retain. The per-user count reports `exact: false` once the
window is full, because a windowed count presented as a total is worse than one
that admits its window.

`Ai_Usage_Audit::forget()` removes the counters and the window, and nothing else —
the project's own analysis is governed by the existing history rules.

---

## 6. Prompt injection

Website content is untrusted data and is treated as data. The defences, in the
order they apply:

1. **Redaction before the request.** `Ai_Context_Builder` drops secret-shaped values
   and reports how many it removed.
2. **Explicit data delimiters.** `Ai_Prompt_Builder` wraps the context in
   `<REPLICAForge_DATA>…</REPLICAForge_DATA>`, so a model can see where the untrusted
   content begins and ends. The test asserts injected text lands *inside* the data
   region, which is the property that makes the delimiter mean anything.
3. **Redaction on the way back.** A repair prompt quotes the model's own previous
   output, which is the one place ReplicaForge sends text it did not build — so it is
   redacted, and visibly so.
4. **No code path treats context text as an instruction.** There is no `eval`, no
   `include`, no shell, and no template that interpolates context. The safety property
   is structural, not a filter.

The injected text is **carried through as text**, not dropped. A reconstruction needs
the page's real content; a model that has been told to ignore its instructions will
ignore them, because the instruction is a string and the delimiter is the boundary.

---

## 7. What is not built

- **No live provider request is exercised in the test suite.** The adapters are
  tested against the failure and capability layers; no test sends a real request.
  §66's end-to-end run requires a real API key and is a manual procedure, listed in
  `PHASE-11-COMPLETION-REPORT.md`.
- **No provider fallback is implemented.** `fallback_worthy` classifies when a
  fallback would be justified, and nothing acts on it. §39 requires fallback to be
  explicitly configured, and no provider is configured, so building a switcher would
  mean shipping a code path that can silently move a request to a different vendor —
  exactly what §39 forbids by default.
- **`Ai_Manager` does not yet call the budget or the estimator.** The classes are
  built and tested; wiring them into the request path is the next integration step,
  and it is listed as such in the completion report.
- **No multi-stage AI reconstruction.** §15 asks for stages 1–5; the budget's chunking
  produces the per-section payloads it would consume, but no orchestrator drives
  them.
