<?php
/**
 * Phase 20: the public extension contract.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The single interface a ReplicaForge extension implements.
 *
 * ### Why one interface rather than eight
 *
 * The obvious design is one interface per capability — `AnalyzerInterface`,
 * `ComponentProviderInterface`, and so on. It is worse, for a reason specific to this
 * platform: **extension manifests are data, not code.**
 *
 * A provider is registered with a manifest that declares its capabilities in an array. A
 * per-capability interface would let PHP check at *compile* time that a class implements
 * `AnalyzerInterface`, but it would say nothing about whether the class that declared
 * `"capabilities": ["analyzer"]` in its manifest is the class that implements it. The
 * mismatch is possible, and at that point the failure is a fatal deep inside a pipeline
 * rather than a refusal at registration.
 *
 * So the guarantee is made where it can actually be made. {@see Extension_Registry::register()}
 * requires that every capability a manifest declares has a **callable method of the
 * corresponding name** on the provider, and refuses the registration with the missing method
 * named. That is checked against the same data that grants the capability, which is a
 * stronger guarantee than a type hint, and it produces a refusal an operator can read.
 *
 * ### The security shape, and why it is structural
 *
 * Look at what a method receives. There is no `$wpdb`. No path. No callable. No user object
 * to impersonate. No secret.
 *
 * That is the whole enforcement mechanism for "extensions must not bypass core security",
 * and it is deliberate: it is enforced by the shape of the interface rather than by a rule
 * somewhere that a future method could forget to follow. An extension cannot run SQL because
 * nothing hands it a connection. It cannot read a file because nothing hands it a path. It
 * cannot escalate because {@see Platform_Limits::FORBIDDEN_EXTENSION_PERMISSIONS} are
 * refused at manifest validation before a provider is ever constructed.
 *
 * ### Methods are capability-gated
 *
 * A method is only ever called for a capability the manifest declared, and only when the
 * registration also granted the permission that method needs. {@see self::METHODS} is the
 * authoritative map, and {@see Extension_Registry} uses it for both checks.
 *
 * ### Everything a provider returns is untrusted
 *
 * Every return value goes back through ReplicaForge's own validation before it is stored or
 * rendered. An analyzer's observations are validated as observations. A template provider's
 * package is handed to Phase 19's `Template_Package::inspect()` — which scans it, checks
 * its schema version, and applies the widget and setting allowlists — and the extension has
 * no say in the outcome. A validation provider's findings cannot mark anything valid;
 * {@see Template_Validator} owns the verdict.
 *
 * @package ReplicaForge
 */
interface Extension_Provider_Contract {

	/**
	 * The capability to required method name.
	 *
	 * The authoritative map. `Extension_Registry` requires every declared capability to
	 * have a callable method here, and uses the same entry to decide which permission the
	 * method needs.
	 *
	 * @var array<string, array{method: string, permission: string}>
	 */
	const METHODS = array(
		'analyzer'             => array( 'method' => 'observe', 'permission' => 'analysis.result.write' ),
		'component_provider'    => array( 'method' => 'components', 'permission' => 'component.register' ),
		'template_provider'     => array( 'method' => 'template', 'permission' => 'template.register' ),
		'content_mapper'        => array( 'method' => 'map_content', 'permission' => 'project.analysis.read' ),
		'validation_provider'   => array( 'method' => 'validate', 'permission' => 'validation.result.write' ),
		'export_provider'       => array( 'method' => 'export', 'permission' => 'template.metadata.read' ),
		'notification_provider' => array( 'method' => 'notify', 'permission' => 'webhook.event.read' ),
		'automation'            => array( 'method' => 'automations', 'permission' => 'workflow.trigger' ),
	);

	/**
	 * Contribute analysis observations.
	 *
	 * Called with a **representation** — the Phase 2 output ReplicaForge already holds — and
	 * a context of `project_id`, `workspace_id`, `page_id` and `event_id`. Nothing here is
	 * writable: the return value is merged into a fresh observation set and stored by
	 * ReplicaForge, never applied to anything.
	 *
	 * The provider declares which keys it wants to read in its manifest's
	 * `configuration.schema`, and this call is refused for a representation it has not been
	 * granted `project.analysis.read` for.
	 *
	 * @param array<string, mixed> $representation The stored representation.
	 * @param array<string, mixed> $context        Call context.
	 * @return array<string, mixed> Observations. Scalars and arrays of scalars only; a value
	 *                               ReplicaForge cannot reduce to that is replaced by a
	 *                               description of itself, exactly as in every other boundary
	 *                               in the plugin.
	 */
	public function observe( array $representation, array $context = array() );

	/**
	 * Supply reusable component descriptors.
	 *
	 * @param array<string, mixed> $context Call context, including the template snapshot.
	 * @return array<string, array<string, mixed>> Component descriptors keyed by id.
	 */
	public function components( array $context = array() );

	/**
	 * Supply a reusable template package.
	 *
	 * The return value is a **package** in the Phase 19 format. It is not installed by this
	 * call: {@see Extension_Registry} hands it to `Template_Package::inspect()` and the
	 * result of that is what gets stored. An extension cannot bypass the security pipeline
	 * by returning a package, because the pipeline runs on its output regardless.
	 *
	 * @param array<string, mixed> $context Call context.
	 * @return array<string, mixed> A template package, or an empty array for none.
	 */
	public function template( array $context = array() );

	/**
	 * Map source fields onto declared destinations.
	 *
	 * The provider chooses *source fields* and *destination fields* from the ones the
	 * destination already declares. It cannot introduce a new destination, which is what
	 * stops a mapper from writing somewhere ReplicaForge did not offer.
	 *
	 * @param array<string, mixed> $source      Extracted source entities.
	 * @param array<int, string>    $destinations Allowed destination field ids.
	 * @param array<string, mixed>  $context     Call context.
	 * @return array<int, array<string, mixed>> Mapping proposals, each naming a source field
	 *                                          and a destination from `$destinations`.
	 */
	public function map_content( array $source, array $destinations, array $context = array() );

	/**
	 * Add validation checks.
	 *
	 * Returns findings. It cannot return a verdict: {@see Template_Validator} merges the
	 * findings and owns the state, so a provider cannot mark a template valid.
	 *
	 * @param array<string, mixed> $subject  The thing being validated.
	 * @param array<string, mixed> $context  Call context.
	 * @return array<int, array<string, mixed>> Findings, each with `code`, `severity` and
	 *                                             `message`. An unrecognised severity is
	 *                                             downgraded to `notice`.
	 */
	public function validate( array $subject, array $context = array() );

	/**
	 * Supply an additional export format.
	 *
	 * Returns a **string**. There is no path parameter and no write instruction, so a
	 * provider cannot choose where a file goes; the caller writes it wherever it already
	 * writes exports.
	 *
	 * @param array<string, mixed> $context Call context, including the export payload.
	 * @return string The serialised document.
	 */
	public function export( array $context = array() );

	/**
	 * Deliver a notification.
	 *
	 * Delivery goes through Phase 15's `Notification_Service`, so a provider never holds a
	 * recipient address or a channel credential. It returns whether the service accepted
	 * the request, not whether anything was delivered.
	 *
	 * @param array<string, mixed> $context Call context including `workspace_id` and `type`.
	 * @return bool
	 */
	public function notify( array $context = array() );

	/**
	 * Return the automations this extension wants registered.
	 *
	 * @param array<string, mixed> $context Call context including `workspace_id`.
	 * @return array<int, array<string, mixed>> Automation definitions. Each is validated
	 *                                          against {@see Platform_Limits::AUTOMATION_TRIGGERS}
	 *                                          and {@see Platform_Limits::AUTOMATION_ACTIONS}
	 *                                          and an invalid one is dropped with a reason.
	 */
	public function automations( array $context = array() );
}
