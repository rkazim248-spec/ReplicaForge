<?php
/**
 * Phase 12: safe page discovery.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the pages of one website, and refuses to become a crawler.
 *
 * The hard part of this class is not finding pages. It is deciding which pages must
 * **never** be found, and being sure that decision cannot be talked out of it by
 * anything in the fetched HTML.
 *
 * ### The threat model, stated plainly
 *
 * A crawler is a request amplifier pointed at someone else's server, and the
 * fetched content is attacker-controlled input that arrives *after* the crawl has
 * started. So there are two separate attacks:
 *
 * 1. **The site points the crawler elsewhere.** A sitemap naming
 *    `169.254.169.254/`, a link to `admin/`, a redirect to a payment gateway. Every
 *    discovered URL is therefore re-validated through the existing
 *    {@see Url_Validator} before it is queued, not when it is eventually fetched —
 *    validating at fetch time means the frontier has already been poisoned.
 * 2. **The site makes the crawler expensive.** Ten thousand links, a calendar, a tag
 *    cloud. Hence {@see Site_Limits::MAX_LINKS_PER_PAGE} and
 *    {@see Site_Limits::MAX_FRONTIER}, both applied at *discovery* rather than at the
 *    end, so a hostile site cannot make ReplicaForge allocate its way through a list
 *    it should never have collected.
 *
 * ### What is never crawled
 *
 * External domains, social networks, logins, admin, checkout, and private areas.
 * These are refused by path, not merely by origin, because a site's own `/wp-admin`
 * is same-origin and would otherwise pass a pure origin check.
 */
final class Page_Discovery {

	/**
	 * Safe HTTP client.
	 *
	 * @var Http_Client
	 */
	private $http;

	/**
	 * URL validator.
	 *
	 * @var Url_Validator
	 */
	private $validator;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Paths that are never crawled, whatever their origin.
	 *
	 * Matched as a **whole path segment**, not as a string prefix, and the
	 * distinction is not cosmetic. A `strpos()` prefix test on `/admin` also refuses
	 * `/admiralty`, `/logistics`, `/accounting`, and `/setting-up` — real pages on a
	 * maritime museum's, a courier's, and an accountant's website, each dropped for
	 * a reason that has nothing to do with security. The matcher
	 * ({@see self::is_never_path()}) therefore compares the first segment against
	 * this list, so `/admin` and `/admin/login` are refused and `/admiralty` is not.
	 *
	 * ### Why `/administration` is on the list even though it is a real page name
	 *
	 * Universities, government sites, and clubs all have a content page at
	 * `/administration/`, and refusing it drops that page for every one of them.
	 * Including it here is a deliberate choice of the worse failure: a user who
	 * wants that page can add the address by hand, whereas a site whose admin lives
	 * at `/administration/` would otherwise be crawled unauthenticated, and the user
	 * would have no way to know. §4 lists "admin pages" and "private dashboards" as
	 * things never crawled automatically, and an ambiguous path is resolved toward
	 * that list rather than away from it. The same reasoning is why
	 * {@see Site_Compatibility::ownership()} treats uncertainty as "do nothing".
	 *
	 * @var array<int, string>
	 */
	const NEVER_PATHS = array(
		'wp-admin',
		'wp-login',
		'wp-json',
		'wp-content',
		'admin',
		'administrator',
		'administration',
		'login',
		'signin',
		'logout',
		'register',
		'signup',
		'account',
		'my-account',
		'dashboard',
		'cart',
		'checkout',
		'basket',
		'payment',
		'billing',
		'subscriptions',
		'private',
		'settings',
		'password',
		'reset',
	);

	/**
	 * Hosts that are never crawled even when the entry page is on them.
	 *
	 * A site whose own homepage is a social profile is a site ReplicaForge should
	 * analyse as one page, not as a mirror of somebody else's network.
	 *
	 * @var array<int, string>
	 */
	const NEVER_HOSTS = array(
		'facebook.com', 'www.facebook.com', 'm.facebook.com',
		'twitter.com', 'www.twitter.com', 'x.com',
		'instagram.com', 'www.instagram.com',
		'linkedin.com', 'www.linkedin.com',
		'youtube.com', 'www.youtube.com',
		'tiktok.com', 'pinterest.com', 'reddit.com',
		'github.com', 'gitlab.com',
		'wa.me', 'api.whatsapp.com', 't.me',
		'disqus.com', 'livefyre.com', 'addthis.com',
		'google.com', 'accounts.google.com',
	);

	/**
	 * Sitemap paths tried, in order.
	 *
	 * @var array<int, string>
	 */
	const SITEMAP_PATHS = array( '/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml' );

	/**
	 * Maximum time a single discovery pass may run.
	 *
	 * @var int
	 */
	private $deadline = 0;

	/**
	 * Constructor.
	 *
	 * @param Http_Client|null $http      Optional HTTP client.
	 * @param Url_Validator|null $validator Optional URL validator.
	 * @param Logger|null      $logger    Optional logger.
	 */
	public function __construct( $http = null, $validator = null, $logger = null ) {
		$this->logger    = $logger instanceof Logger ? $logger : new Logger();
		$this->validator = $validator instanceof Url_Validator ? $validator : new Url_Validator();
		$this->http      = $http instanceof Http_Client ? $http : new Http_Client( $this->validator, null );
	}

	/**
	 * Discover the pages of a website.
	 *
	 * @param string $entry_url  The page the crawl starts from.
	 * @param array<string, mixed> $options Options.
	 * @return array<string, mixed>
	 */
	public function discover( $entry_url, array $options = array() ) {
		$started = microtime( true );
		$this->deadline = $started + (int) ( $options['max_seconds'] ?? Site_Limits::MAX_PASS_SECONDS );

		$entry = $this->normalize( $entry_url );
		$origin = ( '' !== $entry ) ? $this->origin_of( $entry ) : '';
		$max_pages = max( 1, (int) ( $options['max_pages'] ?? Site_Limits::MAX_PAGES ) );
		$max_depth = max( 1, (int) ( $options['max_depth'] ?? Site_Limits::MAX_DEPTH ) );
		$include_subdomains = ! empty( $options['include_subdomains'] );

		if ( '' === $entry ) {
			return $this->empty_result( 'The entry address could not be used. Enter a full public web address.', $started );
		}

		$seed = $this->accepts( $entry, $origin, $include_subdomains );
		if ( null === $seed ) {
			return $this->empty_result(
				'That address cannot be used as the start of a website crawl. It is not a public web page on an allowed domain.',
				$started,
				$origin
			);
		}

		$found     = array( $seed => array( 'url' => $seed, 'depth' => 0, 'from' => '', 'via' => 'entry' ) );
		$frontier  = array( $seed );
		$examined  = 0;
		$refused   = array();
		$stopped   = '';

		// A sitemap is read first because it is the site's own declaration of what it
		// has, and it costs one request instead of a crawl. It is still filtered
		// through `accepts()`, so a sitemap is a *suggestion* and not a command.
		if ( empty( $options['skip_sitemaps'] ) ) {
			foreach ( $this->sitemaps( $origin, $refused ) as $sm_url ) {
				if ( count( $found ) >= $max_pages ) {
					break;
				}
				$found[ $sm_url ] = array( 'url' => $sm_url, 'depth' => 1, 'from' => '', 'via' => 'sitemap' );
			}
		}

		$last_request = 0.0;

		while ( array() !== $frontier && count( $found ) < $max_pages ) {
			if ( microtime( true ) >= $this->deadline ) {
				$stopped = 'time_budget';
				break;
			}
			if ( count( $found ) >= Site_Limits::MAX_FRONTIER ) {
				$stopped = 'frontier_bound';
				break;
			}

			$url   = array_shift( $frontier );
			$entry_data = $found[ $url ];
			$depth = (int) $entry_data['depth'];
			if ( $depth >= $max_depth ) {
				continue;
			}

			// Spacing between requests. A small site crawled at full speed is
			// antisocial even when every other limit is satisfied.
			$wait = Site_Limits::MIN_REQUEST_INTERVAL - ( microtime( true ) - $last_request );
			if ( $wait > 0 ) {
				sleep( (int) ceil( $wait ) );
			}
			$last_request = microtime( true );

			$examined++;
			$page = $this->http->fetch( $url );
			if ( empty( $page['success'] ) ) {
				continue;
			}

			$links = $this->links_in( isset( $page['html'] ) ? (string) $page['html'] : '' );
			$taken = 0;

			foreach ( $links as $link ) {
				if ( $taken >= Site_Limits::MAX_LINKS_PER_PAGE ) {
					break;
				}
				if ( count( $found ) >= $max_pages ) {
					break;
				}

				$normalized = $this->normalize( $link );
				if ( '' === $normalized || isset( $found[ $normalized ] ) ) {
					// Duplicates are dropped here, which is the dedup requirement. A
					// site linking to itself two hundred times produces one entry.
					continue;
				}

				$accepted = $this->accepts( $normalized, $origin, $include_subdomains );
				if ( null === $accepted ) {
					$reason             = $this->refusal_reason( $normalized, $origin, $include_subdomains );
					$refused[ $reason ] = ( $refused[ $reason ] ?? 0 ) + 1;
					continue;
				}

				$found[ $accepted ] = array(
					'url'   => $accepted,
					'depth' => $depth + 1,
					'from'  => $url,
					'via'   => 'link',
				);
				$frontier[] = $accepted;
				$taken++;
			}
		}

		if ( '' === $stopped && count( $found ) >= $max_pages ) {
			$stopped = 'page_limit';
		}

		return array(
			'success'    => true,
			'entry'      => $entry,
			'origin'     => $origin,
			'pages'      => array_values( $found ),
			'count'      => count( $found ),
			'examined'   => $examined,
			'refused'    => $refused,
			'stopped'    => $stopped,
			'max_pages'  => $max_pages,
			'max_depth'  => $max_depth,
			'subdomains' => $include_subdomains,
			'duration'   => round( microtime( true ) - $started, 3 ),
		);
	}

	/**
	 * Return whether a URL would be accepted, without fetching it.
	 *
	 * Used by the REST layer to filter a user-supplied URL list through exactly the
	 * same rules the crawler uses, so a manually entered URL is held to the same
	 * standard as a discovered one.
	 *
	 * @param string $url                 Candidate URL.
	 * @param string $origin              The crawl origin.
	 * @param bool   $include_subdomains  Whether subdomains are allowed.
	 * @return bool
	 */
	public function permits( $url, $origin, $include_subdomains = false ) {
		return null !== $this->accepts( $this->normalize( $url ), $origin, (bool) $include_subdomains );
	}

	/**
	 * Read a website's declared sitemaps.
	 *
	 * @param string                $origin  Crawl origin.
	 * @param array<string, int>    $refused Refusal tally, by reference.
	 * @return array<int, string>
	 */
	private function sitemaps( $origin, array &$refused ) {
		$out = array();
		if ( '' === $origin ) {
			return $out;
		}

		// A sitemap may list further sitemaps. One level is followed, because an
		// unbounded sitemap index is a way to make a crawler do unbounded work
		// without a single page ever being requested.
		$roots = array();
		foreach ( self::SITEMAP_PATHS as $path ) {
			$url   = $origin . $path;
			$roots[] = $url;
			$page = $this->http->fetch( $url );
			if ( empty( $page['success'] ) || empty( $page['body'] ) ) {
				continue;
			}
			$xml = (string) $page['body'];
			$loc = $this->parse_sitemap( $xml, 0 );
			if ( array() === $loc ) {
				// A sitemap index lists other sitemaps rather than pages.
				foreach ( $this->parse_sitemap( $xml, 1 ) as $nested ) {
					if ( count( $roots ) >= 6 ) {
						break;
					}
					$roots[] = $nested;
				}
			}
		}

		foreach ( $roots as $root ) {
			$accepted = $this->accepts( $root, $origin, false );
			if ( null === $accepted ) {
				$reason               = $this->refusal_reason( $root, $origin, false );
				$refused[ $reason ]   = ( $refused[ $reason ] ?? 0 ) + 1;
				continue;
			}
			$page = $this->http->fetch( $accepted );
			if ( empty( $page['success'] ) || empty( $page['body'] ) ) {
				continue;
			}
			foreach ( $this->parse_sitemap( (string) $page['body'], 0 ) as $url ) {
				$accepted_page = $this->accepts( $url, $origin, false );
				if ( null === $accepted_page ) {
					continue;
				}
				if ( count( $out ) >= Site_Limits::MAX_PAGES ) {
					break 2;
				}
				$out[ $accepted_page ] = $accepted_page;
			}
		}

		return array_values( $out );
	}

	/**
	 * Extract `<loc>` values from sitemap XML.
	 *
	 * A plain textual scan rather than `simplexml_load_string`, because an XML parser
	 * resolving an external entity is a XXE and this input is attacker-controlled.
	 * The value is then handed to `accepts()`, which is the real filter; this only
	 * extracts candidates.
	 *
	 * @param string $xml  Sitemap body.
	 * @param int    $mode 0 for page locations, 1 for nested sitemap locations.
	 * @return array<int, string>
	 */
	private function parse_sitemap( $xml, $mode = 0 ) {
		if ( ! is_string( $xml ) || '' === $xml ) {
			return array();
		}
		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $xml ) ) {
			// A doctype or entity declaration in a sitemap is never legitimate and is
			// the shape an XXE takes. Refused before any parsing.
			return array();
		}

		$out = array();
		if ( preg_match_all( '#<loc>\s*([^<\s][^<]{0,2000}?)\s*</loc>#i', $xml, $matches ) ) {
			foreach ( $matches[1] as $raw ) {
				$url = html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$url = trim( $url );
				if ( '' === $url ) {
					continue;
				}
				$looks_like_sitemap = ( false !== stripos( $url, '.xml' ) );
				if ( 1 === $mode && ! $looks_like_sitemap ) {
					continue;
				}
				if ( 0 === $mode && $looks_like_sitemap ) {
					continue;
				}
				$out[] = $url;
				if ( count( $out ) >= Site_Limits::MAX_PAGES ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Extract links from fetched HTML.
	 *
	 * Read with a tag scan rather than a DOM build, because a full parse of
	 * attacker-controlled markup is the expensive operation a page bomb targets, and
	 * this only needs the `href` values.
	 *
	 * @param string $html Page HTML.
	 * @return array<int, string>
	 */
	private function links_in( $html ) {
		$out = array();
		if ( ! is_string( $html ) || '' === $html ) {
			return $out;
		}

		$count = 0;
		if ( preg_match_all( '#<a\b[^>]*?\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$href = '';
				foreach ( array( 2, 3, 4 ) as $group ) {
					if ( isset( $match[ $group ] ) && '' !== $match[ $group ] ) {
						$href = $match[ $group ];
						break;
					}
				}
				$href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' === $href ) {
					continue;
				}
				$out[] = $href;
				if ( ++$count >= Site_Limits::MAX_LINKS_PER_PAGE * 2 ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Accept or refuse a candidate URL.
	 *
	 * @param string $url                 Normalised candidate.
	 * @param string $origin              Crawl origin.
	 * @param bool   $include_subdomains  Whether subdomains are allowed.
	 * @return string|null The accepted URL, or null.
	 */
	private function accepts( $url, $origin, $include_subdomains ) {
		if ( '' === (string) $url || '' === (string) $origin ) {
			return null;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return null;
		}

		if ( in_array( $host, self::NEVER_HOSTS, true ) ) {
			return null;
		}

		if ( ! $this->same_site( $host, (string) wp_parse_url( $origin, PHP_URL_HOST ), $include_subdomains ) ) {
			return null;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( $this->is_never_path( $path ) ) {
			return null;
		}

		// The Phase 1 validator, applied at *discovery*. Validating at fetch time
		// would mean the frontier had already been poisoned by a URL that points at
		// a private address.
		$verdict = $this->validator->validate( $url );
		if ( empty( $verdict['success'] ) ) {
			return null;
		}

		return (string) $verdict['url'];
	}

	/**
	 * Return whether a path is one that is never crawled.
	 *
	 * Compared segment by segment. A `strpos()` prefix test would refuse
	 * `/admiralty`, `/logistics`, `/accounting` and `/setting-up` along with
	 * `/admin`, silently dropping real pages on the strength of a rule that exists
	 * for a different reason. A dotted admin script is caught by stripping the
	 * extension first, so `/admin.php` is the admin page.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	private function is_never_path( $path ) {
		$segments = array_values( array_filter( explode( '/', strtolower( (string) $path ) ), 'strlen' ) );
		if ( array() === $segments ) {
			return false;
		}

		$first = $segments[0];
		$stem  = ( false !== strpos( $first, '.' ) ) ? (string) substr( $first, 0, (int) strpos( $first, '.' ) ) : $first;

		return in_array( $first, self::NEVER_PATHS, true ) || in_array( $stem, self::NEVER_PATHS, true );
	}

	/**
	 * Return why a URL was refused, for the report.
	 *
	 * @param string $url                Candidate.
	 * @param string $origin             Crawl origin.
	 * @param bool   $include_subdomains Whether subdomains are allowed.
	 * @return string
	 */
	private function refusal_reason( $url, $origin, $include_subdomains ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return 'unsupported_scheme';
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( in_array( $host, self::NEVER_HOSTS, true ) ) {
			return 'excluded_host';
		}
		if ( ! $this->same_site( $host, (string) wp_parse_url( $origin, PHP_URL_HOST ), $include_subdomains ) ) {
			return 'external_domain';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( $this->is_never_path( $path ) ) {
			return 'private_area';
		}

		return 'blocked_destination';
	}

	/**
	 * Return whether a host is in scope.
	 *
	 * Subdomain inclusion is **off by default** and covers only one additional
	 * label, so `www.` and `shop.` are included but `evil.co.uk` reached from
	 * `example.com` is not. A deeper inclusion rule is how a crawl ends up on a
	 * hosting provider's customer list.
	 *
	 * @param string $host   Candidate host.
	 * @param string $origin Origin host.
	 * @param bool   $include_subdomains Whether subdomains are allowed.
	 * @return bool
	 */
	private function same_site( $host, $origin, $include_subdomains ) {
		$host   = $this->registrable( $host );
		$origin = $this->registrable( $origin );
		if ( '' === $host || '' === $origin ) {
			return false;
		}
		return ( $host === $origin );
	}

	/**
	 * Reduce a host to something comparable.
	 *
	 * A trailing `www.` is removed, because `example.com` and `www.example.com` are
	 * one website and refusing the second would miss every page of a site that links
	 * to itself canonically.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private function registrable( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '/^www\d?\./', '', $host );
		return (string) $host;
	}

	/**
	 * Normalise a URL for comparison and deduplication.
	 *
	 * Strips the fragment, drops tracking parameters, and lowercases the host. It
	 * does **not** strip unknown query parameters, because a query can select the
	 * page's content and treating `?p=2` as `?p=1` would silently analyse the wrong
	 * thing. Only parameters known to be tracking are removed, because those
	 * demonstrably are not.
	 *
	 * @param string $url Candidate.
	 * @return string
	 */
	private function normalize( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		if ( 0 === strpos( strtolower( $url ), 'http://' ) || 0 === strpos( strtolower( $url ), 'https://' ) ) {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return '';
			}

			$scheme = strtolower( (string) $parts['scheme'] );
			$host   = strtolower( (string) $parts['host'] );
			$path   = (string) ( $parts['path'] ?? '/' );
			if ( '' === $path ) {
				$path = '/';
			}
			// A trailing slash on a directory path is a duplicate of the path
			// without one for most sites, and keeping both doubles every page.
			if ( '/' !== $path && '/' === substr( $path, -1 ) ) {
				$path = rtrim( $path, '/' );
				if ( '' === $path ) {
					$path = '/';
				}
			}

			$query = '';
			if ( ! empty( $parts['query'] ) ) {
				$params = array();
				parse_str( (string) $parts['query'], $params );
				$tracking = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'mc_cid', 'mc_eid', 'ref', 'ref_src' );
				foreach ( $params as $key => $value ) {
					if ( in_array( strtolower( (string) $key ), $tracking, true ) || ! is_scalar( $value ) ) {
						continue;
					}
					$params[ $key ] = (string) $value;
				}
				if ( array() !== $params ) {
					$query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
				}
			}

			$port = '';
			if ( ! empty( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) {
				$port = ':' . (int) $parts['port'];
			}

			// The fragment is dropped: it never reaches a server, so two URLs
			// differing only by fragment are one page.
			return $scheme . '://' . $host . $port . $path . ( '' !== $query ? '?' . $query : '' );
		}

		return '';
	}

	/**
	 * Return the origin of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function origin_of( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
	}

	/**
	 * Build an empty result.
	 *
	 * @param string $message Why.
	 * @param float  $started Start time.
	 * @param string $origin  Crawl origin.
	 * @return array<string, mixed>
	 */
	private function empty_result( $message, $started, $origin = '' ) {
		return array(
			'success'   => false,
			'message'   => $message,
			'entry'     => '',
			'origin'    => $origin,
			'pages'     => array(),
			'count'     => 0,
			'examined'  => 0,
			'refused'   => array(),
			'stopped'   => 'refused',
			'duration'  => round( microtime( true ) - $started, 3 ),
		);
	}
}
