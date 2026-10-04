<?php
/**
 * Generated page analyzer for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Converts an Elementor draft into the normalized generated side.
 *
 * Two sources of truth are read. The element tree gives structure, hierarchy,
 * widget identity, and the intent recorded in settings. The stylesheet Elementor
 * generated for the document gives the effective values per device, including
 * tablet and mobile overrides, which is what a comparison against the source
 * must use rather than the intention.
 */
final class Generated_Page_Analyzer {

	/**
	 * Elementor widget names mapped onto comparison types.
	 *
	 * @var array<string, string>
	 */
	private $widget_types = array(
		'heading'     => 'heading',
		'text-editor' => 'paragraph',
		'button'      => 'button',
		'image'       => 'image',
		'divider'     => 'divider',
		'spacer'      => 'spacer',
		'icon'        => 'icon',
		'video'       => 'video',
		'menu'        => 'navigation',
	);

	/**
	 * Custom properties mapped onto comparison properties.
	 *
	 * Elementor containers express layout through custom properties. The map
	 * below is fixed and declared here so a comparison is reproducible.
	 *
	 * @var array<string, string>
	 */
	private $custom_properties = array(
		'--flex-direction'   => 'flex-direction',
		'--flex-wrap'        => 'flex-wrap',
		'--flex-justify-content' => 'justify-content',
		'--flex-align-items' => 'align-items',
		'--row-gap'          => 'row-gap',
		'--column-gap'       => 'column-gap',
		'--gap'              => 'gap',
		'--width'            => 'width',
		'--content-width'    => 'max-width',
		'--min-height'       => 'min-height',
		'--padding-top'      => 'padding-top',
		'--padding-right'    => 'padding-right',
		'--padding-bottom'   => 'padding-bottom',
		'--padding-left'     => 'padding-left',
		'--margin-top'       => 'margin-top',
		'--margin-right'     => 'margin-right',
		'--margin-bottom'    => 'margin-bottom',
		'--margin-left'      => 'margin-left',
		'--border-top-width' => 'border-width-top',
		'--border-right-width' => 'border-width-right',
		'--border-bottom-width' => 'border-width-bottom',
		'--border-left-width' => 'border-width-left',
		'--border-color'     => 'border-color',
		'--border-style'     => 'border-style',
		'--background-color' => 'background-color',
		'--overlay-color'    => 'overlay-color',
		'--display'          => 'display',
	);

	/**
	 * Standard declarations collected for every element.
	 *
	 * @var array<int, string>
	 */
	private $declarations = array(
		'font-family',
		'font-size',
		'font-weight',
		'font-style',
		'line-height',
		'letter-spacing',
		'text-transform',
		'text-align',
		'color',
		'background-color',
		'background-image',
		'border-radius',
		'border-top-left-radius',
		'box-shadow',
		'width',
		'max-width',
		'min-height',
		'height',
		'padding',
		'padding-top',
		'padding-right',
		'padding-bottom',
		'padding-left',
		'margin',
		'margin-top',
		'gap',
		'row-gap',
		'column-gap',
		'flex-direction',
		'flex-wrap',
		'justify-content',
		'align-items',
		'display',
		'object-fit',
		'object-position',
		'border-width',
		'border-color',
		'border-style',
		'opacity',
	);

	/**
	 * Viewport breakpoints read from the generated stylesheet, keyed by device.
	 *
	 * @var array<string, int>
	 */
	private $breakpoints = array(
		'tablet' => 1024,
		'mobile' => 767,
	);

	/**
	 * Build the normalized generated side for a draft.
	 *
	 * @param int $post_id Draft post ID.
	 * @return array<string, mixed>
	 */
	public function analyze( $post_id ) {
		$post_id = absint( $post_id );
		$side    = Comparison_Schema::empty_side( 'generated' );

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			$side['warnings'][] = __( 'The generated draft could not be read.', 'replicaforge' );
			return $side;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			$side['warnings'][] = __( 'The draft is not built with Elementor or has no document data.', 'replicaforge' );
			return $side;
		}

		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			$side['warnings'][] = __( 'The Elementor document could not be decoded.', 'replicaforge' );
			return $side;
		}

		$styles = $this->style_index( $post_id, $side['warnings'] );
		$id_map = $this->id_map( $post_id );

		$flat  = array();
		$order = 0;
		$this->walk( $elements, '', 1, $flat, $order, $id_map, $styles );

		$side['page'] = array(
			'url'                  => '',
			'title'                => (string) $post->post_title,
			// Phase 4 records no semantic page type, so the generated side
			// never claims one. Nothing in the comparison depends on it.
			'type'                 => 'unknown',
			'container_max_width'  => null,
			'container_centered'   => null,
		);

		$side['components'] = $this->components( $flat, $id_map );
		$side['sections']   = $this->sections( $flat, $side['components'] );
		$side['design_system'] = $this->design_system( $flat, $side['components'] );
		$side['viewports']  = $this->viewports( $flat, $styles );
		$side['navigation'] = $this->navigation( $flat );
		$side['mapping']    = $id_map;
		$side['counters']   = $this->counters( $side );
		$side['metadata']   = $this->metadata( $post_id );

		return $side;
	}

	/**
	 * Walk the Elementor element tree into a flat, ordered list.
	 *
	 * @param array<int, mixed>   $elements Element list.
	 * @param string              $parent   Parent element ID.
	 * @param int                 $depth    Current depth.
	 * @param array<int, array>   $flat     Flattened output, by reference.
	 * @param int                 $counter  Order counter, by reference.
	 * @param array<string, string> $id_map Source component map.
	 * @param array<string, mixed> $styles  Style index.
	 * @return void
	 */
	private function walk( array $elements, $parent, $depth, array &$flat, int &$counter, array $id_map, array $styles ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( count( $flat ) >= Validation_Limits::MAX_GENERATED_ELEMENTS ) {
				return;
			}
			$counter++;
			$id       = isset( $element['id'] ) && is_string( $element['id'] ) ? $element['id'] : '';
			$el_type  = isset( $element['elType'] ) && is_string( $element['elType'] ) ? $element['elType'] : '';
			$widget   = isset( $element['widgetType'] ) && is_string( $element['widgetType'] ) ? $element['widgetType'] : '';
			if ( '' === $id || '' === $el_type ) {
				continue;
			}

			$flat[] = array(
				'element_id'  => $id,
				'el_type'     => $el_type,
				'widget'      => $widget,
				'parent_id'   => $parent,
				'depth'       => $depth,
				'order'       => $counter,
				'settings'    => $this->settings( isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array() ),
				'styles'      => isset( $styles['elements'][ $id ] ) && is_array( $styles['elements'][ $id ] ) ? $styles['elements'][ $id ] : array(),
				'source_id'   => isset( $id_map[ $id ] ) ? $id_map[ $id ] : '',
				'child_count' => isset( $element['elements'] ) && is_array( $element['elements'] ) ? count( $element['elements'] ) : 0,
			);

			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
			if ( $children && $depth < Elementor_Limits::MAX_DEPTH + 2 ) {
				$this->walk( $children, $id, $depth + 1, $flat, $counter, $id_map, $styles );
			}
		}
	}

	/**
	 * Read the generated stylesheet and index it per element and device.
	 *
	 * @param int                 $post_id  Draft post ID.
	 * @param array<int, string>  $warnings Warning collector.
	 * @return array<string, mixed>
	 */
	private function style_index( $post_id, array &$warnings ) {
		$index = array(
			'available' => false,
			'elements'  => array(),
			'source'    => '',
			'bytes'     => 0,
			'rules'     => 0,
		);

		$css = $this->generated_css( $post_id );
		if ( ! is_string( $css ) || '' === trim( $css ) ) {
			$warnings[] = __( 'The generated Elementor stylesheet was not available, so only document settings were compared.', 'replicaforge' );
			return $index;
		}

		$css = substr( $css, 0, Validation_Limits::MAX_GENERATED_CSS_BYTES );
		if ( strlen( $css ) >= Validation_Limits::MAX_GENERATED_CSS_BYTES ) {
			$warnings[] = __( 'The generated stylesheet was larger than the configured limit and was truncated for comparison.', 'replicaforge' );
		}

		$index['available'] = true;
		$index['bytes']     = strlen( $css );

		foreach ( $this->parse_rules( $css, $index['rules'] ) as $rule ) {
			$device  = $rule['device'];
			$element = $rule['element_id'];
			if ( '' === $element ) {
				continue;
			}
			if ( ! isset( $index['elements'][ $element ] ) ) {
				$index['elements'][ $element ] = array();
			}
			if ( ! isset( $index['elements'][ $element ][ $device ] ) ) {
				$index['elements'][ $element ][ $device ] = array();
			}
			foreach ( $rule['declarations'] as $property => $value ) {
				// The last declaration wins, matching the cascade within a file.
				$index['elements'][ $element ][ $device ][ $property ] = $value;
			}
		}

		return $index;
	}

	/**
	 * Return the generated stylesheet for a document.
	 *
	 * @param int $post_id Draft post ID.
	 * @return string
	 */
	private function generated_css( $post_id ) {
		$meta = get_post_meta( $post_id, '_elementor_css', true );
		if ( is_array( $meta ) && ! empty( $meta['css'] ) && is_string( $meta['css'] ) ) {
			return $meta['css'];
		}

		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				$file = \Elementor\Core\Files\CSS\Post::create( $post_id );
				if ( is_object( $file ) && method_exists( $file, 'get_path' ) ) {
					$path = (string) $file->get_path();
					if ( '' !== $path && is_readable( $path ) && filesize( $path ) < Validation_Limits::MAX_GENERATED_CSS_BYTES ) {
						$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
						return is_string( $contents ) ? $contents : '';
					}
				}
			} catch ( \Throwable $exception ) {
				return '';
			}
		}

		return '';
	}

	/**
	 * Parse a stylesheet into flat rules annotated with device and element.
	 *
	 * The stylesheet is walked once in document order. A media block is
	 * attributed to a device and parsed recursively, so a rule is never counted
	 * twice and a nested block is attributed to the innermost device that applies.
	 *
	 * @param string $css    Stylesheet text.
	 * @param int    $budget Running rule budget, by reference.
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_rules( $css, &$budget ) {
		$rules = array();
		$this->parse_block( $this->strip_comments( $css ), 'desktop', $budget, $rules );

		return array_values(
			array_filter(
				$rules,
				static function ( $rule ) {
					return '' !== $rule['device'] && '' !== $rule['element_id'] && ! empty( $rule['declarations'] );
				}
			)
		);
	}

	/**
	 * Parse a generated stylesheet into comparable per-device rules.
	 *
	 * This is the public seam for the parser. The running budget is created here
	 * rather than taken from the caller, because it is an internal bound and a
	 * caller has no business raising it.
	 *
	 * @param string $css Stylesheet text.
	 * @return array<int, array<string, mixed>>
	 */
	public function parse_stylesheet( $css ) {
		$budget = 0;

		return $this->parse_rules( (string) $css, $budget );
	}

	/**
	 * Walk one CSS block and collect its rules.
	 *
	 * @param string               $css    Block text.
	 * @param string               $device Device key that applies to this block.
	 * @param int                  $budget Rule budget, by reference.
	 * @param array<int, array>    $rules  Collected rules, by reference.
	 * @return void
	 */
	private function parse_block( $css, $device, &$budget, array &$rules ) {
		$length = strlen( $css );
		$cursor = 0;

		while ( $cursor < $length && $budget < Validation_Limits::MAX_CSS_RULES ) {
			$open = strpos( $css, '{', $cursor );
			if ( false === $open ) {
				return;
			}
			$prelude = trim( substr( $css, $cursor, $open - $cursor ) );
			$close   = $this->matching_brace( $css, $open );
			if ( false === $close ) {
				return;
			}
			$body   = substr( $css, $open + 1, $close - $open - 1 );
			$cursor = $close + 1;

			if ( '' === $prelude ) {
				continue;
			}

			if ( 0 === stripos( $prelude, '@media' ) ) {
				$nested = $this->device_for_query( $prelude );
				if ( '' === $nested && $this->desktop_refinement( $prelude ) ) {
					// A query that only refines the widest view still describes the
					// desktop page, so it keeps the desktop device rather than being
					// discarded. A print or motion query is not comparable and is
					// still skipped.
					$nested = 'desktop';
				}
				if ( '' !== $nested ) {
					$this->parse_block( $body, $nested, $budget, $rules );
				}
				continue;
			}

			if ( 0 === strpos( $prelude, '@' ) ) {
				// A conditional group rule such as @supports, @layer, @container or
				// @scope contains ordinary style rules, and those rules belong to the
				// device already in scope, so the body is walked with the device
				// unchanged. Elementor emits @supports blocks, so skipping the body
				// here would silently lose real element styles.
				if ( $this->groups_rules( $prelude ) ) {
					$this->parse_block( $body, $device, $budget, $rules );
				}
				// @font-face, @keyframes, @import and friends hold no comparable
				// element styles, so their body is intentionally not read.
				continue;
			}

			$budget++;
			if ( strlen( $body ) > 20000 ) {
				continue;
			}

			$element = $this->element_from_selector( $prelude );
			if ( '' === $element ) {
				continue;
			}

			$declarations = $this->declarations( $body );
			if ( empty( $declarations ) ) {
				continue;
			}

			$rules[] = array(
				'selector'     => $prelude,
				'element_id'   => $element,
				'declarations' => $declarations,
				'device'       => $device,
			);
		}
	}

	/**
	 * Return the offset of the brace closing the one at an offset.
	 *
	 * @param string $css   CSS text.
	 * @param int    $open  Offset of the opening brace.
	 * @return int|false
	 */
	private function matching_brace( $css, $open ) {
		$depth  = 0;
		$length = strlen( $css );
		for ( $index = $open; $index < $length; $index++ ) {
			$character = $css[ $index ];
			if ( '{' === $character ) {
				$depth++;
			} elseif ( '}' === $character ) {
				$depth--;
				if ( 0 === $depth ) {
					return $index;
				}
			}
		}
		return false;
	}

	/**
	 * Extract comparable declarations from a rule body.
	 *
	 * @param string $body Rule body.
	 * @return array<string, string>
	 */
	private function declarations( $body ) {
		$result = array();
		foreach ( explode( ';', $body ) as $declaration ) {
			$declaration = trim( $declaration );
			if ( '' === $declaration || false === strpos( $declaration, ':' ) ) {
				continue;
			}
			list( $property, $value ) = array_map( 'trim', explode( ':', $declaration, 2 ) );
			$property = strtolower( $property );
			$value    = trim( $value, " \t\n\r\0\x0B;\"'" );
			if ( '' === $property || '' === $value ) {
				continue;
			}
			if ( strlen( $value ) > 300 || Elementor_Values::is_executable( $value ) ) {
				continue;
			}
			if ( isset( $this->custom_properties[ $property ] ) ) {
				$result[ $this->custom_properties[ $property ] ] = $value;
				continue;
			}
			if ( in_array( $property, $this->declarations, true ) ) {
				$result[ $property ] = $value;
			}
		}
		return $result;
	}

	/**
	 * Return the Elementor element ID referenced by a selector.
	 *
	 * @param string $selector CSS selector.
	 * @return string
	 */
	private function element_from_selector( $selector ) {
		if ( preg_match( '/\.elementor-element-([a-z0-9]{5,10})\b/i', $selector, $matches ) ) {
			return strtolower( $matches[1] );
		}
		if ( preg_match( '/\.elementor-([a-f0-9]{7})\b/i', $selector, $matches ) ) {
			return strtolower( $matches[1] );
		}
		return '';
	}

	/**
	 * Return whether a media query only refines the widest view.
	 *
	 * `@media (min-width: 1025px)` and similar queries restate the desktop layout
	 * for large screens, so their rules belong to the desktop device. A query that
	 * selects an output or user-preference medium describes a view ReplicaForge
	 * does not measure, so it is not comparable.
	 *
	 * @param string $prelude Media query prelude.
	 * @return bool
	 */
	private function desktop_refinement( $prelude ) {
		$query = strtolower( (string) $prelude );
		if ( preg_match( '/\b(print|speech|reduced-motion|reduced-transparency|update|forced-colors)\b/', $query ) ) {
			return false;
		}
		if ( ! preg_match( '/min-width:\s*(\d+)px/', $query, $matches ) ) {
			return false;
		}
		return (int) $matches[1] > $this->breakpoints['tablet'];
	}

	/**
	 * Return whether an at-rule can contain ordinary style rules of its own.
	 *
	 * A group rule is transparent to device attribution: the rules inside it
	 * belong to whichever device is already in scope. An at-rule such as
	 * `@keyframes` also has a body, but that body holds percentage selectors
	 * rather than element selectors, so it is not read.
	 *
	 * @param string $prelude At-rule prelude.
	 * @return bool
	 */
	private function groups_rules( $prelude ) {
		foreach ( array( '@supports', '@layer', '@container', '@document', '@scope' ) as $keyword ) {
			if ( 0 === stripos( $prelude, $keyword ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the device key for a media query.
	 *
	 * A query with no `max-width` has no device of its own, so it returns an empty
	 * string and the caller leaves the current device in scope.
	 *
	 * @param string $query Media query text.
	 * @return string
	 */
	private function device_for_query( $query ) {
		$query = strtolower( (string) $query );
		if ( false === strpos( $query, 'max-width' ) ) {
			return '';
		}
		if ( ! preg_match( '/max-width:\s*(\d+)px/', $query, $matches ) ) {
			return '';
		}
		$width = (int) $matches[1];
		if ( $width <= $this->breakpoints['mobile'] ) {
			return 'mobile';
		}
		if ( $width <= $this->breakpoints['tablet'] ) {
			return 'tablet';
		}
		return '';
	}

	/**
	 * Remove comments from a stylesheet.
	 *
	 * @param string $css Stylesheet text.
	 * @return string
	 */
	private function strip_comments( $css ) {
		$stripped = preg_replace( '#/\*.*?\*/#s', '', (string) $css );
		return is_string( $stripped ) ? $stripped : '';
	}

	/**
	 * Read the Phase 4 component to element map from a draft.
	 *
	 * @param int $post_id Draft post ID.
	 * @return array<string, string>
	 */
	public function id_map( $post_id ) {
		$raw = get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'id_map', true );
		$map = array();
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $component_id => $element_id ) {
					$component_id = $this->token( $component_id, 120 );
					$element_id   = is_string( $element_id ) ? strtolower( trim( $element_id ) ) : '';
					if ( '' === $component_id || '' === $element_id ) {
						continue;
					}
					$map[ $element_id ] = $component_id;
				}
			}
		}
		return $map;
	}

	/**
	 * Return bounded element settings.
	 *
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<string, mixed>
	 */
	private function settings( array $settings ) {
		$result = array();
		$flat   = $this->flatten( $settings, 0 );
		foreach ( $flat as $key => $value ) {
			if ( is_scalar( $value ) && strlen( (string) $value ) <= 300 ) {
				$result[ $key ] = (string) $value;
			}
		}
		return $result;
	}

	/**
	 * Flatten nested settings into dotted keys.
	 *
	 * @param mixed $settings Settings value.
	 * @param int   $depth    Current depth.
	 * @return array<string, mixed>
	 */
	private function flatten( $settings, $depth ) {
		$result = array();
		if ( $depth > 5 || ! is_array( $settings ) ) {
			return $result;
		}
		foreach ( $settings as $key => $value ) {
			$name = is_string( $key ) ? $key : (string) $key;
			if ( is_array( $value ) ) {
				foreach ( $this->flatten( $value, $depth + 1 ) as $child_key => $child_value ) {
					$result[ $name . '.' . $child_key ] = $child_value;
				}
				continue;
			}
			$result[ $name ] = $value;
		}
		return $result;
	}

	/**
	 * Build comparable component records for the generated side.
	 *
	 * @param array<int, array>    $flat   Flattened elements.
	 * @param array<string, string> $id_map Source component map.
	 * @return array<string, array<string, mixed>>
	 */
	private function components( array $flat, array $id_map ) {
		$result = array();
		$order  = 0;

		foreach ( $flat as $element ) {
			if ( 'container' === $element['el_type'] && 0 === (int) $element['depth'] ) {
				continue;
			}
			// Top-level containers are sections and are compared as sections.
			$order++;
			$id         = (string) $element['element_id'];
			$widget     = (string) $element['widget'];
			$is_section = 'container' === $element['el_type'] && '' === (string) $element['parent_id'];
			$type       = $this->comparison_type( $element, $is_section );
			$settings   = $element['settings'];
			$styles     = $element['styles'];

			$result[ $id ] = array(
				'key'         => $id,
				'order'       => $order,
				'type'        => $type,
				'widget'      => $widget,
				'el_type'     => $element['el_type'],
				'parent_id'   => (string) $element['parent_id'],
				'depth'       => (int) $element['depth'],
				'child_count' => (int) $element['child_count'],
				'source_id'   => (string) $element['source_id'],
				'mapped'      => '' !== (string) $element['source_id'],
				'text'        => $this->element_text( $settings, $type ),
				'link'        => $this->element_link( $settings ),
				'fields'      => $this->element_fields( $settings ),
				'image'       => $this->element_image( $settings ),
				'typography'  => $this->element_typography( $styles, $type, $widget, $settings ),
				'box'         => $this->element_box( $styles ),
				'layout'      => $this->element_layout( $styles ),
				'settings'    => $settings,
			);
		}

		return $result;
	}

	/**
	 * Build comparable section records for the generated side.
	 *
	 * @param array<int, array>                  $flat       Flattened elements.
	 * @param array<string, array<string, mixed>> $components Normalized components.
	 * @return array<int, array<string, mixed>>
	 */
	private function sections( array $flat, array $components ) {
		$root_ids = array();
		foreach ( $flat as $element ) {
			if ( 'container' === $element['el_type'] && 1 === (int) $element['depth'] ) {
				$root_ids[] = (string) $element['element_id'];
			}
		}

		$sections = array();
		$order    = 0;
		foreach ( $root_ids as $id ) {
			$order++;
			$style = $this->styles_by_id( $flat, $id );
			$desktop = isset( $style['desktop'] ) && is_array( $style['desktop'] ) ? $style['desktop'] : array();

			$members    = array();
			$columns    = 0;
			$ratios     = array();
			foreach ( $this->descendants( $flat, $id ) as $child ) {
				$child_id = (string) $child['element_id'];
				if ( isset( $components[ $child_id ] ) ) {
					$members[] = $child_id;
				}
				if ( 'container' === $child['el_type'] && 2 === (int) $child['depth'] ) {
					$columns++;
					$width    = $this->style_value( isset( $child['styles'] ) && is_array( $child['styles'] ) ? $child['styles'] : array(), 'desktop', 'width' );
					$percent  = Comparison_Schema::length( $width );
					if ( null !== $percent && $percent > 0 ) {
						$ratios[] = round( $percent / 100, 4 );
					}
				}
			}

			$sections[] = array(
				'key'           => $id,
				'order'         => $order,
				'type'          => $this->section_type( $flat, $id ),
				'column_count'  => max( 1, $columns ),
				'column_ratios' => count( $ratios ) === max( 1, $columns ) ? $ratios : array(),
				'direction'     => $this->direction( $desktop ),
				'gap'           => $this->gap( $desktop ),
				'wrap'          => $this->style_value_by_id( $flat, $id, 'desktop', 'flex-wrap' ),
				'align'         => array(
					'justify' => $this->style_value_by_id( $flat, $id, 'desktop', 'justify-content' ),
					'align'   => $this->style_value_by_id( $flat, $id, 'desktop', 'align-items' ),
				),
				'max_width'     => Comparison_Schema::length( $this->style_value_by_id( $flat, $id, 'desktop', 'max-width' ) ),
				'members'       => $members,
				'heading'       => $this->section_heading( $members, $components ),
				'tag'           => 'div',
				'confidence'    => 1.0,
			);
		}

		return $sections;
	}

	/**
	 * Return the per-device styles of an element.
	 *
	 * @param array<int, array> $flat Flattened elements.
	 * @param string            $id   Element ID.
	 * @return array<string, string>
	 */
	private function styles_by_id( array $flat, $id ) {
		foreach ( $flat as $element ) {
			if ( (string) $element['element_id'] === $id ) {
				$styles = array();
				foreach ( (array) $element['styles'] as $device => $values ) {
					if ( is_array( $values ) ) {
						$styles[ (string) $device ] = $values;
					}
				}
				return $styles;
			}
		}
		return array();
	}

	/**
	 * Return one style value for an element and device.
	 *
	 * @param array<int, array>  $flat   Flattened elements.
	 * @param string             $id     Element ID.
	 * @param string             $device Device key.
	 * @param string             $key    Property name.
	 * @return string
	 */
	private function style_value_by_id( array $flat, $id, $device, $key ) {
		$styles = $this->styles_by_id( $flat, $id );
		if ( isset( $styles[ $device ][ $key ] ) && is_string( $styles[ $device ][ $key ] ) ) {
			return $styles[ $device ][ $key ];
		}
		return '';
	}

	/**
	 * Return one style value from a normalized component style record.
	 *
	 * @param array<string, mixed> $styles Style record.
	 * @param string               $device Device key.
	 * @param string               $key    Property name.
	 * @return string
	 */
	private function style_value( $styles, $device, $key ) {
		if ( isset( $styles[ $device ][ $key ] ) && is_string( $styles[ $device ][ $key ] ) ) {
			return $styles[ $device ][ $key ];
		}
		return '';
	}

	/**
	 * Return all descendants of an element.
	 *
	 * @param array<int, array> $flat    Flattened elements.
	 * @param string            $parent  Parent element ID.
	 * @param int               $max     Maximum results.
	 * @return array<int, array>
	 */
	private function descendants( array $flat, $parent, $max = 500 ) {
		$result = array();
		foreach ( $flat as $element ) {
			if ( (string) $element['parent_id'] === (string) $parent ) {
				$result[] = $element;
				if ( count( $result ) >= $max ) {
					break;
				}
			}
		}
		return $result;
	}

	/**
	 * Return the comparison type of a generated element.
	 *
	 * @param array<string, mixed> $element     Flattened element.
	 * @param bool                 $is_section  Whether the element is a section.
	 * @return string
	 */
	private function comparison_type( array $element, $is_section ) {
		if ( 'container' === $element['el_type'] ) {
			return $is_section ? 'section' : 'container';
		}
		$widget = (string) $element['widget'];
		return isset( $this->widget_types[ $widget ] ) ? $this->widget_types[ $widget ] : 'widget';
	}

	/**
	 * Return the text of a generated element.
	 *
	 * @param array<string, mixed> $settings Element settings.
	 * @param string               $type     Comparison type.
	 * @return string
	 */
	private function element_text( array $settings, $type ) {
		$candidates = array( 'title', 'editor', 'text', 'caption' );
		foreach ( $candidates as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( $settings[ $key ] ) ) {
				$value = $settings[ $key ];
				if ( function_exists( 'wp_strip_all_tags' ) ) {
					$value = wp_strip_all_tags( $value );
				}
				$value = trim( preg_replace( '/\s+/u', ' ', $value ) );
				if ( function_exists( 'mb_substr' ) ) {
					return mb_substr( $value, 0, 900, 'UTF-8' );
				}
				return substr( $value, 0, 900 );
			}
		}
		return '';
	}

	/**
	 * Return the link of a generated element.
	 *
	 * @param array<string, mixed> $settings Element settings.
	 * @return string
	 */
	private function element_link( array $settings ) {
		$url = isset( $settings['link.url'] ) && is_string( $settings['link.url'] ) ? trim( $settings['link.url'] ) : '';
		if ( '' === $url || '#' === $url ) {
			return '';
		}
		return Security::is_safe_public_reference( $url ) ? (string) Security::normalize_http_url( $url ) : '';
	}

	/**
	 * Return comparable fields of a generated element.
	 *
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<string, string>
	 */
	private function element_fields( array $settings ) {
		$map = array(
			'price'      => 'price',
			'sale_price' => 'sale_price',
			'rating'     => 'rating',
			'badge'      => 'badge',
			'button'     => 'text',
			'label'      => 'label',
		);
		$result = array();
		foreach ( $map as $key => $field ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) && '' !== trim( $settings[ $key ] ) ) {
				$result[ $field ] = trim( $settings[ $key ] );
			}
		}
		if ( isset( $settings['image.alt'] ) && is_string( $settings['image.alt'] ) && '' !== $settings['image.alt'] ) {
			$result['image_alt'] = $settings['image.alt'];
		}
		return $result;
	}

	/**
	 * Return comparable image metadata of a generated element.
	 *
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<string, mixed>
	 */
	private function element_image( array $settings ) {
		$url     = isset( $settings['image.url'] ) && is_string( $settings['image.url'] ) ? trim( $settings['image.url'] ) : '';
		$is_local = 0 === strpos( $url, 'http' );
		return array(
			'src'         => $is_local && Security::is_safe_public_reference( $url ) ? (string) Security::normalize_http_url( $url ) : '',
			'attachment'  => isset( $settings['image.id'] ) && is_numeric( $settings['image.id'] ) ? (int) $settings['image.id'] : 0,
			'width'       => isset( $settings['image_size.width'] ) && is_numeric( $settings['image_size.width'] ) ? (int) $settings['image_size.width'] : null,
			'height'      => isset( $settings['image_size.height'] ) && is_numeric( $settings['image_size.height'] ) ? (int) $settings['image_size.height'] : null,
			'caption'     => isset( $settings['caption'] ) && is_string( $settings['caption'] ) ? trim( $settings['caption'] ) : '',
			'present'     => '' !== $url,
			'local_asset' => $is_local,
		);
	}

	/**
	 * Return comparable typography for a generated element.
	 *
	 * The captured stylesheet is preferred, because it is what the page actually
	 * renders. The element's own Elementor settings are used as a fallback, because
	 * they are the document's authoritative record of the value and a draft that has
	 * never been rendered has no stylesheet. Without the fallback every typography
	 * property reads as missing on an unrendered draft, which invents differences
	 * and proposes corrections for values that are already set.
	 *
	 * @param array<string, array> $styles   Element styles.
	 * @param string               $type     Comparison type.
	 * @param string               $widget   Widget name.
	 * @param array<string, mixed> $settings Element settings.
	 * @return array<string, mixed>
	 */
	private function element_typography( $styles, $type, $widget, array $settings = array() ) {
		$read = function ( $device, $property ) use ( $styles ) {
			return isset( $styles[ $device ][ $property ] ) && is_string( $styles[ $device ][ $property ] ) ? $styles[ $device ][ $property ] : '';
		};

		$desktop = isset( $styles['desktop'] ) && is_array( $styles['desktop'] ) ? $styles['desktop'] : array();
		$role    = 'heading' === $type ? 'heading' : ( 'button' === $type ? 'button' : 'body' );

		// The first value that is present wins, so a rendered declaration is never
		// overridden by a stored setting that happens to differ.
		$first = function ( $from_styles, $control, $normalizer ) use ( $settings ) {
			$from_styles = (string) $from_styles;
			if ( '' !== $from_styles ) {
				return call_user_func( $normalizer, $from_styles );
			}
			if ( ! isset( $settings[ $control ] ) ) {
				return null;
			}
			$stored = $settings[ $control ];
			if ( ! is_scalar( $stored ) ) {
				return null;
			}
			return call_user_func( $normalizer, (string) $stored );
		};

		$slider = function ( $value ) {
			if ( ! is_array( $value ) || ! isset( $value['size'] ) || ! is_numeric( $value['size'] ) ) {
				return null;
			}
			return (float) $value['size'];
		};

		$font_family = $first( isset( $desktop['font-family'] ) ? $desktop['font-family'] : '', 'typography_font_family', array( __CLASS__, 'comparison_font_family' ) );
		$font_size   = $first( isset( $desktop['font-size'] ) ? $desktop['font-size'] : '', 'typography_font_size', $slider );

		return array(
			'role'           => $role,
			'font_family'    => $font_family,
			'font_size'      => $font_size,
			'font_weight'    => $first( isset( $desktop['font-weight'] ) ? $desktop['font-weight'] : '', 'typography_font_weight', array( __CLASS__, 'comparison_font_weight' ) ),
			'line_height'    => $first( isset( $desktop['line-height'] ) ? $desktop['line-height'] : '', 'typography_line_height', array( __CLASS__, 'comparison_line_height' ) ),
			'letter_spacing' => $first( isset( $desktop['letter-spacing'] ) ? $desktop['letter-spacing'] : '', 'typography_letter_spacing', array( __CLASS__, 'comparison_length' ) ),
			'text_transform' => isset( $desktop['text-transform'] ) ? strtolower( trim( (string) $desktop['text-transform'] ) ) : '',
			'text_align'     => isset( $desktop['text-align'] ) ? strtolower( trim( (string) $desktop['text-align'] ) ) : '',
			'color'          => Comparison_Schema::color( isset( $desktop['color'] ) ? $desktop['color'] : '' ),
			'font_size_tablet' => Comparison_Schema::font_size( $read( 'tablet', 'font-size' ) ),
			'font_size_mobile' => Comparison_Schema::font_size( $read( 'mobile', 'font-size' ) ),
			'display_tablet' => isset( $styles['tablet']['display'] ) ? strtolower( trim( (string) $styles['tablet']['display'] ) ) : '',
			'display_mobile' => isset( $styles['mobile']['display'] ) ? strtolower( trim( (string) $styles['mobile']['display'] ) ) : '',
			'widget'         => $widget,
		);
	}

	/**
	 * Callable wrappers for the comparison normalizers.
	 *
	 * The normalizers are static methods on another class, and a closure array is
	 * not callable in every supported PHP build, so they are reached through named
	 * methods instead of `array( __CLASS__, ... )` on a class that does not own
	 * them.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	public static function comparison_font_family( $value ) {
		return Comparison_Schema::font_family( $value );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	public static function comparison_font_weight( $value ) {
		return Comparison_Schema::font_weight( $value );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function comparison_line_height( $value ) {
		return Comparison_Schema::line_height( $value );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function comparison_length( $value ) {
		return Comparison_Schema::length( $value );
	}

	/**
	 * Return comparable box values for a generated element.
	 *
	 * @param array<string, array> $styles Element styles.
	 * @return array<string, mixed>
	 */
	private function element_box( $styles ) {
		$desktop = isset( $styles['desktop'] ) && is_array( $styles['desktop'] ) ? $styles['desktop'] : array();
		return array(
			'background_color' => Comparison_Schema::color( isset( $desktop['background-color'] ) ? $desktop['background-color'] : '' ),
			'background_image' => isset( $desktop['background-image'] ) && is_string( $desktop['background-image'] ) && false !== strpos( $desktop['background-image'], 'url(' ) ? 'present' : '',
			'border_color'     => Comparison_Schema::color( isset( $desktop['border-color'] ) ? $desktop['border-color'] : '' ),
			'border_style'     => isset( $desktop['border-style'] ) ? strtolower( trim( (string) $desktop['border-style'] ) ) : '',
			'border_width'     => Comparison_Schema::length( isset( $desktop['border-width'] ) ? $desktop['border-width'] : '' ),
			'border_radius'    => $this->radius( $desktop ),
			'box_shadow'       => isset( $desktop['box-shadow'] ) && is_string( $desktop['box-shadow'] ) && 'none' !== $desktop['box-shadow'] ? $desktop['box-shadow'] : '',
			'width'            => Comparison_Schema::length( isset( $desktop['width'] ) ? $desktop['width'] : '' ),
			'max_width'        => Comparison_Schema::length( isset( $desktop['max-width'] ) ? $desktop['max-width'] : '' ),
			'min_height'       => Comparison_Schema::length( isset( $desktop['min-height'] ) ? $desktop['min-height'] : '' ),
			'object_fit'       => isset( $desktop['object-fit'] ) ? strtolower( trim( (string) $desktop['object-fit'] ) ) : '',
		);
	}

	/**
	 * Return a comparable border radius.
	 *
	 * @param array<string, mixed> $desktop Desktop declarations.
	 * @return float|null
	 */
	private function radius( array $desktop ) {
		foreach ( array( 'border-radius', 'border-top-left-radius' ) as $key ) {
			if ( isset( $desktop[ $key ] ) ) {
				$length = Comparison_Schema::length( $desktop[ $key ] );
				if ( null !== $length ) {
					return $length;
				}
			}
		}
		return null;
	}

	/**
	 * Return comparable layout values for a generated element.
	 *
	 * @param array<string, array> $styles Element styles.
	 * @return array<string, mixed>
	 */
	private function element_layout( $styles ) {
		$desktop = isset( $styles['desktop'] ) && is_array( $styles['desktop'] ) ? $styles['desktop'] : array();
		return array(
			'direction'   => $this->direction( $desktop ),
			'gap'         => $this->gap( $desktop ),
			'padding_top' => Comparison_Schema::length( isset( $desktop['padding-top'] ) ? $desktop['padding-top'] : '' ),
			'padding_right' => Comparison_Schema::length( isset( $desktop['padding-right'] ) ? $desktop['padding-right'] : '' ),
			'padding_bottom' => Comparison_Schema::length( isset( $desktop['padding-bottom'] ) ? $desktop['padding-bottom'] : '' ),
			'padding_left' => Comparison_Schema::length( isset( $desktop['padding-left'] ) ? $desktop['padding-left'] : '' ),
			'margin_top'  => Comparison_Schema::length( isset( $desktop['margin-top'] ) ? $desktop['margin-top'] : '' ),
			'margin_bottom' => Comparison_Schema::length( isset( $desktop['margin-bottom'] ) ? $desktop['margin-bottom'] : '' ),
		);
	}

	/**
	 * Return a normalized flex direction from declarations.
	 *
	 * @param array<string, mixed> $styles Declarations.
	 * @return string
	 */
	private function direction( $styles ) {
		$direction = isset( $styles['flex-direction'] ) ? strtolower( trim( (string) $styles['flex-direction'] ) ) : '';
		return in_array( $direction, array( 'row', 'column', 'row-reverse', 'column-reverse' ), true ) ? $direction : '';
	}

	/**
	 * Return a normalized gap value from declarations.
	 *
	 * @param array<string, mixed> $styles Declarations.
	 * @return float|null
	 */
	private function gap( $styles ) {
		foreach ( array( 'row-gap', 'column-gap', 'gap' ) as $key ) {
			if ( isset( $styles[ $key ] ) ) {
				$length = Comparison_Schema::length( $styles[ $key ] );
				if ( null !== $length ) {
					return $length;
				}
			}
		}
		return null;
	}

	/**
	 * Return a comparable section type.
	 *
	 * @param array<int, array> $flat Flattened elements.
	 * @param string            $id   Section element ID.
	 * @return string
	 */
	private function section_type( array $flat, $id ) {
		foreach ( $flat as $element ) {
			if ( (string) $element['element_id'] !== $id ) {
				continue;
			}
			$tag = isset( $element['settings']['html_tag'] ) ? (string) $element['settings']['html_tag'] : '';
			if ( in_array( $tag, array( 'header', 'footer', 'main', 'nav', 'article', 'aside', 'section' ), true ) ) {
				return $tag;
			}
			break;
		}
		return 'section';
	}

	/**
	 * Return the heading text of a section.
	 *
	 * @param array<int, string>                    $members    Member element IDs.
	 * @param array<string, array<string, mixed>>   $components Normalized components.
	 * @return string
	 */
	private function section_heading( array $members, array $components ) {
		foreach ( $members as $id ) {
			if ( isset( $components[ $id ] ) && 'heading' === $components[ $id ]['type'] && '' !== $components[ $id ]['text'] ) {
				return $components[ $id ]['text'];
			}
		}
		return '';
	}

	/**
	 * Build the generated design system.
	 *
	 * @param array<int, array>                     $flat       Flattened elements.
	 * @param array<string, array<string, mixed>>   $components Normalized components.
	 * @return array<string, mixed>
	 */
	private function design_system( array $flat, array $components ) {
		$colors     = array();
		$typography = array();
		$radius     = array();
		$shadows    = array();

		foreach ( $components as $component ) {
			$color = isset( $component['typography']['color'] ) ? $component['typography']['color'] : null;
			if ( is_array( $color ) && ! isset( $colors['text'] ) ) {
				$colors['text'] = $color;
			}
			$background = isset( $component['box']['background_color'] ) ? $component['box']['background_color'] : null;
			if ( is_array( $background ) && ! isset( $colors['background'] ) ) {
				$colors['background'] = $background;
			}
			if ( 'button' === $component['type'] && is_array( $color ) && ! isset( $colors['primary'] ) ) {
				$colors['primary'] = $color;
			}

			$role = isset( $component['typography']['role'] ) ? (string) $component['typography']['role'] : '';
			if ( '' === $role ) {
				continue;
			}
			if ( ! isset( $typography[ $role ] ) ) {
				$typography[ $role ] = array(
					'font_family'    => isset( $component['typography']['font_family'] ) ? $component['typography']['font_family'] : null,
					'font_size'      => isset( $component['typography']['font_size'] ) ? $component['typography']['font_size'] : null,
					'font_weight'    => isset( $component['typography']['font_weight'] ) ? $component['typography']['font_weight'] : null,
					'line_height'    => isset( $component['typography']['line_height'] ) ? $component['typography']['line_height'] : null,
					'letter_spacing' => isset( $component['typography']['letter_spacing'] ) ? $component['typography']['letter_spacing'] : null,
					'text_transform' => isset( $component['typography']['text_transform'] ) ? $component['typography']['text_transform'] : '',
				);
			}

			$component_radius = isset( $component['box']['border_radius'] ) ? $component['box']['border_radius'] : null;
			if ( null !== $component_radius && count( $radius ) < 16 ) {
				$radius[] = (float) $component_radius;
			}
			$shadow = isset( $component['box']['box_shadow'] ) ? $component['box']['box_shadow'] : '';
			if ( '' !== $shadow && count( $shadows ) < 10 ) {
				$shadows[] = $shadow;
			}
		}

		return array(
			'colors'           => $colors,
			'color_confidence' => array(),
			'typography'       => $typography,
			'spacing'          => $this->spacing_values( $components ),
			'radius'           => array_values( array_unique( $radius ) ),
			'shadows'          => array_values( array_unique( $shadows ) ),
		);
	}

	/**
	 * Collect comparable spacing values from the generated side.
	 *
	 * @param array<string, array<string, mixed>> $components Normalized components.
	 * @return array<int, float>
	 */
	private function spacing_values( array $components ) {
		$values = array();
		foreach ( $components as $component ) {
			foreach ( array( 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'margin_top', 'margin_bottom' ) as $key ) {
				$value = isset( $component['layout'][ $key ] ) ? $component['layout'][ $key ] : null;
				if ( null !== $value ) {
					$values[] = (float) $value;
				}
			}
			$gap = isset( $component['layout']['gap'] ) ? $component['layout']['gap'] : null;
			if ( null !== $gap ) {
				$values[] = (float) $gap;
			}
		}
		sort( $values );
		return array_slice( array_values( array_unique( $values ) ), 0, 24 );
	}

	/**
	 * Build per-viewport records for the generated side.
	 *
	 * @param array<int, array> $flat   Flattened elements.
	 * @param array<string, mixed> $styles Style index.
	 * @return array<string, mixed>
	 */
	private function viewports( array $flat, array $styles ) {
		$viewports = array();
		foreach ( array_keys( Validation_Limits::VIEWPORTS ) as $device ) {
			$viewports[ $device ] = array(
				'available'     => false,
				'evidence'      => false,
				'overrides'     => 0,
				'directions'    => array(),
				'font_size_hints' => array(),
				'spacing_hints' => array(),
				'visibility'    => array(),
				'column_count'  => null,
			);
		}

		$viewports['desktop']['available'] = true;
		$viewports['desktop']['evidence']  = ! empty( $styles['available'] );

		foreach ( $flat as $element ) {
			$element_styles = (array) $element['styles'];
			foreach ( $element_styles as $device => $values ) {
				if ( ! isset( $viewports[ $device ] ) || ! is_array( $values ) ) {
					continue;
				}
				$viewports[ $device ]['available'] = true;
				$viewports[ $device ]['overrides']++;
				if ( isset( $values['flex-direction'] ) ) {
					$viewports[ $device ]['directions'][ (string) $element['element_id'] ] = strtolower( (string) $values['flex-direction'] );
				}
				if ( isset( $values['font-size'] ) ) {
					$viewports[ $device ]['font_size_hints'][] = (string) $element['element_id'];
				}
				if ( isset( $values['row-gap'] ) || isset( $values['column-gap'] ) ) {
					$viewports[ $device ]['spacing_hints'][] = (string) $element['element_id'];
				}
				if ( isset( $values['display'] ) ) {
					$viewports[ $device ]['visibility'][ (string) $element['element_id'] ] = strtolower( (string) $values['display'] );
				}
			}
		}

		// Column counts per device are derived from the direction of depth-two
		// containers: a stacked column set is a single column at that device.
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			$max_columns = 0;
			foreach ( $flat as $element ) {
				if ( 'container' !== $element['el_type'] || 2 !== (int) $element['depth'] ) {
					continue;
				}
				$direction = $this->direction( isset( $element['styles'][ $device ] ) ? (array) $element['styles'][ $device ] : array() );
				$max_columns = max( $max_columns, 'row' === $direction ? 2 : 1 );
			}
			$viewports[ $device ]['column_count'] = $max_columns > 0 ? $max_columns : null;
		}

		return $viewports;
	}

	/**
	 * Derive navigation state for the generated side.
	 *
	 * @param array<int, array> $flat Flattened elements.
	 * @return array<string, mixed>
	 */
	private function navigation( array $flat ) {
		$links = 0;
		$hidden_mobile = 0;
		foreach ( $flat as $element ) {
			if ( 'button' === $element['widget'] || 'navigation' === $element['widget'] ) {
				$links++;
			}
			$mobile = isset( $element['styles']['mobile']['display'] ) ? strtolower( (string) $element['styles']['mobile']['display'] ) : '';
			if ( 'none' === $mobile ) {
				$hidden_mobile++;
			}
		}

		return array(
			'links'            => $links,
			'hidden_on_mobile' => $hidden_mobile,
			'behavior'         => 'unknown',
			'reproduced'       => 'unknown',
		);
	}

	/**
	 * Read bounded generation metadata from a draft.
	 *
	 * @param int $post_id Draft post ID.
	 * @return array<string, mixed>
	 */
	private function metadata( $post_id ) {
		$raw = get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'provenance', true );
		return array(
			'generation_id'       => (string) get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'generation_id', true ),
			'generated_at'        => (string) get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'generated_at', true ),
			'schema_version'      => (string) get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'schema_version', true ),
			'elementor_version'   => (string) get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'elementor_version', true ),
			'ai_used'             => '1' === (string) get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'ai_used', true ),
			'source_url'          => (string) get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'source_url', true ),
			'provenance'          => is_string( $raw ) && '' !== $raw ? $this->provenance_summary( $raw ) : array(),
		);
	}

	/**
	 * Summarize stored asset provenance without keeping the whole payload.
	 *
	 * @param string $raw Stored JSON.
	 * @return array<string, int>
	 */
	private function provenance_summary( $raw ) {
		$decoded  = json_decode( $raw, true );
		$summary  = array(
			'imported'   => 0,
			'referenced' => 0,
			'blocked'    => 0,
			'failed'     => 0,
			'unavailable' => 0,
		);
		if ( ! is_array( $decoded ) ) {
			return $summary;
		}
		foreach ( $decoded as $record ) {
			if ( ! is_array( $record ) || ! isset( $record['import_status'] ) ) {
				continue;
			}
			$status = (string) $record['import_status'];
			if ( 'pending' === $status ) {
				$status = 'referenced';
			}
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
		}
		return $summary;
	}

	/**
	 * Build comparison counters for the generated side.
	 *
	 * @param array<string, mixed> $side Normalized side.
	 * @return array<string, mixed>
	 */
	private function counters( array $side ) {
		$types  = array();
		$images = 0;
		$links  = 0;
		foreach ( $side['components'] as $component ) {
			$type = (string) $component['type'];
			$types[ $type ] = isset( $types[ $type ] ) ? $types[ $type ] + 1 : 1;
			if ( ! empty( $component['image']['present'] ) ) {
				$images++;
			}
			if ( '' !== $component['link'] ) {
				$links++;
			}
		}
		$sections = array();
		foreach ( $side['sections'] as $section ) {
			$sections[ (string) $section['key'] ] = true;
		}
		return array(
			'sections'        => count( $side['sections'] ),
			'components'      => count( $side['components'] ),
			'component_types' => $types,
			'images'          => $images,
			'links'           => $links,
			'mapped'          => count( $side['mapping'] ),
		);
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
}
