<?php
/**
 * Phase 9: central sync limits and vocabulary.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Every bound, status, category, and enum Phase 9 uses, in one place.
 *
 * Phase 7 established the rule that limits live together rather than being
 * scattered as literals. Phase 9 has more of them than any previous phase, because
 * monitoring is the first feature in the plugin that runs on a schedule without a
 * person sitting in front of the screen. A limit discovered by a user is a limit
 * that was not enforced.
 *
 * The enums are `const` arrays rather than free strings so that a typo in a
 * classification is a fatal at the call site rather than a value no reader
 * recognises.
 */
final class Sync_Limits {

	/**
	 * Phase identity.
	 */
	const PHASE = '9.0';

	/**
	 * Sync plan schema version.
	 *
	 * Separate from the plugin version, so a patch release does not invalidate a
	 * stored plan.
	 */
	const SCHEMA_VERSION = '9.0';

	/**
	 * Source version schema version.
	 */
	const SOURCE_SCHEMA_VERSION = '9.0';

	/**
	 * Engine version, for the comparison and classification rules themselves.
	 */
	const ENGINE_VERSION = '1.0';

	/**
	 * Post meta key holding the source-to-Elementor mapping.
	 */
	const MAP_META = 'replicaforge_sync_map';

	/**
	 * Post meta key holding the last applied sync result.
	 */
	const RESULT_META = 'replicaforge_sync_result';

	/**
	 * Option holding monitoring records.
	 */
	const MONITOR_OPTION = 'replicaforge_monitors';

	/**
	 * Cron hook that runs due monitors.
	 */
	const CRON_HOOK = 'replicaforge_run_monitors';

	/**
	 * Transient prefix for an in-progress monitor, which is the lock.
	 */
	const LOCK_PREFIX = 'replicaforge_sync_lock_';

	/**
	 * Cron interval name, registered so it can be scheduled and cleared by name.
	 */
	const CRON_INTERVAL = 'hourly';

	/**
	 * Monitoring frequencies, mapped to their interval in seconds.
	 *
	 * `manual` is a real entry rather than an absence: a project can be monitored
	 * for changes without ever being checked automatically, and representing that
	 * as "no frequency" would make it indistinguishable from a project that was
	 * never set up.
	 *
	 * @var array<string, int>
	 */
	const FREQUENCIES = array(
		'manual' => 0,
		'daily'  => 86400,
		'weekly' => 604800,
		'biweekly' => 1209600,
		'monthly' => 2592000,
	);

	/**
	 * Hard floor on how often any project may be checked, whatever its frequency.
	 *
	 * A frequency is a ceiling on how often the user wants checks; this is a floor
	 * on how often ReplicaForge permits them. Without it, a project left on `daily`
	 * plus a site with several projects means a burst of requests to the same host,
	 * which is a way to be a bad citizen of someone else's server.
	 */
	const MIN_INTERVAL_SECONDS = 21600;

	/**
	 * Maximum monitors retained across all projects.
	 */
	const MAX_MONITORS = 40;

	/**
	 * Maximum monitors processed in one cron pass.
	 *
	 * A pass that tries to do everything is a pass that times out, and a timed-out
	 * pass holds locks that stop the next one from running.
	 */
	const MONITORS_PER_PASS = 5;

	/**
	 * Lock lifetime for one monitor, in seconds.
	 *
	 * Longer than the fetch timeout so a slow check is not declared dead while it
	 * is still running, and short enough that a crashed worker's lock expires
	 * without an operator.
	 */
	const LOCK_TTL = 300;

	/**
	 * Consecutive failures before a monitor is marked failed rather than active.
	 */
	const MAX_CONSECUTIVE_FAILURES = 3;

	/**
	 * Consecutive identical reachability failures before a source may be called
	 * removed.
	 *
	 * This is the §41 requirement stated as a number. A source is only called
	 * removed after several separate checks agree, because a single 404 is a
	 * mistyped URL, a maintenance page, or a temporary routing fault far more often
	 * than it is a deletion.
	 */
	const REMOVAL_CONFIRMATIONS = 3;

	/**
	 * Source versions retained per project.
	 */
	const MAX_SOURCE_VERSIONS = 10;

	/**
	 * Source versions per project at which a version body is pruned.
	 */
	const SOURCE_PRUNE_THRESHOLD = 12;

	/**
	 * Sync reports retained per project.
	 */
	const MAX_SYNC_REPORTS = 50;

	/**
	 * Sync plans retained before the oldest is pruned.
	 */
	const MAX_SYNC_PLANS = 20;

	/**
	 * Transient lifetime for a stored sync plan.
	 */
	const PLAN_TTL = 86400;

	/**
	 * Maximum components compared in one change detection run.
	 */
	const MAX_COMPONENTS = 1200;

	/**
	 * Maximum changes retained in one sync report.
	 */
	const MAX_REPORT_CHANGES = 400;

	/**
	 * Maximum changes a single apply may carry.
	 */
	const MAX_APPLY_CHANGES = 200;

	/**
	 * Maximum candidate matches evaluated per component.
	 *
	 * Bounded so a page where every component looks alike does not produce a
	 * quadratic comparison.
	 */
	const MAX_MATCH_CANDIDATES = 12;

	/**
	 * Maximum automatic sync iterations.
	 *
	 * §77. A sync that is followed by a validation that suggests another sync is a
	 * loop, and a loop has to have a bound that is enforced rather than intended.
	 */
	const MAX_ITERATIONS = 3;

	/**
	 * Maximum generations one project may hold.
	 */
	const MAX_GENERATIONS = 10;

	/**
	 * Maximum snapshots retained per draft.
	 */
	const MAX_SNAPSHOTS = 5;

	/**
	 * Monitor statuses.
	 *
	 * @var array<int, string>
	 */
	const STATUSES = array( 'active', 'paused', 'failed', 'disabled' );

	/**
	 * Project source states, per §82.
	 *
	 * @var array<int, string>
	 */
	const SOURCE_STATES = array(
		'source_active',
		'source_unavailable',
		'source_archived',
		'monitoring_paused',
	);

	/**
	 * Reachability outcomes, per §41.
	 *
	 * These are kept distinct on purpose. Conflating `source_timeout` with
	 * `source_removed` is how a monitoring tool deletes a replica because a source
	 * server was briefly down, which is the single worst failure this feature has.
	 *
	 * @var array<int, string>
	 */
	const REACHABILITY = array(
		'reachable',
		'source_unreachable',
		'source_timeout',
		'source_blocked',
		'source_http_error',
		'source_rate_limited',
		'source_removed',
		'source_moved',
		'invalid_url',
	);

	/**
	 * Change categories, per §10.
	 *
	 * @var array<int, string>
	 */
	const CATEGORIES = array(
		'content',
		'image',
		'asset',
		'typography',
		'color',
		'spacing',
		'layout',
		'component',
		'section',
		'navigation',
		'footer',
		'responsive',
		'interaction',
		'link',
		'product',
		'blog',
		'theme',
	);

	/**
	 * Change severities, per §11.
	 *
	 * @var array<int, string>
	 */
	const SEVERITIES = array( 'none', 'minor', 'moderate', 'major', 'critical' );

	/**
	 * Change types.
	 *
	 * @var array<int, string>
	 */
	const CHANGE_TYPES = array( 'added', 'removed', 'modified', 'moved', 'unchanged' );

	/**
	 * Sync risk levels, per §28.
	 *
	 * @var array<int, string>
	 */
	const RISKS = array( 'low', 'medium', 'high', 'blocked' );

	/**
	 * Conflict states, per §22.
	 *
	 * @var array<int, string>
	 */
	const CONFLICT_STATES = array(
		'no_conflict',
		'source_only',
		'user_only',
		'both_changed',
		'unknown',
	);

	/**
	 * Sync modes, per §24.
	 *
	 * `review_only` is the default and is listed first for that reason.
	 *
	 * @var array<int, string>
	 */
	const SYNC_MODES = array( 'review_only', 'manual', 'monitored', 'safe_auto' );

	/**
	 * Sync outcomes, per §39 and §40.
	 *
	 * @var array<int, string>
	 */
	const OUTCOMES = array(
		'no_changes',
		'proposed',
		'completed',
		'partial',
		'failed',
		'rolled_back',
		'blocked',
	);

	/**
	 * Change types that are never applied without an explicit human decision.
	 *
	 * §13 and §25. A removal destroys work; a structural change moves everything
	 * around it. Neither is ever automatic, whatever the risk score says.
	 *
	 * @var array<int, string>
	 */
	const DESTRUCTIVE_TYPES = array( 'removed' );

	/**
	 * Categories that always require review, whatever the measured severity.
	 *
	 * These are the ones where being wrong is expensive in a way a number cannot
	 * express: a deleted section is gone, a restructured navigation is unusable, and
	 * an asset replaced with uncertain provenance is a rights problem.
	 *
	 * @var array<int, string>
	 */
	const ALWAYS_REVIEW_CATEGORIES = array( 'section', 'navigation', 'interaction', 'theme' );

	/**
	 * Change categories eligible for `safe_auto`, per §25.
	 *
	 * The list is deliberately short. It covers a label changing and a metadata
	 * value changing, and nothing that alters appearance in a way a person would
	 * want to look at before it happens.
	 *
	 * @var array<int, string>
	 */
	const AUTO_SAFE_CATEGORIES = array( 'content' );

	/**
	 * Change categories eligible for `safe_auto`, per §25.
	 *
	 * A colour change is a real difference a person can see, so it is reviewable
	 * but not automatic. Only content is automatic, and only when the change is not
	 * a removal.
	 *
	 * @var array<int, string>
	 */
	const AUTO_SAFE_RISK_CEILING = 'low';

	/**
	 * Minimum confidence for a change to be applied without review.
	 *
	 * Below this the system does not know what it is looking at, and not knowing is
	 * not a reason to act.
	 */
	const MIN_AUTO_CONFIDENCE = 0.9;

	/**
	 * Notification channels.
	 *
	 * @var array<int, string>
	 */
	const NOTIFICATION_CHANNELS = array( 'dashboard', 'email' );

	/**
	 * Notification events, per §45.
	 *
	 * @var array<int, string>
	 */
	const NOTIFICATION_EVENTS = array(
		'source_changed',
		'sync_failed',
		'sync_completed',
		'conflict_detected',
	);

	/**
	 * Notification events enabled by default.
	 *
	 * A change and a failure are worth telling someone about. A successful sync is
	 * not, because the alternative is an email every week saying nothing happened,
	 * which trains a user to ignore the ones that matter.
	 *
	 * @var array<int, string>
	 */
	const DEFAULT_NOTIFICATIONS = array( 'source_changed', 'sync_failed' );

	/**
	 * Maximum notification records retained.
	 */
	const MAX_NOTIFICATIONS = 50;

	/**
	 * Option holding notification records.
	 */
	const NOTIFICATION_OPTION = 'replicaforge_notifications';

	/**
	 * Maximum bytes retained for one source version body.
	 *
	 * A source version stores the normalized representation, never the raw HTML.
	 * This bounds what "normalized" is allowed to mean.
	 */
	const MAX_VERSION_BYTES = 4194304;

	/**
	 * Maximum components stored in one source version.
	 */
	const MAX_VERSION_COMPONENTS = 1500;

	/**
	 * Return whether a value is a valid frequency.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_frequency( $value ) {
		return is_string( $value ) && isset( self::FREQUENCIES[ $value ] );
	}

	/**
	 * Return whether a value is a valid status.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_status( $value ) {
		return is_string( $value ) && in_array( $value, self::STATUSES, true );
	}

	/**
	 * Return whether a value is a valid severity.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_severity( $value ) {
		return is_string( $value ) && in_array( $value, self::SEVERITIES, true );
	}

	/**
	 * Return whether a value is a valid category.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_category( $value ) {
		return is_string( $value ) && in_array( $value, self::CATEGORIES, true );
	}

	/**
	 * Return whether a value is a valid change type.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_change_type( $value ) {
		return is_string( $value ) && in_array( $value, self::CHANGE_TYPES, true );
	}

	/**
	 * Return whether a value is a valid risk level.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_risk( $value ) {
		return is_string( $value ) && in_array( $value, self::RISKS, true );
	}

	/**
	 * Return whether a value is a valid conflict state.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_conflict_state( $value ) {
		return is_string( $value ) && in_array( $value, self::CONFLICT_STATES, true );
	}

	/**
	 * Return whether a value is a valid sync mode.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_sync_mode( $value ) {
		return is_string( $value ) && in_array( $value, self::SYNC_MODES, true );
	}

	/**
	 * Return the interval in seconds for a frequency.
	 *
	 * The minimum interval floor is applied here rather than at the call sites, so
	 * there is no way to schedule a check that bypasses it.
	 *
	 * @param string $frequency Frequency name.
	 * @return int Zero for a manual-only monitor.
	 */
	public static function interval_for( $frequency ) {
		if ( ! self::is_frequency( $frequency ) ) {
			return 0;
		}
		$interval = (int) self::FREQUENCIES[ $frequency ];
		if ( $interval <= 0 ) {
			return 0;
		}
		return max( self::MIN_INTERVAL_SECONDS, $interval );
	}

	/**
	 * Return the frequency that names an interval.
	 *
	 * @param int $seconds Interval.
	 * @return string Empty string when no frequency matches.
	 */
	public static function frequency_for_interval( $seconds ) {
		$seconds = (int) $seconds;
		foreach ( self::FREQUENCIES as $frequency => $interval ) {
			if ( $interval > 0 && $interval === $seconds ) {
				return (string) $frequency;
			}
		}
		return '';
	}

	/**
	 * Return a severity rank for comparison and ordering.
	 *
	 * @param string $severity Severity name.
	 * @return int
	 */
	public static function severity_rank( $severity ) {
		$order = array_flip( self::SEVERITIES );
		return isset( $order[ $severity ] ) ? (int) $order[ $severity ] : 0;
	}

	/**
	 * Return a risk rank for comparison and ordering.
	 *
	 * @param string $risk Risk name.
	 * @return int
	 */
	public static function risk_rank( $risk ) {
		$order = array_flip( self::RISKS );
		return isset( $order[ $risk ] ) ? (int) $order[ $risk ] : 0;
	}

	/**
	 * Return whether a reachability outcome means the source is gone.
	 *
	 * Only `source_removed` does. Everything else is a temporary or
	 * configuration problem, and treating any of them as a deletion is the failure
	 * this constant exists to prevent.
	 *
	 * @param string $reachability Outcome.
	 * @return bool
	 */
	public static function is_removal( $reachability ) {
		return 'source_removed' === $reachability;
	}

	/**
	 * Return whether a reachability outcome is a temporary failure.
	 *
	 * @param string $reachability Outcome.
	 * @return bool
	 */
	public static function is_transient( $reachability ) {
		return in_array(
			$reachability,
			array( 'source_unreachable', 'source_timeout', 'source_blocked', 'source_http_error', 'source_rate_limited' ),
			true
		);
	}
}
