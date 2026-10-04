<?php
/**
 * Phase 12: navigation reconstruction and URL mapping.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Reconstructs a website's navigation and maps source links onto replica pages.
 *
 * ### The rule that governs this class
 *
 * §22 says do not automatically preserve the source domain in replica links, and
 * §23 says do not invent a replacement for a page that was not reconstructed. Those
 * two together give exactly three outcomes for an internal link, and this class
 * implements all three rather than defaulting to one:
 *
 * 1. **The target was reconstructed** → rewrite to the local page. Certain.
 * 2. **The target was not reconstructed** → leave it pointing at the source, and
 *    *flag it*. This is the honest option: a user reviewing a draft sees one link
 *    leaving the site and knows why.
 * 3. **The target is external** → leave it alone. Only when the evidence shows it
 *    is genuinely external.
 *
 * The tempting fourth option — rewrite an unmapped internal link to `/` or to a
 * search — is the one that produces a site full of links that go somewhere
 * meaningless. It is not offered.
 *
 * ### A link is never invented
 *
 * §21 is explicit. Every label and every URL in the output comes from the source
 * HTML. Where the source has a nav item with no href, it is recorded with an empty
 * target and a note, not with a plausible-looking one.
 */
final class Navigation_Mapper {

	/**
	 * The menu areas a website is reconstructed with.
	 *
	 * @var array<int, string>
	 */
	const AREAS = array( 'primary', 'secondary', 'mobile', 'utility', 'cta', 'social', 'footer' );

	/**
	 * Maximum links recorded per area.
	 *
	 * @var int
	 */
	const MAX_LINKS_PER_AREA = 80;

	/**
	 * Maximum total links.
	 *
	 * @var int
	 */
	const MAX_LINKS = 300;

	/**
	 * Site origin.
	 *
	 * @var string
	 */
	private $origin;

	/**
	 * Page id => source URL.
	 *
	 * @var array<string, string>
	 */
	private $page_urls = array();

	/**
	 * Source URL => local path.
	 *
	 * @var array<string, string>
	 */
	private $local_paths = array();

	/**
	 * Constructor.
	 *
	 * @param string $origin Site origin.
	 */
	public function __construct( $origin = '' ) {
		$this->origin = (string) $origin;
	}

	/**
	 * Tell the mapper which pages exist in the replica.
	 *
	 * @param array<string, array<string, mixed>> $pages Page id => record.
	 * @return void
	 */
	public function register_pages( array $pages ) {
		foreach ( $pages as $page_id => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$source = (string) ( $record['source_url'] ?? '' );
			if ( '' === $source ) {
				continue;
			}
			$this->page_urls[ (string) $page_id ] = $source;

			// The replica path is derived from the source path, not invented: a user
			// who reconstructed /services/ expects /services/ locally, and any other
			// path would break the correspondence the source site itself implies.
			$path = (string) wp_parse_url( $source, PHP_URL_PATH );
			if ( '' === $path ) {
				$path = '/';
			}
			$this->local_paths[ $this->key( $source ) ] = '/' . ltrim( $path, '/' );
		}
	}

	/**
	 * Map a source URL onto its replica path.
	 *
	 * @param string $source_url Source URL.
	 * @return array<string, mixed>
	 */
	public function map( $source_url ) {
		$source = (string) $source_url;
		$parts  = wp_parse_url( $source );

		// The scheme is resolved *before* the host is required, because the schemes
		// that have no host are exactly the ones that still need handling. A
		// `mailto:` has a scheme and a path but no host, so a host-first order rejects
		// every contact link and every `tel:` — the first draft did, and a test
		// asserting a mailto survives the mapping is what found it.
		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';

		if ( in_array( $scheme, array( 'mailto', 'tel', 'sms', 'callto', 'fax' ), true ) ) {
			return array(
				'success' => true,
				'type'    => 'contact',
				'target'  => $source,
				'note'    => __( 'Kept as supplied. A contact link addresses an application rather than a page, so there is nothing to rewrite it to.', 'replicaforge' ),
			);
		}

		if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			// `javascript:`, `data:`, and the rest. A javascript link in a
			// reconstruction is a cross-site-scripting vector, so it is dropped rather
			// than copied — a dropped link is visible, an executed one is not.
			return array(
				'success' => false,
				'type'    => 'unsafe_scheme',
				'target'  => '',
				'note'    => __( 'A link using this scheme was left out, because copying it could carry a script into the replica.', 'replicaforge' ),
			);
		}

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			// No host, but the scheme test above already cleared the schemeless
			// schemes. What is left is a *relative* address, which is what almost every
			// real navigation contains: `href="/about/"`, `href="services/web/"`,
			// `href="../contact/"`. Resolving it against the origin is what makes the
			// source-to-replica mapping work at all — the first draft rejected every one
			// of them as "invalid", which meant a real site's navigation mapped to
			// nothing and every link silently became unlinked.
			$resolved = $this->resolve( $source );
			if ( '' === $resolved ) {
				return array(
					'success' => false,
					'type'    => 'invalid',
					'target'  => '',
					'note'    => __( 'That link could not be read as a web address.', 'replicaforge' ),
				);
			}

			// Re-enter with the absolute form, so a relative link and the absolute link
			// it denotes map identically and dedupe against each other.
			$mapped = $this->map( $resolved );
			$mapped['source'] = $source;
			return $mapped;
		}

		$host     = strtolower( (string) $parts['host'] );
		$is_local = ( $host === strtolower( (string) wp_parse_url( $this->origin, PHP_URL_HOST ) ) )
			|| ( $host === $this->strip_www( strtolower( (string) wp_parse_url( $this->origin, PHP_URL_HOST ) ) ) );

		if ( ! $is_local ) {
			// §22: external links stay external. Opening a reconstructed page and
			// landing on somebody else's site is the expected behaviour when the
			// source linked out.
			return array(
				'success' => true,
				'type'    => 'external',
				'target'  => $source,
				'note'    => __( 'This linked to another website in the source, so it still points there.', 'replicaforge' ),
			);
		}

		$key    = $this->key( $source );
		$target = $this->local_paths[ $key ] ?? '';

		if ( '' !== $target ) {
			return array(
				'success'  => true,
				'type'     => 'replica_page',
				'target'   => $target,
				'source'   => $source,
				'note'     => __( 'Points at the reconstructed page.', 'replicaforge' ),
			);
		}

		// Not reconstructed. Preserved and flagged — never replaced with something
		// plausible.
		return array(
			'success'  => true,
			'type'     => 'unmapped',
			'target'   => $source,
			'source'   => $source,
			'review'   => true,
			'note'     => __( 'This page was not reconstructed, so the link still points at the source address and has been marked for review.', 'replicaforge' ),
		);
	}

	/**
	 * Build the navigation structure from the fetched pages.
	 *
	 * @param array<string, array<string, mixed>> $pages Page id => representation.
	 * @return array<string, mixed>
	 */
	public function build( array $pages ) {
		$areas  = array();
		foreach ( self::AREAS as $area ) {
			$areas[ $area ] = array();
		}

		$links       = array();
		$unmapped    = array();
		$external    = array();
		$total       = 0;

		foreach ( $pages as $page_id => $representation ) {
			if ( ! is_array( $representation ) ) {
				continue;
			}

			foreach ( $this->links_of( $representation ) as $link ) {
				if ( $total >= self::MAX_LINKS ) {
					break 2;
				}

				$mapped   = $this->map( (string) $link['href'] );
				$area     = Site_Limits::is_shared_role( $link['area'] ) || in_array( $link['area'], self::AREAS, true ) ? $link['area'] : 'primary';

				// A footer link is a footer link whatever a header also said, and a
				// label is never dropped because the same href appears twice.
				$entry = array(
					'label'     => (string) $link['label'],
					'href'      => (string) $mapped['target'],
					'source'    => (string) $link['href'],
					'area'      => $area,
					'type'      => (string) $mapped['type'],
					'from_page' => (string) $page_id,
					'review'    => ! empty( $mapped['review'] ),
				);

				if ( $entry['href'] === '' ) {
					// A nav item with no destination. Recorded so the user can see
					// something was there, with no fabricated target.
					$entry['type'] = 'unlinked';
					$entry['note'] = __( 'This item had no address in the source, so none was invented.', 'replicaforge' );
					$areas['utility'][] = $entry;
					$total++;
					continue;
				}

				if ( count( $areas[ $area ] ) < self::MAX_LINKS_PER_AREA ) {
					$duplicate = false;
					foreach ( $areas[ $area ] as $existing ) {
						if ( $existing['label'] === $entry['label'] && $existing['href'] === $entry['href'] ) {
							$duplicate = true;
							break;
						}
					}
					if ( ! $duplicate ) {
						$areas[ $area ][] = $entry;
					}
				}

				$total++;

				if ( 'unmapped' === $entry['type'] ) {
					$unmapped[ $entry['source'] ] = $entry;
				} elseif ( 'external' === $entry['type'] ) {
					$external[ $entry['source'] ] = $entry;
				} elseif ( in_array( $entry['type'], array( 'replica_page', 'contact' ), true ) ) {
					$target_page = $this->page_for( $entry['source'] );
					$links[]     = array(
						'from_page' => (string) $page_id,
						'to_page'   => (string) $target_page,
						'label'     => $entry['label'],
						'type'      => $entry['type'],
					);
				}
			}
		}

		foreach ( $areas as $area => $items ) {
			$areas[ $area ] = array_values( $items );
		}

		return array(
			'schema_version' => Site_Limits::SCHEMA_VERSION,
			'areas'          => $areas,
			'links'          => $links,
			'url_map'        => $this->url_map(),
			'unmapped'       => array_values( $unmapped ),
			'external'       => array_values( $external ),
			'counts'         => array(
				'total'    => $total,
				'internal' => count( $links ),
				'unmapped' => count( $unmapped ),
				'external' => count( $external ),
			),
			'notes'          => array(
				'labels_preserved' => __( 'Every link label comes from the source. No label was invented.', 'replicaforge' ),
				'no_invention'     => __( 'A link to a page that was not reconstructed still points at the source and is marked for review.', 'replicaforge' ),
			),
		);
	}

	/**
	 * Return the source-to-replica URL map.
	 *
	 * @return array<string, string>
	 */
	public function url_map() {
		return $this->local_paths;
	}

	/**
	 * Resolve a relative address against the site origin.
	 *
	 * Returns an absolute URL, or an empty string when the value is not a relative
	 * address. A value that does not look like a path at all is not silently
	 * resolved into something plausible — that would be inventing a link, which is
	 * the one thing this class must never do.
	 *
	 * `..` segments are collapsed rather than followed, because a link climbing out of
	 * the site is not a page the replica should reach, and resolving it would mean
	 * fetching a URL the source site's own navigation deliberately left the site to
	 * reach. A `../` that would leave the origin resolves to empty and is reported as
	 * invalid, which is honest.
	 *
	 * @param string $href Relative address.
	 * @return string
	 */
	private function resolve( $href ) {
		$href = trim( (string) $href );
		if ( '' === $href || '' === $this->origin ) {
			return '';
		}
		// Already absolute, or a scheme we handle elsewhere.
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $href ) ) {
			return '';
		}
		// A fragment or a query on its own addresses the current page.
		if ( 0 === strpos( $href, '#' ) || 0 === strpos( $href, '?' ) ) {
			return $this->origin . '/';
		}

		$parts = explode( '/', ltrim( $href, '/' ) );
		$out   = array();
		foreach ( $parts as $part ) {
			if ( '.' === $part || '' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				if ( array() === $out ) {
					// Climbing above the origin. Refused rather than clamped, because a
					// clamped link points somewhere the source never pointed.
					return '';
				}
				array_pop( $out );
				continue;
			}
			$out[] = $part;
		}

		$path    = implode( '/', $out );
		$query   = '';
		$qpos    = strpos( $path, '?' );
		if ( false !== $qpos ) {
			$query = substr( $path, $qpos );
			$path  = substr( $path, 0, $qpos );
		}

		// Every segment must be something a path segment can actually contain.
		// Without this, `'not a url'` resolves to `https://example.com/not a url` —
		// a link invented from a value that was not a link at all, which is the one
		// thing this class must never do. Rejecting the whole address is honest;
		// guessing at a plausible path is not.
		foreach ( $out as $part ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9._~%!$&\'()*+,;=:@-]+$/', (string) $part ) ) {
				return '';
			}
		}

		$origin = rtrim( $this->origin, '/' );

		return $origin . ( '' !== $path ? '/' . $path : '/' ) . $query;
	}

	/**
	 * Return the page a source URL belongs to.
	 *
	 * @param string $source_url Source URL.
	 * @return string
	 */
	private function page_for( $source_url ) {
		$key = $this->key( $source_url );
		foreach ( $this->page_urls as $page_id => $url ) {
			if ( $this->key( $url ) === $key ) {
				return (string) $page_id;
			}
		}
		return '';
	}

	/**
	 * Extract the navigation links from a representation.
	 *
	 * @param array<string, mixed> $representation Page representation.
	 * @return array<int, array<string, mixed>>
	 */
	private function links_of( array $representation ) {
		$out = array();
		$seen = 0;

		$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
		foreach ( $sections as $index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			if ( $seen >= Site_Limits::MAX_LINKS_PER_PAGE ) {
				break;
			}

			$type = strtolower( (string) ( $section['type'] ?? '' ) );
			$area = $this->area_of( $type, (int) ( $section['order'] ?? $index ), count( $sections ) );

			// A navigation section's own items.
			foreach ( $this->items_in( $section ) as $item ) {
				if ( $seen >= Site_Limits::MAX_LINKS_PER_PAGE ) {
					break 2;
				}
				$out[]  = $item;
				$seen++;
			}

			// And the links inside its components, which is where most themes put
			// them: the nav is a component, not the section.
			$components = isset( $section['components'] ) && is_array( $section['components'] ) ? $section['components'] : array();
			foreach ( $components as $component ) {
				if ( ! is_array( $component ) ) {
					continue;
				}
				if ( $seen >= Site_Limits::MAX_LINKS_PER_PAGE ) {
					break 2;
				}
				foreach ( $this->items_in( $component ) as $item ) {
					$item['area'] = $area;
					$out[]        = $item;
					$seen++;
				}
			}
		}

		return $out;
	}

	/**
	 * Return the link items in a node.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<int, array<string, mixed>>
	 */
	private function items_in( array $node ) {
		$out = array();

		$items = isset( $node['links'] ) && is_array( $node['links'] ) ? $node['links'] : array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['href'] ) ) {
				$out[] = array(
					'label' => (string) ( $item['label'] ?? $item['text'] ?? '' ),
					'href'  => (string) $item['href'],
					'area'  => (string) ( $item['area'] ?? 'primary' ),
				);
			}
		}

		// A node may itself be a link.
		if ( ! empty( $node['href'] ) ) {
			$out[] = array(
				'label' => (string) ( $node['label'] ?? $node['title'] ?? '' ),
				'href'  => (string) $node['href'],
				'area'  => 'primary',
			);
		}

		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
		foreach ( $children as $child ) {
			if ( is_array( $child ) ) {
				foreach ( $this->items_in( $child ) as $item ) {
					$out[] = $item;
				}
			}
		}

		return $out;
	}

	/**
	 * Return which navigation area a section belongs to.
	 *
	 * @param string $type      Section type.
	 * @param int    $order     Zero-based order.
	 * @param int    $total     Section count.
	 * @return string
	 */
	private function area_of( $type, $order, $total ) {
		if ( in_array( $type, array( 'header', 'masthead', 'topbar', 'nav', 'navigation', 'menu' ), true ) ) {
			return 'primary';
		}
		if ( in_array( $type, array( 'footer', 'site_footer', 'colophon' ), true ) ) {
			return 'footer';
		}
		if ( in_array( $type, array( 'cta', 'banner_cta', 'call_to_action' ), true ) ) {
			return 'cta';
		}
		if ( in_array( $type, array( 'social', 'social_links' ), true ) ) {
			return 'social';
		}
		if ( 0 === $order && $total >= 2 ) {
			return 'primary';
		}
		return 'secondary';
	}

	/**
	 * Return the comparison key for a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function key( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			return strtolower( trim( (string) $url ) );
		}
		$path = (string) ( $parts['path'] ?? '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		// The trailing slash is dropped so `/about` and `/about/` are one key. The
		// page store rtrims for the same reason, and if the two disagreed then a
		// link written either way would fail to find its own page.
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		return $this->strip_www( strtolower( (string) ( $parts['host'] ?? '' ) ) ) . $path;
	}

	/**
	 * Remove a leading `www.`.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private function strip_www( $host ) {
		return (string) preg_replace( '/^www\d?\./', '', (string) $host );
	}
}
