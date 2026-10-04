<?php
/**
 * Provider-neutral AI contract.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the reconstruction manager independent from any external vendor.
 */
interface Ai_Provider_Contract {

	/**
	 * Return the stable provider identifier.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Return what this provider can do.
	 *
	 * §9 is explicit that providers must not be assumed to be interchangeable, and
	 * the fix is to ask rather than to configure. A caller that needs structured
	 * output asks here; a caller that needs to fit a large context asks here.
	 * Guessing produced a plugin that sent a JSON-mode flag to a provider that
	 * ignores it and surfaced the consequence three layers away as a schema error.
	 *
	 * A provider returns its own identifier and nothing else; the capabilities are
	 * resolved through {@see Ai_Capabilities}, so an adapter that knows only its id
	 * still gets the declared table rather than an empty array every caller would
	 * have to defend against.
	 *
	 * @return array<string, mixed>
	 */
	public function capabilities();

	/**
	 * Analyze a bounded prompt request.
	 *
	 * @param array<string, mixed> $request Prompt request.
	 * @return array<string, mixed> Provider envelope containing content or error.
	 */
	public function analyze( array $request );

	/**
	 * Perform a small provider connectivity check.
	 *
	 * @return array<string, mixed>
	 */
	public function test_connection();
}
