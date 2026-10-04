<?php
/**
 * Deterministic Phase 3 reconstruction planner.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a conservative reconstruction specification from Phase 2 evidence.
 *
 * This planner is also the honest fallback when no AI provider is configured or
 * when a provider fails. It does not claim that an AI analysis occurred.
 */
final class Ai_Reconstruction_Planner {

	/**
	 * Build a reconstruction specification.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @param array<string, mixed> $metadata       Pipeline metadata.
	 * @return array<string, mixed>
	 */
	public function build( array $representation, array $metadata = array() ) {
		$sections   = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
		$components = isset( $representation['components'] ) && is_array( $representation['components'] ) ? $representation['components'] : array();
		$component_ids = $this->id_set( $components );
		$section_ids   = $this->id_set( $sections );

		$plan_sections = $this->build_sections( $sections, $component_ids );
		$plan_components = $this->build_components( $components, $section_ids );
		$plan_assets     = $this->build_assets( $components );
		$content         = $this->build_content_mapping( $components );
		$design_system   = $this->build_design_system( $representation );
		$responsive      = $this->build_responsive_strategy( $representation, $sections );
		$hierarchy       = $this->build_hierarchy( $representation, $sections, $components );
		$confidence      = $this->build_confidence( $representation, $plan_sections, $plan_components );
		$page_strategy   = $this->build_page_strategy( $representation, $design_system, $responsive );
		$warnings        = $this->build_warnings( $representation, $sections, $components, $responsive );

		$specification = array(
			'schema_version'      => Ai_Limits::SCHEMA_VERSION,
			'prompt_version'      => Ai_Limits::PROMPT_VERSION,
			'page_strategy'       => $page_strategy,
			'global_styles'       => $this->build_global_styles( $representation ),
			'sections'            => $plan_sections,
			'components'          => $plan_components,
			'hierarchy'           => $hierarchy,
			'design_system'       => $design_system,
			'responsive_strategy' => $responsive,
			'assets'              => $plan_assets,
			'content_mapping'     => $content,
			'confidence'          => $confidence,
			'warnings'            => $warnings,
			'validation'          => array( 'valid' => true, 'errors' => array(), 'warnings' => array() ),
			'provenance'          => $this->build_provenance( $metadata ),
		);
		return $this->bound_specification( $specification );
	}

	/**
	 * Build section plans.
	 *
	 * @param array<int, mixed>  $sections      Sections.
	 * @param array<string,bool> $component_ids Known component IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_sections( $sections, array $component_ids ) {
		$result = array();
		$index  = 0;
		foreach ( array_slice( $sections, 0, Ai_Limits::MAX_SECTIONS ) as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['id'] ) ) {
				continue;
			}
			$index++;
			$source_id = (string) $section['id'];
			$components = array();
			foreach ( isset( $section['components'] ) && is_array( $section['components'] ) ? $section['components'] : array() as $component_id ) {
				if ( is_string( $component_id ) && isset( $component_ids[ $component_id ] ) && ! in_array( $component_id, $components, true ) ) {
					$components[] = $component_id;
				}
			}
			$layout = isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : array();
			$result[] = array(
				'id'             => 'reconstruction_section_' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT ),
				'source_id'      => $source_id,
				'type'           => $this->text( isset( $section['type'] ) ? $section['type'] : 'unknown', 80 ),
				'order'          => $this->integer( isset( $section['order'] ) ? $section['order'] : $index ),
				'confidence'     => $this->confidence( isset( $section['confidence'] ) ? $section['confidence'] : 0 ),
				'layout'         => $this->map_layout( $layout ),
				'components'     => $components,
				'children'       => $this->id_list( isset( $section['children'] ) ? $section['children'] : array(), 100 ),
				'responsive'     => $this->section_responsive( $layout, $source_id ),
				'content_summary' => $this->section_content( $section ),
				'source'         => $this->source( isset( $section['source'] ) ? $section['source'] : array() ),
			);
		}
		return $result;
	}

	/**
	 * Build component plans.
	 *
	 * @param array<int, mixed>  $components  Components.
	 * @param array<string,bool> $section_ids Section IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_components( $components, array $section_ids ) {
		$result = array();
		$index  = 0;
		$cta_index = 0;
		foreach ( array_slice( $components, 0, Ai_Limits::MAX_COMPONENTS ) as $component ) {
			if ( ! is_array( $component ) || ! isset( $component['id'] ) ) {
				continue;
			}
			$index++;
			$source_id = (string) $component['id'];
			$type      = $this->text( isset( $component['type'] ) ? $component['type'] : 'unknown', 100 );
			$role      = $this->text( isset( $component['role'] ) ? $component['role'] : 'unknown', 120 );
			$content_source = $this->component_content_source( $component );
			$semantic_role = $this->semantic_role( $type, $role );
			if ( 'call_to_action' === $role && in_array( $type, array( 'button', 'link' ), true ) ) {
				$cta_index++;
				$semantic_role = 1 === $cta_index ? 'primary_cta' : 'secondary_cta';
			}
			$plan = array(
				'id'                 => 'reconstruction_component_' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT ),
				'source_id'          => $source_id,
				'type'               => $type,
				'reconstruction_type' => $this->reconstruction_type( $type, $role ),
				'role'               => $role,
				'semantic_role'      => $semantic_role,
				'content_source'     => $content_source,
				'confidence'         => $this->confidence( isset( $component['confidence'] ) ? $component['confidence'] : 0 ),
				'section_id'         => isset( $component['section_id'] ) && isset( $section_ids[ $component['section_id'] ] ) ? $component['section_id'] : null,
				'children'           => $this->id_list( isset( $component['children'] ) ? $component['children'] : array(), Ai_Limits::MAX_COMPONENTS ),
				'source'             => $this->source( isset( $component['source'] ) ? $component['source'] : array() ),
			);
			if ( isset( $component['url'] ) ) {
				$plan['target'] = $this->safe_url( $component['url'] );
			}
			if ( isset( $component['fields'] ) && is_array( $component['fields'] ) ) {
				$plan['fields'] = $this->copy_fields( $component['fields'] );
			}
			if ( isset( $component['image'] ) && is_array( $component['image'] ) ) {
				$plan['image'] = $this->copy_image( $component['image'] );
			}
			foreach ( array( 'count', 'card_type', 'repeated_structure' ) as $key ) {
				if ( array_key_exists( $key, $component ) ) {
					$plan[ $key ] = $component[ $key ];
				}
			}
			$result[] = $plan;
		}
		return $result;
	}

	/**
	 * Build asset plans without fetching or replacing assets.
	 *
	 * @param array<int, mixed> $components Components.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_assets( $components ) {
		$result = array();
		$seen   = array();
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || ! isset( $component['id'] ) ) {
				continue;
			}
			$type = isset( $component['type'] ) ? $component['type'] : '';
			if ( ! in_array( $type, array( 'image', 'background_image' ), true ) ) {
				continue;
			}
			$source_id = (string) $component['id'];
			if ( isset( $seen[ $source_id ] ) ) {
				continue;
			}
			$seen[ $source_id ] = true;
			$image = isset( $component['image'] ) && is_array( $component['image'] ) ? $component['image'] : array();
			$url = $this->safe_url( isset( $image['src'] ) ? $image['src'] : '' );
			$result[] = array(
				'id'          => 'asset_' . str_pad( (string) ( count( $result ) + 1 ), 3, '0', STR_PAD_LEFT ),
				'source_id'   => $source_id,
				'type'        => 'image',
				'role'        => $this->text( isset( $component['role'] ) ? $component['role'] : 'content_image', 100 ),
				'usage'       => $this->asset_usage( $component ),
				'source_url'  => $url,
				'preserve'    => null !== $url,
				'reason'      => null !== $url ? null : 'asset_unavailable_or_unsafe',
				'confidence'  => $this->confidence( isset( $component['confidence'] ) ? $component['confidence'] : 0 ),
				'source'      => $this->source( isset( $component['source'] ) ? $component['source'] : array() ),
			);
		}
		return array_slice( $result, 0, Ai_Limits::MAX_IMAGES );
	}

	/**
	 * Return a generic content type for a component.
	 *
	 * @param string $type Component type.
	 * @return string
	 */
	private function content_type( $type ) {
		$map = array( 'heading' => 'heading', 'paragraph' => 'text', 'button' => 'button_text', 'link' => 'link', 'navigation_item' => 'navigation_text', 'product_card' => 'product', 'pricing_card' => 'pricing', 'testimonial_card' => 'testimonial', 'blog_card' => 'article', 'image' => 'image_alt' );
		return isset( $map[ $type ] ) ? $map[ $type ] : 'text';
	}

	/**
	 * Build exact source content mappings.
	 *
	 * @param array<int, mixed> $components Components.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_content_mapping( $components ) {
		$result = array();
		$seen   = array();
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || ! isset( $component['id'] ) ) {
				continue;
			}
			$component_id = (string) $component['id'];
			$text = isset( $component['text'] ) && is_scalar( $component['text'] ) ? $this->text( $component['text'], Ai_Limits::MAX_TEXT_LENGTH ) : '';
			if ( '' !== $text ) {
				$result[] = array( 'id' => 'content_' . str_pad( (string) ( count( $result ) + 1 ), 3, '0', STR_PAD_LEFT ), 'component_id' => $component_id, 'content_type' => $this->content_type( isset( $component['type'] ) ? $component['type'] : '' ), 'source' => 'phase2', 'value' => $text, 'source_reference' => $this->source( isset( $component['source'] ) ? $component['source'] : array() ) );
			}
			if ( isset( $component['fields'] ) && is_array( $component['fields'] ) ) {
				foreach ( $component['fields'] as $field => $value ) {
					if ( in_array( $field, array( 'image', 'link' ), true ) || null === $value || '' === $value || is_array( $value ) || is_object( $value ) ) {
						continue;
					}
					$key = $component_id . '|' . (string) $field . '|' . (string) $value;
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
					$result[] = array( 'id' => 'content_' . str_pad( (string) ( count( $result ) + 1 ), 3, '0', STR_PAD_LEFT ), 'component_id' => $component_id, 'content_type' => 'field', 'field' => $this->key( $field ), 'source' => 'phase2', 'value' => is_scalar( $value ) ? $this->text( $value, 300 ) : null, 'source_reference' => $this->source( isset( $component['source'] ) ? $component['source'] : array() ) );
				}
			}
			if ( isset( $component['url'] ) ) {
				$url = $this->safe_url( $component['url'] );
				$result[] = array( 'id' => 'content_' . str_pad( (string) ( count( $result ) + 1 ), 3, '0', STR_PAD_LEFT ), 'component_id' => $component_id, 'content_type' => 'link', 'source' => 'phase2', 'target' => $url, 'preserve' => null !== $url, 'source_reference' => $this->source( isset( $component['source'] ) ? $component['source'] : array() ) );
			}
			if ( count( $result ) >= Ai_Limits::MAX_CONTENT_ITEMS ) {
				break;
			}
		}
		return $result;
	}

	/**
	 * Build a generic, source-backed hierarchy.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @param array<int, mixed>    $sections       Sections.
	 * @param array<int, mixed>    $components     Components.
	 * @return array<string, mixed>
	 */
	private function build_hierarchy( array $representation, $sections, $components ) {
		$by_section = array();
		foreach ( $components as $component ) {
			if ( is_array( $component ) && isset( $component['section_id'], $component['id'] ) && is_string( $component['section_id'] ) ) {
				$by_section[ $component['section_id'] ][] = $component['id'];
			}
		}
		$section_hierarchy = array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['id'] ) ) {
				continue;
			}
			$id = (string) $section['id'];
			$ids = isset( $by_section[ $id ] ) ? $by_section[ $id ] : array();
			$section_hierarchy[ $id ] = array(
				'source_id' => $id,
				'children'  => array(
					array( 'type' => 'container', 'source_id' => $id, 'layout' => $this->map_layout( isset( $section['layout'] ) ? $section['layout'] : array() ), 'children' => array( array( 'type' => 'component_group', 'source_id' => $id, 'component_ids' => $ids ) ) ),
				),
			);
		}
		$component_groups = array();
		foreach ( array( 'primary', 'secondary', 'supporting', 'cta', 'decorative' ) as $group ) {
			$component_groups[ $group ] = $this->id_list( isset( $representation['hierarchy'][ $group ] ) ? $representation['hierarchy'][ $group ] : array(), 150 );
		}
		return array( 'sections' => $section_hierarchy, 'component_groups' => $component_groups );
	}

	/**
	 * Build the page strategy using only supported signals.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @param array<string, mixed> $design_system  Design system.
	 * @param array<string, mixed> $responsive     Responsive strategy.
	 * @return array<string, mixed>
	 */
	private function build_page_strategy( array $representation, array $design_system, array $responsive ) {
		$page  = isset( $representation['page'] ) && is_array( $representation['page'] ) ? $representation['page'] : array();
		$type  = strtolower( $this->text( isset( $page['type'] ) ? $page['type'] : '', 100 ) );
		$layout = isset( $representation['layout']['page'] ) && is_array( $representation['layout']['page'] ) ? $representation['layout']['page'] : array();
		$page_type = 'unknown';
		if ( false !== strpos( $type, 'commerce' ) || false !== strpos( $type, 'shop' ) || false !== strpos( $type, 'store' ) ) {
			$page_type = 'ecommerce_page';
		} elseif ( false !== strpos( $type, 'portfolio' ) ) {
			$page_type = 'portfolio_page';
		} elseif ( false !== strpos( $type, 'blog' ) || false !== strpos( $type, 'content' ) ) {
			$page_type = 'content_page';
		} elseif ( ! empty( $representation['sections'] ) ) {
			$page_type = 'structured_landing_or_content_page';
		}
		$container = isset( $layout['container'] ) && is_array( $layout['container'] ) ? $layout['container'] : array();
		$container_strategy = ! empty( $container['centered'] ) && ! empty( $container['max_width'] ) ? 'centered_max_width' : ( ! empty( $container['max_width'] ) ? 'source_max_width' : 'unknown' );
		$families = isset( $design_system['typography']['families'] ) && is_array( $design_system['typography']['families'] ) ? $design_system['typography']['families'] : array();
		$global_typography = count( $families ) > 1 ? 'mixed_detected_families' : ( ! empty( $families ) ? 'single_detected_family' : 'unknown' );
		$spacing = ! empty( $design_system['spacing']['scale'] ) ? 'source_spacing_scale' : 'unknown';
		$responsive_strategy = ! empty( $responsive['evidence'] ) ? 'evidence_backed' : 'not_detected';
		return array(
			'layout_type'                => $page_type,
			'container_strategy'        => $container_strategy,
			'section_spacing_strategy'  => $spacing,
			'global_typography'         => $global_typography,
			'responsive_strategy'       => $responsive_strategy,
			'confidence'                => $this->confidence( isset( $page['type_confidence'] ) ? $page['type_confidence'] : 0 ),
			'source'                    => 'phase2_inference',
		);
	}

	/**
	 * Build the global styles portion.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return array<string, mixed>
	 */
	private function build_global_styles( array $representation ) {
		$system = isset( $representation['design_system'] ) && is_array( $representation['design_system'] ) ? $representation['design_system'] : array();
		return array(
			'colors'    => $this->copy_array( isset( $system['colors'] ) ? $system['colors'] : array() ),
			'typography'=> $this->copy_array( isset( $system['typography'] ) ? $system['typography'] : array() ),
			'spacing'   => $this->copy_array( isset( $system['spacing'] ) ? $system['spacing'] : array() ),
			'radius'    => $this->copy_array( isset( $system['radius'] ) ? $system['radius'] : array() ),
			'shadows'   => $this->copy_array( isset( $system['shadows'] ) ? $system['shadows'] : array() ),
			'buttons'   => $this->copy_array( isset( $system['buttons'] ) ? $system['buttons'] : array() ),
			'source'    => 'phase2',
		);
	}

	/**
	 * Build semantic design-token interpretation.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return array<string, mixed>
	 */
	private function build_design_system( array $representation ) {
		$system = isset( $representation['design_system'] ) && is_array( $representation['design_system'] ) ? $representation['design_system'] : array();
		$colors = isset( $system['colors'] ) && is_array( $system['colors'] ) ? $system['colors'] : array();
		$type   = isset( $system['typography'] ) && is_array( $system['typography'] ) ? $system['typography'] : array();
		$roles  = array();
		foreach ( isset( $colors['by_role'] ) && is_array( $colors['by_role'] ) ? $colors['by_role'] : array() as $role => $record ) {
			if ( ! is_array( $record ) || ! isset( $record['value'] ) ) {
				continue;
			}
			$roles[] = array( 'role' => $this->key( $role ), 'value' => $this->text( $record['value'], Ai_Limits::MAX_CSS_VALUE_LENGTH ), 'confidence' => $this->confidence( isset( $record['confidence'] ) ? $record['confidence'] : 0 ), 'source' => 'phase2_design_token', 'evidence' => $this->copy_array( isset( $record['evidence'] ) ? $record['evidence'] : array() ) );
		}
		$hierarchy = array();
		foreach ( isset( $type['hierarchy'] ) && is_array( $type['hierarchy'] ) ? $type['hierarchy'] : array() as $role => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$hierarchy[] = array( 'role' => $this->key( $role ), 'font_family' => $this->nullable_text( isset( $record['font_family'] ) ? $record['font_family'] : null ), 'font_size' => $this->nullable_text( isset( $record['font_size'] ) ? $record['font_size'] : null ), 'font_weight' => $this->nullable_scalar( isset( $record['font_weight'] ) ? $record['font_weight'] : null ), 'line_height' => $this->nullable_scalar( isset( $record['line_height'] ) ? $record['line_height'] : null ), 'confidence' => $this->confidence( isset( $record['confidence'] ) ? $record['confidence'] : 0 ), 'source' => 'phase2_typography_token' );
		}
		return array(
			'colors'      => $roles,
			'typography'  => $hierarchy,
			'spacing'     => $this->copy_array( isset( $system['spacing'] ) ? $system['spacing'] : array() ),
			'radius'      => $this->copy_array( isset( $system['radius'] ) ? $system['radius'] : array() ),
			'shadows'     => $this->copy_array( isset( $system['shadows'] ) ? $system['shadows'] : array(), 40 ),
			'buttons'     => $this->copy_array( isset( $system['buttons'] ) ? $system['buttons'] : array(), 30 ),
			'confidence'  => $this->confidence( isset( $representation['confidence']['design_system'] ) ? $representation['confidence']['design_system'] : 0 ),
			'source'      => 'phase2_design_system',
		);
	}

	/**
	 * Build responsive strategy and evidence.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @param array<int, mixed>    $sections       Sections.
	 * @return array<string, mixed>
	 */
	private function build_responsive_strategy( array $representation, $sections ) {
		$responsive = isset( $representation['responsive'] ) && is_array( $representation['responsive'] ) ? $representation['responsive'] : array();
		$rules = isset( $responsive['rules'] ) && is_array( $responsive['rules'] ) ? array_slice( $responsive['rules'], 0, Ai_Limits::MAX_RESPONSIVE_RULES ) : array();
		$desktop = array( 'layout' => 'unknown' );
		$page_layout = isset( $representation['layout']['page'] ) && is_array( $representation['layout']['page'] ) ? $representation['layout']['page'] : array();
		if ( ! empty( $page_layout['type'] ) ) {
			$desktop['layout'] = $this->text( $page_layout['type'], 80 );
		}
		$tablet = array( 'layout' => 'unknown' );
		$mobile = array( 'layout' => 'unknown' );
		$evidence = array();
		foreach ( $rules as $index => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$breakpoint = strtolower( (string) ( isset( $rule['breakpoint'] ) ? $rule['breakpoint'] : '' ) );
			$viewport = $this->viewport_for_breakpoint( $breakpoint );
			$evidence[] = array( 'id' => 'responsive_evidence_' . str_pad( (string) ( $index + 1 ), 3, '0', STR_PAD_LEFT ), 'viewport' => $viewport, 'breakpoint' => $breakpoint, 'changes' => $this->string_list( isset( $rule['changes'] ) ? $rule['changes'] : array(), 20, 80 ), 'selectors' => $this->selector_list( isset( $rule['selectors'] ) ? $rule['selectors'] : array(), 8 ), 'confidence' => $this->confidence( isset( $rule['confidence'] ) ? $rule['confidence'] : 0 ), 'source' => 'phase2_responsive_rule' );
			$changes = isset( $rule['changes'] ) && is_array( $rule['changes'] ) ? $rule['changes'] : array();
			if ( 'mobile' === $viewport && $this->has_layout_change( $changes ) ) {
				$mobile['layout'] = 'responsive_layout_change_detected';
			} elseif ( 'tablet' === $viewport && $this->has_layout_change( $changes ) ) {
				$tablet['layout'] = 'responsive_layout_change_detected';
			}
		}
		foreach ( $sections as $section ) {
			if ( is_array( $section ) && isset( $section['id'] ) ) {
				$section_id = (string) $section['id'];
				$responsive['section_strategies'][ $section_id ] = $this->section_responsive( isset( $section['layout'] ) ? $section['layout'] : array(), $section_id );
			}
		}
		return array( 'desktop' => $desktop, 'tablet' => $tablet, 'mobile' => $mobile, 'evidence' => $evidence, 'section_strategies' => isset( $responsive['section_strategies'] ) ? $responsive['section_strategies'] : array(), 'confidence' => $this->confidence( isset( $responsive['confidence'] ) ? $responsive['confidence'] : 0 ), 'source' => 'phase2_responsive_evidence' );
	}

	/**
	 * Build per-section responsive values without claiming unsupported layouts.
	 *
	 * @param array<string, mixed> $layout Layout.
	 * @param string               $source_id Section ID.
	 * @return array<string, mixed>
	 */
	private function section_responsive( $layout, $source_id ) {
		$desktop = array( 'layout' => is_array( $layout ) && ! empty( $layout['type'] ) ? $this->text( $layout['type'], 80 ) : 'unknown' );
		return array( 'desktop' => $desktop, 'tablet' => array( 'layout' => 'unknown' ), 'mobile' => array( 'layout' => 'unknown' ), 'evidence' => array(), 'source_id' => $source_id );
	}

	/**
	 * Build aggregate confidence.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @param array<int, mixed>    $sections       Planned sections.
	 * @param array<int, mixed>    $components     Planned components.
	 * @return array<string, float>
	 */
	private function build_confidence( array $representation, array $sections, array $components ) {
		$source = isset( $representation['confidence'] ) && is_array( $representation['confidence'] ) ? $representation['confidence'] : array();
		$section_confidence = $this->average( $sections );
		$component_confidence = $this->average( $components );
		$design_confidence = $this->confidence( isset( $source['design_system'] ) ? $source['design_system'] : 0 );
		$responsive_confidence = $this->confidence( isset( $source['responsive'] ) ? $source['responsive'] : 0 );
		$overall = $section_confidence * 0.3 + $component_confidence * 0.3 + $design_confidence * 0.2 + $responsive_confidence * 0.2;
		return array( 'overall' => $this->confidence( $overall ), 'sections' => $section_confidence, 'components' => $component_confidence, 'design_system' => $design_confidence, 'responsive' => $responsive_confidence, 'inference' => $this->confidence( $overall ) );
	}

	/**
	 * Build warnings without hiding deterministic limitations.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @param array<int, mixed>    $sections       Sections.
	 * @param array<int, mixed>    $components     Components.
	 * @param array<string, mixed> $responsive     Responsive strategy.
	 * @return array<int, string>
	 */
	private function build_warnings( array $representation, $sections, $components, array $responsive ) {
		$warnings = array();
		foreach ( isset( $representation['warnings'] ) && is_array( $representation['warnings'] ) ? $representation['warnings'] : array() as $warning ) {
			if ( is_scalar( $warning ) ) {
				$warnings[] = $this->text( $warning, 300 );
			}
		}
		if ( empty( $sections ) ) {
			$warnings[] = 'No sections were detected in the Phase 2 representation.';
		}
		if ( empty( $components ) ) {
			$warnings[] = 'No components were detected in the Phase 2 representation.';
		}
		if ( empty( $responsive['evidence'] ) ) {
			$warnings[] = 'Responsive behavior could not be determined from the available static evidence.';
		}
		if ( count( $sections ) > Ai_Limits::MAX_SECTIONS ) {
			$warnings[] = 'Some detected sections were omitted because the Phase 3 section limit was reached.';
		}
		if ( count( $components ) > Ai_Limits::MAX_COMPONENTS ) {
			$warnings[] = 'Some detected components were omitted because the Phase 3 component limit was reached.';
		}
		$warnings = array_values( array_unique( array_filter( $warnings ) ) );
		return array_slice( $warnings, 0, 80 );
	}

	/**
	 * Build provenance metadata.
	 *
	 * @param array<string, mixed> $metadata Metadata.
	 * @return array<string, mixed>
	 */
	private function build_provenance( array $metadata ) {
		return array(
			'ai_used'              => ! empty( $metadata['ai_used'] ),
			'provider'             => isset( $metadata['provider'] ) ? $this->text( $metadata['provider'], 80 ) : 'none',
			'model'                => isset( $metadata['model'] ) && '' !== $metadata['model'] ? $this->text( $metadata['model'], 120 ) : null,
			'prompt_version'       => Ai_Limits::PROMPT_VERSION,
			'source_schema_version'=> '2.0',
			'cache_key'            => null,
			'generated_at'         => gmdate( 'c' ),
		);
	}

	/**
	 * Map a generic reconstruction type.
	 *
	 * @param string $type Phase 2 type.
	 * @param string $role Phase 2 role.
	 * @return string
	 */
	private function reconstruction_type( $type, $role ) {
		$map = array(
			'heading' => 'heading', 'paragraph' => 'paragraph', 'image' => 'image', 'background_image' => 'background_image', 'button' => 'button', 'link' => 'link', 'navigation_item' => 'navigation_link', 'social_link' => 'navigation_link', 'form' => 'form', 'form_field' => 'form_field', 'accordion' => 'accordion', 'tabs' => 'tabs', 'tab' => 'tab', 'list' => 'list', 'icon' => 'icon', 'badge' => 'label', 'card' => 'card', 'product_card' => 'product_card', 'pricing_card' => 'pricing_card', 'testimonial_card' => 'testimonial', 'blog_card' => 'blog_card', 'feature_card' => 'feature_card', 'team_card' => 'card', 'portfolio_card' => 'portfolio_card', 'card_group' => 'repeated_card_group',
		);
		if ( isset( $map[ $type ] ) ) {
			return $map[ $type ];
		}
		return 'unknown';
	}

	/**
	 * Infer a semantic role only from existing role/type evidence.
	 *
	 * @param string $type Type.
	 * @param string $role Role.
	 * @return string
	 */
	private function semantic_role( $type, $role ) {
		if ( 'heading' === $type ) {
			return 'primary_heading' === $role ? 'hero_or_page_heading' : ( 'section_heading' === $role ? 'section_heading' : 'subheading' );
		}
		if ( 'button' === $type || 'link' === $type ) {
			return 'call_to_action' === $role ? 'primary_or_secondary_cta' : 'link';
		}
		if ( 'image' === $type ) {
			return $role;
		}
		if ( 'product_card' === $type ) {
			return 'product_card';
		}
		return $role;
	}

	/**
	 * Determine content source.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return string
	 */
	private function component_content_source( array $component ) {
		$has_fields = false;
		foreach ( isset( $component['fields'] ) && is_array( $component['fields'] ) ? $component['fields'] : array() as $value ) {
			if ( null !== $value && '' !== $value ) {
				$has_fields = true;
				break;
			}
		}
		if ( ! empty( $component['text'] ) || ! empty( $component['url'] ) || $has_fields || ( isset( $component['image'] ) && is_array( $component['image'] ) && ! empty( $component['image']['src'] ) ) ) {
			return 'source';
		}
		return 'not_detected';
	}

	/**
	 * Map a static layout to a generic reconstruction layout.
	 *
	 * @param array<string, mixed> $layout Layout.
	 * @return array<string, mixed>
	 */
	private function map_layout( $layout ) {
		$layout = is_array( $layout ) ? $layout : array();
		$allowed = array( 'type', 'display', 'direction', 'alignment', 'wrap', 'gap', 'grid_columns', 'grid_rows', 'column_count', 'columns', 'container', 'confidence', 'source_type' );
		$result = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $layout ) ) {
				$result[ $key ] = $this->copy_value( $layout[ $key ], $key );
			}
		}
		return $result;
	}

	/**
	 * Return section content summary as source-backed data.
	 *
	 * @param array<string, mixed> $section Section.
	 * @return array<string, mixed>
	 */
	private function section_content( array $section ) {
		$content = isset( $section['content'] ) && is_array( $section['content'] ) ? $section['content'] : array();
		return array( 'heading' => $this->nullable_text( isset( $content['heading'] ) ? $content['heading'] : null ), 'text' => $this->nullable_text( isset( $content['text'] ) ? $content['text'] : null ), 'source' => 'phase2' );
	}

	/**
	 * Copy a source field map.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>
	 */
	private function copy_fields( array $fields ) {
		$result = array();
		foreach ( $fields as $key => $value ) {
			$key = $this->key( $key );
			if ( '' === $key || in_array( $key, array( 'password', 'token', 'authorization', 'cookie' ), true ) ) {
				continue;
			}
			$result[ $key ] = in_array( $key, array( 'image', 'link' ), true ) ? $this->safe_url( $value ) : $this->copy_value( $value, $key );
		}
		return $result;
	}

	/**
	 * Copy image metadata.
	 *
	 * @param array<string, mixed> $image Image.
	 * @return array<string, mixed>
	 */
	private function copy_image( array $image ) {
		return array( 'src' => $this->safe_url( isset( $image['src'] ) ? $image['src'] : '' ), 'alt' => $this->nullable_text( isset( $image['alt'] ) ? $image['alt'] : null ), 'width' => isset( $image['width'] ) ? (int) $image['width'] : null, 'height' => isset( $image['height'] ) ? (int) $image['height'] : null );
	}

	/**
	 * Copy a source trace.
	 *
	 * @param mixed $source Source.
	 * @return array<string, string>
	 */
	private function source( $source ) {
		$source = is_array( $source ) ? $source : array();
		return array( 'node_id' => $this->id( isset( $source['node_id'] ) ? $source['node_id'] : '' ), 'tag' => $this->text( isset( $source['tag'] ) ? $source['tag'] : '', 40 ), 'selector' => $this->selector( isset( $source['selector'] ) ? $source['selector'] : '' ) );
	}

	/**
	 * Create an asset usage label.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return string
	 */
	private function asset_usage( array $component ) {
		$role = isset( $component['role'] ) ? strtolower( (string) $component['role'] ) : '';
		if ( false !== strpos( $role, 'hero' ) ) {
			return 'hero_image';
		}
		if ( false !== strpos( $role, 'product' ) ) {
			return 'product_image';
		}
		if ( false !== strpos( $role, 'decorative' ) ) {
			return 'decorative_image';
		}
		return 'content_image';
	}

	/**
	 * Determine viewport for a breakpoint.
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
	 * Check whether responsive changes support a layout inference.
	 *
	 * @param array<int, mixed> $changes Changes.
	 * @return bool
	 */
	private function has_layout_change( array $changes ) {
		foreach ( $changes as $change ) {
			if ( in_array( $change, array( 'layout_change', 'grid_columns_change' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Copy an array while bounding its size.
	 *
	 * @param mixed $values Values.
	 * @param int   $limit Limit.
	 * @return array<int, mixed>
	 */
	private function copy_array( $values, $limit = 100 ) {
		return is_array( $values ) ? array_slice( $values, 0, $limit ) : array();
	}

	/**
	 * Copy a scalar/array value with a conservative key-aware limit.
	 *
	 * @param mixed  $value Value.
	 * @param string $key Key.
	 * @return mixed
	 */
	private function copy_value( $value, $key = '' ) {
		if ( is_array( $value ) ) {
			return $this->copy_array( $value, 100 );
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( preg_match( '~^https?://~i', (string) $value ) ) {
			return $this->safe_url( $value );
		}
		return $this->text( $value, in_array( $key, array( 'value', 'font_size', 'font_weight', 'gap', 'padding', 'margin', 'width', 'height', 'max_width' ), true ) ? Ai_Limits::MAX_CSS_VALUE_LENGTH : Ai_Limits::MAX_TEXT_LENGTH );
	}

	/**
	 * Copy an ID list.
	 *
	 * @param mixed $values Values.
	 * @param int   $limit Limit.
	 * @return array<int, string>
	 */
	private function id_list( $values, $limit ) {
		$result = array();
		foreach ( is_array( $values ) ? array_slice( $values, 0, $limit ) : array() as $value ) {
			$id = $this->id( $value );
			if ( '' !== $id && ! in_array( $id, $result, true ) ) {
				$result[] = $id;
			}
		}
		return $result;
	}

	/**
	 * Make an ID set from records.
	 *
	 * @param array<int, mixed> $records Records.
	 * @return array<string, bool>
	 */
	private function id_set( $records ) {
		$result = array();
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			if ( is_array( $record ) && isset( $record['id'] ) ) {
				$id = $this->id( $record['id'] );
				if ( '' !== $id ) {
					$result[ $id ] = true;
				}
			}
		}
		return $result;
	}

	/**
	 * Normalize a string list.
	 *
	 * @param mixed $values Values.
	 * @param int   $limit Limit.
	 * @param int   $length Length.
	 * @return array<int, string>
	 */
	private function string_list( $values, $limit, $length ) {
		$result = array();
		foreach ( is_array( $values ) ? array_slice( $values, 0, $limit ) : array() as $value ) {
			if ( is_scalar( $value ) ) {
				$value = $this->text( $value, $length );
				if ( '' !== $value && ! in_array( $value, $result, true ) ) {
					$result[] = $value;
				}
			}
		}
		return $result;
	}

	/**
	 * Normalize a selector list.
	 *
	 * @param mixed $values Values.
	 * @param int   $limit Limit.
	 * @return array<int, string>
	 */
	private function selector_list( $values, $limit ) {
		$result = array();
		foreach ( is_array( $values ) ? array_slice( $values, 0, $limit ) : array() as $value ) {
			if ( is_scalar( $value ) ) {
				$value = $this->selector( $value );
				if ( '' !== $value && ! in_array( $value, $result, true ) ) {
					$result[] = $value;
				}
			}
		}
		return $result;
	}

	/**
	 * Bound the serialized deterministic specification.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @return array<string, mixed>
	 */
	private function bound_specification( array $specification ) {
		$iteration = 0;
		while ( $this->encoded_size( $specification ) > Ai_Limits::MAX_OUTPUT_BYTES && $iteration < 10 ) {
			$iteration++;
			if ( count( $specification['content_mapping'] ) > 40 ) {
				$specification['content_mapping'] = array_slice( $specification['content_mapping'], 0, max( 40, (int) floor( count( $specification['content_mapping'] ) * 0.6 ) ) );
			} elseif ( count( $specification['components'] ) > 40 ) {
				$specification['components'] = array_slice( $specification['components'], 0, max( 40, (int) floor( count( $specification['components'] ) * 0.6 ) ) );
			} elseif ( ! empty( $specification['components'] ) ) {
				foreach ( $specification['components'] as $index => $component ) {
					if ( is_array( $component ) ) {
						unset( $specification['components'][ $index ]['text'], $specification['components'][ $index ]['fields'], $specification['components'][ $index ]['image'], $specification['components'][ $index ]['source'], $specification['components'][ $index ]['children'] );
					}
				}
				$specification['components'] = array_values( $specification['components'] );
			} elseif ( ! empty( $specification['hierarchy']['component_groups'] ) ) {
				foreach ( $specification['hierarchy']['component_groups'] as $group => $ids ) {
					$specification['hierarchy']['component_groups'][ $group ] = array_slice( $ids, 0, 30 );
				}
			} elseif ( ! empty( $specification['global_styles'] ) ) {
				$specification['global_styles'] = array( 'colors' => array_slice( isset( $specification['global_styles']['colors'] ) ? $specification['global_styles']['colors'] : array(), 0, 20 ), 'typography' => array_slice( isset( $specification['global_styles']['typography'] ) ? $specification['global_styles']['typography'] : array(), 0, 20 ), 'spacing' => array( 'scale' => array_slice( isset( $specification['global_styles']['spacing']['scale'] ) ? $specification['global_styles']['spacing']['scale'] : array(), 0, 20 ) ), 'radius' => array( 'scale' => array_slice( isset( $specification['global_styles']['radius']['scale'] ) ? $specification['global_styles']['radius']['scale'] : array(), 0, 20 ) ), 'shadows' => array_slice( isset( $specification['global_styles']['shadows'] ) ? $specification['global_styles']['shadows'] : array(), 0, 20 ), 'buttons' => array_slice( isset( $specification['global_styles']['buttons'] ) ? $specification['global_styles']['buttons'] : array(), 0, 20 ) );
			} elseif ( ! empty( $specification['design_system'] ) ) {
				$specification['design_system'] = array( 'colors' => array_slice( isset( $specification['design_system']['colors'] ) ? $specification['design_system']['colors'] : array(), 0, 20 ), 'typography' => array_slice( isset( $specification['design_system']['typography'] ) ? $specification['design_system']['typography'] : array(), 0, 20 ), 'spacing' => array( 'scale' => array_slice( isset( $specification['design_system']['spacing']['scale'] ) ? $specification['design_system']['spacing']['scale'] : array(), 0, 20 ) ), 'radius' => array( 'scale' => array_slice( isset( $specification['design_system']['radius']['scale'] ) ? $specification['design_system']['radius']['scale'] : array(), 0, 20 ) ), 'shadows' => array_slice( isset( $specification['design_system']['shadows'] ) ? $specification['design_system']['shadows'] : array(), 0, 20 ), 'buttons' => array_slice( isset( $specification['design_system']['buttons'] ) ? $specification['design_system']['buttons'] : array(), 0, 20 ) );
			} else {
				break;
			}
			$this->prune_specification_references( $specification );
			$specification['warnings'][] = 'The deterministic plan was compacted to stay within the Phase 3 output limit.';
		}
		$specification['warnings'] = array_values( array_unique( $specification['warnings'] ) );
		return $specification;
	}

	/**
	 * Remove references to component records compacted out of a plan.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @return void
	 */
	private function prune_specification_references( array &$specification ) {
		$available = array();
		foreach ( isset( $specification['components'] ) && is_array( $specification['components'] ) ? $specification['components'] : array() as $component ) {
			if ( is_array( $component ) && isset( $component['source_id'] ) ) {
				$available[ (string) $component['source_id'] ] = true;
			}
		}
		if ( empty( $available ) ) {
			return;
		}
		foreach ( isset( $specification['sections'] ) && is_array( $specification['sections'] ) ? $specification['sections'] : array() as $index => $section ) {
			if ( ! is_array( $section ) || ! isset( $section['components'] ) || ! is_array( $section['components'] ) ) {
				continue;
			}
			$specification['sections'][ $index ]['components'] = array_values( array_filter( $section['components'], static function ( $id ) use ( $available ) { return is_string( $id ) && isset( $available[ $id ] ); } ) );
		}
		$mapping = array();
		foreach ( isset( $specification['content_mapping'] ) && is_array( $specification['content_mapping'] ) ? $specification['content_mapping'] : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['component_id'] ) && isset( $available[ $entry['component_id'] ] ) ) {
				$mapping[] = $entry;
			}
		}
		$specification['content_mapping'] = $mapping;
	}

	/**
	 * Measure an encoded specification.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function encoded_size( $value ) {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return is_string( $encoded ) ? strlen( $encoded ) : PHP_INT_MAX;
	}

	/**
	 * Normalize a text value.
	 *
	 * @param mixed $value Value.
	 * @param int   $length Length.
	 * @return string
	 */
	private function text( $value, $length ) {
		$text = Security::clean_text( $value, $length );
		if ( preg_match( '/(?:-----BEGIN [A-Z ]+PRIVATE KEY-----|\\b(?:sk|pk|api|key|token)[-_][A-Za-z0-9_-]{12,}|\\bBearer\\s+[A-Za-z0-9._~+\\/-]+=*|\\bAIza[0-9A-Za-z_-]{20,}\\b|(?:password|passwd|api[_-]?key|access[_-]?token|authorization|cookie)\\s*[:=]\\s*[^\\s,;]+)/i', $text ) ) {
			return '[REDACTED]';
		}
		return $text;
	}

	/**
	 * Normalize nullable text.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function nullable_text( $value ) {
		return null === $value || '' === $value ? null : $this->text( $value, Ai_Limits::MAX_TEXT_LENGTH );
	}

	/**
	 * Normalize a nullable scalar.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function nullable_scalar( $value ) {
		return is_scalar( $value ) ? $value : null;
	}

	/**
	 * Normalize a key.
	 *
	 * @param mixed $key Key.
	 * @return string
	 */
	private function key( $key ) {
		$key = is_scalar( $key ) ? strtolower( trim( (string) $key ) ) : '';
		return 1 === preg_match( '/^[a-z0-9_.-]{1,80}$/', $key ) ? $key : '';
	}

	/**
	 * Normalize an ID.
	 *
	 * @param mixed $id ID.
	 * @return string
	 */
	private function id( $id ) {
		$id = is_scalar( $id ) ? trim( (string) $id ) : '';
		return 1 === preg_match( '/^[a-zA-Z0-9_:-]{1,100}$/', $id ) ? $id : '';
	}

	/**
	 * Normalize a selector.
	 *
	 * @param mixed $selector Selector.
	 * @return string
	 */
	private function selector( $selector ) {
		return Analysis_Normalizer::selector( $selector );
	}

	/**
	 * Normalize a public URL reference.
	 *
	 * @param mixed $value URL.
	 * @return string|null
	 */
	private function safe_url( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$url = Security::normalize_http_url( trim( $value ) );
		$url = is_string( $url ) ? preg_replace( '/#.*$/', '', $url ) : null;
		if ( ! is_string( $url ) || ! Security::is_safe_public_reference( $url ) || $this->url_has_sensitive_query( $url ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * Detect credential-like URL query parameters.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function url_has_sensitive_query( $url ) {
		$parts = Security::parse_url( $url );
		$query = is_array( $parts ) && isset( $parts['query'] ) ? (string) $parts['query'] : '';
		return '' !== $query && 1 === preg_match( '/(?:^|&)(?:token|access_token|id_token|jwt|session(?:id)?|sid|api[_-]?key|key|secret|password|passwd|auth|authorization|signature|sig|code)=/i', $query );
	}

	/**
	 * Clamp confidence.
	 *
	 * @param mixed $value Value.
	 * @return float
	 */
	private function confidence( $value ) {
		return Analysis_Normalizer::confidence( $value );
	}

	/**
	 * Convert a scalar to an integer.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function integer( $value ) {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Average confidence records.
	 *
	 * @param array<int, mixed> $records Records.
	 * @return float
	 */
	private function average( array $records ) {
		$total = 0.0;
		$count = 0;
		foreach ( $records as $record ) {
			if ( is_array( $record ) && isset( $record['confidence'] ) && is_numeric( $record['confidence'] ) ) {
				$total += (float) $record['confidence'];
				$count++;
			}
		}
		return $count ? $this->confidence( $total / $count ) : 0.0;
	}
}
