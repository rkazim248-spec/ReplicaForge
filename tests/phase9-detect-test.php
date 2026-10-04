<?php
/**
 * Phase 9: change detection, classification, and impact.
 *
 * The central claim under test is §2's: the system must understand *what* changed,
 * not merely that bytes differ. So the cases that matter are the ones where naive
 * comparison is wrong — an insertion that renumbers everything below it, a
 * cache-busted image, a reformatted string, a dynamic value — and each of those is
 * asserted to produce the honest answer.
 *
 * Run: php phase9-detect-test.php <wp-root>
 */
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php phase9-detect-test.php <wp-root>\n" );
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

use ReplicaForge\Change_Detector;
use ReplicaForge\Change_Classifier;
use ReplicaForge\Sync_Limits;

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
 * Build a one-section page.
 *
 * @param array<int, array<string, mixed>> $components Components in the hero.
 * @param string                           $section_id Section identifier.
 * @return array<string, mixed>
 */
function page( array $components, $section_id = 'section_hero' ) {
	return array(
		'sections' => array(
			array(
				'id'       => $section_id,
				'role'     => 'hero',
				'elements' => $components,
			),
		),
	);
}

/**
 * Return the ids of changes of a type.
 *
 * @param array<int, array<string, mixed>> $changes Changes.
 * @param string                           $type    Change type.
 * @return array<int, string>
 */
function ids_of_type( array $changes, $type ) {
	$out = array();
	foreach ( $changes as $change ) {
		if ( $type === (string) $change['type'] ) {
			$out[] = (string) $change['source_component_id'];
		}
	}
	return $out;
}

$detector   = new Change_Detector();
$classifier = new Change_Classifier();

echo "--- 1. An identical page reports no changes ---\n";

$base_components = array(
	array( 'id' => 'hero_heading', 'role' => 'h1', 'text' => 'Build faster with confidence', 'font_size' => 60, 'color' => '#111111' ),
	array( 'id' => 'hero_paragraph', 'role' => 'paragraph', 'text' => 'Ship on Elementor.', 'font_size' => 18, 'color' => '#333333' ),
	array( 'id' => 'hero_button', 'role' => 'button', 'text' => 'Get started', 'font_size' => 16 ),
);

$identical = $detector->compare( page( $base_components ), page( $base_components ) );
check( 0 === $identical['count'], 'The same page compared with itself reports no changes.' );
check( true === (bool) $identical['identical'], 'And says so explicitly, so a caller does not have to count an empty list to find that out.' );
check( false === (bool) $identical['truncated'], 'And the comparison was not truncated.' );

echo "--- 2. A text change is detected as content ---\n";

$edited = $base_components;
$edited[1]['text'] = 'Ship on Elementor without touching the DOM.';

$text_change = $detector->compare( page( $base_components ), page( $edited ) );
check( 1 === $text_change['count'], 'A changed paragraph text produces exactly one change.' );

$only = $text_change['changes'][0];
check( 'modified' === (string) $only['type'], 'It is a modification, not a removal and an addition.' );
check( 'hero_paragraph' === (string) $only['source_component_id'], 'It names the component that changed.' );
check( 'content' === (string) $only['category'], 'Its category is content.' );
check( 'text' === (string) $only['field'], 'It names the field that changed.' );
check( 'Ship on Elementor.' === (string) $only['detail']['old_value'], 'It carries the old value.' );
check( 0 === (int) $text_change['by_type']['added'], 'Nothing is reported as added.' );
check( 0 === (int) $text_change['by_type']['removed'], 'And nothing as removed, which is the point: a text edit is not a rebuild.' );

echo "--- 3. Reformatting is not a change ---\n";

// A source that re-wraps its own text should not report a content change on every
// comparison, or a user will stop reading the report.
$reformatted = $base_components;
$reformatted[1]['text'] = "Ship on   Elementor.\n";

$reformat = $detector->compare( page( $base_components ), page( $reformatted ) );
check( 0 === $reformat['count'], 'Collapsed internal whitespace and a trailing newline are not a content change.' );

$truly_different = $base_components;
$truly_different[1]['text'] = 'Ship elsewhere entirely';
$real = $detector->compare( page( $base_components ), page( $truly_different ) );
check( 1 === $real['count'], 'But a genuinely different string still is a change.' );

echo "--- 4. A cache-busted image is not a change ---\n";

// A deploy that appends a version query to an asset is the single most common
// false positive a naive detector produces.
$with_image = array_merge(
	$base_components,
	array( array( 'id' => 'hero_image', 'role' => 'image', 'image' => 'https://cdn.example.com/hero.jpg' ) )
);
$recached    = $with_image;
$recached[3]['image'] = 'https://cdn.example.com/hero.jpg?v=8f2a91';

$cache = $detector->compare( page( $with_image ), page( $recached ) );
check( 0 === $cache['count'], 'A cache-busting query string on an image is not an image change.' );

$real_image    = $with_image;
$real_image[3]['image'] = 'https://cdn.example.com/hero-v2.jpg';
$changed_image = $detector->compare( page( $with_image ), page( $real_image ) );
check( 1 === $changed_image['count'], 'A genuinely different image is one change, not a removal and an addition, because the component is still the same component.' );
check( 'image' === (string) $changed_image['changes'][0]['category'], 'And its category is image.' );
check( 'modified' === (string) $changed_image['changes'][0]['type'], 'Reported as a modification.' );

// The uncertainty is carried, not hidden. An untitled image with a different
// file is probably the same component, but not certainly, so the match is
// reported as low confidence and the classifier requires a person to look.
$uncertain = $classifier->classify( $changed_image['changes'][0] );
check( $changed_image['changes'][0]['confidence'] < 0.75, 'A replaced image with no alt text is matched at low confidence, because nothing but the file distinguishes it.' );
check( true === (bool) $uncertain['review'], 'And the classifier requires a person to look at it.' );
check( false === (bool) $uncertain['auto'], 'And it is never applied automatically.' );

// With alt text present, the text is the better evidence and the match is
// certain enough for the ordinary path.
$titled = page( array( array( 'id' => 'hero_image', 'role' => 'image', 'image' => 'https://cdn.example.com/hero.jpg', 'text' => 'The team at work' ) ) );
$titled2 = page( array( array( 'id' => 'hero_image', 'role' => 'image', 'image' => 'https://cdn.example.com/hero-v2.jpg', 'text' => 'The team at work' ) ) );
$titled_change = $detector->compare( $titled, $titled2 );
check( 1 === $titled_change['count'], 'A replaced image whose alt text is unchanged is one change.' );
check( $titled_change['changes'][0]['confidence'] >= 0.75, 'And is matched with confidence, because the alt text confirms it is the same component.' );

echo "--- 5. An insertion does not renumber ---\n";

// §12's scenario, end to end. A badge appears above the heading in the hero.
$with_badge = array_merge(
	array( array( 'id' => 'hero_badge', 'role' => 'badge', 'text' => 'New' ) ),
	$with_image
);

$insert = $detector->compare( page( $with_image ), page( $with_badge ) );
check( 1 === $insert['count'], 'Inserting a component produces exactly one change.' );
check( array( 'hero_badge' ) === ids_of_type( $insert['changes'], 'added' ), 'The new component is reported as added.' );
check( array() === ids_of_type( $insert['changes'], 'removed' ), 'Nothing is reported as removed, even though every index below the insertion shifted.' );
check( 0 === (int) $insert['by_type']['modified'], 'And nothing as modified, so the three untouched components are not re-reported.' );

echo "--- 6. A removal is detected, and is never silent ---\n";

// Only the button is dropped. Removing a run of components would produce one
// change per component, which is correct but is not what is under test here.
$kept = $with_image;
array_splice( $kept, 2, 1 );
$removed = $detector->compare( page( $with_image ), page( $kept ) );
check( 1 === $removed['count'], 'Removing a component produces one change.' );
check( array( 'hero_button' ) === ids_of_type( $removed['changes'], 'removed' ), 'The missing component is identified.' );
check( 'component' === (string) $removed['changes'][0]['category'], 'Its category is component.' );
check( ! empty( $removed['changes'][0]['evidence'] ), 'It carries evidence for why it is reported as removed.' );

echo "--- 7. A section move is distinguished from an edit ---\n";

$page_a = array(
	'sections' => array(
		array( 'id' => 'section_hero', 'role' => 'hero', 'elements' => array( array( 'id' => 'cta', 'role' => 'button', 'text' => 'Talk to sales' ) ) ),
		array( 'id' => 'section_about', 'role' => 'about', 'elements' => array( array( 'id' => 'about_text', 'role' => 'paragraph', 'text' => 'Who we are' ) ) ),
	),
);
$page_b = array(
	'sections' => array(
		array( 'id' => 'section_about', 'role' => 'about', 'elements' => array( array( 'id' => 'about_text', 'role' => 'paragraph', 'text' => 'Who we are' ) ) ),
		array( 'id' => 'section_hero', 'role' => 'hero', 'elements' => array( array( 'id' => 'cta', 'role' => 'button', 'text' => 'Talk to sales' ) ) ),
	),
);

$reorder = $detector->compare( $page_a, $page_b );
check( 2 === $reorder['count'], 'Reordering two sections produces two changes, one per section.' );
check( 2 === (int) $reorder['by_type']['moved'], 'Both are reported as moved rather than as a removal and an addition.' );
check( 0 === (int) $reorder['by_type']['removed'], 'So a reorder does not look like the page was rebuilt.' );
check( 0 === (int) $reorder['by_type']['added'], 'And does not look like two new sections appeared.' );

$moved_sections = array();
foreach ( $reorder['changes'] as $change ) {
	if ( in_array( (string) $change['source_component_id'], array( 'section_hero', 'section_about' ), true ) ) {
		$moved_sections[] = $change;
	}
}
check( 2 === count( $moved_sections ), 'Both section moves are reported against the section identifier, not against a component inside it.' );
check( 'section' === (string) $moved_sections[0]['category'], 'And the category is section, so a structural change is distinguishable from a content one.' );
$moved_section = $moved_sections[0];
check( isset( $moved_section['detail']['old_position'] ), 'It carries the old position.' );
check( isset( $moved_section['detail']['new_position'] ), 'And the new one.' );

echo "--- 8. A section addition and removal ---\n";

$grown = $page_a;
$grown['sections'][] = array( 'id' => 'section_pricing', 'role' => 'pricing', 'elements' => array( array( 'id' => 'price', 'role' => 'price', 'text' => 'From 9 a month' ) ) );

$growth = $detector->compare( $page_a, $grown );
// Two changes, not one: the section is new, and the component inside it is new.
// Both are true and neither is redundant — a reader needs to know a new band
// appeared and that it holds something.
check( 2 === $growth['count'], 'Adding a section produces two changes: the section is new, and the component inside it is new.' );
check( 2 === (int) $growth['by_type']['added'], 'Both are additions.' );
check( 0 === (int) $growth['by_type']['removed'], 'Nothing looks removed.' );
check( 0 === (int) $growth['by_type']['modified'], 'And the two existing sections are untouched, so their components are not re-reported.' );

$growth_kinds = array();
foreach ( $growth['changes'] as $change ) {
	$growth_kinds[] = $change['type'] . ':' . $change['category'] . ':' . $change['source_component_id'];
}
sort( $growth_kinds );
check(
	array( 'added:component:price', 'added:section:section_pricing' ) === $growth_kinds,
	'The two changes are distinguishable: a new band, and a new price inside it. Collapsing them would hide which is which.'
);

$shrunk = $grown;
array_pop( $shrunk['sections'] );
$shrink = $detector->compare( $grown, $shrunk );
check( 2 === $shrink['count'], 'Removing a section is the mirror of adding one: the section and its contents both go.' );
check( 2 === (int) $shrink['by_type']['removed'], 'Both are removals.' );
$shrink_kinds = array();
foreach ( $shrink['changes'] as $change ) { $shrink_kinds[] = $change['type'] . ':' . $change['category']; }
sort( $shrink_kinds );
check( array( 'removed:component', 'removed:section' ) === $shrink_kinds, 'And the section removal is distinguishable from the component removal inside it.' );

echo "--- 9. A field removed from the source is a change ---\n";

// A style that was dropped upstream is a real difference, not an absence.
$had_radius = array( array( 'id' => 'card', 'role' => 'card', 'text' => 'Pro', 'border_radius' => 8 ) );
$no_radius  = array( array( 'id' => 'card', 'role' => 'card', 'text' => 'Pro' ) );
$dropped    = $detector->compare( page( $had_radius ), page( $no_radius ) );

// border_radius is not a compared field, so this is deliberately not a change. The
// point of the test is that uncompared fields do not leak in as noise.
check( 0 === $dropped['count'], 'A field that is not on the comparison list does not produce a change, so the report stays about what matters.' );

$had_color = array( array( 'id' => 'card', 'role' => 'card', 'text' => 'Pro', 'color' => '#6c63ff' ) );
$no_color  = array( array( 'id' => 'card', 'role' => 'card', 'text' => 'Pro' ) );
$lost_color = $detector->compare( page( $had_color ), page( $no_color ) );
check( 1 === $lost_color['count'], 'A compared field that disappeared upstream is a change, because dropping a style is visible.' );
check( 'color' === (string) $lost_color['changes'][0]['category'], 'Categorised as color.' );
check( null === $lost_color['changes'][0]['detail']['new_value'], 'The new value is null, which is a difference and not a zero.' );

echo "--- 10. Ignored fields can be excluded ---\n";

$noisy = $base_components;
$noisy[1]['text'] = 'A different paragraph entirely';
$ignored = $detector->compare( page( $base_components ), page( $noisy ), array( 'ignore' => array( 'text' ) ) );
check( 0 === $ignored['count'], 'A field on the ignore list does not produce a change.' );
check( array( 'text' ) === $ignored['ignored_fields'], 'And the ignored list is reported, so a filtered report says it was filtered.' );

echo "--- 11. Structural comparison is bounded and honest about it ---\n";

$huge = array();
for ( $index = 0; $index < Sync_Limits::MAX_COMPONENTS + 300; $index++ ) {
	$huge[] = array( 'id' => 'c' . $index, 'role' => 'card', 'text' => 'Card ' . $index );
}
$huge_report = $detector->compare( page( $huge ), page( $huge ) );
check( true === (bool) $huge_report['truncated'], 'A page larger than the comparison bound reports that it was truncated.' );
check( true === (bool) $huge_report['components']['bounded'], 'And the component report says the comparison was bounded.' );
check( true === (bool) $huge_report['components']['flatten_truncated'], 'And separately that the component list itself was cut short, because a truncated list and a bounded matcher are different problems and a no-changes result has to say which one it is.' );
check( Sync_Limits::MAX_COMPONENTS === (int) $huge_report['components']['previous'], 'And the retained count is the cap, not the page.' );

// A comparison that was truncated says so, so a caller cannot read a shortened
// page as an unchanged one.
check( false === (bool) $text_change['truncated'], 'A comparison well inside every bound is not truncated.' );
check( false === (bool) $text_change['identical'], 'And a comparison with a real difference is not identical.' );
check( Sync_Limits::MAX_COMPONENTS === (int) $huge_report['components']['previous'], 'The compared count is the bound, not the page.' );

echo "--- 12. A page with no components ---\n";

$empty_a = $detector->compare( array( 'sections' => array() ), array( 'sections' => array() ) );
check( 0 === $empty_a['count'], 'Two empty pages produce no changes.' );

$empty_b = $detector->compare( page( $base_components ), array( 'sections' => array() ) );
// Losing a whole section removes the section *and* everything in it. Both are true
// and neither is redundant: a user deciding what to do needs to know the band is
// gone, and the structured report is what makes that legible rather than four
// independent component deletions.
check( 4 === $empty_b['count'], 'Losing a section reports the section removal and each component inside it.' );
check( 4 === (int) $empty_b['by_type']['removed'], 'All four are removals.' );

$empty_b_kinds = array();
foreach ( $empty_b['changes'] as $change ) {
	$empty_b_kinds[] = $change['category'];
}
$section_count = 0;
$component_count = 0;
foreach ( $empty_b_kinds as $kind ) {
	if ( 'section' === $kind ) { $section_count++; } else { $component_count++; }
}
check( 1 === $section_count, 'One of them is the section itself.' );
check( 3 === $component_count, 'And three are the components it held.' );

echo "--- 13. Classification: severity is evidence-based ---\n";

$classified = $classifier->classify_all( $text_change['changes'] );
check( 1 === $classified['count'], 'The one text change is classified.' );
check( 'minor' === (string) $classified['changes'][0]['severity'], 'A text change is minor severity.' );

// The real confidence of a reworded heading is about 0.86, not 1.0, because the
// text similarity contributes to the score and a reworded paragraph is not an
// identical one. That is below the automatic threshold, so the change goes to a
// person. This is the common case and it is asserted as it actually behaves rather
// than tuned to make the automatic path look exercised.
check( $text_change['changes'][0]['confidence'] < 1.0, 'A reworded heading is matched with less than certainty, because the text is part of what changed.' );
// Low risk but still reviewed. That is the whole reason the three answers are kept
// separate: the change is harmless, and the reason a person is asked is that the
// system is not certain, which is a different objection.
check( 'low' === (string) $classified['changes'][0]['risk'], 'Its risk stays low, because a text change is harmless however it is resolved.' );
check( true === (bool) $classified['changes'][0]['review'], 'So it requires a person.' );
check( false === (bool) $classified['changes'][0]['auto'], 'And is not applied without a decision.' );
check( ! empty( $classified['changes'][0]['basis'] ), 'It carries the rules that produced the classification.' );

// The automatic path is reachable, and it is reachable only for a confidently matched
// content change. Both halves of the condition are asserted.
$certain = $classifier->classify(
	array( 'type' => 'modified', 'category' => 'content', 'field' => 'text', 'source_component_id' => 'x', 'confidence' => 0.98 )
);
check( 'low' === (string) $certain['risk'], 'A confidently matched content change keeps the lowest risk.' );
check( false === (bool) $certain['review'], 'And needs no decision.' );
check( true === (bool) $certain['auto'], 'And is the only kind of change that may be applied without one.' );

$color_change = array(
	'type' => 'modified', 'category' => 'color', 'field' => 'color',
	'source_component_id' => 'card', 'confidence' => 1.0,
);
$color_class = $classifier->classify( $color_change );
check( 'moderate' === (string) $color_class['severity'], 'A colour change is moderate severity, because it is visible.' );
check( 'low' === (string) $color_class['risk'], 'But its risk is low, because the severities are separate questions.' );
check( false === (bool) $color_class['auto'], 'And it is not automatic, because colour is not on the auto-safe category list.' );
check( false === (bool) $color_class['review'], 'It also does not require review, so a person is neither asked nor prevented.' );

echo "--- 14. A removal is never automatic ---\n";

$removal = array(
	'type' => 'removed', 'category' => 'component', 'field' => '',
	'source_component_id' => 'hero_button', 'confidence' => 1.0,
);
$removal_class = $classifier->classify( $removal );
check( 'major' === (string) $removal_class['severity'], 'A removal is at least a major change, whatever it removes.' );
check( 'high' === (string) $removal_class['risk'], 'And high risk.' );
check( false === (bool) $removal_class['auto'], 'It is never applied automatically.' );
check( true === (bool) $removal_class['review'], 'And always requires a person, per §13.' );
check( false !== strpos( implode( ' ', (array) $removal_class['basis'] ), 'removal' ), 'The basis names the removal rule.' );

echo "--- 15. The never-automatic categories ---\n";

foreach ( array( 'section', 'navigation', 'interaction', 'theme', 'product' ) as $category ) {
	$entry = $classifier->classify(
		array( 'type' => 'modified', 'category' => $category, 'field' => '', 'source_component_id' => 'x', 'confidence' => 1.0 )
	);
	check( false === (bool) $entry['auto'], 'A ' . $category . ' change is never applied automatically.' );
	check( true === (bool) $entry['review'], 'And always requires a person.' );
	check( 'high' === (string) $entry['risk'], 'And carries a high risk, because the harm is not expressible as a number.' );
}

// A price is included above deliberately: ReplicaForge has no business changing a
// commercial figure unattended.
$price = $classifier->classify(
	array( 'type' => 'modified', 'category' => 'product', 'field' => 'price', 'source_component_id' => 'p', 'confidence' => 1.0 )
);
check( 'major' === (string) $price['severity'], 'A price change is major severity.' );
check( false === (bool) $price['auto'], 'And is never automatic.' );

echo "--- 16. Low confidence is not permission ---\n";

$unsure = $classifier->classify(
	array( 'type' => 'modified', 'category' => 'content', 'field' => 'text', 'source_component_id' => 'x', 'confidence' => 0.5 )
);
check( true === (bool) $unsure['review'], 'A change matched at low confidence requires review.' );
check( false === (bool) $unsure['auto'], 'And is not automatic, because not knowing what was matched is not a reason to act.' );
check( 'medium' === (string) $unsure['risk'], 'Its risk is raised, because applying it might address the wrong component.' );

$just_under = $classifier->classify(
	array( 'type' => 'modified', 'category' => 'content', 'field' => 'text', 'source_component_id' => 'x', 'confidence' => Sync_Limits::MIN_AUTO_CONFIDENCE - 0.01 )
);
check( false === (bool) $just_under['auto'], 'A confidence just below the threshold is still not automatic.' );

$at_threshold = $classifier->classify(
	array( 'type' => 'modified', 'category' => 'content', 'field' => 'text', 'source_component_id' => 'x', 'confidence' => Sync_Limits::MIN_AUTO_CONFIDENCE )
);
check( true === (bool) $at_threshold['auto'], 'And at the threshold it is, so the threshold is where it says it is.' );

echo "--- 17. A responsive change is treated as more dangerous than it looks ---\n";

$responsive = $classifier->classify(
	array( 'type' => 'modified', 'category' => 'responsive', 'field' => '', 'source_component_id' => 'x', 'confidence' => 1.0 )
);
check( 'medium' === (string) $responsive['risk'], 'A responsive change carries at least medium risk, because it is invisible in a single-viewport check.' );
check( true === (bool) $responsive['review'], 'And requires a person.' );

echo "--- 18. Grouping, per §50 ---\n";

$group_input = array(
	$classifier->classify( array( 'type' => 'modified', 'category' => 'typography', 'field' => 'font_size', 'source_component_id' => 'hero_heading', 'confidence' => 1.0 ) ),
	$classifier->classify( array( 'type' => 'modified', 'category' => 'typography', 'field' => 'line_height', 'source_component_id' => 'hero_heading', 'confidence' => 1.0 ) ),
	$classifier->classify( array( 'type' => 'modified', 'category' => 'typography', 'field' => 'letter_spacing', 'source_component_id' => 'hero_heading', 'confidence' => 1.0 ) ),
	$classifier->classify( array( 'type' => 'modified', 'category' => 'color', 'field' => 'color', 'source_component_id' => 'hero_button', 'confidence' => 1.0 ) ),
);
$grouped = $classifier->group( $group_input );

check( 2 === count( $grouped ), 'Three typography changes on one component group into one, and a change on another component is its own group.' );
check( 3 === (int) $grouped[0]['count'], 'The typography group holds all three.' );
check( 3 === count( $grouped[0]['fields'] ), 'And lists all three fields, so the detail is expandable rather than lost.' );
check( 'hero_heading' === (string) $grouped[0]['component_id'], 'The group names the component.' );
check( 'moderate' === (string) $grouped[0]['severity'], 'The group carries the highest severity of its members.' );

echo "--- 19. Batch classification counts ---\n";

$all = $classifier->classify_all( array_merge( $text_change['changes'], $removed['changes'] ) );
check( 2 === (int) $all['count'], 'Both changes are classified.' );
check( 1 === (int) $all['by_severity']['minor'], 'One is minor severity.' );
check( 1 === (int) $all['by_severity']['major'], 'One is major.' );
check( 1 === (int) $all['by_risk']['high'], 'One is high risk.' );
// Neither is automatic: the text change because the match is not certain enough, and
// the removal because a removal is never automatic. The automatic path needs a
// confidently matched change, which a changed field never is.
check( 0 === (int) $all['auto'], 'Neither is automatic. A removal never is, and a change to a field cannot be matched with certainty because the field is what differs.' );
check( 2 === (int) $all['review'], 'Both require a person.' );
check( 5 === count( $all['by_severity'] ), 'Every declared severity has a count, including the ones that are zero.' );
check( 4 === count( $all['by_risk'] ), 'And every declared risk level has a count.' );

echo "--- 20. Degenerate input ---\n";

$none = $classifier->classify( array() );
check( Sync_Limits::is_severity( $none['severity'] ), 'An empty change still receives a valid severity.' );
check( Sync_Limits::is_risk( $none['risk'] ), 'And a valid risk.' );
check( false === (bool) $none['auto'], 'And is not automatic, because an unclassifiable change is not a safe one.' );

$bad_category = $classifier->classify( array( 'type' => 'nonsense', 'category' => 'vibes', 'field' => 'wobble' ) );
check( 'modified' === (string) $bad_category['type'], 'An unknown type falls back to modified rather than being stored as nonsense.' );
check( 'component' === (string) $bad_category['category'], 'And an unknown category falls back to component.' );

$empty_detection = $detector->compare( array(), array() );
check( 0 === $empty_detection['count'], 'Two completely empty representations compare to no changes.' );
check( true === (bool) $empty_detection['identical'], 'And report as identical.' );

echo "--- 21. A flat component list with no sections still compares ---\n";

$flat_a = array( 'components' => array( array( 'id' => 'h', 'role' => 'h1', 'text' => 'One' ) ) );
$flat_b = array( 'components' => array( array( 'id' => 'h', 'role' => 'h1', 'text' => 'Two' ) ) );
$flat   = $detector->compare( $flat_a, $flat_b );
check( 1 === $flat['count'], 'A representation with no section structure still compares its components, rather than reporting nothing to do.' );

echo "--- 22. A structured value compares by content, not by order ---\n";

$order_a = page( array( array( 'id' => 'card', 'role' => 'card', 'icon' => array( 'a' => 1, 'b' => 2 ) ) ) );
$order_b = page( array( array( 'id' => 'card', 'role' => 'card', 'icon' => array( 'b' => 2, 'a' => 1 ) ) ) );
$reordered = $detector->compare( $order_a, $order_b );
check( 0 === $reordered['count'], 'A structured value with its keys in a different order is the same value, so key order alone is not a change.' );

echo "\nPhase 9 change detection test passed. Assertions: {$assertions}\n";
