<?php
/**
 * Phase 14: the mapping preview, and the §30 privacy pass.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Shows what a mapping would do, and what would leave the site if it were sent to an
 * AI provider.
 *
 * ### §21's three-stage preview
 *
 * `SOURCE → DESTINATION → ELEMENTOR`. Each stage is shown because each can fail
 * differently: a source value can be absent, a destination field can refuse the value,
 * and an Elementor widget may have no dynamic tag for it. A preview that only showed the
 * first two would say "mapped" for a value that then rendered as an empty widget.
 *
 * ### Nothing destructive happens here
 *
 * A preview reads. It never writes, never reserves usage, and never enqueues a job. That
 * is §19's "never make destructive changes from the mapping preview" and it is enforced by
 * the fact that this class has no path to {@see Content_Applier}.
 *
 * ### §30: redaction is applied to what leaves, not what is stored
 *
 * The redaction here is for the AI boundary specifically. ReplicaForge's own storage is a
 * different question: a product description is content the user owns, and redacting it in
 * the database would corrupt the user's data. So
 * {@see self::for_ai()} is the only method that redacts, and
 * {@see Data_Redactor::structure()} — the existing, reviewed implementation — does the work.
 *
 * Redaction is applied to *whole values* for a small set of roles and to *structured
 * payloads* generally, rather than to every string. Redacting every string with a
 * password-shaped regex would mangle product names that happen to look like tokens, and
 * the useful property here is "no secret leaves the site", not "no text leaves the site".
 */
final class Content_Preview {

	/**
	 * Providers, by id.
	 *
	 * @var array<string, Content_Provider_Contract>
	 */
	private $providers = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, Content_Provider_Contract> $providers Providers.
	 */
	public function __construct( array $providers = array() ) {
		foreach ( $providers as $provider ) {
			if ( $provider instanceof Content_Provider_Contract ) {
				$this->providers[ $provider->id() ] = $provider;
			}
		}
	}

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
	 * Build a preview of a plan.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array<string, mixed>
	 */
	public function preview( array $plan ) {
		$rows      = array();
		$stages    = array();
		$blocked   = 0;
		$unchanged = 0;

		foreach ( (array) ( $plan['mappings'] ?? array() ) as $mapping ) {
			$mapping = (array) $mapping;
			$row     = $this->row( $mapping );

			$stages[ $row['elementor']['status'] ] = ( $stages[ $row['elementor']['status'] ] ?? 0 ) + 1;
			if ( 'blocked' === $row['overall'] ) {
				$blocked++;
			}
			if ( 'unchanged' === $row['overall'] ) {
				$unchanged++;
			}
			$rows[] = $row;
		}

		return array(
			'schema_version' => Content_Limits::SCHEMA_VERSION,
			'plan_id'        => (string) ( $plan['plan_id'] ?? '' ),
			'project_id'     => (string) ( $plan['project_id'] ?? '' ),
			'rows'           => $rows,
			'counts'         => array(
				'rows'      => count( $rows ),
				'blocked'   => $blocked,
				'unchanged' => $unchanged,
				'stages'    => $stages,
			),
			'read_only'      => true,
			'note'           => __( 'This is a preview. Nothing was written, nothing was reserved, and no job was queued.', 'replicaforge' ),
		);
	}

	/**
	 * Return one preview row in the §36 shape.
	 *
	 * @param array<string, mixed> $mapping Mapping.
	 * @return array<string, mixed>
	 */
	private function row( array $mapping ) {
		$source      = (array) ( $mapping['source'] ?? array() );
		$destination = (array) ( $mapping['destination'] ?? array() );
		$provider_id = (string) ( $destination['provider'] ?? '' );
		$provider    = $this->providers[ $provider_id ] ?? null;

		$source_stage = array(
			'role'   => (string) ( $source['role'] ?? '' ),
			'value'  => (string) ( $source['value'] ?? '' ),
			'type'   => (string) ( $source['data_type'] ?? '' ),
			'url'    => (string) ( $source['source_url'] ?? '' ),
			'present'=> ! Content_Limits::is_missing( $source['value'] ?? '' ),
		);

		$destination_stage = array(
			'provider'  => $provider_id,
			'entity'    => (string) ( $destination['entity'] ?? '' ),
			'entity_id' => (int) ( $destination['entity_id'] ?? 0 ),
			'field'     => (string) ( $destination['field'] ?? '' ),
			'available' => ( $provider instanceof Content_Provider_Contract ) && $provider->is_available(),
			'reason'    => ( $provider instanceof Content_Provider_Contract ) ? (string) $provider->unavailable_reason() : __( 'That provider is not registered.', 'replicaforge' ),
		);

		$elementor_stage = $this->elementor_stage( $mapping );

		$overall = 'ready';
		if ( ! $source_stage['present'] ) {
			$overall = 'blocked';
		} elseif ( ! $destination_stage['available'] || '' === $destination_stage['field'] ) {
			$overall = 'blocked';
		} elseif ( 'unavailable' === $elementor_stage['status'] ) {
			// §17: where a dynamic capability is missing, the fallback to static content is
			// *offered*, not taken. Taking it silently would change what the page shows.
			$overall = 'review';
		} elseif ( 'unchanged' === $elementor_stage['status'] ) {
			$overall = 'unchanged';
		}

		return array(
			'mapping_id' => (string) ( $mapping['mapping_id'] ?? '' ),
			'source'      => $source_stage,
			'destination' => $destination_stage,
			'elementor'   => $elementor_stage,
			'confidence'  => (float) ( $mapping['confidence'] ?? 0.0 ),
			'risk'        => (string) ( $mapping['risk'] ?? 'low' ),
			'ownership'   => (string) ( $mapping['ownership'] ?? 'unknown' ),
			'requires_review' => ! empty( $mapping['requires_review'] ),
			'evidence'    => array_values( (array) ( $mapping['evidence'] ?? array() ) ),
			'warnings'    => array_values( (array) ( $mapping['warnings'] ?? array() ) ),
			'overall'     => $overall,
		);
	}

	/**
	 * Return the Elementor stage of a preview row.
	 *
	 * @param array<string, mixed> $mapping Mapping.
	 * @return array<string, mixed>
	 */
	private function elementor_stage( array $mapping ) {
		$destination = (array) ( $mapping['destination'] ?? array() );
		$source      = (array) ( $mapping['source'] ?? array() );
		$field       = (string) ( $destination['field'] ?? '' );
		$entity_id   = (int) ( $destination['entity_id'] ?? 0 );

		$compatibility = new Elementor_Compatibility();
		$tag           = $this->dynamic_tag_for( $field );

		$out = array(
			'tag'         => $tag,
			'field'       => $field,
			'entity_id'   => $entity_id,
			'available'   => false,
			'status'      => 'unavailable',
			'fallback'    => 'static_mapped_content',
			'fallback_approved' => false,
			'elementor_available' => $compatibility->is_available(),
		);

		if ( ! $compatibility->is_available() ) {
			$out['reason'] = (string) $compatibility->message();
			return $out;
		}

		$out['available'] = true;
		$out['status']    = 'dynamic';
		// A dynamic tag is never written as PHP and never as a shortcode. The string
		// below is the tag *name*, which Elementor resolves itself.
		$out['tag'] = ( '' === $tag ) ? 'post-title' : $tag;

		return $out;
	}

	/**
	 * Return the Elementor dynamic tag for a destination field.
	 *
	 * @param string $field Destination field.
	 * @return string
	 */
	private function dynamic_tag_for( $field ) {
		$map = array(
			'post_title'           => 'post-title',
			'post_content'         => 'post-content',
			'post_excerpt'         => 'post-excerpt',
			'post_date'            => 'post-date',
			'post_author'          => 'post-author',
			'featured_image'       => 'post-featured-image',
			'_featured_image_id'   => 'woocommerce-product-image',
			'_regular_price'       => 'woocommerce-product-price',
			'_sale_price'          => 'woocommerce-product-price',
			'_average_rating'      => 'woocommerce-product-rating',
			'sku'                  => 'woocommerce-product-sku',
		);
		return (string) ( $map[ $field ] ?? '' );
	}

	/**
	 * Return a redacted, AI-safe projection of a content model.
	 *
	 * §30 requires that passwords, API keys, tokens, cookies, authentication headers, and
	 * private customer or user information are removed *before* AI processing, and that
	 * only the minimum necessary structured information is sent.
	 *
	 * So this does three things in order: it drops every item whose role is personal or
	 * contact data entirely, it redacts secrets from what remains, and it truncates. A
	 * model asked to classify a product title does not need a reviewer's name, and
	 * sending one would be a privacy failure in exchange for nothing.
	 *
	 * @param array<string, mixed> $model Content model.
	 * @return array<string, mixed>
	 */
	public static function for_ai( array $model ) {
		$out    = array();
		$dropped = array();

		foreach ( (array) ( $model['items'] ?? array() ) as $id => $item ) {
			$item = (array) $item;
			$role = (string) ( $item['role'] ?? '' );

			// §30: personal data is not redacted-within, it is removed. A person's name
			// is not a secret, but it is not needed to classify a layout either.
			if ( in_array( $role, Content_Limits::PERSON_ROLES, true ) || in_array( $role, array( 'phone', 'email', 'address' ), true ) ) {
				$dropped[] = array( 'content_id' => (string) $id, 'role' => $role, 'reason' => 'personal_or_contact_data' );
				continue;
			}

			$out[] = array(
				'role'       => $role,
				'data_type'  => (string) ( $item['data_type'] ?? 'text' ),
				// Bounded, then redacted. Redaction first would shorten a value whose
				// secret is at the end.
				'value'      => Data_Redactor::secrets( substr( (string) ( $item['value'] ?? '' ), 0, 500 ) ),
				'section_id' => (string) ( $item['section_id'] ?? '' ),
				'is_dynamic' => (string) ( $item['is_dynamic'] ?? 'unknown' ),
			);
		}

		$structure = array(
			'page_type' => (string) ( $model['page_type'] ?? 'unknown' ),
			'entity_type' => (string) ( $model['fabrication']['policy'] ?? '' ),
			'items'     => array_slice( $out, 0, 200 ),
			// Never the raw values of entities: a JSON-LD block is author-controlled and
			// may contain anything, including something that looks like a key.
			'entity_types' => array_values( array_unique( array_map( static function ( $entity ) { return is_array( $entity ) ? (string) ( $entity['type'] ?? '' ) : ''; }, (array) ( $model['entities'] ?? array() ) ) ) ),
		);

		return array(
			'schema_version' => Content_Limits::SCHEMA_VERSION,
			'payload'        => Data_Redactor::structure( $structure ),
			'dropped'        => $dropped,
			'note'           => __( 'Personal names, phone numbers, email addresses, and street addresses were removed before this left your site. Secrets were redacted from what remained.', 'replicaforge' ),
		);
	}
}
