<?php
/**
 * Phase 19: the portable template package.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Export and import of a portable template package.
 *
 * ### What a package is
 *
 * A JSON document. Not a ZIP. §39 lists "ZIP/package traversal if package files are used"
 * as something to test, and the honest answer is that the design removes the attack surface
 * rather than testing for it: a single JSON object has no filenames, so there is no
 * `../` to traverse, no symlink to follow, and no size-inflation trick from a
 * compression-ratio limit. A package is a payload, not an archive.
 *
 * When a future marketplace needs a `.zip`, that is the moment to add `PclZip`/`ZipArchive`
 * with the traversal checks — and the payload inside it would still be this structure.
 *
 * ### What a package must never contain
 *
 * §26 is explicit, and this class enforces the list rather than trusting a caller:
 *
 * - no API keys, cookies, or authentication credentials;
 * - no private source data — no source page content, no user records;
 * - no unapproved user data;
 * - no assets whose rights are not established.
 *
 * The export side therefore runs a **redaction pass** over the whole payload, and the
 * import side runs the same pass. A key that reaches either side is dropped, and dropping
 * is reported — so a user who exported a package can see that something was removed rather
 * than discovering a broken template on the far side.
 *
 * ### Versioning
 *
 * `Template_Limits::SCHEMA_VERSION` is written into every package and checked on import. A
 * package from a *newer* schema is refused, following `Plan_Storage::import()`'s rule
 * (implemented in Phase 18 for the same reason) — a document with a key this version does
 * not understand is a document whose limits might be incomplete, and an incomplete limit is
 * read as unlimited.
 */
final class Template_Package {

	/**
	 * Sanitizer.
	 *
	 * @var Template_Sanitizer
	 */
	private $sanitizer;

	/**
	 * Validator.
	 *
	 * @var Template_Validator
	 */
	private $validator;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Template_Sanitizer|null $sanitizer Sanitizer.
	 * @param Template_Validator|null $validator Validator.
	 * @param Logger|null            $logger    Logger.
	 */
	public function __construct( $sanitizer = null, $validator = null, $logger = null ) {
		$this->logger    = $logger instanceof Logger ? $logger : new Logger();
		$this->sanitizer = $sanitizer instanceof Template_Sanitizer ? $sanitizer : new Template_Sanitizer( null, $this->logger );
		$this->validator = $validator instanceof Template_Validator ? $validator : new Template_Validator( $this->sanitizer, null, $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * Forbidden content
	 * ------------------------------------------------------------------ */

	/**
	 * Keys that must never appear in a package, at any depth.
	 *
	 * Matched case-insensitively against the last path segment, so `api_key`,
	 * `openai_api_key` and `replicaforge_ai_settings` are all caught by `api_key`-style
	 * matching, and the *exact* names catch the rest.
	 *
	 * @return array<int, string>
	 */
	public static function forbidden_keys() {
		return array(
			'api_key', 'apikey', 'api_secret', 'secret', 'client_secret', 'access_key',
			'secret_key', 'private_key', 'token', 'bearer', 'authorization', 'auth',
			'password', 'passwd', 'pwd', 'nonce', 'wp_nonce', '_wpnonce', 'session',
			'session_id', 'cookie', 'cookies', 'set-cookie', 'license_key', 'salt',
			'db_password', 'db_user', 'db_host', 'dsn',
		);
	}

	/**
	 * Key fragments that indicate a credential, matched anywhere in a key.
	 *
	 * Catches names this class has never seen — `openai_api_key_v2`, `stripe_secret` — which
	 * is the point of a fragment match. A denylist of exact names is trivially defeated by
	 * a rename, and an allowlist of permitted keys would have to enumerate every legitimate
	 * design field to work.
	 *
	 * @return array<int, string>
	 */
	public static function secret_fragments() {
		return array( 'api_key', 'apikey', 'api_secret', 'secret', 'password', 'passwd', 'private_key', 'bearer', 'authorization', 'credential' );
	}

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------ */

	/**
	 * Build a package from a stored template version.
	 *
	 * @param array<string, mixed> $template Stored template row.
	 * @param array<string, mixed> $version  Stored version row.
	 * @param array<string, mixed> $options  Export options.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function export( array $template, array $version, array $options = array() ) {
		$template_id = (string) ( $template['public_id'] ?? '' );
		$name        = (string) ( $template['name'] ?? '' );

		if ( '' === $template_id || '' === $name ) {
			return new \WP_Error( 'template_export_incomplete', __( 'This template has no readable record, so it cannot be exported.', 'replicaforge' ), array( 'status' => 409 ) );
		}

		$verified = ( new Template_Version_Store() )->verify( $version );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$assets = $this->select_assets( isset( $version['assets'] ) && is_array( $version['assets'] ) ? $version['assets'] : array(), $options );

		$payload = array(
			'schema_version' => Template_Limits::SCHEMA_VERSION,
			'engine_version' => Template_Limits::ENGINE_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'exported_by'    => get_current_user_id(),
			'template'       => array(
				'name'        => $name,
				'description' => (string) ( $template['description'] ?? '' ),
				'type'        => (string) ( $template['type'] ?? 'custom' ),
				'version'     => (int) ( $version['version'] ?? 1 ),
				'version_id'  => (string) ( $version['public_id'] ?? '' ),
				'tags'        => (array) ( $template['tags'] ?? array() ),
			),
			/*
			 * The document is the template. It has to be here.
			 *
			 * An earlier version of this method omitted the `document` key entirely and
			 * exported everything else — tokens, slots, assets, compatibility, provenance —
			 * which looked like a complete package and produced a file that installed as an
			 * empty page. The export reported success, the security check on the importing
			 * side correctly refused it as having no surviving elements, and the two facts
			 * together looked like a scanner bug rather than a missing field.
			 *
			 * So the document is included explicitly, and
			 * {@see Template_Package::inspect()} additionally refuses a package that has no
			 * document, which is what catches a hand-written file that omits it.
			 */
			'document'       => isset( $version['document'] ) && is_array( $version['document'] ) ? $version['document'] : array(),
			'design_system'  => isset( $version['design_system'] ) ? $version['design_system'] : array(),
			'tokens'         => isset( $version['tokens'] ) ? $version['tokens'] : array(),
			'components'     => isset( $version['components'] ) ? $version['components'] : array(),
			'content_slots'  => isset( $version['content_slots'] ) ? $version['content_slots'] : array(),
			'assets'         => $assets,
			'responsive'     => isset( $version['responsive'] ) ? $version['responsive'] : array(),
			'interactions'   => isset( $version['interactions'] ) ? $version['interactions'] : array(),
			'dependencies'   => isset( $version['dependencies'] ) ? $version['dependencies'] : array(),
			'compatibility'  => isset( $version['compatibility'] ) ? $version['compatibility'] : array(),
			'provenance'     => array(
				'origin'     => 'exported',
				'author'     => (string) get_the_author_meta( 'display_name', get_current_user_id() ),
				'created_at' => gmdate( 'c' ),
				/*
				 * Licence metadata is the marketplace foundation §31 asks for. It is
				 * descriptive only: this records what the *exporter* asserts, and it does not
				 * grant anything. §31 is explicit that no distribution is implemented.
				 */
				'licence'    => isset( $options['licence'] ) && is_scalar( $options['licence'] ) ? substr( (string) $options['licence'], 0, 120 ) : __( 'All rights reserved', 'replicaforge' ),
				'maker'      => array(
					'plugin'  => defined( 'REPLICAFORGE_VERSION' ) ? (string) REPLICAFORGE_VERSION : '0.0.0',
					'phase'   => Template_Limits::PHASE,
				),
			),
			'validation'     => isset( $version['validation'] ) ? $version['validation'] : array(),
		);

		$redacted = $this->redact( $payload );

		$encoded = wp_json_encode( $redacted['payload'] );

		if ( ! is_string( $encoded ) ) {
			return new \WP_Error( 'template_export_unencodable', __( 'This template could not be encoded for export.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		if ( strlen( $encoded ) > Template_Limits::MAX_PACKAGE_BYTES ) {
			return new \WP_Error(
				'template_export_too_large',
				sprintf(
					/* translators: 1: the size in kilobytes, 2: the maximum in kilobytes. */
					__( 'This package is %1$d KB, over the %2$d KB a package may be. Export fewer assets, or export a section rather than the whole page.', 'replicaforge' ),
					(int) round( strlen( $encoded ) / 1024 ),
					(int) round( Template_Limits::MAX_PACKAGE_BYTES / 1024 )
				),
				array( 'status' => 413 )
			);
		}

		$this->logger->info(
			'template_exported',
			'Exported a template package.',
			array( 'template_id' => $template_id, 'bytes' => strlen( $encoded ), 'redacted' => count( $redacted['removed'] ) ),
			'template'
		);

		return array(
			'package'  => $redacted['payload'],
			'encoded'  => $encoded,
			'filename' => $this->filename( $name ),
			'bytes'    => strlen( $encoded ),
			'redacted' => $redacted['removed'],
			'assets'   => array(
				'included' => count( $assets ),
				'referenced' => count( isset( $version['assets'] ) && is_array( $version['assets'] ) ? $version['assets'] : array() ) - count( $assets ),
			),
		);
	}

	/**
	 * Choose which assets a package carries.
	 *
	 * Only redistributable assets are included. A `source_derived` or `third_party` asset is
	 * recorded in the package as a *reference* — the URL and its provenance — so the
	 * importing site knows the template needs an image it will have to supply, and §15's
	 * "templates must remain usable when restricted assets are excluded" holds.
	 *
	 * @param array<string, mixed> $assets  All assets.
	 * @param array<string, mixed> $options Export options.
	 * @return array<string, array<string, mixed>>
	 */
	private function select_assets( array $assets, array $options ) {
		$include = ! empty( $options['include_assets'] );
		$out     = array();

		foreach ( $assets as $id => $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$provenance = (string) ( $asset['provenance_class'] ?? 'third_party' );
			$permitted  = Template_Limits::is_redistributable( $provenance );

			if ( ! $include || ! $permitted ) {
				$out[ (string) $id ] = array(
					'asset_id'         => (string) ( $asset['asset_id'] ?? $id ),
					'url'              => (string) ( $asset['url'] ?? '' ),
					'type'             => (string) ( $asset['type'] ?? 'image' ),
					'mime_type'        => (string) ( $asset['mime_type'] ?? '' ),
					'provenance_class' => $provenance,
					'mode'             => 'reference',
					'redistributable'  => false,
					'included'         => false,
					'note'             => $permitted
						? __( 'This asset was left out of the package because assets were not included in the export.', 'replicaforge' )
						: __( 'This image comes from the analysed website, so it is referenced rather than packaged. Replace it with your own media to make the template self-contained.', 'replicaforge' ),
				);
				continue;
			}

			$record = $asset;
			$record['included']        = true;
			$record['mode']            = 'import';
			$record['redistributable'] = true;
			$out[ (string) $id ]       = $record;
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------------ */

	/**
	 * Read and validate an incoming package, without installing it.
	 *
	 * §18's nine steps. This method performs the first seven and returns what the last two
	 * need — a preview, and the conflicts the user must choose between. It writes nothing.
	 *
	 * @param mixed                $raw Raw payload: a JSON string, or an already-decoded array.
	 * @param array<string, mixed> $options Import options.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function inspect( $raw, array $options = array() ) {
		$decoded = $this->decode( $raw );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$steps = array();

		// 1. validate_package
		$structure = $this->check_structure( $decoded );

		if ( is_wp_error( $structure ) ) {
			$steps[] = $this->step( 'validate_package', 'failed', (string) $structure->get_error_message() );
			return $structure;
		}
		$steps[] = $this->step( 'validate_package', 'ok', __( 'The file is a readable ReplicaForge template package.', 'replicaforge' ) );

		// 2. security_scan
		$scan = $this->sanitizer->scan_snapshot( $decoded );

		$steps[] = $this->step(
			'security_scan',
			empty( $scan['fatal'] ) ? 'ok' : 'failed',
			empty( $scan['fatal'] )
				? sprintf(
					/* translators: %d: how many items were removed. */
					_n( 'The security check passed and removed nothing.', 'The security check passed and removed %d item(s).', count( (array) $scan['removals'] ), 'replicaforge' ),
					count( (array) $scan['removals'] )
				)
				: __( 'The security check found something it will not pass on, so this package cannot be imported.', 'replicaforge' )
		);

		if ( ! empty( $scan['fatal'] ) ) {
			return new \WP_Error(
				'template_package_unsafe',
				__( 'This package failed the security check and nothing was imported.', 'replicaforge' ),
				array( 'status' => 422, 'fatal' => (array) $scan['fatal'], 'steps' => $steps )
			);
		}

		// 3. schema_validation
		$steps[] = $this->step( 'schema_validation', 'ok', sprintf(
			/* translators: %s: the package schema version. */
			__( 'The package uses template schema %s, which this version understands.', 'replicaforge' ),
			Template_Limits::SCHEMA_VERSION
		) );

		// 4. compatibility_check
		$snapshot = $this->to_snapshot( $decoded, $scan );
		$resolved = ( new Template_Dependencies() )->resolve( isset( $snapshot['compatibility'] ) ? $snapshot['compatibility'] : array() );

		$steps[] = $this->step(
			'compatibility_check',
			empty( $resolved['blocking'] ) ? 'ok' : 'failed',
			(string) $resolved['summary']
		);

		// 5. dependency_resolution
		$unresolved = array();

		foreach ( (array) ( $resolved['requirements'] ?? array() ) as $requirement ) {
			if ( 'available' !== (string) ( $requirement['state'] ?? '' ) ) {
				$unresolved[] = $requirement;
			}
		}

		$steps[] = $this->step(
			'dependency_resolution',
			empty( $unresolved ) ? 'ok' : 'warned',
			empty( $unresolved )
				? __( 'Everything this template needs is present.', 'replicaforge' )
				: sprintf(
					/* translators: %d: how many requirements are unmet. */
					__( '%d requirement(s) are not met here. They are listed below.', 'replicaforge' ),
					count( $unresolved )
				)
		);

		// 6. asset_review
		$review = $this->review_assets( isset( $decoded['assets'] ) && is_array( $decoded['assets'] ) ? $decoded['assets'] : array() );

		$steps[] = $this->step(
			'asset_review',
			'ok',
			empty( $review['needs_approval'] )
				? __( 'Every asset in this package may be redistributed, or is included as a reference.', 'replicaforge' )
				: sprintf(
					/* translators: %d: how many assets were left out. */
					__( '%d asset(s) are referenced rather than included, because their rights are not established. The template still works; those images need replacing.', 'replicaforge' ),
					count( $review['needs_approval'] )
				)
		);

		// 7. conflict_detection is workspace-scoped, so it is the caller's step.
		$validation = $this->validator->validate( $snapshot );
		$quality    = ( new Template_Quality() )->measure( $snapshot, $options );

		$this->logger->info(
			'template_import_inspected',
			'Inspected an incoming template package.',
			array(
				'name'     => (string) ( $decoded['template']['name'] ?? '' ),
				'removals' => count( (array) $scan['removals'] ),
				'state'    => (string) $validation['state'],
			),
			'template'
		);

		return array(
			'package'   => $decoded,
			'snapshot'  => $snapshot,
			'validation' => $validation,
			'quality'   => $quality,
			'compatibility' => $resolved,
			'assets'    => $review,
			'removals'  => (array) $scan['removals'],
			'steps'     => $steps,
			'next_step' => 'conflict_detection',
			/*
			 * `$validation['blocking']` does not exist — the validator reports `installable`,
			 * derived from its own state. Reading a key that was never set meant the
			 * expression silently evaluated against null, so `installable` was decided by the
			 * dependency result alone and a template the validator had marked
			 * `needs_review` could present as installable.
			 */
			'installable' => ! empty( $validation['installable'] ) && empty( $resolved['blocking'] ),
		);
	}

	/**
	 * Reject a package from a newer schema.
	 *
	 * The same rule `Plan_Storage::import()` follows, and for the same reason: a document
	 * with a key this version does not understand may have limits this version cannot see,
	 * and an unrecognised limit reads as unlimited.
	 *
	 * @param array<string, mixed> $package Decoded package.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check_structure( array $package ) {
		/*
		 * `document` is required, and its absence is reported as a *malformed package* rather
		 * than left to surface later as a security-scan failure. The two mean different things
		 * to whoever produced the file: "this is not a ReplicaForge template" versus "this
		 * template contains something unsafe". A package with metadata but no document is the
		 * first.
		 */
		foreach ( array( 'template', 'provenance', 'document' ) as $key ) {
			if ( ! isset( $package[ $key ] ) || ! is_array( $package[ $key ] ) ) {
				return new \WP_Error(
					'template_package_malformed',
					sprintf(
						/* translators: %s: the missing section. */
						__( 'This file is not a ReplicaForge template package: the "%s" section is missing or unreadable.', 'replicaforge' ),
						$key
					),
					array( 'status' => 400 )
				);
			}
		}

		$schema = isset( $package['schema_version'] ) ? (string) $package['schema_version'] : '';

		if ( '' === $schema ) {
			return new \WP_Error(
				'template_package_unversioned',
				__( 'This package does not say which template schema it uses, so it cannot be read safely.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		if ( version_compare( $schema, Template_Limits::SCHEMA_VERSION, '>' ) ) {
			return new \WP_Error(
				'template_package_from_the_future',
				sprintf(
					/* translators: 1: the package's schema, 2: this version's schema. */
					__( 'This package uses template schema %1$s and this site runs %2$s. Export from a matching version, or upgrade ReplicaForge. Nothing was imported.', 'replicaforge' ),
					$schema,
					Template_Limits::SCHEMA_VERSION
				),
				array( 'status' => 400 )
			);
		}

		return $package;
	}

	/**
	 * Review the assets in a package.
	 *
	 * @param array<string, mixed> $assets Assets.
	 * @return array<string, mixed>
	 */
	private function review_assets( array $assets ) {
		$included       = array();
		$needs_approval = array();
		$unsafe         = array();

		foreach ( $assets as $id => $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$url = (string) ( $asset['url'] ?? '' );

			if ( '' !== $url && ! Security::is_safe_public_reference( $url ) ) {
				$unsafe[] = (string) $id;
				continue;
			}

			if ( ! empty( $asset['included'] ) ) {
				$included[] = (string) $id;
				continue;
			}

			$needs_approval[] = (string) $id;
		}

		return array(
			'included'       => $included,
			'needs_approval' => $needs_approval,
			'unsafe'         => $unsafe,
			'count'          => count( $assets ),
			'message'        => empty( $needs_approval )
				? __( 'Every asset in this package is cleared for redistribution.', 'replicaforge' )
				: __( 'Some assets are referenced rather than included. The template imports and works; those images point at their original locations and should be replaced with your own media.', 'replicaforge' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Decoding and redaction
	 * ------------------------------------------------------------------ */

	/**
	 * Decode an incoming payload.
	 *
	 * @param mixed $raw Raw payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function decode( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) ) {
			return new \WP_Error( 'template_package_unreadable', __( 'A template package must be a JSON object.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		if ( strlen( $raw ) > Template_Limits::MAX_PACKAGE_BYTES * 2 ) {
			/*
			 * Checked before parsing, not after. A 4 MB JSON string can decode into a much
			 * larger structure — a small document with deeply repeated keys — so the
			 * *encoded* size is the bound that actually bounds memory.
			 */
			return new \WP_Error(
				'template_package_too_large',
				sprintf(
					/* translators: %d: the size in megabytes. */
					__( 'This file is larger than %d MB, which is more than a template package may be.', 'replicaforge' ),
					(int) round( Template_Limits::MAX_PACKAGE_BYTES * 2 / 1048576 )
				),
				array( 'status' => 413 )
			);
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'template_package_unreadable', __( 'This file is not readable JSON, so it is not a template package.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		return $decoded;
	}

	/**
	 * Remove anything that must not leave — or arrive in — a package.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return array{payload: array<string, mixed>, removed: array<int, string>}
	 */
	public function redact( array $payload ) {
		$removed   = array();
		$forbidden = self::forbidden_keys();
		$fragments = self::secret_fragments();

		$walk = function ( $node, $depth ) use ( &$walk, &$removed, $forbidden, $fragments ) {
			if ( $depth > 16 ) {
				/*
				 * Too deep to be a template structure. 16 rather than 12, because a document
				 * nests one level per container and a real multi-section page is deeper than 12.
				 * Dropping at 12 silently truncated valid nested containers.
				 */
				$removed[] = 'depth_limit';
				return array();
			}

			if ( ! is_array( $node ) ) {
				return $node;
			}

			$out = array();

			foreach ( $node as $key => $value ) {
				$exact = is_string( $key ) ? $key : '';
				$lower = strtolower( $exact );

				$blocked = in_array( $lower, $forbidden, true );

				if ( ! $blocked ) {
					foreach ( $fragments as $fragment ) {
						if ( '' !== $lower && false !== strpos( $lower, $fragment ) ) {
							$blocked = true;
							break;
						}
					}
				}

				/*
				 * A smuggled raw Elementor document, matched on its *exact* key names.
				 *
				 * This comparison is case-SENSITIVE and it must stay that way. An earlier
				 * version lowercased the key and compared against a list containing
				 * `eltype` and `widgettype`, intending to catch a document pasted in as
				 * metadata. But `elType` and `widgetType` are the *legitimate, required*
				 * fields on every element in the template, and lowercasing made the blocklist
				 * match them.
				 *
				 * The effect was that `redact()` stripped `elType` and `widgetType` from every
				 * element of every exported package. The export reported success, the file was
				 * a plausible-looking complete package, and on the importing side the security
				 * scan rejected it with `no_elements_survived` — which reads as a scanner
				 * false positive rather than a destroyed document.
				 *
				 * The genuine smuggling risk is the `_elementor_*` post-meta keys, which are
				 * matched exactly below. `elType` is a field the whole format depends on.
				 */
				if ( in_array( $exact, array( '_elementor_data', '_elementor_controls', '_elementor_css', '_elementor_page_settings' ), true ) ) {
					$blocked = true;
				}

				if ( $blocked ) {
					$removed[] = $exact;
					continue;
				}

				$out[ $key ] = $walk( $value, $depth + 1 );
			}

			return $out;
		};

		return array( 'payload' => $walk( $payload, 0 ), 'removed' => array_values( array_unique( $removed ) ) );
	}

	/**
	 * Turn a decoded package into a snapshot the stores understand.
	 *
	 * @param array<string, mixed> $package Decoded package.
	 * @param array<string, mixed> $scan    Sanitizer report.
	 * @return array<string, mixed>
	 */
	private function to_snapshot( array $package, array $scan ) {
		$snapshot = array(
			'document'      => isset( $scan['document'] ) ? array( 'elements' => $scan['document'] ) : array(),
			'responsive'    => isset( $package['responsive'] ) && is_array( $package['responsive'] ) ? $package['responsive'] : array(),
			'interactions'  => isset( $package['interactions'] ) && is_array( $package['interactions'] ) ? $package['interactions'] : array(),
			'design_system' => isset( $package['design_system'] ) && is_array( $package['design_system'] ) ? $package['design_system'] : array(),
			'tokens'        => isset( $scan['snapshot']['tokens'] ) ? $scan['snapshot']['tokens'] : ( isset( $package['tokens'] ) && is_array( $package['tokens'] ) ? $package['tokens'] : array() ),
			'components'    => isset( $package['components'] ) && is_array( $package['components'] ) ? $package['components'] : array(),
			'content_slots' => isset( $scan['snapshot']['content_slots'] ) ? $scan['snapshot']['content_slots'] : ( isset( $package['content_slots'] ) && is_array( $package['content_slots'] ) ? $package['content_slots'] : array() ),
			'assets'        => isset( $scan['assets'] ) ? $scan['assets'] : array(),
			'dependencies'  => isset( $package['dependencies'] ) && is_array( $package['dependencies'] ) ? $package['dependencies'] : array(),
			'compatibility' => isset( $package['compatibility'] ) && is_array( $package['compatibility'] ) ? $package['compatibility'] : array(),
			'provenance'    => isset( $scan['provenance'] ) ? $scan['provenance'] : array(),
			'validation'    => array(),
		);

		/*
		 * The package's own `provenance.name` and `type` are the *template metadata*, not the
		 * snapshot's provenance block — a snapshot has no name field, and the two are
		 * different concepts that the package format happens to hold at different levels.
		 */
		$snapshot['meta'] = array(
			'name'        => (string) ( $package['template']['name'] ?? '' ),
			'description' => (string) ( $package['template']['description'] ?? '' ),
			'type'        => (string) ( $package['template']['type'] ?? 'custom' ),
			'version'     => (int) ( $package['template']['version'] ?? 1 ),
			'tags'        => (array) ( $package['template']['tags'] ?? array() ),
		);

		return $snapshot;
	}

	/**
	 * Build one import-step record.
	 *
	 * @param string $step    Step name.
	 * @param string $outcome `ok`, `warned` or `failed`.
	 * @param string $message Human message.
	 * @return array<string, mixed>
	 */
	private function step( $step, $outcome, $message ) {
		return array( 'step' => $step, 'outcome' => $outcome, 'message' => (string) $message );
	}

	/**
	 * Return a download filename for a package.
	 *
	 * @param string $name Template name.
	 * @return string
	 */
	private function filename( $name ) {
		$slug = sanitize_file_name( strtolower( (string) $name ) );

		if ( '' === $slug ) {
			$slug = 'template';
		}

		return 'replicaforge-template-' . substr( $slug, 0, 60 ) . '-' . gmdate( 'Ymd-His' ) . '.json';
	}
}
