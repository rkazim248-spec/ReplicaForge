<?php
/**
 * Phase 4 input validation for the Reconstruction Specification.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalizes a Phase 3 Reconstruction Specification 3.0.
 *
 * Phase 4 never trusts a specification, even one produced by a previous request
 * in the same session. The specification is re-validated, bounded, and
 * normalized into a builder-neutral plan that the mapper consumes. Elementor
 * data, executable markup, and unsafe URLs are rejected here rather than being
 * sanitized silently.
 */
final class Elementor_Spec_Validator {

	/**
	 * Allowed component reconstruction types.
	 *
	 * @var array<int, string>
	 */
	private $allowed_component_types = array(
		'heading',
		'paragraph',
		'button',
		'link',
		'image',
		'background_image',
		'card',
		'product_card',
		'testimonial',
		'pricing_card',
		'form',
		'form_field',
		'navigation_link',
		'logo',
		'icon',
		'gallery',
		'accordion',
		'tabs',
		'tab',
		'list',
		'label',
		'feature_card',
		'blog_card',
		'team_card',
		'portfolio_card',
		'repeated_card_group',
		'unknown',
	);

	/**
	 * Keys that must never appear anywhere in a Phase 3 specification.
	 *
	 * @var array<int, string>
	 */
	private $forbidden_keys = array(
		'_elementor_data',
		'_elementor_edit_mode',
		'_elementor_controls',
		'_elementor_page_settings',
		'_elementor_template_type',
		'eltype',
		'widgettype',
		'shortcode',
		'html_widget',
		'wp_nonce',
		'_wpnonce',
		'nonce',
		'api_key',
		'api_secret',
		'authorization',
		'password',
	);

	/**
	 * Validate a specification.
	 *
	 * @param mixed $specification Raw specification.
	 * @return array<string, mixed> `valid`, `errors`, `warnings`, and `plan`.
	 */
	public function validate( $specification ) {
		$errors   = array();
		$warnings = array();

		if ( ! is_array( $specification ) ) {
			return $this->result( false, array( 'specification_not_an_object' ), $warnings, array() );
		}

		if ( ! isset( $specification['schema_version'] ) || Elementor_Limits::SPEC_SCHEMA_VERSION !== (string) $specification['schema_version'] ) {
			return $this->result(
				false,
				array( 'unsupported_schema_version' ),
				$warnings,
				array(),
				sprintf(
					/* translators: 1: Received schema version, 2: Required schema version. */
					__( 'ReplicaForge expected a Reconstruction Specification %2$s but received %1$s.', 'replicaforge' ),
					isset( $specification['schema_version'] ) && is_scalar( $specification['schema_version'] ) ? (string) $specification['schema_version'] : 'none',
					Elementor_Limits::SPEC_SCHEMA_VERSION
				)
			);
		}

		$this->scan_forbidden_keys( $specification, $errors, 0 );

		$plan = $this->build_plan( $specification, $errors, $warnings );

		$valid = empty( $errors );
		if ( ! $valid ) {
			$plan = array();
		}

		return $this->result( $valid, $errors, $warnings, $plan );
	}

	/**
	 * Build the builder-neutral plan consumed by the mapper.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @param array<int, string>   $errors        Error collector.
	 * @param array<int, string>   $warnings      Warning collector.
	 * @return array<string, mixed>
	 */
	private function build_plan( array $specification, array &$errors, array &$warnings ) {
		$plan = array(
			'schema_version' => Elementor_Limits::SPEC_SCHEMA_VERSION,
			'source'          => $this->plan_source( $specification, $warnings ),
			'page_strategy'   => $this->plan_page_strategy( $specification ),
			'components'      => array(),
			'sections'        => array(),
			'content'         => array(),
			'assets'          => array(),
			'design'          => $this->plan_design( $specification, $warnings ),
			'responsive'      => $this->plan_responsive( $specification ),
			'confidence'      => $this->plan_confidence( $specification ),
			'warnings'        => $this->string_list( isset( $specification['warnings'] ) ? $specification['warnings'] : array(), Elementor_Limits::MAX_REPORT_WARNINGS, 300 ),
		);

		$component_plans = array();
		$components      = isset( $specification['components'] ) && is_array( $specification['components'] ) ? array_slice( $specification['components'], 0, Elementor_Limits::MAX_COMPONENTS ) : array();
		if ( count( $components ) > Elementor_Limits::MAX_COMPONENTS ) {
			$warnings[] = __( 'Some components were not generated because the Phase 4 component limit was reached.', 'replicaforge' );
		}

		foreach ( $components as $index => $component ) {
			if ( ! is_array( $component ) || ! isset( $component['id'] ) || ! isset( $component['source_id'] ) ) {
				$errors[] = 'component_record_invalid';
				continue;
			}
			$source_id = $this->safe_id( $component['source_id'] );
			$plan_id   = $this->safe_id( $component['id'] );
			if ( '' === $source_id || '' === $plan_id ) {
				$errors[] = 'component_id_invalid';
				continue;
			}
			if ( isset( $component_plans[ $source_id ] ) ) {
				$errors[] = 'component_duplicate_id';
				continue;
			}

			$reconstruction_type = isset( $component['reconstruction_type'] ) && is_string( $component['reconstruction_type'] ) ? $component['reconstruction_type'] : '';
			if ( '' === $reconstruction_type ) {
				$reconstruction_type = isset( $component['type'] ) && is_string( $component['type'] ) ? $component['type'] : 'unknown';
			}
			if ( ! in_array( $reconstruction_type, $this->allowed_component_types, true ) ) {
				$warnings[] = __( 'A component used an unrecognized type and was mapped to a safe generic structure.', 'replicaforge' );
				$reconstruction_type = 'unknown';
			}

			$component_plans[ $source_id ] = array(
				'id'                 => $plan_id,
				'index'              => (int) $index,
				'source_id'          => $source_id,
				'type'               => $this->safe_token( isset( $component['type'] ) ? $component['type'] : '', 80 ),
				'reconstruction_type' => $reconstruction_type,
				'role'               => $this->safe_token( isset( $component['role'] ) ? $component['role'] : '', 120 ),
				'semantic_role'      => $this->safe_token( isset( $component['semantic_role'] ) ? $component['semantic_role'] : '', 120 ),
				'content_source'     => $this->safe_token( isset( $component['content_source'] ) ? $component['content_source'] : '', 40 ),
				'confidence'         => $this->confidence( isset( $component['confidence'] ) ? $component['confidence'] : 0 ),
				'section_id'         => isset( $component['section_id'] ) && is_string( $component['section_id'] ) ? $this->safe_id( $component['section_id'] ) : '',
				'children'           => $this->string_list( isset( $component['children'] ) ? $component['children'] : array(), Elementor_Limits::MAX_COMPONENTS, 120 ),
				'tag'                => $this->source_tag( isset( $component['source'] ) ? $component['source'] : array() ),
				'target'             => $this->safe_target( isset( $component['target'] ) ? $component['target'] : null ),
				'target_known'       => isset( $component['target'] ) && is_string( $component['target'] ) && '' !== trim( $component['target'] ),
				'count'              => isset( $component['count'] ) && is_numeric( $component['count'] ) ? max( 0, (int) $component['count'] ) : null,
				'card_type'          => isset( $component['card_type'] ) && is_string( $component['card_type'] ) ? $this->safe_token( $component['card_type'], 60 ) : '',
				'fields'             => $this->plan_fields( isset( $component['fields'] ) ? $component['fields'] : array() ),
				'image'              => $this->plan_image( isset( $component['image'] ) ? $component['image'] : array() ),
			);

			$plan['components'][ $source_id ] = $component_plans[ $source_id ];
		}

		if ( empty( $component_plans ) ) {
			$errors[] = 'components_missing';
		}

		$sections        = isset( $specification['sections'] ) && is_array( $specification['sections'] ) ? array_slice( $specification['sections'], 0, Elementor_Limits::MAX_SECTIONS ) : array();
		$component_order = array_keys( $component_plans );
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['id'] ) || ! isset( $section['source_id'] ) ) {
				$errors[] = 'section_record_invalid';
				continue;
			}
			$source_id = $this->safe_id( $section['source_id'] );
			$plan_id   = $this->safe_id( $section['id'] );
			if ( '' === $source_id || '' === $plan_id ) {
				$errors[] = 'section_id_invalid';
				continue;
			}
			$members = $this->string_list( isset( $section['components'] ) ? $section['components'] : array(), Elementor_Limits::MAX_COMPONENTS, 120 );
			$known   = array();
			foreach ( $members as $member ) {
				if ( isset( $component_plans[ $member ] ) ) {
					$known[] = $member;
				} else {
					$warnings[] = __( 'A section referenced a component that is not present in the specification and it was skipped.', 'replicaforge' );
				}
			}
			foreach ( $component_plans as $component_source_id => $component_plan ) {
				if ( $component_plan['section_id'] === $source_id && ! in_array( $component_source_id, $known, true ) ) {
					$known[] = $component_source_id;
				}
			}
			$plan['sections'][] = array(
				'id'           => $plan_id,
				'source_id'    => $source_id,
				'type'         => $this->safe_token( isset( $section['type'] ) ? $section['type'] : 'unknown', 80 ),
				'order'        => isset( $section['order'] ) && is_numeric( $section['order'] ) ? (int) $section['order'] : 0,
				'confidence'   => $this->confidence( isset( $section['confidence'] ) ? $section['confidence'] : 0 ),
				'layout'       => $this->plan_layout( isset( $section['layout'] ) ? $section['layout'] : array() ),
				'components'   => $known,
				'heading'      => $this->safe_text( isset( $section['content_summary']['heading'] ) ? $section['content_summary']['heading'] : null ),
				'tag'          => $this->source_tag( isset( $section['source'] ) ? $section['source'] : array() ),
			);
		}

		if ( empty( $plan['sections'] ) ) {
			$errors[] = 'sections_missing';
		}

		// Components that no section claims are appended to a trailing container
		// so detected content is never silently dropped.
		$claimed = array();
		foreach ( $plan['sections'] as $section ) {
			foreach ( $section['components'] as $member ) {
				$claimed[ $member ] = true;
			}
		}
		$orphans = array_values( array_diff( $component_order, array_keys( $claimed ) ) );
		if ( ! empty( $orphans ) ) {
			$warnings[] = __( 'Some detected components were not assigned to a section and were placed in an additional content container.', 'replicaforge' );
			$plan['sections'][] = array(
				'id'         => 'reconstruction_section_orphan',
				'source_id'  => 'replicaforge_orphan_section',
				'type'       => 'content',
				'order'      => PHP_INT_MAX,
				'confidence' => 0,
				'layout'     => array(
					'type'         => 'stack',
					'column_count' => 1,
					'direction'    => 'column',
				),
				'components' => $orphans,
				'heading'    => null,
				'tag'        => '',
				'generated'  => true,
			);
		}

		$plan['content'] = $this->plan_content( isset( $specification['content_mapping'] ) ? $specification['content_mapping'] : array(), $plan['components'], $warnings );
		$plan['assets']  = $this->plan_assets( isset( $specification['assets'] ) ? $specification['assets'] : array(), $plan['components'], $warnings );

		return $plan;
	}

	/**
	 * Normalize the source reference block.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @param array<int, string>   $warnings      Warning collector.
	 * @return array<string, mixed>
	 */
	private function plan_source( array $specification, array &$warnings ) {
		$strategy = isset( $specification['page_strategy'] ) && is_array( $specification['page_strategy'] ) ? $specification['page_strategy'] : array();

		$title = '';
		$url   = '';
		if ( isset( $specification['source'] ) && is_array( $specification['source'] ) ) {
			$title = $this->safe_text( isset( $specification['source']['title'] ) ? $specification['source']['title'] : null );
			$url   = $this->safe_target( isset( $specification['source']['url'] ) ? $specification['source']['url'] : null );
		}
		if ( '' === $title && isset( $strategy['source_title'] ) ) {
			$title = $this->safe_text( $strategy['source_title'] );
		}

		return array(
			'title' => $title,
			'url'   => is_string( $url ) ? $url : '',
			'type'  => $this->safe_token( isset( $strategy['layout_type'] ) ? $strategy['layout_type'] : 'unknown', 80 ),
		);
	}

	/**
	 * Normalize the page strategy.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @return array<string, mixed>
	 */
	private function plan_page_strategy( array $specification ) {
		$strategy = isset( $specification['page_strategy'] ) && is_array( $specification['page_strategy'] ) ? $specification['page_strategy'] : array();
		$layout   = isset( $strategy['container_strategy'] ) && is_string( $strategy['container_strategy'] ) ? $strategy['container_strategy'] : 'unknown';
		$width    = '';
		if ( isset( $specification['global_styles']['container_width'] ) ) {
			$candidate = $specification['global_styles']['container_width'];
			if ( is_string( $candidate ) && Elementor_Values::is_safe_length( $candidate ) ) {
				$width = $candidate;
			} elseif ( is_array( $candidate ) && isset( $candidate['max_width'] ) && is_string( $candidate['max_width'] ) && Elementor_Values::is_safe_length( $candidate['max_width'] ) ) {
				$width = $candidate['max_width'];
			}
		}

		return array(
			'layout_type'         => $this->safe_token( isset( $strategy['layout_type'] ) ? $strategy['layout_type'] : 'unknown', 80 ),
			'container_strategy'  => $this->safe_token( $layout, 80 ),
			'container_width'     => $width,
			'responsive_strategy' => $this->safe_token( isset( $strategy['responsive_strategy'] ) ? $strategy['responsive_strategy'] : 'unknown', 80 ),
			'confidence'          => $this->confidence( isset( $strategy['confidence'] ) ? $strategy['confidence'] : 0 ),
		);
	}

	/**
	 * Normalize a section layout.
	 *
	 * @param mixed $layout Raw layout.
	 * @return array<string, mixed>
	 */
	private function plan_layout( $layout ) {
		$layout = is_array( $layout ) ? $layout : array();
		$type   = $this->safe_token( isset( $layout['type'] ) ? $layout['type'] : '', 80 );

		$column_count = 0;
		if ( isset( $layout['column_count'] ) && is_numeric( $layout['column_count'] ) ) {
			$column_count = max( 0, (int) $layout['column_count'] );
		}
		if ( $column_count < 1 && ! in_array( $type, array( 'two_column', 'three_column', 'four_column', 'grid' ), true ) ) {
			$column_count = 1;
		}
		$column_count = min( 6, max( 1, $column_count ) );

		$direction = '';
		if ( isset( $layout['direction'] ) && is_string( $layout['direction'] ) ) {
			$candidate = strtolower( $layout['direction'] );
			if ( in_array( $candidate, array( 'row', 'column', 'row-reverse', 'column-reverse' ), true ) ) {
				$direction = $candidate;
			}
		}
		if ( '' === $direction ) {
			$direction = $column_count > 1 ? 'row' : 'column';
		}
		if ( $column_count > 1 ) {
			$direction = 'row';
		}

		$gap = '';
		if ( isset( $layout['gap'] ) && is_string( $layout['gap'] ) && Elementor_Values::is_safe_length( $layout['gap'] ) ) {
			$gap = $layout['gap'];
		}

		$alignment = '';
		$justify   = '';
		$align     = '';
		if ( isset( $layout['alignment'] ) ) {
			$alignment = $layout['alignment'];
			if ( is_array( $alignment ) ) {
				$justify = isset( $alignment['horizontal'] ) ? (string) $alignment['horizontal'] : '';
				$align   = isset( $alignment['vertical'] ) ? (string) $alignment['vertical'] : '';
			} elseif ( is_string( $alignment ) ) {
				$justify = $alignment;
			}
		}
		$justify = $this->flex_justify( $justify );
		$align   = $this->flex_align( $align );

		$wrap = '';
		if ( isset( $layout['wrap'] ) && is_string( $layout['wrap'] ) ) {
			$candidate = strtolower( $layout['wrap'] );
			if ( in_array( $candidate, array( 'wrap', 'nowrap', 'wrap-reverse' ), true ) ) {
				$wrap = in_array( $candidate, array( 'wrap', 'nowrap' ), true ) ? $candidate : '';
			}
		}
		if ( '' === $wrap && $column_count > 1 ) {
			$wrap = 'wrap';
		}

		$columns = array();
		foreach ( isset( $layout['columns'] ) && is_array( $layout['columns'] ) ? array_slice( $layout['columns'], 0, 6 ) : array() as $column ) {
			$ratio = null;
			if ( is_array( $column ) && isset( $column['width_ratio'] ) && is_numeric( $column['width_ratio'] ) ) {
				$ratio = (float) $column['width_ratio'];
			} elseif ( is_numeric( $column ) ) {
				$ratio = (float) $column;
			}
			if ( null !== $ratio && $ratio > 0 && $ratio <= 1 ) {
				$columns[] = round( $ratio, 4 );
			}
		}
		if ( count( $columns ) !== $column_count ) {
			$columns = array();
		}

		$container = isset( $layout['container'] ) && is_array( $layout['container'] ) ? $layout['container'] : array();
		$width     = '';
		if ( isset( $container['max_width'] ) && is_string( $container['max_width'] ) && Elementor_Values::is_safe_length( $container['max_width'] ) ) {
			$width = $container['max_width'];
		}

		return array(
			'type'         => '' !== $type ? $type : 'stack',
			'column_count' => $column_count,
			'columns'      => $columns,
			'direction'    => $direction,
			'gap'          => $gap,
			'justify'      => $justify,
			'align_items'  => $align,
			'wrap'         => $wrap,
			'max_width'    => $width,
			'min_width'    => $this->safe_length_value( isset( $container['min_width'] ) ? $container['min_width'] : null ),
			'padding'      => $this->safe_box_value( isset( $layout['padding'] ) ? $layout['padding'] : null ),
			'margin'       => $this->safe_box_value( isset( $layout['margin'] ) ? $layout['margin'] : null ),
			'min_height'   => $this->safe_length_value( isset( $layout['min_height'] ) ? $layout['min_height'] : null ),
			'centered'     => ! empty( $container['centered'] ),
			'confidence'   => $this->confidence( isset( $layout['confidence'] ) ? $layout['confidence'] : 0 ),
		);
	}

	/**
	 * Return a safe single CSS length or an empty string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function safe_length_value( $value ) {
		return is_string( $value ) && Elementor_Values::is_safe_length( $value ) ? $value : '';
	}

	/**
	 * Return a safe box value written as one to four CSS lengths.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function safe_box_value( $value ) {
		if ( is_string( $value ) && Elementor_Values::is_safe_length( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 120 ) {
			return '';
		}
		$parts = preg_split( '/\s+/', trim( $value ) );
		if ( ! is_array( $parts ) || count( $parts ) > 4 ) {
			return '';
		}
		foreach ( $parts as $part ) {
			if ( ! Elementor_Values::is_safe_length( $part ) ) {
				return '';
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * Map a detected horizontal alignment to an Elementor justify value.
	 *
	 * @param string $value Detected value.
	 * @return string
	 */
	private function flex_justify( $value ) {
		$map = array(
			'flex-start'     => 'flex-start',
			'start'          => 'flex-start',
			'left'           => 'flex-start',
			'center'         => 'center',
			'centre'         => 'center',
			'flex-end'       => 'flex-end',
			'end'            => 'flex-end',
			'right'          => 'flex-end',
			'space-between'  => 'space-between',
			'space-around'   => 'space-around',
			'space-evenly'   => 'space-evenly',
		);
		$value = strtolower( trim( (string) $value ) );
		return isset( $map[ $value ] ) ? $map[ $value ] : '';
	}

	/**
	 * Map a detected vertical alignment to an Elementor align-items value.
	 *
	 * @param string $value Detected value.
	 * @return string
	 */
	private function flex_align( $value ) {
		$map = array(
			'flex-start' => 'flex-start',
			'start'      => 'flex-start',
			'top'        => 'flex-start',
			'center'     => 'center',
			'centre'     => 'center',
			'middle'     => 'center',
			'flex-end'   => 'flex-end',
			'end'        => 'flex-end',
			'bottom'     => 'flex-end',
			'stretch'    => 'stretch',
			'baseline'   => 'baseline',
		);
		$value = strtolower( trim( (string) $value ) );
		return isset( $map[ $value ] ) ? $map[ $value ] : '';
	}

	/**
	 * Normalize a component field map.
	 *
	 * @param mixed $fields Raw fields.
	 * @return array<string, mixed>
	 */
	private function plan_fields( $fields ) {
		$result = array();
		foreach ( is_array( $fields ) ? $fields : array() as $key => $value ) {
			$name = $this->safe_id( $key );
			if ( '' === $name || isset( $result[ $name ] ) ) {
				continue;
			}
			if ( in_array( $name, array( 'image', 'link', 'url', 'thumbnail' ), true ) ) {
				$result[ $name ] = $this->safe_target( $value );
				continue;
			}
			if ( null === $value || is_array( $value ) || is_object( $value ) ) {
				$result[ $name ] = null;
				continue;
			}
			$result[ $name ] = $this->safe_text( $value, 300 );
		}
		return $result;
	}

	/**
	 * Normalize component image metadata.
	 *
	 * @param mixed $image Raw image metadata.
	 * @return array<string, mixed>
	 */
	private function plan_image( $image ) {
		$image = is_array( $image ) ? $image : array();
		return array(
			'src'    => $this->safe_target( isset( $image['src'] ) ? $image['src'] : null ),
			'alt'    => $this->safe_text( isset( $image['alt'] ) ? $image['alt'] : null, 300 ),
			'width'  => isset( $image['width'] ) && is_numeric( $image['width'] ) ? absint( $image['width'] ) : 0,
			'height' => isset( $image['height'] ) && is_numeric( $image['height'] ) ? absint( $image['height'] ) : 0,
		);
	}

	/**
	 * Normalize content mappings per component.
	 *
	 * @param mixed                      $content_mapping Raw content mapping.
	 * @param array<string, array>       $components      Normalized components.
	 * @param array<int, string>         $warnings        Warning collector.
	 * @return array<string, array<string, mixed>>
	 */
	private function plan_content( $content_mapping, array $components, array &$warnings ) {
		$result = array();
		foreach ( array_keys( $components ) as $component_id ) {
			$result[ $component_id ] = array(
				'text'          => null,
				'alt'           => null,
				'link'          => null,
				'link_known'    => false,
				'link_reported' => false,
				'fields'        => array(),
			);
		}

		$records = is_array( $content_mapping ) ? $content_mapping : array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || ! isset( $record['component_id'] ) || ! is_string( $record['component_id'] ) ) {
				continue;
			}
			$component_id = $this->safe_id( $record['component_id'] );
			if ( ! isset( $result[ $component_id ] ) ) {
				$warnings[] = __( 'A content record referenced a component that is not present in the specification and was ignored.', 'replicaforge' );
				continue;
			}

			$content_type = isset( $record['content_type'] ) && is_string( $record['content_type'] ) ? $record['content_type'] : 'text';
			if ( 'field' === $content_type ) {
				$field = isset( $record['field'] ) && is_string( $record['field'] ) ? $this->safe_id( $record['field'] ) : '';
				if ( '' !== $field && ! isset( $result[ $component_id ]['fields'][ $field ] ) ) {
					$result[ $component_id ]['fields'][ $field ] = $this->safe_text( isset( $record['value'] ) ? $record['value'] : null, 300 );
				}
				continue;
			}

			if ( 'link' === $content_type ) {
				$result[ $component_id ]['link_reported'] = true;
				$target                                 = $this->safe_target( isset( $record['target'] ) ? $record['target'] : null );
				if ( is_string( $target ) && '' !== $target ) {
					$result[ $component_id ]['link']       = $target;
					$result[ $component_id ]['link_known'] = true;
				} elseif ( isset( $record['preserve'] ) && false === $record['preserve'] ) {
					// The specification explicitly records that the link target is
					// unknown, which is the only state that allows a placeholder.
					$result[ $component_id ]['link_known'] = false;
				}
				continue;
			}

			$value = $this->safe_text( isset( $record['value'] ) ? $record['value'] : null );
			if ( null === $value || '' === $value ) {
				continue;
			}

			if ( 'image_alt' === $content_type ) {
				if ( null === $result[ $component_id ]['alt'] ) {
					$result[ $component_id ]['alt'] = $value;
				}
				continue;
			}

			if ( null === $result[ $component_id ]['text'] ) {
				$result[ $component_id ]['text'] = $value;
			}
		}

		return $result;
	}

	/**
	 * Normalize asset references per component.
	 *
	 * @param mixed                $assets     Raw assets.
	 * @param array<string, array> $components Normalized components.
	 * @param array<int, string>   $warnings   Warning collector.
	 * @return array<string, array<string, mixed>>
	 */
	private function plan_assets( $assets, array $components, array &$warnings ) {
		$result = array();
		$seen   = 0;
		foreach ( is_array( $assets ) ? $assets : array() as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['source_id'] ) || ! is_string( $asset['source_id'] ) ) {
				continue;
			}
			$component_id = $this->safe_id( $asset['source_id'] );
			if ( ! isset( $components[ $component_id ] ) ) {
				$warnings[] = __( 'An asset referenced a component that is not present in the specification and was ignored.', 'replicaforge' );
				continue;
			}
			if ( $seen >= Elementor_Limits::MAX_ASSETS ) {
				$warnings[] = __( 'Some assets were not processed because the Phase 4 asset limit was reached.', 'replicaforge' );
				break;
			}
			$seen++;
			$url = $this->safe_target( isset( $asset['source_url'] ) ? $asset['source_url'] : null );
			$result[ $component_id ] = array(
				'component_id' => $component_id,
				'type'         => $this->safe_token( isset( $asset['type'] ) ? $asset['type'] : 'image', 40 ),
				'role'         => $this->safe_token( isset( $asset['role'] ) ? $asset['role'] : 'content_image', 80 ),
				'usage'        => $this->safe_token( isset( $asset['usage'] ) ? $asset['usage'] : 'content_image', 80 ),
				'source_url'   => is_string( $url ) && '' !== $url ? $url : '',
				'confidence'   => $this->confidence( isset( $asset['confidence'] ) ? $asset['confidence'] : 0 ),
			);
		}

		return $result;
	}

	/**
	 * Normalize the design system into role-keyed tokens.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @param array<int, string>   $warnings      Warning collector.
	 * @return array<string, mixed>
	 */
	private function plan_design( array $specification, array &$warnings ) {
		$system = isset( $specification['design_system'] ) && is_array( $specification['design_system'] ) ? $specification['design_system'] : array();

		$colors = array();
		foreach ( isset( $system['colors'] ) && is_array( $system['colors'] ) ? $system['colors'] : array() as $record ) {
			if ( ! is_array( $record ) || ! isset( $record['role'] ) || ! isset( $record['value'] ) ) {
				continue;
			}
			$role = $this->safe_token( $record['role'], 40 );
			$value = Elementor_Values::is_safe_color( $record['value'] ) ? $record['value'] : null;
			if ( '' === $role || null === $value ) {
				continue;
			}
			if ( ! isset( $colors[ $role ] ) ) {
				$colors[ $role ] = array(
					'value'      => $value,
					'confidence' => $this->confidence( isset( $record['confidence'] ) ? $record['confidence'] : 0 ),
				);
			}
		}

		$typography = array();
		foreach ( isset( $system['typography'] ) && is_array( $system['typography'] ) ? $system['typography'] : array() as $record ) {
			if ( ! is_array( $record ) || ! isset( $record['role'] ) ) {
				continue;
			}
			$role = $this->safe_token( $record['role'], 40 );
			if ( '' === $role ) {
				continue;
			}
			$token = array();
			if ( isset( $record['font_family'] ) && Elementor_Values::is_safe_font_family( $record['font_family'] ) ) {
				$token['font_family'] = (string) $record['font_family'];
			}
			if ( isset( $record['font_size'] ) && Elementor_Values::is_safe_length( $record['font_size'] ) ) {
				$token['font_size'] = (string) $record['font_size'];
			}
			if ( isset( $record['font_weight'] ) && Elementor_Values::is_safe_font_weight( $record['font_weight'] ) ) {
				$token['font_weight'] = $record['font_weight'];
			}
			if ( isset( $record['line_height'] ) && Elementor_Values::is_safe_line_height( $record['line_height'] ) ) {
				$token['line_height'] = $record['line_height'];
			}
			if ( ! empty( $token ) ) {
				$token['confidence']   = $this->confidence( isset( $record['confidence'] ) ? $record['confidence'] : 0 );
				$typography[ $role ] = $token;
			}
		}

		$radius = array();
		foreach ( isset( $system['radius']['scale'] ) && is_array( $system['radius']['scale'] ) ? $system['radius']['scale'] : array() as $value ) {
			if ( is_string( $value ) && Elementor_Values::is_safe_length( $value ) ) {
				$radius[] = $value;
			}
			if ( count( $radius ) >= 12 ) {
				break;
			}
		}

		$shadows = array();
		foreach ( isset( $system['shadows'] ) && is_array( $system['shadows'] ) ? $system['shadows'] : array() as $value ) {
			$shadow = $this->plan_shadow( $value );
			if ( null !== $shadow ) {
				$shadows[] = $shadow;
			}
			if ( count( $shadows ) >= 8 ) {
				break;
			}
		}
		if ( empty( $shadows ) ) {
			$warnings[] = __( 'No safe box-shadow tokens were detected, so shadows were not reproduced.', 'replicaforge' );
		}

		return array(
			'colors'     => $colors,
			'typography' => $typography,
			'radius'     => $radius,
			'shadows'    => $shadows,
		);
	}

	/**
	 * Convert one detected shadow into Elementor box-shadow fields.
	 *
	 * @param mixed $value Detected shadow value.
	 * @return array<string, mixed>|null
	 */
	private function plan_shadow( $value ) {
		$raw = '';
		if ( is_string( $value ) ) {
			$raw = $value;
		} elseif ( is_array( $value ) && isset( $value['value'] ) && is_string( $value['value'] ) ) {
			$raw = $value['value'];
		}
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 200 ) {
			return null;
		}
		if ( preg_match( '/[;{}()<>]|expression|url\s*\(|@import|javascript:/i', $raw ) ) {
			return null;
		}
		if ( ! preg_match( '/^(?:inset\s+)?(-?[0-9.]+(?:px|rem|em)?)(?:\s+(-?[0-9.]+(?:px|rem|em)?))?(?:\s+(-?[0-9.]+(?:px|rem|em)?))?(?:\s+(-?[0-9.]+(?:px|rem|em)?))?\s+(.+)$/i', $raw, $matches ) ) {
			return null;
		}
		$color = trim( $matches[5] );
		if ( ! Elementor_Values::is_safe_color( $color ) ) {
			return null;
		}
		$length = static function ( $input ) {
			return is_string( $input ) && '' !== $input ? $input : '0px';
		};
		return array(
			'type'     => 0 === stripos( $raw, 'inset' ) ? 'inset' : 'frame',
			'x_offset' => $length( $matches[1] ),
			'y_offset' => $length( $matches[2] ),
			'blur'     => $length( $matches[3] ),
			'spread'   => $length( $matches[4] ),
			'color'    => $color,
		);
	}

	/**
	 * Normalize the responsive strategy.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @return array<string, mixed>
	 */
	private function plan_responsive( array $specification ) {
		$responsive = isset( $specification['responsive_strategy'] ) && is_array( $specification['responsive_strategy'] ) ? $specification['responsive_strategy'] : array();
		$viewport   = array();
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			$record         = isset( $responsive[ $device ] ) && is_array( $responsive[ $device ] ) ? $responsive[ $device ] : array();
			$viewport[ $device ] = array(
				'layout'     => $this->safe_token( isset( $record['layout'] ) ? $record['layout'] : 'unknown', 80 ),
				'responsive' => isset( $record['responsive'] ) && is_array( $record['responsive'] ) ? $record['responsive'] : array(),
			);
		}

		$sections = array();
		foreach ( isset( $responsive['section_strategies'] ) && is_array( $responsive['section_strategies'] ) ? $responsive['section_strategies'] : array() as $section_id => $record ) {
			if ( ! is_string( $section_id ) ) {
				continue;
			}
			$id = $this->safe_id( $section_id );
			if ( '' === $id || ! is_array( $record ) ) {
				continue;
			}
			$entry = array();
			foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
				$device_record    = isset( $record[ $device ] ) && is_array( $record[ $device ] ) ? $record[ $device ] : array();
				$entry[ $device ] = $this->safe_token( isset( $device_record['layout'] ) ? $device_record['layout'] : 'unknown', 80 );
			}
			$sections[ $id ] = $entry;
		}

		// A section-level record produced by the specification is preferred over
		// the global viewport summary.
		foreach ( isset( $specification['sections'] ) && is_array( $specification['sections'] ) ? $specification['sections'] : array() as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['source_id'] ) || ! isset( $section['responsive'] ) || ! is_array( $section['responsive'] ) ) {
				continue;
			}
			$id = $this->safe_id( $section['source_id'] );
			if ( '' === $id ) {
				continue;
			}
			$entry = isset( $sections[ $id ] ) ? $sections[ $id ] : array();
			foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
				$device_record = isset( $section['responsive'][ $device ] ) && is_array( $section['responsive'][ $device ] ) ? $section['responsive'][ $device ] : array();
				$candidate     = $this->safe_token( isset( $device_record['layout'] ) ? $device_record['layout'] : 'unknown', 80 );
				if ( 'unknown' !== $candidate && ! isset( $entry[ $device ] ) ) {
					$entry[ $device ] = $candidate;
				}
			}
			$sections[ $id ] = $entry;
		}

		$evidence = array();
		foreach ( isset( $responsive['evidence'] ) && is_array( $responsive['evidence'] ) ? $responsive['evidence'] : array() as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$viewport_name = $this->safe_token( isset( $record['viewport'] ) ? $record['viewport'] : 'unknown', 20 );
			$evidence[]     = array(
				'viewport'    => $viewport_name,
				'breakpoint'  => $this->safe_text( isset( $record['breakpoint'] ) ? $record['breakpoint'] : null, 40 ),
				'changes'     => $this->string_list( isset( $record['changes'] ) ? $record['changes'] : array(), 12, 60 ),
				'confidence'  => $this->confidence( isset( $record['confidence'] ) ? $record['confidence'] : 0 ),
			);
			if ( count( $evidence ) >= 40 ) {
				break;
			}
		}

		return array(
			'desktop'           => $viewport['desktop'],
			'tablet'            => $viewport['tablet'],
			'mobile'            => $viewport['mobile'],
			'sections'          => $sections,
			'evidence'          => $evidence,
			'confidence'        => $this->confidence( isset( $responsive['confidence'] ) ? $responsive['confidence'] : 0 ),
		);
	}

	/**
	 * Normalize the confidence block.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @return array<string, float>
	 */
	private function plan_confidence( array $specification ) {
		$confidence = isset( $specification['confidence'] ) && is_array( $specification['confidence'] ) ? $specification['confidence'] : array();
		$result     = array();
		foreach ( array( 'overall', 'sections', 'components', 'design_system', 'responsive' ) as $key ) {
			$result[ $key ] = $this->confidence( isset( $confidence[ $key ] ) ? $confidence[ $key ] : 0 );
		}
		return $result;
	}

	/**
	 * Recursively reject specification keys that belong to a page builder.
	 *
	 * @param mixed            $value  Value to inspect.
	 * @param array<int,string> $errors Error collector.
	 * @param int              $depth  Current depth.
	 * @return void
	 */
	private function scan_forbidden_keys( $value, array &$errors, $depth ) {
		if ( $depth > 8 || ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $key => $child ) {
			if ( is_string( $key ) ) {
				$normalized = strtolower( $key );
				if ( in_array( $normalized, $this->forbidden_keys, true ) ) {
					$errors[] = 'builder_specific_key_rejected';
					return;
				}
			}
			$this->scan_forbidden_keys( $child, $errors, $depth + 1 );
		}
	}

	/**
	 * Return a safe identifier.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function safe_id( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_\-]/', '', $value );
		return is_string( $value ) ? substr( $value, 0, 120 ) : '';
	}

	/**
	 * Return a safe short token.
	 *
	 * @param mixed $value      Raw value.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	private function safe_token( $value, $max_length = 80 ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_\-]/', '', $value );
		return is_string( $value ) ? substr( $value, 0, $max_length ) : '';
	}

	/**
	 * Return safe, bounded, non-executable text.
	 *
	 * @param mixed $value      Raw value.
	 * @param int   $max_length Maximum length.
	 * @return string|null
	 */
	private function safe_text( $value, $max_length = Elementor_Limits::MAX_TEXT_LENGTH ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return null;
		}
		if ( Elementor_Values::is_executable( $value ) ) {
			return null;
		}
		$value = Security::clean_text( $value, $max_length );
		return '' === $value ? null : $value;
	}

	/**
	 * Return a safe public target URL or an empty string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function safe_target( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 2048 ) {
			return '';
		}
		if ( ! Security::is_safe_public_reference( $value ) ) {
			return '';
		}
		$normalized = Security::normalize_http_url( $value );
		return is_string( $normalized ) ? $normalized : '';
	}

	/**
	 * Return a safe source tag.
	 *
	 * @param mixed $source Source trace.
	 * @return string
	 */
	private function source_tag( $source ) {
		$tag = is_array( $source ) && isset( $source['tag'] ) && is_string( $source['tag'] ) ? strtolower( trim( $source['tag'] ) ) : '';
		return preg_match( '/^[a-z][a-z0-9-]{0,20}$/', $tag ) ? $tag : '';
	}

	/**
	 * Return a confidence value inside the inclusive 0..1 range.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private function confidence( $value ) {
		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}
		$value = (float) $value;
		if ( $value < 0 ) {
			return 0.0;
		}
		return $value > 1 ? 1.0 : round( $value, 4 );
	}

	/**
	 * Return a bounded list of safe strings.
	 *
	 * @param mixed $values     Raw values.
	 * @param int   $limit      Maximum items.
	 * @param int   $max_length Maximum length per item.
	 * @return array<int, string>
	 */
	private function string_list( $values, $limit, $max_length = 120 ) {
		$result = array();
		foreach ( is_array( $values ) ? array_slice( $values, 0, $limit ) : array() as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$result[] = $this->safe_token( $value, $max_length );
			}
		}
		return array_values( array_filter( $result ) );
	}

	/**
	 * Build the validation result envelope.
	 *
	 * @param bool               $valid    Whether the specification is usable.
	 * @param array<int, string> $errors   Error codes.
	 * @param array<int, string> $warnings Warning messages.
	 * @param array<string, mixed> $plan   Normalized plan.
	 * @param string             $message  Optional user-facing message.
	 * @return array<string, mixed>
	 */
	private function result( $valid, array $errors, array $warnings, array $plan, $message = '' ) {
		return array(
			'valid'    => (bool) $valid,
			'errors'   => array_values( array_unique( $errors ) ),
			'warnings' => array_values( array_unique( array_filter( $warnings ) ) ),
			'plan'     => $plan,
			'message'  => (string) $message,
		);
	}
}
