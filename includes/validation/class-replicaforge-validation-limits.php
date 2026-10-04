<?php
/**
 * Phase 5 validation limits, tolerances, and controlled vocabularies.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Centralized bounds and vocabularies for the validation engine.
 *
 * Every threshold used by the comparison engine is declared here so a score can
 * be explained and reproduced. Nothing in Phase 5 invents a tolerance at runtime.
 */
final class Validation_Limits {

	/** Phase identifier written into validation metadata. */
	const PHASE = '5.0';

	/** Version of the two-sided comparison schema. */
	const SCHEMA_VERSION = '5.0';

	/** Version of the validation engine itself, used for cache invalidation. */
	const ENGINE_VERSION = '1.0';

	/** Option holding recent validation summaries. */
	const OPTION = 'replicaforge_validations';

	/** Transient prefix for complete validation results. */
	const RESULT_PREFIX = 'replicaforge_validation_result_';

	/** Lifetime of a stored validation result in seconds. */
	const RESULT_TTL = 604800;

	/** Number of validation summaries kept in the option. */
	const MAX_SUMMARIES = 25;

	/**
	 * Baseline viewports.
	 *
	 * These are defaults for comparison only. A renderer may capture a
	 * different size, but the comparison configuration records what was used.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	const VIEWPORTS = array(
		'desktop' => array(
			'label'  => 'Desktop',
			'width'  => 1440,
			'height' => 900,
		),
		'tablet'  => array(
			'label'  => 'Tablet',
			'width'  => 768,
			'height' => 1024,
		),
		'mobile'  => array(
			'label'  => 'Mobile',
			'width'  => 390,
			'height' => 844,
		),
	);

	/** Maximum sections read from either side. */
	const MAX_SECTIONS = 120;

	/** Maximum components read from either side. */
	const MAX_COMPONENTS = 600;

	/** Maximum differences recorded in one result. */
	const MAX_DIFFERENCES = 400;

	/** Maximum checks processed in one run, whether or not they become differences. */
	const MAX_CHECKS = 20000;

	/**
	 * Wall-clock budget for one comparison run, in seconds.
	 *
	 * When the budget is exhausted, recording stops and a warning is added to the
	 * result instead of silently truncating. The budget is a performance guard, not
	 * a measurement, and the result always states whether it was hit.
	 */
	const COMPARE_BUDGET_SECONDS = 20;

	/** Maximum Elementor elements walked in the generated document. */
	const MAX_GENERATED_ELEMENTS = 1500;

	/** Maximum generated stylesheet size read from disk in bytes. */
	const MAX_GENERATED_CSS_BYTES = 4194304;

	/** Maximum number of CSS rules parsed from the generated stylesheet. */
	const MAX_CSS_RULES = 20000;

	/** Maximum screenshots compared per viewport (source and generated). */
	const MAX_SCREENSHOTS = 6;

	/** Maximum screenshot bytes accepted from a render provider. */
	const MAX_SCREENSHOT_BYTES = 8388608;

	/** Maximum screenshot pixel dimension accepted on any axis. */
	const MAX_SCREENSHOT_EDGE = 6000;

	/** Render provider request timeout in seconds. */
	const RENDER_TIMEOUT = 30;

	/** Maximum differences retained in the machine-readable correction plan. */
	const MAX_CORRECTIONS = 200;

	/** Maximum differences sent to an AI provider for explanation. */
	const MAX_AI_DIFFERENCES = 60;

	/** Maximum bytes of measured data sent to an AI provider for explanation. */
	const MAX_AI_PAYLOAD_BYTES = 60000;

	/**
	 * Difference categories.
	 *
	 * Phase 13 adds five: `position`, `size`, `radius`, `layering`, and `alignment`.
	 *
	 * **Added here rather than in a Phase 13 class on purpose.** This constant is
	 * read by `Difference_Engine`, `Validation_Report`, `Correction_Plan`, the metric
	 * groups, and every Phase 5 to 9 test. A second list in a second class would mean
	 * two sources of truth for one vocabulary, and the drift this file's own history
	 * records would repeat. So Phase 13 extends the existing list in the existing
	 * place, and the eight categories §60 already names keep their existing names so
	 * nothing that depended on them changes.
	 *
	 * @var array<int, string>
	 */
	const CATEGORIES = array(
		'structure',
		'section_order',
		'component',
		'layout',
		'spacing',
		'typography',
		'color',
		'background',
		'border',
		'shadow',
		'image',
		'content',
		'link',
		'responsive',
		'navigation',
		'asset',
		'visibility',
		'interaction',
		// Phase 13. Five visual categories that had nowhere to live before. Each is a
		// real difference type from §60 that the earlier list could not express, and
		// each maps onto an existing metric group via `METRIC_GROUPS` below.
		'position',
		'size',
		'radius',
		'layering',
		'alignment',
	);

	/**
	 * Severity levels, most severe first.
	 *
	 * @var array<int, string>
	 */
	const SEVERITIES = array( 'critical', 'major', 'moderate', 'minor', 'informational' );

	/**
	 * Numeric comparison properties and their tolerance bands.
	 *
	 * `small` is the pass band, `medium` is the partial band, and anything
	 * larger is a failure. `weight` is the relative importance used when the
	 * category metric is computed.
	 *
	 * @var array<string, array<string, float>>
	 */
	const TOLERANCES = array(
		'length'      => array(
			'small'  => 4.0,
			'medium' => 12.0,
			'weight' => 1.0,
		),
		'font_size'   => array(
			'small'  => 2.0,
			'medium' => 6.0,
			'weight' => 1.5,
		),
		'ratio'       => array(
			'small'  => 0.02,
			'medium' => 0.08,
			'weight' => 1.5,
		),
		'color'       => array(
			'small'  => 12.0,
			'medium' => 40.0,
			'weight' => 1.0,
		),
		'weight_value' => array(
			'small'  => 0.0,
			'medium' => 0.0,
			'weight' => 1.0,
		),
		'line_height' => array(
			'small'  => 0.05,
			'medium' => 0.2,
			'weight' => 1.0,
		),
	);

	/**
	 * Category weights used for the overall measurement.
	 *
	 * @var array<string, float>
	 */
	const CATEGORY_WEIGHTS = array(
		'structure' => 2.0,
		'section_order' => 1.5,
		'component' => 1.5,
		'layout' => 1.5,
		'spacing' => 1.0,
		'typography' => 1.5,
		'color' => 1.0,
		'background' => 0.75,
		'border' => 0.5,
		'shadow' => 0.5,
		'image' => 1.0,
		'content' => 1.5,
		'link' => 1.0,
		'responsive' => 1.5,
		'navigation' => 0.75,
		'asset' => 0.75,
		'visibility' => 0.75,
		'interaction' => 0.5,
	);

	/**
	 * Metric groups reported to the user, in display order.
	 *
	 * @var array<string, array<int, string>>
	 */
	const METRIC_GROUPS = array(
		'structure'     => array( 'structure', 'section_order', 'component' ),
		// `position`, `size`, `layering`, and `alignment` join `layout` here, and
		// `radius` joins `detail`: the five Phase 13 categories are added to the
		// groups that already describe them, so a visual finding contributes to the
		// same category metric as the structural finding it corroborates. Without
		// this they would be classified, stored, and then silently excluded from
		// every score — which is the specific way an "extended" vocabulary fails to
		// actually be used.
		'layout'        => array( 'layout', 'position', 'size', 'layering', 'alignment' ),
		'spacing'       => array( 'spacing' ),
		'typography'    => array( 'typography' ),
		'colors'        => array( 'color', 'background' ),
		'assets'        => array( 'asset', 'image' ),
		'content'       => array( 'content', 'link' ),
		'responsive'    => array( 'responsive', 'navigation', 'visibility' ),
		'detail'        => array( 'border', 'shadow', 'interaction', 'radius' ),
	);
	/**
	 * Perceptual thresholds for rendered image comparison, 0..1.
	 *
	 * `anti_alias` absorbs font rasterization and sub-pixel differences so that
	 * harmless browser rendering noise is not reported as a design difference.
	 *
	 * @var array<string, float>
	 */
	const IMAGE_TOLERANCES = array(
		'anti_alias' => 0.08,
		'noticeable' => 0.2,
		'major'      => 0.45,
	);

	/** Comparison bands used when collapsing image difference into a score. */
	const IMAGE_BAND_SMALL = 0.02;

	/** Medium image difference band. */
	const IMAGE_BAND_MEDIUM = 0.12;

	/**
	 * Image difference band widths used to locate changed regions.
	 *
	 * @var array<int, int>
	 */
	const IMAGE_BANDS = array( 16, 8, 4 );

	/** Validation levels, most capable last. */
	const LEVELS = array(
		1 => 'structural_comparison',
		2 => 'design_system_comparison',
		3 => 'rendered_visual_comparison',
		4 => 'ai_explanation',
	);
}
