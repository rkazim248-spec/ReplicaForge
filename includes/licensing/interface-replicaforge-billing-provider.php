<?php
/**
 * Phase 10: the billing provider contract.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * What a billing provider must be able to do.
 *
 * This interface is **not implemented in this phase**, and that is the point of
 * writing it down. §12 asks for an abstraction that Stripe, Paddle, WooCommerce, or
 * a custom licensing server can be added behind later, without the plan logic
 * having to be rewritten. An interface with no implementation is the strongest
 * possible statement that no payment processing exists: there is no code path that
 * could take a card.
 *
 * Every method returns a result that describes a *fact about the world*, never one
 * that changes it. There is no `charge()`, no `create_customer()`, no
 * `cancel_immediately()`. A billing provider reads; a separate, later, separately
 * reviewed module would write. Keeping the write verbs out of this interface is
 * what makes it safe to ship the interface now.
 *
 * An installation with no billing provider configured is a fully supported state.
 * {@see License_Manager::billing()} returns null, the plan screen says plainly that
 * billing is not configured, and the plugin behaves exactly as it does in a
 * development checkout.
 */
interface Billing_Provider_Contract {

	/**
	 * Return a unique provider identifier.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Return the provider's display name.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Return whether the provider is usable right now.
	 *
	 * False covers both "not configured" and "configured but cannot reach its
	 * service". The system status screen distinguishes them by inspecting
	 * {@see self::diagnostics()}.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Return whether the provider needs to talk to an external service.
	 *
	 * @return bool
	 */
	public function is_remote();

	/**
	 * Describe the provider's configuration state without performing a payment.
	 *
	 * @return array<string, mixed>
	 */
	public function diagnostics();

	/**
	 * Return the plan identifiers this provider is able to sell.
	 *
	 * Used to warn an administrator when a plan in the local plan set has no route
	 * to purchase. An empty list means "unknown", not "none", so a provider that
	 * cannot enumerate its catalogue is not treated as having an empty one.
	 *
	 * @return array<int, string>
	 */
	public function sellable_plan_ids();
}
