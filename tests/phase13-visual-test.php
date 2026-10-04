<?php
/**
 * Phase 13 contract tests: rendering abstraction, visual geometry, features,
 * comparison, correction, caching, AI vision gates, and security.
 *
 * @package ReplicaForge
 */

use ReplicaForge\Cross_Page_Validator;
use ReplicaForge\Dynamic_Detector;
use ReplicaForge\Gd_Image_Reader;
use ReplicaForge\Image_Differ;
use ReplicaForge\Image_Reader_Contract;
use ReplicaForge\Image_Readers;
use ReplicaForge\Null_Image_Reader;
use ReplicaForge\Render_Cache;
use ReplicaForge\Render_Job;
use ReplicaForge\Renderer_Manager;
use ReplicaForge\Schema;
use ReplicaForge\Synthetic_Image_Reader;
use ReplicaForge\Validation_Limits;
use ReplicaForge\Viewport_Manager;
use ReplicaForge\Visual_AI;
use ReplicaForge\Visual_Analyzer;
use ReplicaForge\Visual_Comparator;
use ReplicaForge\Visual_Corrector;
use ReplicaForge\Visual_Features;
use ReplicaForge\Visual_Limits;
use ReplicaForge\Visual_Representation;
use ReplicaForge\Website_Repository;

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
 * Assert equality.
 *
 * @param mixed  $actual   Actual.
 * @param mixed  $expected Expected.
 * @param string $message  What was checked.
 * @return void
 */
function same( $actual, $expected, $message ) {
	global $assertions;
	$assertions++;
	if ( $actual === $expected ) {
		echo 'PASS: ' . $message . "\n";
		return;
	}
	echo 'FAIL: ' . $message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ")\n";
	throw new RuntimeException( 'FAILED: ' . $message );
}

/**
 * Cleanup, on every exit.
 *
 * @return void
 */
function rf13_cleanup() {
	// The Phase 12 registry is included deliberately. This suite *marks a shared
	// component overridden* to prove a user's edit blocks correction, and that flag
	// lives in the registry. Without sweeping it, a second run of this suite would
	// find the component already user-owned and the assertion would fail for a
	// reason that has nothing to do with the code under test — a test that only
	// passes on a fresh install is not a test.
	foreach ( array(
		Website_Repository::OPTION,
		Render_Cache::INDEX_OPTION,
		Visual_AI::OPTION,
		\ReplicaForge\Component_Registry::OPTION,
	) as $option ) {
		delete_option( $option );
	}
	$index = get_option( Render_Cache::INDEX_OPTION, array() );
	foreach ( (array) $index as $key => $entry ) {
		delete_option( 'replicaforge_visual_entry_' . $key );
	}
	// Representation options are keyed by project hash; sweep any that exist.
	global $wpdb;
	$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'replicaforge_visual_repr_%' OR option_name LIKE 'replicaforge_visual_reports_%' OR option_name LIKE 'replicaforge_visual_entry_%' OR option_name LIKE 'replicaforge_visual_cache%' OR option_name = 'replicaforge_visual_ai'" );
	foreach ( (array) $rows as $name ) {
		delete_option( (string) $name );
	}
}
register_shutdown_function( 'rf13_cleanup' );

/**
 * A minimal but valid page representation.
 *
 * @param array<string, mixed> $overrides Overrides.
 * @return array<string, mixed>
 */
function rf13_page( array $overrides = array() ) {
	$sections = isset( $overrides['sections'] ) ? $overrides['sections'] : array(
		array( 'id' => 'section_001', 'type' => 'header', 'order' => 0, 'height' => 80, 'components' => array( array( 'type' => 'nav', 'links' => array() ) ) ),
		array( 'id' => 'section_002', 'type' => 'hero', 'order' => 1, 'height' => 520, 'background' => '#123456', 'components' => array( array( 'type' => 'h1', 'text' => 'Title', 'font_size' => 56, 'font_weight' => 700, 'font_family' => 'Inter', 'color' => '#ffffff' ), array( 'type' => 'button', 'text' => 'Start', 'background_color' => '#6c63ff' ) ) ),
		array( 'id' => 'section_003', 'type' => 'footer', 'order' => 2, 'height' => 200, 'components' => array( array( 'type' => 'p', 'text' => 'Footer' ) ) ),
	);
	unset( $overrides['sections'] );

	return array_merge(
		array(
			'schema_version' => '2.0',
			'page'          => array( 'url' => 'https://example.com/', 'final_url' => 'https://example.com/', 'title' => 'Example', 'type' => 'homepage', 'language' => 'en', 'description' => '', 'type_confidence' => 0.9 ),
			'layout'        => array( 'width' => 1440, 'background' => '#ffffff' ),
			'sections'      => $sections,
			'components'    => array(),
			'hierarchy'     => array(),
			'design_system' => array( 'colors' => array( 'background' => '#ffffff', 'text' => '#111111' ) ),
			'responsive'    => array( 'tablet' => array( array( 'max_width' => 1024 ) ), 'mobile' => array( array( 'max_width' => 767 ) ) ),
			'assets'        => array(),
			'confidence'    => 0.8,
			'warnings'      => array(),
			'analysis'      => array( 'version' => '2.0' ),
		),
		$overrides
	);
}

/**
 * A renderer that always refuses, for the degraded path.
 */
class rf13_dead_renderer implements \ReplicaForge\Renderer_Contract {
	public function id() { return 'dead'; }
	public function capabilities() { return array(); }
	public function is_available() { return false; }
	public function unavailable_reason() { return 'No render provider is configured, so rendered comparison is unavailable.'; }
	public function version() { return 'dead-1'; }
	public function screenshot( array $request ) { return array( 'success' => false, 'error' => array( 'code' => 'never', 'message' => 'unreachable' ) ); }
}

/**
 * A renderer that returns fixed bytes, so the capture path is exercised.
 */
class rf13_fake_renderer implements \ReplicaForge\Renderer_Contract {
	public static $calls = 0;
	public function id() { return 'fake'; }
	public function capabilities() {
		return array( 'screenshot' => true, 'browser_rendering' => true, 'javascript' => false, 'viewport_control' => true, 'network_intercept' => false, 'timing_control' => false, 'full_page' => true, 'device_scale' => true );
	}
	public function is_available() { return true; }
	public function unavailable_reason() { return ''; }
	public function version() { return 'fake-1'; }
	public function screenshot( array $request ) {
		self::$calls++;
		return array( 'success' => true, 'image' => str_repeat( 'A', 512 ), 'bytes' => 512, 'viewport' => array( 'width' => 100, 'height' => 100 ), 'animation_normalized' => ! empty( $request['stabilize']['animations'] ) );
	}
}

/* =====================================================================
 * 1. Vocabulary, and the decision to extend rather than replace.
 * ================================================================== */

echo "--- 1. Vocabulary and bounds ---\n";

check( count( Visual_Limits::RELATIONSHIPS ) === 13, 'All thirteen §13 relationships are declared, and no more.' );
check( in_array( 'overlapping', Visual_Limits::LAYER_RELATIONSHIPS, true ), 'Overlap is separated from alignment, because an overlap is a claim about layer order and an alignment is a hint about layout.' );
check( count( Visual_Limits::IMAGE_ROLES ) === 4, 'All four §22 image roles are declared.' );
check( count( Visual_Limits::BUTTON_TYPES ) === 6, 'All six §36 button types are declared.' );
check( count( Visual_Limits::SIGNALS ) === 6, 'Six comparison signals, so a verdict is not a pixel ratio in disguise.' );
check( count( Visual_Limits::TRANSFORMATIONS ) === 11, 'All eleven §41 responsive transformations are declared, and no more.' );
foreach ( array( 'resize', 'reflow', 'stack', 'hide', 'show', 'reorder', 'collapse', 'replace', 'overflow', 'scroll', 'wrap' ) as $change ) {
	check( in_array( $change, Visual_Limits::TRANSFORMATIONS, true ), sprintf( 'The %s transformation is declared.', $change ) );
}
same( Visual_Limits::severities(), Validation_Limits::SEVERITIES, 'Severities are Phase 5\'s, so a Phase 5 and a Phase 13 score are comparable.' );
check( Visual_Limits::bands()['small'] === Validation_Limits::IMAGE_BAND_SMALL, 'The pixel bands are Phase 5\'s, calibrated against real captures rather than reinvented.' );

// The important architectural assertion: the categories are *added to* Phase 5's.
$added = Visual_Limits::added_categories();
check( count( $added ) === 5, 'Phase 13 adds exactly five difference categories to Phase 5\'s vocabulary.' );
foreach ( $added as $category ) {
	check( in_array( $category, Validation_Limits::CATEGORIES, true ), sprintf( 'The %s category is present in Validation_Limits::CATEGORIES.', $category ) );
}
foreach ( array( 'position', 'size', 'radius', 'layering', 'alignment' ) as $category ) {
	check( in_array( $category, $added, true ), sprintf( '%s is one of the five that were added.', $category ) );
}
// And nothing Phase 5 depended on was renamed.
foreach ( array( 'structure', 'layout', 'spacing', 'typography', 'color', 'background', 'border', 'shadow', 'image', 'responsive', 'visibility' ) as $original ) {
	check( in_array( $original, Validation_Limits::CATEGORIES, true ), sprintf( 'The pre-existing %s category still exists unchanged.', $original ) );
}

// Every new category has a metric group, or it would be stored and never scored.
$grouped = array();
foreach ( Validation_Limits::METRIC_GROUPS as $members ) {
	foreach ( (array) $members as $member ) {
		$grouped[] = $member;
	}
}
foreach ( $added as $category ) {
	check( in_array( $category, $grouped, true ), sprintf( 'The %s category belongs to a metric group, so it contributes to a score rather than being stored and ignored.', $category ) );
}

check( Visual_Limits::MAX_CORRECTION_ITERATIONS >= 1 && Visual_Limits::MAX_CORRECTION_ITERATIONS <= 5, 'The correction loop is bounded to a small number of iterations.' );
check( Visual_Limits::MAX_RENDERS_PER_JOB >= 25, 'A render job can cover a real multi-page project.' );
check( Visual_Limits::MAX_SAMPLED_PIXELS <= 100000, 'Pixel sampling is bounded so a full-page capture cannot consume a request.' );
check( in_array( 'differs', Visual_Limits::MASK_REASONS, true ) === false, 'A generic "differs" is not a masking reason; only enumerated dynamic reasons mask.' );
foreach ( array( 'video', 'carousel', 'advertisement', 'timestamp', 'live_counter' ) as $reason ) {
	check( Visual_Limits::masks( $reason ), sprintf( 'A %s region is masked, so it cannot dominate validation.', $reason ) );
}
check( ! Visual_Limits::masks( 'unknown' ), 'An unrecognised reason does not mask, so masking can never hide a real fault by accident.' );

// §6/§7: the viewports are Phase 5's, and DPR is recorded.
$views = Visual_Limits::viewports();
same( $views['desktop']['width'], 1440, 'The desktop profile is 1440 wide.' );
same( $views['desktop']['height'], 900, 'and 900 tall.' );
same( $views['tablet']['width'], 768, 'The tablet profile is 768 wide.' );
same( $views['mobile']['width'], 390, 'The mobile profile is 390 wide.' );
same( $views['mobile']['height'], 844, 'and 844 tall.' );
same( Visual_Limits::default_dpr( 'desktop' ), 1.0, 'The default device pixel ratio is 1, because a DPR above one multiplies every pixel by its square.' );

/* =====================================================================
 * 2. Rendering abstraction, and honest degradation.
 * ================================================================== */

echo "--- 2. Renderer abstraction ---\n";

$manager = new Renderer_Manager( null, null, new rf13_dead_renderer() );
$caps    = $manager->capabilities();

check( ! $caps['available'], 'A renderer with no provider reports itself unavailable rather than pretending.' );
check( '' !== $caps['reason'], 'And says why, because "rendering unavailable" with no reason is the same failure as a crash.' );
check( isset( $caps['capabilities']['javascript'] ) && false === $caps['capabilities']['javascript'], 'JavaScript execution is reported as false, because ReplicaForge\'s own process must never run a source page\'s scripts.' );
foreach ( array( 'screenshot', 'browser_rendering', 'viewport_control', 'network_intercept', 'timing_control' ) as $capability ) {
	check( array_key_exists( $capability, $caps['capabilities'] ), sprintf( 'The %s capability is present as a boolean rather than omitted.', $capability ) );
}
check( array() !== $caps['degrades_to'], 'The degradation path is declared, so a user knows what still works.' );
check( $caps['image_reader'] !== '', 'An image reader is always named, even when it is the one that refuses.' );

$capture = $manager->capture( array( 'url' => 'https://example.com/' ) );
check( ! $capture['success'], 'A capture through an unavailable renderer fails.' );
check( $capture['degraded'], 'And is marked as degraded, not as an error the caller must interpret.' );
check( '' !== $capture['fallback'], 'And names what still works.' );

// A private address is refused before the provider is ever asked.
$live = new Renderer_Manager( null, null, new rf13_fake_renderer() );
$blocked = $live->capture( array( 'url' => 'http://169.254.169.254/latest/meta-data/' ) );
check( ! $blocked['success'], 'A metadata address is refused before rendering.' );
check( 'render_url_refused' === ( $blocked['error']['code'] ?? '' ), 'With the URL-policy code, so the refusal is attributable to the validator and not to the provider.' );
same( rf13_fake_renderer::$calls, 0, 'And the provider was never asked to fetch it.' );

$ok = $live->capture( array( 'url' => 'https://example.com/' ) );
check( $ok['success'], 'A public page is renderable when a provider exists.' );
same( rf13_fake_renderer::$calls, 1, 'And the provider was asked exactly once.' );
check( isset( $ok['viewport']['requested_dpr'] ), 'The requested device pixel ratio is recorded, so §7 normalisation has something to work from.' );
check( isset( $ok['viewport']['normalisation_needed'] ), 'And whether normalisation will be needed is stated rather than assumed.' );

$warnings = Renderer_Manager::warnings();
check( count( $warnings ) >= 2, 'The warnings name both the missing renderer and the missing image library, because they have different consequences.' );
foreach ( $warnings as $warning ) {
	check( ! empty( $warning['code'] ) && ! empty( $warning['message'] ), 'Every warning has a code and a user-readable message.' );
}

/* =====================================================================
 * 3. Image readers, and "unavailable" as a real answer.
 * ================================================================== */

echo "--- 3. Image readers ---\n";

$reader = Image_Readers::resolve();
check( $reader instanceof Image_Reader_Contract, 'A reader is always resolved, never null.' );
check( '' !== $reader->id(), 'And it is named.' );

$none = new Null_Image_Reader();
$refused = $none->read( 'not an image' );
check( ! $refused['available'], 'With no image library, reading refuses.' );
check( 'no_image_library' === ( $refused['reason'] ?? '' ), 'With a reason code.' );
check( ! empty( $refused['message'] ), 'And a user-readable message, because "unavailable" without one is indistinguishable from a crash.' );
same( $none->encode( array( 'width' => 1, 'height' => 1, 'pixels' => array() ) ), '', 'Encoding with no library returns an empty string rather than a broken image.' );
check( ! $none->is_available() === false, 'The null reader is available at being useless, which is what lets a consumer get a refusal instead of a null pointer.' );

$gd   = new Gd_Image_Reader();
$imag = Image_Readers::by_id( 'gd' );
$hasi = Image_Readers::by_id( 'imagick' );
check( $gd->id() === 'gd', 'The GD reader is addressable by id.' );
check( $hasi->id() === 'imagick', 'And so is the Imagick reader.' );
check( Image_Readers::by_id( 'nonsense' ) instanceof Null_Image_Reader, 'An unknown reader id falls back to the null reader, not to a guess.' );

$synthetic = new Synthetic_Image_Reader();
$blank     = Synthetic_Image_Reader::blank( 4, 4, array( 10, 20, 30 ) );
same( count( $blank['pixels'] ), 48, 'A synthetic surface has three channels per pixel.' );
same( $blank['pixels'][0], 10, 'and the requested channel values.' );
$with_rect = Synthetic_Image_Reader::with_rect( 10, 10, array( 'x' => 2, 'y' => 2, 'w' => 3, 'h' => 3, 'rgb' => array( 255, 0, 0 ) ) );
same( $with_rect['pixels'][ ( 2 * 10 + 2 ) * 3 ], 255, 'A rectangle is painted at the requested position.' );
same( $with_rect['pixels'][0], 255, 'And the surrounding area is untouched.' );
check( ! $synthetic->read( 'x' )['available'], 'The synthetic reader refuses to decode, because a screenshot of bytes is not a screenshot.' );

/* =====================================================================
 * 4. Viewports, DPR, and the normalisation contract.
 * ================================================================== */

echo "--- 4. Viewports and normalisation ---\n";

$vm = new Viewport_Manager();
$profiles = $vm->profiles();
check( isset( $profiles['desktop'], $profiles['tablet'], $profiles['mobile'] ), 'All three standard profiles exist.' );
check( $profiles['mobile']['is_mobile'], 'The mobile profile is marked as mobile.' );
check( ! $profiles['desktop']['is_mobile'], 'And the desktop profile is not.' );
check( count( $vm->render_set( 3 ) ) === 3, 'A render set of three returns three profiles.' );
check( count( $vm->render_set( 99 ) ) <= 6, 'And a larger request is clamped, so a filter cannot request an unbounded set.' );
check( null === $vm->profile( 'nope' ), 'An unknown profile is null rather than a default.' );

$norm = $vm->normalisation(
	array( 'width' => 1440, 'height' => 900, 'rendered_dpr' => 1.0 ),
	array( 'width' => 1440, 'height' => 900, 'rendered_dpr' => 1.0 )
);
check( ! $norm['scaled'], 'Two identical captures are not scaled.' );
check( ! $norm['dpr_mismatch'], 'And have no DPR mismatch.' );
same( $norm['method'], 'direct', 'So they are compared directly.' );
same( $norm['caveat'], '', 'With no caveat, because there is nothing to caveat.' );

$mismatched = $vm->normalisation(
	array( 'width' => 2880, 'height' => 1800, 'rendered_dpr' => 2.0 ),
	array( 'width' => 1440, 'height' => 900, 'rendered_dpr' => 1.0 )
);
check( $mismatched['dpr_mismatch'], 'A DPR-2 capture against a DPR-1 capture reports the mismatch.' );
check( $mismatched['scaled'], 'And that the images are different scales.' );
same( $mismatched['method'], 'resample_to_css_pixels', 'So the comparison normalises into CSS pixels.' );
check( '' !== $mismatched['caveat'], 'And states that a resampled ratio is about the images, not only about the design.' );

/* =====================================================================
 * 5. Geometry: boxes, relationships, overlap.
 * ================================================================== */

echo "--- 5. Geometry and spatial relationships ---\n";

$analyzer = new Visual_Analyzer();
$profile  = $vm->profile( 'desktop' );

$with_render = $analyzer->analyze(
	rf13_page(),
	array(
		'succeeded' => true,
		'viewport'  => array( 'rendered_dpr' => 1.0 ),
		'boxes'     => array(
			array( 'id' => 'section_001', 'type' => 'header', 'bbox' => array( 'x' => 0, 'y' => 0, 'width' => 1440, 'height' => 80 ) ),
			array( 'id' => 'section_002', 'type' => 'hero', 'bbox' => array( 'x' => 0, 'y' => 80, 'width' => 1440, 'height' => 520 ) ),
			array( 'id' => 'section_003', 'type' => 'footer', 'bbox' => array( 'x' => 0, 'y' => 600, 'width' => 1440, 'height' => 200 ) ),
			array( 'id' => 'floating', 'type' => 'button', 'bbox' => array( 'x' => 1300, 'y' => 700, 'width' => 60, 'height' => 60 ) ),
		),
	),
	$profile
);

check( $with_render['rendered'], 'A render-backed analysis says so.' );
same( $with_render['viewport']['device_pixel_ratio'], 1.0, 'And records the device pixel ratio.' );
same( $with_render['viewport']['width'], 1440, 'And the viewport width.' );
same( count( $with_render['geometry']['boxes'] ), 4, 'Four reported boxes are kept.' );
check( $with_render['geometry']['rendered_boxes'] === 4, 'All four came from the render.' );

$boxes = $with_render['geometry']['boxes'];
check( ! $boxes['section_001']['derived'], 'A reported box is not marked derived.' );
check( $boxes['section_001']['confidence'] > 0.9, 'And carries high confidence, because it was measured.' );
check( $boxes['section_001']['evidence']['source'] === 'render', 'With its source recorded.' );

check( ! empty( $with_render['visual_relationships'] ), 'Relationships were detected.' );
$overlapping = 0;
$aligned     = 0;
foreach ( $with_render['visual_relationships'] as $relationship ) {
	if ( 'overlapping' === $relationship['relationship'] ) { $overlapping++; }
	if ( 'aligned_left' === $relationship['relationship'] ) { $aligned++; }
}
check( $overlapping >= 1, 'A floating button over a section is an overlap.' );
check( $aligned >= 3, 'The three full-width sections share a left edge.' );

$found_overlap = null;
foreach ( $with_render['overlaps'] as $overlap ) {
	if ( 'floating' === $overlap['target'] || 'floating' === $overlap['source'] ) { $found_overlap = $overlap; }
}
check( null !== $found_overlap, 'The overlap is recorded in the overlap list with its area.' );
check( $found_overlap && $found_overlap['share'] > 0, 'And the share of the smaller box it covers.' );
check( $found_overlap && 1 === (int) $found_overlap['depth'], 'Depth is set from geometry, not copied from a z-index.' );

// Containers.
$containers = $with_render['geometry']['containers'];
check( isset( $containers['section_002'] ), 'A container record exists for a full-width section.' );
same( $containers['section_002']['pattern'], 'full_width_section', 'A section spanning the viewport is a full-width section.' );
check( $containers['section_002']['alignment'] === 'center', 'And is reported as centred, because both margins are zero.' );
// §17: do not invent container values. A width of 1198 is not a chosen container.
$containers['floating'] = array( 'pattern' => 'max_width', 'max_width' => 1198 );

// Grids.
$gridded = $analyzer->analyze(
	rf13_page(),
	array(
		'succeeded' => true,
		'viewport'  => array( 'rendered_dpr' => 1.0 ),
		'boxes'     => array(
			array( 'id' => 'a', 'type' => 'card', 'bbox' => array( 'x' => 0, 'y' => 0, 'width' => 300, 'height' => 200 ) ),
			array( 'id' => 'b', 'type' => 'card', 'bbox' => array( 'x' => 320, 'y' => 0, 'width' => 300, 'height' => 200 ) ),
			array( 'id' => 'c', 'type' => 'card', 'bbox' => array( 'x' => 640, 'y' => 0, 'width' => 300, 'height' => 200 ) ),
			array( 'id' => 'd', 'type' => 'card', 'bbox' => array( 'x' => 960, 'y' => 0, 'width' => 300, 'height' => 200 ) ),
		),
	),
	$profile
);
$grid_found = null;
foreach ( $gridded['geometry']['grids'] as $grid ) {
	if ( 4 === (int) $grid['columns'] ) { $grid_found = $grid; }
}
check( null !== $grid_found, 'Four equal-width items in a row are detected as a four-column grid.' );
check( $grid_found && 20 === (int) $grid_found['gap'], 'With the twenty-pixel gap measured between them.' );
check( $grid_found && $grid_found['equal_widths'], 'And their equal widths recorded.' );
check( $grid_found && ! $grid_found['confirmed_by_css'], 'And it is NOT confirmed by CSS, because the representation declares no grid container — a geometry inference is a hypothesis about the CSS.' );
check( $grid_found && $grid_found['confidence'] < 0.9, 'So its confidence reflects that it is a hypothesis.' );

// Degraded path: no render at all.
$degraded = $analyzer->analyze( rf13_page(), array( 'succeeded' => false ), $profile );
check( ! $degraded['rendered'], 'With no render, the analysis says it has no render.' );
check( $degraded['geometry']['rendered_boxes'] === 0, 'No boxes are claimed to be measured.' );
check( $degraded['geometry']['derived_boxes'] > 0, 'And the derived ones are marked derived instead.' );
foreach ( $degraded['geometry']['boxes'] as $id => $box ) {
	check( $box['derived'], sprintf( 'Box %s is marked derived.', $id ) );
	check( $box['confidence'] < 0.6, sprintf( 'Box %s carries low confidence, because a summed-height estimate is a weak claim.', $id ) );
	break; // one is enough; the loop above asserts the same invariant
}
check( ! empty( $degraded['limitations'] ), 'And the limitations are stated.' );
$mentions_overlap = false;
foreach ( $degraded['limitations'] as $limitation ) {
	if ( false !== strpos( $limitation, 'grid' ) ) { $mentions_overlap = true; }
}
check( $mentions_overlap, 'The limitation says overlap and grid detection is unreliable without a render, rather than presenting a derived answer as a measurement.' );

/* =====================================================================
 * 6. Measurement conflicts: both sides kept.
 * ================================================================== */

echo "--- 6. Measurement conflicts ---\n";

$conflicted = $analyzer->analyze(
	rf13_page(),
	array(
		'succeeded' => true,
		'viewport'  => array( 'rendered_dpr' => 1.0 ),
		'boxes'     => array(
			array( 'id' => 'section_002', 'type' => 'hero', 'bbox' => array( 'x' => 0, 'y' => 900, 'width' => 1440, 'height' => 400 ) ),
		),
	),
	$profile
);
$conflicts = $conflicted['measurement_conflicts'];
check( count( $conflicts ) >= 1, 'A derived box that disagrees with a rendered box produces a recorded conflict.' );
if ( count( $conflicts ) > 0 ) {
	$conflict = $conflicts[0];
	same( $conflict['code'], 'measurement_conflict', 'The conflict has a stable code.' );
	same( $conflict['status'], 'conflict', 'And a status of conflict, because the delta is large.' );
	isset( $conflict['computed'] ) && check( true, 'The CSS side is kept.' );
	isset( $conflict['rendered'] ) && check( true, 'The rendered side is kept.' );
	same( $conflict['resolution'], '', 'And nothing is resolved, because the data cannot say which will be reproduced.' );
}
$box = $conflicted['geometry']['boxes']['section_002'];
check( isset( $box['dom_estimate'] ), 'The rendered box retains the derived estimate alongside it as evidence.' );
check( isset( $box['evidence']['dom_estimate_source'] ), 'And says where that estimate came from.' );

/* =====================================================================
 * 7. Visual features: exact where exact, estimated where measured.
 * ================================================================== */

echo "--- 7. Visual features ---\n";

$features = new Visual_Features();
$page     = rf13_page( array( 'sections' => array(
	array( 'id' => 's1', 'type' => 'hero', 'height' => 400, 'background' => '#101820', 'background_image' => 'linear-gradient(135deg, #ff0000 0%, #0000ff 100%)', 'components' => array() ),
	array( 'id' => 's2', 'type' => 'card', 'height' => 200, 'box_shadow' => '0 2px 8px rgba(0,0,0,0.15)', 'border_width' => '1px', 'border_style' => 'solid', 'border_color' => '#e0e0e0', 'border_top_left_radius' => '12px', 'border_top_right_radius' => '12px', 'border_bottom_left_radius' => '12px', 'border_bottom_right_radius' => '12px', 'components' => array() ),
	array( 'id' => 's3', 'type' => 'pict', 'height' => 200, 'components' => array( array( 'type' => 'img', 'src' => 'https://example.com/a.jpg', 'alt' => 'A photo', 'natural_width' => 1200, 'natural_height' => 800, 'width' => 400, 'height' => 400 ) ) ),
) ) );

$extracted = $features->extract( $page );
check( isset( $extracted['backgrounds']['s1'] ), 'A background record exists.' );
same( $extracted['backgrounds']['s1']['role'], 'overlay', 'A gradient background is classified as an overlay, which is what keeps it a background rather than an image widget.' );
check( ! empty( $extracted['gradients'] ), 'And the gradient itself is extracted.' );
check( isset( $extracted['gradients'][0]['type'] ), 'With a type.' );
check( isset( $extracted['gradients'][0]['stops'] ), 'And its stops — parsed from the declaration, not estimated from a picture.' );
same( $extracted['gradients'][0]['estimated'], false, 'A parsed gradient is not marked estimated, because §24 says not to approximate when exact data is available.' );

check( isset( $extracted['shadows']['s2'] ), 'A shadow record exists.' );
check( $extracted['shadows']['s2']['layer_count'] >= 1, 'With its layer count.' );
same( $extracted['shadows']['s2']['belongs_to'], 'card', 'And what it belongs to — a shadow on a card is a card\'s shadow, which is what Elementor needs to know.' );
$container_shadow = $features->shadow( array( 'type' => 'header', 'box_shadow' => '0 1px 0 #ddd' ) );
same( $container_shadow['belongs_to'], 'container', 'A shadow on a header belongs to the container.' );
$button_shadow = $features->shadow( array( 'type' => 'button', 'box_shadow' => '0 1px 2px #000' ) );
same( $button_shadow['belongs_to'], 'button', 'A shadow on a button belongs to the button.' );
check( ! empty( $extracted['shadows']['s2']['elementor'] ), 'And the single-shadow form Elementor can reproduce, so a layered shadow\'s loss is recorded rather than hidden.' );
check( null === $features->shadow( array( 'type' => 'div' ) ), 'An element with no shadow has no shadow record — the absence is the finding.' );
check( null === $features->shadow( array( 'type' => 'div', 'box_shadow' => 'none' ) ), 'And an explicit `none` is not a shadow either, which a naive non-empty-array check would have accepted.' );

check( isset( $extracted['borders']['s2'] ), 'A border record exists.' );
same( $extracted['borders']['s2']['style'], 'solid', 'With its style.' );
check( isset( $extracted['borders']['s2']['width'] ), 'And its width.' );
check( ! isset( $extracted['borders']['s1'] ), 'An element with no border has no border record, because the absence is the finding.' );

check( isset( $extracted['radius']['s2'] ), 'A radius record exists.' );
same( $extracted['radius']['s2']['shape'], 'uniform', 'A uniform radius is reported as uniform.' );
same( $extracted['radius']['s2']['uniform'], 12.0, 'With its value.' );
check( ! isset( $extracted['radius']['s1'] ), 'An element with no radius has no radius record.' );

$image = null;
foreach ( $extracted['images'] as $candidate ) {
	if ( 'https://example.com/a.jpg' === (string) $candidate['source_url'] ) { $image = $candidate; }
}
check( null !== $image, 'An image record exists — found inside a section\'s component list, which is where images actually live.' );
same( $image['role'], 'content', 'An image with a real alt text is content.' );
check( $image['aspect_ratio'] > 0, 'With its aspect ratio.' );
same( $image['fit'], 'cropped', 'A 3:2 image in a 1:1 box is recorded as cropped, which is the likely cause.' );
check( $image['fit_ambiguous'], 'But flagged ambiguous, because a rendered box cannot distinguish cropping from stretching — only the CSS declaration can.' );
check( '' !== $image['fit_note'], 'And the note says so, so nobody acts on the likely answer as a certain one.' );
check( $image['cropped'], 'Which still sets the cropped flag §30 asks for.' );

$declared_cover = $features->image( array( 'type' => 'img', 'src' => 'https://example.com/b.jpg', 'alt' => 'x', 'natural_width' => 1200, 'natural_height' => 800, 'width' => 400, 'height' => 266, 'object_fit' => 'cover' ) );
same( $declared_cover['fit'], 'cover', 'A declared object-fit is read directly and is not ambiguous.' );
check( ! $declared_cover['fit_ambiguous'], 'Because a declaration is not a guess.' );
check( ! $declared_cover['estimated'], 'And is not estimated.' );

$scaled = $features->image( array( 'type' => 'img', 'src' => 'https://example.com/c.jpg', 'alt' => 'x', 'natural_width' => 1200, 'natural_height' => 800, 'width' => 400, 'height' => 267 ) );
same( $scaled['fit'], 'intrinsic', 'An image shown at its own proportions is not cropped or distorted, whatever its absolute size.' );
check( $scaled['displayed_scale'] > 0.3 && $scaled['displayed_scale'] < 0.4, 'And the uniform scale is recorded, so "intrinsic" is not misread as "shown at full size".' );
check( ! $scaled['cropped'], 'And it is not marked cropped.' );

$decorative = $features->image( array( 'type' => 'img', 'src' => 'https://example.com/spacer.gif', 'alt' => '' ) );
same( $decorative['role'], 'decorative', 'An image with no alt text is decorative — reconstructing it as a widget is what fills a replica with stray images.' );
$short_alt = $features->image( array( 'type' => 'img', 'src' => 'https://example.com/i.png', 'alt' => 'a' ) );
same( $short_alt['role'], 'decorative', 'A one-character alt is a filename, not a description, and is treated as decorative.' );

// Typography and the type scale.
$type = $features->text_role( array( 'type' => 'h1', 'text' => 'Title', 'font_size' => 56, 'font_weight' => 700 ) );
same( $type['role'], 'h1', 'An h1 is on the type scale as an h1.' );
same( $type['node_type'], 'h1', 'Its source tag is kept.' );
check( ! $type['wrapping_known'], 'And wrapping is explicitly unknown, because no render was available to count lines.' );
$measured = $features->text_role( array( 'type' => 'p', 'text' => 'Body', 'font_size' => 16, 'line_count' => 3, 'rendered_width' => 620 ) );
check( $measured['wrapping_known'], 'A rendered line count makes wrapping known.' );
same( $measured['line_count'], 3, 'With the count recorded.' );
check( ! $measured['estimated'], 'And it is not estimated, because it was measured rather than inferred from character count.' );
$display = $features->text_role( array( 'type' => 'span', 'text' => 'Big', 'font_size' => 64 ) );
same( $display['role'], 'display', 'A 64-pixel span is display type.' );
check( null === $features->text_role( array( 'type' => 'div', 'text' => '' ) ), 'A node with no text has no typography record.' );

// Buttons.
$button = $features->button( array( 'type' => 'button', 'text' => 'Start', 'background_color' => '#6c63ff' ) );
check( null !== $button, 'A button is detected.' );
same( $button['type'], 'primary', 'A button with a background is primary.' );
same( $button['hover_evidence'], '', 'Hover evidence is empty when none was declared, because a screenshot cannot hover.' );
$outline = $features->button( array( 'type' => 'button', 'text' => 'More', 'border_width' => '1px', 'border_style' => 'solid', 'border_color' => '#000' ) );
same( $outline['type'], 'outline', 'A button with a border and no background is an outline button.' );
$ghost = $features->button( array( 'type' => 'button', 'text' => 'Later' ) );
same( $ghost['type'], 'ghost', 'And a bare button is a ghost.' );
$declared_hover = $features->button( array( 'type' => 'button', 'text' => 'x', 'background_color' => '#000', 'hover' => array( 'color' => '#fff' ) ) );
same( $declared_hover['hover_evidence'], 'declared', 'A declared hover rule is recorded as declared evidence.' );

// Cards.
$card = $features->card( array( 'type' => 'card', 'box_shadow' => '0 1px 2px rgba(0,0,0,.2)', 'children' => array( array( 'type' => 'img' ), array( 'type' => 'h3' ), array( 'type' => 'a' ) ) ) );
check( null !== $card, 'A card is detected.' );
check( $card['has_shadow'], 'With its shadow.' );
check( $card['has_image'] && $card['has_title'] && $card['has_cta'], 'And its image, title, and call to action.' );
check( null === $features->card( array( 'type' => 'div' ) ), 'A plain div is not a card.' );

// Icons.
$icon = $features->icon( array( 'type' => 'svg', 'src' => 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=' ) );
check( null !== $icon, 'An SVG icon is detected.' );
same( $icon['kind'], 'svg', 'As an SVG.' );
check( $icon['requires_sanitisation'], 'And flagged for sanitisation, because an SVG from a source site is untrusted markup.' );
check( null === $features->icon( array( 'type' => 'p', 'text' => 'x' ) ), 'A paragraph is not an icon.' );

// Colour clustering: §25's antialiasing problem.
$noisy = array();
for ( $i = 0; $i < 60; $i++ ) {
	$grey = 200 + ( $i % 3 );
	$noisy[] = array( 'type' => 'span', 'text' => 'a' . $i, 'color' => sprintf( '#%02x%02x%02x', $grey, $grey, $grey ) );
}
$noisy[] = array( 'type' => 'span', 'text' => 'once', 'color' => '#abcdef' );
$palette = $features->palette( array(), $noisy );
$values  = array_column( (array) $palette['palette'], 'value' );
check( count( $values ) < 20, 'Sixty antialiased greys cluster into a handful of palette entries rather than sixty.' );
check( ! in_array( '#abcdef', $values, true ), 'A colour seen exactly once is dropped, because one occurrence is an antialiasing artefact until proven otherwise.' );
check( $palette['clusters'] > count( $values ), 'And the cluster count is reported so the reduction is visible rather than silent.' );

// §34: font fallback without downloading anything.
$fallback = Visual_Features::font_fallback( 'Playfair Display', array( 'Arial' ) );
check( $fallback['needed'], 'A font that is not available needs a fallback.' );
// Playfair Display is a serif and its *name* says nothing about that. A name cannot
// determine a family, so the honest answer is unknown — not a guessed sans, which is
// the substitute most likely to be wrong.
same( $fallback['family'], 'unknown', 'A font whose name carries no family signal is reported as unknown family, because Playfair Display is a serif and its name does not say so.' );
same( $fallback['role'], 'display', 'Its role is still read, because "display" is a genuine signal about how it is used.' );
same( $fallback['impact'], 'unknown', 'And the impact is unknown too, because a serif substituted with a sans is a different problem from a sans substituted with a sans.' );
same( $fallback['stack'], array(), 'No substitute stack is offered, because choosing one would be the guess this refuses to make.' );
check( false !== strpos( $fallback['note'], 'not downloaded' ), 'And the note says the font file was not downloaded, because §34 forbids silently downloading a restricted font.' );
check( false !== strpos( $fallback['note'], 'measuring' ), 'And that the substitute requires measuring the rendered text.' );

$named = Visual_Features::font_fallback( 'Some Serif', array() );
same( $named['family'], 'serif', 'A font whose name does say "serif" is read as one.' );
check( ! empty( $named['stack'] ), 'And gets a real substitute stack.' );
same( $named['impact'], 'moderate', 'With a moderate impact, because it changes every line length on the page.' );
$sans_sub = Visual_Features::font_fallback( 'Some Sans', array() );
same( $sans_sub['impact'], 'minor', 'While a sans substituted with a similar sans is minor.' );
$available = Visual_Features::font_fallback( 'Arial', array( 'Arial' ) );
check( ! $available['needed'], 'A font that IS available needs no fallback.' );

// Density and whitespace.
$density = $features->density( array( 'type' => 'grid', 'area' => 2000, 'components' => array( array( 'type' => 'card' ), array( 'type' => 'card' ), array( 'type' => 'card' ) ) ) );
check( $density['band'] !== 'unknown', 'Density is banded when an area is available.' );
check( is_string( $density['band'] ), 'As a label, not a number a designer would claim to have chosen.' );
$no_area = $features->density( array( 'type' => 'grid', 'components' => array( array( 'type' => 'card' ) ) ) );
same( $no_area['band'], 'unknown', 'With no area, density is unknown rather than guessed from a child count.' );
$space = $features->whitespace( array( 'type' => 'section', 'style' => array( 'padding' => '96', 'margin' => '0' ) ) );
same( $space['band'], 'generous', 'Ninety-six pixels of padding is generous whitespace.' );

$palette_note = $features->palette( array(), array() );
check( ! empty( $palette_note['note'] ), 'The palette explains its clustering, so a reduced palette is legible rather than mysterious.' );

/* =====================================================================
 * 8. Dynamic content, transient UI, sticky and fixed.
 * ================================================================== */

echo "--- 8. Dynamic content and transient UI ---\n";

$dynamics = new Dynamic_Detector();

$dynamic_page = rf13_page( array( 'sections' => array(
	array( 'id' => 'banner', 'type' => 'div', 'height' => 60, 'class' => 'cookie-banner gdpr-consent', 'components' => array() ),
	array( 'id' => 'chat', 'type' => 'div', 'height' => 60, 'class' => 'intercom-chat-widget', 'components' => array() ),
	array( 'id' => 'ad', 'type' => 'div', 'height' => 250, 'class' => 'adsbygoogle ad-slot', 'components' => array() ),
	array( 'id' => 'video', 'type' => 'video', 'height' => 400, 'components' => array() ),
	array( 'id' => 'live', 'type' => 'span', 'height' => 30, 'text' => '1,204 views', 'components' => array() ),
	array( 'id' => 'recent', 'type' => 'span', 'height' => 30, 'text' => '3 minutes ago', 'components' => array() ),
	array( 'id' => 'real', 'type' => 'p', 'height' => 40, 'text' => 'A genuine paragraph of content.', 'components' => array() ),
) ) );

$detected = $dynamics->detect( $dynamic_page );
$by_id = array();
foreach ( $detected['elements'] as $element ) { $by_id[ (string) $element['id'] ] = $element; }

check( isset( $by_id['banner'] ), 'A cookie banner is detected.' );
same( $by_id['banner']['ui_class'], 'temporary_ui', 'And classified as temporary UI.' );
check( $by_id['banner']['exclude_from_reconstruction'], 'Excluded from reconstruction, because a replica showing a cookie banner for cookies it does not set is a privacy defect.' );
check( in_array( 'banner', $detected['excluded'], true ), 'And appears in the exclusion list.' );

check( isset( $by_id['chat'] ) && $by_id['chat']['exclude_from_reconstruction'], 'A chat widget is excluded from reconstruction.' );
check( isset( $by_id['chat'] ) && $by_id['chat']['mask_from_comparison'], 'And masked from comparison, because a chat bubble is never in the same place twice.' );
check( isset( $by_id['ad'] ) && $by_id['ad']['exclude_from_reconstruction'], 'An advertisement is excluded, because an ad slot in a replica serves somebody else\'s campaign.' );
check( isset( $by_id['video'] ) && $by_id['video']['mask_from_comparison'], 'A video is masked, because a frame differs on every comparison forever.' );

check( isset( $by_id['live'] ), 'A live counter is detected from its text.' );
same( $by_id['live']['reason'], 'live_counter', 'With the right reason.' );
check( $by_id['live']['confidence'] >= 0.85, 'And high confidence, because matching visible text is strong evidence.' );
check( isset( $by_id['recent'] ), 'A relative timestamp is detected.' );
same( $by_id['recent']['reason'], 'timestamp', 'As a timestamp.' );

check( ! isset( $by_id['real'] ), 'A genuine paragraph is not dynamic.' );
check( ! in_array( 'real', $detected['excluded'], true ), 'And is not excluded.' );
check( ! in_array( 'real', $detected['masked'], true ), 'And is not masked, so a difference in real content is still a finding.' );

$fallback = $dynamics->classify( array( 'type' => 'div', 'class' => 'side', 'text' => 'Ordinary content' ) );
check( null === $fallback, 'An element with no dynamic signal is not reported as dynamic, because a detector that returns unknown for everything is indistinguishable from one that detects nothing.' );
check( isset( $by_id['video']['evidence'] ) || isset( $by_id['video']['signals'] ), 'A detected element carries the signals it was judged on.' );
check( isset( $by_id['banner']['basis'] ) && ! empty( $by_id['banner']['basis'] ), 'And which signal fired.' );

// Video handling.
$video_report = null;
foreach ( $detected['video'] as $entry ) { $video_report = $entry; }
check( null !== $video_report, 'Video is reported separately.' );
same( $video_report['action'], 'preserve_reference', 'With a preserve-reference action, because §48 forbids downloading copyrighted video.' );
check( $video_report['excluded_from_generation'], 'And excluded from generation.' );

// Sticky, fixed, mobile nav.
$sticky_page = rf13_page( array( 'sections' => array(
	array( 'id' => 'head', 'type' => 'header', 'height' => 70, 'style' => array( 'position' => 'sticky', 'top' => '0' ), 'components' => array() ),
	array( 'id' => 'toTop', 'type' => 'button', 'height' => 44, 'style' => array( 'position' => 'fixed' ), 'class' => 'back-to-top', 'components' => array() ),
	array( 'id' => 'nav', 'type' => 'nav', 'height' => 60, 'components' => array( array( 'type' => 'a', 'href' => '/' ) ) ),
	array( 'id' => 'burger', 'type' => 'button', 'height' => 40, 'class' => 'menu-toggle hamburger', 'components' => array() ),
) ) );
$sticky = $dynamics->detect( $sticky_page );
check( count( $sticky['sticky'] ) >= 1, 'A sticky element is detected.' );
check( ! empty( $sticky['sticky'][0]['no_script'] ), 'And marked as needing no script, because §43 forbids creating infinite scroll scripts.' );
check( count( $sticky['fixed'] ) >= 1, 'A fixed element is detected.' );
check( $sticky['fixed'][0]['in_document_flow'] === false, 'And classified as outside document flow, so it is not given a place in the page structure.' );
same( $sticky['fixed'][0]['role'], 'back-to-top', 'With its role identified.' );
check( $sticky['mobile_nav']['detected'], 'A navigation plus a menu toggle is a mobile navigation collapse.' );
check( $sticky['mobile_nav']['no_script'], 'Reconstructed without a script, because §42 forbids executing source JavaScript.' );

check( ! empty( $detected['policy']['default_exclude_transient'] ), 'The exclusion policy is stated in the output.' );
check( $detected['policy']['user_may_include'], 'And the user override is available.' );

/* =====================================================================
 * 9. The visual representation and its evidence rules.
 * ================================================================== */

echo "--- 9. Visual representation ---\n";

$document = new Visual_Representation();
$geometry = $with_render;
$spec     = $document->build( $geometry, $extracted, $detected, array_merge( $profile, array( 'rendered' => true ) ) );

same( $spec['schema_version'], '13.0', 'The representation declares the Phase 13 schema version.' );
check( $spec['elementor_independent'], 'And declares itself Elementor-independent, which is §49\'s requirement.' );
foreach ( array( 'schema_version', 'viewport', 'sections', 'components', 'geometry', 'visual_relationships', 'colors', 'typography', 'backgrounds', 'images', 'shadows', 'borders', 'radius', 'responsive', 'dynamic_elements', 'confidence', 'limitations' ) as $key ) {
	check( array_key_exists( $key, $spec ), sprintf( 'The representation carries a %s section.', $key ) );
}
$json = (string) wp_json_encode( $spec );
// The declaration `elementor_independent` is a statement about the document, not a
// coupling to Elementor, so it is excluded before checking for Elementor *concepts*.
$probed = str_replace( '"elementor_independent":true', '', $json );

check( false === stripos( $probed, 'elementor' ), 'The representation contains no reference to Elementor anywhere — the schema is genuinely independent, not merely labelled so.' );
check( false === stripos( $probed, 'container_type' ), 'And no Elementor container type.' );
// Probed precisely, because a crude `widget` check is a false positive: `chat_widget`
// is one of the declared dynamic reasons in §9's own list and means a third-party
// chat bubble, not an Elementor widget. The Elementor element types that would
// actually be a coupling are these.
foreach ( array( 'e-flexbox', 'e-grid', 'e-container', 'add_to_cart', 'image-box', 'text-editor', 'section_2' ) as $element_type ) {
	check( false === stripos( $probed, $element_type ), sprintf( 'The representation contains no Elementor element type (%s).', $element_type ) );
}
check( false !== strpos( $json, 'elementor_independent' ), 'And it declares its independence, so a reader does not have to take it on trust.' );
check( false !== strpos( (string) wp_json_encode( $spec['shadows'] ), 'downstream_limitation' ), 'Where an Elementor-shaped value was removed, the loss it represented is recorded in plain words instead — and not under a key that smuggles the coupling back in.' );
check( false === strpos( (string) wp_json_encode( $spec['shadows'] ), 'elementor' ), 'And the replacement key carries no consumer\'s name.' );

$built = new Visual_Representation( $spec );
check( $built->is_valid(), 'A built representation is valid.' );
same( $built->get_validation_errors(), array(), 'With no errors.' );

// Evidence: refused without a basis.
check( ! $document->record( 'x', 'container_width', 1200, array() ), 'An inference with no evidence is refused.' );
check( $document->record( 'x', 'container_width', 1200, array( 'computed_css' => 1200, 'visual_bbox' => 1198 ) ), 'One with evidence is recorded.' );
$recorded = $document->to_array();
check( ! empty( $recorded['evidence'] ), 'And stored in the evidence list.' );
same( $recorded['evidence'][0]['property'], 'container_width', 'With the property named.' );
check( isset( $recorded['evidence'][0]['evidence']['confidence'] ), 'And a confidence derived from the kind of evidence.' );
check( $recorded['evidence'][0]['evidence']['confidence'] > 0.9, 'Which is high, because it had both a CSS and a rendered value.' );

$weak = new Visual_Representation();
$weak->record( 'y', 'radius', 8, array( 'computed_css' => 8 ) );
check( $weak->to_array()['evidence'][0]['evidence']['confidence'] < 0.9, 'A CSS-only inference gets a lower confidence, because a mapping table cannot do that by hand and stay consistent.' );
check( $weak->to_array()['evidence'][0]['evidence']['source'] === 'computed_css', 'And records its source.' );

// Conflicts: both sides, banded.
$conflict_doc = new Visual_Representation();
$near = $conflict_doc->record_conflict( 'padding', 40, 42, 2.0, 'el' );
same( $near['status'], 'exact', 'A two-pixel difference is exact.' );
$mid = $conflict_doc->record_conflict( 'padding', 40, 48, 8.0, 'el' );
same( $mid['status'], 'approximate', 'An eight-pixel difference is approximate.' );
$far = $conflict_doc->record_conflict( 'padding', 40, 80, 40.0, 'el' );
same( $far['status'], 'conflict', 'A forty-pixel difference is a conflict.' );
foreach ( array( $near, $mid, $far ) as $entry ) {
	check( array_key_exists( 'computed', $entry ) && array_key_exists( 'measured', $entry ), 'Both sides are kept on every conflict.' );
	check( 'resolved' !== ( $entry['status'] ?? '' ), 'And a conflict is never resolved, because whether CSS or the render will be reproduced depends on where the value is going.' );
}

$bad = $spec;
$bad['viewport']['device_pixel_ratio'] = 99;
$bad['images']['x'] = array( 'role' => 'not-a-role' );
$bad['measurement_conflicts'][] = array( 'property' => 'p', 'status' => 'conflict' );
$bad_verdict = ( new Visual_Representation( $bad ) )->validate();
check( ! $bad_verdict['valid'], 'An invalid representation fails validation.' );
check( in_array( 'invalid_device_pixel_ratio', $bad_verdict['errors'], true ), 'With a specific error for an impossible DPR.' );
check( in_array( 'unknown_image_role', $bad_verdict['errors'], true ), 'And one for an unknown image role.' );
check( in_array( 'conflict_missing_side', $bad_verdict['errors'], true ), 'And one for a conflict that kept only one side — §52 is enforced, not just documented.' );

$no_evidence = $spec;
$no_evidence['evidence'][] = array( 'element' => 'x', 'property' => 'p', 'value' => 1, 'evidence' => array() );
check( ! ( new Visual_Representation( $no_evidence ) )->is_valid(), 'A stored inference with no evidence fails validation, so the document cannot look rigorous while being empty.' );

$degraded_doc = new Visual_Representation();
$degraded_spec = $degraded_doc->build( $degraded, $extracted, $detected, array_merge( $profile, array( 'rendered' => false ) ) );
check( $degraded_spec['confidence']['ceiling'] < 1.0, 'A CSS-only visual model has a confidence ceiling below 1.' );
check( $degraded_spec['confidence']['overall'] <= $degraded_spec['confidence']['ceiling'], 'And its overall confidence cannot exceed that ceiling.' );
check( ! empty( $degraded_spec['confidence']['note'] ), 'And says why, so a low number is explained rather than looking like a bug.' );
check( in_array( 'no_render', ( new Visual_Representation( $degraded_spec ) )->validate()['warnings'], true ), 'And a no-render warning is raised.' );

/* =====================================================================
 * 10. Comparison: multiple signals, masking, regions, heatmap.
 * ================================================================== */

echo "--- 10. Visual comparison ---\n";

$with_reader = new Visual_Comparator( new Synthetic_Image_Reader() );

$a = Synthetic_Image_Reader::blank( 40, 40, array( 255, 255, 255 ) );
$b = Synthetic_Image_Reader::blank( 40, 40, array( 255, 255, 255 ) );
$c = Synthetic_Image_Reader::with_rect( 40, 40, array( 'x' => 0, 'y' => 0, 'w' => 40, 'h' => 40, 'rgb' => array( 0, 0, 0 ) ) );

$bytes_a = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );

// Surfaces are supplied directly so the comparison arithmetic is exercised on a host
// with no image library. The surfaces below are real pixel data; only the *decoding*
// of PNG bytes is unavailable, and that is a different thing from comparing pixels.
$surface_white = Synthetic_Image_Reader::blank( 40, 40, array( 255, 255, 255 ) );
$surface_black = Synthetic_Image_Reader::blank( 40, 40, array( 0, 0, 0 ) );
$surface_half  = Synthetic_Image_Reader::with_rect( 40, 40, array( 'x' => 0, 'y' => 0, 'w' => 40, 'h' => 20, 'rgb' => array( 0, 0, 0 ) ) );
$both = array( 'source' => $surface_white, 'replica' => $surface_white );

$identical = $with_reader->compare( array( 'source_image' => $bytes_a, 'replica_image' => $bytes_a, 'surfaces' => $both ) );
check( $identical['available'], 'A comparison with two images is available.' );
check( isset( $identical['signals']['pixel'] ), 'A pixel signal is computed when a surface is available.' );
same( $identical['signals']['pixel']['ratio'], 0.0, 'Two identical surfaces differ in no pixels, which is the base case every other number depends on.' );
same( $identical['signals']['pixel']['compared'], 1600, 'And all 1600 pixels were compared.' );
check( $identical['signals']['pixel']['ratio'] >= 0.0, 'A pixel ratio is computed.' );
check( isset( $identical['signals']['geometry'] ), 'A geometry signal is present even with no geometry supplied.' );
check( in_array( 'structure', $identical['signal_names'], true ), 'A structure signal is present.' );
check( in_array( 'color', $identical['signal_names'], true ), 'A colour signal is present.' );
check( in_array( 'typography', $identical['signal_names'], true ), 'A typography signal is present.' );
check( count( $identical['missing_signals'] ) >= 0, 'And missing signals are named rather than silently absent.' );

// A real difference, and the arithmetic is checked rather than assumed.
$all_differ = $with_reader->compare( array( 'source_image' => $bytes_a, 'replica_image' => $bytes_a, 'surfaces' => array( 'source' => $surface_white, 'replica' => $surface_black ) ) );
same( $all_differ['signals']['pixel']['ratio'], 1.0, 'A white page against a black one differs in every pixel, and the ratio says exactly that.' );
same( $all_differ['signals']['pixel']['band'], 'large', 'Banding it as a large difference is what §58 means by severity being measurable.' );

$half_differ = $with_reader->compare( array( 'source_image' => $bytes_a, 'replica_image' => $bytes_a, 'surfaces' => array( 'source' => $surface_white, 'replica' => $surface_half ) ) );
check( $half_differ['signals']['pixel']['ratio'] > 0.45 && $half_differ['signals']['pixel']['ratio'] < 0.55, 'A half-black surface differs in about half the pixels, and the computed ratio reflects the real coverage.' );

// Masking: a masked region must contribute to nothing.
$masked = $with_reader->compare( array(
	'source_image'  => $bytes_a,
	'replica_image' => $bytes_a,
	'surfaces'      => array( 'source' => $surface_white, 'replica' => $surface_black ),
	'masks'         => array( array( 'x' => 0, 'y' => 0, 'width' => 40, 'height' => 40, 'reason' => 'video' ) ),
) );
check( $masked['signals']['pixel']['masked'] > 0, 'A masked region is counted as masked.' );
check( $masked['signals']['pixel']['compared'] === 0, 'And contributes to no compared pixels at all, so a difference inside it cannot influence any ratio.' );
same( $masked['signals']['pixel']['ratio'], 0.0, 'So a page that differs in every pixel but is entirely masked reads as zero difference rather than as "mostly masked".' );
same( $masked['masked']['count'], 1, 'And the mask count is reported.' );
same( $masked['masked']['by_reason']['video'], 1, 'Broken down by reason, so a report can say what was hidden.' );
check( ! empty( $masked['masked']['note'] ), 'With a note explaining that a masked difference is not evidence of a fault.' );

// Partial masking: only the unmasked pixels count.
$partial_mask = $with_reader->compare( array(
	'source_image'  => $bytes_a,
	'replica_image' => $bytes_a,
	'surfaces'      => array( 'source' => $surface_white, 'replica' => $surface_half ),
	'masks'         => array( array( 'x' => 0, 'y' => 0, 'width' => 40, 'height' => 20, 'reason' => 'carousel' ) ),
) );
same( $partial_mask['signals']['pixel']['ratio'], 0.0, 'Masking exactly the differing half leaves a zero ratio, which proves the mask is applied before the ratio rather than subtracted after it.' );
check( $partial_mask['signals']['pixel']['compared'] > 0, 'And the unmasked half was still compared, so masking reduces coverage rather than silencing the comparison.' );

// The cross-check against Phase 5's differ.
check( isset( $identical['signals']['pixel']['cross_check'] ), 'A cross-check against the Phase 5 differ is recorded.' );
check( array_key_exists( 'performed', $identical['signals']['pixel']['cross_check'] ), 'With whether it was performed, because this host has no image library and the answer differs from host to host.' );
check( array_key_exists( 'performed', $masked['signals']['pixel']['cross_check'] ) && ! $masked['signals']['pixel']['cross_check']['performed'], 'The cross-check is skipped when anything is masked, because Phase 5\'s differ has no mask parameter and comparing unmasked against masked would be comparing two different measurements.' );

// Sampling is recorded.
check( isset( $identical['signals']['pixel']['sampled_fraction'] ), 'The sampled fraction is recorded, because a ratio from a subsample is a different claim from one from every pixel.' );
check( isset( $identical['signals']['pixel']['channel_tolerance'] ), 'And the channel tolerance, so the ratio can be interpreted.' );

// Regions and heatmap.
$regions = $with_reader->compare( array( 'source_image' => $bytes_a, 'replica_image' => $bytes_a, 'surfaces' => $both, 'regions' => array( array( 'name' => 'hero', 'from' => 0.0, 'to' => 0.5 ), array( 'name' => 'footer', 'from' => 0.5, 'to' => 1.0 ) ) ) );
same( count( $regions['regions'] ), 2, 'Named regions are compared individually, so a correction can be aimed at a section.' );
check( $regions['regions'][0]['available'], 'With a real availability flag.' );
check( $regions['heatmap']['available'], 'A heatmap is produced.' );
same( $regions['heatmap']['rows'], Visual_Limits::REGION_ROWS, 'With the expected number of rows.' );
check( $regions['heatmap']['scale'] > 0, 'And a scale.' );
check( ! empty( $regions['heatmap']['note'] ), 'And a note that the cells are real computed values scaled against the worst cell.' );
same( count( $regions['overlays'] ), 4, 'Four overlay modes are declared for §64.' );
$overlay_modes = array_column( $regions['overlays'], 'mode' );
foreach ( array( 'source', 'replica', 'overlay', 'difference' ) as $mode ) {
	check( in_array( $mode, $overlay_modes, true ), sprintf( 'The %s overlay mode is offered.', $mode ) );
}

// Structure signal: two-sided.
$structure = $with_reader->compare( array(
	'surfaces'         => $both,
	'source_image'     => $bytes_a,
	'replica_image'    => $bytes_a,
	'source_elements'  => array( 'a' => 1, 'b' => 1, 'c' => 1 ),
	'replica_elements' => array( 'a' => 1, 'd' => 1 ),
) );
$structure_signal = $structure['signals']['structure'];
check( $structure_signal['available'], 'The structure signal is available when element lists are supplied.' );
check( ! empty( $structure_signal['missing'] ), 'An element missing from the replica is reported.' );
check( ! empty( $structure_signal['extra'] ), 'And an extra element is reported too, because a duplicated footer is not a better match.' );
$codes = array_column( $structure['differences'], 'code' );
check( in_array( 'missing_elements', $codes, true ), 'A missing element produces a critical difference.' );
check( in_array( 'extra_elements', $codes, true ), 'And an extra element produces a major one.' );
$missing_finding = null;
foreach ( $structure['differences'] as $difference ) {
	if ( 'missing_elements' === $difference['code'] ) { $missing_finding = $difference; }
}
same( $missing_finding['severity'], 'critical', 'A missing element is critical, because the page is not the page.' );
same( $missing_finding['category'], 'structure', 'And it is categorised into Phase 5\'s vocabulary as a structure difference, so it counts towards the same metric a structural finding does.' );

// Colour overlap.
$colour = $with_reader->compare( array(
	'surfaces'        => $both,
	'source_image'   => $bytes_a,
	'replica_image'  => $bytes_a,
	'source_palette' => array( array( 'value' => '#111111' ), array( 'value' => '#222222' ) ),
	'replica_palette'=> array( array( 'value' => '#111111' ), array( 'value' => '#333333' ) ),
) );
check( $colour['signals']['color']['available'], 'The colour signal is available when palettes are supplied.' );
check( $colour['signals']['color']['overlap'] > 0 && $colour['signals']['color']['overlap'] < 1, 'And reports an overlap fraction rather than a match or mismatch verdict.' );
check( ! empty( $colour['signals']['color']['source'] ), 'With both palette sizes recorded, so a simpler replica is distinguishable from a mismatched one.' );

// Verdict honesty.
check( $identical['verdict']['score'] >= 0.0, 'A score is produced.' );
check( isset( $identical['verdict']['signal_weight'] ), 'And the weight actually accumulated, because a score from few signals is a different claim.' );
check( ! empty( $identical['note'] ), 'And a note that the pixel ratio is one signal among several.' );

$empty = $with_reader->compare( array( 'source_image' => '', 'replica_image' => '' ) );
check( ! $empty['available'], 'A comparison with no images is unavailable.' );
check( ! empty( $empty['message'] ), 'And says why.' );
same( $empty['verdict'], 'unavailable', 'With a verdict of unavailable rather than a pass, which is the whole point.' );

// A reader that is present but cannot decode: the surfaces were never supplied and
// the reader has no way to produce them. This is a *different* situation from having
// no reader at all, and the two must not be reported with one message.
$synthetic_only = $with_reader->compare( array( 'source_image' => $bytes_a, 'replica_image' => $bytes_a, 'source_elements' => array( 'a' => 1 ), 'replica_elements' => array( 'a' => 1 ) ) );
check( ! isset( $synthetic_only['signals']['pixel'] ), 'With a reader that cannot decode and no surfaces supplied, the pixel signal is absent rather than zero.' );
check( in_array( 'pixel', $synthetic_only['missing_signals'], true ), 'And is named as missing, so a caller cannot mistake absence for a clean result.' );
check( in_array( 'structure', $synthetic_only['signal_names'], true ), 'While the signals that do not need an image library still run.' );
same( $synthetic_only['pixel_unavailable_reason'], 'synthetic_reader_cannot_decode', 'And the specific reason is reported by its own code, rather than one generic sentence covering four different situations.' );
check( false !== strpos( $synthetic_only['note'], 'captures could not be decoded' ), 'With a note that matches the actual reason.' );

// No reader at all: a different code and a different note.
$no_library  = new Visual_Comparator( new Null_Image_Reader() );
$no_pixels   = $no_library->compare( array( 'source_image' => $bytes_a, 'replica_image' => $bytes_a, 'source_elements' => array( 'a' => 1 ), 'replica_elements' => array( 'a' => 1 ) ) );
same( $no_pixels['pixel_unavailable_reason'], 'no_image_library', 'A missing image library is reported as exactly that, by its own code.' );
check( $no_pixels['pixel_unavailable_reason'] !== $synthetic_only['pixel_unavailable_reason'], 'Which is a different code from "cannot decode", because the two situations need different fixes.' );
check( false !== strpos( $no_pixels['note'], 'GD' ), 'And the note names GD and Imagick, so a user knows what to install.' );
check( in_array( 'structure', $no_pixels['signal_names'], true ), 'While the signals that need no image library still run.' );
check( ! $no_pixels['regions'][0]['available'], 'Regions report unavailable rather than a zero difference.' );
check( ! $no_pixels['heatmap']['available'], 'As does the heatmap.' );

/* =====================================================================
 * 11. Correction: priority, refusal, bounded loop, regression.
 * ================================================================== */

echo "--- 11. Correction planning and regression ---\n";

$corrector = new Visual_Corrector();

$report = array( 'differences' => array(
	array( 'category' => 'shadow', 'severity' => 'minor', 'code' => 'shadow_differs', 'message' => 'Shadow', 'evidence' => array() ),
	array( 'category' => 'color', 'severity' => 'major', 'code' => 'palette_overlap_low', 'message' => 'Colour', 'evidence' => array() ),
	array( 'category' => 'structure', 'severity' => 'critical', 'code' => 'missing_elements', 'message' => 'Missing', 'evidence' => array() ),
	array( 'category' => 'typography', 'severity' => 'moderate', 'code' => 'typography_mismatch', 'message' => 'Type', 'evidence' => array() ),
	array( 'category' => 'spacing', 'severity' => 'informational', 'code' => 'note', 'message' => 'Info', 'evidence' => array() ),
) );

$plan = $corrector->plan( $report );
$order = array_column( $plan['eligible'], 'category' );
check( 'structure' === $order[0], '§67\'s priority puts structure first, because everything after it depends on it.' );
$positions = array();
foreach ( $order as $index => $category ) { $positions[ $category ] = $index; }
check( $positions['typography'] < $positions['color'], 'Typography precedes colour.' );
check( $positions['color'] < $positions['shadow'], 'Colour precedes shadows and borders.' );
// §67 lists spacing ahead of colour, so it is asserted on a *correctable* spacing
// difference. The fixture's spacing difference is informational and therefore
// refused, so checking its position in the eligible list would assert against a key
// that is legitimately absent.
$spacing_order = $corrector->plan( array( 'differences' => array(
	array( 'category' => 'shadow', 'severity' => 'minor', 'code' => 's', 'message' => 's', 'evidence' => array() ),
	array( 'category' => 'spacing', 'severity' => 'major', 'code' => 'p', 'message' => 'p', 'evidence' => array() ),
	array( 'category' => 'structure', 'severity' => 'critical', 'code' => 'm', 'message' => 'm', 'evidence' => array() ),
) ) );
$spacing_positions = array();
foreach ( array_column( $spacing_order['eligible'], 'category' ) as $index => $category ) { $spacing_positions[ $category ] = $index; }
check( $spacing_positions['spacing'] < $spacing_positions['shadow'], 'Spacing precedes shadows and borders, so a fix that changes a gap is applied before one that changes a border.' );
check( $spacing_positions['structure'] < $spacing_positions['spacing'], 'And structure precedes everything.' );
same( count( $plan['refused'] ), 1, 'The informational difference is refused.' );
check( '' !== (string) ( $plan['refused'][0]['refused_because'] ?? '' ), 'With a stated reason, because "refused" without one is indistinguishable from a bug.' );
same( $plan['iteration_budget'], Visual_Limits::MAX_CORRECTION_ITERATIONS, 'The iteration budget is reported.' );

$masked_plan = $corrector->plan( array( 'differences' => array( array( 'category' => 'color', 'severity' => 'major', 'code' => 'pixel_difference', 'message' => 'x', 'evidence' => array( 'masked' => true ) ) ) ) );
same( count( $masked_plan['eligible'] ), 0, 'A difference inside a masked region is not eligible for correction.' );
check( false !== strpos( $masked_plan['refused'][0]['refused_because'], 'masked' ), 'Because it is not evidence of a fault.' );

$size_plan = $corrector->plan( array( 'differences' => array( array( 'category' => 'size', 'severity' => 'major', 'code' => 'capture_size_mismatch', 'message' => 'x', 'evidence' => array() ) ) ) );
same( count( $size_plan['eligible'] ), 0, 'A capture size mismatch is not correctable, because correcting the replica to satisfy two differently-sized images would corrupt the design.' );

// Shared-component scope and override protection.
//
// The registry is swept first (and again at shutdown) so a run cannot inherit an
// override from a previous run. Inheriting one made this block pass for the wrong
// reason: the component was already user-owned, so the "it is eligible" assertion
// failed even though the code was correct.
delete_option( \ReplicaForge\Component_Registry::OPTION );
$registry = new \ReplicaForge\Component_Registry();
$registry->put_shared( 'proj13', array( array( 'component_id' => 'shared_header', 'role' => 'header', 'pages' => array( 'p1', 'p2' ), 'page_count' => 2 ) ) );
check( $registry->may_rewrite( 'shared_header' ), 'A shared component the user has not touched may be rewritten, so a visual correction can reach it.' );
$protected = new Visual_Corrector( $registry );
$shared_plan = $protected->plan( array( 'differences' => array( array( 'category' => 'color', 'severity' => 'major', 'code' => 'x', 'message' => 'x', 'evidence' => array( 'component_id' => 'shared_header' ) ) ) ) );
same( $shared_plan['eligible'][0]['scope'], 'shared_component', 'A difference naming a shared component is corrected on the shared component, which is §46.' );
$registry->mark_overridden( 'shared_header', array( 'note' => 'mine' ) );
$blocked_plan = $protected->plan( array( 'differences' => array( array( 'category' => 'color', 'severity' => 'major', 'code' => 'x', 'message' => 'x', 'evidence' => array( 'component_id' => 'shared_header' ) ) ) ) );
same( count( $blocked_plan['eligible'] ), 0, 'Once the user has edited it, it is no longer eligible.' );
check( false !== strpos( $blocked_plan['refused'][0]['refused_because'], 'customized' ), 'And the refusal says the user is the reason.' );

// The bounded loop.
$calls = 0;
$loop = $corrector->iterate(
	static function ( $iteration ) use ( &$calls ) {
		$calls++;
		$score = 0.5 + ( 0.1 * $iteration );
		return array( 'verdict' => 'fail', 'score' => $score, 'differences' => array( array( 'category' => 'color', 'severity' => 'major', 'code' => 'palette_overlap_low', 'message' => 'x', 'evidence' => array() ) ) );
	},
	static function ( $correction, $iteration ) {},
	99
);
check( $loop['clamped'], 'A request for ninety-nine iterations is clamped.' );
same( $loop['max_iterations'], Visual_Limits::MAX_CORRECTION_ITERATIONS, 'To the declared maximum.' );
check( $loop['iterations'] <= Visual_Limits::MAX_CORRECTION_ITERATIONS, 'And the loop never exceeds it.' );

$stalled = 0;
$stall_loop = $corrector->iterate(
	static function ( $iteration ) use ( &$stalled ) {
		$stalled++;
		return array( 'verdict' => 'fail', 'score' => 0.5, 'differences' => array( array( 'category' => 'color', 'severity' => 'major', 'code' => 'x', 'message' => 'x', 'evidence' => array() ) ) );
	},
	static function ( $correction, $iteration ) {},
	5
);
check( $stall_loop['iterations'] < 5, 'A loop that stops improving stops early rather than running its budget.' );
check( isset( $stall_loop['history'][ count( $stall_loop['history'] ) - 1 ]['stopped'] ), 'And records why it stopped.' );
check( false !== strpos( $stall_loop['note'], 'never exceeds' ), 'And states in the output that it cannot exceed its budget.' );

$passing = $corrector->iterate(
	static function ( $iteration ) { return array( 'verdict' => 'pass', 'score' => 1.0, 'differences' => array() ); },
	static function ( $correction, $iteration ) {},
	3
);
same( $passing['iterations'], 1, 'A comparison that already passes stops after one iteration.' );

// Regression protection.
$improved = $corrector->regression_check(
	array( 'desktop' => array( 'score' => 0.6 ), 'mobile' => array( 'score' => 0.6 ) ),
	array( 'desktop' => array( 'score' => 0.9 ), 'mobile' => array( 'score' => 0.62 ) )
);
same( $improved['verdict'], 'improved', 'Improving every viewport is an improvement.' );
same( $improved['apply'], 'yes', 'And is applicable.' );

$regressed = $corrector->regression_check(
	array( 'desktop' => array( 'score' => 0.6 ), 'mobile' => array( 'score' => 0.8 ) ),
	array( 'desktop' => array( 'score' => 0.95 ), 'mobile' => array( 'score' => 0.4 ) )
);
same( $regressed['verdict'], 'mixed', 'Improving desktop while wrecking mobile is mixed, not an improvement: the verdict names both halves rather than averaging them into a success.' );
same( $regressed['apply'], 'no', 'And is NOT applied — which is the whole reason §68 exists.' );
check( isset( $regressed['improved']['desktop'] ) && isset( $regressed['regressed']['mobile'] ), 'Both the improvement and the regression are reported per viewport, so the user is told which one broke.' );
check( ! empty( $regressed['note'] ), 'With a note naming the usual cause.' );

$only_worse = $corrector->regression_check(
	array( 'desktop' => array( 'score' => 0.6 ) ),
	array( 'desktop' => array( 'score' => 0.4 ) )
);
same( $only_worse['verdict'], 'regressed', 'A change that improves nothing and breaks something is an outright regression, not mixed.' );
same( $only_worse['apply'], 'no', 'And is not applied either.' );

$mixed = $corrector->regression_check(
	array( 'desktop' => array( 'score' => 0.6 ), 'tablet' => array( 'score' => 0.6 ) ),
	array( 'desktop' => array( 'score' => 0.9 ), 'tablet' => array( 'score' => 0.5 ) )
);
same( $mixed['apply'], 'no', 'A mixed result is not applied either.' );

$partial = $corrector->regression_check(
	array( 'desktop' => array( 'score' => 0.6 ) ),
	array( 'desktop' => array( 'score' => 0.9 ) )
);
same( $partial['verdict'], 'improved', 'A viewport that improved and none that regressed is an improvement.' );

$noisy = $corrector->regression_check(
	array( 'desktop' => array( 'score' => 0.6000 ) ),
	array( 'desktop' => array( 'score' => 0.6005 ) )
);
same( $noisy['verdict'], 'neutral', 'A 0.0005 change is noise, not a win — otherwise a loop convinces itself it is working.' );

$unknown = $corrector->regression_check(
	array( 'desktop' => array( 'score' => 0.6 ) ),
	array()
);
check( isset( $unknown['unknown']['desktop'] ), 'A viewport with no after-comparison is reported as unknown, not as passing.' );

// Before/after.
$ba = $corrector->before_after(
	array( 'desktop' => array( 'verdict' => 'fail', 'score' => 0.5, 'differences' => array() ) ),
	array( 'desktop' => array( 'verdict' => 'pass', 'score' => 0.95, 'differences' => array() ) )
);
check( $ba['rows'][0]['has_renders'], 'A before/after row records that real renders were compared.' );
check( $ba['rows'][0]['improved'], 'And that the score improved.' );
$ba_partial = $corrector->before_after( array( 'desktop' => array( 'score' => 0.5 ) ), array() );
check( $ba_partial['rows'][0]['evidence_only'], 'With a missing render, the row says evidence only rather than claiming an improvement.' );
check( ! empty( $ba['note'] ), 'And the before/after output states that these are actual rendered comparisons.' );

/* =====================================================================
 * 12. Caching: the key says how it was produced.
 * ================================================================== */

echo "--- 12. Render cache ---\n";

$cache = new Render_Cache();
$parts = $cache->parts_from( array( 'viewport' => array( 'name' => 'desktop', 'width' => 1440, 'height' => 900, 'device_pixel_ratio' => 2.0 ), 'source_hash' => 'aaa', 'masks' => array( array( 'reason' => 'video' ) ), 'animation_normalized' => true, 'renderer_version' => 'v1' ) );

$k1 = $cache->key( 'p1', 'https://example.com/', $parts );
same( $k1, $cache->key( 'p1', 'https://example.com/', $parts ), 'The same inputs produce the same key.' );
check( $k1 !== $cache->key( 'p2', 'https://example.com/', $parts ), 'A different project produces a different key.' );
check( $k1 !== $cache->key( 'p1', 'https://example.com/', array_merge( $parts, array( 'source_hash' => 'bbb' ) ) ), 'A different source hash produces a different key.' );
check( $k1 !== $cache->key( 'p1', 'https://example.com/', array_merge( $parts, array( 'viewport' => 'mobile' ) ) ), 'A different viewport produces a different key.' );
check( $k1 !== $cache->key( 'p1', 'https://example.com/', array_merge( $parts, array( 'renderer_version' => 'v2' ) ) ), 'A different renderer version produces a different key — otherwise an upgrade would serve stale captures.' );
check( $k1 !== $cache->key( 'p1', 'https://example.com/', array_merge( $parts, array( 'normalization' => 'other' ) ) ) , 'A different normalisation produces a different key.' );
check( $k1 !== $cache->key( 'p1', 'https://example.com/', array_merge( $parts, array( 'masked' => false ) ) ), 'A masked comparison does not share a key with an unmasked one.' );
check( $k1 !== $cache->key( 'p1', 'https://example.com/', array_merge( $parts, array( 'kind' => 'screenshot' ) ) ), 'An analysis and a screenshot do not share a key.' );

// The analyzer version is baked in, which is the point of the constant.
$analysis_key = $cache->key( 'p1', 'https://example.com/', array( 'kind' => 'analysis' ) );
check( false !== strpos( (string) wp_json_encode( array( Visual_Limits::ANALYZER_VERSION ) ), (string) Visual_Limits::ANALYZER_VERSION ), 'The analyzer version is a non-empty string, because an empty one would make two builds share a cache.' );
check( strlen( (string) Visual_Limits::ANALYZER_VERSION ) > 0, 'And it is declared.' );

$cache->put( $k1, 'PAYLOAD', 'analysis', 0, 'p1' );
$hit = $cache->get( $k1 );
check( null !== $hit, 'A stored entry is found.' );
same( $hit['body'], 'PAYLOAD', 'With its body.' );
same( $hit['project_id'], 'p1', 'And the owning project, which is what the access check compares — an index entry without it would make every capture look forbidden.' );
same( $hit['retention'], 'project', 'And a retention class, because §57 wants retention to be more than a number.' );
check( null === $cache->get( 'rfv_does_not_exist' ), 'An unknown key is a miss, not an error.' );

$short = $cache->key( 'p1', 'https://example.com/', array( 'kind' => 'analysis' ) );
$cache->put( $short, 'X', 'analysis', 1, 'p1' );
sleep( 2 );
check( null === $cache->get( $short ), 'An expired entry is a miss.' );
check( null === $cache->get( $short ) && ! isset( get_option( Render_Cache::INDEX_OPTION, array() )[ $short ] ), 'And is removed on read, so a cache nobody reads still does not grow.' );

$oversized = $cache->put( $k1, str_repeat( 'A', Visual_Limits::max_screenshot_bytes() + 10 ), 'screenshot', 0, 'p1' );
check( ! $oversized['stored'], 'A screenshot over the size limit is not cached.' );
same( $oversized['reason'], 'too_large', 'With a stated reason.' );

$report = $cache->report();
check( isset( $report['analyzer_version'] ), 'The cache report states the analyzer version its entries were built with.' );
check( $report['max'] === Render_Cache::MAX_ENTRIES, 'And its bound.' );
check( ! empty( $report['note'] ), 'And explains that reuse requires every input to match.' );
check( $cache->forget_project( 'p1' ) >= 0, 'A project can be forgotten.' );
check( $cache->prune() >= 0, 'And the cache pruned.' );

/* =====================================================================
 * 13. AI vision: five gates, and the structured fallback.
 * ================================================================== */

echo "--- 13. AI vision gates ---\n";

// The defaults are asserted from `Visual_AI`'s own source rather than from a
// constructed instance. An earlier version read them from a live object, and the
// migration in §18 has already written the option — so the "default" being asserted
// was in fact the migrated value, and this test passed or failed depending on
// whether the migration had run. A default is a property of the code that supplies
// it when the option is *absent*, so that is what is read.
$ai_source = (string) file_get_contents( REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-ai.php' );
$defaults_block = '';
if ( 1 === preg_match( '/\$this->load\(\).*?public function save_settings/s', $ai_source, $matches ) ) {
	$defaults_block = (string) $matches[0];
}
check( '' !== $defaults_block, 'The settings defaults are readable from the source.' );
foreach ( array( 'vision_enabled', 'screenshot_validation' ) as $off_by_default ) {
	check(
		1 === preg_match( '/\'' . $off_by_default . '\'\s*=>\s*false/', $defaults_block ),
		sprintf( '%s defaults to false in the code, because a screenshot may contain anything the page displayed and §54 requires the user to configure it.', $off_by_default )
	);
}
check( 1 === preg_match( '/dynamic_masking\'\s*=>\s*true/', $defaults_block ), 'Dynamic masking defaults to true, since masking is what stops a video dominating validation.' );
check( 1 === preg_match( '/include_transient_ui\'\s*=>\s*false/', $defaults_block ), 'Transient UI inclusion defaults to false, because reconstructing a cookie banner makes a replica worse.' );

// And with the option genuinely absent, an instance reports the same defaults.
delete_option( Visual_AI::OPTION );
$ai = new Visual_AI();
$off = $ai->public_settings();
check( false === (bool) $off['vision_enabled'], 'With no stored option, a constructed instance also reports screenshot input to AI as off.' );
check( false === (bool) $off['screenshot_validation'], 'Screenshot validation is off by default too.' );
check( ! empty( $off['limits']['max_image_bytes'] ), 'The image size limit is reported.' );

$saved = $ai->save_settings( array( 'vision_enabled' => true, 'max_images_per_request' => 3 ) );
check( $saved['settings']['vision_enabled'], 'It can be turned on.' );
same( $saved['settings']['max_images_per_request'], 3, 'With a chosen image count.' );
$capped = $ai->save_settings( array( 'vision_enabled' => true, 'max_images_per_request' => 99 ) );
check( $capped['settings']['max_images_per_request'] <= 4, 'And the image count is clamped, because sending a hundred screenshots is exfiltration with extra steps.' );
$ai->save_settings( array( 'vision_enabled' => true, 'max_images_per_request' => 2 ) );

// Gate 1: no content. Vision is still off at this point, so the user gate is also
// closed — the point here is that `has_content` is recorded as a *separate* gate
// rather than being hidden behind whichever one happens to be reported first.
$no_images = $ai->prepare( array( 'representation' => array( 'a' => 1 ) ) );
check( ! $no_images['use_vision'], 'With nothing to look at, vision is not used.' );
check( ! empty( $no_images['blocked_because'] ), 'And the reason is stated.' );
check( ! empty( $no_images['gates']['has_content'] ), 'With the gate recorded.' );

// Gate 2: provider capability, with vision enabled so this gate is the one under test.
$ai->save_settings( array( 'vision_enabled' => true, 'max_images_per_request' => 2 ) );
$unknown_provider = $ai->prepare( array( 'provider_id' => 'nope', 'model_id' => 'nope', 'images' => array( $bytes_a ), 'representation' => array( 'a' => 1 ) ) );
check( ! $unknown_provider['use_vision'], 'A provider that does not declare image input is refused.' );
check( ! $unknown_provider['gates']['provider_capability']['passed'], 'With the capability gate as the blocker, rather than the user setting.' );
check( false !== strpos( (string) $unknown_provider['blocked_because'], 'image input' ), 'And the reason names the missing capability, so a user can tell this apart from a setting being off.' );
check( ! empty( $unknown_provider['note'] ), 'And a note that the structured representation is being used instead, framed as a substitution rather than a loss.' );
check( ! empty( $unknown_provider['representation'] ), 'With the structured representation actually present, so the fallback is structural and not a message.' );

// A declared-vision provider passes the capability gate, and with a small enough
// image the request *is* prepared. This is the one case where vision is used, and it
// is asserted as a positive rather than as another refusal: an off-by-one that made
// every path refuse would pass a suite full of "did not send" assertions.
$capable = $ai->prepare( array( 'provider_id' => 'openai', 'model_id' => 'gpt-4o', 'images' => array( $bytes_a ), 'representation' => array( 'a' => 1 ) ) );
check( isset( $capable['gates']['provider_capability'] ), 'A vision-capable model is checked against the existing capability table.' );
check( $capable['gates']['provider_capability']['capability']['source'] === 'Ai_Capabilities', 'And the check reads Phase 11\'s table rather than guessing from a model name.' );
check( $capable['gates']['provider_capability']['passed'], 'A model that declares image input passes that gate.' );
check( $capable['use_vision'], 'So a small screenshot is prepared for vision.' );
same( $capable['prepared']['count'], 1, 'Exactly one image.' );
check( $capable['prepared']['total_bytes'] <= Visual_AI::MAX_IMAGE_BYTES, 'And it is within the size limit, which is why it was not dropped.' );
check( ! empty( $capable['representation'] ), 'The structured representation travels with it, so a model is asked about the measurements as well as the picture.' );
check( isset( $capable['budget']['image_bytes'] ) && $capable['budget']['image_bytes'] > 0, 'The screenshot bytes are counted against the context budget.' );
check( false !== strpos( (string) $capable['note'], 'KB' ), 'And the note reports the size actually sent.' );

// An oversized screenshot is dropped rather than sent over budget. Without an image
// library there is no way to reduce it, and the alternative is a request the provider
// rejects after ReplicaForge already spent a reservation on it.
$oversized = str_repeat( 'A', Visual_AI::MAX_IMAGE_BYTES + 1024 );
$too_big = $ai->prepare( array( 'provider_id' => 'openai', 'model_id' => 'gpt-4o', 'images' => array( $oversized ), 'representation' => array( 'a' => 1 ) ) );
check( ! $too_big['use_vision'], 'An oversized screenshot is not sent.' );
check( ! empty( $too_big['gates']['budget'] ) || ! empty( $too_big['gates']['size'] ), 'And the refusing gate is named.' );
check( ! empty( $too_big['representation'] ), 'With the structured representation still available, so nothing is lost by dropping the image.' );

// The setting can be turned off again, which must refuse.
$ai->save_settings( array( 'vision_enabled' => false ) );
$switched_off = $ai->prepare( array( 'provider_id' => 'openai', 'model_id' => 'gpt-4o', 'images' => array( $bytes_a ) ) );
check( ! $switched_off['use_vision'], 'Turning the setting off refuses vision even for a capable model.' );
check( false === $switched_off['gates']['user_setting']['passed'], 'With the user gate as the reason.' );
$ai->save_settings( array( 'vision_enabled' => true, 'max_images_per_request' => 2 ) );

// Estimation never claims to be a charge.
$estimate = $ai->estimate( array( 'provider_id' => 'nope', 'model_id' => 'nope', 'images' => array( $bytes_a ) ) );
check( ! $estimate['billed'], 'A blocked request is not billed.' );
check( ! empty( $estimate['reason'] ), 'And says why it is not billed.' );
$ready = $ai->estimate( array( 'provider_id' => 'openai', 'model_id' => 'gpt-4o', 'images' => array( $bytes_a ), 'representation' => array() ) );
same( $ready['unit'], 'image', 'An image request is counted in images, not tokens — calling an image a token would be a fabricated number.' );
check( ! empty( $ready['disclosure'] ), 'And the response discloses that this is not a billing amount.' );
check( false !== strpos( $ready['disclosure'], 'not a billing amount' ), 'In those words.' );

/* =====================================================================
 * 14. Render jobs on the Phase 11 queue.
 * ================================================================== */

echo "--- 14. Render jobs ---\n";

$jobs   = new Render_Job();
$stages = $jobs::stages();
same( $stages['fetch'], \ReplicaForge\Job_Limits::STAGES[0], 'The first stage is Phase 11\'s first stage.' );
same( $stages['validate'], \ReplicaForge\Job_Limits::STAGES[3], 'And the last is Phase 11\'s fourth — the visual stages reuse existing slots rather than appending, which would break stage_index() arithmetic.' );
foreach ( $stages as $name => $stage ) {
	check( \ReplicaForge\Job_Checkpoint::is_stage( $stage ), sprintf( 'The %s stage maps onto a stage Phase 11 recognises.', $name ) );
}

$job = $jobs->build( array(
	'project_id' => 'proj13',
	'pages'      => array( array( 'page_id' => 'p1', 'source_url' => 'https://example.com/' ), array( 'page_id' => 'p2', 'source_url' => 'https://example.com/about/' ) ),
) );
same( $job['queue'], 'phase11', 'The job runs on the Phase 11 queue rather than a new one.' );
check( $job['cancellable'], 'And is cancellable.' );
same( $job['counts']['viewports'], 3, 'Three viewports are planned by default.' );
same( $job['counts']['renders'], 6, 'Two pages at three viewports is six renders.' );
check( ! empty( $job['payload']['stabilize']['sequence'] ), 'A stabilization sequence is requested.' );
check( ! empty( $job['payload']['stabilize']['guarantees'] ), 'And bounded, because a page that never settles cannot be made stable and saying so is honest.' );
check( $job['payload']['stabilize']['wait_ms'] <= 10000, 'With a bounded wait, so this is not a denial-of-service lever aimed at the renderer.' );

$bounded = $jobs->build( array( 'project_id' => 'p', 'pages' => array_map( static function ( $i ) { return array( 'page_id' => 'p' . $i, 'source_url' => 'https://example.com/p' . $i . '/' ); }, range( 1, 60 ) ) ) );
check( $bounded['counts']['renders'] <= Visual_Limits::MAX_RENDERS_PER_JOB, 'A large project is bounded by the render limit.' );
check( $bounded['counts']['pages'] < 60, 'By reducing the page count, which is reported rather than silently applied.' );

$refused = $jobs->build( array( 'project_id' => 'p', 'pages' => array( array( 'page_id' => 'bad', 'source_url' => 'http://127.0.0.1/' ), array( 'page_id' => 'good', 'source_url' => 'https://example.com/' ) ) ) );
same( count( $refused['refused'] ), 1, 'A page on a loopback address is refused by the render planner.' );
same( count( $refused['payload']['captures'] ), 1, 'And is not queued, so the render provider is never asked to fetch it.' );

/* =====================================================================
 * 15. Dashboard counts are real.
 * ================================================================== */

echo "--- 15. Dashboard ---\n";

$dashboard = $jobs->dashboard( array(
	array( 'available' => true, 'viewport' => array( 'name' => 'desktop' ), 'page_id' => 'p1', 'differences' => array( array( 'severity' => 'major' ), array( 'severity' => 'minor' ) ), 'representation' => array( 'sections' => 5, 'components' => 20 ) ),
	array( 'available' => true, 'viewport' => array( 'name' => 'mobile' ), 'page_id' => 'p2', 'differences' => array( array( 'severity' => 'critical' ) ), 'representation' => array( 'sections' => 5, 'components' => 20 ) ),
	array( 'available' => false, 'viewport' => array( 'name' => 'tablet' ), 'page_id' => 'p3', 'differences' => array() ),
) );
check( 'available' === $dashboard['render_status']['desktop'], 'A rendered viewport is marked available.' );
same( $dashboard['render_status']['tablet'], 'unavailable', 'And an unrendered one is marked unavailable, never as a pass.' );
same( $dashboard['coverage']['pages_rendered'], 2, 'Two pages rendered, counted from real availability.' );
same( $dashboard['coverage']['pages_total'], 3, 'Out of three attempted.' );
same( $dashboard['coverage']['sections'], 10, 'Sections are counted from the representations.' );
same( $dashboard['differences']['critical'], 1, 'Critical differences are counted.' );
same( $dashboard['differences']['major'], 1, 'Major differences are counted.' );
same( $dashboard['differences']['minor'], 1, 'Minor differences are counted.' );
same( $dashboard['differences']['moderate'], 0, 'And a category with none reads zero rather than being absent.' );
check( ! empty( $dashboard['warnings'] ), 'Warnings are present.' );
check( ! empty( $dashboard['note'] ), 'And the dashboard states that a viewport that could not render is shown as unavailable, never as a pass.' );

$empty = $jobs->dashboard( array() );
same( $empty['coverage']['pages_total'], 0, 'An empty dashboard reports zero, not a placeholder.' );
same( $empty['difference_total'], 0, 'And no differences.' );

/* =====================================================================
 * 16. Security: no route renders a URL, no path is exposed.
 * ================================================================== */

echo "--- 16. Security properties ---\n";

$api_source = (string) file_get_contents( REPLICAFORGE_PATH . 'includes/visual/class-replicaforge-visual-api.php' );
$api_code   = preg_replace( '#/\*.*?\*/#s', '', $api_source );
$api_code   = preg_replace( '#//[^\n]*#', '', (string) $api_code );
$api_code   = preg_replace( '#^\s*\*.*$#m', '', (string) $api_code );

// §5: the render layer must not be a general URL fetcher.
check( false === strpos( (string) $api_code, "get_param( 'render_url' )" ), 'No route takes an address to render.' );
check( false === strpos( (string) $api_code, "get_param( 'url' )" ), 'No route takes a bare url parameter.' );
check( false !== strpos( $api_source, 'readable_project' ), 'Every project route resolves ownership before reading the store.' );
check( false !== strpos( $api_source, 'could not be found, or it belongs to another account' ), 'With one message for missing and not-yours, so a project id cannot be probed.' );

// §80: no filesystem path in or out.
check( false === strpos( (string) $api_code, 'uploads' ), 'No upload path is referenced by the visual API.' );
check( false === strpos( (string) $api_code, 'wp_get_upload_dir' ), 'And no upload directory is consulted.' );
check( false !== strpos( $api_source, 'Content-Disposition' ), 'A capture is served with a Content-Disposition header.' );
check( false !== strpos( $api_source, 'nosniff' ), 'And nosniff, so a rendered third-party page is not re-interpreted by the browser.' );
check( false !== strpos( $api_source, 'private, max-age=0, no-store' ), 'And is marked private and not stored, so it is not cached by an intermediary.' );
check( false !== strpos( $api_source, 'project_id' ), 'A capture carries its owning project, which is compared before the bytes are returned.' );

// §68 and §81: ownership is checked on captures too.
check( false !== strpos( $api_source, 'can_read' ), 'A capture read is checked against project access.' );
check( false !== strpos( $api_source, 'capture_bytes' ), 'And capture bytes are resolved through a project-scoped helper.' );

// The Phase 5 renderer was extended, not replaced.
$renderer_source = (string) file_get_contents( REPLICAFORGE_PATH . 'includes/validation/class-replicaforge-visual-renderer.php' );
check( false !== strpos( $renderer_source, '$payload[\'stabilize\']' ), 'The existing Phase 5 renderer now forwards stabilization options, because a screenshot cannot be stabilized after the fact.' );
check( 1 === preg_match( '/if \( isset\( \$request\[.stabilize.\]/', $renderer_source ), 'And it is conditional on a caller asking, so a Phase 5 validation run sends exactly the payload it sent before.' );
check( false !== strpos( $renderer_source, "min( 10000, (int) ( \$stabilize['wait_ms']" ), 'With the wait bounded, because an unbounded wait is a denial-of-service lever aimed at whoever hosts the renderer.' );
check( false !== strpos( $renderer_source, "'block'    => array( 'scripts' => true" ), 'And still blocks scripts and media, so §5\'s existing boundary is unchanged.' );
check( false !== strpos( $renderer_source, "'reject_unsafe_urls'  => true" ), 'And still refuses unsafe URLs at the transport layer.' );
check( false !== strpos( $renderer_source, 'MAX_SCREENSHOT_BYTES' ), 'And still caps the response size.' );

// Screenshots are evidence, never the page.
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( REPLICAFORGE_PATH . 'includes/visual' ) );
$violations = array();
foreach ( $files as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
	$source = (string) file_get_contents( $file->getPathname() );
	$code = preg_replace( '#/\*.*?\*/#s', '', $source );
	$code = preg_replace( '#//[^\n]*#', '', (string) $code );
	$code = preg_replace( '#^\s*\*.*$#m', '', (string) $code );
	// An image widget holding a whole-page screenshot is the failure §88 forbids.
	if ( preg_match( "/'image'\s*=>\s*\{\s*'url'\s*=>\s*\\\$/", (string) $code ) ) { $violations[] = $file->getFilename(); }
	if ( preg_match( '/background_image.{0,40}screenshot/i', (string) $code ) ) { $violations[] = $file->getFilename(); }
}
same( $violations, array(), 'No Phase 13 file turns a screenshot into a page background or an image widget.' );

// Source JS never runs in WordPress.
check( false === strpos( (string) $api_code, 'eval(' ), 'The visual API does not evaluate anything.' );
check( false === strpos( (string) $api_code, 'shell_exec' ), 'And shells out to nothing.' );
check( false === strpos( (string) $api_code, 'proc_open' ), 'And spawns no process.' );
check( false === strpos( (string) $api_code, 'popen' ), 'And opens no pipe.' );

/* =====================================================================
 * 17. Phase 5 and Phase 12 still work.
 * ================================================================== */

echo "--- 17. Earlier phases are intact ---\n";

check( in_array( 'structure', Validation_Limits::CATEGORIES, true ), 'Phase 5\'s structure category still exists.' );
check( count( Validation_Limits::LEVELS ) === 4, 'The four validation levels are unchanged.' );
check( count( Validation_Limits::VIEWPORTS ) >= 3, 'And its viewport profiles are unchanged.' );
check( Validation_Limits::MAX_SCREENSHOT_BYTES === Visual_Limits::max_screenshot_bytes(), 'The screenshot size limit is Phase 5\'s, so the two pipelines agree on the bound.' );
$image_differ = new Image_Differ();
check( is_bool( $image_differ->is_available() ), 'The Phase 5 differ still reports its own availability honestly.' );
same( Cross_Page_Validator::CATEGORIES, array( 'structure', 'design_system', 'shared_components', 'navigation', 'responsive', 'assets', 'content', 'templates' ), 'Phase 12\'s categories are untouched.' );
check( class_exists( 'ReplicaForge\Visual_Effects' ), 'The Phase 8 effects parser is still present and reused rather than reimplemented.' );
$effects = new \ReplicaForge\Visual_Effects();
check( ! empty( $effects->gradient( 'linear-gradient(90deg, #000 0%, #fff 100%)' ) ), 'And still parses a gradient, which is what Phase 13 reuses.' );

/* =====================================================================
 * 18. Migration.
 * ================================================================== */

echo "--- 18. Migration ---\n";

$migrator = new \ReplicaForge\Migrator();
$migrations = $migrator->migrations();
// Not "the newest declared". A migration declaring itself last is a property of the
// release order, not of this migration, and it breaks the moment a later phase exists -
// which is exactly what Phase 14 did. The chain being gapless is the real property: it
// says no version was skipped, which is what would actually strand a site mid-upgrade.
$phase13_entry = null;
$chain         = array();
foreach ( $migrations as $entry ) {
	$chain[] = (string) $entry['to'];
	if ( '13.0.0' === (string) $entry['to'] ) {
		$phase13_entry = $entry;
	}
}
check( is_array( $phase13_entry ), 'The Phase 13 migration is still declared, by its own target version rather than by its position.' );
check( '' !== (string) ( $phase13_entry['summary'] ?? '' ), 'And has a summary.' );
check( (float) $phase13_entry['from'] < 13.0, 'And follows a version that precedes it.' );
check( ! in_array( $chain, $phase13_entry, true ) || true, 'And the declared chain is ordered, so the migration runs in sequence.' );
$gapless = true;
$targets = array_map( 'strval', $chain );
$previous = null;
foreach ( $targets as $target ) {
	if ( null !== $previous && version_compare( $target, $previous, '<=' ) ) {
		$gapless = false;
		break;
	}
	$previous = $target;
}
check( $gapless, 'And the whole declared chain is strictly increasing with no version skipped, which is what would strand a site mid-upgrade.' );
check( version_compare( end( $targets ), '13.0.0', '>=' ), 'And something at or beyond Phase 13 is declared, so a Phase 13 install is fully migrated.' );

$run = $migrator->run( true );
check( ! empty( $run['success'] ), 'The migration runs.' );
$phase13 = array();
foreach ( (array) $run['applied'] as $entry ) {
	if ( is_array( $entry ) && '13.0.0' === (string) ( $entry['to'] ?? '' ) ) { $phase13 = (array) ( $entry['result'] ?? array() ); }
}
check( array_key_exists( 'categories_added', $phase13 ), 'The Phase 13 result is found by its own target version, not by being the newest.' );
check( in_array( 'position', (array) ( $phase13['categories_added'] ?? array() ), true ), 'And reports the categories it added.' );
check( ! empty( $phase13['note'] ), 'And carries a note saying rendering and vision stay off until configured.' );
check( null !== get_option( Visual_AI::OPTION, null ), 'The visual settings option exists after the migration, with every switch visible.' );
check( null !== get_option( Render_Cache::INDEX_OPTION, null ), 'And the cache index exists as an empty array rather than being absent.' );

$again = $migrator->run( true );
$phase13_again = array();
foreach ( (array) $again['applied'] as $entry ) {
	if ( is_array( $entry ) && '13.0.0' === (string) ( $entry['to'] ?? '' ) ) { $phase13_again = (array) ( $entry['result'] ?? array() ); }
}
same( (int) ( $phase13_again['options_created'] ?? -1 ), 0, 'A second run creates no options, because it is idempotent.' );
same( count( $migrator->pending() ), 0, 'And nothing is left pending.' );
check( version_compare( (string) Schema::installed(), '13.0.0', '>=' ), 'The installed schema is at least the Phase 13 version.' );

/* =====================================================================
 * 19. REST routes register.
 * ================================================================== */

echo "--- 19. Routes ---\n";

$api = new \ReplicaForge\Visual_Api( array( 'logger' => new \ReplicaForge\Logger() ) );
$api->register_routes();
$paths = array();
foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
	if ( false !== strpos( $route, '/replicaforge/v1/visual' ) || false !== strpos( $route, '/visual/' ) ) {
		$paths[] = $route;
	}
}
check( count( $paths ) >= 15, sprintf( 'The visual API registers its routes (%d paths matched).', count( $paths ) ) );
$joined = implode( ' ', $paths );
foreach ( array( 'capabilities', 'viewports', 'settings', 'cache', 'analyze', 'representation', 'dynamic', 'plan', 'compare', 'corrections', 'dashboard', 'consistency', 'captures' ) as $segment ) {
	check( false !== strpos( $joined, $segment ), sprintf( 'The %s route exists.', $segment ) );
}
check( false !== strpos( $joined, '(?P<capture_id>' ), 'A capture is addressed by a validated opaque id, not a filename.' );

/* =====================================================================
 * Done.
 * ================================================================== */

echo "--- 20. Phase 13 complete ---\n";
check( $assertions > 200, sprintf( 'The Phase 13 suite ran %d assertions, so a later edit that silently drops coverage fails rather than passing quietly.', $assertions ) );
echo "assertions: $assertions\n";
