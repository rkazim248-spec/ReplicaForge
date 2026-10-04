<?php
/**
 * Phase 20: per-extension configuration.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Typed, validated, per-extension settings.
 *
 * ### Why extension settings need their own store
 *
 * `Maintenance::set_setting()` is a closed allowlist of five retention keys, and
 * `Feature_Flags::FLAGS` is a closed list of five booleans. Neither can hold an extension's
 * endpoint URL or its channel choice, and neither *should* be widened to — a settings list
 * that anything can extend is a settings list nobody has audited.
 *
 * So this is one more bounded option, following the `Ai_Settings` pattern: its own
 * `OPTION_NAME`, `autoload = false`, and values that are re-validated against the extension's
 * **declared schema** on every read and write.
 *
 * ### The validation is the schema's, not this class's
 *
 * A field the manifest did not declare cannot be stored, and a value that fails the
 * declared type or bounds is refused with the reason. That means an extension author
 * describing `{"type": "url"}` gets the URL rules for free, without re-implementing them,
 * and cannot accidentally be handed a setting type nobody has thought about.
 */
final class Extension_Configuration {

	/**
	 * Option holding every extension's settings.
	 *
	 * @var string
	 */
	const OPTION = 'replicaforge_extension_settings';

	/**
	 * Extensions tracked in the option.
	 *
	 * @var int
	 */
	const MAX_EXTENSIONS = 100;

	/**
	 * Value types an extension may declare.
	 *
	 * Deliberately short. Each type maps to one coercion and one set of rules; a type that
	 * does not exist here cannot be declared, because a setting nobody knows how to validate
	 * is a setting nobody can trust.
	 *
	 * @var array<int, string>
	 */
	const TYPES = array( 'string', 'text', 'url', 'email', 'int', 'bool', 'enum', 'json' );

	/**
	 * Return whether a value is a declarable setting type.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_type( $value ) {
		return is_string( $value ) && in_array( $value, self::TYPES, true );
	}

	/**
	 * Read every setting for one extension.
	 *
	 * @param string $extension_id Extension id.
	 * @return array<string, mixed>
	 */
	public static function all( $extension_id ) {
		$extension_id = self::clean_id( $extension_id );

		if ( '' === $extension_id ) {
			return array();
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return isset( $stored[ $extension_id ] ) && is_array( $stored[ $extension_id ] )
			? $stored[ $extension_id ]
			: array();
	}

	/**
	 * Read the effective settings for an extension: declared defaults, overridden by stored.
	 *
	 * @param string              $extension_id Extension id.
	 * @param array<string, mixed> $schema       Declared configuration schema.
	 * @return array<string, mixed>
	 */
	public static function effective( $extension_id, array $schema ) {
		$stored = self::all( $extension_id );
		$out    = array();

		foreach ( self::fields( $schema ) as $name => $field ) {
			if ( array_key_exists( $name, $stored ) ) {
				$out[ $name ] = $stored[ $name ];
				continue;
			}

			if ( array_key_exists( 'default', $field ) && null !== $field['default'] ) {
				$out[ $name ] = $field['default'];
			}
		}

		return $out;
	}

	/**
	 * Write one setting.
	 *
	 * @param string              $extension_id Extension id.
	 * @param string              $field        Field name.
	 * @param array<string, mixed> $schema       Declared schema.
	 * @param mixed               $value        Proposed value.
	 * @return true|\WP_Error
	 */
	public static function set( $extension_id, $field, array $schema, $value ) {
		$extension_id = self::clean_id( $extension_id );
		$fields       = self::fields( $schema );

		if ( '' === $extension_id ) {
			return new \WP_Error( 'extension_id_required', __( 'An extension is required.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		$field = is_string( $field ) ? strtolower( trim( $field ) ) : '';

		if ( '' === $field || ! isset( $fields[ $field ] ) ) {
			return new \WP_Error(
				'extension_field_unknown',
				__( 'This extension does not declare that setting, so it cannot be stored.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$clean = self::coerce( $fields[ $field ], $value );

		if ( null === $clean['ok'] ) {
			return new \WP_Error(
				'extension_field_rejected',
				(string) $clean['reason'],
				array( 'status' => 400 )
			);
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( ! isset( $stored[ $extension_id ] ) && count( $stored ) >= self::MAX_EXTENSIONS ) {
			return new \WP_Error(
				'extension_settings_limit',
				__( 'Too many extensions have stored settings.', 'replicaforge' ),
				array( 'status' => 409 )
			);
		}

		$stored[ $extension_id ][ $field ] = $clean['value'];

		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Remove every setting for an extension.
	 *
	 * @param string $extension_id Extension id.
	 * @return bool
	 */
	public static function forget( $extension_id ) {
		$extension_id = self::clean_id( $extension_id );

		if ( '' === $extension_id ) {
			return false;
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( ! isset( $stored[ $extension_id ] ) ) {
			return false;
		}

		unset( $stored[ $extension_id ] );

		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Normalise a declared schema to the shape the read path expects.
	 *
	 * @param array<string, mixed> $schema Declared schema.
	 * @return array<string, array<string, mixed>>
	 */
	private static function fields( array $schema ) {
		$out = array();

		foreach ( $schema as $name => $field ) {
			if ( ! is_string( $name ) || ! is_array( $field ) ) {
				continue;
			}

			if ( ! self::is_type( $field['type'] ?? '' ) ) {
				continue;
			}

			$out[ strtolower( $name ) ] = $field;
		}

		return $out;
	}

	/**
	 * Validate one value against its declared field.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed               $value Proposed value.
	 * @return array{ok: bool|null, value: mixed, reason: string} `ok` is null on refusal.
	 */
	private static function coerce( array $field, $value ) {
		$type  = (string) ( $field['type'] ?? 'string' );
		$limit = (int) ( $field['max_length'] ?? 400 );

		switch ( $type ) {
			case 'bool':
				// Only a real boolean or one of the two literals. "1" and "true" and 1 are
				// accepted because a JSON client may send any of them; anything else is a
				// setting whose meaning depends on how it happened to arrive.
				if ( is_bool( $value ) ) {
					return array( 'ok' => true, 'value' => $value, 'reason' => '' );
				}
				if ( in_array( $value, array( 'true', '1', 1, 'yes', 'on' ), true ) ) {
					return array( 'ok' => true, 'value' => true, 'reason' => '' );
				}
				if ( in_array( $value, array( 'false', '0', 0, 'no', 'off', '', null ), true ) ) {
					return array( 'ok' => true, 'value' => false, 'reason' => '' );
				}
				return array( 'ok' => null, 'value' => null, 'reason' => __( 'That setting must be true or false.', 'replicaforge' ) );

			case 'int':
				if ( ! is_numeric( $value ) ) {
					return array( 'ok' => null, 'value' => null, 'reason' => __( 'That setting must be a number.', 'replicaforge' ) );
				}
				$number = (int) $value;

				if ( null !== $field['min'] && $number < (int) $field['min'] ) {
					return array( 'ok' => null, 'value' => null, 'reason' => __( 'That number is below the minimum this setting allows.', 'replicaforge' ) );
				}
				if ( null !== $field['max'] && $number > (int) $field['max'] ) {
					return array( 'ok' => null, 'value' => null, 'reason' => __( 'That number is above the maximum this setting allows.', 'replicaforge' ) );
				}

				return array( 'ok' => true, 'value' => $number, 'reason' => '' );

			case 'enum':
				$options = array_map( 'strval', (array) ( $field['options'] ?? array() ) );

				if ( array() === $options ) {
					return array( 'ok' => null, 'value' => null, 'reason' => __( 'That setting has no allowed values.', 'replicaforge' ) );
				}
				if ( ! in_array( (string) $value, $options, true ) ) {
					return array(
						'ok'     => null,
						'value'  => null,
						'reason' => sprintf(
							/* translators: %s: comma-separated allowed values. */
							__( 'That setting must be one of: %s.', 'replicaforge' ),
							implode( ', ', $options )
						),
					);
				}

				return array( 'ok' => true, 'value' => (string) $value, 'reason' => '' );

			case 'url':
				$text = is_scalar( $value ) ? trim( (string) $value ) : '';

				/* An empty URL clears the setting. That is a real operation — "unset it" — so
				 * it is allowed, and it does not weaken anything. */
				if ( '' === $text ) {
					return array( 'ok' => true, 'value' => '', 'reason' => '' );
				}

				/*
				 * The same SSRF boundary a template asset URL goes through. An extension
				 * that declares a `url` setting is declaring a place ReplicaForge will
				 * *fetch*, so an internal address must be refused at configuration time
				 * rather than discovered at request time.
				 */
				if ( ! Security::is_safe_public_reference( $text ) ) {
					return array(
						'ok'     => null,
						'value'  => null,
						'reason' => __( 'That address is not a public web address. An internal or non-web address cannot be used here.', 'replicaforge' ),
					);
				}

				return array( 'ok' => true, 'value' => substr( (string) Security::normalize_http_url( $text ), 0, 2048 ), 'reason' => '' );

			case 'email':
				$text = is_scalar( $value ) ? trim( (string) $value ) : '';

				if ( '' === $text ) {
					return array( 'ok' => true, 'value' => '', 'reason' => '' );
				}

				if ( ! is_email( $text ) ) {
					return array( 'ok' => null, 'value' => null, 'reason' => __( 'That is not a usable email address.', 'replicaforge' ) );
				}

				return array( 'ok' => true, 'value' => $text, 'reason' => '' );

			case 'json':
				if ( is_string( $value ) ) {
					$decoded = json_decode( $value, true );
					if ( ! is_array( $decoded ) ) {
						return array( 'ok' => null, 'value' => null, 'reason' => __( 'That is not readable JSON.', 'replicaforge' ) );
					}
					$value = $decoded;
				}

				if ( ! is_array( $value ) ) {
					return array( 'ok' => null, 'value' => null, 'reason' => __( 'That setting must be a JSON object.', 'replicaforge' ) );
				}

				$encoded = wp_json_encode( $value );

				if ( ! is_string( $encoded ) || strlen( $encoded ) > min( $limit, 8192 ) ) {
					return array( 'ok' => null, 'value' => null, 'reason' => __( 'That JSON value is too large to store.', 'replicaforge' ) );
				}

				/* Reduced through the Phase 1 boundary, so a setting cannot carry an object
				 * graph or a class instance into storage. */
				return array( 'ok' => true, 'value' => Data_Redactor::structure( $value ), 'reason' => '' );

			case 'text':
			default:
				if ( ! is_scalar( $value ) ) {
					return array( 'ok' => null, 'value' => null, 'reason' => __( 'That setting must be text.', 'replicaforge' ) );
				}

				$text = (string) $value;

				if ( strlen( $text ) > min( $limit, 8000 ) ) {
					return array( 'ok' => null, 'value' => null, 'reason' => __( 'That text is longer than this setting allows.', 'replicaforge' ) );
				}

				if ( 'text' !== $type && ! is_scalar( $value ) ) {
					return array( 'ok' => null, 'value' => null, 'reason' => __( 'That setting must be a single value.', 'replicaforge' ) );
				}

				/* `wp_kses_post` rather than `sanitize_text_field`, because a description may
				 * legitimately contain a link, and the output is escaped at every render site
				 * regardless. Anything that is not permitted markup is removed rather than
				 * escaped into visible text. */
				$clean = 'text' === $type ? wp_kses_post( $text ) : sanitize_text_field( $text );

				if ( Elementor_Values::is_executable( $clean ) ) {
					return array( 'ok' => null, 'value' => null, 'reason' => __( 'That value contains code and cannot be stored.', 'replicaforge' ) );
				}

				return array( 'ok' => true, 'value' => $clean, 'reason' => '' );
		}
	}

	/**
	 * Reduce an extension id to its storable form.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private static function clean_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ), 0, 64 ) : '';
	}
}
