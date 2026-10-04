<?php
/**
 * Two-sided comparison schema for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The shared vocabulary both sides of a comparison are normalized into.
 *
 * Phase 2 produces a Design Representation 2.0 from static analysis of a remote
 * page. The generated side is produced from an Elementor document and its
 * generated stylesheet. Those two systems are never compared directly. Each side
 * is normalized into the records defined here first, and only normalized records
 * are compared.
 */
final class Comparison_Schema {

	/**
	 * Named CSS colors supported for comparison.
	 *
	 * A name outside this list is reported as not comparable rather than being
	 * guessed.
	 *
	 * @var array<string, int>
	 */
	private static $named_colors = array(
		'black'       => 0x000000,
		'white'       => 0xffffff,
		'red'         => 0xff0000,
		'green'       => 0x008000,
		'blue'        => 0x0000ff,
		'yellow'      => 0xffff00,
		'orange'      => 0xffa500,
		'purple'      => 0x800080,
		'gray'        => 0x808080,
		'grey'        => 0x808080,
		'silver'      => 0xc0c0c0,
		'navy'        => 0x000080,
		'teal'        => 0x008080,
		'maroon'      => 0x800000,
		'lime'        => 0x00ff00,
		'aqua'        => 0x00ffff,
		'cyan'        => 0x00ffff,
		'fuchsia'     => 0xff00ff,
		'magenta'     => 0xff00ff,
		'transparent' => 0x000000,
	);

	/**
	 * Return the schema version.
	 *
	 * @return string
	 */
	public static function version() {
		return Validation_Limits::SCHEMA_VERSION;
	}

	/**
	 * Build an empty side record.
	 *
	 * @param string $side Either `source` or `generated`.
	 * @return array<string, mixed>
	 */
	public static function empty_side( $side ) {
		return array(
			'schema_version' => self::version(),
			'side'           => 'source' === $side ? 'source' : 'generated',
			'page'           => array(
				'url'                 => '',
				'title'               => '',
				'type'                => 'unknown',
				'container_max_width' => null,
				'container_centered'  => null,
			),
			'sections'       => array(),
			'components'     => array(),
			'design_system'  => array(
				'colors'     => array(),
				'typography' => array(),
				'spacing'    => array(),
				'radius'     => array(),
				'shadows'    => array(),
			),
			'viewports'      => array(),
			'navigation'     => array(
				'mobile_detected' => null,
				'behavior'        => 'unknown',
				'confidence'      => 0.0,
			),
			'mapping'        => array(),
			'counters'       => array(),
			'warnings'       => array(),
		);
	}

	/**
	 * Normalize a CSS color into a comparable representation.
	 *
	 * Accepts hex, rgb(), rgba(), hsl(), hsla(), and the small named-color set.
	 * Anything else returns null so the caller reports the value as unknown
	 * instead of inventing a comparison.
	 *
	 * @param mixed $value Raw color.
	 * @return array{hex: string, rgb: array{0:int,1:int,2:int}, alpha: float}|null
	 */
	public static function color( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = strtolower( trim( $value ) );
		if ( '' === $value || strlen( $value ) > 80 ) {
			return null;
		}
		if ( Elementor_Values::is_executable( $value ) ) {
			return null;
		}

		if ( isset( self::$named_colors[ $value ] ) ) {
			$hex = sprintf( '%06x', self::$named_colors[ $value ] );
			return array(
				'hex'   => $hex,
				'rgb'   => array(
					hexdec( substr( $hex, 0, 2 ) ),
					hexdec( substr( $hex, 2, 2 ) ),
					hexdec( substr( $hex, 4, 2 ) ),
				),
				'alpha' => 'transparent' === $value ? 0.0 : 1.0,
			);
		}

		if ( 1 === preg_match( '/^#([0-9a-f]{3,8})$/', $value, $matches ) ) {
			return self::from_hex( $matches[1] );
		}

		if ( 1 === preg_match( '/^rgba?\(([^)]*)\)$/', $value, $matches ) ) {
			$parts = array_map( 'trim', explode( ',', $matches[1] ) );
			if ( count( $parts ) < 3 ) {
				return null;
			}
			$rgb = array();
			foreach ( array( 0, 1, 2 ) as $index ) {
				$number = self::clamp_channel( $parts[ $index ] );
				if ( null === $number ) {
					return null;
				}
				$rgb[ $index ] = $number;
			}
			$alpha = 1.0;
			if ( isset( $parts[3] ) ) {
				$alpha = self::clamp_alpha( $parts[3] );
			}
			return self::from_rgb( $rgb, $alpha );
		}

		if ( 1 === preg_match( '/^hsla?\(([^)]*)\)$/', $value, $matches ) ) {
			$parts = array_map( 'trim', explode( ',', $matches[1] ) );
			if ( count( $parts ) < 3 ) {
				return null;
			}
			$hue = (float) preg_replace( '/[^0-9.\-]/', '', $parts[0] );
			$sat = self::clamp_percent( $parts[1] );
			$lig = self::clamp_percent( $parts[2] );
			if ( '' === trim( $parts[0] ) || null === $sat || null === $lig ) {
				return null;
			}
			$rgb = self::hsl_to_rgb( $hue, $sat, $lig );
			$alpha = isset( $parts[3] ) ? self::clamp_alpha( $parts[3] ) : 1.0;
			return self::from_rgb( $rgb, $alpha );
		}

		return null;
	}

	/**
	 * Convert a hex triplet into a comparable color.
	 *
	 * @param string $hex Hex digits without the leading hash.
	 * @return array{hex: string, rgb: array{0:int,1:int,2:int}, alpha: float}|null
	 */
	private static function from_hex( $hex ) {
		$length = strlen( $hex );
		if ( 3 === $length || 4 === $length ) {
			$expanded = '';
			for ( $index = 0; $index < $length; $index++ ) {
				$expanded .= $hex[ $index ] . $hex[ $index ];
			}
			$hex = $expanded;
			$length = strlen( $hex );
		}
		if ( 6 !== $length && 8 !== $length ) {
			return null;
		}
		$rgb = array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
		$alpha = 1.0;
		if ( 8 === $length ) {
			$alpha = round( hexdec( substr( $hex, 6, 2 ) ) / 255, 4 );
		}
		return array(
			'hex'   => sprintf( '%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] ),
			'rgb'   => $rgb,
			'alpha' => $alpha,
		);
	}

	/**
	 * Build a comparable color from channels and alpha.
	 *
	 * @param array<int, int> $rgb   Channels.
	 * @param float           $alpha Alpha channel.
	 * @return array{hex: string, rgb: array{0:int,1:int,2:int}, alpha: float}
	 */
	private static function from_rgb( array $rgb, $alpha ) {
		$rgb = array(
			(int) max( 0, min( 255, $rgb[0] ) ),
			(int) max( 0, min( 255, $rgb[1] ) ),
			(int) max( 0, min( 255, $rgb[2] ) ),
		);
		return array(
			'hex'   => sprintf( '%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] ),
			'rgb'   => $rgb,
			'alpha' => round( (float) max( 0.0, min( 1.0, $alpha ) ), 4 ),
		);
	}

	/**
	 * Clamp one RGB channel.
	 *
	 * @param string $value Raw channel.
	 * @return int|null
	 */
	private static function clamp_channel( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return (int) max( 0, min( 255, round( (float) $value ) ) );
	}

	/**
	 * Clamp an alpha value.
	 *
	 * @param string $value Raw alpha.
	 * @return float
	 */
	private static function clamp_alpha( $value ) {
		$value = trim( (string) $value );
		if ( is_numeric( $value ) ) {
			return (float) max( 0.0, min( 1.0, (float) $value ) );
		}
		return 0 === strpos( $value, '%' ) && is_numeric( trim( $value, '%' ) )
			? (float) max( 0.0, min( 1.0, (float) trim( $value, '%' ) / 100 ) )
			: 1.0;
	}

	/**
	 * Clamp a percentage.
	 *
	 * @param string $value Raw percentage.
	 * @return float|null
	 */
	private static function clamp_percent( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || ! is_numeric( trim( $value, '%' ) ) ) {
			return null;
		}
		return (float) max( 0.0, min( 100.0, (float) trim( $value, '%' ) ) ) / 100;
	}

	/**
	 * Convert HSL to RGB.
	 *
	 * @param float $hue        Hue in degrees.
	 * @param float $saturation Saturation 0..1.
	 * @param float $lightness  Lightness 0..1.
	 * @return array<int, int>
	 */
	private static function hsl_to_rgb( $hue, $saturation, $lightness ) {
		$hue        = fmod( fmod( $hue, 360 ) + 360, 360 ) / 360;
		$complement = ( 1 - abs( ( 2 * $lightness ) - 1 ) ) * $saturation;
		$segment    = $hue * 6;
		$match      = $lightness - ( $complement / 2 );

		$red = 0.0;
		$green = 0.0;
		$blue = 0.0;
		switch ( (int) floor( $segment ) ) {
			case 0:
				$red   = $complement;
				$green = $hue * 6 * $complement;
				$blue  = 0;
				break;
			case 1:
				$red   = ( 1 - $hue ) * 6 * $complement;
				$green = $complement;
				$blue  = 0;
				break;
			case 2:
				$red   = 0;
				$green = $complement;
				$blue  = ( $hue * 2 - 1 ) * 6 * $complement;
				break;
			case 3:
				$red   = 0;
				$green = ( 1 - $hue * 2 ) * 6 * $complement;
				$blue  = $complement;
				break;
			case 4:
				$red   = $hue * 4 * 6 * $complement;
				$green = 0;
				$blue  = $complement;
				break;
			default:
				$red   = $complement;
				$green = 0;
				$blue  = ( 1 - ( $hue * 4 ) ) * 6 * $complement;
				break;
		}
		return array(
			(int) round( ( $red + $match ) * 255 ),
			(int) round( ( $green + $match ) * 255 ),
			(int) round( ( $blue + $match ) * 255 ),
		);
	}

	/**
	 * Return the weighted distance between two comparable colors.
	 *
	 * The distance is a luminance-weighted sRGB distance, so a difference in a
	 * channel a human barely perceives scores lower than one in the dominant
	 * channel. The scale is 0 (identical) to 441.67 (black versus white).
	 *
	 * @param array{rgb: array{0:int,1:int,2:int}, alpha: float}|null $expected Source color.
	 * @param array{rgb: array{0:int,1:int,2:int}, alpha: float}|null $actual   Generated color.
	 * @return float|null
	 */
	public static function color_distance( $expected, $actual ) {
		if ( ! is_array( $expected ) || ! is_array( $actual ) || ! isset( $expected['rgb'], $actual['rgb'] ) ) {
			return null;
		}
		$red_mean   = ( $expected['rgb'][0] + $actual['rgb'][0] ) / 2;
		$delta_red   = $expected['rgb'][0] - $actual['rgb'][0];
		$delta_green = $expected['rgb'][1] - $actual['rgb'][1];
		$delta_blue  = $expected['rgb'][2] - $actual['rgb'][2];

		$weight_red   = ( 2 + ( $red_mean / 256 ) ) * $delta_red * $delta_red;
		$weight_green = ( 4 + ( $red_mean / 256 ) ) * $delta_green * $delta_green;
		$weight_blue  = ( 2 + ( ( 255 - $red_mean ) / 256 ) ) * $delta_blue * $delta_blue;

		$distance = round( sqrt( $weight_red + $weight_green + $weight_blue ), 2 );
		if ( isset( $expected['alpha'], $actual['alpha'] ) && abs( $expected['alpha'] - $actual['alpha'] ) > 0.01 ) {
			// A differing alpha is reported as a fixed additional distance so a
			// transparent and an opaque color never compare as identical.
			$distance = round( $distance + 25, 2 );
		}
		return $distance;
	}

	/**
	 * Normalize a CSS length into a comparable number.
	 *
	 * Returns null when the value is not a comparable length, so an unknown
	 * value is never treated as a difference or as a match.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function length( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value || ! Elementor_Values::is_safe_length( $value ) ) {
			return null;
		}
		if ( 1 === preg_match( '/^(-?[0-9]*\.?[0-9]+)([a-z%]*)$/i', $value, $matches ) ) {
			return (float) $matches[1];
		}
		return null;
	}

	/**
	 * Normalize a font size, preferring pixels.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function font_size( $value ) {
		$length = self::length( $value );
		if ( null === $length ) {
			return null;
		}
		if ( is_string( $value ) && preg_match( '/em$|rem$/i', trim( $value ) ) ) {
			// Relative sizes cannot be compared to a pixel value without knowing
			// the inherited font size, so they are reported as not comparable.
			return null;
		}
		return $length;
	}

	/**
	 * Normalize a line height.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function line_height( $value ) {
		if ( ! is_numeric( $value ) ) {
			$length = self::length( $value );
			return null === $length ? null : round( $length, 3 );
		}
		return round( (float) $value, 3 );
	}

	/**
	 * Normalize a font weight into a comparable number.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function font_weight( $value ) {
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			$map   = array(
				'normal' => 400,
				'bold'   => 700,
			);
			if ( isset( $map[ $value ] ) ) {
				return (float) $map[ $value ];
			}
			if ( 'inherit' === $value ) {
				return null;
			}
			if ( in_array( $value, array( 'lighter', 'bolder' ), true ) ) {
				return null;
			}
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$weight = (int) $value;
		if ( $weight < 100 || $weight > 900 ) {
			return null;
		}
		return (float) $weight;
	}

	/**
	 * Normalize a font family into a comparable form.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	public static function font_family( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 160 || Elementor_Values::is_executable( $value ) ) {
			return null;
		}
		$families = array();
		foreach ( explode( ',', $value ) as $family ) {
			$family = trim( $family, " \t\n\r\0\x0B'\"" );
			if ( '' !== $family ) {
				$families[] = strtolower( $family );
			}
		}
		return empty( $families ) ? null : implode( ',', $families );
	}

	/**
	 * Return the tolerance band configuration for a property type.
	 *
	 * @param string $type Property type key.
	 * @return array<string, float>
	 */
	public static function tolerance( $type ) {
		return isset( Validation_Limits::TOLERANCES[ $type ] )
			? Validation_Limits::TOLERANCES[ $type ]
			: Validation_Limits::TOLERANCES['length'];
	}

	/**
	 * Classify a numeric difference into a band.
	 *
	 * Returns `pass` inside the small band, `partial` inside the medium band,
	 * and `fail` beyond it. A null difference is `unknown`.
	 *
	 * @param float|null $difference Absolute difference.
	 * @param string     $type       Property type key.
	 * @return string
	 */
	public static function band( $difference, $type ) {
		if ( null === $difference ) {
			return 'unknown';
		}
		$tolerance = self::tolerance( $type );
		$absolute  = abs( (float) $difference );
		if ( $absolute <= (float) $tolerance['small'] ) {
			return 'pass';
		}
		if ( $absolute <= (float) $tolerance['medium'] ) {
			return 'partial';
		}
		return 'fail';
	}
}
