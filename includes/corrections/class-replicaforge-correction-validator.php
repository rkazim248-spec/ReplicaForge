<?php
/**
 * Correction plan validation for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The last gate before a correction can touch an Elementor document.
 *
 * The planner classifies; this class refuses. It re-derives every value through
 * the whitelist, confirms the target element really exists in the document the
 * request will save, confirms the action is permitted for the selected
 * corrections, and confirms nothing executable can reach the writer. A request
 * therefore cannot smuggle a control name, an element identifier, or a value past
 * the plan simply by putting it in the request body.
 */
final class Correction_Validator {

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
	 * Validate a plan against the document it will modify.
	 *
	 * @param array<string, mixed>      $plan     Correction plan.
	 * @param Elementor_Document_Reader $reader   Loaded reader.
	 * @param array<int, string>        $selected Correction identifiers the user approved.
	 * @return array<string, mixed>
	 */
	public function validate( array $plan, Elementor_Document_Reader $reader, array $selected ) {
		$errors   = array();
		$approved = array();
		$refused  = array();

		$available = array();
		foreach ( $plan['corrections'] as $correction ) {
			if ( is_array( $correction ) && isset( $correction['correction_id'] ) ) {
				$available[ (string) $correction['correction_id'] ] = $correction;
			}
		}

		$selected = array_values( array_unique( array_filter( array_map( 'strval', $selected ) ) ) );
		if ( count( $selected ) > Correction_Limits::MAX_APPLY ) {
			$errors[] = 'too_many_corrections_selected';
		}
		if ( empty( $selected ) ) {
			$errors[] = 'no_corrections_selected';
		}

		foreach ( $selected as $correction_id ) {
			if ( ! isset( $available[ $correction_id ] ) ) {
				$refused[] = array(
					'correction_id' => $correction_id,
					'reason'        => 'correction_not_in_plan',
				);
				continue;
			}

			$check = $this->check( $available[ $correction_id ], $reader );
			if ( empty( $check['valid'] ) ) {
				$refused[] = array(
					'correction_id' => $correction_id,
					'reason'        => (string) $check['reason'],
				);
				continue;
			}

			$approved[ $correction_id ] = $check['correction'];
		}

		if ( empty( $approved ) ) {
			$errors[] = 'no_valid_corrections';
		}

		return array(
			'valid'       => empty( $errors ),
			'errors'      => $errors,
			'approved'    => $approved,
			'refused'     => $refused,
			'approved_count' => count( $approved ),
		);
	}

	/**
	 * Validate one correction.
	 *
	 * @param array<string, mixed>      $correction Correction.
	 * @param Elementor_Document_Reader $reader     Loaded reader.
	 * @return array{valid: bool, reason: string, correction: array<string, mixed>}
	 */
	public function check( array $correction, Elementor_Document_Reader $reader ) {
		$action   = isset( $correction['action'] ) ? (string) $correction['action'] : '';
		$property = isset( $correction['property'] ) ? (string) $correction['property'] : '';
		$device   = $this->properties->device( isset( $correction['viewport'] ) ? $correction['viewport'] : 'desktop' );

		if ( ! in_array( $action, Correction_Limits::ACTIONS, true ) ) {
			return $this->refuse( 'action_not_permitted', $correction );
		}

		// A structural action may only be applied when the request explicitly
		// selected it. The request marks this with `approved_structural`.
		if ( in_array( $action, Correction_Limits::STRUCTURAL_ACTIONS, true ) && empty( $correction['approved_structural'] ) ) {
			return $this->refuse( 'structural_action_not_approved', $correction );
		}

		if ( 'blocked' === (string) ( $correction['eligibility'] ?? '' ) ) {
			return $this->refuse( 'correction_is_blocked', $correction );
		}

		if ( in_array( $action, Correction_Limits::STRUCTURAL_ACTIONS, true ) ) {
			return $this->check_structural( $correction, $reader );
		}

		$element_id = isset( $correction['target']['elementor_element_id'] ) ? strtolower( (string) $correction['target']['elementor_element_id'] ) : '';
		if ( '' === $element_id ) {
			return $this->refuse( 'target_element_missing', $correction );
		}

		$element = $reader->element( $element_id );
		if ( null === $element ) {
			return $this->refuse( 'target_element_missing', $correction );
		}

		// The reader returns an index record, which names the type `el_type`. Reading
		// `elType` here would leave the type empty and refuse every property
		// correction, which is a silent refusal rather than a safe one.
		$el_type = isset( $element['el_type'] ) ? (string) $element['el_type'] : '';
		if ( ! in_array( $el_type, Correction_Limits::TARGET_EL_TYPES, true ) ) {
			return $this->refuse( 'target_element_type_refused', $correction );
		}

		$control = $this->properties->control( $property, $el_type, $device );
		if ( '' === $control ) {
			return $this->refuse( 'control_not_valid_for_element', $correction );
		}
		if ( $this->forbidden_control( $control ) ) {
			return $this->refuse( 'control_forbidden', $correction );
		}

		$current = $reader->control_value( $element_id, $control );
		if ( null === $current ) {
			return $this->refuse( 'target_property_not_present', $correction );
		}

		$coerced = $this->properties->coerce( $property, isset( $correction['value'] ) ? $correction['value'] : null );
		if ( null === $coerced ) {
			return $this->refuse( 'value_not_acceptable', $correction );
		}
		if ( $this->executable( $coerced ) ) {
			return $this->refuse( 'value_contains_executable_content', $correction );
		}

		$companion = $this->properties->companion( $property );
		if ( null !== $companion ) {
			$requirement = $this->properties->requirement( $property );
			if ( 'boxed_content_width' === $requirement ) {
				$companion_value = $reader->control_value( $element_id, $companion['control'] );
				if ( $companion_value !== $companion['value'] ) {
					return $this->refuse( 'companion_control_not_satisfied', $correction );
				}
			}
		}

		$correction['control'] = $control;
		$correction['value']  = $coerced;

		return array(
			'valid'       => true,
			'reason'      => '',
			'correction'  => $correction,
		);
	}

	/**
	 * Validate one structural correction.
	 *
	 * @param array<string, mixed>      $correction Correction.
	 * @param Elementor_Document_Reader $reader     Loaded reader.
	 * @return array{valid: bool, reason: string, correction: array<string, mixed>}
	 */
	private function check_structural( array $correction, Elementor_Document_Reader $reader ) {
		$action = (string) $correction['action'];

		if ( 'reorder' === $action ) {
			$order = isset( $correction['value']['order'] ) ? $correction['value']['order'] : null;
			if ( ! is_array( $order ) || count( $order ) !== count( $reader->top_level() ) ) {
				return $this->refuse( 'reorder_incomplete', $correction );
			}
			$known = $reader->top_level();
			foreach ( $order as $element_id ) {
				if ( ! in_array( strtolower( (string) $element_id ), $known, true ) ) {
					return $this->refuse( 'reorder_unknown_element', $correction );
				}
			}
			$correction['value'] = array( 'order' => array_map( 'strval', $order ) );
			return array(
				'valid'      => true,
				'reason'     => '',
				'correction' => $correction,
			);
		}

		if ( 'remove' === $action ) {
			$element_id = strtolower( (string) ( $correction['target']['elementor_element_id'] ?? '' ) );
			if ( ! in_array( $element_id, $reader->top_level(), true ) ) {
				return $this->refuse( 'remove_target_not_top_level', $correction );
			}
			if ( count( $reader->top_level() ) < 2 ) {
				return $this->refuse( 'remove_would_empty_document', $correction );
			}
			$correction['value'] = 'absent';
			return array(
				'valid'      => true,
				'reason'     => '',
				'correction' => $correction,
			);
		}

		if ( 'insert' === $action ) {
			$section_id = (string) ( $correction['target']['source_section_id'] ?? '' );
			if ( '' === $section_id ) {
				return $this->refuse( 'insert_section_unknown', $correction );
			}
			if ( empty( $correction['specification']['plan'] ) ) {
				return $this->refuse( 'insert_specification_unavailable', $correction );
			}
			$plan = $correction['specification']['plan'];
			$position = isset( $correction['value']['position'] ) ? (int) $correction['value']['position'] : 0;
			if ( $position < 0 || $position > count( $reader->top_level() ) ) {
				return $this->refuse( 'insert_position_invalid', $correction );
			}
			$correction['value'] = array(
				'position'   => $position,
				'section_id' => $section_id,
			);
			unset( $plan );
			return array(
				'valid'      => true,
				'reason'     => '',
				'correction' => $correction,
			);
		}

		return $this->refuse( 'structural_action_not_implemented', $correction );
	}

	/**
	 * Return whether a control key is on the never-written list.
	 *
	 * @param string $control Control key.
	 * @return bool
	 */
	private function forbidden_control( $control ) {
		if ( ! is_string( $control ) || '' === $control ) {
			return true;
		}
		foreach ( Correction_Limits::FORBIDDEN_CONTROLS as $forbidden ) {
			$pattern = '/^' . str_replace( '\\*', '.*', preg_quote( $forbidden, '/' ) ) . '/';
			if ( preg_match( $pattern, $control ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return whether a coerced value contains anything executable.
	 *
	 * @param mixed $value Coerced value.
	 * @return bool
	 */
	private function executable( $value ) {
		if ( is_string( $value ) ) {
			return Elementor_Values::is_executable( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( is_string( $item ) ) {
					if ( Elementor_Values::is_executable( $item ) ) {
						return true;
					}
					continue;
				}
				if ( $this->executable( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Build a refusal outcome.
	 *
	 * @param string               $reason     Reason code.
	 * @param array<string, mixed> $correction Correction.
	 * @return array{valid: bool, reason: string, correction: array<string, mixed>}
	 */
	private function refuse( $reason, array $correction ) {
		unset( $correction );
		return array(
			'valid'      => false,
			'reason'     => (string) $reason,
			'correction' => array(),
		);
	}
}
