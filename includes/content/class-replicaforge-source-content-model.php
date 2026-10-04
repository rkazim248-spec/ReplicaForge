<?php
/**
 * Phase 14: the source content model.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and validates the §2 `14.0` source content model.
 *
 * ### Buckets, and why a role can appear in more than one
 *
 * §2 asks for a bucket per content type. The buckets are an *index*, not a partition: a
 * product name is in `content.products` *and* is reachable by its `content_id`, and the
 * mapper reads by id. A single partitioned list would force a choice between "the thing
 * is a product name" and "the thing is a product", and that choice is exactly the
 * judgement the role exists to defer to the mapping step.
 *
 * ### The rule this class enforces
 *
 * **Every item is either real or explicitly absent.** There is no third state. A field
 * the source page did not have does not appear in the model; a field that was looked
 * for and not found appears with a value from {@see Content_Limits::NULL_MARKERS} and a
 * reason. Nothing is ever filled with a plausible value, and {@see self::validate()}
 * is written so that a fabricated item fails rather than passes.
 */
final class Source_Content_Model {

	/**
	 * Which role belongs in which §2 bucket.
	 *
	 * A role may appear in more than one bucket, which is the point: `product_name` is a
	 * product field *and* a heading, and which of those it is depends on the mapping.
	 *
	 * @var array<string, array<int, string>>
	 */
	const BUCKETS = array(
		'headings'   => array( 'hero_heading', 'section_heading', 'blog_title', 'product_name', 'feature_title', 'site_name' ),
		'paragraphs' => array( 'hero_description', 'body_text', 'feature_description', 'product_description', 'blog_excerpt', 'review_text', 'footer_text' ),
		'buttons'    => array( 'button', 'hero_cta' ),
		'links'      => array( 'social_link' ),
		'images'     => array( 'product_image', 'site_logo', 'product_gallery' ),
		'products'   => array( 'product_name', 'product_price', 'product_sale_price', 'product_description', 'product_category', 'product_rating', 'product_image' ),
		'categories' => array( 'product_category', 'blog_category' ),
		'reviews'    => array( 'review_author', 'review_text', 'product_rating' ),
		'authors'    => array( 'blog_author', 'review_author' ),
		'dates'      => array( 'blog_date' ),
		'prices'     => array( 'product_price', 'product_sale_price' ),
		'metadata'   => array( 'site_name', 'site_logo' ),
		'forms'      => array(),
	);

	/**
	 * Content items, keyed by `content_id`.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $items = array();

	/**
	 * Entities.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $entities = array();

	/**
	 * Relationships between items and entities.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $relationships = array();

	/**
	 * Dynamic regions, carried from Phase 13.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $dynamic_regions = array();

	/**
	 * Source descriptor.
	 *
	 * @var array<string, mixed>
	 */
	private $source = array();

	/**
	 * Structured-data read result.
	 *
	 * @var array<string, mixed>
	 */
	private $structured = array();

	/**
	 * Confidence summary.
	 *
	 * @var array<string, mixed>
	 */
	private $confidence = array();

	/**
	 * Warnings.
	 *
	 * @var array<int, string>
	 */
	private $warnings = array();

	/**
	 * Validation problems.
	 *
	 * @var array<int, string>
	 */
	private $errors = array();

	/**
	 * Build the model from a role detection and a structured-data read.
	 *
	 * @param array<string, mixed> $items      Content items from the role detector.
	 * @param array<string, mixed> $structured  Structured-data read result.
	 * @param array<string, mixed> $source      Source descriptor.
	 * @param array<int, mixed>     $dynamic     Phase 13 dynamic regions.
	 * @return array<string, mixed>
	 */
	public function build( array $items, array $structured = array(), array $source = array(), array $dynamic = array() ) {
		$this->items          = array();
		$this->entities       = (array) ( $structured['entities'] ?? array() );
		$this->structured     = $structured;
		$this->source         = $source;
		$this->dynamic_regions = $dynamic;
		$this->relationships  = array();
		$this->warnings       = array();
		$this->errors         = array();

		$page_type  = (string) ( $structured['page_type']['type'] ?? 'unknown' );
		$entity_type = $this->entity_type_for( $page_type );

		foreach ( $items as $item ) {
			$item = (array) $item;

			// §13: an item with no role, no value, and no evidence is not content. It is
			// dropped and counted, because a model padded with empty records looks
			// complete and is not.
			if ( ! Content_Limits::is_role( $item['role'] ?? '' ) ) {
				$this->warnings[] = __( 'Some elements had no mappable role and were left out of the content model rather than assigned an invented one.', 'replicaforge' );
				continue;
			}

			$id = (string) ( $item['content_id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}

			$normalised = $this->normalise_item( $item, $entity_type );
			if ( isset( $this->items[ $id ] ) ) {
				// A duplicate content_id means two elements produced the same identity.
				// The first wins and the duplicate is recorded, because silently merging
				// two elements into one record would lose one of them without saying so.
				$normalised['duplicate_of'] = $id;
				$this->warnings[] = __( 'Two elements produced the same content identity. The first was kept and the duplicate recorded.', 'replicaforge' );
			}
			$this->items[ $id ] = $normalised;

			$this->relationships[] = array(
				'from'      => $id,
				'to'        => (string) ( $normalised['entity_id'] ?? '' ),
				'type'      => 'describes',
				'entity'    => (string) $entity_type,
				'evidence'  => array( 'role=' . $normalised['role'] ),
			);
		}

		// Assign each item to a declared entity where one exists on the page, so
		// `relationships` is not a list of empty edges.
		$this->attach_entities( $entity_type );

		$model = $this->to_array();

		$verdict = $this->validate( $model );
		$this->confidence = $verdict['confidence'];

		return $this->to_array();
	}

	/**
	 * Return the model as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		$content = array();
		foreach ( self::BUCKETS as $bucket => $roles ) {
			$content[ $bucket ] = array();
			foreach ( $this->items as $id => $item ) {
				if ( in_array( (string) $item['role'], $roles, true ) ) {
					$content[ $bucket ][] = $id;
				}
			}
		}

		$warnings = $this->warnings;
		foreach ( (array) ( $this->structured['warnings'] ?? array() ) as $warning ) {
			$warnings[] = (string) $warning;
		}

		return array(
			'schema_version'   => Content_Limits::SCHEMA_VERSION,
			'source'           => array_merge(
				array(
					'url'          => '',
					'page_id'      => '',
					'content_hash' => '',
				),
				$this->source
			),
			'content'          => $content,
			'items'            => $this->items,
			'entities'         => $this->entities,
			'relationships'    => $this->relationships,
			'dynamic_regions'  => $this->dynamic_regions,
			'page_type'        => (string) ( $this->structured['page_type']['type'] ?? 'unknown' ),
			'confidence'       => $this->confidence,
			'warnings'         => array_values( array_unique( $warnings ) ),
			// The single most important field in the model.
			'fabrication'      => array(
				'policy'    => 'never_invent',
				'sentinels' => Content_Limits::NULL_MARKERS,
				'note'      => __( 'A value in this model either came from the source page or is one of the markers for "not detected". ReplicaForge does not generate content.', 'replicaforge' ),
			),
		);
	}

	/**
	 * Return one item.
	 *
	 * @param string $content_id Content id.
	 * @return array<string, mixed>|null
	 */
	public function item( $content_id ) {
		return $this->items[ (string) $content_id ] ?? null;
	}

	/**
	 * Return the item ids in a bucket.
	 *
	 * @param string $bucket Bucket name.
	 * @return array<int, string>
	 */
	public function bucket( $bucket ) {
		$roles = self::BUCKETS[ (string) $bucket ] ?? null;
		if ( null === $roles ) {
			return array();
		}
		$out = array();
		foreach ( $this->items as $id => $item ) {
			if ( in_array( (string) $item['role'], $roles, true ) ) {
				$out[] = (string) $id;
			}
		}
		return $out;
	}

	/**
	 * Validate the model.
	 *
	 * Written so a fabricated value *fails*. An item with a value that is neither a real
	 * value nor a declared marker is an error, and an item with a role but no traceable
	 * evidence is an error. That is §13 turned into a check rather than a promise.
	 *
	 * @param array<string, mixed> $model Model.
	 * @return array<string, mixed>
	 */
	public function validate( array $model = array() ) {
		$model = ( array() === $model ) ? $this->to_array() : $model;
		$this->errors = array();

		if ( Content_Limits::SCHEMA_VERSION !== (string) ( $model['schema_version'] ?? '' ) ) {
			$this->errors[] = 'invalid_schema_version';
		}
		$required = array( 'source', 'content', 'items', 'entities', 'relationships', 'dynamic_regions', 'confidence', 'warnings', 'fabrication' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $model ) ) {
				$this->errors[] = 'missing_' . $key;
			}
		}

		$roles     = 0;
		$absent    = 0;
		$sum       = 0.0;
		$classified = 0;

		foreach ( (array) ( $model['items'] ?? array() ) as $id => $item ) {
			$item = (array) $item;

			if ( ! Content_Limits::is_role( $item['role'] ?? '' ) ) {
				$this->errors[] = 'item_unknown_role';
				continue;
			}
			$roles++;

			$value = $item['value'] ?? null;
			if ( Content_Limits::is_missing( $value ) ) {
				// A declared absence. Legitimate, and counted separately so a report can
				// say "12 fields were not found" rather than implying 12 real values.
				$absent++;
			} elseif ( ! is_string( $value ) && ! is_numeric( $value ) ) {
				// A structured value where a scalar is required. This is the shape a
				// fabricated record usually takes, so it is an error, not a warning.
				$this->errors[] = 'item_value_not_scalar';
			}

			if ( ! Content_Limits::is_data_type( $item['data_type'] ?? '' ) ) {
				$this->errors[] = 'item_unknown_data_type';
			}
			if ( empty( $item['evidence'] ) || ! is_array( $item['evidence'] ) ) {
				// §51's rule, applied to content: an inference with no basis is not an
				// inference, it is an assertion.
				$this->errors[] = 'item_without_evidence';
			}
			if ( ! in_array( (string) ( $item['is_dynamic'] ?? '' ), Content_Limits::DYNAMIC_CLASSES, true ) ) {
				$this->errors[] = 'item_unknown_dynamism';
			}

			$confidence = isset( $item['confidence'] ) && is_numeric( $item['confidence'] ) ? (float) $item['confidence'] : 0.0;
			$sum       += $confidence;
			if ( $confidence > 0.0 ) {
				$classified++;
			}
		}

		foreach ( (array) ( $model['entities'] ?? array() ) as $entity ) {
			if ( ! is_array( $entity ) ) {
				$this->errors[] = 'entity_not_object';
				continue;
			}
			if ( empty( $entity['evidence'] ) ) {
				// §5: an entity without evidence is a pattern match, and §5 forbids
				// treating a pattern as a fact.
				$this->errors[] = 'entity_without_evidence';
			}
			if ( ! empty( $entity['verified'] ) ) {
				// A source-page entity can never be *verified*. Verification is a
				// destination-side concept, and a model that claims one is claiming
				// something it did not check.
				$this->errors[] = 'entity_claims_verification';
			}
		}

		$total = max( 1, $roles );
		$confidence = array(
			'items'          => $roles,
			'classified'     => $classified,
			'absent'         => $absent,
			'mean'           => round( $sum / $total, 3 ),
			'entities'       => count( (array) ( $model['entities'] ?? array() ) ),
			'page_type'      => (string) ( $model['page_type'] ?? 'unknown' ),
			// §6: a page whose type could not be determined has content whose behaviour
			// is equally undetermined, and the model must say so rather than assume
			// static and let a sync overwrite it later.
			'dynism_known'   => ( 'unknown' !== (string) ( $model['page_type'] ?? 'unknown' ) ),
		);

		return array(
			'valid'      => ( 0 === count( $this->errors ) ),
			'errors'     => $this->errors,
			'warnings'   => array_values( array_unique( $this->warnings ) ),
			'confidence' => $confidence,
			'counts'     => array(
				'items'    => $roles,
				'absent'   => $absent,
				'entities' => count( (array) ( $model['entities'] ?? array() ) ),
			),
		);
	}

	/**
	 * Return the validation errors.
	 *
	 * @return array<int, string>
	 */
	public function get_validation_errors() {
		return $this->errors;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Normalise one item into the model's shape.
	 *
	 * @param array<string, mixed> $item        Item.
	 * @param string               $entity_type Entity type.
	 * @return array<string, mixed>
	 */
	private function normalise_item( array $item, $entity_type ) {
		$value = $item['value'] ?? '';

		// A value that is neither a scalar nor a marker is replaced by the marker. This
		// is the single most important line in the class: a role detector that returned
		// an array, or a nested object, cannot get that into the model, because the only
		// outcome is a declared absence.
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			$value = 'not_detected';
		}

		return array(
			'content_id'        => (string) ( $item['content_id'] ?? '' ),
			'role'              => (string) $item['role'],
			'data_type'         => (string) ( $item['data_type'] ?? 'text' ),
			'value'             => $value,
			'source_url'        => (string) ( $item['source_url'] ?? '' ),
			'section_id'        => (string) ( $item['section_id'] ?? '' ),
			'component_id'      => (string) ( $item['component_id'] ?? '' ),
			'element_reference' => (string) ( $item['element_reference'] ?? '' ),
			'fingerprint'       => (string) ( $item['fingerprint'] ?? Content_Fingerprint::of( (string) $item['role'], (string) $value ) ),
			'confidence'        => isset( $item['confidence'] ) && is_numeric( $item['confidence'] ) ? round( (float) $item['confidence'], 3 ) : 0.0,
			'source_type'       => (string) ( $item['source_type'] ?? '' ),
			'entity_type'       => (string) ( $item['entity_type'] ?? $entity_type ),
			'is_dynamic'        => (string) ( $item['is_dynamic'] ?? 'unknown' ),
			'dynamic_confidence'=> isset( $item['dynamic_confidence'] ) && is_numeric( $item['dynamic_confidence'] ) ? (float) $item['dynamic_confidence'] : 0.0,
			'dynamic_reason'    => (string) ( $item['dynamic_reason'] ?? '' ),
			'evidence'          => array_values( (array) ( $item['evidence'] ?? array() ) ),
			'entity_id'         => (string) ( $item['entity_id'] ?? '' ),
		);
	}

	/**
	 * Attach items to the declared entity on the page.
	 *
	 * @param string $entity_type Entity type.
	 * @return void
	 */
	private function attach_entities( $entity_type ) {
		if ( array() === $this->entities ) {
			return;
		}
		$primary = (string) $this->entities[0]['entity_id'];
		foreach ( $this->items as $id => $item ) {
			if ( '' === $item['entity_id'] ) {
				$this->items[ $id ]['entity_id'] = $primary;
			}
		}
	}

	/**
	 * Map a page type to an entity type.
	 *
	 * @param string $page_type Page type.
	 * @return string
	 */
	private function entity_type_for( $page_type ) {
		$map = array(
			'product_detail' => 'product',
			'blog_post'      => 'post',
			'listing'        => 'listing',
			'organization'   => 'organization',
			'archive'        => 'collection',
		);
		return (string) ( $map[ (string) $page_type ] ?? 'unknown' );
	}
}
