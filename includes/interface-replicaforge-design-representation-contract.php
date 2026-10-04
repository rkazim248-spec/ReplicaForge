<?php
/**
 * Contract for the Phase 2 design representation.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Future phases can consume this contract without parsing raw HTML again.
 */
interface Design_Representation_Contract {

	/**
	 * Return the validated representation array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array();

	/**
	 * Validate the representation structure and references.
	 *
	 * @return bool
	 */
	public function is_valid();

	/**
	 * Return validation errors without exposing internal exceptions.
	 *
	 * @return array<int, string>
	 */
	public function get_validation_errors();
}
