<?php
/**
 * Phase 8: visual effect extraction.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Reads gradients, shadows, borders, and backgrounds into structured form.
 *
 * These four are grouped because they share the same failure mode: a shorthand
 * hides several declarations, a page may use any of three spellings for the same
 * effect, and the interesting part is usually in the longhand. Reading them
 * together also lets one decision be made once — a background that is a gradient
 * must not also be reported as a background color, or a card with a decorative
 * background image would be reconstructed as a content image.
 *
 * Every extractor reports what it understood and what it did not. A gradient in a
 * color space the parser cannot read is reported as unsupported rather than being
 * approximated, because an approximated gradient is a different colour on screen.
 */
final class Visual_Effects {

	/**
	 * Gradient types understood.
	 */
	const GRADIENT_TYPES = array( 'linear', 'radial', 'conic' );

	/**
	 * Border styles that draw nothing, and so should not produce a border.
	 */
	const BORDER_STYLES = array( 'none', 'hidden', 'initial', 'unset' );

	/**
	 * Font size used to resolve em lengths.
	 *
	 * @var float
	 */
	private $font_size;

	/**
	 * Viewport width used to resolve viewport lengths.
	 *
	 * @var float
	 */
	private $viewport;

	/**
	 * Constructor.
	 *
	 * @param float $font_size Root font size in pixels.
	 * @param float $viewport  Viewport width in pixels.
	 */
	public function __construct( $font_size = 16.0, $viewport = 1440.0 ) {
		$this->font_size = $font_size > 0 ? (float) $font_size : 16.0;
		$this->viewport  = $viewport > 0 ? (float) $viewport : 1440.0;
	}

	/**
	 * Parse a gradient value.
	 *
	 * @param string $value Raw value.
	 * @return array<string, mixed>|null Null when the value is not a gradient.
	 */
	public function gradient( $value ) {
		$value = Css_Value_Parser::bound( $value );
		if ( '' === $value ) {
			return null;
		}

		$type = null;
		$args = array();
		foreach ( self::GRADIENT_TYPES as $candidate ) {
			$parts = Css_Value_Parser::function_args( $value, $candidate . '-gradient', ',' );
			if ( ! empty( $parts ) ) {
				$type = $candidate;
				$args = $parts;
				break;
			}
		}

		if ( null === $type ) {
			// `repeating-linear-gradient` and the image variants are real and
			// different: a repeating gradient cannot be reproduced by a single
			// Elementor background, so it is recognized and reported as such.
			foreach ( array( 'repeating-linear-gradient', 'repeating-radial-gradient', 'repeating-conic-gradient', '-webkit-linear-gradient', '-webkit-radial-gradient' ) as $vendor ) {
				$parts = Css_Value_Parser::function_args( $value, $vendor, ',' );
				if ( ! empty( $parts ) ) {
					$base = str_replace( array( 'repeating-', '-webkit-' ), '', $vendor );
					$base = str_replace( '-gradient', '', $base );
					return array(
						'type'        => $base,
						'repeating'   => false !== strpos( $vendor, 'repeating' ),
						'vendor'      => $vendor,
						'angle'       => null,
						'position'    => null,
						'shape'       => null,
						'stops'       => array(),
						'supported'   => false,
						'limitation'  => 'A repeating gradient tiles its stops. Elementor backgrounds hold a single gradient, so the replica shows one repetition rather than a repeating pattern.',
						'raw'         => $value,
					);
				}
			}
			return null;
		}

		$parsed = $this->gradient_parts( $type, $args, $value );

		return $parsed;
	}

	/**
	 * Read a gradient's direction, shape, and stops.
	 *
	 * @param string               $type  Gradient type.
	 * @param array<int, string>   $args  Comma-separated arguments.
	 * @param string               $value Raw value.
	 * @return array<string, mixed>
	 */
	private function gradient_parts( $type, array $args, $value ) {
		$stops     = array();
		$angle     = null;
		$position  = null;
		$shape     = null;
		$remaining = array();

		foreach ( $args as $argument ) {
			$argument = trim( $argument );
			if ( '' === $argument ) {
				continue;
			}

			$lower = strtolower( $argument );

			// A shape and a position written together, as in
			// `circle at 50% 30%`, arrive as one argument because they are separated
			// by a space rather than a comma.
			if ( preg_match( '/^(circle|ellipse)\s+at\s+(.+)$/i', $argument, $matches ) ) {
				$shape    = strtolower( $matches[1] );
				$position = trim( $matches[2] );
				continue;
			}

			// A direction keyword or an angle, when it is not a stop.
			if ( 0 === count( $stops ) && 0 === count( $remaining ) ) {
				$degrees = Css_Value_Parser::parse_angle( $argument );
				if ( null !== $degrees && ! Css_Value_Parser::color( $argument ) ) {
					$angle = $degrees;
					continue;
				}
				if ( in_array( $lower, array( 'circle', 'ellipse', 'at', 'closest-side', 'farthest-side', 'closest-corner', 'farthest-corner' ), true ) ) {
					$shape = $lower;
					continue;
				}
			}

			// A radial position such as `at 50% 30%`.
			if ( 0 === strpos( $lower, 'at ' ) ) {
				$position = trim( substr( $argument, 3 ) );
				continue;
			}

			// A size keyword for a radial gradient, which may stand alone.
			if ( in_array( $lower, array( 'closest-side', 'farthest-side', 'closest-corner', 'farthest-corner' ), true ) ) {
				$position = ( null === $position ? '' : $position . ' ' ) . $lower;
				continue;
			}

			$stop = $this->gradient_stop( $argument );
			if ( null !== $stop ) {
				$stops[] = $stop;
				continue;
			}

			$remaining[] = $argument;
		}

		$limitation = null;
		$supported  = count( $stops ) >= 2;

		if ( ! $supported ) {
			$limitation = 'A gradient with fewer than two stops is not a gradient in the CSS sense and cannot be reproduced.';
		}
		if ( 'conic' === $type ) {
			$supported  = false;
			$limitation = 'A conic gradient sweeps by angle from a center. Elementor backgrounds support linear and radial gradients, so this is reproduced as a flat color taken from its first stop rather than as a sweep.';
		}

		// Stops default to an even distribution, which is what a browser does.
		$count = count( $stops );
		if ( $count >= 2 ) {
			foreach ( $stops as $index => $stop ) {
				if ( null !== $stop['position'] ) {
					continue;
				}
				$stops[ $index ]['position']       = round( $index / ( $count - 1 ), 4 );
				$stops[ $index ]['position_source'] = 'interpolated';
			}
		}

		return array(
			'type'       => $type,
			'repeating'  => false,
			'angle'      => $angle,
			'position'   => $position,
			'shape'      => $shape,
			'stops'      => $stops,
			'supported'  => $supported,
			'limitation' => $limitation,
			'raw'        => $value,
		);
	}

	/**
	 * Read one color stop.
	 *
	 * A stop is a color and, optionally, a position. The position is a percentage or
	 * a length, and `transparent` is a color rather than an absence of one, which is
	 * why a two-stop transparent gradient still counts as a gradient.
	 *
	 * @param string $argument Stop text.
	 * @return array<string, mixed>|null
	 */
	private function gradient_stop( $argument ) {
		$parts = Css_Value_Parser::split( $argument, ' ' );
		$parts = array_values( array_filter( $parts, static function ( $part ) {
			return '' !== trim( $part );
		} ) );

		if ( empty( $parts ) ) {
			return null;
		}

		$color = Css_Value_Parser::color( $parts[0] );
		if ( null === $color ) {
			return null;
		}

		$position = null;
		if ( isset( $parts[1] ) ) {
			$position = Css_Value_Parser::percentage( $parts[1] );
			if ( null === $position ) {
				$length = Css_Value_Parser::length( $parts[1], $this->font_size, $this->viewport );
				if ( null !== $length && isset( $length['pixels'] ) && null !== $length['pixels'] ) {
					$position = round( ( (float) $length['pixels'] / $this->viewport ), 4 );
				}
			}
		}

		return array(
			'color'          => $color['hex'],
			'alpha'          => (float) $color['alpha'],
			'rgb'            => $color['rgb'],
			'position'       => $position,
			'position_source' => null === $position ? 'absent' : 'declaration',
		);
	}

	/**
	 * Parse a box-shadow or text-shadow value.
	 *
	 * A shadow list has one shadow per comma-separated group, and each group mixes
	 * lengths, a color, and an inset keyword, so the parts are classified rather
	 * than assigned by position.
	 *
	 * @param string $value Raw value.
	 * @param string $type  Either `box` or `text`.
	 * @return array<string, mixed>
	 */
	public function shadow( $value, $type = 'box' ) {
		$value = Css_Value_Parser::bound( $value );
		if ( '' === $value || 'none' === strtolower( $value ) ) {
			return array( 'shadows' => array(), 'present' => false, 'count' => 0, 'raw' => $value );
		}

		$shadows = array();
		foreach ( Css_Value_Parser::split( $value, ',' ) as $group ) {
			$shadow = $this->one_shadow( $group, $type );
			if ( null !== $shadow ) {
				$shadows[] = $shadow;
			}
		}

		return array(
			'shadows'   => $shadows,
			'present'   => ! empty( $shadows ),
			'count'     => count( $shadows ),
			// Elementor's shadow control takes a single shadow, so a layered shadow
			// is reproduced by its first layer. Which one is first is a real
			// difference on screen, so the count and the loss are both reported.
			'elementor' => $this->elementor_shadow( $shadows, $type ),
			'raw'       => $value,
		);
	}

	/**
	 * Read one shadow.
	 *
	 * @param string $group Shadow text.
	 * @param string $type  Shadow type.
	 * @return array<string, mixed>|null
	 */
	private function one_shadow( $group, $type ) {
		$parts = Css_Value_Parser::split( trim( $group ), ' ' );
		if ( empty( $parts ) ) {
			return null;
		}

		$lengths = array();
		$color    = null;
		$inset    = false;
		$blur     = null;
		$spread   = null;

		foreach ( $parts as $part ) {
			$lower = strtolower( $part );

			if ( 'inset' === $lower ) {
				$inset = true;
				continue;
			}
			if ( 'none' === $lower ) {
				return null;
			}

			$parsed_color = Css_Value_Parser::color( $part );
			if ( null !== $parsed_color ) {
				$color = $parsed_color;
				continue;
			}

			$length = Css_Value_Parser::length( $part, $this->font_size, $this->viewport );
			if ( null !== $length && isset( $length['pixels'] ) && null !== $length['pixels'] ) {
				$lengths[] = (float) $length['pixels'];
				continue;
			}

			// A calc() length is recorded as unresolved rather than dropped, so the
			// shadow is reported as present but not fully reproduced.
			if ( is_string( $part ) && preg_match( '/^(calc|min|max|clamp)\s*\(/i', trim( $part ) ) ) {
				$lengths[] = null;
			}
		}

		$resolved = array_values( array_filter( $lengths, static function ( $value ) {
			return null !== $value;
		} ) );

		// A text shadow has no spread, so its third length is a blur as well.
		$limit = ( 'text' === $type ) ? 3 : 4;
		if ( count( $resolved ) > $limit ) {
			$resolved = array_slice( $resolved, 0, $limit );
		}

		$offset_x = isset( $resolved[0] ) ? $resolved[0] : 0.0;
		$offset_y = isset( $resolved[1] ) ? $resolved[1] : 0.0;
		$blur     = isset( $resolved[2] ) ? $resolved[2] : null;
		$spread   = ( 'text' === $type ) ? null : ( isset( $resolved[3] ) ? $resolved[3] : null );

		$complete = ( count( $lengths ) === count( $resolved ) ) && ( null !== $blur );

		return array(
			'offset_x'   => $offset_x,
			'offset_y'   => $offset_y,
			'blur'       => $blur,
			'spread'     => $spread,
			'inset'      => $inset,
			'color'      => null !== $color ? $color['hex'] : null,
			'alpha'      => null !== $color ? (float) $color['alpha'] : 1.0,
			'complete'   => $complete,
			'limitation' => $complete
				? null
				: 'The shadow uses a length that cannot be resolved without evaluating a calculation, so the replica approximates it.',
		);
	}

	/**
	 * Map a shadow list onto an Elementor shadow control.
	 *
	 * @param array<int, array<string, mixed>> $shadows Shadows.
	 * @param string                            $type    Shadow type.
	 * @return array<string, mixed>
	 */
	private function elementor_shadow( array $shadows, $type ) {
		if ( empty( $shadows ) ) {
			return array( 'supported' => false, 'limitation' => null, 'value' => null );
		}

		$limitation = null;
		if ( count( $shadows ) > 1 ) {
			$limitation = 'The source has ' . count( $shadows ) . ' layered shadows. Elementor applies one shadow per control, so the replica uses the outermost shadow and the others are lost.';
		}

		$first = $shadows[0];
		if ( empty( $first['complete'] ) ) {
			$limitation = ( null === $limitation )
				? (string) $first['limitation']
				: $limitation . ' ' . (string) $first['limitation'];
		}

		return array(
			'supported'  => true,
			'value'      => $first,
			'layered'    => count( $shadows ) > 1,
			'limitation' => $limitation,
		);
	}

	/**
	 * Read a border from its four longhands plus the shorthands.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, mixed>
	 */
	public function border( array $declarations ) {
		$sides = array();

		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$width = $this->length( $declarations, 'border-' . $side . '-width' );
			$style = $this->keyword( $declarations, 'border-' . $side . '-style' );
			$color = Css_Value_Parser::color( (string) $this->raw( $declarations, 'border-' . $side . '-color' ) );

			// The bare longhands are the fallback. A page that sets border-style and
			// border-color without a width is common, and reading only the per-side
			// form made such a border look as if nothing drew.
			if ( null === $width ) {
				$width = $this->length( $declarations, 'border-width' );
			}
			if ( null === $style ) {
				$style = $this->keyword( $declarations, 'border-style' );
			}
			if ( null === $color ) {
				$color = Css_Value_Parser::color( (string) $this->raw( $declarations, 'border-color' ) );
			}

			// The shorthand is expanded here rather than in the caller, because a page
			// commonly sets a shorthand on the element and one longhand in a state, and
			// the longhand has to win. The per-side shorthand is read first, because it
			// is the more specific declaration; the bare shorthand is the fallback.
			$shorthand = $this->shorthand_border( $declarations, 'border-' . $side );
			if ( null === $shorthand['style'] && null === $shorthand['width'] && null === $shorthand['color'] ) {
				$shorthand = $this->shorthand_border( $declarations, 'border' );
			}
			$width = null !== $width ? $width : $shorthand['width'];
			$style = $style ?? $shorthand['style'];
			$color = $color ?? $shorthand['color'];

			$draws = null !== $style && ! in_array( strtolower( (string) $style ), self::BORDER_STYLES, true );
			$null_style = null === $style;
			// The CSS initial value of border-width is medium, which is three pixels,
			// and it applies whether or not a style is declared. This is the width a
			// browser draws when a page sets a border color and a style and nothing
			// else, which is a common way to outline a card.
			$default    = 3.0;

			$sides[ $side ] = array(
				'width'  => $width,
				// A missing width on a style that draws is assumed to be the CSS
				// medium default, and the assumption is recorded rather than hidden.
				'width_source' => null !== $width ? 'declaration' : ( $draws ? 'medium_default' : null ),
				'style'  => $style,
				'color'  => null !== $color ? $color['hex'] : null,
				'alpha'  => null !== $color ? (float) $color['alpha'] : null,
				'draws'  => $draws,
				'uniform' => ! $null_style,
				'default_width' => $draws ? $default : null,
			);
		}

		$uniform = true;
		$first   = $sides['top'];
		foreach ( $sides as $side ) {
			if ( $side['width'] !== $first['width'] || $side['style'] !== $first['style'] || $side['color'] !== $first['color'] ) {
				$uniform = false;
				break;
			}
		}

		$radius = $this->border_radius( $declarations );

		return array(
			'sides'      => $sides,
			'uniform'    => $uniform,
			'present'    => $uniform && $first['draws'],
			'radius'     => $radius,
			'elementor'  => $this->elementor_border( $sides, $uniform, $radius ),
		);
	}

	/**
	 * Read a border shorthand.
	 *
	 * The color is returned parsed rather than as a hex string, so a caller reading
	 * either a longhand or a shorthand sees the same shape.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $property     Shorthand property name.
	 * @return array<string, mixed>
	 */
	private function shorthand_border( array $declarations, $property ) {
		$out = array( 'width' => null, 'style' => null, 'color' => null );

		$raw = $this->raw( $declarations, $property );
		if ( null === $raw ) {
			return $out;
		}

		foreach ( Css_Value_Parser::split( $raw, ' ' ) as $part ) {
			$lower = strtolower( $part );

			if ( in_array( $lower, array( 'none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset' ), true )
				&& null === $out['style'] && null === Css_Value_Parser::color( $part ) ) {
				$out['style'] = $lower;
				continue;
			}

			$color = Css_Value_Parser::color( $part );
			if ( null !== $color ) {
				// The parsed color is kept rather than only its hex, so the caller
				// reads one shape whichever path produced the value.
				$out['color'] = $color;
				continue;
			}

			$length = Css_Value_Parser::length( $part, $this->font_size, $this->viewport );
			if ( null !== $length && isset( $length['pixels'] ) && null !== $length['pixels'] ) {
				$out['width'] = (float) $length['pixels'];
			}
		}

		return $out;
	}

	/**
	 * Read a border radius, keeping per-corner differences.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, mixed>
	 */
	private function border_radius( array $declarations ) {
		$corners = array();
		$order   = array( 'top-left', 'top-right', 'bottom-right', 'bottom-left' );

		foreach ( $order as $corner ) {
			$raw = $this->raw( $declarations, 'border-' . $corner . '-radius' );
			if ( null === $raw ) {
				continue;
			}
			$corners[ $corner ] = $this->radius_pair( $raw );
		}

		// The bare shorthand is the ordinary way a page sets a radius, so it is
		// expanded here rather than left to a caller that would otherwise have to
		// know the four-value corner order.
		if ( empty( $corners ) ) {
			$shorthand = $this->raw( $declarations, 'border-radius' );
			if ( null !== $shorthand ) {
				$parts = Css_Value_Parser::split( $shorthand, '/' );
				$horizontal = Css_Value_Parser::split( $parts[0] ?? '', ' ' );
				$vertical   = isset( $parts[1] ) ? Css_Value_Parser::split( $parts[1], ' ' ) : array();

				$expanded = $this->expand_corner_values( $horizontal, $vertical );
				foreach ( $order as $index => $corner ) {
					$corners[ $corner ] = $expanded[ $index ];
				}
			}
		}

		$uniform = false;
		$value   = null;
		if ( count( $corners ) === 4 ) {
			$first   = $corners['top-left']['horizontal'];
			$uniform = true;
			foreach ( $corners as $corner ) {
				if ( $corner['horizontal'] !== $first ) {
					$uniform = false;
					break;
				}
			}
			$value = $first;
		} elseif ( 1 === count( $corners ) ) {
			$value = $corners['top-left']['horizontal'];
		}

		return array(
			'corners' => $corners,
			'uniform' => $uniform,
			'value'   => $value,
			// A radius far larger than any element is the ordinary way a page writes
			// a pill. It is recognized so the generator can reproduce it exactly,
			// because a pill written as 9999px and a pill written as 50% look the same
			// on screen and are not the same declaration.
			'pill'    => null !== $value && $value >= 999.0,
		);
	}

	/**
	 * Read one radius into its horizontal and vertical parts.
	 *
	 * @param string $raw Radius value.
	 * @return array<string, float|null>
	 */
	private function radius_pair( $raw ) {
		$parts = Css_Value_Parser::split( trim( (string) $raw ), '/' );

		$horizontal = Css_Value_Parser::length( $parts[0] ?? '', $this->font_size, $this->viewport );
		$vertical   = isset( $parts[1] )
			? Css_Value_Parser::length( $parts[1], $this->font_size, $this->viewport )
			: null;

		return array(
			'horizontal' => ( null !== $horizontal && isset( $horizontal['pixels'] ) ) ? $horizontal['pixels'] : null,
			// An elliptical radius names two lengths. A circular one names one, and
			// then both parts are the same value.
			'vertical'   => ( null !== $vertical && isset( $vertical['pixels'] ) ) ? $vertical['pixels'] : ( ( null !== $horizontal && isset( $horizontal['pixels'] ) ) ? $horizontal['pixels'] : null ),
			'elliptical'  => isset( $parts[1] ),
		);
	}

	/**
	 * Expand the one-to-four value radius shorthand into the four corners.
	 *
	 * @param array<int, string> $horizontal Horizontal values.
	 * @param array<int, string> $vertical   Vertical values.
	 * @return array<int, array<string, float|null>>
	 */
	private function expand_corner_values( array $horizontal, array $vertical ) {
		$h = array_values( array_filter( $horizontal, static function ( $part ) {
			return '' !== trim( (string) $part );
		} ) );
		$v = array_values( array_filter( $vertical, static function ( $part ) {
			return '' !== trim( (string) $part );
		} ) );

		// One value is all corners, two is horizontal then vertical, three and four
		// are the CSS corner order starting at the top left.
		$map = array(
			1 => array( 0, 0, 0, 0 ),
			2 => array( 0, 1, 0, 1 ),
			3 => array( 0, 1, 2, 1 ),
			4 => array( 0, 1, 2, 3 ),
		);

		$count = count( $h );
		if ( ! isset( $map[ $count ] ) ) {
			$map[ $count ] = $map[4];
		}
		$hindex = $map[ $count ];

		// A vertical list is optional; without it the radius is circular.
		$vindex = empty( $v ) ? $hindex : $this->expand_corner_values_map( count( $v ) );

		$out = array();
		for ( $corner = 0; $corner < 4; $corner++ ) {
			$pair = $this->radius_pair(
				$h[ $hindex[ $corner ] ] . ( empty( $v ) ? '' : ' / ' . $v[ $vindex[ $corner ] ] )
			);
			$out[ $corner ] = $pair;
		}

		return $out;
	}

	/**
	 * Return the corner index map for a given value count.
	 *
	 * @param int $count Number of values.
	 * @return array<int, int>
	 */
	private function expand_corner_values_map( $count ) {
		$map = array(
			1 => array( 0, 0, 0, 0 ),
			2 => array( 0, 1, 0, 1 ),
			3 => array( 0, 1, 2, 1 ),
			4 => array( 0, 1, 2, 3 ),
		);
		return isset( $map[ $count ] ) ? $map[ $count ] : $map[4];
	}
	/**
	 * Map a border onto the Elementor controls.
	 *
	 * @param array<string, array<string, mixed>> $sides   Sides.
	 * @param bool                                  $uniform Whether the sides match.
	 * @param array<string, mixed>                  $radius  Radius.
	 * @return array<string, mixed>
	 */
	private function elementor_border( array $sides, $uniform, $radius ) {
		$limitation = null;

		if ( ! $uniform ) {
			$limitation = 'The source sets a different border on each side. Elementor exposes one border control for all four, so the replica applies the top border to all four sides.';
		}

		$chosen = $sides['top'];
		if ( ! $chosen['draws'] ) {
			// The first side that actually draws is a better representative than the
			// top, which is often left unset.
			foreach ( $sides as $side ) {
				if ( $side['draws'] ) {
					$chosen = $side;
					break;
				}
			}
		}

		if ( null === $chosen['width'] && $chosen['draws'] ) {
			$limitation = trim( ( null === $limitation ? '' : $limitation . ' ' ) . 'The border width is not declared, so the CSS medium default of three pixels is used.' );
		}

		return array(
			'supported'  => true,
			'width'      => $chosen['width'] ?? $chosen['default_width'],
			'style'      => $chosen['style'],
			'color'      => $chosen['color'],
			'radius'     => $radius['value'],
			'pill'       => (bool) $radius['pill'],
			'limitation' => $limitation,
		);
	}

	/**
	 * Read a background, and decide what kind of visual it is.
	 *
	 * A page uses background-image for two quite different things: the visible art
	 * of a hero, and a decorative texture behind a card. Treating the second as a
	 * content image would import a file that is not content and would put a
	 * background into a widget that has no background, so the two are distinguished
	 * by what the element is and how large the image is relative to it.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param array<string, mixed> $context      Optional node context: `role`, `area`, `has_text`, `width`, `height`.
	 * @return array<string, mixed>
	 */
	public function background( array $declarations, array $context = array() ) {
		$color = Css_Value_Parser::color( (string) $this->raw( $declarations, 'background-color' ) );

		// The longhand is read first and the shorthand is the fallback, because that
		// is the order a browser resolves the pair in. Reading only the shorthand
		// meant a page that set background-image directly had no image at all.
		$longhand_layers = $this->background_layers( $declarations );
		$shorthand       = $this->background_shorthand( $declarations );

		$gradient = null;
		$url      = null;
		foreach ( $longhand_layers as $layer ) {
			if ( 'gradient' === $layer['kind'] && null === $gradient ) {
				$gradient = $layer['gradient'];
			}
			if ( 'image' === $layer['kind'] && null === $url ) {
				$url = $layer['url'];
			}
		}
		if ( null === $url ) {
			$url = $shorthand['image_url'];
		}
		if ( null === $gradient && isset( $shorthand['gradient_raw'] ) ) {
			$gradient = $this->gradient( (string) $shorthand['gradient_raw'] );
		}

		$repeat     = $this->raw( $declarations, 'background-repeat' ) ?? $shorthand['repeat'];
		$position   = $this->raw( $declarations, 'background-position' ) ?? $shorthand['position'];
		$size       = $this->raw( $declarations, 'background-size' ) ?? $shorthand['size'];
		$attachment = $this->raw( $declarations, 'background-attachment' ) ?? $shorthand['attachment'];

		// A color inside the shorthand is only used when no longhand set one, because
		// the longhand is the more specific declaration.
		if ( null === $color && null !== $shorthand['color'] ) {
			$parsed = Css_Value_Parser::color( (string) $shorthand['color'] );
			$color  = $parsed;
		}

		$layers = array();
		if ( null !== $url ) {
			$layers[] = array(
				'kind'       => 'image',
				'url'        => $url,
				'repeat'     => $repeat,
				'position'   => $position,
				'size'       => $size,
				'attachment' => $attachment,
			);
		}
		if ( null !== $gradient ) {
			$layers[] = array(
				'kind'       => 'gradient',
				'gradient'   => $gradient,
				'repeat'     => $repeat,
				'position'   => $position,
				'size'       => $size,
			);
		}
		if ( null !== $color ) {
			$layers[] = array(
				'kind'  => 'color',
				'color' => $color['hex'],
				'alpha' => (float) $color['alpha'],
			);
		}

		$role   = isset( $context['role'] ) ? (string) $context['role'] : '';
		$has_text = ! empty( $context['has_text'] );

		return array(
			'layers'     => $layers,
			'present'    => ! empty( $layers ),
			'color'      => null !== $color ? $color['hex'] : null,
			'gradient'   => $gradient,
			'image'      => $url,
			'position'   => $position,
			'size'       => $size,
			'repeat'     => $repeat,
			'attachment' => $attachment,
			'overlay'    => $this->overlay_ratio( $color, $layers, $has_text ),
			'class'      => $this->background_class( $role, $has_text, $url, $gradient, $context ),
			'elementor'  => $this->elementor_background( $url, $gradient, $color, $size, $position, $attachment, $repeat ),
		);
	}

	/**
	 * Read a background shorthand.
	 *
	 * The shorthand mixes a color, an image, a position, a size, a repeat, and an
	 * attachment in any order after a slash, so each part is classified.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, mixed>
	 */
	private function background_shorthand( array $declarations ) {
		$out = array(
			'image_url' => null,
			'color'     => null,
			'repeat'    => null,
			'position'  => null,
			'size'      => null,
			'attachment'=> null,
		);

		$raw = $this->raw( $declarations, 'background' );
		if ( null === $raw ) {
			return $out;
		}

		// The gradient function is isolated first, because it contains spaces and
		// commas that would otherwise be read as several shorthand parts.
		$without_gradient = $raw;
		$function = Css_Value_Parser::first_function( $raw );
		if ( null !== $function && false !== strpos( (string) $function['function'], 'gradient' ) ) {
			$out['gradient_raw'] = $raw;
			$without_gradient = '';
		}

		$parts = Css_Value_Parser::split( $without_gradient, ' ' );
		$position_parts = array();
		$after_slash   = false;

		foreach ( $parts as $part ) {
			$lower = strtolower( $part );

			if ( '/' === $part ) {
				$after_slash = true;
				continue;
			}
			if ( false !== strpos( $part, 'url(' ) ) {
				$out['image_url'] = $this->url_from_function( $part );
				continue;
			}
			if ( 'none' === $lower ) {
				continue;
			}
			if ( in_array( $lower, array( 'repeat', 'no-repeat', 'repeat-x', 'repeat-y', 'space', 'round' ), true ) ) {
				$out['repeat'] = $lower;
				continue;
			}
			if ( in_array( $lower, array( 'fixed', 'scroll', 'local' ), true ) ) {
				$out['attachment'] = $lower;
				continue;
			}
			if ( in_array( $lower, array( 'border-box', 'padding-box', 'content-box' ), true ) ) {
				continue;
			}

			$color = Css_Value_Parser::color( $part );
			if ( null !== $color && null === $out['color'] ) {
				$out['color'] = $color['hex'];
				continue;
			}

			if ( $after_slash ) {
				$out['size'] = $lower;
				continue;
			}

			$position_parts[] = $lower;
		}

		if ( ! empty( $position_parts ) ) {
			$out['position'] = implode( ' ', $position_parts );
		}

		return $out;
	}

	/**
	 * Read the longhand forms, which win over the shorthand.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $property     Longhand name.
	 * @return string|null
	 */
	private function longhand( array $declarations, $property ) {
		return $this->raw( $declarations, $property );
	}

	/**
	 * Return how opaque a background is over whatever is behind it.
	 *
	 * This is the number that decides whether text on the background is readable,
	 * and it is a computed value rather than a declared one, so it is derived here
	 * and marked as derived.
	 *
	 * @param array<string, mixed>|null $color   Background color.
	 * @param array<int, array<string, mixed>> $layers Layers.
	 * @param bool                       $has_text Whether the element has text.
	 * @return array<string, mixed>
	 */
	private function overlay_ratio( $color, array $layers, $has_text ) {
		// Layers are in paint order, topmost first, so the first layer that is not
		// fully opaque is what a reader sees the content through.
		$alpha        = null;
		$from         = null;
		$composited   = 1.0;
		$has_gradient = false;

		foreach ( $layers as $layer ) {
			if ( 'image' === $layer['kind'] ) {
				// An image at the top makes the background opaque regardless of what is
				// beneath it, unless the image itself is partly transparent, which cannot
				// be known without fetching it.
				if ( null === $alpha ) {
					$alpha = 1.0;
					$from  = 'image';
				}
				continue;
			}

			if ( 'gradient' === $layer['kind'] ) {
				$has_gradient = true;
				$stops        = isset( $layer['gradient']['stops'] ) ? $layer['gradient']['stops'] : array();
				if ( empty( $stops ) ) {
					continue;
				}
				// A gradient varies across the element, so the weakest stop is the one
				// that most reduces the contrast with the text above it.
				$weakest = 1.0;
				foreach ( $stops as $stop ) {
					$weakest = min( $weakest, (float) ( $stop['alpha'] ?? 1.0 ) );
				}
				if ( null === $alpha ) {
					$alpha = $weakest;
					$from  = 'gradient';
				}
				$composited = min( $composited, $weakest );
				continue;
			}

			if ( 'color' === $layer['kind'] ) {
				$layer_alpha = (float) ( $layer['alpha'] ?? 1.0 );
				if ( null === $alpha ) {
					$alpha = $layer_alpha;
					$from  = 'color';
				}
				continue;
			}
		}

		if ( null === $alpha && null !== $color ) {
			$alpha = (float) $color['alpha'];
			$from  = 'color';
		}

		// With several translucent layers the visible result is the product of their
		// opacities, so a translucent gradient over a translucent color is more
		// transparent than either alone.
		if ( null !== $alpha && $alpha < 1.0 && $has_gradient ) {
			$alpha = $composited;
		}

		return array(
			'alpha'    => $alpha,
			'from'     => $from,
			'derived'  => true,
			'has_text' => (bool) $has_text,
			// A translucent background behind text is the most common reason a
			// reconstruction is unreadable, so the combination is surfaced rather
			// than left for a reader to notice.
			'risk'     => ( null !== $alpha && $alpha < 0.85 && $has_text ) ? 'low_contrast_risk' : null,
		);
	}
	/**
	 * Decide what kind of background this is.
	 *
	 * @param string                      $role     Element role.
	 * @param bool                        $has_text Whether it has text.
	 * @param string|null                 $url      Image URL.
	 * @param array<string, mixed>|null   $gradient Gradient.
	 * @param array<string, mixed>        $context  Node context.
	 * @return string
	 */
	private function background_class( $role, $has_text, $url, $gradient, array $context ) {
		if ( null === $url && null === $gradient ) {
			return 'none';
		}
		if ( in_array( $role, array( 'hero', 'masthead', 'banner' ), true ) ) {
			return 'hero';
		}
		if ( in_array( $role, array( 'card', 'panel', 'tile' ), true ) ) {
			return 'card';
		}
		// An image behind a small element with no text is a texture rather than
		// content, and a repeated one is almost always a texture.
		if ( $has_text ) {
			return 'section';
		}
		$repeat = $this->longhand( array(), '' );
		unset( $repeat );
		$small = isset( $context['area'] ) && is_numeric( $context['area'] ) && $context['area'] < 20000;
		return $small ? 'decorative' : 'section';
	}

	/**
	 * Map a background onto the Elementor controls.
	 *
	 * @param string|null               $url        Image URL.
	 * @param array<string, mixed>|null $gradient   Gradient.
	 * @param array<string, mixed>|null $color      Color.
	 * @param string|null               $size       Background size.
	 * @param string|null               $position   Background position.
	 * @param string|null               $attachment Attachment.
	 * @param string|null               $repeat     Repeat.
	 * @return array<string, mixed>
	 */
	private function elementor_background( $url, $gradient, $color, $size, $position, $attachment, $repeat ) {
		$limitations = array();

		// Elementor takes one background image and one background color. A page that
		// layers several is reproduced with the topmost, which is the one a person
		// sees first.
		if ( null !== $gradient && ! empty( $gradient['limitation'] ) ) {
			$limitations[] = (string) $gradient['limitation'];
		}
		if ( 'fixed' === $attachment ) {
			$limitations[] = 'A fixed background does not scroll with the page. Elementor has no equivalent, so the replica scrolls with the content.';
		}
		if ( null !== $repeat && 'no-repeat' !== $repeat && 'repeat' !== $repeat ) {
			$limitations[] = 'The background repeats along only one axis (' . $repeat . '), which Elementor cannot express.';
		}

		return array(
			'image'      => $url,
			'gradient'   => $gradient,
			'color'      => null !== $color ? $color['hex'] : null,
			'size'       => $size,
			'position'   => $position,
			'repeat'     => $repeat,
			'attachment' => $attachment,
			'limitation' => empty( $limitations ) ? null : implode( ' ', $limitations ),
		);
	}

	/**
	 * Read a background from the longhand properties when a page uses them.
	 *
	 * A page that sets `background-image` directly rather than the shorthand is
	 * common enough that reading only the shorthand missed a large share of real
	 * backgrounds.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, mixed>
	 */
	public function background_layers( array $declarations ) {
		$raw = $this->raw( $declarations, 'background-image' );
		if ( null === $raw ) {
			return array();
		}

		// Each comma-separated part is a layer, and a layer is a gradient or an
		// image. Testing the whole value for a gradient only matched when the
		// gradient happened to be the first layer, which lost a gradient that sat
		// beneath an image.
		$layers = array();
		foreach ( Css_Value_Parser::split( $raw, ',' ) as $part ) {
			$part = trim( $part );
			if ( '' === $part || 'none' === strtolower( $part ) ) {
				continue;
			}

			$gradient = $this->gradient( $part );
			if ( null !== $gradient ) {
				$layers[] = array( 'kind' => 'gradient', 'gradient' => $gradient );
				continue;
			}

			$url = $this->url_from_function( $part );
			if ( null !== $url ) {
				$layers[] = array( 'kind' => 'image', 'url' => $url );
			}
		}

		return $layers;
	}
	/**
	 * Read a url() reference.
	 *
	 * @param string $value Raw value.
	 * @return string|null
	 */
	private function url_from_function( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '/^url\(\s*[\'"]?(.*?)[\'"]?\s*\)$/is', $value, $matches ) ) {
			$url = trim( $matches[1] );
			// A data URI is a real technique but is not an external asset and must not
			// be treated as one, so it is reported as a data reference.
			if ( 0 === stripos( $url, 'data:' ) ) {
				return null;
			}
			return $url;
		}
		return null;
	}

	/**
	 * Return a raw declaration value.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $property     Property name.
	 * @return string|null
	 */
	private function raw( array $declarations, $property ) {
		return isset( $declarations[ $property ] ) && is_scalar( $declarations[ $property ] )
			? trim( (string) $declarations[ $property ] )
			: null;
	}

	/**
	 * Return a normalized keyword declaration.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $property     Property name.
	 * @return string|null
	 */
	private function keyword( array $declarations, $property ) {
		$raw = $this->raw( $declarations, $property );
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$raw = strtolower( $raw );
		if ( false !== strpos( $raw, ',' ) || false !== strpos( $raw, '(' ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * Return a length in pixels.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $property     Property name.
	 * @return float|null
	 */
	private function length( array $declarations, $property ) {
		$raw = $this->raw( $declarations, $property );
		if ( null === $raw ) {
			return null;
		}
		$parsed = Css_Value_Parser::length( $raw, $this->font_size, $this->viewport );
		return null !== $parsed && isset( $parsed['pixels'] ) && null !== $parsed['pixels']
			? (float) $parsed['pixels']
			: null;
	}
}
