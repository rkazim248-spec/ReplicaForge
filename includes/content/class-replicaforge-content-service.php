<?php
/**
 * Phase 14: the content intelligence service.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The single entry point into the content layer.
 *
 * ### Why a facade rather than callers assembling the pieces
 *
 * §1's thirteen classes are not thirteen things a caller should have to know the order
 * of. The order matters: structured data is read before roles are detected; roles are
 * detected before a model is built; a model is built before it is mapped; a mapping is
 * planned before it is validated; and only a *validated* plan is applicable. A caller
 * that assembles those itself will eventually get the order wrong, and the failure mode
 * of the wrong order is a mapping that skips validation — which is the one failure this
 * whole phase exists to make impossible.
 *
 * So the order is expressed once, here, and every entry point starts from a place where
 * the previous step cannot have been skipped.
 */
final class Content_Service {

	/**
	 * Registered providers.
	 *
	 * @var array<string, Content_Provider_Contract>
	 */
	private $providers = array();

	/**
	 * Structured-data reader.
	 *
	 * @var Structured_Data
	 */
	private $structured;

	/**
	 * Content cache.
	 *
	 * @var Content_Cache
	 */
	private $cache;

	/**
	 * Mapper.
	 *
	 * @var Content_Mapper
	 */
	private $mapper;

	/**
	 * Entity matcher.
	 *
	 * @var Content_Entity_Matcher
	 */
	private $matcher;

	/**
	 * Validator.
	 *
	 * @var Content_Validator
	 */
	private $validator;

	/**
	 * Preview builder.
	 *
	 * @var Content_Preview
	 */
	private $preview;

	/**
	 * Applier.
	 *
	 * @var Content_Applier
	 */
	private $applier;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * The user whose content is being read.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $services Optional service overrides.
	 */
	public function __construct( array $services = array() ) {
		$this->user_id   = isset( $services['user_id'] ) ? (int) $services['user_id'] : get_current_user_id();
		$this->logger    = ( isset( $services['logger'] ) && $services['logger'] instanceof Logger ) ? $services['logger'] : new Logger();
		$this->structured = new Structured_Data( isset( $services['urls'] ) ? $services['urls'] : null );
		$this->cache     = ( isset( $services['cache'] ) && $services['cache'] instanceof Content_Cache ) ? $services['cache'] : new Content_Cache();
		$this->mapper    = new Content_Mapper();
		$this->matcher   = new Content_Entity_Matcher();
		$this->validator = new Content_Validator();
		$this->applier   = new Content_Applier( null, $this->logger );

		if ( isset( $services['providers'] ) && is_array( $services['providers'] ) ) {
			foreach ( $services['providers'] as $provider ) {
				if ( $provider instanceof Content_Provider_Contract ) {
					$this->add_provider( $provider );
				}
			}
		}
		if ( ! isset( $this->providers['wordpress'] ) ) {
			$this->add_provider( new WordPress_Content_Provider( $this->user_id, $services['custom_fields'] ?? null ) );
		}
		if ( ! isset( $this->providers['woocommerce'] ) ) {
			// The WooCommerce provider is registered even when WooCommerce is absent. It
			// reports itself unavailable, and a mapping screen can therefore show *why*
			// there is no store rather than showing an empty list.
			$this->add_provider( new WooCommerce_Provider( $this->user_id, (array) ( $services['woocommerce_api'] ?? array() ) ) );
		}

		$this->preview = new Content_Preview( $this->providers );
	}

	/**
	 * Register a provider.
	 *
	 * @param Content_Provider_Contract $provider Provider.
	 * @return void
	 */
	public function add_provider( Content_Provider_Contract $provider ) {
		$this->providers[ $provider->id() ] = $provider;
		$this->mapper->add_provider( $provider );
		$this->applier->add_provider( $provider );
	}

	/**
	 * Return the provider report.
	 *
	 * @return array<string, mixed>
	 */
	public function providers() {
		$out = $this->mapper->providers();
		foreach ( $this->providers as $id => $provider ) {
			$out[ $id ]['fields'] = $this->describe_fields( $provider );
		}
		return $out;
	}

	/**
	 * Analyse a page's content and return the `14.0` model.
	 *
	 * @param string               $project_id Project id.
	 * @param string               $page_id    Page id.
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @param \DOMDocument|null    $document    Parsed document, when available.
	 * @param array<string, mixed> $options     Options.
	 * @return array<string, mixed>
	 */
	public function analyse( $project_id, $page_id, array $representation, $document = null, array $options = array() ) {
		$source = array(
			'url'          => (string) ( $representation['page']['final_url'] ?? ( $representation['page']['url'] ?? '' ) ),
			'page_id'      => (string) $page_id,
			'content_hash' => (string) ( $options['source_hash'] ?? $this->hash( $representation ) ),
		);

		$key = $this->cache->key( $project_id, array(
			'source_hash' => $source['content_hash'],
			'view'        => 'analyse',
		) );
		$cached = $this->cache->get( $key );
		if ( null !== $cached && empty( $options['no_cache'] ) ) {
			$body = (array) $cached['body'];
			$body['cache'] = array( 'hit' => true, 'key' => $key );
			return $body;
		}

		// Structured data first. The role detector reads its claims, so this must not
		// come second.
		$structured = $document instanceof \DOMDocument
			? $this->structured->read( $document, (string) $source['url'] )
			: $this->structured->read( null, (string) $source['url'] );

		$roles   = new Content_Role_Detector( $structured, (array) ( $options['visual'] ?? array() ) );
		$items   = $roles->detect( $representation );

		$model   = new Source_Content_Model();
		$spec    = $model->build(
			$items,
			$structured,
			$source,
			(array) ( $options['dynamic_regions'] ?? array() )
		);

		$verdict = $model->validate( $spec );
		$out     = array(
			'schema_version' => Content_Limits::SCHEMA_VERSION,
			'project_id'     => (string) $project_id,
			'page_id'        => (string) $page_id,
			'model'          => $spec,
			'structured'     => array(
				'counts'    => (array) ( $structured['counts'] ?? array() ),
				'page_type' => (array) ( $structured['page_type'] ?? array() ),
				'trust'     => (string) ( $structured['trust'] ?? 'unverified_source_claim' ),
				'rejected'  => (array) ( $structured['rejected'] ?? array() ),
			),
			'validation'     => $verdict,
			'cache'          => array( 'hit' => false, 'key' => $key ),
		);

		$this->cache->put( $key, $out );
		return $out;
	}

	/**
	 * Build a mapping plan for a project.
	 *
	 * @param string               $project_id Project id.
	 * @param array<string, mixed> $model      Content model.
	 * @param array<string, mixed> $options    Options: mode, page_id.
	 * @return array<string, mixed>
	 */
	public function plan( $project_id, array $model, array $options = array() ) {
		$mode    = (string) ( $options['mode'] ?? 'hybrid_replica' );
		$page_id = (string) ( $options['page_id'] ?? '' );

		$mappings = $this->mapper->map(
			$model,
			array(
				'project_id' => (string) $project_id,
				'page_id'    => $page_id,
				'mode'       => $mode,
			)
		);

		$plan = $this->validator->plan(
			$project_id,
			$mappings,
			array(
				'mode'  => $mode,
				'model' => $model,
			)
		);

		if ( ! empty( $options['confirmed'] ) ) {
			$plan['confirmed'] = true;
		}

		$this->cache->store_plan( $project_id, $plan );

		$this->logger->info(
			'content_plan_built',
			'Built a content mapping plan.',
			array( 'project' => (string) $project_id, 'mappings' => count( $plan['mappings'] ), 'review' => $plan['requires_review'] ),
			'content'
		);

		return $plan;
	}

	/**
	 * Preview a plan.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array<string, mixed>
	 */
	public function preview( array $plan ) {
		return $this->preview->preview( $plan );
	}

	/**
	 * Apply a plan.
	 *
	 * @param array<string, mixed> $plan     Plan.
	 * @param array<int, string>   $approved Approved mapping ids.
	 * @param array<string, mixed> $options  Options.
	 * @return array<string, mixed>
	 */
	public function apply( array $plan, array $approved = array(), array $options = array() ) {
		$options['user_id'] = (int) ( $options['user_id'] ?? $this->user_id );
		return $this->applier->apply( $plan, $approved, $options );
	}

	/**
	 * Match source entities to destination entities.
	 *
	 * @param array<int, array<string, mixed>> $sources      Source entities.
	 * @param string                            $entity_type  Destination entity type.
	 * @param array<string, mixed>              $options      Options.
	 * @return array<string, mixed>
	 */
	public function match_entities( array $sources, $entity_type, array $options = array() ) {
		$provider_id = (string) ( $options['provider'] ?? 'woocommerce' );
		$provider    = $this->providers[ $provider_id ] ?? null;

		if ( ! $provider instanceof Content_Provider_Contract ) {
			return array(
				'schema_version' => Content_Limits::SCHEMA_VERSION,
				'available'      => false,
				'results'        => array(),
				'counts'         => array( 'matched' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'skipped' => 0 ),
				'reason'         => __( 'That destination provider is not registered.', 'replicaforge' ),
			);
		}
		if ( ! $provider->is_available() ) {
			return array(
				'schema_version' => Content_Limits::SCHEMA_VERSION,
				'available'      => false,
				'results'        => array(),
				'counts'         => array( 'matched' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'skipped' => 0 ),
				'code'           => 'woocommerce_not_active',
				// `empty` is false, so "no store" cannot be read as "no products".
				'empty'          => false,
				'reason'         => (string) $provider->unavailable_reason(),
			);
		}

		$destinations = $this->collect_destinations( $provider, $entity_type, $options );

		// The candidate summary is added *after* matching rather than during it, so the
		// matcher never has to hold the whole destination set in a result row — a batch
		// of 200 products would otherwise be carried into every one of 200 results.
		$result = $this->matcher->match( $sources, $destinations, $options );

		// `available` is stated on this path as well as on the failure paths. The first
		// draft set it only when something went wrong, so a caller reading `available`
		// got "absent" for a perfectly good run that happened to match nothing - and
		// "the store is unavailable" is a materially different thing to tell a user than
		// "none of these products are in your store yet".
		$result['available'] = true;
		$result['empty']     = ( array() === $destinations );

		$result['destination'] = array(
			'provider'    => (string) $provider->id(),
			'entity_type' => (string) $entity_type,
			'considered'  => count( $destinations ),
			'sample'      => array_slice( $destinations, 0, 5 ),
			'version'     => (string) $provider->version(),
		);

		return $result;
	}

	/**
	 * Bind matched destination entities onto a plan.
	 *
	 * ### The link between §20 and §15
	 *
	 * A mapping says *which field of which kind of entity* a source value belongs in —
	 * `product_price` onto a product's `_regular_price`. It cannot say *which product*,
	 * because that is a per-project fact discovered by matching, not a rule.
	 *
	 * So the plan is built with `entity_id` at zero, and this is what fills it in. Until
	 * it runs, every mapping is a field mapping with no entity, and
	 * {@see Content_Validator::eligible_for_apply()} correctly withholds all of them with
	 * `destination_entity_not_resolved`. That withholding is the honest state: ReplicaForge
	 * does not know which of ten thousand products a price belongs to until a human has
	 * had a chance to say.
	 *
	 * This is what makes §41 work end to end: analyse the product card design, match the
	 * source products to the store's products, bind the match, and the same mappings that
	 * were withheld a moment ago become applicable — against the user's own data, with the
	 * source's structure untouched.
	 *
	 * ### Why binding does not break the plan's seal
	 *
	 * The source digest in {@see Content_Validator::source_digest()} covers `content_id`
	 * and `source.value` only. Binding an `entity_id` changes what a mapping *writes to*,
	 * never what it *contains*, so the seal still holds and the plan is still provably the
	 * one the validator checked. That separation is deliberate: choosing a destination
	 * record is a decision a user is entitled to change, and inventing a source value is
	 * something they are not.
	 *
	 * @param array<string, mixed> $plan    Plan.
	 * @param array<string, mixed> $matches Entity-match result from {@see self::match_entities()}.
	 * @param array<string, mixed> $options Options.
	 * @return array<string, mixed> The plan with `entity_id` bound, plus a report.
	 */
	public function resolve_destinations( array $plan, array $matches, array $options = array() ) {
		$explicit = (array) ( $options['entity_ids'] ?? array() );
		$report   = array( 'bound' => 0, 'unbound' => 0, 'from' => array(), 'reason' => '' );

		// Winning entity ids from a match result, in the order the matcher reported them.
		$winners = array();
		foreach ( (array) ( $matches['results'] ?? array() ) as $result ) {
			$result = (array) $result;
			$id     = (int) ( $result['entity_id'] ?? 0 );
			$status = (string) ( $result['status'] ?? '' );
			if ( $id > 0 && in_array( $status, array( 'matched', 'review' ), true ) ) {
				$winners[] = array( 'id' => $id, 'status' => $status, 'score' => (float) ( $result['score'] ?? 0.0 ) );
			}
		}

		// A single page describes one entity. When the match produced exactly one winner
		// every mapping of that entity type takes it. When it produced several — a
		// product archive with four products on it — the mappings are *not* spread across
		// them by index, because "the price on the page goes to whichever product the
		// matcher happened to list first" is an invention. The caller has to say which
		// product each section belongs to, and the report says so.
		$single = ( 1 === count( $winners ) ) ? $winners[0] : null;
		$default = (int) ( $options['entity_id'] ?? 0 );
		if ( $default > 0 ) {
			$single = array( 'id' => $default, 'status' => 'manual', 'score' => 1.0 );
		}

		$mappings = (array) ( $plan['mappings'] ?? array() );
		foreach ( $mappings as $index => $mapping ) {
			$mapping = (array) $mapping;
			if ( 0 !== (int) ( $mapping['destination']['entity_id'] ?? 0 ) ) {
				continue;
			}
			// A dynamic mapping legitimately has no entity: it renders whatever post is
			// being displayed, so binding one would be wrong rather than merely noisy.
			if ( ! empty( $mapping['destination']['dynamic'] ) ) {
				continue;
			}

			$key = (string) ( $mapping['destination']['entity'] ?? '' ) . ':' . (string) ( $mapping['source']['content_id'] ?? '' );
			$id  = (int) ( $explicit[ $key ] ?? 0 );

			if ( $id < 1 && null !== $single ) {
				$id = (int) $single['id'];
			}

			if ( $id > 0 ) {
				$mappings[ $index ]['destination']['entity_id'] = $id;
				$report['bound']++;
				$report['from'][ (string) $id ] = ( null !== $single ) ? (string) $single['status'] : 'manual';
			} else {
				$report['unbound']++;
			}
		}

		// The reason is filled in *before* the report is copied onto the plan. The first
		// draft assigned $plan['resolution'] = $report and only then set
		// $report['reason']. PHP arrays are value types, so that wrote the reason to a
		// discarded copy and the caller never saw it - the same copy-then-mutate mistake
		// Phase 13 made in merge_boxes(), and it failed silently because the rest of the
		// report was correct.
		if ( $report['unbound'] > 0 && array() === $explicit ) {
			$report['reason'] = ( null === $single )
				? __( 'No single destination record was matched, so ReplicaForge did not guess which one these mappings belong to. Choose the record each section maps to, and they become applicable.', 'replicaforge' )
				: '';
		}

		$plan['mappings']   = $mappings;
		$plan['resolution'] = $report;

		return $plan;
	}

	/**
	 * Return the destination model §9 describes.
	 *
	 * @return array<string, mixed>
	 */
	public function destination_model() {
		$wordpress  = $this->providers['wordpress'] ?? null;
		$woocommerce = $this->providers['woocommerce'] ?? null;

		$post_types = array();
		$taxonomies = array();
		if ( $wordpress instanceof Content_Provider_Contract && $wordpress->is_available() ) {
			foreach ( $wordpress->entity_types() as $entity_type ) {
				$object = get_post_type_object( $entity_type );
				$post_types[] = array(
					'post_type'   => (string) $entity_type,
					'label'       => $object ? (string) $object->labels->name : (string) $entity_type,
					'public'      => $object ? (bool) $object->public : false,
					'publicly_queryable' => $object ? (bool) $object->publicly_queryable : false,
					'supports'    => $object ? array_keys( get_all_post_type_supports( $entity_type ) ) : array(),
					'taxonomies'  => ( $object ) ? array_values( (array) get_object_taxonomies( $entity_type, 'objects' ) ) : array(),
				);
			}
			foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
				$taxonomies[] = array(
					'taxonomy' => (string) $taxonomy->name,
					'label'    => (string) $taxonomy->labels->name,
					'public'   => (bool) $taxonomy->public,
					'object_types' => array_values( (array) $taxonomy->object_type ),
				);
			}
		}

		return array(
			'schema_version' => Content_Limits::SCHEMA_VERSION,
			'wordpress'      => array(
				'available'   => ( $wordpress instanceof Content_Provider_Contract ) && $wordpress->is_available(),
				'post_types'  => $post_types,
				'taxonomies'  => $taxonomies,
			),
			'woocommerce'   => array(
				'enabled'     => ( $woocommerce instanceof Content_Provider_Contract ) && $woocommerce->is_available(),
				'reason'      => ( $woocommerce instanceof Content_Provider_Contract ) ? (string) $woocommerce->unavailable_reason() : '',
				'entity_types'=> ( $woocommerce instanceof Content_Provider_Contract ) ? array_values( (array) $woocommerce->entity_types() ) : array(),
				'product_types' => WooCommerce_Provider::PRODUCT_TYPES,
			),
			'content_sources' => array_values( array_map( array( $this, 'describe_provider' ), $this->providers ) ),
		);
	}

	/**
	 * Return a stored plan.
	 *
	 * @param string $project_id Project id.
	 * @param string $plan_id    Plan id.
	 * @return array<string, mixed>|null
	 */
	public function plan_by_id( $project_id, $plan_id ) {
		return $this->cache->plan( $project_id, $plan_id );
	}

	/**
	 * Return provenance for a project or one field.
	 *
	 * @param string $project_id  Project id.
	 * @param int    $destination Destination entity id.
	 * @param string $field       Field.
	 * @return array<int, array<string, mixed>>
	 */
	public function provenance( $project_id, $destination = 0, $field = '' ) {
		return $this->cache->provenance( $project_id, $destination, $field );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Collect destination entities across pages, bounded.
	 *
	 * @param Content_Provider_Contract $provider Provider.
	 * @param string                    $entity   Entity type.
	 * @param array<string, mixed>      $options  Options.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_destinations( Content_Provider_Contract $provider, $entity, array $options ) {
		$per_page = (int) ( $options['per_page'] ?? 50 );
		$pages    = max( 1, min( 50, (int) ( $options['pages'] ?? 3 ) ) );

		$out = array();
		for ( $page = 1; $page <= $pages; $page++ ) {
			$reported = $provider->entities( $entity, $page, $per_page, (array) ( $options['query'] ?? array() ) );
			foreach ( (array) ( $reported['items'] ?? array() ) as $item ) {
				$out[] = (array) $item;
			}
			if ( count( $out ) >= Content_Limits::MAX_DESTINATION_BATCH ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Describe a provider.
	 *
	 * @param Content_Provider_Contract $provider Provider.
	 * @return array<string, mixed>
	 */
	private function describe_provider( Content_Provider_Contract $provider ) {
		return array(
			'provider'     => (string) $provider->id(),
			'available'    => (bool) $provider->is_available(),
			'reason'       => (string) $provider->unavailable_reason(),
			'version'      => (string) $provider->version(),
			'entity_types' => array_values( (array) $provider->entity_types() ),
			'fields'       => $this->describe_fields( $provider ),
		);
	}

	/**
	 * Describe every field a provider offers.
	 *
	 * @param Content_Provider_Contract $provider Provider.
	 * @return array<int, array<string, mixed>>
	 */
	private function describe_fields( Content_Provider_Contract $provider ) {
		$out = array();
		foreach ( (array) $provider->entity_types() as $entity_type ) {
			foreach ( (array) $provider->fields_for( $entity_type ) as $field ) {
				$field = (array) $field;
				$field['entity_type'] = (string) $entity_type;
				$out[] = $field;
			}
		}
		return $out;
	}

	/**
	 * Hash a representation into a source content hash.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return string
	 */
	private function hash( array $representation ) {
		$material = array(
			'url'     => (string) ( $representation['page']['final_url'] ?? '' ),
			// The component list and its text, because a content change is a text change
			// and nothing else about the representation is relevant to content identity.
			'content' => array_map(
				static function ( $component ) {
					return array( (string) ( $component['id'] ?? '' ), (string) ( $component['type'] ?? '' ), (string) ( $component['text'] ?? '' ) );
				},
				(array) ( $representation['components'] ?? array() )
			),
		);
		return substr( hash( 'sha256', (string) wp_json_encode( $material ) ), 0, 32 );
	}
}
