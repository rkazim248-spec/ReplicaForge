<?php
/**
 * Generation persistence for ReplicaForge Phase 4.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Stores bounded generation records and short-lived specifications.
 *
 * No source HTML, no credentials, and no full analysis payload is persisted. A
 * generation record exists so a future regeneration feature can reference a
 * previous run without re-analyzing the source website.
 */
final class Elementor_Repository {

	/** Option holding recent generation records. */
	const OPTION = 'replicaforge_generations';

	/** Transient prefix for cached specifications. */
	const SPEC_PREFIX = 'replicaforge_spec_';

	/**
	 * Store a validated specification for later reference.
	 *
	 * @param array<string, mixed> $specification Validated specification.
	 * @param array<string, mixed> $meta          Bounded metadata.
	 * @return string Specification identifier, or an empty string.
	 */
	public function save_specification( array $specification, array $meta = array() ) {
		$encoded = wp_json_encode( $specification );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 1048576 ) {
			return '';
		}
		$id = 'spec_' . substr( hash( 'sha256', $encoded ), 0, 32 );
		set_transient(
			self::SPEC_PREFIX . $id,
			array(
				'specification' => $specification,
				'meta'          => array(
					'schema_version' => Elementor_Limits::SPEC_SCHEMA_VERSION,
					'source_url'     => isset( $meta['source_url'] ) && is_string( $meta['source_url'] ) ? $meta['source_url'] : '',
					'stored_at'      => gmdate( 'c' ),
				),
			),
			Elementor_Limits::SPEC_TTL
		);
		return $id;
	}

	/**
	 * Return a stored specification.
	 *
	 * @param string $specification_id Specification identifier.
	 * @return array<string, mixed>|null
	 */
	public function get_specification( $specification_id ) {
		if ( ! is_string( $specification_id ) || ! preg_match( '/^spec_[a-f0-9]{32}$/', $specification_id ) ) {
			return null;
		}
		$stored = get_transient( self::SPEC_PREFIX . $specification_id );
		return is_array( $stored ) && isset( $stored['specification'] ) && is_array( $stored['specification'] )
			? $stored['specification']
			: null;
	}

	/**
	 * Append a bounded generation record.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return void
	 */
	public function record( array $record ) {
		$records = $this->recent( Elementor_Limits::MAX_GENERATION_RECORDS );
		array_unshift( $records, $this->bound_record( $record ) );
		update_option( self::OPTION, array_slice( $records, 0, Elementor_Limits::MAX_GENERATION_RECORDS ), false );
	}

	/**
	 * Return recent generation records.
	 *
	 * @param int $limit Maximum records.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( $limit = 10 ) {
		$records = get_option( self::OPTION, array() );
		if ( ! is_array( $records ) ) {
			return array();
		}
		$records = array_values( array_filter( $records, 'is_array' ) );
		return array_slice( $records, 0, max( 1, absint( $limit ) ) );
	}

	/**
	 * Reduce a record to bounded, non-sensitive fields.
	 *
	 * @param array<string, mixed> $record Raw record.
	 * @return array<string, mixed>
	 */
	private function bound_record( array $record ) {
		$warnings = isset( $record['warnings'] ) && is_array( $record['warnings'] ) ? $record['warnings'] : array();
		$errors   = isset( $record['errors'] ) && is_array( $record['errors'] ) ? $record['errors'] : array();

		return array(
			'generation_id'       => isset( $record['generation_id'] ) && is_scalar( $record['generation_id'] ) ? sanitize_text_field( (string) $record['generation_id'] ) : '',
			'source_url'          => isset( $record['source_url'] ) && is_string( $record['source_url'] ) ? $record['source_url'] : '',
			'source_hash'         => isset( $record['source_hash'] ) && is_scalar( $record['source_hash'] ) ? sanitize_text_field( (string) $record['source_hash'] ) : '',
			'reconstruction_hash' => isset( $record['reconstruction_hash'] ) && is_scalar( $record['reconstruction_hash'] ) ? sanitize_text_field( (string) $record['reconstruction_hash'] ) : '',
			'draft_post_id'       => isset( $record['draft_post_id'] ) ? absint( $record['draft_post_id'] ) : 0,
			'specification_id'    => isset( $record['specification_id'] ) && is_string( $record['specification_id'] ) ? $record['specification_id'] : '',
			'elementor_version'   => isset( $record['elementor_version'] ) && is_string( $record['elementor_version'] ) ? $record['elementor_version'] : '',
			'replicaforge_version' => defined( 'REPLICAFORGE_VERSION' ) ? REPLICAFORGE_VERSION : '',
			'generation_status'   => isset( $record['generation_status'] ) && is_string( $record['generation_status'] ) ? sanitize_key( $record['generation_status'] ) : 'unknown',
			'phase'               => Elementor_Limits::PHASE,
			'sections'            => isset( $record['sections'] ) ? absint( $record['sections'] ) : 0,
			'components'          => isset( $record['components'] ) ? absint( $record['components'] ) : 0,
			'elements'            => isset( $record['elements'] ) ? absint( $record['elements'] ) : 0,
			'warning_count'       => count( $warnings ),
			'error_count'         => count( $errors ),
			'created_by'          => get_current_user_id(),
			'created_at'          => gmdate( 'c' ),
			'completed_at'        => isset( $record['completed_at'] ) && is_string( $record['completed_at'] ) ? $record['completed_at'] : '',
		);
	}
}
