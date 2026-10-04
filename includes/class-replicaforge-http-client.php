<?php
/**
 * Redirect-aware, size-limited HTTP client for public resources.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches one public page or stylesheet without following redirects implicitly.
 *
 * Every URL is validated before the request. Redirects are handled one at a
 * time so each destination receives the same SSRF and protocol checks as the
 * original submission.
 */
final class Http_Client {

	/**
	 * URL validator.
	 *
	 * @var Url_Validator
	 */
	private $validator;

	/**
	 * Shared security helper.
	 *
	 * @var Security
	 */
	private $security;

	/**
	 * Constructor.
	 *
	 * @param Url_Validator|null $validator URL validator.
	 * @param Security|null      $security  Shared security helper.
	 */
	public function __construct( $validator = null, $security = null ) {
		$this->validator = $validator instanceof Url_Validator ? $validator : new Url_Validator( $security );
		$this->security  = $security instanceof Security ? $security : new Security();
	}

	/**
	 * Fetch a validated public frontend URL.
	 *
	 * @param string $url User-supplied URL.
	 * @return array<string, mixed> Response envelope.
	 */
	public function fetch( $url ) {
		return $this->fetch_with_policy(
			$url,
			array(
				'max_size'     => Security::MAX_RESPONSE_SIZE,
				'timeout'      => Security::REQUEST_TIMEOUT,
				'allowed_types' => array( 'text/html', 'application/xhtml+xml' ),
				'accept'       => 'text/html,application/xhtml+xml;q=0.9,text/plain;q=0.5',
				'missing_type' => 'html',
				'purpose'      => 'page',
			)
		);
	}

	/**
	 * Fetch one external stylesheet using the same redirect and SSRF policy.
	 *
	 * @param string    $url     Stylesheet URL.
	 * @param int|float|null $timeout Optional remaining timeout.
	 * @return array<string, mixed> Response envelope.
	 */
	public function fetch_stylesheet( $url, $timeout = null ) {
		$stylesheet_timeout = Analysis_Limits::STYLESHEET_TIMEOUT;
		if ( is_numeric( $timeout ) ) {
			$stylesheet_timeout = min( $stylesheet_timeout, max( 1, (int) ceil( $timeout ) ) );
		}
		return $this->fetch_with_policy(
			$url,
			array(
				'max_size'     => Analysis_Limits::MAX_STYLESHEET_SIZE,
				'timeout'      => $stylesheet_timeout,
				'allowed_types' => array( 'text/css', 'text/plain', 'application/octet-stream' ),
				'accept'       => 'text/css,text/plain;q=0.8',
				'missing_type' => 'css',
				'purpose'      => 'stylesheet',
			)
		);
	}

	/**
	 * Fetch one validated image for optional Phase 4 asset importing.
	 *
	 * The same redirect, SSRF, size, and timeout policy is applied. Only a small
	 * allow-list of raster media types is accepted, and nothing is executed.
	 *
	 * @param string               $url     Image URL.
	 * @param array<string, mixed> $options Optional `max_size`, `timeout`, and `max_redirects`.
	 * @return array<string, mixed> Response envelope.
	 */
	public function fetch_media( $url, array $options = array() ) {
		$max_size = isset( $options['max_size'] ) ? absint( $options['max_size'] ) : 3145728;
		$timeout  = isset( $options['timeout'] ) ? max( 1, absint( $options['timeout'] ) ) : 10;
		$max_size = max( 1, min( $max_size, Security::MAX_RESPONSE_SIZE ) );
		$timeout  = max( 1, min( $timeout, Security::REQUEST_TIMEOUT ) );

		return $this->fetch_with_policy(
			$url,
			array(
				'max_size'      => $max_size,
				'timeout'       => $timeout,
				'allowed_types' => array( 'image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp' ),
				'accept'        => 'image/webp,image/png,image/jpeg,image/gif;q=0.8,*/*;q=0.1',
				'missing_type'  => 'image',
				'max_redirects' => isset( $options['max_redirects'] ) ? max( 0, absint( $options['max_redirects'] ) ) : 2,
				'purpose'       => 'asset',
			)
		);
	}

	/**
	 * Execute a bounded, redirect-aware request under a resource policy.
	 *
	 * @param string               $url    Initial URL.
	 * @param array<string, mixed> $policy Resource policy.
	 * @return array<string, mixed>
	 */
	private function fetch_with_policy( $url, array $policy ) {
		$current_url = is_string( $url ) ? trim( $url ) : '';
		$started_at  = microtime( true );
		$redirects   = 0;
		$visited     = array();
		$max_size    = isset( $policy['max_size'] ) ? absint( $policy['max_size'] ) : Security::MAX_RESPONSE_SIZE;
		$timeout     = isset( $policy['timeout'] ) ? max( 1, absint( $policy['timeout'] ) ) : Security::REQUEST_TIMEOUT;
		$max_redirs  = isset( $policy['max_redirects'] ) ? max( 0, absint( $policy['max_redirects'] ) ) : Security::MAX_REDIRECTS;

		while ( true ) {
			$remaining = $timeout - ( microtime( true ) - $started_at );
			if ( $remaining <= 0 ) {
				return $this->error( 'request_timeout', 'The request timed out.', 408 );
			}

			$validation = $this->validator->validate( $current_url );
			if ( empty( $validation['success'] ) ) {
				return $validation;
			}

			$current_url = $validation['url'];
			if ( isset( $visited[ $current_url ] ) ) {
				return $this->error( 'redirect_loop', $this->message( $policy, 'The website redirected too many times.', 'The resource redirected too many times.' ), 502 );
			}
			$visited[ $current_url ] = true;

			$remaining = $timeout - ( microtime( true ) - $started_at );
			if ( $remaining < 1 ) {
				return $this->error( 'request_timeout', 'The request timed out.', 408 );
			}
			$request_timeout = (int) min( $timeout, max( 1, floor( $remaining ) ) );
			$args             = array(
				'timeout'             => $request_timeout,
				'redirection'         => 0,
				'limit_response_size' => $max_size + 1,
				'reject_unsafe_urls'  => true,
				'sslverify'           => true,
				'user-agent'          => 'ReplicaForge/' . ( defined( 'REPLICAFORGE_VERSION' ) ? REPLICAFORGE_VERSION : '0.3.0' ),
				'headers'             => array(
					'Accept'          => isset( $policy['accept'] ) ? $policy['accept'] : 'text/html',
					'Accept-Encoding' => 'identity',
				),
			);

			$response = wp_safe_remote_get( $current_url, $args );
			if ( is_wp_error( $response ) ) {
				Security::log_event( 'http_request_failed', array( 'host' => $validation['host'] ) );
				return $this->error( 'request_failed', 'The website could not be reached.', 502 );
			}

			$status         = (int) wp_remote_retrieve_response_code( $response );
			$location       = wp_remote_retrieve_header( $response, 'location' );
			$content_type   = wp_remote_retrieve_header( $response, 'content-type' );
			$content_length = wp_remote_retrieve_header( $response, 'content-length' );

			if ( is_numeric( $content_length ) && (int) $content_length > $max_size ) {
				return $this->error( 'response_too_large', $this->message( $policy, 'The page is too large to analyze.', 'The resource is too large to analyze.' ), 413 );
			}

			if ( $status >= 300 && $status < 400 ) {
				if ( $redirects >= $max_redirs ) {
					return $this->error( 'too_many_redirects', $this->message( $policy, 'The website redirected too many times.', 'The resource redirected too many times.' ), 502 );
				}

				if ( ! is_string( $location ) || '' === trim( $location ) ) {
					return $this->error( 'invalid_redirect', $this->message( $policy, 'The website returned an invalid redirect.', 'The resource returned an invalid redirect.' ), 502 );
				}

				$next_url = $this->security->resolve_url( $current_url, trim( $location ) );
				if ( null === $next_url ) {
					return $this->error( 'invalid_redirect', $this->message( $policy, 'The website returned an invalid redirect.', 'The resource returned an invalid redirect.' ), 502 );
				}

				$redirects++;
				$current_url = $next_url;
				Security::log_event( 'redirect_validated', array( 'host' => $validation['host'], 'redirects' => $redirects ) );
				continue;
			}

			if ( $status < 200 || $status >= 300 ) {
				if ( in_array( $status, array( 408, 504 ), true ) ) {
					return $this->error( 'request_timeout', 'The request timed out.', 408 );
				}

				return $this->error( 'http_status', $this->message( $policy, 'The website returned an unexpected response.', 'The resource returned an unexpected response.' ), 502 );
			}

			$body = wp_remote_retrieve_body( $response );
			if ( ! is_string( $body ) || '' === trim( $body ) ) {
				return $this->error( 'empty_response', $this->message( $policy, 'The website returned an unexpected response.', 'The resource returned an unexpected response.' ), 502 );
			}

			if ( strlen( $body ) > $max_size ) {
				return $this->error( 'response_too_large', $this->message( $policy, 'The page is too large to analyze.', 'The resource is too large to analyze.' ), 413 );
			}

			$content_type = is_string( $content_type ) ? strtolower( $content_type ) : '';
			if ( ! $this->is_allowed_content_type( $content_type, $body, $policy ) ) {
				return $this->error( 'unsupported_response', $this->message( $policy, 'The website returned an unexpected response.', 'The resource returned an unsupported response.' ), 415 );
			}

			Security::log_event(
				'fetch_complete',
				array(
					'host'      => $validation['host'],
					'status'    => $status,
					'redirects' => $redirects,
					'count'     => strlen( $body ),
				)
			);

			return array(
				'success'      => true,
				'status'       => $status,
				'body'         => $body,
				'final_url'    => $current_url,
				'redirects'    => $redirects,
				'content_type' => $content_type,
			);
		}
	}

	/**
	 * Check a response content type against the resource policy.
	 *
	 * @param string               $content_type Response content type.
	 * @param string               $body         Response body.
	 * @param array<string, mixed> $policy       Resource policy.
	 * @return bool
	 */
	private function is_allowed_content_type( $content_type, $body, array $policy ) {
		$allowed = isset( $policy['allowed_types'] ) && is_array( $policy['allowed_types'] ) ? $policy['allowed_types'] : array();
		foreach ( $allowed as $type ) {
			if ( '' !== $content_type && false !== strpos( $content_type, $type ) ) {
				if ( 'application/octet-stream' === $type && ! $this->looks_like_css( $body ) ) {
					continue;
				}
				return true;
			}
		}

		if ( '' === $content_type ) {
			if ( ! isset( $policy['missing_type'] ) ) {
				return false;
			}
			if ( 'html' === $policy['missing_type'] ) {
				return $this->looks_like_html( $body );
			}
			if ( 'css' === $policy['missing_type'] ) {
				return $this->looks_like_css( $body );
			}
			if ( 'image' === $policy['missing_type'] ) {
				return $this->looks_like_image( $body );
			}
			return false;
		}

		return false;
	}

	/**
	 * Determine whether a response body carries a raster image signature.
	 *
	 * @param string $body Response body.
	 * @return bool
	 */
	private function looks_like_image( $body ) {
		if ( ! is_string( $body ) || strlen( $body ) < 12 ) {
			return false;
		}
		$sample = substr( $body, 0, 64 );
		if ( "\x89PNG\r\n\x1a\n" === substr( $sample, 0, 8 ) ) {
			return true;
		}
		if ( "\xff\xd8\xff" === substr( $sample, 0, 3 ) ) {
			return true;
		}
		if ( 'GIF87a' === substr( $sample, 0, 6 ) || 'GIF89a' === substr( $sample, 0, 6 ) ) {
			return true;
		}
		if ( 'RIFF' === substr( $sample, 0, 4 ) && 'WEBP' === substr( $sample, 8, 4 ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Determine whether a response without a content type looks like HTML.
	 *
	 * @param string $body Response body.
	 * @return bool
	 */
	private function looks_like_html( $body ) {
		$prefix = strtolower( ltrim( substr( $body, 0, 1024 ) ) );
		return ( isset( $prefix[0] ) && '<' === $prefix[0] )
			|| 0 === strpos( $prefix, '<!doctype' )
			|| 0 === strpos( $prefix, '<html' )
			|| false !== strpos( $prefix, '<head' )
			|| false !== strpos( $prefix, '<body' );
	}

	/**
	 * Determine whether a response looks like CSS.
	 *
	 * @param string $body Response body.
	 * @return bool
	 */
	private function looks_like_css( $body ) {
		$sample = strtolower( substr( $body, 0, 4096 ) );
		return false !== strpos( $sample, '@media' )
			|| false !== strpos( $sample, '@import' )
			|| false !== strpos( $sample, '{' )
			|| ( false !== strpos( $sample, ':' ) && false !== strpos( $sample, ';' ) );
	}

	/**
	 * Select a policy-specific user-facing message.
	 *
	 * @param array<string, mixed> $policy    Resource policy.
	 * @param string               $page      Page message.
	 * @param string               $resource  Resource message.
	 * @return string
	 */
	private function message( $policy, $page, $resource ) {
		return isset( $policy['purpose'] ) && 'page' !== $policy['purpose'] ? $resource : $page;
	}

	/**
	 * Create a safe HTTP error envelope.
	 *
	 * @param string $code    Error code.
	 * @param string $message User-facing message.
	 * @param int    $status  Error status.
	 * @return array<string, mixed>
	 */
	private function error( $code, $message, $status ) {
		return $this->security->error( $code, $message, $status );
	}
}
