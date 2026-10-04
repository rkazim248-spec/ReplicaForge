<?php
/**
 * Phase 17: durable, bounded storage for the artifacts a workflow produces.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Per-workflow artifact storage.
 *
 * ### Why this class had to exist
 *
 * Every stage in the pipeline needs the output of the stage before it, and a job may be
 * interrupted between any two of them. That means the intermediate results have to outlive
 * the request.
 *
 * The existing plugin has no place for them. The phase 2 representation lives in a
 * job-scoped transient that expires, a specification lives in a 24 hour transient, and the
 * `replicaforge_analysis` post meta that phases 12, 13 and 14 all read is never written by
 * anything. An orchestrator that tried to reuse one of those would have a pipeline whose
 * stages could not see each other's output after a resume.
 *
 * So this is a real store, and it is deliberately small. It holds a *bounded* number of
 * artifacts for one workflow, each with a hard size cap, and it records provenance so a
 * consumer can tell what it is holding.
 *
 * ### What it deliberately does not do
 *
 * It does not replace any phase's own storage. A specification still lives in
 * `Elementor_Repository`, a correction plan still lives in phase 6, a validation result
 * still lives in phase 5's cache. This store holds a **reference** to those, plus the small
 * intermediates that have no home anywhere else - chiefly the phase 2 representation, which
 * nothing in the plugin currently persists.
 *
 * That is the whole design: the orchestration layer supplies the missing glue without
 * becoming a second copy of every phase's storage.
 */
final class Workflow_Artifacts {

	/**
	 * The artifact kinds this store accepts.
	 *
	 * A closed list, because an open one lets a caller write anything under any key and the
	 * store stops being a contract. The kinds that are *references* are declared as such so
	 * a consumer can tell a pointer from a payload without inspecting either.
	 */
	const KINDS = array(
		'representation',      // Phase 2 output. The artifact that had no home.
		'site_specification',  // Phase 12 website specification.
		'context',             // The aggregated intelligence context (references only).
		'preflight',
		'preflight_security',
		'plan',
		'quality_gate',
		'regression',
		'content_report',
		'interaction_reference', // Phase 16 persists its own model; this is a pointer.
		'specification_reference', // Phase 4 persists specifications; this is a pointer.
		'validation_reference',   // Phase 5 persists results; this is a pointer.
		'correction_reference',   // Phase 6 persists plans; this is a pointer.
		'page_record',
	);

	/**
	 * The kinds that hold a pointer rather than a payload.
	 *
	 * A reference kind is verified to contain only scalar identifiers, which is what keeps
	 * a large dataset from being copied into a workflow record by accident.
	 */
	const REFERENCE_KINDS = array(
		'interaction_reference',
		'specification_reference',
		'validation_reference',
		'correction_reference',
	);

	/**
	 * The option prefix.
	 *
	 * @var string
	 */
	const PREFIX = 'replicaforge_workflow_artifacts_';

	/**
	 * The maximum number of artifacts per workflow.
	 *
	 * @var int
	 */
	const MAX_ARTIFACTS = 40;

	/**
	 * The maximum encoded size of a single artifact, in bytes.
	 *
	 * 512 KB. A phase 2 representation of a large page can approach this; a screenshot,
	 * a full HTML document or an AI conversation is orders of magnitude larger, and those
	 * must never land here. They belong in the media library or the phase's own cache, and
	 * this store records a reference to them.
	 *
	 * @var int
	 */
	const MAX_BYTES = 524288;

	/**
	 * The maximum encoded size of a whole workflow's artifacts, in bytes.
	 *
	 * @var int
	 */
	const MAX_TOTAL_BYTES = 2097152;

	/**
	 * The workflow id.
	 *
	 * @var string
	 */
	private $workflow_id;

	/**
	 * The decoded store, loaded once.
	 *
	 * @var array<string, array>|null
	 */
	private $store = null;

	/**
	 * Build the store for one workflow.
	 *
	 * @param string $workflow_id Workflow public id.
	 */
	public function __construct( $workflow_id ) {
		$this->workflow_id = self::sanitize_id( $workflow_id );
	}

	/**
	 * Store an artifact.
	 *
	 * @param string $key        Artifact key, unique within the workflow.
	 * @param string $kind       One of the declared kinds.
	 * @param mixed  $payload    The payload, or the reference scalars for a reference kind.
	 * @param array  $provenance Where it came from, and at what schema version.
	 * @return true|WP_Error
	 */
	public function put( $key, $kind, $payload, array $provenance = array() ) {
		$key  = self::sanitize_key( $key );
		$kind = (string) $kind;

		if ( '' === $key ) {
			return new \WP_Error( 'orchestrator_artifact_key', __( 'The artifact key is empty or invalid.', 'replicaforge' ) );
		}

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return new \WP_Error(
				'orchestrator_artifact_kind',
				sprintf(
					/* translators: %s: artifact kind. */
					__( 'The artifact kind "%s" is not one this store accepts.', 'replicaforge' ),
					$kind
				)
			);
		}

		if ( in_array( $kind, self::REFERENCE_KINDS, true ) ) {
			return $this->put_reference( $key, $kind, $payload, $provenance );
		}

		$encoded = wp_json_encode( $payload );

		if ( ! is_string( $encoded ) ) {
			return new \WP_Error(
				'orchestrator_artifact_unencodable',
				__( 'The artifact could not be encoded for storage.', 'replicaforge' )
			);
		}

		$bytes = strlen( $encoded );

		if ( $bytes > self::MAX_BYTES ) {
			/*
			 * Refused rather than truncated. A truncated representation would be consumed by
			 * the next stage as though it were complete, and the resulting draft would be
			 * missing whatever fell off the end - with nothing in the record to say so.
			 */
			return new \WP_Error(
				'orchestrator_artifact_too_large',
				sprintf(
					/* translators: 1: size in bytes, 2: the limit in bytes. */
					__( 'The artifact is %1$d bytes, above the %2$d byte limit for a single artifact. Store a reference to it instead.', 'replicaforge' ),
					$bytes,
					self::MAX_BYTES
				),
				array( 'bytes' => $bytes, 'limit' => self::MAX_BYTES )
			);
		}

		$store = $this->load();

		if ( count( $store ) >= self::MAX_ARTIFACTS && ! isset( $store[ $key ] ) ) {
			return new \WP_Error(
				'orchestrator_artifact_count',
				sprintf(
					/* translators: %d: the limit. */
					__( 'A workflow may hold at most %d artifacts.', 'replicaforge' ),
					self::MAX_ARTIFACTS
				)
			);
		}

		/*
		 * The total is computed with the key being replaced excluded, so an overwrite frees
		 * the old artifact's bytes rather than counting them against the new one. An earlier
		 * version tracked this with a running total decremented in place, which was both
		 * wrong (the decrement could not affect the value already checked) and unreadable.
		 */
		if ( $this->total_bytes( $store, $key ) + $bytes > self::MAX_TOTAL_BYTES ) {
			return new \WP_Error(
				'orchestrator_artifact_budget',
				sprintf(
					/* translators: %d: the limit in bytes. */
					__( 'A workflow may hold at most %d bytes of artifacts.', 'replicaforge' ),
					self::MAX_TOTAL_BYTES
				)
			);
		}

		$store[ $key ] = array(
			'key'            => $key,
			'kind'           => $kind,
			'reference'      => false,
			'schema_version' => $this->resolve_schema( $kind, $provenance ),
			'provenance'     => $this->clean_provenance( $provenance ),
			'stored_at'      => gmdate( 'c' ),
			'bytes'          => $bytes,
			'hash'           => hash( 'sha256', $encoded ),
			'payload'        => $payload,
		);

		return $this->save( $store );
	}

	/**
	 * Store a reference to an artifact another phase already persists.
	 *
	 * The payload is checked to contain only scalar identifiers. That check is the whole
	 * point of a reference kind: it is what stops a large dataset being copied into a
	 * workflow record "just as a reference", which is the failure the specification's
	 * "store references instead of copying large datasets" requirement is aimed at.
	 *
	 * @param string $key        Artifact key.
	 * @param string $kind       A reference kind.
	 * @param array  $payload    Scalar identifiers.
	 * @param array  $provenance Provenance.
	 * @return true|WP_Error
	 */
	private function put_reference( $key, $kind, $payload, array $provenance ) {
		$payload = (array) $payload;

		if ( empty( $payload ) ) {
			return new \WP_Error(
				'orchestrator_reference_empty',
				__( 'A reference must name at least one identifier.', 'replicaforge' )
			);
		}

		$clean = array();

		foreach ( $payload as $name => $value ) {
			$name = self::sanitize_key( $name );

			if ( '' === $name ) {
				continue;
			}

			// Scalars only. An array or an object here is a payload in disguise.
			if ( is_array( $value ) || is_object( $value ) ) {
				return new \WP_Error(
					'orchestrator_reference_not_scalar',
					sprintf(
						/* translators: %s: field name. */
						__( 'The reference field "%s" is not a scalar. Store the data in its own phase and reference its identifier.', 'replicaforge' ),
						$name
					)
				);
			}

			$clean[ $name ] = is_bool( $value ) ? $value : ( is_scalar( $value ) ? (string) $value : '' );
		}

		if ( array() === $clean ) {
			return new \WP_Error(
				'orchestrator_reference_empty',
				__( 'A reference must name at least one usable identifier.', 'replicaforge' )
			);
		}

		$store = $this->load();
		$bytes = strlen( (string) wp_json_encode( $clean ) );

		$store[ $key ] = array(
			'key'            => $key,
			'kind'           => $kind,
			'reference'      => true,
			'schema_version' => $this->resolve_schema( $kind, $provenance ),
			'provenance'     => $this->clean_provenance( $provenance ),
			'stored_at'      => gmdate( 'c' ),
			'bytes'          => $bytes,
			'hash'           => hash( 'sha256', (string) wp_json_encode( $clean ) ),
			'payload'        => $clean,
		);

		return $this->save( $store );
	}

	/**
	 * Read an artifact, validating its schema and provenance first.
	 *
	 * The validation is not ceremonial. An artifact written by an older engine version, or
	 * by a phase whose schema has since moved, is returned as an error rather than as data -
	 * consuming a 2.0 representation as though it were a 2.1 one produces a draft that is
	 * subtly wrong in ways no later stage can detect.
	 *
	 * @param string $key            Artifact key.
	 * @param string $expected_kind  The kind the caller expects, or '' for any.
	 * @return array|WP_Error
	 */
	public function get( $key, $expected_kind = '' ) {
		$key = self::sanitize_key( $key );
		$all = $this->load();

		if ( ! isset( $all[ $key ] ) ) {
			return new \WP_Error(
				'orchestrator_artifact_missing',
				__( 'The requested artifact is not stored for this workflow.', 'replicaforge' )
			);
		}

		$entry = $all[ $key ];

		if ( '' !== $expected_kind && (string) $entry['kind'] !== (string) $expected_kind ) {
			return new \WP_Error(
				'orchestrator_artifact_mismatch',
				sprintf(
					/* translators: 1: expected kind, 2: actual kind. */
					__( 'The artifact is a "%2$s" and was expected to be a "%1$s".', 'replicaforge' ),
					(string) $expected_kind,
					(string) $entry['kind']
				)
			);
		}

		$expected_schema = $this->resolve_schema( (string) $entry['kind'], (array) $entry['provenance'] );

		if ( '' !== $expected_schema && (string) $entry['schema_version'] !== $expected_schema ) {
			return new \WP_Error(
				'orchestrator_artifact_schema',
				sprintf(
					/* translators: 1: stored schema, 2: current schema. */
					__( 'The artifact was written against schema %1$s and the current schema is %2$s. Re-run the stage that produces it.', 'replicaforge' ),
					(string) $entry['schema_version'],
					$expected_schema
				)
			);
		}

		// The hash is re-checked on read. It catches a partial write and, more usefully,
		// makes a hand-edited option detectable rather than silent.
		$reencoded = wp_json_encode( $entry['payload'] );

		if ( ! is_string( $reencoded ) || ! hash_equals( (string) $entry['hash'], hash( 'sha256', $reencoded ) ) ) {
			return new \WP_Error(
				'orchestrator_artifact_corrupt',
				__( 'The stored artifact does not match its recorded hash and cannot be trusted.', 'replicaforge' )
			);
		}

		return $entry;
	}

	/**
	 * Return the identifiers of every stored artifact, without their payloads.
	 *
	 * This is what a report, a job record or a REST response carries. It is small enough to
	 * inline anywhere and it says what exists without carrying any of it.
	 *
	 * @return array<int, array>
	 */
	public function references() {
		$out = array();

		foreach ( $this->load() as $entry ) {
			$out[] = array(
				'key'            => (string) $entry['key'],
				'kind'           => (string) $entry['kind'],
				'reference'      => ! empty( $entry['reference'] ),
				'schema_version' => (string) $entry['schema_version'],
				'stored_at'      => (string) $entry['stored_at'],
				'bytes'          => (int) $entry['bytes'],
				'hash'           => (string) $entry['hash'],
			);
		}

		return $out;
	}

	/**
	 * Return whether an artifact is stored.
	 *
	 * @param string $key Artifact key.
	 * @return bool
	 */
	public function has( $key ) {
		return isset( $this->load()[ self::sanitize_key( $key ) ] );
	}

	/**
	 * Remove an artifact.
	 *
	 * @param string $key Artifact key.
	 * @return bool
	 */
	public function remove( $key ) {
		$store = $this->load();
		$key   = self::sanitize_key( $key );

		if ( ! isset( $store[ $key ] ) ) {
			return false;
		}

		unset( $store[ $key ] );

		$this->save( $store );

		return true;
	}

	/**
	 * Return the total bytes held.
	 *
	 * @return int
	 */
	public function total_size() {
		return $this->total_bytes( $this->load() );
	}

	/**
	 * Delete every artifact for a workflow.
	 *
	 * @return void
	 */
	public function purge() {
		delete_option( self::PREFIX . $this->workflow_id );
		$this->store = null;
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------ */

	/**
	 * Load the store.
	 *
	 * @return array<string, array>
	 */
	private function load() {
		if ( null !== $this->store ) {
			return $this->store;
		}

		$raw = get_option( self::PREFIX . $this->workflow_id, array() );

		$this->store = is_array( $raw ) ? $raw : array();

		return $this->store;
	}

	/**
	 * Save the store.
	 *
	 * @param array<string, array> $store The store.
	 * @return true|WP_Error
	 */
	private function save( array $store ) {
		$this->store = $store;

		/*
		 * Two obvious state bugs, both now covered by the suite: the memo has to be updated
		 * before the option is written, or a failed write leaves the object claiming to hold
		 * artifacts that are not in the database; and a brand new option returns false from
		 * both update_option and get_option, so the second call is what distinguishes "the
		 * value did not change" from "there is no value".
		 */
		$updated = update_option( self::PREFIX . $this->workflow_id, $store, false );

		if ( false === $updated && ! is_array( get_option( self::PREFIX . $this->workflow_id, null ) ) ) {
			return new \WP_Error(
				'orchestrator_artifact_save',
				__( 'The artifacts could not be stored.', 'replicaforge' )
			);
		}

		return true;
	}

	/**
	 * Sum the stored bytes, optionally excluding one key.
	 *
	 * @param array<string, array> $store The store.
	 * @param string               $skip  A key to leave out, for an in-progress replacement.
	 * @return int
	 */
	private function total_bytes( array $store, $skip = '' ) {
		$total = 0;

		foreach ( $store as $name => $entry ) {
			if ( '' !== $skip && (string) $name === (string) $skip ) {
				continue;
			}

			$total += (int) ( $entry['bytes'] ?? 0 );
		}

		return $total;
	}

	/* ---------------------------------------------------------------------
	 * Validation helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve the schema version an artifact of a kind is currently written against.
	 *
	 * The mapping is to the *producing phase's* schema, not to the orchestrator's own. An
	 * artifact's schema is a fact about its producer, and the orchestrator's schema says
	 * nothing about whether a phase 2 representation is still readable.
	 *
	 * The provenance-supplied version is used when present, because a phase that is several
	 * minor versions ahead of this table still wrote a valid artifact, and refusing it would
	 * be wrong. A provenance version is only trusted when it is a non-empty string; anything
	 * else falls back to the table.
	 *
	 * @param string $kind       Artifact kind.
	 * @param array  $provenance Provenance.
	 * @return string
	 */
	private function resolve_schema( $kind, array $provenance ) {
		$supplied = isset( $provenance['schema_version'] ) ? $provenance['schema_version'] : '';

		if ( is_string( $supplied ) && '' !== $supplied ) {
			return $supplied;
		}

		$map = array(
			'representation'         => defined( 'REPLICAFORGE_DESIGN_SCHEMA' ) ? (string) constant( 'REPLICAFORGE_DESIGN_SCHEMA' ) : '2.0',
			'site_specification'     => class_exists( 'ReplicaForge\\Site_Limits' ) ? (string) Site_Limits::SCHEMA_VERSION : '',
			'specification_reference' => class_exists( 'ReplicaForge\\Elementor_Limits' ) ? (string) Elementor_Limits::SPEC_SCHEMA_VERSION : '',
			'validation_reference'   => class_exists( 'ReplicaForge\\Validation_Limits' ) ? (string) Validation_Limits::SCHEMA_VERSION : '',
			'interaction_reference'  => class_exists( 'ReplicaForge\\Interaction_Limits' ) ? (string) Interaction_Limits::SCHEMA_VERSION : '',
			'correction_reference'   => class_exists( 'ReplicaForge\\Correction_Limits' ) ? (string) Correction_Limits::SCHEMA_VERSION : '',
		);

		return (string) ( $map[ $kind ] ?? Orchestrator_Limits::SCHEMA_VERSION );
	}

	/**
	 * Reduce provenance to the fields worth recording.
	 *
	 * A closed list again, and for the same reason as everything else in this plugin: an
	 * open metadata bag eventually accumulates a source HTML excerpt or a prompt, and then
	 * a report carries it.
	 *
	 * @param array $provenance Supplied provenance.
	 * @return array
	 */
	private function clean_provenance( array $provenance ) {
		$allowed = array( 'source', 'schema_version', 'phase', 'url', 'generator', 'engine_version' );
		$clean   = array();

		foreach ( $allowed as $field ) {
			if ( ! isset( $provenance[ $field ] ) ) {
				continue;
			}

			$value = $provenance[ $field ];

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = (string) $value;

			// A URL is kept, but only if it survives the same validation every other source
			// URL in this plugin does. An artifact must not be a way to persist a private
			// network address that validation refused at fetch time.
			if ( 'url' === $field ) {
				$verdict = ( new Url_Validator() )->validate( $value );

				if ( empty( $verdict['success'] ) ) {
					continue;
				}

				$value = (string) $verdict['url'];
			}

			$clean[ $field ] = substr( $value, 0, 300 );
		}

		return $clean;
	}

	/**
	 * Reduce a workflow id to a safe option suffix.
	 *
	 * Delegates to the one shared sanitiser, rather than keeping a second copy of the regex.
	 * See {@see Orchestrator_Limits::sanitize_workflow_id()} for what happens when the two
	 * copies disagree: the record is deleted and its artifacts are left behind forever, with
	 * nothing in the record to say so.
	 *
	 * @param string $workflow_id Raw id.
	 * @return string
	 */
	public static function sanitize_id( $workflow_id ) {
		return Orchestrator_Limits::sanitize_workflow_id( $workflow_id );
	}

	/**
	 * Reduce an artifact key to a safe array key.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private static function sanitize_key( $key ) {
		return substr( preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $key ) ), 0, 60 );
	}
}
