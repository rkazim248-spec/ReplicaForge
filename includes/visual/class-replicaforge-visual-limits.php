<?php
/**
 * Phase 13: the visual intelligence vocabulary and bounds.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Every name, type, relationship, and bound the visual layer uses.
 *
 * ### Why this file exists, again
 *
 * Phases 10, 11, and 12 each introduced a vocabulary class, and the reason is the
 * same each time: a name spelled independently in the analyzer, the comparator, the
 * corrector, and the REST layer is a rename that becomes a silent behaviour change in
 * whichever copy was missed. §83's test list is long enough that the vocabulary will
 * be read in four or five places.
 *
 * ### What it deliberately does *not* define
 *
 * The **difference categories are not redefined here.** They belong to Phase 5's
 * {@see Validation_Limits::CATEGORIES}, which the existing `Difference_Engine`,
 * `Validation_Report`, `Correction_Plan`, and every Phase 5–9 test already depend on.
 * Restating them would fork the vocabulary in the one place where it is most
 * expensive. Instead {@see self::add_categories()} *extends* the existing list, and
 * `Validation_Limits::CATEGORIES` remains the single source of truth. Phase 12's
 * lesson applied in reverse: extend a stable list rather than replace it.
 */
final class Visual_Limits {

	/**
	 * Visual representation schema version.
	 *
	 * Separate from the page representation's `2.0` and the website's `12.0`. A visual
	 * representation answers a different question from either — "what does this look
	 * like" rather than "what is this page" or "what is this website" — and the three
	 * change for different reasons. Reusing one version number would make a cache key
	 * ambiguous.
	 */
	const SCHEMA_VERSION = '13.0';

	/**
	 * The visual analyzer version, folded into every cache key.
	 *
	 * A cache key that omits the analyzer version returns a stale representation after
	 * the analyzer improves, and the stale result is indistinguishable from a correct
	 * one. So it is a constant that must be bumped, and the constant is why the key
	 * is honest.
	 */
	const ANALYZER_VERSION = '1.0';

	/* ---------------------------------------------------------------------
	 * Viewports
	 * ------------------------------------------------------------------ */

	/**
	 * The viewport profiles §6 requires.
	 *
	 * Read from {@see Validation_Limits::VIEWPORTS} rather than restated, because
	 * Phase 5 already renders validation at exactly these three and a fourth
	 * definition would mean the Phase 5 report and the Phase 13 report disagree about
	 * what "tablet" means.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function viewports() {
		return Validation_Limits::VIEWPORTS;
	}

	/**
	 * Return the default device pixel ratio per viewport.
	 *
	 * One everywhere by default. A DPR above one multiplies every pixel the renderer
	 * produces by its square, so a 1440-wide page at DPR 2 is 2880 pixels wide and
	 * four times the memory — and a comparison between a DPR-1 source and a DPR-2
	 * replica is not a comparison of the same thing.
	 *
	 * @param string $viewport Viewport name.
	 * @return float
	 */
	public static function default_dpr( $viewport ) {
		$overrides = self::dpr_overrides();
		$viewport  = (string) $viewport;
		return (float) ( $overrides[ $viewport ] ?? 1.0 );
	}

	/**
	 * Return configured DPR overrides.
	 *
	 * Filterable, and clamped, because a DPR of 4 on a 1440-wide page is a 5760-pixel
	 * image and a memory problem rather than a fidelity gain.
	 *
	 * @return array<string, float>
	 */
	private static function dpr_overrides() {
		$overrides = array();
		$configured = apply_filters( 'replicaforge_visual_dpr', array() );
		if ( is_array( $configured ) ) {
			foreach ( $configured as $name => $ratio ) {
				if ( ! is_string( $name ) || ! is_numeric( $ratio ) ) {
					continue;
				}
				$overrides[ $name ] = max( 1.0, min( 3.0, (float) $ratio ) );
			}
		}
		return $overrides;
	}

	/* ---------------------------------------------------------------------
	 * Visual relationships
	 * ------------------------------------------------------------------ */

	/**
	 * The spatial relationships §13 lists.
	 *
	 * A closed list. A relationship detector with unbounded output produces
	 * `aligned_left` and `left_aligned` and `alignsWithLeft` for one fact, and a
	 * consumer cannot filter that without knowing every spelling.
	 *
	 * @var array<int, string>
	 */
	const RELATIONSHIPS = array(
		'aligned_left',
		'aligned_right',
		'centered',
		'same_width',
		'same_height',
		'same_baseline',
		'equal_spacing',
		'overlapping',
		'contained',
		'adjacent',
		'stacked',
		'anchored',
		'floating',
	);

	/**
	 * Relationships that describe an *obstruction* rather than an alignment.
	 *
	 * Separated because they are handled differently: an alignment is a hint about
	 * layout, while an overlap is a claim about layer order that reconstruction has to
	 * respect or the result visibly breaks.
	 *
	 * @var array<int, string>
	 */
	const LAYER_RELATIONSHIPS = array( 'overlapping', 'floating', 'anchored', 'contained' );

	/* ---------------------------------------------------------------------
	 * Geometry
	 * ------------------------------------------------------------------ */

	/**
	 * The container width patterns §17 names.
	 *
	 * @var array<int, string>
	 */
	const CONTAINER_PATTERNS = array( 'full_width_section', 'contained_content', 'nested_container', 'edge_to_edge', 'max_width', 'fluid' );

	/**
	 * Grid inference bounds.
	 *
	 * `MIN_COLUMNS` is 2 because one column is a stack, not a grid. `MAX_COLUMNS` is 12
	 * because a "grid" of 40 items in one row is a table and a reconstruction target
	 * nobody wants.
	 *
	 * @var int
	 */
	const MIN_COLUMNS = 2;

	/**
	 * @var int
	 */
	const MAX_COLUMNS = 12;

	/**
	 * Minimum samples before a grid's column count is claimed.
	 *
	 * Two items side by side is coincidence. Four is a pattern, and the same
	 * "three is a pattern" reasoning Phase 8 applied to tokens applies here.
	 *
	 * @var int
	 */
	const MIN_GRID_SAMPLES = 3;

	/* ---------------------------------------------------------------------
	 * Elements
	 * ------------------------------------------------------------------ */

	/**
	 * The button classifications §36 lists.
	 *
	 * @var array<int, string>
	 */
	const BUTTON_TYPES = array( 'primary', 'secondary', 'outline', 'ghost', 'link', 'icon_button' );

	/**
	 * The background roles §22 distinguishes.
	 *
	 * The distinction is the point. A content image becomes an Elementor image
	 * widget; a background image becomes a section background; a decorative image
	 * becomes *nothing*, because reconstructing it as a widget puts an image where
	 * the source had no content. Getting this wrong is the single most common way a
	 * "faithful" reconstruction ends up with a dozen stray image widgets.
	 *
	 * @var array<int, string>
	 */
	const IMAGE_ROLES = array( 'content', 'background', 'decorative', 'overlay' );

	/**
	 * The image fit modes §30 asks to be distinguished.
	 *
	 * @var array<int, string>
	 */
	const IMAGE_FITS = array( 'intrinsic', 'cover', 'contain', 'stretched', 'cropped' );

	/**
	 * The typography hierarchy levels §33 lists.
	 *
	 * @var array<int, string>
	 */
	const TYPE_SCALE = array( 'display', 'h1', 'h2', 'h3', 'body', 'small', 'caption', 'label', 'button', 'navigation' );

	/* ---------------------------------------------------------------------
	 * Dynamic content
	 * ------------------------------------------------------------------ */

	/**
	 * The transient-UI classifications §10 requires.
	 *
	 * @var array<int, string>
	 */
	const UI_CLASSES = array( 'temporary_ui', 'persistent_ui', 'unknown' );

	/**
	 * Why an element is considered dynamic.
	 *
	 * @var array<int, string>
	 */
	const DYNAMIC_REASONS = array(
		'timestamp',
		'carousel',
		'rotating_banner',
		'random_content',
		'advertisement',
		'live_counter',
		'animation',
		'video',
		'user_specific',
		'consent_ui',
		'chat_widget',
	);

	/**
	 * Whether a region is masked during comparison.
	 *
	 * §59's rule: a dynamic region must not dominate validation. A video that differs
	 * on every frame would otherwise report a critical difference and trigger a
	 * correction loop chasing a frame.
	 *
	 * @var array<string, bool>
	 */
	const MASK_REASONS = array(
		'timestamp'      => true,
		'live_counter'   => true,
		'carousel'       => true,
		'video'          => true,
		'advertisement'  => true,
		'random_content' => true,
		'user_specific'  => true,
		'chat_widget'    => true,
		'consent_ui'     => true,
		'rotating_banner'=> true,
		'animation'      => true,
	);

	/* ---------------------------------------------------------------------
	 * Responsive
	 * ------------------------------------------------------------------ */

	/**
	 * The responsive transformation types §41 lists.
	 *
	 * @var array<int, string>
	 */
	const TRANSFORMATIONS = array( 'resize', 'reflow', 'stack', 'hide', 'show', 'reorder', 'collapse', 'replace', 'overflow', 'scroll', 'wrap' );

	/* ---------------------------------------------------------------------
	 * Comparison
	 * ------------------------------------------------------------------ */

	/**
	 * The visual signals the comparator combines.
	 *
	 * Pixel difference alone is not a measure of visual fidelity: a 1px shift of a
	 * whole page and a 40px shift of one heading can produce similar differing-pixel
	 * ratios while meaning very different things. So geometry, colour, and structure
	 * are separate signals and the verdict combines them — §58.
	 *
	 * @var array<int, string>
	 */
	const SIGNALS = array( 'pixel', 'geometry', 'color', 'structure', 'typography', 'spacing' );

	/**
	 * Differing-pixel bands, reusing Phase 5's.
	 *
	 * The thresholds were already calibrated against real captures in Phase 5, so
	 * inventing a second set here would make a Phase 5 score and a Phase 13 score
	 * incomparable for the same two images.
	 *
	 * @return array<int, float>
	 */
	public static function bands() {
		return array(
			'small'  => Validation_Limits::IMAGE_BAND_SMALL,
			'medium' => Validation_Limits::IMAGE_BAND_MEDIUM,
		);
	}

	/**
	 * Visual severities, reusing Phase 5's.
	 *
	 * @return array<int, string>
	 */
	public static function severities() {
		return Validation_Limits::SEVERITIES;
	}

	/**
	 * The difference categories Phase 13 adds to Phase 5's vocabulary.
	 *
	 * Added, not substituted. §60 names twelve types; eight already exist in
	 * {@see Validation_Limits::CATEGORIES} and four do not. The four are added so a
	 * visual finding has a home, and the eight keep their existing names so every
	 * Phase 5–9 consumer continues to work untouched.
	 *
	 * @return array<int, string>
	 */
	public static function added_categories() {
		return array( 'position', 'size', 'radius', 'layering', 'alignment' );
	}

	/**
	 * Apply the visual categories to Phase 5's list.
	 *
	 * Called once at load. Idempotent, and a no-op if the categories already exist —
	 * so a future Phase that removes one cannot break this.
	 *
	 * @param array<int, string> $categories Existing categories.
	 * @return array<int, string>
	 */
	public static function add_categories( array $categories ) {
		foreach ( self::added_categories() as $category ) {
			if ( ! in_array( $category, $categories, true ) ) {
				$categories[] = $category;
			}
		}
		return $categories;
	}

	/* ---------------------------------------------------------------------
	 * Bounds
	 * ------------------------------------------------------------------ */

	/**
	 * Maximum elements carrying a bounding box.
	 *
	 * @var int
	 */
	const MAX_BOXED_ELEMENTS = 600;

	/**
	 * Maximum relationships recorded per representation.
	 *
	 * @var int
	 */
	const MAX_RELATIONSHIPS = 2000;

	/**
	 * Maximum geometry conflicts recorded.
	 *
	 * @var int
	 */
	const MAX_MEASUREMENT_CONFLICTS = 200;

	/**
	 * Maximum pixels examined in one comparison, per signal.
	 *
	 * A 1440×900 capture is 1.3M pixels; a full-page capture at DPR 2 is 5.3M. Reading
	 * every pixel of every signal in PHP is seconds of CPU, so the sampler is bounded
	 * and the sampling is *recorded* — a comparison that sampled 5% of pixels says so,
	 * because a number computed from a subsample is not the same claim as one computed
	 * from every pixel.
	 *
	 * @var int
	 */
	const MAX_SAMPLED_PIXELS = 40000;

	/**
	 * Grid rows used to bucket a page into regions for §62's region comparison.
	 *
	 * @var int
	 */
	const REGION_ROWS = 12;

	/**
	 * Grid columns used for region bucketing.
	 *
	 * @var int
	 */
	const REGION_COLUMNS = 6;

	/**
	 * Maximum heatmap cells.
	 *
	 * @var int
	 */
	const MAX_HEATMAP_CELLS = 200;

	/**
	 * Maximum bytes a screenshot may occupy.
	 *
	 * Phase 5's limit, reused so the storage bound does not differ between the
	 * validation that already exists and the visual pipeline that reuses its renderer.
	 *
	 * @var int
	 */
	public static function max_screenshot_bytes() {
		return (int) Validation_Limits::MAX_SCREENSHOT_BYTES;
	}

	/**
	 * Maximum correction iterations.
	 *
	 * §66 requires the loop to be bounded, and a test asserts this constant is
	 * respected rather than trusting the loop to be careful.
	 *
	 * @var int
	 */
	const MAX_CORRECTION_ITERATIONS = 3;

	/**
	 * Maximum renders in one job.
	 *
	 * Pages × viewports. A 25-page project at three viewports is 75 renders, which is
	 * a plan limit rather than a technical one — but it is bounded here as well,
	 * because a technical bound is the one that holds when a plan changes.
	 *
	 * @var int
	 */
	const MAX_RENDERS_PER_JOB = 75;

	/* ---------------------------------------------------------------------
	 * Storage and retention
	 * ------------------------------------------------------------------ */

	/**
	 * Screenshot retention classes §57 requires.
	 *
	 * @var array<int, string>
	 */
	const RETENTION = array( 'temporary', 'project', 'snapshot', 'deleted' );

	/**
	 * Default retention for a validation screenshot.
	 *
	 * Seven days, matching {@see Validation_Limits::RESULT_TTL}. A screenshot is
	 * evidence for a specific finding, and evidence for a finding from three weeks ago
	 * is not what a user is looking at when they open the report.
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 604800;

	/**
	 * Return whether a value is a declared relationship.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_relationship( $value ) {
		return is_string( $value ) && in_array( $value, self::RELATIONSHIPS, true );
	}

	/**
	 * Return whether a dynamic reason should be masked during comparison.
	 *
	 * @param string $reason Reason.
	 * @return bool
	 */
	public static function masks( $reason ) {
		return ! empty( self::MASK_REASONS[ (string) $reason ] );
	}

	/**
	 * Return the bounded visual categories.
	 *
	 * @return array<int, string>
	 */
	public static function categories() {
		return Validation_Limits::CATEGORIES;
	}

	/**
	 * Return the mapped relationship for a pair of boxes, if any.
	 *
	 * Centralised so the detector and the validator cannot disagree about what
	 * "overlapping" means. The caller supplies already-computed booleans because the
	 * arithmetic is the expensive part and the caller usually has it.
	 *
	 * @param bool $same_left     Same left edge.
	 * @param bool $same_right    Same right edge.
	 * @param bool $same_top      Same top edge.
	 * @param bool $same_bottom   Same bottom edge.
	 * @param bool $same_width    Same width.
	 * @param bool $same_height   Same height.
	 * @param bool $overlaps      Overlapping.
	 * @param bool $contained     Contained.
	 * @param bool $adjacent      Adjacent with no gap.
	 * @param bool $tolerance     Whether edges counted as aligned.
	 * @return array<int, string>
	 */
	public static function relationships_for( array $flags ) {
		$out = array();

		if ( ! empty( $flags['tolerance'] ) ) {
			foreach ( array( 'same_left' => 'aligned_left', 'same_right' => 'aligned_right', 'same_top' => 'same_baseline' ) as $flag => $relationship ) {
				if ( ! empty( $flags[ $flag ] ) ) {
					$out[] = $relationship;
				}
			}
		}
		if ( ! empty( $flags['same_width'] ) && ! empty( $flags['same_height'] ) && empty( $flags['overlaps'] ) ) {
			$out[] = 'centered';
		}
		if ( ! empty( $flags['overlaps'] ) ) {
			$out[] = 'overlapping';
			$out[] = 'anchored';
		}
		if ( ! empty( $flags['contained'] ) ) {
			$out[] = 'contained';
		}
		if ( ! empty( $flags['adjacent'] ) ) {
			$out[] = 'stacked';
		}

		$out = array_values( array_unique( array_filter( $out, array( __CLASS__, 'is_relationship' ) ) ) );
		return $out;
	}
}
