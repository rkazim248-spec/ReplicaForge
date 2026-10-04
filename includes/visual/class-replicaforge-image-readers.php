<?php
/**
 * Phase 13: image readers, and the null answer.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves an image reader from the loaded PHP extensions.
 *
 * Deliberately thin: it picks a backend, and every backend reports honestly when it
 * cannot read. It never fabricates a surface, because a fabricated surface is a
 * fabricated comparison.
 */
final class Image_Readers {

	/**
	 * Return an available reader.
	 *
	 * Imagick first, then GD. Imagick because it handles more formats and is less
	 * likely to fail on a screenshot, GD because it is far more commonly present.
	 *
	 * @return Image_Reader_Contract
	 */
	public static function resolve() {
		$candidates = array( new Imagick_Image_Reader(), new Gd_Image_Reader() );
		foreach ( $candidates as $candidate ) {
			if ( $candidate->is_available() ) {
				return $candidate;
			}
		}
		return new Null_Image_Reader();
	}

	/**
	 * Return a reader for tests, by id.
	 *
	 * @param string $id Reader id.
	 * @return Image_Reader_Contract
	 */
	public static function by_id( $id ) {
		$readers = array(
			'imagick'  => new Imagick_Image_Reader(),
			'gd'       => new Gd_Image_Reader(),
			'synthetic'=> new Synthetic_Image_Reader(),
			'null'     => new Null_Image_Reader(),
		);
		return $readers[ (string) $id ] ?? new Null_Image_Reader();
	}
}

/**
 * Reads pixels with Imagick.
 *
 * @package ReplicaForge
 */
final class Imagick_Image_Reader implements Image_Reader_Contract {

	/**
	 * Return whether Imagick is loaded.
	 *
	 * @return bool
	 */
	public function is_available() {
		return extension_loaded( 'imagick' ) && class_exists( '\Imagick' );
	}

	/**
	 * Return the reader identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'imagick';
	}

	/**
	 * Read image bytes.
	 *
	 * @param string $bytes Encoded image bytes.
	 * @return array<string, mixed>
	 */
	public function read( $bytes ) {
		if ( ! $this->is_available() || ! is_string( $bytes ) || '' === $bytes ) {
			return array( 'available' => false, 'reason' => 'unavailable' );
		}

		try {
			$image = new \Imagick();
			// A bounded pixel count. A decompression bomb is a real PNG attack and
			// Imagick will happily expand a 40KB file into 200,000×200,000 pixels.
			$image->setOption( 'MAGICK_MEMORY_LIMIT', '256MiB' );
			$image->setOption( 'MAGICK_MAP_LIMIT', '256MiB' );
			$image->setOption( 'MAGICK_AREA_LIMIT', '64MP' );
			$image->readImageBlob( $bytes );
			$image->setImageColorspace( \Imagick::COLORSPACE_SRGB );
			$image->setImageFormat( 'png' );

			$width  = (int) $image->getImageWidth();
			$height = (int) $image->getImageHeight();
			if ( $width < 1 || $height < 1 ) {
				return array( 'available' => false, 'reason' => 'unreadable' );
			}

			$export = $image->exportImagePixels( 0, 0, $width, $height, 'RGB', \Imagick::PIXEL_CHAR );

			return array(
				'available' => true,
				'width'     => $width,
				'height'    => $height,
				'pixels'    => is_array( $export ) ? $export : array(),
			);
		} catch ( \Throwable $exception ) {
			return array( 'available' => false, 'reason' => 'decode_failed' );
		}
	}

	/**
	 * Encode a surface to PNG.
	 *
	 * @param array<string, mixed> $surface Surface.
	 * @return string
	 */
	public function encode( array $surface ) {
		if ( ! $this->is_available() || empty( $surface['pixels'] ) ) {
			return '';
		}
		try {
			$image = new \Imagick();
			$image->newImage( (int) $surface['width'], (int) $surface['height'], 'white' );
			$image->importImagePixels( 0, 0, (int) $surface['width'], (int) $surface['height'], 'RGB', \Imagick::PIXEL_CHAR, (array) $surface['pixels'] );
			return (string) $image->getImageBlob();
		} catch ( \Throwable $exception ) {
			return '';
		}
	}

	/**
	 * Resize a surface.
	 *
	 * @param array<string, mixed> $surface Surface.
	 * @param int                   $width   Target width.
	 * @param int                   $height  Target height.
	 * @return array<string, mixed>
	 */
	public function resize( array $surface, $width, $height ) {
		if ( ! $this->is_available() || empty( $surface['pixels'] ) ) {
			return $surface;
		}
		try {
			$image = new \Imagick();
			$image->newImage( (int) $surface['width'], (int) $surface['height'], 'white' );
			$image->importImagePixels( 0, 0, (int) $surface['width'], (int) $surface['height'], 'RGB', \Imagick::PIXEL_CHAR, (array) $surface['pixels'] );
			$image->setImageFormat( 'png' );
			$sample = $image->clone();
			$sample->resizeImage( max( 1, (int) $width ), max( 1, (int) $height ), \Imagick::FILTER_POINT, 1 );
			$w = (int) $sample->getImageWidth();
			$h = (int) $sample->getImageHeight();
			$export = $sample->exportImagePixels( 0, 0, $w, $h, 'RGB', \Imagick::PIXEL_CHAR );
			return array( 'available' => true, 'width' => $w, 'height' => $h, 'pixels' => is_array( $export ) ? $export : array() );
		} catch ( \Throwable $exception ) {
			return $surface;
		}
	}
}

/**
 * Reads pixels with GD.
 *
 * @package ReplicaForge
 */
final class Gd_Image_Reader implements Image_Reader_Contract {

	/**
	 * Return whether GD is loaded.
	 *
	 * @return bool
	 */
	public function is_available() {
		return extension_loaded( 'gd' ) && function_exists( 'imagecreatefromstring' );
	}

	/**
	 * Return the reader identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'gd';
	}

	/**
	 * Read image bytes.
	 *
	 * @param string $bytes Encoded image bytes.
	 * @return array<string, mixed>
	 */
	public function read( $bytes ) {
		if ( ! $this->is_available() || ! is_string( $bytes ) || '' === $bytes ) {
			return array( 'available' => false, 'reason' => 'unavailable' );
		}

		$image = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $image ) {
			return array( 'available' => false, 'reason' => 'decode_failed' );
		}

		$width  = (int) imagesx( $image );
		$height = (int) imagesy( $image );
		if ( $width < 1 || $height < 1 || ( $width * $height ) > 40000000 ) {
			imagedestroy( $image );
			return array( 'available' => false, 'reason' => 'unreadable' );
		}

		$pixels = array();
		for ( $y = 0; $y < $height; $y++ ) {
			$row = imagecolorat( $image, 0, $y );
			unset( $row );
			for ( $x = 0; $x < $width; $x++ ) {
				$rgb = imagecolorat( $image, $x, $y );
				$pixels[] = (int) ( ( $rgb >> 16 ) & 0xFF );
				$pixels[] = (int) ( ( $rgb >> 8 ) & 0xFF );
				$pixels[] = (int) ( $rgb & 0xFF );
			}
		}
		imagedestroy( $image );

		return array( 'available' => true, 'width' => $width, 'height' => $height, 'pixels' => $pixels );
	}

	/**
	 * Encode a surface to PNG.
	 *
	 * @param array<string, mixed> $surface Surface.
	 * @return string
	 */
	public function encode( array $surface ) {
		if ( ! $this->is_available() || empty( $surface['pixels'] ) ) {
			return '';
		}
		$image = $this->to_gd( $surface );
		if ( ! $image ) {
			return '';
		}
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		imagedestroy( $image );
		return $bytes;
	}

	/**
	 * Resize a surface.
	 *
	 * @param array<string, mixed> $surface Surface.
	 * @param int                   $width   Target width.
	 * @param int                   $height  Target height.
	 * @return array<string, mixed>
	 */
	public function resize( array $surface, $width, $height ) {
		if ( ! $this->is_available() || empty( $surface['pixels'] ) ) {
			return $surface;
		}
		$source = $this->to_gd( $surface );
		if ( ! $source ) {
			return $surface;
		}
		$target = imagecreatetruecolor( max( 1, (int) $width ), max( 1, (int) $height ) );
		imagecopyresampled( $target, $source, 0, 0, 0, 0, (int) $width, (int) $height, (int) $surface['width'], (int) $surface['height'] );
		imagedestroy( $source );

		$w      = (int) imagesx( $target );
		$h      = (int) imagesy( $target );
		$pixels = array();
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$rgb = imagecolorat( $target, $x, $y );
				$pixels[] = (int) ( ( $rgb >> 16 ) & 0xFF );
				$pixels[] = (int) ( ( $rgb >> 8 ) & 0xFF );
				$pixels[] = (int) ( $rgb & 0xFF );
			}
		}
		imagedestroy( $target );

		return array( 'available' => true, 'width' => $w, 'height' => $h, 'pixels' => $pixels );
	}

	/**
	 * Convert a surface to a GD image.
	 *
	 * @param array<string, mixed> $surface Surface.
	 * @return resource|object|null
	 */
	private function to_gd( array $surface ) {
		$width  = (int) ( $surface['width'] ?? 0 );
		$height = (int) ( $surface['height'] ?? 0 );
		$pixels = (array) ( $surface['pixels'] ?? array() );
		if ( $width < 1 || $height < 1 || count( $pixels ) < ( $width * $height * 3 ) ) {
			return null;
		}

		$image = imagecreatetruecolor( $width, $height );
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$offset = ( ( $y * $width ) + $x ) * 3;
				imagesetpixel( $image, $x, $y, (int) ( ( $pixels[ $offset ] << 16 ) | ( $pixels[ $offset + 1 ] << 8 ) | $pixels[ $offset + 2 ] ) );
			}
		}
		return $image;
	}
}

/**
 * A reader that generates a surface without an image library.
 *
 * This is what makes the comparison *logic* testable on a host with no GD and no
 * Imagick — which is a real host class, and the class where untested code is most
 * likely to break. It is never used in production: {@see self::is_available()}
 * returns true so a test can inject it explicitly, and it is not returned by
 * {@see Image_Readers::resolve()}.
 */
final class Synthetic_Image_Reader implements Image_Reader_Contract {

	/**
	 * Return available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * Return the reader identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'synthetic';
	}

	/**
	 * Read bytes.
	 *
	 * A screenshot of bytes is not a screenshot, so this refuses rather than inventing
	 * a surface. The synthetic reader is for *building* surfaces, not decoding.
	 *
	 * @param string $bytes Encoded image bytes.
	 * @return array<string, mixed>
	 */
	public function read( $bytes ) {
		return array( 'available' => false, 'reason' => 'synthetic_reader_cannot_decode' );
	}

	/**
	 * Encode a surface.
	 *
	 * @param array<string, mixed> $surface Surface.
	 * @return string
	 */
	public function encode( array $surface ) {
		// A minimal, valid 1×1 PNG. Enough for a test that asserts a heatmap produced
		// *some* output, without pretending to be a real image.
		return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
	}

	/**
	 * Resize a surface.
	 *
	 * @param array<string, mixed> $surface Surface.
	 * @param int                   $width   Target width.
	 * @param int                   $height  Target height.
	 * @return array<string, mixed>
	 */
	public function resize( array $surface, $width, $height ) {
		return self::blank( (int) $width, (int) $height );
	}

	/**
	 * Build a solid surface.
	 *
	 * @param int   $width  Width.
	 * @param int   $height Height.
	 * @param array $rgb    Channel values.
	 * @return array<string, mixed>
	 */
	public static function blank( $width, $height, array $rgb = array( 255, 255, 255 ) ) {
		$width  = max( 1, (int) $width );
		$height = max( 1, (int) $height );
		$pixels = array();
		for ( $i = 0; $i < ( $width * $height ); $i++ ) {
			$pixels[] = (int) ( $rgb[0] ?? 255 );
			$pixels[] = (int) ( $rgb[1] ?? 255 );
			$pixels[] = (int) ( $rgb[2] ?? 255 );
		}
		return array( 'available' => true, 'width' => $width, 'height' => $height, 'pixels' => $pixels );
	}

	/**
	 * Build a surface with a rectangle painted into it.
	 *
	 * @param int    $width   Width.
	 * @param int    $height  Height.
	 * @param array  $rect    `x`, `y`, `w`, `h`, `rgb`.
	 * @param array  $base    Base colour.
	 * @return array<string, mixed>
	 */
	public static function with_rect( $width, $height, array $rect, array $base = array( 255, 255, 255 ) ) {
		$surface = self::blank( $width, $height, $base );
		$rgb     = (array) ( $rect['rgb'] ?? array( 0, 0, 0 ) );
		$x0      = max( 0, (int) ( $rect['x'] ?? 0 ) );
		$y0      = max( 0, (int) ( $rect['y'] ?? 0 ) );
		$x1      = min( (int) $width, $x0 + max( 1, (int) ( $rect['w'] ?? 1 ) ) );
		$y1      = min( (int) $height, $y0 + max( 1, (int) ( $rect['h'] ?? 1 ) ) );

		for ( $y = $y0; $y < $y1; $y++ ) {
			for ( $x = $x0; $x < $x1; $x++ ) {
				$offset                          = ( ( $y * (int) $width ) + $x ) * 3;
				$surface['pixels'][ $offset ]     = (int) ( $rgb[0] ?? 0 );
				$surface['pixels'][ $offset + 1 ] = (int) ( $rgb[1] ?? 0 );
				$surface['pixels'][ $offset + 2 ] = (int) ( $rgb[2] ?? 0 );
			}
		}
		return $surface;
	}
}

/**
 * The reader that is always available and always refuses.
 *
 * Its whole purpose is to make "no image library" a *stated* answer rather than a
 * crash or a silent zero. Every consumer gets `available: false` with a reason, and
 * the reason is user-readable.
 */
final class Null_Image_Reader implements Image_Reader_Contract {

	/**
	 * Return available — because this reader is always usable at being useless.
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * Return the reader identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'none';
	}

	/**
	 * Refuse to read.
	 *
	 * @param string $bytes Encoded image bytes.
	 * @return array<string, mixed>
	 */
	public function read( $bytes ) {
		return array(
			'available' => false,
			'reason'    => 'no_image_library',
			'message'   => __( 'This server has no image library (GD or Imagick), so screenshots cannot be measured pixel by pixel. Structural and design-system comparison are unaffected.', 'replicaforge' ),
		);
	}

	/**
	 * Refuse to encode.
	 *
	 * @param array<string, mixed> $surface Surface.
	 * @return string
	 */
	public function encode( array $surface ) {
		return '';
	}

	/**
	 * Return the surface unchanged.
	 *
	 * @param array<string, mixed> $surface Surface.
	 * @param int                   $width   Target width.
	 * @param int                   $height  Target height.
	 * @return array<string, mixed>
	 */
	public function resize( array $surface, $width, $height ) {
		return $surface;
	}
}
