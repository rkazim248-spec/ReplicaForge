<?php
/**
 * Phase 20: extension manifest validation.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Validates an extension manifest and states exactly what it is allowed to do.
 *
 * ### This class is the boundary between metadata and behaviour
 *
 * A manifest arrives as data. This class turns that data into a *narrowed* record: a
 * capability list filtered against the closed vocabulary, a permission list filtered the
 * same way, a requirements block reduced to what actually exists to check, and a
 * configuration schema reduced to declared fields.
 *
 * Nothing downstream re-reads the raw manifest. That is the point. If the registry stored
 * what the author wrote and validated at use-time, every call site would need the same
 * checks and one of them would eventually forget. Validating once, at the boundary, means
 * the rest of the platform can trust its own store.
 *
 * ### What is refused, and the difference between refusal and downgrade
 *
 * Three different outcomes, deliberately distinguished:
 *
 * - **Refused** — the whole registration fails. An unknown capability, a forbidden
 *   permission, a malformed id. There is no partial result to salvage.
 * - **Dropped** — one entry is removed and the rest proceeds. An unknown dependency, an
 *   unknown configuration field. The extension still works; it just does not have the thing
 *   it hoped for.
 * - **Recorded** — the manifest asked for something the environment cannot currently
 *   provide, and the manifest is still valid. A minimum WordPress version this site does
 *   not meet. The extension is `incompatible`, not broken.
 *
 * ### Compatibility is checked against real versions
 *
 * {@see Schema::all()} and {@see System_Status} are the source. A manifest declaring
 * `"requires": { "replicaforge": "99.0.0" }` is not refused — it is *valid* and
 * *incompatible*, and the console says which requirement failed. Refusing it would lose
 * the information that would let an operator upgrade rather than disable.
 */
final class Extension_Manifest {

	/**
	 * Manifest schema version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = Platform_Limits::MANIFEST_SCHEMA_VERSION;

	/**
	 * Maximum extensions in one registration.
	 *
	 * @var int
	 */
	const MAX_CAPABILITIES = 8;

	/**
	 * Maximum permissions in one registration.
	 *
	 * @var int
	 */
	const MAX_PERMISSIONS = 16;

	/**
	 * Maximum declared dependencies.
	 *
	 * @var int
	 */
	const MAX_DEPENDENCIES = 20;

	/**
	 * Maximum declared configuration fields.
	 *
	 * @var int
	 */
	const MAX_CONFIGURATION_FIELDS = 40;

	/**
	 * Result of a validation.
	 *
	 * @var array<string, mixed>
	 */
	private $result;

	/**
	 * Validate a manifest.
	 *
	 * @param mixed $manifest Raw manifest, typically a decoded JSON object.
	 * @return array{ok: bool, manifest: array<string, mixed>, capabilities: array<int, string>, permissions: array<int, string>, errors: array<int, string>, warnings: array<int, string>, dropped: array<int, string>, compatible: bool, incompatibilities: array<int, array<string, string>>}
	 */
	public static function validate( $manifest ) {
		$errors     = array();
		$warnings   = array();
		$dropped    = array();
		$incompat   = array();

		if ( ! is_array( $manifest ) ) {
			return self::failure( __( 'An extension manifest must be an object.', 'replicaforge' ) );
		}

		if ( isset( $manifest['schema_version'] ) && '' !== (string) $manifest['schema_version'] ) {
			$declared = (string) $manifest['schema_version'];

			if ( version_compare( $declared, self::SCHEMA_VERSION, '>' ) ) {
				/* Refused rather than downgraded. A manifest this version cannot read may
				 * have a permission or a configuration field whose meaning has changed, and
				 * reading it partially is the failure mode `Plan_Storage::import()` guards
				 * against for the same reason. */
				return self::failure(
					sprintf(
						/* translators: 1: the manifest's schema version, 2: the version this install understands. */
						__( 'This extension uses manifest schema %1$s; this ReplicaForge understands %2$s. Upgrade ReplicaForge, or ask the extension author for a compatible build.', 'replicaforge' ),
						$declared,
						self::SCHEMA_VERSION
					)
				);
			}
		}

		// --- id -------------------------------------------------------------
		$id = isset( $manifest['id'] ) && is_string( $manifest['id'] ) ? strtolower( trim( $manifest['id'] ) ) : '';

		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{2,63}$/', $id ) ) {
			$errors[] = __( 'An extension needs an id of 3 to 64 lowercase characters, starting with a letter or digit, using letters, digits, hyphens and underscores.', 'replicaforge' );
		}

		// --- version --------------------------------------------------------
		$version = isset( $manifest['version'] ) && is_string( $manifest['version'] ) ? trim( $manifest['version'] ) : '';

		if ( ! preg_match( '/^\d{1,4}(\.\d{1,4}){0,3}(-[0-9A-Za-z.-]{1,32})?$/', $version ) ) {
			$errors[] = __( 'An extension needs a version, written as numbers separated by dots, with an optional suffix.', 'replicaforge' );
		}

		// --- name -----------------------------------------------------------
		$name = isset( $manifest['name'] ) && is_string( $manifest['name'] ) ? sanitize_text_field( $manifest['name'] ) : '';

		if ( '' === $name || strlen( $name ) > 120 ) {
			$errors[] = __( 'An extension needs a name.', 'replicaforge' );
		}

		// --- capabilities ---------------------------------------------------
		$requested   = isset( $manifest['capabilities'] ) && is_array( $manifest['capabilities'] ) ? $manifest['capabilities'] : array();
		$capabilities = array();

		if ( array() === $requested ) {
			$errors[] = __( 'An extension must declare at least one capability.', 'replicaforge' );
		}

		foreach ( array_slice( array_values( $requested ), 0, self::MAX_CAPABILITIES + 1 ) as $capability ) {
			if ( ! is_string( $capability ) ) {
				$errors[] = __( 'A capability was not a name.', 'replicaforge' );
				continue;
			}

			$capability = trim( $capability );

			if ( ! Platform_Limits::is_extension_capability( $capability ) ) {
				/*
				 * Refused, not warned about. An unknown capability is either a typo or an
				 * attempt to claim something that does not exist, and in both cases the
				 * honest response is to refuse the whole registration rather than to grant
				 * the subset that happens to be recognised — which would let an author
				 * believe they had a capability they did not.
				 */
				$errors[] = sprintf(
					/* translators: %s: the unrecognised capability name. */
					__( '"%s" is not a capability ReplicaForge has. It supports: %s.', 'replicaforge' ),
					$capability,
					implode( ', ', array_keys( Platform_Limits::EXTENSION_CAPABILITIES ) )
				);
				continue;
			}

			if ( in_array( $capability, $capabilities, true ) ) {
				continue;
			}

			$capabilities[] = $capability;
		}

		if ( count( $requested ) > self::MAX_CAPABILITIES ) {
			$errors[] = sprintf(
				/* translators: %d: the maximum number of capabilities. */
				__( 'An extension may declare at most %d capabilities.', 'replicaforge' ),
				self::MAX_CAPABILITIES
			);
		}

		// --- permissions ----------------------------------------------------
		$requested_permissions = isset( $manifest['permissions'] ) && is_array( $manifest['permissions'] ) ? $manifest['permissions'] : array();
		$permissions            = array();

		foreach ( array_slice( array_values( $requested_permissions ), 0, self::MAX_PERMISSIONS + 1 ) as $permission ) {
			if ( ! is_string( $permission ) ) {
				$errors[] = __( 'A permission was not a name.', 'replicaforge' );
				continue;
			}

			$permission = trim( $permission );

			if ( Platform_Limits::is_forbidden_permission( $permission ) ) {
				/*
				 * Named explicitly rather than silently dropped. An extension that believes
				 * it holds `database.write` and silently does not is a debugging problem the
				 * author will not solve; one told "that permission does not exist and never
				 * will" is a one-line fix.
				 */
				$errors[] = sprintf(
					/* translators: %s: the refused permission. */
					__( 'The permission "%s" does not exist and cannot be granted. An extension has no database, filesystem, code-execution or impersonation access, because none of those are ever passed to one.', 'replicaforge' ),
					$permission
				);
				continue;
			}

			if ( ! Platform_Limits::is_extension_permission( $permission ) ) {
				$errors[] = sprintf(
					/* translators: %s: the unrecognised permission name. */
					__( '"%s" is not a permission ReplicaForge grants to extensions.', 'replicaforge' ),
					$permission
				);
				continue;
			}

			if ( in_array( $permission, $permissions, true ) ) {
				continue;
			}

			$permissions[] = $permission;
		}

		if ( count( $requested_permissions ) > self::MAX_PERMISSIONS ) {
			$errors[] = sprintf(
				/* translators: %d: the maximum number of permissions. */
				__( 'An extension may declare at most %d permissions.', 'replicaforge' ),
				self::MAX_PERMISSIONS
			);
		}

		/*
		 * Every declared capability implies at least one permission. Declaring a capability
		 * whose method has no permission available would mean a provider that is invoked
		 * with input it has no right to see.
		 */
		foreach ( $capabilities as $capability ) {
			$method = isset( Extension_Provider_Contract::METHODS[ $capability ] ) ? Extension_Provider_Contract::METHODS[ $capability ]['permission'] : '';

			if ( '' !== $method && ! in_array( $method, $permissions, true ) ) {
				$permissions[] = $method;
			}
		}

		// --- dependencies ---------------------------------------------------
		$dependencies = array();

		if ( isset( $manifest['dependencies'] ) ) {
			if ( ! is_array( $manifest['dependencies'] ) ) {
				$errors[] = __( 'Dependencies must be a list of extension ids.', 'replicaforge' );
			} else {
				foreach ( array_slice( array_values( $manifest['dependencies'] ), 0, self::MAX_DEPENDENCIES + 1 ) as $dependency ) {
					$dependency = is_string( $dependency ) ? strtolower( trim( $dependency ) ) : '';

					if ( '' === $dependency || ! preg_match( '/^[a-z0-9][a-z0-9_-]{2,63}$/', $dependency ) ) {
						$errors[] = __( 'A dependency was not a valid extension id.', 'replicaforge' );
						continue;
					}

					// A dependency on itself is refused rather than dropped: it is a manifest
					// that can never resolve, and treating it as a soft warning would leave a
					// permanently-incompatible extension with a plausible-looking reason.
					if ( $dependency === $id ) {
						$errors[] = __( 'An extension cannot depend on itself.', 'replicaforge' );
						continue;
					}

					if ( ! in_array( $dependency, $dependencies, true ) ) {
						$dependencies[] = $dependency;
					}
				}
			}
		}

		// --- requirements ---------------------------------------------------
		$requires     = isset( $manifest['requires'] ) && is_array( $manifest['requires'] ) ? $manifest['requires'] : array();
		$requirements = array();

		foreach ( array( 'replicaforge', 'replicaforge_tested', 'wordpress', 'php', 'elementor', 'woocommerce' ) as $requirement ) {
			if ( ! isset( $requires[ $requirement ] ) ) {
				continue;
			}

			$value = is_scalar( $requires[ $requirement ] ) ? trim( (string) $requires[ $requirement ] ) : '';

			if ( '' === $value ) {
				continue;
			}

			if ( ! preg_match( '/^\d{1,4}(\.\d{1,4}){0,3}$/', $value ) ) {
				$errors[] = sprintf(
					/* translators: %s: the requirement name. */
					__( 'The "%s" requirement must be a version written as numbers separated by dots.', 'replicaforge' ),
					$requirement
				);
				continue;
			}

			$requirements[ $requirement ] = $value;
		}

		// --- configuration ---------------------------------------------------
		$configuration  = self::validate_configuration( isset( $manifest['configuration'] ) ? $manifest['configuration'] : array(), $errors, $dropped );
		$subscribe      = self::validate_events( isset( $manifest['subscribes'] ) ? $manifest['subscribes'] : array(), $dropped );

		// --- compatibility ---------------------------------------------------
		$incompatibilities = self::compatibility( $requirements );

		if ( array() !== $incompatibilities ) {
			foreach ( $incompatibilities as $incompatibility ) {
				$warnings[] = (string) $incompatibility['detail'];
			}
		}

		$clean = array(
			'schema_version' => self::SCHEMA_VERSION,
			'id'             => $id,
			'name'           => $name,
			'version'        => $version,
			'author'         => isset( $manifest['author'] ) && is_scalar( $manifest['author'] ) ? substr( sanitize_text_field( (string) $manifest['author'] ), 0, 120 ) : '',
			'description'    => isset( $manifest['description'] ) && is_scalar( $manifest['description'] ) ? substr( sanitize_text_field( (string) $manifest['description'] ), 0, 500 ) : '',
			'homepage'       => self::clean_homepage( isset( $manifest['homepage'] ) ? $manifest['homepage'] : '' ),
			'capabilities'   => $capabilities,
			'permissions'    => $permissions,
			'dependencies'   => $dependencies,
			'requires'       => $requirements,
			'configuration'  => $configuration,
			'subscribes'     => $subscribe,
			'registered_at'  => gmdate( 'c' ),
		);

		if ( array() !== $errors ) {
			return array(
				'ok'                => false,
				'manifest'          => $clean,
				'capabilities'      => $capabilities,
				'permissions'       => $permissions,
				'errors'            => $errors,
				'warnings'          => $warnings,
				'dropped'           => array_values( array_unique( $dropped ) ),
				'compatible'        => false,
				'incompatibilities' => $incompatibilities,
			);
		}

		return array(
			'ok'                => true,
			'manifest'          => $clean,
			'capabilities'      => $capabilities,
			'permissions'       => $permissions,
			'errors'            => array(),
			'warnings'          => $warnings,
			'dropped'           => array_values( array_unique( $dropped ) ),
			'compatible'        => array() === $incompatibilities,
			'incompatibilities' => $incompatibilities,
		);
	}

	/**
	 * Validate the configuration schema.
	 *
	 * A configuration schema is a *description*, so it is reduced to the shape
	 * `Extension_Configuration` needs to read and write settings: a field name, a type, and
	 * bounds. A field with no recognised type is dropped, because a setting ReplicaForge
	 * cannot type cannot be safely stored.
	 *
	 * @param mixed              $schema  Raw schema.
	 * @param array<int, string> $errors  Errors, by reference.
	 * @param array<int, string> $dropped Dropped entries, by reference.
	 * @return array<string, array<string, mixed>>
	 */
	private static function validate_configuration( $schema, array &$errors, array &$dropped ) {
		if ( ! is_array( $schema ) ) {
			return array();
		}

		// A bare `{ "schema": { ... } }` wrapper is accepted, because it is the shape a
		// manifest written for a settings screen tends to have.
		if ( isset( $schema['schema'] ) && is_array( $schema['schema'] ) ) {
			$schema = $schema['schema'];
		}

		$fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();

		if ( array() === $fields ) {
			// A flat map of name => type is also accepted.
			$fields = $schema;
		}

		$clean = array();

		foreach ( array_slice( $fields, 0, self::MAX_CONFIGURATION_FIELDS + 1, true ) as $key => $definition ) {
			$name = is_string( $key ) ? strtolower( trim( $key ) ) : '';

			if ( ! preg_match( '/^[a-z0-9][a-z0-9_]{0,39}$/', $name ) ) {
				$dropped[] = 'configuration field name';
				continue;
			}

			// `field => 'string'` and `field => array( 'type' => 'string' )` both work.
			$type = is_string( $definition ) ? $definition : ( isset( $definition['type'] ) && is_string( $definition['type'] ) ? $definition['type'] : '' );
			$type = trim( $type );

			if ( ! Extension_Configuration::is_type( $type ) ) {
				$dropped[] = sprintf( 'configuration field "%s"', $name );
				continue;
			}

			$clean[ $name ] = array(
				'type'    => $type,
				'default' => isset( $definition['default'] ) ? $definition['default'] : ( is_array( $definition ) ? null : $definition ),
				'options' => isset( $definition['options'] ) && is_array( $definition['options'] ) ? array_slice( array_values( $definition['options'] ), 0, 40 ) : array(),
				'min'     => isset( $definition['min'] ) && is_numeric( $definition['min'] ) ? (float) $definition['min'] : null,
				'max'     => isset( $definition['max'] ) && is_numeric( $definition['max'] ) ? (float) $definition['max'] : null,
				'max_length' => isset( $definition['max_length'] ) && is_numeric( $definition['max_length'] ) ? (int) $definition['max_length'] : 400,
			);
		}

		if ( count( $fields ) > self::MAX_CONFIGURATION_FIELDS ) {
			$dropped[] = sprintf( 'configuration fields beyond the first %d', self::MAX_CONFIGURATION_FIELDS );
		}

		return $clean;
	}

	/**
	 * Validate the event subscription list.
	 *
	 * @param mixed              $events  Raw list.
	 * @param array<int, string> $dropped Dropped entries, by reference.
	 * @return array<int, string>
	 */
	private static function validate_events( $events, array &$dropped ) {
		if ( ! is_array( $events ) ) {
			return array();
		}

		$clean = array();

		foreach ( array_slice( array_values( $events ), 0, 40 ) as $event ) {
			$event = is_string( $event ) ? trim( $event ) : '';

			if ( ! Platform_Limits::is_event( $event ) ) {
				$dropped[] = sprintf( 'event subscription "%s"', $event );
				continue;
			}

			if ( ! in_array( $event, $clean, true ) ) {
				$clean[] = $event;
			}
		}

		return $clean;
	}

	/**
	 * Check declared requirements against this install.
	 *
	 * @param array<string, string> $requirements Declared requirements.
	 * @return array<int, array{requirement: string, required: string, found: string, detail: string}>
	 */
	private static function compatibility( array $requirements ) {
		$found = array();

		if ( isset( $requirements['replicaforge'] ) ) {
			$found['replicaforge'] = defined( 'REPLICAFORGE_VERSION' ) ? (string) REPLICAFORGE_VERSION : '0.0.0';
		}
		if ( isset( $requirements['wordpress'] ) ) {
			$found['wordpress'] = (string) get_bloginfo( 'version' );
		}
		if ( isset( $requirements['php'] ) ) {
			$found['php'] = PHP_VERSION;
		}
		if ( isset( $requirements['elementor'] ) ) {
			$status    = ( new Elementor_Compatibility() )->status();
			$found['elementor'] = (string) ( $status['version'] ?? '' );
		}
		if ( isset( $requirements['woocommerce'] ) ) {
			$found['woocommerce'] = ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) ? '1.0.0' : '0.0.0';
		}

		$out = array();

		foreach ( $requirements as $requirement => $minimum ) {
			$installed = (string) ( $found[ $requirement ] ?? '' );

			/*
			 * An elementor requirement when Elementor is absent is `incompatible`, not
			 * `unknown`. They are different answers to different questions: "this build is
			 * too old" versus "there is nothing to be too old against", and an operator
			 * reacts to them differently.
			 */
			if ( '' === $installed && in_array( $requirement, array( 'elementor', 'woocommerce' ), true ) ) {
				$out[] = array(
					'requirement' => $requirement,
					'required'    => $minimum,
					'found'       => '',
					'detail'      => sprintf(
						/* translators: %s: the product name. */
						__( 'This extension needs %s %s or newer, and %s is not installed on this site.', 'replicaforge' ),
						ucfirst( $requirement ),
						$minimum,
						ucfirst( $requirement )
					),
				);
				continue;
			}

			if ( '' === $installed ) {
				continue;
			}

			if ( version_compare( $installed, $minimum, '>=' ) ) {
				continue;
			}

			$out[] = array(
				'requirement' => $requirement,
				'required'    => $minimum,
				'found'       => $installed,
				'detail'      => sprintf(
					/* translators: 1: product name, 2: required version, 3: installed version. */
					__( 'This extension needs %1$s %2$s or newer; this site has %3$s.', 'replicaforge' ),
					ucfirst( $requirement ),
					$minimum,
					$installed
				),
			);
		}

		return $out;
	}

	/**
	 * Validate a manifest homepage.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private static function clean_homepage( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		/*
		 * Through the SSRF boundary, because a manifest homepage is a URL an author
		 * supplied and is rendered into the console as a link. It is never fetched, so the
		 * risk is `javascript:` in an anchor rather than a request — but the same check
		 * answers both and there is no reason to use a weaker one here.
		 */
		if ( ! Security::is_safe_public_reference( $value ) ) {
			return '';
		}

		return substr( (string) Security::normalize_http_url( $value ), 0, 300 );
	}

	/**
	 * Return a refusal carrying one reason.
	 *
	 * @param string $reason Why.
	 * @return array<string, mixed>
	 */
	private static function failure( $reason ) {
		return array(
			'ok'                => false,
			'manifest'          => array(),
			'capabilities'      => array(),
			'permissions'       => array(),
			'errors'            => array( (string) $reason ),
			'warnings'          => array(),
			'dropped'           => array(),
			'compatible'        => false,
			'incompatibilities' => array(),
		);
	}
}
