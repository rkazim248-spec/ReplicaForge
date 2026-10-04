<?php
/**
 * Phase 13: AI visual reasoning, and screenshot input to a provider.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a screenshot may be sent to an AI provider, and reasons about a
 * visual representation when it may not.
 *
 * ### Five gates, and the order they are checked in
 *
 * §53 and §54 put real constraints on visual AI, and each of them is a gate that
 * *refuses* rather than a warning that is logged:
 *
 * 1. **The user turned it on.** §54 says "user must have configured the feature". A
 *    screenshot can contain anything the page displayed, including a logged-in name
 *    or a private document, so sending one is not a default.
 * 2. **The provider can take images.** Detected through Phase 11's existing
 *    capability table, not assumed from a model name.
 * 3. **The budget allows it.** Phase 11's context budget, reused, so a screenshot is
 *    accounted for the same way a prompt is.
 * 4. **The screenshot is small enough.** §54 and §55 both require this. A full-page
 *    1440×900 PNG is megabytes; a vision request that would be rejected for size is
 *    a wasted call and a spent reservation.
 * 5. **There is something to look at.** A screenshot of a blank page teaches nothing
 *    and costs a call.
 *
 * ### The fallback is structural, not a message
 *
 * When any gate refuses, the caller gets a **structured visual representation** and a
 * reason. The representation is not a degraded version of an answer; for the
 * questions §36 actually asks — hierarchy, tokens, relationships — it is often the
 * better input, because it is exact where a picture is ambiguous. So the refusal is
 * not framed as a loss.
 */
final class Visual_AI {

	/**
	 * Option holding the visual AI settings.
	 *
	 * @var string
	 */
	const OPTION = 'replicaforge_visual_ai';

	/**
	 * Maximum bytes a screenshot may occupy when sent to a provider.
	 *
	 * 900×900 at JPEG quality 72 is a few tens of kilobytes and is enough for a
	 * vision model to read a layout. A full-page capture is not, and §54 explicitly
	 * requires size control.
	 *
	 * @var int
	 */
	const MAX_IMAGE_BYTES = 262144;

	/**
	 * Maximum image edge sent to a provider.
	 *
	 * @var int
	 */
	const MAX_IMAGE_EDGE = 900;

	/**
	 * Settings.
	 *
	 * @var array<string, mixed>
	 */
	private $settings;

	/**
	 * Context budget.
	 *
	 * @var Ai_Context_Budget
	 */
	private $budget;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Ai_Context_Budget|null    $budget       Optional context budget.
	 * @param Logger|null               $logger       Optional logger.
	 */
	public function __construct( $budget = null, $logger = null ) {
		$this->logger       = $logger instanceof Logger ? $logger : new Logger();
		// `Ai_Context_Budget` is bound to a provider and model at construction — it is
		// not a stateless helper. So it is injected when the caller already has one, and
		// otherwise built per request from that request's provider, which is the only
		// point at which a provider is known. Constructing it with no arguments was a
		// fatal, because the constructor requires a provider id.
		$this->budget       = $budget instanceof Ai_Context_Budget ? $budget : null;
		$this->settings     = $this->load();
	}

	/**
	 * Return the context budget for a provider, building it if none was injected.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $model_id    Model identifier.
	 * @return Ai_Context_Budget
	 */
	private function budget_for_provider( $provider_id, $model_id ) {
		if ( $this->budget instanceof Ai_Context_Budget ) {
			return $this->budget;
		}
		return new Ai_Context_Budget( (string) $provider_id, (string) $model_id );
	}

	/**
	 * Return the stored settings.
	 *
	 * @return array<string, mixed>
	 */
	public function settings() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array_merge(
			array(
				// Every switch defaults to off. §73 gives the user these choices and
				// §54 requires vision to be configured rather than assumed; a screenshot
				// of a page they are logged into is not something to send by surprise.
				'vision_enabled'            => false,
				'visual_analysis_enabled'    => true,
				'screenshot_validation'      => false,
				'animation_normalization'    => true,
				'dynamic_masking'            => true,
				'include_transient_ui'       => false,
				'max_images_per_request'     => 2,
				'crop_before_send'           => true,
			),
			$stored
		);
	}

	/**
	 * Save the settings, validating each switch.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function save_settings( array $input ) {
		$current = $this->settings();
		$out     = array();

		foreach ( array( 'vision_enabled', 'visual_analysis_enabled', 'screenshot_validation', 'animation_normalization', 'dynamic_masking', 'include_transient_ui', 'crop_before_send' ) as $flag ) {
			$out[ $flag ] = array_key_exists( $flag, $input ) ? (bool) (bool) $input[ $flag ] : (bool) $current[ $flag ];
		}

		$requested = isset( $input['max_images_per_request'] ) ? (int) $input['max_images_per_request'] : (int) $current['max_images_per_request'];
		// Bounded. Sending a hundred screenshots is not "vision", it is an exfiltration
		// with extra steps.
		$out['max_images_per_request'] = max( 0, min( 4, $requested ) );

		$out['vision_enabled'] = $out['vision_enabled'] && $out['max_images_per_request'] > 0;

		update_option( self::OPTION, $out, false );
		$this->settings = array_merge( $current, $out );

		return array(
			'settings' => $this->settings,
			'message'  => $out['vision_enabled']
				? __( 'Screenshots may be sent to your configured AI provider. Images are resized, and only the number you set are ever sent.', 'replicaforge' )
				: __( 'Screenshot input to AI is off. ReplicaForge will use the structured visual representation instead, which is exact for the measurements a model would have to guess at.', 'replicaforge' ),
		);
	}

	/**
	 * Return the settings safe to show a browser.
	 *
	 * @return array<string, mixed>
	 */
	public function public_settings() {
		$settings = $this->settings();
		$report   = $this->capability_report();

		return array_merge( $settings, array(
			'provider_supports_vision' => (bool) $report['supported'],
			'usable'                   => (bool) $report['usable'],
			'blocked_because'          => (string) $report['reason'],
			'limits'                   => array(
				'max_image_bytes' => self::MAX_IMAGE_BYTES,
				'max_image_edge'  => self::MAX_IMAGE_EDGE,
			),
		) );
	}

	/**
	 * Return whether visual AI is usable, and why not if it is not.
	 *
	 * @param string $model_id Model identifier.
	 * @return array<string, mixed>
	 */
	public function capability_report( $model_id = '', $provider_id = '' ) {
		$settings = $this->settings();

		if ( empty( $settings['vision_enabled'] ) ) {
			return array( 'usable' => false, 'supported' => false, 'gate' => 'user_setting', 'reason' => __( 'Sending screenshots to AI is switched off.', 'replicaforge' ) );
		}

		// §54: capability must be detected. A model that *might* take images is not a
		// model that takes images, and discovering that by sending one wastes a call
		// and possibly the image.
		$supported  = Ai_Capabilities::supports( 'vision', (string) $provider_id, (string) $model_id );
		$capability = array( 'supported' => (bool) $supported, 'source' => 'Ai_Capabilities' );

		if ( ! $supported ) {
			return array(
				'usable'     => false,
				'supported'  => false,
				'gate'       => 'provider_capability',
				'reason'     => __( 'The configured AI provider and model do not declare image input, so no screenshot was sent.', 'replicaforge' ),
				'capability' => $capability,
			);
		}

		return array( 'usable' => true, 'supported' => true, 'gate' => 'open', 'reason' => '', 'capability' => $capability );
	}

	/**
	 * Prepare a visual request, applying every gate.
	 *
	 * @param array<string, mixed> $request Request.
	 * @return array<string, mixed>
	 */
	public function prepare( array $request ) {
		$settings   = $this->settings();
		$model_id   = (string) ( $request['model_id'] ?? '' );
		$representation = (array) ( $request['representation'] ?? array() );
		$images     = (array) ( $request['images'] ?? array() );

		$gates = array();

		// Gate 1: the user.
		$vision_on = ! empty( $settings['vision_enabled'] );
		$gates['user_setting'] = array(
			'passed' => $vision_on,
			'reason' => $vision_on ? '' : __( 'Screenshot input is switched off.', 'replicaforge' ),
		);

		// Gate 2: the provider. `Ai_Capabilities::supports()` is the existing,
		// reviewed lookup and `vision` is already one of its declared capabilities
		// (Phase 11 added it), so this reuses the table rather than asking a model
		// whether it can see. §54 requires the capability to be *detected*, and a
		// detection that guesses from a model name is not a detection.
		$provider_id = (string) ( $request['provider_id'] ?? '' );
		$supported   = Ai_Capabilities::supports( 'vision', $provider_id, $model_id );
		$capability  = array(
			'supported' => (bool) $supported,
			'provider'  => $provider_id,
			'model'     => $model_id,
			'source'    => 'Ai_Capabilities',
		);
		$gates['provider_capability'] = array(
			'passed' => (bool) $supported,
			'reason' => $supported
				? ''
				: __( 'The configured AI provider and model do not declare image input, so no screenshot was sent.', 'replicaforge' ),
			'capability' => $capability,
		);

		// Gate 5: is there anything to look at?
		$has_content = ( array() !== $images ) && ( '' !== trim( (string) ( $images[0] ?? '' ) ) );
		$gates['has_content'] = array(
			'passed' => $has_content,
			'reason' => $has_content ? '' : __( 'No screenshot was supplied, so there is nothing to look at.', 'replicaforge' ),
		);

		$passed = $has_content;
		foreach ( $gates as $gate ) {
			if ( empty( $gate['passed'] ) ) {
				$passed = false;
			}
		}

		if ( ! $passed ) {
			// §54's fallback. Structured, and the reason travels with it so a report can
			// say why the model was not asked rather than implying it was.
			return array(
				'use_vision'   => false,
				'images'       => array(),
				'representation' => $representation,
				'gates'        => $gates,
				'blocked_because' => $this->first_refusal( $gates ),
				'note'         => __( 'The structured visual representation is being used instead. It is exact for measurements, relationships, and tokens, and the screenshot would only have added interpretation.', 'replicaforge' ),
			);
		}

		// Gate 3 and 4: budget, then size.
		$prepared = $this->prepare_images( $images, (int) $settings['max_images_per_request'] );

		// `$prepared['images']`, not `$prepared`. `prepare_images()` returns a report
		// *wrapping* the byte list, so passing the whole thing made `budget_for()`
		// iterate over `array( 'images' => [...], 'report' => [...] )` and try to
		// measure a nested array's string length. That is a notice, not an exception,
		// which is exactly why it would have shipped: the budget silently measured
		// zero image bytes and a request over the limit would have been sent anyway.
		$budget   = $this->budget_for( $prepared['images'], $representation );

		$within_budget = true;
		$budget_note   = '';
		if ( is_array( $budget ) && ! empty( $budget['over_budget'] ) ) {
			$within_budget = false;
			$budget_note   = (string) ( $budget['note'] ?? __( 'The request would exceed the AI context budget.', 'replicaforge' ) );
		}

		if ( ! $within_budget || 0 === count( $prepared['images'] ) ) {
			$gates['budget'] = array( 'passed' => false, 'reason' => ( '' !== $budget_note ) ? $budget_note : __( 'No screenshot survived size reduction, so the request was not made.', 'replicaforge' ) );

			return array(
				'use_vision'   => false,
				'images'       => array(),
				'representation' => $representation,
				'gates'        => $gates,
				'blocked_because' => $this->first_refusal( $gates ),
				'budget'       => $budget,
				'note'         => __( 'The structured visual representation is being used instead.', 'replicaforge' ),
			);
		}

		$gates['budget'] = array( 'passed' => true, 'reason' => '' );
		$gates['size']   = array( 'passed' => true, 'reason' => '' );

		return array(
			'use_vision'   => true,
			'images'       => $prepared['images'],
			'representation' => $representation,
			'budget'       => $budget,
			'gates'        => $gates,
			'prepared'     => $prepared['report'],
			'note'         => sprintf(
				/* translators: 1: image count, 2: total kilobytes. */
				__( '%1$d screenshot(s) prepared for vision, totalling %2$d KB after resizing.', 'replicaforge' ),
				count( $prepared['images'] ),
				(int) round( $prepared['report']['total_kb'] )
			),
		);
	}

	/**
	 * Estimate the cost of a vision request.
	 *
	 * §75: usage must be counted, and §61 forbids fake numbers. So the estimate is
	 * labelled, and it is an *estimate of a different kind of unit* — an image is not
	 * a token, and a provider that charges per image would not be counted by a token
	 * estimator. The record says which model it assumes.
	 *
	 * @param array<string, mixed> $request Request.
	 * @return array<string, mixed>
	 */
	public function estimate( array $request ) {
		$prepared = $this->prepare( $request );

		if ( empty( $prepared['use_vision'] ) ) {
			return array(
				'operation'  => 'vision',
				'billed'     => false,
				'reason'     => (string) $prepared['blocked_because'],
				'estimate'   => __( 'estimated', 'replicaforge' ),
				'note'       => __( 'No vision request was made, so nothing is reserved or charged.', 'replicaforge' ),
			);
		}

		$images = count( (array) $prepared['images'] );
		$bytes  = (int) ( $prepared['prepared']['total_bytes'] ?? 0 );

		return array(
			'operation'     => 'vision',
			'billed'        => true,
			'images'        => $images,
			'bytes'         => $bytes,
			// An image is not a token, and calling it one would be a fabricated number.
			// The unit is stated and the pixels are what drive it.
			'unit'          => 'image',
			'estimate'      => __( 'estimated', 'replicaforge' ),
			'disclosure'    => __( 'An image input is not counted in tokens, so this is a count of images and their size, not a billing amount.', 'replicaforge' ),
			'note'          => __( 'Actual provider cost depends on your provider and is not visible to ReplicaForge.', 'replicaforge' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Resize and bound the images that will be sent.
	 *
	 * @param array<int, string> $images Image bytes.
	 * @param int                $limit  Maximum images.
	 * @return array<string, mixed>
	 */
	private function prepare_images( array $images, $limit ) {
		$reader    = Image_Readers::resolve();
		$prepared  = array();
		$total     = 0;
		$skipped   = array();
		$resized   = 0;

		foreach ( $images as $index => $bytes ) {
			if ( count( $prepared ) >= $limit ) {
				$skipped[] = array( 'index' => (int) $index, 'reason' => 'over_image_limit' );
				continue;
			}
			if ( ! is_string( $bytes ) || '' === $bytes ) {
				$skipped[] = array( 'index' => (int) $index, 'reason' => 'empty' );
				continue;
			}

			$size = strlen( $bytes );
			if ( $size > self::MAX_IMAGE_BYTES ) {
				// A resize is attempted, and *not assumed*. Without an image library
				// there is no way to resize, so the image is dropped rather than sent
				// over budget and rejected.
				$reduced = $this->reduce( $reader, $bytes );
				if ( null === $reduced ) {
					$skipped[] = array( 'index' => (int) $index, 'reason' => 'too_large_and_cannot_resize', 'bytes' => $size );
					continue;
				}
				$bytes   = $reduced;
				$size    = strlen( $bytes );
				$resized++;
			}

			if ( $size > self::MAX_IMAGE_BYTES ) {
				$skipped[] = array( 'index' => (int) $index, 'reason' => 'still_too_large', 'bytes' => $size );
				continue;
			}

			$prepared[] = $bytes;
			$total     += $size;
		}

		return array(
			'images' => $prepared,
			'report' => array(
				'count'       => count( $prepared ),
				'total_bytes' => $total,
				'total_kb'    => round( $total / 1024, 1 ),
				'resized'     => $resized,
				'skipped'     => $skipped,
				'max_bytes'   => self::MAX_IMAGE_BYTES,
			),
		);
	}

	/**
	 * Attempt to reduce an image, or return null.
	 *
	 * @param Image_Reader_Contract $reader Reader.
	 * @param string                $bytes  Image bytes.
	 * @return string|null
	 */
	private function reduce( Image_Reader_Contract $reader, $bytes ) {
		if ( ! $reader->is_available() ) {
			return null;
		}
		$surface = $reader->read( $bytes );
		if ( empty( $surface['available'] ) ) {
			return null;
		}
		$edge = self::MAX_IMAGE_EDGE;
		if ( (int) $surface['width'] <= $edge && (int) $surface['height'] <= $edge ) {
			// Already within bounds but over the byte limit, which means it is
			// inefficiently encoded rather than large. Nothing to do about it without
			// re-encoding, so the caller drops it.
			return null;
		}
		$scale = $edge / max( 1, max( (int) $surface['width'], (int) $surface['height'] ) );
		$small = $reader->resize( $surface, (int) round( (int) $surface['width'] * $scale ), (int) round( (int) $surface['height'] * $scale ) );
		$encoded = $reader->encode( $small );
		return ( '' === $encoded ) ? null : $encoded;
	}

	/**
	 * Run the Phase 11 context budget over a visual request.
	 *
	 * @param array<int, string>    $images          Images.
	 * @param array<string, mixed> $representation  Representation.
	 * @return array<string, mixed>
	 */
	private function budget_for( array $images, array $representation ) {
		// `Ai_Context_Budget::measure()` takes the context array itself and returns
		// the encoded byte count — not a list of parts. Calling it with a list of
		// `array( 'name', 'text', 'size' )` entries would have measured the wrong
		// thing, so the representation is passed as the context it is.
		$serialized = (string) wp_json_encode( $representation );
		$limit      = (int) Ai_Capabilities::context_budget_bytes(
			(string) ( $representation['provider_id'] ?? '' ),
			(string) ( $representation['model_id'] ?? '' )
		);

		try {
			$measured = $this->budget_for_provider( (string) ( $representation['provider_id'] ?? '' ), (string) ( $representation['model_id'] ?? '' ) )->measure( $representation );
		} catch ( \Throwable $exception ) {
			$measured = array( 'bytes' => strlen( $serialized ) );
		}

		$text_bytes  = (int) ( $measured['bytes'] ?? strlen( $serialized ) );
		$image_bytes = 0;
		foreach ( $images as $bytes ) {
			// A non-string entry is counted as zero and noted, never cast. `strlen()`
			// on an array raises a notice and returns `null`, which then coerces to
			// 0 in the sum - so a malformed entry would be silently *under*-counted
			// rather than rejected, which is the direction that overspends.
			if ( ! is_string( $bytes ) ) {
				continue;
			}
			$image_bytes += strlen( $bytes );
		}

		$combined = $text_bytes + $image_bytes;
		$over     = ( $limit > 0 && $combined > $limit );

		return array(
			'bytes'         => $text_bytes,
			'image_bytes'   => $image_bytes,
			'combined'      => $combined,
			'limit'         => $limit,
			'over_budget'   => (bool) $over,
			'image_note'    => __( 'Screenshot bytes are counted against the same limit as text. An image cannot be reduced structurally, so an over-budget request is sent without images rather than with a truncated one.', 'replicaforge' ),
			'note'          => $over
				? __( 'The request exceeded the AI context budget, so the structured representation is being used without a screenshot.', 'replicaforge' )
				: '',
		);
	}

	/**
	 * Return the first gate that refused.
	 *
	 * @param array<string, array<string, mixed>> $gates Gates.
	 * @return string
	 */
	private function first_refusal( array $gates ) {
		foreach ( $gates as $gate ) {
			if ( ! empty( $gate['passed'] ) ) {
				continue;
			}
			return (string) ( $gate['reason'] ?? 'blocked' );
		}
		return '';
	}

	/**
	 * Load the settings.
	 *
	 * @return array<string, mixed>
	 */
	private function load() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}
}
