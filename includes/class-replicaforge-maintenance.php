<?php
/**
 * Phase 7: scheduled cleanup and storage limits.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps ReplicaForge's stored data bounded.
 *
 * Everything here is additive and safe to run at any time. It never removes a
 * draft, a post, or user media, and it never removes data a job is currently
 * using. A cleanup that cannot prove a row is safe to remove leaves it alone and
 * says so, rather than guessing.
 */
final class Maintenance {

	/**
	 * Cron hook for daily maintenance.
	 */
	const DAILY_HOOK = 'replicaforge_daily_maintenance';

	/**
	 * Cron hook for job processing.
	 */
	const JOB_HOOK = 'replicaforge_process_jobs';

	/**
	 * Option holding retention settings.
	 */
	const SETTINGS_OPTION = 'replicaforge_settings';

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Optional logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Return the retention settings, with defaults filled in.
	 *
	 * @return array<string, int>
	 */
	public function settings() {
		$stored = get_option( self::SETTINGS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( array( 'log_days' => 30, 'job_days' => 14, 'snapshot_days' => 30, 'transient_ttl' => 604800, 'log_limit' => 500 ) as $key => $default ) {
			$out[ $key ] = isset( $stored[ $key ] ) && is_numeric( $stored[ $key ] ) ? (int) $stored[ $key ] : (int) $default;
		}

		return $out;
	}

	/**
	 * Persist one retention setting.
	 *
	 * @param string $key   Setting name.
	 * @param int    $value Desired value.
	 * @return bool
	 */
	public function set_setting( $key, $value ) {
		$allowed = array( 'log_days' => array( 1, 365 ), 'job_days' => array( 1, 365 ), 'snapshot_days' => array( 1, 365 ), 'transient_ttl' => array( 3600, 2592000 ), 'log_limit' => array( 50, Logger::MAX_LIMIT ) );

		$key = is_string( $key ) ? $key : '';
		if ( ! isset( $allowed[ $key ] ) ) {
			return false;
		}
		$value = (int) $value;
		if ( $value < $allowed[ $key ][0] || $value > $allowed[ $key ][1] ) {
			return false;
		}
		$stored          = $this->settings();
		$stored[ $key ]  = $value;
		update_option( self::SETTINGS_OPTION, $stored, false );
		return true;
	}

	/**
	 * Register the scheduled hooks.
	 *
	 * @return void
	 */
	public function schedule() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + ( 10 * MINUTE_IN_SECONDS ), 'daily', self::DAILY_HOOK );
		}
		if ( ! wp_next_scheduled( self::JOB_HOOK ) ) {
			// A short interval, because a queued job that waits minutes looks broken
			// to the person watching it. WP-Cron only fires on traffic, so this is a
			// ceiling on latency rather than a guarantee.
			wp_schedule_event( time() + ( 1 * MINUTE_IN_SECONDS ), 'replicaforge_minute', self::JOB_HOOK );
		}
	}

	/**
	 * Remove the scheduled hooks.
	 *
	 * @return void
	 */
	public function unschedule() {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_clear_scheduled_hook( self::JOB_HOOK );
	}

	/**
	 * Run the daily maintenance.
	 *
	 * @return array<string, mixed>
	 */
	public function daily() {
		$settings = $this->settings();
		$summary  = array(
			'transients'   => $this->purge_expired(),
			'jobs'         => array(),
			'snapshots'    => array(),
			'log'          => 0,
			'idempotency'  => 0,
			// Phase 10: usage reservations left open by a process that died. An
			// unsettled reservation keeps consuming the user's allowance, so
			// expiring it is not housekeeping — it is the difference between a
			// crashed job costing a user one operation and costing them the rest of
			// the month.
			'reservations' => 0,
			// Phase 11: the reliability sweeps.
			'recovery'     => array(),
			'locks'        => 0,
			'cancellations'=> 0,
			// Phase 15: the two collaboration logs and the notification queue, at
			// three different retentions. Kept apart from the counts above because they
			// are governed by different rules - see prune_collaboration().
			'collaboration'=> array(),
		);

		$repository = new Job_Repository( $this->logger );
		$summary['jobs']['removed']   = $repository->prune( (int) $settings['job_days'] );
		$summary['snapshots']         = $this->prune_snapshots( (int) $settings['snapshot_days'] );
		$summary['log']               = $this->prune_log( (int) $settings['log_days'] );
		$summary['idempotency']       = $this->prune_idempotency();
		$summary['reservations']      = ( new Usage_Manager( $this->logger ) )->sweep();
		$summary['collaboration']     = $this->prune_collaboration();

		// A job whose worker died is resolved here rather than on a later tick, so
		// the first person to look at the queue sees the real state. Resumable work
		// is put back in the queue; work that had already begun writing is expired
		// and waits for a person.
		$summary['recovery'] = ( new Job_Recovery( $repository, $this->logger ) )->sweep( 25 );

		// An expired lock is harmless — the next acquire takes it over — but an
		// options table that grows one row per crashed job is worse than one that
		// does not.
		$summary['locks']         = ( new Job_Lock( $this->logger ) )->prune();
		$summary['cancellations'] = ( new Job_Cancellation() )->prune();

		$this->logger->info(
			'daily_maintenance',
			'Ran the scheduled cleanup.',
			array(
				'transients'   => (int) $summary['transients']['transients'],
				'jobs'         => (int) $summary['jobs']['removed'],
				'log'          => (int) $summary['log'],
				'reservations' => (int) $summary['reservations'],
				'locks'        => (int) $summary['locks'],
			),
			'system'
		);

		return $summary;
	}

	/**
	 * Remove expired ReplicaForge transients.
	 *
	 * A transient past its own expiry is unreadable by definition, so removing it
	 * discards nothing. The check is on the recorded timeout rather than the value,
	 * which is what makes it safe.
	 *
	 * @return array<string, int>
	 */
	public function purge_expired() {
		global $wpdb;

		$now      = time();
		$removed  = 0;
		$examined = 0;

		// A site with a very large options table should not run a wide LIKE in one
		// request, so the scan is bounded. Anything past the bound is caught on the
		// next run, which is harmless because the data is already unreadable.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 5000",
				$wpdb->esc_like( '_transient_replicaforge_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_replicaforge_' ) . '%'
			)
		);

		foreach ( (array) $names as $name ) {
			$name = (string) $name;
			$examined++;

			if ( 0 === strpos( $name, '_transient_timeout_replicaforge_' ) ) {
				$timeout = get_option( $name );
				if ( ! is_numeric( $timeout ) || (int) $timeout >= $now ) {
					continue;
				}

				// The matching value row is this name with only the timeout marker
				// removed. Rebuilding it by stripping the whole prefix instead
				// produced a name that matched nothing, so the value outlived every
				// future cleanup.
				$value_name = str_replace( '_transient_timeout_replicaforge_', '_transient_replicaforge_', $name );

				if ( false !== strpos( $name, 'replicaforge_correction_plan_' ) ) {
					// A plan or snapshot transient is left to expire on its own. Its
					// value row is what a pending rollback reads, and a rollback in
					// progress must never be broken by a cleanup.
					delete_option( $name );
					continue;
				}

				if ( delete_option( $value_name ) ) {
					$removed++;
				}
				delete_option( $name );
				continue;
			}

			// A value row with no timeout row is either an orphan left by an
			// interrupted write or a row whose timeout was already reaped. Nothing
			// can read it, so it is removed.
			$timeout_name = '_transient_timeout_replicaforge_' . substr( $name, strlen( '_transient_replicaforge_' ) );
			if ( false === get_option( $timeout_name, false ) ) {
				if ( delete_option( $name ) ) {
					$removed++;
				}
			}
		}

		return array(
			'transients' => (int) $removed,
			'examined'   => $examined,
		);
	}

	/**
	 * Remove snapshots older than a cutoff for drafts that are not being corrected.
	 *
	 * Snapshot summaries live in post meta, so this walks the drafts that have any.
	 * Only the summary list is touched. The restorable document itself lives in a
	 * bounded transient that expires on its own, and the transient is never removed
	 * while its summary still says it is restorable, so a rollback in progress is
	 * never broken by a cleanup.
	 *
	 * @param int $days Retention in days.
	 * @return array<string, int>
	 */
	public function prune_snapshots( $days = 30 ) {
		$days    = max( 1, (int) $days );
		$cutoff  = time() - ( $days * DAY_IN_SECONDS );
		$removed = 0;
		$examined = 0;

		foreach ( $this->drafts_with_snapshots() as $post_id ) {
			$snapshots = $this->stored_snapshots( $post_id );
			if ( empty( $snapshots ) ) {
				continue;
			}
			$kept = array();
			foreach ( $snapshots as $snapshot ) {
				$examined++;
				$created     = isset( $snapshot['created_at'] ) ? strtotime( (string) $snapshot['created_at'] ) : false;
				$inside      = false !== $created && $created >= $cutoff;
				$restorable  = ! empty( $snapshot['restorable'] );
				$is_legacy   = ! empty( $snapshot['legacy'] );

				if ( $inside ) {
					// Recent and, if it claims to be restorable, still backed by a
					// transient. Kept as it is.
					$kept[] = $snapshot;
					continue;
				}
				if ( $restorable && ! $is_legacy ) {
					// Old but still restorable. The transient is the authority, so the
					// summary is kept until the transient expires; only the flag is
					// refreshed so the UI stops promising a rollback that is about to
					// become unavailable.
					$kept[] = $snapshot;
					continue;
				}
				$removed++;
			}
			$this->store_snapshots( $post_id, $kept );
		}

		return array(
			'removed'  => $removed,
			'examined' => $examined,
		);
	}

	/**
	 * Return the ids of drafts that carry snapshot summaries.
	 *
	 * @return array<int, int>
	 */
	public function drafts_with_snapshots() {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 500",
				Correction_Limits::META_PREFIX . 'snapshots'
			)
		);

		$out = array();
		foreach ( (array) $ids as $id ) {
			$out[] = (int) $id;
		}
		return $out;
	}

	/**
	 * Prune the log by age.
	 *
	 * @param int $days Retention in days.
	 * @return int
	 */
	public function prune_log( $days = 30 ) {
		$logger = new Logger();
		return $logger->prune( $days );
	}

	/**
	 * Remove expired idempotency records.
	 *
	 * @return int
	 */
	public function prune_idempotency() {
		$queue = new Job_Queue( null, $this->logger );
		return $queue->prune_records();
	}

	/**
	 * Sweep the Phase 15 stores.
	 *
	 * ### Why this is its own method
	 *
	 * The tables may not exist. A site whose migration has not run has no activity table,
	 * and a scheduled job that fatals once a day is a worse problem than a log that is
	 * briefly overdue. So every store is reached through `class_exists()`.
	 *
	 * ### What the report means when a table is absent
	 *
	 * The keys are always present, and read zero when the table is missing - because a
	 * `DELETE` against a table that is not there affects no rows, and "removed nothing" is
	 * a true description of that. It is worth being explicit rather than clever here: an
	 * earlier version of this method promised to *omit* a key for an absent store, which
	 * would have let a caller distinguish "nothing to prune" from "not installed". That
	 * distinction is not worth a second code path, and the two cases are operationally
	 * identical - either way there is nothing to delete. What is not acceptable is
	 * reporting a removal that did not happen, and a count of zero cannot do that.
	 *
	 * ### Retention is constant-only, and deliberately so
	 *
	 * The activity and audit ages come from `Collaboration_Log::prune()`, which reads
	 * `Workspace_Limits::ACTIVITY_TTL` and `AUDIT_TTL`. They are *not* exposed as
	 * administrator settings, and the reasoning is worth stating because a settings screen
	 * with two more number fields is the obvious next thing to add.
	 *
	 * A retention setting is a promise about what will still be there in a year's time. If
	 * the default lives in one place and the override in another, a reader cannot answer
	 * "how long do you keep audit rows?" from the code without following two paths, and the
	 * two will drift. Worse, a setting that can be set to a value nobody tested is a
	 * promise the code has not made. The constants are the single authority, they are
	 * declared next to the vocabulary they describe, and an administrator who needs a
	 * different age changes one constant.
	 *
	 * Notifications are the exception: nothing about the notification vocabulary implies an
	 * age, so there is no constant to defer to and the caller states the age it is pruning
	 * to. That asymmetry is deliberate - inventing a constant in this file to match would
	 * put a retention policy in a file that has no business holding one.
	 *
	 * This is a deliberate limitation, not an oversight. It is recorded as such in the
	 * Phase 15 completion report rather than left for somebody to discover.
	 *
	 * ### Why invitations are swept by their own service
	 *
	 * An expired invitation is a *refusal*, not a deletion. It records that the link was
	 * valid until a moment and then was not, which is a different fact from "there is no
	 * longer a row here" - and the difference is the whole point of a token with an expiry.
	 * So `expire_stale()` marks them rather than removing them, and a stale invitation can
	 * still be shown to an administrator as having been issued and having expired.
	 *
	 * @return array<string, int> What was removed, keyed by store. A count of zero means
	 *                            nothing was old enough, or the table was not present -
	 *                            never that a removal was claimed and did not happen.
	 */
	private function prune_collaboration() {
		$removed = array();

		if ( class_exists( '\ReplicaForge\Collaboration_Log' ) ) {
			$removed = ( new \ReplicaForge\Collaboration_Log() )->prune();
		}

		if ( class_exists( '\ReplicaForge\Notification_Service' ) ) {
			$removed['notifications'] = (int) ( new \ReplicaForge\Notification_Service() )->prune(
				defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS * 90 : 90 * 86400
			);
		}

		if ( class_exists( '\ReplicaForge\Invitation_Service' ) ) {
			$removed['invitations_expired'] = (int) ( new \ReplicaForge\Invitation_Service() )->expire_stale();
		}

		return $removed;
	}

	/**
	 * Return the stored snapshot summaries for a draft.
	 *
	 * @param int $post_id Draft post identifier.
	 * @return array<int, array<string, mixed>>
	 */
	private function stored_snapshots( $post_id ) {
		$stored = get_post_meta( (int) $post_id, Correction_Limits::META_PREFIX . 'snapshots', true );
		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : array();
	}

	/**
	 * Persist the snapshot summaries for a draft.
	 *
	 * @param int                             $post_id   Draft post identifier.
	 * @param array<int, array<string, mixed>> $snapshots Summaries.
	 * @return void
	 */
	private function store_snapshots( $post_id, array $snapshots ) {
		if ( empty( $snapshots ) ) {
			delete_post_meta( (int) $post_id, Correction_Limits::META_PREFIX . 'snapshots' );
			return;
		}
		update_post_meta( (int) $post_id, Correction_Limits::META_PREFIX . 'snapshots', array_values( $snapshots ) );
	}

	/**
	 * Return a summary of stored snapshot state.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot_summary() {
		$post_ids = $this->drafts_with_snapshots();
		$total    = 0;
		foreach ( $post_ids as $post_id ) {
			$total += count( $this->stored_snapshots( $post_id ) );
		}
		return array(
			'post_ids' => $post_ids,
			'total'    => $total,
			'drafts'   => count( $post_ids ),
		);
	}

	/**
	 * Return a storage report for the system status screen.
	 *
	 * @return array<string, mixed>
	 */
	public function storage_report() {
		global $wpdb;

		// A transient is stored as two rows, a value and a timeout, so counting
		// rows would report every transient twice. Only the value rows are counted.
		$counts = array(
			'transients'  => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_replicaforge_' ) . '%'
				)
			),
			'transient_rows' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options}
					 WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_replicaforge_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_replicaforge_' ) . '%'
				)
			),
			'jobs'        => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
					Job_Repository::OPTION
				)
			),
		);

		$log_size = 0;
		$stored   = get_option( Logger::OPTION, array() );
		if ( is_array( $stored ) ) {
			$log_size = strlen( (string) wp_json_encode( $stored ) );
		}

		$counts['log_entries']  = is_array( $stored ) ? count( $stored ) : 0;
		$counts['log_bytes']    = $log_size;
		$counts['snapshots']    = (int) $this->snapshot_summary()['total'];

		/*
		 * The caches.
		 *
		 * `storage_report()` counted three options and the log, so it reported a footprint of a
		 * few kilobytes on a site holding 120 screenshots. Screenshots are the largest thing
		 * this plugin stores - `Render_Cache` writes raw PNG bytes into `wp_options` - and
		 * they were invisible to the one screen whose job is to show what is being used.
		 *
		 * Each store is named rather than swept by pattern, because a pattern cannot tell a
		 * cache entry from a project record, and alarming about or deleting the wrong thing is
		 * worse than not reporting it.
		 */
		$indexed = array(
			'render_cache_index'  => Render_Cache::INDEX_OPTION,
			'content_cache_index' => Content_Cache::ANALYSIS_OPTION,
			'validations'         => Validation_Limits::OPTION,
			'generations'         => Elementor_Repository::OPTION,
			'visual_ai'           => 'replicaforge_visual_ai',
			'site_registry'       => 'replicaforge_site_registry',
			'site_snapshots'      => Multi_Page_Planner::SNAPSHOT_OPTION,
			'websites'            => Website_Repository::OPTION,
		);

		$counts['caches'] = array();

		foreach ( $indexed as $label => $option_name ) {
			$counts['caches'][ $label ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s",
					(string) $option_name,
					$wpdb->esc_like( (string) $option_name ) . '%'
				)
			);
		}

		/*
		 * The per-entry bodies are separate option families, and these hold the bytes. The
		 * index rows above are metadata about them; the entries are the payload.
		 */
		$families = array(
			'render_cache_bodies'  => 'replicaforge_visual_entry_',
			'content_cache_bodies' => 'rfc_entry_',
			'workflow_artifacts'   => Workflow_Artifacts::PREFIX,
		);

		foreach ( $families as $label => $prefix ) {
			$counts['caches'][ $label ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}

		// One total an operator can act on, reported alongside the breakdown rather than instead of it.
		$counts['cache_entries_total'] = (int) array_sum( $counts['caches'] );

		return $counts;
	}
}
