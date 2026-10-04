<?php
/**
 * ReplicaForge - secure frontend analyzer, reconstruction planner, Elementor generator, visual validation, and correction engine.
 *
 * @package ReplicaForge
 *
 * Plugin Name:       ReplicaForge
 * Description:       Analyze a public website frontend, understand its design, build an editable Elementor draft, measure the differences, and correct the measurable ones.
 * Version:           1.5.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            ReplicaForge
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       replicaforge
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'REPLICAFORGE_VERSION' ) ) {
	define( 'REPLICAFORGE_VERSION', '1.5.0' );
}

if ( ! defined( 'REPLICAFORGE_FILE' ) ) {
	define( 'REPLICAFORGE_FILE', __FILE__ );
}

if ( ! defined( 'REPLICAFORGE_PATH' ) ) {
	define( 'REPLICAFORGE_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'REPLICAFORGE_URL' ) ) {
	define( 'REPLICAFORGE_URL', plugin_dir_url( __FILE__ ) );
}

require_once REPLICAFORGE_PATH . 'includes/css/class-replicaforge-css-value-parser.php';
require_once REPLICAFORGE_PATH . 'includes/layout/class-replicaforge-layout-engine.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-effects.php';
require_once REPLICAFORGE_PATH . 'includes/security/class-replicaforge-svg-sanitizer.php';
require_once REPLICAFORGE_PATH . 'includes/security/class-replicaforge-security-audit.php';
require_once REPLICAFORGE_PATH . 'includes/tokens/class-replicaforge-token-engine.php';
require_once REPLICAFORGE_PATH . 'includes/projects/class-replicaforge-project-repository.php';

// Phase 9: source monitoring and incremental synchronisation.
require_once REPLICAFORGE_PATH . 'includes/sync/class-replicaforge-sync-limits.php';
require_once REPLICAFORGE_PATH . 'includes/sync/class-replicaforge-elementor-map.php';
require_once REPLICAFORGE_PATH . 'includes/sync/class-replicaforge-component-matcher.php';
require_once REPLICAFORGE_PATH . 'includes/sync/class-replicaforge-sync-conflict-detector.php';
require_once REPLICAFORGE_PATH . 'includes/sync/class-replicaforge-change-detector.php';
require_once REPLICAFORGE_PATH . 'includes/sync/class-replicaforge-change-classifier.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-security.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-error-catalog.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-request-context.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-data-redactor.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-logger.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-feature-flags.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-schema.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-url-validator.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-analysis-limits.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-http-client.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-html-parser.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-content-extractor.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-design-extractor.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-structure-detector.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-analysis-normalizer.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-dom-analyzer.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-stylesheet-loader.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-style-analyzer.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-typography-analyzer.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-layout-analyzer.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-responsive-analyzer.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-section-detector.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-component-detector.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-representation-validator.php';
require_once REPLICAFORGE_PATH . 'includes/interface-replicaforge-design-representation-contract.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-design-representation.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-design-analyzer.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-limits.php';
require_once REPLICAFORGE_PATH . 'includes/ai/interface-replicaforge-ai-provider.php';
require_once REPLICAFORGE_PATH . 'includes/ai/interface-replicaforge-ai-cache.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-settings.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-transient-cache.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-context-builder.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-prompt-builder.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-reconstruction-planner.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-output-validator.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-provider-http.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-provider-openai.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-provider-gemini.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-manager.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-limits.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-values.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-compatibility.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-spec-validator.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-widget-registry.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-responsive.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-mapper.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-document-builder.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-assets.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-validator.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-repository.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-draft-service.php';
require_once REPLICAFORGE_PATH . 'includes/elementor/class-replicaforge-elementor-generator.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-validation-limits.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-comparison-schema.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-source-representation-adapter.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-generated-page-analyzer.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-comparison-context.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-difference-engine.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-structural-comparator.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-layout-comparator.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-typography-comparator.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-color-comparator.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-spacing-comparator.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-asset-comparator.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-responsive-comparator.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-visual-renderer.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-image-differ.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-validation-metrics.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-correction-plan.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-validation-cache.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-ai-validation-explainer.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-validation-report.php';
require_once REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-validation-engine.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-correction-limits.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-correction-property-map.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-elementor-document-reader.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-elementor-document-writer.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-correction-snapshot.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-correction-eligibility.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-correction-planner.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-correction-validator.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-correction-history.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-regression-detector.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-correction-applier.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-correction-report.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-ai-correction-planner.php';
require_once REPLICAFORGE_PATH . 'includes/corrections/class-replicaforge-correction-engine.php';
// The Phase 11 state vocabulary is declared before the Phase 7 job classes,
// because `Job_Limits::STATUSES` is now built from `Job_States` rather than
// restating it. Two definitions of the same list would drift, and the drift would
// show up as a job status that one class accepts and another refuses.
require_once REPLICAFORGE_PATH . 'includes/jobs/class-replicaforge-job-states.php';
require_once REPLICAFORGE_PATH . 'includes/jobs/class-replicaforge-job-limits.php';
require_once REPLICAFORGE_PATH . 'includes/jobs/class-replicaforge-job-repository.php';
require_once REPLICAFORGE_PATH . 'includes/jobs/class-replicaforge-job-queue.php';
require_once REPLICAFORGE_PATH . 'includes/jobs/class-replicaforge-job-runner.php';
require_once REPLICAFORGE_PATH . 'includes/jobs/class-replicaforge-job-api.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-maintenance.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-migrator.php';

// Phase 10: plans, entitlements, usage accounting, licensing, and capabilities.
// Licensing before plans: License_Manager is a dependency of Plan_Manager, which is
// a dependency of Entitlement_Manager. The requires are explicit rather than
// autoloaded, so the order is the load order.
require_once REPLICAFORGE_PATH . 'includes/licensing/interface-replicaforge-license-provider.php';
require_once REPLICAFORGE_PATH . 'includes/licensing/interface-replicaforge-billing-provider.php';
require_once REPLICAFORGE_PATH . 'includes/licensing/class-replicaforge-license-state.php';
require_once REPLICAFORGE_PATH . 'includes/licensing/class-replicaforge-local-license-provider.php';
require_once REPLICAFORGE_PATH . 'includes/licensing/class-replicaforge-license-manager.php';
require_once REPLICAFORGE_PATH . 'includes/plans/class-replicaforge-plan-limits.php';
require_once REPLICAFORGE_PATH . 'includes/plans/class-replicaforge-plan-definition.php';
require_once REPLICAFORGE_PATH . 'includes/plans/class-replicaforge-plan-storage.php';
require_once REPLICAFORGE_PATH . 'includes/plans/class-replicaforge-audit-log.php';
require_once REPLICAFORGE_PATH . 'includes/plans/class-replicaforge-usage-manager.php';
require_once REPLICAFORGE_PATH . 'includes/plans/class-replicaforge-plan-manager.php';
require_once REPLICAFORGE_PATH . 'includes/plans/class-replicaforge-entitlement-manager.php';
require_once REPLICAFORGE_PATH . 'includes/plans/class-replicaforge-feature-gate.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-capabilities.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-project-access.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-project-status.php';
require_once REPLICAFORGE_PATH . 'includes/plans/class-replicaforge-onboarding.php';
require_once REPLICAFORGE_PATH . 'includes/plans/class-replicaforge-plans-api.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-system-status.php';

// Phase 11: AI reliability and cost control. Declared before the job classes
// because the orchestrator and the runner both ask the capability and failure
// tables what they are dealing with.
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-capabilities.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-failures.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-context-budget.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-cost-estimator.php';
require_once REPLICAFORGE_PATH . 'includes/ai/class-replicaforge-ai-usage-audit.php';

// Phase 11: the job reliability layer. These sit beside the Phase 7 job classes
// rather than replacing them: `Job_Repository`, `Job_Queue`, and `Job_Runner` are
// unchanged and still own their jobs.
require_once REPLICAFORGE_PATH . 'includes/jobs/class-replicaforge-job-lock.php';
require_once REPLICAFORGE_PATH . 'includes/jobs/class-replicaforge-job-checkpoint.php';
require_once REPLICAFORGE_PATH . 'includes/jobs/class-replicaforge-job-recovery.php';
require_once REPLICAFORGE_PATH . 'includes/jobs/class-replicaforge-job-cancellation.php';
require_once REPLICAFORGE_PATH . 'includes/jobs/class-replicaforge-job-manager.php';

// Phase 12: multi-page reconstruction. Load order is significant in one place
// only. `Site_Limits::families()` reads `Token_Engine::FAMILIES` at *runtime*, not
// at load time, so the token engine (line 41) may come earlier or later; but
// `Site_Limits::page_limit_for()` reads `Plan_Limits::PLAN_ORDER` and
// `Plan_Limits::plan_rank()`, so the plans layer must be required first. Everything
// else here is a class that only *calls* its collaborators, so the sequence within
// the block is by dependency, not by reference.
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-site-limits.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-page-discovery.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-page-classifier.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-site-design-system.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-shared-component-detector.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-component-registry.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-navigation-mapper.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-asset-registry.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-site-representation.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-site-compatibility.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-cross-page-validator.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-multipage-planner.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-website-repository.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-site-analyzer.php';
require_once REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-multipage-api.php';

// Phase 13: advanced visual intelligence. Load order is by dependency here, not
// by reference, because the only class that reads another's constant at *load* time
// is `Visual_Limits`, which reads `Validation_Limits::VIEWPORTS` and
// `Token_Engine::FAMILIES` at *runtime* — so the plans and validation blocks above
// only need to be loaded before the first *call*, not before these requires.
//
// The two interfaces come first because six classes implement them, and
// `Visual_Limits` comes before the classes that call its helpers. Nothing here
// references an Elementor class, which is deliberate: the visual representation is
// required to be independent from Elementor, and a require list that implied
// otherwise would be a lie about the layering.
require_once REPLICAFORGE_PATH . 'includes/visual/interface-replicaforge-renderer.php';
require_once REPLICAFORGE_PATH . 'includes/visual/interface-replicaforge-image-reader.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-limits.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-image-readers.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-renderer-manager.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-analyzer.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-features.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-dynamic-detector.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-representation.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-comparator.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-render-cache.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-corrector.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-ai.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-render-job.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-api.php';
require_once REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-api.php';

// Phase 16: interaction intelligence.
//
// The order below is a dependency chain, not a reading order:
//
//   Interaction_Limits       the vocabulary. Every other class reads it and
//                            declares no list of its own.
//   Browser_Driver_Contract  the observation seam, loaded before anything that
//                            might implement or inject a driver.
//   State_Machine            the unit of modelling.
//   Interaction_Model        the aggregate and the validation gate.
//   Interaction_Detector     static detection, which needs nothing above it.
//   Interaction_Mapper       resolves Elementor capabilities at runtime.
//   Interaction_Validator    the gate an interaction must pass before use.
//   Interaction_Service      orchestration, budgets, honest statuses.
//   Interaction_Api          REST.
//
// Interaction_Limits comes first because it *reads* Validation_Limits::SEVERITIES
// and Validation_Limits::VIEWPORTS rather than restating them, so the plugin keeps
// one home per vocabulary. The same rule is why there is no Phase 16 severity list
// and no Phase 16 viewport list.
require_once REPLICAFORGE_PATH . 'includes/interactions/class-replicaforge-interaction-limits.php';
require_once REPLICAFORGE_PATH . 'includes/interactions/interface-replicaforge-browser-driver.php';
require_once REPLICAFORGE_PATH . 'includes/interactions/class-replicaforge-state-machine.php';
require_once REPLICAFORGE_PATH . 'includes/interactions/class-replicaforge-interaction-model.php';
require_once REPLICAFORGE_PATH . 'includes/interactions/class-replicaforge-interaction-detector.php';
require_once REPLICAFORGE_PATH . 'includes/interactions/class-replicaforge-interaction-mapper.php';
require_once REPLICAFORGE_PATH . 'includes/interactions/class-replicaforge-interaction-validator.php';
require_once REPLICAFORGE_PATH . 'includes/interactions/class-replicaforge-interaction-service.php';
require_once REPLICAFORGE_PATH . 'includes/interactions/class-replicaforge-interaction-api.php';

// Phase 17: the reconstruction orchestrator. Loaded after every phase it coordinates, so
// that its probes can measure them rather than assume they exist.
require_once REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-orchestrator-limits.php';
require_once REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-capability-registry.php';
require_once REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-workflow-definition.php';
require_once REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-workflow-artifacts.php';
require_once REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-workflow-repository.php';
require_once REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-preflight.php';
require_once REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-quality-gate.php';
require_once REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-workflow-report.php';
require_once REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-workflow-executor.php';
require_once REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-orchestrator-api.php';
require_once REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-orchestrator-admin.php';

// Phase 14: content and data intelligence. Order is by dependency here, and it is
// a real dependency rather than a presentational one:
// `Content_Limits` reads `Sync_Conflict_Detector::OWNERSHIP` and
// `Validation_Limits::CATEGORIES` at *runtime*, but `Source_Content_Model` reads
// `Content_Limits::BUCKETS` and `Content_Fingerprint::of()` at *call* time, so the
// ordering below is what guarantees a class is declared before anything constructs it.
//
// The interface comes first because three classes implement it. `Content_Limits` comes
// before the analyzer because every other file calls into its vocabulary.
require_once REPLICAFORGE_PATH . 'includes/content/interface-replicaforge-content-provider.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-limits.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-fingerprint.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-structured-data.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-role-detector.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-source-content-model.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-wordpress-content-provider.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-woocommerce-provider.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-mapper.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-entity-matcher.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-validator.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-cache.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-preview.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-applier.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-service.php';
require_once REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-api.php';
// Phase 15: enterprise collaboration, project management and team workflows.
//
// The order below is a dependency order, not a presentational one. `Workspace_Limits` holds
// the capability vocabulary and the table names, so it has to exist before anything that
// reads either. `Collaboration_Schema` names its tables through that vocabulary.
// `Collaboration_Store` is the abstract gateway every concrete store extends, and the
// concrete stores reference each other at *call* time (a client store reaching for its
// contacts, a project context reaching for reviews), so their relative order is not
// load-bearing - but `Permission_Manager` composes several of them, so it comes after.
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-workspace-limits.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-collaboration-schema.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-collaboration-store.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-workspace-store.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-workspace-member-store.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-project-member-store.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-client-contact-store.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-client-store.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-review-store.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-project-context-store.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-task-store.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-comment-store.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-issue-store.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-collaboration-log.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-secure-token.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-notification-service.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-invitation-service.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-review-link-service.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-workspace-admin.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-workspace-api.php';
require_once REPLICAFORGE_PATH . 'includes/workspace/class-replicaforge-permission-manager.php';

/*
 * Phase 19: the template and design-system library.
 *
 * Placed after the workspace block because the three stores extend `Collaboration_Store`,
 * and PHP resolves a parent class when the child's file is *included*, not when the method
 * is called. Loading them earlier is a fatal on `class not found`, and the failure would
 * appear on a request that never touches a template.
 *
 * The order inside the block is dependency order: constants first, then the pure
 * registries, then the security gate, then the stores, then the pipeline that uses them.
 */
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-limits.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-design-token-registry.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-content-slot-registry.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-sanitizer.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-dependencies.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-store.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-version-store.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-component-store.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-validator.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-extractor.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-conflicts.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-package.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-installer.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-quality.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-api.php';
require_once REPLICAFORGE_PATH . 'includes/templates/class-replicaforge-template-admin.php';

/* ---------------------------------------------------------------------------
 * Phase 20: the developer extensibility platform.
 *
 * Loaded last, and after both Phase 17 (orchestrator) and Phase 19 (templates), because
 * almost everything here composes them rather than standing alone:
 *
 * - `Automation_Runner::start_workflow()` calls Phase 17's `Workflow_Executor::run()`. It
 *   does not have its own stages, gates, retries or budgets, and loading it before the
 *   orchestrator would put a class whose only job is to delegate above the thing it
 *   delegates to.
 * - `Platform_Limits::backoff_seconds()` delegates to `Job_Limits::backoff_seconds()`, so a
 *   webhook and a job back off on one curve.
 * - Every store extends Phase 15's `Collaboration_Store`, which PHP resolves when the
 *   child's file is *included* rather than when the method runs — so this block has to sit
 *   after the workspace block above.
 * - `Api_Credential_Store` and `Webhook_Signer` use Phase 15's `Secure_Token`, and
 *   `Developer_Api` composes `Workspace_Store`, `Project_Repository` and
 *   `Permission_Manager`.
 *
 * Within the block the order is the same shape: limits and interfaces first (they have no
 * dependencies), then the pure logic that depends only on them (configuration, manifest,
 * signer, rate limiter), then the stores (which need the shared base class), then the
 * orchestration pieces, and finally the HTTP surface.
 */
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-platform-limits.php';
require_once REPLICAFORGE_PATH . 'includes/platform/interface-replicaforge-extension-provider.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-extension-configuration.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-extension-manifest.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-extension-store.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-extension-registry.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-api-credential-store.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-webhook-signer.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-webhook-store.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-webhook-delivery-store.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-webhook-delivery.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-event-store.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-event-dispatcher.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-automation-store.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-automation-runner.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-rate-limiter.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-api-authenticator.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-developer-api.php';
require_once REPLICAFORGE_PATH . 'includes/platform/class-replicaforge-developer-admin.php';

require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-analyzer.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-rest-api.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-admin.php';
require_once REPLICAFORGE_PATH . 'includes/class-replicaforge-plugin.php';

if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, array( '\\ReplicaForge\\Plugin', 'activate' ) );
	register_deactivation_hook( __FILE__, array( '\\ReplicaForge\\Plugin', 'deactivate' ) );
}

\ReplicaForge\Plugin::instance()->boot();
