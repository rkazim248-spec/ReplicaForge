<?php
/**
 * Phase 4 Elementor generation orchestrator.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a validated Reconstruction Specification into an Elementor document and
 * a WordPress draft.
 *
 * The pipeline is deliberately linear and deterministic:
 *
 *     specification validation
 *         -> Elementor capability check
 *         -> asset resolution
 *         -> responsive plan
 *         -> element mapping
 *         -> document build
 *         -> document validation
 *         -> draft creation
 *         -> post-creation validation
 *
 * Phase 4 never re-fetches the analyzed page, never calls an AI provider, and
 * never accepts an Elementor document from the browser.
 */
final class Elementor_Generator {

	/**
	 * Compatibility service.
	 *
	 * @var Elementor_Compatibility
	 */
	private $compatibility;

	/**
	 * Specification validator.
	 *
	 * @var Elementor_Spec_Validator
	 */
	private $spec_validator;

	/**
	 * Phase 3 output validator used to re-check a supplied specification.
	 *
	 * @var Ai_Output_Validator
	 */
	private $spec_output_validator;

	/**
	 * Deterministic Phase 3 planner used when no specification is supplied.
	 *
	 * @var Ai_Reconstruction_Planner
	 */
	private $planner;

	/**
	 * Asset service.
	 *
	 * @var Elementor_Assets
	 */
	private $assets;

	/**
	 * Responsive service.
	 *
	 * @var Elementor_Responsive
	 */
	private $responsive;

	/**
	 * Widget registry.
	 *
	 * @var Elementor_Widget_Registry
	 */
	private $registry;

	/**
	 * Mapper.
	 *
	 * @var Elementor_Mapper
	 */
	private $mapper;

	/**
	 * Document builder.
	 *
	 * @var Elementor_Document_Builder
	 */
	private $builder;

	/**
	 * Document validator.
	 *
	 * @var Elementor_Validator
	 */
	private $validator;

	/**
	 * Draft service.
	 *
	 * @var Elementor_Draft_Service
	 */
	private $drafts;

	/**
	 * Repository.
	 *
	 * @var Elementor_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $services Optional service overrides.
	 */
	public function __construct( array $services = array() ) {
		$this->compatibility = isset( $services['compatibility'] ) && $services['compatibility'] instanceof Elementor_Compatibility
			? $services['compatibility']
			: new Elementor_Compatibility();
		$this->registry     = isset( $services['registry'] ) && $services['registry'] instanceof Elementor_Widget_Registry
			? $services['registry']
			: new Elementor_Widget_Registry( $this->compatibility );
		$this->spec_validator = isset( $services['spec_validator'] ) && $services['spec_validator'] instanceof Elementor_Spec_Validator
			? $services['spec_validator']
			: new Elementor_Spec_Validator();
		$this->spec_output_validator = isset( $services['spec_output_validator'] ) && $services['spec_output_validator'] instanceof Ai_Output_Validator
			? $services['spec_output_validator']
			: new Ai_Output_Validator();
		$this->planner = isset( $services['planner'] ) && $services['planner'] instanceof Ai_Reconstruction_Planner
			? $services['planner']
			: new Ai_Reconstruction_Planner();
		$this->assets = isset( $services['assets'] ) && $services['assets'] instanceof Elementor_Assets
			? $services['assets']
			: new Elementor_Assets();
		$this->responsive = isset( $services['responsive'] ) && $services['responsive'] instanceof Elementor_Responsive
			? $services['responsive']
			: new Elementor_Responsive();
		$this->mapper = isset( $services['mapper'] ) && $services['mapper'] instanceof Elementor_Mapper
			? $services['mapper']
			: new Elementor_Mapper( $this->registry );
		$this->builder = isset( $services['builder'] ) && $services['builder'] instanceof Elementor_Document_Builder
			? $services['builder']
			: new Elementor_Document_Builder();
		$this->validator = isset( $services['validator'] ) && $services['validator'] instanceof Elementor_Validator
			? $services['validator']
			: new Elementor_Validator( $this->registry );
		$this->drafts = isset( $services['drafts'] ) && $services['drafts'] instanceof Elementor_Draft_Service
			? $services['drafts']
			: new Elementor_Draft_Service( $this->compatibility, $this->validator );
		$this->repository = isset( $services['repository'] ) && $services['repository'] instanceof Elementor_Repository
			? $services['repository']
			: new Elementor_Repository();
	}

	/**
	 * Return the Elementor availability status for the admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public function status() {
		return $this->compatibility->status();
	}

	/**
	 * Describe what a generation would produce without writing anything.
	 *
	 * @param mixed                $representation Phase 2 representation.
	 * @param mixed                $specification  Optional Phase 3 specification.
	 * @param array<string, mixed> $options        Generation options.
	 * @return array<string, mixed>
	 */
	public function preview( $representation, $specification = null, array $options = array() ) {
		$prepared = $this->prepare( $representation, $specification, $options );
		if ( empty( $prepared['success'] ) ) {
			return $prepared;
		}

		return array(
			'success' => true,
			'data'    => array(
				'mode'               => 'preview',
				'preview'            => true,
				'report'             => $prepared['report'],
				'warnings'           => $prepared['warnings'],
				'errors'             => array(),
				'elementor'          => $this->public_compatibility(),
			),
		);
	}

	/**
	 * Generate an editable Elementor draft.
	 *
	 * @param mixed                $representation Phase 2 representation.
	 * @param mixed                $specification  Optional Phase 3 specification.
	 * @param array<string, mixed> $options        Generation options.
	 * @return array<string, mixed>
	 */
	public function generate( $representation, $specification = null, array $options = array() ) {
		Security::log_event( 'generation_started', array( 'code' => 'elementor_generate' ) );
		$started = microtime( true );
		$prepared = $this->prepare( $representation, $specification, $options );
		if ( empty( $prepared['success'] ) ) {
			return $prepared;
		}

		$meta = array(
			'generation_id'    => $prepared['generation_id'],
			'source_url'       => $prepared['source_url'],
			'source_title'     => $prepared['source_title'],
			'source_host'      => $prepared['source_host'],
			'specification_id' => $prepared['specification_id'],
			'ai_used'          => $prepared['ai_used'],
			'id_map'           => $prepared['id_map'],
			'report'           => $prepared['report'],
			'provenance'       => $prepared['asset_provenance'],
		);

		$created = $this->drafts->create( $prepared['elements'], $meta );

		if ( empty( $created['success'] ) ) {
			Security::log_event( 'generation_failed', array( 'code' => 'draft_creation_failed', 'reason' => 'elementor' ) );
			$this->repository->record(
				array(
					'generation_id'   => $prepared['generation_id'],
					'source_url'      => $prepared['source_url'],
					'draft_post_id'   => 0,
					'generation_status' => 'rolled_back',
					'sections'        => $prepared['report']['sections']['generated'],
					'components'      => $prepared['report']['components']['generated'],
					'elements'        => 0,
					'warnings'        => $prepared['warnings'],
					'errors'          => $created['errors'],
				)
			);

			return $this->error(
				'draft_creation_failed',
				__( 'ReplicaForge could not create the Elementor draft. Any incomplete draft was removed and nothing was published.', 'replicaforge' ),
				500,
				array(
					'warnings' => array_values( array_unique( array_merge( $prepared['warnings'], $created['warnings'] ) ) ),
					'report'   => $prepared['report'],
				)
			);
		}

		$warnings = array_values( array_unique( array_merge( $prepared['warnings'], $created['warnings'] ) ) );
		$report   = $prepared['report'];
		$report['elements']['generated'] = (int) $created['element_count'];

		$this->repository->record(
			array(
				'generation_id'     => $prepared['generation_id'],
				'source_url'        => $prepared['source_url'],
				'source_hash'       => $prepared['source_hash'],
				'reconstruction_hash' => $prepared['reconstruction_hash'],
				'draft_post_id'     => (int) $created['draft_id'],
				'specification_id'  => $prepared['specification_id'],
				'elementor_version' => $this->compatibility->version(),
				'generation_status' => 'completed',
				'completed_at'      => gmdate( 'c' ),
				'sections'          => $report['sections']['generated'],
				'components'        => $report['components']['generated'],
				'elements'          => (int) $created['element_count'],
				'warnings'          => $warnings,
				'errors'            => array(),
			)
		);

		Security::log_event(
			'generation_completed',
			array(
				'count'       => (int) $created['element_count'],
				'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			)
		);

		return array(
			'success' => true,
			'data'    => array(
				'mode'               => 'generate',
				'preview'            => false,
				'draft_id'           => (int) $created['draft_id'],
				'edit_url'           => (string) $created['edit_url'],
				'preview_url'        => (string) $created['preview_url'],
				'elementor_edit_url' => (string) $created['elementor_edit_url'],
				'post_status'        => 'draft',
				'published'          => false,
				'generated_sections' => (int) $report['sections']['generated'],
				'generated_components' => (int) $report['components']['generated'],
				'generated_elements' => (int) $created['element_count'],
				'generated_assets'   => (int) $report['assets']['imported'],
				'generation_id'      => $prepared['generation_id'],
				'specification_id'   => $prepared['specification_id'],
				'duration_ms'        => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'report'             => $report,
				'elementor'          => $this->public_compatibility(),
				'warnings'           => $warnings,
				'errors'             => array(),
			),
		);
	}

	/**
	 * Run every step that precedes draft creation.
	 *
	 * @param mixed                $representation Phase 2 representation.
	 * @param mixed                $specification  Optional Phase 3 specification.
	 * @param array<string, mixed> $options        Generation options.
	 * @return array<string, mixed>
	 */
	private function prepare( $representation, $specification, array $options ) {
		$warnings = array();

		if ( ! is_array( $representation ) ) {
			return $this->error( 'invalid_design_representation', __( 'A valid Phase 2 Design Representation 2.0 is required before an Elementor draft can be generated.', 'replicaforge' ), 400 );
		}
		$design = new Design_Representation( $representation );
		if ( ! $design->is_valid() ) {
			return $this->error( 'invalid_design_representation', __( 'The Phase 2 Design Representation could not be validated, so generation was refused.', 'replicaforge' ), 400 );
		}
		$representation = $design->to_array();

		if ( ! $this->compatibility->is_available() ) {
			Security::log_event( 'elementor_not_available', array( 'code' => 'compatibility', 'reason' => 'elementor' ) );
			return $this->error( 'elementor_not_available', $this->compatibility->message(), 409, array( 'elementor' => $this->public_compatibility() ) );
		}

		$resolved = $this->resolve_specification( $representation, $specification, $options );
		if ( empty( $resolved['success'] ) ) {
			return $this->error(
				isset( $resolved['error']['code'] ) ? $resolved['error']['code'] : 'invalid_reconstruction_specification',
				isset( $resolved['error']['message'] ) ? $resolved['error']['message'] : __( 'The reconstruction specification could not be used for generation.', 'replicaforge' ),
				isset( $resolved['error']['status'] ) ? (int) $resolved['error']['status'] : 400
			);
		}

		$specification = $resolved['specification'];
		$warnings      = array_merge( $warnings, $resolved['warnings'] );

		$plan_check = $this->spec_validator->validate( $specification );
		if ( empty( $plan_check['valid'] ) ) {
			Security::log_event( 'generation_validation_failed', array( 'code' => 'specification_invalid', 'reason' => 'input' ) );
			return $this->error(
				'invalid_reconstruction_specification',
				! empty( $plan_check['message'] )
					? $plan_check['message']
					: __( 'The reconstruction specification is incomplete or unsafe, so no draft was created. Re-run the design analysis and try again.', 'replicaforge' ),
				400
			);
		}
		$plan      = $plan_check['plan'];
		$warnings  = array_merge( $warnings, $plan_check['warnings'] );
		$warnings  = array_values( array_unique( $warnings ) );

		$generation_id = $this->generation_id( $resolved['source_url'], $specification );

		$assets = $this->assets->resolve( $plan['assets'], array(
			'import_assets' => ! empty( $options['import_assets'] ),
			'generation_id' => $generation_id,
		) );
		$warnings = array_values( array_unique( array_merge( $warnings, $assets['warnings'] ) ) );

		$responsive = $this->responsive->build( $plan );
		$warnings   = array_values( array_unique( array_merge( $warnings, $responsive['warnings'] ) ) );

		$mapped = $this->mapper->map( $plan, $assets['states'], $responsive );
		$warnings = array_values( array_unique( array_merge( $warnings, $mapped['warnings'] ) ) );

		$document = $this->builder->build( $mapped['tree'], array( 'generation_id' => $generation_id ) );
		$warnings = array_values( array_unique( array_merge( $warnings, $document['warnings'] ) ) );

		$check = $this->validator->validate_document( $document['elements'] );
		if ( empty( $check['valid'] ) ) {
			Security::log_event( 'generation_validation_failed', array( 'code' => 'document_invalid', 'reason' => 'document' ) );
			return $this->error(
				'elementor_document_invalid',
				__( 'The generated Elementor document did not pass validation, so no draft was created. This is usually caused by an incompatible Elementor build.', 'replicaforge' ),
				500,
				array( 'warnings' => $warnings )
			);
		}

		$provenance = array();
		foreach ( $assets['states'] as $component_id => $state ) {
			$provenance[ $component_id ] = array(
				'source_url'    => isset( $state['source_url'] ) ? (string) $state['source_url'] : '',
				'source_type'   => isset( $state['source_type'] ) ? (string) $state['source_type'] : 'remote',
				'import_status' => isset( $state['import_status'] ) ? (string) $state['import_status'] : 'unknown',
				'provenance'    => isset( $state['provenance'] ) ? (string) $state['provenance'] : 'source_website',
				'attachment_id' => isset( $state['attachment_id'] ) ? absint( $state['attachment_id'] ) : 0,
				'reason'        => isset( $state['reason'] ) ? (string) $state['reason'] : '',
			);
		}

		$report = $this->report(
			$plan,
			$mapped['stats'],
			$assets['summary'],
			$responsive,
			$check,
			$resolved,
			$generation_id
		);

		$warnings = array_slice( array_values( array_unique( $warnings ) ), 0, Elementor_Limits::MAX_REPORT_WARNINGS );

		return array(
			'success'           => true,
			'plan'              => $plan,
			'elements'          => $document['elements'],
			'id_map'            => $document['id_map'],
			'warnings'          => $warnings,
			'report'            => $report,
			'generation_id'     => $generation_id,
			'specification_id'  => $resolved['specification_id'],
			'source_url'        => $resolved['source_url'],
			'source_title'      => $resolved['source_title'],
			'source_host'       => $resolved['source_host'],
			'source_hash'       => $resolved['source_hash'],
			'reconstruction_hash' => $resolved['reconstruction_hash'],
			'ai_used'           => $resolved['ai_used'],
			'asset_provenance'  => $provenance,
		);
	}

	/**
	 * Resolve and verify the reconstruction specification.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @param mixed                $specification  Supplied specification.
	 * @param array<string, mixed> $options        Generation options.
	 * @return array<string, mixed>
	 */
	private function resolve_specification( array $representation, $specification, array $options ) {
		$warnings   = array();
		$source_url = isset( $options['source_url'] ) && is_string( $options['source_url'] ) ? $this->safe_url( $options['source_url'] ) : '';
		$page_url   = isset( $representation['page']['final_url'] ) ? $representation['page']['final_url'] : ( isset( $representation['page']['url'] ) ? $representation['page']['url'] : '' );
		if ( '' === $source_url ) {
			$source_url = $this->safe_url( $page_url );
		}
		$source_title = isset( $representation['page']['title'] ) && is_string( $representation['page']['title'] ) ? $representation['page']['title'] : '';
		$parts        = Security::parse_url( $source_url );
		$source_host  = is_array( $parts ) && ! empty( $parts['host'] ) ? (string) $parts['host'] : '';

		$specification_id = isset( $options['specification_id'] ) && is_string( $options['specification_id'] ) ? $options['specification_id'] : '';
		if ( '' !== $specification_id ) {
			$stored = $this->repository->get_specification( $specification_id );
			if ( is_array( $stored ) ) {
				$specification = $stored;
			}
		}

		if ( ! is_array( $specification ) ) {
			$specification = $this->planner->build( $representation, array( 'ai_used' => false, 'provider' => 'none', 'model' => null ) );
			$warnings[]    = __( 'No reconstruction specification was supplied, so the deterministic reconstruction plan was generated. Phase 4 never calls an AI provider.', 'replicaforge' );
		}

		$source_check = $this->spec_output_validator->validate( $specification, $representation, false );
		if ( empty( $source_check['valid'] ) ) {
			Security::log_event( 'generation_validation_failed', array( 'code' => 'specification_inconsistent', 'reason' => 'input' ) );
			return Security::error(
				'invalid_reconstruction_specification',
				__( 'The reconstruction specification did not match the Phase 2 analysis, so no Elementor document was created. Re-run the design analysis and try again.', 'replicaforge' ),
				400
			);
		}
		$warnings = array_merge( $warnings, $source_check['warnings'] );

		$specification_id = $this->repository->save_specification( $specification, array( 'source_url' => $source_url ) );

		$encoded = wp_json_encode( $specification );

		return array(
			'success'            => true,
			'specification'      => $specification,
			'specification_id'   => $specification_id,
			'warnings'           => array_values( array_unique( $warnings ) ),
			'source_url'         => $source_url,
			'source_title'       => Security::clean_text( $source_title, 120 ),
			'source_host'        => $source_host,
			'source_hash'        => hash( 'sha256', (string) wp_json_encode( $representation ) ),
			'reconstruction_hash' => is_string( $encoded ) ? hash( 'sha256', $encoded ) : '',
			'ai_used'            => ! empty( $specification['provenance']['ai_used'] ),
		);
	}

	/**
	 * Build the generation report.
	 *
	 * @param array<string, mixed> $plan       Normalized plan.
	 * @param array<string, mixed> $stats      Mapper statistics.
	 * @param array<string, mixed> $assets     Asset summary.
	 * @param array<string, mixed> $responsive Responsive plan.
	 * @param array<string, mixed> $check      Document validation result.
	 * @param array<string, mixed> $resolved   Resolved specification context.
	 * @param string               $generation_id Generation identifier.
	 * @return array<string, mixed>
	 */
	private function report( array $plan, array $stats, array $assets, array $responsive, array $check, array $resolved, $generation_id ) {
		$sections_detected = count( $plan['sections'] );
		$components_detected = count( $plan['components'] );
		$asset_roles        = array();
		foreach ( $plan['assets'] as $asset ) {
			$role = isset( $asset['role'] ) ? (string) $asset['role'] : 'content_image';
			$asset_roles[ $role ] = isset( $asset_roles[ $role ] ) ? $asset_roles[ $role ] + 1 : 1;
		}

		return array(
			'generation_id' => $generation_id,
			'source'        => array(
				'url'    => $resolved['source_url'],
				'host'   => $resolved['source_host'],
				'title'  => $resolved['source_title'],
				'type'   => isset( $plan['page_strategy']['layout_type'] ) ? $plan['page_strategy']['layout_type'] : 'unknown',
				'ai_used' => ! empty( $resolved['ai_used'] ),
			),
			'specification' => array(
				'schema_version' => Elementor_Limits::SPEC_SCHEMA_VERSION,
				'prompt_version' => isset( $resolved['specification']['prompt_version'] ) ? (string) $resolved['specification']['prompt_version'] : '',
				'specification_id' => $resolved['specification_id'],
			),
			'sections'      => array(
				'detected'  => $sections_detected,
				'generated' => (int) $stats['sections'],
				'omitted'   => max( 0, $sections_detected - (int) $stats['sections'] ),
			),
			'components'    => array(
				'detected'        => $components_detected,
				'generated'       => (int) $stats['components'],
				'widgets'         => (int) $stats['widgets'],
				'containers'      => (int) $stats['containers'],
				'fallbacks'       => (int) $stats['fallbacks'],
				'skipped'         => (int) $stats['skipped'],
				'low_confidence'  => (int) $stats['low_confidence'],
			),
			'elements'      => array(
				'generated'  => (int) $check['count'],
				'max_depth'  => (int) $check['max_depth'],
			),
			'assets'        => array(
				'detected'   => (int) $assets['detected'],
				'imported'   => (int) $assets['imported'],
				'referenced' => (int) $assets['referenced'],
				'blocked'    => (int) $assets['blocked'],
				'failed'     => (int) $assets['failed'],
				'unavailable' => (int) $assets['unavailable'],
				'bytes'      => (int) $assets['bytes'],
				'roles'      => $asset_roles,
			),
			'responsive'    => $responsive['report'],
			'confidence'    => $plan['confidence'],
			'limitations'   => $this->limitations(),
			'elementor'     => $this->public_compatibility(),
			'visual_validation' => array(
				'supported' => false,
				'reason'    => 'Phase 4 does not capture or compare screenshots. A visual accuracy claim has not been verified.',
			),
		);
	}

	/**
	 * Return the documented, always-applicable Phase 4 limitations.
	 *
	 * @return array<int, string>
	 */
	private function limitations() {
		return array(
			__( 'Pixel-level accuracy is not claimed. ReplicaForge maps detected structure and design values, it does not measure rendered output.', 'replicaforge' ),
			__( 'Source JavaScript behaviour, carousels, filters, and animations are not reproduced.', 'replicaforge' ),
			__( 'Source form submissions, authentication, and private endpoints are never recreated.', 'replicaforge' ),
			__( 'Fonts are only applied when the source font family was detected. Unknown families are left unset rather than invented.', 'replicaforge' ),
			__( 'Global site colours are not modified. Colours are written per element so the active Elementor kit is untouched.', 'replicaforge' ),
		);
	}

	/**
	 * Return a safe, public compatibility summary.
	 *
	 * @return array<string, mixed>
	 */
	private function public_compatibility() {
		$status = $this->compatibility->status();
		return array(
			'available'      => ! empty( $status['available'] ),
			'version'        => isset( $status['version'] ) ? (string) $status['version'] : '',
			'containers'     => ! empty( $status['containers'] ),
			'message'        => $this->compatibility->message(),
			'phase'          => Elementor_Limits::PHASE,
		);
	}

	/**
	 * Build a deterministic generation identifier.
	 *
	 * @param string               $source_url    Source URL.
	 * @param array<string, mixed> $specification Specification.
	 * @return string
	 */
	private function generation_id( $source_url, array $specification ) {
		$encoded = wp_json_encode( $specification );
		$seed    = (string) $source_url . '|' . ( is_string( $encoded ) ? $encoded : '' );
		return 'rf_' . gmdate( 'Ymd\THis' ) . '_' . substr( hash( 'sha256', $seed ), 0, 12 );
	}

	/**
	 * Normalize a public source URL.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function safe_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return '';
		}
		if ( ! Security::is_safe_public_reference( $url ) ) {
			return '';
		}
		$normalized = Security::normalize_http_url( $url );
		return is_string( $normalized ) ? $normalized : '';
	}

	/**
	 * Build a safe error envelope.
	 *
	 * @param string               $code    Error code.
	 * @param string               $message User-facing message.
	 * @param int                  $status  HTTP-like status.
	 * @param array<string, mixed> $extra   Extra data.
	 * @return array<string, mixed>
	 */
	private function error( $code, $message, $status, array $extra = array() ) {
		$error = array(
			'code'    => sanitize_key( $code ),
			'message' => (string) $message,
			'status'  => absint( $status ),
		);
		if ( isset( $extra['warnings'] ) && is_array( $extra['warnings'] ) ) {
			$error['warnings'] = array_values( array_unique( $extra['warnings'] ) );
		}
		if ( isset( $extra['elementor'] ) && is_array( $extra['elementor'] ) ) {
			$error['elementor'] = $extra['elementor'];
		}
		if ( isset( $extra['report'] ) && is_array( $extra['report'] ) ) {
			$error['report'] = $extra['report'];
		}
		return array(
			'success' => false,
			'error'   => $error,
		);
	}
}
