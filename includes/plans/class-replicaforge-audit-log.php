<?php
/**
 * Phase 10: the commercial audit log.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Records the security-relevant commercial events a site owner may need to review.
 *
 * This is a separate record from {@see Logger} for two reasons that matter.
 *
 * **Retention.** A diagnostic log is pruned on a day count and is expected to be
 * transient. An audit record is evidence: "who changed the plan, when, and from
 * where" has to be answerable after the fact, so a bounded ring of the last few
 * hundred events is kept where the plugin's own pruning cannot quietly delete it.
 *
 * **Selection.** The logger records everything the code thinks is interesting,
 * including request failures. This records only the events in
 * {@see self::EVENTS} — a closed list. A closed list is what makes §36's "do not
 * log API keys, passwords, authorization headers, complete source HTML, or
 * sensitive AI prompts" enforceable rather than aspirational: an event that is not
 * in the list cannot be written here, and the context each declared event may
 * carry is spelled out next to it.
 *
 * Storage is a single bounded option. The plugin has no custom tables, an audit
 * ring of a few hundred short rows is well inside what an option is for, and
 * introducing a table would mean a migration for something that is read by one
 * screen and written rarely. The bound is a real limitation and is documented in
 * `docs/PLANS-AND-USAGE.md`: a burst of denials can push an earlier event out of
 * the ring, and the ring is a recent-history record, not a compliance archive.
 */
final class Audit_Log {

	/**
	 * Option holding the ring.
	 */
	const OPTION = 'replicaforge_audit_log';

	/**
	 * Maximum entries retained.
	 *
	 * Bounded so a hostile client that triggers a denial on every request cannot
	 * grow an option without limit. 500 entries at roughly 150 bytes each is about
	 * 75 KB, which is comfortable for an autoloaded option.
	 */
	const MAX_ENTRIES = 500;

	/**
	 * Maximum length of any single string value.
	 */
	const MAX_VALUE_LENGTH = 120;

	/**
	 * Context keys that are never recorded, whatever the caller passes.
	 *
	 * Matched as substrings against the lowercased key, so `api_key`,
	 * `userApiKey`, and `api-key` are all caught by `key`.
	 *
	 * @var array<int, string>
	 */
	const FORBIDDEN_KEY_PARTS = array(
		'pass',
		'secret',
		'token',
		'key',
		'auth',
		'cookie',
		'credential',
		'signature',
		'nonce',
		'html',
		'body',
		'prompt',
		'response',
	);

	/**
	 * The declared audit events.
	 *
	 * Each entry lists the context keys that event may carry. A key not listed for
	 * the event is dropped, which is stricter than filtering by name alone: a
	 * caller cannot smuggle a value into a declared event under an innocent key.
	 *
	 * @var array<string, array<int, string>>
	 */
	const EVENTS = array(
		'plan_changed'              => array( 'plan_id', 'from_plan', 'to_plan' ),
		'license_state_changed'     => array( 'from_state', 'to_state', 'provider' ),
		'license_invalidated'       => array( 'from_state', 'to_state', 'provider' ),
		'usage_charged'             => array( 'operation', 'quantity', 'plan_id' ),
		'usage_not_charged'         => array( 'operation', 'reason' ),
		'limit_reached'             => array( 'operation', 'limit', 'used', 'plan_id' ),
		'entitlement_denied'        => array( 'operation', 'reason' ),
		'ownership_denied'          => array( 'operation', 'project_id' ),
		'capability_missing'        => array( 'operation', 'capability' ),
		'plan_definition_imported'  => array( 'stored', 'rejected', 'by' ),
		'plan_definition_exported'  => array( 'count', 'by' ),
		'plan_definition_reset'     => array( 'by' ),
		'trial_settings_changed'    => array( 'enabled', 'days', 'plan', 'by' ),
		'trial_started'             => array( 'plan', 'days', 'by' ),
		'trial_cancelled'           => array( 'by' ),
		'setting_changed'           => array( 'setting', 'value', 'by' ),
		'capabilities_granted'      => array( 'roles', 'count' ),
		'onboarding_completed'      => array( 'by' ),
		'onboarding_skipped'        => array( 'by' ),
	);

	/**
	 * Record an event.
	 *
	 * An event that is not declared is dropped and reported in the log, not
	 * written. A typo'd event name silently disappearing would be exactly the
	 * failure this class is supposed to prevent, so it is made visible.
	 *
	 * @param string               $event   Event name.
	 * @param array<string, mixed> $context Context, filtered to the event's declared keys.
	 * @param int                  $user_id Acting user.
	 * @return bool Whether the event was written.
	 */
	public static function record( $event, array $context = array(), $user_id = 0 ) {
		$event = is_string( $event ) ? $event : '';

		if ( ! isset( self::EVENTS[ $event ] ) ) {
			( new Logger() )->warning(
				'audit_event_undeclared',
				'An audit event was not in the declared list and was dropped.',
				array( 'event' => substr( $event, 0, 40 ) ),
				'plans'
			);
			return false;
		}

		$entry = array(
			'event'     => $event,
			'timestamp' => time(),
			'user_id'   => max( 0, (int) $user_id ),
			'ip_hash'   => self::ip_hash(),
			'context'   => self::filter_context( $event, $context ),
		);

		/**
		 * Fires after an audit event is prepared, before it is stored.
		 *
		 * Returning a modified entry array changes what is stored. This is an
		 * extension point for an installation that needs to add a field of its own;
		 * the entry is already sanitised by the time it arrives here.
		 *
		 * @param array<string, mixed> $entry  The prepared entry.
		 * @param string               $event  The event name.
		 */
		$entry = apply_filters( 'replicaforge_audit_entry', $entry, $event );
		if ( ! is_array( $entry ) ) {
			return false;
		}

		$entries = get_option( self::OPTION, array() );
		$entries = is_array( $entries ) ? array_values( $entries ) : array();

		$entries[] = $entry;

		$overflow = count( $entries ) - self::MAX_ENTRIES;
		if ( $overflow > 0 ) {
			$entries = array_slice( $entries, $overflow );
		}

		update_option( self::OPTION, $entries, false );

		return true;
	}

	/**
	 * Return recent entries, newest first.
	 *
	 * @param array<string, mixed> $filters Optional event and user filters.
	 * @param int                  $limit   Maximum entries.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( array $filters = array(), $limit = 50 ) {
		$entries = get_option( self::OPTION, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		$entries = array_reverse( array_values( $entries ) );

		$event  = isset( $filters['event'] ) && is_string( $filters['event'] ) ? $filters['event'] : '';
		$user   = isset( $filters['user_id'] ) ? (int) $filters['user_id'] : 0;
		$since  = isset( $filters['since'] ) ? (int) $filters['since'] : 0;

		$out = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( '' !== $event && (string) ( $entry['event'] ?? '' ) !== $event ) {
				continue;
			}
			if ( $user > 0 && (int) ( $entry['user_id'] ?? 0 ) !== $user ) {
				continue;
			}
			if ( $since > 0 && (int) ( $entry['timestamp'] ?? 0 ) < $since ) {
				continue;
			}
			$out[] = $entry;
			if ( count( $out ) >= max( 1, min( self::MAX_ENTRIES, (int) $limit ) ) ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Return how many entries are stored.
	 *
	 * @return int
	 */
	public static function count() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? count( $entries ) : 0;
	}

	/**
	 * Return a summary by event name.
	 *
	 * @return array<string, int>
	 */
	public static function summary() {
		$entries = get_option( self::OPTION, array() );
		$out     = array();
		if ( ! is_array( $entries ) ) {
			return $out;
		}
		foreach ( $entries as $entry ) {
			$event = is_array( $entry ) ? (string) ( $entry['event'] ?? '' ) : '';
			if ( '' === $event ) {
				continue;
			}
			$out[ $event ] = isset( $out[ $event ] ) ? $out[ $event ] + 1 : 1;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Return the declared events with their allowed context keys.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function events() {
		return self::EVENTS;
	}

	/**
	 * Remove every entry.
	 *
	 * @return bool
	 */
	public static function clear() {
		return delete_option( self::OPTION );
	}

	/**
	 * Reduce context to the event's declared keys and sanitise the values.
	 *
	 * @param string               $event   Event name.
	 * @param array<string, mixed> $context Incoming context.
	 * @return array<string, mixed>
	 */
	private static function filter_context( $event, array $context ) {
		$allowed = isset( self::EVENTS[ $event ] ) ? self::EVENTS[ $event ] : array();
		$out     = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}

			$lowered = strtolower( $key );
			$blocked = false;
			foreach ( self::FORBIDDEN_KEY_PARTS as $part ) {
				if ( false !== strpos( $lowered, $part ) ) {
					$blocked = true;
					break;
				}
			}
			if ( $blocked ) {
				continue;
			}

			$value = $context[ $key ];
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$out[ $key ] = $value;
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				// An array or object is not a fact an audit entry needs, and a nested
				// structure is where a large value would hide.
				continue;
			}

			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}

			// Markup is stripped rather than escaped, because an audit entry that
			// contains a tag is a value that came from something the plugin did not
			// generate — a source page, an AI response, an error page. Dropping it
			// is both the §36 requirement and the simplest way to keep a 120-byte
			// field from being a place to smuggle content.
			if ( preg_match( '/<[a-z!\/]/i', $value ) ) {
				continue;
			}

			$out[ $key ] = substr( sanitize_text_field( $value ), 0, self::MAX_VALUE_LENGTH );
		}

		return $out;
	}

	/**
	 * Return a stable, non-reversible marker for the requesting address.
	 *
	 * A raw IP address is personal data, and §28 forbids collecting more than is
	 * needed. What an administrator actually wants from an audit entry is "was this
	 * the same client as last time", so the address is hashed with a per-site salt.
	 * Two entries from the same client share a marker; the marker cannot be turned
	 * back into an address, and the salt means it cannot be matched against a log
	 * file from somewhere else.
	 *
	 * @return string
	 */
	private static function ip_hash() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $remote || ! is_string( $remote ) ) {
			return '';
		}

		$salt = defined( 'LOGGED_IN_SALT' ) && LOGGED_IN_SALT ? LOGGED_IN_SALT : AUTH_SALT;
		if ( ! is_string( $salt ) || '' === $salt ) {
			// A site with no salts configured is misconfigured; without one the hash
			// would be a plain address digest that a rainbow table defeats, so
			// nothing is recorded rather than something weakly recorded.
			return '';
		}

		return substr( hash_hmac( 'sha256', $remote, $salt ), 0, 16 );
	}
}
