<?php
/**
 * Server-side AI settings.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Stores AI configuration without exposing credentials to the browser.
 */
final class Ai_Settings {

	const OPTION_NAME = 'replicaforge_ai_settings';

	/**
	 * Return internal settings, including the server-side key.
	 *
	 * @return array<string, mixed>
	 */
	public function get() {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$settings = array_merge( $this->defaults(), $stored );
		$settings['provider'] = is_scalar( $settings['provider'] ) ? (string) $settings['provider'] : 'none';
		$settings['model']    = is_scalar( $settings['model'] ) ? (string) $settings['model'] : '';
		$settings['api_key']  = is_scalar( $settings['api_key'] ) ? $this->decrypt_secret( (string) $settings['api_key'] ) : '';
		$settings['enabled']  = ! empty( $settings['enabled'] );
		$settings['timeout']  = $this->clamp_timeout( isset( $settings['timeout'] ) ? $settings['timeout'] : Ai_Limits::DEFAULT_TIMEOUT );
		return $settings;
	}

	/**
	 * Return settings safe for the admin JavaScript configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function get_public_config() {
		$settings = $this->get();
		return array(
			'enabled'       => (bool) $settings['enabled'],
			'provider'      => $this->provider_label( $settings['provider'] ),
			'model'         => (string) $settings['model'],
			'timeout'       => absint( $settings['timeout'] ),
			'api_key_set'   => '' !== (string) $settings['api_key'],
			'api_key_masked' => '' !== (string) $settings['api_key'] ? str_repeat( '•', 12 ) : '',
		);
	}

	/**
	 * Determine whether a configured provider can be used.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$settings = $this->get();
		return ! empty( $settings['enabled'] ) && 'none' !== $settings['provider'] && '' !== trim( (string) $settings['model'] ) && '' !== trim( (string) $settings['api_key'] );
	}

	/**
	 * Save settings. A blank key preserves the existing server-side key.
	 *
	 * @param array<string, mixed> $input Untrusted form input.
	 * @return array<string, mixed> Public settings.
	 */
	public function save( array $input ) {
		$existing = $this->get();
		$provider = isset( $input['provider'] ) && is_scalar( $input['provider'] ) ? sanitize_key( (string) $input['provider'] ) : 'none';
		if ( ! in_array( $provider, array( 'none', 'openai', 'gemini' ), true ) ) {
			$provider = 'none';
		}
		$model = isset( $input['model'] ) && is_scalar( $input['model'] ) ? sanitize_text_field( (string) $input['model'] ) : '';
		$model = substr( $model, 0, 120 );
		$key = isset( $input['api_key'] ) && is_scalar( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		if ( '' === $key || '[REDACTED]' === $key || '••••••••••••' === $key ) {
			$key = (string) $existing['api_key'];
		} else {
			$sanitized = $this->sanitize_secret( $key );
			$key       = '' !== $sanitized ? $sanitized : (string) $existing['api_key'];
		}
		$settings = array(
			'provider'   => $provider,
			'model'      => $model,
			'api_key'    => $this->encrypt_secret( $key ),
			'enabled'    => ! empty( $input['enabled'] ),
			'timeout'    => $this->clamp_timeout( isset( $input['timeout'] ) ? $input['timeout'] : Ai_Limits::DEFAULT_TIMEOUT ),
			'updated_at' => gmdate( 'c' ),
		);
		update_option( self::OPTION_NAME, $settings, false );
		return $this->get_public_config();
	}

	/**
	 * Return default non-sensitive settings.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults() {
		return array(
			'provider'   => 'none',
			'model'      => '',
			'api_key'    => '',
			'enabled'    => false,
			'timeout'    => Ai_Limits::DEFAULT_TIMEOUT,
			'updated_at' => '',
		);
	}

	/**
	 * Clamp provider timeout.
	 *
	 * @param mixed $timeout Raw timeout.
	 * @return int
	 */
	private function clamp_timeout( $timeout ) {
		$timeout = is_scalar( $timeout ) ? absint( $timeout ) : Ai_Limits::DEFAULT_TIMEOUT;
		if ( $timeout < Ai_Limits::MIN_TIMEOUT ) {
			return Ai_Limits::MIN_TIMEOUT;
		}
		return min( Ai_Limits::MAX_TIMEOUT, $timeout );
	}

	/**
	 * Sanitize a secret without logging or returning it to the browser.
	 *
	 * @param string $secret Raw secret.
	 * @return string
	 */
	private function sanitize_secret( $secret ) {
		$secret = trim( $secret );
		if ( '' === $secret || strlen( $secret ) > 512 || preg_match( '/[\x00-\x1f\x7f]/', $secret ) ) {
			return '';
		}
		return $secret;
	}

	/**
	 * Encrypt a secret when the runtime and WordPress salts are available.
	 *
	 * @param string $secret Plain secret.
	 * @return string
	 */
	private function encrypt_secret( $secret ) {
		if ( '' === $secret ) {
			return '';
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$salts = $this->salts();
			if ( '' !== $salts ) {
				$iv = openssl_random_pseudo_bytes( 16 );
				if ( ! is_string( $iv ) || 16 !== strlen( $iv ) ) {
					return '';
				}
				$key = hash( 'sha256', $salts, true );
				$cipher = openssl_encrypt( $secret, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
				if ( is_string( $cipher ) ) {
					return 'enc:' . base64_encode( $iv . $cipher );
				}
			}
		}
		return '';
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	private function decrypt_secret( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'plain:' ) ) {
			$decoded = base64_decode( substr( $stored, 6 ), true );
			return is_string( $decoded ) ? $decoded : '';
		}
		if ( 0 === strpos( $stored, 'enc:' ) && function_exists( 'openssl_decrypt' ) ) {
			$salts = $this->salts();
			$decoded = base64_decode( substr( $stored, 4 ), true );
			if ( '' === $salts || ! is_string( $decoded ) || strlen( $decoded ) <= 16 ) {
				return '';
			}
			$iv = substr( $decoded, 0, 16 );
			$cipher = substr( $decoded, 16 );
			$key = hash( 'sha256', $salts, true );
			$secret = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return is_string( $secret ) ? $secret : '';
		}
		return $stored;
	}

	/**
	 * Return stable server-side key material for secret encryption.
	 *
	 * @return string
	 */
	private function salts() {
		$auth   = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$secure = defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '';
		if ( '' === $auth && function_exists( 'wp_salt' ) ) {
			$auth = (string) wp_salt( 'auth' );
		}
		if ( '' === $secure && function_exists( 'wp_salt' ) ) {
			$secure = (string) wp_salt( 'secure_auth' );
		}
		return '' !== $auth && '' !== $secure ? $auth . $secure : '';
	}

	/**
	 * Return a provider label safe for the browser.
	 *
	 * @param string $provider Provider ID.
	 * @return string
	 */
	private function provider_label( $provider ) {
		$provider = sanitize_key( $provider );
		return in_array( $provider, array( 'none', 'openai', 'gemini' ), true ) ? $provider : 'none';
	}
}
