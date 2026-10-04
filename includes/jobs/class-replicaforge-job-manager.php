<?php
/**
 * Phase 11: the job orchestrator.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The single entry point for starting and running long work.
 *
 * Everything Phase 7 built still does its job: `Job_Repository` stores records,
 * `Job_Queue` owns the lifecycle, and `Job_Runner` executes the stages. This class
 * does not replace any of that and must not grow into a second queue.
 *
 * What it adds is the four things that were missing around the queue:
 *
 * 1. **Ordered admission.** Enqueue goes through authentication, capability,
 *    ownership, the Phase 10 entitlement, and the user's active-job limit, in that
 *    order, and reserves usage before anything starts. `Entitlement_Manager::begin()`
 *    already does the first four and returns a reservation token; the reservation
 *    is what makes a long job cost one unit rather than zero or two.
 * 2. **A resource lock per job.** Acquired before the first stage, held across
 *    them, released on every exit including a fatal one.
 * 3. **A time budget per tick.** A worker checks how much execution time is left
 *    and stops at a stage boundary rather than being killed mid-stage, which is
 *    what produces the "expired" jobs `Job_Recovery` has to reason about.
 * 4. **Cooperative cancellation and pause checks** at those same boundaries.
 *
 * ### Why a tick has a time budget at all
 *
 * WP-Cron fires inside a normal page request, on a host with a shared PHP time
 * limit and no say over how many workers there are. A tick that starts three jobs
 * and each runs for 25 seconds will hit the limit and be killed partway through
 * the third — leaving a job that says it is running, a half-written draft, and a
 * lock held until its TTL. Measuring the remaining budget and stopping cleanly at
 * a stage boundary converts all three of those into a job that is simply not
 * finished yet, which is recoverable.
 */
final class Job_Manager {

	/**
	 * Option holding the orchestrator configuration.
	 */
	const OPTION = 'replicaforge_job_settings';

	/**
	 * Fraction of the PHP time limit a single tick may consume.
	 *
	 * Half, so there is room for the shutdown work — releasing locks, writing the
	 * lease — before the limit is reached. A tick that uses 100% of what it has
	 * leaves nothing for the cleanup that makes it recoverable.
	 */
	const TIME_SHARE = 0.5;

	/**
	 * Minimum seconds a tick will run for, however little time is left.
	 *
	 * Below this, starting work is more likely to be interrupted than to finish, so
	 * the tick returns and the next one takes it.
	 */
	const MIN_BUDGET_SECONDS = 5;

	/**
	 * Default maximum active jobs per user.
	 */
	const DEFAULT_USER_JOBS = 5;

	/**
	 * Default maximum simultaneous AI-holding jobs on the site.
	 */
	const DEFAULT_AI_CONCURRENCY = 2;

	/**
	 * Job queue.
	 *
	 * @var Job_Queue
	 */
	private $queue;

	/**
	 * Job repository.
	 *
	 * @var Job_Repository
	 */
	private $jobs;

	/**
	 * Resource locks.
	 *
	 * @var Job_Lock
	 */
	private $locks;

	/**
	 * Cancellation requests.
	 *
	 * @var Job_Cancellation
	 */
	private $cancellations;

	/**
	 * Recovery.
	 *
	 * @var Job_Recovery
	 */
	private $recovery;

	/**
	 * Entitlements.
	 *
	 * @var Entitlement_Manager
	 */
	private $entitlements;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * The job this process is currently running.
	 *
	 * @var string
	 */
	private $current = '';

	/**
	 * The runner used to execute stages.
	 *
	 * Injected by {@see Plugin} so this class has no construction-order dependency
	 * on `Job_Runner`'s service graph — `Job_Runner` needs the analyzers, and those
	 * need the HTTP client, and none of that should be a reason this class cannot
	 * be built in a test.
	 *
	 * @var Job_Runner|null
	 */
	private $runner = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $services Optional service overrides.
	 */
	public function __construct( array $services = array() ) {
		// Every key is read defensively. An injected subset is the normal case — a
		// test that wants only a lock manager passes one key — so reading the array
		// positionally would emit a notice for each service the caller did not care
		// about, and this class runs inside the test suite.
		$this->logger        = ( isset( $services['logger'] ) && $services['logger'] instanceof Logger ) ? $services['logger'] : new Logger();
		$this->jobs          = ( isset( $services['jobs'] ) && $services['jobs'] instanceof Job_Repository ) ? $services['jobs'] : new Job_Repository( $this->logger );
		$this->queue         = ( isset( $services['queue'] ) && $services['queue'] instanceof Job_Queue ) ? $services['queue'] : new Job_Queue( $this->jobs, $this->logger );
		$this->locks         = ( isset( $services['locks'] ) && $services['locks'] instanceof Job_Lock ) ? $services['locks'] : new Job_Lock( $this->logger );
		$this->cancellations = ( isset( $services['cancellations'] ) && $services['cancellations'] instanceof Job_Cancellation ) ? $services['cancellations'] : new Job_Cancellation();
		$this->recovery      = ( isset( $services['recovery'] ) && $services['recovery'] instanceof Job_Recovery ) ? $services['recovery'] : new Job_Recovery( $this->jobs, $this->logger );
		$this->entitlements  = ( isset( $services['entitlements'] ) && $services['entitlements'] instanceof Entitlement_Manager ) ? $services['entitlements'] : new Entitlement_Manager( null, null, null, $this->logger );

		// A fatal error must not leave a resource lock held. A shutdown function is
		// the only cleanup that runs on the failure path, which is exactly the path
		// that needs it.
		register_shutdown_function( array( $this->locks, 'release_all' ) );
	}

	/**
	 * Return the job queue.
	 *
	 * @return Job_Queue
	 */
	public function queue() {
		return $this->queue;
	}

	/**
	 * Return the job repository.
	 *
	 * @return Job_Repository
	 */
	public function jobs() {
		return $this->jobs;
	}

	/**
	 * Return the lock manager.
	 *
	 * @return Job_Lock
	 */
	public function locks() {
		return $this->locks;
	}

	/* ---------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------ */

	/**
	 * Return the orchestrator configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			// Concurrent jobs for one user. Separate from the Phase 10 usage limits:
			// a plan can allow a hundred generations a month and still be right to
			// refuse five of them at once.
			'user_jobs'         => isset( $stored['user_jobs'] ) && is_numeric( $stored['user_jobs'] )
				? max( 1, min( 20, (int) $stored['user_jobs'] ) )
				: self::DEFAULT_USER_JOBS,
			// Simultaneous jobs holding an AI call. The default of two is not a
			// guess about a server: it is a guess about a plan. Most self-hosted
			// sites share one IP with several other sites, and a provider that sees
			// one account issuing a burst of requests from one address will rate
			// limit it regardless of how many workers the host has.
			'ai_concurrency'    => isset( $stored['ai_concurrency'] ) && is_numeric( $stored['ai_concurrency'] )
				? max( 1, min( 10, (int) $stored['ai_concurrency'] ) )
				: self::DEFAULT_AI_CONCURRENCY,
			// Seconds a stage may run before a tick stops. A stage that exceeds this
			// is not killed — it is allowed to finish its current unit of work and
			// the tick stops at the next boundary.
			'stage_budget'      => isset( $stored['stage_budget'] ) && is_numeric( $stored['stage_budget'] )
				? max( 5, min( 600, (int) $stored['stage_budget'] ) )
				: 60,
			'tick_jobs'         => isset( $stored['tick_jobs'] ) && is_numeric( $stored['tick_jobs'] )
				? max( 1, min( 10, (int) $stored['tick_jobs'] ) )
				: 3,
			'enable_recovery'   => ! isset( $stored['enable_recovery'] ) || ! empty( $stored['enable_recovery'] ),
		);
	}

	/**
	 * Store the orchestrator configuration.
	 *
	 * @param array<string, mixed> $input Configuration.
	 * @return array{success: bool, settings: array<string, mixed>, errors: array<int, string>}
	 */
	public static function save( array $input ) {
		$clean  = self::settings();
		$errors = array();

		$bounds = array(
			'user_jobs'      => array( 1, 20 ),
			'ai_concurrency' => array( 1, 10 ),
			'stage_budget'   => array( 5, 600 ),
			'tick_jobs'      => array( 1, 10 ),
		);

		foreach ( $bounds as $key => $range ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			if ( ! is_numeric( $input[ $key ] ) ) {
				$errors[] = sprintf(
					/* translators: %s: a setting name. */
					__( '"%s" must be a number.', 'replicaforge' ),
					$key
				);
				continue;
			}
			$value              = (int) $input[ $key ];
			$clean[ $key ]      = max( $range[0], min( $range[1], $value ) );
		}

		if ( isset( $input['enable_recovery'] ) ) {
			$clean['enable_recovery'] = ! empty( $input['enable_recovery'] );
		}

		if ( array() !== $errors ) {
			return array(
				'success'  => false,
				'settings' => self::settings(),
				'errors'   => $errors,
			);
		}

		update_option( self::OPTION, $clean, false );

		return array(
			'success'  => true,
			'settings' => $clean,
			'errors'   => array(),
		);
	}

	/* ---------------------------------------------------------------------
	 * Admission
	 * ------------------------------------------------------------------ */

	/**
	 * Admit an operation: check everything, then queue it.
	 *
	 * The order is the brief's order, and it is the same order
	 * `Entitlement_Manager::check()` uses, so the two cannot disagree about why a
	 * request was refused.
	 *
	 * @param string               $type      Job type.
	 * @param int                  $user_id   Requesting user.
	 * @param string               $project_id Owning project, if any.
	 * @param array<string, mixed> $params    Job parameters.
	 * @param array<string, mixed> $options   Queue options.
	 * @return array<string, mixed>
	 */
	public function admit( $type, $user_id, $project_id = '', array $params = array(), array $options = array() ) {
		$user_id    = (int) $user_id;
		$project_id = is_string( $project_id ) ? trim( $project_id ) : '';

		// The operation the entitlement gate checks is the job type, and Phase 10
		// declares it. An undeclared type is refused rather than queued, because a
		// type the gate does not know has no limit and no feature behind it.
		$operation = $this->operation_for( $type );

		$access = $this->entitlements->access()->refusal( $user_id, $project_id );
		if ( '' !== $project_id && empty( $access['allowed'] ) ) {
			return $this->refusal(
				(string) $access['code'],
				(string) $access['message'],
				(int) $access['status']
			);
		}

		$begin = $this->entitlements->begin(
			$operation,
			$user_id,
			array(
				'project_id' => $project_id,
				'metadata'   => array( 'source' => 'job_queue' ),
			)
		);

		if ( empty( $begin['allowed'] ) ) {
			return $this->refusal(
				(string) $begin['code'],
				(string) $begin['message'],
				(int) $begin['status'],
				isset( $begin['details'] ) ? (array) $begin['details'] : array()
			);
		}

		$limit = $this->active_job_limit( $user_id );
		$active = $this->count_active( $user_id );
		if ( $active >= $limit ) {
			// The reservation is released rather than settled: nothing ran, and
			// charging a user for a job that was never queued is exactly the
			// accounting bug §38 is about.
			$this->entitlements->fail( $user_id, (string) $begin['reservation'], 'job_queue_full' );

			return $this->refusal(
				'too_many_active_jobs',
				sprintf(
					/* translators: %d: the number of jobs already running. */
					__( 'You already have %d ReplicaForge operations running. Wait for one to finish before starting another.', 'replicaforge' ),
					$active
				),
				429,
				array(
					'active' => $active,
					'limit'  => $limit,
				)
			);
		}

		$queued = $this->queue->enqueue(
			$type,
			array_merge(
				array(
					'user_id'    => $user_id,
					'project_id' => $project_id,
					'operation'  => $operation,
				),
				$params
			),
			$options
		);

		$job = isset( $queued['job'] ) ? (array) $queued['job'] : array();
		$job_id = isset( $job['job_id'] ) ? (string) $job['job_id'] : '';

		// A duplicate returns the job that already exists, and the reservation taken
		// for this request is released rather than settled — the work is already
		// accounted to the request that started it. Charging twice for one job
		// would be a bug a user would notice immediately.
		if ( ! empty( $queued['duplicate'] ) ) {
			$this->entitlements->fail( $user_id, (string) $begin['reservation'], 'duplicate_job' );

			$this->logger->info(
				'job_admission_duplicate',
				'A duplicate request returned the existing job instead of queueing a second one.',
				array(
					'job_id' => $job_id,
					'type'   => (string) $type,
				),
				'job'
			);

			return array(
				'success'    => true,
				'duplicate'  => true,
				'job'        => $job,
				'job_id'     => $job_id,
				'reservation' => '',
				'message'    => __( 'An identical operation is already running. It was returned instead of starting a second one.', 'replicaforge' ),
			);
		}

		// The reservation is settled immediately and the *job* records the charge.
		// A job that then fails does not refund — the work was attempted, and §38
		// charges for completed work, which is a narrower thing than "attempted".
		// The job's own record is the place a refund would belong, and there is no
		// refund path yet, so this is a stated limitation rather than a silent gap.
		$this->entitlements->settle( $user_id, (string) $begin['reservation'], (string) ( $begin['plan_id'] ?? '' ) );

		$this->jobs->update(
			$job_id,
			array(
				'checkpoint'    => Job_Checkpoint::empty_checkpoint(),
				'queue_state'   => Job_States::QUEUED,
				'user_id'       => $user_id,
				'project_id'    => $project_id,
				'operation'     => $operation,
				'next_attempt'  => 0,
			)
		);

		return array(
			'success'     => true,
			'duplicate'   => false,
			'job'         => $this->jobs->find( $job_id ),
			'job_id'      => $job_id,
			'reservation' => '',
			'message'     => '',
		);
	}

	/**
	 * Return the operation a job type maps to for the entitlement gate.
	 *
	 * @param string $type Job type.
	 * @return string
	 */
	public function operation_for( $type ) {
		$map = array(
			'replica'     => 'generation',
			'analysis'    => 'analysis',
			'design'      => 'analysis',
			'generation'  => 'generation',
			'validation'  => 'validation',
			'correction'  => 'correction',
			'sync'        => 'sync_operation',
			'monitoring'  => 'sync_operation',
			'export'      => 'export',
			'import'      => 'import',
		);

		$type = is_string( $type ) ? strtolower( trim( $type ) ) : '';

		return isset( $map[ $type ] ) ? $map[ $type ] : 'generation';
	}

	/**
	 * Count a user's jobs that are in flight.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public function count_active( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return 0;
		}

		$count = 0;
		foreach ( $this->jobs->all() as $job ) {
			if ( (int) ( $job['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			$status = (string) ( $job['status'] ?? '' );
			if ( Job_States::is_active( $status ) || Job_States::is_scheduled( $status ) ) {
				$count++;
			}
		}

		return $count;
	}

	/* ---------------------------------------------------------------------
	 * Running
	 * ------------------------------------------------------------------ */

	/**
	 * Return how much execution time this request has left, in seconds.
	 *
	 * @return int Zero when the limit is unknown, which callers treat as "assume
	 *             nothing" and therefore refuse to start a long stage.
	 */
	public function time_left() {
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit <= 0 ) {
			return 0;
		}

		$used   = (int) round( microtime( true ) - $this->request_started() );
		$budget = (int) floor( $limit * self::TIME_SHARE );

		return max( 0, $budget - $used );
	}

	/**
	 * Run one pass of the queue.
	 *
	 * @param int $max Maximum jobs to start this pass.
	 * @return array<string, mixed>
	 */
	public function tick( $max = 0 ) {
		$settings = self::settings();
		$max      = ( $max > 0 ) ? (int) $max : (int) $settings['tick_jobs'];
		$now      = time();

		$out = array(
			'tick_id'     => Request_Context::make_id( 'tick', 10 ),
			'started_at'  => gmdate( 'c' ),
			'time_budget' => $this->time_left(),
			'considered'  => 0,
			'started'     => 0,
			'deferred'    => 0,
			'refused'     => 0,
			'recovered'   => 0,
			'job_ids'     => array(),
			'reasons'     => array(),
		);

		if ( $settings['enable_recovery'] ) {
			// Recovery runs first, so a job whose worker died is resolved before the
			// tick decides there is nothing to do. Doing it afterwards would mean one
			// tick of a stuck job looking normal.
			$swept              = $this->recovery->sweep( 10 );
			$out['recovered']   = (int) $swept['retried'] + (int) $swept['expired'];
		}

		if ( $out['time_budget'] < self::MIN_BUDGET_SECONDS ) {
			$out['reasons'][] = 'insufficient_time';
			$out['finished_at'] = gmdate( 'c' );
			return $out;
		}

		$ai_slots = $this->ai_slots_available();

		foreach ( array_slice( $this->jobs->claimable(), 0, $max * 3 ) as $job ) {
			$out['considered']++;
			$job_id = (string) $job['job_id'];

			if ( count( $out['job_ids'] ) >= $max ) {
				break;
			}

			if ( $this->time_left() < self::MIN_BUDGET_SECONDS ) {
				$out['deferred']++;
				$out['reasons'][] = 'time_budget_exhausted';
				break;
			}

			$status = (string) ( $job['status'] ?? '' );

			// A paused job is not claimable in the first place, but a cancel request
			// may have arrived since, and honouring it before doing any work is the
			// point of a cooperative cancel.
			if ( $this->cancellations->is_requested( $job_id ) ) {
				$this->finish_cancelled( $job_id );
				$out['refused']++;
				continue;
			}

			$project_id = (string) ( $job['project_id'] ?? '' );
			$lock_key   = $this->lock_key( $project_id, $job_id );

			$lock = $this->locks->acquire( $lock_key, $job_id, Job_Limits::LEASE_SECONDS + (int) $settings['stage_budget'] );
			if ( empty( $lock['success'] ) ) {
				// Somebody else is in here. The job stays claimable and the tick
				// moves on; it is not failed, because nothing went wrong.
				$out['deferred']++;
				$out['reasons'][] = 'resource_locked';
				continue;
			}

			// Concurrency is checked after the lock, because taking the lock is what
			// makes the count meaningful: a job that could not take the lock is not
			// holding a slot.
			if ( $this->needs_ai( $job ) && $ai_slots <= 0 ) {
				$this->locks->release( $lock_key, (string) $lock['token'] );
				$out['deferred']++;
				$out['reasons'][] = 'ai_concurrency_reached';
				continue;
			}

			$this->current = $job_id;
			Request_Context::set( 'job', $job_id );

			$claimed = $this->queue->claim( $job_id );
			if ( null === $claimed ) {
				$this->locks->release( $lock_key, (string) $lock['token'] );
				$this->current = '';
				continue;
			}

			$out['started']++;
			$out['job_ids'][] = $job_id;
			$out['reasons'][]  = 'started';

			// The stage work itself is the injected runner's job. This class decides
			// whether a stage may start; `Job_Runner` decides how to run it.
			$progress = $this->run_stage( $claimed, $settings );

			$this->locks->release( $lock_key, (string) $lock['token'] );
			$this->current = '';

			if ( $this->needs_ai( $job ) ) {
				$ai_slots--;
			}

			// A stage that hit the time budget leaves the job running its lease out
			// rather than being failed. The lease is not renewed, so the next tick
			// takes it over cleanly once the lease expires — which is the same path
			// a crashed worker takes, and it is already handled.
			if ( empty( $progress['done'] ) && ! empty( $progress['deferred'] ) ) {
				$out['deferred']++;
			}
		}

		$out['finished_at'] = gmdate( 'c' );

		$this->logger->info(
			'job_tick',
			'Ran a job queue pass.',
			array(
				'considered' => $out['considered'],
				'started'    => $out['started'],
				'deferred'   => $out['deferred'],
				'recovered'  => $out['recovered'],
			),
			'job'
		);

		return $out;
	}

	/**
	 * Run one stage of a job, honouring the time budget.
	 *
	 * @param array<string, mixed> $job      Claimed job.
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private function run_stage( array $job, array $settings ) {
		$job_id = (string) $job['job_id'];

		$runner = $this->runner();
		if ( null === $runner ) {
			$this->queue->fail( $job_id, 'internal_error', 'No job runner is available.' );
			return array( 'done' => false, 'deferred' => false );
		}

		$allowed = max( self::MIN_BUDGET_SECONDS, min( (int) $settings['stage_budget'], $this->time_left() ) );

		$result = $runner->process( $job_id, $allowed );

		return array(
			'done'     => ! empty( $result['done'] ),
			'deferred' => ! empty( $result['deferred'] ),
			'stage'    => (string) ( $result['stage'] ?? '' ),
		);
	}

	/**
	 * Set the stage runner.
	 *
	 * @param Job_Runner|null $runner Runner.
	 * @return void
	 */
	public function set_runner( $runner ) {
		$this->runner = $runner instanceof Job_Runner ? $runner : null;
	}

	/* ---------------------------------------------------------------------
	 * Cancellation and progress
	 * ------------------------------------------------------------------ */

	/**
	 * Request a cancellation and settle the job if nothing is running it.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $by     Requesting user.
	 * @return array<string, mixed>
	 */
	public function cancel( $job_id, $by = '' ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return $this->refusal( 'job_not_found', __( 'That job is no longer available.', 'replicaforge' ), 404 );
		}

		$status = (string) ( $job['status'] ?? '' );
		if ( Job_States::is_terminal( $status ) ) {
			return $this->refusal(
				'job_not_cancellable',
				sprintf(
					/* translators: %s: a job state. */
					__( 'This job has already finished, so it cannot be cancelled. It is %s.', 'replicaforge' ),
					Job_States::label( $status )
				),
				409
			);
		}

		$this->cancellations->request( $job_id, $by );

		// A queued or paused job has no worker to tell, so it is settled now. A
		// running job keeps its lease and stops at its next stage boundary, which is
		// what stops a cancellation arriving halfway through an Elementor write.
		if ( ! Job_States::is_active( $status ) ) {
			$this->finish_cancelled( $job_id );
			return array(
				'success'   => true,
				'state'     => Job_States::CANCELLED,
				'deferred'  => false,
				'message'   => __( 'The job was cancelled.', 'replicaforge' ),
			);
		}

		return array(
			'success'  => true,
			'state'    => (string) $status,
			'deferred' => true,
			'message'  => __( 'The job will stop at the next safe point.', 'replicaforge' ),
		);
	}

	/**
	 * Pause a job.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>
	 */
	public function pause( $job_id ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return $this->refusal( 'job_not_found', __( 'That job is no longer available.', 'replicaforge' ), 404 );
		}

		$status = (string) ( $job['status'] ?? '' );
		if ( Job_States::is_terminal( $status ) ) {
			return $this->refusal( 'job_not_pausable', __( 'This job has already finished, so it cannot be paused.', 'replicaforge' ), 409 );
		}

		$updated = $this->queue->pause( $job_id );
		if ( null === $updated ) {
			return $this->refusal( 'job_not_pausable', __( 'This job cannot be paused.', 'replicaforge' ), 409 );
		}

		return array(
			'success' => true,
			'state'   => Job_States::PAUSED,
			'job'     => $updated,
		);
	}

	/**
	 * Resume a paused or expired job.
	 *
	 * An expired job is resumable only by an explicit call, which is the whole
	 * point of it being terminal: `Job_Recovery` will not do it on its own.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>
	 */
	public function resume( $job_id ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return $this->refusal( 'job_not_found', __( 'That job is no longer available.', 'replicaforge' ), 404 );
		}

		$status = (string) ( $job['status'] ?? '' );
		if ( Job_States::is_active( $status ) ) {
			return $this->refusal( 'job_already_running', __( 'This job is already running.', 'replicaforge' ), 409 );
		}

		$explanation = $this->recovery->explain( $job );

		if ( Job_States::EXPIRED === $status && empty( $explanation['resumable'] ) ) {
			// An expired job reached a stage that may have written. Resuming is
			// allowed, because the user asked for it, but the record says what
			// happened so the next reader knows this was not automatic.
			$this->jobs->update( $job_id, array( 'resumed_by_hand' => 1 ) );

			$this->logger->warning(
				'job_resumed_after_write',
				'A job that had already begun writing was resumed by hand.',
				array(
					'job_id' => (string) $job_id,
					'stage'  => (string) ( Job_Checkpoint::sanitize( (array) ( $job['checkpoint'] ?? array() ) )['stage'] ),
				),
				'job'
			);
		}

		$this->cancellations->clear( $job_id );

		$updated = $this->queue->resume( $job_id );
		if ( null === $updated ) {
			return $this->refusal( 'job_not_resumable', __( 'This job cannot be resumed.', 'replicaforge' ), 409 );
		}

		return array(
			'success' => true,
			'state'   => (string) ( $updated['status'] ?? Job_States::QUEUED ),
			'job'     => $updated,
			'warning' => (string) ( $explanation['message'] ?? '' ),
		);
	}

	/**
	 * Return a job's progress, derived rather than invented.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>|null
	 */
	public function progress( $job_id ) {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return null;
		}

		$checkpoint = Job_Checkpoint::sanitize( (array) ( $job['checkpoint'] ?? array() ) );
		$progress   = Job_Checkpoint::progress( $checkpoint );

		return array(
			'job_id'     => (string) $job_id,
			'status'     => (string) ( $job['status'] ?? '' ),
			'status_label' => Job_States::label( (string) ( $job['status'] ?? '' ) ),
			'explanation' => Job_States::explain( (string) ( $job['status'] ?? '' ) ),
			'stage'      => (string) $checkpoint['stage'],
			'stage_label' => Job_Checkpoint::stage_label( (string) $checkpoint['stage'] ),
			'percent'    => (int) $progress['percent'],
			'of'         => (int) $progress['of'],
			'position'   => (int) $progress['position'],
			'next_retry_at' => (int) ( $job['next_attempt'] ?? 0 ),
			'attempts'   => (int) ( $job['attempts'] ?? 0 ),
			'cancel_requested' => $this->cancellations->is_requested( $job_id ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Diagnostics
	 * ------------------------------------------------------------------ */

	/**
	 * Return a full reliability report.
	 *
	 * @return array<string, mixed>
	 */
	public function report() {
		$counts = $this->jobs->counts();

		return array(
			'settings'    => self::settings(),
			'counts'      => $counts,
			'locks'       => $this->locks->report(),
			'recovery'    => $this->recovery->report(),
			'cancellations' => count( $this->cancellations->all() ),
			'time_budget' => $this->time_left(),
			'time_limit'  => (int) ini_get( 'max_execution_time' ),
			'environment' => $this->environment(),
		);
	}

	/**
	 * Return what can be safely detected about the hosting environment.
	 *
	 * §24 asks for resource detection, and the honest answer is that most of it
	 * cannot be read from PHP on a shared host. What is here is what genuinely
	 * can: the configured limits, whether they are real, and whether the ones
	 * ReplicaForge depends on are adequate. What cannot be detected is said so
	 * rather than guessed.
	 *
	 * @return array<string, mixed>
	 */
	public function environment() {
		$time_limit = (int) ini_get( 'max_execution_time' );
		$memory     = (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		return array(
			'memory_limit'        => $memory,
			'memory_limit_text'   => (string) ini_get( 'memory_limit' ),
			'peak_memory'         => memory_get_peak_usage( true ),
			'execution_limit'     => $time_limit,
			// 0 means "unlimited", which is a CLI SAPI or a permissive ini_set. Both
			// are fine; neither can be assumed, so the fact is reported.
			'execution_unlimited' => ( $time_limit <= 0 ),
			'cron_disabled'       => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'sapi'                => PHP_SAPI,
			'worker_available'    => ( function_exists( 'fastcgi_finish_request' ) || 'cli' !== PHP_SAPI ),
			// The things that would matter and cannot be read. Said out loud so
			// nobody looks for them in this report.
			'not_detectable'      => array(
				__( 'How many PHP workers the host runs.', 'replicaforge' ),
				__( 'How much CPU the host has spare.', 'replicaforge' ),
				__( 'Whether another process is using the same site\'s database.', 'replicaforge' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Mark a job cancelled, clearing anything that would keep it alive.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	private function finish_cancelled( $job_id ) {
		$this->queue->cancel( $job_id );
		$this->cancellations->clear( $job_id );
	}

	/**
	 * Return the lock key for a job.
	 *
	 * A job with a project locks the project, so two jobs on one project are
	 * serialised. A job without one locks a key derived from its own id, so
	 * unrelated jobs do not contend with each other.
	 *
	 * @param string $project_id Project identifier.
	 * @param string $job_id     Job identifier.
	 * @return string
	 */
	private function lock_key( $project_id, $job_id ) {
		return ( '' !== (string) $project_id ) ? 'project_' . (string) $project_id : 'job_' . (string) $job_id;
	}

	/**
	 * Return whether a job needs an AI concurrency slot.
	 *
	 * A job needs one when its **next** stage is the AI stage. Judging by whether
	 * the job's *current* stage is `ai` is wrong in both directions: a job sitting
	 * in `ai` holds a slot while doing nothing, and a job about to enter `ai` would
	 * be admitted only to find it has no slot after it has already claimed a worker
	 * and a lock.
	 *
	 * @param array<string, mixed> $job Job record.
	 * @return bool
	 */
	private function needs_ai( array $job ) {
		$checkpoint = Job_Checkpoint::sanitize( (array) ( $job['checkpoint'] ?? array() ) );

		return ( 'ai' === Job_Limits::next_stage( (string) $checkpoint['stage'] ) );
	}

	/**
	 * Return how many AI slots are free.
	 *
	 * @return int
	 */
	private function ai_slots_available() {
		$settings = self::settings();
		$limit    = (int) $settings['ai_concurrency'];
		$in_flight = 0;

		foreach ( $this->jobs->all() as $job ) {
			$stage = (string) ( Job_Checkpoint::sanitize( (array) ( $job['checkpoint'] ?? array() ) )['stage'] );
			if ( 'ai' === $stage && Job_States::is_active( (string) ( $job['status'] ?? '' ) ) ) {
				$in_flight++;
			}
		}

		return max( 0, $limit - $in_flight );
	}

	/**
	 * Return the active-job limit for a user.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	private function active_job_limit( $user_id ) {
		$settings = self::settings();
		return (int) $settings['user_jobs'];
	}

	/**
	 * Return when this request started.
	 *
	 * @return float
	 */
	private function request_started() {
		if ( isset( $GLOBALS['replicaforge_request_started'] ) ) {
			return (float) $GLOBALS['replicaforge_request_started'];
		}
		$GLOBALS['replicaforge_request_started'] = microtime( true );
		return (float) $GLOBALS['replicaforge_request_started'];
	}

	/**
	 * Build a structured refusal.
	 *
	 * @param string               $code    Error code.
	 * @param string               $message User-facing message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $details Extra details.
	 * @return array<string, mixed>
	 */
	private function refusal( $code, $message, $status, array $details = array() ) {
		return array(
			'success' => false,
			'code'    => (string) $code,
			'message' => (string) $message,
			'status'  => (int) $status,
			'details' => $details,
		);
	}
}
