<?php
/**
 * Phase 16: the browser observation contract.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * What a browser observation provider must be able to do.
 *
 * ### This is not a second rendering engine, and it is not a browser automation library
 *
 * The specification is explicit on both points, and the difference matters enough to spell
 * out:
 *
 * - **Not a second rendering engine.** `Renderer_Contract` (phase 13) already models
 *   "something out of process that can look at a page". This contract is a *narrower* view
 *   of the same out-of-process world: it exposes a fixed list of observation operations and
 *   nothing else. A provider that implements this does not gain the ability to screenshot,
 *   and a screenshot provider does not gain the ability to click. Splitting them is
 *   deliberate — a render job and an interaction job have different budgets, different
 *   retentions and different privacy postures, and one contract for both would mean
 *   loosening one of them.
 *
 * - **Not automation.** There is no "evaluate this JavaScript", no "run this expression",
 *   no "type these arbitrary keystrokes", and no way to reach a shell. The operations below
 *   are the *complete* surface. A provider cannot be asked to do something else, because
 *   there is no method to ask with. That is the enforcement mechanism: the danger is not
 *   that a provider might misbehave, it is that asking would be possible at all.
 *
 * ### Why a contract and not a Playwright dependency
 *
 * Because the plugin cannot ship one. Bundling a browser means shipping ~300MB of
 * binaries, a Chromium that needs system libraries the host may not have, and a
 * CVE surface that has to be tracked. Every earlier phase made the same call and Phase 13
 * documents it at length. An operator who wants interaction intelligence configures a
 * driver; an operator who does not gets a working product that reports
 * `BROWSER_UNAVAILABLE` and still produces a full static interaction model, because
 * `aria-expanded`, `<details>`, `role="tab"` and `cursor:pointer` are all readable without
 * executing anything.
 *
 * ### What a driver must never do
 *
 * - **Execute scripts inside WordPress.** Never. A driver's scripts run in the driver's
 *   process, out of process, in the operator's sandbox.
 * - **Return cookies, tokens, storage or form values.** §50 forbids persisting them and a
 *   provider that returns them invites their accidental storage. {@see self::observe()}
 *   is documented to return normalised observations only.
 * - **Follow a redirect to a private address.** The driver re-validates every hop through
 *   {@see Url_Validator}; a driver that follows redirects blindly turns ReplicaForge into
 *   an SSRF proxy with a screenshot attached.
 * - **Bypass its own budget.** `max_interactions` and the rest are passed in and the
 *   provider reports what it used. A provider that silently continues past a limit has
 *   turned a bounded observation into an unbounded one.
 */
interface Browser_Driver_Contract {

	/**
	 * Return the driver's identifier.
	 *
	 * Part of the observation cache key, so two drivers never share cached observations —
	 * a driver with a different DOM model genuinely sees different things.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Return the driver's version.
	 *
	 * Also part of the cache key. A driver upgrade can change which interactions are
	 * detected, and a version that is not in the key cannot express that.
	 *
	 * @return string
	 */
	public function version();

	/**
	 * Return the capabilities this driver supports.
	 *
	 * Every key in {@see self::CAPABILITIES} must be present as a boolean. Omitting a key
	 * is treated as unsupported, so omission is always the safe direction — but a caller
	 * still gets a complete answer it can show a user, which matters because "this browser
	 * cannot read the accessibility tree" is a sentence somebody needs to be able to read.
	 *
	 * @return array<string, bool>
	 */
	public function capabilities();

	/**
	 * Return whether the driver can be used right now.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Return why the driver is unavailable.
	 *
	 * Must be specific and user-readable. "Browser unavailable" with no reason is
	 * indistinguishable from a crash, and the operator needs to know whether to configure
	 * something or to fix something.
	 *
	 * @return string
	 */
	public function unavailable_reason();

	/**
	 * Run one observation session against one page.
	 *
	 * ### The request
	 *
	 * `url` must already have been through {@see Url_Validator}. The driver re-validates
	 * rather than trusting that, because a driver is a network client and the boundary
	 * belongs at the client.
	 *
	 * `budget` carries the ceilings from {@see Interaction_Limits::BUDGETS()}. The driver
	 * reports actual usage in the result rather than only stopping, so the caller can
	 * distinguish "stopped because the budget ran out" from "finished".
	 *
	 * `interaction_types` is the allowlist the session may trigger, already intersected with
	 * {@see Interaction_Limits::OBSERVABLE_TYPES()} by the caller. A driver that triggers
	 * something outside it has misbehaved, and the session recorder checks for that.
	 *
	 * ### The result
	 *
	 * `observations` is a list of normalised events and nothing else:
	 *
	 *     array(
	 *       'seq'           => int,     // order within the session
	 *       'kind'          => string,  // 'initial' | 'after_trigger'
	 *       'trigger'       => string,
	 *       'element_id'    => string,
	 *       'dom_changed'   => bool,
	 *       'geometry_changed' => bool,
	 *       'visibility_changed' => bool,
	 *       'attribute_changes' => array<string, string>,
	 *       'class_added'   => array<int, string>,
	 *       'class_removed' => array<int, string>,
	 *       'url_changed'   => bool,
	 *       'url'           => string,  // only if url_changed
	 *       'scroll_y'      => int,
	 *       'at_ms'         => int,
	 *       'viewport'      => string,
	 *     )
	 *
	 * There is no `html`, no `outerHTML`, no `script`, no `cookie`, no `storage` and no form
	 * value in that shape, and a provider returning one has broken the contract.
	 *
	 * @param array<string, mixed> $request Observation request.
	 * @return array<string, mixed> Result, or an error envelope.
	 */
	public function observe( array $request );
}
