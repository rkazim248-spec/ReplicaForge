<?php
/**
 * DOM indexing and source-traceable page context.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a sanitized DOM into a bounded, queryable analysis context.
 *
 * The context stores safe metadata and DOM references only. It never exposes
 * raw remote HTML to the REST or admin layers.
 */
final class Dom_Analyzer {

	/**
	 * HTML parser helpers.
	 *
	 * @var Html_Parser
	 */
	private $parser;

	/**
	 * Constructor.
	 *
	 * @param Html_Parser|null $parser HTML parser.
	 */
	public function __construct( $parser = null ) {
		$this->parser = $parser instanceof Html_Parser ? $parser : new Html_Parser();
	}

	/**
	 * Build the Phase 2 DOM context.
	 *
	 * @param \DOMDocument $document Sanitized document.
	 * @param string       $base_url Final page URL.
	 * @return array<string, mixed>
	 */
	public function build( $document, $base_url ) {
		$context = array(
			'base_url' => Security::normalize_http_url( $base_url ),
			'document' => $document instanceof \DOMDocument ? $document : null,
			'nodes'    => array(),
			'dom'      => array(),
			'node_map' => array(),
			'roots'    => array(),
			'regions'  => array(),
			'warnings' => array(),
		);
		if ( ! $document instanceof \DOMDocument ) {
			$context['warnings'][] = 'The DOM document was unavailable.';
			return $context;
		}

		$elements = array();
		foreach ( $document->getElementsByTagName( '*' ) as $element ) {
			if ( $element instanceof \DOMElement ) {
				$elements[] = $element;
			}
			if ( count( $elements ) >= Analysis_Limits::MAX_DOM_NODES ) {
				$context['warnings'][] = 'The DOM node limit was reached.';
				break;
			}
		}

		foreach ( $elements as $index => $element ) {
			$node_id = 'node_' . str_pad( (string) ( $index + 1 ), 5, '0', STR_PAD_LEFT );
			$hash    = spl_object_hash( $element );
			$parent  = $element->parentNode;
			$parent_id = null;
			$depth    = 0;
			$current  = $element;
			while ( $current instanceof \DOMElement && $depth < Analysis_Limits::MAX_DOM_DEPTH ) {
				$current = $current->parentNode;
				$depth++;
			}

			if ( $parent instanceof \DOMElement ) {
				$parent_hash = spl_object_hash( $parent );
				$parent_id   = isset( $context['node_map'][ $parent_hash ] ) ? $context['node_map'][ $parent_hash ] : null;
			}

			$attributes = $this->extract_attributes( $element );
			$classes    = $this->split_classes( $element->getAttribute( 'class' ) );
			$context['nodes'][ $node_id ] = array(
				'id'         => $node_id,
				'tag'        => strtolower( $element->tagName ),
				'order'      => $index,
				'parent'     => $parent_id,
				'children'   => array(),
				'depth'      => max( 0, $depth - 1 ),
				'classes'    => $classes,
				'attributes' => $attributes,
				'style'      => Security::clean_text( $element->getAttribute( 'style' ), 2000 ),
				'visible'    => ! $this->parser->is_hidden( $element ),
				'child_count' => $this->count_element_children( $element ),
			);
			$context['dom'][ $node_id ] = $element;
			$context['node_map'][ $hash ] = $node_id;
		}

		foreach ( $context['nodes'] as $node_id => $node ) {
			if ( null !== $node['parent'] && isset( $context['nodes'][ $node['parent'] ] ) ) {
				$context['nodes'][ $node['parent'] ]['children'][] = $node_id;
			} else {
				$context['roots'][] = $node_id;
			}
		}

		foreach ( $context['nodes'] as $node_id => $node ) {
			$context['nodes'][ $node_id ]['selector'] = $this->build_selector( $context, $node_id );
		}

		$context['regions'] = $this->detect_regions( $context );
		return $context;
	}

	/**
	 * Return the DOM element for a context node.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Node ID.
	 * @return \DOMElement|null
	 */
	public function get_element( $context, $node_id ) {
		return isset( $context['dom'][ $node_id ] ) && $context['dom'][ $node_id ] instanceof \DOMElement
			? $context['dom'][ $node_id ]
			: null;
	}

	/**
	 * Return safe node metadata.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Node ID.
	 * @return array<string, mixed>
	 */
	public function get_node( $context, $node_id ) {
		return isset( $context['nodes'][ $node_id ] ) && is_array( $context['nodes'][ $node_id ] )
			? $context['nodes'][ $node_id ]
			: array();
	}

	/**
	 * Return indexed DOM elements in document order.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @return array<int, \DOMElement>
	 */
	public function get_elements( $context ) {
		return array_values( array_filter( (array) ( isset( $context['dom'] ) ? $context['dom'] : array() ), static function ( $element ) { return $element instanceof \DOMElement; } ) );
	}

	/**
	 * Return all indexed node IDs in document order.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @return array<int, string>
	 */
	public function get_node_ids( $context ) {
		return array_keys( isset( $context['nodes'] ) && is_array( $context['nodes'] ) ? $context['nodes'] : array() );
	}

	/**
	 * Return direct child node IDs.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Node ID.
	 * @return array<int, string>
	 */
	public function get_child_ids( $context, $node_id ) {
		$node = $this->get_node( $context, $node_id );
		return ! empty( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
	}

	/**
	 * Return bounded descendant node IDs in document order.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Root node ID.
	 * @param int                  $limit   Maximum descendants.
	 * @return array<int, string>
	 */
	public function get_descendant_ids( $context, $node_id, $limit = 5000 ) {
		$queue = $this->get_child_ids( $context, $node_id );
		$result = array();
		$index  = 0;
		while ( isset( $queue[ $index ] ) && count( $result ) < $limit ) {
			$current = $queue[ $index ];
			$index++;
			$result[] = $current;
			foreach ( $this->get_child_ids( $context, $current ) as $child ) {
				$queue[] = $child;
			}
		}
		return $result;
	}

	/**
	 * Return ancestor node IDs from nearest to root.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Node ID.
	 * @return array<int, string>
	 */
	public function get_ancestor_ids( $context, $node_id ) {
		$ancestors = array();
		$current   = $this->get_node( $context, $node_id );
		$steps     = 0;
		while ( ! empty( $current['parent'] ) && $steps < Analysis_Limits::MAX_DOM_DEPTH ) {
			$ancestors[] = $current['parent'];
			$current = $this->get_node( $context, $current['parent'] );
			$steps++;
		}
		return $ancestors;
	}

	/**
	 * Determine whether an ancestor has a given tag.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @param string               $tag     Tag name.
	 * @return bool
	 */
	public function has_ancestor_tag( $context, $node_id, $tag ) {
		foreach ( $this->get_ancestor_ids( $context, $node_id ) as $ancestor_id ) {
			$ancestor = $this->get_node( $context, $ancestor_id );
			if ( isset( $ancestor['tag'] ) && $tag === $ancestor['tag'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return visible text for a node.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Node ID.
	 * @param int                  $limit   Maximum text length.
	 * @return string
	 */
	public function get_text( $context, $node_id, $limit = Analysis_Limits::MAX_SOURCE_TEXT_LENGTH ) {
		$element = $this->get_element( $context, $node_id );
		return $element ? $this->parser->get_clean_text( $element, $limit ) : '';
	}

	/**
	 * Return a safe source-trace object.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Node ID.
	 * @return array<string, string>
	 */
	public function get_source( $context, $node_id ) {
		$node = $this->get_node( $context, $node_id );
		return array(
			'node_id' => $node_id,
			'tag'     => isset( $node['tag'] ) ? $node['tag'] : 'unknown',
			'selector' => isset( $node['selector'] ) ? $node['selector'] : $node_id,
		);
	}

	/**
	 * Return a class/id token string for heuristics.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Node ID.
	 * @return string
	 */
	public function get_hint_text( $context, $node_id ) {
		$node = $this->get_node( $context, $node_id );
		$parts = array();
		if ( ! empty( $node['classes'] ) && is_array( $node['classes'] ) ) {
			$parts = array_merge( $parts, $node['classes'] );
		}
		$id = isset( $node['attributes']['id'] ) ? $node['attributes']['id'] : '';
		if ( '' !== $id ) {
			$parts[] = $id;
		}
		return strtolower( implode( ' ', $parts ) );
	}

	/**
	 * Detect top-level page regions from semantic and class/id signals.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @return array<int, array<string, mixed>>
	 */
	public function detect_regions( $context ) {
		$regions = array();
		$seen    = array();
		$map     = array(
			'header' => 'header',
			'nav'    => 'navigation',
			'main'   => 'main',
			'footer' => 'footer',
			'aside'  => 'sidebar',
		);
		$hints   = array(
			'header'   => 'header',
			'nav'      => 'navigation',
			'main'     => 'main',
			'content'  => 'content',
			'sidebar'  => 'sidebar',
			'footer'   => 'footer',
			'hero'     => 'hero',
		);

		$counts = array(
			'header'     => 0,
			'navigation' => 0,
			'main'       => 0,
			'content'    => 0,
			'footer'     => 0,
			'sidebar'    => 0,
			'hero'       => 0,
		);
		foreach ( $this->get_node_ids( $context ) as $node_id ) {
			$node = $this->get_node( $context, $node_id );
			if ( empty( $node['visible'] ) ) {
				continue;
			}
			$tag  = isset( $node['tag'] ) ? $node['tag'] : '';
			$depth = isset( $node['depth'] ) ? (int) $node['depth'] : 0;
			$hint = $this->get_hint_text( $context, $node_id );
			$type = '';
			$confidence = 0.0;
			$is_semantic = isset( $map[ $tag ] );
			if ( $is_semantic ) {
				$type = $map[ $tag ];
				$confidence = 0.9;
			}
			if ( ! $is_semantic ) {
				foreach ( $hints as $needle => $label ) {
					if ( preg_match( '/(?:^|[\s_-])' . preg_quote( $needle, '/' ) . '(?:$|[\s_-])/i', $hint ) ) {
						if ( $depth > 3 || ( 'content' === $label && $depth > 1 ) ) {
							continue;
						}
						$type = $label;
						$confidence = max( $confidence, 0.72 );
						break;
					}
				}
			}
			if ( 'navigation' === $type && $this->has_ancestor_tag( $context, $node_id, 'footer' ) ) {
				continue;
			}
			if ( '' === $type || isset( $seen[ $node_id ] ) || ! isset( $counts[ $type ] ) ) {
				continue;
			}
			$max_regions = 'navigation' === $type ? 3 : ( 'sidebar' === $type || 'hero' === $type ? 2 : 1 );
			if ( $counts[ $type ] >= $max_regions || count( $regions ) >= 20 ) {
				continue;
			}
			$counts[ $type ]++;
			$seen[ $node_id ] = true;
			$regions[] = array(
				'id'         => 'region_' . str_pad( (string) ( count( $regions ) + 1 ), 3, '0', STR_PAD_LEFT ),
				'type'       => $type,
				'order'      => count( $regions ),
				'confidence' => round( $confidence, 2 ),
				'source'     => $this->get_source( $context, $node_id ),
				'node_id'    => $node_id,
			);
		}

		return $regions;
	}

	/**
	 * Check whether a node has a class token.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Node ID.
	 * @param string               $needle  Token fragment.
	 * @return bool
	 */
	public function has_class_hint( $context, $node_id, $needle ) {
		return false !== strpos( $this->get_hint_text( $context, $node_id ), strtolower( $needle ) );
	}

	/**
	 * Match a simple static selector against an indexed node.
	 *
	 * This intentionally supports only safe structural selectors. It is not a
	 * browser CSS engine and never evaluates selector scripts.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Node ID.
	 * @param string               $selector Selector text.
	 * @return bool
	 */
	public function matches_selector( $context, $node_id, $selector ) {
		$node = $this->get_node( $context, $node_id );
		if ( empty( $node['tag'] ) || ! is_string( $selector ) ) {
			return false;
		}
		if ( preg_match( '/::?[a-zA-Z_-]/', $selector ) ) {
			return false;
		}
		$selector = trim( $selector );
		if ( '' === $selector ) {
			return false;
		}
		$parts = preg_split( '/\s+/', $selector );
		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return false;
		}
		$last = array_pop( $parts );
		if ( ! $this->matches_compound( $context, $node_id, $last ) ) {
			return false;
		}
		$ancestors = $this->get_ancestor_ids( $context, $node_id );
		$ancestor_index = count( $ancestors ) - 1;
		foreach ( array_reverse( $parts ) as $part ) {
			$matched = false;
			while ( $ancestor_index >= 0 ) {
				if ( $this->matches_compound( $context, $ancestors[ $ancestor_index ], $part ) ) {
					$matched = true;
					$ancestor_index--;
					break;
				}
				$ancestor_index--;
			}
			if ( ! $matched ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Return a bounded attribute map safe for internal analysis.
	 *
	 * @param \DOMElement $element Element.
	 * @return array<string, string>
	 */
	private function extract_attributes( $element ) {
		$allowed = array(
			'id', 'class', 'href', 'src', 'alt', 'role', 'type', 'name', 'rel', 'media',
			'width', 'height', 'placeholder', 'method', 'enctype', 'open', 'datetime',
			'aria-label', 'aria-hidden', 'aria-expanded', 'aria-controls', 'aria-haspopup',
			'aria-current', 'data-toggle', 'data-target', 'data-bs-toggle', 'data-state',
			'data-index', 'data-role', 'data-open', 'data-src', 'data-lazy-src', 'loading',
			'disabled', 'checked', 'multiple', 'required', 'rows', 'cols', 'scope', 'value',
			// Phase 16. The attributes that carry an interaction's declared semantics.
			//
			// Each of these was previously unreachable, so a carousel declared with
			// data-ride, a tab set marked with ria-selected, or a widget typed by
			// ria-roledescription had no attribute for the detector to read and
			// simply could not be found. That is the interesting shape of this change:
			// the markup was already declaring the behaviour, and the allowlist was
			// what stopped us seeing it.
			//
			// Adding here is additive and bounded. A consumer reading the phase 2
			// representation sees extra keys and ignores the ones it does not know,
			// which is the contract every previous addition to this list has had.
			//
			// alue is still the only attribute on this list that can hold user
			// input, and Phase 16 deliberately does not copy it - see
			// Interaction_Detector::safe_attributes(), which projects a narrower set
			// for the same reason.
			'aria-selected', 'aria-modal', 'aria-roledescription', 'aria-describedby',
			'aria-labelledby', 'aria-pressed', 'aria-checked', 'aria-level', 'aria-owns',
			'data-bs-target', 'data-ride', 'data-slide', 'data-slide-to', 'data-interval',
			'data-bs-interval', 'data-bs-pause', 'data-wrap', 'data-bs-wrap', 'data-trigger',
			'data-dismiss', 'data-bs-dismiss', 'data-parent', 'data-offset', 'data-spy',
			'for', 'tabindex', 'autocomplete', 'pattern', 'minlength', 'maxlength',
		);
		$attributes = array();
		if ( ! $element->hasAttributes() ) {
			return $attributes;
		}
		foreach ( $element->attributes as $attribute ) {
			$name = strtolower( $attribute->nodeName );
			if ( ! in_array( $name, $allowed, true ) ) {
				continue;
			}
			$attributes[ $name ] = Security::clean_text( $attribute->nodeValue, 500 );
		}
		return $attributes;
	}

	/**
	 * Normalize class tokens.
	 *
	 * @param string $class_string Raw class attribute.
	 * @return array<int, string>
	 */
	private function split_classes( $class_string ) {
		$class_string = substr( (string) $class_string, 0, 2048 );
		$tokens = preg_split( '/\s+/', strtolower( trim( $class_string ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) ) {
			return array();
		}
		$clean = array();
		foreach ( $tokens as $token ) {
			$token = preg_replace( '/[^a-z0-9_-]/', '', $token );
			if ( '' !== $token ) {
				$clean[] = substr( $token, 0, 80 );
			}
			if ( count( $clean ) >= 12 ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Count direct element children.
	 *
	 * @param \DOMElement $element Element.
	 * @return int
	 */
	private function count_element_children( $element ) {
		$count = 0;
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Build a deterministic, non-executable source selector.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Node ID.
	 * @return string
	 */
	private function build_selector( $context, $node_id ) {
		$parts = array();
		$current_id = $node_id;
		$steps = 0;
		while ( '' !== $current_id && $steps < 12 ) {
			$node = $this->get_node( $context, $current_id );
			if ( empty( $node['tag'] ) ) {
				break;
			}
			$sibling_index = 1;
			$parent_id = isset( $node['parent'] ) ? $node['parent'] : null;
			if ( $parent_id ) {
				foreach ( $this->get_child_ids( $context, $parent_id ) as $sibling_id ) {
					if ( $sibling_id === $current_id ) {
						break;
					}
					$sibling = $this->get_node( $context, $sibling_id );
					if ( isset( $sibling['tag'] ) && $sibling['tag'] === $node['tag'] ) {
						$sibling_index++;
					}
				}
			}
			$parts[] = $node['tag'] . ':nth-of-type(' . $sibling_index . ')';
			$current_id = $parent_id;
			$steps++;
		}
		$selector = implode( ' > ', array_reverse( $parts ) );
		return substr( $selector, 0, Analysis_Limits::MAX_SELECTOR_LENGTH );
	}

	/**
	 * Match a single compound selector such as `.card`, `h2`, or `#hero`.
	 *
	 * @param array<string, mixed> $context Analysis context.
	 * @param string               $node_id Node ID.
	 * @param string               $part    Compound selector.
	 * @return bool
	 */
	private function matches_compound( $context, $node_id, $part ) {
		$node = $this->get_node( $context, $node_id );
		if ( empty( $node['tag'] ) ) {
			return false;
		}
		$part = trim( $part );
		if ( '' === $part || '*' === $part ) {
			return true;
		}
		if ( preg_match_all( '/\.([a-zA-Z0-9_-]+)/', $part, $classes ) ) {
			foreach ( $classes[1] as $class ) {
				if ( ! in_array( strtolower( $class ), $node['classes'], true ) ) {
					return false;
				}
			}
		}
		if ( preg_match_all( '/#([a-zA-Z0-9_-]+)/', $part, $ids ) ) {
			$actual_id = isset( $node['attributes']['id'] ) ? strtolower( $node['attributes']['id'] ) : '';
			foreach ( $ids[1] as $id ) {
				if ( strtolower( $id ) !== $actual_id ) {
					return false;
				}
			}
		}
		if ( preg_match( '/^\s*([a-zA-Z][a-zA-Z0-9-]*)/', $part, $tag_match ) && strtolower( $tag_match[1] ) !== $node['tag'] ) {
			return false;
		}
		if ( preg_match_all( '/\[([a-zA-Z0-9_-]+)(?:=["\']?([^\]"\' ]*)["\']?)?\]/', $part, $attributes, PREG_SET_ORDER ) ) {
			foreach ( $attributes as $attribute ) {
				$name = strtolower( $attribute[1] );
				$actual = isset( $node['attributes'][ $name ] ) ? $node['attributes'][ $name ] : null;
				if ( null === $actual ) {
					return false;
				}
				if ( isset( $attribute[2] ) && '' !== $attribute[2] && $actual !== $attribute[2] ) {
					return false;
				}
			}
		}
		return true;
	}
}
