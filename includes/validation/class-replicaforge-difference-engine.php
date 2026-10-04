<?php
/**
 * Difference engine for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Turns comparison checks into stable, machine-readable difference records.
 *
 * Comparators never decide severity or identifiers themselves. They report what
 * they compared, and this engine applies the documented severity table, assigns
 * stable identifiers, bounds the result, and keeps every difference traceable
 * back to a Phase 2 ID, a Phase 3 ID, and a Phase 4 Elementor element ID.
 */
final class Difference_Engine {

	/**
	 * Severity assigned to a failing check, keyed by category.
	 *
	 * `state` is one of `partial`, `fail`, `missing`, `extra`, `unknown`.
	 * The table is fixed so a difference of the same kind always scores the same.
	 *
	 * @var array<string, array<string, string>>
	 */
	private $severity_table = array(
		'structure'     => array(
			'partial' => 'major',
			'fail'    => 'major',
			'missing' => 'critical',
			'extra'   => 'minor',
		),
		'section_order' => array(
			'partial' => 'major',
			'fail'    => 'major',
			'missing' => 'major',
			'extra'   => 'informational',
		),
		'component'     => array(
			'partial' => 'moderate',
			'fail'    => 'moderate',
			'missing' => 'major',
			'extra'   => 'minor',
		),
		'layout'        => array(
			'partial' => 'minor',
			'fail'    => 'moderate',
			'missing' => 'moderate',
			'extra'   => 'informational',
		),
		'spacing'       => array(
			'partial' => 'minor',
			'fail'    => 'moderate',
			'missing' => 'minor',
			'extra'   => 'informational',
		),
		'typography'    => array(
			'partial' => 'minor',
			'fail'    => 'moderate',
			'missing' => 'moderate',
			'extra'   => 'informational',
		),
		'color'         => array(
			'partial' => 'minor',
			'fail'    => 'moderate',
			'missing' => 'moderate',
			'extra'   => 'informational',
		),
		'background'    => array(
			'partial' => 'minor',
			'fail'    => 'moderate',
			'missing' => 'minor',
			'extra'   => 'informational',
		),
		'border'        => array(
			'partial' => 'minor',
			'fail'    => 'minor',
			'missing' => 'informational',
			'extra'   => 'informational',
		),
		'shadow'        => array(
			'partial' => 'minor',
			'fail'    => 'minor',
			'missing' => 'informational',
			'extra'   => 'informational',
		),
		'image'         => array(
			'partial' => 'minor',
			'fail'    => 'moderate',
			'missing' => 'moderate',
			'extra'   => 'minor',
		),
		'asset'         => array(
			'partial' => 'minor',
			'fail'    => 'moderate',
			'missing' => 'moderate',
			'extra'   => 'informational',
		),
		'content'       => array(
			'partial' => 'moderate',
			'fail'    => 'major',
			'missing' => 'major',
			'extra'   => 'minor',
		),
		'link'          => array(
			'partial' => 'moderate',
			'fail'    => 'moderate',
			'missing' => 'moderate',
			'extra'   => 'informational',
		),
		'responsive'    => array(
			'partial' => 'minor',
			'fail'    => 'moderate',
			'missing' => 'moderate',
			'extra'   => 'informational',
		),
		'navigation'    => array(
			'partial' => 'minor',
			'fail'    => 'moderate',
			'missing' => 'moderate',
			'extra'   => 'informational',
		),
		'visibility'    => array(
			'partial' => 'minor',
			'fail'    => 'moderate',
			'missing' => 'minor',
			'extra'   => 'informational',
		),
		'interaction'   => array(
			'partial' => 'informational',
			'fail'    => 'moderate',
			'missing' => 'informational',
			'extra'   => 'informational',
		),
	);

	/**
	 * Collected differences for the current run.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $differences = array();

	/**
	 * Deduplication keys already used.
	 *
	 * @var array<string, bool>
	 */
	private $seen = array();

	/**
	 * Collected checks, retained so metrics can be computed from them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $checks = array();

	/**
	 * Deadline for the run, as a Unix timestamp with microseconds.
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Whether the run hit its check or time budget.
	 *
	 * @var bool
	 */
	private $exhausted = false;

	/**
	 * Reason the run stopped early, or an empty string.
	 *
	 * @var string
	 */
	private $exhaustion_reason = '';

	/**
	 * Reset the engine for a new run.
	 *
	 * @param float $budget_seconds Wall-clock budget for the run.
	 * @return void
	 */
	public function reset( $budget_seconds = 0 ) {
		$this->differences        = array();
		$this->checks             = array();
		$this->seen               = array();
		$this->exhausted          = false;
		$this->exhaustion_reason  = '';

		$budget = is_numeric( $budget_seconds ) ? (float) $budget_seconds : 0.0;
		if ( $budget > 0 ) {
			$this->deadline = microtime( true ) + $budget;
		} else {
			$this->deadline = 0.0;
		}
	}

	/**
	 * Return whether the run stopped early and why.
	 *
	 * @return array{exhausted: bool, reason: string}
	 */
	public function exhaustion() {
		return array(
			'exhausted' => $this->exhausted,
			'reason'    => $this->exhaustion_reason,
		);
	}

	/**
	 * Return whether the run may still record a check.
	 *
	 * @return bool
	 */
	private function has_budget() {
		if ( count( $this->checks ) >= Validation_Limits::MAX_CHECKS ) {
			$this->exhausted         = true;
			$this->exhaustion_reason = 'check_budget';
			return false;
		}
		if ( $this->deadline > 0 && microtime( true ) > $this->deadline ) {
			$this->exhausted         = true;
			$this->exhaustion_reason = 'time_budget';
			return false;
		}
		return true;
	}

	/**
	 * Record one comparison check.
	 *
	 * Checks with a `pass` state are kept for metric denominators and are never
	 * reported as differences. Checks with an `unknown` state are counted as not
	 * comparable and are never reported as differences either, because the source
	 * evidence did not support a comparison.
	 *
	 * A check whose identity was already measured is discarded, so one measurement
	 * contributes exactly once to the denominator and at most one difference.
	 *
	 * @param array<string, mixed> $check Check record.
	 * @return bool True when the check produced a difference.
	 */
	public function record( array $check ) {
		if ( ! $this->has_budget() ) {
			return false;
		}
		$category = isset( $check['category'] ) && in_array( $check['category'], Validation_Limits::CATEGORIES, true )
			? (string) $check['category']
			: 'structure';
		$state = isset( $check['state'] ) && in_array( $check['state'], array( 'pass', 'partial', 'fail', 'missing', 'extra', 'unknown' ), true )
			? (string) $check['state']
			: 'unknown';

		$normalized = array(
			'category'             => $category,
			'target'               => isset( $check['target'] ) ? $this->label( $check['target'] ) : '',
			'property'             => isset( $check['property'] ) ? $this->label( $check['property'], 60 ) : '',
			'expected'             => $this->scalar( isset( $check['expected'] ) ? $check['expected'] : null ),
			'actual'               => $this->scalar( isset( $check['actual'] ) ? $check['actual'] : null ),
			'difference'           => isset( $check['difference'] ) && is_numeric( $check['difference'] ) ? round( (float) $check['difference'], 4 ) : null,
			'state'                => $state,
			'tolerance_type'       => isset( $check['tolerance_type'] ) && is_string( $check['tolerance_type'] ) ? (string) $check['tolerance_type'] : 'length',
			'severity'             => $this->severity( $category, $state, $check ),
			'message'              => isset( $check['message'] ) ? $this->message( $check['message'] ) : '',
			'confidence'           => isset( $check['confidence'] ) && is_numeric( $check['confidence'] ) ? $this->clamp( $check['confidence'] ) : 0.0,
			'viewport'             => isset( $check['viewport'] ) && in_array( $check['viewport'], array_keys( Validation_Limits::VIEWPORTS ), true ) ? (string) $check['viewport'] : 'desktop',
			'source_reference'     => $this->reference( isset( $check['source_reference'] ) ? $check['source_reference'] : array() ),
			'generated_reference'  => $this->reference( isset( $check['generated_reference'] ) ? $check['generated_reference'] : array() ),
		);

		$key = $normalized['category'] . '|' . $normalized['target'] . '|' . $normalized['property'] . '|' . $normalized['viewport'] . '|' . $normalized['state'];

		// A repeat of an identity already measured is the same measurement, not a
		// second one. It is dropped before the check list as well as the difference
		// list, because a duplicated check would otherwise inflate the metric
		// denominator and understate the difference it is paired with.
		if ( isset( $this->seen[ $key ] ) ) {
			return false;
		}
		$this->seen[ $key ] = true;

		$this->checks[] = $normalized;

		if ( in_array( $state, array( 'pass', 'unknown' ), true ) ) {
			return false;
		}
		if ( count( $this->differences ) >= Validation_Limits::MAX_DIFFERENCES ) {
			$this->exhausted         = true;
			$this->exhaustion_reason = 'difference_budget';
			return false;
		}

		$normalized['id'] = 'diff_' . str_pad( (string) ( count( $this->differences ) + 1 ), 4, '0', STR_PAD_LEFT ) . '_' . substr( hash( 'sha256', $key ), 0, 8 );
		$this->differences[] = $normalized;

		return true;
	}

	/**
	 * Record a batch of checks.
	 *
	 * @param array<int, array<string, mixed>> $checks Check records.
	 * @return int
	 */
	public function record_many( array $checks ) {
		$count = 0;
		foreach ( $checks as $check ) {
			if ( is_array( $check ) && $this->record( $check ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Return the collected differences.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function differences() {
		return $this->differences;
	}

	/**
	 * Return the collected checks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function checks() {
		return $this->checks;
	}

	/**
	 * Count differences by severity.
	 *
	 * @return array<string, int>
	 */
	public function severity_counts() {
		$counts = array_fill_keys( Validation_Limits::SEVERITIES, 0 );
		foreach ( $this->differences as $difference ) {
			$severity = (string) $difference['severity'];
			if ( isset( $counts[ $severity ] ) ) {
				$counts[ $severity ]++;
			}
		}
		return $counts;
	}

	/**
	 * Count differences by category.
	 *
	 * @return array<string, int>
	 */
	public function category_counts() {
		$counts = array();
		foreach ( $this->differences as $difference ) {
			$category = (string) $difference['category'];
			$counts[ $category ] = isset( $counts[ $category ] ) ? $counts[ $category ] + 1 : 1;
		}
		return $counts;
	}

	/**
	 * Count differences by viewport.
	 *
	 * @return array<string, int>
	 */
	public function viewport_counts() {
		$counts = array_fill_keys( array_keys( Validation_Limits::VIEWPORTS ), 0 );
		foreach ( $this->differences as $difference ) {
			$viewport = (string) $difference['viewport'];
			if ( isset( $counts[ $viewport ] ) ) {
				$counts[ $viewport ]++;
			}
		}
		return $counts;
	}

	/**
	 * Resolve the severity of a check.
	 *
	 * @param string               $category Category key.
	 * @param string               $state    Check state.
	 * @param array<string, mixed> $check    Original check.
	 * @return string
	 */
	private function severity( $category, $state, array $check ) {
		if ( ! empty( $check['severity'] ) && in_array( $check['severity'], Validation_Limits::SEVERITIES, true ) ) {
			return (string) $check['severity'];
		}
		if ( 'unknown' === $state || 'pass' === $state ) {
			return 'informational';
		}
		if ( ! isset( $this->severity_table[ $category ] ) ) {
			return 'moderate';
		}
		if ( isset( $this->severity_table[ $category ][ $state ] ) ) {
			return $this->severity_table[ $category ][ $state ];
		}
		return 'moderate';
	}

	/**
	 * Return a safe target or property label.
	 *
	 * @param mixed $value      Raw value.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	private function label( $value, $max_length = 160 ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value || Elementor_Values::is_executable( $value ) ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_length, 'UTF-8' );
		}
		return substr( $value, 0, $max_length );
	}

	/**
	 * Return a human-readable message.
	 *
	 * @param mixed $value Raw message.
	 * @return string
	 */
	private function message( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		$value = Elementor_Values::is_executable( $value ) ? '' : $value;
		if ( '' === $value ) {
			return '';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, 300, 'UTF-8' );
		}
		return substr( $value, 0, 300 );
	}

	/**
	 * Return a safe scalar comparison value.
	 *
	 * @param mixed $value Raw value.
	 * @return string|int|float|bool|null
	 */
	private function scalar( $value ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( strlen( $value ) > 200 || Elementor_Values::is_executable( $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Normalize a reference block.
	 *
	 * @param mixed $value Raw reference.
	 * @return array<string, string>
	 */
	private function reference( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$allowed = array(
			'source_section_id',
			'source_component_id',
			'reconstruction_component_id',
			'elementor_element_id',
		);
		$result  = array();
		foreach ( $allowed as $key ) {
			if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
				$label = strtolower( trim( (string) $value[ $key ] ) );
				$label = preg_replace( '/[^a-z0-9_\-]/', '', $label );
				if ( is_string( $label ) && '' !== $label ) {
					$result[ $key ] = substr( $label, 0, 120 );
				}
			}
		}
		return $result;
	}

	/**
	 * Clamp a confidence value.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private function clamp( $value ) {
		$value = (float) $value;
		if ( $value < 0 ) {
			return 0.0;
		}
		return $value > 1 ? 1.0 : round( $value, 4 );
	}
}
