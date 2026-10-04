<?php
/**
 * Correction eligibility for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a detected difference may be corrected, and who must decide.
 *
 * Eligibility is the human-control boundary. A correction is `safe` only when
 * three things hold at once: the property is on the whitelist, the value is
 * deterministic and already measured, and nothing about the draft says a human
 * touched it. Everything else is either `requires_review`, which the user
 * decides, or `blocked`, which is never applied and is always explained.
 */
final class Correction_Eligibility {

	/**
	 * Property whitelist.
	 *
	 * @var Correction_Property_Map
	 */
	private $properties;

	/**
	 * Constructor.
	 *
	 * @param Correction_Property_Map|null $properties Optional property whitelist.
	 */
	public function __construct( $properties = null ) {
		$this->properties = $properties instanceof Correction_Property_Map ? $properties : new Correction_Property_Map();
	}

	/**
	 * Classify a candidate correction.
	 *
	 * @param array<string, mixed> $correction Candidate correction.
	 * @return array<string, mixed> The correction with `eligibility`, `reason`, and `auto` filled in.
	 */
	public function classify( array $correction ) {
		$action    = isset( $correction['action'] ) ? (string) $correction['action'] : '';
		$property  = isset( $correction['property'] ) ? (string) $correction['property'] : '';
		$category  = isset( $correction['category'] ) ? (string) $correction['category'] : '';
		$confidence = isset( $correction['confidence'] ) ? (float) $correction['confidence'] : 0.0;

		$correction['action']      = $action;
		$correction['property']    = $property;
		$correction['category']    = $category;
		$correction['confidence']  = $confidence;

		// 1. A property on the explicit never-correct list is refused outright.
		if ( in_array( $property, Correction_Limits::BLOCKED_PROPERTIES, true ) ) {
			return $this->decide( $correction, 'blocked', $this->blocked_reason( $property ) );
		}

		// 2. A structural action always needs a human decision.
		if ( in_array( $action, Correction_Limits::STRUCTURAL_ACTIONS, true ) ) {
			return $this->decide( $correction, 'requires_review', $this->structural_reason( $action, $property ) );
		}

		// 3. A property with no Elementor control cannot be written at all.
		if ( ! $this->properties->is_writable( $property ) ) {
			return $this->decide( $correction, 'blocked', $this->unwritable_reason( $property ) );
		}

		// 4. The value has to be one ReplicaForge is willing to store.
		if ( ! isset( $correction['value'] ) || null === $correction['value'] ) {
			return $this->decide( $correction, 'blocked', __( 'The source value for this property was not measured, so there is nothing to apply.', 'replicaforge' ) );
		}
		if ( null === $this->properties->coerce( $property, $correction['value'] ) ) {
			return $this->decide( $correction, 'blocked', __( 'The measured value is outside the range ReplicaForge is willing to write to an Elementor control.', 'replicaforge' ) );
		}

		// 5. The target must resolve to a real, mapped Elementor element.
		if ( empty( $correction['target']['elementor_element_id'] ) ) {
			return $this->decide( $correction, 'requires_review', __( 'No Elementor element is mapped to this difference, so ReplicaForge will not guess a target.', 'replicaforge' ) );
		}

		// 6. A manual change to the same property is a conflict, not a correction.
		if ( ! empty( $correction['manual_change']['modified'] ) ) {
			return $this->decide( $correction, 'requires_review', __( 'This property was changed after generation. Review the manual value before applying the source value.', 'replicaforge' ) );
		}

		// 7. A property that is not currently set would have to be created rather
		//    than corrected, which is a design decision rather than a fix.
		if ( ! empty( $correction['target_property_present'] ) === false ) {
			return $this->decide( $correction, 'requires_review', __( 'This property is not set on the element, so applying it would add a style that was not there before.', 'replicaforge' ) );
		}

		// 8. A low-confidence measurement is shown but never auto-applied.
		if ( $confidence < Correction_Limits::MIN_AUTO_CONFIDENCE ) {
			return $this->decide( $correction, 'requires_review', __( 'The measurement confidence for this difference is low, so review it before applying.', 'replicaforge' ) );
		}

		// 9. A difference detected in a category outside the automatic set is
		//    shown to the user but never applied automatically.
		if ( ! in_array( $category, Correction_Limits::AUTO_CATEGORIES, true ) ) {
			return $this->decide( $correction, 'requires_review', __( 'This difference is not in the automatically correctable set, so review it before applying.', 'replicaforge' ) );
		}

		$correction['level'] = $this->properties->level( $property );
		$correction['batch'] = $this->properties->batch( $property );

		return $this->decide(
			$correction,
			'safe',
			sprintf(
				/* translators: 1: Property label, 2: Correction level. */
				__( '%1$s is a whitelisted property with a measured value, so it can be corrected at level %2$d.', 'replicaforge' ),
				$this->properties->label( $property ),
				(int) $correction['level']
			)
		);
	}

	/**
	 * Return the correction level a classification belongs to.
	 *
	 * @param array<string, mixed> $correction Classified correction.
	 * @return int
	 */
	public function level( array $correction ) {
		return isset( $correction['level'] ) ? (int) $correction['level'] : 0;
	}

	/**
	 * Return the highest level present in a list of corrections.
	 *
	 * @param array<int, array<string, mixed>> $corrections Corrections.
	 * @return int
	 */
	public function highest_level( array $corrections ) {
		$highest = 0;
		foreach ( $corrections as $correction ) {
			if ( isset( $correction['level'] ) && (int) $correction['level'] > $highest ) {
				$highest = (int) $correction['level'];
			}
		}
		return $highest;
	}

	/**
	 * Finalize a classification.
	 *
	 * @param array<string, mixed> $correction  Correction.
	 * @param string               $eligibility Eligibility outcome.
	 * @param string               $reason      Human-readable reason.
	 * @return array<string, mixed>
	 */
	private function decide( array $correction, $eligibility, $reason ) {
		if ( ! in_array( $eligibility, Correction_Limits::ELIGIBILITY, true ) ) {
			// A classification outside the controlled vocabulary is treated as
			// needing review, so an unexpected outcome can never widen the write
			// surface.
			$eligibility = 'requires_review';
			$reason      = __( 'This correction could not be classified into a known outcome, so it requires review.', 'replicaforge' );
		}
		$correction['eligibility'] = $eligibility;
		$correction['reason']      = (string) $reason;
		$correction['auto']        = 'safe' === $eligibility && in_array( (string) $correction['action'], Correction_Limits::AUTO_ACTIONS, true );
		if ( ! isset( $correction['level'] ) ) {
			$correction['level'] = 'blocked' === $eligibility ? 0 : $this->properties->level( (string) $correction['property'] );
		}
		if ( ! isset( $correction['batch'] ) ) {
			$correction['batch'] = $this->properties->batch( (string) $correction['property'] );
		}
		return $correction;
	}

	/**
	 * Return the reason a property is on the never-correct list.
	 *
	 * @param string $property Comparison property.
	 * @return string
	 */
	private function blocked_reason( $property ) {
		$reasons = array(
			'section_present'    => __( 'Adding or removing a section changes the page structure. Review it and rebuild it from the specification if you want it.', 'replicaforge' ),
			'section_order'      => __( 'Section order can be changed, but only with explicit approval because it changes the page meaning, not just a style.', 'replicaforge' ),
			'component_present'  => __( 'Adding or removing a component changes the page structure and is not applied automatically.', 'replicaforge' ),
			'text'               => __( 'ReplicaForge never rewrites text automatically. Compare the two texts and edit the one you want in Elementor.', 'replicaforge' ),
			'image_count'        => __( 'An image count difference is reported but is never corrected automatically.', 'replicaforge' ),
			'image_present'      => __( 'An image replacement changes the media library and is never applied automatically.', 'replicaforge' ),
			'aspect_ratio'       => __( 'An aspect ratio difference needs the original asset, so ReplicaForge reports it instead of resizing blindly.', 'replicaforge' ),
			'column_count'       => __( 'A column count change alters the layout structure. Adjust it in Elementor or regenerate the section.', 'replicaforge' ),
			'column_progression' => __( 'A responsive progression change affects every device at once and is never applied automatically.', 'replicaforge' ),
			'mobile_navigation'  => __( 'ReplicaForge does not reproduce menu interaction, so this cannot be corrected automatically.', 'replicaforge' ),
			'rendered_comparison' => __( 'A rendered comparison is an observation about pixels, not a property, so it cannot be applied as a correction.', 'replicaforge' ),
			'rendered_difference' => __( 'A rendered difference is an observation about pixels, not a property, so it cannot be applied as a correction.', 'replicaforge' ),
		);
		return isset( $reasons[ $property ] ) ? $reasons[ $property ] : __( 'This property is not corrected automatically.', 'replicaforge' );
	}

	/**
	 * Return the reason a structural action needs approval.
	 *
	 * @param string $action   Correction action.
	 * @param string $property Comparison property.
	 * @return string
	 */
	private function structural_reason( $action, $property ) {
		$reasons = array(
			'insert'        => __( 'A missing section is proposed here and can be inserted only after you approve it. The section is rebuilt from the reconstruction specification, not authored by the correction engine.', 'replicaforge' ),
			'remove'        => __( 'An extra section is never deleted automatically. Approve the removal to delete it.', 'replicaforge' ),
			'reorder'       => __( 'Section order is never changed automatically. Approve the new order to apply it.', 'replicaforge' ),
			'replace'       => __( 'Replacing a component rebuilds it from the specification and is never automatic.', 'replicaforge' ),
			'asset_update'  => __( 'An asset change may copy a file from the source website into the media library, so it is never automatic.', 'replicaforge' ),
			'content_update' => __( 'ReplicaForge never rewrites content automatically.', 'replicaforge' ),
		);
		return isset( $reasons[ $action ] ) ? $reasons[ $action ] : __( 'This change alters the document structure and needs explicit approval.', 'replicaforge' );
	}

	/**
	 * Return the reason a property has no writable control.
	 *
	 * @param string $property Comparison property.
	 * @return string
	 */
	private function unwritable_reason( $property ) {
		unset( $property );
		return __( 'This property has no ReplicaForge-approved Elementor control, so it is reported but never written.', 'replicaforge' );
	}
}
