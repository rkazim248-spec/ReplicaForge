<?php
/**
 * Phase 7: request and job correlation.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Carries one correlation id across a REST call, its job, and every log line.
 *
 * Without this, supporting a user means matching timestamps by hand. With it, one
 * identifier ties the HTTP request to the job it started, the generation it
 * produced, the validation that ran against it, and the log entries in between.
 */
final class Request_Context {

	/**
	 * The id for the current request.
	 *
	 * @var string
	 */
	private static $request_id = '';

	/**
	 * Correlation ids for the current request, keyed by role.
	 *
	 * @var array<string, string>
	 */
	private static $ids = array();

	/**
	 * Return the current request id, generating one on first use.
	 *
	 * @return string
	 */
	public static function request_id() {
		if ( '' === self::$request_id ) {
			self::$request_id = 'req_' . self::token( 16 );
		}
		return self::$request_id;
	}

	/**
	 * Record a correlation id under a role.
	 *
	 * Roles are `job`, `generation`, `validation`, `correction`, and `snapshot`.
	 *
	 * @param string $role Role name.
	 * @param string $id   Identifier.
	 * @return void
	 */
	public static function set( $role, $id ) {
		$role = self::token_from_role( $role );
		$id   = is_string( $id ) ? $id : '';
		if ( '' === $role || '' === $id ) {
			return;
		}
		self::$ids[ $role ] = $id;
	}

	/**
	 * Return a recorded id, or an empty string.
	 *
	 * @param string $role Role name.
	 * @return string
	 */
	public static function get( $role ) {
		$role = self::token_from_role( $role );
		return isset( self::$ids[ $role ] ) ? (string) self::$ids[ $role ] : '';
	}

	/**
	 * Return every recorded correlation id.
	 *
	 * @return array<string, string>
	 */
	public static function all() {
		return self::$ids;
	}

	/**
	 * Return the context for a log entry or a response `meta` block.
	 *
	 * @return array<string, string>
	 */
	public static function meta() {
		$meta = array( 'request_id' => self::request_id() );
		foreach ( self::$ids as $role => $id ) {
			$meta[ $role . '_id' ] = $id;
		}
		return $meta;
	}

	/**
	 * Reset the context. Used by long-running workers between jobs and by tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$request_id = '';
		self::$ids       = array();
	}

	/**
	 * Generate an identifier with a given prefix.
	 *
	 * @param string $prefix Identifier prefix.
	 * @param int    $length Number of random bytes.
	 * @return string
	 */
	public static function make_id( $prefix, $length = 12 ) {
		return self::token_from_role( $prefix ) . '_' . self::token( $length );
	}

	/**
	 * Normalize a role or prefix into a safe lowercase token.
	 *
	 * @param string $role Raw role.
	 * @return string
	 */
	private static function token_from_role( $role ) {
		if ( ! is_string( $role ) || '' === $role ) {
			return '';
		}
		$role = strtolower( trim( $role ) );
		$role = preg_replace( '/[^a-z0-9_]/', '', $role );
		return is_string( $role ) ? trim( $role, '_' ) : '';
	}

	/**
	 * Return a random lowercase hex token.
	 *
	 * @param int $bytes Number of bytes.
	 * @return string
	 */
	private static function token( $bytes ) {
		$bytes = max( 4, min( 32, (int) $bytes ) );
		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( \Throwable $exception ) {
			// A failure here must not take the request down; a weaker token is still
			// far better than a predictable one being impossible to produce at all.
			return substr( hash( 'sha256', uniqid( (string) wp_rand(), true ) ), 0, $bytes * 2 );
		}
	}
}
