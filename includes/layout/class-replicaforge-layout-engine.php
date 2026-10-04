<?php
/**
 * Phase 8: layout relationship engine.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a node's layout and the relationships between nodes.
 *
 * Phase 2 already reads a display type and a column count. That is enough to say
 * "this is a three-column row", and not enough to reproduce one: it says nothing
 * about the gap, the track sizing, the alignment, whether a child spans two
 * columns, or whether a floating badge is overlapping the section above it.
 *
 * This engine reads the whole layout grammar and reports what it found, the
 * Elementor structure that is the closest editable equivalent, and — where the
 * equivalent is only approximate — exactly what the approximation costs. A caller
 * that needs to record a limitation can, because the limitation is data rather
 * than something it has to infer from a missing field.
 */
final class Layout_Engine {

	/**
	 * Layout relationships reported between two nodes.
	 *
	 * These are the relations that survive into an Elementor document. A relation
	 * with no editable equivalent is still reported, because "these two overlap and
	 * Elementor cannot express that" is information a user needs before they edit
	 * the page, and omitting it would leave them to discover it by eye.
	 */
	const RELATIONS = array(
		'contains',
		'sibling',
		'aligned_with',
		'stacked_with',
		'overlaps',
		'anchored_to',
		'centered_in',
		'constrained_by',
		'full_width',
	);

	/**
	 * Maximum number of relationships recorded for one node.
	 */
	const MAX_RELATIONS_PER_NODE = 32;

	/**
	 * Font size used to resolve em-based lengths.
	 *
	 * @var float
	 */
	private $font_size;

	/**
	 * Viewport width used to resolve viewport-based lengths.
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
	 * Read one node's complete layout.
	 *
	 * @param array<string, mixed> $declarations Computed declarations for the node.
	 * @return array<string, mixed>
	 */
	public function analyze( array $declarations ) {
		$display  = $this->keyword( $declarations, 'display' );
		$position = $this->keyword( $declarations, 'position' );

		$layout = array(
			'display'     => $display,
			'position'    => $position,
			'mode'        => $this->mode( $display ),
			'box'         => $this->box( $declarations ),
			'grid'        => $this->grid( $declarations ),
			'flex'        => $this->flex( $declarations ),
			'placement'   => $this->placement( $declarations ),
			'spacing'     => $this->spacing( $declarations ),
			'overflow'    => $this->keyword( $declarations, 'overflow' ),
			'align_self'  => $this->keyword( $declarations, 'align-self' ),
			'justify_self'=> $this->keyword( $declarations, 'justify-self' ),
			'order'       => $this->order( $declarations ),
			'aspect'      => $this->aspect_ratio( $declarations ),
		);

		// A mode is only reported when the declarations that produce it are present.
		// A node with no display declaration inherits, and claiming `block` for it
		// would put an inference in the representation as if it were an observation.
		$layout['supported'] = ( null !== $display || ! empty( $layout['grid']['present'] ) || ! empty( $layout['flex']['present'] ) );

		return $layout;
	}

	/**
	 * Return the layout mode, from the display value or from grid and flex evidence.
	 *
	 * @param string|null $display Display value.
	 * @return string
	 */
	private function mode( $display ) {
		if ( 'grid' === $display || 'inline-grid' === $display ) {
			return 'grid';
		}
		if ( 'flex' === $display || 'inline-flex' === $display ) {
			return 'flex';
		}
		if ( 'block' === $display || 'flow-root' === $display || 'list-item' === $display ) {
			return 'block';
		}
		if ( 'table' === $display || 'inline-table' === $display ) {
			return 'table';
		}
		if ( null !== $display && 0 === strpos( $display, 'inline' ) ) {
			return 'inline';
		}
		return 'unknown';
	}

	/**
	 * Read the box model: size, constraint, and spacing.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, mixed>
	 */
	private function box( array $declarations ) {
		$width      = $this->length( $declarations, 'width' );
		$max_width  = $this->length( $declarations, 'max-width' );
		$min_width  = $this->length( $declarations, 'min-width' );
		$height     = $this->length( $declarations, 'height' );
		$box_sizing = $this->keyword( $declarations, 'box-sizing' );

		// A percentage is not a pixel length, so it is never converted to one.
		// It is still the most important width declaration on a real page:
		// `width: 100%` is how a full-bleed row is written, and reading only pixels
		// made every full-width band look like an auto-width block.
		$width_ratio    = $this->ratio( $declarations, 'width' );
		$width_viewport = $this->viewport_ratio( $declarations, 'width' );
		$max_ratio      = $this->ratio( $declarations, 'max-width' );
		$max_viewport   = $this->viewport_ratio( $declarations, 'max-width' );

		$auto_margins = array();
		foreach ( array( 'margin-left', 'margin-right' ) as $property ) {
			$auto_margins[] = $this->is_auto( $declarations, $property );
		}
		$centered = $auto_margins[0] && $auto_margins[1];

		// Full bleed is any of the three ways a page writes it: a pixel width at or
		// above the viewport, a 100% width, or a 100vw width.
		$full_width = ( null !== $width && $width >= $this->viewport )
			|| ( null !== $width_ratio && $width_ratio >= 0.99 )
			|| ( null !== $width_viewport && $width_viewport >= 0.99 );

		// A maximum is a constraint whatever unit it is written in. A page that
		// constrains its content with `max-width: 72rem` is as constrained as one
		// using pixels, and treating the former as unconstrained lost the boxed band.
		$constrained = null !== $max_width || null !== $max_ratio || null !== $max_viewport;

		$kind = 'auto';
		if ( $constrained ) {
			$kind = 'boxed';
		} elseif ( $full_width ) {
			$kind = 'full_width';
		} elseif ( $centered ) {
			$kind = 'centered';
		} elseif ( null !== $width || null !== $width_ratio ) {
			$kind = 'fixed';
		}

		return array(
			'kind'         => $kind,
			'width'        => $width,
			'width_ratio'  => $width_ratio,
			'width_viewport' => $width_viewport,
			'max_width'    => $max_width,
			'max_ratio'    => $max_ratio,
			'max_viewport' => $max_viewport,
			'min_width'    => $min_width,
			'height'       => $height,
			'centered'     => $centered,
			'full_width'   => $full_width,
			'box_sizing'   => $box_sizing,
			// Elementor expresses a constrained band as a content width plus an
			// alignment, which is not the same thing as a max-width, so the value is
			// carried across rather than dropped.
			'elementor'    => $this->elementor_width( $kind, $max_width, $max_ratio, $centered, $full_width ),
		);
	}

	/**
	 * Map a box onto the Elementor width model.
	 *
	 * A band constrained by `max-width: 72rem` is translated using the root font
	 * size, and a band constrained by a percentage is already the unit Elementor
	 * uses, so neither is invented.
	 *
	 * @param string     $kind       Box kind.
	 * @param float|null $max_width  Maximum width in pixels.
	 * @param float|null $max_ratio  Maximum width as a fraction.
	 * @param bool       $centered   Whether margins center it.
	 * @param bool       $full_width Whether it spans the band.
	 * @return array<string, mixed>
	 */
	private function elementor_width( $kind, $max_width, $max_ratio, $centered, $full_width ) {
		if ( 'boxed' === $kind ) {
			// A percentage is already the unit Elementor states, so it is exact.
			if ( null !== $max_ratio ) {
				return array(
					'model'       => 'boxed',
					'percent'     => (int) max( 1, min( 100, round( $max_ratio * 100 ) ) ),
					'centered'    => $centered,
					'approximate' => false,
					'limitation'  => null,
				);
			}

			// A pixel maximum is a different unit from an Elementor content width,
			// so the nearest percentage is offered and the change is recorded.
			$percent = null;
			if ( null !== $max_width && $this->viewport > 0 ) {
				$percent = (int) max( 1, min( 100, round( ( $max_width / $this->viewport ) * 100 ) ) );
			}
			return array(
				'model'       => 'boxed',
				'percent'     => $percent,
				'centered'    => $centered,
				'approximate' => null !== $percent,
				'limitation'  => null === $percent
					? null
					: 'max-width is a pixel value; Elementor states a content width as a percentage of the container, so the band is reproduced as the nearest percentage.',
			);
		}

		if ( $full_width ) {
			return array( 'model' => 'full_width', 'percent' => 100, 'centered' => true, 'approximate' => false, 'limitation' => null );
		}

		if ( $centered ) {
			return array( 'model' => 'boxed', 'percent' => 100, 'centered' => true, 'approximate' => false, 'limitation' => null );
		}

		// A fractional width is directly translatable.
		if ( null !== $max_ratio ) {
			return array( 'model' => 'boxed', 'percent' => (int) max( 1, min( 100, round( $max_ratio * 100 ) ) ), 'centered' => false, 'approximate' => false, 'limitation' => null );
		}

		return array( 'model' => 'full_width', 'percent' => 100, 'centered' => true, 'approximate' => false, 'limitation' => null );
	}
	/**
	 * Read CSS Grid.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, mixed>
	 */
	private function grid( array $declarations ) {
		$columns = $this->track_list( $declarations, 'grid-template-columns' );
		$rows    = $this->track_list( $declarations, 'grid-template-rows' );
		$present = ! empty( $columns ) || ! empty( $rows );

		$flow = $this->keyword( $declarations, 'grid-auto-flow' );
		$auto = $this->keyword( $declarations, 'grid-auto-columns' );

		$repeat = null;
		$template = $this->raw( $declarations, 'grid-template-columns' );
		if ( null !== $template && preg_match( '/repeat\s*\(\s*(\d+)\s*,/', $template, $matches ) ) {
			$repeat = (int) $matches[1];
		}

		$repeat_auto = null;
		if ( null !== $template && preg_match( '/repeat\s*\(\s*(auto-fit|auto-fill)\s*,/', $template, $matches ) ) {
			$repeat_auto = $matches[1];
		}

		$areas = $this->raw( $declarations, 'grid-template-areas' );

		return array(
			'present'            => $present,
			'columns'            => $columns,
			'rows'               => $rows,
			'column_count'       => null !== $repeat ? $repeat : count( $columns ),
			'row_count'          => count( $rows ),
			'auto_flow'          => $flow,
			'auto_columns'       => $auto,
			'repeat_explicit'    => $repeat,
			'repeat_responsive'  => $repeat_auto,
			'areas'              => $this->named_areas( $areas ),
			'gap'                => $this->gap( $declarations, 'grid' ),
			'justify_items'      => $this->keyword( $declarations, 'justify-items' ),
			'align_items'        => $this->keyword( $declarations, 'align-items' ),
			'justify_content'    => $this->keyword( $declarations, 'justify-content' ),
			'align_content'      => $this->keyword( $declarations, 'align-content' ),
			// A responsive repeat has no fixed column count, so a fixed-column
			// Elementor container is the closest equivalent and the difference is
			// recorded rather than presented as an exact match.
			'elementor'          => $this->elementor_grid( $repeat, count( $columns ), $repeat_auto ),
		);
	}

	/**
	 * Map a grid onto an Elementor container.
	 *
	 * @param int|null $repeat      Explicit repeat count.
	 * @param int      $track_count Number of resolved tracks.
	 * @param string|null $repeat_auto A responsive repeat keyword.
	 * @return array<string, mixed>
	 */
	private function elementor_grid( $repeat, $track_count, $repeat_auto ) {
		$columns = null !== $repeat ? $repeat : $track_count;

		if ( null !== $repeat_auto ) {
			return array(
				'columns'    => 2,
				'approximate' => true,
				'limitation' => 'The source uses repeat(' . $repeat_auto . ', …), which chooses its column count from the available width. Elementor containers hold a fixed column count, so this is a fixed approximation and the source will reflow where the replica will not.',
			);
		}

		if ( null === $columns || $columns < 1 ) {
			return array( 'columns' => 1, 'approximate' => false, 'limitation' => null );
		}

		// Elementor supports a bounded column count. A grid of more than six columns
		// is real — a dense card wall, for instance — and is clamped with the reason
		// recorded rather than silently truncated.
		if ( $columns > 6 ) {
			return array(
				'columns'     => 6,
				'approximate' => true,
				'limitation'  => 'The source grid has ' . $columns . ' columns. Elementor containers support at most six, so the replica uses six and the source would show a finer grid at the same width.',
			);
		}

		return array( 'columns' => $columns, 'approximate' => false, 'limitation' => null );
	}

	/**
	 * Read Flexbox.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, mixed>
	 */
	private function flex( array $declarations ) {
		$direction = $this->keyword( $declarations, 'flex-direction' );
		$wrap      = $this->keyword( $declarations, 'flex-wrap' );
		$present   = null !== $direction || null !== $wrap || null !== $this->raw( $declarations, 'gap' );

		return array(
			'present'          => $present,
			'direction'        => $direction,
			'wrap'             => $wrap,
			'gap'              => $this->gap( $declarations, 'flex' ),
			'justify_content'  => $this->keyword( $declarations, 'justify-content' ),
			'align_items'      => $this->keyword( $declarations, 'align-items' ),
			'align_content'    => $this->keyword( $declarations, 'align-content' ),
			'align_self'       => $this->keyword( $declarations, 'align-self' ),
			'order'            => $this->order( $declarations ),
			'elementor'        => $this->elementor_flex( $declarations, $direction, $wrap ),
		);
	}

	/**
	 * Map a flex container onto an Elementor container.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string|null          $direction    Flex direction.
	 * @param string|null          $wrap         Flex wrap.
	 * @return array<string, mixed>
	 */
	private function elementor_flex( array $declarations, $direction, $wrap ) {
		$limitation = null;

		if ( 'wrap' === $wrap || 'wrap-reverse' === $wrap ) {
			// A wrapping row is the classic responsive card grid. Elementor's
			// equivalent is a fixed column count, so the line break is reproduced at
			// the width where the source would break and is not fluid.
			$limitation = 'The source flex container wraps, so its line breaks depend on the available width. Elementor columns have a fixed width, so the replica breaks at one width rather than reflowing continuously.';
		}

		$direction_label = 'row' === $direction ? 'horizontal' : ( 'column' === $direction ? 'vertical' : '' );

		return array(
			'content_direction' => $direction_label,
			'align'             => $this->elementor_align( $declarations, $direction ),
			'justify'           => $this->elementor_justify( $declarations, $direction ),
			'wrap_supported'    => false,
			'limitation'        => $limitation,
		);
	}

	/**
	 * Map the cross-axis alignment onto an Elementor container alignment.
	 *
	 * For a flex row the cross axis is vertical, which is the axis an Elementor
	 * container aligns on directly. For a flex column the cross axis is horizontal,
	 * which Elementor expresses as content alignment instead, so the value is mapped
	 * onto the closest available control and the axis is reported so a caller knows
	 * which one was used.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string|null          $direction    Flex direction.
	 * @return array<string, mixed>
	 */
	private function elementor_align( array $declarations, $direction ) {
		$align = $this->keyword( $declarations, 'align-items' );

		$map = array(
			'flex-start' => 'top',
			'start'      => 'top',
			'center'     => 'center',
			'flex-end'   => 'bottom',
			'end'        => 'bottom',
			'stretch'    => 'stretch',
			'baseline'   => 'top',
			'normal'     => 'top',
		);

		if ( null === $align ) {
			// The CSS default is stretch, and the Elementor default is top. Reporting
			// the observed default as if it were declared would be a claim, so the
			// absence is reported and the value is marked as defaulted.
			return array(
				'value'   => 'top',
				'axis'    => 'cross',
				'source'  => 'default',
				'limitation' => 'No align-items declaration was found, so the CSS default of stretch is assumed. If the source relies on stretching, the replica will align its children to the top instead.',
			);
		}

		$axis = ( 'column' === $direction ) ? 'horizontal' : 'vertical';

		return array(
			'value'      => isset( $map[ $align ] ) ? $map[ $align ] : $align,
			'declared'   => $align,
			'axis'       => $axis,
			'source'     => 'declaration',
			'limitation' => ( 'column' === $direction )
				? 'A flex column aligns on the horizontal axis. Elementor aligns a container vertically, so the alignment is reproduced on the child widths rather than on the container.'
				: ( 'baseline' === $align
					? 'Baseline alignment has no direct Elementor equivalent and is reproduced as top alignment.'
					: null ),
		);
	}

	/**
	 * Map the main-axis distribution onto Elementor's content alignment.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string|null          $direction    Flex direction.
	 * @return array<string, mixed>
	 */
	private function elementor_justify( array $declarations, $direction ) {
		$justify = $this->keyword( $declarations, 'justify-content' );

		$map = array(
			'flex-start'    => 'flex-start',
			'start'         => 'flex-start',
			'center'        => 'center',
			'flex-end'      => 'flex-end',
			'end'           => 'flex-end',
			'space-between' => 'space-between',
			'space-around'  => 'space-around',
			'space-evenly'  => 'space-evenly',
			'stretch'       => 'stretch',
			'normal'        => 'flex-start',
		);

		$limitation = null;
		if ( 'space-around' === $justify || 'space-evenly' === $justify ) {
			$limitation = 'space-around and space-evenly distribute the gaps relative to the container edges, which Elementor does not reproduce exactly. The children keep their widths and the distribution is approximated.';
		}

		return array(
			'value'      => null !== $justify && isset( $map[ $justify ] ) ? $map[ $justify ] : 'flex-start',
			'declared'   => $justify,
			'axis'       => ( 'column' === $direction ) ? 'vertical' : 'horizontal',
			'source'     => null !== $justify ? 'declaration' : 'default',
			'limitation' => $limitation,
		);
	}

	/**
	 * Read positioning, which drives the relationship report.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, mixed>
	 */
	private function placement( array $declarations ) {
		$position = $this->keyword( $declarations, 'position' );
		$sticky   = null;

		if ( 'fixed' === $position || 'sticky' === $position ) {
			$sticky = array(
				'mode'     => $position,
				'top'      => $this->length( $declarations, 'top' ),
				'right'    => $this->length( $declarations, 'right' ),
				'bottom'   => $this->length( $declarations, 'bottom' ),
				'left'     => $this->length( $declarations, 'left' ),
				'z_index'  => $this->z_index( $declarations ),
			);
		}

		return array(
			'position'    => $position,
			'inset'       => $this->inset( $declarations ),
			'z_index'     => $this->z_index( $declarations ),
			'transform'   => $this->raw( $declarations, 'transform' ),
			'float'       => $this->keyword( $declarations, 'float' ),
			'clear'       => $this->keyword( $declarations, 'clear' ),
			'sticky'      => $sticky,
			'margin'      => $this->margins( $declarations ),
			// An out-of-flow element is the case where flattening into normal flow
			// would visibly move it, so it is flagged for the relationship engine.
			'out_of_flow' => in_array( $position, array( 'absolute', 'fixed' ), true ),
			'in_flow'     => 'sticky' === $position,
		);
	}

	/**
	 * Read the inset, which is how a positioned element is anchored.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, mixed>
	 */
	private function inset( array $declarations ) {
		return array(
			'top'    => $this->length( $declarations, 'top' ),
			'right'  => $this->length( $declarations, 'right' ),
			'bottom' => $this->length( $declarations, 'bottom' ),
			'left'   => $this->length( $declarations, 'left' ),
		);
	}

	/**
	 * Read a z-index.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return int|null
	 */
	private function z_index( array $declarations ) {
		$raw = $this->raw( $declarations, 'z-index' );
		if ( null === $raw ) {
			return null;
		}
		if ( is_numeric( $raw ) ) {
			return (int) $raw;
		}
		return null;
	}

	/**
	 * Read margins, keeping negative values.
	 *
	 * A negative bottom margin is how a section pulls up over the one above it, so
	 * the sign is the evidence for an overlap and must survive.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, float|null>
	 */
	private function margins( array $declarations ) {
		$shorthand = $this->raw( $declarations, 'margin' );
		$parts     = ( null !== $shorthand ) ? Css_Value_Parser::split( $shorthand, ' ' ) : array();

		$map = array(
			'top'    => 'margin-top',
			'right'  => 'margin-right',
			'bottom' => 'margin-bottom',
			'left'   => 'margin-left',
		);

		$out = array();
		foreach ( $map as $side => $property ) {
			$explicit = $this->length( $declarations, $property );
			if ( null !== $explicit ) {
				$out[ $side ] = $explicit;
				continue;
			}
			if ( empty( $parts ) ) {
				$out[ $side ] = null;
				continue;
			}
			$out[ $side ] = $this->read_length( $parts[ $this->shorthand_index( $side, count( $parts ) ) ] );
		}

		return $out;
	}

	/**
	 * Return the shorthand index for one side.
	 *
	 * @param string $side  Side name.
	 * @param int    $count Number of parts.
	 * @return int
	 */
	private function shorthand_index( $side, $count ) {
		if ( 1 === $count ) {
			return 0;
		}
		if ( 2 === $count ) {
			return ( 'left' === $side || 'right' === $side ) ? 1 : 0;
		}
		if ( 3 === $count ) {
			return 'top' === $side ? 0 : ( 'bottom' === $side ? 2 : 1 );
		}
		return array_search( $side, array( 'top', 'right', 'bottom', 'left' ), true );
	}

	/**
	 * Read padding, which does not need its negative sign preserved.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, float|null>
	 */
	private function spacing( array $declarations ) {
		$shorthand = $this->raw( $declarations, 'padding' );
		$parts     = ( null !== $shorthand ) ? Css_Value_Parser::split( $shorthand, ' ' ) : array();

		$out = array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$explicit = $this->length( $declarations, 'padding-' . $side );
			if ( null !== $explicit ) {
				$out[ $side ] = $explicit;
				continue;
			}
			$out[ $side ] = empty( $parts )
				? null
				: $this->read_length( $parts[ $this->shorthand_index( $side, count( $parts ) ) ] );
		}

		return $out;
	}

	/**
	 * Read a gap, preferring the row and column properties over the shorthand.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $prefix        Either `grid` or `flex`.
	 * @return array<string, float|null>
	 */
	private function gap( array $declarations, $prefix ) {
		// The unprefixed longhand and the bare gap shorthand are what a page
		// declares today. The prefixed names are the legacy spelling and are read
		// only as a fallback, because a page that uses both means the unprefixed one.
		$row   = $this->length( $declarations, 'row-gap' );
		$col   = $this->length( $declarations, 'column-gap' );
		$short = $this->raw( $declarations, 'gap' );

		if ( null === $short ) {
			$short = $this->raw( $declarations, $prefix . '-gap' );
		}
		if ( null === $row ) {
			$row = $this->length( $declarations, $prefix . '-row-gap' );
		}
		if ( null === $col ) {
			$col = $this->length( $declarations, $prefix . '-column-gap' );
		}

		if ( null !== $short ) {
			$parts = Css_Value_Parser::split( $short, ' ' );
			if ( null === $row && isset( $parts[0] ) ) {
				$row = $this->read_length( $parts[0] );
			}
			if ( null === $col ) {
				$col = isset( $parts[1] ) ? $this->read_length( $parts[1] ) : null;
			}
		}

		// A one-value gap applies to both axes.
		if ( null === $col ) {
			$col = $row;
		}
		if ( null === $row ) {
			$row = $col;
		}

		return array(
			'row'     => $row,
			'column'  => $col,
			'present' => null !== $row || null !== $col,
		);
	}
	/**
	 * Read an aspect ratio.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return array<string, float>|null
	 */
	private function aspect_ratio( array $declarations ) {
		$raw = $this->raw( $declarations, 'aspect-ratio' );
		if ( null === $raw || 'auto' === $raw ) {
			return null;
		}
		if ( is_numeric( $raw ) ) {
			return array( 'width' => (float) $raw, 'height' => 1.0 );
		}
		if ( preg_match( '/^([\d.]+)\s*\/\s*([\d.]+)$/', $raw, $matches ) && (float) $matches[2] > 0 ) {
			return array(
				'width'  => (float) $matches[1],
				'height' => (float) $matches[2],
			);
		}
		return null;
	}

	/**
	 * Parse a track list into resolved and unresolved tracks.
	 *
	 * `repeat(3, 1fr)` becomes three fractional tracks; `minmax(200px, 1fr)` keeps its
	 * bounds; `auto-fit` and `auto-fill` are recorded separately because they are
	 * not tracks at all but a repeat instruction.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $property     Property name.
	 * @return array<int, array<string, mixed>>
	 */
	private function track_list( array $declarations, $property ) {
		$raw = $this->raw( $declarations, $property );
		if ( null === $raw || 'none' === $raw ) {
			return array();
		}

		$raw = preg_replace( '/\brepeat\s*\(([^()]*)\)/i', '', $raw );
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array();
		}

		$tracks = array();
		foreach ( Css_Value_Parser::split( $raw, ' ' ) as $part ) {
			$track = $this->read_track( $part );
			if ( null !== $track ) {
				$tracks[] = $track;
			}
		}

		return $tracks;
	}

	/**
	 * Read one track.
	 *
	 * @param string $part Track value.
	 * @return array<string, mixed>|null
	 */
	private function read_track( $part ) {
		$part = trim( $part );
		if ( '' === $part ) {
			return null;
		}

		if ( preg_match( '/^minmax\s*\((.+)\)$/i', $part, $matches ) ) {
			$bounds = Css_Value_Parser::split( $matches[1], ',' );
			return array(
				'type'   => 'minmax',
				'min'    => isset( $bounds[0] ) ? $bounds[0] : null,
				'max'    => isset( $bounds[1] ) ? $bounds[1] : null,
			);
		}

		if ( preg_match( '/^([\d.]+)fr$/i', $part, $matches ) ) {
			return array( 'type' => 'fr', 'value' => (float) $matches[1] );
		}

		$length = $this->read_length( $part );
		if ( null !== $length ) {
			return array( 'type' => 'length', 'value' => $length );
		}

		if ( in_array( strtolower( $part ), array( 'auto', 'min-content', 'max-content' ), true ) ) {
			return array( 'type' => strtolower( $part ) );
		}

		return array( 'type' => 'unknown', 'raw' => $part );
	}

	/**
	 * Parse a named-area template into its row structure.
	 *
	 * @param string|null $raw Template value.
	 * @return array<int, array<int, string>>|null
	 */
	private function named_areas( $raw ) {
		if ( null === $raw || 'none' === $raw ) {
			return null;
		}
		$rows = array();
		foreach ( Css_Value_Parser::split( $raw, ' ' ) as $line ) {
			$cells = Css_Value_Parser::split( trim( $line, '"' ), ' ' );
			if ( ! empty( $cells ) ) {
				$rows[] = $cells;
			}
		}
		return empty( $rows ) ? null : $rows;
	}

	/**
	 * Read the visual order, which a flex or grid container may reorder children.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @return int|null
	 */
	private function order( array $declarations ) {
		$raw = $this->raw( $declarations, 'order' );
		return is_numeric( $raw ) ? (int) $raw : null;
	}

	/**
	 * Derive the relationships between a set of laid-out nodes.
	 *
	 * The relations reported are the ones an editable document can preserve. Sibling
	 * and alignment come from the tree and the flex alignment; overlap comes from
	 * negative margins, transforms, and out-of-flow placement; anchoring comes from
	 * an absolute element's nearest positioned ancestor.
	 *
	 * @param array<int, array<string, mixed>> $nodes Laid-out nodes, each with `id` and `analyze` output.
	 * @return array<int, array<string, mixed>>
	 */
	public function relate( array $nodes ) {
		$relations = array();
		$by_id     = array();

		foreach ( $nodes as $node ) {
			if ( ! isset( $node['id'] ) ) {
				continue;
			}
			$by_id[ (string) $node['id'] ] = $node;
		}

		// Sibling and alignment, from the declared tree.
		foreach ( $nodes as $node ) {
			if ( empty( $node['children'] ) || ! is_array( $node['children'] ) ) {
				continue;
			}
			$children = array_values( array_filter( array_map( 'strval', $node['children'] ) ) );

			$relations[] = array(
				'relationship' => 'contains',
				'source'       => (string) $node['id'],
				'target'       => $children,
				'confidence'   => 1.0,
				'evidence'     => 'dom',
			);

			$siblings = array();
			foreach ( $children as $child_id ) {
				if ( ! isset( $by_id[ $child_id ] ) ) {
					continue;
				}
				$siblings[] = array(
					'relationship' => 'stacked_with',
					'source'       => $child_id,
					'target'       => array_values( array_diff( $children, array( $child_id ) ) ),
					'confidence'   => 0.9,
					'evidence'     => 'dom_sibling',
				);

				// Two flex children with the same align-items share a baseline, and
				// two grid children in the same row share an edge. Either way, they
				// are aligned in a way an Elementor row preserves.
				$siblings[] = array(
					'relationship' => 'aligned_with',
					'source'       => $child_id,
					'target'       => $this->alignment_peers( $by_id, $children, $child_id ),
					'confidence'   => $this->alignment_confidence( $node ),
					'evidence'     => 'layout_alignment',
				);
			}
			$relations = array_merge( $relations, $siblings );
		}

		// Overlap, from the three ways a page produces one.
		foreach ( $nodes as $node ) {
			$id  = (string) $node['id'];
			$lay = isset( $node['layout'] ) && is_array( $node['layout'] ) ? $node['layout'] : array();

			$reasons = array();

			$bottom = $lay['placement']['margin']['bottom'] ?? null;
			if ( null !== $bottom && $bottom < 0 ) {
				$reasons[] = 'negative_margin_bottom';
			}
			$top = $lay['placement']['margin']['top'] ?? null;
			if ( null !== $top && $top < 0 ) {
				$reasons[] = 'negative_margin_top';
			}
			if ( ! empty( $lay['placement']['out_of_flow'] ) ) {
				$reasons[] = 'positioned_' . (string) $lay['placement']['position'];
			}
			$transform = $lay['placement']['transform'] ?? null;
			if ( is_string( $transform ) && '' !== $transform && 'none' !== $transform ) {
				$reasons[] = 'transform';
			}

			if ( ! empty( $reasons ) ) {
				$relations[] = array(
					'relationship' => 'overlaps',
					'source'       => $id,
					'target'       => $this->overlap_targets( $by_id, $node ),
					'confidence'   => $this->overlap_confidence( $reasons ),
					'evidence'     => $reasons,
					'limitation'   => 'An overlap is reproduced with a negative margin and a z-index where Elementor allows it. Where it does not, the replica places the element in flow and the visual relationship is lost.',
				);
			}

			// An absolute element is anchored to its nearest positioned ancestor.
			if ( ! empty( $lay['placement']['out_of_flow'] ) && ! empty( $node['parent'] ) ) {
				$parent_id = (string) $node['parent'];
				$parent    = isset( $by_id[ $parent_id ] ) ? $by_id[ $parent_id ] : null;
				$anchor    = $this->positioned_ancestor( $by_id, (string) $node['parent'] );

				$relations[] = array(
					'relationship' => 'anchored_to',
					'source'       => $id,
					'target'       => null !== $anchor ? $anchor : $parent_id,
					'confidence'   => null !== $anchor ? 0.95 : 0.6,
					'evidence'     => null !== $anchor
						? array( 'positioned_ancestor' )
						: array( 'dom_ancestor', 'no_positioned_ancestor' ),
					'limitation'   => null === $anchor
						? 'The positioned element has no positioned ancestor, so its containing block is the page. The replica anchors it to its structural parent instead, which will not move with a scroll.'
						: null,
				);
			}

			// A centered box is centered in its parent.
			if ( ! empty( $lay['box']['centered'] ) && ! empty( $node['parent'] ) ) {
				$relations[] = array(
					'relationship' => 'centered_in',
					'source'       => $id,
					'target'       => (string) $node['parent'],
					'confidence'   => 0.95,
					'evidence'     => array( 'auto_margins' ),
				);
			}
		}

		// Bounded, so a large page cannot produce an unbounded relationship list.
		if ( count( $relations ) > self::MAX_RELATIONS_PER_NODE * max( 1, count( $nodes ) ) ) {
			$relations = array_slice( $relations, 0, self::MAX_RELATIONS_PER_NODE * max( 1, count( $nodes ) ) );
		}

		return $relations;
	}

	/**
	 * Return the nodes that share a layout edge with one node.
	 *
	 * @param array<string, array<string, mixed>> $by_id    Nodes by id.
	 * @param array<int, string>                  $children Sibling ids.
	 * @param string                              $child_id The node.
	 * @return array<int, string>
	 */
	private function alignment_peers( array $by_id, array $children, $child_id ) {
		$peers = array();
		foreach ( $children as $candidate ) {
			if ( $candidate !== $child_id && isset( $by_id[ $candidate ] ) ) {
				$peers[] = $candidate;
			}
		}
		return $peers;
	}

	/**
	 * Return how confident the alignment claim is.
	 *
	 * Alignment within a grid or flex container is a structural fact. Within a block
	 * container it is a coincidence of widths, which is a weaker claim.
	 *
	 * @param array<string, mixed> $node The parent node.
	 * @return float
	 */
	private function alignment_confidence( array $node ) {
		$mode = $node['layout']['mode'] ?? 'unknown';
		if ( 'grid' === $mode ) {
			return 0.9;
		}
		if ( 'flex' === $mode ) {
			return 0.85;
		}
		return 0.45;
	}

	/**
	 * Return how confident the overlap claim is.
	 *
	 * @param array<int, string> $reasons Why an overlap was inferred.
	 * @return float
	 */
	private function overlap_confidence( array $reasons ) {
		if ( in_array( 'negative_margin_bottom', $reasons, true ) && in_array( 'negative_margin_top', $reasons, true ) ) {
			return 0.6;
		}
		if ( count( $reasons ) > 1 ) {
			return 0.8;
		}
		return 0.5;
	}

	/**
	 * Return the nodes a given node plausibly overlaps.
	 *
	 * @param array<string, array<string, mixed>> $by_id Nodes by id.
	 * @param array<string, mixed>                $node  The node.
	 * @return array<int, string>
	 */
	private function overlap_targets( array $by_id, array $node ) {
		$parent_id = isset( $node['parent'] ) ? (string) $node['parent'] : '';
		if ( '' === $parent_id ) {
			return array();
		}

		$parent = isset( $by_id[ $parent_id ] ) ? $by_id[ $parent_id ] : null;
		if ( null === $parent || empty( $parent['children'] ) || ! is_array( $parent['children'] ) ) {
			return array( $parent_id );
		}

		$out = array();
		foreach ( $parent['children'] as $sibling_id ) {
			$sibling_id = (string) $sibling_id;
			if ( $sibling_id !== (string) $node['id'] ) {
				$out[] = $sibling_id;
			}
		}

		return empty( $out ) ? array( $parent_id ) : $out;
	}

	/**
	 * Return the nearest positioned ancestor, which is the containing block.
	 *
	 * @param array<string, array<string, mixed>> $by_id    Nodes by id.
	 * @param string                              $start_id Where to start looking.
	 * @return string|null
	 */
	private function positioned_ancestor( array $by_id, $start_id ) {
		$current = $by_id[ $start_id ] ?? null;
		$guard   = 0;

		while ( null !== $current && $guard < Analysis_Limits::MAX_DOM_DEPTH ) {
			$guard++;
			$position = $current['layout']['placement']['position'] ?? null;
			if ( in_array( $position, array( 'relative', 'absolute', 'fixed', 'sticky' ), true ) ) {
				return (string) $current['id'];
			}
			$parent_id = isset( $current['parent'] ) ? (string) $current['parent'] : '';
			if ( '' === $parent_id || ! isset( $by_id[ $parent_id ] ) ) {
				return null;
			}
			$current = $by_id[ $parent_id ];
		}

		return null;
	}

	/**
	 * Read a child item's own grid or flex participation.
	 *
	 * This is what tells the generator that a child spans two columns, or grows to
	 * fill the row, which is often the difference between a faithful and a merely
	 * plausible reconstruction.
	 *
	 * @param array<string, mixed> $declarations Child declarations.
	 * @return array<string, mixed>
	 */
	public function participation( array $declarations ) {
		$span = array();
		foreach ( array( 'column' => 'grid-column', 'row' => 'grid-row' ) as $axis => $prefix ) {
			// The longhand is grid-column-start, not grid-column-column-start, so the
			// prefix already carries the axis. Building it by appending the axis again
			// produced a property name that never exists, which is why a `1 / 3`
			// placement was read as no placement at all.
			$explicit = $this->raw( $declarations, $prefix . '-span' );
			$start    = $this->raw( $declarations, $prefix . '-start' );
			$end      = $this->raw( $declarations, $prefix . '-end' );

			$value = null;
			if ( is_numeric( $explicit ) ) {
				$value = (int) $explicit;
			} elseif ( is_numeric( $start ) && is_numeric( $end ) ) {
				// The span is the distance between the two lines, not the distance plus
				// one. `grid-column: 1 / 3` starts on line 1 and ends on line 3, which
				// covers columns 1 and 2. Adding one made every spanning child a
				// column too wide.
				$value = ( (int) $end ) - ( (int) $start );
			} elseif ( null !== $explicit && preg_match( '/^span\s+(\d+)$/i', $explicit, $matches ) ) {
				$value = (int) $matches[1];
			} elseif ( null !== $start && preg_match( '/^span\s+(\d+)$/i', $start, $matches ) ) {
				$value = (int) $matches[1];
			}

			$span[ $axis ] = $value;
		}

		// The `span n` keyword can also appear as a bare grid-column value.
		foreach ( array( 'column' => 'grid-column', 'row' => 'grid-row' ) as $axis => $prefix ) {
			if ( null !== $span[ $axis ] ) {
				continue;
			}
			$shorthand = $this->raw( $declarations, $prefix );
			if ( null === $shorthand ) {
				continue;
			}
			if ( preg_match( '/^span\s+(\d+)$/i', trim( $shorthand ), $matches ) ) {
				$span[ $axis ] = (int) $matches[1];
				continue;
			}
			// The range form is matched directly. Splitting on whitespace would put
			// the slash in its own token, so `1 / 3` would not read as two numbers.
			if ( preg_match( '/^\s*([+-]?\d+)\s*\/\s*([+-]?\d+)\s*$/', $shorthand, $matches ) ) {
				$span[ $axis ] = ( (int) $matches[2] ) - ( (int) $matches[1] );
				continue;
			}
			// Two bare numbers separated by whitespace are the same construct written
			// without the slash, which some minifiers produce.
			$parts = Css_Value_Parser::split( $shorthand, ' ' );
			if ( 2 === count( $parts ) && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
				$span[ $axis ] = ( (int) $parts[1] ) - ( (int) $parts[0] );
			}
		}

		$grow   = $this->number( $declarations, 'flex-grow' );
		$shrink = $this->number( $declarations, 'flex-shrink' );
		$basis  = $this->length( $declarations, 'flex-basis' );

		return array(
			'span'       => $span,
			'grow'       => $grow,
			'shrink'     => $shrink,
			'basis'      => $basis,
			'align_self' => $this->keyword( $declarations, 'align-self' ),
			'order'      => $this->order( $declarations ),
			// Elementor expresses a span as a child width, so a two-column span in a
			// four-column grid is a half-width child. That is an exact translation for
			// a fractional grid and an approximation otherwise.
			'elementor'  => $this->elementor_participation( $span, $grow, $basis ),
		);
	}
	/**
	 * Map a child's participation onto an Elementor width.
	 *
	 * @param array<string, int|null> $span  Grid spans.
	 * @param float|null              $grow  Flex grow.
	 * @param float|null              $basis Flex basis.
	 * @return array<string, mixed>
	 */
	private function elementor_participation( array $span, $grow, $basis ) {
		if ( null !== $span['column'] && $span['column'] > 1 ) {
			return array(
				'width_mode' => 'span',
				'span'       => $span['column'],
				'limitation' => 'A grid child spanning ' . $span['column'] . ' columns becomes an Elementor child with a proportional width. The replica will not reflow the way the grid does when the column count changes.',
			);
		}

		if ( null !== $grow && $grow > 0.0 ) {
			return array(
				'width_mode' => 'grow',
				'grow'       => $grow,
				'limitation' => null !== $basis
					? 'The child grows from a flex basis. Elementor children take a fixed width, so the remaining space is distributed at generation time rather than at layout time.'
					: null,
			);
		}

		return array( 'width_mode' => 'auto', 'span' => null, 'grow' => $grow, 'limitation' => null );
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
		// A value with a comma or a parenthesis is a list, not a keyword.
		if ( false !== strpos( $raw, ',' ) || false !== strpos( $raw, '(' ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * Return a numeric declaration.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $property     Property name.
	 * @return float|null
	 */
	private function number( array $declarations, $property ) {
		$raw = $this->raw( $declarations, $property );
		return is_numeric( $raw ) ? (float) $raw : null;
	}

	/**
	 * Return a length in pixels, or null when it is not convertible.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $property     Property name.
	 * @return float|null
	 */
	private function length( array $declarations, $property ) {
		$raw = $this->raw( $declarations, $property );
		if ( null === $raw || 'auto' === strtolower( $raw ) ) {
			return null;
		}
		// A percentage is not a pixel length. Returning it would put a 50% into a
		// field that means pixels everywhere else in the representation.
		if ( false !== strpos( $raw, '%' ) ) {
			return null;
		}
		$parsed = Css_Value_Parser::length( $raw, $this->font_size, $this->viewport );
		return null !== $parsed && isset( $parsed['pixels'] ) && null !== $parsed['pixels']
			? (float) $parsed['pixels']
			: null;
	}

	/**
	 * Read one length value.
	 *
	 * @param string $value Raw value.
	 * @return float|null
	 */
	private function read_length( $value ) {
		$parsed = Css_Value_Parser::length( $value, $this->font_size, $this->viewport );
		return null !== $parsed && isset( $parsed['pixels'] ) && null !== $parsed['pixels']
			? (float) $parsed['pixels']
			: null;
	}

	/**
	 * Return a width as a fraction of its containing block, or null.
	 *
	 * A percentage is not a pixel length, but it is the most important width
	 * declaration on a real page, so it is read as a ratio rather than discarded.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $property     Property name.
	 * @return float|null
	 */
	private function ratio( array $declarations, $property ) {
		$raw = $this->raw( $declarations, $property );
		if ( null === $raw ) {
			return null;
		}
		return Css_Value_Parser::percentage( $raw );
	}

	/**
	 * Return a viewport-relative width as a fraction, or null.
	 *
	 * A viewport unit is relative to the viewport rather than to the parent, so it
	 * is a different relationship from a percentage and is reported separately
	 * instead of being folded into the same field.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $property     Property name.
	 * @return float|null
	 */
	private function viewport_ratio( array $declarations, $property ) {
		$raw = $this->raw( $declarations, $property );
		if ( null === $raw ) {
			return null;
		}
		$parsed = Css_Value_Parser::length( $raw, $this->font_size, $this->viewport );
		if ( null === $parsed || 'vw' !== (string) $parsed['unit'] || null === $parsed['pixels'] ) {
			return null;
		}
		return round( (float) $parsed['pixels'] / $this->viewport, 4 );
	}

	/**
	 * Return whether a declaration is the `auto` keyword.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $property     Property name.
	 * @return bool
	 */
	private function is_auto( array $declarations, $property ) {
		$raw = $this->raw( $declarations, $property );
		return null !== $raw && 'auto' === strtolower( $raw );
	}
}
