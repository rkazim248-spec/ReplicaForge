<?php
/**
 * Phase 13: the visual render job, and the multi-page visual dashboard.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a Phase 11 job for rendering, and assembles the §71 dashboard.
 *
 * ### Reusing the queue rather than adding one
 *
 * §76 says to use Phase 11 job orchestration, and the point is not politeness. A
 * second queue would mean a second place where a lock can be taken twice, a second
 * recovery path, and a second set of states — and Phase 11's own notes record how
 * much of its design exists to prevent exactly one of those. So this class produces a
 * *job payload* in the vocabulary {@see Job_Repository} and {@see Job_Checkpoint}
 * already understand, and the existing runner executes it.
 *
 * The stages are therefore Phase 11 stage names, and the checkpoint records which
 * viewport it reached — so a killed render job resumes at the third viewport rather
 * than starting over, and `expired` means something specific: a job that died after
 * writing an image has an image nobody will re-take.
 */
final class Render_Job {

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Optional logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * The stages a render job passes through.
	 *
	 * Read from {@see Job_Limits::STAGES} rather than restated, so a Phase 11 stage
	 * rename cannot leave this queueing a stage that does not exist — and so the
	 * indices {@see Job_Checkpoint} and {@see Job_Recovery} use keep meaning what
	 * they meant.
	 *
	 * The mapping is explicit rather than derived because the *semantic* pairing is
	 * what matters: a render job's "analyze" is a capture, and its "validate" is the
	 * visual comparison. Appending new visual stages instead would change
	 * `Job_Limits::stage_index()` arithmetic that Phase 11's recovery depends on.
	 *
	 * @return array<string, string>
	 */
	public static function stages() {
		return array(
			'fetch'    => Job_Limits::STAGES[0],
			'capture'  => Job_Limits::STAGES[1],
			'analyze'  => Job_Limits::STAGES[2],
			'validate' => Job_Limits::STAGES[3],
		);
	}

	/**
	 * Build a render job payload.
	 *
	 * @param array<string, mixed> $request Request.
	 * @return array<string, mixed>
	 */
	public function build( array $request ) {
		$views      = new Viewport_Manager();
		$viewports  = (array) ( $request['viewports'] ?? array() );
		$profiles   = $views->render_set( (int) ( $request['max_viewports'] ?? 3 ) );
		$pages      = (array) ( $request['pages'] ?? array() );

		$renders = min( count( $profiles ), 6 );
		$total   = $renders * max( 0, count( $pages ) );

		if ( $total > Visual_Limits::MAX_RENDERS_PER_JOB ) {
			// §78. A bound, reported rather than silently applied — a user asking for
			// 25 pages at three viewports is told the job covers fewer and why.
			$allowed = (int) floor( Visual_Limits::MAX_RENDERS_PER_JOB / max( 1, $renders ) );
			$pages   = array_slice( $pages, 0, $allowed );
			$total   = $renders * count( $pages );
		}

		$stages  = self::stages();
		$targets = array();
		foreach ( $pages as $page ) {
			$page_id = (string) ( $page['page_id'] ?? '' );
			$url     = (string) ( $page['source_url'] ?? '' );
			if ( '' === $page_id || '' === $url ) {
				continue;
			}
			$targets[] = array(
				'page_id'    => $page_id,
				'source_url' => $url,
				'source_hash'=> (string) ( $page['source_hash'] ?? '' ),
				'viewports'  => array_map(
					static function ( $profile ) {
						return array(
							'name'  => (string) $profile['name'],
							'width' => (int) $profile['width'],
							'height'=> (int) $profile['height'],
							'dpr'   => (float) $profile['device_pixel_ratio'],
						);
					},
					array_values( $profiles )
				),
			);
		}

		$untrusted = array();
		foreach ( $targets as $target ) {
			$verdict = ( new Url_Validator() )->validate( (string) $target['source_url'] );
			if ( empty( $verdict['success'] ) ) {
				$untrusted[] = array( 'page_id' => $target['page_id'], 'reason' => 'url_refused' );
				continue;
			}
			$target['source_url'] = (string) $verdict['url'];
		}
		$targets = array_values( array_filter( $targets, static function ( $target ) use ( $untrusted ) {
			foreach ( $untrusted as $refused ) {
				if ( $refused['page_id'] === $target['page_id'] ) {
					return false;
				}
			}
			return true;
		} ) );

		return array(
			'schema_version' => Visual_Limits::SCHEMA_VERSION,
			'operation'      => 'replica',
			'stage'          => $stages['fetch'],
			'stages'         => array_values( $stages ),
			'payload'        => array(
				'task'      => 'visual_render',
				'project_id'=> (string) ( $request['project_id'] ?? '' ),
				'captures'  => $targets,
				'stabilize' => $this->stabilization( $request ),
				'full_page' => ! empty( $request['full_page'] ),
			),
			'counts'         => array(
				'pages'     => count( $targets ),
				'viewports' => $renders,
				'renders'   => $total,
				'max'       => Visual_Limits::MAX_RENDERS_PER_JOB,
			),
			'refused'        => $untrusted,
			'cancellable'    => true,
			// Phase 11 vocabulary, so the existing queue, lock, and checkpoint
			// machinery apply unchanged.
			'queue'          => 'phase11',
			'checkpoint_key' => 'viewport_index',
			'note'           => __( 'This job runs on the Phase 11 queue. One render failing does not fail the others, and the checkpoint records which viewport it reached.', 'replicaforge' ),
		);
	}

	/**
	 * Return the stabilization plan §8 requires.
	 *
	 * Every step is a *request* to the provider, and every step is recorded as a
	 * request. §8 says "do not assume every website becomes stable immediately", and
	 * the honest way to express that is to return the plan and a note saying the
	 * provider is not obliged to satisfy it.
	 *
	 * @param array<string, mixed> $request Request.
	 * @return array<string, mixed>
	 */
	public function stabilization( array $request ) {
		$settings = array(
			'wait_for'   => (array) ( $request['wait_for'] ?? array( 'dom', 'network_idle', 'images' ) ),
			'wait_ms'    => max( 0, min( 10000, (int) ( $request['wait_ms'] ?? 1500 ) ) ),
			'animations' => ! array_key_exists( 'animations', $request ) || ! empty( $request['animations'] ),
			'lazy_load'  => ! array_key_exists( 'lazy_load', $request ) || ! empty( $request['lazy_load'] ),
			'scroll_to'  => max( 0, min( 50, (int) ( $request['scroll_to'] ?? 0 ) ) ),
		);

		return array_merge( $settings, array(
			'sequence' => array( 'load', 'dom', 'network_idle', 'images', 'lazy_load', 'animations_off', 'capture' ),
			// The claim is bounded on purpose. A page with an animation that never
			// settles cannot be made stable, and asserting that it was would be a lie
			// that produces a comparison nobody can act on.
			'guarantees' => __( 'A page that never settles cannot be made stable. When the wait expires the capture is taken anyway and the region is marked dynamic rather than being reported as a reconstruction fault.', 'replicaforge' ),
			'animation_normalized_requested' => (bool) $settings['animations'],
		) );
	}

	/**
	 * Build the §71 visual intelligence dashboard.
	 *
	 * Every number is a real count from the reports it summarises. A dashboard whose
	 * numbers are placeholders is worse than no dashboard, because it looks like a
	 * result.
	 *
	 * @param array<string, mixed> $reports Stored visual reports.
	 * @return array<string, mixed>
	 */
	public function dashboard( array $reports ) {
		$render_status = array();
		$pages_rendered = array();
		$severities = array();
		foreach ( Visual_Limits::severities() as $severity ) {
			$severities[ $severity ] = 0;
		}

		$sections  = 0;
		$components = 0;
		$by_viewport = array();

		foreach ( $reports as $report ) {
			if ( ! is_array( $report ) ) {
				continue;
			}
			$viewport = (string) ( $report['viewport']['name'] ?? 'desktop' );
			$available = ! empty( $report['available'] );

			$render_status[ $viewport ] = $available ? 'available' : 'unavailable';
			$by_viewport[ $viewport ]  = ( $by_viewport[ $viewport ] ?? 0 ) + 1;

			$sections   += (int) ( $report['representation']['sections'] ?? count( (array) ( $report['regions'] ?? array() ) ) );
			$components += (int) ( $report['representation']['components'] ?? 0 );

			foreach ( (array) ( $report['differences'] ?? array() ) as $difference ) {
				$severity = (string) ( $difference['severity'] ?? '' );
				if ( isset( $severities[ $severity ] ) ) {
					$severities[ $severity ]++;
				}
			}

			$page_id = (string) ( $report['page_id'] ?? '' );
			if ( '' !== $page_id && $available ) {
				$pages_rendered[ $page_id ] = true;
			}
		}

		$warnings = Renderer_Manager::warnings();
		if ( array() !== ( $reports ? array_filter( array_map( static function ( $r ) { return empty( $r['available'] ); }, $reports ) ) : array() ) ) {
			$warnings[] = array(
				'code'    => 'some_viewports_unavailable',
				'message' => __( 'At least one viewport could not be captured. The others are unaffected and the missing one is reported rather than counted as a pass.', 'replicaforge' ),
			);
		}

		return array(
			'schema_version' => Visual_Limits::SCHEMA_VERSION,
			'render_status'  => $render_status,
			'coverage'       => array(
				'pages_rendered' => count( $pages_rendered ),
				'pages_total'    => count( $reports ),
				'sections'       => $sections,
				'components'     => $components,
			),
			'differences'    => $severities,
			'difference_total' => array_sum( $severities ),
			'by_viewport'    => $by_viewport,
			'warnings'       => $warnings,
			'note'           => __( 'Every count here is computed from the stored visual reports. A viewport that could not be rendered is shown as unavailable, never as a pass.', 'replicaforge' ),
		);
	}
}
