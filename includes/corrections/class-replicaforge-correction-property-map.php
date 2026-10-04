<?php
/**
 * Correction property whitelist for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The complete, closed set of properties a correction may write.
 *
 * Every writable path goes through this class. A comparison property that is not
 * in the table has no Elementor control and therefore cannot be corrected, and a
 * value is only ever written through `Elementor_Values`, the same policy Phase 4
 * used to create the value in the first place.
 *
 * Each entry declares:
 *
 * - `control`  the Elementor control key, without a device suffix
 * - `kinds`    the element types the control is valid on
 * - `shape`    how the comparison value is coerced into the Elementor value
 * - `devices`  whether a `_<device>` suffix is permitted
 * - `sides`    for a dimensions control, which sides a single-side property writes
 * - `level`    the correction level the property belongs to
 * - `batch`    the batch the property is applied in
 */
final class Correction_Property_Map {

	/**
	 * The whitelist.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $map = array(
		// Typography ------------------------------------------------------------
		'font_size'       => array(
			'control' => 'typography_font_size',
			'kinds'   => array( 'widget' ),
			'shape'   => 'slider',
			'devices' => true,
			'level'   => 2,
			'batch'   => 'typography',
		),
		'font_family'     => array(
			'control' => 'typography_font_family',
			'kinds'   => array( 'widget' ),
			'shape'   => 'font_family',
			'devices' => false,
			'level'   => 2,
			'batch'   => 'typography',
		),
		'font_weight'     => array(
			'control' => 'typography_font_weight',
			'kinds'   => array( 'widget' ),
			'shape'   => 'font_weight',
			'devices' => false,
			'level'   => 2,
			'batch'   => 'typography',
		),
		'line_height'     => array(
			'control' => 'typography_line_height',
			'kinds'   => array( 'widget' ),
			'shape'   => 'line_height',
			'devices' => false,
			'level'   => 2,
			'batch'   => 'typography',
		),
		'letter_spacing'  => array(
			'control' => 'typography_letter_spacing',
			'kinds'   => array( 'widget' ),
			'shape'   => 'slider',
			'devices' => true,
			'level'   => 2,
			'batch'   => 'typography',
		),
		'text_transform'  => array(
			'control' => 'typography_text_transform',
			'kinds'   => array( 'widget' ),
			'shape'   => 'enum:uppercase,lowercase,capitalize,none',
			'devices' => false,
			'level'   => 2,
			'batch'   => 'typography',
		),
		'text_color'      => array(
			'control' => 'typography_color',
			'kinds'   => array( 'widget' ),
			'shape'   => 'color',
			'devices' => true,
			'level'   => 2,
			'batch'   => 'colors',
		),
		// Colours and borders ---------------------------------------------------
		'background_color' => array(
			'control' => 'background_color',
			'kinds'   => array( 'container', 'widget' ),
			'shape'   => 'color',
			'devices' => true,
			'level'   => 1,
			'batch'   => 'colors',
		),
		'border_color'     => array(
			'control' => 'border_color',
			'kinds'   => array( 'container', 'widget' ),
			'shape'   => 'color',
			'devices' => true,
			'level'   => 1,
			'batch'   => 'colors',
		),
		'border_width'     => array(
			'control' => 'border_width',
			'kinds'   => array( 'container', 'widget' ),
			'shape'   => 'dimensions',
			'devices' => true,
			'level'   => 1,
			'batch'   => 'finetune',
		),
		'border_radius'    => array(
			'control' => 'border_radius',
			'kinds'   => array( 'container', 'widget' ),
			'shape'   => 'dimensions',
			'devices' => true,
			'level'   => 1,
			'batch'   => 'finetune',
		),
		'box_shadow'       => array(
			'control' => 'box_shadow',
			'kinds'   => array( 'container', 'widget' ),
			'shape'   => 'shadow',
			'devices' => false,
			'level'   => 1,
			'batch'   => 'finetune',
		),
		// Spacing ---------------------------------------------------------------
		'section_gap'      => array(
			'control' => 'flex_gap',
			'kinds'   => array( 'container' ),
			'shape'   => 'gaps',
			'devices' => true,
			'level'   => 3,
			'batch'   => 'spacing',
		),
		'padding_top'      => array(
			'control' => 'padding',
			'kinds'   => array( 'container', 'widget' ),
			'shape'   => 'dimensions',
			'sides'   => array( 'top' ),
			'devices' => true,
			'level'   => 3,
			'batch'   => 'spacing',
		),
		'padding_bottom'   => array(
			'control' => 'padding',
			'kinds'   => array( 'container', 'widget' ),
			'shape'   => 'dimensions',
			'sides'   => array( 'bottom' ),
			'devices' => true,
			'level'   => 3,
			'batch'   => 'spacing',
		),
		'margin_top'       => array(
			'control' => 'margin',
			'kinds'   => array( 'container', 'widget' ),
			'shape'   => 'dimensions',
			'sides'   => array( 'top' ),
			'devices' => true,
			'level'   => 3,
			'batch'   => 'spacing',
		),
		'margin_bottom'    => array(
			'control' => 'margin',
			'kinds'   => array( 'container', 'widget' ),
			'shape'   => 'dimensions',
			'sides'   => array( 'bottom' ),
			'devices' => true,
			'level'   => 3,
			'batch'   => 'spacing',
		),
		// Dimensions and layout -------------------------------------------------
		'max_width'        => array(
			'control'    => 'boxed_width',
			'companion' => 'content_width',
			'companion_value' => 'boxed',
			'kinds'      => array( 'container' ),
			'shape'      => 'slider',
			'devices'    => false,
			'level'      => 3,
			'batch'      => 'dimensions',
			'requires'   => 'boxed_content_width',
		),
		'width'            => array(
			'control' => 'width',
			'kinds'   => array( 'container' ),
			'shape'   => 'slider_percent',
			'devices' => true,
			'level'   => 3,
			'batch'   => 'widths',
		),
		'min_height'       => array(
			'control' => 'min_height',
			'kinds'   => array( 'container' ),
			'shape'   => 'slider',
			'devices' => true,
			'level'   => 3,
			'batch'   => 'dimensions',
		),
		'flex_direction'   => array(
			'control' => 'flex_direction',
			'kinds'   => array( 'container' ),
			'shape'   => 'enum:row,column,row-reverse,column-reverse',
			'devices' => true,
			'level'   => 3,
			'batch'   => 'direction',
		),
		'align_items'      => array(
			'control' => 'flex_align_items',
			'kinds'   => array( 'container' ),
			'shape'   => 'enum:flex-start,center,flex-end,stretch,baseline',
			'devices' => true,
			'level'   => 3,
			'batch'   => 'direction',
		),
		'justify_content'  => array(
			'control' => 'flex_justify_content',
			'kinds'   => array( 'container' ),
			'shape'   => 'enum:flex-start,center,flex-end,space-between,space-around,space-evenly',
			'devices' => true,
			'level'   => 3,
			'batch'   => 'direction',
		),
		'flex_wrap'        => array(
			'control' => 'flex_wrap',
			'kinds'   => array( 'container' ),
			'shape'   => 'enum:wrap,nowrap,reverse',
			'devices' => true,
			'level'   => 3,
			'batch'   => 'direction',
		),
	);

	/**
	 * Controls whose current value must be inspected before a correction is written.
	 *
	 * @var array<int, string>
	 */
	private $side_properties = array( 'padding_top', 'padding_bottom', 'margin_top', 'margin_bottom' );

	/**
	 * Cached entry lookup.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $cache = array();

	/**
	 * Return the whole whitelist, keyed by comparison property.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all() {
		return $this->map;
	}

	/**
	 * Return the entries that belong to a correction level.
	 *
	 * @param int $level Correction level.
	 * @return array<int, string>
	 */
	public function properties_for_level( $level ) {
		$properties = array();
		foreach ( $this->map as $property => $entry ) {
			if ( isset( $entry['level'] ) && (int) $entry['level'] === (int) $level ) {
				$properties[] = $property;
			}
		}
		return $properties;
	}

	/**
	 * Return the entry for a comparison property.
	 *
	 * @param string $property Comparison property.
	 * @return array<string, mixed>|null
	 */
	public function get( $property ) {
		if ( ! is_string( $property ) || '' === $property ) {
			return null;
		}
		if ( isset( $this->cache[ $property ] ) ) {
			return $this->cache[ $property ];
		}
		$this->cache[ $property ] = isset( $this->map[ $property ] ) ? $this->map[ $property ] : null;
		return $this->cache[ $property ];
	}

	/**
	 * Return whether a comparison property is writable at all.
	 *
	 * @param string $property Comparison property.
	 * @return bool
	 */
	public function is_writable( $property ) {
		return null !== $this->get( $property );
	}

	/**
	 * Return the correction level a property belongs to.
	 *
	 * @param string $property Comparison property.
	 * @return int Zero when the property is not writable.
	 */
	public function level( $property ) {
		$entry = $this->get( $property );
		return null !== $entry && isset( $entry['level'] ) ? (int) $entry['level'] : 0;
	}

	/**
	 * Return the batch a property is applied in.
	 *
	 * @param string $property Comparison property.
	 * @return string
	 */
	public function batch( $property ) {
		$entry = $this->get( $property );
		return null !== $entry && isset( $entry['batch'] ) ? (string) $entry['batch'] : 'finetune';
	}

	/**
	 * Return the Elementor control key for a property, element type, and device.
	 *
	 * A device other than `desktop` produces the `_<device>` control that Elementor
	 * reads from the document, so a tablet correction never touches the desktop
	 * value.
	 *
	 * @param string $property  Comparison property.
	 * @param string $el_type   Elementor element type.
	 * @param string $device    Device key.
	 * @return string Empty string when the control is not valid here.
	 */
	public function control( $property, $el_type, $device = 'desktop' ) {
		$entry = $this->get( $property );
		if ( null === $entry ) {
			return '';
		}
		if ( ! $this->applies_to_type( $entry, $el_type ) ) {
			return '';
		}
		$device = $this->device( $device );
		if ( 'desktop' !== $device && empty( $entry['devices'] ) ) {
			return '';
		}
		return 'desktop' === $device ? (string) $entry['control'] : (string) $entry['control'] . '_' . $device;
	}

	/**
	 * Return the companion control a property must set alongside its own control.
	 *
	 * @param string $property Comparison property.
	 * @return array{control: string, value: string}|null
	 */
	public function companion( $property ) {
		$entry = $this->get( $property );
		if ( null === $entry || empty( $entry['companion'] ) ) {
			return null;
		}
		return array(
			'control' => (string) $entry['companion'],
			'value'   => (string) $entry['companion_value'],
		);
	}

	/**
	 * Return the extra condition a property needs before it can be written.
	 *
	 * @param string $property Comparison property.
	 * @return string
	 */
	public function requirement( $property ) {
		$entry = $this->get( $property );
		return null !== $entry && isset( $entry['requires'] ) ? (string) $entry['requires'] : '';
	}

	/**
	 * Return whether a property writes one side of a dimensions control.
	 *
	 * @param string $property Comparison property.
	 * @return bool
	 */
	public function is_side_property( $property ) {
		return in_array( $property, $this->side_properties, true );
	}

	/**
	 * Coerce a comparison value into the Elementor value for a property.
	 *
	 * The returned value is the only thing the writer is ever allowed to store.
	 * A value that cannot be coerced safely returns null, and the correction is
	 * reported as blocked rather than written approximately.
	 *
	 * @param string $property Comparison property.
	 * @param mixed  $value    Comparison value.
	 * @return array<string, mixed>|null
	 */
	public function coerce( $property, $value ) {
		$entry = $this->get( $property );
		if ( null === $entry ) {
			return null;
		}
		$shape = isset( $entry['shape'] ) ? (string) $entry['shape'] : '';

		// Coercion is idempotent. The validator coerces a value and hands the result
		// to the writer, which coerces it again before writing, and a value that is
		// already in the control's own shape would otherwise be refused by the second
		// pass. The guard still refuses anything that is not exactly the shape the
		// control stores, so it is not a way around the checks below.
		$already = $this->already_coerced( $shape, $value );
		if ( null !== $already ) {
			return $already;
		}

		if ( 0 === strpos( $shape, 'enum:' ) ) {
			return $this->coerce_enum( $value, substr( $shape, 5 ) );
		}

		switch ( $shape ) {
			case 'slider':
				return $this->coerce_slider( $value, false );
			case 'slider_percent':
				return $this->coerce_slider( $value, true );
			case 'dimensions':
				return $this->coerce_dimensions( $value );
			case 'gaps':
				return $this->coerce_gaps( $value );
			case 'color':
				return $this->coerce_color( $value );
			case 'font_family':
				return $this->coerce_font_family( $value );
			case 'font_weight':
				return $this->coerce_font_weight( $value );
			case 'line_height':
				return $this->coerce_line_height( $value );
			case 'shadow':
				return $this->coerce_shadow( $value );
		}

		return null;
	}

	/**
	 * Return the value when it is already in the shape the control stores.
	 *
	 * Each branch accepts only the exact structure the corresponding coercer emits,
	 * so a value that merely resembles it still goes through the full checks. Null is
	 * returned when the value is not already coerced, which sends it to the normal
	 * path.
	 *
	 * @param string $shape Declared shape.
	 * @param mixed  $value Raw value.
	 * @return array<string, mixed>|string|int|null
	 */
	private function already_coerced( $shape, $value ) {
		$units = array( 'px', 'em', 'rem', 'vh', 'vw', '%' );

		$is_slider = static function ( $candidate ) use ( $units ) {
			return is_array( $candidate )
				&& array_keys( $candidate ) === array( 'size', 'unit' )
				&& isset( $candidate['size'], $candidate['unit'] )
				&& is_numeric( $candidate['size'] )
				&& in_array( $candidate['unit'], $units, true );
		};

		switch ( $shape ) {
			case 'slider':
			case 'slider_percent':
				return $is_slider( $value ) ? $value : null;

			case 'color':
				return is_string( $value ) && 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : null;

			case 'font_family':
				return is_string( $value ) && Elementor_Values::is_safe_font_family( $value ) ? trim( $value ) : null;

			case 'font_weight':
				return ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && Elementor_Values::is_safe_font_weight( $value )
					? $value
					: null;

			case 'line_height':
				return ( is_float( $value ) || is_int( $value ) ) && $value > 0 ? (float) $value : null;

			case 'dimensions':
			case 'gaps':
				if ( ! is_array( $value ) || ! isset( $value['unit'] ) || ! in_array( $value['unit'], $units, true ) ) {
					return null;
				}
				foreach ( $value as $key => $item ) {
					if ( 'unit' === $key ) {
						continue;
					}
					if ( ! is_string( $item ) || null === Comparison_Schema::length( $item ) ) {
						return null;
					}
				}
				return $value;
		}

		return null;
	}

	/**
	 * Coerce a value into a slider control.
	 *
	 * @param mixed $value       Comparison value.
	 * @param bool  $percent_only Whether only a percentage is acceptable.
	 * @return array<string, mixed>|null
	 */
	private function coerce_slider( $value, $percent_only ) {
		$length = Comparison_Schema::length( $value );
		if ( null === $length ) {
			return null;
		}
		if ( $length < 0 || $length > 10000 ) {
			return null;
		}
		if ( $percent_only ) {
			return array(
				'size' => (float) $length,
				'unit' => '%',
			);
		}
		$slider = Elementor_Values::slider( $length . 'px' );
		if ( $slider['size'] <= 0 ) {
			return null;
		}
		return $slider;
	}

	/**
	 * Coerce a value into a full dimensions control.
	 *
	 * @param mixed $value Comparison value.
	 * @return array<string, mixed>|null
	 */
	private function coerce_dimensions( $value ) {
		$length = Comparison_Schema::length( $value );
		if ( null === $length || $length < 0 || $length > 10000 ) {
			return null;
		}
		$box = Elementor_Values::dimensions( $length . 'px' );
		if ( null === $box ) {
			return null;
		}
		return $box;
	}

	/**
	 * Coerce a value into a gaps control.
	 *
	 * @param mixed $value Comparison value.
	 * @return array<string, mixed>|null
	 */
	private function coerce_gaps( $value ) {
		$length = Comparison_Schema::length( $value );
		if ( null === $length || $length < 0 || $length > 1000 ) {
			return null;
		}
		return Elementor_Values::gaps( $length . 'px' );
	}

	/**
	 * Coerce a value into a colour control.
	 *
	 * @param mixed $value Comparison value.
	 * @return string|null
	 */
	private function coerce_color( $value ) {
		if ( is_array( $value ) ) {
			$value = isset( $value['hex'] ) ? '#' . $value['hex'] : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( ! Elementor_Values::is_safe_color( $value ) ) {
			return null;
		}
		// A comparison value is already normalized, so a hex form is stored in hex
		// form to keep the value the review screen showed the user.
		$normalized = Comparison_Schema::color( $value );
		return is_array( $normalized ) ? '#' . $normalized['hex'] : $value;
	}

	/**
	 * Coerce a value into a font family control.
	 *
	 * @param mixed $value Comparison value.
	 * @return string|null
	 */
	private function coerce_font_family( $value ) {
		if ( ! is_string( $value ) || ! Elementor_Values::is_safe_font_family( $value ) ) {
			return null;
		}
		return trim( $value );
	}

	/**
	 * Coerce a value into a font weight control.
	 *
	 * @param mixed $value Comparison value.
	 * @return string|int|null
	 */
	private function coerce_font_weight( $value ) {
		if ( ! Elementor_Values::is_safe_font_weight( $value ) ) {
			return null;
		}
		return is_string( $value ) ? strtolower( trim( $value ) ) : (string) (int) $value;
	}

	/**
	 * Coerce a value into a line height control.
	 *
	 * @param mixed $value Comparison value.
	 * @return array<string, mixed>|null
	 */
	private function coerce_line_height( $value ) {
		if ( ! Elementor_Values::is_safe_line_height( $value ) ) {
			return null;
		}
		return array(
			'size' => (float) $value,
			'unit' => 'em',
		);
	}

	/**
	 * Coerce a value into a box shadow control.
	 *
	 * @param mixed $value Comparison value.
	 * @return array<string, mixed>|null
	 */
	private function coerce_shadow( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['color'] ) ) {
			return null;
		}
		$color = $this->coerce_color( $value['color'] );
		if ( null === $color ) {
			return null;
		}
		$offset_x = $this->shadow_length( isset( $value['x'] ) ? $value['x'] : 0 );
		$offset_y = $this->shadow_length( isset( $value['y'] ) ? $value['y'] : 0 );
		$blur     = $this->shadow_length( isset( $value['blur'] ) ? $value['blur'] : 0 );
		$spread   = $this->shadow_length( isset( $value['spread'] ) ? $value['spread'] : 0 );
		if ( null === $offset_x || null === $offset_y || null === $blur || null === $spread ) {
			return null;
		}
		$parts = array( $offset_x . 'px', $offset_y . 'px', $blur . 'px', $spread . 'px', $color );
		return array(
			'color'  => $color,
			'x'      => $offset_x,
			'y'      => $offset_y,
			'shadow' => implode( ' ', $parts ),
		);
	}

	/**
	 * Normalize one shadow component.
	 *
	 * @param mixed $value Raw component.
	 * @return float|null
	 */
	private function shadow_length( $value ) {
		$length = Comparison_Schema::length( $value );
		if ( null === $length || $length < 0 || $length > 500 ) {
			return null;
		}
		return round( (float) $length, 2 );
	}

	/**
	 * Coerce a value into a closed enumeration.
	 *
	 * @param mixed  $value  Comparison value.
	 * @param string $allowed Comma separated allowed values.
	 * @return string|null
	 */
	private function coerce_enum( $value, $allowed ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = strtolower( trim( $value ) );
		$value = str_replace( '-', '_', $value );
		$value = str_replace( ' ', '_', $value );
		$list  = array_map( 'trim', explode( ',', $allowed ) );
		$list  = array_map(
			static function ( $item ) {
				return str_replace( ' ', '_', strtolower( $item ) );
			},
			$list
		);
		return in_array( $value, $list, true ) ? $value : null;
	}

	/**
	 * Return whether an entry is valid on an element type.
	 *
	 * @param array<string, mixed> $entry   Map entry.
	 * @param string               $el_type Elementor element type.
	 * @return bool
	 */
	private function applies_to_type( array $entry, $el_type ) {
		$kinds = isset( $entry['kinds'] ) && is_array( $entry['kinds'] ) ? $entry['kinds'] : array();
		return in_array( $el_type, $kinds, true );
	}

	/**
	 * Return a supported device key.
	 *
	 * @param mixed $device Raw device.
	 * @return string
	 */
	public function device( $device ) {
		return in_array( $device, array_keys( Validation_Limits::VIEWPORTS ), true ) ? (string) $device : 'desktop';
	}

	/**
	 * Return the sides a side-property writes.
	 *
	 * @param string $property Comparison property.
	 * @return array<int, string>
	 */
	public function sides( $property ) {
		$entry = $this->get( $property );
		return null !== $entry && isset( $entry['sides'] ) && is_array( $entry['sides'] ) ? $entry['sides'] : array();
	}

	/**
	 * Return a safe display label for a property.
	 *
	 * @param string $property Comparison property.
	 * @return string
	 */
	public function label( $property ) {
		$readable = str_replace( '_', ' ', (string) $property );
		return ucfirst( $readable );
	}

	/**
	 * Read a control value out of a settings map.
	 *
	 * The map is passed in rather than being reached through the element, because
	 * Elementor keeps a desktop value in the element settings and a responsive
	 * override in a separate map keyed by element id. Nesting a device override
	 * under the control name would collide with the control's own desktop value, so
	 * the two are read from two different maps and the caller decides which.
	 *
	 * @param array<string, mixed> $map     Settings or responsive overrides.
	 * @param string               $control Control key.
	 * @return mixed Null when the control is not set.
	 */
	public function read_control( array $map, $control ) {
		if ( ! is_string( $control ) || '' === $control ) {
			return null;
		}

		return isset( $map[ $control ] ) ? $map[ $control ] : null;
	}

	/**
	 * Write a control value into a settings map.
	 *
	 * The companion `read_control()` decides where the value belongs, so a write
	 * can never land somewhere a read would not find it.
	 *
	 * @param array<string, mixed> $map     Settings or responsive overrides, by
	 *                                       reference.
	 * @param string               $control Control key.
	 * @param mixed                $value   Value to write.
	 * @return void
	 */
	public function write_control( array &$map, $control, $value ) {
		if ( ! is_string( $control ) || '' === $control ) {
			return;
		}
		$map[ $control ] = $value;
	}

	/**
	 * Return the device a control name is scoped to.
	 *
	 * A desktop control is not suffixed, so this only recognises a device the
	 * whitelist actually declares. Elementor names its responsive overrides with
	 * this same suffix, which is how a device control is told apart from a
	 * differently named desktop control.
	 *
	 * @param string $control Control key.
	 * @return string Device key, or an empty string for a desktop control.
	 */
	public function control_device( $control ) {
		foreach ( array_keys( Validation_Limits::VIEWPORTS ) as $device ) {
			$device = (string) $device;
			if ( 'desktop' === $device ) {
				continue;
			}
			$suffix = '_' . $device;
			if ( strlen( $control ) > strlen( $suffix ) && substr( $control, -strlen( $suffix ) ) === $suffix ) {
				return $device;
			}
		}
		return '';
	}
}
