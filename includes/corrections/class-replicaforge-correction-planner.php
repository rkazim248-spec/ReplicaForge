<?php
/**
 * Correction planner for ReplicaForge Phase 6.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a Phase 5 validation result into an ordered, reviewed correction plan.
 *
 * The planner never writes anything. It reads the differences Phase 5 measured,
 * resolves each one to an Elementor element through the Phase 4 identity map,
 * checks eligibility, resolves the manual-edit conflict state, groups the result
 * into dependency-ordered batches, and records what a correction would fix so the
 * optimization pass can prefer a single container change over five child changes.
 */
final class Correction_Planner {

	/**
	 * Property whitelist.
	 *
	 * @var Correction_Property_Map
	 */
	private $properties;

	/**
	 * Eligibility classifier.
	 *
	 * @var Correction_Eligibility
	 */
	private $eligibility;

	/**
	 * Snapshot service, used for manual-change detection.
	 *
	 * @var Correction_Snapshot
	 */
	private $snapshots;

	/**
	 * Constructor.
	 *
	 * @param Correction_Property_Map|null $properties  Optional property whitelist.
	 * @param Correction_Eligibility|null $eligibility Optional classifier.
	 * @param Correction_Snapshot|null     $snapshots   Optional snapshot service.
	 */
	public function __construct( $properties = null, $eligibility = null, $snapshots = null ) {
		$this->properties  = $properties instanceof Correction_Property_Map ? $properties : new Correction_Property_Map();
		$this->eligibility = $eligibility instanceof Correction_Eligibility ? $eligibility : new Correction_Eligibility( $this->properties );
		$this->snapshots   = $snapshots instanceof Correction_Snapshot ? $snapshots : new Correction_Snapshot( $this->properties );
	}

	/**
	 * Build a correction plan from a Phase 5 validation result.
	 *
	 * @param array<string, mixed>      $validation Phase 5 validation result.
	 * @param Elementor_Document_Reader $reader     Loaded reader.
	 * @param int                       $post_id    Draft post identifier.
	 * @return array<string, mixed>
	 */
	public function plan( array $validation, Elementor_Document_Reader $reader, $post_id ) {
		$validation_id = isset( $validation['validation_id'] ) ? (string) $validation['validation_id'] : '';
		$differences   = isset( $validation['differences'] ) && is_array( $validation['differences'] ) ? $validation['differences'] : array();

		// The baseline is established once, before anything is planned, so every
		// later plan compares against the state that existed at the start.
		$baseline_size = $this->snapshots->establish_baseline( $post_id, $reader );

		$candidates = array();
		$blocked    = array();
		$seen       = array();

		foreach ( $differences as $difference ) {
			if ( ! is_array( $difference ) ) {
				continue;
			}
			$candidate = $this->candidate( $difference, $reader, $post_id );
			if ( null === $candidate ) {
				continue;
			}
			if ( 'blocked' === $candidate['eligibility'] ) {
				$blocked[] = $candidate;
				continue;
			}
			$key = $candidate['dedupe_key'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			// The key is kept on the candidate because `assign_ids()` derives the
			// correction identifier from it, and the identifier is what ties a plan
			// entry back to a difference. It is stripped there, once it has been
			// used, so the stored plan carries no internal field.
			$candidates[] = $candidate;
		}

		$candidates = $this->optimize( $candidates );
		$candidates = $this->cap( $candidates, $blocked );
		$candidates = $this->assign_ids( $candidates );
		$candidates = $this->order( $candidates );

		$counts = array(
			'safe'            => 0,
			'requires_review' => 0,
			'blocked'         => count( $blocked ),
		);
		foreach ( $candidates as $candidate ) {
			$counts[ $candidate['eligibility'] ]++;
		}
		$counts['blocked'] = count( $blocked );

		$batches = $this->batches( $candidates );

		$plan = array(
			'schema_version'   => Correction_Limits::SCHEMA_VERSION,
			'phase'            => Correction_Limits::PHASE,
			'plan_id'          => 'plan_' . substr( hash( 'sha256', $validation_id . '|' . $reader->document_hash() . '|' . wp_json_encode( array_map( array( $this, 'fingerprint' ), $candidates ) ) ), 0, 20 ),
			'created_at'       => gmdate( 'c' ),
			'created_by'       => get_current_user_id(),
			'post_id'          => $reader->post_id(),
			'validation_id'    => $validation_id,
			'validation_score' => isset( $validation['metrics']['overall']['value'] ) ? $validation['metrics']['overall']['value'] : null,
			'document_hash'    => $reader->document_hash(),
			'baseline_size'    => (int) $baseline_size,
			'counts'           => $counts,
			'levels_used'      => $this->eligibility->highest_level( $candidates ),
			'corrections'      => $candidates,
			'blocked'          => $blocked,
			'batches'          => $batches,
			'auto_available'   => $counts['safe'],
			'applied'          => false,
			'human_review_required' => true,
			'read_only'        => true,
		);

		$this->store( $plan );

		return $plan;
	}

	/**
	 * Turn one difference into a candidate correction.
	 *
	 * @param array<string, mixed>      $difference Phase 5 difference.
	 * @param Elementor_Document_Reader $reader     Loaded reader.
	 * @param int                       $post_id    Draft post identifier.
	 * @return array<string, mixed>|null
	 */
	private function candidate( array $difference, Elementor_Document_Reader $reader, $post_id ) {
		$action = $this->action( $difference );
		if ( '' === $action ) {
			return null;
		}

		$property  = isset( $difference['property'] ) ? (string) $difference['property'] : '';
		$device    = isset( $difference['viewport'] ) ? (string) $difference['viewport'] : 'desktop';
		$element_id = $this->element_id( $difference, $reader );

		$candidate = array(
			'difference_id'    => isset( $difference['id'] ) ? (string) $difference['id'] : '',
			'category'         => isset( $difference['category'] ) ? (string) $difference['category'] : '',
			'property'         => $property,
			'property_label'   => $this->properties->label( $property ),
			'action'           => $action,
			'viewport'         => $this->properties->device( $device ),
			'severity'         => isset( $difference['severity'] ) ? (string) $difference['severity'] : 'moderate',
			'confidence'       => isset( $difference['confidence'] ) ? round( (float) $difference['confidence'], 4 ) : 0.0,
			'current'          => isset( $difference['actual'] ) ? $difference['actual'] : null,
			'expected'         => isset( $difference['expected'] ) ? $difference['expected'] : null,
			'difference'       => isset( $difference['difference'] ) ? $difference['difference'] : null,
			'measured_message' => isset( $difference['message'] ) ? (string) $difference['message'] : '',
			'target'           => array(
				'source_component_id'    => $this->reference( $difference, 'source_component_id' ),
				'source_section_id'      => $this->reference( $difference, 'source_section_id' ),
				'elementor_element_id'   => $element_id,
			),
			'manual_change'    => array( 'modified' => false ),
		);

		$candidate['value'] = $this->value( $difference, $action, $candidate );
		$candidate['value_label'] = $this->value_label( $candidate['value'] );

		$el_type = '';
		if ( '' !== $element_id ) {
			$element = $reader->element( $element_id );
			if ( null === $element ) {
				// The recorded element is gone, which usually means the user deleted
				// it. Nothing is guessed.
				$candidate['eligibility'] = 'requires_review';
				$candidate['reason']       = __( 'The Elementor element this difference referred to no longer exists in the draft.', 'replicaforge' );
				$candidate['auto']         = false;
				$candidate['dedupe_key']   = $this->dedupe_key( $candidate );
				return $candidate;
			}
			// The reader returns an index record, which names the element type
			// `el_type`. Reading `elType` here would silently yield null, no control
			// would ever resolve, and every correction would fall through to manual
			// review.
			$el_type = isset( $element['el_type'] ) ? (string) $element['el_type'] : '';
			if ( '' !== $el_type ) {
				$el_type = strtolower( $el_type );
			}
		}

		$control = $this->properties->control( $property, $el_type, $candidate['viewport'] );
		$candidate['control'] = $control;

		// A property with no control on this element type can never be written here,
		// so it is blocked rather than offered for review. Offering it would promise
		// the user a change the apply step could only refuse, which is the same as
		// promising something and then not doing it.
		if ( '' === $control ) {
			$candidate['eligibility'] = 'blocked';
			$candidate['auto']         = false;
			$candidate['level']        = 0;
			$candidate['batch']        = '';
			$candidate['reason']       = '' === $el_type
				? __( 'No Elementor element is mapped to this difference, so there is no control to write.', 'replicaforge' )
				: sprintf(
					/* translators: 1: Property label, 2: Element type. */
					__( '%1$s has no Elementor control on a %2$s, so this difference cannot be corrected on this element.', 'replicaforge' ),
					$this->properties->label( $property ),
					$el_type
				);
			$candidate['dedupe_key'] = $this->dedupe_key( $candidate );
			return $candidate;
		}

		$candidate['target_property_present'] = '' !== $element_id
			? null !== $reader->control_value( $element_id, $control )
			: null;
		$candidate['current_document_value'] = '' !== $element_id
			? $reader->control_value( $element_id, $control )
			: null;
		$candidate['manual_change'] = '' !== $element_id
			? $this->snapshots->manual_change(
				$reader,
				$post_id,
				$element_id,
				$property,
				$candidate['viewport']
			)
			: array( 'modified' => false );

		// A document value that already equals the source value needs no write, even
		// when Phase 5 compared the source against a different reading.
		if ( $this->already_matching( $candidate ) ) {
			$candidate['eligibility'] = 'blocked';
			$candidate['reason']       = __( 'The document already holds the source value for this property, so nothing needs to change.', 'replicaforge' );
			$candidate['auto']         = false;
			$candidate['dedupe_key']   = $this->dedupe_key( $candidate );
			return $candidate;
		}

		$classified                    = $this->eligibility->classify( $candidate );
		$classified['dedupe_key']      = $this->dedupe_key( $classified );
		$classified['fixes']           = array( (string) $candidate['difference_id'] );

		return $classified;
	}

	/**
	 * Return the action a difference maps to.
	 *
	 * @param array<string, mixed> $difference Phase 5 difference.
	 * @return string
	 */
	private function action( array $difference ) {
		$property = isset( $difference['property'] ) ? (string) $difference['property'] : '';
		$device   = isset( $difference['viewport'] ) ? (string) $difference['viewport'] : 'desktop';
		$state    = isset( $difference['state'] ) ? (string) $difference['state'] : '';

		if ( 'section_present' === $property ) {
			return in_array( $state, array( 'extra', 'fail' ), true ) ? 'remove' : 'insert';
		}
		if ( 'section_order' === $property && 'fail' === $state ) {
			return 'reorder';
		}
		if ( 'text' === $property ) {
			return 'content_update';
		}
		if ( in_array( $property, array( 'image_present', 'aspect_ratio' ), true ) ) {
			return 'asset_update';
		}
		if ( ! $this->properties->is_writable( $property ) ) {
			return '';
		}
		if ( 'desktop' !== $this->properties->device( $device ) ) {
			return 'responsive_update';
		}
		if ( in_array( $property, array( 'width', 'min_height', 'padding_top', 'padding_bottom', 'margin_top', 'margin_bottom' ), true ) ) {
			return 'resize';
		}
		if ( in_array( $property, array( 'flex_direction', 'align_items', 'justify_content', 'flex_wrap' ), true ) ) {
			return 'reposition';
		}
		return 'update';
	}

	/**
	 * Return the Elementor element identifier for a difference.
	 *
	 * @param array<string, mixed>      $difference Phase 5 difference.
	 * @param Elementor_Document_Reader $reader     Loaded reader.
	 * @return string
	 */
	private function element_id( array $difference, Elementor_Document_Reader $reader ) {
		$recorded = $this->element_id_shape( $this->reference( $difference, 'elementor_element_id' ) );
		if ( '' !== $recorded && null !== $reader->element( $recorded ) ) {
			return $recorded;
		}

		// Fall back to the identity map for the source component, which is the
		// authoritative Phase 4 link. Nothing is inferred from position or type.
		$component = $this->reference( $difference, 'source_component_id' );
		if ( '' !== $component ) {
			$mapped = $this->element_id_shape( $reader->element_for_component( $component ) );
			if ( '' !== $mapped ) {
				return $mapped;
			}
		}

		return $recorded;
	}

	/**
	 * Return an element identifier only when it has the Elementor element shape.
	 *
	 * A malformed identifier is dropped here so it can never reach the writer.
	 *
	 * @param string $element_id Raw identifier.
	 * @return string
	 */
	private function element_id_shape( $element_id ) {
		if ( ! is_string( $element_id ) ) {
			return '';
		}
		$element_id = strtolower( trim( $element_id ) );
		return preg_match( '/^[a-z0-9]{5,10}$/', $element_id ) ? $element_id : '';
	}

	/**
	 * Return the value a correction would write.
	 *
	 * @param array<string, mixed> $difference Phase 5 difference.
	 * @param string               $action     Correction action.
	 * @param array<string, mixed> $candidate  Candidate so far.
	 * @return mixed
	 */
	private function value( array $difference, $action, array $candidate ) {
		if ( 'reorder' === $action ) {
			return array( 'order' => $difference['actual'] ?? null );
		}
		if ( 'remove' === $action ) {
			return 'absent';
		}
		if ( 'insert' === $action ) {
			return array(
				'position'   => isset( $difference['insert_position'] ) ? (int) $difference['insert_position'] : 0,
				'section_id' => $candidate['target']['source_section_id'],
			);
		}
		if ( 'content_update' === $action || 'asset_update' === $action ) {
			return $difference['expected'] ?? null;
		}
		return $difference['expected'] ?? null;
	}

	/**
	 * Return whether the document already holds the source value.
	 *
	 * A font family is compared without regard to case, because CSS matches family
	 * names case-insensitively. The source side stores a comparable, lowercased
	 * family, so an exact comparison would see `Inter` and `inter` as different and
	 * propose a correction that changes the document without changing how it renders.
	 *
	 * @param array<string, mixed> $candidate Candidate correction.
	 * @return bool
	 */
	private function already_matching( array $candidate ) {
		$document_value = isset( $candidate['current_document_value'] ) ? $candidate['current_document_value'] : null;
		$target         = isset( $candidate['value'] ) ? $candidate['value'] : null;
		if ( null === $document_value || null === $target ) {
			return false;
		}
		$coerced = $this->properties->coerce( (string) $candidate['property'], $target );
		if ( null === $coerced ) {
			return false;
		}
		if ( is_string( $document_value ) && is_string( $coerced ) && 'font_family' === (string) $candidate['property'] ) {
			return strtolower( trim( $document_value ) ) === strtolower( trim( $coerced ) );
		}

		return wp_json_encode( $document_value ) === wp_json_encode( $coerced );
	}

	/**
	 * Prefer a single parent change over several child changes.
	 *
	 * A container dimension change is what actually fixes the positions of every
	 * child inside it, so when the same source component already has a dimension
	 * correction, the individual child width corrections for that component are
	 * dropped and recorded as covered by the parent.
	 *
	 * @param array<int, array<string, mixed>> $corrections Corrections.
	 * @return array<int, array<string, mixed>>
	 */
	private function optimize( array $corrections ) {
		$parent_keys = array();
		foreach ( $corrections as $correction ) {
			$component = isset( $correction['target']['source_component_id'] ) ? (string) $correction['target']['source_component_id'] : '';
			if ( '' === $component ) {
				continue;
			}
			if ( in_array( (string) $correction['property'], array( 'max_width', 'width', 'section_gap' ), true ) && 'desktop' === (string) $correction['viewport'] ) {
				$parent_keys[ $component ] = true;
			}
		}

		if ( empty( $parent_keys ) ) {
			return $corrections;
		}

		$result = array();
		foreach ( $corrections as $correction ) {
			$component = isset( $correction['target']['source_component_id'] ) ? (string) $correction['target']['source_component_id'] : '';
			$property  = (string) $correction['property'];
			if ( isset( $parent_keys[ $component ] )
				&& in_array( $property, array( 'padding_top', 'padding_bottom', 'margin_top', 'margin_bottom', 'min_height' ), true )
				&& 'desktop' === (string) $correction['viewport'] ) {
				continue;
			}
			$result[] = $correction;
		}
		return $result;
	}

	/**
	 * Bound the number of corrections and the number of elements a plan may touch.
	 *
	 * The dropped corrections are reported as blocked so the user still sees
	 * everything that was found, rather than a plan that silently looks complete.
	 *
	 * @param array<int, array<string, mixed>> $corrections Corrections.
	 * @param array<int, array<string, mixed>> $blocked     Blocked corrections, by reference.
	 * @return array<int, array<string, mixed>>
	 */
	private function cap( array $corrections, array &$blocked ) {
		$elements = array();
		$capped   = array();
		$dropped  = 0;

		foreach ( $corrections as $correction ) {
			if ( count( $capped ) >= Correction_Limits::MAX_CORRECTIONS ) {
				$dropped++;
				continue;
			}
			$element_id = isset( $correction['target']['elementor_element_id'] ) ? (string) $correction['target']['elementor_element_id'] : '';
			if ( '' !== $element_id && ! isset( $elements[ $element_id ] ) && count( $elements ) >= Correction_Limits::MAX_TARGETS ) {
				$dropped++;
				continue;
			}
			if ( '' !== $element_id ) {
				$elements[ $element_id ] = true;
			}
			$capped[] = $correction;
		}

		if ( $dropped > 0 ) {
			$blocked[] = array(
				'difference_id'  => '',
				'category'       => 'structure',
				'property'       => 'correction_limit',
				'property_label' => __( 'Plan limit', 'replicaforge' ),
				'action'         => 'update',
				'viewport'       => 'desktop',
				'severity'       => 'informational',
				'confidence'     => 0.0,
				'eligibility'    => 'blocked',
				'auto'           => false,
				'target'         => array( 'elementor_element_id' => '' ),
				'reason'         => sprintf(
					/* translators: %d: Number of dropped corrections. */
					__( '%d difference(s) were left out of this plan because the correction or element limit was reached. Plan again after applying the current plan to see the rest.', 'replicaforge' ),
					(int) $dropped
				),
			);
		}

		return $capped;
	}

	/**
	 * Assign stable identifiers to the corrections in a plan.
	 *
	 * The identifier is the position in the ordered plan followed by a short hash of
	 * the correction's own identity, so the same measured difference keeps the same
	 * identifier across runs and two different corrections never collide. The
	 * internal identity key is removed here, because it has served its purpose and
	 * does not belong in a stored plan.
	 *
	 * @param array<int, array<string, mixed>> $corrections Corrections.
	 * @return array<int, array<string, mixed>>
	 */
	private function assign_ids( array $corrections ) {
		$index = 0;
		foreach ( $corrections as $position => $correction ) {
			$index++;
			$corrections[ $position ]['correction_id'] = sprintf(
				'correction_%03d_%s',
				$index,
				substr( hash( 'sha256', $this->identity( $correction ) ), 0, 8 )
			);
			unset( $corrections[ $position ]['dedupe_key'] );
		}
		return $corrections;
	}

	/**
	 * Return the identity a correction identifier is derived from.
	 *
	 * The dedupe key is used when it is present. When it is not, the identity is
	 * rebuilt from the correction's own fields, so an identifier is never derived
	 * from an empty value and never collapses two corrections onto one hash.
	 *
	 * @param array<string, mixed> $correction Correction.
	 * @return string
	 */
	private function identity( array $correction ) {
		if ( isset( $correction['dedupe_key'] ) && is_string( $correction['dedupe_key'] ) && '' !== $correction['dedupe_key'] ) {
			return $correction['dedupe_key'];
		}

		return implode(
			'|',
			array(
				isset( $correction['action'] ) ? (string) $correction['action'] : '',
				isset( $correction['property'] ) ? (string) $correction['property'] : '',
				isset( $correction['viewport'] ) ? (string) $correction['viewport'] : '',
				isset( $correction['target']['elementor_element_id'] ) ? (string) $correction['target']['elementor_element_id'] : '',
				isset( $correction['control'] ) ? (string) $correction['control'] : '',
			)
		);
	}

	/**
	 * Order corrections by batch so dependencies resolve.
	 *
	 * @param array<int, array<string, mixed>> $corrections Corrections.
	 * @return array<int, array<string, mixed>>
	 */
	private function order( array $corrections ) {
		usort(
			$corrections,
			function ( $left, $right ) {
				$order = array_flip( Correction_Limits::BATCHES );
				// A correction that never reached the eligibility rules has no batch of
				// its own, so it sorts last rather than raising a notice.
				$left_batch  = isset( $left['batch'] ) ? (string) $left['batch'] : '';
				$right_batch = isset( $right['batch'] ) ? (string) $right['batch'] : '';
				$left_b      = '' !== $left_batch && isset( $order[ $left_batch ] ) ? $order[ $left_batch ] : 99;
				$right_b     = '' !== $right_batch && isset( $order[ $right_batch ] ) ? $order[ $right_batch ] : 99;
				if ( $left_b !== $right_b ) {
					return $left_b < $right_b ? -1 : 1;
				}
				// Within a batch, the safest corrections run first.
				if ( $left['auto'] !== $right['auto'] ) {
					return $left['auto'] ? -1 : 1;
				}
				return strcmp( (string) $left['correction_id'], (string) $right['correction_id'] );
			}
		);
		return $corrections;
	}

	/**
	 * Group corrections into the declared batches.
	 *
	 * @param array<int, array<string, mixed>> $corrections Corrections.
	 * @return array<int, array<string, mixed>>
	 */
	private function batches( array $corrections ) {
		$batches = array();
		foreach ( Correction_Limits::BATCHES as $batch ) {
			$batches[ $batch ] = array(
				'batch'    => $batch,
				'ids'      => array(),
				'safe'     => 0,
				'review'   => 0,
				'total'    => 0,
			);
		}
		foreach ( $corrections as $correction ) {
			$batch = isset( $correction['batch'] ) ? (string) $correction['batch'] : 'finetune';
			if ( ! isset( $batches[ $batch ] ) ) {
				$batches[ $batch ] = array(
					'batch'  => $batch,
					'ids'    => array(),
					'safe'   => 0,
					'review' => 0,
					'total'  => 0,
				);
			}
			$batches[ $batch ]['ids'][]   = (string) $correction['correction_id'];
			$batches[ $batch ]['total']++;
			if ( ! empty( $correction['auto'] ) ) {
				$batches[ $batch ]['safe']++;
			} else {
				$batches[ $batch ]['review']++;
			}
		}
		return array_values( array_filter( $batches, static function ( $batch ) {
			return $batch['total'] > 0;
		} ) );
	}

	/**
	 * Return a stable fingerprint of a correction.
	 *
	 * @param array<string, mixed> $correction Correction.
	 * @return string
	 */
	public function fingerprint( array $correction ) {
		return implode(
			'|',
			array(
				(string) $correction['property'],
				(string) $correction['viewport'],
				(string) $correction['action'],
				(string) ( $correction['target']['elementor_element_id'] ?? '' ),
				(string) ( is_scalar( $correction['value'] ?? null ) ? $correction['value'] : wp_json_encode( $correction['value'] ?? null ) ),
			)
		);
	}

	/**
	 * Return the dedupe key of a correction.
	 *
	 * @param array<string, mixed> $correction Correction.
	 * @return string
	 */
	private function dedupe_key( array $correction ) {
		return $this->fingerprint( $correction );
	}

	/**
	 * Return a display label for a value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function value_label( $value ) {
		if ( null === $value ) {
			return '—';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return substr( (string) wp_json_encode( $value ), 0, 120 );
		}
		return substr( (string) $value, 0, 120 );
	}

	/**
	 * Read a reference field from a difference.
	 *
	 * @param array<string, mixed> $difference Difference.
	 * @param string               $key        Reference key.
	 * @return string
	 */
	private function reference( array $difference, $key ) {
		foreach ( array( 'generated_reference', 'source_reference' ) as $block ) {
			if ( isset( $difference[ $block ][ $key ] ) && is_string( $difference[ $block ][ $key ] ) ) {
				$value = strtolower( trim( $difference[ $block ][ $key ] ) );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * Store a plan so a later apply request can load exactly this plan.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return string
	 */
	private function store( array $plan ) {
		set_transient(
			Correction_Limits::PLAN_PREFIX . $plan['plan_id'],
			$plan,
			Correction_Limits::PLAN_TTL
		);
		return (string) $plan['plan_id'];
	}

	/**
	 * Return a stored plan.
	 *
	 * @param string $plan_id Plan identifier.
	 * @return array<string, mixed>|null
	 */
	public function load( $plan_id ) {
		if ( ! is_string( $plan_id ) || ! preg_match( '/^plan_[a-f0-9]{20}$/', $plan_id ) ) {
			return null;
		}
		$stored = get_transient( Correction_Limits::PLAN_PREFIX . $plan_id );
		return is_array( $stored ) && isset( $stored['corrections'] ) ? $stored : null;
	}
}
