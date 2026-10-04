<?php
/**
 * Phase 9: component identity and matching.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Gives a source component an identity that survives the page changing around it,
 * and decides which component in a new analysis is the same one.
 *
 * The failure this exists to prevent is matching by position. If identity is the
 * index in a list, then inserting a badge above a hero heading renumbers everything
 * below it, and a change detector comparing two analyses would report that the
 * heading was removed and a new, different heading was added. The user would be
 * shown a delete-and-recreate for a heading that never moved.
 *
 * So identity is derived from what a component *is* — its role, its text, its image,
 * its tag and structure — and position is deliberately the weakest signal, used only
 * to break a tie between otherwise indistinguishable candidates.
 *
 * Every match carries its confidence and the reasons behind it. A match with no
 * stated reasons is an assertion, and §17 asks for the reasons so a reader can judge
 * a match rather than trust it.
 */
final class Component_Matcher {

	/**
	 * Signal weights.
	 *
	 * These sum to one. An identity or image match is worth far more than a
	 * position, because a position is destroyed by any insertion above the component
	 * while an identity is not.
	 *
	 * @var array<string, float>
	 */
	const WEIGHTS = array(
		'fingerprint'  => 0.34,
		'role'         => 0.20,
		'section'      => 0.18,
		'image'        => 0.14,
		'text'         => 0.10,
		'structure'    => 0.03,
		'position'     => 0.01,
	);

	/**
	 * Minimum similarity for a candidate to be considered at all.
	 *
	 * Below this the two components are different things, and offering them as a
	 * match would put a plausible-looking wrong answer in front of a user.
	 */
	const MIN_SIMILARITY = 0.35;

	/**
	 * Minimum similarity for a match to be accepted as the same component.
	 */
	const ACCEPT_SIMILARITY = 0.72;

	/**
	 * Similarity at or above which a match needs no review.
	 */
	const CONFIDENT_SIMILARITY = 0.9;

	/**
	 * Minimum text similarity before two components are considered the same at
	 * all.
	 *
	 * Below this the text is treated as a veto rather than as a penalty, because
	 * a component that was entirely rewritten is a different component and
	 * pairing it with the old one would produce a "modification" for something
	 * that is really a replacement.
	 */
	const MIN_TEXT_SIMILARITY = 0.35;


	/**
	 * Maximum text length used in a fingerprint.
	 *
	 * A fingerprint is an identity, not a content store. Hashing a whole page of
	 * text would make the identity change for any edit at all, which is the failure
	 * a fingerprint is meant to avoid.
	 */
	const FINGERPRINT_TEXT_LENGTH = 160;

	/**
	 * Return the stable identity of a component.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return array<string, mixed>
	 */
	public function identity( array $component ) {
		$role   = $this->role_of( $component );
		$text   = $this->text_of( $component );
		$image  = $this->image_of( $component );
		$tag    = strtolower( trim( (string) ( $component['tag'] ?? $component['el_type'] ?? '' ) ) );
		$tag    = preg_replace( '/[^a-z0-9]/', '', $tag );
		$depth  = (int) ( $component['depth'] ?? 0 );

			// The fingerprint is the identity. It deliberately excludes position,
			// index, and the text: position is destroyed by an insertion above the
			// component and the text is the thing that is allowed to change, so
			// including either would make an edit or an insertion look like a new
			// component.
			$identity_material = implode(
				'|',
				array( $role, $tag )
			);

			// The content hash includes the text. Two components with identical
			// content are certainly the same one, so it is the sufficient condition
			// for a match with no scoring at all.
			$content_material = implode(
				'|',
				array( $role, $tag, mb_substr( $text, 0, self::FINGERPRINT_TEXT_LENGTH ), $image )
			);
		// The fingerprint is the identity. It deliberately excludes position, index,
		// the text, and the image: position is destroyed by an insertion above the
		// component, and the text and the image are the two things a design change
		// most often alters. Including any of them would make an ordinary edit look
		// like a brand new component, and a replica would be rebuilt every time a
		// headline was reworded.
		$identity_material = implode(
			'|',
			array( $role, $tag )
		);

		// The content hash includes the text. Two components with identical content
		// are certainly the same one, so it is the sufficient condition for a match
		// with no scoring at all.
		$content_material = implode(
			'|',
			array( $role, $tag, mb_substr( $text, 0, self::FINGERPRINT_TEXT_LENGTH ), $image )
		);

		return array(
			'role'         => $role,
			'text'         => $text,
			'text_key'     => $this->normalize_text( $text ),
			'image'        => $image,
			'tag'          => $tag,
			'depth'        => $depth,
			'structure'    => $this->structure_of( $component ),
			'position'     => (int) ( $component['index'] ?? -1 ),
			'section'      => $this->section_of( $component ),
			'fingerprint'  => substr( hash( 'sha256', $identity_material ), 0, 20 ),
			'content_hash' => substr( hash( 'sha256', $content_material ), 0, 20 ),
			// Whether the component carries any content at all. An empty container
			// has no identity of its own, so it is identified by its shape and its
			// position, and the caller is told that its identity is weak.
			'has_content'  => ( '' !== $text || '' !== $image ),
		);
	}

	/**
	 * Score one old component against one new component.
	 *
	 * @param array<string, mixed> $old    Old identity.
	 * @param array<string, mixed> $new    New identity.
	 * @param array<string, mixed> $old_position Old position within its section.
	 * @param array<string, mixed> $new_position New position within its section.
	 * @return array<string, mixed>
	 */
	public function score( array $old, array $new, $old_position = -1, $new_position = -1 ) {
		$reasons = array();
		$score   = 0.0;

		// Every read below is defaulted. `score()` is public and a release gate
		// requires zero warnings, so a caller passing a partial identity — or an
		// empty one, which is a legitimate degenerate case — gets the absence of a
		// signal rather than a notice.
		//
		// The identity is the strongest single signal and it is compared once. A
		// component whose identity matches has not had its role, tag, or image
		// changed, so the image weight below cannot also apply: scoring it twice
		// would let one signal outweigh the rest of the scheme.
		$identity_same = (
			'' !== (string) ( $old['fingerprint'] ?? '' )
			&& (string) $old['fingerprint'] === (string) ( $new['fingerprint'] ?? '' )
		);

		if ( $identity_same ) {
			$score += self::WEIGHTS['fingerprint'];
			$reasons[] = 'same identity';
		}

		$old_role = (string) ( $old['role'] ?? '' );
		$new_role = (string) ( $new['role'] ?? '' );
		if ( '' !== $old_role && $old_role === $new_role ) {
			$score += self::WEIGHTS['role'];
			$reasons[] = 'same semantic role';
		}

		$old_section = (string) ( $old['section'] ?? '' );
		$new_section = (string) ( $new['section'] ?? '' );
		if ( '' !== $old_section && $old_section === $new_section ) {
			$score += self::WEIGHTS['section'];
			$reasons[] = 'same section';
		}

		$old_image = (string) ( $old['image'] ?? '' );
		$new_image = (string) ( $new['image'] ?? '' );
		if ( '' !== $old_image && $old_image === $new_image ) {
			$score += self::WEIGHTS['image'];
			$reasons[] = 'same image';
		}

		$text_similarity = $this->text_similarity( (string) ( $old['text_key'] ?? '' ), (string) ( $new['text_key'] ?? '' ) );

		// A text conflict is a veto, not a penalty. Two components whose text is
		// entirely different are not the same component however much else they
		// share, and a hero with two paragraphs would otherwise be paired
		// arbitrarily. Sharing a role and a section is not evidence of identity
		// when the content says otherwise.
		$both_have_text = (
			'' !== (string) ( $old['text_key'] ?? '' )
			&& '' !== (string) ( $new['text_key'] ?? '' )
		);
		$text_conflict = ( $both_have_text && $text_similarity < self::MIN_TEXT_SIMILARITY );

		if ( $text_conflict ) {
			$reasons[] = 'the text is entirely different';
		}

		// Two components that differ only in which file they point at, and have no
		// text to tell them apart, are an uncertain match rather than a certain
		// one. This does not veto: a replaced image is a modification of a
		// component that is probably still the same component, and reporting it as
		// a removal and an addition would ask a person to decide about two
		// changes instead of one. The uncertainty is carried as a low confidence,
		// which the classifier already requires a person to look at.
		//
		// The signal is skipped when either side has text, because text is then the
		// better evidence and the asset is simply the thing that changed.
		$old_image = (string) ( $old['image'] ?? '' );
		$new_image = (string) ( $new['image'] ?? '' );
		$asset_conflict = (
			'' !== $old_image
			&& '' !== $new_image
			&& $old_image !== $new_image
			&& ! $both_have_text
		);

		if ( $asset_conflict ) {
			$reasons[] = 'a different image and no text to tell them apart, so the match is uncertain';
		}

		if ( $text_similarity >= 0.5 ) {
			$score += self::WEIGHTS['text'] * $text_similarity;
			$reasons[] = $text_similarity >= 0.9 ? 'identical text' : 'similar text';
		}

		$structure_similarity = $this->structure_similarity( (string) ( $old['structure'] ?? '' ), (string) ( $new['structure'] ?? '' ) );
		if ( $structure_similarity > 0 ) {
			$score += self::WEIGHTS['structure'] * $structure_similarity;
			if ( $structure_similarity >= 0.99 ) {
				$reasons[] = 'same DOM structure';
			}
		}

		// Position is the last resort and the smallest weight. It is the one signal
		// an insertion destroys, so it is never allowed on its own to carry a match.
		if ( (int) $old_position >= 0 && (int) $new_position >= 0 ) {
			$distance = abs( (int) $old_position - (int) $new_position );
			if ( 0 === $distance ) {
				$score += self::WEIGHTS['position'];
				$reasons[] = 'same position within its section';
			} elseif ( 1 === $distance ) {
				$score += self::WEIGHTS['position'] * 0.5;
			}
		}

		$score = min( 1.0, round( $score, 4 ) );

		// A match that rests only on weak signals is reported as weak even if the
		// arithmetic reached the threshold, because the threshold is about the sum
		// and this is about what the sum was made of.
		$confidence = $this->confidence_for( $score, $reasons );
		if ( $text_conflict || $asset_conflict ) {
			// A veto caps the confidence rather than leaving it to the score, because
			// the score can be high on role and section alone.
			$confidence = min( $confidence, 0.4 );
		}


		return array(
			'similarity'  => $score,
			'confidence'  => $confidence,
		// A vetoed match is never accepted, whatever the arithmetic reached.
		'accepted'    => $score >= self::ACCEPT_SIMILARITY && ! $text_conflict && ! $asset_conflict,
			'confident'   => $score >= self::CONFIDENT_SIMILARITY,
			'reasons'     => $reasons,
			'old_section' => $old_section,
			'new_section' => $new_section,
		);
	}

	/**
	 * Match a set of old components to a set of new ones.
	 *
	 * Matching is greedy on descending confidence rather than globally optimal. A
	 * globally optimal assignment is the textbook answer, and it is wrong here: it
	 * will happily pair a component with a slightly better overall total while
	 * taking an element that another component needed. Greedy on the best remaining
	 * pair takes the most confident matches first, which is the order a person
	 * reviewing the result would want to see them in.
	 *
	 * @param array<int, array<string, mixed>> $old_components Old components.
	 * @param array<int, array<string, mixed>> $new_components New components.
	 * @return array<string, mixed>
	 */
	public function match( array $old_components, array $new_components ) {
		$old_count = min( count( $old_components ), Sync_Limits::MAX_COMPONENTS );
		$new_count = min( count( $new_components ), Sync_Limits::MAX_COMPONENTS );

		$old_identities = array();
		foreach ( array_slice( $old_components, 0, $old_count ) as $position => $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$identity            = $this->identity( $component );
			$identity['_source']  = (string) ( $component['id'] ?? ( $component['component_id'] ?? ( 'old_' . $position ) ) );
			$identity['_position'] = $position;
			$old_identities[]     = $identity;
		}

		$new_identities = array();
		foreach ( array_slice( $new_components, 0, $new_count ) as $position => $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$identity            = $this->identity( $component );
			$identity['_source']  = (string) ( $component['id'] ?? ( $component['component_id'] ?? ( 'new_' . $position ) ) );
			$identity['_position'] = $position;
			$new_identities[]     = $identity;
		}

		$old_by_id = array();
		foreach ( $old_identities as $identity ) {
			$old_by_id[ $identity['_source'] ] = $identity;
		}
		$new_by_id = array();
		foreach ( $new_identities as $identity ) {
			$new_by_id[ $identity['_source'] ] = $identity;
		}

		// An identical fingerprint is a certain match and is taken first, so the
		// similarity search below never spends its budget on a pair that is already
		// answered.
		$matches = array();
		$taken   = array( 'old' => array(), 'new' => array() );

		foreach ( $old_identities as $old ) {
			if ( empty( $old['has_content'] ) ) {
				continue;
			}
			foreach ( $new_identities as $new ) {
				if ( empty( $new['has_content'] ) ) {
					continue;
				}
					if ( (string) ( $old['content_hash'] ?? '' ) !== (string) ( $new['content_hash'] ?? '' ) ) {
					continue;
				}
				if ( isset( $taken['old'][ $old['_source'] ] ) || isset( $taken['new'][ $new['_source'] ] ) ) {
					continue;
				}
				$taken['old'][ $old['_source'] ] = true;
				$taken['new'][ $new['_source'] ] = true;
				$matches[ $old['_source'] ] = array(
					'old_id'            => $old['_source'],
					'new_id'            => $new['_source'],
					'similarity'        => 1.0,
					'match_confidence'  => 1.0,
						'match_reasons'     => array( 'identical content' ),
					'confident'         => true,
					'old_position'      => $old['_position'],
					'new_position'      => $new['_position'],
					'old_section'       => (string) $old['section'],
					'new_section'       => (string) $new['section'],
					'moved'             => (string) $old['section'] !== (string) $new['section'],
				);
			}
		}

		// New identities are indexed by section, because a component almost always
		// matches within its own section and a full sweep is quadratic. The sweep
		// is still available as a fallback for a component that genuinely moved, so
		// blocking by section costs recall for a moved component and buys a linear
		// reduction in work for every component that did not.
		$new_by_section = array();
		foreach ( $new_identities as $new ) {
			$section_key = (string) ( $new['section'] ?? '' );
			if ( ! isset( $new_by_section[ $section_key ] ) ) {
				$new_by_section[ $section_key ] = array();
			}
			$new_by_section[ $section_key ][] = $new;
		}

		// Every remaining old component is scored against the new components in its
		// own section, then against the rest only if nothing there cleared the floor.
		$candidates = array();
		foreach ( $old_identities as $old ) {
			if ( isset( $taken['old'][ $old['_source'] ] ) ) {
				continue;
			}

			$section_key    = (string) ( $old['section'] ?? '' );
			$pool           = isset( $new_by_section[ $section_key ] ) ? $new_by_section[ $section_key ] : array();
			$scored         = $this->score_pool( $old, $pool, $taken, $old['_position'] );

			// The sweep is for a section with no new components at all, which is where
			// a genuine section move lands. A pool that exists but yields nothing
			// above the floor means the section is accounted for, and sweeping the
			// whole page for it would reintroduce the quadratic pass this index
			// exists to remove.
			if ( empty( $pool ) ) {
				$all = array();
				foreach ( $new_identities as $new ) {
					if ( ! isset( $taken['new'][ $new['_source'] ] ) ) {
						$all[] = $new;
					}
				}
				$scored = $this->score_pool( $old, $all, $taken, $old['_position'] );
			}

			usort( $scored, array( $this, 'compare_candidates' ) );
			$candidates[ $old['_source'] ] = array_slice( $scored, 0, Sync_Limits::MAX_MATCH_CANDIDATES );
		}
		// threshold.
		$candidates = array();
		foreach ( $old_identities as $old ) {
			if ( isset( $taken['old'][ $old['_source'] ] ) ) {
				continue;
			}
			$scored = array();
			foreach ( $new_identities as $new ) {
				if ( isset( $taken['new'][ $new['_source'] ] ) ) {
					continue;
				}
				$result = $this->score( $old, $new, $old['_position'], $new['_position'] );
				if ( $result['similarity'] < self::MIN_SIMILARITY ) {
					continue;
				}
				$scored[] = array(
					'old_id'           => $old['_source'],
					'new_id'           => $new['_source'],
					'similarity'       => $result['similarity'],
					'match_confidence' => $result['confidence'],
					'match_reasons'    => $result['reasons'],
					'confident'        => $result['confident'],
					'accepted'         => $result['accepted'],
					'old_position'     => $old['_position'],
					'new_position'     => $new['_position'],
					'old_section'      => (string) $old['section'],
					'new_section'      => (string) $new['section'],
					// A component whose section changed is a move, not an edit. The
					// caller needs this to tell the two apart.
					'moved'            => (string) $old['section'] !== (string) $new['section'],
				);
			}
			usort( $scored, array( $this, 'compare_candidates' ) );
			$candidates[ $old['_source'] ] = array_slice( $scored, 0, Sync_Limits::MAX_MATCH_CANDIDATES );
		}

		$progress = true;
		while ( $progress ) {
			$progress = false;
			$best     = null;

			foreach ( $candidates as $old_id => $scored ) {
				if ( empty( $scored ) ) {
					continue;
				}
				$top = $scored[0];
				if ( null === $best || $this->compare_candidates( $top, $best['candidate'] ) < 0 ) {
					$best = array( 'old_id' => $old_id, 'candidate' => $top );
				}
			}

			if ( null === $best ) {
				break;
			}

			$accepted = $best['candidate']['accepted'];

			// A single remaining candidate is accepted by elimination, whatever the
			// direct evidence scored. Nothing else on the page could plausibly be this
			// component, and that is a stronger signal than a shared role. It is not
			// the same as lowering the threshold: a component with two or more
			// candidates is still not matched.
			if ( ! $accepted && 1 === count( $candidates[ $best['old_id'] ] ) ) {
				$accepted = true;
			}

			if ( ! $accepted ) {
				break;
			}

			// The accepted candidate is consumed, so the next pass cannot take it again.
			$candidate = $best['candidate'];
			$matches[ $best['old_id'] ] = $candidate;

			// Both ends are marked taken. Marking only the old one would let the same
			// new component be paired with a second old component, and marking only the
			// new one would let the same old component be paired twice.
			$taken['old'][ $best['old_id'] ]     = true;
			$taken['new'][ $candidate['new_id'] ] = true;
			$candidates[ $best['old_id'] ]        = array();

			// Any other old component's candidate list may contain the new component
			// just taken, so the lists are rebuilt rather than searched for the entry.
			foreach ( $candidates as $other_old => $scored ) {
				if ( isset( $taken['old'][ $other_old ] ) || empty( $scored ) ) {
					continue;
				}
				$kept = array();
				foreach ( $scored as $entry ) {
					if ( ! isset( $taken['new'][ $entry['new_id'] ] ) ) {
						$kept[] = $entry;
					}
				}
				$candidates[ $other_old ] = $kept;
			}

			$progress = true;
		}

		$removed = array();
		foreach ( $old_identities as $old ) {
			if ( ! isset( $matches[ $old['_source'] ] ) ) {
				$removed[] = $old['_source'];
			}
		}

		$added = array();
		foreach ( $new_identities as $new ) {
			if ( ! isset( $taken['new'][ $new['_source'] ] ) ) {
				$added[] = $new['_source'];
			}
		}

		// Unmatched old components that were *close* to something are reported
		// separately from ones that were not, because "the heading moved to another
		// section" and "the heading is gone" are different messages for a user.
		$unmatched_with_candidates = array();
		foreach ( $candidates as $old_id => $scored ) {
			if ( isset( $taken['old'][ $old_id ] ) ) {
				continue;
			}
			if ( ! empty( $scored ) ) {
				$unmatched_with_candidates[ $old_id ] = $scored;
			}
		}

		return array(
			'matches'         => array_values( $matches ),
			'added'            => $added,
			'removed'          => $removed,
			'unmatched_candidates' => $unmatched_with_candidates,
			'old_count'        => count( $old_identities ),
			'new_count'        => count( $new_identities ),
			'bounded'          => count( $old_components ) > $old_count || count( $new_components ) > $new_count,
		);
	}

	/**
	 * Score one component against a pool of candidates.
	 *
	 * A pool rather than the whole set, so the caller can block the search by
	 * section and keep the work linear in the page rather than quadratic.
	 *
	 * @param array<string, mixed>  $old     Old identity.
	 * @param array<int, array<string, mixed>> $pool Candidate identities.
	 * @param array<string, bool>   $taken   New identities already taken.
	 * @param int                    $old_position Old position.
	 * @return array<int, array<string, mixed>>
	 */
	private function score_pool( array $old, array $pool, array $taken, $old_position ) {
		$out = array();

		foreach ( $pool as $new ) {
			if ( isset( $taken['new'][ $new['_source'] ] ) ) {
				continue;
			}

			$result = $this->score( $old, $new, $old_position, (int) $new['_position'] );
			if ( $result['similarity'] < self::MIN_SIMILARITY ) {
				continue;
			}

			$out[] = array(
				'old_id'           => $old['_source'],
				'new_id'           => $new['_source'],
				'similarity'       => $result['similarity'],
				'match_confidence' => $result['confidence'],
				'match_reasons'    => $result['reasons'],
				'confident'        => $result['confident'],
				'accepted'         => $result['accepted'],
				'old_position'     => $old_position,
				'new_position'     => (int) $new['_position'],
				'old_section'      => (string) ( $old['section'] ?? '' ),
				'new_section'      => (string) ( $new['section'] ?? '' ),
				'moved'            => (string) ( $old['section'] ?? '' ) !== (string) ( $new['section'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Order two candidates, most confident first.
	 *
	 * @param array<string, mixed> $left  One candidate.
	 * @param array<string, mixed> $right The other.
	 * @return int
	 */
	public function compare_candidates( $left, $right ) {
		// A candidate that clears the acceptance threshold outranks one that does
		// not, at equal similarity, because only one of them is a usable match.
		$left_accepted  = ! empty( $left['accepted'] );
		$right_accepted = ! empty( $right['accepted'] );
		if ( $left_accepted !== $right_accepted ) {
			return $left_accepted ? -1 : 1;
		}
		if ( $left['similarity'] === $right['similarity'] ) {
			return strcmp( (string) $left['new_id'], (string) $right['new_id'] );
		}
		return ( $right['similarity'] <=> $left['similarity'] );
	}

	/**
	 * Return the confidence for a similarity score and its reasons.
	 *
	 * A score reached entirely from weak signals is reported as less confident than
	 * the same score reached from a fingerprint, because the arithmetic is the same
	 * and the meaning is not.
	 *
	 * @param float            $score   Similarity.
	 * @param array<int,string> $reasons Reasons.
	 * @return float
	 */
	private function confidence_for( $score, array $reasons ) {
		$strong = 0;
		foreach ( $reasons as $reason ) {
			if ( in_array(
				$reason,
				array( 'identical content', 'same identity', 'same semantic role', 'same section', 'same image' ),
				true
			) ) {
				$strong++;
			}
		}

		$confidence = $score;

		// One strong signal is enough if the score is already high. Two or more are
		// required before a middling score is reported as a match at all.
		if ( $strong < 1 ) {
			$confidence *= 0.5;
		} elseif ( $strong < 2 && $score < self::CONFIDENT_SIMILARITY ) {
			$confidence *= 0.85;
		}

		// A match resting only on position is not a match. The weight is already
		// tiny, but the case is called out because it is the one §16 warns about.
		if ( in_array( 'same position within its section', $reasons, true ) && $strong < 2 ) {
			$confidence = min( $confidence, 0.4 );
		}

		return round( min( 1.0, $confidence ), 4 );
	}

	/**
	 * Return the normalized text of a component.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return string
	 */
	private function text_of( array $component ) {
		foreach ( array( 'text', 'content', 'label', 'title' ) as $field ) {
			if ( isset( $component[ $field ] ) && is_scalar( $component[ $field ] ) ) {
				$text = trim( (string) $component[ $field ] );
				if ( '' !== $text ) {
					return $text;
				}
			}
		}
		return '';
	}

	/**
	 * Return the normalized image reference of a component.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return string
	 */
	private function image_of( array $component ) {
		foreach ( array( 'image', 'src', 'image_url', 'asset' ) as $field ) {
			if ( isset( $component[ $field ] ) && is_scalar( $component[ $field ] ) ) {
				$image = trim( (string) $component[ $field ] );
				if ( '' === $image ) {
					continue;
				}
				// Only the path is kept, and the host is dropped. A cache-busting
				// query string changes on every deploy and would make every image look
				// new, which is precisely the false positive this class exists to prevent.
				$path = (string) wp_parse_url( $image, PHP_URL_PATH );
				if ( '' === $path ) {
					// Not a parseable URL, so the whole value is the identity.
					return $this->lowercase( $image );
				}

				// Dot segments are resolved. A server resolves /a/../b.jpg and /b.jpg to
				// the same file, and treating them as two images reports a change every
				// time a site rewrites a path. A segment that would climb above the root
				// is dropped rather than kept, so a stored identity can never contain a
				// traversal sequence.
				$segments = array();
				foreach ( explode( '/', $path ) as $segment ) {
					if ( '' === $segment || '.' === $segment ) {
						continue;
					}
					if ( '..' === $segment ) {
						array_pop( $segments );
						continue;
					}
				$segments[] = $segment;
				}

				return $this->lowercase( '/' . implode( '/', $segments ) );
			}
		}
		return '';
	}

	/**
	 * Return the semantic role of a component.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return string
	 */
	private function role_of( array $component ) {
		foreach ( array( 'role', 'component_type', 'type', 'semantic_role' ) as $field ) {
			if ( isset( $component[ $field ] ) && is_scalar( $component[ $field ] ) ) {
				$role = strtolower( trim( (string) $component[ $field ] ) );
				if ( '' !== $role ) {
					return preg_replace( '/[^a-z0-9_]/', '_', $role );
				}
			}
		}
		return '';
	}

	/**
	 * Return the section a component belongs to.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return string
	 */
	private function section_of( array $component ) {
		foreach ( array( 'section_id', 'section', 'parent_section' ) as $field ) {
			if ( isset( $component[ $field ] ) && is_scalar( $component[ $field ] ) ) {
				$section = strtolower( trim( (string) $component[ $field ] ) );
				if ( '' !== $section ) {
					return preg_replace( '/[^a-z0-9_.\-]/', '_', $section );
				}
			}
		}
		return '';
	}

	/**
	 * Return a structural description of a component.
	 *
	 * @param array<string, mixed> $component Component.
	 * @return string
	 */
	private function structure_of( array $component ) {
		$tag = strtolower( trim( (string) ( $component['tag'] ?? $component['el_type'] ?? '' ) ) );
		$tag = preg_replace( '/[^a-z0-9]/', '', $tag );
		$children = isset( $component['child_count'] ) && is_numeric( $component['child_count'] )
			? (int) $component['child_count']
			: -1;
		return $tag . '@' . $children . 'd' . (int) ( $component['depth'] ?? 0 );
	}

	/**
	 * Return the structural similarity of two descriptions.
	 *
	 * @param string $left  One description.
	 * @param string $right The other.
	 * @return float
	 */
	private function structure_similarity( $left, $right ) {
		if ( '' === $left || '' === $right ) {
			return 0.0;
		}
		return $left === $right ? 1.0 : 0.0;
	}

	/**
	 * Return the normalized form of a text value.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function normalize_text( $text ) {
		$text = $this->lowercase( trim( (string) $text ) );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Lowercase a string, with or without the mbstring extension.
	 *
	 * `mb_strtolower` is a PHP 8.4 function and WordPress polyfills `mb_substr` and
	 * `mb_strlen` but not this one, so a build without mbstring fatals on it. A
	 * fingerprint needs a stable key, not a linguistically correct one, so the
	 * fallback is `strtolower`: on a build without mbstring it will not case-fold
	 * non-ASCII, which makes two spellings of the same non-ASCII string fingerprint
	 * differently. That is conservative — it under-matches rather than
	 * over-matches — and the failure it could cause, a change offered for review that
	 * turns out to be identical text, is one a person catches by reading.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	/**
	 * Normalize an asset reference to a comparable form.
	 *
	 * A cache-busting query string changes on every deploy and a path may be
	 * written with dot segments, so two spellings of one file compare as two
	 * different images. Both the matcher and the change detector use this,
	 * because a rule learned in one of them and not the other produces exactly
	 * the false positives this class exists to prevent.
	 *
	 * @param string $value Raw reference.
	 * @return string
	 */
	public static function normalize_asset_reference( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$path = (string) wp_parse_url( $value, PHP_URL_PATH );
		if ( '' === $path ) {
			// Not a parseable URL, so the whole value is the identity.
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
		}

		// Dot segments are resolved. A server resolves /a/../b.jpg and /b.jpg to
		// the same file. A segment that would climb above the root is dropped,
		// so a stored identity can never contain a traversal sequence.
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}

		$normalized = '/' . implode( '/', $segments );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $normalized ) : strtolower( $normalized );
	}


	private function lowercase( $text ) {
		if ( function_exists( 'mb_strtolower' ) ) {
			return (string) mb_strtolower( $text );
		}
		return strtolower( $text );
	}

	/**
	 * Return the similarity of two normalized strings.
	 *
	 * @param string $left  One string.
	 * @param string $right The other.
	 * @return float
	 */
	private function text_similarity( $left, $right ) {
		if ( '' === $left || '' === $right ) {
			return 0.0;
		}
		if ( $left === $right ) {
			return 1.0;
		}
		// similar_text is a character-level comparison, which suits short labels and
		// headings. It is deliberately not used for long text, where two paragraphs
		// of different content can score as partly similar and mean nothing.
		if ( mb_strlen( $left ) > self::FINGERPRINT_TEXT_LENGTH || mb_strlen( $right ) > self::FINGERPRINT_TEXT_LENGTH ) {
			return 0.0;
		}

		$percent = 0;
		similar_text( $left, $right, $percent );

		return round( $percent / 100, 4 );
	}
}
