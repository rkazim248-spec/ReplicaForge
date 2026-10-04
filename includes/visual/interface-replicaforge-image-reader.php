<?php
/**
 * Phase 13: the image reading contract.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a screenshot into comparable pixels.
 *
 * ### Why the pixel layer needs an interface too
 *
 * The rendering interface exists because a browser cannot be shipped in a plugin. The
 * same is true of an image library: neither GD nor Imagick is guaranteed on a shared
 * host, and Phase 5's `Image_Differ` already reports itself unavailable when neither
 * is loaded rather than pretending to compare.
 *
 * Putting the decode behind an interface does two things Phase 5 cannot do on its own:
 *
 * 1. **The comparison *logic* becomes testable without a library.** Masking, region
 *    bucketing, signal aggregation, and severity banding are arithmetic over pixels
 *    and are the parts most likely to be wrong. Testing them needs a reader, not a
 *    real photograph — a synthetic reader returns a known gradient and the expected
 *    difference is computable by hand. Without this seam, every one of those tests
 *    would have to be skipped on a host without GD, which is exactly the host where
 *    the code is least likely to have been exercised.
 * 2. **"Unavailable" becomes a first-class answer** rather than a `false` return that
 *    a caller forgets to check, which is how a comparison engine ends up reporting
 *    "0% different" because nothing was compared.
 */
interface Image_Reader_Contract {

	/**
	 * Return whether pixels can be read at all.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Return the reader's identifier, for a report.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Read image bytes into a width/height/pixel surface.
	 *
	 * @param string $bytes Encoded image bytes.
	 * @return array<string, mixed> `available`, `width`, `height`, `pixels`.
	 */
	public function read( $bytes );

	/**
	 * Encode a pixel surface back to PNG bytes.
	 *
	 * Needed because a heatmap (§63) and an overlay (§64) are *products* of the
	 * comparison, and a product nobody can view is not a report.
	 *
	 * @param array<string, mixed> $surface Surface from {@see self::read()}.
	 * @return string PNG bytes, or an empty string.
	 */
	public function encode( array $surface );

	/**
	 * Resize a surface, for heatmap cells and downscaled AI input.
	 *
	 * @param array<string, mixed> $surface Surface.
	 * @param int                   $width   Target width.
	 * @param int                   $height  Target height.
	 * @return array<string, mixed>
	 */
	public function resize( array $surface, $width, $height );
}
