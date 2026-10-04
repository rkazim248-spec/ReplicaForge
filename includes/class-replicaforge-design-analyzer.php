<?php
/**
 * Phase 2 deterministic design-understanding pipeline.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Converts Phase 1 analysis into a validated Design Representation.
 */
final class Design_Analyzer {

	/**
	 * DOM analyzer.
	 *
	 * @var Dom_Analyzer
	 */
	private $dom;

	/**
	 * Section detector.
	 *
	 * @var Section_Detector
	 */
	private $sections;

	/**
	 * Style analyzer.
	 *
	 * @var Style_Analyzer
	 */
	private $style;

	/**
	 * Typography analyzer.
	 *
	 * @var Typography_Analyzer
	 */
	private $typography;

	/**
	 * Layout analyzer.
	 *
	 * @var Layout_Analyzer
	 */
	private $layout;

	/**
	 * Component detector.
	 *
	 * @var Component_Detector
	 */
	private $components;

	/**
	 * Responsive analyzer.
	 *
	 * @var Responsive_Analyzer
	 */
	private $responsive;

	/**
	 * Stylesheet loader.
	 *
	 * @var Stylesheet_Loader
	 */
	private $stylesheets;

	/**
	 * Constructor.
	 *
	 * @param Dom_Analyzer|null      $dom        DOM analyzer.
	 * @param Section_Detector|null  $sections   Section detector.
	 * @param Style_Analyzer|null    $style      Style analyzer.
	 * @param Typography_Analyzer|null $typography Typography analyzer.
	 * @param Layout_Analyzer|null   $layout     Layout analyzer.
	 * @param Component_Detector|null $components Component detector.
	 * @param Responsive_Analyzer|null $responsive Responsive analyzer.
	 * @param Stylesheet_Loader|null $stylesheets Stylesheet loader.
	 */
	public function __construct( $dom = null, $sections = null, $style = null, $typography = null, $layout = null, $components = null, $responsive = null, $stylesheets = null ) {
		$this->dom        = $dom instanceof Dom_Analyzer ? $dom : new Dom_Analyzer();
		$this->sections   = $sections instanceof Section_Detector ? $sections : new Section_Detector( $this->dom );
		$this->style      = $style instanceof Style_Analyzer ? $style : new Style_Analyzer();
		$this->typography = $typography instanceof Typography_Analyzer ? $typography : new Typography_Analyzer( $this->dom, $this->style );
		$this->layout     = $layout instanceof Layout_Analyzer ? $layout : new Layout_Analyzer( $this->dom, $this->style );
		$this->components = $components instanceof Component_Detector ? $components : new Component_Detector( $this->dom, $this->style );
		$this->responsive = $responsive instanceof Responsive_Analyzer ? $responsive : new Responsive_Analyzer( $this->dom, $this->style );
		$this->stylesheets = $stylesheets instanceof Stylesheet_Loader ? $stylesheets : new Stylesheet_Loader();
	}

	/**
	 * Run the Phase 2 pipeline.
	 *
	 * @param array<string, mixed> $phase1  Phase 1 analysis.
	 * @param \DOMDocument          $document Sanitized DOM document.
	 * @return array<string, mixed>
	 */
	public function analyze( $phase1, $document ) {
		$started_at = microtime( true );
		try {
			$normalized = Analysis_Normalizer::normalize_phase1( $phase1 );
			$base_url = ! empty( $normalized['page']['final_url'] ) ? $normalized['page']['final_url'] : ( ! empty( $normalized['page']['url'] ) ? $normalized['page']['url'] : '' );
			$context = $this->dom->build( $document, $base_url );
			if ( empty( $context['nodes'] ) ) {
				return $this->error( 'dom_context_unavailable', 'The page structure could not be analyzed.', 502 );
			}

			$discovered = $this->stylesheets->discover( $this->dom->get_elements( $context ), $base_url );
			$loaded = $this->stylesheets->load( $discovered );
			$style_tokens = $this->style->analyze( $context, $loaded['stylesheets'] );
			$section_data = $this->sections->detect( $context );
			$component_data = $this->components->detect( $context, $section_data );
			$layout_data = $this->layout->analyze( $context, $section_data );
			$typography_data = $this->typography->analyze( $context );
			$responsive_data = $this->responsive->analyze( $context, $normalized );

			$section_data = $this->attach_section_data( $context, $section_data, $component_data['components'], $layout_data );
			$components = $this->normalize_components( $component_data['components'] );
			$hierarchy = $this->build_hierarchy( $components );
			$design_system = $this->build_design_system( $style_tokens, $typography_data );
			$assets = $this->build_assets( $normalized, $discovered, $loaded, $components, $style_tokens );
			$confidence = $this->build_confidence( $section_data, $components, $design_system, $responsive_data );
			$warnings = array();
			foreach ( isset( $context['warnings'] ) ? (array) $context['warnings'] : array() as $warning ) {
				$warnings[] = is_scalar( $warning ) ? (string) $warning : 'A DOM analysis warning occurred.';
			}
			foreach ( isset( $loaded['warnings'] ) ? (array) $loaded['warnings'] : array() as $warning ) {
				if ( is_array( $warning ) ) {
					$warnings[] = isset( $warning['message'] ) ? (string) $warning['message'] : 'An external stylesheet warning occurred.';
				} else {
					$warnings[] = is_scalar( $warning ) ? (string) $warning : 'An external stylesheet warning occurred.';
				}
			}
			foreach ( isset( $style_tokens['warnings'] ) ? (array) $style_tokens['warnings'] : array() as $warning ) {
				$warnings[] = is_scalar( $warning ) ? (string) $warning : 'A CSS analysis warning occurred.';
			}

			$representation = array(
				'schema_version' => '2.0',
				'page'           => array(
					'url'         => $normalized['page']['url'],
					'final_url'   => $normalized['page']['final_url'],
					'title'       => $normalized['page']['title'],
					'type'        => $normalized['page']['type'],
					'type_confidence' => $normalized['page']['type_confidence'],
					'type_source' => 'inferred',
					'language'    => $normalized['page']['language'],
					'description' => $normalized['page']['description'],
					'http_status' => $normalized['page']['status'],
				),
				'layout'         => array(
					'page'    => $layout_data['page'],
					'regions' => $context['regions'],
				),
				'sections'       => $section_data,
				'components'     => $components,
				'hierarchy'      => $hierarchy,
				'design_system'  => $design_system,
				'responsive'     => $responsive_data,
				'assets'         => $assets,
				'confidence'     => $confidence,
				'warnings'       => array_values( array_map( 'strval', $warnings ) ),
				'analysis'       => array(
					'phase'        => '2.0',
					'analyzed_at'  => gmdate( 'c' ),
					'duration_ms'  => max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) ),
					'phase1_schema' => '1.0',
				),
			);

			$design_representation = new Design_Representation( $representation );
			if ( ! $design_representation->is_valid() ) {
				Security::log_event( 'design_representation_invalid', array( 'reason' => 'validation' ) );
				return $this->error( 'design_representation_invalid', 'The page structure could not be validated.', 500 );
			}
			return array( 'success' => true, 'representation' => $design_representation->to_array() );
		} catch ( \Throwable $exception ) {
			Security::log_event( 'design_analysis_failed', array( 'reason' => 'exception' ) );
			return $this->error( 'design_analysis_failed', 'The page structure could not be analyzed.', 500 );
		}
	}

	/**
	 * Attach layout, styles, and component IDs to sections.
	 *
	 * @param array<string, mixed>                $context    DOM context.
	 * @param array<int, array<string, mixed>>    $sections   Sections.
	 * @param array<int, array<string, mixed>>    $components Components.
	 * @param array<string, mixed>                $layout     Layout data.
	 * @return array<int, array<string, mixed>>
	 */
	private function attach_section_data( $context, $sections, $components, $layout ) {
		$by_section = array();
		foreach ( $components as $component ) {
			if ( ! empty( $component['section_id'] ) ) {
				$by_section[ $component['section_id'] ][] = $component['id'];
			}
		}
		foreach ( $sections as $index => $section ) {
			$sections[ $index ]['components'] = isset( $by_section[ $section['id'] ] ) ? $by_section[ $section['id'] ] : array();
			$sections[ $index ]['layout'] = isset( $layout['sections'][ $section['node_id'] ] ) ? $layout['sections'][ $section['node_id'] ] : array();
			$sections[ $index ]['styles'] = $this->section_styles( $context, $section['node_id'] );
			unset( $sections[ $index ]['node_id'] );
		}
		return $sections;
	}

	/**
	 * Return selected section styles.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return array<string, string>
	 */
	private function section_styles( $context, $node_id ) {
		$declarations = $this->style->get_declarations( $this->dom, $context, $node_id );
		$allowed = array( 'display', 'flex-direction', 'justify-content', 'align-items', 'gap', 'grid-template-columns', 'background', 'background-color', 'color', 'padding', 'margin', 'max-width', 'width' );
		$result = array();
		foreach ( $allowed as $property ) {
			if ( isset( $declarations[ $property ] ) ) {
				$result[ $property ] = $declarations[ $property ];
			}
		}
		return $result;
	}

	/**
	 * Normalize component records before representation validation.
	 *
	 * @param array<int, array<string, mixed>> $components Components.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_components( $components ) {
		$result = array();
		foreach ( $components as $component ) {
			$component['text'] = Analysis_Normalizer::text( isset( $component['text'] ) ? $component['text'] : '', 700 );
			$component['confidence'] = Analysis_Normalizer::confidence( isset( $component['confidence'] ) ? $component['confidence'] : 0 );
			if ( isset( $component['source']['selector'] ) ) {
				$component['source']['selector'] = Analysis_Normalizer::selector( $component['source']['selector'] );
			}
			$result[] = $component;
		}
		return array_slice( $result, 0, Analysis_Limits::MAX_COMPONENTS );
	}

	/**
	 * Build a conservative component hierarchy.
	 *
	 * @param array<int, array<string, mixed>> $components Components.
	 * @return array<string, array<int, string>>
	 */
	private function build_hierarchy( $components ) {
		$hierarchy = array(
			'primary'     => array(),
			'secondary'   => array(),
			'supporting'  => array(),
			'cta'         => array(),
			'decorative'  => array(),
		);
		foreach ( $components as $component ) {
			$type = isset( $component['type'] ) ? $component['type'] : '';
			$role = isset( $component['role'] ) ? $component['role'] : '';
			if ( 'primary_heading' === $role ) {
				$hierarchy['primary'][] = $component['id'];
			} elseif ( in_array( $role, array( 'section_heading', 'subheading' ), true ) ) {
				$hierarchy['secondary'][] = $component['id'];
			} elseif ( 'call_to_action' === $role ) {
				$hierarchy['cta'][] = $component['id'];
			} elseif ( in_array( $role, array( 'decorative_image', 'icon' ), true ) || 'background_image' === $type ) {
				$hierarchy['decorative'][] = $component['id'];
			} elseif ( in_array( $type, array( 'paragraph', 'list', 'tabs', 'tab', 'form', 'form_field', 'card_group', 'product_card', 'feature_card', 'testimonial_card', 'pricing_card', 'blog_card', 'team_card', 'portfolio_card', 'image' ), true ) ) {
				$hierarchy['supporting'][] = $component['id'];
			}
		}
		foreach ( $hierarchy as $key => $ids ) {
			$hierarchy[ $key ] = array_slice( array_values( array_unique( $ids ) ), 0, 100 );
		}
		return $hierarchy;
	}

	/**
	 * Build the design-system portion of the representation.
	 *
	 * @param array<string, mixed> $style_tokens Style tokens.
	 * @param array<string, mixed> $typography  Typography analysis.
	 * @return array<string, mixed>
	 */
	private function build_design_system( $style_tokens, $typography ) {
		$spacing = $this->extract_scale( isset( $style_tokens['spacing'] ) ? $style_tokens['spacing'] : array() );
		$radius = $this->extract_scale( isset( $style_tokens['radius'] ) ? $style_tokens['radius'] : array() );
		$colors = isset( $style_tokens['colors'] ) ? $style_tokens['colors'] : array( 'tokens' => array(), 'by_role' => array() );
		$shadows = isset( $style_tokens['shadows'] ) ? $style_tokens['shadows'] : array();
		$buttons = isset( $style_tokens['button_variants'] ) ? $style_tokens['button_variants'] : array();
		$containers = isset( $style_tokens['containers'] ) ? $style_tokens['containers'] : array();
		return array(
			'colors'    => $colors,
			'typography' => $typography,
			'font_families' => isset( $style_tokens['fonts'] ) ? $style_tokens['fonts'] : array(),
			'spacing'   => array( 'scale' => $spacing ),
			'radius'    => array( 'scale' => $radius ),
			'shadows'   => isset( $style_tokens['shadows'] ) ? $style_tokens['shadows'] : array(),
			'buttons'   => isset( $style_tokens['button_variants'] ) ? $style_tokens['button_variants'] : array(),
			'containers' => isset( $style_tokens['containers'] ) ? $style_tokens['containers'] : array(),
			'layout_rules' => isset( $style_tokens['layout_rules'] ) ? $style_tokens['layout_rules'] : array(),
			'design_tokens' => array(
				'colors'    => $colors,
				'font_sizes' => isset( $style_tokens['font_sizes'] ) ? $style_tokens['font_sizes'] : array(),
				'font_weights' => isset( $style_tokens['font_weights'] ) ? $style_tokens['font_weights'] : array(),
				'spacing'   => $spacing,
				'radius'    => $radius,
				'shadows'   => $shadows,
				'containers' => $containers,
				'buttons'   => $buttons,
			),
		);
	}

	/**
	 * Build safe asset metadata without returning raw CSS or image bytes.
	 *
	 * @param array<string, mixed>             $normalized  Normalized Phase 1.
	 * @param array<int, array<string, mixed>> $discovered  Discovered stylesheets.
	 * @param array<string, mixed>             $loaded      Loaded stylesheets.
	 * @param array<int, array<string, mixed>> $components  Components.
	 * @param array<string, mixed>             $style       Style tokens.
	 * @return array<string, mixed>
	 */
	private function build_assets( $normalized, $discovered, $loaded, $components, $style ) {
		$image_roles = array();
		foreach ( $components as $component ) {
			if ( in_array( $component['type'], array( 'image', 'background_image' ), true ) && ! empty( $component['image']['src'] ) ) {
				$role = isset( $component['role'] ) ? $component['role'] : 'content_image';
				$image_roles[ $role ] = isset( $image_roles[ $role ] ) ? $image_roles[ $role ] + 1 : 1;
			}
		}
		$stylesheet_meta = array();
		foreach ( $loaded['stylesheets'] as $stylesheet ) {
			$stylesheet_meta[] = array( 'url' => $stylesheet['url'], 'final_url' => $stylesheet['final_url'], 'bytes' => absint( $stylesheet['bytes'] ), 'status' => absint( $stylesheet['status'] ), 'media' => $stylesheet['media'] );
		}
		return array(
			'stylesheets' => array( 'discovered_count' => count( $discovered ), 'loaded' => $stylesheet_meta, 'fetched_css_bytes' => isset( $loaded['stats']['bytes'] ) ? absint( $loaded['stats']['bytes'] ) : 0 ),
			'images'      => array( 'count' => isset( $normalized['content']['images'] ) ? count( $normalized['content']['images'] ) : 0, 'roles' => $image_roles ),
			'fonts'       => isset( $style['fonts'] ) ? $style['fonts'] : array(),
			'counts'      => array( 'images_not_downloaded' => true, 'javascript_executed' => false, 'raw_css_returned' => false ),
		);
	}

	/**
	 * Build aggregate confidence metadata.
	 *
	 * @param array<int, array<string, mixed>> $sections      Sections.
	 * @param array<int, array<string, mixed>> $components    Components.
	 * @param array<string, mixed>             $design_system Design system.
	 * @param array<string, mixed>             $responsive    Responsive data.
	 * @return array<string, mixed>
	 */
	private function build_confidence( $sections, $components, $design_system, $responsive ) {
		$section_confidence = $this->average_confidence( $sections );
		$component_confidence = $this->average_confidence( $components );
		$has_typography = false;
		if ( ! empty( $design_system['typography']['hierarchy'] ) && is_array( $design_system['typography']['hierarchy'] ) ) {
			foreach ( $design_system['typography']['hierarchy'] as $typography ) {
				if ( is_array( $typography ) && ( ! empty( $typography['font_family'] ) || ! empty( $typography['font_size'] ) || ! empty( $typography['font_weight'] ) || ! empty( $typography['line_height'] ) ) ) {
					$has_typography = true;
					break;
				}
			}
		}
		$design_confidence = ! empty( $design_system['colors']['tokens'] ) || $has_typography ? 0.68 : 0.3;
		$responsive_confidence = isset( $responsive['confidence'] ) ? Analysis_Normalizer::confidence( $responsive['confidence'] ) : 0.2;
		$overall = ! empty( $sections ) || ! empty( $components ) ? ( $section_confidence * 0.35 + $component_confidence * 0.3 + $design_confidence * 0.2 + $responsive_confidence * 0.15 ) : 0.1;
		return array(
			'overall'       => Analysis_Normalizer::confidence( $overall ),
			'sections'      => $section_confidence,
			'components'    => $component_confidence,
			'design_system' => Analysis_Normalizer::confidence( $design_confidence ),
			'responsive'    => $responsive_confidence,
		);
	}

	/**
	 * Extract a sorted scale from token records.
	 *
	 * @param array<int, array<string, mixed>> $tokens Token records.
	 * @return array<int, string>
	 */
	private function extract_scale( $tokens ) {
		$values = array();
		foreach ( (array) $tokens as $token ) {
			$value = is_array( $token ) && isset( $token['value'] ) ? $token['value'] : $token;
			$value = Analysis_Normalizer::css_value( $value, 80 );
			if ( '' !== $value && ! in_array( $value, $values, true ) ) {
				$values[] = $value;
			}
		}
		usort( $values, array( $this, 'compare_css_values' ) );
		return array_slice( $values, 0, Analysis_Limits::MAX_TOKENS_PER_FAMILY );
	}

	/**
	 * Compare CSS scale values without assuming they are all the same unit.
	 *
	 * @param string $left  Left value.
	 * @param string $right Right value.
	 * @return int
	 */
	public function compare_css_values( $left, $right ) {
		$left_unit = strtolower( substr( $left, -2 ) );
		$right_unit = strtolower( substr( $right, -2 ) );
		if ( $left_unit === $right_unit ) {
			$numeric = (float) $left <=> (float) $right;
			return 0 !== $numeric ? $numeric : strnatcasecmp( $left, $right );
		}
		return strnatcasecmp( $left, $right );
	}

	/**
	 * Average confidence across records.
	 *
	 * @param array<int, array<string, mixed>> $records Records.
	 * @return float
	 */
	private function average_confidence( $records ) {
		if ( empty( $records ) ) {
			return 0.0;
		}
		$total = 0.0;
		$count = 0;
		foreach ( $records as $record ) {
			if ( isset( $record['confidence'] ) && is_numeric( $record['confidence'] ) ) {
				$total += (float) $record['confidence'];
				$count++;
			}
		}
		return $count > 0 ? Analysis_Normalizer::confidence( $total / $count ) : 0.0;
	}

	/**
	 * Create a safe error envelope.
	 *
	 * @param string $code    Error code.
	 * @param string $message User-facing message.
	 * @param int    $status  Error status.
	 * @return array<string, mixed>
	 */
	private function error( $code, $message, $status ) {
		return array( 'success' => false, 'error' => array( 'code' => sanitize_key( $code ), 'message' => $message, 'status' => absint( $status ) ) );
	}
}
