<?php
/**
 * Colour, border, shadow, and background comparison for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the colour system and the per-element box decoration.
 *
 * Every colour is normalized through the shared schema first, so a hex, rgb, or
 * hsl value on one side is compared against any format on the other.
 */
final class Color_Comparator {

	/**
	 * Run the comparison.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @param Comparison_Context   $context   Comparison context.
	 * @return array<int, array<string, mixed>>
	 */
	public function compare( array $source, array $generated, Comparison_Context $context ) {
		$checks = array();

		foreach ( $this->role_checks( $source, $generated ) as $check ) {
			$checks[] = $check;
		}
		foreach ( $context->pairs() as $key => $pair ) {
			if ( empty( $pair['generated'] ) ) {
				continue;
			}
			$element  = (string) $pair['generated']['key'];
			$generated_box = isset( $pair['generated']['box'] ) && is_array( $pair['generated']['box'] ) ? $pair['generated']['box'] : array();

			$checks[] = $this->box_color_check( (string) $key, $element, 'background_color', $generated_box );
			$checks[] = $this->border_checks( (string) $key, $element, $generated_box );
			$checks[] = $this->shadow_check( (string) $key, $element, $generated_box );
		}

		return $checks;
	}

	/**
	 * Compare semantic colour roles.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return array<int, array<string, mixed>>
	 */
	private function role_checks( array $source, array $generated ) {
		$checks   = array();
		$expected = isset( $source['design_system']['colors'] ) && is_array( $source['design_system']['colors'] ) ? $source['design_system']['colors'] : array();
		$actual   = isset( $generated['design_system']['colors'] ) && is_array( $generated['design_system']['colors'] ) ? $generated['design_system']['colors'] : array();
		$confid   = isset( $source['design_system']['color_confidence'] ) && is_array( $source['design_system']['color_confidence'] ) ? $source['design_system']['color_confidence'] : array();

		foreach ( $expected as $role => $color ) {
			if ( ! is_array( $color ) ) {
				continue;
			}
			$role_key     = (string) $role;
			$actual_color = isset( $actual[ $role_key ] ) && is_array( $actual[ $role_key ] ) ? $actual[ $role_key ] : null;
			if ( null === $actual_color ) {
				$checks[] = array(
					'category'            => 'color',
					'target'              => 'design_system',
					'property'            => 'color_role:' . $role_key,
					'expected'            => '#' . $color['hex'],
					'actual'              => null,
					'state'               => 'missing',
					'severity'            => 'informational',
					'message'             => sprintf(
						/* translators: %s: Colour role name. */
						__( 'The %s colour role was detected on the source but is not set on the draft. Element colours are written per element, so this may still apply locally.', 'replicaforge' ),
						str_replace( '_', ' ', $role_key )
					),
					'confidence'          => isset( $confid[ $role_key ] ) ? (float) $confid[ $role_key ] : 0.0,
					'source_reference'    => array(),
					'generated_reference' => array(),
				);
				continue;
			}

			$distance = Comparison_Schema::color_distance( $color, $actual_color );
			$state    = Comparison_Schema::band( $distance, 'color' );
			$checks[] = array(
				'category'            => 'color',
				'target'              => 'design_system',
				'property'            => 'color_role:' . $role_key,
				'expected'            => '#' . $color['hex'],
				'actual'              => '#' . $actual_color['hex'],
				'difference'          => $distance,
				'state'               => $state,
				'tolerance_type'      => 'color',
				'severity'            => 'pass' === $state ? 'informational' : 'minor',
				'message'             => 'pass' === $state ? '' : sprintf(
					/* translators: 1: Role, 2: Source colour, 3: Generated colour, 4: Distance. */
					__( 'The %1$s colour differs: %2$s on the source, %3$s in the draft (distance %4$s).', 'replicaforge' ),
					str_replace( '_', ' ', $role_key ),
					'#' . $color['hex'],
					'#' . $actual_color['hex'],
					(string) $distance
				),
				'confidence'          => isset( $confid[ $role_key ] ) ? (float) $confid[ $role_key ] : 0.0,
				'source_reference'    => array(),
				'generated_reference' => array(),
			);
		}

		return $checks;
	}

	/**
	 * Compare a per-element box colour.
	 *
	 * The source representation records no per-element colours, so this check is
	 * always unknown evidence. It is retained so a future representation that
	 * carries element styles is compared automatically.
	 *
	 * @param string               $key       Source component key.
	 * @param string               $element   Elementor element ID.
	 * @param string               $property  Property name.
	 * @param array<string, mixed> $box       Generated box record.
	 * @return array<string, mixed>
	 */
	private function box_color_check( $key, $element, $property, array $box ) {
		$color = isset( $box[ $property ] ) && is_array( $box[ $property ] ) ? $box[ $property ] : null;
		return array(
			'category'            => 'background',
			'target'              => 'component:' . $key,
			'property'            => $property,
			'expected'            => null,
			'actual'              => is_array( $color ) && isset( $color['hex'] ) ? '#' . $color['hex'] : null,
			'state'               => 'unknown',
			'confidence'          => 0.0,
			'source_reference'    => array( 'source_component_id' => $key ),
			'generated_reference' => array( 'elementor_element_id' => $element ),
		);
	}

	/**
	 * Compare border values of one element.
	 *
	 * @param string               $key     Source component key.
	 * @param string               $element Elementor element ID.
	 * @param array<string, mixed> $box     Generated box record.
	 * @return array<int, array<string, mixed>>
	 */
	private function border_checks( $key, $element, array $box ) {
		$checks = array();

		$radius = isset( $box['border_radius'] ) && is_numeric( $box['border_radius'] ) ? (float) $box['border_radius'] : null;
		$checks[] = array(
			'category'            => 'border',
			'target'              => 'component:' . $key,
			'property'            => 'border_radius',
			'expected'            => null,
			'actual'              => $radius,
			'state'               => 'unknown',
			'confidence'          => 0.0,
			'source_reference'    => array( 'source_component_id' => $key ),
			'generated_reference' => array( 'elementor_element_id' => $element ),
		);

		$width = isset( $box['border_width'] ) && is_numeric( $box['border_width'] ) ? (float) $box['border_width'] : null;
		$checks[] = array(
			'category'            => 'border',
			'target'              => 'component:' . $key,
			'property'            => 'border_width',
			'expected'            => null,
			'actual'              => $width,
			'state'               => 'unknown',
			'confidence'          => 0.0,
			'source_reference'    => array( 'source_component_id' => $key ),
			'generated_reference' => array( 'elementor_element_id' => $element ),
		);

		return $checks;
	}

	/**
	 * Compare a box shadow.
	 *
	 * @param string               $key     Source component key.
	 * @param string               $element Elementor element ID.
	 * @param array<string, mixed> $box     Generated box record.
	 * @return array<string, mixed>
	 */
	private function shadow_check( $key, $element, array $box ) {
		$shadow = isset( $box['box_shadow'] ) && is_string( $box['box_shadow'] ) ? $box['box_shadow'] : '';

		return array(
			'category'            => 'shadow',
			'target'              => 'component:' . $key,
			'property'            => 'box_shadow',
			'expected'            => null,
			'actual'              => '' === $shadow ? null : $shadow,
			'state'               => 'unknown',
			'confidence'          => 0.0,
			'source_reference'    => array( 'source_component_id' => $key ),
			'generated_reference' => array( 'elementor_element_id' => $element ),
		);
	}
}
