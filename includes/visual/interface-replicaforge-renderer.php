<?php
/**
 * Phase 13: the rendering provider contract.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * What every rendering provider must be able to do.
 *
 * ### Why an interface, stated as a constraint rather than a preference
 *
 * §3 says the architecture must not permanently depend on one browser technology, and
 * the real reason is not elegance. ReplicaForge cannot ship a headless browser, and
 * rendering the analyzed page **inside the WordPress process** would execute
 * untrusted site content in the same interpreter that holds the site's database
 * credentials. That is not a risk worth taking for a screenshot.
 *
 * So rendering is always *out of process*, always *explicitly configured*, and always
 * *optional*. This interface is the seam: an operator can point ReplicaForge at
 * Browserless, a self-hosted Playwright, or an internal render service without the
 * plugin changing, and an operator with none of those gets a working product that
 * says rendered comparison is unavailable rather than one that silently guesses.
 *
 * ### What a provider must never do
 *
 * - **Return the page as the answer.** A screenshot is *evidence*. A provider that
 *   returns "here is your website, one image" inverts the entire design and would
 *   produce an unreadable, uneditable replica. {@see self::screenshot()} returns image
 *   bytes and the interface says nothing about reconstruction; reconstruction reads
 *   the *representation*, never the image.
 * - **Execute scripts inside WordPress.** Scripts are the provider's problem, in the
 *   provider's sandbox. §5 and §42.
 * - **Fetch an unvalidated URL.** The provider receives a URL that has already been
 *   through {@see Url_Validator}; a provider that will fetch anything it is given is a
 *   provider that turns ReplicaForge into an SSRF proxy.
 */
interface Renderer_Contract {

	/**
	 * Return the provider's identifier.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Return the capabilities §4 requires be detected.
	 *
	 * Every key must be present as a boolean. A provider that omits a key is treated
	 * as not supporting it, so omitting is always the safe direction — but the caller
	 * still gets a complete answer to show a user.
	 *
	 * @return array<string, bool>
	 */
	public function capabilities();

	/**
	 * Return whether the provider can be used right now.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Return why the provider is unavailable.
	 *
	 * Must be user-readable and specific. "Rendering unavailable" with no reason is
	 * the same failure as a crash, from the user's side.
	 *
	 * @return string
	 */
	public function unavailable_reason();

	/**
	 * Return the provider's version string, for a cache key.
	 *
	 * A provider upgrade can change what a screenshot looks like, so its version is
	 * part of the cache key. A provider with no version must return a non-empty
	 * string — returning `''` would make two different builds share a cache.
	 *
	 * @return string
	 */
	public function version();

	/**
	 * Capture one screenshot.
	 *
	 * @param array<string, mixed> $request Render request.
	 * @return array<string, mixed>
	 */
	public function screenshot( array $request );
}
