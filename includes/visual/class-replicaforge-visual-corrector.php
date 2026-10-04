<?php
/**
 * Phase 13: the bounded visual correction loop, and cross-viewport regression.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Plans and bounds visual corrections, and refuses a fix that helps one viewport by
 * breaking another.
 *
 * ### The loop is bounded by construction
 *
 * §66 requires generate → render → compare → detect → plan → apply → render again →
 * compare again, with a maximum number of iterations. A loop that is "bounded" by a
 * counter that a caller can pass in is not bounded, so
 * {@see Visual_Limits::MAX_CORRECTION_ITERATIONS} is the only source of the number
 * and {@see self::iterate()} clamps whatever it is given. A caller asking for ten
 * iterations gets three, and is told it got three.
 *
 * ### Why regression protection is not optional
 *
 * §68 is the requirement that makes this class worth having. The obvious way to fix a
 * desktop difference — widen a container, change a margin, hide a section — very
 * often makes mobile worse, because a fixed width that happened to fit at 1440 is
 * what was keeping a 390px layout from collapsing. Correcting at desktop only is how a
 * replica ends up pixel-accurate on the screenshot and broken on the phone.
 *
 * So every proposed correction is checked at **all** viewports, and a change that
 * improves one while regressing another is not applied — it is *reported*. The
 * report is the useful output: "fixing the desktop spacing would overlap the mobile
 * navigation" is something a user can act on, and a silent fix is not.
 */
final class Visual_Corrector {

	/**
	 * Registry.
	 *
	 * @var Component_Registry
	 */
	private $registry;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Component_Registry|null $registry Optional registry.
	 * @param Logger|null             $logger   Optional logger.
	 */
	public function __construct( $registry = null, $logger = null ) {
		$this->logger   = $logger instanceof Logger ? $logger : new Logger();
		$this->registry = $registry instanceof Component_Registry ? $registry : new Component_Registry( $this->logger );
	}

	/**
	 * Plan corrections from a comparison report.
	 *
	 * @param array<string, mixed> $report Comparison report.
	 * @return array<string, mixed>
	 */
	public function plan( array $report ) {
		$differences = isset( $report['differences'] ) && is_array( $report['differences'] ) ? $report['differences'] : array();
		$ordered    = $this->prioritise( $differences );
		$eligible   = array();
		$refused    = array();

		foreach ( $ordered as $difference ) {
			$verdict = $this->eligibility( $difference );
			if ( ! $verdict['eligible'] ) {
				$refused[] = array_merge( $difference, array( 'refused_because' => $verdict['reason'] ) );
				continue;
			}
			$eligible[] = array_merge( $difference, array(
				'phase6_category' => $this->phase6_category( $difference ),
				'priority'        => (int) $verdict['priority'],
				'scope'           => (string) $verdict['scope'],
			) );
		}

		usort(
			$eligible,
			static function ( $left, $right ) {
				return (int) $left['priority'] <=> (int) $right['priority'];
			}
		);

		return array(
			'schema_version' => Visual_Limits::SCHEMA_VERSION,
			'eligible'       => $eligible,
			'refused'        => $refused,
			'counts'         => array( 'total' => count( $differences ), 'eligible' => count( $eligible ), 'refused' => count( $refused ) ),
			'order'          => Visual_Limits::categories(),
			// §65: before and after are rendered results, so the loop records which
			// iteration each comparison belongs to and never compares a report to
			// itself.
			'iteration_budget' => Visual_Limits::MAX_CORRECTION_ITERATIONS,
			'note'           => __( 'Corrections are applied one at a time and re-measured. A correction that improves one viewport while regressing another is not applied.', 'replicaforge' ),
		);
	}

	/**
	 * Run the bounded iteration loop.
	 *
	 * The callback is invoked once per iteration and must return a comparison report.
	 * Nothing is written by this class — the loop measures, decides, and reports, and
	 * the caller applies. A corrector that both measures and writes cannot be
	 * reasoned about when an iteration goes wrong.
	 *
	 * @param callable             $compare  Returns a comparison report.
	 * @param callable             $apply    Applies a planned correction.
	 * @param int                  $requested Requested iterations, clamped.
	 * @return array<string, mixed>
	 */
	public function iterate( callable $compare, callable $apply, $requested = 0 ) {
		// Clamped here rather than trusted. A caller asking for ten gets three.
		$iterations = ( $requested > 0 ) ? (int) $requested : Visual_Limits::MAX_CORRECTION_ITERATIONS;
		$iterations = max( 1, min( Visual_Limits::MAX_CORRECTION_ITERATIONS, $iterations ) );

		$history  = array();
		$previous = null;
		$applied  = array();

		for ( $i = 1; $i <= $iterations; $i++ ) {
			$report = $compare( $i );
			$state  = array(
				'iteration'   => $i,
				'verdict'     => (string) ( $report['verdict'] ?? 'unavailable' ),
				'score'       => isset( $report['score'] ) ? (float) $report['score'] : 0.0,
				'differences' => (int) count( (array) ( $report['differences'] ?? array() ) ),
				'severity'    => (string) ( $report['severity'] ?? 'informational' ),
			);

			if ( null !== $previous ) {
				$state['improved'] = ( $state['score'] > $previous['score'] );
				$state['delta']    = round( $state['score'] - $previous['score'], 4 );
				// A regression stops the loop. Continuing past one means the loop is
				// now making things worse and calling it optimisation.
				if ( ! $state['improved'] ) {
					$state['stopped'] = 'no_improvement';
					$history[]        = $state;
					$this->logger->info(
						'visual_correction_stopped',
						'A visual correction iteration did not improve the comparison, so the loop stopped.',
						array( 'iteration' => $i, 'delta' => $state['delta'] ),
						'visual'
					);
					break;
				}
			}

			$history[]  = $state;
			$previous   = $state;

			if ( 'pass' === $state['verdict'] || 'unavailable' === $state['verdict'] ) {
				break;
			}

			$plan   = $this->plan( $report );
			$next   = $this->first_actionable( $plan );
			if ( null === $next ) {
				$history[ count( $history ) - 1 ]['stopped'] = 'nothing_eligible';
				break;
			}

			$applied[] = $next;
			$apply( $next, $i );
		}

		return array(
			'schema_version' => Visual_Limits::SCHEMA_VERSION,
			'iterations'     => count( $history ),
			'max_iterations' => $iterations,
			'requested'      => (int) $requested,
			'clamped'        => ( $requested > Visual_Limits::MAX_CORRECTION_ITERATIONS ),
			'history'        => $history,
			'applied'        => $applied,
			'final'          => ( array() === $history ) ? null : $history[ count( $history ) - 1 ],
			'note'           => __( 'The loop stops on the first iteration that does not improve the comparison, or when the iteration budget is reached. It never exceeds the budget.', 'replicaforge' ),
		);
	}

	/**
	 * Check a correction across every viewport §68 requires.
	 *
	 * @param array<int, array<string, mixed>> $before Per-viewport reports before.
	 * @param array<int, array<string, mixed>> $after  Per-viewport reports after.
	 * @return array<string, mixed>
	 */
	public function regression_check( array $before, array $after ) {
		$viewports = array_keys( $before );
		$improved  = array();
		$regressed = array();
		$unchanged = array();
		$unknown   = array();

		foreach ( $viewports as $viewport ) {
			if ( ! isset( $after[ $viewport ] ) ) {
				// §79: a viewport that could not be measured is unknown, not passing.
				$unknown[ $viewport ] = __( 'No comparison is available for this viewport, so the change could not be checked here.', 'replicaforge' );
				continue;
			}
			$was = (float) ( $before[ $viewport ]['score'] ?? 0.0 );
			$now = (float) ( $after[ $viewport ]['score'] ?? 0.0 );
			$delta = round( $now - $was, 4 );

			// A small band either way is noise, not a change. Reporting a 0.001
			// improvement as a win is how a loop convinces itself it is working.
			if ( $delta > 0.01 ) {
				$improved[ $viewport ] = $delta;
			} elseif ( $delta < -0.01 ) {
				$regressed[ $viewport ] = $delta;
			} else {
				$unchanged[ $viewport ] = $delta;
			}
		}

		$verdict = 'neutral';
		if ( array() !== $regressed && array() !== $improved ) {
			$verdict = 'mixed';
		} elseif ( array() !== $regressed ) {
			$verdict = 'regressed';
		} elseif ( array() !== $improved ) {
			$verdict = 'improved';
		}

		return array(
			'schema_version' => Visual_Limits::SCHEMA_VERSION,
			'verdict'        => (string) $verdict,
			'apply'          => ( 'regressed' === $verdict || 'mixed' === $verdict ) ? 'no' : 'yes',
			'improved'       => $improved,
			'regressed'      => $regressed,
			'unchanged'      => $unchanged,
			'unknown'        => $unknown,
			'note'           => ( 'regressed' === $verdict || 'mixed' === $verdict )
				? __( 'This change makes at least one viewport worse, so ReplicaForge will not apply it. Fixing desktop spacing by a fixed width is the usual cause.', 'replicaforge' )
				: '',
		);
	}

	/**
	 * Build a before-and-after comparison §65 asks for.
	 *
	 * @param array<int, array<string, mixed>> $before  Reports before.
	 * @param array<int, array<string, mixed>> $after   Reports after.
	 * @return array<string, mixed>
	 */
	public function before_after( array $before, array $after ) {
		$rows = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $viewport ) {
			$was = $before[ $viewport ] ?? null;
			$now = $after[ $viewport ] ?? null;
			$rows[] = array(
				'viewport'      => (string) $viewport,
				'before'        => null === $was ? null : $this->summarise( $was ),
				'after'         => null === $now ? null : $this->summarise( $now ),
				'has_renders'   => ( null !== $was && null !== $now ),
				'improved'      => ( null !== $was && null !== $now ) ? ( (float) ( $now['score'] ?? 0 ) > (float) ( $was['score'] ?? 0 ) ) : null,
				'evidence_only' => ( null === $was || null === $now ),
			);
		}
		return array(
			'schema_version' => Visual_Limits::SCHEMA_VERSION,
			'rows'           => $rows,
			'note'           => __( 'Before and after are actual rendered comparisons. Where a render is missing the row says so rather than showing an improvement that was not measured.', 'replicaforge' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Order differences by §67's priority.
	 *
	 * Structure first and shadows last, because a corrected container width that then
	 * gets a corrected radius will be corrected *again* by the width fix, and a loop
	 * that alternates between two properties never converges.
	 *
	 * @param array<int, array<string, mixed>> $differences Differences.
	 * @return array<int, array<string, mixed>>
	 */
	private function prioritise( array $differences ) {
		$scale = array(
			'structure'  => 10,
			'layout'     => 20,
			'size'       => 30,
			'position'   => 40,
			'alignment'  => 50,
			'layering'   => 55,
			'responsive' => 60,
			'image'      => 70,
			'typography' => 80,
			'spacing'    => 90,
			'color'      => 100,
			'background' => 105,
			'shadow'     => 110,
			'border'     => 115,
			'radius'     => 120,
			'visibility' => 90,
		);

		$out = array();
		foreach ( $differences as $difference ) {
			if ( ! is_array( $difference ) ) {
				continue;
			}
			$category = (string) ( $difference['category'] ?? 'layout' );
			$out[]    = $difference;
			$out[ count( $out ) - 1 ]['_rank'] = $scale[ $category ] ?? 999;
		}

		usort(
			$out,
			static function ( $left, $right ) {
				if ( $left['_rank'] === $right['_rank'] ) {
					$order = array( 'critical' => 0, 'major' => 1, 'moderate' => 2, 'minor' => 3, 'informational' => 4 );
					$ls = $order[ (string) ( $left['severity'] ?? '' ) ] ?? 9;
					$rs = $order[ (string) ( $right['severity'] ?? '' ) ] ?? 9;
					return $ls <=> $rs;
				}
				return $left['_rank'] <=> $right['_rank'];
			}
		);

		foreach ( $out as $index => $difference ) {
			unset( $out[ $index ]['_rank'] );
		}
		return array_values( $out );
	}

	/**
	 * Decide whether a difference is eligible for correction.
	 *
	 * @param array<string, mixed> $difference Difference.
	 * @return array<string, mixed>
	 */
	private function eligibility( array $difference ) {
		$severity = (string) ( $difference['severity'] ?? 'informational' );
		$code     = (string) ( $difference['code'] ?? '' );
		$evidence = (array) ( $difference['evidence'] ?? array() );

		// A masked region's difference is not eligible: it is not evidence of a fault.
		if ( ! empty( $evidence['masked'] ) ) {
			return array( 'eligible' => false, 'reason' => __( 'The difference is inside a region that was masked as dynamic, so it is not a fault to correct.', 'replicaforge' ), 'priority' => 999, 'scope' => 'none' );
		}

		// A size mismatch means the captures were not aligned. Correcting the replica
		// to make two differently-sized images agree would corrupt the design.
		if ( 'capture_size_mismatch' === $code ) {
			return array( 'eligible' => false, 'reason' => __( 'The two captures are different sizes, so the difference is a capture problem rather than a reconstruction fault.', 'replicaforge' ), 'priority' => 999, 'scope' => 'none' );
		}

		// A dynamic region.
		if ( in_array( $code, array( 'pixel_difference' ), true ) && ! empty( $evidence['masked'] ) ) {
			return array( 'eligible' => false, 'reason' => __( 'Masked region.', 'replicaforge' ), 'priority' => 999, 'scope' => 'none' );
		}

		$category = (string) ( $difference['category'] ?? 'layout' );
		$rank     = array(
			'structure' => 10, 'layout' => 20, 'size' => 30, 'position' => 40, 'alignment' => 50,
			'layering' => 55, 'responsive' => 60, 'image' => 70, 'typography' => 80,
			'spacing' => 90, 'color' => 100, 'background' => 105, 'shadow' => 110,
			'border' => 115, 'radius' => 120,
		);

		// A shared component the user has edited is not correctable — Phase 6's
		// existing rule, re-read from the registry rather than reimplemented.
		$component_id = (string) ( $evidence['component_id'] ?? '' );
		if ( '' !== $component_id && ! $this->registry->may_rewrite( $component_id ) ) {
			return array( 'eligible' => false, 'reason' => __( 'You have customized this component, so ReplicaForge will not change it.', 'replicaforge' ), 'priority' => 999, 'scope' => 'none' );
		}

		if ( 'informational' === $severity ) {
			return array( 'eligible' => false, 'reason' => __( 'Informational differences are recorded but not corrected.', 'replicaforge' ), 'priority' => 999, 'scope' => 'none' );
		}

		return array(
			'eligible' => true,
			'reason'   => '',
			'priority' => (int) ( $rank[ $category ] ?? 200 ),
			// §46: prefer the shared component. When the difference names one, that is
			// the scope — correcting every page individually is what §46 exists to avoid.
			'scope'    => ( '' !== $component_id ) ? 'shared_component' : 'page',
		);
	}

	/**
	 * Return the Phase 6 category a difference maps to.
	 *
	 * @param array<string, mixed> $difference Difference.
	 * @return string
	 */
	private function phase6_category( array $difference ) {
		$map = array(
			'structure'   => 'structure',
			'section_order' => 'structure',
			'layout'      => 'geometry',
			'size'        => 'geometry',
			'position'    => 'geometry',
			'alignment'   => 'geometry',
			'layering'    => 'geometry',
			'spacing'     => 'spacing',
			'typography'  => 'typography',
			'color'       => 'color',
			'background'  => 'background',
			'image'       => 'assets',
			'asset'       => 'assets',
			'shadow'      => 'effects',
			'border'      => 'effects',
			'radius'      => 'effects',
			'visibility'  => 'structure',
			'responsive'  => 'responsive',
		);
		$category = (string) ( $difference['category'] ?? 'layout' );
		return (string) ( $map[ $category ] ?? 'geometry' );
	}

	/**
	 * Return the first eligible correction, or null.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array<string, mixed>|null
	 */
	private function first_actionable( array $plan ) {
		foreach ( (array) ( $plan['eligible'] ?? array() ) as $correction ) {
			return $correction;
		}
		return null;
	}

	/**
	 * Summarise a comparison report for a before/after row.
	 *
	 * @param array<string, mixed> $report Report.
	 * @return array<string, mixed>
	 */
	private function summarise( array $report ) {
		$counts = array();
		foreach ( Visual_Limits::severities() as $severity ) {
			$counts[ $severity ] = 0;
		}
		foreach ( (array) ( $report['differences'] ?? array() ) as $difference ) {
			$severity = (string) ( $difference['severity'] ?? '' );
			if ( isset( $counts[ $severity ] ) ) {
				$counts[ $severity ]++;
			}
		}
		return array(
			'verdict'     => (string) ( $report['verdict'] ?? 'unavailable' ),
			'score'       => (float) ( $report['score'] ?? 0.0 ),
			'differences' => (int) count( (array) ( $report['differences'] ?? array() ) ),
			'severities'  => $counts,
		);
	}
}
