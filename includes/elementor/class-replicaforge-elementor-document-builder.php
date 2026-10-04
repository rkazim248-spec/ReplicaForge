<?php
/**
 * Elementor document builder for ReplicaForge Phase 4.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Converts the mapper tree into an Elementor document tree.
 *
 * The builder owns element identifiers, hierarchy, and serialization only. It
 * never decides layout, never contacts a remote service, and scrubs every value
 * one final time before the document is handed to Elementor.
 */
final class Elementor_Document_Builder {

	/**
	 * Widget types that may never be written into a generated document.
	 *
	 * @var array<int, string>
	 */
	private $forbidden_widgets = array( 'html', 'shortcode', 'embed', 'custom-html', 'html-tag' );

	/**
	 * Issued element identifiers for the current document.
	 *
	 * @var array<string, bool>
	 */
	private $issued = array();

	/**
	 * Component to element identifier map.
	 *
	 * @var array<string, string>
	 */
	private $id_map = array();

	/**
	 * Generation identifier used to seed element identifiers.
	 *
	 * @var string
	 */
	private $seed = '';

	/**
	 * Element count for the current document.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Warnings collected while building.
	 *
	 * @var array<int, string>
	 */
	private $warnings = array();

	/**
	 * Build an Elementor element array.
	 *
	 * @param array<int, mixed>    $tree    Mapper tree.
	 * @param array<string, mixed> $context Build context containing `generation_id`.
	 * @return array<string, mixed>
	 */
	public function build( array $tree, array $context = array() ) {
		$this->issued   = array();
		$this->id_map   = array();
		$this->count    = 0;
		$this->warnings = array();
		$this->seed     = isset( $context['generation_id'] ) && is_string( $context['generation_id'] ) && '' !== $context['generation_id']
			? $context['generation_id']
			: 'replicaforge-' . gmdate( 'YmdHis' );

		$elements = array();
		foreach ( $tree as $index => $node ) {
			$element = $this->build_node( $node, (string) $index, 1 );
			if ( null !== $element ) {
				$elements[] = $element;
			}
		}

		return array(
			'elements' => $elements,
			'id_map'   => $this->id_map,
			'count'    => $this->count,
			'warnings' => array_values( array_unique( $this->warnings ) ),
		);
	}

	/**
	 * Build one node and its children.
	 *
	 * @param mixed $node  Mapper node.
	 * @param string $path Stable node path.
	 * @param int   $depth Current depth.
	 * @return array<string, mixed>|null
	 */
	private function build_node( $node, $path, $depth ) {
		if ( ! is_array( $node ) || $depth > Elementor_Limits::MAX_DEPTH + 2 ) {
			return null;
		}
		if ( $this->count >= Elementor_Limits::MAX_ELEMENTS ) {
			$this->warn( __( 'The Elementor element limit was reached; remaining nodes were not written.', 'replicaforge' ) );
			return null;
		}

		$kind   = isset( $node['kind'] ) ? (string) $node['kind'] : '';
		$widget = isset( $node['widget'] ) ? (string) $node['widget'] : '';
		$meta   = isset( $node['meta'] ) && is_array( $node['meta'] ) ? $node['meta'] : array();

		if ( 'container' === $kind ) {
			if ( 'container' !== $widget ) {
				return null;
			}
		} elseif ( 'widget' === $kind ) {
			if ( '' === $widget || in_array( $widget, $this->forbidden_widgets, true ) ) {
				$this->warn( __( 'A node that required an unsupported or executable widget was not written.', 'replicaforge' ) );
				return null;
			}
		} else {
			return null;
		}

		$settings = $this->scrub( isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array(), 0 );

		$element = array(
			'id'       => $this->element_id( $path ),
			'elType'   => 'container' === $kind ? 'container' : 'widget',
			'settings' => $settings,
			'elements' => array(),
		);
		if ( 'widget' === $kind ) {
			$element['widgetType'] = $widget;
			$element['elements']   = array();
		} else {
			$element['isInner'] = false;
		}

		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
		foreach ( $children as $index => $child ) {
			$child_element = $this->build_node( $child, $path . '.' . (string) $index, $depth + 1 );
			if ( null !== $child_element ) {
				$element['elements'][] = $child_element;
			}
		}

		$this->count++;

		if ( ! empty( $meta['component_id'] ) && is_string( $meta['component_id'] ) ) {
			$this->id_map[ $meta['component_id'] ] = $element['id'];
		}

		return $element;
	}

	/**
	 * Return a stable, unique seven character element identifier.
	 *
	 * @param string $path Node path.
	 * @return string
	 */
	private function element_id( $path ) {
		$attempt = 0;
		do {
			$candidate = substr( md5( $this->seed . '|' . $path . '|' . $attempt ), 0, 7 );
			$attempt++;
		} while ( isset( $this->issued[ $candidate ] ) && $attempt < 20 );

		$this->issued[ $candidate ] = true;
		return $candidate;
	}

	/**
	 * Recursively remove values that are unsafe to store in a document.
	 *
	 * @param mixed $value Value to scrub.
	 * @param int   $depth Current depth.
	 * @return mixed
	 */
	private function scrub( $value, $depth ) {
		if ( $depth > 8 ) {
			return null;
		}
		if ( is_string( $value ) ) {
			if ( Elementor_Values::is_executable( $value ) ) {
				$this->warn( __( 'A generated value that contained executable markup or an unsafe scheme was removed before the document was saved.', 'replicaforge' ) );
				return null;
			}
			return $value;
		}
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $key => $item ) {
				if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9_\-\[\]]{1,60}$/i', $key ) ) {
					continue;
				}
				$clean = $this->scrub( $item, $depth + 1 );
				if ( null !== $clean ) {
					$result[ $key ] = $clean;
				}
			}
			return $result;
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return $value;
		}
		return null;
	}

	/**
	 * Record a warning once.
	 *
	 * @param string $message Warning message.
	 * @return void
	 */
	private function warn( $message ) {
		if ( is_string( $message ) && '' !== $message && ! in_array( $message, $this->warnings, true ) ) {
			$this->warnings[] = $message;
		}
	}
}
