<?php
/**
 * Phase 14: the content mapping engine.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Decides where each piece of source content should come from in the destination.
 *
 * ### The rule §10 states, and how it is enforced
 *
> Do not map fields purely by name.
 *
 * Name matching is the obvious implementation and it is wrong in a way that costs money.
> A source `product_price` is a `text` field as far as a name comparison is concerned, so
 * `product_price` would map happily onto `post_title` — and a replica would then show
 * `$49.99` where a product's name belongs.
 *
 * So a mapping has to pass four gates, and all four are structural rather than advisory:
 *
 * 1. **Role.** The source role must be in the destination field's accepted role set. A
 *    `product_price` cannot reach a field that only accepts a `product_name`.
 * 2. **Data type.** {@see Content_Limits::types_compatible()} must hold, read from
 *    §10's own table. This is the gate that refuses `product_price -> post_title`, because
 *    `currency` and `text` are not compatible in that direction.
 * 3. **Availability and editability.** The destination field must exist and must say it
 *    can be written. A read-only field — a stock level, a computed rating — is refused,
 *    which is how §8's "do not modify inventory or prices" is enforced without anyone
 *    having to remember.
 * 4. **Ownership.** The destination field's ownership state decides whether a later sync
 *    may overwrite it, and a `user_controlled` destination is never auto-applied.
 *
 * ### Why the vocabulary is separate from the scoring
 *
 * {@see self::RULES} is the *only* place a role-to-field pair is written down, and it is
 * read rather than restated from anywhere. Adding a rule is one array entry. Two tables
 * of "what maps to what" would be two answers within a release, which is how a price
 * ends up in a description.
 *
 * ### Confidence is arithmetic, and it can be zero
 *
 * The score is a weighted sum of *named, checkable* evidence, and every weight is in
 * {@see self::WEIGHTS}. Two things follow. A mapping with no evidence scores 0 and is
 * rejected, which is §13 operating on the output side. And a mapping's confidence is
 * reproducible from its evidence list, so a user who disputes it can be shown exactly
 * which term is small.
 */
final class Content_Mapper {

	/**
	 * Evidence weights.
	 *
	 * Summed to 1.0 so a confidence is directly readable as "how much of the case is
	 * made". Role match and type compatibility are the two that cannot be faked, so they
	 * carry the most.
	 *
	 * @var array<string, float>
	 */
	const WEIGHTS = array(
		'role'          => 0.35,
		'data_type'     => 0.25,
		'exact_field'   => 0.20,
		'entity'        => 0.10,
		'page_context'  => 0.10,
	);

	/**
	 * The role-to-field rules.
	 *
	 * The single source of truth for what maps where. `roles` is the set of source roles
	 * the rule serves; `field` is the destination field name; `entity` is the destination
	 * entity type; `data_type` is the source data type the rule assumes.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	const RULES = array(
		// Ecommerce.
		array( 'field' => 'post_title',            'entity' => 'product', 'provider' => 'woocommerce', 'roles' => array( 'product_name' ),        'data_type' => 'text',     'ownership' => 'user_controlled',  'ownership_reason' => 'a product name is the store owner\'s copy, not the source site\'s' ),
		array( 'field' => '_regular_price',        'entity' => 'product', 'provider' => 'woocommerce', 'roles' => array( 'product_price' ),       'data_type' => 'currency',  'ownership' => 'user_controlled',  'ownership_reason' => 'a price is commercial data the store owner sets' ),
		array( 'field' => '_sale_price',           'entity' => 'product', 'provider' => 'woocommerce', 'roles' => array( 'product_sale_price' ),  'data_type' => 'currency',  'ownership' => 'user_controlled',  'ownership_reason' => 'a sale price is commercial data the store owner sets' ),
		array( 'field' => 'post_excerpt',          'entity' => 'product', 'provider' => 'woocommerce', 'roles' => array( 'product_description' ), 'data_type' => 'richtext', 'ownership' => 'mixed',            'ownership_reason' => 'a short description may be the source copy or the store owner\'s' ),
		array( 'field' => 'post_content',          'entity' => 'product', 'provider' => 'woocommerce', 'roles' => array( 'product_description' ), 'data_type' => 'richtext', 'ownership' => 'mixed',            'ownership_reason' => 'a long description may be the source copy or the store owner\'s' ),
		array( 'field' => '_featured_image_id',    'entity' => 'product', 'provider' => 'woocommerce', 'roles' => array( 'product_image' ),       'data_type' => 'image',    'ownership' => 'user_controlled',  'ownership_reason' => 'a product photo is the store owner\'s asset' ),
		array( 'field' => '_average_rating',       'entity' => 'product', 'provider' => 'woocommerce', 'roles' => array( 'product_rating' ),      'data_type' => 'rating',   'ownership' => 'user_controlled',  'ownership_reason' => 'a rating belongs to the reviews the store owner holds' ),

		// Blog.
		array( 'field' => 'post_title',            'entity' => 'post',    'provider' => 'wordpress',  'roles' => array( 'blog_title' ),         'data_type' => 'text',     'ownership' => 'user_controlled',  'ownership_reason' => 'a post title is the store owner\'s copy' ),
		array( 'field' => 'post_excerpt',          'entity' => 'post',    'provider' => 'wordpress',  'roles' => array( 'blog_excerpt' ),       'data_type' => 'richtext', 'ownership' => 'mixed',            'ownership_reason' => 'an excerpt may be the source copy or the owner\'s' ),
		array( 'field' => 'post_content',          'entity' => 'post',    'provider' => 'wordpress',  'roles' => array( 'body_text' ),          'data_type' => 'richtext', 'ownership' => 'mixed',            'ownership_reason' => 'body copy may be the source copy or the owner\'s' ),
		array( 'field' => 'post_date',             'entity' => 'post',    'provider' => 'wordpress',  'roles' => array( 'blog_date' ),          'data_type' => 'date',     'ownership' => 'user_controlled',  'ownership_reason' => 'a post date is editorial, not copied' ),
		array( 'field' => 'post_author',           'entity' => 'post',    'provider' => 'wordpress',  'roles' => array( 'blog_author' ),        'data_type' => 'relation', 'ownership' => 'user_controlled',  'ownership_reason' => 'authorship is the owner\'s decision' ),
		array( 'field' => 'featured_image',        'entity' => 'post',    'provider' => 'wordpress',  'roles' => array( 'product_image', 'site_logo' ), 'data_type' => 'image', 'ownership' => 'user_controlled', 'ownership_reason' => 'an image is the owner\'s asset' ),

		// Pages: the site-identity and page-copy fields. These are the *least* ownership
		// sensitive, and the most likely to be copied, because a replica's own identity is
		// the one thing that must not come from the source site.
		array( 'field' => 'post_title',            'entity' => 'page',    'provider' => 'wordpress',  'roles' => array( 'site_name', 'section_heading', 'hero_heading' ), 'data_type' => 'text',     'ownership' => 'user_controlled', 'ownership_reason' => 'a page title is the owner\'s copy' ),
		array( 'field' => 'post_content',          'entity' => 'page',    'provider' => 'wordpress',  'roles' => array( 'body_text', 'hero_description', 'feature_description' ), 'data_type' => 'richtext', 'ownership' => 'mixed',           'ownership_reason' => 'page copy may be imported once, then owned' ),
		array( 'field' => 'post_excerpt',          'entity' => 'page',    'provider' => 'wordpress',  'roles' => array( 'body_text' ),          'data_type' => 'richtext', 'ownership' => 'mixed',            'ownership_reason' => 'page copy may be imported once, then owned' ),

		// Contact details: these must be the *owner's*, and never the source site's.
		array( 'field' => 'post_excerpt',          'entity' => 'page',    'provider' => 'wordpress',  'roles' => array( 'phone', 'email', 'address' ), 'data_type' => 'text', 'ownership' => 'user_controlled', 'ownership_reason' => 'contact details belong to the owner; the source site\'s are not the owner\'s' ),
	);

	/**
	 * Destination providers, by id.
	 *
	 * @var array<string, Content_Provider_Contract>
	 */
	private $providers = array();

	/**
	 * Register a provider.
	 *
	 * @param Content_Provider_Contract $provider Provider.
	 * @return void
	 */
	public function add_provider( Content_Provider_Contract $provider ) {
		$this->providers[ $provider->id() ] = $provider;
	}

	/**
	 * Return the registered providers and their availability.
	 *
	 * @return array<string, mixed>
	 */
	public function providers() {
		$out = array();
		foreach ( $this->providers as $id => $provider ) {
			$out[ $id ] = array(
				'id'          => (string) $id,
				'available'   => (bool) $provider->is_available(),
				'reason'      => (string) $provider->unavailable_reason(),
				'version'     => (string) $provider->version(),
				'entity_types'=> array_values( (array) $provider->entity_types() ),
			);
		}
		return $out;
	}

	/**
	 * Map a source content model onto the registered destination providers.
	 *
	 * @param array<string, mixed> $model      Source content model.
	 * @param array<string, mixed> $context    Mapping context.
	 * @return array<int, array<string, mixed>>
	 */
	public function map( array $model, array $context = array() ) {
		$page_type   = (string) ( $model['page_type'] ?? 'unknown' );
		$project_id  = (string) ( $context['project_id'] ?? '' );
		$page_id     = (string) ( $context['page_id'] ?? '' );
		$mode        = (string) ( $context['mode'] ?? 'hybrid_replica' );
		$mappings    = array();

		foreach ( (array) ( $model['items'] ?? array() ) as $content_id => $item ) {
			$item = (array) $item;

			// An absent value has nothing to map. It is recorded as `unmapped` with the
			// sentinel as its value, so the mapping screen can list it rather than it
			// simply being invisible.
			if ( Content_Limits::is_missing( $item['value'] ?? '' ) ) {
				$mappings[] = $this->mapping(
					$project_id,
					$page_id,
					(string) $content_id,
					$item,
					array(),
					'unmapped',
					0.0,
					'blocked',
					array( 'the source has no value for this field' ),
					array( 'no_value' ),
					$mode
				);
				continue;
			}

			foreach ( $this->candidates( (string) $item['role'] ) as $candidate ) {
				$mapping = $this->evaluate( $item, $candidate, $project_id, $page_id, $page_type, $mode );
				if ( null === $mapping ) {
					continue;
				}
				$mappings[] = $mapping;
				if ( count( $mappings ) >= Content_Limits::MAX_MAPPINGS ) {
					break 2;
				}
			}

			// A classified, present, mappable field with no candidate at all is a real
			// finding, not a silence.
			if ( ! $this->has_candidate( $mappings, (string) $content_id ) ) {
				$mappings[] = $this->mapping(
					$project_id,
					$page_id,
					(string) $content_id,
					$item,
					array(),
					'unmapped',
					0.0,
					'low',
					array( __( 'No destination field accepts this role and data type.', 'replicaforge' ) ),
					array( 'no_destination' ),
					$mode
				);
			}
		}

		return $mappings;
	}

	/**
	 * Return the rule candidates for a role.
	 *
	 * Filtered by role only. The data type is deliberately *not* filtered here, because
	 * {@see self::evaluate()} scores a type mismatch as a rejected mapping with a stated
	 * reason. Filtering it out earlier would make a `product_image` role carrying a `text`
	 * value silently produce no mapping at all, and "no mapping" is indistinguishable
	 * from "we did not look".
	 *
	 * @param string $role Source role.
	 * @return array<int, array<string, mixed>>
	 */
	private function candidates( $role ) {
		$out = array();
		foreach ( self::RULES as $index => $rule ) {
			if ( in_array( $role, (array) $rule['roles'], true ) ) {
				$out[] = array( 'rule' => $rule, 'index' => $index );
			}
		}
		return $out;
	}

	/**
	 * Evaluate one candidate into a mapping, or reject it.
	 *
	 * @param array<string, mixed> $item      Source item.
	 * @param array<string, mixed> $candidate Candidate rule.
	 * @param string               $project_id Project id.
	 * @param string               $page_id    Page id.
	 * @param string               $page_type  Page type.
	 * @param string               $mode       Reconstruction mode.
	 * @return array<string, mixed>|null
	 */
	private function evaluate( array $item, array $candidate, $project_id, $page_id, $page_type, $mode ) {
		$rule      = (array) $candidate['rule'];
		$evidence  = array();
		$score     = 0.0;
		$provider  = (string) $rule['provider'];
		$entity    = (string) $rule['entity'];
		$field     = (string) $rule['field'];

		// Gate 3a: the provider must exist and be available. An unavailable WooCommerce
		// means a product has *no destination*, which is a reported blocked mapping, not
		// a silent skip and not a fallback onto a WordPress field.
		$instance = $this->providers[ $provider ] ?? null;
		if ( ! $instance instanceof Content_Provider_Contract ) {
			// A rule naming a provider nobody registered is a *different* fault from a
			// provider that is installed-but-off, and it is reported separately rather
			// than skipped. The first draft returned `null` here, which meant a rule set
			// that drifted out of sync with the registry lost those mappings with no
			// trace at all — the same silence as "this page has no products", and for a
			// reason that is entirely within ReplicaForge's control and therefore
			// ReplicaForge's bug to report.
			return $this->mapping(
				$project_id,
				$page_id,
				(string) $item['content_id'],
				$item,
				array( 'provider' => $provider, 'entity' => $entity, 'field' => $field ),
				'unmap',
				0.0,
				'blocked',
				array( sprintf( 'No %s provider is registered in this build, so there is nowhere to map this value.', $provider ) ),
				array( 'provider_unregistered=' . $provider ),
				$mode
			);
		}
		if ( ! $instance->is_available() ) {
			return $this->mapping(
				$project_id,
				$page_id,
				(string) $item['content_id'],
				$item,
				array( 'provider' => $provider, 'entity' => $entity, 'field' => $field ),
				'unmap',
				0.0,
				'blocked',
				array( $instance->unavailable_reason() ),
				array( 'provider_unavailable=' . $provider ),
				$mode
			);
		}

		// Gate 3b: the field must exist and be writable on that provider.
		$destination = $this->field_descriptor( $instance, $entity, $field );
		if ( null === $destination ) {
			return null;
		}
		if ( empty( $destination['editable'] ) ) {
			// §8: read-only fields are refused here, not at apply time. A stock level or
			// a computed rating never becomes a mapping target.
			return $this->mapping(
				$project_id,
				$page_id,
				(string) $item['content_id'],
				$item,
				array( 'provider' => $provider, 'entity' => $entity, 'field' => $field ),
				'ignore',
				0.0,
				'blocked',
				array( (string) ( $destination['note'] ?? __( 'That destination field cannot be written.', 'replicaforge' ) ) ),
				array( 'field_read_only=' . $field ),
				$mode
			);
		}

		// Gate 1: role.
		$score    += self::WEIGHTS['role'];
		$evidence[] = sprintf( 'source_role=%s is accepted by the rule for %s', (string) $item['role'], $field );

		// Gate 2: data type.
		$source_type = (string) ( $item['data_type'] ?? 'text' );
		$dest_type   = (string) ( $destination['data_type'] ?? 'text' );
		if ( ! Content_Limits::types_compatible( $source_type, $dest_type ) ) {
			// Rejected outright, with the reason naming the two types. This is the gate
			// that makes "map by name" impossible: `currency` into `text` is refused even
			// though both are strings and the names line up.
			return $this->mapping(
				$project_id,
				$page_id,
				(string) $item['content_id'],
				$item,
				array( 'provider' => $provider, 'entity' => $entity, 'field' => $field ),
				'review',
				0.0,
				'blocked',
				array( sprintf( 'The source value is %s and the destination field is %s, which cannot hold it.', $source_type, $dest_type ) ),
				array( 'data_type_mismatch', 'source_type=' . $source_type, 'destination_type=' . $dest_type ),
				$mode
			);
		}
		$score    += self::WEIGHTS['data_type'];
		$evidence[] = sprintf( 'data_type=%s is compatible with %s', $source_type, $dest_type );

		// Exact field-name agreement. Not required, but it is the difference between a
		// rule written for this pair and a rule that happens to share a role.
		$rule_type = (string) $rule['data_type'];
		if ( $rule_type === $source_type ) {
			$score    += self::WEIGHTS['exact_field'];
			$evidence[] = 'rule_written_for_this_type';
		} else {
			$evidence[] = sprintf( 'rule_assumed_%s_but_source_is_%s', $rule_type, $source_type );
		}

		// Entity agreement.
		$source_entity = (string) ( $item['entity_type'] ?? 'unknown' );
		if ( $source_entity === $entity || ( 'unknown' === $source_entity ) ) {
			$score    += self::WEIGHTS['entity'];
			$evidence[] = 'entity_type=' . ( 'unknown' === $source_entity ? 'unverified' : $entity );
		} else {
			$evidence[] = sprintf( 'entity_type_differs: source says %s, rule targets %s', $source_entity, $entity );
		}

		// Page context. A product field on a product page is expected; on a contact page
		// it is a mis-detection, and saying so is useful.
		$context_ok = $this->context_matches( $entity, $page_type );
		if ( $context_ok ) {
			$score    += self::WEIGHTS['page_context'];
			$evidence[] = 'page_context=' . $page_type;
		} else {
			$evidence[] = sprintf( 'page_context_%s_is_unusual_for_a_%s', $page_type, $entity );
		}

		$confidence = round( min( 1.0, $score ), 3 );
		$ownership  = (string) ( $rule['ownership'] ?? 'unknown' );
		$risk       = $this->risk_for( $confidence, $ownership, $item, $page_type, $context_ok );
		$action     = 'map';
		$review     = false;

		if ( $confidence < Content_Limits::REJECT_CONFIDENCE ) {
			// Below the floor the mapping is *wrong*, not uncertain, and offering it
			// wastes a reviewer's attention on a suggestion with no evidence behind it.
			$action = 'unmap';
			$risk   = 'low';
			$evidence[] = 'rejected: confidence below the rejection floor';
		} elseif ( $confidence < Content_Limits::AUTO_APPLY_CONFIDENCE || 'high' === $risk || 'blocked' === $risk ) {
			// §11: high-risk mappings require human review, and so does anything not
			// confident enough to apply on its own.
			$action = 'review';
			$review = true;
		}

		// §18: a mode that replaces source content with destination content requires
		// explicit confirmation, and a mapping inside one carries that until it is given.
		if ( Content_Limits::mode_requires_confirmation( $mode ) ) {
			$review = true;
			$evidence[] = 'mode=' . $mode . ' requires confirmation before source content is replaced with destination content';
		}

		return $this->mapping(
			$project_id,
			$page_id,
			(string) $item['content_id'],
			$item,
			array( 'provider' => $provider, 'entity' => $entity, 'field' => $field, 'data_type' => $dest_type ),
			$action,
			$confidence,
			$risk,
			array( (string) ( $rule['ownership_reason'] ?? '' ) ),
			$evidence,
			$mode,
			$ownership,
			$review,
			0,
			$context_ok
		);
	}

	/**
	 * Return a provider's field descriptor, or null.
	 *
	 * @param Content_Provider_Contract $provider Provider.
	 * @param string                    $entity   Entity type.
	 * @param string                    $field    Field name.
	 * @return array<string, mixed>|null
	 */
	private function field_descriptor( Content_Provider_Contract $provider, $entity, $field ) {
		foreach ( (array) $provider->fields_for( $entity ) as $descriptor ) {
			if ( is_array( $descriptor ) && (string) ( $descriptor['field'] ?? '' ) === (string) $field ) {
				return $descriptor;
			}
		}
		return null;
	}

	/**
	 * Return whether a page type is expected for an entity type.
	 *
	 * @param string $entity    Entity type.
	 * @param string $page_type Page type.
	 * @return bool
	 */
	private function context_matches( $entity, $page_type ) {
		$map = array(
			'product'       => array( 'product_detail', 'archive', 'listing', 'collection', 'unknown' ),
			'post'          => array( 'blog_post', 'archive', 'unknown' ),
			'page'          => array( 'site', 'unknown', 'organization', 'blog_post' ),
			'listing'       => array( 'listing', 'archive', 'unknown' ),
		);
		$expected = $map[ (string) $entity ] ?? null;
		if ( null === $expected ) {
			return false;
		}
		return in_array( (string) $page_type, $expected, true );
	}

	/**
	 * Return the §11 risk for a mapping.
	 *
	 * Risk is *not* derived from confidence. A mapping can be highly confident and still
	 * dangerous — an exact role match onto a price is a certainty and a real-world cost —
	 * and a mapping can be uncertain and harmless. They are independent axes and the
	 * highest of the two wins.
	 *
	 * @param float                $confidence  Confidence.
	 * @param string               $ownership   Ownership state.
	 * @param array<string, mixed> $item        Source item.
	 * @param string               $page_type   Page type.
	 * @param bool                 $context_ok  Context agreement.
	 * @return string
	 */
	private function risk_for( $confidence, $ownership, array $item, $page_type, $context_ok ) {
		$risk = 'low';

		// A price is the one field where a wrong value costs money, so it is high risk
		// regardless of how confident the mapping is.
		if ( in_array( (string) ( $item['role'] ?? '' ), Content_Limits::PRICE_ROLES, true ) ) {
			$risk = 'high';
		}
		// Contact details must be the owner's. Mapping the source site's phone number
		// onto a replica is a misdirection as well as a mistake.
		if ( in_array( (string) ( $item['role'] ?? '' ), array( 'phone', 'email', 'address' ), true ) ) {
			$risk = 'high';
		}
		if ( 'user_controlled' === $ownership && $confidence >= Content_Limits::AUTO_APPLY_CONFIDENCE ) {
			// Auto-applying onto a field the sync layer considers user-owned is exactly
			// where a Phase 9 overwrite would come from, so it is never automatic.
			$risk = ( 'high' === $risk ) ? 'high' : 'medium';
		}
		if ( ! $context_ok ) {
			$risk = ( 'low' === $risk ) ? 'medium' : $risk;
		}

		return (string) $risk;
	}

	/**
	 * Return whether a content id already has a live candidate.
	 *
	 * @param array<int, array<string, mixed>> $mappings   Mappings so far.
	 * @param string                           $content_id Content id.
	 * @return bool
	 */
	private function has_candidate( array $mappings, $content_id ) {
		foreach ( $mappings as $mapping ) {
			if ( (string) ( $mapping['source']['content_id'] ?? '' ) === $content_id && 'unmapped' !== (string) ( $mapping['action'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build a mapping record in the §11/§15 shape.
	 *
	 * @param string               $project_id  Project id.
	 * @param string               $page_id     Page id.
	 * @param string               $content_id  Content id.
	 * @param array<string, mixed> $item        Source item.
	 * @param array<string, mixed> $destination Destination descriptor.
	 * @param string               $action      Action.
	 * @param float                $confidence  Confidence.
	 * @param string               $risk        Risk.
	 * @param array<int, string>   $warnings    Warnings.
	 * @param array<int, string>   $evidence    Evidence.
	 * @param string               $mode        Reconstruction mode.
	 * @param string               $ownership   Ownership state.
	 * @param bool                 $review      Whether review is required.
	 * @param int                  $entity_id   Destination entity id.
	 * @param bool                 $context_ok  Context agreement.
	 * @return array<string, mixed>
	 */
	private function mapping( $project_id, $page_id, $content_id, array $item, array $destination, $action, $confidence, $risk, array $warnings, array $evidence, $mode, $ownership = 'unknown', $review = false, $entity_id = 0, $context_ok = true ) {
		// A destination record must name a provider, an entity, and a field. Without all
		// three there is nothing to apply, and a half-built destination is how a mapping
		// ends up writing "somewhere".
		$complete = ( '' !== (string) ( $destination['provider'] ?? '' ) )
			&& ( '' !== (string) ( $destination['entity'] ?? '' ) )
			&& ( '' !== (string) ( $destination['field'] ?? '' ) );

		return array(
			'schema_version'     => Content_Limits::SCHEMA_VERSION,
			'mapping_id'         => 'map_' . substr( md5( $project_id . '|' . $page_id . '|' . $content_id . '|' . (string) ( $destination['field'] ?? '' ) ), 0, 16 ),
			'project_id'         => (string) $project_id,
			'source'             => array(
				'page_id'            => (string) $page_id,
				'content_id'         => (string) $content_id,
				'role'               => (string) ( $item['role'] ?? '' ),
				'data_type'          => (string) ( $item['data_type'] ?? 'text' ),
				'value'              => $item['value'] ?? Content_Limits::NULL_MARKERS[0],
				'fingerprint'        => (string) ( $item['fingerprint'] ?? '' ),
				'source_url'         => (string) ( $item['source_url'] ?? '' ),
				'section_id'         => (string) ( $item['section_id'] ?? '' ),
				'component_id'       => (string) ( $item['component_id'] ?? '' ),
				'element_reference'  => (string) ( $item['element_reference'] ?? '' ),
				'is_dynamic'         => (string) ( $item['is_dynamic'] ?? 'unknown' ),
			),
			'destination'        => array(
				'provider'   => (string) ( $destination['provider'] ?? '' ),
				'entity'     => (string) ( $destination['entity'] ?? '' ),
				'entity_id'  => (int) $entity_id,
				'field'      => (string) ( $destination['field'] ?? '' ),
				'data_type'  => (string) ( $destination['data_type'] ?? '' ),
				'complete'   => (bool) $complete,
			),
			'confidence'         => (float) $confidence,
			'risk'               => (string) $risk,
			'ownership'          => (string) $ownership,
			'action'             => (string) $action,
			'requires_review'    => (bool) ( $review || 'high' === $risk || 'blocked' === $risk ),
			'mode'               => (string) $mode,
			'context_agrees'     => (bool) $context_ok,
			'evidence'           => array_values( $evidence ),
			'warnings'           => array_values( array_filter( $warnings ) ),
			'provenance'         => array(
				'source_url'        => (string) ( $item['source_url'] ?? '' ),
				'source_content_id' => (string) $content_id,
				'source_component_id' => (string) ( $item['component_id'] ?? '' ),
				'content_hash'      => (string) ( $item['fingerprint'] ?? '' ),
				'recorded_at'       => time(),
			),
		);
	}
}
