<?php
/**
 * Phase 10: the project status vocabulary, health, and timeline.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * One definition of what a project's status means, what state it is in, and how it
 * got there.
 *
 * §16 asks for a single status vocabulary and §17 for a health state, with the
 * explicit constraint that health must be based on real project state and must not
 * be a fabricated score. Both are here, and the health rules are written as a
 * precedence rather than as a pile of independent checks, because a project can
 * satisfy several of them at once and "which one wins" has to be decided once.
 *
 * Two things this class will not do:
 *
 * It will not report a health state it cannot derive. A project with a validation
 * result whose metrics cannot be read is `needs_review` with the reason
 * `validation_metrics_unreadable`, not `healthy`. Saying "healthy" there would be
 * asserting something the plugin does not know, and the whole point of a health
 * indicator is that it is a claim.
 *
 * It will not invent a combined score. §18 allows one if it already exists and
 * requires its calculation to be explained. {@see self::quality()} reports the
 * per-group metrics that Phase 5 actually produces and, separately, reports a
 * combined value only when one is present in the stored result — with the
 * explanation attached.
 */
final class Project_Status {

	/**
	 * A project that has been recorded but has not been analyzed.
	 */
	const NEW = 'new';

	/**
	 * An analysis is running.
	 */
	const ANALYZING = 'analyzing';

	/**
	 * An analysis has completed.
	 */
	const ANALYZED = 'analyzed';

	/**
	 * A reconstruction is being planned.
	 */
	const PLANNING = 'planning';

	/**
	 * A plan exists and generation could start.
	 */
	const READY_TO_GENERATE = 'ready_to_generate';

	/**
	 * A draft is being generated.
	 */
	const GENERATING = 'generating';

	/**
	 * A draft has been generated.
	 */
	const GENERATED = 'generated';

	/**
	 * A validation is running.
	 */
	const VALIDATING = 'validating';

	/**
	 * A validation found differences that corrections could address.
	 */
	const NEEDS_CORRECTION = 'needs_correction';

	/**
	 * Source monitoring is watching this project.
	 */
	const MONITORING = 'monitoring';

	/**
	 * The source changed and a review is waiting.
	 */
	const SYNC_REVIEW = 'sync_review';

	/**
	 * A sync is being applied.
	 */
	const SYNCING = 'syncing';

	/**
	 * Nothing is outstanding.
	 */
	const COMPLETED = 'completed';

	/**
	 * Something failed.
	 */
	const FAILED = 'failed';

	/**
	 * The project is kept but not worked on.
	 */
	const ARCHIVED = 'archived';

	/**
	 * Every status, in workflow order.
	 *
	 * @var array<int, string>
	 */
	const STATUSES = array(
		self::NEW,
		self::ANALYZING,
		self::ANALYZED,
		self::PLANNING,
		self::READY_TO_GENERATE,
		self::GENERATING,
		self::GENERATED,
		self::VALIDATING,
		self::NEEDS_CORRECTION,
		self::MONITORING,
		self::SYNC_REVIEW,
		self::SYNCING,
		self::COMPLETED,
		self::FAILED,
		self::ARCHIVED,
	);

	/**
	 * Statuses that mean work is in flight.
	 *
	 * @var array<int, string>
	 */
	const IN_PROGRESS = array(
		self::ANALYZING,
		self::PLANNING,
		self::GENERATING,
		self::VALIDATING,
		self::SYNCING,
	);

	/**
	 * Statuses a project is finished in.
	 *
	 * @var array<int, string>
	 */
	const TERMINAL = array( self::COMPLETED, self::ARCHIVED );

	/**
	 * Health: nothing outstanding.
	 */
	const HEALTHY = 'healthy';

	/**
	 * Health: something needs a person's attention.
	 */
	const NEEDS_REVIEW = 'needs_review';

	/**
	 * Health: the source changed.
	 */
	const SYNC_AVAILABLE = 'sync_available';

	/**
	 * Health: the last validation found differences.
	 */
	const VALIDATION_ISSUES = 'validation_issues';

	/**
	 * Health: the source could not be reached.
	 */
	const SOURCE_UNREACHABLE = 'source_unreachable';

	/**
	 * Health: generation did not complete.
	 */
	const GENERATION_FAILED = 'generation_failed';

	/**
	 * Health: the document was edited by hand.
	 */
	const MANUAL_CHANGES = 'manual_changes';

	/**
	 * Every health state.
	 *
	 * @var array<int, string>
	 */
	const HEALTH_STATES = array(
		self::HEALTHY,
		self::NEEDS_REVIEW,
		self::SYNC_AVAILABLE,
		self::VALIDATION_ISSUES,
		self::SOURCE_UNREACHABLE,
		self::GENERATION_FAILED,
		self::MANUAL_CHANGES,
	);

	/**
	 * The allowed status transitions.
	 *
	 * A map, not a list, because "is this transition allowed" needs both sides. A
	 * flat list of reachable statuses could not distinguish a legitimate move from
	 * an impossible one, and the interesting cases are exactly the impossible
	 * ones: a project cannot go from `completed` straight to `generating` without
	 * passing through a state that says a new generation has begun, and it cannot
	 * leave `failed` for `archived` without the failure being acknowledged.
	 *
	 * @var array<string, array<int, string>>
	 */
	const TRANSITIONS = array(
		self::NEW                => array( self::ANALYZING, self::ARCHIVED, self::FAILED ),
		self::ANALYZING         => array( self::ANALYZED, self::FAILED, self::ARCHIVED ),
		self::ANALYZED          => array( self::PLANNING, self::READY_TO_GENERATE, self::ANALYZING, self::ARCHIVED, self::FAILED ),
		self::PLANNING          => array( self::READY_TO_GENERATE, self::GENERATING, self::FAILED, self::ARCHIVED ),
		self::READY_TO_GENERATE => array( self::GENERATING, self::ANALYZING, self::FAILED, self::ARCHIVED ),
		self::GENERATING        => array( self::GENERATED, self::FAILED, self::ARCHIVED ),
		self::GENERATED         => array( self::VALIDATING, self::NEEDS_CORRECTION, self::COMPLETED, self::MONITORING, self::SYNCING, self::FAILED, self::ARCHIVED ),
		self::VALIDATING        => array( self::GENERATED, self::NEEDS_CORRECTION, self::COMPLETED, self::FAILED, self::MONITORING ),
		self::NEEDS_CORRECTION  => array( self::VALIDATING, self::COMPLETED, self::MONITORING, self::FAILED, self::ARCHIVED ),
		self::MONITORING        => array( self::SYNC_REVIEW, self::SYNCING, self::VALIDATING, self::NEEDS_CORRECTION, self::COMPLETED, self::ARCHIVED, self::FAILED ),
		self::SYNC_REVIEW       => array( self::SYNCING, self::MONITORING, self::VALIDATING, self::NEEDS_CORRECTION, self::COMPLETED, self::FAILED ),
		self::SYNCING           => array( self::MONITORING, self::VALIDATING, self::NEEDS_CORRECTION, self::COMPLETED, self::FAILED ),
		self::COMPLETED         => array( self::MONITORING, self::VALIDATING, self::ANALYZING, self::SYNC_REVIEW, self::ARCHIVED ),
		self::FAILED            => array( self::ANALYZING, self::READY_TO_GENERATE, self::GENERATING, self::VALIDATING, self::ARCHIVED ),
		self::ARCHIVED          => array( self::NEW ),
	);

	/* ---------------------------------------------------------------------
	 * Status vocabulary
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether a value is a declared status.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		return is_string( $value ) && in_array( $value, self::STATUSES, true );
	}

	/**
	 * Return the status a project record declares.
	 *
	 * An unrecognised status reads as {@see self::NEW} rather than as an error,
	 * because refusing to display a project whose status a future version changed
	 * would be worse than showing it at the start of the workflow.
	 *
	 * @param array<string, mixed> $project Project record.
	 * @return string
	 */
	public static function of( array $project ) {
		$status = isset( $project['status'] ) ? (string) $project['status'] : '';
		return self::is_valid( $status ) ? $status : self::NEW;
	}

	/**
	 * Return whether a transition is allowed.
	 *
	 * A transition to the same status is always allowed. A workflow frequently
	 * re-asserts the state it is already in — an analysis that starts from
	 * `analyzed` and returns to `analyzed` — and refusing that would make the
	 * vocabulary unusable without a special case at every call site.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 * @return bool
	 */
	public static function can_transition( $from, $to ) {
		if ( ! self::is_valid( $from ) || ! self::is_valid( $to ) ) {
			return false;
		}
		if ( $from === $to ) {
			return true;
		}
		$allowed = isset( self::TRANSITIONS[ $from ] ) ? self::TRANSITIONS[ $from ] : array();
		return in_array( $to, $allowed, true );
	}

	/**
	 * Return the statuses reachable from a status.
	 *
	 * @param string $status Current status.
	 * @return array<int, string>
	 */
	public static function reachable_from( $status ) {
		if ( ! self::is_valid( $status ) ) {
			return array();
		}
		$allowed = isset( self::TRANSITIONS[ $status ] ) ? self::TRANSITIONS[ $status ] : array();
		$out     = array( $status );
		foreach ( $allowed as $target ) {
			if ( ! in_array( $target, $out, true ) ) {
				$out[] = $target;
			}
		}
		return $out;
	}

	/**
	 * Return the status label.
	 *
	 * @param string $status Status name.
	 * @return string
	 */
	public static function label( $status ) {
		$labels = array(
			self::NEW                => __( 'New', 'replicaforge' ),
			self::ANALYZING         => __( 'Analyzing', 'replicaforge' ),
			self::ANALYZED          => __( 'Analyzed', 'replicaforge' ),
			self::PLANNING          => __( 'Planning', 'replicaforge' ),
			self::READY_TO_GENERATE => __( 'Ready to generate', 'replicaforge' ),
			self::GENERATING        => __( 'Generating', 'replicaforge' ),
			self::GENERATED         => __( 'Generated', 'replicaforge' ),
			self::VALIDATING        => __( 'Validating', 'replicaforge' ),
			self::NEEDS_CORRECTION  => __( 'Needs correction', 'replicaforge' ),
			self::MONITORING        => __( 'Monitoring', 'replicaforge' ),
			self::SYNC_REVIEW       => __( 'Sync review', 'replicaforge' ),
			self::SYNCING           => __( 'Syncing', 'replicaforge' ),
			self::COMPLETED         => __( 'Completed', 'replicaforge' ),
			self::FAILED            => __( 'Failed', 'replicaforge' ),
			self::ARCHIVED          => __( 'Archived', 'replicaforge' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Return the full status vocabulary, for a screen or a filter.
	 *
	 * @return array<string, array{label: string, in_progress: bool, terminal: bool, reachable: array<int, string>}>
	 */
	public static function vocabulary() {
		$out = array();
		foreach ( self::STATUSES as $status ) {
			$out[ $status ] = array(
				'label'       => self::label( $status ),
				'in_progress' => in_array( $status, self::IN_PROGRESS, true ),
				'terminal'    => in_array( $status, self::TERMINAL, true ),
				'reachable'   => self::reachable_from( $status ),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Health
	 * ------------------------------------------------------------------ */

	/**
	 * Return a project's health.
	 *
	 * The rules are a precedence chain, most urgent first. A project that failed to
	 * generate and also has pending corrections reports the failure, because the
	 * corrections are about a document that does not exist. The order is not a
	 * guess about which matters more; it is the order in which a later fact
	 * presupposes an earlier one.
	 *
	 * @param array<string, mixed> $project Project record.
	 * @return array<string, mixed>
	 */
	public static function health( array $project ) {
		$status  = self::of( $project );
		$reasons = array();

		if ( self::FAILED === $status ) {
			return self::health_result(
				self::GENERATION_FAILED,
				array( 'generation_failed' ),
				$status
			);
		}

		$sync = self::sync_signal( $project );
		if ( '' !== $sync ) {
			return self::health_result( self::SYNC_AVAILABLE, array( $sync ), $status );
		}

		$unreachable = self::unreachable_signal( $project );
		if ( '' !== $unreachable ) {
			return self::health_result( self::SOURCE_UNREACHABLE, array( $unreachable ), $status );
		}

		$manual = self::manual_change_signal( $project );
		if ( '' !== $manual ) {
			return self::health_result( self::MANUAL_CHANGES, array( $manual ), $status );
		}

		$validation = self::validation_signal( $project );
		if ( self::VALIDATION_ISSUES === $validation ) {
			return self::health_result( self::VALIDATION_ISSUES, array( 'validation_differences' ), $status );
		}
		if ( 'validation_unreadable' === $validation ) {
			return self::health_result( self::NEEDS_REVIEW, array( 'validation_metrics_unreadable' ), $status );
		}

		if ( self::NEEDS_CORRECTION === $status ) {
			$reasons[] = 'corrections_pending';
		}

		$warnings = isset( $project['warnings'] ) && is_array( $project['warnings'] ) ? $project['warnings'] : array();
		if ( array() !== $warnings ) {
			$reasons[] = 'project_warnings';
		}

		if ( array() === $reasons ) {
			return self::health_result( self::HEALTHY, array(), $status );
		}

		return self::health_result( self::NEEDS_REVIEW, $reasons, $status );
	}

	/**
	 * Return a project's health label.
	 *
	 * @param string $state Health state.
	 * @return string
	 */
	public static function health_label( $state ) {
		$labels = array(
			self::HEALTHY             => __( 'Healthy', 'replicaforge' ),
			self::NEEDS_REVIEW        => __( 'Needs review', 'replicaforge' ),
			self::SYNC_AVAILABLE      => __( 'Sync available', 'replicaforge' ),
			self::VALIDATION_ISSUES   => __( 'Validation issues', 'replicaforge' ),
			self::SOURCE_UNREACHABLE  => __( 'Source unreachable', 'replicaforge' ),
			self::GENERATION_FAILED   => __( 'Generation failed', 'replicaforge' ),
			self::MANUAL_CHANGES      => __( 'Manual changes detected', 'replicaforge' ),
		);

		return isset( $labels[ $state ] ) ? $labels[ $state ] : (string) $state;
	}

	/**
	 * Return an explanation of a health reason.
	 *
	 * @param string $reason Machine reason.
	 * @return string
	 */
	public static function health_reason( $reason ) {
		$reasons = array(
			'generation_failed'             => __( 'The last generation did not complete.', 'replicaforge' ),
			'source_change_pending'         => __( 'The source page has changed since it was last analyzed.', 'replicaforge' ),
			'source_unreachable'            => __( 'The source page could not be reached the last time it was checked.', 'replicaforge' ),
			'manual_element_edits'         => __( 'The generated draft has been edited by hand since ReplicaForge wrote it.', 'replicaforge' ),
			'validation_differences'        => __( 'The last validation found differences between the source and the draft.', 'replicaforge' ),
			'validation_metrics_unreadable' => __( 'A validation result exists but its metrics could not be read, so it was not scored.', 'replicaforge' ),
			'corrections_pending'           => __( 'Corrections have been proposed and have not been applied.', 'replicaforge' ),
			'project_warnings'              => __( 'The project recorded warnings during its last run.', 'replicaforge' ),
		);

		return isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : (string) $reason;
	}

	/* ---------------------------------------------------------------------
	 * Quality
	 * ------------------------------------------------------------------ */

	/**
	 * Return the per-group validation metrics for a project.
	 *
	 * §18 asks for separate metrics and forbids collapsing everything into one
	 * number. The group names come from {@see Validation_Limits::METRIC_GROUPS} —
	 * the same declaration Phase 5 scores with — so the list here cannot drift from
	 * what the engine produces.
	 *
	 * @param array<string, mixed> $project Project record.
	 * @return array<string, mixed>
	 */
	public static function quality( array $project ) {
		$validation = isset( $project['validation'] ) && is_array( $project['validation'] ) ? $project['validation'] : array();

		$out = array(
			'available'  => array() !== $validation,
			'groups'     => array(),
			'combined'   => null,
			'combined_explained' => '',
			'levels'     => '',
			'validated_at' => isset( $validation['created_at'] ) ? (string) $validation['created_at'] : '',
		);

		if ( array() === $validation ) {
			return $out;
		}

		$groups = array();
		if ( isset( $validation['metrics']['groups'] ) && is_array( $validation['metrics']['groups'] ) ) {
			$groups = $validation['metrics']['groups'];
		}

		foreach ( array_keys( Validation_Limits::METRIC_GROUPS ) as $group ) {
			$value = null;
			if ( isset( $groups[ $group ] ) ) {
				$value = self::read_score( $groups[ $group ] );
			}
			$out['groups'][ $group ] = array(
				'label' => self::group_label( $group ),
				'value' => $value,
				// A group with no value is shown as "not measured" rather than as a
				// zero. A zero would read as "this part of the replica is completely
				// wrong", which is a much stronger claim than "not measured".
				'measured' => ( null !== $value ),
			);
		}

		$out['levels'] = self::read_level( $validation );

		$combined = self::read_combined_score( $validation );
		if ( null !== $combined ) {
			$out['combined']           = $combined;
			$out['combined_explained'] = __( 'Weighted average of the per-group scores, using the weight ReplicaForge assigns to each validation category.', 'replicaforge' );
		}

		return $out;
	}

	/**
	 * Return a translated label for a validation metric group.
	 *
	 * @param string $group Group name.
	 * @return string
	 */
	public static function group_label( $group ) {
		$labels = array(
			'structure'  => __( 'Structure', 'replicaforge' ),
			'layout'     => __( 'Layout', 'replicaforge' ),
			'typography' => __( 'Typography', 'replicaforge' ),
			'colors'     => __( 'Colors', 'replicaforge' ),
			'spacing'    => __( 'Spacing', 'replicaforge' ),
			'assets'     => __( 'Assets', 'replicaforge' ),
			'content'    => __( 'Content', 'replicaforge' ),
			'responsive' => __( 'Responsive', 'replicaforge' ),
			'detail'     => __( 'Detail', 'replicaforge' ),
		);

		return isset( $labels[ $group ] ) ? $labels[ $group ] : (string) $group;
	}

	/* ---------------------------------------------------------------------
	 * Timeline
	 * ------------------------------------------------------------------ */

	/**
	 * Return a project's timeline, newest first.
	 *
	 * §19 asks for this and asks that existing history be reused rather than
	 * duplicated. The project record already carries `versions`, each with a
	 * timestamp and a kind, and the analysis, design, validation, and correction
	 * fields each carry their own `created_at`. Everything below is read from those;
	 * no new event store is created.
	 *
	 * @param array<string, mixed> $project Project record.
	 * @param int                  $limit   Maximum entries.
	 * @return array<int, array<string, mixed>>
	 */
	public static function timeline( array $project, $limit = 40 ) {
		$events = array();

		if ( ! empty( $project['created_at'] ) ) {
			$events[] = self::event( $project['created_at'], 'project_created', __( 'Project created', 'replicaforge' ) );
		}

		$versions = isset( $project['versions'] ) && is_array( $project['versions'] ) ? $project['versions'] : array();
		foreach ( $versions as $version ) {
			if ( ! is_array( $version ) ) {
				continue;
			}
			$label = isset( $version['label'] ) ? (string) $version['label'] : ( isset( $version['kind'] ) ? (string) $version['kind'] : '' );
			$at    = self::version_time( $version );
			if ( '' === $at || '' === $label ) {
				continue;
			}
			$events[] = self::event( $at, 'version', $label );
		}

		foreach ( array( 'analysis', 'design', 'specification', 'validation', 'corrections' ) as $field ) {
			$value = isset( $project[ $field ] ) ? $project[ $field ] : null;
			if ( ! is_array( $value ) || array() === $value ) {
				continue;
			}
			$at = isset( $value['created_at'] ) ? (string) $value['created_at'] : '';
			if ( '' === $at ) {
				continue;
			}
			$events[] = self::event( $at, $field, self::field_label( $field ) );
		}

		if ( ! empty( $project['updated_at'] ) && $events ) {
			$events[] = self::event( $project['updated_at'], 'project_updated', __( 'Project updated', 'replicaforge' ) );
		}

		usort(
			$events,
			static function ( $left, $right ) {
				if ( $left['timestamp'] === $right['timestamp'] ) {
					return strcmp( $left['kind'], $right['kind'] );
				}
				return ( $left['timestamp'] < $right['timestamp'] ) ? 1 : -1;
			}
		);

		return array_slice( $events, 0, max( 1, (int) $limit ) );
	}

	/* ---------------------------------------------------------------------
	 * Quick actions
	 * ------------------------------------------------------------------ */

	/**
	 * Return the actions a project screen should offer.
	 *
	 * §46 asks for context-aware actions and no irrelevant ones. The mapping is
	 * derived from the status and the record, so a project that has no draft never
	 * offers "Validate replica" and a project whose plan cannot monitor never
	 * offers "Enable monitoring".
	 *
	 * @param array<string, mixed> $project Project record.
	 * @param Feature_Gate|null    $gate    Optional feature gate.
	 * @param int                  $user_id User id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function actions( array $project, $gate = null, $user_id = 0 ) {
		$gate    = $gate instanceof Feature_Gate ? $gate : new Feature_Gate();
		$user_id = (int) $user_id;
		$status  = self::of( $project );
		$out     = array();

		$add = function ( $action, $label, $operation, $url = '' ) use ( &$out, $gate, $user_id ) {
			$state = $gate->state( $operation, $user_id );
			$out[] = array(
				'action'   => $action,
				'label'    => $label,
				'allowed'  => ! empty( $state['allowed'] ),
				'locked'   => ! empty( $state['locked'] ),
				'lock_code' => (string) $state['code'],
				'url'      => (string) $url,
			);
		};

		$has_draft    = ! empty( $project['drafts'] ) && is_array( $project['drafts'] );
		$has_analysis = ! empty( $project['analysis'] ) && is_array( $project['analysis'] );

		if ( ! $has_analysis && self::NEW !== $status ) {
			$add( 'analyze', __( 'Analyze', 'replicaforge' ), 'analysis' );
		}
		if ( $has_analysis && ! $has_draft ) {
			$add( 'generate', __( 'Generate replica', 'replicaforge' ), 'generation' );
		}
		if ( $has_draft && empty( $project['validation'] ) ) {
			$add( 'validate', __( 'Validate replica', 'replicaforge' ), 'validation' );
		}
		if ( ! empty( $project['validation'] ) ) {
			$add( 'review_differences', __( 'Review differences', 'replicaforge' ), 'validation' );
		}
		if ( ! empty( $project['corrections'] ) ) {
			$add( 'review_corrections', __( 'Review corrections', 'replicaforge' ), 'correction' );
		}
		if ( empty( $project['settings']['monitoring']['enabled'] ) ) {
			$add( 'enable_monitoring', __( 'Enable monitoring', 'replicaforge' ), 'sync_operation' );
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Build a health result.
	 *
	 * @param string               $state   Health state.
	 * @param array<int, string>   $reasons Machine reasons.
	 * @param string               $status  Project status.
	 * @return array<string, mixed>
	 */
	private static function health_result( $state, array $reasons, $status ) {
		$out = array();
		foreach ( $reasons as $reason ) {
			$out[] = array(
				'code'    => (string) $reason,
				'message' => self::health_reason( $reason ),
			);
		}

		return array(
			'state'   => (string) $state,
			'label'   => self::health_label( $state ),
			'status'  => (string) $status,
			'reasons' => $out,
		);
	}

	/**
	 * Return the sync-pending reason, if a project has one.
	 *
	 * Phase 9 built the detection and classification but no monitor, so nothing
	 * writes this signal yet. The reader exists so that when a monitor does write
	 * it, the health rule is already correct — and so that the absence is a stated
	 * limitation rather than a silently missing rule.
	 *
	 * @param array<string, mixed> $project Project record.
	 * @return string Empty string when there is no such signal.
	 */
	private static function sync_signal( array $project ) {
		$monitoring = isset( $project['settings']['monitoring'] ) && is_array( $project['settings']['monitoring'] )
			? $project['settings']['monitoring']
			: array();

		if ( ! empty( $monitoring['changes_pending'] ) ) {
			return 'source_change_pending';
		}

		return '';
	}

	/**
	 * Return the source-unreachable reason, if a project has one.
	 *
	 * @param array<string, mixed> $project Project record.
	 * @return string
	 */
	private static function unreachable_signal( array $project ) {
		$monitoring = isset( $project['settings']['monitoring'] ) && is_array( $project['settings']['monitoring'] )
			? $project['settings']['monitoring']
			: array();

		if ( ! empty( $monitoring['unreachable'] ) ) {
			return 'source_unreachable';
		}

		// The last analysis failing for a network reason is a real signal that the
		// source was not reachable. It is checked as a set of declared codes rather
		// than by substring, because "the word 'failed' appears somewhere in the
		// warnings" is not a statement about the network.
		$warnings = isset( $project['warnings'] ) && is_array( $project['warnings'] ) ? $project['warnings'] : array();
		$network  = array(
			'dns_resolution_failed',
			'request_timeout',
			'request_failed',
			'too_many_redirects',
			'redirect_loop',
		);

		foreach ( $warnings as $warning ) {
			$code = is_array( $warning ) ? (string) ( $warning['code'] ?? '' ) : (string) $warning;
			if ( in_array( $code, $network, true ) ) {
				return 'source_unreachable';
			}
		}

		return '';
	}

	/**
	 * Return the manual-change reason, if a project has one.
	 *
	 * Phase 6's {@see Correction_Snapshot} already knows which element properties a
	 * user edited, but it is keyed by draft and property rather than surfaced on the
	 * project. This reads a summary if one is present; it does not reach into the
	 * snapshot store, because doing so would mean a project-list query reading
	 * every snapshot of every draft.
	 *
	 * @param array<string, mixed> $project Project record.
	 * @return string
	 */
	private static function manual_change_signal( array $project ) {
		$corrections = isset( $project['corrections'] ) && is_array( $project['corrections'] ) ? $project['corrections'] : array();
		if ( ! empty( $corrections['manual_changes'] ) ) {
			return 'manual_element_edits';
		}
		return '';
	}

	/**
	 * Return the validation signal for a project.
	 *
	 * The only signal used is the presence of a `critical` or `major` difference.
	 * That is a deliberate restriction, and it is the second defect this file
	 * contained when it was first written.
	 *
	 * Phase 5 scores with **bands**, not with a 0-to-1 value: `Validation_Limits`
	 * declares a `small` and a `medium` band per check type, and a separate
	 * `IMAGE_BAND_SMALL` of `0.02` for rendered comparison. A group aggregate is
	 * therefore not a score on a known scale, and there is no declared floor to
	 * compare it against. Inventing one — "below 0.9 is a problem", which is what
	 * this method first did — would be exactly the fabricated threshold §17 rules
	 * out, and it would mark healthy replicas as unhealthy on a different
	 * installation's numbers.
	 *
	 * Difference *severities* are declared, and `critical` and `major` are the two
	 * Phase 5 already treats as worth correcting. So that is the whole rule. The
	 * per-group numbers are still shown by {@see self::quality()} — displaying a
	 * measured value is honest even with no threshold attached to it.
	 *
	 * @param array<string, mixed> $project Project record.
	 * @return string One of {@see self::VALIDATION_ISSUES}, `validation_unreadable`, or an empty string.
	 */
	private static function validation_signal( array $project ) {
		$validation = isset( $project['validation'] ) && is_array( $project['validation'] ) ? $project['validation'] : array();
		if ( array() === $validation ) {
			return '';
		}

		if ( isset( $validation['differences'] ) && is_array( $validation['differences'] ) ) {
			foreach ( $validation['differences'] as $difference ) {
				$severity = is_array( $difference ) ? (string) ( $difference['severity'] ?? '' ) : '';
				if ( in_array( $severity, array( 'critical', 'major' ), true ) ) {
					return self::VALIDATION_ISSUES;
				}
			}
			// A present, readable list with nothing blocking in it means the
			// validation ran and found nothing. That is a positive result, not a
			// missing one, and it must not be reported as unreadable.
			return '';
		}

		// A validation exists but carries no readable difference list, so there is
		// nothing to judge. Reporting "healthy" would be a claim we cannot make.
		return 'validation_unreadable';
	}

	/**
	 * Read a numeric score from a metric entry in whatever shape it arrives.
	 *
	 * Phase 5 writes `value` for a per-viewport score and a group entry may be a
	 * bare number or an array. Both are accepted; anything else reads as
	 * unmeasured, which is the safe direction.
	 *
	 * @param mixed $entry Metric entry.
	 * @return float|null
	 */
	private static function read_score( $entry ) {
		if ( is_numeric( $entry ) ) {
			return (float) $entry;
		}
		if ( is_array( $entry ) ) {
			foreach ( array( 'value', 'score' ) as $key ) {
				if ( isset( $entry[ $key ] ) && is_numeric( $entry[ $key ] ) ) {
					return (float) $entry[ $key ];
				}
			}
		}
		return null;
	}

	/**
	 * Read a combined score, if the stored result has one.
	 *
	 * @param array<string, mixed> $validation Stored validation.
	 * @return float|null
	 */
	private static function read_combined_score( array $validation ) {
		foreach ( array( 'score', 'overall', 'combined' ) as $key ) {
			if ( isset( $validation[ $key ] ) && is_numeric( $validation[ $key ] ) ) {
				return (float) $validation[ $key ];
			}
		}
		if ( isset( $validation['metrics']['overall'] ) && is_numeric( $validation['metrics']['overall'] ) ) {
			return (float) $validation['metrics']['overall'];
		}
		return null;
	}

	/**
	 * Read the overall verdict level, if the stored result has one.
	 *
	 * @param array<string, mixed> $validation Stored validation.
	 * @return string
	 */
	private static function read_level( array $validation ) {
		if ( isset( $validation['levels'] ) && is_scalar( $validation['levels'] ) ) {
			return (string) $validation['levels'];
		}
		return '';
	}

	/**
	 * Return the timestamp of a version entry.
	 *
	 * @param array<string, mixed> $version Version entry.
	 * @return string
	 */
	private static function version_time( array $version ) {
		foreach ( array( 'created_at', 'timestamp', 'at' ) as $key ) {
			if ( ! empty( $version[ $key ] ) && is_scalar( $version[ $key ] ) ) {
				return (string) $version[ $key ];
			}
		}
		return '';
	}

	/**
	 * Build a timeline event.
	 *
	 * @param string $at    Timestamp.
	 * @param string $kind  Event kind.
	 * @param string $label Event label.
	 * @return array<string, mixed>
	 */
	private static function event( $at, $kind, $label ) {
		$time = is_numeric( $at ) ? (int) $at : (int) strtotime( (string) $at );

		return array(
			'timestamp' => $time,
			'at'        => gmdate( 'c', max( 0, $time ) ),
			'kind'      => (string) $kind,
			'label'     => (string) $label,
		);
	}

	/**
	 * Return a translated label for a project field.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	private static function field_label( $field ) {
		$labels = array(
			'analysis'      => __( 'Website analysis completed', 'replicaforge' ),
			'design'        => __( 'Design understanding completed', 'replicaforge' ),
			'specification' => __( 'Reconstruction plan ready', 'replicaforge' ),
			'validation'    => __( 'Validation completed', 'replicaforge' ),
			'corrections'   => __( 'Corrections planned', 'replicaforge' ),
		);

		return isset( $labels[ $field ] ) ? $labels[ $field ] : (string) $field;
	}
}
