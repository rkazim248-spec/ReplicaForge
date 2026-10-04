<?php
/**
 * Section and website-type heuristics for ReplicaForge.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Provides a replaceable, transparent structural analysis layer.
 *
 * A future AI or computer-vision service can implement the same conceptual
 * boundary without coupling itself to the REST controller or admin UI.
 */
final class Structure_Detector {

	const MAX_SECTIONS = 100;

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
	 * Detect semantic and likely visual sections.
	 *
	 * @param array<int, \DOMElement> $elements Document elements.
	 * @return array<int, array<string, mixed>>
	 */
	public function detect_sections( $elements ) {
		$sections = array();
		$seen     = array();
		$semantic = array(
			'header'  => 'Header',
			'nav'     => 'Navigation',
			'main'    => 'Main',
			'section' => 'Section',
			'article' => 'Article',
			'footer'  => 'Footer',
			'aside'   => 'Aside',
		);
		$hints = array(
			'hero'          => 'Hero',
			'feature'       => 'Features',
			'product'       => 'Products',
			'pricing'       => 'Pricing',
			'testimonial'   => 'Testimonials',
			'faq'           => 'FAQ',
			'contact'       => 'Contact',
			'gallery'       => 'Gallery',
			'portfolio'     => 'Portfolio',
			'service'       => 'Services',
			'about'         => 'About',
			'team'          => 'Team',
			'blog'          => 'Blog',
			'cta'           => 'CTA',
			'call'          => 'CTA',
			'news'          => 'News',
		);

		foreach ( $elements as $element ) {
			$tag  = strtolower( $element->tagName );
			$type = '';
			$hint = in_array( $tag, array( 'div', 'main', 'section', 'article', 'aside', 'header', 'footer', 'nav' ), true )
				? $this->parser->get_section_hint( $element, $hints )
				: '';
			if ( '' !== $hint ) {
				$type = $hint;
			} elseif ( isset( $semantic[ $tag ] ) ) {
				$type = $semantic[ $tag ];
			}

			if ( '' === $type ) {
				continue;
			}

			$object_id = spl_object_hash( $element );
			if ( isset( $seen[ $object_id ] ) || count( $sections ) >= self::MAX_SECTIONS ) {
				continue;
			}
			$seen[ $object_id ] = true;

			$sections[] = array(
				'type'     => $type,
				'index'    => count( $sections ) + 1,
				'heading'  => $this->parser->get_first_heading( $element ),
				'elements' => $this->parser->get_element_types( $element ),
			);
		}

		return $sections;
	}

	/**
	 * Apply transparent frontend-only website type heuristics.
	 *
	 * @param string                            $title      Page title.
	 * @param array<int, array<string, string>> $headings   Headings.
	 * @param array<int, array<string, string>> $paragraphs Paragraphs.
	 * @param array<int, array<string, string>> $links      Links.
	 * @param array<int, array<string, mixed>>  $sections   Sections.
	 * @return array{type: string, confidence: float}
	 */
	public function detect_website_type( $title, $headings, $paragraphs, $links, $sections ) {
		$parts = array( $title );
		foreach ( array( $headings, $paragraphs, $links ) as $collection ) {
			foreach ( $collection as $item ) {
				$parts[] = isset( $item['text'] ) ? $item['text'] : ( isset( $item['url'] ) ? $item['url'] : '' );
			}
		}
		foreach ( $sections as $section ) {
			$parts[] = isset( $section['type'] ) ? $section['type'] : '';
			$parts[] = isset( $section['heading'] ) ? $section['heading'] : '';
		}

		$haystack = strtolower( Security::truncate_text( implode( ' ', array_filter( $parts ) ), 120000 ) );
		$signals  = array(
			'E-commerce' => array(
				'cart' => 5, 'checkout' => 6, 'add to cart' => 7, 'product' => 2, 'shop' => 3,
				'store' => 2, 'pricing' => 1, 'buy now' => 4, 'order' => 1,
			),
			'Blog' => array(
				'blog' => 5, 'article' => 2, 'posts' => 3, 'read more' => 2, 'author' => 2, 'archive' => 2,
			),
			'Portfolio' => array(
				'portfolio' => 6, 'project' => 3, 'case study' => 4, 'my work' => 4, 'creative' => 2, 'resume' => 2,
			),
			'Documentation' => array(
				'documentation' => 6, 'docs' => 4, 'guide' => 2, 'tutorial' => 3, 'api reference' => 5, 'changelog' => 3, 'installation' => 2,
			),
			'News' => array(
				'news' => 5, 'headlines' => 4, 'latest news' => 5, 'press' => 2, 'article' => 1,
			),
			'Landing Page' => array(
				'hero' => 3, 'features' => 2, 'get started' => 4, 'sign up' => 3, 'call to action' => 4, 'testimonials' => 2, 'subscribe' => 2,
			),
			'Business' => array(
				'about us' => 3, 'our services' => 4, 'contact us' => 3, 'company' => 2, 'solutions' => 2, 'team' => 1,
			),
		);

		$scores = array();
		foreach ( $signals as $type => $terms ) {
			$score = 0;
			foreach ( $terms as $term => $weight ) {
				$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/iu';
				$count   = preg_match_all( $pattern, $haystack, $term_matches );
				$score  += min( 3, (int) $count ) * $weight;
			}
			$scores[ $type ] = $score;
		}

		$max = max( $scores );
		if ( $max < 2 ) {
			return array(
				'type'       => 'Unknown',
				'confidence' => 0,
			);
		}

		arsort( $scores );
		$total      = array_sum( $scores );
		$winner     = key( $scores );
		$confidence = min(
			0.8,
			0.25 + min( 0.35, $max * 0.04 ) + ( $max / max( 1, $total ) * 0.2 )
		);

		return array(
			'type'       => (string) $winner,
			'confidence' => round( (float) $confidence, 2 ),
		);
	}
}
