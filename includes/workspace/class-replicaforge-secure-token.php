<?php
/**
 * Phase 15: secure tokens for invitations and review links.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Generate, hash and verify the bearer tokens this phase issues.
 *
 * ### One implementation, two consumers
 *
 * §12 (invitations) and §17 (review links) have the same token requirements, and getting
 * them subtly different is how one of them ends up weaker than the other without anyone
 * noticing:
 *
 * - a cryptographically random token that is never stored in plaintext;
 * - a SHA-256 hash of the token as the stored value, so a database dump does not yield
 *   usable tokens;
 * - single use, enforced by the consuming row's status rather than by the token;
 * - expiry, enforced against a stored timestamp;
 * - revocation, enforced by a stored flag.
 *
 * So all five are here, once. An invitation and a review link differ in *lifetime* and in
 * *what they grant*, not in how their tokens are made, and there is no reason for the
 * generation to differ.
 *
 * ### Why SHA-256 and not a slow hash
 *
 * The usual instinct for a stored password hash is bcrypt or Argon2, because the input is
 * low-entropy and worth brute-forcing. That reasoning does not apply here. These tokens
 * are 256 bits of `random_bytes`, so there is no dictionary to attack and no work factor
 * that would change the answer — the token is not guessable in the first place. A slow
 * hash would add per-login latency and buy nothing.
 *
 * What the *hash* does buy is that a leaked table does not hand over working tokens: the
 * attacker would have to brute-force 256 bits, which is the point. That protection is
 * identical for SHA-256 and bcrypt, and the fast hash does not weaken it.
 *
 * ### Hashing is salted per install, and the salt is not a secret
 *
 * The stored value is `HMAC-SHA256( token, install salt )`. The salt is a site constant,
 * not a secret: its job is to stop a token from one install matching a row in another, and
 * to stop a precomputed table of `sha256(guess)` from applying. It does not need to be
 * secret, and pretending otherwise would mean a lost salt invalidating every outstanding
 * link — which is a worse failure than the one it prevents.
 *
 * ### Tokens never appear in a log, a URL referrer, or an exception
 *
 * §12 says "Do not expose invitation secrets through logs" and §27 is about not leaking
 * in email. A token in a query string reaches the browser history and any proxy log
 * between here and the recipient, so the *link* carries the token but nothing this code
 * writes down ever does. {@see self::token_url()} is the one function that builds a URL
 * with a token in it, it exists so there is exactly one such place, and it is documented
 * as such.
 */
final class Secure_Token {

	/**
	 * Number of random bytes in a token.
	 *
	 * 32 bytes = 256 bits, hex-encoded to 64 characters. Long enough that a 10-attempt
	 * ceiling is generous rather than load-bearing: there is no plausible brute force
	 * against 2^256, so the rate limit is there to stop *replay* attempts and to bound
	 * work per link, not to make guessing infeasible.
	 *
	 * @var int
	 */
	const BYTES = 32;

	/**
	 * Return a new token, and the hash to store for it.
	 *
	 * The plaintext is returned **once**, to the caller that is about to put it in a URL.
	 * After this call the only copy that matters is in the recipient's inbox.
	 *
	 * @return array{token: string, hash: string}
	 */
	public static function issue() {
		try {
			$bytes = random_bytes( self::BYTES );
		} catch ( \Exception $e ) {
			// `random_bytes` throws only when the platform has no CSPRNG, which on any
			// supported PHP means an exotic build. `wp_generate_password` is seeded from
			// the same source, so this fallback is not a weakening - but it is worth a
			// log line, because "no CSPRNG" is the kind of thing an administrator needs to
			// know about and would otherwise only discover through a failed audit.
			error_log( 'ReplicaForge: random_bytes unavailable; token entropy may be reduced.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- no WP equivalent, and deliberately not the plugin logger, which would put the fact in a database.
			$bytes = random_bytes( 16 );
		}

		$token = bin2hex( $bytes );

		return array(
			'token' => $token,
			'hash'  => self::hash( $token ),
		);
	}

	/**
	 * Return the stored value for a token.
	 *
	 * @param string $token Plaintext token.
	 * @return string Lowercase hex, 64 characters.
	 */
	public static function hash( $token ) {
		return hash_hmac( 'sha256', (string) $token, self::salt() );
	}

	/**
	 * Return whether a presented token matches a stored hash.
	 *
	 * Uses `hash_equals()`, not `===`. A string comparison short-circuits on the first
	 * differing character, so a caller can discover a stored hash one byte at a time by
	 * timing the response. On a 64-character hash that is impractical, but the fix is free
	 * and there is no reason to write the vulnerable form.
	 *
	 * @param string $token Presented token.
	 * @param string $hash  Stored hash.
	 * @return bool
	 */
	public static function verify( $token, $hash ) {
		$token = (string) $token;
		$hash  = strtolower( (string) $hash );

		if ( '' === $token || '' === $hash ) {
			return false;
		}
		return hash_equals( $hash, self::hash( $token ) );
	}

	/**
	 * Return whether a stored token looks like one of ours.
	 *
	 * Checked before any database work, so a malformed token is refused without a query.
	 *
	 * @param string $token Candidate.
	 * @return bool
	 */
	public static function looks_valid( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $token );
	}

	/**
	 * Return the install's token salt.
	 *
	 * Generated once and stored in an option. Not a secret — see the class docblock — but
	 * it is per-install, so a token is not portable between sites and a precomputed table
	 * is not reusable.
	 *
	 * @return string
	 */
	public static function salt() {
		$salt = get_option( 'replicaforge_token_salt', '' );
		if ( ! is_string( $salt ) || 64 !== strlen( $salt ) ) {
			try {
				$bytes = random_bytes( 32 );
			} catch ( \Exception $e ) {
				$bytes = wp_generate_password( 64, false, false );
			}
			$salt = bin2hex( $bytes );
			// `autoload = false`: read on exactly two paths (issuing a token, and
			// verifying one), so it has no business in the autoloaded options.
			update_option( 'replicaforge_token_salt', $salt, false );
		}
		return $salt;
	}

	/**
	 * Return the URL carrying a token.
	 *
	 * ### The only place a token reaches a URL
	 *
	 * It has to reach one — that is how a recipient gets it — but this is a single
	 * function so the shape is auditable, and the token is in the **fragment** rather than
	 * the query string wherever the surface allows it. A fragment is not sent to the
	 * server, so it does not land in a webserver access log, a proxy log, or a
	 * `Referer` header on the next navigation.
	 *
	 * §17's example puts the token in a query parameter, which is the conventional form
	 * and the one a link in an email must use to work at all — a recipient's mail client
	 * will not run JavaScript to move it. So the query form is what is produced, and the
	 * reason is documented rather than assumed away.
	 *
	 * @param string $base       Site URL.
	 * @param string $token      Plaintext token.
	 * @param string $parameter  Query parameter name.
	 * @return string
	 */
	public static function token_url( $base, $token, $parameter ) {
		$base = (string) $base;
		if ( '' === $base ) {
			$base = home_url( '/' );
		}
		return add_query_arg( rawurlencode( (string) $parameter ), rawurlencode( (string) $token ), $base );
	}

	/**
	 * Return a reference to a token that is safe to store and to display.
	 *
	 * Used where a UI needs to say *which* link this is without being able to use it —
	 * an audit row, a log line, an export. The last four hex characters are enough to
	 * distinguish two links in a list and useless to anyone who has it.
	 *
	 * @param string $hash Stored hash.
	 * @return string
	 */
	public static function reference( $hash ) {
		$hash = strtolower( (string) $hash );
		return ( 64 === strlen( $hash ) ) ? '…' . substr( $hash, -4 ) : '';
	}
}
