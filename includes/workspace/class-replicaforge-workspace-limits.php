<?php
/**
 * Phase 15: the collaboration vocabulary and bounds.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Every role, capability, status, event name, and bound the collaboration layer uses.
 *
 * ### Why this file exists, and what it refuses to define
 *
 * The same reason `Content_Limits` and `Visual_Limits` exist. A permission string, an
 * event name or a severity spelled independently in the resolver, the REST layer, the
 * activity recorder and the admin screen is a rename that becomes a silent behaviour
 * change in whichever copy was missed. §58 asks for a permission matrix, and a matrix can
 * only be authoritative if exactly one table produces it.
 *
 * **Roles are labels, not the boundary.** §4 says so explicitly, and it is the single most
 * important rule in this file. `designer` is a convenience for a human reading a screen;
 * nothing anywhere asks "is this user a designer?". Every check is
 * `Permission_Manager::can( $user, 'correction.apply' )`, and {@see self::ROLE_CAPS} is
 * only the table that decides which labels imply which capabilities. That indirection is
 * the difference between "the Designer role cannot approve" being a rule and being a
 * coincidence.
 *
 * **Reused vocabularies, not restated:**
 *
 * - Ownership states are **read** from `Sync_Conflict_Detector::OWNERSHIP`, exactly as
 *   Phase 14 does. Two lists would let the sync layer and the collaboration layer disagree
 *   about who owns a field, and the wrong one would win when a source page changed.
 * - Issue severity is **read** from `Validation_Limits::SEVERITIES`. §21 forbids a second
 *   severity system, and a review issue promoted from a validation difference must keep
 *   the difference's severity or the promotion means nothing.
 * - Reconstruction status is **not** restated. See {@see self::stage()} below.
 */
final class Workspace_Limits {

	/**
	 * Collaboration schema version.
	 */
	const SCHEMA_VERSION = '15.0';

	/* ---------------------------------------------------------------------
	 * Capabilities
	 * ------------------------------------------------------------------ */

	/**
	 * The §5 capability vocabulary.
	 *
	 * A closed list, grouped by the resource it governs. Grouping is not decoration: the
	 * permission matrix in the documentation is generated from these groups, and a
	 * capability with no group would be invisible in it — which is how a capability
	 * quietly ends up checked by nothing.
	 *
	 * Naming is `resource.action`, never a verb alone, so `members.invite` cannot be
	 * confused with `clients.invite` in a log or a grep.
	 *
	 * @var array<string, array<int, string>>
	 */
	const CAPABILITY_GROUPS = array(
		'workspace'  => array( 'workspace.view', 'workspace.manage', 'workspace.settings' ),
		'members'    => array( 'members.view', 'members.invite', 'members.remove', 'roles.manage' ),
		'clients'    => array( 'clients.view', 'clients.create', 'clients.edit', 'clients.delete' ),
		'projects'   => array( 'projects.view', 'projects.create', 'projects.edit', 'projects.delete', 'projects.archive', 'projects.manage_members' ),
		'engine'     => array( 'analysis.run', 'ai.run', 'generation.run', 'validation.run', 'correction.review', 'correction.apply' ),
		'content'    => array( 'content.view', 'content.map', 'content.apply' ),
		'sync'       => array( 'sync.view', 'sync.run', 'sync.approve' ),
		'comments'   => array( 'comments.create', 'comments.resolve' ),
		'reviews'    => array( 'reviews.view', 'reviews.create', 'reviews.approve', 'reviews.reject' ),
		'export'     => array( 'exports.create', 'exports.download' ),
		// Phase 19. Declared here rather than in `Template_Limits` alone because
		// `Permission_Manager::can()` validates against THIS list and nothing else. A
		// capability that exists only in the feature's own list would be refused by the
		// manager — so the template UI would show a button that always 403s, which is a
		// worse failure than the capability not existing.
		'templates'  => array(
			'templates.view',
			'templates.create',
			'templates.edit',
			'templates.delete',
			'templates.export',
			'templates.import',
			'templates.share',
			'templates.install',
			'design_systems.manage',
		),
		// Phase 20. Same reasoning as the templates group above, and it is load-bearing:
		// `Permission_Manager::can()` validates against THIS list and nothing else. A
		// Phase 20 capability declared only in `Platform_Limits` would be refused, so the
		// developer console would render a working "Revoke" button that always 403s — the
		// same failure Phase 19 documented for template capabilities.
		//
		// Six capabilities for five surfaces, split by what the operator can *change*.
		// Credentials and webhooks are the two things that reach outside this site, so each
		// gets its own and neither is implied by the other. Reading credentials is separated
		// from managing them because an auditor who may see that a key exists has no need to
		// be able to mint one.
		//
		// Extensions are *disabled* rather than granted — see `Extension_Registry::set_state()`
		// — so `api.extensions.manage` covers the switch. Automations run as the person who
		// created them, so creating one is gated on the capability for the action it performs
		// and this capability only governs the rule itself.
		'api'         => array(
			'api.credentials.read',
			'api.credentials.manage',
			'api.webhooks.manage',
			'api.extensions.manage',
			'api.automations.manage',
			'api.events.read',
		),
	);
	/* ---------------------------------------------------------------------
	 * Roles
	 * ------------------------------------------------------------------ */

	/**
	 * The §4 roles.
	 *
	 * `owner` is not a grantable role: it is derived from `workspace.owner_id`, and
	 * {@see Permission_Manager} resolves it separately. A stored `owner` row would let a
	 * demoted owner keep full control, or a promoted member erase the only owner.
	 *
	 * @var array<int, string>
	 */
	const ROLES = array( 'owner', 'admin', 'project_manager', 'designer', 'developer', 'reviewer', 'client' );

	/**
	 * Which roles may be assigned to a member.
	 *
	 * `owner` is absent, deliberately, and the list is a whitelist rather than "anything
	 * not owner": an unknown role in a stored record would otherwise be granted nothing
	 * and reported as a member who can see nothing, which is indistinguishable from a bug.
	 *
	 * @var array<int, string>
	 */
	const ASSIGNABLE_ROLES = array( 'admin', 'project_manager', 'designer', 'developer', 'reviewer', 'client' );

	/**
	 * Capability sets per role.
	 *
	 * Deliberately *not* cumulative from a lower role. `designer` and `developer` overlap
	 * on the engine capabilities, and a cumulative ladder would make "what can a reviewer
	 * do" a function of ordering rather than of intent.
	 *
	 * `client` is the interesting one: it can comment and can approve **only a review it
	 * was given**, never `reviews.approve` in the workspace sense. That distinction is
	 * enforced by `Permission_Manager`, which treats review-scoped grants separately from
	 * workspace capabilities — see the class docblock there. Listing `reviews.approve`
	 * here without that caveat would be a lie, so it is absent and the review path grants
	 * it explicitly.
	 *
	 * @var array<string, array<int, string>>
	 */
	const ROLE_CAPS = array(
		'owner'            => array(), // Resolved separately: the owner holds every capability.
		'admin'            => array(
			'workspace.view', 'workspace.settings',
			'members.view', 'members.invite', 'members.remove', 'roles.manage',
			'clients.view', 'clients.create', 'clients.edit', 'clients.delete',
			'projects.view', 'projects.create', 'projects.edit', 'projects.archive', 'projects.manage_members',
			'analysis.run', 'ai.run', 'generation.run', 'validation.run', 'correction.review', 'correction.apply',
			'content.view', 'content.map', 'content.apply',
			'sync.view', 'sync.run', 'sync.approve',
			'comments.create', 'comments.resolve',
			'reviews.view', 'reviews.create', 'reviews.approve', 'reviews.reject',
			'exports.create', 'exports.download',
			'templates.view', 'templates.create', 'templates.edit', 'templates.delete',
			'templates.export', 'templates.import', 'templates.share', 'templates.install',
			'design_systems.manage',
			// Phase 20. Full platform control, including `api.credentials.manage` and
			// `api.extensions.manage`. Both are consequential in a way the other grants are
			// not: a credential outlives the session that issued it, and an extension runs
			// inside the request. An administrator is trusted with both; nobody else is.
			'api.credentials.read', 'api.credentials.manage',
			'api.webhooks.manage', 'api.extensions.manage',
			'api.automations.manage', 'api.events.read',
		),
		'project_manager'  => array(
			'workspace.view',
			'members.view',
			'clients.view', 'clients.create', 'clients.edit',
			'projects.view', 'projects.create', 'projects.edit', 'projects.archive', 'projects.manage_members',
			'analysis.run', 'ai.run', 'generation.run', 'validation.run', 'correction.review', 'correction.apply',
			'content.view', 'content.map', 'content.apply',
			'sync.view', 'sync.run', 'sync.approve',
			'comments.create', 'comments.resolve',
			'reviews.view', 'reviews.create', 'reviews.approve', 'reviews.reject',
			'exports.create', 'exports.download',
			'templates.view', 'templates.create', 'templates.edit', 'templates.delete',
			'templates.export', 'templates.import', 'templates.share', 'templates.install',
			'design_systems.manage',
			// Phase 20. The API surface is split the same way the capability group is: a
			// project manager runs agency automations and watches webhooks, but does not
			// mint credentials and does not load code. A designer can see the events
			// about their own work and nothing more.
			//
			// `api.credentials.manage` is deliberately absent from `project_manager`. It is
			// the one capability that produces a bearer token that survives the browser
			// session, and an agency should require two people to be able to issue one.
			'api.credentials.read', 'api.webhooks.manage',
			'api.automations.manage', 'api.events.read',
		),
		'designer'         => array(
			'workspace.view', 'projects.view',
			'analysis.run', 'generation.run', 'validation.run', 'correction.review', 'correction.apply',
			'content.view', 'content.map',
			'comments.create', 'comments.resolve',
			'reviews.view', 'reviews.create',
			/*
			 * A designer builds and uses templates. They are not granted `templates.share`
			 * (publishing to the whole workspace is a decision for someone accountable for
			 * it), `templates.import` (an imported package is untrusted input, and admitting
			 * untrusted input is not a design task), `templates.delete` (destructive), or
			 * `design_systems.manage` (re-points every template at once — see
			 * §8, which requires a confirmation and a preview of what is affected).
			 */
			'templates.view', 'templates.create', 'templates.edit', 'templates.export', 'templates.install',
			// Phase 20. Read-only by design. A designer sees the events about the work they
			// did; they cannot create a subscription that sends data to a third party, and
			// they cannot mint a credential. `developer` is the same, and neither gets
			// `api.webhooks.manage` — a designer debugging "why is my page not finishing"
			// needs the event, not the ability to wire their own receiver.
			'api.events.read',
		),
		'developer'        => array(
			'workspace.view', 'projects.view',
			'analysis.run', 'ai.run', 'generation.run', 'validation.run', 'correction.review', 'correction.apply',
			'content.view', 'content.map', 'content.apply',
			'sync.view', 'sync.run',
			'comments.create', 'comments.resolve',
			'reviews.view', 'reviews.create',
			'exports.create',
			// Same reasoning as `designer`: build and use, not publish or import.
			'templates.view', 'templates.create', 'templates.edit', 'templates.export', 'templates.install',
			// Phase 20. Read-only by design. A designer sees the events about the work they
			// did; they cannot create a subscription that sends data to a third party, and
			// they cannot mint a credential. `developer` is the same, and neither gets
			// `api.webhooks.manage` — a designer debugging "why is my page not finishing"
			// needs the event, not the ability to wire their own receiver.
			'api.events.read',
		),
		'reviewer'         => array(
			'workspace.view', 'projects.view',
			'validation.run',
			'comments.create', 'comments.resolve',
			'reviews.view', 'reviews.create', 'reviews.approve', 'reviews.reject',
			// Read-only. A reviewer must be able to see which template a page was built
			// from, because "was this page made from our standard hero?" is a review
			// question, but changing a template is not a review action.
			'templates.view',
		),
		'client'           => array(
			// Read of the *review surface* only. `reviews.view` here means "may open the
			// review they were given", which Permission_Manager scopes to that review.
			//
			// No template capability at all. A client sees a rendered page; the template
			// behind it is an implementation detail, and exposing it would hand a client a
			// read path into the design system of a site they are reviewing rather than
			// running. `client_review` visibility exists for the *pages* a client reviews,
			// not for the template library.
			'reviews.view', 'comments.create',
		),
	);

	/**
	 * The §10 project-level roles.
	 *
	 * A separate, smaller vocabulary, because a project role may only *narrow* a
	 * workspace role (see `Permission_Manager`). Allowing a project role to grant
	 * something the workspace role lacks would make project membership a privilege
	 * escalation path — the exact bug §41 names.
	 *
	 * @var array<int, string>
	 */
	const PROJECT_ROLES = array( 'lead', 'contributor', 'reviewer', 'observer', 'client_contact' );

	/**
	 * Capabilities a project role may grant, as a narrowing of the workspace role.
	 *
	 * `lead` is not "can do anything in the project": it cannot grant what it does not
	 * have. A project lead of the `client` workspace role is still a client.
	 *
	 * @var array<string, array<int, string>>
	 */
	const PROJECT_ROLE_CAPS = array(
		'lead'            => array(
			'projects.view', 'projects.edit', 'projects.manage_members',
			'analysis.run', 'generation.run', 'validation.run', 'correction.review', 'correction.apply',
			'content.view', 'content.map', 'content.apply',
			'sync.view', 'sync.run',
			'comments.create', 'comments.resolve',
			'reviews.view', 'reviews.create', 'reviews.approve', 'reviews.reject',
		),
		'contributor'     => array(
			'projects.view', 'analysis.run', 'generation.run', 'validation.run', 'correction.review', 'correction.apply',
			'content.view', 'content.map',
			'comments.create', 'comments.resolve', 'reviews.view',
		),
		'reviewer'        => array( 'projects.view', 'comments.create', 'comments.resolve', 'reviews.view', 'reviews.approve', 'reviews.reject' ),
		'observer'        => array( 'projects.view', 'reviews.view' ),
		'client_contact'  => array( 'reviews.view', 'comments.create' ),
	);

	/* ---------------------------------------------------------------------
	 * Project stage
	 * ------------------------------------------------------------------ */

	/**
	 * The §8 agency workflow stages.
	 *
	 * ### Why this is not `Project_Status`
	 *
	 * `Project_Status::STATUSES` is the **reconstruction pipeline** — new, analyzing,
	 * analyzed, planning, generating, validating, needs_correction, monitoring,
	 * sync_review, syncing, completed, failed, archived. It has a transition table, a
	 * health model, and fourteen phases of code reading it.
	 *
	 * §8 asks for a different set — draft, analyzing, reconstructing, review, changes
	 * requested, approved, completed, archived, failed — which is an *agency* view: where a
	 * project sits in a human workflow, who is waiting on whom.
	 *
	 * Overloading one field with both would destroy the pipeline. `Project_Status` is
	 * driven by the engine (a generation completes it moves to `generated`); the stage is
	 * driven by people (a client approves it, the stage moves to `approved`). They
	 * disagree legitimately and often: a project can be `stage = in_review` while
	 * `status = monitoring`, because the client is looking at a page the engine considers
	 * settled.
	 *
	 * So there are two axes, both reported, and neither overwrites the other.
	 *
	 * @var array<string, string>
	 */
	const STAGES = array(
		'draft'              => 'Draft',
		'in_progress'        => 'In Progress',
		'in_review'          => 'In Review',
		'changes_requested'  => 'Changes Requested',
		'approved'           => 'Approved',
		'completed'          => 'Completed',
		'archived'           => 'Archived',
	);

	/**
	 * Stages that close a project.
	 *
	 * `completed` is a *stage* the user sets; §39 separately gates whether the project
	 * *may* be completed. Both facts are reported, and the gate is the one that is enforced.
	 *
	 * @var array<int, string>
	 */
	const TERMINAL_STAGES = array( 'completed', 'archived' );

	/**
	* Project and task priorities.
	*
	* A **list**, not a map, so its membership test is `in_array()` and *not*
	* `array_key_exists()`. Two call sites got that wrong, and every `urgent` value
	* was silently rejected in favour of `medium` - worse than an error, because the
	* guard returned false instead of raising and the code carried on with a default.
	* Nothing reported a wrong answer; it reported a plausible one.
	*
	* {@see self::is_priority()} exists so no caller has to make that distinction.
	* {@see self::TASK_PRIORITIES} *is* a map (name => urgency rank), because a task
	* list has to be sortable by urgency. The two shapes are deliberate, and the
	* helper hides the difference.
	*
	* @var array<int, string>
	 */
	const PRIORITIES = array( 'low', 'medium', 'high', 'urgent' );

	/* ---------------------------------------------------------------------
	 * Reviews
	 * ------------------------------------------------------------------ */

	/**
	 * The §14 review statuses.
	 *
	 * @var array<int, string>
	 */
	const REVIEW_STATUSES = array( 'pending', 'in_review', 'changes_requested', 'approved', 'rejected', 'cancelled' );

	/**
	 * Review types.
	 *
	 * `internal` and `client` differ in *who* may act and in what they can see, not in
	 * what a review is. A `client` review never exposes the validation report's internals
	 * or the source crawl; it exposes a rendered preview and the difference summary.
	 *
	 * @var array<int, string>
	 */
	const REVIEW_TYPES = array( 'internal', 'client' );

	/**
	 * Review statuses that are still open.
	 *
	 * @var array<int, string>
	 */
	const OPEN_REVIEW_STATUSES = array( 'pending', 'in_review', 'changes_requested' );

	/* ---------------------------------------------------------------------
	 * Comments
	 * ------------------------------------------------------------------ */

	/**
	 * The §19 comment statuses.
	 *
	 * @var array<int, string>
	 */
	const COMMENT_STATUSES = array( 'open', 'resolved', 'reopened' );

	/**
	 * What a comment can anchor to, §18.
	 *
	 * The reference is a *stable* identifier where one exists — an Elementor element id,
	 * a Phase 13 component id — and only a viewport region when nothing stable does. §20
	 * is explicit that coordinates are supplemental, and this list is where that is
	 * enforced: a comment with only pixel coordinates is anchored to the version it was
	 * made against, never to "the page as it looks now".
	 *
	 * @var array<int, string>
	 */
	const COMMENT_ANCHORS = array( 'project', 'page', 'version', 'section', 'component', 'element', 'difference', 'content_mapping' );

	/* ---------------------------------------------------------------------
	 * Tasks and issues
	 * ------------------------------------------------------------------ */

	/**
	 * The §22 task statuses.
	 *
	 * @var array<string, string>
	 */
	const TASK_STATUSES = array(
		'todo'         => 'To Do',
		'in_progress'  => 'In Progress',
		'review'       => 'Review',
		'done'         => 'Done',
		'blocked'      => 'Blocked',
	);

	/**
	 * The §22 task priorities, ordered by urgency so a sort needs no lookup table.
	 *
	 * @var array<string, int>
	 */
	const TASK_PRIORITIES = array( 'low' => 1, 'medium' => 2, 'high' => 3, 'urgent' => 4 );

	/**
	 * Task statuses that count as finished.
	 *
	 * @var array<int, string>
	 */
	const DONE_TASK_STATUSES = array( 'done' );

	/**
	 * The §21 issue statuses.
	 *
	 * @var array<int, string>
	 */
	const ISSUE_STATUSES = array( 'open', 'in_progress', 'resolved', 'wont_fix', 'reopened' );

	/**
	 * Issue sources, §23.
	 *
	 * The value is the phase that can raise it, so an issue always says where it came
	 * from and the caller knows which reference to store.
	 *
	 * @var array<string, string>
	 */
	const ISSUE_SOURCES = array(
		'validation'       => 'A Phase 5 validation difference',
		'comment'          => 'A review comment',
		'content_conflict' => 'A Phase 14 content mapping conflict',
		'sync_conflict'    => 'A Phase 9 synchronisation conflict',
		'correction'       => 'A Phase 6 correction that failed',
		'manual'           => 'Raised by hand',
	);

	/* ---------------------------------------------------------------------
	 * Members and invitations
	 * ------------------------------------------------------------------ */

	/**
	 * §12 invitation statuses.
	 *
	 * @var array<int, string>
	 */
	const INVITATION_STATUSES = array( 'pending', 'accepted', 'expired', 'revoked' );

	/**
	 * Invitation lifetimes, in seconds.
	 *
	 * 14 days is long enough to cross a weekend and short enough that a leaked link is
	 * not a standing door. §17's review links are shorter — a review link is read once,
	 * an invitation grants standing access.
	 *
	 * @var array<string, int>
	 */
	const INVITATION_TTL = array(
		'workspace' => 1209600,
		'project'   => 604800,
	);

	/**
	 * §17 review-link lifetimes.
	 *
	 * @var array<string, int>
	 */
	const REVIEW_LINK_TTL = array(
		'default' => 604800,
		'extended' => 2592000,
	);

	/* ---------------------------------------------------------------------
	 * Activity and audit
	 * ------------------------------------------------------------------ */

	/**
	 * The §24 activity events.
	 *
	 * The list is closed because an unrecognised event name reaching a timeline renders as
	 * a blank row, and a blank row in an audit history is worse than a missing one.
	 *
	 * @var array<int, string>
	 */
	const ACTIVITY_EVENTS = array(
		'project_created', 'project_updated', 'project_archived', 'project_restored',
		'member_added', 'member_removed', 'member_role_changed',
		'client_created', 'client_updated',
		'analysis_started', 'analysis_completed',
		'generation_started', 'generation_completed',
		'validation_completed',
		'correction_applied', 'correction_failed',
		'sync_started', 'sync_completed',
		'content_mapped', 'content_applied',
		'review_requested', 'review_started', 'review_completed',
		'comment_added', 'comment_resolved',
		'issue_created', 'issue_resolved',
		'task_created', 'task_assigned', 'task_completed',
		'approval_granted', 'changes_requested',
		'version_created', 'rollback_performed',
		'export_created',
	);

	/**
	 * The §25 audit events.
	 *
	 * Kept separate from {@see self::ACTIVITY_EVENTS} because the two answer different
	 * questions and have different retention. Activity is a narrative ("Sara requested
	 * changes"); audit is a record of who changed a permission, who was invited, who
	 * exported what. A permission change is not an activity event, and a page generated is
	 * not an audit event — mixing them produces a timeline where a security question cannot
	 * be answered.
	 *
	 * @var array<int, string>
	 */
	const AUDIT_EVENTS = array(
		'permission_changed', 'role_granted', 'role_revoked',
		'member_invited', 'invitation_revoked', 'invitation_accepted',
		'member_removed', 'member_added',
		'project_access_granted', 'project_access_revoked',
		'review_link_created', 'review_link_revoked', 'review_link_used',
		'export_created', 'export_downloaded',
		'import_run',
		'project_deleted', 'client_deleted',
		'rollback_performed',
		'settings_changed',
		'api_config_changed',
		// Phase 20. The credential lifecycle gets its own actions rather than sharing
		// "api_config_changed", because the security audit requires that token theft
		// and revocation be answerable from the trail alone: "a key was created" and
		// "this key was revoked" are different questions, and an operator investigating an
		// incident needs the second answered rather than inferred from a changed settings
		// blob. Nothing secret is in any of them: a credential prefix is a fragment of a
		// hash, never the token.
		'api_credential_created', 'api_credential_revoked',
		'webhook_created', 'webhook_updated', 'webhook_removed',
		'automation_created', 'automation_updated', 'automation_removed',
		'extension_registered', 'extension_state_changed',
		'workspace_created', 'workspace_settings_changed',
	);

	/* ---------------------------------------------------------------------
	 * Notifications
	 * ------------------------------------------------------------------ */

	/**
	 * The §26 notification types.
	 *
	 * @var array<int, string>
	 */
	const NOTIFICATION_TYPES = array(
		'review_requested', 'review_changes_requested', 'review_approved',
		'comment_mention', 'comment_reply',
		'task_assigned', 'task_completed',
		'issue_created', 'issue_resolved',
		'generation_completed', 'validation_completed',
		'sync_conflict', 'content_conflict',
		'member_added', 'invitation_accepted',
		'weekly_summary',
	);

	/**
	 * The §53 notification preference keys.
	 *
	 * @var array<int, string>
	 */
	const PREFERENCE_KEYS = array(
		'review_requests', 'mentions', 'task_assignments', 'client_changes',
		'generation_completion', 'validation_completion', 'sync_conflicts', 'weekly_summaries',
	);

	/**
	 * Notification channels.
	 *
	 * @var array<int, string>
	 */
	const CHANNELS = array( 'in_app', 'email' );

	/* ---------------------------------------------------------------------
	 * Bounds
	 * ------------------------------------------------------------------ */

	/**
	 * Rows fetched per page, default and ceiling.
	 *
	 * @var array<string, int>
	 */
	const PAGE = array(
		'default'  => 25,
		'ceiling'  => 100,
	);

	/**
	 * Maximum workspaces one user may own.
	 *
	 * @var int
	 */
	const MAX_OWNED_WORKSPACES = 5;

	/**
	 * Maximum members per workspace.
	 *
	 * @var int
	 */
	const MAX_MEMBERS = 200;

	/**
	 * Maximum clients per workspace.
	 *
	 * @var int
	 */
	const MAX_CLIENTS = 500;

	/**
	 * Maximum contacts per client.
	 *
	 * @var int
	 */
	const MAX_CLIENT_CONTACTS = 20;

	/**
	 * Maximum project members per project.
	 *
	 * @var int
	 */
	const MAX_PROJECT_MEMBERS = 50;

	/**
	 * Maximum members named on one invitation batch.
	 *
	 * @var int
	 */
	const MAX_INVITATION_BATCH = 50;

	/**
	 * Maximum open reviews returned by a dashboard.
	 *
	 * @var int
	 */
	const MAX_DASHBOARD_ITEMS = 10;

	/**
	 * Activity retention, in seconds.
	 *
	 * 180 days. Activity is a narrative and grows without bound; §45 expects thousands
	 * of events, and an unbounded log in a table is a table nobody queries. Audit is
	 * retained separately and longer, because it is the record that answers a security
	 * question.
	 *
	 * @var int
	 */
	const ACTIVITY_TTL = 15552000;

	/**
	 * Audit retention, in seconds — one year.
	 *
	 * @var int
	 */
	const AUDIT_TTL = 31536000;

	/**
	 * Maximum open review links per review.
	 *
	 * @var int
	 */
	const MAX_REVIEW_LINKS = 10;

	/**
	 * Maximum attachments of a comment thread's replies loaded at once.
	 *
	 * @var int
	 */
	const MAX_COMMENT_DEPTH = 12;

	/**
	 * Review-link attempt ceiling before the link is suspended.
	 *
	 * §17 requires rate limiting. Ten attempts is generous for a person clicking a link
	 * twice, and low enough that a 32-character token is not brute-forceable in practice.
	 *
	 * @var int
	 */
	const REVIEW_LINK_MAX_ATTEMPTS = 10;

	/* ---------------------------------------------------------------------
	 * Accessors
	 * ------------------------------------------------------------------ */

	/**
	 * Return every declared capability, flattened.
	 *
	 * @return array<int, string>
	 */
	public static function capabilities() {
		$out = array();
		foreach ( self::CAPABILITY_GROUPS as $group ) {
			foreach ( $group as $capability ) {
				$out[] = $capability;
			}
		}
		return $out;
	}

	/**
		* Return a human label for a priority.
		*
		* `PRIORITIES` is a list rather than a map - its membership test is `in_array()`,
		* see `is_priority()` - so it cannot carry labels without a second structure that
		* would have to be kept in step. The labels live here instead, keyed by the value.
		*
		* @param string $priority Priority.
		* @return string
	*/
	public static function priority_label( $priority ) {
		$labels = array(
			'low'    => __( 'Low', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'medium' => __( 'Medium', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'high'   => __( 'High', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'urgent' => __( 'Urgent', 'replicaforge' ),
		);

		$priority = (string) $priority;
		return $labels[ $priority ] ?? (string) $priority;
	}

	/**
		* Return a human label for a role.
		*
		* The owner is included deliberately: a screen that lists roles and cannot name the
		* owner invites the reader to conclude the owner is not a role, which is the wrong
		* model. The owner is a role on the membership row; what it *grants* is derived from
		* `workspace.owner_id`, and that is a separate question answered by
		* {@see Permission_Manager::is_workspace_owner()}.
		*
		* @param string $role Role.
		* @return string
	*/
	public static function role_label( $role ) {
		$labels = array(
			'low'    => __( 'Low', 'replicaforge' ),'owner'           => __( 'Owner', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'admin'            => __( 'Administrator', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'project_manager' => __( 'Project manager', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'designer'         => __( 'Designer', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'developer'        => __( 'Developer', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'reviewer'          => __( 'Reviewer', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'client'            => __( 'Client', 'replicaforge' ),
		);

		$role = (string) $role;
		return $labels[ $role ] ?? (string) $role;
	}

	/**
		* Return a human label for a notification preference category.
		*
		* @param string $key Preference key.
		* @return string
	*/
	public static function preference_label( $key ) {
		$labels = array(
			'low'    => __( 'Low', 'replicaforge' ),'review_requests'      => __( 'Review requests and decisions', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'mentions'             => __( 'Mentions and replies', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'task_assignments'     => __( 'Task assignments', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'issue_updates'       => __( 'Issue changes', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'validation_completion' => __( 'Validation finished', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'generation_completion' => __( 'Generation finished', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'sync_conflicts'       => __( 'Sync and content conflicts', 'replicaforge' ),
			'low'    => __( 'Low', 'replicaforge' ),'weekly_summary'      => __( 'Weekly summary', 'replicaforge' ),
		);

		$key = (string) $key;
		return $labels[ $key ] ?? (string) $key;
	}
	/**
		* Return whether a value is a declared priority.
		*
		* Exists so that no caller has to know whether a vocabulary is a list or a map.
		* Getting that wrong is silent, and a silent permission or validation answer is the
		* one that is hardest to notice.
		*
		* @param mixed $value Candidate.
		* @return bool
	*/
	public static function is_priority( $value ) {
		return is_string( $value ) && in_array( $value, self::PRIORITIES, true );
	}

	/**
		* Return whether a value is a declared stage.
		*
		* @param mixed $value Candidate.
		* @return bool
	*/
	public static function is_stage( $value ) {
		return is_string( $value ) && array_key_exists( $value, self::STAGES );
	}

	/**
	 * Return whether a value is a declared capability.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_capability( $value ) {
		return is_string( $value ) && in_array( $value, self::capabilities(), true );
	}

	/**
	 * Return the capabilities a workspace role implies.
	 *
	 * The owner is answered here rather than at the call site, so a caller that asks
	 * "what can an owner do" gets every capability rather than the empty array
	 * {@see self::ROLE_CAPS} holds for it. Leaving that empty was deliberate — an owner
	 * is derived from `workspace.owner_id`, not from a stored role — and returning an
	 * empty set for it would make the documentation matrix wrong.
	 *
	 * @param string $role Role.
	 * @return array<int, string>
	 */
	public static function caps_for_role( $role ) {
		$role = (string) $role;
		if ( 'owner' === $role ) {
			return self::capabilities();
		}
		return isset( self::ROLE_CAPS[ $role ] ) ? self::ROLE_CAPS[ $role ] : array();
	}

	/**
	 * Return the capabilities a project role implies.
	 *
	 * @param string $role Role.
	 * @return array<int, string>
	 */
	public static function project_caps_for_role( $role ) {
		$role = (string) $role;
		return isset( self::PROJECT_ROLE_CAPS[ $role ] ) ? self::PROJECT_ROLE_CAPS[ $role ] : array();
	}

	/**
	 * Return the ownership states, read from Phase 9.
	 *
	 * @return array<int, string>
	 */
	public static function ownership_states() {
		return Sync_Conflict_Detector::OWNERSHIP;
	}

	/**
	 * Return the issue severities, read from Phase 5.
	 *
	 * §21 forbids a second severity system. A review issue promoted from a validation
	 * difference keeps that difference's severity, and a difference's severity is
	 * `Validation_Limits::SEVERITIES`.
	 *
	 * @return array<int, string>
	 */
	public static function severities() {
		return Validation_Limits::SEVERITIES;
	}

	/**
	 * Return the human label for a stage.
	 *
	 * @param string $stage Stage.
	 * @return string
	 */
	public static function stage_label( $stage ) {
		$stage = (string) $stage;
		return isset( self::STAGES[ $stage ] ) ? self::STAGES[ $stage ] : ucfirst( str_replace( '_', ' ', $stage ) );
	}

	/**
	 * Return the human label for a task status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function task_status_label( $status ) {
		return isset( self::TASK_STATUSES[ $status ] ) ? self::TASK_STATUSES[ $status ] : (string) $status;
	}

	/**
	 * Return the human label for a review status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function review_status_label( $status ) {
		$labels = array(
			'pending'           => __( 'Pending', 'replicaforge' ),
			'in_review'         => __( 'In Review', 'replicaforge' ),
			'changes_requested' => __( 'Changes Requested', 'replicaforge' ),
			'approved'          => __( 'Approved', 'replicaforge' ),
			'rejected'          => __( 'Rejected', 'replicaforge' ),
			'cancelled'         => __( 'Cancelled', 'replicaforge' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Return a bounded page size.
	 *
	 * @param mixed $per_page Requested size.
	 * @return int
	 */
	public static function page_size( $per_page ) {
		$per_page = (int) $per_page;
		if ( $per_page < 1 ) {
			return self::PAGE['default'];
		}
		return min( $per_page, self::PAGE['ceiling'] );
	}

	/**
	 * The admin page a notification links back to.
	 *
	 * `Admin` declares `PAGE_SLUG`, but that class is only loaded in an admin context - a
	 * REST request, a cron run or a CLI invocation has no `Admin`, and a notification
	 * composed from any of those would fatal on the constant. So the slug is declared here,
	 * in a class that is always loaded, and {@see Admin::PAGE_SLUG} is checked against it
	 * by the test suite so the two cannot drift apart silently.
	 *
	 * A duplicate constant is the right shape for this: two copies of a string that must
	 * agree, with an assertion that they do, beats a runtime dependency on a class that is
	 * not always present.
	 */
	const ADMIN_PAGE = 'replicaforge';
	/**
	 * Return the table name for a collaboration entity.
	 *
	 * Centralised because a table name is a string, and a string is exactly the kind of
	 * thing a typo turns into a query against the wrong table. There is no
	 * `$wpdb->prefix . 'replicaforge_' . $kind` anywhere in this layer.
	 *
	 * @param string $kind Entity kind.
	 * @return string Empty string when the kind is unknown, which every caller treats as
	 *                a refusal rather than guessing.
	 */
	public static function table( $kind ) {
		$tables = array(
			'workspaces'     => 'replicaforge_workspaces',
			'members'        => 'replicaforge_workspace_members',
			'invitations'    => 'replicaforge_invitations',
			'clients'        => 'replicaforge_clients',
			'contacts'       => 'replicaforge_client_contacts',
			'project_member' => 'replicaforge_project_members',
			'reviews'        => 'replicaforge_reviews',
			'comments'       => 'replicaforge_comments',
			'tasks'          => 'replicaforge_tasks',
			'issues'         => 'replicaforge_issues',
			'notifications'  => 'replicaforge_notifications',
			'activity'       => 'replicaforge_activity',
			// Phase 19. Three tables, not one, because they have genuinely different
			// lifecycles: a template row is mutable metadata, a version row is append-only
			// history, and a component row is a separate reusable entity with its own
			// identity. Collapsing them would either lose version history or require every
			// version read to walk a JSON blob.
			'templates'      => 'replicaforge_templates',
			'template_version' => 'replicaforge_template_versions',
			'template_component' => 'replicaforge_template_components',
			// Phase 20. Six tables, and the argument is the same one Phase 19 made for
			// templates: each of these is filtered, paginated and workspace-scoped in the
			// developer console, and none of them is a document small enough to serve from
			// an option without loading the whole set for every view.
			//
			// The splits that earn their own table:
			//  - `webhook` (the subscription) from `webhook_delivery` (the attempt log), so
			//    listing subscriptions never scans deliveries.
			//  - `extension` from everything an extension produces, because the manifest is
			//    metadata that outlives any one capability being callable.
			//  - `event` from `automation`, because events are written on every emission
			//    while automations are configuration read only when one might match.
			'api_credential'     => 'replicaforge_api_credentials',
			'webhook'            => 'replicaforge_webhooks',
			'webhook_delivery'   => 'replicaforge_webhook_deliveries',
			'extension'          => 'replicaforge_extensions',
			'automation'         => 'replicaforge_automations',
			'event'              => 'replicaforge_events',
			'audit'          => 'replicaforge_audit',
		);
		return isset( $tables[ $kind ] ) ? $tables[ $kind ] : '';
	}

	/**
	 * Return the table name for an entity, prefixed.
	 *
	 * @param string $kind Entity kind.
	 * @return string
	 */
	public static function prefixed_table( $kind ) {
		global $wpdb;
		$table = self::table( $kind );
		return ( '' === $table ) ? '' : $wpdb->prefix . $table;
	}
}
