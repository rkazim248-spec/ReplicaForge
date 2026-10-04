<?php
/**
 * Phase 9: conflict detection between the source and the user's own edits.
 *
 * The central test is the §20 scenario: ReplicaForge generated 48px, the user changed
 * it to 56px, the source now says 52px. Writing either value discards something, so
 * the answer has to be "ask", and it has to be derived from the three values rather
 * than from an assumption about which side wins.
 *
 * Run: php phase9-conflict-test.php <wp-root>
 */
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php phase9-conflict-test.php <wp-root>\n" );
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

use ReplicaForge\Sync_Conflict_Detector;
use ReplicaForge\Correction_Snapshot;
use ReplicaForge\Elementor_Document_Reader;
use ReplicaForge\Elementor_Map;

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
 * Build a draft holding a heading widget.
 *
 * The document is written the way Elementor stores it, so the reader is exercised
 * against real stored structure rather than a fixture array.
 *
 * @param string $heading_id Element id of the heading.
 * @param string $font_size  Stored font size, or an empty string for no size.
 * @param string $paragraph_id Element id of the paragraph.
 * @return int Post identifier.
 */
function make_draft( $heading_id, $font_size, $paragraph_id = 'd4e5f60' ) {
	$heading = array(
		'id'       => $heading_id,
		'elType'   => 'widget',
		'widgetType' => 'heading',
		'settings' => array( 'title' => 'Build faster' ),
		'elements' => array(),
	);
	if ( '' !== $font_size ) {
		$heading['settings']['typography_font_size'] = $font_size;
	}

	$post_id = wp_insert_post(
		array(
			'post_title'  => 'ReplicaForge conflict fixture',
			'post_status' => 'draft',
			'post_type'   => 'page',
		)
	);

	// The reader refuses a document that is not a ReplicaForge draft, so the
	// fixture carries the generation id Phase 4 writes. This is the guard that keeps
	// the synchronisation path from ever addressing a page ReplicaForge did not
	// generate.
	update_post_meta( $post_id, 'replicaforge_generation_id', 'gen_fixture' );
	update_post_meta( $post_id, 'replicaforge_generation_hash', hash( 'sha256', 'fixture' ) );
	update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );

	update_post_meta(
		$post_id,
		'_elementor_data',
		wp_slash(
			wp_json_encode(
				array(
					array(
						'id'       => 'a1b2c3d',
						'elType'   => 'container',
						'settings' => array( 'content_width' => '1200' ),
						'elements' => array(
							array(
								'id'       => 'b2c3d4e',
								'elType'   => 'container',
								'settings' => array(),
								'elements' => array(
									array_merge( $heading, array( 'id' => $heading_id ) ),
									array(
										'id'         => $paragraph_id,
										'elType'     => 'widget',
										'widgetType' => 'text-editor',
										'settings'   => array( 'editor' => 'Body copy.', 'typography_font_size' => '16' ),
										'elements'   => array(),
									),
									// A widget with a real value that ReplicaForge never
									// recorded, which is what a property a person added in
									// the editor looks like to the conflict detector. The
									// unknown case needs a property that exists in the
									// document, otherwise it passes for the wrong reason:
									// a missing element is also unknown.
									array(
										'id'         => 'e5f6071',
										'elType'     => 'widget',
										'widgetType' => 'button',
										'settings'   => array( 'text' => 'Get started', 'typography_font_size' => '14' ),
										'elements'   => array(),
									),
								),
							),
						),
					),
				)
			)
		)
	);

	return $post_id;
}

$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( empty( $administrators ) ) {
	throw new RuntimeException( 'SKIP: an administrator user is required, because the document reader enforces the capability check the REST layer also applies.' );
}
wp_set_current_user( (int) $administrators[0] );

$snapshots = new Correction_Snapshot();
$detector  = new Sync_Conflict_Detector( $snapshots );

echo "--- 1. The §20 scenario, end to end ---\n";

// ReplicaForge generated a heading at 48px.
$post_id = make_draft( 'c3d4e5f', '48' );
$reader  = new Elementor_Document_Reader();
$reader->load( $post_id );

$heading_key = $snapshots->state_key( 'c3d4e5f', 'font_size', 'desktop' );
$paragraph_key = $snapshots->state_key( 'd4e5f60', 'font_size', 'desktop' );
$snapshots->record_written(
	$post_id,
	array(
		array( 'property_key' => $heading_key, 'written_value' => 48 ),
		array( 'property_key' => $paragraph_key, 'written_value' => 16 ),
	)
);
$reader = new Elementor_Document_Reader();
$reader->load( $post_id );

// The user opened the draft and changed it to 56px by hand.
$document = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
$document[0]['elements'][0]['elements'][0]['settings']['typography_font_size'] = '56';
update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $document ) ) );

$reader = new Elementor_Document_Reader();
$reader->load( $post_id );

$baseline_check = $snapshots->baseline_value( $post_id, 'c3d4e5f', 'font_size', 'desktop' );
check( $baseline_check['recorded'], 'The generated baseline is recorded, so there is something to compare the user against.' );
check( 48.0 === (float) $baseline_check['value'], 'The baseline is the value ReplicaForge wrote.' );

$current_check = $snapshots->current_value( $reader, 'c3d4e5f', 'font_size', 'desktop' );
check( '56' === (string) $current_check, 'The document holds the value the user set.' );

$manual = $snapshots->manual_change( $reader, $post_id, 'c3d4e5f', 'font_size', 'desktop' );
check( true === $manual['modified'], 'The manual change is detected.' );

// The source now says 52px.
$result = $detector->resolve( $reader, $post_id, 'c3d4e5f', 'font_size', 52 );

check( 48.0 === (float) $result['generated_value'], 'The resolution reports the generated baseline.' );
check( '56' === (string) $result['current_value'], 'It reports the current replica value.' );
check( 52.0 === (float) $result['source_value'], 'It reports the new source value.' );
check( true === (bool) $result['source_changed'], 'It recognises that the source changed.' );
check( true === (bool) $result['user_changed'], 'It recognises that the replica changed.' );
check( 'both_changed' === (string) $result['conflict'], 'The state is both_changed.' );
check( 'mixed' === (string) $result['ownership'], 'The ownership is mixed, which is the fourth state the brief names.' );
check( false === (bool) $result['writable'], 'The property is not writable, so nothing is written over the user.' );
check( 'review' === (string) $result['recommendation'], 'The recommendation is to review, not to pick a side.' );
check( false !== strpos( (string) $result['reason'], 'discard' ), 'The reason says that writing either value would discard something.' );

echo "--- 2. All four resolved states, plus the two easy-to-miss cases ---\n";

// The heading cannot demonstrate source_only or no_conflict: the user has already
// changed it, so any source value makes it both_changed. Those cases need a property
// the user has not touched, which is exactly the situation a safe automatic update
// acts on, so they are exercised on the paragraph.
$reader = new Elementor_Document_Reader();
$reader->load( $post_id );

$untouched = $detector->resolve( $reader, $post_id, 'd4e5f60', 'font_size', 16 );
check( 'no_conflict' === (string) $untouched['conflict'], 'An untouched property whose source has not moved is in no_conflict.' );
check( false === (bool) $untouched['writable'], 'And there is nothing to write.' );

// source_only: the source moved and the replica still holds what was generated.
$source_only = $detector->resolve( $reader, $post_id, 'd4e5f60', 'font_size', 18 );
check( 'source_only' === (string) $source_only['conflict'], 'When only the source changed, the state is source_only.' );
check( true === (bool) $source_only['writable'], 'And the property is writable, because nothing would be lost.' );
check( 'apply' === (string) $source_only['recommendation'], 'The recommendation is to apply.' );
check( 'source_controlled' === (string) $source_only['ownership'], 'The ownership is source_controlled.' );

// user_only: the user moved the replica and the source has not. The paragraph is
// edited the same way the heading was, so the same three values can be read.
$edited = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
$edited[0]['elements'][0]['elements'][1]['settings']['typography_font_size'] = '20';
update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $edited ) ) );

$reader = new Elementor_Document_Reader();
$reader->load( $post_id );

$user_only = $detector->resolve( $reader, $post_id, 'd4e5f60', 'font_size', 16 );
check( 'user_only' === (string) $user_only['conflict'], 'When only the replica changed, the state is user_only.' );
check( false === (bool) $user_only['writable'], 'And it is not writable, so the source is not applied over the user.' );
check( 'skip' === (string) $user_only['recommendation'], 'The recommendation is to skip.' );
check( 'user_controlled' === (string) $user_only['ownership'], 'The ownership is user_controlled.' );

// A genuine conflict on a second property, which proves the three-way read is taken
// from the live document rather than from a value cached during the first resolve.
$paragraph_conflict = $detector->resolve( $reader, $post_id, 'd4e5f60', 'font_size', 22 );
check( 'both_changed' === (string) $paragraph_conflict['conflict'], 'The same three-way read on a second property reaches both_changed independently.' );

// Put the paragraph back so the batch expectations below stay meaningful.
$restored = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
$restored[0]['elements'][0]['elements'][1]['settings']['typography_font_size'] = '16';
update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $restored ) ) );

$reader = new Elementor_Document_Reader();
$reader->load( $post_id );

// The case that is easy to get wrong: the user and the source arrived at the same
// value independently. There is nothing to reconcile.
$agreed = $detector->resolve( $reader, $post_id, 'c3d4e5f', 'font_size', 56 );
check( 'no_conflict' === (string) $agreed['conflict'], 'When the source and the replica already agree, there is no conflict, because applying the source would change nothing.' );
check( false === (bool) $agreed['writable'], 'And nothing is written.' );
check( false !== strpos( (string) $agreed['reason'], 'nothing' ), 'The reason says so, rather than reporting a conflict a person would have to dismiss.' );
check( 'mixed' === (string) $agreed['ownership'], 'The ownership is still mixed, because both sides did change; only the outcome differs.' );

echo "--- 3. Unknown is not permission ---\n";

$unwritten = $detector->resolve( $reader, $post_id, 'e5f6071', 'font_size', 20 );
check( 'unknown' === (string) $unwritten['conflict'], 'A property ReplicaForge never wrote is unknown.' );
check( 'unknown' === (string) $unwritten['ownership'], 'And its ownership is unknown rather than source_controlled.' );
check( false === (bool) $unwritten['writable'], 'It is not writable, because ReplicaForge has no standing to claim the source owns it.' );
check( false === (bool) $unwritten['has_baseline'], 'It reports that no baseline exists, rather than reporting a null baseline as if it were one.' );
check( false !== strpos( (string) $unwritten['reason'], 'no recorded value' ), 'The reason names the absent baseline, which distinguishes this from a property whose element is missing from the document.' );
check( false === strpos( (string) $unwritten['reason'], 'not in the document' ), 'And it is explicitly not the missing-element case, so the test cannot pass for the wrong reason.' );

echo "--- 4. The property whitelist is checked before anything is compared ---\n";

$not_writable = $detector->resolve( $reader, $post_id, 'c3d4e5f', 'not_a_real_property', 10 );
check( 'unknown' === (string) $not_writable['conflict'], 'A property outside the whitelist is unknown.' );
check( false === (bool) $not_writable['writable'], 'It is not writable.' );
check( false !== strpos( (string) $not_writable['reason'], 'whitelist' ), 'The reason names the whitelist, so the failure is legible.' );

// The brief's §61: the backend decides what may be written, never the caller.
$scriptish = $detector->resolve( $reader, $post_id, 'c3d4e5f', '../../evil', 10 );
check( false === (bool) $scriptish['writable'], 'A property name shaped like a traversal is refused.' );

echo "--- 5. Missing elements and missing posts ---\n";

$missing_element = $detector->resolve( $reader, $post_id, 'does_not_exist', 'font_size', 10 );
check( false === (bool) $missing_element['writable'], 'An element that is not in the document is not writable.' );
check( false !== strpos( (string) $missing_element['reason'], 'not in the document' ), 'The reason says the element is absent.' );

$missing_post = $detector->resolve( $reader, 0, 'c3d4e5f', 'font_size', 10 );
check( false === (bool) $missing_post['writable'], 'A missing post is not writable.' );

$empty_element = $detector->resolve( $reader, $post_id, '', 'font_size', 10 );
check( false === (bool) $empty_element['writable'], 'An empty element id is not writable.' );

echo "--- 6. Value comparison across types ---\n";

// A control read from a document is a string; a source value is a number. Comparing
// them with === would report every unchanged property as changed.
$snapshots->record_written(
	$post_id,
	array(
		array( 'element_id' => 'd4e5f60', 'property' => 'font_size', 'device' => 'desktop', 'value' => 16 ),
	)
);
$reader = new Elementor_Document_Reader();
$reader->load( $post_id );
$typed = $detector->resolve( $reader, $post_id, 'd4e5f60', 'font_size', 16 );
check( 'no_conflict' === (string) $typed['conflict'], 'A numeric source value of 16 and a stored string of 16 are the same value, so this is not reported as a change.' );
check( false === (bool) $typed['source_changed'], 'And the source is not reported as changed.' );

// A null and a real value are not the same, which PHP's loose comparison would
// get wrong in the other direction: null == "0" is true.
//
// A recorded baseline is required for the comparison to run at all, because
// without one the unknown path fires first and this never reaches the check.
$color_key   = $snapshots->state_key( 'c3d4e5f', 'text_color', 'desktop' );
$snapshots->record_written( $post_id, array( array( 'property_key' => $color_key, 'written_value' => '#111111' ) ) );
$reader = new Elementor_Document_Reader();
$reader->load( $post_id );

$null_check = $detector->resolve( $reader, $post_id, 'c3d4e5f', 'text_color', null );
check( 'no_conflict' === (string) $null_check['conflict'], 'A null source value against a recorded baseline is not reported as agreeing, because the replica holds no colour either.' );
check( false === (bool) $null_check['writable'], 'And nothing is written, because there is no value to write.' );
check( false === strpos( (string) $null_check['reason'], 'already hold the same value' ), 'The reason does not claim the two agree, because two absent values are an absence rather than an agreement.' );
check( false !== strpos( (string) $null_check['reason'], 'nothing to write' ), 'It says there is nothing to write, which is the true statement.' );
check( true === (bool) $null_check['source_changed'], 'And the source is still recorded as having changed, because a recorded baseline is not what the source now says.' );

echo "--- 7. Resolving a batch ---\n";

$batch = $detector->resolve_all(
	$reader,
	$post_id,
	array(
		array( 'id' => 'c1', 'elementor_element_id' => 'c3d4e5f', 'property' => 'font_size', 'source_value' => 60, 'category' => 'typography' ),
		array( 'id' => 'c2', 'elementor_element_id' => 'c3d4e5f', 'property' => 'font_size', 'source_value' => 48, 'category' => 'typography' ),
		array( 'id' => 'c3', 'elementor_element_id' => 'd4e5f60', 'property' => 'font_size', 'source_value' => 18, 'category' => 'typography' ),
		array( 'id' => 'c4', 'elementor_element_id' => 'nope', 'property' => 'font_size', 'source_value' => 20, 'category' => 'typography' ),
		array( 'id' => 'c5', 'elementor_element_id' => 'c3d4e5f', 'property' => 'bogus_property', 'source_value' => 1, 'category' => 'typography' ),
		'not an array',
	)
);

check( 5 === count( $batch['resolved'] ), 'Every array change is resolved, and a non-array is skipped.' );
check( 1 === (int) $batch['writable'], 'Exactly one change is writable: the source-only heading change.' );
check( 1 === (int) $batch['conflicted'], 'One change is a genuine conflict.' );
check( 2 === (int) $batch['unknown'], 'Two changes are unknown: the absent element and the unwritable property.' );
check( 1 === (int) $batch['counts']['source_only'], 'The source-only count is right.' );
check( 1 === (int) $batch['counts']['user_only'], 'The user-only count is right.' );
check( 1 === (int) $batch['counts']['both_changed'], 'The conflict count is right.' );
check( 4 === (int) $batch['needs_review'], 'The review count is the writable change plus the conflict plus both unknowns, so every item that needs a decision is counted.' );
check( 5 === (int) $batch['writable'] + (int) $batch['conflicted'] + (int) $batch['unknown'] + (int) $batch['counts']['user_only'], 'Every resolved change falls into exactly one of writable, conflicted, unknown, or the user having already decided.' );

echo "--- 8. The gate, and why a conflict does not block the safe work ---\n";

$gate = $detector->gate( $batch['resolved'] );
check( false === (bool) $gate['clear'], 'A batch containing a conflict does not pass the gate.' );
check( 3 === (int) $gate['blocked_count'], 'Three items are blocked: one conflict and two unknowns.' );
check( true === (bool) $gate['requires_review'], 'The batch is marked as requiring review.' );

$safe_index = null;
foreach ( $batch['resolved'] as $index => $entry ) {
	if ( ! empty( $entry['writable'] ) ) {
		$safe_index = $index;
		break;
	}
}
check( null !== $safe_index, 'Exactly one change in the batch is writable.' );

$clean_gate = $detector->gate( array( $batch['resolved'][ $safe_index ] ) );
check( true === (bool) $clean_gate['clear'], 'A batch of one safe change passes the gate.' );
check( 0 === (int) $clean_gate['blocked_count'], 'And nothing in it is blocked.' );

$conflict_gate = $detector->gate( array( $batch['resolved'][0] ) );
check( false === (bool) $conflict_gate['clear'], 'A batch of one conflict does not pass the gate.' );

echo "--- 9. The review screen summary ---\n";

$summary = $detector->summary( $batch['resolved'] );
check( 1 === (int) $summary['buckets']['safe'], 'One change is safe.' );
check( 1 === (int) $summary['buckets']['conflict'], 'One is a conflict.' );
check( 1 === (int) $summary['buckets']['review'], 'The unknown whose element is missing is counted as reviewable, because a person can decide to add the element or skip it.' );
check( 1 === (int) $summary['buckets']['blocked'], 'The unknown whose property is off the whitelist is counted as blocked, because no decision a person makes can make it writable.' );
check( false !== strpos( (string) $summary['headline'], 'conflict' ), 'The headline names the conflict, because that is the thing needing a person.' );
check( 'both_changed' === $detector->blocking_state( $batch['resolved'] ), 'The blocking state is the conflict, which outranks an unknown.' );

$all_clean = $detector->summary( array( $batch['resolved'][1] ) );
check( 'No changes need a decision.' === (string) $all_clean['headline'], 'A batch of a user-only change produces the honest headline, rather than an empty or invented one.' );

echo "--- 10. The map and the conflict detector agree on the element ---\n";

$map = new Elementor_Map( $post_id );
$map->set(
	array(
		'source_component_id'  => 'hero_paragraph',
		'elementor_element_id' => 'd4e5f60',
		'source_section_id'    => 'section_hero',
		'properties'           => array( 'font_size' ),
	)
);
$map->set(
	array(
		'source_component_id'  => 'hero_heading',
		'elementor_element_id' => 'c3d4e5f',
		'source_section_id'    => 'section_hero',
		'properties'           => array( 'font_size' ),
	)
);
check( 'd4e5f60' === $map->element_for( 'hero_paragraph' ), 'The map resolves the source component to the element.' );
check( 'c3d4e5f' === $map->element_for( 'hero_heading' ), 'And resolves a second component independently.' );

// An Elementor element id is exactly seven lowercase hex characters, so a
// mapping to anything else would address an element that cannot exist.
check( ! $map->set( array( 'source_component_id' => 'bad', 'elementor_element_id' => 'nothexid' ) ), 'A mapping to an element id in the wrong format is refused at write time.' );
check( ! $map->set( array( 'source_component_id' => 'bad', 'elementor_element_id' => 'a1b2c3' ) ), 'A mapping to a six-character id is refused, because Elementor ids are seven.' );
$upper = $map->set( array( 'source_component_id' => 'shouty', 'elementor_element_id' => 'A1B2C3D' ) );
check( true === $upper, 'An upper-case element id is normalized rather than refused, because refusing it would help nobody and missing a lookup over one would.' );
check( 'a1b2c3d' === $map->element_for( 'SHOUTY' ), 'The stored id is lowercase, and the source id is matched case-insensitively, so both ends of the lookup resolve.' );

$mapped_change = array(
	'id'                   => 'c1',
	'source_component_id'  => 'hero_paragraph',
	'elementor_element_id' => $map->element_for( 'hero_paragraph' ),
	'property'             => 'font_size',
	'source_value'         => 18,
	'category'             => 'typography',
);
$mapped_batch = $detector->resolve_all( $reader, $post_id, array( $mapped_change ) );
check( 1 === (int) $mapped_batch['writable'], 'A change routed through the map resolves to the same writable result as one addressed directly.' );
check( 'hero_paragraph' === (string) $mapped_batch['resolved'][0]['source_component_id'], 'The resolution carries the source component through, so a review can name the thing rather than the address.' );

// A change routed to the heading resolves to a conflict, because the user
// changed it. The map does not make a conflict safe.
$mapped_conflict = $detector->resolve_all(
	$reader,
	$post_id,
	array( array( 'id' => 'c2', 'source_component_id' => 'hero_heading', 'elementor_element_id' => $map->element_for( 'hero_heading' ), 'property' => 'font_size', 'source_value' => 60 ) )
);
check( 0 === (int) $mapped_conflict['writable'], 'A change mapped to an element the user edited is still blocked.' );
check( 1 === (int) $mapped_conflict['conflicted'], 'And it is still reported as a conflict.' );

// A change addressed to an unmapped component cannot be resolved at all, which is
// what makes map coverage the precondition for an incremental update.
$unmapped = $detector->resolve_all(
	$reader,
	$post_id,
	array( array( 'id' => 'c9', 'source_component_id' => 'never_mapped', 'elementor_element_id' => '', 'property' => 'font_size', 'source_value' => 10 ) )
);
check( 0 === (int) $unmapped['writable'], 'A change with no resolved element is not writable.' );
check( 1 === (int) $unmapped['unknown'], 'And is reported as unknown rather than silently dropped.' );

echo "--- 11. A manual edit survives a full resolution pass ---\n";

$reader = new Elementor_Document_Reader();
$reader->load( $post_id );
$final = $detector->resolve( $reader, $post_id, 'c3d4e5f', 'font_size', 52 );
$stored = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
$after  = $stored[0]['elements'][0]['elements'][0]['settings']['typography_font_size'];

check( '56' === (string) $after, 'Resolving a conflict does not write anything, so the user value is still in the document.' );
check( false === (bool) $final['writable'], 'And the resolution still reports it as not writable.' );

echo "--- 12. Device keys are normalised ---\n";

$desktop = $detector->resolve( $reader, $post_id, 'c3d4e5f', 'font_size', 60, 'desktop' );
$default = $detector->resolve( $reader, $post_id, 'c3d4e5f', 'font_size', 60, '' );
check( 'desktop' === (string) $desktop['device'], 'A device key is normalised to a known device.' );
check( 'desktop' === (string) $default['device'], 'An empty device key defaults to desktop rather than producing an unmatched state key.' );

// The fixtures are cleaned up.
if ( null !== get_post( $post_id ) ) {
	wp_delete_post( $post_id, true );
}

echo "\nPhase 9 conflict detection test passed. Assertions: {$assertions}\n";
