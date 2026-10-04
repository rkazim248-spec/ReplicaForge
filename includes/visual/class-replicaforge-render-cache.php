<?php
/**
 * Phase 13: render and visual caching.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Caches screenshots and visual analysis, with a key that says how it was produced.
 *
 * ### The key is the whole design
 *
 * §56 lists what a cache key must include. Every entry is there because omitting it
 * produces a *plausible wrong answer* rather than a cache miss:
 *
 * - **`renderer_version`** — a renderer upgrade can change what a screenshot contains.
 *   Without it, a cached screenshot from the old build is served after the upgrade
 *   and looks like a fresh capture that happens to be wrong.
 * - **`analyzer_version`** — the same argument for the analysis, and the reason
 *   {@see Visual_Limits::ANALYZER_VERSION} exists as a constant that must be bumped.
 * - **`normalization`** — a DPR or a masking change alters the numbers, and a cached
 *   number from before the change is not a smaller version of the new one.
 * - **`source_hash`** — the page actually changed.
 *
 * A key missing any of these is a key that returns a stale answer with a current
 * timestamp, which is the one thing a cache must never do.
 *
 * ### Screenshots are not held forever
 *
 * §57. A screenshot is large and it is evidence for a *specific* finding; three weeks
 * later it is not what anyone opens the report to see. So capture records carry a TTL,
 * the cache is pruned on read as well as on write, and a store with no reader is
 * swept by the Phase 7 maintenance job.
 */
final class Render_Cache {

	/**
	 * Cache option.
	 *
	 * @var string
	 */
	const OPTION = 'replicaforge_visual_cache';

	/**
	 * Index of stored captures and analyses.
	 *
	 * @var string
	 */
	const INDEX_OPTION = 'replicaforge_visual_cache_index';

	/**
	 * Maximum entries.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 120;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Optional logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Build a cache key.
	 *
	 * @param string               $project_id  Project id.
	 * @param string               $source_url  Source URL.
	 * @param array<string, mixed> $parts       Key parts.
	 * @return string
	 */
	public function key( $project_id, $source_url, array $parts = array() ) {
		$material = array(
			'project'    => (string) $project_id,
			'source'     => (string) $source_url,
			'source_hash'=> (string) ( $parts['source_hash'] ?? '' ),
			'generated_hash' => (string) ( $parts['generated_hash'] ?? '' ),
			'viewport'   => (string) ( $parts['viewport'] ?? 'desktop' ),
			'width'      => (int) ( $parts['width'] ?? 0 ),
			'height'     => (int) ( $parts['height'] ?? 0 ),
			'dpr'        => (string) ( $parts['device_pixel_ratio'] ?? '1' ),
			'renderer'   => (string) ( $parts['renderer'] ?? '' ),
			'renderer_version' => (string) ( $parts['renderer_version'] ?? '' ),
			'analyzer'   => Visual_Limits::ANALYZER_VERSION,
			'normalization' => (string) ( $parts['normalization'] ?? '' ),
			'masked'     => ! empty( $parts['masked'] ),
			'kind'       => (string) ( $parts['kind'] ?? 'analysis' ),
		);

		return 'rfv_' . substr( hash( 'sha256', (string) wp_json_encode( $material ) ), 0, 32 );
	}

	/**
	 * Return the key parts derived from a request.
	 *
	 * @param array<string, mixed> $request Request.
	 * @return array<string, mixed>
	 */
	public function parts_from( array $request ) {
		$viewport = isset( $request['viewport'] ) && is_array( $request['viewport'] ) ? $request['viewport'] : array();
		$masks    = (array) ( $request['masks'] ?? array() );
		$reasons  = array();
		foreach ( $masks as $mask ) {
			if ( is_array( $mask ) && ! empty( $mask['reason'] ) ) {
				$reasons[] = (string) $mask['reason'];
			}
		}
		sort( $reasons );

		return array(
			'source_hash'    => (string) ( $request['source_hash'] ?? '' ),
			'generated_hash' => (string) ( $request['generated_hash'] ?? '' ),
			'viewport'       => (string) ( $viewport['name'] ?? 'desktop' ),
			'width'          => (int) ( $viewport['width'] ?? 0 ),
			'height'         => (int) ( $viewport['height'] ?? 0 ),
			'device_pixel_ratio' => (string) ( $viewport['device_pixel_ratio'] ?? '1' ),
			'renderer'       => (string) ( $request['renderer'] ?? '' ),
			'renderer_version' => (string) ( $request['renderer_version'] ?? '' ),
			'normalization'  => (string) ( $request['normalization'] ?? ( ! empty( $request['animation_normalized'] ) ? 'animations_off' : 'none' ) ),
			'masked'         => ( array() !== $masks ),
			'kind'           => (string) ( $request['kind'] ?? 'analysis' ),
			'mask_reasons'   => $reasons,
		);
	}

	/**
	 * Return a cached entry.
	 *
	 * @param string $key Key.
	 * @return array<string, mixed>|null
	 */
	public function get( $key ) {
		$index = $this->index();
		$key   = (string) $key;
		if ( ! isset( $index[ $key ] ) ) {
			return null;
		}

		$entry = $index[ $key ];
		$ttl   = (int) ( $entry['ttl'] ?? Visual_Limits::DEFAULT_TTL );
		if ( $ttl > 0 && ( time() - (int) ( $entry['stored_at'] ?? 0 ) ) > $ttl ) {
			// Expired. Removed on read, so a cache nobody reads still does not grow
			// without bound, and the sweep is not the only thing keeping it tidy.
			$this->forget( $key );
			return null;
		}

		$body = get_option( 'replicaforge_visual_entry_' . $key, null );
		if ( null === $body ) {
			// The index outlived the body. A dangling index entry would report a cache
			// hit that returns nothing, which is worse than a miss.
			$this->forget( $key );
			return null;
		}

		$entry['hit']  = true;
		$entry['body'] = $body;
		return $entry;
	}

	/**
	 * Store an entry.
	 *
	 * @param string $key        Key.
	 * @param mixed  $body       Body.
	 * @param string $kind       `screenshot` or `analysis`.
	 * @param int    $ttl        Retention.
	 * @param string $project_id Owning project, for the access check.
	 * @return array<string, mixed>
	 */
	public function put( $key, $body, $kind = 'analysis', $ttl = 0, $project_id = '' ) {
		$key   = (string) $key;
		$bytes = is_string( $body ) ? strlen( $body ) : 0;

		// A screenshot over the size limit is not cached. Storing it would fill the
		// options table with something the reader cannot load and the comparator would
		// refuse anyway.
		if ( 'screenshot' === $kind && $bytes > Visual_Limits::max_screenshot_bytes() ) {
			return array( 'stored' => false, 'reason' => 'too_large' );
		}

		$ttl = ( $ttl > 0 ) ? (int) $ttl : Visual_Limits::DEFAULT_TTL;

		$index   = $this->index();
		$index[ $key ] = array(
			'kind'       => (string) $kind,
			'bytes'      => $bytes,
			'stored_at'  => time(),
			'ttl'        => $ttl,
			// The owning project is recorded *in the index*, not derived from the key.
			// `Visual_Api::get_capture()` compares it against the caller's project, and
			// an index entry without it would make that check fail for every capture —
			// which looks like a permissions bug and is a storage bug. The first draft
			// omitted this field entirely.
			'project_id' => (string) $project_id,
			// §57: retention is a class, not just a number, so a report can say whether
			// an image is temporary, part of project history, or a validation snapshot.
			'retention'  => ( $ttl >= Visual_Limits::DEFAULT_TTL ) ? 'project' : 'temporary',
		);

		// Bounded: oldest first. An unbounded cache is a disk-full incident.
		if ( count( $index ) > self::MAX_ENTRIES ) {
			uasort( $index, static function ( $left, $right ) { return (int) $left['stored_at'] <=> (int) $right['stored_at']; } );
			$index = array_slice( $index, -self::MAX_ENTRIES, null, true );
		}

		update_option( 'replicaforge_visual_entry_' . $key, $body, false );
		update_option( self::INDEX_OPTION, $index, false );

		return array( 'stored' => true, 'key' => $key, 'bytes' => $bytes, 'retention' => $index[ $key ]['retention'] );
	}

	/**
	 * Remove one entry.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function forget( $key ) {
		$key   = (string) $key;
		$index = $this->index();
		if ( ! isset( $index[ $key ] ) ) {
			return false;
		}
		delete_option( 'replicaforge_visual_entry_' . $key );
		unset( $index[ $key ] );
		update_option( self::INDEX_OPTION, $index, false );
		return true;
	}

	/**
	 * Remove every entry for a project.
	 *
	 * @param string $project_id Project id.
	 * @return int Number removed.
	 */
	public function forget_project( $project_id ) {
		$project_id = (string) $project_id;
		$removed    = 0;
		foreach ( $this->index() as $key => $entry ) {
			if ( (string) ( $entry['project_id'] ?? '' ) !== $project_id ) {
				continue;
			}
			if ( $this->forget( $key ) ) {
				$removed++;
			}
		}
		return $removed;
	}

	/**
	 * Remove expired entries.
	 *
	 * @return int Number removed.
	 */
	public function prune() {
		$removed = 0;
		$now     = time();
		foreach ( $this->index() as $key => $entry ) {
			$ttl = (int) ( $entry['ttl'] ?? 0 );
			if ( $ttl > 0 && ( $now - (int) ( $entry['stored_at'] ?? 0 ) ) > $ttl ) {
				if ( $this->forget( $key ) ) {
					$removed++;
				}
			}
		}
		return $removed;
	}

	/**
	 * Return a cache report.
	 *
	 * @return array<string, mixed>
	 */
	public function report() {
		$index   = $this->index();
		$bytes   = 0;
		$kinds   = array();
		$expired = 0;
		$now     = time();
		foreach ( $index as $entry ) {
			$bytes += (int) ( $entry['bytes'] ?? 0 );
			$kind   = (string) ( $entry['kind'] ?? 'analysis' );
			$kinds[ $kind ] = ( $kinds[ $kind ] ?? 0 ) + 1;
			$ttl    = (int) ( $entry['ttl'] ?? 0 );
			if ( $ttl > 0 && ( $now - (int) ( $entry['stored_at'] ?? 0 ) ) > $ttl ) {
				$expired++;
			}
		}
		return array(
			'entries' => count( $index ),
			'bytes'   => $bytes,
			'kinds'   => $kinds,
			'expired' => $expired,
			'max'     => self::MAX_ENTRIES,
			'analyzer_version' => Visual_Limits::ANALYZER_VERSION,
			'note'    => __( 'A cached entry is only reused when the source, the viewport, the renderer, the analyzer, and the normalization all match.', 'replicaforge' ),
		);
	}

	/**
	 * Read the index.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function index() {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}
}

/**
 * Viewport profile management §6 and §7 require.
 *
 * The three profiles are Phase 5's, read rather than restated, and a fourth is
 * filterable. What is new is the **normalisation contract**: what a captured
 * screenshot's dimensions mean once a device pixel ratio is involved, and the fact
 * that a comparison between differently-scaled captures is not a comparison of the
 * same thing.
 */
final class Viewport_Manager {

	/**
	 * Return the viewport profiles.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function profiles() {
		$out = array();
		foreach ( Visual_Limits::viewports() as $name => $profile ) {
			$out[ $name ] = array(
				'name'               => (string) $name,
				'label'              => (string) ( $profile['label'] ?? $name ),
				'width'              => (int) ( $profile['width'] ?? 1440 ),
				'height'             => (int) ( $profile['height'] ?? 900 ),
				'device_pixel_ratio' => Visual_Limits::default_dpr( $name ),
				'is_mobile'          => ( ( (int) ( $profile['width'] ?? 1440 ) ) < 768 ),
			);
		}

		$configured = apply_filters( 'replicaforge_visual_viewports', array() );
		if ( is_array( $configured ) ) {
			foreach ( $configured as $name => $profile ) {
				if ( ! is_string( $name ) || ! is_array( $profile ) ) {
					continue;
				}
				// Bounded, so a filter cannot request a 40000-pixel-wide viewport and
				// turn a comparison into a memory exhaustion.
				$width  = max( 320, min( 3840, (int) ( $profile['width'] ?? 1440 ) ) );
				$height = max( 240, min( 2160, (int) ( $profile['height'] ?? 900 ) ) );
				$out[ sanitize_key( $name ) ] = array(
					'name'               => sanitize_key( $name ),
					'label'              => (string) ( $profile['label'] ?? $name ),
					'width'              => $width,
					'height'             => $height,
					'device_pixel_ratio' => max( 1.0, min( 3.0, (float) ( $profile['device_pixel_ratio'] ?? 1.0 ) ) ),
					'is_mobile'          => ( $width < 768 ),
					'custom'             => true,
				);
			}
		}

		return $out;
	}

	/**
	 * Return one profile.
	 *
	 * @param string $name Name.
	 * @return array<string, mixed>|null
	 */
	public function profile( $name ) {
		$profiles = $this->profiles();
		return isset( $profiles[ (string) $name ] ) ? $profiles[ (string) $name ] : null;
	}

	/**
	 * Return every profile as a render request set, bounded by §78.
	 *
	 * @param int $limit Maximum viewports.
	 * @return array<int, array<string, mixed>>
	 */
	public function render_set( $limit = 0 ) {
		$limit = ( $limit > 0 ) ? (int) $limit : count( $this->profiles() );
		return array_slice( array_values( $this->profiles() ), 0, max( 1, min( 6, $limit ) ) );
	}

	/**
	 * Return the comparison normalisation contract for two captures.
	 *
	 * §7 asks that captures at different DPRs remain comparable. They do — by
	 * comparing in CSS pixels — and the condition under which that is true is
	 * returned rather than assumed.
	 *
	 * @param array<string, mixed> $source  Source capture.
	 * @param array<string, mixed> $replica Replica capture.
	 * @return array<string, mixed>
	 */
	public function normalisation( array $source, array $replica ) {
		$sw = (int) ( $source['width'] ?? 0 );
		$sh = (int) ( $source['height'] ?? 0 );
		$rw = (int) ( $replica['width'] ?? 0 );
		$rh = (int) ( $replica['height'] ?? 0 );

		$sdpr = (float) ( $source['rendered_dpr'] ?? $source['device_pixel_ratio'] ?? 1.0 );
		$rdpr = (float) ( $replica['rendered_dpr'] ?? $replica['device_pixel_ratio'] ?? 1.0 );
		$sdpr = ( $sdpr > 0 ) ? $sdpr : 1.0;
		$rdpr = ( $rdpr > 0 ) ? $rdpr : 1.0;

		$scale_x = ( $rw > 0 ) ? ( $sw / $rw ) : 1.0;
		$scale_y = ( $rh > 0 ) ? ( $sh / $rh ) : 1.0;
		$scaled  = ( abs( $scale_x - 1.0 ) > 0.02 || abs( $scale_y - 1.0 ) > 0.02 );

		return array(
			'source'      => array( 'width' => $sw, 'height' => $sh, 'dpr' => $sdpr ),
			'replica'     => array( 'width' => $rw, 'height' => $rh, 'dpr' => $rdpr ),
			'scale_x'     => round( $scale_x, 4 ),
			'scale_y'     => round( $scale_y, 4 ),
			'scaled'      => (bool) $scaled,
			'dpr_mismatch'=> ( abs( $sdpr - $rdpr ) > 0.001 ),
			'method'      => ( $scaled || abs( $sdpr - $rdpr ) > 0.001 )
				? 'resample_to_css_pixels'
				: 'direct',
			// A scaled comparison is *valid* but its ratio is not a statement about
			// the design — resampling invents pixels. So it is reported as a caveat
			// rather than silently applied.
			'caveat'      => ( $scaled || abs( $sdpr - $rdpr ) > 0.001 )
				? __( 'The two captures are different sizes, so the replica was resampled to the source before comparison. A resampled difference ratio is about the images, not only about the design.', 'replicaforge' )
				: '',
		);
	}
}
