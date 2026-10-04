<?php
/**
 * Phase 10: plan, entitlement, and usage vocabulary.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Every operation, feature, limit name, and plan id Phase 10 uses, in one place.
 *
 * The brief's rule that plan checks must not be scattered through random files is
 * really a statement about where the *vocabulary* lives. A limit of `'analyses'` is
 * written in the plan definition, in the usage manager, in the REST argument
 * validator, and in the admin screen. If those four places each spell it themselves,
 * a rename becomes a silent behaviour change: the definition would read `analyses`
 * while the counter recorded `analysis`, and the limit would never trip.
 *
 * So the names are declared once here and nothing else invents one. An operation that
 * is not in this list cannot be metered, which is the correct outcome — an unlisted
 * operation is a gap, not a free pass.
 */
final class Plan_Limits {

	/**
	 * The bounded meterable operations.
	 *
	 * `monitored_projects` and `sync_frequency` are deliberately absent. The first is
	 * a *count of entities*, not a tally of events, so metering it by incrementing a
	 * counter would be wrong — a project that is deleted must decrement it. It is
	 * counted directly instead. The second is a configuration value on a project, not
	 * an event, and a frequency is not something you do more of; it is something you
	 * are allowed to choose.
	 *
	 * @var array<int, string>
	 */
	const OPERATIONS = array(
		'analysis',
		'ai_analysis',
		'generation',
		'validation',
		'correction',
		'sync_operation',
		'export',
		'import',
		// Phase 14. Three operations, because content mapping has three genuinely
		// different costs and one combined counter would price them wrongly. Reading a
		// page's content is cheap, building a mapping plan is moderate, and *writing*
		// into the user's own store is the one that must be metered most tightly — it
		// is the only operation in the plugin that changes a record the user cares
		// about, as opposed to a draft ReplicaForge owns.
		'content_analysis',
		'content_mapping',
		'content_apply',
	);

	/**
	 * Features that can be granted or withheld by a plan.
	 *
	 * @var array<int, string>
	 */
	const FEATURES = array(
		'basic_analysis',
		'ai_understanding',
		'elementor_generation',
		'visual_validation',
		'automatic_correction',
		'monitoring',
		'source_sync',
		'advanced_reconstruction',
		'project_export',
		'project_import',
		// Phase 14. `content_mapping` is the feature a plan grants or withholds; the
		// three operations above all gate on it, because there is no coherent product
		// in which a user may map content but not see what the mapping says.
		'content_mapping',
	);

	/**
	 * The mapping from an operation to the feature that gates it.
	 *
	 * This is the one place the two vocabularies meet. Every operation must name the
	 * feature that permits it, because "may the user analyze" is a feature question
	 * and "how many analyses are left" is a limit question, and answering the wrong
	 * one produces either an unusable feature or an unenforced limit.
	 *
	 * @var array<string, string>
	 */
	const OPERATION_FEATURES = array(
		'analysis'           => 'basic_analysis',
		'ai_analysis'        => 'ai_understanding',
		'generation'         => 'elementor_generation',
		'validation'         => 'visual_validation',
		'correction'         => 'automatic_correction',
		'sync_operation'     => 'source_sync',
		'export'             => 'project_export',
		'import'             => 'project_import',
		'content_analysis'   => 'content_mapping',
		'content_mapping'    => 'content_mapping',
		'content_apply'      => 'content_mapping',
	);

	/**
	 * Limit names.
	 *
	 * A limit is the count of an operation within a period. The names are
	 * `<operation>_per_period` so a plan definition cannot accidentally set a limit
	 * for an operation that does not exist, and so the meter and the limit can never
	 * be about different things.
	 *
	 * @var array<int, string>
	 */
	const LIMIT_NAMES = array(
		'analysis_per_period',
		'ai_analysis_per_period',
		'generation_per_period',
		'validation_per_period',
		'correction_per_period',
		'sync_operation_per_period',
		'export_per_period',
		'import_per_period',
		'monitored_projects',
		'history_projects',
	);

	/**
	 * Limits that count retained entities rather than metered events.
	 *
	 * @var array<int, string>
	 */
	const ENTITY_LIMITS = array( 'monitored_projects', 'history_projects' );

	/**
	 * The value meaning "no limit".
	 *
	 * Not zero. A limit of zero would mean "this is forbidden", which is a different
	 * statement and is expressed by withholding the feature instead. An unlimited
	 * plan says `-1` here and gets an unlimited meter, which is not the same as a
	 * plan that happens to have a large number.
	 */
	const UNLIMITED = -1;

	/**
	 * Plan ids, in ascending order of capability.
	 *
	 * The order is what the plan matrix screen orders by, and it is declared once so
	 * that adding a plan in the middle does not mean re-sorting a UI array.
	 *
	 * @var array<int, string>
	 */
	const PLAN_ORDER = array( 'free', 'starter', 'pro', 'agency' );

	/**
	 * Operation outcome codes.
	 *
	 * Stable strings, because they are stored in usage records and read back by
	 * later phases of a plan's life.
	 *
	 * @var array<int, string>
	 */
	const OUTCOMES = array( 'committed', 'released', 'expired' );

	/**
	 * Reservation lifetime, in seconds.
	 *
	 * A long-running job reserves usage before it starts and commits it on success.
	 * A reservation that is never committed — because the worker died, or the job was
	 * cancelled — has to expire on its own, or a crashed job permanently consumes the
	 * user's quota.
	 *
	 * Two hours is longer than any generation in `Elementor_Limits`
	 * (`GENERATION_TIME_BUDGET` 25s) or `Correction_Limits` (`APPLY_TIME_BUDGET` 45s)
	 * with a great deal of headroom, and short enough that a user who loses a job does
	 * not wait a day to try again.
	 */
	const RESERVATION_TTL = 7200;

	/**
	 * Maximum reservations held at once, per user.
	 *
	 * A bound rather than a rate: a client that opens thousands of reservations
	 * without executing anything must not be able to lock a user out of their own
	 * quota.
	 */
	const MAX_OPEN_RESERVATIONS = 20;

	/**
	 * Maximum usage records retained per user per period.
	 *
	 * The meter only needs totals, and the recent list only needs a tail. Anything
	 * beyond this is history nobody reads, and usage records are the largest thing
	 * this phase stores.
	 */
	const MAX_RECORDS_PER_PERIOD = 500;

	/**
	 * The custom capabilities Phase 10 introduces.
	 *
	 * `edit_pages` and `manage_options` are the only capabilities the plugin used
	 * before this phase, which is why §26's multi-user requirement could not be
	 * satisfied: there was no way to say "may use ReplicaForge" as distinct from
	 * "may edit pages" or "may administer WordPress".
	 *
	 * @var array<string, array{label: string, description: string}>
	 */
	const CAPABILITIES = array(
		'replicaforge_use'         => array(
			'label'       => 'Use ReplicaForge',
			'description' => 'Run analyses, generate replicas, and validate results. Required for any ReplicaForge operation.',
		),
		'replicaforge_generate'    => array(
			'label'       => 'Generate ReplicaForge drafts',
			'description' => 'Create and update editable Elementor drafts. Separate from using ReplicaForge so generation can be restricted.',
		),
		'replicaforge_manage_projects' => array(
			'label'       => 'Manage all ReplicaForge projects',
			'description' => 'View, change, and delete any project, not only the ones the user owns.',
		),
		'replicaforge_manage_settings' => array(
			'label'       => 'Manage ReplicaForge settings',
			'description' => 'Change analysis, AI, Elementor, and retention settings.',
		),
		'replicaforge_manage_plans' => array(
			'label'       => 'Manage ReplicaForge plans and licenses',
			'description' => 'Change plan definitions, licensing, and trial configuration. The most sensitive capability in the plugin.',
		),
	);

	/**
	 * Return the limit name for an operation.
	 *
	 * @param string $operation Operation name.
	 * @return string Empty string when the operation has no per-period limit.
	 */
	public static function limit_for_operation( $operation ) {
		$operation = is_string( $operation ) ? strtolower( trim( $operation ) ) : '';
		if ( ! self::is_operation( $operation ) ) {
			return '';
		}
		return $operation . '_per_period';
	}

	/**
	 * Return the feature that gates an operation.
	 *
	 * @param string $operation Operation name.
	 * @return string Empty string when the operation is not gated by a feature.
	 */
	public static function feature_for_operation( $operation ) {
		$operation = is_string( $operation ) ? strtolower( trim( $operation ) ) : '';
		return self::OPERATION_FEATURES[ $operation ] ?? '';
	}

	/**
	 * Return whether a value is a declared operation.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_operation( $value ) {
		return is_string( $value ) && in_array( $value, self::OPERATIONS, true );
	}

	/**
	 * Return whether a value is a declared feature.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_feature( $value ) {
		return is_string( $value ) && in_array( $value, self::FEATURES, true );
	}

	/**
	 * Return whether a value is a declared limit name.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_limit( $value ) {
		return is_string( $value ) && in_array( $value, self::LIMIT_NAMES, true );
	}

	/**
	 * Return whether a limit name counts retained entities rather than events.
	 *
	 * @param string $limit Limit name.
	 * @return bool
	 */
	public static function is_entity_limit( $limit ) {
		return is_string( $limit ) && in_array( $limit, self::ENTITY_LIMITS, true );
	}

	/**
	 * Return whether a limit value means unlimited.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_unlimited( $value ) {
		return is_numeric( $value ) && (int) $value === self::UNLIMITED;
	}

	/**
	 * Return the current metering period key.
	 *
	 * A period is a calendar month in UTC, so a user's quota resets at a moment they
	 * can predict and two sites in different timezones reset at the same instant. A
	 * rolling thirty-day window would be easier to implement and impossible to
	 * explain on a screen.
	 *
	 * @param int|null $timestamp Optional timestamp.
	 * @return string
	 */
	public static function period_key( $timestamp = null ) {
		$timestamp = ( null === $timestamp ) ? time() : (int) $timestamp;
		return gmdate( 'Y-m', $timestamp );
	}

	/**
	 * Return when the current period ends.
	 *
	 * @param int|null $timestamp Optional timestamp.
	 * @return int
	 */
	public static function period_ends( $timestamp = null ) {
		$timestamp = ( null === $timestamp ) ? time() : (int) $timestamp;
		return (int) strtotime( gmdate( 'Y-m-01', $timestamp ) . ' +1 month' );
	}

	/**
	 * Return the plan rank, for ordering.
	 *
	 * An unknown plan ranks below every known one, so a misconfigured plan id cannot
	 * sort to the top of a matrix.
	 *
	 * @param string $plan_id Plan identifier.
	 * @return int
	 */
	public static function plan_rank( $plan_id ) {
		$index = array_search( (string) $plan_id, self::PLAN_ORDER, true );
		return false === $index ? -1 : (int) $index;
	}
}
