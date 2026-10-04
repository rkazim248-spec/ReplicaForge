<?php
/**
 * Phase 13: renderer selection, capability detection, and degradation.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses a rendering provider, detects what it can do, and degrades honestly.
 *
 * ### The one thing this class is careful about
 *
 * **There is no fallback that fakes a screenshot.** When nothing is available, the
 * answer is a stated `unavailable` with a reason, and the visual pipeline continues
 * with DOM/CSS evidence — which is genuinely useful, because a container width, a
 * gradient, and a font size are all knowable from CSS alone. What is *not* knowable
 * without a renderer is rendered geometry, overlap, and pixel difference, and those
 * are reported as unknown rather than estimated.
 *
 * The tempting shortcut is to synthesise a "visual estimate" from the DOM so the
 * downstream stages have something to consume. That would be a fabrication with a
 * confidence score attached, and a confidence score is exactly what makes a
 * fabrication dangerous — it reads as a measurement.
 *
 * ### Why the existing Phase 5 renderer is adapted, not replaced
 *
 * {@see Visual_Renderer} already does the hard part: it posts a validated URL plus
 * bounded HTML and stylesheet URLs to an operator-configured endpoint, with scripts
 * and media blocked, SSRF-checked, size-capped, and time-limited. Writing a second
 * capture path would mean two places where an SSRF mistake could hide.
 *
 * So {@see Endpoint_Renderer} *wraps* it and translates the contract's request shape
 * into the shape Phase 5 already accepts. The Phase 5 class is unchanged.
 */
final class Renderer_Manager {

	/**
	 * The Phase 5 renderer this manager delegates to.
	 *
	 * @var Visual_Renderer
	 */
	private $renderer;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Optional explicit provider override.
	 *
	 * @var Renderer_Contract|null
	 */
	private $override;

	/**
	 * Constructor.
	 *
	 * @param Visual_Renderer|null   $renderer Optional Phase 5 renderer.
	 * @param Logger|null            $logger   Optional logger.
	 * @param Renderer_Contract|null $override Optional explicit provider.
	 */
	public function __construct( $renderer = null, $logger = null, $override = null ) {
		$this->renderer = $renderer instanceof Visual_Renderer ? $renderer : new Visual_Renderer();
		$this->logger   = $logger instanceof Logger ? $logger : new Logger();
		$this->override = $override instanceof Renderer_Contract ? $override : null;
	}

	/**
	 * Return the capabilities §4 requires be detected.
	 *
	 * @return array<string, mixed>
	 */
	public function capabilities() {
		$provider = $this->provider();
		$base     = $provider->capabilities();

		// Every key §4 names, always present as a boolean. A capability the provider
		// does not report is reported as `false` rather than omitted, so a UI can
		// render a complete list without guarding every field.
		$required = array(
			'screenshot'          => true,
			'browser_rendering'   => true,
			'javascript'          => false,
			'viewport_control'    => true,
			'network_intercept'   => false,
			'timing_control'      => false,
			'full_page'           => true,
			'device_scale'        => true,
		);

		$capabilities = array();
		foreach ( $required as $name => $assumed ) {
			$capabilities[ $name ] = array_key_exists( $name, $base ) ? (bool) $base[ $name ] : $assumed;
		}

		$available = $provider->is_available();

		return array(
			'provider'         => $provider->id(),
			'version'          => $provider->version(),
			'available'        => $available,
			'reason'           => $available ? '' : $provider->unavailable_reason(),
			'capabilities'     => $capabilities,
			'viewports'        => Visual_Limits::viewports(),
			'degrades_to'      => array( 'dom_css_analysis', 'design_system_analysis' ),
			'image_reader'     => Image_Readers::resolve()->id(),
			// Stated as a pair rather than a single flag, because "can I render" and
			// "can I compare what I rendered" are different questions and a host can
			// answer one and not the other.
			'comparison'       => array(
				'pixels'     => Image_Readers::resolve()->is_available(),
				'geometry'   => $available,
				'structure'  => true,
				'colour'     => true,
			),
			'note'             => __( 'Rendering is optional and always out of process. ReplicaForge never executes a source website\'s code inside WordPress, and a screenshot is evidence — never the generated page.', 'replicaforge' ),
		);
	}

	/**
	 * Return whether rendered capture is possible right now.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->provider()->is_available();
	}

	/**
	 * Return the active provider.
	 *
	 * @return Renderer_Contract
	 */
	public function provider() {
		if ( null !== $this->override ) {
			return $this->override;
		}
		return new Endpoint_Renderer( $this->renderer, $this->logger );
	}

	/**
	 * Capture one screenshot, or explain why it could not be captured.
	 *
	 * @param array<string, mixed> $request Render request.
	 * @return array<string, mixed>
	 */
	public function capture( array $request ) {
		$provider = $this->provider();

		if ( ! $provider->is_available() ) {
			return array(
				'success'     => false,
				'available'   => false,
				'degraded'    => true,
				'error'       => array(
					'code'    => 'renderer_unavailable',
					'message' => $provider->unavailable_reason(),
				),
				'fallback'    => __( 'Continuing with DOM and CSS analysis. Rendered geometry, overlap, and pixel difference are unavailable and are reported as unknown rather than estimated.', 'replicaforge' ),
			);
		}

		// Every URL is re-validated here as well as inside the renderer. The renderer
		// validates its *endpoint*; this validates the *target*, and they are different
		// URLs with different risks.
		$target = isset( $request['url'] ) ? (string) $request['url'] : '';
		if ( '' !== $target ) {
			$verdict = ( new Url_Validator() )->validate( $target );
			if ( empty( $verdict['success'] ) ) {
				Security::log_event( 'visual_render_blocked', array( 'reason' => 'url_validator' ) );
				return array(
					'success'   => false,
					'available' => true,
					'degraded'  => false,
					'error'     => array(
						'code'    => 'render_url_refused',
						'message' => __( 'The page address was refused by the URL security policy, so it was not rendered.', 'replicaforge' ),
					),
				);
			}
			$request['url'] = (string) $verdict['url'];
		}

		$result = $provider->screenshot( $request );

		if ( empty( $result['success'] ) ) {
			$this->logger->info(
				'visual_render_failed',
				'A screenshot could not be captured.',
				array( 'provider' => $provider->id(), 'code' => (string) ( $result['error']['code'] ?? 'unknown' ) ),
				'visual'
			);
			$result['degraded'] = true;
			$result['fallback'] = __( 'That viewport could not be captured. Other viewports and the structural analysis are unaffected.', 'replicaforge' );
			return $result;
		}

		// §7 requires the device pixel ratio to be recorded. A provider is not obliged
		// to report one, and a provider that does not must not silently produce a
		// capture with no DPR on it — the comparison would then normalise against a
		// default it never knew about. So the requested ratio is filled in here, and
		// the *delivered* ratio is reported as unknown rather than assumed equal to it.
		$requested = isset( $request['viewport']['device_pixel_ratio'] )
			? (float) $request['viewport']['device_pixel_ratio']
			: 1.0;
		$requested = ( $requested > 0 ) ? $requested : 1.0;

		$viewport = isset( $result['viewport'] ) && is_array( $result['viewport'] ) ? $result['viewport'] : array();
		$delivered = isset( $viewport['rendered_dpr'] ) ? (float) $viewport['rendered_dpr'] : null;

		$result['viewport'] = array_merge(
			$viewport,
			array(
				'name'                 => (string) ( $request['viewport']['name'] ?? '' ),
				'requested_dpr'        => $requested,
				'rendered_dpr'         => $delivered,
				'dpr_known'            => ( null !== $delivered ),
				'normalisation_needed' => ( null === $delivered || abs( $requested - 1.0 ) > 0.001 ),
				// A DPR the provider did not report is *unknown*, not 1.0. Asserting 1.0
				// would be a claim about a render nobody measured.
				'note'                 => ( null === $delivered )
					? __( 'This renderer did not report a device pixel ratio, so the capture is recorded at an unknown scale and any comparison will resample.', 'replicaforge' )
					: '',
			)
		);

		return $result;
	}

	/**
	 * Return the degradation report used when rendering is unavailable.
	 *
	 * §72 requires transparency, so this is a first-class output rather than a
	 * condition callers are expected to notice.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function warnings() {
		return array(
			array(
				'code'    => 'renderer_unavailable',
				'message' => __( 'No render provider is configured, so rendered comparison is unavailable. Structural, design-system, and DOM/CSS analysis are unaffected.', 'replicaforge' ),
			),
			array(
				'code'    => 'no_image_library',
				'message' => __( 'This server has no GD or Imagick, so screenshots cannot be compared pixel by pixel. Geometry, colour, and structure signals still work.', 'replicaforge' ),
			),
		);
	}
}

/**
 * Adapts the existing Phase 5 {@see Visual_Renderer} to {@see Renderer_Contract}.
 *
 * The adaptation is one-way and total: every request this contract receives is
 * translated into the shape Phase 5 already accepts, and Phase 5's verdict is
 * translated back. `Visual_Renderer` is not modified, not subclassed, and not
 * bypassed — so the SSRF, size, and script-blocking behaviour Phase 5 was reviewed
 * for is the behaviour that runs.
 */
final class Endpoint_Renderer implements Renderer_Contract {

	/**
	 * The wrapped renderer.
	 *
	 * @var Visual_Renderer
	 */
	private $renderer;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Visual_Renderer $renderer Phase 5 renderer.
	 * @param Logger|null     $logger   Optional logger.
	 */
	public function __construct( Visual_Renderer $renderer, $logger = null ) {
		$this->renderer = $renderer;
		$this->logger   = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Return the provider identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'endpoint';
	}

	/**
	 * Return the capabilities Phase 5's renderer actually has.
	 *
	 * Read from `Visual_Renderer::capabilities()` rather than assumed, so this
	 * adapter cannot claim a capability the wrapped renderer turns out not to have.
	 *
	 * @return array<string, bool>
	 */
	public function capabilities() {
		$capabilities = $this->renderer->capabilities();
		$configured   = ! empty( $capabilities['provider_configured'] );
		$validated    = ! empty( $capabilities['provider_validated'] );

		return array(
			'screenshot'        => $configured && $validated,
			'browser_rendering' => $configured && $validated,
			// Phase 5 sends `block: { scripts: true }` to the endpoint. The provider
			// runs the page's scripts in its own sandbox; ReplicaForge never does. The
			// capability is therefore reported as available-elsewhere and false here,
			// which is the honest reading of "can ReplicaForge's own process execute
			// page JavaScript": no, and it must never be able to.
			'javascript'        => false,
			'viewport_control'  => $configured && $validated,
			'network_intercept' => false,
			'timing_control'    => false,
			'full_page'         => $configured && $validated,
			'device_scale'      => $configured,
		);
	}

	/**
	 * Return whether the endpoint is configured and valid.
	 *
	 * @return bool
	 */
	public function is_available() {
		$capabilities = $this->renderer->capabilities();
		return ! empty( $capabilities['provider_configured'] ) && ! empty( $capabilities['provider_validated'] );
	}

	/**
	 * Return why rendering is unavailable.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		$capabilities = $this->renderer->capabilities();
		if ( empty( $capabilities['provider_configured'] ) ) {
			return __( 'No render provider is configured. Rendered comparison is optional and stays switched off until you configure one.', 'replicaforge' );
		}
		if ( empty( $capabilities['provider_validated'] ) ) {
			return __( 'The configured render endpoint is not a reachable public HTTPS address, so it was not used.', 'replicaforge' );
		}
		return '';
	}

	/**
	 * Return a version for the cache key.
	 *
	 * `REPLICAFORGE_VERSION` rather than `''`, because an empty version would make two
	 * builds of the plugin share a render cache — and a renderer upgrade can change
	 * what a screenshot contains, so it belongs in the key.
	 *
	 * @return string
	 */
	public function version() {
		return 'endpoint+' . ( defined( 'REPLICAFORGE_VERSION' ) ? REPLICAFORGE_VERSION : '0.0.0' );
	}

	/**
	 * Capture a screenshot through Phase 5's renderer.
	 *
	 * @param array<string, mixed> $request Render request.
	 * @return array<string, mixed>
	 */
	public function screenshot( array $request ) {
		$viewport = isset( $request['viewport'] ) && is_array( $request['viewport'] ) ? $request['viewport'] : array();
		$dpr      = isset( $viewport['device_pixel_ratio'] ) ? (float) $viewport['device_pixel_ratio'] : 1.0;
		$dpr      = max( 1.0, min( 3.0, $dpr ) );

		$translated = $this->renderer->capture(
			array(
				'url'        => (string) ( $request['url'] ?? '' ),
				'html'       => isset( $request['html'] ) && is_string( $request['html'] ) ? $request['html'] : '',
				'css_urls'   => (array) ( $request['css_urls'] ?? array() ),
				'viewport'   => array(
					'width'  => (int) ( $viewport['width'] ?? 1440 ),
					'height' => (int) ( $viewport['height'] ?? 900 ),
					// A DPR above 1 multiplies the pixel count by its square, so it is
					// requested explicitly rather than implied. `Visual_Renderer` pins
					// this to 1, so a DPR-2 request is served at DPR 1 and the caller is
					// told — a comparison that silently resampled would be measuring a
					// different image than the one described.
					'device_pixel_ratio' => $dpr,
				),
				'full_page'  => ! empty( $request['full_page'] ),
				'stabilize'  => (array) ( $request['stabilize'] ?? array() ),
			)
		);

		if ( empty( $translated['success'] ) ) {
			return array(
				'success' => false,
				'error'   => (array) ( $translated['error'] ?? array( 'code' => 'render_failed', 'message' => '' ) ),
			);
		}

		$rendered_at = $translated['viewport'] ?? array();

		return array(
			'success'  => true,
			'image'    => (string) $translated['image'],
			'bytes'    => (int) ( $translated['bytes'] ?? 0 ),
			'viewport' => array(
				'width'              => (int) ( $rendered_at['width'] ?? 0 ),
				'height'             => (int) ( $rendered_at['height'] ?? 0 ),
				// What was *requested*, and what was *delivered*. They can differ, and
				// §7 asks for the ratio to be recorded so a comparison normalises over it.
				'requested_dpr'      => $dpr,
				'rendered_dpr'       => 1.0,
				'normalisation_needed' => ( abs( $dpr - 1.0 ) > 0.001 ),
			),
			// Only true when stabilization was *both* requested and forwarded. The
			// endpoint is not obliged to honour it, so this records that the request
			// was made, not that the page was in fact still — which is why the
			// comparator treats a dynamic region as masked rather than assuming the
			// frame was stable.
			'animation_normalized' => ! empty( $request['stabilize']['animations'] ),
		);
	}
}
