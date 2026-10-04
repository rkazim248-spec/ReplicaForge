<?php
/**
 * Elementor document validation for ReplicaForge Phase 4.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Validates generated documents before and after they are written.
 *
 * The pre-save pass refuses to hand a structurally broken or unsafe document to
 * Elementor. The post-save pass re-reads what was actually stored, so a silent
 * serialization or filter failure cannot be reported as success.
 */
final class Elementor_Validator {

	/**
	 * Widget registry.
	 *
	 * @var Elementor_Widget_Registry
	 */
	private $registry;

	/**
	 * Element types ReplicaForge may write.
	 *
	 * @var array<int, string>
	 */
	private $allowed_el_types = array( 'container', 'widget' );

	/**
	 * Constructor.
	 *
	 * @param object|null $registry Optional widget registry exposing `is_allowed_widget()`.
	 */
	public function __construct( $registry = null ) {
		$this->registry = ( is_object( $registry ) && method_exists( $registry, 'is_allowed_widget' ) )
			? $registry
			: new Elementor_Widget_Registry();
	}

	/**
	 * Validate a document element array.
	 *
	 * @param mixed $elements Element array.
	 * @return array<string, mixed>
	 */
	public function validate_document( $elements ) {
		$errors   = array();
		$warnings = array();

		if ( ! is_array( $elements ) ) {
			return $this->result( false, array( 'document_not_an_array' ), $warnings, 0, 0 );
		}

		if ( empty( $elements ) ) {
			$warnings[] = __( 'The generated document contains no Elementor elements.', 'replicaforge' );
			return $this->result( false, array( 'document_empty' ), $warnings, 0, 0 );
		}

		$state = array(
			'ids'      => array(),
			'count'    => 0,
			'max_depth' => 0,
		);

		$this->walk( $elements, 1, $state, $errors, $warnings, true );

		if ( count( $elements ) > Elementor_Limits::MAX_ELEMENTS ) {
			$errors[] = 'element_limit_exceeded';
		}

		return $this->result( empty( $errors ), $errors, $warnings, (int) $state['count'], (int) $state['max_depth'] );
	}

	/**
	 * Validate a created draft and its stored Elementor data.
	 *
	 * @param int $post_id Draft post ID.
	 * @return array<string, mixed>
	 */
	public function validate_saved_post( $post_id ) {
		$errors   = array();
		$warnings = array();
		$post_id  = absint( $post_id );

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return $this->result( false, array( 'draft_not_found' ), $warnings, 0, 0 );
		}

		if ( 'draft' !== $post->post_status ) {
			$errors[] = 'draft_not_a_draft';
		}
		if ( 'page' !== $post->post_type ) {
			$errors[] = 'unexpected_post_type';
		}
		if ( 'builder' !== (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			$errors[] = 'elementor_edit_mode_missing';
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $this->result( false, array( 'elementor_data_missing' ), $warnings, 0, 0 );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return $this->result( false, array( 'elementor_data_unreadable' ), $warnings, 0, 0 );
		}

		$document = $this->validate_document( $decoded );
		if ( empty( $document['valid'] ) ) {
			foreach ( $document['errors'] as $error ) {
				$errors[] = $error;
			}
		}
		$warnings = array_merge( $warnings, $document['warnings'] );

		$meta_present = array();
		foreach ( array( 'replicaforge_generation_id', 'replicaforge_schema_version', 'replicaforge_generated_at', 'replicaforge_phase' ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' === $value || null === $value ) {
				$errors[] = 'generation_metadata_missing';
			} else {
				$meta_present[ $key ] = true;
			}
		}

		return $this->result( empty( $errors ), array_values( array_unique( $errors ) ), array_values( array_unique( $warnings ) ), (int) $document['count'], (int) $document['max_depth'] );
	}

	/**
	 * Recursively validate one element list.
	 *
	 * @param array<int, mixed>    $elements Element list.
	 * @param int                  $depth    Current depth.
	 * @param array<string, mixed> $state    Shared state.
	 * @param array<int, string>   $errors   Error collector.
	 * @param array<int, string>   $warnings Warning collector.
	 * @param bool                 $root     Whether this is the document root.
	 * @return void
	 */
	private function walk( array $elements, $depth, array &$state, array &$errors, array &$warnings, $root = false ) {
		$state['max_depth'] = max( $state['max_depth'], $depth );

		if ( $depth > Elementor_Limits::MAX_DEPTH + 2 ) {
			$errors[] = 'document_too_deep';
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				$errors[] = 'element_not_an_array';
				continue;
			}
			$state['count']++;
			if ( $state['count'] > Elementor_Limits::MAX_ELEMENTS ) {
				return;
			}

			$id = isset( $element['id'] ) && is_string( $element['id'] ) ? $element['id'] : '';
			if ( ! preg_match( '/^[a-f0-9]{7}$/', $id ) ) {
				$errors[] = 'element_id_invalid';
			} elseif ( isset( $state['ids'][ $id ] ) ) {
				$errors[] = 'element_id_duplicate';
			} else {
				$state['ids'][ $id ] = true;
			}

			$el_type = isset( $element['elType'] ) && is_string( $element['elType'] ) ? $element['elType'] : '';
			if ( ! in_array( $el_type, $this->allowed_el_types, true ) ) {
				$errors[] = 'element_type_unsupported';
				continue;
			}

			if ( $root && 'container' !== $el_type ) {
				$errors[] = 'root_element_must_be_container';
			}

			if ( 'widget' === $el_type ) {
				$widget = isset( $element['widgetType'] ) && is_string( $element['widgetType'] ) ? $element['widgetType'] : '';
				if ( '' === $widget ) {
					$errors[] = 'widget_type_missing';
				} elseif ( ! $this->registry->is_allowed_widget( $widget ) ) {
					$errors[] = 'widget_type_unavailable';
				}
			}

			if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
				$errors[] = 'element_settings_invalid';
			} else {
				$this->scan_settings( $element['settings'], $errors, 0 );
			}

			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
			if ( 'widget' === $el_type && ! empty( $children ) ) {
				$errors[] = 'widget_children_unsupported';
				continue;
			}
			if ( ! empty( $children ) ) {
				$this->walk( $children, $depth + 1, $state, $errors, $warnings );
			}
		}
	}

	/**
	 * Recursively reject unsafe values inside element settings.
	 *
	 * @param mixed              $settings Settings value.
	 * @param array<int, string> $errors   Error collector.
	 * @param int                $depth    Current depth.
	 * @return void
	 */
	private function scan_settings( $settings, array &$errors, $depth ) {
		if ( $depth > 8 ) {
			return;
		}
		if ( is_array( $settings ) ) {
			foreach ( $settings as $value ) {
				$this->scan_settings( $value, $errors, $depth + 1 );
			}
			return;
		}
		if ( ! is_string( $settings ) || '' === $settings ) {
			return;
		}
		if ( Elementor_Values::is_executable( $settings ) ) {
			$errors[] = 'executable_value_in_document';
			return;
		}
		if ( preg_match( '/^\s*(?:javascript|vbscript|data|file|about|blob)\s*:/i', $settings ) ) {
			$errors[] = 'unsafe_scheme_in_document';
			return;
		}
		if ( 1 === preg_match( '/^https?:\/\//i', $settings ) && ! Security::is_safe_public_reference( $settings ) ) {
			$errors[] = 'unsafe_url_in_document';
		}
	}

	/**
	 * Build a validation result.
	 *
	 * @param bool               $valid     Validity.
	 * @param array<int, string> $errors    Error codes.
	 * @param array<int, string> $warnings  Warning messages.
	 * @param int                $count     Element count.
	 * @param int                $max_depth Maximum depth.
	 * @return array<string, mixed>
	 */
	private function result( $valid, array $errors, array $warnings, $count, $max_depth ) {
		return array(
			'valid'     => (bool) $valid,
			'errors'    => array_values( array_unique( $errors ) ),
			'warnings'  => array_values( array_unique( array_filter( $warnings ) ) ),
			'count'     => (int) $count,
			'max_depth' => (int) $max_depth,
		);
	}
}
