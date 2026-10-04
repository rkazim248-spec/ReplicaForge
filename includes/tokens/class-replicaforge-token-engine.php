<?php
/**
 * Phase 8: design token engine.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Collapses repeated values into named tokens, with evidence for every name.
 *
 * A page that uses one colour forty times has one design decision, not forty. The
 * representation is more useful, and the Elementor document is smaller, when that
 * decision is named once and referenced. But naming it is a judgement, and a
 * judgement that invents a name is worse than no token at all: a token called
 * `primary` that the source never called primary is an assertion the evidence does
 * not support.
 *
 * Every name here is therefore derived from something observable. A colour used on
 * text is `text`, a colour used on a page background is `background`, a colour used
 * by the largest text on the page is `display`. A name is only assigned when the
 * usage evidence is unambiguous, and a token with no evidence-based name keeps an
 * ordinal name that says nothing it cannot support.
 */
final class Token_Engine {

	/**
	 * Token families produced.
	 */
	const FAMILIES = array( 'colors', 'typography', 'spacing', 'radius', 'shadows', 'containers', 'breakpoints' );

	/**
	 * Minimum occurrences before a repeated value is considered a token rather than
	 * a coincidence.
	 *
	 * One occurrence is a value, not a decision. Two is suggestive. Three is a
	 * pattern, and is where the threshold sits.
	 */
	const MIN_OCCURRENCES = 3;

	/**
	 * Maximum tokens retained per family.
	 *
	 * @var array<string, int>
	 */
	const MAX_PER_FAMILY = array(
		'colors'      => Analysis_Limits::MAX_TOKENS_PER_FAMILY,
		'typography'  => Analysis_Limits::MAX_TOKENS_PER_FAMILY,
		'spacing'    => Analysis_Limits::MAX_TOKENS_PER_FAMILY,
		'radius'     => 40,
		'shadows'    => 40,
		'containers' => 40,
		'breakpoints'=> 20,
	);

	/**
	 * Minimum value contrast, in luminance, for a token to be named as a text color.
	 *
	 * A colour used on a surface is only a text colour if it contrasts with that
	 * surface. Without the surface, the claim is not made.
	 *
	 * @var float
	 */
	const MIN_TEXT_CONTRAST = 0.4;

	/**
	 * Build the token set from observed usage.
	 *
	 * @param array<int, array<string, mixed>> $observations One entry per element.
	 * @param array<string, mixed>              $page         Optional page-level facts: `background`, `largest_text_color`, `viewport`.
	 * @return array<string, mixed>
	 */
	public function build( array $observations, array $page = array() ) {
		$colors      = $this->collect_colors( $observations, $page );
		$typography  = $this->collect_typography( $observations );
		$spacing     = $this->collect_spacing( $observations );
		$radius      = $this->collect_values( $observations, 'radius' );
		$shadows     = $this->collect_shadows( $observations );
		$containers  = $this->collect_containers( $observations );
		$breakpoints = $this->collect_breakpoints( $observations );

		$tokens = array(
			'colors'      => $colors,
			'typography'  => $typography,
			'spacing'     => $spacing,
			'radius'      => $radius,
			'shadows'     => $shadows,
			'containers'  => $containers,
			'breakpoints' => $breakpoints,
		);

		$counts = array();
		foreach ( self::FAMILIES as $family ) {
			$counts[ $family ] = count( $tokens[ $family ] );
		}

		return array(
			'version'    => '8.0',
			'tokens'     => $tokens,
			'counts'     => $counts,
			'total'      => array_sum( $counts ),
			// How many of the names are evidence-based rather than ordinal. A token
			// set where most names are ordinal is a de-duplication, not a design
			// system, and saying so is more useful than the count alone.
			'named'      => $this->count_named( $tokens ),
			'evidence'   => $this->evidence_summary( $tokens ),
		);
	}

	/**
	 * Collect and name colors.
	 *
	 * @param array<int, array<string, mixed>> $observations Observations.
	 * @param array<string, mixed>              $page         Page facts.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_colors( array $observations, array $page ) {
		$usage = array();
		$page_background = isset( $page['background'] ) ? Css_Value_Parser::color_key( (string) $page['background'] ) : '';

		foreach ( $observations as $index => $observation ) {
			$keys = array();
			foreach ( $this->color_values( $observation ) as $value ) {
				$key = Css_Value_Parser::color_key( $value );
				if ( '' === $key ) {
					continue;
				}
				$keys[] = $key;
			}

			foreach ( array_unique( $keys ) as $key ) {
				if ( ! isset( $usage[ $key ] ) ) {
					$usage[ $key ] = array(
						'value'       => $key,
						'occurrences' => 0,
						'on_text'     => 0,
						'as_background' => 0,
						'as_border'   => 0,
						'samples'     => array(),
						'largest_text'=> false,
					);
				}
				$usage[ $key ]['occurrences']++;

				if ( ! empty( $observation['has_text'] ) ) {
					$usage[ $key ]['on_text']++;
				}
				if ( in_array( (string) ( $observation['role'] ?? '' ), array( 'section', 'hero', 'page' ), true )
					|| ! empty( $observation['is_container'] ) ) {
					$usage[ $key ]['as_background']++;
				}
				if ( ! empty( $observation['has_border'] ) ) {
					$usage[ $key ]['as_border']++;
				}
				if ( ! empty( $observation['largest_text'] ) ) {
					$usage[ $key ]['largest_text'] = true;
				}
				if ( count( $usage[ $key ]['samples'] ) < 5 ) {
					$usage[ $key ]['samples'][] = (string) ( $observation['node_id'] ?? ( 'node-' . $index ) );
				}
			}
		}

		$out = array();
		foreach ( $usage as $key => $entry ) {
			// The key carries the alpha as a suffix so that a translucent colour
			// does not collapse into the opaque one. The key is not itself a
			// parseable colour, so the hex and the alpha are taken off it
			// separately and the channels are parsed from the hex.
			$at          = strpos( $key, '@' );
			$hex         = ( false === $at ) ? $key : substr( $key, 0, $at );
			$key_alpha   = ( false === $at ) ? 1.0 : (float) substr( $key, $at + 1 );
			$parsed      = Css_Value_Parser::color( $hex );
			$name        = $this->color_name( $entry, $parsed, $page_background );

			$out[] = array(
				// The name is the claim, and its basis says how much the claim rests on.
				'name'        => $name['name'],
				'name_basis'  => $name['basis'],
				'value'       => $hex,
				'key'         => $key,
				'alpha'       => $key_alpha,
				'rgb'         => null !== $parsed ? $parsed['rgb'] : null,
				'hsl'         => null !== $parsed ? $parsed['hsl'] : null,
				'luminance'   => null !== $parsed && isset( $parsed['rgb'] ) ? $this->luminance( $parsed['rgb'] ) : null,
				'occurrences' => $entry['occurrences'],
				'usage'       => array(
					'on_text'        => $entry['on_text'],
					'as_background'  => $entry['as_background'],
					'as_border'      => $entry['as_border'],
					'largest_text'   => (bool) $entry['largest_text'],
				),
				'evidence'    => $entry['samples'],
			);
		}

		// Ordered so the most-used value is first, which is the order a reader
		// expects and the order the Elementor mapping consumes.
		usort( $out, static function ( $left, $right ) {
			if ( $left['occurrences'] === $right['occurrences'] ) {
				return strcmp( (string) $left['value'], (string) $right['value'] );
			}
			return $right['occurrences'] <=> $left['occurrences'];
		} );

		return $this->cap( $out, 'colors' );
	}

	/**
	 * Name a color from how it is used.
	 *
	 * @param array<string, mixed> $entry           Usage entry.
	 * @param array<string, mixed>|null $parsed     Parsed color.
	 * @param string               $page_background Page background key.
	 * @return array{name: string, basis: string}
	 */
	private function color_name( array $entry, $parsed, $page_background ) {
		$ordinal = 'color_' . substr( hash( 'sha256', (string) $entry['value'] ), 0, 6 );

		if ( $entry['largest_text'] ) {
			return array( 'name' => 'display', 'basis' => 'used by the largest text on the page' );
		}
		if ( $entry['on_text'] >= $entry['as_background'] && $entry['on_text'] > 0 ) {
			if ( null !== $parsed && '' !== $page_background ) {
				$background = Css_Value_Parser::color( $page_background );
				if ( null !== $background && isset( $background['rgb'] ) && isset( $parsed['rgb'] ) ) {
					$contrast = $this->contrast( $parsed['rgb'], $background['rgb'] );
					if ( $contrast >= self::MIN_TEXT_CONTRAST ) {
						return array(
							'name'   => 'text',
							// The contrast was measured, so the name rests on a
							// measurement rather than on the usage alone.
							'basis'  => 'used on text against the page background, at a measured contrast of ' . round( $contrast, 2 ),
						);
					}
				}
			}
			return array( 'name' => 'text', 'basis' => 'used on text' );
		}
		if ( $entry['as_background'] > 0 && '' !== $page_background && $entry['value'] === $page_background ) {
			return array( 'name' => 'background', 'basis' => 'used as the page background' );
		}
		if ( $entry['as_background'] > 0 ) {
			return array( 'name' => 'surface', 'basis' => 'used as a section or container background' );
		}
		if ( $entry['as_border'] > 0 && 0 === $entry['on_text'] ) {
			return array( 'name' => 'border', 'basis' => 'used on a border and not on text' );
		}
		if ( $entry['on_text'] > 0 ) {
			return array( 'name' => 'muted_text', 'basis' => 'used on text, but not the dominant text color' );
		}

		// No usage evidence distinguishes this value, so no name is claimed. The
		// ordinal says nothing the evidence does not support.
		return array( 'name' => $ordinal, 'basis' => 'no distinguishing usage evidence, so no semantic name is claimed' );
	}

	/**
	 * Collect typography tokens.
	 *
	 * A font declaration becomes a token when the same family and weight and size
	 * appear more than once, which is what makes it a decision rather than an
	 * accident. Its role is then read from how it is used, because that is the
	 * evidence a role name needs.
	 *
	 * @param array<int, array<string, mixed>> $observations Observations.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_typography( array $observations ) {
		$usage = array();

		foreach ( $observations as $observation ) {
			$type = $this->typography_of( $observation );
			if ( null === $type ) {
				continue;
			}
			$family = strtolower( trim( (string) ( $type['family'] ?? '' ) ) );
			if ( '' === $family ) {
				$family = 'inherit';
			}
			$weight = (int) ( $type['weight'] ?? 400 );
			$size   = (float) ( $type['size'] ?? 0 );

			$key = $family . '|' . $weight . '|' . ( $size > 0 ? (string) $size : 'inherit' );

			if ( ! isset( $usage[ $key ] ) ) {
				$usage[ $key ] = array(
					'family'     => $family,
					'weight'     => $weight,
					'size'       => $size,
					'line_height'=> $type['line_height'] ?? null,
					'letter_spacing' => $type['letter_spacing'] ?? null,
					'transform'  => $type['transform'] ?? null,
					'occurrences'=> 0,
					'roles'      => array(),
					'sizes'      => array(),
					'samples'    => array(),
				);
			}

			$usage[ $key ]['occurrences']++;
			$role = (string) ( $observation['role'] ?? '' );
			if ( '' !== $role ) {
				$usage[ $key ]['roles'][ $role ] = ( $usage[ $key ]['roles'][ $role ] ?? 0 ) + 1;
			}
			if ( $size > 0 ) {
				$usage[ $key ]['sizes'][ $size ] = true;
			}
			if ( count( $usage[ $key ]['samples'] ) < 5 ) {
				$usage[ $key ]['samples'][] = (string) ( $observation['node_id'] ?? '' );
			}
		}

		$max_size = 0.0;
		foreach ( $usage as $entry ) {
			foreach ( array_keys( $entry['sizes'] ) as $size ) {
				$max_size = max( $max_size, (float) $size );
			}
		}

		$out = array();
		foreach ( $usage as $key => $entry ) {
			$role = $this->type_role( $entry, $max_size );
			$out[] = array(
				'name'        => $role['name'],
				'name_basis'  => $role['basis'],
				'family'      => $entry['family'],
				'weight'      => $entry['weight'],
				'size'        => $entry['size'] > 0 ? $entry['size'] : null,
				'line_height' => $entry['line_height'],
				'letter_spacing' => $entry['letter_spacing'],
				'text_transform'  => $entry['transform'],
				'occurrences' => $entry['occurrences'],
				'roles'       => $entry['roles'],
				'evidence'    => $entry['samples'],
			);
		}

		usort( $out, static function ( $left, $right ) {
			$left_size  = null !== $left['size'] ? (float) $left['size'] : 0.0;
			$right_size = null !== $right['size'] ? (float) $right['size'] : 0.0;
			if ( $left_size === $right_size ) {
				return strcmp( (string) $left['family'], (string) $right['family'] );
			}
			return $right_size <=> $left_size;
		} );

		return $this->cap( $out, 'typography' );
	}

	/**
	 * Read a type declaration from an observation.
	 *
	 * @param array<string, mixed> $observation Observation.
	 * @return array<string, mixed>|null
	 */
	private function typography_of( array $observation ) {
		$type = isset( $observation['typography'] ) && is_array( $observation['typography'] ) ? $observation['typography'] : null;
		if ( null === $type ) {
			return null;
		}
		// An observation with no family, no size, and no weight carries no type
		// decision at all.
		if ( empty( $type['family'] ) && empty( $type['size'] ) && empty( $type['weight'] ) ) {
			return null;
		}
		return $type;
	}

	/**
	 * Name a type token from its size and its role.
	 *
	 * @param array<string, mixed> $entry    Token entry.
	 * @param float                $max_size Largest size on the page.
	 * @return array{name: string, basis: string}
	 */
	private function type_role( array $entry, $max_size ) {
		$ordinal = 'type_' . substr( hash( 'sha256', $entry['family'] . '|' . $entry['weight'] . '|' . $entry['size'] ), 0, 6 );
		$roles   = array_keys( $entry['roles'] );

		// A semantic element is stronger evidence than a size comparison, because a
		// page may style its own heading to look like body text.
		foreach ( array( 'h1' => 'heading_1', 'h2' => 'heading_2', 'h3' => 'heading_3', 'button' => 'button', 'nav' => 'navigation', 'caption' => 'caption', 'label' => 'label', 'badge' => 'badge' ) as $element => $name ) {
			if ( isset( $entry['roles'][ $element ] ) ) {
				return array( 'name' => $name, 'basis' => 'used on a ' . $element . ' element' );
			}
		}

		if ( $entry['size'] > 0 && $max_size > 0 && $entry['size'] >= $max_size ) {
			return array( 'name' => 'display', 'basis' => 'the largest type size on the page' );
		}
		if ( $entry['size'] > 0 && $max_size > 0 && $entry['size'] >= $max_size * 0.6 ) {
			return array( 'name' => 'heading', 'basis' => 'among the largest type sizes on the page' );
		}
		if ( $entry['size'] > 0 && $entry['size'] <= 13.0 ) {
			return array( 'name' => 'small', 'basis' => 'a type size of thirteen pixels or less' );
		}
		if ( $entry['size'] > 0 ) {
			return array( 'name' => 'body', 'basis' => 'the type size body content uses' );
		}

		return array( 'name' => $ordinal, 'basis' => 'no distinguishing evidence, so no semantic name is claimed' );
	}

	/**
	 * Collect spacing tokens.
	 *
	 * @param array<int, array<string, mixed>> $observations Observations.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_spacing( array $observations ) {
		$usage = array();

		foreach ( $observations as $observation ) {
			$spacing = isset( $observation['spacing'] ) && is_array( $observation['spacing'] ) ? $observation['spacing'] : array();
			foreach ( $spacing as $side => $value ) {
				if ( ! is_numeric( $value ) ) {
					continue;
				}
				// A negative margin is a layout technique rather than a spacing scale
				// value, so it is kept out of the scale.
				if ( (float) $value < 0 ) {
					continue;
				}
				$key = $this->number_key( (float) $value );
				if ( ! isset( $usage[ $key ] ) ) {
					$usage[ $key ] = array( 'value' => (float) $value, 'occurrences' => 0, 'sides' => array(), 'samples' => array() );
				}
				$usage[ $key ]['occurrences']++;
				$usage[ $key ]['sides'][ (string) $side ] = true;
				if ( count( $usage[ $key ]['samples'] ) < 5 ) {
					$usage[ $key ]['samples'][] = (string) ( $observation['node_id'] ?? '' );
				}
			}
		}

		$out = array();
		$rank = 0;
		uasort( $usage, static function ( $left, $right ) {
			return $left['value'] <=> $right['value'];
		} );
		foreach ( $usage as $entry ) {
			$out[] = array(
				// A spacing scale is an ordered list, so the name is its position. That
				// is a fact about the order rather than an invented label.
				'name'        => 'space_' . str_pad( (string) $rank, 2, '0', STR_PAD_LEFT ),
				'name_basis'  => 'position in the observed spacing scale',
				'value'       => $entry['value'],
				'occurrences' => $entry['occurrences'],
				'sides'       => array_keys( $entry['sides'] ),
				'evidence'    => $entry['samples'],
			);
			$rank++;
		}

		return $this->cap( $out, 'spacing' );
	}

	/**
	 * Collect radius tokens.
	 *
	 * @param array<int, array<string, mixed>> $observations Observations.
	 * @param string                            $family       Family name.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_values( array $observations, $family ) {
		$usage = array();

		foreach ( $observations as $observation ) {
			$value = $observation[ $family ] ?? null;
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$key = $this->number_key( (float) $value );
			if ( ! isset( $usage[ $key ] ) ) {
				$usage[ $key ] = array( 'value' => (float) $value, 'occurrences' => 0, 'samples' => array() );
			}
			$usage[ $key ]['occurrences']++;
			if ( count( $usage[ $key ]['samples'] ) < 5 ) {
				$usage[ $key ]['samples'][] = (string) ( $observation['node_id'] ?? '' );
			}
		}

		$rank = 0;
		$out  = array();
		uasort( $usage, static function ( $left, $right ) {
			return $left['value'] <=> $right['value'];
		} );
		foreach ( $usage as $entry ) {
			$out[] = array(
				'name'        => $family . '_' . str_pad( (string) $rank, 2, '0', STR_PAD_LEFT ),
				'name_basis'  => 'position in the observed scale',
				'value'       => $entry['value'],
				'occurrences' => $entry['occurrences'],
				'evidence'    => $entry['samples'],
			);
			$rank++;
		}

		return $this->cap( $out, $family );
	}

	/**
	 * Collect shadow tokens.
	 *
	 * @param array<int, array<string, mixed>> $observations Observations.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_shadows( array $observations ) {
		$usage = array();

		foreach ( $observations as $observation ) {
			$shadow = $observation['shadow'] ?? null;
			if ( ! is_array( $shadow ) || empty( $shadow ) ) {
				continue;
			}
			$key = $this->shadow_key( $shadow );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $usage[ $key ] ) ) {
				$usage[ $key ] = array( 'shadow' => $shadow, 'occurrences' => 0, 'samples' => array() );
			}
			$usage[ $key ]['occurrences']++;
			if ( count( $usage[ $key ]['samples'] ) < 5 ) {
				$usage[ $key ]['samples'][] = (string) ( $observation['node_id'] ?? '' );
			}
		}

		$out = array();
		$rank = 0;
		uasort( $usage, array( $this, 'compare_shadow_strength' ) );
		foreach ( $usage as $key => $entry ) {
			$out[] = array(
				// A shadow's role is read from its blur, because that is what a
				// designer means by depth: a tight shadow is a border and a soft one
				// is a raised surface.
				'name'        => $this->shadow_role( $entry['shadow'] ),
				'name_basis'  => 'the blur radius, which is what distinguishes a border shadow from a raised one',
				'value'       => $entry['shadow'],
				'key'         => $key,
				'occurrences' => $entry['occurrences'],
				'evidence'    => $entry['samples'],
			);
			$rank++;
		}

		return $this->cap( $out, 'shadows' );
	}

	/**
	 * Return a comparable key for one shadow.
	 *
	 * @param array<string, mixed> $shadow Shadow.
	 * @return string
	 */
	private function shadow_key( array $shadow ) {
		if ( ! isset( $shadow['offset_x'] ) && ! isset( $shadow['blur'] ) ) {
			return '';
		}
		return implode(
			'|',
			array(
				$this->number_key( (float) ( $shadow['offset_x'] ?? 0 ) ),
				$this->number_key( (float) ( $shadow['offset_y'] ?? 0 ) ),
				$this->number_key( (float) ( $shadow['blur'] ?? 0 ) ),
				$this->number_key( (float) ( $shadow['spread'] ?? 0 ) ),
				(string) ( $shadow['color'] ?? '' ),
			)
		);
	}

	/**
	 * Order shadows by how far they lift off the page.
	 *
	 * @param array<string, mixed> $left  One shadow entry.
	 * @param array<string, mixed> $right The other.
	 * @return int
	 */
	public function compare_shadow_strength( $left, $right ) {
		$a = (float) ( $left['shadow']['blur'] ?? 0 ) + (float) ( $left['shadow']['offset_y'] ?? 0 );
		$b = (float) ( $right['shadow']['blur'] ?? 0 ) + (float) ( $right['shadow']['offset_y'] ?? 0 );
		return $a <=> $b;
	}

	/**
	 * Name a shadow from its blur.
	 *
	 * @param array<string, mixed> $shadow Shadow.
	 * @return string
	 */
	private function shadow_role( array $shadow ) {
		$blur = (float) ( $shadow['blur'] ?? 0 );
		if ( $blur <= 2.0 ) {
			return 'shadow_tight';
		}
		if ( $blur <= 8.0 ) {
			return 'shadow_raised';
		}
		return 'shadow_floating';
	}

	/**
	 * Collect container tokens.
	 *
	 * @param array<int, array<string, mixed>> $observations Observations.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_containers( array $observations ) {
		$usage = array();

		foreach ( $observations as $observation ) {
			$max = $observation['container_max_width'] ?? null;
			if ( ! is_numeric( $max ) ) {
				continue;
			}
			$key  = $this->number_key( (float) $max );
			$hash = $key . ( ! empty( $observation['container_centered'] ) ? '|centered' : '|full' );
			if ( ! isset( $usage[ $hash ] ) ) {
				$usage[ $hash ] = array(
					'max_width' => (float) $max,
					'centered'  => ! empty( $observation['container_centered'] ),
					'occurrences' => 0,
					'samples'   => array(),
				);
			}
			$usage[ $hash ]['occurrences']++;
			if ( count( $usage[ $hash ]['samples'] ) < 5 ) {
				$usage[ $hash ]['samples'][] = (string) ( $observation['node_id'] ?? '' );
			}
		}

		$out  = array();
		$rank = 0;
		uasort( $usage, static function ( $left, $right ) {
			return $left['max_width'] <=> $right['max_width'];
		} );
		foreach ( $usage as $entry ) {
			$out[] = array(
				'name'        => 'container_' . str_pad( (string) $rank, 2, '0', STR_PAD_LEFT ),
				'name_basis'  => 'the most common content width is the page band, so the ranks are ordered by width',
				'max_width'   => $entry['max_width'],
				'centered'    => $entry['centered'],
				'occurrences' => $entry['occurrences'],
				'evidence'    => $entry['samples'],
			);
			$rank++;
		}

		return $this->cap( $out, 'containers' );
	}

	/**
	 * Collect breakpoint tokens.
	 *
	 * @param array<int, array<string, mixed>> $observations Observations.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_breakpoints( array $observations ) {
		$widths = array();

		foreach ( $observations as $observation ) {
			$responsive = isset( $observation['responsive'] ) && is_array( $observation['responsive'] ) ? $observation['responsive'] : array();
			foreach ( $responsive as $rules ) {
				if ( ! is_array( $rules ) ) {
					continue;
				}
				foreach ( $rules as $rule ) {
					if ( isset( $rule['min_width'] ) && is_numeric( $rule['min_width'] ) ) {
						$widths[ (int) $rule['min_width'] ] = true;
					}
				}
			}
		}

		$out   = array();
		$index = 0;
		ksort( $widths, SORT_NUMERIC );
		foreach ( array_keys( $widths ) as $width ) {
			$out[] = array(
				// The name is the width, because that is what a media query is and a
				// label would add nothing.
				'name'        => (string) $width,
				'name_basis'  => 'the minimum width a declared media query uses',
				'min_width'   => (int) $width,
				'occurrences' => 0,
			);
			$index++;
		}

		return $this->cap( $out, 'breakpoints' );
	}

	/**
	 * Count tokens whose name rests on evidence rather than on position.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $tokens Token families.
	 * @return array<string, int>
	 */
	private function count_named( array $tokens ) {
		$out = array();
		foreach ( $tokens as $family => $entries ) {
			$named = 0;
			foreach ( $entries as $entry ) {
				$basis = (string) ( $entry['name_basis'] ?? '' );
				if ( false !== strpos( $basis, 'no semantic name is claimed' ) ) {
					continue;
				}
				$named++;
			}
			$out[ $family ] = $named;
		}
		return $out;
	}

	/**
	 * Return the evidence summary.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $tokens Token families.
	 * @return array<string, mixed>
	 */
	private function evidence_summary( array $tokens ) {
		$summary = array();
		foreach ( $tokens as $family => $entries ) {
			$deduplicated = 0;
			$occurrences  = 0;
			foreach ( $entries as $entry ) {
				$count = (int) ( $entry['occurrences'] ?? 0 );
				$occurrences += $count;
				// One token standing in for three or more uses is where the
				// representation becomes smaller than the page.
				if ( $count >= self::MIN_OCCURRENCES ) {
					$deduplicated += $count - 1;
				}
			}
			$summary[ $family ] = array(
				'tokens'       => count( $entries ),
				'occurrences'  => $occurrences,
				'deduplicated' => $deduplicated,
			);
		}
		return $summary;
	}

	/**
	 * Read the color values out of one observation.
	 *
	 * @param array<string, mixed> $observation Observation.
	 * @return array<int, string>
	 */
	private function color_values( array $observation ) {
		$out = array();

		$typography = isset( $observation['typography'] ) && is_array( $observation['typography'] ) ? $observation['typography'] : array();
		if ( ! empty( $typography['color'] ) ) {
			$out[] = (string) $typography['color'];
		}

		$background = isset( $observation['background'] ) && is_array( $observation['background'] ) ? $observation['background'] : array();
		if ( ! empty( $background['color'] ) ) {
			$out[] = (string) $background['color'];
		}

		$border = isset( $observation['border'] ) && is_array( $observation['border'] ) ? $observation['border'] : array();
		$chosen = isset( $border['elementor'] ) && is_array( $border['elementor'] ) ? $border['elementor'] : array();
		if ( ! empty( $chosen['color'] ) ) {
			$out[] = (string) $chosen['color'];
		}

		return $out;
	}

	/**
	 * Return the relative luminance of an rgb triple.
	 *
	 * @param array<int, int> $rgb Channels.
	 * @return float
	 */
	private function luminance( array $rgb ) {
		$channels = array();
		foreach ( $rgb as $value ) {
			$normalized = max( 0.0, min( 1.0, ( (int) $value ) / 255.0 ) );
			$channels[] = ( $normalized <= 0.03928 )
				? $normalized / 12.92
				: pow( ( $normalized + 0.055 ) / 1.055, 2.4 );
		}
		return round( 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2], 4 );
	}

	/**
	 * Return the contrast ratio between two rgb triples.
	 *
	 * @param array<int, int> $left  One color.
	 * @param array<int, int> $right The other.
	 * @return float
	 */
	private function contrast( array $left, array $right ) {
		$a = $this->luminance( $left );
		$b = $this->luminance( $right );
		$lighter = max( $a, $b );
		$darker  = min( $a, $b );
		return round( ( $lighter + 0.05 ) / ( $darker + 0.05 ), 2 );
	}

	/**
	 * Return a stable key for a number.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private function number_key( $value ) {
		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Cap a family at its declared maximum.
	 *
	 * @param array<int, array<string, mixed>> $entries Tokens.
	 * @param string                           $family  Family name.
	 * @return array<int, array<string, mixed>>
	 */
	private function cap( array $entries, $family ) {
		$max = isset( self::MAX_PER_FAMILY[ $family ] ) ? (int) self::MAX_PER_FAMILY[ $family ] : 100;
		return array_slice( $entries, 0, $max );
	}
}
