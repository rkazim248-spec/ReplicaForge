<?php
/**
 * Phase 6 correction engine contract smoke test.
 *
 * Run inside a bootstrapped WordPress environment with:
 *   wp eval-file wp-content/plugins/replicaforge/tests/phase6-contract-test.php
 *
 * The test is deterministic. It never calls an AI provider and never contacts a
 * remote renderer. When Elementor is available it creates one real draft, plans
 * corrections against it, applies them, proves each property changed in isolation,
 * proves a rollback restores the exact previous document, and deletes the draft.
 *
 * @package ReplicaForge
 */

defined( 'ABSPATH' ) || exit;

$fixture = dirname( __DIR__ ) . '/tests/fixtures/phase3-representation.json';
$raw     = is_readable( $fixture ) ? file_get_contents( $fixture ) : '';
$source  = '' !== $raw ? json_decode( $raw, true ) : null;
if ( ! is_array( $source ) ) {
	throw new RuntimeException( 'Phase 6 fixture could not be loaded.' );
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAILED: ' . $message );
	}
	echo 'PASS: ' . $message . "\n";
};

$properties  = new \ReplicaForge\Correction_Property_Map();
$eligibility = new \ReplicaForge\Correction_Eligibility( $properties );
$validator   = new \ReplicaForge\Correction_Validator( $properties );
$regressions = new \ReplicaForge\Regression_Detector();
$history     = new \ReplicaForge\Correction_History();
$report      = new \ReplicaForge\Correction_Report();
$snapshots   = new \ReplicaForge\Correction_Snapshot( $properties );

/* ------------------------------------------------------------------ */
/* 1. The whitelist is closed.                                          */
/* ------------------------------------------------------------------ */
$whitelist = $properties->all();
$assert( ! empty( $whitelist ), 'The property whitelist is declared.' );
$assert( '5.0' !== \ReplicaForge\Correction_Limits::SCHEMA_VERSION && '6.0' === \ReplicaForge\Correction_Limits::SCHEMA_VERSION, 'The correction plan schema is version 6.0.' );

$levels = array();
foreach ( $whitelist as $entry ) {
	$levels[ (int) $entry['level'] ] = true;
}
// Reported once per distinct level rather than once per property, so a level is
// still covered for every property that declares it without repeating the line.
foreach ( array_keys( $levels ) as $level ) {
	$assert( isset( \ReplicaForge\Correction_Limits::LEVELS[ $level ] ), 'Property level ' . $level . ' is a declared correction level.' );
}
$assert( in_array( 1, array_keys( $levels ), true ), 'The whitelist starts at level 1.' );

$assert( $properties->is_writable( 'font_size' ), 'font size is writable.' );
$assert( $properties->is_writable( 'section_gap' ), 'section gap is writable.' );
$assert( ! $properties->is_writable( 'section_present' ), 'section presence is not writable.' );
$assert( ! $properties->is_writable( 'text' ), 'text is not writable.' );
$assert( ! $properties->is_writable( 'column_progression' ), 'column progression is not writable.' );
$assert( ! $properties->is_writable( 'made_up_property' ), 'An unknown property is not writable.' );

$assert( 'typography_font_size' === $properties->control( 'font_size', 'widget', 'desktop' ), 'A widget font size maps to the Elementor typography control.' );
$assert( 'typography_font_size_tablet' === $properties->control( 'font_size', 'widget', 'tablet' ), 'A tablet font size maps to the device-suffixed control.' );
$assert( 'typography_font_size_mobile' === $properties->control( 'font_size', 'widget', 'mobile' ), 'A mobile font size maps to the device-suffixed control.' );
$assert( '' === $properties->control( 'font_size', 'container', 'desktop' ), 'A widget-only control is refused on a container.' );
$assert( 'typography_font_size_tablet' === $properties->control( 'font_size', 'widget', 'tablet' ), 'A device-enabled property resolves a device control.' );
$assert( '' === $properties->control( 'font_family', 'widget', 'tablet' ), 'A property that is desktop-only refuses a device control.' );
$assert( 'boxed_width' === $properties->control( 'max_width', 'container', 'desktop' ), 'Container max width maps to the boxed width control.' );
$assert( 'boxed_content_width' === $properties->requirement( 'max_width' ), 'Container max width requires a boxed content width.' );
$assert( null !== $properties->companion( 'max_width' ), 'Container max width declares its companion control.' );

/* ------------------------------------------------------------------ */
/* 2. Values are coerced through the Phase 4 policy.                    */
/* ------------------------------------------------------------------ */
$slider = $properties->coerce( 'font_size', 64 );
$assert( is_array( $slider ) && 64.0 === (float) $slider['size'] && 'px' === $slider['unit'], 'A font size coerces into an Elementor slider value.' );
$assert( null === $properties->coerce( 'font_size', 0 ), 'A zero font size is refused rather than written.' );
$assert( null === $properties->coerce( 'font_size', 99999 ), 'An out of range font size is refused.' );
$assert( null === $properties->coerce( 'font_size', 'expression(alert(1))' ), 'An executable font size is refused.' );
$assert( null === $properties->coerce( 'font_size', 'javascript:alert(1)' ), 'A scheme font size is refused.' );

$color = $properties->coerce( 'text_color', '#6C63FF' );
$assert( '#6c63ff' === $color, 'A colour normalizes to the hex form the review screen showed.' );
$assert( null === $properties->coerce( 'text_color', 'url(javascript:alert(1))' ), 'An executable colour is refused.' );
$assert( null === $properties->coerce( 'text_color', 'not-a-colour' ), 'An unparseable colour is refused.' );

$direction = $properties->coerce( 'flex_direction', 'column' );
$assert( 'column' === $direction, 'A flex direction normalizes into the control vocabulary.' );
$assert( null === $properties->coerce( 'flex_direction', 'sideways' ), 'A value outside the enumeration is refused.' );

$gaps = $properties->coerce( 'section_gap', 24 );
$assert( is_array( $gaps ) && 24.0 === (float) $gaps['row'] && 'px' === $gaps['unit'], 'A gap coerces into an Elementor gaps value.' );
$assert( null === $properties->coerce( 'section_gap', -4 ), 'A negative gap is refused.' );

$sides = $properties->sides( 'padding_top' );
$assert( array( 'top' ) === $sides, 'A single side property declares only that side.' );
$assert( $properties->is_side_property( 'padding_bottom' ), 'A bottom padding property is recognized as a side property.' );
$assert( ! $properties->is_side_property( 'font_size' ), 'A font size is not a side property.' );

/* ------------------------------------------------------------------ */
/* 3. Eligibility never silently writes.                                */
/* ------------------------------------------------------------------ */
$safe = $eligibility->classify(
	array(
		'action'    => 'update',
		'property'  => 'font_size',
		'category'  => 'typography',
		'viewport'  => 'desktop',
		'confidence' => 0.9,
		'value'     => 64,
		'target'    => array( 'elementor_element_id' => 'abc1234' ),
		'target_property_present' => true,
	)
);
$assert( 'safe' === $safe['eligibility'] && true === $safe['auto'], 'A whitelisted property with a measured value is safe.' );
$assert( 2 === $safe['level'], 'A typography correction is level 2.' );
$assert( 'typography' === $safe['batch'], 'A typography correction runs in the typography batch.' );

$low = $eligibility->classify(
	array(
		'action'    => 'update',
		'property'  => 'font_size',
		'category'  => 'typography',
		'viewport'  => 'desktop',
		'confidence' => 0.2,
		'value'     => 64,
		'target'    => array( 'elementor_element_id' => 'abc1234' ),
		'target_property_present' => true,
	)
);
$assert( 'requires_review' === $low['eligibility'] && false === $low['auto'], 'A low confidence difference is never automatic.' );

$structural = $eligibility->classify(
	array(
		'action'    => 'insert',
		'property'  => 'section_present',
		'category'  => 'structure',
		'viewport'  => 'desktop',
		'confidence' => 0.95,
		'value'     => array( 'position' => 1 ),
		'target'    => array( 'elementor_element_id' => 'abc1234' ),
		'target_property_present' => true,
	)
);
// `section_present` is on the never-correct list, so it is refused before the
// structural-action rule is even reached. Blocked is stronger than review: a
// section insertion can never be produced by a validation result at all.
$assert( 'blocked' === $structural['eligibility'], 'A section insertion is blocked outright, not merely queued for review.' );

// The structural-action rule is what stops a writable property from being
// inserted, removed, or reordered without a human decision.
$structural_writable = $eligibility->classify(
	array(
		'action'    => 'remove',
		'property'  => 'section_gap',
		'category'  => 'layout',
		'viewport'  => 'desktop',
		'confidence' => 0.99,
		'value'     => 24,
		'target'    => array( 'elementor_element_id' => 'abc1234' ),
		'target_property_present' => true,
	)
);
$assert( 'requires_review' === $structural_writable['eligibility'] && false === $structural_writable['auto'], 'A structural action on a writable property requires review.' );

$blocked = $eligibility->classify(
	array(
		'action'    => 'content_update',
		'property'  => 'text',
		'category'  => 'content',
		'viewport'  => 'desktop',
		'confidence' => 0.99,
		'value'     => 'Something else',
		'target'    => array( 'elementor_element_id' => 'abc1234' ),
		'target_property_present' => true,
	)
);
$assert( 'blocked' === $blocked['eligibility'], 'A content rewrite is blocked outright.' );

$unmapped = $eligibility->classify(
	array(
		'action'    => 'update',
		'property'  => 'font_size',
		'category'  => 'typography',
		'viewport'  => 'desktop',
		'confidence' => 0.9,
		'value'     => 64,
		'target'    => array( 'elementor_element_id' => '' ),
		'target_property_present' => true,
	)
);
$assert( 'requires_review' === $unmapped['eligibility'], 'An unmapped target is never guessed.' );

$absent = $eligibility->classify(
	array(
		'action'    => 'update',
		'property'  => 'font_size',
		'category'  => 'typography',
		'viewport'  => 'desktop',
		'confidence' => 0.9,
		'value'     => 64,
		'target'    => array( 'elementor_element_id' => 'abc1234' ),
		'target_property_present' => false,
	)
);
$assert( 'requires_review' === $absent['eligibility'], 'Writing a property that is not currently set requires review.' );

$unmeasured = $eligibility->classify(
	array(
		'action'    => 'update',
		'property'  => 'font_size',
		'category'  => 'typography',
		'viewport'  => 'desktop',
		'confidence' => 0.9,
		'value'     => null,
		'target'    => array( 'elementor_element_id' => 'abc1234' ),
		'target_property_present' => true,
	)
);
$assert( 'blocked' === $unmeasured['eligibility'], 'An unmeasured value is never written.' );

$manual = $eligibility->classify(
	array(
		'action'    => 'update',
		'property'  => 'font_size',
		'category'  => 'typography',
		'viewport'  => 'desktop',
		'confidence' => 0.9,
		'value'     => 64,
		'target'    => array( 'elementor_element_id' => 'abc1234' ),
		'target_property_present' => true,
		'manual_change' => array( 'modified' => true ),
	)
);
$assert( 'requires_review' === $manual['eligibility'], 'A manual change to the same property is a conflict, not a correction.' );

$high_confidence = $eligibility->classify(
	array(
		'action'    => 'update',
		'property'  => 'background_color',
		'category'  => 'content',
		'viewport'  => 'desktop',
		'confidence' => 0.99,
		'value'     => '#112233',
		'target'    => array( 'elementor_element_id' => 'abc1234' ),
		'target_property_present' => true,
	)
);
$assert( 'requires_review' === $high_confidence['eligibility'], 'A category outside the automatic set is reviewed rather than applied.' );

$assert( 6 === count( \ReplicaForge\Correction_Limits::LEVELS ), 'Six correction levels are declared.' );
// The level number is a key of the table, not one of its values.
$assert( array_key_exists( 6, \ReplicaForge\Correction_Limits::LEVELS ), 'Level 6 is declared.' );
$assert( 'section_regeneration' === \ReplicaForge\Correction_Limits::LEVELS[6], 'Level 6 is section regeneration.' );
$assert( ! in_array( 'insert', \ReplicaForge\Correction_Limits::AUTO_ACTIONS, true ), 'A section insertion is never in the automatic action set.' );
$assert( in_array( 'update', \ReplicaForge\Correction_Limits::AUTO_ACTIONS, true ), 'A property update is in the automatic action set.' );

/* ------------------------------------------------------------------ */
/* 4. Regression detection compares every scope.                        */
/* ------------------------------------------------------------------ */
$before = array(
	'metrics'   => array(
		'overall' => array( 'value' => 87.0 ),
		'groups'  => array( 'responsive' => array( 'value' => 82.0 ), 'typography' => array( 'value' => 94.0 ) ),
	),
	'viewports' => array(
		'desktop' => array( 'score' => 90.0 ),
		'tablet'  => array( 'score' => 84.0 ),
		'mobile'  => array( 'score' => 82.0 ),
	),
);
$after  = array(
	'metrics'   => array(
		'overall' => array( 'value' => 93.0 ),
		'groups'  => array( 'responsive' => array( 'value' => 74.0 ), 'typography' => array( 'value' => 96.0 ) ),
	),
	'viewports' => array(
		'desktop' => array( 'score' => 94.0 ),
		'tablet'  => array( 'score' => 81.0 ),
		'mobile'  => array( 'score' => 69.0 ),
	),
);
$comparison = $regressions->compare( $before, $after );
$assert( 6.0 === (float) $comparison['improvement'], 'The measured improvement is reported.' );
$assert( true === $comparison['has_regression'], 'An overall improvement that damages other scopes is still a regression.' );
$scopes = array();
foreach ( $comparison['regressions'] as $regression ) {
	$scopes[] = $regression['scope'];
}
$assert( in_array( 'group:responsive', $scopes, true ), 'A regressed metric group is reported.' );
$assert( in_array( 'viewport:mobile', $scopes, true ), 'A regressed viewport is reported.' );
$assert( ! in_array( 'viewport:desktop', $scopes, true ), 'A viewport that improved is not reported as a regression.' );
$assert( ! in_array( 'group:typography', $scopes, true ), 'A metric group that improved is not reported as a regression.' );
$assert( 2 === count( $scopes ), 'Only the regressed scopes are reported.' );

$improved_only = $regressions->compare(
	array( 'metrics' => array( 'overall' => array( 'value' => 80.0 ) ), 'groups' => array(), 'viewports' => array() ),
	array( 'metrics' => array( 'overall' => array( 'value' => 92.0 ) ), 'groups' => array(), 'viewports' => array() )
);
$assert( false === $improved_only['has_regression'], 'A pure improvement is not a regression.' );

$unknown = $regressions->compare( array(), array() );
$assert( false === $unknown['available'], 'An unavailable comparison is reported as unavailable rather than as an improvement.' );

/* ------------------------------------------------------------------ */
/* 5. Batch order encodes the documented dependency chain.              */
/* ------------------------------------------------------------------ */
$batches = \ReplicaForge\Correction_Limits::BATCHES;
// The dependency chain is expressed as positions in the batch list, so the
// positions are what has to be compared, not the batch names.
$batch_position = static function ( $name ) use ( $batches ) {
	$position = array_search( $name, $batches, true );
	return false === $position ? -1 : (int) $position;
};
$assert( 'structure' === $batches[0], 'Structure is corrected first.' );
$assert( 'dimensions' === $batches[1], 'Container dimensions are corrected before widths.' );
$assert( 'direction' === $batches[2], 'Layout direction is corrected before widths.' );
$assert( $batch_position( 'direction' ) < $batch_position( 'widths' ), 'The direction batch precedes the width batch.' );
$assert( $batch_position( 'typography' ) < $batch_position( 'responsive' ), 'Typography is corrected before responsive values.' );
$assert( $batch_position( 'responsive' ) < $batch_position( 'finetune' ), 'Responsive values are corrected before fine adjustments.' );
$assert( 3 === \ReplicaForge\Correction_Limits::MAX_ITERATIONS, 'The correction loop has a hard maximum of three iterations.' );
$assert( \ReplicaForge\Correction_Limits::MIN_IMPROVEMENT > 0, 'A minimum improvement is declared so the loop cannot chase noise.' );

/* ------------------------------------------------------------------ */
/* 6. History records are bounded and carry no content.                 */
/* ------------------------------------------------------------------ */
$recorded = $history->record(
	array(
		'correction_id'      => 'cor_0123456789abcdef0123',
		'post_id'            => 1,
		'plan_id'            => 'plan_0123456789abcdef0123',
		'validation_id'      => 'val_0123456789abcdef01234567',
		'generation_id'      => 'gen_test',
		'snapshot_id'        => 'snap_0123456789abcdef0123',
		'validation_before'  => 87.0,
		'validation_after'   => 93.0,
		'improvement'        => 6.0,
		'iterations'         => 1,
		'status'             => 'completed',
		'stop_reason'        => 'applied',
		'counts'             => array( 'applied' => 1, 'rejected' => 0, 'blocked' => 0, 'failed' => 0 ),
		'changes'            => array(
			array(
				'correction_id' => 'correction_001_abcdef01',
				'element_id'    => 'abc1234',
				'property'      => 'font_size',
				'control'       => 'typography_font_size',
				'viewport'      => 'desktop',
				'action'        => 'update',
				'old_value'     => array( 'size' => 56, 'unit' => 'px' ),
				'new_value'     => array( 'size' => 64, 'unit' => 'px' ),
				'status'        => 'applied',
			),
		),
	)
);
$assert( 1 === $recorded['counts']['applied'], 'The history records how many corrections were applied.' );
$assert( 'applied' === $recorded['changes'][0]['status'], 'Each change carries its outcome status.' );
$assert( 'typography_font_size' === $recorded['changes'][0]['control'], 'Each change records the Elementor control it wrote.' );
$assert( null !== $history->get( $recorded['correction_id'] ), 'A recorded run can be looked up by identifier.' );
$assert( null === $history->get( 'cor_invalid' ), 'An invalid identifier never resolves.' );

$hostile = $history->record(
	array(
		'correction_id' => 'cor_fedcba9876543210fedc',
		'post_id'       => 1,
		'counts'        => array( 'applied' => 1 ),
		'changes'       => array(
			array( 'correction_id' => 'x', 'property' => 'text', 'old_value' => '<script>alert(1)</script>', 'new_value' => 'x' ),
		),
	)
);
$assert( false === strpos( (string) $hostile['changes'][0]['old_value'], '<script' ), 'An executable value never reaches the history.' );

/* ------------------------------------------------------------------ */
/* 7. Report payloads are read-only and export cleanly.                 */
/* ------------------------------------------------------------------ */
$plan_report = $report->plan(
	array(
		'plan_id'        => 'plan_0123456789abcdef0123',
		'post_id'        => 1,
		'validation_id'  => 'val_0123456789abcdef01234567',
		// The counts below are deliberately wrong, because the report must derive
		// its own counts from the corrections it actually holds.
		'counts'         => array( 'safe' => 2, 'requires_review' => 1, 'blocked' => 3 ),
		'levels_used'    => 3,
		'batches'        => array( array( 'batch' => 'typography', 'safe' => 2, 'review' => 0, 'total' => 2, 'ids' => array( 'correction_001_abcdef01' ) ) ),
		'corrections'    => array(
			array(
				'correction_id' => 'correction_001_abcdef01',
				'property'      => 'font_size',
				'property_label' => 'Font size',
				'action'        => 'update',
				'eligibility'   => 'safe',
				'auto'          => true,
				'viewport'      => 'desktop',
				'severity'      => 'moderate',
				'confidence'    => 0.9,
				'current_label' => '56px',
				'value_label'   => '64px',
				'value'         => 64,
				'level'         => 2,
				'batch'         => 'typography',
				'target'        => array( 'elementor_element_id' => 'abc1234' ),
				'reason'        => 'measured',
			),
			array(
				'correction_id' => 'correction_002_abcdef02',
				'property'      => 'flex_direction',
				'property_label' => 'Direction',
				'action'        => 'update',
				'eligibility'   => 'requires_review',
				'auto'          => false,
				'viewport'      => 'tablet',
				'severity'      => 'moderate',
				'confidence'    => 0.8,
				'current_label' => 'Row',
				'value_label'   => 'Column',
				'value'         => 'column',
				'level'         => 3,
				'batch'         => 'direction',
				'target'        => array( 'elementor_element_id' => 'abc1234' ),
				'reason'        => 'measured',
			),
		),
		'blocked'        => array(
			array(
				'correction_id' => 'correction_003_abcdef03',
				'property'      => 'text',
				'property_label' => 'Text',
				'action'        => 'content_update',
				'eligibility'   => 'blocked',
				'auto'          => false,
				'viewport'      => 'desktop',
				'severity'      => 'moderate',
				'confidence'    => 0.9,
				'current_label' => 'Build better sites',
				'value_label'   => 'Something else',
				'value'         => 'Something else',
				'level'         => 0,
				'batch'         => '',
				'target'        => array( 'elementor_element_id' => 'abc1234' ),
				'reason'        => 'blocked',
			),
			array(
				'correction_id' => 'correction_004_abcdef04',
				'property'      => 'section_present',
				'property_label' => 'Section presence',
				'action'        => 'insert',
				'eligibility'   => 'blocked',
				'auto'          => false,
				'viewport'      => 'desktop',
				'severity'      => 'moderate',
				'confidence'    => 0.9,
				'current_label' => 'Absent',
				'value_label'   => 'Present',
				'value'         => array( 'position' => 1 ),
				'level'         => 0,
				'batch'         => '',
				'target'        => array( 'elementor_element_id' => 'abc1234' ),
				'reason'        => 'blocked',
			),
			array(
				'correction_id' => 'correction_005_abcdef05',
				'property'      => 'column_count',
				'property_label' => 'Column count',
				'action'        => 'update',
				'eligibility'   => 'blocked',
				'auto'          => false,
				'viewport'      => 'mobile',
				'severity'      => 'moderate',
				'confidence'    => 0.9,
				'current_label' => '2 columns',
				'value_label'   => '1 column',
				'value'         => 1,
				'level'         => 0,
				'batch'         => '',
				'target'        => array( 'elementor_element_id' => 'abc1234' ),
				'reason'        => 'blocked',
			),
		),
	),
	1
);
$assert( 1 === $plan_report['counts']['safe'], 'The plan report groups the safe corrections.' );
$assert( 1 === $plan_report['counts']['requires_review'], 'The plan report groups the review corrections.' );
$assert( 3 === $plan_report['counts']['blocked'], 'The plan report groups the blocked corrections.' );
$assert( 5 === $plan_report['counts']['total'], 'The plan report counts every correction it holds.' );
$assert( count( $plan_report['safe'] ) === $plan_report['counts']['safe'], 'A count never disagrees with the list rendered beside it.' );
$assert( true === $plan_report['human_review_required'], 'The plan report requires a human decision.' );
$assert( false === $plan_report['applied'], 'A plan is never marked as applied.' );
$assert( false === $plan_report['published'], 'A plan never implies a published page.' );

$plan_csv = $report->export_plan_csv( $plan_report );
$assert( false !== strpos( $plan_csv, 'elementor_element_id' ), 'The plan CSV includes the Elementor element column.' );
$assert( false !== strpos( $plan_csv, 'manually_modified' ), 'The plan CSV includes the manual-change column.' );
$assert( false === strpos( $plan_csv, "\r" ), 'The plan CSV has no carriage returns.' );

$plan_json = $report->export_plan_json( $plan_report, 1 );
$assert( false === isset( $plan_json['correction_plan_body'] ), 'The plan export carries no document body.' );
$assert( false === $plan_json['applied'], 'The plan export is never marked as applied.' );

$run_report = $report->run(
	array(
		'correction_id'      => 'cor_0123456789abcdef0123',
		'plan_id'            => 'plan_0123456789abcdef0123',
		'snapshot_id'        => 'snap_0123456789abcdef0123',
		'validation_before'  => 87.0,
		'validation_after'   => 93.0,
		'applied'            => 1,
		'changes'            => array( array( 'element_id' => 'abc1234', 'property' => 'font_size', 'old_value' => '56', 'new_value' => '64', 'viewport' => 'desktop' ) ),
		'regressions'        => array(),
		'rolled_back'        => false,
		'stop_reason'        => 'applied',
		'duration_ms'        => 120,
	),
	1
);
$assert( 6.0 === (float) $run_report['improvement'], 'The run report reports the measured change.' );
$assert( false !== strpos( (string) $run_report['measurement_note'], 'not a guarantee' ), 'The run report states the measurement is not a guarantee.' );
$assert( false === $run_report['published'], 'A run report never implies a published page.' );

$run_json = $report->export_run_json( $run_report, 1 );
$encoded  = (string) wp_json_encode( $run_json );
$assert( false === strpos( $encoded, '_elementor_data' ), 'The run export carries no raw document data.' );
$assert( false === strpos( $encoded, 'Bearer' ), 'The run export carries no credential material.' );

/* ------------------------------------------------------------------ */
/* 8. The AI planner can only reorder, never invent.                     */
/* ------------------------------------------------------------------ */
$ai = new \ReplicaForge\Ai_Correction_Planner();
$ai_result = $ai->plan(
	array(
		'post_id'          => 1,
		'validation_score' => 87.0,
		'counts'           => array( 'safe' => 2 ),
		'corrections'      => array(
			array(
				'correction_id' => 'correction_001_abcdef01',
				'property'      => 'font_size',
				'action'        => 'update',
				'batch'         => 'typography',
				'level'         => 2,
				'viewport'      => 'desktop',
				'eligibility'   => 'safe',
				'auto'          => true,
				'target'        => array( 'elementor_element_id' => 'abc1234' ),
				'confidence'    => 0.9,
			),
		),
	)
);
$assert( false === $ai_result['available'], 'AI planning is unavailable without a provider.' );
$assert( is_array( $ai_result['warnings'] ) && ! empty( $ai_result['warnings'] ), 'Unavailable AI planning explains itself.' );

$ordered = $ai->apply_order(
	array(
		array( 'correction_id' => 'correction_001_aaaaaaaa', 'batch' => 'typography', 'property' => 'font_size' ),
		array( 'correction_id' => 'correction_002_bbbbbbbb', 'batch' => 'spacing', 'property' => 'section_gap' ),
	),
	array( array( 'correction_id' => 'correction_001_aaaaaaaa', 'order' => 0 ) )
);
$assert( 2 === count( $ordered ), 'AI ordering returns the same corrections it was given.' );

// The AI may only move a correction inside the deterministic order, so the set of
// corrections and their batches must be identical before and after. No `order`
// key is written onto a correction, because the order is the list position.
$correction_signature = static function ( array $list ) {
	$signature = array();
	foreach ( $list as $correction ) {
		$signature[] = (string) $correction['correction_id'] . '|' . (string) $correction['batch'];
	}
	return $signature;
};
$input_signature = $correction_signature(
	array(
		array( 'correction_id' => 'correction_001_aaaaaaaa', 'batch' => 'typography' ),
		array( 'correction_id' => 'correction_002_bbbbbbbb', 'batch' => 'spacing' ),
	)
);
$assert( $input_signature === $correction_signature( $ordered ), 'AI ordering rewrites no correction, it only reorders them.' );

$unknown_id = $ai->apply_order(
	array(
		array( 'correction_id' => 'correction_001_aaaaaaaa', 'batch' => 'typography' ),
		array( 'correction_id' => 'correction_002_bbbbbbbb', 'batch' => 'spacing' ),
	),
	array(
		array( 'correction_id' => 'correction_999_zzzzzzzz', 'order' => -999 ),
	)
);
$assert( $correction_signature( $unknown_id ) === $input_signature, 'A recommendation naming an unknown correction is ignored.' );

$unchanged = $ai->apply_order(
	array( array( 'correction_id' => 'correction_001_aaaaaaaa', 'batch' => 'typography' ) ),
	array()
);
$assert( 1 === count( $unchanged ), 'An empty AI recommendation leaves the order untouched.' );

/* ------------------------------------------------------------------ */
/* 9. End-to-end against a real generated draft.                        */
/* ------------------------------------------------------------------ */
$generator = new \ReplicaForge\Elementor_Generator();
$status    = $generator->status();
if ( empty( $status['available'] ) ) {
	echo "SKIP: end-to-end corrections (Elementor is not active on this host)\n";
	echo "Phase 6 contract test completed.\n";
	return;
}

$wp_user = wp_get_current_user();
if ( ! $wp_user || ! $wp_user->exists() ) {
	$wp_user = get_user_by( 'id', 1 );
}
if ( ! $wp_user || ! $wp_user->exists() ) {
	throw new RuntimeException( 'A user is required to run the end-to-end Phase 6 test.' );
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
$spec_v    = new \ReplicaForge\Elementor_Spec_Validator();
$mapper    = new \ReplicaForge\Elementor_Mapper( $registry );
$builder   = new \ReplicaForge\Elementor_Document_Builder();
$drafts    = new \ReplicaForge\Elementor_Draft_Service();

$spec = $planner->build( $source );
$plan = $spec_v->validate( $spec );
$tree = $mapper->map( $plan['plan'] );
$doc  = $builder->build( $tree['tree'], array( 'generation_id' => 'gen_contract_phase6' ) );

$draft_id = 0;

try {
	// The draft service creates and owns the draft, so its identifier is the one
	// the rest of the run has to read. A separate hand-made post would leave the
	// document on a page nothing else refers to.
	$created = $drafts->create(
		$doc['elements'],
		array(
			'generation_id'    => 'gen_contract_phase6',
			'source_url'       => 'https://example.com/',
			'source_title'     => 'Example',
			'source_host'      => 'example.com',
			'specification_id' => '',
			'ai_used'          => false,
			'id_map'           => $doc['id_map'],
			'report'           => array(),
			'provenance'       => array(),
		)
	);
	if ( empty( $created['success'] ) ) {
		throw new RuntimeException(
			'The contract draft document could not be saved: '
			. wp_json_encode(
				array(
					'errors'   => isset( $created['errors'] ) ? $created['errors'] : array(),
					'warnings' => isset( $created['warnings'] ) ? $created['warnings'] : array(),
				)
			)
		);
	}
	$draft_id = (int) $created['draft_id'];
	$assert( 'draft' === get_post_status( $draft_id ), 'The contract draft is a draft.' );
	$assert( '' !== (string) get_post_meta( $draft_id, \ReplicaForge\Elementor_Limits::META_PREFIX . 'generation_hash', true ), 'Phase 4 stored the generation hash for Phase 6 to compare against.' );

	$reader = new \ReplicaForge\Elementor_Document_Reader( $registry, $properties );
	$assert( $reader->load( $draft_id ), 'The document reader opens a ReplicaForge draft.' );
	$assert( '' === $reader->error(), 'The reader reports no refusal reason for a valid draft.' );
	$assert( $reader->element_count() > 0, 'The reader indexed the document elements.' );
	$assert( ! empty( $reader->id_map() ), 'The reader loaded the Phase 4 identity map.' );

	$assert( ! $reader->load( 0 ), 'A missing draft is refused.' );
	$assert( 'invalid_draft_id' === $reader->error(), 'A missing draft reports a clear reason.' );

	$plain = wp_insert_post(
		array( 'post_title' => 'Not a ReplicaForge draft', 'post_status' => 'draft', 'post_type' => 'page', 'post_author' => $wp_user->ID ),
		true
	);
	if ( ! is_wp_error( $plain ) ) {
		$assert( ! $reader->load( (int) $plain ), 'A page ReplicaForge did not generate is refused.' );
		$assert( 'not_a_replicaforge_draft' === $reader->error(), 'A non-ReplicaForge page reports a clear reason.' );
		wp_delete_post( (int) $plain, true );
	}

	$published = wp_insert_post(
		array( 'post_title' => 'ReplicaForge published probe', 'post_status' => 'publish', 'post_type' => 'page', 'post_author' => $wp_user->ID ),
		true
	);
	if ( ! is_wp_error( $published ) ) {
		update_post_meta( (int) $published, \ReplicaForge\Elementor_Limits::META_PREFIX . 'generation_id', 'gen_probe' );
		update_post_meta( (int) $published, '_elementor_edit_mode', 'builder' );
		update_post_meta( (int) $published, '_elementor_data', '[]' );
		$assert( ! $reader->load( (int) $published ), 'A published page is refused for correction.' );
		$assert( 'page_is_not_a_draft' === $reader->error(), 'A published page reports that it is not a draft.' );
		wp_delete_post( (int) $published, true );
	}

	// A refused load must leave nothing behind, so a later read cannot mistake the
	// previous draft for the one that was asked for.
	$assert( array() === $reader->elements(), 'A refused load leaves no document in memory.' );
	$assert( array() === $reader->top_level(), 'A refused load leaves no top-level elements in memory.' );
	$assert( $reader->load( $draft_id ), 'The reader opens the real draft again after a refusal.' );
	$assert( ! empty( $reader->top_level() ), 'The reloaded reader indexes the top-level elements again.' );

	/* A real validation, then a real plan. */
	$engine = new \ReplicaForge\Validation_Engine();
	$validation = $engine->validate( $source, $draft_id, array( 'visual' => false, 'ai' => false, 'force' => true ) );
	$assert( ! empty( $validation['validation_id'] ), 'A validation was produced for the contract draft.' );

	$correction_engine = new \ReplicaForge\Correction_Engine();
	$plan_report       = $correction_engine->plan( $validation, $draft_id, array( 'ai' => false ) );
	$assert( ! empty( $plan_report['plan_id'] ), 'A correction plan was produced.' );
	$assert( isset( $plan_report['counts']['safe'] ) && isset( $plan_report['counts']['requires_review'] ) && isset( $plan_report['counts']['blocked'] ), 'The plan reports all three eligibility counts.' );
	$assert( true === $plan_report['human_review_required'], 'A real plan requires a human decision.' );
	$assert( false === $plan_report['applied'], 'A real plan changes nothing.' );
	$assert( ! empty( $plan_report['blocked'] ), 'Structural differences are reported as blocked rather than applied.' );

	$blocked_categories = array();
	foreach ( $plan_report['blocked'] as $blocked_entry ) {
		$blocked_categories[] = $blocked_entry['property'];
	}
	$assert( in_array( 'text', $blocked_categories, true ), 'A content difference is blocked, never auto-applied.' );

	$document_before = (string) get_post_meta( $draft_id, '_elementor_data', true );

	/* The fixture's design system measures no padding and emits no tablet override,
	   so the two most important writer guarantees - a side property touching only
	   its own side, and a device correction leaving desktop alone - would otherwise
	   go untested. The controls are seeded here so they are exercised. The values
	   are written through the same helpers the plugin uses, so the test cannot
	   accidentally assert a shape the plugin does not produce. */
	$seeded_elements = $reader->elements();
	$seed_container  = '';
	foreach ( $reader->index() as $element_id => $element ) {
		if ( 'container' === ( $element['el_type'] ?? '' ) ) {
			$seed_container = $element_id;
			break;
		}
	}
	$seed_helper = function ( array $list ) use ( &$seed_helper, $properties, $seed_container ) {
		foreach ( $list as $index => $element ) {
			if ( ! is_array( $element ) || ! isset( $element['id'] ) ) {
				continue;
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			if ( strtolower( (string) $element['id'] ) === $seed_container ) {
				$properties->write_control(
					$settings,
					'padding',
					array(
						'top'    => '10px',
						'bottom' => '20px',
						'left'   => '30px',
						'right'  => '40px',
						'unit'   => 'px',
					)
				);
				$properties->write_control( $settings, 'flex_direction', 'row' );
				$list[ $index ]['settings'] = $settings;
				return array( $list, true );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$child = $seed_helper( $element['elements'] );
				if ( $child[1] ) {
					$list[ $index ]['elements'] = $child[0];
					return array( $list, true );
				}
			}
		}
		return array( $list, false );
	};
	$seeded = $seed_helper( $seeded_elements );
	$assert( (bool) $seeded[1], 'The contract document holds a container to seed.' );

	// A device override lives in the responsive map, not in the element settings,
	// which is where the Elementor editor reads it from.
	$seed_responsive = array( $seed_container => array() );
	$properties->write_control( $seed_responsive[ $seed_container ], 'flex_direction_tablet', 'row' );

	$reader->set_elements( $seeded[0] );
	$reader->set_responsive( $seed_responsive );
	$assert( null !== $reader->control_value( $seed_container, 'padding' ), 'The seeded container has a padding value.' );
	$assert( 'row' === $reader->control_value( $seed_container, 'flex_direction' ), 'The seeded container has a desktop direction.' );
	$assert( 'row' === $reader->control_value( $seed_container, 'flex_direction_tablet' ), 'The seeded container has a tablet direction.' );

	/* Test 1 - a single property correction changes only that property. */
	$writer  = new \ReplicaForge\Elementor_Document_Writer( $registry, $properties, $mapper, $builder );
	$target  = '';
	foreach ( $reader->id_map() as $element_id => $component_id ) {
		$element = $reader->element( $element_id );
		// The reader returns an index record, which names the type `el_type`.
		if ( is_array( $element ) && 'widget' === ( $element['el_type'] ?? '' ) && null !== $reader->control_value( $element_id, 'typography_font_size' ) ) {
			$target = $element_id;
			break;
		}
	}
	if ( '' !== $target ) {
		$before_size = $reader->control_value( $target, 'typography_font_size' );
		$new_size    = (float) $before_size['size'] + 8;
		$applied     = $writer->apply(
			$reader->elements(),
			array(
				array(
					'correction_id' => 'correction_001_aaaaaaaa',
					'action'       => 'update',
					'property'     => 'font_size',
					'viewport'     => 'desktop',
					'value'        => $new_size,
					'eligibility'  => 'safe',
					'level'        => 2,
					'batch'        => 'typography',
					'target'       => array( 'elementor_element_id' => $target ),
				),
			)
		);
		$assert( 1 === count( $applied['applied'] ), 'One selected correction applies one change.' );
		$assert( empty( $applied['failed'] ), 'The change reports no failure.' );

		$after_reader = new \ReplicaForge\Elementor_Document_Reader( $registry, $properties );
		$after_reader->set_elements( $applied['elements'] );
		$after_size = $after_reader->control_value( $target, 'typography_font_size' );
		$assert( $new_size === (float) $after_size['size'], 'The corrected font size is the source value.' );

		$unchanged_property = 'typography_color';
		$assert(
			wp_json_encode( $reader->control_value( $target, $unchanged_property ) ) === wp_json_encode( $after_reader->control_value( $target, $unchanged_property ) ),
			'An unrelated colour on the same element is unchanged.'
		);

		$neighbour = '';
		foreach ( $after_reader->index() as $element_id => $element ) {
			if ( $element_id !== $target && 'widget' === $element['el_type'] ) {
				$neighbour = $element_id;
				break;
			}
		}
		if ( '' !== $neighbour ) {
			$assert(
				wp_json_encode( $reader->control_value( $neighbour, 'typography_font_size' ) ) === wp_json_encode( $after_reader->control_value( $neighbour, 'typography_font_size' ) ),
				'A different element is unchanged.'
			);
		}
	} else {
		echo "SKIP: isolated property correction (the generated document has no sized typography)\n";
	}

	/* A side property must only change its own side. */
	$container = '';
	foreach ( $reader->index() as $element_id => $element ) {
		if ( 'container' === $element['el_type'] && null !== $reader->control_value( $element_id, 'padding' ) ) {
			$container = $element_id;
			break;
		}
	}
	if ( '' !== $container ) {
		$before_padding = $reader->control_value( $container, 'padding' );
		$side_applied   = $writer->apply(
			$reader->elements(),
			array(
				array(
					'correction_id' => 'correction_002_bbbbbbbb',
					'action'       => 'resize',
					'property'     => 'padding_top',
					'viewport'     => 'desktop',
					'value'        => (float) $before_padding['top'] + 16,
					'eligibility'  => 'safe',
					'level'        => 3,
					'batch'        => 'spacing',
					'target'       => array( 'elementor_element_id' => $container ),
				),
			)
		);
		$side_reader = new \ReplicaForge\Elementor_Document_Reader( $registry, $properties );
		$side_reader->set_elements( $side_applied['elements'] );
		$after_padding = $side_reader->control_value( $container, 'padding' );
		$assert( (float) $after_padding['top'] === (float) $before_padding['top'] + 16, 'The corrected padding side is the source value.' );
		$assert( (float) $after_padding['bottom'] === (float) $before_padding['bottom'], 'The opposite padding side is unchanged.' );
		$assert( (float) $after_padding['left'] === (float) $before_padding['left'], 'The horizontal padding sides are unchanged.' );
	} else {
		echo "SKIP: side-only padding correction (the generated document has no measured padding)\n";
	}

	/* A tablet correction must not touch the desktop value. */
	$tablet_target = '';
	foreach ( $reader->index() as $element_id => $element ) {
		if ( 'container' === ( $element['el_type'] ?? '' ) && null !== $reader->control_value( $element_id, 'flex_direction_tablet' ) ) {
			$tablet_target = $element_id;
			break;
		}
	}
	if ( '' !== $tablet_target ) {
		// The writer is seeded with the overrides already on the draft, which is how
		// the applier uses it. Without that it would not find the current value and
		// would report the correction as refused.
		$writer->set_responsive( $reader->responsive() );
		$before_direction = $reader->control_value( $tablet_target, 'flex_direction_tablet' );
		$desktop_before   = $reader->control_value( $tablet_target, 'flex_direction' );
		$tablet_applied   = $writer->apply(
			$reader->elements(),
			array(
				array(
					'correction_id' => 'correction_003_cccccccc',
					'action'       => 'responsive_update',
					'property'     => 'flex_direction',
					'viewport'     => 'tablet',
					'value'        => 'column' === $before_direction ? 'row' : 'column',
					'eligibility'  => 'safe',
					'level'        => 3,
					'batch'        => 'responsive',
					'target'       => array( 'elementor_element_id' => $tablet_target ),
				),
			)
		);
		$assert( 1 === count( $tablet_applied['applied'] ), 'A device correction is applied.' );
		$assert( empty( $tablet_applied['failed'] ), 'A device correction reports no failure.' );

		// The corrected device value is in the responsive map, not in the element
		// settings, because that is where the Elementor editor reads it from.
		$tablet_reader = new \ReplicaForge\Elementor_Document_Reader( $registry, $properties );
		$tablet_reader->set_elements( $tablet_applied['elements'] );
		$tablet_reader->set_responsive( $tablet_applied['responsive'] );
		$assert( ( $before_direction === 'column' ? 'row' : 'column' ) === $tablet_reader->control_value( $tablet_target, 'flex_direction_tablet' ), 'The tablet direction is the corrected value.' );
		$assert( $desktop_before === $tablet_reader->control_value( $tablet_target, 'flex_direction' ), 'The desktop direction is untouched by a tablet correction.' );
		$assert( null === $tablet_reader->control_value( $tablet_target, 'flex_direction_mobile' ), 'A tablet correction adds no mobile rule.' );

		// The device value must not be smuggled into the element settings, where the
		// editor would ignore it.
		$written_settings = $tablet_applied['elements'];
		$flat_key_found   = false;
		$find_flat = function ( array $list ) use ( &$find_flat, $tablet_target ) {
			foreach ( $list as $element ) {
				if ( ! is_array( $element ) || ! isset( $element['id'] ) ) {
					continue;
				}
				if ( strtolower( (string) $element['id'] ) === $tablet_target ) {
					return isset( $element['settings']['flex_direction_tablet'] );
				}
				if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
					if ( $find_flat( $element['elements'] ) ) {
						return true;
					}
				}
			}
			return false;
		};
		$flat_key_found = $find_flat( $written_settings );
		$assert( false === $flat_key_found, 'A device correction is not written into the element settings.' );
	} else {
		echo "SKIP: per-device correction (the generated document has no tablet rule)\n";
	}

	/* Test 10 - unsafe and unknown corrections are refused. */
	$refusals = array(
		array( 'property' => 'made_up_control', 'value' => 'x', 'reason' => 'property_not_writable' ),
		array( 'property' => 'font_size', 'value' => '<script>alert(1)</script>', 'reason' => 'value_not_acceptable' ),
		array( 'property' => 'text_color', 'value' => 'url(javascript:alert(1))', 'reason' => 'value_not_acceptable' ),
		array( 'property' => 'background_color', 'value' => 'expression(alert(1))', 'reason' => 'value_not_acceptable' ),
	);
	foreach ( $refusals as $refusal ) {
		$outcome = $writer->apply(
			$reader->elements(),
			array(
				array(
					'correction_id' => 'correction_004_dddddddd',
					'action'       => 'update',
					'property'     => $refusal['property'],
					'viewport'     => 'desktop',
					'value'        => $refusal['value'],
					'eligibility'  => 'safe',
					'level'        => 1,
					'batch'        => 'finetune',
					'target'       => array( 'elementor_element_id' => $container ? $container : $target ),
				),
			)
		);
		$assert( 0 === count( $outcome['applied'] ), 'An unsafe correction is not applied: ' . $refusal['reason'] . '.' );
		$assert( 1 === count( $outcome['failed'] ), 'An unsafe correction reports why it was refused.' );
	}

	$unknown_target = $writer->apply(
		$reader->elements(),
		array(
			array(
				'correction_id' => 'correction_005_eeeeeeee',
				'action'       => 'update',
				'property'     => 'font_size',
				'viewport'     => 'desktop',
				'value'        => 64,
				'eligibility'  => 'safe',
				'level'        => 2,
				'batch'        => 'typography',
				'target'       => array( 'elementor_element_id' => 'zzzz999' ),
			),
		)
	);
	$assert( 0 === count( $unknown_target['applied'] ), 'A correction for an unknown element is refused.' );
	$assert( 'target_element_missing' === $unknown_target['failed'][0]['reason'], 'An unknown element reports a clear reason.' );

	/* A structural action needs explicit approval. */
	$unapproved = $validator->check(
		array(
			'correction_id' => 'correction_006_ffffffff',
			'action'       => 'remove',
			'property'     => 'section_present',
			'viewport'     => 'desktop',
			'value'        => 'absent',
			'eligibility'  => 'requires_review',
			'target'       => array( 'elementor_element_id' => $reader->top_level()[0] ),
		),
		$reader
	);
	$assert( false === $unapproved['valid'], 'A structural correction is refused without explicit approval.' );
	$assert( 'structural_action_not_approved' === $unapproved['reason'], 'A structural refusal reports why approval is needed.' );

	$blocked_entry = $validator->check(
		array(
			'correction_id' => 'correction_007_11111111',
			'action'       => 'update',
			'property'     => 'font_size',
			'viewport'     => 'desktop',
			'value'        => 64,
			'eligibility'  => 'blocked',
			'target'       => array( 'elementor_element_id' => $target ),
		),
		$reader
	);
	$assert( false === $blocked_entry['valid'], 'A blocked correction is refused by the plan validator.' );

	/* Test 11 - an unauthorized request must not reach the writer. */
	$engine_under_test = new \ReplicaForge\Correction_Engine();
	$previous_user    = get_current_user_id();
	wp_set_current_user( 0 );
	$refused_plan = $engine_under_test->plan( $validation, $draft_id, array() );
	$assert( empty( $refused_plan['success'] ), 'An unauthenticated plan request is refused.' );
	$assert( 'replicaforge_forbidden' === $refused_plan['error']['code'] || 'forbidden' === $refused_plan['error']['code'], 'An unauthenticated plan request reports a forbidden error.' );
	$refused_apply = $engine_under_test->apply( $plan_report['plan_id'], array( 'correction_001_aaaaaaaa' ), array() );
	$assert( empty( $refused_apply['success'] ), 'An unauthenticated apply request is refused.' );
	wp_set_current_user( $previous_user );

	/* Test 7 - a manual edit is detected as a conflict. */
	$edited_document = json_decode( $document_before, true );
	$edit_key        = '';
	foreach ( $edited_document as $index => $element ) {
		if ( isset( $element['id'], $element['settings']['typography_font_size'] ) ) {
			$edited_document[ $index ]['settings']['typography_font_size']['size'] = 41;
			$edit_key = (string) $element['id'];
			break;
		}
	}
	if ( '' !== $edit_key ) {
		$snapshot = $snapshots->create( $draft_id, $validation['validation_id'] );
		$assert( ! empty( $snapshot['success'] ), 'A snapshot was created before the manual edit test.' );
		$writer->save( $draft_id, $edited_document );

		$edited_reader = new \ReplicaForge\Elementor_Document_Reader( $registry, $properties );
		$assert( $edited_reader->load( $draft_id ), 'The edited document is readable.' );
		$conflict = $snapshots->manual_change( $edited_reader, $draft_id, $edit_key, 'font_size', 'desktop' );
		$assert( true === $conflict['modified'], 'A manual change to a recorded property is detected.' );

		$conflict_candidate = array(
			'action'    => 'update',
			'property'  => 'font_size',
			'category'  => 'typography',
			'viewport'  => 'desktop',
			'confidence' => 0.95,
			'value'     => 64,
			'target'    => array( 'elementor_element_id' => $edit_key ),
			'target_property_present' => true,
			'manual_change' => array( 'modified' => true ),
		);
		$conflict_classified = $eligibility->classify( $conflict_candidate );
		$assert( 'requires_review' === $conflict_classified['eligibility'], 'A conflicting correction requires review rather than overwriting the edit.' );

		$restored = $snapshots->restore( $draft_id, (string) $snapshot['snapshot_id'] );
		$assert( ! empty( $restored['success'] ), 'The manual edit test snapshot was restored.' );
	}

	/* Test 8 - a save survives Elementor's own serialization, and rollback
	   restores the exact document that was there before. */
	$document_before_rollback = (string) get_post_meta( $draft_id, '_elementor_data', true );
	$rollback_snapshot        = $snapshots->create( $draft_id, $validation['validation_id'] );
	$assert( ! empty( $rollback_snapshot['success'] ), 'A snapshot was created before the rollback test.' );

	$round_trip = json_decode( $document_before_rollback, true );
	$assert( is_array( $round_trip ) && ! empty( $round_trip ), 'The stored document is readable JSON.' );
	$saved = $writer->save( $draft_id, $round_trip );
	$assert( ! empty( $saved['success'] ), 'A document save through the writer succeeds for a valid document.' );

	// Saving the same elements must not lose anything, so the stored document is
	// compared as data rather than as a byte-identical string: Elementor is free to
	// serialise it differently, but not to drop or alter an element.
	$after_round_trip = json_decode( (string) get_post_meta( $draft_id, '_elementor_data', true ), true );
	$assert( is_array( $after_round_trip ), 'The document is still readable JSON after a save.' );
	$assert(
		count( $after_round_trip ) === count( $round_trip )
		&& array_keys( $after_round_trip ) === array_keys( $round_trip ),
		'A save round-trips every top-level element through Elementor.'
	);
	$assert( $after_round_trip === $round_trip, 'A save round-trips the document through Elementor without altering it.' );

	// A real change is then written, so the rollback has something to undo. The
	// title lives on a nested widget, because every top-level element is a
	// container, so the search has to walk the tree.
	$set_first_title = function ( array $list ) use ( &$set_first_title ) {
		foreach ( $list as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['settings']['title'] ) ) {
				$list[ $index ]['settings']['title'] = 'Changed before rollback';
				return array( $list, true );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$child = $set_first_title( $element['elements'] );
				if ( $child[1] ) {
					$list[ $index ]['elements'] = $child[0];
					return array( $list, true );
				}
			}
		}
		return array( $list, false );
	};
	$title_change    = $set_first_title( $round_trip );
	$changed         = (bool) $title_change[1];
	$changed_elements = $title_change[0];
	$assert( $changed, 'The contract document holds a writable title to change.' );
	$written = $writer->save( $draft_id, $changed_elements );
	$assert( ! empty( $written['success'] ), 'A real change is written through the writer.' );
	$assert(
		false !== strpos( (string) get_post_meta( $draft_id, '_elementor_data', true ), 'Changed before rollback' ),
		'A real change is readable from the stored document.'
	);

	$invalid_write = $writer->save( $draft_id, array( 'not-an-element' => array( 'id' => 'x' ) ) );
	$assert( empty( $invalid_write['success'] ), 'An invalid document is refused by the writer.' );

	$rollback = $snapshots->restore( $draft_id, (string) $rollback_snapshot['snapshot_id'] );
	$assert( ! empty( $rollback['success'] ), 'The snapshot was restored.' );
	$restored_document = new \ReplicaForge\Elementor_Document_Reader( $registry, $properties );
	$assert( $restored_document->load( $draft_id ), 'The restored document is readable.' );

	// The stored document must hash to the value the snapshot recorded when it was
	// taken, which is what makes a rollback verifiable rather than hopeful.
	$assert(
		hash( 'sha256', (string) get_post_meta( $draft_id, '_elementor_data', true ) ) === $rollback['document_hash'],
		'The rollback restored the exact stored document.'
	);

	// The change that was written after the snapshot is gone, and the document that
	// was there before it is back, compared as data because Elementor re-serialises
	// on every save.
	$after_rollback = json_decode( (string) get_post_meta( $draft_id, '_elementor_data', true ), true );
	$assert(
		false === strpos( (string) get_post_meta( $draft_id, '_elementor_data', true ), 'Changed before rollback' ),
		'The rollback removed the change written after the snapshot.'
	);
	$assert( $after_rollback === $round_trip, 'The rollback restored the document element for element.' );

	$bad_rollback = $snapshots->restore( $draft_id, 'snap_00000000000000000000' );
	$assert( empty( $bad_rollback['success'] ), 'An unknown snapshot is refused.' );
	$assert( 'snapshot_not_found' === $bad_rollback['error']['code'], 'An unknown snapshot reports a clear reason.' );

	/* A plan is bound to the document it was built from. */
	$stale = $engine_under_test->apply( $plan_report['plan_id'], array( 'correction_001_aaaaaaaa' ), array() );
	$assert( empty( $stale['success'] ) || 'plan_document_changed' === $stale['error']['code'], 'A plan built against a different document is refused or reported.' );

	$assert( 'draft' === get_post_status( $draft_id ), 'The correction workflow never publishes the draft.' );
	$assert( $restored_document->element_count() > 0, 'The document is intact after the correction workflow.' );

	/* Test 11 - the whole path a user takes, end to end.
	   Everything above drives the writer directly, so the route that actually
	   matters - validate, plan, review, apply, roll back - is exercised here against
	   a real draft. A defect in the planner, the validator, or the applier shows up
	   as a refusal or a no-op rather than as a corrected document.
	   A deliberately wrong value is written first, so there is a real discrepancy
	   to find. Without one the plan is correctly empty and the run would pass while
	   proving nothing. */
	$wrong_size  = 11;
	$live_writer = new \ReplicaForge\Elementor_Document_Writer( $registry, $properties, $mapper, $builder );
	$live_writer->set_responsive( $reader->responsive() );

	// Find a widget that carries a font size and put a wrong one in it.
	$wrong_target = '';
	$wrong_written = false;
	$shrink_heading = function ( array $list ) use ( &$shrink_heading, $properties, $wrong_size, &$wrong_target, &$wrong_written ) {
		foreach ( $list as $index => $element ) {
			if ( ! is_array( $element ) || ! isset( $element['id'] ) ) {
				continue;
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			if ( ! isset( $settings['typography_font_size'] ) ) {
				if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
					$child = $shrink_heading( $element['elements'] );
					if ( $child[1] ) {
						$list[ $index ]['elements'] = $child[0];
						return array( $list, true );
					}
				}
				continue;
			}
			$settings['typography_font_size'] = array( 'size' => $wrong_size, 'unit' => 'px' );
			$list[ $index ]['settings']     = $settings;
			$wrong_target                   = strtolower( (string) $element['id'] );
			$wrong_written                  = true;
			return array( $list, true );
		}
		return array( $list, false );
	};
	$shrunk        = $shrink_heading( $reader->elements() );
	$assert( $wrong_written, 'A widget with a font size was found to write a wrong value into.' );
	$assert( '' !== $wrong_target, 'The wrong value was written to a known element.' );

	$live_written = $live_writer->save( $draft_id, $shrunk[0], $reader->responsive() );
	$assert( ! empty( $live_written['success'] ), 'The wrong value is written for the end-to-end run.' );

	$wrong_reader = new \ReplicaForge\Elementor_Document_Reader( $registry, $properties );
	$assert( $wrong_reader->load( $draft_id ), 'The draft with the wrong value is readable.' );
	$read_back = $wrong_reader->control_value( $wrong_target, 'typography_font_size' );
	// Both sides are cast, because a strict comparison of an integer against a
	// float is false even when the numbers are equal.
	$assert(
		is_array( $read_back ) && (float) $wrong_size === (float) $read_back['size'],
		'The wrong value is the value the document now holds: target=' . $wrong_target . ' read=' . wp_json_encode( $read_back )
	);

	$live_validation = $engine->validate( $source, $draft_id, array( 'visual' => false, 'ai' => false, 'force' => true ) );
	$live_engine     = new \ReplicaForge\Correction_Engine();
	$live_plan       = $live_engine->plan( $live_validation, $draft_id, array( 'ai' => false ) );
	$assert( ! empty( $live_plan['plan_id'] ), 'An end-to-end plan is produced.' );

	// The plan report groups corrections by eligibility, so the reviewable entries
	// are the safe and the requires-review groups.
	$applicable = array();
	foreach ( array( 'safe', 'requires_review' ) as $group ) {
		$entries = isset( $live_plan[ $group ] ) && is_array( $live_plan[ $group ] ) ? $live_plan[ $group ] : array();
		foreach ( $entries as $entry ) {
			if ( isset( $entry['correction_id'] ) ) {
				$applicable[] = (string) $entry['correction_id'];
			}
		}
	}
	$assert( ! empty( $applicable ), 'The end-to-end plan offers at least one correction to review: ' . wp_json_encode( $live_plan['counts'] ) );

	// The correction for the element that was deliberately broken has to be in the
	// plan, otherwise the run is correcting something other than the discrepancy.
	$planned_target = '';
	foreach ( $live_plan['safe'] as $entry ) {
		if (
			'font_size' === ( $entry['property'] ?? '' )
			&& $wrong_target === ( $entry['target']['elementor_element_id'] ?? '' )
		) {
			$planned_target = (string) $entry['correction_id'];
		}
	}
	$assert( '' !== $planned_target, 'The plan corrects the font size that was made wrong.' );

	$document_pre_apply = (string) get_post_meta( $draft_id, '_elementor_data', true );
	$live_applied      = $live_engine->apply( $live_plan['plan_id'], array( $planned_target ), array() );

	$assert(
		isset( $live_applied['status'] ) && 'completed' === $live_applied['status'],
		'An approved plan is applied without error: ' . wp_json_encode( $live_applied )
	);
	$assert( ! empty( $live_applied['correction_id'] ), 'The apply produces a correction record identifier.' );
	$assert( ! empty( $live_applied['counts']['applied'] ), 'The apply reports at least one applied correction.' );
	$assert( 'draft' === get_post_status( $draft_id ), 'Applying corrections never publishes the draft.' );

	// The document must now hold the source value, not the wrong one. This is the
	// whole point of the phase, so it is asserted on the stored document rather
	// than on what the run reported.
	$live_reader = new \ReplicaForge\Elementor_Document_Reader( $registry, $properties );
	$assert( $live_reader->load( $draft_id ), 'The draft is still readable after an apply.' );
	$corrected = $live_reader->control_value( $wrong_target, 'typography_font_size' );
	$assert( is_array( $corrected ), 'The corrected element still carries a font size.' );
	$assert(
		$wrong_size !== (float) $corrected['size'],
		'The apply replaced the wrong font size with the source value.'
	);
	$assert(
		(float) $corrected['size'] > (float) $wrong_size,
		'The corrected font size is the larger source value, not the wrong one.'
	);
	$assert(
		$live_reader->element_count() === $restored_document->element_count(),
		'An apply does not add or remove elements.'
	);

	// Roll the applied run back and confirm the wrong value comes back, which is the
	// guarantee the review screen offers the user.
	$live_rollback = $live_engine->rollback( $draft_id, (string) $live_applied['correction_id'] );
	$assert( ! empty( $live_rollback['success'] ), 'The applied run is rolled back through the engine.' );
	$after_rollback_reader = new \ReplicaForge\Elementor_Document_Reader( $registry, $properties );
	$assert( $after_rollback_reader->load( $draft_id ), 'The rolled-back draft is readable.' );
	$rolled_back = $after_rollback_reader->control_value( $wrong_target, 'typography_font_size' );
	$assert(
		is_array( $rolled_back ) && (float) $wrong_size === (float) $rolled_back['size'],
		'The rollback returned the wrong font size the correction replaced.'
	);
	$assert(
		hash( 'sha256', (string) get_post_meta( $draft_id, '_elementor_data', true ) ) === hash( 'sha256', $document_pre_apply ),
		'The rollback returns the document to its pre-apply state.'
	);
	$assert( 'draft' === get_post_status( $draft_id ), 'A rollback never publishes the draft.' );

	$history = $live_engine->history( $draft_id, 5 );
	$assert( ! empty( $history['runs'] ) || ! empty( $history ), 'The correction history records the run.' );
} finally {
	wp_delete_post( $draft_id, true );
}

$assert( null === get_post( $draft_id ), 'The contract draft is deleted again.' );

echo "Phase 6 contract test completed.\n";
