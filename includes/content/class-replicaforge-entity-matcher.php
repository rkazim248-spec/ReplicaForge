<?php
/**
 * Phase 14: bulk entity matching.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Matches source entities to destination entities for §20's bulk workflow.
 *
 * ### Signals, and what each is worth
 *
 * §20 lists SKU, exact title, normalised title, slug, product URL, structured data,
 * image fingerprint, category, and brand. They are **not** equally trustworthy, and
 * {@see self::WEIGHTS} is where the difference is recorded:
 *
 * - **SKU 0.40** — a SKU is a merchant's own identifier for a specific variant. Two
 *   products sharing one are the same product by definition.
 * - **Normalised title 0.25** — the strongest *content* signal, and the one most likely
 *   to be right when no SKU exists.
 * - **Category 0.12** and **brand 0.08** — corroborating. They cannot identify a
 *   product, only narrow a candidate set.
 * - **Slug 0.08** — a slug is a URL convenience and two products can share one across
 *   categories.
 * - **URL 0.04** — near-useless for cross-site matching, since the source and the store
 *   are different sites with different address schemes. Included because §20 asks for
 *   it and because it is *nearly* useless, which is worth recording rather than
 *   silently dropping.
 * - **Image 0.03** — see below.
 *
 * ### Why image similarity cannot drive a match
 *
 * §20 is explicit: never use image similarity alone. Two stores importing the same
 * catalogue routinely use the same manufacturer's photo for several variants, so an
 * image match is very often *correct* and completely useless — it points at a family of
 * products, not a product.
 *
 * So image weight is 0.03, and additionally
 * {@see Content_Limits::IMAGE_ONLY_CEILING} caps any match whose only signal is the
 * image. The cap sits *below* {@see Content_Limits::ACCEPT_MATCH}, which means an
 * image-only match can never be auto-applied — only shown, because "these six products
 * share a photo" is genuinely useful to a human deciding.
 *
 * ### Ambiguity is a first-class outcome
 *
 * When two destination products score the same against one source entity, neither
 * wins. The result is `ambiguous` with both candidates listed, because picking one of two
 * equally-scored products is a coin toss dressed as a match, and a coin toss that moves
 * product data is worse than an unanswered question.
 */
final class Content_Entity_Matcher {

	/**
	 * Signal weights.
	 *
	 * These are *scores*, not shares of a total, so they deliberately sum to more than
	 * 1.0: two independent signals agreeing is more certain than either alone, and the
	 * total is clamped to 1.0.
	 *
	 * The values are taken from the worked examples the specification gives - 92% for a
	 * SKU match, 87% for title plus category, 64% for image similarity alone - so the
	 * numbers a user is shown are the numbers that were promised.
	 *
	 * - sku 0.92 clears {@see Content_Limits::ACCEPT_MATCH} on its own. A SKU is a
	 *   merchant's own identifier for one specific variant, so two products sharing one
	 *   are the same product by definition: it is evidence of *identity*, not
	 *   similarity. The first draft weighted it 0.40, which meant a pure SKU match could
	 *   never reach the accept band and a merchant's own assertion was downgraded to
	 *   "review" - the signal the specification calls decisive, treated as the weakest.
	 * - 	itle 0.55 alone stays below the band, which is right: one matching title is
	 *   suggestive, not proof. With category (0.32) it reaches 0.87 and matches.
	 * - image 0.64 is the highest non-identifying score, so a photo match is *offered*
	 *   and lands in review. See {@see Content_Limits::IMAGE_ONLY_CEILING}.
	 *
	 * @var array<string, float>
	 */
	const WEIGHTS = array(
		'sku'      => 0.92,
		'title'    => 0.55,
		'category' => 0.32,
		'slug'     => 0.30,
		'url'      => 0.20,
		'brand'    => 0.20,
		'image'    => 0.64,
	);

	/**
	 * Margin required for a winner to be unambiguous.
	 *
	 * A 0.01 lead is noise. Two candidates within this margin are `ambiguous`, which
	 * costs a reviewer one click and saves them from a silent wrong answer.
	 */
	const AMBIGUITY_MARGIN = 0.05;

	/**
	 * Match a batch of source entities against destination entities.
	 *
	 * @param array<int, array<string, mixed>> $sources      Source entities.
	 * @param array<int, array<string, mixed>> $destinations Destination entities.
	 * @param array<string, mixed>             $options      Options.
	 * @return array<string, mixed>
	 */
	public function match( array $sources, array $destinations, array $options = array() ) {
		$started = microtime( true );

		// Destinations are indexed once, by every key §20 names. Indexing inside the
		// loop would be O(n·m) string operations on a 10,000-product store, which is the
		// §44 case that has to work.
		$indexes = $this->index_destinations( $destinations );

		$results   = array();
		$counts    = array( 'matched' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'skipped' => 0 );
		$processed = 0;
		$cursor    = 0;

		$total = count( $sources );
		foreach ( $sources as $index => $source ) {
			$source = (array) $source;
			$cursor++;

			// §44: a page at a time, so a 10,000-product store never loads 10,000 source
			// entities into a request. A caller pages; this class refuses to pretend one
			// page is the whole job.
			$per_page = (int) ( $options['per_page'] ?? 0 );
			if ( $per_page > 0 && $cursor > $per_page ) {
				break;
			}

			$processed++;
			$verdict = $this->match_one( $source, $destinations, $indexes, $options );
			$results[] = $verdict;

			$key = (string) $verdict['status'];
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}

		return array(
			'schema_version' => Content_Limits::SCHEMA_VERSION,
			'engine_version' => Content_Limits::ENGINE_VERSION,
			'results'        => $results,
			'counts'         => $counts,
			'progress'       => array(
				'total'     => $total,
				'processed' => $processed,
				'cursor'    => $cursor,
				'partial'   => ( $processed < $total ),
			),
			'weights'        => self::WEIGHTS,
			'thresholds'     => array(
				'min'            => Content_Limits::MIN_MATCH,
				'accept'         => Content_Limits::ACCEPT_MATCH,
				'confident'      => Content_Limits::CONFIDENT_MATCH,
				'image_only_max' => Content_Limits::IMAGE_ONLY_CEILING,
			),
			'elapsed'        => round( microtime( true ) - $started, 3 ),
			'note'           => __( 'Image similarity alone never produces an automatic match, because two products frequently share a photograph. An image-only match is always offered for review and never applied.', 'replicaforge' ),
		);
	}

	/**
	 * Match one source entity.
	 *
	 * @param array<string, mixed>             $source       Source entity.
	 * @param array<int, array<string, mixed>> $destinations Destinations.
	 * @param array<string, array<int, int>>   $indexes      Destination indexes.
	 * @param array<string, mixed>             $options      Options.
	 * @return array<string, mixed>
	 */
	private function match_one( array $source, array $destinations, array $indexes, array $options ) {
		$source_sku  = Content_Fingerprint::normalise_sku( (string) ( $source['sku'] ?? '' ) );
		$source_key  = Content_Fingerprint::normalise_title( (string) ( $source['title'] ?? ( $source['name'] ?? '' ) ) );
		$source_slug = Content_Fingerprint::normalise_slug( (string) ( $source['slug'] ?? '' ) );
		$source_img  = Content_Fingerprint::image_key( (string) ( $source['image'] ?? '' ) );
		$source_cat  = Content_Fingerprint::normalise_category( (string) ( $source['category'] ?? '' ) );
		$source_brand = Content_Fingerprint::normalise_title( (string) ( $source['brand'] ?? '' ) );

		// §13: a source entity with nothing to match on cannot be matched, and saying
		// "no match" is the answer. Falling back to "the closest by image" would be
		// inventing a correspondence.
		//
		// The photograph IS something to match on, so it belongs in this test. The
		// first draft listed only SKU, title and slug, so an entity carrying nothing but
		// a product photo was rejected here - before the image index was ever consulted -
		// and the whole image-only ceiling was unreachable dead code. Image fingerprint
		// is a matching signal precisely for the case where a product has no SKU and no
		// distinctive title, and that case could not be reached.
		if ( '' === $source_sku && '' === $source_key && '' === $source_slug && '' === $source_img ) {
			return $this->verdict( $source, 'unmatched', array(), 0.0, array( __( 'This entity has no SKU, title, slug, or photograph, so there is nothing to match on.', 'replicaforge' ) ) );
		}

		// Candidate set: every destination reachable from any index the source touches.
		$candidates = array();
		$signals   = array();
		if ( '' !== $source_sku && isset( $indexes['sku'][ $source_sku ] ) ) {
			$candidates[ $indexes['sku'][ $source_sku ] ] = true;
			$signals[] = 'sku';
		}
		if ( '' !== $source_key && isset( $indexes['title'][ $source_key ] ) ) {
			foreach ( $indexes['title'][ $source_key ] as $id ) { $candidates[ $id ] = true; }
			$signals[] = 'title';
		}
		if ( '' !== $source_slug && isset( $indexes['slug'][ $source_slug ] ) ) {
			$candidates[ $indexes['slug'][ $source_slug ] ] = true;
			$signals[] = 'slug';
		}
		if ( '' !== $source_img && isset( $indexes['image'][ $source_img ] ) ) {
			foreach ( $indexes['image'][ $source_img ] as $id ) { $candidates[ $id ] = true; }
			$signals[] = 'image';
		}

		// A SKU match is decisive and stops here. Two products sharing a SKU are the same
		// product by the merchant's own definition, and continuing to score could only
		// find a *better* candidate — which would be worse, not better.
		if ( count( $signals ) === 1 && 'sku' === $signals[0] && count( $candidates ) === 1 ) {
			$key         = (int) array_key_first( $candidates );
			$position    = (int) ( $indexes['at'][ $key ] ?? -1 );
			$destination = ( $position >= 0 ) ? (array) $destinations[ $position ] : array();
			$score       = self::WEIGHTS['sku'];
			return $this->verdict(
				$source,
				( $score >= Content_Limits::ACCEPT_MATCH ) ? 'matched' : 'review',
				array( $this->candidate( $destination, (int) ( $destination['entity_id'] ?? 0 ), $score, array( 'sku' => $score ) ) ),
				$score,
				array( 'SKU match: a merchant identifier is the merchant\'s own statement that these are the same product.' ),
				(int) ( $destination['entity_id'] ?? 0 )
			);
		}

		if ( array() === $candidates ) {
			return $this->verdict( $source, 'unmatched', array(), 0.0, array( __( 'No destination product matched on SKU, title, slug, or image.', 'replicaforge' ) ) );
		}

		// Score every candidate.
		$scored = array();
		foreach ( array_keys( $candidates ) as $id ) {
			$destination = $destinations[ (int) ( $indexes['at'][ (int) $id ] ?? -1 ) ] ?? array();
			$breakdown   = $this->score( $source_sku, $source_key, $source_slug, $source_img, $source_cat, $source_brand, (string) ( $source['url'] ?? '' ), (array) $destination );
			$total = min( 1.0, array_sum( $breakdown ) );

			// The §20 image rule, applied here rather than at apply time so a capped match
			// is *shown* as capped rather than silently accepted at its raw score.
			// `image_only` means "no signal other than the photograph". The ceiling caps
			// what such a match may report, and it sits below ACCEPT_MATCH, which is what
			// makes the status rule further down explicit rather than accidental.
			$non_image  = $total - ( $breakdown['image'] ?? 0.0 );
			$image_only = ( $non_image <= 0.0 );
			if ( $image_only ) {
				$total = min( $total, Content_Limits::IMAGE_ONLY_CEILING );
			}

			// The entity id comes from the record, never from the index key, which is a
			// list position.
			$scored[] = $this->candidate( (array) $destination, (int) ( $destination['entity_id'] ?? 0 ), $total, $breakdown, $image_only );
		}

		usort( $scored, static function ( $left, $right ) { return (float) $right['score'] <=> (float) $left['score']; } );

		$best = $scored[0] ?? array( 'score' => 0.0 );
		$best_score = (float) $best['score'];

		if ( $best_score < Content_Limits::MIN_MATCH ) {
			return $this->verdict( $source, 'unmatched', array_slice( $scored, 0, 3 ), $best_score, array( __( 'The closest candidate was below the minimum similarity, so no match was offered.', 'replicaforge' ) ) );
		}

		// Ambiguity. A tie inside the margin is not a match with a small lead; it is two
		// answers, and picking one is a coin toss that moves product data.
		$second = $scored[1] ?? null;
		if ( null !== $second && ( (float) $second['score'] >= ( $best_score - self::AMBIGUITY_MARGIN ) ) ) {
			return $this->verdict(
				$source,
				'ambiguous',
				array_slice( $scored, 0, 3 ),
				$best_score,
				array( __( 'Two or more destination products scored the same. ReplicaForge did not choose between them, because a coin toss that moves product data is worse than an unanswered question.', 'replicaforge' ) )
			);
		}

		// Both conditions are named and both are needed. The score test handles the
		// ordinary case; the image_only test is the specification's rule stated
		// directly, so that raising {@see Content_Limits::IMAGE_ONLY_CEILING} later can
		// never quietly turn image matching into automatic matching.
		$status = ( $best_score >= Content_Limits::ACCEPT_MATCH && empty( $best['image_only'] ) ) ? 'matched' : 'review';

		return $this->verdict( $source, $status, array_slice( $scored, 0, 3 ), $best_score, $this->explain( $best ), (int) $best['entity_id'] );
	}

	/**
	 * Score one candidate against the source keys.
	 *
	 * @param string               $source_sku  Source SKU key.
	 * @param string               $source_key  Source title key.
	 * @param string               $source_slug Source slug key.
	 * @param string               $source_img  Source image key.
	 * @param string               $source_cat  Source category key.
	 * @param string               $source_brand Source brand key.
	 * @param string               $source_url  Source URL.
	 * @param array<string, mixed> $destination Destination entity.
	 * @return array<string, float>
	 */
	private function score( $source_sku, $source_key, $source_slug, $source_img, $source_cat, $source_brand, $source_url, array $destination ) {
		$out = array();

		$out['sku'] = ( '' !== $source_sku && $source_sku === Content_Fingerprint::normalise_sku( (string) ( $destination['sku'] ?? '' ) ) )
			? self::WEIGHTS['sku'] : 0.0;

		$dest_key = Content_Fingerprint::normalise_title( (string) ( $destination['title'] ?? ( $destination['name'] ?? '' ) ) );
		$out['title'] = ( '' !== $source_key && $source_key === $dest_key )
			? self::WEIGHTS['title']
			: $this->partial_text( $source_key, $dest_key, self::WEIGHTS['title'] );

		$out['slug'] = ( '' !== $source_slug && $source_slug === Content_Fingerprint::normalise_slug( (string) ( $destination['slug'] ?? '' ) ) )
			? self::WEIGHTS['slug'] : 0.0;

		$out['category'] = ( '' !== $source_cat && $source_cat === Content_Fingerprint::normalise_category( (string) ( $destination['category'] ?? ( is_array( $destination['categories'] ?? null ) ? implode( '/', (array) $destination['categories'] ) : '' ) ) ) )
			? self::WEIGHTS['category'] : 0.0;

		$out['brand'] = ( '' !== $source_brand && $source_brand === Content_Fingerprint::normalise_title( (string) ( $destination['brand'] ?? '' ) ) )
			? self::WEIGHTS['brand'] : 0.0;

		// A URL match is recorded because §20 asks for it, at a weight that says plainly
		// how little it is worth across two different sites. The first draft read
		// `$source['url']` inside this method, where `$source` does not exist — so the
		// URL signal was always `null > ''`, always false, and the weight was declared but
		// unreachable.
		$out['url'] = ( '' !== $source_url && $source_url === (string) ( $destination['url'] ?? '' ) )
			? self::WEIGHTS['url'] : 0.0;

		$out['image'] = ( '' !== $source_img && $source_img === Content_Fingerprint::image_key( (string) ( $destination['image'] ?? (string) ( $destination['image_key'] ?? '' ) ) ) )
			? self::WEIGHTS['image'] : 0.0;

		return $out;
	}

	/**
	 * Score two titles that are not identical.
	 *
	 * Token overlap rather than string similarity, because two shops spell the same
	 * product "Blue Cotton Shirt" and "Cotton Shirt, Blue" and neither an edit-distance
	 * nor a substring test catches that reliably.
	 *
	 * @param string $left     Left.
	 * @param string $right    Right.
	 * @param float  $max      Maximum weight.
	 * @return float
	 */
	private function partial_text( $left, $right, $max ) {
		if ( '' === $left || '' === $right ) {
			return 0.0;
		}
		// Stop words removed: they are in almost every product title and carry no
		// information about which product this is.
		$stop = array( 'the', 'a', 'an', 'and', 'with', 'for', 'of', 'in', 'by' );
		$left_tokens  = array_values( array_diff( preg_split( '/[^\p{L}\p{N}]+/u', $left, -1, PREG_SPLIT_NO_EMPTY ) ?: array(), $stop ) );
		$right_tokens = array_values( array_diff( preg_split( '/[^\p{L}\p{N}]+/u', $right, -1, PREG_SPLIT_NO_EMPTY ) ?: array(), $stop ) );

		if ( array() === $left_tokens || array() === $right_tokens ) {
			return 0.0;
		}
		$shared = array_intersect( $left_tokens, $right_tokens );
		if ( array() === $shared ) {
			return 0.0;
		}

		// Jaccard-style over the *union*, so a long title padded with common words does
		// not score a perfect match.
		$union = array_unique( array_merge( $left_tokens, $right_tokens ) );
		return round( $max * ( count( $shared ) / max( 1, count( $union ) ) ), 4 );
	}

	/**
	 * Build destination indexes.
	 *
	 * @param array<int, array<string, mixed>> $destinations Destinations.
	 * @return array<string, mixed>
	 *
	 * Every index stores a **position** in the $destinations list, never an entity id,
	 * and t converts a key back to a position. The first draft stored entity ids and
	 * then looked them up with $destinations[ $id ] - but $destinations is a list
	 * keyed  , 1, 2, ..., so a lookup of product 182 missed and yielded an empty
	 * record. Every signal then scored 0, every page reported unmatched, and the
	 * matcher looked like it was refusing to match rather than unable to read its own
	 * candidates. Only the SKU short-circuit survived, because it does not score at all.
	 */
	private function index_destinations( array $destinations ) {
		$out = array( 'sku' => array(), 'title' => array(), 'slug' => array(), 'image' => array(), 'at' => array() );

		foreach ( array_values( $destinations ) as $position => $destination ) {
			$destination = (array) $destination;
			// The *position* is a candidate's identity. A zero or absent entity id is
			// legal for a raw record, and keying on the id would collapse every such
			// record onto key 0 - one candidate standing in for hundreds.
			$key = (int) $position;
			$out['at'][ $key ] = (int) $position;

			$sku = Content_Fingerprint::normalise_sku( (string) ( $destination['sku'] ?? '' ) );
			if ( '' !== $sku ) {
				$out['sku'][ $sku ] = $key;
			}
			$title = Content_Fingerprint::normalise_title( (string) ( $destination['title'] ?? ( $destination['name'] ?? '' ) ) );
			if ( '' !== $title ) {
				$out['title'][ $title ][] = $key;
			}
			$slug = Content_Fingerprint::normalise_slug( (string) ( $destination['slug'] ?? ( $destination['slug_key'] ?? '' ) ) );
			if ( '' !== $slug ) {
				$out['slug'][ $slug ][] = $key;
			}
			$image = (string) ( $destination['image_key'] ?? '' );
			if ( '' === $image ) {
				$image = Content_Fingerprint::image_key( (string) ( $destination['image'] ?? '' ) );
			}
			if ( '' !== $image ) {
				$out['image'][ $image ][] = $key;
			}
		}

		return $out;
	}

	/**
	 * Build a candidate record.
	 *
	 * @param array<string, mixed> $destination Destination entity.
	 * @param int                   $id         Identity key.
	 * @param float                 $score      Score.
	 * @param array<string, float>  $breakdown  Signal breakdown.
	 * @param bool                  $image_only Whether this is an image-only match.
	 * @return array<string, mixed>
	 */
	private function candidate( array $destination, $id, $score, array $breakdown = array(), $image_only = false ) {
		return array(
			'entity_id'  => (int) ( $destination['entity_id'] ?? $id ),
			'title'      => (string) ( $destination['title'] ?? ( $destination['name'] ?? '' ) ),
			'sku'        => (string) ( $destination['sku'] ?? '' ),
			'score'      => round( (float) $score, 4 ),
			'signals'    => $breakdown,
			'image_only' => (bool) $image_only,
		);
	}

	/**
	 * Explain a match in the §20 form.
	 *
	 * @param array<string, mixed> $best Best candidate.
	 * @return array<int, string>
	 */
	private function explain( array $best ) {
		$out   = array();
		$order = array( 'sku', 'title', 'category', 'brand', 'slug', 'url', 'image' );
		foreach ( $order as $signal ) {
			$weight = (float) ( $best['signals'][ $signal ] ?? 0.0 );
			if ( $weight <= 0.0 ) {
				continue;
			}
			$out[] = sprintf(
				/* translators: 1: signal name, 2: percentage of the maximum, 3: percentage score. */
				__( '%1$s matched (worth %2$d%% of the match, %3$d%% overall)', 'replicaforge' ),
				$signal,
				(int) round( 100 * self::WEIGHTS[ $signal ] ),
				(int) round( 100 * (float) $best['score'] )
			);
		}
		if ( ! empty( $best['image_only'] ) ) {
			$out[] = __( 'This is an image-only match, which is capped and always needs review, because two products frequently share a photograph.', 'replicaforge' );
		}
		return $out;
	}

	/**
	 * Build a verdict record.
	 *
	 * @param array<string, mixed>             $source      Source entity.
	 * @param string                           $status      Status.
	 * @param array<int, mixed>                $candidates  Candidates.
	 * @param float                            $score       Best score.
	 * @param array<int, string>               $notes       Notes.
	 * @param int                              $entity_id   Winning entity id.
	 * @return array<string, mixed>
	 */
	private function verdict( array $source, $status, array $candidates, $score, array $notes, $entity_id = 0 ) {
		return array(
			'source'      => array(
				'entity_id'    => (string) ( $source['entity_id'] ?? '' ),
				'title'        => (string) ( $source['title'] ?? ( $source['name'] ?? '' ) ),
				'sku'          => (string) ( $source['sku'] ?? '' ),
				'source_url'   => (string) ( $source['url'] ?? '' ),
				'fingerprint'  => (string) ( $source['fingerprint'] ?? '' ),
			),
			'status'      => (string) $status,
			'score'       => round( (float) $score, 4 ),
			'entity_id'   => (int) $entity_id,
			'candidates'  => array_values( $candidates ),
			'notes'       => array_values( $notes ),
			'requires_review' => ( 'matched' !== (string) $status ),
		);
	}
}
