<?php
/**
 * Phase 10: plan definition storage.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the plan definitions and the trial configuration.
 *
 * The defaults are here rather than in a database table, and they are filterable. Two
 * reasons, both from the brief: the values must not be permanently hard-coded, and a
 * plan set is a handful of rows that are read together and changed rarely — a table
 * would add a migration and a query to solve a problem options already solves.
 *
 * The filter is the extension point. An installation that sells different plans adds
 * them through `replicaforge_plan_definitions` and the rest of the plugin — the
 * matrix screen, the entitlement checks, the usage meter — reads whatever comes back
 * without knowing where it came from.
 */
final class Plan_Storage {

	/**
	 * Option holding the plan overrides.
	 *
	 * Empty means "use the defaults". Storing only the difference keeps a plan set
	 * upgrade from overwriting an administrator's deliberate change, and keeps the
	 * option small.
	 */
	const OPTION = 'replicaforge_plan_definitions';

	/**
	 * Option holding the trial configuration.
	 */
	const TRIAL_OPTION = 'replicaforge_trial_settings';

	/**
	 * Cache key prefix for the compiled plan set.
	 */
	const CACHE_PREFIX = 'replicaforge_plans_';

	/**
	 * Group used for cache invalidation.
	 */
	const CACHE_GROUP = 'replicaforge';

	/**
	 * The default plan set.
	 *
	 * The numbers are an example, not a price list. They are deliberately modest on
	 * the free plan so a new installation cannot burn a quota in an afternoon, and
	 * deliberately present on the paid plans so a paying installation is not
	 * artificially constrained. `agency` is unlimited on every count, which is what
	 * distinguishes it from `pro` beyond its numbers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults() {
		$defaults = array(
			'free'    => array(
				'name'        => __( 'Free', 'replicaforge' ),
				'description' => __( 'Everything needed to turn a page into an editable replica.', 'replicaforge' ),
				'limits'      => array(
					'analysis_per_period'     => 5,
					'ai_analysis_per_period'  => 5,
					'generation_per_period'   => 2,
					'validation_per_period'   => 20,
					'correction_per_period'   => 5,
					'sync_operation_per_period' => 0,
					'export_per_period'       => 1,
					'import_per_period'       => 0,
					'monitored_projects'      => 0,
					'history_projects'        => 5,
				),
				'features'    => array(
					'basic_analysis'           => true,
					'ai_understanding'         => true,
					'elementor_generation'     => true,
					'visual_validation'        => true,
					'automatic_correction'     => false,
					'monitoring'               => false,
					'source_sync'              => false,
					'advanced_reconstruction'  => false,
					'project_export'           => true,
					'project_import'           => false,
				),
			),
			'starter' => array(
				'name'        => __( 'Starter', 'replicaforge' ),
				'description' => __( 'For a site owner maintaining several pages.', 'replicaforge' ),
				'limits'      => array(
					'analysis_per_period'       => 50,
					'ai_analysis_per_period'    => 50,
					'generation_per_period'     => 20,
					'validation_per_period'     => 200,
					'correction_per_period'     => 50,
					'sync_operation_per_period' => 20,
					'export_per_period'         => 20,
					'import_per_period'         => 5,
					'monitored_projects'        => 2,
					'history_projects'          => 25,
				),
				'features'    => array(
					'basic_analysis'          => true,
					'ai_understanding'        => true,
					'elementor_generation'    => true,
					'visual_validation'       => true,
					'automatic_correction'    => true,
					'monitoring'              => false,
					'source_sync'             => false,
					'advanced_reconstruction' => false,
					'project_export'          => true,
					'project_import'          => true,
				),
			),
			'pro'      => array(
				'name'        => __( 'Pro', 'replicaforge' ),
				'description' => __( 'For a professional maintaining many replicas of moving sites.', 'replicaforge' ),
				'limits'      => array(
					'analysis_per_period'       => Plan_Limits::UNLIMITED,
					'ai_analysis_per_period'    => Plan_Limits::UNLIMITED,
					'generation_per_period'     => 100,
					'validation_per_period'     => Plan_Limits::UNLIMITED,
					'correction_per_period'     => 500,
					'sync_operation_per_period' => 200,
					'export_per_period'         => Plan_Limits::UNLIMITED,
					'import_per_period'         => 100,
					'monitored_projects'        => 20,
					'history_projects'          => 100,
				),
				'features'    => array(
					'basic_analysis'          => true,
					'ai_understanding'        => true,
					'elementor_generation'    => true,
					'visual_validation'       => true,
					'automatic_correction'    => true,
					'monitoring'              => true,
					'source_sync'             => true,
					'advanced_reconstruction' => true,
					'project_export'          => true,
					'project_import'          => true,
				),
			),
			'agency'   => array(
				'name'        => __( 'Agency', 'replicaforge' ),
				'description' => __( 'Unlimited analysis, generation, and monitoring.', 'replicaforge' ),
				'limits'      => array(
					'analysis_per_period'       => Plan_Limits::UNLIMITED,
					'ai_analysis_per_period'    => Plan_Limits::UNLIMITED,
					'generation_per_period'     => Plan_Limits::UNLIMITED,
					'validation_per_period'     => Plan_Limits::UNLIMITED,
					'correction_per_period'     => Plan_Limits::UNLIMITED,
					'sync_operation_per_period' => Plan_Limits::UNLIMITED,
					'export_per_period'         => Plan_Limits::UNLIMITED,
					'import_per_period'         => Plan_Limits::UNLIMITED,
					'monitored_projects'        => Plan_Limits::UNLIMITED,
					'history_projects'          => Plan_Limits::UNLIMITED,
				),
				'features'    => array(
					'basic_analysis'          => true,
					'ai_understanding'        => true,
					'elementor_generation'    => true,
					'visual_validation'       => true,
					'automatic_correction'    => true,
					'monitoring'              => true,
					'source_sync'             => true,
					'advanced_reconstruction' => true,
					'project_export'          => true,
					'project_import'          => true,
				),
			),
		);

		/**
		 * Filters the plan definitions.
		 *
		 * The returned array replaces the defaults wholesale, so an installation that
		 * wants a different plan set does not have to restate the parts it agrees
		 * with — though restating them is safer than merging, because a merge would
		 * silently keep a limit the new plan set meant to remove.
		 *
		 * @param array<string, array<string, mixed>> $defaults Plan definitions keyed by plan id.
		 */
		$filtered = apply_filters( 'replicaforge_plan_definitions', $defaults );
		if ( ! is_array( $filtered ) || array() === $filtered ) {
			// A filter that returns nothing has removed every plan, which would leave
			// the plugin with no entitlements at all. The defaults are restored rather
			// than honouring the filter, because a plugin with no plans cannot do
			// anything and the filter is almost certainly a mistake.
			return $defaults;
		}

		return $filtered;
	}

	/**
	 * Return the stored plan overrides.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function overrides() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Return the compiled plan set, as definitions.
	 *
	 * @param bool $fresh Whether to bypass the cache.
	 * @return array<string, Plan_Definition>
	 */
	public static function all( $fresh = false ) {
		$key    = self::CACHE_PREFIX . 'set';
		$cached = $fresh ? false : wp_cache_get( $key, self::CACHE_GROUP );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$merged = self::merge( self::defaults(), self::overrides() );

		$out = array();
		foreach ( $merged as $plan_id => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$definition = Plan_Definition::create( $plan_id, $data );
			if ( null !== $definition ) {
				$out[ $definition->id() ] = $definition;
			}
		}

		// Ordered by the declared plan order, so a screen that iterates the set gets a
		// sensible order without sorting it itself.
		uasort(
			$out,
			static function ( $left, $right ) {
				$by_rank = $left->rank() <=> $right->rank();
				if ( 0 !== $by_rank ) {
					return $by_rank;
				}
				return strcmp( $left->id(), $right->id() );
			}
		);

		wp_cache_set( $key, $out, self::CACHE_GROUP, 300 );

		return $out;
	}

	/**
	 * Return one plan definition.
	 *
	 * @param string $plan_id Plan identifier.
	 * @return Plan_Definition|null Null when the plan does not exist.
	 */
	public static function get( $plan_id ) {
		if ( ! is_string( $plan_id ) || '' === trim( $plan_id ) ) {
			return null;
		}
		$all = self::all();
		$key = strtolower( trim( $plan_id ) );
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Store plan overrides.
	 *
	 * Only the editable keys are kept: plan id, name, description, limits, and
	 * features. The brief requires that exported and imported plan configuration
	 * cannot execute anything, and the practical form of that is to accept only these
	 * five keys and drop everything else rather than trying to sanitise an unknown
	 * shape.
	 *
	 * @param array<string, mixed> $plans Plan data keyed by plan id.
	 * @return array{success: bool, stored: int, rejected: array<int, string>}
	 */
	public static function store( array $plans ) {
		$clean    = array();
		$rejected = array();

		foreach ( $plans as $plan_id => $data ) {
			if ( ! is_array( $data ) ) {
				$rejected[] = (string) $plan_id;
				continue;
			}
			$definition = Plan_Definition::create( $plan_id, $data );
			if ( null === $definition ) {
				$rejected[] = (string) $plan_id;
				continue;
			}

			$limits   = array();
			foreach ( $definition->limits() as $name => $value ) {
				$limits[ $name ] = (int) $value;
			}
			$features = array();
			foreach ( $definition->features() as $name => $enabled ) {
				$features[ $name ] = (bool) $enabled;
			}

			$clean[ $definition->id() ] = array(
				'name'        => $definition->name(),
				'description' => $definition->description(),
				'limits'      => $limits,
				'features'    => $features,
			);
		}

		if ( array() === $clean ) {
			return array(
				'success'  => false,
				'stored'   => 0,
				'rejected' => $rejected,
			);
		}

		update_option( self::OPTION, $clean, false );
		self::flush_cache();

		return array(
			'success'  => true,
			'stored'   => count( $clean ),
			'rejected' => $rejected,
		);
	}

	/**
	 * Return the trial configuration.
	 *
	 * A trial is **disabled by default**. A development installation should not hand
	 * out paid entitlements on its own, and a trial that starts without being asked
	 * for is a trial nobody agreed to.
	 *
	 * @return array<string, mixed>
	 */
	public static function trial_settings() {
		$stored = get_option( self::TRIAL_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$defaults = array(
			'enabled'      => false,
			'days'         => 14,
			'plan'         => 'pro',
			'allow_reentry' => false,
		);

		$out = array(
			'enabled'       => ! empty( $stored['enabled'] ),
			'days'          => isset( $stored['days'] ) && is_numeric( $stored['days'] )
				? max( 0, min( 365, (int) $stored['days'] ) )
				: (int) $defaults['days'],
			'plan'          => isset( $stored['plan'] ) && is_string( $stored['plan'] )
				? (string) $stored['plan']
				: (string) $defaults['plan'],
			'allow_reentry' => ! empty( $stored['allow_reentry'] ),
		);

		return $out;
	}

	/**
	 * Store the trial configuration.
	 *
	 * The trial plan is validated against the plan set on read rather than on write,
	 * because a plan can be removed after a trial was configured against it, and a
	 * trial pointing at a plan that no longer exists must not grant anything.
	 *
	 * @param array<string, mixed> $settings Trial settings.
	 * @return bool
	 */
	public static function store_trial( array $settings ) {
		$clean = array(
			'enabled'       => ! empty( $settings['enabled'] ),
			'days'          => isset( $settings['days'] ) && is_numeric( $settings['days'] )
				? max( 0, min( 365, (int) $settings['days'] ) )
				: 14,
			'plan'          => isset( $settings['plan'] ) && is_string( $settings['plan'] )
				? sanitize_key( $settings['plan'] )
				: 'pro',
			'allow_reentry' => ! empty( $settings['allow_reentry'] ),
		);

		update_option( self::TRIAL_OPTION, $clean, false );
		return true;
	}

	/**
	 * Export the editable plan configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function export() {
		$out = array();
		foreach ( self::all( true ) as $definition ) {
			$out[ $definition->id() ] = $definition->to_editable_array();
		}

		return array(
			'exported_at' => gmdate( 'c' ),
			'schema'      => '9.0',
			'plans'       => $out,
			'trial'       => self::trial_settings(),
		);
	}

	/**
	 * Import plan configuration.
	 *
	 * Every value is rebuilt through `Plan_Definition`, which drops unknown keys, so
	 * an imported document cannot introduce a key that something later trusts. There is
	 * no code path from an imported value to execution.
	 *
	 * @param array<string, mixed> $payload Exported payload.
	 * @return array{success: bool, stored: int, rejected: array<int, string>, errors: array<int, string>}
	 */
	public static function import( array $payload ) {
		$errors = array();

		/*
		 * Refuse a payload from a newer plan schema.
		 *
		 * `export()` writes `'schema' => '9.0'` and `import()` never read it, so the
		 * protection described on `Schema::PLAN_SCHEMA_VERSION` - "an import that carries a
		 * newer plan schema is refused rather than half-read, because a plan document with a
		 * key this version does not understand is a document whose limits might be
		 * incomplete - and an incomplete limit is read as unlimited" - was documented and not
		 * implemented. An import from a newer ReplicaForge could therefore install a plan
		 * whose limits this version cannot see, and an unrecognised limit reads as unlimited.
		 *
		 * An older schema is allowed: it is a subset, and `store()` drops keys it does not
		 * recognise. Only a *newer* one is refused.
		 */
		if ( isset( $payload['schema'] ) && is_string( $payload['schema'] ) && '' !== $payload['schema'] ) {
			$supplied = $payload['schema'];

			if ( version_compare( $supplied, Schema::PLAN_SCHEMA_VERSION, '>' ) ) {
				return array(
					'success'  => false,
					'stored'   => 0,
					'rejected' => array(),
					'errors'   => array(
						sprintf(
							/* translators: 1: the import's schema, 2: this version's schema. */
							__( 'The export uses plan schema %1$s and this site runs %2$s. Export from a matching version, or upgrade ReplicaForge first. Nothing was imported.', 'replicaforge' ),
							$supplied,
							Schema::PLAN_SCHEMA_VERSION
						),
					),
				);
			}
		}

		if ( ! isset( $payload['plans'] ) || ! is_array( $payload['plans'] ) ) {
			return array(
				'success'  => false,
				'stored'   => 0,
				'rejected' => array(),
				'errors'   => array( 'The import contained no plans.' ),
			);
		}

		$result = self::store( $payload['plans'] );
		if ( empty( $result['success'] ) ) {
			$errors[] = 'No plan in the import had a usable identifier.';
		}

		if ( isset( $payload['trial'] ) && is_array( $payload['trial'] ) ) {
			self::store_trial( $payload['trial'] );
		}

		return array(
			'success'  => ! empty( $result['success'] ),
			'stored'   => (int) $result['stored'],
			'rejected' => (array) $result['rejected'],
			'errors'   => $errors,
		);
	}

	/**
	 * Restore the default plan set.
	 *
	 * @return bool
	 */
	public static function reset() {
		delete_option( self::OPTION );
		self::flush_cache();
		return true;
	}

	/**
	 * Flush the compiled plan cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		wp_cache_delete( self::CACHE_PREFIX . 'set', self::CACHE_GROUP );
	}

	/**
	 * Merge overrides onto the defaults.
	 *
	 * A plan named by an override is **replaced**, not merged. A merge would keep
	 * limits the new definition meant to remove, which is the more dangerous failure:
	 * an administrator who publishes a plan intending to halve a quota would
	 * silently keep the old higher limit for every key they did not mention.
	 *
	 * @param array<string, array<string, mixed>> $defaults  Defaults.
	 * @param array<string, array<string, mixed>> $overrides Overrides.
	 * @return array<string, array<string, mixed>>
	 */
	private static function merge( array $defaults, array $overrides ) {
		$out = $defaults;

		foreach ( $overrides as $plan_id => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$key = strtolower( trim( (string) $plan_id ) );
			if ( ! preg_match( '/^[a-z0-9_]+$/', $key ) ) {
				continue;
			}
			$out[ $key ] = $data;
		}

		return $out;
	}
}
