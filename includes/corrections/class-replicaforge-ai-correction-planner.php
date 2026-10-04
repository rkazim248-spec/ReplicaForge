<?php
/**
 * AI correction planning for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Optional AI planning over already-measured differences.
 *
 * The model never writes Elementor data. It receives the measured differences and
 * the deterministic plan ReplicaForge already produced, and it may only
 * *reorder* or *group* what is already there, or say that no better grouping
 * exists. Every recommendation is then re-validated by `Correction_Validator`,
 * exactly like a deterministic correction, so a model response can never widen
 * the write surface.
 *
 * Source website text is untrusted data and is never placed in a position where
 * it could act as an instruction.
 */
final class Ai_Correction_Planner {

	/**
	 * Configured AI manager.
	 *
	 * @var Ai_Manager|null
	 */
	private $manager;

	/**
	 * Plan validator.
	 *
	 * @var Correction_Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param Ai_Manager|null            $manager   Optional AI manager.
	 * @param Correction_Validator|null  $validator Optional plan validator.
	 */
	public function __construct( $manager = null, $validator = null ) {
		$this->manager   = $manager instanceof Ai_Manager ? $manager : null;
		$this->validator = $validator instanceof Correction_Validator ? $validator : new Correction_Validator();
	}

	/**
	 * Return whether AI planning is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->manager instanceof Ai_Manager;
	}

	/**
	 * Ask the model to group and order the measured corrections.
	 *
	 * @param array<string, mixed> $plan Stored deterministic plan.
	 * @return array<string, mixed>
	 */
	public function plan( array $plan ) {
		$result = array(
			'available'       => false,
			'requested'       => true,
			'level'           => 5,
			'explanation'     => '',
			'recommendations' => array(),
			'discarded'       => 0,
			'warnings'        => array(),
		);

		if ( ! $this->manager instanceof Ai_Manager ) {
			$result['warnings'][] = __( 'AI planning is unavailable because no provider is configured. The deterministic plan is unaffected.', 'replicaforge' );
			return $result;
		}

		$corrections = isset( $plan['corrections'] ) && is_array( $plan['corrections'] ) ? $plan['corrections'] : array();
		if ( empty( $corrections ) ) {
			$result['warnings'][] = __( 'There are no measured differences to plan, so no AI planning was requested.', 'replicaforge' );
			return $result;
		}

		$payload = $this->payload( $plan, $corrections );
		$response = $this->manager->plan_corrections( $payload );
		if ( empty( $response['success'] ) ) {
			$result['warnings'][] = __( 'The AI provider did not return a usable correction plan. The deterministic plan is unaffected.', 'replicaforge' );
			return $result;
		}

		$data  = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$known = array();
		foreach ( $corrections as $correction ) {
			if ( isset( $correction['correction_id'] ) ) {
				$known[ (string) $correction['correction_id'] ] = $correction;
			}
		}

		$verified = array();
		$discarded = 0;
		foreach ( isset( $data['recommendations'] ) && is_array( $data['recommendations'] ) ? $data['recommendations'] : array() as $recommendation ) {
			if ( ! is_array( $recommendation ) || empty( $recommendation['correction_id'] ) ) {
				$discarded++;
				continue;
			}
			$correction_id = (string) $recommendation['correction_id'];
			if ( ! isset( $known[ $correction_id ] ) ) {
				// A recommendation for a difference ReplicaForge did not measure is
				// discarded, not reported.
				$discarded++;
				continue;
			}

			$measured = $known[ $correction_id ];
			$proposal = array(
				'order'          => isset( $recommendation['order'] ) ? (int) $recommendation['order'] : 0,
				'depends_on'     => array(),
				'regenerate'     => ! empty( $recommendation['regenerate_component'] ),
				'section_level'  => ! empty( $recommendation['section_level'] ),
			);
			$proposal['depends_on'] = array_values(
				array_filter(
					array_map(
						static function ( $value ) {
							return is_scalar( $value ) ? (string) $value : '';
						},
						isset( $recommendation['depends_on'] ) && is_array( $recommendation['depends_on'] ) ? $recommendation['depends_on'] : array()
					),
					static function ( $value ) {
						return '' !== $value;
					}
				)
			);

			// A dependency must reference a correction in the same plan, and a
			// model cannot create a cycle.
			foreach ( $proposal['depends_on'] as $dependency ) {
				if ( ! isset( $known[ $dependency ] ) ) {
					$proposal['depends_on'] = array();
					break;
				}
				if ( $dependency === $correction_id ) {
					$proposal['depends_on'] = array();
					break;
				}
			}

			$verified[] = array(
				'correction_id' => $correction_id,
				'property'     => (string) $measured['property'],
				'action'       => (string) $measured['action'],
				'batch'        => (string) $measured['batch'],
				'auto'         => ! empty( $measured['auto'] ),
				'order'        => $proposal['order'],
				'depends_on'   => $proposal['depends_on'],
				'regenerate'   => $proposal['regenerate'],
				'section_level' => $proposal['section_level'],
				'confidence'   => isset( $recommendation['confidence'] ) && is_numeric( $recommendation['confidence'] )
					? min( 1.0, max( 0.0, (float) $recommendation['confidence'] ) )
					: 0.0,
				'reason'       => isset( $recommendation['reason'] ) && is_string( $recommendation['reason'] ) ? sanitize_text_field( $recommendation['reason'] ) : '',
			);
		}

		$result['available']       = ! empty( $verified );
		$result['explanation']     = isset( $data['explanation'] ) && is_string( $data['explanation'] ) ? sanitize_text_field( $data['explanation'] ) : '';
		$result['recommendations'] = $verified;
		$result['discarded']       = (int) $discarded;
		if ( $discarded > 0 ) {
			$result['warnings'][] = sprintf(
				/* translators: %d: Number of discarded recommendations. */
				__( '%d AI recommendation(s) referenced a difference that was not measured and were discarded. The deterministic plan is unaffected.', 'replicaforge' ),
				(int) $discarded
			);
		}
		if ( empty( $result['explanation'] ) && empty( $verified ) ) {
			$result['warnings'][] = __( 'The AI response could not be verified against the measured differences, so it was not used.', 'replicaforge' );
		}

		return $result;
	}

	/**
	 * Return the corrected application order for a plan.
	 *
	 * The deterministic batch order is always the base. AI planning may only move
	 * a correction earlier or later inside that same order, never add, remove, or
	 * rewrite a correction.
	 *
	 * @param array<int, array<string, mixed>> $corrections   Ordered corrections.
	 * @param array<int, array<string, mixed>> $recommendations Verified recommendations.
	 * @return array<int, array<string, mixed>>
	 */
	public function apply_order( array $corrections, array $recommendations ) {
		if ( empty( $recommendations ) ) {
			return $corrections;
		}

		$weights = array();
		$index   = 0;
		foreach ( $corrections as $correction ) {
			$index++;
			$weights[ (string) $correction['correction_id'] ] = $index * 1000;
		}

		foreach ( $recommendations as $recommendation ) {
			$correction_id = (string) $recommendation['correction_id'];
			if ( ! isset( $weights[ $correction_id ] ) ) {
				continue;
			}
			// The model may nudge a weight, but the deterministic batch order
			// dominates, so a model cannot move a correction across batches.
			$nudge = max( -999, min( 999, (int) $recommendation['order'] ) );
			$weights[ $correction_id ] += $nudge;
		}

		$ordered = $corrections;
		usort(
			$ordered,
			static function ( $left, $right ) use ( $weights ) {
				$a = isset( $weights[ (string) $left['correction_id'] ] ) ? $weights[ (string) $left['correction_id'] ] : 0;
				$b = isset( $weights[ (string) $right['correction_id'] ] ) ? $weights[ (string) $right['correction_id'] ] : 0;
				if ( $a === $b ) {
					return 0;
				}
				return $a < $b ? -1 : 1;
			}
		);

		return $ordered;
	}

	/**
	 * Build the bounded, measured payload sent to the provider.
	 *
	 * @param array<string, mixed>       $plan         Plan.
	 * @param array<int, array<string,mixed>> $corrections Corrections.
	 * @return array<string, mixed>
	 */
	private function payload( array $plan, array $corrections ) {
		$records = array();
		foreach ( array_slice( $corrections, 0, Validation_Limits::MAX_AI_DIFFERENCES ) as $correction ) {
			$records[] = array(
				'correction_id' => (string) $correction['correction_id'],
				'property'      => (string) $correction['property'],
				'action'        => (string) $correction['action'],
				'batch'         => (string) $correction['batch'],
				'level'         => (int) $correction['level'],
				'viewport'      => (string) $correction['viewport'],
				'eligibility'   => (string) $correction['eligibility'],
				'auto'          => (bool) $correction['auto'],
				'element'       => (string) ( $correction['target']['elementor_element_id'] ?? '' ),
				'component'     => (string) ( $correction['target']['source_component_id'] ?? '' ),
				'current'       => isset( $correction['current'] ) ? $correction['current'] : null,
				'expected'      => isset( $correction['expected'] ) ? $correction['expected'] : null,
				'confidence'    => (float) $correction['confidence'],
				'measured_message' => (string) ( $correction['measured_message'] ?? '' ),
			);
		}

		return array(
			'task'  => 'order_measured_corrections',
			'rules' => array(
				'Only reference correction_id values that are present in the data.',
				'Never invent a difference, value, colour, dimension, or element.',
				'Never change what a correction sets. Only order it.',
				'Never claim pixel-perfect or visual accuracy.',
				'Return one JSON object with an explanation string and a recommendations array.',
				'Each recommendation must include correction_id, order, depends_on, confidence, and reason.',
			),
			'output' => array(
				'explanation'    => 'string',
				'recommendations' => 'array of {correction_id, order, depends_on, confidence, reason, regenerate_component, section_level}',
			),
			'batch_order' => Correction_Limits::BATCHES,
			'measured'    => $records,
			'context'     => array(
				'post_id'          => (int) $plan['post_id'],
				'validation_score' => $plan['validation_score'] ?? null,
				'correction_count' => count( $corrections ),
				'auto_count'       => (int) ( $plan['counts']['safe'] ?? 0 ),
			),
		);
	}
}
