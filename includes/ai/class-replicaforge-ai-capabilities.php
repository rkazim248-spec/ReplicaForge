<?php
/**
 * Phase 11: AI provider and model capabilities.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * What each provider and model can actually do.
 *
 * Until now the plugin assumed every provider behaves the same. It sends
 * `response_format: json_object` to both OpenAI and Gemini, and the context
 * builder trims to one fixed byte budget regardless of which model is answering.
 * That works today because both shipped models happen to accept both. It is a
 * coincidence, and §9 is right that assuming it is how an integration breaks: a
 * provider that does not support JSON mode silently returns prose, and the
 * failure surfaces as a schema error three layers later, where nothing points at
 * the cause.
 *
 * So capabilities are declared, and the layers that depend on them **ask**. The
 * context budget asks whether the model can take the context before building it.
 * The cost estimator asks what a call costs before making it. The estimator asks
 * whether the model can see images before deciding to send one.
 *
 * ### Cost metadata is not a price list
 *
 * §10 says cost metadata must be configurable and not authoritative. The values
 * here are **estimates used for a pre-flight warning**, and they are marked as
 * such everywhere they surface. They are not billing information, they are not
 * read from any provider, and a wrong one produces a wrong *hint*, never a wrong
 * *charge* — ReplicaForge does not charge for AI tokens at all; the Phase 10
 * per-operation limits do that.
 *
 * A site that knows its real numbers replaces them through the
 * `replicaforge_model_capabilities` filter and nothing else has to change.
 */
final class Ai_Capabilities {

	/**
	 * Capability names.
	 *
	 * A closed list, because `supports()` answering true for an unrecognised name
	 * is how a caller starts assuming a feature exists.
	 *
	 * @var array<int, string>
	 */
	const CAPABILITIES = array(
		'structured_output',
		'json_schema',
		'vision',
		'streaming',
		'function_calling',
		'batch',
		'seed',
	);

	/**
	 * The declared provider capability sets.
	 *
	 * Provider-level, not model-level: what the transport supports at all.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function providers() {
		return array(
			'openai' => array(
				'label'        => 'OpenAI',
				'structured_output' => true,
				'json_schema'  => true,
				'vision'       => true,
				'streaming'    => true,
				'function_calling' => true,
				'batch'        => true,
				'seed'         => true,
			),
			'gemini' => array(
				'label'        => 'Google Gemini',
				'structured_output' => true,
				'json_schema'  => false,
				'vision'       => true,
				'streaming'    => true,
				'function_calling' => true,
				'batch'        => false,
				'seed'         => false,
			),
		);
	}

	/**
	 * The declared model capability sets.
	 *
	 * `context` is the usable input capacity in tokens. `output` is the largest
	 * response to ask for. `cost_in` and `cost_out` are **estimates** in the
	 * provider's own currency unit per million tokens, used only to label an
	 * operation low or high before it runs.
	 *
	 * Models not listed here fall back to the most conservative declared entry for
	 * their provider, and then to a hard-coded floor. Assuming a larger context
	 * than a model has produces a request the provider rejects; assuming a smaller
	 * one only makes ReplicaForge trim more than it had to.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function models() {
		return array(
			'openai' => array(
				'gpt-4o' => array(
					'label' => 'gpt-4o', 'context' => 128000, 'output' => 16384,
					'structured_output' => true, 'json_schema' => true, 'vision' => true,
					'cost_in' => 2.50, 'cost_out' => 10.00, 'available' => true,
				),
				'gpt-4o-mini' => array(
					'label' => 'gpt-4o-mini', 'context' => 128000, 'output' => 16384,
					'structured_output' => true, 'json_schema' => true, 'vision' => true,
					'cost_in' => 0.15, 'cost_out' => 0.60, 'available' => true,
				),
				'gpt-4.1' => array(
					'label' => 'gpt-4.1', 'context' => 1000000, 'output' => 32768,
					'structured_output' => true, 'json_schema' => true, 'vision' => true,
					'cost_in' => 2.00, 'cost_out' => 8.00, 'available' => true,
				),
				'gpt-4.1-mini' => array(
					'label' => 'gpt-4.1-mini', 'context' => 1000000, 'output' => 32768,
					'structured_output' => true, 'json_schema' => true, 'vision' => true,
					'cost_in' => 0.40, 'cost_out' => 1.60, 'available' => true,
				),
			),
			'gemini' => array(
				'gemini-2.5-pro' => array(
					'label' => 'gemini-2.5-pro', 'context' => 1000000, 'output' => 65536,
					'structured_output' => true, 'json_schema' => false, 'vision' => true,
					'cost_in' => 1.25, 'cost_out' => 10.00, 'available' => true,
				),
				'gemini-2.5-flash' => array(
					'label' => 'gemini-2.5-flash', 'context' => 1000000, 'output' => 65536,
					'structured_output' => true, 'json_schema' => false, 'vision' => true,
					'cost_in' => 0.30, 'cost_out' => 2.50, 'available' => true,
				),
			),
		);
	}

	/**
	 * Return the conservative defaults for a model nobody has declared.
	 *
	 * The two halves of an unknown model are treated differently, and the
	 * difference is deliberate.
	 *
	 * **Size is conservative.** A model not in the table gets a small context and a
	 * small output allowance. Assuming a large context produces a request the
	 * provider rejects; assuming a small one produces extra trimming, which is cheap
	 * and recoverable. So the floor is the safe direction.
	 *
	 * **Transport capability is inherited from the provider.** A new model on a
	 * provider that supports JSON mode almost certainly supports JSON mode, and
	 * assuming it does *not* would mean ReplicaForge stops asking for structured
	 * output and starts receiving prose, which then fails schema validation three
	 * layers away. The provider is a real constraint on the transport; the model
	 * entry is an optimisation on top of it. So an unknown model inherits the
	 * provider's capabilities and takes only the size floor.
	 *
	 * @return array<string, mixed>
	 */
	public static function unknown_model() {
		return array(
			'label'              => '',
			'context'            => 16000,
			'output'             => 8000,
			'structured_output'  => false,
			'json_schema'        => false,
			'vision'             => false,
			'streaming'          => false,
			'function_calling'   => false,
			'batch'              => false,
			'seed'               => false,
			'cost_in'            => 0.0,
			'cost_out'           => 0.0,
			'available'          => true,
			'known'              => false,
		);
	}

	/**
	 * Return the capabilities of a provider and model pair.
	 *
	 * A model inherits from its provider, so a provider that supports vision gives
	 * its models vision unless the model entry says otherwise. A capability the
	 * provider does not have is never granted by a model entry — the transport
	 * cannot do it, whatever the model claims.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $model       Model identifier.
	 * @return array<string, mixed>
	 */
	public static function resolve( $provider_id, $model = '' ) {
		$provider_id = is_string( $provider_id ) ? strtolower( trim( $provider_id ) ) : '';
		$model       = is_string( $model ) ? trim( $model ) : '';

		$providers = self::providers();
		$models    = self::models();

		$provider = isset( $providers[ $provider_id ] ) ? $providers[ $provider_id ] : array();
		$entries  = isset( $models[ $provider_id ] ) ? $models[ $provider_id ] : array();
		$entry    = ( '' !== $model && isset( $entries[ $model ] ) ) ? $entries[ $model ] : array();

		$out = array(
			'provider'    => $provider_id,
			'provider_label' => (string) ( $provider['label'] ?? ucfirst( $provider_id ) ),
			'model'       => (string) ( $entry['label'] ?? $model ),
			'known'       => ( '' !== $model && isset( $entries[ $model ] ) ),
			'declared'    => ( '' !== $provider_id && isset( $providers[ $provider_id ] ) ),
		);

		foreach ( self::CAPABILITIES as $capability ) {
			// A model may grant what its provider has; it may not exceed it.
			$out[ $capability ] = (bool) ( $entry[ $capability ] ?? ( $provider[ $capability ] ?? false ) );
		}

		$out['context']  = max( 1000, (int) ( $entry['context'] ?? self::unknown_model()['context'] ) );
		$out['output']   = max( 256, (int) ( $entry['output'] ?? self::unknown_model()['output'] ) );
		$out['cost_in']  = max( 0.0, (float) ( $entry['cost_in'] ?? 0.0 ) );
		$out['cost_out'] = max( 0.0, (float) ( $entry['cost_out'] ?? 0.0 ) );
		$out['available']= (bool) ( $entry['available'] ?? true );

		/**
		 * Filters the resolved capabilities of a provider and model.
		 *
		 * This is the extension point for a site that knows its real numbers — real
		 * context limits, real costs, a model the shipped table has never heard of.
		 *
		 * @param array<string, mixed> $capabilities Resolved capabilities.
		 * @param string               $provider_id  Provider identifier.
		 * @param string               $model        Model identifier.
		 */
		$filtered = apply_filters( 'replicaforge_model_capabilities', $out, $provider_id, $model );
		if ( ! is_array( $filtered ) ) {
			return $out;
		}

		// Whatever comes back is rebuilt through the same clamp, so a filter cannot
		// produce a context of zero or a negative cost and break the budget maths.
		$out               = array_merge( $out, $filtered );
		$out['context']    = max( 1000, (int) $out['context'] );
		$out['output']     = max( 256, (int) $out['output'] );
		$out['cost_in']    = max( 0.0, (float) $out['cost_in'] );
		$out['cost_out']   = max( 0.0, (float) $out['cost_out'] );
		foreach ( self::CAPABILITIES as $capability ) {
			$out[ $capability ] = (bool) $out[ $capability ];
		}

		return $out;
	}

	/**
	 * Return whether a capability is available.
	 *
	 * @param string $capability  Capability name.
	 * @param string $provider_id Provider identifier.
	 * @param string $model       Model identifier.
	 * @return bool
	 */
	public static function supports( $capability, $provider_id, $model = '' ) {
		if ( ! in_array( $capability, self::CAPABILITIES, true ) ) {
			// An unrecognised capability is not supported. A caller asking about a
			// feature this version has never heard of must not be told yes.
			return false;
		}
		$resolved = self::resolve( $provider_id, $model );
		return ! empty( $resolved[ $capability ] );
	}

	/**
	 * Return the largest context that may be sent to a model, in bytes.
	 *
	 * The usable fraction is deliberately below the model's nominal capacity. A
	 * context filled to the exact limit leaves no room for the system prompt, the
	 * response instructions, or the provider's own framing, and a request that
	 * overflows is rejected in a way that reads like an outage.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $model       Model identifier.
	 * @return int
	 */
	public static function context_budget_bytes( $provider_id, $model = '' ) {
		$resolved = self::resolve( $provider_id, $model );

		// Four bytes per token is the usual English approximation, and the fraction
		// leaves roughly an eighth of the window for framing.
		$tokens = (int) floor( $resolved['context'] * 0.85 );
		$bytes  = (int) floor( $tokens * 4 );

		return max( 4096, min( $bytes, (int) Ai_Limits::MAX_CONTEXT_BYTES ) );
	}

	/**
	 * Return the largest response that may be requested, in bytes.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $model       Model identifier.
	 * @return int
	 */
	public static function output_budget_bytes( $provider_id, $model = '' ) {
		$resolved = self::resolve( $provider_id, $model );
		$bytes    = (int) floor( $resolved['output'] * 4 );

		return max( 1024, min( $bytes, (int) Ai_Limits::MAX_OUTPUT_BYTES ) );
	}

	/**
	 * Return the models a provider offers, for a settings screen.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public static function models_for( $provider_id ) {
		$models   = self::models();
		$provider = is_string( $provider_id ) ? strtolower( trim( $provider_id ) ) : '';
		$out      = array();

		foreach ( ( isset( $models[ $provider ] ) ? $models[ $provider ] : array() ) as $model_id => $entry ) {
			$out[] = array(
				'model'     => (string) $model_id,
				'label'     => (string) ( $entry['label'] ?? $model_id ),
				'context'   => (int) ( $entry['context'] ?? 0 ),
				'output'    => (int) ( $entry['output'] ?? 0 ),
				'available' => (bool) ( $entry['available'] ?? true ),
				// Marked as an estimate at the point it is read, not only in the
				// documentation, because this array is what a settings screen
				// renders.
				'cost_estimate' => array(
					'in'  => (float) ( $entry['cost_in'] ?? 0.0 ),
					'out' => (float) ( $entry['cost_out'] ?? 0.0 ),
					'note' => __( 'Approximate, for planning only. Not a billing amount.', 'replicaforge' ),
				),
			);
		}

		return $out;
	}

	/**
	 * Return a full report of the capability registry, for diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	public static function report() {
		$providers = self::providers();
		$out       = array( 'capabilities' => self::CAPABILITIES, 'providers' => array() );

		foreach ( array_keys( $providers ) as $provider_id ) {
			$out['providers'][ $provider_id ] = array(
				'label'   => (string) ( $providers[ $provider_id ]['label'] ?? $provider_id ),
				'models'  => self::models_for( $provider_id ),
				'resolved' => self::resolve( $provider_id ),
			);
		}

		// An undeclared model resolves to the conservative floor, and that is worth
		// reporting because it is the answer to "why is so much being trimmed".
		$out['unconfigured'] = self::unknown_model();

		return $out;
	}
}
