<?php
/**
 * Phase 8: the design token engine and the project system.
 *
 * Run: php phase8-tokens-projects-test.php <wp-root>
 */
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : '';
if ( '' === $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "usage: php phase8-tokens-projects-test.php <wp-root>\n" );
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

use ReplicaForge\Token_Engine;
use ReplicaForge\Project_Repository;

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
 * Shorthand for an observation.
 *
 * @param array<string, mixed> $pairs Fields.
 * @return array<string, mixed>
 */
function obs( array $pairs ) {
	return $pairs;
}

$engine = new Token_Engine();

echo "--- 1. A repeated color becomes one token ---\n";

// A page where the same text color appears on every heading.
$observations = array();
for ( $index = 0; $index < 6; $index++ ) {
	$observations[] = obs(
		array(
			'node_id'   => 'h' . $index,
			'role'      => 'h2',
			'has_text'  => true,
			'typography'=> array( 'family' => 'Inter', 'weight' => 700, 'size' => 32, 'color' => '#111111' ),
		)
	);
}

$tokens = $engine->build( $observations, array( 'background' => '#ffffff' ) );

check( '8.0' === (string) $tokens['version'], 'The token set declares its schema version, as a dotted string like every other schema version.' );
check( ! empty( $tokens['tokens']['colors'] ), 'Colors are collected.' );

$first = $tokens['tokens']['colors'][0];
check( '#111111' === (string) $first['value'], 'The most-used color is first.' );
check( 6 === (int) $first['occurrences'], 'Its occurrence count is real.' );
check( 'text' === (string) $first['name'], 'A color used on text against a contrasting page is named text.' );
check( false !== strpos( (string) $first['name_basis'], 'measured contrast' ), 'The naming basis records that a contrast measurement supports the name, rather than the name being asserted.' );
check( ! empty( $first['evidence'] ), 'The token carries evidence about where it was seen.' );
check( count( $first['evidence'] ) <= 5, 'The evidence list is bounded.' );

echo "--- 2. One color, several spellings, one token ---\n";

$spellings = array(
	obs( array( 'node_id' => 'a', 'has_text' => true, 'typography' => array( 'color' => '#6C63FF' ) ) ),
	obs( array( 'node_id' => 'b', 'has_text' => true, 'typography' => array( 'color' => '#6c63ff' ) ) ),
	obs( array( 'node_id' => 'c', 'has_text' => true, 'typography' => array( 'color' => 'rgb(108, 99, 255)' ) ) ),
);
$spelled = $engine->build( $spellings );
check( 1 === count( $spelled['tokens']['colors'] ), 'Three spellings of one color collapse to one token.' );
check( 3 === (int) $spelled['tokens']['colors'][0]['occurrences'], 'The collapsed token counts all three uses.' );
check( '#6c63ff' === (string) $spelled['tokens']['colors'][0]['value'], 'The token value is the normalized hex form.' );

echo "--- 3. A translucent color is not the same token as an opaque one ---\n";

$alpha = $engine->build(
	array(
		obs( array( 'node_id' => 'a', 'has_text' => true, 'typography' => array( 'color' => '#000000' ) ) ),
		obs( array( 'node_id' => 'b', 'has_text' => true, 'typography' => array( 'color' => 'rgba(0,0,0,0.5)' ) ) ),
		obs( array( 'node_id' => 'c', 'has_text' => true, 'background' => array( 'color' => '#000000' ) ) ),
	)
);
check( 2 === count( $alpha['tokens']['colors'] ), 'A translucent black and an opaque black are two tokens, because they are not the same value.' );

$translucent = null;
foreach ( $alpha['tokens']['colors'] as $entry ) {
	if ( 0.5 === round( (float) $entry['alpha'], 2 ) ) {
		$translucent = $entry;
	}
}
check( null !== $translucent, 'The translucent token is present.' );
check( 0.5 === round( (float) $translucent['alpha'], 2 ), 'Its alpha is retained.' );

echo "--- 4. A name is only claimed when the evidence supports it ---\n";

$ambiguous = $engine->build(
	array(
		obs( array( 'node_id' => 'a', 'typography' => array( 'color' => '#abcdef' ) ) ),
		obs( array( 'node_id' => 'b', 'typography' => array( 'color' => '#abcdef' ) ) ),
		obs( array( 'node_id' => 'c', 'typography' => array( 'color' => '#abcdef' ) ) ),
	)
);
$ambiguous_token = $ambiguous['tokens']['colors'][0];
check(
	0 === strpos( (string) $ambiguous_token['name'], 'color_' ),
	'A color with no distinguishing usage evidence is not given a semantic name.'
);
check(
	false !== strpos( (string) $ambiguous_token['name_basis'], 'no semantic name is claimed' ),
	'The basis says outright that no semantic name is claimed, so a reader is not misled by the token existing.'
);
check( 0 === (int) $ambiguous['named']['colors'], 'The named count does not include a token that only claims an ordinal.' );

echo "--- 5. Role-based names ---\n";

$section_observations = array(
	obs( array( 'node_id' => 'a', 'role' => 'section', 'is_container' => true, 'background' => array( 'color' => '#f5f5f5' ) ) ),
	obs( array( 'node_id' => 'b', 'role' => 'section', 'is_container' => true, 'background' => array( 'color' => '#f5f5f5' ) ) ),
	obs( array( 'node_id' => 'c', 'role' => 'section', 'is_container' => true, 'background' => array( 'color' => '#f5f5f5' ) ) ),
);

$roles = $engine->build( $section_observations, array( 'background' => '#f5f5f5' ) );
$surface = null;
foreach ( $roles['tokens']['colors'] as $entry ) {
	if ( '#f5f5f5' === (string) $entry['value'] ) {
		$surface = $entry;
	}
}
check( null !== $surface, 'A repeated section background is collected.' );
check( 'background' === (string) $surface['name'], 'A color that the caller reported as the page background is named background.' );
check( false !== strpos( (string) $surface['name_basis'], 'page background' ), 'The basis says why.' );

// Without the page background, calling a colour the page background would be an
// assertion the observations do not support, so the weaker true name is used.
$unknown = $engine->build( $section_observations );
$unknown_surface = null;
foreach ( $unknown['tokens']['colors'] as $entry ) {
	if ( '#f5f5f5' === (string) $entry['value'] ) {
		$unknown_surface = $entry;
	}
}
check( 'surface' === (string) $unknown_surface['name'], 'Without a known page background, a repeated section background is named surface rather than background.' );
check( false !== strpos( (string) $unknown_surface['name_basis'], 'section or container' ), 'The basis says what the name is based on.' );

echo "--- 6. Typography roles ---\n";

$type = $engine->build(
	array(
		obs( array( 'node_id' => 'h1', 'role' => 'h1', 'typography' => array( 'family' => 'Inter', 'weight' => 800, 'size' => 60, 'color' => '#111111' ) ) ),
		obs( array( 'node_id' => 'h1b', 'role' => 'h1', 'typography' => array( 'family' => 'Inter', 'weight' => 800, 'size' => 60, 'color' => '#111111' ) ) ),
		obs( array( 'node_id' => 'p', 'role' => 'paragraph', 'typography' => array( 'family' => 'Inter', 'weight' => 400, 'size' => 16, 'color' => '#333333' ) ) ),
		obs( array( 'node_id' => 'pb', 'role' => 'paragraph', 'typography' => array( 'family' => 'Inter', 'weight' => 400, 'size' => 16, 'color' => '#333333' ) ) ),
		obs( array( 'node_id' => 'small', 'role' => 'caption', 'typography' => array( 'family' => 'Inter', 'weight' => 400, 'size' => 12, 'color' => '#666666' ) ) ),
		obs( array( 'node_id' => 'smallb', 'role' => 'caption', 'typography' => array( 'family' => 'Inter', 'weight' => 400, 'size' => 12, 'color' => '#666666' ) ) ),
	)
);

$names = array();
foreach ( $type['tokens']['typography'] as $entry ) {
	$names[ (string) $entry['name'] ] = $entry;
}
check( isset( $names['heading_1'] ), 'A type used on an h1 is named for that role.' );
check( false !== strpos( (string) $names['heading_1']['name_basis'], 'h1 element' ), 'The basis names the element the role came from, because the element is stronger evidence than a size comparison.' );
check( isset( $names['body'] ), 'The body type is named body.' );
check( isset( $names['caption'] ), 'The caption type is named for its element role.' );
check( 60.0 === (float) $type['tokens']['typography'][0]['size'], 'Typography tokens are ordered by size, largest first.' );

echo "--- 7. Spacing, radius, shadow, container, and breakpoint families ---\n";

$families = $engine->build(
	array(
		obs(
			array(
				'node_id'   => 'card',
				'spacing'   => array( 'top' => 24, 'bottom' => 24, 'left' => 24, 'right' => 24 ),
				'radius'    => 8,
				'shadow'    => array( 'offset_x' => 0, 'offset_y' => 2, 'blur' => 6, 'color' => '#000000' ),
				'container_max_width' => 1200,
				'container_centered' => true,
				'responsive'=> array( 'tablet' => array( array( 'min_width' => 768 ) ) ),
			)
		),
		obs(
			array(
				'node_id'   => 'card2',
				'spacing'   => array( 'top' => 24, 'bottom' => 24, 'left' => 24, 'right' => 24 ),
				'radius'    => 8,
				'shadow'    => array( 'offset_x' => 0, 'offset_y' => 2, 'blur' => 6, 'color' => '#000000' ),
				'container_max_width' => 1200,
				'container_centered' => true,
				'responsive'=> array( 'tablet' => array( array( 'min_width' => 768 ) ) ),
			)
		),
		obs(
			array(
				'node_id'   => 'panel',
				'spacing'   => array( 'top' => -40, 'bottom' => 48 ),
				'radius'    => 16,
				'container_max_width' => 1200,
				'container_centered' => true,
			)
		),
	)
);

check( ! empty( $families['tokens']['spacing'] ), 'Spacing tokens are produced.' );
$negative_present = false;
foreach ( $families['tokens']['spacing'] as $entry ) {
	if ( (float) $entry['value'] < 0 ) {
		$negative_present = true;
	}
}
check( false === $negative_present, 'A negative margin stays out of the spacing scale, because it is a layout technique rather than a scale value.' );
check( 2 === count( $families['tokens']['radius'] ), 'Two distinct radii produce two tokens.' );
check( ! empty( $families['tokens']['shadows'] ), 'A repeated shadow produces one shadow token.' );
check( 'shadow_raised' === (string) $families['tokens']['shadows'][0]['name'], 'A shadow with a six-pixel blur is named for the depth it represents.' );
check( false !== strpos( (string) $families['tokens']['shadows'][0]['name_basis'], 'blur radius' ), 'The shadow name rests on the blur, which is what distinguishes depth.' );
check( 1 === count( $families['tokens']['containers'] ), 'The repeated content width produces one container token.' );
check( 1200.0 === (float) $families['tokens']['containers'][0]['max_width'], 'Its width is retained.' );
check( true === (bool) $families['tokens']['containers'][0]['centered'], 'Its centering is retained, because centered and full width are different bands.' );
check( 1 === count( $families['tokens']['breakpoints'] ), 'The declared media query becomes one breakpoint.' );
check( 768 === (int) $families['tokens']['breakpoints'][0]['min_width'], 'Its width is retained.' );

echo "--- 8. Deduplication is reported as a number ---\n";

$summary = $families['evidence']['spacing'];
check( isset( $summary['tokens'], $summary['occurrences'], $summary['deduplicated'] ), 'The evidence summary reports tokens, occurrences, and how many occurrences a token absorbed.' );
check( $summary['deduplicated'] > 0, 'A token standing in for several uses reports that it absorbed them, which is the point of having tokens.' );

echo "--- 9. An empty page produces an empty set, not an invented one ---\n";

$empty = $engine->build( array() );
check( 0 === $empty['total'], 'No observations produce no tokens.' );
check( 0 === count( $empty['tokens']['colors'] ), 'And specifically no colors.' );

echo "--- 10. Families are capped ---\n";

$many = array();
for ( $index = 0; $index < 500; $index++ ) {
	$many[] = obs(
		array(
			'node_id' => 'n' . $index,
			'radius'  => $index,
		)
	);
}
$capped = $engine->build( $many );
check( count( $capped['tokens']['radius'] ) <= 40, 'The radius family is capped, so one pathological value per node cannot grow the representation without bound.' );

echo "--- 11. Project creation ---\n";

$projects_option = Project_Repository::OPTION;
$prefs_option    = Project_Repository::PREFERENCES_OPTION;
$original_projects = get_option( $projects_option );
$original_prefs    = get_option( $prefs_option );

$repository = new Project_Repository();
update_option( $projects_option, array(), false );
update_option( $prefs_option, false );

$project = $repository->create( 'https://example.com/pricing' );
check( ! empty( $project['project_id'] ), 'A project gets an identifier.' );
check( 1 === count( $repository->all() ), 'It is stored.' );
check( 'example.com' === (string) $project['source_host'], 'The host is recorded separately from the URL, so a list can be filtered by site.' );
check( false !== strpos( (string) $project['name'], 'Pricing' ), 'A name is derived from the path when the caller supplies none.' );
check( 'analyzed' === (string) $project['status'], 'A new project starts in the analyzed state, which is the truth about what has happened.' );
check( 'balanced' === (string) $project['settings']['mode'], 'The default reconstruction mode is balanced.' );
check( 'reference' === (string) $project['settings']['asset_policy'], 'The default asset policy references rather than imports, which is the conservative choice.' );
check( false === (bool) $project['settings']['auto_correct'], 'Automatic correction is off by default, because a correction still needs review.' );

$found = $repository->find( (string) $project['project_id'] );
check( null !== $found, 'A project can be found by its identifier.' );
check( null === $repository->find( 'nope' ), 'An unknown identifier returns null rather than an empty project.' );
check( null === $repository->find( '' ), 'An empty identifier returns null.' );
check( null === $repository->find( array( 'x' ) ), 'A non-string identifier returns null.' );

echo "--- 12. Duplicate source detection ---\n";

$same = $repository->find_by_source( 'https://example.com/pricing' );
check( null !== $same, 'The same page analyzed again is recognized.' );
check( (string) $same['project_id'] === (string) $project['project_id'], 'It resolves to the same project, so a repeat analysis can offer to continue rather than starting over.' );

$duplicates = $repository->duplicates_of( 'https://example.com/pricing' );
check( 1 === count( $duplicates ), 'The duplicates list holds the existing project.' );

// A trailing slash, a fragment, and a reordered query string are the same page.
$variants = array(
	'https://example.com/pricing/',
	'https://example.com/pricing#plans',
	'https://EXAMPLE.com/pricing',
);

// The ordering of a query string is normalized, because two orderings of the
// same parameters are one request. A query itself is not normalized away,
// because a filtered listing is a different page from the base one.
$filtered = $repository->create( 'https://example.com/list?b=2&a=1' );
check(
	null !== $repository->find_by_source( 'https://example.com/list?a=1&b=2' ),
	'Two orderings of the same query resolve to the same project.'
);
check(
	null === $repository->find_by_source( 'https://example.com/list' ),
	'A query does not resolve to the base page, because a filtered listing is a different page.'
);
check(
	null === $repository->find_by_source( 'https://example.com/list?a=2&b=2' ),
	'A different query value is a different page.'
);
check( 'https://example.com/list?a=1&b=2' === $repository->source_key( 'https://example.com/list?b=2&a=1' ), 'The normalized key sorts the query parameters.' );
check( 'https://example.com/pricing' === $repository->source_key( 'https://example.com/pricing/' ), 'A trailing slash is normalized away.' );
check( 'https://example.com/pricing' === $repository->source_key( 'https://example.com/pricing#plans' ), 'A fragment is normalized away, because it is not sent to the server.' );
foreach ( $variants as $variant ) {
	$match = $repository->find_by_source( $variant );
	check( null !== $match, 'A URL variant resolves to the same project: ' . $variant );
}

// A different page, host, scheme, or query is a different project.
$different = array(
	'https://example.com/about',
	'https://other.test/pricing',
	'http://example.com/pricing',
);
foreach ( $different as $variant ) {
	$match = $repository->find_by_source( $variant );
	check( null === $match, 'A genuinely different URL is not treated as the same project: ' . $variant );
}

echo "--- 13. Versions ---\n";

$versioned = $repository->add_version(
	(string) $project['project_id'],
	array(
		'source_hash' => 'abc123',
		'analysis'    => 'analysis-token-1',
		'design'      => 'design-token-1',
		'draft_id'    => 0,
		'change'      => 'initial',
		'impact'      => 'low',
	)
);
check( null !== $versioned, 'A version is added.' );
check( 1 === (int) $versioned['versions'][0]['version'], 'The first version is numbered one.' );
check( 'analysis-token-1' === (string) $versioned['analysis'], 'The version carries the analysis reference.' );
check( 'draft_created' === (string) $versioned['status'] || 'analyzed' === (string) $versioned['status'], 'The status reflects whether a draft exists.' );

$second = $repository->add_version(
	(string) $project['project_id'],
	array( 'source_hash' => 'def456', 'analysis' => 'analysis-token-2', 'change' => 'color_change', 'impact' => 'low' )
);
check( 2 === count( $second['versions'] ), 'A second version is added.' );
check( 2 === (int) $second['versions'][1]['version'], 'It is numbered two.' );
check( 'analysis-token-1' === (string) $second['versions'][0]['analysis'], 'The first version is not overwritten, which is what makes a comparison between them meaningful.' );
check( 'analysis-token-2' === (string) $second['analysis'], 'The project points at the latest version.' );
check( 'color_change' === (string) $second['versions'][1]['change'], 'The version records what changed.' );

check( null === $repository->add_version( 'nope', array() ), 'Adding a version to an unknown project returns null rather than creating one.' );

echo "--- 14. Version history is bounded ---\n";

$many_projects = $repository->create( 'https://example.com/many' );
for ( $index = 0; $index < Project_Repository::MAX_VERSIONS + 8; $index++ ) {
	$many_projects = $repository->add_version(
		(string) $many_projects['project_id'],
		array( 'source_hash' => 'h' . $index, 'change' => 'change-' . $index )
	);
}
check(
	count( $many_projects['versions'] ) <= Project_Repository::MAX_VERSIONS,
	'Version history is capped, so a page analyzed repeatedly cannot grow a project without bound.'
);
$newest = end( $many_projects['versions'] );
check(
	'change-' . ( Project_Repository::MAX_VERSIONS + 7 ) === (string) $newest['change'],
	'The newest version is the one retained.'
);
check(
	'change-' . ( Project_Repository::MAX_VERSIONS + 8 - Project_Repository::MAX_VERSIONS ) === (string) $many_projects['versions'][0]['change'],
	'The oldest retained version is the newest twenty, so the earliest eight are dropped.'
);
check(
	9 === (int) $many_projects['versions'][0]['version'],
	'Version numbering continues from the whole history rather than restarting, so version 9 is still called 9 after versions 1 to 8 are dropped.'
);
check(
	count( $many_projects['versions'] ) === (int) end( $many_projects['versions'] )['version'] - (int) $many_projects['versions'][0]['version'] + 1,
	'The retained versions are consecutive, so the history has no gap in it.'
);

echo "--- 15. Project settings are validated ---\n";

$configured = $repository->create(
	'https://example.com/configured',
	array(
		'name'     => 'My Replica',
		'settings' => array(
			'mode'         => 'editable',
			'asset_policy' => 'import',
			'auto_correct' => true,
			'priority'     => array( 'editability' => 9, 'performance' => 1, 'visual' => 99 ),
		),
	)
);
check( 'My Replica' === (string) $configured['name'], 'A supplied name is used.' );
check( 'editable' === (string) $configured['settings']['mode'], 'A valid mode is accepted.' );
check( 'import' === (string) $configured['settings']['asset_policy'], 'A valid asset policy is accepted.' );
check( true === (bool) $configured['settings']['auto_correct'], 'The auto-correct flag is accepted when the caller asks for it.' );
check( 9 === (int) $configured['settings']['priority']['editability'], 'A priority inside the range is kept.' );
check( 1 === (int) $configured['settings']['priority']['performance'], 'A priority of one is kept, rather than being treated as unset.' );

$invalid = $repository->create(
	'https://example.com/invalid',
	array( 'settings' => array( 'mode' => 'nonsense', 'asset_policy' => 'download-everything' ) )
);
check( 'balanced' === (string) $invalid['settings']['mode'], 'An unknown mode is refused and the default is kept, rather than being stored as a mode no reader understands.' );
check( 'reference' === (string) $invalid['settings']['asset_policy'], 'An unknown asset policy is refused and the conservative default is kept.' );

echo "--- 16. Deleting a project does not delete the draft ---\n";

// A real post stands in for a generated draft.
$draft_id = wp_insert_post(
	array(
		'post_title'  => 'ReplicaForge project fixture draft',
		'post_status' => 'draft',
		'post_type'   => 'page',
	)
);
check( is_int( $draft_id ) && $draft_id > 0, 'A draft post is created for the deletion tests.' );
add_post_meta( $draft_id, 'replicaforge_generation_hash', 'test-hash' );

$owner = $repository->create( 'https://example.com/with-draft' );
$owner = $repository->add_version(
	(string) $owner['project_id'],
	array( 'draft_id' => $draft_id, 'change' => 'initial' )
);
check( 1 === count( $owner['drafts'] ), 'The project records the draft its version produced.' );
check( 'replicaforge' === (string) $owner['drafts'][0]['ownership'], 'A draft carrying the generation hash is recorded as ReplicaForge\'s.' );
check( true === (bool) $owner['drafts'][0]['exists'], 'Its existence is checked rather than assumed.' );

$deleted = $repository->delete( (string) $owner['project_id'] );
check( true === $deleted['success'], 'The project is deleted.' );
check( 0 === (int) $deleted['drafts_deleted'], 'No draft is deleted by default.' );
check( 1 === (int) $deleted['drafts_kept'], 'The draft is reported as kept.' );
check( null !== get_post( $draft_id ), 'And the draft is still there, which is the whole point: a project is bookkeeping and a page is the user\'s content.' );
check( null === $repository->find( (string) $owner['project_id'] ), 'The project record is gone.' );

echo "--- 17. Deleting a draft requires asking, twice ---\n";

$also_deleted = $repository->delete( (string) $owner['project_id'] );
check( false === $also_deleted['success'], 'Deleting a project that is already gone reports that rather than pretending to succeed.' );
check( 'project_not_found' === (string) $also_deleted['reason'], 'The reason is given.' );

$explicit = $repository->create( 'https://example.com/explicit-delete' );
$explicit = $repository->add_version(
	(string) $explicit['project_id'],
	array( 'draft_id' => $draft_id, 'change' => 'initial' )
);
$removed = $repository->delete( (string) $explicit['project_id'], true );
check( 1 === (int) $removed['drafts_deleted'], 'A draft is deleted when the caller explicitly asks.' );
check( null === get_post( $draft_id ), 'And it is gone.' );

echo "--- 18. A user's own page is never deleted ---\n";

$user_page = wp_insert_post(
	array(
		'post_title'  => 'A page the user made',
		'post_status' => 'draft',
		'post_type'   => 'page',
	)
);
$user_owner = $repository->create( 'https://example.com/user-page' );
$user_owner = $repository->add_version(
	(string) $user_owner['project_id'],
	array( 'draft_id' => $user_page, 'change' => 'initial' )
);
check( 'user' === (string) $user_owner['drafts'][0]['ownership'], 'A draft without the generation hash is recorded as the user\'s, whatever its title suggests.' );

$kept = $repository->delete( (string) $user_owner['project_id'], true );
check( 0 === (int) $kept['drafts_deleted'], 'Asking to delete drafts still does not delete a page ReplicaForge did not generate.' );
check( null !== get_post( $user_page ), 'The user\'s page survives.' );

echo "--- 19. A published page is never deleted ---\n";

$published = wp_insert_post(
	array(
		'post_title'  => 'A published replica',
		'post_status' => 'publish',
		'post_type'   => 'page',
	)
);
add_post_meta( $published, 'replicaforge_generation_hash', 'test-hash' );
$published_owner = $repository->create( 'https://example.com/published' );
$published_owner = $repository->add_version(
	(string) $published_owner['project_id'],
	array( 'draft_id' => $published, 'change' => 'initial' )
);
$published_kept = $repository->delete( (string) $published_owner['project_id'], true );
check( 0 === (int) $published_kept['drafts_deleted'], 'A generated page that is no longer a draft is not deleted, because publishing is the user acting on it.' );
check( 'publish' === get_post_status( $published ), 'The published page is untouched.' );

// Clean up the fixtures this suite created.
foreach ( array( $user_page, $published ) as $fixture ) {
	if ( null !== get_post( $fixture ) ) {
		wp_delete_post( $fixture, true );
	}
}

echo "--- 20. Other projects are untouched by a deletion ---\n";

$survivor = $repository->create( 'https://example.com/survivor' );
$repository->delete( (string) $many_projects['project_id'] );
check( null !== $repository->find( (string) $survivor['project_id'] ), 'Deleting one project leaves the others in place.' );
check( null !== $repository->find( (string) $project['project_id'] ), 'And leaves the earlier project in place.' );

echo "--- 21. Listing and filtering ---\n";

$listed = $repository->recent( array(), 5 );
check( count( $listed ) <= 5, 'The list is limited to what was asked for.' );
check( isset( $listed[0]['project_id'] ), 'Each entry is in the shape the UI uses.' );
check( ! isset( $listed[0]['versions'] ), 'A listed entry does not carry its whole version history, which would make the list expensive to render.' );
check( isset( $listed[0]['can_delete'] ), 'The listing says whether the project can be deleted, rather than leaving the UI to guess.' );

$other_host = $repository->create( 'https://another.test/page' );
$filtered = $repository->recent( array( 'host' => 'another.test' ), 10 );
check( 1 === count( $filtered ), 'A host filter narrows the list to that site.' );
check(
	(string) $other_host['project_id'] === (string) $filtered[0]['project_id'],
	'And returns the project on that site, not the newest project overall.'
);
check(
	0 === count( $repository->recent( array( 'host' => 'survivor' ), 10 ) ),
	'A path fragment does not match a host filter, because the path is not part of the host.'
);
check(
	count( $repository->recent( array( 'host' => 'example' ), 100 ) ) === count( $repository->recent( array( 'host' => 'example.com' ), 100 ) ),
	'The host filter is a substring match, so a partial host narrows the list in the way a search box does.'
);
check(
	count( $repository->recent( array( 'host' => 'example.com' ), 100 ) ) > 1,
	'A host with several projects returns all of them.'
);

$none = $repository->recent( array( 'host' => 'nothing.test' ), 10 );
check( 0 === count( $none ), 'A filter that matches nothing returns an empty list rather than everything.' );

echo "--- 22. Preferences ---\n";

check( $repository->set_preference( 'mode', 'visual' ), 'A preference is stored.' );
check( 'visual' === (string) $repository->preferences()['mode'], 'It is read back.' );
check( false === $repository->set_preference( '', 'x' ), 'An empty preference name is refused.' );

// A stored value that is not an array must not break the read.
update_option( $prefs_option, 'not an array', false );
check( is_array( $repository->preferences() ), 'A corrupted preference option reads as the defaults rather than returning a string.' );
check( 'balanced' === (string) $repository->preferences()['mode'], 'And the defaults are the real defaults.' );

// A corrupted project option must not break the listing either.
update_option( $projects_option, 'not an array', false );
check( array() === $repository->all(), 'A corrupted project option reads as no projects rather than producing a listing error.' );
check( null === $repository->find( 'anything' ), 'And nothing is findable.' );

$repository->create( 'https://example.com/after-corruption' );
check( 1 === count( $repository->all() ), 'A project can still be created after a corrupted option, so the corruption is recoverable rather than sticky.' );

echo "--- 23. An invalid source URL is refused a project identity ---\n";

check( 'invalid' === $repository->source_key( 'not a url' ), 'A URL with no host produces an invalid key rather than a key that could collide.' );
check( 'invalid' === $repository->source_key( '' ), 'An empty URL is invalid.' );

$named = $repository->create( 'https://example.com/' );
check( 'example.com' === (string) $named['name'], 'A URL with an empty path is named after its host.' );

$deep = $repository->create( 'https://example.com/blog/2026/01/some-long-article-title-here' );
check( mb_strlen( (string) $deep['name'] ) <= 120, 'A long derived name is bounded.' );

// Restore the options this suite changed.
if ( false === $original_projects ) {
	delete_option( $projects_option );
} else {
	update_option( $projects_option, $original_projects, false );
}
if ( false === $original_prefs ) {
	delete_option( $prefs_option );
} else {
	update_option( $prefs_option, $original_prefs, false );
}

echo "\nToken engine and project system test passed. Assertions: {$assertions}\n";
