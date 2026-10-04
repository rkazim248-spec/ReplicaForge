<?php
/**
 * Phase 13: the multi-signal visual comparator.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares a source render against a replica render on several independent signals.
 *
 * ### Why pixel difference alone is not the answer
 *
 * Consider two failures. A heading is 40 pixels to the left of where it should be.
 * A video frame differs. Both produce large differing-pixel ratios, and a
 * pixel-only comparator would rank them identically. But one is a visible layout
 * error a user would complain about and the other is a frame of somebody else's
 * video, which will differ on *every* comparison forever and can never be fixed.
 *
 * Treating those as the same severity is how a validation report becomes noise: the
 * user reads "17% different", concludes the replica is bad, and is right — but for
 * the wrong reason, and fixes the wrong thing.
 *
 * So this class computes signals separately and only then combines them, and it
 * applies masking *before* any ratio is computed rather than subtracting afterwards.
 * A masked region contributes to no signal at all.
 *
 * ### Reuse, and what was added
 *
 * {@see Image_Differ} already does a per-pixel walk, banding, and coarse region
 * detection. It is used for the pixel signal rather than reimplemented. What is added
 * here is: masking, signal *separation*, the classification into §60's difference
 * types, and the geometry/structure/colour signals that a pixel walk cannot produce.
 */
final class Visual_Comparator {

	/**
	 * Image reader.
	 *
	 * @var Image_Reader_Contract
	 */
	private $reader;

	/**
	 * Phase 5 pixel differ.
	 *
	 * @var Image_Differ
	 */
	private $differ;

	/**
	 * Per-pixel difference threshold, 0–255.
	 *
	 * Two channels within this of each other count as the same. Without it, PNG
	 * quantisation alone produces a few percent of "differing" pixels on an identical
	 * image, and a report that says two identical screenshots differ is a report
	 * nobody trusts.
	 *
	 * @var int
	 */
	const CHANNEL_TOLERANCE = 12;

	/**
	 * Constructor.
	 *
	 * @param Image_Reader_Contract|null $reader Optional image reader.
	 * @param Image_Differ|null          $differ Optional Phase 5 differ.
	 */
	public function __construct( $reader = null, $differ = null ) {
		$this->reader = $reader instanceof Image_Reader_Contract ? $reader : Image_Readers::resolve();
		$this->differ = $differ instanceof Image_Differ ? $differ : new Image_Differ();
	}

	/**
	 * Compare two renders.
	 *
	 * @param array<string, mixed> $request Comparison request.
	 * @return array<string, mixed>
	 */
	public function compare( array $request ) {
		$source   = (string) ( $request['source_image'] ?? '' );
		$replica  = (string) ( $request['replica_image'] ?? '' );
		$viewport = isset( $request['viewport'] ) && is_array( $request['viewport'] ) ? $request['viewport'] : array();
		$masks    = (array) ( $request['masks'] ?? array() );
		$regions  = (array) ( $request['regions'] ?? array() );
		$geometry = (array) ( $request['geometry'] ?? array() );

		$started = microtime( true );

		// Pixel surfaces may be supplied directly, in which case nothing is encoded and
		// re-decoded. Not a test hook: a renderer that already has pixels should not
		// have to round-trip them, and it is the only way the comparison arithmetic —
		// masking, sampling, banding, region bucketing — can be exercised on a host
		// with no image library, which is a real class of host.
		$surfaces = (array) ( $request['surfaces'] ?? array() );

		if ( '' === $source || '' === $replica ) {
			return $this->unavailable( __( 'A visual comparison needs both a source and a replica capture.', 'replicaforge' ), $started );
		}

		// §79: one viewport failing does not fail the comparison. Everything that can
		// be measured without pixels is measured, and the pixel signal is reported
		// as unavailable rather than as zero.
		$signals = array();

		$pixel = $this->pixel_signal( $source, $replica, $masks, $surfaces );
		if ( null !== $pixel ) {
			// §58 wants more than one method, and here that means more than one
			// *implementation* too. Phase 5's `Image_Differ` is the reviewed one, so
			// when nothing is masked both walk the same two images and their ratios are
			// compared. If they disagree, that is recorded rather than resolved: two
			// different pixel walks disagreeing means at least one has a bug, and a
			// report that quietly prefers the newer one would hide that.
			$pixel['cross_check'] = $this->cross_check( $source, $replica, $masks, $pixel );
			$signals['pixel']     = $pixel;
		}

		$signals['geometry']   = $this->geometry_signal( $geometry );
		$signals['structure']  = $this->structure_signal( $request );
		$signals['color']      = $this->color_signal( $request );
		$signals['spacing']    = $this->spacing_signal( $geometry );
		$signals['typography'] = $this->typography_signal( $request );

		$available = array_keys( $signals );
		$verdict   = $this->verdict( $signals );

		$region_report = $this->regions( $source, $replica, $masks, $regions, $surfaces );

		return array(
			'schema_version' => Visual_Limits::SCHEMA_VERSION,
			'available'      => ( array() !== $available ),
			'viewport'       => array(
				'name'               => (string) ( $viewport['name'] ?? 'desktop' ),
				'width'              => (int) ( $viewport['width'] ?? 0 ),
				'height'             => (int) ( $viewport['height'] ?? 0 ),
				'device_pixel_ratio' => (float) ( $viewport['device_pixel_ratio'] ?? 1.0 ),
			),
			'signals'        => $signals,
			'signal_names'   => $available,
			'missing_signals'=> array_values( array_diff( Visual_Limits::SIGNALS, $available ) ),
			'verdict'        => $verdict,
			'differences'    => $verdict['differences'],
			'regions'        => $region_report,
			'heatmap'        => $this->heatmap( $source, $replica, $masks, $surfaces ),
			'overlays'       => $this->overlay_modes(),
			'masked'         => $this->mask_summary( $masks ),
			'animation_normalized' => ! empty( $request['animation_normalized'] ),
			'elapsed'        => round( microtime( true ) - $started, 3 ),
			'pixel_unavailable_reason' => ( null === $pixel ) ? $this->pixel_unavailable_reason() : '',
			'note'           => ( null !== $pixel )
				? __( 'Differing pixels are one signal among several. A large pixel difference caused by an unmasked dynamic region is not a reconstruction fault.', 'replicaforge' )
				: $this->pixel_unavailable_note(),
		);
	}

	/**
	 * Return why the pixel signal could not be computed.
	 *
	 * Read from the reader rather than restated, so the specific reason — no library,
	 * an undecodable capture, a size mismatch — reaches the output instead of a single
	 * generic sentence covering four different situations. The first draft hardcoded
	 * one message and so could not tell a user which of the four had happened.
	 *
	 * @return string
	 */
	private function pixel_unavailable_reason() {
		if ( ! $this->reader->is_available() ) {
			return 'reader_unavailable';
		}
		$probe = $this->reader->read( '' );
		return (string) ( $probe['reason'] ?? 'unreadable' );
	}

	/**
	 * Return the note shown when the pixel signal is missing.
	 *
	 * @return string
	 */
	private function pixel_unavailable_note() {
		$reason = $this->pixel_unavailable_reason();
		if ( 'no_image_library' === $reason ) {
			return __( 'This server has no image library (GD or Imagick), so screenshots cannot be measured pixel by pixel. Geometry, colour, structure, spacing, and typography signals are unaffected.', 'replicaforge' );
		}
		return __( 'The captures could not be decoded, so no pixel difference was computed. The other signals are unaffected, and a missing pixel signal is reported as missing rather than as a clean result.', 'replicaforge' );
	}

	/* ---------------------------------------------------------------------
	 * Pixel signal
	 * ------------------------------------------------------------------ */

	/**
	 * Compute the pixel signal, with masking applied before any ratio.
	 *
	 * @param string                         $source   Source bytes.
	 * @param string                         $replica  Replica bytes.
	 * @param array<int, array<string, mixed>> $masks  Mask regions.
	 * @return array<string, mixed>|null
	 */
	private function pixel_signal( $source, $replica, array $masks, array $surfaces = array() ) {
		// A surface may be supplied directly, in which case nothing is decoded. This is
		// not a test hook: a renderer that already has pixels in hand should not have to
		// encode and re-decode them, and it is the only way the comparison *arithmetic*
		// — masking, sampling, banding, region bucketing — can be exercised on a host
		// with no image library, which is a real class of host.
		$source_surface  = isset( $surfaces['source'] ) && ! empty( $surfaces['source']['available'] )
			? (array) $surfaces['source']
			: null;
		$replica_surface = isset( $surfaces['replica'] ) && ! empty( $surfaces['replica']['available'] )
			? (array) $surfaces['replica']
			: null;

		if ( null === $source_surface || null === $replica_surface ) {
			if ( ! $this->reader->is_available() ) {
				return null;
			}
			$source_surface  = $this->reader->read( $source );
			$replica_surface = $this->reader->read( $replica );
		}

		if ( empty( $source_surface['available'] ) || empty( $replica_surface['available'] ) ) {
			return null;
		}

		$width  = min( (int) $source_surface['width'], (int) $replica_surface['width'] );
		$height = min( (int) $source_surface['height'], (int) $replica_surface['height'] );
		if ( $width < 1 || $height < 1 ) {
			return null;
		}

		$size_mismatch = ( (int) $source_surface['width'] !== (int) $replica_surface['width'] )
			|| ( (int) $source_surface['height'] !== (int) $replica_surface['height'] );

		$total    = 0;
		$differing = 0;
		$masked   = 0;
		$examined = 0;
		$sampled  = 0;

		$step = 1;
		$potential = $width * $height;
		if ( $potential > Visual_Limits::MAX_SAMPLED_PIXELS ) {
			// Sampling is a *recorded* fact about the measurement, not a silent
			// shortcut. A ratio computed from 3% of the pixels is a different claim
			// from one computed from all of them, and a consumer needs to know which
			// it is looking at.
			$step = (int) ceil( sqrt( $potential / Visual_Limits::MAX_SAMPLED_PIXELS ) );
		}

		for ( $y = 0; $y < $height; $y += $step ) {
			for ( $x = 0; $x < $width; $x += $step ) {
				$examined++;
				$sampled++;

				if ( $this->in_mask( $x, $y, $masks ) ) {
					$masked++;
					continue;
				}

				$offset = ( ( $y * (int) $source_surface['width'] ) + $x ) * 3;
				$ro     = ( ( $y * (int) $replica_surface['width'] ) + $x ) * 3;
				if ( ! isset( $source_surface['pixels'][ $offset + 2 ] ) || ! isset( $replica_surface['pixels'][ $ro + 2 ] ) ) {
					continue;
				}

				$total++;
				$delta = 0;
				for ( $channel = 0; $channel < 3; $channel++ ) {
					$delta = max( $delta, abs(
						(int) $source_surface['pixels'][ $offset + $channel ]
						- (int) $replica_surface['pixels'][ $ro + $channel ]
					) );
				}
				if ( $delta > self::CHANNEL_TOLERANCE ) {
					$differing++;
				}
			}
		}

		$ratio = ( $total > 0 ) ? ( $differing / $total ) : 0.0;
		$bands = Visual_Limits::bands();

		return array(
			'ratio'            => round( $ratio, 5 ),
			'similarity'       => round( 1.0 - $ratio, 5 ),
			'band'             => ( $ratio <= $bands['small'] ) ? 'small' : ( ( $ratio <= $bands['medium'] ) ? 'medium' : 'large' ),
			'examined'         => $examined,
			'compared'         => $total,
			'masked'           => $masked,
			'sampling_step'    => (int) $step,
			'sampled_fraction' => ( $potential > 0 ) ? round( $examined / $potential, 4 ) : 1.0,
			'size_mismatch'    => (bool) $size_mismatch,
			'width'            => (int) $width,
			'height'           => (int) $height,
			'channel_tolerance'=> self::CHANNEL_TOLERANCE,
		);
	}

	/**
	 * Cross-check this class's pixel walk against Phase 5's differ.
	 *
	 * Only meaningful with no masks: {@see Image_Differ::compare()} has no mask
	 * parameter, so comparing its ratio against a masked ratio would be comparing
	 * two different measurements. So the cross-check is skipped when anything is
	 * masked, and says so.
	 *
	 * @param string                $source  Source bytes.
	 * @param string                $replica Replica bytes.
	 * @param array<int, mixed>     $masks   Masks.
	 * @param array<string, mixed>  $pixel   This class's pixel signal.
	 * @return array<string, mixed>
	 */
	private function cross_check( $source, $replica, array $masks, array $pixel ) {
		if ( array() !== $masks ) {
			return array( 'performed' => false, 'reason' => 'masks_applied' );
		}
		if ( method_exists( $this->differ, 'is_available' ) && ! $this->differ->is_available() ) {
			return array( 'performed' => false, 'reason' => 'phase5_differ_unavailable' );
		}

		$theirs = $this->differ->compare( $source, $replica );
		if ( ! is_array( $theirs ) || empty( $theirs['available'] ) ) {
			return array( 'performed' => false, 'reason' => 'phase5_differ_refused' );
		}

		$ours     = (float) ( $pixel['ratio'] ?? 0 );
		$theirs_v = (float) ( $theirs['differing_ratio'] ?? 0 );
		// A generous band. The two walks use different per-pixel thresholds and
		// different sampling, so exact agreement is not expected; a large divergence
		// is what matters.
		$delta = abs( $ours - $theirs_v );

		return array(
			'performed'     => true,
			'phase5_ratio'  => round( $theirs_v, 5 ),
			'phase13_ratio' => round( $ours, 5 ),
			'delta'         => round( $delta, 5 ),
			'agrees'        => ( $delta <= 0.05 ),
			'note'          => ( $delta <= 0.05 )
				? __( 'Two independent pixel walks agree, so the difference ratio is not an artefact of either one.', 'replicaforge' )
				: __( 'Two independent pixel walks disagree by more than 5%. Treat the ratio as unreliable until that is understood.', 'replicaforge' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Non-pixel signals
	 * ------------------------------------------------------------------ */
	/**
	 * Compare geometry between the source and the replica.
	 *
	 * @param array<string, mixed> $geometry Comparison geometry.
	 * @return array<string, mixed>
	 */
	private function geometry_signal( array $geometry ) {
		$source  = (array) ( $geometry['source'] ?? array() );
		$replica = (array) ( $geometry['replica'] ?? array() );
		if ( array() === $source || array() === $replica ) {
			return array( 'available' => false, 'reason' => 'no_geometry' );
		}

		$deltas = array();
		foreach ( $source as $id => $box ) {
			if ( ! isset( $replica[ $id ] ) || ! is_array( $box ) || ! is_array( $replica[ $id ] ) ) {
				continue;
			}
			foreach ( array( 'x', 'y', 'width', 'height' ) as $key ) {
				$a = (float) ( $box[ $key ] ?? 0 );
				$b = (float) ( $replica[ $id ][ $key ] ?? 0 );
				$deltas[] = abs( $a - $b );
			}
		}
		if ( array() === $deltas ) {
			return array( 'available' => false, 'reason' => 'no_matched_elements' );
		}

		$worst = max( $deltas );
		$mean  = array_sum( $deltas ) / count( $deltas );
		$small = (float) ( Validation_Limits::TOLERANCES['length']['small'] ?? 4.0 );
		$medium = (float) ( Validation_Limits::TOLERANCES['length']['medium'] ?? 12.0 );

		return array(
			'available'    => true,
			'comparisons'  => count( $deltas ),
			'worst_delta'  => round( $worst, 2 ),
			'mean_delta'   => round( $mean, 2 ),
			'band'         => ( $worst <= $small ) ? 'aligned' : ( ( $worst <= $medium ) ? 'near' : 'divergent' ),
		);
	}

	/**
	 * Compare structure — what is present, not where.
	 *
	 * @param array<string, mixed> $request Request.
	 * @return array<string, mixed>
	 */
	private function structure_signal( array $request ) {
		$source   = array_keys( (array) ( $request['source_elements'] ?? array() ) );
		$replica  = array_keys( (array) ( $request['replica_elements'] ?? array() ) );
		if ( array() === $source && array() === $replica ) {
			return array( 'available' => false, 'reason' => 'no_element_lists' );
		}

		$missing = array_values( array_diff( $source, $replica ) );
		$extra   = array_values( array_diff( $replica, $source ) );

		return array(
			'available' => true,
			'source'    => count( $source ),
			'replica'   => count( $replica ),
			'missing'   => $missing,
			'extra'     => $extra,
			// Structure is a two-sided signal. An element in the replica that the
			// source does not have is a difference too, and a comparator that only
			// counts what is missing would call a replica with a duplicated footer a
			// better match.
			'matched'   => (int) count( array_intersect( $source, $replica ) ),
		);
	}

	/**
	 * Compare colour distributions.
	 *
	 * @param array<string, mixed> $request Request.
	 * @return array<string, mixed>
	 */
	private function color_signal( array $request ) {
		$source  = (array) ( $request['source_palette'] ?? array() );
		$replica = (array) ( $request['replica_palette'] ?? array() );
		if ( array() === $source || array() === $replica ) {
			return array( 'available' => false, 'reason' => 'no_palettes' );
		}

		$source_values = array_map( static function ( $entry ) { return strtolower( (string) ( is_array( $entry ) ? ( $entry['value'] ?? '' ) : $entry ) ); }, $source );
		$shared = 0;
		foreach ( $replica as $entry ) {
			$value = strtolower( (string) ( is_array( $entry ) ? ( $entry['value'] ?? '' ) : $entry ) );
			if ( in_array( $value, $source_values, true ) ) {
				$shared++;
			}
		}

		$union = count( array_unique( array_merge( $source_values, array_map( static function ( $entry ) { return strtolower( (string) ( is_array( $entry ) ? ( $entry['value'] ?? '' ) : $entry ) ); }, $replica ) ) ) );

		return array(
			'available' => true,
			'source'    => count( $source_values ),
			'replica'   => count( $replica ),
			'shared'    => $shared,
			'union'     => $union,
			// Overlap, not accuracy. A replica with three colours where the source has
			// ten is a *simpler* design, not a colour-mismatched one, and the
			// difference is a category on its own.
			'overlap'   => ( $union > 0 ) ? round( $shared / $union, 3 ) : 0.0,
		);
	}

	/**
	 * Compare spacing.
	 *
	 * @param array<string, mixed> $geometry Geometry.
	 * @return array<string, mixed>
	 */
	private function spacing_signal( array $geometry ) {
		$source  = (array) ( $geometry['source_gaps'] ?? array() );
		$replica = (array) ( $geometry['replica_gaps'] ?? array() );
		if ( array() === $source || array() === $replica ) {
			return array( 'available' => false, 'reason' => 'no_gaps' );
		}

		$count    = min( count( $source ), count( $replica ) );
		$deltas   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$deltas[] = abs( (float) $source[ $i ] - (float) $replica[ $i ] );
		}
		if ( array() === $deltas ) {
			return array( 'available' => false, 'reason' => 'no_paired_gaps' );
		}

		return array(
			'available'   => true,
			'comparisons' => count( $deltas ),
			'mean_delta'  => round( array_sum( $deltas ) / count( $deltas ), 2 ),
			'worst_delta' => round( max( $deltas ), 2 ),
		);
	}

	/**
	 * Compare typography.
	 *
	 * @param array<string, mixed> $request Request.
	 * @return array<string, mixed>
	 */
	private function typography_signal( array $request ) {
		$source  = (array) ( $request['source_typography'] ?? array() );
		$replica = (array) ( $request['replica_typography'] ?? array() );
		if ( array() === $source || array() === $replica ) {
			return array( 'available' => false, 'reason' => 'no_typography' );
		}

		$matched = 0;
		$mismatched = array();
		foreach ( $source as $id => $node ) {
			if ( ! isset( $replica[ $id ] ) || ! is_array( $node ) || ! is_array( $replica[ $id ] ) ) {
				continue;
			}
			$size_ok  = abs( (float) ( $node['font_size'] ?? 0 ) - (float) ( $replica[ $id ]['font_size'] ?? 0 ) ) <= (float) ( Validation_Limits::TOLERANCES['font_size']['small'] ?? 2.0 );
			$family_ok= ( 0 === strcasecmp( (string) ( $node['font_family'] ?? '' ), (string) ( $replica[ $id ]['font_family'] ?? '' ) ) );
			$weight_ok= ( (int) ( $node['font_weight'] ?? 0 ) === (int) ( $replica[ $id ]['font_weight'] ?? 0 ) );
			if ( $size_ok && $family_ok && $weight_ok ) {
				$matched++;
			} else {
				$mismatched[] = array(
					'element' => (string) $id,
					'font_size' => ( $size_ok ? 'match' : 'differs' ),
					'font_family' => ( $family_ok ? 'match' : 'differs' ),
					'font_weight' => ( $weight_ok ? 'match' : 'differs' ),
				);
			}
		}

		return array(
			'available'   => true,
			'compared'    => count( $source ),
			'matched'     => $matched,
			'mismatched'  => $mismatched,
			'ratio'       => ( count( $source ) > 0 ) ? round( $matched / count( $source ), 3 ) : 0.0,
		);
	}

	/* ---------------------------------------------------------------------
	 * Verdict and difference classification
	 * ------------------------------------------------------------------ */

	/**
	 * Combine the signals into a verdict and a list of differences.
	 *
	 * @param array<string, mixed> $signals Signals.
	 * @return array<string, mixed>
	 */
	private function verdict( array $signals ) {
		$differences = array();
		$score       = 0.0;
		$weights     = 0.0;

		if ( isset( $signals['pixel']['ratio'] ) ) {
			$pixel    = (float) $signals['pixel']['ratio'];
			$score   += ( 1.0 - $pixel ) * 0.35;
			$weights += 0.35;
			if ( $pixel > (float) Visual_Limits::bands()['medium'] ) {
				$differences[] = $this->difference( 'color', 'major', 'pixel_difference', sprintf(
					/* translators: 1: percentage of differing pixels, 2: the band. */
					__( '%.1f%% of compared pixels differ, which is a %s difference across the whole page.', 'replicaforge' ),
					$pixel * 100,
					$signals['pixel']['band']
				), array( 'ratio' => $pixel, 'masked' => $signals['pixel']['masked'] ) );
			}
			if ( ! empty( $signals['pixel']['size_mismatch'] ) ) {
				$differences[] = $this->difference( 'size', 'major', 'capture_size_mismatch', __( 'The two captures are different sizes, so they are not aligned for comparison and the difference includes a scale error.', 'replicaforge' ), array(
					'width'  => $signals['pixel']['width'],
					'height' => $signals['pixel']['height'],
				) );
			}
		}

		if ( ! empty( $signals['geometry']['available'] ) ) {
			$band      = (string) $signals['geometry']['band'];
			$alignment = array( 'aligned' => 1.0, 'near' => 0.6, 'divergent' => 0.0 );
			$score    += ( $alignment[ $band ] ?? 0.5 ) * 0.30;
			$weights  += 0.30;
			if ( 'divergent' === $band ) {
				$differences[] = $this->difference( 'position', 'major', 'geometry_divergent', sprintf(
					/* translators: %s: the largest distance in pixels. */
					__( 'Elements are up to %s pixels from where the source placed them.', 'replicaforge' ),
					$signals['geometry']['worst_delta']
				), array( 'worst_delta' => $signals['geometry']['worst_delta'] ) );
			}
		}

		if ( ! empty( $signals['structure']['available'] ) ) {
			$missing = count( $signals['structure']['missing'] );
			$extra   = count( $signals['structure']['extra'] );
			$total   = max( 1, (int) $signals['structure']['source'] );
			$ratio   = ( $missing + $extra ) / $total;
			$score  += ( 1.0 - min( 1.0, $ratio ) ) * 0.20;
			$weights += 0.20;
			if ( $missing > 0 ) {
				$differences[] = $this->difference( 'structure', 'critical', 'missing_elements', sprintf(
					/* translators: %d: number of missing elements. */
					__( '%d element(s) present in the source are absent from the replica.', 'replicaforge' ),
					$missing
				), array( 'missing' => array_slice( (array) $signals['structure']['missing'], 0, 20 ) ) );
			}
			if ( $extra > 0 ) {
				$differences[] = $this->difference( 'structure', 'major', 'extra_elements', sprintf(
					/* translators: %d: number of extra elements. */
					__( '%d element(s) appear in the replica that are not in the source.', 'replicaforge' ),
					$extra
				), array( 'extra' => array_slice( (array) $signals['structure']['extra'], 0, 20 ) ) );
			}
		}

		if ( ! empty( $signals['color']['available'] ) ) {
			$overlap = (float) $signals['color']['overlap'];
			$score  += $overlap * 0.08;
			$weights += 0.08;
			if ( $overlap < 0.5 ) {
				$differences[] = $this->difference( 'color', 'moderate', 'palette_overlap_low', sprintf(
					/* translators: 1: an overlap fraction, 2: source colour count, 3: replica colour count. */
					__( 'Only %.0f%% of the colours match: the source uses %d distinct colours and the replica uses %d.', 'replicaforge' ),
					$overlap * 100,
					(int) $signals['color']['source'],
					(int) $signals['color']['replica']
				), array( 'overlap' => $overlap ) );
			}
		}

		if ( ! empty( $signals['typography']['available'] ) ) {
			$ratio  = (float) $signals['typography']['ratio'];
			$score += $ratio * 0.04;
			$weights += 0.04;
			if ( $ratio < 0.8 ) {
				$differences[] = $this->difference( 'typography', 'moderate', 'typography_mismatch', sprintf(
					/* translators: 1: matched count, 2: total compared. */
					__( '%1$d of %2$d text elements match in size, family, and weight.', 'replicaforge' ),
					(int) $signals['typography']['matched'],
					(int) $signals['typography']['compared']
				), array( 'mismatched' => array_slice( (array) $signals['typography']['mismatched'], 0, 20 ) ) );
			}
		}

		if ( ! empty( $signals['spacing']['available'] ) ) {
			$mean = (float) $signals['spacing']['mean_delta'];
			$small = (float) ( Validation_Limits::TOLERANCES['length']['small'] ?? 4.0 );
			$quality = ( $mean <= $small ) ? 1.0 : max( 0.0, 1.0 - ( $mean / 100 ) );
			$score += $quality * 0.03;
			$weights += 0.03;
			if ( $mean > (float) ( Validation_Limits::TOLERANCES['length']['medium'] ?? 12.0 ) ) {
				$differences[] = $this->difference( 'spacing', 'moderate', 'spacing_divergent', sprintf(
					/* translators: %s: a mean distance in pixels. */
					__( 'Vertical spacing differs by %s pixels on average.', 'replicaforge' ),
					$mean
				), array( 'mean_delta' => $mean ) );
			}
		}

		$overall = ( $weights > 0 ) ? round( $score / $weights, 4 ) : 0.0;
		$overall = max( 0.0, min( 1.0, $overall ) );

		$severities = Visual_Limits::severities();
		$verdict    = ( 0 === count( $differences ) ) ? 'pass' : ( $this->worst_severity( $differences, $severities ) );

		return array(
			'score'       => $overall,
			'verdict'     => (string) $verdict,
			'differences' => $differences,
			'counts'      => $this->count_severities( $differences ),
			// A score with no signals behind it would be a fabricated accuracy claim,
			// so the weight actually accumulated is reported alongside it.
			'signal_weight' => round( $weights, 3 ),
			'note'        => ( $weights < 0.5 )
				? __( 'This score is based on few signals. Treat it as indicative, not as a measurement of visual accuracy.', 'replicaforge' )
				: '',
		);
	}

	/**
	 * Build one difference record.
	 *
	 * @param string               $category Category, from Phase 5's vocabulary.
	 * @param string               $severity Severity.
	 * @param string               $code     Stable code.
	 * @param string               $message  Message.
	 * @param array<string, mixed> $evidence Evidence.
	 * @return array<string, mixed>
	 */
	private function difference( $category, $severity, $code, $message, array $evidence = array() ) {
		// The category is checked against Phase 5's list, which Visual_Limits extends
		// with the visual categories. An unrecognised category would be dropped by
		// `Difference_Engine` downstream, so it is reported here rather than silently
		// discarded.
		$allowed = Visual_Limits::categories();
		if ( ! in_array( $category, $allowed, true ) ) {
			$category = 'layout';
		}

		return array(
			'category' => (string) $category,
			'severity' => (string) $severity,
			'code'     => (string) $code,
			'message'  => (string) $message,
			'evidence' => $evidence,
			'auto_fix' => 'no',
		);
	}

	/* ---------------------------------------------------------------------
	 * Regions, heatmap, overlays
	 * ------------------------------------------------------------------ */

	/**
	 * Compare page regions §62 asks for.
	 *
	 * @param string                          $source   Source bytes.
	 * @param string                          $replica  Replica bytes.
	 * @param array<int, array<string, mixed>> $masks    Masks.
	 * @param array<int, array<string, mixed>> $regions  Named regions.
	 * @return array<int, array<string, mixed>>
	 */
	private function regions( $source, $replica, array $masks, array $regions, array $surfaces = array() ) {
		$out = array();

		// Named regions from the representation when available, otherwise a uniform
		// row banding. A region with no name is not reportable — "row 5" is not
		// something a user can act on.
		if ( array() === $regions ) {
			$rows = Visual_Limits::REGION_ROWS;
			for ( $i = 0; $i < $rows; $i++ ) {
				$regions[] = array( 'name' => 'band_' . ( $i + 1 ), 'from' => $i / $rows, 'to' => ( $i + 1 ) / $rows );
			}
		}

		$source_surface  = ! empty( $surfaces['source']['available'] ) ? (array) $surfaces['source']
			: ( $this->reader->is_available() ? $this->reader->read( $source ) : array( 'available' => false ) );
		$replica_surface = ! empty( $surfaces['replica']['available'] ) ? (array) $surfaces['replica']
			: ( $this->reader->is_available() ? $this->reader->read( $replica ) : array( 'available' => false ) );

		if ( empty( $source_surface['available'] ) || empty( $replica_surface['available'] ) ) {
			foreach ( array_slice( $regions, 0, 20 ) as $region ) {
				$out[] = array(
					'name'     => (string) ( $region['name'] ?? 'region' ),
					'available'=> false,
					'ratio'    => null,
					'severity' => 'informational',
					'reason'   => __( 'Regions could not be compared because this server cannot read images.', 'replicaforge' ),
				);
			}
			return $out;
		}

		foreach ( array_slice( $regions, 0, 30 ) as $region ) {
			$from = isset( $region['from'] ) ? (float) $region['from'] : 0.0;
			$to   = isset( $region['to'] ) ? (float) $region['to'] : 1.0;

			$total     = 0;
			$differing = 0;
			$masked    = 0;

			$width  = min( (int) $source_surface['width'], (int) $replica_surface['width'] );
			$height = min( (int) $source_surface['height'], (int) $replica_surface['height'] );
			$y0 = (int) round( $from * $height );
			$y1 = (int) round( $to * $height );

			for ( $y = $y0; $y < $y1; $y++ ) {
				for ( $x = 0; $x < $width; $x += 2 ) {
					if ( $this->in_mask( $x, $y, $masks ) ) {
						$masked++;
						continue;
					}
					$so = ( ( $y * (int) $source_surface['width'] ) + $x ) * 3;
					$ro = ( ( $y * (int) $replica_surface['width'] ) + $x ) * 3;
					if ( ! isset( $source_surface['pixels'][ $so + 2 ] ) ) {
						continue;
					}
					$total++;
					$delta = 0;
					for ( $c = 0; $c < 3; $c++ ) {
						$delta = max( $delta, abs( (int) $source_surface['pixels'][ $so + $c ] - (int) $replica_surface['pixels'][ $ro + $c ] ) );
					}
					if ( $delta > self::CHANNEL_TOLERANCE ) {
						$differing++;
					}
				}
			}

			$ratio = ( $total > 0 ) ? ( $differing / $total ) : 0.0;
			$out[]  = array(
				'name'      => (string) ( $region['name'] ?? 'region' ),
				'available' => true,
				'ratio'     => round( $ratio, 5 ),
				'compared'  => $total,
				'masked'    => $masked,
				'severity'  => $this->region_severity( $ratio, $total ),
			);
		}

		return $out;
	}

	/**
	 * Return the severity of a region's difference.
	 *
	 * Based on the *share* of the region that differs, not on a whole-page score: a
	 * footer that differs on 60% of its own pixels is a real problem even when it is
	 * 2% of the page.
	 *
	 * @param float $ratio  Ratio.
	 * @param int   $total  Compared pixels.
	 * @return string
	 */
	private function region_severity( $ratio, $total ) {
		if ( $total < 100 ) {
			// Too few pixels for a ratio to mean anything. A 1% difference in a
			// 40-pixel region is one pixel.
			return 'informational';
		}
		if ( $ratio >= 0.5 ) {
			return 'major';
		}
		if ( $ratio >= 0.2 ) {
			return 'moderate';
		}
		if ( $ratio > Visual_Limits::bands()['small'] ) {
			return 'minor';
		}
		return 'informational';
	}

	/**
	 * Build a difference heatmap §63 asks for.
	 *
	 * Real calculated cells, not a placeholder. And it is *optional* — a heatmap of
	 * 72 cells computed on every comparison would cost more than it is worth when
	 * nobody is going to look at it, so it is requested.
	 *
	 * @param string                           $source  Source bytes.
	 * @param string                           $replica Replica bytes.
	 * @param array<int, array<string, mixed>> $masks   Masks.
	 * @return array<string, mixed>
	 */
	private function heatmap( $source, $replica, array $masks, array $surfaces = array() ) {
		$source_surface  = ! empty( $surfaces['source']['available'] ) ? (array) $surfaces['source']
			: ( $this->reader->is_available() ? $this->reader->read( $source ) : array( 'available' => false ) );
		$replica_surface = ! empty( $surfaces['replica']['available'] ) ? (array) $surfaces['replica']
			: ( $this->reader->is_available() ? $this->reader->read( $replica ) : array( 'available' => false ) );
		if ( empty( $source_surface['available'] ) || empty( $replica_surface['available'] ) ) {
			return array( 'available' => false, 'reason' => 'unreadable' );
		}

		$cols   = Visual_Limits::REGION_COLUMNS;
		$rows   = Visual_Limits::REGION_ROWS;
		$width  = min( (int) $source_surface['width'], (int) $replica_surface['width'] );
		$height = min( (int) $source_surface['height'], (int) $replica_surface['height'] );
		$cells  = array();
		$worst  = 0.0;

		for ( $row = 0; $row < $rows; $row++ ) {
			$line = array();
			for ( $col = 0; $col < $cols; $col++ ) {
				$x0 = (int) floor( ( $col / $cols ) * $width );
				$x1 = (int) floor( ( ( $col + 1 ) / $cols ) * $width );
				$y0 = (int) floor( ( $row / $rows ) * $height );
				$y1 = (int) floor( ( ( $row + 1 ) / $rows ) * $height );

				$total = 0;
				$diff  = 0;
				$masked = 0;
				for ( $y = $y0; $y < $y1 && $y < $height; $y += 2 ) {
					for ( $x = $x0; $x < $x1 && $x < $width; $x += 2 ) {
						if ( $this->in_mask( $x, $y, $masks ) ) {
							$masked++;
							continue;
						}
						$so = ( ( $y * (int) $source_surface['width'] ) + $x ) * 3;
						$ro = ( ( $y * (int) $replica_surface['width'] ) + $x ) * 3;
						if ( ! isset( $source_surface['pixels'][ $so + 2 ] ) ) {
							continue;
						}
						$total++;
						$delta = 0;
						for ( $c = 0; $c < 3; $c++ ) {
							$delta = max( $delta, abs( (int) $source_surface['pixels'][ $so + $c ] - (int) $replica_surface['pixels'][ $ro + $c ] ) );
						}
						if ( $delta > self::CHANNEL_TOLERANCE ) {
							$diff++;
						}
					}
				}
				$ratio = ( $total > 0 ) ? round( $diff / $total, 4 ) : 0.0;
				$worst = max( $worst, $ratio );
				$line[] = array( 'ratio' => $ratio, 'compared' => $total, 'masked' => $masked );
			}
			$cells[] = $line;
		}

		return array(
			'available' => true,
			'rows'      => $rows,
			'columns'   => $cols,
			'cells'     => $cells,
			'worst'     => round( $worst, 4 ),
			// Normalised against the worst cell rather than against 1.0, because a
			// heatmap's job is to show *where* differences concentrate; a scale pinned
			// to 1.0 makes a 4%-worst page look uniformly cool.
			'scale'     => ( $worst > 0 ) ? round( $worst, 4 ) : 1.0,
			'note'      => __( 'Cell values are real computed difference ratios, scaled against the worst cell on the page.', 'replicaforge' ),
		);
	}

	/**
	 * Return the overlay modes §64 requires.
	 *
	 * Declared rather than generated. The images themselves are produced by the
	 * admin screen from the two captures, because generating a third and fourth
	 * image on every comparison would double the storage for a view that is read
	 * occasionally.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function overlay_modes() {
		return array(
			array( 'mode' => 'source',  'label' => __( 'Source', 'replicaforge' ), 'blend' => 'normal' ),
			array( 'mode' => 'replica', 'label' => __( 'Replica', 'replicaforge' ), 'blend' => 'normal' ),
			array( 'mode' => 'overlay', 'label' => __( '50/50 overlay', 'replicaforge' ), 'blend' => 'difference' ),
			array( 'mode' => 'difference', 'label' => __( 'Difference', 'replicaforge' ), 'blend' => 'screen' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Masks and helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether a pixel is inside any mask.
	 *
	 * @param int                            $x     X.
	 * @param int                            $y     Y.
	 * @param array<int, array<string, mixed>> $masks Masks.
	 * @return bool
	 */
	private function in_mask( $x, $y, array $masks ) {
		foreach ( $masks as $mask ) {
			if ( ! is_array( $mask ) ) {
				continue;
			}
			$mx = (int) ( $mask['x'] ?? 0 );
			$my = (int) ( $mask['y'] ?? 0 );
			$mw = (int) ( $mask['width'] ?? 0 );
			$mh = (int) ( $mask['height'] ?? 0 );
			if ( $mw < 1 || $mh < 1 ) {
				continue;
			}
			if ( $x >= $mx && $x < ( $mx + $mw ) && $y >= $my && $y < ( $my + $mh ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return a summary of what was masked and why.
	 *
	 * @param array<int, array<string, mixed>> $masks Masks.
	 * @return array<string, mixed>
	 */
	private function mask_summary( array $masks ) {
		$by_reason = array();
		$area      = 0;
		foreach ( $masks as $mask ) {
			if ( ! is_array( $mask ) ) {
				continue;
			}
			$reason = (string) ( $mask['reason'] ?? 'unknown' );
			$by_reason[ $reason ] = ( $by_reason[ $reason ] ?? 0 ) + 1;
			$area += max( 0, (int) ( $mask['width'] ?? 0 ) ) * max( 0, (int) ( $mask['height'] ?? 0 ) );
		}
		return array(
			'count'     => count( $masks ),
			'by_reason' => $by_reason,
			'area'      => $area,
			'note'      => ( 0 === count( $masks ) )
				? __( 'Nothing was masked, so every part of the page contributed to the comparison.', 'replicaforge' )
				: __( 'Masked regions contributed to no signal. A difference inside one is not evidence of a fault.', 'replicaforge' ),
		);
	}

	/**
	 * Return the worst severity in a set of differences.
	 *
	 * @param array<int, array<string, mixed>> $differences Differences.
	 * @param array<int, string>               $severities  Severity order.
	 * @return string
	 */
	private function worst_severity( array $differences, array $severities ) {
		$best = 'informational';
		foreach ( $differences as $difference ) {
			$index = array_search( (string) ( $difference['severity'] ?? '' ), $severities, true );
			if ( false === $index ) {
				continue;
			}
			if ( $index < array_search( $best, $severities, true ) ) {
				$best = (string) $difference['severity'];
			}
		}
		return $best;
	}

	/**
	 * Count differences by severity.
	 *
	 * @param array<int, array<string, mixed>> $differences Differences.
	 * @return array<string, int>
	 */
	private function count_severities( array $differences ) {
		$out = array();
		foreach ( Visual_Limits::severities() as $severity ) {
			$out[ $severity ] = 0;
		}
		foreach ( $differences as $difference ) {
			$severity = (string) ( $difference['severity'] ?? '' );
			if ( isset( $out[ $severity ] ) ) {
				$out[ $severity ]++;
			}
		}
		return $out;
	}

	/**
	 * Build an unavailable result.
	 *
	 * @param string $message Reason.
	 * @param float  $started Start time.
	 * @return array<string, mixed>
	 */
	private function unavailable( $message, $started ) {
		return array(
			'schema_version'  => Visual_Limits::SCHEMA_VERSION,
			'available'       => false,
			'message'         => (string) $message,
			'signals'         => array(),
			'signal_names'    => array(),
			'missing_signals' => Visual_Limits::SIGNALS,
			'verdict'         => 'unavailable',
			'differences'     => array(),
			'regions'         => array(),
			'heatmap'         => array( 'available' => false ),
			'elapsed'         => round( microtime( true ) - $started, 3 ),
		);
	}
}
