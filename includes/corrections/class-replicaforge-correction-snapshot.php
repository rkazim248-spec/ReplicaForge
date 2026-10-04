<?php
/**
 * Correction snapshots and the manual-edit baseline for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and restores document snapshots, and tracks the manual-edit baseline.
 *
 * A snapshot is the exact `_elementor_data` string of a draft before a batch is
 * applied, plus the generation and validation hashes at that moment. Restoring a
 * snapshot writes that exact string back, so a rollback restores the previous
 * state rather than an approximation of it.
 *
 * The baseline is the separate concern of protecting user work. It records the
 * value ReplicaForge last wrote for every whitelisted property. When the current
 * document value differs from that recorded value, a human changed it after
 * generation, and a correction to the same property requires review instead of
 * overwriting the edit.
 */
final class Correction_Snapshot {

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
	 * Read the current stored document of a draft.
	 *
	 * @param int $post_id Draft post identifier.
	 * @return string Empty string when the document cannot be read.
	 */
	public function read_document( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return '';
		}
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Read the current stored responsive overrides of a draft.
	 *
	 * A device correction changes this map as well as the document, so a snapshot
	 * that recorded only `_elementor_data` would restore the document while leaving
	 * a device override behind, which is not the state the user was looking at.
	 *
	 * @param int $post_id Draft post identifier.
	 * @return string Empty string when there are none.
	 */
	public function read_responsive( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return '';
		}
		$raw = get_post_meta( $post_id, '_elementor_responsive', true );
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Create a snapshot of a draft.
	 *
	 * @param int    $post_id        Draft post identifier.
	 * @param string $validation_id  Validation the corrections come from.
	 * @param array<string, mixed>  $meta       Bounded metadata.
	 * @return array<string, mixed> Snapshot record, or an error.
	 */
	public function create( $post_id, $validation_id = '', array $meta = array() ) {
		$post_id = absint( $post_id );
		$document = $this->read_document( $post_id );
		if ( '' === $document ) {
			return $this->error( 'snapshot_document_unavailable', __( 'The draft document could not be read, so no snapshot was created.', 'replicaforge' ) );
		}

		$id = 'snap_' . substr( hash( 'sha256', $document . '|' . $validation_id . '|' . microtime( true ) ), 0, 20 );

		$snapshot = array(
			'snapshot_id'        => $id,
			'post_id'            => $post_id,
			'validation_id'      => is_string( $validation_id ) ? $validation_id : '',
			'document_hash'      => hash( 'sha256', $document ),
			'document'           => $document,
			'responsive'         => $this->read_responsive( $post_id ),
			'generation_hash'    => (string) get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'generation_hash', true ),
			'correction_state'   => $this->read_state( $post_id ),
			'baseline_state'     => $this->read_baseline( $post_id ),
			'bytes'              => strlen( $document ),
			'correction_count'   => isset( $meta['correction_count'] ) ? absint( $meta['correction_count'] ) : 0,
			'created_by'         => get_current_user_id(),
			'created_at'         => gmdate( 'c' ),
		);

		$snapshots = $this->snapshots( $post_id );
		array_unshift( $snapshots, $this->bound( $snapshot ) );
		$this->store_snapshots( $post_id, array_slice( $snapshots, 0, Correction_Limits::MAX_SNAPSHOTS ) );

		// A snapshot must be restorable, so it is kept in a bounded transient keyed
		// by the snapshot id, while the post meta holds only the summary list.
		set_transient( Correction_Limits::PLAN_PREFIX . $id, $snapshot, Correction_Limits::PLAN_TTL );

		return array(
			'success'     => true,
			'snapshot_id' => $id,
			'document_hash' => $snapshot['document_hash'],
			'bytes'       => $snapshot['bytes'],
			'created_at'  => $snapshot['created_at'],
		);
	}

	/**
	 * Return the snapshots stored for a draft.
	 *
	 * @param int $post_id Draft post identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function snapshots( $post_id ) {
		$stored = get_post_meta( absint( $post_id ), Correction_Limits::META_PREFIX . 'snapshots', true );
		if ( is_array( $stored ) ) {
			return array_values( array_filter( $stored, 'is_array' ) );
		}
		// Phase 4 stored a single hash; migrate it into the list so a draft that
		// predates snapshots still has a documented generation marker.
		$hash = (string) get_post_meta( absint( $post_id ), Elementor_Limits::META_PREFIX . 'generation_hash', true );
		if ( '' === $hash ) {
			return array();
		}
		return array(
			array(
				'snapshot_id'   => '',
				'post_id'       => absint( $post_id ),
				'document_hash' => $hash,
				'created_at'    => '',
				'legacy'        => true,
			),
		);
	}

	/**
	 * Load a full snapshot including its document.
	 *
	 * @param string $snapshot_id Snapshot identifier.
	 * @return array<string, mixed>|null
	 */
	public function load( $snapshot_id ) {
		if ( ! is_string( $snapshot_id ) || ! preg_match( '/^snap_[a-f0-9]{20}$/', $snapshot_id ) ) {
			return null;
		}
		$stored = get_transient( Correction_Limits::PLAN_PREFIX . $snapshot_id );
		if ( is_array( $stored ) && isset( $stored['document'] ) && is_string( $stored['document'] ) ) {
			return $stored;
		}
		return null;
	}

	/**
	 * Restore a snapshot onto a draft.
	 *
	 * The document is validated before it is written, so a corrupt snapshot can
	 * never replace a working document.
	 *
	 * @param int    $post_id     Draft post identifier.
	 * @param string $snapshot_id Snapshot identifier.
	 * @param bool   $through_elementor Whether to save through Elementor.
	 * @return array<string, mixed>
	 */
	public function restore( $post_id, $snapshot_id, $through_elementor = true ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return $this->error( 'snapshot_post_invalid', __( 'The draft could not be identified.', 'replicaforge' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'snapshot_forbidden', __( 'You do not have permission to restore this draft.', 'replicaforge' ) );
		}

		$snapshot = $this->load( $snapshot_id );
		if ( null === $snapshot ) {
			return $this->error( 'snapshot_not_found', __( 'The snapshot is no longer available. ReplicaForge keeps snapshots for a limited time.', 'replicaforge' ) );
		}
		if ( (int) $snapshot['post_id'] !== $post_id ) {
			return $this->error( 'snapshot_post_mismatch', __( 'The snapshot belongs to a different draft.', 'replicaforge' ) );
		}

		$decoded = json_decode( (string) $snapshot['document'], true );
		$validator = new Elementor_Validator();
		$check    = $validator->validate_document( is_array( $decoded ) ? $decoded : array() );
		if ( empty( $check['valid'] ) ) {
			return $this->error( 'snapshot_document_invalid', __( 'The stored snapshot no longer passes document validation, so nothing was restored.', 'replicaforge' ) );
		}

		$writer = new Elementor_Document_Writer( $validator );
		$stored_responsive = isset( $snapshot['responsive'] ) && is_string( $snapshot['responsive'] ) ? $snapshot['responsive'] : '';
		$restored_responsive = array();
		if ( '' !== $stored_responsive ) {
			$decoded_responsive = json_decode( $stored_responsive, true );
			if ( is_array( $decoded_responsive ) ) {
				$restored_responsive = $decoded_responsive;
			}
		}
		$saved  = $writer->save( $post_id, is_array( $decoded ) ? $decoded : array(), $restored_responsive );
		if ( empty( $saved['success'] ) ) {
			return $this->error( 'snapshot_restore_failed', __( 'The previous document could not be written back to Elementor.', 'replicaforge' ) );
		}

		// The map is written back exactly as it was, including the empty case, so a
		// rollback also removes an override the batch added.
		if ( '' === $stored_responsive ) {
			delete_post_meta( $post_id, '_elementor_responsive' );
		}

		$this->write_state( $post_id, $snapshot['correction_state'] );
		$this->write_baseline( $post_id, $snapshot['baseline_state'] );

		Security::log_event(
			'rollback_completed',
			array(
				'code'   => 'snapshot_restored',
				'reason' => 'correction',
			)
		);

		return array(
			'success'      => true,
			'snapshot_id'  => $snapshot_id,
			'document_hash' => (string) $snapshot['document_hash'],
		);
	}

	/**
	 * Return the current value of one whitelisted property.
	 *
	 * @param Elementor_Document_Reader $reader     Loaded reader.
	 * @param string                    $element_id Element identifier.
	 * @param string                    $property   Comparison property.
	 * @param string                    $device     Device key.
	 * @return mixed Null when the element does not set the property.
	 */
	public function current_value( Elementor_Document_Reader $reader, $element_id, $property, $device ) {
		$element = $reader->element( $element_id );
		if ( null === $element ) {
			return null;
		}
		// The index record names the type `el_type`, because the index holds
		// descriptive fields only.
		$el_type = isset( $element['el_type'] ) ? (string) $element['el_type'] : '';
		$control = $this->properties->control( $property, $el_type, $device );
		if ( '' === $control ) {
			return null;
		}
		return $reader->control_value( $element_id, $control );
	}

	/**
	 * Return the stored baseline of one property.
	 *
	 * @param int    $post_id    Draft post identifier.
	 * @param string $element_id Element identifier.
	 * @param string $property   Comparison property.
	 * @param string $device     Device key.
	 * @return array{value: mixed, recorded: bool}
	 */
	public function baseline_value( $post_id, $element_id, $property, $device ) {
		$state = $this->read_state( $post_id );
		$key   = $this->state_key( $element_id, $property, $device );
		return array(
			'value'    => isset( $state[ $key ] ) ? $state[ $key ] : null,
			'recorded' => isset( $state[ $key ] ),
		);
	}

	/**
	 * Return whether a property was changed by a human after ReplicaForge wrote it.
	 *
	 * A property is considered manually modified when ReplicaForge recorded a
	 * value for it and the document no longer holds that value. A property Replica
	 * Forge has never written is not a conflict; it is simply unknown.
	 *
	 * @param Elementor_Document_Reader $reader     Loaded reader.
	 * @param int                       $post_id    Draft post identifier.
	 * @param string                    $element_id Element identifier.
	 * @param string                    $property   Comparison property.
	 * @param string                    $device     Device key.
	 * @return array{modified: bool, recorded_value: mixed, current_value: mixed, has_record: bool}
	 */
	public function manual_change( Elementor_Document_Reader $reader, $post_id, $element_id, $property, $device ) {
		$baseline = $this->baseline_value( $post_id, $element_id, $property, $device );
		$current  = $this->current_value( $reader, $element_id, $property, $device );

		return array(
			'modified'       => $baseline['recorded'] && ! $this->same( $baseline['value'], $current ),
			'recorded_value' => $baseline['value'],
			'current_value'  => $current,
			'has_record'     => $baseline['recorded'],
		);
	}

	/**
	 * Record the values ReplicaForge writes for a set of properties.
	 *
	 * An existing record for the same key is only overwritten when ReplicaForge
	 * is the one writing, so a manual edit is never absorbed into the baseline.
	 *
	 * @param int                                    $post_id     Draft post identifier.
	 * @param array<int, array<string, mixed>>        $corrections Applied corrections.
	 * @param Elementor_Document_Reader|null         $reader      Reader positioned after the write.
	 * @return void
	 */
	public function record_written( $post_id, array $corrections, $reader = null ) {
		$post_id = absint( $post_id );
		$state   = $this->read_state( $post_id );
		$changed = false;

		foreach ( $corrections as $correction ) {
			if ( ! is_array( $correction ) || empty( $correction['property_key'] ) ) {
				continue;
			}
			$value = isset( $correction['written_value'] ) ? $correction['written_value'] : null;
			$key   = (string) $correction['property_key'];
			if ( array_key_exists( $key, $state ) && null === $value ) {
				continue;
			}
			$state[ $key ] = $value;
			$changed       = true;
		}

		if ( $changed ) {
			$this->write_state( $post_id, $state );
		}
	}

	/**
	 * Establish the baseline for every whitelisted property of a draft.
	 *
	 * This runs once, before the first correction is planned. A property that is
	 * already absent stays absent, so an unset property is never recorded as set.
	 *
	 * @param int                       $post_id Draft post identifier.
	 * @param Elementor_Document_Reader $reader  Loaded reader.
	 * @return int Number of properties recorded.
	 */
	public function establish_baseline( $post_id, Elementor_Document_Reader $reader ) {
		$post_id  = absint( $post_id );
		$existing = $this->read_baseline( $post_id );
		if ( ! empty( $existing ) ) {
			return count( $existing );
		}

		$baseline = array();
		foreach ( $reader->index() as $element_id => $element ) {
			if ( count( $baseline ) >= 2000 ) {
				break;
			}
			$el_type = isset( $element['el_type'] ) ? (string) $element['el_type'] : '';
			foreach ( $this->properties->all() as $property => $entry ) {
				unset( $entry );
				foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
					$control = $this->properties->control( $property, $el_type, $device );
					if ( '' === $control ) {
						continue;
					}
					$value = $reader->control_value( $element_id, $control );
					if ( null === $value ) {
						continue;
					}
					$baseline[ $this->state_key( $element_id, $property, $device ) ] = $value;
				}
			}
		}

		$this->write_baseline( $post_id, $baseline );
		return count( $baseline );
	}

	/**
	 * Return the state key for one property.
	 *
	 * @param string $element_id Element identifier.
	 * @param string $property   Comparison property.
	 * @param string $device     Device key.
	 * @return string
	 */
	public function state_key( $element_id, $property, $device ) {
		return $element_id . '|' . $property . '|' . $this->properties->device( $device );
	}

	/**
	 * Read the last-written state.
	 *
	 * @param int $post_id Draft post identifier.
	 * @return array<string, mixed>
	 */
	public function read_state( $post_id ) {
		$stored = get_post_meta( absint( $post_id ), Correction_Limits::META_PREFIX . 'state', true );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Write the last-written state.
	 *
	 * @param int                 $post_id Draft post identifier.
	 * @param array<string, mixed> $state  State map.
	 * @return void
	 */
	private function write_state( $post_id, $state ) {
		update_post_meta( absint( $post_id ), Correction_Limits::META_PREFIX . 'state', is_array( $state ) ? $state : array() );
	}

	/**
	 * Read the baseline state.
	 *
	 * @param int $post_id Draft post identifier.
	 * @return array<string, mixed>
	 */
	public function read_baseline( $post_id ) {
		$stored = get_post_meta( absint( $post_id ), Correction_Limits::META_PREFIX . 'baseline', true );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Write the baseline state.
	 *
	 * @param int                   $post_id   Draft post identifier.
	 * @param array<string, mixed> $baseline  Baseline map.
	 * @return void
	 */
	private function write_baseline( $post_id, $baseline ) {
		update_post_meta( absint( $post_id ), Correction_Limits::META_PREFIX . 'baseline', is_array( $baseline ) ? $baseline : array() );
	}

	/**
	 * Persist the bounded snapshot list.
	 *
	 * @param int                           $post_id   Draft post identifier.
	 * @param array<int, array<string, mixed>> $snapshots Snapshots.
	 * @return void
	 */
	private function store_snapshots( $post_id, array $snapshots ) {
		update_post_meta( absint( $post_id ), Correction_Limits::META_PREFIX . 'snapshots', array_values( $snapshots ) );
	}

	/**
	 * Reduce a snapshot to a summary without the document body.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return array<string, mixed>
	 */
	private function bound( array $snapshot ) {
		return array(
			'snapshot_id'      => (string) $snapshot['snapshot_id'],
			'post_id'          => (int) $snapshot['post_id'],
			'validation_id'    => (string) $snapshot['validation_id'],
			'document_hash'    => (string) $snapshot['document_hash'],
			'bytes'            => (int) $snapshot['bytes'],
			'correction_count' => (int) $snapshot['correction_count'],
			'created_by'       => (int) $snapshot['created_by'],
			'created_at'       => (string) $snapshot['created_at'],
		);
	}

	/**
	 * Compare two stored values.
	 *
	 * @param mixed $left  Left value.
	 * @param mixed $right Right value.
	 * @return bool
	 */
	private function same( $left, $right ) {
		if ( is_array( $left ) || is_array( $right ) ) {
			return wp_json_encode( $left ) === wp_json_encode( $right );
		}
		return $left === $right;
	}

	/**
	 * Build a safe error envelope.
	 *
	 * @param string $code    Error code.
	 * @param string $message User-facing message.
	 * @return array<string, mixed>
	 */
	private function error( $code, $message ) {
		return array(
			'success' => false,
			'error'   => array(
				'code'    => sanitize_key( $code ),
				'message' => (string) $message,
			),
		);
	}
}
