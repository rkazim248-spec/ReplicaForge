<?php
/**
 * Bounded, security-aware external stylesheet loading.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers and safely fetches a small number of linked stylesheets.
 *
 * This loader does not recursively follow @import URLs and never fetches
 * images, fonts, scripts, or arbitrary page resources.
 */
final class Stylesheet_Loader {

	/**
	 * Safe HTTP client.
	 *
	 * @var Http_Client
	 */
	private $http_client;

	/**
	 * Constructor.
	 *
	 * @param Http_Client|null $http_client Safe HTTP client.
	 */
	public function __construct( $http_client = null ) {
		$this->http_client = $http_client instanceof Http_Client ? $http_client : new Http_Client();
	}

	/**
	 * Discover stylesheet links from a document element list.
	 *
	 * @param array<int, \DOMElement> $elements  Sanitized DOM elements.
	 * @param string                   $base_url  Final page URL.
	 * @return array<int, array<string, mixed>>
	 */
	public function discover( $elements, $base_url ) {
		$stylesheets = array();
		$seen        = array();

		foreach ( $elements as $element ) {
			if ( ! $element instanceof \DOMElement || 'link' !== strtolower( $element->tagName ) ) {
				continue;
			}

			$rel = strtolower( trim( $element->getAttribute( 'rel' ) ) );
			if ( false === strpos( $rel, 'stylesheet' ) ) {
				continue;
			}

			$media = strtolower( trim( $element->getAttribute( 'media' ) ) );
			if ( '' !== $media && false === strpos( $media, 'all' ) && false === strpos( $media, 'screen' ) ) {
				continue;
			}

			$url = $this->normalize_resource_url( $base_url, $element->getAttribute( 'href' ) );
			if ( null === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;

			$stylesheets[] = array(
				'url'      => $url,
				'media'    => $media,
				'source'   => 'link',
				'position' => count( $stylesheets ),
			);
			if ( count( $stylesheets ) >= Analysis_Limits::MAX_STYLESHEETS ) {
				break;
			}
		}

		return $stylesheets;
	}

	/**
	 * Fetch discovered stylesheets under strict aggregate limits.
	 *
	 * @param array<int, array<string, mixed>> $stylesheets Discovered stylesheets.
	 * @return array<string, mixed>
	 */
	public function load( $stylesheets ) {
		$stylesheets = is_array( $stylesheets ) ? $stylesheets : array();
		$loaded    = array();
		$warnings  = array();
		$bytes     = 0;
		$started   = microtime( true );
		$requested = 0;

		foreach ( $stylesheets as $stylesheet ) {
			if ( count( $loaded ) >= Analysis_Limits::MAX_STYLESHEETS ) {
				$warnings[] = array( 'code' => 'stylesheet_limit', 'message' => 'The stylesheet limit was reached.' );
				break;
			}
			if ( $bytes >= Analysis_Limits::MAX_TOTAL_CSS_SIZE ) {
				$warnings[] = array( 'code' => 'css_size_limit', 'message' => 'The aggregate CSS size limit was reached.' );
				break;
			}
			if ( ( microtime( true ) - $started ) >= Analysis_Limits::MAX_RESOURCE_SECONDS ) {
				$warnings[] = array( 'code' => 'resource_time_limit', 'message' => 'The external resource time limit was reached.' );
				break;
			}

			$url = isset( $stylesheet['url'] ) ? $stylesheet['url'] : '';
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$remaining = Analysis_Limits::MAX_RESOURCE_SECONDS - ( microtime( true ) - $started );
			if ( $remaining <= 1 ) {
				$warnings[] = array( 'code' => 'resource_time_limit', 'message' => 'The external resource time limit was reached.' );
				break;
			}
			$requested++;
			$response = $this->http_client->fetch_stylesheet( $url, $remaining );
			if ( empty( $response['success'] ) || ! isset( $response['body'] ) || ! is_string( $response['body'] ) ) {
				$error = isset( $response['error'] ) && is_array( $response['error'] ) ? $response['error'] : array();
				$warnings[] = array(
					'code'    => isset( $error['code'] ) ? sanitize_key( $error['code'] ) : 'stylesheet_failed',
					'message' => 'An external stylesheet could not be analyzed.',
					'url'     => $url,
				);
				continue;
			}

			$body = $response['body'];
			if ( $bytes + strlen( $body ) > Analysis_Limits::MAX_TOTAL_CSS_SIZE ) {
				$warnings[] = array( 'code' => 'css_size_limit', 'message' => 'The aggregate CSS size limit was reached.' );
				break;
			}
			$bytes += strlen( $body );
			$loaded[] = array(
				'url'      => $url,
				'final_url' => isset( $response['final_url'] ) ? $response['final_url'] : $url,
				'media'    => isset( $stylesheet['media'] ) ? $stylesheet['media'] : '',
				'bytes'    => strlen( $body ),
				'status'   => isset( $response['status'] ) ? absint( $response['status'] ) : 0,
				'css'      => $body,
			);
		}

		return array(
			'stylesheets' => $loaded,
			'warnings'    => $warnings,
			'stats'       => array(
				'requested' => $requested,
				'loaded'    => count( $loaded ),
				'bytes'     => $bytes,
			),
		);
	}

	/**
	 * Normalize a discovered resource URL without making a request.
	 *
	 * The HTTP client performs the authoritative DNS/IP validation before any
	 * stylesheet request is made.
	 *
	 * @param string $base_url Final page URL.
	 * @param string $raw_url  Raw href.
	 * @return string|null
	 */
	private function normalize_resource_url( $base_url, $raw_url ) {
		$raw_url = trim( (string) $raw_url );
		if ( '' === $raw_url || strlen( $raw_url ) > 2048 ) {
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
}
