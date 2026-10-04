<?php
/**
 * Phase 14: structured metadata reading.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Schema.org JSON-LD, OpenGraph, and Twitter metadata as **data only**.
 *
 * ### The threat model, stated before the code
 *
 * JSON-LD is author-controlled content on a page ReplicaForge has not authenticated
 * against, and it is the single most abused injection surface on the open web. A page
 * can put anything in it, including:
 *
 * - a `@type` of `Product` on a page with no products, to make a mapping engine write a
 *   price onto a post that is not a product;
 * - an `offers.price` of `"0"` or `"999999999"`, to move money;
 * - a `url` of `http://169.254.169.254/`, to make ReplicaForge fetch cloud metadata;
 * - deeply nested `@graph` structures, to exhaust memory;
 * - megabyte-sized string values, to exhaust a request;
 * - a `sameAs` array pointing at internal hosts, to probe a network.
 *
 * So **nothing this class returns is trusted as a fact about the world.** It is trusted
 * as a *claim by the source page*, recorded with the evidence that it was a claim. Every
 * value that could cause a side effect — a URL, an identifier — is put through
 * {@see Url_Validator} or refused, and every value that could be mistaken for a real
 * record is marked `unverified`.
 *
 * ### What is deliberately not done
 *
 * - No `json_decode( ..., true, $depth )` beyond the bound, and no second decode.
 * - No object instantiation, no `eval`, no `unserialize`, no schema `$ref` resolution.
 *   A `@type` of `https://schema.org/Product` is treated as the string `Product`.
 * - No network request of any kind. This class never fetches. Following an `@id` into
 *   another document is an SSRF primitive and is not implemented.
 * - No field is copied into a destination without going through
 *   {@see Content_Validator}, which requires it to be traceable.
 */
final class Structured_Data {

	/**
	 * The JSON-LD types that produce a recognised entity.
	 *
	 * Matched on the *last* segment of the type, because both `Product` and
	 * `https://schema.org/Product` are in common use, and a type written as a full URI
	 * must not silently stop matching.
	 *
	 * @var array<string, string>
	 */
	const TYPES = array(
		'product'              => 'product',
		'productgroup'         => 'product',
		'productmodel'         => 'product',
		'article'              => 'post',
		'newsarticle'          => 'post',
		'blogposting'          => 'post',
		'techarticle'          => 'post',
		'person'               => 'author',
		'organization'         => 'organization',
		'localbusiness'        => 'organization',
		'restaurant'           => 'organization',
		'store'                => 'organization',
		'place'                => 'location',
		'postaladdress'        => 'address',
		'contactpoint'         => 'contact',
		'event'                => 'event',
		'jobposting'           => 'listing',
		'residence'            => 'property',
		'apartment'            => 'property',
		'singlefamilyresidence' => 'property',
		'offer'                => 'offer',
		'aggregatoffer'        => 'offer',
		'review'               => 'review',
		'ratings'              => 'rating',
		'breadcrumblist'       => 'breadcrumb',
		'question'             => 'faq',
		'faqpage'              => 'faq',
		'imageobject'          => 'image',
		'brand'                => 'brand',
		'itemlist'             => 'list',
		'collectionpage'       => 'archive',
		'itempage'             => 'detail',
	);

	/**
	 * OpenGraph `og:type` values that produce a recognised page type.
	 *
	 * @var array<string, string>
	 */
	const OG_TYPES = array(
		'product'       => 'product_detail',
		'product.item'  => 'product_detail',
		'article'       => 'blog_post',
		'blog'          => 'blog_archive',
		'website'       => 'site',
		'profile'       => 'profile',
		'video.other'   => 'video',
	);

	/**
	 * URL validator.
	 *
	 * @var Url_Validator
	 */
	private $urls;

	/**
	 * Constructor.
	 *
	 * @param Url_Validator|null $urls Optional URL validator.
	 */
	public function __construct( $urls = null ) {
		$this->urls = $urls instanceof Url_Validator ? $urls : new Url_Validator();
	}

	/**
	 * Read every structured source from a parsed document.
	 *
	 * @param \DOMDocument $document  Parsed document.
	 * @param string       $base_url  Final page URL, for relative reference resolution.
	 * @return array<string, mixed>
	 */
	public function read( $document, $base_url = '' ) {
		if ( ! $document instanceof \DOMDocument ) {
			return $this->empty_result( __( 'No document was available to read structured data from.', 'replicaforge' ) );
		}

		$json_ld  = $this->read_json_ld( $document );
		$og       = $this->read_meta( $document, 'property', 'og:' );
		$twitter  = $this->read_meta( $document, 'name', 'twitter:' );
		$standard = $this->read_standard_meta( $document );

		$entities = $this->entities_from( $json_ld );
		$page_type = $this->page_type( $og['values'], $entities );

		$rejected = array_merge(
			$json_ld['rejected'],
			$og['rejected'],
			$twitter['rejected']
		);

		return array(
			'schema_version' => Content_Limits::SCHEMA_VERSION,
			'json_ld'        => $json_ld['blocks'],
			'opengraph'      => $og['values'],
			'twitter'        => $twitter['values'],
			'standard'       => $standard,
			'entities'       => $entities,
			'page_type'      => $page_type,
			'counts'         => array(
				'json_ld_blocks' => count( $json_ld['blocks'] ),
				'json_ld_rejected' => $json_ld['rejected_count'],
				'meta'           => count( $og['values'] ) + count( $twitter['values'] ),
			),
			// Every claim carries this. Nothing here is a verified fact.
			'trust'          => 'unverified_source_claim',
			'rejected'       => $rejected,
			'warnings'       => $this->warnings( $json_ld, $og, $twitter ),
			'note'           => __( 'Structured data is written by the source website and asserts nothing. ReplicaForge records these as claims with their evidence and never treats one as a fact about a product, a price, or a person.', 'replicaforge' ),
		);
	}

	/**
	 * Read and flatten JSON-LD blocks.
	 *
	 * @param \DOMDocument $document Parsed document.
	 * @return array<string, mixed>
	 */
	private function read_json_ld( \DOMDocument $document ) {
		$blocks   = array();
		$rejected = array();
		$count    = 0;

		$xpath = new \DOMXPath( $document );

		/** @var \DOMNodeList<\DOMElement> $nodes */
		$nodes = $xpath->query( '//script[@type="application/ld+json"]' );
		if ( ! $nodes ) {
			return array( 'blocks' => array(), 'rejected' => array(), 'rejected_count' => 0 );
		}

		foreach ( $nodes as $node ) {
			if ( $count >= Content_Limits::MAX_STRUCTURED_BLOCKS ) {
				$rejected[] = array(
					'reason' => 'too_many_blocks',
					'limit'  => Content_Limits::MAX_STRUCTURED_BLOCKS,
					// Said plainly rather than silently truncated: a page with 200 blocks
					// is unusual, and a user should know the rest were not read.
					'note'   => __( 'This page declares more structured-data blocks than ReplicaForge reads. The rest were ignored.', 'replicaforge' ),
				);
				break;
			}

			$raw = (string) $node->textContent;
			if ( '' === trim( $raw ) ) {
				$rejected[] = array( 'reason' => 'empty_block' );
				continue;
			}

			// Size bounded *before* decode. `json_decode` on a 50MB string is a memory
			// exhaustion, and the bound has to be the length check rather than
			// something the decoder does for us.
			if ( strlen( $raw ) > Content_Limits::MAX_STRUCTURED_BYTES ) {
				$rejected[] = array( 'reason' => 'too_large', 'bytes' => strlen( $raw ) );
				continue;
			}

			// Depth passed to the decoder itself, so a hostile nesting is refused by
			// PHP rather than by a recursion limit we might raise later.
			$decoded = json_decode( $raw, true, Content_Limits::MAX_STRUCTURED_DEPTH );
			if ( ! is_array( $decoded ) ) {
				// Malformed JSON-LD is extremely common — templates emit trailing commas
				// and unescaped newlines constantly — so this is a warning, not an
				// error, and it is never treated as absence of data.
				$rejected[] = array( 'reason' => 'malformed_json', 'detail' => json_last_error_msg() );
				continue;
			}

			$blocks[] = $this->flatten_block( $decoded );
			$count++;
		}

		return array(
			'blocks'         => $blocks,
			'rejected'       => $rejected,
			'rejected_count' => count( $rejected ),
		);
	}

	/**
	 * Flatten one decoded block into a list of type-tagged items.
	 *
	 * A block is often an array of entities, or a `@graph` array, or a single entity, and
	 * a real block is frequently all three at different depths. Rather than guessing, the
	 * walk records *every* object that declares an `@type`, which is the only thing a
	 * consumer actually wants.
	 *
	 * @param array<int|string, mixed> $value Decoded block.
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten_block( array $value ) {
		$out   = array();
		$seen  = array();

		$walk = function ( $node, $depth ) use ( &$walk, &$out, &$seen ) {
			if ( ! is_array( $node ) || $depth > Content_Limits::MAX_STRUCTURED_DEPTH ) {
				return;
			}

			// An object.
			if ( ! array_is_list( $node ) ) {
				$type = $this->type_of( $node );
				if ( '' !== $type ) {
					$key = $type . '|' . md5( (string) wp_json_encode( $node ) );
					if ( ! isset( $seen[ $key ] ) ) {
						$seen[ $key ] = true;
						$out[]        = array(
							'type'     => $type,
							'raw_type'  => is_string( $node['@type'] ?? null ) ? (string) $node['@type'] : ( is_array( $node['@type'] ?? null ) ? implode( ',', array_map( 'strval', (array) $node['@type'] ) ) : '' ),
							'data'      => $this->normalise_values( $node, $depth ),
						);
					}
				}
			}

			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					$walk( $child, $depth + 1 );
				}
			}
		};

		$walk( $value, 0 );

		return $out;
	}

	/**
	 * Return the recognised type of a decoded object, or an empty string.
	 *
	 * @param array<string, mixed> $node Decoded object.
	 * @return string
	 */
	private function type_of( array $node ) {
		$declared = $node['@type'] ?? null;
		if ( is_array( $declared ) ) {
			$declared = $declared[0] ?? '';
		}
		if ( ! is_string( $declared ) || '' === $declared ) {
			return '';
		}

		foreach ( preg_split( '/[\s,]+/', $declared ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			// Both `Product` and `https://schema.org/Product` are in common use, so the
			// last path segment is what is matched. A type written as a full URI must not
			// silently stop being recognised.
			//
			// Two ordering bugs made this recognise *nothing*, and both are worth
			// recording because the symptom — a page silently reporting `unknown` with
			// zero entities — looks like "the site has no structured data" rather than
			// like a bug:
			//
			// 1. `substr( $x, strrpos( '/' . $x, '/' ) + 1 )` returns an index into the
			//    *prepended* string while slicing the *original*, so it ate the first
			//    character of the segment. `Product` became `roduct`.
			// 2. `strtolower()` applied *after* `preg_replace( '/[^a-z0-9]/', ... )` let
			//    the filter run against mixed case, so it deleted every capital letter.
			//    `Product` became `roduct` again — and `Offer`, `Article`, and `Person`
			//    all failed the same way, which is most of schema.org.
			//
			// So: case first, then segment, then strip.
			$bare = strtolower( $candidate );
			$bare = basename( (string) str_replace( '\\', '/', $bare ) );
			$bare = (string) preg_replace( '/[^a-z0-9]/', '', $bare );
			if ( isset( self::TYPES[ $bare ] ) ) {
				return self::TYPES[ $bare ];
			}
		}

		return '';
	}

	/**
	 * Normalise a decoded object's values into bounded, safe scalars.
	 *
	 * Every string is length-capped, every nested structure is depth-capped, and any key
	 * that looks like a secret is dropped — because this data is about to be stored,
	 * possibly logged, and possibly sent to an AI provider, and a source page should not
	 * be able to plant something in ReplicaForge's own records.
	 *
	 * @param array<string, mixed> $node  Decoded object.
	 * @param int                  $depth Current depth.
	 * @return array<string, mixed>
	 */
	private function normalise_values( array $node, $depth ) {
		$out = array();

		foreach ( $node as $key => $value ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, '@' ) ) {
				continue;
			}
			if ( Data_Redactor::is_secret_key( $key ) ) {
				// Dropped rather than stored. A page that puts `api_key` in its
				// JSON-LD is either broken or probing, and either way it must not end up
				// in ReplicaForge's records.
				continue;
			}
			if ( ! is_scalar( $value ) && null !== $value ) {
				if ( is_array( $value ) ) {
					$out[ $key ] = $depth >= Content_Limits::MAX_STRUCTURED_DEPTH ? null : $this->normalise_values( $value, $depth + 1 );
				}
				continue;
			}
			if ( is_string( $value ) ) {
				$value = Data_Redactor::secrets( $value );
				$value = $this->bounded_text( $value, 2000 );
			}
			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * Read OpenGraph or Twitter meta values.
	 *
	 * @param \DOMDocument $document Parsed document.
	 * @param string       $attr     Attribute holding the name.
	 * @param string       $prefix   Prefix to collect.
	 * @return array<string, mixed>
	 */
	private function read_meta( \DOMDocument $document, $attr, $prefix ) {
		$values   = array();
		$rejected = array();
		$count    = 0;

		$xpath   = new \DOMXPath( $document );
		$pattern = './/meta[@' . $attr . ']';
		/** @var \DOMNodeList<\DOMElement> $nodes */
		$nodes = $xpath->query( $pattern );

		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				$name = (string) $node->getAttribute( $attr );
				if ( 0 !== stripos( $name, $prefix ) ) {
					continue;
				}
				$content = $this->bounded_text( Data_Redactor::secrets( (string) $node->getAttribute( 'content' ) ), 2000 );
				if ( '' === $content ) {
					continue;
				}

				$key = strtolower( $name );
				if ( 'og:image' === $key || 'twitter:image' === $key ) {
					// An image URL is the one meta value that could cause a request, so
					// it is validated rather than merely bounded. A blocked image URL is
					// dropped and recorded, not silently kept.
					$verdict = $this->urls->validate( $content );
					if ( empty( $verdict['success'] ) ) {
						$rejected[] = array( 'key' => $key, 'reason' => 'url_refused' );
						continue;
					}
					$content = (string) $verdict['url'];
				}

				$values[ $key ] = $content;
				$count++;
				if ( $count >= 60 ) {
					$rejected[] = array( 'reason' => 'meta_limit' );
					break;
				}
			}
		}

		return array( 'values' => $values, 'rejected' => $rejected, 'rejected_count' => count( $rejected ) );
	}

	/**
	 * Read the standard head metadata Phase 2 already reads, for completeness.
	 *
	 * @param \DOMDocument $document Parsed document.
	 * @return array<string, string>
	 */
	private function read_standard_meta( \DOMDocument $document ) {
		$out = array();
		$xpath = new \DOMXPath( $document );
		foreach ( array( 'description', 'author', 'generator', 'keywords' ) as $name ) {
			/** @var \DOMNodeList<\DOMElement> $nodes */
			$nodes = $xpath->query( './/meta[@name="' . $name . '"]' );
			if ( $nodes && $nodes->length > 0 ) {
				$out[ $name ] = $this->bounded_text( (string) $nodes->item( 0 )->getAttribute( 'content' ), 500 );
			}
		}
		return $out;
	}

	/**
	 * Derive entities from the flattened JSON-LD items.
	 *
	 * Iterates **blocks** and then the items inside each block, because
	 * {@see self::flatten_block()} returns a *list* of items per block - one block
	 * routinely holds a product, its offer, its rating, and its brand, sometimes
	 * through a `@graph`.
	 *
	 * The first draft iterated the block as though it were a single item, so every
	 * `$item['type']` read empty, the `'' === $type` guard skipped every block, and
	 * `read()` returned zero entities for every page - which is indistinguishable from
	 * "this site publishes no structured data", and is the more reassuring of the two
	 * mistakes.
	 *
	 * @param array<string, mixed> $json_ld Read result.
	 * @return array<int, array<string, mixed>>
	 */
	private function entities_from( array $json_ld ) {
		$out = array();

		foreach ( (array) ( $json_ld['blocks'] ?? array() ) as $block ) {
			foreach ( (array) $block as $entry ) {
				$item = (array) $entry;
				$data = (array) ( $item['data'] ?? array() );
				$type = (string) ( $item['type'] ?? '' );
				if ( '' === $type ) {
					continue;
				}

			$entity = array(
				'entity_id'  => 'sd_' . substr( md5( $type . '|' . (string) wp_json_encode( $data ) ), 0, 16 ),
				'type'       => $type,
				'raw_type'   => (string) ( $item['raw_type'] ?? '' ),
				'name'       => $this->first_string( $data, array( 'name', 'headline', 'title' ) ),
				'description'=> $this->first_string( $data, array( 'description', 'abstract' ) ),
				'sku'        => $this->first_string( $data, array( 'sku', 'productID', 'identifier' ) ),
				'url'        => $this->safe_url( $this->first_string( $data, array( 'url', '@id' ) ) ),
				'image'      => $this->image_of( $data ),
				'data'       => $data,
				// §5: an entity detected from a claim is not a verified entity.
				'evidence'   => array(
					'source'      => 'json_ld',
					'raw_type'    => (string) ( $item['raw_type'] ?? '' ),
					'confidence'  => 0.7,
				),
				'confidence' => 0.7,
				'verified'   => false,
			);

			$price = $this->price_of( $data );
			if ( null !== $price ) {
				$entity['price']        = $price['value'];
				$entity['price_status'] = $price['status'];
				$entity['currency']     = $price['currency'];
			}

			$rating = $this->rating_of( $data );
			if ( null !== $rating ) {
				$entity['rating']       = $rating['value'];
				$entity['rating_count'] = $rating['count'];
			}

			$out[] = $entity;
			if ( count( $out ) >= Content_Limits::MAX_ENTITIES ) {
				// Both loops, not just the inner one: the entity cap must actually
				// bound the result, and `break` out of the inner loop alone would leave
				// the outer loop appending every remaining item of every remaining
				// block.
				return $out;
			}
			}
		}

		return $out;
	}

	/**
	 * Derive a page type from OpenGraph and the detected entities.
	 *
	 * Takes the OpenGraph **values**, not the wrapper returned by
	 * {@see self::read_meta()}. The first draft was handed the wrapper and read
	 * `$og['og:type']` from it, so the key was one level too high, every page with an
	 * `og:type` fell through to the entity check, and a perfectly good
	 * `og:type="product"` was reported as `unknown` with confidence 0. The wrapper has
	 * three keys (`values`, `rejected`, `rejected_count`) and none of them is a page
	 * type, so a wrong level here fails silently every time.
	 *
	 * @param array<string, mixed> $og       OpenGraph values.
	 * @param array<int, mixed>    $entities Detected entities.
	 * @return array<string, mixed>
	 */
	private function page_type( array $og, array $entities ) {
		$declared = strtolower( (string) ( $og['og:type'] ?? '' ) );
		if ( isset( self::OG_TYPES[ $declared ] ) ) {
			return array(
				'type'       => self::OG_TYPES[ $declared ],
				'evidence'   => array( 'source' => 'opengraph', 'og:type' => $declared ),
				'confidence' => 0.75,
			);
		}

		$types = array();
		foreach ( $entities as $entity ) {
			if ( is_array( $entity ) && ! empty( $entity['type'] ) ) {
				$types[ (string) $entity['type'] ] = true;
			}
		}

		if ( isset( $types['product'] ) ) {
			// A page with a product claim is a *product detail* page, not an archive.
			// `CollectionPage` would say archive, and §40 requires the two to be
			// distinguished.
			return array( 'type' => 'product_detail', 'evidence' => array( 'source' => 'json_ld', 'saw' => 'product' ), 'confidence' => 0.7 );
		}
		if ( isset( $types['post'] ) ) {
			return array( 'type' => 'blog_post', 'evidence' => array( 'source' => 'json_ld', 'saw' => 'article' ), 'confidence' => 0.7 );
		}
		if ( isset( $types['listing'] ) || isset( $types['event'] ) ) {
			return array( 'type' => 'listing', 'evidence' => array( 'source' => 'json_ld' ), 'confidence' => 0.6 );
		}
		if ( isset( $types['organization'] ) ) {
			return array( 'type' => 'organization', 'evidence' => array( 'source' => 'json_ld' ), 'confidence' => 0.6 );
		}

		// §6: absence of a claim is not a claim of absence. `unknown` is the answer,
		// and the dynism class that depends on it stays `unknown` too.
		return array( 'type' => 'unknown', 'evidence' => array( 'source' => 'none' ), 'confidence' => 0.0 );
	}

	/**
	 * Extract a price from offers data.
	 *
	 * @param array<string, mixed> $data Decoded values.
	 * @return array<string, mixed>|null
	 */
	private function price_of( array $data ) {
		$offers = $data['offers'] ?? null;
		if ( is_array( $offers ) && array_is_list( $offers ) ) {
			$offers = $offers[0] ?? null;
		}
		if ( ! is_array( $offers ) ) {
			return null;
		}

		$raw = $offers['price'] ?? ( $offers['lowPrice'] ?? null );
		if ( ! is_scalar( $raw ) ) {
			return null;
		}

		$value = $this->currency_value( (string) $raw );
		if ( null === $value ) {
			return null;
		}

		$status = strtolower( (string) ( $offers['priceCurrency'] ?? '' ) );
		$sale   = isset( $offers['highPrice'] ) && $offers['highPrice'] !== $offers['price'];

		return array(
			'value'    => $value,
			'currency' => ( 3 === strlen( $status ) ) ? strtoupper( $status ) : '',
			// Whether the source says this is a sale is a claim. A *destination* price
			// must never be set from this without review, which is why `status` is
			// recorded separately from the amount.
			'status'   => $sale ? 'claimed_sale' : 'claimed_regular',
		);
	}

	/**
	 * Parse a currency string into a number, or null.
	 *
	 * @param string $raw Raw value.
	 * @return float|null
	 */
	private function currency_value( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		// Non-numeric junk in a price string is common (`"USD 49.99"`, `"49,99"`, `"$49.99"`)
		// and the number is still recoverable. But a string with *no* digits is not a
		// price and is refused rather than coerced to zero — a fabricated $0.00 is a
		// worse failure than a missing price.
		if ( 1 !== preg_match( '/[0-9]/', $raw ) ) {
			return null;
		}
		$clean = preg_replace( '/[^0-9.,\-]/', '', $raw );
		$clean = (string) $clean;

		// A comma is a decimal separator in some locales and a thousands separator in
		// others. The disambiguation is positional: a comma followed by exactly two
		// digits at the end is a decimal point; anything else is a thousands separator.
		if ( false !== strpos( $clean, ',' ) && false === strpos( $clean, '.' ) ) {
			$clean = ( 1 === preg_match( '/,\d{2}$/', $clean ) ) ? str_replace( ',', '.', $clean ) : str_replace( ',', '', $clean );
		} else {
			$clean = str_replace( ',', '', $clean );
		}

		if ( ! is_numeric( $clean ) ) {
			return null;
		}
		return round( (float) $clean, 2 );
	}

	/**
	 * Extract a rating from aggregateRating.
	 *
	 * @param array<string, mixed> $data Decoded values.
	 * @return array<string, mixed>|null
	 */
	private function rating_of( array $data ) {
		$rating = $data['aggregateRating'] ?? null;
		if ( ! is_array( $rating ) ) {
			return null;
		}
		$value = $rating['ratingValue'] ?? null;
		if ( ! is_scalar( $value ) || ! is_numeric( (string) $value ) ) {
			return null;
		}
		$count = $rating['ratingCount'] ?? ( $rating['reviewCount'] ?? null );
		return array(
			'value' => round( (float) $value, 2 ),
			'count' => ( is_scalar( $count ) && is_numeric( (string) $count ) ) ? (int) $count : 0,
		);
	}

	/**
	 * Return the first non-empty string among a set of keys.
	 *
	 * @param array<string, mixed> $data Values.
	 * @param array<int, string>   $keys Keys in priority order.
	 * @return string
	 */
	private function first_string( array $data, array $keys ) {
		foreach ( $keys as $key ) {
			$value = $data[ $key ] ?? null;
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return $this->bounded_text( (string) $value, 500 );
			}
		}
		return '';
	}

	/**
	 * Return a validated image reference, or an empty string.
	 *
	 * @param array<string, mixed> $data Values.
	 * @return string
	 */
	private function image_of( array $data ) {
		$image = $data['image'] ?? null;
		if ( is_array( $image ) ) {
			if ( array_is_list( $image ) ) {
				$image = $image[0] ?? null;
			} else {
				$image = $image['url'] ?? ( $image['contentUrl'] ?? null );
			}
		}
		if ( ! is_scalar( $image ) ) {
			return '';
		}
		return $this->safe_url( (string) $image );
	}

	/**
	 * Validate a URL, or return an empty string.
	 *
	 * @param string $url Candidate URL.
	 * @return string
	 */
	private function safe_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$verdict = $this->urls->validate( $url );
		return empty( $verdict['success'] ) ? '' : (string) $verdict['url'];
	}

	/**
	 * Bound a string's length.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private function bounded_text( $value, $length ) {
		$value = (string) $value;
		if ( strlen( $value ) <= $length ) {
			return $value;
		}
		return substr( $value, 0, $length ) . '…';
	}

	/**
	 * Return the warnings this read produced.
	 *
	 * @param array<string, mixed> $json_ld JSON-LD result.
	 * @param array<string, mixed> $og      OpenGraph result.
	 * @param array<string, mixed> $twitter Twitter result.
	 * @return array<int, string>
	 */
	private function warnings( array $json_ld, array $og, array $twitter ) {
		$out = array();

		if ( 0 === count( (array) $json_ld['blocks'] ) ) {
			$out[] = __( 'This page declares no structured data, so no product, price, or article claims were available. Content roles had to be inferred from markup and text alone.', 'replicaforge' );
		}
		foreach ( array( 'json_ld' => $json_ld, 'opengraph' => $og, 'twitter' => $twitter ) as $label => $set ) {
			if ( ! empty( $set['rejected_count'] ) ) {
				$out[] = sprintf(
					/* translators: 1: source of the metadata, 2: number of entries rejected. */
					__( '%1$s: %2$d entries were rejected — malformed, oversized, or pointing at an address ReplicaForge will not fetch.', 'replicaforge' ),
					$label,
					(int) $set['rejected_count']
				);
			}
		}

		return $out;
	}

	/**
	 * Return an empty result.
	 *
	 * @param string $message Reason.
	 * @return array<string, mixed>
	 */
	private function empty_result( $message ) {
		return array(
			'schema_version' => Content_Limits::SCHEMA_VERSION,
			'json_ld'        => array(),
			'opengraph'      => array(),
			'twitter'        => array(),
			'standard'       => array(),
			'entities'       => array(),
			'page_type'      => array( 'type' => 'unknown', 'evidence' => array( 'source' => 'none' ), 'confidence' => 0.0 ),
			'counts'         => array( 'json_ld_blocks' => 0, 'json_ld_rejected' => 0, 'meta' => 0 ),
			'trust'          => 'unverified_source_claim',
			'rejected'       => array(),
			'warnings'       => array( $message ),
			'note'           => __( 'Structured data is written by the source website and asserts nothing.', 'replicaforge' ),
		);
	}
}
