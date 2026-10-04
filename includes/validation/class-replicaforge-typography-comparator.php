<?php
/**
 * Typography comparison for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares font family, size, weight, line height, letter spacing, transform,
 * alignment, and text colour.
 *
 * A font family is only reported as different when the source detected one. An
 * undetected source font is unknown evidence, because the draft may legitimately
 * fall back to a site font.
 */
final class Typography_Comparator {

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
		foreach ( $context->pairs() as $key => $pair ) {
			$expected = isset( $pair['source']['typography'] ) && is_array( $pair['source']['typography'] ) ? $pair['source']['typography'] : array();
			$actual   = ! empty( $pair['generated'] ) && isset( $pair['generated']['typography'] ) && is_array( $pair['generated']['typography'] ) ? $pair['generated']['typography'] : array();
			$element  = ! empty( $pair['generated'] ) ? (string) $pair['generated']['key'] : '';

			$checks[] = $this->text_check( (string) $key, $expected, $actual, $element, 'font_family', $this->family_difference( isset( $expected['font_family'] ) ? $expected['font_family'] : null, isset( $actual['font_family'] ) ? $actual['font_family'] : null ), 'font family' );
			$checks[] = $this->text_check( (string) $key, $expected, $actual, $element, 'font_size', $this->value_difference( isset( $expected['font_size'] ) ? $expected['font_size'] : null, isset( $actual['font_size'] ) ? $actual['font_size'] : null ), 'font size', 'font_size' );
			$checks[] = $this->text_check( (string) $key, $expected, $actual, $element, 'font_weight', $this->value_difference( isset( $expected['font_weight'] ) ? $expected['font_weight'] : null, isset( $actual['font_weight'] ) ? $actual['font_weight'] : null ), 'font weight', 'weight_value' );
			$checks[] = $this->text_check( (string) $key, $expected, $actual, $element, 'line_height', $this->value_difference( isset( $expected['line_height'] ) ? $expected['line_height'] : null, isset( $actual['line_height'] ) ? $actual['line_height'] : null ), 'line height', 'line_height' );
			$checks[] = $this->text_check( (string) $key, $expected, $actual, $element, 'letter_spacing', $this->value_difference( isset( $expected['letter_spacing'] ) ? $expected['letter_spacing'] : null, isset( $actual['letter_spacing'] ) ? $actual['letter_spacing'] : null ), 'letter spacing', 'length' );
			$checks[] = $this->text_check( (string) $key, $expected, $actual, $element, 'text_transform', $this->value_difference( $this->token( isset( $expected['text_transform'] ) ? $expected['text_transform'] : '' ), $this->token( isset( $actual['text_transform'] ) ? $actual['text_transform'] : '' ) ), 'text transform' );
			$checks[] = $this->color_check( (string) $key, $expected, $actual, $element );
		}

		foreach ( $this->scale_checks( $source, $generated ) as $check ) {
			$checks[] = $check;
		}

		return $checks;
	}

	/**
	 * Compare the detected typography scale.
	 *
	 * @param array<string, mixed> $source    Normalized source side.
	 * @param array<string, mixed> $generated Normalized generated side.
	 * @return array<int, array<string, mixed>>
	 */
	private function scale_checks( array $source, array $generated ) {
		$checks  = array();
		$expected = isset( $source['design_system']['typography'] ) && is_array( $source['design_system']['typography'] ) ? $source['design_system']['typography'] : array();
		$actual   = isset( $generated['design_system']['typography'] ) && is_array( $generated['design_system']['typography'] ) ? $generated['design_system']['typography'] : array();

		foreach ( $expected as $role => $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			$role_key   = (string) $role;
			$expected_size = isset( $token['font_size'] ) ? $token['font_size'] : null;
			$actual_size   = isset( $actual[ $role_key ]['font_size'] ) ? $actual[ $role_key ]['font_size'] : null;
			if ( null === $expected_size || null === $actual_size ) {
				continue;
			}
			$difference = round( (float) $actual_size - (float) $expected_size, 2 );
			$state      = Comparison_Schema::band( $difference, 'font_size' );
			$checks[]   = array(
				'category'            => 'typography',
				'target'              => 'design_system',
				'property'            => 'font_size:' . $role_key,
				'expected'            => round( (float) $expected_size, 2 ),
				'actual'              => round( (float) $actual_size, 2 ),
				'difference'          => $difference,
				'state'               => $state,
				'tolerance_type'      => 'font_size',
				'severity'            => 'pass' === $state ? 'informational' : ( 'fail' === $state ? 'moderate' : 'minor' ),
				'message'             => 'pass' === $state ? '' : sprintf(
					/* translators: 1: Typography role, 2: Expected size, 3: Actual size, 4: Difference. */
					__( 'The %1$s size differs: %2$spx detected, %3$spx generated (%4$spx).', 'replicaforge' ),
					str_replace( '_', ' ', $role_key ),
					round( (float) $expected_size, 2 ),
					round( (float) $actual_size, 2 ),
					round( abs( $difference ), 2 )
				),
				'confidence'          => isset( $token['confidence'] ) ? (float) $token['confidence'] : 0.0,
				'source_reference'    => array(),
				'generated_reference' => array(),
			);
		}

		return $checks;
	}

	/**
	 * Build one typography check.
	 *
	 * @param string               $key       Source component key.
	 * @param array<string, mixed> $expected  Source typography.
	 * @param array<string, mixed> $actual    Generated typography.
	 * @param string               $element   Elementor element ID.
	 * @param string               $property  Property name.
	 * @param float|null           $difference Numeric difference.
	 * @param string               $label     Human label.
	 * @param string               $type      Tolerance type.
	 * @return array<string, mixed>
	 */
	private function text_check( $key, array $expected, array $actual, $element, $property, $difference, $label, $type = 'length' ) {
		$source_value = $this->source_property( $expected, $property );
		if ( null === $source_value ) {
			return array(
				'category'            => 'typography',
				'target'              => 'component:' . $key,
				'property'            => $property,
				'expected'            => null,
				'actual'              => $this->generated_property( $actual, $property ),
				'state'               => 'unknown',
				'confidence'          => 0.0,
				'source_reference'    => array( 'source_component_id' => $key ),
				'generated_reference' => array( 'elementor_element_id' => $element ),
			);
		}

		$actual_value = $this->generated_property( $actual, $property );
		if ( null === $actual_value ) {
			return array(
				'category'            => 'typography',
				'target'              => 'component:' . $key,
				'property'            => $property,
				'expected'            => $source_value,
				'actual'              => null,
				'state'               => 'missing',
				'severity'            => 'minor',
				'message'             => sprintf(
					/* translators: 1: Property label, 2: Source value. */
					__( 'The %1$s is %2$s on the source and is not set on this element in the draft.', 'replicaforge' ),
					$label,
					(string) $source_value
				),
				'confidence'          => 0.7,
				'source_reference'    => array( 'source_component_id' => $key ),
				'generated_reference' => array( 'elementor_element_id' => $element ),
			);
		}

		$state = Comparison_Schema::band( $difference, $type );
		return array(
			'category'            => 'typography',
			'target'              => 'component:' . $key,
			'property'            => $property,
			'expected'            => $source_value,
			'actual'              => $actual_value,
			'difference'          => null === $difference ? null : round( $difference, 4 ),
			'state'               => $state,
			'tolerance_type'      => $type,
			'severity'            => 'pass' === $state ? 'informational' : ( 'fail' === $state ? 'moderate' : 'minor' ),
			'message'             => 'pass' === $state ? '' : sprintf(
				/* translators: 1: Property label, 2: Source value, 3: Generated value. */
				__( 'The %1$s differs: %2$s on the source, %3$s in the draft.', 'replicaforge' ),
				$label,
				(string) $source_value,
				(string) $actual_value
			),
			'confidence'          => 0.85,
			'source_reference'    => array( 'source_component_id' => $key ),
			'generated_reference' => array( 'elementor_element_id' => $element ),
		);
	}

	/**
	 * Compare the text colour of one component.
	 *
	 * @param string               $key      Source component key.
	 * @param array<string, mixed> $expected Source typography.
	 * @param array<string, mixed> $actual   Generated typography.
	 * @param string               $element  Elementor element ID.
	 * @return array<string, mixed>
	 */
	private function color_check( $key, array $expected, array $actual, $element ) {
		$expected_color = $this->source_property( $expected, 'color' );
		$actual_color   = $this->generated_property( $actual, 'color' );
		if ( null === $expected_color ) {
			return array(
				'category'            => 'typography',
				'target'              => 'component:' . $key,
				'property'            => 'text_color',
				'expected'            => null,
				'actual'              => is_array( $actual_color ) && isset( $actual_color['hex'] ) ? '#' . $actual_color['hex'] : null,
				'state'               => 'unknown',
				'confidence'          => 0.0,
				'source_reference'    => array( 'source_component_id' => $key ),
				'generated_reference' => array( 'elementor_element_id' => $element ),
			);
		}
		if ( ! is_array( $expected_color ) || ! is_array( $actual_color ) ) {
			return array(
				'category'            => 'typography',
				'target'              => 'component:' . $key,
				'property'            => 'text_color',
				'expected'            => null,
				'actual'              => is_array( $actual_color ) && isset( $actual_color['hex'] ) ? '#' . $actual_color['hex'] : null,
				'state'               => 'unknown',
				'confidence'          => 0.0,
				'source_reference'    => array( 'source_component_id' => $key ),
				'generated_reference' => array( 'elementor_element_id' => $element ),
			);
		}

		$distance = Comparison_Schema::color_distance( $expected_color, $actual_color );
		$state    = Comparison_Schema::band( $distance, 'color' );
		return array(
			'category'            => 'typography',
			'target'              => 'component:' . $key,
			'property'            => 'text_color',
			'expected'            => '#' . $expected_color['hex'],
			'actual'              => '#' . $actual_color['hex'],
			'difference'          => $distance,
			'state'               => $state,
			'tolerance_type'      => 'color',
			'severity'            => 'pass' === $state ? 'informational' : 'minor',
			'message'             => 'pass' === $state ? '' : sprintf(
				/* translators: 1: Source colour, 2: Generated colour, 3: Colour distance. */
				__( 'Text colour differs: %1$s on the source, %2$s in the draft (distance %3$s).', 'replicaforge' ),
				'#' . $expected_color['hex'],
				'#' . $actual_color['hex'],
				(string) $distance
			),
			'confidence'          => 0.85,
			'source_reference'    => array( 'source_component_id' => $key ),
			'generated_reference' => array( 'elementor_element_id' => $element ),
		);
	}

	/**
	 * Return a comparable source typography value.
	 *
	 * @param array<string, mixed> $typography Typography record.
	 * @param string               $property   Property name.
	 * @return mixed
	 */
	private function source_property( array $typography, $property ) {
		if ( 'text_transform' === $property ) {
			$value = isset( $typography['text_transform'] ) ? $this->token( $typography['text_transform'] ) : '';
			return '' === $value ? null : $value;
		}
		if ( ! isset( $typography[ $property ] ) ) {
			return null;
		}
		$value = $typography[ $property ];
		if ( null === $value || '' === $value ) {
			return null;
		}
		return is_float( $value ) ? round( $value, 4 ) : $value;
	}

	/**
	 * Return a comparable generated typography value.
	 *
	 * @param array<string, mixed> $typography Typography record.
	 * @param string               $property   Property name.
	 * @return mixed
	 */
	private function generated_property( array $typography, $property ) {
		$key = 'text_color' === $property ? 'color' : $property;
		if ( ! isset( $typography[ $key ] ) ) {
			return null;
		}
		$value = $typography[ $key ];
		if ( is_array( $value ) ) {
			return isset( $value['hex'] ) ? '#' . $value['hex'] : null;
		}
		if ( null === $value || '' === $value ) {
			return null;
		}
		return is_float( $value ) ? round( $value, 4 ) : $value;
	}

	/**
	 * Return the difference between two comparable values.
	 *
	 * @param mixed $expected Expected value.
	 * @param mixed $actual   Actual value.
	 * @return float|null
	 */
	private function value_difference( $expected, $actual ) {
		if ( is_numeric( $expected ) && is_numeric( $actual ) ) {
			return round( (float) $actual - (float) $expected, 4 );
		}
		if ( is_string( $expected ) && is_string( $actual ) ) {
			return 0.0 === strcasecmp( $expected, $actual ) ? 0.0 : 1.0;
		}
		return null;
	}

	/**
	 * Return the difference between two font families.
	 *
	 * @param mixed $expected Expected family.
	 * @param mixed $actual   Actual family.
	 * @return float|null
	 */
	private function family_difference( $expected, $actual ) {
		if ( ! is_string( $expected ) || '' === $expected || ! is_string( $actual ) || '' === $actual ) {
			return null;
		}
		$expected_list = array_map( 'trim', explode( ',', strtolower( $expected ) ) );
		$actual_list   = array_map( 'trim', explode( ',', strtolower( $actual ) ) );
		$expected_head  = isset( $expected_list[0] ) ? $expected_list[0] : '';
		$actual_head    = isset( $actual_list[0] ) ? $actual_list[0] : '';
		if ( '' === $expected_head || '' === $actual_head ) {
			return null;
		}
		return $expected_head === $actual_head ? 0.0 : 1.0;
	}

	/**
	 * Return a safe short token.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function token( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		return preg_replace( '/[^a-z0-9_-]/', '', $value );
	}
}
