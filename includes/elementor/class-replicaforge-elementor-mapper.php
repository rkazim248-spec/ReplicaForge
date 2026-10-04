<?php
/**
 * Reconstruction specification to element tree mapper.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a normalized Reconstruction Specification into a builder-neutral tree.
 *
 * The mapper is deterministic. It never invents content, prices, links, fonts,
 * colors, images, or responsive behavior. Every value it writes comes from a
 * source-backed specification field, and every gap in the evidence produces an
 * explicit warning instead of a fabricated default.
 */
final class Elementor_Mapper {

	/**
	 * Widget registry.
	 *
	 * @var Elementor_Widget_Registry
	 */
	private $registry;

	/**
	 * Counters and per-generation warnings.
	 *
	 * @var array<string, mixed>
	 */
	private $stats = array();

	/**
	 * Collected warnings for the current mapping.
	 *
	 * @var array<int, string>
	 */
	private $warnings = array();

	/**
	 * Element budget for the current document.
	 *
	 * @var int
	 */
	private $element_budget = Elementor_Limits::MAX_ELEMENTS;

	/**
	 * Constructor.
	 *
	 * @param Elementor_Widget_Registry|null $registry Optional widget registry.
	 */
	public function __construct( $registry = null ) {
		$this->registry = ( is_object( $registry ) && method_exists( $registry, 'resolve' ) )
			? $registry
			: new Elementor_Widget_Registry();
	}

	/**
	 * Map a normalized plan into an element tree.
	 *
	 * @param array<string, mixed> $plan       Normalized reconstruction plan.
	 * @param array<string, mixed> $assets     Resolved asset states keyed by component.
	 * @param array<string, mixed> $responsive Responsive plan.
	 * @return array<string, mixed>
	 */
	public function map( array $plan, array $assets = array(), array $responsive = array() ) {
		$this->stats    = array(
			'sections'      => 0,
			'components'    => 0,
			'containers'    => 0,
			'widgets'       => 0,
			'fallbacks'     => 0,
			'skipped'       => 0,
			'elements'      => 0,
			'low_confidence' => 0,
		);
		$this->warnings = array();

		$overall = isset( $plan['confidence']['overall'] ) ? (float) $plan['confidence']['overall'] : 0.0;
		if ( $overall > 0 && $overall < 0.5 ) {
			$this->warn( __( 'The overall reconstruction confidence is low. The generated draft is a best-effort approximation and should be reviewed before it is published.', 'replicaforge' ) );
		}

		$tree   = array();
		$design = isset( $plan['design'] ) && is_array( $plan['design'] ) ? $plan['design'] : array();
		$plan['design'] = $design;

		$sections = isset( $plan['sections'] ) && is_array( $plan['sections'] ) ? $plan['sections'] : array();
		usort(
			$sections,
			static function ( $left, $right ) {
				$a = isset( $left['order'] ) ? (int) $left['order'] : 0;
				$b = isset( $right['order'] ) ? (int) $right['order'] : 0;
				if ( $a === $b ) {
					return 0;
				}
				return ( $a < $b ) ? -1 : 1;
			}
		);

		$navigation_links = 0;
		foreach ( isset( $plan['components'] ) && is_array( $plan['components'] ) ? $plan['components'] : array() as $component ) {
			if ( in_array( $component['reconstruction_type'], array( 'navigation_link', 'link' ), true ) && 'navigation' !== $component['role'] ) {
				$navigation_links++;
			}
		}

		foreach ( array_slice( $sections, 0, Elementor_Limits::MAX_SECTIONS ) as $section ) {
			$node = $this->section_node( $section, $plan, $assets, $responsive, 1 );
			if ( null === $node ) {
				continue;
			}
			$tree[] = $node;
		}

		if ( count( $sections ) > Elementor_Limits::MAX_SECTIONS ) {
			$this->warn( __( 'Some detected sections were not generated because the Phase 4 section limit was reached.', 'replicaforge' ) );
		}

		if ( $navigation_links > 0 ) {
			$this->warn( __( 'Mobile navigation interaction could not be reproduced using available Elementor widgets. The detected navigation links are present as editable structures, but no menu behaviour was recreated.', 'replicaforge' ) );
		}

		return array(
			'tree'     => $tree,
			'stats'    => $this->stats,
			'warnings' => array_values( array_unique( $this->warnings ) ),
		);
	}

	/**
	 * Build one section container.
	 *
	 * @param array<string, mixed> $section    Normalized section.
	 * @param array<string, mixed> $plan       Normalized plan.
	 * @param array<string, mixed> $assets     Resolved assets.
	 * @param array<string, mixed> $responsive Responsive plan.
	 * @param int                  $depth      Current depth.
	 * @return array<string, mixed>|null
	 */
	private function section_node( array $section, array $plan, array $assets, array $responsive, $depth ) {
		if ( $depth > Elementor_Limits::MAX_DEPTH ) {
			$this->warn( __( 'A section was skipped because the maximum container nesting depth was reached.', 'replicaforge' ) );
			return null;
		}

		$layout     = isset( $section['layout'] ) && is_array( $section['layout'] ) ? $section['layout'] : array();
		$columns    = isset( $layout['column_count'] ) ? max( 1, (int) $layout['column_count'] ) : 1;
		$components = isset( $section['components'] ) && is_array( $section['components'] ) ? $section['components'] : array();
		$background = null;
		$members    = array();

		foreach ( $components as $component_id ) {
			if ( ! isset( $plan['components'][ $component_id ] ) ) {
				continue;
			}
			$component = $plan['components'][ $component_id ];
			if ( 'background_image' === $component['reconstruction_type'] ) {
				$background = isset( $assets[ $component_id ] ) && is_array( $assets[ $component_id ] ) ? $assets[ $component_id ] : null;
				continue;
			}
			$members[] = $component_id;
		}

		$settings = $this->container_settings( $layout );
		$tag      = isset( $section['tag'] ) ? (string) $section['tag'] : '';
		if ( in_array( $tag, array( 'header', 'footer', 'main', 'section', 'article', 'aside', 'nav', 'div' ), true ) ) {
			$settings['html_tag'] = $tag;
		}
		if ( is_array( $background ) && '' !== (string) $background['url'] ) {
			$this->apply_background( $settings, $background );
		}

		$section_responsive = isset( $responsive['sections'][ $section['source_id'] ] ) && is_array( $responsive['sections'][ $section['source_id'] ] ) ? $responsive['sections'][ $section['source_id'] ] : array();
		$stack_devices      = array();
		foreach ( array( 'tablet', 'mobile' ) as $device ) {
			if ( isset( $section_responsive[ $device ]['columns'] ) && 1 === (int) $section_responsive[ $device ]['columns'] ) {
				$stack_devices[] = $device;
			}
		}

		$children = array();
		if ( $columns > 1 && ! empty( $members ) ) {
			$this->warn( __( 'The reconstruction specification records a column count but not column membership, so components were distributed evenly across the detected columns. Review the column order in the Elementor editor.', 'replicaforge' ) );
			$groups = $this->distribute( $members, $columns );
			foreach ( $groups as $index => $group ) {
				$column_children = $this->component_nodes( $group, $plan, $assets, $depth + 1 );
				if ( empty( $column_children ) ) {
					continue;
				}
				$column_settings = array(
					'content_width'   => 'full',
					'flex_direction'  => 'column',
					'flex_align_items' => 'stretch',
				);
				$ratio = $this->column_ratio( $layout, $index, $columns );
				if ( null !== $ratio ) {
					$column_settings['width'] = array(
						'size' => $ratio,
						'unit' => '%',
					);
					$column_settings['_flex_size'] = 'shrink';
				} else {
					$column_settings['_flex_size'] = 'grow';
				}
				foreach ( $stack_devices as $device ) {
					$column_settings[ 'width_' . $device ] = array(
						'size' => 100,
						'unit' => '%',
					);
				}
				$children[] = $this->container_node( $column_settings, $column_children, array(
					'kind'      => 'column',
					'section_id' => $section['source_id'],
					'column'    => $index + 1,
				) );
			}
			if ( ! empty( $stack_devices ) ) {
				$settings['flex_wrap'] = 'wrap';
			}
		} elseif ( ! empty( $members ) ) {
			$children = $this->component_nodes( $members, $plan, $assets, $depth + 1 );
		}

		foreach ( $stack_devices as $device ) {
			$settings[ 'flex_direction_' . $device ] = 'column';
		}

		if ( empty( $children ) ) {
			$this->warn( __( 'A detected section did not contain any generatable content and was generated as an empty editable container.', 'replicaforge' ) );
		}

		$this->stats['sections']++;
		return $this->container_node(
			$settings,
			$children,
			array(
				'kind'       => 'section',
				'section_id' => $section['source_id'],
				'plan_id'    => $section['id'],
				'type'       => $section['type'],
			)
		);
	}

	/**
	 * Map a list of component IDs into nodes.
	 *
	 * @param array<int, string>   $component_ids Component source IDs.
	 * @param array<string, mixed> $plan          Normalized plan.
	 * @param array<string, mixed> $assets        Resolved assets.
	 * @param int                  $depth         Current depth.
	 * @return array<int, array<string, mixed>>
	 */
	private function component_nodes( array $component_ids, array $plan, array $assets, $depth ) {
		$nodes = array();
		foreach ( array_slice( $component_ids, 0, Elementor_Limits::MAX_COMPONENTS ) as $component_id ) {
			if ( $this->stats['elements'] >= $this->element_budget ) {
				$this->warn( __( 'Some components were not generated because the Elementor element limit was reached.', 'replicaforge' ) );
				break;
			}
			if ( ! isset( $plan['components'][ $component_id ] ) ) {
				continue;
			}
			$node = $this->component_node( $plan['components'][ $component_id ], $plan, $assets, $depth );
			if ( null !== $node ) {
				$nodes[] = $node;
			}
		}
		return $nodes;
	}

	/**
	 * Map one component into a node.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @param array<string, mixed> $plan      Normalized plan.
	 * @param array<string, mixed> $assets    Resolved assets.
	 * @param int                  $depth     Current depth.
	 * @return array<string, mixed>|null
	 */
	private function component_node( array $component, array $plan, array $assets, $depth ) {
		$this->stats['components']++;

		if ( isset( $component['confidence'] ) && (float) $component['confidence'] < 0.5 ) {
			$this->stats['low_confidence']++;
			$this->warn( __( 'Some detected components have low reconstruction confidence. Their structure is a best-effort approximation and should be reviewed in the Elementor editor.', 'replicaforge' ) );
		}

		$mapping = $this->registry->resolve( $component['reconstruction_type'] );
		if ( ! empty( $mapping['fallback'] ) && '' !== $mapping['reason'] ) {
			$this->stats['fallbacks']++;
			$this->warn( $mapping['reason'] );
		}

		$content = $this->content_for( $component, $plan );

		switch ( $mapping['kind'] ) {
			case 'attached':
				return null;
			case 'skip':
				$this->stats['skipped']++;
				$this->warn( $mapping['reason'] );
				return null;
			case 'container':
				return $this->structural_node( $component, $content, $plan, $assets, $depth );
			case 'widget':
				return $this->widget_node( $component, $content, $mapping['widget'], $plan, $assets );
		}

		$this->stats['skipped']++;
		return null;
	}

	/**
	 * Build a widget node for a leaf component.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @param array<string, mixed> $content   Normalized content.
	 * @param string               $widget    Elementor widget name.
	 * @param array<string, mixed> $plan      Normalized plan.
	 * @param array<string, mixed> $assets    Resolved assets.
	 * @return array<string, mixed>|null
	 */
	private function widget_node( array $component, array $content, $widget, array $plan, array $assets ) {
		// Every widget passes through this one method, so the availability check
		// belongs here rather than at each call site. A call site that names an
		// unavailable widget therefore falls back instead of writing a widget the
		// current Elementor build does not have.
		$requested = (string) $widget;
		if ( ! $this->registry->is_allowed_widget( $requested ) ) {
			$fallback = $this->registry->is_allowed_widget( 'text-editor' ) ? 'text-editor' : '';
			if ( '' === $fallback ) {
				// No widget at all is available, so the component is dropped rather
				// than written as something the current Elementor build cannot open.
				$this->stats['skipped']++;
				$this->warn(
					sprintf(
						/* translators: %s: Requested widget name. */
						__( 'The %s widget is unavailable and no text widget could replace it, so the component was not generated.', 'replicaforge' ),
						$requested
					)
				);
				return null;
			}
			$this->stats['fallbacks']++;
			$this->warn(
				sprintf(
					/* translators: 1: Requested widget, 2: Fallback widget. */
					__( 'The %1$s widget is unavailable, so the %2$s widget was used instead.', 'replicaforge' ),
					$requested,
					$fallback
				)
			);
			$widget = $fallback;
		}

		$settings = array();
		$design   = isset( $plan['design'] ) && is_array( $plan['design'] ) ? $plan['design'] : array();

		switch ( $widget ) {
			case 'heading':
				$text = $this->component_text( $component, $content );
				if ( null === $text ) {
					$this->stats['skipped']++;
					$this->warn( __( 'A detected heading had no text in the reconstruction specification and was not generated.', 'replicaforge' ) );
					return null;
				}
				$settings['title']       = $text;
				$settings['header_size'] = $this->heading_size( $component );
				$this->apply_typography( $settings, $design, $this->typography_role( $component ), $widget );
				break;

			case 'text-editor':
				$text = $this->component_text( $component, $content );
				if ( null === $text ) {
					$this->stats['skipped']++;
					$this->warn( __( 'A detected text component had no text in the reconstruction specification and was not generated.', 'replicaforge' ) );
					return null;
				}
				$settings['editor'] = $text;
				$this->apply_typography( $settings, $design, 'body', $widget );
				break;

			case 'button':
				$text = $this->component_text( $component, $content );
				if ( null === $text && isset( $content['fields']['button'] ) && is_string( $content['fields']['button'] ) ) {
					$text = $content['fields']['button'];
				}
				if ( null === $text ) {
					$this->stats['skipped']++;
					$this->warn( __( 'A detected link or button had no label in the reconstruction specification and was not generated.', 'replicaforge' ) );
					return null;
				}
				$settings['text'] = $text;
				$link             = $this->link_setting( $content );
				if ( '' !== $link ) {
					$settings['link'] = $link;
				}
				$this->apply_typography( $settings, $design, 'button', $widget );
				break;

			case 'image':
				$image = $this->image_settings( $component, $content, $assets );
				if ( null === $image ) {
					$this->stats['skipped']++;
					$this->warn( __( 'A detected image had no usable source in the reconstruction specification and was generated as a placeholder.', 'replicaforge' ) );
					$settings['image'] = array( 'url' => $this->placeholder_image() );
				} else {
					$settings['image'] = $image;
				}
				break;

			case 'divider':
				break;

			case 'spacer':
				$settings['space'] = array(
					'size' => 20,
					'unit' => 'px',
				);
				$this->warn( __( 'Spacer sizes were not part of the reconstruction specification, so default Elementor spacing was used.', 'replicaforge' ) );
				break;

			case 'icon':
				$this->stats['skipped']++;
				$this->warn( __( 'Icon components were not generated because the source icon identity could not be verified from the reconstruction specification.', 'replicaforge' ) );
				return null;

			default:
				$this->stats['skipped']++;
				$this->warn( __( 'A component type could not be mapped to an available Elementor widget and was not generated.', 'replicaforge' ) );
				return null;
		}

		$this->stats['widgets']++;
		$this->stats['elements']++;

		return array(
			'kind'     => 'widget',
			'widget'   => $widget,
			'settings' => $settings,
			'children' => array(),
			'meta'     => array(
				'kind'         => 'component',
				'component_id' => $component['source_id'],
				'plan_id'      => $component['id'],
				'type'         => $component['reconstruction_type'],
				'confidence'   => $component['confidence'],
			),
		);
	}

	/**
	 * Build a container structure for a non-leaf component.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @param array<string, mixed> $content   Normalized content.
	 * @param array<string, mixed> $plan      Normalized plan.
	 * @param array<string, mixed> $assets    Resolved assets.
	 * @param int                  $depth     Current depth.
	 * @return array<string, mixed>|null
	 */
	private function structural_node( array $component, array $content, array $plan, array $assets, $depth ) {
		$type = $component['reconstruction_type'];

		if ( $depth > Elementor_Limits::MAX_DEPTH ) {
			$this->stats['skipped']++;
			$this->warn( __( 'A component was skipped because the maximum container nesting depth was reached.', 'replicaforge' ) );
			return null;
		}

		switch ( $type ) {
			case 'repeated_card_group':
				$children = $this->group_node_children( $component, $plan, $assets, $depth );
				return $this->container_node(
					array(
						'content_width'    => 'full',
						'flex_direction'   => 'row',
						'flex_wrap'        => 'wrap',
						'flex_align_items' => 'stretch',
					),
					$children,
					array(
						'kind'         => 'card_group',
						'component_id' => $component['source_id'],
						'plan_id'      => $component['id'],
					)
				);

			case 'gallery':
				$children = $this->gallery_children( $component, $content, $plan, $assets, $depth );
				if ( empty( $children ) ) {
					$this->stats['skipped']++;
					return null;
				}
				return $this->container_node(
					array(
						'content_width'    => 'full',
						'flex_direction'   => 'row',
						'flex_wrap'        => 'wrap',
						'flex_align_items' => 'stretch',
					),
					$children,
					array(
						'kind'         => 'gallery',
						'component_id' => $component['source_id'],
						'plan_id'      => $component['id'],
					)
				);

			case 'form':
				$children = array();
				$text     = $this->component_text( $component, $content );
				if ( null !== $text && $this->registry->is_allowed_widget( 'heading' ) ) {
					$node = $this->widget_node( $component, $content, 'heading', $plan, $assets );
					if ( null !== $node ) {
						$children[] = $node;
					}
				}
				$this->warn( __( 'The detected form was converted into a safe structural representation. No form endpoint, field name, or credential from the source website was copied, and no submission target was recreated.', 'replicaforge' ) );
				return $this->container_node(
					array(
						'content_width'  => 'boxed',
						'flex_direction' => 'column',
					),
					$children,
					array(
						'kind'         => 'form',
						'component_id' => $component['source_id'],
						'plan_id'      => $component['id'],
					)
				);

			case 'accordion':
			case 'tabs':
			case 'tab':
				$children = array();
				$text     = $this->component_text( $component, $content );
				if ( null !== $text ) {
					$node = $this->widget_node( $component, $content, 'text-editor', $plan, $assets );
					if ( null !== $node ) {
						$children[] = $node;
					}
				}
				$this->warn( __( 'Interactive source behaviour such as accordions and tabs was not reproduced. The detected content is present as editable text.', 'replicaforge' ) );
				return $this->container_node(
					array(
						'content_width'  => 'boxed',
						'flex_direction' => 'column',
					),
					$children,
					array(
						'kind'         => $type,
						'component_id' => $component['source_id'],
						'plan_id'      => $component['id'],
					)
				);

			case 'form_field':
				$text = $this->component_text( $component, $content );
				if ( null === $text ) {
					$this->stats['skipped']++;
					return null;
				}
				$this->warn( __( 'Detected form fields were rendered as read-only text. No input, hidden token, or submission endpoint from the source website was copied.', 'replicaforge' ) );
				return $this->widget_node( $component, $content, 'text-editor', $plan, $assets );

			default:
				return $this->card_node( $component, $content, $plan, $assets, $depth );
		}
	}

	/**
	 * Build a card container with independent, editable children.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @param array<string, mixed> $content   Normalized content.
	 * @param array<string, mixed> $plan      Normalized plan.
	 * @param array<string, mixed> $assets    Resolved assets.
	 * @param int                  $depth     Current depth.
	 * @return array<string, mixed>|null
	 */
	private function card_node( array $component, array $content, array $plan, array $assets, $depth ) {
		$fields    = isset( $content['fields'] ) && is_array( $content['fields'] ) ? $content['fields'] : array();
		$image     = null;
		$title     = null;
		$body      = null;
		$meta      = array();
		$button    = null;
		$link      = null;

		$image_value = $this->first_field( $fields, array( 'image', 'thumbnail', 'photo' ) );
		if ( null !== $image_value && $this->registry->is_allowed_widget( 'image' ) ) {
			$image = $image_value;
		}

		$title = $this->first_field( $fields, array( 'title', 'heading', 'name', 'product_name' ) );
		if ( null === $title ) {
			$title = $this->component_text( $component, $content );
		}

		$body = $this->first_field( $fields, array( 'description', 'summary', 'excerpt', 'text', 'content' ) );

		foreach ( array( 'price', 'sale_price', 'badge', 'rating', 'category', 'author', 'date' ) as $key ) {
			if ( isset( $fields[ $key ] ) && is_string( $fields[ $key ] ) && '' !== $fields[ $key ] ) {
				$meta[] = $fields[ $key ];
			}
		}

		$button = $this->first_field( $fields, array( 'button', 'cta', 'action' ) );
		$link   = $this->first_field( $fields, array( 'link', 'url' ) );

		$children = array();

		if ( null !== $image ) {
			$node = $this->image_node( $component, $content, $assets, $plan, $image );
			if ( null !== $node ) {
				$children[] = $node;
			}
		}

		if ( null !== $title ) {
			$node = $this->text_node( $component, $plan, $assets, $title, 'heading', 'h3' );
			if ( null !== $node ) {
				$children[] = $node;
			}
		}

		if ( null !== $body && $body !== $title ) {
			$node = $this->text_node( $component, $plan, $assets, $body, 'text-editor' );
			if ( null !== $node ) {
				$children[] = $node;
			}
		}

		foreach ( $meta as $value ) {
			$node = $this->text_node( $component, $plan, $assets, $value, 'text-editor' );
			if ( null !== $node ) {
				$children[] = $node;
			}
		}

		if ( null !== $button ) {
			$button_content = array(
				'text'          => $button,
				'link'          => is_string( $link ) && '' !== $link ? $link : null,
				'link_known'    => null !== $link,
				'link_reported' => null !== $link,
				'fields'        => array(),
			);
			$node = $this->widget_node( $component, $button_content, $this->registry->is_allowed_widget( 'button' ) ? 'button' : 'text-editor', $plan, $assets );
			if ( null !== $node ) {
				$children[] = $node;
			}
		}

		if ( empty( $children ) ) {
			$this->stats['skipped']++;
			$this->warn( __( 'A detected card had no usable content in the reconstruction specification and was not generated.', 'replicaforge' ) );
			return null;
		}

		return $this->container_node(
			array(
				'content_width'    => 'boxed',
				'flex_direction'   => 'column',
				'flex_align_items' => 'stretch',
			),
			$children,
			array(
				'kind'         => 'card',
				'component_id' => $component['source_id'],
				'plan_id'      => $component['id'],
				'type'         => $component['reconstruction_type'],
			)
		);
	}

	/**
	 * Build the children of a repeated card group.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @param array<string, mixed> $plan      Normalized plan.
	 * @param array<string, mixed> $assets    Resolved assets.
	 * @param int                  $depth     Current depth.
	 * @return array<int, array<string, mixed>>
	 */
	private function group_node_children( array $component, array $plan, array $assets, $depth ) {
		$children = array();
		$members  = isset( $component['children'] ) && is_array( $component['children'] ) ? $component['children'] : array();

		foreach ( array_slice( $members, 0, Elementor_Limits::MAX_CARD_CHILDREN ) as $member ) {
			if ( ! isset( $plan['components'][ $member ] ) ) {
				continue;
			}
			$child = $plan['components'][ $member ];
			$node  = $this->structural_node( $child, $this->content_for( $child, $plan ), $plan, $assets, $depth + 1 );
			if ( null !== $node ) {
				$children[] = $node;
			}
		}

		if ( empty( $children ) && ! empty( $members ) ) {
			$this->warn( __( 'A repeated card group did not contain generatable member components.', 'replicaforge' ) );
		}

		return $children;
	}

	/**
	 * Build the children of a gallery component.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @param array<string, mixed> $content   Normalized content.
	 * @param array<string, mixed> $plan      Normalized plan.
	 * @param array<string, mixed> $assets    Resolved assets.
	 * @param int                  $depth     Current depth.
	 * @return array<int, array<string, mixed>>
	 */
	private function gallery_children( array $component, array $content, array $plan, array $assets, $depth ) {
		$children = array();
		$members  = isset( $component['children'] ) && is_array( $component['children'] ) ? $component['children'] : array();
		foreach ( array_slice( $members, 0, Elementor_Limits::MAX_CARD_CHILDREN ) as $member ) {
			if ( ! isset( $plan['components'][ $member ] ) ) {
				continue;
			}
			$child    = $plan['components'][ $member ];
			$settings = $this->image_settings( $child, $this->content_for( $child, $plan ), $assets );
			if ( null === $settings ) {
				continue;
			}
			$this->stats['widgets']++;
			$this->stats['elements']++;
			$children[] = array(
				'kind'     => 'widget',
				'widget'   => 'image',
				'settings' => array( 'image' => $settings ),
				'children' => array(),
				'meta'     => array(
					'kind'         => 'component',
					'component_id' => $child['source_id'],
					'plan_id'      => $child['id'],
					'type'         => $child['reconstruction_type'],
					'confidence'   => $child['confidence'],
				),
			);
		}

		if ( empty( $children ) ) {
			$node = $this->image_node( $component, $content, $assets, $plan, null );
			if ( null !== $node ) {
				$children[] = $node;
			}
		}

		return $children;
	}

	/**
	 * Build a plain text widget node.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @param array<string, mixed> $plan      Normalized plan.
	 * @param array<string, mixed> $assets    Resolved assets.
	 * @param string               $text      Safe text.
	 * @param string               $widget    Widget name.
	 * @param string               $tag       Optional heading tag.
	 * @return array<string, mixed>|null
	 */
	private function text_node( array $component, array $plan, array $assets, $text, $widget, $tag = '' ) {
		$content = array(
			'text'          => $text,
			'alt'           => null,
			'link'          => null,
			'link_known'    => false,
			'link_reported' => false,
			'fields'        => array(),
		);

		$component_for_node                        = $component;
		$component_for_node['reconstruction_type'] = 'heading' === $widget ? 'heading' : 'paragraph';
		if ( '' !== $tag ) {
			$component_for_node['tag'] = $tag;
		}

		return $this->widget_node( $component_for_node, $content, $widget, $plan, $assets );
	}

	/**
	 * Build an image widget node.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @param array<string, mixed> $content   Normalized content.
	 * @param array<string, mixed> $assets    Resolved assets.
	 * @param array<string, mixed> $plan      Normalized plan.
	 * @param string|null          $fallback  Optional fallback image URL.
	 * @return array<string, mixed>|null
	 */
	private function image_node( array $component, array $content, array $assets, array $plan, $fallback ) {
		$settings = $this->image_settings( $component, $content, $assets );
		if ( null === $settings && is_string( $fallback ) && '' !== $fallback && $this->is_referenceable_media_url( $fallback ) ) {
			$settings = array( 'url' => $fallback );
		}
		if ( null === $settings ) {
			return null;
		}

		$this->stats['widgets']++;
		$this->stats['elements']++;

		return array(
			'kind'     => 'widget',
			'widget'   => 'image',
			'settings' => array( 'image' => $settings ),
			'children' => array(),
			'meta'     => array(
				'kind'         => 'component',
				'component_id' => $component['source_id'],
				'plan_id'      => $component['id'],
				'type'         => 'image',
				'confidence'   => $component['confidence'],
			),
		);
	}

	/**
	 * Build the image control value for a component.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @param array<string, mixed> $content   Normalized content.
	 * @param array<string, mixed> $assets    Resolved assets.
	 * @return array<string, mixed>|null
	 */
	private function image_settings( array $component, array $content, array $assets ) {
		$source_id = $component['source_id'];
		$asset     = isset( $assets[ $source_id ] ) && is_array( $assets[ $source_id ] ) ? $assets[ $source_id ] : null;

		$url = '';
		$id  = 0;
		if ( null !== $asset ) {
			$url = (string) $asset['url'];
			$id  = (int) $asset['attachment_id'];
		} elseif ( isset( $component['image']['src'] ) && is_string( $component['image']['src'] ) && '' !== $component['image']['src'] ) {
			$url = (string) $component['image']['src'];
		}

		if ( '' === $url || ! $this->is_usable_asset_url( $url ) ) {
			return null;
		}

		$settings = array( 'url' => $url );
		if ( $id > 0 ) {
			$settings['id'] = $id;
		}
		return $settings;
	}

	/**
	 * Return true when a resolved asset URL may be written into a document.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_usable_asset_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		if ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) ) {
			return Security::is_safe_public_reference( $url );
		}
		// Local uploads are only trusted when the path stays inside uploads.
		$uploads = wp_get_upload_dir();
		if ( ! is_array( $uploads ) || empty( $uploads['baseurl'] ) ) {
			return false;
		}
		return 0 === strpos( $url, (string) $uploads['baseurl'] );
	}

	/**
	 * Return true when a remote URL may be referenced as an image source.
	 *
	 * The same conservative media policy used by the asset layer is applied so a
	 * field-level image URL cannot bypass the type checks.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_referenceable_media_url( $url ) {
		if ( ! $this->is_usable_asset_url( $url ) || 0 !== strpos( $url, 'http' ) ) {
			return false;
		}
		$parts = Security::parse_url( $url );
		$path  = is_array( $parts ) && ! empty( $parts['path'] ) ? (string) $parts['path'] : '';
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $extension ) {
			return true;
		}
		return in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true );
	}

	/**
	 * Return the Elementor placeholder image URL.
	 *
	 * @return string
	 */
	private function placeholder_image() {
		if ( class_exists( '\Elementor\Utils' ) && method_exists( '\Elementor\Utils', 'get_placeholder_image_src' ) ) {
			$placeholder = \Elementor\Utils::get_placeholder_image_src();
			if ( is_string( $placeholder ) && '' !== $placeholder ) {
				return $placeholder;
			}
		}
		return includes_url( 'images/media/default.png' );
	}

	/**
	 * Return the normalized content block of a component.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @param array<string, mixed> $plan      Normalized plan.
	 * @return array<string, mixed>
	 */
	private function content_for( array $component, array $plan ) {
		$source_id = $component['source_id'];
		$content   = array(
			'text'          => null,
			'alt'           => null,
			'link'          => null,
			'link_known'    => false,
			'link_reported' => false,
			'fields'        => array(),
		);
		if ( isset( $plan['content'][ $source_id ] ) && is_array( $plan['content'][ $source_id ] ) ) {
			$content = array_merge( $content, $plan['content'][ $source_id ] );
		}

		// The component record carries detected product and card fields that the
		// content mapping intentionally omits, such as image and link targets.
		if ( ! empty( $component['fields'] ) && is_array( $component['fields'] ) ) {
			$fields = isset( $content['fields'] ) && is_array( $content['fields'] ) ? $content['fields'] : array();
			foreach ( $component['fields'] as $key => $value ) {
				if ( ! array_key_exists( $key, $fields ) || null === $fields[ $key ] ) {
					$fields[ $key ] = $value;
				}
			}
			$content['fields'] = $fields;
		}

		if ( null === $content['alt'] && ! empty( $component['image']['alt'] ) ) {
			$content['alt'] = $component['image']['alt'];
		}
		if ( ! $content['link_reported'] && ! empty( $component['target'] ) ) {
			$content['link']          = $component['target'];
			$content['link_known']    = true;
			$content['link_reported'] = true;
		}

		return $content;
	}

	/**
	 * Resolve the display text of a component.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @param array<string, mixed> $content   Normalized content.
	 * @return string|null
	 */
	private function component_text( array $component, array $content ) {
		$text = isset( $content['text'] ) && is_string( $content['text'] ) && '' !== $content['text'] ? $content['text'] : null;
		if ( null !== $text ) {
			return $text;
		}
		foreach ( array( 'title', 'label', 'name', 'button' ) as $key ) {
			if ( isset( $content['fields'][ $key ] ) && is_string( $content['fields'][ $key ] ) && '' !== $content['fields'][ $key ] ) {
				return $content['fields'][ $key ];
			}
		}
		return null;
	}

	/**
	 * Return the first available field from a list of candidates.
	 *
	 * @param array<string, mixed> $fields    Field map.
	 * @param array<int, string>   $candidates Candidate keys.
	 * @return string|null
	 */
	private function first_field( array $fields, array $candidates ) {
		foreach ( $candidates as $candidate ) {
			if ( isset( $fields[ $candidate ] ) && is_string( $fields[ $candidate ] ) && '' !== $fields[ $candidate ] ) {
				return $fields[ $candidate ];
			}
		}
		return null;
	}

	/**
	 * Build an Elementor URL control value.
	 *
	 * @param array<string, mixed> $content Normalized content.
	 * @return string
	 */
	private function link_setting( array $content ) {
		$url = isset( $content['link'] ) && is_string( $content['link'] ) ? $content['link'] : '';
		if ( '' !== $url && Security::is_safe_public_reference( $url ) ) {
			return $url;
		}
		$reported = ! empty( $content['link_reported'] );
		$known    = ! empty( $content['link_known'] );
		if ( $reported && ! $known ) {
			$this->warn( __( 'A detected link had no verifiable target in the reconstruction specification. The Elementor link was left as a placeholder instead of an invented destination.', 'replicaforge' ) );
			return '#';
		}
		return '';
	}

	/**
	 * Return the heading tag for a component.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @return string
	 */
	private function heading_size( array $component ) {
		$tag = isset( $component['tag'] ) ? (string) $component['tag'] : '';
		if ( preg_match( '/^h[1-6]$/', $tag ) ) {
			return $tag;
		}
		$semantic = isset( $component['semantic_role'] ) ? (string) $component['semantic_role'] : '';
		if ( 'hero_or_page_heading' === $semantic ) {
			return 'h1';
		}
		if ( 'section_heading' === $semantic ) {
			return 'h2';
		}
		if ( 'subheading' === $semantic ) {
			return 'h3';
		}
		return 'h2';
	}

	/**
	 * Return the design-system typography role for a component.
	 *
	 * @param array<string, mixed> $component Normalized component.
	 * @return string
	 */
	private function typography_role( array $component ) {
		$tag = isset( $component['tag'] ) ? (string) $component['tag'] : '';
		if ( preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
			return 'heading_' . $matches[1];
		}
		$semantic = isset( $component['semantic_role'] ) ? (string) $component['semantic_role'] : '';
		if ( 'hero_or_page_heading' === $semantic ) {
			return 'heading_1';
		}
		if ( 'section_heading' === $semantic ) {
			return 'heading_2';
		}
		if ( 'subheading' === $semantic ) {
			return 'heading_3';
		}
		return '';
	}

	/**
	 * Apply a design-system typography token to a widget.
	 *
	 * @param array<string, mixed> $settings Settings, by reference.
	 * @param array<string, mixed> $design   Design tokens.
	 * @param string               $role     Typography role.
	 * @param string               $widget   Widget name.
	 * @return void
	 */
	private function apply_typography( array &$settings, array $design, $role, $widget ) {
		$token = null;
		if ( '' !== $role && isset( $design['typography'][ $role ] ) && is_array( $design['typography'][ $role ] ) ) {
			$token = $design['typography'][ $role ];
		} elseif ( isset( $design['typography']['body'] ) && is_array( $design['typography']['body'] ) ) {
			$role  = 'body';
			$token = $design['typography']['body'];
		} else {
			$role = '';
		}

		if ( null === $token ) {
			$role = '';
		}

		$prefix = 'typography_';
		$has    = false;
		if ( isset( $token['font_family'] ) ) {
			$settings[ $prefix . 'font_family' ] = $token['font_family'];
			$has                                 = true;
		}
		if ( isset( $token['font_size'] ) ) {
			$settings[ $prefix . 'font_size' ] = Elementor_Values::slider( $token['font_size'] );
			$has                                = true;
		}
		if ( isset( $token['font_weight'] ) ) {
			$settings[ $prefix . 'font_weight' ] = is_string( $token['font_weight'] ) ? $token['font_weight'] : (string) (int) $token['font_weight'];
			$has                                  = true;
		}
		if ( isset( $token['line_height'] ) ) {
			$settings[ $prefix . 'line_height' ] = array(
				'size' => (float) $token['line_height'],
				'unit' => 'em',
			);
			$has = true;
		}
		if ( $has ) {
			$settings[ $prefix . 'typography' ] = 'custom';
		}

		foreach ( $this->color_roles( $role, $widget ) as $color_role ) {
			$this->apply_color( $settings, $design, $color_role, $prefix . 'color' );
			if ( isset( $settings[ $prefix . 'color' ] ) ) {
				break;
			}
		}
	}

	/**
	 * Return the color roles a widget may inherit, most preferred first.
	 *
	 * @param string $role   Typography role.
	 * @param string $widget Widget name.
	 * @return array<int, string>
	 */
	private function color_roles( $role, $widget ) {
		if ( 'button' === $widget ) {
			return array( 'primary', 'accent' );
		}
		if ( 0 === strpos( (string) $role, 'heading_' ) ) {
			return array( 'heading', 'text', 'primary' );
		}
		return array( 'text', 'primary' );
	}

	/**
	 * Apply a color role to a widget.
	 *
	 * @param array<string, mixed> $settings Settings, by reference.
	 * @param array<string, mixed> $design   Design tokens.
	 * @param string               $role     Color role.
	 * @param string               $key      Setting key that stores the color.
	 * @return void
	 */
	private function apply_color( array &$settings, array $design, $role, $key ) {
		if ( isset( $design['colors'][ $role ]['value'] ) && is_string( $design['colors'][ $role ]['value'] ) ) {
			$settings[ $key ] = $design['colors'][ $role ]['value'];
		}
	}

	/**
	 * Apply a resolved background asset to a container.
	 *
	 * @param array<string, mixed> $settings Settings, by reference.
	 * @param array<string, mixed> $asset    Resolved asset.
	 * @return void
	 */
	private function apply_background( array &$settings, array $asset ) {
		$settings['background_background'] = 'classic';
		$settings['background_image']      = array(
			'url' => (string) $asset['url'],
		);
		if ( ! empty( $asset['attachment_id'] ) ) {
			$settings['background_image']['id'] = (int) $asset['attachment_id'];
		}
		$settings['background_size']        = isset( $asset['size'] ) && is_string( $asset['size'] ) && '' !== $asset['size'] ? $asset['size'] : 'cover';
		$settings['background_position']    = 'center center';
		$settings['background_repeat']      = 'no-repeat';
		$settings['background_attachment']  = 'scroll';
	}

	/**
	 * Build the flex settings for a section or column container.
	 *
	 * @param array<string, mixed> $layout Normalized layout.
	 * @return array<string, mixed>
	 */
	private function container_settings( array $layout ) {
		$settings = array(
			'content_width'  => 'full',
			'flex_direction' => isset( $layout['direction'] ) ? (string) $layout['direction'] : 'column',
		);

		if ( isset( $layout['gap'] ) && is_string( $layout['gap'] ) && '' !== $layout['gap'] ) {
			$settings['flex_gap'] = Elementor_Values::gaps( $layout['gap'] );
		}
		if ( isset( $layout['justify'] ) && is_string( $layout['justify'] ) && '' !== $layout['justify'] ) {
			$settings['flex_justify_content'] = $layout['justify'];
		}
		if ( isset( $layout['align_items'] ) && is_string( $layout['align_items'] ) && '' !== $layout['align_items'] ) {
			$settings['flex_align_items'] = $layout['align_items'];
		}
		if ( isset( $layout['wrap'] ) && is_string( $layout['wrap'] ) && '' !== $layout['wrap'] ) {
			$settings['flex_wrap'] = $layout['wrap'];
		}

		$width = '';
		if ( isset( $layout['max_width'] ) && is_string( $layout['max_width'] ) && '' !== $layout['max_width'] ) {
			$width = $layout['max_width'];
		}
		if ( '' !== $width ) {
			$settings['content_width'] = 'boxed';
			$settings['boxed_width']  = Elementor_Values::slider( $width );
		}

		// Padding, margin, and minimum height are only written when the
		// specification actually carries measured values. Nothing is invented.
		if ( isset( $layout['padding'] ) && is_string( $layout['padding'] ) && '' !== $layout['padding'] ) {
			$box = Elementor_Values::dimensions( $layout['padding'] );
			if ( null !== $box ) {
				$settings['padding'] = $box;
			}
		}
		if ( isset( $layout['margin'] ) && is_string( $layout['margin'] ) && '' !== $layout['margin'] ) {
			$box = Elementor_Values::dimensions( $layout['margin'] );
			if ( null !== $box ) {
				$settings['margin'] = $box;
			}
		}
		if ( isset( $layout['min_height'] ) && is_string( $layout['min_height'] ) && '' !== $layout['min_height'] ) {
			$settings['min_height'] = Elementor_Values::slider( $layout['min_height'] );
		}

		return $settings;
	}

	/**
	 * Build a container node and count it.
	 *
	 * @param array<string, mixed> $settings Container settings.
	 * @param array<int, mixed>    $children Child nodes.
	 * @param array<string, mixed> $meta     Node metadata.
	 * @return array<string, mixed>
	 */
	private function container_node( array $settings, array $children, array $meta ) {
		$this->stats['containers']++;
		$this->stats['elements']++;

		return array(
			'kind'     => 'container',
			'widget'   => 'container',
			'settings' => $settings,
			'children' => array_values( $children ),
			'meta'     => $meta,
		);
	}

	/**
	 * Distribute component IDs across a column count.
	 *
	 * @param array<int, string> $members Component IDs.
	 * @param int                $columns Column count.
	 * @return array<int, array<int, string>>
	 */
	private function distribute( array $members, $columns ) {
		$columns = max( 1, (int) $columns );
		$groups  = array_fill( 0, $columns, array() );
		$index   = 0;
		foreach ( $members as $member ) {
			$groups[ $index % $columns ][] = $member;
			$index++;
		}
		return $groups;
	}

	/**
	 * Return the measured column width ratio for a column index.
	 *
	 * @param array<string, mixed> $layout Normalized layout.
	 * @param int                  $index  Column index.
	 * @param int                  $total  Column count.
	 * @return float|null
	 */
	private function column_ratio( array $layout, $index, $total ) {
		if ( ! isset( $layout['columns'] ) || ! is_array( $layout['columns'] ) ) {
			return null;
		}
		$columns = $layout['columns'];
		if ( count( $columns ) !== $total || ! isset( $columns[ $index ] ) ) {
			return null;
		}
		$ratio = (float) $columns[ $index ];
		if ( $ratio <= 0 || $ratio > 1 ) {
			return null;
		}
		return round( $ratio * 100, 2 );
	}

	/**
	 * Record a warning once.
	 *
	 * @param string $message Warning message.
	 * @return void
	 */
	private function warn( $message ) {
		$message = is_string( $message ) ? trim( $message ) : '';
		if ( '' === $message ) {
			return;
		}
		if ( ! in_array( $message, $this->warnings, true ) ) {
			$this->warnings[] = $message;
		}
	}
}
