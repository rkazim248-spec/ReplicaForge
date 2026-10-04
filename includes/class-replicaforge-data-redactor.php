<?php
/**
 * Phase 7: the only place secrets are removed.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Removes secret-shaped text before it leaves the site.
 *
 * Anything that goes to an AI provider, into a log, or into a diagnostic export
 * passes through here first. Centralizing it means a new secret shape is handled
 * once rather than in five call sites, and it makes the redaction testable in one
 * place instead of per integration.
 */
final class Data_Redactor {

	/**
	 * Placeholder written in place of a removed value.
	 */
	const REDACTED = '[redacted]';

	/**
	 * Patterns replaced with the placeholder.
	 *
	 * Each is a `preg_replace` pattern. The order matters: the most specific shapes
	 * run first, because a broad pattern would otherwise consume part of a specific
	 * match and leave the rest behind.
	 *
	 * @var array<int, string>
	 */
	const PATTERNS = array(
		// Authorization and proxy headers, including bearer tokens.
		'/(authorization\s*[:=]\s*)(?:bearer|basic|token)?\s*\S+/i',
		'/(proxy-authorization\s*[:=]\s*)\S+/i',
		// Vendor API key shapes, longest and most specific first.
		'/\bsk-[A-Za-z0-9_-]{16,}\b/',
		'/\bsk-ant-[A-Za-z0-9_-]{16,}\b/',
		'/\bAIza[0-9A-Za-z_-]{20,}\b/',
		'/\bghp_[0-9A-Za-z]{20,}\b/',
		'/\bgithub_pat_[0-9A-Za-z_]{20,}\b/',
		'/\bxox[baprs]-[0-9A-Za-z-]{10,}\b/',
		'/\bAKIA[0-9A-Z]{16}\b/',
		'/\bASIA[0-9A-Z]{16}\b/',
		'/\bglpat-[0-9A-Za-z_-]{16,}\b/',
		// Generic `key = value` and `key: value` assignments for secret names.
		'/\b((?:api[_-]?key|apikey|secret|secret[_-]?key|access[_-]?token|auth[_-]?token|refresh[_-]?token|client[_-]?secret|password|passwd|pwd|session[_-]?id|private[_-]?key)\s*[:=]\s*)(["\']?)([^\s"\'&,;]{4,})\2/i',
		// Cookie and session material. A WordPress cookie name carries a hash
		// suffix, as in `wordpress_logged_in_abc123=`, so the name is matched with an
		// optional trailing token. The short `sid` alternative is deliberately not
		// used: it appears inside ordinary words, and a redaction pattern that fires
		// on `considered=` would quietly corrupt unrelated text.
		'/\b(?:wordpress_logged_in|wordpress_sec|wp-settings|wp_lang|phpunit|sessionid|jsessionid)(?:_[A-Za-z0-9]+)?\s*=\s*[^\s;]+/i',
		'/\b(set-cookie|cookie)\s*[:=]\s*[^\r\n]+/i',
		// Credentials embedded in a URL.
		'/\b([a-z][a-z0-9+.-]*:\/\/)[^\/\s:@]+:[^\/\s@]+@/i',
		// Private key blocks.
		'/-----BEGIN[^-]{0,40}PRIVATE KEY-----.*?-----END[^-]{0,40}PRIVATE KEY-----/s',
		// Filesystem paths that reveal the server layout.
		'/(?:^|[\s\'"=(])(?:\/(?:home|var|www|usr|srv|opt|etc|tmp|Users|Applications)\/[A-Za-z0-9._\/-]{2,})/',
		// A database DSN with credentials.
		'/\b(mysql|mariadb|pgsql|postgres|sqlite):\/\/[^\s]+/i',
	);

	/**
	 * Replacement patterns for values that are not secret but should not be sent.
	 *
	 * @var array<int, string>
	 */
	const PRIVATE_PATTERNS = array(
		// Private and link-local addresses, including the cloud metadata endpoint.
		'/\b(?:10\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/',
		'/\b(?:172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})\b/',
		'/\b(?:192\.168\.\d{1,3}\.\d{1,3})\b/',
		'/\b169\.254\.\d{1,3}\.\d{1,3}\b/',
		'/\b127\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',
		'/\b0\.0\.0\.0\b/',
		'/\b(?:\[?::1\]?|\b fc00::[0-9a-f:]+\b|\bfd[0-9a-f]{2}:[0-9a-f:]+)/i',
		// Loopback and container hostnames.
		'/\b(?:localhost|127\.0\.0\.1|host\.docker\.internal)\b/i',
		'/\b[a-z0-9-]+\.internal\b/i',
		'/\bmetadata\.google\.internal\b/i',
	);

	/**
	 * Redact secrets from a string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function secrets( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = (string) $value;
		if ( '' === $text ) {
			return '';
		}
		foreach ( self::PATTERNS as $pattern ) {
			$replaced = preg_replace( $pattern, self::replacement_for( $pattern ), $text );
			if ( is_string( $replaced ) ) {
				$text = $replaced;
			}
		}
		return $text;
	}

	/**
	 * Redact private network detail from a string.
	 *
	 * Private addresses are not secrets, but they describe the internal network and
	 * are never useful to a third party, so they are not transmitted either.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function private_network( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = (string) $value;
		foreach ( self::PRIVATE_PATTERNS as $pattern ) {
			$replaced = preg_replace( $pattern, self::REDACTED, $text );
			if ( is_string( $replaced ) ) {
				$text = $replaced;
			}
		}
		return $text;
	}

	/**
	 * Redact secrets, private network detail, and executable markup.
	 *
	 * The output of this function is written to the log, sent to an AI provider,
	 * and included in a diagnostic export. Markup that survived redaction would be
	 * a stored-injection vector in every one of those places, so it is removed here
	 * rather than relying on each consumer remembering to escape. Output escaping
	 * is still applied; this is the second of two independent measures.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function text( $value ) {
		return self::neutralize_markup( self::private_network( self::secrets( $value ) ) );
	}

	/**
	 * Remove markup and executable URL schemes from a string.
	 *
	 * @param string $text Redacted text.
	 * @return string
	 */
	public static function neutralize_markup( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		// Control characters go first, so a tag split by a null byte cannot
		// reassemble into markup after the tag patterns have run.
		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		$text     = is_string( $stripped ) ? $stripped : '';

		// Script and style bodies are removed with their content, because a body is
		// code even once its tags are gone.
		$text = self::replace( $text, '#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is' );
		$text = self::replace( $text, '#<\s*style\b[^>]*>.*?<\s*/\s*style\s*>#is' );
		$text = self::replace( $text, '#<\s*(?:iframe|object|embed|applet|frame|frameset)\b[^>]*>.*?<\s*/\s*[a-z]+\s*>#is' );

		// Every remaining tag is removed rather than escaped: this is data, not
		// markup, and keeping a tag around only risks someone rendering it later.
		$text = self::replace( $text, '#<\s*/?\s*[a-z][^>]*>#is' );

		// Executable URL schemes, in case one survived as bare text.
		$text = self::replace( $text, '#\b(?:javascript|vbscript|data|file|about|blob)\s*:#i', 'blocked:' );
		$text = self::replace( $text, '/\bexpression\s*\(/i', 'blocked(' );

		$collapsed = preg_replace( '/\s+/', ' ', $text );

		return is_string( $collapsed ) ? trim( $collapsed ) : '';
	}

	/**
	 * Apply a replacement, treating a pattern failure as a hard stop.
	 *
	 * A preg_replace that returns null means the pattern or the subject was
	 * rejected, usually a backtrack limit on hostile input. Returning the subject
	 * unchanged in that case would silently pass unfiltered text downstream, so
	 * the value is reduced to a placeholder instead.
	 *
	 * @param string $text    Subject.
	 * @param string $pattern Pattern.
	 * @return string
	 */
	private static function replace( $text, $pattern ) {
		$result = preg_replace( $pattern, ' ', $text );
		return is_string( $result ) ? $result : '';
	}

	/**
	 * Redact an arbitrary structure, recursively.
	 *
	 * Keys are inspected too, because a value under a key named `api_key` is a
	 * secret whatever it contains. Depth and breadth are bounded so a hostile
	 * structure cannot turn redaction into a denial of service.
	 *
	 * @param mixed  $value  Raw value.
	 * @param int    $depth  Current depth.
	 * @param int    $budget Remaining node budget.
	 * @return mixed
	 */
	public static function structure( $value, $depth = 0, &$budget = null ) {
		if ( null === $budget ) {
			$budget = 2000;
		}
		if ( $budget <= 0 || $depth > 8 ) {
			return self::REDACTED;
		}
		$budget--;

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::structure( $item, $depth + 1, $budget );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			// Objects are never transmitted; only their scalar description is safe
			// and useful, and a serialized object could carry anything at all.
			return get_class( $value );
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		return self::text( $value );
	}

	/**
	 * Redact a structure and additionally drop values under secret-looking keys.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function payload( $value ) {
		return self::structure( self::drop_secret_keys( $value ) );
	}

	/**
	 * Return a copy of a structure with secret-looking keys removed entirely.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $depth Current depth.
	 * @return mixed
	 */
	private static function drop_secret_keys( $value, $depth = 0 ) {
		if ( $depth > 8 || ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && self::is_secret_key( $key ) ) {
				$out[ $key ] = self::REDACTED;
				continue;
			}
			$out[ $key ] = is_array( $item ) ? self::drop_secret_keys( $item, $depth + 1 ) : $item;
		}
		return $out;
	}

	/**
	 * Return whether a key name looks like it holds a secret.
	 *
	 * @param string $key Key name.
	 * @return bool
	 */
	public static function is_secret_key( $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			return false;
		}
		$key = strtolower( $key );
		foreach ( array( 'api_key', 'apikey', 'secret', 'password', 'passwd', 'pwd', 'token', 'authorization', 'credential', 'private_key', 'client_secret', 'access_key', 'session_id' ) as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return whether a value looks like it contains a secret.
	 *
	 * Used as a belt-and-braces check on AI output: if a model echoes a
	 * credential-looking string, the response is discarded rather than stored.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function contains_secret( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		if ( self::secrets( $value ) !== $value ) {
			return true;
		}
		return false;
	}

	/**
	 * Return the replacement used for one pattern.
	 *
	 * A capture-group pattern keeps its prefix and replaces only the value, so a
	 * log line still shows which header or field was involved.
	 *
	 * @param string $pattern Pattern.
	 * @return string
	 */
	private static function replacement_for( $pattern ) {
		if ( 1 === preg_match( '/^.*\(\?P<[^>]+>|\(.*\+\)/', $pattern ) ) {
			return '$1' . self::REDACTED;
		}
		// Patterns whose first group is the field name keep the field name.
		if ( 0 === strpos( $pattern, '/\b((?:api' ) || 0 === strpos( $pattern, '/((?:authorization' ) || 0 === strpos( $pattern, '/(authorization' ) ) {
			return '$1' . self::REDACTED;
		}
		if ( 0 === strpos( $pattern, '/((?:proxy' ) || 0 === strpos( $pattern, '/(proxy' ) ) {
			return '$1' . self::REDACTED;
		}
		if ( 0 === strpos( $pattern, '/((?:wordpress_logged' ) ) {
			return '$1' . self::REDACTED;
		}
		if ( 0 === strpos( $pattern, '/\b([a-z][a-z0-9+.-]*:\/\/)' ) ) {
			return '$1' . self::REDACTED . '@';
		}
		if ( 0 === strpos( $pattern, '/((?:^|[\s\'"=(])' ) ) {
			return self::REDACTED;
		}
		return self::REDACTED;
	}
}
