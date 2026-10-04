<?php
/**
 * Elementor availability and capability detection for ReplicaForge.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the installed Elementor runtime before anything is generated.
 *
 * Nothing in Phase 4 assumes an Elementor version, class name, widget list, or
 * document API. Availability is resolved at runtime and every failure mode is
 * reported as a human-readable reason instead of a fatal error.
 */
final class Elementor_Compatibility {

	/**
	 * Cached status for the current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private $status = null;

	/**
	 * Required Elementor classes used by the generator.
	 *
	 * @var array<int, string>
	 */
	private $required_classes = array(
		'\Elementor\Plugin',
		'\Elementor\Controls_Manager',
		'\Elementor\Widgets_Manager',
		'\Elementor\Elements_Manager',
		'\Elementor\Includes\Elements\Container',
		'\Elementor\Core\Base\Document',
	);

	/**
	 * Required Elementor document methods used by the generator.
	 *
	 * @var array<int, string>
	 */
	private $required_document_methods = array(
		'save',
		'get_elements_data',
		'is_built_with_elementor',
	);

	/**
	 * Return the resolved Elementor status.
	 *
	 * @param bool $refresh Recompute instead of using the request cache.
	 * @return array<string, mixed>
	 */
	public function status( $refresh = false ) {
		if ( ! $refresh && is_array( $this->status ) ) {
			return $this->status;
		}

		$this->status = $this->resolve();
		return $this->status;
	}

	/**
	 * Return true when Phase 4 can generate a real Elementor document.
	 *
	 * @return bool
	 */
	public function is_available() {
		$status = $this->status();
		return ! empty( $status['available'] );
	}

	/**
	 * Return the detected Elementor version or an empty string.
	 *
	 * @return string
	 */
	public function version() {
		$status = $this->status();
		return isset( $status['version'] ) && is_string( $status['version'] ) ? $status['version'] : '';
	}

	/**
	 * Return true when the container element type is registered.
	 *
	 * @return bool
	 */
	public function supports_containers() {
		$status = $this->status();
		return ! empty( $status['containers'] );
	}

	/**
	 * Return true when a widget type is registered in the running Elementor.
	 *
	 * @param string $widget_name Widget name, for example `heading`.
	 * @return bool
	 */
	public function has_widget( $widget_name ) {
		$status = $this->status();
		return is_string( $widget_name ) && '' !== $widget_name && isset( $status['widgets'][ $widget_name ] );
	}

	/**
	 * Return true when an element type is registered in the running Elementor.
	 *
	 * @param string $element_name Element name, for example `container`.
	 * @return bool
	 */
	public function has_element_type( $element_name ) {
		$status = $this->status();
		return is_string( $element_name ) && '' !== $element_name && isset( $status['element_types'][ $element_name ] );
	}

	/**
	 * Return the registered widget names.
	 *
	 * @return array<string, bool>
	 */
	public function widget_types() {
		$status = $this->status();
		return isset( $status['widgets'] ) && is_array( $status['widgets'] ) ? $status['widgets'] : array();
	}

	/**
	 * Return the active responsive device keys.
	 *
	 * @return array<int, string>
	 */
	public function active_devices() {
		$status = $this->status();
		return isset( $status['devices'] ) && is_array( $status['devices'] ) ? $status['devices'] : array();
	}

	/**
	 * Return a safe, human-readable availability message for the admin UI.
	 *
	 * @return string
	 */
	public function message() {
		$status = $this->status();
		if ( ! empty( $status['available'] ) ) {
			return sprintf(
				/* translators: 1: Elementor version, 2: ReplicaForge phase. */
				__( 'Elementor %1$s is active. ReplicaForge can generate an editable draft (%2$s).', 'replicaforge' ),
				(string) $status['version'],
				Elementor_Limits::PHASE
			);
		}

		return isset( $status['message'] ) && is_string( $status['message'] ) && '' !== $status['message']
			? $status['message']
			: __( 'Elementor is required to generate editable replicas. Please install and activate Elementor, then try again.', 'replicaforge' );
	}

	/**
	 * Resolve the runtime capabilities.
	 *
	 * @return array<string, mixed>
	 */
	private function resolve() {
		$status = array(
			'available'      => false,
			'installed'      => false,
			'active'         => false,
			'version'        => '',
			'containers'     => false,
			'widgets'        => array(),
			'element_types'  => array(),
			'devices'        => array( 'desktop', 'tablet', 'mobile' ),
			'missing'        => array(),
			'message'        => '',
			'checked_at'     => gmdate( 'c' ),
		);

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			$status['message'] = __( 'Elementor is required to generate editable replicas. Please install and activate Elementor, then try again.', 'replicaforge' );
			return $status;
		}

		$status['installed'] = true;
		$status['version']   = (string) ELEMENTOR_VERSION;

		foreach ( $this->required_classes as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				$status['missing'][] = $class_name;
			}
		}
		if ( ! empty( $status['missing'] ) ) {
			$status['message'] = __( 'Elementor is installed but has not finished loading. Please reload the ReplicaForge screen and try again.', 'replicaforge' );
			return $status;
		}

		$plugin = $this->plugin_instance();
		if ( ! $plugin instanceof \Elementor\Plugin ) {
			$status['message'] = __( 'Elementor is installed but is not active. Please activate Elementor, then try again.', 'replicaforge' );
			return $status;
		}
		$status['active'] = true;

		if ( version_compare( $status['version'], Elementor_Limits::MINIMUM_ELEMENTOR_VERSION, '<' ) ) {
			$status['message'] = sprintf(
				/* translators: 1: Installed Elementor version, 2: Minimum supported version. */
				__( 'ReplicaForge could not generate the Elementor draft because the installed Elementor version (%1$s) is older than the minimum supported container architecture (%2$s).', 'replicaforge' ),
				$status['version'],
				Elementor_Limits::MINIMUM_ELEMENTOR_VERSION
			);
			return $status;
		}

		$element_types = $this->element_types( $plugin );
		$widgets       = $this->widgets( $plugin );
		$documents     = isset( $plugin->documents ) ? $plugin->documents : null;

		if ( ! $documents || ! method_exists( $documents, 'get' ) ) {
			$status['message'] = __( 'ReplicaForge could not generate the Elementor draft because the installed Elementor build does not expose its document API.', 'replicaforge' );
			return $status;
		}

		$document_class = '\Elementor\Core\Base\Document';
		foreach ( $this->required_document_methods as $method_name ) {
			if ( ! method_exists( $document_class, $method_name ) ) {
				$status['missing'][] = $document_class . '::' . $method_name;
			}
		}
		if ( ! empty( $status['missing'] ) ) {
			$status['message'] = __( 'ReplicaForge could not generate the Elementor draft because the installed Elementor build does not support the required document API.', 'replicaforge' );
			return $status;
		}

		$status['element_types'] = $element_types;
		$status['widgets']       = $widgets;
		$status['containers']    = isset( $element_types['container'] );
		$status['devices']       = $this->devices( $plugin );
		$status['available']     = $status['containers'] && ! empty( $widgets );

		if ( ! $status['available'] ) {
			$status['message'] = __( 'ReplicaForge could not generate the Elementor draft because the installed Elementor build does not register the required container element and basic widgets.', 'replicaforge' );
		}

		return $status;
	}

	/**
	 * Return the running Elementor plugin instance.
	 *
	 * @return \Elementor\Plugin|null
	 */
	private function plugin_instance() {
		if ( class_exists( '\Elementor\Plugin' ) && method_exists( '\Elementor\Plugin', 'instance' ) ) {
			$instance = \Elementor\Plugin::instance();
			if ( is_object( $instance ) ) {
				return $instance;
			}
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) && is_object( \Elementor\Plugin::$instance ) ) {
			return \Elementor\Plugin::$instance;
		}
		return null;
	}

	/**
	 * Return registered element type names.
	 *
	 * @param \Elementor\Plugin $plugin Plugin instance.
	 * @return array<string, bool>
	 */
	private function element_types( $plugin ) {
		$names = array();
		if ( ! isset( $plugin->elements_manager ) || ! method_exists( $plugin->elements_manager, 'get_element_types' ) ) {
			return $names;
		}
		$types = $plugin->elements_manager->get_element_types();
		if ( is_array( $types ) ) {
			foreach ( $types as $name => $type ) {
				if ( is_string( $name ) && '' !== $name ) {
					$names[ $name ] = true;
				}
			}
		}
		return $names;
	}

	/**
	 * Return registered widget type names.
	 *
	 * @param \Elementor\Plugin $plugin Plugin instance.
	 * @return array<string, bool>
	 */
	private function widgets( $plugin ) {
		$names = array();
		if ( ! isset( $plugin->widgets_manager ) || ! method_exists( $plugin->widgets_manager, 'get_widget_types' ) ) {
			return $names;
		}
		$widgets = $plugin->widgets_manager->get_widget_types();
		if ( is_array( $widgets ) ) {
			foreach ( $widgets as $name => $widget ) {
				if ( is_string( $name ) && '' !== $name ) {
					$names[ $name ] = true;
				}
			}
		}
		return $names;
	}

	/**
	 * Return the active responsive device keys reported by Elementor.
	 *
	 * @param \Elementor\Plugin $plugin Plugin instance.
	 * @return array<int, string>
	 */
	private function devices( $plugin ) {
		$devices = array( 'desktop', 'tablet', 'mobile' );
		if ( ! isset( $plugin->breakpoints ) || ! method_exists( $plugin->breakpoints, 'get_active_breakpoints' ) ) {
			return $devices;
		}
		try {
			$active = $plugin->breakpoints->get_active_breakpoints();
		} catch ( \Throwable $exception ) {
			return $devices;
		}
		if ( ! is_array( $active ) || empty( $active ) ) {
			return $devices;
		}
		$supported = array();
		foreach ( $active as $key => $value ) {
			$name = is_string( $key ) ? $key : ( isset( $value['name'] ) && is_string( $value['name'] ) ? $value['name'] : '' );
			if ( in_array( $name, $devices, true ) ) {
				$supported[] = $name;
			}
		}
		return empty( $supported ) ? $devices : array_values( array_unique( $supported ) );
	}
}
