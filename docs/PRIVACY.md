# ReplicaForge Privacy

## 1. Summary

| Question | Answer |
|---|---|
| Does ReplicaForge send anything anywhere without being asked? | No. |
| Is there telemetry? | No. None is implemented, and Phase 7 introduced none. |
| What leaves the site, and when? | Only a design representation, and only when a person asks for an AI reconstruction. |
| Is an API key ever sent to a browser? | No. |
| Does uninstalling delete my pages or media? | No. |

## 2. What leaves the site

**By default: nothing.** Analysis, generation, validation, and correction all run on
the site.

**When a person configures an AI provider and runs the AI stage**, the Phase 2 design
representation and the source URL are sent to that provider. The representation is a
derived structure — section types, component roles, design tokens, typography
measurements, layout ratios. It is not the page's HTML.

Redaction runs before the send. Removed:

- API keys, tokens, and credentials, including 14 vendor key shapes
- Authorization and proxy headers, bearer and basic tokens
- WordPress and session cookies
- Private IP addresses and internal hostnames
- Server filesystem paths
- Database credentials and DSNs
- Environment secrets under secret-looking key names

## 3. What the AI provider receives

| Field | Contents |
|---|---|
| System instructions | ReplicaForge's own. Never website text. |
| Design representation | The derived structure above, redacted |
| Source URL | The public page address |
| Website content | Quoted, labelled untrusted, and separated from the instructions |

Website content is treated as data. An instruction inside a page is page content.
See `docs/AI.md`.

## 4. What is stored locally

| Data | Where | Bound |
|---|---|---|
| Analysis, design, specification | Transients | TTL-limited |
| Validation records | Transients | TTL-limited, cleaned daily |
| Correction plans and snapshots | Transients | TTL-limited, cleaned daily |
| Job records | `replicaforge_jobs` option | 100 records |
| Log entries | `replicaforge_log` option | 500 entries, 5000 ceiling |
| Generated document | Elementor's own post meta | The user's draft |
| AI API key | `replicaforge_ai_settings` option | Never rendered, never exported |

## 5. What is never stored

- A raw API key in a log, a job record, or an export
- A session cookie
- An authorization header
- Website JavaScript, in any form
- Website credentials

Every log entry, job parameter, checkpoint, and diagnostic export passes through
`Data_Redactor` before it is written, and exports are redacted again on the way
out.

## 6. What is never sent to a browser

- The AI API key
- Any configuration value
- Any filesystem path
- Any stack trace

The admin configuration is JSON-encoded with `JSON_HEX_TAG`, `JSON_HEX_AMP`,
`JSON_HEX_APOS`, and `JSON_HEX_QUOT`, so server data cannot terminate the script
element or the string it sits in.

## 7. The diagnostic export

**System Status → Logs → Export log** produces a newline-delimited JSON file. It is
redacted again at export, so an entry captured before the redactor existed is still
not exported. It is intended to be attached to a support request.

## 8. Cookies

ReplicaForge sets no cookies. It uses the WordPress REST nonce, which is tied to the
existing session and adds nothing.

## 9. Third-party code

None. ReplicaForge bundles no third-party JavaScript, no external font, no CDN
dependency, and no analytics. The only outbound connections it makes are:

1. The website the person asked to analyze.
2. The AI provider the person configured, and only when they ran the AI stage.
3. The render provider, if one is configured, and only during a visual comparison.

## 10. Data subject requests

ReplicaForge stores no personal data about site visitors. It analyzes a public
frontend and stores a derived structure. The job records reference a public URL and
the WordPress user id of the person who ran the job, which is the same identifier
WordPress already holds for that person's own actions.

Deleting a job record removes its parameters, its checkpoints, and its stage
payloads. Deleting a draft removes it through WordPress, as it would for any page.

## 11. Uninstall

`uninstall.php` removes ReplicaForge's options, transients, and post meta, which
includes the stored AI key. It never deletes a post, an attachment, or anything
under Elementor's keys.

## 12. Compliance posture

ReplicaForge is a frontend analysis and reconstruction tool. It does not:

- bypass authentication or access control on any site
- access private systems
- copy backend code
- execute third-party website code
- collect visitor data
- transmit anything to a ReplicaForge-operated service, because there is none

## 13. Content and rights

ReplicaForge analyzes **frontend information** and produces editable structures. It
does not copy a website's backend, its database, or its authenticated content.

**Public accessibility does not automatically grant reproduction rights.** Logos,
images, text, product descriptions, and other assets on a site may be protected by
copyright, trademark, or other law. ReplicaForge does not provide legal advice about
any individual site, and it does not determine whether a particular use is
permitted.

**You are responsible for ensuring you have permission to reuse any content or asset
you import.** The asset import option is off by default for this reason, and the
generation report lists what was imported, what was referenced rather than copied,
and what was blocked.

If you are unsure whether a use is permitted, ask someone qualified to advise you on
it before publishing anything.

---

## Phase 10: what is stored, and what is deliberately not

Phase 10 adds ten storage keys and no database table. See `ARCHITECTURE.md` for the
full table; this section is about the parts that were harder to decide.

### No table, on purpose

The plugin has never had a custom table. Usage accounting looked like the case
that would change that — a growing event stream, per user, queried by period — and
it did not. What the meter actually needs is four integers per user per month, and
the "recent" list needs a short tail. That is four integers and a bounded array
in user meta, and a table would have meant a migration, an index strategy, a
uninstall path, and multisite questions, to solve a problem meta already solves.

The cost is stated rather than hidden: a period that falls out of use leaves a
small orphaned user-meta key, and the existing retention pass in `Maintenance`
already prunes ReplicaForge meta.

### The audit ring is not a compliance archive

`replicaforge_audit_log` holds the **last 500 entries**. A burst of denials can
push an earlier event out of the ring. It is a recent-history record for an
administrator, not an archive that will still answer a question in two years. A
retention period and a size-agnostic store would be the answer if anyone needs
that, and are Phase 11 work.

The ring is bounded deliberately: a hostile client that triggers a denial on every
request must not be able to grow an option without limit.

### The client marker is a digest, not an address

An audit entry records a salted HMAC of the requesting address, truncated to 16
hex characters. What an administrator wants from an audit entry is "was this the
same client as last time", so a raw IP would be more personal data than the entry
needs. The salt is `LOGGED_IN_SALT` (falling back to `AUTH_SALT`), so the marker
cannot be turned back into an address and cannot be matched against a log file from
somewhere else. A site with neither salt configured records nothing rather than
something weakly recorded.

### Nothing from the source website is stored here

No Phase 10 key holds a source URL, page content, an asset, an AI prompt, or an AI
response. Usage metadata is an allowlist of four short keys
(`outcome`, `result`, `reason`, `source`), each truncated; the `source` field is
reduced to a machine token because a URL would be redundant — it is already on the
project — and one more copy of a user's browsing target to retain.

`Audit_Log` declares, per event, exactly which context keys it may carry. A key
not declared for that event is dropped. That is stricter than filtering by name
alone: a caller cannot smuggle a value into a declared event under an innocent key.

### No telemetry

Phase 10 sends nothing anywhere. There is no analytics, no crash reporting, no
heartbeat, and no usage ping. The only outbound requests ReplicaForge has ever
made are to the website a user asked it to analyze, and to an AI provider the user
configured.

### What a plan record does not contain

`replicaforge_site_plan` and `replicaforge_license_local` are configuration an
administrator set. A plan has no price, a license record has no plan id, and
neither is ever sent to a third party. There is no third party.

### The uninstall path

`uninstall.php` already removes ReplicaForge options, transients, and post meta by
prefix. `replicaforge_audit_log`, `replicaforge_plan_definitions`,
`replicaforge_trial_settings`, `replicaforge_site_plan`, and
`replicaforge_license_local` all begin with `replicaforge_`, so they are removed by
that existing sweep — **but the user-meta keys are not**, because the existing
sweep covers options and post meta only. Phase 10's user-meta keys
(`replicaforge_usage_*`, `replicaforge_usage_reserved_*`,
`replicaforge_usage_recent_*`, `replicaforge_onboarding`, `replicaforge_trial_*`)
are removed by `Usage_Manager::forget()` and by role removal, and
`Capabilities::revoke_all_roles()` is called from the uninstall path.

**Known gap:** a full uninstall does not sweep user meta for these keys. The keys
hold counts and acknowledgement flags, not content, so the residue is small, but
it is a real gap and it is listed in `PHASE-10-COMPLETION-REPORT.md` §7.
