<?php
/**
 * Phase 17: the orchestrator vocabulary.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Every constant the orchestration layer reads.
 *
 * ### Why the stage list is declared here and not assembled from what happens to exist
 *
 * The tempting design is to build the pipeline by asking each subsystem whether it is
 * available and appending whatever answers yes. That produces a *different workflow on
 * every install*, which is the opposite of what an orchestrator is for: a user who reads a
 * report has to be able to know what ran and why.
 *
 * So the pipeline is declared, and capability detection decides which declared stages are
 * *eligible* — never which stages exist. A workflow therefore has the same shape everywhere,
 * and a skipped stage is a recorded, explainable fact rather than a silent absence.
 *
 * ### The two axes
 *
 * `STAGES` is what happens, in order. `MODES` is what the user asked to prioritise. A mode
 * changes weights and thresholds; it does not add or remove stages, for the same reason.
 * The planner may *recommend* a mode but never silently overrides the selected one — the
 * user's stated goal outranks a heuristic.
 *
 * ### What is NOT here
 *
 * No severity list, no viewport list, no category list. `Validation_Limits` owns all three,
 * and Phase 16 set the precedent for reading rather than restating: a second vocabulary
 * eventually disagrees with the first, and the one that is wrong is the one nobody tests.
 */
final class Orchestrator_Limits {

	/**
	 * The phase.
	 *
	 * @var string
	 */
	const PHASE = '17.0';

	/**
	 * The workflow schema version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '17.0';

	/**
	 * The engine version, folded into every workflow record.
	 *
	 * @var string
	 */
	const ENGINE_VERSION = '1.0';

	/**
	 * The option holding a project's workflows.
	 *
	 * `public` so a store, a REST route and a test can all reach the one name.
	 *
	 * @var string
	 */
	const OPTION = 'replicaforge_workflows';

	/**
	 * The per-workflow option prefix.
	 *
	 * Split from the index deliberately: the index is a small list for listing, and each
	 * workflow's record is its own option so a long-running workflow's evidence does not
	 * have to be loaded to draw a dashboard.
	 *
	 * @var string
	 */
	const RECORD_PREFIX = 'replicaforge_workflow_';

	/**
	 * The transient prefix for a workflow's runtime state.
	 *
	 * @var string
	 */
	const STATE_PREFIX = 'replicaforge_workflow_state_';

	/**
	 * The maximum workflows retained per project.
	 *
	 * @var int
	 */
	const MAX_PER_PROJECT = 20;

	/**
	 * The maximum workflow records retained in the index.
	 *
	 * @var int
	 */
	const MAX_INDEX = 200;

	/**
	 * The stage list, in pipeline order.
	 *
	 * The pipeline the specification names is:
	 *
	 *   project -> preflight -> plan -> analysis -> intelligence -> specification
	 *   -> approvals -> generation -> render -> validation -> corrections
	 *   -> regression -> final review -> completion
	 *
	 * `preflight` is first because everything after it is expensive, and the whole value of
	 * checking the environment is lost if the check happens after the cost.
	 *
	 * `sync` sits after `analysis` because it is the stage that consumes a fresh
	 * representation to work out what actually changed. It is declared here rather than
	 * appended by the type, for the reason the first version of this file got wrong: a stage
	 * that exists only in one type's stage list is a stage the validator cannot check, and an
	 * unvalidated stage in a dependency graph is a stage that can run in the wrong order.
	 */
	const STAGES = array(
		'preflight',
		'planning',
		'analysis',
		'site_architecture',
		'sync',
		'intelligence',
		'specification',
		'approval_plan',
		'approval_draft',
		'generation',
		'render',
		'validation',
		'interactions',
		'corrections',
		'regression',
		'final_review',
		'completion',
	);

	/**
	 * The stages a single-page workflow skips.
	 *
	 * Skipped-with-a-reason, never omitted. A single-page run has no site-wide
	 * architecture to discover, and running a crawl for a one-page site would be both
	 * wasteful and misleading in the report — but *not recording* the stage would leave a
	 * reader unable to tell "we did not need it" from "we forgot".
	 */
	const SINGLE_PAGE_SKIP = array( 'site_architecture' );

	/**
	 * What each stage requires before it may run.
	 *
	 * A DAG, not a sequence, even though the list is in order. The order is what a reader
	 * expects; the graph is what the executor enforces, and it means a future stage can
	 * declare a dependency that is not "the one before me" without the list changing.
	 *
	 * Kept explicit rather than inferred from position, because inference from position is
	 * exactly how a cycle becomes invisible — the graph would agree with itself.
	 */
	const STAGE_DEPENDENCIES = array(
		'preflight'          => array(),
		'planning'           => array( 'preflight' ),
		'analysis'           => array( 'planning' ),
		'site_architecture'  => array( 'analysis' ),
		'sync'               => array( 'analysis' ),
		'intelligence'       => array( 'site_architecture', 'sync' ),
		'specification'      => array( 'intelligence' ),
		'approval_plan'      => array( 'specification' ),
		'approval_draft'     => array( 'approval_plan' ),
		'generation'         => array( 'approval_draft' ),
		'render'             => array( 'generation' ),
		'validation'         => array( 'render' ),
		'interactions'       => array( 'generation' ),
		'corrections'        => array( 'validation' ),
		'regression'         => array( 'corrections' ),
		'final_review'       => array( 'regression' ),
		'completion'         => array( 'final_review' ),
	);

	/**
	 * The capability each stage needs.
	 *
	 * A stage whose capability is unavailable is *skipped with a recorded reason*, never
	 * marked successful. That distinction is the difference between "this workflow did not
	 * need a browser" and "this workflow pretended a browser existed".
	 */
	const STAGE_CAPABILITIES = array(
		'preflight'         => 'core',
		'planning'          => 'core',
		'analysis'          => 'fetch',
		'site_architecture' => 'multipage',
		'sync'              => 'sync',
		'intelligence'      => 'analysis',
		'specification'     => 'planning',
		'approval_plan'     => 'core',
		'approval_draft'    => 'elementor',
		'generation'        => 'elementor',
		'render'            => 'rendering',
		'validation'        => 'validation',
		'interactions'      => 'interactions',
		'corrections'       => 'corrections',
		'regression'        => 'validation',
		'final_review'      => 'core',
		'completion'        => 'core',
	);

	/**
	 * The capability vocabulary.
	 *
	 * `core` is always present — it is the orchestrator itself — and every other entry is
	 * something a site may genuinely not have. The registry answers each one by measurement,
	 * never by assumption.
	 */
	const CAPABILITIES = array(
		'core',
		'fetch',
		'analysis',
		'planning',
		'elementor',
		'rendering',
		'validation',
		'corrections',
		'interactions',
		'content',
		'multipage',
		'sync',
		'ai',
		'browser',
	);

	/**
	 * Capabilities whose absence makes a workflow impossible rather than reduced.
	 *
	 * Everything else degrades. These four do not, because each one is the thing the
	 * workflow exists to produce: without `fetch` there is no source, without `elementor`
	 * there is no destination, without `core` there is no orchestrator, and `ai` is here
	 * because phase 3's specification is an AI artefact — a workflow that cannot plan cannot
	 * claim to reconstruct.
	 */
	const REQUIRED_CAPABILITIES = array( 'core', 'fetch', 'elementor' );

	/**
	 * The workflow types.
	 */
	const TYPES = array(
		'single_page',
		'multi_page',
		'ecommerce',
		'blog',
		'incremental',
	);

	/**
	 * The reconstruction modes.
	 */
	const MODES = array(
		'balanced',
		'visual_accuracy',
		'editable_structure',
		'functional',
	);

	/**
	 * How each mode weights the quality dimensions.
	 *
	 * Weights are relative, not percentages, and they are applied per dimension rather than
	 * collapsed into one score. The specification is explicit that an unexplained overall
	 * number hides the thing that matters — a replica can be visually perfect and
	 * structurally unusable, and one figure cannot say which happened.
	 */
	const MODE_WEIGHTS = array(
		'balanced' => array(
			'structure'    => 1.0,
			'visual'       => 1.0,
			'responsive'   => 1.0,
			'interaction'  => 0.8,
			'content'      => 0.9,
			'assets'       => 0.8,
			'editability'  => 1.0,
			'consistency'  => 0.9,
			'security'     => 1.0,
		),
		'visual_accuracy' => array(
			'structure'    => 0.6,
			'visual'       => 1.6,
			'responsive'   => 1.3,
			'interaction'  => 0.5,
			'content'      => 0.6,
			'assets'       => 1.0,
			'editability'  => 0.5,
			'consistency'  => 0.7,
			'security'     => 1.0,
		),
		'editable_structure' => array(
			'structure'    => 1.5,
			'visual'       => 0.6,
			'responsive'   => 0.9,
			'interaction'  => 0.6,
			'content'      => 1.0,
			'assets'       => 0.7,
			'editability'  => 1.8,
			'consistency'  => 1.2,
			'security'     => 1.0,
		),
		'functional' => array(
			'structure'    => 0.9,
			'visual'       => 0.6,
			'responsive'   => 1.1,
			'interaction'  => 1.8,
			'content'      => 1.3,
			'assets'       => 0.8,
			'editability'  => 0.9,
			'consistency'  => 1.1,
			'security'     => 1.0,
		),
	);

	/**
	 * The quality dimensions.
	 *
	 * Every one can be `unavailable`, and `unavailable` is a real answer that the report
	 * states. Fabricating a value for a dimension that could not be measured is the single
	 * most dishonest thing this layer could do, because the number would be indistinguishable
	 * from a measured one downstream.
	 */
	const DIMENSIONS = array(
		'structure',
		'visual',
		'responsive',
		'interaction',
		'content',
		'assets',
		'editability',
		'consistency',
		'security',
	);

	/**
	 * Workflow states.
	 *
	 * Phase 11's `Job_States` already models the execution lifecycle and owns it. These are
	 * the *workflow* states on top: the same vocabulary where it applies, plus the states
	 * that are specific to a pipeline — chiefly `waiting_approval`, which has no job
	 * equivalent because a job cannot wait for a human.
	 */
	const STATES = array(
		'draft',
		'preflight',
		'queued',
		'running',
		'waiting_approval',
		'paused',
		'validating',
		'correcting',
		'final_review',
		'completed',
		'completed_with_warnings',
		'failed',
		'cancelled',
	);

	/**
	 * The workflow states that mean "no more work will happen on their own".
	 *
	 * ### Why `failed` is terminal and still has an exit
	 *
	 * It looks contradictory and is not: it is the exact shape Phase 11 uses.
	 * `Job_States::TERMINAL` includes FAILED, and `Job_States::TRANSITIONS[FAILED]`
	 * includes QUEUED.
	 *
	 * The reading is that a terminal state generates no further work by itself. A
	 * re-queue is a new attempt with a new owner, not a continuation of the failed
	 * one - which is why re-queueing is an explicit act that goes through the same
	 * permission and budget checks as starting one. A failed workflow left alone
	 * stays failed. A failed workflow somebody retries becomes a running workflow
	 * with a new history, and the old attempt remains in the report.
	 *
	 * @var array<int, string>
	 */
	const TERMINAL_STATES = array( 'completed', 'completed_with_warnings', 'failed', 'cancelled' );

	/**
	 * The states that may be re-queued.
	 *
	 * Declared rather than derived, so a caller asking whether a retry is possible
	 * reads one list instead of re-deriving the rule and getting it subtly different
	 * from the one that enforces it.
	 *
	 * @var array<int, string>
	 */
	const RETRYABLE_STATES = array( 'failed' );

	/**
	 * The valid transitions.
	 *
	 * `draft -> preflight` rather than `draft -> queued`, because preflight is cheap and
	 * refusing a workflow that cannot run should happen before it is queued at all.
	 */
	const TRANSITIONS = array(
		'draft'                  => array( 'preflight', 'cancelled', 'failed' ),
		'preflight'              => array( 'queued', 'failed', 'cancelled' ),
		'queued'                 => array( 'running', 'paused', 'cancelled', 'failed' ),
		'running'                => array( 'running', 'waiting_approval', 'paused', 'validating', 'correcting', 'final_review', 'completed', 'completed_with_warnings', 'failed', 'cancelled' ),
		'waiting_approval'       => array( 'running', 'paused', 'cancelled', 'failed' ),
		'paused'                 => array( 'queued', 'running', 'cancelled' ),
		'validating'             => array( 'running', 'correcting', 'paused', 'final_review', 'completed', 'completed_with_warnings', 'failed', 'cancelled' ),
		'correcting'             => array( 'running', 'validating', 'paused', 'final_review', 'completed', 'completed_with_warnings', 'failed', 'cancelled' ),
		'final_review'           => array( 'completed', 'completed_with_warnings', 'failed', 'cancelled' ),
		'completed'              => array(),
		'completed_with_warnings' => array(),
		'failed'                 => array( 'queued' ),
		'cancelled'              => array(),
	);

	/**
	 * Stage outcomes.
	 *
	 * `skipped` is a first-class outcome and is recorded with a reason. It is never
	 * `succeeded`: a stage that did not run has not succeeded, and a dependency that thinks
	 * otherwise will treat a missing validation as a passing one.
	 */
	const STAGE_OUTCOMES = array( 'pending', 'running', 'succeeded', 'skipped', 'failed', 'blocked' );

	/**
	 * The approval gates.
	 *
	 * Named for what they gate, not for what they are called, so a caller cannot
	 * accidentally gate the wrong thing.
	 */
	const GATES = array(
		'draft_creation',
		'content_mapping',
		'commerce_mutation',
		'destructive_correction',
		'user_component',
		'multi_page_update',
		'sync_apply',
		'finalization',
	);

	/**
	 * Which stage each gate sits at.
	 */
	const GATE_STAGES = array(
		'draft_creation'          => 'approval_draft',
		'content_mapping'         => 'approval_plan',
		'commerce_mutation'       => 'approval_draft',
		'destructive_correction'  => 'corrections',
		'user_component'          => 'corrections',
		'multi_page_update'       => 'generation',
		'sync_apply'              => 'final_review',
		'finalization'            => 'final_review',
	);

	/**
	 * The capability a gate's approval requires.
	 *
	 * Read from the Phase 15 vocabulary rather than invented, so a gate cannot require a
	 * permission the permission system does not have, and so the approver is chosen by the
	 * same rules as every other Phase 15 decision.
	 */
	const GATE_CAPABILITIES = array(
		'draft_creation'         => 'generation.run',
		'content_mapping'        => 'content.apply',
		'commerce_mutation'      => 'content.apply',
		'destructive_correction' => 'correction.apply',
		'user_component'         => 'correction.review',
		'multi_page_update'      => 'projects.edit',
		'sync_apply'             => 'sync.approve',
		'finalization'           => 'reviews.approve',
	);

	/**
	 * The final report statuses.
	 *
	 * `passed` and `passed_with_warnings` are about *execution*; `needs_review` is about
	 * *quality*. A workflow can execute perfectly and still need a person to look at it,
	 * and collapsing those into one status is how a technically-complete reconstruction
	 * gets presented as a good one.
	 */
	const FINAL_STATUSES = array(
		'passed',
		'passed_with_warnings',
		'needs_review',
		'blocked',
		'failed',
	);

	/**
	 * Risk classes.
	 */
	const RISKS = array( 'low', 'moderate', 'high' );

	/**
	 * The resource budget names.
	 *
	 * Budgets are ceilings this layer enforces on *its own* orchestration. They are not
	 * billing, and they do not replace Phase 10's plan enforcement — a user on a free plan
	 * is still stopped by Phase 10, and this layer's budget is a second, coarser guard that
	 * stops a single workflow consuming the whole allowance in one go.
	 */
	const BUDGETS = array(
		'max_stages'          => 40,
		'max_attempts'        => 3,
		'max_corrections'     => 3,
		'max_validation_pass' => 4,
		'max_pages'           => 12,
		'max_seconds'         => 900,
	);

	/**
	 * Return whether a value is a declared stage.
	 *
	 * @param string $stage Candidate.
	 * @return bool
	 */
	public static function is_stage( $stage ) {
		return in_array( (string) $stage, self::STAGES, true );
	}

	/**
	 * Return whether a value is a declared state.
	 *
	 * @param string $state Candidate.
	 * @return bool
	 */
	public static function is_state( $state ) {
		return in_array( (string) $state, self::STATES, true );
	}

	/**
	 * Return whether a value is a declared mode.
	 *
	 * @param string $mode Candidate.
	 * @return bool
	 */
	public static function is_mode( $mode ) {
		return in_array( (string) $mode, self::MODES, true );
	}

	/**
	 * Return whether a value is a declared type.
	 *
	 * @param string $type Candidate.
	 * @return bool
	 */
	public static function is_type( $type ) {
		return in_array( (string) $type, self::TYPES, true );
	}

	/**
	 * Return whether a value is a declared gate.
	 *
	 * @param string $gate Candidate.
	 * @return bool
	 */
	public static function is_gate( $gate ) {
		return in_array( (string) $gate, self::GATES, true );
	}

	/**
	 * Return whether a state is terminal.
	 *
	 * @param string $state Candidate.
	 * @return bool
	 */
	public static function is_terminal( $state ) {
		return in_array( (string) $state, self::TERMINAL_STATES, true );
	}

	/**
	 * Return whether a transition is allowed.
	 *
	 * Read from the same table the state machine uses, so a caller cannot hold a different
	 * opinion about what is legal than the thing enforcing it.
	 *
	 * @param string $from From state.
	 * @param string $to   To state.
	 * @return bool
	 */
	public static function can_transition( $from, $to ) {
		$from = (string) $from;
		$to   = (string) $to;

		if ( $from === $to ) {
			return true;
		}
		if ( ! self::is_state( $from ) || ! self::is_state( $to ) ) {
			return false;
		}

		return in_array( $to, (array) ( self::TRANSITIONS[ $from ] ?? array() ), true );
	}

	/**
	 * Return the weights for a mode.
	 *
	 * @param string $mode Mode.
	 * @return array<string, float>
	 */
	public static function weights_for( $mode ) {
		$mode = self::is_mode( $mode ) ? $mode : 'balanced';

		return (array) ( self::MODE_WEIGHTS[ $mode ] ?? self::MODE_WEIGHTS['balanced'] );
	}

	/**
	 * Return the stages a workflow type runs.
	 *
	 * A type decides which stages are *eligible*, never which stages exist. The list
	 * is always the full declared set, in declared order. A stage absent from the
	 * list cannot be reported as skipped, and a stage present but ineligible has to
	 * say why - "we did not need a crawl" and "the crawl did not happen" are
	 * different facts and only one of them is a decision.
	 *
	 * @param string $type Workflow type.
	 * @return array<int, string>
	 */
	public static function stages_for( $type ) {
		/*
		 * Every type runs every declared stage, in declared order.
		 *
		 * The first version of this synthesised a stage list per type - injecting
		 * `site_architecture` for multi-page and appending `sync` for incremental. Both
		 * injections were bugs waiting to happen: the injected names had to be kept in step
		 * with `STAGES`, and the incremental one was not, so every `incremental` workflow
		 * failed validation with `orchestrator_unknown_stage`. The graph is now the single
		 * source of truth and a type only decides what is *skipped*, which is recorded with
		 * a reason and cannot corrupt the ordering.
		 */
		return self::STAGES;
	}

	/**
	 * Return the stages a workflow type does not need.
	 *
	 * These are skipped *with a recorded reason*, not removed. The distinction is
	 * what lets a reader of the report tell an intentional omission from a stage
	 * that failed to run.
	 *
	 * @param string $type Workflow type.
	 * @return array<int, string>
	 */
	public static function ineligible_stages( $type ) {
		$type = self::is_type( $type ) ? $type : 'single_page';

		if ( 'incremental' === $type ) {
			/*
			 * A crawl is exactly what an incremental update is not doing. The sync stage is
			 * the one that replaces it: it compares a fresh representation against the stored
			 * one to decide what actually changed.
			 */
			return array( 'site_architecture' );
		}

		if ( 'single_page' === $type ) {
			return self::SINGLE_PAGE_SKIP;
		}

		return array();
	}

	/**
	 * Return the list of REST namespaces the orchestrator's routes live under.
	 *
	 * Exposed so a test - or any future caller that wants to enumerate the surface - reads
	 * the real prefixes rather than re-deriving them. A duplicated prefix in a test is a test
	 * that passes after the route has moved.
	 *
	 * @return array<string>
	 */
	public static function rest_prefixes() {
		$api = class_exists( 'ReplicaForge\\Orchestrator_Api' ) ? 'replicaforge/v1/orchestrator' : '';

		return '' === $api ? array() : array( $api );
	}

	/**
	 * Reduce a workflow id to the form every option name is derived from.
	 *
	 * ### Why this is a function and not two copies of a regex
	 *
	 * A workflow record lives in `replicaforge_workflow_<id>` and its artifacts live in
	 * `replicaforge_workflow_artifacts_<id>`. If those two derivations disagree by even a
	 * character, the failure is silent and expensive: the record is found but its artifacts
	 * are not, or a delete removes the record and leaves the artifacts behind forever.
	 *
	 * That is not hypothetical. The id is `wf_` plus a `Ymd\THis` timestamp, so it contains
	 * an uppercase `T`. An earlier version of this layer had one copy of this sanitiser
	 * lowercasing and the other preserving case, and every deleted workflow leaked its
	 * artifacts - including a copy of the source page's structural representation - into the
	 * options table. One function, called by both, is the fix.
	 *
	 * Case is **preserved**, not normalised, because the record option is written with the id
	 * exactly as generated and rewriting it would orphan every existing record.
	 *
	 * @param string $workflow_id Raw id.
	 * @return string
	 */
	public static function sanitize_workflow_id( $workflow_id ) {
		return substr( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $workflow_id ), 0, 60 );
	}

	/**
	 * Clamp a budget, never raising it.
	 *
	 * @param string $name      Budget name.
	 * @param int    $requested Requested.
	 * @return int
	 */
	public static function budget( $name, $requested = 0 ) {
		$name = (string) $name;
		if ( ! isset( self::BUDGETS[ $name ] ) ) {
			return 0;
		}
		$requested = (int) $requested;

		return $requested > 0 ? min( $requested, (int) self::BUDGETS[ $name ] ) : (int) self::BUDGETS[ $name ];
	}
}
