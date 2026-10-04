<?php
/**
 * Phase 7: feature flags.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Controls whether an unstable or optional capability is available.
 *
 * A flag here never grants a capability the user is not entitled to, and never
 * bypasses a security check. It only turns a documented feature on or off, so a
 * problem with a new capability can be contained without shipping a downgrade.
 */
final class Feature_Flags {

	/**
	 * Option holding the overrides.
	 */
	const OPTION = 'replicaforge_feature_flags';

	/**
	 * Declared flags and their defaults.
	 *
	 * @var array<string, bool>
	 */
	const FLAGS = array(
		// The deterministic pipeline is the product. Every flag below is optional.
		'ai_enabled'                   => true,
		'visual_validation_enabled'    => true,
		'auto_correction_enabled'      => true,
		'background_jobs_enabled'      => true,
		'advanced_asset_import_enabled'=> false,
	);

	/**
	 * Overrides read from the database.
	 *
	 * @var array<string, bool>|null
	 */
	private $overrides = null;

	/**
	 * Return every flag and its effective value.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all() {
		$overrides = $this->load();
		$out       = array();
		foreach ( self::FLAGS as $name => $default ) {
			$value = array_key_exists( $name, $overrides ) ? (bool) $overrides[ $name ] : $default;
			$out[ $name ] = array(
				'enabled' => $value,
				'default' => (bool) $default,
				'overridden' => array_key_exists( $name, $overrides ),
			);
		}
		return $out;
	}

	/**
	 * Return whether a flag is on.
	 *
	 * A constant can force a flag off for a whole install, which is how a site
	 * owner can disable a capability without editing the database.
	 *
	 * @param string $name Flag name.
	 * @return bool
	 */
	public function enabled( $name ) {
		$name = $this->normalize( $name );
		if ( ! isset( self::FLAGS[ $name ] ) ) {
			return false;
		}
		$constant = 'REPLICAFORGE_DISABLE_' . strtoupper( $name );
		if ( defined( $constant ) && constant( $constant ) ) {
			return false;
		}
		$overrides = $this->load();
		return array_key_exists( $name, $overrides ) ? (bool) $overrides[ $name ] : (bool) self::FLAGS[ $name ];
	}

	/**
	 * Set an override.
	 *
	 * @param string $name    Flag name.
	 * @param bool   $enabled Desired value.
	 * @return bool False when the flag is not declared.
	 */
	public function set( $name, $enabled ) {
		$name = $this->normalize( $name );
		if ( ! isset( self::FLAGS[ $name ] ) ) {
			return false;
		}
		$overrides = $this->load();
		$overrides[ $name ] = (bool) $enabled;
		$this->save( $overrides );
		return true;
	}

	/**
	 * Remove an override, returning the flag to its default.
	 *
	 * @param string $name Flag name.
	 * @return bool
	 */
	public function reset( $name ) {
		$name = $this->normalize( $name );
		$overrides = $this->load();
		if ( ! array_key_exists( $name, $overrides ) ) {
			return false;
		}
		unset( $overrides[ $name ] );
		$this->save( $overrides );
		return true;
	}

	/**
	 * Remove every override.
	 *
	 * @return void
	 */
	public function reset_all() {
		$this->save( array() );
	}

	/**
	 * Read the stored overrides.
	 *
	 * @return array<string, bool>
	 */
	private function load() {
		if ( null !== $this->overrides ) {
			return $this->overrides;
		}
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		if ( is_array( $stored ) ) {
			foreach ( $stored as $name => $value ) {
				$name = $this->normalize( (string) $name );
				if ( '' !== $name && isset( self::FLAGS[ $name ] ) ) {
					$out[ $name ] = (bool) $value;
				}
			}
		}
		$this->overrides = $out;
		return $out;
	}

	/**
	 * Persist the overrides.
	 *
	 * @param array<string, bool> $overrides Overrides.
	 * @return void
	 */
	private function save( array $overrides ) {
		$this->overrides = $overrides;
		update_option( self::OPTION, $overrides, false );
	}

	/**
	 * Normalize a flag name.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private function normalize( $name ) {
		if ( ! is_string( $name ) ) {
			return '';
		}
		$name = strtolower( trim( $name ) );
		return preg_replace( '/[^a-z0-9_]/', '', $name );
	}
}
