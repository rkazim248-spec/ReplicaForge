<?php
/**
 * Phase 11: job checkpoints.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers where a job got to, so a restart does not repeat expensive work.
 *
 * Phase 7's `Job_Queue::advance()` accepts a checkpoint array, and
 * `Job_Repository::set_payload()` stores arbitrary per-job data. So the storage
 * exists. What did not exist is a **shape** — and a shape is the whole point,
 * because a checkpoint nobody can read is a checkpoint that cannot be resumed
 * from, and the temptation when resuming is to re-run the stage, which is
 * exactly the expense the checkpoint was meant to avoid.
 *
 * Three rules make a checkpoint trustworthy:
 *
 * 1. **A checkpoint is only accepted if it names a stage this job type declares.**
 *    An unrecognised stage is dropped rather than stored, so a typo cannot produce
 *    a record that claims a job finished something it never started.
 * 2. **Monotonic.** A checkpoint never moves backwards within an attempt. A late
 *    write from a stage that has already been superseded must not roll a job back
 *    to an earlier stage, which would make the job loop forever between two
 *    stages.
 * 3. **Bounded.** A checkpoint is a summary of completed work, not the work
 *    itself. It carries ids and hashes, and its size is capped, so a job that
 *    stores a megabyte of intermediate output in its checkpoint cannot exhaust
 *    the option it lives in.
 *
 * The last rule is why a checkpoint is a *pointer* to intermediate data rather than
 * the data. Large intermediate artefacts — a design representation, a validation
 * result — already have their own stores with their own TTLs, and a checkpoint
 * records where they are.
 */
final class Job_Checkpoint {

	/**
	 * Maximum bytes a checkpoint may occupy when serialized.
	 *
	 * Small enough that a hundred checkpoints fit in an option comfortably, large
	 * enough for a list of section ids on a large page.
	 */
	const MAX_BYTES = 8192;

	/**
	 * Maximum entries in a checkpoint list value.
	 */
	const MAX_LIST = 200;

	/**
	 * Maximum length of a single scalar value.
	 */
	const MAX_SCALAR = 200;

	/**
	 * Keys a checkpoint may carry.
	 *
	 * A closed list, for the same reason `Audit_Log` has one: a checkpoint is
	 * written by stage code, and a closed list is what makes "this is the shape we
	 * will read back" a property rather than a hope.
	 *
	 * @var array<int, string>
	 */
	const KEYS = array(
		'stage',
		'completed_stages',
		'section_ids',
		'component_count',
		'design_hash',
		'specification_hash',
		'draft_id',
		'validation_id',
		'observations',
	);

	/**
	 * Return a clean, empty checkpoint.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_checkpoint() {
		return array(
			'stage'            => Job_Limits::STAGES[0],
			'completed_stages' => array(),
			'section_ids'      => array(),
			'component_count'  => 0,
			'design_hash'      => '',
			'specification_hash' => '',
			'draft_id'         => 0,
			'validation_id'    => '',
			'observations'     => array(),
		);
	}

	/**
	 * Return whether a stage may be recorded as complete.
	 *
	 * @param mixed $stage Stage name.
	 * @return bool
	 */
	public static function is_stage( $stage ) {
		return is_string( $stage ) && in_array( $stage, Job_Limits::STAGES, true );
	}

	/**
	 * Merge a partial checkpoint into an existing one.
	 *
	 * The merge is where the three rules are enforced. `stage` is compared by index
	 * rather than by name, so a job cannot move from `correct` back to `analyze`
	 * and re-run work it already paid for.
	 *
	 * @param array<string, mixed> $current  The stored checkpoint.
	 * @param array<string, mixed> $incoming What a stage reports.
	 * @return array<string, mixed>
	 */
	public static function merge( array $current, array $incoming ) {
		$base   = self::empty_checkpoint();
		$merged = array_merge( $base, self::sanitize( $current ) );
		$add    = self::sanitize( $incoming );

		// `stage` only moves forward.
		$from_index = Job_Limits::stage_index( (string) $merged['stage'] );
		$to_index   = Job_Limits::stage_index( (string) $add['stage'] );
		if ( '' !== (string) $add['stage'] && $to_index >= $from_index ) {
			$merged['stage'] = (string) $add['stage'];
		}

		// Completed stages accumulate and stay ordered, so the list is a record of
		// what happened rather than the last thing that happened.
		$done = array_values( array_unique( array_merge( (array) $merged['completed_stages'], (array) $add['completed_stages'] ) ) );
		$done = array_values(
			array_filter(
				$done,
				static function ( $stage ) {
					return self::is_stage( $stage ) && Job_Limits::STAGES[0] !== $stage;
				}
			)
		);
		usort(
			$done,
			static function ( $left, $right ) {
				return Job_Limits::stage_index( (string) $left ) <=> Job_Limits::stage_index( (string) $right );
			}
		);
		$merged['completed_stages'] = $done;

		// Section ids accumulate up to the cap. A page with more sections than the
		// cap still records the first N, and the count says how many there were, so
		// a truncated list is never mistaken for a complete one.
		$requested = (int) ( $add['component_count'] ?? 0 );
		$sections  = array_values( array_unique( array_merge( (array) $merged['section_ids'], (array) $add['section_ids'] ) ) );
		$merged['section_ids']     = array_slice( $sections, 0, self::MAX_LIST );
		$merged['component_count'] = max( (int) $merged['component_count'], $requested );

		foreach ( array( 'design_hash', 'specification_hash', 'validation_id' ) as $key ) {
			if ( '' !== (string) $add[ $key ] ) {
				$merged[ $key ] = (string) $add[ $key ];
			}
		}

		if ( (int) $add['draft_id'] > 0 ) {
			$merged['draft_id'] = (int) $add['draft_id'];
		}

		$observations            = array_values( array_unique( array_merge( (array) $merged['observations'], (array) $add['observations'] ) ) );
		$merged['observations']  = array_slice( $observations, 0, 20 );

		return $merged;
	}

	/**
	 * Reduce a checkpoint to the shape that may be stored.
	 *
	 * @param array<string, mixed> $checkpoint Raw checkpoint.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $checkpoint ) {
		$out = self::empty_checkpoint();

		$stage = isset( $checkpoint['stage'] ) ? (string) $checkpoint['stage'] : '';
		$out['stage'] = self::is_stage( $stage ) ? $stage : Job_Limits::STAGES[0];

		$done = array();
		foreach ( (array) ( $checkpoint['completed_stages'] ?? array() ) as $entry ) {
			if ( self::is_stage( $entry ) ) {
				$done[] = (string) $entry;
			}
		}
		$out['completed_stages'] = array_values( array_unique( $done ) );

		$out['section_ids']     = self::clean_list( $checkpoint['section_ids'] ?? array() );
		$out['observations']    = self::clean_list( $checkpoint['observations'] ?? array() );
		$out['component_count'] = max( 0, (int) ( $checkpoint['component_count'] ?? 0 ) );
		$out['draft_id']        = max( 0, (int) ( $checkpoint['draft_id'] ?? 0 ) );

		foreach ( array( 'design_hash', 'specification_hash', 'validation_id' ) as $key ) {
			$value = $checkpoint[ $key ] ?? '';
			if ( is_scalar( $value ) ) {
				$out[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 64 );
			}
		}

		return $out;
	}

	/**
	 * Return whether a checkpoint's serialized size is within the cap.
	 *
	 * A checkpoint is written by stage code, so exceeding the cap is a bug in the
	 * plugin rather than bad input. This is reported rather than enforced, because
	 * dropping an over-sized checkpoint silently would lose the resume point
	 * without saying so.
	 *
	 * @param array<string, mixed> $checkpoint Checkpoint.
	 * @return array{ok: bool, bytes: int}
	 */
	public static function measure( array $checkpoint ) {
		$encoded = wp_json_encode( self::sanitize( $checkpoint ) );
		$bytes   = ( false === $encoded ) ? 0 : strlen( $encoded );
		return array(
			'ok'    => ( $bytes <= self::MAX_BYTES ),
			'bytes' => $bytes,
		);
	}

	/**
	 * Return the stage a job should resume from.
	 *
	 * This is the function the whole class exists for. A job whose checkpoint says
	 * it completed `design` resumes at `ai`, not at `queued`, so an interrupted
	 * reconstruction does not re-pay for the analysis that came before it.
	 *
	 * @param array<string, mixed> $checkpoint Stored checkpoint.
	 * @return string
	 */
	public static function resume_stage( array $checkpoint ) {
		$clean = self::sanitize( $checkpoint );

		// The first declared stage that is not in the completed list is where work
		// must resume. Computed from the stage list rather than from the recorded
		// `stage`, so a checkpoint that recorded a stage without recording the
		// completion still resumes at that stage rather than skipping past it.
		foreach ( Job_Limits::STAGES as $stage ) {
			if ( Job_Limits::STAGES[0] === $stage ) {
				continue;
			}
			if ( ! in_array( $stage, (array) $clean['completed_stages'], true ) ) {
				return $stage;
			}
		}

		return (string) end( Job_Limits::STAGES );
	}

	/**
	 * Return how much of a job is done, as a derived percentage.
	 *
	 * Derived from the stage list and never invented. A job that has done nothing
	 * reports 0 and a stalled job reports a stalled number, which is the property
	 * §48 is really asking for: a progress bar that never moves is honest, and one
	 * that advances on a timer is a lie.
	 *
	 * @param array<string, mixed> $checkpoint Stored checkpoint.
	 * @return array<string, mixed>
	 */
	public static function progress( array $checkpoint ) {
		$clean   = self::sanitize( $checkpoint );
		$weights = Job_Limits::stage_weights();
		$total   = array_sum( $weights );
		if ( $total < 1 ) {
			return array( 'percent' => 0, 'stage' => $clean['stage'], 'label' => '', 'complete' => false );
		}

		$done   = 0;
		$label  = '';
		$index  = 0;
		$number = 0;

		foreach ( Job_Limits::STAGES as $position => $stage ) {
			$weight = (float) ( $weights[ $stage ] ?? 0 );
			if ( in_array( $stage, (array) $clean['completed_stages'], true ) ) {
				$done += $weight;
			}
			if ( Job_Limits::stage_index( $clean['stage'] ) === $position ) {
				$label  = self::stage_label( $stage );
				$index  = $position;
				$number = count( Job_Limits::STAGES );
				break;
			}
		}

		$percent = (int) min( 100, max( 0, (int) round( ( $done / $total ) * 100 ) ) );

		return array(
			'percent'  => $percent,
			'stage'    => (string) $clean['stage'],
			'label'    => (string) $label,
			'position' => (int) $index,
			'of'       => (int) $number,
			'complete' => ( $percent >= 100 ),
		);
	}

	/**
	 * Return a user-facing label for a stage.
	 *
	 * @param string $stage Stage name.
	 * @return string
	 */
	public static function stage_label( $stage ) {
		$labels = array(
			'queued'    => __( 'Queued', 'replicaforge' ),
			'analyze'   => __( 'Analyzing website', 'replicaforge' ),
			'design'    => __( 'Reading the design system', 'replicaforge' ),
			'ai'        => __( 'Reconstructing with AI', 'replicaforge' ),
			'generate'  => __( 'Building the Elementor draft', 'replicaforge' ),
			'validate'  => __( 'Measuring the differences', 'replicaforge' ),
			'correct'   => __( 'Applying corrections', 'replicaforge' ),
			'finalize'  => __( 'Finishing up', 'replicaforge' ),
		);

		return isset( $labels[ $stage ] ) ? $labels[ $stage ] : (string) $stage;
	}

	/**
	 * Reduce a list to a clean, bounded, string list.
	 *
	 * @param mixed $value Candidate.
	 * @return array<int, string>
	 */
	private static function clean_list( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $entry ) {
			if ( ! is_scalar( $entry ) ) {
				continue;
			}
			$entry = substr( sanitize_text_field( (string) $entry ), 0, self::MAX_SCALAR );
			if ( '' !== $entry ) {
				$out[] = $entry;
			}
			if ( count( $out ) >= self::MAX_LIST ) {
				break;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
