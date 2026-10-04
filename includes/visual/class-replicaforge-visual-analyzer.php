<?php
/**
 * Phase 13: DOM to visual mapping, geometry, and spatial relationships.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Joins what the DOM says to what the render says, and records where they disagree.
 *
 * ### The honest core of this class
 *
 * There are two sources of truth about a page's geometry and they are not equally
 * reliable:
 *
 * - **Computed CSS** is precise, deterministic, and says what the browser was
 *   *instructed* to do. It is available on every host, with no renderer.
 * - **Rendered geometry** is what actually happened, after layout, after JavaScript,
 *   after a font loaded late, after a media query fired. It is available only with a
 *   renderer.
 *
 * The instinct on finding two numbers is to prefer the rendered one, because it is
 * "real". That is right more often than not — and it is *wrong* in the specific case
 * that matters most for reconstruction: the replica will be rendered by Elementor on
 * the *user's* host with a *different* font stack and a *different* width. Following
 * rendered geometry pixel-for-pixel into a document that will be re-laid-out bakes in
 * measurements that will not survive the round trip.
 *
 * So this class does not pick a winner. It stores **both**, labels them, and records a
 * `measurement_conflict` when they disagree by more than the tolerance. §14 asks for
 * exactly that, and it is the only answer that is right in both directions.
 *
 * ### What is inferred, and what is measured
 *
 * A bounding box is *reported* by the renderer or *derived* from CSS. The two are
 * never conflated: a derived box carries `derived: true` and a confidence below 1.0,
 * because a derived box is a claim about a layout that has not happened. Every
 * consumer can therefore tell what it is looking at, and a confidence score is
 * derived from the kind of evidence rather than invented per case.
 */
final class Visual_Analyzer {

	/**
	 * Alignment tolerance, in CSS pixels.
	 *
	 * Sub-pixel positions are real — a 0.4px difference is two different rounding
	 * outcomes of the same layout — and treating them as misalignment produces a
	 * relationship finding on every pair of elements on the page. Phase 5's
	 * `length` tolerance of 4px is used for the same reason and is reused rather than
	 * reinvented.
	 *
	 * @var float
	 */
	const ALIGN_TOLERANCE = 2.0;

	/**
	 * Gap tolerance, in CSS pixels, for `adjacent` / `stacked`.
	 *
	 * @var float
	 */
	const GAP_TOLERANCE = 4.0;

	/**
	 * Overlap tolerance, as a fraction of the smaller box.
	 *
	 * Two boxes sharing one edge pixel "overlap" under a naive intersection test, and
	 * every adjacent pair of sections would be reported as overlapping. A tenth of the
	 * smaller box is the smallest overlap worth calling an overlap.
	 *
	 * @var float
	 */
	const OVERLAP_FRACTION = 0.1;

	/**
	 * Build geometry and relationships for one page at one viewport.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @param array<string, mixed> $render         Render evidence, possibly empty.
	 * @param array<string, mixed> $viewport       Viewport profile.
	 * @return array<string, mixed>
	 */
	public function analyze( array $representation, array $render = array(), array $viewport = array() ) {
		$profile = array_merge( Visual_Limits::viewports(), array() );
		$width   = (int) ( $viewport['width'] ?? ( $profile['desktop']['width'] ?? 1440 ) );
		$height  = (int) ( $viewport['height'] ?? ( $profile['desktop']['height'] ?? 900 ) );
		$dpr     = (float) ( $viewport['device_pixel_ratio'] ?? 1.0 );

		$boxes        = $this->boxes_from_render( $render );
		$derived      = $this->boxes_from_dom( $representation, $width );
		$conflicts    = array();
		$merged       = $this->merge_boxes( $derived, $boxes, $conflicts );
		$relationships = $this->relationships( $merged, $representation );
		$containers   = $this->containers( $merged, $width );
		$grids        = $this->grids( $merged, $representation );
		$overlaps     = $this->overlaps( $merged, $representation );
		$sections     = $this->section_boundaries( $merged, $representation, $width );

		return array(
			'schema_version'    => Visual_Limits::SCHEMA_VERSION,
			'viewport'          => array(
				'name'                => (string) ( $viewport['name'] ?? 'desktop' ),
				'width'               => $width,
				'height'              => $height,
				'device_pixel_ratio'  => $dpr,
				'rendered'            => ! empty( $render['succeeded'] ),
			),
			'geometry'          => array(
				'boxes'            => $merged,
				'rendered_boxes'   => count( $boxes ),
				'derived_boxes'    => count( $derived ),
				'containers'       => $containers,
				'grids'            => $grids,
			),
			'visual_relationships' => $relationships,
			'overlaps'          => $overlaps,
			'sections'          => $sections,
			'measurement_conflicts' => array_slice( $conflicts, 0, Visual_Limits::MAX_MEASUREMENT_CONFLICTS ),
			'rendered'          => ! empty( $render['succeeded'] ),
			'limitations'       => $this->limitations( $render, $boxes, $derived ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Bounding boxes
	 * ------------------------------------------------------------------ */

	/**
	 * Return the bounding boxes the renderer reported.
	 *
	 * @param array<string, mixed> $render Render evidence.
	 * @return array<string, array<string, mixed>>
	 */
	public function boxes_from_render( array $render ) {
		$out = array();
		$raw = isset( $render['boxes'] ) && is_array( $render['boxes'] ) ? $render['boxes'] : array();
		$dpr = (float) ( $render['viewport']['rendered_dpr'] ?? 1.0 );
		if ( $dpr > 0 && abs( $dpr - 1.0 ) > 0.001 ) {
			// Normalise to CSS pixels so a DPR-2 capture compares against a DPR-1 one.
			// §7 asks for this; without it every comparison reports a size difference
			// that is only a scale factor.
			$dpr = 1.0;
		}

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) || ! isset( $entry['bbox'] ) || ! is_array( $entry['bbox'] ) ) {
				continue;
			}
			$box = $this->clean_box( $entry['bbox'] );
			if ( null === $box ) {
				continue;
			}
			$out[ (string) $entry['id'] ] = array(
				'id'       => (string) $entry['id'],
				'type'     => (string) ( $entry['type'] ?? 'unknown' ),
				'box'      => $box,
				'evidence' => array( 'source' => 'render', 'dpr_normalised' => (float) ( $render['viewport']['rendered_dpr'] ?? 1.0 ) ),
				'derived'  => false,
				'confidence' => 0.98,
			);
			if ( count( $out ) >= Visual_Limits::MAX_BOXED_ELEMENTS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Derive bounding boxes from the representation when no render is available.
	 *
	 * A derived box is a *claim*, and it says so. It is produced by summing the
	 * declared sizes in document order — which is what a single-column stack actually
	 * does — and is therefore only meaningful for stacked content. For a grid or a
	 * flex row it is wrong, and rather than guess a layout it reports the box it can
	 * justify and marks it derived.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @param int                  $width          Viewport width.
	 * @return array<string, array<string, mixed>>
	 */
	public function boxes_from_dom( array $representation, $width ) {
		$out     = array();
		$offset  = 0.0;
		$content = (int) round( max( 0, $width ) * 0.8 );

		$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
		foreach ( $sections as $index => $section ) {
			if ( ! is_array( $section ) || count( $out ) >= Visual_Limits::MAX_BOXED_ELEMENTS ) {
				continue;
			}
			$id   = (string) ( $section['id'] ?? ( 'section_' . ( $index + 1 ) ) );
			$top  = $offset;
			$high = $this->declared_height( $section );

			$out[ $id ] = array(
				'id'       => $id,
				'type'     => (string) ( $section['type'] ?? 'section' ),
				'box'      => array(
					'x'      => (int) round( ( $width - $content ) / 2 ),
					'y'      => (int) round( $top ),
					'width'  => $content,
					'height' => $high,
				),
				'evidence' => array( 'source' => 'dom_declared', 'basis' => 'declared heights summed in document order' ),
				'derived'  => true,
				// 0.55, not 0.9. A summed-height estimate is a weak claim and giving
				// it a strong confidence would let a correction act on it.
				'confidence' => 0.55,
			);

			$offset += $high;
		}

		return $out;
	}

	/**
	 * Merge derived and rendered boxes, recording disagreement.
	 *
	 * @param array<string, array<string, mixed>> $derived   Derived boxes.
	 * @param array<string, array<string, mixed>> $rendered  Rendered boxes.
	 * @param array<int, array<string, mixed>>   $conflicts Conflicts, by reference.
	 * @return array<string, array<string, mixed>>
	 */
	private function merge_boxes( array $derived, array $rendered, array &$conflicts ) {
		// The loop below mutates `$merged` directly. An earlier version started with
		// `$merged = $rendered;` and then modified `$rendered` — and PHP arrays are
		// value types, so the merge was written into a copy that was thrown away. The
		// only boxes that survived were the derived ones with no render, and the
		// `dom_estimate` and the merged confidence never reached the output at all. It
		// type-checked, returned a plausible array, and silently did nothing.
		$merged = array();

		foreach ( $derived as $id => $entry ) {
			if ( isset( $rendered[ $id ] ) ) {
				// Both exist. The rendered one wins for the *value* — it is what
				// happened — and the derived one is kept alongside it as evidence, so
				// §51's requirement is met: an inference carries both numbers.
				$rendered[ $id ]['dom_estimate']        = $entry['box'];
				$rendered[ $id ]['confidence']          = 0.95;
				$rendered[ $id ]['evidence']['dom_estimate_source'] = 'dom_declared';
				$delta = $this->box_delta( $entry['box'], $rendered[ $id ]['box'] );
				if ( $delta > self::ALIGN_TOLERANCE ) {
					$conflicts[] = array(
						'code'        => 'measurement_conflict',
						'id'          => (string) $id,
						'property'    => 'geometry',
						'computed'    => $entry['box'],
						'rendered'    => $rendered[ $id ]['box'],
						'delta'       => round( $delta, 2 ),
						'status'      => 'conflict',
						// Neither is discarded. A consumer that needs CSS-reproducible
						// numbers uses `computed`; one that needs what the visitor saw
						// uses `rendered`.
						'resolution'  => '',
						'note'        => __( 'The declared layout and the rendered layout disagree. Both are kept: CSS is what a replica can reproduce, and the render is what a visitor saw.', 'replicaforge' ),
					);
				}
				$merged[ $id ] = $rendered[ $id ];
				continue;
			}
			$merged[ $id ] = $entry;
		}

		// Rendered boxes with no derived counterpart — a floating button, a chat
		// widget, anything the representation has no node for — must survive too, or
		// the visual layer would be blind to exactly the elements that have no
		// structural counterpart.
		foreach ( $rendered as $id => $entry ) {
			if ( ! isset( $merged[ $id ] ) ) {
				$merged[ $id ] = $entry;
			}
		}

		return $merged;
	}

	/* ---------------------------------------------------------------------
	 * Relationships
	 * ------------------------------------------------------------------ */

	/**
	 * Detect the spatial relationships §13 lists.
	 *
	 * @param array<string, array<string, mixed>> $boxes          Boxes.
	 * @param array<string, mixed>                $representation Representation.
	 * @return array<int, array<string, mixed>>
	 */
	private function relationships( array $boxes, array $representation ) {
		$items = array();
		foreach ( $boxes as $id => $entry ) {
			$items[] = array( 'id' => (string) $id, 'box' => $entry['box'], 'type' => (string) $entry['type'] );
		}

		$out   = array();
		$count = count( $items );
		// Pairwise. Bounded by `MAX_BOXED_ELEMENTS` and by the output cap, and the
		// cap is applied at the end rather than by breaking early so the *strongest*
		// relationships survive — overlap and containment are more informative than
		// two more `aligned_left` pairs.
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( count( $out ) >= Visual_Limits::MAX_RELATIONSHIPS ) {
					break 2;
				}
				$left  = $items[ $i ];
				$right = $items[ $j ];

				$flags = array(
					'same_left'   => abs( $left['box']['x'] - $right['box']['x'] ) <= self::ALIGN_TOLERANCE,
					'same_right'  => abs( ( $left['box']['x'] + $left['box']['width'] ) - ( $right['box']['x'] + $right['box']['width'] ) ) <= self::ALIGN_TOLERANCE,
					'same_top'    => abs( $left['box']['y'] - $right['box']['y'] ) <= self::ALIGN_TOLERANCE,
					'same_width'  => abs( $left['box']['width'] - $right['box']['width'] ) <= self::ALIGN_TOLERANCE,
					'same_height' => abs( $left['box']['height'] - $right['box']['height'] ) <= self::ALIGN_TOLERANCE,
					'overlaps'    => $this->overlaps_by( $left['box'], $right['box'] ),
					'contained'   => $this->contains( $left['box'], $right['box'] ) || $this->contains( $right['box'], $left['box'] ),
					'adjacent'    => $this->adjacent( $left['box'], $right['box'] ),
					'tolerance'   => true,
				);

				foreach ( Visual_Limits::relationships_for( $flags ) as $relationship ) {
					$out[] = array(
						'relationship' => (string) $relationship,
						'source'       => $left['id'],
						'target'       => $right['id'],
						// Depth is *not* invented from a z-index. A z-index is declared
						// intent within a stacking context and says nothing about
						// whether two elements actually overlap, so `depth` is only set
						// where the geometry supports it — an overlap's depth is 1, a
						// containment's is 2, and an alignment's is 0.
						'depth'        => in_array( $relationship, Visual_Limits::LAYER_RELATIONSHIPS, true ) ? ( 'contained' === $relationship ? 2 : 1 ) : 0,
						'evidence'     => array( 'computed' => true, 'basis' => 'box intersection' ),
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Detect overlaps §20 requires, with the direction that matters.
	 *
	 * @param array<string, mixed>                 $boxes          Boxes.
	 * @param array<string, mixed>                 $representation Representation.
	 * @return array<int, array<string, mixed>>
	 */
	public function overlaps( array $boxes, array $representation ) {
		$out   = array();
		$items = array();
		foreach ( $boxes as $id => $entry ) {
			$items[] = array( 'id' => (string) $id, 'box' => $entry['box'] );
		}

		for ( $i = 0; $i < count( $items ); $i++ ) {
			for ( $j = $i + 1; $j < count( $items ); $j++ ) {
				$area = $this->intersection_area( $items[ $i ]['box'], $items[ $j ]['box'] );
				if ( $area < 1.0 ) {
					continue;
				}
				$smaller = min( $items[ $i ]['box']['width'] * $items[ $i ]['box']['height'], $items[ $j ]['box']['width'] * $items[ $j ]['box']['height'] );
				$share   = $smaller > 0 ? ( $area / $smaller ) : 0.0;
				if ( $share < self::OVERLAP_FRACTION ) {
					continue;
				}
				$out[] = array(
					// `overlapping`, not `overlaps`. The standalone overlap list and the
					// relationship list describe the same fact, and the first draft used
					// both spellings — `overlaps` here and `overlapping` in
					// `Visual_Limits::RELATIONSHIPS`. A consumer filtering on one silently
					// missed the other, which is the whole class of bug the closed
					// relationship vocabulary exists to prevent.
					'relationship' => 'overlapping',
					'source'       => $items[ $i ]['id'],
					'target'       => $items[ $j ]['id'],
					'depth'        => 1,
					'area'         => (int) round( $area ),
					'share'        => round( $share, 3 ),
					'evidence'     => array( 'computed' => true, 'basis' => 'box intersection area' ),
				);
			}
		}

		return array_slice( $out, 0, 200 );
	}

	/* ---------------------------------------------------------------------
	 * Containers, grids
	 * ------------------------------------------------------------------ */

	/**
	 * Extract container geometry §17 asks for.
	 *
	 * @param array<string, array<string, mixed>> $boxes Boxes.
	 * @param int                                $width Viewport width.
	 * @return array<string, mixed>
	 */
	public function containers( array $boxes, $width ) {
		$width = max( 1, (int) $width );
		$found = array();

		foreach ( $boxes as $id => $entry ) {
			$box = $entry['box'];
			if ( $box['width'] < 40 ) {
				continue;
			}
			$left  = max( 0, (int) $box['x'] );
			$right = min( $width, $left + (int) $box['width'] );

			$left_margin  = ( $width > 0 ) ? ( $left / $width ) : 0.0;
			$right_margin = ( $width > 0 ) ? ( ( $width - $right ) / $width ) : 0.0;
			$centred      = abs( $left_margin - $right_margin ) < 0.02;

			$pattern = 'fluid';
			if ( $left <= 1 && $right >= $width - 1 ) {
				$pattern = 'full_width_section';
			} elseif ( $centred ) {
				$pattern = 'max_width';
			}

			$found[ (string) $id ] = array(
				'id'          => (string) $id,
				'viewport_width' => $width,
				'width'       => (int) $box['width'],
				'left_margin' => round( $left_margin, 4 ),
				'right_margin'=> round( $right_margin, 4 ),
				'padding'     => 0,
				'alignment'   => $centred ? 'center' : 'left',
				'pattern'     => (string) $pattern,
				// `max_width` is only claimed when the width is a recognisable
				// round number. §17 says do not invent container values, and 1198 is not
				// a container a designer chose — it is a viewport minus two margins.
				'max_width'   => ( $centred && $this->is_round_number( (int) $box['width'] ) ) ? (int) $box['width'] : 0,
				'evidence'    => array( 'computed' => $entry['derived'] ? false : true, 'derived' => (bool) $entry['derived'] ),
			);
		}

		return $found;
	}

	/**
	 * Infer visual grids §18 asks for.
	 *
	 * @param array<string, array<string, mixed>> $boxes          Boxes.
	 * @param array<string, mixed>                $representation Representation.
	 * @return array<int, array<string, mixed>>
	 */
	public function grids( array $boxes, array $representation ) {
		$rows = array();
		$derived_used = 0;
		foreach ( $boxes as $id => $entry ) {
			$box = $entry['box'];
			if ( $box['width'] < 40 || $box['height'] < 30 ) {
				continue;
			}
			// A derived box is excluded, and this is the single most important filter in
			// the class. A derived box sums declared heights down the page, which is
			// exactly right for a stack and exactly wrong for a grid: it would place
			// four cards side by side in the same "row" and report a six-column grid
			// where there are four. A grid inferred from an estimate is not a weaker
			// grid, it is a fabricated one, so the estimate is dropped rather than
			// downweighted.
			if ( ! empty( $entry['derived'] ) ) {
				$derived_used++;
				continue;
			}
			// Bucketed by vertical position, so items in the same visual row group
			// together regardless of their order in the document.
			$row = (int) round( $box['y'] / max( 1.0, (float) max( 1, $box['height'] ) ) );
			$rows[ $row ][] = array( 'id' => (string) $id, 'box' => $box );
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( count( $row ) < Visual_Limits::MIN_GRID_SAMPLES ) {
				continue;
			}			usort(
				$row,
				static function ( $left, $right ) {
					return $left['box']['x'] <=> $right['box']['x'];
				}
			);

			$widths = array();
			$gaps   = array();
			for ( $i = 0; $i < count( $row ); $i++ ) {
				$widths[] = (int) $row[ $i ]['box']['width'];
				if ( $i > 0 ) {
					$gaps[] = (int) $row[ $i ]['box']['x'] - (int) $row[ $i - 1 ]['box']['x'] - (int) $row[ $i - 1 ]['box']['width'];
				}
			}

			$columns = count( $row );
			if ( $columns < Visual_Limits::MIN_COLUMNS || $columns > Visual_Limits::MAX_COLUMNS ) {
				continue;
			}

			$equal_widths = ( ( max( $widths ) - min( $widths ) ) <= self::ALIGN_TOLERANCE );
			$equal_gaps   = ( array() === $gaps ) || ( ( max( $gaps ) - min( $gaps ) ) <= self::GAP_TOLERANCE );

			$out[] = array(
				'columns'     => $columns,
				'gap'         => $equal_gaps && array() !== $gaps ? (int) round( array_sum( $gaps ) / count( $gaps ) ) : 0,
				'equal_gaps'  => $equal_gaps,
				'equal_widths'=> $equal_widths,
				'items'       => array_column( $row, 'id' ),
				// A grid inferred from geometry is a hypothesis about the CSS. It is
				// only *confirmed* when the representation also declares a grid or
				// flex container for the same items.
				'confirmed_by_css' => $this->css_confirms( $representation, $row ),
				'confidence'   => ( $equal_widths && $equal_gaps ) ? 0.8 : 0.55,
			);
		}

		// A grid inferred from estimates would be a fabrication, so the count of
		// estimates excluded from the inference is reported rather than left implicit.
		if ( $derived_used > 0 && array() === $out ) {
			$out[] = array(
				'columns'      => 0,
				'gap'          => 0,
				'items'        => array(),
				'limitation'   => sprintf(
					/* translators: %d: number of derived boxes excluded from grid detection. */
					__( 'No grid was inferred: the only candidate boxes were derived from declared styles (%1$d of them), and a summed-height estimate cannot tell a grid from a stack.', 'replicaforge' ),
					$derived_used
				),
			);
		}

		return array_slice( $out, 0, 40 );
	}

	/* ---------------------------------------------------------------------
	 * Section boundaries
	 * ------------------------------------------------------------------ */

	/**
	 * Refine section boundaries using rendered appearance §15 asks for.
	 *
	 * @param array<string, array<string, mixed>> $boxes          Boxes.
	 * @param array<string, mixed>                $representation Representation.
	 * @param int                                $width          Viewport width.
	 * @return array<int, array<string, mixed>>
	 */
	public function section_boundaries( array $boxes, array $representation, $width ) {
		$items = array();
		foreach ( $boxes as $id => $entry ) {
			$items[] = array( 'id' => (string) $id, 'box' => $entry['box'], 'type' => (string) $entry['type'] );
		}
		usort(
			$items,
			static function ( $left, $right ) {
				return $left['box']['y'] <=> $right['box']['y'];
			}
		);

		$out    = array();
		$cursor = 0.0;

		for ( $i = 0; $i < count( $items ); $i++ ) {
			$item = $items[ $i ];
			$top  = (float) $item['box']['y'];
			$gap  = $top - $cursor;

			$boundary = 'continuation';
			if ( $i === 0 ) {
				$boundary = 'document_start';
			} elseif ( $gap > ( 2 * self::GAP_TOLERANCE ) ) {
				// A vertical gap wider than twice the tolerance is a boundary a viewer
				// would describe as "then this", which is the definition §15 gives.
				$boundary = 'whitespace_transition';
			} elseif ( $this->background_differs( $items[ $i - 1 ], $item, $representation ) ) {
				$boundary = 'background_transition';
			}

			$out[] = array(
				'id'        => $item['id'],
				'type'      => $item['type'],
				'y'         => (int) round( $top ),
				'height'    => (int) $item['box']['height'],
				'gap_before'=> (int) round( $gap ),
				'boundary'  => $boundary,
				'edge_to_edge' => ( $item['box']['x'] <= 1 && ( $item['box']['x'] + $item['box']['width'] ) >= ( $width - 1 ) ),
				'evidence'  => array( 'rendered' => isset( $item['box']['source'] ) ? false : true ),
			);

			$cursor = max( $cursor, $top + (float) $item['box']['height'] );
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return the declared height of a section.
	 *
	 * @param array<string, mixed> $section Section.
	 * @return int
	 */
	private function declared_height( array $section ) {
		$candidates = array( $section['height'] ?? null, $section['min_height'] ?? null, $section['style']['height'] ?? null );
		foreach ( $candidates as $candidate ) {
			if ( is_numeric( $candidate ) && (float) $candidate > 0 ) {
				return (int) round( (float) $candidate );
			}
			if ( is_string( $candidate ) && 1 === preg_match( '/^(\d+(?:\.\d+)?)px$/', $candidate, $matches ) ) {
				return (int) round( (float) $matches[1] );
			}
		}
		// A section with children but no declared height gets a floor proportional to
		// how much is in it. Recorded as derived, so nothing treats it as measured.
		$children = isset( $section['components'] ) && is_array( $section['components'] ) ? count( $section['components'] ) : 0;
		return (int) max( 60, 40 + ( $children * 24 ) );
	}

	/**
	 * Clean and bound a raw bounding box.
	 *
	 * @param array<string, mixed> $raw Raw box.
	 * @return array<string, int>|null
	 */
	private function clean_box( array $raw ) {
		$out = array();
		foreach ( array( 'x', 'y', 'width', 'height' ) as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_numeric( $raw[ $key ] ) ) {
				return null;
			}
			$out[ $key ] = (int) round( (float) $raw[ $key ] );
		}
		if ( $out['width'] < 0 || $out['height'] < 0 ) {
			return null;
		}
		// Bounded. A renderer reporting a box 10^9 pixels wide is either broken or
		// hostile, and letting it through poisons every derived average.
		foreach ( array( 'x', 'y' ) as $key ) {
			$out[ $key ] = max( -10000, min( 200000, $out[ $key ] ) );
		}
		$out['width']  = min( 200000, $out['width'] );
		$out['height'] = min( 400000, $out['height'] );
		return $out;
	}

	/**
	 * Return the largest edge difference between two boxes.
	 *
	 * @param array<string, int> $left  Left.
	 * @param array<string, int> $right Right.
	 * @return float
	 */
	private function box_delta( array $left, array $right ) {
		$worst = 0.0;
		foreach ( array( 'x', 'y', 'width', 'height' ) as $key ) {
			$worst = max( $worst, abs( (float) $left[ $key ] - (float) $right[ $key ] ) );
		}
		return $worst;
	}

	/**
	 * Return whether two boxes overlap meaningfully.
	 *
	 * @param array<string, int> $left  Left.
	 * @param array<string, int> $right Right.
	 * @return bool
	 */
	private function overlaps_by( array $left, array $right ) {
		$area = $this->intersection_area( $left, $right );
		if ( $area < 1.0 ) {
			return false;
		}
		$smaller = min( $left['width'] * $left['height'], $right['width'] * $right['height'] );
		return ( $smaller > 0 ) && ( ( $area / $smaller ) >= self::OVERLAP_FRACTION );
	}

	/**
	 * Return the intersection area of two boxes.
	 *
	 * @param array<string, int> $left  Left.
	 * @param array<string, int> $right Right.
	 * @return float
	 */
	private function intersection_area( array $left, array $right ) {
		$x1 = max( (int) $left['x'], (int) $right['x'] );
		$y1 = max( (int) $left['y'], (int) $right['y'] );
		$x2 = min( (int) $left['x'] + (int) $left['width'], (int) $right['x'] + (int) $right['width'] );
		$y2 = min( (int) $left['y'] + (int) $left['height'], (int) $right['y'] + (int) $right['height'] );

		if ( $x2 <= $x1 || $y2 <= $y1 ) {
			return 0.0;
		}
		return (float) ( ( $x2 - $x1 ) * ( $y2 - $y1 ) );
	}

	/**
	 * Return whether one box contains another.
	 *
	 * @param array<string, int> $outer Outer.
	 * @param array<string, int> $inner Inner.
	 * @return bool
	 */
	private function contains( array $outer, array $inner ) {
		return $outer['x'] <= $inner['x'] + self::ALIGN_TOLERANCE
			&& $outer['y'] <= $inner['y'] + self::GAP_TOLERANCE
			&& ( $outer['x'] + $outer['width'] ) >= ( $inner['x'] + $inner['width'] - self::ALIGN_TOLERANCE )
			&& ( $outer['y'] + $outer['height'] ) >= ( $inner['y'] + $inner['height'] - self::GAP_TOLERANCE );
	}

	/**
	 * Return whether two boxes are vertically adjacent with no meaningful gap.
	 *
	 * @param array<string, int> $left  Left.
	 * @param array<string, int> $right Right.
	 * @return bool
	 */
	private function adjacent( array $left, array $right ) {
		$bottom = (int) $left['y'] + (int) $left['height'];
		$top    = (int) $right['y'];
		$gap    = abs( $top - $bottom );
		if ( $gap > self::GAP_TOLERANCE ) {
			return false;
		}
		// Only if they also share horizontal extent, or "adjacent" would be claimed
		// for a sidebar and a heading that happen to be level.
		$overlap_x = min( (int) $left['x'] + (int) $left['width'], (int) $right['x'] + (int) $right['width'] ) - max( (int) $left['x'], (int) $right['x'] );
		return $overlap_x > 0;
	}

	/**
	 * Return whether the representation declares a container for a row of items.
	 *
	 * @param array<string, mixed>                    $representation Representation.
	 * @param array<int, array<string, mixed>>        $row              Row items.
	 * @return bool
	 */
	private function css_confirms( array $representation, array $row ) {
		$ids = array_column( $row, 'id' );
		$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$layout = strtolower( (string) ( $section['layout'] ?? ( $section['display'] ?? '' ) ) );
			if ( in_array( $layout, array( 'grid', 'flex', 'inline-flex' ), true ) ) {
				$children = isset( $section['components'] ) && is_array( $section['components'] ) ? count( $section['components'] ) : 0;
				if ( $children >= count( $ids ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Return whether two adjacent sections differ in background.
	 *
	 * @param array<string, mixed> $left           Left item.
	 * @param array<string, mixed> $right          Right item.
	 * @param array<string, mixed> $representation Representation.
	 * @return bool
	 */
	private function background_differs( array $left, array $right, array $representation ) {
		$by_id = array();
		$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
		foreach ( $sections as $section ) {
			if ( is_array( $section ) && ! empty( $section['id'] ) ) {
				$by_id[ (string) $section['id'] ] = $section;
			}
		}
		$left_bg  = $this->background_of( $by_id[ $left['id'] ] ?? array() );
		$right_bg = $this->background_of( $by_id[ $right['id'] ] ?? array() );
		if ( '' === $left_bg || '' === $right_bg ) {
			return false;
		}
		return strtolower( $left_bg ) !== strtolower( $right_bg );
	}

	/**
	 * Return a section's background colour.
	 *
	 * @param array<string, mixed> $section Section.
	 * @return string
	 */
	private function background_of( array $section ) {
		foreach ( array( $section['background'] ?? null, $section['style']['background'] ?? null, $section['style']['background_color'] ?? null ) as $candidate ) {
			if ( is_string( $candidate ) && 1 === preg_match( '/^#[0-9a-fA-F]{3,8}$/', $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Return whether a width is a value a designer would have chosen.
	 *
	 * @param int $width Width.
	 * @return bool
	 */
	private function is_round_number( $width ) {
		$width = (int) $width;
		if ( $width < 200 ) {
			return false;
		}
		foreach ( array( 20, 40, 60, 80, 100, 120, 200 ) as $step ) {
			if ( 0 === $width % $step ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the limitations this analysis ran under.
	 *
	 * @param array<string, mixed>                     $render   Render evidence.
	 * @param array<string, array<string, mixed>>      $rendered Rendered boxes.
	 * @param array<string, array<string, mixed>>      $derived  Derived boxes.
	 * @return array<int, string>
	 */
	private function limitations( array $render, array $rendered, array $derived ) {
		$out = array();
		if ( array() === $rendered ) {
			$out[] = __( 'No rendered geometry was available, so every bounding box is derived from declared CSS and is a claim about a layout that has not happened.', 'replicaforge' );
		}
		$derived_count = count( $derived );
		$rendered_count = count( $rendered );
		if ( $derived_count > 0 && $rendered_count === 0 ) {
			$out[] = __( 'Overlapping and grid detection below is unreliable without a render: a derived box sums declared heights, which is correct for a stack and wrong for a grid.', 'replicaforge' );
		}
		return $out;
	}
}
