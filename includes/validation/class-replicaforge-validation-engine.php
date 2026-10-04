<?php
/**
 * Validation engine for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a validation and assembles the machine-readable result.
 *
 * The engine has four capability levels and degrades one at a time:
 *
 *     Level 1  structural comparison          always available
 *     Level 2  design-system comparison       always available
 *     Level 3  rendered visual comparison     only with a configured renderer
 *     Level 4  AI explanation                 only with a configured provider
 *
 * The engine reads, compares, and reports. It never writes to the Elementor
 * document, never publishes, and never follows a correction.
 */
final class Validation_Engine {

	/**
	 * Source adapter.
	 *
	 * @var Source_Representation_Adapter
	 */
	private $source_adapter;

	/**
	 * Generated page analyzer.
	 *
	 * @var Generated_Page_Analyzer
	 */
	private $generated_analyzer;

	/**
	 * Comparison context.
	 *
	 * @var Comparison_Context
	 */
	private $context;

	/**
	 * Difference engine.
	 *
	 * @var Difference_Engine
	 */
	private $differences;

	/**
	 * Metrics calculator.
	 *
	 * @var Validation_Metrics
	 */
	private $metrics;

	/**
	 * Correction plan builder.
	 *
	 * @var Correction_Plan
	 */
	private $corrections;

	/**
	 * Cache.
	 *
	 * @var Validation_Cache
	 */
	private $cache;

	/**
	 * Visual renderer.
	 *
	 * @var Visual_Renderer
	 */
	private $renderer;

	/**
	 * Image differ.
	 *
	 * @var Image_Differ
	 */
	private $differ;

	/**
	 * Optional AI manager for Level 4 explanations.
	 *
	 * @var Ai_Manager|null
	 */
	private $ai_manager;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $services Optional service overrides.
	 */
	public function __construct( array $services = array() ) {
		$this->source_adapter = isset( $services['source_adapter'] ) && $services['source_adapter'] instanceof Source_Representation_Adapter
			? $services['source_adapter']
			: new Source_Representation_Adapter();
		$this->generated_analyzer = isset( $services['generated_analyzer'] ) && $services['generated_analyzer'] instanceof Generated_Page_Analyzer
			? $services['generated_analyzer']
			: new Generated_Page_Analyzer();
		$this->context = isset( $services['context'] ) && $services['context'] instanceof Comparison_Context
			? $services['context']
			: new Comparison_Context();
		$this->differences = isset( $services['differences'] ) && $services['differences'] instanceof Difference_Engine
			? $services['differences']
			: new Difference_Engine();
		$this->metrics = isset( $services['metrics'] ) && $services['metrics'] instanceof Validation_Metrics
			? $services['metrics']
			: new Validation_Metrics();
		$this->corrections = isset( $services['corrections'] ) && $services['corrections'] instanceof Correction_Plan
			? $services['corrections']
			: new Correction_Plan();
		$this->cache = isset( $services['cache'] ) && $services['cache'] instanceof Validation_Cache
			? $services['cache']
			: new Validation_Cache();
		$this->renderer = isset( $services['renderer'] ) && $services['renderer'] instanceof Visual_Renderer
			? $services['renderer']
			: new Visual_Renderer();
		$this->differ = isset( $services['differ'] ) && $services['differ'] instanceof Image_Differ
			? $services['differ']
			: new Image_Differ( $this->renderer->image_library() );
		$this->ai_manager = isset( $services['ai_manager'] ) && $services['ai_manager'] instanceof Ai_Manager
			? $services['ai_manager']
			: null;
	}

	/**
	 * Run a validation.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @param int                  $draft_id      Generated draft post ID.
	 * @param array<string, mixed> $options       Validation options.
	 * @return array<string, mixed>
	 */
	public function validate( $representation, $draft_id, array $options = array() ) {
		$started = microtime( true );
		Security::log_event( 'validation_started', array( 'code' => 'phase5', 'reason' => 'user_triggered' ) );

		if ( ! is_array( $representation ) ) {
			return $this->error( 'invalid_design_representation', __( 'A valid Phase 2 Design Representation 2.0 is required for validation.', 'replicaforge' ), 400 );
		}
		$design = new Design_Representation( $representation );
		if ( ! $design->is_valid() ) {
			return $this->error( 'invalid_design_representation', __( 'The Phase 2 Design Representation could not be validated.', 'replicaforge' ), 400 );
		}
		$representation = $design->to_array();

		$draft_id = absint( $draft_id );
		if ( $draft_id < 1 || ! get_post( $draft_id ) ) {
			return $this->error( 'invalid_draft', __( 'The generated draft could not be found.', 'replicaforge' ), 404 );
		}
		if ( ! current_user_can( 'edit_post', $draft_id ) ) {
			return $this->error( 'forbidden_draft', __( 'You do not have permission to inspect this draft.', 'replicaforge' ), 403 );
		}
		if ( 'builder' !== (string) get_post_meta( $draft_id, '_elementor_edit_mode', true ) ) {
			return $this->error( 'not_elementor_document', __( 'The selected page is not an Elementor document, so it cannot be validated.', 'replicaforge' ), 400 );
		}

		$viewports = $this->viewports( isset( $options['viewports'] ) ? $options['viewports'] : array() );
		$use_cache = ! isset( $options['force'] ) || ! $options['force'];

		$source_hash    = hash( 'sha256', (string) wp_json_encode( $this->stable_representation( $representation ) ) );
		$generated_hash = $this->generated_hash( $draft_id );
		$cache_key      = $this->cache->key(
			array(
				'source_hash'    => $source_hash,
				'generated_hash' => $generated_hash,
				'viewports'      => $viewports,
				'visual'         => ! empty( $options['visual'] ),
			)
		);

		if ( $use_cache ) {
			$cached = $this->cache->get( $cache_key );
			if ( is_array( $cached ) && isset( $cached['result']['source_hash'] ) && $cached['result']['source_hash'] === $source_hash && $cached['result']['generated_hash'] === $generated_hash ) {
				$cached['result']['cache'] = 'hit';
				return $cached['result'];
			}
		}

		$source    = $this->source_adapter->build( $representation );
		$generated = $this->generated_analyzer->analyze( $draft_id );

		if ( empty( $generated['components'] ) && empty( $generated['sections'] ) ) {
			return $this->error( 'generated_document_unreadable', __( 'The generated document could not be analyzed. Structural validation is unavailable for this draft.', 'replicaforge' ), 500 );
		}

		$this->context->build( $source, $generated );
		$this->differences->reset( Validation_Limits::COMPARE_BUDGET_SECONDS );

		$comparators = array(
			new Structural_Comparator(),
			new Layout_Comparator(),
			new Typography_Comparator(),
			new Color_Comparator(),
			new Spacing_Comparator(),
			new Asset_Comparator(),
			new Responsive_Comparator(),
		);

		$levels = array(
			1 => Validation_Limits::LEVELS[1],
			2 => Validation_Limits::LEVELS[2],
		);

		foreach ( $comparators as $comparator ) {
			// The structural comparator reads only the paired context, and the
			// responsive comparator compares the two whole sides rather than
			// individual pairs. Every other comparator takes both sides and the
			// context. Each call is made explicitly so a signature change cannot
			// be absorbed by a generic call and turn into a fatal.
			if ( $comparator instanceof Structural_Comparator ) {
				$this->differences->record_many( $comparator->compare( $this->context ) );
				continue;
			}
			if ( $comparator instanceof Responsive_Comparator ) {
				$this->differences->record_many( $comparator->compare( $source, $generated ) );
				continue;
			}
			$this->differences->record_many( $comparator->compare( $source, $generated, $this->context ) );
		}

		$visual = $this->visual_comparison( $source, $generated, $draft_id, $viewports, ! empty( $options['visual'] ) );

		$differences = $this->differences->differences();
		$metrics     = $this->metrics->compute( $this->differences->checks() );
		$exhaustion  = $this->differences->exhaustion();

		if ( ! empty( $visual['available'] ) ) {
			$levels[3] = Validation_Limits::LEVELS[3];
		}

		$ai = $this->ai_explanation( $differences, $metrics, $source, $generated, ! empty( $options['ai'] ) );
		if ( ! empty( $ai['available'] ) ) {
			$levels[4] = Validation_Limits::LEVELS[4];
		}

		$validation_id = $this->validation_id( $source_hash, $generated_hash );
		$warnings      = $this->warnings( $source, $generated, $visual, $differences, $exhaustion );
		$duration      = (int) round( ( microtime( true ) - $started ) * 1000 );

		$result = array(
			'schema_version'   => Validation_Limits::SCHEMA_VERSION,
			'phase'            => Validation_Limits::PHASE,
			'validation_id'    => $validation_id,
			'created_at'       => gmdate( 'c' ),
			'duration_ms'      => $duration,
			'cache'            => 'miss',
			'engine_version'   => Validation_Limits::ENGINE_VERSION,
			'status'           => 'completed',
			'cache_key'        => $cache_key,
			'source_hash'      => $source_hash,
			'generated_hash'   => $generated_hash,
			'levels'           => $levels,
			'source'           => array(
				'url'    => isset( $source['page']['url'] ) ? (string) $source['page']['url'] : '',
				'title'  => isset( $source['page']['title'] ) ? (string) $source['page']['title'] : '',
				'type'   => isset( $source['page']['type'] ) ? (string) $source['page']['type'] : 'unknown',
				'side'   => 'source',
			),
			'generated'        => array(
				'draft_id'         => $draft_id,
				'title'            => isset( $generated['page']['title'] ) ? (string) $generated['page']['title'] : '',
				'generation_id'    => isset( $generated['metadata']['generation_id'] ) ? (string) $generated['metadata']['generation_id'] : '',
				'elementor_version' => isset( $generated['metadata']['elementor_version'] ) ? (string) $generated['metadata']['elementor_version'] : '',
				'side'             => 'generated',
			),
			'viewports'        => $viewports,
			'differences'      => $differences,
			'metrics'          => $metrics,
			'visual'           => $visual,
			'ai'               => $ai,
			'correction_plan'  => $this->corrections->build( $differences ),
			'counts'           => array(
				'differences' => count( $differences ),
				'by_severity' => $this->differences->severity_counts(),
				'by_category' => $this->differences->category_counts(),
				'by_viewport' => $this->differences->viewport_counts(),
				'checks'     => count( $this->differences->checks() ),
				'warnings'    => count( $warnings ),
			),
			'budget'           => array(
				'limit_seconds' => Validation_Limits::COMPARE_BUDGET_SECONDS,
				'max_checks'    => Validation_Limits::MAX_CHECKS,
				'max_differences' => Validation_Limits::MAX_DIFFERENCES,
				'exhausted'     => $exhaustion['exhausted'],
				'reason'        => $exhaustion['reason'],
			),
			'summary'          => $this->summary( $source, $generated, $metrics, $differences ),
			'warnings'         => $warnings,
			'limitations'      => $this->limitations( $visual ),
			'read_only'        => true,
		);

		$this->cache->set( $cache_key, $result );
		$this->cache->record( $result );
		Security::log_event(
			'validation_completed',
			array(
				'count'       => count( $differences ),
				'duration_ms' => $duration,
			)
		);

		return $result;
	}

	/**
	 * Return a bounded, non-sensitive representation hash input.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @return array<string, mixed>
	 */
	private function stable_representation( array $representation ) {
		$copy = $representation;
		unset( $copy['analysis']['analyzed_at'], $copy['analysis']['duration_ms'] );
		return $copy;
	}

	/**
	 * Return a hash of the generated document.
	 *
	 * @param int $draft_id Draft post ID.
	 * @return string
	 */
	private function generated_hash( $draft_id ) {
		$raw  = (string) get_post_meta( $draft_id, '_elementor_data', true );
		$meta = (string) get_post_meta( $draft_id, Elementor_Limits::META_PREFIX . 'generated_at', true );
		return hash( 'sha256', $raw . '|' . $meta );
	}

	/**
	 * Resolve the viewport configuration.
	 *
	 * @param mixed $requested Requested viewports.
	 * @return array<string, array<string, mixed>>
	 */
	private function viewports( $requested ) {
		$viewports = array();
		foreach ( array_keys( Validation_Limits::VIEWPORTS ) as $device ) {
			$defaults = Validation_Limits::VIEWPORTS[ $device ];
			$width    = $defaults['width'];
			$height   = $defaults['height'];
			if ( is_array( $requested ) && isset( $requested[ $device ] ) && is_array( $requested[ $device ] ) ) {
				$width  = isset( $requested[ $device ]['width'] ) && is_numeric( $requested[ $device ]['width'] ) ? (int) $requested[ $device ]['width'] : $width;
				$height = isset( $requested[ $device ]['height'] ) && is_numeric( $requested[ $device ]['height'] ) ? (int) $requested[ $device ]['height'] : $height;
			}
			$viewports[ $device ] = array(
				'label'  => $defaults['label'],
				'width'  => max( 320, min( 4000, $width ) ),
				'height' => max( 240, min( 4000, $height ) ),
			);
		}
		return $viewports;
	}

	/**
	 * Run the optional rendered comparison.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @param int                  $draft_id  Draft post ID.
	 * @param array<string, mixed> $viewports Resolved viewports.
	 * @param bool                 $requested Whether the user asked for it.
	 * @return array<string, mixed>
	 */
	private function visual_comparison( array $source, array $generated, $draft_id, array $viewports, $requested ) {
		$capabilities = $this->renderer->capabilities();
		$result       = array(
			'available'    => false,
			'requested'    => (bool) $requested,
			'capabilities' => $capabilities,
			'viewports'    => array(),
			'warnings'     => array(),
		);

		if ( ! $requested ) {
			$result['warnings'][] = __( 'Rendered comparison was not requested. Structural and design-system comparison completed.', 'replicaforge' );
			return $result;
		}
		if ( empty( $capabilities['provider_configured'] ) ) {
			$result['warnings'][] = __( 'Rendered capture is unavailable because no render provider is configured. Structural and design-system comparison completed successfully.', 'replicaforge' );
			return $result;
		}
		if ( ! $this->differ->is_available() ) {
			$result['warnings'][] = __( 'Rendered screenshots could not be compared because no image library is installed. Structural comparison completed successfully.', 'replicaforge' );
			return $result;
		}

		$source_url = isset( $source['page']['url'] ) ? (string) $source['page']['url'] : '';
		if ( '' === $source_url || ! Security::is_safe_public_reference( $source_url ) ) {
			$result['warnings'][] = __( 'The source page could not be re-referenced safely, so rendered comparison was skipped.', 'replicaforge' );
			return $result;
		}

		$render = $this->render_generated_markup( $draft_id );
		$captured = 0;

		foreach ( $viewports as $device => $viewport ) {
			$result['viewports'][ $device ] = array(
				'available' => false,
				'reason'    => '',
			);

			// Two captures per device, bounded in total so a misconfigured
			// viewport list can never turn into an unbounded render run.
			if ( $captured + 2 > Validation_Limits::MAX_SCREENSHOTS ) {
				$result['viewports'][ $device ]['reason'] = __( 'The screenshot budget for this run was exhausted.', 'replicaforge' );
				$result['warnings'][]                      = __( 'Rendered comparison stopped at the configured screenshot limit. Compare the remaining viewports in a second run.', 'replicaforge' );
				continue;
			}

			Security::log_event( 'source_render_started', array( 'code' => $device, 'reason' => 'validation' ) );
			$source_capture = $this->renderer->capture(
				array(
					'url'      => $source_url,
					'viewport' => $viewport,
				)
			);
			if ( empty( $source_capture['success'] ) ) {
				$result['viewports'][ $device ]['reason'] = isset( $source_capture['error']['message'] ) ? (string) $source_capture['error']['message'] : __( 'Source capture failed.', 'replicaforge' );
				$result['warnings'][]           = sprintf(
					/* translators: %s: Device name. */
					__( 'Source rendering was unavailable at %s. Structural validation remains available.', 'replicaforge' ),
					$viewport['label']
				);
				continue;
			}

			Security::log_event( 'generated_render_started', array( 'code' => $device, 'reason' => 'validation' ) );
			$generated_capture = $this->renderer->capture(
				array(
					'html'      => $render['html'],
					'css_urls'  => $render['css_urls'],
					'viewport'  => $viewport,
				)
			);
			if ( empty( $generated_capture['success'] ) ) {
				$result['viewports'][ $device ]['reason'] = isset( $generated_capture['error']['message'] ) ? (string) $generated_capture['error']['message'] : __( 'Generated capture failed.', 'replicaforge' );
				$result['warnings'][]           = sprintf(
					/* translators: %s: Device name. */
					__( 'The generated draft could not be rendered at %s. Elementor structural validation remains available.', 'replicaforge' ),
					$viewport['label']
				);
				continue;
			}

			$captured++;
			$comparison = $this->differ->compare( $source_capture['image'], $generated_capture['image'] );
			$result['viewports'][ $device ] = array_merge( $result['viewports'][ $device ], $comparison );
			$this->record_visual_check( (string) $device, $comparison );
		}

		$result['available'] = $captured > 0;
		$result['captured_viewports'] = $captured;
		$result['captures']  = $captured * 2;
		if ( 0 === $captured ) {
			$result['warnings'][] = __( 'Rendered comparison could not be completed at any viewport. Structural comparison remains available.', 'replicaforge' );
		}

		return $result;
	}

	/**
	 * Record a rendered comparison as a check.
	 *
	 * @param string               $device     Device key.
	 * @param array<string, mixed> $comparison Image comparison result.
	 * @return void
	 */
	private function record_visual_check( $device, array $comparison ) {
		if ( empty( $comparison['available'] ) ) {
			$this->differences->record(
				array(
					'category'    => 'layout',
					'target'      => 'rendered:' . $device,
					'property'    => 'rendered_comparison',
					'expected'    => 'comparable',
					'actual'      => 'unavailable',
					'state'       => 'unknown',
					'viewport'    => $device,
					'confidence'  => 0.0,
				)
			);
			return;
		}

		$ratio   = (float) $comparison['differing_ratio'];
		$state   = $comparison['band'];
		$message = '';
		if ( ! empty( $comparison['size_mismatch'] ) ) {
			$message = sprintf(
				/* translators: 1: Source size, 2: Generated size. */
				__( 'Rendered capture size differs: %1$s on the source, %2$s in the draft.', 'replicaforge' ),
				$comparison['source_size']['width'] . 'x' . $comparison['source_size']['height'],
				$comparison['generated_size']['width'] . 'x' . $comparison['generated_size']['height']
			);
		} elseif ( 'pass' !== $state ) {
			$message = sprintf(
				/* translators: 1: Device, 2: Percentage of differing pixels. */
				__( 'Rendered comparison at %1$s reports %2$s of differing pixels.', 'replicaforge' ),
				$device,
				round( $ratio * 100, 1 ) . '%'
			);
		}

		$this->differences->record(
			array(
				'category'     => 'layout',
				'target'       => 'rendered:' . $device,
				'property'     => 'rendered_difference',
				'expected'     => 0.0,
				'actual'       => $ratio,
				'difference'   => $ratio,
				'state'        => $state,
				'tolerance_type' => 'length',
				'severity'     => 'major' === $state ? 'major' : ( 'fail' === $state ? 'moderate' : ( 'partial' === $state ? 'minor' : 'informational' ) ),
				'viewport'     => $device,
				'message'      => $message,
				'confidence'   => 0.7,
			)
		);
	}

	/**
	 * Render the generated document markup for capture.
	 *
	 * @param int $draft_id Draft post ID.
	 * @return array<string, mixed>
	 */
	private function render_generated_markup( $draft_id ) {
		$html = '';
		if ( class_exists( '\Elementor\Plugin' ) && method_exists( '\Elementor\Plugin', 'instance' ) ) {
			$plugin = \Elementor\Plugin::instance();
			if ( is_object( $plugin ) && isset( $plugin->documents ) && is_object( $plugin->documents ) ) {
				$document = $plugin->documents->get( $draft_id, false );
				if ( is_object( $document ) && method_exists( $document, 'print_content_with_wrapper' ) ) {
					ob_start();
					try {
						$document->print_content_with_wrapper();
						$html = (string) ob_get_clean();
					} catch ( \Throwable $exception ) {
						$html = (string) ob_get_clean();
					}
				}
			}
		}

		$css_urls = array();
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				$file = \Elementor\Core\Files\CSS\Post::create( $draft_id );
				if ( is_object( $file ) && method_exists( $file, 'get_url' ) ) {
					$css_urls[] = (string) $file->get_url();
				}
			} catch ( \Throwable $exception ) {
				$css_urls = array();
			}
		}
		$frontend = includes_url( 'css/elementor-frontend.min.css' );
		if ( is_string( $frontend ) && '' !== $frontend ) {
			$css_urls[] = $frontend;
		}

		return array(
			'html'     => $html,
			'css_urls' => array_values( array_filter( $css_urls ) ),
		);
	}

	/**
	 * Run the optional AI explanation.
	 *
	 * The model is given only already-detected differences. It may explain them
	 * and may restate the measured values. It may not introduce a difference that
	 * the deterministic comparison did not find, and any recommendation is stored
	 * as text, never as a document change.
	 *
	 * @param array<int, array>    $differences Difference records.
	 * @param array<string, mixed> $metrics     Metrics.
	 * @param array<string, mixed> $source      Normalized source side.
	 * @param array<string, mixed> $generated   Normalized generated side.
	 * @param bool                 $requested   Whether the user asked for it.
	 * @return array<string, mixed>
	 */
	private function ai_explanation( array $differences, array $metrics, array $source, array $generated, $requested ) {
		$result = array(
			'available'   => false,
			'requested'   => (bool) $requested,
			'explanation' => '',
			'recommendations' => array(),
			'warnings'    => array(),
		);

		if ( ! $requested ) {
			return $result;
		}
		if ( ! $this->ai_manager instanceof Ai_Manager ) {
			$result['warnings'][] = __( 'AI explanation is unavailable because no provider is configured.', 'replicaforge' );
			return $result;
		}
		if ( empty( $differences ) ) {
			$result['available']   = true;
			$result['explanation'] = __( 'The deterministic comparison found no differences, so there is nothing to explain.', 'replicaforge' );
			return $result;
		}

		$explanation = Ai_Validation_Explainer::explain( $this->ai_manager, $differences, $metrics, $source, $generated );
		$result['available']   = ! empty( $explanation['available'] );
		$result['explanation'] = isset( $explanation['explanation'] ) ? (string) $explanation['explanation'] : '';
		$result['recommendations'] = isset( $explanation['recommendations'] ) && is_array( $explanation['recommendations'] ) ? $explanation['recommendations'] : array();
		$result['warnings']    = isset( $explanation['warnings'] ) && is_array( $explanation['warnings'] ) ? $explanation['warnings'] : array();
		return $result;
	}

	/**
	 * Assemble the run warnings.
	 *
	 * @param array<string, mixed> $source      Normalized source side.
	 * @param array<string, mixed> $generated   Normalized generated side.
	 * @param array<string, mixed> $visual      Visual comparison result.
	 * @param array<int, array>    $differences Difference records.
	 * @param array{exhausted: bool, reason: string} $exhaustion Budget outcome.
	 * @return array<int, string>
	 */
	private function warnings( array $source, array $generated, array $visual, array $differences, array $exhaustion ) {
		$warnings = array();
		foreach ( $source['warnings'] as $warning ) {
			$warnings[] = (string) $warning;
		}
		foreach ( $generated['warnings'] as $warning ) {
			$warnings[] = (string) $warning;
		}
		foreach ( $visual['warnings'] as $warning ) {
			$warnings[] = (string) $warning;
		}
		if ( empty( $generated['design_system']['spacing'] ) && array() === $generated['design_system']['radius'] ) {
			$warnings[] = __( 'The generated stylesheet was not readable, so design values were compared from document settings only.', 'replicaforge' );
		}
		if ( ! empty( $exhaustion['exhausted'] ) ) {
			$warnings[] = 'time_budget' === $exhaustion['reason']
				? sprintf(
					/* translators: %d: Number of seconds. */
					__( 'The comparison reached its %d second budget and stopped early. The result is a partial comparison; raise the limit or validate a smaller page to cover everything.', 'replicaforge' ),
					(int) Validation_Limits::COMPARE_BUDGET_SECONDS
				)
				: __( 'The comparison reached its configured record limit and stopped early. The result is a partial comparison.', 'replicaforge' );
		}
		$critical = 0;
		foreach ( $differences as $difference ) {
			if ( isset( $difference['severity'] ) && 'critical' === $difference['severity'] ) {
				$critical++;
			}
		}
		if ( $critical > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: Number of critical differences. */
				__( '%d critical difference(s) were detected. The generated page is not a usable reproduction of the source yet.', 'replicaforge' ),
				$critical
			);
		}
		return array_values( array_unique( array_filter( $warnings ) ) );
	}

	/**
	 * Build the human summary.
	 *
	 * @param array<string, mixed> $source      Normalized source side.
	 * @param array<string, mixed> $generated   Normalized generated side.
	 * @param array<string, mixed> $metrics     Metrics.
	 * @param array<int, array>    $differences Difference records.
	 * @return array<string, mixed>
	 */
	private function summary( array $source, array $generated, array $metrics, array $differences ) {
		$top = array();
		usort(
			$differences,
			static function ( $left, $right ) {
				$order = array_flip( Validation_Limits::SEVERITIES );
				$a     = isset( $order[ $left['severity'] ] ) ? $order[ $left['severity'] ] : 99;
				$b     = isset( $order[ $right['severity'] ] ) ? $order[ $right['severity'] ] : 99;
				if ( $a === $b ) {
					return 0;
				}
				return $a < $b ? -1 : 1;
			}
		);
		foreach ( array_slice( $differences, 0, 8 ) as $difference ) {
			if ( '' === (string) $difference['message'] ) {
				continue;
			}
			$top[] = array(
				'severity' => (string) $difference['severity'],
				'category' => (string) $difference['category'],
				'message'  => (string) $difference['message'],
				'id'       => (string) $difference['id'],
			);
		}

		return array(
			'source_sections'    => isset( $source['counters']['sections'] ) ? (int) $source['counters']['sections'] : 0,
			'generated_sections' => isset( $generated['counters']['sections'] ) ? (int) $generated['counters']['sections'] : 0,
			'source_components'  => isset( $source['counters']['components'] ) ? (int) $source['counters']['components'] : 0,
			'generated_components' => isset( $generated['counters']['components'] ) ? (int) $generated['counters']['components'] : 0,
			'mapped_components'  => isset( $generated['counters']['mapped'] ) ? (int) $generated['counters']['mapped'] : 0,
			'overall'            => isset( $metrics['overall']['value'] ) ? $metrics['overall']['value'] : null,
			'headline_differences' => $top,
		);
	}

	/**
	 * Return the limitations of this validation.
	 *
	 * @param array<string, mixed> $visual Visual comparison result.
	 * @return array<int, string>
	 */
	private function limitations( array $visual ) {
		$limitations = array(
			__( 'Phase 5 compares detected structure and effective CSS values. It does not render the pages unless a render provider is configured.', 'replicaforge' ),
			__( 'Phase 2 records no per-component padding, margin, or minimum height, so those properties are reported as unknown rather than compared.', 'replicaforge' ),
			__( 'A source value that was never detected is never treated as a difference and never as a match.', 'replicaforge' ),
			__( 'Fonts, colours, and images detected only in the browser are outside static analysis and are reported as unknown.', 'replicaforge' ),
			__( 'The validation measurement is an internal comparison metric. It is not a visual accuracy score and no pixel-perfect claim is made.', 'replicaforge' ),
			__( 'Interactive behaviour, animations, and JavaScript-driven layout changes are not validated.', 'replicaforge' ),
			__( 'Validation never modifies the Elementor document, never publishes, and never applies the correction plan.', 'replicaforge' ),
		);
		if ( empty( $visual['available'] ) ) {
			$limitations[] = __( 'No rendered comparison was performed, so pixel-level differences are unknown for this run.', 'replicaforge' );
		}
		return $limitations;
	}

	/**
	 * Build a deterministic validation identifier.
	 *
	 * @param string $source_hash    Source hash.
	 * @param string $generated_hash Generated hash.
	 * @return string
	 */
	private function validation_id( $source_hash, $generated_hash ) {
		return 'val_' . substr( hash( 'sha256', $source_hash . '|' . $generated_hash . '|' . Validation_Limits::ENGINE_VERSION ), 0, 24 );
	}

	/**
	 * Build a safe error envelope.
	 *
	 * @param string $code    Error code.
	 * @param string $message User-facing message.
	 * @param int    $status  HTTP-like status.
	 * @return array<string, mixed>
	 */
	private function error( $code, $message, $status ) {
		Security::log_event( 'validation_failed', array( 'code' => sanitize_key( $code ), 'reason' => 'phase5' ) );
		return array(
			'success' => false,
			'error'   => array(
				'code'    => sanitize_key( $code ),
				'message' => (string) $message,
				'status'  => absint( $status ),
			),
		);
	}
}
