<?php
/**
 * Phase 10: the licensing provider contract.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * What a licensing provider must be able to do.
 *
 * The interface exists so that connecting a real licensing server later is an
 * addition, not a rewrite. The brief is explicit that no paid service may be
 * required, so the only implementation shipped in this phase is
 * {@see Local_License_Provider}, which reads a value an administrator set in the
 * database. What the interface fixes is the *shape* of the answer, so that the
 * entitlement system never has to know where a plan came from.
 *
 * Two rules are enforced by this interface's shape rather than by convention:
 *
 * A provider returns a {@see License_State}, never a plan id. That is what stops a
 * caller from treating "the provider said so" as "the plan is whatever the string
 * said". The state is the fact; the plan is derived from it and validated against
 * the plan set.
 *
 * A provider is asked, never told. There is no `activate()` here on purpose. §11
 * forbids fake activation, and the surest way not to build it is to leave the
 * method out: a real provider added later can be a different interface entirely,
 * and a licensing server that requires an outbound request cannot be expressed as
 * "read a value out of the database" in the first place.
 */
interface License_Provider_Contract {

	/**
	 * Return a unique provider identifier.
	 *
	 * Used in the audit log and in the system status screen so an administrator can
	 * tell *which* provider answered.
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
	 * Return whether the provider talks to an external service.
	 *
	 * A provider that does is unavailable when the site has no outbound network
	 * access, which changes what "unknown" means for a user: for the local provider
	 * unknown is a configuration mistake, and for a remote provider it is a network
	 * problem. The system status screen shows these differently because a user
	 * cannot fix them differently.
	 *
	 * @return bool
	 */
	public function is_remote();

	/**
	 * Return the current license state.
	 *
	 * Implementations must not throw. A provider that cannot answer returns a
	 * state of `unknown` and says why in the state's reason, because a licensing
	 * outage must not take generation down with it.
	 *
	 * @return License_State
	 */
	public function state();
}
