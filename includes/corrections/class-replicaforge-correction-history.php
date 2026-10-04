<?php
/**
 * Correction history for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Records what was corrected, by whom, and with what outcome.
 *
 * A history record is the audit trail for a human decision. It stores the plan
 * that was reviewed, the identifiers that were selected, the values that changed,
 * the validation before and after, and the outcome of every correction, so a
 * later run can explain exactly what a previous run did to the document.
 */
final class Correction_History {

	/**
	 * Return the bounded correction history.
	 *
	 * @param int $limit Maximum records.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( $limit = 10 ) {
		$records = get_option( Correction_Limits::OPTION, array() );
		if ( ! is_array( $records ) ) {
			return array();
		}
		$records = array_values( array_filter( $records, 'is_array' ) );
		return array_slice( $records, 0, max( 1, absint( $limit ) ) );
	}

	/**
	 * Append one correction run to the history.
	 *
	 * @param array<string, mixed> $record Run record.
	 * @return array<string, mixed> The bounded record that was stored.
	 */
	public function record( array $record ) {
		$bounded = $this->bound( $record );

		$records = $this->recent( Correction_Limits::MAX_HISTORY );
		array_unshift( $records, $bounded );
		update_option( Correction_Limits::OPTION, array_slice( $records, 0, Correction_Limits::MAX_HISTORY ), false );

		Security::log_event(
			'correction_completed',
			array(
				'count'      => (int) $bounded['counts']['applied'],
				'iterations' => (int) $bounded['iterations'],
				'status'     => (string) $bounded['status'],
			)
		);

		return $bounded;
	}

	/**
	 * Return one history record.
	 *
	 * @param string $correction_id Correction run identifier.
	 * @return array<string, mixed>|null
	 */
	public function get( $correction_id ) {
		if ( ! is_string( $correction_id ) || ! preg_match( '/^cor_[a-f0-9]{20}$/', $correction_id ) ) {
			return null;
		}
		foreach ( $this->recent( Correction_Limits::MAX_HISTORY ) as $record ) {
			if ( isset( $record['correction_id'] ) && $record['correction_id'] === $correction_id ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * Return the correction runs recorded for a draft.
	 *
	 * @param int $post_id Draft post identifier.
	 * @param int $limit   Maximum records.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_draft( $post_id, $limit = 10 ) {
		$post_id = absint( $post_id );
		$result  = array();
		foreach ( $this->recent( Correction_Limits::MAX_HISTORY ) as $record ) {
			if ( isset( $record['post_id'] ) && (int) $record['post_id'] === $post_id ) {
				$result[] = $record;
			}
			if ( count( $result ) >= max( 1, absint( $limit ) ) ) {
				break;
			}
		}
		return $result;
	}

	/**
	 * Reduce a run record to bounded, non-sensitive fields.
	 *
	 * No source content, no credentials, and no full document are stored.
	 *
	 * @param array<string, mixed> $record Raw record.
	 * @return array<string, mixed>
	 */
	private function bound( array $record ) {
		$changes = array();
		$raw     = isset( $record['changes'] ) && is_array( $record['changes'] ) ? $record['changes'] : array();
		foreach ( array_slice( $raw, 0, Correction_Limits::MAX_CHANGES_PER_RUN ) as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			$status = isset( $change['status'] ) ? (string) $change['status'] : '';
			if ( ! in_array( $status, Correction_Limits::STATUSES, true ) ) {
				$status = 'skipped';
			}
			$changes[] = array(
				'correction_id' => isset( $change['correction_id'] ) ? $this->token( $change['correction_id'], 40 ) : '',
				'element_id'    => isset( $change['element_id'] ) ? $this->token( $change['element_id'], 20 ) : '',
				'property'      => isset( $change['property'] ) ? $this->token( $change['property'], 40 ) : '',
				'control'       => isset( $change['control'] ) ? $this->token( $change['control'], 60 ) : '',
				'viewport'      => isset( $change['viewport'] ) ? $this->token( $change['viewport'], 20 ) : '',
				'action'        => isset( $change['action'] ) ? $this->token( $change['action'], 30 ) : '',
				'old_value'     => $this->scalar( isset( $change['old_value'] ) ? $change['old_value'] : null ),
				'new_value'     => $this->scalar( isset( $change['new_value'] ) ? $change['new_value'] : null ),
				'status'        => $status,
			);
		}

		$counts = array(
			'applied'      => isset( $record['counts']['applied'] ) ? absint( $record['counts']['applied'] ) : 0,
			'rejected'     => isset( $record['counts']['rejected'] ) ? absint( $record['counts']['rejected'] ) : 0,
			'blocked'      => isset( $record['counts']['blocked'] ) ? absint( $record['counts']['blocked'] ) : 0,
			'failed'       => isset( $record['counts']['failed'] ) ? absint( $record['counts']['failed'] ) : 0,
			'skipped'      => isset( $record['counts']['skipped'] ) ? absint( $record['counts']['skipped'] ) : 0,
			'rolled_back'  => isset( $record['counts']['rolled_back'] ) ? absint( $record['counts']['rolled_back'] ) : 0,
		);

		return array(
			'correction_id'   => isset( $record['correction_id'] ) ? (string) $record['correction_id'] : '',
			'schema_version'  => Correction_Limits::SCHEMA_VERSION,
			'engine_version'  => Correction_Limits::ENGINE_VERSION,
			'post_id'         => isset( $record['post_id'] ) ? absint( $record['post_id'] ) : 0,
			'plan_id'         => isset( $record['plan_id'] ) ? (string) $record['plan_id'] : '',
			'validation_id'   => isset( $record['validation_id'] ) ? (string) $record['validation_id'] : '',
			'generation_id'   => isset( $record['generation_id'] ) ? (string) $record['generation_id'] : '',
			'snapshot_id'     => isset( $record['snapshot_id'] ) ? (string) $record['snapshot_id'] : '',
			'validation_before' => isset( $record['validation_before'] ) && is_numeric( $record['validation_before'] ) ? (float) $record['validation_before'] : null,
			'validation_after'  => isset( $record['validation_after'] ) && is_numeric( $record['validation_after'] ) ? (float) $record['validation_after'] : null,
			'improvement'       => isset( $record['improvement'] ) && is_numeric( $record['improvement'] ) ? round( (float) $record['improvement'], 2 ) : null,
			'iterations'      => isset( $record['iterations'] ) ? absint( $record['iterations'] ) : 1,
			'regressions'     => isset( $record['regressions'] ) && is_array( $record['regressions'] ) ? count( $record['regressions'] ) : 0,
			'regression_detail' => isset( $record['regressions'] ) && is_array( $record['regressions'] ) ? array_slice( $record['regressions'], 0, 20 ) : array(),
			'status'          => isset( $record['status'] ) ? sanitize_key( $record['status'] ) : 'unknown',
			'stop_reason'     => isset( $record['stop_reason'] ) ? $this->token( $record['stop_reason'], 40 ) : '',
			'counts'          => $counts,
			'changes'         => $changes,
			'warnings'        => isset( $record['warnings'] ) && is_array( $record['warnings'] )
				? array_slice( array_filter( array_map( array( $this, 'sentence' ), $record['warnings'] ) ), 0, 20 )
				: array(),
			'created_by'      => get_current_user_id(),
			'created_at'      => gmdate( 'c' ),
		);
	}

	/**
	 * Return a safe short token.
	 *
	 * A token is a key, a status, or a property name. It is lowercased and stripped
	 * of anything that is not a word character, so it must never be used for a
	 * sentence a person reads: the spaces would be removed and the result would be
	 * unreadable.
	 *
	 * @param mixed $value      Raw value.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	private function token( $value, $max_length = 200 ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_.\-]/', '', $value );
		return is_string( $value ) ? substr( $value, 0, $max_length ) : '';
	}

	/**
	 * Return a safe sentence for a person to read.
	 *
	 * Executable content is refused outright rather than escaped, because a warning
	 * is ReplicaForge's own text and a warning that needed escaping would mean
	 * something unexpected had reached it. Tags are stripped and the length is
	 * bounded, because the result is rendered on the review screen and stored in an
	 * option.
	 *
	 * @param mixed $value      Raw value.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	private function sentence( $value, $max_length = 300 ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( Elementor_Values::is_executable( $value ) ) {
			return '';
		}
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		$value = is_string( $value ) ? trim( preg_replace( '/\s+/', ' ', $value ) ) : '';

		return '' === $value ? '' : substr( $value, 0, $max_length );
	}

	/**
	 * Return a safe scalar representation of a value.
	 *
	 * @param mixed $value Raw value.
	 * @return string|int|float|bool|null
	 */
	private function scalar( $value ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return Elementor_Values::is_executable( $value ) ? null : substr( $value, 0, 200 );
		}
		if ( is_array( $value ) ) {
			$encoded = wp_json_encode( $value );
			return is_string( $encoded ) && ! Elementor_Values::is_executable( $encoded ) ? substr( $encoded, 0, 200 ) : null;
		}
		return null;
	}
}
