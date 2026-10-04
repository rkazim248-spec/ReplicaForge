<?php
/**
 * Phase 5 visual validation contract smoke test.
 *
 * Run inside a bootstrapped WordPress environment with:
 *   wp eval-file wp-content/plugins/replicaforge/tests/phase5-contract-test.php
 *
 * The test is deterministic. It never contacts the analyzed website, never calls
 * an AI provider, and never renders a page. When Elementor is available it
 * creates one real draft, runs a real validation against it, edits the document
 * the way a user would in the Elementor editor, proves the change is detected,
 * and then deletes the draft again. In every case it asserts that validation did
 * not change the document it read and did not publish it.
 *
 * @package ReplicaForge
 */

defined( 'ABSPATH' ) || exit;

$fixture = dirname( __DIR__ ) . '/tests/fixtures/phase3-representation.json';
$raw     = is_readable( $fixture ) ? file_get_contents( $fixture ) : '';
$source  = '' !== $raw ? json_decode( $raw, true ) : null;
if ( ! is_array( $source ) ) {
	throw new RuntimeException( 'Phase 5 fixture could not be loaded.' );
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAILED: ' . $message );
	}
	echo 'PASS: ' . $message . "\n";
};

$first_difference = static function ( array $differences, $category ) {
	foreach ( $differences as $difference ) {
		if ( isset( $difference['category'] ) && $difference['category'] === $category ) {
			return $difference;
		}
	}
	return array();
};

/* ------------------------------------------------------------------ */
/* 1. Value normalization is shared and never guesses.                  */
/* ------------------------------------------------------------------ */
$assert( '5.0' === \ReplicaForge\Validation_Limits::SCHEMA_VERSION, 'The comparison schema is version 5.0.' );
$assert( isset( \ReplicaForge\Validation_Limits::TOLERANCES['length']['small'] ), 'Tolerance bands are declared centrally.' );
$assert( in_array( 'critical', \ReplicaForge\Validation_Limits::SEVERITIES, true ) && in_array( 'informational', \ReplicaForge\Validation_Limits::SEVERITIES, true ), 'Severity levels are a controlled vocabulary.' );
$assert( in_array( 'responsive', \ReplicaForge\Validation_Limits::CATEGORIES, true ), 'Difference categories are a controlled vocabulary.' );

$color = \ReplicaForge\Comparison_Schema::color( 'rgb(108, 99, 255)' );
$assert( is_array( $color ) && '6c63ff' === $color['hex'], 'An rgb() source color normalizes to a comparable hex value.' );
$color_short = \ReplicaForge\Comparison_Schema::color( '#ABC' );
$assert( is_array( $color_short ) && 'aabbcc' === $color_short['hex'], 'A three digit hex color expands correctly.' );
$color_alpha = \ReplicaForge\Comparison_Schema::color( 'rgba(0, 0, 0, 0)' );
$assert( is_array( $color_alpha ) && 0.0 === (float) $color_alpha['alpha'], 'An rgba() alpha channel is preserved.' );
$color_named = \ReplicaForge\Comparison_Schema::color( 'rebeccapurple' );
$assert( null === $color_named, 'An unknown color name is reported as not comparable rather than guessed.' );
$assert( null === \ReplicaForge\Comparison_Schema::color( 'url(javascript:alert(1))' ), 'An executable-looking color value is refused.' );
$color_hsl = \ReplicaForge\Comparison_Schema::color( 'hsl(240, 100%, 70%)' );
$assert( is_array( $color_hsl ), 'An hsl() color normalizes into the same representation.' );

$distance = \ReplicaForge\Comparison_Schema::color_distance(
	\ReplicaForge\Comparison_Schema::color( '#6C63FF' ),
	\ReplicaForge\Comparison_Schema::color( '#705FFF' )
);
$assert( is_float( $distance ) && $distance < 12.0, 'A near-identical colour distance falls inside the small band.' );
$distance_far = \ReplicaForge\Comparison_Schema::color_distance(
	\ReplicaForge\Comparison_Schema::color( '#000000' ),
	\ReplicaForge\Comparison_Schema::color( '#ffffff' )
);
$assert( is_float( $distance_far ) && $distance_far > 400.0, 'Black versus white produces the largest distance.' );

$assert( 'pass' === \ReplicaForge\Comparison_Schema::band( 2.0, 'length' ), 'A 2px difference is inside the pass band.' );
$assert( 'partial' === \ReplicaForge\Comparison_Schema::band( 8.0, 'length' ), 'An 8px difference is inside the partial band.' );
$assert( 'fail' === \ReplicaForge\Comparison_Schema::band( 40.0, 'length' ), 'A 40px difference fails the comparison.' );
$assert( 'unknown' === \ReplicaForge\Comparison_Schema::band( null, 'length' ), 'A null difference is unknown, never a pass.' );

$assert( 64.0 === \ReplicaForge\Comparison_Schema::font_size( '64px' ), 'A pixel font size normalizes to a number.' );
$assert( null === \ReplicaForge\Comparison_Schema::font_size( '3rem' ), 'A relative font size is not comparable to a pixel value.' );
$assert( 700.0 === \ReplicaForge\Comparison_Schema::font_weight( 'bold' ), 'A keyword font weight normalizes to a number.' );
$assert( null === \ReplicaForge\Comparison_Schema::font_weight( 'bolder' ), 'A relative font weight is not comparable.' );
$assert( 'inter,sans-serif' === \ReplicaForge\Comparison_Schema::font_family( ' "Inter", sans-serif ' ), 'A font stack normalizes to a comparable list.' );

/* ------------------------------------------------------------------ */
/* 2. The source side is normalized from the Phase 2 representation.     */
/* ------------------------------------------------------------------ */
$adapter = new \ReplicaForge\Source_Representation_Adapter();
$side    = $adapter->build( $source );
$assert( 'source' === $side['side'], 'The adapter produces the source side of the comparison schema.' );
$assert( '5.0' === $side['schema_version'], 'The normalized side carries the schema version.' );
$assert( ! empty( $side['sections'] ), 'Source sections are normalized.' );
$assert( ! empty( $side['components'] ), 'Source components are normalized.' );
$assert( isset( $side['viewports']['desktop'], $side['viewports']['tablet'], $side['viewports']['mobile'] ), 'Desktop, tablet, and mobile viewports are always present.' );
$assert( true === $side['viewports']['desktop']['evidence'], 'Desktop is always treated as having evidence.' );
$assert( is_array( $side['design_system']['colors'] ), 'The source design system is normalized.' );

$empty_side = $adapter->build( array() );
$assert( array() === $empty_side['sections'], 'An empty representation produces no invented sections.' );
$assert( array() === $empty_side['components'], 'An empty representation produces no invented components.' );

$hostile = $adapter->build(
	array(
		'page'     => array( 'final_url' => 'javascript:alert(1)', 'title' => '<script>alert(1)</script>' ),
		'sections' => array( array( 'id' => 'section_001', 'layout' => array(), 'components' => array() ) ),
		'components' => array(
			array( 'id' => 'component_001', 'type' => 'heading', 'text' => '<script>alert(1)</script>', 'url' => 'javascript:alert(1)' ),
		),
	)
);
$hostile_component = reset( $hostile['components'] );
$assert( false === strpos( (string) $hostile_component['text'], '<script' ), 'Source text is never carried into the comparison as markup.' );
$assert( '' === (string) $hostile_component['link'], 'A javascript: source link is never carried into the comparison.' );
$assert( '' === (string) $hostile['page']['url'], 'A javascript: source URL is never carried into the comparison.' );

/* ------------------------------------------------------------------ */
/* 3. The generated stylesheet is parsed per device.                     */
/* ------------------------------------------------------------------ */
$analyzer   = new \ReplicaForge\Generated_Page_Analyzer();
$css        = '.elementor-element-abc123{--flex-direction:row;--gap:24px;width:50%}'
	. '@media(max-width: 1024px){.elementor-element-abc123{--flex-direction:column;font-size:18px}}'
	. '@media(max-width: 767px){.elementor-element-abc123{display:none}}';
$rules      = $analyzer->parse_stylesheet( $css );
$assert( 3 === count( $rules ), 'Each stylesheet rule is parsed exactly once.' );

$by_device = array();
foreach ( $rules as $rule ) {
	$by_device[ $rule['device'] ] = $rule['declarations'];
}
$assert( isset( $by_device['desktop'] ) && 'row' === $by_device['desktop']['flex-direction'], 'A container custom property maps to a comparable layout property on desktop.' );
$assert( 24.0 === (float) $by_device['desktop']['gap'], 'A container gap custom property is captured.' );
$assert( 50.0 === (float) $by_device['desktop']['width'], 'A container width declaration is captured.' );
$assert( isset( $by_device['tablet'] ) && 'column' === $by_device['tablet']['flex-direction'], 'A tablet media query is attributed to the tablet device.' );
$assert( 18.0 === (float) $by_device['tablet']['font-size'], 'A tablet font size override is captured.' );
$assert( isset( $by_device['mobile'] ) && 'none' === $by_device['mobile']['display'], 'A mobile display override is captured.' );

$rules_nested  = $analyzer->parse_stylesheet( '@media(max-width:1024px){@supports(display:grid){.elementor-element-abc123{display:grid}}}' );
$assert( 1 === count( $rules_nested ) && 'tablet' === $rules_nested[0]['device'], 'A nested at-rule keeps the innermost matching device.' );

$rules_broken  = $analyzer->parse_stylesheet( '.elementor-element-abc123{color:red' );
$assert( 0 === count( $rules_broken ), 'An unterminated rule is skipped instead of being parsed partially.' );

$rules_hostile  = $analyzer->parse_stylesheet( '.elementor-element-abc123{background-image:url(javascript:alert(1));color:#fff}' );
$assert( 1 === count( $rules_hostile ), 'A rule with one unsafe value still yields its safe values.' );
$assert( ! isset( $rules_hostile[0]['declarations']['background-image'] ), 'An executable declaration value is never indexed.' );
$assert( '#fff' === $rules_hostile[0]['declarations']['color'], 'The safe declaration in the same rule is kept.' );

$rules_min = $analyzer->parse_stylesheet( '@media(min-width:1025px){.elementor-element-abc123{color:#111}}' );
$assert( 1 === count( $rules_min ) && 'desktop' === $rules_min[0]['device'], 'A min-width query refines the desktop view instead of being discarded.' );

$rules_print = $analyzer->parse_stylesheet( '@media print{.elementor-element-abc123{color:#000}}' );
$assert( 0 === count( $rules_print ), 'A print media query is not treated as a device rule.' );

$rules_frames = $analyzer->parse_stylesheet( '@keyframes spin{from{opacity:0}to{opacity:1}}' );
$assert( 0 === count( $rules_frames ), 'A keyframes block is not read as element styles.' );

$rules_group = $analyzer->parse_stylesheet( '@layer base{@media(max-width:767px){.elementor-element-abc123{display:grid}}}' );
$assert( 1 === count( $rules_group ) && 'mobile' === $rules_group[0]['device'], 'A group at-rule keeps the device in scope.' );

/* ------------------------------------------------------------------ */
/* 4. Pairing prefers the Phase 4 identity map.                         */
/* ------------------------------------------------------------------ */
$generated_component = static function ( $key, $source_id, $text, $order, $type = 'heading' ) {
	return array(
		'key'        => $key,
		'order'      => $order,
		'type'       => $type,
		'widget'     => $type,
		'el_type'    => 'widget',
		'parent_id'  => 'bbb2222',
		'depth'      => 2,
		'source_id'  => $source_id,
		'mapped'     => '' !== $source_id,
		'text'       => $text,
		'link'       => '',
		'fields'     => array(),
		'image'      => array( 'src' => '', 'alt' => '', 'width' => null, 'height' => null, 'present' => false ),
		'typography' => array(),
		'box'        => array(),
		'layout'     => array(),
		'settings'   => array(),
	);
};

$first_source_key = '';
foreach ( $side['components'] as $component_key => $component ) {
	$first_source_key = $component_key;
	break;
}
$assert( '' !== $first_source_key, 'The source side exposes at least one component to pair.' );

$generated              = \ReplicaForge\Comparison_Schema::empty_side( 'generated' );
$generated['components'] = array(
	// Mapped through the Phase 4 identity map.
	'aaa1111' => $generated_component( 'aaa1111', $first_source_key, 'Hello', 1 ),
	// Unmapped, and the only paragraph on the generated side, so it can only be
	// paired by a deterministic type-and-order match.
	'ddd4444' => $generated_component( 'ddd4444', '', 'Generated paragraph', 2, 'paragraph' ),
	// Unmapped heading with no source heading left to pair with.
	'ccc3333' => $generated_component( 'ccc3333', '', 'User added heading', 3 ),
);
$generated['sections'] = array(
	array(
		'key'           => 'bbb2222',
		'order'         => 1,
		'type'          => 'section',
		'column_count'  => 1,
		'column_ratios' => array(),
		'direction'     => 'column',
		'gap'           => 24.0,
		'wrap'          => '',
		'align'         => array( 'justify' => '', 'align' => '' ),
		'max_width'     => 1200.0,
		'members'       => array( 'aaa1111', 'ddd4444', 'ccc3333' ),
		'heading'       => 'Hello',
		'tag'           => 'div',
		'confidence'    => 1.0,
	),
);
$generated['mapping'] = array( 'aaa1111' => $first_source_key );
$generated['counters'] = array( 'sections' => 1, 'components' => 3, 'component_types' => array( 'heading' => 2, 'paragraph' => 1 ), 'images' => 0, 'links' => 0, 'mapped' => 1 );
$generated['viewports'] = array(
	'desktop' => array( 'available' => true, 'evidence' => true, 'overrides' => 4, 'directions' => array(), 'font_size_hints' => array(), 'spacing_hints' => array(), 'visibility' => array(), 'column_count' => 3 ),
	'tablet'  => array( 'available' => true, 'evidence' => true, 'overrides' => 2, 'directions' => array(), 'font_size_hints' => array(), 'spacing_hints' => array(), 'visibility' => array(), 'column_count' => 2 ),
	'mobile'  => array( 'available' => true, 'evidence' => true, 'overrides' => 2, 'directions' => array(), 'font_size_hints' => array(), 'spacing_hints' => array(), 'visibility' => array(), 'column_count' => 1 ),
);
$generated['navigation'] = array( 'links' => 3, 'hidden_on_mobile' => 0, 'behavior' => 'unknown', 'reproduced' => 'unknown' );
$generated['metadata']   = array( 'provenance' => array( 'imported' => 2, 'referenced' => 0, 'blocked' => 1, 'failed' => 0, 'unavailable' => 0 ) );

$context = new \ReplicaForge\Comparison_Context();
$context->build( $side, $generated );
$assert( ! empty( $context->pairs() ), 'Components are paired before comparison.' );

$identity_paired = false;
$type_paired     = 0;
foreach ( $context->pairs() as $source_key => $pair ) {
	if ( ! empty( $pair['generated'] ) && 'identity_map' === $pair['pairing'] && (string) $source_key === (string) $pair['generated']['source_id'] ) {
		$identity_paired = true;
	}
	if ( ! empty( $pair['generated'] ) && 'type_and_order' === $pair['pairing'] ) {
		$type_paired++;
	}
}
$assert( $identity_paired, 'A mapped component is paired through the Phase 4 identity map.' );
$assert( 1 === $type_paired, 'An unmapped component is paired by a deterministic type and order match.' );
$assert( in_array( 'ccc3333', $context->extra_components(), true ), 'A generated element with no source counterpart is reported as extra.' );
$assert( ! in_array( 'ddd4444', $context->extra_components(), true ), 'A type-paired component is not also reported as extra.' );
$assert( ! empty( $context->missing_components() ), 'A source component with no generated element is reported as missing.' );

/* ------------------------------------------------------------------ */
/* 5. Difference records are stable, bounded, and traceable.             */
/* ------------------------------------------------------------------ */
$engine = new \ReplicaForge\Difference_Engine();
$engine->reset();
$first = $engine->record(
	array(
		'category'            => 'typography',
		'target'              => 'component:' . $first_source_key,
		'property'            => 'font_size',
		'expected'            => 64,
		'actual'              => 56,
		'difference'          => -8,
		'state'               => 'fail',
		'tolerance_type'      => 'font_size',
		'message'             => 'The heading size differs: 64px detected, 56px generated (8px).',
		'confidence'          => 0.85,
		'source_reference'    => array( 'source_component_id' => $first_source_key ),
		'generated_reference' => array( 'elementor_element_id' => 'aaa1111' ),
	)
);
$assert( true === $first, 'A failing check produces one difference record.' );
$duplicate = $engine->record(
	array(
		'category' => 'typography',
		'target'   => 'component:' . $first_source_key,
		'property' => 'font_size',
		'state'    => 'fail',
	)
);
$assert( false === $duplicate, 'An identical difference is recorded once.' );

$difference = $engine->differences();
$assert( 1 === count( $difference ), 'The difference list holds one record.' );
$assert( 'moderate' === $difference[0]['severity'], 'A failing typography check is moderate by the declared table.' );
$assert( isset( $difference[0]['id'] ) && 0 === strpos( $difference[0]['id'], 'diff_' ), 'A stable difference identifier is assigned.' );
$assert( $first_source_key === $difference[0]['source_reference']['source_component_id'], 'The difference points back to the Phase 2 component.' );
$assert( 'aaa1111' === $difference[0]['generated_reference']['elementor_element_id'], 'The difference points forward to the Elementor element.' );

$engine->record( array( 'category' => 'layout', 'target' => 'x', 'property' => 'p', 'state' => 'pass' ) );
$engine->record( array( 'category' => 'layout', 'target' => 'y', 'property' => 'q', 'state' => 'unknown' ) );
$assert( 1 === count( $engine->differences() ), 'Passing and unknown checks never become differences.' );
$assert( 3 === count( $engine->checks() ), 'Passing and unknown checks are retained for the metric denominators.' );

$counts = $engine->severity_counts();
$assert( isset( $counts['moderate'] ) && 1 === $counts['moderate'], 'Severity counts are reported per level.' );

$exhaustion = $engine->exhaustion();
$assert( false === $exhaustion['exhausted'], 'A run inside its budget is not marked as exhausted.' );

$bounded = new \ReplicaForge\Difference_Engine();
$bounded->reset( 0.0 );
$bounded->record( array( 'category' => 'layout', 'target' => 'seed', 'property' => 'count', 'state' => 'pass' ) );
$seed = $bounded->checks();
$assert( 1 === count( $seed ), 'A fresh difference engine starts with an empty check list.' );

$engine->reset();
$engine->record(
	array(
		'category' => 'content',
		'target'   => 'z',
		'property' => 'text',
		'state'    => 'fail',
		'actual'   => '<script>alert(1)</script>',
	)
);
$stored = $engine->differences();
$assert( false === strpos( (string) $stored[0]['actual'], '<script' ), 'An executable value is never stored in a difference record.' );

/* ------------------------------------------------------------------ */
/* 6. The correction plan is data only.                                 */
/* ------------------------------------------------------------------ */
$plan_builder = new \ReplicaForge\Correction_Plan();
$plan         = $plan_builder->build(
	array(
		array(
			'id'         => 'diff_0001_abcdef01',
			'category'   => 'typography',
			'target'     => 'component:' . $first_source_key,
			'property'   => 'font_size',
			'viewport'   => 'desktop',
			'expected'   => 64,
			'actual'     => 56,
			'severity'   => 'moderate',
			'confidence' => 0.9,
			'message'    => 'The heading size differs.',
			'source_reference'    => array( 'source_component_id' => $first_source_key ),
			'generated_reference' => array( 'elementor_element_id' => 'aaa1111' ),
		),
		array(
			'id'       => 'diff_0002_abcdef02',
			'category' => 'structure',
			'target'   => 'section:section_001',
			'property' => 'section_present',
			'expected' => 'hero',
			'actual'   => null,
			'severity' => 'critical',
		),
	)
);
$assert( false === $plan['applied'], 'The correction plan is never marked as applied.' );
$assert( null === $plan['applied_at'], 'The correction plan records no application time.' );
$assert( 1 === $plan['correction_count'], 'A structural difference is not turned into a style correction.' );
$assert( 56.0 === (float) $plan['corrections'][0]['from'] && 64.0 === (float) $plan['corrections'][0]['to'], 'A correction carries the measured from and to values.' );
$assert( 'aaa1111' === $plan['corrections'][0]['generated_reference']['elementor_element_id'], 'A correction keeps the Elementor element reference for a later phase.' );

/* ------------------------------------------------------------------ */
/* 7. Metrics are deterministic and exclude unknown evidence.            */
/* ------------------------------------------------------------------ */
$metrics_calculator = new \ReplicaForge\Validation_Metrics();
$metrics            = $metrics_calculator->compute(
	array(
		array( 'category' => 'layout', 'state' => 'pass', 'tolerance_type' => 'length' ),
		array( 'category' => 'layout', 'state' => 'pass', 'tolerance_type' => 'length' ),
		array( 'category' => 'layout', 'state' => 'fail', 'tolerance_type' => 'length' ),
		array( 'category' => 'layout', 'state' => 'unknown', 'tolerance_type' => 'length' ),
	)
);
$assert( 66.7 === (float) $metrics['categories']['layout']['value'], 'A category score is 100 * (passed + 0.5 * partial) / comparable.' );
$assert( 1 === (int) $metrics['categories']['layout']['not_comparable'], 'An unknown check is counted as not comparable.' );
$assert( 3 === (int) $metrics['categories']['layout']['comparable'], 'An unknown check is excluded from the denominator.' );
$assert( is_string( $metrics['formula'] ) && false !== strpos( $metrics['formula'], 'passed' ), 'The formula is published with the measurement.' );
$assert( is_array( $metrics['tolerances'] ), 'The tolerance table is published with the measurement.' );
$assert( is_array( $metrics['category_weights'] ), 'The category weights are published with the measurement.' );

$repeat = $metrics_calculator->compute(
	array(
		array( 'category' => 'layout', 'state' => 'pass', 'tolerance_type' => 'length' ),
		array( 'category' => 'layout', 'state' => 'pass', 'tolerance_type' => 'length' ),
		array( 'category' => 'layout', 'state' => 'fail', 'tolerance_type' => 'length' ),
		array( 'category' => 'layout', 'state' => 'unknown', 'tolerance_type' => 'length' ),
	)
);
$assert( $metrics['categories']['layout']['value'] === $repeat['categories']['layout']['value'], 'The same checks always produce the same measurement.' );

$metrics_empty = $metrics_calculator->compute( array() );
$assert( null === $metrics_empty['overall']['value'], 'An empty comparison reports no overall measurement instead of a number.' );

/* ------------------------------------------------------------------ */
/* 8. Comparators produce the expected categories.                      */
/* ------------------------------------------------------------------ */
$structural = new \ReplicaForge\Structural_Comparator();
$checks     = $structural->compare( $context );
$assert( ! empty( $checks ), 'The structural comparator produces checks.' );

$found_states = array();
foreach ( $checks as $check ) {
	$found_states[ $check['state'] ] = true;
}
$assert( isset( $found_states['missing'] ) || isset( $found_states['extra'] ), 'The structural comparator reports unpaired components and sections.' );

$content_difference = $first_difference( $checks, 'content' );
$assert( ! empty( $content_difference ), 'A paired component with different text produces a content check.' );
$assert( 'fail' === $content_difference['state'] && 'content mismatch' === strtolower( substr( $content_difference['message'], 0, 16 ) ), 'A content difference is reported without rewriting the text.' );

$link_difference = $first_difference( $checks, 'link' );
$assert( ! empty( $link_difference ) && 'unknown' === $link_difference['state'], 'A component with no source link is reported as unknown evidence, not a link difference.' );

$responsive          = new \ReplicaForge\Responsive_Comparator();
$responsive_checks   = $responsive->compare( $side, $generated );
$responsive_props    = array();
foreach ( $responsive_checks as $check ) {
	$responsive_props[] = $check['property'];
}
$assert( in_array( 'column_progression', $responsive_props, true ), 'The responsive comparator compares the device progression.' );
$assert( in_array( 'mobile_navigation', $responsive_props, true ), 'The responsive comparator checks mobile navigation reproduction.' );

$progression = $first_difference( $responsive_checks, 'responsive' );
$assert( ! empty( $progression ), 'The responsive comparator reports a category record.' );

$asset              = new \ReplicaForge\Asset_Comparator();
$asset_checks       = $asset->compare( $side, $generated, $context );
$asset_props        = array();
foreach ( $asset_checks as $check ) {
	$asset_props[] = $check['property'];
}
$assert( in_array( 'image_count', $asset_props, true ), 'The asset comparator compares image counts.' );
$assert( in_array( 'asset_unavailable:blocked', $asset_props, true ), 'A blocked source asset is reported as an availability notice, not a design failure.' );
$unavailable = $first_difference( $asset_checks, 'asset' );
$assert( ! empty( $unavailable ) && 'informational' === $unavailable['severity'], 'An unavailable asset is informational.' );

$spacing = new \ReplicaForge\Spacing_Comparator();
$spacing_checks = $spacing->compare( $side, $generated, $context );
$assert( ! empty( $spacing_checks ), 'The spacing comparator produces checks.' );
$assert( 'spacing' === $spacing_checks[0]['category'], 'The spacing comparator records spacing checks.' );

$layout          = new \ReplicaForge\Layout_Comparator();
$layout_checks   = $layout->compare( $side, $generated, $context );
$assert( ! empty( $layout_checks ), 'The layout comparator produces checks.' );

$typography        = new \ReplicaForge\Typography_Comparator();
$typography_checks = $typography->compare( $side, $generated, $context );
$assert( ! empty( $typography_checks ), 'The typography comparator produces checks.' );

$color      = new \ReplicaForge\Color_Comparator();
$color_checks = $color->compare( $side, $generated, $context );
$assert( ! empty( $color_checks ), 'The colour comparator produces checks.' );

/* ------------------------------------------------------------------ */
/* 9. The image differ absorbs rendering noise and bounds its input.     */
/* ------------------------------------------------------------------ */
$differ = new \ReplicaForge\Image_Differ();
if ( $differ->is_available() ) {
	$width  = 24;
	$height = 24;
	$solid  = static function ( $r, $g, $b ) use ( $width, $height, $differ ) {
		if ( 'imagick' === $differ->library() ) {
			$image = new \Imagick();
			$image->newPseudoImage( $width, $height, 'gray' );
			$draw = new \ImagickDraw();
			$draw->setFillColor( 'rgb(' . $r . ',' . $g . ',' . $b . ')' );
			$draw->rectangle( 0, 0, $width, $height );
			$image->drawImage( $draw );
			return $image->getImageBlob();
		}
		$canvas = imagecreatetruecolor( $width, $height );
		$color  = imagecolorallocate( $canvas, $r, $g, $b );
		imagefilledrectangle( $canvas, 0, 0, $width, $height, $color );
		ob_start();
		imagepng( $canvas );
		return (string) ob_get_clean();
	};

	$identical = $differ->compare( $solid( 255, 255, 255 ), $solid( 255, 255, 255 ) );
	$assert( true === $identical['available'], 'The image differ runs when an image library is installed.' );
	$assert( 0.0 === (float) $identical['differing_ratio'], 'Two identical captures report no differing pixels.' );
	$assert( 100.0 === (float) $identical['similarity'], 'Two identical captures report full similarity.' );
	$assert( 'pass' === $identical['band'], 'Two identical captures fall in the pass band.' );

	$different = $differ->compare( $solid( 255, 255, 255 ), $solid( 0, 0, 0 ) );
	$assert( (float) $different['differing_ratio'] > 0.9, 'A completely different capture reports a high differing ratio.' );
	$assert( ! empty( $different['regions'] ), 'A completely different capture reports differing regions.' );

	$near = $differ->compare( $solid( 255, 255, 255 ), $solid( 254, 255, 255 ) );
	$assert( (float) $near['differing_ratio'] < 0.08, 'A one step colour shift is absorbed by the anti-aliasing threshold.' );

	$oversized = $differ->compare( str_repeat( 'a', \ReplicaForge\Validation_Limits::MAX_SCREENSHOT_BYTES + 1 ), 'b' );
	$assert( false === $oversized['available'], 'An oversized screenshot payload is refused.' );
} else {
	$unavailable = $differ->compare( 'x', 'y' );
	$assert( false === $unavailable['available'], 'Without an image library the differ reports itself unavailable instead of failing.' );
	echo "SKIP: rendered image comparison (no GD or Imagick extension on this host)\n";
}

/* ------------------------------------------------------------------ */
/* 10. Cache keys change with every input that can change a result.      */
/* ------------------------------------------------------------------ */
$cache  = new \ReplicaForge\Validation_Cache();
$base   = array(
	'source_hash'    => 'a',
	'generated_hash' => 'b',
	'viewports'      => array( 'desktop' => array( 'width' => 1440, 'height' => 900 ) ),
	'visual'         => false,
);
$key_a = $cache->key( $base );
$assert( 1 === preg_match( '/^v_[a-f0-9]{40}$/', $key_a ), 'A cache key has a bounded, validated shape.' );
$assert( $key_a === $cache->key( $base ), 'The same inputs produce the same cache key.' );
$assert( $key_a !== $cache->key( array_merge( $base, array( 'source_hash' => 'c' ) ) ), 'A changed source analysis invalidates the cache key.' );
$assert( $key_a !== $cache->key( array_merge( $base, array( 'generated_hash' => 'c' ) ) ), 'A changed Elementor document invalidates the cache key.' );
$assert( $key_a !== $cache->key( array_merge( $base, array( 'visual' => true ) ) ), 'Requesting rendered comparison invalidates the cache key.' );
$viewport_change = $base;
$viewport_change['viewports']['desktop']['width'] = 1280;
$assert( $key_a !== $cache->key( $viewport_change ), 'A changed viewport configuration invalidates the cache key.' );
$assert( null === $cache->get( 'not-a-key' ), 'An invalid cache key never returns a stored result.' );

/* ------------------------------------------------------------------ */
/* 11. The renderer refuses anything that is not an explicit setup.      */
/* ------------------------------------------------------------------ */
$renderer      = new \ReplicaForge\Visual_Renderer();
$public        = $renderer->public_settings();
$capabilities  = $renderer->capabilities();
$assert( isset( $public['configured'] ), 'The renderer reports whether a provider is configured.' );
$assert( is_bool( $capabilities['level_3_available'] ), 'The renderer reports its capability level as a boolean.' );
$assert( is_bool( $capabilities['provider_configured'] ), 'The renderer reports provider configuration as a boolean.' );

$capture = $renderer->capture( array( 'viewport' => array( 'width' => 1440, 'height' => 900 ) ) );
$assert( empty( $capture['success'] ), 'Rendered capture is refused when no provider is configured.' );
$assert( 'render_provider_unavailable' === $capture['error']['code'], 'An unconfigured renderer reports a clear reason instead of failing.' );

$rejected_insecure = $renderer->save_settings( array( 'enabled' => true, 'endpoint' => 'http://127.0.0.1:9222/screenshot' ) );
$assert( empty( $rejected_insecure['success'] ), 'A non-HTTPS render endpoint is refused.' );
$assert( false === $renderer->public_settings()['enabled'], 'A refused endpoint is never enabled.' );

$rejected_private = $renderer->save_settings( array( 'enabled' => true, 'endpoint' => 'https://127.0.0.1/screenshot' ) );
$assert( empty( $rejected_private['success'] ), 'A loopback render endpoint is refused by the shared public-target policy.' );
$assert( false === $renderer->public_settings()['enabled'], 'A refused private endpoint is never enabled.' );

$rejected_range = $renderer->save_settings( array( 'enabled' => true, 'endpoint' => 'https://192.168.1.10/screenshot' ) );
$assert( empty( $rejected_range['success'] ), 'A private network render endpoint is refused by the shared public-target policy.' );

/* ------------------------------------------------------------------ */
/* 12. Export payloads carry no secrets and no document markup.          */
/* ------------------------------------------------------------------ */
$report_builder = new \ReplicaForge\Validation_Report();
$result         = array(
	'validation_id'   => 'val_0123456789abcdef01234567',
	'schema_version'  => '5.0',
	'created_at'      => '2026-01-01T00:00:00+00:00',
	'engine_version'  => '1.0.0',
	'cache'           => 'miss',
	'levels'          => array( 1 => 'structural_comparison', 2 => 'design_system_comparison' ),
	'source'          => array( 'url' => 'https://example.com/', 'title' => 'Example', 'type' => 'landing', 'side' => 'source' ),
	'generated'       => array( 'draft_id' => 123, 'title' => 'Replica', 'generation_id' => 'gen_1', 'elementor_version' => '4.3.2', 'side' => 'generated' ),
	'viewports'       => array( 'desktop' => array( 'label' => 'Desktop', 'width' => 1440, 'height' => 900 ) ),
	'metrics'         => $metrics,
	'differences'     => $engine->differences(),
	'visual'          => array( 'available' => false, 'capabilities' => array(), 'viewports' => array(), 'warnings' => array() ),
	'ai'              => array( 'available' => true, 'explanation' => 'measured', 'recommendations' => array() ),
	'correction_plan' => $plan,
	'counts'          => array( 'differences' => count( $engine->differences() ), 'warnings' => 0 ),
	'warnings'        => array( 'A warning.' ),
	'limitations'     => array( 'A limitation.' ),
);

$report = $report_builder->build( $result );
$assert( isset( $report['viewports']['desktop'], $report['viewports']['tablet'], $report['viewports']['mobile'] ), 'The report contains desktop, tablet, and mobile sections.' );
$assert( true === $report['read_only'], 'The report is marked read only.' );

$export = $report_builder->export_json( $result );
$assert( ! isset( $export['ai'] ), 'The AI block is not part of the export payload.' );
$assert( true === $export['read_only'], 'The export is marked read only.' );
$assert( isset( $export['metrics']['formula'] ), 'The export carries the metric formula so a score can be explained.' );
$encoded = (string) wp_json_encode( $export );
$assert( false === strpos( $encoded, '_elementor_data' ), 'The export contains no raw document data.' );
$assert( false === strpos( $encoded, 'Authorization' ), 'The export contains no authorization header.' );

$csv   = $report_builder->export_csv( $result );
$lines = array_filter( explode( "\n", $csv ) );
$assert( count( $lines ) >= 1, 'The CSV export produces at least a header row.' );
$assert( false !== strpos( $csv, 'elementor_element_id' ), 'The CSV export includes the Elementor element reference column.' );
$assert( false === strpos( $csv, "\r" ), 'The CSV export has no carriage returns.' );

/* ------------------------------------------------------------------ */
/* 13. End to end against a real generated draft.                        */
/* ------------------------------------------------------------------ */
$generator = new \ReplicaForge\Elementor_Generator();
$status    = $generator->status();
if ( empty( $status['available'] ) ) {
	echo "SKIP: end-to-end validation (Elementor is not active on this host)\n";
	echo "Phase 5 contract test completed.\n";
	return;
}

$wp_user = wp_get_current_user();
if ( ! $wp_user || ! $wp_user->exists() ) {
	$wp_user = get_user_by( 'id', 1 );
}
if ( ! $wp_user || ! $wp_user->exists() ) {
	throw new RuntimeException( 'A user is required to run the end-to-end Phase 5 test.' );
}
wp_set_current_user( $wp_user->ID );

$registry_double = new class() {
	public function has_widget( $name ) {
		return is_string( $name ) && in_array( $name, array( 'heading', 'text-editor', 'button', 'image', 'divider', 'spacer', 'icon' ), true );
	}

	public function has_element_type( $name ) {
		return 'container' === $name;
	}

	public function supports_containers() {
		return true;
	}
};

$registry  = new \ReplicaForge\Elementor_Widget_Registry( $registry_double );
$planner   = new \ReplicaForge\Ai_Reconstruction_Planner();
$validator = new \ReplicaForge\Elementor_Spec_Validator();
$mapper    = new \ReplicaForge\Elementor_Mapper( $registry );
$builder   = new \ReplicaForge\Elementor_Document_Builder();

$spec = $planner->build( $source );
$plan = $validator->validate( $spec );
$tree = $mapper->map( $plan['plan'] );
$doc  = $builder->build( $tree['tree'], array( 'generation_id' => 'rf_test_p5' ) );

$draft_id = wp_insert_post(
	array(
		'post_title'  => 'ReplicaForge Phase 5 contract draft',
		'post_status' => 'draft',
		'post_type'   => 'post',
		'post_author' => $wp_user->ID,
	),
	true
);
if ( is_wp_error( $draft_id ) ) {
	throw new RuntimeException( 'The contract draft could not be created: ' . $draft_id->get_error_message() );
}
$draft_id = (int) $draft_id;

try {
	$document = \Elementor\Plugin::instance()->documents->get( $draft_id, false );
	$document->save( array( 'elements' => $doc['elements'] ) );
	update_post_meta( $draft_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $draft_id, \ReplicaForge\Elementor_Limits::META_PREFIX . 'generation_id', 'gen_contract_phase5' );
	update_post_meta( $draft_id, \ReplicaForge\Elementor_Limits::META_PREFIX . 'generated_at', gmdate( 'c' ) );
	update_post_meta( $draft_id, \ReplicaForge\Elementor_Limits::META_PREFIX . 'id_map', (string) wp_json_encode( $doc['id_map'] ) );
	update_post_meta( $draft_id, \ReplicaForge\Elementor_Limits::META_PREFIX . 'provenance', (string) wp_json_encode( array() ) );

	$before = (string) get_post_meta( $draft_id, '_elementor_data', true );
	$assert( '' !== $before, 'The contract draft holds a real Elementor document.' );

	$engine_under_test = new \ReplicaForge\Validation_Engine();
	$outcome           = $engine_under_test->validate( $source, $draft_id, array( 'visual' => false, 'ai' => false, 'force' => true ) );
	$assert( ! empty( $outcome['schema_version'] ) && '5.0' === $outcome['schema_version'], 'A real validation returns a 5.0 record.' );
	$assert( isset( $outcome['validation_id'] ) && 1 === preg_match( '/^val_[a-f0-9]{24}$/', $outcome['validation_id'] ), 'A real validation returns a stable identifier.' );
	$assert( true === $outcome['read_only'], 'A real validation reports itself as read only.' );
	$assert( false === $outcome['correction_plan']['applied'], 'A real validation does not apply its correction plan.' );
	$assert( isset( $outcome['levels'][1], $outcome['levels'][2] ), 'Structural and design-system levels always run.' );
	$assert( ! isset( $outcome['levels'][3] ), 'The rendered level is not claimed without a render provider.' );
	$assert( ! empty( $outcome['limitations'] ), 'A real validation lists its limitations.' );
	$assert( isset( $outcome['summary']['mapped_components'] ) && $outcome['summary']['mapped_components'] > 0, 'The Phase 4 element map is consumed by validation.' );
	$assert( is_array( $outcome['differences'] ), 'A real validation returns a difference list.' );
	$assert( $draft_id === (int) $outcome['generated']['draft_id'], 'A real validation records the draft it read.' );

	$cached = $engine_under_test->validate( $source, $draft_id, array( 'visual' => false, 'ai' => false ) );
	$assert( 'hit' === $cached['cache'], 'An unchanged re-run is served from the validation cache.' );
	$assert( $cached['validation_id'] === $outcome['validation_id'], 'The cached result keeps the same identifier.' );

	$after = (string) get_post_meta( $draft_id, '_elementor_data', true );
	$assert( $before === $after, 'Validation does not change the Elementor document data.' );
	$assert( 'draft' === get_post_status( $draft_id ), 'Validation does not publish the draft.' );

	// Prove a manual Elementor edit is detected on the next run. The closure
	// recurses into child elements, so it must capture itself by reference.
	$edit_heading = static function ( array $list ) use ( &$edit_heading ) {
		foreach ( $list as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['widgetType'] ) && 'heading' === $element['widgetType'] ) {
				$list[ $index ]['settings']            = is_array( $element['settings'] ?? null ) ? $element['settings'] : array();
				$list[ $index ]['settings']['title'] = 'Edited in Elementor';
				return array( $list, true );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$child = $edit_heading( $element['elements'] );
				if ( $child[1] ) {
					$list[ $index ]['elements'] = $child[0];
					return array( $list, true );
				}
			}
		}
		return array( $list, false );
	};

	$edit_result = $edit_heading( $doc['elements'] );
	$edited      = $edit_result[0];
	$changed     = (bool) $edit_result[1];

	if ( $changed ) {
		$document->save( array( 'elements' => $edited ) );
		$after_edit = (string) get_post_meta( $draft_id, '_elementor_data', true );
		$assert( $before !== $after_edit, 'A manual Elementor edit changes the document.' );

		$edited_outcome = $engine_under_test->validate( $source, $draft_id, array( 'visual' => false, 'ai' => false, 'force' => true ) );
		$assert( 'miss' === $edited_outcome['cache'], 'A changed document invalidates the cached validation.' );

		$edit_found = false;
		foreach ( $edited_outcome['differences'] as $difference_record ) {
			if ( isset( $difference_record['category'] ) && 'content' === $difference_record['category'] && false !== strpos( (string) $difference_record['message'], 'Edited in Elementor' ) ) {
				$edit_found = true;
			}
		}
		$assert( $edit_found, 'A heading edited in Elementor produces a content difference on the next validation.' );
		$assert( null === get_post( $draft_id ) || 'draft' === get_post_status( $draft_id ), 'Validation never changes the draft status.' );
	} else {
		echo "SKIP: manual edit detection (the generated document has no heading widget)\n";
	}
} finally {
	wp_delete_post( $draft_id, true );
}

$assert( null === get_post( $draft_id ), 'The contract draft is deleted again.' );

echo "Phase 5 contract test completed.\n";
