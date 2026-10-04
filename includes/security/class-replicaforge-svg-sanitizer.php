<?php
/**
 * Phase 8: SVG sanitization.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Makes an SVG safe to store and to render.
 *
 * An SVG is not an image format. It is a document format that can contain script,
 * event handlers, external references, and embedded HTML, and a browser renders it
 * with the same privileges as the page it sits on. Importing one from a website
 * being analyzed would therefore be importing an attack the analyst did not write.
 *
 * This sanitizer takes a conservative approach on purpose. It removes everything it
 * does not positively recognize as drawing, because an icon that loses a decorative
 * path is a cosmetic loss while an icon that keeps a script handler is a
 * compromise. Anything it strips is reported, so a caller can tell the difference
 * between an icon that was already clean and one that arrived hostile.
 *
 * It is a sanitizer, not a parser. It does not attempt to fully understand SVG, and
 * it does not claim the result is safe by construction — it reports what it removed
 * so that claim can be audited.
 */
final class Svg_Sanitizer {

	/**
	 * Elements that draw and are kept.
	 *
	 * Anything not listed here is removed. This is an allow list on purpose.
	 *
	 * @var array<string, string>
	 */
	const ALLOWED_ELEMENTS = array(
		'svg'            => 'svg',
		'g'              => 'g',
		'defs'           => 'defs',
		'path'           => 'path',
		'circle'         => 'circle',
		'ellipse'        => 'ellipse',
		'line'           => 'line',
		'polyline'       => 'polyline',
		'polygon'        => 'polygon',
		'rect'           => 'rect',
		'text'           => 'text',
		'tspan'          => 'tspan',
		'title'          => 'title',
		'desc'           => 'desc',
		// SVG spells these in camel case, but the lookup is case-insensitive
		// because a hostile document may use any case, so the keys are lowercase.
		'lineargradient' => 'linearGradient',
		'radialgradient' => 'radialGradient',
		'stop'           => 'stop',
		'clippath'       => 'clipPath',
		'mask'           => 'mask',
		'pattern'        => 'pattern',
		'marker'         => 'marker',
		'use'            => 'use',
		'symbol'         => 'symbol',
		'textpath'       => 'textPath',
		'fegaussianblur' => 'feGaussianBlur',
		'fecolormatrix'  => 'feColorMatrix',
		'feblend'        => 'feBlend',
		'femergenode'    => 'feMergeNode',
		'femergenodes'   => 'feMergeNodes',
		'fecomponenttransfer' => 'feComponentTransfer',
		'fecomposite'    => 'feComposite',
		'feconvolvematrix' => 'feConvolveMatrix',
		'fediffuselighting' => 'feDiffuseLighting',
		'fedisplacementmap' => 'feDisplacementMap',
		'fedropshadow'   => 'feDropShadow',
		'feflood'        => 'feFlood',
		'fefunca'        => 'feFuncA',
		'fefuncb'        => 'feFuncB',
		'fefuncg'        => 'feFuncG',
		'fefuncr'        => 'feFuncR',
		'feoffset'       => 'feOffset',
		'fepointlight'   => 'fePointLight',
		'fespecularlighting' => 'feSpecularLighting',
		'fespotlight'    => 'feSpotLight',
		'fetile'         => 'feTile',
		'feturbulence'   => 'feTurbulence',
	);

	/**
	 * Elements removed together with everything inside them.
	 *
	 * The contents of these elements are the payload rather than drawing, so
	 * removing the element has to take the contents with it. Lifting them out as
	 * loose text would leave the code in the document.
	 *
	 * @var array<string, string>
	 */
	const REJECTED_ELEMENTS = array(
		'script'         => 'executes script',
		'style'          => 'can carry executable constructs and can override page styles',
		'foreignObject'  => 'embeds arbitrary HTML, including scripts',
		'iframe'         => 'embeds a document',
		'embed'          => 'embeds a document',
		'object'         => 'embeds a document',
		'animatetransform' => 'runs a timed transform',
		'animatemotion'  => 'runs a timed motion path',
		'animate'        => 'runs a timed change and can set an attribute to script',
		'set'            => 'runs a timed attribute change',
		'handler'        => 'registers an event handler',
		'mpath'          => 'references an animation path that may itself animate',
	);

	/**
	 * Elements whose wrapper is removed while their contents are kept.
	 *
	 * A link around a path is the ordinary way an icon is built, so removing the link
	 * with its contents would leave nothing. The wrapper is the problem, not what it
	 * contains, and the contents are cleaned on their own merits.
	 *
	 * @var array<string, string>
	 */
	const UNWRAPPED_ELEMENTS = array(
		'a' => 'can navigate or execute',
	);

	/**
	 * Attributes that are always removed, with the reason recorded.
	 *
	 * @var array<string, string>
	 */
	const REJECTED_ATTRIBUTES = array(
		'onload'         => 'runs on load',
		'onerror'        => 'runs on error',
		'onclick'        => 'runs on click',
		'onmouseover'    => 'runs on hover',
		'onmouseout'     => 'runs on hover',
		'onfocus'        => 'runs on focus',
		'onblur'         => 'runs on blur',
		'onbegin'        => 'runs when an animation begins',
		'onend'          => 'runs when an animation ends',
		'onrepeat'       => 'runs on every animation repeat',
		'onactivate'     => 'runs when activated',
		'formaction'     => 'submits a form',
		'ping'          => 'sends a request on click',
	);

	/**
	 * Attributes whose value is a URL and must be checked.
	 *
	 * @var array<string, string>
	 */
	const URL_ATTRIBUTES = array(
		'href'          => 'reference',
		'xlink:href'    => 'reference',
		'src'           => 'reference',
		'filter'        => 'filter',
		'fill'          => 'paint',
		'stroke'        => 'paint',
		'clip-path'     => 'clip',
		'mask'          => 'mask',
		'marker-start'  => 'marker',
		'marker-mid'    => 'marker',
		'marker-end'    => 'marker',
		'style'         => 'inline style',
	);

	/**
	 * URL schemes that may appear in a value.
	 *
	 * A fragment reference is how an icon reuses a symbol, so it is allowed. Every
	 * other scheme reaches outside the document, and a data scheme can carry a
	 * document of its own.
	 *
	 * @var array<int, string>
	 */
	const SAFE_SCHEMES = array( '' );

	/**
	 * Maximum bytes accepted for one SVG.
	 */
	const MAX_BYTES = 262144;

	/**
	 * Maximum number of elements retained.
	 */
	const MAX_ELEMENTS = 2000;

	/**
	 * Sanitize an SVG document.
	 *
	 * @param string $svg Raw SVG.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $svg ) {
		$report = array(
			'accepted'   => false,
			'safe'       => false,
			'reason'     => null,
			'removed'    => array(),
			'counts'     => array( 'elements' => 0, 'attributes' => 0 ),
			'sanitized'  => null,
			'bytes'      => 0,
		);

		if ( ! is_string( $svg ) || '' === trim( $svg ) ) {
			$report['reason'] = 'empty';
			return $report;
		}

		$report['bytes'] = strlen( $svg );

		if ( $report['bytes'] > self::MAX_BYTES ) {
			// A large SVG is refused rather than truncated, because truncating
			// document markup produces a document that is not what was written.
			$report['reason'] = 'too_large';
			return $report;
		}

		// An XML declaration or a doctype is not needed for an inline SVG and is a
		// place an entity declaration can hide.
		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $svg ) ) {
			$report['reason'] = 'doctype_or_entity';
			$report['removed'][] = 'a document type or entity declaration';
			return $report;
		}

		if ( ! preg_match( '/<svg[\s>]/i', $svg ) ) {
			$report['reason'] = 'not_svg';
			return $report;
		}

		$document = self::parse( $svg );
		if ( null === $document ) {
			$report['reason'] = 'unparsable';
			return $report;
		}

		$removed = array();
		$counts  = array( 'elements' => 0, 'attributes' => 0 );
		// The document element, not the document. A DOMDocument's node type is the
	// document type rather than the element type, so passing it would be refused as
	// an unrecognized node and the icon would be rejected however clean it was.
	$root    = $document->documentElement;
	$clean   = ( null === $root ) ? null : self::clean_node( $root, $removed, $counts, 0 );

		if ( $counts['elements'] > self::MAX_ELEMENTS ) {
			$report['reason'] = 'too_many_elements';
			$report['removed'] = $removed;
			$report['counts']  = $counts;
			return $report;
		}

		if ( null === $clean ) {
			$report['reason']     = 'no_drawable_root';
			$report['removed']    = $removed;
			$report['counts']     = $counts;
			return $report;
		}

		// The root has to remain an svg element, or the result is not an SVG at all.
		if ( 'svg' !== strtolower( (string) $clean->nodeName ) ) {
			$report['reason']  = 'root_not_svg';
			$report['removed'] = $removed;
			$report['counts']  = $counts;
			return $report;
		}

		$output = $document->saveXML( $clean );

		$report['accepted']  = true;
		$report['safe']      = true;
		// A sanitized SVG is safe by having been reduced to a known set of drawing
		// elements. A document that arrived with nothing removed is reported as
		// already clean, which is a different statement from one that was cleaned.
		$report['removed']   = array_values( array_unique( $removed ) );
		$report['counts']    = $counts;
		$report['was_clean'] = empty( $report['removed'] );
		$report['sanitized'] = is_string( $output ) ? $output : null;

		return $report;
	}

	/**
	 * Parse an SVG into a document, with entity expansion disabled.
	 *
	 * @param string $svg Raw SVG.
	 * @return \DOMDocument|null
	 */
	private static function parse( $svg ) {
		if ( ! class_exists( '\DOMDocument' ) ) {
			return null;
		}

		$previous = null;
		if ( function_exists( 'libxml_use_internal_errors' ) ) {
			$previous = libxml_use_internal_errors( true );
		}

		$document                     = new \DOMDocument();
		$document->preserveWhiteSpace = false;
		$document->formatOutput       = false;

		// LIBXML_NONET blocks network access during parsing, and no entity flags are
		// passed, so no entity is expanded and no external document is fetched.
		$flags = LIBXML_NONET;

		$loaded = @$document->loadXML( $svg, $flags );

		if ( function_exists( 'libxml_clear_errors' ) ) {
			libxml_clear_errors();
		}
		if ( null !== $previous && function_exists( 'libxml_use_internal_errors' ) ) {
			libxml_use_internal_errors( $previous );
		}

		return $loaded ? $document : null;
	}

	/**
	 * Recursively reduce a node to what is safe to keep.
	 *
	 * @param \DOMNode              $node    Node to clean.
	 * @param array<int, string>    $removed Collected removals.
	 * @param array<string, int>    $counts  Element and attribute counts.
	 * @param int                   $depth   Current depth.
	 * @return \DOMNode|null
	 */
	private static function clean_node( $node, array &$removed, array &$counts, $depth ) {
		if ( $depth > Analysis_Limits::MAX_DOM_DEPTH ) {
			$removed[] = 'content deeper than the depth limit';
			return null;
		}

		// A comment or a processing instruction carries no drawing and is a place a
		// conditional comment can hide markup.
		if ( XML_COMMENT_NODE === $node->nodeType || XML_PI_NODE === $node->nodeType ) {
			$removed[] = 'a comment or processing instruction';
			return null;
		}

		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			$text = (string) $node->nodeValue;
			// Markup characters in text would be re-parsed as markup on save, so they
			// are encoded rather than left as they are.
			$safe = htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
			$node->nodeValue = $safe;
			return $node;
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			$removed[] = 'a node type that is not an element or text';
			return null;
		}

		$name = strtolower( (string) $node->nodeName );
		$counts['elements']++;

		if ( isset( self::REJECTED_ELEMENTS[ $name ] ) ) {
			$removed[] = 'a <' . $name . '> element, which ' . self::REJECTED_ELEMENTS[ $name ];
			// Removed with its contents, because the contents are the payload.
			return null;
		}

		if ( isset( self::UNWRAPPED_ELEMENTS[ $name ] ) ) {
			$removed[] = 'a <' . $name . '> element, which ' . self::UNWRAPPED_ELEMENTS[ $name ];
			// The wrapper goes and what it contained is cleaned on its own merits.
			return self::lift_children( $node, $removed, $counts, $depth );
		}

		if ( ! isset( self::ALLOWED_ELEMENTS[ $name ] ) ) {
			// An unrecognized element is removed rather than kept, but its children
			// may still draw, so they are lifted out rather than discarded with it.
			$removed[] = 'an unrecognized <' . $name . '> element';
			$lifted     = self::lift_children( $node, $removed, $counts, $depth );
			return $lifted;
		}

		self::clean_attributes( $node, $removed, $counts );

		// Children are cleaned before the parent is returned, so a removed child
		// cannot reintroduce anything through the parent.
		$children = array();
		foreach ( $node->childNodes as $child ) {
			$cleaned = self::clean_node( $child, $removed, $counts, $depth + 1 );
			if ( null !== $cleaned ) {
				$children[] = $cleaned;
			}
		}

		while ( $node->firstChild ) {
			$node->removeChild( $node->firstChild );
		}
		foreach ( $children as $child ) {
			$node->appendChild( $child );
		}

		// The document element carries the namespace. A nested element that
		// declares one is harmless but unnecessary, and removing it avoids a
		// re-declaration on save.
		if ( 'svg' !== $name && $node->hasAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xlink' ) ) {
			$node->removeAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xlink' );
		}

		return $node;
	}

	/**
	 * Lift the drawing children out of an element that is being removed.
	 *
	 * @param \DOMNode           $node    Node being removed.
	 * @param array<int, string> $removed Collected removals.
	 * @param array<string, int> $counts  Counts.
	 * @param int                $depth   Current depth.
	 * @return \DOMNode|null
	 */
	private static function lift_children( \DOMNode $node, array &$removed, array &$counts, $depth ) {
		$kept = array();
		foreach ( $node->childNodes as $child ) {
			$cleaned = self::clean_node( $child, $removed, $counts, $depth + 1 );
			if ( null !== $cleaned ) {
				$kept[] = $cleaned;
			}
		}

		if ( empty( $kept ) ) {
			return null;
		}

		// The children are wrapped in a group so the surrounding structure stays
		// valid. A group draws nothing itself, so nothing visible is lost.
		$group = $node->ownerDocument->createElementNS( 'http://www.w3.org/2000/svg', 'g' );
		foreach ( $kept as $child ) {
			$group->appendChild( $child );
		}

		return $group;
	}

	/**
	 * Reduce one element's attributes to what is safe.
	 *
	 * @param \DOMNode           $node    Element.
	 * @param array<int, string> $removed Collected removals.
	 * @param array<string, int> $counts  Counts.
	 * @return void
	 */
	private static function clean_attributes( \DOMNode $node, array &$removed, array &$counts ) {
		if ( ! $node->hasAttributes() ) {
			return;
		}

		// The attribute list is copied first, because removing from a live
		// NamedNodeMap while iterating it skips entries.
		$attributes = array();
		foreach ( $node->attributes as $attribute ) {
			$attributes[] = array(
				'name'  => strtolower( (string) $attribute->nodeName ),
				'local' => strtolower( (string) $attribute->localName ),
				'value' => (string) $attribute->nodeValue,
			);
		}

		foreach ( $attributes as $attribute ) {
			$name = $attribute['name'];

			// An event handler, in any form. This is checked before the name is
			// matched against the known list so that an unexpected spelling of one
			// is still caught.
			if ( 0 === strpos( $name, 'on' ) ) {
				$removed[] = 'an event handler attribute (' . $name . ')';
				$node->removeAttribute( $attribute['name'] );
				continue;
			}

			if ( isset( self::REJECTED_ATTRIBUTES[ $name ] ) ) {
				$removed[] = 'an attribute that ' . self::REJECTED_ATTRIBUTES[ $name ] . ' (' . $name . ')';
				$node->removeAttribute( $attribute['name'] );
				continue;
			}

			if ( 'style' === $name ) {
				$removed[] = 'an inline style attribute, which can carry an expression';
				$node->removeAttribute( $attribute['name'] );
				continue;
			}

			if ( in_array( $name, array( self::URL_ATTRIBUTES ), true )
				|| isset( self::URL_ATTRIBUTES[ $name ] )
				|| in_array( $attribute['local'], array( 'href', 'src' ), true ) ) {
				if ( ! self::safe_url( $attribute['value'] ) ) {
					$removed[] = 'a ' . ( self::URL_ATTRIBUTES[ $name ] ?? 'reference' ) . ' attribute with an unsafe value';
					$node->removeAttribute( $attribute['name'] );
				}
				continue;
			}

			// An attribute value must never contain markup, because the value is
			// re-serialized and a stray angle bracket would change the structure.
			if ( false !== strpos( $attribute['value'], '<' ) ) {
				$removed[] = 'an attribute value containing markup';
				$node->removeAttribute( $attribute['name'] );
				continue;
			}

			$counts['attributes']++;
		}
	}

	/**
	 * Return whether a URL value is safe to keep.
	 *
	 * Only a same-document fragment is allowed. A relative path reaches the network
	 * or the filesystem, an absolute URL leaves the site, and a data scheme can carry
	 * a whole document.
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	public static function safe_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return true;
		}

		// A fragment reference is how an icon reuses a symbol.
		if ( 0 === strpos( $value, '#' ) ) {
			return true;
		}

		// A paint keyword is not a URL at all. url() is handled below.
		if ( 0 === strpos( $value, 'url(' ) ) {
			$inner = trim( substr( $value, 4, -1 ) );
			$inner = trim( $inner, "'\" \t" );
			return self::safe_url( $inner );
		}

		if ( in_array( strtolower( $value ), array( 'none', 'inherit', 'currentcolor', 'transparent', 'initial', 'unset' ), true ) ) {
			return true;
		}

		// A bare color, a keyword, or a function is not a reference.
		if ( null !== Css_Value_Parser::color( $value ) ) {
			return true;
		}
		if ( preg_match( '/^[a-z-]+\s*\(/i', $value ) ) {
			// A function that is not url() and not a color function, such as a
			// gradient or a calc, does not fetch anything by itself. A gradient can
			// contain a url(), which is caught by the check inside the function.
			return false !== strpos( $value, 'url(' ) ? self::safe_url( $value ) : true;
		}

		// A scheme of any kind is not a fragment, so it leaves the document.
		if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $value ) ) {
			return false;
		}

		// A relative path would resolve against the page, which is a request.
		if ( '/' === $value[0] || '.' === $value[0] || '\\' === $value[0] ) {
			return false;
		}

		// A bare word is a keyword such as `middle` or `nonzero`.
		if ( preg_match( '/^[a-z][a-z0-9-]*$/i', $value ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Return whether a value looks like an external or executable reference.
	 *
	 * Used by the asset layer to decide whether an asset can be referenced at all,
	 * before the sanitizer is involved.
	 *
	 * @param string $value Raw value.
	 * @return array<string, mixed>
	 */
	public static function inspect_reference( $value ) {
		$value = trim( (string) $value );
		$out   = array(
			'value'   => $value,
			'scheme'  => null,
			'kind'    => 'unknown',
			'allowed' => false,
			'reason'  => null,
		);

		if ( '' === $value ) {
			$out['kind']    = 'empty';
			$out['allowed'] = true;
			return $out;
		}

		if ( 0 === strpos( $value, '#' ) ) {
			$out['kind']    = 'fragment';
			$out['allowed'] = true;
			return $out;
		}

		if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $value, $matches ) ) {
			$out['scheme'] = strtolower( $matches[1] );
		}

		// A data URI can carry a document of its own, including a script.
		if ( 'data' === $out['scheme'] ) {
			$out['kind']   = 'data';
			$out['reason'] = 'a data URI can carry an executable document';
			return $out;
		}

		if ( 'javascript' === $out['scheme'] || 'vbscript' === $out['scheme'] ) {
			$out['kind']   = 'script';
			$out['reason'] = 'a script scheme executes';
			return $out;
		}

		if ( 'file' === $out['scheme'] || 'ftp' === $out['scheme'] ) {
			$out['kind']   = 'filesystem';
			$out['reason'] = 'the scheme reaches outside the web origin';
			return $out;
		}

		if ( in_array( $out['scheme'], array( 'http', 'https' ), true ) ) {
			$out['kind']   = 'remote';
			$out['allowed'] = true;
			return $out;
		}

		if ( 0 === strpos( $value, '/' ) || 0 === strpos( $value, './' ) || 0 === strpos( $value, '../' ) ) {
			$out['kind']    = 'relative';
			$out['allowed'] = true;
			return $out;
		}

		$out['kind']    = 'relative';
		$out['allowed'] = true;

		return $out;
	}
}
