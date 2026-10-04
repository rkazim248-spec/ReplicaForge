<?php
/**
 * Shared security and data-handling helpers for ReplicaForge.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes security policy decisions used by the URL validator, HTTP client,
 * parser, analyzer, and REST layer.
 */
final class Security {

	/**
	 * Maximum number of bytes accepted from a remote response.
	 *
	 * @var int
	 */
	const MAX_RESPONSE_SIZE = 5242880;

	/**
	 * Maximum duration of the complete redirect-aware request.
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 15;

	/**
	 * Maximum number of redirects followed by the HTTP client.
	 *
	 * @var int
	 */
	const MAX_REDIRECTS = 3;

	/**
	 * Return a safe, user-facing error envelope.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message User-facing message.
	 * @param int    $status  HTTP-like status used by the REST adapter.
	 * @return array<string, mixed>
	 */
	public static function error( $code, $message, $status = 400 ) {
		return array(
			'success' => false,
			'error'   => array(
				'code'    => sanitize_key( $code ),
				'message' => (string) $message,
				'status'  => absint( $status ),
			),
		);
	}

	/**
	 * Parse a URL using WordPress' parser when it is available.
	 *
	 * @param string $url URL to parse.
	 * @return array<string, mixed>|false
	 */
	public static function parse_url( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url );
		}

		return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}

	/**
	 * Check whether a URL has safe HTTP(S) syntax without making a request.
	 *
	 * This is intentionally used for extracted links and images. Those values
	 * are displayed as metadata and are never fetched by the analyzer.
	 *
	 * @param string $url URL to inspect.
	 * @return bool
	 */
	public static function is_safe_http_syntax( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return false;
		}

		$url = trim( $url );
		if ( function_exists( 'wp_check_invalid_utf8' ) && wp_check_invalid_utf8( $url ) !== $url ) {
			return false;
		}
		if ( strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) || false !== strpos( $url, '\\' ) ) {
			return false;
		}

		if ( preg_match( '/%(?![0-9a-f]{2})/i', $url ) || preg_match( '/%(?:00|0d|0a)/i', $url ) ) {
			return false;
		}

		$parts = self::parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		if ( isset( $parts['port'] ) && ( ! is_int( $parts['port'] ) || $parts['port'] < 1 || $parts['port'] > 65535 ) ) {
			return false;
		}

		$host = trim( (string) $parts['host'] );
		if ( '' === $host || false !== strpos( $host, '@' ) ) {
			return false;
		}

		return self::normalize_host( $host ) !== '';
	}

	/**
	 * Normalize a safe absolute HTTP(S) URL without resolving DNS.
	 *
	 * This is used for links and images discovered in a page. Those values
	 * are metadata only and are never fetched by the analyzer.
	 *
	 * @param string $url URL to normalize.
	 * @return string|null
	 */
	public static function normalize_http_url( $url ) {
		if ( ! self::is_safe_http_syntax( $url ) ) {
			return null;
		}

		$parts = self::parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = self::normalize_host( (string) $parts['host'] );
		if ( '' === $host ) {
			return null;
		}

		$normalized_host = $host;
		if ( false !== filter_var( $normalized_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$normalized_host = '[' . $normalized_host . ']';
		}

		$port = '';
		if ( isset( $parts['port'] ) ) {
			$port_number = (int) $parts['port'];
			if ( ! ( ( 'http' === $scheme && 80 === $port_number ) || ( 'https' === $scheme && 443 === $port_number ) ) ) {
				$port = ':' . $port_number;
			}
		}

		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		$query    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $scheme . '://' . $normalized_host . $port . $path . $query . $fragment;
	}

	/**
	 * Check a discovered URL reference against the non-fetching portion of the
	 * public-target policy.
	 *
	 * This deliberately does not resolve hostnames. References are metadata in
	 * Phase 2, and any future consumer that intends to fetch one must call the
	 * authoritative DNS-aware validator again immediately before the request.
	 *
	 * @param string $url URL reference.
	 * @return bool
	 */
	public static function is_safe_public_reference( $url ) {
		$normalized = self::normalize_http_url( $url );
		if ( null === $normalized ) {
			return false;
		}

		$parts = self::parse_url( $normalized );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( isset( $parts['port'] ) ) {
			$port = (int) $parts['port'];
			if ( ! ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) ) {
				return false;
			}
		}

		$path_allowed = ! self::is_forbidden_target_path(
			isset( $parts['path'] ) ? (string) $parts['path'] : '/',
			isset( $parts['query'] ) ? (string) $parts['query'] : ''
		);
		if ( ! $path_allowed ) {
			return false;
		}

		$raw_host = self::normalize_host( (string) $parts['host'] );
		if ( '' === $raw_host || self::is_dangerous_hostname( $raw_host ) ) {
			return false;
		}

		$legacy = self::normalize_legacy_ipv4( $raw_host );
		if ( null !== $legacy ) {
			return self::is_public_ip( $legacy );
		}
		if ( false !== filter_var( $raw_host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $raw_host );
		}

		return true;
	}

	/**
	 * Normalize a hostname or IP address.
	 *
	 * @param string $host Hostname to normalize.
	 * @return string Normalized host, or an empty string when invalid.
	 */
	public static function normalize_host( $host ) {
		if ( ! is_string( $host ) ) {
			return '';
		}

		$host = strtolower( trim( $host ) );
		if ( '' === $host ) {
			return '';
		}

		if ( '[' === substr( $host, 0, 1 ) && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}

		$host = rtrim( $host, '.' );
		if ( '' === $host || strlen( $host ) > 253 ) {
			return '';
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}

		if ( function_exists( 'idn_to_ascii' ) ) {
			$flags   = defined( 'IDNA_NONTRANSITIONAL_TO_ASCII' ) ? IDNA_NONTRANSITIONAL_TO_ASCII : 0;
			$variant = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0;
			$converted = @idn_to_ascii( $host, $flags, $variant ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_string( $converted ) && '' !== $converted ) {
				$host = strtolower( $converted );
			}
		}

		$host = rtrim( $host, '.' );
		if ( '' === $host || strlen( $host ) > 253 || preg_match( '/[^\x21-\x7e]/', $host ) ) {
			return '';
		}

		$labels = explode( '.', $host );
		if ( count( $labels ) < 2 ) {
			return '';
		}

		foreach ( $labels as $label ) {
			if ( '' === $label || strlen( $label ) > 63 || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label ) ) {
				return '';
			}
		}

		return $host;
	}

	/**
	 * Normalize legacy IPv4 representations such as decimal, octal, and
	 * hexadecimal host notation. Ambiguous numeric hostnames are treated as
	 * IPs and are therefore subject to the public-address policy.
	 *
	 * @param string $host Host value.
	 * @return string|null Canonical IP, or null when the value is not numeric.
	 */
	public static function normalize_legacy_ipv4( $host ) {
		if ( ! is_string( $host ) || ! preg_match( '/^(?:0x[0-9a-f]+|[0-9.]+)$/i', $host ) ) {
			return null;
		}

		$components = explode( '.', $host );
		if ( count( $components ) > 4 ) {
			return null;
		}

		$values = array();
		foreach ( $components as $component ) {
			if ( '' === $component ) {
				return null;
			}

			if ( 0 === stripos( $component, '0x' ) ) {
				$number = hexdec( substr( $component, 2 ) );
			} elseif ( strlen( $component ) > 1 && '0' === $component[0] ) {
				$number = octdec( substr( $component, 1 ) );
			} else {
				$number = (int) $component;
			}

			if ( $number < 0 || ( 1 === count( $components ) ? $number > 4294967295 : $number > 255 ) ) {
				return null;
			}
			$values[] = $number;
		}

		// Use floating-point accumulation so legacy numeric host parsing does
		// not depend on a 64-bit PHP integer being available.
		$address = 0.0;
		foreach ( $values as $value ) {
			$address = ( $address * 256 ) + (float) $value;
		}

		if ( $address < 0 || $address > 4294967295 ) {
			return null;
		}

		return sprintf(
			'%d.%d.%d.%d',
			(int) floor( $address / 16777216 ) % 256,
			(int) floor( $address / 65536 ) % 256,
			(int) floor( $address / 256 ) % 256,
			(int) floor( $address ) % 256
		);
	}

	/**
	 * Determine whether an IP address is globally routable.
	 *
	 * The explicit checks cover ranges which are not consistently treated as
	 * private by every PHP/WordPress version, including carrier-grade NAT,
	 * documentation, benchmarking, multicast, and cloud link-local space.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		if ( ! is_string( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return false;
		}

		if ( 4 === strlen( $packed ) ) {
			if ( ! self::is_public_ipv4_bytes( $packed ) ) {
				return false;
			}
		} else {
			$mapped = self::extract_mapped_ipv4( $packed );
			if ( null !== $mapped ) {
				return self::is_public_ip( $mapped );
			}

			if ( ! self::is_public_ipv6_bytes( $packed ) ) {
				return false;
			}
		}

		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Check IPv4 address bytes against non-public ranges.
	 *
	 * @param string $packed Four packed bytes.
	 * @return bool
	 */
	private static function is_public_ipv4_bytes( $packed ) {
		$first = ord( $packed[0] );
		$second = ord( $packed[1] );
		$third = ord( $packed[2] );

		if ( 0 === $first || 10 === $first || 127 === $first || 224 <= $first ) {
			return false;
		}

		if ( 100 === $first && $second >= 64 && $second <= 127 ) {
			return false;
		}

		if ( 169 === $first && 254 === $second ) {
			return false;
		}

		if ( 172 === $first && $second >= 16 && $second <= 31 ) {
			return false;
		}

		if ( 192 === $first && 168 === $second ) {
			return false;
		}

		if ( 192 === $first && 0 === $second && 0 === $third ) {
			return false;
		}

		if ( 192 === $first && 0 === $second && 2 === $third ) {
			return false;
		}

		if ( 192 === $first && 88 === $second && 99 === $third ) {
			return false;
		}

		if ( 198 === $first && ( 18 === $second || 19 === $second ) ) {
			return false;
		}

		if ( 198 === $first && 51 === $second && 100 === $third ) {
			return false;
		}

		if ( 203 === $first && 0 === $second && 113 === $third ) {
			return false;
		}

		// 240.0.0.0/4 is reserved and includes the broadcast address.
		if ( $first >= 240 ) {
			return false;
		}

		return true;
	}

	/**
	 * Extract an IPv4 address embedded in an IPv4-mapped or compatible IPv6
	 * address.
	 *
	 * @param string $packed Sixteen packed bytes.
	 * @return string|null
	 */
	private static function extract_mapped_ipv4( $packed ) {
		$is_mapped = 0 === substr_compare( $packed, str_repeat( "\0", 10 ), 0, 10 )
			&& 255 === ord( $packed[10] ) && 255 === ord( $packed[11] );
		$is_compatible = 0 === substr_compare( $packed, str_repeat( "\0", 12 ), 0, 12 );

		if ( ! $is_mapped && ! $is_compatible ) {
			return null;
		}

		$address = @inet_ntop( substr( $packed, 12, 4 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_string( $address ) ? $address : null;
	}

	/**
	 * Check IPv6 bytes against private, reserved, and special-purpose ranges.
	 *
	 * @param string $packed Sixteen packed bytes.
	 * @return bool
	 */
	private static function is_public_ipv6_bytes( $packed ) {
		$first = ord( $packed[0] );
		$second = ord( $packed[1] );

		if ( 0 === substr_compare( $packed, str_repeat( "\0", 16 ), 0, 16 ) ) {
			return false;
		}

		if ( 0 === substr_compare( $packed, str_repeat( "\0", 15 ) . "\1", 0, 16 ) ) {
			return false;
		}

		// Unique local addresses fc00::/7.
		if ( 0xfc === ( $first & 0xfe ) ) {
			return false;
		}

		// Link-local fe80::/10 and deprecated site-local fec0::/10.
		if ( 0xfe === $first && ( 0x80 === ( $second & 0xc0 ) || 0xc0 === ( $second & 0xc0 ) ) ) {
			return false;
		}

		// Multicast.
		if ( 0xff === $first ) {
			return false;
		}

		// Documentation, discard-only, Teredo, benchmarking, and ORCHID ranges.
		if ( self::ipv6_has_prefix( $packed, array( 0x20, 0x01, 0x0d, 0xb8 ), 32 ) ) {
			return false;
		}
		if ( self::ipv6_has_prefix( $packed, array( 0x20, 0x01, 0x00, 0x00 ), 32 ) ) {
			return false;
		}
		if ( self::ipv6_has_prefix( $packed, array( 0x20, 0x01, 0x00, 0x02, 0x00, 0x00 ), 48 ) ) {
			return false;
		}
		if ( self::ipv6_has_prefix( $packed, array( 0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00 ), 64 ) ) {
			return false;
		}
		if ( self::ipv6_has_prefix( $packed, array( 0x20, 0x01, 0x00, 0x10 ), 28 ) ) {
			return false;
		}
		if ( self::ipv6_has_prefix( $packed, array( 0x20, 0x01, 0x00, 0x20 ), 28 ) ) {
			return false;
		}

		// NAT64 translation prefixes can route embedded private IPv4 space.
		if ( self::ipv6_has_prefix( $packed, array( 0x00, 0x64, 0xff, 0x9b, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00 ), 96 ) ) {
			return false;
		}
		if ( self::ipv6_has_prefix( $packed, array( 0x00, 0x64, 0xff, 0x9b, 0x00, 0x01 ), 48 ) ) {
			return false;
		}

		// 6to4 can encapsulate a private IPv4 destination.
		if ( self::ipv6_has_prefix( $packed, array( 0x20, 0x02 ), 16 ) ) {
			$embedded = @inet_ntop( substr( $packed, 2, 4 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_string( $embedded ) && ! self::is_public_ip( $embedded ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Test an IPv6 byte string for a prefix.
	 *
	 * @param string $packed  Packed address.
	 * @param array  $prefix  Prefix bytes.
	 * @param int    $bits    Prefix length in bits.
	 * @return bool
	 */
	private static function ipv6_has_prefix( $packed, $prefix, $bits ) {
		$full_bytes = (int) floor( $bits / 8 );
		$remaining   = $bits % 8;
		$required   = $full_bytes + ( $remaining > 0 ? 1 : 0 );
		if ( count( $prefix ) < $required ) {
			return false;
		}

		for ( $index = 0; $index < $full_bytes; $index++ ) {
			if ( ord( $packed[ $index ] ) !== $prefix[ $index ] ) {
				return false;
			}
		}

		if ( $remaining > 0 ) {
			$mask       = ( 0xff << ( 8 - $remaining ) ) & 0xff;
			$prefix_byte = $prefix[ $full_bytes ];
			if ( ( ord( $packed[ $full_bytes ] ) & $mask ) !== ( $prefix_byte & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Reject hostnames commonly used for local services and cloud metadata.
	 *
	 * @param string $host Normalized hostname.
	 * @return bool
	 */
	public static function is_dangerous_hostname( $host ) {
		$host = strtolower( rtrim( (string) $host, '.' ) );
		if ( '' === $host ) {
			return true;
		}

		$exact = array(
			'localhost',
			'metadata',
			'metadata.google.internal',
			'instance-data',
			'instance-data.ec2.internal',
			'169.254.169.254',
		);

		if ( in_array( $host, $exact, true ) ) {
			return true;
		}

		$suffixes = array(
			'.localhost',
			'.local',
			'.internal',
			'.lan',
			'.home',
			'.corp',
			'.home.arpa',
		);

		foreach ( $suffixes as $suffix ) {
			if ( $suffix === substr( $host, -strlen( $suffix ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reject known WordPress administration and private API paths.
	 *
	 * @param string $path   URL path.
	 * @param string $query  URL query string.
	 * @return bool
	 */
	public static function is_forbidden_target_path( $path, $query = '' ) {
		$path = strtolower( (string) $path );
		$path = preg_replace( '#/+#', '/', $path );
		$path = '/' . ltrim( $path, '/' );

		// Decode repeatedly for policy checks so encoded separators cannot hide
		// an administration or private-API path. The original encoded path is
		// still preserved in the normalized URL returned to the HTTP client.
		$decoded_path = $path;
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$next = rawurldecode( $decoded_path );
			if ( $next === $decoded_path ) {
				break;
			}
			$decoded_path = strtolower( $next );
		}
		$decoded_path = preg_replace( '#/+#', '/', $decoded_path );
		$decoded_path = self::remove_policy_dot_segments( $decoded_path );

		$forbidden_paths = array(
			'/wp-admin',
			'/wp-login.php',
			'/xmlrpc.php',
			'/wp-json',
		);

		foreach ( $forbidden_paths as $forbidden ) {
			if ( $path === $forbidden || 0 === strpos( $path, $forbidden . '/' ) ) {
				return true;
			}
			if ( $decoded_path === $forbidden || 0 === strpos( $decoded_path, $forbidden . '/' ) ) {
				return true;
			}
		}

		$admin_path_pattern = '~^/wp-admin(?:$|[/;.])~';
		if ( preg_match( $admin_path_pattern, $path ) || preg_match( $admin_path_pattern, $decoded_path ) ) {
			return true;
		}

		$query = strtolower( (string) $query );
		$decoded_query = rawurldecode( $query );
		if ( preg_match( '/(?:^|[?&;])(?:rest_route|action|login|logout)=/', $query ) || preg_match( '/(?:^|[?&;])(?:rest_route|action|login|logout)=/', $decoded_query ) ) {
			return true;
		}

		// Do not accept or forward URL-embedded credentials/tokens.
		$credential_parameter = '/(?:^|[?&;])[^=&#;]*(?:access[_-]?token|auth[_-]?token|token|secret|password|passwd|api[_-]?key|access[_-]?key|client[_-]?secret|session[_-]?id|signature)[^=&#;]*=/i';
		if ( preg_match( $credential_parameter, $query ) || preg_match( $credential_parameter, $decoded_query ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize dot segments for a policy check without changing the request
	 * URL that will be sent over the network.
	 *
	 * @param string $path Decoded path.
	 * @return string
	 */
	private static function remove_policy_dot_segments( $path ) {
		$segments = explode( '/', (string) $path );
		$result   = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $result );
				continue;
			}
			$result[] = $segment;
		}

		return '/' . implode( '/', $result );
	}

	/**
	 * Resolve a URI reference against a validated base URL.
	 *
	 * @param string $base     Base URL.
	 * @param string $relative URI reference, absolute URL, or protocol-relative URL.
	 * @return string|null
	 */
	public static function resolve_url( $base, $relative ) {
		$base     = trim( (string) $base );
		$relative = trim( (string) $relative );

		if ( '' === $base || '' === $relative || preg_match( '/[\x00-\x20\x7f]/', $relative ) || false !== strpos( $relative, '\\' ) ) {
			return null;
		}

		if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $relative ) ) {
			return $relative;
		}

		$base_parts = self::parse_url( $base );
		if ( ! is_array( $base_parts ) || empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) $base_parts['scheme'] );
		if ( 0 === strpos( $relative, '//' ) ) {
			return $scheme . ':' . $relative;
		}

		$authority_host = (string) $base_parts['host'];
		if ( false !== filter_var( $authority_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$authority_host = '[' . $authority_host . ']';
		}
		$authority = $authority_host;
		if ( isset( $base_parts['port'] ) ) {
			$authority .= ':' . (int) $base_parts['port'];
		}

		$base_path = '/' . ltrim( isset( $base_parts['path'] ) && '' !== $base_parts['path'] ? $base_parts['path'] : '/', '/' );

		$fragment = '';
		$hash_position = strpos( $relative, '#' );
		if ( false !== $hash_position ) {
			$fragment = substr( $relative, $hash_position );
			$relative = substr( $relative, 0, $hash_position );
		}

		$query           = '';
		$has_query       = false;
		$query_position  = strpos( $relative, '?' );
		if ( false !== $query_position ) {
			$has_query = true;
			$query     = substr( $relative, $query_position );
			$relative  = substr( $relative, 0, $query_position );
		}

		if ( '' === $relative ) {
			$base_query = $has_query ? $query : ( isset( $base_parts['query'] ) ? '?' . $base_parts['query'] : '' );
			return $scheme . '://' . $authority . $base_path . $base_query . $fragment;
		}

		if ( '/' === $relative[0] ) {
			$path = $relative;
		} else {
			$last_slash = strrpos( $base_path, '/' );
			$directory  = false === $last_slash ? '/' : substr( $base_path, 0, $last_slash + 1 );
			$path       = $directory . $relative;
		}

		$path = self::remove_dot_segments( $path );

		return $scheme . '://' . $authority . $path . $query . $fragment;
	}

	/**
	 * Remove dot segments from a URL path according to RFC 3986.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	private static function remove_dot_segments( $path ) {
		$segments = explode( '/', $path );
		$result   = array();

		foreach ( $segments as $segment ) {
			if ( '.' === $segment || '' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $result );
				continue;
			}

			$result[] = $segment;
		}

		$normalized = '/' . implode( '/', $result );
		return '/' === substr( $path, -1 ) && '/' !== $normalized ? $normalized . '/' : $normalized;
	}

	/**
	 * Normalize and cap untrusted text used in the analysis result.
	 *
	 * @param mixed $value      Input value.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	public static function clean_text( $value, $max_length = 1000 ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$value = wp_check_invalid_utf8( $value, true );
		}

		$value = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', ' ', $value );
		$value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( $value );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );

		return self::truncate_text( $value, $max_length );
	}

	/**
	 * Truncate text without requiring mbstring.
	 *
	 * @param string $value      Text.
	 * @param int    $max_length Maximum length.
	 * @return string
	 */
	public static function truncate_text( $value, $max_length = 1000 ) {
		$max_length = max( 1, absint( $max_length ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_length, 'UTF-8' );
		}

		$truncated = substr( $value, 0, $max_length );
		return function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( $truncated, true ) : $truncated;
	}

	/**
	 * Log only a small, explicitly controlled set of development fields.
	 *
	 * @param string               $event   Event name.
	 * @param array<string, mixed> $context Safe scalar context.
	 * @return void
	 */
	public static function log_event( $event, $context = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		$allowed = array( 'host', 'status', 'code', 'duration_ms', 'count', 'redirects', 'reason' );
		$safe    = array();
		foreach ( $allowed as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$safe[ $key ] = self::clean_text( $context[ $key ], 120 );
			}
		}

		$payload = function_exists( 'wp_json_encode' ) ? wp_json_encode( $safe ) : json_encode( $safe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		error_log( '[ReplicaForge] ' . self::clean_text( $event, 80 ) . ' ' . $payload ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
