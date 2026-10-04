<?php
/**
 * Phase 10: the local licensing provider.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The provider that ships with the plugin: no external service, no activation.
 *
 * This is what a development installation, a local build, or a self-hosted
 * commercial deployment with its own licensing arrangement all use. It reads an
 * administrator-set record from the database and turns it into a
 * {@see License_State}. It performs no network request, and there is no code in it
 * that could.
 *
 * What it is *not* is a fake activation flow. There is no key to enter, no
 * signature to verify against a bundled public key, and no "contact us" step. An
 * administrator either sets the plan for this site in settings — which is a local
 * configuration decision, not a purchase — or leaves it alone and the site runs on
 * the free plan.
 *
 * The state vocabulary is still the full one, because an installation that later
 * swaps in a remote provider gets the same downstream behaviour. `revoked` and
 * `invalid` are reachable here only if an administrator sets them, and the
 * system status screen says plainly that a locally-set state carries no
 * verification.
 */
final class Local_License_Provider implements License_Provider_Contract {

	/**
	 * Option holding the local licensing record.
	 */
	const OPTION = 'replicaforge_license_local';

	/**
	 * Return the provider identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'local_development';
	}

	/**
	 * Return the provider display name.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Local / development licensing', 'replicaforge' );
	}

	/**
	 * Return that the provider is local.
	 *
	 * @return bool
	 */
	public function is_remote() {
		return false;
	}

	/**
	 * Return the current state.
	 *
	 * @return License_State
	 */
	public function state() {
		$record = $this->record();

		if ( ! is_array( $record ) || array() === $record ) {
			// No record at all. This is the ordinary state of a fresh installation
			// and is deliberately `inactive` rather than `unknown`: nothing is
			// broken, nobody has claimed a license, and the plugin should be usable
			// on the default plan.
			return License_State::make( License_State::INACTIVE, 'not_configured' );
		}

		$state = isset( $record['state'] ) ? (string) $record['state'] : License_State::UNKNOWN;
		$state = strtolower( trim( $state ) );

		if ( ! in_array( $state, License_State::STATES, true ) ) {
			return License_State::unknown( 'invalid_record' );
		}

		$expires = isset( $record['expires_at'] ) && is_numeric( $record['expires_at'] )
			? (int) $record['expires_at']
			: 0;

		$reference = isset( $record['reference'] ) && is_scalar( $record['reference'] )
			? (string) $record['reference']
			: '';

		return License_State::make( $state, 'local_record', $expires, $reference );
	}

	/**
	 * Return the stored record.
	 *
	 * @return array<string, mixed>
	 */
	public function record() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Store a local licensing record.
	 *
	 * Only the four fields of a license state are accepted. A record is not a
	 * licence to store arbitrary data in an option, and a plan id is **not** among
	 * them: a local record may say "active", and what plan that means is decided by
	 * {@see License_Manager} against the local plan set. That keeps the same
	 * separation a remote provider has to respect.
	 *
	 * @param array<string, mixed> $record Record fields.
	 * @return array{success: bool, state: string, errors: array<int, string>}
	 */
	public function store( array $record ) {
		$errors = array();
		$state  = isset( $record['state'] ) && is_scalar( $record['state'] )
			? strtolower( trim( (string) $record['state'] ) )
			: '';

		if ( ! in_array( $state, License_State::STATES, true ) ) {
			$errors[] = __( 'That license state is not one ReplicaForge recognises.', 'replicaforge' );
		}

		$expires = 0;
		if ( isset( $record['expires_at'] ) && '' !== $record['expires_at'] && null !== $record['expires_at'] ) {
			if ( ! is_numeric( $record['expires_at'] ) || (int) $record['expires_at'] < 0 ) {
				$errors[] = __( 'The expiry date could not be read.', 'replicaforge' );
			} else {
				$expires = (int) $record['expires_at'];
			}
		}

		if ( array() !== $errors ) {
			return array(
				'success' => false,
				'state'   => $state,
				'errors'  => $errors,
			);
		}

		$clean = array(
			'state'          => $state,
			'expires_at'     => $expires,
			'reference'      => isset( $record['reference'] ) && is_scalar( $record['reference'] )
				? substr( sanitize_text_field( (string) $record['reference'] ), 0, 80 )
				: '',
			'updated_at'     => time(),
			'updated_by'     => get_current_user_id(),
			'verification'   => 'none_local_record',
		);

		update_option( self::OPTION, $clean, false );

		return array(
			'success' => true,
			'state'   => $state,
			'errors'  => array(),
		);
	}

	/**
	 * Remove the local record.
	 *
	 * @return bool
	 */
	public function clear() {
		return delete_option( self::OPTION );
	}

	/**
	 * Return a description of what this provider is and is not.
	 *
	 * The system status screen and the plan screen both show this. The brief is
	 * emphatic that no fake payment or license functionality may be presented as
	 * real, and the only reliable way to honour that is to say it in the product
	 * rather than only in the documentation.
	 *
	 * @return array<string, mixed>
	 */
	public function diagnostics() {
		$record = $this->record();

		return array(
			'provider'      => $this->id(),
			'label'         => $this->label(),
			'remote'        => false,
			'configured'    => is_array( $record ) && array() !== $record,
			'state'         => $this->state()->name(),
			'verification'  => __( 'None. A local record is an administrator setting, not a verified purchase.', 'replicaforge' ),
			'requires_service' => false,
			'notes'         => array(
				__( 'No licensing server is contacted. ReplicaForge works fully offline.', 'replicaforge' ),
				__( 'No license key is validated and no activation is simulated.', 'replicaforge' ),
				__( 'The plan applied to this site is configured in ReplicaForge settings.', 'replicaforge' ),
			),
		);
	}
}
