<?php
/**
 * Validation for the Phase 2 design representation.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Fails safely when generated representation data is structurally unsafe.
 */
final class Representation_Validator {

	/**
	 * Validation errors.
	 *
	 * @var array<int, string>
	 */
	private $errors = array();

	/**
	 * Validate a representation.
	 *
	 * @param mixed $representation Representation data.
	 * @return bool
	 */
	public function validate( $representation ) {
		$this->errors = array();
		if ( ! is_array( $representation ) ) {
			$this->errors[] = 'representation_not_array';
			return false;
		}

		$required = array(
			'schema_version',
			'page',
			'layout',
			'sections',
			'components',
			'hierarchy',
			'design_system',
			'responsive',
			'assets',
			'confidence',
			'warnings',
			'analysis',
		);
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $representation ) ) {
				$this->errors[] = 'missing_' . $key;
			}
		}
		if ( ! array_key_exists( 'schema_version', $representation ) || '2.0' !== $representation['schema_version'] ) {
			$this->errors[] = 'invalid_schema_version';
		}
		if ( ! $this->validate_strings( $representation ) ) {
			$this->errors[] = 'oversized_or_unsafe_value';
		}

		$this->validate_page( $representation );
		$this->validate_layout( $representation );
		$this->validate_design_system( $representation );
		$this->validate_responsive( $representation );
		$this->validate_assets( $representation );
		$this->validate_confidence( $representation );

		$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
		$components = isset( $representation['components'] ) && is_array( $representation['components'] ) ? $representation['components'] : array();
		if ( count( $sections ) > Analysis_Limits::MAX_SECTIONS || count( $components ) > Analysis_Limits::MAX_COMPONENTS ) {
			$this->errors[] = 'representation_limit_exceeded';
		}

		$section_ids = $this->validate_sections( $sections );
		$component_ids = $this->validate_components( $components, $section_ids );
		$this->validate_component_references( $components, $component_ids );
		$this->validate_section_references( $sections, $component_ids );
		$this->validate_hierarchy( $representation, $component_ids );

		if ( ! isset( $representation['warnings'] ) || ! is_array( $representation['warnings'] ) ) {
			$this->errors[] = 'invalid_warnings';
		} else {
			foreach ( $representation['warnings'] as $warning ) {
				if ( ! is_string( $warning ) || '' === trim( $warning ) ) {
					$this->errors[] = 'invalid_warning_value';
				}
			}
		}

		$this->validate_analysis( $representation );
		return empty( $this->errors );
	}

	/**
	 * Return validation errors.
	 *
	 * @return array<int, string>
	 */
	public function get_errors() {
		return array_values( array_unique( $this->errors ) );
	}

	/**
	 * Validate page metadata.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return void
	 */
	private function validate_page( $representation ) {
		$page = isset( $representation['page'] ) && is_array( $representation['page'] ) ? $representation['page'] : array();
		foreach ( array( 'url', 'final_url', 'title', 'type', 'language', 'description' ) as $key ) {
			if ( ! array_key_exists( $key, $page ) || ! is_string( $page[ $key ] ) ) {
				$this->errors[] = 'invalid_page_' . $key;
			}
		}
		foreach ( array( 'url', 'final_url' ) as $key ) {
			if ( isset( $page[ $key ] ) && is_string( $page[ $key ] ) && '' !== $page[ $key ] && ! $this->is_safe_url( $page[ $key ] ) ) {
				$this->errors[] = 'unsafe_page_' . $key;
			}
		}
		if ( ! isset( $page['type_confidence'] ) || ! $this->valid_confidence( $page['type_confidence'] ) ) {
			$this->errors[] = 'invalid_page_type_confidence';
		}
		if ( ! isset( $page['http_status'] ) || ! is_numeric( $page['http_status'] ) || (int) $page['http_status'] < 100 || (int) $page['http_status'] > 599 ) {
			$this->errors[] = 'invalid_page_http_status';
		}
	}

	/**
	 * Validate layout and region structure.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return void
	 */
	private function validate_layout( $representation ) {
		$layout = isset( $representation['layout'] ) && is_array( $representation['layout'] ) ? $representation['layout'] : array();
		if ( ! isset( $layout['page'] ) || ! is_array( $layout['page'] ) || ! isset( $layout['regions'] ) || ! is_array( $layout['regions'] ) ) {
			$this->errors[] = 'invalid_layout';
			return;
		}
		if ( count( $layout['regions'] ) > 20 ) {
			$this->errors[] = 'invalid_layout_regions';
		}
		$region_ids = array();
		foreach ( $layout['regions'] as $region ) {
			if ( ! is_array( $region ) || ! $this->valid_id( $region['id'] ?? '', '/^region_[0-9]{3}$/' ) || isset( $region_ids[ $region['id'] ] ) ) {
				$this->errors[] = 'invalid_or_duplicate_region_id';
				continue;
			}
			$region_ids[ $region['id'] ] = true;
			if ( ! is_string( $region['type'] ?? null ) || ! isset( $region['order'] ) || ! is_int( $region['order'] ) || ! isset( $region['confidence'] ) || ! $this->valid_confidence( $region['confidence'] ) || ! is_array( $region['source'] ?? null ) ) {
				$this->errors[] = 'invalid_region_fields';
			}
		}
	}

	/**
	 * Validate design-system families.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return void
	 */
	private function validate_design_system( $representation ) {
		$system = isset( $representation['design_system'] ) && is_array( $representation['design_system'] ) ? $representation['design_system'] : array();
		foreach ( array( 'colors', 'typography', 'spacing', 'radius', 'shadows', 'buttons', 'containers', 'design_tokens' ) as $key ) {
			if ( ! isset( $system[ $key ] ) || ! is_array( $system[ $key ] ) ) {
				$this->errors[] = 'invalid_design_system_' . $key;
			}
		}
		if ( isset( $system['colors'] ) && is_array( $system['colors'] ) ) {
			if ( ! isset( $system['colors']['tokens'], $system['colors']['by_role'] ) || ! is_array( $system['colors']['tokens'] ) || ! is_array( $system['colors']['by_role'] ) ) {
				$this->errors[] = 'invalid_color_system';
			}
		}
		if ( isset( $system['typography'] ) && is_array( $system['typography'] ) ) {
			if ( ! isset( $system['typography']['hierarchy'], $system['typography']['families'] ) || ! is_array( $system['typography']['hierarchy'] ) || ! is_array( $system['typography']['families'] ) ) {
				$this->errors[] = 'invalid_typography_system';
			}
		}
	}

	/**
	 * Validate responsive evidence.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return void
	 */
	private function validate_responsive( $representation ) {
		$responsive = isset( $representation['responsive'] ) && is_array( $representation['responsive'] ) ? $representation['responsive'] : array();
		foreach ( array( 'viewport_meta', 'media_queries_detected', 'breakpoints', 'rules', 'mobile_navigation', 'confidence' ) as $key ) {
			if ( ! array_key_exists( $key, $responsive ) ) {
				$this->errors[] = 'invalid_responsive_' . $key;
			}
		}
		if ( isset( $responsive['breakpoints'] ) && ! is_array( $responsive['breakpoints'] ) ) {
			$this->errors[] = 'invalid_breakpoints';
		}
		if ( isset( $responsive['rules'] ) && ! is_array( $responsive['rules'] ) ) {
			$this->errors[] = 'invalid_responsive_rules';
		}
		if ( isset( $responsive['mobile_navigation'] ) && ! is_array( $responsive['mobile_navigation'] ) ) {
			$this->errors[] = 'invalid_mobile_navigation';
		}
		if ( isset( $responsive['confidence'] ) && ! $this->valid_confidence( $responsive['confidence'] ) ) {
			$this->errors[] = 'invalid_responsive_confidence';
		}
	}

	/**
	 * Validate asset metadata.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return void
	 */
	private function validate_assets( $representation ) {
		$assets = isset( $representation['assets'] ) && is_array( $representation['assets'] ) ? $representation['assets'] : array();
		foreach ( array( 'stylesheets', 'images', 'fonts', 'counts' ) as $key ) {
			if ( ! isset( $assets[ $key ] ) || ! is_array( $assets[ $key ] ) ) {
				$this->errors[] = 'invalid_assets_' . $key;
			}
		}
	}

	/**
	 * Validate aggregate confidence.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return void
	 */
	private function validate_confidence( $representation ) {
		$confidence = isset( $representation['confidence'] ) && is_array( $representation['confidence'] ) ? $representation['confidence'] : array();
		foreach ( array( 'overall', 'sections', 'components', 'design_system', 'responsive' ) as $key ) {
			if ( ! isset( $confidence[ $key ] ) || ! $this->valid_confidence( $confidence[ $key ] ) ) {
				$this->errors[] = 'invalid_confidence_' . $key;
			}
		}
	}

	/**
	 * Validate sections and return their IDs.
	 *
	 * @param array<int, mixed> $sections Sections.
	 * @return array<string, bool>
	 */
	private function validate_sections( $sections ) {
		$ids = array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || ! $this->valid_id( $section['id'] ?? '', '/^section_[0-9]{3}$/' ) || isset( $ids[ $section['id'] ] ) ) {
				$this->errors[] = 'invalid_or_duplicate_section_id';
				continue;
			}
			$ids[ $section['id'] ] = true;
			if ( ! is_string( $section['type'] ?? null ) || ! isset( $section['order'] ) || ! is_int( $section['order'] ) || ! isset( $section['confidence'] ) || ! $this->valid_confidence( $section['confidence'] ) || ! is_array( $section['source'] ?? null ) ) {
				$this->errors[] = 'invalid_section_fields';
			}
			if ( ! isset( $section['components'] ) || ! is_array( $section['components'] ) || ! isset( $section['children'] ) || ! is_array( $section['children'] ) ) {
				$this->errors[] = 'invalid_section_lists';
			}
		}
		return $ids;
	}

	/**
	 * Validate components and return their IDs.
	 *
	 * @param array<int, mixed>       $components Components.
	 * @param array<string, bool>      $section_ids Section IDs.
	 * @return array<string, bool>
	 */
	private function validate_components( $components, $section_ids ) {
		$ids = array();
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || ! $this->valid_id( $component['id'] ?? '', '/^component_[0-9]{3,}$/' ) || isset( $ids[ $component['id'] ] ) ) {
				$this->errors[] = 'invalid_or_duplicate_component_id';
				continue;
			}
			$ids[ $component['id'] ] = true;
			if ( ! is_string( $component['type'] ?? null ) || ! isset( $component['confidence'] ) || ! $this->valid_confidence( $component['confidence'] ) || ! is_array( $component['source'] ?? null ) || ! isset( $component['children'] ) || ! is_array( $component['children'] ) ) {
				$this->errors[] = 'invalid_component_fields';
			}
			if ( isset( $component['section_id'] ) && null !== $component['section_id'] && ( ! is_string( $component['section_id'] ) || ! isset( $section_ids[ $component['section_id'] ] ) ) ) {
				$this->errors[] = 'invalid_component_section_reference';
			}
		}
		return $ids;
	}

	/**
	 * Validate component child references.
	 *
	 * @param array<int, mixed>  $components   Components.
	 * @param array<string, bool> $component_ids Component IDs.
	 * @return void
	 */
	private function validate_component_references( $components, $component_ids ) {
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) || ! isset( $component['children'] ) || ! is_array( $component['children'] ) ) {
				continue;
			}
			foreach ( $component['children'] as $reference ) {
				if ( ! is_string( $reference ) || ! isset( $component_ids[ $reference ] ) ) {
					$this->errors[] = 'invalid_component_child_reference';
				}
			}
		}
	}

	/**
	 * Validate section-to-component and child references.
	 *
	 * @param array<int, mixed>  $sections     Sections.
	 * @param array<string, bool> $component_ids Component IDs.
	 * @return void
	 */
	private function validate_section_references( $sections, $component_ids ) {
		$section_ids = array();
		foreach ( $sections as $section ) {
			if ( is_array( $section ) && is_string( $section['id'] ?? null ) ) {
				$section_ids[ $section['id'] ] = true;
			}
		}
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			foreach ( array( 'components', 'children' ) as $list_name ) {
				if ( ! isset( $section[ $list_name ] ) || ! is_array( $section[ $list_name ] ) ) {
					continue;
				}
				foreach ( $section[ $list_name ] as $reference ) {
					$valid = is_string( $reference ) && ( 'components' === $list_name ? isset( $component_ids[ $reference ] ) : isset( $section_ids[ $reference ] ) );
					if ( ! $valid ) {
						$this->errors[] = 'invalid_section_' . $list_name . '_reference';
					}
				}
			}
		}
	}

	/**
	 * Validate hierarchy references.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @param array<string, bool>  $component_ids   Component IDs.
	 * @return void
	 */
	private function validate_hierarchy( $representation, $component_ids ) {
		$hierarchy = isset( $representation['hierarchy'] ) && is_array( $representation['hierarchy'] ) ? $representation['hierarchy'] : array();
		foreach ( array( 'primary', 'secondary', 'supporting', 'cta', 'decorative' ) as $group ) {
			if ( ! isset( $hierarchy[ $group ] ) || ! is_array( $hierarchy[ $group ] ) ) {
				$this->errors[] = 'invalid_hierarchy_' . $group;
				continue;
			}
			foreach ( $hierarchy[ $group ] as $reference ) {
				if ( ! is_string( $reference ) || ! isset( $component_ids[ $reference ] ) ) {
					$this->errors[] = 'invalid_hierarchy_reference';
				}
			}
		}
	}

	/**
	 * Validate analysis metadata.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return void
	 */
	private function validate_analysis( $representation ) {
		$analysis = isset( $representation['analysis'] ) && is_array( $representation['analysis'] ) ? $representation['analysis'] : array();
		if ( ! isset( $analysis['phase'] ) || '2.0' !== $analysis['phase'] || ! isset( $analysis['duration_ms'] ) || ! is_numeric( $analysis['duration_ms'] ) || (int) $analysis['duration_ms'] < 0 ) {
			$this->errors[] = 'invalid_analysis';
		}
	}

	/**
	 * Validate recursive strings and URL-shaped values.
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Key hint.
	 * @return bool
	 */
	private function validate_strings( $value, $key = '', $depth = 0 ) {
		if ( $depth > 32 || is_object( $value ) || is_resource( $value ) ) {
			return false;
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 10000 ) {
				return false;
			}
			if ( in_array( $key, array( 'selector', 'selectors', 'source' ), true ) && strlen( $value ) > Analysis_Limits::MAX_SELECTOR_LENGTH ) {
				return false;
			}
			if ( preg_match( '~^https?://~i', $value ) && ! $this->is_safe_url( $value ) ) {
				return false;
			}
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child_key => $child ) {
				$next_key = is_string( $child_key ) ? $child_key : $key;
				if ( ! $this->validate_strings( $child, $next_key, $depth + 1 ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Check a URL against the public reference policy.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_safe_url( $url ) {
		return Security::is_safe_http_syntax( $url ) && Security::is_safe_public_reference( $url );
	}

	/**
	 * Check confidence values.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function valid_confidence( $value ) {
		return is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0 && (float) $value <= 1;
	}

	/**
	 * Check an ID shape without using it as an unsafe array key.
	 *
	 * @param mixed  $value Value.
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	private function valid_id( $value, $pattern ) {
		return is_string( $value ) && 1 === preg_match( $pattern, $value );
	}
}
