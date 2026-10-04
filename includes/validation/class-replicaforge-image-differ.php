<?php
/**
 * Rendered image difference for ReplicaForge Phase 5.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares two captured screenshots pixel by pixel.
 *
 * The comparison is deliberately forgiving of harmless rendering noise. A
 * per-pixel difference below the declared anti-aliasing threshold counts as
 * equal, so font rasterization and sub-pixel rendering are not reported as
 * design differences. Region banding then localizes the differences that remain,
 * which is what makes a shifted section or a resized image visible.
 */
final class Image_Differ {

	/**
	 * Image library in use.
	 *
	 * @var string
	 */
	private $library;

	/**
	 * Constructor.
	 *
	 * @param string $library Either `gd` or `imagick`, or an empty string.
	 */
	public function __construct( $library = '' ) {
		$this->library = $this->resolve_library( $library );
	}

	/**
	 * Return the resolved image library.
	 *
	 * @param string $library Preferred library.
	 * @return string
	 */
	private function resolve_library( $library ) {
		if ( 'gd' === $library && extension_loaded( 'gd' ) ) {
			return 'gd';
		}
		if ( 'imagick' === $library && extension_loaded( 'imagick' ) ) {
			return 'imagick';
		}
		if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
			return 'imagick';
		}
		if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatefromstring' ) ) {
			return 'gd';
		}
		return '';
	}

	/**
	 * Return true when a comparison is possible.
	 *
	 * @return bool
	 */
	public function is_available() {
		return '' !== $this->library;
	}

	/**
	 * Return the resolved library name.
	 *
	 * @return string
	 */
	public function library() {
		return $this->library;
	}

	/**
	 * Compare two PNG payloads.
	 *
	 * @param string $source    Source screenshot bytes.
	 * @param string $generated Generated screenshot bytes.
	 * @return array<string, mixed>
	 */
	public function compare( $source, $generated ) {
		if ( ! $this->is_available() ) {
			return $this->unavailable( __( 'Visual image comparison is unavailable because no image library is installed.', 'replicaforge' ) );
		}
		if ( ! is_string( $source ) || ! is_string( $generated ) || '' === $source || '' === $generated ) {
			return $this->unavailable( __( 'Visual image comparison needs two captured screenshots.', 'replicaforge' ) );
		}
		if ( strlen( $source ) > Validation_Limits::MAX_SCREENSHOT_BYTES || strlen( $generated ) > Validation_Limits::MAX_SCREENSHOT_BYTES ) {
			return $this->unavailable( __( 'A captured screenshot exceeded the configured size limit.', 'replicaforge' ) );
		}

		$source_image    = $this->load( $source );
		$generated_image = $this->load( $generated );
		if ( null === $source_image || null === $generated_image ) {
			return $this->unavailable( __( 'A captured screenshot could not be decoded.', 'replicaforge' ) );
		}

		$source_size    = $this->size( $source_image );
		$generated_size = $this->size( $generated_image );
		if ( null === $source_size || null === $generated_size ) {
			return $this->unavailable( __( 'A captured screenshot could not be measured.', 'replicaforge' ) );
		}

		$size_mismatch = $source_size !== $generated_size;
		$width         = min( $source_size['width'], $generated_size['width'] );
		$height        = min( $source_size['height'], $generated_size['height'] );

		$analysis = $this->analyze( $source_image, $generated_image, $width, $height, $size_mismatch );

		return array(
			'available'     => true,
			'source_size'   => $source_size,
			'generated_size' => $generated_size,
			'size_mismatch' => $size_mismatch,
			'width'         => $width,
			'height'        => $height,
			'differing_ratio' => $analysis['ratio'],
			'similarity'    => $analysis['similarity'],
			'band'          => $analysis['band'],
			'regions'       => $analysis['regions'],
			'largest_region_ratio' => $analysis['largest_region_ratio'],
		);
	}

	/**
	 * Build an unavailable result.
	 *
	 * @param string $message Reason.
	 * @return array<string, mixed>
	 */
	private function unavailable( $message ) {
		return array(
			'available'       => false,
			'message'         => $message,
			'differing_ratio' => null,
			'similarity'      => null,
			'regions'         => array(),
		);
	}

	/**
	 * Load image bytes into a handle.
	 *
	 * @param string $bytes PNG bytes.
	 * @return resource|object|null
	 */
	private function load( $bytes ) {
		if ( 'imagick' === $this->library ) {
			try {
				$image = new \Imagick();
				$image->readImageBlob( $bytes );
				return $image;
			} catch ( \Throwable $exception ) {
				return null;
			}
		}
		if ( function_exists( 'imagecreatefromstring' ) ) {
			$image = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return is_object( $image ) ? $image : null;
		}
		return null;
	}

	/**
	 * Return the size of a loaded image.
	 *
	 * @param resource|object $image Image handle.
	 * @return array<string, int>|null
	 */
	private function size( $image ) {
		if ( 'imagick' === $this->library && is_object( $image ) ) {
			return array(
				'width'  => (int) $image->getImageWidth(),
				'height' => (int) $image->getImageHeight(),
			);
		}
		if ( is_object( $image ) && function_exists( 'imagesx' ) ) {
			return array(
				'width'  => (int) imagesx( $image ),
				'height' => (int) imagesy( $image ),
			);
		}
		return null;
	}

	/**
	 * Analyze two images pixel by pixel.
	 *
	 * @param resource|object $source        Source image.
	 * @param resource|object $generated     Generated image.
	 * @param int             $width         Compared width.
	 * @param int             $height        Compared height.
	 * @param bool            $size_mismatch Whether the captures differ in size.
	 * @return array<string, mixed>
	 */
	private function analyze( $source, $generated, $width, $height, $size_mismatch ) {
		$step = 1;
		$max  = (int) ceil( sqrt( max( 1, $width * $height ) / 20000 ) );

		$threshold = (float) Validation_Limits::IMAGE_TOLERANCES['anti_alias'];
		$total     = 0;
		$different  = 0;
		$accumulated = 0.0;

		$rows = array();
		foreach ( Validation_Limits::IMAGE_BANDS as $band_size ) {
			$rows[ $band_size ] = array(
				'different' => 0,
				'total'     => 0,
			);
		}

		for ( $y = 0; $y < $height; $y += $step ) {
			for ( $x = 0; $x < $width; $x += $step ) {
				$left  = $this->pixel( $source, $x, $y );
				$right = $this->pixel( $generated, $x, $y );
				if ( null === $left || null === $right ) {
					continue;
				}
				$total++;
				$delta = ( abs( $left[0] - $right[0] ) + abs( $left[1] - $right[1] ) + abs( $left[2] - $right[2] ) ) / 765;
				$accumulated += $delta;
				if ( $delta <= $threshold ) {
					continue;
				}
				$different++;
				foreach ( $rows as $band_size => $unused ) {
					$rows[ $band_size ]['total']++;
					if ( $delta > (float) Validation_Limits::IMAGE_TOLERANCES['noticeable'] ) {
						$rows[ $band_size ]['different']++;
					}
				}
			}
		}

		$ratio = $total > 0 ? $different / $total : 0.0;
		if ( $size_mismatch ) {
			// A size mismatch is itself a measurable difference, so it raises the
			// ratio rather than being ignored.
			$ratio = min( 1.0, $ratio + 0.1 );
		}

		$similarity = round( max( 0.0, 1.0 - $ratio ), 4 );
		$band       = $this->band_for( $ratio );
		$regions    = $this->regions( $rows, $width, $height, $max );

		return array(
			'ratio'                => round( $ratio, 4 ),
			'similarity'           => $similarity,
			'band'                 => $band,
			'regions'              => $regions,
			'largest_region_ratio' => empty( $regions ) ? 0.0 : round( (float) $regions[0]['ratio'], 4 ),
			'_mean_delta'          => $total > 0 ? round( $accumulated / $total, 4 ) : 0.0,
		);
	}

	/**
	 * Read one pixel as an RGB triple.
	 *
	 * @param resource|object $image Image handle.
	 * @param int             $x     X coordinate.
	 * @param int             $y     Y coordinate.
	 * @return array<int, int>|null
	 */
	private function pixel( $image, $x, $y ) {
		if ( 'imagick' === $this->library && is_object( $image ) ) {
			$colors = $image->getImagePixelRegion( 1, 1, $x, $y );
			if ( is_array( $colors ) && isset( $colors['colors'] ) && isset( $colors['colors'][0] ) ) {
				return array(
					(int) $colors['colors'][0]['r'],
					(int) $colors['colors'][0]['g'],
					(int) $colors['colors'][0]['b'],
				);
			}
			return null;
		}
		if ( is_object( $image ) && function_exists( 'imagecolorat' ) ) {
			$color = @imagecolorat( $image, $x, $y ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_int( $color ) ) {
				return null;
			}
			return array(
				( $color >> 16 ) & 0xFF,
				( $color >> 8 ) & 0xFF,
				$color & 0xFF,
			);
		}
		return null;
	}

	/**
	 * Classify a difference ratio.
	 *
	 * @param float $ratio Difference ratio.
	 * @return string
	 */
	private function band_for( $ratio ) {
		if ( $ratio <= Validation_Limits::IMAGE_BAND_SMALL ) {
			return 'pass';
		}
		if ( $ratio <= Validation_Limits::IMAGE_BAND_MEDIUM ) {
			return 'partial';
		}
		if ( $ratio <= (float) Validation_Limits::IMAGE_TOLERANCES['major'] ) {
			return 'fail';
		}
		return 'major';
	}

	/**
	 * Locate the largest differing regions.
	 *
	 * @param array<int, array> $rows   Band counters keyed by band size.
	 * @param int               $width  Image width.
	 * @param int               $height Image height.
	 * @param int               $scale  Sample scale.
	 * @return array<int, array<string, mixed>>
	 */
	private function regions( array $rows, $width, $height, $scale ) {
		$regions = array();
		foreach ( $rows as $band_size => $counters ) {
			if ( $counters['total'] < 1 ) {
				continue;
			}
			$ratio = $counters['different'] / $counters['total'];
			if ( $ratio < (float) Validation_Limits::IMAGE_TOLERANCES['anti_alias'] ) {
				continue;
			}
			$regions[] = array(
				'band_size'   => (int) $band_size,
				'ratio'       => round( $ratio, 4 ),
				'bands_total' => (int) ceil( $height / max( 1, (int) $band_size ) ),
				'severity'    => $this->band_for( $ratio ),
			);
		}

		usort(
			$regions,
			static function ( $left, $right ) {
				if ( $left['ratio'] === $right['ratio'] ) {
					return $left['band_size'] < $right['band_size'] ? 1 : -1;
				}
				return $left['ratio'] < $right['ratio'] ? 1 : -1;
			}
		);

		unset( $width, $height, $scale );

		return array_slice( $regions, 0, 12 );
	}
}
