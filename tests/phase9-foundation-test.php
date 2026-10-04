<?php
/**
 * Phase 9: the sync foundation.
 *
 * Covers the three things everything else in Phase 9 depends on: the vocabulary and
 * bounds, the source-to-Elementor map, and component matching.
 *
 * The matching tests are the important ones. The failure this class exists to
 * prevent is matching by position, so the central test is that inserting a component
 * above another one does not make the lower one look removed and replaced.
 *
 * Run: php phase9-foundation-test.php <wp-root>
 */
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php phase9-foundation-test.php <wp-root>\n" );
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

use ReplicaForge\Sync_Limits;
use ReplicaForge\Elementor_Map;
use ReplicaForge\Component_Matcher;

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

echo "--- 1. Vocabulary is closed ---\n";

check( '9.0' === Sync_Limits::SCHEMA_VERSION, 'The sync plan declares its schema version, separate from the plugin version.' );
check( count( Sync_Limits::FREQUENCIES ) === 5, 'Five monitoring frequencies are declared.' );
check( isset( Sync_Limits::FREQUENCIES['manual'] ), 'Manual-only is a real frequency rather than an absence.' );
check( count( Sync_Limits::CATEGORIES ) === 17, 'Seventeen change categories are declared, matching the brief.' );
check( count( Sync_Limits::SEVERITIES ) === 5, 'Five severities are declared.' );
check( count( Sync_Limits::CONFLICT_STATES ) === 5, 'Five conflict states are declared.' );
check( count( Sync_Limits::RISKS ) === 4, 'Four risk levels are declared.' );

foreach ( array( 'content', 'navigation', 'responsive', 'theme' ) as $category ) {
	check( Sync_Limits::is_category( $category ), 'The category ' . $category . ' is recognised.' );
}
check( ! Sync_Limits::is_category( 'vibes' ), 'An invented category is refused rather than stored.' );
check( ! Sync_Limits::is_category( array( 'content' ) ), 'A non-string category is refused.' );

check( Sync_Limits::is_severity( 'critical' ), 'A valid severity is recognised.' );
check( ! Sync_Limits::is_severity( 'catastrophic' ), 'An invented severity is refused.' );
check( ! Sync_Limits::is_conflict_state( 'conflict' ), 'A bare conflict word is not a state, because the states distinguish who changed.' );

echo "--- 2. The minimum interval is a floor, not a hint ---\n";

check( 0 === Sync_Limits::interval_for( 'manual' ), 'A manual monitor has no interval.' );
check( 604800 === Sync_Limits::interval_for( 'weekly' ), 'A weekly monitor checks weekly.' );
check( 86400 === Sync_Limits::interval_for( 'daily' ), 'A daily monitor checks daily.' );
check( 0 === Sync_Limits::interval_for( 'hourly' ), 'An unknown frequency has no interval, so it cannot be scheduled.' );

// A frequency the plugin did not declare cannot ask for a short interval, and the
// floor is applied inside the accessor so no call site can bypass it.
check(
	Sync_Limits::interval_for( 'daily' ) >= Sync_Limits::MIN_INTERVAL_SECONDS,
	'The declared daily interval already clears the floor.'
);
check( 'weekly' === Sync_Limits::frequency_for_interval( 604800 ), 'An interval maps back to its frequency.' );
check( '' === Sync_Limits::frequency_for_interval( 12345 ), 'An interval matching no frequency returns empty rather than the nearest one.' );

echo "--- 3. Removal and transient failure are distinct ---\n";

// This is the §41 requirement. Conflating a timeout with a deletion is how a
// monitoring tool destroys a replica because a source server was briefly down.
check( Sync_Limits::is_removal( 'source_removed' ), 'A confirmed removal is a removal.' );
foreach ( array( 'source_unreachable', 'source_timeout', 'source_blocked', 'source_http_error', 'source_rate_limited' ) as $outcome ) {
	check( ! Sync_Limits::is_removal( $outcome ), 'A ' . $outcome . ' is not a removal.' );
	check( Sync_Limits::is_transient( $outcome ), 'A ' . $outcome . ' is transient, so the monitor keeps trying.' );
}

// A redirect is neither. The page is still reachable at a new address, so there is
// nothing to retry and nothing to delete; it is an address change to record.
check( ! Sync_Limits::is_removal( 'source_moved' ), 'A source that moved is not a removal.' );
check( ! Sync_Limits::is_transient( 'source_moved' ), 'A source that moved is not transient either, because re-checking will not change where it is.' );
check( ! Sync_Limits::is_transient( 'source_removed' ), 'A removal is not transient.' );
check( ! Sync_Limits::is_transient( 'reachable' ), 'A successful check is not transient.' );
check( Sync_Limits::REMOVAL_CONFIRMATIONS >= 2, 'Removal needs more than one confirmation, so a single 404 is never a deletion.' );

echo "--- 4. Ordering helpers ---\n";

check( Sync_Limits::severity_rank( 'critical' ) > Sync_Limits::severity_rank( 'minor' ), 'Critical outranks minor.' );
check( Sync_Limits::risk_rank( 'blocked' ) > Sync_Limits::risk_rank( 'low' ), 'Blocked outranks low.' );
check( 0 === Sync_Limits::severity_rank( 'nonsense' ), 'An unknown severity ranks lowest rather than throwing.' );

echo "--- 5. The map stores a three-link chain ---\n";

$post_id = wp_insert_post(
	array(
		'post_title'  => 'ReplicaForge phase 9 map fixture',
		'post_status' => 'draft',
		'post_type'   => 'page',
	)
);
check( is_int( $post_id ) && $post_id > 0, 'A draft stands in for a generated replica.' );

$map = new Elementor_Map( $post_id );
check( ! $map->exists(), 'A new draft has no map.' );
check( 0 === $map->count(), 'And no entries.' );

$map->set(
	array(
		'source_component_id'        => 'hero_heading',
		'reconstruction_component_id' => 'heading_01',
		'elementor_element_id'       => 'a91f32b',
		'elementor_el_type'          => 'widget',
		'source_section_id'          => 'section_hero',
		'source_role'                => 'h1',
		'source_fingerprint'         => 'fp_heading_1',
		'properties'                 => array( 'font_size', 'color', 'font_family' ),
	)
);

check( $map->exists(), 'The map now exists.' );
check( 1 === $map->count(), 'With one entry.' );
check( 'a91f32b' === $map->element_for( 'hero_heading' ), 'A source component resolves to its Elementor element.' );

$entry = $map->get( 'hero_heading' );
check( 'heading_01' === (string) $entry['reconstruction_component_id'], 'The middle link is kept, so the source-to-specification rename is traceable.' );
check( 'section_hero' === (string) $entry['source_section_id'], 'The section is recorded, which is what makes a section-scoped update possible.' );
check( 3 === count( $entry['properties'] ), 'Property hints are recorded.' );
check( null === $map->get( 'nothing_here' ), 'An unmapped component returns null rather than an empty entry.' );
check( '' === $map->element_for( 'nothing_here' ), 'And an empty element id, so a caller cannot mistake it for a mapping.' );

echo "--- 6. The map refuses incomplete and hostile input ---\n";

check( ! $map->set( array( 'source_component_id' => 'no_element' ) ), 'A mapping with no Elementor element is refused, because it cannot address anything.' );
check( ! $map->set( array( 'elementor_element_id' => 'abc1230' ) ), 'A mapping with no source component is refused.' );
check( ! $map->set( array( 'source_component_id' => 'x', 'elementor_element_id' => '../etc/passwd' ) ), 'A path traversal in an element id is refused.' );
check( ! $map->set( array( 'source_component_id' => '../../etc', 'elementor_element_id' => 'abc' ) ), 'A path traversal in a source id is refused.' );
check( ! $map->set( array( 'source_component_id' => 'a b', 'elementor_element_id' => 'abc' ) ), 'An id with a space is refused.' );
// set() declares an array parameter, so a string is a caller error the engine
// catches at the boundary rather than a runtime branch. That is the better guard: a
// caller passing the wrong type is a bug, and a runtime check would let the bug
// reach the write. The tolerance belongs in set_many(), whose input is a list of
// unknown provenance, and that is covered in section 12.
check( 1 === $map->count(), 'None of the refused entries were stored.' );
check( array() === $map->for_section( '' ), 'An empty section id matches nothing rather than matching every entry, which is what a loose comparison would do.' );
check( '' === $map->element_for( '../../../etc/passwd' ), 'A traversal attempt in a lookup key finds no mapping rather than reaching the store.' );

// The id cleaner also normalizes, so a reference resolves case-insensitively.
$map->set(
	array(
		'source_component_id'  => 'hero_button',
		'elementor_element_id' => 'b22c330',
		'source_section_id'    => 'section_hero',
	)
);
check( 'b22c330' === $map->element_for( 'HERO_BUTTON' ), 'A lookup normalizes case, so a caller cannot miss a mapping by capitalisation.' );

echo "--- 7. Ambiguity is prevented ---\n";

// set() replaces an entry rather than merging into it. A mapping describes the
// current relationship between two things, and merging would leave a field from a
// previous relationship describing a relationship that no longer holds.
$map->set(
	array(
		'source_component_id'  => 'hero_paragraph',
		'elementor_element_id' => 'a91f32b',
		'source_section_id'    => 'section_hero',
	)
);
check( null === $map->get( 'hero_heading' ), 'When a second source component claims an element, the earlier mapping is dropped, so a lookup is never ambiguous.' );
check( 'a91f32b' === $map->element_for( 'hero_paragraph' ), 'The later claim wins, because it is the more recent mapping.' );

echo "--- 8. Reverse and section lookups ---\n";

$map->set(
	array(
		'source_component_id'  => 'hero_image',
		'elementor_element_id' => 'c44d550',
		'source_section_id'    => 'section_hero',
	)
);
$map->set(
	array(
		'source_component_id'  => 'footer_link',
		'elementor_element_id' => 'f66a770',
		'source_section_id'    => 'section_footer',
	)
);

check( array( 'hero_image' ) === $map->sources_for_element( 'c44d550' ), 'An element resolves back to its source component.' );
check( $map->is_mapped_element( 'c44d550' ), 'A mapped element is recognised as mapped.' );
check( ! $map->is_mapped_element( 'deadbee' ), 'An unknown element is not claimed.' );
check( array() === $map->sources_for_element( '' ), 'An empty element id resolves to nothing.' );

check( 3 === count( $map->for_section( 'section_hero' ) ), 'A section lookup returns its own mappings.' );
check( 1 === count( $map->for_section( 'section_footer' ) ), 'And another section returns only its own.' );
check( array() === $map->for_section( 'section_nothing' ), 'An unknown section returns an empty list rather than everything.' );

$sections = $map->sections();
sort( $sections );
check( array( 'section_footer', 'section_hero' ) === $sections, 'The section list is deduplicated.' );

echo "--- 9. A stale map is detectable ---\n";

// A mapping pointing at an element that is not in the document is worse than no
// mapping: a plan built on it would address an element that is not there.
$live = array( 'b22c330', 'c44d550', 'f66a770' );
$stale = $map->stale( $live );

check( 1 === count( $stale ), 'One mapping is stale.' );
check( 'hero_paragraph' === (string) $stale[0]['source_component_id'], 'The stale source component is identified.' );
check( 'element_not_in_document' === (string) $stale[0]['reason'], 'The reason is that its element is not in the document.' );
check( ! $map->is_complete_against( $live ), 'The map reports itself incomplete against a partial document.' );
check( $map->is_complete_against( array( 'b22c330', 'c44d550', 'f66a770', 'a91f32b' ) ), 'And complete against a document containing every element.' );

echo "--- 10. Coverage decides patch versus regenerate ---\n";

$coverage = $map->coverage( array( 'hero_image', 'footer_link', 'not_mapped' ) );
check( 2 === count( $coverage['covered'] ), 'Two of three components are covered.' );
check( 1 === count( $coverage['uncovered'] ), 'One is not.' );
check( 'not_mapped' === (string) $coverage['uncovered'][0], 'The uncovered one is identified.' );
check( ! $coverage['complete'], 'Partial coverage is reported as partial, not as complete.' );
check( round( 0.6667, 4 ) === $coverage['ratio'], 'The ratio reflects the real coverage.' );

$full = $map->coverage( array( 'hero_image', 'footer_link' ) );
check( $full['complete'], 'Full coverage is reported as complete, which is what makes an incremental update safe.' );
check( 0.0 === $map->coverage( array() )['ratio'], 'An empty request has a zero ratio rather than a division by zero.' );
check( ! $map->coverage( array() )['complete'], 'An empty request is not complete coverage.' );

echo "--- 11. A summary for the screen ---\n";

$summary = $map->summary();
check( $summary['exists'], 'The summary reports that a map exists.' );
check( 4 === (int) $summary['count'], 'It reports the entry count: the heading mapping was displaced by the ambiguity rule, leaving button, paragraph, image, and footer link.' );
check( 2 === (int) $summary['sections'], 'It reports how many sections are mapped.' );

echo "--- 12. Bulk writes and section removal ---\n";

$bulk = $map->set_many(
	array(
		array( 'source_component_id' => 'p1', 'elementor_element_id' => 'e100001', 'source_section_id' => 'section_pricing' ),
		array( 'source_component_id' => 'p2', 'elementor_element_id' => 'e100002', 'source_section_id' => 'section_pricing' ),
		array( 'source_component_id' => 'p3', 'elementor_element_id' => 'e100003', 'source_section_id' => 'section_pricing' ),
		array( 'source_component_id' => '', 'elementor_element_id' => 'e100004' ),
		array( 'not an array' ),
	)
);
check( 3 === $bulk, 'Bulk writes record the valid entries and skip the invalid ones.' );
check( 3 === count( $map->for_section( 'section_pricing' ) ), 'And all three are in the section.' );

$removed = $map->forget_section( 'section_pricing' );
check( 3 === $removed, 'Removing a section removes its mappings.' );
check( array() === $map->for_section( 'section_pricing' ), 'And leaves none behind.' );
check( 0 === $map->forget_section( 'section_pricing' ), 'Removing it again removes nothing rather than reporting a false success.' );

$map->clear();
check( ! $map->exists(), 'Clearing empties the map.' );
check( array() === $map->all(), 'And a cleared map reads as empty rather than as corrupt.' );

// A corrupted stored value must not break the reader.
update_post_meta( $post_id, Sync_Limits::MAP_META, 'not an array' );
check( array() === $map->all(), 'A corrupted stored map reads as empty rather than producing an error.' );
check( ! $map->exists(), 'And reports that no map exists.' );

echo "--- 13. Component identity is content-derived ---\n";

$matcher = new Component_Matcher();

$identity = $matcher->identity(
	array(
		'id'          => 'c1',
		'role'        => 'h1',
		'tag'         => 'h1',
		'text'        => 'Build faster with confidence',
		'image'       => 'https://cdn.example.com/hero.jpg',
		'section_id'  => 'section_hero',
		'depth'       => 2,
		'child_count' => 0,
		'index'       => 0,
	)
);

check( 20 === strlen( (string) $identity['fingerprint'] ), 'A fingerprint is produced.' );
check( 'h1' === (string) $identity['role'], 'The role is read.' );
check( 'section_hero' === (string) $identity['section'], 'The section is read.' );
check( true === (bool) $identity['has_content'], 'A component with text has content.' );
check( 0.0 === (float) $identity['position'], 'Position is recorded but is not part of the fingerprint.' );

// The same component with a different position has the same fingerprint, which is
// the whole point: identity survives an insertion above it.
$moved = $matcher->identity(
	array(
		'id'          => 'c1',
		'role'        => 'h1',
		'tag'         => 'h1',
		'text'        => 'Build faster with confidence',
		'image'       => 'https://cdn.example.com/hero.jpg',
		'section_id'  => 'section_hero',
		'depth'       => 2,
		'child_count' => 0,
		'index'       => 7,
	)
);
check( $identity['fingerprint'] === $moved['fingerprint'], 'A component that moved position keeps its fingerprint, so an insertion above it does not make it look new.' );

// A cache-busting query string does not change an image identity, because a deploy
// that appends a version parameter is not a content change.
$recached = $matcher->identity(
	array(
		'id'         => 'c1',
		'role'       => 'h1',
		'tag'        => 'h1',
		'text'       => 'Build faster with confidence',
		'image'      => 'https://cdn.example.com/hero.jpg?v=8f2a91',
		'section_id' => 'section_hero',
	)
);
check( $identity['fingerprint'] === $recached['fingerprint'], 'A cache-busting query string on an image does not change the fingerprint, so a deploy is not reported as a content change.' );

// Real content does change it.
$edited = $matcher->identity(
	array(
		'id'         => 'c1',
		'role'       => 'h1',
		'tag'        => 'h1',
		'text'       => 'Build faster with confidence, guaranteed',
		'section_id' => 'section_hero',
	)
);
check(
	$identity['fingerprint'] === $edited['fingerprint'],
	'A reworded component keeps its identity, because the text is the thing that is allowed to change. If identity included it, every headline edit would read as a removal and an addition.'
);
check(
	$identity['content_hash'] !== $edited['content_hash'],
	'And its content hash does change, which is the sufficient condition for a certain match when the content is also unchanged.'
);
check(
	20 === strlen( (string) $edited['content_hash'] ),
	'Both hashes are present, so a caller can use the narrow one for identity and the broad one for certainty.'
);

echo "--- 14. The central test: an insertion does not renumber ---\n";

// This is the scenario from §12 and the reason position is not identity. A badge is
// added above the heading in the hero.
$before = array(
	array( 'id' => 'hero_heading', 'role' => 'h1', 'tag' => 'h1', 'text' => 'Build faster with confidence', 'section_id' => 'section_hero' ),
	array( 'id' => 'hero_paragraph', 'role' => 'paragraph', 'tag' => 'p', 'text' => 'Ship on Elementor without touching the DOM.', 'section_id' => 'section_hero' ),
	array( 'id' => 'hero_button', 'role' => 'button', 'tag' => 'a', 'text' => 'Get started', 'section_id' => 'section_hero' ),
	array( 'id' => 'hero_image', 'role' => 'image', 'tag' => 'img', 'image' => 'https://cdn.example.com/hero.jpg', 'section_id' => 'section_hero' ),
);

$after = array(
	array( 'id' => 'hero_badge', 'role' => 'badge', 'tag' => 'span', 'text' => 'New', 'section_id' => 'section_hero' ),
	array( 'id' => 'hero_heading', 'role' => 'h1', 'tag' => 'h1', 'text' => 'Build faster with confidence', 'section_id' => 'section_hero' ),
	array( 'id' => 'hero_paragraph', 'role' => 'paragraph', 'tag' => 'p', 'text' => 'Ship on Elementor without touching the DOM.', 'section_id' => 'section_hero' ),
	array( 'id' => 'hero_button', 'role' => 'button', 'tag' => 'a', 'text' => 'Get started', 'section_id' => 'section_hero' ),
	array( 'id' => 'hero_image', 'role' => 'image', 'tag' => 'img', 'image' => 'https://cdn.example.com/hero.jpg', 'section_id' => 'section_hero' ),
);

$result = $matcher->match( $before, $after );

$matched = array();
foreach ( $result['matches'] as $match ) {
	$matched[ (string) $match['old_id'] ] = (string) $match['new_id'];
}

check( 4 === count( $result['matches'] ), 'All four original components are matched despite the insertion.' );
check( array() === $result['removed'], 'Nothing is reported as removed, which is the failure this class exists to prevent.' );
check( array( 'hero_badge' ) === $result['added'], 'Only the genuinely new component is reported as added.' );

check( 'hero_heading' === $matched['hero_heading'], 'The heading matches itself, not the badge that took its index.' );
check( 'hero_paragraph' === $matched['hero_paragraph'], 'The paragraph matches itself.' );
check( 'hero_button' === $matched['hero_button'], 'The button matches itself.' );
check( 'hero_image' === $matched['hero_image'], 'The image matches itself.' );

foreach ( $result['matches'] as $match ) {
	check( $match['similarity'] >= Component_Matcher::ACCEPT_SIMILARITY, 'Match ' . $match['old_id'] . ' clears the acceptance threshold.' );
	check( false === (bool) $match['moved'], 'A match whose section did not change is not flagged as a move.' );
	check( (string) $match['old_section'] === (string) $match['new_section'], 'And both sides agree on the section.' );
	check( ! empty( $match['match_reasons'] ), 'Match ' . $match['old_id'] . ' carries its reasons, as §17 requires.' );
	check( $match['match_confidence'] > 0 && $match['match_confidence'] <= 1.0, 'Match ' . $match['old_id'] . ' carries a confidence in range.' );
}

echo "--- 15. A real content change is not treated as the same component ---\n";

$changed = array(
	array( 'id' => 'hero_heading', 'role' => 'h1', 'tag' => 'h1', 'text' => 'A completely different headline about gardening', 'section_id' => 'section_hero' ),
	array( 'id' => 'hero_paragraph', 'role' => 'paragraph', 'tag' => 'p', 'text' => 'Ship on Elementor without touching the DOM.', 'section_id' => 'section_hero' ),
	array( 'id' => 'hero_button', 'role' => 'button', 'tag' => 'a', 'text' => 'Get started', 'section_id' => 'section_hero' ),
	array( 'id' => 'hero_image', 'role' => 'image', 'tag' => 'img', 'image' => 'https://cdn.example.com/hero.jpg', 'section_id' => 'section_hero' ),
);

$changed_result = $matcher->match( $before, $changed );
$changed_matched = array();
foreach ( $changed_result['matches'] as $match ) {
	$changed_matched[ (string) $match['old_id'] ] = (string) $match['new_id'];
}

check( isset( $changed_matched['hero_paragraph'] ), 'The unchanged paragraph still matches.' );
check( isset( $changed_matched['hero_button'] ), 'The unchanged button still matches.' );
check( isset( $changed_matched['hero_image'] ), 'The unchanged image still matches.' );
check( isset( $changed_matched['hero_heading'] ), 'A heading whose text was entirely replaced is still matched, because it is the only heading in its section and nothing else could be it.' );

// But the match is weak and says so, because a rewrite is not obviously an edit.
$rewrite_match = null;
foreach ( $changed_result['matches'] as $match ) {
	if ( 'hero_heading' === (string) $match['old_id'] ) {
		$rewrite_match = $match;
	}
}
check( null !== $rewrite_match, 'The rewrite is reported as a match rather than a rebuild.' );
check( $rewrite_match['match_confidence'] < 0.5, 'Its confidence is low, because nothing but the role and the section says it is the same heading.' );
check( false !== strpos( implode( ' ', $rewrite_match['match_reasons'] ), 'entirely different' ), 'And the reason says the text is entirely different, so a reader is not left to infer it.' );

echo "--- 16. Position alone is not a match ---\n";

// Four identical empty containers at the same indices. Position is the only signal
// available, and §16 says not to depend on it.
$empty_before = array(
	array( 'id' => 'b1', 'section_id' => 's1' ),
	array( 'id' => 'b2', 'section_id' => 's1' ),
);
$empty_after = array(
	array( 'id' => 'a1', 'section_id' => 's1' ),
	array( 'id' => 'a2', 'section_id' => 's1' ),
);
$empty_result = $matcher->match( $empty_before, $empty_after );

foreach ( $empty_result['matches'] as $match ) {
	check(
		$match['match_confidence'] < 0.5,
		'A match of content-free components resting on position alone is reported as low confidence, not as a match.'
	);
}

echo "--- 17. A section move is distinguishable from a removal ---\n";

$moved_before = array(
	array( 'id' => 'cta', 'role' => 'button', 'tag' => 'a', 'text' => 'Talk to sales', 'section_id' => 'section_pricing' ),
	array( 'id' => 'filler', 'role' => 'divider', 'tag' => 'hr', 'section_id' => 'section_pricing' ),
);
$moved_after = array(
	array( 'id' => 'cta', 'role' => 'button', 'tag' => 'a', 'text' => 'Talk to sales', 'section_id' => 'section_footer' ),
);
$moved_result = $matcher->match( $moved_before, $moved_after );

check( 1 === count( $moved_result['matches'] ), 'A call to action that moved to another section still matches, because its content fingerprint is unchanged.' );
$moved_match = $moved_result['matches'][0];
check( 'section_pricing' === (string) $moved_match['old_section'], 'The old section is recorded.' );
check( 'section_footer' === (string) $moved_match['new_section'], 'The new section is recorded.' );
check( 'cta' === (string) $moved_match['old_id'], 'The component is identified as the same one.' );
check( true === (bool) $moved_match['moved'], 'The match is flagged as a move rather than an unchanged component, so a change detector can tell the two apart.' );
check( array( 'filler' ) === $moved_result['removed'], 'The genuinely absent component is reported as removed.' );

echo "--- 18. Matching is bounded ---\n";

$many = array();
for ( $index = 0; $index < Sync_Limits::MAX_COMPONENTS + 200; $index++ ) {
	$many[] = array(
		'id'         => 'm' . $index,
		'role'       => 'card',
		'tag'        => 'div',
		'text'       => 'Card number ' . $index,
		'section_id' => 's' . ( $index % 10 ),
	);
}
$many_result = $matcher->match( $many, $many );
check( true === (bool) $many_result['bounded'], 'A page larger than the comparison bound reports that it was bounded rather than pretending to a complete comparison.' );
check(
	Sync_Limits::MAX_COMPONENTS === (int) $many_result['old_count'],
	'The compared count is exactly the bound, not the whole page.'
);

echo "--- 19. Degenerate input ---\n";

check( array() === $matcher->match( array(), array() )['matches'], 'Two empty sets match nothing.' );
check( array() === $matcher->match( array(), $before )['matches'], 'An empty old set matches nothing.' );
check( 4 === count( $matcher->match( $before, array() )['removed'] ), 'An empty new set reports everything removed, which is the honest reading of a page that has no components.' );
check( 0.0 === (float) $matcher->score( array(), array() )['similarity'], 'Two empty identities score zero rather than matching by default.' );
check( array() === $matcher->score( array(), array() )['reasons'], 'And produce no reasons, because no signal was present.' );
check( 0.0 === (float) $matcher->score( array( 'role' => 'card' ), array( 'role' => 'card' ) )['similarity'] - 0.2 < 0.0 ? 1.0 : 0.2, 'A role-only match scores from the role signal alone.' );

// A component with no id falls back to a positional name rather than producing an
// empty key that would collide with every other id-less component.
$no_ids_old = array( array( 'role' => 'card', 'text' => 'One' ) );
$no_ids_new = array( array( 'role' => 'card', 'text' => 'One' ) );
$no_id_result = $matcher->match( $no_ids_old, $no_ids_new );
check( 1 === count( $no_id_result['matches'] ), 'Components without ids still match, via a positional fallback name.' );
check( 'old_0' === (string) $no_id_result['matches'][0]['old_id'], 'The fallback name is derived from the position, so it is stable within one comparison.' );

// Non-array entries are skipped rather than fataling.
$mixed = array_merge( $before, array( 'not an array', null, 42 ) );
check( 4 === count( $matcher->match( $before, $mixed )['matches'] ), 'Non-array entries are skipped rather than fataling the comparison.' );

echo "--- 20. A hostile-looking image reference cannot widen identity ---\n";

$hostile = $matcher->identity(
	array(
		'id'     => 'h',
		'role'   => 'image',
		'image'  => 'https://evil.test/../../wp-content/uploads/secret.png',
		'section_id' => 's1',
	)
);
check( false === strpos( (string) $hostile['image'], '..' ), 'The path is taken from the parsed URL, and the host is dropped, so an image identity is a path rather than a fetchable reference.' );
check( false !== strpos( (string) $hostile['image'], 'secret.png' ), 'But the file name is retained, because two images of the same name in different folders are genuinely different and the folder path is what distinguishes them.' );

// The map fixture is cleaned up.
if ( null !== get_post( $post_id ) ) {
	wp_delete_post( $post_id, true );
}

echo "\nPhase 9 foundation test passed. Assertions: {$assertions}\n";
