<?php
/**
 * Phase 11: AI failure classification.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Turns whatever an AI provider said into a decision.
 *
 * `Error_Catalog` already has the codes and already says which are retryable. What
 * it does not carry is the three things a scheduler needs:
 *
 * - **`retry_after`.** §21 is explicit: when a provider reports a delay, the job
 *   must wait exactly that long. Retrying on our own backoff instead means either
 *   hammering a provider that just asked us to stop, or waiting far longer than we
 *   had to.
 * - **Provider classification.** A 401, a 429, a 500, and a connection reset reach
 *   us as an HTTP status and a body, and each means something different. Deciding
 *   "is this retryable" from the message text is how a plugin ends up retrying an
 *   invalid API key three times on every single request.
 * - **Which failures are worth a fallback provider.** §39 forbids a silent switch,
 *   so the decision has to be explicit and visible.
 *
 * Three message layers, and the separation is the point:
 *
 * - `user_message` — safe to show a person. Never contains a provider name, an
 *   endpoint, a model id, a key fragment, or a raw body.
 * - `technical_message` — for the log. May name the provider and the status.
 * - `retryable` — whether retrying the *same* provider can plausibly succeed.
 *
 * The security property to hold onto: **nothing here ever puts a secret in an
 * exception message.** Provider response bodies are truncated, then scanned for
 * key-shaped substrings, then dropped entirely if one is found. §19 says "never
 * expose provider secrets" and the way to guarantee that is to not compose the
 * message from the untrusted body at all — so the body is used only to *choose a
 * code*, never to build a sentence.
 */
final class Ai_Failures {

	/**
	 * The classified failure codes.
	 *
	 * @var array<int, string>
	 */
	const CODES = array(
		'AI_AUTH_ERROR',
		'AI_RATE_LIMIT',
		'AI_TIMEOUT',
		'AI_UNAVAILABLE',
		'AI_INVALID_RESPONSE',
		'AI_SCHEMA_ERROR',
		'AI_CONTEXT_TOO_LARGE',
		'AI_QUOTA_EXCEEDED',
		'AI_PROVIDER_ERROR',
		'AI_CONFIGURATION_ERROR',
		'AI_NETWORK_BLOCKED',
		'AI_CANCELLED',
	);

	/**
	 * Whether each code is worth retrying against the same provider.
	 *
	 * This table is the whole basis of §20. A code that is absent is treated as
	 * not retryable, so a new code defaults to the safe answer.
	 *
	 * @var array<string, bool>
	 */
	const RETRYABLE = array(
		'AI_RATE_LIMIT'          => true,
		'AI_TIMEOUT'             => true,
		'AI_UNAVAILABLE'         => true,
		'AI_PROVIDER_ERROR'      => true,
		'AI_NETWORK_BLOCKED'     => true,
		// An auth error, an invalid response, a schema error, an oversized context
		// and a configuration error are all the same request failing the same way
		// again. Retrying any of them spends money and time to arrive at the same
		// answer.
		'AI_AUTH_ERROR'          => false,
		'AI_INVALID_RESPONSE'    => false,
		'AI_SCHEMA_ERROR'        => false,
		'AI_CONTEXT_TOO_LARGE'   => false,
		'AI_QUOTA_EXCEEDED'      => false,
		'AI_CONFIGURATION_ERROR' => false,
		'AI_CANCELLED'           => false,
	);

	/**
	 * Whether each code justifies trying a configured fallback provider.
	 *
	 * Only failures that are about *this provider right now* qualify. A schema
	 * error would be produced identically by the fallback, and an auth error means
	 * the site is misconfigured rather than that one endpoint is unwell.
	 *
	 * @var array<string, bool>
	 */
	const FALLBACK_WORTHY = array(
		'AI_RATE_LIMIT'      => true,
		'AI_TIMEOUT'         => true,
		'AI_UNAVAILABLE'     => true,
		'AI_PROVIDER_ERROR'  => true,
		'AI_NETWORK_BLOCKED' => true,
	);

	/**
	 * Return whether a code is declared.
	 *
	 * @param mixed $code Candidate.
	 * @return bool
	 */
	public static function is_code( $code ) {
		return is_string( $code ) && in_array( $code, self::CODES, true );
	}

	/**
	 * Return whether a code is retryable.
	 *
	 * @param mixed $code Candidate.
	 * @return bool
	 */
	public static function is_retryable( $code ) {
		if ( ! self::is_code( $code ) ) {
			return false;
		}
		return ! empty( self::RETRYABLE[ $code ] );
	}

	/**
	 * Return whether a code justifies a fallback provider.
	 *
	 * @param mixed $code Candidate.
	 * @return bool
	 */
	public static function fallback_worthy( $code ) {
		if ( ! self::is_code( $code ) ) {
			return false;
		}
		return ! empty( self::FALLBACK_WORTHY[ $code ] );
	}

	/**
	 * Classify a provider HTTP outcome.
	 *
	 * The status decides the code. The body is used only to distinguish a rate limit
	 * from an exhausted quota, and to extract a `retry_after`, because those are
	 * facts a scheduler needs and a sentence is not. The body never becomes a
	 * message.
	 *
	 * @param int                  $status      HTTP status, or 0 for a transport failure.
	 * @param string               $body        Response body, if any.
	 * @param array<string, mixed> $headers     Response headers, lowercased.
	 * @param string               $transport   Transport error message, if any.
	 * @param string               $provider_id Provider identifier.
	 * @return array<string, mixed>
	 */
	public static function from_http( $status, $body = '', array $headers = array(), $transport = '', $provider_id = '' ) {
		$status = (int) $status;
		$code   = 'AI_PROVIDER_ERROR';

		if ( $status >= 200 && $status < 300 ) {
			$code = 'AI_INVALID_RESPONSE';
		} elseif ( 401 === $status || 403 === $status ) {
			$code = 'AI_AUTH_ERROR';
		} elseif ( 429 === $status ) {
			// A 429 with a "quota" or "credit" in the body is an exhausted balance,
			// which waiting does not fix.
			$code = ( false !== stripos( (string) $body, 'quota' ) || false !== stripos( (string) $body, 'credit' ) || false !== stripos( (string) $body, 'billing' ) )
				? 'AI_QUOTA_EXCEEDED'
				: 'AI_RATE_LIMIT';
		} elseif ( 400 === $status || 422 === $status ) {
			$code = ( false !== stripos( (string) $body, 'context' ) || false !== stripos( (string) $body, 'token' ) )
				? 'AI_CONTEXT_TOO_LARGE'
				: 'AI_INVALID_RESPONSE';
		} elseif ( 404 === $status ) {
			$code = 'AI_CONFIGURATION_ERROR';
		} elseif ( 408 === $status ) {
			$code = 'AI_TIMEOUT';
		} elseif ( 413 === $status ) {
			$code = 'AI_CONTEXT_TOO_LARGE';
		} elseif ( $status >= 500 ) {
			$code = ( 503 === $status || 502 === $status ) ? 'AI_UNAVAILABLE' : 'AI_PROVIDER_ERROR';
		} elseif ( 0 === $status ) {
			$code = '' !== (string) $transport ? 'AI_NETWORK_BLOCKED' : 'AI_TIMEOUT';
		}

		return self::make(
			$code,
			array(
				'provider'    => (string) $provider_id,
				'http_status' => $status,
				'retry_after' => self::retry_after( $headers ),
				'transport'   => self::safe_fragment( (string) $transport ),
			)
		);
	}

	/**
	 * Classify a decoded AI response that did not survive validation.
	 *
	 * These are all failures of the *response*, not of the request, which is why
	 * none of them is retryable: sending the same request again produces the same
	 * bad response. The distinctions are kept because they are different problems
	 * for whoever has to fix them — a schema error is a prompt problem, an invalid
	 * response is a provider problem, and a reference error is a hallucination.
	 *
	 * @param string $reason One of: not_json, schema, empty, too_large, secret, references, evidence.
	 * @return array<string, mixed>
	 */
	public static function from_response( $reason ) {
		$map = array(
			'not_json'   => 'AI_INVALID_RESPONSE',
			'empty'      => 'AI_INVALID_RESPONSE',
			'secret'     => 'AI_INVALID_RESPONSE',
			'schema'     => 'AI_SCHEMA_ERROR',
			'references' => 'AI_SCHEMA_ERROR',
			'evidence'   => 'AI_SCHEMA_ERROR',
			// A response that parsed but came back too large is the provider's
			// *output* being unusable. That is a different problem from our request
			// being oversized, and the two deserve different codes even though both
			// are non-retryable.
			'too_large'  => 'AI_INVALID_RESPONSE',
		);

		$reason = (string) $reason;
		$code   = isset( $map[ $reason ] ) ? $map[ $reason ] : 'AI_INVALID_RESPONSE';

		return self::make( $code, array( 'reason' => self::safe_fragment( $reason ) ) );
	}

	/**
	 * Build a classified failure.
	 *
	 * @param string               $code    Failure code.
	 * @param array<string, mixed> $context Context, sanitised.
	 * @return array<string, mixed>
	 */
	public static function make( $code, array $context = array() ) {
		$code = self::is_code( $code ) ? $code : 'AI_PROVIDER_ERROR';
		$copy = self::copy( $code );

		$retry_after = isset( $context['retry_after'] ) ? (int) $context['retry_after'] : 0;
		if ( $retry_after < 0 ) {
			$retry_after = 0;
		}
		// A provider asking for longer than ten minutes is treated as not asking at
		// all, and the job falls back to its own backoff. Honouring a twenty-minute
		// wait from an untrusted header would let a provider park a job indefinitely.
		$honoured = ( $retry_after > 0 && $retry_after <= self::MAX_RETRY_AFTER ) ? $retry_after : 0;

		$provider = isset( $context['provider'] ) ? (string) $context['provider'] : '';

		return array(
			'code'               => $code,
			'retryable'         => self::is_retryable( $code ),
			'fallback_worthy'   => self::fallback_worthy( $code ),
			'user_message'      => (string) $copy['user'],
			'technical_message' => self::technical( $code, $provider, $context ),
			'retry_after'       => $honoured,
			'http_status'       => isset( $context['http_status'] ) ? (int) $context['http_status'] : 0,
			// The raw body is never carried. It is the one field a provider can put
			// anything at all into, including an echoed key.
			'provider'          => $provider,
		);
	}

	/**
	 * Return the user-facing and technical wording for a code.
	 *
	 * @param string $code Failure code.
	 * @return array{user: string, technical: string}
	 */
	private static function copy( $code ) {
		$map = array(
			'AI_AUTH_ERROR'          => array(
				'user'      => __( 'The AI provider rejected the API key. Check the key in ReplicaForge settings.', 'replicaforge' ),
				'technical' => 'The provider rejected the credentials.',
			),
			'AI_RATE_LIMIT'          => array(
				'user'      => __( 'The AI provider is rate limiting requests. This will be retried automatically.', 'replicaforge' ),
				'technical' => 'The provider rate limited the request.',
			),
			'AI_TIMEOUT'             => array(
				'user'      => __( 'The AI provider took too long to answer. This will be retried.', 'replicaforge' ),
				'technical' => 'The provider timed out.',
			),
			'AI_UNAVAILABLE'         => array(
				'user'      => __( 'The AI provider is temporarily unavailable. This will be retried.', 'replicaforge' ),
				'technical' => 'The provider reported itself unavailable.',
			),
			'AI_INVALID_RESPONSE'    => array(
				'user'      => __( 'The AI provider returned something ReplicaForge could not use. Nothing was written.', 'replicaforge' ),
				'technical' => 'The provider response could not be used.',
			),
			'AI_SCHEMA_ERROR'        => array(
				'user'      => __( 'The AI response did not match the required structure, so it was discarded rather than guessed at.', 'replicaforge' ),
				'technical' => 'The provider response failed schema validation.',
			),
			'AI_CONTEXT_TOO_LARGE'   => array(
				'user'      => __( 'The page is too large to send to the AI provider in one request. Reduce the number of sections analyzed, or use a model with a larger context.', 'replicaforge' ),
				'technical' => 'The request exceeded the provider context limit.',
			),
			'AI_QUOTA_EXCEEDED'      => array(
				'user'      => __( 'The AI provider account has no remaining allowance. Waiting will not help; the provider account needs attention.', 'replicaforge' ),
				'technical' => 'The provider reported an exhausted quota.',
			),
			'AI_PROVIDER_ERROR'      => array(
				'user'      => __( 'The AI provider reported an error. This will be retried.', 'replicaforge' ),
				'technical' => 'The provider returned an error status.',
			),
			'AI_CONFIGURATION_ERROR' => array(
				'user'      => __( 'The AI provider is not configured correctly. Check the provider and model in ReplicaForge settings.', 'replicaforge' ),
				'technical' => 'The provider configuration was rejected.',
			),
			'AI_NETWORK_BLOCKED'     => array(
				'user'      => __( 'ReplicaForge could not reach the AI provider. Check that the server allows outbound HTTPS.', 'replicaforge' ),
				'technical' => 'The request to the provider did not complete.',
			),
			'AI_CANCELLED'           => array(
				'user'      => __( 'The operation was cancelled.', 'replicaforge' ),
				'technical' => 'The operation was cancelled.',
			),
		);

		return isset( $map[ $code ] ) ? $map[ $code ] : array(
			'user'      => __( 'The AI request could not be completed.', 'replicaforge' ),
			'technical' => 'An unclassified AI failure occurred.',
		);
	}

	/**
	 * Build the technical message, from declared parts only.
	 *
	 * @param string               $code     Failure code.
	 * @param string               $provider Provider identifier.
	 * @param array<string, mixed> $context  Context.
	 * @return string
	 */
	private static function technical( $code, $provider, array $context ) {
		$copy    = self::copy( $code );
		$message = (string) $copy['technical'];

		if ( '' !== $provider ) {
			$message .= ' Provider=' . $provider . '.';
		}
		if ( ! empty( $context['http_status'] ) ) {
			$message .= ' Status=' . (int) $context['http_status'] . '.';
		}
		if ( ! empty( $context['retry_after'] ) ) {
			$message .= ' RetryAfter=' . (int) $context['retry_after'] . 's.';
		}
		if ( ! empty( $context['reason'] ) ) {
			$message .= ' Reason=' . (string) $context['reason'] . '.';
		}
		// A transport error is the one piece of provider text that is useful, and
		// only because it comes from PHP's socket layer rather than the provider's
		// body. It is truncated and stripped regardless.
		if ( ! empty( $context['transport'] ) ) {
			$message .= ' Transport=' . (string) $context['transport'] . '.';
		}

		return $message;
	}

	/**
	 * Return the longest retry delay that will be honoured, in seconds.
	 */
	const MAX_RETRY_AFTER = 600;

	/**
	 * Extract a retry delay from response headers.
	 *
	 * Both header names are checked because providers disagree: `Retry-After` is
	 * the standard and is either seconds or an HTTP date, while several use a
	 * millisecond `retryDelay` field in a JSON body. Only the headers are read
	 * here, and only as an integer.
	 *
	 * @param array<string, mixed> $headers Response headers, lowercased.
	 * @return int Seconds, or 0.
	 */
	public static function retry_after( array $headers ) {
		foreach ( array( 'retry-after', 'x-ratelimit-reset-after', 'x-ratelimit-reset' ) as $name ) {
			if ( ! isset( $headers[ $name ] ) ) {
				continue;
			}
			$value = $headers[ $name ];
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' === $value || ! is_numeric( $value ) ) {
				continue;
			}
			$seconds = (int) $value;
			// `x-ratelimit-reset` is often an epoch timestamp rather than a delay.
			// A value far larger than any plausible delay is treated as an epoch and
			// converted, and anything still implausible is discarded.
			if ( $seconds > 100000 ) {
				$seconds = $seconds - time();
			}
			if ( $seconds > 0 ) {
				return min( self::MAX_RETRY_AFTER * 2, $seconds );
			}
		}

		return 0;
	}

	/**
	 * Reduce an untrusted string to something safe to put in a log line.
	 *
	 * Stripped of anything key-shaped, truncated, and reduced to printable ASCII.
	 * A provider body can contain anything, including an echoed authorization
	 * header, and the technical message ends up in a log file.
	 *
	 * @param string $value Candidate.
	 * @return string
	 */
	private static function safe_fragment( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		// A key-shaped run is removed rather than shortened: shortening leaves most
		// of a secret intact, which is worse than leaving nothing.
		$value = (string) preg_replace( '/\b(sk|pk|key|token|bearer)[-_][A-Za-z0-9_\-]{8,}/i', '[redacted]', $value );
		$value = (string) preg_replace( '/[A-Za-z0-9_\-]{32,}/', '[redacted]', $value );
		$value = (string) preg_replace( '/[^A-Za-z0-9 ._\-:\/()\[\]]/', ' ', $value );
		$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );

		return substr( $value, 0, 120 );
	}

	/**
	 * Convert a classified failure into the shape the job queue expects.
	 *
	 * The queue's `fail()` takes a catalogue code. Mapping here means the AI layer
	 * does not have to know the catalogue's codes, and the mapping is in one place
	 * if the catalogue changes.
	 *
	 * @param array<string, mixed> $failure Classified failure.
	 * @return array<string, mixed>
	 */
	public static function to_queue_error( array $failure ) {
		$map = array(
			'AI_AUTH_ERROR'          => 'ai_not_configured',
			'AI_RATE_LIMIT'          => 'ai_rate_limited',
			'AI_TIMEOUT'             => 'ai_timeout',
			'AI_UNAVAILABLE'         => 'ai_provider_error',
			'AI_INVALID_RESPONSE'    => 'ai_invalid_response',
			'AI_SCHEMA_ERROR'        => 'ai_output_rejected',
			'AI_CONTEXT_TOO_LARGE'   => 'ai_invalid_response',
			'AI_QUOTA_EXCEEDED'      => 'ai_not_configured',
			'AI_PROVIDER_ERROR'      => 'ai_provider_error',
			'AI_CONFIGURATION_ERROR' => 'ai_not_configured',
			'AI_NETWORK_BLOCKED'     => 'ai_provider_error',
			'AI_CANCELLED'           => 'internal_error',
		);

		$code = isset( $failure['code'] ) ? (string) $failure['code'] : 'AI_PROVIDER_ERROR';

		return array(
			'code'    => isset( $map[ $code ] ) ? $map[ $code ] : 'ai_provider_error',
			'message' => (string) ( $failure['technical_message'] ?? '' ),
			// Carried through so a caller can decide without re-deriving it from the
			// catalogue. The catalogue's own flag is authoritative for the *queue*;
			// this one is the AI layer's own judgement about the provider response.
			'retryable'   => (bool) ( $failure['retryable'] ?? false ),
			'fallback_worthy' => (bool) ( $failure['fallback_worthy'] ?? false ),
			'retry_after' => (int) ( $failure['retry_after'] ?? 0 ),
			'failure' => $failure,
		);
	}

	/**
	 * Return the whole classification table, for diagnostics and tests.
	 *
	 * @return array<string, array{retryable: bool, fallback_worthy: bool, user: string}>
	 */
	public static function report() {
		$out = array();
		foreach ( self::CODES as $code ) {
			$copy         = self::copy( $code );
			$out[ $code ] = array(
				'retryable'       => self::is_retryable( $code ),
				'fallback_worthy' => self::fallback_worthy( $code ),
				'user'            => (string) $copy['user'],
			);
		}
		return $out;
	}
}
