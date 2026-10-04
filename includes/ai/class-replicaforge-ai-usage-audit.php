<?php
/**
 * Phase 11: AI usage audit.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * A record of what each AI call was, who caused it, and what it produced.
 *
 * The brief asks for this and the instinct is to log the request. That is the one
 * thing to avoid. An AI request contains the page being reconstructed: its text,
 * its image URLs, its product names, and its prices. Logging it means retaining a
 * copy of somebody's website inside ReplicaForge's logs, for every AI call, for a
 * retention period nobody chose — §45 forbids exactly that.
 *
 * So this records **shape, not content**:
 *
 * - the provider and model, because a cost problem is always about a model;
 * - the byte and token *estimates*, because "this call was 90 KB" is what
 *   diagnoses a context problem;
 * - the attempt count and the outcome, because a retry loop is invisible otherwise;
 * - the cache key's identity, hashed, so repeated calls are visible without
 *   holding the input twice.
 *
 * What it never records: the prompt, the context, the response, the cache key's
 * pre-image, or anything the analysis already stores. The context is already in
 * the project's own data with its own TTL; duplicating it here would be the
 * privacy problem the class exists to avoid.
 *
 * Records are counted per period and summarised, not listed, for the common case.
 * A per-call log is useful for diagnosing one job and expensive to retain, so
 * individual records are kept only for the most recent window and everything older
 * collapses into a count.
 */
final class Ai_Usage_Audit {

	/**
	 * Option holding the per-period counters.
	 */
	const OPTION = 'replicaforge_ai_usage';

	/**
	 * Option holding the recent record window.
	 */
	const RECENT_OPTION = 'replicaforge_ai_usage_recent';

	/**
	 * Records kept in the recent window.
	 *
	 * A window rather than a full log. Diagnosing "why did this job make eleven AI
	 * requests" needs the last few dozen records; a year's worth needs a log file.
	 */
	const MAX_RECENT = 100;

	/**
	 * Context keys a record may carry.
	 *
	 * @var array<int, string>
	 */
	const CONTEXT_KEYS = array( 'user_id', 'project_id', 'job_id', 'operation', 'attempt', 'cache', 'chunk' );

	/**
	 * Values a result may be.
	 *
	 * @var array<int, string>
	 */
	const RESULTS = array( 'success', 'failure', 'cached', 'refused', 'cancelled' );

	/**
	 * Record one AI call.
	 *
	 * @param string               $provider  Provider identifier.
	 * @param string               $model     Model identifier.
	 * @param string               $operation Operation name.
	 * @param string               $result    One of {@see self::RESULTS}.
	 * @param array<string, mixed> $context   Context, filtered to the declared keys.
	 * @return array<string, mixed> The stored record.
	 */
	public static function record( $provider, $model, $operation, $result, array $context = array() ) {
		$result = in_array( (string) $result, self::RESULTS, true ) ? (string) $result : 'failure';

		$entry = array(
			'provider'  => substr( sanitize_key( (string) $provider ), 0, 40 ),
			'model'     => substr( sanitize_text_field( (string) $model ), 0, 60 ),
			'operation' => substr( sanitize_key( (string) $operation ), 0, 40 ),
			'result'    => $result,
			'period'    => Plan_Limits::period_key(),
			'timestamp' => time(),
		);

		foreach ( self::CONTEXT_KEYS as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}
			$value = $context[ $key ];
			if ( is_int( $value ) || is_float( $value ) ) {
				$entry[ $key ] = $value;
				continue;
			}
			if ( is_bool( $value ) ) {
				$entry[ $key ] = (int) $value;
				continue;
			}
			if ( is_string( $value ) ) {
				$entry[ $key ] = substr( sanitize_text_field( $value ), 0, 60 );
			}
		}

		foreach ( array( 'input_bytes', 'output_bytes', 'input_tokens', 'output_tokens', 'attempts', 'duration_ms' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_numeric( $context[ $key ] ) ) {
				$entry[ $key ] = max( 0, (int) $context[ $key ] );
			}
		}

		// A failure carries its classified code, which is a declared constant and
		// never a provider message.
		if ( isset( $context['failure_code'] ) && is_string( $context['failure_code'] ) && Ai_Failures::is_code( $context['failure_code'] ) ) {
			$entry['failure_code'] = (string) $context['failure_code'];
		}

		self::count( $entry );
		self::append( $entry );

		/**
		 * Fires after an AI call is recorded.
		 *
		 * @param array<string, mixed> $entry The stored record.
		 */
		do_action( 'replicaforge_ai_usage_recorded', $entry );

		return $entry;
	}

	/**
	 * Return the counts for a period.
	 *
	 * @param string $period Optional period key.
	 * @return array<string, mixed>
	 */
	public static function summary( $period = '' ) {
		$period = ( '' === $period || ! is_string( $period ) ) ? Plan_Limits::period_key() : $period;
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$row    = isset( $stored[ $period ] ) && is_array( $stored[ $period ] ) ? $stored[ $period ] : array();

		return array(
			'period'         => $period,
			'calls'          => (int) ( $row['calls'] ?? 0 ),
			'success'        => (int) ( $row['success'] ?? 0 ),
			'failure'        => (int) ( $row['failure'] ?? 0 ),
			'cached'         => (int) ( $row['cached'] ?? 0 ),
			'refused'        => (int) ( $row['refused'] ?? 0 ),
			'cancelled'      => (int) ( $row['cancelled'] ?? 0 ),
			'input_bytes'    => (int) ( $row['input_bytes'] ?? 0 ),
			'output_bytes'   => (int) ( $row['output_bytes'] ?? 0 ),
			'by_operation'   => ( is_array( $row['by_operation'] ?? null ) ? $row['by_operation'] : array() ),
			'by_model'       => ( is_array( $row['by_model'] ?? null ) ? $row['by_model'] : array() ),
			'by_failure'     => ( is_array( $row['by_failure'] ?? null ) ? $row['by_failure'] : array() ),
		);
	}

	/**
	 * Return the recent records, newest first.
	 *
	 * @param int    $limit Maximum records.
	 * @param string $event Optional failure code or result filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( $limit = 25, $event = '' ) {
		$stored = get_option( self::RECENT_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();
		foreach ( array_reverse( array_values( $stored ) ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( '' !== $event ) {
				$matches = ( (string) ( $entry['failure_code'] ?? '' ) === $event )
					|| ( (string) ( $entry['result'] ?? '' ) === $event );
				if ( ! $matches ) {
					continue;
				}
			}
			$out[] = $entry;
			if ( count( $out ) >= max( 1, min( self::MAX_RECENT, (int) $limit ) ) ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Return a per-user count for a period.
	 *
	 * Counted from the recent window, so it is exact only for the window. That is
	 * stated in the key name rather than presented as a total, because a
	 * "per-user AI calls" figure that silently stops counting is worse than one
	 * that admits its window.
	 *
	 * @param int    $user_id User id.
	 * @param string $period  Optional period key.
	 * @return array<string, mixed>
	 */
	public static function for_user( $user_id, $period = '' ) {
		$user_id = (int) $user_id;
		$period  = ( '' === $period || ! is_string( $period ) ) ? Plan_Limits::period_key() : $period;

		$records = get_option( self::RECENT_OPTION, array() );
		$records = is_array( $records ) ? $records : array();

		$count = 0;
		foreach ( $records as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( (int) ( $entry['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			if ( (string) ( $entry['period'] ?? '' ) !== $period ) {
				continue;
			}
			$count++;
		}

		return array(
			'user_id' => $user_id,
			'period'  => $period,
			'count'   => $count,
			'window'  => self::MAX_RECENT,
			'exact'   => ( count( $records ) < self::MAX_RECENT ),
		);
	}

	/**
	 * Return everything recorded for a job, oldest first.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_job( $job_id ) {
		$job_id = is_string( $job_id ) ? $job_id : '';
		if ( '' === $job_id ) {
			return array();
		}

		$stored = get_option( self::RECENT_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$out = array();
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && (string) ( $entry['job_id'] ?? '' ) === $job_id ) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	/**
	 * Remove the retained AI usage.
	 *
	 * §38 requires the user to be able to clear AI history. This removes the
	 * counters and the window, and it removes **only** those — the project's own
	 * analysis and specifications are governed by the existing project history
	 * rules and are not touched here.
	 *
	 * @return bool
	 */
	public static function forget() {
		$counts  = delete_option( self::OPTION );
		$recent  = delete_option( self::RECENT_OPTION );
		return (bool) ( $counts || $recent );
	}

	/**
	 * Increment the period counters.
	 *
	 * @param array<string, mixed> $entry Record.
	 * @return void
	 */
	private static function count( array $entry ) {
		$period = (string) $entry['period'];
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$row = isset( $stored[ $period ] ) && is_array( $stored[ $period ] ) ? $stored[ $period ] : array();

		$row['calls'] = (int) ( $row['calls'] ?? 0 ) + 1;
		$key          = (string) $entry['result'];
		$row[ $key ]  = (int) ( $row[ $key ] ?? 0 ) + 1;

		foreach ( array( 'input_bytes', 'output_bytes' ) as $field ) {
			if ( isset( $entry[ $field ] ) ) {
				$row[ $field ] = (int) ( $row[ $field ] ?? 0 ) + (int) $entry[ $field ];
			}
		}

		$operation                = (string) $entry['operation'];
		$by_operation             = ( is_array( $row['by_operation'] ?? null ) ? $row['by_operation'] : array() );
		$by_operation[ $operation ] = (int) ( $by_operation[ $operation ] ?? 0 ) + 1;

		$model         = (string) $entry['model'];
		$by_model      = ( is_array( $row['by_model'] ?? null ) ? $row['by_model'] : array() );
		$by_model[ $model ] = (int) ( $by_model[ $model ] ?? 0 ) + 1;

		// Initialised outside the branch below. A record with no failure code still
		// reaches the assignment, and reading an undefined variable there is a
		// notice on every successful call — which is most calls.
		$by_failure = ( is_array( $row['by_failure'] ?? null ) ? $row['by_failure'] : array() );
		if ( isset( $entry['failure_code'] ) ) {
			$code               = (string) $entry['failure_code'];
			$by_failure[ $code ] = (int) ( $by_failure[ $code ] ?? 0 ) + 1;
		}

		// A user's own counts, kept separately from the site total so a single-user
		// site's numbers are not a global counter in disguise.
		$user_id = (int) ( $entry['user_id'] ?? 0 );
		if ( $user_id > 0 ) {
			$by_user   = ( is_array( $row['by_user'] ?? null ) ? $row['by_user'] : array() );
			$by_user[ $user_id ] = (int) ( $by_user[ $user_id ] ?? 0 ) + 1;
			$row['by_user'] = $by_user;
		}

		$row['by_operation'] = $by_operation;
		$row['by_model']     = $by_model;
		$row['by_failure']   = $by_failure;

		// Only the current and previous period are kept. Older periods are counts
		// nobody reads, and this is an option.
		ksort( $stored );
		while ( count( $stored ) > 2 ) {
			array_shift( $stored );
		}
		$stored[ $period ] = $row;

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Append a record to the bounded window.
	 *
	 * @param array<string, mixed> $entry Record.
	 * @return void
	 */
	private static function append( array $entry ) {
		$stored = get_option( self::RECENT_OPTION, array() );
		$stored = is_array( $stored ) ? array_values( $stored ) : array();

		$stored[] = $entry;
		if ( count( $stored ) > self::MAX_RECENT ) {
			$stored = array_slice( $stored, -self::MAX_RECENT );
		}

		update_option( self::RECENT_OPTION, $stored, false );
	}
}
