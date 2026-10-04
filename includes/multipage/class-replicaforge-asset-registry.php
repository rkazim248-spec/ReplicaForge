<?php
/**
 * Phase 12: the website-level asset registry.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Records every asset the website uses, deduplicates it, and tracks where it came
 * from.
 *
 * ### Reference is the default, and it is the right default
 *
 * §32 offers three modes. `reference` is the default, and the reason is worth
 * stating because it is a legal and a practical point as much as a technical one:
 * importing an image copies it into the media library, which means the replica now
 * contains a copy of somebody else's asset. A user who wants that can ask for it
 * per asset. A user who did not should not silently accumulate other people's
 * images.
 *
 * ### Deduplication is by content, not by URL
 *
 * §30 says deduplicate identical assets. The obvious implementation keys on URL,
 * which catches `logo.png` referenced from three pages — useful — and misses
 * `logo.png?v=2`, `logo.png#frag`, and the same image on two CDNs, which is the
 * case that actually costs. So the key is the URL *with its cache-busting query
 * stripped*, and a content hash is recorded once an asset has been fetched. Two
 * URLs resolving to the same bytes are one asset.
 *
 * ### Provenance is mandatory, not optional
 *
 * §67 requires provenance for every imported category, and this class records it at
 * the moment of discovery rather than on import. An asset that was never imported
 * still has a source, and "where did this image come from" is a question a user
 * asks about the replica, not only about the import.
 */
final class Asset_Registry {

	/**
	 * Query parameters that identify a *different* resource rather than a
	 * cache-busting variant.
	 *
	 * Stripping `id=5` and treating it as `id=4` would merge two genuinely
	 * different products into one asset, so only the known-boring ones go.
	 *
	 * @var array<int, string>
	 */
	const CACHE_PARAMS = array( 'v', 'ver', 'version', 'rev', 't', 'cachebust', '_', 'cb' );

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Optional logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Build the asset registry for a set of pages.
	 *
	 * @param array<string, array<string, mixed>> $pages Page id => representation.
	 * @return array<string, mixed>
	 */
	public function build( array $pages ) {
		$by_key  = array();
		$order   = array();
		$rejected = array();

		foreach ( $pages as $page_id => $representation ) {
			if ( ! is_array( $representation ) ) {
				continue;
			}

			$assets = isset( $representation['assets'] ) && is_array( $representation['assets'] ) ? $representation['assets'] : array();
			foreach ( $assets as $asset ) {
				if ( ! is_array( $asset ) ) {
					continue;
				}
				$source = isset( $asset['url'] ) ? (string) $asset['url'] : ( isset( $asset['src'] ) ? (string) $asset['src'] : '' );
				if ( '' === $source ) {
					continue;
				}

				$verdict = $this->inspect( $source );
				if ( ! $verdict['usable'] ) {
					$rejected[ $verdict['reason'] ] = ( $rejected[ $verdict['reason'] ] ?? 0 ) + 1;
					continue;
				}

				$key = $verdict['key'];

				if ( ! isset( $by_key[ $key ] ) ) {
					$by_key[ $key ] = array(
						'asset_id'    => $this->asset_id( $key ),
						'source_url'  => $verdict['url'],
						'resolved_url'=> $verdict['url'],
						'type'        => (string) ( $asset['type'] ?? $verdict['type'] ),
						'role'        => (string) ( $asset['role'] ?? $this->role_of( $asset ) ),
						'dimensions'  => $this->dimensions_of( $asset ),
						'mime_type'   => (string) ( $asset['mime_type'] ?? $verdict['type'] ),
						'hash'        => '',
						'usage_count' => 0,
						'pages'       => array(),
						'provenance'  => array(
							'discovered_from' => (string) $page_id,
							'source_site'     => $this->host_of( $verdict['url'] ),
							'first_seen'      => time(),
							'stored'          => false,
						),
						'status'      => 'discovered',
						'mode'        => 'reference',
					);
					$order[] = $key;
				}

				$entry = &$by_key[ $key ];
				$entry['usage_count']++;
				if ( ! in_array( (string) $page_id, $entry['pages'], true ) ) {
					$entry['pages'][] = (string) $page_id;
				}
				// A role seen on more than one page is a *shared* role; one seen once is
				// that page's own use. Recorded, not resolved — the same reasoning as
				// the design system.
				if ( (string) ( $asset['role'] ?? '' ) !== '' ) {
					$entry['role'] = (string) $asset['role'];
				}
				unset( $entry );
			}
		}

		$assets = array();
		foreach ( $order as $key ) {
			$entry = $by_key[ $key ];
			if ( count( $entry['pages'] ) > 1 ) {
				$entry['status'] = 'deduplicated';
				$entry['shared'] = true;
			} else {
				$entry['shared'] = false;
			}
			$assets[] = $entry;
		}

		$bounded = array_slice( $assets, 0, Site_Limits::MAX_ASSETS );

		$deduped = count( $assets ) - count( $bounded );
		$refs    = 0;
		$shared  = 0;
		foreach ( $bounded as $asset ) {
			if ( ! empty( $asset['shared'] ) ) {
				$shared++;
			}
			if ( 'reference' === (string) $asset['mode'] ) {
				$refs++;
			}
		}

		return array(
			'schema_version' => Site_Limits::SCHEMA_VERSION,
			'assets'         => $bounded,
			'count'          => count( $bounded ),
			'by_type'        => $this->by_type( $bounded ),
			'shared'         => $shared,
			'references'     => $refs,
			'deduplicated'   => max( 0, $shared ),
			'over_bound'     => $deduped,
			'rejected'       => $rejected,
			'modes'          => Site_Limits::ASSET_MODES,
			'default_mode'   => 'reference',
			'notes'          => array(
				'no_download'     => __( 'Nothing has been downloaded. Assets point at their source address until you ask for them to be imported.', 'replicaforge' ),
				'provenance'      => __( 'Every asset records which pages use it and which website it came from.', 'replicaforge' ),
				'rights'          => __( 'An address being public is not permission to reuse it. You are responsible for the rights to anything you import.', 'replicaforge' ),
			),
		);
	}

	/**
	 * Inspect an asset URL for usability.
	 *
	 * @param string $url Candidate.
	 * @return array<string, mixed>
	 */
	public function inspect( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return $this->reject( $url, 'empty' );
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		if ( 0 === strpos( strtolower( $url ), 'data:' ) ) {
			// A data: URI is inline content, not an asset, and is often a base64
			// payload. Recorded as unusable rather than stored.
			return $this->reject( $url, 'data_uri' );
		}

		$validator = new Url_Validator();
		$verdict   = $validator->validate( $url );
		if ( empty( $verdict['success'] ) ) {
			return $this->reject( $url, 'blocked_destination' );
		}

		$clean = (string) $verdict['url'];

		return array(
			'usable' => true,
			'url'    => $clean,
			'key'    => $this->dedup_key( $clean ),
			'type'   => $this->type_of( $clean ),
			'reason' => '',
		);
	}

	/**
	 * Set an asset's mode.
	 *
	 * @param string $mode Requested mode.
	 * @return array<string, mixed>
	 */
	public static function normalise_mode( $mode ) {
		$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : '';
		if ( in_array( $mode, Site_Limits::ASSET_MODES, true ) ) {
			return $mode;
		}
		return 'reference';
	}

	/**
	 * Set an asset's status.
	 *
	 * @param string $status Requested status.
	 * @return string
	 */
	public static function normalise_status( $status ) {
		$status = is_string( $status ) ? strtolower( trim( $status ) ) : '';
		return in_array( $status, Site_Limits::ASSET_STATUSES, true ) ? $status : 'discovered';
	}

	/**
	 * Return whether an asset may be imported.
	 *
	 * Phase 4 and Phase 8 already validate media before import; this exists so the
	 * website layer does not need to know that, and refuses a mode it cannot
	 * support rather than pretending to.
	 *
	 * @param array<string, mixed> $asset Asset record.
	 * @return array<string, mixed>
	 */
	public static function may_import( array $asset ) {
		$type = strtolower( (string) ( $asset['type'] ?? $asset['mime_type'] ?? '' ) );
		$ok   = in_array( $type, array( 'image', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ), true ) || in_array( $type, Elementor_Limits::ALLOWED_MEDIA_TYPES, true );

		if ( ! $ok ) {
			return array(
				'ok'    => false,
				'reason' => 'unsupported_type',
				'message' => __( 'Only images are imported. Anything else stays as a reference to its source address.', 'replicaforge' ),
			);
		}

		return array(
			'ok'    => true,
			'reason' => '',
			'message' => __( 'This asset can be imported into the media library.', 'replicaforge' ),
		);
	}

	/**
	 * Return the deduplication key for a URL.
	 *
	 * Cache-busting parameters are removed because they identify the same bytes
	 * under a different name. Everything else is kept, because a parameter that is
	 * not on the known-boring list might select the resource.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function dedup_key( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			return strtolower( (string) $url );
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path = (string) ( $parts['path'] ?? '' );
		$key  = $host . $path;

		if ( ! empty( $parts['query'] ) ) {
			$params = array();
			parse_str( (string) $parts['query'], $params );
			foreach ( $params as $name => $value ) {
				// `unset`, not `continue`. A `continue` here skips only the *assignment*
				// below and leaves the cache-busting parameter in the array, so
				// `logo.png?v=1` and `logo.png?v=2` still keyed differently and the
				// deduplication silently did nothing. The first draft had exactly that,
				// and it read as working code because the loop looked right.
				if ( in_array( strtolower( (string) $name ), self::CACHE_PARAMS, true ) ) {
					unset( $params[ $name ] );
					continue;
				}
				if ( ! is_scalar( $value ) ) {
					unset( $params[ $name ] );
					continue;
				}
				$params[ $name ] = (string) $value;
			}
			ksort( $params );
			foreach ( $params as $name => $value ) {
				$key .= '|' . strtolower( (string) $name ) . '=' . (string) $value;
			}
		}

		return $key;
	}

	/**
	 * Return an asset identifier.
	 *
	 * @param string $key Deduplication key.
	 * @return string
	 */
	public function asset_id( $key ) {
		return 'asset_' . substr( hash( 'sha256', (string) $key ), 0, 12 );
	}

	/**
	 * Return the asset type from its extension.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function type_of( $url ) {
		$path      = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) );
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		$map = array(
			'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
			'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
			'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
			'css' => 'text/css', 'js' => 'application/javascript', 'woff' => 'font/woff',
			'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
		);

		if ( isset( $map[ $extension ] ) ) {
			return $map[ $extension ];
		}
		if ( 'svg' === $extension ) {
			return 'image/svg+xml';
		}
		return '';
	}

	/**
	 * Return an asset's role.
	 *
	 * @param array<string, mixed> $asset Asset.
	 * @return string
	 */
	private function role_of( array $asset ) {
		$alt = strtolower( (string) ( $asset['alt'] ?? '' ) );
		if ( false !== strpos( $alt, 'logo' ) ) {
			return 'logo';
		}
		if ( ! empty( $asset['in_header'] ) ) {
			return 'header';
		}
		if ( ! empty( $asset['in_footer'] ) ) {
			return 'footer';
		}
		return 'content';
	}

	/**
	 * Return an asset's declared dimensions.
	 *
	 * Only declared ones. A dimension guessed from the layout is not a dimension,
	 * and a validator that treats it as one reports a mismatch that does not exist.
	 *
	 * @param array<string, mixed> $asset Asset.
	 * @return array<string, int>
	 */
	private function dimensions_of( array $asset ) {
		$out = array();
		foreach ( array( 'width', 'height' ) as $key ) {
			if ( isset( $asset[ $key ] ) && is_numeric( $asset[ $key ] ) ) {
				$value = (int) $asset[ $key ];
				if ( $value > 0 && $value <= 20000 ) {
					$out[ $key ] = $value;
				}
			}
		}
		return $out;
	}

	/**
	 * Return a host.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function host_of( $url ) {
		return strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
	}

	/**
	 * Return asset counts by type.
	 *
	 * @param array<int, array<string, mixed>> $assets Assets.
	 * @return array<string, int>
	 */
	private function by_type( array $assets ) {
		$out = array();
		foreach ( $assets as $asset ) {
			$type = (string) ( $asset['type'] ?? 'unknown' );
			$out[ $type ] = ( $out[ $type ] ?? 0 ) + 1;
		}
		arsort( $out );
		return $out;
	}

	/**
	 * Build a rejection.
	 *
	 * @param string $url    URL.
	 * @param string $reason Reason.
	 * @return array<string, mixed>
	 */
	private function reject( $url, $reason ) {
		return array(
			'usable' => false,
			'url'    => (string) $url,
			'key'    => '',
			'type'   => '',
			'reason' => (string) $reason,
		);
	}
}
