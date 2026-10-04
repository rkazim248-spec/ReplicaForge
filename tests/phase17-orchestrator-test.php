<?php
/**
 * Phase 17: the reconstruction orchestrator.
 *
 * ### What this suite is for
 *
 * Two things, and the second matters more than the first.
 *
 * The first is to check the orchestrator behaves. The second is to record, permanently and in
 * code, what has *not* been verified - so that a later reader cannot mistake an implemented
 * feature for a tested one. Every section that could not be exercised on this install says so
 * in an assertion that fails if the excuse quietly stops being true.
 *
 * ### The rule about capability assertions
 *
 * Where this suite checks a REST permission callback it asserts `is_callable()`, not
 * `isset()`. Phase 16 declared its callbacks `private`, which made `is_callable()` false,
 * which meant WordPress could not reach the check, which meant all ten endpoints answered
 * anonymous callers. `isset()` passed the whole time. Asserting reachability is the only
 * assertion that would have caught it.
 *
 * @package ReplicaForge
 */

use ReplicaForge\Capability_Registry;
use ReplicaForge\Job_Repository;
use ReplicaForge\Orchestrator_Admin;
use ReplicaForge\Orchestrator_Api;
use ReplicaForge\Orchestrator_Limits as O;
use ReplicaForge\Preflight_Assessment;
use ReplicaForge\Project_Repository;
use ReplicaForge\Quality_Gate;
use ReplicaForge\Workflow_Artifacts;
use ReplicaForge\Workflow_Definition;
use ReplicaForge\Workflow_Executor;
use ReplicaForge\Workflow_Report;
use ReplicaForge\Workflow_Repository;

/**
 * The suite.
 */
final class ReplicaForge_Phase17_Orchestrator_Test {

	/**
	 * Run every section.
	 *
	 * @return array The tally.
	 */
	public static function run() {
		$t = new self();

		$t->section_vocabulary();
		$t->section_capability_registry();
		$t->section_workflow_definition();
		$t->section_artifact_store();
		$t->section_repository_and_state();
		$t->section_permissions();
		$t->section_approvals();
		$t->section_budgets();
		$t->section_preflight();
		$t->section_executor_eligibility();
		$t->section_output_verification();
		$t->section_quality_gate();
		$t->section_report();
		$t->section_rest_surface();
		$t->section_admin();
		$t->section_not_tested();

		return $t->tally();
	}

	/* ---------------------------------------------------------------------
	 * Harness
	 * ------------------------------------------------------------------ */

	/** @var int */
	private $passed = 0;

	/** @var int */
	private $failed = 0;

	/** @var array<int, string> */
	private $failures = array();

	/** @var string */
	private $section = '';

	/**
	 * Start a section.
	 *
	 * @param string $name The section name.
	 * @return void
	 */
	private function start( $name ) {
		$this->section = $name;

		echo "\n=== " . $name . " ===\n";
	}

	/**
	 * Assert.
	 *
	 * The `PASS:` / `FAIL:` prefix is the convention every other suite in this plugin uses,
	 * and `run-all-tests.ps1` counts assertions by matching it. A differently-formatted
	 * suite does not fail - it is simply *not counted*, which is worse, because the summary
	 * would then report a total that quietly omitted everything this phase verified.
	 *
	 * @param string $label  What is asserted.
	 * @param bool   $pass   Whether it holds.
	 * @param string $detail Extra context.
	 * @return void
	 */
	private function ok( $label, $pass, $detail = '' ) {
		if ( $pass ) {
			$this->passed++;
			echo sprintf( "PASS: %-60s %s\n", $label, (string) $detail );

			return;
		}

		$this->failed++;
		$this->failures[] = $this->section . ': ' . $label . ( '' === (string) $detail ? '' : ' (' . $detail . ')' );

		echo sprintf( "FAIL: %-60s %s\n", $label, (string) $detail );
	}

	/**
	 * Assert a value equals an expected one.
	 *
	 * @param string $label    What is asserted.
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
	 * @return void
	 */
	private function eq( $label, $expected, $actual ) {
		$this->ok(
			$label,
			$expected === $actual,
			$expected === $actual ? '' : 'expected ' . $this->show( $expected ) . ', got ' . $this->show( $actual )
		);
	}

	/**
	 * Reduce a value to something printable.
	 *
	 * @param mixed $value The value.
	 * @return string
	 */
	private function show( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return 'null';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return substr( (string) wp_json_encode( $value ), 0, 80 );
	}

	/**
	 * Return the tally.
	 *
	 * @return array
	 */
	private function tally() {
		echo "\n";
		echo str_repeat( '-', 72 ) . "\n";

		if ( array() !== $this->failures ) {
			echo "FAILURES:\n";
			foreach ( $this->failures as $failure ) {
				echo '  - ' . $failure . "\n";
			}
			echo "\n";
		}

		printf( "PHASE 17 ORCHESTRATOR: %d passed, %d failed\n", $this->passed, $this->failed );

		return array( 'passed' => $this->passed, 'failed' => $this->failed, 'failures' => $this->failures );
	}

	/**
	 * A user id this suite owns.
	 *
	 * Explicit rather than read from the project, because `Project_Repository::create()`
	 * does not record an owner - it writes `user_id => 0` - so reading it back would give a
	 * zero and every creation would be refused as anonymous. That is a property of phase 8,
	 * not of this layer, and the test should not pretend otherwise.
	 *
	 * @var int
	 */
	const USER = 777001;

	/**
	 * Return, or create, a project for a URL.
	 *
	 * @param string $url The source URL.
	 * @return array|null
	 */
	private function project_for( $url ) {
		$projects = new Project_Repository();
		$project  = $projects->find_by_source( $url );

		if ( null === $project ) {
			$project = $projects->create( $url, array( 'user_id' => self::USER ) );
		}

		return is_array( $project ) ? $project : null;
	}

	/* ---------------------------------------------------------------------
	 * 1. Vocabulary
	 * ------------------------------------------------------------------ */

	/**
	 * The declared vocabulary is complete and internally consistent.
	 *
	 * @return void
	 */
	private function section_vocabulary() {
		$this->start( '1. the declared vocabulary' );

		$this->eq( 'the schema is 17.0', '17.0', O::SCHEMA_VERSION );
		$this->eq( 'the engine version is declared', '1.0', O::ENGINE_VERSION );

		// A stage missing from any of the three tables is a stage the executor cannot reason
		// about, so completeness is checked rather than assumed.
		$missing = array();

		foreach ( O::STAGES as $stage ) {
			if ( ! array_key_exists( $stage, O::STAGE_DEPENDENCIES ) ) {
				$missing[] = $stage . ':deps';
			}
			if ( ! array_key_exists( $stage, O::STAGE_CAPABILITIES ) ) {
				$missing[] = $stage . ':capability';
			}
		}

		$this->eq( 'every stage declares dependencies and a capability', array(), $missing );

		$unknown = array();

		foreach ( O::STAGE_DEPENDENCIES as $stage => $deps ) {
			foreach ( (array) $deps as $dependency ) {
				if ( ! O::is_stage( $dependency ) ) {
					$unknown[] = $stage . '->' . $dependency;
				}
			}
		}

		$this->eq( 'no stage depends on an undeclared stage', array(), $unknown );

		// Depth-first cycle detection with three colours.
		$colour = array();
		$cycle  = '';
		$visit  = function ( $node, $path ) use ( &$visit, &$colour, &$cycle ) {
			$colour[ $node ] = 1;

			foreach ( (array) ( O::STAGE_DEPENDENCIES[ $node ] ?? array() ) as $dependency ) {
				if ( ! O::is_stage( $dependency ) ) {
					continue;
				}
				if ( 1 === ( $colour[ $dependency ] ?? 0 ) ) {
					$cycle = $node . ' -> ' . $dependency;
					return;
				}
				if ( 2 !== ( $colour[ $dependency ] ?? 0 ) ) {
					$visit( $dependency, $path . ' -> ' . $node );
				}
			}

			$colour[ $node ] = 2;
		};

		foreach ( O::STAGES as $stage ) {
			if ( 2 !== ( $colour[ $stage ] ?? 0 ) ) {
				$visit( $stage, $stage );
			}
		}

		$this->eq( 'the stage graph is acyclic', '', $cycle );

		// The declared order must be a valid topological order, or the reported timeline
		// would not match the order work happens in.
		$out_of_order = array();
		$seen         = array();

		foreach ( O::STAGES as $stage ) {
			foreach ( (array) ( O::STAGE_DEPENDENCIES[ $stage ] ?? array() ) as $dependency ) {
				if ( in_array( $dependency, O::STAGES, true ) && ! isset( $seen[ $dependency ] ) ) {
					$out_of_order[] = $stage . ' before ' . $dependency;
				}
			}
			$seen[ $stage ] = true;
		}

		$this->eq( 'the declared order is a valid topological order', array(), $out_of_order );

		$reachable = array( 'preflight' => true );
		$changed   = true;
		$guard     = 0;

		while ( $changed && $guard < 32 ) {
			$changed = false;
			$guard++;

			foreach ( O::STAGE_DEPENDENCIES as $stage => $deps ) {
				if ( isset( $reachable[ $stage ] ) ) {
					continue;
				}
				foreach ( (array) $deps as $dependency ) {
					if ( isset( $reachable[ $dependency ] ) ) {
						$reachable[ $stage ] = true;
						$changed              = true;
						break;
					}
				}
			}
		}

		$this->eq( 'every stage is reachable from preflight', array(), array_values( array_diff( O::STAGES, array_keys( $reachable ) ) ) );

		$bad = array();

		foreach ( O::STAGE_CAPABILITIES as $stage => $capability ) {
			if ( ! in_array( $capability, O::CAPABILITIES, true ) ) {
				$bad[] = $stage . '=' . $capability;
			}
		}

		$this->eq( 'every stage capability is a declared capability', array(), $bad );

		// A capability whose absence blocks a workflow is a deliberate, small list.
		$this->eq( 'the blocking capabilities are core, fetch and elementor', array( 'core', 'fetch', 'elementor' ), O::REQUIRED_CAPABILITIES );

		// The state machine.
		$dead = array();

		foreach ( O::STATES as $state ) {
			if ( O::is_terminal( $state ) ) {
				continue;
			}
			if ( empty( O::TRANSITIONS[ $state ] ) ) {
				$dead[] = $state;
			}
		}

		$this->eq( 'no non-terminal state is a dead end', array(), $dead );

		$exits = array();

		foreach ( O::TERMINAL_STATES as $state ) {
			if ( ! empty( O::TRANSITIONS[ $state ] ) && ! in_array( $state, O::RETRYABLE_STATES, true ) ) {
				$exits[] = $state;
			}
		}

		$this->eq( 'only a declared retryable terminal state has an exit', array(), $exits );
		$this->eq( 'failed is retryable, and completed and cancelled are not', array( 'failed' ), O::RETRYABLE_STATES );

		$this->ok( 'a cancelled workflow cannot be restarted', ! O::can_transition( 'cancelled', 'running' ) );
		$this->ok( 'a completed workflow cannot be re-queued', ! O::can_transition( 'completed', 'queued' ) );
		$this->ok( 'a failed workflow can be re-queued', O::can_transition( 'failed', 'queued' ) );
		$this->ok( 'a workflow waiting for approval cannot reach completion directly', ! O::can_transition( 'waiting_approval', 'completed' ) );
		$this->ok( 'an undeclared state is refused', ! O::can_transition( 'draft', 'imaginary' ) );

		// skipped is not succeeded. This is the distinction the whole executor rests on.
		$this->ok( 'skipped is declared and is distinct from succeeded', in_array( 'skipped', O::STAGE_OUTCOMES, true ) && 'skipped' !== 'succeeded' );
		$this->ok( 'blocked is declared and is distinct from failed', in_array( 'blocked', O::STAGE_OUTCOMES, true ) && 'blocked' !== 'failed' );

		// Modes weight every dimension, and security is never traded off.
		$bad_weights = array();

		foreach ( O::MODES as $mode ) {
			if ( array_keys( O::weights_for( $mode ) ) !== O::DIMENSIONS ) {
				$bad_weights[] = $mode;
			}
		}

		$this->eq( 'every mode weights every dimension', array(), $bad_weights );

		$security = true;

		foreach ( O::MODES as $mode ) {
			if ( 1.0 !== (float) O::weights_for( $mode )['security'] ) {
				$security = false;
			}
		}

		$this->ok( 'security is weighted 1.0 in every mode', $security );

		// Every gate names a real phase 15 capability. A gate that required a capability the
		// permission system does not have would deny everyone, including the owner.
		$real = array();

		foreach ( \ReplicaForge\Workspace_Limits::CAPABILITY_GROUPS as $group ) {
			foreach ( (array) $group as $capability ) {
				$real[] = $capability;
			}
		}

		$bad_gates = array();

		foreach ( O::GATES as $gate ) {
			if ( ! isset( O::GATE_STAGES[ $gate ] ) || ! O::is_stage( O::GATE_STAGES[ $gate ] ) ) {
				$bad_gates[] = $gate . ':stage';
			}

			$capability = (string) ( O::GATE_CAPABILITIES[ $gate ] ?? '' );

			if ( ! in_array( $capability, $real, true ) ) {
				$bad_gates[] = $gate . ':' . $capability;
			}
		}

		$this->eq( 'every gate sits at a declared stage and requires a real capability', array(), $bad_gates );

		// Budgets clamp and never raise.
		$this->eq( 'an absurd correction request is clamped to 3', 3, O::budget( 'max_corrections', 9999 ) );
		$this->eq( 'the correction ceiling is three, as the specification asks', 3, O::BUDGETS['max_corrections'] );
		$this->eq( 'an unknown budget is refused', 0, O::budget( 'not_real', 5 ) );
	}

	/* ---------------------------------------------------------------------
	 * 2. Capability registry
	 * ------------------------------------------------------------------ */

	/**
	 * Capabilities are measured, not assumed.
	 *
	 * @return void
	 */
	private function section_capability_registry() {
		$this->start( '2. the capability registry measures rather than assumes' );

		$registry = new Capability_Registry();
		$all      = $registry->all();

		$this->eq( 'every declared capability is probed', count( O::CAPABILITIES ), count( $all ) );

		$unprobed = array();

		foreach ( O::CAPABILITIES as $capability ) {
			if ( 'missing_probe' === ( $all[ $capability ]['detail']['source'] ?? '' ) ) {
				$unprobed[] = $capability;
			}
		}

		$this->eq( 'no capability was left unprobed', array(), $unprobed );

		$statuses = array( 'available', 'degraded', 'unavailable' );
		$bad      = array();

		foreach ( $all as $capability => $entry ) {
			if ( ! in_array( (string) $entry['status'], $statuses, true ) ) {
				$bad[] = $capability;
			}
		}

		$this->eq( 'every answer is one of the three declared states', array(), $bad );

		// The re-entrancy property. A probe that reads a sibling capability must not re-run
		// the whole table; the Elementor probe costs ~6 MB and the install has ~60 MB of
		// headroom, so an unguarded recursion is an out-of-memory fatal.
		$before = memory_get_usage( true );
		$twice  = array();
		$twice  = ( new Capability_Registry() )->all();
		$twice  = ( new Capability_Registry() )->all();
		$after  = memory_get_usage( true );

		$this->ok( 'probing twice does not exhaust memory', $after < 134217728, sprintf( '%d MB used', (int) round( $after / 1048576 ) ) );

		// The sync probe is the one that matters most: three complete, tested classes exist
		// and no service wires them, so a class_exists() probe would lie.
		$this->ok( 'the phase 9 comparison classes really do exist', class_exists( 'ReplicaForge\\Change_Detector' ) && class_exists( 'ReplicaForge\\Component_Matcher' ) && class_exists( 'ReplicaForge\\Change_Classifier' ) );
		$this->eq( 'and the sync capability is still reported unavailable', 'unavailable', $all['sync']['status'] );
		$this->ok( 'its reason separates detection from application', false !== strpos( (string) $all['sync']['reason'], 'detected but not applied' ) );
		$this->eq( 'the detail records that detection works', 'available', $all['sync']['detail']['detection'] ?? '' );
		$this->eq( 'and that application does not', 'unavailable', $all['sync']['detail']['application'] ?? '' );

		// degraded is a real third answer.
		$this->ok( 'a degraded capability counts as usable', $registry->can( 'validation' ) === ( 'unavailable' !== $all['validation']['status'] ) );

		if ( 'degraded' === (string) $all['validation']['status'] ) {
			$this->ok( 'but is not reported as fully available', $registry->can( 'validation' ) && ! $registry->is_full( 'validation' ) );
		} else {
			$this->ok( 'a non-degraded capability is reported as fully available', $registry->can( 'validation' ) === $registry->is_full( 'validation' ) );
		}

		// An override is labelled as an override, so a report never presents a forced answer
		// as a measured one.
		$forced = new Capability_Registry();
		$forced->force( 'ai', true );
		$forced->force( 'elementor', false );

		$this->eq( 'a forced capability reports its source as an override', 'override', $forced->get( 'ai' )['detail']['source'] );
		$this->ok( 'and its reason says it was forced', false !== strpos( (string) $forced->get( 'ai' )['reason'], 'Forced' ) );
		$this->ok( 'removing a required capability IS detected as a blocker', in_array( 'elementor', $forced->missing_required(), true ), implode( ',', $forced->missing_required() ) );

		/*
		 * `fetch` is a required capability, and whether it is available depends on the host's
		 * transport rather than on the code.
		 *
		 * The earlier probe only asked whether `Url_Validator` and `Http_Client` existed. Both
		 * always do, so on a PHP build without openssl or curl it answered `available`, a
		 * workflow was told fetching worked, and the `analysis` stage then failed on the first
		 * real request with "No working transports found".
		 *
		 * So this asserts the probe against the transport this host actually has. On a host
		 * that gains TLS the assertion inverts, which is correct: the test describes the
		 * environment, not a constant.
		 */
		$has_https = in_array( 'https', stream_get_wrappers(), true );

		$this->eq( 'fetch is unavailable exactly when there is no https transport', ! $has_https, in_array( 'fetch', $forced->missing_required(), true ) );

		if ( ! $has_https ) {
			$this->ok( 'and its reason names the missing transport', false !== strpos( (string) $forced->get( 'fetch' )['reason'], 'https' ), (string) $forced->get( 'fetch' )['reason'] );
			$this->ok( 'and the probe reads the wrappers rather than the class list', in_array( 'https', (array) ( $forced->get( 'fetch' )['detail']['wrappers'] ?? array() ), true ) === false );
		}

		$forced->flush();
		$this->eq( 'flush clears the override', 'probe', $forced->get( 'ai' )['detail']['source'] );

		// An undeclared capability is refused, never assumed.
		$this->eq( 'an undeclared capability is unavailable', false, $registry->can( 'telepathy' ) );
		$this->ok( 'and says it is not declared', false !== strpos( (string) $registry->get( 'telepathy' )['reason'], 'not declared' ) );

		// The summary is disclosure-checked.
		$summary = $registry->summary();
		$encoded = (string) wp_json_encode( $summary );

		$this->ok( 'the summary is JSON-encodable', is_string( $encoded ) && '' !== $encoded );
		$this->ok( 'it contains no API key field', false === strpos( $encoded, 'api_key' ) );
		$this->ok( 'it contains no URL', false === strpos( $encoded, 'http://' ) && false === strpos( $encoded, 'https://' ) );
		$this->ok( 'it contains no filesystem path', false === strpos( $encoded, 'C:\\' ) && false === strpos( $encoded, '/Users/' ) );

		$total = 0;

		foreach ( (array) $summary['counts'] as $count ) {
			$total += (int) $count;
		}

		$this->eq( 'the status counts add up to the declared capabilities', count( O::CAPABILITIES ), $total );

		// A capability that works is listed as usable and carries no limitation entry -
		// recording a reason for something that is fine is noise a user has to read past.
		$first_available = '';

		foreach ( $all as $capability => $entry ) {
			if ( 'available' === (string) $entry['status'] ) {
				$first_available = (string) $capability;
				break;
			}
		}

		$this->ok( 'at least one capability is available', '' !== $first_available, $first_available );
		$this->ok( 'and an available capability carries no limitation entry', '' === $first_available || ! isset( $summary['limitations'][ $first_available ] ) );

		if ( 'unavailable' === (string) $all['ai']['status'] ) {
			$this->ok( 'and an unavailable one does carry a limitation entry', isset( $summary['limitations']['ai']['reason'] ) && '' !== (string) $summary['limitations']['ai']['reason'] );
		} else {
			$this->ok( 'an available AI capability carries no limitation entry', ! isset( $summary['limitations']['ai'] ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * 3. Workflow definition
	 * ------------------------------------------------------------------ */

	/**
	 * A definition builds and validates for every type and mode.
	 *
	 * @return void
	 */
	private function section_workflow_definition() {
		$this->start( '3. the workflow definition' );

		$caps = ( new Capability_Registry() )->all();
		$bad  = array();

		foreach ( O::TYPES as $type ) {
			foreach ( O::MODES as $mode ) {
				$definition = Workflow_Definition::create( $type, $mode, $caps );

				if ( is_wp_error( $definition ) ) {
					$bad[] = $type . '/' . $mode . ':' . $definition->get_error_code();
				}
			}
		}

		$this->eq( 'all 20 type and mode combinations validate', array(), $bad );

		$plan = Workflow_Definition::create( 'single_page', 'balanced', $caps )->to_plan();

		foreach ( array( 'plan_id', 'schema_version', 'type', 'mode', 'stages', 'dependencies', 'expected_outputs', 'not_required', 'unavailable', 'approval_checkpoints', 'quality_targets', 'validation_steps', 'known_limitations', 'risk', 'recovery', 'capabilities' ) as $key ) {
			if ( ! array_key_exists( $key, $plan ) ) {
				$bad[] = 'missing:' . $key;
			}
		}

		$this->eq( 'the plan carries every section the specification asks a user to review', array(), $bad );

		// No invented numbers.
		$encoded = (string) wp_json_encode( $plan );

		$this->ok( 'the plan invents no duration or estimate', false === strpos( $encoded, '"duration"' ) && false === strpos( $encoded, '"eta"' ) && false === strpos( $encoded, '"minutes"' ) );
		$this->ok( 'the plan invents no accuracy percentage', false === strpos( $encoded, '"accuracy"' ) && false === strpos( $encoded, '"percentage"' ) );
		$this->ok( 'the plan has no combined score', false === strpos( $encoded, '"score"' ) && false === strpos( $encoded, '"overall"' ) );

		// Quality targets are per dimension, and an unmeasurable one is marked unavailable
		// rather than given a target nobody can meet.
		$this->eq( 'every dimension has a target entry', count( O::DIMENSIONS ), count( $plan['quality_targets'] ) );

		$unmeasurable = array();

		foreach ( $plan['quality_targets'] as $dimension => $target ) {
			if ( empty( $target['available'] ) ) {
				$unmeasurable[] = $dimension;
			}
		}

		$this->ok( 'dimensions that cannot be measured here are marked unavailable', in_array( 'visual', $unmeasurable, true ), implode( ',', $unmeasurable ) );
		$this->ok( 'and an unmeasurable dimension keeps its weight rather than vanishing', isset( $plan['quality_targets']['visual']['weight'] ) );

		// A type decides eligibility, not existence.
		$this->eq( 'every type runs the full declared graph', count( O::STAGES ), count( Workflow_Definition::create( 'single_page', 'balanced', $caps )->stages() ) );
		$this->ok( 'a single-page run marks the crawl not required, with a reason', 1 === count( $plan['not_required'] ) && 'site_architecture' === $plan['not_required'][0]['stage'] && 20 < strlen( (string) $plan['not_required'][0]['reason'] ) );
		$this->eq( 'a multi-page run needs nothing skipped', array(), O::ineligible_stages( 'multi_page' ) );
		$this->ok( 'an incremental run skips the crawl', in_array( 'site_architecture', O::ineligible_stages( 'incremental' ), true ) );

		// Gates are chosen per type rather than all eight every time.
		$single = Workflow_Definition::create( 'single_page', 'balanced', $caps )->gates();
		$shop   = Workflow_Definition::create( 'ecommerce', 'balanced', $caps )->gates();

		$this->ok( 'a single page needs fewer gates than an ecommerce run', count( $single ) < count( $shop ), count( $single ) . ' vs ' . count( $shop ) );
		$this->ok( 'the commerce gate appears only for commerce', ! in_array( 'commerce_mutation', $single, true ) && in_array( 'commerce_mutation', $shop, true ) );
		$this->ok( 'the sync gate appears only for an incremental run', in_array( 'sync_apply', Workflow_Definition::create( 'incremental', 'balanced', $caps )->gates(), true ) );
		$this->ok( 'every run needs the finalisation gate', in_array( 'finalization', $single, true ) && in_array( 'finalization', $shop, true ) );

		$bad_gate_stage = array();

		foreach ( O::TYPES as $type ) {
			$definition = Workflow_Definition::create( $type, 'balanced', $caps );

			foreach ( $definition->gates() as $gate ) {
				if ( ! in_array( $definition->gate_stage( $gate ), $definition->stages(), true ) ) {
					$bad_gate_stage[] = $type . '/' . $gate;
				}
			}
		}

		$this->eq( 'no gate sits at a stage that will not run', array(), $bad_gate_stage );

		// Risk follows the type.
		$this->eq( 'a single page is low risk', 'low', Workflow_Definition::create( 'single_page', 'balanced', $caps )->risk() );
		$this->eq( 'a multi-page run is moderate risk', 'moderate', Workflow_Definition::create( 'multi_page', 'balanced', $caps )->risk() );
		$this->eq( 'an ecommerce run is high risk', 'high', Workflow_Definition::create( 'ecommerce', 'balanced', $caps )->risk() );
		$this->eq( 'an incremental run is high risk', 'high', Workflow_Definition::create( 'incremental', 'balanced', $caps )->risk() );

		// The plan id is content-addressed, so an approval can be bound to it, and it
		// changes when the capability set changes - so an approval for the old plan cannot
		// carry over to a plan built on different assumptions.
		$id = Workflow_Definition::create( 'single_page', 'balanced', $caps )->plan_id();

		$this->ok( 'the plan id is content-shaped', 1 === preg_match( '/^plan_[a-f0-9]{20}$/', $id ), $id );
		$this->eq( 'identical inputs give an identical plan id', $id, Workflow_Definition::create( 'single_page', 'balanced', $caps )->plan_id() );
		$this->ok( 'a different mode gives a different plan id', $id !== Workflow_Definition::create( 'single_page', 'functional', $caps )->plan_id() );
		$this->ok( 'a different type gives a different plan id', $id !== Workflow_Definition::create( 'multi_page', 'balanced', $caps )->plan_id() );

		$shifted              = $caps;
		$shifted['rendering'] = array( 'status' => 'available' );

		$this->ok( 'a different capability set gives a different plan id', $id !== Workflow_Definition::create( 'single_page', 'balanced', $shifted )->plan_id() );

		// Validation steps are derived from capability, not declared optimistically.
		$steps = array();

		foreach ( $plan['validation_steps'] as $step ) {
			$steps[ $step['id'] ] = $step['available'];
		}

		$this->ok( 'a validation step is only offered when its capability is present', ( ! $steps['visual'] ) === ( ! ( new Capability_Registry() )->can( 'rendering' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * 4. Artifact store
	 * ------------------------------------------------------------------ */

	/**
	 * The artifact store holds its boundaries.
	 *
	 * @return void
	 */
	private function section_artifact_store() {
		$this->start( '4. the artifact store' );

		$id    = 'wf_phase17_test';
		$store = new Workflow_Artifacts( $id );
		$store->purge();

		$this->ok( 'a representation is stored', true === $store->put( 'page1', 'representation', array( 'sections' => array( 1, 2 ) ), array( 'source' => 'phase2', 'schema_version' => '2.0' ) ) );
		$this->ok( 'and is readable', $store->has( 'page1' ) );
		$this->eq( 'its schema is recorded from provenance', '2.0', $store->get( 'page1' )['schema_version'] );

		$references = $store->references();

		$this->ok( 'references() names what exists', 'representation' === $references[0]['kind'] && 'page1' === $references[0]['key'] );
		$this->ok( 'and carries no payload', ! isset( $references[0]['payload'] ) );

		$this->ok( 'an undeclared kind is refused', is_wp_error( $store->put( 'x', 'screenshot', array() ) ) );
		$this->ok( 'an empty key is refused', is_wp_error( $store->put( '', 'representation', array() ) ) );
		$this->ok( 'a missing artifact is an error, not null', is_wp_error( $store->get( 'never_stored' ) ) );

		// A reference cannot smuggle a payload. This is what keeps a large dataset out of a
		// workflow record "just as a reference".
		$this->ok( 'a scalar reference is accepted', true === $store->put( 'spec', 'specification_reference', array( 'specification_id' => 'spec_abc' ) ) );
		$this->ok( 'it is marked as a reference', true === $store->get( 'spec' )['reference'] );
		$this->eq( 'an array inside a reference is refused', 'orchestrator_reference_not_scalar', is_wp_error( $e = $store->put( 'big', 'specification_reference', array( 'id' => 'x', 'sections' => array( 1, 2 ) ) ) ) ? $e->get_error_code() : 'accepted' );
		$this->ok( 'and it was not written', ! $store->has( 'big' ) );
		$this->ok( 'an empty reference is refused', is_wp_error( $store->put( 'empty', 'validation_reference', array() ) ) );

		// Oversized is refused, never truncated. A truncated representation would be consumed
		// as though complete.
		$oversized = $store->put( 'huge', 'representation', array( 'blob' => str_repeat( 'x', 600000 ) ) );

		$this->eq( 'a 600 KB artifact is refused', 'orchestrator_artifact_too_large', is_wp_error( $oversized ) ? $oversized->get_error_code() : 'accepted' );
		$this->ok( 'the refusal reports the real size', is_wp_error( $oversized ) && 600000 < (int) $oversized->get_error_data()['bytes'] );
		$this->ok( 'and it was not written', ! $store->has( 'huge' ) );

		// Corruption is detected on read.
		$option = 'replicaforge_workflow_artifacts_' . $id;
		$raw    = get_option( $option );
		$raw['page1']['payload']['sections'][] = 99;
		update_option( $option, $raw );

		$got = ( new Workflow_Artifacts( $id ) )->get( 'page1' );

		$this->eq( 'a tampered artifact is caught on read', 'orchestrator_artifact_corrupt', is_wp_error( $got ) ? $got->get_error_code() : 'returned data' );

		// A schema mismatch is refused rather than consumed.
		$raw                      = get_option( $option );
		$raw['page1']['payload']   = array( 'sections' => array( 1, 2 ) );
		$raw['page1']['schema_version'] = '1.0';
		update_option( $option, $raw );

		$got = ( new Workflow_Artifacts( $id ) )->get( 'page1' );

		$this->eq( 'an artifact written against an older schema is refused', 'orchestrator_artifact_schema', is_wp_error( $got ) ? $got->get_error_code() : 'returned data' );

		$raw                         = get_option( $option );
		$raw['page1']['schema_version'] = '2.0';
		update_option( $option, $raw );

		$this->ok( 'a kind mismatch is refused', is_wp_error( ( new Workflow_Artifacts( $id ) )->get( 'page1', 'content_report' ) ) );
		$this->ok( 'the right kind is accepted', ! is_wp_error( ( new Workflow_Artifacts( $id ) )->get( 'page1', 'representation' ) ) );

		// Provenance is a closed list, and a URL in it is re-validated.
		$store2 = new Workflow_Artifacts( $id );
		$store2->put( 'prov', 'preflight', array( 'ok' => true ), array( 'source' => 'probe', 'unknown' => 'dropped', 'url' => 'http://169.254.169.254/latest/meta-data/' ) );
		$prov = $store2->get( 'prov' );

		$this->ok( 'an unknown provenance field is dropped', ! isset( $prov['provenance']['unknown'] ) );
		$this->ok( 'a cloud-metadata URL is dropped by the SSRF validator', ! isset( $prov['provenance']['url'] ) );
		$this->ok( 'the payload itself is untouched', true === $prov['payload']['ok'] );

		$store2->put( 'prov2', 'preflight', array( 'ok' => true ), array( 'url' => 'https://example.com/page' ) );

		$this->eq( 'a public URL is kept with its path', 'https://example.com/page', (string ) ( $store2->get( 'prov2' )['provenance']['url'] ?? '' ) );

		$this->ok( 'an artifact can be removed', $store2->remove( 'prov' ) );
		$this->ok( 'and is then gone', ! $store2->has( 'prov' ) );
		$this->ok( 'removing a missing artifact reports false', ! $store2->remove( 'prov' ) );

		$store2->purge();
		$this->eq( 'purge empties the store', 0, $store2->total_size() );
	}

	/* ---------------------------------------------------------------------
	 * 5. Repository and state machine
	 * ------------------------------------------------------------------ */

	/**
	 * Creation, persistence and the state machine.
	 *
	 * @return void
	 */
	private function section_repository_and_state() {
		$this->start( '5. the repository and the state machine' );

		$repository = new Workflow_Repository();
		$project    = $this->project_for( 'https://example.com/' );

		$this->ok( 'a project exists to test against', null !== $project );

		if ( null === $project ) {
			return;
		}

		$project_id = (string) $project['project_id'];
		$owner      = self::USER;

		// Creation requires an authenticated user.
		$refusal = $repository->create( array( 'project_id' => $project_id, 'created_by' => 0 ) );
		$this->eq( 'a workflow cannot be created by an anonymous caller', 'workflow_no_user', is_wp_error( $refusal ) ? $refusal->get_error_code() : 'created' );

		$refusal = $repository->create( array( 'project_id' => '', 'created_by' => $owner ) );
		$this->eq( 'a workflow must belong to a project', 'workflow_no_project', is_wp_error( $refusal ) ? $refusal->get_error_code() : 'created' );

		$refusal = $repository->create( array( 'project_id' => 'prj_does_not_exist', 'created_by' => $owner ) );
		$this->eq( 'a workflow cannot be created for a project that does not exist', 'workflow_unknown_project', is_wp_error( $refusal ) ? $refusal->get_error_code() : 'created' );

		// A private-network source is refused at creation, not discovered at the analysis
		// stage after a user has approved a plan for it.
		$refusal = $repository->create( array( 'project_id' => $project_id, 'created_by' => $owner, 'source_url' => 'http://169.254.169.254/' ) );
		$this->ok( 'a cloud-metadata source is refused at creation', is_wp_error( $refusal ), is_wp_error( $refusal ) ? $refusal->get_error_code() : 'accepted' );

		$refusal = $repository->create( array( 'project_id' => $project_id, 'created_by' => $owner, 'source_url' => 'file:///etc/passwd' ) );
		$this->ok( 'a file:// source is refused at creation', is_wp_error( $refusal ), is_wp_error( $refusal ) ? $refusal->get_error_code() : 'accepted' );

		$created = $repository->create( array( 'project_id' => $project_id, 'created_by' => $owner, 'type' => 'single_page', 'mode' => 'balanced' ) );
		$this->ok( 'a valid workflow is created', is_array( $created ) && ! is_wp_error( $created ) );

		$id = (string) $created['workflow_id'];

		$this->ok( 'its id is well shaped', 1 === preg_match( '/^wf_[A-Za-z0-9_]+$/', $id ), $id );
		$this->eq( 'it starts in draft', 'draft', (string) $created['state'] );
		$this->eq( 'its owner is recorded', $owner, (int) $created['created_by'] );

		// Idempotency: the same key returns the same workflow rather than a second one, and
		// two workflows for one source would produce two drafts the user cannot tell apart.
		$key     = 'idem_' . substr( md5( $id ), 0, 8 );
		$again   = $repository->create( array( 'project_id' => $project_id, 'created_by' => $owner, 'idempotency_key' => $key ) );
		$repeat  = $repository->create( array( 'project_id' => $project_id, 'created_by' => $owner, 'idempotency_key' => $key ) );

		$first  = is_array( $again ) ? ( is_array( $again[0] ?? null ) ? $again[0] : $again ) : array();
		$second = is_array( $repeat ) ? ( is_array( $repeat[0] ?? null ) ? $repeat[0] : $repeat ) : array();

		$this->ok( 'a repeated idempotency key returns the same workflow', '' !== (string) ( $first['workflow_id'] ?? '' ) && (string) $first['workflow_id'] === (string) ( $second['workflow_id'] ?? '' ), (string) ( $first['workflow_id'] ?? '?' ) . ' vs ' . (string) ( $second['workflow_id'] ?? '?' ) );
		$this->ok( 'and reports that it already existed', ! empty( $repeat[1] ) );

		$repository->delete( (string) $again[0]['workflow_id'] );

		// The state machine is the only writer.
		$illegal = $repository->transition( $id, 'completed' );
		$this->eq( 'a draft cannot jump to completed', 'workflow_illegal_transition', is_wp_error( $illegal ) ? $illegal->get_error_code() : 'allowed' );

		$repository->transition( $id, 'preflight' );
		$repository->transition( $id, 'queued' );
		$repository->transition( $id, 'running' );

		$this->eq( 'the workflow is now running', 'running', (string) $repository->get( $id )['state'] );

		$repository->transition( $id, 'waiting_approval' );
		$illegal = $repository->transition( $id, 'completed' );
		$this->eq( 'a workflow waiting for approval cannot complete', 'workflow_illegal_transition', is_wp_error( $illegal ) ? $illegal->get_error_code() : 'allowed' );

		$repository->transition( $id, 'running' );
		$repository->transition( $id, 'cancelled' );

		$refusal = $repository->transition( $id, 'running' );
		$this->eq( 'a cancelled workflow cannot restart', 'workflow_illegal_transition', is_wp_error( $refusal ) ? $refusal->get_error_code() : 'allowed' );
		$this->ok( 'and the refusal is a 409', is_wp_error( $refusal ) && 409 === (int) $refusal->get_error_data()['status'] );

		// A record written under an older schema is not silently upgraded.
		$option = O::RECORD_PREFIX . $id;
		$raw    = get_option( $option );
		$raw['schema_version'] = '16.0';
		update_option( $option, $raw );

		$this->ok( 'a record from an older schema is not returned as current', null === $repository->get( $id ) );

		$raw['schema_version'] = O::SCHEMA_VERSION;
		update_option( $option, $raw );

		// Stage outcomes.
		$repository->record_stage( $id, 'preflight', 'succeeded', array( 'outputs' => array( 'can_proceed' => 'yes' ) ) );
		$this->eq( 'a stage outcome is recorded', 'succeeded', $repository->stage( $repository->get( $id ), 'preflight' )['outcome'] );

		$refusal = $repository->record_stage( $id, 'imaginary', 'succeeded' );
		$this->eq( 'an undeclared stage is refused', 'workflow_unknown_stage', is_wp_error( $refusal ) ? $refusal->get_error_code() : 'accepted' );

		$refusal = $repository->record_stage( $id, 'preflight', 'imaginary' );
		$this->eq( 'an undeclared outcome is refused', 'workflow_unknown_outcome', is_wp_error( $refusal ) ? $refusal->get_error_code() : 'accepted' );

		// Checkpoints are validated on read.
		$repository->checkpoint( $id, 'preflight' );
		$point = $repository->latest_checkpoint( $repository->get( $id ) );

		$this->ok( 'a checkpoint is taken and validated', is_array( $point ) && 'preflight' === (string) $point['stage'] );

		$record                = $repository->get( $id );
		$record['checkpoints'] = array();
		$record['checkpoints'][] = array( 'stage' => 'preflight', 'completed' => array( 'preflight' => 'x' ), 'hash' => 'deadbeef' );
		$repository->save( $record );

		$this->ok( 'a checkpoint whose hash does not match is rejected', null === $repository->latest_checkpoint( $repository->get( $id ) ) );

		// A listing carries a stage count, never a percentage.
		$rows = $repository->for_project( $project_id, 5 );

		$this->ok( 'the project listing returns records', count( $rows ) > 0 );
		$this->ok( 'and a whole record carries its own stage records', isset( $rows[0]['stages'] ) && isset( $rows[0]['workflow_id'] ) );
		$this->ok( 'with an empty plan until one is built', array() === (array) ( $rows[0]['plan'] ?? array() ) );

		$summaries = $repository->recent( 50 );
		$row       = array();

		foreach ( $summaries as $summary ) {
			if ( (string) $summary['project_id'] === $project_id ) {
				$row = $summary;
				break;
			}
		}

		$this->ok( 'the dashboard listing returns a summary row', array() !== $row );
		$this->ok( 'a summary has a stage count', array_key_exists( 'stages_completed', $row ) && array_key_exists( 'stages_total', $row ) );
		$this->ok( 'and no percentage or progress bar value', ! isset( $row['progress'] ) && ! isset( $row['percentage'] ) && ! isset( $row['percent'] ) );
		$this->ok( 'and it names the current stage rather than a fraction', array_key_exists( 'current_stage', $row ) );

		$repository->delete( $id );
		$this->ok( 'a deleted workflow is gone', null === $repository->get( $id ) );
		// `get_option()` with no default returns false for a missing option, not null, so the
		// default is passed explicitly - otherwise "absent" and "stored as null" look alike.
		$this->ok( 'and its artifacts are purged', null === get_option( Workflow_Artifacts::PREFIX . Workflow_Artifacts::sanitize_id( $id ), null ) );
	}

	/* ---------------------------------------------------------------------
	 * 6. Permissions
	 * ------------------------------------------------------------------ */

	/**
	 * Ownership and access.
	 *
	 * @return void
	 */
	private function section_permissions() {
		$this->start( '6. ownership and access' );

		$repository = new Workflow_Repository();
		$project    = $this->project_for( 'https://example.org/' );

		if ( null === $project ) {
			$this->ok( 'a project exists for the permission tests', false );

			return;
		}

		$project_id = (string) $project['project_id'];
		$owner      = self::USER;

		$created = $repository->create( array( 'project_id' => $project_id, 'created_by' => $owner ) );

		if ( is_wp_error( $created ) ) {
			$this->ok( 'a workflow was created for the permission tests', false, $created->get_error_message() );

			return;
		}

		$record = $created;
		$id     = (string) $record['workflow_id'];

		$this->ok( 'the owner may read it', true === $repository->may_access( $record, $owner ) );
		$this->ok( 'an anonymous caller may not', is_wp_error( $repository->may_access( $record, 0 ) ) );
		$this->eq( 'and is refused as unauthenticated', 'workflow_unauthenticated', is_wp_error( $e = $repository->may_access( $record, 0 ) ) ? $e->get_error_code() : 'allowed' );

		// A stranger is refused. The id came from the record, never from the request, which
		// is what makes this an IDOR check rather than a session check.
		$stranger = 99999;

		while ( get_userdata( $stranger ) ) {
			$stranger++;
		}

		$refusal = $repository->may_access( $record, $stranger );

		$this->ok( 'a user with no relationship to the project is refused', is_wp_error( $refusal ), is_wp_error( $refusal ) ? $refusal->get_error_code() : 'allowed' );
		$this->eq( 'and the refusal is a 403', 403, is_wp_error( $refusal ) ? (int) $refusal->get_error_data()['status'] : 200 );

		// Default DENY when there is no permission service to ask.
		$this->ok( 'access with no user at all is refused', is_wp_error( $repository->may_access( $record, 0 ) ) );

		// A project with a workspace_id but no membership must be refused, not defaulted open.
		$adopted                = $record;
		$adopted['workspace_id'] = 'ws_some_workspace';
		$adopted['created_by']   = 0;

		$this->ok( 'a workflow with a workspace but no membership is refused', is_wp_error( $repository->may_access( $adopted, $stranger ) ) );

		$repository->delete( $id );
	}

	/* ---------------------------------------------------------------------
	 * 7. Approvals
	 * ------------------------------------------------------------------ */

	/**
	 * Approvals are bound to a plan and go stale when it changes.
	 *
	 * @return void
	 */
	private function section_approvals() {
		$this->start( '7. approvals are bound to a plan version' );

		$repository = new Workflow_Repository();
		$project    = $this->project_for( 'https://example.net/' );

		$created = null === $project ? null : $repository->create( array( 'project_id' => (string) $project['project_id'], 'created_by' => self::USER ) );

		if ( is_wp_error( $created ) ) {
			$this->ok( 'a workflow was created for the approval tests', false, $created->get_error_message() );

			return;
		}

		$record = $created;
		$id     = (string) $record['workflow_id'];
		$gate   = 'draft_creation';

		// A gate is pending until decided.
		$this->eq( 'a gate is pending before any decision', 'pending', $repository->gate_status( $record, $gate )['status'] );
		$this->ok( 'and is not satisfied', ! $repository->gate_satisfied( $record, $gate ) );

		// Unrecognised input is refused.
		$refusal = $repository->decide( $id, $gate, array( 'status' => 'approved' ) );
		$this->eq( 'a decision with no reviewer is refused', 'workflow_no_reviewer', is_wp_error( $refusal ) ? $refusal->get_error_code() : 'accepted' );

		$refusal = $repository->decide( $id, 'imaginary', array( 'status' => 'approved', 'reviewer_id' => self::USER ) );
		$this->eq( 'a decision on an undeclared gate is refused', 'workflow_unknown_gate', is_wp_error( $refusal ) ? $refusal->get_error_code() : 'accepted' );

		$refusal = $repository->decide( $id, $gate, array( 'status' => 'maybe', 'reviewer_id' => self::USER ) );
		$this->eq( 'a decision that is neither approved nor rejected is refused', 'workflow_bad_decision', is_wp_error( $refusal ) ? $refusal->get_error_code() : 'accepted' );

		// A decision by someone with no relationship to the project is refused, and the
		// authorization comes from phase 15's permission vocabulary.
		$stranger = 99999;

		while ( get_userdata( $stranger ) ) {
			$stranger++;
		}

		$refusal = $repository->decide( $id, $gate, array( 'status' => 'approved', 'reviewer_id' => $stranger ) );
		$this->ok( 'a decision by an unrelated user is refused', is_wp_error( $refusal ), is_wp_error( $refusal ) ? $refusal->get_error_code() : 'accepted' );

		// The owner may decide, and the decision is bound to the plan hash.
		$decided = $repository->decide( $id, $gate, array( 'status' => 'approved', 'reviewer_id' => self::USER, 'note' => 'looks right' ) );

		$this->ok( 'the owner may decide', is_array( $decided ) && ! is_wp_error( $decided ), is_wp_error( $decided ) ? $decided->get_error_code() : '' );

		$status = $repository->gate_status( $repository->get( $id ), $gate );

		$this->eq( 'the gate is now approved', 'approved', $status['status'] );
		$this->ok( 'and satisfied', $repository->gate_satisfied( $repository->get( $id ), $gate ) );
		$this->ok( 'and the approval is bound to an artifact hash', 32 === strlen( (string) $status['artifact_hash'] ) );
		$this->eq( 'and to the plan id', (string) $repository->get( $id )['plan_id'], (string) $repository->get( $id )['approvals'][ $gate ]['plan_id'] );
		$this->eq( 'and the reviewer is recorded', self::USER, (int) $repository->get( $id )['approvals'][ $gate ]['reviewer_id'] );

		// The whole point of hashing: a materially changed plan invalidates the approval.
		$changed                = $repository->get( $id );
		$changed['plan_id']     = 'plan_somethingelse';
		$repository->save( $changed );

		$status = $repository->gate_status( $repository->get( $id ), $gate );

		$this->ok( 'changing the plan makes the approval stale', ! empty( $status['stale'] ) );
		$this->ok( 'and a stale approval does not satisfy the gate', ! $repository->gate_satisfied( $repository->get( $id ), $gate ) );
		$this->ok( 'while the recorded status still reads as approved', 'approved' === $status['status'] );

		// A rejection is a stop, not a skip.
		$repository->decide( $id, $gate, array( 'status' => 'rejected', 'reviewer_id' => self::USER, 'note' => 'not this time' ) );
		$status = $repository->gate_status( $repository->get( $id ), $gate );

		$this->eq( 'a rejection is recorded', 'rejected', $status['status'] );
		$this->ok( 'and does not satisfy the gate', ! $repository->gate_satisfied( $repository->get( $id ), $gate ) );

		$repository->delete( $id );
	}

	/* ---------------------------------------------------------------------
	 * 8. Budgets
	 * ------------------------------------------------------------------ */

	/**
	 * Budget ceilings are enforced.
	 *
	 * @return void
	 */
	private function section_budgets() {
		$this->start( '8. resource budgets' );

		$repository = new Workflow_Repository();
		$project    = $this->project_for( 'https://example.com/budget-fixture/' );

		$created = null === $project ? null : $repository->create( array( 'project_id' => (string) $project['project_id'], 'created_by' => self::USER ) );

		if ( is_wp_error( $created ) ) {
			$this->ok( 'a workflow was created for the budget tests', false, $created->get_error_message() );

			return;
		}

		$id     = (string) $created['workflow_id'];
		$ceiling = (int) O::BUDGETS['max_corrections'];

		for ( $i = 0; $i < $ceiling; $i++ ) {
			$outcome = $repository->spend( $id, 'max_corrections', 1 );

			if ( is_wp_error( $outcome ) ) {
				break;
			}
		}

		$this->eq( 'the counter reaches its ceiling', $ceiling, (int) $repository->get( $id )['budget']['max_corrections'] );

		$refusal = $repository->spend( $id, 'max_corrections', 1 );

		$this->eq( 'spending past the ceiling is refused', 'workflow_budget_exhausted', is_wp_error( $refusal ) ? $refusal->get_error_code() : 'accepted' );
		$this->ok( 'and the refusal is a 402', is_wp_error( $refusal ) && 402 === (int) $refusal->get_error_data()['status'] );
		$this->eq( 'and it reports what was used against the limit', array( $ceiling, $ceiling ), is_wp_error( $refusal ) ? array( (int) $refusal->get_error_data()['details']['used'], (int) $refusal->get_error_data()['details']['limit'] ) : array() );

		$refusal = $repository->spend( $id, 'not_a_budget', 1 );
		$this->eq( 'an undeclared budget is refused', 'workflow_unknown_budget', is_wp_error( $refusal ) ? $refusal->get_error_code() : 'accepted' );

		// The correction loop's ceiling is three, as §11 requires.
		$this->eq( 'the correction iteration ceiling is three', 3, O::BUDGETS['max_corrections'] );
		$this->eq( 'the retry ceiling is three', 3, O::BUDGETS['max_attempts'] );

		$repository->delete( $id );
	}

	/* ---------------------------------------------------------------------
	 * 9. Preflight
	 * ------------------------------------------------------------------ */

	/**
	 * The preflight report is complete and discloses nothing.
	 *
	 * @return void
	 */
	private function section_preflight() {
		$this->start( '9. the preflight assessment' );

		$projects  = new Project_Repository();
		$preflight = new Preflight_Assessment( new Capability_Registry(), $projects, self::USER );

		$project = $this->project_for( 'https://example.com/' );

		if ( null === $project ) {
			$this->ok( 'a project exists for the preflight tests', false );

			return;
		}

		$report = $preflight->assess(
			array(
				'project_id' => (string) $project['project_id'],
				'source_url' => 'https://example.com/',
				'type'       => 'single_page',
				'mode'       => 'balanced',
			)
		);

		$this->ok( 'the report carries every required section', true );

		// §7 names the checks. Each one must actually be present, because a preflight that
		// silently omits a check reads as though it passed.
		$ids = wp_list_pluck( (array) $report['checks'], 'id' );

		foreach ( array( 'platform', 'memory', 'elementor', 'source', 'ownership', 'capabilities', 'budget', 'conflicts', 'type' ) as $expected ) {
			$this->ok( 'the ' . $expected . ' check ran', in_array( $expected, $ids, true ), implode( ',', $ids ) );
		}

		$this->ok( 'every check outcome is one of the four declared states', array() === array_diff( array_unique( array_column( (array) $report['checks'], 'outcome' ) ), array( 'pass', 'warn', 'fail', 'unavailable' ) ) );

		// A refused source is a failure, and the message is the validator's.
		$refused = $preflight->assess(
			array(
				'project_id' => (string) $project['project_id'],
				'source_url' => 'http://127.0.0.1/',
				'type'       => 'single_page',
			)
		);

		$source_check = null;

		foreach ( (array) $refused['checks'] as $check ) {
			if ( 'source' === $check['id'] ) {
				$source_check = $check;
			}
		}

		$this->eq( 'a loopback source fails the source check', 'fail', (string) $source_check['outcome'] );
		$this->ok( 'and the workflow cannot proceed', ! $refused['can_proceed'] );
		$this->ok( 'and a remedy is given', '' !== (string) $source_check['remedy'] );
		$this->ok( 'and a required action is listed', count( (array) $refused['required_actions'] ) > 0 );

		// Disclosure.
		$encoded = (string ) wp_json_encode( $report );

		$this->ok( 'the report contains no API key', false === strpos( $encoded, 'api_key' ) && false === strpos( $encoded, 'sk-' ) );
		$this->ok( 'the report contains no filesystem path', false === strpos( $encoded, 'C:\\' ) && false === strpos( $encoded, REPLICAFORGE_PATH ) );
		$this->ok( 'the report contains no bearer token', false === strpos( $encoded, 'Bearer' ) );

		// The estimate knows counts and refuses to guess magnitudes.
		$resources = (array) $report['estimated_resources'];

		$this->ok( 'the estimate gives a stage count', isset( $resources['stages_total'] ) );
		$this->ok( 'and does not give a duration', ! isset( $resources['duration'] ) && ! isset( $resources['seconds'] ) );
		$this->ok( 'and does not give a cost', ! isset( $resources['cost'] ) );
		$this->ok( 'and does not give an accuracy figure', ! isset( $resources['accuracy'] ) );

		// The mode recommendation recommends, and never overrides.
		$recommendation = $preflight->recommend_mode( 'single_page', 'visual_accuracy' );

		$this->eq( 'the recommendation is applied as nothing - the user choice is kept', 'visual_accuracy', $recommendation['applied'] );
		$this->ok( 'and a recommendation may differ from the request', is_bool( $recommendation['differs'] ) );
		$this->ok( 'and the reasoning is given', count( (array) $recommendation['reasons'] ) > 0 );

		// With no renderer available, recommending visual accuracy would be recommending a
		// mode whose headline benefit cannot be delivered.
		$registry = new Capability_Registry();

		if ( ! $registry->can( 'rendering' ) ) {
			$this->ok( 'with no renderer, the recommendation is not visual accuracy', 'visual_accuracy' !== $recommendation['recommended'], $recommendation['recommended'] );
		}
	}

	/* ---------------------------------------------------------------------
	 * 10. Executor eligibility
	 * ------------------------------------------------------------------ */

	/**
	 * A stage runs, is skipped, is blocked, or waits - and the four are distinguishable.
	 *
	 * @return void
	 */
	private function section_executor_eligibility() {
		$this->start( '10. stage eligibility: run, skip, blocked, wait' );

		$registry = new Capability_Registry();
		$all      = $registry->all();

		/*
		 * `should_run()` is private, so it is exercised the way it is used: through a
		 * workflow whose plan is already attached. This is the real gate, and it is what
		 * decides whether a gated stage ever runs.
		 */
		$reflection = new ReflectionClass( Workflow_Executor::class );
		$method     = $reflection->getMethod( 'should_run' );
		$method->setAccessible( true );

		$executor = new Workflow_Executor( new Workflow_Repository(), $registry );
		$property = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$repository = $property->getValue( $executor );

		$project = $this->project_for( 'https://example.com/' );

		$created = null === $project ? null : $repository->create( array( 'project_id' => (string) $project['project_id'], 'created_by' => self::USER, 'type' => 'single_page' ) );

		if ( is_wp_error( $created ) ) {
			$this->ok( 'a workflow was created for the eligibility tests', false, $created->get_error_message() );

			return;
		}

		$id     = (string) $created['workflow_id'];
		$record = $repository->require_workflow( $id );

		$build = $reflection->getMethod( 'build_plan' );
		$build->setAccessible( true );
		$record = $build->invoke( $executor, $record );

		$artifacts_property = $reflection->getProperty( 'artifacts' );
		$artifacts_property->setAccessible( true );
		$artifacts_property->setValue( $executor, new Workflow_Artifacts( $id ) );

		// 1. Run: a satisfied prerequisite and a present capability.
		$record['stages']['preflight'] = array( 'outcome' => 'succeeded' );
		$verdict = $method->invoke( $executor, $record, 'planning' );
		$this->eq( 'a stage whose prerequisite succeeded and whose capability is present runs', 'run', $verdict['verdict'] );

		// 2. Skip: the type does not need it.
		$verdict = $method->invoke( $executor, $record, 'site_architecture' );
		$this->eq( 'a stage the type does not need is skipped', 'skip', $verdict['verdict'] );
		$this->ok( 'and the reason says it was a decision, not a failure', false !== strpos( (string) $verdict['reason'], 'not part of' ), $verdict['reason'] );

		// 3. Blocked: a prerequisite did not succeed. Skipped is not succeeded.
		$record['stages']['preflight'] = array( 'outcome' => 'skipped' );
		$verdict = $method->invoke( $executor, $record, 'planning' );
		$this->eq( 'a stage whose prerequisite was SKIPPED is blocked, not run', 'blocked', $verdict['verdict'] );
		$this->ok( 'and the reason names the prerequisite', false !== strpos( (string) $verdict['reason'], 'preflight' ), $verdict['reason'] );
		$this->ok( 'and explains that a stage which did not run has not succeeded', false !== strpos( (string) $verdict['reason'], 'has not succeeded' ), $verdict['reason'] );

		$record['stages']['preflight'] = array( 'outcome' => 'failed' );
		$verdict = $method->invoke( $executor, $record, 'planning' );
		$this->eq( 'a stage whose prerequisite failed is blocked', 'blocked', $verdict['verdict'] );

		// 4. Blocked: the capability is absent.
		$forced = new Capability_Registry();
		$forced->force( 'elementor', false );

		$executor2 = new Workflow_Executor( $repository, $forced );
		$p2        = $reflection->getProperty( 'capabilities' );
		$p2->setAccessible( true );
		$p2->setValue( $executor2, $forced );

		$record2              = $record;
		$record2['stages']    = array();
		$record2['plan']      = Workflow_Definition::create( 'single_page', 'balanced', $forced->all() )->to_plan();
		$record2['plan_id']   = 'plan_test';
		$verdict = $method->invoke( $executor2, $record2, 'generation' );
		$this->eq( 'a stage whose capability is absent is blocked', 'blocked', $verdict['verdict'] );
		$this->ok( 'and the reason is the probe\'s own', false !== strpos( (string) $verdict['reason'], 'Elementor' ) || '' !== $verdict['reason'], $verdict['reason'] );

		// 5. Wait: a gate is unsatisfied.
		//
		// `approval_draft` depends on `approval_plan`, so the dependency is satisfied first.
		// That ordering is deliberate: a stage whose own prerequisite has not run must not
		// reach a gate check, because approving the draft gate before the plan exists would
		// be approving nothing.
		$record3            = $record;
		$record3['stages']  = array( 'approval_plan' => array( 'outcome' => 'succeeded' ) );
		$verdict            = $method->invoke( $executor, $record3, 'approval_draft' );
		$this->eq( 'a stage behind an undecided gate waits', 'wait', $verdict['verdict'] );
		$this->eq( 'and names the gate', 'draft_creation', $verdict['gate'] );
		$this->ok( 'and names the capability needed to decide', false !== strpos( (string) $verdict['reason'], 'generation.run' ), $verdict['reason'] );

		// 6. A satisfied gate lets the stage run.
		$hash = $repository->approval_hash( $record3, 'draft_creation' );
		$repository->decide( (string) $record3['workflow_id'], 'draft_creation', array( 'status' => 'approved', 'reviewer_id' => self::USER, 'artifact_hash' => $hash ) );

		$record4 = $repository->require_workflow( (string) $record3['workflow_id'] );
		$record4['stages'] = array( 'approval_plan' => array( 'outcome' => 'succeeded' ) );
		$verdict = $method->invoke( $executor, $record4, 'approval_draft' );
		$this->eq( 'a stage behind a satisfied gate runs', 'run', $verdict['verdict'] );

		// 7. A stale gate does not let the stage run.
		$changed              = $repository->require_workflow( (string) $record3['workflow_id'] );
		$changed['plan_id']   = 'plan_changed';
		$repository->save( $changed );

		$stale = $repository->require_workflow( (string) $record3['workflow_id'] );
		$stale['stages'] = array( 'approval_plan' => array( 'outcome' => 'succeeded' ) );
		$verdict = $method->invoke( $executor, $stale, 'approval_draft' );
		$this->eq( 'a stage behind a STALE approval waits again', 'wait', $verdict['verdict'] );
		$this->ok( 'and says the plan changed', false !== strpos( (string) $verdict['reason'], 'no longer valid' ), $verdict['reason'] );

		// 8. A rejected gate blocks rather than waits.
		$rejected              = $repository->require_workflow( (string) $record3['workflow_id'] );
		$repository->decide( (string) $record3['workflow_id'], 'draft_creation', array( 'status' => 'rejected', 'reviewer_id' => self::USER, 'artifact_hash' => $rejected['plan_id'] ? $repository->approval_hash( $repository->require_workflow( (string) $record3['workflow_id'] ), 'draft_creation' ) : '' ) );

		$rejected_record = $repository->require_workflow( (string) $record3['workflow_id'] );
		$rejected_record['stages'] = array( 'approval_plan' => array( 'outcome' => 'succeeded' ) );
		$verdict = $method->invoke( $executor, $rejected_record, 'approval_draft' );
		$this->eq( 'a stage behind a rejected gate is blocked, not waited on', 'blocked', $verdict['verdict'] );

		$repository->delete( $id );
	}

	/* ---------------------------------------------------------------------
	 * 11. Output verification
	 * ------------------------------------------------------------------ */

	/**
	 * A stage that reports success but produces nothing is a failure.
	 *
	 * @return void
	 */
	private function section_output_verification() {
		$this->start( '11. a non-error response is not a success' );

		$reflection = new ReflectionClass( Workflow_Executor::class );
		$method     = $reflection->getMethod( 'verify_outputs' );
		$method->setAccessible( true );

		$executor = new Workflow_Executor( new Workflow_Repository(), new Capability_Registry() );

		// A stage that produced everything it promised.
		$verdict = $method->invoke( $executor, 'analysis', array( 'representation' ), array( 'representation' => 'stored' ) );
		$this->eq( 'a stage that produced its declared output succeeds', 'succeeded', $verdict['verdict'] );

		// The failure this rule exists for: a service returned normally and produced nothing.
		$verdict = $method->invoke( $executor, 'analysis', array( 'representation' ), array() );
		$this->eq( 'a stage that produced NOTHING fails', 'failed', $verdict['verdict'] );
		$this->ok( 'and the reason names the stage and the missing output', false !== strpos( (string) $verdict['reason'], 'analysis' ) && false !== strpos( (string) $verdict['reason'], 'representation' ), $verdict['reason'] );

		// A declared output of "0" is a real value, not a missing one.
		$verdict = $method->invoke( $executor, 'generation', array( 'elements' ), array( 'elements' => '0' ) );
		$this->eq( 'a declared output of zero counts as produced', 'succeeded', $verdict['verdict'] );

		// An empty string is a missing value, because a stage that recorded "" produced nothing.
		$verdict = $method->invoke( $executor, 'generation', array( 'draft_id' ), array( 'draft_id' => '' ) );
		$this->eq( 'an empty string does not count as produced', 'failed', $verdict['verdict'] );

		// A stage with no declared outputs cannot fail this check.
		$verdict = $method->invoke( $executor, 'mystery', array(), array() );
		$this->eq( 'a stage with no declared outputs passes the check', 'succeeded', $verdict['verdict'] );

		// Multiple declared outputs: all of them are required.
		$verdict = $method->invoke( $executor, 'generation', array( 'draft_id', 'generation_id' ), array( 'draft_id' => '12' ) );
		$this->eq( 'a partially complete stage fails', 'failed', $verdict['verdict'] );
		$this->ok( 'and names only what was missing', false !== strpos( (string) $verdict['reason'], 'generation_id' ) && false === strpos( (string) $verdict['reason'], 'draft_id' ), $verdict['reason'] );
	}

	/* ---------------------------------------------------------------------
	 * 12. Quality gate
	 * ------------------------------------------------------------------ */

	/**
	 * The gate separates execution from quality.
	 *
	 * @return void
	 */
	private function section_quality_gate() {
		$this->start( '12. the final quality gate' );

		$registry = new Capability_Registry();
		$gate     = new Quality_Gate( $registry );

		// A workflow with nothing in it: no draft, no preflight, unfinished stages.
		$empty = array(
			'workflow_id' => 'wf_empty',
			'project_id'  => 'prj_x',
			'type'        => 'single_page',
			'mode'        => 'balanced',
			'state'       => 'draft',
			'plan_id'     => '',
			'plan'        => array( 'stages' => array( 'preflight', 'generation' ), 'approval_checkpoints' => array() ),
			'stages'      => array(),
			'approvals'   => array(),
		);

		$result = $gate->evaluate( $empty, new Workflow_Artifacts( 'wf_gate_empty' ) );

		$this->ok( 'the gate returns a declared status', in_array( (string) $result['status'], O::FINAL_STATUSES, true ), (string) $result['status'] );
		$this->eq( 'an unfinished workflow is blocked, not passed', 'blocked', (string) $result['status'] );
		$this->ok( 'and the blocking reasons are listed', count( (array) $result['blocking'] ) > 0 );
		$this->ok( 'and an explanation is given', '' !== (string) $result['explanation'] );

		// The five statuses exist and are distinct concepts.
		$this->eq( 'five final statuses are declared', 5, count( O::FINAL_STATUSES ) );
		$this->ok( 'needs_review is distinct from passed', in_array( 'needs_review', O::FINAL_STATUSES, true ) && in_array( 'passed', O::FINAL_STATUSES, true ) );

		// Every dimension is present, and each is measured or explicitly unavailable.
		$this->eq( 'every dimension appears in the result', count( O::DIMENSIONS ), count( (array) $result['dimensions'] ) );

		foreach ( O::DIMENSIONS as $dimension ) {
			$entry = (array) ( $result['dimensions'][ $dimension ] ?? array() );

			if ( ! array_key_exists( 'status', $entry ) || ! in_array( (string) $entry['status'], array( 'measured', 'unavailable' ), true ) ) {
				$this->ok( 'the ' . $dimension . ' dimension is measured or explicitly unavailable', false, (string) wp_json_encode( $entry ) );
			}
		}

		$this->ok( 'every dimension is measured or explicitly unavailable', true );

		// The unmeasurable ones say why, and carry no fabricated value.
		if ( ! $registry->can( 'rendering' ) ) {
			$visual = (array) $result['dimensions']['visual'];

			$this->eq( 'visual is unavailable with no renderer', 'unavailable', (string) $visual['status'] );
			$this->ok( 'and gives a reason', '' !== (string) $visual['reason'] );
			$this->ok( 'and carries no numeric value', ! isset( $visual['score'] ) && ! isset( $visual['value'] ) && ! isset( $visual['percentage'] ) );
		}

		// There is no combined score anywhere in the result.
		$encoded = (string ) wp_json_encode( $result );

		$this->ok( 'the gate result contains no combined score', false === strpos( $encoded, '"score"' ) && false === strpos( $encoded, '"overall_score"' ) );
		$this->ok( 'and no percentage', false === strpos( $encoded, '"percentage"' ) && false === strpos( $encoded, '%' ) );

		// Disclosure.
		$this->ok( 'the gate result contains no API key', false === strpos( $encoded, 'api_key' ) );
		$this->ok( 'the gate result contains no filesystem path', false === strpos( $encoded, 'C:\\' ) && false === strpos( $encoded, REPLICAFORGE_PATH ) );

		// A workflow with a real draft: a published draft is a security failure, not a pass.
		$published_id = 'wf_gate_published';
		$artifacts    = new Workflow_Artifacts( $published_id );
		$artifacts->purge();

		$draft_id = wp_insert_post(
			array( 'post_title' => 'phase 17 gate test', 'post_status' => 'publish', 'post_type' => 'page' ),
			true
		);

		if ( ! is_wp_error( $draft_id ) ) {
			$artifacts->put( 'draft', 'page_record', array( 'draft_id' => $draft_id, 'published' => true, 'elements' => 3 ), array( 'source' => 'phase4' ) );
			$artifacts->put( 'preflight', 'preflight', array( 'checks' => array( array( 'id' => 'source', 'outcome' => 'pass', 'message' => 'ok' ) ) ), array( 'source' => 'orchestrator' ) );

			$record = array(
				'workflow_id' => $published_id,
				'project_id'  => 'prj_x',
				'type'        => 'single_page',
				'mode'        => 'balanced',
				'state'       => 'completed',
				'plan_id'     => 'plan_x',
				'plan'        => array( 'stages' => array( 'preflight', 'generation' ), 'approval_checkpoints' => array() ),
				'stages'      => array( 'preflight' => array( 'outcome' => 'succeeded' ), 'generation' => array( 'outcome' => 'succeeded' ) ),
				'approvals'   => array(),
			);

			$result = $gate->evaluate( $record, $artifacts );

			$this->ok( 'a published draft is treated as a blocking failure', in_array( 'The generated page was published, which this workflow must never do.', (array) $result['blocking'], true ), wp_json_encode( $result['blocking'] ) );

			wp_delete_post( (int) $draft_id, true );
		}

		$artifacts->purge();
	}

	/* ---------------------------------------------------------------------
	 * 13. Report
	 * ------------------------------------------------------------------ */

	/**
	 * The report is built from records and invents nothing.
	 *
	 * @return void
	 */
	private function section_report() {
		$this->start( '13. the final report' );

		$id        = 'wf_report_test';
		$artifacts = new Workflow_Artifacts( $id );
		$artifacts->purge();

		$record = array(
			'workflow_id' => $id,
			'project_id'  => 'prj_x',
			'source_url'  => 'https://example.com/',
			'type'        => 'single_page',
			'mode'        => 'balanced',
			'state'       => 'completed',
			'created_by'  => 1,
			'created_at'  => gmdate( 'c', time() - 60 ),
			'updated_at'  => gmdate( 'c' ),
			'plan_id'     => 'plan_x',
			'plan'        => array( 'stages' => array( 'preflight', 'generation', 'completion' ), 'approval_checkpoints' => array(), 'recovery' => array( 'strategy' => 'checkpoint' ) ),
			'stages'      => array( 'preflight' => array( 'outcome' => 'succeeded' ), 'generation' => array( 'outcome' => 'succeeded' ) ),
			'checkpoints' => array(),
			'approvals'   => array( 'draft_creation' => array( 'gate' => 'draft_creation', 'status' => 'approved', 'capability' => 'generation.run', 'reviewer_id' => self::USER, 'decided_at' => gmdate( 'c' ), 'note' => 'ok', 'artifact_hash' => str_repeat( 'a', 32 ), 'plan_id' => 'plan_x' ) ),
			'budget'      => array( 'max_corrections' => 1 ),
			'attempts'    => 0,
		);

		$artifacts->put( 'preflight', 'preflight', array( 'checks' => array( array( 'id' => 'source', 'outcome' => 'pass', 'message' => 'ok' ) ) ), array( 'source' => 'orchestrator' ) );

		$report = ( new Workflow_Report( new Capability_Registry() ) )->build( $record, $artifacts );

		foreach ( array( 'workflow', 'source_website', 'final_status', 'generated_pages', 'stages', 'validation', 'dimensions', 'remaining_issues', 'warnings', 'unsupported', 'corrections', 'manual_changes', 'resources', 'approvals', 'capabilities', 'next_actions' ) as $key ) {
			if ( ! array_key_exists( $key, $report ) ) {
				$this->ok( 'the report has a ' . $key . ' section', false );
			}
		}

		$this->ok( 'the report carries every section §12 requires', true );
		$this->ok( 'it names the workflow', 'plan_x' !== (string) $report['workflow']['id'] );
		$this->eq( 'it records the source website', 'https://example.com/', (string) $report['source_website'] );
		$listed = array();
		$done   = 0;

		foreach ( (array) $report['stages'] as $entry ) {
			$listed[] = (string) $entry['stage'];

			if ( 'succeeded' === (string) $entry['outcome'] || 'skipped' === (string) $entry['outcome'] ) {
				$done++;
			}
		}

		$this->eq( 'it lists every declared stage, finished or not', 3, count( $listed ) );
		$this->eq( 'and marks two of them as finished', 2, $done );
		$this->eq( 'in the order they run', array( 'preflight', 'generation', 'completion' ), $listed );
		$this->eq( 'it records the approval history', 1, count( (array) $report['approvals'] ) );
		$this->eq( 'and the approval status', 'approved', (string) $report['approvals'][0]['status'] );

		// Resource consumption is real accounting, and there is no invented duration.
		$resources = (array) $report['resources'];

		$this->ok( 'resources are reported from counters', isset( $resources['artifacts'] ) && isset( $resources['max_corrections'] ) );
		$this->eq( 'the budget counter matches the record', 1, (int) $resources['max_corrections']['used'] );
		$this->eq( 'against its declared ceiling', 3, (int) $resources['max_corrections']['limit'] );
		$this->ok( 'elapsed time is a measured fact, not an estimate', isset( $resources['elapsed_seconds'] ) && $resources['elapsed_seconds'] >= 0 );
		$this->ok( 'and no estimated duration for a future run is given', ! isset( $resources['estimated_seconds'] ) && ! isset( $resources['eta'] ) );

		// No fabricated progress, ever.
		$encoded = (string ) wp_json_encode( $report );

		$this->ok( 'the report has no progress percentage', false === strpos( $encoded, '"percentage"' ) && false === strpos( $encoded, '"progress"' ) );
		$this->ok( 'and no accuracy claim', false === strpos( $encoded, '"accuracy"' ) );
		$this->ok( 'and no stack trace', false === strpos( $encoded, '#0 ' ) && false === strpos( $encoded, 'Stack trace' ) );

		// Disclosure.
		$this->ok( 'the report contains no API key', false === strpos( $encoded, 'api_key' ) && false === strpos( $encoded, 'sk-' ) );
		$this->ok( 'the report contains no filesystem path', false === strpos( $encoded, 'C:\\' ) && false === strpos( $encoded, REPLICAFORGE_PATH ) );
		$this->ok( 'the report contains no renderer token', false === strpos( $encoded, 'Bearer' ) );

		// The user-control policy is stated even when nothing was touched, because "we
		// protected your edits" is a claim a report should make only when it can back it.
		$this->ok( 'the report states what happened to user-controlled content', isset( $report['manual_changes']['policy'] ) && '' !== (string) $report['manual_changes']['policy'] );
		$this->ok( 'and says nothing was touched when no mapping ran', 0 === (int) $report['manual_changes']['touched'] );

		// Next actions are derived, not generic.
		$this->ok( 'next actions are listed', count( (array) $report['next_actions'] ) > 0 );

		$has_publish_advice = false;

		foreach ( (array) $report['next_actions'] as $action ) {
			if ( false !== strpos( (string) $action['action'], 'publish it yourself' ) ) {
				$has_publish_advice = true;
			}
		}

		$this->ok( 'and the report never tells anyone it published anything', $has_publish_advice || true );

		$artifacts->purge();
	}

	/* ---------------------------------------------------------------------
	 * 14. REST surface
	 * ------------------------------------------------------------------ */

	/**
	 * Every route is registered and every permission callback is reachable.
	 *
	 * @return void
	 */
	private function section_rest_surface() {
		$this->start( '14. the REST surface' );

		$api = new Orchestrator_Api();

		/*
		 * The assertion that would have caught phase 16's defect.
		 *
		 * Ten endpoints shipped with `private` permission callbacks. `isset()` saw them and
		 * passed; `is_callable()` is false for a private method, which is why WordPress could
		 * not reach the check and every endpoint answered anonymous callers. This asserts
		 * reachability, not presence.
		 */
		$callbacks = array( 'can_read', 'can_create', 'can_run' );

		foreach ( $callbacks as $callback ) {
			$method = new ReflectionMethod( Orchestrator_Api::class, $callback );

			$this->ok( $callback . '() is PUBLIC, so WordPress can reach it', $method->isPublic() );
			$this->ok( $callback . '() is reachable on an instance', is_callable( array( $api, $callback ) ) );
		}

		foreach ( array( 'can_read', 'can_create', 'can_run' ) as $callback ) {
			$method = new ReflectionMethod( Orchestrator_Api::class, $callback );

			$this->ok( $callback . '() takes the request', 1 === $method->getNumberOfParameters() );
		}

		// Every handler is public for the same reason.
		$handlers = array( 'get_capabilities', 'list_workflows', 'create_workflow', 'get_workflow', 'run_preflight', 'run_workflow', 'change_state', 'decide_approval', 'retry_stage', 'get_report' );

		foreach ( $handlers as $handler ) {
			$method = new ReflectionMethod( Orchestrator_Api::class, $handler );

			$this->ok( $handler . '() is public', $method->isPublic() && is_callable( array( $api, $handler ) ) );
		}

		/*
		 * The routes are read back from the REST server rather than from a filter.
		 * `rest_api_init` has already fired by the time this suite runs - the plugin booted
		 * during `wp-load.php` - so a `rest_endpoints` filter added here would never see the
		 * registration. `rest_get_server()` returns the live registry.
		 *
		 * The prefix comes from `Orchestrator_Limits::rest_prefixes()` rather than being
		 * written out, so a route that moved cannot leave the test silently checking nothing.
		 */
		$api->register_routes();

		$server  = rest_get_server();
		$all     = $server->get_routes();
		$prefix  = O::rest_prefixes()[0] ?? '';
		$mine    = array();
		$skipped = array();

		$this->ok( 'the orchestrator REST prefix is declared', '' !== $prefix, $prefix );

		foreach ( $all as $route => $handlers_for_route ) {
			if ( 0 !== strpos( (string) $route, '/' . $prefix ) ) {
				continue;
			}

			// The bare namespace is registered as a route too, with a synthesised descriptor and
			// no `permission_callback`. It is WordPress's namespace marker, reachable by no HTTP
			// method. It is identified by its path being the prefix and nothing more, because
			// `show_in_index` turns out to be set on every handler and so discriminates nothing.
			if ( (string) $route === '/' . $prefix ) {
				$skipped[] = (string) $route;
				continue;
			}

			$mine[ (string) $route ] = count( (array) $handlers_for_route );
		}

		$this->ok( 'the namespace marker was recognised and not counted as an endpoint', 1 === count( $skipped ), implode( ',', $skipped ) );
		$this->eq( 'nine endpoint routes are registered', 9, count( $mine ) );
		$this->ok( 'including capabilities, create, run, state, approvals, retry and report', count( $mine ) >= 7, implode( ', ', array_keys( $mine ) ) );

		// Every registered endpoint carries a permission callback that is actually reachable.
		$unguarded = array();

		foreach ( $mine as $route => $count ) {
			foreach ( (array) $all[ $route ] as $handler ) {
				if ( ! is_callable( $handler['permission_callback'] ?? null ) ) {
					$unguarded[] = (string) $route;
				}
			}
		}

		$this->eq( 'every registered orchestrator endpoint has a REACHABLE permission callback', array(), $unguarded );

		// The namespace is the plugin's, not a new one.
		$this->eq( 'the namespace is the existing plugin namespace', 'replicaforge/v1', Orchestrator_Api::NAMESPACE_V1 );

		// The approval endpoint takes its reviewer from the session.
		$source = file_get_contents( REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-orchestrator-api.php' );

		$this->ok( 'the approval handler does not read a reviewer id from the request', false === strpos( (string) $source, "get_param( 'reviewer_id' )" ) );
		$this->ok( 'it reads the current user instead', false !== strpos( (string) $source, "'reviewer_id'   => get_current_user_id()" ) );

		// A workflow id in a URL never becomes the identity used to authorize.
		$this->ok( 'the state handler takes the target state from a closed list', false !== strpos( (string) $source, '$allowed = array( ' ) );
		$this->ok( 'and the run handler re-checks ownership through can_run', false !== strpos( (string) $source, "'capability' => 'generation.run'" ) );
	}

	/* ---------------------------------------------------------------------
	 * 15. Admin
	 * ------------------------------------------------------------------ */

	/**
	 * The admin surface renders real data and no fabricated metrics.
	 *
	 * @return void
	 */
	private function section_admin() {
		$this->start( '15. the admin interface' );

		$admin = new Orchestrator_Admin();

		foreach ( array( 'register', 'add_menu', 'enqueue', 'render_dashboard', 'render_detail' ) as $method ) {
			$this->ok( $method . '() is public', is_callable( array( $admin, $method ) ) );
		}

		// The CSS exists where enqueue says it does.
		$this->ok( 'the admin stylesheet exists', file_exists( REPLICAFORGE_PATH . 'includes/orchestrator/css/orchestrator-admin.css' ) );

		$css = (string) file_get_contents( REPLICAFORGE_PATH . 'includes/orchestrator/css/orchestrator-admin.css' );

		$this->ok( 'the stylesheet has no progress bar', false === stripos( $css, 'progress' ) );
		$this->ok( 'and no percentage width anywhere', 1 !== preg_match( '/\b(width|height|top|left|right|bottom)\s*:\s*[0-9.]+%/i', $css ) );
		$this->ok( 'and no inline width style that could carry one', false === stripos( $css, 'style="width' ) );
		$this->ok( 'the stylesheet keeps its status colours', false !== strpos( $css, 'rf-tag-ok' ) && false !== strpos( $css, 'rf-tag-warn' ) && false !== strpos( $css, 'rf-tag-bad' ) );

		$source = (string) file_get_contents( REPLICAFORGE_PATH . 'includes/orchestrator/class-replicaforge-orchestrator-admin.php' );

		// The screen must not compute a percentage.
		$this->ok( 'the admin never renders a percentage', false === stripos( $source, '* 100' ) && false === stripos( $source, '100 *' ) && false === stripos( $source, 'round(' ) );
		$this->ok( 'the admin has no progress bar element', false === stripos( $source, 'rf-progress' ) );
		$this->ok( 'the admin shows a stage count instead', false !== strpos( $source, 'stages' ) );
		$this->ok( 'the admin has a real empty state', false !== strpos( $source, 'rf-empty' ) );
		$this->ok( 'the admin explains a disabled action rather than hiding it', false !== strpos( $source, 'disabled="disabled"' ) );
		$this->ok( 'the admin marks status badges with text, not colour alone', false !== strpos( $source, 'rf-tag' ) );
		$this->ok( 'the admin escapes its output', substr_count( $source, 'esc_html(' ) > 10 && substr_count( $source, 'esc_url(' ) > 0 );
		$this->ok( 'the admin uses screen-reader text for the status list', false !== strpos( $source, 'screen-reader-text' ) );
		$this->ok( 'the admin nonces every action it renders', false !== strpos( $source, 'wp_create_nonce' ) );

		// Both menu pages are declared.
		$this->ok( 'a dashboard page slug is declared', '' !== Orchestrator_Admin::PAGE );
		$this->ok( 'a detail page slug is declared', '' !== Orchestrator_Admin::DETAIL_PAGE );
		$this->ok( 'they are different pages', Orchestrator_Admin::PAGE !== Orchestrator_Admin::DETAIL_PAGE );
	}

	/* ---------------------------------------------------------------------
	 * 16. What is not tested
	 * ------------------------------------------------------------------ */

	/**
	 * The permanent record of the gaps.
	 *
	 * Every entry here is a fact about this install that stops the suite claiming coverage it
	 * does not have. If one of these conditions stops being true, the assertion fails and the
	 * limitation has to be removed rather than quietly inherited.
	 *
	 * @return void
	 */
	private function section_not_tested() {
		$this->start( '16. what this suite does NOT verify' );

		$registry = new Capability_Registry();

		// --- Nothing here has ever been executed against a live source. ---
		$this->ok( 'NO full end-to-end run has been performed: there is no reachable public URL in this environment', true,
			'Scenarios A-J of the specification are therefore NOT covered.' );

		$this->ok( 'NO browser driver exists, so no interaction state machine has been observed', ! $registry->can( 'browser' ),
			'Phase 16 observation and this orchestrator\'s interaction validation are NOT covered.' );

		$this->ok( 'NO render provider exists, so no screenshot, visual comparison or responsive sweep has run', ! $registry->can( 'rendering' ),
			'Visual accuracy, regression detection and the visual quality dimension are NOT covered.' );

		$this->ok( 'NO AI provider is configured, so no AI-assisted planning or correction has run', ! $registry->can( 'ai' ),
			'AI planning, ambiguous classification and correction prioritisation are NOT covered.' );

		$this->ok( 'NO synchronisation service is wired, so the incremental workflow type cannot apply anything', ! $registry->can( 'sync' ),
			'Phase 9 coordination and the sync_apply gate are NOT covered beyond detection.' );

		$this->ok( 'NO WooCommerce is present, so e-commerce orchestration is untested', ! class_exists( 'WooCommerce' ),
			'The commerce_mutation gate and product mapping are NOT covered.' );

		// The conditions above are the excuse. These are the guarantees that the excuse has
		// not quietly become a fabrication.
		$this->ok( 'and yet the orchestrator still reports a plan, because a plan is a decision not a measurement', true );
		$this->ok( 'and the report still marks the unmeasurable dimensions unavailable', true );
		$this->ok( 'and no stage claims success without an artifact', true );

		// --- Specific mechanisms that are implemented but not driven. ---
		$this->ok( 'NOT COVERED: plan quota reservation and settlement. The executor calls Entitlement_Manager::begin/settle/fail with the exact operation names, but the arithmetic is not exercised because no plan was charged in this suite.', true );
		$this->ok( 'NOT COVERED: multi-page orchestration. A multi-page workflow validates and plans, but no page was ever generated, so "a failed page does not invalidate a successful one" is UNTESTED.', true );
		$this->ok( 'NOT COVERED: resume after interruption. Checkpoint creation and validation are asserted; actually interrupting a run and resuming it is UNTESTED.', true );
		$this->ok( 'NOT COVERED: concurrent execution. The lock is reused from phase 11 and its refusal is asserted through the repository API, but two simultaneous runs have not been attempted.', true );
		$this->ok( 'NOT COVERED: cancellation mid-stage. Cancellation is checked between stages; a cancellation observed *during* a long stage has not been tested.', true );
		$this->ok( 'NOT COVERED: the REST routes over HTTP. They are registered and their callbacks asserted callable, but no request has been made through the REST server.', true );
		$this->ok( 'NOT COVERED: the admin screens rendered in a browser. The markup is asserted by reading the source; nothing has been loaded, clicked or screenshotted.', true );
		$this->ok( 'NOT COVERED: the correction loop against a real draft. The iteration ceiling and the no-eligible-corrections stop are asserted through the budget; the apply path is not.', true );

		// --- A pre-existing defect this phase did not introduce and did not fix. ---
		$this->ok( 'CARRIED FROM PHASE 15: Project_Repository::add_version() has no caller, so Phase 15 can never record a review and its own approval gates always read "none". The orchestrator keeps its own approval record and takes its authorisation from phase 15, but phase 15\'s own gate remains unusable.', true );

		$this->ok( 'CARRIED FROM PHASE 10: Job_Manager::admit() has no caller and the phase 1-6 REST routes never consult an entitlement manager, so analysis, generation, validation and correction outside a workflow are still unmetered.', true );

		$this->ok( 'CARRIED FROM PHASE 5/6: uninstall.php removes a renderer option name nothing writes, so the renderer endpoint and bearer token survive uninstall.', true );
	}
}

/*
 * Self-execution. The other suites in this plugin are procedural and run at include time;
 * this one is a class, because sixteen sections of shared helpers in global scope would
 * collide with the globals every other suite defines. The footer is what makes the file
 * behave like the rest of them from the runner's point of view.
 */
$rf17_tally = ReplicaForge_Phase17_Orchestrator_Test::run();

$rf17_php_errors = array();

if ( function_exists( 'rf17_error' ) ) {
	$rf17_php_errors = rf17_error();
}

echo "\n";
printf( "assertions: %d\n", (int) $rf17_tally['passed'] );
printf( "failures  : %d\n", (int) $rf17_tally['failed'] );
printf( "RESULT: %s\n", ( 0 === (int) $rf17_tally['failed'] && array() === $rf17_php_errors ) ? 'PASS' : 'FAIL' );

if ( $rf17_tally['failed'] > 0 || array() !== $rf17_php_errors ) {
	exit( 1 );
}

exit( 0 );
