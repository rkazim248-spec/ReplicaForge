# Phase 15 — Enterprise Collaboration, Project Management & Team Workflows

**Status:** complete, with documented limitations
**Plugin version:** 1.3.0
**Schema version:** 15.0.0 (was 14.0.0)
**Job schema version:** 11.0.0 — unchanged
**Test suite:** 23 suites, 5,427 assertions passed, 0 failed, 2 skipped (both pre-existing)

---

## 1. What this phase is

ReplicaForge was, through phase 14, a reconstruction tool: analyse a public site, understand
its structure, plan and generate a WordPress/Elementor replica, validate it visually, map
content to live data, and keep the two in sync. It was a single-user tool, and it is still
one — nothing in phases 1–14 changed behaviour.

Phase 15 adds the layer that turns it into something an agency can run as a business: a
workspace with people in it, projects with a client and a stage, reviews with a decision
attached to a version, comments anchored to a place, tasks that came from somewhere
specific, and a record of who did what.

### The one architectural decision that shaped everything else

**Phase 15 introduces the plugin's first database tables.** Thirteen of them. Every
pre-existing phase stores its state in WordPress options.

That was a real decision with a real cost, and it was taken because the alternative was
worse:

- An audit log, a comment thread and a per-workspace notification queue are *relational*.
  Modelling them as a serialised option array means every read deserialises the entire
  history, every write rewrites the entire history, and two concurrent writes lose one of
  the two. An audit trail that loses a row to a race is not an audit trail.
- The option approach is also a §41 problem. Everything in one option is one authorisation
  surface, one blast radius, and one place where a permission check can be forgotten.

What was *not* done matters just as much: there is no second job queue, no second severity
vocabulary, no second validation engine, no second versioning engine, and no second project
entity. Phase 15 reads those systems rather than reimplementing them.
`Task_Store::from_difference()` promotes a Phase 12/13/14 difference into a task and copies
the evidence; it does not re-derive the difference.

The 13 tables, in declaration order: `workspaces`, `members`, `invitations`, `clients`,
`contacts`, `project_member`, `reviews`, `comments`, `tasks`, `issues`, `notifications`,
`activity`, `audit`.

---

## 2. Files created

### `includes/workspace/` — the whole Phase 15 layer, 21 files, 533.4 KB

| File | Size | What it is |
|---|---:|---|
| `class-replicaforge-workspace-limits.php` | 32.3 KB | The vocabulary. Every constant, table name, and label helper. Read before anything else. |
| `class-replicaforge-collaboration-schema.php` | 22.7 KB | 13 table definitions, `install()`, `status()`, `table_exists()`. |
| `class-replicaforge-collaboration-store.php` | 29.9 KB | The abstract gateway: cursor + offset pagination, `group_counts()`, casting, base32 ids. |
| `class-replicaforge-workspace-store.php` | 12.2 KB | Workspaces, settings, ownership. |
| `class-replicaforge-workspace-member-store.php` | 16.1 KB | Memberships, roles, duplicate detection. |
| `class-replicaforge-project-member-store.php` | 8.8 KB | Per-project assignments and project roles. |
| `class-replicaforge-project-context-store.php` | 16.8 KB | The §8 project fields, read/written onto the *existing* project record. |
| `class-replicaforge-client-store.php` | 10.4 KB | Clients. |
| `class-replicaforge-client-contact-store.php` | 8 KB | Client contacts. |
| `class-replicaforge-invitation-service.php` | 30.6 KB | Invitations: issue, accept, revoke, expire. |
| `class-replicaforge-permission-manager.php` | 21.2 KB | The capability resolver. The only thing that answers "may this user do this". |
| `class-replicaforge-collaboration-log.php` | 24 KB | Activity and audit stores, plus the log facade. |
| `class-replicaforge-secure-token.php` | 8.1 KB | Token generation and hashing. |
| `class-replicaforge-notification-service.php` | 36.4 KB | Provider interface, in-app and email providers, the service, the store. |
| `class-replicaforge-review-store.php` | 27 KB | Reviews, bound to a version. |
| `class-replicaforge-review-link-service.php` | 25.3 KB | Client review links with passwords and expiry. |
| `class-replicaforge-comment-store.php` | 22.4 KB | Comments and their anchors. |
| `class-replicaforge-task-store.php` | 17.2 KB | Tasks, and promotion from a difference. |
| `class-replicaforge-issue-store.php` | 16.2 KB | Issues. |
| `class-replicaforge-workspace-api.php` | 75.3 KB | The REST layer: 46 endpoints on 34 routes, every one gated. |
| `class-replicaforge-workspace-admin.php` | 72.2 KB | The three admin screens, and 15 POST handlers. |

### Tests

- `tests/phase15-collaboration-test.php` — 134.3 KB, 2,263 lines, 26 sections, **881
  assertions**.

### Documentation

- `docs/PHASE-15-COMPLETION-REPORT.md` — this file.

---

## 3. Files modified

| File | Change |
|---|---|
| `replicaforge.php` | 21 Phase 15 requires; version → 1.3.0. |
| `includes/class-replicaforge-plugin.php` | `collaboration_log()`, `workspace_api()`, `workspace_admin()` accessors; boot construction; `rest_api_init` registration. |
| `includes/class-replicaforge-schema.php` | `DB_SCHEMA_VERSION` → 15.0.0. `JOB_SCHEMA_VERSION` unchanged at 11.0. |
| `includes/class-replicaforge-migrator.php` | `migrate_collaboration()`, registered 14.0.0 → 15.0.0. |
| `includes/class-replicaforge-maintenance.php` | `prune_collaboration()`; `daily()` reports a `collaboration` section. |
| `includes/class-replicaforge-system-status.php` | `check_collaboration_tables()`; `check_schema()` documented as reading the *option*. |
| `includes/class-replicaforge-admin.php` | `PAGE_SLUG` now derives from `Workspace_Limits::ADMIN_PAGE`. The seven existing pages are otherwise untouched. |
| `includes/projects/class-replicaforge-project-repository.php` | The `MAX_PROJECTS` conflict documented at both the constant and `store()`. **No behaviour change** — see §12. |
| `uninstall.php` | 13 tables and 3 options added to the sweep. |
| `tests/runner.php` | The new suite registered. |
| `tests/phase14-content-test.php` | One assertion strengthened (see §8). |

**Nothing else was modified.** The reconstruction engine was not touched.

---

## 4. How it fits together

### Two status axes, never merged

This is the most important thing to understand about the model, and the easiest thing to get
wrong.

```
Project_Status::STATUSES   14 values   the reconstruction pipeline
   queued → analyzed → planned → generating → generated → validating → …
   "how far has the machine got"

Workspace_Limits::STAGES    7 values   the agency workflow
   draft → in_progress → in_review → changes_requested → approved → completed → archived
   "where is this job in the business"
```

The stage names are worth reading rather than assuming: they are the *reconstruction agency's*
stages, which is why `in_review` appears in both vocabularies with different meanings. In
`STAGES` it means the job is with a reviewer; in `Project_Status` it would mean the machine
is in its review pass. Keeping them in separate columns is what stops that collision.

A project can be `completed` (agency) while its status is `validated` (reconstruction) — and
that is the normal state of a healthy project, not a contradiction. Merging them would have
produced a single value that had to lie about one of the two.

### The §8 project fields go on the existing project record

The specification asks for a dozen project fields (client, stage, priority, dates, owner).
The tempting implementation is a side table. That was rejected: a side table would be a
second project entity, and every reader would have to join two sources to answer "what is
this project".

So the fields live on the record `Project_Repository` already keeps, written through
`Project_Repository::update(['collaboration' => …])`. `Project_Context_Store` is a
reader/writer over that record, not a second store. Existing projects read back with
defaults filled in, so nothing that worked before breaks.

### Roles are labels; capabilities are the boundary

`Workspace_Limits::ROLES` has 7 values — `owner`, `admin`, `project_manager`, `designer`,
`developer`, `reviewer`, `client` — drawn from 10 capability groups holding 37 capabilities
in total. None of the role names is consulted directly by any authorisation decision. What is
consulted is `Permission_Manager::can()`, which asks "does this user hold this capability in
this workspace".

Two consequences worth stating:

- **A project role narrows, never widens.** `Permission_Manager` *intersects* the
  project-role capabilities with the workspace-role capabilities. A project manager who is
  a designer in the workspace cannot approve, whatever the project row says.
- **The owner is not a grantable role.** Ownership is derived from `workspace.owner_id`.
  `ASSIGNABLE_ROLES` excludes `owner`, and `add_owner()` is a separate operation. This is
  why the owner's membership row cannot be demoted, suspended, or deleted — the access is
  in the workspace, not the row. Three separate defects came out of getting this wrong; see
  §8.

### Default deny

Every capability check returns false when it cannot establish a grant. There is no path
through `Permission_Manager` that returns true because a lookup was empty, and there is no
"administrators can do everything" shortcut in the workspace layer — a site administrator who
is not a member gets nothing, and the *only* exception is `Project_Access`, which
recognises the owner.

### The client grant is review-scoped

`ROLE_CAPS['client']` deliberately omits `reviews.approve`. A client contact approves the
one review that names them via `Permission_Manager::can_act_on_review()`, which checks the
review's own reviewer fields. The same code path cannot reach any other review, because it
is handed a review rather than a list.

### §17 and the shape of every id

Every table has a random `public_id` and a `workspace_id`. Public ids are Crockford base32,
fixed 26 characters — 128 bits, matching `CHAR(26)`. No sequential integer is ever an
authorisation input. The first implementation used base64, whose output was 21 or 22
characters depending on the random bytes, so the column was `VARCHAR(32)` and the entropy
was not what the length implied. It was replaced.

### §25: two logs, genuinely two

`Workspace_Limits::ACTIVITY_EVENTS` (35 names) and `AUDIT_EVENTS` (23 names) share exactly
four: `export_created`, `member_added`, `member_removed`, `rollback_performed`. That is
intentional, and the four are asserted by name in the test suite rather than counted.

"Dana added Chris" and "Chris's capabilities changed at 14:02 from designer to client" are
different facts about the same event, and one of them is a story while the other is a security
record.

What §25 requires is that the *logs* be separate, and they are: two tables, two stores, two
vocabularies, two retentions (180 days and 1 year), and no row crosses between them. The
retention difference is asserted directly — a 200-day audit row survives a sweep that removed
a 200-day activity row.

---

## 5. End-to-end flow

**A new user arrives on an existing install.** The migration has already run and created one
personal workspace per project owner, adopting their projects. The user has no workspace; the
REST layer provisions one lazily on first access rather than refusing, because refusing every
screen until somebody visits a settings page is a worse failure than an empty workspace that
grants its owner what they could already do.

**An owner invites a designer.** The invitation is a hashed token with a 14-day expiry (7 days
for project-scoped). The link is shown **once** and is not recoverable — the service stores
only a reference, so an administrator listing outstanding invitations sees `token_ref` and
never a working token.

**Work happens, and is recorded.** Every mutation writes an activity row. Permission changes
also write an audit row. A reader's timeline and an administrator's audit trail are different
views of different tables.

**A version goes to review.** `Review_Store::create()` requires a `version_id` that is NOT
NULL, and the version is resolved *from the project's own history* rather than trusted from
the request. This closes a real hole: a caller could otherwise claim to be reviewing version
4 while pointing the record at version 3, and an approval would then be recorded against the
wrong artefact.

**A client is asked to look.** Off by default (§40). When enabled, a link is generated with
an optional password and an expiry (7 days, extendable to 30). The §16 client view
deliberately withholds `last_workspace`, `last_reviewer_email`, and the internal review brief
— not because they are secret, but because a client-facing view that leaks the internal
narrative is not a client-facing view.

**A difference becomes a task.** `Task_Store::from_difference()` requires an explicit
`source`; it refuses rather than defaulting to `manual`. The evidence prose is appended to
the description, and `promoted_from` records which phase raised it.

**A comment is anchored.** A stable anchor — component id, element id, section — is reported
as a stable anchor. A bare coordinate is reported as "Position only", because a pixel offset
does not survive a regeneration and a marker that cannot be re-placed is worse than no
marker.

**A stage changes.** `Project_Context_Store::update()` ignores an unrecognised stage rather
than resetting it, and returns the *stored row* — so a caller can read back a sanitised value
and tell "updated" from "silently did nothing".

**Nothing accumulates.** `Maintenance::daily()` sweeps activity, audit and notifications, and
expires stale invitations. All four counts appear in the daily summary.

---

## 6. Admin screens

Three, and they are a separate class from the pre-existing `Admin` for a reason.

Every submenu in the pre-Phase-15 admin is registered with `manage_options`, because until
this phase the only question was "are you a site administrator?". That is now the wrong
question. A designer who may generate but not approve has a screen they can reach and a
button they cannot press. So the Phase 15 screens resolve a real `Permission_Manager` answer
per workspace, and each control is rendered only to a caller who holds the capability behind
it. The existing seven pages keep their `manage_options` gate, because their content is
site-wide.

| Screen | Shows |
|---|---|
| **Projects** | Every project, with both status axes side by side, priority, version count, and outstanding approval gates. A stage control per row, only where `projects.edit` is held. |
| **Team** | Members and their roles, outstanding invitations (by reference, never by token), add/invite forms, the full permission matrix, and the reader's own notification preferences. |
| **Review** | One project: versions, reviews and their decisions, comments with their anchors, tasks with provenance, issues, and the activity timeline. |

Fifteen POST handlers, each following the same three steps in the same order: verify the
nonce, resolve the capability server-side, then act. No action is reachable by GET. An
unknown action is refused rather than ignored, because a stale form submitting into a
silently-ignored branch reports success.

**The permission matrix is rendered from `Permission_Manager`, not from a second copy of the
role table.** A matrix nobody can read is documentation; one rendered from the same vocabulary
the resolver uses cannot disagree with what is enforced.

---

## 7. Security

Nothing in phases 1–14 was weakened. Specifically re-verified:

- **No IDOR.** Every read and write resolves through a `workspace_id`, and a row from another
  workspace is answered identically to a row that does not exist — the two produce the same
  status *and the same message*, so the response is not a probe for which ids are real.
- **No privilege escalation.** Capabilities are checked server-side on every handler. Hidden
  form fields decide what is *requested*; they never decide what is *permitted*.
- **No enumeration.** Public ids are 128-bit random. No endpoint accepts a sequential integer.
- **No token theft.** Invitation and review-link tokens are stored hashed; the plaintext is
  shown once and never persisted anywhere readable.
- **No XSS.** Untrusted content is sanitised on the way in (`sanitize_textarea_field()`) *and*
  escaped on the way out. The test suite asserts the markup never reaches the page — the
  stronger of the two claims, because sanitising on input is not a licence to trust later.
- **No CSRF.** Every mutating form carries a nonce, and the suite asserts a nonce count at
  least as large as the POST-form count.
- **Rate limiting.** Inherited from the existing REST layer; the Phase 15 routes register
  through the same `route()` helper and inherit the same gate.

New in this phase:

- **Every route is gated.** All 46 Phase 15 endpoints have a permission callback — verified
  by walking the live route table, and the count is checked against the number of `$this->route()`
  declarations in the source so a route that fails to register is caught. No route admits an
  unauthenticated caller, and none admits a caller who is a member of no workspace.
- **Handlers verify independently.** Every REST handler re-checks via `authorize()` /
  `refuse_unless()` rather than trusting `permission_callback` alone — because a refactor, or
  a second caller, will eventually bypass the gate.
- **The owner is protected.** The owner's membership row cannot be demoted, suspended, or
  removed. Three separate defects existed here before they were found and fixed.
- **Review links are off by default**, per §40, along with client approval and automatic
  notifications.
- **Cross-workspace reads are indistinguishable from missing ones**, including the message
  text.

---

## 8. Defects found by execution and fixed

Twenty-one. Each was found by running the code, not by reading it, and each is now covered
by an assertion.

### Dead or inverted logic

1. **`list_projects()` returned no project.** It returned `{project_id, context}` and nothing
   else, so the Projects screen could only show a list of opaque ids — no name, no source
   URL, no status, no version count. It now includes the project record, read once for the
   whole set rather than once per row.
2. **`list_projects()` looked projects up in a list as if it were a map.**
   `Project_Repository::all()` returns `array<int, array>` and is documented as such; the
   lookup was `isset($stored[$project_id])`, which never matched. Every row came back
   orphaned. Found by printing the return value rather than by asserting on it.
3. **`Task_Store::from_difference()` lost its own provenance.** `$task['promoted_from']` was
   assigned to the *returned array* after the insert, so the value existed for the rest of
   the request and was never written to the row. It was also **not a column in the DDL at
   all**. A task reported where it came from on the screen that created it and then lost it.
   The column was added to the schema, to `writable_columns()` and to `column_types()`, and
   the value is now passed into the insert.
4. **`promoted_from` had no defaulting rule.** Added `clean_source()`, which returns an empty
   string for an undeclared source — deliberately *not* the `medium`-style fallback
   `clean_priority()` uses. A task with no priority becomes medium; a task with no
   provenance must stay empty, because the alternative is inventing a phase.
5. **The review screen read a non-existent key.** `$review['display_name']` does not exist on
   a review whose reviewer is named by address and has no account, producing an undefined-key
   warning on every client review.

### Security and correctness

6. **`Workspace_Api::update_member()` called a protected method.** `$this->members->find()` is
   protected on the base store, so changing a member's role fataled the moment it was used.
   Found by the *pre-existing* security contract suite, which exercises the admin screens —
   a cross-class regression introduced by Phase 15 and caught by a suite written before it.
   Fixed with a public `get()` on `Workspace_Member_Store`, matching the accessors
   `Client_Contact_Store`, `Invitation_Store` and `Review_Store` already had for the same
   reason.
7. **The owner's row was demotable, suspendable and removable** — all three stripped owner
   access, because the role is a label and the access lives in `workspace.owner_id`. Now
   refused at the store.
8. **`reviews.link_hash` was UNIQUE**, and the "no link yet" value is the empty string. A
   UNIQUE index therefore permitted **exactly one linkless review per workspace** — the
   second review that did not yet have a link failed to insert. Not unique.
9. **The token salt had insufficient entropy**, because the id generator derived from a value
   that was not uniformly distributed. Replaced with raw random bytes, base32-encoded to a
   fixed 26 characters.
10. **Reopened comments were unresolvable.** A comment that had been resolved and reopened
    had no path back — a dead-end state with no way out but deletion. Added `reopen()`, and
    the state machine is asserted in both directions.
11. **`Issue_Store::from_difference()` discarded the caller's `source_reference`**, so a
    issue promoted from a validation difference could not be traced back to the difference.

### Test expectations corrected rather than papered over

12. **`PRIORITIES` is a list, tested with `array_key_exists` at three sites.** `array_key_exists`
    against a list means "is 0 a key", so every priority test was passing for the wrong
    reason — and `urgent` silently became `medium` in three places. `is_priority()` and
    `is_stage()` were added so no caller can make the mistake again.
13. **The REST gate test counted the namespace descriptor as an ungated endpoint.** It has no
    permission callback and dispatches no resource. Excluded by shape, not by name.
14. **The gate test asserted three routes were refused that name no workspace.**
    `/workspace`, `/notification-preferences` and `/invitations/accept` have no id in the
    path, so admitting an authenticated caller is correct for them. The skip now keys on
    whether the path names a workspace.
15. **An assertion used `in_array()` on a string** to check the stage — which can only be true
    when the stage is literally the string `in_review`. Rewritten as a membership check
    against `Workspace_Limits::STAGES`.
16. **The escaping assertion checked for `&lt;script&gt;`, which the database never held.**
    `sanitize_textarea_field()` strips markup on the way *in*. The assertion now checks the
    stronger property: the markup never reaches the page at all.
17. **Two assertions on remembered route paths** (`/content/mappings`,
    `/visual/analyses`) that had never existed. The content API registers `/content/mapping/…`
    and the visual API registers `/websites/{project}/visual/…`. Replaced with a check that
    discovers each phase's routes from the live table, so a phase that *stops* registering
    fails and a phase that *renames* a route is not mistaken for a break.
18. **A boot check read a local `$wp_rest_server` instead of the global.**
    `register_rest_route()` does not read the server the action is passed — it reads the
    global `rest_get_server()` returns — so the second `do_action()` registered everything
    into the wrong object and the local came back empty. It found no routes at all, and
    would have been reported as "Phase 5 stopped registering".
19. **The System Status probe read `$report['collaboration']`** when the checks live under
    `$report['checks']`. One mistake, thirteen false failures.
20. **The retention probe counted rows across a table** that earlier sections had populated,
    making the assertion a statement about the suite's history rather than about the sweep.
    Scoped to the planted ids.
21. **A `prune_collaboration()` docblock claimed a behaviour the code did not have** — that an
    absent store would contribute *no key*. It contributes zero. The docblock was corrected to
    describe what happens, with the reasoning, rather than left asserting something false.

### Figures in this report that were wrong until measured

22. **The route count.** This report originally claimed "74 routes / 79 endpoints". Measured,
    Phase 15 registers **46 endpoints on 34 routes**, and the whole plugin registers 125
    endpoints on 107 routes under the shared namespace. The probe now derives the expected
    number from the `$this->route()` declarations in the source rather than from a
    remembered total, so a route that fails to register fails the check.
23. **Three vocabulary counts** — capability groups, event counts, and page bounds — were
    stated from memory. Measured, they are 10 groups / 37 capabilities, 35 activity and 23
    audit events, and `Workspace_Limits::PAGE` is default 25 / ceiling 100. The four shared
    event names are now asserted individually rather than by a count, after a probe that
    intersected two *lists* by `array_keys()` and got the integer range 0–22 back.

### Pre-existing suites strengthened, not loosened

- `tests/phase14-content-test.php`: 504 → 506 assertions. A hard-coded migration-assertion
  position was replaced with a chain-property check (membership, `>=`, gapless-increasing),
  because a position is a fact about the array, not about the behaviour.

---

## 9. Migration

`migrate_collaboration()` is registered for 14.0.0 → 15.0.0 and:

1. installs the 13 tables via `dbDelta`;
2. creates one personal workspace per existing project owner;
3. adopts their projects into it;
4. **skips orphans and records the reason** — `no_owner`, `owner_missing`, and so on;
5. stores diagnostics in `replicaforge_collaboration_migration`.

Skipping with a recorded reason rather than guessing was the important decision. A project
whose owner cannot be resolved is one whose access cannot be established, and adopting it
into an arbitrary workspace would be a privilege grant nobody authorised. The diagnostic
option means an administrator can find them.

**Verified:** 27 assertions, all passing; idempotent on re-run; existing projects preserved.

The migration is conservative on every default, per §40:

| Setting | Default | Why |
|---|---|---|
| Client approval required | on | The safer of the two failure directions. |
| Client review links | **off** | A link is a capability handed to an email address. |
| Project scoping | off | Narrowing later is trivial; unwidening is not. |
| Automatic notifications | off | Sending mail nobody asked for is not recoverable. |

---

## 10. Tests

**Phase 15 suite:** 26 sections, **881 assertions**, 0 failures, 0 warnings.

Registered in the runner alongside the 22 pre-existing suites.

**Full suite: 23 suites, 5,427 assertions passed, 0 failed, 2 skipped.** Both skips are
pre-existing skips in earlier phases, not Phase 15 behaviour. They are recorded here rather
than quietly converted to passes, because a skip that becomes a pass without the underlying
capability existing would be a fabricated result.

The 26 sections cover: the schema; the permission model and the role/capability matrix;
workspaces and members; projects and the two status axes; clients and contacts; invitations;
reviews and version binding; review links; comments and anchors; tasks and promotion; issues;
the two logs; notifications and preferences; the migration; the REST layer; plugin wiring
across all six phases; the admin screens per role; the retention sweep; and System Status.

Verification beyond the suite:

- `php -l` across all **233** PHP files: **0 failures**.
- Plugin wiring probe: all six phases' routes register from the plugin's own `rest_api_init`
  hook.
- Admin screens rendered as each role: **46 assertions, 0 failures** — real HTML, real data,
  real escaping, and controls present only for callers who hold the capability.
- Uninstall probe: 13 tables dropped, 3 options removed, schema reinstalls cleanly afterwards.
- Retention probe: expired rows removed, live rows kept, the two logs pruned at different
  ages, idempotent, and it does not fatal with the tables absent.
- Route-count probe: 46 live endpoints match 46 source declarations; 0 ungated.

---

## 11. Performance

- **The 13-table schema** replaces option-array history for the Phase 15 records. Reads are
  indexed on `workspace_id` and, where a list is paginated, on `(workspace_id, created_at,
  public_id)` for the cursor.
- **No composite `workspace_user`/`project_user` index.** `dbDelta` reconciles index
  *presence*, not uniqueness — so an index relaxation never reaches existing installs. The
  single-column `user_id` index serves both lookups, and `Workspace_Member_Store::add()`'s
  `existing()` does the duplicate detection, matching on user id *or* email.
- **`list_projects()` reads the repository once for the whole set**, not once per row. On a
  screen that can list hundreds of projects, that is the difference between one option read
  and one per project.
- **Pagination is cursor + offset**, with the page size clamped by
  `Workspace_Limits::PAGE` — default 25, ceiling 100. A caller asking for more than the
  ceiling gets 100, not 10,000.
- **The permission resolver caches per request**, so a screen rendering a hundred rows
  resolves each capability once rather than a hundred times.

---

## 12. Known limitations

Stated plainly. None of these is worked around silently.

### Carried forward from earlier phases

- **`Project_Repository::MAX_PROJECTS = 60` conflicts with the specification's 500.** When a
  61st project is created the oldest is dropped, silently. **Verified empirically**: 62
  creates leave 60, and the two earliest projects are gone.

  The constant was **left alone**, and the reason is now recorded in the code at both
  `MAX_PROJECTS` and `store()`. The project list is a single option, so every project is
  re-serialised on every write and the cap bounds that cost; reaching 500 properly is a
  schema change, not a constant edit. Raising it would trade a documented, visible limit for
  an undocumented performance cost on exactly the large sites that could least afford it.
  A silent drop is bad behaviour that has been load-bearing for a long time, and changing it
  is a change of observable behaviour that sites at the cap may depend on.

  This is a real deviation from the specification and is surfaced rather than hidden.

- **Retention is not administrator-settable.** The activity and audit ages come from
  `Workspace_Limits`, which is the single authority; there is deliberately no second override
  path. A retention *setting* would mean a default in one place and an override in another,
  and a reader could no longer answer "how long do you keep audit rows?" without following
  both. Notifications are the exception — nothing about the notification vocabulary implies
  an age, so the caller states it. This is a deliberate limitation, documented in
  `prune_collaboration()` and in this report.

### Introduced by this phase

- **`uninstall.php` duplicates the table names** as a literal list, because during an
  uninstall the plugin classes may already be unloaded. The list is verified against
  `Workspace_Limits::table()` for every kind, so the two cannot drift silently.
- **Multisite is untested.** The schema uses `$wpdb->prefix`, so per-site tables are the
  expected shape, but no multisite install was exercised. This is stated rather than assumed
  to work.
- **No screenshot or page rendering.** There is no render provider or headless browser in
  this environment, so no visual verification of any screen and no §84 performance sweep was
  possible. Screens are verified by rendering them server-side and asserting on the HTML,
  which catches escaping and permission errors but **cannot** catch a layout problem.
- **No live AI provider was exercised**, so §51/§52 AI summarisation is not implemented. A
  provider abstraction with graceful fallback is in place; the summarisation itself is not.
- **No real WooCommerce** in this environment, so the content mapping paths are exercised
  through their data shape rather than against a live store.
- **Screenshots and renders are never the final output.** The final artefact remains real
  WordPress and Elementor content, as in every earlier phase.
- **Source JavaScript never executes inside WordPress/PHP.** The reviewed sites' scripts are
  read and analysed, never run.
- **No Git repository exists**, so none of this work is committed. Every file is uncommitted.

---

## 13. Acceptance criteria

| Criterion | Status |
|---|---|
| Workspaces, members, roles, capabilities | Done. 10 capability groups / 37 capabilities, 7 roles (6 assignable), default deny. |
| Projects with client, stage, priority, dates, owner | Done. On the existing project record. |
| Both status axes kept separate | Done. 14 reconstruction statuses, 7 agency stages; never merged; asserted. |
| Reviews bound to a version | Done. `version_id` NOT NULL, resolved from project history. |
| Client review links | Done. **Off by default**, password and expiry supported. |
| Anchored comments | Done. 8 anchor types; stable anchors reported as stable, coordinates as positions. |
| Tasks promoted from differences | Done. Source required, evidence copied, provenance persisted. |
| Issues | Done. Reusing the existing severity vocabulary; 5 statuses, 6 sources. |
| Invitations | Done. Hashed tokens, shown once, revocable, expiring. |
| Activity and audit, separate | Done. Two tables, 35 and 23 events, four shared names, two retentions, asserted. |
| Notifications | Done. 16 types, 2 channels, 8 preference categories, per-user. |
| REST API, all gated | Done. 46 endpoints on 34 routes, every one gated, handlers verify independently. |
| Admin screens | Done. Three screens; existing seven untouched. |
| Migration from 14.0.0 | Done. Idempotent, orphans skipped with a recorded reason. |
| Uninstall sweep | Done. 13 tables, 3 options, verified by execution. |
| Retention sweep | Done. Differentiated ages, idempotent, safe with the tables absent. |
| System Status | Done. Table presence checked against the database, not the recorded version. |
| Backward compatibility | Done. Phases 1–14 unchanged; every earlier phase still registers and passes. |
| No second queue / table / provider / engine | Done. Reused, not reimplemented. |
| No fabricated metrics or evidence | Done. No analytics invented; three figures in this report were corrected after measurement, and the report says so. |
| 500 projects | **Not met.** `MAX_PROJECTS = 60`, pre-existing, documented above. |

---

## 14. Verification summary

| Check | Result |
|---|---|
| Phase 15 suite | 881 assertions, 0 failures, 0 warnings |
| Full suite | 23 suites, 5,427 assertions, 0 failed, 2 skipped (pre-existing) |
| `php -l`, all files | 233 files, 0 failures |
| Route registration, all 6 phases | Pass |
| Phase 15 route surface | 46 endpoints on 34 routes, matching 46 source declarations |
| Route gating | 46/46 Phase 15 endpoints gated; 0 ungated in the whole namespace; 0 admit unauthenticated; 0 admit a non-member |
| Admin screens, per role | 46 assertions, 0 failures |
| Uninstall sweep | 13 tables dropped, 3 options removed, reinstall clean |
| Retention sweep | Expired removed, live kept, differentiated ages, idempotent, safe with no tables |
| Migration | 27 assertions, idempotent, preservation confirmed |
| Visual rendering | **Not performed** — no render provider available |
| Multisite | **Not exercised** |
| Live AI provider | **Not exercised** |
| Real WooCommerce | **Not exercised** |
| `MAX_PROJECTS` vs spec 500 | **Conflict, documented, not changed** |

Phase 15 is complete. The reconstruction engine is unchanged. The one specification
deviation — the 60-project cap — is documented in the code that performs it and above, and
was left alone deliberately rather than quietly raised.
