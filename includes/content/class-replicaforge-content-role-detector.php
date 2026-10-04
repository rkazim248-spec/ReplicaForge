<?php
/**
 * Phase 14: semantic content role detection.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns a semantic {@see Content_Limits::ROLES} role to each piece of source content.
 *
 * ### What this class does *not* do, and why that matters
 *
 * Phase 2's {@see Component_Detector} already classifies every element with a type and a
 * role, already extracts card fields, and already groups repeated cards. The first draft
 * of this class re-derived all three — its own price regex, its own repeated-block
 * bucketing, its own heading heuristics — and would have disagreed with Phase 2 wherever
 * the two differed, which is the specific way a content model becomes wrong while looking
 * complete.
 *
 * So this class **consumes** Phase 2's output and adds only what Phase 2 structurally
 * cannot do:
 *
 * 1. **The §3 semantic vocabulary.** Phase 2 says `heading` with role `h1`. That is what
 *    the markup was, not what the content is *for*, and only the role can be mapped to a
 *    destination field. An `h1` is a `hero_heading` on a page with a hero and a
 *    `blog_title` on a post.
 * 2. **The §6 dynamism class**, which depends on the mapping destination and so cannot
 *    be decided at analysis time.
 * 3. **Value signals Phase 2 does not extract** — dates, email addresses, telephone
 *    numbers, author lines, "out of 5" ratings.
 * 4. **Structured-data and Phase 13 visual context** as refinements, never as
 *    substitutes.
 *
 * ### Every role is a claim with a confidence, never a certainty
 *
 * A role it cannot justify is left `unclassified` rather than guessed. A wrong role is
 * worse than no role, because a wrong role would map a paragraph into a price field.
 */
final class Content_Role_Detector {

	/**
	 * Class/id vocabulary per role, as a *refinement* signal.
	 *
	 * Deliberately small and specific. A large keyword list produces false positives: a
	 * `.title` class is a section heading far more often than a hero heading, and
	 * matching it to `hero_heading` would map a product's name into a hero slot. Because
	 * these only *add* to Phase 2's classification, a false positive here raises a wrong
	 * score rather than replacing a right one.
	 *
	 * @var array<string, array<int, string>>
	 */
	const SIGNALS = array(
		'site_logo'           => array( 'site-logo', 'brand-mark', 'site-branding' ),
		'site_name'           => array( 'site-name', 'site-title', 'brand-name', 'wordmark' ),
		'navigation_label'    => array( 'nav-link', 'menu-item', 'primary-menu' ),
		'hero_heading'        => array( 'hero-title', 'hero-heading', 'banner-title' ),
		'hero_description'    => array( 'hero-subtitle', 'hero-description', 'hero-text' ),
		'hero_cta'            => array( 'hero-cta', 'hero-button', 'banner-cta' ),
		'section_heading'     => array( 'section-title', 'section-heading' ),
		'body_text'           => array( 'body-copy', 'entry-content' ),
		'feature_title'       => array( 'feature-title', 'feature-name', 'service-title' ),
		'feature_description' => array( 'feature-text', 'service-excerpt' ),
		'product_name'        => array( 'product-title', 'product-name', 'woocommerce-loop-product__title' ),
		'product_price'       => array( 'product-price', 'woocommerce-loop-product__price', 'woocommerce-Price-amount' ),
		'product_sale_price'  => array( 'sale-price', 'price-sale' ),
		'product_image'       => array( 'product-image', 'product-thumb', 'product_img' ),
		'product_gallery'     => array( 'product-gallery', 'woocommerce-product-gallery' ),
		'product_description' => array( 'product-description', 'product-summary' ),
		'product_category'    => array( 'product-cat', 'product-category', 'posted-in' ),
		'product_rating'      => array( 'star-rating', 'woocommerce-review-link', 'aggregate-rating' ),
		'review_author'       => array( 'review-author', 'reviewer' ),
		'review_text'         => array( 'review-text', 'review-content', 'comment-text' ),
		'blog_title'          => array( 'post-title', 'entry-title', 'article-title' ),
		'blog_excerpt'        => array( 'post-excerpt', 'entry-summary', 'teaser' ),
		'blog_author'         => array( 'post-author', 'byline' ),
		'blog_date'           => array( 'post-date', 'entry-date', 'timestamp' ),
		'blog_category'       => array( 'post-category', 'entry-category', 'cat-links' ),
		'phone'               => array( 'contact-phone' ),
		'email'               => array( 'contact-email' ),
		'address'             => array( 'street-address', 'postal-address' ),
		'social_link'         => array( 'social', 'follow-us' ),
		'footer_text'         => array( 'footer-text', 'copyright' ),
	);

	/**
	 * Phase 2 component types that are collection members.
	 *
	 * Read from {@see Component_Detector}'s own list, so a new card type Phase 2 adds is
	 * recognised here without a second edit.
	 *
	 * @var array<int, string>
	 */
	const CARD_TYPES = array(
		'card',
		'product_card',
		'pricing_card',
		'testimonial_card',
		'blog_card',
		'feature_card',
		'team_card',
		'portfolio_card',
	);

	/**
	 * The maximum a visual signal may contribute.
	 *
	 * §26 says visual similarity must never create a product mapping. This is how that is
	 * enforced rather than advised: 0.12 is well under a third of the
	 * {@see self::MIN_SCORE} floor, so no amount of visual agreement can lift an
	 * unclassified item into a role.
	 */
	const MAX_VISUAL_CONTRIBUTION = 0.12;

	/**
	 * Minimum score for a role to be assigned at all.
	 */
	const MIN_SCORE = 0.35;

	/**
	 * Structured-data evidence.
	 *
	 * @var array<string, mixed>
	 */
	private $structured;

	/**
	 * Phase 13 visual representation, when available.
	 *
	 * @var array<string, mixed>
	 */
	private $visual;

	/**
	 * URL validator.
	 *
	 * @var Url_Validator
	 */
	private $urls;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $structured Structured-data read result.
	 * @param array<string, mixed> $visual     Phase 13 visual representation.
	 * @param Url_Validator|null  $urls       Optional URL validator.
	 */
	public function __construct( array $structured = array(), array $visual = array(), $urls = null ) {
		$this->structured = $structured;
		$this->visual     = $visual;
		$this->urls       = $urls instanceof Url_Validator ? $urls : new Url_Validator();
	}

	/**
	 * Detect roles for every component in a representation.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @return array<int, array<string, mixed>>
	 */
	public function detect( array $representation ) {
		$page_type   = (string) ( $this->structured['page_type']['type'] ?? 'unknown' );
		$entity_type = $this->entity_type_for( $page_type );

		$components = isset( $representation['components'] ) && is_array( $representation['components'] )
			? $representation['components']
			: array();
		$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] )
			? $representation['sections']
			: array();

		// Phase 2's own card grouping, consumed rather than recomputed. A component
		// that is a member of a `card_group` is one of a repeating set, which is the
		// single strongest signal that its title is a *product* name rather than a page
		// heading.
		$group_of = $this->group_membership( $components );
		$by_id    = array();
		foreach ( $components as $component ) {
			if ( is_array( $component ) && ! empty( $component['id'] ) ) {
				$by_id[ (string) $component['id'] ] = $component;
			}
		}
		$section_types = array();
		foreach ( $sections as $section ) {
			if ( is_array( $section ) && ! empty( $section['id'] ) ) {
				$section_types[ (string) $section['id'] ] = strtolower( (string) ( $section['type'] ?? '' ) );
			}
		}

		$out = array();
		foreach ( $components as $index => $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$id = (string) ( $component['id'] ?? ( 'component_' . ( $index + 1 ) ) );

			// A card is a *composite*: Phase 2 stored name, price, rating, image and
			// button on the one component, and §2's content model has a bucket per role.
			// So a component may yield several items, and `classify()` returns a list
			// rather than one item. The first draft returned `$items[0]` and silently
			// dropped the other four fields of every product card on the page.
			// Resolved into a local before use. `isset( $by_id[ $group_of[ $id ] ] )`
			// evaluates the *inner* offset first, so a component that is not in a card
			// group raised "Undefined array key" on every page that had no groups at
			// all - and the warning was the only symptom of a condition that is
			// entirely normal.
			$group_id = isset( $group_of[ $id ] ) ? (string) $group_of[ $id ] : '';
			$group    = ( '' !== $group_id && isset( $by_id[ $group_id ] ) ) ? $by_id[ $group_id ] : array();

			foreach ( $this->classify(
				$component,
				$id,
				$entity_type,
				'' === $group_id ? null : $group_id,
				$group,
				$section_types[ (string) ( $component['section_id'] ?? '' ) ] ?? ''
			) as $item ) {
				$out[] = $item;
				if ( count( $out ) >= Content_Limits::MAX_CONTENT_ITEMS ) {
					break 2;
				}
			}
		}

		return $out;
	}

	/**
	 * Return the entity type this page's content belongs to.
	 *
	 * @param string $page_type Page type.
	 * @return string
	 */
	public function entity_type_for( $page_type ) {
		$map = array(
			'product_detail' => 'product',
			'blog_post'      => 'post',
			'listing'        => 'listing',
			'organization'   => 'organization',
			'video'          => 'video',
			'archive'        => 'collection',
		);
		return (string) ( $map[ (string) $page_type ] ?? 'unknown' );
	}

	/**
	 * Build a component-id to group-id map from Phase 2's card groups.
	 *
	 * @param array<int, array<string, mixed>> $components Components.
	 * @return array<string, string>
	 */
	private function group_membership( array $components ) {
		$out = array();
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || 'card_group' !== ( $component['type'] ?? '' ) ) {
				continue;
			}
			$group_id = (string) ( $component['id'] ?? '' );
			if ( '' === $group_id ) {
				continue;
			}
			foreach ( (array) ( $component['children'] ?? array() ) as $member ) {
				$out[ (string) $member ] = $group_id;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Classification
	 * ------------------------------------------------------------------ */

	/**
	 * Classify one component.
	 *
	 * @param array<string, mixed> $component   Component.
	 * @param string               $id          Component id.
	 * @param string               $entity_type Entity type.
	 * @param string|null          $group_id    Card group id, if a member.
	 * @param array<string, mixed> $group       The group component, if a member.
	 * @param string               $section     Section type.
	 * @return array<int, array<string, mixed>> One or more content items.
	 */
	private function classify( array $component, $id, $entity_type, $group_id, array $group, $section ) {
		$type      = strtolower( (string) ( $component['type'] ?? '' ) );
		$phase2    = strtolower( (string) ( $component['role'] ?? '' ) );
		$text      = trim( (string) ( $component['text'] ?? '' ) );
		$fields    = (array) ( $component['fields'] ?? array() );
		$haystack  = $this->vocabulary( $component );
		$score     = array();

		// 0. A card group is not content. It has no fields of its own, and mapping it
		//    would map a *container* into a destination field.
		//
		//    The argument list is `($role, $score, $evidence, $dyn_class, $dyn_conf)`
		//    — it is not `($scores, $score, $evidence, ...)` as it was before the
		//    unused `$scores` parameter was dropped. Passing the old shape shifted every
		//    argument by one, so `$dyn_class` received the *evidence array* and
		//    `(string) $dyn_class` raised "Array to string conversion" on every card
		//    group on the page, and the dynamism class was an array rather than one of
		//    the four declared values.
		if ( 'card_group' === $type ) {
			return array(
				$this->item(
					$component,
					$id,
					$entity_type,
					$section,
					'unclassified',
					0.0,
					array( 'a repeated-structure container, which holds content rather than being content' ),
					'unknown',
					0.0,
					''
				),
			);
		}

		// 1. Phase 2's own classification is the base. It is tag-derived and carries a
		//    confidence of 0.9-0.98, so it is far stronger evidence than anything this
		//    class adds, and it is *read* rather than re-derived.
		$base = $this->base_role( $type, $phase2, $section );
		if ( '' !== $base ) {
			$score[ $base ] = 0.5;
		}

		// 2. Card context. A `product_card`'s title is a product name; a `blog_card`'s
		//    is a post title. Phase 2 already extracted the fields, so this only
		//    decides what they *mean*.
		//
		//    The card type is the group's when the component is a member of one, and
		//    the component's **own** type otherwise. That second case is not a
		//    refinement: a product page's main product card is usually a card with
		//    *no siblings*, so it belongs to no `card_group` at all. The first draft
		//    gated this block on `$group_id !== null`, so the single most important
		//    card on the most important kind of page scored nothing from its own type
		//    and fell through to `unclassified` — a product page with one product
		//    produced no `product_name` and no mapping for its headline field.
		$card_type = strtolower( (string) ( ( null !== $group_id ? ( $group['card_type'] ?? '' ) : '' ) ?: $type ) );
		$card_role = '';
		if ( in_array( $card_type, self::CARD_TYPES, true ) || array() !== $fields ) {
			$card_role = $this->card_context( $card_type, $component, $fields );
			if ( '' !== $card_role ) {
				$score[ $card_role ] = ( $score[ $card_role ] ?? 0 ) + 0.35;
			}
		}

		// 3. Structured data. The strongest available evidence, and still only a claim.
		foreach ( $this->structured_signals( $entity_type, $component, $text ) as $role => $weight ) {
			$score[ $role ] = ( $score[ $role ] ?? 0 ) + $weight;
		}

		// 4. Class/id vocabulary, as a refinement only.
		foreach ( self::SIGNALS as $candidate => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					$score[ $candidate ] = ( $score[ $candidate ] ?? 0 ) + 0.2;
					break;
				}
			}
		}

		// 5. Value signals Phase 2 does not extract.
		foreach ( $this->value_signals( $text, $component ) as $role => $weight ) {
			$score[ $role ] = ( $score[ $role ] ?? 0 ) + $weight;
		}

		// 6. Phase 13 visual context. Bounded, and never sufficient on its own.
		foreach ( $this->visual_signals( $component ) as $role => $weight ) {
			$score[ $role ] = ( $score[ $role ] ?? 0 ) + $weight;
		}

		arsort( $score );
		$best       = (string) key( $score );
		$best_score = (float) ( $score[ $best ] ?? 0 );

		// `unclassified` is a real outcome. A component with no value and no distinctive
		// context — an icon, a badge, a divider — genuinely has no mappable role, and
		// inventing one is the failure §13 exists to prevent.
		if ( '' === $best || $best_score < self::MIN_SCORE || ! Content_Limits::is_role( $best ) ) {
			return array(
				$this->item(
					$component,
					$id,
					$entity_type,
					$section,
					'unclassified',
					$best_score,
					array( 'no signal was strong enough to assign a role' ),
					'unknown',
					0.0,
					''
				),
			);
		}

		$dynamism = $this->dynamism( $best, $entity_type, $group_id );
		$evidence = $this->evidence( $component, $best, $best_score, $group_id, $card_type );

		// Confidence starts from Phase 2's own component confidence, because a
		// structurally-confident component is a trustworthy starting point, and is
		// capped at 0.9: a role detected from markup is never a certainty, and 1.0 would
		// let a mapping auto-apply on the strength of a guess.
		$base_confidence = isset( $component['confidence'] ) && is_numeric( $component['confidence'] )
			? (float) $component['confidence']
			: 0.6;
		$confidence      = round( min( 0.9, ( $base_confidence * 0.6 ) + ( $best_score * 0.4 ) ), 3 );

		// A composite card carries several roles at once — a name, a price, a rating,
		// an image, a button. §2's content model has a bucket per role, so a card is
		// expanded into one item per field it *actually* has. The fields it does not
		// have are simply absent, never filled with a placeholder: a card with no
		// rating simply has no `product_rating` item, which is the finding.
		$expanded = $this->card_field_items( $component, $id, $entity_type, $section, (array) ( $component['fields'] ?? array() ), $score );
		if ( array() !== $expanded ) {
			return $expanded;
		}

		return array( $this->item( $component, $id, $entity_type, $section, $best, $score, $best_score, $evidence, $dynamism['class'], $dynamism['confidence'], $dynamism['reason'], $confidence ) );
	}

	/**
	 * Map Phase 2's type and role to a §3 semantic role.
	 *
	 * @param string $type    Phase 2 type.
	 * @param string $phase2  Phase 2 role.
	 * @param string $section Section type.
	 * @return string
	 */
	private function base_role( $type, $phase2, $section ) {
		$is_hero = ( false !== strpos( $section, 'hero' ) || false !== strpos( $section, 'banner' ) );
		$is_post = ( false !== strpos( $section, 'post' ) || false !== strpos( $section, 'article' ) || false !== strpos( $section, 'blog' ) );

		switch ( $type ) {
			case 'heading':
				$level = (int) str_replace( 'h', '', $phase2 );
				if ( 1 === $level ) {
					return $is_hero ? 'hero_heading' : ( $is_post ? 'blog_title' : 'hero_heading' );
				}
				return $is_post ? 'blog_title' : 'section_heading';
			case 'paragraph':
				return $is_hero ? 'hero_description' : ( false !== strpos( $section, 'feature' ) ? 'feature_description' : 'body_text' );
			case 'image':
				return ( false !== strpos( $section, 'header' ) || false !== strpos( $section, 'footer' ) ) ? 'site_logo' : 'product_image';
			case 'background_image':
				return 'product_image';
			case 'button':
				return $is_hero ? 'hero_cta' : 'button';
			case 'navigation_item':
				return 'navigation_label';
			case 'social_link':
				return 'social_link';
			case 'form':
			case 'form_field':
			case 'accordion':
			case 'tabs':
			case 'tab':
			case 'list':
			case 'icon':
			case 'badge':
				// These are real page structure with no mappable content role. Reporting
				// them as `unclassified` is correct; inventing a role is what §13 forbids.
				return '';
			default:
				return '';
		}
	}

	/**
	 * Return the role a card member's field plays.
	 *
	 * @param string               $card_type Card type.
	 * @param array<string, mixed> $component Component.
	 * @param array<string, mixed> $fields    Phase 2 card fields.
	 * @return string
	 */
	private function card_context( $card_type, array $component, array $fields ) {
		$text = trim( (string) ( $component['text'] ?? '' ) );

		// A card whose own Phase 2 fields carry a price is a product card whatever its
		// class name said. That is the DOM's own evidence, not a guess.
		$has_price = ( isset( $fields['price'] ) && '' !== $fields['price'] ) || ( isset( $fields['sale_price'] ) && '' !== $fields['sale_price'] );

		if ( 'product_card' === $card_type || ( '' !== $card_type && $has_price ) ) {
			// Which field is this component? Phase 2 stored card fields on the card
			// itself, so a *card* component carries product_name, product_price, and
			// product_image at once.
			if ( $has_price ) {
				// A card is a composite: report the dominant role and let the card's
				// `fields` carry the rest, which is why §2's content model has separate
				// buckets rather than one role per item.
				return 'product_name';
			}
			return 'product_name';
		}
		if ( 'blog_card' === $card_type || 'portfolio_card' === $card_type ) {
			return 'blog_title';
		}
		if ( 'feature_card' === $card_type ) {
			return 'feature_title';
		}
		if ( 'testimonial_card' === $card_type ) {
			return 'review_text';
		}
		if ( 'team_card' === $card_type ) {
			return 'blog_author';
		}
		if ( 'pricing_card' === $card_type ) {
			return 'feature_title';
		}

		return '';
	}

	/**
	 * Return role signals from structured-data claims.
	 *
	 * @param string               $entity_type Entity type.
	 * @param array<string, mixed> $component   Component.
	 * @param string               $text        Text.
	 * @return array<string, float>
	 */
	private function structured_signals( $entity_type, array $component, $text ) {
		$out = array();

		if ( 'product' === $entity_type ) {
			$has_claimed_price = false;
			foreach ( (array) ( $this->structured['entities'] ?? array() ) as $entity ) {
				if ( is_array( $entity ) && 'product' === ( $entity['type'] ?? '' ) && isset( $entity['price'] ) ) {
					$has_claimed_price = true;
					break;
				}
			}
			if ( $has_claimed_price ) {
				// A price present in the page's own declared data is strong evidence
				// that the visible numbers here are prices — and still only evidence.
				$out['product_price']      = 0.3;
				$out['product_sale_price'] = 0.15;
			}
			$out['product_name'] = ( $out['product_name'] ?? 0 ) + 0.2;
		} elseif ( 'post' === $entity_type ) {
			$out['blog_title'] = ( $out['blog_title'] ?? 0 ) + 0.2;
		} elseif ( 'author' === $entity_type ) {
			$out['blog_author'] = ( $out['blog_author'] ?? 0 ) + 0.3;
		}

		return $out;
	}

	/**
	 * Return role signals from the value's own shape.
	 *
	 * Only signals Phase 2 does not already extract. Price, image, and rating extraction
	 * is Phase 2's {@see Component_Detector::extract_price()} and its card fields, and
	 * duplicating that here would give two answers.
	 *
	 * @param string               $text      Text.
	 * @param array<string, mixed> $component Component.
	 * @return array<string, float>
	 */
	private function value_signals( $text, array $component ) {
		$out = array();
		if ( '' === $text || strlen( $text ) > 200 ) {
			return $out;
		}

		if ( 1 === preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $text ) ) {
			$out['email'] = 0.6;
		}

		if ( 1 === preg_match( '/\b(\d{4}-\d{2}-\d{2}|\d{1,2}\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}|\w{3,9}\s+\d{1,2},?\s+\d{4})\b/i', $text ) ) {
			$out['blog_date'] = 0.4;
		}

		if ( 1 === preg_match( '/^(by|posted by|written by|reviewed by)\s+\S/i', $text ) ) {
			$out['blog_author']   = 0.45;
			$out['review_author'] = 0.25;
		}

		if ( strlen( $text ) <= 40 && 1 === preg_match( '/^[+()\d][\d\s().-]{6,}$/', $text ) ) {
			$out['phone'] = 0.35;
		}

		$href = strtolower( (string) ( $component['url'] ?? '' ) );
		if ( '' !== $href ) {
			foreach ( array( 'tel:' => 'phone', 'mailto:' => 'email' ) as $prefix => $role ) {
				if ( 0 === strpos( $href, $prefix ) ) {
					// A `tel:` or `mailto:` href is the *scheme* saying what the value
					// is, which is far stronger than a numeric-looking string.
					$out[ $role ] = 0.6;
				}
			}
		}

		return $out;
	}

	/**
	 * Return role signals from Phase 13 visual evidence.
	 *
	 * §26 is explicit that visual similarity alone must never create a mapping, and this
	 * method can only ever *add* up to {@see self::MAX_VISUAL_CONTRIBUTION} — which is
	 * less than a third of {@see self::MIN_SCORE}, so no amount of visual agreement can
	 * lift an unclassified component into a role.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return array<string, float>
	 */
	private function visual_signals( array $component ) {
		$out = array();
		if ( array() === $this->visual ) {
			return $out;
		}
		$id = (string) ( $component['id'] ?? '' );
		if ( '' === $id ) {
			return $out;
		}

		// Did Phase 13 find this element inside a visual grid? A grid is a repeated
		// arrangement, which corroborates a collection without asserting anything about
		// what the content means.
		foreach ( (array) ( $this->visual['geometry']['grids'] ?? array() ) as $grid ) {
			if ( is_array( $grid ) && in_array( $id, (array) ( $grid['items'] ?? array() ), true ) ) {
				$out['product_name'] = self::MAX_VISUAL_CONTRIBUTION;
				$out['blog_title']   = self::MAX_VISUAL_CONTRIBUTION;
				return $out;
			}
		}

		return $out;
	}

	/**
	 * Return the §6 dynamism class.
	 *
	 * @param string      $role        Role.
	 * @param string      $entity_type Entity type.
	 * @param string|null $group_id    Card group id.
	 * @return array<string, mixed>
	 */
	private function dynamism( $role, $entity_type, $group_id ) {
		// Dynamic by nature: changes on its own schedule, and belongs to the
		// destination's data rather than to the source's copy.
		$dynamic = array( 'product_price', 'product_sale_price', 'product_rating', 'blog_date', 'blog_excerpt', 'review_text', 'review_author' );

		if ( in_array( $role, $dynamic, true ) ) {
			return array( 'class' => 'dynamic', 'confidence' => 0.75, 'reason' => 'a field that changes on its own schedule' );
		}

		// A card member's title is semi-dynamic: the *pattern* is source-owned, the
		// individual values are not.
		if ( null !== $group_id && in_array( $role, array( 'product_name', 'blog_title' ), true ) ) {
			return array( 'class' => 'semi_dynamic', 'confidence' => 0.65, 'reason' => 'a field inside a repeating block' );
		}

		if ( 'unknown' === $entity_type ) {
			// §6: absence of evidence is not evidence of static. A page whose type could
			// not be determined has content whose behaviour is equally undetermined.
			return array( 'class' => 'unknown', 'confidence' => 0.0, 'reason' => 'the page type could not be determined' );
		}

		return array( 'class' => 'static', 'confidence' => 0.6, 'reason' => 'page copy, which does not change on its own' );
	}

	/**
	 * Return readable evidence for a classification.
	 *
	 * @param array<string, mixed> $component Component.
	 * @param string               $role      Role.
	 * @param float                $score     Score.
	 * @param string|null          $group_id  Card group id.
	 * @param string               $card_type Card type, if the component is a card.
	 * @return array<int, string>
	 */
	private function evidence( array $component, $role, $score, $group_id, $card_type = '' ) {
		$out = array( sprintf( 'role=%s score=%.2f', $role, $score ) );

		$out[] = sprintf( 'phase2_type=%s phase2_role=%s', (string) ( $component['type'] ?? '' ), (string) ( $component['role'] ?? '' ) );
		if ( null !== $group_id ) {
			$out[] = 'member_of_repeated_block=' . $group_id;
		}
		if ( '' !== $card_type ) {
			// Recorded whether or not it is a group member, because "this is a product
			// card" and "this is one of four product cards" are different evidence and a
			// reviewer needs to know which was used.
			$out[] = 'card_type=' . $card_type;
		}
		if ( isset( $component['fields'] ) && is_array( $component['fields'] ) && array() !== $component['fields'] ) {
			$out[] = 'card_fields_present=' . implode( ',', array_keys( array_filter( $component['fields'], static function ( $v ) { return null !== $v && '' !== $v; } ) ) );
		}
		$declared = (string) ( $this->structured['page_type']['type'] ?? 'unknown' );
		if ( 'unknown' !== $declared ) {
			$out[] = 'structured_page_type=' . $declared;
		}

		return $out;
	}

	/**
	 * Build one content item.
	 *
	 * @param array<string, mixed> $component   Component.
	 * @param string               $id          Component id.
	 * @param string               $entity_type Entity type.
	 * @param string               $section     Section type.
	 * @param string               $role        Role.
	 * @param array<string, float> $scores      Scores.
	 * @param float                $score       Winning score.
	 * @param array<int, string>  $evidence    Evidence.
	 * @param string               $dyn_class   Dynamism class.
	 * @param float                $dyn_conf    Dynamism confidence.
	 * @param string               $dyn_reason  Dynamism reason.
	 * @param float|null           $confidence  Confidence.
	 * @return array<string, mixed>
	 */
	private function item( array $component, $id, $entity_type, $section, $role, $score, $evidence, $dyn_class, $dyn_conf, $dyn_reason = '', $confidence = null ) {
		$text   = trim( (string) ( $component['text'] ?? '' ) );
		$value  = $this->value_of( $component, $text );

		return array(
			'content_id'         => 'c_' . substr( md5( $id . '|' . $role . '|' . $text ), 0, 16 ),
			'role'               => (string) $role,
			'data_type'          => $this->data_type_for( $role, $value ),
			'value'              => $value,
			'source_type'        => (string) ( $component['type'] ?? '' ),
			'section_id'         => (string) ( $component['section_id'] ?? '' ),
			'section_type'       => (string) $section,
			'component_id'       => (string) $id,
			'element_reference'  => (string) ( $component['source'] ?? $id ),
			'source_url'         => $this->source_url( $component ),
			'entity_type'        => (string) $entity_type,
			'confidence'         => ( null === $confidence ) ? 0.0 : (float) $confidence,
			'evidence'           => $evidence,
			'is_dynamic'         => (string) $dyn_class,
			'dynamic_confidence' => (float) $dyn_conf,
			'dynamic_reason'     => (string) $dyn_reason,
			'fingerprint'        => Content_Fingerprint::of( $role, $value ),
		);
	}

	/**
	 * Return the data type implied by a role.
	 *
	 * §10 requires a data-type check, and the role is the best available signal for it.
	 * An unrecognised role is `text`, which is the most permissive type and therefore
	 * the safest default: a `text` source will be refused against a `currency`
	 * destination, whereas guessing `currency` would let it through.
	 *
	 * @param string $role  Role.
	 * @param string $value Value.
	 * @return string
	 */
	private function data_type_for( $role, $value ) {
		if ( in_array( $role, Content_Limits::PRICE_ROLES, true ) ) {
			return 'currency';
		}
		if ( 'product_rating' === $role ) {
			return 'rating';
		}
		if ( 'product_image' === $role || 'site_logo' === $role ) {
			return 'image';
		}
		if ( 'social_link' === $role || 'button' === $role || 'hero_cta' === $role ) {
			return ( 0 === strpos( $value, 'http' ) ) ? 'url' : 'text';
		}
		if ( 'phone' === $role ) {
			return 'text';
		}
		if ( 'email' === $role ) {
			return 'email';
		}
		if ( 'blog_date' === $role ) {
			return 'date';
		}
		return 'text';
	}

	/**
	 * Expand a Phase 2 card's fields into one content item per populated field.
	 *
	 * @param array<string, mixed> $component   Component.
	 * @param string               $id          Component id.
	 * @param string               $entity_type Entity type.
	 * @param string               $section     Section type.
	 * @param array<string, mixed> $fields      Phase 2 card fields.
	 * @param float                $score       Winning score.
	 * @return array<int, array<string, mixed>>
	 */
	private function card_field_items( array $component, $id, $entity_type, $section, array $fields, $score ) {
		$map = array(
			'title'      => array( 'product_name', 'text' ),
			'price'      => array( 'product_price', 'currency' ),
			'sale_price' => array( 'product_sale_price', 'currency' ),
			'rating'     => array( 'product_rating', 'rating' ),
			'image'      => array( 'product_image', 'image' ),
			'button'     => array( 'button', 'text' ),
			'link'       => array( 'button', 'url' ),
			'badge'      => array( 'product_category', 'text' ),
		);

		$out    = array();
		$weight = max( 0.5, (float) $score );
		$card_type = strtolower( (string) ( $component['type'] ?? '' ) );
		$evidence = $this->evidence( $component, '', $weight, null, $card_type );

		foreach ( $map as $field => $spec ) {
			$value = $fields[ $field ] ?? null;
			// The absence of a field is the finding. Nothing is substituted, and
			// `Content_Limits::NULL_MARKERS` is used for a field that exists but is
			// empty, so "no price on this card" and "an empty price" stay distinct.
			if ( null === $value || '' === $value ) {
				continue;
			}

			list( $role, $data_type ) = $spec;

			// A product-card field on a testimonial or team card means something
			// different, so the card type re-labels the field rather than reusing the
			// product vocabulary blindly.
			$card_type = strtolower( (string) ( $component['type'] ?? '' ) );
			if ( 'testimonial_card' === $card_type && 'title' === $field ) {
				$role      = 'review_author';
				$data_type = 'text';
			} elseif ( 'team_card' === $card_type && 'title' === $field ) {
				$role      = 'blog_author';
				$data_type = 'text';
			} elseif ( in_array( $card_type, array( 'feature_card', 'pricing_card' ), true ) && 'title' === $field ) {
				$role      = 'feature_title';
				$data_type = 'text';
			} elseif ( 'blog_card' === $card_type && 'title' === $field ) {
				$role      = 'blog_title';
				$data_type = 'text';
			}

			$out[] = array(
				'content_id'         => 'c_' . substr( md5( $id . '|' . $field . '|' . $role ), 0, 16 ),
				'role'               => (string) $role,
				'data_type'          => (string) $data_type,
				'value'              => is_scalar( $value ) ? (string) $value : ( 'image' === $data_type ? $this->safe_url( (string) $value ) : 'not_detected' ),
				'source_type'        => (string) ( $component['type'] ?? '' ) . '#' . $field,
				'section_id'         => (string) ( $component['section_id'] ?? '' ),
				'section_type'       => (string) $section,
				'component_id'       => (string) $id,
				'card_field'         => (string) $field,
				'element_reference'  => (string) ( $component['source'] ?? $id ),
				'source_url'         => (string) ( $component['url'] ?? '' ),
				'entity_type'        => (string) $entity_type,
				'confidence'         => (float) min( 0.9, $weight ),
				'evidence'           => array_merge( $evidence, array( 'phase2_card_field=' . $field ) ),
				'is_dynamic'         => $this->is_dynamic_role( $role ) ? 'dynamic' : 'semi_dynamic',
				'dynamic_confidence' => $this->is_dynamic_role( $role ) ? 0.75 : 0.65,
				'dynamic_reason'     => $this->is_dynamic_role( $role ) ? 'a field that changes on its own schedule' : 'a field inside a repeating block',
				'fingerprint'        => Content_Fingerprint::of( $role, is_scalar( $value ) ? (string) $value : '' ),
			);
		}

		return $out;
	}

	/**
	 * Return whether a role is dynamic by nature.
	 *
	 * @param string $role Role.
	 * @return bool
	 */
	private function is_dynamic_role( $role ) {
		return in_array(
			(string) $role,
			array( 'product_price', 'product_sale_price', 'product_rating', 'blog_date', 'blog_excerpt', 'review_text', 'review_author' ),
			true
		);
	}

	/**
	 * Return the class/id vocabulary of a component.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return string
	 */
	private function vocabulary( array $component ) {
		$parts = array(
			(string) ( $component['type'] ?? '' ),
			(string) ( $component['role'] ?? '' ),
			(string) ( $component['card_type'] ?? '' ),
			(string) ( $component['_card_type'] ?? '' ),
		);
		foreach ( (array) ( $component['attributes'] ?? array() ) as $key => $value ) {
			$parts[] = (string) $key;
			if ( is_scalar( $value ) ) {
				$parts[] = (string) $value;
			}
		}
		return strtolower( ' ' . implode( ' ', array_filter( $parts ) ) . ' ' );
	}

	/**
	 * Return a component's value, URL-validated.
	 *
	 * @param array<string, mixed> $component Component.
	 * @param string               $text      Text.
	 * @return string
	 */
	private function value_of( array $component, $text ) {
		$type = strtolower( (string) ( $component['type'] ?? '' ) );
		if ( in_array( $type, array( 'image', 'background_image' ), true ) ) {
			$url = (string) ( $component['url'] ?? '' );
			if ( '' !== $url ) {
				return $this->safe_url( $url );
			}
		}
		return $text;
	}

	/**
	 * Return a component's source URL, validated.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return string
	 */
	private function source_url( array $component ) {
		$url = (string) ( $component['url'] ?? '' );
		return ( '' === $url ) ? '' : $this->safe_url( $url );
	}

	/**
	 * Validate a URL, returning the marker rather than the value on refusal.
	 *
	 * @param string $url Candidate.
	 * @return string
	 */
	private function safe_url( $url ) {
		$verdict = $this->urls->validate( $url );
		// `not_detected` rather than the raw value: storing an address ReplicaForge has
		// just refused is how a blocked target becomes a stored one.
		return empty( $verdict['success'] ) ? 'not_detected' : (string) $verdict['url'];
	}
}
