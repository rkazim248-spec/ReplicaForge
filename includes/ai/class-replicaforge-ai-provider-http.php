<?php
/**
 * Shared safe HTTP transport for optional AI providers.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Provider adapters use fixed provider endpoints and server-side credentials.
 *
 * This class does not accept a user-controlled endpoint, follow redirects, or
 * include credentials in errors or logs.
 */
abstract class Ai_Provider_HTTP implements Ai_Provider_Contract {

	/**
	 * Internal provider settings.
	 *
	 * @var array<string, mixed>
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $settings Internal settings.
	 */
	public function __construct( array $settings = array() ) {
		$this->settings = $settings;
	}

	/**
	 * Return what this provider can do.
	 *
	 * Implemented once on the shared base, because both shipped adapters speak the
	 * same HTTP JSON shape and so return the same *shape* of answer with different
	 * values. A subclass that supports something extra overrides the one key it
	 * differs on.
	 *
	 * @return array<string, mixed>
	 */
	public function capabilities() {
		return Ai_Capabilities::resolve( $this->get_id(), $this->model() );
	}

	/**
	 * Return configured timeout.
	 *
	 * @return int
	 */
	protected function timeout() {
		$timeout = isset( $this->settings['timeout'] ) ? (int) $this->settings['timeout'] : Ai_Limits::DEFAULT_TIMEOUT;
		return max( Ai_Limits::MIN_TIMEOUT, min( Ai_Limits::MAX_TIMEOUT, $timeout ) );
	}

	/**
	 * Return configured model.
	 *
	 * @return string
	 */
	protected function model() {
		return isset( $this->settings['model'] ) ? trim( (string) $this->settings['model'] ) : '';
	}

	/**
	 * Return the server-side API key.
	 *
	 * @return string
	 */
	protected function api_key() {
		return isset( $this->settings['api_key'] ) ? trim( (string) $this->settings['api_key'] ) : '';
	}

	/**
	 * Send a JSON request to a fixed HTTPS provider endpoint.
	 *
	 * @param string               $url     Fixed endpoint.
	 * @param array<string, mixed> $body    Request body.
	 * @param array<string, string> $headers Extra headers.
	 * @return array<string, mixed>
	 */
	protected function post_json( $url, array $body, array $headers = array() ) {
		if ( '' === $this->api_key() || '' === $this->model() ) {
			return $this->error( 'provider_not_configured', 'The AI provider is not fully configured.' );
		}
		if ( ! function_exists( 'wp_safe_remote_post' ) ) {
			return $this->error( 'provider_transport_unavailable', 'The AI provider transport is unavailable.' );
		}
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $body ) : json_encode( $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return $this->error( 'provider_request_invalid', 'The AI request could not be encoded.' );
		}
		$request_headers = array_merge( array( 'Accept' => 'application/json', 'Content-Type' => 'application/json' ), $headers );
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'             => $this->timeout(),
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'sslverify'           => true,
				'limit_response_size' => Ai_Limits::MAX_OUTPUT_BYTES + 4096,
				'headers'             => $request_headers,
				'body'                => $encoded,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $this->error( 'provider_unavailable', 'The AI provider could not be reached.' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body_text = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body_text ) || '' === trim( $body_text ) ) {
			return $this->error( 'provider_empty_response', 'The AI provider returned an empty response.' );
		}
		if ( strlen( $body_text ) > Ai_Limits::MAX_OUTPUT_BYTES + 4096 ) {
			return $this->error( 'provider_response_too_large', 'The AI provider response exceeded the configured limit.' );
		}
		$decoded = json_decode( $body_text, true );
		if ( ! is_array( $decoded ) ) {
			return $this->error( 'provider_invalid_response', 'The AI provider returned an invalid response.' );
		}
		if ( $status < 200 || $status >= 300 ) {
			return $this->error( 'provider_http_error', 'The AI provider rejected the request.' );
		}
		return array( 'success' => true, 'status' => $status, 'data' => $decoded );
	}

	/**
	 * Create a safe provider error envelope.
	 *
	 * @param string $code Machine code.
	 * @param string $message Safe message.
	 * @return array<string, mixed>
	 */
	protected function error( $code, $message ) {
		return array( 'success' => false, 'error' => array( 'code' => sanitize_key( $code ), 'message' => (string) $message ) );
	}

	/**
	 * Extract text parts from a provider message value.
	 *
	 * @param mixed $content Content.
	 * @return string
	 */
	protected function extract_text( $content ) {
		if ( is_string( $content ) ) {
			return $content;
		}
		if ( is_array( $content ) ) {
			$text = '';
			foreach ( $content as $part ) {
				if ( is_string( $part ) ) {
					$text .= $part;
				} elseif ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
			return $text;
		}
		return '';
	}
}
