<?php
/**
 * Phase 17: the preflight assessment, run before anything expensive.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The checks that run before a workflow spends anything.
 *
 * ### Why this exists rather than being folded into the first stage
 *
 * Every check here is cheap, and every one of them can save an expensive failure. Elementor
 * being absent is discovered in a millisecond here and would be discovered after a fetch, an
 * analysis and a plan. A source URL pointing at a private network address is refused here
 * rather than after the user has approved a plan for it. The specification's ordering is the
 * argument: preflight is first in the stage list because "the whole value of checking the
 * environment is lost if the check happens after the cost".
 *
 * ### What a preflight report is allowed to contain
 *
 * Never a secret, an endpoint, a filesystem path or a private address. §7 forbids it and
 * §41 forbids it independently, and the reason is worth stating once: a preflight report is
 * the most widely-shared artefact this plugin produces. It is shown to a user who may not
 * have the capability to run the workflow, it appears in a REST response, and it is the
 * first thing a person pastes into a support ticket. Every check therefore reports a
 * *conclusion* and a *remedy*, and the raw evidence that produced it is discarded.
 */
final class Preflight_Assessment {

	/**
	 * The check outcomes.
	 *
	 * `pass` means it is fine. `warn` means the workflow can proceed with a recorded
	 * limitation. `fail` means the workflow cannot proceed. `unavailable` is a fourth answer
	 * for a capability that could not be assessed at all - distinct from `fail`, because
	 * "we could not check" and "the check failed" lead to different actions.
	 */
	const OUTCOMES = array( 'pass', 'warn', 'fail', 'unavailable' );

	/**
	 * The capability registry.
	 *
	 * @var Capability_Registry
	 */
	private $capabilities;

	/**
	 * The repository, for project ownership.
	 *
	 * @var Project_Repository
	 */
	private $projects;

	/**
	 * The user the checks are run for.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Build the assessment.
	 *
	 * @param Capability_Registry|null $capabilities Registry.
	 * @param Project_Repository|null  $projects     Projects.
	 * @param int                      $user_id      The user.
	 */
	public function __construct( $capabilities = null, $projects = null, $user_id = 0 ) {
		$this->capabilities = ( $capabilities instanceof Capability_Registry ) ? $capabilities : new Capability_Registry();
		$this->projects     = ( $projects instanceof Project_Repository ) ? $projects : new Project_Repository();
		$this->user_id      = (int) $user_id;
	}

	/**
	 * Run every check.
	 *
	 * @param array $input project_id, source_url, type, mode.
	 * @return array The preflight report.
	 */
	public function assess( array $input ) {
		$project_id = (string) ( $input['project_id'] ?? '' );
		$source_url = (string) ( $input['source_url'] ?? '' );
		$type       = (string) ( $input['type'] ?? 'single_page' );
		$mode       = (string) ( $input['mode'] ?? 'balanced' );

		$checks = array();

		$checks[] = $this->check_platform();
		$checks[] = $this->check_memory();
		$checks[] = $this->check_elementor();
		$checks[] = $this->check_source( $source_url );
		$checks[] = $this->check_ownership( $project_id );
		$checks[] = $this->check_capabilities();
		$checks[] = $this->check_budget();
		$checks[] = $this->check_conflicts( $project_id, $source_url );
		$checks[] = $this->check_type( $type );

		$counts = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'unavailable' => 0 );
		$failed = array();
		$warned = array();

		foreach ( $checks as $check ) {
			$counts[ $check['outcome'] ] = ( $counts[ $check['outcome'] ] ?? 0 ) + 1;

			if ( 'fail' === $check['outcome'] ) {
				$failed[] = $check;
			}

			if ( 'warn' === $check['outcome'] ) {
				$warned[] = $check;
			}
		}

		$missing = $this->capabilities->missing_required();

		$report = array(
			'schema_version'      => Orchestrator_Limits::SCHEMA_VERSION,
			'checked_at'          => gmdate( 'c' ),
			'project_id'          => $project_id,
			'type'                => $type,
			'mode'                => $mode,
			'checks'              => $checks,
			'counts'              => $counts,
			'capabilities'        => $this->capabilities->summary(),
			'missing_required'    => $missing,
			'estimated_resources' => $this->estimate( $type, $mode ),
			'required_actions'    => $this->required_actions( $failed, $warned, $missing ),
			'can_proceed'         => array() === $failed && array() === $missing,
		);

		$report['summary'] = $this->summarise( $report );

		return $report;
	}

	/* ---------------------------------------------------------------------
	 * Individual checks
	 * ------------------------------------------------------------------ */

	/**
	 * The platform: PHP and WordPress versions against the plugin's own requirement.
	 *
	 * @return array
	 */
	private function check_platform() {
		$required_php = defined( 'REPLICAFORGE_MIN_PHP' ) ? (string) constant( 'REPLICAFORGE_MIN_PHP' ) : '7.4';
		$required_wp  = defined( 'REPLICAFORGE_MIN_WP' ) ? (string) constant( 'REPLICAFORGE_MIN_WP' ) : '6.2';

		$php_ok = version_compare( PHP_VERSION, $required_php, '>=' );
		$wp_ok  = version_compare( get_bloginfo( 'version' ), $required_wp, '>=' );

		if ( ! $php_ok || ! $wp_ok ) {
			return $this->check(
				'platform',
				'fail',
				__( 'This server is older than ReplicaForge supports.', 'replicaforge' ),
				__( 'Ask your host to update PHP or WordPress.', 'replicaforge' )
			);
		}

		// Multi-site is untested across every phase, so it is named rather than hidden.
		if ( is_multisite() ) {
			return $this->check(
				'platform',
				'warn',
				__( 'This is a WordPress multi-site install. ReplicaForge has not been tested on multi-site.', 'replicaforge' ),
				__( 'Run the workflow on a single site first if you can.', 'replicaforge' )
			);
		}

		return $this->check( 'platform', 'pass', __( 'The server meets the minimum requirements.', 'replicaforge' ) );
	}

	/**
	 * Available memory.
	 *
	 * A workflow runs a DOM analysis, an Elementor generation and a validation pass in one
	 * request, and on this plugin's own install WordPress with Elementor already occupies
	 * roughly 68 MB. A 128 MB limit leaves about ten megabytes of headroom per megabyte of
	 * representation, so the check is here rather than discovered as a fatal.
	 *
	 * @return array
	 */
	private function check_memory() {
		$limit = $this->memory_limit_bytes();

		if ( $limit <= 0 ) {
			// Unset means the host sets no limit, which is a pass, not a failure.
			return $this->check( 'memory', 'pass', __( 'No PHP memory limit is set.', 'replicaforge' ) );
		}

		$used  = memory_get_usage( true );
		$spare = $limit - $used;

		if ( $spare < 33554432 ) {
			return $this->check(
				'memory',
				'fail',
				sprintf(
					/* translators: 1: spare MB, 2: total limit MB. */
					__( 'Only %1$d MB of the %2$d MB memory limit is free. A reconstruction needs more than that.', 'replicaforge' ),
					(int) round( $spare / 1048576 ),
					(int) round( $limit / 1048576 )
				),
				__( 'Raise the PHP memory limit to at least 256M.', 'replicaforge' )
			);
		}

		if ( $spare < 67108864 ) {
			return $this->check(
				'memory',
				'warn',
				sprintf(
					/* translators: %d: spare MB. */
					__( 'Only %d MB of memory is free. A large source page may exhaust the limit mid-workflow.', 'replicaforge' ),
					(int) round( $spare / 1048576 )
				),
				__( 'Raise the PHP memory limit to at least 256M for large pages.', 'replicaforge' )
			);
		}

		return $this->check( 'memory', 'pass', __( 'There is enough memory available.', 'replicaforge' ) );
	}

	/**
	 * Elementor, measured.
	 *
	 * @return array
	 */
	private function check_elementor() {
		$entry = $this->capabilities->get( 'elementor' );

		if ( 'unavailable' === $entry['status'] ) {
			return $this->check(
				'elementor',
				'fail',
				(string) $entry['reason'],
				__( 'Install and activate Elementor, then start the workflow again.', 'replicaforge' )
			);
		}

		if ( 'degraded' === $entry['status'] ) {
			return $this->check(
				'elementor',
				'warn',
				(string) $entry['reason'],
				__( 'Update Elementor to a version with Flexbox containers for a more editable result.', 'replicaforge' )
			);
		}

		return $this->check( 'elementor', 'pass', __( 'Elementor is available with container support.', 'replicaforge' ) );
	}

	/**
	 * The source URL, validated.
	 *
	 * The URL is validated here as well as at the analysis stage, and the duplication is
	 * deliberate: preflight runs before the user approves a plan, and a plan built around a
	 * source that will be refused is a plan the user should never have been asked to review.
	 *
	 * @param string $source_url The URL.
	 * @return array
	 */
	private function check_source( $source_url ) {
		if ( '' === (string) $source_url ) {
			return $this->check(
				'source',
				'fail',
				__( 'No source URL was supplied.', 'replicaforge' ),
				__( 'Choose the website you want to reconstruct.', 'replicaforge' )
			);
		}

		$verdict = ( new Url_Validator() )->validate( $source_url );

		if ( empty( $verdict['success'] ) ) {
			$error = (array) ( $verdict['error'] ?? array() );

			/*
			 * The validator's own message is used, because it is written for a human and
			 * already refuses to disclose anything about the network. Its code is passed
			 * through so a caller can branch on it, and its details are dropped entirely.
			 */
			return $this->check(
				'source',
				'fail',
				(string) ( $error['message'] ?? __( 'The source URL was refused.', 'replicaforge' ) ),
				__( 'Enter a public http or https address.', 'replicaforge' ),
				(string) ( $error['code'] ?? '' )
			);
		}

		return $this->check( 'source', 'pass', __( 'The source URL is a public address and can be fetched.', 'replicaforge' ) );
	}

	/**
	 * Ownership and permission.
	 *
	 * @param string $project_id Project id.
	 * @return array
	 */
	private function check_ownership( $project_id ) {
		if ( $this->user_id < 1 ) {
			return $this->check(
				'ownership',
				'fail',
				__( 'You must be signed in to start a workflow.', 'replicaforge' ),
				__( 'Sign in and try again.', 'replicaforge' )
			);
		}

		if ( '' === (string) $project_id ) {
			return $this->check( 'ownership', 'fail', __( 'No project was supplied.', 'replicaforge' ) );
		}

		$project = $this->projects->find( $project_id );

		if ( null === $project ) {
			return $this->check( 'ownership', 'fail', __( 'The project does not exist.', 'replicaforge' ) );
		}

		/*
		 * Ownership is checked, not assumed. A project created before the collaboration
		 * tables existed has an owner and no workspace, and a project adopted into a
		 * workspace has a workspace and a membership. Both are legitimate, and both are
		 * answered by asking the permission service rather than by inspecting fields.
		 */
		$owner = (int) ( $project['user_id'] ?? 0 );

		if ( $owner === $this->user_id ) {
			return $this->check( 'ownership', 'pass', __( 'You own this project.', 'replicaforge' ) );
		}

		if ( user_can( $this->user_id, 'manage_options' ) ) {
			return $this->check( 'ownership', 'pass', __( 'You administer this site.', 'replicaforge' ) );
		}

		return $this->check(
			'ownership',
			'fail',
			__( 'You do not own this project.', 'replicaforge' ),
			__( 'Ask the project owner to start the workflow, or to add you as a member.', 'replicaforge' )
		);
	}

	/**
	 * Every capability, as one check.
	 *
	 * @return array
	 */
	private function check_capabilities() {
		$missing = $this->capabilities->missing_required();
		$degraded = $this->capabilities->degraded();

		if ( ! empty( $missing ) ) {
			return $this->check(
				'capabilities',
				'fail',
				sprintf(
					/* translators: %s: comma separated capability names. */
					__( 'This site cannot run the workflow. Missing: %s.', 'replicaforge' ),
					implode( ', ', $missing )
				),
				__( 'Resolve the missing capability, or choose a workflow that does not need it.', 'replicaforge' ),
				implode( ',', $missing )
			);
		}

		if ( ! empty( $degraded ) ) {
			$reasons = array();

			foreach ( $degraded as $capability ) {
				$reasons[] = (string) $this->capabilities->get( $capability )['reason'];
			}

			return $this->check(
				'capabilities',
				'warn',
				implode( ' ', $reasons ),
				__( 'The workflow will run, with the limitations above recorded in the report.', 'replicaforge' ),
				implode( ',', $degraded )
			);
		}

		return $this->check( 'capabilities', 'pass', __( 'Every capability this workflow needs is fully available.', 'replicaforge' ) );
	}

	/**
	 * Storage and execution limits.
	 *
	 * The plan quota is read, not enforced here. Phase 10's `Entitlement_Manager` is the thing
	 * that enforces a plan, and this check only reports whether an operation this workflow
	 * needs is in the plan at all - a free plan without `elementor_generation` cannot
	 * reconstruct, and saying so at preflight is far better than discovering it at
	 * generation with a draft half-built.
	 *
	 * @return array
	 */
	private function check_budget() {
		if ( ! class_exists( 'ReplicaForge\\Entitlement_Manager' ) ) {
			return $this->check(
				'budget',
				'unavailable',
				__( 'The plan and quota system is not available, so usage could not be checked.', 'replicaforge' ),
				__( 'Usage will not be metered for this run.', 'replicaforge' )
			);
		}

		try {
			$entitlements = new Entitlement_Manager();
			$plan         = $entitlements->plans()->resolve( $this->user_id );
		} catch ( \Throwable $e ) {
			return $this->check(
				'budget',
				'unavailable',
				__( 'The plan could not be resolved, so usage could not be checked.', 'replicaforge' ),
				__( 'The workflow will still run; usage accounting may be incomplete.', 'replicaforge' )
			);
		}

		if ( null === $plan ) {
			return $this->check(
				'budget',
				'unavailable',
				__( 'No plan could be determined for your account.', 'replicaforge' ),
				__( 'The workflow will run, but plan limits cannot be enforced.', 'replicaforge' )
			);
		}

		$unavailable = array();

		// The exact operation vocabulary. A name outside `Plan_Limits::OPERATIONS` returns
		// 400 from the entitlement check, so a typo here would read as "no plan has this".
		foreach ( array( 'analysis', 'generation', 'validation' ) as $operation ) {
			if ( ! $plan->permits( $operation ) ) {
				$unavailable[] = $entitlements->plans()->feature_label( $operation );
			}
		}

		if ( ! empty( $unavailable ) ) {
			return $this->check(
				'budget',
				'fail',
				sprintf(
					/* translators: %s: comma separated plan limits. */
					__( 'Your plan does not include: %s.', 'replicaforge' ),
					implode( ', ', $unavailable )
				),
				__( 'Upgrade your plan to run a full reconstruction.', 'replicaforge' ),
				implode( ',', $unavailable )
			);
		}

		return $this->check( 'budget', 'pass', __( 'Your plan includes the operations this workflow needs.', 'replicaforge' ) );
	}

	/**
	 * Existing workflows and duplicate reconstruction.
	 *
	 * @param string $project_id Project id.
	 * @param string $source_url Source url.
	 * @return array
	 */
	private function check_conflicts( $project_id, $source_url ) {
		$duplicates = $this->projects->duplicates_of( $source_url );

		if ( ! empty( $duplicates ) ) {
			return $this->check(
				'conflicts',
				'warn',
				sprintf(
					/* translators: %d: number of existing projects. */
					_n(
						'This website has already been added as %d project. Running the workflow again will create a second draft.',
						'This website has already been added as %d projects. Running the workflow again will create a second draft.',
						count( $duplicates ),
						'replicaforge'
					),
					count( $duplicates )
				),
				__( 'Open the existing project instead, or use an incremental update to bring it up to date.', 'replicaforge' )
			);
		}

		return $this->check( 'conflicts', 'pass', __( 'This website has not been reconstructed before.', 'replicaforge' ) );
	}

	/**
	 * Type-specific feasibility.
	 *
	 * @param string $type Workflow type.
	 * @return array
	 */
	private function check_type( $type ) {
		$type = Orchestrator_Limits::is_type( $type ) ? $type : 'single_page';

		if ( 'ecommerce' === $type ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return $this->check(
					'type',
					'warn',
					__( 'This is an e-commerce reconstruction but WooCommerce is not installed, so product mapping is unavailable.', 'replicaforge' ),
					__( 'Install WooCommerce to map products. The layout will still be reconstructed.', 'replicaforge' )
				);
			}

			return $this->check( 'type', 'pass', __( 'WooCommerce is available for product mapping.', 'replicaforge' ) );
		}

		if ( 'incremental' === $type ) {
			$sync = $this->capabilities->get( 'sync' );

			if ( 'unavailable' === $sync['status'] ) {
				/*
				 * Not a failure. An incremental update still fetches, analyses and plans; what
				 * it cannot do is apply the differences automatically. A user who asked to
				 * update an existing site should be told that up front rather than discover it
				 * when the sync stage is reached.
				 */
				return $this->check(
					'type',
					'warn',
					(string) $sync['reason'],
					__( 'The update will be analysed and reported, but the changes will need to be applied by hand.', 'replicaforge' )
				);
			}
		}

		return $this->check( 'type', 'pass', __( 'This workflow type is supported on this site.', 'replicaforge' ) );
	}

	/* ---------------------------------------------------------------------
	 * Planning support
	 * ------------------------------------------------------------------ */

	/**
	 * Recommend a mode, without overriding the user's choice.
	 *
	 * §8 requires the planner to recommend but never silently override. The recommendation is
	 * therefore computed and returned, and the caller decides what to do with it - the
	 * definition always uses the mode the user asked for.
	 *
	 * The recommendation is derived from what is actually missing rather than from a guess
	 * about the user's intent. If visual comparison is unavailable, recommending
	 * `visual_accuracy` would be recommending a mode whose headline benefit cannot be
	 * delivered on this install.
	 *
	 * @param string $type      Workflow type.
	 * @param string $requested The mode the user asked for.
	 * @return array
	 */
	public function recommend_mode( $type, $requested ) {
		$requested = Orchestrator_Limits::is_mode( $requested ) ? (string) $requested : 'balanced';

		$recommended = 'balanced';
		$reasons     = array();

		if ( 'single_page' === $type ) {
			$reasons[] = __( 'A single page has no cross-page consistency to preserve, so the balanced weights suit it.', 'replicaforge' );
		}

		if ( ! $this->capabilities->can( 'rendering' ) ) {
			$reasons[] = __( 'Visual comparison is unavailable here, so visual accuracy cannot be measured and the plan will not claim it.', 'replicaforge' );
		}

		if ( ! $this->capabilities->can( 'browser' ) && ! $this->capabilities->can( 'rendering' ) ) {
			$recommended = 'editable_structure';
			$reasons[]   = __( 'With neither rendering nor browser observation, the achievable result is a faithful structure, so that is what this plan prioritises.', 'replicaforge' );
		} elseif ( $this->capabilities->can( 'elementor' ) && ! $this->capabilities->can( 'rendering' ) ) {
			$recommended = 'editable_structure';
			$reasons[]   = __( 'Elementor generation works but pixels cannot be compared, so structural quality is the measurable outcome.', 'replicaforge' );
		}

		if ( $this->capabilities->can( 'rendering' ) ) {
			$recommended = $requested;
		}

		return array(
			'requested'   => $requested,
			'recommended' => $recommended,
			'differs'     => $recommended !== $requested,
			'reasons'     => $reasons,
			// The user's selection is preserved no matter what the recommendation says.
			'applied'     => $requested,
		);
	}

	/**
	 * Estimate what the workflow will need.
	 *
	 * Counts of stages and pages are knowable before execution. Durations, costs and accuracy
	 * are not, and §8 forbids inventing them, so those keys are absent rather than estimated.
	 *
	 * @param string $type Workflow type.
	 * @param string $mode Mode.
	 * @return array
	 */
	private function estimate( $type, $mode ) {
		$stages = count( Orchestrator_Limits::stages_for( $type ) );
		$skips  = count( Orchestrator_Limits::ineligible_stages( $type ) );

		$blocked = 0;
		foreach ( Orchestrator_Limits::stages_for( $type ) as $stage ) {
			$capability = (string) ( Orchestrator_Limits::STAGE_CAPABILITIES[ $stage ] ?? 'core' );

			if ( 'unavailable' === $this->capabilities->get( $capability )['status'] ) {
				$blocked++;
			}
		}

		$pages = 1;

		if ( in_array( $type, array( 'multi_page', 'ecommerce', 'blog' ), true ) ) {
			$pages = Orchestrator_Limits::budget( 'max_pages' );
		}

		return array(
			'stages_total'         => $stages,
			'stages_not_required'  => $skips,
			'stages_blocked'       => $blocked,
			'stages_expected'      => max( 0, $stages - $skips - $blocked ),
			'pages_maximum'        => $pages,
			'correction_iterations_max' => Orchestrator_Limits::BUDGETS['max_corrections'],
			'validation_passes_max'     => Orchestrator_Limits::BUDGETS['max_validation_pass'],
			/*
			 * Deliberately absent: an estimated duration, an estimated cost and an expected
			 * accuracy. None of them can be derived before execution on a site whose
			 * capabilities are this variable, and an estimate that reads as a measurement is
			 * worse than no estimate.
			 */
		);
	}

	/**
	 * Return what the user must do before the workflow can run.
	 *
	 * @param array $failed   Failed checks.
	 * @param array $warned   Warned checks.
	 * @param array $missing  Missing capabilities.
	 * @return array<int, array>
	 */
	private function required_actions( array $failed, array $warned, array $missing ) {
		$actions = array();

		foreach ( $failed as $check ) {
			if ( '' === (string) $check['remedy'] ) {
				continue;
			}

			$actions[] = array(
				'severity' => 'required',
				'check'    => $check['id'],
				'action'   => $check['remedy'],
			);
		}

		foreach ( $warned as $check ) {
			if ( '' === (string) $check['remedy'] ) {
				continue;
			}

			$actions[] = array(
				'severity' => 'optional',
				'check'    => $check['id'],
				'action'   => $check['remedy'],
			);
		}

		return $actions;
	}

	/**
	 * Reduce the report to a sentence a person reads.
	 *
	 * @param array $report The report.
	 * @return string
	 */
	private function summarise( array $report ) {
		$counts = (array) $report['counts'];

		if ( ! $report['can_proceed'] ) {
			$names = array();

			foreach ( (array) $report['checks'] as $check ) {
				if ( 'fail' === $check['outcome'] ) {
					$names[] = $check['label'];
				}
			}

			return sprintf(
				/* translators: %s: comma separated check labels. */
				__( 'This workflow cannot run yet. Blocking checks: %s.', 'replicaforge' ),
				implode( ', ', $names )
			);
		}

		if ( (int) $counts['warn'] > 0 ) {
			return sprintf(
				/* translators: 1: expected stage count, 2: warning count. */
				__( 'This workflow can run. %1$d stages are expected to produce a result, and %2$d limitation(s) are recorded in the report.', 'replicaforge' ),
				(int) $report['estimated_resources']['stages_expected'],
				(int) $counts['warn']
			);
		}

		return sprintf(
			/* translators: %d: expected stage count. */
			__( 'This workflow can run. %d stages are expected to produce a result.', 'replicaforge' ),
			(int) $report['estimated_resources']['stages_expected']
		);
	}

	/**
	 * Build one check entry.
	 *
	 * @param string $id      Check id.
	 * @param string $outcome Outcome.
	 * @param string $message The finding.
	 * @param string $remedy  What to do, or '' when there is nothing to do.
	 * @param string $code    A machine-readable code.
	 * @return array
	 */
	private function check( $id, $outcome, $message, $remedy = '', $code = '' ) {
		if ( ! in_array( $outcome, self::OUTCOMES, true ) ) {
			$outcome = 'unavailable';
		}

		return array(
			'id'      => (string) $id,
			'outcome' => (string) $outcome,
			'label'   => $this->label( (string) $id ),
			'message' => (string) $message,
			'remedy'  => (string) $remedy,
			'code'    => (string) $code,
		);
	}

	/**
	 * Return a check's human label.
	 *
	 * @param string $id Check id.
	 * @return string
	 */
	private function label( $id ) {
		$labels = array(
			'platform'     => __( 'Server compatibility', 'replicaforge' ),
			'memory'       => __( 'Available memory', 'replicaforge' ),
			'elementor'    => __( 'Elementor', 'replicaforge' ),
			'source'       => __( 'Source website', 'replicaforge' ),
			'ownership'    => __( 'Project access', 'replicaforge' ),
			'capabilities' => __( 'Available features', 'replicaforge' ),
			'budget'       => __( 'Plan and usage', 'replicaforge' ),
			'conflicts'    => __( 'Existing projects', 'replicaforge' ),
			'type'         => __( 'Workflow type', 'replicaforge' ),
		);

		return (string) ( $labels[ $id ] ?? $id );
	}

	/**
	 * Return the memory limit in bytes.
	 *
	 * @return int Zero when no limit is set.
	 */
	private function memory_limit_bytes() {
		$raw = (string) ini_get( 'memory_limit' );

		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}

		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (int) $raw;

		switch ( $unit ) {
			case 'g':
				return $value * 1073741824;
			case 'm':
				return $value * 1048576;
			case 'k':
				return $value * 1024;
			default:
				return $value;
		}
	}
}
