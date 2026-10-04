<?php
/**
 * Elementor document writer for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The only class in ReplicaForge that modifies an existing Elementor document.
 *
 * The writer never receives a value from a browser. It receives a validated
 * correction plan, resolves each correction to a control through
 * `Correction_Property_Map`, applies it to the in-memory element tree, validates
 * the result, and saves the document exactly once.
 *
 * Saving once is deliberate. A correction batch of any size is applied to the
 * tree in memory, validated, then persisted in a single `Document::save()` call,
 * so a partial failure cannot leave half a batch written.
 */
final class Elementor_Document_Writer {

	/**
	 * Document validator.
	 *
	 * @var Elementor_Validator
	 */
	private $validator;

	/**
	 * Property whitelist.
	 *
	 * @var Correction_Property_Map
	 */
	private $properties;

	/**
	 * Mapper used to build an approved section for insertion.
	 *
	 * @var Elementor_Mapper|null
	 */
	private $mapper;

	/**
	 * Builder used to build an approved section for insertion.
	 *
	 * @var Elementor_Document_Builder|null
	 */
	private $builder;

	/**
	 * Changes applied during the current batch.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $changes = array();

	/**
	 * Responsive overrides, keyed by element id.
	 *
	 * Elementor stores a device override outside the element settings, so a device
	 * correction is written here and persisted by `save()`. Writing it into the
	 * element settings instead would produce a document the editor ignores, while
	 * still reporting the correction as applied.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $responsive = array();

	/**
	 * Constructor.
	 *
	 * @param Elementor_Validator|null          $validator  Optional document validator.
	 * @param Correction_Property_Map|null      $properties Optional property whitelist.
	 * @param Elementor_Mapper|null             $mapper     Optional mapper for section insertion.
	 * @param Elementor_Document_Builder|null   $builder    Optional builder for section insertion.
	 */
	public function __construct( $validator = null, $properties = null, $mapper = null, $builder = null ) {
		$this->validator  = $validator instanceof Elementor_Validator ? $validator : new Elementor_Validator();
		$this->properties = $properties instanceof Correction_Property_Map ? $properties : new Correction_Property_Map();
		$this->mapper     = $mapper instanceof Elementor_Mapper ? $mapper : null;
		$this->builder    = $builder instanceof Elementor_Document_Builder ? $builder : null;
	}

	/**
	 * Return the changes applied by the last batch.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function changes() {
		return $this->changes;
	}

	/**
	 * Seed the writer with the responsive overrides already on the draft.
	 *
	 * A device correction reads the current override before replacing it, so the
	 * writer has to know what is there. Without this it would assume no override
	 * exists and add one, which turns a correction into an unrelated style change.
	 *
	 * @param array<string, array<string, mixed>> $responsive Overrides by element id.
	 * @return void
	 */
	public function set_responsive( array $responsive ) {
		$this->responsive = array();
		foreach ( $responsive as $element_id => $overrides ) {
			if ( is_string( $element_id ) && is_array( $overrides ) ) {
				$this->responsive[ strtolower( $element_id ) ] = $overrides;
			}
		}
	}

	/**
	 * Return the responsive overrides the last batch produced.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function responsive() {
		return $this->responsive;
	}

	/**
	 * Apply a validated plan to a document in memory and return the new document.
	 *
	 * The document is not written here. The caller decides when to save, so it can
	 * validate and roll back as one unit.
	 *
	 * @param array<int, mixed>              $elements    Current document.
	 * @param array<int, array<string,mixed>> $corrections Approved corrections.
	 * @return array{elements: array<int, mixed>, applied: array<int, array>, failed: array<int, array>}
	 */
	public function apply( array $elements, array $corrections ) {
		$this->changes = array();
		$applied       = array();
		$failed        = array();

		// Structural corrections run first because a reorder, removal, or
		// insertion changes element identifiers and positions that the
		// property-level corrections then resolve against.
		$ordered = $this->order( $corrections );

		foreach ( $ordered as $correction ) {
			if ( ! is_array( $correction ) || ! isset( $correction['action'] ) ) {
				continue;
			}
			$action = (string) $correction['action'];
			switch ( $action ) {
				case 'update':
				case 'resize':
				case 'reposition':
				case 'responsive_update':
				case 'visibility':
					$outcome = $this->apply_property( $elements, $correction );
					break;
				case 'reorder':
					$outcome = $this->apply_reorder( $elements, $correction );
					break;
				case 'remove':
					$outcome = $this->apply_remove( $elements, $correction );
					break;
				case 'insert':
					$outcome = $this->apply_insert( $elements, $correction );
					break;
				default:
					$outcome = array(
						'success' => false,
						'reason'  => 'action_not_implemented',
					);
			}

			if ( empty( $outcome['success'] ) ) {
				// The identifying fields travel with the failure, because a history
				// record that only carries a reason cannot tell the user which
				// property on which element was refused.
				$failed[] = array(
					'correction_id' => isset( $correction['correction_id'] ) ? (string) $correction['correction_id'] : '',
					'reason'        => isset( $outcome['reason'] ) ? (string) $outcome['reason'] : 'unknown',
					'action'        => $action,
					'property'      => isset( $correction['property'] ) ? (string) $correction['property'] : '',
					'control'       => isset( $correction['control'] ) ? (string) $correction['control'] : '',
					'viewport'      => isset( $correction['viewport'] ) ? (string) $correction['viewport'] : '',
					'element_id'    => isset( $correction['target']['elementor_element_id'] ) ? (string) $correction['target']['elementor_element_id'] : '',
				);
				continue;
			}

			$applied[] = $outcome['record'];
			$this->changes[] = $outcome['record'];
		}

		return array(
			'elements'   => $elements,
			'responsive' => $this->responsive,
			'applied'    => $applied,
			'failed'     => $failed,
		);
	}

	/**
	 * Save a document to a draft.
	 *
	 * The document is validated before it is handed to Elementor and re-read after
	 * it is saved, so a silent serialization failure cannot be reported as
	 * success. Responsive overrides are merged into whatever is already stored, so
	 * a device correction does not discard overrides ReplicaForge did not write.
	 *
	 * @param int                            $post_id    Draft post identifier.
	 * @param array<int, mixed>              $elements   Document.
	 * @param array<string, array<string, mixed>> $responsive Overrides by element id.
	 * @return array<string, mixed>
	 */
	public function save( $post_id, array $elements, array $responsive = array() ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'save_forbidden', __( 'You do not have permission to change this draft.', 'replicaforge' ) );
		}

		$check = $this->validator->validate_document( $elements );
		if ( empty( $check['valid'] ) ) {
			return $this->error( 'save_document_invalid', __( 'The corrected document failed validation, so nothing was written.', 'replicaforge' ) );
		}

		$document = $this->document_for( $post_id );
		if ( ! is_object( $document ) || ! method_exists( $document, 'save' ) ) {
			return $this->error( 'save_document_unavailable', __( 'The Elementor document could not be opened, so nothing was written.', 'replicaforge' ) );
		}

		try {
			$saved = $document->save( array( 'elements' => $elements ) );
		} catch ( \Throwable $exception ) {
			Security::log_event( 'correction_failed', array( 'code' => 'document_save_exception', 'reason' => 'exception' ) );
			return $this->error( 'save_document_exception', __( 'Elementor rejected the corrected document, so nothing was written.', 'replicaforge' ) );
		}

		if ( true !== $saved ) {
			Security::log_event( 'correction_failed', array( 'code' => 'document_save_rejected', 'reason' => 'elementor' ) );
			return $this->error( 'save_document_rejected', __( 'Elementor did not accept the corrected document, so nothing was written.', 'replicaforge' ) );
		}

		$raw    = get_post_meta( $post_id, '_elementor_data', true );
		$stored = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$after  = $this->validator->validate_document( is_array( $stored ) ? $stored : array() );
		if ( empty( $after['valid'] ) ) {
			return $this->error( 'save_document_stored_invalid', __( 'The corrected document could not be verified after saving.', 'replicaforge' ) );
		}

		if ( ! empty( $responsive ) ) {
			$merged = $this->merge_responsive( $post_id, $responsive );
			if ( empty( $merged['success'] ) ) {
				return $merged;
			}
		}

		return array(
			'success'     => true,
			'element_count' => (int) $after['count'],
			'max_depth'   => (int) $after['max_depth'],
			'warnings'    => $after['warnings'],
			'document_hash' => hash( 'sha256', is_string( $raw ) ? $raw : '' ),
		);
	}

	/**
	 * Merge responsive overrides into the ones already stored on a draft.
	 *
	 * The stored map is read first and only the named elements and controls are
	 * replaced, so an override ReplicaForge did not write is never discarded. The
	 * merged map is read back and compared, because a device correction that is not
	 * stored must be reported as a failure rather than as a success.
	 *
	 * @param int                            $post_id    Draft post identifier.
	 * @param array<string, array<string, mixed>> $responsive Overrides by element id.
	 * @return array<string, mixed>
	 */
	private function merge_responsive( $post_id, array $responsive ) {
		$stored = get_post_meta( $post_id, '_elementor_responsive', true );
		$merged = array();
		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				$merged = $decoded;
			}
		}

		foreach ( $responsive as $element_id => $overrides ) {
			$element_id = strtolower( (string) $element_id );
			if ( '' === $element_id || ! is_array( $overrides ) ) {
				continue;
			}
			$existing = isset( $merged[ $element_id ] ) && is_array( $merged[ $element_id ] ) ? $merged[ $element_id ] : array();
			foreach ( $overrides as $control => $value ) {
				$existing[ $control ] = $value;
			}
			$merged[ $element_id ] = $existing;
		}

		$written = update_post_meta( $post_id, '_elementor_responsive', wp_slash( (string) wp_json_encode( $merged ) ) );
		if ( ! $written ) {
			// `update_post_meta` returns false when the value is unchanged, which is
			// not a failure, so the stored value is compared instead of the return.
			$raw = get_post_meta( $post_id, '_elementor_responsive', true );
			if ( (string) $raw !== (string) wp_json_encode( $merged ) ) {
				Security::log_event( 'correction_failed', array( 'code' => 'responsive_write_failed', 'reason' => 'meta' ) );
				return $this->error( 'responsive_write_failed', __( 'The responsive overrides could not be stored, so nothing was written.', 'replicaforge' ) );
			}
		}

		return array( 'success' => true );
	}

	/**
	 * Apply one property-level correction.
	 *
	 * @param array<int, mixed>        $elements   Document, by reference.
	 * @param array<string, mixed>     $correction Correction.
	 * @return array<string, mixed>
	 */
	private function apply_property( array &$elements, array $correction ) {
		$element_id = isset( $correction['target']['elementor_element_id'] ) ? (string) $correction['target']['elementor_element_id'] : '';
		$property   = isset( $correction['property'] ) ? (string) $correction['property'] : '';
		$device     = isset( $correction['viewport'] ) ? (string) $correction['viewport'] : 'desktop';
		$written    = isset( $correction['value'] ) ? $correction['value'] : null;

		$entry = $this->properties->get( $property );
		if ( null === $entry ) {
			return $this->failure( 'property_not_writable' );
		}

		$located = $this->locate( $elements, $element_id );
		if ( null === $located ) {
			return $this->failure( 'target_element_missing' );
		}

		$el_type = (string) $located['element']['elType'];
		if ( ! in_array( $el_type, Correction_Limits::TARGET_EL_TYPES, true ) ) {
			return $this->failure( 'target_element_type_refused' );
		}

		$control = $this->properties->control( $property, $el_type, $device );
		if ( '' === $control ) {
			return $this->failure( 'control_not_valid_for_element' );
		}

		$value = $this->properties->coerce( $property, $written );
		if ( null === $value ) {
			return $this->failure( 'value_not_acceptable' );
		}

		// A device override lives in the responsive map, which Elementor stores
		// separately from the element settings. Reading and writing the wrong map
		// would report a correction as applied while the editor never sees it.
		$device_scoped = '' !== $this->properties->control_device( $control );
		if ( $device_scoped ) {
			$current_map = isset( $this->responsive[ $element_id ] ) && is_array( $this->responsive[ $element_id ] )
				? $this->responsive[ $element_id ]
				: array();
			$old = $this->properties->read_control( $current_map, $control );
		} else {
			$settings = isset( $located['element']['settings'] ) && is_array( $located['element']['settings'] )
				? $located['element']['settings']
				: array();
			$old = $this->properties->read_control( $settings, $control );
		}
		if ( null === $old ) {
			return $this->failure( 'target_property_not_present' );
		}

		$new = $this->merge( $property, $old, $value );
		if ( null === $new ) {
			return $this->failure( 'value_not_acceptable' );
		}

		$companion = $this->properties->companion( $property );
		if ( null !== $companion && empty( $correction['skip_companion'] ) ) {
			$companion_map = $device_scoped
				? $current_map
				: ( isset( $located['element']['settings'] ) && is_array( $located['element']['settings'] )
					? $located['element']['settings']
					: array() );
			if (
				'boxed_content_width' === $this->properties->requirement( $property )
				&& 'boxed' !== (string) $this->properties->read_control( $companion_map, $companion['control'] )
			) {
				return $this->failure( 'companion_control_not_satisfied' );
			}
			$this->properties->write_control( $companion_map, $companion['control'], $companion['value'] );
		}

		if ( $device_scoped ) {
			$this->properties->write_control( $current_map, $control, $new );
			$this->responsive[ $element_id ] = $current_map;
		} else {
			$this->properties->write_control( $located['element']['settings'], $control, $new );
			$this->replace( $elements, $located, $located['element'] );
		}

		return array(
			'success' => true,
			'record'  => array(
				'correction_id' => isset( $correction['correction_id'] ) ? (string) $correction['correction_id'] : '',
				'action'        => isset( $correction['action'] ) ? (string) $correction['action'] : 'update',
				'property'      => $property,
				'control'       => $control,
				'element_id'    => $element_id,
				'viewport'      => $this->properties->device( $device ),
				'old_value'     => $old,
				'new_value'     => $new,
				'written_value' => $new,
				'property_key'  => $element_id . '|' . $property . '|' . $this->properties->device( $device ),
			),
		);
	}

	/**
	 * Merge a coerced value into the current value of a control.
	 *
	 * A side property such as `padding_top` changes only the top side of the
	 * existing dimensions value, so the other three sides keep whatever the
	 * document already had.
	 *
	 * @param string $property Comparison property.
	 * @param mixed  $old     Current value.
	 * @param mixed  $value   Coerced value.
	 * @return mixed Null when the merge is not possible.
	 */
	private function merge( $property, $old, $value ) {
		if ( ! $this->properties->is_side_property( $property ) ) {
			return $value;
		}
		if ( ! is_array( $old ) || ! is_array( $value ) ) {
			return null;
		}
		$merged  = $old;
		$changed = false;
		foreach ( $this->properties->sides( $property ) as $side ) {
			if ( ! isset( $value[ $side ] ) ) {
				continue;
			}
			$merged[ $side ] = $value[ $side ];
			$changed         = true;
		}
		if ( ! $changed ) {
			return null;
		}
		// The sides must still share one unit for Elementor to accept the value.
		$units = array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			if ( isset( $merged[ $side ] ) ) {
				$units[] = (string) ( $merged['unit'] ?? 'px' );
			}
		}
		if ( count( array_unique( $units ) ) > 1 ) {
			$merged['unit'] = (string) ( $value['unit'] ?? 'px' );
		}
		return $merged;
	}

	/**
	 * Reorder a top-level section.
	 *
	 * @param array<int, mixed>    $elements   Document, by reference.
	 * @param array<string, mixed> $correction Correction.
	 * @return array<string, mixed>
	 */
	private function apply_reorder( array &$elements, array $correction ) {
		$order = isset( $correction['value']['order'] ) && is_array( $correction['value']['order'] ) ? $correction['value']['order'] : array();
		if ( count( $order ) !== count( $elements ) ) {
			return $this->failure( 'reorder_incomplete' );
		}

		$by_id = array();
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) && is_string( $element['id'] ) ) {
				$by_id[ strtolower( $element['id'] ) ] = $element;
			}
		}
		if ( count( $by_id ) !== count( $order ) ) {
			return $this->failure( 'reorder_unknown_element' );
		}

		$reordered = array();
		foreach ( $order as $element_id ) {
			$element_id = strtolower( (string) $element_id );
			if ( ! isset( $by_id[ $element_id ] ) ) {
				return $this->failure( 'reorder_unknown_element' );
			}
			$reordered[] = $by_id[ $element_id ];
		}

		$elements = $reordered;

		return array(
			'success' => true,
			'record'  => array(
				'correction_id' => isset( $correction['correction_id'] ) ? (string) $correction['correction_id'] : '',
				'action'        => 'reorder',
				'property'      => 'section_order',
				'element_id'    => '',
				'viewport'      => 'desktop',
				'old_value'     => array_keys( $by_id ),
				'new_value'     => array_map( 'strval', $order ),
			),
		);
	}

	/**
	 * Remove one top-level section after explicit approval.
	 *
	 * @param array<int, mixed>    $elements   Document, by reference.
	 * @param array<string, mixed> $correction Correction.
	 * @return array<string, mixed>
	 */
	private function apply_remove( array &$elements, array $correction ) {
		$element_id = isset( $correction['target']['elementor_element_id'] ) ? strtolower( (string) $correction['target']['elementor_element_id'] ) : '';
		if ( '' === $element_id ) {
			return $this->failure( 'target_element_missing' );
		}

		$kept    = array();
		$removed = null;
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) && strtolower( (string) $element['id'] ) === $element_id ) {
				$removed = $element;
				continue;
			}
			$kept[] = $element;
		}

		if ( null === $removed ) {
			return $this->failure( 'target_element_missing' );
		}
		if ( count( $kept ) < 1 ) {
			return $this->failure( 'remove_would_empty_document' );
		}

		$elements = $kept;

		return array(
			'success' => true,
			'record'  => array(
				'correction_id' => isset( $correction['correction_id'] ) ? (string) $correction['correction_id'] : '',
				'action'        => 'remove',
				'property'      => 'section_present',
				'element_id'    => $element_id,
				'viewport'      => 'desktop',
				'old_value'     => 'present',
				'new_value'     => 'absent',
			),
		);
	}

	/**
	 * Insert one approved section built by the Phase 4 mapper and builder.
	 *
	 * The section is rebuilt from the reconstruction specification rather than
	 * authored by Phase 6, so the inserted structure is exactly the structure
	 * Phase 4 would have generated for it.
	 *
	 * @param array<int, mixed>    $elements   Document, by reference.
	 * @param array<string, mixed> $correction Correction.
	 * @return array<string, mixed>
	 */
	private function apply_insert( array &$elements, array $correction ) {
		if ( ! $this->mapper instanceof Elementor_Mapper || ! $this->builder instanceof Elementor_Document_Builder ) {
			return $this->failure( 'insert_builder_unavailable' );
		}

		$plan      = isset( $correction['specification']['plan'] ) && is_array( $correction['specification']['plan'] ) ? $correction['specification']['plan'] : null;
		$section_id = isset( $correction['specification']['section_id'] ) ? (string) $correction['specification']['section_id'] : '';
		if ( null === $plan || '' === $section_id ) {
			return $this->failure( 'insert_specification_unavailable' );
		}

		$tree = $this->mapper->map( $plan );
		$node = $this->section_node( isset( $tree['tree'] ) && is_array( $tree['tree'] ) ? $tree['tree'] : array(), $section_id );
		if ( null === $node ) {
			return $this->failure( 'insert_section_not_in_specification' );
		}

		$built = $this->builder->build(
			array( $node ),
			array( 'generation_id' => 'correction-' . ( isset( $correction['correction_id'] ) ? (string) $correction['correction_id'] : 'insert' ) )
		);
		if ( empty( $built['elements'] ) ) {
			return $this->failure( 'insert_section_empty' );
		}

		$position = isset( $correction['value']['position'] ) ? (int) $correction['value']['position'] : count( $elements );
		$position = max( 0, min( count( $elements ), $position ) );

		array_splice( $elements, $position, 0, array( $built['elements'][0] ) );

		return array(
			'success' => true,
			'record'  => array(
				'correction_id' => isset( $correction['correction_id'] ) ? (string) $correction['correction_id'] : '',
				'action'        => 'insert',
				'property'      => 'section_present',
				'element_id'    => isset( $built['elements'][0]['id'] ) ? (string) $built['elements'][0]['id'] : '',
				'viewport'      => 'desktop',
				'old_value'     => 'absent',
				'new_value'     => 'present',
				'position'      => $position,
			),
		);
	}

	/**
	 * Return the mapper node for one section.
	 *
	 * @param array<int, mixed> $tree       Mapper tree.
	 * @param string            $section_id Source section identifier.
	 * @return array<string, mixed>|null
	 */
	private function section_node( array $tree, $section_id ) {
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['meta'] ) || ! is_array( $node['meta'] ) ) {
				continue;
			}
			if ( isset( $node['meta']['section_id'] ) && (string) $node['meta']['section_id'] === $section_id ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Locate an element and its path through the tree.
	 *
	 * @param array<int, mixed> $elements   Document.
	 * @param string            $element_id Element identifier.
	 * @return array{element: array, path: array<int, int>, parent: array<int, mixed>}|null
	 */
	private function locate( array $elements, $element_id ) {
		$element_id = strtolower( (string) $element_id );
		if ( '' === $element_id ) {
			return null;
		}
		// `search()` reports the parent branch through a by-reference argument, and
		// PHP cannot pass a literal such as `null` by reference, so the variable is
		// declared here instead.
		$parent = null;

		return $this->search( $elements, $element_id, array(), $parent );
	}

	/**
	 * Search a branch of the tree for an element.
	 *
	 * @param array<int, mixed>      $elements Branch.
	 * @param string                 $element_id Element identifier.
	 * @param array<int, int>        $path     Path so far.
	 * @param array<int, mixed>|null $parent   Parent branch, by reference.
	 * @return array<string, mixed>|null
	 */
	private function search( array $elements, $element_id, array $path, &$parent ) {
		$branch = $elements;
		foreach ( $elements as $key => $element ) {
			if ( ! is_array( $element ) || ! isset( $element['id'] ) || ! is_string( $element['id'] ) ) {
				continue;
			}
			$child_path   = array_merge( $path, array( (int) $key ) );
			$child_parent = $branch;
			if ( strtolower( $element['id'] ) === $element_id ) {
				$parent = $child_parent;
				return array(
					'element' => $element,
					'path'    => $child_path,
					'parent'  => $child_parent,
				);
			}
			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
			if ( $children ) {
				$found = $this->search( $children, $element_id, $child_path, $parent );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Replace an element in a document using a located path.
	 *
	 * @param array<int, mixed>  $elements Document, by reference.
	 * @param array<string, mixed> $located Located element.
	 * @param array<string, mixed> $replacement Replacement element.
	 * @return void
	 */
	private function replace( array &$elements, array $located, array $replacement ) {
		$cursor = &$elements;
		$keys   = $located['path'];
		$last   = array_pop( $keys );
		foreach ( $keys as $key ) {
			if ( ! isset( $cursor[ $key ]['elements'] ) || ! is_array( $cursor[ $key ]['elements'] ) ) {
				return;
			}
			$cursor = &$cursor[ $key ]['elements'];
		}
		if ( null !== $last && isset( $cursor[ $last ] ) ) {
			$cursor[ $last ] = $replacement;
		}
		unset( $cursor );
	}

	/**
	 * Order corrections so structural work happens before property work.
	 *
	 * @param array<int, array<string, mixed>> $corrections Corrections.
	 * @return array<int, array<string, mixed>>
	 */
	private function order( array $corrections ) {
		$structural = array();
		$property   = array();
		foreach ( $corrections as $correction ) {
			if ( ! is_array( $correction ) || ! isset( $correction['action'] ) ) {
				continue;
			}
			if ( in_array( (string) $correction['action'], Correction_Limits::STRUCTURAL_ACTIONS, true ) ) {
				$structural[] = $correction;
				continue;
			}
			$property[] = $correction;
		}
		return array_merge( $structural, $property );
	}

	/**
	 * Return the Elementor document object for a post.
	 *
	 * @param int $post_id Draft post identifier.
	 * @return object|null
	 */
	private function document_for( $post_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! method_exists( '\Elementor\Plugin', 'instance' ) ) {
			return null;
		}
		$plugin = \Elementor\Plugin::instance();
		if ( ! is_object( $plugin ) || ! isset( $plugin->documents ) || ! is_object( $plugin->documents ) ) {
			return null;
		}
		$document = $plugin->documents->get( $post_id, false );
		return is_object( $document ) ? $document : null;
	}

	/**
	 * Build a failure outcome.
	 *
	 * @param string $reason Reason code.
	 * @return array<string, mixed>
	 */
	private function failure( $reason ) {
		return array(
			'success' => false,
			'reason'  => (string) $reason,
		);
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
