<?php
/**
 * Guarded AI explanation for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Explains differences that the deterministic comparison already found.
 *
 * The model receives only measured values and the difference records produced by
 * the comparators. Its output is constrained to a short explanation and a list
 * of restated corrections. Every returned recommendation is checked against the
 * machine-readable correction plan, so a recommendation can only restate a
 * measured difference. Anything the model produces that does not match a
 * recorded difference is discarded rather than reported.
 */
final class Ai_Validation_Explainer {

	/**
	 * Explain the detected differences.
	 *
	 * @param Ai_Manager           $manager     Configured AI manager.
	 * @param array<int, array>    $differences Difference records.
	 * @param array<string, mixed> $metrics     Validation metrics.
	 * @param array<string, mixed> $source      Normalized source side.
	 * @param array<string, mixed> $generated   Normalized generated side.
	 * @return array<string, mixed>
	 */
	public static function explain( Ai_Manager $manager, array $differences, array $metrics, array $source, array $generated ) {
		$result = array(
			'available'       => false,
			'explanation'     => '',
			'recommendations' => array(),
			'warnings'        => array(),
		);

		$public = $manager->get_public_settings();
		if ( empty( $public['enabled'] ) ) {
			$result['warnings'][] = __( 'AI is not enabled, so the deterministic validation result is shown without a model explanation.', 'replicaforge' );
			return $result;
		}

		$payload = self::build_payload( $differences, $metrics, $source, $generated );
		$response = self::request( $manager, $payload );
		if ( empty( $response['available'] ) ) {
			$result['warnings'] = $response['warnings'];
			return $result;
		}

		$result['available']       = true;
		$result['explanation']     = self::sanitize_text( $response['explanation'] );
		$result['recommendations'] = self::verify_recommendations( $response['recommendations'], $differences );
		if ( empty( $result['explanation'] ) && empty( $result['recommendations'] ) ) {
			$result['warnings'][] = __( 'The model response could not be verified against the measured differences, so no explanation was used.', 'replicaforge' );
			$result['available']  = false;
		}

		return $result;
	}

	/**
	 * Build the bounded, structured payload sent to the provider.
	 *
	 * @param array<int, array>    $differences Difference records.
	 * @param array<string, mixed> $metrics     Metrics.
	 * @param array<string, mixed> $source      Normalized source side.
	 * @param array<string, mixed> $generated   Normalized generated side.
	 * @return array<string, mixed>
	 */
	private static function build_payload( array $differences, array $metrics, array $source, array $generated ) {
		$records = array();
		foreach ( array_slice( $differences, 0, Validation_Limits::MAX_AI_DIFFERENCES ) as $difference ) {
			$records[] = array(
				'id'         => isset( $difference['id'] ) ? (string) $difference['id'] : '',
				'category'   => isset( $difference['category'] ) ? (string) $difference['category'] : '',
				'target'     => isset( $difference['target'] ) ? (string) $difference['target'] : '',
				'property'   => isset( $difference['property'] ) ? (string) $difference['property'] : '',
				'viewport'   => isset( $difference['viewport'] ) ? (string) $difference['viewport'] : 'desktop',
				'expected'   => isset( $difference['expected'] ) ? $difference['expected'] : null,
				'actual'     => isset( $difference['actual'] ) ? $difference['actual'] : null,
				'difference' => isset( $difference['difference'] ) ? $difference['difference'] : null,
				'severity'   => isset( $difference['severity'] ) ? (string) $difference['severity'] : '',
				'confidence' => isset( $difference['confidence'] ) ? (float) $difference['confidence'] : 0.0,
				'measured_message' => isset( $difference['message'] ) ? (string) $difference['message'] : '',
			);
		}

		return array(
			'task'   => 'explain_measured_differences',
			'rules'  => array(
				'Only explain differences present in measured_differences.',
				'Never invent a difference that is not listed.',
				'Every recommendation must restate a listed expected and actual value.',
				'Never claim a visual or pixel-perfect result.',
				'Never propose changing content text.',
			),
			'output' => array(
				'explanation'     => 'string',
				'recommendations' => 'array of {difference_id, action, from, to}',
			),
			'measured_metrics' => array(
				'overall' => isset( $metrics['overall']['value'] ) ? $metrics['overall']['value'] : null,
				'groups'  => isset( $metrics['groups'] ) ? $metrics['groups'] : array(),
			),
			'measured_differences' => $records,
			'context' => array(
				'source_sections'      => isset( $source['counters']['sections'] ) ? (int) $source['counters']['sections'] : 0,
				'generated_sections'   => isset( $generated['counters']['sections'] ) ? (int) $generated['counters']['sections'] : 0,
				'source_components'    => isset( $source['counters']['components'] ) ? (int) $source['counters']['components'] : 0,
				'generated_components' => isset( $generated['counters']['components'] ) ? (int) $generated['counters']['components'] : 0,
			),
		);
	}

	/**
	 * Call the provider through the existing AI manager stack.
	 *
	 * @param Ai_Manager           $manager AI manager.
	 * @param array<string, mixed> $payload Bounded payload.
	 * @return array<string, mixed>
	 */
	private static function request( Ai_Manager $manager, array $payload ) {
		$result = array(
			'available'       => false,
			'explanation'     => '',
			'recommendations' => array(),
			'warnings'        => array(),
		);

		$method = method_exists( $manager, 'explain_validation' ) ? 'explain_validation' : '';
		if ( '' === $method ) {
			$result['warnings'][] = __( 'The configured provider could not be used for validation explanation.', 'replicaforge' );
			return $result;
		}

		$response = $manager->explain_validation( $payload );
		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			$result['warnings'][] = __( 'The provider did not return a usable explanation. The deterministic validation result is unaffected.', 'replicaforge' );
			return $result;
		}

		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$result['available']       = true;
		$result['explanation']     = isset( $data['explanation'] ) ? (string) $data['explanation'] : '';
		$result['recommendations'] = isset( $data['recommendations'] ) && is_array( $data['recommendations'] ) ? $data['recommendations'] : array();

		return $result;
	}

	/**
	 * Keep only recommendations that restate a measured difference.
	 *
	 * @param mixed              $recommendations Model recommendations.
	 * @param array<int, array>  $differences    Measured differences.
	 * @return array<int, array<string, mixed>>
	 */
	private static function verify_recommendations( $recommendations, array $differences ) {
		$index = array();
		foreach ( $differences as $difference ) {
			if ( isset( $difference['id'] ) ) {
				$index[ (string) $difference['id'] ] = $difference;
			}
		}

		$verified = array();
		foreach ( is_array( $recommendations ) ? array_slice( $recommendations, 0, Validation_Limits::MAX_CORRECTIONS ) : array() as $recommendation ) {
			if ( ! is_array( $recommendation ) ) {
				continue;
			}
			$id = isset( $recommendation['difference_id'] ) ? (string) $recommendation['difference_id'] : '';
			if ( '' === $id || ! isset( $index[ $id ] ) ) {
				continue;
			}
			$measured = $index[ $id ];
			$verified[] = array(
				'difference_id' => $id,
				'category'      => (string) $measured['category'],
				'target'        => (string) $measured['target'],
				'property'      => (string) $measured['property'],
				'viewport'      => (string) $measured['viewport'],
				'action'        => self::sanitize_text( isset( $recommendation['action'] ) ? $recommendation['action'] : '' ),
				'from'          => isset( $measured['actual'] ) ? $measured['actual'] : null,
				'to'            => isset( $measured['expected'] ) ? $measured['expected'] : null,
				'severity'      => (string) $measured['severity'],
				'confidence'    => (float) $measured['confidence'],
			);
		}

		return $verified;
	}

	/**
	 * Return safe, bounded text.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_text( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		if ( Elementor_Values::is_executable( $value ) ) {
			return '';
		}
		$value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, 1200, 'UTF-8' );
		}
		return substr( $value, 0, 1200 );
	}
}
