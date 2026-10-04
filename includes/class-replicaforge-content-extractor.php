<?php
/**
 * Bounded content extraction helpers for ReplicaForge.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts safe, bounded page content from a sanitized DOM.
 */
final class Content_Extractor {

	const MAX_HEADINGS   = 100;
	const MAX_PARAGRAPHS = 200;
	const MAX_IMAGES     = 100;
	const MAX_LINKS      = 200;

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
	 * Extract the document title.
	 *
	 * @param \DOMDocument $document Parsed document.
	 * @return string
	 */
	public function extract_title( $document ) {
		if ( ! $document instanceof \DOMDocument ) {
			return '';
		}

		foreach ( $document->getElementsByTagName( 'title' ) as $title ) {
			if ( $title instanceof \DOMElement ) {
				$value = $this->parser->get_clean_text( $title, 300 );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Extract selected metadata values.
	 *
	 * @param array<int, \DOMElement> $elements Document elements.
	 * @return array{description: string, language: string, viewport: string}
	 */
	public function extract_meta( $elements ) {
		$description = '';
		$viewport    = '';
		$language    = '';

		foreach ( $elements as $element ) {
			if ( 'html' === strtolower( $element->tagName ) && '' === $language ) {
				$language = Security::clean_text( $this->parser->get_attribute( $element, 'lang' ), 35 );
			}

			if ( 'meta' !== strtolower( $element->tagName ) ) {
				continue;
			}

			$name       = strtolower( trim( $this->parser->get_attribute( $element, 'name' ) ) );
			$property   = strtolower( trim( $this->parser->get_attribute( $element, 'property' ) ) );
			$http_equiv = strtolower( trim( $this->parser->get_attribute( $element, 'http-equiv' ) ) );
			$content    = Security::clean_text( $this->parser->get_attribute( $element, 'content' ), 1000 );

			if ( '' === $content ) {
				continue;
			}

			if ( '' === $description && in_array( $name, array( 'description', 'og:description', 'twitter:description' ), true ) ) {
				$description = $content;
			}
			if ( '' === $description && 'description' === $property ) {
				$description = $content;
			}
			if ( '' === $viewport && 'viewport' === $name ) {
				$viewport = $content;
			}
			if ( '' === $language && in_array( $name, array( 'language', 'content-language' ), true ) ) {
				$language = $content;
			}
			if ( '' === $language && 'content-language' === $http_equiv ) {
				$language = $content;
			}
		}

		return array(
			'description' => $description,
			'language'    => $language,
			'viewport'    => $viewport,
		);
	}

	/**
	 * Extract headings in document order.
	 *
	 * @param array<int, \DOMElement> $elements Document elements.
	 * @return array<int, array{tag: string, text: string}>
	 */
	public function extract_headings( $elements ) {
		$headings = array();
		$tags     = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

		foreach ( $elements as $element ) {
			$tag = strtolower( $element->tagName );
			if ( ! in_array( $tag, $tags, true ) ) {
				continue;
			}

			$text = $this->parser->get_clean_text( $element, 500 );
			if ( '' === $text ) {
				continue;
			}

			$headings[] = array(
				'tag'  => $tag,
				'text' => $text,
			);
			if ( count( $headings ) >= self::MAX_HEADINGS ) {
				break;
			}
		}

		return $headings;
	}

	/**
	 * Extract visible paragraph text.
	 *
	 * @param array<int, \DOMElement> $elements Document elements.
	 * @return array<int, array{text: string}>
	 */
	public function extract_paragraphs( $elements ) {
		$paragraphs = array();

		foreach ( $elements as $element ) {
			if ( 'p' !== strtolower( $element->tagName ) || $this->parser->is_hidden( $element ) ) {
				continue;
			}

			$text = $this->parser->get_clean_text( $element, 1200 );
			if ( '' === $text ) {
				continue;
			}

			$paragraphs[] = array( 'text' => $text );
			if ( count( $paragraphs ) >= self::MAX_PARAGRAPHS ) {
				break;
			}
		}

		return $paragraphs;
	}

	/**
	 * Extract image metadata without downloading image resources.
	 *
	 * @param array<int, \DOMElement> $elements Document elements.
	 * @param string                   $base_url Final page URL.
	 * @return array<int, array{src: string, alt: string, width: int|null, height: int|null}>
	 */
	public function extract_images( $elements, $base_url ) {
		$images = array();
		$seen   = array();

		foreach ( $elements as $element ) {
			if ( 'img' !== strtolower( $element->tagName ) || $this->parser->is_hidden( $element ) ) {
				continue;
			}

			$src = null;
			foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $attribute ) {
				$candidate = $this->normalize_extracted_url( $base_url, $this->parser->get_attribute( $element, $attribute ) );
				if ( null !== $candidate ) {
					$src = $candidate;
					break;
				}
			}

			if ( null === $src || isset( $seen[ $src ] ) ) {
				continue;
			}
			$seen[ $src ] = true;

			$alt       = $this->parser->get_attribute( $element, 'alt' );
			$images[] = array(
				'src'    => $src,
				'alt'    => '' !== $alt ? Security::clean_text( $alt, 300 ) : '',
				'width'  => $this->get_dimension( $this->parser->get_attribute( $element, 'width' ) ),
				'height' => $this->get_dimension( $this->parser->get_attribute( $element, 'height' ) ),
			);
			if ( count( $images ) >= self::MAX_IMAGES ) {
				break;
			}
		}

		return $images;
	}

	/**
	 * Extract safe frontend links without crawling them.
	 *
	 * @param array<int, \DOMElement> $elements Document elements.
	 * @param string                   $base_url Final page URL.
	 * @return array<int, array{text: string, url: string}>
	 */
	public function extract_links( $elements, $base_url ) {
		$links = array();
		$seen  = array();

		foreach ( $elements as $element ) {
			if ( 'a' !== strtolower( $element->tagName ) || $this->parser->is_hidden( $element ) ) {
				continue;
			}

			$url = $this->normalize_extracted_url( $base_url, $this->parser->get_attribute( $element, 'href' ) );
			if ( null === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;

			$text = $this->parser->get_clean_text( $element, 240 );
			if ( '' === $text ) {
				$text = $url;
			}

			$links[] = array(
				'text' => $text,
				'url'  => $url,
			);
			if ( count( $links ) >= self::MAX_LINKS ) {
				break;
			}
		}

		return $links;
	}

	/**
	 * Resolve and validate an extracted URL without resolving its hostname.
	 *
	 * @param string $base_url Final page URL.
	 * @param string $raw_url  Raw href/src value.
	 * @return string|null
	 */
	private function normalize_extracted_url( $base_url, $raw_url ) {
		$raw_url = trim( (string) $raw_url );
		if ( '' === $raw_url || strlen( $raw_url ) > 2048 || ',' === $raw_url[0] ) {
			return null;
		}

		$url = Security::resolve_url( $base_url, $raw_url );
		$url = Security::normalize_http_url( (string) $url );
		if ( null === $url ) {
			return null;
		}

		$parts = Security::parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		if ( Security::is_forbidden_target_path(
			isset( $parts['path'] ) ? $parts['path'] : '/',
			isset( $parts['query'] ) ? $parts['query'] : ''
		) ) {
			return null;
		}

		$host = Security::normalize_host( (string) $parts['host'] );
		if ( '' === $host || Security::is_dangerous_hostname( $host ) ) {
			return null;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) && ! Security::is_public_ip( $host ) ) {
			return null;
		}

		return $url;
	}

	/**
	 * Parse a positive pixel dimension.
	 *
	 * @param string $value Attribute value.
	 * @return int|null
	 */
	private function get_dimension( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{1,6}$/', $value ) ) {
			return null;
		}

		$dimension = absint( $value );
		return $dimension > 0 && $dimension <= 100000 ? $dimension : null;
	}
}
