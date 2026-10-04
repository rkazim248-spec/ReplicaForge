<?php
/**
 * Provider-neutral Phase 3 AI orchestration.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds context, invokes an optional provider, validates output, and falls back
 * to a deterministic reconstruction plan without pretending AI ran.
 */
final class Ai_Manager {

	/**
	 * Server-side settings.
	 *
	 * @var Ai_Settings
	 */
	private $settings;

	/**
	 * Cache implementation.
	 *
	 * @var Ai_Cache_Contract
	 */
	private $cache;

	/**
	 * Context builder.
	 *
	 * @var Ai_Context_Builder
	 */
	private $context_builder;

	/**
	 * Deterministic planner.
	 *
	 * @var Ai_Reconstruction_Planner
	 */
	private $planner;

	/**
	 * Output validator.
	 *
	 * @var Ai_Output_Validator
	 */
	private $validator;

	/**
	 * Optional injected providers, keyed by ID.
	 *
	 * @var array<string, Ai_Provider_Contract>
	 */
	private $providers;

	/**
	 * Constructor.
	 *
	 * @param Ai_Settings|null          $settings Settings service.
	 * @param Ai_Cache_Contract|null    $cache    Cache service.
	 * @param array<int, mixed>          $providers Optional provider instances.
	 */
	public function __construct( $settings = null, $cache = null, array $providers = array() ) {
		$this->settings       = $settings instanceof Ai_Settings ? $settings : new Ai_Settings();
		$this->cache          = $cache instanceof Ai_Cache_Contract ? $cache : new Ai_Transient_Cache();
		$this->context_builder = new Ai_Context_Builder();
		$this->planner         = new Ai_Reconstruction_Planner();
		$this->validator       = new Ai_Output_Validator();
		$this->providers       = array();
		foreach ( $providers as $provider ) {
			if ( $provider instanceof Ai_Provider_Contract ) {
				$provider_id = sanitize_key( $provider->get_id() );
				if ( '' !== $provider_id ) {
					$this->providers[ $provider_id ] = $provider;
				}
			}
		}
	}

	/**
	 * Analyze a Phase 2 representation.
	 *
	 * @param mixed  $representation Phase 2 representation.
	 * @param string $source_url      Optional public source URL.
	 * @return array<string, mixed>
	 */
	public function analyze( $representation, $source_url = '' ) {
		if ( ! is_array( $representation ) ) {
			return $this->error( 'invalid_design_representation', 'A valid Design Representation 2.0 is required.', 400 );
		}
		$design = new Design_Representation( $representation );
		if ( ! $design->is_valid() ) {
			return $this->error( 'invalid_design_representation', 'The Design Representation could not be validated.', 400 );
		}
		$representation = $design->to_array();
		$raw_source_url = is_string( $source_url ) ? trim( $source_url ) : '';
		$source_url     = $this->safe_source_url( $raw_source_url );
		if ( '' !== $raw_source_url && null === $source_url ) {
			return $this->error( 'invalid_source_url', 'The source URL is not a safe public reference.', 400 );
		}
		if ( ! is_string( $source_url ) ) {
			$source_url = '';
		}
		$representation_url = isset( $representation['page']['final_url'] ) ? $representation['page']['final_url'] : ( isset( $representation['page']['url'] ) ? $representation['page']['url'] : '' );
		if ( '' !== $source_url && ! $this->same_public_host( $source_url, $representation_url ) ) {
			return $this->error( 'source_url_mismatch', 'The source URL does not match the analyzed page.', 400 );
		}

		$context = $this->context_builder->build( $representation );
		if ( ! is_array( $context ) || empty( $context ) ) {
			return $this->error( 'ai_context_unavailable', 'The bounded AI context could not be built.', 500 );
		}
		$context_size     = isset( $context['limits']['estimated_bytes'] ) ? (int) $context['limits']['estimated_bytes'] : 0;
		$require_complete = empty( $context['limits']['truncated'] );

		$settings       = $this->settings->get();
		$provider_id    = isset( $settings['provider'] ) ? sanitize_key( $settings['provider'] ) : 'none';
		$model          = isset( $settings['model'] ) ? trim( (string) $settings['model'] ) : '';
		$fallback       = $this->planner->build( $representation, array( 'ai_used' => false, 'provider' => 'none', 'model' => null ) );
		$fallback_check = $this->validator->validate( $fallback, $representation, false );
		if ( empty( $fallback_check['valid'] ) ) {
			return $this->error( 'deterministic_plan_invalid', 'The deterministic reconstruction plan could not be validated.', 500 );
		}
		$fallback['validation'] = array( 'valid' => true, 'errors' => array(), 'warnings' => $fallback_check['warnings'] );

		if ( $context_size > Ai_Limits::MAX_CONTEXT_BYTES ) {
			return $this->success( $fallback, false, true, 'none', false, 'The page is too large for a bounded AI request. The deterministic plan is available.' );
		}
		if ( ! $this->settings->is_configured() || 'none' === $provider_id || '' === $model ) {
			return $this->success( $fallback, false, true, 'none', false, 'AI is disabled or not configured. The deterministic plan is available.' );
		}
		$provider = $this->get_provider( $provider_id, $settings );
		if ( ! $provider instanceof Ai_Provider_Contract ) {
			return $this->success( $fallback, false, true, $provider_id, false, 'The selected AI provider is unavailable. The deterministic plan is available.' );
		}
		$cache_key = $this->cache_key( $source_url, $representation, $context, $provider_id, $model );
		$cached    = $this->cache->get( $cache_key );
		if ( is_array( $cached ) ) {
			$cached_check = $this->validator->validate( $cached, $representation, $require_complete );
			$cached_is_ai = isset( $cached['provenance']['ai_used'] ) && true === $cached['provenance']['ai_used'];
			if ( ! empty( $cached_check['valid'] ) && $cached_is_ai ) {
				$cached['validation'] = array( 'valid' => true, 'errors' => array(), 'warnings' => $cached_check['warnings'] );
				$cached['provenance'] = $this->provenance( true, $provider_id, $model, $cache_key, true );
				return $this->success( $cached, true, false, $provider_id, true, 'A cached AI reconstruction specification was used.' );
			}
			$this->cache->delete( $cache_key );
		}

		$system = Ai_Prompt_Builder::system_instructions();
		$user   = Ai_Prompt_Builder::user_prompt( $context );
		$last_errors = array();
		$last_error   = '';
		for ( $attempt = 1; $attempt <= Ai_Limits::MAX_PROVIDER_ATTEMPTS; $attempt++ ) {
			$request_user = $user;
			if ( $attempt > 1 && '' !== $last_error ) {
				$request_user = Ai_Prompt_Builder::repair_prompt( $context, $last_error, $last_errors );
			}
			try {
				$response = $provider->analyze(
					array(
						'system'           => $system,
						'user'             => $request_user,
						'attempt'          => $attempt,
						'schema_version'   => Ai_Limits::SCHEMA_VERSION,
						'prompt_version'   => Ai_Limits::PROMPT_VERSION,
						'max_output_bytes' => Ai_Limits::MAX_OUTPUT_BYTES,
					)
				);
			} catch ( \Throwable $exception ) {
				$response = array( 'success' => false, 'error' => array( 'code' => 'provider_exception', 'message' => 'The AI provider failed unexpectedly.' ) );
			}
			if ( empty( $response['success'] ) ) {
				$last_error = '';
				$last_errors = array( 'provider_request_failed' );
				$error_code = isset( $response['error']['code'] ) ? sanitize_key( $response['error']['code'] ) : 'provider_request_failed';
				Security::log_event( 'ai_provider_failed', array( 'code' => $error_code, 'reason' => 'provider' ) );
				if ( $attempt < Ai_Limits::MAX_PROVIDER_ATTEMPTS ) {
					continue;
				}
				return $this->fallback_result( $fallback, $representation, $provider_id, $model, $error_code );
			}
			$content = isset( $response['content'] ) && is_string( $response['content'] ) ? $response['content'] : '';
			$parsed  = $this->decode_json( $content );
			if ( ! is_array( $parsed ) ) {
				$last_error = Security::truncate_text( $content, 30000 );
				$last_errors = array( 'invalid_json_response' );
				if ( $attempt < Ai_Limits::MAX_PROVIDER_ATTEMPTS ) {
					continue;
				}
				return $this->fallback_result( $fallback, $representation, $provider_id, $model, 'invalid_json_response' );
			}
			$check = $this->validator->validate( $parsed, $representation, $require_complete );
			if ( empty( $check['valid'] ) ) {
				$last_error = Security::truncate_text( $content, 30000 );
				$last_errors = $check['errors'];
				continue;
			}
			if ( ! empty( $context['limits']['truncated'] ) && count( $parsed['warnings'] ) < 100 ) {
				$parsed['warnings'][] = 'The AI context was truncated; omitted records were not inferred.';
			}
			$parsed['warnings'] = array_values( array_unique( $parsed['warnings'] ) );
			$parsed['validation'] = array( 'valid' => true, 'errors' => array(), 'warnings' => $check['warnings'] );
			$parsed['provenance'] = $this->provenance( true, $provider_id, $model, $cache_key, false );
			$final_check = $this->validator->validate( $parsed, $representation, $require_complete );
			if ( empty( $final_check['valid'] ) ) {
				return $this->fallback_result( $fallback, $representation, $provider_id, $model, 'ai_output_post_validation_failed' );
			}
			$this->cache->set( $cache_key, $parsed );
			return $this->success( $parsed, true, false, $provider_id, false, 'AI reconstruction specification generated and validated.' );
		}
		return $this->fallback_result( $fallback, $representation, $provider_id, $model, 'ai_output_validation_failed' );
	}

	/**
	 * Test a configured provider without exposing credentials.
	 *
	 * @return array<string, mixed>
	 */
	public function test_connection() {
		$settings = $this->settings->get();
		if ( ! $this->settings->is_configured() ) {
			return $this->error( 'provider_not_configured', 'Configure and enable an AI provider first.', 400 );
		}
		$provider = $this->get_provider( isset( $settings['provider'] ) ? sanitize_key( $settings['provider'] ) : 'none', $settings );
		if ( ! $provider instanceof Ai_Provider_Contract ) {
			return $this->error( 'provider_unavailable', 'The selected AI provider is unavailable.' );
		}
		try {
			return $provider->test_connection();
		} catch ( \Throwable $exception ) {
			return $this->error( 'provider_exception', 'The AI provider failed unexpectedly.', 502 );
		}
	}

	/**
	 * Order already-measured corrections for Phase 6.
	 *
	 * The payload contains only values the deterministic comparison already
	 * measured. This method builds the prompt, calls the provider, and enforces
	 * the response shape. It never creates a correction, never changes what a
	 * correction sets, and never returns an Elementor value.
	 *
	 * @param array<string, mixed> $payload Bounded, measured payload.
	 * @return array<string, mixed>
	 */
	public function plan_corrections( array $payload ) {
		if ( ! $this->settings->is_configured() ) {
			return $this->error( 'provider_not_configured', __( 'No AI provider is configured.', 'replicaforge' ), 400 );
		}
		$settings     = $this->settings->get();
		$provider_id = isset( $settings['provider'] ) ? sanitize_key( $settings['provider'] ) : 'none';
		$model       = isset( $settings['model'] ) ? trim( (string) $settings['model'] ) : '';
		if ( 'none' === $provider_id || '' === $model ) {
			return $this->error( 'provider_not_configured', __( 'No AI provider is configured.', 'replicaforge' ), 400 );
		}
		$provider = $this->get_provider( $provider_id, $settings );
		if ( ! $provider instanceof Ai_Provider_Contract ) {
			return $this->error( 'provider_unavailable', __( 'The selected AI provider is unavailable.', 'replicaforge' ), 400 );
		}

		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > Validation_Limits::MAX_AI_PAYLOAD_BYTES ) {
			return $this->error( 'correction_payload_too_large', __( 'The correction payload is too large to plan.', 'replicaforge' ), 400 );
		}

		$system = implode(
			"\n",
			array(
				'You order corrections that a deterministic comparison already measured.',
				'Only reference correction_id values that appear in the supplied data.',
				'Never invent a difference, value, colour, dimension, element, or content.',
				'Never change what a correction sets. Only propose the order it is applied in.',
				'Never claim pixel-perfect or visual accuracy.',
				'Return one JSON object with an explanation string and a recommendations array.',
				'Each recommendation must include correction_id, order, depends_on, confidence, and reason.',
			)
		);

		$user = "<REPLICAFORGE_MEASURED_CORRECTIONS>\n" . $encoded . "\n</REPLICAFORGE_MEASURED_CORRECTIONS>";

		try {
			$response = $provider->analyze(
				array(
					'system' => $system,
					'user'   => $user,
				)
			);
		} catch ( \Throwable $exception ) {
			return $this->error( 'provider_exception', __( 'The AI provider failed unexpectedly.', 'replicaforge' ), 502 );
		}

		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			Security::log_event( 'correction_planning_failed', array( 'code' => 'provider', 'reason' => 'correction' ) );
			return $this->error( 'provider_request_failed', __( 'The AI provider did not return a usable correction plan.', 'replicaforge' ), 502 );
		}

		$decoded = $this->decode_json( isset( $response['content'] ) ? (string) $response['content'] : '' );
		if ( ! is_array( $decoded ) ) {
			return $this->error( 'invalid_correction_response', __( 'The AI correction plan was not in the expected format.', 'replicaforge' ), 502 );
		}

		$recommendations = array();
		if ( isset( $decoded['recommendations'] ) && is_array( $decoded['recommendations'] ) ) {
			foreach ( array_slice( $decoded['recommendations'], 0, Correction_Limits::MAX_CORRECTIONS ) as $recommendation ) {
				if ( ! is_array( $recommendation ) || empty( $recommendation['correction_id'] ) || ! is_string( $recommendation['correction_id'] ) ) {
					continue;
				}
				$recommendations[] = array(
					'correction_id' => sanitize_text_field( $recommendation['correction_id'] ),
					'order'        => isset( $recommendation['order'] ) && is_numeric( $recommendation['order'] ) ? (int) $recommendation['order'] : 0,
					'depends_on'   => isset( $recommendation['depends_on'] ) && is_array( $recommendation['depends_on'] ) ? $recommendation['depends_on'] : array(),
					'confidence'   => isset( $recommendation['confidence'] ) && is_numeric( $recommendation['confidence'] ) ? (float) $recommendation['confidence'] : 0.0,
					'reason'       => isset( $recommendation['reason'] ) && is_string( $recommendation['reason'] ) ? sanitize_text_field( $recommendation['reason'] ) : '',
					'regenerate_component' => ! empty( $recommendation['regenerate_component'] ),
					'section_level'        => ! empty( $recommendation['section_level'] ),
				);
			}
		}

		return array(
			'success' => true,
			'data'    => array(
				'explanation'    => isset( $decoded['explanation'] ) && is_string( $decoded['explanation'] ) ? sanitize_text_field( $decoded['explanation'] ) : '',
				'recommendations' => $recommendations,
			),
		);
	}

	/**
	 * Explain already-measured validation differences.
	 *
	 * Phase 5 supplies differences its deterministic comparators already found.
	 * This method builds the prompt, calls the configured provider, and checks
	 * the response shape. It never derives a difference and never returns a
	 * document change.
	 *
	 * @param array<string, mixed> $payload Bounded, measured payload.
	 * @return array<string, mixed>
	 */
	public function explain_validation( array $payload ) {
		$settings = $this->settings->get();
		if ( ! $this->settings->is_configured() ) {
			return $this->error( 'provider_not_configured', __( 'No AI provider is configured.', 'replicaforge' ), 400 );
		}
		$provider_id = isset( $settings['provider'] ) ? sanitize_key( $settings['provider'] ) : 'none';
		$model       = isset( $settings['model'] ) ? trim( (string) $settings['model'] ) : '';
		if ( 'none' === $provider_id || '' === $model ) {
			return $this->error( 'provider_not_configured', __( 'No AI provider is configured.', 'replicaforge' ), 400 );
		}
		$provider = $this->get_provider( $provider_id, $settings );
		if ( ! $provider instanceof Ai_Provider_Contract ) {
			return $this->error( 'provider_unavailable', __( 'The selected AI provider is unavailable.', 'replicaforge' ), 400 );
		}

		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > Validation_Limits::MAX_AI_PAYLOAD_BYTES ) {
			return $this->error( 'validation_payload_too_large', __( 'The validation payload is too large to explain.', 'replicaforge' ), 400 );
		}

		$system = implode(
			"\n",
			array(
				'You explain differences a deterministic comparison already measured.',
				'Only use differences present in the supplied data.',
				'Never invent a difference, colour, font, size, or behaviour that is absent from the data.',
				'Never claim pixel-perfect or visual accuracy.',
				'Never propose changing text content.',
				'Return one JSON object with an explanation string and a recommendations array.',
				'Each recommendation must include difference_id, action, from, and to.',
			)
		);

		$user = "<REPLICAFORGE_VALIDATION_DATA>\n" . $encoded . "\n</REPLICAFORGE_VALIDATION_DATA>";

		try {
			// The provider contract only reads `system` and `user`, so the
			// validation request deliberately sends nothing else.
			$response = $provider->analyze(
				array(
					'system' => $system,
					'user'   => $user,
				)
			);
		} catch ( \Throwable $exception ) {
			return $this->error( 'provider_exception', __( 'The AI provider failed unexpectedly.', 'replicaforge' ), 502 );
		}

		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			Security::log_event( 'validation_explanation_failed', array( 'code' => 'provider', 'reason' => 'validation' ) );
			return $this->error( 'provider_request_failed', __( 'The AI provider did not return a usable explanation.', 'replicaforge' ), 502 );
		}

		$decoded = $this->decode_json( isset( $response['content'] ) ? (string) $response['content'] : '' );
		if ( ! is_array( $decoded ) || ! isset( $decoded['explanation'] ) || ! is_string( $decoded['explanation'] ) ) {
			return $this->error( 'invalid_explanation_response', __( 'The AI explanation was not in the expected format.', 'replicaforge' ), 502 );
		}

		$recommendations = array();
		if ( isset( $decoded['recommendations'] ) && is_array( $decoded['recommendations'] ) ) {
			foreach ( array_slice( $decoded['recommendations'], 0, Validation_Limits::MAX_CORRECTIONS ) as $recommendation ) {
				if ( ! is_array( $recommendation ) || ! isset( $recommendation['difference_id'] ) || ! is_string( $recommendation['difference_id'] ) ) {
					continue;
				}
				$recommendations[] = array(
					'difference_id' => sanitize_text_field( $recommendation['difference_id'] ),
					'action'        => isset( $recommendation['action'] ) && is_string( $recommendation['action'] ) ? sanitize_text_field( $recommendation['action'] ) : '',
					'from'          => isset( $recommendation['from'] ) && is_scalar( $recommendation['from'] ) ? $recommendation['from'] : null,
					'to'            => isset( $recommendation['to'] ) && is_scalar( $recommendation['to'] ) ? $recommendation['to'] : null,
				);
			}
		}

		return array(
			'success' => true,
			'data'    => array(
				'explanation'     => sanitize_text_field( $decoded['explanation'] ),
				'recommendations' => $recommendations,
			),
		);
	}

	/**
	 * Return public settings for an admin screen.
	 *
	 * @return array<string, mixed>
	 */
	public function get_public_settings() {
		return $this->settings->get_public_config();
	}

	/**
	 * Return an injected or built provider.
	 *
	 * @param string               $provider_id Provider ID.
	 * @param array<string, mixed> $settings    Internal settings.
	 * @return Ai_Provider_Contract|null
	 */
	private function get_provider( $provider_id, array $settings ) {
		if ( isset( $this->providers[ $provider_id ] ) ) {
			return $this->providers[ $provider_id ];
		}
		if ( 'openai' === $provider_id ) {
			return new Ai_Provider_OpenAI( $settings );
		}
		if ( 'gemini' === $provider_id ) {
			return new Ai_Provider_Gemini( $settings );
		}
		return null;
	}

	/**
	 * Build a deterministic cache key.
	 *
	 * @param string               $source_url Source URL.
	 * @param array<string, mixed> $representation Representation.
	 * @param array<string, mixed> $context Context.
	 * @param string               $provider_id Provider.
	 * @param string               $model Model.
	 * @return string
	 */
	private function cache_key( $source_url, array $representation, array $context, $provider_id, $model ) {
		$stable_representation = $representation;
		unset( $stable_representation['analysis']['analyzed_at'], $stable_representation['analysis']['duration_ms'] );
		$payload = array( 'source_url' => $source_url, 'representation' => $stable_representation, 'context' => $context, 'schema' => Ai_Limits::SCHEMA_VERSION, 'prompt' => Ai_Limits::PROMPT_VERSION, 'provider' => $provider_id, 'model' => $model );
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return hash( 'sha256', is_string( $encoded ) ? $encoded : serialize( $payload ) );
	}

	/**
	 * Return a safe provenance object.
	 *
	 * @param bool   $ai_used Whether AI was used.
	 * @param string $provider Provider ID.
	 * @param string $model Model.
	 * @param string $cache_key Cache key.
	 * @param bool   $cached Whether result came from cache.
	 * @return array<string, mixed>
	 */
	private function provenance( $ai_used, $provider_id, $model, $cache_key, $cached ) {
		return array( 'ai_used' => (bool) $ai_used, 'provider' => sanitize_key( $provider_id ), 'model' => '' !== $model ? Security::clean_text( $model, 120 ) : null, 'prompt_version' => Ai_Limits::PROMPT_VERSION, 'source_schema_version' => '2.0', 'cache_key' => $cache_key ? hash( 'sha256', $cache_key ) : null, 'cache_hit' => (bool) $cached, 'generated_at' => gmdate( 'c' ) );
	}

	/**
	 * Build a successful manager envelope.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @param bool                 $ai_used Whether AI was used.
	 * @param bool                 $fallback Whether this is fallback.
	 * @param string               $provider Provider.
	 * @param bool                 $cached Whether cached.
	 * @param string               $message User-facing status.
	 * @return array<string, mixed>
	 */
	private function success( array $specification, $ai_used, $fallback, $provider, $cached, $message ) {
		return array( 'success' => true, 'ai_used' => (bool) $ai_used, 'fallback' => (bool) $fallback, 'cached' => (bool) $cached, 'provider' => sanitize_key( $provider ), 'message' => (string) $message, 'data' => $specification );
	}

	/**
	 * Return fallback with a safe provider failure warning.
	 *
	 * @param array<string, mixed> $fallback Fallback specification.
	 * @param array<string, mixed> $representation Representation.
	 * @param string               $provider Provider.
	 * @param string               $model Model.
	 * @param string               $reason Safe reason code.
	 * @return array<string, mixed>
	 */
	private function fallback_result( array $fallback, array $representation, $provider, $model, $reason ) {
		$fallback['warnings'][] = 'AI analysis was unavailable or failed validation; a deterministic reconstruction plan is shown instead.';
		$fallback['provenance'] = $this->provenance( false, $provider, $model, '', false );
		$fallback['provenance']['fallback_reason'] = sanitize_key( $reason );
		$check = $this->validator->validate( $fallback, $representation, false );
		$fallback['validation'] = array( 'valid' => ! empty( $check['valid'] ), 'errors' => $check['errors'], 'warnings' => $check['warnings'] );
		if ( empty( $check['valid'] ) ) {
			return $this->error( 'deterministic_plan_invalid', 'The deterministic reconstruction plan could not be validated.', 500 );
		}
		return $this->success( $fallback, false, true, $provider, false, 'AI analysis unavailable. The deterministic design analysis is still available.' );
	}

	/**
	 * Decode strict JSON or one bounded JSON object envelope.
	 *
	 * @param string $content Provider content.
	 * @return array<string, mixed>|null
	 */
	private function decode_json( $content ) {
		$content = trim( (string) $content );
		if ( '' === $content || strlen( $content ) > Ai_Limits::MAX_OUTPUT_BYTES + 4096 ) {
			return null;
		}
		$decoded = json_decode( $content, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		$start = strpos( $content, '{' );
		$end   = strrpos( $content, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		$candidate = substr( $content, $start, $end - $start + 1 );
		$decoded = json_decode( $candidate, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Compare the host of two public references without fetching either URL.
	 *
	 * @param string $left First URL.
	 * @param string $right Second URL.
	 * @return bool
	 */
	private function same_public_host( $left, $right ) {
		$left_parts  = Security::parse_url( $left );
		$right_parts = Security::parse_url( $right );
		if ( ! is_array( $left_parts ) || ! is_array( $right_parts ) || empty( $left_parts['host'] ) || empty( $right_parts['host'] ) ) {
			return false;
		}
		return strtolower( (string) $left_parts['host'] ) === strtolower( (string) $right_parts['host'] );
	}

	/**
	 * Normalize a source URL reference.
	 *
	 * @param mixed $url URL.
	 * @return string|null
	 */
	private function safe_source_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return '';
		}
		$normalized = Security::normalize_http_url( trim( $url ) );
		if ( ! is_string( $normalized ) || ! Security::is_safe_public_reference( $normalized ) ) {
			return null;
		}
		$parts = Security::parse_url( $normalized );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$base = preg_replace( '/#.*$/', '', $normalized );
		if ( ! is_string( $base ) ) {
			return null;
		}
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		if ( '' !== $query ) {
			$kept = array();
			foreach ( explode( '&', $query ) as $segment ) {
				$key_parts = explode( '=', $segment, 2 );
				$key       = strtolower( rawurldecode( $key_parts[0] ) );
				if ( 1 === preg_match( '/^(?:token|access_token|id_token|jwt|session(?:id)?|sid|api[_-]?key|key|secret|password|passwd|auth|authorization|signature|sig|code)$/', $key ) ) {
					continue;
				}
				$kept[] = $segment;
			}
			$base .= empty( $kept ) ? '' : '?' . implode( '&', $kept );
		}
		return Security::is_safe_public_reference( $base ) ? $base : null;
	}

	/**
	 * Create a safe manager error.
	 *
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param int    $status Status.
	 * @return array<string, mixed>
	 */
	private function error( $code, $message, $status = 500 ) {
		return array( 'success' => false, 'error' => array( 'code' => sanitize_key( $code ), 'message' => (string) $message, 'status' => absint( $status ) ) );
	}
}
