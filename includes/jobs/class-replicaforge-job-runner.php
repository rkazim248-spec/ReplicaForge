<?php
/**
 * Phase 7: job execution.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a queued job one stage at a time.
 *
 * The runner calls exactly the same Phase 1 to Phase 6 services the REST
 * endpoints call. There is no second implementation of the pipeline, so a job and
 * a direct request cannot diverge, and a fix to a stage fixes both.
 */
final class Job_Runner {

	/**
	 * Job queue.
	 *
	 * @var Job_Queue
	 */
	private $queue;

	/**
	 * Phase 1 analyzer facade.
	 *
	 * @var Analyzer
	 */
	private $analyzer;

	/**
	 * Phase 2 design analyzer.
	 *
	 * @var Design_Analyzer
	 */
	private $design_analyzer;

	/**
	 * Phase 3 AI manager.
	 *
	 * @var Ai_Manager
	 */
	private $ai_manager;

	/**
	 * Phase 4 generator.
	 *
	 * @var Elementor_Generator
	 */
	private $generator;

	/**
	 * Phase 5 validation engine.
	 *
	 * @var Validation_Engine
	 */
	private $validation_engine;

	/**
	 * Phase 5 validation cache, used to re-read a result by identifier.
	 *
	 * @var Validation_Cache
	 */
	private $validation_cache;

	/**
	 * Phase 6 correction engine.
	 *
	 * @var Correction_Engine
	 */
	private $correction_engine;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Feature flags.
	 *
	 * @var Feature_Flags
	 */
	private $flags;

	/**
	 * Job repository, used for stage payloads outside the job record.
	 *
	 * @var Job_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $services Optional service overrides.
	 */
	public function __construct( array $services = array() ) {
		$this->queue    = isset( $services['queue'] ) && $services['queue'] instanceof Job_Queue ? $services['queue'] : new Job_Queue();
		$this->logger   = isset( $services['logger'] ) && $services['logger'] instanceof Logger ? $services['logger'] : new Logger();
		$this->analyzer = isset( $services['analyzer'] ) && $services['analyzer'] instanceof Analyzer ? $services['analyzer'] : new Analyzer();
		$this->design_analyzer = isset( $services['design_analyzer'] ) && $services['design_analyzer'] instanceof Design_Analyzer
			? $services['design_analyzer']
			: new Design_Analyzer();
		$this->ai_manager = isset( $services['ai_manager'] ) && $services['ai_manager'] instanceof Ai_Manager
			? $services['ai_manager']
			: new Ai_Manager();
		$this->generator = isset( $services['generator'] ) && $services['generator'] instanceof Elementor_Generator
			? $services['generator']
			: new Elementor_Generator();
		$this->validation_engine = isset( $services['validation_engine'] ) && $services['validation_engine'] instanceof Validation_Engine
			? $services['validation_engine']
			: new Validation_Engine();
		$this->validation_cache = isset( $services['validation_cache'] ) && $services['validation_cache'] instanceof Validation_Cache
			? $services['validation_cache']
			: new Validation_Cache();
		$this->correction_engine = isset( $services['correction_engine'] ) && $services['correction_engine'] instanceof Correction_Engine
			? $services['correction_engine']
			: new Correction_Engine();
		$this->flags = isset( $services['flags'] ) && $services['flags'] instanceof Feature_Flags
			? $services['flags']
			: new Feature_Flags();
		$this->repository = isset( $services['repository'] ) && $services['repository'] instanceof Job_Repository
			? $services['repository']
			: new Job_Repository( $this->logger );
	}

	/**
	 * Process every claimable job, one stage each.
	 *
	 * Called from WP-Cron. Bounded so a large backlog cannot turn one cron tick
	 * into a request that runs out of time.
	 *
	 * @param int $max Maximum jobs to touch.
	 * @return array<string, mixed>
	 */
	public function tick( $max = 3 ) {
		$processed = array();
		$jobs      = $this->claimable_jobs();

		$count = 0;
		foreach ( $jobs as $job ) {
			if ( $count >= max( 1, (int) $max ) ) {
				break;
			}
			$count++;
			$processed[] = $this->process( (string) $job['job_id'] );
		}

		return array(
			'processed' => $processed,
			'count'     => count( $processed ),
		);
	}

	/**
	 * Process one job's current stage.
	 *
	 * @param string $job_id Job identifier.
	 * @return array<string, mixed>
	 */
	public function process( $job_id ) {
		$claimed = $this->queue->claim( $job_id );
		if ( null === $claimed ) {
			return array(
				'job_id' => (string) $job_id,
				'skipped' => true,
				'reason' => 'not_claimable',
			);
		}

		Request_Context::set( 'job', (string) $job_id );
		$stage  = (string) ( $claimed['stage'] ?? 'queued' );
		$params = is_array( $claimed['params'] ?? null ) ? $claimed['params'] : array();
		$store  = is_array( $claimed['checkpoint'] ?? null ) ? $claimed['checkpoint'] : array();

		$this->logger->info(
			'job_stage_started',
			'Started the ' . $stage . ' stage.',
			array( 'job_id' => (string) $job_id ),
			'job'
		);

		try {
			switch ( $stage ) {
				case 'analyze':
					return $this->stage_analyze( $claimed, $params, $store );
				case 'design':
					return $this->stage_design( $claimed, $params, $store );
				case 'ai':
					return $this->stage_ai( $claimed, $params, $store );
				case 'generate':
					return $this->stage_generate( $claimed, $params, $store );
				case 'validate':
					return $this->stage_validate( $claimed, $params, $store );
				case 'correct':
					return $this->stage_correct( $claimed, $params, $store );
				case 'finalize':
					return $this->stage_finalize( $claimed, $params, $store );
				default:
					$advanced = $this->queue->advance( $job_id, 'analyze' );
					return array( 'job_id' => (string) $job_id, 'stage' => (string) ( $advanced['stage'] ?? '' ), 'ok' => true );
			}
		} catch ( \Throwable $exception ) {
			// An unexpected throw is still a job failure, never a fatal that takes
			// the whole site with it.
			$this->queue->fail(
				$job_id,
				'internal_error',
				'',
				array( 'exception' => get_class( $exception ), 'stage' => $stage )
			);
			return array(
				'job_id' => (string) $job_id,
				'ok'     => false,
				'stage'  => $stage,
				'error'  => 'internal_error',
			);
		}
	}

	/**
	 * Analyze the submitted page.
	 *
	 * This calls `Analyzer::analyze_url()`, the same entry point the REST handler
	 * uses, and keeps the returned representation as the checkpoint every later
	 * stage reads. Nothing is re-derived, so a job and a direct request produce the
	 * same result.
	 *
	 * @param array<string, mixed> $job    Claimed job.
	 * @param array<string, mixed> $params Job parameters.
	 * @param array<string, mixed> $store  Checkpoints.
	 * @return array<string, mixed>
	 */
	private function stage_analyze( array $job, array $params, array $store ) {
		$job_id = (string) $job['job_id'];
		$url    = isset( $params['source_url'] ) ? (string) $params['source_url'] : '';

		$outcome = $this->analyzer->analyze_url( $url );
		if ( ! is_array( $outcome ) || empty( $outcome['success'] ) ) {
			$error = isset( $outcome['error'] ) && is_array( $outcome['error'] ) ? $outcome['error'] : array();
			return $this->fail_from( $job_id, (string) ( $error['code'] ?? 'internal_error' ), isset( $error['message'] ) ? (string) $error['message'] : '' );
		}

		$representation = isset( $outcome['representation'] ) ? $outcome['representation'] : null;
		if ( empty( $representation ) ) {
			return $this->fail_from( $job_id, 'analysis_incomplete' );
		}

		$checkpoint = array(
			// The representation is held outside the job record. It is the one value
			// large enough to matter, and inlining it made the shared job list grow to
			// the size of every retained analysis.
			'representation_ref' => $this->repository->set_payload( $job_id, 'representation', $representation ),
			'source_host'        => isset( $params['source_host'] ) ? (string) $params['source_host'] : '',
			'warnings'          => array_slice( array_values( array_filter( array_map( 'strval', (array) ( $outcome['warnings'] ?? array() ) ) ) ), 0, 20 ),
		);
		$advanced = $this->queue->advance( $job_id, 'design', $checkpoint );

		return array(
			'job_id' => $job_id,
			'ok'     => true,
			'stage'  => (string) ( $advanced['stage'] ?? '' ),
		);
	}

	/**
	 * Record the design understanding stage.
	 *
	 * Phase 2 already runs inside `analyze_url()`, so this stage does not repeat
	 * the work. It confirms the representation is present and carries the design
	 * system forward, and it is where a Phase 2 failure would be reported if the
	 * phases were ever separated.
	 *
	 * @param array<string, mixed> $job    Claimed job.
	 * @param array<string, mixed> $params Job parameters.
	 * @param array<string, mixed> $store  Checkpoints.
	 * @return array<string, mixed>
	 */
	private function stage_design( array $job, array $params, array $store ) {
		$job_id         = (string) $job['job_id'];
		$representation = $this->representation_for( $job_id, $store );

		if ( empty( $representation ) ) {
			return $this->fail_from( $job_id, 'analysis_incomplete' );
		}

		$design = isset( $representation['design_system'] ) && is_array( $representation['design_system'] )
			? $representation['design_system']
			: array();

		$checkpoint = array(
			'section_count'     => isset( $representation['sections'] ) && is_array( $representation['sections'] )
				? count( $representation['sections'] )
				: 0,
			'has_design_system' => ! empty( $design ),
		);
		$advanced = $this->queue->advance( $job_id, 'ai', $checkpoint );

		return array(
			'job_id' => $job_id,
			'ok'     => true,
			'stage'  => (string) ( $advanced['stage'] ?? '' ),
		);
	}

	/**
	 * Optionally ask the AI provider for a reconstruction plan.
	 *
	 * @param array<string, mixed> $job    Claimed job.
	 * @param array<string, mixed> $params Job parameters.
	 * @param array<string, mixed> $store  Checkpoints.
	 * @return array<string, mixed>
	 */
	private function stage_ai( array $job, array $params, array $store ) {
		$job_id         = (string) $job['job_id'];
		$representation = $this->representation_for( $job_id, $store );

		$checkpoint = array( 'specification' => null, 'specification_id' => '', 'ai_used' => false );

		$use_ai = ! empty( $params['use_ai'] ) && $this->flags->enabled( 'ai_enabled' );
		if ( $use_ai && ! empty( $representation ) ) {
			$outcome = $this->ai_manager->analyze( $representation, isset( $params['source_url'] ) ? (string) $params['source_url'] : '' );
			if ( is_array( $outcome ) && ! empty( $outcome['success'] ) ) {
				$checkpoint['specification']    = isset( $outcome['specification'] ) ? $outcome['specification'] : null;
				$checkpoint['specification_id'] = (string) ( $outcome['specification_id'] ?? '' );
				$checkpoint['ai_used']          = true;
			} else {
				// AI is an improvement, not a prerequisite. The job continues with the
				// deterministic plan and records why, rather than failing outright.
				$this->queue->warn( $job_id, __( 'The AI stage did not complete, so the draft will be generated from the analyzed design alone.', 'replicaforge' ) );
			}
		}

		$advanced = $this->queue->advance( $job_id, 'generate', $checkpoint );

		return array(
			'job_id' => $job_id,
			'ok'     => true,
			'stage'  => (string) ( $advanced['stage'] ?? '' ),
		);
	}

	/**
	 * Generate the Elementor draft.
	 *
	 * @param array<string, mixed> $job    Claimed job.
	 * @param array<string, mixed> $params Job parameters.
	 * @param array<string, mixed> $store  Checkpoints.
	 * @return array<string, mixed>
	 */
	private function stage_generate( array $job, array $params, array $store ) {
		$job_id         = (string) $job['job_id'];
		$representation = $this->representation_for( $job_id, $store );

		if ( empty( $representation ) ) {
			return $this->fail_from( $job_id, 'analysis_incomplete' );
		}

		$status = $this->generator->status();
		if ( empty( $status['available'] ) ) {
			$reason = isset( $status['reason'] ) ? (string) $status['reason'] : '';
			$code   = 'elementor_active' === $reason ? 'elementor_inactive' : 'elementor_missing';
			return $this->fail_from( $job_id, $code );
		}

		$specification = isset( $store['ai']['specification'] ) ? $store['ai']['specification'] : null;
		$options       = array(
			'source_url'    => isset( $params['source_url'] ) ? (string) $params['source_url'] : '',
			'import_assets' => ! empty( $params['import_assets'] ),
		);
		if ( ! empty( $store['ai']['specification_id'] ) ) {
			$options['specification_id'] = (string) $store['ai']['specification_id'];
		}

		$outcome = $this->generator->generate( $representation, $specification, $options );
		if ( ! is_array( $outcome ) || empty( $outcome['success'] ) ) {
			$error = isset( $outcome['error'] ) && is_array( $outcome['error'] ) ? $outcome['error'] : array();
			return $this->fail_from(
				$job_id,
				(string) ( $error['code'] ?? 'draft_insert_failed' ),
				isset( $error['message'] ) ? (string) $error['message'] : ''
			);
		}

		$draft_id = (int) ( $outcome['draft_id'] ?? 0 );
		Request_Context::set( 'generation', (string) ( $outcome['generation_id'] ?? '' ) );

		$checkpoint = array(
			'draft_id'      => $draft_id,
			'generation_id' => (string) ( $outcome['generation_id'] ?? '' ),
			'element_count' => (int) ( $outcome['element_count'] ?? 0 ),
			'ai_used'       => ! empty( $store['ai']['ai_used'] ),
		);
		$advanced = $this->queue->advance( $job_id, 'validate', $checkpoint );

		return array(
			'job_id'   => $job_id,
			'ok'       => true,
			'stage'    => (string) ( $advanced['stage'] ?? '' ),
			'draft_id' => $draft_id,
		);
	}

	/**
	 * Validate the generated draft.
	 *
	 * @param array<string, mixed> $job    Claimed job.
	 * @param array<string, mixed> $params Job parameters.
	 * @param array<string, mixed> $store  Checkpoints.
	 * @return array<string, mixed>
	 */
	private function stage_validate( array $job, array $params, array $store ) {
		$job_id         = (string) $job['job_id'];
		$draft_id       = isset( $store['generate']['draft_id'] ) ? (int) $store['generate']['draft_id'] : 0;
		$representation = $this->representation_for( $job_id, $store );

		if ( $draft_id < 1 || empty( $representation ) ) {
			return $this->fail_from( $job_id, 'validation_failed' );
		}

		$outcome = $this->validation_engine->validate(
			$representation,
			$draft_id,
			array(
				'visual' => ! empty( $params['visual'] ) && $this->flags->enabled( 'visual_validation_enabled' ),
				'ai'     => false,
				'force'  => true,
			)
		);

		if ( ! is_array( $outcome ) || empty( $outcome['schema_version'] ) ) {
			$error = isset( $outcome['error'] ) && is_array( $outcome['error'] ) ? $outcome['error'] : array();
			return $this->fail_from(
				$job_id,
				(string) ( $error['code'] ?? 'validation_failed' ),
				isset( $error['message'] ) ? (string) $error['message'] : ''
			);
		}

		Request_Context::set( 'validation', (string) ( $outcome['validation_id'] ?? '' ) );
		$checkpoint = array(
			'validation_id' => (string) ( $outcome['validation_id'] ?? '' ),
			'score'         => isset( $outcome['metrics']['overall']['value'] ) ? $outcome['metrics']['overall']['value'] : null,
			'differences'   => isset( $outcome['differences'] ) && is_array( $outcome['differences'] )
				? count( $outcome['differences'] )
				: 0,
		);
		$advanced = $this->queue->advance( $job_id, 'correct', $checkpoint );

		return array(
			'job_id' => $job_id,
			'ok'     => true,
			'stage'  => (string) ( $advanced['stage'] ?? '' ),
		);
	}

	/**
	 * Plan corrections. Applying them stays a human decision.
	 *
	 * @param array<string, mixed> $job    Claimed job.
	 * @param array<string, mixed> $params Job parameters.
	 * @param array<string, mixed> $store  Checkpoints.
	 * @return array<string, mixed>
	 */
	private function stage_correct( array $job, array $params, array $store ) {
		$job_id        = (string) $job['job_id'];
		$draft_id      = isset( $store['generate']['draft_id'] ) ? (int) $store['generate']['draft_id'] : 0;
		$validation_id = isset( $store['validate']['validation_id'] ) ? (string) $store['validate']['validation_id'] : '';

		$checkpoint = array( 'plan_id' => '', 'plan_counts' => array() );

		if ( $this->flags->enabled( 'auto_correction_enabled' ) && $draft_id > 0 && '' !== $validation_id ) {
			$validation = $this->validation_cache->get_by_id( $validation_id );
			if ( is_array( $validation ) ) {
				$plan = $this->correction_engine->plan( $validation, $draft_id, array( 'ai' => false ) );
				if ( is_array( $plan ) && ! empty( $plan['plan_id'] ) ) {
					$checkpoint['plan_id']     = (string) $plan['plan_id'];
					$checkpoint['plan_counts'] = isset( $plan['counts'] ) && is_array( $plan['counts'] ) ? $plan['counts'] : array();
				}
			} else {
				$this->queue->warn( $job_id, __( 'The validation result was no longer available, so no corrections were planned. Validate the draft again to plan corrections.', 'replicaforge' ) );
			}
		}

		$advanced = $this->queue->advance( $job_id, 'finalize', $checkpoint );

		return array(
			'job_id' => $job_id,
			'ok'     => true,
			'stage'  => (string) ( $advanced['stage'] ?? '' ),
		);
	}

	/**
	 * Close the job with its result.
	 *
	 * @param array<string, mixed> $job    Claimed job.
	 * @param array<string, mixed> $params Job parameters.
	 * @param array<string, mixed> $store  Checkpoints.
	 * @return array<string, mixed>
	 */
	private function stage_finalize( array $job, array $params, array $store ) {
		$job_id = (string) $job['job_id'];

		$result = array(
			'draft_id'       => isset( $store['generate']['draft_id'] ) ? (int) $store['generate']['draft_id'] : 0,
			'generation_id'  => isset( $store['generate']['generation_id'] ) ? (string) $store['generate']['generation_id'] : '',
			'element_count'  => isset( $store['generate']['element_count'] ) ? (int) $store['generate']['element_count'] : 0,
			'validation_id'  => isset( $store['validate']['validation_id'] ) ? (string) $store['validate']['validation_id'] : '',
			'score'          => isset( $store['validate']['score'] ) ? $store['validate']['score'] : null,
			'plan_id'        => isset( $store['correct']['plan_id'] ) ? (string) $store['correct']['plan_id'] : '',
			'elementor_url'  => $this->edit_url( isset( $store['generate']['draft_id'] ) ? (int) $store['generate']['draft_id'] : 0 ),
		);

		$completed = $this->queue->complete( $job_id, $result );

		return array(
			'job_id'   => $job_id,
			'ok'       => true,
			'stage'    => 'finalize',
			'result'   => $result,
			'status'   => (string) ( $completed['status'] ?? '' ),
		);
	}

	/**
	 * Return the analyzed representation for a job.
	 *
	 * The value is read back from the payload the analyze stage stored, falling
	 * back to a checkpoint that carries it inline. When neither is available the
	 * job cannot continue, and it says so rather than continuing with nothing.
	 *
	 * @param string               $job_id Job identifier.
	 * @param array<string, mixed> $store  Checkpoints.
	 * @return array<string, mixed>|null
	 */
	private function representation_for( $job_id, array $store ) {
		$reference = isset( $store['analyze']['representation_ref'] ) ? (string) $store['analyze']['representation_ref'] : '';
		if ( '' !== $reference ) {
			$payload = $this->repository->get_payload( $job_id, 'representation', $reference );
			if ( is_array( $payload ) && ! empty( $payload ) ) {
				return $payload;
			}
		}
		if ( isset( $store['analyze']['representation'] ) && is_array( $store['analyze']['representation'] ) ) {
			return $store['analyze']['representation'];
		}
		if ( isset( $store['design']['design_representation'] ) && is_array( $store['design']['design_representation'] ) ) {
			return $store['design']['design_representation'];
		}
		return null;
	}

	/**
	 * Turn a service error into a job failure.
	 *
	 * @param string $job_id  Job identifier.
	 * @param string $code    Error code.
	 * @param string $message User-facing message.
	 * @return array<string, mixed>
	 */
	private function fail_from( $job_id, $code, $message = '' ) {
		$this->queue->fail( $job_id, $code, $message );
		return array(
			'job_id' => (string) $job_id,
			'ok'     => false,
			'error'  => (string) $code,
		);
	}

	/**
	 * Return the Elementor editor URL for a draft, when there is one.
	 *
	 * @param int $draft_id Draft post identifier.
	 * @return string
	 */
	private function edit_url( $draft_id ) {
		if ( $draft_id < 1 ) {
			return '';
		}
		$url = get_edit_post_link( $draft_id, 'raw' );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Return the jobs a worker could claim.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function claimable_jobs() {
		$repository = new Job_Repository( $this->logger );
		return $repository->claimable();
	}
}
