<?php
/**
 * Phase 16: the state machine.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * One component's states and the transitions between them.
 *
 * ### The shape
 *
 *     array(
 *       'machine_id'      => string,
 *       'component_id'    => string,
 *       'type'            => string,   // Interaction_Limits::TYPES
 *       'role'            => string,   // 'navigation' | 'dialog' | 'disclosure' | …
 *       'initial_state'   => string,
 *       'states'          => array<int, string>,
 *       'transitions'     => array<int, array<string, mixed>>,
 *       'by_viewport'     => array<string, mixed>,
 *       'evidence_source' => string,   // declared | inferred | observed | visual
 *       'evidence'        => array<int, string>,
 *       'confidence'      => float,
 *     )
 *
 * A transition is:
 *
 *     array(
 *       'interaction_id' => string,
 *       'trigger'        => string,   // Interaction_Limits::TRIGGERS
 *       'source_element' => string,   // the element that carries the trigger
 *       'from'           => string,
 *       'to'             => string,
 *       'transition'     => string,   // 'instant' | 'fade' | 'slide' | 'expand'
 *       'duration'       => int,      // ms
 *       'conditions'     => array,    // viewport, media query, breakpoint
 *       'viewport'       => string,
 *       'confidence'     => float,
 *       'evidence'       => array<int, string>,
 *     )
 *
 * ### Why `from` is required on every transition
 *
 * It looks redundant next to the machine's `initial_state`, and for a linear machine it is.
 * It is not redundant for a machine that returns — closed → open → closed — where the
 * second transition's `from` is `open`, not `initial_state`. Without it a consumer has to
 * infer the sequence, and inferring a sequence is exactly how a cycle becomes a list of
 * independent one-way rules that fight each other when two are applied.
 */
final class State_Machine {

	/**
	 * Return a new machine with the given identity.
	 *
	 * @param string $component_id Component id.
	 * @param string $type         Interaction type.
	 * @param string $role         Component role.
	 * @return array<string, mixed>
	 */
	public static function make( $component_id, $type, $role = '' ) {
		$type = Interaction_Limits::is_type( (string) $type ) ? (string) $type : 'unknown';

		return array(
			'machine_id'      => 'im_' . substr( hash( 'sha256', (string) $component_id . '|' . $type ), 0, 16 ),
			'component_id'    => (string) $component_id,
			'type'            => $type,
			'role'            => (string) $role,
			'initial_state'   => Interaction_Limits::initial_state( $type ),
			'states'          => array( Interaction_Limits::initial_state( $type ) ),
			'transitions'     => array(),
			'by_viewport'     => array(),
			'evidence_source' => 'inferred',
			'evidence'        => array(),
			'confidence'      => 0.0,
		);
	}

	/**
	 * Add a transition to a machine, registering any new state.
	 *
	 * Returns the machine rather than a bool, because the caller almost always wants to
	 * keep building the same structure and threading a return value through every call
	 * invites forgetting to reassign it.
	 *
	 * @param array  $machine    Machine.
	 * @param string $trigger    Trigger.
	 * @param string $from       Source state.
	 * @param string $to         Target state.
	 * @param string $element    Source element.
	 * @param array  $attributes Optional: transition, duration, conditions, viewport,
	 *                           confidence, evidence.
	 * @return array<string, mixed>
	 */
	public static function add_transition( array $machine, $trigger, $from, $to, $element = '', array $attributes = array() ) {
		$from = (string) $from;
		$to   = (string) $to;

		$machine['states'] = array_values( array_unique( array_merge( (array) $machine['states'], array( $from, $to ) ) ) );

		$machine['transitions'][] = array(
			'interaction_id' => 'ix_' . substr( hash( 'sha256', (string) $machine['machine_id'] . '|' . $trigger . '|' . $from . '|' . $to . '|' . (string) ( $attributes['viewport'] ?? '' ) ), 0, 16 ),
			'trigger'        => Interaction_Limits::is_trigger( (string) $trigger ) ? (string) $trigger : 'click',
			'source_element' => (string) $element,
			'from'           => $from,
			'to'             => $to,
			'transition'     => (string) ( $attributes['transition'] ?? 'instant' ),
			'duration'       => max( 0, min( 10000, (int) ( $attributes['duration'] ?? 0 ) ) ),
			'conditions'     => (array) ( $attributes['conditions'] ?? array() ),
			'viewport'       => (string) ( $attributes['viewport'] ?? '' ),
			'confidence'     => Interaction_Limits::clamp_confidence( $attributes['confidence'] ?? 0 ),
			'evidence'       => array_values( array_map( 'strval', (array) ( $attributes['evidence'] ?? array() ) ) ),
		);

		// The machine's own confidence and evidence follow its transitions, so a caller
		// never has to remember to raise them. The maximum wins: a machine is as confident
		// as its best-supported transition, and averaging would let five weak inferences
		// dilute one strong declared attribute.
		$last = $machine['transitions'][ count( $machine['transitions'] ) - 1 ];
		if ( $last['confidence'] > (float) $machine['confidence'] ) {
			$machine['confidence'] = $last['confidence'];
		}
		$machine['evidence'] = array_values( array_unique( array_merge( (array) $machine['evidence'], $last['evidence'] ) ) );

		return $machine;
	}

	/**
	 * Add a closed cycle to a machine.
	 *
	 * This is the shape the specification gives for four of its five examples — closed,
	 * open, closed — and it is the most common shape in practice for anything that toggles.
	 * Having it as a named operation rather than two `add_transition()` calls means the
	 * common case cannot be written half-way.
	 *
	 * @param array  $machine Machine.
	 * @param string $open    Open trigger.
	 * @param string $close   Close trigger.
	 * @param string $closed  Closed state.
	 * @param string $open_to Open state.
	 * @param string $element Source element.
	 * @param array  $attrs   Optional attributes.
	 * @return array<string, mixed>
	 */
	public static function add_cycle( array $machine, $open, $close, $closed, $open_to, $element = '', array $attrs = array() ) {
		$machine = self::add_transition( $machine, $open, $closed, $open_to, $element, $attrs );
		$machine = self::add_transition( $machine, $close, $open_to, $closed, $element, $attrs );

		return $machine;
	}

	/**
	 * Return whether a machine forms a cycle.
	 *
	 * Reported rather than assumed, because "this is a toggle" and "this is a one-way
	 * reveal" are different facts about the same component and a consumer reconstructing a
	 * cycle as a one-shot produces a menu that opens once and never closes.
	 *
	 * @param array<string, mixed> $machine Machine.
	 * @return bool
	 */
	public static function is_cyclic( array $machine ) {
		$edges = array();
		foreach ( self::transitions( $machine ) as $transition ) {
			$edges[ (string) $transition['from'] ][] = (string) $transition['to'];
		}

		// Depth-first cycle search. Bounded by the state count, so a machine that names a
		// state twice cannot loop.
		$visit = function ( $node, $seen ) use ( &$visit, $edges ) {
			if ( isset( $seen[ $node ] ) ) {
				return true;
			}
			$seen[ $node ] = true;
			foreach ( (array) ( $edges[ $node ] ?? array() ) as $next ) {
				if ( $visit( $next, $seen ) ) {
					return true;
				}
			}

			return false;
		};

		foreach ( array_keys( $edges ) as $start ) {
			if ( $visit( $start, array() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the transitions of a machine.
	 *
	 * @param array<string, mixed> $machine Machine.
	 * @return array<int, array<string, mixed>>
	 */
	public static function transitions( array $machine ) {
		return array_values( (array) ( $machine['transitions'] ?? array() ) );
	}

	/**
	 * Return the states reachable from the initial state.
	 *
	 * Used by the mapping step, which needs to know what behaviour a widget has to
	 * reproduce — and a state that cannot be reached is a state the widget does not need.
	 *
	 * @param array<string, mixed> $machine Machine.
	 * @return array<int, string>
	 */
	public static function reachable_states( array $machine ) {
		$outgoing = array();
		foreach ( self::transitions( $machine ) as $transition ) {
			$outgoing[ (string) $transition['from'] ][] = (string) $transition['to'];
		}

		/*
		 * The traversal starts from every transition's `from`, not only from the initial
		 * state — and it has to.
		 *
		 * A machine can legitimately have states nothing can reach *from* its initial
		 * state, and that is not a defect: a detector may attach a transition to a
		 * component whose initial state the vocabulary describes differently from the
		 * one the transition leaves. Seeding only from `initial_state` reported such a
		 * machine as containing exactly one reachable state while its own transition
		 * plainly fires, which is the kind of contradiction that makes a caller distrust
		 * the whole model rather than the one wrong method.
		 *
		 * So: everything a transition can leave *is* reachable, and from there the usual
		 * closure. `initial_state` is included too, so a machine with no transitions yet
		 * still reports the one state it has.
		 */
		$seeds = array( (string) ( $machine['initial_state'] ?? '' ) );
		foreach ( array_keys( $outgoing ) as $from ) {
			$seeds[] = $from;
		}

		$reached = array();
		$queue   = array();
		foreach ( array_filter( array_unique( $seeds ) ) as $seed ) {
			$reached[ $seed ] = true;
			$queue[]          = $seed;
		}

		$guard = 0;
		while ( array() !== $queue && $guard < 256 ) {
			$node = array_shift( $queue );
			foreach ( (array) ( $outgoing[ $node ] ?? array() ) as $next ) {
				if ( isset( $reached[ $next ] ) ) {
					continue;
				}
				$reached[ $next ] = true;
				$queue[]          = $next;
				$guard++;
			}
		}

		return array_keys( $reached );
	}

	/**
	 * Validate a machine.
	 *
	 * @param array<string, mixed> $machine Machine.
	 * @return array<string, mixed>
	 */
	public static function validate( array $machine ) {
		$errors = array();

		if ( '' === (string) ( $machine['component_id'] ?? '' ) ) {
			$errors[] = 'A state machine must name a component.';
		}
		if ( ! Interaction_Limits::is_type( (string) ( $machine['type'] ?? '' ) ) ) {
			$errors[] = sprintf( 'Interaction type is not declared: %s', (string) ( $machine['type'] ?? '' ) );
		}
		if ( '' === (string) ( $machine['initial_state'] ?? '' ) ) {
			$errors[] = 'A state machine must name an initial state.';
		}

		$states   = array_map( 'strval', (array) ( $machine['states'] ?? array() ) );
		$initial  = (string) ( $machine['initial_state'] ?? '' );
		if ( '' !== $initial && ! in_array( $initial, $states, true ) ) {
			$errors[] = sprintf( 'The initial state %s is not in the machine\'s states.', $initial );
		}

		$transitions = self::transitions( $machine );
		if ( array() === $transitions ) {
			$errors[] = sprintf( 'Component %s has a state machine with no transitions.', (string) ( $machine['component_id'] ?? '' ) );
		}

		foreach ( $transitions as $transition ) {
			$label = (string) ( $transition['interaction_id'] ?? '?' );
			if ( ! Interaction_Limits::is_trigger( (string) ( $transition['trigger'] ?? '' ) ) ) {
				$errors[] = sprintf( 'Transition %s has an undeclared trigger.', $label );
			}
			if ( ! in_array( (string) ( $transition['from'] ?? '' ), $states, true ) ) {
				$errors[] = sprintf( 'Transition %s starts from a state the machine does not have.', $label );
			}
			if ( ! in_array( (string) ( $transition['to'] ?? '' ), $states, true ) ) {
				$errors[] = sprintf( 'Transition %s ends in a state the machine does not have.', $label );
			}
			if ( '' === (string) ( $transition['to'] ?? '' ) ) {
				$errors[] = sprintf( 'Transition %s has no target state.', $label );
			}
			if ( '' === (string) ( $transition['source_element'] ?? '' ) ) {
				// A trigger with no element is a trigger nobody can bind to a widget, which
				// is the single most common way a reconstructed interaction does nothing.
				$errors[] = sprintf( 'Transition %s names no source element, so it cannot be bound.', $label );
			}
		}

		return array(
			'valid'       => array() === $errors,
			'errors'      => $errors,
			'states'      => $states,
			'transitions' => count( $transitions ),
			'cyclic'      => self::is_cyclic( $machine ),
		);
	}

	/**
	 * Render a machine as a human-readable timeline.
	 *
	 * §49 asks for this, and it earns its place for a specific reason: when a replica's
	 * menu does the wrong thing, the first question is not "what state machine did you
	 * build" but "what did you think the source did". A linear timeline answers that
	 * without the reader needing to understand the model.
	 *
	 * @param array<string, mixed> $machine Machine.
	 * @return array<int, array<string, mixed>>
	 */
	public static function timeline( array $machine ) {
		$rows   = array();
		$rows[] = array( 'step' => 0, 'state' => (string) ( $machine['initial_state'] ?? '' ), 'trigger' => '', 'note' => 'initial' );

		$step = 1;
		foreach ( self::transitions( $machine ) as $transition ) {
			$rows[] = array(
				'step'    => $step,
				'state'   => (string) $transition['to'],
				'trigger' => (string) $transition['trigger'],
				'from'    => (string) $transition['from'],
				'element' => (string) $transition['source_element'],
				'viewport' => (string) $transition['viewport'],
				'note'    => (string) $transition['transition'],
			);
			$step++;
		}

		return $rows;
	}
}
