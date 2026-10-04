<?php
/**
 * Phase 13: the visual design representation and its evidence rules.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The §49 visual representation, and the §51/§52 evidence and conflict rules.
 *
 * ### Independent from Elementor, on purpose
 *
 * §49 says the representation must remain independent from Elementor, and the reason
 * is that a representation that knows about containers and widgets can only be checked
 * by a machine that also knows about containers and widgets. Then "the replica is
 * correct" and "Elementor agrees" become the same claim, and a bug in the Elementor
 * layer becomes undetectable.
 *
 * So nothing here mentions a widget, an element type, or a control. The vocabulary is
 * boxes, regions, colours, measurements, and evidence. The Elementor layer is a
 * *consumer*, and it has to justify its translation.
 *
 * ### Evidence is structural, not a note
 *
 * §51 asks for evidence on every important inference and §52 for both sides of a
 * disagreement. Both are enforced by {@see self::record()}, which refuses to store an
 * inference without evidence and refuses to resolve a conflict. The alternative — an
 * `evidence` key that callers may omit — produces a document that looks rigorous and
 * is not, which is worse than one that is visibly thin.
 */
final class Visual_Representation {

	/**
	 * The representation.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Validation problems.
	 *
	 * @var array<int, string>
	 */
	private $errors = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Representation.
	 */
	public function __construct( array $data = array() ) {
		$this->data = $data;
	}

	/**
	 * Return the representation as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * Record one visual inference with its evidence.
	 *
	 * Refuses an inference with no evidence. §51's requirement is not a convention —
	 * a confidence number with nothing behind it is a fabricated measurement, and a
	 * fabricated measurement is what a correction loop then acts on.
	 *
	 * @param string               $id       Element id.
	 * @param string               $property  Property name.
	 * @param mixed                $value     Value.
	 * @param array<string, mixed> $evidence  Evidence.
	 * @return bool Whether it was recorded.
	 */
	public function record( $id, $property, $value, array $evidence ) {
		$id       = (string) $id;
		$property = (string) $property;

		if ( '' === $id || '' === $property ) {
			return false;
		}
		if ( array() === $evidence ) {
			// Refused, and refused *silently as a no-op* rather than stored with an
			// empty evidence array. A stored inference with no evidence is
			// indistinguishable from a measured one at read time.
			return false;
		}
		if ( ! isset( $evidence['confidence'] ) || ! is_numeric( $evidence['confidence'] ) ) {
			$derived = $this->default_confidence( $evidence );
			// A caller may supply one of the two without the other; the derived value
			// fills the gap rather than being ignored, so an evidence record is never
			// half-described.
			if ( ! isset( $evidence['confidence'] ) ) {
				$evidence['confidence'] = (float) $derived['confidence'];
			}
			if ( ! isset( $evidence['source'] ) || 'unknown' === $evidence['source'] ) {
				$evidence['source'] = (string) $derived['source'];
			}
		}

		if ( ! isset( $this->data['evidence'] ) || ! is_array( $this->data['evidence'] ) ) {
			$this->data['evidence'] = array();
		}
		$this->data['evidence'][] = array(
			'element'  => $id,
			'property' => $property,
			'value'    => $this->scalarise( $value ),
			'evidence' => $evidence,
		);

		if ( count( $this->data['evidence'] ) > 2000 ) {
			$this->data['evidence'] = array_slice( $this->data['evidence'], -2000 );
		}
		return true;
	}

	/**
	 * Record a disagreement between two sources, keeping both.
	 *
	 * §52's rule: CSS says 40px, the render says 36px, and *both* are stored with a
	 * status. The status vocabulary is `exact`, `approximate`, `conflict`, `unknown` —
	 * and there is deliberately no `resolved`, because a resolution requires knowing
	 * which source will be reproduced, and that depends on where the value is going.
	 * A container width going into an Elementor setting is CSS-reproducible; a
	 * container width going into a pixel comparison is not.
	 *
	 * @param string              $property Property.
	 * @param mixed               $computed CSS value.
	 * @param mixed               $measured Measured value.
	 * @param float               $delta    Absolute difference.
	 * @param string              $id       Element id.
	 * @return array<string, mixed>
	 */
	public function record_conflict( $property, $computed, $measured, $delta, $id = '' ) {
		$delta  = abs( (float) $delta );
		$tolerance = (float) ( Validation_Limits::TOLERANCES['length']['medium'] ?? 12.0 );
		$exact_band = (float) ( Validation_Limits::TOLERANCES['length']['small'] ?? 4.0 );

		if ( $delta <= $exact_band ) {
			$status = 'exact';
		} elseif ( $delta <= $tolerance ) {
			$status = 'approximate';
		} else {
			$status = 'conflict';
		}

		$entry = array(
			'id'         => (string) $id,
			'property'   => (string) $property,
			'computed'   => $this->scalarise( $computed ),
			'measured'   => $this->scalarise( $measured ),
			'delta'      => round( $delta, 3 ),
			'tolerance'  => $tolerance,
			'status'     => (string) $status,
			// Both kept. A consumer that needs a CSS-reproducible number reads
			// `computed`; one that needs what a visitor saw reads `measured`.
			'resolution' => '',
			'note'       => ( 'conflict' === $status )
				? __( 'The declared value and the measured value disagree by more than the tolerance. Both are kept and neither was discarded.', 'replicaforge' )
				: '',
		);

		if ( ! isset( $this->data['measurement_conflicts'] ) || ! is_array( $this->data['measurement_conflicts'] ) ) {
			$this->data['measurement_conflicts'] = array();
		}
		$this->data['measurement_conflicts'][] = $entry;

		if ( count( $this->data['measurement_conflicts'] ) > Visual_Limits::MAX_MEASUREMENT_CONFLICTS ) {
			$this->data['measurement_conflicts'] = array_slice( $this->data['measurement_conflicts'], 0, Visual_Limits::MAX_MEASUREMENT_CONFLICTS );
		}

		return $entry;
	}

	/**
	 * Build the §49 representation.
	 *
	 * @param array<string, mixed> $geometry  Geometry from the analyzer.
	 * @param array<string, mixed> $features  Features.
	 * @param array<string, mixed> $dynamics  Dynamic detection.
	 * @param array<string, mixed> $viewport  Viewport.
	 * @return array<string, mixed>
	 */
	public function build( array $geometry, array $features, array $dynamics, array $viewport ) {
		$this->data = array(
			'schema_version'        => Visual_Limits::SCHEMA_VERSION,
			'analyzer_version'      => Visual_Limits::ANALYZER_VERSION,
			'viewport'              => array(
				'name'               => (string) ( $viewport['name'] ?? 'desktop' ),
				'width'              => (int) ( $viewport['width'] ?? 1440 ),
				'height'             => (int) ( $viewport['height'] ?? 900 ),
				'device_pixel_ratio' => (float) ( $viewport['device_pixel_ratio'] ?? 1.0 ),
				'rendered'           => (bool) ( $viewport['rendered'] ?? false ),
			),
			'sections'              => (array) ( $geometry['sections'] ?? array() ),
			'components'            => $this->components( $geometry, $features, $dynamics ),
			'geometry'              => array(
				'boxes'      => (array) ( $geometry['geometry']['boxes'] ?? array() ),
				'containers' => (array) ( $geometry['geometry']['containers'] ?? array() ),
				'grids'      => (array) ( $geometry['geometry']['grids'] ?? array() ),
				'overlaps'   => (array) ( $geometry['overlaps'] ?? array() ),
			),
			'visual_relationships'  => (array) ( $geometry['visual_relationships'] ?? array() ),
			'colors'                => (array) ( $features['colors'] ?? array() ),
			'typography'            => (array) ( $features['typography'] ?? array() ),
			'backgrounds'           => (array) ( $features['backgrounds'] ?? array() ),
			'images'                => (array) ( $features['images'] ?? array() ),
			'gradients'             => (array) ( $features['gradients'] ?? array() ),
			'shadows'               => (array) ( $features['shadows'] ?? array() ),
			'borders'               => (array) ( $features['borders'] ?? array() ),
			'radius'                => (array) ( $features['radius'] ?? array() ),
			'buttons'               => (array) ( $features['buttons'] ?? array() ),
			'cards'                 => (array) ( $features['cards'] ?? array() ),
			'icons'                 => (array) ( $features['icons'] ?? array() ),
			'density'               => (array) ( $features['density'] ?? array() ),
			'whitespace'            => (array) ( $features['whitespace'] ?? array() ),
			'responsive'            => (array) ( $dynamics['responsive'] ?? array() ),
			'dynamic_elements'      => (array) ( $dynamics['elements'] ?? array() ),
			'excluded_from_build'   => (array) ( $dynamics['excluded'] ?? array() ),
			'masked_from_compare'   => (array) ( $dynamics['masked'] ?? array() ),
			'sticky'                => (array) ( $dynamics['sticky'] ?? array() ),
			'fixed'                 => (array) ( $dynamics['fixed'] ?? array() ),
			'animations'            => (array) ( $dynamics['animations'] ?? array() ),
			'carousel'              => (array) ( $dynamics['carousel'] ?? array() ),
			'video'                 => (array) ( $dynamics['video'] ?? array() ),
			'mobile_navigation'     => (array) ( $dynamics['mobile_nav'] ?? array() ),
			'evidence'              => (array) ( $this->data['evidence'] ?? array() ),
			'measurement_conflicts' => (array) ( $this->data['measurement_conflicts'] ?? array() ),
			'confidence'            => $this->confidence( $geometry, $features, $viewport ),
			'limitations'           => (array) ( $geometry['limitations'] ?? array() ),
			'warnings'              => (array) ( $features['notes'] ?? array() ),
			'built_at'              => time(),
			'elementor_independent' => true,
		);

		// §49 says the representation must remain independent from Elementor, and the
		// first draft was not. `Visual_Fffects` returns an `elementor` sub-array beside
		// every shadow and border — the single-value form Elementor's control takes —
		// and passing those through put Elementor's own structure inside a document
		// that is supposed to be checkable without knowing about Elementor.
		//
		// So the key is removed, recursively, and the *information* it carried is kept
		// as a plain statement of what is lost. A consumer that needs the Elementor form
		// re-derives it from the values beside it, which is the direction the
		// dependency should run.
		$this->data = $this->scrub_elementor( $this->data );

		return $this->data;
	}

	/**
	 * Remove every `elementor` key from a nested structure.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function scrub_elementor( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$out = array();
		foreach ( $value as $key => $inner ) {
			if ( 'elementor' === (string) $key ) {
				// Replaced by a statement of the loss rather than a structure that
				// cannot be represented, so the information survives without the
				// coupling. The replacement key is deliberately *not* named after the
				// consumer: a key called `elementor_limitation` would satisfy "the
				// structure is gone" while keeping Elementor's name inside a document
				// that is supposed to be checkable without knowing about it.
				$out['downstream_limitation'] = __( 'This property has a form that carries more than one variant, and only a single variant is described here.', 'replicaforge' );
				continue;
			}
			$out[ (string) $key ] = $this->scrub_elementor( $inner );
		}
		return $out;
	}

	/**
	 * Return the validation verdict.
	 *
	 * @return array<string, mixed>
	 */
	public function validate() {
		$this->errors = array();
		$data         = $this->data;

		if ( ! isset( $data['schema_version'] ) || Visual_Limits::SCHEMA_VERSION !== (string) $data['schema_version'] ) {
			$this->errors[] = 'invalid_schema_version';
		}
		foreach ( array( 'viewport', 'sections', 'components', 'geometry', 'visual_relationships', 'colors', 'typography', 'backgrounds', 'images', 'shadows', 'borders', 'responsive', 'dynamic_elements', 'confidence', 'limitations' ) as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$this->errors[] = 'missing_' . $key;
			}
		}

		$viewport = isset( $data['viewport'] ) && is_array( $data['viewport'] ) ? $data['viewport'] : array();
		foreach ( array( 'width', 'height' ) as $key ) {
			if ( ! isset( $viewport[ $key ] ) || ! is_numeric( $viewport[ $key ] ) || (int) $viewport[ $key ] < 1 ) {
				$this->errors[] = 'invalid_viewport_' . $key;
			}
		}
		$dpr = (float) ( $viewport['device_pixel_ratio'] ?? 0.0 );
		if ( $dpr < 0.1 || $dpr > 4.0 ) {
			$this->errors[] = 'invalid_device_pixel_ratio';
		}

		foreach ( (array) ( $data['visual_relationships'] ?? array() ) as $relationship ) {
			if ( ! is_array( $relationship ) || ! Visual_Limits::is_relationship( $relationship['relationship'] ?? '' ) ) {
				$this->errors[] = 'unknown_relationship';
				continue;
			}
			if ( empty( $relationship['source'] ) || empty( $relationship['target'] ) ) {
				$this->errors[] = 'relationship_missing_endpoint';
			}
		}

		foreach ( (array) ( $data['images'] ?? array() ) as $image ) {
			if ( ! is_array( $image ) || ! in_array( (string) ( $image['role'] ?? '' ), Visual_Limits::IMAGE_ROLES, true ) ) {
				$this->errors[] = 'unknown_image_role';
			}
		}

		// §51: an inference with no evidence is a defect in the document, not a
		// missing nicety.
		foreach ( (array) ( $data['evidence'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['evidence'] ) ) {
				$this->errors[] = 'evidence_without_basis';
			}
		}

		// A relationship must use a declared name. `overlaps` and `overlapping` are
		// the same fact with two spellings, and the first draft of the overlap list used
		// the former while the vocabulary declares the latter — so this check is what
		// turns a near-miss into a validation error rather than a silently dropped
		// record downstream.
		foreach ( (array) ( $data['geometry']['overlaps'] ?? array() ) as $overlap ) {
			if ( ! is_array( $overlap ) || ! Visual_Limits::is_relationship( $overlap['relationship'] ?? '' ) ) {
				$this->errors[] = 'unknown_relationship';
			}
		}

		// §52: a conflict must keep both sides.
		foreach ( (array) ( $data['measurement_conflicts'] ?? array() ) as $conflict ) {
			if ( ! is_array( $conflict ) ) {
				continue;
			}
			if ( ! array_key_exists( 'computed', $conflict ) || ! array_key_exists( 'measured', $conflict ) ) {
				$this->errors[] = 'conflict_missing_side';
			}
			if ( ! in_array( (string) ( $conflict['status'] ?? '' ), array( 'exact', 'approximate', 'conflict', 'unknown' ), true ) ) {
				$this->errors[] = 'conflict_bad_status';
			}
		}

		$warnings = array();
		if ( empty( $viewport['rendered'] ) ) {
			$warnings[] = 'no_render';
		}
		if ( ! empty( $data['measurement_conflicts'] ) ) {
			$warnings[] = 'measurement_conflicts_present';
		}

		return array(
			'valid'    => ( 0 === count( $this->errors ) ),
			'errors'   => $this->errors,
			'warnings' => $warnings,
			'components' => count( (array) ( $data['components'] ?? array() ) ),
			'evidence' => count( (array) ( $data['evidence'] ?? array() ) ),
			'conflicts' => count( (array) ( $data['measurement_conflicts'] ?? array() ) ),
		);
	}

	/**
	 * Return whether the representation is valid.
	 *
	 * @return bool
	 */
	public function is_valid() {
		$verdict = $this->validate();
		return (bool) $verdict['valid'];
	}

	/**
	 * Return the validation errors.
	 *
	 * @return array<int, string>
	 */
	public function get_validation_errors() {
		$this->validate();
		return $this->errors;
	}

	/**
	 * Build the component records.
	 *
	 * @param array<string, mixed> $geometry Geometry.
	 * @param array<string, mixed> $features Features.
	 * @param array<string, mixed> $dynamics Dynamics.
	 * @return array<int, array<string, mixed>>
	 */
	private function components( array $geometry, array $features, array $dynamics ) {
		$boxes      = (array) ( $geometry['geometry']['boxes'] ?? array() );
		$excluded   = array_flip( (array) ( $dynamics['excluded'] ?? array() ) );
		$dynamic    = array();
		foreach ( (array) ( $dynamics['elements'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
				$dynamic[ (string) $entry['id'] ] = $entry;
			}
		}

		$out = array();
		foreach ( $boxes as $id => $box ) {
			$id = (string) $id;
			$out[] = array(
				'component_id'    => $id,
				'type'            => (string) $box['type'],
				'bbox'            => (array) $box['box'],
				'evidence'        => (array) ( $box['evidence'] ?? array() ),
				'confidence'      => (float) ( $box['confidence'] ?? 0.0 ),
				'derived'         => (bool) ( $box['derived'] ?? false ),
				'excluded'        => isset( $excluded[ $id ] ),
				'dynamic'         => isset( $dynamic[ $id ] ),
				'background'      => (array) ( $features['backgrounds'][ $id ] ?? array() ),
				'typography'      => (array) ( $features['typography'][ $id ] ?? array() ),
				'image'           => (array) ( $features['images'][ $id ] ?? array() ),
				'shadow'          => (array) ( $features['shadows'][ $id ] ?? array() ),
				'border'          => (array) ( $features['borders'][ $id ] ?? array() ),
				'radius'          => (array) ( $features['radius'][ $id ] ?? array() ),
				'button'          => (array) ( $features['buttons'][ $id ] ?? array() ),
				'card'            => (array) ( $features['cards'][ $id ] ?? array() ),
				'icon'            => (array) ( $features['icons'][ $id ] ?? array() ),
			);
		}
		return $out;
	}

	/**
	 * Return the confidence of the representation as a whole.
	 *
	 * Arithmetic and itemised, not a single flattering number — the same reasoning
	 * Phase 12 applied to a website's confidence. A user deciding whether to trust a
	 * visual model needs to know *which* part is weak.
	 *
	 * @param array<string, mixed> $geometry Geometry.
	 * @param array<string, mixed> $features Features.
	 * @param array<string, mixed> $viewport Viewport.
	 * @return array<string, mixed>
	 */
	private function confidence( array $geometry, array $features, array $viewport ) {
		$boxes      = (array) ( $geometry['geometry']['boxes'] ?? array() );
		$total      = max( 1, count( $boxes ) );
		$derived    = 0;
		$sum        = 0.0;
		foreach ( $boxes as $box ) {
			$sum += (float) ( $box['confidence'] ?? 0.0 );
			if ( ! empty( $box['derived'] ) ) {
				$derived++;
			}
		}
		$mean   = $sum / $total;
		$render = ! empty( $viewport['rendered'] );

		return array(
			'rendered'          => $render,
			'boxes'             => count( $boxes ),
			'derived_boxes'     => $derived,
			'derived_ratio'     => round( $derived / $total, 3 ),
			'mean_confidence'   => round( $mean, 3 ),
			// With no render, confidence cannot exceed 0.6 however good the CSS is —
			// and capping it is the point. A visual model built entirely from
			// declarations is a good description of the source and a weak description
			// of the page, and the number has to say so.
			'ceiling'           => $render ? 1.0 : 0.6,
			'overall'           => round( min( $render ? 1.0 : 0.6, $mean ), 3 ),
			'note'              => $render
				? __( 'Built from both computed styles and rendered geometry.', 'replicaforge' )
				: __( 'No render was available, so confidence is capped: this describes the source\'s declared styles, not what a visitor saw.', 'replicaforge' ),
		);
	}

	/**
	 * Return the confidence and source implied by a set of evidence.
	 *
	 * A mapping rather than a per-caller number, so two callers producing the same
	 * kind of evidence cannot disagree about its strength. It also *derives* the
	 * source rather than only the confidence, because an evidence record whose
	 * source reads `unknown` while carrying a rendered bounding box is
	 * self-contradictory — which is exactly what happened when only the confidence
	 * was derived.
	 *
	 * @param array<string, mixed> $evidence Evidence.
	 * @return array<string, mixed>
	 */
	private function default_confidence( array $evidence ) {
		// `visual_bbox` and `rendered` both mean "measured from a render". They are
		// treated as the same signal so a caller can use whichever name it has.
		$rendered = ! empty( $evidence['rendered'] ) || ! empty( $evidence['visual_bbox'] ) || ! empty( $evidence['rendered_bbox'] );
		$computed = ! empty( $evidence['computed_css'] ) || ! empty( $evidence['declared'] );

		if ( $rendered && $computed ) {
			return array( 'confidence' => 0.95, 'source' => 'computed_css_and_render' );
		}
		if ( $rendered ) {
			return array( 'confidence' => 0.85, 'source' => 'render' );
		}
		if ( $computed ) {
			return array( 'confidence' => 0.75, 'source' => 'computed_css' );
		}
		return array( 'confidence' => 0.5, 'source' => 'unclassified' );
	}

	/**
	 * Reduce a value to something storable as evidence.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function scalarise( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $inner ) {
				$out[ (string) $key ] = $this->scalarise( $inner );
			}
			return $out;
		}
		return null;
	}
}
