<?php
/**
 * URL validation and DNS/IP safety checks for ReplicaForge.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Validates submitted URLs and redirect destinations before any request is made.
 */
final class Url_Validator {

	/**
	 * Shared security policy.
	 *
	 * @var Security
	 */
	private $security;

	/**
	 * Per-request DNS cache.
	 *
	 * @var array<string, array<int, string>|array{error: string}>
	 */
	private $dns_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Security|null $security Shared security helper.
	 */
	public function __construct( $security = null ) {
		$this->security = $security instanceof Security ? $security : new Security();
	}

	/**
	 * Validate and normalize a public HTTP(S) URL.
	 *
	 * @param mixed $url Submitted URL.
	 * @return array<string, mixed> Validated URL or a safe error envelope.
	 */
	public function validate( $url ) {
		if ( ! is_string( $url ) ) {
			return $this->blocked( 'invalid_url', 'Invalid URL.', 400 );
		}

		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return $this->blocked( 'invalid_url', 'Invalid URL.', 400 );
		}

		if ( function_exists( 'wp_check_invalid_utf8' ) && wp_check_invalid_utf8( $url ) !== $url ) {
			return $this->blocked( 'invalid_url', 'Invalid URL.', 400 );
		}

		if ( preg_match( '/[\x00-\x20\x7f]/', $url ) || false !== strpos( $url, '\\' ) ) {
			return $this->blocked( 'invalid_url', 'Invalid URL.', 400 );
		}

		if ( preg_match( '/%(?![0-9a-f]{2})/i', $url ) || preg_match( '/%(?:00|0d|0a)/i', $url ) ) {
			return $this->blocked( 'invalid_url', 'Invalid URL.', 400 );
		}

		$parts = $this->security->parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) ) {
			return $this->blocked( 'invalid_url', 'Invalid URL.', 400 );
		}

		$scheme = strtolower( trim( (string) $parts['scheme'] ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return $this->blocked( 'unsupported_protocol', 'Only HTTP and HTTPS URLs are supported.', 400 );
		}

		if ( empty( $parts['host'] ) ) {
			return $this->blocked( 'invalid_url', 'Invalid URL.', 400 );
		}

		// Inspect the authority independently of parse_url's user/password fields.
		$authority_start = strpos( $url, '://' );
		if ( false !== $authority_start ) {
			$authority      = substr( $url, $authority_start + 3 );
			$authority_parts = preg_split( '~[/?#]~', $authority, 2 );
			$authority       = is_array( $authority_parts ) ? $authority_parts[0] : $authority;
			if ( false !== strpos( $authority, '@' ) ) {
				return $this->blocked( 'credentials_not_allowed', 'URLs containing credentials are not allowed.', 400 );
			}
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $this->blocked( 'credentials_not_allowed', 'URLs containing credentials are not allowed.', 400 );
		}

		$raw_host = trim( (string) $parts['host'] );
		if ( $this->security->is_dangerous_hostname( $raw_host ) ) {
			return $this->blocked( 'blocked_destination', 'This website cannot be analyzed because the URL is not allowed.', 403 );
		}

		$host     = $this->security->normalize_legacy_ipv4( $raw_host );
		if ( null === $host ) {
			$host = $this->security->normalize_host( $raw_host );
		}
		if ( '' === $host ) {
			return $this->blocked( 'invalid_url', 'Invalid URL.', 400 );
		}

		if ( $this->security->is_dangerous_hostname( $host ) ) {
			return $this->blocked( 'blocked_destination', 'This website cannot be analyzed because the URL is not allowed.', 403 );
		}

		$port = null;
		if ( isset( $parts['port'] ) ) {
			$port = $parts['port'];
			if ( ! is_int( $port ) || $port < 1 || $port > 65535 ) {
				return $this->blocked( 'invalid_port', 'The URL uses an invalid port.', 400 );
			}

			// Restrict the initial analyzer to standard web ports. This avoids
			// turning a public URL validator into a service-port scanner.
			if ( ! in_array( $port, array( 80, 443 ), true ) ) {
				return $this->blocked( 'blocked_port', 'The URL uses a port that is not allowed.', 403 );
			}
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		$query = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';

		if ( $this->security->is_forbidden_target_path( $path, isset( $parts['query'] ) ? (string) $parts['query'] : '' ) ) {
			return $this->blocked( 'forbidden_target', 'Only public frontend pages can be analyzed.', 403 );
		}

		$legacy_ip = $this->security->normalize_legacy_ipv4( $host );
		if ( null !== $legacy_ip ) {
			$host = $legacy_ip;
			if ( ! $this->security->is_public_ip( $host ) ) {
				return $this->blocked( 'blocked_destination', 'This website cannot be analyzed because the URL is not allowed.', 403 );
			}
			$resolved_ips = array( $host );
		} elseif ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( ! $this->security->is_public_ip( $host ) ) {
				return $this->blocked( 'blocked_destination', 'This website cannot be analyzed because the URL is not allowed.', 403 );
			}
			$resolved_ips = array( $host );
		} else {
			$resolved_ips = $this->resolve_host( $host );
			if ( is_array( $resolved_ips ) && isset( $resolved_ips['error'] ) ) {
				return $this->blocked( 'dns_resolution_failed', 'The website hostname could not be resolved.', 400 );
			}

			if ( ! is_array( $resolved_ips ) || empty( $resolved_ips ) ) {
				return $this->blocked( 'dns_resolution_failed', 'The website hostname could not be resolved.', 400 );
			}

			foreach ( $resolved_ips as $resolved_ip ) {
				if ( ! $this->security->is_public_ip( $resolved_ip ) ) {
					return $this->blocked( 'blocked_destination', 'This website cannot be analyzed because the URL is not allowed.', 403 );
				}
			}
		}

		$normalized_host = $host;
		if ( false !== filter_var( $normalized_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$normalized_host = '[' . $normalized_host . ']';
		}

		$normalized_port = '';
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = null;
		}
		if ( null !== $port ) {
			$normalized_port = ':' . (int) $port;
		}

		$normalized_url = strtolower( $scheme ) . '://' . $normalized_host . $normalized_port . $path . $query;
		$normalized_url = $this->remove_fragment( $normalized_url );

		return array(
			'success'       => true,
			'url'           => $normalized_url,
			'scheme'        => strtolower( $scheme ),
			'host'          => $host,
			'port'          => $port,
			'public_target' => true,
		);
	}

	/**
	 * Resolve a hostname using the available PHP DNS functions.
	 *
	 * @param string $host Normalized hostname.
	 * @return array<int, string>|array{error: string}
	 */
	private function resolve_host( $host ) {
		if ( isset( $this->dns_cache[ $host ] ) ) {
			return $this->dns_cache[ $host ];
		}
		$addresses = array();

		if ( function_exists( 'gethostbynamel' ) ) {
			$ipv4 = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $ipv4 ) ) {
				$addresses = array_merge( $addresses, $ipv4 );
			}
		}

		if ( function_exists( 'dns_get_record' ) ) {
			$record_types = 0;
			if ( defined( 'DNS_A' ) ) {
				$record_types |= DNS_A;
			}
			if ( defined( 'DNS_AAAA' ) ) {
				$record_types |= DNS_AAAA;
			}

			if ( $record_types ) {
				$records = @dns_get_record( $host, $record_types ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $records ) ) {
					foreach ( $records as $record ) {
						if ( ! empty( $record['ip'] ) && filter_var( $record['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
							$addresses[] = $record['ip'];
						}
						if ( ! empty( $record['ipv6'] ) && filter_var( $record['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
							$addresses[] = $record['ipv6'];
						}
					}
				}
			}
		}

		$addresses = array_values(
			array_unique(
				array_filter(
					$addresses,
					static function ( $address ) {
						return is_string( $address ) && false !== filter_var( $address, FILTER_VALIDATE_IP );
					}
				)
			)
		);

		if ( empty( $addresses ) ) {
			$this->dns_cache[ $host ] = array( 'error' => 'dns_resolution_failed' );
			return $this->dns_cache[ $host ];
		}

		$this->dns_cache[ $host ] = $addresses;
		return $this->dns_cache[ $host ];
	}

	/**
	 * Remove a fragment, which is never sent in an HTTP request.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function remove_fragment( $url ) {
		$position = strpos( $url, '#' );
		return false === $position ? $url : substr( $url, 0, $position );
	}

	/**
	 * Create a consistent error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message User-facing message.
	 * @param int    $status  Error status.
	 * @return array<string, mixed>
	 */
	private function blocked( $code, $message, $status ) {
		return $this->security->error( $code, $message, $status );
	}
}
