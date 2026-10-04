<?php
/**
 * Safe asset resolution for ReplicaForge Phase 4.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves specification asset references into usable, safe image sources.
 *
 * ReplicaForge never assumes that a publicly reachable image may be copied into
 * a client's media library. Importing is explicit, bounded, MIME verified, and
 * limited to raster media types. When an asset cannot be imported safely the
 * original state is preserved as a reference or a blocked record so the report
 * can describe exactly what happened.
 */
final class Elementor_Assets {

	/**
	 * Bounded HTTP client.
	 *
	 * @var Http_Client
	 */
	private $http_client;

	/**
	 * Reference extensions that may be used without importing.
	 *
	 * @var array<int, string>
	 */
	private $reference_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

	/**
	 * Extensions that are never referenced or imported.
	 *
	 * @var array<int, string>
	 */
	private $blocked_extensions = array(
		'svg', 'svgz', 'php', 'phtml', 'phar', 'js', 'mjs', 'html', 'htm', 'xhtml', 'xml',
		'json', 'zip', 'gz', 'tar', 'rar', 'exe', 'dll', 'bin', 'sh', 'bat', 'cmd',
		'pdf', 'swf', 'jar', 'apk', 'mp4', 'webm', 'ico', 'cur', 'eot', 'ttf', 'otf', 'woff', 'woff2',
	);

	/**
	 * Total bytes downloaded during the current run.
	 *
	 * @var int
	 */
	private $total_bytes = 0;

	/**
	 * Number of assets imported during the current run.
	 *
	 * @var int
	 */
	private $imported_count = 0;

	/**
	 * Provenance metadata attached to every imported attachment.
	 *
	 * @var array<string, mixed>
	 */
	private $context = array();

	/**
	 * Constructor.
	 *
	 * @param Http_Client|null $http_client Optional bounded HTTP client.
	 */
	public function __construct( $http_client = null ) {
		$this->http_client = $http_client instanceof Http_Client ? $http_client : new Http_Client( new Url_Validator() );
	}

	/**
	 * Resolve every asset reference in a normalized plan.
	 *
	 * @param array<string, mixed> $assets  Planned assets keyed by component ID.
	 * @param array<string, mixed> $options `import_assets` and `generation_id`.
	 * @return array{states: array<string, array<string, mixed>>, summary: array<string, mixed>, warnings: array<int, string>}
	 */
	public function resolve( array $assets, array $options = array() ) {
		$this->total_bytes    = 0;
		$this->imported_count = 0;
		$this->context        = array(
			'generation_id' => isset( $options['generation_id'] ) && is_string( $options['generation_id'] ) ? $options['generation_id'] : '',
			'imported_at'   => gmdate( 'c' ),
		);

		/*
		 * The import gate is the feature flag, not the request.
		 *
		 * `$options['import_assets']` originates as a REST boolean
		 * (`Job_Api:413 -> $request->get_param( 'import_assets' )`) with nothing in between
		 * to constrain it. `Feature_Flags` declares `advanced_asset_import_enabled` for
		 * exactly this, defaulting to `false`, and nothing in the plugin has ever read it.
		 *
		 * That combination is the worst of both: the flag that an administrator would switch
		 * off to stop asset importing gates nothing, while the switch that actually controls it
		 * is an unvalidated request parameter. A flag whose name says it gates something and
		 * does not is worse than no flag, because it is read as a control that works.
		 *
		 * Both are now required. Importing writes files into `wp-content/uploads`, so the flag
		 * defaults to off and a request alone cannot turn it on.
		 */
		$requested = ! empty( $options['import_assets'] );
		$permitted = ( new Feature_Flags() )->enabled( 'advanced_asset_import_enabled' );
		$import    = $requested && $permitted;

		$states  = array();
		$summary = array(
			'detected'   => 0,
			'imported'   => 0,
			'referenced' => 0,
			'blocked'    => 0,
			'failed'     => 0,
			'unavailable' => 0,
			'bytes'      => 0,
		);
		$warnings = array();

		if ( $requested && ! $permitted ) {
			/*
			 * Downgraded to reference-only rather than refused outright. The generated page
			 * still needs its images, and referencing the original remote URL produces a
			 * working page without writing anything to disk - which is the `pending` /
			 * `remote_reference_not_copied` path the non-import branch already handles.
			 *
			 * The reason goes into the returned warnings rather than the log, because the
			 * warnings are what the caller surfaces to the person who asked for the import.
			 * A log line nobody reads does not explain a generation that did not import.
			 */
			$warnings[] = __( 'Asset import was requested, but importing images into this site is switched off. The original images are referenced instead of copied, so the page is not self-contained. An administrator can enable advanced asset import in the ReplicaForge settings.', 'replicaforge' );
		}

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['component_id'] ) ) {
				continue;
			}
			if ( $summary['detected'] >= Elementor_Limits::MAX_ASSETS ) {
				$warnings[] = __( 'Some assets were not processed because the Phase 4 asset limit was reached.', 'replicaforge' );
				break;
			}
			$summary['detected']++;

			$state  = $this->resolve_one( $asset, $import, $warnings );
			$status = (string) $state['import_status'];
			if ( 'pending' === $status ) {
				$summary['referenced']++;
			} elseif ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
			if ( 'failed' === $status ) {
				$warnings[] = __( 'An image could not be copied into the media library. It stays referenced from the source website and is listed in the report.', 'replicaforge' );
			}
			$states[ (string) $asset['component_id'] ] = $state;
		}

		$summary['imported'] = $this->imported_count;
		$summary['bytes']    = $this->total_bytes;

		return array(
			'states'   => $states,
			'summary'  => $summary,
			'warnings' => array_values( array_unique( $warnings ) ),
		);
	}

	/**
	 * Resolve a single asset reference.
	 *
	 * @param array<string, mixed> $asset    Planned asset.
	 * @param bool                 $import   Whether importing was requested.
	 * @param array<int, string>   $warnings Warning collector.
	 * @return array<string, mixed>
	 */
	private function resolve_one( array $asset, $import, array &$warnings ) {
		$state  = $this->initial_state( $asset );
		$source = (string) $asset['source_url'];

		if ( '' === $source ) {
			$state['import_status'] = 'unavailable';
			$state['reason']         = 'asset_unavailable_or_unsafe';
			return $state;
		}

		if ( ! $this->is_safe_reference( $source ) ) {
			$state['import_status'] = 'blocked';
			$state['reason']         = 'asset_url_blocked';
			return $state;
		}

		$extension = $this->extension( $source );
		if ( in_array( $extension, $this->blocked_extensions, true ) ) {
			$state['import_status'] = 'blocked';
			$state['reason']         = 'unsupported_asset_type';
			$warnings[]              = __( 'An asset type that cannot be verified as safe media was blocked. It was neither imported nor referenced.', 'replicaforge' );
			return $state;
		}

		if ( ! $import ) {
			if ( '' !== $extension && ! in_array( $extension, $this->reference_extensions, true ) ) {
				$state['import_status'] = 'blocked';
				$state['reason']         = 'unsupported_asset_type';
				return $state;
			}
			$state['import_status'] = 'pending';
			$state['url']           = $source;
			$state['reason']        = 'remote_reference_not_copied';
			return $state;
		}

		if ( $this->imported_count >= Elementor_Limits::MAX_IMPORTED_ASSETS ) {
			$state['import_status'] = 'pending';
			$state['url']           = $source;
			$state['reason']        = 'import_limit_reached';
			$warnings[]            = __( 'The asset import limit was reached. Remaining images are referenced from the source website instead of being copied.', 'replicaforge' );
			return $state;
		}

		if ( $this->total_bytes >= Elementor_Limits::MAX_TOTAL_ASSET_BYTES ) {
			$state['import_status'] = 'pending';
			$state['url']           = $source;
			$state['reason']        = 'import_budget_reached';
			$warnings[]            = __( 'The total asset import budget was reached. Remaining images are referenced from the source website instead of being copied.', 'replicaforge' );
			return $state;
		}

		return $this->import_asset( $source, $state );
	}

	/**
	 * Return a safe, explicit state when a detail is missing.
	 *
	 * @param array<string, mixed> $asset Planned asset.
	 * @return array<string, mixed>
	 */
	private function initial_state( array $asset ) {
		return array(
			'component_id'  => isset( $asset['component_id'] ) ? (string) $asset['component_id'] : '',
			'url'           => '',
			'attachment_id' => 0,
			'bytes'         => 0,
			'source_type'   => 'remote',
			'source_url'    => isset( $asset['source_url'] ) ? (string) $asset['source_url'] : '',
			'usage'         => isset( $asset['usage'] ) ? (string) $asset['usage'] : 'content_image',
			'import_status' => 'blocked',
			'reason'        => '',
			'provenance'    => 'source_website',
		);
	}

	/**
	 * Download and store one asset with strict limits.
	 *
	 * @param string               $source Source URL.
	 * @param array<string, mixed> $state  Initial state.
	 * @return array<string, mixed>
	 */
	private function import_asset( $source, array $state ) {
		$response = $this->http_client->fetch_media(
			$source,
			array(
				'max_size'      => Elementor_Limits::MAX_ASSET_BYTES,
				'timeout'       => Elementor_Limits::ASSET_TIMEOUT,
				'max_redirects' => Elementor_Limits::ASSET_MAX_REDIRECTS,
			)
		);

		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			$code = is_array( $response ) && isset( $response['error']['code'] ) ? (string) $response['error']['code'] : 'asset_fetch_failed';
			Security::log_event( 'asset_import_failed', array( 'code' => $code, 'reason' => 'asset' ) );
			$state['import_status'] = 'failed';
			$state['reason']        = $this->safe_reason( $code );
			$state['url']           = $source;
			return $state;
		}

		$body        = isset( $response['body'] ) && is_string( $response['body'] ) ? $response['body'] : '';
		$content_type = isset( $response['content_type'] ) ? strtolower( (string) $response['content_type'] ) : '';
		$type        = $this->media_type( $content_type );

		if ( '' === $type || ! isset( Elementor_Limits::ALLOWED_MEDIA_TYPES[ $type ] ) ) {
			Security::log_event( 'asset_import_failed', array( 'code' => 'unsupported_media_type', 'reason' => 'asset' ) );
			$state['import_status'] = 'blocked';
			$state['reason']        = 'unsupported_media_type';
			$state['url']           = $source;
			return $state;
		}

		$attachment_id = $this->store_media( $body, $source, $type );
		if ( ! is_int( $attachment_id ) || $attachment_id <= 0 ) {
			Security::log_event( 'asset_import_failed', array( 'code' => 'media_store_failed', 'reason' => 'asset' ) );
			$state['import_status'] = 'failed';
			$state['reason']        = 'media_store_failed';
			$state['url']           = $source;
			return $state;
		}

		$this->imported_count++;
		$this->total_bytes += strlen( $body );

		$url = wp_get_attachment_url( $attachment_id );
		$url = is_string( $url ) && '' !== $url ? $url : $source;

		$state['attachment_id'] = $attachment_id;
		$state['url']           = $url;
		$state['bytes']         = strlen( $body );
		$state['import_status'] = 'imported';
		$state['reason']        = '';
		$state['provenance']    = 'source_website';
		$this->store_provenance( $attachment_id, $state );

		return $state;
	}

	/**
	 * Store verified bytes in the media library.
	 *
	 * @param string $body        Verified image bytes.
	 * @param string $source      Source URL.
	 * @param string $type        Verified media type.
	 * @return int Attachment ID, or 0 on failure.
	 */
	private function store_media( $body, $source, $type ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			if ( ! defined( 'ABSPATH' ) || ! file_exists( ABSPATH . 'wp-admin/includes/media.php' ) ) {
				return 0;
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$uploads = wp_upload_bits( sanitize_file_name( $this->file_name( $source ) ), null, $body );
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['file'] ) ) {
			return 0;
		}

		$filetype = wp_check_filetype( $uploads['file'], null );
		$allowed  = Elementor_Limits::ALLOWED_MEDIA_TYPES;
		if ( ! is_array( $filetype ) || empty( $filetype['type'] ) || ! isset( $allowed[ $filetype['type'] ] ) ) {
			$this->remove_file( $uploads['file'] );
			return 0;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'],
				'post_title'     => $this->file_name( $source ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$uploads['file']
		);

		if ( ! is_int( $attachment_id ) || $attachment_id <= 0 || is_wp_error( $attachment_id ) ) {
			$this->remove_file( $uploads['file'] );
			return 0;
		}

		if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $uploads['file'] );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		}

		return $attachment_id;
	}

	/**
	 * Store provenance metadata on an imported attachment.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $state         Asset state.
	 * @return void
	 */
	private function store_provenance( $attachment_id, array $state ) {
		$provenance = array(
			'source_url'    => (string) $state['source_url'],
			'source_type'   => 'remote',
			'import_status' => 'imported',
			'provenance'    => 'source_website',
			'usage'         => (string) $state['usage'],
			'generation_id' => (string) $this->context['generation_id'],
			'imported_at'   => (string) $this->context['imported_at'],
			'plugin'        => 'replicaforge',
		);
		$encoded = wp_json_encode( $provenance );
		if ( is_string( $encoded ) ) {
			update_post_meta( $attachment_id, '_replicaforge_provenance', wp_slash( $encoded ) );
		}
	}

	/**
	 * Delete a temporary upload that could not be used.
	 *
	 * @param string $path Absolute file path.
	 * @return void
	 */
	private function remove_file( $path ) {
		$uploads = wp_get_upload_dir();
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) || ! is_string( $path ) ) {
			return;
		}
		$basedir = wp_normalize_path( $uploads['basedir'] );
		$target  = wp_normalize_path( $path );
		if ( '' !== $basedir && 0 === strpos( $target, $basedir ) && file_exists( $target ) ) {
			wp_delete_file( $target );
		}
	}

	/**
	 * Return the normalized media type of a response.
	 *
	 * @param string $content_type Response content type.
	 * @return string
	 */
	private function media_type( $content_type ) {
		$content_type = strtolower( trim( (string) $content_type ) );
		if ( '' === $content_type ) {
			return '';
		}
		$type = trim( (string) preg_replace( '/[;\s].*$/', '', $content_type ) );
		return preg_match( '#^image/[a-z0-9.+-]{1,40}$#', $type ) ? $type : '';
	}

	/**
	 * Return true when a URL may be referenced without importing.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private function is_safe_reference( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		return Security::is_safe_public_reference( $url );
	}

	/**
	 * Return the lowercase file extension of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function extension( $url ) {
		$parts = Security::parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return '';
		}
		$path      = (string) $parts['path'];
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		return preg_match( '/^[a-z0-9]{1,10}$/', $extension ) ? $extension : '';
	}

	/**
	 * Build a safe file name from a source URL.
	 *
	 * @param string $url Source URL.
	 * @return string
	 */
	private function file_name( $url ) {
		$parts = Security::parse_url( $url );
		$path  = is_array( $parts ) && ! empty( $parts['path'] ) ? (string) $parts['path'] : '';
		$name  = basename( $path );
		$name  = preg_replace( '/[^A-Za-z0-9._-]/', '-', $name );
		$name  = ltrim( (string) $name, '-.' );
		if ( '' === $name || strlen( $name ) > 80 ) {
			$name = 'replicaforge-image';
		}
		if ( ! preg_match( '/\.(?:jpe?g|png|gif|webp)$/i', $name ) ) {
			$name .= '.jpg';
		}
		return $name;
	}

	/**
	 * Convert a transport error code into a safe, short reason.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	private function safe_reason( $code ) {
		$code = sanitize_key( (string) $code );
		return '' !== $code ? $code : 'asset_fetch_failed';
	}
}
