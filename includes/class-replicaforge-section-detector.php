<?php
/**
 * Deterministic page-region and section detection.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies semantic and visually meaningful page sections.
 */
final class Section_Detector {

	/**
	 * DOM analyzer.
	 *
	 * @var Dom_Analyzer
	 */
	private $dom;

	/**
	 * Constructor.
	 *
	 * @param Dom_Analyzer|null $dom DOM analyzer.
	 */
	public function __construct( $dom = null ) {
		$this->dom = $dom instanceof Dom_Analyzer ? $dom : new Dom_Analyzer();
	}

	/**
	 * Detect sections in document order.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @return array<int, array<string, mixed>>
	 */
	public function detect( $context ) {
		$sections = array();
		$selected = array();
		$hints = array(
			'hero' => 'hero',
			'feature' => 'features',
			'service' => 'services',
			'about' => 'about',
			'product' => 'products',
			'catalog' => 'product_grid',
			'shop' => 'product_grid',
			'collection' => 'product_grid',
			'category' => 'categories',
			'testimonial' => 'testimonials',
			'review' => 'reviews',
			'pricing' => 'pricing',
			'faq' => 'faq',
			'stat' => 'statistics',
			'team' => 'team',
			'portfolio' => 'portfolio',
			'gallery' => 'gallery',
			'blog' => 'blog',
			'news' => 'news',
			'contact' => 'contact',
			'newsletter' => 'newsletter',
			'subscribe' => 'newsletter',
			'cta' => 'cta',
			'call' => 'cta',
			'footer' => 'footer',
			'header' => 'header',
			'nav' => 'navigation',
			'sidebar' => 'sidebar',
		);
		$semantic = array(
			'header' => 'header',
			'nav' => 'navigation',
			'main' => 'main',
			'section' => 'section',
			'article' => 'article',
			'footer' => 'footer',
			'aside' => 'sidebar',
		);
		$body_id = $this->find_body( $context );
		$total_nodes = max( 1, count( $this->dom->get_node_ids( $context ) ) );

		foreach ( $this->dom->get_node_ids( $context ) as $node_id ) {
			$node = $this->dom->get_node( $context, $node_id );
			if ( empty( $node['visible'] ) ) {
				continue;
			}
			$tag = isset( $node['tag'] ) ? $node['tag'] : '';
			if ( in_array( $tag, array( 'html', 'head', 'body', 'style', 'script' ), true ) ) {
				continue;
			}
			$hint_text = $this->dom->get_hint_text( $context, $node_id );
			$hinted_type = $this->match_hint( $hint_text, $hints );
			$is_semantic = isset( $semantic[ $tag ] );
			if ( ! $is_semantic && $this->is_card_container_hint( $hint_text ) ) {
				continue;
			}
			$is_structural_hint = in_array( $tag, array( 'div', 'section', 'article', 'main', 'aside', 'header', 'footer', 'nav' ), true ) && '' !== $hinted_type;
			$is_body_child = $body_id && in_array( $node_id, $this->dom->get_child_ids( $context, $body_id ), true );
			$is_meaningful_body_child = $is_body_child && ( $this->has_heading( $context, $node_id ) || count( $this->dom->get_child_ids( $context, $node_id ) ) >= 2 );
			if ( ! $is_semantic && ! $is_structural_hint && ! $is_meaningful_body_child ) {
				continue;
			}
			if ( isset( $selected[ $node_id ] ) || count( $sections ) >= Analysis_Limits::MAX_SECTIONS ) {
				continue;
			}
			$selected[ $node_id ] = true;

			$classification = $this->classify( $context, $node_id, $tag, $hinted_type, $semantic, $total_nodes );
			if ( $is_meaningful_body_child && ! $is_semantic && '' === $hinted_type && 'section' === $classification['type'] ) {
				$classification['type'] = 'content';
				$classification['confidence'] = 0.58;
				$classification['signals'][] = 'body_content_container';
			}
			$section = array(
				'id'         => 'section_' . str_pad( (string) ( count( $sections ) + 1 ), 3, '0', STR_PAD_LEFT ),
				'type'       => $classification['type'],
				'order'      => count( $sections ) + 1,
				'confidence' => $classification['confidence'],
				'source_type' => 'inferred',
				'source'     => $this->dom->get_source( $context, $node_id ),
				'node_id'    => $node_id,
				'signals'    => $classification['signals'],
				'content'    => $this->content_summary( $context, $node_id ),
				'children'   => array(),
				'components' => array(),
				'layout'     => array(),
				'styles'     => array(),
			);
			if ( in_array( $section['type'], array( 'navigation', 'header' ), true ) ) {
				$section['navigation'] = $this->navigation_summary( $context, $node_id );
			}
			$sections[] = $section;
		}

		$this->add_nested_children( $context, $sections );
		return $sections;
	}

	/**
	 * Classify one candidate.
	 *
	 * @param array<string, mixed> $context      DOM context.
	 * @param string               $node_id      Node ID.
	 * @param string               $tag          Tag.
	 * @param string               $hinted_type  Hint type.
	 * @param array<string, string> $semantic    Semantic map.
	 * @param int                  $total_nodes  Total node count.
	 * @return array<string, mixed>
	 */
	private function classify( $context, $node_id, $tag, $hinted_type, $semantic, $total_nodes ) {
		$signals = array();
		$confidence = 0.0;
		$type = $hinted_type;
		if ( isset( $semantic[ $tag ] ) ) {
			$type = $semantic[ $tag ];
			$confidence = 0.82;
			$signals[] = 'semantic_' . $tag;
		}
		if ( '' !== $hinted_type && ( ! isset( $semantic[ $tag ] ) || in_array( $tag, array( 'section', 'div', 'article' ), true ) ) ) {
			$type = $hinted_type;
			$confidence = max( $confidence, 0.76 );
			$signals[] = 'class_or_id_hint';
		}

		$heading = $this->first_heading( $context, $node_id );
		$has_paragraph = $this->has_descendant_tag( $context, $node_id, 'p' );
		$has_image = $this->has_descendant_tag( $context, $node_id, 'img' );
		$has_button = $this->has_button( $context, $node_id );
		$link_count = $this->count_descendant_tag( $context, $node_id, 'a' );
		$repeated = $this->count_repeated_children( $context, $node_id );
		$position = $this->relative_position( $context, $node_id, $total_nodes );
		$background = $this->has_background_signal( $context, $node_id );

		if ( 'hero' === $type || ( $position < 0.3 && '' !== $heading && $has_paragraph && ( $has_image || $has_button ) && ! isset( $semantic[ $tag ] ) ) ) {
			$type = 'hero';
			$confidence = 0.58;
			if ( '' !== $heading ) {
				$confidence += 0.14;
				$signals[] = 'heading';
			}
			if ( $has_paragraph ) {
				$confidence += 0.08;
				$signals[] = 'supporting_paragraph';
			}
			if ( $has_image ) {
				$confidence += 0.08;
				$signals[] = 'image';
			}
			if ( $has_button ) {
				$confidence += 0.08;
				$signals[] = 'call_to_action';
			}
			if ( $background ) {
				$confidence += 0.06;
				$signals[] = 'background_signal';
			}
			if ( $position < 0.3 ) {
				$confidence += 0.05;
				$signals[] = 'near_top';
			}
			$confidence = min( 0.97, $confidence );
		}
		$own_product_hint = false;
		foreach ( array( 'product', 'shop', 'catalog', 'collection', 'category' ) as $needle ) {
			if ( false !== strpos( $this->dom->get_hint_text( $context, $node_id ), $needle ) ) {
				$own_product_hint = true;
				break;
			}
		}
		if ( in_array( $type, array( 'products', 'product_grid' ), true ) || ( ! in_array( $tag, array( 'main', 'body' ), true ) && $repeated >= 2 && $own_product_hint ) ) {
			$type = 'product_grid';
			$confidence = max( $confidence, $repeated >= 2 ? 0.84 : 0.7 );
			$signals[] = $repeated >= 2 ? 'repeated_product_structures' : 'product_signal';
		}
		if ( 'navigation' === $type || ( 'header' === $type && $link_count >= 3 ) ) {
			$type = 'header' === $type && 'navigation' !== $hinted_type ? 'header' : 'navigation';
			$confidence = max( $confidence, $link_count >= 3 ? 0.86 : 0.68 );
			$signals[] = 'navigation_links';
		}
		if ( '' === $type ) {
			$type = 'section';
			$confidence = 0.55;
			$signals[] = 'structural_container';
		}
		return array( 'type' => $type, 'confidence' => Analysis_Normalizer::confidence( $confidence ), 'signals' => array_values( array_unique( $signals ) ) );
	}

	/**
	 * Match class/id hints.
	 *
	 * @param string               $hint_text Hint text.
	 * @param array<string, string> $hints   Hint map.
	 * @return string
	 */
	private function match_hint( $hint_text, $hints ) {
		$tokens = preg_split( '/[\s_#.:>-]+/', strtolower( (string) $hint_text ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) ) {
			return '';
		}
		foreach ( $hints as $needle => $type ) {
			foreach ( $tokens as $token ) {
				if ( $token === $needle || ( strlen( $needle ) >= 4 && ( 0 === strpos( $token, $needle ) || strlen( $token ) >= strlen( $needle ) && substr( $token, -strlen( $needle ) ) === $needle ) ) ) {
					return $type;
				}
			}
		}
		return '';
	}

	/**
	 * Determine whether a class/id describes an individual card rather than a
	 * page-level section.
	 *
	 * @param string $hint_text Hint text.
	 * @return bool
	 */
	private function is_card_container_hint( $hint_text ) {
		foreach ( array( 'card', 'product-card', 'feature-card', 'testimonial-card', 'pricing-card', 'post-card', 'team-card', 'portfolio-card' ) as $needle ) {
			if ( false !== strpos( $hint_text, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Add basic content summary.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return array<string, mixed>
	 */
	private function content_summary( $context, $node_id ) {
		$paragraphs = array();
		foreach ( $this->dom->get_descendant_ids( $context, $node_id, 1000 ) as $descendant ) {
			$node = $this->dom->get_node( $context, $descendant );
			if ( empty( $node['visible'] ) ) {
				continue;
			}
			if ( 'p' === ( isset( $node['tag'] ) ? $node['tag'] : '' ) ) {
				$text = $this->dom->get_text( $context, $descendant, 240 );
				if ( '' !== $text ) {
					$paragraphs[] = $text;
				}
			}
			if ( count( $paragraphs ) >= 4 ) {
				break;
			}
		}
		return array(
			'heading'        => $this->first_heading( $context, $node_id ),
			'text'           => Analysis_Normalizer::text( implode( ' ', $paragraphs ), 700 ),
			'paragraph_count' => count( $paragraphs ),
			'link_count'     => $this->count_descendant_tag( $context, $node_id, 'a' ),
			'image_count'    => $this->count_descendant_tag( $context, $node_id, 'img' ),
			'button_count'   => $this->count_descendant_tag( $context, $node_id, 'button' ),
		);
	}

	/**
	 * Summarize static navigation evidence.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return array<string, mixed>
	 */
	private function navigation_summary( $context, $node_id ) {
		$items = array();
		$dropdowns = array();
		$logo = null;
		foreach ( $this->dom->get_descendant_ids( $context, $node_id, 1500 ) as $descendant ) {
			$node = $this->dom->get_node( $context, $descendant );
			$tag = isset( $node['tag'] ) ? $node['tag'] : '';
			$hint = $this->dom->get_hint_text( $context, $descendant );
			if ( ! empty( $node['visible'] ) && ( false !== strpos( $hint, 'dropdown' ) || false !== strpos( $hint, 'submenu' ) || false !== strpos( $hint, 'menu-item-has-children' ) ) ) {
				$dropdowns[] = array( 'text' => $this->dom->get_text( $context, $descendant, 160 ), 'source' => $this->dom->get_source( $context, $descendant ) );
			}
			if ( 'img' === $tag && ( false !== strpos( $hint, 'logo' ) || false !== strpos( $hint, 'brand' ) ) ) {
				$logo = array( 'src' => $this->safe_reference( $context, $descendant, 'src' ), 'alt' => isset( $node['attributes']['alt'] ) ? $node['attributes']['alt'] : '', 'source' => $this->dom->get_source( $context, $descendant ) );
			}
			if ( 'a' === $tag ) {
				$url = $this->safe_reference( $context, $descendant, 'href' );
				if ( null === $url ) {
					continue;
				}
				$items[] = array( 'text' => $this->dom->get_text( $context, $descendant, 120 ), 'url' => $url, 'source' => $this->dom->get_source( $context, $descendant ) );
				if ( count( $items ) >= 30 ) {
					break;
				}
			}
		}
		$mobile = false;
		$cta = null;
		foreach ( $this->dom->get_descendant_ids( $context, $node_id, 1500 ) as $descendant ) {
			$node = $this->dom->get_node( $context, $descendant );
			if ( empty( $node['visible'] ) ) {
				continue;
			}
			$hint = $this->dom->get_hint_text( $context, $descendant );
			if ( false !== strpos( $hint, 'hamburger' ) || false !== strpos( $hint, 'menu-toggle' ) || isset( $node['attributes']['aria-expanded'] ) ) {
				$mobile = true;
			}
			if ( null === $cta && in_array( isset( $node['tag'] ) ? $node['tag'] : '', array( 'a', 'button' ), true ) ) {
				$text = strtolower( $this->dom->get_text( $context, $descendant, 120 ) );
				if ( false !== strpos( $hint, 'cta' ) || false !== strpos( $hint, 'btn' ) || false !== strpos( $text, 'get started' ) || false !== strpos( $text, 'contact' ) ) {
					$cta = array( 'text' => $this->dom->get_text( $context, $descendant, 120 ), 'url' => $this->safe_reference( $context, $descendant, 'href' ), 'source' => $this->dom->get_source( $context, $descendant ) );
				}
			}
		}
		return array( 'logo' => $logo, 'items' => $items, 'dropdowns' => array_slice( $dropdowns, 0, 20 ), 'has_dropdowns' => ! empty( $dropdowns ), 'cta' => $cta, 'mobile_trigger' => $mobile, 'behavior' => $mobile ? 'unknown' : 'not_detected' );
	}

	/**
	 * Link nested section IDs.
	 *
	 * @param array<string, mixed> $context  DOM context.
	 * @param array<int, array<string, mixed>> $sections Sections.
	 * @return void
	 */
	private function add_nested_children( $context, &$sections ) {
		$by_node = array();
		foreach ( $sections as $index => $section ) {
			$by_node[ $section['node_id'] ] = $index;
		}
		foreach ( $sections as $index => $section ) {
			foreach ( $this->dom->get_descendant_ids( $context, $section['node_id'], 5000 ) as $descendant ) {
				if ( isset( $by_node[ $descendant ] ) && $by_node[ $descendant ] !== $index ) {
					$sections[ $index ]['children'][] = $sections[ $by_node[ $descendant ] ]['id'];
				}
			}
		}
	}

	/**
	 * Find the first heading text in a subtree.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return string
	 */
	private function first_heading( $context, $node_id ) {
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			foreach ( $this->dom->get_descendant_ids( $context, $node_id, 1500 ) as $descendant ) {
				$node = $this->dom->get_node( $context, $descendant );
				if ( empty( $node['visible'] ) ) {
					continue;
				}
				if ( $tag === ( isset( $node['tag'] ) ? $node['tag'] : '' ) ) {
					$text = $this->dom->get_text( $context, $descendant, 240 );
					if ( '' !== $text ) {
						return $text;
					}
				}
			}
		}
		return '';
	}

	/**
	 * Determine whether a node has a descendant heading.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return bool
	 */
	private function has_heading( $context, $node_id ) {
		return '' !== $this->first_heading( $context, $node_id );
	}

	/**
	 * Count descendants with a tag.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @param string               $tag     Tag.
	 * @return int
	 */
	private function count_descendant_tag( $context, $node_id, $tag ) {
		$count = 0;
		foreach ( $this->dom->get_descendant_ids( $context, $node_id, 5000 ) as $descendant ) {
			$node = $this->dom->get_node( $context, $descendant );
			if ( empty( $node['visible'] ) ) {
				continue;
			}
			if ( $tag === ( isset( $node['tag'] ) ? $node['tag'] : '' ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Determine whether a subtree has a tag.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @param string               $tag     Tag.
	 * @return bool
	 */
	private function has_descendant_tag( $context, $node_id, $tag ) {
		return $this->count_descendant_tag( $context, $node_id, $tag ) > 0;
	}

	/**
	 * Detect button-like descendants.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return bool
	 */
	private function has_button( $context, $node_id ) {
		if ( $this->has_descendant_tag( $context, $node_id, 'button' ) ) {
			return true;
		}
		foreach ( $this->dom->get_descendant_ids( $context, $node_id, 1500 ) as $descendant ) {
			$node = $this->dom->get_node( $context, $descendant );
			if ( 'a' !== ( isset( $node['tag'] ) ? $node['tag'] : '' ) ) {
				continue;
			}
			$hint = $this->dom->get_hint_text( $context, $descendant );
			if ( false !== strpos( $hint, 'btn' ) || false !== strpos( $hint, 'button' ) || false !== strpos( $hint, 'cta' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Count repeated direct child structures.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return int
	 */
	private function count_repeated_children( $context, $node_id ) {
		$types = array();
		foreach ( $this->dom->get_child_ids( $context, $node_id ) as $child_id ) {
			$hint = $this->dom->get_hint_text( $context, $child_id );
			foreach ( array( 'card', 'product', 'post', 'tile', 'item', 'feature' ) as $needle ) {
				if ( false !== strpos( $hint, $needle ) ) {
					$types[ $needle ] = true;
					break;
				}
			}
		}
		$count = 0;
		foreach ( $this->dom->get_child_ids( $context, $node_id ) as $child_id ) {
			$hint = $this->dom->get_hint_text( $context, $child_id );
			foreach ( array_keys( $types ) as $needle ) {
				if ( false !== strpos( $hint, $needle ) ) {
					$count++;
					break;
				}
			}
		}
		return $count;
	}

	/**
	 * Get relative position in the document.
	 *
	 * @param array<string, mixed> $context    DOM context.
	 * @param string               $node_id    Node ID.
	 * @param int                  $total_nodes Total nodes.
	 * @return float
	 */
	private function relative_position( $context, $node_id, $total_nodes ) {
		$node = $this->dom->get_node( $context, $node_id );
		$order = isset( $node['order'] ) ? (int) $node['order'] : 0;
		return $total_nodes > 0 ? min( 1, $order / $total_nodes ) : 0;
	}

	/**
	 * Detect static background signals.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return bool
	 */
	private function has_background_signal( $context, $node_id ) {
		$node = $this->dom->get_node( $context, $node_id );
		return isset( $node['style'] ) && ( false !== stripos( $node['style'], 'background-image' ) || false !== stripos( $node['style'], 'background:' ) );
	}

	/**
	 * Return a safe URL reference from a node attribute.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @param string               $attribute Attribute.
	 * @return string|null
	 */
	private function safe_reference( $context, $node_id, $attribute ) {
		$node = $this->dom->get_node( $context, $node_id );
		$value = isset( $node['attributes'][ $attribute ] ) ? $node['attributes'][ $attribute ] : '';
		$url = Security::resolve_url( $context['base_url'], $value );
		$url = Security::normalize_http_url( (string) $url );
		return is_string( $url ) && Security::is_safe_public_reference( $url ) ? $url : null;
	}

	/**
	 * Find body node.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @return string
	 */
	private function find_body( $context ) {
		foreach ( $this->dom->get_node_ids( $context ) as $node_id ) {
			$node = $this->dom->get_node( $context, $node_id );
			if ( isset( $node['tag'] ) && 'body' === $node['tag'] ) {
				return $node_id;
			}
		}
		return '';
	}
}
