<?php
/**
 * Phase 10: a license state.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable answer from a licensing provider.
 *
 * The state is the only thing a provider may assert. It never carries a plan id,
 * because "the provider said the license is active" and "the user is on the Pro
 * plan" are different claims and only the first one is a provider's to make. The
 * plan follows from the state plus a locally configured mapping, which means a
 * compromised or confused provider can hand out a state and nothing more.
 *
 * `unknown` is a first-class state rather than an error, and it is the state a
 * provider returns when it cannot answer. It is deliberately **not** treated as
 * permissive. An unknown license falls back to the installation's configured
 * default plan, and that default is `free` unless an administrator changed it.
 */
final class License_State {

	/**
	 * No license has been presented.
	 */
	const INACTIVE = 'inactive';

	/**
	 * A trial is running.
	 */
	const TRIAL = 'trial';

	/**
	 * A paid license is valid.
	 */
	const ACTIVE = 'active';

	/**
	 * A paid license has run out.
	 */
	const EXPIRED = 'expired';

	/**
	 * An expired license is inside a read-only grace window.
	 */
	const GRACE_PERIOD = 'grace_period';

	/**
	 * The license was rejected.
	 */
	const INVALID = 'invalid';

	/**
	 * The license was withdrawn by the issuer.
	 */
	const REVOKED = 'revoked';

	/**
	 * The provider could not answer.
	 */
	const UNKNOWN = 'unknown';

	/**
	 * Every state.
	 *
	 * @var array<int, string>
	 */
	const STATES = array(
		self::INACTIVE,
		self::TRIAL,
		self::ACTIVE,
		self::EXPIRED,
		self::GRACE_PERIOD,
		self::INVALID,
		self::REVOKED,
		self::UNKNOWN,
	);

	/**
	 * States that grant the plan's entitlements.
	 *
	 * `grace_period` is included because a grace window exists precisely so that a
	 * site does not stop generating the moment a payment bounces — the whole point
	 * is that the user is given time to fix it. `expired` is not, because an
	 * expired license is a completed failure and treating it like a grace period
	 * would make the grace period meaningless.
	 *
	 * @var array<int, string>
	 */
	const GRANTING = array( self::TRIAL, self::ACTIVE, self::GRACE_PERIOD );

	/**
	 * The state name.
	 *
	 * @var string
	 */
	private $state;

	/**
	 * Short machine reason, for the audit log and diagnostics.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Moment the state stops being true, or 0.
	 *
	 * @var int
	 */
	private $expires_at;

	/**
	 * Provider-supplied reference. Never a secret.
	 *
	 * @var string
	 */
	private $reference;

	/**
	 * When the answer was produced.
	 *
	 * @var int
	 */
	private $checked_at;

	/**
	 * Constructor.
	 *
	 * @param string $state       State name.
	 * @param string $reason      Short machine reason.
	 * @param int    $expires_at  Expiry timestamp, or 0.
	 * @param string $reference   Provider reference.
	 * @param int    $checked_at  When the answer was produced.
	 */
	private function __construct( $state, $reason, $expires_at, $reference, $checked_at ) {
		$this->state      = self::clean_state( $state );
		$this->reason     = is_scalar( $reason ) ? substr( sanitize_key( (string) $reason ), 0, 60 ) : '';
		$this->expires_at = max( 0, (int) $expires_at );
		$this->reference  = is_scalar( $reference ) ? substr( sanitize_text_field( (string) $reference ), 0, 80 ) : '';
		$this->checked_at = max( 0, (int) $checked_at );
	}

	/**
	 * Build a state.
	 *
	 * An unrecognised state name becomes `unknown` rather than being kept. Keeping
	 * it would mean a provider typo silently producing a state that grants nothing
	 * and appears in no list, and the resulting "why is my license not working"
	 * support question has no answer.
	 *
	 * @param string $state      State name.
	 * @param string $reason     Short machine reason.
	 * @param int    $expires_at Expiry timestamp.
	 * @param string $reference  Provider reference.
	 * @return self
	 */
	public static function make( $state, $reason = '', $expires_at = 0, $reference = '' ) {
		return new self( $state, $reason, $expires_at, $reference, time() );
	}

	/**
	 * Return the `unknown` state.
	 *
	 * @param string $reason Short machine reason.
	 * @return self
	 */
	public static function unknown( $reason = 'provider_unavailable' ) {
		return new self( self::UNKNOWN, $reason, 0, '', time() );
	}

	/**
	 * Return the state name.
	 *
	 * @return string
	 */
	public function name() {
		return $this->state;
	}

	/**
	 * Return the short machine reason.
	 *
	 * @return string
	 */
	public function reason() {
		return $this->reason;
	}

	/**
	 * Return the expiry timestamp, or 0.
	 *
	 * @return int
	 */
	public function expires_at() {
		return $this->expires_at;
	}

	/**
	 * Return the provider reference.
	 *
	 * @return string
	 */
	public function reference() {
		return $this->reference;
	}

	/**
	 * Return when the answer was produced.
	 *
	 * @return int
	 */
	public function checked_at() {
		return $this->checked_at;
	}

	/**
	 * Return whether the state grants entitlements.
	 *
	 * Time-aware. This is not decoration: a license that expired an hour ago must
	 * not read as granting anything, and the alternative — a `grants()` that answers
	 * from the state as declared rather than as of now — is a method that returns
	 * `true` for an expired license, which is the most dangerous single method in
	 * this file. {@see self::grants_at()} is the same check with the moment stated
	 * explicitly; this one uses the current time.
	 *
	 * @return bool
	 */
	public function grants() {
		return $this->grants_at();
	}

	/**
	 * Return whether the state is a failure a user must be told about.
	 *
	 * `inactive` and `unknown` are not failures: nobody bought anything, and an
	 * unreachable provider is the provider's problem. `expired`, `invalid`, and
	 * `revoked` are statements about a license that existed.
	 *
	 * @return bool
	 */
	public function is_problem() {
		return in_array( $this->state, array( self::EXPIRED, self::INVALID, self::REVOKED ), true );
	}

	/**
	 * Return whether the state is a temporary condition.
	 *
	 * @return bool
	 */
	public function is_temporary() {
		return in_array( $this->state, array( self::GRACE_PERIOD, self::UNKNOWN ), true );
	}

	/**
	 * Return whether the state is still valid at a moment.
	 *
	 * An expiry in the past demotes `active` and `trial` to `expired`, and demotes
	 * `grace_period` to `expired`. This is why the demotion happens in a method
	 * rather than in a setter: a state built a second ago can become false without
	 * anything changing it, and code that checked at construction would keep
	 * honouring a license that lapsed while the page sat open.
	 *
	 * @param int|null $timestamp Optional moment.
	 * @return string
	 */
	public function effective_name( $timestamp = null ) {
		$now = ( null === $timestamp ) ? time() : (int) $timestamp;
		if ( $this->expires_at > 0 && $this->expires_at <= $now ) {
			return self::EXPIRED;
		}
		return $this->state;
	}

	/**
	 * Return whether the state grants entitlements at a moment.
	 *
	 * @param int|null $timestamp Optional moment.
	 * @return bool
	 */
	public function grants_at( $timestamp = null ) {
		return in_array( $this->effective_name( $timestamp ), self::GRANTING, true );
	}
	/**
	 * Return the state as a plain array, safe to send to a browser.
	 *
	 * @param int|null $timestamp Optional moment.
	 * @return array<string, mixed>
	 */
	public function to_array( $timestamp = null ) {
		$effective = $this->effective_name( $timestamp );

		return array(
			'state'          => $effective,
			'declared_state' => $this->state,
			'reason'         => $this->reason,
			'grants'         => in_array( $effective, self::GRANTING, true ),
			'is_problem'     => in_array( $effective, array( self::EXPIRED, self::INVALID, self::REVOKED ), true ),
			'expires_at'    => $this->expires_at,
			'checked_at'     => $this->checked_at,
		);
	}

	/**
	 * Normalise a state name.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private static function clean_state( $value ) {
		if ( ! is_scalar( $value ) ) {
			return self::UNKNOWN;
		}
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, self::STATES, true ) ? $value : self::UNKNOWN;
	}
}
