<?php
/**
 * Phase 8: the layout relationship engine.
 *
 * Covers the value shapes real pages use: fixed and responsive grids, wrapping flex
 * rows, constrained bands, positioned elements, sticky headers, and the overlaps
 * that negative margins produce.
 *
 * Run: php phase8-layout-test.php <wp-root>
 */
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php phase8-layout-test.php <wp-root>\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['SERVER_PORT']    = '80';
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}
require_once $root . '/wp-load.php';

use ReplicaForge\Layout_Engine;

$assertions = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   What was checked.
 * @return void
 */
function check( $condition, $message ) {
	global $assertions;
	$assertions++;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	if ( ! $condition ) {
		throw new RuntimeException( 'FAILED: ' . $message );
	}
}

/**
 * Shorthand for a declarations array.
 *
 * @param array<string, mixed> $pairs Property and value pairs.
 * @return array<string, mixed>
 */
function decls( array $pairs ) {
	return $pairs;
}

$engine = new Layout_Engine( 16.0, 1440.0 );

echo "--- 1. A fixed grid ---\n";

$grid = $engine->analyze( decls( array(
	'display'               => 'grid',
	'grid-template-columns' => 'repeat(4, 1fr)',
	'gap'                   => '24px',
) ) );

check( 'grid' === $grid['mode'], 'A grid display is reported as a grid.' );
check( 4 === (int) $grid['grid']['column_count'], 'repeat(4, 1fr) resolves to four columns.' );
check( 24.0 === (float) $grid['grid']['gap']['row'], 'The row gap is read.' );
check( 24.0 === (float) $grid['grid']['gap']['column'], 'A one-value gap applies to both axes.' );
check( 4 === (int) $grid['grid']['elementor']['columns'], 'Four columns maps to an Elementor container of four.' );
check( false === (bool) $grid['grid']['elementor']['approximate'], 'A four-column grid needs no approximation.' );
check( null === $grid['grid']['elementor']['limitation'], 'A four-column grid records no limitation.' );

echo "--- 2. A responsive grid ---\n";

$auto = $engine->analyze( decls( array(
	'display'               => 'grid',
	'grid-template-columns' => 'repeat(auto-fit, minmax(280px, 1fr))',
	'gap'                   => '20px 16px',
) ) );

check( 'auto-fit' === (string) $auto['grid']['repeat_responsive'], 'A responsive repeat is recognized.' );
check( true === (bool) $auto['grid']['elementor']['approximate'], 'A responsive repeat is reported as an approximation.' );
check( false !== strpos( (string) $auto['grid']['elementor']['limitation'], 'auto-fit' ), 'The limitation names the construct it cannot reproduce.' );
check( 20.0 === (float) $auto['grid']['gap']['row'], 'The two-value gap row is read.' );
check( 16.0 === (float) $auto['grid']['gap']['column'], 'The two-value gap column is read.' );

$auto_fill = $engine->analyze( decls( array(
	'display'               => 'grid',
	'grid-template-columns' => 'repeat(auto-fill, minmax(200px, 1fr))',
) ) );
check( 'auto-fill' === (string) $auto_fill['grid']['repeat_responsive'], 'auto-fill is distinguished from auto-fit.' );

echo "--- 3. minmax and fractional tracks ---\n";

$tracks = $engine->analyze( decls( array(
	'display'               => 'grid',
	'grid-template-columns' => 'minmax(0, 2fr) minmax(0, 1fr)',
) ) );

check( 2 === count( $tracks['grid']['columns'] ), 'Two minmax tracks are resolved.' );
check( 'minmax' === (string) $tracks['grid']['columns'][0]['type'], 'A minmax track is typed as minmax.' );
check( '2fr' === (string) $tracks['grid']['columns'][0]['max'], 'The minmax maximum keeps its fraction.' );
check( 2 === (int) $tracks['grid']['column_count'], 'Two tracks means two columns.' );

$mixed = $engine->analyze( decls( array(
	'display'               => 'grid',
	'grid-template-columns' => '200px 1fr auto',
) ) );
check( 'length' === (string) $mixed['grid']['columns'][0]['type'], 'A pixel track is a length.' );
check( 200.0 === (float) $mixed['grid']['columns'][0]['value'], 'The pixel track keeps its value.' );
check( 'fr' === (string) $mixed['grid']['columns'][1]['type'], 'A fractional track is typed fr.' );
check( 'auto' === (string) $mixed['grid']['columns'][2]['type'], 'An auto track is typed auto.' );

echo "--- 4. A dense grid is clamped, not silently truncated ---\n";

$dense = $engine->analyze( decls( array(
	'display'               => 'grid',
	'grid-template-columns' => 'repeat(8, 1fr)',
) ) );
check( 8 === (int) $dense['grid']['column_count'], 'The observed column count is reported honestly.' );
check( 6 === (int) $dense['grid']['elementor']['columns'], 'The Elementor container is clamped to its supported maximum.' );
check( true === (bool) $dense['grid']['elementor']['approximate'], 'A clamp is reported as an approximation.' );
check( false !== strpos( (string) $dense['grid']['elementor']['limitation'], '8' ), 'The limitation states the column count that was lost.' );

echo "--- 5. Flexbox ---\n";

$flex = $engine->analyze( decls( array(
	'display'         => 'flex',
	'flex-direction'  => 'row',
	'gap'             => '16px',
	'align-items'     => 'center',
	'justify-content' => 'space-between',
) ) );

check( 'flex' === $flex['mode'], 'A flex display is reported as flex.' );
check( 'row' === (string) $flex['flex']['direction'], 'The direction is read.' );
check( 16.0 === (float) $flex['flex']['gap']['row'], 'The flex gap is read.' );
check( 'center' === (string) $flex['flex']['elementor']['align']['value'], 'align-items center maps to an Elementor center alignment.' );
check( 'vertical' === (string) $flex['flex']['elementor']['align']['axis'], 'A flex row aligns on the vertical axis.' );
check( 'declaration' === (string) $flex['flex']['elementor']['align']['source'], 'The alignment is reported as declared, not defaulted.' );
check( 'space-between' === (string) $flex['flex']['elementor']['justify']['value'], 'space-between maps through.' );
check( null === $flex['flex']['elementor']['limitation'], 'An ordinary flex row records no limitation.' );

$column_flex = $engine->analyze( decls( array(
	'display'        => 'flex',
	'flex-direction' => 'column',
	'align-items'    => 'center',
) ) );
check( 'vertical' === (string) $column_flex['flex']['elementor']['content_direction'], 'A column flex is reported as vertical content.' );
check( 'horizontal' === (string) $column_flex['flex']['elementor']['align']['axis'], 'A flex column aligns on the horizontal axis.' );
check( false !== strpos( (string) $column_flex['flex']['elementor']['align']['limitation'], 'horizontal' ), 'The axis translation is recorded as a limitation.' );

echo "--- 6. Wrapping flex is a limitation, not a silent change ---\n";

$wrap = $engine->analyze( decls( array(
	'display'        => 'flex',
	'flex-wrap'      => 'wrap',
	'flex-direction' => 'row',
) ) );
check( 'wrap' === (string) $wrap['flex']['wrap'], 'flex-wrap is read.' );
check( false === (bool) $wrap['flex']['elementor']['wrap_supported'], 'Elementor wrap is reported as unsupported.' );
check( false !== strpos( (string) $wrap['flex']['elementor']['limitation'], 'wrap' ), 'The limitation names wrapping.' );

echo "--- 7. A defaulted alignment is labelled as such ---\n";

$no_align = $engine->analyze( decls( array( 'display' => 'flex', 'flex-direction' => 'row' ) ) );
check( 'default' === (string) $no_align['flex']['elementor']['align']['source'], 'An absent align-items is reported as a default, not an observation.' );
check( false !== strpos( (string) $no_align['flex']['elementor']['align']['limitation'], 'stretch' ), 'The CSS default is named so a reader knows what was assumed.' );

echo "--- 8. Constrained content bands ---\n";

$boxed = $engine->analyze( decls( array(
	'width'         => '100%',
	'max-width'     => '1200px',
	'margin-left'   => 'auto',
	'margin-right'  => 'auto',
) ) );
check( 'boxed' === (string) $boxed['box']['kind'], 'A max-width makes the band boxed.' );
check( 1200.0 === (float) $boxed['box']['max_width'], 'The maximum width is read.' );
check( true === (bool) $boxed['box']['centered'], 'Auto margins on both sides mark it centered.' );
check( 'boxed' === (string) $boxed['box']['elementor']['model'], 'A band maps to a boxed Elementor container.' );
check( 83 === (int) $boxed['box']['elementor']['percent'], 'A pixel band becomes the nearest percentage of the viewport.' );
check( true === (bool) $boxed['box']['elementor']['approximate'], 'A pixel band reported as a percentage is an approximation.' );
check( false !== strpos( (string) $boxed['box']['elementor']['limitation'], 'max-width' ), 'The limitation explains the unit change.' );

$full = $engine->analyze( decls( array( 'width' => '100%' ) ) );
check( 'full_width' === (string) $full['box']['kind'], 'A bare 100% width is full width.' );
check( true === (bool) $full['box']['full_width'], 'Full width is flagged.' );
check( 'full_width' === (string) $full['box']['elementor']['model'], 'Full width maps to a full-width container.' );
check( null === $full['box']['width'], 'A percentage width is not converted to a fabricated pixel value.' );
check( 1.0 === (float) $full['box']['width_ratio'], 'A percentage width is read as a ratio.' );

$viewport_full = $engine->analyze( decls( array( 'width' => '100vw' ) ) );
check( true === (bool) $viewport_full['box']['full_width'], 'A viewport width is also full width.' );
check( 1.0 === (float) $viewport_full['box']['width_viewport'], 'A viewport width is read as a viewport ratio, and kept as the reason it is full width.' );

$rem_boxed = $engine->analyze( decls( array( 'width' => '100%', 'max-width' => '72rem' ) ) );
check( 'boxed' === (string) $rem_boxed['box']['kind'], 'A rem maximum still constrains the band.' );
check( 1152.0 === (float) $rem_boxed['box']['max_width'], 'A rem maximum converts against the root font size.' );

$percent_boxed = $engine->analyze( decls( array( 'max-width' => '80%' ) ) );
check( 'boxed' === (string) $percent_boxed['box']['kind'], 'A percentage maximum constrains the band.' );
check( 80 === (int) $percent_boxed['box']['elementor']['percent'], 'A percentage band is expressed in the unit Elementor already uses.' );
check( false === (bool) $percent_boxed['box']['elementor']['approximate'], 'A percentage band is an exact translation, not an approximation.' );

echo "--- 9. Positioning, anchoring, and sticky ---\n";

$absolute = $engine->analyze( decls( array(
	'position' => 'absolute',
	'top'      => '50%',
	'left'     => '10px',
	'z-index'  => '5',
) ) );
check( 'absolute' === (string) $absolute['placement']['position'], 'Absolute positioning is read.' );
check( true === (bool) $absolute['placement']['out_of_flow'], 'An absolute element is out of flow.' );
check( 5 === (int) $absolute['placement']['z_index'], 'The z-index is read.' );
// 50% is not a pixel length, so it must not become one.
check( null === $absolute['placement']['inset']['top'], 'A percentage inset is not converted to a fabricated pixel value.' );
check( 10.0 === (float) $absolute['placement']['inset']['left'], 'A pixel inset converts.' );

$sticky = $engine->analyze( decls( array( 'position' => 'sticky', 'top' => '0' ) ) );
check( 'sticky' === (string) $sticky['placement']['position'], 'Sticky positioning is read.' );
check( true === (bool) $sticky['placement']['in_flow'], 'A sticky element is in flow, unlike an absolute one.' );
check( null !== $sticky['placement']['sticky'], 'A sticky element carries a sticky block.' );
check( 0.0 === (float) $sticky['placement']['sticky']['top'], 'A zero offset is preserved as zero, not treated as absent.' );

echo "--- 10. Negative margins are the overlap evidence ---\n";

$pull_up = $engine->analyze( decls( array( 'margin-top' => '-80px', 'position' => 'relative' ) ) );
check( -80.0 === (float) $pull_up['placement']['margin']['top'], 'A negative margin keeps its sign.' );
check( null === $pull_up['placement']['margin']['bottom'], 'An absent margin stays null rather than becoming zero.' );

$shorthand = $engine->analyze( decls( array( 'margin' => '-40px 0 0' ) ) );
check( -40.0 === (float) $shorthand['placement']['margin']['top'], 'A three-value margin shorthand assigns the first to the top.' );
check( 0.0 === (float) $shorthand['placement']['margin']['left'], 'A three-value shorthand assigns the second to both sides.' );
check( 0.0 === (float) $shorthand['placement']['margin']['bottom'], 'A three-value shorthand assigns the third to the bottom.' );

echo "--- 11. Aspect ratio and gap properties ---\n";

$ratio = $engine->analyze( decls( array( 'aspect-ratio' => '16 / 9' ) ) );
check( 16.0 === (float) $ratio['aspect']['width'], 'An aspect ratio width is read.' );
check( 9.0 === (float) $ratio['aspect']['height'], 'An aspect ratio height is read.' );
check( null === $engine->analyze( decls( array( 'aspect-ratio' => 'auto' ) ) )['aspect'], 'auto is not an aspect ratio.' );
check( null === $engine->analyze( decls( array( 'aspect-ratio' => 'nonsense' ) ) )['aspect'], 'A nonsense ratio is refused.' );

$grid_gap = $engine->analyze( decls( array( 'display' => 'grid', 'grid-row-gap' => '10px', 'grid-column-gap' => '20px' ) ) );
check( 10.0 === (float) $grid_gap['grid']['gap']['row'], 'The legacy prefixed longhand row gap is read.' );
check( 20.0 === (float) $grid_gap['grid']['gap']['column'], 'The legacy prefixed longhand column gap is read.' );

$bare_longhand = $engine->analyze( decls( array( 'display' => 'grid', 'row-gap' => '8px', 'column-gap' => '12px' ) ) );
check( 8.0 === (float) $bare_longhand['grid']['gap']['row'], 'The unprefixed longhand row gap is read.' );
check( 12.0 === (float) $bare_longhand['grid']['gap']['column'], 'The unprefixed longhand column gap is read.' );

$legacy_shorthand = $engine->analyze( decls( array( 'display' => 'grid', 'grid-gap' => '6px' ) ) );
check( 6.0 === (float) $legacy_shorthand['grid']['gap']['row'], 'The legacy prefixed shorthand is read as a fallback.' );

$no_gap = $engine->analyze( decls( array( 'display' => 'grid', 'grid-template-columns' => 'repeat(2, 1fr)' ) ) );
check( false === (bool) $no_gap['grid']['gap']['present'], 'A grid with no gap reports it absent rather than zero.' );
check( null === $no_gap['grid']['gap']['row'], 'An absent gap is null rather than a fabricated zero.' );
check( true === (bool) $grid['grid']['gap']['present'], 'A grid that declares a gap reports it present.' );
check( true === (bool) $grid_gap['grid']['gap']['present'], 'A grid with a longhand gap reports it present.' );

$flex_gap = $engine->analyze( decls( array( 'display' => 'flex', 'gap' => '16px' ) ) );
check( 16.0 === (float) $flex_gap['flex']['gap']['row'], 'The bare gap shorthand is read for a flex container.' );
check( 16.0 === (float) $flex_gap['flex']['gap']['column'], 'A one-value flex gap applies to both axes.' );

echo "--- 12. Child participation ---\n";

$span = $engine->participation( decls( array( 'grid-column' => '1 / 3' ) ) );
check( 2 === (int) $span['span']['column'], 'A grid-column range resolves to a span of two.' );
check( 'span' === (string) $span['elementor']['width_mode'], 'A span maps to a spanning child width.' );
check( false !== strpos( (string) $span['elementor']['limitation'], 'reflow' ), 'The span limitation records that the replica will not reflow.' );

$grow = $engine->participation( decls( array( 'flex-grow' => '1', 'flex-basis' => '0' ) ) );
check( 1.0 === (float) $grow['grow'], 'flex-grow is read.' );
check( 'grow' === (string) $grow['elementor']['width_mode'], 'A growing child maps to a grow width mode.' );
check( false !== strpos( (string) $grow['elementor']['limitation'], 'flex basis' ), 'A grow with a basis records that the distribution is not at layout time.' );

$span_keyword = $engine->participation( decls( array( 'grid-column' => 'span 3' ) ) );
check( 3 === (int) $span_keyword['span']['column'], 'The span keyword is read.' );

$span_longhand = $engine->participation( decls( array( 'grid-column-span' => '2' ) ) );
check( 2 === (int) $span_longhand['span']['column'], 'The longhand span is read.' );

$row_span = $engine->participation( decls( array( 'grid-row' => '1 / 4' ) ) );
check( 3 === (int) $row_span['span']['row'], 'A row placement range resolves to a row span.' );

$shrink = $engine->participation( decls( array( 'flex-shrink' => '0' ) ) );
check( 0.0 === (float) $shrink['shrink'], 'flex-shrink is read, and a zero shrink is not treated as absent.' );

$plain = $engine->participation( decls( array() ) );
check( 'auto' === (string) $plain['elementor']['width_mode'], 'A child with no layout participation is auto.' );

echo "--- 13. Named areas ---\n";

$areas = $engine->analyze( decls( array(
	'display'               => 'grid',
	'grid-template-areas'   => '"header header" "sidebar main"',
) ) );
check( is_array( $areas['grid']['areas'] ), 'Named areas are parsed into rows.' );
check( 2 === count( $areas['grid']['areas'] ), 'Two area rows are read.' );
check( array( 'header', 'header' ) === $areas['grid']['areas'][0], 'The first area row is read correctly.' );

echo "--- 14. Relationships ---\n";

$nodes = array(
	array(
		'id'       => 'hero',
		'parent'   => '',
		'children' => array( 'hero_text', 'hero_media', 'hero_cta' ),
		'layout'   => $engine->analyze( decls( array( 'display' => 'grid', 'grid-template-columns' => '1fr 1fr' ) ) ),
	),
	array(
		'id'       => 'hero_text',
		'parent'   => 'hero',
		'children' => array(),
		'layout'   => $engine->analyze( decls( array( 'display' => 'block' ) ) ),
	),
	array(
		'id'       => 'hero_media',
		'parent'   => 'hero',
		'children' => array( 'inner_badge' ),
		'layout'   => $engine->analyze( decls( array( 'position' => 'relative' ) ) ),
	),
	array(
		'id'       => 'inner_badge',
		'parent'   => 'hero_media',
		'children' => array(),
		'layout'   => $engine->analyze( decls( array( 'position' => 'absolute', 'z-index' => '4' ) ) ),
	),
	array(
		'id'       => 'hero_cta',
		'parent'   => 'hero',
		'children' => array(),
		'layout'   => $engine->analyze( decls( array( 'margin-left' => 'auto', 'margin-right' => 'auto', 'max-width' => '600px' ) ) ),
	),
	array(
		'id'       => 'badge',
		'parent'   => 'hero',
		'children' => array(),
		'layout'   => $engine->analyze( decls( array( 'position' => 'absolute', 'z-index' => '10' ) ) ),
	),
);

$relations = $engine->relate( $nodes );
$kinds = array();
foreach ( $relations as $relation ) {
	$kinds[] = $relation['relationship'];
}

check( in_array( 'contains', $kinds, true ), 'A parent reports a contains relationship.' );
check( in_array( 'stacked_with', $kinds, true ), 'Siblings report a stacked_with relationship.' );
check( in_array( 'aligned_with', $kinds, true ), 'Siblings report an aligned_with relationship.' );
check( in_array( 'overlaps', $kinds, true ), 'An absolutely positioned child reports an overlap.' );
check( in_array( 'anchored_to', $kinds, true ), 'An absolutely positioned child reports what it is anchored to.' );
check( in_array( 'centered_in', $kinds, true ), 'A centered child reports what it is centered in.' );

// The centered child must name its parent, and a centered band must also report
// the constraint that makes centering meaningful.
$centered = null;
foreach ( $relations as $relation ) {
	if ( 'centered_in' === $relation['relationship'] ) {
		$centered = $relation;
	}
}
check( null !== $centered && 'hero_cta' === (string) $centered['source'], 'The centered element is the source of the relationship.' );
check( null !== $centered && 'hero' === (string) $centered['target'], 'It reports its parent as what it is centered in.' );
check( null !== $centered && false !== strpos( implode( ' ', $centered['evidence'] ), 'auto_margins' ), 'The evidence names the auto margins it was read from.' );

// Every relationship carries a confidence and some evidence, or it is a claim
// rather than an observation.
foreach ( $relations as $relation ) {
	check(
		isset( $relation['confidence'] ) && $relation['confidence'] > 0 && $relation['confidence'] <= 1.0,
		'Relationship ' . $relation['relationship'] . ' carries a confidence in range.'
	);
	check( ! empty( $relation['evidence'] ), 'Relationship ' . $relation['relationship'] . ' carries evidence.' );
}

// A badge inside a positioned column anchors to that column, because that is the
// containing block a browser would use.
$anchor = null;
foreach ( $relations as $relation ) {
	if ( 'anchored_to' === $relation['relationship'] && 'inner_badge' === $relation['source'] ) {
		$anchor = $relation;
	}
}
check( null !== $anchor, 'The inner badge has an anchoring relationship.' );
check( 'hero_media' === (string) $anchor['target'], 'The inner badge anchors to its nearest positioned ancestor.' );
check( 0.95 === (float) $anchor['confidence'], 'Anchoring to a declared positioned ancestor is high confidence.' );
check( null === $anchor['limitation'], 'A confidently anchored element records no limitation.' );

// A badge whose ancestors are all unpositioned has no positioned ancestor, so its
// containing block is the page. That has to be said rather than presented as a
// confident structural anchor.
$orphan_anchor = null;
foreach ( $relations as $relation ) {
	if ( 'anchored_to' === $relation['relationship'] && 'badge' === $relation['source'] ) {
		$orphan_anchor = $relation;
	}
}
check( null !== $orphan_anchor, 'The badge with no positioned ancestor still reports what it falls back to.' );
check( 'hero' === (string) $orphan_anchor['target'], 'It falls back to its structural parent.' );
check( 0.6 === (float) $orphan_anchor['confidence'], 'A fallback anchor is a weaker claim than a declared one.' );
check( false !== strpos( implode( ' ', $orphan_anchor['evidence'] ), 'no_positioned_ancestor' ), 'The evidence records that no positioned ancestor was found.' );
check( false !== strpos( (string) $orphan_anchor['limitation'], 'containing block' ), 'The limitation explains that the source containing block is the page.' );

// A grid sibling is confidently aligned; a block sibling is not.
$grid_align = null;
$block_align = null;
foreach ( $relations as $relation ) {
	if ( 'aligned_with' !== $relation['relationship'] || 'hero_text' !== $relation['source'] ) {
		continue;
	}
	if ( 'grid' === $nodes[0]['layout']['mode'] ) {
		$grid_align = (float) $relation['confidence'];
	}
}
check( null !== $grid_align && $grid_align >= 0.85, 'Alignment inside a grid is a confident claim.' );

$block_nodes = array(
	array( 'id' => 'parent', 'parent' => '', 'children' => array( 'a', 'b' ), 'layout' => $engine->analyze( decls( array( 'display' => 'block' ) ) ) ),
	array( 'id' => 'a', 'parent' => 'parent', 'children' => array(), 'layout' => $engine->analyze( decls( array( 'display' => 'block' ) ) ) ),
	array( 'id' => 'b', 'parent' => 'parent', 'children' => array(), 'layout' => $engine->analyze( decls( array( 'display' => 'block' ) ) ) ),
);
$block_conf = null;
foreach ( $engine->relate( $block_nodes ) as $relation ) {
	if ( 'aligned_with' === $relation['relationship'] ) {
		$block_conf = (float) $relation['confidence'];
	}
}
check( null !== $block_conf && $block_conf < 0.6, 'Alignment inside a plain block is a weak claim, because it is a coincidence of widths.' );

echo "--- 15. An unbounded page cannot produce unbounded relationships ---\n";

$many = array();
for ( $index = 0; $index < 300; $index++ ) {
	$many[] = array(
		'id'       => 'n' . $index,
		'parent'   => 0 === $index ? '' : 'n' . ( $index - 1 ),
		'children' => ( $index + 1 < 300 ) ? array( 'n' . ( $index + 1 ) ) : array(),
		'layout'   => $engine->analyze( decls( array( 'position' => 'absolute' ) ) ),
	);
}
$many_relations = $engine->relate( $many );
$cap = Layout_Engine::MAX_RELATIONS_PER_NODE * count( $many );
check( count( $many_relations ) <= $cap, 'The relationship list is bounded on a large page.' );

echo "--- 16. An undeclared display is not invented ---\n";

$inherited = $engine->analyze( decls( array( 'color' => 'red' ) ) );
check( 'unknown' === (string) $inherited['mode'], 'A node with no display declaration is reported as unknown.' );
check( false === (bool) $inherited['supported'], 'A node with no layout evidence is not marked supported.' );

$block = $engine->analyze( decls( array( 'display' => 'block' ) ) );
check( true === (bool) $block['supported'], 'A node with a display declaration is marked supported.' );

echo "\nLayout engine test passed. Assertions: {$assertions}\n";
