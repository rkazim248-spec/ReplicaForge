<?php
/**
 * Component to Elementor widget registry.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Maps ReplicaForge component types onto Elementor element types.
 *
 * The registry never assumes a widget exists. Every mapping is resolved against
 * the running Elementor instance and degrades to a safe, editable fallback so
 * one unsupported widget can never break a whole page.
 */
final class Elementor_Widget_Registry {

	/**
	 * Compatibility service.
	 *
	 * @var Elementor_Compatibility
	 */
	private $compatibility;

	/**
	 * Resolved mappings for the current request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private $resolved = null;

	/**
	 * Component type to widget name preferences, most preferred first.
	 *
	 * @var array<string, array<int, string>>
	 */
	private $widget_map = array(
		'heading'         => array( 'heading', 'text-editor' ),
		'paragraph'       => array( 'text-editor' ),
		'label'           => array( 'text-editor' ),
		'list'            => array( 'text-editor' ),
		'button'          => array( 'button', 'text-editor' ),
		'link'            => array( 'button', 'text-editor' ),
		'navigation_link' => array( 'button', 'text-editor' ),
		'image'           => array( 'image' ),
		'logo'            => array( 'image' ),
		'divider'         => array( 'divider' ),
		'spacer'          => array( 'spacer' ),
		'icon'            => array( 'icon' ),
		'unknown'         => array( 'text-editor' ),
	);

	/**
	 * Component types rendered as a nested container structure.
	 *
	 * @var array<int, string>
	 */
	private $container_types = array(
		'card',
		'product_card',
		'feature_card',
		'portfolio_card',
		'blog_card',
		'team_card',
		'pricing_card',
		'testimonial',
		'gallery',
		'repeated_card_group',
		'form',
		'form_field',
		'accordion',
		'tabs',
		'tab',
	);

	/**
	 * Component types that never become a separate document node.
	 *
	 * @var array<int, string>
	 */
	private $attached_types = array( 'background_image' );

	/**
	 * Widget types ReplicaForge refuses to emit even when registered.
	 *
	 * @var array<int, string>
	 */
	private $forbidden_widgets = array( 'html', 'shortcode', 'embed', 'html-tag', 'custom-html' );

	/**
	 * Constructor.
	 *
	 * The compatibility argument only needs to answer `has_widget()`,
	 * `has_element_type()`, and `supports_containers()`. Accepting any object with
	 * that contract keeps the registry testable without a running Elementor.
	 *
	 * @param object|null $compatibility Optional compatibility service.
	 */
	public function __construct( $compatibility = null ) {
		$this->compatibility = ( is_object( $compatibility ) && method_exists( $compatibility, 'has_widget' ) )
			? $compatibility
			: new Elementor_Compatibility();
	}

	/**
	 * Resolve the mapping for a ReplicaForge component type.
	 *
	 * @param string $type Component reconstruction type.
	 * @return array<string, mixed> `kind`, `widget`, `fallback`, and `reason`.
	 */
	public function resolve( $type ) {
		$type = is_string( $type ) ? strtolower( trim( $type ) ) : '';
		if ( '' !== $this->resolved && isset( $this->resolved[ $type ] ) ) {
			return $this->resolved[ $type ];
		}

		$mapped = $this->resolve_uncached( $type );
		if ( ! isset( $this->resolved ) ) {
			$this->resolved = array();
		}
		$this->resolved[ $type ] = $mapped;
		return $mapped;
	}

	/**
	 * Resolve without the request cache.
	 *
	 * @param string $type Component type.
	 * @return array<string, mixed>
	 */
	private function resolve_uncached( $type ) {
		if ( in_array( $type, $this->attached_types, true ) ) {
			return $this->mapping( 'attached', '', true, __( 'Background images are applied to their parent container instead of becoming separate widgets.', 'replicaforge' ) );
		}

		if ( in_array( $type, $this->container_types, true ) ) {
			return $this->mapping( 'container', 'container', false, '' );
		}

		$preferences = isset( $this->widget_map[ $type ] ) ? $this->widget_map[ $type ] : $this->widget_map['unknown'];
		$fallback    = 'unknown' !== $type;

		foreach ( $preferences as $widget_name ) {
			if ( in_array( $widget_name, $this->forbidden_widgets, true ) ) {
				continue;
			}
			if ( $this->compatibility->has_widget( $widget_name ) ) {
				return $this->mapping( 'widget', $widget_name, $fallback, $fallback ? __( 'The preferred Elementor widget was unavailable, so a safe alternative was used.', 'replicaforge' ) : '' );
			}
		}

		return $this->mapping(
			'skip',
			'',
			true,
			__( 'The matching Elementor widget is not available in the installed version, so this component was not generated.', 'replicaforge' )
		);
	}

	/**
	 * Return true when the widget type is one ReplicaForge emits.
	 *
	 * @param string $widget_name Widget name.
	 * @return bool
	 */
	public function is_allowed_widget( $widget_name ) {
		return is_string( $widget_name )
			&& '' !== $widget_name
			&& ! in_array( $widget_name, $this->forbidden_widgets, true )
			&& $this->compatibility->has_widget( $widget_name );
	}

	/**
	 * Return the container element type when it is registered.
	 *
	 * @return string
	 */
	public function container_type() {
		return $this->compatibility->supports_containers() ? 'container' : '';
	}

	/**
	 * Return every widget name this class is willing to emit.
	 *
	 * ### Why this exists
	 *
	 * Phase 19 needs an *allowlist* for template import, because
	 * `is_allowed_widget()` is a blocklist plus a live registration check and is therefore
	 * the wrong predicate for untrusted input: it accepts any registered widget that is not
	 * one of the five forbidden names, including third-party ones.
	 *
	 * The allowlist has to come from this class's own `$widget_map` rather than from a list
	 * restated elsewhere, or the two would drift — and a drifted allowlist is either
	 * unusably narrow (every legitimate template fails) or uselessly wide (it stops being an
	 * allowlist). So the vocabulary is exposed here, on the class that owns it, and Phase 19
	 * reads it.
	 *
	 * Deduplicated and order-preserving: the map is ordered by preference, so the result
	 * reads in the order ReplicaForge prefers these widgets.
	 *
	 * @return array<int, string>
	 */
	public function widget_vocabulary() {
		$out = array();

		foreach ( $this->widget_map as $preferences ) {
			foreach ( (array) $preferences as $widget ) {
				if ( is_string( $widget ) && '' !== $widget && ! in_array( $widget, $out, true ) ) {
					$out[] = $widget;
				}
			}
		}

		return $out;
	}

	/**
	 * Build one mapping result.
	 *
	 * @param string $kind     Result kind.
	 * @param string $widget   Elementor widget name.
	 * @param bool   $fallback Whether a fallback was used.
	 * @param string $reason   Safe reason text.
	 * @return array<string, mixed>
	 */
	private function mapping( $kind, $widget, $fallback, $reason ) {
		return array(
			'kind'     => $kind,
			'widget'   => $widget,
			'fallback' => (bool) $fallback,
			'reason'   => (string) $reason,
		);
	}
}
