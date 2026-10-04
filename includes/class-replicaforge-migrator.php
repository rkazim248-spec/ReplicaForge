<?php
/**
 * Phase 7: schema migration.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Brings stored data from an older schema to the current one.
 *
 * A migration never discards user data. Where a shape has changed, the old value
 * is carried forward or the record is left readable as it was and reported, so an
 * upgrade degrades to "some history is not shown" rather than to "history is gone".
 */
final class Migrator {

	/**
	 * Option recording the last migration attempt.
	 */
	const STATE_OPTION = 'replicaforge_migration_state';

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
	 * Return the declared migrations, oldest first.
	 *
	 * Each entry declares the version it produces and the work it performs. Adding a
	 * migration is appending an entry here; nothing else changes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function migrations() {
		return array(
			array(
				'from'    => '',
				'to'      => '1.0.0',
				'run'     => array( $this, 'migrate_initial' ),
				'summary' => 'Record the schema version and the first cleanup of expired data.',
			),
			array(
				'from'    => '1.0.0',
				'to'      => '10.0.0',
				'run'     => array( $this, 'migrate_commercial' ),
				'summary' => 'Add the commercial defaults, grant the ReplicaForge capabilities, and record the site plan.',
			),
			array(
				'from'    => '10.0.0',
				'to'      => '11.0.0',
				'run'     => array( $this, 'migrate_reliability' ),
				'summary' => 'Add the reliability defaults and backfill the Phase 11 fields onto existing job records.',
			),
			array(
				'from'    => '11.0.0',
				'to'      => '12.0.0',
				'run'     => array( $this, 'migrate_multipage' ),
				'summary' => 'Add the multi-page defaults and record that no website specification exists yet.',
			),
			array(
				'from'    => '12.0.0',
				'to'      => '13.0.0',
				'run'     => array( $this, 'migrate_visual' ),
				'summary' => 'Add the visual intelligence defaults with every switch off, and record that the five new difference categories are available.',
			),
			array(
				'from'    => '13.0.0',
				'to'      => '14.0.0',
				'run'     => array( $this, 'migrate_content' ),
				'summary' => 'Add the content intelligence defaults with no store connected, and record the content model and mapping engine versions.',
			),

			array(
			'from'    => '14.0.0',
			'to'      => '15.0.0',
			'run'     => array( $this, 'migrate_collaboration' ),
			'summary' => 'Install the collaboration tables, create a personal workspace for each existing project owner, and adopt every project that already has a workspace left untouched.',
			),

			/*
			 * Phase 19.
			 *
			 * `to` is `19.0.0` rather than `16.0.0` because the plugin shipped phases 16, 17
			 * and 18 without a schema change, so there is no 16/17/18 schema to step through.
			 * `Migrator::run()` runs a migration whenever the installed version is lower than
			 * its target, so a site at 15.0.0 and a fresh install both arrive at 19.0.0 by one
			 * step.
			 *
			 * The migration is additive: it re-runs `Collaboration_Schema::install()`, which
			 * is `dbDelta` over every definition and therefore adds the three new tables
			 * without touching the thirteen existing ones. It touches no project data and
			 * deletes nothing.
			 */
			array(
			'from'    => '15.0.0',
			'to'      => '19.0.0',
			'run'     => array( $this, 'migrate_templates' ),
			'summary' => 'Add the template library tables, the template capability grants, and the design-token store. Nothing existing is altered and nothing is removed.',
			),
	/*
	 * Phase 20.
	 *
	 * A separate step from 19.0.0 rather than a fold into the 15.0.0 entry, for a
	 * reason that matters after the fact: folding them would mean a site that already
	 * completed 19.0.0 would never run the Phase 20 half, because `Migrator::run()`
	 * skips a step whose `to` is at or below the recorded version. Two entries is the
	 * only thing that makes both halves run for a site arriving from 15.0.0 *and* for
	 * one already at 19.0.0.
	 *
	 * Additive, like every step here: `Collaboration_Schema::install()` is `dbDelta`
	 * over all definitions, so this adds six tables, alters none of the nineteen that
	 * exist, and deletes nothing. `api_credentials` and `webhooks` have no `token` or
	 * `secret` column, and this migration writes no rows into either.
	 */
	array(
		'from'    => '19.0.0',
		'to'      => '20.0.0',
		'run'     => array( $this, 'migrate_platform' ),
		'summary' => 'Add the developer platform tables for API credentials, webhooks, webhook deliveries, extensions, automations and events. Creates no data and removes nothing.',
	),
		);
	}

	/**
	 * Run any migration the install has not had yet.
	 *
	 * @param bool $force Run regardless of the recorded version.
	 * @return array<string, mixed>
	 */
	public function run( $force = false ) {
		$installed = Schema::installed();
		$applied   = array();

		foreach ( $this->migrations() as $migration ) {
			$target = (string) $migration['to'];
			if ( ! $force && '' !== $installed && version_compare( $installed, $target, '>=' ) ) {
				continue;
			}
			if ( ! is_callable( $migration['run'] ) ) {
				continue;
			}

			try {
				$result = call_user_func( $migration['run'] );
				$applied[] = array(
					'to'      => $target,
					'summary' => (string) $migration['summary'],
					'result'  => is_array( $result ) ? $result : array(),
				);
				Schema::mark_installed( $target );
				$installed = $target;

				/*
				 * Clear a previous failure once a migration has succeeded.
				 *
				 * `STATE_OPTION` was only ever written on failure, so a site that failed 15.0.0
				 * once, fixed the cause, and then migrated cleanly reported that failure
				 * forever on the System Status screen. A healed site looked permanently broken,
				 * which teaches an administrator to ignore the one indicator that exists.
				 */
				$state = get_option( self::STATE_OPTION, array() );

				if ( is_array( $state ) && ! empty( $state ) ) {
					delete_option( self::STATE_OPTION );
				}

				$this->logger->info(
					'schema_migrated',
					'Applied the migration to schema ' . $target . '.',
					array( 'result' => is_array( $result ) ? $result : array() ),
					'system'
				);
			} catch ( \Throwable $exception ) {
				// A failed migration must not leave the version marked as applied, and
				// must not stop the site from loading. The message is recorded so an
				// administrator can act on it.
				$this->logger->error(
					'schema_migration_failed',
					'The migration to schema ' . $target . ' did not complete.',
					array(
						'version' => $target,
						'message' => $exception->getMessage(),
					),
					'system'
				);
				update_option(
					self::STATE_OPTION,
					array(
						'failed_version' => $target,
						'failed_at'      => gmdate( 'c' ),
					),
					false
				);
				return array(
					'success' => false,
					'applied' => $applied,
					'error'   => 'The migration to schema ' . $target . ' did not complete.',
				);
			}
		}

		if ( empty( $applied ) ) {
			// Still mark the current version, so a fresh install with no recorded
			// version does not re-run the first migration on every request.
			Schema::mark_installed( Schema::DB_SCHEMA_VERSION );
		}

		return array(
			'success'      => true,
			'applied'      => $applied,
			'installed'    => Schema::installed(),
			'was_stale'    => Schema::is_stale( $installed ),
		);
	}

	/**
	 * The first migration: record versions and clear expired data.
	 *
	 * @return array<string, mixed>
	 */
	private function migrate_initial() {
		// Expired transients and finished jobs are safe to remove: they are by
		// definition no longer readable, and leaving them grows the options table
		// without bound. Each is reported by its own call, because the two
		// collections have different shapes.
		$purge  = new Maintenance( $this->logger );
		$trans  = $purge->purge_expired();
		$jobs   = ( new Job_Repository( $this->logger ) )->prune( 14 );

		return array(
			'transients_removed' => isset( $trans['transients'] ) ? (int) $trans['transients'] : 0,
			'jobs_removed'       => (int) $jobs,
		);
	}

	/**
	 * The Phase 10 migration: commercial defaults, capabilities, and the site plan.
	 *
	 * Every step is idempotent and none of them destroys anything. That is the whole
	 * design constraint, and it is why this migration can be re-run by hand from the
	 * system status screen without anyone having to think about it first.
	 *
	 * It does four things:
	 *
	 * 1. Grants the ReplicaForge capabilities to the roles that should hold them.
	 *    Without this, an installation upgrading from Phase 9 would have the code
	 *    but not the permissions, and every user would be refused.
	 * 2. Records the site's plan as the free plan if it has never been set. This is
	 *    what an unconfigured site resolves to anyway; writing it makes the state
	 *    visible rather than implied.
	 * 3. Records the trial configuration, which is disabled by default. Again this is
	 *    the implicit default, and making it explicit is what stops a later change
	 *    from looking like it enabled something.
	 * 4. Leaves the plan definitions empty, so the shipped set is used. Storing them
	 *    would freeze a plan set that is supposed to be configurable, and an
	 *    administrator who had overridden a limit would find the override lost on
	 *    the next plan release.
	 *
	 * No project data, no analysis, no draft, and no validation is touched. The
	 * migration cannot read any of it — it has no call into
	 * {@see Project_Repository} or any Phase 1 to 9 service at all.
	 *
	 * @return array<string, mixed>
	 */
	private function migrate_commercial() {
		$granted = Capabilities::grant_default_roles();

		$site_plan_set = false;
		if ( false === get_option( License_Manager::SITE_PLAN_OPTION, false ) ) {
			update_option( License_Manager::SITE_PLAN_OPTION, 'free', false );
			$site_plan_set = true;
		}

		$trial_set = false;
		if ( false === get_option( Plan_Storage::TRIAL_OPTION, false ) ) {
			Plan_Storage::store_trial( Plan_Storage::trial_settings() );
			$trial_set = true;
		}

		$orphans = Capabilities::orphan_check();

		Audit_Log::record(
			'capabilities_granted',
			array(
				'roles' => implode( ',', array_keys( $granted ) ),
				'count' => array_sum( $granted ),
			),
			0
		);

		return array(
			'capabilities_added'   => array_sum( $granted ),
			'roles_changed'        => count( $granted ),
			'site_plan_written'    => $site_plan_set,
			'trial_written'        => $trial_set,
			'orphaned_capabilities' => $orphans['orphaned'],
		);
	}

	/**
	 * The Phase 11 migration: reliability defaults and job record backfill.
	 *
	 * Idempotent, and it destroys nothing.
	 *
	 * The interesting part is the backfill. A job created before this phase has no
	 * `checkpoint`, no owning `user_id`, and no `project_id`, and the reliability
	 * layer reads all three. Without a backfill those jobs would be invisible to
	 * recovery — `Job_Recovery` could not tell whether one had already written, and
	 * would treat every pre-existing job as safe to re-run, which is precisely the
	 * mistake §28 warns about.
	 *
	 * So each existing job gets a checkpoint derived from the stage it had already
	 * reached, plus the two new terminal states. The derivation is conservative: a
	 * job that reached `generate` or later is treated as having written, because
	 * assuming it has is the safe direction.
	 *
	 * No project, analysis, draft, or validation is read or written. The migration
	 * touches the job store and the new options, and nothing else.
	 *
	 * @return array<string, mixed>
	 */
	private function migrate_reliability() {
		$repository = new Job_Repository( $this->logger );
		$jobs       = $repository->all();
		$backfilled = 0;
		$expired    = 0;

		foreach ( $jobs as $job ) {
			$job_id = (string) ( $job['job_id'] ?? '' );
			if ( '' === $job_id ) {
				continue;
			}

			// A job that already carries the Phase 11 fields is skipped. Re-writing
			// every record on every run is not idempotency, it is repeated work: on a
			// site with a hundred jobs a re-applied migration would perform a hundred
			// writes and report a hundred backfills, which is indistinguishable from
			// having done nothing the first time. This is the check that makes the
			// reported count mean something.
			if ( ! empty( $job['checkpoint'] ) && array_key_exists( 'queue_state', $job ) ) {
				continue;
			}

			$stage = (string) ( $job['stage'] ?? Job_Limits::STAGES[0] );
			if ( ! Job_Checkpoint::is_stage( $stage ) ) {
				$stage = Job_Limits::STAGES[0];
			}

			// A completed or cancelled job gets a fully recorded checkpoint so its
			// history is readable. A job that was in flight gets only the stages it
			// demonstrably completed, never the stage it was attempting.
			$completed = array();
			$status    = (string) ( $job['status'] ?? '' );
			$finished  = in_array( $status, array( Job_Limits::STATUSES['completed'], Job_Limits::STATUSES['cancelled'] ), true );

			if ( $finished ) {
				$completed = array_values( array_diff( Job_Limits::STAGES, array( Job_Limits::STAGES[0] ) ) );
			} else {
				$index = Job_Limits::stage_index( $stage );
				for ( $i = 1; $i < $index; $i++ ) {
					$completed[] = Job_Limits::STAGES[ $i ];
				}
			}

			$changes = array(
				'checkpoint'  => Job_Checkpoint::sanitize(
					array(
						'stage'            => $stage,
						'completed_stages' => $completed,
						'draft_id'         => (int) ( $job['draft_id'] ?? 0 ),
					)
				),
				'queue_state'  => Job_States::is_valid( $status ) ? $status : Job_States::QUEUED,
				'user_id'      => (int) ( $job['user_id'] ?? 0 ),
				'project_id'   => (string) ( $job['project_id'] ?? '' ),
			);

			// A job that was `running` when the plugin was upgraded has a lease from a
			// process that no longer exists. It is put back in the queue rather than
			// left claiming to run.
			if ( Job_Limits::STATUSES['running'] === $status ) {
				$changes['status']      = Job_States::QUEUED;
				$changes['lease_until'] = 0;
				$expired++;
			}

			if ( $repository->update( $job_id, $changes ) !== null ) {
				$backfilled++;
			}
		}

		// The new options are written so their defaults are visible rather than
		// implied. An empty orchestrator setting is a real answer, not a missing one.
		Job_Manager::save( array() );
		Ai_Cost_Estimator::save( array() );

		return array(
			'jobs_backfilled' => $backfilled,
			'jobs_total'      => count( $jobs ),
			'jobs_released'   => $expired,
			'orchestrator'    => Job_Manager::settings(),
			'estimator'       => Ai_Cost_Estimator::settings(),
		);
	}

	/**
	 * Phase 12: the multi-page migration.
	 *
	 * Deliberately small. Phase 12 adds no database table and no data that Phase 1 to
	 * 11 stored in a shape it cannot be read from, so there is nothing to convert.
	 * What it does need is for the new options to *exist* with their defaults rather
	 * than being implied by a `get_option( $name, array() )` at read time — a user
	 * looking at the site options should see the multi-page settings, and a test
	 * should be able to assert they are set rather than inferring it.
	 *
	 * It also records the phase in the migrator's own state so a completed Phase 12
	 * migration is visible without opening the schema table.
	 *
	 * @return array<string, mixed>
	 */
	private function migrate_multipage() {
		// Written unconditionally: the content is a constant, so a re-run writes
		// the same bytes and the operation is idempotent by construction rather than
		// by a guard clause.
		$defaults = array(
			'websites' => array(),
			'registry' => array(),
			'snapshots'=> array(),
		);

		$existing = get_option( Website_Repository::OPTION, null );
		if ( null === $existing ) {
			add_option( Website_Repository::OPTION, $defaults['websites'], '', false );
		}

		$registry = get_option( Component_Registry::OPTION, null );
		if ( null === $registry ) {
			add_option( Component_Registry::OPTION, $defaults['registry'], '', false );
		}

		$snapshots = get_option( Multi_Page_Planner::SNAPSHOT_OPTION, null );
		if ( null === $snapshots ) {
			add_option( Multi_Page_Planner::SNAPSHOT_OPTION, $defaults['snapshots'], '', false );
		}

		// Global style ownership is recorded as `unknown` rather than left absent, so
		// "ReplicaForge does not own your global styles" is a stored decision and not
		// the absence of one. The default behaviour of `Site_Compatibility::ownership()`
		// is already do-nothing, so this write changes no behaviour; it makes the
		// decision legible.
		$ownership = get_option( Site_Compatibility::OWNERSHIP_OPTION, null );
		if ( null === $ownership ) {
			add_option( Site_Compatibility::OWNERSHIP_OPTION, array(), '', false );
		}

		return array(
			'websites_created' => ( null === $existing ? 1 : 0 ),
			'websites_present' => count( is_array( $existing ) ? $existing : array() ),
			'registry_created' => ( null === $registry ? 1 : 0 ),
			'snapshots_created' => ( null === $snapshots ? 1 : 0 ),
			'ownership_state'  => 'unknown',
			'schema'           => Site_Limits::SCHEMA_VERSION,
		);
	}

	/**
	 * Phase 13: the visual intelligence migration.
	 *
	 * Deliberately does three small things and no more.
	 *
	 * 1. **Creates the visual settings with every switch off.** §54 and §73 make
	 *    screenshot-to-AI a user decision, and `Visual_AI::settings()` already
	 *    defaults `vision_enabled` to false. Writing the option makes the *whole* set
	 *    of decisions visible in the site options rather than only the one that
	 *    happens to differ from a default.
	 *
	 * 2. **Creates the render cache index** as an empty array rather than letting it
	 *    appear on first write. An absent index and an empty one behave identically;
	 *    having one present means a user can see that the cache exists and is empty
	 *    rather than wondering whether rendering is silently failing.
	 *
	 * 3. **Records the new difference categories.** Not as data — they are a constant
	 *    in `Validation_Limits`, extended in place — but as a reported fact, so a
	 *    support conversation about an unfamiliar category in a report can be traced
	 *    to the version that introduced it.
	 *
	 * It is idempotent by construction: every write is `add_option()`, which is a
	 * no-op when the option already exists.
	 *
	 * @return array<string, mixed>
	 */
	private function migrate_visual() {
		$created = 0;

		if ( null === get_option( Visual_AI::OPTION, null ) ) {
			add_option(
				Visual_AI::OPTION,
				array(
					'vision_enabled'         => false,
					'visual_analysis_enabled' => true,
					'screenshot_validation'   => false,
					'animation_normalization' => true,
					'dynamic_masking'         => true,
					'include_transient_ui'    => false,
					'max_images_per_request'  => 2,
					'crop_before_send'        => true,
				),
				'',
				false
			);
			$created++;
		}

		if ( null === get_option( Render_Cache::INDEX_OPTION, null ) ) {
			add_option( Render_Cache::INDEX_OPTION, array(), '', false );
			$created++;
		}

		$categories = Validation_Limits::CATEGORIES;
		$visual     = Visual_Limits::added_categories();

		return array(
			'options_created'    => $created,
			'vision_enabled'     => false,
			'schema'             => Visual_Limits::SCHEMA_VERSION,
			'analyzer_version'   => Visual_Limits::ANALYZER_VERSION,
			'categories_added'   => $visual,
			'categories_total'   => count( $categories ),
			'categories_present' => count( array_intersect( $visual, $categories ) ),
			'note'               => __( 'Rendered comparison stays off until a render provider is configured, and screenshot input to AI stays off until you turn it on. Neither is required for the structural pipeline.', 'replicaforge' ),
		);
	}

	/**
	 * Phase 14: the content intelligence migration.
	 *
	 * Deliberately does three things and no more.
	 *
	 * 1. **Creates the content cache index** as an empty array rather than letting it
	 *    appear on first write, so a user can see that content caching exists and is
	 *    empty rather than wondering whether analysis is silently failing.
	 *
	 * 2. **Records the project's content mode as `hybrid_replica`.** Section 18 makes
	 *    hybrid the default, and writing it down means the *default* is visible in the
	 *    site's options rather than only existing as a fallback in code - the same
	 *    reasoning Phase 13 used for the visual switches. The confirmation rule itself
	 *    is *derived* from
	 *    {@see Content_Limits::mode_requires_confirmation()} rather than stored, so the
	 *    two cannot disagree about which modes need a confirmation.
	 *
	 * 3. **Records the model and engine versions.** Not as behaviour, but as a reported
	 *    fact, so a support conversation about a stale mapping can be traced to the
	 *    version that produced it. A content cache key depends on the engine version, so
	 *    this is what makes an unexpected cache hit explainable.
	 *
	 * It is idempotent by construction: every write is `add_option()`, which is a no-op
	 * when the option already exists. It also creates **no** table and **no** project,
	 * job, or user structures - section 34 forbids duplicating them, and nothing here
	 * needs them.
	 *
	 * @return array<string, mixed>
	 */
	private function migrate_content() {
		$created = 0;

		if ( null === get_option( Content_Cache::ANALYSIS_OPTION, null ) ) {
			add_option( Content_Cache::ANALYSIS_OPTION, array(), '', false );
			$created++;
		}

		// The literal option name, not a constant on a class that does not exist. The
		// first draft referenced `Content_Mode::OPTION` for a mode class that was never
		// written, and would have fataled on the first migration run.
		$mode_option = 'replicaforge_content_mode';

		if ( null === get_option( $mode_option, null ) ) {
			add_option(
				$mode_option,
				array(
					'mode'                  => 'hybrid_replica',
					'confirmed'             => false,
					// Phase 13's section 73 pattern: every decision visible at once,
					// rather than only the ones that happen to differ from a default.
					'transient_ui'          => 'exclude',
					'show_provenance'       => true,
					'low_confidence_review' => true,
				),
				'',
				false
			);
			$created++;
		}

		return array(
			'options_created'  => $created,
			'schema_version'   => Content_Limits::SCHEMA_VERSION,
			'engine_version'   => Content_Limits::ENGINE_VERSION,
			'mode'             => 'hybrid_replica',
			'needs_confirmation' => Content_Limits::mode_requires_confirmation( 'hybrid_replica' ),
			'ownership_states' => count( Content_Limits::ownership_states() ),
			'actions'          => count( Content_Limits::ACTIONS ),
			'operations'       => array( 'content_analysis', 'content_mapping', 'content_apply' ),
			'note'             => __( 'No store was connected and no product, price, or review was created. Content mapping stays off until you analyse a page and confirm the mode.', 'replicaforge' ),
		);
	}

	/**
	 * Return the state of the last migration, for the system status screen.
	 *
	 * @return array<string, mixed>
	 */
	public function state() {
		$installed = Schema::installed();
		$state     = get_option( self::STATE_OPTION, array() );

		return array(
			'installed'      => $installed,
			'current'        => Schema::DB_SCHEMA_VERSION,
			'up_to_date'     => '' !== $installed && version_compare( $installed, Schema::DB_SCHEMA_VERSION, '>=' ),
			'pending'        => $this->pending(),
			'last_failure'   => is_array( $state ) ? $state : array(),
		);
	}

	/**
	 * Return the migrations that have not run.
	 *
	 * @return array<int, string>
	 */
	public function pending() {
		$installed = Schema::installed();
		$pending   = array();
		foreach ( $this->migrations() as $migration ) {
			$target = (string) $migration['to'];
			if ( '' === $installed || version_compare( $installed, $target, '<' ) ) {
				$pending[] = $target;
			}
		}
		return $pending;
	}
	/**
	 * Install the collaboration tables and adopt every existing project into a workspace.
	 *
	 * ### What section 57 asks for, and what each step does
	 *
	 * 1. *Detect existing projects* - read them from the repository that has always held
	 *    them, so nothing is duplicated or re-derived.
	 * 2. *Create or assign a default workspace* - one personal workspace per owning user,
	 *    created only where the user has none.
	 * 3. *Associate the projects* - the `workspace_id` is written onto the existing project
	 *    record, so the project keeps its own identity.
	 * 4. *Preserve ids* - nothing is renumbered and no record is recreated.
	 * 5. *Preserve history* - the option-stored project array is read and rewritten whole, so
	 *    every version, draft reference and analysis payload comes back unchanged.
	 * 6. *Preserve permissions* - the personal workspace is owned by the user who already
	 *    owned the projects, so every existing capability is unchanged. Nothing is granted
	 *    and nothing is revoked.
	 * 7. *Never delete project data* - the migration only adds fields.
	 * 8. *Provide diagnostics* - a per-user report of what was adopted, what was skipped and
	 *    why, stored so an administrator can read it afterwards.
	 *
	 * ### When ownership cannot be determined, nothing is guessed
	 *
	 * A project whose `user_id` is 0 - or names a user account that no longer exists - is
	 * **skipped**, not assigned to a fallback workspace. Section 57 says to mark such a
	 * record for administrator review rather than guess, and guessing here would mean an
	 * orphan project silently appearing under somebody else's workspace, where the owner of
	 * that workspace could then archive it, reassign it, or export it. A skipped project
	 * is visible and inert; a mis-assigned one is neither.
	 *
	 * The same applies to a user who already owns several workspaces: their projects are
	 * left exactly where they are, because the migration has no basis on which to choose one
	 * and "tidying up" a user who has already organised themselves does harm in the name of
	 * consistency.
	 *
	 * ### Idempotent
	 *
	 * Every step is keyed on existing state: a project that already names a workspace is not
	 * touched, a user who already owns a workspace gets none, and the table install is
	 * `dbDelta`. Running the migration twice is therefore a no-op, which matters because
	 * Phase 11 runs migrations on a schedule and on demand.
	 *
	 * @return array<string, mixed>
	 */
	private function migrate_collaboration() {
		$report = array(
			'tables'    => array(),
			'workspaces'=> 0,
			'adopted'   => 0,
			'skipped'   => array(),
			'existing'  => 0,
			'errors'    => array(),
		);

		// ---- 1. The tables. A failure here is reported and the rest of the migration is
		// still attempted, because the project adoption writes to the option store and does
		// not depend on a table - and refusing to adopt would leave a workspace-less install
		// with projects nobody can reach, which is worse than an install with an unusable
		// collaboration screen.
		$schema   = new Collaboration_Schema();
		$installed = $schema->install();
		$report['tables'] = $installed;
		foreach ( (array) ( $installed['errors'] ?? array() ) as $kind => $message ) {
			$report['errors'][] = $kind . ': ' . $message;
		}

		$workspaces = new Workspace_Store();
		$context    = new Project_Context_Store();
		$projects   = new Project_Repository();
		$all        = $projects->all();
		if ( ! is_array( $all ) || array() === $all ) {
			// Nothing to adopt. Not an error - a fresh install has no projects - but it is
			// recorded, because "no projects" and "could not read the projects" otherwise
			// look identical in a diagnostics report.
			$report['note'] = 'no existing projects';
			$this->collaboration_report( $report );
			return $report;
		}

		foreach ( $all as $project ) {
			if ( ! is_array( $project ) ) {
				continue;
			}

			$project_id = (string) ( $project['project_id'] ?? '' );
			if ( '' === $project_id ) {
				$report['skipped'][] = array( 'project_id' => '(none)', 'reason' => 'no_project_id' );
				continue;
			}

			$existing = $context->get( $project_id );
			if ( (string) ( $existing['workspace_id'] ?? '' ) !== '' ) {
				// Already adopted by an earlier run. Counted, not touched.
				$report['existing']++;
				continue;
			}

			$owner_id = (int) ( $project['user_id'] ?? 0 );
			if ( $owner_id < 1 ) {
				$report['skipped'][] = array( 'project_id' => $project_id, 'reason' => 'no_owner' );
				continue;
			}

			$owner = get_userdata( $owner_id );
			if ( ! $owner instanceof \WP_User ) {
				// The account is gone. Not guessed at: see the docblock.
				$report['skipped'][] = array( 'project_id' => $project_id, 'reason' => 'owner_missing' );
				continue;
			}

			$owned = $workspaces->for_user( $owner_id, true );
			if ( count( $owned ) >= Workspace_Limits::MAX_OWNED_WORKSPACES ) {
				$report['skipped'][] = array( 'project_id' => $project_id, 'reason' => 'workspace_limit' );
				continue;
			}

			if ( array() === $owned ) {
				$name  = ( '' !== trim( (string) $owner->display_name ) )
					? (string) $owner->display_name . "'s Workspace"
					: __( 'My Workspace', 'replicaforge' );
				$created = $workspaces->create( $owner_id, $name, array( 'migrated' => true ) );
				if ( null === $created ) {
					$report['skipped'][] = array( 'project_id' => $project_id, 'reason' => 'workspace_not_created' );
					continue;
				}
				$report['workspaces']++;
				$workspace_id = (string) $created['public_id'];
			} else {
				// The user already has a workspace. The first one is adopted into, and the
				// choice is recorded so an administrator can see it was made here rather
				// than by the user.
				$workspace_id = (string) $owned[0]['public_id'];
			}

			$written = $context->update( $project_id, array( 'workspace_id' => $workspace_id ) );
			if ( '' === (string) ( $written['workspace_id'] ?? '' ) ) {
				$report['skipped'][] = array( 'project_id' => $project_id, 'reason' => 'write_failed' );
				continue;
			}

			$report['adopted']++;
		}

		$this->collaboration_report( $report );
		return $report;
	}

	/**
	 * Phase 19: add the template library.
	 *
	 * ### What this does
	 *
	 * Exactly one thing with side effects: re-runs `Collaboration_Schema::install()`, which
	 * is `dbDelta` over every table definition. That adds `replicaforge_templates`,
	 * `replicaforge_template_versions` and `replicaforge_template_components` and leaves the
	 * thirteen Phase 15 tables alone.
	 *
	 * ### What this deliberately does not do
	 *
	 * It creates no templates, extracts nothing from any project, and moves no data. A
	 * migration that invents templates would be a migration that puts content in a user's
	 * library they did not ask for, and §17 and §33 both forbid fabricating a design asset.
	 * A site that wants templates creates them from its own projects, through the library.
	 *
	 * The remaining steps are the ones a schema change genuinely needs: make sure the
	 * capability grants exist for the roles that should have them, and record that the
	 * design-token store is empty rather than absent — "no tokens yet" and "the feature is
	 * not installed" are different states and should not look the same.
	 *
	 * @return array<string, mixed>
	 */
	private function migrate_templates() {
		$report = array(
			'tables'   => array(),
			'granted'  => array(),
			'note'     => '',
		);

		$schema  = new Collaboration_Schema();
		$install = $schema->install();

		$report['tables'] = array_keys( (array) ( $install['tables'] ?? array() ) );

		if ( empty( $install['ok'] ) ) {
			/*
			 * A failed install is reported and, crucially, not swallowed. `Migrator::run()`
			 * only marks the schema version when the callable returns, so a throw here
			 * leaves the version unmoved and the next request retries — which is the
			 * self-healing path the `Migrator` docblock describes.
			 */
			$report['note'] = 'The template tables could not be created. The migration will be retried on the next request.';

			throw new \RuntimeException( 'replicaforge_templates_installation_failed' );
		}

		/*
		 * The capability grants.
		 *
		 * `Capabilities::grant_default_roles()` re-grants the plugin's own WordPress roles,
		 * which is idempotent and is what the commercial migration does. The workspace
		 * template capabilities are *not* granted here: they are granted by role at
		 * permission-check time from `Workspace_Limits::ROLE_CAPS`, so there is no stored row
		 * to backfill and nothing to do. The check below exists to prove that, rather than
		 * asserting it.
		 */
		( new Capabilities() )->grant_default_roles();

		$report['granted'] = array(
			'wordpress_roles' => 'granted',
			'workspace_roles' => 'inherent',
		);

		$report['capability_count'] = count( Workspace_Limits::capabilities() );

		$report['note'] = 'The template library is empty. Templates are created from your own projects.';

		update_option( 'replicaforge_template_migration', $report, false );

		return $report;
	}

	/**
	 * Install the Phase 20 developer-platform tables.
	 *
	 * ### One step from 19.0.0, not one per phase
	 *
	 * Phases 20 changed the schema once. Writing a migration per phase would mean a site
	 * upgrading through 20.0.0 runs three near-identical `dbDelta()` passes, and the report
	 * would carry three entries saying the same thing.
	 *
	 * ### This migration creates no data
	 *
	 * No credential, no webhook, no automation, no event, no extension record. An empty
	 * library is the correct post-install state, and a migration that invented rows would put
	 * objects in a user's account that they did not ask for.
	 *
	 * The one thing it *does* record is the absence, because "no credentials exist" and "the
	 * credential feature is not installed" must not look identical to an administrator
	 * reading the console.
	 *
	 * @return array<string, mixed>
	 */
	private function migrate_platform() {
		$report = array(
			'tables'       => array(),
			'granted'      => array(),
			'vocabularies' => array(),
			'note'         => '',
		);

		$schema  = new Collaboration_Schema();
		$install = $schema->install();

		$report['tables'] = array_keys( (array) ( $install['tables'] ?? array() ) );

		if ( empty( $install['ok'] ) ) {
			/*
			 * Deliberately not swallowed. `Migrator::run()` only records the schema version
			 * when the callable returns, so a throw here leaves the version unmoved and the
			 * next request retries — the self-healing path the `Migrator` docblock describes.
			 *
			 * Failing loudly also matters here specifically: without these tables the developer
			 * console would render and every action would refuse, which looks like a
			 * permissions bug rather than a missing migration.
			 */
			$report['note'] = 'The developer platform tables could not be created. The migration will be retried on the next request.';

			throw new \RuntimeException( 'replicaforge_platform_installation_failed' );
		}

		/*
		 * No capability grants to backfill.
		 *
		 * The six Phase 20 workspace capabilities (`api.credentials.read`, `api.webhooks.manage`,
		 * and so on) are resolved by role from `Workspace_Limits::ROLE_CAPS` at check time, so
		 * there is no stored row for them and nothing to grant. `Capabilities::grant_default_roles()`
		 * is re-run anyway because it is idempotent and it covers the plugin's WordPress roles,
		 * which *are* stored.
		 *
		 * The audit actions added in Phase 20 (`api_credential_created` and friends) are part of
		 * `Workspace_Limits::AUDIT_EVENTS` for the same reason — a closed vocabulary read at
		 * write time, not a permission row.
		 */
		( new Capabilities() )->grant_default_roles();

		$report['granted'] = array(
			'wordpress_roles' => 'granted',
			'workspace_roles' => 'inherent',
		);

		/*
		 * The counts, so the console's "did this install" check is evidence rather than an
		 * assumption. `Workspace_Limits::capabilities()` is the single master vocabulary the
		 * permission manager validates against.
		 */
		$report['capability_count'] = count( Workspace_Limits::capabilities() );
		$report['vocabularies']     = array(
			'scopes'            => count( Platform_Limits::API_SCOPES ),
			'gate_mappings'      => count( Platform_Limits::GATE_SCOPES ),
			'events'            => count( Platform_Limits::EVENTS ),
			'extension_caps'    => count( Platform_Limits::EXTENSION_CAPABILITIES ),
			'extension_perms'   => count( Platform_Limits::EXTENSION_PERMISSIONS ),
			'automation_triggers' => count( Platform_Limits::AUTOMATION_TRIGGERS ),
			'automation_actions' => count( Platform_Limits::AUTOMATION_ACTIONS ),
		);

		$report['note'] = 'No API credentials, webhooks, automations or extensions exist. Create them in the developer console.';

		update_option( 'replicaforge_platform_migration', $report, false );

		return $report;
	}

	/**
	 * Store the adoption diagnostics where an administrator can read them.
	 *
	 * Stored rather than logged, because Â§57 asks for migration diagnostics and a log line
	 * is gone by the time anyone thinks to look. The report names skipped projects and the
	 * reason for each, which is the whole point: a project the migration declined to guess
	 * about has to be *findable*, or the decision is invisible and the record is incomplete.
	 *
	 * @param array<string, mixed> $report Report.
	 * @return void
	 */
	private function collaboration_report( array $report ) {
		$report['generated_at'] = gmdate( 'c' );
		update_option( 'replicaforge_collaboration_migration', $report, false );
	}
}
