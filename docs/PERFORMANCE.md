# ReplicaForge Performance

Every number here was measured on the environment recorded in
`docs/PHASE-7-AUDIT.md`, or is a documented limit. Nothing is estimated.

## 1. Measured costs

Measured on a small synthetic fixture against WordPress 7.1.2, PHP 8.4.25,
`memory_limit` 128M, SQLite.

| Operation | Time | Notes |
|---|---|---|
| Contract test suite (9 suites, 908 assertions) | ~5 s | Includes a full boot of WordPress per suite |
| Boot check (99 types, 10 services, 20 routes) | < 1 s | |
| Validation engine (applier time) | 219 ms | Small fixture |
| Correction plan | 40 ms | |
| Correction apply | 60 ms | |
| Job record read | < 1 ms | One option read |
| Job list of 100 | < 5 ms | One option read |
| Log summary | < 2 ms | |

A real page with 40 stylesheets and a render provider is a different order of
magnitude, which is exactly why the work is now a job rather than a request.

## 2. Query profile

ReplicaForge issues no queries with interpolated values. The queries that exist:

| Query | Frequency | Index used |
|---|---|---|
| Option read/write | Per service call | Primary key |
| Transient scan for cleanup | Daily, and on demand | `option_name` |
| Post meta scan for snapshot drafts | Daily, and on demand | `meta_key` |

There is no N+1 pattern. A job list of 100 records is one option read, not 100
queries. A cleanup scan is bounded to 5000 rows so a very large options table cannot
turn a daily task into a timeout.

## 3. Remote requests

Every request is bounded on seven axes simultaneously:

| Bound | Value |
|---|---|
| Timeout | Per phase, with a shared deadline across redirects |
| Redirects | 3, each re-validated |
| Response size | Per phase, enforced before and after the read |
| Redirect loop | Detected by URL tracking |
| Content type | Enforced; extensions are never trusted |
| Decompression | `Accept-Encoding: identity` |

A redirect chain cannot multiply the time budget, because the deadline is shared
across hops rather than reset per hop.

## 4. Memory

| Structure | Bound |
|---|---|
| HTML document | Response cap |
| Stylesheet | Per-sheet cap, sheet count cap |
| DOM | Depth cap; parsing stops and reports truncation |
| Phase 1 representation | Per-phase element cap |
| AI payload | 2 MB per request |
| REST representation argument | 2 MB |
| Checkpoint bulky value | Transient, not in the job record |
| Log entry | Message and context truncated to 2000 chars |
| Log total | 500 entries default, 5000 ceiling |
| Job records | 100 |
| Redaction | Depth and node budget |

**System Status** warns below 128 MB and recommends 256 MB or more. A page that
reaches the limit reports `memory_limit_reached` and a partial analysis rather than
fataling.

## 5. Large websites

Every limit degrades gracefully. Reaching one produces partial analysis plus a
warning naming the limit, not a fatal and not a silent truncation.

| Limit | Behaviour when reached |
|---|---|
| HTML size | Stop fetching; report `response_too_large` |
| CSS size | Stop fetching that sheet; report the skip |
| Image count | Stop collecting; report the count collected |
| Link count | Stop collecting; report the count collected |
| DOM depth | Stop descending; report the depth reached |
| Section count | Stop collecting; report the count |
| AI payload | Refuse with a message naming the size |
| Generation size | Stop building; report what was built |

**System Status** reports the current memory and time limits, because a limit the
user cannot see is a limit they cannot plan for.

## 6. Caching

| Operation | Cached | Key includes |
|---|---|---|
| Phase 1 analysis | Yes | Source hash, analysis schema, plugin version |
| Phase 2 representation | Yes | Source hash, analysis schema |
| Phase 3 reconstruction | Yes | Representation hash, reconstruction schema, **prompt version**, model, provider |
| Asset metadata | Yes | Source hash, elementor schema |
| Phase 5 validation | Yes | Source hash, draft hash, validation schema, viewport set |

Every key folds in the version that produced the result, so a schema change
invalidates the cache rather than serving a stale result. The reconstruction key
includes the prompt version, which is the one that is easy to forget and expensive
to get wrong.

**Ignore cached result** on the validation screen forces a fresh comparison.

## 7. Duplicate work avoided

| Waste | Avoided by |
|---|---|
| A second draft from a repeated click | Idempotency keys on the job queue |
| Re-running a completed stage on resume | Stage checkpoints |
| Storing a large representation per retained job | Payloads in a bounded transient |
| Fetching the same stylesheet twice | Per-request fetch tracking |
| Parsing the same CSS twice | Stylesheet cache within a request |
| Correcting the same difference twice | Dedupe by `dedupe_key` before planning |

Duplicate checks are also deduped against the **metric denominator**, not only
against the difference list. Leaving them in inflated the score, which was a real
defect found in Phase 5.

## 8. Background processing

Long work moved off the request so a timeout cannot truncate it. The cost is that
progress is asynchronous, which is why the UI polls rather than blocking.

A tick processes at most 2 jobs, one stage each, so a backlog cannot turn one cron
tick into a request that runs out of time.

## 9. What was not optimised

Recorded so it is not mistaken for an oversight:

- `render_page()` (332 lines) and `enqueue_assets()` (284 lines) are long but
  declarative — markup and asset tables matching the established pattern. Splitting
  them would add indirection without reducing work.
- `register_correction_routes()` (193 lines) is a declarative route block.
- The log and the job list are written whole. Bounded collections, one query. A
  custom table would scale better and cost more in schema surface.
- No caching layer was added to the admin screens. A ReplicaForge admin is not a
  high-traffic page, and caching it would introduce a staleness problem for no
  measurable gain.

## 10. Profiling

```powershell
# Contract suites, which boot WordPress and run the pipeline
powershell -ExecutionPolicy Bypass -File run-all-tests.ps1

# Storage footprint
php report-cleanup.php <wp-root>

# TTL distribution, to confirm what is live and what is reclaimable
php report-ttls.php <wp-root>
```

A production site can measure a real request with a profiler plugin, but the first
thing to check is the **System Status** screen: it reports the memory limit, the
time limit, whether cron is running, and whether outbound requests are permitted,
which account for most "ReplicaForge is slow" reports.

---

## Phase 12: multi-page performance

### Measured (3-page synthetic fixture, real WordPress bootstrap)

| Stage | Time |
|---|---|
| Classification, 3 pages | < 5 ms |
| Design system, 3 pages | < 10 ms |
| Shared component detection, 3 pages | < 5 ms |
| Full `Site_Analyzer::analyze()`, 3 pages | ~20 ms |
| `Cross_Page_Validator::validate()` | < 5 ms |
| Snapshot | < 2 ms (no content copied) |

The design system cost is `N+1` calls to `Token_Engine::build()` § one per page for
per-page evidence, one for the global set § which is the price of being able to say
*which* pages disagree rather than only that they do.

### Bounds

| Constant | Value | Why |
|---|---|---|
| `MAX_PAGES` | 100 | Hard ceiling; a plan can only lower it |
| `MAX_DEPTH` | 3 | Beyond three is pagination, an unbounded set dressed as a hierarchy |
| `MAX_FRONTIER` | 400 | Bounds the work list *before* the page limit is consulted |
| `MAX_LINKS_PER_PAGE` | 300 | A page with 10,000 links is a sitemap wearing a page's clothes |
| `MAX_PASS_SECONDS` | 20 | A discovery pass must not consume a Phase 11 tick |
| `MIN_REQUEST_INTERVAL` | 1 s | A small site crawled at full speed is antisocial |
| `MAX_SHARED_COMPONENTS` | 60 | |
| `MAX_TEMPLATES` | 20 | |
| `MAX_ASSETS` | 600 | |
| `MAX_SNAPSHOTS` | 10 | Per project |

### Not measured

**No real 25-, 50-, or 100-page crawl was performed.** Doing so would mean crawling a
third party's website, so the scaling figures for a real site are unmeasured rather
than estimated. Memory, database operations, HTTP request count, AI calls, and Elementor
generation time for a real multi-page run are likewise unmeasured, because nothing in
Phase 12 generates a page yet.

This is a gap, not a result. The 1/5/10/25/50 sweep §70 asks for should be run against
a site the operator controls before this is relied on at any scale.
## Phase 13: visual performance

**No performance figure in this section was measured.** Rendering requires an
operator-provided endpoint, and no render provider exists in the development environment,
so render time, memory per capture, and screenshot size are all unmeasurable here. Any
number would be invented. The 1/5/10/25/50 sweep has not been run.

### What is bounded, and enforced in tests

| Bound | Value | Why this value |
| --- | --- | --- |
| `MAX_RENDERS_PER_JOB` | 75 | A 25-page project at 3 viewports. A plan limit, but bounded technically too, because a technical bound holds when a plan changes. |
| `MAX_SAMPLED_PIXELS` | 40,000 | A 1440x900 capture is 1.3M pixels; a full-page DPR-2 capture is 5.3M. Reading every pixel of every signal in PHP is seconds of CPU. |
| `MAX_CORRECTION_ITERATIONS` | 3 | The loop must be bounded. The caller is clamped and told it was clamped. |
| Cache entries | 120 | An unbounded cache is a disk-full incident. |
| Stored representations | 400/project | 100 pages x 3 viewports is 300; past that a representation is stale anyway. |
| Stored reports | 40/project | Bounded history, oldest dropped. |
| DPR | 1.0-3.0, default 1 | A DPR above one multiplies every pixel by its square: 1440 wide at DPR 2 is 2880 pixels and four times the memory. |
| Viewport via filter | 320-3840 wide | A filter must not be able to request a memory exhaustion. |
| Images per AI request | 0-4 | Sending a hundred screenshots is exfiltration with extra steps. |

### Three costs worth knowing about before enabling rendering

- **Pixel comparison is the dominant cost.** Six signals, each walking a subsample. A
  25-page project at three viewports is 75 captures, and 75 full-page pixel walks. This is
  why the sampler is bounded, and why the sampled fraction is *recorded* in every report: a
  ratio from a 3% sample is a different claim from one computed from every pixel.
- **The pixel walk runs several times per comparison when nothing is masked** -- once for
  the signal, once for the regions, once for the heatmap, and once more for the Phase 5
  cross-check -- and each reads the surfaces independently. A shared decoded surface would
  be the obvious optimisation and is noted rather than done, because it changes the memory
  profile of a path that has not yet been measured at scale.
- **Grid inference is bucketed, not truly quadratic.** Boxes are bucketed by visual row
  before comparison, but `MAX_BOXED_ELEMENTS` (600) is still the real bound.

### Before relying on any of this at scale

Run the sweep against a site you control, with a render provider you operate, and record
render time, peak memory, capture size, and comparison time per viewport. Until then the
bounds above are the only claim, and they are the honest one.

---

## Phase 14 â€” Content and Data Intelligence

### Bounded at every level

| Bound | Value | What it prevents |
|---|---|---|
| `MAX_CONTENT_ITEMS` | 600 | A pathological page producing an unbounded model |
| `MAX_ENTITIES` | 300 | The same for entities |
| `MAX_STRUCTURED_BLOCKS` | 40 | A page declaring thousands of JSON-LD blocks |
| `MAX_STRUCTURED_DEPTH` | 12 | A 500-deep `@graph` exhausting the stack |
| `MAX_STRUCTURED_BYTES` | 256 KB | `json_decode` on a 50 MB string â€” checked *before* decode |
| `MAX_MAPPINGS` | 2000 | An unbounded plan |
| `MAX_DESTINATION_BATCH` | 200 | A 10,000-product store loading into one request |
| `MAX_PROVENANCE` | 2000 | Unbounded provenance growth |
| `MAX_PLANS` | 20 | Unbounded plan retention |

### Working at 10,000+ products

Â§44 is met by construction, not by hope:

- **Providers page.** `entities( $entity_type, $page, $per_page )` returns one page and
  reports `total` and `total_pages`. `collect_destinations()` walks pages and stops at
  `MAX_DESTINATION_BATCH`.
- **The matcher indexes once.** Destinations are bucketed by SKU, title, slug and image
  key in a single pass; scoring is then a hash lookup per source rather than an
  O(nÂ·m) comparison.
- **A batch is bounded and honest.** `match()` stops at `per_page` and reports
  `partial: true` with the true total, so a caller cannot mistake one page for the whole
  job.
- **Scanning is capped.** Every recursive read is depth-bounded, every string is
  length-bounded, and the structured-data read checks size before decoding.

Indexes store **positions**, not entity ids. Keying by id and then looking up a list by
id made every lookup miss for any real id, which is a correctness bug that would also have
been a performance one.

### Caching

Analysis results are cached under a key that folds in the source content hash, the
destination schema hash, the engine version, and each provider's version. A changed page
is a miss; an unchanged one is a hit, and the result says which.

### The largest single cost

`Content_Role_Detector` is O(components) with a linear pre-pass to build the
component-to-group map, and it consumes Phase 2's card groups rather than re-deriving
repetition. The first draft recomputed repetition with its own bucketing, which was both
slower and a correctness risk.