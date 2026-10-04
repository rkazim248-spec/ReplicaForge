<?php
/**
 * Correction limits and controlled vocabularies for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Every bound and vocabulary the correction engine uses.
 *
 * Nothing in Phase 6 invents a threshold, a property, an action, or a status at
 * runtime. The tables below are the whole contract, and the correction schema
 * version changes whenever they do.
 */
final class Correction_Limits {

	/** Phase identifier written into correction metadata. */
	const PHASE = '6.0';

	/** Version of the machine-readable correction plan. */
	const SCHEMA_VERSION = '6.0';

	/** Version of the correction engine, used for cache and history invalidation. */
	const ENGINE_VERSION = '1.0';

	/** Prefix for every ReplicaForge correction post meta key. */
	const META_PREFIX = 'replicaforge_correction_';

	/** Option holding the bounded correction history. */
	const OPTION = 'replicaforge_corrections';

	/** Transient prefix for stored correction plans awaiting review. */
	const PLAN_PREFIX = 'replicaforge_correction_plan_';

	/** Lifetime of a stored plan awaiting review, in seconds. */
	const PLAN_TTL = 21600;

	/** Number of correction history records kept in the option. */
	const MAX_HISTORY = 25;

	/** Number of history change records kept per correction run. */
	const MAX_CHANGES_PER_RUN = 400;

	/** Maximum corrections in one plan. */
	const MAX_CORRECTIONS = 300;

	/** Maximum corrections a single apply request may apply. */
	const MAX_APPLY = 200;

	/** Elementor elements a plan may touch. */
	const MAX_TARGETS = 400;

	/**
	 * Correction levels, least invasive first.
	 *
	 * A level is never skipped upward automatically. Level 6 is only reachable
	 * from an explicit user request, never from a validation result.
	 *
	 * @var array<int, string>
	 */
	const LEVELS = array(
		1 => 'safe_deterministic',
		2 => 'structured_component',
		3 => 'layout',
		4 => 'responsive',
		5 => 'ai_assisted_planning',
		6 => 'section_regeneration',
	);

	/**
	 * Correction actions.
	 *
	 * `update`, `resize`, `reposition`, `responsive_update`, and `visibility` are
	 * property-level and can be applied automatically once reviewed. The rest
	 * change document structure and always require explicit approval.
	 *
	 * @var array<int, string>
	 */
	const ACTIONS = array(
		'update',
		'insert',
		'remove',
		'replace',
		'reorder',
		'resize',
		'reposition',
		'visibility',
		'responsive_update',
		'asset_update',
		'content_update',
	);

	/**
	 * Actions the automatic path may apply after human review.
	 *
	 * @var array<int, string>
	 */
	const AUTO_ACTIONS = array(
		'update',
		'resize',
		'reposition',
		'responsive_update',
		'visibility',
	);

	/**
	 * Actions that change structure and therefore always require explicit approval.
	 *
	 * @var array<int, string>
	 */
	const STRUCTURAL_ACTIONS = array(
		'insert',
		'remove',
		'replace',
		'reorder',
		'asset_update',
		'content_update',
	);

	/**
	 * Eligibility outcomes.
	 *
	 * `safe` may be applied in one click. `requires_review` is shown to the user
	 * and needs an explicit decision. `blocked` is never applied and is reported
	 * with a reason.
	 *
	 * @var array<int, string>
	 */
	const ELIGIBILITY = array(
		'safe',
		'requires_review',
		'blocked',
	);

	/**
	 * Per-correction outcome statuses.
	 *
	 * @var array<int, string>
	 */
	const STATUSES = array(
		'applied',
		'rejected',
		'blocked',
		'failed',
		'skipped',
		'rolled_back',
	);

	/**
	 * Correction batches, applied in this order.
	 *
	 * The order encodes correction dependencies: a parent container dimension
	 * must be corrected before a child width, and layout direction before spacing.
	 *
	 * @var array<int, string>
	 */
	const BATCHES = array(
		'structure',
		'dimensions',
		'direction',
		'widths',
		'spacing',
		'typography',
		'images',
		'colors',
		'responsive',
		'finetune',
	);

	/**
	 * Phase 5 difference categories that may become an automatic correction.
	 *
	 * A category that is absent from this list can still be reported to the user
	 * as blocked or as requiring review, but it is never converted into a plan
	 * entry that the applier will act on.
	 *
	 * @var array<int, string>
	 */
	const AUTO_CATEGORIES = array(
		'typography',
		'layout',
		'spacing',
		'color',
		'background',
		'border',
		'shadow',
		'image',
		'responsive',
		'link',
	);

	/**
	 * Difference properties that are never corrected automatically.
	 *
	 * These are structural, content-level, or depend on rendered evidence that
	 * ReplicaForge cannot verify from a document alone.
	 *
	 * @var array<int, string>
	 */
	const BLOCKED_PROPERTIES = array(
		'section_present',
		'section_order',
		'component_present',
		'text',
		'image_count',
		'column_count',
		'column_progression',
		'mobile_navigation',
		'rendered_comparison',
		'rendered_difference',
		'image_present',
		'aspect_ratio',
	);

	/** Minimum confidence a correction needs before it can be automatic. */
	const MIN_AUTO_CONFIDENCE = 0.6;

	/** Maximum number of corrections in a single iteration. */
	const MAX_ITERATION_CORRECTIONS = 60;

	/** Default maximum correction iterations. */
	const MAX_ITERATIONS = 3;

	/**
	 * Minimum overall measurement improvement required to continue iterating.
	 *
	 * A run stops when an iteration improves by less than this, so the engine
	 * cannot loop indefinitely on noise.
	 */
	const MIN_IMPROVEMENT = 0.5;

	/**
	 * Regressions larger than this are reported and block further iterations.
	 *
	 * @var array<string, float>
	 */
	const REGRESSION_THRESHOLDS = array(
		'overall'  => 2.0,
		'viewport' => 3.0,
		'group'    => 3.0,
	);

	/** Wall-clock budget for one apply request, in seconds. */
	const APPLY_TIME_BUDGET = 45;

	/** Maximum snapshots retained per draft. */
	const MAX_SNAPSHOTS = 5;

	/**
	 * Properties that may never be written, regardless of the source difference.
	 *
	 * The list is deliberately explicit so an addition to the property map cannot
	 * silently widen the write surface.
	 *
	 * @var array<int, string>
	 */
	const FORBIDDEN_CONTROLS = array(
		'html',
		'editor',
		'title',
		'link',
		'_elementor_custom',
		'custom_css',
		'html_tag',
		'background_image',
		'_transform_*',
	);

	/**
	 * Elementor element types a correction may target.
	 *
	 * @var array<int, string>
	 */
	const TARGET_EL_TYPES = array( 'container', 'widget' );
}
