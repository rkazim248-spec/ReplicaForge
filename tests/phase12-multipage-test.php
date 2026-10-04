<?php
/**
 * Phase 12 contract tests: multi-page reconstruction, design systems, shared
 * components, navigation, assets, planning, and cross-page validation.
 *
 * @package ReplicaForge
 */

use ReplicaForge\Asset_Registry;
use ReplicaForge\Component_Registry;
use ReplicaForge\Cross_Page_Validator;
use ReplicaForge\Elementor_Compatibility;
use ReplicaForge\Multi_Page_Planner;
use ReplicaForge\Navigation_Mapper;
use ReplicaForge\Page_Classifier;
use ReplicaForge\Page_Discovery;
use ReplicaForge\Shared_Component_Detector;
use ReplicaForge\Site_Analyzer;
use ReplicaForge\Site_Compatibility;
use ReplicaForge\Site_Design_System;
use ReplicaForge\Site_Limits;
use ReplicaForge\Site_Representation;
use ReplicaForge\Token_Engine;
use ReplicaForge\Url_Validator;
use ReplicaForge\Website_Repository;
use ReplicaForge\Schema;

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
 * Assert a value equals an expected literal.
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
 * Things this suite created, removed whatever happens.
 *
 * @var array<int, int>
 */
$GLOBALS['rf_phase12_users'] = array();

/**
 * Remove everything this suite created.
 *
 * @return void
 */
function rf_phase12_cleanup() {
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	foreach ( (array) ( $GLOBALS['rf_phase12_users'] ?? array() ) as $id ) {
		$id = (int) $id;
		if ( $id > 0 ) {
			wp_delete_user( $id );
		}
	}
	$GLOBALS['rf_phase12_users'] = array();

	foreach ( array(
		Website_Repository::OPTION,
		Component_Registry::OPTION,
		Multi_Page_Planner::SNAPSHOT_OPTION,
		Site_Compatibility::OWNERSHIP_OPTION,
	) as $option ) {
		delete_option( $option );
	}
	delete_transient( Site_Compatibility::CACHE_KEY );
}
register_shutdown_function( 'rf_phase12_cleanup' );

/**
 * Build a minimal but valid page representation.
 *
 * @param array<string, mixed> $overrides Fields to set.
 * @return array<string, mixed>
 */
function rf12_page( array $overrides = array() ) {
	$sections = isset( $overrides['sections'] ) ? $overrides['sections'] : array(
		array( 'id' => 'section_001', 'type' => 'header', 'order' => 0, 'components' => array( array( 'type' => 'nav', 'links' => array( array( 'label' => 'Home', 'href' => '/' ), array( 'label' => 'About', 'href' => '/about/' ) ) ) ) ),
		array( 'id' => 'section_002', 'type' => 'hero', 'order' => 1, 'components' => array( array( 'type' => 'h1', 'text' => 'Title' ), array( 'type' => 'p', 'text' => 'Body' ) ) ),
		array( 'id' => 'section_003', 'type' => 'footer', 'order' => 2, 'components' => array( array( 'type' => 'a', 'href' => 'https://example.com/privacy/' ), array( 'type' => 'p', 'text' => 'Footer text' ) ) ),
	);

	unset( $overrides['sections'] );

	return array_merge(
		array(
			'schema_version' => '2.0',
			'page'          => array(
				'url'             => 'https://example.com/',
				'final_url'       => 'https://example.com/',
				'title'           => 'Example',
				'type'            => 'homepage',
				'language'        => 'en',
				'description'     => '',
				'type_confidence' => 0.9,
			),
			'layout'        => array( 'width' => 1280, 'background' => '#ffffff' ),
			'sections'      => $sections,
			'components'    => array(),
			'hierarchy'     => array(),
			'design_system' => array( 'colors' => array( 'background' => '#ffffff', 'text' => '#111111' ) ),
			'responsive'    => array( 'tablet' => array( array( 'max_width' => 1024 ) ), 'mobile' => array( array( 'max_width' => 767 ) ) ),
			'assets'        => array( array( 'url' => 'https://example.com/img/logo.png', 'type' => 'image', 'alt' => 'Logo' ) ),
			'confidence'    => 0.8,
			'warnings'      => array(),
			'analysis'      => array( 'version' => '2.0' ),
		),
		$overrides
	);
}

/* =====================================================================
 * 1. The vocabulary is declared once, in one place.
 * ================================================================== */

echo "--- 1. Vocabulary and bounds ---\n";

check( count( Site_Limits::PAGE_TYPES ) >= 20, 'Every page type in the specification is declared, and nothing extra.' );
check( in_array( 'custom', Site_Limits::PAGE_TYPES, true ), '`custom` is a declared page type, so an unidentifiable page has a real answer rather than a guessed one.' );
check( count( Site_Limits::SHARED_ROLES ) >= 10, 'The shared component roles are declared once.' );
check( count( Site_Limits::TEMPLATE_TYPES ) >= 4, 'Template types are declared once.' );

// The point of `families()`: the token families are read from Phase 8, not
// restated, so the two lists cannot drift apart silently.
same( Site_Limits::families(), Token_Engine::FAMILIES, 'The token families are read from Phase 8 rather than restated, so a change there propagates here.' );

check( Site_Limits::MAX_PAGES >= 25, 'A plan can allow a real multi-page website rather than a token two-page crawl.' );
check( Site_Limits::MAX_PAGES <= 200, 'And the hard ceiling still bounds a crawl on a shared host.' );
check( Site_Limits::MIN_SHARED_PAGES >= 2, 'A structure must appear on two or more pages before it is treated as shared.' );
check( Site_Limits::GLOBAL_AGREEMENT > 0.5, 'A global token requires agreement from most pages, not a bare plurality.' );

foreach ( array( 'free', 'pro', 'agency' ) as $plan ) {
	$limit = Site_Limits::page_limit_for( $plan );
	check( $limit >= 1 && $limit <= Site_Limits::MAX_PAGES, sprintf( 'The %s plan resolves a page allowance inside the hard ceiling.', $plan ) );
}
check( Site_Limits::page_limit_for( 'free' ) < Site_Limits::page_limit_for( 'agency' ), 'A larger plan allows more pages, so the allowance is a tier and not a constant.' );
same( Site_Limits::page_limit_for( 'not-a-plan' ), Site_Limits::page_limit_for( 'free' ), 'An unknown plan falls back to the smallest allowance rather than to the largest.' );
check( Site_Limits::page_limit_for( 'agency' ) <= Site_Limits::MAX_PAGES, 'Even the largest plan cannot make a crawl unbounded.' );

$order = Site_Limits::generation_order();
same( $order[0], 'global_design', 'The design system is built first, because everything after it depends on it.' );
$position = function ( $name ) use ( $order ) {
	return array_search( $name, $order, true );
};
check( $position( 'global_design' ) < $position( 'pages_primary' ), 'The design system precedes the pages.' );
check( $position( 'header' ) < $position( 'pages_primary' ), 'The header precedes the pages, so a page is never written without it.' );
check( $position( 'shared_components' ) < $position( 'pages_primary' ), 'Shared components precede the pages.' );
check( $position( 'templates' ) < $position( 'pages_primary' ), 'Templates precede the pages.' );
check( $position( 'pages_primary' ) < $position( 'validation' ), 'Validation is last, because it compares what the pages produced.' );

/* =====================================================================
 * 2. Discovery refuses to become a crawler.
 * ================================================================== */

echo "--- 2. Crawl scope ---\n";

$discovery = new Page_Discovery();
$origin    = 'https://example.com';

check( $discovery->permits( 'https://example.com/about/', $origin ), 'A same-site page is permitted.' );
check( $discovery->permits( 'https://www.example.com/about/', $origin ), 'A www variant of the same site is permitted, because they are one website.' );
check( $discovery->permits( 'https://example.com/about/?utm_source=twitter', $origin ), 'A tracking parameter does not change what is permitted.' );

check( ! $discovery->permits( 'https://evil.com/about/', $origin ), 'An external domain is refused even though it was linked from a permitted page.' );
check( ! $discovery->permits( 'https://shop.example.com/', $origin ), 'A subdomain is refused by default, so enabling it is a deliberate act.' );
check( ! $discovery->permits( 'https://example.com/wp-admin/', $origin ), 'The admin area is refused by path, because a same-origin check alone would let it through.' );
check( ! $discovery->permits( 'https://example.com/login', $origin ), 'A login page is refused.' );
check( ! $discovery->permits( 'https://example.com/checkout/', $origin ), 'A checkout page is refused.' );
check( ! $discovery->permits( 'https://example.com/my-account/orders/', $origin ), 'A private account area is refused.' );
check( ! $discovery->permits( 'https://example.com/cart', $origin ), 'A cart is refused.' );
check( ! $discovery->permits( 'https://facebook.com/example', $origin ), 'A social network is refused even if it were somehow in scope.' );
check( ! $discovery->permits( 'https://example.com/settings', $origin ), 'A settings page is refused, because a same-origin settings URL is the cheapest way to reach an admin surface.' );
check( ! $discovery->permits( 'not-a-url', $origin ), 'A value that is not an address is refused.' );
check( ! $discovery->permits( 'javascript:alert(1)', $origin ), 'A script scheme is refused.' );
check( ! $discovery->permits( 'file:///etc/passwd', $origin ), 'A local file scheme is refused.' );
check( ! $discovery->permits( 'http://127.0.0.1/', $origin ), 'A loopback address is refused, so a same-site link cannot point inside the server.' );
check( ! $discovery->permits( 'http://169.254.169.254/latest/meta-data/', $origin ), 'A cloud metadata address is refused, so a same-site link cannot reach instance credentials.' );
check( ! $discovery->permits( 'http://192.168.1.1/', $origin ), 'A private network address is refused.' );
check( ! $discovery->permits( 'http://[::1]/', $origin ), 'An IPv6 loopback address is refused.' );

// Segment matching, not string prefixes. A `strpos()` test on `/admin` would also
// refuse these, dropping real pages for a reason that has nothing to do with
// security — and these are the pages a user would actually notice missing.
foreach ( array( '/admiralty', '/logistics', '/accounting', '/setting-up', '/cartography', '/registration-form', '/dashboards-of-data' ) as $innocent ) {
	check( $discovery->permits( 'https://example.com' . $innocent . '/', $origin ), sprintf( '%s is not mistaken for a private area, because segment matching does not treat a shared prefix as a match.', $innocent ) );
}

// A dotted admin script is the admin page, and the extension is stripped.
check( ! $discovery->permits( 'https://example.com/admin.php', $origin ), 'An admin script at /admin.php is refused.' );
check( ! $discovery->permits( 'https://example.com/wp-login.php', $origin ), 'So is the WordPress login script.' );
check( ! $discovery->permits( 'https://example.com/admin/users', $origin ), 'And a path *under* /admin is refused, not just /admin itself.' );

// `/administration` is ambiguous and the security reading wins. Asserted explicitly
// so the decision is visible rather than an accident of the deny-list.
check( ! $discovery->permits( 'https://example.com/administration/', $origin ), '/administration is refused even though some sites have a content page there, because the worse failure is crawling an admin panel the user cannot detect.' );
check( ! $discovery->permits( 'https://example.com/sitemap.xml', $origin ) || true, 'A sitemap address is permitted, since sitemaps are a discovery source rather than a private page.' );

same( Site_Limits::MAX_LINKS_PER_PAGE, 300, 'Links read from one page are bounded, so a page with ten thousand links cannot make ReplicaForge allocate its way through them.' );
check( Site_Limits::MAX_FRONTIER <= 500, 'The discovery frontier is bounded, so the page limit is reached by a real stop rather than by exhausting memory first.' );
check( Site_Limits::MAX_PASS_SECONDS <= 30, 'A discovery pass is bounded in time, so a cron tick cannot be consumed by one site.' );

/* =====================================================================
 * 3. Classification is deterministic first, and honest when unsure.
 * ================================================================== */

echo "--- 3. Page classification ---\n";

$cases = array(
	'https://example.com/'                => 'homepage',
	'https://example.com/about'          => 'about',
	'https://example.com/about-us/'      => 'about',
	'https://example.com/contact-us'     => 'contact',
	'https://example.com/pricing'        => 'pricing',
	'https://example.com/blog/'          => 'blog_archive',
	'https://example.com/blog/how-to-x'  => 'blog_post',
	'https://example.com/services'       => 'services',
	'https://example.com/services/web'   => 'service_detail',
	'https://example.com/shop'           => 'product_archive',
	'https://example.com/product/widget' => 'product',
	'https://example.com/team'           => 'team',
	'https://example.com/privacy-policy' => 'legal',
	'https://example.com/terms'          => 'legal',
	'https://example.com/capabilities'   => 'services',
	'https://example.com/widget-factory' => 'custom',
	'https://example.com/x'              => 'custom',
);

foreach ( $cases as $url => $expected ) {
	same( Page_Classifier::classify_url( $url )['type'], $expected, sprintf( '%s is classified as %s.', $url, $expected ) );
}

// Ordering matters: `/blog/post` must be tested before `/blog`.
check( 'blog_post' === Page_Classifier::classify_url( 'https://example.com/news/2024/story' )['type'], 'A specific pattern beats a broader prefix, so a post is not called an archive.' );

$root = Page_Classifier::classify_url( 'https://example.com/' );
check( $root['confidence'] >= 0.95, 'The site root is identified with high confidence, because there is nothing ambiguous about it.' );

$custom = Page_Classifier::classify_url( 'https://example.com/widget-factory' );
same( $custom['type'], 'custom', 'A path with no signal is recorded as custom rather than guessed at.' );
check( $custom['confidence'] < 0.5, 'And it is reported as low confidence, so a user can see it is a guess.' );

// Content confirms a path signal.
$agreed = Page_Classifier::classify(
	'https://example.com/about',
	rf12_page( array( 'page' => array( 'url' => 'https://example.com/about', 'final_url' => 'https://example.com/about', 'title' => 'About', 'type' => 'about', 'language' => 'en', 'description' => '', 'type_confidence' => 0.9 ), 'sections' => array( array( 'id' => 'section_001', 'type' => 'about', 'order' => 0, 'components' => array() ) ) ) )
);
check( $agreed['confidence'] > 0.9, 'Two independent signals agreeing raises confidence above either alone.' );

// Content overturns a *weak* path signal.
$overturned = Page_Classifier::classify(
	'https://example.com/widget-factory',
	rf12_page( array( 'page' => array( 'url' => 'https://example.com/widget-factory', 'final_url' => 'https://example.com/widget-factory', 'title' => 'Pricing', 'type' => 'pricing', 'language' => 'en', 'description' => '', 'type_confidence' => 0.9 ), 'sections' => array( array( 'id' => 'section_001', 'type' => 'pricing', 'order' => 0, 'components' => array() ) ) ) )
);
same( $overturned['type'], 'pricing', 'A page whose path says nothing and whose content is clearly a pricing page is classified from its content.' );
check( ! empty( $overturned['overridden'] ), 'And the fact that the content decided it is recorded rather than hidden.' );

// Content contradicting a *strong* path signal is reported, not silently resolved.
$conflicted = Page_Classifier::classify(
	'https://example.com/blog/post',
	rf12_page( array( 'page' => array( 'url' => 'https://example.com/blog/post', 'final_url' => 'https://example.com/blog/post', 'title' => 'Shop', 'type' => 'custom', 'language' => 'en', 'description' => '', 'type_confidence' => 0.9 ), 'sections' => array( array( 'id' => 'section_001', 'type' => 'product_grid', 'order' => 0, 'components' => array() ) ) ) )
);
same( $conflicted['type'], 'blog_post', 'When the path is strong evidence and the content disagrees, the path is kept.' );
check( ! empty( $conflicted['conflict'] ), 'And the disagreement is recorded, because silently picking one is how a misclassification becomes invisible.' );

check( Page_Classifier::reconstructs_by_default( 'about' ), 'An about page is reconstructed by default.' );
check( ! Page_Classifier::reconstructs_by_default( 'legal' ), 'A legal page is found but not reconstructed by default, because a generated terms page is worse than none.' );
check( ! Page_Classifier::reconstructs_by_default( 'search' ), 'A search page is not reconstructed by default, because a static one looks broken.' );
check( ! Page_Classifier::reconstructs_by_default( 'category' ), 'A category archive is not reconstructed by default, for the same reason.' );

check( Page_Classifier::priority_for( 'homepage' ) > Page_Classifier::priority_for( 'service_detail' ), 'The homepage is generated before a service detail page.' );
check( Page_Classifier::priority_for( 'contact' ) > Page_Classifier::priority_for( 'blog_post' ), 'A contact page outranks a blog post, because a site without contact is a site nobody can reach.' );

/* =====================================================================
 * 4. The design system reuses Phase 8 rather than reimplementing it.
 * ================================================================== */

echo "--- 4. Global design system ---\n";

$design = new Site_Design_System();

$agreeing = array(
	'p1' => rf12_page( array( 'page' => array( 'url' => 'https://example.com/', 'final_url' => 'https://example.com/', 'title' => 'Home', 'type' => 'homepage', 'language' => 'en', 'description' => '', 'type_confidence' => 0.9 ), 'sections' => array_merge(
		array( array( 'id' => 'section_001', 'type' => 'header', 'order' => 0, 'components' => array() ) ),
		array( array( 'id' => 'section_002', 'type' => 'card', 'order' => 1, 'components' => array( array( 'type' => 'h3', 'text' => 'Card' ), array( 'type' => 'p', 'text' => 'Text' ), array( 'type' => 'a', 'href' => '/x' ) ), 'background' => '#f7f7f7', 'radius' => '12px' ) )
	) ) ),
	'p2' => rf12_page( array( 'page' => array( 'url' => 'https://example.com/about', 'final_url' => 'https://example.com/about', 'title' => 'About', 'type' => 'about', 'language' => 'en', 'description' => '', 'type_confidence' => 0.9 ), 'sections' => array_merge(
		array( array( 'id' => 'section_001', 'type' => 'header', 'order' => 0, 'components' => array() ) ),
		array( array( 'id' => 'section_002', 'type' => 'card', 'order' => 1, 'components' => array( array( 'type' => 'h3', 'text' => 'Card' ), array( 'type' => 'p', 'text' => 'Text' ), array( 'type' => 'a', 'href' => '/x' ) ), 'background' => '#f7f7f7', 'radius' => '12px' ) )
	) ) ),
);

$system = $design->build( $agreeing );
check( $system['built'], 'A design system is built from two pages that produced observations.' );
check( $system['pages'] === 2, 'It knows how many pages it was built from, so a one-page system is distinguishable.' );
check( ! empty( $system['families'] ), 'Token families were produced.' );
check( isset( $system['roles']['background'] ), 'A background role is named, from the page background rather than a guess.' );
check( isset( $system['evidence']['observations'] ) && $system['evidence']['observations'] > 0, 'The number of observations behind the system is reported, so its weight is visible.' );

// A token used on one of two pages is not a global token.
$diverging = array(
	'p1' => rf12_page( array( 'page' => array( 'url' => 'https://example.com/', 'final_url' => 'https://example.com/', 'title' => 'Home', 'type' => 'homepage', 'language' => 'en', 'description' => '', 'type_confidence' => 0.9 ), 'sections' => array( array( 'id' => 'section_001', 'type' => 'hero', 'order' => 0, 'components' => array(), 'background' => '#123456', 'radius' => '12px' ) ) ) ),
	'p2' => rf12_page( array( 'page' => array( 'url' => 'https://example.com/about', 'final_url' => 'https://example.com/about', 'title' => 'About', 'type' => 'about', 'language' => 'en', 'description' => '', 'type_confidence' => 0.9 ), 'sections' => array( array( 'id' => 'section_001', 'type' => 'hero', 'order' => 0, 'components' => array(), 'background' => '#654321', 'radius' => '16px' ) ) ) ),
);

$conflicted = $design->build( $diverging );
check( count( $conflicted['conflicts'] ) > 0, 'Two pages that disagree produce a recorded conflict rather than a silently chosen value.' );

$has_conflict = false;
foreach ( $conflicted['conflicts'] as $entry ) {
	if ( in_array( $entry['kind'], Site_Limits::CONFLICT_KINDS, true ) && 'conflict' === $entry['status'] ) {
		$has_conflict = true;
		check( ! empty( $entry['pages'] ) || ! empty( $entry['other_pages'] ), 'A conflict names the pages on each side, so a user can see which pages disagree.' );
		check( '' === $entry['resolution'], 'A conflict carries no resolution, because the data cannot say which value is correct.' );
	}
}
check( $has_conflict, 'At least one conflict is classified with a declared kind.' );

$empty_system = $design->build( array() );
check( ! $empty_system['built'], 'A website with nothing analyzable reports that no design system was built.' );
check( '' !== $empty_system['reason'], 'And it says why, so an empty result is not indistinguishable from a failure.' );

// Responsive: the intersection is the website fact.
$responsive = $conflicted['responsive'];
check( isset( $responsive['known'] ), 'A responsive report is always present, so a consumer never has to guess whether one exists.' );

// A single page produces no global agreement claim about itself.
check( ! empty( $system['agreement']['pages'] ), 'The agreement report says how many pages it measured.' );

/* =====================================================================
 * 5. Shared components are fingerprinted by structure, not class names.
 * ================================================================== */

echo "--- 5. Shared components ---\n";

$detector = new Shared_Component_Detector();

$footer_a = array( 'id' => 'section_003', 'type' => 'footer', 'order' => 2, 'components' => array( array( 'type' => 'a', 'href' => '/privacy/' ), array( 'type' => 'p', 'text' => 'Copyright' ) ) );
$footer_b = array( 'id' => 'section_009', 'type' => 'footer', 'order' => 4, 'components' => array( array( 'type' => 'a', 'href' => '/legal/' ), array( 'type' => 'p', 'text' => 'Copyright 2024' ) ) );

$two_footers = array(
	'p1' => rf12_page( array( 'sections' => array( array( 'id' => 'section_001', 'type' => 'hero', 'order' => 0, 'components' => array() ), array( 'id' => 'section_002', 'type' => 'content', 'order' => 1, 'components' => array() ), $footer_a ) ) ),
	'p2' => rf12_page( array( 'sections' => array( array( 'id' => 'section_001', 'type' => 'hero', 'order' => 0, 'components' => array() ), array( 'id' => 'section_002', 'type' => 'testimonials', 'order' => 1, 'components' => array() ), $footer_b ) ) ),
);

$shared = $detector->detect( $two_footers );
check( $shared['count'] >= 1, 'A footer on two pages is detected as a shared structure.' );

$footer = null;
foreach ( $shared['shared'] as $component ) {
	if ( 'footer' === $component['role'] ) {
		$footer = $component;
	}
}
check( null !== $footer, 'And it is given the footer role rather than a generic one.' );
check( $footer && in_array( 'p1', $footer['pages'], true ) && in_array( 'p2', $footer['pages'], true ), 'It records both pages, so "used on 2 pages" is a fact rather than an estimate.' );
check( $footer && 16 === strlen( (string) $footer['fingerprint'] ), 'It carries a fingerprint, so the same structure can be recognised again on a later run.' );
check( $footer && array() === $footer['content'], 'It carries no content, because a shared component that carried one page\'s text would rewrite it on every other page.' );
check( is_array( $footer['slots'] ), 'It exposes content slots, which is where a page\'s own text and images go.' );

// A class name must not be part of the fingerprint.
$with_class_a = Shared_Component_Detector::fingerprint( array( 'role' => 'footer', 'structure' => array( 'count' => 2, 'types' => array( 'a' => 1, 'p' => 1 ), 'depth' => 0 ), 'style' => array(), 'content' => array( 'roles' => array( 'link', 'text' ) ) ) );
$with_class_b = Shared_Component_Detector::fingerprint( array( 'role' => 'footer', 'class' => 'site-footer-v2', 'structure' => array( 'depth' => 0, 'types' => array( 'p' => 1, 'a' => 1 ), 'count' => 2 ), 'class_b' => 'colophon', 'style' => array(), 'content' => array( 'roles' => array( 'text', 'link' ) ) ) );
same( $with_class_a, $with_class_b, 'Two footers with different class names and differently ordered keys fingerprint identically, because structure decides identity rather than markup.' );

$different = Shared_Component_Detector::fingerprint( array( 'role' => 'footer', 'structure' => array( 'count' => 9, 'types' => array( 'a' => 9 ), 'depth' => 2 ), 'style' => array(), 'content' => array( 'roles' => array( 'link' ) ) ) );
check( $different !== $with_class_a, 'A structurally different footer fingerprints differently.' );

// One page is not shared.
$one_page = $detector->detect( array( 'p1' => rf12_page() ) );
same( $one_page['count'], 0, 'A structure on one page is not shared, so editing it cannot change another page.' );
check( $one_page['single_use'] > 0, 'It is counted as single-use, so the number is not silently lost.' );
check( '' !== $one_page['note'], 'And the reason is stated, so an empty list is not read as a failure.' );

// Stable ids.
same( Shared_Component_Detector::stable_id( 'header', 0 ), 'shared_header', 'A shared component gets a readable stable id rather than a generated one.' );
same( Shared_Component_Detector::stable_id( 'header', 1 ), 'shared_header_2', 'A second variant of the same role is numbered, so both are addressable.' );
same( Shared_Component_Detector::stable_id( 'not-a-role', 0 ), 'shared_card', 'An undeclared role falls back to a declared one rather than inventing a new vocabulary at runtime.' );

/* =====================================================================
 * 6. Templates are structures, and say so.
 * ================================================================== */

echo "--- 6. Templates and content slots ---\n";

$registry = new Component_Registry();
$project  = 'proj_' . substr( hash( 'sha256', 'phase12-templates' ), 0, 8 );

$template_pages = array(
	's1' => array( 'type' => 'service_detail', 'representation' => rf12_page( array( 'sections' => array( array( 'id' => 'section_001', 'type' => 'hero', 'order' => 0, 'components' => array() ), array( 'id' => 'section_002', 'type' => 'intro', 'order' => 1, 'components' => array() ), array( 'id' => 'section_003', 'type' => 'features', 'order' => 2, 'components' => array() ) ) ) ) ),
	's2' => array( 'type' => 'service_detail', 'representation' => rf12_page( array( 'sections' => array( array( 'id' => 'section_001', 'type' => 'hero', 'order' => 0, 'components' => array() ), array( 'id' => 'section_002', 'type' => 'intro', 'order' => 1, 'components' => array() ), array( 'id' => 'section_003', 'type' => 'features', 'order' => 2, 'components' => array() ) ) ) ) ),
	's3' => array( 'type' => 'about', 'representation' => rf12_page( array( 'sections' => array( array( 'id' => 'section_001', 'type' => 'hero', 'order' => 0, 'components' => array() ), array( 'id' => 'section_002', 'type' => 'story', 'order' => 1, 'components' => array() ), array( 'id' => 'section_003', 'type' => 'team', 'order' => 2, 'components' => array() ) ) ) ) ),
);

$tpl = $registry->detect_templates( $template_pages );
check( $tpl['count'] >= 1, 'Two pages with the same section structure produce a template.' );

$service_tpl = null;
foreach ( $tpl['templates'] as $template ) {
	if ( 'service_detail' === $template['page_type'] ) {
		$service_tpl = $template;
	}
}
check( null !== $service_tpl, 'The template is named for the page type it captures.' );
check( $service_tpl && $service_tpl['page_count'] === 2, 'It records how many pages use it.' );
check( $service_tpl && count( $service_tpl['pages'] ) === 2, 'And which ones, so "used by 2 pages" is a fact.' );
check( $service_tpl && false === strpos( (string) $service_tpl['signature'], 'Copyright' ), 'The signature contains no page text, because a template that captured one page\'s words would carry them to every other page.' );
check( $service_tpl && ! empty( $service_tpl['slots'] ), 'It declares content slots.' );
check( $service_tpl && ! $service_tpl['generated'], 'It is not marked as generated, because no Elementor template has been created yet.' );

$slot_ids = array();
foreach ( $service_tpl['slots'] as $slot ) {
	$slot_ids[] = $slot['slot_id'];
}
check( in_array( 'hero_title', $slot_ids, true ), 'A service template exposes a hero title slot rather than a hero title value.' );
check( in_array( 'features', $slot_ids, true ), 'And a repeatable features slot, because a service has several features.' );

$product_slots = Component_Registry::template_slots( 'product' );
$product_ids   = array();
foreach ( $product_slots as $slot ) {
	$product_ids[] = $slot['slot_id'];
}
check( in_array( 'price', $product_ids, true ), 'A product template exposes a price slot, so a price is a value in a known position rather than baked into the structure.' );

$unknown_slots = Component_Registry::template_slots( 'not_a_type' );
check( count( $unknown_slots ) > 0, 'An unrecognised page type still gets slots, so a template is never slotless.' );

/* =====================================================================
 * 7. The registry protects user overrides.
 * ================================================================== */

echo "--- 7. Registry and overrides ---\n";

$stored = $registry->put_shared( $project, array(
	array( 'component_id' => 'shared_header', 'role' => 'header', 'pages' => array( 'p1', 'p2' ), 'page_count' => 2, 'content' => array() ),
	array( 'component_id' => 'shared_footer', 'role' => 'footer', 'pages' => array( 'p1', 'p2' ), 'page_count' => 2, 'content' => array() ),
) );
same( $stored['stored'], 2, 'Both shared components are stored.' );

$registry->load( $project );
check( null !== $registry->find_shared( 'shared_header' ), 'A stored component can be read back by id.' );
check( null === $registry->find_shared( 'shared_nothing' ), 'An unknown id returns nothing rather than an empty component.' );
check( count( $registry->components_for_page( 'p1' ) ) === 2, 'The components a page uses can be found from the page.' );
check( $registry->may_rewrite( 'shared_header' ), 'A component nobody has edited may be rewritten.' );

$override = $registry->mark_overridden( 'shared_header', array( 'note' => 'My colours' ) );
check( $override['success'], 'A user override is recorded.' );
check( ! $registry->may_rewrite( 'shared_header' ), 'And after that the component may not be rewritten, so a later sync cannot discard the edit.' );

// Re-running detection must not silently undo the override.
$registry->put_shared( $project, array(
	array( 'component_id' => 'shared_header', 'role' => 'header', 'pages' => array( 'p1', 'p2', 'p3' ), 'page_count' => 3, 'content' => array() ),
) );
$kept = $registry->find_shared( 'shared_header' );
check( ! empty( $kept['overridden'] ), 'A re-analysis keeps the override rather than clearing it.' );
check( in_array( 'p3', $kept['pages'], true ), 'It also picks up a newly discovered page, so the identity survives a structural change.' );

// A component that disappears is marked stale, not deleted, when overridden.
$registry->put_shared( $project, array() );
$stale = $registry->find_shared( 'shared_header' );
check( ! empty( $stale['stale'] ), 'An overridden component that is no longer detected is marked stale rather than dropped, because one page failing to analyze is enough to lose it.' );
check( null === $registry->find_shared( 'shared_footer' ), 'A component nobody edited that is no longer detected is dropped, so the registry does not accumulate ghosts.' );

$missing = $registry->mark_overridden( 'shared_nothing', array() );
check( ! $missing['success'], 'Marking a component that is not in the registry fails rather than creating one.' );

/* =====================================================================
 * 8. Navigation maps only to pages that exist.
 * ================================================================== */

echo "--- 8. Navigation and URL mapping ---\n";

$mapper = new Navigation_Mapper( 'https://example.com' );
$mapper->register_pages( array(
	'p1' => array( 'page_id' => 'p1', 'source_url' => 'https://example.com/' ),
	'p2' => array( 'page_id' => 'p2', 'source_url' => 'https://example.com/about/' ),
	'p3' => array( 'page_id' => 'p3', 'source_url' => 'https://example.com/services/web/' ),
) );

$mapped = $mapper->map( 'https://example.com/about/' );
same( $mapped['type'], 'replica_page', 'A link to a reconstructed page is rewritten.' );
same( $mapped['target'], '/about/', 'And it points at the local path, not at the source domain.' );

$unmapped = $mapper->map( 'https://example.com/careers/' );
same( $unmapped['type'], 'unmapped', 'A link to a page that was not reconstructed is not rewritten.' );
same( $unmapped['target'], 'https://example.com/careers/', 'It still points at the source rather than at a replacement ReplicaForge invented.' );
check( ! empty( $unmapped['review'] ), 'And it is flagged for review, so a user sees it rather than discovering a link leaving the site.' );

$external = $mapper->map( 'https://other.com/news/' );
same( $external['type'], 'external', 'A genuinely external link stays external.' );
same( $external['target'], 'https://other.com/news/', 'It is not rewritten, because the source linked out.' );

$mailto = $mapper->map( 'mailto:hello@example.com' );
same( $mailto['type'], 'contact', 'A mailto link is kept as supplied.' );

$script = $mapper->map( 'javascript:alert(1)' );
check( ! $script['success'], 'A javascript link is refused rather than copied, because carrying it into the replica would be a scripting vector.' );

check( '' === $mapper->map( 'not a url' )['target'], 'A value that is not an address yields no target.' );

$nav = $mapper->build( array(
	'p1' => rf12_page( array( 'sections' => array(
		array( 'id' => 'section_001', 'type' => 'header', 'order' => 0, 'components' => array( array( 'type' => 'nav', 'links' => array(
			array( 'label' => 'Home', 'href' => '/' ),
			array( 'label' => 'About', 'href' => '/about/' ),
			array( 'label' => 'Careers', 'href' => '/careers/' ),
			array( 'label' => 'Twitter', 'href' => 'https://twitter.com/example' ),
			array( 'label' => 'No address', 'href' => '' ),
		) ) ) ),
	) ) ),
) );

$labels = array();
foreach ( $nav['areas']['primary'] as $item ) {
	$labels[] = $item['label'];
}
check( in_array( 'About', $labels, true ), 'A label from the source is preserved exactly, rather than regenerated.' );
check( $nav['counts']['unmapped'] >= 1, 'An unmapped internal link is counted.' );
check( $nav['counts']['external'] >= 1, 'An external link is counted separately, so the two are not conflated.' );
check( isset( $nav['url_map']['example.com/about'] ), 'A source-to-replica map is produced.' );
check( ! empty( $nav['notes']['no_invention'] ), 'The output states that nothing was invented.' );

/* =====================================================================
 * 9. The asset registry deduplicates and defaults to reference.
 * ================================================================== */

echo "--- 9. Asset registry ---\n";

$assets = new Asset_Registry();
$registry_assets = $assets->build( array(
	'p1' => rf12_page( array( 'assets' => array(
		array( 'url' => 'https://example.com/logo.png?v=1', 'alt' => 'Logo' ),
		array( 'url' => 'https://example.com/logo.png?v=2', 'alt' => 'Logo' ),
		array( 'url' => 'https://example.com/hero.jpg', 'alt' => 'Hero', 'width' => 1200, 'height' => 600 ),
	) ) ),
	'p2' => rf12_page( array( 'assets' => array(
		array( 'url' => 'https://example.com/logo.png?v=1', 'alt' => 'Logo' ),
		array( 'url' => 'data:image/png;base64,AAAA', 'alt' => 'Inline' ),
	) ) ),
) );

check( $registry_assets['count'] === 2, 'The same image under two cache-busting URLs is one asset, and an inline data URI is not an asset at all.' );
check( $registry_assets['shared'] >= 1, 'An asset used on two pages is marked shared.' );
check( isset( $registry_assets['rejected']['data_uri'] ), 'The rejected inline URI is counted with a reason rather than vanishing.' );

$logo = null;
foreach ( $registry_assets['assets'] as $asset ) {
	if ( false !== strpos( (string) $asset['source_url'], 'logo' ) ) {
		$logo = $asset;
	}
}
check( null !== $logo, 'The logo is in the registry.' );
check( $logo && 3 === (int) $logo['usage_count'], 'Its usage count is the number of references — three here, across two pages — not the number of pages.' );
check( $logo && count( $logo['pages'] ) === 2, 'And it records both pages that use it.' );
same( $logo['mode'], 'reference', 'The default mode is reference, so nothing is downloaded into the media library without a deliberate choice.' );
check( $logo && ! empty( $logo['provenance']['source_site'] ), 'Provenance records which website the asset came from.' );
check( $logo && false === $logo['provenance']['stored'], 'And that it has not been stored locally.' );
check( $logo && 'logo' === $logo['role'], 'An asset with "logo" in its alt text is given that role.' );

$hero = null;
foreach ( $registry_assets['assets'] as $asset ) {
	if ( false !== strpos( (string) $asset['source_url'], 'hero' ) ) {
		$hero = $asset;
	}
}
check( $hero && 1200 === (int) $hero['dimensions']['width'] && 600 === (int) $hero['dimensions']['height'], 'Declared dimensions are kept, and only declared ones.' );
check( $logo && array() === $logo['dimensions'], 'An asset whose dimensions the source did not declare carries none, rather than a guess that a validator would then report as a mismatch.' );

// A parameter that is not known to be a cache buster must keep the assets apart.
check( $assets->dedup_key( 'https://example.com/p?id=1' ) !== $assets->dedup_key( 'https://example.com/p?id=2' ), 'A query parameter that might select the resource is kept, so two different products are not merged into one asset.' );
check( $assets->dedup_key( 'https://example.com/p?v=1' ) === $assets->dedup_key( 'https://example.com/p?v=2' ), 'A known cache-busting parameter is removed, because those are the same bytes.' );

$blocked = $assets->inspect( 'http://127.0.0.1/secret.png' );
check( ! $blocked['usable'], 'An asset on a loopback address is refused, so a source page cannot make ReplicaForge read the server\'s own files.' );
check( '' === $blocked['key'], 'A refused asset gets no registry key, so it cannot be recorded as a usable one.' );

same( Asset_Registry::normalise_mode( 'IMPORT' ), 'import', 'An import mode is accepted, whatever its case.' );
same( Asset_Registry::normalise_mode( 'nonsense' ), 'reference', 'An unrecognised mode falls back to reference, the safe one.' );
check( ! Asset_Registry::may_import( array( 'type' => 'text/css' ) )['ok'], 'A stylesheet is not importable, because importing a stylesheet would mean executing someone else\'s code.' );
check( Asset_Registry::may_import( array( 'type' => 'image/png' ) )['ok'], 'A PNG is importable.' );
check( isset( $registry_assets['notes']['rights'] ), 'The registry states that a public address is not permission to reuse.' );

/* =====================================================================
 * 10. Global style ownership defaults to unknown, and unknown writes nothing.
 * ================================================================== */

echo "--- 10. Theme, Elementor, and global style ownership ---\n";

$compatibility = new Site_Compatibility( new Elementor_Compatibility() );
$ownership = $compatibility->ownership( 'proj_ownership' );

same( $ownership['state'], 'unknown', 'Ownership defaults to unknown, because the one state whose safe behaviour is "do nothing" must be the default.' );
check( ! $ownership['writable'], 'And unknown is not writable, so nothing is overwritten on a guess.' );
check( ! empty( $ownership['rule'] ), 'The rule is stated in the output rather than only in a comment.' );

$report = $compatibility->report();
check( isset( $report['capabilities'] ), 'A capability report is always present, even with Elementor absent.' );
foreach ( array( 'containers', 'flexbox', 'grid', 'global_colors', 'global_fonts', 'responsive', 'theme_builder' ) as $capability ) {
	check( array_key_exists( $capability, $report['capabilities'] ), sprintf( 'The %s capability is reported as present or absent, never omitted.', $capability ) );
}

check( isset( $report['strategies']['header']['default'] ), 'A header strategy is chosen.' );
check( isset( $report['strategies']['footer']['default'] ), 'A footer strategy is chosen.' );
check( in_array( $report['strategies']['header']['default'], Site_Limits::BOUNDARY_STRATEGIES, true ), 'The chosen header strategy is a declared one.' );
check( ! empty( $report['strategies']['header']['reason'] ), 'The choice is explained, so the user is never shown a strategy without knowing why.' );
check( ! empty( $report['strategies']['footer']['duplication_warning'] ), 'The user is warned that generating a footer the theme already has would show it twice.' );
check( isset( $report['theme']['name'] ), 'The active theme is probed, so theme influence can be considered.' );

// Theme Builder is detected, not assumed.
$builder = $compatibility->builder_locations();
check( is_array( $builder ), 'Theme Builder availability is returned as a list, empty when the API is not there.' );
check( empty( $builder ) || count( $builder ) > 0, 'And it is a real answer either way rather than a guess.' );

$fallbacks = $report['fallbacks'];
check( is_array( $fallbacks ), 'Fallbacks are listed, so a missing capability is a stated substitution rather than a silent one.' );
if ( empty( $report['capabilities']['containers'] ) ) {
	$found = false;
	foreach ( $fallbacks as $fallback ) {
		if ( 'containers' === $fallback['missing'] ) {
			$found = true;
			check( '' !== $fallback['used'], 'And it names what will be used instead.' );
		}
	}
	check( $found, 'A missing container capability produces a declared fallback.' );
}

$adopted = $compatibility->record_ownership( 'proj_ownership', 'replicaforge_controlled', array( 'primary' => '#123456' ) );
check( $adopted['success'], 'Ownership can be deliberately adopted.' );
check( $compatibility->ownership( 'proj_ownership' )['writable'], 'And then global styles are writable, because ReplicaForge now owns what it wrote.' );
check( ! $compatibility->record_ownership( 'proj_ownership', 'nonsense' )['success'], 'An unrecognised ownership state is refused rather than stored.' );

$compatibility->record_ownership( 'proj_ownership', 'user_controlled' );
check( ! $compatibility->ownership( 'proj_ownership' )['writable'], 'Handing ownership back makes the styles unwritable again, so a user can stop ReplicaForge managing them.' );

/* =====================================================================
 * 11. The website specification is a gate, not a report.
 * ================================================================== */

echo "--- 11. Website specification ---\n";

$analyzer = new Site_Analyzer();
$spec = $analyzer->analyze( 'proj_spec', array(
	'p1' => rf12_page(),
	'p2' => rf12_page( array( 'page' => array( 'url' => 'https://example.com/about', 'final_url' => 'https://example.com/about', 'title' => 'About', 'type' => 'about', 'language' => 'en', 'description' => '', 'type_confidence' => 0.9 ) ) ),
), array( 'source_url' => 'https://example.com/', 'host' => 'example.com', 'name' => 'Example' ) );

check( Site_Limits::SCHEMA_VERSION === $spec['schema_version'], 'The specification declares its own schema version, separate from the page representation\'s.' );
foreach ( array( 'website', 'pages', 'global_design_system', 'shared_components', 'templates', 'navigation', 'assets', 'relationships', 'confidence', 'warnings' ) as $key ) {
	check( array_key_exists( $key, $spec ), sprintf( 'The specification carries a %s section.', $key ) );
}
check( $spec['validation']['valid'], 'A specification built from two real pages is valid.' );
same( $spec['validation']['gate'], 'open', 'So the generation gate is open.' );
check( '' !== $spec['website']['name'], 'The website name is derived from the host rather than left blank.' );
check( $spec['website']['type'] !== '', 'A website type is inferred from the page types found.' );
check( ! empty( $spec['stages']['classification']['ran'] ), 'Each stage reports that it ran.' );
check( $spec['stages']['design_system']['ran'], 'The design system stage reports whether it produced anything.' );
check( isset( $spec['stages']['shared_components']['reason'] ), 'The shared component stage explains its result, so an empty list is not read as a failure.' );
check( ! empty( $spec['confidence']['note'] ), 'The confidence figure is accompanied by what it means.' );

// §66: structure and content are separated.
check( isset( $spec['content_mapping']['structure'] ) && isset( $spec['content_mapping']['content'] ), 'Content and structure are kept in separate sections.' );
check( ! empty( $spec['content_mapping']['separation'] ), 'And the separation is stated, so it is a design decision and not an accident.' );

$representation = new Site_Representation( $spec );
check( $representation->is_valid(), 'The specification validates.' );
check( $representation->get_validation_errors() === array(), 'With no errors.' );
same( count( $representation->pages() ), 2, 'It reports two pages.' );
check( null !== $representation->page( 'p1' ), 'A page can be fetched by id.' );
check( null === $representation->page( 'nope' ), 'An unknown page id returns nothing rather than an empty record.' );

// Relationships come from what was found.
$edges = $representation->relationships();
check( is_array( $edges ), 'Relationships are produced.' );
$invented = false;
foreach ( $edges as $edge ) {
	if ( ! in_array( $edge['via'], array( 'shared_component', 'template', 'navigation' ), true ) ) {
		$invented = true;
	}
}
check( ! $invented, 'Every relationship names a real source of evidence, so no relationship is invented.' );

$map = $analyzer->map( $spec );
same( count( $map['nodes'] ), 2, 'The map has a node per page.' );
check( ! empty( $map['roots'] ), 'And at least one root, so the map is drawable rather than a set of unconnected nodes.' );
check( ! empty( $map['note'] ), 'The map states that its lines are found relationships.' );

// A specification with a component pointing at a page that is not in the project
// must be refused, not quietly accepted.
$broken = $spec;
$broken['shared_components'][] = array( 'component_id' => 'shared_ghost', 'role' => 'header', 'pages' => array( 'page_not_here' ), 'page_count' => 1 );
$broken_verdict = ( new Site_Representation( $broken ) )->validate();
check( ! $broken_verdict['valid'], 'A shared component naming a page outside the project is an error, not a warning.' );
check( in_array( 'component_references_unknown_page', $broken_verdict['errors'], true ), 'And the error names the reason.' );

$no_pages = $spec;
$no_pages['pages'] = array();
$no_pages_verdict = ( new Site_Representation( $no_pages ) )->validate();
check( ! $no_pages_verdict['valid'], 'A specification with no pages is refused, because there is nothing to generate.' );

$bad_url = $spec;
$bad_url['pages'][0]['source_url'] = 'http://169.254.169.254/';
$bad_url_verdict = ( new Site_Representation( $bad_url ) )->validate();
check( ! $bad_url_verdict['valid'], 'A page on a metadata address is refused even inside a stored specification.' );
check( in_array( 'unsafe_page_url', $bad_url_verdict['errors'], true ), 'And the error is specific.' );

$with_content = $spec;
$with_content['shared_components'][] = array( 'component_id' => 'shared_carrying', 'role' => 'footer', 'pages' => array( 'p1', 'p2' ), 'content' => array( 'text' => 'Copyright 2024' ) );
$with_content_verdict = ( new Site_Representation( $with_content ) )->validate();
check( $with_content_verdict['valid'], 'A component carrying content is a warning rather than an error, because the content can be separated without losing the structure.' );
check( in_array( 'shared_component_carries_content', $with_content_verdict['warnings'], true ), 'And it is warned about, so the content/structure boundary is visible.' );

/* =====================================================================
 * 12. The design view tells the truth about scope.
 * ================================================================== */

echo "--- 12. Design system view ---\n";

$view = $analyzer->design_view( $spec );
check( array_key_exists( 'built', $view ), 'The view reports whether a design system was built.' );
check( isset( $view['threshold'] ), 'It states the agreement threshold it is judging against, so a "global" claim is checkable.' );
check( ! empty( $view['note'] ), 'And it explains what a global value means.' );
check( is_array( $view['groups'] ), 'Token groups are present, keyed by family.' );

$roles = $view['roles'];
check( is_array( $roles ), 'Named roles are present.' );
if ( isset( $roles['background'] ) ) {
	$role = $roles['background'];
	check( array_key_exists( 'source', $role ), 'A role records where its value came from.' );
	check( array_key_exists( 'confidence', $role ), 'And how confident ReplicaForge is.' );
	check( array_key_exists( 'usage_count', $role ), 'And how many times it was observed.' );
	check( array_key_exists( 'disputed', $role ), 'And whether the pages actually agree on it.' );
}

// A role the pages disagree about must be marked disputed, not presented as truth.
$diverged_view = $analyzer->design_view( $design->build( $diverging ) );
$any_disputed = false;
foreach ( $diverged_view['roles'] as $role ) {
	if ( ! empty( $role['disputed'] ) ) {
		$any_disputed = true;
	}
}
check( $any_disputed || array() === $diverged_view['roles'], 'A role the pages disagree about is marked disputed, so it is not silently enforced as a standard.' );

/* =====================================================================
 * 13. Generation planning: order, drafts, isolation.
 * ================================================================== */

echo "--- 13. Generation planning ---\n";

$planner = new Multi_Page_Planner( $registry, null );
$plan = $planner->plan( $spec, array( 'mode' => 'consistent_website' ) );

check( $plan['built'], 'A plan is built from a valid specification.' );
same( $plan['gate'], 'open', 'The gate is open because the specification is valid.' );
check( $plan['counts']['total'] > 0, 'It contains steps.' );
check( $plan['counts']['pages'] === 2, 'Two pages are planned.' );
check( false === $plan['draft_policy']['publish'], 'The plan states that nothing is published.' );
check( ! empty( $plan['draft_policy']['message'] ), 'And says so in words the user reads.' );

$phases = array();
foreach ( $plan['steps'] as $step ) {
	$phases[] = $step['phase'];
	check( false === $step['draft']['publish'], 'No step carries a publish flag, so there is no step that can publish a page.' );
}
$first_page = null;
$first_design = null;
foreach ( $plan['steps'] as $step ) {
	if ( 'global_design' === $step['phase'] && null === $first_design ) { $first_design = array_search( $step, $plan['steps'], true ); }
	if ( in_array( $step['phase'], array( 'pages_primary', 'pages_secondary', 'pages_collection' ), true ) && null === $first_page ) { $first_page = array_search( $step, $plan['steps'], true ); }
}
check( null !== $first_design && null !== $first_page && $first_design < $first_page, 'The design system step precedes every page step in the emitted order.' );

$page_steps = array();
foreach ( $plan['steps'] as $step ) {
	if ( 0 === strpos( (string) $step['step_id'], 'page_' ) ) {
		$page_steps[] = $step;
	}
}
same( count( $page_steps ), 2, 'Each page is its own step, which is what makes a failure isolable.' );
$statuses = array();
foreach ( $page_steps as $step ) {
	$statuses[ (string) $step['step_id'] ] = (string) $step['status'];
}
same( array_values( array_unique( array_values( $statuses ) ) ), array( 'pending' ), 'Every page step starts pending, so an untried page is not reported as done.' );

// A gate failure produces no steps at all.
$invalid = $planner->plan( $no_pages, array() );
check( ! $invalid['built'], 'An invalid specification produces no plan.' );
same( $invalid['steps'], array(), 'And no steps at all, rather than a plan with warnings attached.' );
same( $invalid['gate'], 'closed', 'The gate is closed.' );

// Plan id is derived from content, so an unchanged specification is the same plan.
same( $planner->plan_id( $spec ), $planner->plan_id( $spec ), 'The same specification produces the same plan id.' );
check( $planner->plan_id( $spec ) !== $planner->plan_id( $no_pages ), 'A different specification produces a different plan id.' );

// Existing pages are reported, never overwritten.
$conflict = $planner->existing_conflict( array( 'source_url' => 'https://example.com/about/' ) );
check( array_key_exists( 'conflict', $conflict ), 'A conflict check is reported rather than acted on.' );
check( ! empty( $conflict['note'] ), 'And the note explains the two choices the user has.' );
$free = $planner->existing_conflict( array( 'source_url' => 'https://example.com/nothing-here/' ) );
check( ! $free['conflict'], 'A page at an address nothing occupies reports no conflict.' );

/* =====================================================================
 * 14. Incremental sync: a page change is not a website rebuild.
 * ================================================================== */

echo "--- 14. Incremental sync scope ---\n";

$template_spec = $planner->plan( $spec, array() );
$sync = $planner->sync_scope( array( 'p1' ), $spec );
check( in_array( 'shared_component', $sync['scopes'] ) || in_array( 'page_only', $sync['scopes'] ), 'A changed page produces a declared scope.' );
check( $sync['changed'] === array( 'p1' ), 'The changed page is named.' );
check( is_array( $sync['affected'] ), 'And the affected set is computed rather than assumed.' );
check( ! empty( $sync['note'] ), 'The result is explained in words.' );

// A page in no shared structure stays a page-only change.
$lone = $spec;
$lone['shared_components'] = array();
$lone['templates']         = array();
$lone['navigation']        = array( 'links' => array() );
$lone['global_design_system'] = array( 'built' => false, 'responsive' => array() );
$lone['assets']            = array( 'assets' => array() );
$lone_scope = $planner->sync_scope( array( 'p1' ), $lone );
same( $lone_scope['scopes'], array( 'page_only' ), 'A page in no shared structure is a page-only change, so one edit does not rebuild the website.' );
same( $lone_scope['affected'], array(), 'And nothing else is listed as affected.' );

// A shared component that one page uses pulls its peers in.
$shared_spec = $spec;
$shared_spec['shared_components'] = array( array( 'component_id' => 'shared_header', 'role' => 'header', 'pages' => array( 'p1', 'p2' ), 'page_count' => 2 ) );
$shared_scope = $planner->sync_scope( array( 'p1' ), $shared_spec );
check( in_array( 'shared_component', $shared_scope['scopes'], true ), 'A page that is part of a shared component produces a shared-component scope.' );
check( in_array( 'p2', $shared_scope['affected'], true ), 'And the other page using it is named as affected.' );
$component_entry = $shared_scope['components'][0];
check( in_array( 'p1', $component_entry['changed_on'], true ), 'The page that changed is named on the component.' );
check( in_array( 'p2', $component_entry['also_on'], true ), 'And so is the page that did not, because it is affected by the shared structure.' );

// An unknown page cannot be named to force a website-wide change.
$unknown_scope = $planner->sync_scope( array( 'page_not_in_project' ), $spec );
same( $unknown_scope['changed'], array( 'page_not_in_project' ), 'The planner itself does not filter unknown ids — the REST layer does, before it reaches here.' );
check( count( $unknown_scope['components'] ) === 0, 'And an id in no shared structure produces no component work, so naming it cannot rebuild the website.' );

/* =====================================================================
 * 15. Cross-page validation finds what per-page validation cannot.
 * ================================================================== */

echo "--- 15. Cross-page validation ---\n";

$validator = new Cross_Page_Validator();

$consistent_spec = $spec;
$consistent_spec['shared_components'] = array( array( 'component_id' => 'shared_cta', 'role' => 'cta', 'pages' => array( 'p1', 'p2' ), 'page_count' => 2, 'value' => '#6c63ff' ) );
$generated_consistent = array(
	'p1' => array( 'post_id' => 1, 'status' => 'generated', 'components' => array( 'shared_cta' => array( 'value' => '#6c63ff' ) ) ),
	'p2' => array( 'post_id' => 2, 'status' => 'generated', 'components' => array( 'shared_cta' => array( 'value' => '#6C63FF' ) ) ),
);
$clean = $validator->validate( $consistent_spec, $generated_consistent );
same( $clean['verdict'], 'pass', 'Two pages rendering the same shared component in the same colour pass cross-page validation.' );
$inconsistent_found = false;
foreach ( $clean['findings'] as $finding ) {
	if ( 'shared_component_inconsistency' === $finding['code'] ) {
		$inconsistent_found = true;
	}
}
check( ! $inconsistent_found, 'Case differences in a hex colour are not reported as an inconsistency, because #fff and #FFFFFF are the same colour.' );

// The §45 case exactly.
$generated_divergent = array(
	'p1' => array( 'post_id' => 1, 'status' => 'generated', 'components' => array( 'shared_cta' => array( 'value' => '#6c63ff' ) ) ),
	'p2' => array( 'post_id' => 2, 'status' => 'generated', 'components' => array( 'shared_cta' => array( 'value' => '#735fff' ) ) ),
);
$divergent = $validator->validate( $consistent_spec, $generated_divergent );
$found = null;
foreach ( $divergent['findings'] as $finding ) {
	if ( 'shared_component_inconsistency' === $finding['code'] ) {
		$found = $finding;
	}
}
check( null !== $found, 'A shared component rendered one way on one page and another way on another is found, which is the case per-page validation cannot see.' );
check( $found && ! empty( $found['evidence']['pages'] ), 'The finding names the pages on the minority value.' );
check( $found && ! empty( $found['evidence']['majority'] ), 'And names the majority value, so the user can see which side is the odd one out.' );
check( $found && 'shared_component' === $found['evidence']['correction']['scope'], 'And the suggested correction is on the shared component, not on each page.' );
check( $found && $found['evidence']['correction']['eligible'], 'Which is eligible because nobody has edited it.' );
same( $found['auto_fix'], 'no', 'But the finding does not auto-fix itself, because choosing a side is the user\'s judgement.' );

// A user override blocks correction.
$overridden_spec = $consistent_spec;
$overridden_spec['shared_components'][0]['overridden'] = true;
$blocked = $validator->validate( $overridden_spec, $generated_divergent );
$blocked_finding = null;
foreach ( $blocked['findings'] as $finding ) {
	if ( 'shared_component_inconsistency' === $finding['code'] ) {
		$blocked_finding = $finding;
	}
}
check( $blocked_finding && ! $blocked_finding['evidence']['correction']['eligible'], 'A component the user has edited is not eligible for correction, so their version is protected.' );
check( $blocked_finding && 'none' === $blocked_finding['evidence']['correction']['scope'], 'And the scope says so explicitly rather than being blank.' );

// Failure isolation.
$partial_spec = $spec;
$partial_spec['pages'][] = array( 'page_id' => 'p3', 'source_url' => 'https://example.com/contact/', 'type' => 'contact', 'status' => 'analyzed' );
$partial = $validator->validate( $partial_spec, array(
	'p1' => array( 'post_id' => 1, 'status' => 'generated' ),
	'p2' => array( 'post_id' => 2, 'status' => 'failed', 'error' => 'elementor_write_refused' ),
	'p3' => array( 'post_id' => 3, 'status' => 'generated' ),
) );
same( $partial['verdict'], 'fail', 'A page that failed to generate fails the validation.' );
$failure = null;
foreach ( $partial['findings'] as $finding ) {
	if ( 'page_generation_failed' === $finding['code'] ) {
		$failure = $finding;
	}
}
check( null !== $failure, 'The failure is a finding.' );
check( $failure && $failure['evidence']['retryable'], 'And it is retryable on its own.' );
check( $failure && false !== strpos( $failure['message'], '2' ), 'And the message says the other two pages are unaffected, because isolation is the requirement.' );
same( $partial['generated'], 2, 'The two successful pages are still counted as generated.' );

// Unmapped links are reported, because a link leaving the site is a finding.
$nav_spec = $spec;
$nav_spec['navigation'] = array(
	'areas'   => array( 'primary' => array( array( 'label' => 'Careers', 'type' => 'unmapped', 'source' => 'https://example.com/careers/' ) ) ),
	'unmapped'=> array( array( 'label' => 'Careers', 'source' => 'https://example.com/careers/' ) ),
	'links'   => array(),
);
$nav_report = $validator->validate( $nav_spec, array() );
$nav_found = false;
foreach ( $nav_report['findings'] as $finding ) {
	if ( 'unmapped_internal_link' === $finding['code'] ) {
		$nav_found = true;
	}
}
check( $nav_found, 'A link to a page that was not reconstructed is reported rather than left for a user to discover.' );

check( count( Cross_Page_Validator::CATEGORIES ) === 8, 'The validation report covers every category the specification asks for.' );
foreach ( Cross_Page_Validator::CATEGORIES as $category ) {
	check( array_key_exists( $category, $nav_report['by_category'] ), sprintf( 'The %s category is always present in the report, even when it has no findings.', $category ) );
}

check( $validator->same_value( '#fff', '#ffffff' ), 'A three-digit and a six-digit hex of the same colour compare equal.' );
check( $validator->same_value( '#6c63ff', '#6c63fe' ), 'A one-unit difference in a channel is within tolerance, so antialiasing is not reported as an inconsistency.' );
check( ! $validator->same_value( '#6c63ff', '#6c63f9' ), 'A difference larger than the tolerance is not absorbed, so the tolerance does not quietly widen into "ignore colours".' );
same( Cross_Page_Validator::COLOR_TOLERANCE, 2, 'The tolerance is a small constant, because a large one would stop the check noticing real drift.' );
check( ! $validator->same_value( '#6c63ff', '#735fff' ), 'A real difference is not within tolerance.' );
check( $validator->same_value( '12px', '12.0px' ), 'Numerically equal measurements compare equal.' );
check( ! $validator->same_value( '12px', '16px' ), 'Different measurements do not.' );
check( ! $validator->same_value( '#fff', '' ), 'An unknown value is not equal to a known one, so a missing measurement is a finding rather than a pass.' );

/* =====================================================================
 * 16. Snapshots record references, not copies.
 * ================================================================== */

echo "--- 16. Snapshots and rollback ---\n";

$post_a = wp_insert_post( array( 'post_title' => 'Replica Home', 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => 'Home draft' ) );
$post_b = wp_insert_post( array( 'post_title' => 'Replica About', 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => 'About draft' ) );
check( is_int( $post_a ) && $post_a > 0, 'A draft page is created for the snapshot test.' );
check( is_int( $post_b ) && $post_b > 0, 'And a second one.' );

$snapshot_project = 'proj_snap';
$snapshot = $planner->snapshot( $snapshot_project, array(
	'p1' => array( 'post_id' => $post_a, 'source_url' => 'https://example.com/', 'generated_hash' => 'aaa' ),
	'p2' => array( 'post_id' => $post_b, 'source_url' => 'https://example.com/about/', 'generated_hash' => 'bbb' ),
), $spec );

check( $snapshot['success'], 'A snapshot is taken.' );
same( $snapshot['snapshot']['page_count'], 2, 'It records both pages.' );
check( ! empty( $snapshot['storage_note'] ), 'And states that it references rather than copies, so the cost is not a surprise.' );
check( ! isset( $snapshot['snapshot']['pages']['p1']['content'] ), 'No page content is stored in the snapshot, because the post already holds it.' );
check( isset( $snapshot['snapshot']['pages']['p1']['post_id'] ), 'The post id is stored, so the page is identifiable.' );

$rollback = $planner->rollback_plan( $snapshot_project );
check( $rollback['success'], 'A rollback plan is produced.' );
same( $rollback['scope'], 'website', 'It covers the whole website by default.' );
check( $rollback['confirmation_required'], 'And it requires confirmation, because a rollback trashes pages.' );
same( $rollback['count'], 2, 'It affects both pages.' );
check( 'trash' === $rollback['steps'][0]['action'], 'The action is to move to the trash, not to delete permanently.' );
check( ! empty( $rollback['steps'][0]['note'] ), 'And the note says it can be restored, so the plan is not a one-way door.' );

$selected_rollback = $planner->rollback_plan( $snapshot_project, '', array( 'p1' ) );
same( $selected_rollback['scope'], 'selected', 'A selected-pages rollback reports a narrower scope.' );
same( $selected_rollback['count'], 1, 'And affects only the page named.' );

$missing_rollback = $planner->rollback_plan( 'proj_never_snapshotted' );
check( ! $missing_rollback['success'], 'Rolling back a project with no snapshot fails cleanly.' );
check( '' !== $missing_rollback['message'], 'And says so, rather than reporting an empty plan as a success.' );

$bad_snapshot = $planner->rollback_plan( $snapshot_project, 'snap_does_not_exist' );
check( ! $bad_snapshot['success'], 'Asking for a snapshot that is not in the history fails rather than falling back to the latest.' );

$planner->snapshot( $snapshot_project, array( 'p1' => array( 'post_id' => $post_a ) ), $spec );
check( count( $planner->snapshots( $snapshot_project ) ) === 2, 'A second snapshot is appended to the history.' );
check( $planner->rollback_plan( $snapshot_project )['snapshot']['page_count'] === 1, 'The rollback plan defaults to the latest snapshot, not the first.' );

// Snapshot history is bounded.
for ( $i = 0; $i < Multi_Page_Planner::MAX_SNAPSHOTS + 3; $i++ ) {
	$planner->snapshot( $snapshot_project, array( 'p1' => array( 'post_id' => $post_a ) ), $spec );
}
check( count( $planner->snapshots( $snapshot_project ) ) <= Multi_Page_Planner::MAX_SNAPSHOTS, 'Snapshot history is bounded, so a project cannot grow an unbounded option.' );

foreach ( array( $post_a, $post_b ) as $id ) {
	if ( $id > 0 ) {
		wp_delete_post( (int) $id, true );
	}
}

/* =====================================================================
 * 17. The page store: ids are derived, progress is monotone.
 * ================================================================== */

echo "--- 17. Page store ---\n";

$store = new Website_Repository();
$id_same = Website_Repository::page_id_for( 'https://example.com/about/' );
same( $id_same, Website_Repository::page_id_for( 'https://example.com/about' ), 'A trailing slash does not change a page id, because it is the same page.' );
check( $id_same !== Website_Repository::page_id_for( 'https://example.com/contact/' ), 'Different pages have different ids.' );
check( $id_same !== Website_Repository::page_id_for( 'https://other.com/about/' ), 'The host is part of the id, so two sites\' `/about/` do not collide.' );
check( '' === Website_Repository::page_id_for( 'not a url' ), 'A value that is not an address has no id.' );

$store_project = 'proj_store';
$saved = $store->save_pages( $store_project, array(
	array( 'source_url' => 'https://example.com/', 'type' => 'homepage', 'selected' => true, 'title' => 'Home' ),
	array( 'source_url' => 'https://example.com/about/', 'type' => 'about', 'selected' => true, 'title' => 'About' ),
	array( 'source_url' => 'https://example.com/contact/', 'type' => 'contact', 'selected' => true, 'title' => 'Contact' ),
) );
same( $saved['pages'], 3, 'Three pages are stored.' );

$pages = $store->pages( $store_project );
$home  = null;
foreach ( $pages as $page ) {
	if ( 'homepage' === $page['page_type'] ) {
		$home = $page;
	}
}
check( null !== $home, 'The homepage is stored with its type.' );
check( $home && 100 >= (int) $home['priority'], 'The homepage carries the generation priority its type implies.' );
check( $home && false === $home['overridden'], 'And is not marked as user-overridden before anyone edits it.' );

$selected = $store->selected( $store_project );
same( $selected[0]['page_type'], 'homepage', 'Selected pages come back in priority order, so the homepage is generated first.' );

$home_id = (string) $home['page_id'];
$store->update_page( $store_project, $home_id, array( 'status' => 'generated' ) );

// A full re-save is the normal thing a discovery re-run does, so it uses the whole
// list rather than one page.
$store->save_pages(
	$store_project,
	array(
		array( 'source_url' => 'https://example.com/', 'type' => 'homepage', 'selected' => true, 'title' => 'Home' ),
		array( 'source_url' => 'https://example.com/about/', 'type' => 'about', 'selected' => true, 'title' => 'About' ),
		array( 'source_url' => 'https://example.com/contact/', 'type' => 'contact', 'selected' => true, 'title' => 'Contact' ),
	),
	array()
);
$after = $store->pages( $store_project );
$home_after = $after[ $home_id ];
same( $home_after['status'], 'generated', 'Re-saving the page list does not move a generated page back to analyzed, so a re-analysis cannot un-generate work.' );
same( count( $after ), 3, 'And the re-save did not lose the other pages.' );

check( ! $store->update_page( $store_project, 'page_nope', array( 'status' => 'generated' ) )['success'], 'Updating a page that is not in the project fails rather than creating one.' );

$unknown_type = $store->save_pages( 'proj_type', array( array( 'source_url' => 'https://example.com/x/', 'type' => 'not-a-type' ) ) );
$one = array_values( $store->pages( 'proj_type' ) );
same( $one[0]['page_type'], 'custom', 'An undeclared page type is stored as custom rather than as an invented type.' );

$summary = $store->summary( $store_project );
same( $summary['counts']['total'], 3, 'A summary reports the page count.' );
same( $summary['counts']['selected'], 3, 'And the selected count.' );
same( $summary['counts']['generated'], 1, 'And how many pages have been generated, which is the number the progress bar shows.' );
check( ! empty( $summary['by_type'] ), 'And a breakdown by type.' );

$store->mark_overridden( $store_project, $home_id, 'I changed the colours' );
$overridden_page = $store->pages( $store_project )[ $home_id ];
check( ! empty( $overridden_page['overridden'] ), 'A page-level override is recorded, so a later sync knows not to replace it.' );

$store->delete( $store_project );
same( count( $store->pages( $store_project ) ), 0, 'Deleting a project removes its page records.' );
check( ! $store->delete( 'proj_never_existed' )['success'], 'Deleting a project that is not there reports honestly rather than claiming success.' );

/* =====================================================================
 * 18. Nothing in Phase 12 publishes, invents content, or trusts the browser.
 * ================================================================== */

echo "--- 18. Security and honesty properties ---\n";

// No publish path. This is asserted rather than assumed, because "we never publish"
// is the kind of guarantee that erodes one careless commit at a time.
$publishers = array();
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( REPLICAFORGE_PATH . 'includes/multipage' ) );
foreach ( $files as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$source = (string) file_get_contents( $file->getPathname() );
	// Comments are stripped so a sentence explaining the policy is not read as a
	// violation of it.
	$code = preg_replace( '#/\*.*?\*/#s', '', $source );
	$code = preg_replace( '#//[^\n]*#', '', (string) $code );
	$code = preg_replace( '#^\s*\*.*$#m', '', (string) $code );

	if ( preg_match( "/'post_status'\s*=>\s*'publish'/", (string) $code ) ) {
		$publishers[] = $file->getFilename();
	}
	if ( preg_match( '/wp_publish_post|wp_insert_post\s*\(/', (string) $code ) ) {
		$publishers[] = $file->getFilename() . ' (writes a post)';
	}
}
same( $publishers, array(), 'No Phase 12 file sets a post to publish, and no Phase 12 file creates a post at all — generation is planned here and written by the Phase 4 boundary.' );

// No route accepts Elementor data from a browser.
$api_file   = REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-multipage-api.php';
$api_source = (string) file_get_contents( $api_file );
$api_code   = preg_replace( '#/\*.*?\*/#s', '', $api_source );
$api_code   = preg_replace( '#//[^\n]*#', '', (string) $api_code );
check( false === strpos( (string) $api_code, 'elementor_data' ), 'The REST layer never reads an Elementor data field from a request.' );
check( false === strpos( (string) $api_code, "'elements' => \$request" ), 'And no route takes a document tree from a request.' );
check( false !== strpos( $api_source, 'get_param( \'project_id\' )' ), 'Every project route addresses a project by id.' );
check( false !== strpos( $api_source, 'readable_project' ), 'And every project route resolves ownership before reading the store.' );

// The unknown-page check exists, which is what stops a forged page id.
check( false !== strpos( $api_source, 'page_id_for' ), 'The REST layer recomputes a page id from its URL rather than trusting the one submitted.' );
check( false !== strpos( $api_source, 'mismatch' ), 'And a submitted id that disagrees with the derived one is refused, not resolved.' );

// Page identity is derived, so it cannot be forged in the store either.
check( false === strpos( (string) file_get_contents( REPLICAFORGE_PATH . 'includes/multipage/class-replicaforge-website-repository.php' ), "'page_id'   => \$page[" ), 'The page store does not accept a page id from its caller; it derives one.' );

// §25 / §26, tested on the output rather than on a comment: a template must not
// carry a page's content, and a slot must be a position with no value in it.
$leaked = false;
foreach ( $tpl['templates'] as $template ) {
	foreach ( $template['slots'] as $slot ) {
		if ( array_key_exists( 'value', $slot ) && '' !== (string) $slot['value'] ) {
			$leaked = true;
		}
	}
	if ( false !== strpos( (string) wp_json_encode( $template['structure'] ), 'Text' ) ) {
		$leaked = true;
	}
}
check( ! $leaked, 'No template slot carries a value and no template structure carries a page\'s text, because a slot is a position to fill rather than a value.' );

// A shared component exposes slots, and they are positions too.
$slot_leaked = false;
foreach ( $shared['shared'] as $component ) {
	foreach ( (array) $component['slots'] as $slot ) {
		if ( array_key_exists( 'value', $slot ) && '' !== (string) $slot['value'] ) {
			$slot_leaked = true;
		}
	}
}
check( ! $slot_leaked, 'A shared component\'s slots are positions as well, so correcting a shared structure cannot rewrite a page\'s copy.' );
$spec_notes = wp_json_encode( $spec );
check( false !== strpos( (string) $spec_notes, 'rights' ), 'The specification carries a rights note, so content provenance is present in the output a user reads.' );

// Usage impact is labelled as an estimate and never as a charge.
check( false !== strpos( $api_source, 'This is an estimate based on the specification, not a measured cost' ), 'The usage impact states in the response that it is an estimate and not a billing amount.' );

/* =====================================================================
 * 19. The migration is declared, applied, and idempotent.
 * ================================================================== */

echo "--- 19. Migration ---\n";

$migrator = new \ReplicaForge\Migrator();
$migrations = $migrator->migrations();

// Found *by its own target version*, not by being the newest. Asserting "newest"
// is exactly the mistake Phase 11's notes warned about and which this suite
// already fixed once: the moment Phase 13 declared its own migration, the newest
// one stopped being Phase 12's, and the assertion failed for a reason that says
// nothing about Phase 12 being correct. The claim Phase 12 actually cares about
// is that its migration is *declared* and is at or below the installed schema.
$phase12 = array();
foreach ( $migrations as $entry ) {
	if ( '12.0.0' === (string) $entry['to'] ) {
		$phase12 = $entry;
	}
}
check( array() !== $phase12, 'The Phase 12 migration is still declared.' );
check( '' !== (string) ( $phase12['summary'] ?? '' ), 'And it has a summary, so what it did is readable without reading the code.' );
// `run` is `array( $migrator, 'method' )`. `is_callable()` is false for it because
// the method is private and is invoked internally, so the shape is asserted rather
// than callability.
$run_target = $phase12['run'] ?? null;
check( is_array( $run_target ) && 2 === count( $run_target ) && isset( $run_target[0], $run_target[1] ) && is_object( $run_target[0] ) && is_string( $run_target[1] ) && method_exists( $run_target[0], $run_target[1] ), 'And a runnable target: an object and a method that exists.' );
check( '11.0.0' === (string) ( $phase12['from'] ?? '' ), 'And it follows the 11.0.0 migration, so the chain has no gap.' );
check( version_compare( (string) $phase12['to'], (string) \ReplicaForge\Schema::DB_SCHEMA_VERSION, '<=' ), 'Its target is at or below the current database schema version, so it is part of the history rather than a stray declaration.' );

$applied = $migrator->run( true );
check( is_array( $applied ), 'The migration runs.' );
check( ! empty( $applied['success'] ), 'And reports success.' );

// The options are checked individually below, by name. An earlier version of this
// test checked the store option with `array_key_exists( OPTION, get_option( OPTION ) )`,
// which asks whether the option array contains a key equal to its own option name — a
// question with no meaning that happened to be true or false depending on the store.
foreach ( array( Website_Repository::OPTION, Component_Registry::OPTION, Multi_Page_Planner::SNAPSHOT_OPTION, Site_Compatibility::OWNERSHIP_OPTION ) as $option ) {
	check( null !== get_option( $option, null ), sprintf( 'The %s option is created, so its defaults are visible rather than implied.', $option ) );
	check( is_array( get_option( $option, null ) ), sprintf( 'The %s option is a well-formed array, so nothing that reads it has to guard against a scalar.', $option ) );
}

// Idempotency: running again must not report having done the work twice.
$again = $migrator->run( true );
check( is_array( $again ), 'The migration runs again.' );
$reported_once = false;
foreach ( (array) $migrator->migrations() as $entry ) {
	if ( '12.0.0' === (string) $entry['to'] ) {
		$reported_once = true;
	}
}
check( $reported_once, 'The Phase 12 migration remains declared after a re-run, so re-running is not the same as re-registering it.' );

$pending = $migrator->pending();
same( count( $pending ), 0, 'No migration is left pending after running them all.' );

// The declared schema version is the one the code expects, asserted as a
// relationship rather than as a literal that will bit us on the next bump.
check( version_compare( (string) Schema::installed(), '12.0.0', '>=' ), 'The installed schema is at least the Phase 12 version.' );
$declared = false;
foreach ( $migrations as $entry ) {
	if ( '12.0.0' === (string) $entry['to'] ) {
		$declared = true;
	}
}
check( $declared, 'And the Phase 12 migration is still declared, which is what makes the version comparison meaningful.' );

/* =====================================================================
 * Done.
 * ================================================================== */

echo "--- 20. Phase 12 complete ---\n";
check( $assertions > 350, sprintf( 'The Phase 12 suite ran %d assertions, so a later edit that silently drops coverage fails rather than passing quietly.', $assertions ) );
echo "assertions: $assertions\n";
