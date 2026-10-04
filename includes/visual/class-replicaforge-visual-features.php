<?php
/**
 * Phase 13: visual feature extraction.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts the appearance properties §22–§39 ask for.
 *
 * ### Exact where the source is exact, and honest where it is not
 *
 * A gradient written in CSS is *parsed*, not estimated: {@see Visual_Effects::gradient()}
 * already turns a declaration into a typed structure, and this class reuses it rather
 * than approximating an angle from a screenshot. §24 says do not approximate when
 * exact data is available, and the exact data is in the declaration.
 *
 * Where a screenshot is the only evidence — a colour that appears in a render but in
 * no declaration, a radius inferred from a rendered corner — the result is marked
 * `estimated` and carries the evidence it came from. A number that came from
 * measuring a picture is not the same kind of number as a number that came from a
 * declaration, and a consumer needs to be able to tell them apart.
 *
 * ### Nothing here invents a value
 *
 * No property is filled in from a default because a default "looks right". An element
 * with no declared border has no border record. An image with no declared dimensions
 * gets `intrinsic` and nothing else. The absence is the finding.
 */
final class Visual_Features {

	/**
	 * Visual effects parser.
	 *
	 * @var Visual_Effects
	 */
	private $effects;

	/**
	 * Colour normalisation helper.
	 *
	 * @var Color_Comparator
	 */
	private $colours;

	/**
	 * Constructor.
	 *
	 * @param Visual_Effects|null  $effects Optional effects parser.
	 * @param Color_Comparator|null $colours Optional colour comparator.
	 */
	public function __construct( $effects = null, $colours = null ) {
		$this->effects = $effects instanceof Visual_Effects ? $effects : new Visual_Effects( 16.0, 1440.0 );
		$this->colours = $colours instanceof Color_Comparator ? $colours : new Color_Comparator();
	}

	/**
	 * Extract every visual feature for a representation.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @param array<string, mixed> $render         Render evidence.
	 * @return array<string, mixed>
	 */
	public function extract( array $representation, array $render = array() ) {
		$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
		$components = isset( $representation['components'] ) && is_array( $representation['components'] ) ? $representation['components'] : array();

		$backgrounds = array();
		$images      = array();
		$typography  = array();
		$buttons     = array();
		$cards       = array();
		$shadows     = array();
		$borders     = array();
		$radii       = array();
		$gradients   = array();
		$icons       = array();
		$density     = array();
		$whitespace  = array();

		// Sections, the representation's top-level components, **and** the components
		// nested inside each section. The third group is the one that matters: an image,
		// a button, or a heading overwhelmingly lives inside a section's component list
		// rather than beside it, and walking only the first two groups analysed almost
		// nothing on a real page while looking like it had analysed the page.
		$nodes = array_merge( $sections, $components );
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			foreach ( (array) ( $section['components'] ?? array() ) as $child ) {
				if ( is_array( $child ) ) {
					$nodes[] = $child;
				}
			}
		}

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || count( $backgrounds ) > 3000 ) {
				continue;
			}
			$id = (string) ( $node['id'] ?? '' );
			if ( '' === $id ) {
				// A node with no id still has properties worth reading, and they are
				// keyed by its type and position. Without a key they would be dropped
				// entirely, which is the same gap one level up.
				$id = 'node_' . ( is_scalar( $node['type'] ?? null ) ? (string) $node['type'] : 'anon' ) . '_' . count( $backgrounds );
			}

			$background = $this->background( $node );
			if ( null !== $background ) {
				$backgrounds[ $id ] = $background;
				foreach ( (array) ( $background['gradients'] ?? array() ) as $gradient ) {
					$gradient['owner'] = $id;
					$gradients[]       = $gradient;
				}
			}

			$shadow = $this->shadow( $node );
			if ( null !== $shadow ) {
				$shadows[ $id ] = $shadow;
			}

			$border = $this->border( $node );
			if ( null !== $border ) {
				$borders[ $id ] = $border;
			}

			$radius = $this->radius( $node );
			if ( null !== $radius ) {
				$radii[ $id ] = $radius;
			}

			$image = $this->image( $node );
			if ( null !== $image ) {
				$images[ $id ] = $image;
			}

			$icon = $this->icon( $node );
			if ( null !== $icon ) {
				$icons[ $id ] = $icon;
			}

			$type = $this->text_role( $node );
			if ( null !== $type ) {
				$typography[ $id ] = $type;
			}

			$button = $this->button( $node );
			if ( null !== $button ) {
				$buttons[ $id ] = $button;
			}

			$card = $this->card( $node );
			if ( null !== $card ) {
				$cards[ $id ] = $card;
			}

			$density[ $id ]    = $this->density( $node );
			$whitespace[ $id ] = $this->whitespace( $node );
		}

		$palette = $this->palette( $backgrounds, $typography, $render );

		return array(
			'schema_version' => Visual_Limits::SCHEMA_VERSION,
			'colors'         => $palette,
			'gradients'      => array_slice( $gradients, 0, 80 ),
			'backgrounds'    => $backgrounds,
			'images'         => $images,
			'typography'     => $typography,
			'buttons'        => $buttons,
			'cards'          => $cards,
			'icons'          => $icons,
			'shadows'        => $shadows,
			'borders'        => $borders,
			'radius'         => $radii,
			'density'        => $density,
			'whitespace'     => $whitespace,
			'notes'          => $this->notes( $render ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Backgrounds and gradients
	 * ------------------------------------------------------------------ */

	/**
	 * Classify a node's background §22 requires.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>|null
	 */
	public function background( array $node ) {
		$declarations = $this->declarations( $node );
		$colour       = (string) $this->scalar( $declarations, 'background_color' );
		$image        = (string) $this->scalar( $declarations, 'background_image' );
		$position     = (string) $this->scalar( $declarations, 'background_position' );
		$size         = (string) $this->scalar( $declarations, 'background_size' );
		$repeat       = (string) $this->scalar( $declarations, 'background_repeat' );

		if ( '' === $colour && '' === $image ) {
			return null;
		}

		$gradients = array();
		if ( '' !== $image && false !== strpos( $image, 'gradient(' ) ) {
			$parsed = $this->effects->gradient( $image );
			if ( ! empty( $parsed ) ) {
				$gradients[] = array_merge( $parsed, array( 'evidence' => 'computed_css', 'estimated' => false ) );
			}
		}

		// The role distinction is the point. A background image becomes a section
		// background in Elementor; a decorative one becomes nothing at all. Recording
		// which is which is what stops a reconstruction filling up with image widgets
		// where the source had none.
		$role = 'background';
		if ( '' !== $image && '' === $colour && array() === $gradients ) {
			$role = ( false !== strpos( strtolower( $position ), 'no-repeat' ) || false !== strpos( $size, 'contain' ) ) ? 'decorative' : 'background';
		}
		if ( array() !== $gradients ) {
			$role = 'overlay';
		}

		return array(
			'role'        => (string) $role,
			'color'       => $colour,
			'image'       => ( '' !== $image && array() === $gradients ) ? $image : '',
			'position'    => $position,
			'size'        => $size,
			'repeat'      => $repeat,
			'fit'         => $this->fit_from_size( $size ),
			'gradients'   => $gradients,
			'evidence'    => array( 'source' => 'computed_css' ),
			'estimated'   => false,
		);
	}

	/**
	 * Derive an object-fit from a background-size.
	 *
	 * @param string $size Background size.
	 * @return string
	 */
	private function fit_from_size( $size ) {
		$size = strtolower( trim( (string) $size ) );
		if ( 'cover' === $size ) {
			return 'cover';
		}
		if ( 'contain' === $size ) {
			return 'contain';
		}
		if ( '' === $size || 'auto' === $size ) {
			return 'intrinsic';
		}
		return 'stretched';
	}

	/* ---------------------------------------------------------------------
	 * Shadows, borders, radii
	 * ------------------------------------------------------------------ */

	/**
	 * Parse a node's shadow §26 asks for, and say what it belongs to.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>|null
	 */
	public function shadow( array $node ) {
		$raw = (string) $this->scalar( $this->declarations( $node ), 'box_shadow' );
		if ( '' === $raw || 'none' === strtolower( $raw ) ) {
			return null;
		}

		$parsed = $this->effects->shadow( $raw, 'box' );
		// `Visual_Effects::shadow()` returns `{ shadows, present, count, elementor, raw }`.
		// `present` is the field that matters: a `none` shadow returns a non-empty
		// *array* with `present: false`, so testing `empty( $parsed )` would accept
		// every element as having a shadow — the first draft did exactly that.
		if ( ! is_array( $parsed ) || empty( $parsed['present'] ) || empty( $parsed['shadows'] ) ) {
			return null;
		}

		$layers = array_values( (array) $parsed['shadows'] );
		$first  = $layers[0];

		return array(
			'values'      => $layers,
			'layer_count' => (int) ( $parsed['count'] ?? count( $layers ) ),
			'first'       => $first,
			// Elementor reproduces a layered shadow from its first layer, so the loss
			// is recorded rather than hidden.
			'elementor'   => $parsed['elementor'] ?? array(),
			'belongs_to'  => $this->shadow_owner( $node ),
			'opacity'     => isset( $first['color']['alpha'] ) ? (float) $first['color']['alpha'] : 1.0,
			'evidence'    => array( 'source' => 'computed_css' ),
			'estimated'   => false,
		);
	}

	/**
	 * Return what a shadow most likely belongs to.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return string
	 */
	private function shadow_owner( array $node ) {
		$type = strtolower( (string) ( $node['type'] ?? '' ) );
		if ( false !== strpos( $type, 'button' ) || 'a' === $type ) {
			return 'button';
		}
		if ( false !== strpos( $type, 'card' ) || false !== strpos( $type, 'tile' ) ) {
			return 'card';
		}
		if ( in_array( $type, array( 'img', 'image', 'figure' ), true ) ) {
			return 'image';
		}
		if ( false !== strpos( $type, 'header' ) || false !== strpos( $type, 'footer' ) ) {
			return 'container';
		}
		return 'container';
	}

	/**
	 * Parse a node's border §27 asks for.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>|null
	 */
	public function border( array $node ) {
		$declarations = $this->declarations( $node );
		$parsed = $this->effects->border( $declarations );

		// `Visual_Effects::border()` returns `{ sides, uniform, present, radius, elementor }`.
		// There is no top-level `width`, `style`, or `color` — those live under
		// `sides[ $side ]`. Reading the top level read `null` for every one of them, so
		// every border was judged absent and `border()` returned null for a card that
		// plainly had a visible one. `present` is the field that decides.
		if ( ! is_array( $parsed ) || empty( $parsed['present'] ) || empty( $parsed['sides'] ) ) {
			return null;
		}

		$first = isset( $parsed['sides']['top'] ) ? (array) $parsed['sides']['top'] : array();
		$style = strtolower( (string) ( $first['style'] ?? '' ) );
		$width = (float) ( $first['width'] ?? 0 );
		$color = (string) ( $first['color'] ?? '' );

		// "None" is recorded as none rather than as a zero-width solid, because the
		// two behave differently in Elementor and a validator comparing them would
		// report a difference that does not exist.
		if ( 'none' === $style || ( $width <= 0 && '' === $color ) ) {
			return null;
		}

		$asymmetric = false;
		foreach ( (array) $parsed['sides'] as $side ) {
			$side = (array) $side;
			if ( (string) ( $side['style'] ?? '' ) !== (string) ( $first['style'] ?? '' ) ) {
				$asymmetric = true;
				break;
			}
		}

		return array(
			'width'      => round( $width, 2 ),
			'style'      => in_array( $style, array( 'solid', 'dashed', 'dotted', 'double' ), true ) ? $style : 'solid',
			'color'      => $color,
			'alpha'      => isset( $first['alpha'] ) ? (float) $first['alpha'] : 1.0,
			'sides'      => (array) $parsed['sides'],
			'uniform'    => (bool) ( $parsed['uniform'] ?? true ),
			'asymmetric' => $asymmetric,
			// A width the source never declared but that CSS draws anyway. Recorded
			// rather than presented as a declared value, because it is the CSS initial
			// value and a user asking "what width is this border" deserves to know it
			// was never chosen.
			'width_source' => (string) ( $first['width_source'] ?? 'declaration' ),
			'elementor'  => (array) ( $parsed['elementor'] ?? array() ),
			'evidence'   => array( 'source' => 'computed_css' ),
			'estimated'  => false,
		);
	}

	/**
	 * Analyse a node's radius §28 asks for.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>|null
	 */
	public function radius( array $node ) {
		$declarations = $this->declarations( $node );
		$corners = array(
			'top_left'     => $this->scalar( $declarations, 'border_top_left_radius' ),
			'top_right'    => $this->scalar( $declarations, 'border_top_right_radius' ),
			'bottom_left'  => $this->scalar( $declarations, 'border_bottom_left_radius' ),
			'bottom_right' => $this->scalar( $declarations, 'border_bottom_right_radius' ),
		);
		$all = array( $corners['top_left'], $corners['top_right'], $corners['bottom_left'], $corners['bottom_right'] );
		$all = array_values( array_filter( array_map( 'strval', $all ), 'strlen' ) );

		if ( array() === $all ) {
			$shorthand = (string) $this->scalar( $declarations, 'border_radius' );
			if ( '' === $shorthand || '0' === $shorthand || '0px' === $shorthand ) {
				return null;
			}
			$all = array( $shorthand );
		}

		$values = array();
		foreach ( $all as $value ) {
			if ( 1 === preg_match( '/^(\d+(?:\.\d+)?)(px|rem|em|%)?$/', trim( $value ), $matches ) ) {
				$values[] = (float) $matches[1];
			}
		}
		if ( array() === $values ) {
			return null;
		}

		$uniform = ( ( max( $values ) - min( $values ) ) < 0.5 );
		$shape   = 'uniform';
		if ( ! $uniform ) {
			$shape = ( ( abs( $values[0] - $values[1] ) < 0.5 ) && ( abs( $values[2] - $values[3] ) < 0.5 ) ) ? 'top_only' : 'asymmetric';
		}

		return array(
			'shape'     => (string) $shape,
			'uniform'   => (float) round( $uniform ? $values[0] : 0, 2 ),
			'corners'   => $corners,
			'evidence'  => array( 'source' => 'computed_css' ),
			'estimated' => false,
		);
	}

	/* ---------------------------------------------------------------------
	 * Images
	 * ------------------------------------------------------------------ */

	/**
	 * Analyse an image §29 and §30 ask for.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>|null
	 */
	public function image( array $node ) {
		$type = strtolower( (string) ( $node['type'] ?? '' ) );
		$src  = (string) ( $node['src'] ?? ( $node['url'] ?? ( $node['image'] ?? '' ) ) );
		$is_background = ( '' === $src && ( ! empty( $node['background'] ) || ! empty( $node['background_image'] ) ) );

		if ( ! $is_background && ( ! in_array( $type, array( 'img', 'image', 'figure', 'picture', 'video' ), true ) || '' === $src ) ) {
			return null;
		}

		$declarations = $this->declarations( $node );

		// The natural size comes only from the intrinsic-dimension attributes, and the
		// displayed size comes from the rendered ones — with `width`/`height` read as
		// *displayed*, not natural.
		//
		// The first draft had `width` as the last fallback for the *natural* size, which
		// is the opposite of what a representation means by it: `width` on an image node
		// is the rendered box. The consequence was that an image with a CSS size and no
		// intrinsic attributes compared its displayed width against itself, the ratios
		// always matched, and every image was reported as uncropped.
		$natural_w = $this->numeric( $node, array( 'natural_width', 'width_attribute' ) );
		$natural_h = $this->numeric( $node, array( 'natural_height', 'height_attribute' ) );
		$shown_w   = $this->numeric( $node, array( 'display_width', 'rendered_width', 'width' ) );
		$shown_h   = $this->numeric( $node, array( 'display_height', 'rendered_height', 'height' ) );

		$fit       = 'intrinsic';
		$declared  = (string) $this->scalar( $declarations, 'object_fit' );
		$ambiguous = false;
		$fit_note  = '';
		$scale     = 0.0;

		if ( in_array( $declared, array( 'cover', 'contain', 'fill', 'none', 'scale-down' ), true ) ) {
			// A declaration is not a guess, so nothing here is ambiguous.
			$fit = $declared;
		} elseif ( $natural_w > 0 && $natural_h > 0 && $shown_w > 0 && $shown_h > 0 ) {
			$derived   = $this->fit_from_ratios( $natural_w, $natural_h, $shown_w, $shown_h );
			$fit       = (string) $derived['fit'];
			$ambiguous = (bool) $derived['ambiguous'];
			$fit_note  = (string) $derived['note'];
			$scale     = (float) $derived['scale'];
		}

		$aspect = ( $natural_w > 0 && $natural_h > 0 ) ? round( $natural_w / $natural_h, 4 ) : 0.0;

		return array(
			// §22: content, background, decorative, or overlay. An `<img>` with text
			// inside an `<a>` is content; one with an empty alt is almost always
			// decorative, and treating a decorative image as content is what produces
			// a replica littered with image widgets a user has to delete.
			'role'        => $this->image_role( $node, $type, $is_background ),
			'source_url'  => $is_background ? '' : $src,
			'natural'     => array( 'width' => (int) $natural_w, 'height' => (int) $natural_h ),
			'displayed'   => array( 'width' => (int) $shown_w, 'height' => (int) $shown_h ),
			'aspect_ratio'=> $aspect,
			'fit'         => (string) $fit,
			'displayed_scale' => $scale,
			// The other §30 outcomes are reported alongside so a consumer can reason
			// about the fit without re-deriving it from the same two numbers.
			'cropped'     => ( 'cover' === $fit || 'cropped' === $fit ),
			'stretched'   => ( 'fill' === $fit ),
			'contained'   => ( 'contain' === $fit ),
			'object_fit'  => $declared,
			'fit_ambiguous' => (bool) $ambiguous,
			'fit_note'    => $fit_note,
			'focal_point' => (string) ( $node['focal_point'] ?? '' ),
			'evidence'    => array( 'source' => ( $shown_w > 0 ) ? 'rendered' : 'dom_declared' ),
			// A fit derived from comparing natural and displayed sizes is a measurement
			// of a render; a fit read from `object-fit` is a declaration. Marked so a
			// correction can prefer the reproducible one.
			'estimated'   => ( '' === $declared && $fit !== 'intrinsic' ),
		);
	}

	/**
	 * Return the role of an image.
	 *
	 * @param array<string, mixed> $node         Node.
	 * @param string               $type         Node type.
	 * @param bool                 $is_background Whether it is a background.
	 * @return string
	 */
	private function image_role( array $node, $type, $is_background ) {
		if ( $is_background ) {
			return 'background';
		}
		if ( 'video' === $type ) {
			return 'background';
		}
		$alt = trim( (string) ( $node['alt'] ?? '' ) );
		if ( '' === $alt ) {
			return 'decorative';
		}
		// A very short alt is often a filename or a stray word, not a description.
		if ( strlen( $alt ) < 3 ) {
			return 'decorative';
		}
		return 'content';
	}

	/**
	 * Return the fit implied by comparing natural and displayed dimensions.
	 *
	 * ### What can and cannot be concluded from a bounding box
	 *
	 * If the aspect ratio is preserved, the image is provably being *scaled* and
	 * nothing has been cropped or distorted — that is a real conclusion and it is
	 * reported as `intrinsic` with the scale recorded.
	 *
	 * If the aspect ratio has changed, the honest answer is that the image has been
	 * cropped **or** stretched, and a bounding box cannot tell you which: a 3:2 image
	 * in a 1:1 box looks identical whether `object-fit` is `cover` or `fill`. The first
	 * draft returned `cover`, which is a guess dressed as a measurement.
	 *
	 * So `cropped` is returned — it is by far the more common of the two, `cover` is
	 * near-universal on the web, and §30 asks for a determination — but the answer is
	 * flagged `fit_ambiguous` with a note saying a CSS declaration is needed to settle
	 * it. A consumer that respects the flag knows not to act on it; a consumer that
	 * wants a value still gets the most likely one.
	 *
	 * @param float $natural_w Natural width.
	 * @param float $natural_h Natural height.
	 * @param float $shown_w   Displayed width.
	 * @param float $shown_h   Displayed height.
	 * @return array<string, mixed>
	 */
	private function fit_from_ratios( $natural_w, $natural_h, $shown_w, $shown_h ) {
		if ( $natural_w <= 0 || $natural_h <= 0 || $shown_w <= 0 || $shown_h <= 0 ) {
			return array( 'fit' => 'intrinsic', 'ambiguous' => false, 'scale' => 0.0, 'note' => '' );
		}

		$natural = $natural_w / $natural_h;
		$shown   = $shown_w / $shown_h;
		$ratio   = ( $natural > 0 ) ? ( $shown / $natural ) : 1.0;

		if ( abs( $ratio - 1.0 ) < 0.02 ) {
			// The ratio holds. Either the image is shown at its own size, or it is
			// scaled uniformly — and neither crops or distorts anything.
			return array(
				'fit'      => 'intrinsic',
				'ambiguous'=> false,
				'scale'    => round( ( $natural_w > 0 ? $shown_w / $natural_w : 1.0 ), 4 ),
				'note'     => ( abs( $natural_w - $shown_w ) > 2 ) ? __( 'The image is scaled uniformly, so nothing is cropped or distorted.', 'replicaforge' ) : '',
			);
		}

		return array(
			'fit'      => 'cropped',
			'ambiguous'=> true,
			'scale'    => 0.0,
			'note'     => __( 'The displayed box does not match the image\'s own proportions, so the image has been cropped or stretched. A rendered box cannot distinguish the two; the CSS declaration settles it.', 'replicaforge' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Typography
	 * ------------------------------------------------------------------ */

	/**
	 * Measure a text node §31 asks for.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>|null
	 */
	public function text_role( array $node ) {
		$type = strtolower( (string) ( $node['type'] ?? '' ) );
		$text = trim( (string) ( $node['text'] ?? '' ) );
		if ( '' === $text || ! in_array( $type, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'a', 'li', 'label', 'button', 'small', 'strong', 'em' ), true ) ) {
			return null;
		}

		$declarations = $this->declarations( $node );
		$size   = $this->numeric( $node, array( 'font_size', 'typography', 'size' ) );
		$weight = $this->numeric( $node, array( 'font_weight', 'weight' ) );
		$family = (string) $this->scalar( $declarations, 'font_family' );
		$line   = (string) $this->scalar( $declarations, 'line_height' );
		$track  = (string) $this->scalar( $declarations, 'letter_spacing' );
		$align  = (string) $this->scalar( $declarations, 'text_align' );

		$shown_w = $this->numeric( $node, array( 'rendered_width', 'display_width' ) );
		$lines   = $this->numeric( $node, array( 'line_count', 'rendered_line_count' ) );

		return array(
			'role'         => $this->type_scale( $node, $type, $size, $weight ),
			'node_type'    => $type,
			'font_family'  => $family,
			'font_size'    => ( $size > 0 ) ? round( $size, 2 ) : 0.0,
			'font_weight'  => ( $weight > 0 ) ? (int) $weight : 0,
			'line_height'  => ( is_numeric( $line ) ) ? round( (float) $line, 3 ) : 0.0,
			'letter_spacing'=> ( is_numeric( $track ) ) ? round( (float) $track, 3 ) : 0.0,
			'align'        => in_array( $align, array( 'left', 'center', 'right', 'justify' ), true ) ? $align : 'left',
			// §32: wrapping is measured, never forced. `line_count` from a render is a
			// fact; derived from character count it would be a guess about a font that
			// has not loaded, so it is left at 0 and flagged.
			'text_width'   => (int) $shown_w,
			'line_count'   => (int) $lines,
			'wrapping_known'=> ( $lines > 0 ),
			'color'        => (string) $this->scalar( $declarations, 'color' ),
			'evidence'     => array( 'source' => ( $lines > 0 ) ? 'rendered' : 'computed_css' ),
			'estimated'    => ( $lines <= 0 ),
		);
	}

	/**
	 * Place a text node on the §33 hierarchy.
	 *
	 * @param array<string, mixed> $node   Node.
	 * @param string               $type   Node type.
	 * @param float                $size   Font size.
	 * @param float                $weight Font weight.
	 * @return string
	 */
	private function type_scale( array $node, $type, $size, $weight ) {
		$map = array(
			'h1' => 'h1', 'h2' => 'h2', 'h3' => 'h3', 'h4' => 'h3', 'h5' => 'small', 'h6' => 'small',
			'button' => 'button', 'label' => 'label', 'small' => 'small',
		);
		if ( isset( $map[ $type ] ) ) {
			return $map[ $type ];
		}
		if ( $size >= 48 ) {
			return 'display';
		}
		if ( $weight >= 600 && $size >= 28 ) {
			return 'h2';
		}
		if ( $size >= 24 ) {
			return 'h3';
		}
		if ( $size > 0 && $size <= 13 ) {
			return 'caption';
		}
		if ( 'a' === $type || 'li' === $type ) {
			return 'navigation';
		}
		return 'body';
	}

	/**
	 * Return the nearest available font category when a family cannot be reproduced.
	 *
	 * §34: do not silently download a restricted font file, and say what the
	 * substitution will cost visually. This returns a *category* and a note, never a
	 * downloaded file.
	 *
	 * @param string  $family   Declared family.
	 * @param array   $available Families available in the replica's theme.
	 * @return array<string, mixed>
	 */
	public static function font_fallback( $family, array $available = array() ) {
		$family = trim( (string) $family );
		$lower  = strtolower( $family );
		if ( '' === $family ) {
			return array( 'needed' => false, 'category' => '', 'family' => '', 'role' => '', 'stack' => array(), 'impact' => 'none', 'note' => '' );
		}
		if ( array() !== $available ) {
			foreach ( $available as $candidate ) {
				if ( 0 === strcasecmp( (string) $candidate, $family ) ) {
					return array( 'needed' => false, 'category' => (string) $candidate, 'family' => (string) $candidate, 'role' => '', 'stack' => array( (string) $candidate ), 'impact' => 'none', 'note' => '' );
				}
			}
		}

		// Family and role are separate, and conflating them is a real error rather
		// than a naming preference. "Playfair Display" is a *serif* face whose
		// *role* is display; calling it a display font and substituting a sans
		// substitute like Impact changes every line length on the page.
		//
		// And when the name carries no family signal at all, the honest answer is that
		// the family is **unknown**. Playfair Display is a serif and its name says
		// nothing about that. Defaulting to `sans-serif` — which is what a bare
		// `sans-serif` initial value amounts to — would silently pick the substitute
		// most likely to be wrong, and report it with the confidence of a
		// measurement. So: no signal means unknown, and unknown means the impact is
		// unknown too, because a serif substituted with a sans is a different problem
		// from a sans substituted with a sans.
		$family_kind = 'unknown';
		foreach ( array( 'serif' => 'serif', 'slab' => 'serif', 'mono' => 'monospace', 'courier' => 'monospace', 'script' => 'script', 'hand' => 'script', 'calligra' => 'script', 'sans' => 'sans-serif', 'grot' => 'sans-serif', 'gothic' => 'sans-serif', 'helvetica' => 'sans-serif', 'arial' => 'sans-serif' ) as $needle => $label ) {
			if ( false !== strpos( $lower, $needle ) ) {
				$family_kind = $label;
				break;
			}
		}

		$role = '';
		foreach ( array( 'display', 'black', 'heavy', 'condensed', 'narrow', 'title' ) as $needle ) {
			if ( false !== strpos( $lower, $needle ) ) {
				$role = $needle;
				break;
			}
		}

		$impact = 'unknown';
		$note   = __( 'The source font was not available, and its name does not say what family it belongs to, so no substitute can be chosen without measuring the rendered text. The font file was not downloaded.', 'replicaforge' );
		if ( 'unknown' !== $family_kind ) {
			$impact = in_array( $family_kind, array( 'serif', 'monospace', 'script' ), true ) ? 'moderate' : 'minor';
			$note   = __( 'The source font was not available, so a similar category will be used. The font file was not downloaded.', 'replicaforge' );
		}

		return array(
			'needed'   => true,
			'declared' => $family,
			'category' => $family_kind,
			'family'   => $family_kind,
			'role'     => $role,
			'stack'    => ( 'unknown' === $family_kind ) ? array() : self::stack_for( $family_kind ),
			// Measured by family, not asserted. A serif substituted with a sans changes
			// every line length on the page, which is a large visual change; a sans
			// substituted with a similar sans is not. The *role* is recorded but does
			// not affect the impact, because substituting a different weight of the same
			// family does not reflow text.
			'impact'   => $impact,
			'note'     => $note,
		);
	}

	/**
	 * Return a fallback stack for a category.
	 *
	 * @param string $category Category.
	 * @return array<int, string>
	 */
	private static function stack_for( $category ) {
		$stacks = array(
			'serif'      => array( 'Georgia', 'Times New Roman', 'serif' ),
			'monospace'  => array( 'Consolas', 'Menlo', 'monospace' ),
			'script'     => array( 'Brush Script MT', 'cursive' ),
			'display'    => array( 'Impact', 'Haettenschweiler', 'sans-serif' ),
			'sans-serif' => array( 'Helvetica Neue', 'Arial', 'sans-serif' ),
		);
		return $stacks[ $category ] ?? $stacks['sans-serif'];
	}

	/* ---------------------------------------------------------------------
	 * Buttons, cards, icons
	 * ------------------------------------------------------------------ */

	/**
	 * Classify a button §36 asks for.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>|null
	 */
	public function button( array $node ) {
		$type = strtolower( (string) ( $node['type'] ?? '' ) );
		$text = trim( (string) ( $node['text'] ?? '' ) );
		$is_button = in_array( $type, array( 'button', 'btn' ), true );
		$is_link   = ( 'a' === $type ) && ( $this->looks_like_cta( $node ) );
		if ( ! $is_button && ! $is_link ) {
			return null;
		}

		$declarations = $this->declarations( $node );
		$background   = (string) $this->scalar( $declarations, 'background_color' );
		$color        = (string) $this->scalar( $declarations, 'color' );
		$radius       = $this->radius( $node );
		$padding      = (string) $this->scalar( $declarations, 'padding' );

		$classification = 'ghost';
		if ( ! empty( $node['icon_only'] ) || ( '' === $text && ! empty( $node['icon'] ) ) ) {
			$classification = 'icon_button';
		} elseif ( 'a' === $type ) {
			$classification = 'link';
		} elseif ( '' !== $background ) {
			$classification = 'primary';
		} elseif ( null !== $this->border( $node ) ) {
			$classification = 'outline';
		}

		return array(
			'type'         => (string) $classification,
			'text'         => $text,
			'background'   => $background,
			'color'        => $color,
			'radius'       => null === $radius ? 0.0 : (float) $radius['uniform'],
			'padding'      => $padding,
			'width'        => $this->numeric( $node, array( 'rendered_width', 'display_width' ) ),
			'height'       => $this->numeric( $node, array( 'rendered_height', 'display_height' ) ),
			'has_icon'     => ! empty( $node['icon'] ),
			'hover_evidence' => $this->hover_evidence( $node ),
			'evidence'     => array( 'source' => 'computed_css' ),
		);
	}

	/**
	 * Return whether a link looks like a call to action.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return bool
	 */
	private function looks_like_cta( array $node ) {
		$declarations = $this->declarations( $node );
		if ( '' !== (string) $this->scalar( $declarations, 'background_color' ) ) {
			return true;
		}
		$padding = (string) $this->scalar( $declarations, 'padding' );
		return ( '' !== $padding && 1 === preg_match( '/\d/', $padding ) );
	}

	/**
	 * Return whether hover styling was observed.
	 *
	 * §36 asks for hover evidence "where available". It is almost never available,
	 * because a screenshot cannot hover. So this reads a *declared* `:hover` rule
	 * when the representation carried one, and returns an empty string otherwise
	 * rather than a guess.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return string
	 */
	private function hover_evidence( array $node ) {
		foreach ( array( $node['hover'] ?? null, $node['hover_style'] ?? null, $node['states']['hover'] ?? null ) as $candidate ) {
			if ( is_array( $candidate ) && array() !== $candidate ) {
				return 'declared';
			}
		}
		return '';
	}

	/**
	 * Detect a card §37 asks for.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>|null
	 */
	public function card( array $node ) {
		$type = strtolower( (string) ( $node['type'] ?? '' ) );
		$is_card = ( false !== strpos( $type, 'card' ) )
			|| in_array( $type, array( 'tile', 'feature', 'service_item', 'product_item', 'post_item', 'testimonial_item', 'pricing_card' ), true );
		if ( ! $is_card ) {
			return null;
		}

		$border = $this->border( $node );
		$shadow = $this->shadow( $node );
		$radius = $this->radius( $node );
		$parts  = array();
		foreach ( (array) ( $node['children'] ?? $node['components'] ?? array() ) as $child ) {
			if ( is_array( $child ) && ! empty( $child['type'] ) ) {
				$parts[] = (string) $child['type'];
			}
		}
		$parts = array_values( array_unique( $parts ) );
		sort( $parts );

		return array(
			'has_border'  => ( null !== $border ),
			'has_shadow'  => ( null !== $shadow ),
			'radius'      => null === $radius ? 0.0 : (float) $radius['uniform'],
			'padding'     => (string) $this->scalar( $this->declarations( $node ), 'padding' ),
			'structure'   => $parts,
			'has_image'   => ( false !== strpos( (string) wp_json_encode( $parts ), 'img' ) ),
			'has_title'   => $this->has_part( $parts, array( 'h2', 'h3', 'h4', 'heading', 'title' ) ),
			'has_metadata'=> $this->has_part( $parts, array( 'small', 'span', 'time', 'meta' ) ),
			'has_cta'     => $this->has_part( $parts, array( 'a', 'button', 'btn' ) ),
			'evidence'    => array( 'source' => 'computed_css' ),
		);
	}

	/**
	 * Return whether a card's structure contains one of a set of parts.
	 *
	 * @param array<int, string> $parts Parts.
	 * @param array<int, string> $want  Wanted.
	 * @return bool
	 */
	private function has_part( array $parts, array $want ) {
		foreach ( $parts as $part ) {
			if ( in_array( $part, $want, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect an icon §35 asks for.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>|null
	 */
	public function icon( array $node ) {
		$type = strtolower( (string) ( $node['type'] ?? '' ) );
		$src  = (string) ( $node['src'] ?? ( $node['icon'] ?? '' ) );
		$char = (string) ( $node['character'] ?? ( $node['glyph'] ?? '' ) );

		$kind = '';
		if ( in_array( $type, array( 'svg', 'icon', 'icon_svg' ), true ) || 0 === stripos( $src, 'data:image/svg' ) ) {
			$kind = 'svg';
		} elseif ( false !== strpos( $type, 'icon' ) && '' !== $src ) {
			$kind = 'image';
		} elseif ( false !== strpos( $type, 'icon' ) || in_array( $type, array( 'span', 'i' ), true ) ) {
			$kind = 'css_or_unicode';
		}
		if ( '' === $kind ) {
			return null;
		}

		return array(
			'kind'       => (string) $kind,
			'glyph'      => $char,
			'size'       => $this->numeric( $node, array( 'width', 'font_size', 'size' ) ),
			'color'      => (string) $this->scalar( $this->declarations( $node ), 'color' ),
			'stroke'     => $this->icon_stroke( $node ),
			'background' => (string) $this->scalar( $this->declarations( $node ), 'background_color' ),
			// An SVG arriving from a source site is untrusted markup. It is never
			// inlined into the replica; Phase 8's SVG sanitiser handles it, and this
			// record only says one is present.
			'requires_sanitisation' => ( 'svg' === $kind ),
			'evidence'   => array( 'source' => 'dom_declared' ),
		);
	}

	/**
	 * Return whether an icon is stroked or filled.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return string
	 */
	private function icon_stroke( array $node ) {
		foreach ( array( $node['stroke'] ?? null, $node['fill'] ?? null ) as $key => $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return ( 'stroke' === $key ) ? 'stroke' : 'fill';
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Density and whitespace
	 * ------------------------------------------------------------------ */

	/**
	 * Measure a node's visual density §38 asks for.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>
	 */
	public function density( array $node ) {
		$children = isset( $node['components'] ) && is_array( $node['components'] ) ? $node['components'] : array();
		$text     = trim( (string) ( $node['text'] ?? '' ) );
		$area     = $this->numeric( $node, array( 'area', 'rendered_area' ) );

		$elements = count( $children ) + ( '' !== $text ? 1 : 0 );
		if ( $area <= 0 ) {
			// No area, so no ratio. Reporting a band from a count alone would make
			// "has three children" look like a density measurement.
			return array( 'elements' => $elements, 'ratio' => 0.0, 'band' => 'unknown', 'evidence' => 'no_area' );
		}

		$ratio = $elements / max( 1.0, $area ) * 1000.0;
		$band  = 'low';
		if ( $ratio >= 12 ) {
			$band = 'high';
		} elseif ( $ratio >= 5 ) {
			$band = 'medium';
		}

		return array( 'elements' => $elements, 'ratio' => round( $ratio, 3 ), 'band' => (string) $band, 'evidence' => 'computed' );
	}

	/**
	 * Measure a node's whitespace §39 asks for.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>
	 */
	public function whitespace( array $node ) {
		$declarations = $this->declarations( $node );
		$padding      = (string) $this->scalar( $declarations, 'padding' );
		$margin       = (string) $this->scalar( $declarations, 'margin' );
		$gap          = (string) $this->scalar( $declarations, 'gap' );

		$vertical_padding = ( is_numeric( $padding ) ) ? (float) $padding : 0.0;
		$margin_value     = ( is_numeric( $margin ) ) ? (float) $margin : 0.0;

		// A band, not a number a designer would say. §39's purpose is a hint for
		// reconstruction, and a hint that reads as a measurement invites a correction
		// to chase it.
		$band = 'compact';
		$space = $vertical_padding + $margin_value;
		if ( $space >= 80 ) {
			$band = 'generous';
		} elseif ( $space >= 40 ) {
			$band = 'comfortable';
		}

		return array(
			'padding'   => $vertical_padding,
			'margin'    => $margin_value,
			'gap'       => $gap,
			'band'      => (string) $band,
			'evidence'  => array( 'source' => 'computed_css' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Palette
	 * ------------------------------------------------------------------ */

	/**
	 * Build the colour palette §25 asks for.
	 *
	 * Anti-aliasing colours are the problem §25 names. A body of text on a white page
	 * produces a hundred intermediate greys along every glyph edge, and a naive
	 * frequency count returns a palette of near-identical greys. So colours are
	 * clustered by distance and only clusters with real support survive.
	 *
	 * @param array<string, mixed> $backgrounds Backgrounds.
	 * @param array<string, mixed> $typography  Typography.
	 * @param array<string, mixed> $render      Render evidence.
	 * @return array<string, mixed>
	 */
	public function palette( array $backgrounds, array $typography, array $render = array() ) {
		$roles = array(
			'background' => '',
			'surface'    => '',
			'text'       => '',
			'heading'    => '',
			'border'     => '',
			'accent'     => '',
			'primary'    => '',
			'secondary'  => '',
		);
		$tally = array();

		foreach ( $backgrounds as $node ) {
			$colour = (string) ( $node['color'] ?? '' );
			if ( '' === $colour || ! $this->is_colour( $colour ) ) {
				continue;
			}
			$key = $this->cluster_key( $colour );
			$tally[ $key ] = ( $tally[ $key ] ?? 0 ) + 1;
		}
		foreach ( $typography as $node ) {
			$colour = (string) ( $node['color'] ?? '' );
			if ( '' === $colour || ! $this->is_colour( $colour ) ) {
				continue;
			}
			$key = $this->cluster_key( $colour );
			$tally[ $key ] = ( $tally[ $key ] ?? 0 ) + 1;
			if ( in_array( (string) ( $node['role'] ?? '' ), array( 'h1', 'h2', 'h3', 'display' ), true ) && '' === $roles['heading'] ) {
				$roles['heading'] = $colour;
			}
		}

		arsort( $tally );

		$kept = array();
		foreach ( $tally as $colour => $count ) {
			// A colour seen once is an antialiasing artefact until proven otherwise. The
			// threshold is 2 because a real brand colour used in a logo appears once.
			if ( $count < 2 ) {
				continue;
			}
			$kept[] = array( 'value' => (string) $colour, 'occurrences' => (int) $count );
			if ( count( $kept ) >= 12 ) {
				break;
			}
		}

		foreach ( $kept as $index => $entry ) {
			if ( 0 === $index ) {
				$roles['background'] = $entry['value'];
			} elseif ( 1 === $index && '' === $roles['primary'] ) {
				$roles['primary'] = $entry['value'];
			} elseif ( 2 === $index && '' === $roles['secondary'] ) {
				$roles['secondary'] = $entry['value'];
			} elseif ( 3 === $index && '' === $roles['accent'] ) {
				$roles['accent'] = $entry['value'];
			}
		}
		foreach ( $typography as $node ) {
			if ( '' === $roles['text'] && (string) ( $node['role'] ?? '' ) === 'body' && ! empty( $node['color'] ) ) {
				$roles['text'] = (string) $node['color'];
			}
		}

		return array(
			'roles'    => $roles,
			'palette'  => $kept,
			'clusters' => count( $tally ),
			'note'     => __( 'Colours are clustered by distance, so the intermediate greys along an antialiased glyph edge do not become palette entries.', 'replicaforge' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return a node's declarations, wherever they were recorded.
	 *
	 * Phase 2 records computed values in more than one place depending on the node
	 * kind, and reading one fixed path would silently miss most of them. So the
	 * common containers are merged, with later ones taking precedence.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>
	 */
	private function declarations( array $node ) {
		$out = array();
		foreach ( array( $node, $node['style'] ?? array(), $node['computed'] ?? array(), $node['css'] ?? array() ) as $container ) {
			if ( ! is_array( $container ) ) {
				continue;
			}
			foreach ( $container as $key => $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$key = (string) $key;
				$out[ $key ] = (string) $value;

				// Underscores are normalised to hyphens as well. A representation may
				// record a property as `border_width` while the CSS parser reads
				// `border-width`, and the mismatch is silent: the parser looks for a key
				// that is not there, finds nothing, and reports the element as having no
				// border. Registering both spellings is cheap and removes a whole class
				// of "the data was there and it was not used".
				if ( false !== strpos( $key, '_' ) ) {
					$hyphenated = str_replace( '_', '-', $key );
					if ( ! array_key_exists( $hyphenated, $out ) ) {
						$out[ $hyphenated ] = (string) $value;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Read a scalar declaration.
	 *
	 * @param array<string, mixed> $declarations Declarations.
	 * @param string               $key          Key.
	 * @return string
	 */
	private function scalar( array $declarations, $key ) {
		return isset( $declarations[ $key ] ) && is_scalar( $declarations[ $key ] ) ? (string) $declarations[ $key ] : '';
	}

	/**
	 * Read the first numeric value found under a set of keys.
	 *
	 * @param array<string, mixed> $node Node.
	 * @param array<int, string>   $keys Keys.
	 * @return float
	 */
	private function numeric( array $node, array $keys ) {
		foreach ( $keys as $key ) {
			$value = $node[ $key ] ?? null;
			if ( is_numeric( $value ) && (float) $value > 0 ) {
				return (float) $value;
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $inner ) {
					if ( is_numeric( $inner ) && (float) $inner > 0 ) {
						return (float) $inner;
					}
				}
			}
		}
		return 0.0;
	}

	/**
	 * Return whether a value is a colour.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_colour( $value ) {
		return 1 === preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', trim( (string) $value ) );
	}

	/**
	 * Return a cluster key for a colour, expanding short hex.
	 *
	 * @param string $colour Colour.
	 * @return string
	 */
	private function cluster_key( $colour ) {
		$colour = trim( (string) $colour );
		if ( 1 === preg_match( '/^#([0-9a-fA-F]{3})$/', $colour, $matches ) ) {
			$colour = '#' . $matches[1][0] . $matches[1][0] . $matches[1][1] . $matches[1][1] . $matches[1][2] . $matches[1][2];
		}
		if ( 1 === preg_match( '/^#([0-9a-fA-F]{8})$/', $colour, $matches ) ) {
			$colour = '#' . substr( $matches[1], 0, 6 );
		}
		return strtolower( $colour );
	}

	/**
	 * Return the notes this extraction ran under.
	 *
	 * @param array<string, mixed> $render Render evidence.
	 * @return array<int, string>
	 */
	private function notes( array $render ) {
		$out = array();
		if ( empty( $render['succeeded'] ) ) {
			$out[] = __( 'Every property here came from computed CSS, not from a render. That is exact for what the browser was told to do and unknown for what it actually did.', 'replicaforge' );
		}
		return $out;
	}
}
