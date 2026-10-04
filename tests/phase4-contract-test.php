<?php
/**
 * Phase 4 Elementor generation contract smoke test.
 *
 * Run inside a bootstrapped WordPress environment with:
 *   wp eval-file wp-content/plugins/replicaforge/tests/phase4-contract-test.php
 *
 * The test is deterministic. It never contacts the analyzed website and never
 * calls an AI provider. When Elementor is available it creates one real draft,
 * validates the stored document, and then deletes the draft again.
 *
 * @package ReplicaForge
 */

defined( 'ABSPATH' ) || exit;

$fixture = dirname( __DIR__ ) . '/tests/fixtures/phase3-representation.json';
$raw     = is_readable( $fixture ) ? file_get_contents( $fixture ) : '';
$source  = '' !== $raw ? json_decode( $raw, true ) : null;
if ( ! is_array( $source ) ) {
	throw new RuntimeException( 'Phase 4 fixture could not be loaded.' );
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAILED: ' . $message );
	}
	echo 'PASS: ' . $message . "\n";
};

/*
 * A registry double keeps the structural assertions independent from whichever
 * Elementor build is installed.
 */
$registry_double = new class() {
	public $widgets = array(
		'heading'     => true,
		'text-editor' => true,
		'button'      => true,
		'image'       => true,
		'divider'     => true,
		'spacer'      => true,
		'icon'        => true,
	);

	public function has_widget( $name ) {
		return is_string( $name ) && isset( $this->widgets[ $name ] );
	}

	public function has_element_type( $name ) {
		return 'container' === $name;
	}

	public function supports_containers() {
		return true;
	}
};

$limited_registry = new class() {
	public function has_widget( $name ) {
		return 'text-editor' === $name;
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
$validator = new \ReplicaForge\Elementor_Spec_Validator();
$mapper    = new \ReplicaForge\Elementor_Mapper( $registry );
$builder   = new \ReplicaForge\Elementor_Document_Builder();
$checker   = new \ReplicaForge\Elementor_Validator( $registry );
$responsive = new \ReplicaForge\Elementor_Responsive();

/* ------------------------------------------------------------------ */
/* 1. Specification input validation.                                  */
/* ------------------------------------------------------------------ */
$spec   = $planner->build( $source );
$result = $validator->validate( $spec );
$assert( ! empty( $result['valid'] ), 'A validated Phase 3 specification is accepted by the Phase 4 input validator.' );

$assert( ! empty( $result['plan']['sections'] ), 'The normalized plan contains sections.' );
$assert( ! empty( $result['plan']['components'] ), 'The normalized plan contains components.' );
$assert( ! empty( $result['plan']['content'] ), 'The normalized plan carries source content mappings.' );
$assert( '3.0' === $result['plan']['schema_version'], 'The normalized plan is bound to specification 3.0.' );

$plan = $result['plan'];

$broken = $spec;
$broken['schema_version'] = '2.0';
$result = $validator->validate( $broken );
$assert( empty( $result['valid'] ), 'A specification with the wrong schema version is rejected.' );

$broken = $spec;
$broken['_elementor_data'] = array( 'elType' => 'container' );
$result = $validator->validate( $broken );
$assert( empty( $result['valid'] ), 'Builder-specific keys in the specification are rejected.' );

$broken                              = $spec;
$broken['components'][0]['target']   = 'javascript:alert(1)';
$broken['components'][0]['image']    = array( 'src' => 'http://127.0.0.1/secret.png' );
$result                              = $validator->validate( $broken );
$unsafe_plan                         = $result['plan'];
$assert(
	'' === $unsafe_plan['components']['component_001']['target'] && '' === $unsafe_plan['components']['component_001']['image']['src'],
	'Unsafe component targets and image sources are discarded during normalization.'
);

$broken                                = $spec;
$broken['content_mapping'][0]['value'] = '<script>alert(1)</script>Build better sites';
$result                                = $validator->validate( $broken );
$assert(
	null === $result['plan']['content']['component_001']['text'] || false === strpos( (string) $result['plan']['content']['component_001']['text'], '<script' ),
	'Executable markup in a content value is dropped rather than written to a widget.'
);

/* ------------------------------------------------------------------ */
/* 2. Mapping, document build, and document validation.                */
/* ------------------------------------------------------------------ */
$assets         = array();
$responsive_plan = $responsive->build( $plan );
$mapped          = $mapper->map( $plan, $assets, $responsive_plan );
$document        = $builder->build( $mapped['tree'], array( 'generation_id' => 'rf_test_generation' ) );

$assert( ! empty( $mapped['tree'] ), 'The mapper produces an element tree.' );
$assert( $mapped['stats']['containers'] > 0, 'The mapper produces Elementor containers.' );
$assert( $mapped['stats']['widgets'] > 0, 'The mapper produces real Elementor widgets.' );
$check = $checker->validate_document( $document['elements'] );
$assert( ! empty( $check['valid'] ), 'The generated Elementor document passes structural validation.' );
$assert( (int) $check['count'] === (int) $document['count'], 'The document validator counts every generated element.' );

$encoded = wp_json_encode( $document['elements'] );
$assert(
	false === strpos( (string) $encoded, '"elType":"html"' ) && false === strpos( (string) $encoded, '"widgetType":"html"' ),
	'No HTML or shortcode widget is emitted.'
);
$assert( false === strpos( (string) $encoded, '<?php' ), 'No PHP is emitted into the document.' );

$ids = array();
$collect_ids = static function ( $elements ) use ( &$collect_ids, &$ids ) {
	foreach ( $elements as $element ) {
		$ids[] = $element['id'];
		if ( ! empty( $element['elements'] ) ) {
			$collect_ids( $element['elements'] );
		}
	}
};
$collect_ids( $document['elements'] );
$assert( count( $ids ) === count( array_unique( $ids ) ), 'Every generated element has a unique identifier.' );
$valid_ids = true;
foreach ( $ids as $id ) {
	if ( ! preg_match( '/^[a-f0-9]{7}$/', $id ) ) {
		$valid_ids = false;
		break;
	}
}
$assert( $valid_ids, 'Every element identifier matches the Elementor format.' );

$top_level_containers = 0;
foreach ( $document['elements'] as $element ) {
	if ( 'container' === $element['elType'] ) {
		$top_level_containers++;
	}
}
$assert( $top_level_containers === count( $document['elements'] ), 'Every top-level element is a container.' );
$assert( ! empty( $document['id_map'] ), 'A component to element identifier map is produced.' );

/* ------------------------------------------------------------------ */
/* 3. Content fidelity.                                                */
/* ------------------------------------------------------------------ */
$encoded = (string) wp_json_encode( $document['elements'] );
$assert( false !== strpos( $encoded, 'Build better sites' ), 'Source heading text is preserved verbatim.' );
$assert( false !== strpos( $encoded, 'Product One' ), 'Detected product card title is preserved.' );
$assert( false !== strpos( $encoded, '$19.00' ), 'Detected product price is preserved.' );
$assert( false === strpos( $encoded, '$99.00' ) && false === strpos( $encoded, 'Free Shipping' ), 'No price or marketing claim is invented.' );

// A URL cannot be searched for in `wp_json_encode` output, because that escapes
// every forward slash, so the written setting is asserted on the document itself.
// The builder nests children under `elements`, which is the Elementor format.
$collect_links = function ( $nodes ) use ( &$collect_links ) {
	$links = array();
	foreach ( $nodes as $node ) {
		if ( isset( $node['settings']['link'] ) && is_string( $node['settings']['link'] ) ) {
			$links[] = $node['settings']['link'];
		}
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			$links = array_merge( $links, $collect_links( $node['elements'] ) );
		}
	}
	return $links;
};
$written_links = $collect_links( $document['elements'] );
$assert( in_array( 'https://example.com/contact', $written_links, true ), 'A detected safe link target is preserved.' );

/* ------------------------------------------------------------------ */
/* 4. Fallbacks when widgets are unavailable.                           */
/* ------------------------------------------------------------------ */
$fallback_registry = new \ReplicaForge\Elementor_Widget_Registry( $limited_registry );
$fallback_mapper   = new \ReplicaForge\Elementor_Mapper( $fallback_registry );
$fallback          = $fallback_mapper->map( $plan, $assets, $responsive_plan );
$fallback_document = $builder->build( $fallback['tree'], array( 'generation_id' => 'rf_test_fallback' ) );
$assert( ! empty( $fallback['tree'] ), 'A missing widget still produces a document through a safe fallback.' );
$assert( ! empty( $fallback['warnings'] ), 'Fallback use is reported as a warning.' );
$fallback_encoded = (string) wp_json_encode( $fallback_document['elements'] );
$assert( false === strpos( $fallback_encoded, '"widgetType":"heading"' ), 'An unavailable heading widget is not written.' );

/* ------------------------------------------------------------------ */
/* 5. Dangerous input rejection.                                        */
/* ------------------------------------------------------------------ */
$hostile = $document;
$hostile['elements'][0]['settings']['background_image'] = array( 'url' => 'javascript:alert(1)' );
$check = $checker->validate_document( $hostile['elements'] );
$assert( empty( $check['valid'] ), 'A javascript: URL in a document is rejected.' );

$hostile = $document;
array_unshift(
	$hostile['elements'],
	array(
		'id'         => 'aaaaaaa',
		'elType'     => 'widget',
		'widgetType' => 'html',
		'settings'   => array( 'html' => '<script>alert(1)</script>' ),
		'elements'   => array(),
	)
);
$check = $checker->validate_document( $hostile['elements'] );
$assert( empty( $check['valid'] ), 'An HTML widget in a document is rejected.' );

$hostile = $document;
$hostile['elements'][0]['elements'][] = array(
	'id'       => 'aaaaaaa',
	'elType'   => 'container',
	'settings' => array( 'html' => '<?php echo 1; ?>' ),
	'elements' => array(),
);
$check = $checker->validate_document( $hostile['elements'] );
$assert( empty( $check['valid'] ), 'PHP in a document setting is rejected.' );

$hostile = $document;
$hostile['elements'][0]['id'] = 'NOT-VALID';
$check = $checker->validate_document( $hostile['elements'] );
$assert( empty( $check['valid'] ), 'An invalid element identifier is rejected.' );

/* ------------------------------------------------------------------ */
/* 6. Asset safety.                                                     */
/* ------------------------------------------------------------------ */
$asset_service = new \ReplicaForge\Elementor_Assets();
$assets_result = $asset_service->resolve(
	array(
		'remote' => array(
			'component_id' => 'component_remote',
			'type'         => 'image',
			'role'         => 'content_image',
			'usage'        => 'content_image',
			'source_url'   => 'https://example.com/photo.jpg',
			'confidence'   => 0.5,
		),
		'loopback' => array(
			'component_id' => 'component_loopback',
			'type'         => 'image',
			'role'         => 'content_image',
			'usage'        => 'content_image',
			'source_url'   => 'http://127.0.0.1/private.png',
			'confidence'   => 0.5,
		),
		'metadata' => array(
			'component_id' => 'component_metadata',
			'type'         => 'image',
			'role'         => 'content_image',
			'usage'        => 'content_image',
			'source_url'   => 'http://169.254.169.254/latest/meta-data/',
			'confidence'   => 0.5,
		),
		'script'   => array(
			'component_id' => 'component_script',
			'type'         => 'image',
			'role'         => 'content_image',
			'usage'        => 'content_image',
			'source_url'   => 'https://example.com/payload.php',
			'confidence'   => 0.5,
		),
		'missing'  => array(
			'component_id' => 'component_missing',
			'type'         => 'image',
			'role'         => 'content_image',
			'usage'        => 'content_image',
			'source_url'   => '',
			'confidence'   => 0.5,
		),
	),
	array( 'import_assets' => false )
);

$assert( 'pending' === $assets_result['states']['component_remote']['import_status'], 'A public image is referenced rather than copied when importing is off.' );
$assert( 'blocked' === $assets_result['states']['component_loopback']['import_status'], 'A loopback asset URL is blocked.' );
$assert( 'blocked' === $assets_result['states']['component_metadata']['import_status'], 'A cloud metadata asset URL is blocked.' );
$assert( 'blocked' === $assets_result['states']['component_script']['import_status'], 'An executable asset type is blocked.' );
$assert( 'unavailable' === $assets_result['states']['component_missing']['import_status'], 'A missing asset keeps an explicit unavailable state.' );
$assert( 0 === (int) $assets_result['summary']['imported'], 'Nothing is copied into the media library when importing is disabled.' );

/* ------------------------------------------------------------------ */
/* 7. Responsive honesty.                                              */
/* ------------------------------------------------------------------ */
$assert( 'not_detected' !== $responsive_plan['report']['desktop'] || 'applied' === $responsive_plan['report']['desktop'], 'Desktop layout is reported from measured evidence.' );
$assert(
	in_array( $responsive_plan['report']['mobile'], array( 'not_detected', 'applied_from_evidence' ), true ),
	'Mobile is only reported as applied when responsive evidence exists.'
);

/* ------------------------------------------------------------------ */
/* 8. Elementor availability and one real draft.                       */
/* ------------------------------------------------------------------ */
$compatibility = new \ReplicaForge\Elementor_Compatibility();
$status        = $compatibility->status();
echo 'INFO: Elementor status: ' . wp_json_encode(
	array(
		'available'  => ! empty( $status['available'] ),
		'version'    => $status['version'],
		'containers' => ! empty( $status['containers'] ),
		'message'    => $compatibility->message(),
	)
) . "\n";

$generator = new \ReplicaForge\Elementor_Generator();

if ( empty( $status['available'] ) ) {
	$preview = $generator->preview( $source, null, array( 'import_assets' => false ) );
	$assert( empty( $preview['success'] ), 'Generation is refused with a clear error when Elementor is unavailable.' );
	echo 'INFO: ' . $preview['error']['message'] . "\n";
} else {
	$admin_id = get_current_user_id();
	if ( ! user_can( $admin_id, 'edit_pages' ) ) {
		$administrator = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);
		if ( ! empty( $administrator ) ) {
			wp_set_current_user( (int) $administrator[0] );
		}
	}

	$preview = $generator->preview( $source, $spec, array( 'import_assets' => false ) );
	$assert( ! empty( $preview['success'] ), 'Preview succeeds without creating a draft.' );
	$assert( ! empty( $preview['data']['report']['limitations'] ), 'The preview reports the documented limitations.' );
	$assert( (int) $preview['data']['report']['elements']['generated'] > 0, 'The preview reports the element count that would be generated.' );
	$assert( (int) $preview['data']['report']['sections']['generated'] > 0, 'The preview reports the section count that would be generated.' );

	$generated = $generator->generate( $source, $spec, array( 'import_assets' => false ) );
	$assert( ! empty( $generated['success'] ), 'A real Elementor draft is generated.' );

	$data    = $generated['data'];
	$post_id = (int) $data['draft_id'];
	$assert( $post_id > 0, 'The generation result contains a draft identifier.' );
	$assert( 'draft' === get_post_status( $post_id ), 'The generated page is a draft, never published.' );
	$assert( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ), 'The draft is marked as built with Elementor.' );
	$assert( '' !== (string) get_post_meta( $post_id, 'replicaforge_generation_id', true ), 'Generation metadata is stored on the draft.' );
	$assert( ! empty( $data['elementor_edit_url'] ), 'An Elementor editor URL is returned.' );

	$post_check = $checker->validate_saved_post( $post_id );
	$assert( ! empty( $post_check['valid'] ), 'The stored Elementor document passes post-creation validation.' );
	$assert( (int) $post_check['count'] === (int) $data['generated_elements'], 'The stored element count matches the reported count.' );

	$stored = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
	$assert( is_array( $stored ) && ! empty( $stored ), 'The draft stores a readable Elementor document.' );
	$stored_encoded = (string) wp_json_encode( $stored );
	$assert( false === strpos( $stored_encoded, '"widgetType":"html"' ), 'The stored document contains no HTML widget.' );
	$assert( false !== strpos( $stored_encoded, 'Build better sites' ), 'The stored document contains the source heading text.' );

	foreach ( $stored as $element ) {
		$assert( 'container' === $element['elType'], 'Top-level stored elements are containers.' );
		break;
	}

	$invalid = $generator->generate( array( 'schema_version' => '2.0' ), $spec, array( 'import_assets' => false ) );
	$assert( empty( $invalid['success'] ), 'Generation is refused for an invalid Phase 2 representation.' );

	$hostile_spec                     = $spec;
	$hostile_spec['content_mapping'][] = array(
		'component_id' => 'component_001',
		'content_type' => 'text',
		'source'       => 'phase2',
		'value'        => 'Invented headline',
	);
	$rejected = $generator->generate( $source, $hostile_spec, array( 'import_assets' => false ) );
	$assert( empty( $rejected['success'] ), 'A specification with invented content is rejected before any draft is created.' );

	wp_delete_post( $post_id, true );
	echo "INFO: test draft {$post_id} deleted.\n";
}

echo "Phase 4 contract smoke test passed.\n";
