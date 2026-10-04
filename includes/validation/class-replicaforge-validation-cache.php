<?php
/**
 * Validation cache for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Reuses a previous validation when nothing that affects it has changed.
 *
 * The cache key covers the source hash, the generated document hash, the engine
 * version, the schema version, and the viewport configuration. Any change to the
 * analysis, to the Elementor document, to the validation engine, or to the
 * comparison viewports produces a new key and therefore a fresh validation.
 */
final class Validation_Cache {

	/**
	 * Bounded repository for validation summaries.
	 *
	 * @var Elementor_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Elementor_Repository|null $repository Optional Phase 4 repository.
	 */
	public function __construct( $repository = null ) {
		$this->repository = $repository instanceof Elementor_Repository ? $repository : new Elementor_Repository();
	}

	/**
	 * Build a deterministic cache key.
	 *
	 * @param array<string, mixed> $inputs Cache inputs.
	 * @return string
	 */
	public function key( array $inputs ) {
		$payload = array(
			'source_hash'    => isset( $inputs['source_hash'] ) ? (string) $inputs['source_hash'] : '',
			'generated_hash' => isset( $inputs['generated_hash'] ) ? (string) $inputs['generated_hash'] : '',
			'engine_version' => Validation_Limits::ENGINE_VERSION,
			'schema_version' => Validation_Limits::SCHEMA_VERSION,
			'phase'          => Validation_Limits::PHASE,
			'viewports'      => isset( $inputs['viewports'] ) && is_array( $inputs['viewports'] ) ? $inputs['viewports'] : array(),
			'visual'         => ! empty( $inputs['visual'] ),
			'tolerances'     => Validation_Limits::TOLERANCES,
			'category_weights' => Validation_Limits::CATEGORY_WEIGHTS,
		);
		$encoded = wp_json_encode( $payload );
		return 'v_' . substr( hash( 'sha256', is_string( $encoded ) ? $encoded : serialize( $payload ) ), 0, 40 );
	}

	/**
	 * Return a stored result.
	 *
	 * @param string $key Cache key.
	 * @return array<string, mixed>|null
	 */
	public function get( $key ) {
		if ( ! is_string( $key ) || ! preg_match( '/^v_[a-f0-9]{40}$/', $key ) ) {
			return null;
		}
		$stored = get_transient( Validation_Limits::RESULT_PREFIX . $key );
		if ( ! is_array( $stored ) || empty( $stored['result'] ) || ! is_array( $stored['result'] ) ) {
			return null;
		}
		$stored['cache'] = 'hit';
		return $stored;
	}

	/**
	 * Store a result.
	 *
	 * @param string               $key    Cache key.
	 * @param array<string, mixed> $result Validation result.
	 * @return void
	 */
	public function set( $key, array $result ) {
		if ( ! is_string( $key ) || ! preg_match( '/^v_[a-f0-9]{40}$/', $key ) ) {
			return;
		}
		$encoded = wp_json_encode( $result );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 4194304 ) {
			return;
		}
		set_transient(
			Validation_Limits::RESULT_PREFIX . $key,
			array(
				'result'     => $result,
				'stored_at'  => gmdate( 'c' ),
			),
			Validation_Limits::RESULT_TTL
		);
	}

	/**
	 * Record a validation summary.
	 *
	 * @param array<string, mixed> $record Summary.
	 * @return void
	 */
	public function record( array $record ) {
		$summary = array(
			'validation_id'       => isset( $record['validation_id'] ) ? (string) $record['validation_id'] : '',
			'generation_id'       => isset( $record['generation_id'] ) ? (string) $record['generation_id'] : '',
			'validation_status'   => isset( $record['status'] ) ? (string) $record['status'] : 'unknown',
			'source_url'          => isset( $record['source']['url'] ) ? (string) $record['source']['url'] : '',
			'source_hash'         => isset( $record['source_hash'] ) ? (string) $record['source_hash'] : '',
			'generated_hash'      => isset( $record['generated_hash'] ) ? (string) $record['generated_hash'] : '',
			'schema_version'      => Validation_Limits::SCHEMA_VERSION,
			'engine_version'      => Validation_Limits::ENGINE_VERSION,
			'draft_post_id'       => isset( $record['generated']['draft_id'] ) ? (int) $record['generated']['draft_id'] : 0,
			'overall'             => isset( $record['metrics']['overall']['value'] ) ? (float) $record['metrics']['overall']['value'] : 0,
			'difference_count'    => isset( $record['counts']['differences'] ) ? (int) $record['counts']['differences'] : 0,
			'warning_count'       => isset( $record['counts']['warnings'] ) ? (int) $record['counts']['warnings'] : 0,
			'completed_at'        => gmdate( 'c' ),
		);

		/*
		 * Write the summary to the store `recent()` actually reads.
		 *
		 * This method used to call `Elementor_Repository::record()`, which writes to
		 * `replicaforge_generations` - the Phase 4 *generation* ring. `recent()` reads
		 * `Validation_Limits::OPTION` (`replicaforge_validations`), which nothing in the
		 * plugin ever wrote. So `recent()` always returned an empty array, `get_by_id()`
		 * always returned null, and `Job_Runner::stage_correct()` therefore always took its
		 * "no validation result available" branch: automatic correction through the job queue
		 * never ran, on any site, ever.
		 *
		 * It also filled the generation ring with validation summaries, evicting real
		 * generation records from a 25-entry bound.
		 *
		 * The two stores now agree. `Validation_Limits::OPTION` is the owner here.
		 */
		$summaries = get_option( Validation_Limits::OPTION, array() );

		if ( ! is_array( $summaries ) ) {
			$summaries = array();
		}

		// Newest first, matching how `recent()` reads it.
		array_unshift( $summaries, $summary );

		if ( count( $summaries ) > (int) Validation_Limits::MAX_SUMMARIES ) {
			$summaries = array_slice( $summaries, 0, (int) Validation_Limits::MAX_SUMMARIES );
		}

		update_option( Validation_Limits::OPTION, $summaries, false );
	}

	/**
	 * Return recent validation summaries.
	 *
	 * @param int $limit Maximum records.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( $limit = 10 ) {
		$records = get_option( Validation_Limits::OPTION, array() );
		if ( ! is_array( $records ) ) {
			return array();
		}
		return array_slice( array_values( array_filter( $records, 'is_array' ) ), 0, max( 1, absint( $limit ) ) );
	}

	/**
	 * Return a stored result by validation identifier.
	 *
	 * @param string $validation_id Validation identifier.
	 * @return array<string, mixed>|null
	 */
	public function get_by_id( $validation_id ) {
		if ( ! is_string( $validation_id ) || ! preg_match( '/^val_[a-f0-9]{24}$/', $validation_id ) ) {
			return null;
		}
		foreach ( $this->recent( Validation_Limits::MAX_SUMMARIES ) as $record ) {
			if ( isset( $record['validation_id'] ) && $record['validation_id'] === $validation_id ) {
				$cache_key = isset( $record['source_hash'], $record['generated_hash'] )
					? $this->key(
						array(
							'source_hash'    => (string) $record['source_hash'],
							'generated_hash' => (string) $record['generated_hash'],
						)
					)
					: '';
				$stored = '' !== $cache_key ? $this->get( $cache_key ) : null;
				if ( is_array( $stored ) && isset( $stored['result'] ) && (string) $stored['result']['validation_id'] === $validation_id ) {
					return $stored;
				}
			}
		}
		return null;
	}
}
