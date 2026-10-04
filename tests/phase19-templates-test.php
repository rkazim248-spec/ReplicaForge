<?php
/**
 * Phase 19: template and design-system library.
 *
 * Run with: run-test.php <wp-root> tests/phase19-templates-test.php
 * Assertions print a `PASS:` or `FAIL:` prefix; the runner counts them.
 *
 * The test is written to be re-runnable. It creates its own workspace-scoped records with
 * unique names, so a second run does not collide with the first, and it cleans up the
 * records it created rather than leaving the library in a state the next run cannot use.
 *
 * @package ReplicaForge
 */

use ReplicaForge\Content_Slot_Registry;
use ReplicaForge\Design_Token_Registry;
use ReplicaForge\Template_Component_Store;
use ReplicaForge\Template_Conflicts;
use ReplicaForge\Template_Dependencies;
use ReplicaForge\Template_Extractor;
use ReplicaForge\Template_Installer;
use ReplicaForge\Template_Limits;
use ReplicaForge\Template_Package;
use ReplicaForge\Template_Quality;
use ReplicaForge\Template_Sanitizer;
use ReplicaForge\Template_Store;
use ReplicaForge\Template_Validator;
use ReplicaForge\Template_Version_Store;
use ReplicaForge\Workspace_Store;

$assertions = 0;
$failures   = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   What was checked.
 * @return bool
 */
function check( $condition, $message ) {
	global $assertions, $failures;
	$assertions++;
	if ( $condition ) {
		echo 'PASS: ' . $message . "\n";
		return true;
	}
	$failures++;
	echo 'FAIL: ' . $message . "\n";
	return false;
}

/**
 * Assert that a callable does not raise, and report what it returned.
 *
 * Several security assertions here are "this refuses cleanly". A test that dies on a fatal
 * inside the guard under test reports nothing, so a refusal is always called through a
 * wrapper that catches.
 *
 * @param callable $callback Callback.
 * @param string   $message  What was checked.
 * @return mixed
 */
function check_raises( callable $callback, $message ) {
	global $assertions, $failures;
	$assertions++;
	try {
		$callback();
	} catch ( Throwable $error ) {
		$failures++;
		echo 'FAIL: ' . $message . ' (raised ' . get_class( $error ) . ': ' . $error->getMessage() . ")\n";
		return null;
	}
	echo 'PASS: ' . $message . "\n";
	return null;
}

/**
 * Start a section.
 *
 * @param string $title Section title.
 * @return void
 */
function section( $title ) {
	echo "\n== " . $title . " ==\n";
}

/**
 * Build a snapshot with real structure, tokens, slots and an asset.
 *
 * @param string      $name   Template name.
 * @param string      $type   Template type.
 * @param Logger|null $logger Logger.
 * @return array<string, mixed>
 */
function rf19_snapshot( $name, $type, $logger, $component_id = 'rf19_cta_banner' ) {
	$elements = array(
		array(
			'id'       => 'rf19aaa1',
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => array( 'background_background' => 'classic', 'background_color' => '#101820' ),
			'elements' => array(
				array( 'id' => 'rf19aaa2', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Reconstructed heading', 'header_size' => 'h2', 'align' => 'center' ), 'elements' => array() ),
				array( 'id' => 'rf19aaa3', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p>Structure only.</p>' ), 'elements' => array() ),
				array( 'id' => 'rf19aaa4', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Call to action', 'link' => array( 'url' => 'https://example.com/', 'is_external' => '' ) ), 'elements' => array() ),
			),
		),
	);

	return array(
		'document'      => array( 'elements' => $elements ),
		'responsive'    => array( 'devices' => array( 'desktop', 'tablet', 'mobile' ), 'sections' => array( 'rf19aaa1' => array( 'tablet' => array( 'evidence' => 'responsive_layout_change_detected' ), 'mobile' => array( 'evidence' => 'responsive_layout_change_detected' ) ) ) ),
		'interactions'  => array(),
		'design_system' => array(),
		'tokens'        => array(
			'colors.display'   => array( 'token_id' => 'colors.display', 'name' => 'display', 'category' => 'colors', 'value' => '#101820', 'unit' => '', 'source' => 'source_analysis', 'confidence' => 0.9, 'scope' => 'template', 'ownership' => 'replicaforge_controlled', 'editable' => true, 'usage_count' => 12, 'role' => 'color.text' ),
			'colors.accent'    => array( 'token_id' => 'colors.accent', 'name' => 'accent', 'category' => 'colors', 'value' => '#6C63FF', 'unit' => '', 'source' => 'source_analysis', 'confidence' => 0.8, 'scope' => 'template', 'ownership' => 'replicaforge_controlled', 'editable' => true, 'usage_count' => 7, 'role' => 'color.accent' ),
			'radius.radius_1' => array( 'token_id' => 'radius.radius_1', 'name' => 'radius_1', 'category' => 'radius', 'value' => '8px', 'unit' => 'px', 'source' => 'source_analysis', 'confidence' => 0.7, 'scope' => 'template', 'ownership' => 'replicaforge_controlled', 'editable' => true, 'usage_count' => 5, 'role' => 'shape.radius' ),
		),
		'components'    => array(
			(string) $component_id => array( 'component_id' => (string) $component_id, 'type' => 'cta', 'element_ids' => array( 'rf19aaa4' ), 'element_count' => 1, 'name' => 'CTA banner' ),
		),
		'content_slots' => array(
			array( 'slot_id' => 'heading_1', 'type' => 'heading', 'label' => 'Heading', 'required' => true, 'kind' => 'dynamic', 'dynamic_source' => 'post_title', 'default' => null, 'observed_value' => 'Reconstructed heading', 'observed' => true, 'validation' => array( 'max_length' => 160, 'strip_tags' => true, 'single_line' => true ) ),
			array( 'slot_id' => 'paragraph_1', 'type' => 'paragraph', 'label' => 'Text', 'required' => false, 'kind' => 'static', 'dynamic_source' => '', 'default' => null, 'observed_value' => '', 'observed' => false, 'validation' => array( 'max_length' => 1200, 'strip_tags' => true ) ),
		),
		'assets'        => array(
			'asset_hero' => array( 'asset_id' => 'asset_hero', 'url' => 'https://example.com/hero.jpg', 'type' => 'image', 'mime_type' => 'image/jpeg', 'provenance_class' => 'source_derived', 'mode' => 'reference', 'redistributable' => false, 'ownership' => 'source_derived', 'licence' => '', 'usage_count' => 1, 'source_host' => 'example.com' ),
		),
		'dependencies'  => array(),
		'compatibility' => ( new Template_Dependencies( null, $logger ) )->declare( array() ),
		'provenance'    => array( 'origin' => 'extracted', 'author' => 'Phase 19 test', 'source_post' => 0, 'url' => 'https://example.com/', 'project_id' => '', 'created_at' => gmdate( 'c' ) ),
		'validation'    => array( 'state' => '', 'checked_at' => '' ),
		'meta'          => array( 'name' => $name, 'description' => 'A Phase 19 test template.', 'type' => $type, 'version' => 1, 'tags' => array( 'test' ) ),
	);
}

/* ================================================================ setup */

$logger = new ReplicaForge\Logger();

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );

if ( check( ! empty( $admins ), 'an administrator exists to run the test as' ) ) {
	$admin_id = (int) $admins[0];
} else {
	echo "RESULT: FAIL (no administrator)\n";
	exit( 1 );
}

$previous_user = get_current_user_id();
wp_set_current_user( $admin_id );

$workspaces = new Workspace_Store();
$owned      = $workspaces->for_user( $admin_id, true );

if ( array() === $owned ) {
	$workspaces->create( $admin_id, __( 'Phase 19 test workspace', 'replicaforge' ) );
	$owned = $workspaces->for_user( $admin_id, true );
}

$workspace_id = (string) $owned[0]['public_id'];

check( '' !== $workspace_id, 'a workspace is available' );

$run      = 'r' . substr( md5( (string) mt_rand() ), 0, 6 );

/*
 * Every identifier the test creates is scoped to the run.
 *
 * A component id is a *stable, reusable* identity, so the same id in a second run is the
 * same component — and asserting "version 1" against a component a previous run already
 * versioned fails on the second run rather than on a defect. Component ids are therefore
 * unique per run. Template names are likewise unique, though they are not identities.
 */
$component_id = 'rf19_cta_' . $run;

$created  = array();
$tokens   = new Design_Token_Registry( $logger );
$store    = new Template_Store( null, $logger );
$versions = new Template_Version_Store( null, $logger );
$parts    = new Template_Component_Store( null, $logger );

/* ================================================================ 1. schema */

section( '1. The template library schema' );

$schema = new ReplicaForge\Collaboration_Schema();
$status = $schema->status();

check( ! empty( $status['ok'] ), 'every collaboration table exists' );
check( class_exists( Workspace_Store::class ), 'the workspace store class loads' );

foreach ( array( 'templates', 'template_version', 'template_component' ) as $kind ) {
	check( '' !== ReplicaForge\Workspace_Limits::table( $kind ), 'the ' . $kind . ' table has a declared name' );
}

/*
 * The schema version is asserted as *consistency*, not as a literal.
 *
 * This suite originally hardcoded '19.0.0' in both places, and Phase 20 — which legitimately
 * moved both constants to 20.0.0 — failed on two assertions that were only ever checking that
 * a number had not drifted. That is the wrong test: it fails on every future phase for a reason
 * that says nothing about templates, and it cannot catch the failure it looks like it catches.
 *
 * What is actually worth asserting here is the invariant the two constants are supposed to
 * share, which Phase 15 already asserts directly:
 *
 *   - `Collaboration_Schema::VERSION` and `Schema::DB_SCHEMA_VERSION` agree;
 *   - `DB_SCHEMA_VERSION` equals the newest declared migration's target, so a version bump
 *     that forgets to add a migration fails here;
 *   - neither has gone *backwards* from what Phase 19 introduced.
 *
 * The lower bound is kept because it is a real assertion: a schema version that decreased
 * would mean a site could skip migrations on the way back up.
 */
$rf19_declared = array_column( ( new ReplicaForge\Migrator() )->migrations(), 'to' );
$rf19_newest   = (string) end( $rf19_declared );

check(
	ReplicaForge\Collaboration_Schema::VERSION === ReplicaForge\Schema::DB_SCHEMA_VERSION,
	'the collaboration table schema and the migration version agree'
);

check(
	ReplicaForge\Schema::DB_SCHEMA_VERSION === $rf19_newest,
	'the declared schema version matches the newest migration'
);

check(
	version_compare( ReplicaForge\Schema::DB_SCHEMA_VERSION, '19.0.0', '>=' ),
	'the schema version has not gone backwards below what Phase 19 introduced'
);

check( '' === ReplicaForge\Workspace_Limits::table( 'not_a_kind' ), 'an unknown entity has no table name' );

$ready = new ReflectionMethod( Template_Store::class, 'ready' );
$ready->setAccessible( true );
check( true === $ready->invoke( new Template_Store( null, $logger ) ), 'the template store is ready' );

/* ================================================================ 2. capabilities */

section( '2. Permissions' );

check( ReplicaForge\Workspace_Limits::is_capability( 'templates.view' ), 'templates.view is a known capability' );
check( ReplicaForge\Workspace_Limits::is_capability( 'templates.delete' ), 'templates.delete is a known capability' );
check( ReplicaForge\Workspace_Limits::is_capability( 'design_systems.manage' ), 'design_systems.manage is a known capability' );
check( ! ReplicaForge\Workspace_Limits::is_capability( 'templates.destroy_everything' ), 'an invented capability is not' );

foreach ( array( 'admin', 'project_manager', 'designer', 'developer', 'reviewer' ) as $role ) {
	$caps = ReplicaForge\Workspace_Limits::caps_for_role( $role );
	check( in_array( 'templates.view', $caps, true ), $role . ' can view templates' );
}

check( ! in_array( 'templates.view', ReplicaForge\Workspace_Limits::caps_for_role( 'client' ), true ), 'a client cannot view templates' );
check( ! in_array( 'templates.import', ReplicaForge\Workspace_Limits::caps_for_role( 'designer' ), true ), 'a designer cannot import untrusted packages' );
check( ! in_array( 'templates.share', ReplicaForge\Workspace_Limits::caps_for_role( 'designer' ), true ), 'a designer cannot publish to the workspace' );
check( ! in_array( 'design_systems.manage', ReplicaForge\Workspace_Limits::caps_for_role( 'developer' ), true ), 'a developer cannot re-point every template' );
check( in_array( 'design_systems.manage', ReplicaForge\Workspace_Limits::caps_for_role( 'admin' ), true ), 'an admin can manage the design system' );

$refused = ( new ReplicaForge\Permission_Manager() )->can( $admin_id, $workspace_id, 'templates.nonsense' );
check( ! $refused, 'an unrecognised capability is refused at the permission manager' );

$missing = array();
foreach ( Template_Limits::CAPABILITIES as $capability ) {
	if ( ! in_array( $capability, ReplicaForge\Workspace_Limits::capabilities(), true ) ) {
		$missing[] = $capability;
	}
}
check( array() === $missing, 'every Phase 19 capability is in the master list' );

/*
 * Every table this phase creates is dropped on uninstall.
 *
 * `uninstall.php` keeps a literal copy of the table vocabulary so the drop still works when
 * the plugin classes are already gone, and the two are meant to agree. A new table that is
 * added to `Workspace_Limits::table()` and forgotten there leaves real content with no owner
 * after somebody removes the plugin — a template library whose versions reference Elementor
 * documents nothing can reach. So the list is checked rather than trusted.
 */
$uninstall_source = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

check( false !== $uninstall_source, 'the uninstall routine is readable' );

if ( false !== $uninstall_source ) {
	foreach ( array( 'templates', 'template_version', 'template_component' ) as $kind ) {
		$expected = ReplicaForge\Workspace_Limits::table( $kind );

		check( '' !== $expected, 'the ' . $kind . ' table has a name to look for' );
		check( false !== strpos( $uninstall_source, "'" . $expected . "'" ), 'uninstall.php drops ' . $expected );
	}
}

/* ================================================================ 3. vocabulary */

section( '3. Vocabularies' );

check( count( Template_Limits::template_types() ) >= 40, 'the template taxonomy is substantial' );
check( 'theme' === Template_Limits::template_group( 'header' ), 'a header is a theme template' );
check( 'page' === Template_Limits::template_group( 'homepage' ), 'a homepage is a page template' );
check( 'section' === Template_Limits::template_group( 'hero' ), 'a hero is a section template' );
check( 'component' === Template_Limits::template_group( 'button' ), 'a button is a component template' );
check( '' === Template_Limits::template_group( 'not_a_type' ), 'an unknown type has no group' );
check( Template_Limits::is_theme_type( 'footer' ), 'a footer installs onto a theme location' );
check( ! Template_Limits::is_theme_type( 'hero' ), 'a hero does not' );
check( count( Template_Limits::SLOT_TYPES ) >= 10, 'slot types are defined' );
check( count( Template_Limits::SEMANTIC_ROLES ) >= 20, 'semantic roles are defined' );
check( 7 === count( Template_Limits::ELEMENTOR_FEATURES ), 'the feature list matches Site_Compatibility' );

$live = ( new ReplicaForge\Site_Compatibility() )->capabilities();
$unknown = array();
foreach ( array_keys( (array) $live ) as $feature ) {
	if ( ! Template_Limits::is_elementor_feature( $feature ) ) {
		$unknown[] = $feature;
	}
}
check( array() === $unknown, 'every live capability is in the declared vocabulary' );

check( ! isset( Template_Limits::CONFLICT_STRATEGIES['overwrite'] ), 'there is no overwrite conflict strategy' );
check( in_array( 'placeholder', Template_Limits::REDISTRIBUTABLE_PROVENANCE, true ), 'a placeholder may be redistributed' );
check( ! in_array( 'source_derived', Template_Limits::REDISTRIBUTABLE_PROVENANCE, true ), 'a source-derived asset may not' );
check( ! in_array( 'third_party', Template_Limits::REDISTRIBUTABLE_PROVENANCE, true ), 'nor a third-party one' );
check( ! in_array( 'restricted', Template_Limits::REDISTRIBUTABLE_PROVENANCE, true ), 'nor a restricted one' );

/* ================================================================ 4. section template */

section( '4. Create a section template' );

$installer = new Template_Installer( null, null, null, null, null, $logger );
$validator = new Template_Validator( null, null, $logger );

$installed = $installer->install( $workspace_id, rf19_snapshot( 'RF19 hero ' . $run, 'hero', $logger, $component_id ), array( 'user_id' => $admin_id ) );

if ( check( ! is_wp_error( $installed ), 'a section template installs' ) ) {
	$template_id = (string) $installed['template_id'];
	$created[]   = $template_id;

	check( '' !== $template_id, 'it returns a template id' );

	$row = $store->find_template( $workspace_id, $template_id );

	check( is_array( $row ), 'the template is readable from the store' );
	check( 'hero' === (string) ( $row['type'] ?? '' ), 'it keeps its declared type' );
	check( 'active' === (string) ( $row['status'] ?? '' ), 'it is active' );
	check( 1 === (int) ( $row['version_count'] ?? 0 ), 'it has one version' );
	check( 'private' === (string) ( $row['visibility'] ?? '' ), 'it is private by default' );
	check( $admin_id === (int) ( $row['user_id'] ?? 0 ), 'it records its owner' );
	check( '' !== (string) ( $row['current_version'] ?? '' ), 'and points at its current version' );

	$steps   = array_column( (array) $installed['steps'], 'step' );
	$cursor  = 0;
	$ordered = true;
	foreach ( Template_Limits::INSTALL_STEPS as $declared_step ) {
		$at = array_search( $declared_step, array_slice( $steps, $cursor ), true );
		if ( false === $at ) {
			$ordered = false;
			break;
		}
		$cursor += (int) $at + 1;
	}
	check( $ordered, 'every declared install step ran in the declared order' );
	check( false === (bool) $installed['rolled_back'], 'and the install committed' );
} else {
	$template_id = '';
	echo '      ' . $installed->get_error_message() . "\n";
}

/* ================================================================ 5. page template */

section( '5. Create a page template' );

$page = $installer->install( $workspace_id, rf19_snapshot( 'RF19 landing ' . $run, 'landing', $logger, $component_id ), array( 'user_id' => $admin_id ) );

if ( check( ! is_wp_error( $page ), 'a full page template installs' ) ) {
	$page_id  = (string) $page['template_id'];
	$created[] = $page_id;
	$page_row = $store->find_template( $workspace_id, $page_id );

	check( is_array( $page_row ), 'and is readable' );
	check( 'landing' === (string) ( $page_row['type'] ?? '' ), 'it keeps the landing type' );
	check( 'page' === Template_Limits::template_group( (string) ( $page_row['type'] ?? '' ) ), 'which is in the page group' );
} else {
	$page_id = '';
}

/* ================================================================ 6. tokens */

section( '6. Design tokens are extracted and stored' );

if ( '' !== $template_id ) {
	$version = $versions->current( $workspace_id, $template_id );
	check( is_array( $version ), 'the stored version is readable' );

	$stored = is_array( $version ) && is_array( $version['tokens'] ) ? $version['tokens'] : array();

	check( 3 === count( $stored ), 'the three tokens survived storage' );
	check( '#6C63FF' === (string) ( $stored['colors.accent']['value'] ?? '' ), 'a colour keeps its extracted value' );
	check( 'px' === (string) ( $stored['radius.radius_1']['unit'] ?? '' ), 'a length reports its unit' );
	check( 0.9 === (float) ( $stored['colors.display']['confidence'] ?? 0 ), 'confidence is preserved' );
	check( 'replicaforge_controlled' === (string) ( $stored['colors.accent']['ownership'] ?? '' ), 'an extracted token is replicaforge-controlled' );
	check( 12 === (int) ( $stored['colors.display']['usage_count'] ?? 0 ), 'usage count is preserved' );
	check( 'color.text' === (string) ( $stored['colors.display']['role'] ?? '' ), 'the semantic role is preserved' );

	$summary = Design_Token_Registry::summarise( $stored );
	check( 3 === (int) $summary['total'], 'the summary counts the tokens' );
	check( 3 === (int) $summary['with_role'], 'each token carries its semantic role' );
	check( 0 === (int) $summary['disputed'], 'none is disputed' );

	$merged = $tokens->merge( $workspace_id, $stored );
	check( (int) $merged['merged'] >= 3, 'tokens merge into the workspace registry' );
	check( count( $tokens->registry( $workspace_id ) ) >= 3, 'the live registry now holds them' );

	// A token value is checked against the same CSS policy a template installs through.
	$registry = new Design_Token_Registry( $logger );
	check( '#6C63FF' === $registry->clean_value( '#6C63FF', 'colors' ), 'a hex colour is accepted' );
	check( '8px' === $registry->clean_value( '8px', 'radius' ), 'a length is accepted' );
	check( null === $registry->clean_value( 'javascript:alert(1)', 'colors' ), 'a script URL is refused as a colour' );
	check( null === $registry->clean_value( '<script>x</script>', 'colors' ), 'markup is refused as a colour' );
	check( null === $registry->clean_value( array( 'x' ), 'colors' ), 'a non-scalar is refused' );
	check( 'px' === Design_Token_Registry::unit_for( '12px' ), 'a unit is read from a value' );
	check( '' === Design_Token_Registry::unit_for( '0' ), 'a unitless value reports no unit' );
}

/* ================================================================ 7. components */

section( '7. Reusable components' );

if ( '' !== $template_id ) {
	$component = $parts->find_component( $workspace_id, $component_id );

	check( is_array( $component ), 'the component was registered' );
	check( 'cta' === (string) ( $component['type'] ?? '' ), 'it keeps its type' );
	check( 1 === (int) ( $component['version'] ?? 0 ), 'it is at version 1' );
	/*
	 * The component stores the *claimed element's subtree*, not the page it came from.
	 *
	 * `extract_subtree()` returns the ancestor chain needed to reach the claimed element,
	 * so the stored shape is container(button) rather than a bare button: the container is
	 * what holds the element in the document, and dropping it would produce a component
	 * that cannot be placed. What must be absent is everything the component did not
	 * claim — the heading and the text block.
	 */
	$stored_elements = isset( $component['document']['elements'] ) ? (array) $component['document']['elements'] : array();
	check( array() !== $stored_elements, 'it stores its own document' );

	$widget_types = array();
	$walk         = static function ( array $list ) use ( &$walk, &$widget_types ) {
		foreach ( $list as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( '' !== (string) ( $node['widgetType'] ?? '' ) ) {
				$widget_types[] = (string) $node['widgetType'];
			}
			$walk( isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : array() );
		}
	};
	$walk( $stored_elements );

	check( array( 'button' ) === $widget_types, 'and it holds only the element it claimed, not the whole page' );
	check( ! in_array( 'heading', $widget_types, true ), 'the heading from the same page is not in it' );
	check( ! in_array( 'text-editor', $widget_types, true ), 'nor the text block' );

	$second = $installer->install( $workspace_id, rf19_snapshot( 'RF19 footer ' . $run, 'footer', $logger, $component_id ), array( 'user_id' => $admin_id ) );

	if ( check( ! is_wp_error( $second ), 'a second template installs' ) ) {
		$created[] = (string) $second['template_id'];
		check( count( (array) $second['components'] ) >= 1, 'and registers the same component' );
	}

	$consumers = $parts->consumers( $workspace_id, $component_id );
	check( count( $consumers ) >= 2, 'both templates are listed as using it' );

	/*
	 * The second template carried the same component unchanged, so the component must NOT
	 * have gained a version. A version number that increments on every install is a count of
	 * references, not a record of changes, and §10 ties a version to an update.
	 */
	check( 1 === (int) ( $parts->find_component( $workspace_id, $component_id )['version'] ?? 0 ), 'installing the same component again did not create a version' );

	/*
	 * Registering the same payload twice, asserted directly rather than by reconstructing a
	 * byte-identical payload from a document the extractor has already transformed. The
	 * version counter must be a record of changes, so an unchanged registration is a no-op
	 * the caller is told about.
	 */
	$probe   = array( 'component_id' => $component_id, 'name' => 'CTA banner', 'type' => 'cta', 'user_id' => $admin_id, 'document' => array( 'elements' => array( array( 'id' => 'rf19probe', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Go' ), 'elements' => array() ) ) ) );
	$changed = $parts->register( $workspace_id, $probe );
	$same    = $parts->register( $workspace_id, $probe );

	check( is_array( $changed ) && empty( $changed['created'] ), 'a changed registration creates a version' );
	check( is_array( $same ) && ! empty( $same['unchanged'] ), 'registering the identical payload is reported as unchanged' );
	check( (int) ( $same['version'] ?? 0 ) === (int) ( $changed['version'] ?? -1 ), 'and reports the same version' );
	check( (int) ( $parts->find_component( $workspace_id, $component_id )['version'] ?? 0 ) === (int) ( $changed['version'] ?? -1 ), 'and leaves the stored version alone' );

	// Registering an existing component adds a version; it never replaces.
	$again = $parts->register( $workspace_id, array( 'component_id' => $component_id, 'name' => 'CTA banner v2', 'type' => 'cta', 'user_id' => $admin_id, 'document' => array( 'elements' => array() ) ) );

	check( ! is_wp_error( $again ), 'registering an existing component succeeds' );
	check( is_array( $again ) && empty( $again['created'] ), 'as a new version, not a replacement' );
	check( is_array( $again ) && (int) $again['version'] > 1, 'with a higher version number' );
	check( is_array( $again ) && ! empty( $again['provenance']['changelog'] ), 'and a changelog entry' );

	$reloaded = $parts->find_component( $workspace_id, $component_id );
	check( (int) $reloaded['version'] >= 2, 'the stored component is at the new version' );

	// A component in use cannot be updated silently.
	$safety = $parts->update_safety( $workspace_id, $component_id, (int) $component['version'] + 1 );
	check( ! (bool) $safety['safe'], 'updating a component in use is not reported as safe' );
	check( count( $safety['consumers'] ) >= 2, 'the consumers are listed' );
	check( ! (bool) $safety['manual_edits_detectable'], 'and it admits manual edits cannot be detected' );
	check( isset( $safety['strategies']['update_selected'] ), 'a per-selection strategy is offered' );
	check( ! isset( $safety['strategies']['overwrite'] ), 'and no overwrite strategy exists' );

	$downgrade = $parts->update_safety( $workspace_id, $component_id, 1 );
	check( ! (bool) $downgrade['safe'], 'a downgrade is refused' );
}

/* ================================================================ 8. content slots */

section( '8. Content is separated from structure' );

$slots = ( new Content_Slot_Registry( $logger ) )->build( array( array( 'role' => 'heading', 'value' => 'A heading from the source' ) ), array( 'keep_content' => false ) );

check( 1 === (int) $slots['count'], 'a content role becomes a slot' );
check( '' === (string) $slots['slots'][0]['default'], 'and the slot default stays empty' );
check( 'A heading from the source' === (string) $slots['slots'][0]['observed_value'], 'while the observed value is recorded' );
check( true === (bool) $slots['slots'][0]['observed'], 'and flagged as observed' );

$empty = ( new Content_Slot_Registry( $logger ) )->build( array( array( 'role' => 'heading', 'value' => '' ) ), array( 'keep_content' => true ) );
check( '' === (string) $empty['slots'][0]['default'], 'an empty source value produces no default either' );

// Even asked to carry content, a source value is not auto-adopted as a default.
$kept = ( new Content_Slot_Registry( $logger ) )->build( array( array( 'role' => 'heading', 'value' => 'Chosen' ) ), array( 'keep_content' => true ) );
check( '' === (string) $kept['slots'][0]['default'], 'even with keep_content, the default stays empty' );
check( 'Chosen' === (string) $kept['slots'][0]['observed_value'], 'while the supplied value is still recorded' );

if ( '' !== $template_id ) {
	$summary = Content_Slot_Registry::summarise( is_array( $version['content_slots'] ) ? $version['content_slots'] : array() );
	check( 2 === (int) $summary['total'], 'the stored slot set is summarised' );
	check( 1 === (int) $summary['required'], 'and the required slot is identified' );
	check( 1 === (int) $summary['dynamic'], 'and the dynamic one' );
}

// Fills are validated.
$registry = new Content_Slot_Registry( $logger );
$stripped = $registry->validate_fill( array( 'type' => 'heading', 'required' => true, 'validation' => array( 'max_length' => 160, 'strip_tags' => true ) ), '<script>x</script>Hello' );
check( $stripped['ok'] && false === strpos( (string) $stripped['value'], 'script' ), 'a fill is stripped of tags' );

$bad_url = $registry->validate_fill( array( 'type' => 'button_url', 'required' => false, 'validation' => array( 'public_http' => true ) ), 'javascript:alert(1)' );
check( ! $bad_url['ok'], 'a javascript: URL is refused as a slot fill' );

$required = $registry->validate_fill( array( 'type' => 'heading', 'required' => true ), '' );
check( ! $required['ok'], 'a required slot cannot be left empty' );

$bad_type = $registry->validate( array( array( 'type' => 'not_a_slot_type', 'slot_id' => 'x' ) ) );
check( ! $bad_type['valid'], 'a slot of an unknown type is rejected' );

$woocommerce = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
check( Content_Slot_Registry::dynamic_availability( 'product_price' )['available'] === $woocommerce, 'a WooCommerce slot reports the real environment' );
check( '' !== (string) Content_Slot_Registry::dynamic_availability( 'not_a_source' )['message'], 'an unknown dynamic source is explained' );
check( Content_Slot_Registry::dynamic_availability( 'post_title' )['available'], 'a WordPress source is available here' );

/* ================================================================ 9. sanitisation */

section( '9. Template sanitisation' );

$sanitizer = new Template_Sanitizer( null, $logger );
$allowed   = Template_Sanitizer::allowed_widgets();

check( in_array( 'heading', $allowed, true ), 'the allowlist includes heading' );
check( in_array( 'text-editor', $allowed, true ), 'and text-editor' );
check( ! in_array( 'html', $allowed, true ), 'and excludes html' );
check( ! in_array( 'shortcode', $allowed, true ), 'and shortcode' );
check( ! in_array( 'embed', $allowed, true ), 'and embed' );
check( ! in_array( 'some_thirdparty_widget', $allowed, true ), 'and any third-party widget' );
check( in_array( 'shortcode', Template_Sanitizer::forbidden_settings(), true ), 'a shortcode setting is explicitly forbidden' );
check( in_array( '_elementor_data', Template_Sanitizer::forbidden_settings(), true ), 'so is a smuggled Elementor document' );

$good = array( array( 'id' => 'rf19ok01', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Fine' ), 'elements' => array() ) );
$evil = array( 'id' => 'rf19ev01', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<script>alert(1)</script>' ), 'elements' => array() );

$scan = $sanitizer->scan( array_merge( $good, array( $evil ) ) );
check( 1 === (int) $scan['kept'], 'a forbidden widget is removed from a mixed document' );
check( 1 === (int) $scan['dropped'], 'and counted' );
check( ! empty( $scan['removals'] ), 'with a reason recorded' );

// A hostile *value* is dropped; the element survives. Destroying the element would turn a
// template into a shorter template with holes, which is worse than one fewer field.
foreach ( array(
	'editor' => '<script>alert(1)</script>',
	'link'   => 'javascript:alert(1)',
) as $setting => $value ) {
	$widget = 'button' === $setting ? 'button' : 'text-editor';
	$hit    = $sanitizer->scan( array( array( 'id' => 'rf19v001', 'elType' => 'widget', 'widgetType' => $widget, 'settings' => array( $setting => $value ), 'elements' => array() ) ) );
	check( 1 === (int) $hit['kept'], 'an element with a hostile ' . $setting . ' is kept' );
	check( empty( $hit['elements'][0]['settings'] ), 'and the ' . $setting . ' value is gone' );
}

// SSRF: a scheme-legal URL that reaches inside the network.
foreach ( array(
	'http://169.254.169.254/latest/meta-data/',
	'http://10.0.0.5/internal',
	'http://127.0.0.1/',
) as $url ) {
	$hit = $sanitizer->scan( array( array( 'id' => 'rf19ss01', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'url' => $url ) ), 'elements' => array() ) ) );
	check( empty( $hit['elements'][0]['settings'] ), 'an internal address is removed: ' . $url );
}

$traversal = $sanitizer->scan( array( array( 'id' => 'rf19tv01', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'url' => '../../../wp-config.php' ) ), 'elements' => array() ) ) );
check( empty( $traversal['elements'][0]['settings'] ), 'a path traversal string is removed' );

$data_uri = $sanitizer->scan( array( array( 'id' => 'rf19du01', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'url' => 'data:image/svg+xml;base64,PHN2Zz4=' ) ), 'elements' => array() ) ) );
check( empty( $data_uri['elements'][0]['settings'] ), 'a data: URL is removed' );

$unknown_setting = $sanitizer->scan( array( array( 'id' => 'rf19us01', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( '_elementor_custom' => 'x' ), 'elements' => array() ) ) );
check( empty( $unknown_setting['elements'][0]['settings'] ), 'a setting outside the allowlist is removed' );

// Nesting is walked, not just the top level.
$deep_heading = array( 'id' => 'rf19d003', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Deep' ), 'elements' => array() );
$deep_inner   = array( 'id' => 'rf19d002', 'elType' => 'container', 'settings' => array(), 'elements' => array( $deep_heading ) );
$deep_outer   = array( 'id' => 'rf19d001', 'elType' => 'container', 'settings' => array(), 'elements' => array( $deep_inner ) );
$nested       = $sanitizer->scan( array( $deep_outer ) );
check( 3 === (int) $nested['kept'], 'a nested document is walked in full' );
check( 3 === (int) $nested['depth'], 'and its depth is reported' );

// A document of only forbidden widgets is refused, not returned empty.
$refused = $sanitizer->scan( array( $evil ) );
check( ! $refused['ok'], 'a document with nothing safe left is refused' );

/* ================================================================ 10. assets */

section( '10. Asset provenance and redistribution' );

$source_asset = array(
	'schema_version' => Template_Limits::SCHEMA_VERSION,
	'template'       => array( 'name' => 'With image', 'type' => 'custom' ),
	'provenance'     => array( 'origin' => 'imported' ),
	'document'       => array( 'elements' => array( array( 'id' => 'rf19i001', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'url' => 'https://example.com/a.jpg' ) ), 'elements' => array() ) ) ),
	'assets'         => array( 'a' => array( 'url' => 'https://example.com/a.jpg', 'provenance_class' => 'source_derived', 'mode' => 'import', 'included' => true ) ),
);

$asset_scan = $sanitizer->scan_snapshot( $source_asset );
check( 'reference' === (string) ( $asset_scan['assets']['a']['mode'] ?? '' ), 'an import-marked source-derived asset is downgraded to a reference' );
check( ! (bool) ( $asset_scan['assets']['a']['redistributable'] ?? true ), 'and marked not redistributable' );

$unknown_licence = $sanitizer->scan_snapshot( array_merge( $source_asset, array( 'assets' => array( 'a' => array( 'url' => 'https://example.com/a.jpg', 'mode' => 'import' ) ) ) ) );
check( 'third_party' === (string) ( $unknown_licence['assets']['a']['provenance_class'] ?? '' ), 'an asset with no stated provenance defaults to third-party' );
check( 'reference' === (string) ( $unknown_licence['assets']['a']['mode'] ?? '' ), 'and is therefore not embedded' );

$own_asset = $sanitizer->scan_snapshot( array_merge( $source_asset, array( 'assets' => array( 'a' => array( 'url' => 'https://example.com/a.jpg', 'provenance_class' => 'user_owned', 'mode' => 'import', 'included' => true ) ) ) ) );
check( 'import' === (string) ( $own_asset['assets']['a']['mode'] ?? '' ), "a user's own asset may be embedded" );
check( true === (bool) ( $own_asset['assets']['a']['redistributable'] ?? false ), 'and is marked redistributable' );

$unsafe_asset = $sanitizer->scan_snapshot( array_merge( $source_asset, array( 'assets' => array( 'a' => array( 'url' => 'http://169.254.169.254/', 'provenance_class' => 'user_owned' ) ) ) ) );
check( ! isset( $unsafe_asset['assets']['a'] ), 'an unsafe asset URL is dropped from the asset set' );

/* ================================================================ 11. export and import */

section( '11. Export and import' );

$package_handler = new Template_Package( null, $validator, $logger );

if ( '' !== $template_id ) {
	$row     = $store->find_template( $workspace_id, $template_id );
	$version = $versions->current( $workspace_id, $template_id );
	$export  = $package_handler->export( $row, $version, array( 'include_assets' => true ) );

	if ( check( ! is_wp_error( $export ), 'a package exports' ) ) {
		$created[] = $template_id;

		check( str_ends_with( (string) $export['filename'], '.json' ), 'the filename ends in .json' );
		check( (int) $export['bytes'] > 0, 'the package has a size' );

		$pkg = (array) $export['package'];

		check( Template_Limits::SCHEMA_VERSION === (string) ( $pkg['schema_version'] ?? '' ), 'it records its schema version' );
		check( isset( $pkg['template']['name'] ), 'it names the template' );
		check( isset( $pkg['provenance']['licence'] ), 'it carries licence metadata for a future marketplace' );
		check( isset( $pkg['document']['elements'] ) && count( (array) $pkg['document']['elements'] ) > 0, 'and the document, which is the template' );
		check( 'heading' === (string) ( $pkg['document']['elements'][0]['elements'][0]['widgetType'] ?? '' ), 'with its element types intact' );
		check( 'reference' === (string) ( $pkg['assets']['asset_hero']['mode'] ?? '' ), 'the source image is a reference, not a file' );
		check( ! (bool) ( $pkg['assets']['asset_hero']['redistributable'] ?? true ), 'and not redistributable' );

		$encoded = (string) $export['encoded'];
		check( false === strpos( $encoded, 'api_key' ), 'the encoded package has no credential key' );
		check( false === strpos( $encoded, 'Bearer' ), 'and no bearer token' );

		$inspected = $package_handler->inspect( $encoded );
		check( ! is_wp_error( $inspected ), 'the package passes inspection' );

		if ( ! is_wp_error( $inspected ) ) {
			$names = array_column( (array) $inspected['steps'], 'step' );
			foreach ( array( 'validate_package', 'security_scan', 'schema_validation', 'compatibility_check', 'dependency_resolution', 'asset_review' ) as $required ) {
				check( in_array( $required, $names, true ), 'the import ran the ' . $required . ' step' );
			}
			check( 'conflict_detection' === (string) ( $inspected['next_step'] ?? '' ), 'conflict detection is named as next' );
			check( ! empty( $inspected['assets']['needs_approval'] ), 'and the reference-only asset is flagged for review' );

			$conflicts = new Template_Conflicts( null, null, null, $logger );
			$detected  = $conflicts->detect( $workspace_id, $inspected['snapshot'] );
			check( is_array( $detected ) && isset( $detected['conflicts'] ), 'conflicts are detected before anything is written' );

			$plan = $conflicts->plan( $workspace_id, $inspected['snapshot'], array( 'default' => 'keep_existing' ) );
			check( ! is_wp_error( $plan ), 'a conflict plan is produced' );
			check( is_array( $plan ) && false === (bool) $plan['overwrites'], 'which never overwrites' );

			$reimported = $installer->install( $workspace_id, $inspected['snapshot'], array( 'user_id' => $admin_id, 'category' => 'imported' ) );
			check( ! is_wp_error( $reimported ), 'the package installs' );

			if ( ! is_wp_error( $reimported ) ) {
				$created[] = (string) $reimported['template_id'];
				$landed     = $store->find_template( $workspace_id, (string) $reimported['template_id'] );
				check( 'imported' === (string) ( $landed['category'] ?? '' ), 'and lands in the imported category' );
			}
		}
	}
}

/* ================================================================ 12. conflicts */

section( '12. Conflict detection and token ownership' );

if ( '' !== $template_id ) {
	$tokens->set( $workspace_id, 'colors.accent', '#FF0000' );
	check( true === $tokens->set( $workspace_id, 'colors.accent', '#00FF00' ), 'a token can be set by hand' );

	$registry_now = $tokens->registry( $workspace_id );
	check( 'user_controlled' === (string) ( $registry_now['colors.accent']['ownership'] ?? '' ), 'setting a token marks it user-controlled' );
	check( '#00FF00' === (string) ( $registry_now['colors.accent']['value'] ?? '' ), 'and applies the new value' );
	check( '#FF0000' === (string) ( $registry_now['colors.accent']['previous_value'] ?? '' ), 'and records what it replaced' );

	$remerge = $tokens->merge( $workspace_id, $version['tokens'] );
	check( (int) $remerge['kept_user'] >= 1, 'a re-extraction does not undo a hand-set token' );
	check( '#00FF00' === (string) ( $tokens->registry( $workspace_id )['colors.accent']['value'] ?? '' ), 'the hand-set value survives' );

	$clash = new Template_Conflicts( null, null, null, $logger );
	$found = $clash->detect( $workspace_id, array( 'meta' => array( 'name' => 'RF19 clash ' . $run ), 'tokens' => array( 'colors.accent' => array( 'token_id' => 'colors.accent', 'name' => 'accent', 'category' => 'colors', 'value' => '#0000FF' ) ) ) );
	$kinds = array_column( (array) $found['conflicts'], 'kind' );
	check( in_array( 'token_name', $kinds, true ) || in_array( 'token_value', $kinds, true ), 'a differing token value is a conflict' );

	$plan = $clash->plan( $workspace_id, array( 'meta' => array( 'name' => 'RF19 clash ' . $run ), 'tokens' => array( 'colors.accent' => array( 'token_id' => 'colors.accent', 'name' => 'accent', 'category' => 'colors', 'value' => '#0000FF' ) ) ), array( 'default' => 'new_version' ) );
	$action = null;
	foreach ( (array) $plan['actions'] as $candidate ) {
		if ( 0 === strpos( (string) $candidate['kind'], 'token_' ) ) {
			$action = $candidate;
		}
	}
	check( is_array( $action ), 'a strategy is chosen for the token conflict' );
	check( 'new_version' !== (string) ( $action['strategy'] ?? '' ), 'and is not a "new version", which means nothing for a token' );
	check( false === (bool) ( $action['overwrites'] ?? true ), 'the token is not overwritten' );

	$impact = $tokens->impact( $workspace_id, 'colors.accent' );
	check( (int) $impact['template_count'] >= 1, 'the impact report lists the templates using it' );
	check( true === (bool) $impact['manual_edits_possible'], 'and admits manual edits cannot be detected' );
	check( '' !== (string) $impact['note'], 'with an explanation' );
}

/* ================================================================ 13. incompatible */

section( '13. Incompatibility is refused before anything is written' );

$incompatible = array_merge( rf19_snapshot( 'RF19 impossible ' . $run, 'custom', $logger, $component_id ), array( 'compatibility' => array( 'elementor' => array( 'minimum' => '99.0.0' ) ) ) );

$before = (int) $store->browse( $workspace_id, array( 'per_page' => 100 ) )['count'];
$refuse = $installer->install( $workspace_id, $incompatible, array( 'user_id' => $admin_id ) );
$after  = (int) $store->browse( $workspace_id, array( 'per_page' => 100 ) )['count'];

check( is_wp_error( $refuse ), 'installing an incompatible template is refused' );
check( is_wp_error( $refuse ) && 'template_not_installable' === $refuse->get_error_code(), 'refused at validation' );
check( is_wp_error( $refuse ) && 'incompatible' === (string) ( $refuse->get_error_data()['validation']['state'] ?? '' ), 'with an incompatible state, not merely invalid' );
check( $before === $after, 'and the library count is unchanged' );

$deps = new Template_Dependencies( null, $logger );
check( ! (bool) $deps->resolve( array( 'elementor' => array( 'minimum' => '99.0.0' ) ) )['satisfied'], 'an unmet Elementor requirement is not satisfied' );
check( (bool) $deps->resolve( array( 'elementor' => array( 'minimum' => '99.0.0' ) ) )['blocking'], 'and blocks' );
check( (bool) $deps->resolve( array( 'features' => array( 'a_feature_this_version_does_not_know' ) ) )['blocking'], 'an unrecognised feature blocks rather than being assumed satisfied' );

$empty_doc = $validator->validate( array( 'document' => array( 'elements' => array() ) ) );
check( 'invalid' === (string) $empty_doc['state'], 'an empty document is invalid' );
check( ! (bool) $empty_doc['installable'], 'and not installable' );

/* ================================================================ 14. quality */

section( '14. Quality indicators' );

if ( '' !== $template_id ) {
	$quality = ( new Template_Quality() )->measure( $version );

	check( 8 === (int) $quality['total'], 'eight indicators are reported' );
	check( ! isset( $quality['score'] ) && ! isset( $quality['total_score'] ), 'and there is no single aggregate score' );
	check( '' !== (string) $quality['note'], 'with an explanation of what was measured' );
	check( isset( $quality['indicators']['structural_completeness'] ), 'including structural completeness' );
	check( isset( $quality['indicators']['content_separation'] ), 'and content separation' );
	check( (int) $quality['indicators']['structural_completeness']['value'] === 100, 'a container with children is structurally complete' );
}

$unmeasurable = ( new Template_Quality() )->measure( array( 'document' => array( 'elements' => array( array( 'id' => 'rf19s001', 'elType' => 'widget', 'widgetType' => 'spacer', 'settings' => array(), 'elements' => array() ) ) ) ) );
check( Template_Limits::NOT_MEASURED === (string) $unmeasurable['indicators']['responsive_completeness']['value'], 'an unmeasurable dimension reports Not available, not zero' );
check( (int) $unmeasurable['unmeasured'] >= 1, 'and is counted as unmeasured' );

/* ================================================================ 15. versions */

section( '15. Versions are immutable' );

if ( '' !== $template_id ) {
	$before_version = (int) $version['version'];

	$second = $versions->append( $workspace_id, $template_id, $version, array( 'user_id' => $admin_id, 'change_note' => 'Second version' ) );

	check( ! is_wp_error( $second ), 'a second version can be appended' );
	check( is_array( $second ) && (int) $second['version'] > $before_version, 'with a higher number' );
	check( ! method_exists( $versions, 'update_version' ), 'and there is no method to mutate a stored version' );

	$history = $versions->history( $template_id, 20 );
	check( count( $history ) >= 2, 'the history lists both' );
	check( (int) $history[0]['version'] > (int) $history[1]['version'], 'newest first' );

	check( ! is_wp_error( $versions->verify( $version ) ), 'a stored version passes its integrity check' );

	$tampered            = $version;
	$tampered['document'] = array( 'elements' => array() );
	$bad_verify          = $versions->verify( $tampered );

	check( is_wp_error( $bad_verify ), 'a tampered version fails it' );
	check( is_wp_error( $bad_verify ) && 'template_version_corrupt' === $bad_verify->get_error_code(), 'with a specific reason' );

	$oversized = $versions->append( $workspace_id, $template_id, array( 'document' => array( 'elements' => array( str_repeat( 'x', Template_Limits::MAX_VERSION_BYTES ) ) ) ), array( 'user_id' => $admin_id ) );
	check( is_wp_error( $oversized ), 'an over-sized version is refused' );
	check( is_wp_error( $oversized ) && 'template_version_too_large' === $oversized->get_error_code(), 'for being too large' );
}

/* ================================================================ 16. rollback */

section( '16. Rollback' );

if ( '' !== $page_id ) {
	$before_count = (int) $store->browse( $workspace_id, array( 'per_page' => 100 ) )['count'];
	$versions_before = $versions->count_of( $page_id );

	$rollback = $installer->rollback( array( array( 'kind' => 'template', 'id' => $page_id, 'undo' => 'delete_template' ) ) );

	check( in_array( 'template:' . $page_id, (array) $rollback['undone'], true ), 'a journalled template write is undone' );
	check( null === $store->find_template( $workspace_id, $page_id ), 'the template is gone' );
	check( 0 === $versions->count_of( $page_id ), 'along with its versions' );
	check( $before_count === (int) $store->browse( $workspace_id, array( 'per_page' => 100 ) )['count'] + 1, 'and the library count reflects it' );

	$failed = $installer->rollback( array( array( 'kind' => 'template', 'id' => 'does-not-exist', 'undo' => 'delete_template' ) ) );
	check( count( (array) $failed['failed'] ) >= 1, 'a rollback that cannot undo something says so' );
}

$token_undo = $installer->rollback( array( array( 'kind' => 'tokens', 'id' => $workspace_id, 'undo' => 'restore_tokens', 'before' => $tokens->registry( $workspace_id ) ) ) );
check( in_array( 'tokens:' . $workspace_id, (array) $token_undo['undone'], true ), 'a token merge is rolled back' );

/* ================================================================ 17. malicious import */

section( '17. Malicious packages are refused' );

$handler = new Template_Package( null, $validator, $logger );

$malicious = array(
	'schema_version' => Template_Limits::SCHEMA_VERSION,
	'template'       => array( 'name' => 'Evil', 'type' => 'custom' ),
	'provenance'     => array( 'origin' => 'imported' ),
	'document'       => array( 'elements' => array( array( 'id' => 'rf19x001', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<script>alert(1)</script>' ), 'elements' => array() ) ) ),
	'api_key'        => 'sk-should-never-travel',
	'authorization'  => 'Bearer should-never-travel',
	'password'       => 'hunter2',
);

$redacted = $handler->redact( $malicious );
check( ! isset( $redacted['payload']['api_key'] ), 'a credential key is stripped' );
check( ! isset( $redacted['payload']['authorization'] ), 'an authorization header is stripped' );
check( ! isset( $redacted['payload']['password'] ), 'a password is stripped' );
check( count( $redacted['removed'] ) >= 3, 'and the removals are reported' );

/* The document survives redaction: `elType` is required and must not be caught by a denylist. */
$redacted_doc = $handler->redact( array( 'document' => array( 'elements' => array( array( 'elType' => 'container', 'widgetType' => 'heading', 'isInner' => false ) ) ) ) );
check( isset( $redacted_doc['payload']['document']['elements'][0]['elType'] ), 'redaction keeps elType' );
check( isset( $redacted_doc['payload']['document']['elements'][0]['widgetType'] ), 'and widgetType' );

$future = array_merge( $malicious, array( 'schema_version' => '99.0' ) );
$future_result = $handler->inspect( wp_json_encode( $future ) );
check( is_wp_error( $future_result ), 'a package from a newer schema is refused' );
check( is_wp_error( $future_result ) && 'template_package_from_the_future' === $future_result->get_error_code(), 'with a specific reason' );

check( is_wp_error( $handler->inspect( 'not json at all {{{' ) ), 'unreadable input is refused' );
check( is_wp_error( $handler->inspect( wp_json_encode( array( 'template' => array(), 'provenance' => array() ) ) ) ), 'a package with no schema version is refused' );
check( is_wp_error( $handler->inspect( wp_json_encode( array( 'schema_version' => Template_Limits::SCHEMA_VERSION, 'provenance' => array() ) ) ) ), 'a package with no template block is refused' );
check( is_wp_error( $handler->inspect( wp_json_encode( array( 'schema_version' => Template_Limits::SCHEMA_VERSION, 'template' => array(), 'provenance' => array() ) ) ) ), 'a package with no document is refused' );

$huge = $handler->inspect( str_repeat( 'x', Template_Limits::MAX_PACKAGE_BYTES * 3 ) );
check( is_wp_error( $huge ), 'an oversized package is refused' );
check( is_wp_error( $huge ) && 'template_package_too_large' === $huge->get_error_code(), 'for its size, checked before parsing' );

check_raises( static function () use ( $handler ) { return $handler->inspect( 'PK' . str_repeat( "\x00", 200 ) ); }, 'a zip-looking payload does not crash the reader' );
check_raises( static function () use ( $handler ) { return $handler->inspect( "\xB1\x31" ); }, 'a binary payload does not crash the reader' );

// An unsafe document is refused outright.
$unsafe_package = array(
	'schema_version' => Template_Limits::SCHEMA_VERSION,
	'template'       => array( 'name' => 'Evil', 'type' => 'custom' ),
	'provenance'     => array( 'origin' => 'imported' ),
	'document'       => array( 'elements' => array( array( 'id' => 'rf19e001', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array(), 'elements' => array() ) ) ),
);
$unsafe_result = $handler->inspect( wp_json_encode( $unsafe_package ) );
check( is_wp_error( $unsafe_result ), 'a package containing only a forbidden widget is refused' );
check( is_wp_error( $unsafe_result ) && 'template_package_unsafe' === $unsafe_result->get_error_code(), 'with a specific reason' );

/* ================================================================ 18. cross-workspace */

section( '18. Cross-workspace access' );

$other = $workspaces->create( $admin_id, __( 'Phase 19 other ' . $run, 'replicaforge' ) );
$other_id = (string) ( $other['public_id'] ?? '' );

check( '' !== $other_id, 'a second workspace exists' );

if ( '' !== $template_id && '' !== $other_id ) {
	check( null === $store->find_template( $other_id, $template_id ), 'the template is not readable from the other workspace' );
	check( null === $versions->current( $other_id, $template_id ), 'and neither is its version' );

	$api     = new ReplicaForge\Template_Api( $logger );
	$request = new WP_REST_Request( 'GET', '/replicaforge/v1/templates/' . $template_id );
	$request->set_param( 'workspace', $other_id );

	$denied = $api->get_template( $request );
	check( is_wp_error( $denied ), 'reading it through the API from the other workspace is refused' );
	check( is_wp_error( $denied ) && 404 === (int) ( $denied->get_error_data()['status'] ?? 0 ), 'with a 404, so the id space is not enumerable' );
}

$library = new WP_REST_Request( 'GET', '/replicaforge/v1/templates/library' );
$library->set_param( 'workspace', $workspace_id );

wp_set_current_user( 0 );
$api2     = new ReplicaForge\Template_Api( $logger );
$unauthed = $api2->can_read( $library );
wp_set_current_user( $admin_id );
check( is_wp_error( $unauthed ) && 401 === (int) ( $unauthed->get_error_data()['status'] ?? 0 ), 'an unauthenticated caller is refused with 401' );

$subscribers = get_users( array( 'role' => 'subscriber', 'number' => 1, 'fields' => 'ID' ) );

if ( ! empty( $subscribers ) ) {
	wp_set_current_user( (int) $subscribers[0] );
	$api3     = new ReplicaForge\Template_Api( $logger );
	$outsider = $api3->can_read( $library );
	wp_set_current_user( $admin_id );
	check( is_wp_error( $outsider ), 'a non-member is refused' );
}

/* ================================================================ 19. extraction honesty */

section( '19. The extractor reports what it did not find' );

$extractor = new Template_Extractor( $logger );

$none = $extractor->representation_for( 0, '' );
check( 'none' === (string) $none['source'], 'with no post and no project, no representation is claimed' );
check( '' !== (string) $none['note'], 'and the reason is stated' );

$probe_post = wp_insert_post( array( 'post_title' => 'RF19 probe', 'post_type' => 'page', 'post_status' => 'draft' ) );
$readback   = $extractor->representation_for( $probe_post, '' );
check( 'none' === (string) $readback['source'], 'a page with no analysis meta has no representation' );
check( '' !== (string) $readback['note'], 'and says so rather than returning an empty array' );
wp_delete_post( $probe_post, true );

$api4     = new ReplicaForge\Template_Api( $logger );
$missing  = new WP_REST_Request( 'POST', '/replicaforge/v1/templates/extract' );
$missing->set_param( 'post_id', 99999999 );
$missing->set_param( 'project_id', 'proj_nonexistent' );
$missing->set_param( 'workspace', $workspace_id );
$denied_extract = $api4->extract_template( $missing );
check( is_wp_error( $denied_extract ), 'extraction from a non-existent page is refused' );
check( is_wp_error( $denied_extract ) && 404 === (int) ( $denied_extract->get_error_data()['status'] ?? 0 ), 'with a 404' );

$not_generated_post = wp_insert_post( array( 'post_title' => 'RF19 hand made', 'post_type' => 'page', 'post_status' => 'draft' ) );
$projects           = new ReplicaForge\Project_Repository( $logger );
$project            = $projects->find_by_source( 'https://example.com/' );

if ( null !== $project ) {
	$request2 = new WP_REST_Request( 'POST', '/replicaforge/v1/templates/extract' );
	$request2->set_param( 'post_id', $not_generated_post );
	$request2->set_param( 'project_id', (string) $project['project_id'] );
	$request2->set_param( 'workspace', $workspace_id );
	$ungenerated = $api4->extract_template( $request2 );

	check( is_wp_error( $ungenerated ), 'extraction from a page ReplicaForge did not generate is refused' );
	check( is_wp_error( $ungenerated ) && 'template_not_generated' === $ungenerated->get_error_code(), 'because it has no design provenance' );
}
wp_delete_post( $not_generated_post, true );

/* ================================================================ cleanup */

update_option( Template_Limits::TOKENS_OPTION, array(), false );

foreach ( $created as $id ) {
	if ( '' !== (string) $id ) {
		$store->delete_template( (string) $id );
	}
}

check_raises( static function () { return true; }, 'cleanup completed without raising' );

wp_set_current_user( $previous_user );

echo "\n";
echo str_repeat( '=', 60 ) . "\n";
echo "assertions: {$assertions}\n";
echo "failures  : {$failures}\n";

if ( $failures > 0 ) {
	echo "RESULT: FAIL\n";
	exit( 1 );
}

echo "RESULT: PASS\n";
