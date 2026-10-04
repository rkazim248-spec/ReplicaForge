<?php
/**
 * Phase 16: the interaction model.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The state-machine model, and the aggregate a page's interactions are collected into.
 *
 * ### Why a state machine and not a trigger/action pair
 *
 * The obvious representation is `{trigger: click, action: open}`. It is wrong for almost
 * every interesting component, in three ways that show up immediately on a real site:
 *
 * 1. **It cannot represent a cycle.** A modal is not "click → open"; it is
 *    closed → opening → open → closing → closed, and the opening/closing states are the
 *    ones that decide whether a second click during the animation should be honoured.
 * 2. **It cannot represent two components sharing a state.** A mobile menu, a search
 *    overlay and a cookie banner can all be "the top layer is occupied", and which of them
 *    is open determines whether the others open at all. Three independent trigger/action
 *    pairs cannot express that; three states in one machine can.
 * 3. **It cannot represent a responsive difference.** The specification's own example is a
 *    navigation that is a hover mega menu on desktop and a tap drawer on mobile. As a
 *    trigger/action pair that is two components; as a state machine with per-viewport
 *    transitions it is one component with two paths, which is what it actually is.
 *
 * So {@see State_Machine} is the unit of modelling, and a detected interaction is a
 * transition *within* a machine rather than a standalone record. A machine with one
 * transition and no cycle is still a machine — it just happens to be trivial — and being
 * uniform means a consumer never has to ask which shape it is holding.
 *
 * ### What this class is responsible for
 *
 * Building, validating and bounding the aggregate. Detection lives in the detectors;
 * this is the thing they all write into, and the thing that refuses to be inconsistent.
 */
final class Interaction_Model {

	/**
	 * The page this model describes.
	 *
	 * @var string
	 */
	private $page_id = '';

	/**
	 * The source URL.
	 *
	 * @var string
	 */
	private $source_url = '';

	/**
	 * The state machines.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $machines = array();

	/**
	 * The flat interaction list, for consumers that do not want a state machine.
	 *
	 * Derived from the machines rather than maintained alongside them. Two lists kept in
	 * step by hand is exactly the arrangement that produces a trigger with no machine and a
	 * machine with no trigger.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $interactions = array();

	/**
	 * Form observations.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $forms = array();

	/**
	 * Per-viewport summaries.
	 *
	 * @var array<string, mixed>
	 */
	private $responsive = array();

	/**
	 * Animation observations.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $animations = array();

	/**
	 * Honest limitations.
	 *
	 * @var array<int, string>
	 */
	private $limitations = array();

	/**
	 * The analysis status.
	 *
	 * @var string
	 */
	private $status = 'PARTIAL_INTERACTION_ANALYSIS';

	/**
	 * The evidence source that produced the strongest observation.
	 *
	 * @var string
	 */
	private $evidence_source = 'inferred';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $args Initial values.
	 */
	public function __construct( array $args = array() ) {
		$this->page_id    = isset( $args['page_id'] ) ? (string) $args['page_id'] : '';
		$this->source_url = isset( $args['source_url'] ) ? (string) $args['source_url'] : '';
		if ( ! empty( $args['status'] ) && Interaction_Limits::is_status( (string) $args['status'] ) ) {
			$this->status = (string) $args['status'];
		}
	}

	/**
	 * Add a state machine.
	 *
	 * Refuses a machine that does not validate rather than storing it, for the reason
	 * §40 requires: a specification applied without validation is how a replica ends up
	 * with a trigger that points at nothing.
	 *
	 * @param array<string, mixed> $machine State machine.
	 * @return bool True when accepted.
	 */
	public function add_machine( array $machine ) {
		$verdict = State_Machine::validate( $machine );

		if ( empty( $verdict['valid'] ) ) {
			foreach ( (array) $verdict['errors'] as $error ) {
				$this->note_limitation( (string) $error );
			}

			return false;
		}

		$this->machines[] = $machine;
		$this->raise_evidence( isset( $machine['evidence_source'] ) ? (string) $machine['evidence_source'] : 'inferred' );

		/*
		 * The flat interaction list is derived from the machines and cached, so adding a
		 * machine has to invalidate it. Without this the very first call to
		 * `interactions()` — which the budget check in the analysis loop makes before any
		 * machine exists — freezes the list at its empty state and every later addition is
		 * invisible. That produced a report claiming one interaction on a page with
		 * twelve, and it is the kind of bug that a "does it return an array" test would
		 * never catch.
		 */
		$this->interactions = array();

		return true;
	}

	/**
	 * Add a form observation.
	 *
	 * @param array<string, mixed> $form Form observation.
	 * @return bool
	 */
	public function add_form( array $form ) {
		if ( ! Interaction_Limits::is_form_type( (string) ( $form['type'] ?? 'unknown' ) ) ) {
			return false;
		}
		$this->forms[] = $form;

		return true;
	}

	/**
	 * Add an animation observation.
	 *
	 * @param array<string, mixed> $animation Animation observation.
	 * @return bool
	 */
	public function add_animation( array $animation ) {
		if ( empty( $animation['type'] ) ) {
			return false;
		}
		$this->animations[] = $animation;

		return true;
	}

	/**
	 * Record a limitation.
	 *
	 * @param string $limitation Limitation.
	 * @return void
	 */
	public function note_limitation( $limitation ) {
		$limitation = trim( (string) $limitation );
		if ( '' === $limitation || in_array( $limitation, $this->limitations, true ) ) {
			return;
		}
		$this->limitations[] = $limitation;
	}

	/**
	 * Record why the analysis is not a complete picture.
	 *
	 * @param string $reason Reason.
	 * @return void
	 */
	public function note_incomplete( $reason ) {
		$this->note_limitation( $reason );
		if ( 'PARTIAL_INTERACTION_ANALYSIS' === $this->status ) {
			return;
		}
		// An incomplete reason never downgrades a more specific status. If a browser timed
		// out, the truth is BROWSER_TIMEOUT, and a later "we also hit a limit" must not
		// overwrite it with the vaguer PARTIAL.
		if ( in_array( $this->status, Interaction_Limits::INCOMPLETE_STATUSES, true ) ) {
			return;
		}
		$this->status = 'PARTIAL_INTERACTION_ANALYSIS';
	}

	/**
	 * Set the analysis status.
	 *
	 * @param string $status Status.
	 * @return void
	 */
	public function set_status( $status ) {
		if ( Interaction_Limits::is_status( (string) $status ) ) {
			$this->status = (string) $status;
		}
	}

	/**
	 * Return the analysis status.
	 *
	 * @return string
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * Return whether this model is a complete picture of the page's behaviour.
	 *
	 * Reads the status rather than a separate flag, so "complete" cannot be claimed by
	 * forgetting to set something. An absent browser, an exhausted budget or a blocked
	 * source all mean the answer is partial, and the status is the single place that knows.
	 *
	 * @return bool
	 */
	public function is_complete() {
		return 'INTERACTION_ANALYSIS_COMPLETE' === $this->status
			&& array() === $this->limitations;
	}

	/**
	 * Record that an observation of a given evidence source was made.
	 *
	 * The strongest source seen wins, using the ordering in
	 * {@see Interaction_Limits::CONFIDENCE_FLOORS} as the ranking — which is the right
	 * ordering because that constant is the one that decides what gets reported at all.
	 *
	 * @param string $source Evidence source.
	 * @return void
	 */
	private function raise_evidence( $source ) {
		$source = (string) $source;
		if ( ! in_array( $source, Interaction_Limits::EVIDENCE_SOURCES, true ) ) {
			$source = 'inferred';
		}
		if ( Interaction_Limits::confidence_floor( $source ) <= Interaction_Limits::confidence_floor( $this->evidence_source ) ) {
			return;
		}
		$this->evidence_source = $source;
	}

	/**
	 * Return the strongest evidence source seen.
	 *
	 * @return string
	 */
	public function evidence_source() {
		return $this->evidence_source;
	}

	/**
	 * Set the per-viewport summary.
	 *
	 * @param string $viewport Viewport name.
	 * @param array  $summary  Summary.
	 * @return void
	 */
	public function set_viewport( $viewport, array $summary ) {
		$viewport = (string) $viewport;
		if ( '' === $viewport ) {
			return;
		}
		$this->responsive[ $viewport ] = $summary;
	}

	/**
	 * Return the per-viewport summary.
	 *
	 * @return array<string, mixed>
	 */
	public function responsive() {
		return $this->responsive;
	}

	/**
	 * Derive the flat interaction list from the machines.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function interactions() {
		if ( ! empty( $this->interactions ) ) {
			return $this->interactions;
		}

		$flat = array();
		foreach ( $this->machines as $machine ) {
			foreach ( State_Machine::transitions( $machine ) as $transition ) {
				$flat[] = array(
					'interaction_id' => (string) ( $transition['interaction_id'] ?? '' ),
					'component_id'   => (string) ( $machine['component_id'] ?? '' ),
					'type'           => (string) ( $machine['type'] ?? 'unknown' ),
					'trigger'        => (string) ( $transition['trigger'] ?? '' ),
					'source_element' => (string) ( $transition['source_element'] ?? '' ),
					'initial_state'  => (string) ( $transition['from'] ?? '' ),
					'target_state'   => (string) ( $transition['to'] ?? '' ),
					'transition'     => (string) ( $transition['transition'] ?? 'instant' ),
					'duration'       => (int) ( $transition['duration'] ?? 0 ),
					'conditions'     => (array) ( $transition['conditions'] ?? array() ),
					'viewport'       => (string) ( $transition['viewport'] ?? '' ),
					'confidence'     => Interaction_Limits::clamp_confidence( $transition['confidence'] ?? 0 ),
					'evidence'       => array_values( (array) ( $transition['evidence'] ?? array() ) ),
				);
			}
		}

		$this->interactions = $flat;

		return $flat;
	}

	/**
	 * Return the state machines.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function machines() {
		return $this->machines;
	}

	/**
	 * Replace the machines wholesale.
	 *
	 * Exists for one case: promotion after browser observation, where a machine that was
	 * already accepted needs its evidence source and confidence raised because a
	 * transition was actually seen. Adding a second machine for the same element would
	 * give the model two components for one thing and leave the reconstruction choosing
	 * between them.
	 *
	 * It does not re-validate. The machines passed in were validated on the way in, and
	 * re-running the gate here would duplicate it for no gain while making the method
	 * impossible to use for the promotion it exists to serve.
	 *
	 * @param array<int, array<string, mixed>> $machines Machines.
	 * @return void
	 */
	public function replace_machines( array $machines ) {
		$this->machines     = array_values( $machines );
		// The flat interaction list is derived, so it has to be invalidated or the model
		// would report the pre-observation interactions forever.
		$this->interactions = array();
	}

	/**
	 * Return the form observations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function forms() {
		return $this->forms;
	}

	/**
	 * Return the animation observations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function animations() {
		return $this->animations;
	}

	/**
	 * Return the honest limitations.
	 *
	 * @return array<int, string>
	 */
	public function limitations() {
		return $this->limitations;
	}

	/**
	 * Return the aggregate confidence.
	 *
	 * The mean of the interaction confidences, floored by the evidence source. A model
	 * built entirely from inference cannot report a high aggregate just because its
	 * individual guesses happened to be generous — the floor is what stops a pile of
	 * 0.6-confidence inferences averaging up into something that reads as certainty.
	 *
	 * @return float
	 */
	public function confidence() {
		$interactions = $this->interactions();
		if ( array() === $interactions ) {
			return 0.0;
		}

		$sum = 0.0;
		foreach ( $interactions as $interaction ) {
			$sum += (float) $interaction['confidence'];
		}

		$mean = $sum / count( $interactions );

		return round( min( $mean, Interaction_Limits::confidence_floor( $this->evidence_source ) * 2.0 ), 3 );
	}

	/**
	 * Validate the whole model.
	 *
	 * The §40 gate. Every check here exists because its absence produces a specific,
	 * already-considered failure:
	 *
	 * - `no_arbitrary_code` — a model carrying a script or a `javascript:` URL is rejected
	 *   outright. This is the §1 principle, enforced at the point where a model enters the
	 *   system rather than trusted to be well-behaved afterwards.
	 * - `no_unsafe_url` — a transition whose target is a private or unvalidated address.
	 * - `type_declared` — a type not in `Interaction_Limits::TYPES` is a vocabulary
	 *   disagreement, and applying it would mean a consumer guessing.
	 *
	 * @return array<string, mixed>
	 */
	public function validate() {
		$errors = array();
		$warnings = array();

		if ( '' === $this->page_id ) {
			$errors[] = 'A model must name the page it describes.';
		}

		foreach ( $this->interactions() as $interaction ) {
			$label = (string) $interaction['interaction_id'];

			if ( ! Interaction_Limits::is_type( (string) $interaction['type'] ) ) {
				$errors[] = sprintf( 'Interaction %s has a type that is not declared: %s', $label, (string) $interaction['type'] );
			}
			if ( '' === (string) $interaction['component_id'] ) {
				$errors[] = sprintf( 'Interaction %s names no component.', $label );
			}
			if ( ! Interaction_Limits::is_trigger( (string) $interaction['trigger'] ) ) {
				$errors[] = sprintf( 'Interaction %s has a trigger that is not declared: %s', $label, (string) $interaction['trigger'] );
			}
			if ( '' === (string) $interaction['target_state'] ) {
				$errors[] = sprintf( 'Interaction %s has no target state.', $label );
			}
			if ( array() === (array) $interaction['evidence'] ) {
				// Not an error: evidence-less interactions are filtered out before they get
				// this far. Reaching here means one was injected, which is worth knowing.
				$warnings[] = sprintf( 'Interaction %s carries no evidence.', $label );
			}
			if ( (float) $interaction['confidence'] < Interaction_Limits::confidence_floor( 'inferred' ) ) {
				$warnings[] = sprintf( 'Interaction %s is below the reporting floor.', $label );
			}
		}

		$offending = $this->find_code( $this->to_array() );
		if ( array() !== $offending ) {
			$errors[] = 'The model carries content that must never be reproduced: ' . implode( ', ', $offending );
		}

		return array(
			'valid'     => array() === $errors,
			'errors'    => $errors,
			'warnings'  => $warnings,
			'interactions' => count( $this->interactions() ),
			'machines'  => count( $this->machines ),
			'forms'     => count( $this->forms ),
		);
	}

	/**
	 * Find any content in an array that must never reach a replica.
	 *
	 * A deep scan rather than a check of known keys, because the value of this function is
	 * that it does not depend on knowing where someone put the thing. A `script` tag in a
	 * field nobody anticipated is still a `script` tag.
	 *
	 * @param mixed $value Value to scan.
	 * @param int   $depth Current depth.
	 * @return array<int, string> Descriptions of what was found.
	 */
	public static function find_code( $value, $depth = 0 ) {
		$found = array();

		// Bounded so a pathological or self-referential structure cannot spin here. The
		// models this scans are built by this class, so exceeding the depth is itself the
		// signal worth reporting.
		if ( $depth > 12 ) {
			return array( 'a structure nested deeper than 12 levels' );
		}

		if ( is_string( $value ) ) {
			$lower = strtolower( $value );
			foreach ( array( '<script', 'javascript:', 'onerror=', 'onload=', 'onclick=', 'onmouseover=' ) as $needle ) {
				if ( false !== strpos( $lower, $needle ) ) {
					$found[] = $needle;
				}
			}

			return $found;
		}

		if ( ! is_array( $value ) ) {
			return $found;
		}

		foreach ( $value as $child ) {
			foreach ( self::find_code( $child, $depth + 1 ) as $hit ) {
				$found[] = $hit;
			}
		}

		return $found;
	}

	/**
	 * Return the model as the version 16.0 array.
	 *
	 * Key order follows the specification's example, so a reader comparing this to the
	 * brief sees the same shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'schema_version' => Interaction_Limits::SCHEMA_VERSION,
			'engine_version' => Interaction_Limits::ENGINE_VERSION,
			'page_id'        => $this->page_id,
			'source_url'     => $this->source_url,
			'status'         => $this->status,
			'complete'       => $this->is_complete(),
			'evidence_source' => $this->evidence_source,
			'interactions'   => $this->interactions(),
			'states'         => $this->states(),
			'triggers'       => $this->triggers(),
			'transitions'    => $this->interactions(),
			'components'     => $this->components(),
			'state_machines' => $this->machines,
			'navigation'     => $this->navigation(),
			'forms'          => $this->forms,
			'responsive'     => $this->responsive,
			'animations'     => $this->animations,
			'confidence'     => array(
				'overall'         => $this->confidence(),
				'evidence_source' => $this->evidence_source,
				'floor'           => Interaction_Limits::confidence_floor( $this->evidence_source ),
			),
			'limitations'    => $this->limitations,
			'built_at'       => time(),
		);
	}

	/**
	 * Return the distinct states, in first-seen order.
	 *
	 * @return array<int, string>
	 */
	public function states() {
		$states = array();
		foreach ( $this->interactions() as $interaction ) {
			foreach ( array( 'initial_state', 'target_state' ) as $key ) {
				$state = (string) $interaction[ $key ];
				if ( '' !== $state && ! in_array( $state, $states, true ) ) {
					$states[] = $state;
				}
			}
		}

		return $states;
	}

	/**
	 * Return the distinct triggers, in first-seen order.
	 *
	 * @return array<int, string>
	 */
	public function triggers() {
		$triggers = array();
		foreach ( $this->interactions() as $interaction ) {
			$trigger = (string) $interaction['trigger'];
			if ( '' !== $trigger && ! in_array( $trigger, $triggers, true ) ) {
				$triggers[] = $trigger;
			}
		}

		return $triggers;
	}

	/**
	 * Return the distinct components, with their types.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function components() {
		$components = array();
		foreach ( $this->machines as $machine ) {
			$id = (string) ( $machine['component_id'] ?? '' );
			if ( '' === $id || isset( $components[ $id ] ) ) {
				continue;
			}
			$components[ $id ] = array(
				'component_id' => $id,
				'type'         => (string) ( $machine['type'] ?? 'unknown' ),
				'states'       => array_values( (array) ( $machine['states'] ?? array() ) ),
				'role'         => (string) ( $machine['role'] ?? '' ),
			);
		}

		return array_values( $components );
	}

	/**
	 * Return the navigation summary.
	 *
	 * Navigation is pulled out of the machines rather than detected separately, so a
	 * dropdown that is also an accordion is one component that appears in both views rather
	 * than two components that disagree about how many there are.
	 *
	 * @return array<string, mixed>
	 */
	public function navigation() {
		$navigation_types = array( 'navigation', 'dropdown', 'mega_menu', 'mobile_menu' );

		$items = array();
		foreach ( $this->machines as $machine ) {
			if ( ! in_array( (string) ( $machine['type'] ?? '' ), $navigation_types, true ) ) {
				continue;
			}
			$items[] = array(
				'component_id'   => (string) ( $machine['component_id'] ?? '' ),
				'type'           => (string) $machine['type'],
				'role'           => (string) ( $machine['role'] ?? '' ),
				'initial_state'  => (string) ( $machine['initial_state'] ?? 'closed' ),
				'triggers'       => array_values( array_map(
					static function ( $transition ) {
						return (string) ( $transition['trigger'] ?? '' );
					},
					State_Machine::transitions( $machine )
				) ),
				'by_viewport'    => (array) ( $machine['by_viewport'] ?? array() ),
			);
		}

		return array(
			'count'    => count( $items ),
			'items'    => $items,
			'patterns' => $this->navigation_patterns( $items ),
		);
	}

	/**
	 * Summarise the navigation patterns found.
	 *
	 * @param array<int, array<string, mixed>> $items Navigation items.
	 * @return array<string, mixed>
	 */
	private function navigation_patterns( array $items ) {
		$patterns = array(
			'hover_dropdown'  => 0,
			'click_dropdown'  => 0,
			'drawer'          => 0,
			'mega_menu'       => 0,
			'accordion_submenu' => 0,
		);

		foreach ( $items as $item ) {
			switch ( (string) $item['type'] ) {
				case 'mega_menu':
					$patterns['mega_menu']++;
					break;
				case 'mobile_menu':
					$patterns['drawer']++;
					break;
				case 'dropdown':
					foreach ( (array) $item['triggers'] as $trigger ) {
						if ( 'hover' === $trigger ) {
							$patterns['hover_dropdown']++;
						} elseif ( in_array( (string) $trigger, array( 'click', 'tap' ), true ) ) {
							$patterns['click_dropdown']++;
						}
					}
					break;
			}
		}

		return $patterns;
	}
}
