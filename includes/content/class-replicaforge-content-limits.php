<?php
/**
 * Phase 14: the content intelligence vocabulary and bounds.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Every name, type, relationship, sentinel, and bound the content layer uses.
 *
 * ### Why this file exists
 *
 * Phases 10, 11, 12, and 13 each introduced a vocabulary class for the same reason:
 * a name spelled independently in the analyzer, the mapper, the validator, the applier,
 * and the REST layer is a rename that becomes a silent behaviour change in whichever copy
 * was missed. §45's test list is long enough that the vocabulary will be read in five
 * places.
 *
 * ### What it deliberately does *not* define
 *
 * **Ownership is read from Phase 9, not restated.** §16's four states —
 * `source_controlled`, `user_controlled`, `mixed`, `unknown` — already exist as
 * {@see Sync_Conflict_Detector::OWNERSHIP}, and Phase 9's conflict resolution is written
 * against them. A second list in a Phase 14 class would let the two drift, and a mapping
 * marked `user_controlled` in one place and `source_controlled` in another would mean
 * Phase 9 overwrites a user's price. So {@see self::ownership_states()} reads it.
 *
 * **Severity is Phase 5's.** A content difference is reported in the same vocabulary as
 * every other difference, so a Phase 5 score and a Phase 14 score are comparable.
 */
final class Content_Limits {

	/**
	 * Content model schema version.
	 *
	 * Separate from `2.0` (page), `12.0` (website), and `13.0` (visual). The content
	 * model changes when the *meaning* of a role changes, not when a design token does,
	 * so reusing a version number would make a cache key ambiguous.
	 */
	const SCHEMA_VERSION = '14.0';

	/**
	 * The mapping engine version, folded into every content cache key.
	 *
	 * Bump when mapping behaviour changes. A cache key that omits it returns a stale
	 * mapping after the engine improves, and the stale result is indistinguishable from a
	 * correct one — which for a *mapping* means a source price silently written to the
	 * wrong product.
	 */
	const ENGINE_VERSION = '1.0';

	/* ---------------------------------------------------------------------
	 * Semantic content roles
	 * ------------------------------------------------------------------ */

	/**
	 * The §3 semantic roles.
	 *
	 * A closed list. An open one produces `product_title`, `productTitle`, and
	 * `product-title` for one fact, and a consumer cannot filter that without knowing
	 * every spelling.
	 */
	const ROLES = array(
		'site_logo',
		'site_name',
		'navigation_label',
		'hero_heading',
		'hero_description',
		'hero_cta',
		'section_heading',
		'body_text',
		'feature_title',
		'feature_description',
		'product_name',
		'product_price',
		'product_sale_price',
		'product_image',
		'product_gallery',
		'product_description',
		'product_category',
		'product_rating',
		'review_author',
		'review_text',
		'blog_title',
		'blog_excerpt',
		'blog_author',
		'blog_date',
		'blog_category',
		'button',
		'phone',
		'email',
		'address',
		'social_link',
		'footer_text',
	);

	/**
	 * Roles that carry a monetary value.
	 *
	 * Separate because price handling has its own rules and its own risk: a price is the
	 * one content field where a wrong value has a cost, and it is the field a mapping
	 * engine is most tempted to guess at.
	 */
	const PRICE_ROLES = array( 'product_price', 'product_sale_price' );

	/**
	 * Roles that describe a person.
	 *
	 * Grouped so the privacy pass in {@see Content_Preview} can redact them in one
	 * place. A review author or blog author is personal data even when the post is
	 * public.
	 */
	const PERSON_ROLES = array( 'review_author', 'blog_author' );

	/* ---------------------------------------------------------------------
	 * Data types
	 * ------------------------------------------------------------------ */

	/**
	 * Field data types, used for §10 compatibility checks.
	 *
	 * A field-to-field mapping is refused when the data types cannot hold each other's
	 * values, whatever the names say. Mapping `product_price` onto `post_title` because
	 * both are strings is the specific failure this vocabulary exists to prevent.
	 */
	const DATA_TYPES = array( 'text', 'richtext', 'number', 'currency', 'date', 'boolean', 'image', 'url', 'email', 'rating', 'relation', 'enum' );

	/**
	 * The §6 dynamism classes.
	 *
	 * `unknown` is a real answer and the most common one on a page whose behaviour
	 * cannot be observed. Defaulting to `static` would let a future sync overwrite
	 * content that was never actually verified as source-owned.
	 */
	const DYNAMIC_CLASSES = array( 'static', 'dynamic', 'semi_dynamic', 'unknown' );

	/* ---------------------------------------------------------------------
	 * Entities
	 * ------------------------------------------------------------------ */

	/**
	 * The §5 entity vocabulary, grouped by the site type that produces it.
	 *
	 * Grouped rather than flat so {@see self::entities_for()} can offer the right subset
	 * for a detected site type, and so §40 can distinguish a product *card* from a
	 * product *detail* page by which entities appear together.
	 */
	const ENTITY_GROUPS = array(
		'ecommerce' => array( 'product', 'product_category', 'brand', 'price', 'variant', 'sku', 'rating', 'review', 'availability', 'image', 'gallery' ),
		'blog'      => array( 'post', 'author', 'category', 'tag', 'date', 'featured_image', 'excerpt', 'content' ),
		'corporate' => array( 'service', 'team_member', 'office', 'contact', 'case_study', 'client', 'testimonial' ),
		'real_estate' => array( 'property', 'location', 'price', 'property_type', 'agent', 'gallery', 'amenity' ),
		'directory' => array( 'listing', 'category', 'location', 'rating', 'contact' ),
		'common'    => array( 'page', 'navigation', 'form', 'image', 'link' ),
	);

	/* ---------------------------------------------------------------------
	 * Mapping
	 * ------------------------------------------------------------------ */

	/**
	 * The §15 mapping actions.
	 *
	 * A closed list. §15 asks that arbitrary database operations be impossible, and the
	 * way to make an arbitrary operation impossible is for the operation itself not to
	 * exist: an apply step can only ever do one of these four things to one named field.
	 */
	const ACTIONS = array( 'map', 'unmap', 'ignore', 'review' );

	/**
	 * §11 risk levels.
	 */
	const RISKS = array( 'low', 'medium', 'high', 'blocked' );

	/**
	 * §18 reconstruction content modes.
	 *
	 * The default is `hybrid`, but a *project* default is not the same as a per-mapping
	 * default, and §18 requires explicit confirmation before source content is replaced
	 * with destination content. {@see self::mode_requires_confirmation()} encodes which.
	 */
	const MODES = array( 'static_replica', 'dynamic_replica', 'hybrid_replica' );

	/**
	 * §23 options when destination content already exists.
	 *
	 * Every one of these is a *choice*, never an action ReplicaForge takes. `skip` and
	 * `review` are the safe defaults, and a destructive-sounding option never has a
	 * silent default.
	 */
	const EXISTING_CONTENT_OPTIONS = array( 'create_new', 'use_existing', 'create_duplicate', 'skip', 'review' );

	/* ---------------------------------------------------------------------
	 * Match bands
	 * ------------------------------------------------------------------ */

	/**
	 * Minimum score for a destination entity to be offered as a match at all.
	 *
	 * Below this the two entities are different things, and offering them puts a
	 * plausible-looking wrong answer in front of a user. Same reasoning, and the same
	 * value, as {@see Component_Matcher::MIN_SIMILARITY}.
	 */
	const MIN_MATCH = 0.35;

	/**
	 * Score at which a match may be applied without review.
	 */
	const ACCEPT_MATCH = 0.72;

	/**
	 * Score above which a match is reported as confident.
	 */
	const CONFIDENT_MATCH = 0.9;

	/**
	 * Hard ceiling on a match that relies on image similarity alone.
	 *
	 * §20 says never use image similarity alone for final automatic matching, and this
	 * is how that is enforced rather than advised. Two stores routinely reuse the same
	 * manufacturer photo for different products, and a photo match above
	 * {@see self::ACCEPT_MATCH} would auto-write one product's price onto another.
	 *
	 * The ceiling sits *below* {@see self::ACCEPT_MATCH} deliberately, which means an
	 * image-only match can never be auto-applied at all. It can still be shown, because
	 * "these four products use the same photo" is useful to a human deciding.
	 */
	const IMAGE_ONLY_CEILING = 0.55;

	/* ---------------------------------------------------------------------
	 * Confidence floors
	 * ------------------------------------------------------------------ */

	/**
	 * Confidence at or above which a mapping is applied without review.
	 */
	const AUTO_APPLY_CONFIDENCE = 0.85;

	/**
	 * Confidence below which a mapping is refused outright rather than queued for review.
	 *
	 * Distinct from "needs review". Below this the mapping is *wrong*, not *uncertain*,
	 * and offering it wastes a reviewer's attention on a suggestion that has no
	 * evidence behind it.
	 */
	const REJECT_CONFIDENCE = 0.4;

	/* ---------------------------------------------------------------------
	 * Scaffolding
	 * ------------------------------------------------------------------ */

	/**
	 * The §13 "this does not exist" sentinels.
	 *
	 * A closed list, and the single most important vocabulary in this class. §13 is
	 * mandatory: the system must never invent a product, price, SKU, review, author,
	 * address, or URL. These are the only correct answers for absent data, and
	 * {@see Content_Validator} rejects any value that is neither present nor one of
	 * these.
	 */
	const MISSING = array( 'null', 'not_detected', 'unmapped', 'unknown' );

	/**
	 * The literal null markers a content value may carry.
	 *
	 * Distinguished from {@see self::MISSING} because they mean different things: `null`
	 * is "the field exists and is empty", while `not_detected` is "ReplicaForge did not
	 * find this field". Collapsing them loses the difference between an empty price and
	 * an undetected price field, which is the difference between rendering "$0.00" and
	 * rendering nothing.
	 */
	const NULL_MARKERS = array( '', null, 'not_detected', 'unmapped', 'unknown' );

	/* ---------------------------------------------------------------------
	 * Bounds
	 * ------------------------------------------------------------------ */

	/**
	 * Maximum content items in one page model.
	 */
	const MAX_CONTENT_ITEMS = 600;

	/**
	 * Maximum entities per page.
	 */
	const MAX_ENTITIES = 300;

	/**
	 * Maximum mappings in one plan.
	 */
	const MAX_MAPPINGS = 2000;

	/**
	 * Maximum JSON-LD blocks parsed from one document.
	 */
	const MAX_STRUCTURED_BLOCKS = 40;

	/**
	 * Maximum depth walked inside a JSON-LD block.
	 *
	 * A hostile page can nest `@graph` 500 deep. `json_decode` handles the nesting, but
	 * the *walk* is ours, so it is bounded.
	 */
	const MAX_STRUCTURED_DEPTH = 12;

	/**
	 * Maximum bytes accepted from a single JSON-LD block.
	 */
	const MAX_STRUCTURED_BYTES = 262144;

	/**
	 * Maximum destination records read in one provider pass.
	 *
	 * §44 requires working with 10,000+ products without loading them all. Providers
	 * page; this bounds one page.
	 */
	const MAX_DESTINATION_BATCH = 200;

	/**
	 * Maximum plans retained per project.
	 */
	const MAX_PLANS = 20;

	/**
	 * Maximum provenance records per project.
	 *
	 * Declared here, not on the store, because this class is where every bound lives.
	 * Content_Cache referenced Content_Limits::MAX_PROVENANCE while declaring a
	 * MAX_PROVENANCE of its own, so the reference pointed at nothing and the first
	 * write of a provenance record was a fatal. Two declarations of one bound is the
	 * same mistake as two declarations of one role: the copy that gets read is the copy
	 * nobody remembers to update.
	 */
	const MAX_PROVENANCE = 2000;
	/**
	 * Maximum history records per mapping.
	 */
	const MAX_HISTORY = 20;

	/**
	 * Provenance retention, matching the §57 screenshot TTL precedent.
	 *
	 * Provenance is the evidence that a user's price was not overwritten, so it must
	 * outlive the mapping that produced it — but not forever.
	 */
	const PROVENANCE_TTL = 2592000;

	/* ---------------------------------------------------------------------
	 * Accessors that read existing vocabularies
	 * ------------------------------------------------------------------ */

	/**
	 * Return the §16 ownership states, read from Phase 9.
	 *
	 * Read, not restated, because Phase 9's conflict resolution is written against this
	 * list and a mapping marked `user_controlled` here but `source_controlled` there
	 * would mean a future sync silently overwrites a user's edit.
	 *
	 * @return array<int, string>
	 */
	public static function ownership_states() {
		return Sync_Conflict_Detector::OWNERSHIP;
	}

	/**
	 * Return the difference categories, read from Phase 5.
	 *
	 * @return array<int, string>
	 */
	public static function categories() {
		return Validation_Limits::CATEGORIES;
	}

	/**
	 * Return the severities, read from Phase 5.
	 *
	 * @return array<int, string>
	 */
	public static function severities() {
		return Validation_Limits::SEVERITIES;
	}

	/**
	 * Return the entity vocabulary for a site type, plus the common entities.
	 *
	 * @param string $site_type Detected site type.
	 * @return array<int, string>
	 */
	public static function entities_for( $site_type ) {
		$out = self::ENTITY_GROUPS['common'];
		$key = strtolower( trim( (string) $site_type ) );
		foreach ( self::ENTITY_GROUPS as $group => $members ) {
			if ( 'common' === $group || $key !== $group ) {
				continue;
			}
			$out = array_merge( $out, $members );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Return whether a value is a declared role.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_role( $value ) {
		return is_string( $value ) && in_array( $value, self::ROLES, true );
	}

	/**
	 * Return whether a value is a declared data type.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_data_type( $value ) {
		return is_string( $value ) && in_array( $value, self::DATA_TYPES, true );
	}

	/**
	 * Return whether a value is a declared action.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_action( $value ) {
		return is_string( $value ) && in_array( $value, self::ACTIONS, true );
	}

	/**
	 * Return whether a value is a declared risk.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_risk( $value ) {
		return is_string( $value ) && in_array( $value, self::RISKS, true );
	}

	/**
	 * Return whether a value marks absent data.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_missing( $value ) {
		return in_array( $value, self::NULL_MARKERS, true ); // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- a null marker may literally be null.
	}

	/**
	 * Return whether two data types can hold each other's values.
	 *
	 * §10 says do not map by name. This is the type-level half of that rule, and it is
	 * what refuses `product_price -> post_title` even though both are strings.
	 *
	 * @param string $source      Source data type.
	 * @param string $destination Destination data type.
	 * @return bool
	 */
	public static function types_compatible( $source, $destination ) {
		$source      = (string) $source;
		$destination = (string) $destination;

		if ( ! self::is_data_type( $source ) || ! self::is_data_type( $destination ) ) {
			return false;
		}
		if ( $source === $destination ) {
			return true;
		}

		// Text and richtext hold each other's values: a plain string is a valid
		// richtext, which is why this is the one widening that is safe.
		$textual = array( 'text' => array( 'text', 'richtext' ), 'richtext' => array( 'text', 'richtext' ) );
		if ( isset( $textual[ $source ] ) && in_array( $destination, $textual[ $source ], true ) ) {
			return true;
		}

		// A number is acceptable where a currency is expected, because a price with no
		// decimal part is still a number. The reverse is not: a currency string cannot
		// safely become a plain number field.
		if ( 'number' === $source && 'currency' === $destination ) {
			return true;
		}

		// A date may be stored as a number by some destinations, but not a *string*
		// field, because the string would then be interpreted as a date by whatever
		// renders it and the value would change meaning.
		if ( 'date' === $source && in_array( $destination, array( 'number' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Return whether a reconstruction mode requires explicit confirmation.
	 *
	 * §18 requires confirmation before source content is *replaced* with destination
	 * content. That is true of `dynamic_replica` and of any mapping inside a
	 * `hybrid_replica`; it is not true of `static_replica`, which replaces nothing.
	 *
	 * @param string $mode Reconstruction mode.
	 * @return bool
	 */
	public static function mode_requires_confirmation( $mode ) {
		return in_array( (string) $mode, array( 'dynamic_replica', 'hybrid_replica' ), true );
	}
}
