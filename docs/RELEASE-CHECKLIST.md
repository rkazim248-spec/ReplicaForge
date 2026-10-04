# ReplicaForge Release Checklist

The gate a release must pass. A box is ticked by running something, not by reading
the code. Where a box cannot be ticked in this environment, it says so rather than
being assumed.

- [ ] Plugin version bumped in the header and `REPLICAFORGE_VERSION`
- [ ] `Schema::DB_SCHEMA_VERSION` bumped **only** if a stored shape changed
- [ ] `Schema::PROMPT_VERSION` bumped **only** if a prompt changed
- [ ] `CHANGELOG` entry written
- [ ] The completion report for this phase reflects this release

---

## 0. Phase 8 scope gate — read before anything else

**Phase 8 is not a feature release.** Six engines are built, tested, and **not wired
into the analysis pipeline**. A user installing this build gets exactly Phase 7
behaviour. That is not a defect to be discovered at release; it is the state of the
work, and it is recorded in the README, both Phase 8 documents, and the completion
report.

Tick these before shipping anything described as a Phase 8 feature:

- [ ] **Decide the release shape.** Either ship as Phase 7.0 with the engines
      unreachable, or do not ship. Shipping a version number that implies working
      grid, flexbox, gradient, or token support when none is reachable is the one
      outcome to avoid.
- [ ] If shipping the engines as reachable, the wiring in §11.1 of
      `docs/PHASE-8-COMPLETION-REPORT.md` is complete, in order, with all 14 suites
      passing at each step.
- [ ] `docs/PHASE-8-ADVANCED-RECONSTRUCTION.md` and `docs/PHASE-8-COMPLETION-REPORT.md`
      still describe the shipped state. If the wiring landed, the "not wired" sections
      in both documents are now wrong and must be corrected before release, not after.
- [ ] The five decision-record documents (`ASSET-POLICY`, `INTERACTIONS`,
      `THEME-COMPATIBILITY`, `SOURCE-CHANGE-DETECTION`, and the gap list in
      `PHASE-8-ADVANCED-RECONSTRUCTION.md`) still say at the top that they are not
      implemented. **Do not delete those warnings without implementing the feature.**

---

## 1. Installation

- [ ] Clean WordPress installation: activate, deactivate, reactivate
- [ ] Existing WordPress installation with content: no content is modified on activation
- [ ] Activation schedules the background work
- [ ] Deactivation unschedules it and **keeps** stored data
- [ ] Reactivate reschedules without duplicating events
- [ ] Activation runs migrations
- [ ] Update from the previous version: stored data migrates and stays readable
- [ ] Uninstall removes ReplicaForge data and nothing else
- [ ] After uninstall, no `replicaforge_*` option, transient, or post meta remains
- [ ] After uninstall, the stored AI API key is gone
- [ ] Deactivation keeps stored data, including a queued job
- [ ] After uninstall, every Elementor page and every media item is untouched

```
Verify: php lifecycle-contract-test.php <wp-root>   (47 assertions)
```

## 2. Elementor

- [ ] Elementor active: generation works
- [ ] Elementor absent: analysis and AI planning work, generation refuses with an action
- [ ] Elementor installed but inactive: refuses with `elementor_inactive`
- [ ] Elementor without container support: falls back to sections and columns, and the report says so
- [ ] A widget the version does not provide is not emitted
- [ ] A control the version does not expose makes the property `blocked`
- [ ] Invalid element id is refused
- [ ] Invalid property is refused
- [ ] Responsive settings round-trip
- [ ] Draft creation produces a draft, never a published page
- [ ] Draft validation re-reads the saved document
- [ ] Rollback restores the document and the responsive map

```
Verify: php -l, the phase4 and phase6 suites, and manual editor QA below.
```

**Manual editor QA — not verified in this environment:**

- [ ] Open a generated draft in the Elementor editor
- [ ] Apply a device correction, then confirm it appears in the **Tablet**/**Mobile** tabs
- [ ] Confirm a desktop correction appears in `settings`
- [ ] Confirm a device correction appears in `_elementor_responsive`, not in `settings`
- [ ] Edit a property by hand, re-validate with **Ignore cached result**, confirm the manual-change flag appears
- [ ] Roll back from **Corrections**; confirm the restored hash equals the snapshot hash and the responsive map is restored

## 3. Security

- [ ] localhost refused
- [ ] `127.0.0.0/8` refused, including the decimal, hex, and octal notations
- [ ] `0.0.0.0` refused
- [ ] Private IPv4 refused
- [ ] Private IPv6 refused
- [ ] Link-local refused, including `169.254.169.254`
- [ ] Cloud metadata hostname refused
- [ ] Credentials in a URL refused
- [ ] Redirect to a private address refused
- [ ] Redirect loop refused
- [ ] `file:`, `ftp:`, `javascript:`, `data:` refused
- [ ] Non-standard port refused
- [ ] XSS payload survives as text
- [ ] SQL payload is not executed; no query is built by concatenation
- [ ] Invalid nonce refused
- [ ] Insufficient capability refused, with a valid session
- [ ] Unauthenticated request refused on every functional route
- [ ] Every REST argument is validated
- [ ] No secret in a log, a job record, an export, or a REST response
- [ ] Uninstall deletes no post, no attachment, and no Elementor document

```
Verify: php security-contract-test.php <wp-root>   (132 assertions)
        php verify-rest-args.php <wp-root>          (0 unvalidated arguments)
```

## 4. Jobs

- [ ] Queue a replica job; it returns 202 with a job id
- [ ] A repeated click returns the same job, not a second one
- [ ] Closing the tab does not stop the job
- [ ] Progress matches the stage reached
- [ ] A queued job is 0%, not an invented number
- [ ] A completed job is 100%
- [ ] Kill a worker mid-stage; the lease expires and the next tick recovers it
- [ ] A retryable failure is retried with backoff
- [ ] A permanent failure is not retried
- [ ] The attempt budget is enforced
- [ ] A resumed job restarts at the stage it reached
- [ ] Work completed before a pause is still there after the resume
- [ ] Cancelling keeps the record of a draft the job produced
- [ ] Deleting a job deletes its stage payloads
- [ ] A failure reports a message written for a person

```
Verify: php jobs-contract-test.php <wp-root>   (122 assertions)
```

## 5. Performance

- [ ] Small website analyzed cleanly
- [ ] Medium website analyzed within the limits
- [ ] Large website degrades to partial analysis with a warning
- [ ] Low `memory_limit` reports a warning and a limit message, not a fatal
- [ ] Slow network reports a timeout, not a hang
- [ ] AI timeout reports a message and a retry decision
- [ ] A long job is not truncated by the request timeout
- [ ] No N+1 query pattern

```
Verify: php report-cleanup.php <wp-root>, php report-ttls.php <wp-root>
```

## 6. UX

- [ ] Desktop, tablet, and mobile: the admin UI is usable at each
- [ ] Below 782px the job and log tables become card lists
- [ ] Full keyboard navigation through every screen
- [ ] A visible focus ring on every interactive element
- [ ] `role="progressbar"` with a value and an accessible name
- [ ] Status is conveyed by a word as well as a colour
- [ ] Every loading state is visible
- [ ] Every error state names the action
- [ ] Every empty state explains itself
- [ ] Duplicate actions are disabled while a request runs
- [ ] No screen says "Coming soon", "100%", or "Completed" without the backend having done it
- [ ] `prefers-reduced-motion` and `prefers-contrast` are honoured
- [ ] ReplicaForge assets load on ReplicaForge screens only

```
Verify: php admin-contract-test.php <wp-root>   (66 assertions)
```

## 7. Internationalization

- [ ] Every user-facing string uses a translation function
- [ ] Every translation call uses the `replicaforge` text domain
- [ ] `languages/` exists and is declared via `Domain Path`
- [ ] No hard-coded user-facing string remains in a template

```
Verify: scan for translation calls with a wrong domain; expect 0.
```

## 8. Data and migrations

- [ ] The stored schema version is recorded
- [ ] A pending migration is reported on **System Status**
- [ ] A migration can be run from the screen
- [ ] A forced migration is safe to repeat
- [ ] A migration never discards user data
- [ ] A failed migration does not block site load and records the failure
- [ ] Expired transients are removed
- [ ] Live transients are kept
- [ ] A transient backing a pending rollback is kept
- [ ] Finished jobs are pruned; unfinished jobs are kept
- [ ] Log entries are pruned by age
- [ ] Retention settings are range-checked

```
Verify: php maintenance-contract-test.php <wp-root>   (123 assertions)
```

## 9. Automated tests

- [ ] `php -l` clean on every file (122 files, 0 failures)
- [ ] Boot check: every type loads, every service constructs, every route registers
      (105 types, 0 failures, 10 services, 20 routes)
- [ ] Every contract suite passes (14 suites, 1556 assertions, 0 failures)
  - [ ] Phase 1–7 regression (9 suites, 908 assertions) — unchanged by Phase 8
  - [ ] Phase 8 engines (5 suites, 648 assertions)
- [ ] Zero PHP warnings or notices during the suites
- [ ] `admin.js` parses
- [ ] No unescaped variable echo
- [ ] No query built by concatenation

## 10. Documentation

- [ ] `README.md` describes the implemented system
- [ ] `docs/ARCHITECTURE.md` matches the code
- [ ] `docs/SECURITY.md` matches the defences
- [ ] `docs/API.md` matches the registered routes and arguments
- [ ] `docs/DATABASE.md` matches the storage
- [ ] `docs/AI.md` matches the trust boundary
- [ ] `docs/ELEMENTOR.md` matches the compatibility layer
- [ ] `docs/JOBS.md` matches the job system
- [ ] `docs/PERFORMANCE.md` matches the measurements
- [ ] `docs/PRIVACY.md` matches what leaves the site
- [ ] `docs/COMPATIBILITY.md` matches the versions
- [ ] `docs/TROUBLESHOOTING.md` covers the failures seen in support
- [ ] No documented feature does not exist
- [ ] No existing feature is undocumented

---

## Sign-off

| Gate | Result | Notes |
|---|---|---|
| Installation | | |
| Elementor | | |
| Security | | |
| Performance | | |
| UX | | |
| Jobs | | |
| Data and migrations | | |
| Tests | | |
| Documentation | | |

**A box that could not be ticked is left unticked and recorded in the completion
report.** An unticked box is information. A ticked box that was not verified is a
false claim, which is worse than an unticked one.
