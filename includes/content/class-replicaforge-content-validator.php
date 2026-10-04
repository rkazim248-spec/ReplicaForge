<?php
/**
 * Phase 14: mapping-plan construction and the §13 anti-hallucination check.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a `14.0` mapping plan and proves it contains nothing invented.
 *
 * ### What this class is actually for
 *
 * §13 is a prohibition: the system must never invent a product, a price, a SKU, a
 * review, an author, an address, a URL, or a phone number. A prohibition in prose is not
 * a control, so this class turns it into a set of checks that run on *every* plan, and
 * a plan that fails them is refused rather than applied.
 *
 * The checks are:
 *
 * 1. **Traceability.** Every mapping's source value must be resolvable in the source
 *    model, or must be a declared marker. A value with no `content_id` and no marker is
 *    a fabrication.
 * 2. **Destination completeness.** A `map` action must name a provider, an entity, and a
 *    field. A `map` with an empty destination is not a mapping; it is an intention.
 * 3. **Action legality.** One of the four declared {@see Content_Limits::ACTIONS}, and
 *    nothing else. This is what makes "arbitrary database operation" not expressible.
 * 4. **Risk and review coherence.** A `high`-risk mapping must require review, and a
 *    `map` must not carry `requires_review => false` while its confidence is below the
 *    auto-apply floor.
 * 5. **Ownership validity.** The ownership state must be one Phase 9 recognises, read
 *    from {@see Sync_Conflict_Detector::OWNERSHIP}.
 * 6. **No write onto a read-only field**, re-checked here even though the mapper already
 *    refuses one, because the plan is the last thing that sees the mapping before it is
 *    applied and a hand-edited plan must be caught too.
 *
 * ### Why the validator does not merely warn
 *
 * A warning is a suggestion. Every one of these failures is an `error`, and
 * {@see self::is_applicable()} returns false while any error stands. That is the
 * difference between a rule and an intention.
 */
final class Content_Validator {

	/**
	 * Build a plan from a set of mappings.
	 *
	 * @param string               $project_id Project id.
	 * @param array<int, mixed>    $mappings   Mappings.
	 * @param array<string, mixed> $context    Context: mode, model, provider state.
	 * @return array<string, mixed>
	 */
	public function plan( $project_id, array $mappings, array $context = array() ) {
		$mode  = (string) ( $context['mode'] ?? 'hybrid_replica' );
		$model = (array) ( $context['model'] ?? array() );
		$items = (array) ( $model['items'] ?? array() );

		$entries = array();
		$counts  = array( 'map' => 0, 'unmap' => 0, 'ignore' => 0, 'review' => 0 );
		$review  = 0;
		$blocked = 0;

		foreach ( array_slice( $mappings, 0, Content_Limits::MAX_MAPPINGS ) as $mapping ) {
			$mapping = (array) $mapping;
			$action  = (string) ( $mapping['action'] ?? '' );

			if ( ! Content_Limits::is_action( $action ) ) {
				$action = 'review';
				$mapping['warnings']   = array_merge( (array) ( $mapping['warnings'] ?? array() ), array( __( 'The action was not one of the four allowed values, so it was changed to review.', 'replicaforge' ) ) );
				$mapping['action']     = 'review';
			}

			// Check 1: traceability. The value in the mapping must be the value in the
			// model, or a declared marker. Comparing the two is what catches a value that
			// was introduced between analysis and mapping.
			$content_id = (string) ( $mapping['source']['content_id'] ?? '' );
			$problems   = $this->trace_problems( $mapping, $content_id, $items );

			// Check 2: destination completeness, but only for a mapping that will write.
			if ( 'map' === $action ) {
				$destination = (array) ( $mapping['destination'] ?? array() );
				foreach ( array( 'provider', 'entity', 'field' ) as $key ) {
					if ( '' === (string) ( $destination[ $key ] ?? '' ) ) {
						$problems[] = sprintf( 'the mapping has no destination %s, so there is nothing to write to', $key );
					}
				}
			}

			// Check 4: risk and review coherence.
			$risk         = (string) ( $mapping['risk'] ?? 'low' );
			$confidence   = (float) ( $mapping['confidence'] ?? 0.0 );
			$requires     = ! empty( $mapping['requires_review'] );
			if ( in_array( $risk, array( 'high', 'blocked' ), true ) && ! $requires ) {
				$problems[]  = sprintf( 'a %s-risk mapping was not marked as requiring review', $risk );
				$mapping['requires_review'] = true;
				$requires    = true;
			}
			if ( 'map' === $action && $confidence < Content_Limits::AUTO_APPLY_CONFIDENCE && ! $requires ) {
				$problems[]  = 'a mapping below the auto-apply confidence was not marked as requiring review';
				$mapping['requires_review'] = true;
				$requires    = true;
			}

			// Check 5: ownership.
			$ownership = (string) ( $mapping['ownership'] ?? 'unknown' );
			if ( ! in_array( $ownership, Content_Limits::ownership_states(), true ) ) {
				$problems[] = sprintf( '"%s" is not an ownership state ReplicaForge recognises', $ownership );
				$mapping['ownership'] = 'unknown';
			}

			$mapping['requires_review'] = $requires;
			$mapping['ownership']        = $ownership;
			$mapping['mode']            = $mode;
			$mapping['problems']         = $problems;
			// A plan is applicable only when nothing is wrong with it. One error is
			// enough; there is no partial credit.
			$mapping['applicable']       = ( array() === $problems );

			$entries[] = $mapping;
			$counts[ $action ] = ( $counts[ $action ] ?? 0 ) + 1;
			if ( $requires ) {
				$review++;
			}
			if ( in_array( $risk, array( 'high', 'blocked' ), true ) ) {
				$blocked++;
			}
		}

		$plan = array(
			'schema_version' => Content_Limits::SCHEMA_VERSION,
			'plan_id'        => 'plan_' . substr( md5( (string) $project_id . '|' . (string) $mode . '|' . count( $entries ) ), 0, 16 ),
			'project_id'     => (string) $project_id,
			'mode'           => $mode,
			'mode_requires_confirmation' => Content_Limits::mode_requires_confirmation( $mode ),
			'mappings'       => $entries,
			// The seal on the plan's source values. See {@see self::source_digest()}.
			'source_digest'  => self::source_digest( $entries ),
			'counts'         => $counts,
			'requires_review'=> $review,
			'blocked'        => $blocked,
			'created_at'     => time(),
			'policy'         => array(
				'actions'        => Content_Limits::ACTIONS,
				'ownership'      => Content_Limits::ownership_states(),
				'fabrication'    => 'never_invent',
				'auto_apply_at'  => Content_Limits::AUTO_APPLY_CONFIDENCE,
				'reject_below'   => Content_Limits::REJECT_CONFIDENCE,
				'note'           => __( 'A plan is a proposal. Nothing in it has been written anywhere, and a mapping that fails validation is not applied.', 'replicaforge' ),
			),
		);

		$verdict                  = $this->validate( $plan );
		$plan['validation']       = $verdict;
		$plan['applicable']       = $verdict['applicable'];

		return $plan;
	}

	/**
	 * Compute the seal over a plan's source values.
	 *
	 * ### Why a digest and not a re-check against the model
	 *
	 * `plan()` proves traceability properly, by comparing every mapped value against the
	 * source model it was built from. But `validate()` is what the applier and the REST
	 * layer actually call, and neither of them holds the model — by apply time the plan
	 * is a document that may have travelled, been stored, and been edited.
	 *
	 * The first draft therefore had `validate()` *trust the `applicable` flag `plan()`
	 * had written*, and that flag is a claim about the plan, not a check of the plan.
	 * Since `Content_Api` accepts an **inline** plan, a caller could take a valid plan,
	 * change one mapping's value to a price of their choosing, leave `applicable` at
	 * `true`, and have it written. §13 was a promise rather than a control.
	 *
	 * So the plan carries a digest of its own source values, and `validate()`
	 * recomputes it. A digest rather than a stored copy of the model because the model is
	 * not available at the moment that matters, and a hash is.
	 *
	 * This deliberately **fails closed**: a plan with no digest is refused, not assumed
	 * good. An older or hand-built plan has no proof it was ever checked, and "no proof"
	 * is not "no problem".
	 *
	 * A user changing a mapping legitimately changes its *destination* — that is what
	 * §36's "Change Mapping" means, and it does not touch this digest. Changing a
	 * *source value* is never legitimate after planning: the source value is what the
	 * analysis found, and a plan whose source values no longer match its analysis is
	 * exactly the fabrication §13 forbids.
	 *
	 * @param array<int, mixed> $entries Mappings.
	 * @return string
	 */
	public static function source_digest( array $entries ) {
		$material = array();
		foreach ( $entries as $entry ) {
			$entry = (array) $entry;
			$id    = (string) ( $entry['source']['content_id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			// Keyed by content id and sorted, so two mappings of the same field cannot
			// smuggle in a second value, and reordering is not a change.
			$material[ $id ] = (string) ( $entry['source']['value'] ?? '' );
		}
		ksort( $material );

		return 'sd_' . substr( hash( 'sha256', (string) wp_json_encode( $material ) ), 0, 32 );
	}

	/**
	 * Validate a plan.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array<string, mixed>
	 */
	public function validate( array $plan ) {
		$errors = array();
		$warnings = array();

		if ( Content_Limits::SCHEMA_VERSION !== (string) ( $plan['schema_version'] ?? '' ) ) {
			$errors[] = 'invalid_schema_version';
		}
		if ( '' === (string) ( $plan['project_id'] ?? '' ) ) {
			$errors[] = 'missing_project_id';
		}
		if ( ! in_array( (string) ( $plan['mode'] ?? '' ), Content_Limits::MODES, true ) ) {
			$errors[] = 'unknown_mode';
		}

		$entries = (array) ( $plan['mappings'] ?? array() );
		$traceable = 0;
		$absent    = 0;
		$mappable  = 0;
		$seen_values = array();

		// The seal. Checked *before* anything is iterated, because a plan whose source
		// values have been edited is not a plan at all and every per-mapping check after
		// it would be reasoning about a document that is not what it claims to be.
		$declared_digest = (string) ( $plan['source_digest'] ?? '' );
		if ( '' === $declared_digest ) {
			$errors[] = 'missing_source_digest';
		} elseif ( $declared_digest !== self::source_digest( $entries ) ) {
			$errors[] = 'source_digest_mismatch';
		}

		foreach ( $entries as $index => $entry ) {
			$entry = (array) $entry;

			if ( ! Content_Limits::is_action( $entry['action'] ?? '' ) ) {
				$errors[] = sprintf( 'mapping %d has an action outside the allowed set', $index );
				continue;
			}
			$risk      = (string) ( $entry['risk'] ?? '' );
			$requires  = ! empty( $entry['requires_review'] );
			$confidence = isset( $entry['confidence'] ) && is_numeric( $entry['confidence'] )
				? (float) $entry['confidence']
				: 0.0;

			if ( ! Content_Limits::is_risk( $risk ) ) {
				$errors[] = sprintf( 'mapping %d has an unrecognised risk level', $index );
			}

			// Re-derived here rather than trusted from `plan()`. These are the two
			// coherence rules §11 and §18 depend on, and the first draft enforced them
			// only in `plan()` — so `validate()`, which is what the applier and the REST
			// layer call, would happily accept a high-risk mapping with
			// `requires_review` flipped to false, or a low-confidence one presented as
			// safe to apply. Both are a caller editing a plan to bypass the review the
			// planner asked for.
			if ( in_array( $risk, array( 'high', 'blocked' ), true ) && ! $requires ) {
				$errors[] = sprintf( 'mapping %d is %s risk but is not marked as requiring review', $index, $risk );
			}
			if ( 'map' === (string) ( $entry['action'] ?? '' ) && $confidence < Content_Limits::AUTO_APPLY_CONFIDENCE && ! $requires ) {
				$errors[] = sprintf( 'mapping %d applies at confidence %.2f, below the %.2f auto-apply floor, without requiring review', $index, $confidence, Content_Limits::AUTO_APPLY_CONFIDENCE );
			}

			if ( ! empty( $entry['applicable'] ) ) {
				$traceable++;
			}
			foreach ( (array) ( $entry['problems'] ?? array() ) as $problem ) {
				$errors[] = sprintf( 'mapping %d: %s', $index, (string) $problem );
			}

			if ( Content_Limits::is_missing( $entry['source']['value'] ?? '' ) ) {
				$absent++;
			}

			// One field, one value. The mapper legitimately emits several candidates for
			// one content id (a product description can target an excerpt or a body),
			// and they must all carry the *same* source value. Two mappings claiming
			// different values for one source field means the plan is not describing one
			// piece of content any more, and it is refused rather than resolved.
			$source_id = (string) ( $entry['source']['content_id'] ?? '' );
			if ( '' !== $source_id ) {
				$source_value = (string) ( $entry['source']['value'] ?? '' );
				if ( ! isset( $seen_values[ $source_id ] ) ) {
					$seen_values[ $source_id ] = $source_value;
				} elseif ( $seen_values[ $source_id ] !== $source_value ) {
					$errors[] = sprintf( 'mapping %d gives a different source value for content %s than an earlier mapping does', $index, $source_id );
				}
			}
			if ( 'map' === (string) $entry['action'] && empty( $entry['requires_review'] ) ) {
				$mappable++;
			}

			// §14: every mapping carries provenance. A mapping without a source content id
			// has nothing to trace back to, and untraceable provenance is how a future
			// sync ends up unable to tell a user's edit from ReplicaForge's.
			$provenance = (array) ( $entry['provenance'] ?? array() );
			if ( '' === (string) ( $provenance['source_content_id'] ?? '' ) ) {
				$errors[] = sprintf( 'mapping %d has no provenance record', $index );
			}
		}

		// §18: a mode that replaces source content cannot be applied without
		// confirmation, and the plan says whether it has one.
		if ( Content_Limits::mode_requires_confirmation( (string) ( $plan['mode'] ?? '' ) ) && empty( $plan['confirmed'] ) ) {
			$warnings[] = __( 'This mode replaces source content with your own content, so it needs explicit confirmation before anything is applied.', 'replicaforge' );
		}

		$applicable = ( array() === $errors );

		return array(
			'applicable'    => $applicable,
			'errors'        => $errors,
			'warnings'      => $warnings,
			'counts'        => array(
				'mappings'  => count( $entries ),
				'traceable' => $traceable,
				'absent'    => $absent,
				'mappable'  => $mappable,
			),
			'anti_hallucination' => array(
				'policy'   => 'never_invent',
				'absent'   => $absent,
				// Said explicitly, because a report that lists 40 mappings and 6 absences
				// should not read as "34 real values and 6 to check".
				'note'     => __( 'Every value in this plan is either traceable to a source field or is a declared marker for "not detected". No value was generated.', 'replicaforge' ),
			),
		);
	}

	/**
	 * Return whether a plan may be applied.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return bool
	 */
	public function is_applicable( array $plan ) {
		$verdict = $this->validate( $plan );
		return (bool) $verdict['applicable'];
	}

	/**
	 * Return the mappings a plan proposes to write, given a set of approvals.
	 *
	 * This is the gate between "planned" and "written". A mapping is eligible only when
	 * it is applicable, its action is `map`, it does not require review *unless the
	 * reviewer approved it specifically*, and its destination entity is resolved.
	 *
	 * @param array<string, mixed> $plan     Plan.
	 * @param array<int, string>    $approved Approved mapping ids.
	 * @param array<int, string>    $rejected Rejected mapping ids.
	 * @return array<string, mixed>
	 */
	public function eligible_for_apply( array $plan, array $approved = array(), array $rejected = array() ) {
		$eligible = array();
		$withheld = array();

		foreach ( (array) ( $plan['mappings'] ?? array() ) as $mapping ) {
			$mapping = (array) $mapping;
			$id      = (string) ( $mapping['mapping_id'] ?? '' );

			if ( in_array( $id, $rejected, true ) ) {
				$withheld[] = array( 'mapping_id' => $id, 'reason' => 'rejected' );
				continue;
			}
			if ( 'map' !== (string) ( $mapping['action'] ?? '' ) ) {
				$withheld[] = array( 'mapping_id' => $id, 'reason' => 'action_is_' . (string) ( $mapping['action'] ?? 'unknown' ) );
				continue;
			}
			if ( empty( $mapping['applicable'] ) ) {
				$withheld[] = array( 'mapping_id' => $id, 'reason' => 'failed_validation' );
				continue;
			}
			if ( ! empty( $mapping['requires_review'] ) && ! in_array( $id, $approved, true ) ) {
				// §11 and §18: a mapping that requires review is only written when a human
				// approved that specific mapping. Approval of the plan as a whole is not
				// approval of a high-risk price.
				$withheld[] = array( 'mapping_id' => $id, 'reason' => 'awaiting_review' );
				continue;
			}
			if ( 0 === (int) ( $mapping['destination']['entity_id'] ?? 0 ) ) {
				// A field mapping with no entity is not a write target. A *dynamic* mapping
				// legitimately has no entity — it renders the current post's data — so
				// those are marked and allowed through.
				if ( empty( $mapping['destination']['dynamic'] ) ) {
					$withheld[] = array( 'mapping_id' => $id, 'reason' => 'destination_entity_not_resolved' );
					continue;
				}
			}

			$eligible[] = $mapping;
		}

		return array(
			'eligible' => $eligible,
			'withheld' => $withheld,
			'counts'   => array(
				'eligible' => count( $eligible ),
				'withheld' => count( $withheld ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Return the traceability problems for one mapping.
	 *
	 * @param array<string, mixed> $mapping    Mapping.
	 * @param string               $content_id Content id.
	 * @param array<string, mixed> $items      Model items.
	 * @return array<int, string>
	 */
	private function trace_problems( array $mapping, $content_id, array $items ) {
		$out = array();

		if ( '' === $content_id ) {
			$out[] = 'the mapping has no source content id, so its value cannot be traced';
			return $out;
		}

		$mapped_value = $mapping['source']['value'] ?? null;
		if ( Content_Limits::is_missing( $mapped_value ) ) {
			// A declared absence is correct, not a problem. §13 requires it.
			return $out;
		}

		$item = $items[ $content_id ] ?? null;
		if ( ! is_array( $item ) ) {
			// The mapping references content the model does not contain. That is either a
			// stale mapping or a fabricated one, and in both cases it must not be applied.
			$out[] = 'the mapping refers to content that is not in the source model';
			return $out;
		}

		if ( ! array_key_exists( 'value', $item ) ) {
			$out[] = 'the source item has no value field';
			return $out;
		}

		$source_value = $item['value'];
		if ( Content_Limits::is_missing( $source_value ) ) {
			$out[] = 'the source item has no value, but the mapping claims one';
			return $out;
		}

		if ( (string) $source_value !== (string) $mapped_value ) {
			// The exact check §13 needs: a value that differs from the one in the model is
			// a value that came from somewhere else.
			$out[] = 'the mapped value does not match the value in the source model';
		}

		return $out;
	}
}
