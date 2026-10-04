<?php
/**
 * Static layout, flexbox, grid, and container analysis.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Produces structural layout signals without a browser rendering engine.
 */
final class Layout_Analyzer {

	/**
	 * DOM analyzer.
	 *
	 * @var Dom_Analyzer
	 */
	private $dom;

	/**
	 * Style analyzer.
	 *
	 * @var Style_Analyzer
	 */
	private $style;

	/**
	 * Constructor.
	 *
	 * @param Dom_Analyzer|null   $dom   DOM analyzer.
	 * @param Style_Analyzer|null $style Style analyzer.
	 */
	public function __construct( $dom = null, $style = null ) {
		$this->dom   = $dom instanceof Dom_Analyzer ? $dom : new Dom_Analyzer();
		$this->style = $style instanceof Style_Analyzer ? $style : new Style_Analyzer();
	}

	/**
	 * Analyze page and section layouts.
	 *
	 * @param array<string, mixed>                $context  DOM context.
	 * @param array<int, array<string, mixed>>    $sections Sections.
	 * @return array{page: array<string, mixed>, sections: array<string, array<string, mixed>>}
	 */
	public function analyze( $context, $sections ) {
		$body_id = $this->find_body( $context );
		$page = $body_id ? $this->analyze_node( $context, $body_id ) : array(
			'type'       => 'unknown',
			'display'    => null,
			'columns'    => array(),
			'column_count' => 0,
			'container'  => array(),
			'confidence' => 0.0,
		);

		$section_layouts = array();
		foreach ( $sections as $section ) {
			if ( empty( $section['node_id'] ) ) {
				continue;
			}
			$section_layouts[ $section['node_id'] ] = $this->analyze_node( $context, $section['node_id'] );
		}

		return array( 'page' => $page, 'sections' => $section_layouts );
	}

	/**
	 * Analyze one node's layout.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @param string               $node_id Node ID.
	 * @return array<string, mixed>
	 */
	public function analyze_node( $context, $node_id ) {
		$declarations = $this->style->get_declarations( $this->dom, $context, $node_id );
		$children     = array_values(
			array_filter(
				$this->dom->get_child_ids( $context, $node_id ),
				function ( $child_id ) use ( $context ) {
					$node = $this->dom->get_node( $context, $child_id );
					return ! empty( $node['visible'] );
				}
			)
		);
		$display = isset( $declarations['display'] ) ? strtolower( $declarations['display'] ) : null;
		$grid_columns = isset( $declarations['grid-template-columns'] ) ? $declarations['grid-template-columns'] : null;
		$flex_direction = isset( $declarations['flex-direction'] ) ? strtolower( $declarations['flex-direction'] ) : null;
		$hint = $this->dom->get_hint_text( $context, $node_id );
		$column_count = $this->count_columns( $grid_columns, $children, $declarations, $hint );
		$type = $this->classify_layout( $display, $column_count, $children, $context, $node_id );
		$columns = array();
		foreach ( $children as $child_id ) {
			$child_declarations = $this->style->get_declarations( $this->dom, $context, $child_id );
			$ratio = $this->width_ratio( $child_declarations );
			if ( null !== $ratio ) {
				$columns[] = array( 'node_id' => $child_id, 'width_ratio' => $ratio );
			}
		}
		if ( empty( $columns ) && $column_count > 0 ) {
			$ratio = $column_count > 0 ? round( 1 / $column_count, 2 ) : null;
			for ( $index = 0; $index < min( $column_count, 6 ); $index++ ) {
				$columns[] = array( 'node_id' => null, 'width_ratio' => $ratio );
			}
		} elseif ( 0 === $column_count && ! in_array( $display, array( 'flex', 'grid' ), true ) ) {
			$columns = array();
		}

		$container = array(
			'width'     => isset( $declarations['width'] ) ? $declarations['width'] : null,
			'max_width' => isset( $declarations['max-width'] ) ? $declarations['max-width'] : null,
			'centered'  => $this->is_centered( $declarations ),
		);

		$detected = null !== $display || null !== $grid_columns || null !== $flex_direction;
		return array(
			'type'          => $type,
			'display'       => $display,
			'direction'     => $flex_direction,
			'alignment'     => array(
				'horizontal' => isset( $declarations['justify-content'] ) ? $declarations['justify-content'] : null,
				'vertical'   => isset( $declarations['align-items'] ) ? $declarations['align-items'] : null,
			),
			'wrap'          => isset( $declarations['flex-wrap'] ) ? $declarations['flex-wrap'] : null,
			'gap'           => isset( $declarations['gap'] ) ? $declarations['gap'] : ( isset( $declarations['column-gap'] ) ? $declarations['column-gap'] : null ),
			'grid_columns'  => $grid_columns,
			'grid_rows'     => isset( $declarations['grid-template-rows'] ) ? $declarations['grid-template-rows'] : null,
			'column_count'  => $column_count,
			'columns'       => $columns,
			'container'     => $container,
			'source'        => $this->dom->get_source( $context, $node_id ),
			'confidence'    => $detected ? 0.84 : ( $column_count > 0 ? 0.58 : 0.2 ),
			'source_type'   => $detected ? 'detected' : 'inferred',
		);
	}

	/**
	 * Count likely layout columns from CSS or direct children.
	 *
	 * @param string|null         $grid_columns Grid template value.
	 * @param array<int, string>  $children     Child IDs.
	 * @param array<string,string> $declarations Node declarations.
	 * @param string               $hint         Class/id hint.
	 * @return int
	 */
	private function count_columns( $grid_columns, $children, $declarations, $hint = '' ) {
		if ( is_string( $grid_columns ) && '' !== $grid_columns ) {
			if ( false !== stripos( $grid_columns, 'auto-fit' ) || false !== stripos( $grid_columns, 'auto-fill' ) ) {
				return 0;
			}
			if ( preg_match( '/repeat\s*\(\s*(\d+)\s*,/i', $grid_columns, $match ) ) {
				return min( 12, max( 1, (int) $match[1] ) );
			}
			$parts = preg_split( '/\s+/', trim( $grid_columns ) );
			if ( is_array( $parts ) ) {
				return min( 12, max( 1, count( $parts ) ) );
			}
		}
		if ( isset( $declarations['flex-direction'] ) && 'row' === strtolower( $declarations['flex-direction'] ) ) {
			return min( 12, max( 1, count( $children ) ) );
		}
		foreach ( array( 'grid', 'row', 'column', 'columns', 'flex', 'sidebar' ) as $needle ) {
			if ( false !== strpos( strtolower( $hint ), $needle ) ) {
				return min( 12, count( $children ) );
			}
		}
		return 0;
	}

	/**
	 * Classify a layout type.
	 *
	 * @param string|null        $display   Display value.
	 * @param int                $columns   Column count.
	 * @param array<int, string> $children  Child IDs.
	 * @param array<string,mixed> $context  DOM context.
	 * @param string             $node_id   Node ID.
	 * @return string
	 */
	private function classify_layout( $display, $columns, $children, $context, $node_id ) {
		if ( 'grid' === $display ) {
			return 'grid';
		}
		if ( 'flex' === $display ) {
			return 'flex';
		}
		$hint = $this->dom->get_hint_text( $context, $node_id );
		if ( false !== strpos( $hint, 'sidebar' ) && $columns >= 2 ) {
			return 'sidebar';
		}
		if ( 1 >= $columns ) {
			return 'stack';
		}
		if ( 2 === $columns ) {
			return 'two_column';
		}
		if ( 3 === $columns ) {
			return 'three_column';
		}
		return 'multi_column';
	}

	/**
	 * Infer a child width ratio from static declarations.
	 *
	 * @param array<string, string> $declarations Declarations.
	 * @return float|null
	 */
	private function width_ratio( $declarations ) {
		foreach ( array( 'width', 'flex' ) as $property ) {
			if ( ! isset( $declarations[ $property ] ) ) {
				continue;
			}
			if ( preg_match( '/(\d+(?:\.\d+)?)%/', $declarations[ $property ], $match ) ) {
				return round( max( 0.01, min( 1.0, (float) $match[1] / 100 ) ), 2 );
			}
		}
		return null;
	}

	/**
	 * Determine static centering signals.
	 *
	 * @param array<string, string> $declarations Declarations.
	 * @return bool|null
	 */
	private function is_centered( $declarations ) {
		if ( ! isset( $declarations['margin-left'], $declarations['margin-right'] ) ) {
			return null;
		}
		$left = strtolower( trim( $declarations['margin-left'] ) );
		$right = strtolower( trim( $declarations['margin-right'] ) );
		if ( 'auto' === $left && 'auto' === $right ) {
			return true;
		}
		if ( $left === $right && '0' === $left ) {
			return true;
		}
		return false;
	}

	/**
	 * Find the body node.
	 *
	 * @param array<string, mixed> $context DOM context.
	 * @return string
	 */
	private function find_body( $context ) {
		foreach ( $this->dom->get_node_ids( $context ) as $node_id ) {
			$node = $this->dom->get_node( $context, $node_id );
			if ( isset( $node['tag'] ) && 'body' === $node['tag'] ) {
				return $node_id;
			}
		}
		return '';
	}
}
