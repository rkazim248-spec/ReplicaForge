<?php
/**
 * Elementor document reader for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Loads a generated draft and exposes its editable structure.
 *
 * The reader is the only place Phase 6 reads a document, and it is deliberately
 * strict. A post identifier that arrives from a browser is never trusted: the
 * post must exist, the current user must be able to edit it, it must be a
 * ReplicaForge-generated draft, and it must still be a draft. An element
 * identifier is likewise never trusted — the caller may only locate an element
 * through the map this reader builds.
 */
final class Elementor_Document_Reader {

	/**
	 * Element index for the loaded document.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $index = array();

	/**
	 * Element settings, keyed by element identifier.
	 *
	 * The index deliberately holds only descriptive fields, because it is exported
	 * and shown on the review screen. A control value has to be read from the real
	 * settings, so they are kept in a separate lookup. Without it every control
	 * reads as unset, which would make every correction look like it is adding a
	 * style that was not there before.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $settings = array();

	/**
	 * Responsive overrides, keyed by element identifier.
	 *
	 * Elementor keeps a responsive override out of the element settings entirely,
	 * in a post-level map keyed by element id, and the editor reads it from there.
	 * Reading a device value from the element settings would find nothing, so the
	 * two maps are kept apart here as well.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $responsive = array();

	/**
	 * Top-level element order, as keys into the element array.
	 *
	 * @var array<int, string>
	 */
	private $top_level = array();

	/**
	 * Decoded document.
	 *
	 * @var array<int, mixed>
	 */
	private $elements = array();

	/**
	 * Component to element map.
	 *
	 * @var array<string, string>
	 */
	private $id_map = array();

	/**
	 * Loaded post identifier.
	 *
	 * @var int
	 */
	private $post_id = 0;

	/**
	 * Reason the last load failed, or an empty string.
	 *
	 * @var string
	 */
	private $error = '';

	/**
	 * Document validator.
	 *
	 * @var Elementor_Validator
	 */
	private $validator;

	/**
	 * Correction property whitelist.
	 *
	 * @var Correction_Property_Map
	 */
	private $properties;

	/**
	 * Constructor.
	 *
	 * @param Elementor_Validator|null     $validator  Optional document validator.
	 * @param Correction_Property_Map|null $properties Optional property whitelist.
	 */
	public function __construct( $validator = null, $properties = null ) {
		$this->validator  = $validator instanceof Elementor_Validator ? $validator : new Elementor_Validator();
		$this->properties = $properties instanceof Correction_Property_Map ? $properties : new Correction_Property_Map();
	}

	/**
	 * Return the reason the last load failed.
	 *
	 * @return string
	 */
	public function error() {
		return $this->error;
	}

	/**
	 * Load a draft for correction.
	 *
	 * @param int  $post_id Draft post identifier.
	 * @param bool $require_draft Whether a non-draft page must be refused.
	 * @return bool
	 */
	public function load( $post_id, $require_draft = true ) {
		$this->error      = '';
		$this->index      = array();
		$this->settings   = array();
		$this->responsive = array();
		$this->elements   = array();
		$this->id_map     = array();
		$this->top_level  = array();
		$this->post_id    = 0;

		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			$this->error = 'invalid_draft_id';
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->error = 'draft_not_found';
			return false;
		}

		// The browser never decides which post is corrected. The post must belong
		// to a ReplicaForge generation and the user must be able to edit it.
		$generation_id = (string) get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'generation_id', true );
		if ( '' === $generation_id ) {
			$this->error = 'not_a_replicaforge_draft';
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'edit_pages' ) ) {
			$this->error = 'insufficient_capability';
			return false;
		}

		if ( 'builder' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			$this->error = 'elementor_edit_mode_missing';
			return false;
		}

		if ( $require_draft && 'draft' !== $post->post_status ) {
			$this->error = 'page_is_not_a_draft';
			return false;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			$this->error = 'elementor_data_missing';
			return false;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$this->error = 'elementor_data_unreadable';
			return false;
		}

		$check = $this->validator->validate_document( $decoded );
		if ( empty( $check['valid'] ) ) {
			$this->error = 'elementor_data_invalid';
			return false;
		}

		$this->post_id    = $post_id;
		$this->elements   = $decoded;
		$this->responsive = $this->read_responsive( $post_id );
		$this->id_map     = $this->read_id_map( $post_id );
		$this->index    = $this->build_index( $decoded, '', 1 );
		$this->top_level = $this->read_top_level( $decoded );

		return true;
	}

	/**
	 * Return the loaded post identifier.
	 *
	 * @return int
	 */
	public function post_id() {
		return $this->post_id;
	}

	/**
	 * Return the decoded document.
	 *
	 * @return array<int, mixed>
	 */
	public function elements() {
		return $this->elements;
	}

	/**
	 * Replace the in-memory document.
	 *
	 * The writer uses this after it has produced a corrected element array. The
	 * reader never writes to the database; the writer owns persistence.
	 *
	 * @param array<int, mixed> $elements Corrected document.
	 * @return void
	 */
	public function set_elements( array $elements ) {
		$this->elements = $elements;
		$this->settings = array();
		$this->index    = $this->build_index( $elements, '', 1 );
		$this->top_level = $this->read_top_level( $elements );
	}

	/**
	 * Replace the in-memory responsive overrides.
	 *
	 * The writer uses this to seed itself with the overrides already on the draft,
	 * so a device correction reads the current value instead of assuming there is
	 * none and overwriting whatever is there.
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
	 * Return the responsive overrides, keyed by element id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function responsive() {
		return $this->responsive;
	}

	/**
	 * Read the responsive overrides stored on a draft.
	 *
	 * @param int $post_id Draft post identifier.
	 * @return array<string, array<string, mixed>>
	 */
	private function read_responsive( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_responsive', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$responsive = array();
		foreach ( $decoded as $element_id => $overrides ) {
			if ( is_string( $element_id ) && is_array( $overrides ) ) {
				$responsive[ strtolower( $element_id ) ] = $overrides;
			}
		}

		return $responsive;
	}

	/**
	 * Return the component to element map stored by Phase 4.
	 *
	 * @return array<string, string>
	 */
	public function id_map() {
		return $this->id_map;
	}

	/**
	 * Return the element index.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function index() {
		return $this->index;
	}

	/**
	 * Return the ordered top-level element identifiers.
	 *
	 * @return array<int, string>
	 */
	public function top_level() {
		return $this->top_level;
	}

	/**
	 * Return the number of indexed elements.
	 *
	 * @return int
	 */
	public function element_count() {
		return count( $this->index );
	}

	/**
	 * Locate an element by identifier.
	 *
	 * @param string $element_id Element identifier.
	 * @return array<string, mixed>|null
	 */
	public function element( $element_id ) {
		if ( ! is_string( $element_id ) || '' === $element_id ) {
			return null;
		}
		return isset( $this->index[ $element_id ] ) ? $this->index[ $element_id ] : null;
	}

	/**
	 * Return the element identifier of a source component.
	 *
	 * The lookup is exact. When the map has no entry, nothing is guessed.
	 *
	 * @param string $component_id Source component identifier.
	 * @return string
	 */
	public function element_for_component( $component_id ) {
		if ( ! is_string( $component_id ) || '' === $component_id ) {
			return '';
		}
		return isset( $this->id_map[ $component_id ] ) ? $this->id_map[ $component_id ] : '';
	}

	/**
	 * Return the source component identifier of an element.
	 *
	 * @param string $element_id Element identifier.
	 * @return string
	 */
	public function component_for_element( $element_id ) {
		if ( ! is_string( $element_id ) || '' === $element_id ) {
			return '';
		}
		return isset( $this->id_map[ $element_id ] ) ? $this->id_map[ $element_id ] : '';
	}

	/**
	 * Read one control value from an element.
	 *
	 * @param string $element_id Element identifier.
	 * @param string $control    Control key.
	 * @return mixed Null when the control is not set.
	 */
	public function control_value( $element_id, $control ) {
		$element_id = is_string( $element_id ) ? strtolower( $element_id ) : '';
		if ( '' === $element_id || '' === $this->properties->control_device( $control ) ) {
			// A desktop control lives in the element settings. An unknown element has
			// no settings, so the value is simply absent.
			if ( '' === $element_id || ! isset( $this->settings[ $element_id ] ) ) {
				return null;
			}
			return $this->properties->read_control( $this->settings[ $element_id ], $control );
		}

		// A device control lives in the responsive map, which Elementor stores
		// separately from the element settings.
		if ( ! isset( $this->responsive[ $element_id ] ) ) {
			return null;
		}

		return $this->properties->read_control( $this->responsive[ $element_id ], $control );
	}

	/**
	 * Return the correction property whitelist.
	 *
	 * @return Correction_Property_Map
	 */
	public function properties() {
		return $this->properties;
	}

	/**
	 * Return the editable properties of an element.
	 *
	 * Only whitelist controls that the element actually carries are returned, so
	 * the review screen can show a real before value for every proposed change.
	 *
	 * @param string $element_id Element identifier.
	 * @return array<string, mixed>
	 */
	public function editable_properties( $element_id ) {
		$element = $this->element( $element_id );
		if ( null === $element ) {
			return array();
		}
		$editable = array();
		foreach ( $this->properties->all() as $property => $entry ) {
			// The index records the type as `el_type`, because the index holds
			// descriptive fields only.
			$el_type = isset( $element['el_type'] ) ? (string) $element['el_type'] : '';
			foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
				$control = $this->properties->control( $property, $el_type, $device );
				if ( '' === $control ) {
					continue;
				}
				$value = $this->control_value( $element_id, $control );
				if ( null === $value ) {
					continue;
				}
				$editable[ $property ][ $device ] = $value;
			}
		}
		return $editable;
	}

	/**
	 * Return a hash of the loaded document.
	 *
	 * @return string
	 */
	public function document_hash() {
		$encoded = wp_json_encode( $this->elements );
		return hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * Read and normalize the Phase 4 identity map.
	 *
	 * @param int $post_id Draft post identifier.
	 * @return array<string, string>
	 */
	private function read_id_map( $post_id ) {
		$raw = get_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'id_map', true );
		$map = array();
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $component_id => $element_id ) {
					$component_id = is_string( $component_id ) ? strtolower( trim( $component_id ) ) : '';
					$element_id   = is_string( $element_id ) ? strtolower( trim( $element_id ) ) : '';
					if ( '' === $component_id || '' === $element_id || ! preg_match( '/^[a-z0-9]{5,10}$/', $element_id ) ) {
						continue;
					}
					$component_id = preg_replace( '/[^a-z0-9_\-]/', '', $component_id );
					if ( is_string( $component_id ) && '' !== $component_id ) {
						$map[ $element_id ] = substr( $component_id, 0, 120 );
					}
				}
			}
		}
		return $map;
	}

	/**
	 * Build a flat index of every element with its position in the tree.
	 *
	 * @param array<int, mixed> $elements Document.
	 * @param string            $parent   Parent element identifier.
	 * @param int               $depth    Current depth.
	 * @return array<string, array<string, mixed>>
	 */
	private function build_index( array $elements, $parent, $depth ) {
		$index = array();
		if ( $depth > Elementor_Limits::MAX_DEPTH + 2 ) {
			return $index;
		}
		foreach ( $elements as $key => $element ) {
			if ( ! is_array( $element ) || ! isset( $element['id'] ) || ! is_string( $element['id'] ) ) {
				continue;
			}
			$id      = strtolower( $element['id'] );
			$index[ $id ] = array(
				'key'       => (int) $key,
				'id'        => $id,
				'el_type'   => isset( $element['elType'] ) ? (string) $element['elType'] : '',
				'widget'    => isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '',
				'parent_id' => $parent,
				'depth'     => $depth,
				'component' => isset( $this->id_map[ $id ] ) ? $this->id_map[ $id ] : '',
			);
			$this->settings[ $id ] = isset( $element['settings'] ) && is_array( $element['settings'] )
				? $element['settings']
				: array();
			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
			if ( $children ) {
				// The child index is assigned key by key rather than merged with
				// `array_merge`. An Elementor identifier may be all digits, and PHP
				// turns such a key into an integer, which `array_merge` then renumbers.
				// A merged index therefore loses every all-digit identifier, and the
				// element is then reported as missing from a draft that still has it.
				foreach ( $this->build_index( $children, $id, $depth + 1 ) as $child_id => $record ) {
					$index[ $child_id ] = $record;
				}
			}
		}
		return $index;
	}

	/**
	 * Return the ordered top-level element identifiers.
	 *
	 * @param array<int, mixed> $elements Document.
	 * @return array<int, string>
	 */
	private function read_top_level( array $elements ) {
		$ids = array();
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) && is_string( $element['id'] ) ) {
				$ids[] = strtolower( $element['id'] );
			}
		}
		return $ids;
	}
}
