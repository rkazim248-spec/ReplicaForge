<?php
/**
 * Reusable component and repeated-card detection.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Detects semantic and visual components from the indexed DOM.
 */
final class Component_Detector {

	/**
	 * DOM analyzer.
	 *
	 * @var Dom_Analyzer
	 */
	private $dom;

	/**
	 * Style analyzer.
	 *
	 * @var Style_Analyzer
	 */
	private $style;

	/**
	 * Monotonic component sequence.
	 *
	 * @var int
	 */
	private $component_sequence = 0;

	/**
	 * Constructor.
	 *
	 * @param Dom_Analyzer|null   $dom   DOM analyzer.
	 * @param Style_Analyzer|null $style Style analyzer.
	 */
	public function __construct( $dom = null, $style = null ) {
		$this->dom   = $dom instanceof Dom_Analyzer ? $dom : new Dom_Analyzer();
		$this->style = $style instanceof Style_Analyzer ? $style : new Style_Analyzer();
	}

	/**
	 * Detect components and repeated card groups.
	 *
	 * @param array<string, mixed>             $context  DOM context.
	 * @param array<int, array<string, mixed>> $sections Sections.
	 * @return array{components: array<int, array<string, mixed>>, groups: array<int, array<string, mixed>>}
	 */
	public function detect( $context, $sections ) {
		$components = array();
		$groups     = array();
		$this->component_sequence = 0;
		$section_map = array();
		foreach ( $sections as $section ) {
			if ( ! empty( $section['node_id'] ) ) {
				$section_map[ $section['node_id'] ] = $section;
			}
		}
		$seen = array();

		foreach ( $this->dom->get_node_ids( $context ) as $node_id ) {
			if ( count( $components ) >= Analysis_Limits::MAX_COMPONENTS ) {
				break;
			}
			$node = $this->dom->get_node( $context, $node_id );
			if ( empty( $node['visible'] ) || in_array( $node['tag'], array( 'html', 'head', 'body', 'style', 'script', 'meta', 'link' ), true ) ) {
				continue;
			}
			$classification = $this->classify_node( $context, $node_id, $section_map );
			if ( empty( $classification['type'] ) || isset( $seen[ $node_id ] ) ) {
				continue;
			}
			$seen[ $node_id ] = true;
			$section = $this->find_section( $context, $node_id, $section_map );
			$component = $this->build_component( $context, $node_id, $classification, $section );
			$components[] = $component;
		}

		$groups = $this->detect_card_groups( $context, $components );
		foreach ( $groups as $group ) {
			if ( count( $components ) < Analysis_Limits::MAX_COMPONENTS ) {
				$components[] = $group;
			}
		}
		foreach ( $components as $index => $component ) {
			unset( $components[ $index ]['_parent_node_id'], $components[ $index ]['_card_type'] );
		}
		return array( 'components' => array_values( $components ), 'groups' => $groups );
	}

	/**
	 * Classify a node into a component type.
	 *
	 * @param array<string, mixed> $context     DOM context.
	 * @param string               $node_id     Node ID.
	 * @param array<string, array<string, mixed>> $section_map Section map.
	 * @return array<string, mixed>
	 */
	private function classify_node( $context, $node_id, $section_map ) {
		$node = $this->dom->get_node( $context, $node_id );
		$tag = isset( $node['tag'] ) ? $node['tag'] : '';
		$hint = $this->dom->get_hint_text( $context, $node_id );
		$section = $this->find_section( $context, $node_id, $section_map );
		$section_type = $section && isset( $section['type'] ) ? $section['type'] : '';

		if ( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			return array( 'type' => 'heading', 'role' => $this->heading_role( $tag ), 'confidence' => 0.98 );
		}
		if ( 'p' === $tag ) {
			return array( 'type' => 'paragraph', 'role' => 'supporting_text', 'confidence' => 0.96 );
		}
		if ( 'img' === $tag ) {
			return array( 'type' => 'image', 'role' => $this->image_role( $context, $node_id, $section_type ), 'confidence' => 0.9 );
		}
		if ( in_array( $tag, array( 'div', 'section', 'header', 'main', 'article', 'aside', 'footer' ), true ) && isset( $node['style'] ) && ( false !== stripos( $node['style'], 'background-image' ) || preg_match( '/(?:^|;)\s*background\s*:[^;]*url\s*\(/i', $node['style'] ) ) ) {
			return array( 'type' => 'background_image', 'role' => 'background_image', 'confidence' => 0.72 );
		}
		if ( 'button' === $tag ) {
			return array( 'type' => 'button', 'role' => $this->button_role( $context, $node_id, $hint ), 'confidence' => 0.97 );
		}
		if ( 'form' === $tag ) {
			return array( 'type' => 'form', 'role' => 'form', 'confidence' => 0.98 );
		}
		if ( in_array( $tag, array( 'input', 'textarea', 'select' ), true ) ) {
			return array( 'type' => 'form_field', 'role' => 'form_field', 'confidence' => 0.96 );
		}
		if ( 'details' === $tag ) {
			return array( 'type' => 'accordion', 'role' => 'expandable_content', 'confidence' => 0.88 );
		}
		if ( in_array( $tag, array( 'div', 'nav', 'ul' ), true ) && preg_match( '/(?:^|[\s_-])tabs?(?:$|[\s_-])/i', $hint ) ) {
			return array( 'type' => 'tabs', 'role' => 'tabs', 'confidence' => 0.76 );
		}
		if ( in_array( $tag, array( 'a', 'button' ), true ) && ( false !== strpos( $hint, 'tab' ) || ( isset( $node['attributes']['role'] ) && 'tab' === strtolower( $node['attributes']['role'] ) ) ) ) {
			return array( 'type' => 'tab', 'role' => 'tab', 'confidence' => 0.82 );
		}
		if ( in_array( $tag, array( 'ul', 'ol' ), true ) ) {
			return array( 'type' => 'list', 'role' => 'list', 'confidence' => 0.94 );
		}
		if ( 'a' === $tag ) {
			if ( preg_match( '/(?:^|[\s_-])tabs?(?:$|[\s_-])/i', $hint ) || ( isset( $node['attributes']['role'] ) && 'tab' === strtolower( $node['attributes']['role'] ) ) ) {
				return array( 'type' => 'tab', 'role' => 'tab', 'confidence' => 0.82 );
			}
			if ( false !== strpos( $hint, 'social' ) ) {
				return array( 'type' => 'social_link', 'role' => 'social_link', 'confidence' => 0.9 );
			}
			if ( $this->is_button_hint( $hint ) || $this->button_role( $context, $node_id, $hint ) === 'call_to_action' ) {
				return array( 'type' => 'button', 'role' => 'call_to_action', 'confidence' => 0.88 );
			}
			if ( in_array( $section_type, array( 'navigation', 'header' ), true ) ) {
				return array( 'type' => 'navigation_item', 'role' => 'navigation', 'confidence' => 0.86 );
			}
			return array( 'type' => 'link', 'role' => 'action_or_navigation', 'confidence' => 0.78 );
		}
		if ( in_array( $tag, array( 'div', 'article', 'section', 'li', 'span', 'i' ), true ) && $this->is_card_hint( $hint ) ) {
			$card_type = $this->card_type( $context, $node_id, $hint, $section_type );
			return array( 'type' => $card_type, 'role' => 'repeated_or_content_card', 'confidence' => $this->card_confidence( $hint, $card_type ), 'card_type' => $card_type );
		}
		if ( false !== strpos( $hint, 'icon' ) && in_array( $tag, array( 'span', 'i', 'div' ), true ) ) {
			return array( 'type' => 'icon', 'role' => 'icon', 'confidence' => 0.78 );
		}
		if ( false !== strpos( $hint, 'badge' ) || false !== strpos( $hint, 'label' ) || false !== strpos( $hint, 'tag' ) ) {
			return array( 'type' => 'badge', 'role' => 'label', 'confidence' => 0.74 );
		}
		return array();
	}

	/**
	 * Build a component record.
	 *
	 * @param array<string, mixed>             $context        DOM context.
	 * @param string                           $node_id        Node ID.
	 * @param array<string, mixed>             $classification Classification.
	 * @param array<string, mixed>|null        $section        Parent section.
	 * @return array<string, mixed>
	 */
	private function build_component( $context, $node_id, $classification, $section ) {
		$node = $this->dom->get_node( $context, $node_id );
		$type = $classification['type'];
		$this->component_sequence++;
		$component = array(
			'id'         => 'component_' . str_pad( (string) $this->component_sequence, 3, '0', STR_PAD_LEFT ),
			'type'       => $type,
			'role'       => isset( $classification['role'] ) ? $classification['role'] : $type,
			'text'       => $this->dom->get_text( $context, $node_id, 500 ),
			'source'     => $this->dom->get_source( $context, $node_id ),
			'section_id' => $section && isset( $section['id'] ) ? $section['id'] : null,
			'confidence' => Analysis_Normalizer::confidence( isset( $classification['confidence'] ) ? $classification['confidence'] : 0.5 ),
			'source_type' => 'detected',
			'attributes' => $this->component_attributes( $context, $node_id, $type ),
			'styles'     => $this->component_styles( $context, $node_id ),
			'layout'     => array( 'child_count' => isset( $node['child_count'] ) ? absint( $node['child_count'] ) : 0 ),
			'children'   => array(),
			'_parent_node_id' => isset( $node['parent'] ) ? $node['parent'] : null,
			'_card_type' => isset( $classification['card_type'] ) ? $classification['card_type'] : '',
		);
		if ( in_array( $type, array( 'card', 'product_card', 'pricing_card', 'testimonial_card', 'blog_card', 'feature_card', 'team_card', 'portfolio_card' ), true ) ) {
			$component['fields'] = $this->card_fields( $context, $node_id, $type );
		}
		if ( in_array( $type, array( 'image', 'background_image' ), true ) ) {
			$component['image'] = 'background_image' === $type ? $this->background_image_fields( $context, $node_id ) : $this->image_fields( $context, $node_id );
		}
		if ( 'link' === $type || 'navigation_item' === $type || 'button' === $type || 'social_link' === $type ) {
			$component['url'] = $this->safe_url( $context, $node_id, 'href' );
		}
		return $component;
	}

	/**
	 * Detect repeated card groups.
	 *
	 * @param array<string, mixed> $context    DOM context.
	 * @param array<int, array<string, mixed>> $components Components.
	 * @return array<int, array<string, mixed>>
	 */
	private function detect_card_groups( $context, $components ) {
		$grouped = array();
		foreach ( $components as $component ) {
			$type = $component['type'];
			if ( ! in_array( $type, array( 'card', 'product_card', 'pricing_card', 'testimonial_card', 'blog_card', 'feature_card', 'team_card', 'portfolio_card' ), true ) ) {
				continue;
			}
			$key = ( $component['section_id'] ? $component['section_id'] : 'none' ) . '|' . $component['_parent_node_id'] . '|' . $type;
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array();
			}
			$grouped[ $key ][] = $component['id'];
		}
		$groups = array();
		$sequence = $this->component_sequence;
		foreach ( $grouped as $key => $ids ) {
			if ( count( $ids ) < 2 || count( $groups ) >= Analysis_Limits::MAX_CARD_GROUPS ) {
				continue;
			}
			$key_parts = array_pad( explode( '|', $key, 3 ), 3, 'card' );
			$type = $key_parts[2];
			$source_node = '' !== $key_parts[1] ? $this->dom->get_source( $context, $key_parts[1] ) : $this->find_parent_for_group( $components, $ids );
			$groups[] = array(
				'id'         => 'component_' . str_pad( (string) ++$sequence, 3, '0', STR_PAD_LEFT ),
				'type'       => 'card_group',
				'role'       => 'repeated_structure',
				'text'       => '',
				'source'     => $source_node,
				'section_id' => $this->section_for_component( $components, $ids ),
				'confidence' => 0.86,
				'source_type' => 'inferred',
				'attributes' => array(),
				'styles'     => array(),
				'layout'     => array( 'child_count' => count( $ids ) ),
				'children'   => $ids,
				'count'      => count( $ids ),
				'card_type'  => $type,
				'repeated_structure' => true,
			);
		}
		return $groups;
	}

	/**
	 * Find a source node for a repeated group.
	 *
	 * @param array<int, array<string, mixed>> $components Components.
	 * @param array<int, string>                $ids        Component IDs.
	 * @return array<string, string>
	 */
	private function find_parent_for_group( $components, $ids ) {
		foreach ( $components as $component ) {
			if ( in_array( $component['id'], $ids, true ) ) {
				return $component['source'];
			}
		}
		return array( 'node_id' => '', 'tag' => 'unknown', 'selector' => '' );
	}

	/**
	 * Find the section ID shared by a group.
	 *
	 * @param array<int, array<string, mixed>> $components Components.
	 * @param array<int, string>                $ids        Component IDs.
	 * @return string|null
	 */
	private function section_for_component( $components, $ids ) {
		foreach ( $components as $component ) {
			if ( in_array( $component['id'], $ids, true ) ) {
				return $component['section_id'];
			}
		}
		return null;
	}

	/**
	 * Find the nearest section for a node.
	 *
	 * @param array<string, mixed> $context     DOM context.
	 * @param string               $node_id     Node ID.
	 * @param array<string, array<string, mixed>> $section_map Section map.
	 * @return array<string, mixed>|null
	 */
	private function find_section( $context, $node_id, $section_map ) {
		$current = $node_id;
		$steps = 0;
		while ( $current && $steps < Analysis_Limits::MAX_DOM_DEPTH ) {
			if ( isset( $section_map[ $current ] ) ) {
				return $section_map[ $current ];
			}
			$node = $this->dom->get_node( $context, $current );
			$current = isset( $node['parent'] ) ? $node['parent'] : '';
			$steps++;
		}
		return null;
	}

	/**
	 * Determine whether a hint indicates a card.
	 *
	 * @param string $hint Hint text.
	 * @return bool
	 */
	private function is_card_hint( $hint ) {
		$hint = strtolower( (string) $hint );
		foreach ( array( 'card', 'testimonial', 'pricing', 'team-member', 'portfolio-item' ) as $needle ) {
			if ( false !== strpos( $hint, $needle ) ) {
				return true;
			}
		}
		if ( preg_match( '/(?:^|[\s_-])(?:post|article)(?:$|[\s_-])/', $hint ) && false === strpos( $hint, 'grid' ) && false === strpos( $hint, 'list' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Determine whether a hint indicates a button.
	 *
	 * @param string $hint Hint text.
	 * @return bool
	 */
	private function is_button_hint( $hint ) {
		foreach ( array( 'btn', 'button', 'cta' ) as $needle ) {
			if ( false !== strpos( $hint, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Infer a heading role.
	 *
	 * @param string $tag Heading tag.
	 * @return string
	 */
	private function heading_role( $tag ) {
		if ( 'h1' === $tag ) {
			return 'primary_heading';
		}
		if ( 'h2' === $tag || 'h3' === $tag ) {
			return 'section_heading';
		}
		return 'subheading';
	}

	/**
	 * Determine button role from static text and classes.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @param string               $hint    Hint text.
	 * @return string
	 */
	private function button_role( $context, $node_id, $hint ) {
		$text = strtolower( $this->dom->get_text( $context, $node_id, 160 ) );
		if ( false !== strpos( $hint, 'cta' ) ) {
			return 'call_to_action';
		}
		foreach ( array( 'get started', 'buy now', 'add to cart', 'sign up', 'subscribe', 'contact', 'download', 'shop now' ) as $needle ) {
			if ( false !== strpos( $text, $needle ) ) {
				return 'call_to_action';
			}
		}
		return 'button';
	}

	/**
	 * Infer an image role from context.
	 *
	 * @param array<string, mixed> $context      DOM context.
	 * @param string               $node_id      Node ID.
	 * @param string               $section_type Section type.
	 * @return string
	 */
	private function image_role( $context, $node_id, $section_type ) {
		$hint = $this->dom->get_hint_text( $context, $node_id );
		foreach ( array( 'logo' => 'logo', 'brand' => 'logo', 'avatar' => 'avatar', 'profile' => 'avatar', 'icon' => 'icon', 'thumbnail' => 'thumbnail', 'thumb' => 'thumbnail', 'decorative' => 'decorative_image', 'spacer' => 'decorative_image' ) as $needle => $role ) {
			if ( false !== strpos( $hint, $needle ) ) {
				return $role;
			}
		}
		if ( 'hero' === $section_type ) {
			return 'hero_image';
		}
		if ( in_array( $section_type, array( 'products', 'product_grid', 'categories' ), true ) ) {
			return 'product_image';
		}
		return 'content_image';
	}

	/**
	 * Extract safe image fields.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return array<string, mixed>
	 */
	private function image_fields( $context, $node_id ) {
		$node = $this->dom->get_node( $context, $node_id );
		$src = $this->safe_url( $context, $node_id, 'src' );
		if ( null === $src && ! empty( $node['attributes']['data-src'] ) ) {
			$src = $this->safe_url( $context, $node_id, 'data-src' );
		}
		if ( null === $src && ! empty( $node['attributes']['data-lazy-src'] ) ) {
			$src = $this->safe_url( $context, $node_id, 'data-lazy-src' );
		}
		return array( 'src' => $src, 'alt' => isset( $node['attributes']['alt'] ) ? $node['attributes']['alt'] : '', 'width' => $node['attributes']['width'] ?? null, 'height' => $node['attributes']['height'] ?? null );
	}

	/**
	 * Extract a background image reference from an inline style only.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return array<string, mixed>
	 */
	private function background_image_fields( $context, $node_id ) {
		$node = $this->dom->get_node( $context, $node_id );
		$style = isset( $node['style'] ) ? $node['style'] : '';
		$src = null;
		if ( preg_match( '/background-image\s*:[^;]*url\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i', $style, $match ) ) {
			$url = Security::resolve_url( isset( $context['base_url'] ) ? $context['base_url'] : '', trim( $match[1] ) );
			$url = Security::normalize_http_url( (string) $url );
			$src = is_string( $url ) && Security::is_safe_public_reference( $url ) ? $url : null;
		}
		return array( 'src' => $src, 'alt' => '', 'width' => null, 'height' => null );
	}

	/**
	 * Infer a card type.
	 *
	 * @param array<string, mixed> $context      DOM context.
	 * @param string               $node_id      Node ID.
	 * @param string               $hint         Hint text.
	 * @param string               $section_type Section type.
	 * @return string
	 */
	private function card_type( $context, $node_id, $hint, $section_type ) {
		$text = strtolower( $this->dom->get_text( $context, $node_id, 700 ) );
		if ( false !== strpos( $hint, 'product' ) || false !== strpos( $text, 'add to cart' ) || false !== strpos( $text, 'price' ) || in_array( $section_type, array( 'products', 'product_grid' ), true ) ) {
			return 'product_card';
		}
		if ( false !== strpos( $hint, 'pricing' ) || false !== strpos( $text, 'pricing' ) || false !== strpos( $text, 'plan' ) ) {
			return 'pricing_card';
		}
		if ( false !== strpos( $hint, 'testimonial' ) || false !== strpos( $hint, 'review' ) || false !== strpos( $text, 'testimonial' ) ) {
			return 'testimonial_card';
		}
		if ( false !== strpos( $hint, 'post' ) || false !== strpos( $hint, 'article' ) || false !== strpos( $text, 'read more' ) ) {
			return 'blog_card';
		}
		if ( false !== strpos( $hint, 'team' ) ) {
			return 'team_card';
		}
		if ( false !== strpos( $hint, 'portfolio' ) || false !== strpos( $hint, 'project' ) ) {
			return 'portfolio_card';
		}
		return 'feature_card';
	}

	/**
	 * Determine card confidence.
	 *
	 * @param string $hint     Hint text.
	 * @param string $card_type Card type.
	 * @return float
	 */
	private function card_confidence( $hint, $card_type ) {
		$base = 0.68;
		if ( false !== strpos( $hint, 'card' ) ) {
			$base += 0.1;
		}
		if ( 'product_card' === $card_type ) {
			$base += 0.06;
		}
		return min( 0.92, $base );
	}

	/**
	 * Extract card fields without inventing missing values.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @param string               $type    Card type.
	 * @return array<string, mixed>
	 */
	private function card_fields( $context, $node_id, $type ) {
		$image = null;
		$title = '';
		$button = null;
		$link = null;
		foreach ( $this->dom->get_descendant_ids( $context, $node_id, 1000 ) as $descendant ) {
			$node = $this->dom->get_node( $context, $descendant );
			$tag = isset( $node['tag'] ) ? $node['tag'] : '';
			if ( 'img' === $tag && null === $image ) {
				$image = $this->safe_url( $context, $descendant, 'src' );
			}
			if ( '' === $title && in_array( $tag, array( 'h2', 'h3', 'h4', 'a', 'strong' ), true ) ) {
				$title = $this->dom->get_text( $context, $descendant, 180 );
			}
			if ( 'a' === $tag && null === $link ) {
				$link = $this->safe_url( $context, $descendant, 'href' );
			}
			if ( in_array( $tag, array( 'button', 'a' ), true ) && null === $button ) {
				$hint = $this->dom->get_hint_text( $context, $descendant );
				$text = $this->dom->get_text( $context, $descendant, 120 );
				if ( false !== strpos( $hint, 'btn' ) || false !== strpos( $hint, 'button' ) || false !== strpos( $hint, 'cta' ) || in_array( strtolower( $text ), array( 'add to cart', 'buy now', 'view details' ), true ) ) {
					$button = $text;
				}
			}
		}
		$text = $this->dom->get_text( $context, $node_id, 1000 );
		$price = $this->extract_price( $text, false );
		$sale_price = $this->extract_price( $text, true );
		$rating = null;
		if ( preg_match( '/\b([0-5](?:\.\d)?)\s*(?:\/|out of)\s*5\b/i', $text, $match ) ) {
			$rating = (float) $match[1];
		}
		$badge = null;
		foreach ( $this->dom->get_descendant_ids( $context, $node_id, 1000 ) as $descendant ) {
			$hint = $this->dom->get_hint_text( $context, $descendant );
			if ( false !== strpos( $hint, 'badge' ) || false !== strpos( $hint, 'label' ) ) {
				$badge = $this->dom->get_text( $context, $descendant, 100 );
				break;
			}
		}
		return array( 'image' => $image, 'title' => '' !== $title ? $title : null, 'price' => $price, 'sale_price' => $sale_price, 'rating' => $rating, 'badge' => $badge, 'button' => $button, 'link' => $link );
	}

	/**
	 * Extract a numeric price only when present.
	 *
	 * @param string $text Card text.
	 * @param bool   $sale Prefer a discounted/sale marker.
	 * @return string|null
	 */
	private function extract_price( $text, $sale ) {
		if ( $sale ) {
			if ( preg_match( '/(?:sale|was|now)\s*[:\-]?\s*[$€£¥]?\s*[0-9]+(?:[.,][0-9]{1,2})?/i', $text, $match ) ) {
				return trim( $match[0] );
			}
			return null;
		}
		if ( preg_match( '/[$€£¥]\s*[0-9]+(?:[.,][0-9]{1,2})?|\b[0-9]+(?:[.,][0-9]{1,2})?\s*(?:USD|EUR|GBP)\b/i', $text, $match ) ) {
			return trim( $match[0] );
		}
		return null;
	}

	/**
	 * Return safe component attributes.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @param string               $type    Component type.
	 * @return array<string, string>
	 */
	private function component_attributes( $context, $node_id, $type ) {
		$node = $this->dom->get_node( $context, $node_id );
		$attributes = isset( $node['attributes'] ) ? $node['attributes'] : array();
		$allowed = array( 'alt', 'role', 'type', 'name', 'placeholder', 'rel', 'target', 'loading', 'open', 'aria-expanded', 'aria-controls', 'aria-label' );
		$result = array();
		foreach ( $allowed as $attribute ) {
			if ( isset( $attributes[ $attribute ] ) ) {
				$result[ $attribute ] = $attributes[ $attribute ];
			}
		}
		return $result;
	}

	/**
	 * Return selected static styles for a component.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return array<string, string>
	 */
	private function component_styles( $context, $node_id ) {
		$declarations = $this->style->get_declarations( $this->dom, $context, $node_id );
		$allowed = array( 'display', 'flex-direction', 'justify-content', 'align-items', 'gap', 'grid-template-columns', 'width', 'max-width', 'height', 'min-height', 'background', 'background-color', 'color', 'border', 'border-radius', 'padding', 'margin', 'font-family', 'font-size', 'font-weight', 'line-height', 'box-shadow' );
		$result = array();
		foreach ( $allowed as $property ) {
			if ( isset( $declarations[ $property ] ) ) {
				$result[ $property ] = $declarations[ $property ];
			}
		}
		return $result;
	}

	/**
	 * Resolve a safe URL attribute.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @param string               $attribute Attribute.
	 * @return string|null
	 */
	private function safe_url( $context, $node_id, $attribute ) {
		$node = $this->dom->get_node( $context, $node_id );
		$raw = isset( $node['attributes'][ $attribute ] ) ? $node['attributes'][ $attribute ] : '';
		if ( '' === $raw ) {
			return null;
		}
		$url = Security::resolve_url( isset( $context['base_url'] ) ? $context['base_url'] : '', $raw );
		$url = Security::normalize_http_url( (string) $url );
		return is_string( $url ) && Security::is_safe_public_reference( $url ) ? $url : null;
	}
}
