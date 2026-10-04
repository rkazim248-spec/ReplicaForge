<?php
/**
 * Phase 10: usage accounting.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Records what a user consumed, and refuses to let them consume more than they have.
 *
 * Two problems are solved here and they are not the same problem.
 *
 * **Accounting** is the §38 rule: a user is charged for work that succeeded, not for
 * a button they pressed. So usage is never incremented at the start of a request. An
 * operation calls {@see self::reserve()} before it does any work, and then exactly
 * one of {@see self::commit()} or {@see self::release()} afterwards. A reservation
 * that is never settled expires on its own, so a job whose worker died does not
 * consume the quota forever.
 *
 * **Concurrency** is the §39 rule: ten simultaneous requests must not be able to
 * start ten generations against a limit of two. That needs a mutual-exclusion
 * primitive, and WordPress offers exactly one that is atomic across concurrent PHP
 * processes: `add_option()`, which is a single INSERT against a unique column and
 * fails if the row exists. Every read-modify-write of a counter happens inside that
 * lock.
 *
 * Storage is user meta, period-scoped, because the plugin has no custom database
 * tables and introducing one to hold four integers per user per month would be a
 * migration and a query to solve a problem meta already solves. A period that falls
 * out of use leaves a small orphaned key, which is exactly what the existing
 * retention pass in {@see Maintenance} already prunes.
 */
final class Usage_Manager {

	/**
	 * Meta key prefix for committed counts.
	 */
	const META_PREFIX = 'replicaforge_usage_';

	/**
	 * Meta key prefix for open reservations.
	 */
	const RESERVATION_PREFIX = 'replicaforge_usage_reserved_';

	/**
	 * Meta key prefix for the recent record tail.
	 */
	const RECENT_PREFIX = 'replicaforge_usage_recent_';

	/**
	 * Option prefix for named locks.
	 */
	/**
	 * The option prefix for a usage-accounting lock.
	 *
	 * This used to be `replicaforge_lock_`, which is byte-identical to `Job_Lock::PREFIX`.
	 *
	 * The two never collided *in practice* - usage locks are keyed `usage_<user_id>` and job
	 * locks are keyed by a sanitised resource name, so the option names could not be equal -
	 * but they shared a namespace, and `Job_Lock::all()`, `report()` and `prune()` enumerate
	 * everything under that prefix. A usage lock was therefore reported as a job lock, and a
	 * `prune()` that expired stale job locks could delete a live usage lock, letting two
	 * concurrent requests both reserve quota and double-spend it.
	 *
	 * The distinct prefix costs nothing and makes the two lock families impossible to confuse.
	 *
	 * @var string
	 */
	const LOCK_PREFIX = 'replicaforge_usage_lock_';

	/**
	 * How long a lock may be held before another process may take it over.
	 *
	 * Long enough that no legitimate critical section is still running, short enough
	 * that a process killed mid-write does not block the user until tomorrow.
	 */
	const LOCK_TTL = 30;

	/**
	 * Metadata keys that may be stored on a usage record.
	 *
	 * §28 requires that the plugin not collect more than it needs, and §36 forbids
	 * logging API keys, credentials, source HTML, and AI prompts. An allowlist is
	 * the only way to *guarantee* that: a blocklist has to be right about everything
	 * that exists, and this is the place where arbitrary caller data arrives.
	 */
	const METADATA_KEYS = array( 'outcome', 'result', 'reason', 'source' );

	/**
	 * Maximum value for the `source` metadata field.
	 *
	 * The source is recorded as one of a declared set of phrases describing *where*
	 * the operation was started from, never as a URL. A URL would be redundant — it
	 * is already on the project — and it is one more copy of a user's browsing
	 * target to retain.
	 */
	const SOURCE_MAX = 24;

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

	/* ---------------------------------------------------------------------
	 * The operation lifecycle
	 * ------------------------------------------------------------------ */

	/**
	 * Hold usage for an operation that is about to run.
	 *
	 * The caller must settle every successful reservation with `commit()` or
	 * `release()`. An unsettled reservation counts against the limit until it
	 * expires, which is deliberate: an operation that ran for a long time should
	 * keep its claim on the quota for as long as it might still be running.
	 *
	 * @param int                  $user_id    User the usage belongs to.
	 * @param string               $operation  Operation name.
	 * @param int                  $quantity   How many units to hold.
	 * @param Plan_Definition|null $plan       Plan the operation is running under.
	 * @param string               $project_id Owning project, if any.
	 * @param array<string, mixed> $metadata   Allowed metadata only.
	 * @return array<string, mixed>
	 */
	public function reserve( $user_id, $operation, $quantity = 1, $plan = null, $project_id = '', array $metadata = array() ) {
		$user_id  = (int) $user_id;
		$quantity = max( 1, (int) $quantity );

		if ( $user_id < 1 || ! Plan_Limits::is_operation( $operation ) ) {
			return $this->refusal( 'usage_invalid_request', $user_id, $operation );
		}

		$token = Request_Context::make_id( 'use', 12 );

		return $this->with_lock(
			'usage_' . $user_id,
			function () use ( $user_id, $operation, $quantity, $plan, $project_id, $metadata, $token ) {
				$this->prune_reservations( $user_id );

				$open = $this->open_reservations( $user_id );
				if ( count( $open ) >= Plan_Limits::MAX_OPEN_RESERVATIONS ) {
					return $this->refusal( 'usage_too_many_open_operations', $user_id, $operation );
				}

				if ( $plan instanceof Plan_Definition ) {
					$limit_name = Plan_Limits::limit_for_operation( $operation );
					$limit      = ( '' === $limit_name ) ? Plan_Limits::UNLIMITED : $plan->limit( $limit_name );
					if ( ! Plan_Limits::is_unlimited( $limit ) ) {
						$committed = $this->used( $user_id, $operation );
						$held      = $this->reserved( $user_id, $operation );
						$total     = $committed + $held;
						if ( $total + $quantity > $limit ) {
							$this->logger->info(
								'usage_limit_reached',
								'Refused a reservation because the plan limit is already held.',
								array(
									'user_id'   => $user_id,
									'operation' => $operation,
									'limit'     => $limit,
									'used'      => $committed,
									'held'      => $held,
								),
								'plans'
							);
							return array(
								'success'    => false,
								'code'       => 'usage_limit_reached',
								'message'    => __( 'You have used the number of operations your plan allows this period.', 'replicaforge' ),
								'operation'  => $operation,
								'limit'      => $limit,
								// Three separate numbers, because collapsing them loses
								// the thing a user is actually asking. "Used" is what has
								// been charged. "Held" is work in flight that will be
								// charged unless it fails. "Total" is the figure that
								// fills the meter, and it is the one worth showing.
								'used'       => $committed,
								'held'       => $held,
								'total'      => $total,
								'remaining'  => max( 0, $limit - $total ),
								'period_key' => Plan_Limits::period_key(),
								'resets_at'  => Plan_Limits::period_ends(),
							);
						}
					}
				}

				$reservations          = $this->reservation_map( $user_id );
				$reservations[ $token ] = array(
					'operation'  => $operation,
					'quantity'   => $quantity,
					'project_id' => is_string( $project_id ) ? $project_id : '',
					'plan_id'    => ( $plan instanceof Plan_Definition ) ? $plan->id() : '',
					'created_at' => time(),
					'expires_at' => time() + Plan_Limits::RESERVATION_TTL,
					'metadata'   => $this->clean_metadata( $metadata ),
				);
				$this->store_reservations( $user_id, $reservations );

				return array(
					'success'     => true,
					'reservation' => $token,
					'operation'   => $operation,
					'quantity'    => $quantity,
					'period_key'  => Plan_Limits::period_key(),
				);
			}
		);
	}

	/**
	 * Settle a reservation as completed, charging the user for it.
	 *
	 * @param int                  $user_id  User the usage belongs to.
	 * @param string               $token    Reservation token.
	 * @param string               $plan_id  Plan the operation ran under.
	 * @param array<string, mixed> $metadata Allowed metadata only.
	 * @return array<string, mixed>
	 */
	public function commit( $user_id, $token, $plan_id = '', array $metadata = array() ) {
		$user_id = (int) $user_id;
		$token   = is_string( $token ) ? $token : '';

		if ( $user_id < 1 || '' === $token ) {
			return $this->refusal( 'usage_invalid_request', $user_id, '' );
		}

		return $this->with_lock(
			'usage_' . $user_id,
			function () use ( $user_id, $token, $plan_id, $metadata ) {
				$this->prune_reservations( $user_id );
				$reservations = $this->reservation_map( $user_id );

				if ( ! isset( $reservations[ $token ] ) ) {
					// The reservation already expired and was pruned. Charging again
					// would double-count, and refusing would under-count work that
					// genuinely completed. Neither is acceptable, so this is reported
					// and nothing is charged: the safe direction for a *user's* quota
					// is to under-charge, because over-charging a user for work the
					// plugin lost track of is a support problem the user cannot fix.
					$this->logger->warning(
						'usage_commit_without_reservation',
						'A completed operation had no open reservation to settle.',
						array( 'user_id' => $user_id ),
						'plans'
					);
					return array(
						'success'  => false,
						'code'     => 'usage_reservation_not_found',
						'message'  => __( 'That operation was no longer being tracked, so no usage was charged.', 'replicaforge' ),
					);
				}

				$reservation = $reservations[ $token ];
				unset( $reservations[ $token ] );
				$this->store_reservations( $user_id, $reservations );

				$operation = (string) $reservation['operation'];
				$quantity  = max( 1, (int) $reservation['quantity'] );
				$period    = Plan_Limits::period_key();

				$counts         = $this->count_map( $user_id, $period );
				$before         = isset( $counts[ $operation ] ) ? (int) $counts[ $operation ] : 0;
				$counts[ $operation ] = $before + $quantity;
				$this->store_counts( $user_id, $period, $counts );

				$merged              = array_merge(
					$this->clean_metadata( (array) $reservation['metadata'] ),
					$this->clean_metadata( $metadata )
				);
				$merged['outcome']   = Plan_Limits::OUTCOMES[0];

				$this->append_record(
					$user_id,
					array(
						'usage_id'   => Request_Context::make_id( 'usage', 10 ),
						'user_id'    => $user_id,
						'project_id' => (string) $reservation['project_id'],
						'operation'  => $operation,
						'quantity'   => $quantity,
						'plan_id'    => is_string( $plan_id ) && '' !== $plan_id ? $plan_id : (string) $reservation['plan_id'],
						'timestamp'  => time(),
						'metadata'   => $merged,
						'period_key' => $period,
					)
				);

				/**
				 * Fires after usage is charged.
				 *
				 * @param int    $user_id   User charged.
				 * @param string $operation Operation charged.
				 * @param int    $quantity  Units charged.
				 * @param string $plan_id   Plan charged under.
				 */
				do_action( 'replicaforge_usage_recorded', $user_id, $operation, $quantity, (string) $reservation['plan_id'] );

				return array(
					'success'   => true,
					'operation' => $operation,
					'quantity'  => $quantity,
					'used'      => $counts[ $operation ],
					'period_key' => $period,
				);
			}
		);
	}

	/**
	 * Settle a reservation without charging, because the operation did not do the work.
	 *
	 * The refusal is still recorded in the tail. A user looking at their history
	 * should be able to see that an attempt was made and why it did not count —
	 * "3 / 10 used" with a silent fourth attempt is indistinguishable from a bug.
	 *
	 * @param int    $user_id User the usage belongs to.
	 * @param string $token   Reservation token.
	 * @param string $reason  Short machine reason.
	 * @return array<string, mixed>
	 */
	public function release( $user_id, $token, $reason = 'failed' ) {
		$user_id = (int) $user_id;
		$token   = is_string( $token ) ? $token : '';

		if ( $user_id < 1 || '' === $token ) {
			return $this->refusal( 'usage_invalid_request', $user_id, '' );
		}

		return $this->with_lock(
			'usage_' . $user_id,
			function () use ( $user_id, $token, $reason ) {
				$this->prune_reservations( $user_id );
				$reservations = $this->reservation_map( $user_id );

				if ( ! isset( $reservations[ $token ] ) ) {
					return array(
						'success' => true,
						'code'    => 'usage_reservation_not_found',
						'note'    => 'already_settled',
					);
				}

				$reservation = $reservations[ $token ];
				unset( $reservations[ $token ] );
				$this->store_reservations( $user_id, $reservations );

				$period = Plan_Limits::period_key();
				$this->append_record(
					$user_id,
					array(
						'usage_id'   => Request_Context::make_id( 'usage', 10 ),
						'user_id'    => $user_id,
						'project_id' => (string) $reservation['project_id'],
						'operation'  => (string) $reservation['operation'],
						'quantity'   => 0,
						'plan_id'    => (string) $reservation['plan_id'],
						'timestamp'  => time(),
						'metadata'   => array(
							'outcome' => Plan_Limits::OUTCOMES[1],
							'reason'  => $this->clean_token( $reason ),
						),
						'period_key' => $period,
					)
				);

				return array(
					'success'   => true,
					'operation' => (string) $reservation['operation'],
					'charged'   => 0,
				);
			}
		);
	}

	/**
	 * Charge an operation that has already succeeded and had no reservation.
	 *
	 * Legitimate for operations that cannot fail after the point of no return — a
	 * committed database write, for instance. It is **not** a shortcut for charging
	 * on request arrival: there is no check here that the work happened, because
	 * there is nothing left to check.
	 *
	 * @param int                  $user_id    User the usage belongs to.
	 * @param string               $operation  Operation name.
	 * @param int                  $quantity   How many units.
	 * @param Plan_Definition|null $plan       Plan the operation ran under.
	 * @param string               $project_id Owning project, if any.
	 * @param array<string, mixed> $metadata   Allowed metadata only.
	 * @return array<string, mixed>
	 */
	public function charge( $user_id, $operation, $quantity = 1, $plan = null, $project_id = '', array $metadata = array() ) {
		$user_id  = (int) $user_id;
		$quantity = max( 1, (int) $quantity );

		if ( $user_id < 1 || ! Plan_Limits::is_operation( $operation ) ) {
			return $this->refusal( 'usage_invalid_request', $user_id, $operation );
		}

		return $this->with_lock(
			'usage_' . $user_id,
			function () use ( $user_id, $operation, $quantity, $plan, $project_id, $metadata ) {
				$period = Plan_Limits::period_key();
				$counts = $this->count_map( $user_id, $period );

				$before                = isset( $counts[ $operation ] ) ? (int) $counts[ $operation ] : 0;
				$counts[ $operation ]  = $before + $quantity;
				$this->store_counts( $user_id, $period, $counts );

				$clean          = $this->clean_metadata( $metadata );
				$clean['outcome'] = Plan_Limits::OUTCOMES[0];

				$this->append_record(
					$user_id,
					array(
						'usage_id'   => Request_Context::make_id( 'usage', 10 ),
						'user_id'    => $user_id,
						'project_id' => is_string( $project_id ) ? $project_id : '',
						'operation'  => $operation,
						'quantity'   => $quantity,
						'plan_id'    => ( $plan instanceof Plan_Definition ) ? $plan->id() : '',
						'timestamp'  => time(),
						'metadata'   => $clean,
						'period_key' => $period,
					)
				);

				$plan_id = ( $plan instanceof Plan_Definition ) ? $plan->id() : '';
				do_action( 'replicaforge_usage_recorded', $user_id, $operation, $quantity, $plan_id );

				return array(
					'success'    => true,
					'operation'  => $operation,
					'quantity'   => $quantity,
					'used'       => $counts[ $operation ],
					'period_key' => $period,
				);
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Reading usage
	 * ------------------------------------------------------------------ */

	/**
	 * Return the committed count for an operation in a period.
	 *
	 * @param int    $user_id   User id.
	 * @param string $operation Operation name.
	 * @param string $period    Optional period key.
	 * @return int
	 */
	public function used( $user_id, $operation, $period = '' ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! Plan_Limits::is_operation( $operation ) ) {
			return 0;
		}
		$period = ( '' === $period || ! is_string( $period ) ) ? Plan_Limits::period_key() : $period;
		$counts = $this->count_map( $user_id, $period );
		return isset( $counts[ $operation ] ) ? max( 0, (int) $counts[ $operation ] ) : 0;
	}

	/**
	 * Return the count held by open reservations for an operation.
	 *
	 * @param int    $user_id   User id.
	 * @param string $operation Operation name.
	 * @return int
	 */
	public function reserved( $user_id, $operation ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! Plan_Limits::is_operation( $operation ) ) {
			return 0;
		}
		$total = 0;
		foreach ( $this->open_reservations( $user_id ) as $reservation ) {
			if ( (string) $reservation['operation'] === $operation ) {
				$total += max( 1, (int) $reservation['quantity'] );
			}
		}
		return $total;
	}

	/**
	 * Return the total a limit check must compare against.
	 *
	 * Committed plus reserved. Checking only the committed count is the §39 bug: ten
	 * simultaneous requests all read zero, all pass, and all execute.
	 *
	 * @param int                  $user_id   User id.
	 * @param string               $operation Operation name.
	 * @param string               $limit     Limit name.
	 * @param Plan_Definition|null $plan      Plan definition.
	 * @return int
	 */
	public function effective_count( $user_id, $operation, $limit = '', $plan = null ) {
		$total = $this->used( $user_id, $operation );
		$total += $this->reserved( $user_id, $operation );
		return max( 0, $total );
	}

	/**
	 * Return whether a limit check currently passes.
	 *
	 * @param int                  $user_id   User id.
	 * @param string               $operation Operation name.
	 * @param Plan_Definition|null $plan      Plan definition.
	 * @param int                  $quantity  Units about to be consumed.
	 * @return array<string, mixed>
	 */
	public function check( $user_id, $operation, $plan, $quantity = 1 ) {
		$user_id  = (int) $user_id;
		$quantity = max( 1, (int) $quantity );

		if ( $user_id < 1 || ! Plan_Limits::is_operation( $operation ) ) {
			return $this->refusal( 'usage_invalid_request', $user_id, $operation );
		}

		$limit_name = Plan_Limits::limit_for_operation( $operation );
		if ( '' === $limit_name || null === $plan ) {
			return array(
				'success'  => true,
				'unlimited' => true,
			);
		}

		$limit = $plan->limit( $limit_name );
		if ( Plan_Limits::is_unlimited( $limit ) ) {
			return array(
				'success'   => true,
				'unlimited' => true,
			);
		}

		$used      = $this->used( $user_id, $operation );
		$held      = $this->reserved( $user_id, $operation );
		$available = max( 0, $limit - $used - $held );

		return array(
			'success'   => ( $available >= $quantity ),
			'unlimited' => false,
			'code'      => ( $available >= $quantity ) ? '' : 'usage_limit_reached',
			'operation' => $operation,
			'limit'     => $limit,
			'used'      => $used,
			'held'      => $held,
			'available' => $available,
			'period_key' => Plan_Limits::period_key(),
			'resets_at' => Plan_Limits::period_ends(),
		);
	}

	/**
	 * Return every metered operation's usage for a user under a plan.
	 *
	 * This is what the usage meter screen renders, and what the REST route returns.
	 * The percent is computed here rather than in the screen so that the bar and
	 * the "3 / 10" text cannot disagree.
	 *
	 * @param int                  $user_id User id.
	 * @param Plan_Definition|null $plan    Plan definition.
	 * @param string               $period  Optional period key.
	 * @return array<string, mixed>
	 */
	public function meter( $user_id, $plan, $period = '' ) {
		$user_id = (int) $user_id;
		$period  = ( '' === $period || ! is_string( $period ) ) ? Plan_Limits::period_key() : $period;

		$rows = array();
		foreach ( Plan_Limits::OPERATIONS as $operation ) {
			$limit_name = Plan_Limits::limit_for_operation( $operation );
			$used       = $this->used( $user_id, $operation, $period );
			$held       = $this->reserved( $user_id, $operation );
			$unlimited  = ( null === $plan || '' === $limit_name ) || Plan_Limits::is_unlimited( $plan->limit( $limit_name ) );
			$limit      = $unlimited ? Plan_Limits::UNLIMITED : (int) $plan->limit( $limit_name );

			$rows[ $operation ] = array(
				'operation'    => $operation,
				'label'        => $this->operation_label( $operation ),
				'feature'      => Plan_Limits::feature_for_operation( $operation ),
				'available'    => ( null !== $plan ) ? $plan->permits( $operation ) : false,
				'used'         => $used,
				'held'         => $held,
				'limit'        => $limit,
				'unlimited'    => $unlimited,
				'remaining'    => $unlimited ? null : max( 0, $limit - $used - $held ),
				'percent_used' => ( $unlimited || $limit < 1 ) ? 0 : (int) min( 100, (int) round( ( ( $used + $held ) / $limit ) * 100 ) ),
			);
		}

		return array(
			'user_id'     => $user_id,
			'period_key'  => $period,
			'resets_at'   => Plan_Limits::period_ends(),
			'plan_id'     => ( $plan instanceof Plan_Definition ) ? $plan->id() : '',
			'operations'  => $rows,
			'open_holds'  => count( $this->open_reservations( $user_id ) ),
		);
	}

	/**
	 * Return the recent usage tail.
	 *
	 * @param int    $user_id User id.
	 * @param int    $limit   Maximum records.
	 * @param string $period  Optional period key.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( $user_id, $limit = 25, $period = '' ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return array();
		}
		$period = ( '' === $period || ! is_string( $period ) ) ? Plan_Limits::period_key() : $period;
		$stored = get_user_meta( $user_id, self::RECENT_PREFIX . $period, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$limit = max( 1, min( 100, (int) $limit ) );

		// Stored oldest-first so a trim can drop from the front, which is what
		// "keep the most recent" means. Returned newest-first, which is what a
		// history list means.
		return array_slice( array_reverse( $stored ), 0, $limit );
	}

	/**
	 * Return every open reservation for a user.
	 *
	 * @param int $user_id User id.
	 * @return array<int, array<string, mixed>>
	 */
	public function open_reservations( $user_id ) {
		$map = $this->reservation_map( (int) $user_id );
		return array_values( $map );
	}

	/**
	 * Return whether the user has any unsettled reservation.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function has_open_reservation( $user_id ) {
		return array() !== $this->reservation_map( (int) $user_id );
	}

	/**
	 * Expire reservations whose window has closed, for every user with usage.
	 *
	 * Called by the maintenance pass so that a reservation abandoned by a dead
	 * process does not need the user to come back and try again before it clears.
	 * Not a full table scan: the candidate list is bounded and each candidate is a
	 * single meta read.
	 *
	 * @param int $max_users Maximum users to sweep.
	 * @return int Number of reservations expired.
	 */
	public function sweep( $max_users = 50 ) {
		$user_ids = get_users(
			array(
				'meta_key'   => self::META_PREFIX . Plan_Limits::period_key(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'fields'     => 'ID',
				'number'     => max( 1, (int) $max_users ),
				'orderby'    => 'ID',
				'order'      => 'ASC',
			)
		);
		$swept = 0;
		foreach ( (array) $user_ids as $user_id ) {
			$before = count( $this->reservation_map( (int) $user_id ) );
			$this->prune_reservations( (int) $user_id );
			$swept += $before - count( $this->reservation_map( (int) $user_id ) );
		}
		return $swept;
	}

	/**
	 * Remove every usage record for a user.
	 *
	 * Used when a project set is deleted, and exposed for the uninstall path. It
	 * charges nothing and records nothing: deleting usage is not an operation.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function forget( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return false;
		}
		$this->prune_reservations( $user_id );

		global $wpdb;
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND ( meta_key LIKE %s OR meta_key LIKE %s )",
				$user_id,
				$wpdb->esc_like( self::META_PREFIX ) . '%',
				$wpdb->esc_like( self::RECENT_PREFIX ) . '%'
			)
		);
		foreach ( (array) $keys as $key ) {
			delete_user_meta( $user_id, (string) $key );
		}
		// Reservation keys are removed by deleting the exact key for the current
		// period; a sweep of the LIKE pattern is avoided because reservations are
		// only ever written for the current period.
		delete_user_meta( $user_id, self::RESERVATION_PREFIX . Plan_Limits::period_key() );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Mutual exclusion
	 * ------------------------------------------------------------------ */

	/**
	 * Run a callback while holding a named lock.
	 *
	 * `add_option()` is the primitive because it is a single INSERT and the option
	 * name column is unique, so exactly one concurrent process can create the row.
	 * `update_option()` would not do: it is a read followed by a write, and two
	 * processes doing that at once means one of the writes is lost.
	 *
	 * If the lock cannot be taken, the callback is not run and the caller receives a
	 * refusal. Running the critical section unlocked would defeat the purpose, and
	 * waiting would hold a PHP worker for a request-length time on the assumption
	 * the holder is still alive.
	 *
	 * @param string   $name     Lock name.
	 * @param callable $callback Critical section.
	 * @return mixed
	 */
	public function with_lock( $name, callable $callback ) {
		$option = self::LOCK_PREFIX . preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $name ) );
		$now    = time();
		$held   = array(
			'token'      => Request_Context::make_id( 'lock', 8 ),
			'expires_at' => $now + self::LOCK_TTL,
		);

		if ( ! add_option( $option, $held, '', 'no' ) ) {
			// Someone holds it. If their window has closed, their process is gone,
			// so the lock is taken over rather than left blocking the user. Two
			// processes can reach this line together, and the loser will simply fail
			// the re-acquire below — which is the correct outcome, because a
			// contended lock means the decision is being made right now.
			$existing = get_option( $option, array() );
			$expired  = ! is_array( $existing ) || (int) $existing['expires_at'] < $now;
			if ( ! $expired ) {
				return array(
					'success' => false,
					'code'    => 'usage_lock_held',
					'message' => __( 'Another ReplicaForge operation is still finishing. Try again in a moment.', 'replicaforge' ),
				);
			}
			delete_option( $option );
			if ( ! add_option( $option, $held, '', 'no' ) ) {
				return array(
					'success' => false,
					'code'    => 'usage_lock_held',
					'message' => __( 'Another ReplicaForge operation is still finishing. Try again in a moment.', 'replicaforge' ),
				);
			}
		}

		try {
			$result = call_user_func( $callback );
		} catch ( \Throwable $error ) {
			delete_option( $option );
			$this->logger->error(
				'usage_critical_section_failed',
				'A usage critical section raised an error.',
				array( 'lock' => $name ),
				'plans'
			);
			throw $error;
		}

		delete_option( $option );

		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Return a stable refusal.
	 *
	 * @param string $code      Error code.
	 * @param int    $user_id   User id.
	 * @param string $operation Operation name.
	 * @return array<string, mixed>
	 */
	private function refusal( $code, $user_id, $operation ) {
		return array(
			'success'   => false,
			'code'      => $code,
			'message'   => __( 'That usage request could not be recorded.', 'replicaforge' ),
			'operation' => (string) $operation,
			'user_id'   => (int) $user_id,
		);
	}

	/**
	 * Return the committed count map for a period.
	 *
	 * @param int    $user_id User id.
	 * @param string $period  Period key.
	 * @return array<string, int>
	 */
	private function count_map( $user_id, $period ) {
		$stored = get_user_meta( (int) $user_id, self::META_PREFIX . $period, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $operation => $count ) {
			if ( Plan_Limits::is_operation( $operation ) && is_numeric( $count ) ) {
				$out[ $operation ] = max( 0, (int) $count );
			}
		}
		return $out;
	}

	/**
	 * Store the committed count map for a period.
	 *
	 * @param int                $user_id User id.
	 * @param string             $period  Period key.
	 * @param array<string, int> $counts  Counts.
	 * @return void
	 */
	private function store_counts( $user_id, $period, array $counts ) {
		update_user_meta( (int) $user_id, self::META_PREFIX . $period, $counts );
	}

	/**
	 * Return the open reservation map.
	 *
	 * @param int $user_id User id.
	 * @return array<string, array<string, mixed>>
	 */
	private function reservation_map( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return array();
		}
		$stored = get_user_meta( $user_id, self::RESERVATION_PREFIX . Plan_Limits::period_key(), true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $token => $reservation ) {
			if ( ! is_array( $reservation ) || ! Plan_Limits::is_operation( $reservation['operation'] ?? '' ) ) {
				continue;
			}
			$out[ (string) $token ] = $reservation;
		}
		return $out;
	}

	/**
	 * Store the open reservation map.
	 *
	 * @param int                              $user_id      User id.
	 * @param array<string, array<string, mixed>> $reservations Reservations.
	 * @return void
	 */
	private function store_reservations( $user_id, array $reservations ) {
		$user_id = (int) $user_id;
		if ( array() === $reservations ) {
			delete_user_meta( $user_id, self::RESERVATION_PREFIX . Plan_Limits::period_key() );
			return;
		}
		update_user_meta( $user_id, self::RESERVATION_PREFIX . Plan_Limits::period_key(), $reservations );
	}

	/**
	 * Drop reservations whose window has closed.
	 *
	 * An expired reservation is recorded in the tail as `expired` rather than
	 * silently deleted, for the same reason a release is recorded: a user seeing
	 * "1 / 2 used" with no explanation of the second attempt cannot tell a working
	 * meter from a broken one.
	 *
	 * @param int $user_id User id.
	 * @return int Number dropped.
	 */
	private function prune_reservations( $user_id ) {
		$user_id      = (int) $user_id;
		$reservations = $this->reservation_map( $user_id );
		if ( array() === $reservations ) {
			return 0;
		}

		$now   = time();
		$kept  = array();
		$stale = array();

		foreach ( $reservations as $token => $reservation ) {
			$expires = isset( $reservation['expires_at'] ) ? (int) $reservation['expires_at'] : 0;
			if ( $expires < $now ) {
				$stale[] = $reservation;
			} else {
				$kept[ $token ] = $reservation;
			}
		}

		if ( array() === $stale ) {
			return 0;
		}

		$this->store_reservations( $user_id, $kept );

		foreach ( $stale as $reservation ) {
			$this->append_record(
				$user_id,
				array(
					'usage_id'   => Request_Context::make_id( 'usage', 10 ),
					'user_id'    => $user_id,
					'project_id' => (string) ( $reservation['project_id'] ?? '' ),
					'operation'  => (string) $reservation['operation'],
					'quantity'   => 0,
					'plan_id'    => (string) ( $reservation['plan_id'] ?? '' ),
					'timestamp'  => $now,
					'metadata'   => array(
						'outcome' => Plan_Limits::OUTCOMES[2],
						'reason'  => 'reservation_expired',
					),
					'period_key' => Plan_Limits::period_key(),
				)
			);

			$this->logger->warning(
				'usage_reservation_expired',
				'An unsettled usage reservation expired without being charged.',
				array(
					'user_id'   => $user_id,
					'operation' => (string) $reservation['operation'],
				),
				'plans'
			);
		}

		return count( $stale );
	}

	/**
	 * Append a record to the bounded tail.
	 *
	 * @param int                  $user_id User id.
	 * @param array<string, mixed> $record  Record.
	 * @return void
	 */
	private function append_record( $user_id, array $record ) {
		$user_id = (int) $user_id;
		$period  = (string) $record['period_key'];
		$key     = self::RECENT_PREFIX . $period;

		$stored = get_user_meta( $user_id, $key, true );
		$stored = is_array( $stored ) ? array_values( $stored ) : array();

		$stored[] = $record;

		$cap = Plan_Limits::MAX_RECORDS_PER_PERIOD;
		if ( count( $stored ) > $cap ) {
			$stored = array_slice( $stored, -$cap );
		}

		update_user_meta( $user_id, $key, $stored );
	}

	/**
	 * Keep only allowlisted metadata, with scalar, length-bounded values.
	 *
	 * @param array<string, mixed> $metadata Incoming metadata.
	 * @return array<string, string>
	 */
	private function clean_metadata( array $metadata ) {
		$out = array();
		foreach ( self::METADATA_KEYS as $key ) {
			if ( ! array_key_exists( $key, $metadata ) ) {
				continue;
			}
			$value = $metadata[ $key ];
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = (string) $value;
			if ( 'source' === $key ) {
				$value = $this->clean_token( $value );
			} else {
				$value = substr( sanitize_text_field( $value ), 0, 80 );
			}
			if ( '' === $value ) {
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Reduce a value to a short machine token.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_token( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_]/', '', $value );
		return substr( (string) $value, 0, self::SOURCE_MAX );
	}

	/**
	 * Return a translated operation label.
	 *
	 * Declared in one place so the meter, the REST response, and the limit message
	 * all name an operation the same way.
	 *
	 * @param string $operation Operation name.
	 * @return string
	 */
	private function operation_label( $operation ) {
		$labels = array(
			'analysis'        => __( 'Website analyses', 'replicaforge' ),
			'ai_analysis'     => __( 'AI analyses', 'replicaforge' ),
			'generation'      => __( 'Elementor generations', 'replicaforge' ),
			'validation'      => __( 'Validation runs', 'replicaforge' ),
			'correction'      => __( 'Correction batches', 'replicaforge' ),
			'sync_operation'  => __( 'Sync operations', 'replicaforge' ),
			'export'          => __( 'Exports', 'replicaforge' ),
			'import'          => __( 'Imports', 'replicaforge' ),
		);
		return isset( $labels[ $operation ] ) ? $labels[ $operation ] : (string) $operation;
	}
}
