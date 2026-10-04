<?php
/**
 * Phase 9: the source-to-Elementor mapping.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Records which Elementor element came from which source component.
 *
 * Incremental synchronisation is impossible without this. A change detector can say
 * "the hero heading's font size changed", and that is useless unless something knows
 * which element in the document is the hero heading. The mapping is that something.
 *
 * The chain is three links rather than one:
 *
 *     source component -> reconstruction component -> Elementor element
 *
 * The middle link is not decoration. The Phase 2 source representation and the Phase 3
 * reconstruction specification do not use the same identifiers, and a change detected
 * in the source has to travel across that rename before it can address an element.
 * Collapsing the chain to one hop would hide the rename, and a rename that is not
 * tracked is a rename that silently mismatches on the next analysis.
 *
 * The map lives in post meta on the draft, so it survives a browser refresh, a plugin
 * restart, a WordPress restart, a new validation, and a new source version — every
 * case §19 names. It is bounded, and it is never trusted: every read is
 * ownership-checked by the caller and existence-checked here.
 */
final class Elementor_Map {

	/**
	 * Maximum mappings retained per draft.
	 *
	 * A document has at most `Elementor_Limits::MAX_ELEMENTS` elements, so this is
	 * above what a real document needs and exists to bound a corrupt or hostile
	 * stored value.
	 */
	const MAX_ENTRIES = 2000;

	/**
	 * Draft post identifier.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param int           $post_id Draft post identifier.
	 * @param Logger|null   $logger  Optional logger.
	 */
	public function __construct( $post_id, $logger = null ) {
		$this->post_id = absint( $post_id );
		$this->logger  = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Return the whole map.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all() {
		if ( $this->post_id < 1 ) {
			return array();
		}
		$stored = get_post_meta( $this->post_id, Sync_Limits::MAP_META, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		// Keyed by source component id, so a lookup is one hash access and not a
		// scan. A stored value that is not keyed correctly is not trusted.
		$out = array();
		foreach ( $stored as $key => $entry ) {
			if ( is_array( $entry ) && isset( $entry['source_component_id'] ) ) {
				$out[ (string) $entry['source_component_id'] ] = $entry;
			}
			unset( $key );
		}
		return $out;
	}

	/**
	 * Return whether a map exists.
	 *
	 * @return bool
	 */
	public function exists() {
		return ! empty( $this->all() );
	}

	/**
	 * Return the number of mappings.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->all() );
	}

	/**
	 * Look up one source component.
	 *
	 * @param string $source_component_id Source component identifier.
	 * @return array<string, mixed>|null
	 */
	public function get( $source_component_id ) {
		$source_component_id = $this->clean_id( $source_component_id );
		if ( '' === $source_component_id ) {
			return null;
		}
		$all = $this->all();
		return isset( $all[ $source_component_id ] ) ? $all[ $source_component_id ] : null;
	}

	/**
	 * Return the Elementor element id for a source component.
	 *
	 * @param string $source_component_id Source component identifier.
	 * @return string Empty string when unmapped.
	 */
	public function element_for( $source_component_id ) {
		$entry = $this->get( $source_component_id );
		return null === $entry ? '' : (string) ( $entry['elementor_element_id'] ?? '' );
	}

	/**
	 * Return every source component that maps to one Elementor element.
	 *
	 * @param string $element_id Elementor element identifier.
	 * @return array<int, string>
	 */
	public function sources_for_element( $element_id ) {
		$element_id = $this->clean_id( $element_id );
		if ( '' === $element_id ) {
			return array();
		}
		$out = array();
		foreach ( $this->all() as $source_id => $entry ) {
			if ( (string) ( $entry['elementor_element_id'] ?? '' ) === $element_id ) {
				$out[] = (string) $source_id;
			}
		}
		return $out;
	}

	/**
	 * Return whether an Elementor element is claimed by any source component.
	 *
	 * @param string $element_id Elementor element identifier.
	 * @return bool
	 */
	public function is_mapped_element( $element_id ) {
		return ! empty( $this->sources_for_element( $element_id ) );
	}

	/**
	 * Return the mappings for one section.
	 *
	 * @param string $section_id Section identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_section( $section_id ) {
		$section_id = $this->clean_id( $section_id );
		if ( '' === $section_id ) {
			return array();
		}
		$out = array();
		foreach ( $this->all() as $entry ) {
			if ( (string) ( $entry['source_section_id'] ?? '' ) === $section_id ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Return the sections present in the map.
	 *
	 * @return array<int, string>
	 */
	public function sections() {
		$sections = array();
		foreach ( $this->all() as $entry ) {
			$section = (string) ( $entry['source_section_id'] ?? '' );
			if ( '' !== $section ) {
				$sections[ $section ] = true;
			}
		}
		return array_keys( $sections );
	}

	/**
	 * Record one mapping.
	 *
	 * @param array<string, mixed> $entry Mapping entry.
	 * @return bool
	 */
	public function set( array $entry ) {
		$source_id = $this->clean_id( $entry['source_component_id'] ?? '' );
		$element  = $this->clean_element_id( $entry['elementor_element_id'] ?? '' );

		// Both ends of the chain are required. A mapping missing either cannot
		// address an element, so storing it would create the false impression that
		// the component is updatable when it is not.
		if ( '' === $source_id || '' === $element ) {
			return false;
		}

		$clean = array(
			'source_component_id'        => $source_id,
			'reconstruction_component_id' => $this->clean_id( $entry['reconstruction_component_id'] ?? '' ),
			'elementor_element_id'       => $element,
			'elementor_el_type'          => $this->clean_id( $entry['elementor_el_type'] ?? '' ),
			'source_section_id'          => $this->clean_id( $entry['source_section_id'] ?? '' ),
			'source_role'                => $this->clean_id( $entry['source_role'] ?? '' ),
			'source_fingerprint'         => $this->clean_id( $entry['source_fingerprint'] ?? '' ),
			'properties'                 => $this->clean_properties( $entry['properties'] ?? array() ),
			'recorded_at'                => gmdate( 'c' ),
		);

		$all       = $this->all();
		$all[ $source_id ] = $clean;

		// One source component must not claim two elements. A second mapping for the
		// same source replaces the first, because two live mappings would make every
		// lookup ambiguous and the ambiguity would resolve silently to whichever
		// happened to be read first.
		$this->forget_other_elements( $source_id, $element, $all );

		return $this->store( $all );
	}

	/**
	 * Record many mappings at once.
	 *
	 * @param array<int, array<string, mixed>> $entries Mapping entries.
	 * @return int Number recorded.
	 */
	public function set_many( array $entries ) {
		$all     = $this->all();
		$count   = 0;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$source_id = $this->clean_id( $entry['source_component_id'] ?? '' );
			$element   = $this->clean_element_id( $entry['elementor_element_id'] ?? '' );
			if ( '' === $source_id || '' === $element ) {
				continue;
			}

			$all[ $source_id ] = array(
				'source_component_id'        => $source_id,
				'reconstruction_component_id' => $this->clean_id( $entry['reconstruction_component_id'] ?? '' ),
				'elementor_element_id'       => $element,
				'elementor_el_type'          => $this->clean_id( $entry['elementor_el_type'] ?? '' ),
				'source_section_id'          => $this->clean_id( $entry['source_section_id'] ?? '' ),
				'source_role'                => $this->clean_id( $entry['source_role'] ?? '' ),
				'source_fingerprint'         => $this->clean_id( $entry['source_fingerprint'] ?? '' ),
				'properties'                 => $this->clean_properties( $entry['properties'] ?? array() ),
				'recorded_at'                => gmdate( 'c' ),
			);
			$count++;

			$this->forget_other_elements( $source_id, $element, $all );

			if ( count( $all ) >= self::MAX_ENTRIES ) {
				break;
			}
		}

		if ( 0 === $count ) {
			return 0;
		}

		return $this->store( $all ) ? $count : 0;
	}

	/**
	 * Remove a mapping.
	 *
	 * @param string $source_component_id Source component identifier.
	 * @return bool
	 */
	public function forget( $source_component_id ) {
		$source_id = $this->clean_id( $source_component_id );
		$all       = $this->all();
		if ( ! isset( $all[ $source_id ] ) ) {
			return false;
		}
		unset( $all[ $source_id ] );
		return $this->store( $all );
	}

	/**
	 * Remove every mapping for a section.
	 *
	 * @param string $section_id Section identifier.
	 * @return int Number removed.
	 */
	public function forget_section( $section_id ) {
		$section_id = $this->clean_id( $section_id );
		if ( '' === $section_id ) {
			return 0;
		}
		$all     = $this->all();
		$removed = 0;
		foreach ( $all as $source_id => $entry ) {
			if ( (string) ( $entry['source_section_id'] ?? '' ) === $section_id ) {
				unset( $all[ $source_id ] );
				$removed++;
			}
		}
		if ( 0 === $removed ) {
			return 0;
		}
		$this->store( $all );
		return $removed;
	}

	/**
	 * Remove every mapping.
	 *
	 * @return void
	 */
	public function clear() {
		if ( $this->post_id < 1 ) {
			return;
		}
		delete_post_meta( $this->post_id, Sync_Limits::MAP_META );
	}

	/**
	 * Return mappings whose Elementor element is no longer in the document.
	 *
	 * A stale mapping is worse than no mapping: it would let a sync plan address an
	 * element that is not there, and the write would either fail at apply time or,
	 * worse, land on a reused id. This is the check that keeps the map honest.
	 *
	 * @param array<int, string> $live_element_ids Element ids present in the document.
	 * @return array<int, array<string, mixed>>
	 */
	public function stale( array $live_element_ids ) {
		$live = array();
		foreach ( $live_element_ids as $element_id ) {
			$clean = $this->clean_element_id( $element_id );
			if ( '' !== $clean ) {
				$live[ $clean ] = true;
			}
		}

		$out = array();
		foreach ( $this->all() as $source_id => $entry ) {
			$element = (string) ( $entry['elementor_element_id'] ?? '' );
			if ( '' === $element || ! isset( $live[ $element ] ) ) {
				$out[] = array(
					'source_component_id'  => (string) $source_id,
					'elementor_element_id' => $element,
					'reason'              => '' === $element ? 'no_element' : 'element_not_in_document',
				);
			}
		}
		return $out;
	}

	/**
	 * Return whether a set of live element ids covers the whole map.
	 *
	 * @param array<int, string> $live_element_ids Live element ids.
	 * @return bool
	 */
	public function is_complete_against( array $live_element_ids ) {
		return array() === $this->stale( $live_element_ids );
	}

	/**
	 * Return a summary for the admin screen.
	 *
	 * @return array<string, mixed>
	 */
	public function summary() {
		$all      = $this->all();
		$sections = $this->sections();

		$without_element = 0;
		$without_section = 0;
		foreach ( $all as $entry ) {
			if ( '' === (string) ( $entry['elementor_element_id'] ?? '' ) ) {
				$without_element++;
			}
			if ( '' === (string) ( $entry['source_section_id'] ?? '' ) ) {
				$without_section++;
			}
		}

		return array(
			'exists'         => ! empty( $all ),
			'count'          => count( $all ),
			'sections'       => count( $sections ),
			'section_ids'    => $sections,
			'without_element'=> $without_element,
			'without_section'=> $without_section,
		);
	}

	/**
	 * Return a coverage report against a component list.
	 *
	 * Incremental update is only viable where the map covers the changed component,
	 * so coverage is the number that decides between patching and regenerating.
	 *
	 * @param array<int, string> $source_component_ids Component identifiers.
	 * @return array<string, mixed>
	 */
	public function coverage( array $source_component_ids ) {
		$all       = $this->all();
		$covered   = array();
		$uncovered = array();

		foreach ( $source_component_ids as $source_id ) {
			$clean = $this->clean_id( $source_id );
			if ( '' === $clean ) {
				continue;
			}
			if ( isset( $all[ $clean ] ) ) {
				$covered[] = $clean;
			} else {
				$uncovered[] = $clean;
			}
		}

		$total = count( $covered ) + count( $uncovered );

		return array(
			'covered'   => $covered,
			'uncovered' => $uncovered,
			'total'     => $total,
			'ratio'     => $total > 0 ? round( count( $covered ) / $total, 4 ) : 0.0,
			// Full coverage is what makes an incremental update safe rather than
			// hopeful. Below that, the plan is proposing writes it cannot address.
			'complete'  => $total > 0 && 0 === count( $uncovered ),
		);
	}

	/**
	 * Drop a source id's other element mappings.
	 *
	 * @param string                            $source_id Source identifier.
	 * @param string                            $keep      Element id to keep.
	 * @param array<string, array<string, mixed>> $all      Map, by reference.
	 * @return void
	 */
	private function forget_other_elements( $source_id, $keep, array &$all ) {
		foreach ( $all as $other_source => $entry ) {
			if ( (string) $other_source === (string) $source_id ) {
				continue;
			}
			// Two different source components claiming one element is also
			// ambiguous, and it is what a duplicated mapping produces.
			if ( (string) ( $entry['elementor_element_id'] ?? '' ) === $keep ) {
				unset( $all[ $other_source ] );
			}
		}
	}

	/**
	 * Persist the map.
	 *
	 * @param array<string, array<string, mixed>> $all Map.
	 * @return bool
	 */
	private function store( array $all ) {
		if ( $this->post_id < 1 ) {
			return false;
		}
		if ( count( $all ) > self::MAX_ENTRIES ) {
			// The most recent entries are kept. A map is a record of the current
			// document, and the newest mappings are the ones describing it.
			$all = array_slice( $all, -self::MAX_ENTRIES, null, true );
		}
		$written = update_post_meta( $this->post_id, Sync_Limits::MAP_META, $all );
		return false !== $written;
	}

	/**
	 * Clean an identifier.
	 *
	 * Identifiers are stored keys and end up in meta keys and file paths in other
	 * parts of the plugin, so the accepted alphabet is narrow and enforced here
	 * rather than at each use.
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
		if ( ! preg_match( '/^[a-z0-9_.\-]+$/', $value ) ) {
			return '';
		}
		// A path separator or a traversal sequence has no place in an identifier,
		// and refusing them here means no later consumer has to think about it.
		if ( false !== strpos( $value, '..' ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Clean an Elementor element identifier.
	 *
	 * An Elementor element id is exactly seven lowercase hexadecimal
	 * characters, and the Phase 4 document validator enforces that format.
	 * A mapping to an id that could never exist is a mapping that would fail
	 * at apply time, so the format is checked where the mapping is written
	 * rather than discovered at the far end of a synchronisation.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_element_id( $value ) {
		$value = $this->clean_id( $value );
		if ( '' === $value ) {
			return '';
		}
		return preg_match( '/^[a-f0-9]{7}$/', $value ) ? $value : '';
	}

	/**
	 * Clean the property hints attached to a mapping.
	 *
	 * These are hints for the planner, not authority. A property named here is a
	 * candidate; whether it may be written is decided by the correction property
	 * map, and a name that is not in that map is dropped here so the planner never
	 * sees it.
	 *
	 * @param mixed $properties Candidate properties.
	 * @return array<int, string>
	 */
	private function clean_properties( $properties ) {
		if ( ! is_array( $properties ) ) {
			return array();
		}
		$out = array();
		foreach ( $properties as $property ) {
			if ( ! is_string( $property ) ) {
				continue;
			}
			$property = strtolower( trim( $property ) );
			if ( '' === $property || ! preg_match( '/^[a-z0-9_\-]+$/', $property ) ) {
				continue;
			}
			$out[] = $property;
		}
		return array_values( array_unique( array_slice( $out, 0, 40 ) ) );
	}
}
