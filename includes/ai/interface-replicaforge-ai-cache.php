<?php
/**
 * AI result cache contract.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Allows storage to be replaced without changing AI orchestration.
 */
interface Ai_Cache_Contract {

	/**
	 * Read a cached reconstruction specification.
	 *
	 * @param string $key Cache key.
	 * @return array<string, mixed>|null
	 */
	public function get( $key );

	/**
	 * Store a reconstruction specification.
	 *
	 * @param string                 $key           Cache key.
	 * @param array<string, mixed>   $specification Specification.
	 * @return bool
	 */
	public function set( $key, array $specification );

	/**
	 * Delete a cached specification.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function delete( $key );
}
