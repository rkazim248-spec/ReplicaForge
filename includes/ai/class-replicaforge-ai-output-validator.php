<?php
/**
 * Strict validation and source-consistency checks for Phase 3 AI output.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Treats provider output as untrusted structured data and fails closed.
 */
final class Ai_Output_Validator {

	/**
	 * Validation errors.
	 *
	 * @var array<int, string>
	 */
	private $errors = array();

	/**
	 * Non-fatal validation warnings.
	 *
	 * @var array<int, string>
	 */
	private $warnings = array();

	/**
	 * Known source section IDs.
	 *
	 * @var array<string, bool>
	 */
	private $section_ids = array();

	/**
	 * Known source component IDs.
	 *
	 * @var array<string, bool>
	 */
	private $component_ids = array();

	/**
	 * Known source components indexed by ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $components = array();

	/**
	 * Known source sections indexed by ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $sections = array();

	/**
	 * Known source values grouped by kind.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private $source_values = array();

	/**
	 * Whether every bounded source record must be present.
	 *
	 * @var bool
	 */
	private $require_complete = true;

	/**
	 * Responsive viewport evidence.
	 *
	 * @var array<string, bool>
	 */
	private $responsive_evidence = array();

	/**
	 * Validate provider output against the Phase 2 representation.
	 *
	 * @param mixed $output         Provider output.
	 * @param mixed $representation Phase 2 representation.
	 * @param bool  $require_complete Whether every bounded source record must be present.
	 * @return array{valid: bool, errors: array<int, string>, warnings: array<int, string>}
	 */
	public function validate( $output, $representation, $require_complete = true ) {
		$this->reset();
		$this->require_complete = (bool) $require_complete;
		$this->index_source( $representation );
		if ( ! is_array( $output ) ) {
			$this->error( 'output_not_object' );
			return $this->result();
		}
		$this->validate_shape( $output );
		if ( ! empty( $this->errors ) ) {
			return $this->result();
		}
		$this->validate_sections( $output['sections'] );
		$this->validate_components( $output['components'] );
		$this->validate_assets( $output['assets'] );
		$this->validate_content_mapping( $output['content_mapping'] );
		$this->validate_hierarchy( $output['hierarchy'] );
		$this->validate_design_values( $output['global_styles'] );
		$this->validate_design_values( $output['design_system'] );
		$this->validate_responsive( $output['responsive_strategy'] );
		$this->validate_confidence( $output['confidence'] );
		$this->validate_warnings( $output['warnings'] );
		$this->validate_validation_object( $output['validation'] );
		$this->validate_recursive_safety( $output, '', 0 );
		return $this->result();
	}

	/**
	 * Reset validator state.
	 *
	 * @return void
	 */
	private function reset() {
		$this->errors               = array();
		$this->warnings             = array();
		$this->section_ids          = array();
		$this->component_ids        = array();
		$this->components           = array();
		$this->sections             = array();
		$this->source_values        = array( 'color' => array(), 'font_family' => array(), 'font_size' => array(), 'font_weight' => array(), 'line_height' => array(), 'css' => array(), 'link' => array(), 'image' => array(), 'text' => array(), 'field' => array() );
		$this->require_complete     = true;
		$this->responsive_evidence  = array();
	}

	/**
	 * Index source IDs, values, and evidence.
	 *
	 * @param mixed $representation Representation.
	 * @return void
	 */
	private function index_source( $representation ) {
		$representation = is_array( $representation ) ? $representation : array();
		$page = isset( $representation['page'] ) && is_array( $representation['page'] ) ? $representation['page'] : array();
		foreach ( array( 'url', 'final_url' ) as $page_key ) {
			if ( isset( $page[ $page_key ] ) && is_string( $page[ $page_key ] ) ) {
				$this->add_source_value( 'link', $page[ $page_key ] );
			}
		}
		foreach ( isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array() as $section ) {
			if ( is_array( $section ) && isset( $section['id'] ) && is_string( $section['id'] ) ) {
				$this->section_ids[ $section['id'] ] = true;
				$this->sections[ $section['id'] ] = $section;
			}
		}
		foreach ( isset( $representation['components'] ) && is_array( $representation['components'] ) ? $representation['components'] : array() as $component ) {
			if ( ! is_array( $component ) || ! isset( $component['id'] ) || ! is_string( $component['id'] ) ) {
				continue;
			}
			$id = $component['id'];
			$this->component_ids[ $id ] = true;
			$this->components[ $id ] = $component;
			$this->index_component_values( $component );
		}
		$this->index_design_values( $representation );
		$this->index_css_values( isset( $representation['design_system'] ) ? $representation['design_system'] : array() );
		foreach ( array( 'sections', 'components' ) as $collection ) {
			foreach ( isset( $representation[ $collection ] ) && is_array( $representation[ $collection ] ) ? $representation[ $collection ] : array() as $record ) {
				if ( is_array( $record ) ) {
					$this->index_css_values( isset( $record['styles'] ) ? $record['styles'] : array() );
					$this->index_css_values( isset( $record['layout'] ) ? $record['layout'] : array() );
				}
			}
		}
		$this->index_responsive_values( $representation );
	}

	/**
	 * Index component content and references.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return void
	 */
	private function index_component_values( array $component ) {
		if ( isset( $component['text'] ) && is_scalar( $component['text'] ) && '' !== trim( (string) $component['text'] ) ) {
			$this->add_source_value( 'text', (string) $component['text'] );
		}
		if ( isset( $component['url'] ) && is_string( $component['url'] ) ) {
			$this->add_source_value( 'link', $component['url'] );
		}
		if ( isset( $component['image']['src'] ) && is_string( $component['image']['src'] ) ) {
			$this->add_source_value( 'image', $component['image']['src'] );
		}
		if ( isset( $component['fields'] ) && is_array( $component['fields'] ) ) {
			foreach ( $component['fields'] as $field => $value ) {
				if ( 'image' === $field && is_string( $value ) ) {
					$this->add_source_value( 'image', $value );
				} elseif ( 'link' === $field && is_string( $value ) ) {
					$this->add_source_value( 'link', $value );
				} elseif ( is_scalar( $value ) && null !== $value ) {
					$this->add_source_value( 'field', (string) $value );
					$this->add_source_value( 'text', (string) $value );
				}
			}
		}
	}

	/**
	 * Index design-system values.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return void
	 */
	private function index_design_values( array $representation ) {
		$system = isset( $representation['design_system'] ) && is_array( $representation['design_system'] ) ? $representation['design_system'] : array();
		$colors = isset( $system['colors'] ) && is_array( $system['colors'] ) ? $system['colors'] : array();
		foreach ( isset( $colors['tokens'] ) && is_array( $colors['tokens'] ) ? $colors['tokens'] : array() as $token ) {
			if ( is_array( $token ) && isset( $token['value'] ) ) {
				$this->add_source_value( 'color', (string) $token['value'] );
			}
		}
		foreach ( isset( $colors['by_role'] ) && is_array( $colors['by_role'] ) ? $colors['by_role'] : array() as $token ) {
			if ( is_array( $token ) && isset( $token['value'] ) ) {
				$this->add_source_value( 'color', (string) $token['value'] );
			}
		}
		$type = isset( $system['typography'] ) && is_array( $system['typography'] ) ? $system['typography'] : array();
		foreach ( isset( $type['hierarchy'] ) && is_array( $type['hierarchy'] ) ? $type['hierarchy'] : array() as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			foreach ( array( 'font_family' => 'font_family', 'font_size' => 'font_size', 'font_weight' => 'font_weight', 'line_height' => 'line_height' ) as $key => $kind ) {
				if ( isset( $record[ $key ] ) && is_scalar( $record[ $key ] ) ) {
					$this->add_source_value( $kind, (string) $record[ $key ] );
				}
			}
		}
		foreach ( array( 'families', 'sizes', 'weights', 'line_heights' ) as $key ) {
			$kind = 'families' === $key ? 'font_family' : ( 'sizes' === $key ? 'font_size' : ( 'weights' === $key ? 'font_weight' : 'line_height' ) );
			foreach ( isset( $type[ $key ] ) && is_array( $type[ $key ] ) ? $type[ $key ] : array() as $record ) {
				if ( is_array( $record ) && isset( $record['value'] ) ) {
					$this->add_source_value( $kind, (string) $record['value'] );
				} elseif ( is_scalar( $record ) ) {
					$this->add_source_value( $kind, (string) $record );
				}
			}
		}
	}

	/**
	 * Index scalar CSS-like values that may legitimately inform responsive output.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return void
	 */
	private function index_css_values( $value, $depth = 0 ) {
		if ( $depth > 10 ) {
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child_key => $child ) {
				$key = is_string( $child_key ) ? strtolower( $child_key ) : '';
				if ( in_array( $key, array( 'value', 'font_family', 'font_size', 'font_weight', 'line_height', 'padding', 'margin', 'gap', 'width', 'max_width', 'height', 'min_height', 'color', 'background', 'background-color', 'border', 'border-radius', 'grid-template-columns', 'display', 'flex-direction' ), true ) && is_scalar( $child ) ) {
					$this->add_source_value( 'css', $child );
				}
				$this->index_css_values( $child, $depth + 1 );
			}
		} elseif ( is_scalar( $value ) ) {
			$this->add_source_value( 'css', $value );
		}
	}

	/**
	 * Index responsive evidence.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return void
	 */
	private function index_responsive_values( array $representation ) {
		$responsive = isset( $representation['responsive'] ) && is_array( $representation['responsive'] ) ? $representation['responsive'] : array();
		foreach ( isset( $responsive['rules'] ) && is_array( $responsive['rules'] ) ? $responsive['rules'] : array() as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$viewport = $this->viewport_for_breakpoint( isset( $rule['breakpoint'] ) ? (string) $rule['breakpoint'] : '' );
			if ( 'unknown' !== $viewport ) {
				$this->responsive_evidence[ $viewport ] = true;
			}
		}
	}

	/**
	 * Validate required top-level shape and safe primitive values.
	 *
	 * @param array<string, mixed> $output Output.
	 * @return void
	 */
	private function validate_shape( array $output ) {
		$required = array( 'schema_version', 'prompt_version', 'page_strategy', 'global_styles', 'sections', 'components', 'hierarchy', 'design_system', 'responsive_strategy', 'assets', 'content_mapping', 'confidence', 'warnings', 'validation' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $output ) ) {
				$this->error( 'missing_' . $key );
			}
		}
		if ( ! isset( $output['schema_version'] ) || '3.0' !== $output['schema_version'] ) {
			$this->error( 'invalid_schema_version' );
		}
		if ( ! isset( $output['prompt_version'] ) || '1.0' !== $output['prompt_version'] ) {
			$this->error( 'invalid_prompt_version' );
		}
		foreach ( array( 'page_strategy', 'global_styles', 'hierarchy', 'design_system', 'responsive_strategy' ) as $key ) {
			if ( isset( $output[ $key ] ) && ! is_array( $output[ $key ] ) ) {
				$this->error( 'invalid_' . $key );
			}
		}
		foreach ( array( 'sections', 'components', 'assets', 'content_mapping', 'warnings' ) as $key ) {
			if ( isset( $output[ $key ] ) && ! is_array( $output[ $key ] ) ) {
				$this->error( 'invalid_' . $key );
			}
		}
		$allowed_top_level = array_merge( $required, array( 'provenance' ) );
		foreach ( array_keys( $output ) as $key ) {
			if ( ! is_string( $key ) ) {
				$this->error( 'invalid_top_level_key' );
			} elseif ( ! in_array( $key, $allowed_top_level, true ) ) {
				$this->error( 'unexpected_top_level_key' );
			}
		}
		foreach ( array( 'layout_type', 'container_strategy', 'section_spacing_strategy', 'global_typography', 'responsive_strategy' ) as $key ) {
			if ( ! is_array( $output['page_strategy'] ) || ! isset( $output['page_strategy'][ $key ] ) || ! is_string( $output['page_strategy'][ $key ] ) ) {
				$this->error( 'missing_page_strategy_field' );
			}
		}
		foreach ( array( 'global_styles', 'design_system' ) as $key ) {
			foreach ( array( 'colors', 'typography', 'spacing', 'radius', 'shadows', 'buttons' ) as $field ) {
				if ( ! is_array( $output[ $key ] ) || ! isset( $output[ $key ][ $field ] ) || ! is_array( $output[ $key ][ $field ] ) ) {
					$this->error( 'missing_design_system_field' );
				}
			}
		}
		if ( is_array( $output['page_strategy'] ) && isset( $output['page_strategy']['confidence'] ) && ! $this->valid_confidence( $output['page_strategy']['confidence'] ) ) {
			$this->error( 'invalid_page_strategy_confidence' );
		}
	}

	/**
	 * Validate section plans.
	 *
	 * @param mixed $sections Sections.
	 * @return void
	 */
	private function validate_sections( $sections ) {
		if ( ! is_array( $sections ) || count( $sections ) > Ai_Limits::MAX_SECTIONS ) {
			$this->error( 'invalid_section_count' );
			return;
		}
		$seen_ids = array();
		$seen_sources = array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				$this->error( 'invalid_section_record' );
				continue;
			}
			$id = isset( $section['id'] ) && is_string( $section['id'] ) ? $section['id'] : '';
			$source_id = isset( $section['source_id'] ) && is_string( $section['source_id'] ) ? $section['source_id'] : '';
			if ( ! preg_match( '/^reconstruction_section_[0-9]{3,}$/', $id ) || isset( $seen_ids[ $id ] ) ) {
				$this->error( 'invalid_or_duplicate_reconstruction_section_id' );
			}
			$seen_ids[ $id ] = true;
			if ( ! isset( $this->section_ids[ $source_id ] ) || isset( $seen_sources[ $source_id ] ) ) {
				$this->error( 'hallucinated_or_duplicate_section_reference' );
			}
			$seen_sources[ $source_id ] = true;
			if ( ! isset( $section['type'] ) || ! is_string( $section['type'] ) || '' === trim( $section['type'] ) ) {
				$this->error( 'invalid_section_type' );
			}
			if ( ! $this->valid_confidence( isset( $section['confidence'] ) ? $section['confidence'] : null ) ) {
				$this->error( 'invalid_section_confidence' );
			}
			if ( ! isset( $section['layout'] ) || ! is_array( $section['layout'] ) || ! isset( $section['components'] ) || ! is_array( $section['components'] ) || ! isset( $section['responsive'] ) || ! is_array( $section['responsive'] ) ) {
				$this->error( 'invalid_section_plan_fields' );
			}
			foreach ( isset( $section['components'] ) && is_array( $section['components'] ) ? $section['components'] : array() as $component_id ) {
				if ( ! is_string( $component_id ) || ! isset( $this->component_ids[ $component_id ] ) ) {
					$this->error( 'hallucinated_section_component_reference' );
				}
			}
			$source_section = isset( $this->sections[ $source_id ] ) ? $this->sections[ $source_id ] : array();
			$source_content = isset( $source_section['content'] ) && is_array( $source_section['content'] ) ? $source_section['content'] : array();
			$planned_content = isset( $section['content_summary'] ) && is_array( $section['content_summary'] ) ? $section['content_summary'] : array();
			foreach ( array( 'heading', 'text' ) as $content_key ) {
				if ( array_key_exists( $content_key, $planned_content ) && null !== $planned_content[ $content_key ] && ! $this->scalar_matches( isset( $source_content[ $content_key ] ) ? $source_content[ $content_key ] : null, $planned_content[ $content_key ] ) ) {
					$this->error( 'hallucinated_section_content' );
				}
			}
		}
		if ( $this->require_complete && count( $this->section_ids ) <= Ai_Limits::MAX_SECTIONS && count( $seen_sources ) !== count( $this->section_ids ) ) {
			$this->error( 'not_every_source_section_is_represented' );
		}
		if ( count( $sections ) > count( $this->section_ids ) ) {
			$this->error( 'section_count_exceeds_source' );
		}
	}

	/**
	 * Validate component plans.
	 *
	 * @param mixed $components Components.
	 * @return void
	 */
	private function validate_components( $components ) {
		if ( ! is_array( $components ) || count( $components ) > Ai_Limits::MAX_COMPONENTS ) {
			$this->error( 'invalid_component_count' );
			return;
		}
		$seen_ids = array();
		$seen_sources = array();
		$product_count = 0;
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				$this->error( 'invalid_component_record' );
				continue;
			}
			$id = isset( $component['id'] ) && is_string( $component['id'] ) ? $component['id'] : '';
			$source_id = isset( $component['source_id'] ) && is_string( $component['source_id'] ) ? $component['source_id'] : '';
			if ( ! preg_match( '/^reconstruction_component_[0-9]{3,}$/', $id ) || isset( $seen_ids[ $id ] ) ) {
				$this->error( 'invalid_or_duplicate_reconstruction_component_id' );
			}
			$seen_ids[ $id ] = true;
			if ( ! isset( $this->component_ids[ $source_id ] ) || isset( $seen_sources[ $source_id ] ) ) {
				$this->error( 'hallucinated_or_duplicate_component_reference' );
			}
			$seen_sources[ $source_id ] = true;
			$source = isset( $this->components[ $source_id ] ) ? $this->components[ $source_id ] : array();
			if ( isset( $source['type'] ) && 'product_card' === $source['type'] ) {
				$product_count++;
			}
			foreach ( array( 'id', 'source_id', 'type', 'reconstruction_type', 'role', 'content_source', 'confidence' ) as $key ) {
				if ( ! array_key_exists( $key, $component ) ) {
					$this->error( 'missing_component_plan_field' );
				}
			}
			if ( ! is_string( $component['type'] ?? null ) || ! is_string( $component['reconstruction_type'] ?? null ) || ! is_string( $component['role'] ?? null ) ) {
				$this->error( 'invalid_component_plan_type' );
			}
			if ( isset( $component['reconstruction_type'] ) && ! in_array( $component['reconstruction_type'], $this->allowed_reconstruction_types(), true ) ) {
				$this->error( 'unsupported_reconstruction_type' );
			}
			if ( ! in_array( $component['content_source'] ?? '', array( 'source', 'not_detected', 'unknown' ), true ) ) {
				$this->error( 'invalid_component_content_source' );
			}
			if ( ! $this->valid_confidence( $component['confidence'] ?? null ) ) {
				$this->error( 'invalid_component_confidence' );
			}
			if ( isset( $component['section_id'] ) && null !== $component['section_id'] && ( ! is_string( $component['section_id'] ) || ! isset( $this->section_ids[ $component['section_id'] ] ) ) ) {
				$this->error( 'invalid_component_plan_section_reference' );
			}
			if ( array_key_exists( 'count', $component ) && isset( $source['count'] ) && (int) $component['count'] !== (int) $source['count'] ) {
				$this->error( 'hallucinated_repeated_card_count' );
			}
			$this->validate_component_plan_values( $component, $source );
		}
		if ( count( $components ) > count( $this->component_ids ) ) {
			$this->error( 'component_count_exceeds_source' );
		}
		if ( $this->require_complete && count( $this->component_ids ) <= Ai_Limits::MAX_COMPONENTS && count( $seen_sources ) !== count( $this->component_ids ) ) {
			$this->error( 'not_every_source_component_is_represented' );
		}
		if ( $product_count > $this->source_product_count() ) {
			$this->error( 'hallucinated_product_count' );
		}
	}

	/**
	 * Validate optional values copied into a component plan.
	 *
	 * @param array<string, mixed> $plan AI component plan.
	 * @param array<string, mixed> $source Source component.
	 * @return void
	 */
	private function validate_component_plan_values( array $plan, array $source ) {
		if ( array_key_exists( 'text', $plan ) && null !== $plan['text'] && ! $this->value_is_source_backed( $source, $plan['text'], '' ) ) {
			$this->error( 'hallucinated_component_text' );
		}
		foreach ( array( 'url', 'target' ) as $key ) {
			if ( array_key_exists( $key, $plan ) && null !== $plan[ $key ] && ( ! is_string( $plan[ $key ] ) || ! isset( $this->source_values['link'][ $this->value_key( $plan[ $key ] ) ] ) ) ) {
				$this->error( 'hallucinated_component_link' );
			}
		}
		if ( isset( $plan['image']['src'] ) && null !== $plan['image']['src'] && ( ! is_string( $plan['image']['src'] ) || ! isset( $this->source_values['image'][ $this->value_key( $plan['image']['src'] ) ] ) ) ) {
			$this->error( 'hallucinated_component_image' );
		}
		if ( isset( $plan['image']['alt'] ) && null !== $plan['image']['alt'] ) {
			$source_alt = isset( $source['image']['alt'] ) ? $source['image']['alt'] : null;
			if ( ! $this->scalar_matches( $source_alt, $plan['image']['alt'] ) ) {
				$this->error( 'hallucinated_component_image_alt' );
			}
		}
		if ( isset( $plan['fields'] ) && is_array( $plan['fields'] ) ) {
			$source_fields = isset( $source['fields'] ) && is_array( $source['fields'] ) ? $source['fields'] : array();
			foreach ( $plan['fields'] as $field => $value ) {
				if ( null === $value || ! array_key_exists( $field, $source_fields ) ) {
					if ( null !== $value ) {
						$this->error( 'hallucinated_component_field' );
					}
					continue;
				}
				if ( ! $this->scalar_matches( $source_fields[ $field ], $value ) && ! ( is_string( $value ) && isset( $this->source_values['image'][ $this->value_key( $value ) ] ) ) && ! ( is_string( $value ) && isset( $this->source_values['link'][ $this->value_key( $value ) ] ) ) ) {
					$this->error( 'hallucinated_component_field' );
				}
			}
		}
	}

	/**
	 * Validate asset mappings.
	 *
	 * @param mixed $assets Assets.
	 * @return void
	 */
	private function validate_assets( $assets ) {
		if ( ! is_array( $assets ) || count( $assets ) > Ai_Limits::MAX_ASSETS ) {
			$this->error( 'invalid_asset_count' );
			return;
		}
		$seen = array();
		$image_sources = $this->source_image_ids();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				$this->error( 'invalid_asset_record' );
				continue;
			}
			$source_id = isset( $asset['source_id'] ) && is_string( $asset['source_id'] ) ? $asset['source_id'] : '';
			if ( ! isset( $image_sources[ $source_id ] ) || isset( $seen[ $source_id ] ) ) {
				$this->error( 'hallucinated_or_duplicate_asset_reference' );
			}
			$seen[ $source_id ] = true;
			if ( array_key_exists( 'source_url', $asset ) && null !== $asset['source_url'] ) {
				if ( ! is_string( $asset['source_url'] ) || ! isset( $this->source_values['image'][ $this->value_key( $asset['source_url'] ) ] ) ) {
					$this->error( 'hallucinated_asset_url' );
				}
			}
			if ( array_key_exists( 'preserve', $asset ) && ! is_bool( $asset['preserve'] ) ) {
				$this->error( 'invalid_asset_preserve_value' );
			}
			if ( isset( $asset['preserve'] ) && true === $asset['preserve'] && ( ! isset( $asset['source_url'] ) || null === $asset['source_url'] ) ) {
				$this->error( 'asset_preserved_without_source_url' );
			}
			if ( isset( $asset['preserve'] ) && false === $asset['preserve'] && ( ! isset( $asset['reason'] ) || ! is_string( $asset['reason'] ) || '' === trim( $asset['reason'] ) ) ) {
				$this->error( 'unavailable_asset_missing_reason' );
			}
		}
		if ( count( $assets ) > count( $image_sources ) ) {
			$this->error( 'asset_count_exceeds_source' );
		}
	}

	/**
	 * Validate content mappings and exact source values.
	 *
	 * @param mixed $mapping Content mappings.
	 * @return void
	 */
	private function validate_content_mapping( $mapping ) {
		if ( ! is_array( $mapping ) || count( $mapping ) > Ai_Limits::MAX_CONTENT_ITEMS ) {
			$this->error( 'invalid_content_mapping_count' );
			return;
		}
		$product_records = 0;
		$seen_content_ids = array();
		foreach ( $mapping as $entry ) {
			if ( ! is_array( $entry ) ) {
				$this->error( 'invalid_content_mapping_record' );
				continue;
			}
			$content_id = isset( $entry['id'] ) && is_string( $entry['id'] ) ? $entry['id'] : '';
			if ( '' !== $content_id ) {
				if ( isset( $seen_content_ids[ $content_id ] ) ) {
					$this->error( 'duplicate_content_mapping_id' );
				}
				$seen_content_ids[ $content_id ] = true;
			}
			if ( 'product' === strtolower( (string) ( isset( $entry['content_type'] ) ? $entry['content_type'] : '' ) ) || 'product' === strtolower( (string) ( isset( $entry['record_type'] ) ? $entry['record_type'] : '' ) ) ) {
				$product_records++;
			}
			$component_id = isset( $entry['component_id'] ) && is_string( $entry['component_id'] ) ? $entry['component_id'] : '';
			if ( ! isset( $this->component_ids[ $component_id ] ) ) {
				$this->error( 'hallucinated_content_component_reference' );
				continue;
			}
			$component = $this->components[ $component_id ];
			if ( array_key_exists( 'value', $entry ) && null !== $entry['value'] ) {
				if ( ! $this->value_is_source_backed( $component, $entry['value'], isset( $entry['field'] ) ? $entry['field'] : '' ) ) {
					$this->error( 'hallucinated_content_value' );
				}
			}
			if ( array_key_exists( 'target', $entry ) && null !== $entry['target'] && ( ! is_string( $entry['target'] ) || ! isset( $this->source_values['link'][ $this->value_key( $entry['target'] ) ] ) ) ) {
				$this->error( 'hallucinated_content_link' );
			}
			if ( isset( $entry['field'] ) && in_array( strtolower( (string) $entry['field'] ), array( 'price', 'sale_price', 'rating' ), true ) ) {
				$fields = isset( $component['fields'] ) && is_array( $component['fields'] ) ? $component['fields'] : array();
				$field = strtolower( (string) $entry['field'] );
				if ( ! array_key_exists( $field, $fields ) || null === $fields[ $field ] || ! $this->scalar_matches( $fields[ $field ], $entry['value'] ?? null ) ) {
					$this->error( 'hallucinated_product_field' );
				}
			}
		}
		if ( $product_records > $this->source_product_count() ) {
			$this->error( 'hallucinated_product_record_count' );
		}
	}

	/**
	 * Validate hierarchy references recursively.
	 *
	 * @param mixed $hierarchy Hierarchy.
	 * @return void
	 */
	private function validate_hierarchy( $hierarchy ) {
		if ( ! is_array( $hierarchy ) ) {
			$this->error( 'invalid_hierarchy_object' );
			return;
		}
		$sections = isset( $hierarchy['sections'] ) && is_array( $hierarchy['sections'] ) ? $hierarchy['sections'] : array();
		foreach ( $sections as $section_id => $node ) {
			if ( ! is_string( $section_id ) || ! isset( $this->section_ids[ $section_id ] ) ) {
				$this->error( 'hallucinated_hierarchy_section_reference' );
				continue;
			}
			$this->validate_hierarchy_node( $node, 0 );
		}
		$groups = isset( $hierarchy['component_groups'] ) && is_array( $hierarchy['component_groups'] ) ? $hierarchy['component_groups'] : array();
		foreach ( $groups as $ids ) {
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				if ( ! is_string( $id ) || ! isset( $this->component_ids[ $id ] ) ) {
					$this->error( 'hallucinated_hierarchy_component_reference' );
				}
			}
		}
	}

	/**
	 * Validate hierarchy node and descendants.
	 *
	 * @param mixed $node Node.
	 * @param int   $depth Depth.
	 * @return void
	 */
	private function validate_hierarchy_node( $node, $depth ) {
		if ( $depth > 12 || ! is_array( $node ) ) {
			$this->error( 'invalid_hierarchy_node' );
			return;
		}
		if ( isset( $node['source_id'] ) && is_string( $node['source_id'] ) && ! isset( $this->section_ids[ $node['source_id'] ] ) && ! isset( $this->component_ids[ $node['source_id'] ] ) ) {
			$this->error( 'hallucinated_hierarchy_source_reference' );
		}
		foreach ( isset( $node['component_ids'] ) && is_array( $node['component_ids'] ) ? $node['component_ids'] : array() as $id ) {
			if ( ! is_string( $id ) || ! isset( $this->component_ids[ $id ] ) ) {
				$this->error( 'hallucinated_hierarchy_component_reference' );
			}
		}
		foreach ( isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array() as $child ) {
			$this->validate_hierarchy_node( $child, $depth + 1 );
		}
	}

	/**
	 * Validate semantic color and typography values.
	 *
	 * @param mixed $styles Styles.
	 * @return void
	 */
	private function validate_design_values( $styles ) {
		$this->validate_color_records( $styles, 'colors' );
		$this->validate_typography_records( $styles, 'typography' );
		$this->validate_nested_design_values( $styles, 'colors', 0 );
		$this->validate_nested_design_values( $styles, 'typography', 0 );
		foreach ( array( 'spacing', 'radius', 'shadows', 'buttons' ) as $key ) {
			if ( isset( $styles[ $key ] ) ) {
				$this->validate_css_collection( $styles[ $key ], 0 );
			}
		}
	}

	/**
	 * Validate a source-backed CSS/token collection.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return void
	 */
	private function validate_css_collection( $value, $depth ) {
		if ( $depth > 10 ) {
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$is_list_value = is_int( $key ) && is_scalar( $child );
				if ( ( 'value' === $key || $is_list_value ) && is_scalar( $child ) && '' !== trim( (string) $child ) && ! isset( $this->source_values['css'][ $this->value_key( $child ) ] ) ) {
					$this->error( 'hallucinated_css_value' );
				}
				if ( is_array( $child ) ) {
					$this->validate_css_collection( $child, $depth + 1 );
				}
			}
		} elseif ( is_scalar( $value ) && '' !== trim( (string) $value ) && ! isset( $this->source_values['css'][ $this->value_key( $value ) ] ) ) {
			$this->error( 'hallucinated_css_value' );
		}
	}

	/**
	 * Inspect nested token collections that providers commonly use.
	 *
	 * @param mixed  $value Value.
	 * @param string $kind Token kind.
	 * @param int    $depth Depth.
	 * @return void
	 */
	private function validate_nested_design_values( $value, $kind, $depth ) {
		if ( $depth > 8 || ! is_array( $value ) ) {
			return;
		}
		if ( 'colors' === $kind && array_key_exists( 'value', $value ) && null !== $value['value'] ) {
			if ( ! is_string( $value['value'] ) || ! isset( $this->source_values['color'][ $this->value_key( $value['value'] ) ] ) ) {
				$this->error( 'hallucinated_color_value' );
			}
			if ( ! $this->valid_confidence( isset( $value['confidence'] ) ? $value['confidence'] : null ) ) {
				$this->error( 'missing_or_invalid_token_confidence' );
			}
		}
		if ( 'typography' === $kind ) {
			if ( array_key_exists( 'value', $value ) && null !== $value['value'] && isset( $value['property'] ) ) {
				$property_map = array( 'font-family' => 'font_family', 'font-size' => 'font_size', 'font-weight' => 'font_weight', 'line-height' => 'line_height' );
				$property = isset( $property_map[ $value['property'] ] ) ? $property_map[ $value['property'] ] : '';
				if ( '' === $property || ! is_scalar( $value['value'] ) || ! isset( $this->source_values[ $property ][ $this->value_key( (string) $value['value'] ) ] ) ) {
					$this->error( 'hallucinated_typography_value' );
				}
			}
			foreach ( array( 'font_family' => 'font_family', 'font_size' => 'font_size', 'font_weight' => 'font_weight', 'line_height' => 'line_height' ) as $field => $source_kind ) {
				if ( array_key_exists( $field, $value ) && null !== $value[ $field ] && ( ! is_scalar( $value[ $field ] ) || ! isset( $this->source_values[ $source_kind ][ $this->value_key( (string) $value[ $field ] ) ] ) ) ) {
					$this->error( 'hallucinated_typography_value' );
				}
			}
			if ( ( isset( $value['font_family'] ) || isset( $value['font_size'] ) ) && ! $this->valid_confidence( isset( $value['confidence'] ) ? $value['confidence'] : null ) ) {
				$this->error( 'missing_or_invalid_token_confidence' );
			}
		}
		foreach ( array( 'tokens', 'by_role', 'colors', 'hierarchy', 'families', 'sizes', 'weights', 'line_heights', 'typography' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
				$this->validate_nested_design_values( $value[ $key ], $kind, $depth + 1 );
			}
		}
	}

	/**
	 * Validate color records recursively.
	 *
	 * @param mixed  $styles Styles.
	 * @param string $key Key.
	 * @return void
	 */
	private function validate_color_records( $styles, $key ) {
		if ( ! is_array( $styles ) || ! isset( $styles[ $key ] ) ) {
			return;
		}
		$records = $styles[ $key ];
		if ( ! is_array( $records ) ) {
			$this->error( 'invalid_design_color_collection' );
			return;
		}
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				if ( is_string( $record ) && '' !== $record && ! isset( $this->source_values['color'][ $this->value_key( $record ) ] ) ) {
					$this->error( 'hallucinated_color_value' );
				}
				continue;
			}
			if ( array_key_exists( 'value', $record ) && null !== $record['value'] ) {
				if ( ! is_string( $record['value'] ) || ! isset( $this->source_values['color'][ $this->value_key( $record['value'] ) ] ) ) {
					$this->error( 'hallucinated_color_value' );
				}
				if ( ! $this->valid_confidence( isset( $record['confidence'] ) ? $record['confidence'] : null ) ) {
					$this->error( 'missing_or_invalid_token_confidence' );
				}
			}
			if ( isset( $record['role'] ) && ! is_string( $record['role'] ) ) {
				$this->error( 'invalid_color_role' );
			}
		}
	}

	/**
	 * Validate typography records recursively.
	 *
	 * @param mixed  $styles Styles.
	 * @param string $key Key.
	 * @return void
	 */
	private function validate_typography_records( $styles, $key ) {
		if ( ! is_array( $styles ) || ! isset( $styles[ $key ] ) ) {
			return;
		}
		$records = $styles[ $key ];
		if ( ! is_array( $records ) ) {
			$this->error( 'invalid_design_typography_collection' );
			return;
		}
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			foreach ( array( 'font_family' => 'font_family', 'font_size' => 'font_size', 'font_weight' => 'font_weight', 'line_height' => 'line_height' ) as $field => $kind ) {
				if ( array_key_exists( $field, $record ) && null !== $record[ $field ] && ( ! is_scalar( $record[ $field ] ) || ! isset( $this->source_values[ $kind ][ $this->value_key( (string) $record[ $field ] ) ] ) ) ) {
					$this->error( 'hallucinated_typography_value' );
				}
			}
			if ( ( isset( $record['font_family'] ) || isset( $record['font_size'] ) ) && ! $this->valid_confidence( isset( $record['confidence'] ) ? $record['confidence'] : null ) ) {
				$this->error( 'missing_or_invalid_token_confidence' );
			}
		}
	}

	/**
	 * Validate responsive strategy and unsupported inferences.
	 *
	 * @param mixed $responsive Responsive strategy.
	 * @return void
	 */
	private function validate_responsive( $responsive ) {
		if ( ! is_array( $responsive ) ) {
			$this->error( 'invalid_responsive_strategy' );
			return;
		}
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $viewport ) {
			if ( isset( $responsive[ $viewport ] ) && ! is_array( $responsive[ $viewport ] ) ) {
				$this->error( 'invalid_responsive_viewport' );
			} elseif ( in_array( $viewport, array( 'tablet', 'mobile' ), true ) ) {
				$layout = isset( $responsive[ $viewport ]['layout'] ) ? $responsive[ $viewport ]['layout'] : 'unknown';
				if ( is_string( $layout ) && ! in_array( $layout, array( 'unknown', 'not_detected' ), true ) && empty( $this->responsive_evidence[ $viewport ] ) ) {
					$this->error( 'unsupported_responsive_inference' );
				}
				$this->validate_responsive_value_evidence( $responsive[ $viewport ], $viewport, 0 );
			}
		}
		$section_strategies = isset( $responsive['section_strategies'] ) && is_array( $responsive['section_strategies'] ) ? $responsive['section_strategies'] : array();
		foreach ( $section_strategies as $section_id => $strategy ) {
			if ( ! is_string( $section_id ) || ! isset( $this->section_ids[ $section_id ] ) ) {
				$this->error( 'hallucinated_responsive_section_reference' );
				continue;
			}
			foreach ( array( 'tablet', 'mobile' ) as $viewport ) {
				$layout = isset( $strategy[ $viewport ]['layout'] ) ? $strategy[ $viewport ]['layout'] : 'unknown';
				if ( is_string( $layout ) && ! in_array( $layout, array( 'unknown', 'not_detected' ), true ) && empty( $this->responsive_evidence[ $viewport ] ) ) {
					$this->error( 'unsupported_responsive_inference' );
				}
				$this->validate_responsive_value_evidence( isset( $strategy[ $viewport ] ) ? $strategy[ $viewport ] : array(), $viewport, 0 );
			}
		}
		$this->validate_responsive_css_values( $responsive );
	}

	/**
	 * Require responsive evidence for non-layout viewport values.
	 *
	 * @param mixed  $value Viewport value.
	 * @param string $viewport Viewport.
	 * @param int    $depth Depth.
	 * @return void
	 */
	private function validate_responsive_value_evidence( $value, $viewport, $depth ) {
		if ( $depth > 8 || ! is_array( $value ) || ! empty( $this->responsive_evidence[ $viewport ] ) ) {
			return;
		}
		foreach ( $value as $key => $child ) {
			if ( in_array( strtolower( (string) $key ), array( 'font_size', 'font_family', 'font_weight', 'line_height', 'padding', 'margin', 'gap', 'width', 'max_width', 'height', 'color', 'background' ), true ) && is_scalar( $child ) && '' !== (string) $child && ! in_array( $child, array( 'unknown', 'not_detected' ), true ) ) {
				$this->error( 'unsupported_responsive_value_inference' );
			}
			if ( is_array( $child ) ) {
				$this->validate_responsive_value_evidence( $child, $viewport, $depth + 1 );
			}
		}
	}

	/**
	 * Validate responsive CSS values against source tokens.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return void
	 */
	private function validate_responsive_css_values( $value, $depth = 0 ) {
		if ( $depth > 10 ) {
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$key = is_string( $key ) ? strtolower( $key ) : '';
				if ( in_array( $key, array( 'font_size', 'font_weight', 'line_height', 'padding', 'margin', 'gap', 'width', 'max_width', 'height', 'min_height', 'color', 'background', 'border_radius' ), true ) && null !== $child && ( ! is_scalar( $child ) || ! isset( $this->source_values['css'][ $this->value_key( $child ) ] ) ) ) {
					$this->error( 'hallucinated_responsive_css_value' );
				}
				$this->validate_responsive_css_values( $child, $depth + 1 );
			}
		}
	}

	/**
	 * Validate confidence object.
	 *
	 * @param mixed $confidence Confidence.
	 * @return void
	 */
	private function validate_confidence( $confidence ) {
		if ( ! is_array( $confidence ) ) {
			$this->error( 'invalid_confidence_object' );
			return;
		}
		foreach ( array( 'overall', 'sections', 'components', 'design_system', 'responsive', 'inference' ) as $key ) {
			if ( ! $this->valid_confidence( isset( $confidence[ $key ] ) ? $confidence[ $key ] : null ) ) {
				$this->error( 'invalid_or_missing_confidence' );
			}
		}
	}

	/**
	 * Validate warning array.
	 *
	 * @param mixed $warnings Warnings.
	 * @return void
	 */
	private function validate_warnings( $warnings ) {
		if ( ! is_array( $warnings ) || count( $warnings ) > 100 ) {
			$this->error( 'invalid_warnings' );
			return;
		}
		foreach ( $warnings as $warning ) {
			if ( ! is_string( $warning ) || '' === trim( $warning ) || strlen( $warning ) > 500 ) {
				$this->error( 'invalid_warning_value' );
			}
		}
	}

	/**
	 * Validate the provider's validation object.
	 *
	 * @param mixed $validation Validation.
	 * @return void
	 */
	private function validate_validation_object( $validation ) {
		if ( ! is_array( $validation ) || ! array_key_exists( 'valid', $validation ) || ! is_bool( $validation['valid'] ) || true !== $validation['valid'] ) {
			$this->error( 'invalid_validation_object' );
			return;
		}
		foreach ( array( 'errors', 'warnings' ) as $key ) {
			if ( ! isset( $validation[ $key ] ) || ! is_array( $validation[ $key ] ) ) {
				$this->error( 'invalid_validation_details' );
				continue;
			}
			foreach ( $validation[ $key ] as $item ) {
				if ( ! is_string( $item ) || strlen( $item ) > 500 ) {
					$this->error( 'invalid_validation_detail_value' );
				}
			}
		}
	}

	/**
	 * Reject forbidden keys, executable strings, excessive depth, and secrets.
	 *
	 * @param mixed  $value Value.
	 * @param string $key Key.
	 * @param int    $depth Depth.
	 * @return void
	 */
	private function validate_recursive_safety( $value, $key = '', $depth = 0 ) {
		if ( $depth > 32 || is_object( $value ) || is_resource( $value ) ) {
			$this->error( 'unsafe_output_structure' );
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child_key => $child ) {
				$child_key = is_string( $child_key ) ? $child_key : '';
				if ( $this->forbidden_key( $child_key ) ) {
					$this->error( 'forbidden_output_key' );
				}
				$this->validate_recursive_safety( $child, $child_key, $depth + 1 );
			}
			return;
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 10000 ) {
				$this->error( 'oversized_output_string' );
			}
			if ( preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) ) {
				$this->error( 'control_character_output_value' );
			}
			if ( preg_match( '~^https?://~i', $value ) && ! isset( $this->source_values['link'][ $this->value_key( $value ) ] ) && ! isset( $this->source_values['image'][ $this->value_key( $value ) ] ) ) {
				$this->error( 'hallucinated_url_value' );
			}
			if ( preg_match( '/(?:<\\s*script|javascript\\s*:|data\\s*:|<\\?php|\\b(?:rm\\s+-rf|eval\\s*\\(|system\\s*\\(|shell_exec\\s*\\())/i', $value ) ) {
				$this->error( 'executable_output_value' );
			}
			if ( preg_match( '/(?:-----BEGIN [A-Z ]+PRIVATE KEY-----|\\b(?:sk|pk|api|key|token)[-_][A-Za-z0-9_-]{12,}|\\bBearer\\s+[A-Za-z0-9._~+\\/-]+=*|\\bAIza[0-9A-Za-z_-]{20,}\\b)/i', $value ) ) {
				$this->error( 'sensitive_output_value' );
			}
			if ( preg_match( '~^https?://~i', $value ) && preg_match( '/[?&](?:token|access_token|id_token|jwt|session(?:id)?|sid|api[_-]?key|key|secret|password|passwd|auth|authorization|signature|sig|code)=/i', $value ) ) {
				$this->error( 'sensitive_output_value' );
			}
			if ( preg_match( '/url\s*\(\s*[\'"]?https?:\/\//i', $value ) ) {
				$this->error( 'forbidden_output_url_reference' );
			}
		}
	}

	/**
	 * Check a component value against its exact source evidence.
	 *
	 * @param array<string, mixed> $component Component.
	 * @param mixed                $value Output value.
	 * @param string               $field Field name.
	 * @return bool
	 */
	private function value_is_source_backed( array $component, $value, $field ) {
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		if ( is_scalar( $value ) && '[REDACTED]' === (string) $value ) {
			return true;
		}
		if ( '' !== $field ) {
			$fields = isset( $component['fields'] ) && is_array( $component['fields'] ) ? $component['fields'] : array();
			return array_key_exists( $field, $fields ) && $this->scalar_matches( $fields[ $field ], $value );
		}
		$text = isset( $component['text'] ) && is_scalar( $component['text'] ) ? (string) $component['text'] : '';
		$value_text = (string) $value;
		if ( '' !== $text && ( false !== strpos( $text, $value_text ) || false !== strpos( $value_text, $text ) ) ) {
			return true;
		}
		return isset( $this->source_values['text'][ $this->value_key( $value_text ) ] );
	}

	/**
	 * Compare a scalar to a source scalar without type coercion surprises.
	 *
	 * @param mixed $source Source.
	 * @param mixed $value Output.
	 * @return bool
	 */
	private function scalar_matches( $source, $value ) {
		if ( null === $source && null === $value ) {
			return true;
		}
		if ( null === $value && '' === $source ) {
			return true;
		}
		if ( is_scalar( $value ) && '[REDACTED]' === (string) $value ) {
			return true;
		}
		if ( ! is_scalar( $source ) || ! is_scalar( $value ) ) {
			return false;
		}
		return $this->value_key( (string) $source ) === $this->value_key( (string) $value );
	}

	/**
	 * Return known image component IDs.
	 *
	 * @return array<string, bool>
	 */
	private function source_image_ids() {
		$result = array();
		foreach ( $this->components as $id => $component ) {
			if ( in_array( isset( $component['type'] ) ? $component['type'] : '', array( 'image', 'background_image' ), true ) ) {
				$result[ $id ] = true;
			}
		}
		return $result;
	}

	/**
	 * Return source product-card count.
	 *
	 * @return int
	 */
	private function source_product_count() {
		$count = 0;
		foreach ( $this->components as $component ) {
			if ( isset( $component['type'] ) && 'product_card' === $component['type'] ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Add a normalized source value.
	 *
	 * @param string $kind Kind.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private function add_source_value( $kind, $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			$key = $this->value_key( $value );
			if ( '' !== $key ) {
				$this->source_values[ $kind ][ $key ] = true;
			}
		}
	}

	/**
	 * Normalize a value key.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function value_key( $value ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( preg_match( '~^https?://~i', $value ) ) {
			$normalized = Security::normalize_http_url( $value );
			if ( is_string( $normalized ) ) {
				$value = $normalized;
			}
		}
		return strtolower( $value );
	}

	/**
	 * Determine viewport from breakpoint.
	 *
	 * @param string $breakpoint Breakpoint.
	 * @return string
	 */
	private function viewport_for_breakpoint( $breakpoint ) {
		$value = (float) $breakpoint;
		if ( $value > 0 && $value <= 640 ) {
			return 'mobile';
		}
		if ( $value > 640 && $value <= 1024 ) {
			return 'tablet';
		}
		return 'unknown';
	}

	/**
	 * Return the generic reconstruction types allowed by the Phase 3 contract.
	 *
	 * @return array<int, string>
	 */
	private function allowed_reconstruction_types() {
		return array( 'heading', 'paragraph', 'button', 'link', 'image', 'background_image', 'card', 'product_card', 'testimonial', 'pricing_card', 'form', 'form_field', 'navigation_link', 'logo', 'icon', 'gallery', 'accordion', 'tabs', 'tab', 'list', 'label', 'feature_card', 'blog_card', 'team_card', 'portfolio_card', 'repeated_card_group', 'unknown' );
	}

	/**
	 * Check confidence.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function valid_confidence( $value ) {
		return is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0 && (float) $value <= 1;
	}

	/**
	 * Check forbidden output keys.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private function forbidden_key( $key ) {
		return 1 === preg_match( '/(?:^|_)(?:raw_html|html|outer_html|inner_html|css|script|javascript|php|sql|shortcode|elementor|_elementor_data|_elementor_controls|widget_id|command|shell|hook|hooks|action|actions|filter|filters|wp_query|nonce)(?:$|_)/i', $key ) || 1 === preg_match( '/^_elementor/i', $key );
	}

	/**
	 * Add an error.
	 *
	 * @param string $error Error.
	 * @return void
	 */
	private function error( $error ) {
		$this->errors[] = sanitize_key( $error );
	}

	/**
	 * Return result.
	 *
	 * @return array{valid: bool, errors: array<int, string>, warnings: array<int, string>}
	 */
	private function result() {
		$this->errors   = array_values( array_unique( $this->errors ) );
		$this->warnings = array_values( array_unique( $this->warnings ) );
		return array( 'valid' => empty( $this->errors ), 'errors' => $this->errors, 'warnings' => $this->warnings );
	}
}
