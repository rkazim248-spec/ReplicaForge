<?php
/**
 * Phase 10: license resolution.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what plan a user is on, from the license state and nothing else.
 *
 * The chain is short and every link is local:
 *
 * - {@see License_Provider_Contract::state()} answers "is this site entitled to a paid plan?"
 * - A locally configured plan id says which one.
 * - A per-user trial upgrades the free plan when trials are switched on.
 *
 * Two decisions in here are the ones worth arguing about.
 *
 * **An inactive license means the free plan, not the configured plan.** If the
 * configured plan were honoured regardless, the license would be decorative: an
 * administrator who sets "Pro" would get Pro whether or not any license exists,
 * and the whole feature would be a label on a settings screen. Making the license
 * the gate is what makes it mean something, and it is also the safe direction — a
 * licensing integration that fails closed must not silently grant paid features.
 *
 * **A trial only ever upgrades the free plan.** A site already on a paid plan has
 * no reason to be inside a trial, and applying one is either a downgrade or a
 * no-op. Restricting the rule to the free plan removes both cases instead of
 * needing a rank comparison to detect them.
 *
 * Nothing in this class reaches the network, and nothing in it reads a value a
 * browser supplied.
 */
final class License_Manager {

	/**
	 * Option holding the plan this site is configured to run.
	 */
	const SITE_PLAN_OPTION = 'replicaforge_site_plan';

	/**
	 * User meta prefix for trial bookkeeping.
	 */
	const TRIAL_META_PREFIX = 'replicaforge_trial_';

	/**
	 * Active licensing provider.
	 *
	 * @var License_Provider_Contract|null
	 */
	private $provider;

	/**
	 * Active billing provider.
	 *
	 * @var Billing_Provider_Contract|null
	 */
	private $billing;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Whether the provider was explicitly injected.
	 *
	 * @var bool
	 */
	private $provider_injected = false;

	/**
	 * Constructor.
	 *
	 * @param License_Provider_Contract|null $provider Optional provider override.
	 * @param Billing_Provider_Contract|null  $billing  Optional billing provider.
	 * @param Logger|null           $logger   Optional logger.
	 */
	public function __construct( $provider = null, $billing = null, $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();

		if ( $provider instanceof License_Provider_Contract ) {
			$this->provider          = $provider;
			$this->provider_injected = true;
		}
		if ( $billing instanceof Billing_Provider_Contract ) {
			$this->billing = $billing;
		}
	}

	/* ---------------------------------------------------------------------
	 * Providers
	 * ------------------------------------------------------------------ */

	/**
	 * Return the active licensing provider.
	 *
	 * With no provider injected, the local development provider is used, and the
	 * `replicaforge_license_provider` filter may replace it. A filter that returns
	 * something that is not a provider is ignored rather than half-used: a broken
	 * third-party integration should leave the plugin working, not fatal.
	 *
	 * @return License_Provider_Contract
	 */
	public function provider() {
		if ( $this->provider instanceof License_Provider_Contract ) {
			return $this->provider;
		}

		/**
		 * Filters the licensing provider.
		 *
		 * Returning a {@see License_Provider_Contract} connects an external licensing
		 * service. Returning anything else leaves the local provider in place.
		 *
		 * @param License_Provider_Contract $provider The active provider.
		 */
		$filtered = apply_filters( 'replicaforge_license_provider', null );

		if ( $filtered instanceof License_Provider_Contract ) {
			$this->provider = $filtered;
			return $this->provider;
		}

		$this->provider = new Local_License_Provider();
		return $this->provider;
	}

	/**
	 * Return the active billing provider, if one is configured.
	 *
	 * Null is the normal, fully supported state in this phase.
	 *
	 * @return Billing_Provider_Contract|null
	 */
	public function billing() {
		if ( $this->billing instanceof Billing_Provider_Contract ) {
			return $this->billing;
		}

		/**
		 * Filters the billing provider.
		 *
		 * Returning a {@see Billing_Provider_Contract} enables a purchase surface. There is
		 * no default and no fallback: with no provider, the plan screen reports
		 * that billing is not configured.
		 *
		 * @param Billing_Provider_Contract|null $billing The active billing provider.
		 */
		$filtered = apply_filters( 'replicaforge_billing_provider', null );

		if ( $filtered instanceof Billing_Provider_Contract ) {
			$this->billing = $filtered;
		}

		return $this->billing;
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */

	/**
	 * Return the current license state.
	 *
	 * @param int|null $timestamp Optional moment.
	 * @return License_State
	 */
	public function state( $timestamp = null ) {
		try {
			$state = $this->provider()->state();
		} catch ( \Throwable $error ) {
			// A provider that throws must not take the plugin down. The state
			// becomes `unknown`, which resolves to the free plan, so the failure
			// direction is "less access", not "no access".
			$this->logger->error(
				'license_provider_failed',
				'The licensing provider raised an error and the license is being treated as unknown.',
				array( 'error' => $error->getMessage() ),
				'plans'
			);
			return License_State::unknown( 'provider_error' );
		}

		return $state instanceof License_State ? $state : License_State::unknown( 'provider_contract' );
	}

	/**
	 * Return the state as a plain array.
	 *
	 * @param int|null $timestamp Optional moment.
	 * @return array<string, mixed>
	 */
	public function state_array( $timestamp = null ) {
		$state  = $this->state( $timestamp );
		$out    = $state->to_array( $timestamp );
		$out['provider'] = $this->provider()->id();
		$out['provider_label'] = $this->provider()->label();
		$out['remote']   = (bool) $this->provider()->is_remote();
		$out['billing_configured'] = ( null !== $this->billing() );
		$out['billing_provider'] = ( null !== $this->billing() ) ? $this->billing()->id() : '';
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Plans
	 * ------------------------------------------------------------------ */

	/**
	 * Return the plan this site is configured to run.
	 *
	 * The setting is a plan id, validated against the live plan set on read. A
	 * configuration pointing at a plan that no longer exists reads as the free plan
	 * rather than as an error, because a plan set can be edited underneath a
	 * license and a broken reference should not break the site.
	 *
	 * @return Plan_Definition
	 */
	public function configured_plan() {
		$free   = Plan_Storage::get( 'free' );
		$stored = get_option( self::SITE_PLAN_OPTION, 'free' );
		$stored = is_string( $stored ) && '' !== trim( $stored ) ? strtolower( trim( $stored ) ) : 'free';

		$plan = Plan_Storage::get( $stored );
		if ( $plan instanceof Plan_Definition ) {
			return $plan;
		}

		return $plan ? $plan : ( $free ? $free : $this->fallback_plan() );
	}

	/**
	 * Store the plan this site is configured to run.
	 *
	 * @param string $plan_id Plan identifier.
	 * @return array{success: bool, plan_id: string, errors: array<int, string>}
	 */
	public function set_configured_plan( $plan_id ) {
		$plan_id = is_string( $plan_id ) ? strtolower( trim( $plan_id ) ) : '';
		$plan    = Plan_Storage::get( $plan_id );

		if ( ! $plan instanceof Plan_Definition ) {
			return array(
				'success' => false,
				'plan_id' => $plan_id,
				'errors'  => array( __( 'That plan does not exist.', 'replicaforge' ) ),
			);
		}

		update_option( self::SITE_PLAN_OPTION, $plan->id(), false );

		return array(
			'success' => true,
			'plan_id' => $plan->id(),
			'errors'  => array(),
		);
	}

	/**
	 * Return the plan the license actually grants.
	 *
	 * @param License_State|null $state Optional pre-read state.
	 * @return Plan_Definition
	 */
	public function licensed_plan( $state = null ) {
		$state = ( $state instanceof License_State ) ? $state : $this->state();
		$free  = Plan_Storage::get( 'free' );

		if ( ! $state->grants_at() ) {
			return $free ? $free : $this->fallback_plan();
		}

		return $this->configured_plan();
	}

	/**
	 * Return the plan a user is on, trial included.
	 *
	 * @param int                 $user_id User id.
	 * @param License_State|null  $state   Optional pre-read state.
	 * @return array{plan: Plan_Definition, via: string, trial: array<string, mixed>}
	 */
	public function plan_for_user( $user_id, $state = null ) {
		$user_id = (int) $user_id;
		$state   = ( $state instanceof License_State ) ? $state : $this->state();
		$plan    = $this->licensed_plan( $state );
		$trial   = $this->trial( $user_id );

		if ( 'free' !== $plan->id() || empty( $trial['active'] ) ) {
			return array(
				'plan'  => $plan,
				'via'   => ( 'free' === $plan->id() ) ? 'free_default' : 'license',
				'trial' => $trial,
			);
		}

		$trial_plan = Plan_Storage::get( (string) $trial['plan'] );
		$usable     = $trial_plan instanceof Plan_Definition
			&& Plan_Limits::plan_rank( $trial_plan->id() ) > Plan_Limits::plan_rank( $plan->id() );

		if ( ! $usable ) {
			return array(
				'plan'  => $plan,
				'via'   => 'free_default',
				'trial' => $trial,
			);
		}

		return array(
			'plan'  => $trial_plan,
			'via'   => 'trial',
			'trial' => $trial,
		);
	}

	/* ---------------------------------------------------------------------
	 * Trials
	 * ------------------------------------------------------------------ */

	/**
	 * Return a user's trial state.
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>
	 */
	public function trial( $user_id ) {
		$user_id  = (int) $user_id;
		$settings = Plan_Storage::trial_settings();

		$out = array(
			'enabled'       => (bool) $settings['enabled'],
			'active'        => false,
			'plan'          => (string) $settings['plan'],
			'started_at'    => 0,
			'expires_at'    => 0,
			'available'     => false,
			'consumed'      => false,
			'reason'        => 'disabled',
		);

		if ( $user_id < 1 ) {
			$out['reason'] = 'no_user';
			return $out;
		}

		$started = (int) get_user_meta( $user_id, self::TRIAL_META_PREFIX . 'started_at', true );
		$expires = (int) get_user_meta( $user_id, self::TRIAL_META_PREFIX . 'expires_at', true );
		$plan_id = get_user_meta( $user_id, self::TRIAL_META_PREFIX . 'plan', true );
		$plan_id = is_string( $plan_id ) ? $plan_id : '';

		$out['started_at'] = $started;
		$out['expires_at'] = $expires;
		$out['consumed']   = ( $started > 0 );
		if ( '' !== $plan_id ) {
			$out['plan'] = $plan_id;
		}

		if ( empty( $settings['enabled'] ) ) {
			$out['reason'] = $started > 0 ? 'trials_disabled_after_use' : 'disabled';
			return $out;
		}

		if ( $started > 0 ) {
			if ( $expires > 0 && $expires <= time() ) {
				$out['reason'] = 'expired';
				return $out;
			}
			if ( empty( $settings['allow_reentry'] ) && $expires <= 0 ) {
				$out['reason'] = 'consumed';
				return $out;
			}
			$out['active']  = true;
			$out['reason']  = 'running';
			$out['plan']    = ( '' !== $plan_id ) ? $plan_id : (string) $settings['plan'];
			$out['expires_at'] = ( $expires > 0 ) ? $expires : ( $started + ( (int) $settings['days'] * DAY_IN_SECONDS ) );
			return $out;
		}

		$trial_plan = Plan_Storage::get( (string) $settings['plan'] );
		if ( ! $trial_plan instanceof Plan_Definition ) {
			$out['reason'] = 'trial_plan_missing';
			return $out;
		}

		$out['available'] = true;
		$out['reason']    = 'available';
		$out['plan']      = $trial_plan->id();

		return $out;
	}

	/**
	 * Start a user's trial.
	 *
	 * A trial is started by the user, on the server, and only when the
	 * configuration says one is available. There is no automatic start on
	 * activation: §3 is explicit that a fresh install must not do anything on the
	 * user's behalf, and a trial that begins unasked is the first thing a real
	 * product gets sued over.
	 *
	 * @param int $user_id User id.
	 * @return array{success: bool, trial: array<string, mixed>, errors: array<int, string>}
	 */
	public function start_trial( $user_id ) {
		$user_id = (int) $user_id;
		$before  = $this->trial( $user_id );

		if ( $user_id < 1 ) {
			return array(
				'success' => false,
				'trial'   => $before,
				'errors'  => array( __( 'A trial needs a WordPress user.', 'replicaforge' ) ),
			);
		}

		if ( ! $before['available'] ) {
			return array(
				'success' => false,
				'trial'   => $before,
				'errors'  => array( $this->trial_message( $before ) ),
			);
		}

		$settings = Plan_Storage::trial_settings();
		$days     = max( 0, (int) $settings['days'] );
		$now      = time();

		update_user_meta( $user_id, self::TRIAL_META_PREFIX . 'started_at', $now );
		update_user_meta( $user_id, self::TRIAL_META_PREFIX . 'expires_at', $now + ( $days * DAY_IN_SECONDS ) );
		update_user_meta( $user_id, self::TRIAL_META_PREFIX . 'plan', (string) $before['plan'] );

		return array(
			'success' => true,
			'trial'   => $this->trial( $user_id ),
			'errors'  => array(),
		);
	}

	/**
	 * Cancel a user's trial.
	 *
	 * The record is kept rather than deleted, so the trial cannot be restarted by
	 * cancelling it. A "cancel" that re-enables the trial is not a cancel.
	 *
	 * @param int $user_id User id.
	 * @return array{success: bool, trial: array<string, mixed>}
	 */
	public function cancel_trial( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::TRIAL_META_PREFIX . 'expires_at', time() - 1 );
		}
		return array(
			'success' => true,
			'trial'   => $this->trial( $user_id ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Diagnostics
	 * ------------------------------------------------------------------ */

	/**
	 * Return what this installation's licensing setup is.
	 *
	 * Shown on the system status and plan screens. The point of the `verified` key
	 * is that a reader can tell the difference between "a license server said this"
	 * and "an administrator typed this".
	 *
	 * @return array<string, mixed>
	 */
	public function diagnostics() {
		$provider = $this->provider();
		$state    = $this->state();
		$billing  = $this->billing();

		$out = array(
			'provider'         => $provider->id(),
			'provider_label'   => $provider->label(),
			'remote'           => (bool) $provider->is_remote(),
			'state'            => $state->effective_name(),
			'configured_plan'  => $this->configured_plan()->id(),
			'licensed_plan'    => $this->licensed_plan( $state )->id(),
			'billing_configured' => ( null !== $billing ),
			'billing_provider' => ( null !== $billing ) ? $billing->id() : '',
			'verified'         => (bool) $provider->is_remote(),
			'trial_enabled'    => (bool) Plan_Storage::trial_settings()['enabled'],
		);

		if ( $provider instanceof Local_License_Provider ) {
			$out = array_merge( $out, $provider->diagnostics() );
			$out['configured_plan'] = $this->configured_plan()->id();
			$out['licensed_plan']   = $this->licensed_plan( $state )->id();
			$out['verified']        = false;
		}

		if ( null !== $billing ) {
			$out['billing_diagnostics'] = $billing->diagnostics();
		}

		return $out;
	}

	/**
	 * Return a user-facing explanation of why a trial is unavailable.
	 *
	 * @param array<string, mixed> $trial Trial state.
	 * @return string
	 */
	private function trial_message( array $trial ) {
		switch ( (string) $trial['reason'] ) {
			case 'disabled':
				return __( 'Trials are not enabled on this site.', 'replicaforge' );
			case 'expired':
				return __( 'Your trial has already ended.', 'replicaforge' );
			case 'consumed':
				return __( 'A trial has already been used on this account.', 'replicaforge' );
			case 'trial_plan_missing':
				return __( 'The trial plan for this site does not exist, so no trial can start.', 'replicaforge' );
			case 'no_user':
				return __( 'Sign in to start a trial.', 'replicaforge' );
			case 'trials_disabled_after_use':
			case 'running':
			default:
				return __( 'A trial cannot be started right now.', 'replicaforge' );
		}
	}

	/**
	 * Return a plan when even the free plan is missing.
	 *
	 * This should be unreachable — `Plan_Storage::all()` refuses to return an empty
	 * set — but the entitlement system must never be holding a null plan, because
	 * every caller would then need its own null check and one of them would forget.
	 * A minimal, restrictive definition is the right thing to return: it withholds
	 * everything, which is the correct behaviour for a broken installation.
	 *
	 * @return Plan_Definition
	 */
	private function fallback_plan() {
		$definition = Plan_Definition::create(
			'free',
			array(
				'name'        => 'Free',
				'description' => '',
				'limits'      => array(),
				'features'    => array(),
			)
		);

		// Plan_Definition::create only returns null for an unusable identifier, and
		// 'free' is a usable one, so this cannot be null in practice.
		return $definition instanceof Plan_Definition ? $definition : new Plan_Definition( 'free', array() );
	}
}
