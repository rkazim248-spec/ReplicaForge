<?php
/**
 * Phase 12: page classification.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what kind of page each discovered URL is.
 *
 * ### Deterministic first, AI only as a tiebreak
 *
 * §8 allows AI to assist, and this class uses it for exactly one thing: when the
 * deterministic signals cannot separate two candidates. The order matters, because a
 * URL path is evidence. `https://example.com/about/team/` is a team page whether or
 * not a model agrees, and asking a model first would mean paying for an answer
 * obtainable for free and *trusting* it more, since a model cannot be shown its own
 * reasoning afterwards.
 *
 * ### Why `custom` is a real answer
 *
 * A site with `/capabilities/` is not misclassified by being called `custom`; it is
 * misclassified by being called `about`. `custom` is what a page the evidence does
 * not identify gets, and a user can see that and change it. A wrong confident
 * answer is worse than an honest uncertain one, because the wrong one is applied to
 * every page of that type without anybody looking.
 *
 * ### Confidence is reported, not implied
 *
 * Every classification carries a confidence and a list of the signals that
 * produced it, so a user can see *why* a page was called what it was and override
 * it. §8's "AI output must be validated" is satisfied here by AI never being the
 * only evidence for a confident answer.
 */
final class Page_Classifier {

	/**
	 * Path patterns, as exact-or-prefix roots, per type.
	 *
	 * ### Why a type is *either* an exact root or a prefix
	 *
	 * The naive version of this table — one list of path fragments per type, matched
	 * as prefixes — cannot distinguish a blog's front page from one of its posts,
	 * because both are `/blog`. It resolves the collision by ordering, and ordering is
	 * the fragile way to resolve it: whichever entry is checked first silently wins,
	 * and a site with a `/services` page and a `/services/web` page gets one of the
	 * two wrong.
	 *
	 * So the table is split instead, and the split *is* the rule:
	 *
	 * - `EXACT_ROOTS` matches only when the whole path equals the root. `/blog` is
	 *   the archive; `/services` is the services index.
	 * - `PREFIX_ROOTS` matches when the path is the root *or anything under it*.
	 *   `/blog` is therefore a post, `/services/web` is a service, and `/product/x`
	 *   is a product.
	 *
	 * Types appear in exactly one list, so no entry can shadow another, and adding a
	 * type cannot change the behaviour of an existing one.
	 *
	 * ### No trailing slashes
	 *
	 * The candidate path is rtrimmed of its trailing slash before matching, and a
	 * pattern written `/blog/` is compared as `/blog/` against a path that no longer
	 * has one. Those patterns were present in the first draft, could never match, and
	 * classified nothing — a failure that reads as working code. A test asserting
	 * `/blog/how-to-x` is a *post* rather than an archive is what found them.
	 *
	 * @var array<string, array<int, string>>
	 */
	const EXACT_ROOTS = array(
		'homepage'        => array( '/', '/home', '/index' ),
		'blog_archive'    => array( '/blog', '/news', '/posts', '/articles', '/journal', '/insights', '/updates' ),
		'services'        => array( '/services', '/service', '/what-we-do', '/capabilities', '/offerings' ),
		'product_archive' => array( '/products', '/shop', '/store', '/catalog', '/collections', '/catalogue' ),
		'portfolio'       => array( '/portfolio', '/work', '/our-work' ),
		'blog'            => array( '/blog-index' ),
		'category'        => array( '/category', '/categories', '/tag', '/tags', '/topics' ),
		// Legal pages are matched as *slugs*, not as prefixes. `/privacy-policy` and
		// `/privacy` are different pages that are both legal, so a prefix rule would
		// catch one and miss the other. The list is the set of names these pages are
		// published under in practice, and anything outside it becomes `custom` — a
		// legal page ReplicaForge fails to name is not crawled, which is the safe
		// direction for this particular type.
		'legal'           => array(
			'/privacy', '/privacy-policy', '/privacy-notice', '/gdpr',
			'/terms', '/terms-of-service', '/tos', '/terms-of-use',
			'/legal', '/legal-notice', '/legal-information',
			'/cookie-policy', '/cookies', '/cookie-Notice',
			'/imprint', '/impressum', '/disclaimer', '/refund-policy',
			'/shipping-policy', '/accessibility', '/colophon', '/sitemap',
		),
	);

	/**
	 * Prefix roots, per type.
	 *
	 * @var array<string, array<int, string>>
	 */
	const PREFIX_ROOTS = array(
		'blog_post'       => array( '/blog', '/news', '/posts', '/articles', '/journal', '/insights', '/updates' ),
		'product'         => array( '/product', '/products', '/shop', '/store', '/item' ),
		'service_detail'  => array( '/services', '/service', '/solutions', '/solution' ),
		'project_detail'  => array( '/projects', '/case-studies' ),
		'pricing'         => array( '/pricing', '/plans', '/plans-and-pricing', '/subscribe', '/memberships' ),
		'contact'         => array( '/contact', '/contact-us', '/get-in-touch', '/enquiry', '/inquiry' ),
		'faq'             => array( '/faq', '/faqs', '/frequently-asked-questions', '/help', '/support' ),
		'team'            => array( '/team', '/about/team', '/people', '/staff', '/leadership' ),
		'about'           => array( '/about', '/about-us', '/who-we-are', '/our-story', '/mission' ),
		'documentation'   => array( '/docs', '/documentation', '/guides', '/manual', '/reference' ),
		'search'          => array( '/search', '/find' ),
		'landing'         => array( '/lp', '/landing', '/campaign', '/offer', '/promo', '/signup', '/register' ),
	);

	/**
	 * Return the type of one URL, from its path alone.
	 *
	 * @param string $url Candidate URL.
	 * @return array<string, mixed>
	 */
	public static function classify_url( $url ) {
		$path    = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) );
		$path    = ( '' === $path ) ? '/' : $path;
		$trimmed = ( '/' === $path ) ? '/' : rtrim( $path, '/' );

		// An exact root is tested first, in both directions. `/blog` is the archive
		// and `/blog/how-to-x` is a post, and the only way to say that without an
		// ordering accident is to test the exact case before the prefix case.
		foreach ( self::EXACT_ROOTS as $type => $roots ) {
			foreach ( $roots as $root ) {
				if ( '/' === $root ) {
					if ( '/' === $trimmed ) {
						return array(
							'type'       => $type,
							'confidence' => 0.99,
							'signal'     => 'path_is_root',
							'reason'     => 'The path is the site root.',
						);
					}
					continue;
				}
				if ( $trimmed === $root ) {
					return array(
						'type'       => $type,
						'confidence' => 0.95,
						'signal'     => 'path_is_index',
						'reason'     => 'The path is exactly the address this page type is published at, so it is the index rather than an item.',
					);
				}
			}
		}

		foreach ( self::PREFIX_ROOTS as $type => $roots ) {
			foreach ( $roots as $root ) {
				if ( $trimmed === $root || 0 === strpos( $trimmed, $root . '/' ) ) {
					return array(
						'type'       => $type,
						'confidence' => 0.92,
						'signal'     => 'path_prefix',
						'reason'     => 'The path sits under a segment reserved for this page type.',
					);
				}
			}
		}

		return array(
			'type'       => 'custom',
			'confidence' => 0.30,
			'signal'     => 'no_path_signal',
			'reason'     => 'Nothing in the path identified this page, so it is recorded as custom rather than guessed at.',
		);
	}
	/**
	 * Classify a page using its URL and, when available, its analysis.
	 *
	 * The analysis is used to *confirm or overturn* a path signal, not to invent one.
	 * A page whose path says `/blog/` but whose analysis shows a product grid and no
	 * article structure is more likely to be a mislabelled page than a blog, and
	 * saying so is more useful than silently trusting the path.
	 *
	 * @param string               $url            Candidate URL.
	 * @param array<string, mixed> $representation Optional Phase 2 representation.
	 * @return array<string, mixed>
	 */
	public static function classify( $url, array $representation = array() ) {
		$from_path = self::classify_url( $url );
		$out       = array(
			'url'        => (string) $url,
			'type'       => $from_path['type'],
			'confidence' => (float) $from_path['confidence'],
			'signal'     => (string) $from_path['signal'],
			'reason'     => (string) $from_path['reason'],
			'evidence'   => array( $from_path['signal'] ),
			'overridden' => false,
		);

		if ( array() === $representation ) {
			return $out;
		}

		$shape = self::content_shape( $representation );
		if ( 'unknown' === $shape['dominant'] ) {
			return $out;
		}

		$out['shape']    = $shape;
		$out['evidence'] = array_merge( $out['evidence'], array( 'content_shape' ) );

		// Agreement raises confidence, because two independent signals pointing the
		// same way is genuinely more evidence than one.
		if ( $shape['dominant'] === $out['type'] ) {
			$out['confidence'] = min( 0.99, $out['confidence'] + 0.05 );
			$out['reason']    .= ' The page content agrees.';
			return $out;
		}

		// Disagreement is reported rather than resolved. A path is strong evidence
		// and content is strong evidence, and picking one silently is how a
		// misclassification becomes invisible.
		if ( in_array( $out['type'], array( 'custom' ), true ) || $out['confidence'] < 0.6 ) {
			$out['type']       = $shape['dominant'];
			$out['confidence'] = min( 0.85, $shape['confidence'] );
			$out['reason']    .= ' The content shape decided it instead.';
			$out['overridden'] = true;
			return $out;
		}

		$out['conflict'] = (string) $shape['dominant'];
		$out['reason']  .= sprintf(
			' The path suggests %1$s but the content looks like %2$s, so the path was kept and the disagreement recorded.',
			$out['type'],
			$shape['dominant']
		);
		$out['confidence'] = max( 0.4, $out['confidence'] - 0.1 );

		return $out;
	}

	/**
	 * Return what kind of content a page's analysis shows.
	 *
	 * Read from the Phase 2 representation, which already classified its sections
	 * and components. Nothing is re-derived: the representation is the evidence.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @return array<string, mixed>
	 */
	public static function content_shape( array $representation ) {
		$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
		$types    = array();
		$nodes    = 0;

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$type = isset( $section['type'] ) ? strtolower( (string) $section['type'] ) : '';
			if ( '' === $type ) {
				continue;
			}
			$type         = self::normalise_section_type( $type );
			$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
			$nodes++;

			$components = isset( $section['components'] ) && is_array( $section['components'] ) ? $section['components'] : array();
			foreach ( $components as $component ) {
				if ( is_array( $component ) && isset( $component['type'] ) ) {
					$ct          = self::normalise_section_type( strtolower( (string) $component['type'] ) );
					$types[ $ct ] = ( $types[ $ct ] ?? 0 ) + 1;
					$nodes++;
				}
			}
		}

		if ( 0 === $nodes || array() === $types ) {
			return array( 'dominant' => 'unknown', 'confidence' => 0.0, 'types' => array(), 'nodes' => 0 );
		}

		arsort( $types );
		$dominant = (string) array_key_first( $types );
		$top      = (int) $types[ $dominant ];
		$share    = $top / max( 1, array_sum( $types ) );

		return array(
			'dominant'   => $dominant,
			'confidence' => ( $share >= 0.4 ? min( 0.9, 0.4 + ( $share * 0.5 ) ) : 0.35 ),
			'types'      => $types,
			'nodes'      => $nodes,
			'share'      => round( $share, 3 ),
		);
	}

	/**
	 * Return a page type's label.
	 *
	 * @param string $type Page type.
	 * @return string
	 */
	public static function label( $type ) {
		$labels = array(
			'homepage'        => __( 'Home', 'replicaforge' ),
			'about'           => __( 'About', 'replicaforge' ),
			'services'        => __( 'Services', 'replicaforge' ),
			'service_detail'  => __( 'Service', 'replicaforge' ),
			'portfolio'       => __( 'Portfolio', 'replicaforge' ),
			'project_detail'  => __( 'Project', 'replicaforge' ),
			'blog'            => __( 'Blog', 'replicaforge' ),
			'blog_archive'    => __( 'Blog index', 'replicaforge' ),
			'blog_post'       => __( 'Blog post', 'replicaforge' ),
			'product_archive' => __( 'Product listing', 'replicaforge' ),
			'product'         => __( 'Product', 'replicaforge' ),
			'category'        => __( 'Category', 'replicaforge' ),
			'pricing'         => __( 'Pricing', 'replicaforge' ),
			'contact'         => __( 'Contact', 'replicaforge' ),
			'faq'             => __( 'FAQ', 'replicaforge' ),
			'team'            => __( 'Team', 'replicaforge' ),
			'landing'         => __( 'Landing page', 'replicaforge' ),
			'documentation'   => __( 'Documentation', 'replicaforge' ),
			'search'          => __( 'Search', 'replicaforge' ),
			'legal'           => __( 'Legal', 'replicaforge' ),
			'custom'          => __( 'Other', 'replicaforge' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : (string) $type;
	}

	/**
	 * Return whether a page of this type is reconstructed by default.
	 *
	 * Legal pages are excluded because a site's terms and privacy policy are
	 * authored, not reconstructed, and a generated copy is worse than none. `search`
	 * and `category` are excluded because both are query-driven, and a static
	 * reconstruction of either is a page that looks broken.
	 *
	 * @param string $type Page type.
	 * @return bool
	 */
	public static function reconstructs_by_default( $type ) {
		return Site_Limits::is_page_type( $type ) && ! in_array( $type, Site_Limits::EXCLUDED_TYPES, true );
	}

	/**
	 * Return the priority a page of this type is generated at.
	 *
	 * @param string $type Page type.
	 * @return int Higher is earlier.
	 */
	public static function priority_for( $type ) {
		$order = array(
			'homepage'       => 100,
			'contact'        => 90,
			'services'       => 80,
			'about'          => 70,
			'pricing'        => 60,
			'product_archive'=> 55,
			'blog_archive'   => 50,
			'portfolio'      => 45,
			'faq'            => 40,
			'documentation'  => 30,
			'landing'        => 25,
			'team'           => 20,
			'blog_post'      => 15,
			'service_detail' => 10,
			'project_detail' => 10,
			'product'        => 10,
			'category'       => 5,
			'search'         => 5,
			'legal'          => 0,
			'custom'         => 35,
		);

		return isset( $order[ $type ] ) ? (int) $order[ $type ] : 30;
	}

	/**
	 * Reduce a section or component type to a page type where one applies.
	 *
	 * @param string $type Raw type.
	 * @return string
	 */
	private static function normalise_section_type( $type ) {
		$map = array(
			'product_grid'   => 'product_archive',
			'product_list'   => 'product_archive',
			'shop'           => 'product_archive',
			'post_list'      => 'blog_archive',
			'article_list'   => 'blog_archive',
			'blog_list'      => 'blog_archive',
			'article'        => 'blog_post',
			'post'           => 'blog_post',
			'pricing_table'  => 'pricing',
			'plans'          => 'pricing',
			'contact_section'=> 'contact',
			'faq_list'       => 'faq',
			'team_grid'      => 'team',
			'people'         => 'team',
			'cta'            => 'landing',
			'banner'         => 'landing',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : $type;
	}
}
