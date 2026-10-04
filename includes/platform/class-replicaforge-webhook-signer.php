<?php
/**
 * Phase 20: webhook signing.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Signs and verifies webhook payloads.
 *
 * ### The secret is derived, never stored
 *
 * A signing secret has to be usable at send time and unreadable at rest. Those pull in
 * opposite directions, so this resolves it by **not storing it**:
 *
 * ```
 * secret = HMAC-SHA256( Secure_Token::salt(), 'replicaforge-webhook|' . public_id )
 * ```
 *
 * `Secure_Token::salt()` is the plugin's existing site-wide secret. The result is stable for
 * the life of the site, differs per subscription, and exists nowhere at rest — no column,
 * no option, no log line, no console field.
 *
 * ### The signature covers the timestamp, and the timestamp is checked
 *
 * Three headers go out:
 *
 * ```
 * X-ReplicaForge-Signature:  v1=<hex>
 * X-ReplicaForge-Timestamp: 1759276800
 * X-ReplicaForge-Delivery:   <public id>.<attempt>
 * ```
 *
 * The signed string is `"{timestamp}.{body}"`. Binding the timestamp into the signature is
 * what makes replay detection possible at all: a captured signature is only valid while its
 * timestamp is inside {@see Platform_Limits::WEBHOOK_REPLAY_WINDOW}, and a receiver that
 * re-checks the timestamp gets replay protection for free by verifying the same string.
 *
 * The receiver's job is therefore: refuse a timestamp outside a small window, then verify
 * `v1=HMAC(secret, timestamp + "." + body)` with `hash_equals`. Both halves matter — the
 * timestamp check is cheap and catches the common case, the HMAC is what actually
 * authenticates.
 *
 * ### Why `hash_equals` and not `===`
 *
 * The same reasoning as `Secure_Token::verify()`: a string comparison short-circuits, so a
 * caller can discover a signature one byte at a time by timing responses.
 */
final class Webhook_Signer {

	/**
	 * Signature scheme version.
	 *
	 * Sent as `v1=`. Present so a future scheme can be introduced without a receiver having
	 * to guess which comparison produced the value it was given.
	 *
	 * @var string
	 */
	const SCHEME = 'v1';

	/**
	 * Header carrying the signature.
	 *
	 * @var string
	 */
	const HEADER_SIGNATURE = 'X-ReplicaForge-Signature';

	/**
	 * Header carrying the signed timestamp.
	 *
	 * @var string
	 */
	const HEADER_TIMESTAMP = 'X-ReplicaForge-Timestamp';

	/**
	 * Header carrying the delivery id.
	 *
	 * @var string
	 */
	const HEADER_DELIVERY = 'X-ReplicaForge-Delivery';

	/**
	 * Header carrying the event id, so a receiver can correlate without parsing the body.
	 *
	 * @var string
	 */
	const HEADER_EVENT = 'X-ReplicaForge-Event';

	/**
	 * Domain separation string.
	 *
	 * Prevents a value derived for one purpose from ever being usable for another. Without
	 * it, a value that happened to be a valid webhook signature under some other derivation
	 * would verify.
	 *
	 * @var string
	 */
	const CONTEXT = 'replicaforge-webhook|';

	/**
	 * Return the signing secret for a subscription.
	 *
	 * @param string $webhook_id Webhook public id.
	 * @return string
	 */
	public static function secret_for( $webhook_id ) {
		$webhook_id = self::clean_id( $webhook_id );

		if ( '' === $webhook_id ) {
			return '';
		}

		return hash_hmac( 'sha256', self::CONTEXT . $webhook_id, Secure_Token::salt() );
	}

	/**
	 * Sign a payload.
	 *
	 * @param string $webhook_id Webhook public id.
	 * @param string $body       The exact request body.
	 * @param int    $timestamp  Unix timestamp, or 0 for now.
	 * @return array{signature: string, timestamp: int}
	 */
	public static function sign( $webhook_id, $body, $timestamp = 0 ) {
		$timestamp = $timestamp > 0 ? (int) $timestamp : time();

		return array(
			'signature' => self::SCHEME . '=' . self::compute( $webhook_id, (string) $body, $timestamp ),
			'timestamp' => $timestamp,
		);
	}

	/**
	 * Verify a signature. Used by tests and by the delivery test endpoint.
	 *
	 * @param string $webhook_id Webhook public id.
	 * @param string $body       Request body as received.
	 * @param string $signature  The header value.
	 * @param int    $timestamp  The timestamp header.
	 * @return bool
	 */
	public static function verify( $webhook_id, $body, $signature, $timestamp ) {
		$signature = is_string( $signature ) ? trim( $signature ) : '';
		$timestamp = (int) $timestamp;

		if ( '' === $signature || $timestamp < 1 ) {
			return false;
		}

		if ( 0 !== strpos( $signature, self::SCHEME . '=' ) ) {
			return false;
		}

		$presented = substr( $signature, strlen( self::SCHEME ) + 1 );

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $presented ) ) {
			return false;
		}

		if ( ! self::timestamp_is_current( $timestamp ) ) {
			return false;
		}

		return hash_equals( self::compute( $webhook_id, (string) $body, $timestamp ), $presented );
	}

	/**
	 * Return whether a timestamp is inside the replay window.
	 *
	 * @param int  $timestamp Unix timestamp.
	 * @param int  $now       Reference time, or 0 for now.
	 * @return bool
	 */
	public static function timestamp_is_current( $timestamp, $now = 0 ) {
		$timestamp = (int) $timestamp;
		$now       = $now > 0 ? (int) $now : time();

		if ( $timestamp < 1 ) {
			return false;
		}

		/* Absolute rather than signed distance, so a timestamp far in the future is refused
		 * too. A receiver that only checked `now - ts < window` would accept a timestamp set
		 * to the year 2100 indefinitely, which is a replay window with no right edge. */
		return abs( $now - $timestamp ) <= Platform_Limits::WEBHOOK_REPLAY_WINDOW;
	}

	/**
	 * Return a delivery id.
	 *
	 * @param string $delivery_id Delivery record public id.
	 * @param int    $attempt     One-based attempt.
	 * @return string
	 */
	public static function delivery_id( $delivery_id, $attempt ) {
		return substr( (string) $delivery_id, 0, 26 ) . '.' . max( 1, (int) $attempt );
	}

	/**
	 * Return whether a delivery id is one this request already made.
	 *
	 * §15 asks for idempotency, and the only honest implementation is to let the receiver
	 * deduplicate: the id is stable across the retries of one delivery and different for
	 * every new one, so a receiver that remembers the ids it has processed gets exactly-once
	 * behaviour for a delivery that ReplicaForge may have sent more than once.
	 *
	 * ReplicaForge cannot make a remote system idempotent from this side. What it can do is
	 * make the retry visible and stable, which is what the id is for.
	 *
	 * @param string $delivery_id  Delivery id from the header.
	 * @param array<int, string> $seen Ids already sent in this request.
	 * @return bool
	 */
	public static function is_duplicate( $delivery_id, array $seen ) {
		return in_array( (string) $delivery_id, $seen, true );
	}

	/**
	 * Compute the signature value.
	 *
	 * @param string $webhook_id Webhook public id.
	 * @param string $body       Request body.
	 * @param int    $timestamp  Unix timestamp.
	 * @return string
	 */
	private static function compute( $webhook_id, $body, $timestamp ) {
		$secret = self::secret_for( $webhook_id );

		if ( '' === $secret ) {
			return '';
		}

		/*
		 * `timestamp . "." . body` — the separator matters. Without a separator, a timestamp
		 * and a body could be re-split between the two, so a signature over one boundary
		 * would verify over another.
		 */
		return hash_hmac( 'sha256', (int) $timestamp . '.' . $body, $secret );
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private static function clean_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}
}
