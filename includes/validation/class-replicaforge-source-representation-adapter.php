<?php
/**
 * Source-side normalization for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a Phase 2 Design Representation 2.0 onto the comparison schema.
 *
 * The source side is static evidence. Where a value was not detected it stays
 * `null` and is reported as unknown evidence, never as a difference and never as
 * a match.
 */
final class Source_Representation_Adapter {

	/**
	 * Component types that behave like a card in the comparison.
	 *
	 * @var array<int, string>
	 */
	private $card_types = array(
		'card',
		'product_card',
		'feature_card',
		'portfolio_card',
		'blog_card',
		'team_card',
		'pricing_card',
		'testimonial_card',
		'card_group',
	);

	/**
	 * Build the normalized source side.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @return array<string, mixed>
	 */
	public function build( array $representation ) {
		$side = Comparison_Schema::empty_side( 'source' );

		$page    = isset( $representation['page'] ) && is_array( $representation['page'] ) ? $representation['page'] : array();
		$layout  = isset( $representation['layout'] ) && is_array( $representation['layout'] ) ? $representation['layout'] : array();
		$system  = isset( $representation['design_system'] ) && is_array( $representation['design_system'] ) ? $representation['design_system'] : array();
		$page_layout = isset( $layout['page'] ) && is_array( $layout['page'] ) ? $layout['page'] : array();
		$container  = isset( $page_layout['container'] ) && is_array( $page_layout['container'] ) ? $page_layout['container'] : array();

		$side['page'] = array(
			'url'                  => $this->safe_url( isset( $page['final_url'] ) ? $page['final_url'] : ( isset( $page['url'] ) ? $page['url'] : '' ) ),
			'title'                => $this->text( isset( $page['title'] ) ? $page['title'] : null, 200 ),
			'type'                 => $this->token( isset( $page['type'] ) ? $page['type'] : 'unknown', 60 ),
			'container_max_width'  => Comparison_Schema::length( isset( $container['max_width'] ) ? $container['max_width'] : null ),
			'container_centered'   => isset( $container['centered'] ) ? (bool) $container['centered'] : null,
		);

		$side['design_system'] = $this->design_system( $system );
		$side['viewports']     = $this->viewports( isset( $representation['responsive'] ) && is_array( $representation['responsive'] ) ? $representation['responsive'] : array() );
		$side['navigation']    = $this->navigation( isset( $representation['responsive']['mobile_navigation'] ) && is_array( $representation['responsive']['mobile_navigation'] ) ? $representation['responsive']['mobile_navigation'] : array() );

		$components = $this->components( isset( $representation['components'] ) && is_array( $representation['components'] ) ? $representation['components'] : array(), $side['design_system'] );
		$side['components'] = $components;

		$side['sections'] = $this->sections(
			isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array(),
			$components
		);

		$side['counters'] = $this->counters( $side );

		return $side;
	}

	/**
	 * Normalize the detected design system.
	 *
	 * @param array<string, mixed> $system Phase 2 design system.
	 * @return array<string, mixed>
	 */
	private function design_system( array $system ) {
		$colors     = array();
		$by_role    = isset( $system['colors']['by_role'] ) && is_array( $system['colors']['by_role'] ) ? $system['colors']['by_role'] : array();
		$confidence = array();
		foreach ( $by_role as $role => $record ) {
			if ( ! is_string( $role ) || ! is_array( $record ) || ! isset( $record['value'] ) ) {
				continue;
			}
			$color = Comparison_Schema::color( $record['value'] );
			if ( null === $color ) {
				continue;
			}
			$role_key               = $this->token( $role, 40 );
			$colors[ $role_key ]    = $color;
			$confidence[ $role_key ] = isset( $record['confidence'] ) ? $this->confidence( $record['confidence'] ) : 0.0;
		}

		$typography = array();
		$hierarchy  = isset( $system['typography']['hierarchy'] ) && is_array( $system['typography']['hierarchy'] ) ? $system['typography']['hierarchy'] : array();
		foreach ( $hierarchy as $role => $record ) {
			if ( ! is_string( $role ) || ! is_array( $record ) ) {
				continue;
			}
			$role_key = $this->token( $role, 40 );
			$typography[ $role_key ] = array(
				'font_family'     => Comparison_Schema::font_family( isset( $record['font_family'] ) ? $record['font_family'] : null ),
				'font_size'       => Comparison_Schema::font_size( isset( $record['font_size'] ) ? $record['font_size'] : null ),
				'font_weight'     => Comparison_Schema::font_weight( isset( $record['font_weight'] ) ? $record['font_weight'] : null ),
				'line_height'     => Comparison_Schema::line_height( isset( $record['line_height'] ) ? $record['line_height'] : null ),
				'letter_spacing'  => Comparison_Schema::length( isset( $record['letter_spacing'] ) ? $record['letter_spacing'] : null ),
				'text_transform'  => $this->token( isset( $record['text_transform'] ) ? $record['text_transform'] : '', 20 ),
				'color'           => Comparison_Schema::color( isset( $record['color'] ) ? $record['color'] : null ),
				'confidence'      => isset( $record['confidence'] ) ? $this->confidence( $record['confidence'] ) : 0.0,
			);
		}

		$spacing = array();
		foreach ( isset( $system['spacing']['scale'] ) && is_array( $system['spacing']['scale'] ) ? $system['spacing']['scale'] : array() as $value ) {
			$length = Comparison_Schema::length( $value );
			if ( null !== $length ) {
				$spacing[] = $length;
			}
			if ( count( $spacing ) >= 24 ) {
				break;
			}
		}

		$radius = array();
		foreach ( isset( $system['radius']['scale'] ) && is_array( $system['radius']['scale'] ) ? $system['radius']['scale'] : array() as $value ) {
			$length = Comparison_Schema::length( $value );
			if ( null !== $length ) {
				$radius[] = $length;
			}
			if ( count( $radius ) >= 16 ) {
				break;
			}
		}

		$shadows = array();
		foreach ( isset( $system['shadows'] ) && is_array( $system['shadows'] ) ? $system['shadows'] : array() as $value ) {
			$shadow = $this->shadow( is_array( $value ) && isset( $value['value'] ) ? $value['value'] : $value );
			if ( null !== $shadow ) {
				$shadows[] = $shadow;
			}
			if ( count( $shadows ) >= 10 ) {
				break;
			}
		}

		return array(
			'colors'            => $colors,
			'color_confidence'  => $confidence,
			'typography'        => $typography,
			'spacing'           => $spacing,
			'radius'            => $radius,
			'shadows'           => $shadows,
		);
	}

	/**
	 * Normalize a detected box-shadow value.
	 *
	 * @param mixed $value Raw shadow.
	 * @return array<string, mixed>|null
	 */
	private function shadow( $value ) {
		$raw = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $raw || strlen( $raw ) > 200 ) {
			return null;
		}
		if ( preg_match( '/[;{}()<>]|expression|url\s*\(|@import|javascript:/i', $raw ) ) {
			return null;
		}
		if ( ! preg_match( '/^(inset\s+)?(-?[0-9.]+(?:px|rem|em)?)(?:\s+(-?[0-9.]+(?:px|rem|em)?))?(?:\s+(-?[0-9.]+(?:px|rem|em)?))?(?:\s+(-?[0-9.]+(?:px|rem|em)?))?\s+(.+)$/i', $raw, $matches ) ) {
			return null;
		}
		$color = Comparison_Schema::color( trim( $matches[5] ) );
		if ( null === $color ) {
			return null;
		}
		$length = static function ( $input ) {
			return Comparison_Schema::length( $input );
		};
		return array(
			'inset'  => 0 === stripos( $raw, 'inset' ),
			'x'      => $length( $matches[2] ),
			'y'      => $length( $matches[3] ),
			'blur'   => $length( $matches[4] ),
			'spread' => $length( $matches[5] ),
			'color'  => $color,
			'raw'    => $raw,
		);
	}

	/**
	 * Normalize the responsive evidence into per-viewport records.
	 *
	 * @param array<string, mixed> $responsive Phase 2 responsive block.
	 * @return array<string, mixed>
	 */
	private function viewports( array $responsive ) {
		$viewports = array();
		foreach ( array_keys( Validation_Limits::VIEWPORTS ) as $device ) {
			$viewports[ $device ] = array(
				'evidence'        => false,
				'layout_changes'  => array(),
				'breakpoints'     => array(),
				'font_size_hints' => array(),
				'spacing_hints'   => array(),
				'visibility'      => array(),
				'column_count'    => null,
			);
		}

		$rules = isset( $responsive['rules'] ) && is_array( $responsive['rules'] ) ? $responsive['rules'] : array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$breakpoint = isset( $rule['breakpoint'] ) ? (string) $rule['breakpoint'] : '';
			$device     = $this->device_for_breakpoint( $breakpoint );
			if ( '' === $device || ! isset( $viewports[ $device ] ) ) {
				continue;
			}
			$changes = isset( $rule['changes'] ) && is_array( $rule['changes'] ) ? array_map( array( $this, 'token' ), $rule['changes'] ) : array();
			$changes = array_values( array_filter( $changes ) );

			$viewports[ $device ]['evidence']       = true;
			$viewports[ $device ]['breakpoints'][]  = $breakpoint;
			$viewports[ $device ]['layout_changes'] = array_values( array_unique( array_merge( $viewports[ $device ]['layout_changes'], $changes ) ) );

			foreach ( $changes as $change ) {
				if ( false !== strpos( $change, 'font_size' ) || false !== strpos( $change, 'typography' ) ) {
					$viewports[ $device ]['font_size_hints'][] = $change;
				}
				if ( false !== strpos( $change, 'spacing' ) || false !== strpos( $change, 'padding' ) || false !== strpos( $change, 'margin' ) ) {
					$viewports[ $device ]['spacing_hints'][] = $change;
				}
				if ( false !== strpos( $change, 'visibility' ) || false !== strpos( $change, 'display' ) || false !== strpos( $change, 'hidden' ) ) {
					$viewports[ $device ]['visibility'][] = $change;
				}
			}
		}

		$viewports['desktop']['evidence'] = true;
		if ( isset( $responsive['breakpoints'] ) && is_array( $responsive['breakpoints'] ) ) {
			$viewports['desktop']['breakpoints'] = array_values( array_unique( array_map( array( $this, 'token' ), $responsive['breakpoints'] ) ) );
		}

		return $viewports;
	}

	/**
	 * Map a media-query breakpoint to a comparison viewport.
	 *
	 * @param string $breakpoint Breakpoint value such as `768px`.
	 * @return string
	 */
	private function device_for_breakpoint( $breakpoint ) {
		$value = (float) preg_replace( '/[^0-9.]/', '', (string) $breakpoint );
		if ( $value <= 0 ) {
			return '';
		}
		if ( $value <= 767 ) {
			return 'mobile';
		}
		if ( $value <= 1024 ) {
			return 'tablet';
		}
		return '';
	}

	/**
	 * Normalize the mobile navigation evidence.
	 *
	 * @param array<string, mixed> $navigation Phase 2 mobile navigation block.
	 * @return array<string, mixed>
	 */
	private function navigation( array $navigation ) {
		return array(
			'mobile_detected' => isset( $navigation['detected'] ) ? (bool) $navigation['detected'] : null,
			'behavior'        => $this->token( isset( $navigation['behavior'] ) ? $navigation['behavior'] : 'unknown', 40 ),
			'confidence'      => isset( $navigation['confidence'] ) ? $this->confidence( $navigation['confidence'] ) : 0.0,
		);
	}

	/**
	 * Normalize detected components.
	 *
	 * @param array<int, mixed>  $components Phase 2 components.
	 * @param array<string, mixed> $design    Normalized design system.
	 * @return array<string, array<string, mixed>>
	 */
	private function components( $components, array $design ) {
		$result = array();
		$order  = 0;
		foreach ( array_slice( is_array( $components ) ? $components : array(), 0, Validation_Limits::MAX_COMPONENTS ) as $component ) {
			if ( ! is_array( $component ) || ! isset( $component['id'] ) ) {
				continue;
			}
			$order++;
			$id   = $this->id( $component['id'] );
			$type = $this->token( isset( $component['type'] ) ? $component['type'] : 'unknown', 60 );
			$tag  = $this->tag( isset( $component['source'] ) ? $component['source'] : array() );
			$role = $this->token( isset( $component['role'] ) ? $component['role'] : '', 80 );
			$typography_role = $this->typography_role( $type, $tag, $role );

			$result[ $id ] = array(
				'key'            => $id,
				'order'          => $order,
				'type'           => $type,
				'role'           => $role,
				'tag'            => $tag,
				'section_key'    => isset( $component['section_id'] ) ? $this->id( $component['section_id'] ) : '',
				'is_card'        => in_array( $type, $this->card_types, true ),
				'text'           => $this->text( isset( $component['text'] ) ? $component['text'] : null, 900 ),
				'link'           => $this->safe_url( isset( $component['url'] ) ? $component['url'] : null ),
				'fields'         => $this->fields( isset( $component['fields'] ) ? $component['fields'] : array() ),
				'image'          => $this->image( isset( $component['image'] ) ? $component['image'] : array() ),
				'typography'     => $this->component_typography( $typography_role, $design ),
				'confidence'     => isset( $component['confidence'] ) ? $this->confidence( $component['confidence'] ) : 0.0,
				'children'       => $this->id_list( isset( $component['children'] ) ? $component['children'] : array() ),
			);
		}
		return $result;
	}

	/**
	 * Normalize detected sections.
	 *
	 * @param array<int, mixed>                $sections   Phase 2 sections.
	 * @param array<string, array<string, mixed>> $components Normalized components.
	 * @return array<int, array<string, mixed>>
	 */
	private function sections( $sections, array $components ) {
		$result = array();
		$order  = 0;
		foreach ( array_slice( is_array( $sections ) ? $sections : array(), 0, Validation_Limits::MAX_SECTIONS ) as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['id'] ) ) {
				continue;
			}
			$order++;
			$id    = $this->id( $section['id'] );
			$layout = isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : array();

			$members = array();
			foreach ( isset( $section['components'] ) && is_array( $section['components'] ) ? $section['components'] : array() as $member ) {
				$member_id = $this->id( $member );
				if ( '' !== $member_id && isset( $components[ $member_id ] ) ) {
					$members[] = $member_id;
				}
			}

			$result[] = array(
				'key'           => $id,
				'order'         => isset( $section['order'] ) && is_numeric( $section['order'] ) ? (int) $section['order'] : $order,
				'type'          => $this->token( isset( $section['type'] ) ? $section['type'] : 'unknown', 60 ),
				'column_count'  => $this->column_count( $layout ),
				'column_ratios' => $this->column_ratios( $layout ),
				'direction'     => $this->direction( $layout ),
				'gap'           => Comparison_Schema::length( isset( $layout['gap'] ) ? $layout['gap'] : null ),
				'wrap'          => $this->token( isset( $layout['wrap'] ) ? $layout['wrap'] : '', 20 ),
				'align'         => $this->alignment( $layout ),
				'max_width'     => isset( $layout['container']['max_width'] ) ? Comparison_Schema::length( $layout['container']['max_width'] ) : null,
				'members'       => $members,
				'heading'       => $this->text( isset( $section['content']['heading'] ) ? $section['content']['heading'] : null, 300 ),
				'tag'           => $this->tag( isset( $section['source'] ) ? $section['source'] : array() ),
				'confidence'    => isset( $section['confidence'] ) ? $this->confidence( $section['confidence'] ) : 0.0,
			);
		}

		usort(
			$result,
			static function ( $left, $right ) {
				if ( $left['order'] === $right['order'] ) {
					return 0;
				}
				return $left['order'] < $right['order'] ? -1 : 1;
			}
		);

		return $result;
	}

	/**
	 * Return a normalized field map.
	 *
	 * @param mixed $fields Raw fields.
	 * @return array<string, mixed>
	 */
	private function fields( $fields ) {
		$result = array();
		foreach ( is_array( $fields ) ? $fields : array() as $key => $value ) {
			$name = $this->token( $key, 40 );
			if ( '' === $name ) {
				continue;
			}
			if ( in_array( $name, array( 'image', 'link', 'url' ), true ) ) {
				$result[ $name ] = $this->safe_url( $value );
				continue;
			}
			$result[ $name ] = is_scalar( $value ) ? $this->text( $value, 200 ) : null;
		}
		return $result;
	}

	/**
	 * Return normalized image metadata.
	 *
	 * @param mixed $image Raw image record.
	 * @return array<string, mixed>
	 */
	private function image( $image ) {
		$image = is_array( $image ) ? $image : array();
		return array(
			'src'     => $this->safe_url( isset( $image['src'] ) ? $image['src'] : null ),
			'alt'     => $this->text( isset( $image['alt'] ) ? $image['alt'] : null, 200 ),
			'width'   => isset( $image['width'] ) && is_numeric( $image['width'] ) ? (int) $image['width'] : null,
			'height'  => isset( $image['height'] ) && is_numeric( $image['height'] ) ? (int) $image['height'] : null,
			'present' => '' !== $this->safe_url( isset( $image['src'] ) ? $image['src'] : null ),
		);
	}

	/**
	 * Return the typography record for a component.
	 *
	 * @param string               $role   Typography role key.
	 * @param array<string, mixed> $design Normalized design system.
	 * @return array<string, mixed>
	 */
	private function component_typography( $role, array $design ) {
		$empty = array(
			'role'           => $role,
			'font_family'    => null,
			'font_size'      => null,
			'font_weight'    => null,
			'line_height'    => null,
			'letter_spacing' => null,
			'text_transform' => '',
			'color'          => null,
		);
		if ( '' === $role || ! isset( $design['typography'][ $role ] ) ) {
			return $empty;
		}
		$token = $design['typography'][ $role ];
		return array(
			'role'           => $role,
			'font_family'    => isset( $token['font_family'] ) ? $token['font_family'] : null,
			'font_size'      => isset( $token['font_size'] ) ? $token['font_size'] : null,
			'font_weight'    => isset( $token['font_weight'] ) ? $token['font_weight'] : null,
			'line_height'    => isset( $token['line_height'] ) ? $token['line_height'] : null,
			'letter_spacing' => isset( $token['letter_spacing'] ) ? $token['letter_spacing'] : null,
			'text_transform' => isset( $token['text_transform'] ) ? $token['text_transform'] : '',
			'color'          => isset( $token['color'] ) ? $token['color'] : null,
		);
	}

	/**
	 * Resolve the typography role of a component.
	 *
	 * @param string $type Component type.
	 * @param string $tag  Source tag.
	 * @param string $role Component role.
	 * @return string
	 */
	private function typography_role( $type, $tag, $role ) {
		$level = array();
		if ( 1 === preg_match( '/^h([1-6])$/', (string) $tag, $level ) ) {
			return 'heading_' . $level[1];
		}
		if ( 'heading' === $type ) {
			if ( false !== strpos( $role, 'primary' ) || false !== strpos( $role, 'page' ) ) {
				return 'heading_1';
			}
			if ( false !== strpos( $role, 'section' ) ) {
				return 'heading_2';
			}
			return 'heading_3';
		}
		if ( in_array( $type, array( 'paragraph', 'list', 'label', 'card', 'product_card', 'feature_card', 'blog_card', 'portfolio_card', 'testimonial_card', 'pricing_card' ), true ) ) {
			return 'body';
		}
		return '';
	}

	/**
	 * Return a normalized column count.
	 *
	 * @param array<string, mixed> $layout Section layout.
	 * @return int
	 */
	private function column_count( array $layout ) {
		$count = isset( $layout['column_count'] ) && is_numeric( $layout['column_count'] ) ? (int) $layout['column_count'] : 0;
		if ( $count < 1 ) {
			$type = isset( $layout['type'] ) ? strtolower( (string) $layout['type'] ) : '';
			if ( in_array( $type, array( 'two_column', 'sidebar' ), true ) ) {
				$count = 2;
			} elseif ( false !== strpos( $type, 'column' ) && false !== strpos( $type, 'grid' ) ) {
				$count = 3;
			} else {
				$count = 1;
			}
		}
		return max( 1, min( 6, $count ) );
	}

	/**
	 * Return normalized column width ratios.
	 *
	 * @param array<string, mixed> $layout Section layout.
	 * @return array<int, float>
	 */
	private function column_ratios( array $layout ) {
		$ratios = array();
		foreach ( isset( $layout['columns'] ) && is_array( $layout['columns'] ) ? array_slice( $layout['columns'], 0, 6 ) : array() as $column ) {
			$ratio = null;
			if ( is_array( $column ) && isset( $column['width_ratio'] ) && is_numeric( $column['width_ratio'] ) ) {
				$ratio = (float) $column['width_ratio'];
			} elseif ( is_numeric( $column ) ) {
				$ratio = (float) $column;
			}
			if ( null !== $ratio && $ratio > 0 && $ratio <= 1 ) {
				$ratios[] = round( $ratio, 4 );
			}
		}
		return count( $ratios ) === $this->column_count( $layout ) ? $ratios : array();
	}

	/**
	 * Return a normalized flex direction.
	 *
	 * @param array<string, mixed> $layout Section layout.
	 * @return string
	 */
	private function direction( array $layout ) {
		$direction = isset( $layout['direction'] ) ? strtolower( (string) $layout['direction'] ) : '';
		if ( in_array( $direction, array( 'row', 'column', 'row-reverse', 'column-reverse' ), true ) ) {
			return $direction;
		}
		return $this->column_count( $layout ) > 1 ? 'row' : 'column';
	}

	/**
	 * Return normalized alignment values.
	 *
	 * @param array<string, mixed> $layout Section layout.
	 * @return array<string, string>
	 */
	private function alignment( array $layout ) {
		$alignment = isset( $layout['alignment'] ) ? $layout['alignment'] : array();
		$justify   = '';
		$align     = '';
		if ( is_array( $alignment ) ) {
			$justify = isset( $alignment['horizontal'] ) ? $this->token( $alignment['horizontal'], 30 ) : '';
			$align   = isset( $alignment['vertical'] ) ? $this->token( $alignment['vertical'], 30 ) : '';
		} elseif ( is_string( $alignment ) ) {
			$justify = $this->token( $alignment, 30 );
		}
		return array(
			'justify'  => $justify,
			'align'    => $align,
		);
	}

	/**
	 * Build comparison counters for the source side.
	 *
	 * @param array<string, mixed> $side Normalized side.
	 * @return array<string, mixed>
	 */
	private function counters( array $side ) {
		$types = array();
		foreach ( $side['components'] as $component ) {
			$type = (string) $component['type'];
			$types[ $type ] = isset( $types[ $type ] ) ? $types[ $type ] + 1 : 1;
		}
		$images = 0;
		foreach ( $side['components'] as $component ) {
			if ( ! empty( $component['image']['present'] ) ) {
				$images++;
			}
		}
		return array(
			'sections'          => count( $side['sections'] ),
			'components'        => count( $side['components'] ),
			'component_types'   => $types,
			'images'            => $images,
			'links'             => count( $this->collect( $side['components'], 'link' ) ),
			'cards'             => count( $this->collect( $side['components'], 'is_card' ) ),
		);
	}

	/**
	 * Count truthy component fields.
	 *
	 * @param array<string, array<string, mixed>> $components Normalized components.
	 * @param string                             $field      Field key.
	 * @return array<int, string>
	 */
	private function collect( array $components, $field ) {
		$matches = array();
		foreach ( $components as $component ) {
			$value = $component[ $field ] ?? null;
			if ( true === $value || ( is_string( $value ) && '' !== $value ) ) {
				$matches[] = (string) $component['key'];
			}
		}
		return $matches;
	}

	/**
	 * Return a safe identifier.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function id( $value ) {
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
	private function token( $value, $max_length = 60 ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_\-]/', '', $value );
		return is_string( $value ) ? substr( $value, 0, $max_length ) : '';
	}

	/**
	 * Return safe, bounded text.
	 *
	 * @param mixed $value      Raw value.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	private function text( $value, $max_length = 300 ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		if ( '' === trim( $value ) || Elementor_Values::is_executable( $value ) ) {
			return '';
		}
		$value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_length, 'UTF-8' );
		}
		return substr( $value, 0, $max_length );
	}

	/**
	 * Return a safe public URL.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function safe_url( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		return Security::is_safe_public_reference( $value ) ? (string) Security::normalize_http_url( $value ) : '';
	}

	/**
	 * Return a confidence value in the inclusive 0..1 range.
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
	 * Return the source tag of a record.
	 *
	 * @param mixed $source Source trace.
	 * @return string
	 */
	private function tag( $source ) {
		$tag = is_array( $source ) && isset( $source['tag'] ) ? strtolower( trim( (string) $source['tag'] ) ) : '';
		return preg_match( '/^[a-z][a-z0-9-]{0,20}$/', $tag ) ? $tag : '';
	}

	/**
	 * Return a bounded list of identifiers.
	 *
	 * @param mixed $values Raw values.
	 * @return array<int, string>
	 */
	private function id_list( $values ) {
		$result = array();
		foreach ( is_array( $values ) ? array_slice( $values, 0, 200 ) : array() as $value ) {
			$id = $this->id( $value );
			if ( '' !== $id ) {
				$result[] = $id;
			}
		}
		return $result;
	}
}
