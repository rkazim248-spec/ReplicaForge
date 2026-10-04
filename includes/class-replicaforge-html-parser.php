<?php
/**
 * Safe DOM parsing helpers for remote HTML.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes fetched HTML and exposes read-only DOM helpers.
 *
 * The parser never renders, evaluates, or executes markup. It only parses the
 * document as data and returns a DOM tree to the analyzer.
 */
final class Html_Parser {

	/**
	 * Parse a remote HTML document.
	 *
	 * @param string $html Untrusted HTML from the HTTP client.
	 * @return array<string, mixed> Parser envelope.
	 */
	public function parse( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'empty_document',
					'message' => 'The website returned an unexpected response.',
					'status'  => 502,
				),
			);
		}

		if ( strlen( $html ) > Security::MAX_RESPONSE_SIZE ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'response_too_large',
					'message' => 'The page is too large to analyze.',
					'status'  => 413,
				),
			);
		}

		$html = $this->sanitize_html( $html );
		if ( '' === trim( $html ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'empty_document',
					'message' => 'The website returned an unexpected response.',
					'status'  => 502,
				),
			);
		}

		if ( ! class_exists( '\\DOMDocument' ) || ! class_exists( '\\DOMXPath' ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'parser_unavailable',
					'message' => 'The server does not have the HTML parser required for analysis.',
					'status'  => 500,
				),
			);
		}

		$document                     = new \DOMDocument( '1.0', 'UTF-8' );
		$document->preserveWhiteSpace = false;
		$document->strictErrorChecking = false;
		$document->resolveExternals    = false;
		$document->substituteEntities  = false;

		$previous_errors = libxml_use_internal_errors( true );
		$load_result     = $document->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		if ( ! $load_result || ! $document->documentElement ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'invalid_document',
					'message' => 'The website returned an unexpected response.',
					'status'  => 502,
				),
			);
		}

		return array(
			'success' => true,
			'document' => $document,
			'xpath'    => new \DOMXPath( $document ),
			'html'     => $html,
		);
	}

	/**
	 * Get the parsed DOM document.
	 *
	 * @param array<string, mixed> $parsed Parser result.
	 * @return \DOMDocument|null
	 */
	public function get_document( $parsed ) {
		return isset( $parsed['document'] ) && $parsed['document'] instanceof \DOMDocument ? $parsed['document'] : null;
	}

	/**
	 * Get visible text from an element while skipping executable/hidden nodes.
	 *
	 * @param \DOMNode $node  Root node.
	 * @param int      $depth Current recursion depth.
	 * @return string
	 */
	public function get_visible_text( $node, $depth = 0 ) {
		if ( ! $node instanceof \DOMNode || $depth > 64 ) {
			return '';
		}

		if ( $node instanceof \DOMText ) {
			return (string) $node->nodeValue;
		}

		if ( $node instanceof \DOMElement ) {
			$tag = strtolower( $node->tagName );
			if ( in_array( $tag, array( 'script', 'style', 'noscript', 'template', 'iframe', 'object', 'embed' ), true ) || $this->is_hidden( $node ) ) {
				return '';
			}
		}

		$text = '';
		foreach ( $node->childNodes as $child ) {
			$text .= $this->get_visible_text( $child, $depth + 1 );
			if ( strlen( $text ) >= 20000 ) {
				$text = substr( $text, 0, 20000 );
				break;
			}
		}

		return $text;
	}

	/**
	 * Normalize visible text for analysis output.
	 *
	 * @param \DOMNode $node       Root node.
	 * @param int      $max_length Maximum output length.
	 * @return string
	 */
	public function get_clean_text( $node, $max_length = 1000 ) {
		return Security::clean_text( $this->get_visible_text( $node ), $max_length );
	}

	/**
	 * Read a DOM element attribute.
	 *
	 * @param \DOMElement $element Element.
	 * @param string      $name    Attribute name.
	 * @return string
	 */
	public function get_attribute( $element, $name ) {
		if ( ! $element instanceof \DOMElement || ! $element->hasAttribute( $name ) ) {
			return '';
		}

		return (string) $element->getAttribute( $name );
	}

	/**
	 * Determine whether an element is explicitly hidden.
	 *
	 * @param \DOMElement $element Element.
	 * @return bool
	 */
	public function is_hidden( $element ) {
		if ( ! $element instanceof \DOMElement ) {
			return false;
		}

		$current = $element;
		$depth   = 0;
		while ( $current instanceof \DOMElement && $depth < 64 ) {
			if ( $current->hasAttribute( 'hidden' ) ) {
				return true;
			}
			if ( 'true' === strtolower( trim( $this->get_attribute( $current, 'aria-hidden' ) ) ) ) {
				return true;
			}

			$style = strtolower( $this->get_attribute( $current, 'style' ) );
			if ( preg_match( '/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\s*(?:;|$)/', $style ) ) {
				return true;
			}

			$current = $current->parentNode;
			$depth++;
		}

		return false;
	}

	/**
	 * Find the first visible heading within an element.
	 *
	 * @param \DOMElement $element Root element.
	 * @return string
	 */
	public function get_first_heading( $element ) {
		if ( ! $element instanceof \DOMElement ) {
			return '';
		}

		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			$nodes = $element->getElementsByTagName( $tag );
			foreach ( $nodes as $node ) {
				if ( $node instanceof \DOMElement && ! $this->is_hidden( $node ) ) {
					$text = $this->get_clean_text( $node, 240 );
					if ( '' !== $text ) {
						return $text;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Return a compact list of element types below a node.
	 *
	 * @param \DOMElement $element Root element.
	 * @return array<int, string>
	 */
	public function get_element_types( $element ) {
		if ( ! $element instanceof \DOMElement ) {
			return array();
		}

		$types = array();
		$queue = array( $element );
		$index = 0;
		$seen  = 0;

		while ( isset( $queue[ $index ] ) && $seen < 5000 ) {
			$node = $queue[ $index ];
			$index++;
			$seen++;
			if ( ! $node instanceof \DOMElement || $this->is_hidden( $node ) ) {
				continue;
			}

			$tag = strtolower( $node->tagName );
			$type = $this->map_element_type( $tag );
			if ( '' !== $type && ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}

			for ( $child = $node->firstChild; $child; $child = $child->nextSibling ) {
				$queue[] = $child;
			}
		}

		return $types;
	}

	/**
	 * Check class/id tokens for a semantic section hint.
	 *
	 * @param \DOMElement $element Element.
	 * @param array       $needles Lowercase token fragments.
	 * @return string Matching canonical label, or an empty string.
	 */
	public function get_section_hint( $element, array $needles ) {
		if ( ! $element instanceof \DOMElement ) {
			return '';
		}

		$attributes = strtolower( $this->get_attribute( $element, 'class' ) . ' ' . $this->get_attribute( $element, 'id' ) );
		$tokens     = preg_split( '/[\s_#.:>-]+/', $attributes, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) ) {
			return '';
		}

		foreach ( $needles as $needle => $label ) {
			foreach ( $tokens as $token ) {
				if ( $token === $needle || false !== strpos( $token, $needle ) ) {
					return $label;
				}
			}
		}

		return '';
	}

	/**
	 * Sanitize remote HTML before it is parsed.
	 *
	 * @param string $html Untrusted HTML.
	 * @return string
	 */
	private function sanitize_html( $html ) {
		$html = preg_replace( '/<\?xml[^>]*\?>/i', '', $html );
		$html = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $html );
		$html = preg_replace( '/<script\b[^>]*>[\s\S]*?(?:<\/script\s*>|$)/i', '', $html );
		$html = preg_replace( '/<noscript\b[^>]*>[\s\S]*?(?:<\/noscript\s*>|$)/i', '', $html );
		$html = preg_replace( '/<template\b[^>]*>[\s\S]*?(?:<\/template\s*>|$)/i', '', $html );
		// Style blocks are retained as inert text for the CSS analyzer.
		$html = preg_replace( '/<iframe\b[^>]*>[\s\S]*?(?:<\/iframe\s*>|$)/i', '', $html );
		$html = preg_replace( '/<(?:object|embed|applet|base)\b[^>]*>[\s\S]*?<\/(?:object|embed|applet|base)\s*>/i', '', $html );
		$html = preg_replace( '/<(?:object|embed|applet|base)\b[^>]*\/?>/i', '', $html );
		$html = preg_replace( '/\s+(?:href|src|action)\s*=\s*(?:"\s*(?:javascript|vbscript|data):[^"]*"|\'\s*(?:javascript|vbscript|data):[^\']*\'|(?:javascript|vbscript|data):[^\s>]+)/i', '', $html );

		$allowed_tags = $this->get_allowed_tags();
		if ( function_exists( 'wp_kses' ) ) {
			$html = wp_kses( $html, $allowed_tags );
		} else {
			$allowed = '<' . implode( '><', array_keys( $allowed_tags ) ) . '>';
			$html    = strip_tags( $html, $allowed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		}

		// Keep event handlers and active URL schemes out even when a site has
		// customized KSES filters. These attributes are never needed by the
		// analyzer and are never executed by the admin UI.
		$html = preg_replace( '/\s+on[a-z0-9_-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html );
		$html = preg_replace( '/\s+(?:href|src|action)\s*=\s*(?:"\s*(?:javascript|vbscript|data):[^"]*"|\'\s*(?:javascript|vbscript|data):[^\']*\'|(?:javascript|vbscript|data):[^\s>]+)/i', '', $html );

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Return the intentionally small HTML allow-list used by the parser.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function get_allowed_tags() {
		$common = array_fill_keys(
			array(
				'class',
				'id',
				'style',
				'title',
				'role',
				'lang',
				'dir',
				'aria-label',
				'aria-hidden',
				'aria-expanded',
				'aria-controls',
				'aria-haspopup',
				'aria-current',
				'data-toggle',
				'data-target',
				'data-bs-toggle',
				'data-state',
				'data-index',
				'data-role',
				'data-open',
			),
			true
		);
		$tags   = array(
			'html'       => array( 'lang' => true, 'dir' => true ),
			'head'       => array(),
			'title'      => array(),
			'meta'       => array(
				'name'       => true,
				'property'   => true,
				'content'    => true,
				'charset'    => true,
				'http-equiv' => true,
			),
			'link'       => array(
				'rel'   => true,
				'href'  => true,
				'media' => true,
				'type'  => true,
				'sizes' => true,
			),
			'style'      => array( 'media' => true, 'type' => true ),
			'body'       => $common,
			'header'     => $common,
			'nav'        => $common,
			'main'       => $common,
			'section'    => $common,
			'article'    => $common,
			'footer'     => $common,
			'aside'      => $common,
			'div'        => $common,
			'span'       => $common,
			'p'          => $common,
			'h1'         => $common,
			'h2'         => $common,
			'h3'         => $common,
			'h4'         => $common,
			'h5'         => $common,
			'h6'         => $common,
			'a'          => array_merge(
				$common,
				array( 'href' => true, 'target' => true, 'rel' => true )
			),
			'img'        => array_merge(
				$common,
				array(
					'src'          => true,
					'srcset'       => true,
					'alt'          => true,
					'width'        => true,
					'height'       => true,
					'loading'      => true,
					'data-src'     => true,
					'data-lazy-src' => true,
				)
			),
			'picture'    => $common,
			'source'     => array_merge(
				$common,
				array( 'src' => true, 'srcset' => true, 'sizes' => true, 'type' => true, 'media' => true )
			),
			'form'       => array_merge(
				$common,
				array( 'method' => true, 'enctype' => true )
			),
			'fieldset'   => $common,
			'legend'     => $common,
			'label'      => $common,
			'input'      => array_merge(
				$common,
				array(
					'type'        => true,
					'name'        => true,
					'placeholder' => true,
					'required'    => true,
					'disabled'    => true,
					'checked'     => true,
				)
			),
			'textarea'   => array_merge(
				$common,
				array(
					'name'        => true,
					'placeholder' => true,
					'rows'        => true,
					'cols'        => true,
					'required'    => true,
					'disabled'    => true,
				)
			),
			'select'     => array_merge(
				$common,
				array( 'name' => true, 'multiple' => true, 'disabled' => true )
			),
			'option'     => array_merge(
				$common,
				array( 'value' => true, 'selected' => true, 'disabled' => true )
			),
			'button'     => array_merge(
				$common,
				array( 'type' => true, 'disabled' => true )
			),
			'details'    => array_merge( $common, array( 'open' => true ) ),
			'summary'    => $common,
			'address'    => $common,
			'time'       => array_merge( $common, array( 'datetime' => true ) ),
			'mark'       => $common,
			'small'      => $common,
			'b'          => $common,
			'i'          => $common,
			's'          => $common,
			'u'          => $common,
			'abbr'       => $common,
			'cite'       => $common,
			'caption'    => $common,
			'colgroup'   => $common,
			'col'        => $common,
			'tfoot'      => $common,
			'ul'         => $common,
			'ol'         => $common,
			'li'         => $common,
			'table'      => $common,
			'thead'      => $common,
			'tbody'      => $common,
			'tr'         => $common,
			'th'         => array_merge( $common, array( 'colspan' => true, 'rowspan' => true, 'scope' => true ) ),
			'td'         => array_merge( $common, array( 'colspan' => true, 'rowspan' => true ) ),
			'blockquote' => $common,
			'strong'     => $common,
			'em'         => $common,
			'br'         => array(),
			'hr'         => $common,
		);

		return $tags;
	}

	/**
	 * Map a tag name to a compact semantic element type.
	 *
	 * @param string $tag Lowercase tag name.
	 * @return string
	 */
	private function map_element_type( $tag ) {
		$map = array(
			'h1'         => 'heading',
			'h2'         => 'heading',
			'h3'         => 'heading',
			'h4'         => 'heading',
			'h5'         => 'heading',
			'h6'         => 'heading',
			'p'          => 'paragraph',
			'img'        => 'image',
			'a'          => 'link',
			'ul'         => 'list',
			'ol'         => 'list',
			'li'         => 'list-item',
			'table'      => 'table',
			'blockquote' => 'quote',
		);

		return isset( $map[ $tag ] ) ? $map[ $tag ] : '';
	}
}
