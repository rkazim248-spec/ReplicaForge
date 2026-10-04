<?php
/**
 * Centralized Phase 4 Elementor generation limits.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds every deterministic Phase 4 operation.
 *
 * The generator is intentionally conservative. A single analyzed page must
 * never be able to exhaust PHP memory, create an unbounded number of remote
 * requests, or write an unbounded Elementor document.
 */
final class Elementor_Limits {

	/** Phase identifier written into generation metadata. */
	const PHASE = '4.0';

	/** The only Reconstruction Specification schema Phase 4 accepts. */
	const SPEC_SCHEMA_VERSION = '3.0';

	/** Minimum Elementor version that ships the container/flexbox architecture. */
	const MINIMUM_ELEMENTOR_VERSION = '3.16.0';

	/** Maximum number of Phase 3 sections rendered into one document. */
	const MAX_SECTIONS = 60;

	/** Maximum number of Phase 3 components considered for generation. */
	const MAX_COMPONENTS = 400;

	/** Maximum number of Elementor element nodes, including containers. */
	const MAX_ELEMENTS = 1500;

	/** Maximum container nesting depth. */
	const MAX_DEPTH = 10;

	/** Maximum text length copied into a single Elementor setting. */
	const MAX_TEXT_LENGTH = 3000;

	/** Maximum component count considered when building one card structure. */
	const MAX_CARD_CHILDREN = 40;

	/** Maximum asset references resolved in one generation. */
	const MAX_ASSETS = 40;

	/** Maximum number of assets copied into the media library. */
	const MAX_IMPORTED_ASSETS = 24;

	/** Maximum accepted size for a single downloaded asset in bytes. */
	const MAX_ASSET_BYTES = 3145728;

	/** Maximum accepted total downloaded asset size in bytes. */
	const MAX_TOTAL_ASSET_BYTES = 20971520;

	/** Maximum duration of one asset download in seconds. */
	const ASSET_TIMEOUT = 10;

	/** Maximum redirects followed while downloading one asset. */
	const ASSET_MAX_REDIRECTS = 2;

	/** Soft wall-clock budget for one generation request in seconds. */
	const GENERATION_TIME_BUDGET = 25;

	/** Prefix for every ReplicaForge generation post meta key. */
	const META_PREFIX = 'replicaforge_';

	/** Lifetime of the stored reconstruction specification in seconds. */
	const SPEC_TTL = 86400;

	/** Number of generation records kept in the option. */
	const MAX_GENERATION_RECORDS = 25;

	/** Maximum number of warnings stored in a report. */
	const MAX_REPORT_WARNINGS = 60;

	/** Media types that may be imported into the media library. */
	const ALLOWED_MEDIA_TYPES = array(
		'image/jpeg' => 'jpg',
		'image/pjpeg' => 'jpg',
		'image/png' => 'png',
		'image/gif' => 'gif',
		'image/webp' => 'webp',
	);

	/** ReplicaForge component types that map to a non-widget structure. */
	const STRUCTURAL_COMPONENT_TYPES = array(
		'background_image',
		'card',
		'product_card',
		'feature_card',
		'portfolio_card',
		'blog_card',
		'team_card',
		'pricing_card',
		'testimonial',
		'gallery',
		'repeated_card_group',
		'form',
		'form_field',
		'accordion',
		'tabs',
		'tab',
	);
}
