<?php
/**
 * Phase 9: conflict detection between the source and the user's own edits.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Decides, for one property, whether ReplicaForge may write it.
 *
 * This is the part of synchronisation that protects a person's work, and it is the
 * reason synchronisation is safe enough to exist at all. The situation it exists for:
 *
 *     ReplicaForge generated:   font-size = 48px
 *     The user changed it to:   font-size = 56px
 *     The source now says:      font-size = 52px
 *
 * A naive synchronisation writes 52px and the user loses their 56px without ever
 * being told. A worse one writes 48px. Both are silent, and the first is the reason
 * people do not trust automated tools with their own pages.
 *
 * So every proposed write is resolved by comparing three values rather than two:
 * what ReplicaForge generated, what is in the document now, and what the source now
 * says. That is a three-way merge, and the interesting case is the one where all
 * three differ.
 *
 * The two cases that are easy to get wrong and are handled explicitly here:
 *
 *   - The user and the source independently made the *same* change. There is
 *     nothing to reconcile and nothing to ask about; applying the source value would
 *     be a no-op and reporting a conflict would be noise.
 *   - ReplicaForge has no recorded baseline for the property. It has not written
 *     that property, so it has no standing to claim the source owns it. That is
 *     `unknown`, and an unknown is never treated as permission.
 *
 * The three-way comparison is not new logic invented here. Phase 6 already records
 * what ReplicaForge wrote in `Correction_Snapshot`, and already answers "did a human
 * change this?". This class composes those two answers with the source value, which
 * is the only genuinely new step. Reusing them means there is one record of what
 * ReplicaForge wrote, not two that can disagree.
 */
final class Sync_Conflict_Detector {

	/**
	 * Property ownership states, per §21.
	 *
	 * @var array<int, string>
	 */
	const OWNERSHIP = array(
		'source_controlled',
		'user_controlled',
		'mixed',
		'unknown',
	);

	/**
	 * Loadable snapshot, which is the record of what ReplicaForge wrote.
	 *
	 * @var Correction_Snapshot
	 */
	private $snapshots;

	/**
	 * The property whitelist, which is the security boundary.
	 *
	 * @var Correction_Property_Map
	 */
	private $properties;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Correction_Snapshot|null      $snapshots  Snapshot store.
	 * @param Correction_Property_Map|null  $properties Property whitelist.
	 * @param Logger|null                   $logger     Optional logger.
	 */
	public function __construct( $snapshots = null, $properties = null, $logger = null ) {
		$this->snapshots  = $snapshots instanceof Correction_Snapshot ? $snapshots : new Correction_Snapshot();
		$this->properties = $properties instanceof Correction_Property_Map ? $properties : new Correction_Property_Map();
		$this->logger     = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Resolve the ownership and conflict state of one property.
	 *
	 * @param Elementor_Document_Reader $reader     Loaded document reader.
	 * @param int                       $post_id    Draft post identifier.
	 * @param string                    $element_id Elementor element identifier.
	 * @param string                    $property   Comparison property.
	 * @param mixed                     $source_value The value the source now declares.
	 * @param string                    $device     Device key.
	 * @return array<string, mixed>
	 */
	public function resolve( Elementor_Document_Reader $reader, $post_id, $element_id, $property, $source_value, $device = 'desktop' ) {
		$post_id    = absint( $post_id );
		$element_id = $this->clean_id( $element_id );
		$property   = $this->clean_property( $property );

		$out = array(
			'elementor_element_id' => $element_id,
			'property'             => $property,
			'device'               => $this->properties->device( $device ),
			'ownership'            => 'unknown',
			'conflict'             => 'unknown',
			'source_value'         => $source_value,
			'current_value'        => null,
			'generated_value'      => null,
			'has_baseline'         => false,
			'writable'             => false,
			'reason'               => null,
			'recommendation'       => 'skip',
		);

		// A property ReplicaForge may not write is refused before anything is
		// compared, because a conflict resolution for a property that cannot be
		// written is a question about nothing.
		if ( '' === $property || ! $this->properties->is_writable( $property ) ) {
			$out['reason'] = 'The property is not on the write whitelist, so no value of it can ever be written.';
			return $out;
		}

		if ( $post_id < 1 || '' === $element_id ) {
			$out['reason'] = 'No draft and element were given, so there is nothing to compare against.';
			return $out;
		}

		$element = $reader->element( $element_id );
		if ( null === $element ) {
			$out['reason'] = 'The Elementor element is not in the document, so its value cannot be read.';
			return $out;
		}

		$baseline = $this->snapshots->baseline_value( $post_id, $element_id, $property, $device );
		$current  = $this->snapshots->current_value( $reader, $element_id, $property, $device );

		$out['generated_value'] = $baseline['value'];
		$out['has_baseline']    = (bool) $baseline['recorded'];
		$out['current_value']   = $current;

		$source_changed = ! $this->same( $source_value, $baseline['recorded'] ? $baseline['value'] : null );
		$user_changed   = $baseline['recorded'] && ! $this->same( $baseline['value'], $current );

		$out['source_changed'] = $source_changed;
		$out['user_changed']   = $user_changed;
		$out['ownership']      = $this->ownership_for( $baseline['recorded'], $source_changed, $user_changed );

		if ( ! $baseline['recorded'] ) {
			// ReplicaForge has never written this property. It cannot claim the
			// source owns it, and it certainly cannot claim the user's value is
			// wrong. The honest state is unknown, and an unknown is not permission.
			$out['conflict']       = 'unknown';
			$out['reason']         = 'ReplicaForge has no recorded value for this property, so it cannot tell whether the source or the replica changed.';
			$out['recommendation'] = 'review';
			return $out;
		}

		if ( $source_changed && $user_changed ) {
			// The interesting case. Two sub-cases that must not be conflated.
			//
			// The agreement shortcut requires a value to agree on. Two absent values
			// are not an agreement: `same(null, null)` is true, so without this guard
			// a source that declares nothing and a replica that holds nothing would
			// be reported as already agreeing, and the change would be silently
			// dropped while the report claimed there was nothing to do.
			$either_present = ( null !== $source_value || null !== $current );

			if ( $either_present && $this->same( $current, $source_value ) ) {
				// The user and the source arrived at the same value independently.
				// There is nothing to reconcile: applying the source would change
				// nothing, and calling it a conflict would train a user to dismiss
				// conflicts.
				$out['conflict']       = 'no_conflict';
				$out['reason']         = 'The source and the replica already hold the same value, so applying it would change nothing.';
				$out['recommendation'] = 'skip';
				$out['writable']       = false;
				return $out;
			}

			if ( null === $source_value && null === $current ) {
				// Neither side holds a value, so there is nothing to write and
				// nothing to disagree about. It is reported as such rather than as
				// an agreement between two absences.
				$out['conflict']       = 'no_conflict';
				$out['reason']         = 'Neither the source nor the replica holds a value for this property, so there is nothing to write.';
				$out['recommendation'] = 'skip';
				$out['writable']       = false;
				return $out;
			}

			$out['conflict']       = 'both_changed';
			$out['reason']         = 'The source changed and the replica changed, to different values. Writing either one would discard the other.';
			$out['recommendation'] = 'review';
			$out['writable']       = false;
			return $out;
		}

		if ( $source_changed && ! $user_changed ) {
			$out['conflict']       = 'source_only';
			$out['reason']         = 'Only the source changed, and the replica still holds the value ReplicaForge generated.';
			$out['recommendation'] = 'apply';
			$out['writable']       = true;
			return $out;
		}

		if ( ! $source_changed && $user_changed ) {
			$out['conflict']       = 'user_only';
			$out['reason']         = 'Only the replica changed. The user\'s value stands and the source is not applied.';
			$out['recommendation'] = 'skip';
			$out['writable']       = false;
			return $out;
		}

		$out['conflict']       = 'no_conflict';
		$out['reason']         = 'Neither the source nor the replica changed, so there is nothing to do.';
		$out['recommendation'] = 'skip';
		$out['writable']       = false;

		return $out;
	}

	/**
	 * Resolve a whole set of proposed changes.
	 *
	 * @param Elementor_Document_Reader $reader  Loaded document reader.
	 * @param int                       $post_id Draft post identifier.
	 * @param array<int, array<string, mixed>> $changes Proposed changes.
	 * @param string                    $device  Device key.
	 * @return array<string, mixed>
	 */
	public function resolve_all( Elementor_Document_Reader $reader, $post_id, array $changes, $device = 'desktop' ) {
		$resolved = array();
		$counts   = array_fill_keys( Sync_Limits::CONFLICT_STATES, 0 );

		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			$element_id = (string) ( $change['elementor_element_id'] ?? '' );
			$property   = (string) ( $change['property'] ?? '' );

			// A change that addresses no element cannot be conflict-checked, and it
			// is not skipped either: it is reported as unknown so the count of
			// unresolved work is honest.
			$result = $this->resolve(
				$reader,
				$post_id,
				$element_id,
				$property,
				$change['source_value'] ?? null,
				isset( $change['device'] ) ? (string) $change['device'] : $device
			);

			$result['change_id']          = (string) ( $change['id'] ?? '' );
			$result['source_component_id'] = (string) ( $change['source_component_id'] ?? '' );
			$result['category']           = (string) ( $change['category'] ?? '' );

			$state = Sync_Limits::is_conflict_state( $result['conflict'] ) ? $result['conflict'] : 'unknown';
			$result['conflict'] = $state;
			$counts[ $state ]++;

			$resolved[] = $result;
		}

		$writable = 0;
		foreach ( $resolved as $result ) {
			if ( ! empty( $result['writable'] ) ) {
				$writable++;
			}
		}

		return array(
			'resolved'     => $resolved,
			'counts'       => $counts,
			'writable'     => $writable,
			'conflicted'   => (int) $counts['both_changed'],
			'unknown'      => (int) $counts['unknown'],
			'needs_review' => $writable + (int) $counts['both_changed'] + (int) $counts['unknown'],
		);
	}

	/**
	 * Return the ownership state for a set of resolution inputs.
	 *
	 * @param bool $baseline        Whether a baseline was recorded.
	 * @param bool $source_changed  Whether the source changed.
	 * @param bool $user_changed    Whether the replica changed.
	 * @return string
	 */
	public function ownership_for( $baseline, $source_changed, $user_changed ) {
		if ( ! $baseline ) {
			// Nothing was written, so nobody owns the property. The brief's four
			// states are exhaustive, and this is the honest one.
			return 'unknown';
		}
		if ( $source_changed && $user_changed ) {
			return 'mixed';
		}
		if ( $user_changed ) {
			return 'user_controlled';
		}
		if ( $source_changed ) {
			return 'source_controlled';
		}
		return 'source_controlled';
	}

	/**
	 * Return whether a set of resolutions contains anything that must not be applied
	 * without a person.
	 *
	 * @param array<int, array<string, mixed>> $resolved Resolutions.
	 * @return array<string, mixed>
	 */
	public function gate( array $resolved ) {
		$blocked = array();
		foreach ( $resolved as $result ) {
			$state = (string) ( $result['conflict'] ?? 'unknown' );
			if ( 'both_changed' === $state || 'unknown' === $state ) {
				$blocked[] = $result;
			}
		}

		return array(
			'clear'            => array() === $blocked,
			'blocked'          => $blocked,
			'blocked_count'    => count( $blocked ),
			// A plan with anything blocked is not refused outright. It is offered
			// for review with the blocked items marked, because a person may well
			// want the ten safe changes and to decide about the one conflict.
			'requires_review'  => array() !== $blocked,
		);
	}

	/**
	 * Summarize a set of resolutions for the review screen.
	 *
	 * The brief asks for Safe / Review / Conflict / Blocked rather than technical
	 * terms, so the summary is keyed that way and the technical state is carried
	 * alongside for anyone who needs it.
	 *
	 * @param array<int, array<string, mixed>> $resolved Resolutions.
	 * @return array<string, mixed>
	 */
	public function summary( array $resolved ) {
		$buckets = array(
			'safe'     => 0,
			'review'   => 0,
			'conflict' => 0,
			'blocked'  => 0,
		);

		foreach ( $resolved as $result ) {
			switch ( (string) ( $result['conflict'] ?? 'unknown' ) ) {
				case 'no_conflict':
					// A safe item is one that can be applied with no decision.
					if ( ! empty( $result['writable'] ) ) {
						$buckets['safe']++;
					}
					break;
				case 'source_only':
					$buckets['safe']++;
					break;
				case 'user_only':
					// The user's value stands on its own. It is not blocked and not
					// safe to apply; it simply needs no decision, so it is not counted
					// in a bucket that implies one.
					break;
				case 'both_changed':
					$buckets['conflict']++;
					break;
				case 'unknown':
				default:
					$buckets['review']++;
					break;
			}
		}

		// A property ReplicaForge may not write is blocked rather than reviewable,
		// so it is counted separately from the conflicts.
		foreach ( $resolved as $result ) {
			if ( 'unknown' === (string) ( $result['conflict'] ?? '' )
				&& false !== strpos( (string) ( $result['reason'] ?? '' ), 'whitelist' ) ) {
				$buckets['review']--;
				$buckets['blocked']++;
			}
		}

		return array(
			'buckets'  => $buckets,
			'total'    => count( $resolved ),
			'headline' => $this->headline( $buckets ),
		);
	}

	/**
	 * Return the one-line summary the review screen leads with.
	 *
	 * @param array<string, int> $buckets Bucket counts.
	 * @return string
	 */
	private function headline( array $buckets ) {
		$parts = array();
		if ( $buckets['safe'] > 0 ) {
			$parts[] = sprintf( '%d safe', $buckets['safe'] );
		}
		if ( $buckets['review'] > 0 ) {
			$parts[] = sprintf( '%d need review', $buckets['review'] );
		}
		if ( $buckets['conflict'] > 0 ) {
			$parts[] = sprintf( '%d conflict', $buckets['conflict'] );
		}
		if ( $buckets['blocked'] > 0 ) {
			$parts[] = sprintf( '%d blocked', $buckets['blocked'] );
		}

		if ( array() === $parts ) {
			return 'No changes need a decision.';
		}

		return implode( ', ', $parts ) . '.';
	}

	/**
	 * Return the one state a person is most likely to need to act on first.
	 *
	 * @param array<int, array<string, mixed>> $resolved Resolutions.
	 * @return string
	 */
	public function blocking_state( array $resolved ) {
		$states = array( 'both_changed' => 0, 'unknown' => 0 );
		foreach ( $resolved as $result ) {
			$state = (string) ( $result['conflict'] ?? '' );
			if ( isset( $states[ $state ] ) ) {
				$states[ $state ]++;
			}
		}

		if ( $states['both_changed'] > 0 ) {
			return 'both_changed';
		}
		if ( $states['unknown'] > 0 ) {
			return 'unknown';
		}
		return 'none';
	}

	/**
	 * Return whether two values are the same for comparison purposes.
	 *
	 * String and numeric comparison are both needed, because a control read back
	 * from a document is often a string and a source value is often a number, and
	 * `"48" !== 48` would report every unchanged property as changed.
	 *
	 * @param mixed $left  One value.
	 * @param mixed $right The other.
	 * @return bool
	 */
	private function same( $left, $right ) {
		if ( $left === $right ) {
			return true;
		}
		if ( null === $left || null === $right ) {
			// A missing value and a null value are the same absence, but a missing
			// value and a real one are not, and `null == "0"` is true in PHP, which
			// would make an unset property look like a zero.
			return null === $left && null === $right;
		}
		if ( is_array( $left ) || is_array( $right ) ) {
			return wp_json_encode( $left ) === wp_json_encode( $right );
		}
		if ( is_bool( $left ) || is_bool( $right ) ) {
			return (bool) $left === (bool) $right;
		}
		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			return abs( (float) $left - (float) $right ) < 0.0001;
		}

		return 0 === strcmp( (string) $left, (string) $right );
	}

	/**
	 * Clean an element identifier.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value || strlen( $value ) > 120 ) {
			return '';
		}
		return preg_match( '/^[a-z0-9_.\-]+$/', $value ) ? $value : '';
	}

	/**
	 * Clean a property name.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_property( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value || strlen( $value ) > 60 ) {
			return '';
		}
		return preg_match( '/^[a-z0-9_\-]+$/', $value ) ? $value : '';
	}
}
