<?php
/**
 * Phase 10: the centralized plan service.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that answers "what plan is this user on, and what does it say".
 *
 * Everything that needs a plan asks this class. The brief's instruction not to
 * scatter plan checks through random files is really an instruction about this:
 * if a screen reads `Plan_Storage` directly and the REST layer reads
 * `License_Manager` directly, the two will disagree the moment the resolution
 * order changes, and the disagreement will show up as a user who sees a feature on
 * a screen and is then refused by the API.
 *
 * So the resolution order lives here exactly once:
 *
 * 1. The licensing provider says whether this site is entitled to a paid plan.
 * 2. If it is, the locally configured plan applies.
 * 3. If the resulting plan is the free plan and the user has an available trial,
 *    the trial plan replaces it — but only if the trial plan ranks higher.
 *
 * The result is cached per request because the dashboard reads it several times and
 * each read costs a provider call, an option read, and three user meta reads.
 */
final class Plan_Manager {

	/**
	 * Licensing manager.
	 *
	 * @var License_Manager
	 */
	private $licenses;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Per-request resolution cache.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $resolved = array();

	/**
	 * Constructor.
	 *
	 * @param License_Manager|null $licenses Optional licensing manager.
	 * @param Logger|null          $logger   Optional logger.
	 */
	public function __construct( $licenses = null, $logger = null ) {
		$this->logger   = $logger instanceof Logger ? $logger : new Logger();
		$this->licenses = $licenses instanceof License_Manager ? $licenses : new License_Manager( null, null, $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Return every plan definition, in the declared order.
	 *
	 * @param bool $fresh Whether to bypass the plan cache.
	 * @return array<string, Plan_Definition>
	 */
	public function definitions( $fresh = false ) {
		return Plan_Storage::all( (bool) $fresh );
	}

	/**
	 * Return one plan definition.
	 *
	 * @param string $plan_id Plan identifier.
	 * @return Plan_Definition|null
	 */
	public function definition( $plan_id ) {
		return Plan_Storage::get( $plan_id );
	}

	/**
	 * Return the licensing manager.
	 *
	 * @return License_Manager
	 */
	public function licenses() {
		return $this->licenses;
	}

	/**
	 * Return the plan a user is on.
	 *
	 * @param int $user_id User id.
	 * @return Plan_Definition
	 */
	public function current_plan( $user_id ) {
		$resolved = $this->resolve( $user_id );
		return $resolved['plan'];
	}

	/**
	 * Return the full resolution: plan, how it was reached, license, and trial.
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>
	 */
	public function resolution( $user_id ) {
		return $this->resolve( $user_id );
	}

	/**
	 * Return the plan identifier a user is on.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public function current_plan_id( $user_id ) {
		return $this->current_plan( $user_id )->id();
	}

	/**
	 * Return whether a plan grants a feature.
	 *
	 * This asks about the *plan*, not about the user. Whether the user is on that
	 * plan is a separate question, and answering it here would make this method
	 * unusable for the plan matrix screen, which needs to ask about every plan
	 * including ones the reader is not on.
	 *
	 * @param string $feature Feature name.
	 * @param string $plan_id Plan identifier.
	 * @return bool
	 */
	public function plan_allows( $feature, $plan_id = '' ) {
		$plan = ( '' === $plan_id ) ? Plan_Storage::get( 'free' ) : Plan_Storage::get( $plan_id );
		return $plan instanceof Plan_Definition ? $plan->allows( $feature ) : false;
	}

	/**
	 * Return the plan-by-feature matrix for the plan screen.
	 *
	 * Generated from the definitions, never from a hard-coded table, so a plan added
	 * through the filter appears in the matrix without a UI change. §14 requires
	 * exactly this.
	 *
	 * @return array<string, mixed>
	 */
	public function matrix() {
		$plans = $this->definitions();

		$features = array();
		foreach ( Plan_Limits::FEATURES as $feature ) {
			$row = array(
				'feature'  => $feature,
				'label'    => $this->feature_label( $feature ),
				'granted'  => array(),
			);
			foreach ( $plans as $definition ) {
				// A plan that has not been completed does not grant a feature, so
				// the matrix shows the same thing `allows()` returns. Rendering a
				// blank cell here and a refusal at the endpoint would be the kind of
				// mismatch this class exists to prevent.
				$row['granted'][ $definition->id() ] = $definition->allows( $feature );
			}
			$features[] = $row;
		}

		$columns = array();
		foreach ( $plans as $definition ) {
			$columns[ $definition->id() ] = array(
				'plan_id'     => $definition->id(),
				'name'        => $definition->name(),
				'description' => $definition->description(),
				'rank'        => $definition->rank(),
			);
		}

		$limits = array();
		foreach ( Plan_Limits::LIMIT_NAMES as $limit ) {
			$row = array(
				'limit'  => $limit,
				'label'  => $this->limit_label( $limit ),
				'entity' => Plan_Limits::is_entity_limit( $limit ),
				'values' => array(),
			);
			foreach ( $plans as $definition ) {
				$row['values'][ $definition->id() ] = $definition->limit( $limit );
			}
			$limits[] = $row;
		}

		return array(
			'columns'  => $columns,
			'features' => $features,
			'limits'   => $limits,
			'unlimited' => Plan_Limits::UNLIMITED,
		);
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Store the plan this site runs on.
	 *
	 * @param string $plan_id Plan identifier.
	 * @return array{success: bool, plan_id: string, errors: array<int, string>}
	 */
	public function set_site_plan( $plan_id ) {
		$result = $this->licenses->set_configured_plan( $plan_id );
		$this->resolved = array();

		if ( ! empty( $result['success'] ) ) {
			$this->logger->info(
				'plan_site_changed',
				'The plan for this site was changed.',
				array( 'plan_id' => (string) $result['plan_id'] ),
				'plans'
			);
		}

		return $result;
	}

	/**
	 * Store the trial configuration.
	 *
	 * @param array<string, mixed> $settings Trial settings.
	 * @return array{success: bool, settings: array<string, mixed>, errors: array<int, string>}
	 */
	public function set_trial_settings( array $settings ) {
		$errors = array();

		if ( ! empty( $settings['plan'] ) ) {
			$trial_plan = Plan_Storage::get( (string) $settings['plan'] );
			if ( ! $trial_plan instanceof Plan_Definition ) {
				$errors[] = __( 'That trial plan does not exist.', 'replicaforge' );
			}
		}

		if ( ! empty( $settings['enabled'] ) && (int) ( $settings['days'] ?? 0 ) < 1 ) {
			$errors[] = __( 'A trial needs to last at least one day.', 'replicaforge' );
		}

		if ( array() !== $errors ) {
			return array(
				'success'  => false,
				'settings' => Plan_Storage::trial_settings(),
				'errors'   => $errors,
			);
		}

		Plan_Storage::store_trial( $settings );
		$this->resolved = array();

		return array(
			'success'  => true,
			'settings' => Plan_Storage::trial_settings(),
			'errors'   => array(),
		);
	}

	/**
	 * Export the plan configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function export_configuration() {
		return Plan_Storage::export();
	}

	/**
	 * Import the plan configuration.
	 *
	 * @param array<string, mixed> $payload Exported payload.
	 * @return array<string, mixed>
	 */
	public function import_configuration( array $payload ) {
		$result = Plan_Storage::import( $payload );
		$this->resolved = array();
		Plan_Storage::flush_cache();
		return $result;
	}

	/**
	 * Restore the shipped plan definitions.
	 *
	 * @return bool
	 */
	public function reset_configuration() {
		Plan_Storage::reset();
		$this->resolved = array();
		return true;
	}

	/**
	 * Clear the per-request resolution cache.
	 *
	 * @return void
	 */
	public function flush() {
		$this->resolved = array();
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve a user's plan once per request.
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>
	 */
	private function resolve( $user_id ) {
		$user_id = (int) $user_id;

		if ( isset( $this->resolved[ $user_id ] ) ) {
			return $this->resolved[ $user_id ];
		}

		$state     = $this->licenses->state();
		$for_user  = $this->licenses->plan_for_user( $user_id, $state );
		$plan      = $for_user['plan'];

		$out = array(
			'user_id'    => $user_id,
			'plan'       => $plan,
			'plan_id'    => $plan->id(),
			'via'        => (string) $for_user['via'],
			'trial'      => is_array( $for_user['trial'] ) ? $for_user['trial'] : array(),
			'license'    => $state->to_array(),
		);

		/**
		 * Filters the plan a user resolves to.
		 *
		 * Returning a {@see Plan_Definition} replaces the resolved plan; returning
		 * anything else leaves the resolution alone. The intended use is an
		 * installation that assigns plans by its own rules — a group membership, a
		 * domain, a staff list — without changing the plan system.
		 *
		 * This is deliberately *not* `replicaforge_feature_entitlement`. That name
		 * belongs to the allow/deny decision in {@see Entitlement_Manager}, and one
		 * name meaning two different things across two layers is a trap: a filter
		 * added to grant a feature would silently replace plans instead.
		 *
		 * @param Plan_Definition $plan    The resolved plan.
		 * @param int             $user_id The user the plan was resolved for.
		 * @param License_State   $state   The license state behind the resolution.
		 */
		$filtered = apply_filters( 'replicaforge_resolved_plan', $plan, $user_id, $state );
		if ( $filtered instanceof Plan_Definition ) {
			$out['plan']    = $filtered;
			$out['plan_id'] = $filtered->id();
			$out['via']     = 'filter';
		}

		$this->resolved[ $user_id ] = $out;

		return $out;
	}

	/**
	 * Return a translated feature label.
	 *
	 * @param string $feature Feature name.
	 * @return string
	 */
	public function feature_label( $feature ) {
		$labels = array(
			'basic_analysis'          => __( 'Basic analysis', 'replicaforge' ),
			'ai_understanding'        => __( 'AI understanding', 'replicaforge' ),
			'elementor_generation'    => __( 'Elementor generation', 'replicaforge' ),
			'visual_validation'       => __( 'Visual validation', 'replicaforge' ),
			'automatic_correction'    => __( 'Automatic correction', 'replicaforge' ),
			'monitoring'              => __( 'Source monitoring', 'replicaforge' ),
			'source_sync'             => __( 'Source synchronisation', 'replicaforge' ),
			'advanced_reconstruction' => __( 'Advanced reconstruction', 'replicaforge' ),
			'project_export'          => __( 'Project export', 'replicaforge' ),
			'project_import'          => __( 'Project import', 'replicaforge' ),
		);

		return isset( $labels[ $feature ] ) ? $labels[ $feature ] : (string) $feature;
	}

	/**
	 * Return a translated limit label.
	 *
	 * @param string $limit Limit name.
	 * @return string
	 */
	public function limit_label( $limit ) {
		$labels = array(
			'analysis_per_period'        => __( 'Analyses per month', 'replicaforge' ),
			'ai_analysis_per_period'     => __( 'AI analyses per month', 'replicaforge' ),
			'generation_per_period'      => __( 'Generations per month', 'replicaforge' ),
			'validation_per_period'      => __( 'Validations per month', 'replicaforge' ),
			'correction_per_period'      => __( 'Corrections per month', 'replicaforge' ),
			'sync_operation_per_period'  => __( 'Sync operations per month', 'replicaforge' ),
			'export_per_period'          => __( 'Exports per month', 'replicaforge' ),
			'import_per_period'          => __( 'Imports per month', 'replicaforge' ),
			'monitored_projects'         => __( 'Monitored projects', 'replicaforge' ),
			'history_projects'           => __( 'Projects in history', 'replicaforge' ),
		);

		return isset( $labels[ $limit ] ) ? $labels[ $limit ] : (string) $limit;
	}
}
