<?php
/**
 * Phase 2 design representation container.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the stable contract between deterministic analysis and future phases.
 */
final class Design_Representation implements Design_Representation_Contract {

	/**
	 * Representation data.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Original data used for validation before shape defaults are applied.
	 *
	 * @var array<string, mixed>
	 */
	private $validation_data;

	/**
	 * Validator.
	 *
	 * @var Representation_Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>      $data      Representation data.
	 * @param Representation_Validator|null $validator Validator.
	 */
	public function __construct( $data = array(), $validator = null ) {
		$this->validation_data = is_array( $data ) ? $data : array();
		$this->data            = $this->normalize_shape( $this->validation_data );
		$this->validator       = $validator instanceof Representation_Validator ? $validator : new Representation_Validator();
	}

	/**
	 * Return the representation array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return $this->is_valid() ? $this->data : array();
	}

	/**
	 * Validate the representation.
	 *
	 * @return bool
	 */
	public function is_valid() {
		return $this->validator->validate( $this->validation_data );
	}

	/**
	 * Return validation errors.
	 *
	 * @return array<int, string>
	 */
	public function get_validation_errors() {
		$this->validator->validate( $this->validation_data );
		return $this->validator->get_errors();
	}

	/**
	 * Ensure top-level shape exists before validation.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<string, mixed>
	 */
	private function normalize_shape( $data ) {
		$defaults = array(
			'schema_version' => '2.0',
			'page'           => array(),
			'layout'         => array(),
			'sections'       => array(),
			'components'     => array(),
			'hierarchy'      => array(),
			'design_system'  => array(),
			'responsive'     => array(),
			'assets'         => array(),
			'confidence'     => array(),
		);
		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$data[ $key ] = $value;
			}
		}
		return $data;
	}
}
