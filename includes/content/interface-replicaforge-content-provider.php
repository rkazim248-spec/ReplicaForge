<?php
/**
 * Phase 14: the destination content provider contract.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * What a destination — the user's own site — must be able to tell ReplicaForge about.
 *
 * ### Why the destination is behind an interface
 *
 * §8 requires WooCommerce support "if installed and active", and WooCommerce is not
 * installed in every environment — including, as it happens, the environment this phase
 * was built in. The two wrong answers are both obvious: hard-coding `WC_Product` calls
 * makes the plugin fatal on a site without WooCommerce, and shipping a stub that
 * pretends a store exists makes a mapping engine confidently write to nothing.
 *
 * So a provider declares its own availability, and an unavailable provider is a
 * *reported state* rather than a crash. `WordPress_Content_Provider` is always available.
 * `WooCommerce_Provider` is available exactly when the real thing is.
 *
 * ### The security boundary this contract draws
 *
 * A provider **reads** the destination. It never writes to it.
 *
 * That separation is the whole point of the interface, and it is why there is no
 * `update()`, no `set_price()`, and no `save()` on this contract. §12 and §48 are
 * unambiguous that AI and mapping logic must not modify products, prices, inventory,
 * orders, or users, and the way to make that structurally true is for the object that
 * holds the database credentials to have no method that could use them to write.
 *
 * Writes go through exactly one path: {@see Content_Applier}, which takes a *validated
 * plan* and an *approved subset* of it, and which is where snapshots, ownership checks,
 * and rollback live.
 *
 * ### What a provider must never return
 *
 * - Anything the requesting user is not allowed to see. A provider is called with a
 *   `user_id` and must scope every query to what that user can read.
 * - Private customer data, ever. A content provider reads *content*, not *people*. There
 *   is no method that returns an order, a customer, an address book, or a payment
 *   record, so there is no path by which one can leak.
 * - A field it cannot actually write. `writable` is false for a derived or
 *   computed field, and {@see Content_Validator} refuses a mapping onto a field that
 *   says so.
 */
interface Content_Provider_Contract {

	/**
	 * Return the provider's identifier.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Return whether the provider can be used right now.
	 *
	 * A provider that is not available must say why in {@see self::unavailable_reason()}.
	 * Returning `false` with no explanation is the same failure as a crash from the
	 * user's side.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Return why the provider is unavailable, in user-readable terms.
	 *
	 * @return string
	 */
	public function unavailable_reason();

	/**
	 * Return the fields this provider can supply, for the given entity type.
	 *
	 * @param string $entity_type Entity type.
	 * @return array<int, array<string, mixed>> Field descriptors.
	 */
	public function fields_for( $entity_type );

	/**
	 * Return a page of destination entities.
	 *
	 * Paged because §44 requires working with 10,000+ products without loading them
	 * all. A provider that returned everything in one call would be unusable on a real
	 * store, and the paged signature is what makes a correct implementation possible.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $page        One-based page number.
	 * @param int    $per_page    Records per page.
	 * @param array  $args        Optional filters.
	 * @return array<string, mixed>
	 */
	public function entities( $entity_type, $page = 1, $per_page = 0, array $args = array() );

	/**
	 * Return one entity's field values.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity id.
	 * @return array<string, mixed>|null Null when the entity does not exist or is not readable.
	 */
	public function entity( $entity_type, $entity_id );

	/**
	 * Return the entity types this provider supports.
	 *
	 * @return array<int, string>
	 */
	public function entity_types();

	/**
	 * Return a provider version, for the content cache key.
	 *
	 * A provider upgrade can change what fields it offers, so its version belongs in
	 * the key. A provider with no version must return a non-empty string.
	 *
	 * @return string
	 */
	public function version();
}
