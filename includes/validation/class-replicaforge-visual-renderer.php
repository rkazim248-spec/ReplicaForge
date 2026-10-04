<?php
/**
 * Rendered visual capture for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Optionally captures rendered screenshots through an external render provider.
 *
 * A WordPress plugin cannot ship a headless browser, and rendering the analyzed
 * page in-process would execute untrusted site content. ReplicaForge therefore
 * treats rendered capture as an optional, explicitly configured capability. When
 * no provider is configured, the validation engine reports that rendered
 * comparison is unavailable and continues with the structural and design-system
 * levels. Nothing is ever faked.
 *
 * The generated draft is captured by sending the document's own rendered markup
 * and stylesheet URLs to the provider, so the draft never needs to be published
 * or exposed on a public URL.
 */
final class Visual_Renderer {

	/**
	 * Option holding renderer configuration.
	 */
	const OPTION = 'replicaforge_validation_renderer';

	/**
	 * Shared security helper.
	 *
	 * @var Security
	 */
	private $security;

	/**
	 * Constructor.
	 *
	 * @param Security|null $security Optional shared security helper.
	 */
	public function __construct( $security = null ) {
		$this->security = $security instanceof Security ? $security : new Security();
	}

	/**
	 * Return the stored renderer configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function settings() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array_merge(
			array(
				'enabled'   => false,
				'endpoint'  => '',
				'token'     => '',
				'timeout'   => Validation_Limits::RENDER_TIMEOUT,
				'max_width' => 0,
			),
			$stored
		);
	}

	/**
	 * Store the renderer configuration after validating it.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Stored settings, or an error array.
	 */
	public function save_settings( array $input ) {
		$endpoint = isset( $input['endpoint'] ) && is_string( $input['endpoint'] ) ? trim( $input['endpoint'] ) : '';
		$error    = '';

		if ( '' !== $endpoint ) {
			$validation = $this->validate_endpoint( $endpoint );
			if ( empty( $validation['success'] ) ) {
				$error = isset( $validation['error']['message'] ) ? (string) $validation['error']['message'] : __( 'The render endpoint could not be validated.', 'replicaforge' );
			} else {
				$endpoint = (string) $validation['url'];
			}
		}

		$settings = array(
			'enabled'   => ! empty( $input['enabled'] ) && '' !== $endpoint,
			'endpoint'  => $endpoint,
			'token'     => isset( $input['token'] ) && is_string( $input['token'] ) ? substr( trim( $input['token'] ), 0, 200 ) : '',
			'timeout'   => isset( $input['timeout'] ) && is_numeric( $input['timeout'] ) ? max( 5, min( 60, (int) $input['timeout'] ) ) : Validation_Limits::RENDER_TIMEOUT,
			'max_width' => isset( $input['max_width'] ) && is_numeric( $input['max_width'] ) ? max( 0, min( 4000, (int) $input['max_width'] ) ) : 0,
		);

		if ( '' !== $error ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'invalid_render_endpoint',
					'message' => $error,
				),
			);
		}

		update_option( self::OPTION, $settings, false );
		return array(
			'success'  => true,
			'settings' => $this->public_settings(),
		);
	}

	/**
	 * Return a safe, public view of the configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function public_settings() {
		$settings = $this->settings();
		return array(
			'enabled'    => ! empty( $settings['enabled'] ),
			'endpoint'   => (string) $settings['endpoint'],
			'has_token'  => '' !== (string) $settings['token'],
			'timeout'    => (int) $settings['timeout'],
			'max_width'  => (int) $settings['max_width'],
			'configured' => '' !== (string) $settings['endpoint'],
		);
	}

	/**
	 * Return the capabilities of the rendering pipeline.
	 *
	 * @return array<string, mixed>
	 */
	public function capabilities() {
		$settings = $this->settings();
		$library  = $this->image_library();
		return array(
			'provider_configured' => ! empty( $settings['enabled'] ) && '' !== (string) $settings['endpoint'],
			'provider_validated'  => '' !== (string) $settings['endpoint'] && null !== $this->validate_endpoint( (string) $settings['endpoint'] ),
			'image_library'       => $library,
			'diff_available'      => '' !== $library,
			'level_3_available'   => ! empty( $settings['enabled'] ) && '' !== (string) $settings['endpoint'] && '' !== $library,
		);
	}

	/**
	 * Return the available image library name.
	 *
	 * @return string
	 */
	public function image_library() {
		if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
			return 'imagick';
		}
		if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatefromstring' ) ) {
			return 'gd';
		}
		return '';
	}

	/**
	 * Capture one screenshot.
	 *
	 * @param array<string, mixed> $request Render request.
	 * @return array<string, mixed>
	 */
	public function capture( array $request ) {
		$settings = $this->settings();
		if ( empty( $settings['enabled'] ) || '' === (string) $settings['endpoint'] ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'render_provider_unavailable',
					'message' => __( 'Rendered capture is unavailable because no render provider is configured. Structural and design-system comparison are unaffected.', 'replicaforge' ),
				),
			);
		}

		$validation = $this->validate_endpoint( (string) $settings['endpoint'] );
		if ( empty( $validation['success'] ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'render_endpoint_invalid',
					'message' => __( 'The configured render endpoint is not a valid public HTTPS endpoint.', 'replicaforge' ),
				),
			);
		}

		$viewport = isset( $request['viewport'] ) && is_array( $request['viewport'] ) ? $request['viewport'] : array();
		$width    = isset( $viewport['width'] ) && is_numeric( $viewport['width'] ) ? (int) $viewport['width'] : 1440;
		$height   = isset( $viewport['height'] ) && is_numeric( $viewport['height'] ) ? (int) $viewport['height'] : 900;
		$max      = (int) $settings['max_width'];
		if ( $max > 0 ) {
			$width  = min( $width, $max );
			$height = (int) round( $height * ( $width / max( 1, isset( $viewport['width'] ) ? (int) $viewport['width'] : 1440 ) ) );
		}
		$width  = max( 320, min( Validation_Limits::MAX_SCREENSHOT_EDGE, $width ) );
		$height = max( 240, min( Validation_Limits::MAX_SCREENSHOT_EDGE, $height ) );

		$payload = array(
			'url'      => isset( $request['url'] ) ? (string) $request['url'] : '',
			'html'     => isset( $request['html'] ) && is_string( $request['html'] ) ? $this->bounded_html( $request['html'] ) : '',
			'css_urls' => $this->bounded_urls( isset( $request['css_urls'] ) ? $request['css_urls'] : array() ),
			'viewport' => array(
				'width'     => $width,
				'height'    => $height,
				'deviceScaleFactor' => 1,
			),
			'output'   => array(
				'format' => 'png',
				'fullPage' => ! empty( $request['full_page'] ),
			),
			'block'    => array( 'scripts' => true, 'media' => true ),
		);

		// Phase 13. Render stabilization, forwarded to the provider rather than
		// assumed. §8 requires a stabilization process, and the provider is the only
		// place it can happen — a screenshot cannot be stabilized after the fact,
		// because by then the animation has already been captured mid-frame.
		//
		// Additive only: the key is absent unless a caller asks for it, so a Phase 5
		// validation run sends exactly the payload it sent before. The `wait_ms` value
		// is bounded here because an unbounded wait is a denial-of-service lever aimed
		// at whoever is hosting the renderer, and a provider that waits for minutes on
		// an animation that never ends is worse than one that captures early.
		if ( isset( $request['stabilize'] ) && is_array( $request['stabilize'] ) && array() !== $request['stabilize'] ) {
			$stabilize = $request['stabilize'];
			$payload['stabilize'] = array(
				'wait_for'     => array_values( array_map( 'strval', (array) ( $stabilize['wait_for'] ?? array( 'dom', 'network_idle', 'images' ) ) ) ),
				'wait_ms'      => max( 0, min( 10000, (int) ( $stabilize['wait_ms'] ?? 1500 ) ) ),
				'animations'   => ! isset( $stabilize['animations'] ) || ! empty( $stabilize['animations'] ),
				'lazy_load'    => ! isset( $stabilize['lazy_load'] ) || ! empty( $stabilize['lazy_load'] ),
				'scroll_to'    => max( 0, min( 50, (int) ( $stabilize['scroll_to'] ?? 0 ) ) ),
			);
		}

		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'render_payload_failed',
					'message' => __( 'The render request could not be prepared.', 'replicaforge' ),
				),
			);
		}

		$args = array(
			'method'              => 'POST',
			'timeout'             => (int) $settings['timeout'],
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'sslverify'           => true,
			'limit_response_size' => Validation_Limits::MAX_SCREENSHOT_BYTES + 4096,
			'user-agent'          => 'ReplicaForge/' . ( defined( 'REPLICAFORGE_VERSION' ) ? REPLICAFORGE_VERSION : '0.0.0' ) . ' (validation)',
			'headers'             => array(
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
				'Accept-Encoding' => 'identity',
			),
			'body'                => $encoded,
		);
		if ( '' !== (string) $settings['token'] ) {
			$args['headers']['Authorization'] = 'Bearer ' . preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $settings['token'] );
		}

		$response = wp_safe_remote_post( (string) $validation['url'], $args );
		if ( is_wp_error( $response ) ) {
			Security::log_event( 'validation_render_failed', array( 'code' => 'request_failed', 'reason' => 'render' ) );
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'render_request_failed',
					'message' => __( 'The render provider could not be reached.', 'replicaforge' ),
				),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			Security::log_event( 'validation_render_failed', array( 'code' => 'status', 'status' => $status, 'reason' => 'render' ) );
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'render_request_rejected',
					'message' => __( 'The render provider returned an unexpected response.', 'replicaforge' ),
				),
			);
		}

		$body   = (string) wp_remote_retrieve_body( $response );
		$binary = $this->extract_image( $body, (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( null === $binary ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => 'render_response_unusable',
					'message' => __( 'The render provider did not return a usable image.', 'replicaforge' ),
				),
			);
		}

		return array(
			'success'  => true,
			'image'    => $binary,
			'bytes'    => strlen( $binary ),
			'viewport' => array(
				'width'  => $width,
				'height' => $height,
			),
		);
	}

	/**
	 * Validate the render endpoint with the existing URL policy.
	 *
	 * @param string $endpoint Raw endpoint.
	 * @return array<string, mixed>
	 */
	private function validate_endpoint( $endpoint ) {
		$endpoint = trim( (string) $endpoint );
		if ( '' === $endpoint ) {
			return Security::error( 'render_endpoint_missing', __( 'No render endpoint is configured.', 'replicaforge' ), 400 );
		}
		if ( 0 !== stripos( $endpoint, 'https://' ) ) {
			return Security::error( 'render_endpoint_insecure', __( 'The render endpoint must use HTTPS.', 'replicaforge' ), 400 );
		}
		$validator = new Url_Validator( $this->security );
		$result    = $validator->validate( $endpoint );
		if ( empty( $result['success'] ) ) {
			return Security::error(
				isset( $result['error']['code'] ) ? (string) $result['error']['code'] : 'render_endpoint_blocked',
				__( 'The render endpoint was rejected by the public-target policy.', 'replicaforge' ),
				403
			);
		}
		return array(
			'success' => true,
			'url'     => (string) $result['url'],
			'host'    => (string) $result['host'],
		);
	}

	/**
	 * Bound the markup sent to the render provider.
	 *
	 * @param string $html Rendered markup.
	 * @return string
	 */
	private function bounded_html( $html ) {
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
		$html = preg_replace( '#<noscript\b[^>]*>.*?</noscript>#is', '', (string) $html );
		$html = is_string( $html ) ? $html : '';
		return substr( $html, 0, 1500000 );
	}

	/**
	 * Return a bounded list of stylesheet URLs.
	 *
	 * @param mixed $urls Raw URLs.
	 * @return array<int, string>
	 */
	private function bounded_urls( $urls ) {
		$result = array();
		foreach ( is_array( $urls ) ? array_slice( $urls, 0, 12 ) : array() as $url ) {
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				continue;
			}
			$url = esc_url_raw( trim( $url ) );
			if ( '' !== $url ) {
				$result[] = $url;
			}
		}
		return $result;
	}

	/**
	 * Extract a PNG payload from a response.
	 *
	 * @param string $body         Response body.
	 * @param string $content_type Response content type.
	 * @return string|null
	 */
	private function extract_image( $body, $content_type ) {
		$content_type = strtolower( (string) $content_type );
		$is_png       = 0 === strpos( $content_type, 'image/png' ) || "\x89PNG\r\n\x1a\n" === substr( $body, 0, 8 );

		if ( $is_png ) {
			return strlen( $body ) <= Validation_Limits::MAX_SCREENSHOT_BYTES ? $body : null;
		}

		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			foreach ( array( 'image', 'data', 'screenshot', 'base64' ) as $key ) {
				if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
					$candidate = $decoded[ $key ];
					if ( 0 === strpos( $candidate, 'data:' ) ) {
						$comma = strpos( $candidate, ',' );
						$candidate = false === $comma ? '' : substr( $candidate, $comma + 1 );
					}
					$binary = base64_decode( (string) $candidate, true );
					if ( is_string( $binary ) && "\x89PNG\r\n\x1a\n" === substr( $binary, 0, 8 ) && strlen( $binary ) <= Validation_Limits::MAX_SCREENSHOT_BYTES ) {
						return $binary;
					}
				}
			}
		}

		return null;
	}
}
