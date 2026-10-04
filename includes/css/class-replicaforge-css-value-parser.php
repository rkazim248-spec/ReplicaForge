<?php
/**
 * Phase 8: shared CSS value parsing.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Parses the CSS value shapes Phase 8 needs into structured data.
 *
 * The layout, gradient, shadow, and border engines all need to read the same
 * handful of value grammars: a length, a color, a list of tracks, a list of stops.
 * Parsing each of them separately in each engine is how four slightly different
 * answers to "what is 12px" end up disagreeing with each other, so the grammars
 * live here once and every consumer gets the same reading of the same text.
 *
 * Every parser is total. A value it does not understand returns a `supported =>
 * false` result rather than a guess, because a wrong parse that looks right is
 * worse than an honest "not understood" that the caller can record as a
 * limitation.
 */
final class Css_Value_Parser {

	/**
	 * Maximum length of a single value parsed.
	 *
	 * A value longer than this is not a real declaration, and parsing it would be
	 * a way to make the parser do unbounded work on a hostile stylesheet.
	 */
	const MAX_VALUE_LENGTH = 4096;

	/**
	 * Maximum number of items in a parsed list.
	 */
	const MAX_ITEMS = 64;

	/**
	 * Maximum nesting depth when splitting a function's arguments.
	 */
	const MAX_DEPTH = 8;

	/**
	 * Split a value on top-level separators, ignoring separators inside functions
	 * and quoted strings.
	 *
	 * A naive explode on a space or comma would split `rgba(0, 0, 0, 0.5)` into
	 * four pieces, and a comma inside a function is extremely common in exactly
	 * the values this parser exists to read.
	 *
	 * @param string $value     Raw value.
	 * Any single character is accepted as a separator, not only a comma or a space.
	 * A slash separates the two radii of an elliptical border radius and the two lines
	 * of a grid placement, so treating it as ordinary text returned one token where
	 * the caller expected two.
	 *
	 * @param string $separator A single separator character, or a space for whitespace.
	 * @return array<int, string>
	 */
	public static function split( $value, $separator = ' ' ) {
		$value = self::bound( $value );
		if ( '' === $value ) {
			return array();
		}

		$parts = array();
		$depth = 0;
		$quote = '';
		$token = '';

		$length = strlen( $value );
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $value[ $index ];

			if ( '' !== $quote ) {
				$token .= $char;
				if ( $char === $quote && ( $index === 0 || '\\' !== $value[ $index - 1 ] ) ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				$token .= $char;
				continue;
			}

			if ( '(' === $char ) {
				$depth++;
				$token .= $char;
				continue;
			}

			if ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
				$token .= $char;
				continue;
			}

			if ( 0 === $depth ) {
				$is_separator = ( ' ' === $separator )
					? ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char )
					: ( 1 === strlen( $separator ) && $char === $separator );
				if ( $is_separator ) {
					// A run of separators is one separator, so `1px  2px` is two items.
					if ( '' !== trim( $token ) ) {
						$parts[] = trim( $token );
					}
					$token = '';
					continue;
				}
			}

			$token .= $char;
		}

		if ( '' !== trim( $token ) ) {
			$parts[] = trim( $token );
		}

		return array_slice( $parts, 0, self::MAX_ITEMS );
	}

	/**
	 * Return the arguments of a function call, or an empty array when the value is
	 * not that function.
	 *
	 * The separator is a parameter rather than a guess. `linear-gradient()` separates
	 * its stops with commas while `translate()` separates its arguments with spaces,
	 * and a call such as `linear-gradient(90deg, #fff, #000)` splits into the same
	 * number of items either way, so inferring the separator from the item count
	 * cannot work.
	 *
	 * @param string $value     Raw value.
	 * @param string $function  Function name without parentheses.
	 * @param string $separator Argument separator: a comma or whitespace.
	 * @return array<int, string>
	 */
	public static function function_args( $value, $function, $separator = ',' ) {
		$value = self::bound( $value );
		if ( '' === $value ) {
			return array();
		}

		$pattern = '/^\s*' . preg_quote( $function, '/' ) . '\s*\((.*)\)\s*$/is';
		if ( ! preg_match( $pattern, $value, $matches ) ) {
			return array();
		}

		$inner = $matches[1];
		if ( strlen( $inner ) > self::MAX_VALUE_LENGTH ) {
			return array();
		}

		return self::split( $inner, ',' === $separator ? ',' : ' ' );
	}

	/**
	 * Return the name and arguments of any function in a value.
	 *
	 * `background: linear-gradient(...) no-repeat center` mixes a function with
	 * bare keywords, so a caller that has to handle both needs them separated.
	 *
	 * @param string $value Raw value.
	 * @return array{function: string, args: array<int, string>, rest: array<int, string>}|null
	 */
	public static function first_function( $value ) {
		$value = self::bound( $value );
		if ( '' === $value || false === strpos( $value, '(' ) ) {
			return null;
		}

		if ( ! preg_match( '/^\s*([a-z-]+)\s*\((.*)\)([^()]*)$/is', $value, $matches ) ) {
			return null;
		}

		return array(
			'function' => strtolower( $matches[1] ),
			'args'     => self::split( $matches[2], ',' ),
			'rest'     => self::split( $matches[3], ' ' ),
		);
	}

	/**
	 * Parse a CSS length into pixels.
	 *
	 * Returns null for a value with no length, and for a unit that cannot be
	 * converted without knowing a font size or a viewport, because guessing those
	 * would put a fabricated number into the representation.
	 *
	 * @param string $value      Raw value.
	 * @param float  $font_size  Root font size in pixels, for em and rem.
	 * @param float  $viewport   Viewport width in pixels, for vw and vh.
	 * @return array<string, mixed>|null
	 */
	public static function length( $value, $font_size = 16.0, $viewport = 1440.0 ) {
		$value = self::bound( $value );
		if ( '' === $value ) {
			return null;
		}

		// A bare zero needs no unit, and `0px` and `0%` are both zero.
		if ( preg_match( '/^([+-]?(?:\d+\.?\d*|\.\d+))\s*(px|em|rem|pt|pc|vw|vh|vmin|vmax|cm|mm|in|q|%)?$/i', $value, $matches ) ) {
			$number = (float) $matches[1];
			$unit   = isset( $matches[2] ) ? strtolower( $matches[2] ) : 'px';

			if ( 0.0 === $number ) {
				return array(
					'value'  => 0.0,
					'unit'   => $unit,
					'pixels' => 0.0,
					'absolute' => true,
				);
			}

			$multiplier = self::unit_multiplier( $unit, $font_size, $viewport );
			if ( null === $multiplier ) {
				// A relative unit whose basis is unknown. The number and unit are
				// reported so the caller can decide, and pixels stays null so it
				// cannot be used as if it were known.
				return array(
					'value'    => $number,
					'unit'     => $unit,
					'pixels'   => null,
					'relative' => true,
					'absolute' => false,
				);
			}

			return array(
				'value'    => $number,
				'unit'     => $unit,
				'pixels'   => round( $number * $multiplier, 4 ),
				'relative' => false,
				'absolute' => true,
			);
		}

		// calc() and friends are recorded rather than evaluated. Evaluating a calc
		// chain correctly is a browser's job, and an approximation here would feed a
		// fabricated geometry into the Elementor document.
		if ( preg_match( '/^(calc|min|max|clamp)\s*\(/i', $value ) ) {
			return array(
				'value'    => $value,
				'unit'     => 'calc',
				'pixels'   => null,
				'relative' => true,
				'absolute' => false,
			);
		}

		return null;
	}

	/**
	 * Return the pixel multiplier for a unit, or null when it is not convertible.
	 *
	 * @param string $unit      Unit.
	 * @param float  $font_size Root font size in pixels.
	 * @param float  $viewport  Viewport size in pixels.
	 * @return float|null
	 */
	private static function unit_multiplier( $unit, $font_size, $viewport ) {
		$font_size = $font_size > 0 ? $font_size : 16.0;
		$viewport  = $viewport > 0 ? $viewport : 1440.0;

		switch ( $unit ) {
			case 'px':
				return 1.0;
			case 'pt':
				// A physical unit: 1pt is 1/72 inch and an inch is 96 CSS pixels.
				return 96.0 / 72.0;
			case 'pc':
				// 1pc is 12pt.
				return 96.0 / 6.0;
			case 'in':
				return 96.0;
			case 'cm':
				return 96.0 / 2.54;
			case 'mm':
				return 96.0 / 25.4;
			case 'q':
				return 96.0 / 101.6;
			case 'em':
				return $font_size;
			case 'rem':
				return $font_size;
			case 'vw':
				return $viewport / 100.0;
			case 'vh':
				return $viewport / 100.0;
			case 'vmin':
				return min( $viewport, $viewport ) / 100.0;
			case 'vmax':
				return $viewport / 100.0;
			default:
				return null;
		}
	}

	/**
	 * Parse a CSS color into normalized form.
	 *
	 * Returns hex, rgb, and hsl together so a consumer that needs an exact color
	 * space (a gradient stop) and one that needs a comparable key (token detection)
	 * both work from the same parse.
	 *
	 * @param string $value Raw value.
	 * @return array<string, mixed>|null
	 */
	public static function color( $value ) {
		$value = strtolower( self::bound( $value ) );
		if ( '' === $value ) {
			return null;
		}

		$out = array(
			'input'   => $value,
			'hex'     => null,
			'rgb'     => null,
			'hsl'     => null,
			'alpha'   => 1.0,
			'named'   => true,
			'current' => false,
		);

		if ( 'currentcolor' === $value ) {
			$out['current'] = true;
			$out['named']   = false;
			return $out;
		}

		if ( 'transparent' === $value ) {
			$out['hex']   = '#000000';
			$out['rgb']   = array( 0, 0, 0 );
			$out['hsl']   = array( 0, 0, 0 );
			$out['alpha'] = 0.0;
			$out['named'] = true;
			return $out;
		}

		// A hex color.
		if ( preg_match( '/^#([0-9a-f]{3,8})$/i', $value, $matches ) ) {
			$hex = self::expand_hex( $matches[1] );
			if ( null === $hex ) {
				return null;
			}
			$out['hex']   = $hex['hex'];
			$out['rgb']   = $hex['rgb'];
			$out['hsl']   = self::rgb_to_hsl( $hex['rgb'] );
			$out['alpha'] = $hex['alpha'];
			$out['named'] = false;
			return $out;
		}

		// A functional color. alpha() and color() are recorded as unsupported rather
		// than reduced to a color they are not.
		if ( preg_match( '/^rgba?\s*\((.*)\)$/is', $value, $matches ) ) {
			$parts = self::split( $matches[1], ',' );
			if ( count( $parts ) < 3 ) {
				$parts = self::split( $matches[1], ' ' );
			}
			if ( count( $parts ) < 3 ) {
				return null;
			}
			$channels = array();
			foreach ( array_slice( $parts, 0, 3 ) as $part ) {
				$part = trim( $part );

				// The unit decides the scale, so it is read before the number. A
				// percentage channel is a share of 255; a bare number is 0-255.
				if ( preg_match( '/^([+-]?[\d.]+)%$/', $part, $percent ) ) {
					$channels[] = max( 0, min( 255, (int) round( ( (float) $percent[1] / 100.0 ) * 255 ) ) );
					continue;
				}

				// `none` is a valid component of a modern relative color and means zero.
				// It is tested before the unit is stripped, because stripping the
				// trailing letters would leave an empty string that cannot match.
				if ( 'none' === strtolower( $part ) ) {
					$channels[] = 0;
					continue;
				}

				// A channel may also carry a unit, which is ignored because the scale
				// is already fixed.
				$number = preg_replace( '/[a-z]+$/i', '', $part );
				if ( ! is_numeric( $number ) ) {
					// A calc() or var() is not convertible here, so the whole color is
					// refused rather than half-read.
					return null;
				}
				$channels[] = max( 0, min( 255, (int) round( (float) $number ) ) );
			}
			$out['rgb']   = $channels;
			$out['hex']   = self::rgb_to_hex( $channels );
			$out['hsl']   = self::rgb_to_hsl( $channels );
			$out['alpha'] = isset( $parts[3] ) ? self::alpha( $parts[3] ) : 1.0;
			$out['named'] = false;
			return $out;
		}

		// hsl() and hsla().
		if ( preg_match( '/^hsla?\s*\((.*)\)$/is', $value, $matches ) ) {
			$parts = self::split( $matches[1], ',' );
			if ( count( $parts ) < 3 ) {
				$parts = self::split( $matches[1], ' ' );
			}
			if ( count( $parts ) < 3 ) {
				return null;
			}
			$hue        = self::parse_angle( $parts[0] );
			$saturation = self::percentage( $parts[1] );
			$lightness  = self::percentage( $parts[2] );
			if ( null === $hue || null === $saturation || null === $lightness ) {
				return null;
			}
			$rgb = self::hsl_to_rgb( $hue, $saturation, $lightness );
			$out['rgb']   = $rgb;
			$out['hex']   = self::rgb_to_hex( $rgb );
			$out['hsl']   = array( round( $hue, 2 ), round( $saturation, 2 ), round( $lightness, 2 ) );
			$out['alpha'] = isset( $parts[3] ) ? self::alpha( $parts[3] ) : 1.0;
			$out['named'] = false;
			return $out;
		}

		// A named color. The full CSS list is long and the common ones cover the
		// overwhelming majority of real pages, so an unknown name is reported as
		// unparsed rather than mapped to a wrong color.
		$named = self::named_color( $value );
		if ( null !== $named ) {
			$out['hex']   = self::rgb_to_hex( $named );
			$out['rgb']   = $named;
			$out['hsl']   = self::rgb_to_hsl( $named );
			$out['named'] = true;
			return $out;
		}

		return null;
	}

	/**
	 * Return a zero-padded lowercase hex color for an rgb triple.
	 *
	 * @param array<int, int> $rgb Channels.
	 * @return string
	 */
	public static function rgb_to_hex( array $rgb ) {
		return sprintf(
			'#%02x%02x%02x',
			max( 0, min( 255, (int) $rgb[0] ) ),
			max( 0, min( 255, (int) $rgb[1] ) ),
			max( 0, min( 255, (int) $rgb[2] ) )
		);
	}

	/**
	 * Convert an rgb triple to hsl.
	 *
	 * @param array<int, int> $rgb Channels.
	 * @return array<float, float, float>
	 */
	public static function rgb_to_hsl( array $rgb ) {
		$r = $rgb[0] / 255.0;
		$g = $rgb[1] / 255.0;
		$b = $rgb[2] / 255.0;

		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2.0;

		if ( $max === $min ) {
			return array( 0.0, 0.0, round( $l, 4 ) );
		}

		$delta = $max - $min;
		$s     = ( $l > 0.5 ) ? $delta / ( 2.0 - $max - $min ) : $delta / ( $max + $min );

		switch ( $max ) {
			case $r:
				$h = ( ( $g - $b ) / $delta ) + ( $g < $b ? 6.0 : 0.0 );
				break;
			case $g:
				$h = ( ( $b - $r ) / $delta ) + 2.0;
				break;
			default:
				$h = ( ( $r - $g ) / $delta ) + 4.0;
		}

		return array( round( ( $h / 6.0 ) * 100.0, 2 ), round( $s, 4 ), round( $l, 4 ) );
	}

	/**
	 * Convert hsl to an rgb triple.
	 *
	 * @param float $hue        Degrees.
	 * @param float $saturation 0–1.
	 * @param float $lightness  0–1.
	 * @return array<int, int>
	 */
	public static function hsl_to_rgb( $hue, $saturation, $lightness ) {
		$hue        = fmod( fmod( $hue, 360.0 ) + 360.0, 360.0 ) / 360.0;
		$saturation = max( 0.0, min( 1.0, $saturation ) );
		$lightness = max( 0.0, min( 1.0, $lightness ) );

		if ( 0.0 === $saturation ) {
			$level = (int) round( $lightness * 255 );
			return array( $level, $level, $level );
		}

		$q = $lightness < 0.5 ? $lightness * ( 1.0 + $saturation ) : $lightness + $saturation - ( $lightness * $saturation );
		$p = ( 2.0 * $lightness ) - $q;

		return array(
			(int) round( self::hue_to_channel( $p, $q, $hue + ( 1.0 / 3.0 ) ) * 255 ),
			(int) round( self::hue_to_channel( $p, $q, $hue ) * 255 ),
			(int) round( self::hue_to_channel( $p, $q, $hue - ( 1.0 / 3.0 ) ) * 255 ),
		);
	}

	/**
	 * Convert one hue channel of an hsl triple.
	 *
	 * @param float $p   Lowest channel.
	 * @param float $q   Highest channel.
	 * @param float $t   Hue position.
	 * @return float
	 */
	private static function hue_to_channel( $p, $q, $t ) {
		if ( $t < 0.0 ) {
			$t += 1.0;
		}
		if ( $t > 1.0 ) {
			$t -= 1.0;
		}
		if ( $t < 1.0 / 6.0 ) {
			return $p + ( $q - $p ) * 6.0 * $t;
		}
		if ( $t < 1.0 / 2.0 ) {
			return $q;
		}
		if ( $t < 2.0 / 3.0 ) {
			return $p + ( $q - $p ) * ( ( 2.0 / 3.0 ) - $t ) * 6.0;
		}
		return $p;
	}

	/**
	 * Parse an angle into degrees.
	 *
	 * @param string $value Raw value.
	 * @return float|null
	 */
	public static function parse_angle( $value ) {
		$value = strtolower( self::bound( $value ) );
		if ( '' === $value ) {
			return null;
		}
		if ( 'to top' === $value ) {
			return 0.0;
		}
		if ( 'to right' === $value ) {
			return 90.0;
		}
		if ( 'to bottom' === $value ) {
			return 180.0;
		}
		if ( 'to left' === $value ) {
			return 270.0;
		}
		if ( preg_match( '/^([+-]?(?:\d+\.?\d*|\.\d+))\s*(deg|grad|rad|turn)?$/', $value, $matches ) ) {
			$number = (float) $matches[1];
			$unit   = isset( $matches[2] ) ? $matches[2] : 'deg';
			switch ( $unit ) {
				case 'grad':
					return round( $number * 0.9, 4 );
				case 'rad':
					return round( rad2deg( $number ), 4 );
				case 'turn':
					return round( $number * 360.0, 4 );
				default:
					return round( $number, 4 );
			}
		}
		return null;
	}

	/**
	 * Parse a percentage, returning 0–1.
	 *
	 * @param string $value Raw value.
	 * @return float|null
	 */
	public static function percentage( $value ) {
		$value = trim( self::bound( $value ) );
		if ( preg_match( '/^([+-]?(?:\d+\.?\d*|\.\d+))%$/', $value, $matches ) ) {
			return (float) $matches[1] / 100.0;
		}
		return null;
	}

	/**
	 * Parse an alpha value, returning 0–1.
	 *
	 * @param string $value Raw value.
	 * @return float
	 */
	private static function alpha( $value ) {
		$value = trim( self::bound( $value ) );
		if ( preg_match( '/^([\d.]+)%$/', $value, $matches ) ) {
			return max( 0.0, min( 1.0, (float) $matches[1] / 100.0 ) );
		}
		if ( is_numeric( $value ) ) {
			return max( 0.0, min( 1.0, (float) $value ) );
		}
		return 1.0;
	}

	/**
	 * Expand a hex color of 3, 4, 6, or 8 digits.
	 *
	 * @param string $digits Hex digits without the hash.
	 * @return array<string, mixed>|null
	 */
	private static function expand_hex( $digits ) {
		$digits = strtolower( $digits );
		$length = strlen( $digits );

		if ( 3 === $length || 4 === $length ) {
			$expanded = '';
			for ( $index = 0; $index < $length; $index++ ) {
				$expanded .= $digits[ $index ] . $digits[ $index ];
			}
			$digits = $expanded;
			$length = strlen( $digits );
		}

		if ( 6 !== $length && 8 !== $length ) {
			return null;
		}

		return array(
			'hex'   => '#' . substr( $digits, 0, 6 ),
			'rgb'   => array(
				hexdec( substr( $digits, 0, 2 ) ),
				hexdec( substr( $digits, 2, 2 ) ),
				hexdec( substr( $digits, 4, 2 ) ),
			),
			'alpha' => 8 === $length ? round( hexdec( substr( $digits, 6, 2 ) ) / 255.0, 4 ) : 1.0,
		);
	}

	/**
	 * Return the RGB triple for a CSS named color.
	 *
	 * @param string $name Color name.
	 * @return array<int, int>|null
	 */
	public static function named_color( $name ) {
		static $map = null;
		if ( null === $map ) {
			// The extended set. A name outside it returns null rather than a guess.
			$map = array(
				'aliceblue' => array( 240, 248, 255 ), 'antiquewhite' => array( 250, 235, 215 ),
				'aqua' => array( 0, 255, 255 ), 'aquamarine' => array( 127, 255, 212 ),
				'azure' => array( 240, 255, 255 ), 'beige' => array( 245, 245, 220 ),
				'bisque' => array( 255, 228, 196 ), 'black' => array( 0, 0, 0 ),
				'blanchedalmond' => array( 255, 235, 205 ), 'blue' => array( 0, 0, 255 ),
				'blueviolet' => array( 138, 43, 226 ), 'brown' => array( 165, 42, 42 ),
				'burlywood' => array( 222, 184, 135 ), 'cadetblue' => array( 95, 158, 160 ),
				'chartreuse' => array( 127, 255, 0 ), 'chocolate' => array( 210, 105, 30 ),
				'coral' => array( 255, 127, 80 ), 'cornflowerblue' => array( 100, 149, 237 ),
				'cornsilk' => array( 255, 248, 220 ), 'crimson' => array( 220, 20, 60 ),
				'cyan' => array( 0, 255, 255 ), 'darkblue' => array( 0, 0, 139 ),
				'darkcyan' => array( 0, 139, 139 ), 'darkgoldenrod' => array( 184, 134, 11 ),
				'darkgray' => array( 169, 169, 169 ), 'darkgreen' => array( 0, 100, 0 ),
				'darkgrey' => array( 169, 169, 169 ), 'darkkhaki' => array( 189, 183, 107 ),
				'darkmagenta' => array( 139, 0, 139 ), 'darkolivegreen' => array( 85, 107, 47 ),
				'darkorange' => array( 255, 140, 0 ), 'darkorchid' => array( 153, 50, 204 ),
				'darkred' => array( 139, 0, 0 ), 'darksalmon' => array( 233, 150, 122 ),
				'darkseagreen' => array( 143, 188, 143 ), 'darkslateblue' => array( 72, 61, 139 ),
				'darkslategray' => array( 47, 79, 79 ), 'darkslategrey' => array( 47, 79, 79 ),
				'darkturquoise' => array( 0, 206, 209 ), 'darkviolet' => array( 148, 0, 211 ),
				'deeppink' => array( 255, 20, 147 ), 'deepskyblue' => array( 0, 191, 255 ),
				'dimgray' => array( 105, 105, 105 ), 'dimgrey' => array( 105, 105, 105 ),
				'dodgerblue' => array( 30, 144, 255 ), 'firebrick' => array( 178, 34, 34 ),
				'floralwhite' => array( 255, 250, 240 ), 'forestgreen' => array( 34, 139, 34 ),
				'fuchsia' => array( 255, 0, 255 ), 'gainsboro' => array( 220, 220, 220 ),
				'ghostwhite' => array( 248, 248, 255 ), 'gold' => array( 255, 215, 0 ),
				'goldenrod' => array( 218, 165, 32 ), 'gray' => array( 128, 128, 128 ),
				'green' => array( 0, 128, 0 ), 'greenyellow' => array( 173, 255, 47 ),
				'grey' => array( 128, 128, 128 ), 'honeydew' => array( 240, 255, 240 ),
				'hotpink' => array( 255, 105, 180 ), 'indianred' => array( 205, 92, 92 ),
				'indigo' => array( 75, 0, 130 ), 'ivory' => array( 255, 255, 240 ),
				'khaki' => array( 240, 230, 140 ), 'lavender' => array( 230, 230, 250 ),
				'lavenderblush' => array( 255, 240, 245 ), 'lawngreen' => array( 124, 252, 0 ),
				'lemonchiffon' => array( 255, 250, 205 ), 'lightblue' => array( 173, 216, 230 ),
				'lightcoral' => array( 240, 128, 128 ), 'lightcyan' => array( 224, 255, 255 ),
				'lightgoldenrodyellow' => array( 250, 250, 210 ), 'lightgray' => array( 211, 211, 211 ),
				'lightgreen' => array( 144, 238, 144 ), 'lightgrey' => array( 211, 211, 211 ),
				'lightpink' => array( 255, 182, 193 ), 'lightsalmon' => array( 255, 160, 122 ),
				'lightseagreen' => array( 32, 178, 170 ), 'lightskyblue' => array( 135, 206, 250 ),
				'lightslategray' => array( 119, 136, 153 ), 'lightslategrey' => array( 119, 136, 153 ),
				'lightsteelblue' => array( 176, 196, 222 ), 'lightyellow' => array( 255, 255, 224 ),
				'lime' => array( 0, 255, 0 ), 'limegreen' => array( 50, 205, 50 ),
				'linen' => array( 250, 240, 230 ), 'magenta' => array( 255, 0, 255 ),
				'maroon' => array( 128, 0, 0 ), 'mediumaquamarine' => array( 102, 205, 170 ),
				'mediumblue' => array( 0, 0, 205 ), 'mediumorchid' => array( 186, 85, 211 ),
				'mediumpurple' => array( 147, 112, 219 ), 'mediumseagreen' => array( 60, 179, 113 ),
				'mediumslateblue' => array( 123, 104, 238 ), 'mediumspringgreen' => array( 0, 250, 154 ),
				'mediumturquoise' => array( 72, 209, 204 ), 'mediumvioletred' => array( 199, 21, 133 ),
				'midnightblue' => array( 25, 25, 112 ), 'mintcream' => array( 245, 255, 250 ),
				'mistyrose' => array( 255, 228, 225 ), 'moccasin' => array( 255, 228, 181 ),
				'navajowhite' => array( 255, 222, 173 ), 'navy' => array( 0, 0, 128 ),
				'oldlace' => array( 253, 245, 230 ), 'olive' => array( 128, 128, 0 ),
				'olivedrab' => array( 107, 142, 35 ), 'orange' => array( 255, 165, 0 ),
				'orangered' => array( 255, 69, 0 ), 'orchid' => array( 218, 112, 214 ),
				'palegoldenrod' => array( 238, 232, 170 ), 'palegreen' => array( 152, 251, 152 ),
				'paleturquoise' => array( 175, 238, 238 ), 'palevioletred' => array( 219, 112, 147 ),
				'papayawhip' => array( 255, 239, 213 ), 'peachpuff' => array( 255, 218, 185 ),
				'peru' => array( 205, 133, 63 ), 'pink' => array( 255, 192, 203 ),
				'plum' => array( 221, 160, 221 ), 'powderblue' => array( 176, 224, 230 ),
				'purple' => array( 128, 0, 128 ), 'rebeccapurple' => array( 102, 51, 153 ),
				'red' => array( 255, 0, 0 ), 'rosybrown' => array( 188, 143, 143 ),
				'royalblue' => array( 65, 105, 225 ), 'saddlebrown' => array( 139, 69, 19 ),
				'salmon' => array( 250, 128, 114 ), 'sandybrown' => array( 244, 164, 96 ),
				'seagreen' => array( 46, 139, 87 ), 'seashell' => array( 255, 245, 238 ),
				'sienna' => array( 160, 82, 45 ), 'silver' => array( 192, 192, 192 ),
				'skyblue' => array( 135, 206, 235 ), 'slateblue' => array( 106, 90, 205 ),
				'slategray' => array( 112, 128, 144 ), 'slategrey' => array( 112, 128, 144 ),
				'snow' => array( 255, 250, 250 ), 'springgreen' => array( 0, 255, 127 ),
				'steelblue' => array( 70, 130, 180 ), 'tan' => array( 210, 180, 140 ),
				'teal' => array( 0, 128, 128 ), 'thistle' => array( 216, 191, 216 ),
				'tomato' => array( 255, 99, 71 ), 'turquoise' => array( 64, 224, 208 ),
				'violet' => array( 238, 130, 238 ), 'wheat' => array( 245, 222, 179 ),
				'white' => array( 255, 255, 255 ), 'whitesmoke' => array( 245, 245, 245 ),
				'yellow' => array( 255, 255, 0 ), 'yellowgreen' => array( 154, 205, 50 ),
			);
		}
		return isset( $map[ $name ] ) ? $map[ $name ] : null;
	}

	/**
	 * Bound a value before parsing it.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function bound( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( strlen( $value ) > self::MAX_VALUE_LENGTH ) {
			// A value this long is not a real declaration. Cutting it prevents a
			// hostile stylesheet from making the parser do unbounded work, and the
			// truncated text will not match any grammar, so the result is an honest
			// "not understood".
			return substr( $value, 0, self::MAX_VALUE_LENGTH );
		}
		return $value;
	}

	/**
	 * Return a comparable key for a color, so two spellings of one color collapse.
	 *
	 * Token detection depends on recognizing `#FFF` and `rgb(255,255,255)` and
	 * `white` as the same value, or one color would become several tokens.
	 *
	 * @param string $value Raw value.
	 * @return string Empty string when the color was not understood.
	 */
	public static function color_key( $value ) {
		$parsed = self::color( $value );
		if ( null === $parsed || empty( $parsed['hex'] ) ) {
			return '';
		}
		return (string) $parsed['hex'] . ( $parsed['alpha'] < 1.0 ? '@' . round( (float) $parsed['alpha'], 2 ) : '' );
	}

	/**
	 * Return a comparable key for a length.
	 *
	 * @param string $value     Raw value.
	 * @param float  $font_size Root font size.
	 * @param float  $viewport  Viewport width.
	 * @return string
	 */
	public static function length_key( $value, $font_size = 16.0, $viewport = 1440.0 ) {
		$parsed = self::length( $value, $font_size, $viewport );
		if ( null === $parsed || ! isset( $parsed['pixels'] ) || null === $parsed['pixels'] ) {
			return '';
		}
		return rtrim( rtrim( (string) round( (float) $parsed['pixels'], 2 ), '0' ), '.' ) . 'px';
	}
}
