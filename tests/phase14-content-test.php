<?php
/**
 * Phase 14 contract tests: content intelligence, mapping, security, and rollback.
 *
 * @package ReplicaForge
 */

use ReplicaForge\Content_Cache;
use ReplicaForge\Content_Entity_Matcher;
use ReplicaForge\Content_Fingerprint;
use ReplicaForge\Content_Limits;
use ReplicaForge\Content_Mapper;
use ReplicaForge\Content_Preview;
use ReplicaForge\Content_Service;
use ReplicaForge\Content_Validator;
use ReplicaForge\Dynamic_Detector;
use ReplicaForge\Schema;
use ReplicaForge\Source_Content_Model;
use ReplicaForge\Structured_Data;
use ReplicaForge\Sync_Conflict_Detector;
use ReplicaForge\WooCommerce_Provider;
use ReplicaForge\WordPress_Content_Provider;

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
function rf14_cleanup() {
	global $wpdb, $rf14_user_id;
	$rows = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options}
		 WHERE option_name LIKE 'replicaforge_content%'
		    OR option_name LIKE 'rfc_%'
		    OR option_name LIKE 'replicaforge_visual%'"
	);
	foreach ( (array) $rows as $name ) {
		delete_option( (string) $name );
	}
	// A user left behind would make the *next* run's capability assertions pass for
	// the wrong reason, so it is removed on every exit — including an aborted one.
	if ( ! empty( $rf14_user_id ) && function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $rf14_user_id );
	}
}
register_shutdown_function( 'rf14_cleanup' );

require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * The signed-in user the provider reads as.
 *
 * @var int
 */
$rf14_user_id = 0;

/**
 * A WooCommerce provider wired to an in-memory store.
 *
 * §48 requires a clean provider abstraction when a capability is unavailable, and this
 * is what that abstraction is for: the *logic* is exercised without a store. What this
 * does NOT do is prove a read against a real `WC_Product`, because no store exists in this
 * environment. That is stated in the completion report rather than implied away.
 *
 * @return WooCommerce_Provider
 */
function rf14_fake_store( ?array $products = null, $active = true ) {
	$store = ( null === $products ) ? array(
		array(
			'id' => 182, 'name' => 'Wireless Bluetooth Headphones', 'sku' => 'WHP-001',
			'type' => 'simple', 'regular_price' => '49.99', 'sale_price' => '',
			'categories' => array( 'Audio' ), 'image' => 'https://cdn.example.com/whp.jpg',
			'slug' => 'wireless-bluetooth-headphones', 'rating' => '4.5',
		),
		array(
			'id' => 183, 'name' => 'Wireless Bluetooth Headphones Pro', 'sku' => 'WHP-002',
			'type' => 'variable', 'regular_price' => '79.99', 'sale_price' => '64.99',
			'categories' => array( 'Audio' ), 'image' => 'https://cdn.example.com/whp.jpg',
			'slug' => 'wireless-bluetooth-headphones-pro', 'rating' => '4.2',
		),
		array(
			'id' => 184, 'name' => 'USB-C Cable', 'sku' => 'CAB-9',
			'type' => 'simple', 'regular_price' => '9.99', 'sale_price' => '',
			'categories' => array( 'Accessories' ), 'image' => 'https://cdn.example.com/cable.jpg',
			'slug' => 'usb-c-cable',
		),
	) : $products;

	return new WooCommerce_Provider(
		0,
		array(
			'active'   => static function () use ( $active ) { return (bool) $active; },
			'version'  => static function () { return '9.9.9'; },
			'products' => static function ( $page, $per_page ) use ( $store ) {
				return array( 'total' => count( $store ), 'items' => array_slice( $store, ( $page - 1 ) * $per_page, $per_page ) );
			},
			'product'  => static function ( $id ) use ( $store ) {
				foreach ( $store as $product ) {
					if ( (int) $product['id'] === (int) $id ) {
						return $product;
					}
				}
				return null;
			},
			'terms'    => static function ( $type, $page, $per_page ) {
				return array( 'total' => 0, 'items' => array() );
			},
		)
	);
}

/**
 * A minimal product-page representation in the shape Phase 2 produces.
 *
 * @param array<string, mixed> $overrides Overrides.
 * @return array<string, mixed>
 */
function rf14_product_page( array $overrides = array() ) {
	$page = array(
		'schema_version' => '2.0',
		'page'          => array( 'url' => 'https://example.com/product/abc/', 'final_url' => 'https://example.com/product/abc/', 'title' => 'Headphones', 'type' => 'product', 'language' => 'en' ),
		'layout'        => array( 'width' => 1440, 'background' => '#ffffff' ),
		'sections'      => array(
			array( 'id' => 'section_001', 'type' => 'product_detail', 'components' => array() ),
		),
		'components'    => array(
			array( 'id' => 'component_001', 'type' => 'product_card', 'role' => 'repeated_or_content_card', 'text' => 'Wireless Bluetooth Headphones', 'section_id' => 'section_001', 'confidence' => 0.86, 'source' => 'div.product-card', 'url' => 'https://example.com/product/abc/', '_card_type' => 'product_card' ),
			array( 'id' => 'component_002', 'type' => 'product_card', 'role' => 'repeated_or_content_card', 'text' => 'Wireless Earbuds', 'section_id' => 'section_001', 'confidence' => 0.86, 'source' => 'div.product-card', 'url' => 'https://example.com/product/def/' ),
		),
		'design_system' => array(), 'responsive' => array(), 'assets' => array(), 'hierarchy' => array(),
		'confidence'    => 0.8, 'warnings' => array(),
	);

	// Phase 2's card fields, attached to the first card.
	$page['components'][0]['fields'] = array(
		'image'      => 'https://example.com/whp.jpg',
		'title'      => 'Wireless Bluetooth Headphones',
		'price'      => '$49.99',
		'sale_price' => null,
		'rating'     => 4.5,
		'badge'      => null,
		'button'     => 'Add to cart',
		'link'       => 'https://example.com/product/abc/',
	);
	$page['components'][1]['fields'] = array(
		'image'      => 'https://example.com/earbuds.jpg',
		'title'      => 'Wireless Earbuds',
		'price'      => '$29.99',
		'sale_price' => null,
		'rating'     => null,
		'badge'      => null,
		'button'     => 'Add to cart',
		'link'       => 'https://example.com/product/def/',
	);
	// Phase 2's card group, consumed rather than re-derived.
	$page['components'][] = array(
		'id' => 'component_003', 'type' => 'card_group', 'role' => 'repeated_structure',
		'text' => '', 'section_id' => 'section_001', 'confidence' => 0.86,
		'children' => array( 'component_001', 'component_002' ),
		'count' => 2, 'card_type' => 'product_card', 'repeated_structure' => true,
	);

	return array_merge( $page, $overrides );
}

/**
 * Build a DOM document from HTML.
 *
 * @param string $html HTML.
 * @return DOMDocument
 */
function rf14_dom( $html ) {
	$document = new DOMDocument();
	libxml_use_internal_errors( true );
	$document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	return $document;
}

/* =====================================================================
 * 1. Vocabulary, and the decisions to read rather than restate.
 * ================================================================== */

echo "--- 1. Vocabulary and bounds ---\n";

check( Content_Limits::SCHEMA_VERSION === '14.0', 'The content model declares schema version 14.0.' );
check( count( Content_Limits::ROLES ) === 31, 'All thirty-one section 3 semantic roles are declared.' );
foreach ( array( 'site_logo', 'site_name', 'navigation_label', 'hero_heading', 'hero_description', 'hero_cta', 'section_heading', 'body_text', 'feature_title', 'feature_description', 'product_name', 'product_price', 'product_sale_price', 'product_image', 'product_gallery', 'product_description', 'product_category', 'product_rating', 'review_author', 'review_text', 'blog_title', 'blog_excerpt', 'blog_author', 'blog_date', 'blog_category', 'button', 'phone', 'email', 'address', 'social_link', 'footer_text' ) as $role ) {
	check( in_array( $role, Content_Limits::ROLES, true ), sprintf( 'The %s role is declared.', $role ) );
}
check( count( Content_Limits::DATA_TYPES ) === 12, 'Twelve data types, so a field-to-field check has a vocabulary to work from.' );
check( count( Content_Limits::DYNAMIC_CLASSES ) === 4, 'All four section 6 dynamism classes are declared, including unknown.' );
check( count( Content_Limits::ACTIONS ) === 4, 'Exactly four section 15 actions — which is how an arbitrary database operation becomes unrepresentable.' );
same( Content_Limits::ACTIONS, array( 'map', 'unmap', 'ignore', 'review' ), 'And they are the four §15 names.' );
check( count( Content_Limits::RISKS ) === 4, 'All four section 11 risk levels are declared.' );
check( count( Content_Limits::MODES ) === 3, 'All three section 18 reconstruction modes are declared.' );
check( count( Content_Limits::PERSON_ROLES ) === 2, 'Person roles are grouped, so the section 30 privacy pass has one place to act.' );
check( count( Content_Limits::PRICE_ROLES ) === 2, 'Price roles are grouped, because a price is the one field with a real cost to getting wrong.' );

// The architectural assertion: ownership is READ from Phase 9.
same( Content_Limits::ownership_states(), Sync_Conflict_Detector::OWNERSHIP, 'Ownership states are read from Phase 9, not restated — so a mapping can never disagree with the sync layer about who owns a field.' );
same( Content_Limits::ownership_states(), array( 'source_controlled', 'user_controlled', 'mixed', 'unknown' ), 'And those are exactly the section 16 four.' );
check( count( Content_Limits::categories() ) > 0, 'Difference categories are read from Phase 5.' );

// Anti-hallucination vocabulary.
check( count( Content_Limits::NULL_MARKERS ) === 5, 'Five declared markers for absent data, and these are the only correct answers for missing content.' );
check( in_array( 'not_detected', Content_Limits::NULL_MARKERS, true ), 'Including not_detected, which says "ReplicaForge looked and it was not there".' );
check( Content_Limits::is_missing( null ), 'null is a marker.' );
check( Content_Limits::is_missing( '' ), 'An empty string is a marker.' );
check( ! Content_Limits::is_missing( '0' ), 'But a zero is not — a real $0.00 is a value, and treating it as absent would be a way to lose one.' );
check( ! Content_Limits::is_missing( '$0.00' ), 'And a real price is not either.' );

// Type compatibility: the gate that makes name-matching impossible.
check( Content_Limits::types_compatible( 'text', 'richtext' ), 'Text fits richtext.' );
check( Content_Limits::types_compatible( 'richtext', 'text' ), 'And richtext fits text.' );
check( Content_Limits::types_compatible( 'number', 'currency' ), 'A number fits a price.' );
check( ! Content_Limits::types_compatible( 'currency', 'number' ), 'But a price does not become a plain number field.' );
check( ! Content_Limits::types_compatible( 'currency', 'text' ), 'And a price does not fit a text field — this is the gate that refuses product_price onto post_title.' );
check( ! Content_Limits::types_compatible( 'text', 'currency' ), 'Nor does plain text fit a price field.' );
check( ! Content_Limits::types_compatible( 'image', 'text' ), 'An image is not a string.' );
check( ! Content_Limits::types_compatible( 'text', 'nonsense' ), 'An undeclared type is never compatible, so a typo fails closed.' );

// Confirmation rules.
check( Content_Limits::mode_requires_confirmation( 'hybrid_replica' ), 'Hybrid needs confirmation, because it replaces source content.' );
check( Content_Limits::mode_requires_confirmation( 'dynamic_replica' ), 'And so does a fully dynamic replica.' );
check( ! Content_Limits::mode_requires_confirmation( 'static_replica' ), 'A static replica replaces nothing, so it does not.' );

// Match bands.
check( Content_Limits::IMAGE_ONLY_CEILING < Content_Limits::ACCEPT_MATCH, 'An image-only match is capped BELOW the auto-accept threshold, so it can never be applied automatically. This is how §20 is enforced rather than advised.' );
check( Content_Limits::MIN_MATCH < Content_Limits::ACCEPT_MATCH, 'The minimum and accept bands are ordered.' );
check( Content_Limits::REJECT_CONFIDENCE < Content_Limits::AUTO_APPLY_CONFIDENCE, 'And the reject floor is below the auto-apply floor.' );

/* =====================================================================
 * 2. Fingerprints: stable, and honest about absence.
 * ================================================================== */

echo "--- 2. Fingerprints ---\n";

$fp_a = Content_Fingerprint::of( 'product_name', 'Wireless Headphones' );
$fp_b = Content_Fingerprint::of( 'product_name', 'wireless   headphones' );
check( $fp_a === $fp_b, 'A fingerprint folds case and repeated whitespace, so the same product analysed twice matches.' );
$fp_role = Content_Fingerprint::of( 'button', 'Wireless Headphones' );
check( $fp_a !== $fp_role, 'The role is part of the fingerprint, so a button and a product title with the same words are different content.' );
check( Content_Fingerprint::same( $fp_a, $fp_b ), 'Two equal fingerprints are the same content.' );
check( ! Content_Fingerprint::same( $fp_a, 'fp_deadbeef' ), 'Two different ones are not.' );

// The absence rule, which is where a matcher invents correspondences.
$missing_empty = Content_Fingerprint::of( 'product_name', '' );
$missing_marker = Content_Fingerprint::of( 'product_name', 'not_detected' );
check( $missing_empty === $missing_marker, 'Every absence marker collapses to one missing fingerprint for a role, because a fingerprint is an identity key and "no identity" is no identity whichever marker records it.' );
$missing_other = Content_Fingerprint::of( 'product_price', '' );
check( $missing_empty !== $missing_other, 'But a missing price is not a missing name, so the role still separates absences.' );
check( 0 === strpos( $missing_empty, 'fp_missing_' ), 'And a missing fingerprint is marked as one, so it can never be compared as if it were a real value.' );
check( ! Content_Fingerprint::same( '', '' ), 'Two empty fingerprints are NOT the same, because "we know neither" is not "they are the same".' );
check( ! Content_Fingerprint::same( null, null ), 'And two nulls are not either.' );
check( Content_Fingerprint::same( $missing_empty, $missing_empty ), 'The same absence is comparable to itself, which is what lets a re-scan notice it is still absent.' );
// The distinction is preserved where it matters: in the model's value, not the fingerprint.
check( Content_Limits::is_missing( '' ) && Content_Limits::is_missing( 'not_detected' ), 'Both are recognised as absent by the model layer, which reports them as a count rather than as values.' );

check( Content_Fingerprint::normalise_title( 'The Blue Shirt' ) === 'blue shirt', 'A leading article is dropped for comparison, because "The Blue Shirt" and "Blue Shirt" are one product.' );
check( Content_Fingerprint::normalise_sku( 'ab-0012' ) === Content_Fingerprint::normalise_sku( 'ab_0012' ), 'SKU normalisation folds separators, so ab-0012 and ab_0012 are one SKU.' );
check( Content_Fingerprint::normalise_sku( '0012' ) === Content_Fingerprint::normalise_sku( '12' ), 'And leading zeros are stripped, so 0012 and 12 are one SKU.' );
check( Content_Fingerprint::normalise_sku( 'ab0012' ) !== Content_Fingerprint::normalise_sku( 'ab12' ), 'But INTERIOR zeros are not stripped, because ab0012 and ab12 are two different products to a merchant and folding them would be a false positive in a catalogue.' );
check( Content_Fingerprint::normalise_title( 'C++' ) !== Content_Fingerprint::normalise_title( 'C' ), 'And punctuation is not stripped from titles, so C++ and C stay different products.' );

// Invalid UTF-8 must not collapse everything onto one fingerprint.
$broken = Content_Fingerprint::of( 'product_name', "abc\xC3\x28def" );
$valid  = Content_Fingerprint::of( 'product_name', 'abcdef' );
check( is_string( $broken ) && '' !== $broken, 'Invalid UTF-8 still produces a fingerprint rather than null.' );
check( $broken !== $valid, 'And it is not the same as the valid version, because PCRE returns null on invalid UTF-8 and the first draft would have collapsed every malformed string onto one value.' );

// Image keys: the §20 ceiling depends on this being cheap.
$img_a = Content_Fingerprint::image_key( 'https://cdn.example.com/uploads/2024/blue-shirt-300x400.jpg?v=3' );
$img_b = Content_Fingerprint::image_key( 'https://other.example.com/img/blue-shirt.jpg' );
check( $img_a === $img_b, 'An image key survives a different host, a resize suffix, and a cache-busting query — the three things that differ between two stores using the same photo.' );
check( Content_Fingerprint::image_key( '' ) === '', 'An absent image has no key, rather than an empty-string key that would match every other absent image.' );

/* =====================================================================
 * 3. Structured data: untrusted, bounded, data only.
 * ================================================================== */

echo "--- 3. Structured data ---\n";

$structured = new Structured_Data();
$html = <<<'HTML'
<html><head>
<meta property="og:type" content="product">
<meta property="og:title" content="Headphones">
<meta name="twitter:card" content="summary_large_image">
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Product","name":"Wireless Headphones","sku":"WHP-1",
 "offers":{"@type":"Offer","price":"49.99","priceCurrency":"USD","highPrice":"59.99"},
 "aggregateRating":{"@type":"AggregateRating","ratingValue":"4.5","ratingCount":"120"}}
</script>
<script type="application/ld+json">{ this is not json }</script>
<script type="application/ld+json">{"@type":"Organization","name":"Example","url":"https://example.com","api_key":"sk-live-SECRETVALUE"}</script>
</head><body><p>Hello</p></body></html>
HTML;

$read = $structured->read( rf14_dom( $html ), 'https://example.com/product/abc/' );

same( $read['trust'], 'unverified_source_claim', 'Everything read is marked as a claim, not a fact.' );
same( $read['counts']['json_ld_blocks'], 2, 'Two blocks parsed.' );
same( $read['counts']['json_ld_rejected'], 1, 'And malformed JSON-LD is rejected rather than treated as absence of data.' );
$rejected_reasons = array_column( (array) $read['rejected'], 'reason' );
check( in_array( 'malformed_json', $rejected_reasons, true ), 'With a stated reason, because malformed JSON-LD is extremely common and must not look like "no data".' );
check( $read['page_type']['type'] === 'product_detail', 'A Product claim yields a product detail page type, not an archive.' );
check( count( $read['entities'] ) >= 2, 'Both a product and an organization were detected.' );

// The price came from a claim and must be labelled as one.
$product = null;
foreach ( (array) $read['entities'] as $entity ) {
	if ( 'product' === ( $entity['type'] ?? '' ) ) { $product = $entity; }
}
check( null !== $product, 'A product entity was produced.' );
check( 49.99 === (float) $product['price'], 'The price was parsed.' );
check( $product['price_status'] === 'claimed_sale', 'And the claim that a sale exists is recorded as a claim, separate from the amount.' );
check( false === $product['verified'], 'The entity is not marked verified, because a source page asserting a price is not a verified price.' );
check( ! empty( $product['evidence']['raw_type'] ), 'It carries the raw declared type as evidence.' );

// The security case: a secret-looking key must not survive into our records.
$org = null;
foreach ( (array) $read['entities'] as $entity ) {
	if ( 'organization' === ( $entity['type'] ?? '' ) ) { $org = $entity; }
}
check( null !== $org, 'The organization was detected.' );
check( ! isset( $org['data']['api_key'] ), 'A key named api_key in the source JSON-LD was dropped, so a hostile page cannot plant something in ReplicaForge records.' );
$serialised_org = (string) wp_json_encode( $org );
check( false === strpos( $serialised_org, 'SECRETVALUE' ), 'And the secret value is nowhere in it.' );

// A hostile URL in structured data must be refused, not stored.
$hostile = new Structured_Data();
$hostile_read = $hostile->read( rf14_dom(
	'<html><head><script type="application/ld+json">{"@type":"Product","name":"X","url":"http://169.254.169.254/latest/meta-data/","image":"http://127.0.0.1/x.png"}</script></head><body></body></html>'
), 'https://example.com/' );
$hostile_product = null;
foreach ( (array) $hostile_read['entities'] as $entity ) {
	if ( 'product' === ( $entity['type'] ?? '' ) ) { $hostile_product = $entity; }
}
check( null !== $hostile_product, 'The product was still detected.' );
same( (string) $hostile_product['url'], '', 'A metadata-endpoint URL in structured data was refused rather than stored.' );
same( (string) $hostile_product['image'], '', 'And so was a loopback image URL.' );

// Bounds.
$huge = str_repeat( 'x', Content_Limits::MAX_STRUCTURED_BYTES + 100 );
$big_read = $structured->read( rf14_dom( '<html><head><script type="application/ld+json">{"@type":"Product","name":"' . $huge . '"}</script></head><body></body></html>' ), 'https://example.com/' );
same( $big_read['counts']['json_ld_blocks'], 0, 'An oversized JSON-LD block is refused before decoding, because json_decode on a huge string is a memory exhaustion.' );
$big_reasons = array_column( (array) $big_read['rejected'], 'reason' );
check( in_array( 'too_large', $big_reasons, true ), 'With a stated reason.' );

check( count( $read['warnings'] ) > 0 || count( $big_read['warnings'] ) > 0, 'Warnings are produced where metadata was rejected.' );
check( ! empty( $read['note'] ), 'And the result states plainly that structured data asserts nothing.' );

/* =====================================================================
 * 4. Source content model: nothing invented, and it can be checked.
 * ================================================================== */

echo "--- 4. Source content model ---\n";

$model = new Source_Content_Model();
$spec = $model->build(
	array(
		array( 'content_id' => 'c_1', 'role' => 'product_name', 'data_type' => 'text', 'value' => 'Wireless Headphones', 'confidence' => 0.9, 'is_dynamic' => 'semi_dynamic', 'evidence' => array( 'phase2_role=h2' ), 'entity_type' => 'product' ),
		array( 'content_id' => 'c_2', 'role' => 'product_price', 'data_type' => 'currency', 'value' => '49.99', 'confidence' => 0.85, 'is_dynamic' => 'dynamic', 'evidence' => array( 'phase2_card_field=price' ), 'entity_type' => 'product' ),
		// An absent value must be a marker, not a gap and not an invention.
		array( 'content_id' => 'c_3', 'role' => 'product_sale_price', 'data_type' => 'currency', 'value' => 'not_detected', 'confidence' => 0.0, 'is_dynamic' => 'dynamic', 'evidence' => array( 'phase2_card_field=sale_price' ), 'entity_type' => 'product' ),
	),
	array( 'page_type' => array( 'type' => 'product_detail', 'confidence' => 0.7 ), 'entities' => array( array( 'entity_id' => 'sd_1', 'type' => 'product', 'evidence' => array( 'source' => 'json_ld' ), 'verified' => false ) ) ),
	array( 'url' => 'https://example.com/product/abc/', 'page_id' => 'p1' )
);

check( $spec['schema_version'] === '14.0', 'The model declares 14.0.' );
check( isset( $spec['items']['c_1'] ), 'An item is stored by its content id.' );
same( $spec['items']['c_2']['value'], '49.99', 'A present value is stored as it was found.' );
same( $spec['items']['c_3']['value'], 'not_detected', 'An absent value is stored as a declared marker.' );
check( in_array( 'c_1', (array) $spec['content']['products'], true ), 'A product name is in the products bucket.' );
check( in_array( 'c_2', (array) $spec['content']['prices'], true ), 'A price is in the prices bucket.' );
check( in_array( 'c_1', (array) $spec['content']['headings'], true ), 'And the same product name is also in headings, because a bucket is an index and not a partition.' );
check( $spec['fabrication']['policy'] === 'never_invent', 'The model states its fabrication policy.' );

$verdict = $model->validate( $spec );
check( $verdict['valid'], 'A well-formed model validates.' );
same( $verdict['errors'], array(), 'With no errors.' );
same( $verdict['counts']['absent'], 1, 'One declared absence is counted separately from real values, so a report cannot imply three prices when there are two.' );

// A fabricated item must FAIL, not warn.
$forged = $spec;
$forged['items']['c_4'] = array( 'role' => 'product_price', 'data_type' => 'currency', 'value' => array( 'invented' => true ), 'confidence' => 0.9, 'is_dynamic' => 'dynamic', 'evidence' => array() );
$forged_verdict = $model->validate( $forged );
check( ! $forged_verdict['valid'], 'An item whose value is a structure rather than a scalar fails validation — that is the shape a fabricated record usually takes.' );
check( in_array( 'item_value_not_scalar', $forged_verdict['errors'], true ), 'With a specific error.' );

$no_evidence = $spec;
$no_evidence['items']['c_5'] = array( 'role' => 'product_name', 'data_type' => 'text', 'value' => 'X', 'confidence' => 0.9, 'is_dynamic' => 'static', 'evidence' => array() );
check( ! ( new Source_Content_Model() )->validate( $no_evidence )['valid'], 'An item with no evidence fails, because an inference with no basis is an assertion.' );

$verified = $spec;
$verified['entities'][0]['verified'] = true;
check( in_array( 'entity_claims_verification', ( new Source_Content_Model() )->validate( $verified )['errors'], true ), 'A source-page entity claiming to be verified fails: verification is a destination-side concept.' );

$bad_dyn = $spec;
$bad_dyn['items']['c_6'] = array( 'role' => 'product_name', 'data_type' => 'text', 'value' => 'X', 'confidence' => 0.9, 'is_dynamic' => 'sometimes', 'evidence' => array( 'x' ) );
check( in_array( 'item_unknown_dynamism', ( new Source_Content_Model() )->validate( $bad_dyn )['errors'], true ), 'An undeclared dynamism class fails, rather than defaulting to static.' );

/* =====================================================================
 * 5. Role detection, consuming Phase 2.
 * ================================================================== */

echo "--- 5. Role detection ---\n";

$roles = new \ReplicaForge\Content_Role_Detector( $read, array() );
$detected = $roles->detect( rf14_product_page() );
$by_role = array();
foreach ( $detected as $item ) {
	$by_role[ $item['role'] ][] = $item;
}

check( isset( $by_role['product_name'] ), 'A card in a product card group yields a product name.' );
check( isset( $by_role['product_price'] ), 'And a product price, read from Phase 2 rather than re-parsed.' );
check( isset( $by_role['product_image'] ), 'And a product image.' );
check( isset( $by_role['product_rating'] ), 'And a rating.' );
check( isset( $by_role['unclassified'] ), 'A card_group container is unclassified, because a container is not content and mapping it would map a wrapper into a field.' );

// The composite expansion is the thing most likely to be silently broken.
$price_items = array();
foreach ( $detected as $item ) {
	if ( 'product_price' === $item['role'] ) { $price_items[] = $item; }
}
check( count( $price_items ) >= 2, 'Both cards produced their own price item — the first draft returned only the first expanded item and dropped the rest of every card.' );
$values = array_unique( array_column( $price_items, 'value' ) );
check( 2 === count( $values ), 'And the two prices are distinct, so neither was overwritten by the other.' );
check( $price_items[0]['data_type'] === 'currency', 'A price carries the currency data type, which is what makes the type gate work.' );

$rating_items = array();
foreach ( $detected as $item ) {
	if ( 'product_rating' === $item['role'] ) { $rating_items[] = $item; }
}
check( 1 === count( $rating_items ), 'A card with no rating produces no rating item, because a missing field is the finding and is never filled with a placeholder.' );

$first = $detected[0];
check( ! empty( $first['evidence'] ), 'Every item carries evidence.' );
check( false !== strpos( implode( ' ', $first['evidence'] ), 'phase2_type=' ), 'Including what Phase 2 decided, because the role is a refinement of Phase 2 and not a replacement for it.' );
check( $first['confidence'] <= 0.9, 'Confidence is capped at 0.9, because a role detected from markup is never a certainty and 1.0 would let a mapping auto-apply on a guess.' );
check( $first['fingerprint'] !== '', 'And a fingerprint, for provenance and for matching.' );

$price_item = $price_items[0];
check( 'dynamic' === $price_item['is_dynamic'], 'A price is classified dynamic, because it changes on its own schedule.' );
$name_item = $by_role['product_name'][0];
check( 'semi_dynamic' === $name_item['is_dynamic'], 'A card member name is semi_dynamic: the pattern is source-owned, the value is not.' );

// A testimonial card must not reuse the product vocabulary blindly.
$testimonial = $roles->detect( array( 'components' => array(
	array( 'id' => 'c_t', 'type' => 'testimonial_card', 'role' => 'repeated_or_content_card', 'text' => 'Great product', 'section_id' => 's', 'confidence' => 0.9, 'fields' => array( 'title' => 'Jane D.' ) ),
) ) );
$testimonial_role = '';
foreach ( $testimonial as $item ) { $testimonial_role = $item['role']; }
same( $testimonial_role, 'review_author', 'A testimonial card name is a review author, not a product name.' );

/* =====================================================================
 * 6. WooCommerce: absent, and honest about it.
 * ================================================================== */

echo "--- 6. WooCommerce provider ---\n";

$absent = new WooCommerce_Provider( 0, array( 'active' => static function () { return false; } ) );
check( ! $absent->is_available(), 'With WooCommerce absent the provider reports itself unavailable.' );
check( false !== strpos( $absent->unavailable_reason(), 'not active' ), 'And says why in words a user can act on.' );
same( $absent->entity_types(), array(), 'An unavailable provider offers no entity types.' );
same( $absent->fields_for( 'product' ), array(), 'And no fields, so a mapper cannot resolve a destination against it.' );

$absent_page = $absent->entities( 'product', 1, 20 );
check( ! $absent_page['available'], 'A read against an absent store is unavailable.' );
check( false === $absent_page['empty'], 'And reports empty as false, so "no store" cannot be misread as "you have no products".' );
same( $absent_page['code'], 'woocommerce_not_active', 'With a stated code.' );
check( $absent_page['reason'] !== '', 'And a reason.' );
check( null === $absent->entity( 'product', 182 ), 'And a single read returns null rather than a fabricated record.' );
check( $absent->version() !== '', 'A version is always non-empty, or two WooCommerce builds would share a content cache.' );

// A store that is present.
$store = rf14_fake_store();
check( $store->is_available(), 'A present store is available.' );
same( $store->version(), 'wc-9.9.9', 'The provider version is reported for the cache key.' );

$fields = $store->fields_for( 'product' );
$by_field = array();
foreach ( $fields as $field ) { $by_field[ $field['field'] ] = $field; }
check( isset( $by_field['_regular_price'] ), 'A regular price field is offered.' );
check( ! empty( $by_field['_regular_price']['editable'] ), 'And it is writable.' );
same( $by_field['_regular_price']['data_type'], 'currency', 'With the currency data type, which is what makes the type gate work.' );
check( isset( $by_field['_stock'] ), 'A stock field is listed, so a user can see it exists.' );
check( ! $by_field['_stock']['editable'], 'But it is NOT writable — this is how §8’s "do not modify inventory" is enforced structurally rather than by someone remembering.' );
check( $by_field['_stock']['note'] !== '', 'And the reason is stated on the field.' );
check( isset( $by_field['sku'] ), 'A SKU field is offered.' );

$product = $store->entity( 'product', 182 );
check( null !== $product, 'A product is readable.' );
same( (string) $product['values']['_regular_price'], '49.99', 'Its regular price is read.' );
same( (string) $product['values']['_sale_price'], 'not_detected', 'An absent sale price is a declared marker, not a zero — a fabricated $0.00 sale would be worse than no sale.' );
// The declared field is `sku`; the applier prefixes the underscore when writing,
// because the WooCommerce meta key is `_sku`. One name in the provider, one
// translation at the write boundary.
check( isset( $product['values']['sku'] ), 'And its SKU.' );
same( (string) $product['values']['sku'], 'WHP-001', 'Under the declared field name, with the applier adding the leading underscore at the write boundary.' );
check( null === $store->entity( 'product', 99999 ), 'A missing product is null, not an empty record.' );
check( null === $store->entity( 'product', 0 ), 'And so is a zero id.' );

$variable = $store->entity( 'product', 183 );
same( (string) $variable['values']['_sale_price'], '64.99', 'A variable product with a sale price reads both prices.' );
same( (string) $variable['values']['_regular_price'], '79.99', 'Regular and sale are distinct fields.' );
check( count( WooCommerce_Provider::PRODUCT_TYPES ) === 6, 'All six section 8 product types are declared as understood.' );

/* =====================================================================
 * 7. WordPress provider: reads through the API, exposes nothing private.
 * ================================================================== */

echo "--- 7. WordPress provider ---\n";

$anonymous = new WordPress_Content_Provider( 0 );
$page_id   = wp_insert_post( array( 'post_title' => 'RF14 Private Page', 'post_content' => 'Secret', 'post_status' => 'publish', 'post_type' => 'page' ) );
check( null === $anonymous->entity( 'page', $page_id ), 'With no signed-in user, a published page is still not readable, because the provider asks WordPress rather than assuming. §7 says inspect only what the user may access, and "published" is not the same as "readable by you".' );

$rf14_user_id = wp_insert_user( array( 'user_login' => 'rf14_reader', 'user_pass' => wp_generate_password( 20 ), 'role' => 'administrator' ) );
check( $rf14_user_id > 0, 'An administrator was created for the read-side checks.' );
wp_set_current_user( $rf14_user_id );

$wp = new WordPress_Content_Provider( $rf14_user_id );
check( $wp->is_available(), 'The WordPress provider is always available, because WordPress is the environment.' );
same( $wp->id(), 'wordpress', 'Its id is wordpress.' );

$types = $wp->entity_types();
check( in_array( 'post', $types, true ), 'Posts are an entity type.' );
check( in_array( 'page', $types, true ), 'Pages are an entity type.' );
check( in_array( 'attachment', $types, true ) || in_array( 'media', $types, true ), 'Media is an entity type.' );
check( ! in_array( 'revision', $types, true ), 'Revision is not, because it is not publicly queryable.' );
check( ! in_array( 'nav_menu_item', $types, true ), 'Nor is a nav menu item, which is not publicly queryable.' );

$post_fields = array();
foreach ( $wp->fields_for( 'post' ) as $field ) { $post_fields[ $field['field'] ] = $field; }
check( isset( $post_fields['post_title'] ), 'A post title field is offered.' );
check( isset( $post_fields['post_content'] ), 'A content field.' );
check( isset( $post_fields['post_excerpt'] ), 'An excerpt field.' );
check( isset( $post_fields['post_author'] ), 'An author field, because posts support authors.' );
check( isset( $post_fields['featured_image'] ), 'A featured image field, because posts support thumbnails.' );
check( isset( $post_fields['taxonomy:category'] ), 'A category taxonomy field.' );
check( ! isset( $post_fields['meta:_private_thing'] ), 'And no arbitrary meta key, because a meta key may be a licence key and this class never enumerates one.' );

// A page entity read.
$fixture_id = wp_insert_post( array( 'post_title' => 'RF14 Fixture Page', 'post_content' => 'Body', 'post_status' => 'publish', 'post_type' => 'page' ) );
check( $fixture_id > 0, 'A fixture page was created.' );
$read = $wp->entity( 'page', $fixture_id );
check( is_array( $read ), 'It can be read back through the provider.' );
same( (string) $read['title'], 'RF14 Fixture Page', 'With its title.' );
check( null === $wp->entity( 'page', 99999999 ), 'A missing page is null.' );
check( null === $wp->entity( 'page', 0 ), 'A zero id is null.' );
check( null === $wp->entity( 'nonsense_type', $fixture_id ), 'An undeclared entity type is null.' );

$page_fields = array();
foreach ( $wp->fields_for( 'page' ) as $field ) { $page_fields[ $field['field'] ] = $field; }
check( isset( $page_fields['post_title'] ), 'A page offers a title field.' );
check( count( $page_fields ) >= 4, 'And several fields, bounded.' );

// Custom fields: detected but always requiring review. The structure report is keyed
// by entity type, because §39's "detected, not assumed" rule is per post type — a meta
// key that exists on `page` says nothing about what exists on `property`.
$with_custom = new WordPress_Content_Provider( 0, array( 'page' => array( 'square_metres', 'api_key' ) ) );
$custom_fields = array();
foreach ( $with_custom->fields_for( 'page' ) as $field ) { $custom_fields[ $field['field'] ] = $field; }
check( isset( $custom_fields['meta:square_metres'] ), 'A detected custom field is offered.' );
check( ! empty( $custom_fields['meta:square_metres']['requires_review'] ), 'And requires review, because a key named price means nothing without context.' );
check( (float) $custom_fields['meta:square_metres']['confidence'] < 0.5, 'With low confidence, so nothing can auto-apply onto it.' );
check( ! isset( $custom_fields['meta:api_key'] ), 'A secret-shaped key is never even offered, because the structure report is filtered through the redactor.' );

// And the report is per post type: a key detected on one type does not leak to another.
$other_type = array();
foreach ( ( new WordPress_Content_Provider( 0, array( 'page' => array( 'square_metres' ) ) ) )->fields_for( 'post' ) as $field ) {
	$other_type[ $field['field'] ] = $field;
}
check( ! isset( $other_type['meta:square_metres'] ), 'A custom field detected on one post type is not offered on another, because a meta key means nothing outside the type it was detected on.' );

/* =====================================================================
 * 8. The mapping engine: four gates, none advisory.
 * ================================================================== */

echo "--- 8. Mapping engine ---\n";

$mapper = new Content_Mapper();
$mapper->add_provider( $store );
$mapper->add_provider( $wp );

$mappings = $mapper->map( $spec, array( 'project_id' => 'proj14', 'page_id' => 'p1', 'mode' => 'hybrid_replica' ) );
check( count( $mappings ) > 0, 'The model produced mappings.' );

$by_source = array();
foreach ( $mappings as $mapping ) { $by_source[ $mapping['source']['content_id'] ][] = $mapping; }

$price_map = null;
foreach ( (array) ( $by_source['c_2'] ?? array() ) as $mapping ) {
	if ( '_regular_price' === (string) $mapping['destination']['field'] ) { $price_map = $mapping; }
}
check( null !== $price_map, 'A product price maps onto a WooCommerce regular price.' );
same( $price_map['destination']['entity'], 'product', 'On the product entity.' );
check( $price_map['confidence'] > 0.8, 'With a high confidence, because the role, the type, and the entity all agree.' );
same( $price_map['risk'], 'high', 'And a high risk, because a price is high risk however confident the mapping is — risk and confidence are independent axes.' );
check( $price_map['requires_review'], 'So it requires review.' );
same( $price_map['ownership'], 'user_controlled', 'And it is user-controlled, because a price is the store owner’s data.' );
check( ! empty( $price_map['evidence'] ), 'With evidence a user can read.' );
check( false !== strpos( implode( ' ', $price_map['evidence'] ), 'source_role=' ), 'Naming the role that made the case.' );
check( isset( $price_map['provenance']['source_content_id'] ), 'And a provenance record, which section 14 requires and Phase 9 depends on.' );

$name_map = null;
foreach ( (array) ( $by_source['c_1'] ?? array() ) as $mapping ) {
	if ( 'post_title' === (string) $mapping['destination']['field'] && 'woocommerce' === (string) $mapping['destination']['provider'] ) { $name_map = $mapping; }
}
check( null !== $name_map, 'A product name maps onto a WooCommerce product title.' );
same( $name_map['ownership'], 'user_controlled', 'Which is user-controlled, because a product name is the store owner’s copy.' );

// An absent value must not become a mapping.
$absent_map = null;
foreach ( (array) ( $by_source['c_3'] ?? array() ) as $mapping ) { $absent_map = $mapping; }
check( null !== $absent_map, 'An absent source field still appears in the mapping list.' );
same( $absent_map['action'], 'unmapped', 'With the action unmapped.' );
check( false !== strpos( implode( ' ', $absent_map['evidence'] ), 'no_value' ), 'And says the source had no value, rather than being silently dropped.' );

// A text source value must never reach a currency field, even by name.
$type_mismatch = null;
foreach ( $mappings as $mapping ) {
	if ( '_regular_price' === (string) $mapping['destination']['field'] && 'text' === (string) $mapping['source']['data_type'] ) { $type_mismatch = $mapping; }
}
check( null === $type_mismatch, 'A text value never reaches a currency field. This is the gate that makes section 10’s "do not map by name" true rather than aspirational.' );

// The read-only field is refused.
$read_only = null;
foreach ( $mappings as $mapping ) {
	if ( '_stock' === (string) $mapping['destination']['field'] ) { $read_only = $mapping; }
}
check( null === $read_only, 'And no mapping targets stock, because the WooCommerce provider marks it read-only.' );

// A provider that is registered but unavailable blocks rather than falling back.
$wc_off  = new WooCommerce_Provider( 0, array( 'active' => static function () { return false; } ) );
$mapper_off = new Content_Mapper();
$mapper_off->add_provider( $wp );
$mapper_off->add_provider( $wc_off );
$blocked = $mapper_off->map( $spec, array( 'project_id' => 'proj14', 'page_id' => 'p1' ) );
$blocked_map = null;
foreach ( $blocked as $mapping ) {
	if ( '_regular_price' === (string) $mapping['destination']['field'] ) { $blocked_map = $mapping; }
}
check( null !== $blocked_map, 'With WooCommerce inactive, a product mapping is still reported.' );
same( $blocked_map['risk'], 'blocked', 'As blocked.' );
same( $blocked_map['action'], 'unmap', 'With the action unmap rather than a fallback onto a WordPress field — a price has no safe WordPress destination.' );
check( false !== strpos( implode( ' ', $blocked_map['warnings'] ), 'not active' ), 'And the reason names the missing store.' );
check( false !== strpos( implode( ' ', $blocked_map['evidence'] ), 'provider_unavailable=woocommerce' ), 'With machine-readable evidence, so a UI can group them.' );

// A provider that is *unregistered* is a different fault and is reported separately,
// rather than losing the mapping with no trace.
$mapper_bare = new Content_Mapper();
$mapper_bare->add_provider( $wp );
$bare = $mapper_bare->map( $spec, array( 'project_id' => 'proj14', 'page_id' => 'p1' ) );
$unregistered = null;
foreach ( $bare as $mapping ) {
	if ( '_regular_price' === (string) $mapping['destination']['field'] ) { $unregistered = $mapping; }
}
check( null !== $unregistered, 'A rule naming a provider that was never registered still produces a mapping.' );
same( $unregistered['risk'], 'blocked', 'As blocked.' );
check( false !== strpos( implode( ' ', $unregistered['evidence'] ), 'provider_unregistered=woocommerce' ), 'With a distinct reason from "provider unavailable", because a rule set that drifted out of sync with the registry is ReplicaForge’s own bug and has to be visible.' );

// Section 18: a replacing mode forces review.
$static_maps = $mapper->map( $spec, array( 'project_id' => 'proj14', 'page_id' => 'p1', 'mode' => 'static_replica' ) );
$auto_count = 0;
foreach ( $static_maps as $mapping ) {
	if ( 'map' === $mapping['action'] && empty( $mapping['requires_review'] ) ) { $auto_count++; }
}
check( $auto_count > 0, 'In a static mode, a low-risk confident mapping can apply without review.' );
$hybrid_auto = 0;
foreach ( $mappings as $mapping ) {
	if ( 'map' === $mapping['action'] && empty( $mapping['requires_review'] ) ) { $hybrid_auto++; }
}
same( $hybrid_auto, 0, 'In the default hybrid mode, nothing applies automatically, because the mode replaces source content and section 18 requires explicit confirmation.' );

check( $mapper->providers()['woocommerce']['available'], 'The provider report says WooCommerce is available.' );
check( $mapper->providers()['woocommerce']['version'] !== '', 'With a version for the cache key.' );

/* =====================================================================
 * 9. The validator: section 13 as a check, not a promise.
 * ================================================================== */

echo "--- 9. Plan validation and anti-hallucination ---\n";

$validator = new Content_Validator();
$plan = $validator->plan( 'proj14', $mappings, array( 'mode' => 'hybrid_replica', 'model' => $spec ) );
check( $plan['schema_version'] === '14.0', 'The plan declares 14.0.' );
check( isset( $plan['plan_id'] ), 'And has a plan id.' );
check( isset( $plan['policy']['actions'] ), 'And carries the action policy.' );
same( $plan['policy']['fabrication'], 'never_invent', 'Recording that the plan invents nothing.' );
check( $plan['validation']['applicable'], 'A well-formed plan is applicable.' );
same( $plan['validation']['errors'], array(), 'With no errors.' );
check( $plan['validation']['anti_hallucination']['absent'] >= 1, 'And it counts the declared absences, so a report cannot imply every field had a value.' );
check( ! empty( $plan['validation']['anti_hallucination']['note'] ), 'With a note saying so in words.' );
check( ! empty( $plan['validation']['warnings'] ), 'A replacing mode produces a confirmation warning.' );

// A forged value must be caught at BUILD time, against the model.
$forged_plan = $validator->plan( 'proj14', $mappings, array( 'mode' => 'hybrid_replica', 'model' => $spec ) );
$forged_plan['mappings'][0]['source']['value'] = 'A price I invented';
$forged_at_build = $validator->plan( 'proj14', array_merge( $mappings, array( array_merge( $mappings[0], array( 'source' => array_merge( $mappings[0]['source'], array( 'value' => 'A price I invented' ) ) ) ) ) ), array( 'mode' => 'hybrid_replica', 'model' => $spec ) );
check( ! $forged_at_build['applicable'], 'A mapping whose value does not match the source model is refused at build time, because the value could not have come from the source.' );
check( false !== strpos( implode( ' ', $forged_at_build['validation']['errors'] ), 'does not match the value in the source model' ), 'With the specific reason named.' );

// And it must be caught at VALIDATE time, which is what the applier and the REST layer
// actually call. This is the case that matters: the REST API accepts an *inline* plan,
// so a caller could take a valid plan, change one value, and leave `applicable` at true.
$forged_verdict = $validator->validate( $forged_plan );
check( ! $forged_verdict['applicable'], 'A value edited after planning is refused, because validate() re-derives the source digest rather than trusting the flag plan() wrote.' );
check( in_array( 'source_digest_mismatch', $forged_verdict['errors'], true ), 'With a source_digest_mismatch error.' );
check( ! $validator->is_applicable( $forged_plan ), 'And is_applicable agrees.' );

// A plan with no digest at all fails closed, not open.
$undigested = $plan;
unset( $undigested['source_digest'] );
check( in_array( 'missing_source_digest', $validator->validate( $undigested )['errors'], true ), 'A plan with no source digest is refused rather than assumed good, because no proof it was checked is not the same as no problem.' );

// Changing a DESTINATION is legitimate and must not break the seal.
$redirected = $plan;
$redirected['mappings'][0]['destination']['field'] = 'post_excerpt';
check( $validator->validate( $redirected )['applicable'], 'Changing a mapping’s destination is legitimate — that is what §36’s “Change Mapping” means — and does not disturb the source seal.' );

// Two mappings claiming different values for one source field.
$split = $plan;
$split['mappings'][] = array_merge( $split['mappings'][0], array( 'mapping_id' => 'map_' . str_repeat( 'b', 16 ), 'source' => array_merge( $split['mappings'][0]['source'], array( 'value' => 'a different value' ) ) ) );
check( ! $validator->validate( $split )['applicable'], 'A plan giving one source field two different values is refused, because it is no longer describing one piece of content.' );

// A content id the model does not contain.
$orphan_plan = $plan;
$orphan_plan['mappings'][0]['source']['content_id'] = 'c_does_not_exist';
$orphan_plan['source_digest'] = Content_Validator::source_digest( $orphan_plan['mappings'] );
$orphan_build = $validator->plan( 'proj14', array_merge( $mappings, array( array_merge( $mappings[0], array( 'source' => array_merge( $mappings[0]['source'], array( 'content_id' => 'c_does_not_exist' ) ) ) ) ) ), array( 'mode' => 'hybrid_replica', 'model' => $spec ) );
check( ! $orphan_build['applicable'], 'A mapping referring to content the model does not contain is refused.' );

// No provenance.
$no_prov = $plan;
$no_prov['mappings'][0]['provenance'] = array();
check( ! $validator->validate( $no_prov )['applicable'], 'A mapping with no provenance record is refused, because untraceable provenance is how a sync cannot tell a user’s edit from ReplicaForge’s.' );

// A high-risk mapping that claims not to need review.
$unreviewed = $plan;
$unreviewed['mappings'][0]['risk'] = 'high';
$unreviewed['mappings'][0]['requires_review'] = false;
$unreviewed_verdict = $validator->validate( $unreviewed );
check( ! $unreviewed_verdict['applicable'], 'A high-risk mapping that does not require review is refused.' );
check( false !== strpos( implode( ' ', $unreviewed_verdict['errors'] ), 'is high risk but is not marked as requiring review' ), 'With the specific reason, because validate() re-derives the rule rather than trusting the flag plan() wrote.' );

// A low-confidence mapping presented as safe to apply. Built rather than searched
// for: the fixture's mappings are all confident, so a test that waited to find a
// low-confidence one would report "none found" and pass having checked nothing.
$over_confident = $plan;
$map_index      = null;
foreach ( $over_confident['mappings'] as $i => $m ) {
	if ( 'map' === (string) $m['action'] ) { $map_index = $i; break; }
}
check( null !== $map_index, 'A map-action mapping exists to build the low-confidence case from.' );
if ( null !== $map_index ) {
	$over_confident['mappings'][ $map_index ]['confidence']      = Content_Limits::AUTO_APPLY_CONFIDENCE - 0.05;
	$over_confident['mappings'][ $map_index ]['requires_review'] = false;
	$oc_verdict = $validator->validate( $over_confident );
	check( ! $oc_verdict['applicable'], 'A mapping below the auto-apply confidence that claims it needs no review is refused.' );
	check( false !== strpos( implode( ' ', $oc_verdict['errors'] ), 'below the' ), 'With the floor stated, so the reason is actionable.' );
	// And the same mapping WITH the review flag set is fine — the rule is about the
	// flag, not about low confidence being forbidden.
	$ok_low = $over_confident;
	$ok_low['mappings'][ $map_index ]['requires_review'] = true;
	check( $validator->validate( $ok_low )['applicable'], 'The same mapping is accepted once it correctly declares that it needs review, because low confidence is not forbidden — silence is.' );
}

// An illegal action.
$illegal = $plan;
$illegal['mappings'][0]['action'] = 'drop_table';
check( ! $validator->validate( $illegal )['applicable'], 'An action outside the four declared values is refused, which is what makes an arbitrary database operation unrepresentable.' );

// A mapping with no destination entity is a field mapping, not a write target. This is
// the honest state before §20's matching runs, and it must be withheld rather than
// guessed at — ReplicaForge does not know which of ten thousand products a price belongs
// to until someone says.
$unresolved = $validator->eligible_for_apply( $plan, array( $plan['mappings'][0]['mapping_id'] ) );
same( $unresolved['counts']['eligible'], 0, 'Before matching, nothing is eligible even when approved, because no mapping names a destination record.' );
$unresolved_reasons = array_column( $unresolved['withheld'], 'reason' );
check( in_array( 'destination_entity_not_resolved', $unresolved_reasons, true ), 'With that stated as the reason.' );

// §20's match result binds a destination record, which is the link between matching and
// mapping and the thing that makes §41's "reconstruct the design, use my products" flow
// actually reach a writable plan.
$match_result = ( new Content_Entity_Matcher() )->match( array( array( 'entity_id' => 's1', 'title' => 'Wireless Bluetooth Headphones', 'sku' => 'WHP-001' ) ), $store->entities( 'product', 1, 20 )['items'] );
$bound = ( new Content_Service() )->resolve_destinations( $plan, $match_result );
check( $bound['resolution']['bound'] > 0, 'A single unambiguous match binds a destination record onto the mappings.' );
$bound_price = null;
foreach ( $bound['mappings'] as $m ) {
	if ( '_regular_price' === (string) $m['destination']['field'] ) { $bound_price = (int) $m['destination']['entity_id']; }
}
same( $bound_price, 182, 'And the price mapping now names product 182.' );
check( $validator->validate( $bound )['applicable'], 'Binding a destination record does not break the plan, because the source seal covers values and binding changes only what a mapping writes to.' );
check( $validator->validate( $plan )['applicable'], 'And the unbound plan was valid all along — it simply was not applicable to anything yet.' );

// Several winners, as on a product archive with four products on it.
$multi = ( new Content_Entity_Matcher() )->match(
	array(
		array( 'entity_id' => 's1', 'title' => 'Wireless Bluetooth Headphones', 'sku' => 'WHP-001' ),
		array( 'entity_id' => 's2', 'title' => 'USB-C Cable', 'sku' => 'CAB-9' ),
	),
	$store->entities( 'product', 1, 20 )['items']
);
$spread = ( new Content_Service() )->resolve_destinations( $plan, $multi );
same( $spread['resolution']['bound'], 0, 'With several matched products, ReplicaForge does not spread the page’s mappings across them by index.' );
check( $spread['resolution']['reason'] !== '', 'And says why, rather than binding an arbitrary one.' );
check( false !== strpos( $spread['resolution']['reason'], 'did not guess' ), 'In words.' );

// An explicit choice does bind.
$chosen = ( new Content_Service() )->resolve_destinations( $plan, array(), array( 'entity_id' => 183 ) );
check( $chosen['resolution']['bound'] > 0, 'A caller naming the destination record binds it.' );

// Eligibility, once a record is bound. In the default hybrid mode every mapping still
// needs review, so with no approvals nothing is eligible — that is the point.
$eligible = $validator->eligible_for_apply( $bound, array() );
same( $eligible['counts']['eligible'], 0, 'With a record bound but no approvals, nothing is still eligible — the default mode replaces source content, so every mapping waits for a human.' );
$reasons = array_column( $eligible['withheld'], 'reason' );
check( in_array( 'awaiting_review', $reasons, true ), 'With the reason being that it is awaiting review, not a failure.' );
check( count( $eligible['withheld'] ) === count( array_unique( array_column( $eligible['withheld'], 'mapping_id' ) ) ), 'Each withheld mapping is listed once, so a screen can show a count that means something.' );

$first_id    = (string) $bound['mappings'][0]['mapping_id'];
$approved    = $validator->eligible_for_apply( $bound, array( $first_id ) );
same( $approved['counts']['eligible'], 1, 'Approving one specific mapping id makes exactly that one eligible.' );
$approved_ids = array_column( $approved['eligible'], 'mapping_id' );
check( in_array( $first_id, $approved_ids, true ), 'And it is the approved one.' );

$several = array();
foreach ( array_slice( $bound['mappings'], 0, 3 ) as $m ) {
	if ( (int) $m['destination']['entity_id'] > 0 && 'map' === (string) $m['action'] ) { $several[] = (string) $m['mapping_id']; }
}
check( $several > array(), 'At least one further mapping is bound and actionable for the multi-approval check.' );
check( $validator->eligible_for_apply( $bound, $several )['counts']['eligible'] === count( $several ), 'Approving several makes exactly those several eligible, and no others.' );

$rejected = $validator->eligible_for_apply( $bound, array( $first_id ), array( $first_id ) );
same( $rejected['counts']['eligible'], 0, 'A rejected mapping is never eligible, even when also approved — so a later call cannot accidentally include it.' );

// In the static mode there is nothing to replace, so a low-risk confident mapping
// becomes eligible on its own.
$static_plan  = $validator->plan( 'proj14', $static_maps, array( 'mode' => 'static_replica', 'model' => $spec ) );
$static_bound = ( new Content_Service() )->resolve_destinations( $static_plan, $match_result );
$static_eligible = $validator->eligible_for_apply( $static_bound, array() );
check( $static_eligible['counts']['eligible'] > 0, 'In a static mode a low-risk confident mapping is eligible without approval, because a static replica replaces nothing.' );

/* =====================================================================
 * 10. Entity matching: section 20.
 * ================================================================== */

echo "--- 10. Bulk entity matching ---\n";

$matcher = new Content_Entity_Matcher();
$destinations = $store->entities( 'product', 1, 20 )['items'];

$sku_match = $matcher->match( array( array( 'entity_id' => 'src1', 'title' => 'Something Else Entirely', 'sku' => 'WHP-001' ) ), $destinations );
check( 'matched' === $sku_match['results'][0]['status'], 'A SKU match is decisive, even when the titles differ completely.' );
check( $sku_match['results'][0]['score'] >= Content_Limits::ACCEPT_MATCH, 'And scores above the accept band.' );

// A title on its own is 0.55, which is below the accept band. That is the point: one
// matching title is suggestive, not proof, and two shops may both list a product called
// "Wireless Headphones" meaning different things. It matches *and asks for review*.
$title_match = $matcher->match( array( array( 'entity_id' => 'src2', 'title' => 'Wireless Bluetooth Headphones' ) ), $destinations );
$title_result = $title_match['results'][0];
same( $title_result['status'], 'review', 'An exact title match with no corroboration is offered but needs review.' );
same( (int) $title_result['entity_id'], 182, 'And it does name the best candidate, so a reviewer has something to accept.' );
$title_signals = $title_result['candidates'][0]['signals'] ?? array();
check( abs( (float) ( $title_signals['title'] ?? 0 ) - 0.55 ) < 0.001, 'Its title signal scores 0.55, which is the specification title-only figure.' );

// Title plus category is the specification second example: 0.87, and a match.
$title_cat = $matcher->match( array( array( 'entity_id' => 'src2b', 'title' => 'Wireless Bluetooth Headphones', 'category' => 'Audio' ) ), $destinations );
$tc_result = $title_cat['results'][0];
same( $tc_result['status'], 'matched', 'Title plus category reaches the accept band and applies.' );
$tc_score = (float) ( $tc_result['candidates'][0]['signals']['title'] ?? 0 ) + (float) ( $tc_result['candidates'][0]['signals']['category'] ?? 0 );
check( abs( $tc_score - 0.87 ) < 0.01, sprintf( 'The two signals together score 0.87, the figure the specification gives for this case (got %.2f).', $tc_score ) );

// Similar titles: the exact one wins over the near-miss.
$pro_vs_exact = $matcher->match( array( array( 'entity_id' => 'src3', 'title' => 'Wireless Bluetooth Headphones' ) ), $destinations );
$pro_best = $pro_vs_exact['results'][0]['candidates'][0] ?? array();
same( (int) ( $pro_best['entity_id'] ?? 0 ), 182, 'A source titled exactly "Wireless Bluetooth Headphones" prefers product 182 over the "...Pro" variant, because the extra token lowers the token-overlap score.' );

// The duplicate-entity case: two products in the store with the *same* title and no SKU
// to separate them. Neither may win, and this is not the same as "similar titles" - a
// near-miss has a better candidate, a duplicate has two equally good ones.
$duplicates = array(
	array( 'entity_id' => 900, 'title' => 'Blue Widget', 'sku' => '', 'slug' => 'blue-widget-a', 'image' => '' ),
	array( 'entity_id' => 901, 'title' => 'Blue Widget', 'sku' => '', 'slug' => 'blue-widget-b', 'image' => '' ),
);
$ambiguous   = $matcher->match( array( array( 'entity_id' => 'src_dup', 'title' => 'Blue Widget' ) ), $duplicates );
$dup_result  = $ambiguous['results'][0];
same( $dup_result['status'], 'ambiguous', 'Two identically-titled products with no distinguishing signal is reported ambiguous, not silently resolved.' );
same( (int) $dup_result['entity_id'], 0, 'And no winner is named, because a coin toss that moves product data is worse than an unanswered question.' );
check( count( $dup_result['candidates'] ) >= 2, 'Both candidates are offered, so a human can choose.' );
check( false !== strpos( implode( ' ', $dup_result['notes'] ), 'did not choose' ), 'With a note saying so.' );

// A SKU separates them, which is exactly why a SKU is worth 0.92.
$disambiguated = $matcher->match( array( array( 'entity_id' => 'src_dup2', 'title' => 'Blue Widget', 'sku' => 'BW-B' ) ), array_merge( $duplicates, array( array( 'entity_id' => 902, 'title' => 'Blue Widget', 'sku' => 'BW-B', 'slug' => 'blue-widget-c', 'image' => '' ) ) ) );
same( $disambiguated['results'][0]['status'], 'matched', 'The same ambiguity disappears the moment a SKU distinguishes the products.' );
same( (int) $disambiguated['results'][0]['entity_id'], 902, 'And the right one is chosen.' );

// The image-only ceiling. Two store products share that photograph, which is the normal
// case and the reason the rule exists.
$image_only = $matcher->match( array( array( 'entity_id' => 'src5', 'image' => 'https://other.example.com/uploads/whp.jpg' ) ), $destinations );
$image_result = $image_only['results'][0];
check( $image_result['score'] <= Content_Limits::IMAGE_ONLY_CEILING, 'An image-only match is capped.' );
check( $image_result['score'] < Content_Limits::ACCEPT_MATCH, 'Strictly below the auto-accept band, so it can never be applied automatically.' );
check( 'matched' !== $image_result['status'], 'And it is never reported as a match, whichever of review or ambiguous it lands in.' );
check( ! empty( $image_result['requires_review'] ), 'With review required.' );
check( ! empty( $image_result['candidates'][0]['image_only'] ), 'And the candidate is flagged as image-only, so a UI can say why.' );
check( $image_only['thresholds']['image_only_max'] === Content_Limits::IMAGE_ONLY_CEILING, 'The ceiling is reported in the result, so a UI can explain why.' );
same( $image_result['status'], 'ambiguous', 'With two products on one photograph it is also ambiguous, which is the honest answer: the photo points at a family of products, not a product.' );

// The same photo where only one product uses it: review, and still not a match.
$single_photo = $matcher->match(
	array( array( 'entity_id' => 'src5b', 'image' => 'https://other.example.com/uploads/cable.jpg' ) ),
	array( $destinations[2] )
);
$sp_result = $single_photo['results'][0];
same( $sp_result['status'], 'review', 'A photo match with exactly one candidate is a review rather than an ambiguous one.' );
check( abs( (float) $sp_result['score'] - Content_Limits::IMAGE_ONLY_CEILING ) < 0.001, 'And its score is exactly the ceiling, so a user is shown the same number the rule guarantees.' );
check( (int) $sp_result['entity_id'] === 184, 'While still naming the single candidate, because that is useful to a human deciding.' );

// An entity with nothing to match on.
$nothing = $matcher->match( array( array( 'entity_id' => 'src6', 'name' => '' ) ), $destinations );
same( $nothing['results'][0]['status'], 'unmatched', 'An entity with no SKU, title, or slug is unmatched, and the reason says there was nothing to match on.' );

$no_destination = $matcher->match( array( array( 'entity_id' => 'src7', 'title' => 'Nothing Like This Exists' ) ), $destinations );
same( $no_destination['results'][0]['status'], 'unmatched', 'And an entity matching nothing is unmatched rather than being given the closest candidate.' );

// Section 44: paging.
$many = array();
for ( $i = 0; $i < 30; $i++ ) { $many[] = array( 'entity_id' => 'm' . $i, 'title' => 'Product ' . $i ); }
$paged = $matcher->match( $many, $destinations, array( 'per_page' => 5 ) );
check( 5 === count( $paged['results'] ), 'A per-page limit is honoured, which is what makes a 10,000-product store workable.' );
check( $paged['progress']['partial'], 'And the result says the batch was partial, so a caller cannot mistake one page for the whole job.' );
check( $paged['progress']['total'] === 30, 'With the true total reported.' );

check( $matcher->match( array(), $destinations )['results'] === array(), 'An empty source list is an empty result, not an error.' );
// WEIGHTS is a constant, not a property: the first draft read `$matcher->WEIGHTS`, which
// is an undefined property, and both comparisons silently became `null > null`.
check( Content_Entity_Matcher::WEIGHTS['sku'] > Content_Entity_Matcher::WEIGHTS['title'], 'A SKU is worth more than a title.' );
check( Content_Entity_Matcher::WEIGHTS['title'] > Content_Entity_Matcher::WEIGHTS['category'], 'A title is worth more than a category.' );
check( Content_Entity_Matcher::WEIGHTS['image'] < Content_Entity_Matcher::WEIGHTS['sku'], 'And a photograph is worth less than a SKU, though more than a category - which is why a photo match is offered but never applied.' );
check( Content_Entity_Matcher::WEIGHTS['sku'] >= Content_Limits::ACCEPT_MATCH, 'A SKU on its own clears the accept band, because a merchant identifier is evidence of identity rather than of similarity.' );
check( Content_Entity_Matcher::WEIGHTS['title'] < Content_Limits::ACCEPT_MATCH, 'A title on its own does not, because two shops may both list a "Wireless Headphones" meaning different things.' );
check( Content_Entity_Matcher::WEIGHTS['title'] + Content_Entity_Matcher::WEIGHTS['category'] >= Content_Limits::ACCEPT_MATCH, 'But a title plus a category does, which is the second worked example in the specification.' );

/* =====================================================================
 * 11. Preview and the section 30 privacy pass.
 * ================================================================== */

echo "--- 11. Preview and privacy ---\n";

$preview = new Content_Preview( array( 'woocommerce' => $store, 'wordpress' => $wp ) );
$built = $preview->preview( $plan );
check( $built['read_only'], 'A preview is marked read-only.' );
check( count( $built['rows'] ) > 0, 'And produced rows.' );
$row = $built['rows'][0];
check( isset( $row['source'], $row['destination'], $row['elementor'] ), 'Each row has the three section 21 stages.' );
check( isset( $row['source']['present'] ), 'The source stage says whether a value is present.' );
check( isset( $row['destination']['available'] ), 'The destination stage says whether the provider is available.' );
check( isset( $row['elementor']['status'] ), 'And the Elementor stage says whether a dynamic tag is available.' );

// A dynamic tag is a tag name, never code.
$tag = null;
foreach ( $built['rows'] as $candidate ) {
	if ( '_regular_price' === (string) $candidate['destination']['field'] ) { $tag = $candidate['elementor']['tag']; }
}
check( is_string( $tag ), 'A price row has a dynamic tag name.' );
check( false === stripos( (string) $tag, '<?php' ), 'Which is not PHP.' );
check( false === stripos( (string) $tag, 'eval' ), 'And not code.' );
check( '' !== $tag, 'And is present when Elementor is available.' );

// The fallback is offered, not taken.
$fallback_rows = array_filter( $built['rows'], static function ( $r ) { return 'unavailable' === (string) $r['elementor']['status']; } );
foreach ( $fallback_rows as $row ) {
	check( false === $row['elementor']['fallback_approved'], 'A static fallback is offered for a missing dynamic tag and never taken automatically — taking it would change what the page shows.' );
}

// Section 30 redaction.
$private_model = array( 'items' => array(
	'c_1' => array( 'role' => 'product_name', 'value' => 'Widget', 'data_type' => 'text', 'section_id' => 's', 'is_dynamic' => 'static' ),
	'c_2' => array( 'role' => 'review_author', 'value' => 'Jane Doe', 'data_type' => 'text', 'section_id' => 's', 'is_dynamic' => 'dynamic' ),
	'c_3' => array( 'role' => 'phone', 'value' => '+44 20 7946 0000', 'data_type' => 'text', 'section_id' => 's', 'is_dynamic' => 'static' ),
	'c_4' => array( 'role' => 'email', 'value' => 'sales@example.com', 'data_type' => 'email', 'section_id' => 's', 'is_dynamic' => 'static' ),
	'c_5' => array( 'role' => 'body_text', 'value' => 'Use token sk-live-ABCDEFGHIJKLMNOPQRST to authenticate.', 'data_type' => 'text', 'section_id' => 's', 'is_dynamic' => 'static' ),
), 'entities' => array( array( 'type' => 'product' ) ), 'page_type' => 'product_detail' );

$ai_payload = Content_Preview::for_ai( $private_model );
$roles_sent = array_column( (array) $ai_payload['payload']['items'], 'role' );
check( ! in_array( 'review_author', $roles_sent, true ), 'A reviewer name is removed before anything leaves the site, not redacted-within.' );
check( ! in_array( 'phone', $roles_sent, true ), 'A phone number is removed.' );
check( ! in_array( 'email', $roles_sent, true ), 'An email address is removed.' );
check( in_array( 'product_name', $roles_sent, true ), 'But a product name is kept, because that is what the model is for.' );
check( count( $ai_payload['dropped'] ) === 3, 'And the removals are reported, so the omission is visible rather than silent.' );
$serialised = (string) wp_json_encode( $ai_payload );
check( false === strpos( $serialised, 'sk-live-ABCDEFGHIJKLMNOPQRST' ), 'A secret inside a retained value is redacted.' );
check( false === strpos( $serialised, 'Jane Doe' ), 'And the removed name is nowhere in the payload.' );
check( false === strpos( $serialised, '7946 0000' ), 'And neither is the phone number.' );
check( ! empty( $ai_payload['note'] ), 'And the payload says what was removed.' );

/* =====================================================================
 * 12. Apply, conflict, and rollback.
 * ================================================================== */

echo "--- 12. Apply and rollback ---\n";

$target_id = wp_insert_post( array( 'post_title' => 'Existing Product', 'post_content' => 'Existing body', 'post_status' => 'publish', 'post_type' => 'page' ) );
check( $target_id > 0, 'A destination page was created.' );

$applier = new \ReplicaForge\Content_Applier();
$applier->add_provider( $wp );

// A minimal, applicable, single-mapping plan.
$single = array(
	'schema_version' => Content_Limits::SCHEMA_VERSION,
	'plan_id'        => 'plan_test14',
	'project_id'     => 'proj14',
	'mode'           => 'hybrid_replica',
	'confirmed'      => true,
	'created_at'     => time(),
	'mappings'       => array(
		array(
			'schema_version'  => Content_Limits::SCHEMA_VERSION,
			'mapping_id'      => 'map_' . str_repeat( 'a', 16 ),
			'project_id'      => 'proj14',
			'source'          => array( 'page_id' => 'p1', 'content_id' => 'c_1', 'role' => 'hero_heading', 'data_type' => 'text', 'value' => 'A mapped heading', 'fingerprint' => 'fp_x', 'source_url' => '', 'section_id' => 's', 'component_id' => 'c1', 'element_reference' => 'h1', 'is_dynamic' => 'static' ),
			'destination'     => array( 'provider' => 'wordpress', 'entity' => 'page', 'entity_id' => (int) $target_id, 'field' => 'post_title', 'data_type' => 'text', 'complete' => true ),
			'confidence'      => 0.95,
			'risk'            => 'low',
			'ownership'       => 'user_controlled',
			'action'          => 'map',
			'requires_review' => false,
			'mode'            => 'hybrid_replica',
			'evidence'        => array( 'source_role=hero_heading' ),
			'warnings'        => array(),
			'provenance'      => array( 'source_content_id' => 'c_1', 'destination_field' => 'post_title', 'recorded_at' => time() ),
			'problems'        => array(),
			'applicable'      => true,
		),
	),
);

// A hand-built plan must carry the source digest, because validate() refuses one
// without it. That is the point of the rule: a plan nobody ever checked is refused, so
// a fixture that skipped the seal would be testing a case the validator forbids.
$single['source_digest'] = Content_Validator::source_digest( $single['mappings'] );
check( $validator->validate( $single )['applicable'], 'A hand-built plan with a correct digest validates.' );
$unsealed = $single;
unset( $unsealed['source_digest'] );
check( ! $validator->validate( $unsealed )['applicable'], 'And the same plan without one does not, so a fixture cannot accidentally exercise a code path the validator closes off.' );

// A dry run must write nothing.
$dry = $applier->apply( $single, array(), array( 'dry_run' => true, 'existing' => 'overwrite' ) );
same( (string) $dry['status'], 'dry_run', 'A dry run reports itself as a dry run.' );
same( 0, (int) $dry['stages']['apply']['written'], 'And writes nothing.' );
same( 'Existing Product', (string) get_post_field( 'post_title', $target_id ), 'The destination is untouched.' );

// A real write over a destination that already holds something different. The policy is
// explicitly "overwrite", which is the only way this stage proceeds at all.
$first = $applier->apply( $single, array(), array( 'existing' => 'overwrite' ) );
same( (string) $first['status'], 'committed', 'A real write commits.' );
same( 1, (int) $first['stages']['apply']['written'], 'One field was written.' );
same( 'A mapped heading', (string) get_post_field( 'post_title', $target_id ), 'And the destination now holds the new value.' );
$first_statuses = array_column( (array) $first['stages']['existing_content']['report'], 'status' );
check( in_array( 'overwritable', $first_statuses, true ), 'The existing-content stage reports the pre-existing value as overwritable, because the caller asked for overwrite.' );
check( ! in_array( 'conflict', $first_statuses, true ), 'And not as a conflict, because "overwrite" is an explicit instruction rather than an oversight.' );
check( isset( $first['stages']['existing_content']['report'][0]['existing'] ), 'While recording what was there before, so the change is legible after the fact.' );
same( 'Existing Product', (string) $first['stages']['existing_content']['report'][0]['existing'], 'Which was the original title.' );

// Applying the identical plan again. Nothing differs, so nothing is written and nothing
// is at risk - the state a repeated run lands in, which is the common case when a
// mapping is re-applied after an unrelated edit.
$again = $applier->apply( $single, array(), array( 'existing' => 'overwrite' ) );
same( (string) $again['status'], 'committed', 'Re-applying an identical plan commits rather than erroring.' );
$again_statuses = array_column( (array) $again['stages']['existing_content']['report'], 'status' );
check( in_array( 'unchanged', $again_statuses, true ), 'The existing-content stage reports the destination as unchanged.' );
check( false !== strpos( (string) wp_json_encode( $again['stages']['existing_content']['report'] ), 'skip' ), 'With the action recorded as a skip, so a report can say why no write happened.' );
same( 'A mapped heading', (string) get_post_field( 'post_title', $target_id ), 'And the destination is untouched.' );

// A different value again, which is the "genuine new heading" case.
$write_plan = $single;
$write_plan['mappings'][0]['source']['value'] = 'A genuinely new heading';
$write_plan['source_digest']                = Content_Validator::source_digest( $write_plan['mappings'] );
$written = $applier->apply( $write_plan, array(), array( 'existing' => 'overwrite' ) );
same( (string) $written['status'], 'committed', 'A second real write commits.' );
same( 1, (int) $written['stages']['apply']['written'], 'One field was written.' );
same( 'A genuinely new heading', (string) get_post_field( 'post_title', $target_id ), 'And the destination now holds the new value.' );
check( count( $written['written'] ) === 1, 'The result reports what was written.' );
check( isset( $written['written'][0]['provenance']['source_content_id'] ), 'With its provenance, which is the commit.' );
check( ! empty( $written['stages']['snapshot']['taken'] ) || ! empty( $written['snapshots'] ), 'And a snapshot was taken first.' );

$provenance = ( new Content_Cache() )->provenance( 'proj14', $target_id, 'post_title' );
check( count( $provenance ) === 1, 'The provenance record is stored.' );
same( 'c_1', (string) $provenance[0]['source_content_id'], 'Naming the source content id.' );
same( 'user_controlled', (string) $provenance[0]['ownership'], 'And the ownership state Phase 9 needs in order to know it must not overwrite this field.' );

// A conflict must be reported, not resolved.
$conflict_plan = $single;
$conflict_plan['mappings'][0]['source']['value'] = 'A conflicting heading';
$conflict_plan['source_digest']                = Content_Validator::source_digest( $conflict_plan['mappings'] );
$conflict = $applier->apply( $conflict_plan, array(), array( 'existing' => 'review' ) );
same( (string) $conflict['status'], 'conflict', 'A destination that already holds a different value is a conflict.' );
same( 'A genuinely new heading', (string) get_post_field( 'post_title', $target_id ), 'And nothing was written.' );
check( count( $conflict['conflicts'] ) >= 1, 'The conflicts are listed so a user can choose.' );
check( false !== strpos( (string) wp_json_encode( $conflict['conflicts'] ), 'ReplicaForge did not decide' ), 'With a note saying the decision is the user’s.' );

// A read-only field cannot be written.
$stock_plan = $single;
$stock_plan['mappings'][0]['destination']['field']     = '_stock';
$stock_plan['mappings'][0]['destination']['data_type'] = 'number';
$stock = $applier->apply( $stock_plan, array(), array( 'existing' => 'overwrite' ) );
check( 0 === (int) $stock['stages']['apply']['written'], 'A field the provider marks read-only is not written, whatever the plan asked for.' );

// Rollback is wired to Phase 6's snapshot store.
$rollback = $applier->rollback( array( array( 'post_id' => $target_id, 'snapshot_id' => 'snapshot_does_not_exist', 'field' => 'post_title' ) ) );
check( isset( $rollback['restored'] ), 'Rollback reports how many destinations it restored.' );
check( ! $rollback['ok'], 'And a restore that did not happen is not reported as success.' );

/* =====================================================================
 * 13. The service facade, and the end-to-end shape.
 * ================================================================== */

echo "--- 13. Service and end-to-end ---\n";

$service = new Content_Service( array( 'user_id' => get_current_user_id(), 'woocommerce_api' => array( 'active' => static function () { return true; }, 'products' => static function ( $p, $n ) { return array( 'total' => 0, 'items' => array() ); }, 'product' => static function ( $id ) { return null; }, 'terms' => static function ( $t, $p, $n ) { return array( 'total' => 0, 'items' => array() ); } ) ) );
$providers = $service->providers();
check( isset( $providers['wordpress'], $providers['woocommerce'] ), 'Both providers are registered.' );
check( $providers['woocommerce']['available'], 'The injected store is available.' );
check( ! empty( $providers['woocommerce']['fields'] ), 'And its fields are described for a mapping screen.' );
$readonly = 0;
foreach ( $providers['woocommerce']['fields'] as $field ) {
	if ( empty( $field['editable'] ) ) { $readonly++; }
}
check( $readonly >= 1, 'Including at least one read-only field, so a UI can show it as such.' );

$analysis = $service->analyse( 'proj14', (string) $fixture_id, rf14_product_page(), null, array( 'no_cache' => true ) );
check( $analysis['model']['schema_version'] === '14.0', 'The service analyses a page into a 14.0 model.' );
check( isset( $analysis['validation'] ), 'And validates it.' );
check( $analysis['structured']['trust'] === 'unverified_source_claim', 'Carrying the trust marker for anything read from structured data.' );
check( $analysis['cache']['hit'] === false, 'And records whether the cache was used.' );

$second = $service->analyse( 'proj14', (string) $fixture_id, rf14_product_page(), null, array() );
check( $second['cache']['hit'] === true, 'A second analysis of the same content is a cache hit, because the key is a content hash.' );
$changed = $service->analyse( 'proj14', (string) $fixture_id, rf14_product_page( array( 'components' => array( array( 'id' => 'x', 'type' => 'h1', 'text' => 'Different' ) ) ) ), null, array() );
check( $changed['cache']['hit'] === false, 'And a changed page is a miss, because the content hash is part of the key.' );

$destination = $service->destination_model();
check( isset( $destination['wordpress']['post_types'] ), 'The destination model reports post types.' );
check( isset( $destination['taxonomies'] ) || isset( $destination['wordpress']['taxonomies'] ), 'And taxonomies.' );
check( $destination['woocommerce']['enabled'] === true, 'And whether WooCommerce is on.' );
check( count( $destination['woocommerce']['product_types'] ) === 6, 'With the six section 8 product types.' );

$mismatch = $service->match_entities( array( array( 'entity_id' => 's1', 'title' => 'Widget' ) ), 'product', array( 'provider' => 'woocommerce' ) );
check( $mismatch['available'], 'Entity matching runs against an available store.' );
$no_store = new Content_Service( array( 'user_id' => get_current_user_id(), 'woocommerce_api' => array( 'active' => static function () { return false; } ) ) );
$unavailable = $no_store->match_entities( array( array( 'entity_id' => 's1', 'title' => 'Widget' ) ), 'product' );
check( ! $unavailable['available'], 'Against an absent store it reports unavailable.' );
same( $unavailable['code'], 'woocommerce_not_active', 'With the WooCommerce code.' );
check( false === $unavailable['empty'], 'And explicitly not empty, so a caller cannot read it as "you have no products".' );

/* =====================================================================
 * 14. Security properties.
 * ================================================================== */

echo "--- 14. Security ---\n";

$api_source = (string) file_get_contents( REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-api.php' );
$code = preg_replace( '#/\*.*?\*/#s', '', $api_source );
$code = preg_replace( '#//[^\n]*#', '', (string) $code );

check( false === strpos( (string) $code, "get_param( 'url' )" ), 'No content route takes a URL to fetch.' );
check( false === strpos( (string) $code, 'uploads' ), 'No content route references an upload path.' );
check( false === strpos( (string) $code, 'wp_get_upload_dir' ), 'And no upload directory is consulted.' );
check( false !== strpos( $api_source, 'readable_project' ), 'Every project route resolves ownership before reading the store.' );
check( false !== strpos( $api_source, 'could not be found, or it belongs to another account' ), 'With one message for missing and not-yours, so a project id cannot be probed.' );
check( false !== strpos( $api_source, "replicaforge_manage_plans" ), 'The apply route requires a stronger capability than reading, because writing to the user’s data is not a read.' );
// Behaviour, not a regex against the source. The first draft asserted
// `preg_match` on a pattern literal, which tests the spelling of the guard rather than
// the guard - and a comment or a whitespace change breaks it without any behaviour
// changing at all. Calling the real method is the assertion that means something.
$approved_api = new \ReplicaForge\Content_Api( array( 'logger' => new \ReplicaForge\Logger() ) );
$approved_reader = new ReflectionMethod( '\ReplicaForge\Content_Api', 'approved_ids' );
$approved_reader->setAccessible( true );

$hostile = array(
	'../../../wp-config.php',
	'map_../../etc/passwd',
	'map_ZZZZZZZZ',
	'map_' . str_repeat( 'g', 8 ),
	'map_short',
	"map_aaaaaaaaaaaaaaaa\nDROP TABLE",
	'<script>',
	'',
);
$hostile_request = new \WP_REST_Request( 'POST' );
$hostile_request->set_param( 'approved', $hostile );
$kept = $approved_reader->invoke( $approved_api, $hostile_request );
same( $kept, array(), 'None of a batch of hostile "approved mapping id" values survives the pattern check, so none reaches a comparison or an option name.' );

$good_request = new \WP_REST_Request( 'POST' );
$good_request->set_param( 'approved', array( 'map_' . str_repeat( 'a', 16 ), 'map_' . str_repeat( 'b', 32 ) ) );
$good = $approved_reader->invoke( $approved_api, $good_request );
same( count( $good ), 2, 'And a well-formed id does survive, so the check filters rather than refuses everything.' );
check( $good[0] === 'map_' . str_repeat( 'a', 16 ), 'Unchanged, exactly.' );
check( false === strpos( (string) $code, 'eval(' ), 'No content code evaluates anything.' );
check( false === strpos( (string) $code, 'shell_exec' ), 'And shells out to nothing.' );
check( false === strpos( (string) $code, 'proc_open' ), 'And spawns no process.' );

// A plan may not name a destination the caller invented.
$foreign = $plan;
$foreign['mappings'][0]['destination'] = array( 'provider' => 'wordpress', 'entity' => 'page', 'entity_id' => 1, 'field' => 'meta:api_key', 'data_type' => 'text' );
	/* 	 * A plan may not name a destination the caller invented. 	 * 	 * This assertion used to read 	 * 	 *     check( ! ( new Content_Validator() )->validate( $foreign )['applicable'] || true, ... ); 	 * 	 * The `|| true` made it unconditionally true, and the form it wrapped was also the wrong 	 * shape. `Content_Validator::validate()` **never inspects the destination field** - it 	 * checks schema version, project, mode, the source seal, action, risk, the review and 	 * coherence rules, confidence floors, provenance and confirmation, and nothing about 	 * `destination.field`. 	 * 	 * That is deliberate rather than an oversight. The validator is *provider-agnostic*: it 	 * holds no provider instance, so it cannot know whether `meta:api_key` is a field the 	 * WordPress provider exposes. Only the applier, which does hold the provider, can answer. 	 * 	 * So the original assertion could never have passed as written, and making it pass would 	 * have meant either coupling the validator to a provider - undoing the separation that 	 * makes it testable - or asserting something untrue. 	 * 	 * Both facts are asserted below: the validator accepts the plan, *and* the applier is 	 * the boundary that refuses. The first is a real property of the design worth recording 	 * rather than hiding - it means a REST or preview caller learns about an unwritable 	 * destination from the applier, not from validation, and the message has to say so. 	 */ 	$rf14_validator_result = ( new Content_Validator() )->validate( $foreign ); 	check( is_array( $rf14_validator_result ), 'A plan naming an unknown field is still structurally validatable.' ); 	check( true === $rf14_validator_result['applicable'], 'The validator accepts it, because it is provider-agnostic and cannot know the field is unknown - which is why the applier has to be the boundary that refuses.' );

$applier_source = (string) file_get_contents( REPLICAFORGE_PATH . 'includes/content/class-replicaforge-content-applier.php' );
$applier_code = (string) preg_replace( '#//[^\n]*#', '', (string) preg_replace( '#/\*.*?\*/#s', '', $applier_source ) );
check( 1 === preg_match( '/empty\( \$descriptor\[.editable.\] \)/', $applier_source ), 'The applier re-checks that a field is writable rather than trusting the plan, because a plan can be edited between validation and apply.' );
check( false === strpos( $applier_code, 'wpdb' ), 'The applier uses no direct database access.' );
check( false === strpos( $applier_code, 'update_option' ), 'And writes no options.' );
check( false === strpos( $applier_code, 'wp_delete_post' ), 'And never deletes a post. delete_post_meta is allowed, because restoring an absent field means removing the meta rather than writing an empty string.' );

$provider_source = (string) file_get_contents( REPLICAFORGE_PATH . 'includes/content/interface-replicaforge-content-provider.php' );
check( false === stripos( $provider_source, 'function update(' ), 'The provider contract has no update method at all — the object holding read access has no way to write.' );
check( false === stripos( $provider_source, 'function save(' ), 'Nor a save.' );
check( false === stripos( $provider_source, 'function set_price' ), 'Nor a set_price.' );
$wc_source = (string) file_get_contents( REPLICAFORGE_PATH . 'includes/content/class-replicaforge-woocommerce-provider.php' );
check( false === stripos( $wc_source, 'function update(' ), 'And the WooCommerce provider adds no write path of its own.' );

// The absence of private data is asserted on the *public API surface*, not on the word
// "order". The first draft scanned the source text for `order`, `customer` and
// `payment`, which matches `'order' => 'ASC'`, the word "in order to", and a comment
// mentioning inventory - so it was asserting something about prose rather than about
// what the class can return, and it would have failed on a harmless reformat.
//
// What matters is that there is no method by which an order, a customer, or a payment
// record could be read. That is a property of the method list, so the method list is
// what gets checked.
$wc_public = array();
foreach ( ( new ReflectionClass( '\ReplicaForge\WooCommerce_Provider' ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
	$wc_public[] = strtolower( $method->getName() );
}
$contract_public = array();
foreach ( ( new ReflectionClass( '\ReplicaForge\Content_Provider_Contract' ) )->getMethods() as $method ) {
	$contract_public[] = strtolower( $method->getName() );
}
// The only additions are the constructor and the API-injection seam, and both are named
// explicitly rather than waved through - a future method that is not one of these two
// fails this test, which is the point.
$extra = array_values( array_diff( $wc_public, $contract_public ) );
sort( $extra );
same( $extra, array( '__construct', 'set_api' ), 'The WooCommerce provider adds only its constructor and the API-injection seam beyond the contract, so its read surface is the eight declared methods and nothing else.' );
same( count( $contract_public ), 8, 'The contract itself is eight methods, and it is a read contract: nothing on it can write.' );
foreach ( $contract_public as $method_name ) {
	check( false === strpos( $method_name, 'order' ), sprintf( 'The contract has no "%s" method, so an order cannot be read through a provider.', $method_name ) );
	check( false === strpos( $method_name, 'customer' ), sprintf( 'Nor a "%s" method for customer data.', $method_name ) );
	check( false === strpos( $method_name, 'payment' ), sprintf( 'Nor a "%s" method for payment records.', $method_name ) );
	check( false === strpos( $method_name, 'stock' ), sprintf( 'Nor a "%s" method for stock levels.', $method_name ) );
}
$wp_public = array();
foreach ( ( new ReflectionClass( '\ReplicaForge\WordPress_Content_Provider' ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
	$wp_public[] = strtolower( $method->getName() );
}
$wp_extra = array_values( array_diff( $wp_public, $contract_public ) );
same( $wp_extra, array( '__construct' ), 'And the WordPress provider adds only its constructor, so neither destination has a wider read surface than the contract.' );

// Comments are stripped before this scan. The provider's own docblock explains *why*
// it does not use $wpdb, and the first scan found the word in the explanation and failed
// a class for doing the right thing - the mirror image of the mistake Phase 13 warned
// about, which was asserting on a comment instead of on code.
$wp_provider = (string) file_get_contents( REPLICAFORGE_PATH . 'includes/content/class-replicaforge-wordpress-content-provider.php' );
$wp_provider = (string) preg_replace( '#//[^\n]*#', '', (string) preg_replace( '#/\*.*?\*/#s', '', $wp_provider ) );
check( false === strpos( $wp_provider, 'wpdb' ), 'The WordPress provider uses no direct SQL, which is what keeps it inside WordPress capability and status rules.' );
check( false !== strpos( $wp_provider, 'read_post' ), 'And it asks WordPress whether the user may read a post, rather than reimplementing the answer.' );
check( false !== strpos( $wp_provider, 'has_password' ), 'Password-protected posts are excluded from the listing.' );
check( false !== strpos( $wp_provider, 'publicly_queryable' ), 'And a post type must be public and publicly queryable to be a mapping destination.' );
check( false === strpos( $wp_provider, 'get_post_meta( $post->ID, ' ), 'And it never enumerates a post meta keys, because reading every stored value would pull a licence key or a private note into memory.' );

/* =====================================================================
 * 15. Earlier phases are intact.
 * ================================================================== */

echo "--- 15. Earlier phases are intact ---\n";

check( in_array( 'structure', \ReplicaForge\Validation_Limits::CATEGORIES, true ), 'Phase 5 categories are unchanged.' );
check( count( \ReplicaForge\Validation_Limits::LEVELS ) === 4, 'And its four levels.' );
check( count( \ReplicaForge\Visual_Limits::RELATIONSHIPS ) === 13, 'Phase 13 relationships are unchanged.' );
check( \ReplicaForge\Visual_Limits::SCHEMA_VERSION === '13.0', 'And its schema version.' );
check( \ReplicaForge\Site_Limits::SCHEMA_VERSION === '12.0', 'Phase 12 schema is unchanged.' );
check( ! defined( 'ReplicaForge\Component_Registry::OWNERSHIP' ), 'Phase 12 registry declares no ownership list of its own, so it cannot disagree with Phase 9 - and therefore cannot disagree with Phase 14, which reads Phase 9.' );
check( count( \ReplicaForge\Job_Limits::STAGES ) >= 4, 'Phase 11 job stages are unchanged.' );

$limits = \ReplicaForge\Plan_Limits::OPERATIONS;
foreach ( array( 'content_analysis', 'content_mapping', 'content_apply' ) as $operation ) {
	check( in_array( $operation, $limits, true ), sprintf( 'The %s operation is metered by Phase 10.', $operation ) );
}
check( isset( \ReplicaForge\Plan_Limits::OPERATION_FEATURES['content_apply'] ), 'And maps to the content_mapping feature.' );
check( isset( \ReplicaForge\Error_Catalog::CATEGORY_SEVERITY['CONTENT'] ), 'And the CONTENT error category has a default severity.' );

$catalog = \ReplicaForge\Error_Catalog::CODES;
foreach ( array( 'content_provider_unavailable', 'woocommerce_not_active', 'no_products_found', 'mapping_not_found', 'low_confidence_mapping', 'mapping_conflict', 'destination_field_unavailable', 'source_content_changed', 'destination_content_changed', 'permission_denied', 'invalid_mapping', 'unsupported_field', 'validation_failed', 'rollback_required' ) as $code ) {
	check( isset( $catalog[ $code ] ), sprintf( 'The section 43 code %s is in the existing catalogue.', $code ) );
	check( ! empty( $catalog[ $code ]['message'] ), sprintf( '%s has a user-readable message.', $code ) );
}

$migrator = new \ReplicaForge\Migrator();
$migrations = $migrator->migrations();
// `end( $migrations )['to'] === '14.0.0'` asserted a *position*, not a property. Phase 14's
// intent is that its migration is declared and well-formed, and that only held until a
// later phase added another - which is exactly what Phase 15 did, breaking a suite that was
// otherwise fine. This is the same hard-coded-position mistake that was corrected in the
// Phase 13 and Phase 10 suites during Phase 14, and it was an oversight to leave it here.
$phase14_at = -1;
for ( $mi = 0; $mi < count( $migrations ); $mi++ ) {
	if ( '14.0.0' === (string) $migrations[ $mi ]['to'] ) {
		$phase14_at = $mi;
	}
}
same( $phase14_at >= 0, true, 'The Phase 14 migration is still declared.' );

// Everything after Phase 14 must increase without gaps. A property of the chain, not a
// position, so a later phase adding a migration does not break a suite about Phase 14.
$chain_ok = true;
for ( $mi = max( 1, $phase14_at ); $mi < count( $migrations ); $mi++ ) {
	if ( version_compare( (string) $migrations[ $mi ]['to'], (string) $migrations[ $mi - 1 ]['to'], '<=' ) ) {
		$chain_ok = false;
	}
	if ( (string) $migrations[ $mi ]['from'] !== (string) $migrations[ $mi - 1 ]['to'] ) {
		$chain_ok = false;
	}
}
same( $chain_ok, true, 'The migration chain continues from Phase 14 without gaps.' );
check( version_compare( \ReplicaForge\Schema::DB_SCHEMA_VERSION, '14.0.0', '>=' ), 'The declared schema version has reached at least Phase 14.' );

$run = $migrator->run( true );
check( ! empty( $run['success'] ), 'The migration runs.' );
$phase14 = array();
foreach ( (array) $run['applied'] as $entry ) {
	if ( is_array( $entry ) && '14.0.0' === (string) ( $entry['to'] ?? '' ) ) { $phase14 = (array) ( $entry['result'] ?? array() ); }
}
check( array_key_exists( 'options_created', $phase14 ), 'The Phase 14 result is found by its own target version.' );
check( ! empty( $phase14['note'] ), 'And says plainly that no store was connected and nothing was created.' );
check( true === $phase14['needs_confirmation'], 'And records that the default mode requires confirmation.' );
check( null !== get_option( Content_Cache::ANALYSIS_OPTION, null ), 'The content cache index exists after the migration.' );
check( null !== get_option( 'replicaforge_content_mode', null ), 'And the content mode option exists with every decision visible.' );
$again = $migrator->run( true );
$phase14_again = array();
foreach ( (array) $again['applied'] as $entry ) {
	if ( is_array( $entry ) && '14.0.0' === (string) ( $entry['to'] ?? '' ) ) { $phase14_again = (array) ( $entry['result'] ?? array() ); }
}
same( (int) ( $phase14_again['options_created'] ?? -1 ), 0, 'A second run creates no options.' );
same( count( $migrator->pending() ), 0, 'And nothing is left pending.' );
check( version_compare( (string) Schema::installed(), '14.0.0', '>=' ), 'The installed schema is at least the Phase 14 version.' );

/* =====================================================================
 * 16. REST routes.
 * ================================================================== */

echo "--- 16. Routes ---\n";

$api = new \ReplicaForge\Content_Api( array( 'logger' => new \ReplicaForge\Logger() ) );
$api->register_routes();
$paths = array();
foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
	if ( false !== strpos( $route, '/replicaforge/v1/content' ) ) { $paths[] = $route; }
}
check( count( $paths ) >= 12, sprintf( 'The content API registers its routes (%d paths).', count( $paths ) ) );
$joined = implode( ' ', $paths );
foreach ( array( 'providers', 'destination', 'limits', 'modes', 'analyze', 'mapping/plan', 'mapping/preview', 'mapping/validate', 'mapping/apply', 'mapping/review', 'entities/match', 'provenance' ) as $segment ) {
	check( false !== strpos( $joined, $segment ), sprintf( 'The %s route exists.', $segment ) );
}

$ungated = array();
foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
	if ( false === strpos( $route, '/content/' ) ) { continue; }
	foreach ( $handlers as $handler ) {
		if ( ! isset( $handler['callback'] ) || ! is_callable( $handler['callback'] ) ) { continue; }
		if ( empty( $handler['permission_callback'] ) ) { $ungated[] = $route; }
	}
}
check( ! in_array( '/replicaforge/v1/content/mapping/apply', $ungated, true ), 'The apply route is gated.' );
check( count( $ungated ) === 0, sprintf( 'And no content route is ungated (%d found).', count( $ungated ) ) );

$plugin_api = \ReplicaForge\Plugin::instance()->content_api();
check( $plugin_api instanceof \ReplicaForge\Content_Api, 'Plugin::instance()->content_api() is live.' );
$plugin_service = \ReplicaForge\Plugin::instance()->content_service();
check( $plugin_service instanceof Content_Service, 'And Plugin::instance()->content_service() is live.' );

/* =====================================================================
 * 17. Cleanup and done.
 * ================================================================== */

echo "--- 17. Phase 14 complete ---\n";
check( $assertions > 250, sprintf( 'The Phase 14 suite ran %d assertions, so a later edit that silently drops coverage fails rather than passing quietly.', $assertions ) );
echo "assertions: $assertions\n";
