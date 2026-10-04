<?php
/**
 * Phase 10: the entitlement gate.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that answers "may this user do this, and do they have any left".
 *
 * §6 asks for a centralized entitlement service and §9 lists the order the checks
 * run in. This class is that service, and the order is fixed here rather than
 * chosen per call site, because the order is a security property:
 *
 * 1. **Authentication.** No user, no operation.
 * 2. **Capability.** May the user use ReplicaForge at all, and may they perform
 *    this operation.
 * 3. **Ownership.** Does the named project belong to them.
 * 4. **Feature entitlement.** Does the plan grant the feature that gates this
 *    operation.
 * 5. **Usage limit.** Is there room in the quota, counting what is already held.
 *
 * Three of those five are answers about *who*, and two are about *what they have*.
 * The order matters in two specific ways. Ownership comes before entitlement so
 * that a user cannot learn anything about a plan by asking about somebody else's
 * project. Entitlement comes before the limit so that a user on a plan without the
 * feature is told the feature is unavailable, rather than being told to upgrade
 * when no upgrade would help — an operation that is not on any plan is not a
 * metered one.
 *
 * What this class deliberately does **not** do is check project state, Elementor
 * availability, or request validity. Those need services this class does not own,
 * they are not commercial concerns, and folding them in would make this the place
 * where every future feature has to be remembered. The REST layer runs them after
 * this returns an approval, in the same order.
 *
 * {@see self::begin()} and {@see self::settle()} exist so that the whole §38
 * lifecycle — request, validation, authorization, entitlement, execution, success,
 * commit — is the shape of the API rather than a convention each caller has to
 * remember. A caller that calls `begin()` and forgets `settle()` still cannot
 * over-charge a user, because the reservation expires.
 */
final class Entitlement_Manager {

	/**
	 * Plan manager.
	 *
	 * @var Plan_Manager
	 */
	private $plans;

	/**
	 * Usage manager.
	 *
	 * @var Usage_Manager
	 */
	private $usage;

	/**
	 * Project access.
	 *
	 * @var Project_Access
	 */
	private $access;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Plan_Manager|null   $plans  Optional plan manager.
	 * @param Usage_Manager|null  $usage  Optional usage manager.
	 * @param Project_Access|null $access Optional project access.
	 * @param Logger|null         $logger Optional logger.
	 */
	public function __construct( $plans = null, $usage = null, $access = null, $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
		$this->plans  = $plans instanceof Plan_Manager ? $plans : new Plan_Manager( null, $this->logger );
		$this->usage  = $usage instanceof Usage_Manager ? $usage : new Usage_Manager( $this->logger );
		$this->access = $access instanceof Project_Access ? $access : new Project_Access();
	}

	/**
	 * Return the plan manager.
	 *
	 * @return Plan_Manager
	 */
	public function plans() {
		return $this->plans;
	}

	/**
	 * Return the usage manager.
	 *
	 * @return Usage_Manager
	 */
	public function usage() {
		return $this->usage;
	}

	/**
	 * Return the project access service.
	 *
	 * @return Project_Access
	 */
	public function access() {
		return $this->access;
	}

	/* ---------------------------------------------------------------------
	 * Feature entitlement
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether a user's plan grants a feature.
	 *
	 * This is a display question and answers only about the plan. It is
	 * {@see self::check()} that answers whether an operation may proceed, because
	 * "the plan has the feature" and "the user may use it right now" are different
	 * claims.
	 *
	 * @param string $feature Feature name.
	 * @param int    $user_id User id.
	 * @return bool
	 */
	public function plan_grants( $feature, $user_id ) {
		if ( ! Plan_Limits::is_feature( $feature ) ) {
			return false;
		}

		$plan = $this->plans->current_plan( (int) $user_id );

		/**
		 * Filters whether a plan grants a feature.
		 *
		 * Returning a boolean replaces the answer; returning anything else leaves
		 * the plan's own answer in place. This is the hook an installation uses to
		 * grant or withhold a feature by its own rules without editing a plan.
		 *
		 * @param bool             $granted Whether the plan grants the feature.
		 * @param string           $feature The feature name.
		 * @param Plan_Definition  $plan    The user's plan.
		 * @param int              $user_id The user the question is about.
		 */
		$filtered = apply_filters( 'replicaforge_feature_entitlement', $plan->allows( $feature ), $feature, $plan, (int) $user_id );

		return is_bool( $filtered ) ? $filtered : (bool) $plan->allows( $feature );
	}

	/* ---------------------------------------------------------------------
	 * Operation checks
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether an operation may proceed, without consuming anything.
	 *
	 * Use this for a screen that needs to know whether to offer an action. Use
	 * {@see self::begin()} for an operation that is actually going to run, because
	 * this method's answer can go stale between the check and the execution — which
	 * is exactly the race §39 is about.
	 *
	 * @param string               $operation Operation name.
	 * @param int                  $user_id   User id.
	 * @param array<string, mixed> $context   Optional project_id and quantity.
	 * @return array<string, mixed>
	 */
	public function check( $operation, $user_id, array $context = array() ) {
		$user_id    = (int) $user_id;
		$quantity   = isset( $context['quantity'] ) ? max( 1, (int) $context['quantity'] ) : 1;
		$project_id = isset( $context['project_id'] ) && is_scalar( $context['project_id'] ) ? (string) $context['project_id'] : '';

		if ( $user_id < 1 ) {
			return $this->deny( 'authentication_required', __( 'Sign in to use ReplicaForge.', 'replicaforge' ), 401 );
		}

		if ( ! Plan_Limits::is_operation( $operation ) ) {
			// An unrecognised operation is refused rather than allowed. A typo in a
			// call site must fail closed, or a new endpoint would be usable by
			// default until somebody noticed it had no gate.
			return $this->deny(
				'unknown_operation',
				__( 'That operation is not available.', 'replicaforge' ),
				400
			);
		}

		$capability = Capabilities::for_operation( $operation );
		if ( ! Capabilities::user_can( $user_id, $capability ) ) {
			$this->log_denial( $user_id, $operation, 'capability_missing' );
			return $this->deny(
				'capability_missing',
				__( 'Your account is not allowed to run that operation.', 'replicaforge' ),
				403,
				array( 'capability' => $capability )
			);
		}

		if ( '' !== $project_id ) {
			$access = $this->access->refusal( $user_id, $project_id );
			if ( empty( $access['allowed'] ) ) {
				$this->log_denial( $user_id, $operation, 'ownership_denied' );
				return $this->deny(
					(string) $access['code'],
					(string) $access['message'],
					(int) $access['status'],
					array( 'project_id' => $project_id )
				);
			}
		}

		$plan = $this->plans->current_plan( $user_id );
		if ( ! $plan->permits( $operation ) ) {
			return $this->deny_feature( $user_id, $operation, $plan );
		}

		$usage = $this->usage->check( $user_id, $operation, $plan, $quantity );
		if ( empty( $usage['success'] ) ) {
			$this->log_denial( $user_id, $operation, 'usage_limit_reached' );
			return $this->deny(
				'usage_limit_reached',
				$this->limit_message( $operation, $plan, $usage ),
				402,
				array(
					'operation'  => $operation,
					'limit'      => isset( $usage['limit'] ) ? (int) $usage['limit'] : 0,
					'used'       => isset( $usage['used'] ) ? (int) $usage['used'] : 0,
					'held'       => isset( $usage['held'] ) ? (int) $usage['held'] : 0,
					'total'      => isset( $usage['available'] ) ? (int) $usage['used'] + (int) $usage['held'] : 0,
					'remaining'  => isset( $usage['available'] ) ? (int) $usage['available'] : 0,
					'period_key' => Plan_Limits::period_key(),
					'resets_at'  => Plan_Limits::period_ends(),
				)
			);
		}

		return array(
			'allowed'   => true,
			'code'      => '',
			'message'   => '',
			'status'    => 200,
			'details'   => array(),
			'plan_id'   => $plan->id(),
			'unlimited' => ! empty( $usage['unlimited'] ),
		);
	}

	/**
	 * Check and hold usage for an operation that is about to run.
	 *
	 * The returned `reservation` must be settled with {@see self::settle()} or
	 * {@see self::fail()}. If it is not, the reservation expires and nothing is
	 * charged.
	 *
	 * @param string               $operation Operation name.
	 * @param int                  $user_id   User id.
	 * @param array<string, mixed> $context   Optional project_id, quantity, metadata.
	 * @return array<string, mixed>
	 */
	public function begin( $operation, $user_id, array $context = array() ) {
		$user_id = (int) $user_id;

		$check = $this->check( $operation, $user_id, $context );
		if ( empty( $check['allowed'] ) ) {
			return $check;
		}

		$plan       = $this->plans->current_plan( $user_id );
		$quantity   = isset( $context['quantity'] ) ? max( 1, (int) $context['quantity'] ) : 1;
		$project_id = isset( $context['project_id'] ) && is_scalar( $context['project_id'] ) ? (string) $context['project_id'] : '';
		$metadata   = isset( $context['metadata'] ) && is_array( $context['metadata'] ) ? $context['metadata'] : array();

		$reservation = $this->usage->reserve( $user_id, $operation, $quantity, $plan, $project_id, $metadata );

		if ( empty( $reservation['success'] ) ) {
			$code = isset( $reservation['code'] ) ? (string) $reservation['code'] : 'usage_limit_reached';
			$this->log_denial( $user_id, $operation, $code );

			// A lock contention is a 409, not a 402: the user has not run out of
			// allowance, they asked at the same moment as somebody else. Reporting
			// it as a limit would be a lie that sends them to an upgrade page.
			$is_lock = ( 'usage_lock_held' === $code );

			return $this->deny(
				$code,
				isset( $reservation['message'] ) ? (string) $reservation['message'] : $this->limit_message( $operation, $plan, $reservation ),
				$is_lock ? 409 : 402,
				array(
					'operation'  => $operation,
					'limit'      => isset( $reservation['limit'] ) ? (int) $reservation['limit'] : 0,
					'used'       => isset( $reservation['used'] ) ? (int) $reservation['used'] : 0,
					'remaining'  => isset( $reservation['remaining'] ) ? (int) $reservation['remaining'] : 0,
					'period_key' => Plan_Limits::period_key(),
					'resets_at'  => Plan_Limits::period_ends(),
				)
			);
		}

		return array(
			'allowed'     => true,
			'reservation' => (string) $reservation['reservation'],
			'operation'   => $operation,
			'quantity'    => $quantity,
			'plan_id'     => $plan->id(),
			'status'      => 200,
		);
	}

	/**
	 * Settle a reservation as successful, charging the user.
	 *
	 * @param int                  $user_id     User id.
	 * @param string               $reservation Reservation token.
	 * @param string               $plan_id     Plan to charge under.
	 * @param array<string, mixed> $metadata    Allowed metadata only.
	 * @return array<string, mixed>
	 */
	public function settle( $user_id, $reservation, $plan_id = '', array $metadata = array() ) {
		$plan_id = ( '' === $plan_id ) ? $this->plans->current_plan_id( (int) $user_id ) : (string) $plan_id;
		$result  = $this->usage->commit( (int) $user_id, (string) $reservation, $plan_id, $metadata );

		if ( ! empty( $result['success'] ) ) {
			Audit_Log::record(
				'usage_charged',
				array(
					'operation' => isset( $result['operation'] ) ? (string) $result['operation'] : '',
					'quantity'  => isset( $result['quantity'] ) ? (int) $result['quantity'] : 0,
					'plan_id'   => $plan_id,
				),
				(int) $user_id
			);
		}

		return $result;
	}

	/**
	 * Settle a reservation as failed, charging nothing.
	 *
	 * @param int    $user_id     User id.
	 * @param string $reservation Reservation token.
	 * @param string $reason      Short machine reason.
	 * @return array<string, mixed>
	 */
	public function fail( $user_id, $reservation, $reason = 'operation_failed' ) {
		$result = $this->usage->release( (int) $user_id, (string) $reservation, (string) $reason );

		if ( ! empty( $result['success'] ) ) {
			Audit_Log::record(
				'usage_not_charged',
				array(
					'operation' => isset( $result['operation'] ) ? (string) $result['operation'] : '',
					'reason'    => (string) $reason,
				),
				(int) $user_id
			);
		}

		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Entity limits
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether a user may create another entity of a counted kind.
	 *
	 * Entity limits are counted, not metered. Incrementing a counter when a
	 * monitored project is added would need a matching decrement when it is
	 * removed, and a decrement that is missed — by a delete, an uninstall, or a
	 * restore — leaves the user permanently short of their allowance with no way to
	 * recover. Counting the live set cannot drift.
	 *
	 * @param string $limit Limit name.
	 * @param int    $user_id User id.
	 * @param int    $additional How many more are wanted.
	 * @return array<string, mixed>
	 */
	public function check_entity( $limit, $user_id, $additional = 1 ) {
		$user_id    = (int) $user_id;
		$additional = max( 0, (int) $additional );

		if ( $user_id < 1 ) {
			return $this->deny( 'authentication_required', __( 'Sign in to use ReplicaForge.', 'replicaforge' ), 401 );
		}

		if ( ! Plan_Limits::is_entity_limit( $limit ) ) {
			return $this->deny( 'unknown_limit', __( 'That limit is not available.', 'replicaforge' ), 400 );
		}

		$plan = $this->plans->current_plan( $user_id );
		$max  = $plan->limit( $limit );

		if ( Plan_Limits::is_unlimited( $max ) ) {
			return array(
				'allowed'   => true,
				'code'      => '',
				'status'    => 200,
				'details'   => array(
					'limit'     => Plan_Limits::UNLIMITED,
					'unlimited' => true,
				),
			);
		}

		$current = ( 'monitored_projects' === $limit )
			? $this->access->count_monitored( $user_id )['count']
			: $this->access->count_projects( $user_id );

		$available = max( 0, $max - $current );

		if ( $available < $additional ) {
			$this->log_denial( $user_id, $limit, 'entity_limit_reached' );
			return $this->deny(
				'entity_limit_reached',
				sprintf(
					/* translators: 1: limit count, 2: current count, 3: the kind of thing being counted. */
					__( 'Your plan allows %1$d of these. You already have %2$d.', 'replicaforge' ),
					(int) $max,
					(int) $current,
					'monitored_projects' === $limit
						? __( 'monitored projects', 'replicaforge' )
						: __( 'projects in your history', 'replicaforge' )
				),
				402,
				array(
					'limit'    => (int) $max,
					'current'  => (int) $current,
					'remaining' => $available,
					'limit_name' => (string) $limit,
				)
			);
		}

		return array(
			'allowed' => true,
			'code'    => '',
			'status'  => 200,
			'details' => array(
				'limit'     => (int) $max,
				'current'   => (int) $current,
				'remaining' => $available,
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Denial text
	 * ------------------------------------------------------------------ */

	/**
	 * Return the full text a limit screen shows.
	 *
	 * §10 requires a specific shape: what was reached, which plan, the used count,
	 * and what to do next. Assembling it here rather than in a template means the
	 * REST error and the admin screen cannot word the same refusal differently.
	 *
	 * @param string               $operation Operation name.
	 * @param Plan_Definition      $plan      Plan definition.
	 * @param array<string, mixed> $usage     Usage check result.
	 * @return array<string, mixed>
	 */
	public function limit_notice( $operation, Plan_Definition $plan, array $usage ) {
		$limit = isset( $usage['limit'] ) ? (int) $usage['limit'] : 0;
		$used  = isset( $usage['used'] ) ? (int) $usage['used'] : 0;
		$held  = isset( $usage['held'] ) ? (int) $usage['held'] : 0;
		$total = $used + $held;
		$reset = Plan_Limits::period_ends();

		return array(
			'title'   => __( 'You have reached your monthly limit.', 'replicaforge' ),
			'body'    => $this->limit_message( $operation, $plan, $usage ),
			'plan_id' => $plan->id(),
			'plan'    => $plan->name(),
			// The figure that fills the bar is the total, because an operation in
			// flight is going to consume the allowance unless it fails. When
			// something is genuinely in flight the notice says so, so a user who
			// has one running job is not told they used two.
			'used'    => $total,
			'charged' => $used,
			'held'    => $held,
			'in_flight' => ( $held > 0 ),
			'limit'   => $limit,
			'resets_at' => $reset,
			'resets_in' => human_time_diff( time(), $reset ),
			// No purchase button is offered, because there is nowhere to purchase.
			// The screen links to the plan details page, which explains that.
			'upgrade_url' => admin_url( 'admin.php?page=replicaforge-plans' ),
			'billing_configured' => ( null !== $this->plans->licenses()->billing() ),
		);
	}

	/**
	 * Return the message a limit refusal carries.
	 *
	 * @param string               $operation Operation name.
	 * @param Plan_Definition      $plan      Plan definition.
	 * @param array<string, mixed> $usage     Usage check result.
	 * @return string
	 */
	public function limit_message( $operation, Plan_Definition $plan, array $usage ) {
		$limit = isset( $usage['limit'] ) ? (int) $usage['limit'] : 0;
		$used  = isset( $usage['used'] ) ? (int) $usage['used'] : 0;

		$label = $this->operation_noun( $operation );

		return sprintf(
			/* translators: 1: operation count, 2: the plan limit, 3: the kind of operation. */
			__( 'Your plan allows %2$d %3$s each month and %1$d have been used.', 'replicaforge' ),
			$used,
			$limit,
			$label
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Build a feature denial.
	 *
	 * @param int              $user_id   User id.
	 * @param string           $operation Operation name.
	 * @param Plan_Definition  $plan      Plan definition.
	 * @return array<string, mixed>
	 */
	private function deny_feature( $user_id, $operation, Plan_Definition $plan ) {
		$feature = Plan_Limits::feature_for_operation( $operation );
		$this->log_denial( $user_id, $operation, 'feature_not_in_plan' );

		$billable = ( null !== $this->plans->licenses()->billing() );

		return $this->deny(
			'feature_not_in_plan',
			sprintf(
				/* translators: %s: the name of the feature. */
				__( '%s is not included in your current plan.', 'replicaforge' ),
				$this->plans->feature_label( $feature )
			),
			402,
			array(
				'feature'            => $feature,
				'plan_id'            => $plan->id(),
				'plan_name'          => $plan->name(),
				'upgrade_url'        => admin_url( 'admin.php?page=replicaforge-plans' ),
				'billing_configured' => $billable,
			)
		);
	}

	/**
	 * Build a structured denial.
	 *
	 * @param string               $code    Stable error code.
	 * @param string               $message User-facing message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $details Extra details.
	 * @return array<string, mixed>
	 */
	private function deny( $code, $message, $status, array $details = array() ) {
		return array(
			'allowed' => false,
			'code'    => (string) $code,
			'message' => (string) $message,
			'status'  => (int) $status,
			'details' => $details,
		);
	}

	/**
	 * Log a denial.
	 *
	 * §36 lists entitlement denials as audit events. They are recorded through
	 * {@see Audit_Log} rather than the logger, because a denial is a security
	 * event that an administrator may later need to review, and a log line that
	 * ages out is not reviewable.
	 *
	 * @param int    $user_id   User id.
	 * @param string $operation Operation or limit name.
	 * @param string $reason    Machine reason.
	 * @return void
	 */
	private function log_denial( $user_id, $operation, $reason ) {
		Audit_Log::record(
			'entitlement_denied',
			array(
				'operation' => (string) $operation,
				'reason'    => (string) $reason,
			),
			(int) $user_id
		);
	}

	/**
	 * Return a translated noun for an operation, in the singular.
	 *
	 * @param string $operation Operation name.
	 * @return string
	 */
	private function operation_noun( $operation ) {
		$nouns = array(
			'analysis'       => __( 'website analyses', 'replicaforge' ),
			'ai_analysis'    => __( 'AI analyses', 'replicaforge' ),
			'generation'     => __( 'generations', 'replicaforge' ),
			'validation'     => __( 'validations', 'replicaforge' ),
			'correction'     => __( 'corrections', 'replicaforge' ),
			'sync_operation' => __( 'sync operations', 'replicaforge' ),
			'export'         => __( 'exports', 'replicaforge' ),
			'import'         => __( 'imports', 'replicaforge' ),
		);

		return isset( $nouns[ $operation ] ) ? $nouns[ $operation ] : (string) $operation;
	}
}
