<?php
/**
 * Phase 20: extensibility platform vocabulary.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Every closed vocabulary and ceiling the extensibility platform works within.
 *
 * ### Why one class
 *
 * An extension platform fails in a specific way: somebody adds a capability in the place
 * they were working, and now two registries disagree about what capabilities exist. This is
 * the same reasoning as `Workspace_Limits`, `Orchestrator_Limits` and `Template_Limits`, and
 * the same rule: **a value that must be checkable is declared once, and every consumer reads
 * that one declaration.**
 *
 * ### What this class holds that is genuinely new
 *
 * Six vocabularies with no predecessor anywhere in the plugin:
 *
 * - {@see Platform_Limits::EXTENSION_CAPABILITIES} — what an extension can *be*.
 * - {@see Platform_Limits::EXTENSION_PERMISSIONS} — what an extension can *read or write*.
 * - {@see Platform_Limits::API_SCOPES} — what an external credential can *do*.
 * - {@see Platform_Limits::EVENTS} — what an integration can be *told about*.
 * - {@see Platform_Limits::AUTOMATION_TRIGGERS} / {@see Platform_Limits::AUTOMATION_ACTIONS}.
 * - {@see Platform_Limits::EXTENSION_STATES} — an extension's lifecycle.
 *
 * ### What this class holds that already existed
 *
 * Nothing. Where a Phase 20 concept overlaps an earlier phase, this class reads through to
 * the original rather than restating it. Extensions may not have their own vocabulary of
 * workflow states, validation results, plan operations or asset provenance — an extension
 * receives those types, and the definitions stay where they were built.
 *
 * ### The one-line test for adding anything here
 *
 * If it cannot be checked by membership in a list declared in this class, it does not belong
 * in the platform. That is what keeps "extensible without becoming an arbitrary-code
 * execution platform" a property of the design rather than an intention.
 */
final class Platform_Limits {

	/**
	 * Phase marker.
	 *
	 * @var string
	 */
	const PHASE = '20.0';

	/**
	 * The extension-manifest schema version.
	 *
	 * Separate from `Schema::DB_SCHEMA_VERSION`, which tracks the database, and separate
	 * from `Template_Limits::SCHEMA_VERSION`, which tracks template packages. A manifest is
	 * a third thing: a document that can travel between installs, so it carries its own
	 * version and is compared on import.
	 *
	 * @var string
	 */
	const MANIFEST_SCHEMA_VERSION = '20.0';

	/**
	 * The event schema version, written into every event record.
	 *
	 * @var string
	 */
	const EVENT_SCHEMA_VERSION = '20.0';

	/**
	 * The webhook payload schema version, sent in the signature scope.
	 *
	 * @var string
	 */
	const PAYLOAD_SCHEMA_VERSION = '20.0';

	/**
	 * Prefix for the credential a client sends.
	 *
	 * The prefix is not decoration. It makes a leaked credential recognisable in a log
	 * without being usable, and it makes it possible to *refuse* a value that is obviously
	 * not a ReplicaForge credential before spending a hash comparison on it.
	 *
	 * @var string
	 */
	const TOKEN_PREFIX = 'rf_live_';

	/**
	 * Minimum token entropy, in bytes.
	 *
	 * Matches `Secure_Token::BYTES`, which is what actually issues the credential. Declared
	 * here so a validator can check a *presented* token's shape without instantiating the
	 * issuer, and so the two cannot drift.
	 *
	 * @var int
	 */
	const TOKEN_BYTES = 32;

	/* ---------------------------------------------------------------------
	 * Extension capabilities
	 * ------------------------------------------------------------------ */

	/**
	 * What an extension is allowed to be.
	 *
	 * ### Why this is a closed list
	 *
	 * An open set of capabilities is the same as no capability system: a provider that
	 * returns `['analyzer', 'execute_php']` would be trusted, and the next capability added
	 * would be inherited by every provider written before it existed. So a manifest naming
	 * a capability not in this list is **rejected**, not warned about.
	 *
	 * ### What each one may actually do
	 *
	 * A capability is a *label on a bound method*, never a permission grant. Each interface
	 * method below takes only the inputs its capability justifies:
	 *
	 * - `analyzer` — receives a representation, returns observations. Cannot write.
	 * - `component_provider` — returns component descriptors for a template. Cannot write.
	 * - `template_provider` — returns a template package, which is then validated and
	 *   installed by Phase 19's pipeline. Never installed by the extension itself.
	 * - `content_mapper` — maps declared source fields onto declared destination fields.
	 *   Cannot choose a destination.
	 * - `validation_provider` — returns findings. Cannot mark a template valid.
	 * - `export_provider` — returns a serialised document. Cannot touch the filesystem.
	 * - `notification_provider` — sends a notification through Phase 15's service.
	 * - `automation` — may register automations, subject to the same gates as an operator.
	 *
	 * @var array<string, string>
	 */
	const EXTENSION_CAPABILITIES = array(
		'analyzer'             => 'Adds safe analysis observations to a representation',
		'component_provider'    => 'Supplies reusable component descriptors',
		'template_provider'     => 'Supplies a reusable template package',
		'content_mapper'        => 'Maps declared source fields to declared destinations',
		'validation_provider'   => 'Adds validation checks',
		'export_provider'       => 'Supplies an additional export format',
		'notification_provider' => 'Delivers notifications through a configured channel',
		'automation'            => 'Registers agency automations',
	);

	/**
	 * Extension permissions: what data an extension may read or write.
	 *
	 * ### Why permissions are separate from capabilities
	 *
	 * A capability says what a thing *is*; a permission says what it may *see*. Collapsing
	 * them is how an analyzer ends up able to read billing records, because it needed write
	 * access to its own output and the two were the same switch. They are separate switches
	 * here, both must be declared, and both are granted per registration.
	 *
	 * ### What is deliberately absent
	 *
	 * There is no permission for database access, filesystem access, PHP execution, SQL,
	 * shell commands, capability management, user impersonation, or secret reading — and
	 * the absence is structural rather than a matter of policy. Nothing in the provider
	 * interface receives a `$wpdb`, a path, or a callable, so there is no argument to pass
	 * one through even if a provider asked.
	 *
	 * @var array<string, string>
	 */
	const EXTENSION_PERMISSIONS = array(
		'project.metadata.read'   => 'Read a project\'s name, source URL and status',
		'project.analysis.read'   => 'Read the stored representation for a page',
		'project.design.read'     => 'Read the stored design system and tokens',
		'project.settings.write'  => 'Change a project setting this extension declares support for',
		'template.metadata.read'  => 'Read template names, types and versions',
		'analysis.result.write'   => 'Record an observation against a representation',
		'reconstruction.plan.write' => 'Contribute a reconstruction plan fragment',
		'validation.result.read'  => 'Read validation results',
		'validation.result.write' => 'Record a validation finding',
		'component.register'      => 'Register a reusable component',
		'template.register'       => 'Register a reusable template package',
		'workflow.trigger'        => 'Start a reconstruction workflow',
		'workflow.status.read'    => 'Read workflow status and stage outcomes',
		'webhook.event.read'      => 'Receive the events this extension subscribed to',
	);

	/**
	 * Permissions that are refused outright, whatever a manifest claims.
	 *
	 * These are here to be *checked against*, so a manifest containing one is rejected with
	 * a specific reason rather than silently losing that permission and appearing to work.
	 * An extension that thinks it has `database.write` and silently does not is a debugging
	 * problem the person writing it will not solve; one that is told "that permission does
	 * not exist" is a one-line fix.
	 *
	 * @var array<int, string>
	 */
	const FORBIDDEN_EXTENSION_PERMISSIONS = array(
		'database.read',
		'database.write',
		'filesystem.read',
		'filesystem.write',
		'code.execute',
		'php.execute',
		'sql.execute',
		'shell.execute',
		'capability.manage',
		'permission.grant',
		'user.impersonate',
		'secret.read',
		'billing.write',
		'usage.write',
	);

	/* ---------------------------------------------------------------------
	 * Extension lifecycle
	 * ------------------------------------------------------------------ */

	/**
	 * Extension states.
	 *
	 * `failed` exists because a provider that throws is a real and common outcome, and
	 * conflating it with `disabled` would hide a bug behind what looks like a decision.
	 * `deprecated` exists because an extension should not keep working silently against a
	 * version it was not written for.
	 *
	 * @var array<string, string>
	 */
	const EXTENSION_STATES = array(
		'registered'    => 'Registered, manifest not yet checked',
		'active'        => 'Compatible and its capabilities are available',
		'disabled'      => 'Turned off by an operator',
		'incompatible'  => 'Declares requirements this install does not meet',
		'failed'        => 'A provider threw while being registered',
		'deprecated'    => 'Works, but the API it uses is scheduled for change',
	);

	/**
	 * States in which an extension's capabilities are actually reachable.
	 *
	 * @var array<int, string>
	 */
	const LIVE_EXTENSION_STATES = array( 'active', 'deprecated' );

	/* ---------------------------------------------------------------------
	 * API scopes
	 * ------------------------------------------------------------------ */

	/**
	 * Scopes a credential may hold.
	 *
	 * ### Each scope is a pair, not a string
	 *
	 * A scope resolves to *both* a workspace capability and a plan operation, because the
	 * two are different gates and a scope that honoured only one would let a credential
	 * bypass the other:
	 *
	 * - `workspace` — Phase 15's `Permission_Manager::can()`. "May this person, in this
	 *   workspace, do this?"
	 * - `operation` — Phase 10's `Entitlement_Manager::check()`. "May this plan do this,
	 *   and has it already done it enough times?"
	 *
	 * ### What is deliberately absent
	 *
	 * There is no `admin`, `root`, or `everything`. A credential is a *narrower* thing than
	 * a WordPress session, because a session is issued to a person who can be asked to
	 * confirm intent and a credential cannot. A scope that grants unbounded authority
	 * would make the credential the weaker link in a chain that is otherwise strong.
	 *
	 * ### `operation` may be empty
	 *
	 * A read scope that only reads stored records does not consume plan quota, because
	 * reading a project name costs nothing and metering it would make the meter a tax on
	 * dashboards. An operation is named where the action consumes a resource.
	 *
	 * @var array<string, array{workspace: string, operation: string}>
	 */
	const API_SCOPES = array(
		'projects:read'      => array( 'workspace' => 'projects.view', 'operation' => '' ),
		'projects:write'     => array( 'workspace' => 'projects.edit', 'operation' => '' ),
		'analysis:execute'   => array( 'workspace' => 'analysis.run', 'operation' => 'analysis' ),
		'workflows:read'     => array( 'workspace' => 'projects.view', 'operation' => '' ),
		'workflows:execute'  => array( 'workspace' => 'generation.run', 'operation' => 'generation' ),
		'validation:read'    => array( 'workspace' => 'projects.view', 'operation' => '' ),
		'validation:run'     => array( 'workspace' => 'validation.run', 'operation' => 'validation' ),
		'corrections:write'  => array( 'workspace' => 'correction.apply', 'operation' => 'correction' ),
		'templates:read'     => array( 'workspace' => 'templates.view', 'operation' => '' ),
		'templates:import'   => array( 'workspace' => 'templates.import', 'operation' => 'import' ),
		'templates:export'   => array( 'workspace' => 'templates.export', 'operation' => 'export' ),
		'templates:share'    => array( 'workspace' => 'templates.share', 'operation' => '' ),
		'design:manage'      => array( 'workspace' => 'design_systems.manage', 'operation' => '' ),
		'assets:read'        => array( 'workspace' => 'templates.view', 'operation' => '' ),
		'events:read'        => array( 'workspace' => 'api.events.read', 'operation' => '' ),
		'webhooks:manage'    => array( 'workspace' => 'api.webhooks.manage', 'operation' => '' ),
	);

	/**
	 * Scopes that mutate something. A read-only credential cannot hold one.
	 *
	 * @var array<int, string>
	 */
	const WRITE_SCOPES = array(
		'projects:write',
		'analysis:execute',
		'workflows:execute',
		'validation:run',
		'corrections:write',
		'templates:import',
		'templates:export',
		'templates:share',
		'design:manage',
		'webhooks:manage',
	);

	/**
	 * Permission-callback method name to the scopes that satisfy it.
	 *
	 * ### Why this map is keyed on gate names, not on route paths
	 *
	 * Phases 1-19 already registered around 130 routes under `replicaforge/v1`, and every one
	 * declares a `permission_callback`. None of them knows anything about Phase 20 scopes:
	 * they were written before credentials existed, and they correctly check WordPress
	 * authentication and the Phase 15 workspace capability.
	 *
	 * That leaves the scope-escalation hole open. `determine_current_user` resolves a
	 * credential to a user id, and every existing route then sees an ordinary authenticated
	 * user with whatever workspace capabilities that user holds. A credential holding only
	 * `projects:read` could `POST /analyze`, because nothing between the credential and the
	 * handler knows the credential is deliberately narrower than the person behind it.
	 *
	 * So Phase 20 gates them, keyed on the **permission callback's own method name** rather
	 * than on a hand-written list of 130 paths. Two reasons:
	 *
	 * 1. A path list drifts. Phase 19 added nineteen template routes and Phase 17 added
	 *    eight; a map nobody maintains silently stops covering, and the failure is an
	 *    over-permissive credential, which is the worst direction for this list to be wrong.
	 * 2. Every new route already picks an existing gate to reuse. Keying on the gate means
	 *    such a route inherits its scope automatically, and a route introducing a *new* gate
	 *    is **refused** to credential callers until an entry is added here. Fail-closed in
	 *    exactly the direction that matters, and the missing entry shows up in the developer
	 *    document rather than failing silently.
	 *
	 * A credential satisfies a route if it holds **any** scope mapped to that route's gate,
	 * which is what makes a single-purpose token usable. A read-only monitoring credential
	 * holding only `projects:read` passes every gate mapping to `projects:read` and is refused
	 * by every gate mapping to a write scope.
	 *
	 * `can_use` and `gate_signed_in` are the weakest existing gates - "may use ReplicaForge at
	 * all" - so they map to `projects:read`. That is honest rather than generous: a credential
	 * reaching such a route really can do whatever that route does, and the route's own
	 * permission callback still applies on top of this check.
	 *
	 * @var array<string, array<int, string>>
	 */
	const GATE_SCOPES = array(
		// Generic gates.
		'can_use'           => array( 'projects:read' ),
		'can_manage'        => array( 'projects:write' ),
		'gate_signed_in'    => array( 'projects:read' ),
		'gate_project'      => array( 'projects:read' ),
		'can_analyze'       => array( 'analysis:execute' ),
		'can_read'          => array( 'projects:read' ),
		'can_create'        => array( 'projects:write' ),
		'can_edit'          => array( 'projects:write' ),
		'can_delete'        => array( 'projects:write' ),
		'can_run'           => array( 'workflows:execute' ),
		'can_export'        => array( 'templates:export' ),
		'can_import'        => array( 'templates:import' ),
		'can_share'         => array( 'templates:share' ),
		'can_manage_design' => array( 'design:manage' ),

		// Workspace and projects.
		'gate_workspace_view'          => array( 'projects:read' ),
		'gate_workspace_manage'        => array( 'projects:write' ),
		'gate_any_workspace'           => array( 'projects:read' ),
		'gate_projects_view'           => array( 'projects:read' ),
		'gate_projects_edit'           => array( 'projects:write' ),
		'gate_projects_archive'        => array( 'projects:write' ),
		'gate_projects_manage_members' => array( 'projects:write' ),

		// Members.
		'gate_members_view'   => array( 'projects:read' ),
		'gate_members_invite' => array( 'projects:write' ),
		'gate_members_remove' => array( 'projects:write' ),
		'gate_roles_manage'   => array( 'projects:write' ),

		// Clients.
		'gate_clients_view'   => array( 'projects:read' ),
		'gate_clients_create' => array( 'projects:write' ),
		'gate_clients_edit'   => array( 'projects:write' ),

		// Review and comment surface.
		'gate_reviews_view'     => array( 'validation:read' ),
		'gate_reviews_create'   => array( 'projects:write' ),
		'gate_reviews_decide'   => array( 'projects:write' ),
		'gate_comments_read'    => array( 'validation:read' ),
		'gate_comments_create'  => array( 'projects:write' ),
		'gate_comments_resolve' => array( 'projects:write' ),
	);

	/* ---------------------------------------------------------------------
	 * Events
	 * ------------------------------------------------------------------ */

	/**
	 * Events an integration can subscribe to.
	 *
	 * ### Every one of these corresponds to something that actually happens
	 *
	 * This list is the discipline. The brief's example list included `sync.completed`, and
	 * `sync.completed` is **not here**, because Phase 17's `Capability_Registry::probe_sync()`
	 * returns `unavailable` unconditionally with the detail
	 * `detection => available, application => unavailable` — nothing in the plugin can
	 * apply a synchronisation. Emitting an event for it would create a subscription that
	 * can never fire, and a webhook endpoint that silently never receives what it was
	 * promised.
	 *
	 * `group` is what a subscriber picks from; `public` marks an event safe to deliver
	 * outside the workspace. A non-public event is recorded for the console and can be read
	 * by an authenticated operator but is not delivered to a third party.
	 *
	 * @var array<string, array{group: string, label: string, public: bool}>
	 */
	const EVENTS = array(
		// Workflow lifecycle. Every one maps to a state in `Orchestrator_Limits::TRANSITIONS`.
		'workflow.created'          => array( 'group' => 'workflow', 'label' => 'A workflow was created', 'public' => true ),
		'workflow.started'          => array( 'group' => 'workflow', 'label' => 'A workflow began running', 'public' => true ),
		'workflow.completed'        => array( 'group' => 'workflow', 'label' => 'A workflow finished successfully', 'public' => true ),
		'workflow.failed'           => array( 'group' => 'workflow', 'label' => 'A workflow failed', 'public' => true ),
		'workflow.cancelled'        => array( 'group' => 'workflow', 'label' => 'A workflow was cancelled', 'public' => true ),
		'workflow.paused'           => array( 'group' => 'workflow', 'label' => 'A workflow paused at a gate or a budget', 'public' => true ),
		'workflow.awaiting_approval' => array( 'group' => 'workflow', 'label' => 'A workflow is waiting for an approval decision', 'public' => true ),

		// Stage completions. Each maps to a stage in `Orchestrator_Limits::STAGES`.
		'analysis.completed'        => array( 'group' => 'stage', 'label' => 'Source analysis finished', 'public' => true ),
		'reconstruction.completed'  => array( 'group' => 'stage', 'label' => 'Reconstruction produced a draft', 'public' => true ),
		'validation.completed'      => array( 'group' => 'stage', 'label' => 'Validation finished', 'public' => true ),
		'correction.completed'      => array( 'group' => 'stage', 'label' => 'Corrections finished', 'public' => true ),

		// Approval gates. Each maps to a gate in `Orchestrator_Limits::GATES`.
		'approval.requested'        => array( 'group' => 'approval', 'label' => 'An approval was requested', 'public' => true ),
		'approval.completed'        => array( 'group' => 'approval', 'label' => 'An approval decision was recorded', 'public' => true ),

		// Phase 19 templates.
		'template.created'          => array( 'group' => 'template', 'label' => 'A template was created', 'public' => true ),
		'template.imported'         => array( 'group' => 'template', 'label' => 'A template package was imported', 'public' => true ),
		'template.updated'          => array( 'group' => 'template', 'label' => 'A template or one of its versions changed', 'public' => true ),

		// Phase 20 platform surface. These are how an integration learns its own
		// credentials are being revoked, which is the moment it must stop calling.
		'credential.revoked'        => array( 'group' => 'platform', 'label' => 'An API credential was revoked', 'public' => true ),
		'extension.disabled'        => array( 'group' => 'platform', 'label' => 'An extension was disabled', 'public' => false ),
		'automation.failed'         => array( 'group' => 'platform', 'label' => 'An automation could not run', 'public' => false ),
	);

	/**
	 * Event groups, for a subscriber that wants a category rather than a list.
	 *
	 * @var array<string, string>
	 */
	const EVENT_GROUPS = array(
		'workflow' => 'Workflow lifecycle',
		'stage'    => 'Pipeline stage completions',
		'approval' => 'Approval gates',
		'template' => 'Template library',
		'platform' => 'Developer platform',
	);

	/* ---------------------------------------------------------------------
	 * Automation
	 * ------------------------------------------------------------------ */

	/**
	 * What can start an automation.
	 *
	 * Each maps to an event in {@see Platform_Limits::EVENTS}, so a trigger is a
	 * subscription to a thing that happens rather than a condition somebody invented.
	 * `manual` is the exception and is a direct call.
	 *
	 * @var array<string, string>
	 */
	const AUTOMATION_TRIGGERS = array(
		'manual'                     => 'Run it on demand',
		'workflow.completed'         => 'When a workflow finishes',
		'workflow.failed'            => 'When a workflow fails',
		'analysis.completed'         => 'When source analysis finishes',
		'reconstruction.completed'   => 'When reconstruction finishes',
		'validation.completed'       => 'When validation finishes',
		'approval.requested'         => 'When an approval is requested',
		'approval.completed'         => 'When an approval is decided',
		'template.created'           => 'When a template is created',
		'sync.completed'             => 'When a synchronisation finishes',
	);

	/**
	 * What an automation may do.
	 *
	 * Every action resolves to an existing service. There is no "run this code" action,
	 * because an action that takes a callable is an arbitrary-execution feature with a
	 * nicer name.
	 *
	 * @var array<string, string>
	 */
	const AUTOMATION_ACTIONS = array(
		'start_workflow' => 'Start a Phase 17 reconstruction workflow',
		'create_review'  => 'Open a Phase 15 review',
		'create_task'    => 'Create a Phase 15 task',
		'notify'         => 'Send a notification through a configured channel',
		'webhook'        => 'Deliver to a webhook subscription',
	);

	/**
	 * Automation states.
	 *
	 * @var array<string, string>
	 */
	const AUTOMATION_STATES = array(
		'active'   => 'Active',
		'disabled' => 'Disabled',
		'failing'  => 'Disabled after repeated failures',
	);

	/**
	 * Loop prevention.
	 *
	 * ### Why a depth limit and not "detect the loop"
	 *
	 * The failure is `workflow.completed → automation → start_workflow → workflow.completed`,
	 * which is a genuine cycle. Detecting cycles exactly requires tracking the whole
	 * ancestry graph and is not worth it for a platform whose automations are, by
	 * construction, shallow. A depth ceiling is a bound, not a guess: at
	 * {@see Platform_Limits::AUTOMATION_MAX_DEPTH} the chain stops whether or not it is
	 * cyclic, and the refusal names the depth so the operator can see what happened.
	 *
	 * The second guard is per-automation: an automation whose own id already appears in the
	 * event's ancestry is refused, which catches the *self*-triggering case immediately
	 * rather than after the depth ceiling is reached. Between them, a cycle terminates on
	 * its first pass in the common case and at the ceiling in the pathological one.
	 *
	 * @var int
	 */
	const AUTOMATION_MAX_DEPTH = 3;

	/**
	 * How long an automation waits after firing before it may fire again for the same
	 * trigger and resource.
	 *
	 * @var int
	 */
	const AUTOMATION_COOLDOWN_SECONDS = 300;

	/**
	 * Consecutive failures before an automation is disabled.
	 *
	 * @var int
	 */
	const AUTOMATION_MAX_FAILURES = 5;

	/* ---------------------------------------------------------------------
	 * Webhooks
	 * ------------------------------------------------------------------ */

	/**
	 * Webhook subscription states.
	 *
	 * @var array<string, string>
	 */
	const WEBHOOK_STATES = array(
		'active'   => 'Active',
		'disabled' => 'Disabled',
		'failing'  => 'Disabled after repeated delivery failures',
	);

	/**
	 * Delivery states.
	 *
	 * @var array<string, string>
	 */
	const DELIVERY_STATES = array(
		'pending'    => 'Waiting to be delivered',
		'delivering' => 'In flight',
		'delivered'  => 'Delivered',
		'failed'     => 'Failed and will be retried',
		'dead'       => 'Failed permanently',
		'skipped'    => 'Not delivered, because a replay or a limit stopped it',
	);

	/**
	 * States from which a delivery will never be retried.
	 *
	 * @var array<int, string>
	 */
	const TERMINAL_DELIVERY_STATES = array( 'delivered', 'dead', 'skipped' );

	/**
	 * Maximum delivery attempts.
	 *
	 * Five attempts with `Job_Limits::backoff_seconds()` between them — 30 s, 60 s, 120 s,
	 * 240 s — covers a receiver that is briefly down and stops well short of "retry
	 * forever". There is no configuration knob for this, deliberately: a ceiling nobody can
	 * raise is a ceiling nobody can accidentally remove.
	 *
	 * @var int
	 */
	const WEBHOOK_MAX_ATTEMPTS = 5;

	/**
	 * Delivery timeout, in seconds.
	 *
	 * Short. A webhook receiver is a third party, and a request that is still open after
	 * this has almost certainly stopped listening; holding a cron worker on it would let a
	 * hostile endpoint consume the site.
	 *
	 * @var int
	 */
	const WEBHOOK_TIMEOUT_SECONDS = 10;

	/**
	 * Consecutive delivery failures before a subscription is disabled.
	 *
	 * @var int
	 */
	const WEBHOOK_MAX_FAILURES = 12;

	/**
	 * Signatures older than this are refused as a possible replay.
	 *
	 * Five minutes. Long enough that a receiver's clock can be a minute or two out, short
	 * enough that a captured signature is useless within the life of the request it signed.
	 *
	 * @var int
	 */
	const WEBHOOK_REPLAY_WINDOW = 300;

	/* ---------------------------------------------------------------------
	 * Credentials
	 * ------------------------------------------------------------------ */

	/**
	 * Credential states.
	 *
	 * @var array<string, string>
	 */
	const CREDENTIAL_STATES = array(
		'active'  => 'Active',
		'revoked' => 'Revoked',
		'expired' => 'Expired',
	);

	/* ---------------------------------------------------------------------
	 * Ceilings
	 * ------------------------------------------------------------------ */

	/**
	 * Credentials per workspace.
	 *
	 * @var int
	 */
	const MAX_CREDENTIALS = 25;

	/**
	 * Extensions registered on a site.
	 *
	 * Site-wide rather than per workspace, because an installed extension is a property of the
	 * *site* — it is registered by already-trusted PHP running in the process, not by a user
	 * in a workspace. `Extension_Store` has no `workspace_id` column for that reason.
	 *
	 * @var int
	 */
	const MAX_EXTENSIONS = 100;

	/**
	 * Webhook subscriptions per workspace.
	 *
	 * @var int
	 */
	const MAX_WEBHOOKS = 20;

	/**
	 * Pending deliveries per webhook, before the oldest are dropped.
	 *
	 * @var int
	 */
	const MAX_PENDING_DELIVERIES = 200;

	/**
	 * Automations per workspace.
	 *
	 * @var int
	 */
	const MAX_AUTOMATIONS = 30;

	/**
	 * Events retained for the console, per workspace.
	 *
	 * @var int
	 */
	const MAX_RECENT_EVENTS = 500;

	/**
	 * Days a delivery record is retained after it reaches a terminal state.
	 *
	 * @var int
	 */
	const DELIVERY_RETENTION_DAYS = 14;

	/**
	 * Requests a single credential may make in one minute.
	 *
	 * A backstop against a leaked credential, not the primary limit — {@see Api_Authenticator}
	 * also charges the call against the plan's `api_call` quota and against the workspace's
	 * own count, so a legitimate integration at its ceiling is metered rather than refused.
	 *
	 * @var int
	 */
	const RATE_LIMIT_PER_MINUTE = 120;

	/**
	 * Webhook deliveries attempted in one cron tick.
	 *
	 * @var int
	 */
	const WEBHOOK_BATCH = 10;

	/**
	 * Automations evaluated in one event emission.
	 *
	 * @var int
	 */
	const AUTOMATION_BATCH = 20;

	/* ---------------------------------------------------------------------
	 * Predicates
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether a value is a known extension capability.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_extension_capability( $value ) {
		return is_string( $value ) && isset( self::EXTENSION_CAPABILITIES[ $value ] );
	}

	/**
	 * Return whether a value is a known extension permission.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_extension_permission( $value ) {
		return is_string( $value ) && isset( self::EXTENSION_PERMISSIONS[ $value ] );
	}

	/**
	 * Return whether a value is an explicitly forbidden permission.
	 *
	 * Checked separately from {@see self::is_extension_permission()} so the refusal can
	 * say *which* rule was broken.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_forbidden_permission( $value ) {
		return is_string( $value ) && in_array( $value, self::FORBIDDEN_EXTENSION_PERMISSIONS, true );
	}

	/**
	 * Return whether a value is a known extension state.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_extension_state( $value ) {
		return is_string( $value ) && isset( self::EXTENSION_STATES[ $value ] );
	}

	/**
	 * Return whether an extension in this state may serve its capabilities.
	 *
	 * @param mixed $state State.
	 * @return bool
	 */
	public static function is_live_extension( $state ) {
		return in_array( (string) $state, self::LIVE_EXTENSION_STATES, true );
	}

	/**
	 * Return whether a value is a known API scope.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_api_scope( $value ) {
		return is_string( $value ) && isset( self::API_SCOPES[ $value ] );
	}

	/**
	 * Return whether a scope may mutate something.
	 *
	 * @param mixed $scope Scope.
	 * @return bool
	 */
	public static function is_write_scope( $scope ) {
		return in_array( (string) $scope, self::WRITE_SCOPES, true );
	}

	/**
	 * Return the scopes that satisfy a permission gate.
	 *
	 * @param string $gate Permission-callback method name.
	 * @return array<int, string> Empty when the gate is not mapped, which callers must treat
	 *                            as a refusal rather than as "anything goes".
	 */
	public static function gate_scopes( $gate ) {
		if ( ! is_string( $gate ) || '' === $gate ) {
			return array();
		}

		$scopes = self::GATE_SCOPES[ $gate ] ?? array();

		return array_values( array_filter( array_map( 'strval', (array) $scopes ), array( __CLASS__, 'is_api_scope' ) ) );
	}

	/**
	 * Return the definition of a scope.
	 *
	 * @param string $scope Scope.
	 * @return array{workspace: string, operation: string}
	 */
	public static function scope( $scope ) {
		return (array) ( self::API_SCOPES[ (string) $scope ] ?? array( 'workspace' => '', 'operation' => '' ) );
	}

	/**
	 * Return whether a value is a known event type.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_event( $value ) {
		return is_string( $value ) && isset( self::EVENTS[ $value ] );
	}

	/**
	 * Return whether an event may be delivered outside the workspace.
	 *
	 * @param string $event Event type.
	 * @return bool
	 */
	public static function is_public_event( $event ) {
		return ! empty( self::EVENTS[ (string) $event ]['public'] );
	}

	/**
	 * Return every event type, optionally filtered by group.
	 *
	 * @param string $group Group, or empty for all.
	 * @return array<int, string>
	 */
	public static function event_types( $group = '' ) {
		$out = array();

		foreach ( self::EVENTS as $type => $event ) {
			if ( '' !== (string) $group && (string) $event['group'] !== (string) $group ) {
				continue;
			}
			$out[] = (string) $type;
		}

		return $out;
	}

	/**
	 * Return whether a value is a known automation trigger.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_automation_trigger( $value ) {
		return is_string( $value ) && isset( self::AUTOMATION_TRIGGERS[ $value ] );
	}

	/**
	 * Return whether a value is a known automation action.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_automation_action( $value ) {
		return is_string( $value ) && isset( self::AUTOMATION_ACTIONS[ $value ] );
	}

	/**
	 * Return whether a value is a known automation state.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_automation_state( $value ) {
		return is_string( $value ) && isset( self::AUTOMATION_STATES[ $value ] );
	}

	/**
	 * Return whether a value is a known webhook state.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_webhook_state( $value ) {
		return is_string( $value ) && isset( self::WEBHOOK_STATES[ $value ] );
	}

	/**
	 * Return whether a value is a known delivery state.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_delivery_state( $value ) {
		return is_string( $value ) && isset( self::DELIVERY_STATES[ $value ] );
	}

	/**
	 * Return whether a delivery will never be attempted again.
	 *
	 * @param mixed $state State.
	 * @return bool
	 */
	public static function is_terminal_delivery( $state ) {
		return in_array( (string) $state, self::TERMINAL_DELIVERY_STATES, true );
	}

	/**
	 * Return whether a value is a known credential state.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_credential_state( $value ) {
		return is_string( $value ) && isset( self::CREDENTIAL_STATES[ $value ] );
	}

	/**
	 * Return the retry delay for a delivery attempt.
	 *
	 * Delegates to `Job_Limits::backoff_seconds()`, which is the retry schedule the job
	 * queue already uses, so a webhook and a job back off identically and there is one
	 * arithmetic to reason about. Phase 20 does not get its own backoff curve.
	 *
	 * @param int $attempt One-based attempt number.
	 * @return int Seconds to wait.
	 */
	public static function backoff_seconds( $attempt ) {
		return (int) Job_Limits::backoff_seconds( max( 1, (int) $attempt ), 'replicaforge-webhook' );
	}
}
