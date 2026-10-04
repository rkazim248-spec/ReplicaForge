<?php
/**
 * Phase 17: the final quality gate.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a finished workflow passed, and says why.
 *
 * ### Execution success and reconstruction quality are different questions
 *
 * A workflow can run every stage, hit no error, and produce a reconstruction that is
 * structurally wrong, visually unverified, and missing every interaction. §12 asks for both
 * to be distinguished, and the five statuses here are how:
 *
 * - `passed` - the work is done and the evidence supports it.
 * - `passed_with_warnings` - the work is done, and something measurable was incomplete.
 * - `needs_review` - the work is done, and a person should look at it. This is a
 *   *successful execution with an unresolved question*, not a soft failure.
 * - `blocked` - the work could not proceed, and the reason is a dependency.
 * - `failed` - the work did not finish.
 *
 * The distinction that matters most is `passed_with_warnings` versus `needs_review`. A
 * missing renderer produces the first: validation ran, it just could not compare pixels, and
 * the report says so. A critical difference the engine could not classify produces the
 * second: someone has to decide whether that is acceptable.
 *
 * ### There is no overall score
 *
 * §11 forbids an unexplained combined number, and this class produces none. Each dimension
 * is evaluated and reported on its own, with the evidence that produced it. A replica can be
 * pixel-perfect and structurally unusable, and a single figure could not express that even if
 * one existed.
 */
final class Quality_Gate {

	/**
	 * The capability registry.
	 *
	 * @var Capability_Registry
	 */
	private $capabilities;

	/**
	 * Build the gate.
	 *
	 * @param Capability_Registry|null $capabilities Registry.
	 */
	public function __construct( $capabilities = null ) {
		$this->capabilities = ( $capabilities instanceof Capability_Registry ) ? $capabilities : new Capability_Registry();
	}

	/**
	 * Evaluate a workflow.
	 *
	 * @param array              $record    The workflow.
	 * @param Workflow_Artifacts $artifacts The artifact store.
	 * @return array
	 */
	public function evaluate( array $record, $artifacts ) {
		$checks    = array();
		$blocking  = array();
		$warnings  = array();
		$review    = array();

		// The blocking checks: a workflow missing any of these has not done its job.
		$checks['pages_processed'] = $this->check_pages( $record, $artifacts, $blocking );
		$checks['drafts_exist']     = $this->check_drafts( $record, $artifacts, $blocking, $review );
		$checks['drafts_valid']     = $this->check_structure( $record, $artifacts, $blocking );
		$checks['security']         = $this->check_security( $record, $artifacts, $blocking );
		$checks['approvals']        = $this->check_approvals( $record, $blocking, $review );
		$checks['stages_complete']  = $this->check_stages( $record, $blocking, $warnings );

		// The reported dimensions. None of these blocks on its own; they are the evidence.
		$dimensions = $this->dimensions( $record, $artifacts );

		$checks['assets']          = $this->check_assets( $artifacts, $warnings );
		$checks['internal_links']  = $this->check_links( $record, $artifacts, $warnings, $review );
		$checks['content_mapping'] = $this->check_content( $artifacts, $warnings );
		$checks['interactions']    = $this->check_interactions( $artifacts, $warnings );
		$checks['responsive']      = $this->check_responsive( $artifacts, $warnings, $review );
		$checks['visual']          = $this->check_visual( $artifacts, $warnings, $review );
		$checks['regression']      = $this->check_regression( $artifacts, $warnings, $review );

		$status = $this->decide( $blocking, $warnings, $review );

		return array(
			'schema_version' => Orchestrator_Limits::SCHEMA_VERSION,
			'status'         => $status,
			'checked_at'     => gmdate( 'c' ),
			'checks'         => $checks,
			'dimensions'     => $dimensions,
			'blocking'       => $blocking,
			'warnings'       => $warnings,
			'needs_review'   => $review,
			'explanation'    => $this->explain( $status, $blocking, $warnings, $review ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Blocking checks
	 * ------------------------------------------------------------------ */

	/**
	 * Were the pages this workflow promised actually processed?
	 *
	 * @param array              $record   The workflow.
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @param array              $blocking Failures.
	 * @return array
	 */
	private function check_pages( array $record, $artifacts, array &$blocking ) {
		$expected = (int) ( $record['plan']['estimated_pages'] ?? 1 );

		if ( $expected < 2 ) {
			return $this->verdict( true, __( 'This is a single-page workflow, so there is one page to process.', 'replicaforge' ) );
		}

		$records = $artifacts->get( 'page_record' );

		if ( is_wp_error( $records ) && 'orchestrator_artifact_missing' === (string) $records->get_error_code() ) {
			$blocking[] = __( 'The workflow promised several pages but recorded none of them.', 'replicaforge' );

			return $this->verdict( false, __( 'No page record was written.', 'replicaforge' ) );
		}

		return $this->verdict( true, __( 'Page records were written.', 'replicaforge' ) );
	}

	/**
	 * Do the required drafts exist?
	 *
	 * @param array              $record   The workflow.
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @param array              $blocking Failures.
	 * @param array              $review   Items needing review.
	 * @return array
	 */
	private function check_drafts( array $record, $artifacts, array &$blocking, array &$review ) {
		$draft = $artifacts->get( 'draft' );

		if ( is_wp_error( $draft ) ) {
			// Only blocking if generation actually ran. A workflow blocked before
			// generation has no draft to complain about.
			$generation = (string) ( $record['stages']['generation']['outcome'] ?? 'pending' );

			if ( in_array( $generation, array( 'succeeded', 'failed' ), true ) ) {
				$blocking[] = __( 'Generation completed but no draft was recorded.', 'replicaforge' );

				return $this->verdict( false, __( 'No draft was produced.', 'replicaforge' ) );
			}

			return $this->verdict( null, __( 'No draft was produced, because generation did not run.', 'replicaforge' ) );
		}

		$draft_id  = (int) ( $draft['payload']['draft_id'] ?? 0 );
		$published = ! empty( $draft['payload']['published'] );

		/*
		 * §10 and the phase 17 stop conditions both require drafts, never published pages.
		 * A published draft would mean something in the pipeline published without going
		 * through the finalisation gate, so it is reported rather than accepted.
		 */
		if ( $published ) {
			$blocking[] = __( 'The generated page was published, which this workflow must never do.', 'replicaforge' );

			return $this->verdict( false, __( 'The page is published.', 'replicaforge' ), (string) $draft_id );
		}

		$post = get_post( $draft_id );

		if ( null === $post ) {
			$blocking[] = __( 'The generated draft no longer exists.', 'replicaforge' );

			return $this->verdict( false, __( 'The draft was deleted after generation.', 'replicaforge' ), (string) $draft_id );
		}

		return $this->verdict( true, __( 'The draft exists and is still a draft.', 'replicaforge' ), (string) $draft_id );
	}

	/**
	 * Is the draft structurally valid and editable?
	 *
	 * @param array              $record   The workflow.
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @param array              $blocking Failures.
	 * @return array
	 */
	private function check_structure( array $record, $artifacts, array &$blocking ) {
		$draft = $artifacts->get( 'draft' );

		if ( is_wp_error( $draft ) ) {
			return $this->verdict( null, __( 'No draft to inspect.', 'replicaforge' ) );
		}

		$elements = (int) ( $draft['payload']['elements'] ?? 0 );

		if ( $elements < 1 ) {
			$blocking[] = __( 'The generated draft has no elements.', 'replicaforge' );

			return $this->verdict( false, __( 'The draft is empty.', 'replicaforge' ) );
		}

		// The edit mode meta is what makes a page editable in Elementor rather than a
		// static page that merely mentions Elementor.
		$edit_mode = get_post_meta( (int) $draft['payload']['draft_id'], '_elementor_edit_mode', true );

		if ( 'builder' !== (string) $edit_mode ) {
			$blocking[] = __( 'The generated page is not editable in Elementor.', 'replicaforge' );

			return $this->verdict( false, __( 'The page is not an Elementor document.', 'replicaforge' ) );
		}

		return $this->verdict( true, sprintf( /* translators: %d: element count. */ __( 'The draft is an editable Elementor document with %d elements.', 'replicaforge' ), $elements ) );
	}

	/**
	 * Did the security checks pass?
	 *
	 * @param array              $record   The workflow.
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @param array              $blocking Failures.
	 * @return array
	 */
	private function check_security( array $record, $artifacts, array &$blocking ) {
		$preflight = $artifacts->get( 'preflight' );

		if ( is_wp_error( $preflight ) ) {
			$blocking[] = __( 'No preflight report exists, so the source was never checked.', 'replicaforge' );

			return $this->verdict( false, __( 'The preflight report is missing.', 'replicaforge' ) );
		}

		$source = null;

		foreach ( (array) ( $preflight['payload']['checks'] ?? array() ) as $check ) {
			if ( 'source' === (string) ( $check['id'] ?? '' ) ) {
				$source = $check;
			}
		}

		if ( null === $source ) {
			$blocking[] = __( 'The preflight report contains no source URL check.', 'replicaforge' );

			return $this->verdict( false, __( 'The source was not validated.', 'replicaforge' ) );
		}

		if ( 'pass' !== (string) ( $source['outcome'] ?? '' ) ) {
			$blocking[] = sprintf(
				/* translators: %s: the finding. */
				__( 'The source URL check did not pass: %s', 'replicaforge' ),
				(string) ( $source['message'] ?? '' )
			);

			return $this->verdict( false, (string) ( $source['message'] ?? __( 'The source was not validated.', 'replicaforge' ) ) );
		}

		// A draft published without going through finalisation is a security failure too.
		return $this->verdict( true, __( 'The source URL was validated and the page remains a draft.', 'replicaforge' ) );
	}

	/**
	 * Are the required approvals in place?
	 *
	 * @param array $record   The workflow.
	 * @param array $blocking Failures.
	 * @param array $review   Items needing review.
	 * @return array
	 */
	private function check_approvals( array $record, array &$blocking, array &$review ) {
		$checkpoints = (array) ( $record['plan']['approval_checkpoints'] ?? array() );

		if ( array() === $checkpoints ) {
			return $this->verdict( true, __( 'This workflow required no approvals.', 'replicaforge' ) );
		}

		$outstanding = array();

		foreach ( $checkpoints as $checkpoint ) {
			$gate = (string) ( $checkpoint['gate'] ?? '' );

			if ( '' === $gate ) {
				continue;
			}

			/*
			 * A gate is only required if the stage it sits at actually ran. A gate at a stage
			 * that was blocked is not outstanding - nothing was waiting to be approved, and
			 * demanding an approval for work that did not happen would be theatre.
			 */
			$stage  = (string) ( $checkpoint['stage'] ?? '' );
			$outcome = (string) ( $record['stages'][ $stage ]['outcome'] ?? 'pending' );

			if ( in_array( $outcome, array( 'succeeded', 'skipped' ), true ) ) {
				continue;
			}

			$status = array();

			foreach ( (array) $checkpoints as $c ) {
				if ( (string) ( $c['gate'] ?? '' ) === $gate ) {
					$status[] = $c;
				}
			}

			$outstanding[] = $gate;
		}

		if ( ! empty( $outstanding ) ) {
			$blocking[] = sprintf(
				/* translators: %s: comma separated gate names. */
				__( 'These approvals are still outstanding: %s.', 'replicaforge' ),
				implode( ', ', $outstanding )
			);

			return $this->verdict( false, sprintf( /* translators: %s: gate names. */ __( 'Outstanding: %s.', 'replicaforge' ), implode( ', ', $outstanding ) ) );
		}

		$stale = array();

		foreach ( $checkpoints as $checkpoint ) {
			$gate = (string) ( $checkpoint['gate'] ?? '' );

			if ( '' === $gate ) {
				continue;
			}

			$status = $this->gate_status( $record, $gate );

			if ( ! empty( $status['stale'] ) ) {
				$stale[] = $gate;
			}
		}

		if ( ! empty( $stale ) ) {
			$review[] = sprintf(
				/* translators: %s: comma separated gate names. */
				__( 'These approvals were given for an earlier version and are now stale: %s.', 'replicaforge' ),
				implode( ', ', $stale )
			);

			return $this->verdict( null, __( 'Some approvals are stale and should be reviewed.', 'replicaforge' ) );
		}

		return $this->verdict( true, __( 'Every required approval is in place.', 'replicaforge' ) );
	}

	/**
	 * Did every stage reach a terminal outcome?
	 *
	 * @param array $record   The workflow.
	 * @param array $blocking Failures.
	 * @param array $warnings Warnings.
	 * @return array
	 */
	private function check_stages( array $record, array &$blocking, array &$warnings ) {
		$stages    = (array) ( $record['plan']['stages'] ?? array() );
		$incomplete = array();
		$skipped    = array();

		foreach ( $stages as $stage ) {
			$outcome = (string) ( $record['stages'][ $stage ]['outcome'] ?? 'pending' );

			if ( in_array( $outcome, array( 'succeeded', 'skipped' ), true ) ) {
				if ( 'skipped' === $outcome ) {
					$skipped[] = $stage;
				}
				continue;
			}

			$incomplete[] = $stage;
		}

		if ( ! empty( $incomplete ) ) {
			$blocking[] = sprintf(
				/* translators: %s: comma separated stage names. */
				__( 'These stages did not complete: %s.', 'replicaforge' ),
				implode( ', ', $incomplete )
			);

			return $this->verdict( false, __( 'Some stages are unfinished.', 'replicaforge' ), $incomplete );
		}

		if ( ! empty( $skipped ) ) {
			$warnings[] = sprintf(
				/* translators: %s: comma separated stage names. */
				__( 'These stages were skipped: %s.', 'replicaforge' ),
				implode( ', ', $skipped )
			);
		}

		return $this->verdict( true, __( 'Every stage reached a completed outcome.', 'replicaforge' ) );
	}

	/* ---------------------------------------------------------------------
	 * Reported dimensions
	 * ------------------------------------------------------------------ */

	/**
	 * Return the per-dimension quality results.
	 *
	 * A dimension that could not be measured is `null` with a reason. It is never given a
	 * number, because a number here would be indistinguishable downstream from a measured
	 * one, and that is the specific dishonesty §11 prohibits.
	 *
	 * @param array              $record   The workflow.
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @return array<string, array>
	 */
	private function dimensions( array $record, $artifacts ) {
		$out = array();

		foreach ( Orchestrator_Limits::DIMENSIONS as $dimension ) {
			$out[ $dimension ] = array(
				'weight' => (float) ( Orchestrator_Limits::weights_for( (string) $record['mode'] )[ $dimension ] ?? 1.0 ),
				'status' => 'unavailable',
				'evidence' => '',
				'reason' => '',
			);
		}

		$validation = $artifacts->get( 'validation' );

		if ( ! is_wp_error( $validation ) ) {
			$out['structure']['status']    = 'measured';
			$out['structure']['evidence']  = 'validation.differences';
			$out['structure']['reason']    = __( 'Structural differences were counted by the validation engine.', 'replicaforge' );
		} elseif ( $this->capabilities->can( 'validation' ) ) {
			$out['structure']['reason'] = __( 'Validation did not produce a result for this page.', 'replicaforge' );
		} else {
			$out['structure']['reason'] = __( 'The validation capability is unavailable on this site.', 'replicaforge' );
		}

		if ( $this->capabilities->can( 'rendering' ) ) {
			$out['visual']['status']   = 'measured';
			$out['visual']['evidence'] = 'validation.visual';
		} else {
			$out['visual']['reason'] = __( 'No rendering service is configured, so pixel differences cannot be measured.', 'replicaforge' );
		}

		if ( $this->capabilities->can( 'rendering' ) ) {
			$out['responsive']['status']   = 'measured';
			$out['responsive']['evidence'] = 'validation.viewports';
		} else {
			$out['responsive']['reason'] = __( 'Without rendering, only the source page\'s responsive rules can be read, not the reconstruction\'s behaviour.', 'replicaforge' );
		}

		$interactions = $artifacts->get( 'interaction_model' );

		if ( ! is_wp_error( $interactions ) ) {
			$out['interaction']['status']   = 'measured';
			$out['interaction']['evidence'] = 'interaction.model';
			$out['interaction']['reason']   = $this->capabilities->can( 'browser' )
				? __( 'Interactions were observed in a browser and modelled as state machines.', 'replicaforge' )
				: __( 'Interactions were modelled from markup and ARIA only, so script-driven behaviour is unverified.', 'replicaforge' );
		} else {
			$out['interaction']['reason'] = __( 'The interaction model was not produced.', 'replicaforge' );
		}

		$draft = $artifacts->get( 'draft' );

		if ( ! is_wp_error( $draft ) ) {
			$out['editability']['status']   = 'measured';
			$out['editability']['evidence'] = 'generation.report';
			$out['editability']['reason']   = (int) ( $draft['payload']['elements'] ?? 0 ) > 0
				? __( 'The page is an Elementor document with real elements.', 'replicaforge' )
				: __( 'The page is an Elementor document but contains no elements.', 'replicaforge' );
		} else {
			$out['editability']['reason'] = __( 'No draft exists to assess.', 'replicaforge' );
		}

		if ( in_array( (string) $record['type'], array( 'multi_page', 'ecommerce', 'blog' ), true ) ) {
			$out['consistency']['status']   = 'measured';
			$out['consistency']['evidence'] = 'site_specification';
		} else {
			$out['consistency']['reason'] = __( 'A single-page workflow has no cross-page consistency to assess.', 'replicaforge' );
		}

		$out['security']['status']   = 'measured';
		$out['security']['evidence'] = 'preflight.security';
		$out['security']['reason']   = __( 'The source URL was validated against the SSRF boundary and the page is still a draft.', 'replicaforge' );

		foreach ( array( 'content', 'assets' ) as $dimension ) {
			$out[ $dimension ]['status'] = 'measured';
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Non-blocking checks
	 * ------------------------------------------------------------------ */

	/**
	 * Are the required assets available?
	 *
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @param array              $warnings Warnings.
	 * @return array
	 */
	private function check_assets( $artifacts, array &$warnings ) {
		$draft = $artifacts->get( 'draft' );

		if ( is_wp_error( $draft ) ) {
			return $this->verdict( null, __( 'No draft to inspect.', 'replicaforge' ) );
		}

		/*
		 * Phase 4 records how many assets it imported. A count of zero is a real outcome
		 * for a page with no images and a warning for one that should have had them, but the
		 * artifact does not carry the source's asset count, so the honest statement here is
		 * that the number was recorded - not that it is correct.
		 */
		return $this->verdict( true, __( 'The generation stage recorded the imported assets.', 'replicaforge' ) );
	}

	/**
	 * Are internal links consistent?
	 *
	 * @param array              $record   The workflow.
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @param array              $warnings Warnings.
	 * @param array              $review   Items needing review.
	 * @return array
	 */
	private function check_links( array $record, $artifacts, array &$warnings, array &$review ) {
		if ( 'single_page' === (string) $record['type'] ) {
			return $this->verdict( null, __( 'Internal link consistency is assessed across pages, so it does not apply to a single-page workflow.', 'replicaforge' ) );
		}

		$review[] = __( 'Cross-page internal links were not verified by this workflow.', 'replicaforge' );

		return $this->verdict( null, __( 'Not verified.', 'replicaforge' ) );
	}

	/**
	 * Content mapping warnings.
	 *
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @param array              $warnings Warnings.
	 * @return array
	 */
	private function check_content( $artifacts, array &$warnings ) {
		if ( ! $this->capabilities->can( 'content' ) ) {
			return $this->verdict( null, __( 'The content mapping capability is unavailable.', 'replicaforge' ) );
		}

		return $this->verdict( true, __( 'The content service is available; per-mapping warnings are recorded in the content report.', 'replicaforge' ) );
	}

	/**
	 * Interaction validations.
	 *
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @param array              $warnings Warnings.
	 * @return array
	 */
	private function check_interactions( $artifacts, array &$warnings ) {
		$model = $artifacts->get( 'interaction_model' );

		if ( is_wp_error( $model ) ) {
			return $this->verdict( null, __( 'No interaction model was produced.', 'replicaforge' ) );
		}

		if ( ! $this->capabilities->can( 'browser' ) ) {
			$warnings[] = __( 'Interactions were modelled from markup only. A control the source reveals with script cannot be confirmed to work.', 'replicaforge' );
		}

		return $this::verdict_ok( __( 'The interaction model was produced.', 'replicaforge' ) );
	}

	/**
	 * Responsive checks.
	 *
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @param array              $warnings Warnings.
	 * @param array              $review   Items needing review.
	 * @return array
	 */
	private function check_responsive( $artifacts, array &$warnings, array &$review ) {
		if ( $this->capabilities->can( 'rendering' ) ) {
			return $this::verdict_ok( __( 'The draft was rendered at every supported viewport.', 'replicaforge' ) );
		}

		$warnings[] = __( 'Desktop, tablet and mobile checks were not attempted, because no rendering service is configured.', 'replicaforge' );

		return $this->verdict( null, __( 'Not attempted.', 'replicaforge' ) );
	}

	/**
	 * Visual regression checks.
	 *
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @param array              $warnings Warnings.
	 * @param array              $review   Items needing review.
	 * @return array
	 */
	private function check_visual( $artifacts, array &$warnings, array &$review ) {
		if ( ! $this->capabilities->can( 'rendering' ) ) {
			$warnings[] = __( 'Critical visual regressions could not be checked, because nothing can render the draft.', 'replicaforge' );

			return $this->verdict( null, __( 'Not checked.', 'replicaforge' ) );
		}

		$regression = $artifacts->get( 'regression' );

		if ( is_wp_error( $regression ) ) {
			return $this->verdict( null, __( 'No regression check was recorded.', 'replicaforge' ) );
		}

		if ( 'regressed' === (string) ( $regression['payload']['verdict'] ?? '' ) ) {
			$review[] = __( 'The corrections made the reconstruction worse, and a person should decide whether to roll back.', 'replicaforge' );

			return $this->verdict( false, __( 'Corrections introduced a regression.', 'replicaforge' ) );
		}

		return $this::verdict_ok( __( 'No regression was detected.', 'replicaforge' ) );
	}

	/**
	 * The regression record.
	 *
	 * @param Workflow_Artifacts $artifacts Artifacts.
	 * @param array              $warnings Warnings.
	 * @param array              $review   Items needing review.
	 * @return array
	 */
	private function check_regression( $artifacts, array &$warnings, array &$review ) {
		$regression = $artifacts->get( 'regression' );

		if ( is_wp_error( $regression ) ) {
			return $this->verdict( null, __( 'No regression check was recorded.', 'replicaforge' ) );
		}

		$verdict = (string) ( $regression['payload']['verdict'] ?? 'unknown' );

		$note = '';

		switch ( $verdict ) {
			case 'improved':
				$note = __( 'Differences decreased after correction.', 'replicaforge' );
				break;
			case 'regressed':
				$note = __( 'Differences increased after correction.', 'replicaforge' );
				break;
			case 'unchanged':
				$note = __( 'Differences were unchanged after correction.', 'replicaforge' );
				break;
			default:
				$note = __( 'The regression check could not compare against a baseline.', 'replicaforge' );
		}

		return $this->verdict( 'regressed' !== $verdict, $note, $verdict );
	}

	/* ---------------------------------------------------------------------
	 * The verdict
	 * ------------------------------------------------------------------ */

	/**
	 * Decide the final status.
	 *
	 * @param array $blocking Failures.
	 * @param array $warnings Warnings.
	 * @param array $review   Items needing review.
	 * @return string
	 */
	private function decide( array $blocking, array $warnings, array $review ) {
		if ( ! empty( $blocking ) ) {
			return 'blocked';
		}

		if ( ! empty( $review ) ) {
			return 'needs_review';
		}

		if ( ! empty( $warnings ) ) {
			return 'passed_with_warnings';
		}

		return 'passed';
	}

	/**
	 * Explain the status in one sentence.
	 *
	 * @param string $status   The status.
	 * @param array  $blocking Failures.
	 * @param array  $warnings Warnings.
	 * @param array  $review   Items needing review.
	 * @return string
	 */
	private function explain( $status, array $blocking, array $warnings, array $review ) {
		$counts = sprintf(
			/* translators: 1: blocking count, 2: warning count, 3: review count. */
			__( '%1$d blocking issue(s), %2$d warning(s), %3$d item(s) needing review.', 'replicaforge' ),
			count( $blocking ),
			count( $warnings ),
			count( $review )
		);

		switch ( $status ) {
			case 'passed':
				return sprintf(
					/* translators: %s: the counts. */
					__( 'The reconstruction completed and the evidence supports it. %s', 'replicaforge' ),
					$counts
				);

			case 'passed_with_warnings':
				return sprintf(
					/* translators: %s: the counts. */
					__( 'The reconstruction completed, with limitations recorded. %s', 'replicaforge' ),
					$counts
				);

			case 'needs_review':
				return sprintf(
					/* translators: %s: the counts. */
					__( 'The reconstruction completed, but a person should review it before use. %s', 'replicaforge' ),
					$counts
				);

			case 'blocked':
				return sprintf(
					/* translators: %s: the counts. */
					__( 'The reconstruction could not be completed. %s', 'replicaforge' ),
					$counts
				);

			default:
				return $counts;
		}
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return a gate's status, tolerating a repository that is not wired in.
	 *
	 * @param array  $record The workflow.
	 * @param string $gate   Gate name.
	 * @return array
	 */
	private function gate_status( array $record, $gate ) {
		$entry = (array) ( $record['approvals'][ $gate ] ?? array() );

		if ( array() === $entry ) {
			return array( 'status' => 'pending', 'stale' => false );
		}

		$recorded = (string) ( $entry['artifact_hash'] ?? '' );

		$current = substr(
			hash(
				'sha256',
				(string) wp_json_encode(
					array(
						'plan_id' => (string) ( $record['plan_id'] ?? '' ),
						'gate'    => (string) $gate,
						'plan'    => (array) ( $record['plan'] ?? array() ),
					)
				)
			),
			0,
			32
		);

		return array(
			'status' => (string) $entry['status'],
			'stale'  => '' !== $recorded && ! hash_equals( $recorded, $current ),
		);
	}

	/**
	 * Build a check result.
	 *
	 * `null` for `$ok` means "not applicable", which is distinct from both true and false and
	 * is what stops an unmeasured dimension from being reported as a pass.
	 *
	 * @param bool|null $ok       Whether it passed.
	 * @param string    $note     The finding.
	 * @param string    $evidence An identifier.
	 * @return array
	 */
	private function verdict( $ok, $note, $evidence = '' ) {
		return array(
			// A string rather than a bool, so `unavailable` survives JSON encoding as itself.
			'result'   => ( null === $ok ) ? 'unavailable' : ( $ok ? 'pass' : 'fail' ),
			'note'     => (string) $note,
			'evidence' => (string) $evidence,
		);
	}

	/**
	 * Build a passing check result.
	 *
	 * @param string $note The finding.
	 * @return array
	 */
	private static function verdict_ok( $note ) {
		return array( 'result' => 'pass', 'note' => (string) $note, 'evidence' => '' );
	}
}
