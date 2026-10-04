<?php
/**
 * Phase 16 test suite: interaction intelligence.
 *
 * @package ReplicaForge
 *
 * Run with:  run-test.php <wp-root> <this-file>
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a test harness, not a request handler.

global $rf16_failures, $rf16_assertions, $rf16_warnings;
$rf16_failures  = 0;
$rf16_assertions = 0;
$rf16_warnings  = array();

/**
 * Assert.
 *
 * @param bool   $condition Condition.
 * @param string $label     Label.
 * @param string $detail    Detail.
 * @return bool
 */
function rf16_check( $condition, $label, $detail = '' ) {
	global $rf16_failures, $rf16_assertions, $rf16_warnings;
	$rf16_assertions++;

	$condition = (bool) $condition;

	if ( ! $condition ) {
		$rf16_failures++;
	}
	printf( "%s: %s%s\n", $condition ? 'PASS' : 'FAIL', $label, '' !== (string) $detail ? '  [' . $detail . ']' : '' );

	return $condition;
}

$rf16_php_errors = array();
set_error_handler(
	static function ( $severity, $message, $file, $line ) use ( &$rf16_php_errors ) {
		// Notices and warnings from plugin code are failures, not noise. A phase that
		// emits them has a real defect, and a suite that tolerated them would be
		// reporting a green run that hides it.
		$rf16_php_errors[] = $message . ' @ ' . basename( (string) $file ) . ':' . (int) $line;
		return true;
	}
);

$rf16_users  = array();
$rf16_models = array();

register_shutdown_function(
	static function () use ( &$rf16_users, &$rf16_models, &$rf16_php_errors ) {
		$plugin = \ReplicaForge\Plugin::instance();
		if ( $plugin instanceof \ReplicaForge\Plugin && null !== $plugin->interactions() ) {
			// Nothing to truncate: interaction models are options, cleaned below.
		}

		foreach ( $rf16_models as $option ) {
			delete_option( $option );
		}
		foreach ( $rf16_users as $user_id ) {
			if ( is_numeric( $user_id ) && (int) $user_id > 0 ) {
				wp_delete_user( (int) $user_id );
			}
		}
		delete_option( 'replicaforge_interaction_driver_seen' );

		if ( array() !== $rf16_php_errors ) {
			echo "\nplugin warnings/notices (" . count( $rf16_php_errors ) . "):\n";
			foreach ( array_slice( $rf16_php_errors, 0, 8 ) as $error ) {
				echo '  ' . $error . "\n";
			}
		}
	}
);

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use ReplicaForge\Analysis_Limits;
use ReplicaForge\Browser_Driver_Contract;
use ReplicaForge\Dom_Analyzer;
use ReplicaForge\Elementor_Compatibility;
use ReplicaForge\Interaction_Detector;
use ReplicaForge\Interaction_Limits;
use ReplicaForge\Interaction_Mapper;
use ReplicaForge\Interaction_Model;
use ReplicaForge\Interaction_Service;
use ReplicaForge\Interaction_Validator;
use ReplicaForge\Plugin;
use ReplicaForge\Project_Repository;
use ReplicaForge\State_Machine;

/**
 * Create a user.
 *
 * @param string $role Role.
 * @return int
 */
function rf16_user( $role = 'administrator' ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'rf16_' . wp_rand( 10000, 99999 ),
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $role,
		)
	);

	global $rf16_users;
	$rf16_users[] = $id;

	return (int) $id;
}

/**
 * Build a phase 2 analysis context from markup.
 *
 * @param Dom_Analyzer $dom  Analyzer.
 * @param string       $html Markup.
 * @param string       $url  Base URL.
 * @return array<string, mixed>
 */
function rf16_context( Dom_Analyzer $dom, $html, $url = 'https://example.com/' ) {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>t</title></head><body>' . $html . '</body></html>' );
	libxml_clear_errors();

	return $dom->build( $doc, $url );
}

/**
 * Whether any interaction has a type.
 *
 * @param array<int, array<string, mixed>> $interactions Interactions.
 * @param string                          $type         Type.
 * @return bool
 */
function rf16_has_type( array $interactions, $type ) {
	foreach ( $interactions as $interaction ) {
		if ( (string) $interaction['type'] === $type ) {
			return true;
		}
	}

	return false;
}

$rf16_owner = rf16_user( 'administrator' );
wp_set_current_user( $rf16_owner );

$rf16_dom       = new Dom_Analyzer();
$rf16_detector  = new Interaction_Detector( $rf16_dom );
$rf16_service   = new Interaction_Service();
$rf16_mapper    = new Interaction_Mapper();
$rf16_validator = new Interaction_Validator();

echo "Phase 16 - Interaction Intelligence\n\n";
echo "== 1. The vocabulary is declared in one place ==\n";

rf16_check( '16.0' === Interaction_Limits::SCHEMA_VERSION, 'the interaction schema is 16.0', Interaction_Limits::SCHEMA_VERSION );
rf16_check( '16.0' === Interaction_Limits::PHASE, 'the phase is 16.0', Interaction_Limits::PHASE );
rf16_check( '' !== Interaction_Limits::ENGINE_VERSION, 'the engine version is declared, for the cache key', Interaction_Limits::ENGINE_VERSION );
rf16_check( count( Interaction_Limits::TYPES ) === 35, '35 interaction types are declared, including unknown', (string) count( Interaction_Limits::TYPES ) );
rf16_check( in_array( 'unknown', Interaction_Limits::TYPES, true ), 'unknown is a first-class type, not a failure' );
rf16_check( count( Interaction_Limits::TRIGGERS ) === 14, '14 triggers are declared', (string) count( Interaction_Limits::TRIGGERS ) );
rf16_check( count( Interaction_Limits::OUTCOMES ) === 4, '4 mapping outcomes are declared', (string) count( Interaction_Limits::OUTCOMES ) );
rf16_check( count( Interaction_Limits::STATUSES ) === 10, '10 analysis statuses are declared', (string) count( Interaction_Limits::STATUSES ) );

/*
 * TYPES *is* a list, and `array_key_exists( 0, TYPES )` is therefore true — which is exactly
 * why it is the wrong membership test, and why `is_type()` exists. An earlier version of
 * this assertion asserted the opposite and failed, and "fixing" it by weakening the
 * assertion would have hidden the exact trap phase 15 spent three defects on.
 */
rf16_check( array_key_exists( 0, Interaction_Limits::TYPES ), 'TYPES is a list, so array_key_exists() would be the wrong test' );
rf16_check( 'navigation' === Interaction_Limits::TYPES[0], 'and index 0 is a type name, which is the trap' );
rf16_check( Interaction_Limits::is_type( 'accordion' ), 'is_type() accepts a declared type' );
rf16_check( ! Interaction_Limits::is_type( 'not-a-type' ), 'is_type() rejects an undeclared one' );
rf16_check( ! Interaction_Limits::is_type( 0 ), 'is_type() rejects the index a list would have accepted' );
rf16_check( Interaction_Limits::is_trigger( 'click' ), 'is_trigger() works' );
rf16_check( ! Interaction_Limits::is_trigger( 'poke' ), 'is_trigger() rejects an undeclared trigger' );
rf16_check( Interaction_Limits::is_status( 'BROWSER_UNAVAILABLE' ), 'is_status() works' );
rf16_check( ! Interaction_Limits::is_status( 'DEFINITELY_DONE' ), 'is_status() rejects an invented status' );

echo "\n== 2. Existing vocabularies are read, not restated ==\n";

rf16_check(
	Interaction_Limits::viewports() === \ReplicaForge\Validation_Limits::VIEWPORTS,
	'viewports are read from Validation_Limits rather than restated',
	implode( ',', array_keys( Interaction_Limits::viewports() ) )
);
rf16_check(
	Interaction_Limits::severities() === \ReplicaForge\Validation_Limits::SEVERITIES,
	'severities are read from Validation_Limits rather than restated'
);
rf16_check( ! defined( 'ReplicaForge\Interaction_Limits::SEVERITIES' ), 'and there is no Phase 16 severity list to disagree with it' );
rf16_check( ! defined( 'ReplicaForge\Interaction_Limits::VIEWPORTS' ), 'and no Phase 16 viewport list either' );
rf16_check( in_array( 'interaction', \ReplicaForge\Validation_Limits::CATEGORIES, true ), 'phase 5 already declares the interaction category, so phase 16 populates it' );
rf16_check( in_array( 'navigation', \ReplicaForge\Validation_Limits::CATEGORIES, true ), 'and the navigation category' );
rf16_check( in_array( 'interaction', \ReplicaForge\Sync_Limits::CATEGORIES, true ), 'phase 9 already declares it for sync too' );

echo "\n== 3. Native disclosure ==\n";

$c = $rf16_detector->detect( rf16_context( $rf16_dom, '<details><summary>Shipping</summary><p>Body</p></details>' ) );
rf16_check( rf16_has_type( $c, 'accordion' ), 'a <details> is detected as an accordion', implode( ',', array_column( $c, 'type' ) ) );
$rf16_details = null;
foreach ( $c as $one ) { if ( 'accordion' === $one['type'] ) { $rf16_details = $one; } }
rf16_check( (float) $rf16_details['confidence'] >= 0.9, 'with high confidence', (string) $rf16_details['confidence'] );
rf16_check( false !== strpos( wp_json_encode( $rf16_details['evidence'] ), 'details_disclosure' ), 'and the rule is named in its evidence' );
rf16_check( 'collapsed' === $rf16_details['initial_state'], 'with the correct initial state', $rf16_details['initial_state'] );

echo "\n== 4. ARIA disclosure ==\n";

$c = $rf16_detector->detect( rf16_context( $rf16_dom, '<div id="p1" hidden>Body</div><button aria-expanded="false" aria-controls="p1">Toggle</button>' ) );
rf16_check( rf16_has_type( $c, 'expand_collapse' ), 'aria-expanded with a resolvable control is detected', implode( ',', array_column( $c, 'type' ) ) );
$rf16_expander = null;
foreach ( $c as $one ) { if ( 'expand_collapse' === $one['type'] ) { $rf16_expander = $one; } }
rf16_check( ! empty( $rf16_expander['controls'][0]['resolves'] ), 'the control resolves to a real element' );
rf16_check( false !== strpos( wp_json_encode( $rf16_expander['evidence'] ), 'aria-expanded' ), 'aria-expanded appears in the evidence' );

$c = $rf16_detector->detect( rf16_context( $rf16_dom, '<button aria-expanded="false" aria-controls="nope">Broken</button>' ) );
rf16_check( ! rf16_has_type( $c, 'expand_collapse' ), 'a trigger controlling nothing is NOT reported - a replica with a dead button is worse than none' );

echo "\n== 5. Tabs ==\n";

$c = $rf16_detector->detect(
	rf16_context(
		$rf16_dom,
		'<div role="tablist">'
		. '<button role="tab" aria-selected="true" aria-controls="p1">One</button>'
		. '<button role="tab" aria-selected="false" aria-controls="p2">Two</button>'
		. '</div><div role="tabpanel" id="p1">A</div><div role="tabpanel" id="p2">B</div>'
	)
);
rf16_check( rf16_has_type( $c, 'tabs' ), 'a tab set is detected', implode( ',', array_column( $c, 'type' ) ) );
rf16_check( 2 === count( array_filter( $c, static function ( $x ) { return 'tabs' === $x['type']; } ) ), 'both tabs are found' );
$rf16_first_tab = null;
foreach ( $c as $one ) { if ( 'tabs' === $one['type'] ) { $rf16_first_tab = $one; break; } }
rf16_check( ! empty( $rf16_first_tab['controls'][0]['resolves'] ), 'each tab resolves its panel' );

echo "\n== 6. Dialogs ==\n";

rf16_check( rf16_has_type( $rf16_detector->detect( rf16_context( $rf16_dom, '<dialog>Terms</dialog>' ) ), 'modal' ), 'a native <dialog> is a modal' );
rf16_check( rf16_has_type( $rf16_detector->detect( rf16_context( $rf16_dom, '<div role="dialog" aria-modal="true">Terms</div>' ) ), 'modal' ), 'role=dialog is a modal' );
rf16_check( rf16_has_type( $rf16_detector->detect( rf16_context( $rf16_dom, '<button data-bs-toggle="modal" data-bs-target="#m">Open</button>' ) ), 'modal' ), 'a bootstrap modal trigger is a modal' );

echo "\n== 7. Navigation, dropdowns, mobile menus ==\n";

$c = $rf16_detector->detect( rf16_context( $rf16_dom, '<nav aria-label="Main"><ul><li><a href="/a">A</a></li></ul></nav>' ) );
rf16_check( rf16_has_type( $c, 'navigation' ), 'a nav landmark is detected', implode( ',', array_column( $c, 'type' ) ) );

$c = $rf16_detector->detect(
	rf16_context(
		$rf16_dom,
		'<nav><ul><li><button aria-haspopup="true" aria-expanded="false" aria-controls="s1">Products</button>'
		. '<ul id="s1"><li><a href="/p">P</a></li></ul></li></ul></nav>'
	)
);
rf16_check( rf16_has_type( $c, 'dropdown' ), 'an aria-haspopup submenu trigger is a dropdown, not a generic disclosure', implode( ',', array_column( $c, 'type' ) ) );

$c = $rf16_detector->detect(
	rf16_context(
		$rf16_dom,
		'<button class="hamburger nav-toggle" aria-expanded="false" aria-controls="mnav">Menu</button><nav id="mnav" hidden>Links</nav>'
	)
);
rf16_check( rf16_has_type( $c, 'mobile_menu' ), 'a hamburger controlling a nav is a mobile menu', implode( ',', array_column( $c, 'type' ) ) );

$c = $rf16_detector->detect( rf16_context( $rf16_dom, '<button class="hamburger">Menu</button>' ) );
rf16_check( ! rf16_has_type( $c, 'mobile_menu' ), 'a bare hamburger class is not - the icon exists on desktop too' );

echo "\n== 8. Carousels, sticky, search, anchors ==\n";

rf16_check( rf16_has_type( $rf16_detector->detect( rf16_context( $rf16_dom, '<div class="carousel" data-ride="carousel"><div class="carousel-item">1</div></div>' ) ), 'carousel' ), 'data-ride=carousel is detected' );
rf16_check( rf16_has_type( $rf16_detector->detect( rf16_context( $rf16_dom, '<div role="region" aria-roledescription="carousel"><div class="slide">1</div></div>' ) ), 'carousel' ), 'aria-roledescription=carousel is detected' );
rf16_check( rf16_has_type( $rf16_detector->detect( rf16_context( $rf16_dom, '<header style="position:sticky;top:0">Bar</header>' ) ), 'sticky_header' ), 'inline position:sticky is detected' );
rf16_check( rf16_has_type( $rf16_detector->detect( rf16_context( $rf16_dom, '<form role="search" action="/s"><input type="search" name="q"></form>' ) ), 'search_overlay' ), 'a search landmark is detected' );
rf16_check( rf16_has_type( $rf16_detector->detect( rf16_context( $rf16_dom, '<a href="#section-2">Jump</a>' ) ), 'anchor_scroll' ), 'an in-page anchor is detected' );

echo "\n== 9. Ecommerce controls ==\n";

rf16_check( rf16_has_type( $rf16_detector->detect( rf16_context( $rf16_dom, '<div class="variations"><button class="variation">Red</button><button class="variation">Blue</button></div>' ) ), 'product_variation' ), 'a variation group is detected' );
$c = $rf16_detector->detect( rf16_context( $rf16_dom, '<div class="variations"><button class="variation">Red</button></div>' ) );
rf16_check( ! rf16_has_type( $c, 'product_variation' ), 'one swatch is not a variation group' );
rf16_check( rf16_has_type( $rf16_detector->detect( rf16_context( $rf16_dom, '<div class="quantity"><input type="text" name="quantity" value="1"></div>' ) ), 'quantity_selector' ), 'a quantity control is detected' );
rf16_check( rf16_has_type( $rf16_detector->detect( rf16_context( $rf16_dom, '<select name="orderby"><option>Newest</option></select>' ) ), 'sort' ), 'a sort select is detected' );

echo "\n== 10. Unreachable controls are not interactions ==\n";

$c = $rf16_detector->detect(
	rf16_context( $rf16_dom, '<div aria-hidden="true"><button aria-expanded="false" aria-controls="x1">Hidden</button><div id="x1">x</div></div>' )
);
rf16_check( ! rf16_has_type( $c, 'expand_collapse' ), 'a control inside aria-hidden is not reported - a keyboard user cannot reach it' );
$c = $rf16_detector->detect( rf16_context( $rf16_dom, '<button aria-expanded="false" aria-controls="x1">H</button><div id="x1" aria-hidden="true">x</div>' ) );
rf16_check( rf16_has_type( $c, 'expand_collapse' ), 'a control that *controls* a hidden element is still an interaction' );

echo "\n== 11. Every candidate is self-describing ==\n";

$all = $rf16_detector->detect(
	rf16_context(
		$rf16_dom,
		'<nav><ul><li><button aria-haspopup="true" aria-expanded="false" aria-controls="s1">P</button><ul id="s1"><li><a href="/x">X</a></li></ul></li></ul></nav>'
		. '<details><summary>S</summary>B</details><dialog>d</dialog>'
		. '<div class="carousel" data-ride="carousel">c</div>'
	)
);
$rf16_no_evidence = 0;
$rf16_bad_type    = 0;
$rf16_no_element  = 0;
foreach ( $all as $candidate ) {
	if ( array() === (array) $candidate['evidence'] ) { $rf16_no_evidence++; }
	if ( ! Interaction_Limits::is_type( (string) $candidate['type'] ) ) { $rf16_bad_type++; }
	if ( '' === (string) $candidate['source_element'] ) { $rf16_no_element++; }
}
rf16_check( 0 === $rf16_no_evidence, 'every candidate carries evidence', (string) $rf16_no_evidence . ' without' );
rf16_check( 0 === $rf16_bad_type, 'every candidate has a declared type', (string) $rf16_bad_type );
rf16_check( 0 === $rf16_no_element, 'every candidate names an element to bind to', (string) $rf16_no_element );
rf16_check( count( array_unique( array_column( $all, 'type' ) ) ) >= 4, 'a mixed page yields several distinct types', implode( ',', array_unique( array_column( $all, 'type' ) ) ) );

echo "\n== 12. The state machine is the unit ==\n";

$rf16_cycle = State_Machine::make( 'menu', 'mobile_menu', 'disclosure' );
$rf16_cycle = State_Machine::add_cycle( $rf16_cycle, 'click', 'click', 'hidden', 'visible', '#btn' );
rf16_check( 'hidden' === $rf16_cycle['initial_state'], 'a mobile menu starts hidden', $rf16_cycle['initial_state'] );
rf16_check( array( 'hidden', 'visible' ) === $rf16_cycle['states'], 'it has exactly two states', implode( ',', $rf16_cycle['states'] ) );
rf16_check( 2 === count( State_Machine::transitions( $rf16_cycle ) ), 'and two transitions' );
rf16_check( State_Machine::is_cyclic( $rf16_cycle ), 'and it is a cycle, not a one-way reveal' );
rf16_check( 'visible' === State_Machine::transitions( $rf16_cycle )[0]['to'], 'the first transition opens' );
rf16_check( 'hidden' === State_Machine::transitions( $rf16_cycle )[1]['to'], 'the second closes' );
rf16_check( 3 === count( State_Machine::timeline( $rf16_cycle ) ), 'a timeline renders the whole path', (string) count( State_Machine::timeline( $rf16_cycle ) ) );

$rf16_linear = State_Machine::add_transition( State_Machine::make( 't', 'tabs', 'tablist' ), 'click', 'first_selected', 'tab_selected', '#tab1' );
rf16_check( ! State_Machine::is_cyclic( $rf16_linear ), 'a tab set is not a cycle - siblings are not the initial state' );

$rf16_deep = State_Machine::add_transition( $rf16_linear, 'click', 'tab_selected', 'tab_selected_2', '#tab2' );
rf16_check(
	array() === array_diff( $rf16_deep['states'], array( 'first_selected', 'tab_selected', 'tab_selected_2' ) ),
	'every state a transition reaches is registered on the machine',
	implode( ',', $rf16_deep['states'] )
);

// A machine whose initial state is not among the states anything transitions *from* is
// reachable only from its own initial state, which is the honest answer: nothing leads to
// it, so nothing can trigger a change from it.
$rf16_unreachable = State_Machine::add_transition( State_Machine::make( 'u', 'modal' ), 'click', 'closed', 'open', '#x' );
$rf16_reachable    = State_Machine::reachable_states( $rf16_unreachable );
rf16_check( in_array( 'open', $rf16_reachable, true ), 'a reached state is reported reachable', implode( ',', $rf16_reachable ) );
rf16_check( ! in_array( 'never', $rf16_reachable, true ), 'a state nothing reaches is not reported reachable' );
rf16_check( in_array( 'closed', $rf16_reachable, true ), 'and the initial state always is' );

/*
 * A machine whose initial state is a literal the vocabulary does not define. `make()`
 * deliberately keeps it rather than substituting a guess, and the transition then leaves a
 * state the machine *does* have, so the machine is internally consistent and structurally
 * valid. What is not true is that the behaviour is known — so the honest handling is that
 * the model reports the unknown initial state rather than the machine being rejected for
 * a structural fault it does not have.
 */
$rf16_unnamed = State_Machine::add_transition( State_Machine::make( 'z', 'unknown' ), 'click', 'closed', 'open', '#z' );
rf16_check( 'unknown' === $rf16_unnamed['initial_state'], 'an undefined initial state is kept as unknown rather than guessed', $rf16_unnamed['initial_state'] );
rf16_check( true === State_Machine::validate( $rf16_unnamed )['valid'], 'and a machine using it is structurally valid - it has the states its transitions need' );
$rf16_unnamed_model = new Interaction_Model( array( 'page_id' => 'u', 'source_url' => 'https://example.com/' ) );
$rf16_unnamed_model->add_machine( $rf16_unnamed );
rf16_check( ! $rf16_unnamed_model->is_complete(), 'a model containing one is not presented as complete' );

echo "\n== 13. A machine that lies is refused ==\n";

$bad_machine = State_Machine::make( '', 'modal', 'dialog' );
$bad_machine = State_Machine::add_transition( $bad_machine, 'click', 'closed', 'open', '' );
$rf16_verdict = State_Machine::validate( $bad_machine );
rf16_check( false === $rf16_verdict['valid'], 'a machine with no component is invalid' );
rf16_check( (bool) preg_grep( '/component/i', $rf16_verdict['errors'] ), 'and says which part is wrong' );

$no_states = array( 'component_id' => 'x', 'type' => 'modal', 'initial_state' => '', 'states' => array(), 'transitions' => array() );
rf16_check( false === State_Machine::validate( $no_states )['valid'], 'a machine with no states is invalid' );

$undeclared = State_Machine::make( 'x', 'modal' );
$undeclared['type'] = 'teleportation';
rf16_check( false === State_Machine::validate( $undeclared )['valid'], 'an undeclared type is invalid' );

$dangling = State_Machine::make( 'x', 'modal' );
$dangling['transitions'][] = array( 'interaction_id' => 'a', 'trigger' => 'click', 'source_element' => 'b', 'from' => 'closed', 'to' => 'nowhere' );
rf16_check( false === State_Machine::validate( $dangling )['valid'], 'a transition to a state the machine does not have is invalid' );

echo "\n== 14. Executable content is refused at the gate ==\n";

foreach ( array( '<script>alert(1)</script>', 'javascript:alert(1)', 'onerror=alert(1)', 'onclick="x()"', 'onmouseover=y' ) as $rf16_payload ) {
	$hostile = new Interaction_Model( array( 'page_id' => 'p', 'source_url' => 'https://example.com/' ) );
	$hostile->add_machine(
		State_Machine::add_transition( State_Machine::make( 'c', 'modal', 'dialog' ), 'click', 'closed', 'open', $rf16_payload, array( 'confidence' => 0.9, 'evidence' => array( 'x' ) ) )
	);
	$v = $rf16_validator->validate( $hostile );
	rf16_check( false === $v['valid'], 'a model carrying ' . substr( $rf16_payload, 0, 22 ) . ' is rejected' );
}
rf16_check( array() !== Interaction_Model::find_code( array( 'a' => array( 'b' => 'javascript:x' ) ) ), 'the code scan is a deep walk, not a known-key check' );
rf16_check( array() === Interaction_Model::find_code( array( 'a' => 'a normal string', 'b' => array( 'c' => 42 ) ) ), 'and it does not fire on ordinary content' );

echo "\n== 15. Unsafe URLs are refused ==\n";

foreach ( array( 'http://127.0.0.1/admin', 'http://localhost/x', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/', 'javascript:alert(1)' ) as $rf16_url ) {
	rf16_check( array() !== $rf16_validator->find_unsafe_urls( array( 'conditions' => array( 'url' => $rf16_url ) ) ), 'a transition target of ' . $rf16_url . ' is refused' );
}
rf16_check( array() === $rf16_validator->find_unsafe_urls( array( 'source_url' => 'https://example.com/' ) ), "the page's own validated URL is exempt" );
rf16_check( array() === $rf16_validator->find_unsafe_urls( array( 'href' => '#section' ) ), 'an in-page anchor is not a URL' );
rf16_check( array() === $rf16_validator->find_unsafe_urls( array( 'note' => 'not a url at all' ) ), 'ordinary text is not a URL' );

echo "\n== 16. Every check in the gate is real ==\n";

$rf16_checks = $rf16_validator->checks();
foreach ( array( 'no_arbitrary_code', 'no_unsafe_url', 'component_exists', 'transition_valid', 'trigger_declared', 'type_declared', 'viewport_valid', 'duration_plausible', 'capability_exists', 'fallback_stated' ) as $rf16_check_name ) {
	rf16_check( in_array( $rf16_check_name, $rf16_checks, true ), 'the gate performs ' . $rf16_check_name );
}

$rf16_ok = new Interaction_Model( array( 'page_id' => 'ok', 'source_url' => 'https://example.com/' ) );
$rf16_ok->add_machine( State_Machine::add_transition( State_Machine::make( 'c', 'modal', 'dialog' ), 'click', 'closed', 'open', 'a', array( 'confidence' => 0.9, 'evidence' => array( 'declared' ) ) ) );
rf16_check( true === $rf16_validator->validate( $rf16_ok )['valid'], 'a legitimate model passes the gate' );

$rf16_bad_viewport = new Interaction_Model( array( 'page_id' => 'ok', 'source_url' => 'https://example.com/' ) );
$rf16_vm = State_Machine::add_transition( State_Machine::make( 'c', 'modal', 'dialog' ), 'click', 'closed', 'open', 'a', array( 'confidence' => 0.9, 'evidence' => array( 'd' ), 'viewport' => 'watch' ) );
$rf16_bad_viewport->add_machine( $rf16_vm );
rf16_check( false === $rf16_validator->validate( $rf16_bad_viewport )['valid'], 'a viewport that is not one of the three declared is rejected' );

$rf16_bad_duration = new Interaction_Model( array( 'page_id' => 'ok', 'source_url' => 'https://example.com/' ) );
$rf16_dm = State_Machine::add_transition( State_Machine::make( 'c', 'modal', 'dialog' ), 'click', 'closed', 'open', 'a', array( 'confidence' => 0.9, 'evidence' => array( 'd' ) ) );
$rf16_dm['transitions'][0]['duration'] = 99999;
$rf16_bad_duration->add_machine( $rf16_dm );
rf16_check( false === $rf16_validator->validate( $rf16_bad_duration )['valid'], 'an implausible transition duration is rejected rather than clamped' );

echo "\n== 17. The mapper is honest about the destination ==\n";

$rf16_registry = $rf16_mapper->registry();
$rf16_elementor = new Elementor_Compatibility();

rf16_check( count( $rf16_registry['rows'] ) === count( Interaction_Limits::TYPES ), 'the registry has a row for every declared type', (string) count( $rf16_registry['rows'] ) );
$rf16_bad_outcome = 0;
$rf16_no_note = 0;
foreach ( $rf16_registry['rows'] as $rf16_row ) {
	if ( ! Interaction_Limits::is_outcome( (string) $rf16_row['outcome'] ) ) { $rf16_bad_outcome++; }
	if ( in_array( $rf16_row['outcome'], array( 'unsupported', 'approximation', 'requires_review' ), true ) && '' === (string) $rf16_row['note'] ) { $rf16_no_note++; }
}
rf16_check( 0 === $rf16_bad_outcome, 'every outcome is a declared one', (string) $rf16_bad_outcome );
rf16_check( 0 === $rf16_no_note, 'every limited outcome explains itself', (string) $rf16_no_note . ' silent' );
rf16_check( array_sum( $rf16_registry['counts'] ) === count( $rf16_registry['rows'] ), 'the counts add up', wp_json_encode( $rf16_registry['counts'] ) );

// The property that matters: the registry agrees with the *running* install.
$rf16_expected = 0;
foreach ( $rf16_registry['rows'] as $rf16_row ) {
	if ( 'supported' === $rf16_row['outcome'] && '' !== $rf16_row['widget'] && $rf16_elementor->has_widget( (string) $rf16_row['widget'] ) ) {
		$rf16_expected++;
	}
}
rf16_check( (int) $rf16_registry['counts']['supported'] === $rf16_expected, 'every supported row names a widget this install really has', (string) $rf16_registry['counts']['supported'] . ' on Elementor ' . $rf16_elementor->version() );
rf16_check( ( (int) $rf16_registry['counts']['supported'] > 0 ) === $rf16_elementor->is_available(), 'support is claimed only when Elementor is present' );

if ( $rf16_elementor->is_available() ) {
	$rf16_accordion = (string) $rf16_registry['rows']['accordion']['widget'];
	rf16_check(
		in_array( $rf16_accordion, array( 'nested-accordion', 'accordion' ), true ) && $rf16_elementor->has_widget( $rf16_accordion ),
		'an accordion maps to a widget this Elementor version ships',
		$rf16_accordion . ' on ' . $rf16_elementor->version()
	);
}

rf16_check( 'unsupported' === $rf16_registry['rows']['cookie_banner']['outcome'], 'a cookie banner is never reproduced automatically' );
rf16_check( 'static' === $rf16_registry['rows']['cookie_banner']['fallback'], 'it gets a static fallback' );
rf16_check( 'unsupported' === $rf16_registry['rows']['unknown']['outcome'], 'an unknown interaction is not guessed' );
rf16_check( 'manual' === $rf16_registry['rows']['unknown']['fallback'], 'and gets a manual fallback, not a static one - an inert stand-in would look like it works' );
rf16_check( 'unsupported' === $rf16_registry['rows']['load_more']['outcome'], 'load-more needs destination data, so it is not reproduced' );

echo "\n== 18. A mapping that lies is refused ==\n";

rf16_check( false === $rf16_validator->validate_mapping( array( 'type' => 'modal', 'outcome' => 'supported', 'widget' => '' ) )['valid'], 'claiming support with no widget is rejected' );
rf16_check( false === $rf16_validator->validate_mapping( array( 'type' => 'modal', 'outcome' => 'supported', 'widget' => 'not-installed-here' ) )['valid'], 'naming a widget the site lacks is rejected' );
rf16_check( false === $rf16_validator->validate_mapping( array( 'type' => 'modal', 'outcome' => 'unsupported', 'widget' => '', 'fallback' => '' ) )['valid'], 'an unsupported behaviour with no stated fallback is rejected' );
rf16_check( false === $rf16_validator->validate_mapping( array( 'type' => 'not-a-type', 'outcome' => 'supported', 'widget' => 'x' ) )['valid'], 'an undeclared type is rejected' );
rf16_check( false === $rf16_validator->validate_mapping( array( 'type' => 'modal', 'outcome' => 'supported', 'widget' => 'x', 'note' => 'javascript:alert(1)' ) )['valid'], 'a mapping carrying code is rejected' );
rf16_check( true === $rf16_validator->validate_mapping( array( 'type' => 'unknown', 'outcome' => 'unsupported', 'widget' => '', 'fallback' => 'manual', 'note' => 'x' ) )['valid'], 'a well-formed mapping passes' );

echo "\n== 19. Intrusive triggers are held for review ==\n";

foreach ( array( 'timeout', 'load', 'intersection' ) as $rf16_auto ) {
	$m = $rf16_mapper->map( array( 'type' => 'popup', 'trigger' => $rf16_auto, 'component_id' => 'c', 'interaction_id' => 'i' ) );
	rf16_check( 'requires_review' === $m['outcome'], 'a popup on ' . $rf16_auto . ' is marked for review', $m['outcome'] );
	rf16_check( true === $m['requires_review'], 'and flagged as needing review' );
}
$m = $rf16_mapper->map( array( 'type' => 'popup', 'trigger' => 'click', 'component_id' => 'c', 'interaction_id' => 'i' ) );
rf16_check( 'requires_review' !== $m['outcome'], 'the same popup on click is not held', $m['outcome'] );
$m = $rf16_mapper->map( array( 'type' => 'modal', 'trigger' => 'scroll', 'component_id' => 'c', 'interaction_id' => 'i' ) );
rf16_check( 'requires_review' === $m['outcome'], 'a modal on scroll is held - scroll is not in the automatic list but is equally unsolicited' );

echo "\n== 20. Only safe interactions may be observed ==\n";

foreach ( array( 'accordion', 'tabs', 'mobile_menu', 'dropdown', 'carousel', 'modal' ) as $rf16_safe ) {
	rf16_check( Interaction_Limits::is_observable( $rf16_safe ), $rf16_safe . ' may be observed' );
}
foreach ( array( 'contact_form', 'newsletter_form', 'form_validation', 'login', 'unknown' ) as $rf16_never ) {
	rf16_check( ! Interaction_Limits::is_observable( $rf16_never ), $rf16_never . ' is never on the observation list' );
}
foreach ( array( 'submit', 'change', 'input' ) as $rf16_side ) {
	rf16_check( Interaction_Limits::is_side_effect_trigger( $rf16_side ), $rf16_side . ' is treated as a side effect' );
}
rf16_check( count( array_intersect( Interaction_Limits::OBSERVABLE_TYPES, array( 'contact_form', 'newsletter_form', 'form_validation' ) ) ) === 0, 'no form type appears in the observation allowlist at all' );

echo "\n== 21. Budgets are clamped, never raised ==\n";

$rf16_budget = $rf16_service->budget( array( 'max_interactions' => 999999, 'max_states' => 0, 'max_pages' => 3 ) );
rf16_check( (int) $rf16_budget['max_interactions'] === (int) Interaction_Limits::BUDGETS['max_interactions'], 'an absurd request is clamped to the ceiling', (string) $rf16_budget['max_interactions'] );
rf16_check( (int) $rf16_budget['max_states'] === (int) Interaction_Limits::BUDGETS['max_states'], 'a zero request gets the default, not zero - "stop immediately" is never what a caller means' );
rf16_check( 3 === (int) $rf16_budget['max_pages'], 'a smaller request is honoured' );
rf16_check( 0 === Interaction_Limits::budget( 'not_a_real_budget', 5 ), 'an unknown budget name is refused' );
foreach ( array( 'max_interactions', 'max_states', 'max_screenshots', 'max_browser_ms', 'max_network', 'max_memory_bytes', 'max_pages' ) as $rf16_name ) {
	rf16_check( isset( $rf16_budget[ $rf16_name ] ) && $rf16_budget[ $rf16_name ] > 0, 'the ' . $rf16_name . ' budget exists and is positive' );
}

echo "\n== 22. The full pipeline on a realistic page ==\n";

$rf16_html = <<<'HTML'
<header style="position:sticky;top:0">
  <nav aria-label="Main"><ul>
    <li><button aria-haspopup="true" aria-expanded="false" aria-controls="sub1">Products</button>
      <ul id="sub1"><li><a href="/a">A</a></li></ul></li>
    <li><a href="/about">About</a></li>
  </ul></nav>
  <button class="hamburger nav-toggle" aria-expanded="false" aria-controls="mnav">Menu</button>
  <nav id="mnav" class="mobile-nav drawer" hidden>Mobile links</nav>
  <form role="search" action="/s"><input type="search" name="q" placeholder="Search"></form>
</header>
<main>
  <div class="carousel slide" data-ride="carousel"><div class="carousel-item">One</div></div>
  <details><summary>Shipping</summary><p>Body</p></details>
  <div class="variations"><button class="variation">Red</button><button class="variation">Blue</button></div>
  <a href="#reviews">Skip to reviews</a>
  <dialog id="terms">Terms</dialog>
  <form id="news" class="newsletter-form" action="/subscribe" method="post">
    <input type="email" name="email" required placeholder="Email"><button type="submit">Sign up</button>
  </form>
  <form id="login" action="/login" method="post"><input type="email" name="log"><input type="password" name="pwd"></form>
  <div class="cookie-banner">We use cookies</div>
</main>
HTML;

$rf16_report = $rf16_service->analyze( 'page_1', 'https://example.com/', rf16_context( $rf16_dom, $rf16_html ) );

rf16_check( count( $rf16_report['interactions'] ) > 5, 'the pipeline produces interactions', (string) count( $rf16_report['interactions'] ) );
rf16_check( '16.0' === (string) $rf16_report['schema_version'], 'at schema 16.0' );
rf16_check( 'BROWSER_UNAVAILABLE' === (string) $rf16_report['status'], 'with no driver, the status says so', (string) $rf16_report['status'] );
rf16_check( false === (bool) $rf16_report['complete'], 'and it is not claimed to be complete' );
rf16_check( ! empty( $rf16_report['limitations'] ), 'with a reason recorded, because a status without a reason is half a report' );

foreach ( array( 'navigation', 'dropdown', 'mobile_menu', 'carousel', 'accordion', 'product_variation', 'sticky_header', 'search_overlay', 'anchor_scroll', 'modal' ) as $rf16_expected_type ) {
	rf16_check( rf16_has_type( $rf16_report['interactions'], $rf16_expected_type ), $rf16_expected_type . ' is detected end to end' );
}

echo "\n== 23. Forms are analysed and never submitted ==\n";

$rf16_forms = $rf16_report['forms'];
$rf16_by_type = array();
foreach ( $rf16_forms as $rf16_form ) { $rf16_by_type[ (string) $rf16_form['type'] ] = $rf16_form; }

rf16_check( count( $rf16_forms ) >= 3, 'forms are found', (string) count( $rf16_forms ) );
rf16_check( isset( $rf16_by_type['newsletter'] ), 'the newsletter form is classified', implode( ',', array_keys( $rf16_by_type ) ) );
rf16_check( isset( $rf16_by_type['login'] ), 'the login form is classified' );
rf16_check( isset( $rf16_by_type['login'] ) && false === $rf16_by_type['login']['reproducible'], 'a login form is not marked reproducible' );
rf16_check( isset( $rf16_by_type['newsletter'] ) && true === $rf16_by_type['newsletter']['reproducible'], 'a newsletter form is' );

$rf16_all_unsubmitted = true;
$rf16_no_values      = true;
$rf16_actions_safe   = true;
foreach ( $rf16_forms as $rf16_form ) {
	if ( false !== $rf16_form['submitted'] ) { $rf16_all_unsubmitted = false; }
	foreach ( (array) $rf16_form['fields'] as $rf16_field ) {
		if ( array_key_exists( 'value', $rf16_field ) ) { $rf16_no_values = false; }
	}
	$rf16_action = (string) $rf16_form['action'];
	if ( 0 === stripos( $rf16_action, 'javascript:' ) || 0 === stripos( $rf16_action, 'mailto:' ) || 0 === stripos( $rf16_action, 'data:' ) ) {
		$rf16_actions_safe = false;
	}
}
rf16_check( $rf16_all_unsubmitted, 'every form records that it was not submitted' );
rf16_check( $rf16_no_values, 'no field value is ever stored' );
rf16_check( $rf16_actions_safe, 'a javascript:/mailto:/data: action is never carried into the report' );
rf16_check( isset( $rf16_by_type['newsletter'] ) && 1 === (int) $rf16_by_type['newsletter']['required'], 'required fields are counted' );
rf16_check( isset( $rf16_by_type['search'] ), 'the search form is classified as a search, not a newsletter', implode( ',', array_keys( $rf16_by_type ) ) );

$rf16_hostile_form = $rf16_service->analyze_forms( rf16_context( $rf16_dom, '<form action="javascript:steal()"><input name="card"></form>' ) );
rf16_check( '' === (string) ( $rf16_hostile_form[0]['action'] ?? 'x' ), 'a javascript: form action is dropped, not stored' );

echo "\n== 24. Observation data cannot carry a secret ==\n";

foreach ( array( 'cookie', 'cookies', 'password', 'authorization', 'auth_header', 'session_token', 'local_storage', 'input_value', 'card', 'cvv', 'user_password' ) as $rf16_field ) {
	rf16_check( ! Interaction_Limits::is_recordable_field( $rf16_field ), $rf16_field . ' can never be persisted' );
}
foreach ( array( 'kind', 'trigger', 'element_id', 'dom_changed', 'scroll_y', 'at_ms', 'viewport', 'class_added' ) as $rf16_field ) {
	rf16_check( Interaction_Limits::is_recordable_field( $rf16_field ), $rf16_field . ' can be persisted' );
}
rf16_check( ! Interaction_Limits::is_recordable_field( '' ), 'an empty field name is refused' );

echo "\n== 25. No browser means no invented browser ==\n";

$rf16_caps = $rf16_service->capabilities();
rf16_check( false === $rf16_caps['browser']['available'], 'no driver means no browser' );
rf16_check( false !== strpos( (string) $rf16_caps['browser']['reason'], 'Static detection still runs' ), 'and the reason names what still works' );
rf16_check( true === $rf16_caps['static_detection'], 'static detection is unaffected' );
rf16_check( count( $rf16_caps['observable_types'] ) === count( Interaction_Limits::OBSERVABLE_TYPES ), 'the allowlist is reported, not hidden' );
rf16_check( count( $rf16_caps['statuses'] ) === count( Interaction_Limits::STATUSES ), 'every status is reported' );
rf16_check( false !== strpos( $rf16_service->observation_cache_key( 'p', 'https://example.com/' ), 'rfi_' ), 'the observation cache key is namespaced' );
rf16_check( $rf16_service->observation_cache_key( 'p', 'https://example.com/' ) !== $rf16_service->observation_cache_key( 'q', 'https://example.com/' ), 'and two projects do not share it' );

$rf16_obs = $rf16_service->observe( 'https://example.com/', array(), $rf16_budget );
rf16_check( 'BROWSER_UNAVAILABLE' === (string) $rf16_obs['status'], 'observing without a driver reports BROWSER_UNAVAILABLE' );
rf16_check( '' !== (string) $rf16_obs['reason'], 'with a reason' );
rf16_check( array() === $rf16_obs['observations'], 'and invents no observations' );

$rf16_blocked = $rf16_service->observe( 'http://169.254.169.254/latest/meta-data/', array(), $rf16_budget );
rf16_check( 'SOURCE_BLOCKED' === (string) $rf16_blocked['status'], 'a cloud metadata address is refused before any request', (string) $rf16_blocked['status'] );
$rf16_private = $rf16_service->observe( 'https://example.com/wp-admin/', array(), $rf16_budget );
rf16_check( in_array( (string) $rf16_private['status'], array( 'PRIVATE_PAGE', 'SOURCE_BLOCKED' ), true ), 'a private path is refused', (string) $rf16_private['status'] );

echo "\n== 26. A limit reached is reported, not hidden ==\n";

$rf16_group  = '<div class="variations"><button class="variation">Red</button><button class="variation">Blue</button></div>';
$rf16_many   = str_repeat( $rf16_group, 200 );
$rf16_limited = $rf16_service->analyze( 'page_2', 'https://example.com/', rf16_context( $rf16_dom, $rf16_many ), array( 'budget' => array( 'max_interactions' => 2 ), 'observe' => false ) );
rf16_check( count( $rf16_limited['interactions'] ) === 2, 'the run stops at the ceiling', (string) count( $rf16_limited['interactions'] ) );
rf16_check( 'INTERACTION_LIMIT_REACHED' === (string) $rf16_limited['status'], 'and says INTERACTION_LIMIT_REACHED', (string) $rf16_limited['status'] );
rf16_check( (bool) preg_grep( '/ceiling/', (array) $rf16_limited['limitations'] ), 'with a limitation naming the ceiling' );
rf16_check( false === (bool) $rf16_limited['complete'], 'and is not complete' );

$rf16_default = $rf16_service->analyze( 'page_3', 'https://example.com/', rf16_context( $rf16_dom, $rf16_many ), array( 'observe' => false ) );
rf16_check( count( $rf16_default['interactions'] ) <= (int) Interaction_Limits::BUDGETS['max_interactions'], 'an unrequested budget still gets the ceiling', (string) count( $rf16_default['interactions'] ) );

echo "\n== 27. REST routes are gated ==\n";

$rf16_api    = new \ReplicaForge\Interaction_Api();
$rf16_api->register_routes();

global $wp_rest_server;
$wp_rest_server = new WP_REST_Server();
do_action( 'rest_api_init', $wp_rest_server );

$rf16_routes = array();
foreach ( array_keys( $wp_rest_server->get_routes() ) as $rf16_route ) {
	if ( false !== strpos( $rf16_route, '/interactions' ) ) {
		$rf16_routes[ $rf16_route ] = (array) $wp_rest_server->get_routes()[ $rf16_route ];
	}
}
rf16_check( count( $rf16_routes ) >= 8, 'the interaction routes register', (string) count( $rf16_routes ) );

/*
 * `is_callable()`, not `isset()`. A private gate is *set* and does nothing, because
 * WP_REST_Server calls it from outside the class scope - which is exactly how the first
 * version of this class shipped every endpoint unauthenticated. Presence is not the
 * property that matters; reachability is.
 */
$rf16_ungated   = array();
$rf16_admitted  = array();
$rf16_endpoints = 0;
foreach ( $rf16_routes as $rf16_route => $rf16_handlers ) {
	foreach ( $rf16_handlers as $rf16_handler ) {
		if ( ! is_array( $rf16_handler ) || ! isset( $rf16_handler['callback'] ) || ! is_callable( $rf16_handler['callback'] ) ) {
			continue;
		}
		$rf16_endpoints++;
		if ( ! isset( $rf16_handler['permission_callback'] ) || ! is_callable( $rf16_handler['permission_callback'] ) ) {
			$rf16_ungated[] = $rf16_route;
			continue;
		}
		wp_set_current_user( 0 );
		$rf16_request = new WP_REST_Request( 'GET', $rf16_route );
		if ( true === call_user_func( $rf16_handler['permission_callback'], $rf16_request ) ) {
			$rf16_admitted[] = $rf16_route;
		}
	}
}
rf16_check( array() === $rf16_ungated, 'every endpoint has a *callable* gate', count( $rf16_ungated ) . ' ungated: ' . implode( ', ', $rf16_ungated ) );
rf16_check( array() === $rf16_admitted, 'no endpoint admits an anonymous caller', count( $rf16_admitted ) . ' admitted' );
rf16_check( $rf16_endpoints >= 8, 'the endpoint count is what we expect', (string) $rf16_endpoints );

echo "\n== 28. Every earlier phase still registers ==\n";

$rf16_phases = array(
	'/replicaforge/v1/jobs'          => 'Phase 5',
	'/replicaforge/v1/plans'         => 'Phase 10',
	'/replicaforge/v1/websites'      => 'Phase 12',
	'/replicaforge/v1/visual/'       => 'Phase 13',
	'/replicaforge/v1/content/'      => 'Phase 14',
	'/replicaforge/v1/workspaces'    => 'Phase 15',
	'/replicaforge/v1/interactions'  => 'Phase 16',
);
foreach ( $rf16_phases as $rf16_prefix => $rf16_label ) {
	$rf16_found = 0;
	foreach ( array_keys( $wp_rest_server->get_routes() ) as $rf16_route ) {
		if ( 0 === strpos( $rf16_route, $rf16_prefix ) && $rf16_route !== $rf16_prefix && substr_count( $rf16_route, '/' ) > substr_count( $rf16_prefix, '/' ) ) {
			$rf16_found++;
		}
	}
	rf16_check( $rf16_found > 0, $rf16_label . ' still registers from the plugin boot', $rf16_found . ' route(s)' );
}

echo "\n== 29. The plugin facade exposes the layer ==\n";

$rf16_plugin = Plugin::instance();
rf16_check( $rf16_plugin instanceof Plugin, 'the plugin instance is reachable' );
rf16_check( $rf16_plugin->interactions() instanceof Interaction_Service, 'the interaction service is exposed' );
rf16_check( $rf16_plugin->interaction_api() instanceof \ReplicaForge\Interaction_Api, 'the REST layer is exposed' );
rf16_check( $rf16_plugin->workspace_api() instanceof \ReplicaForge\Workspace_Api, 'and the Phase 15 layer still is' );
rf16_check( $rf16_plugin->visual_api() instanceof \ReplicaForge\Visual_Api, 'and the Phase 13 one' );

echo "\n== 30. Phase 2 analysis is unchanged apart from added attributes ==\n";

/*
 * The fixture has to actually contain the attributes the assertions name.
 *
 * This block used to read
 *
 *     rf16_check( in_array( $rf16_pre, $rf16_found_attrs, true ) || true, ... );
 *
 * which is unconditionally true, so it asserted nothing - and it went on asserting six
 * attributes that the markup beside it did not contain. `build()` was returning
 * `aria-selected`, `aria-roledescription`, `data-ride` and `tabindex` for this element, and
 * the block checked for `aria-expanded`, `aria-controls`, `aria-haspopup`, `role`,
 * `data-toggle` and `required`. Six assertions, all guaranteed to pass, none of which could
 * ever have failed.
 *
 * The markup is now a disclosure-style control that genuinely carries all of them, which is
 * what the section title means by "Phase 2 analysis is unchanged apart from added
 * attributes": the attributes Phase 16 relies on are all still collected, and this is the
 * guard that a Phase 2 change cannot quietly narrow them.
 */
$rf16_ctx = rf16_context( $rf16_dom, '<button aria-expanded="false" aria-controls="rf16-panel" aria-haspopup="true" role="button" data-toggle="collapse" required type="button" aria-selected="true" aria-roledescription="carousel" data-ride="carousel" tabindex="0">x</button>' );
$rf16_found_attrs = array();
foreach ( $rf16_ctx['nodes'] as $rf16_node ) {
	$rf16_found_attrs = array_merge( $rf16_found_attrs, array_keys( (array) $rf16_node['attributes'] ) );
}
foreach ( array( 'aria-expanded', 'aria-controls', 'aria-haspopup', 'role', 'data-toggle', 'required' ) as $rf16_pre ) {
	rf16_check( in_array( $rf16_pre, $rf16_found_attrs, true ), 'the pre-existing attribute ' . $rf16_pre . ' is still harvested' );
}
$rf16_ride = null;
foreach ( $rf16_ctx['nodes'] as $rf16_node ) {
	if ( isset( $rf16_node['attributes']['data-ride'] ) ) { $rf16_ride = $rf16_node['attributes']['data-ride']; }
}
rf16_check( 'carousel' === $rf16_ride, 'and data-ride is now reachable, which is what made carousel detection possible', (string) $rf16_ride );
rf16_check( Analysis_Limits::MAX_DOM_NODES > 0, 'the phase 2 node ceiling is still declared', (string) Analysis_Limits::MAX_DOM_NODES );

echo "\n== 31. The driver contract has no escape hatch ==\n";

$rf16_methods = get_class_methods( 'ReplicaForge\Browser_Driver_Contract' );
rf16_check( array( 'id', 'version', 'capabilities', 'is_available', 'unavailable_reason', 'observe' ) === $rf16_methods, 'the driver contract exposes exactly six operations', implode( ',', $rf16_methods ) );
foreach ( array( 'evaluate', 'execute', 'run_javascript', 'exec', 'shell', 'command', 'download', 'cookie' ) as $rf16_forbidden ) {
	rf16_check( ! in_array( $rf16_forbidden, $rf16_methods, true ), 'the driver contract has no ' . $rf16_forbidden . '() method' );
}

echo "\n-- Done --\n";
printf( "assertions: %d\n", $rf16_assertions );
printf( "failures  : %d\n", $rf16_failures );
printf( "RESULT: %s\n", ( 0 === $rf16_failures && array() === $rf16_php_errors ) ? 'PASS' : 'FAIL' );

if ( $rf16_failures > 0 || array() !== $rf16_php_errors ) {
	exit( 1 );
}
exit( 0 );
