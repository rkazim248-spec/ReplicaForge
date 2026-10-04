<?php
/**
 * Phase 20: a bounded fixed-window request counter.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds how often one caller may make a request in a window.
 *
 * ### This is the plugin's first rate limiter, and that is a real limitation
 *
 * An audit of Phases 1–19 found no rate limiter, no throttle and no bucket anywhere in the
 * plugin. Everything rate-shaped before Phase 20 is Phase 10's *plan meter*, which answers a
 * different question: "has this account used its monthly allowance?" rather than "is this one
 * caller sending 40,000 requests a minute?" A plan limit of 500 analyses a month does not
 * stop a loop hammering a read endpoint 500 times a second, because reads are not metered.
 *
 * So this exists, and it is deliberately *not* a second quota system. It has no allowance, no
 * period, no plan, no upgrade message and no persistence beyond the window. Everything about
 * "how much may this plan do" stays in {@see Entitlement_Manager}; everything about "how fast
 * may this caller go" is here.
 *
 * ### Why a fixed window and not a sliding log
 *
 * A sliding-window log is more accurate at the boundary — it prevents the classic 2× burst
 * across a window edge — and it costs one row per request. A fixed window is two integers and
 * one option read-modify-write, and it can burst at the edge.
 *
 * The edge burst is accepted deliberately: it is a factor of two on a value that is itself a
 * backstop, and the accurate alternative is a database write per request, which is a worse
 * trade than the accuracy is worth. `Api_Authenticator` also charges the plan meter, so a
 * legitimate integration is refused for being *expensive* long before it is refused for being
 * *fast*.
 *
 * ### The write cost, stated plainly
 *
 * Each allowed request is one `wp_options` write. On a host with no persistent object cache
 * that is a database round trip per API call. At
 * {@see Platform_Limits::RATE_LIMIT_PER_MINUTE} = 120 per credential that is 120 writes a
 * minute per credential, which is acceptable for a handful of integrations and is *not*
 * acceptable for thousands. This is the reason {@see self::buckets()} is a small fixed map
 * and the reason the phase report lists per-install scaling as a known limitation rather than
 * claiming the design handles it.
 *
 * ### Keying
 *
 * The key is supplied by the caller — `Api_Authenticator` uses `cred:<id>`, `user:<id>` or a
 * hashed address. Buckets are keyed inside one option, so two integrations cannot deny each
 * other service by consuming a shared counter.
 */
final class Rate_Limiter {

	/**
	 * Option holding every live bucket.
	 *
	 * @var string
	 */
	const OPTION = 'replicaforge_rate_buckets';

	/**
	 * Window length, in seconds.
	 *
	 * @var int
	 */
	const WINDOW = 60;

	/**
	 * Buckets retained at once.
	 *
	 * Bounded so the option cannot grow with the number of callers over a busy day. A bucket
	 * is at most two integers, so this is a few kilobytes at worst.
	 *
	 * @var int
	 */
	const MAX_BUCKETS = 500;

	/**
	 * Logger.
	 *
	 * @var Logger|null
	 */
	private $logger;

	/**
	 * Buckets loaded this request.
	 *
	 * @var array<string, array{window: int, count: int}>|null
	 */
	private $buckets = null;

	/**
	 * Buckets written this request.
	 *
	 * @var bool
	 */
	private $dirty = false;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Return whether a caller may make a request, and count it.
	 *
	 * @param string $key    Caller key.
	 * @param int    $limit  Requests allowed per window.
	 * @return array{allowed: bool, remaining: int, limit: int, retry_after: int, window_start: int}
	 */
	public function check( $key, $limit ) {
		$key   = substr( (string) preg_replace( '/[^A-Za-z0-9:_-]/', '', (string) $key ), 0, 80 );
		$limit = max( 1, (int) $limit );

		if ( '' === $key ) {
			/*
			 * An empty key is refused rather than allowed. It should be unreachable — the
			 * authenticator always builds one — and an unlimited bucket under an empty key
			 * would be an open door that only shows up in production.
			 */
			return array(
				'allowed'      => false,
				'remaining'    => 0,
				'limit'        => $limit,
				'retry_after'  => self::WINDOW,
				'window_start' => time(),
			);
		}

		$now      = time();
		$buckets  = $this->buckets();
		$window   = $now - ( $now % self::WINDOW );
		$bucket   = $buckets[ $key ] ?? array( 'window' => $window, 'count' => 0 );

		if ( (int) $bucket['window'] !== $window ) {
			/* A new window. Count restarts, and the previous count is simply gone. */
			$bucket = array( 'window' => $window, 'count' => 0 );
		}

		$count = (int) $bucket['count'];

		if ( $count >= $limit ) {
			$buckets[ $key ] = $bucket;

			$this->buckets = $buckets;
			$this->dirty   = true;

			return array(
				'allowed'      => false,
				'remaining'    => 0,
				'limit'        => $limit,
				/* Seconds until the window rolls over, so a client can back off precisely
				 * rather than guessing at a minute. */
				'retry_after'  => max( 1, ( $window + self::WINDOW ) - $now ),
				'window_start' => $window,
			);
		}

		$buckets[ $key ] = array( 'window' => $window, 'count' => $count + 1 );

		$this->buckets = $buckets;
		$this->dirty   = true;

		return array(
			'allowed'      => true,
			'remaining'    => max( 0, $limit - ( $count + 1 ) ),
			'limit'        => $limit,
			'retry_after'  => 0,
			'window_start' => $window,
		);
	}

	/**
	 * Return whether a caller may proceed, without counting.
	 *
	 * For a screen that needs to know whether to offer an action, the way
	 * `Entitlement_Manager::check()` is the non-consuming variant of `begin()`.
	 *
	 * @param string $key   Caller key.
	 * @param int    $limit Requests allowed per window.
	 * @return array<string, mixed>
	 */
	public function peek( $key, $limit ) {
		$key = substr( (string) preg_replace( '/[^A-Za-z0-9:_-]/', '', (string) $key ), 0, 80 );

		if ( '' === $key ) {
			return array( 'allowed' => false, 'remaining' => 0, 'limit' => (int) $limit, 'retry_after' => self::WINDOW, 'window_start' => time() );
		}

		$now     = time();
		$window  = $now - ( $now % self::WINDOW );
		$buckets = $this->buckets();
		$count   = (int) ( $buckets[ $key ]['count'] ?? 0 );

		if ( (int) ( $buckets[ $key ]['window'] ?? 0 ) !== $window ) {
			$count = 0;
		}

		return array(
			'allowed'      => $count < max( 1, (int) $limit ),
			'remaining'    => max( 0, (int) $limit - $count ),
			'limit'        => max( 1, (int) $limit ),
			'retry_after'  => 0,
			'window_start' => $window,
		);
	}

	/**
	 * Reset one caller's bucket.
	 *
	 * @param string $key Caller key.
	 * @return bool
	 */
	public function forget( $key ) {
		$key = substr( (string) preg_replace( '/[^A-Za-z0-9:_-]/', '', (string) $key ), 0, 80 );

		if ( '' === $key || null === $this->buckets ) {
			return false;
		}

		if ( ! isset( $this->buckets[ $key ] ) ) {
			return false;
		}

		unset( $this->buckets[ $key ] );

		$this->dirty = true;

		return true;
	}

	/**
	 * Remove every bucket from a window that has passed.
	 *
	 * Called from the daily maintenance tick. Without it the option keeps one entry per
	 * caller ever seen, which is exactly the growth {@see self::MAX_BUCKETS} exists to cap —
	 * but capping it silently would let a busy integration be locked out by callers that
	 * made one request a month ago.
	 *
	 * @return int Buckets removed.
	 */
	public function prune() {
		$this->buckets = array();

		$before   = (int) ( get_option( self::OPTION, array() ) ? count( (array) get_option( self::OPTION, array() ) ) : 0 );
		$removed  = $this->forget_all();
		$this->dirty = false;

		$this->logger->info(
			'rate_buckets_pruned',
			'Cleared the API rate limit buckets.',
			array( 'removed' => $removed, 'previous' => $before ),
			'platform'
		);

		return $removed;
	}

	/**
	 * Return a summary for the console.
	 *
	 * @return array<string, mixed>
	 */
	public function report() {
		$buckets = $this->buckets();

		return array(
			'active'      => count( $buckets ),
			'max_buckets' => self::MAX_BUCKETS,
			'window'      => self::WINDOW,
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Load the buckets once per request.
	 *
	 * @return array<string, array{window: int, count: int}>
	 */
	private function buckets() {
		if ( null !== $this->buckets ) {
			return $this->buckets;
		}

		$stored = get_option( self::OPTION, array() );
		$out    = array();

		if ( ! is_array( $stored ) ) {
			$this->buckets = array();

			return $this->buckets;
		}

		foreach ( $stored as $key => $bucket ) {
			if ( ! is_string( $key ) || ! is_array( $bucket ) ) {
				continue;
			}

			$out[ substr( $key, 0, 80 ) ] = array(
				'window' => (int) ( $bucket['window'] ?? 0 ),
				'count'  => (int) ( $bucket['count'] ?? 0 ),
			);
		}

		$this->buckets = $out;

		return $this->buckets;
	}

	/**
	 * Clear every bucket in storage.
	 *
	 * @return int
	 */
	private function forget_all() {
		$stored = get_option( self::OPTION, array() );
		$count  = is_array( $stored ) ? count( $stored ) : 0;

		update_option( self::OPTION, array(), false );

		$this->buckets = array();

		return $count;
	}

	/**
	 * Persist the buckets if anything changed.
	 *
	 * Registered on `shutdown` rather than written inline, so a request that makes several
	 * scoped calls — which every Phase 20 route with two scopes does — writes once rather
	 * than twice.
	 *
	 * @return void
	 */
	public function flush() {
		if ( ! $this->dirty || null === $this->buckets ) {
			return;
		}

		$buckets = $this->buckets;

		if ( count( $buckets ) > self::MAX_BUCKETS ) {
			/* Keep the fullest buckets: those are the active callers, and dropping one
			 * because another caller made a single request an hour ago would let an
			 * attacker evade the limit by spraying requests across keys. */
			uasort(
				$buckets,
				static function ( $left, $right ) {
					return (int) ( $right['count'] ?? 0 ) <=> (int) ( $left['count'] ?? 0 );
				}
			);

			$buckets = array_slice( $buckets, 0, self::MAX_BUCKETS, true );
		}

		update_option( self::OPTION, $buckets, false );

		$this->dirty = false;
	}
}
