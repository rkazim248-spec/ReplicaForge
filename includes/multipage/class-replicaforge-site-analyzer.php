<?php
/**
 * Phase 12: the website analyzer.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Composes the individual detectors into one website-level specification.
 *
 * ### This class is orchestration and nothing else
 *
 * Every judgement — what a page is, what repeats across pages, where two pages
 * disagree, whether a token may be written — lives in its own class and is tested
 * there. What is here is the order of operations, the evidence wiring, and the
 * honesty of the output when a stage produced nothing.
 *
 * The order matters and is not arbitrary: page classification before design-system
 * extraction, because the design system is built from the pages *you selected* and
 * a page you excluded should not contribute tokens; navigation after the pages are
 * known, because a link can only be mapped to a page that exists; and the
 * specification validated at the end, because that is the gate.
 *
 * ### A stage that produced nothing says so
 *
 * A website with one page produces no shared components, because
 * {@see Site_Limits::MIN_SHARED_PAGES} is two. The output says
 * `components.build_stages` with an explicit reason rather than an empty array,
 * because an empty array is indistinguishable from a bug and a user cannot tell
 * the difference between "nothing is shared" and "detection failed".
 */
final class Site_Analyzer {

	/**
	 * Page classifier.
	 *
	 * @var Page_Classifier
	 */
	private $classifier;

	/**
	 * Design system extractor.
	 *
	 * @var Site_Design_System
	 */
	private $design;

	/**
	 * Shared component detector.
	 *
	 * @var Shared_Component_Detector
	 */
	private $components;

	/**
	 * Component registry.
	 *
	 * @var Component_Registry
	 */
	private $registry;

	/**
	 * Asset registry.
	 *
	 * @var Asset_Registry
	 */
	private $assets;

	/**
	 * Compatibility layer.
	 *
	 * @var Site_Compatibility
	 */
	private $compatibility;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $services Optional service overrides.
	 */
	public function __construct( array $services = array() ) {
		$this->logger = isset( $services['logger'] ) && $services['logger'] instanceof Logger ? $services['logger'] : new Logger();

		$this->classifier    = isset( $services['classifier'] ) ? $services['classifier'] : null;
		$this->design        = isset( $services['design'] ) && $services['design'] instanceof Site_Design_System ? $services['design'] : new Site_Design_System();
		$this->components    = isset( $services['components'] ) && $services['components'] instanceof Shared_Component_Detector ? $services['components'] : new Shared_Component_Detector( $this->logger );
		$this->registry      = isset( $services['registry'] ) && $services['registry'] instanceof Component_Registry ? $services['registry'] : new Component_Registry( $this->logger );
		$this->assets        = isset( $services['assets'] ) && $services['assets'] instanceof Asset_Registry ? $services['assets'] : new Asset_Registry( $this->logger );
		$this->compatibility= isset( $services['compatibility'] ) && $services['compatibility'] instanceof Site_Compatibility ? $services['compatibility'] : new Site_Compatibility( null, $this->logger );
	}

	/**
	 * Build the website specification from analyzed pages.
	 *
	 * @param string                            $project_id    Project identifier.
	 * @param array<string, array<string, mixed>> $pages       Page id => representation.
	 * @param array<string, mixed>              $context      Project facts.
	 * @return array<string, mixed>
	 */
	public function analyze( $project_id, array $pages, array $context = array() ) {
		$started   = microtime( true );
		$warnings  = array();
		$stages    = array();

		$classifications = array();
		$representations = array();
		$page_records    = array();

		foreach ( $pages as $page_id => $representation ) {
			if ( ! is_array( $representation ) ) {
				continue;
			}
			$page_id = (string) $page_id;

			$url  = (string) ( $representation['page']['url'] ?? ( $context['pages'][ $page_id ]['source_url'] ?? '' ) );
			$type = Page_Classifier::classify( $url, $representation );

			$record = array(
				'page_id'          => $page_id,
				'source_url'       => $url,
				'canonical_url'    => (string) ( $representation['page']['final_url'] ?? $url ),
				'title'            => (string) ( $representation['page']['title'] ?? '' ),
				'type'             => (string) $type['type'],
				'status'           => (string) ( $context['pages'][ $page_id ]['status'] ?? 'analyzed' ),
				'confidence'       => (float) $type['confidence'],
				'classification'   => $type,
				'source_hash'      => (string) ( $context['pages'][ $page_id ]['source_hash'] ?? '' ),
				'analysis_version' => (string) ( $representation['schema_version'] ?? '' ),
			);

			$page_records[]    = $record;
			$classifications[ $page_id ] = $type;
			$representations[ $page_id ] = $representation;
		}

		$stages['classification'] = array(
			'ran'      => true,
			'pages'    => count( $page_records ),
			'by_type'  => $this->tally( array_map( static function ( $p ) { return $p['type']; }, $page_records ) ),
			'uncertain'=> count( array_filter( $classifications, static function ( $c ) { return (float) $c['confidence'] < 0.6; } ) ),
			'conflicted' => count( array_filter( $classifications, static function ( $c ) { return ! empty( $c['conflict'] ); } ) ),
		);

		// The design system is built from the representations, keyed by page, so a
		// page's own tokens are available for agreement.
		$design = $this->design->build( $representations );
		$design['hash'] = $this->design_hash( $design );
		$stages['design_system'] = array(
			'ran'       => (bool) $design['built'],
			'pages'     => (int) $design['pages'],
			'tokens'    => (int) $design['total'],
			'roles'     => count( (array) $design['roles'] ),
			'conflicts' => count( (array) $design['conflicts'] ),
			'reason'    => (string) ( $design['reason'] ?? '' ),
		);

		if ( ! empty( $design['conflicts'] ) ) {
			$warnings[] = __( 'Some design values differ between pages. They have been reported as conflicts rather than forced into a global token.', 'replicaforge' );
		}

		// Shared components and templates. A single-page website genuinely has
		// neither, and the reason is recorded so the empty result is not ambiguous.
		$shared = $this->components->detect( $representations );
		$this->registry->load( $project_id );
		$this->registry->put_shared( $project_id, (array) $shared['shared'] );
		$stages['shared_components'] = array(
			'ran'        => true,
			'shared'     => (int) $shared['count'],
			'roles'      => array_filter( (array) $shared['by_role'] ),
			'single_use' => (int) $shared['single_use'],
			'reason'     => ( count( $pages ) < Site_Limits::MIN_SHARED_PAGES )
				? __( 'Only one page was analyzed. A structure is only treated as shared once it appears on two or more pages.', 'replicaforge' )
				: (string) $shared['note'],
		);

		// Templates are detected against page id => record, where the record carries
		// both the classification and the representation, because a template is a
		// *structure* grouped by *type* and neither alone is enough.
		$template_input = array();
		foreach ( $page_records as $page ) {
			$page_id = (string) ( $page['page_id'] ?? '' );
			if ( '' === $page_id || ! isset( $representations[ $page_id ] ) ) {
				continue;
			}
			$template_input[ $page_id ] = array(
				'type'           => (string) $page['type'],
				'representation' => $representations[ $page_id ],
			);
		}
		$templates = $this->registry->detect_templates( $template_input );
		$this->registry->put_templates( $project_id, $templates );
		$stages['templates'] = array(
			'ran'       => true,
			'count'     => (int) $templates['count'],
			'reason'    => (string) $templates['note'],
		);

		$navigation = $this->navigation( $representations, $page_records );
		$stages['navigation'] = array(
			'ran'     => true,
			'links'   => (int) $navigation['counts']['internal'],
			'external'=> (int) $navigation['counts']['external'],
			'unmapped'=> (int) $navigation['counts']['unmapped'],
		);

		if ( $navigation['counts']['unmapped'] > 0 ) {
			$warnings[] = __( 'Some links point at pages that were not reconstructed. They have been left pointing at the source and marked for review.', 'replicaforge' );
		}

		$assets = $this->assets->build( $representations );
		$stages['assets'] = array(
			'ran'          => true,
			'count'        => (int) $assets['count'],
			'shared'       => (int) $assets['shared'],
			'rejected'     => $assets['rejected'],
			'default_mode' => (string) $assets['default_mode'],
		);

		$specification = array(
			'schema_version'      => Site_Limits::SCHEMA_VERSION,
			'website'             => array(
				'name'      => (string) ( $context['name'] ?? $this->website_name( $context ) ),
				'type'      => $this->website_type( $page_records ),
				'source_url'=> (string) ( $context['source_url'] ?? $this->origin_of( $representations ) ),
				'origin'    => (string) ( $context['origin'] ?? '' ),
			),
			'pages'                => $page_records,
			'global_design_system' => $design,
			'design_hash'          => (string) $design['hash'],
			'shared_components'    => (array) $shared['shared'],
			'templates'            => (array) $this->registry->templates(),
			'navigation'           => $navigation,
			'assets'               => $assets,
			'relationships'        => array(),
			'compatibility'        => $this->compatibility->report(),
			'responsive_strategy'  => (array) $design['responsive'],
			'content_mapping'      => $this->content_mapping( $page_records, (array) $shared['shared'] ),
			'confidence'           => $this->confidence( $design, $shared, $page_records, $classifications ),
			'warnings'             => $warnings,
			'stages'               => $stages,
			'built_at'             => time(),
			'duration'             => round( microtime( true ) - $started, 3 ),
		);

		$representation_object = new Site_Representation( $specification );
		$verdict               = $representation_object->validate();
		$specification['relationships'] = $representation_object->relationships();
		$specification['validation']     = $verdict;

		foreach ( $verdict['warnings'] as $warning ) {
			$specification['warnings'][] = $warning;
		}
		$specification['warnings'] = array_values( array_unique( $specification['warnings'] ) );

		return $specification;
	}

	/**
	 * Return the map the website view draws.
	 *
	 * Built from the recorded relationships, with no invented edges. A node is a
	 * page that exists; an edge is a shared structure two pages have in common.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @return array<string, mixed>
	 */
	public function map( array $specification ) {
		$nodes = array();
		foreach ( (array) ( $specification['pages'] ?? array() ) as $page ) {
			if ( ! is_array( $page ) || empty( $page['page_id'] ) ) {
				continue;
			}
			$nodes[] = array(
				'id'    => (string) $page['page_id'],
				'label' => (string) ( $page['title'] ?? $page['source_url'] ?? $page['page_id'] ),
				'type'  => (string) ( $page['type'] ?? 'custom' ),
				'depth' => (int) ( $page['depth'] ?? 0 ),
				'via'   => (string) ( $page['via'] ?? 'link' ),
			);
		}

		$edges = array();
		foreach ( (array) ( $specification['relationships'] ?? array() ) as $edge ) {
			if ( ! is_array( $edge ) ) {
				continue;
			}
			$edges[] = array(
				'from' => (string) ( $edge['from'] ?? '' ),
				'to'   => (string) ( $edge['to'] ?? '' ),
				'via'  => (string) ( $edge['via'] ?? '' ),
				'label'=> (string) ( $edge['label'] ?? '' ),
			);
		}

		$roots = array();
		foreach ( $nodes as $node ) {
			$has_parent = false;
			foreach ( $edges as $edge ) {
				if ( $edge['to'] === $node['id'] ) {
					$has_parent = true;
					break;
				}
			}
			if ( ! $has_parent ) {
				$roots[] = $node['id'];
			}
		}

		return array(
			'root'   => (string) ( $specification['website']['source_url'] ?? '' ),
			'nodes'  => $nodes,
			'edges'  => $edges,
			'roots'  => $roots,
			'counts' => array( 'nodes' => count( $nodes ), 'edges' => count( $edges ) ),
			'note'   => __( 'Every line is a relationship ReplicaForge actually found: a shared header, a shared template, or a link. No relationship is assumed.', 'replicaforge' ),
		);
	}

	/**
	 * Return the design system view a user reviews.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @return array<string, mixed>
	 */
	public function design_view( array $specification ) {
		$design = isset( $specification['global_design_system'] ) && is_array( $specification['global_design_system'] ) ? $specification['global_design_system'] : array();
		$groups = array();

		foreach ( (array) ( $design['families'] ?? array() ) as $family => $tokens ) {
			$entries = array();
			foreach ( (array) $tokens as $name => $token ) {
				if ( ! is_array( $token ) || empty( $token['value'] ) ) {
					continue;
				}
				$value = (string) $token['value'];
				$key   = $family . '|' . $value;
				$share = (float) ( $design['agreement']['values'][ $key ] ?? 0.0 );

				$entries[] = array(
					'name'         => (string) $name,
					'value'        => $value,
					'source'       => (string) ( $token['source'] ?? 'observation' ),
					'usage_count'  => (int) ( $token['occurrences'] ?? 0 ),
					'confidence'   => isset( $token['confidence'] ) ? (float) $token['confidence'] : 0.0,
					'agreement'    => $share,
					'scope'        => ( $share >= Site_Limits::GLOBAL_AGREEMENT ? 'global' : ( $share > 0 ? 'component' : 'page' ) ),
					'overridden'   => false,
					'disputed'     => ( $share < Site_Limits::GLOBAL_AGREEMENT ),
				);
			}
			usort(
				$entries,
				static function ( $left, $right ) {
					if ( $left['usage_count'] === $right['usage_count'] ) {
						return strcmp( $left['name'], $right['name'] );
					}
					return ( $left['usage_count'] > $right['usage_count'] ) ? -1 : 1;
				}
			);
			$groups[ (string) $family ] = array_slice( $entries, 0, 40 );
		}

		return array(
			'built'     => ! empty( $design['built'] ),
			'pages'     => (int) ( $design['pages'] ?? 0 ),
			'groups'    => $groups,
			'families'  => array_keys( $groups ),
			'roles'     => (array) ( $design['roles'] ?? array() ),
			'conflicts' => (array) ( $design['conflicts'] ?? array() ),
			'responsive'=> (array) ( $design['responsive'] ?? array() ),
			'threshold' => Site_Limits::GLOBAL_AGREEMENT,
			'note'      => __( 'A value shown as global is used on enough pages to be a website fact. One used on fewer pages is shown with the pages that use it, because a design system that hides disagreement is worse than none.', 'replicaforge' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Build the navigation map.
	 *
	 * @param array<string, array<string, mixed>> $representations Representations.
	 * @param array<int, array<string, mixed>>     $page_records    Page records.
	 * @return array<string, mixed>
	 */
	private function navigation( array $representations, array $page_records ) {
		$origin = '';
		foreach ( $page_records as $page ) {
			if ( ! empty( $page['source_url'] ) ) {
				$parts = wp_parse_url( (string) $page['source_url'] );
				if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
					$origin = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
					break;
				}
			}
		}

		$mapper = new Navigation_Mapper( $origin );
		$mapper->register_pages( $this->pages_by_id( $page_records ) );

		return $mapper->build( $representations );
	}

	/**
	 * Return the content/structure separation §66 requires.
	 *
	 * Structure is what ReplicaForge builds. Content is what came from the source
	 * and belongs to the user. They are listed separately so a user can see which
	 * is which, and so a correction to a structure never rewrites content.
	 *
	 * @param array<int, array<string, mixed>> $page_records Page records.
	 * @return array<string, mixed>
	 */
	private function content_mapping( array $page_records, array $shared = array() ) {
		$structure = array();
		$content   = array();

		foreach ( $page_records as $page ) {
			$page_id = (string) ( $page['page_id'] ?? '' );
			if ( '' === $page_id ) {
				continue;
			}
			$structure[ $page_id ] = array(
				'sections'     => __( 'Layout, sections, components and styles', 'replicaforge' ),
				'from_source'  => true,
				'shared_with'  => array(),
			);
			$content[ $page_id ] = array(
				'text'         => __( 'Headings, body text, links and images, mapped from the source', 'replicaforge' ),
				'rights'       => __( 'A public page is not permission to reuse. You are responsible for the rights to the text and images you keep.', 'replicaforge' ),
				'from_source'  => true,
			);
		}

		foreach ( $shared as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$id = (string) ( $component['component_id'] ?? '' );
			foreach ( (array) ( $component['pages'] ?? array() ) as $page_id ) {
				if ( isset( $structure[ (string) $page_id ] ) ) {
					$structure[ (string) $page_id ]['shared_with'][] = $id;
				}
			}
		}

		return array(
			'structure'   => $structure,
			'content'     => $content,
			'separation'  => __( 'Structure and content are kept apart. A shared structure is built once and never carries the text or images of a particular page.', 'replicaforge' ),
		);
	}

	/**
	 * Return the specification's confidence.
	 *
	 * @param array<string, mixed>                    $design           Design system.
	 * @param array<string, mixed>                    $shared           Shared components.
	 * @param array<int, array<string, mixed>>         $page_records     Page records.
	 * @param array<string, array<string, mixed>>      $classifications  Classifications.
	 * @return array<string, mixed>
	 */
	private function confidence( array $design, array $shared, array $page_records, array $classifications ) {
		$low = 0;
		foreach ( $classifications as $classification ) {
			if ( (float) $classification['confidence'] < 0.6 ) {
				$low++;
			}
		}

		$page_count = max( 1, count( $page_records ) );
		$conflicts  = count( (array) ( $design['conflicts'] ?? array() ) );

		// Deliberately arithmetic and stated rather than a single flattering
		// number. A user deciding whether to trust a 40-page reconstruction needs to
		// know *which* part is uncertain, and an average hides that.
		return array(
			'pages'            => $page_count,
			'page_classified'  => round( 1.0 - ( $low / $page_count ), 2 ),
			'design_built'     => (bool) ( $design['built'] ?? false ),
			'design_conflicts' => $conflicts,
			'shared'           => (int) ( $shared['count'] ?? 0 ),
			'overall'          => round(
				max( 0.0, min( 1.0,
					( 0.4 * ( 1.0 - ( $low / $page_count ) ) )
					+ ( 0.3 * ( ! empty( $design['built'] ) ? 1.0 : 0.0 ) )
					- ( 0.1 * min( 1.0, ( $conflicts / $page_count ) ) )
				) ),
				2
			),
			'note'             => __( 'This reflects how much of the website ReplicaForge could identify with evidence. A low number means parts of it are guesses you should review.', 'replicaforge' ),
		);
	}

	/**
	 * Return the design system's hash.
	 *
	 * @param array<string, mixed> $design Design system.
	 * @return string
	 */
	private function design_hash( array $design ) {
		$material = array(
			'roles'  => $design['roles'] ?? array(),
			'counts' => $design['counts'] ?? array(),
		);
		return substr( hash( 'sha256', (string) wp_json_encode( $material ) ), 0, 16 );
	}

	/**
	 * Return a website's name, derived rather than invented.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return string
	 */
	private function website_name( array $context ) {
		$host = (string) ( $context['host'] ?? '' );
		if ( '' === $host && ! empty( $context['source_url'] ) ) {
			$host = (string) wp_parse_url( (string) $context['source_url'], PHP_URL_HOST );
		}
		if ( '' === $host ) {
			return '';
		}
		$host = (string) preg_replace( '/^www\d?\./', '', $host );
		return ucfirst( $host );
	}

	/**
	 * Return the site's origin.
	 *
	 * @param array<string, array<string, mixed>> $representations Representations.
	 * @return string
	 */
	private function origin_of( array $representations ) {
		foreach ( $representations as $representation ) {
			$url = (string) ( $representation['page']['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$parts = wp_parse_url( $url );
			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				return strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) . '://' . strtolower( (string) $parts['host'] );
			}
		}
		return '';
	}

	/**
	 * Return the page types found, as a website type.
	 *
	 * @param array<int, array<string, mixed>> $page_records Page records.
	 * @return string
	 */
	private function website_type( array $page_records ) {
		$types = $this->tally( array_map( static function ( $p ) { return (string) ( $p['type'] ?? '' ); }, $page_records ) );

		foreach ( array( 'product', 'product_archive' ) as $ecommerce ) {
			if ( isset( $types[ $ecommerce ] ) ) {
				return 'ecommerce';
			}
		}
		foreach ( array( 'blog_post', 'blog_archive' ) as $blog ) {
			if ( isset( $types[ $blog ] ) ) {
				return 'editorial';
			}
		}
		if ( isset( $types['documentation'] ) ) {
			return 'documentation';
		}
		if ( isset( $types['project_detail'] ) || isset( $types['portfolio'] ) ) {
			return 'portfolio';
		}
		return 'business';
	}

	/**
	 * Return pages keyed by id.
	 *
	 * @param array<int, array<string, mixed>> $page_records Page records.
	 * @return array<string, array<string, mixed>>
	 */
	private function pages_by_id( array $page_records ) {
		$out = array();
		foreach ( $page_records as $page ) {
			if ( is_array( $page ) && ! empty( $page['page_id'] ) ) {
				$out[ (string) $page['page_id'] ] = $page;
			}
		}
		return $out;
	}

	/**
	 * Return a tally.
	 *
	 * @param array<int, string> $values Values.
	 * @return array<string, int>
	 */
	private function tally( array $values ) {
		$out = array();
		foreach ( $values as $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			$out[ $value ] = ( $out[ $value ] ?? 0 ) + 1;
		}
		arsort( $out );
		return $out;
	}
}
