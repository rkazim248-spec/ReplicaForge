<?php
/**
 * Phase 10: the UI-facing feature gate.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * What a screen asks when it wants to know whether to show a button.
 *
 * This class is a **presentation layer over {@see Entitlement_Manager} and nothing
 * else**. It performs no checks of its own, and that restriction is the point. A
 * UI gate with its own logic is the most common way an entitlement system starts
 * lying: the screen decides a feature is available, the endpoint decides it is not,
 * and the user is shown a button that produces an error. Keeping the gate to a
 * translator over one decision means the two cannot disagree — the screen renders
 * whatever the gate would have decided anyway.
 *
 * Nothing here is security. Every method here can be called by a browser, and every
 * answer can be ignored by one. The server-side decision is made by
 * {@see Entitlement_Manager::check()} and {@see Entitlement_Manager::begin()}, and
 * a caller that trusts this class instead of those is relying on a hidden button,
 * which §9 forbids.
 *
 * What this class does add is wording. §30 is specific about tone — informative
 * rather than aggressive, no invented countdowns, no invented scarcity — and that
 * wording is worth having in one place rather than reworded in each template.
 */
final class Feature_Gate {

	/**
	 * Entitlement manager.
	 *
	 * @var Entitlement_Manager
	 */
	private $entitlements;

	/**
	 * Constructor.
	 *
	 * @param Entitlement_Manager|null $entitlements Optional entitlement manager.
	 */
	public function __construct( $entitlements = null ) {
		$this->entitlements = $entitlements instanceof Entitlement_Manager
			? $entitlements
			: new Entitlement_Manager();
	}

	/**
	 * Return the entitlement manager.
	 *
	 * @return Entitlement_Manager
	 */
	public function entitlements() {
		return $this->entitlements;
	}

	/**
	 * Return whether an operation looks available to a user.
	 *
	 * @param string               $operation Operation name.
	 * @param int                  $user_id   User id.
	 * @param array<string, mixed> $context   Optional project_id and quantity.
	 * @return bool
	 */
	public function allowed( $operation, $user_id, array $context = array() ) {
		$check = $this->entitlements->check( $operation, $user_id, $context );
		return ! empty( $check['allowed'] );
	}

	/**
	 * Return everything a screen needs to render an action.
	 *
	 * @param string               $operation Operation name.
	 * @param int                  $user_id   User id.
	 * @param array<string, mixed> $context   Optional project_id and quantity.
	 * @return array<string, mixed>
	 */
	public function state( $operation, $user_id, array $context = array() ) {
		$check = $this->entitlements->check( $operation, $user_id, $context );

		$plan_id    = isset( $check['plan_id'] ) ? (string) $check['plan_id'] : '';
		$has_plan   = $this->entitlements->plans()->current_plan( (int) $user_id );

		$out = array(
			'allowed'  => ! empty( $check['allowed'] ),
			'locked'   => empty( $check['allowed'] ),
			'code'     => (string) $check['code'],
			'message'  => (string) $check['message'],
			'status'   => (int) $check['status'],
			'details'  => is_array( $check['details'] ) ? $check['details'] : array(),
			'plan_id'  => ( '' !== $plan_id ) ? $plan_id : $has_plan->id(),
			'plan'     => $has_plan->name(),
			'operation' => (string) $operation,
		);

		if ( ! empty( $out['locked'] ) ) {
			$out['notice'] = $this->lock_notice( $operation, $out );
		}

		return $out;
	}

	/**
	 * Return the upgrade prompt for a locked operation.
	 *
	 * §30's rules are negative constraints — no fake countdown, no fake scarcity,
	 * no false claim about remaining places — and the easiest way to satisfy all
	 * three is to have only one place a sentence can be written. This returns
	 * structured text with no numbers that were not measured.
	 *
	 * @param string               $operation Operation name.
	 * @param array<string, mixed> $state     Result of {@see self::state()}.
	 * @return array<string, mixed>
	 */
	public function lock_notice( $operation, array $state = array() ) {
		$plan_id = isset( $state['plan_id'] ) ? (string) $state['plan_id'] : '';
		$plan    = $this->entitlements->plans()->definition( $plan_id );
		$feature = Plan_Limits::feature_for_operation( $operation );
		$licenses = $this->entitlements->plans()->licenses();
		$billing  = $licenses->billing();

		// The plans that actually grant this feature, taken from the definitions
		// rather than from a list written here. A plan added through the filter
		// appears without a code change, which is the same reason the matrix is
		// generated.
		$granted_by = array();
		foreach ( $this->entitlements->plans()->definitions() as $definition ) {
			if ( $definition->allows( $feature ) ) {
				$granted_by[] = array(
					'plan_id' => $definition->id(),
					'name'    => $definition->name(),
				);
			}
		}

		$body = array(
			'title'      => sprintf(
				/* translators: %s: the feature name. */
				__( '%s', 'replicaforge' ),
				$this->entitlements->plans()->feature_label( $feature )
			),
			'summary'    => sprintf(
				/* translators: %s: the feature name. */
				__( 'This feature is available on other ReplicaForge plans.', 'replicaforge' ),
				$this->entitlements->plans()->feature_label( $feature )
			),
			'current'    => ( $plan instanceof Plan_Definition ) ? $plan->name() : $plan_id,
			'available_on' => $granted_by,
			'plan_url'   => admin_url( 'admin.php?page=replicaforge-plans' ),
		);

		if ( null === $billing ) {
			// No purchase surface exists. Saying so is the honest alternative to a
			// button that goes nowhere, and §10 requires the plan page to explain it.
			$body['billing_configured'] = false;
			$body['note'] = __( 'Billing is not configured on this site, so plans cannot be purchased here. An administrator can change the plan in ReplicaForge settings.', 'replicaforge' );
			$body['action_label'] = __( 'View plan details', 'replicaforge' );
		} else {
			$body['billing_configured'] = true;
			$body['note'] = __( 'Your current plan does not include this feature.', 'replicaforge' );
			$body['action_label'] = __( 'View plans', 'replicaforge' );
		}

		if ( 'usage_limit_reached' === (string) ( $state['code'] ?? '' ) ) {
			$body['summary'] = __( 'You have used all of this operation for the current period.', 'replicaforge' );
		}

		return $body;
	}

	/**
	 * Return the plan matrix for the plan screen.
	 *
	 * @return array<string, mixed>
	 */
	public function matrix() {
		return $this->entitlements->plans()->matrix();
	}

	/**
	 * Return whether a user is on a trial, for a screen badge.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function in_trial( $user_id ) {
		$trial = $this->entitlements->plans()->licenses()->trial( (int) $user_id );
		return ! empty( $trial['active'] );
	}

	/**
	 * Return the class names a locked element should use.
	 *
	 * A locked action is styled differently, but it stays in the accessibility tree
	 * and stays focusable. §41 forbids making functionality depend on colour alone,
	 * and an element that is hidden from a keyboard is worse than one that merely
	 * looks different.
	 *
	 * @param array<string, mixed> $state Result of {@see self::state()}.
	 * @return array<string, string>
	 */
	public function element_state( array $state ) {
		$locked = ! empty( $state['locked'] );

		return array(
			'class'   => $locked ? 'replicaforge-action is-locked' : 'replicaforge-action',
			'aria'    => $locked ? 'aria-disabled="true"' : '',
			'data'    => $locked ? ' data-replicaforge-locked="1"' : '',
			'describedby' => $locked ? ' aria-describedby="replicaforge-lock-notice"' : '',
		);
	}
}
