<?php
/**
 * Transient-backed implementation of the AI result cache contract.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps Phase 3 cache storage replaceable and does not create database tables.
 */
final class Ai_Transient_Cache implements Ai_Cache_Contract {

	/**
	 * Transient prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $prefix Optional transient prefix.
	 */
	public function __construct( $prefix = 'replicaforge_ai_' ) {
		$this->prefix = sanitize_key( $prefix );
		if ( '' === $this->prefix ) {
			$this->prefix = 'replicaforge_ai_';
		}
	}

	/**
	 * Read a cached specification.
	 *
	 * @param string $key Cache key.
	 * @return array<string, mixed>|null
	 */
	public function get( $key ) {
		$key = $this->normalize_key( $key );
		if ( '' === $key ) {
			return null;
		}
		$value = get_transient( $this->prefix . $key );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Store a specification in a bounded transient.
	 *
	 * @param string               $key           Cache key.
	 * @param array<string, mixed> $specification Specification.
	 * @return bool
	 */
	public function set( $key, array $specification ) {
		$key = $this->normalize_key( $key );
		if ( '' === $key || empty( $specification ) ) {
			return false;
		}
		return (bool) set_transient( $this->prefix . $key, $specification, Ai_Limits::CACHE_TTL );
	}

	/**
	 * Delete a cached specification.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function delete( $key ) {
		$key = $this->normalize_key( $key );
		if ( '' === $key ) {
			return false;
		}
		return (bool) delete_transient( $this->prefix . $key );
	}

	/**
	 * Keep transient names within WordPress' practical key bounds.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private function normalize_key( $key ) {
		if ( ! is_string( $key ) || '' === trim( $key ) ) {
			return '';
		}
		$key = preg_replace( '/[^a-f0-9]/', '', strtolower( $key ) );
		return is_string( $key ) ? substr( $key, 0, 40 ) : '';
	}
}
