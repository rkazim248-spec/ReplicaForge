<?php
/**
 * Phase 16: the interaction validator.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The gate an interaction specification must pass before anything uses it.
 *
 * ### Why this is a separate class and not a method on the mapper
 *
 * Because the mapper and the validator answer different questions, and the order they run
 * in is a security property. The mapper asks "what can we do about this"; the validator
 * asks "is this thing safe to act on at all". A gate that ran *after* mapping would be
 * checking a record that had already been resolved into a destination widget, which is one
 * transformation too late — by then the dangerous content has been copied into a field a
 * builder trusts.
 *
 * So validation runs on the *source* model, before mapping, and the mapper's output is
 * trusted only because the input was already checked. There is a second, cheaper check on
 * the mapped output too, because a mapping is constructed from two inputs and one of them
 * is a live Elementor installation.
 *
 * ### The checks, and what each one prevents
 *
 * - `no_arbitrary_code` — §1. A model carrying `<script`, `javascript:` or an inline
 *   handler is rejected outright. This is the check that must never be a warning.
 * - `no_unsafe_url` — a transition target that is not a validated public URL would turn
 *   the replica into a request the operator did not authorise.
 * - `component_exists` — a trigger whose element is not in the model cannot be bound to
 *   anything, and a widget with an unbound trigger is a button that does nothing.
 * - `transition_valid` — delegated to {@see State_Machine::validate()}.
 * - `capability_exists` — the destination must actually have the widget, resolved live.
 * - `viewport_valid` — a viewport that is not one of the three declared ones is a typo,
 *   and a typo here silently drops responsive behaviour.
 */
final class Interaction_Validator {

	/**
	 * The Elementor capability reader.
	 *
	 * @var Elementor_Compatibility
	 */
	private $elementor;

	/**
	 * Constructor.
	 *
	 * @param Elementor_Compatibility|null $elementor Compatibility reader.
	 */
	public function __construct( $elementor = null ) {
		$this->elementor = $elementor instanceof Elementor_Compatibility ? $elementor : new Elementor_Compatibility();
	}

	/**
	 * Validate a model.
	 *
	 * @param Interaction_Model $model Model.
	 * @return array<string, mixed>
	 */
	public function validate( Interaction_Model $model ) {
		$errors   = array();
		$warnings = array();

		$internal = $model->validate();
		foreach ( (array) $internal['errors'] as $error ) {
			$errors[] = (string) $error;
		}
		foreach ( (array) $internal['warnings'] as $warning ) {
			$warnings[] = (string) $warning;
		}

		$components = array();
		foreach ( $model->components() as $component ) {
			$components[ (string) $component['component_id'] ] = true;
		}

		foreach ( $model->interactions() as $interaction ) {
			$label = '' !== (string) $interaction['interaction_id']
				? (string) $interaction['interaction_id']
				: (string) $interaction['component_id'];

			if ( ! isset( $components[ (string) $interaction['component_id'] ] ) ) {
				$errors[] = sprintf( 'Interaction %s names a component that is not in the model.', $label );
			}

			if ( '' === (string) $interaction['source_element'] ) {
				$errors[] = sprintf( 'Interaction %s has no source element, so it cannot be bound to a widget.', $label );
			}

			$viewport = (string) $interaction['viewport'];
			if ( '' !== $viewport && ! array_key_exists( $viewport, Interaction_Limits::viewports() ) ) {
				$errors[] = sprintf( 'Interaction %s names a viewport that is not declared: %s', $label, $viewport );
			}

			$duration = (int) $interaction['duration'];
			if ( $duration < 0 || $duration > 10000 ) {
				// A transition longer than ten seconds is not a transition, and a negative
				// one is a sign the value was not read from the page. Either way it is
				// refused rather than clamped, because clamping hides the bad value.
				$errors[] = sprintf( 'Interaction %s has an implausible transition duration: %d ms.', $label, $duration );
			}
		}

		foreach ( $model->machines() as $machine ) {
			foreach ( State_Machine::validate( $machine )['errors'] as $error ) {
				$errors[] = sprintf( 'Machine %s: %s', (string) ( $machine['component_id'] ?? '?' ), (string) $error );
			}
		}

		$unsafe = $this->find_unsafe_urls( $model->to_array() );
		foreach ( $unsafe as $found ) {
			$errors[] = 'The model carries a URL that is not a validated public address: ' . $found;
		}

		if ( array() === $model->interactions() ) {
			$warnings[] = 'The model contains no interactions.';
		}

		if ( ! $model->is_complete() ) {
			// Not an error. A partial analysis is a legitimate result, and the status says
			// so. The warning exists so a consumer that wanted completeness notices.
			$warnings[] = sprintf( 'The analysis is not complete (%s), so this model is not a full picture of the page.', $model->status() );
		}

		return array(
			'valid'        => array() === $errors,
			'errors'       => $errors,
			'warnings'     => $warnings,
			'interactions' => count( $model->interactions() ),
			'components'   => count( $components ),
			'machines'     => count( $model->machines() ),
		);
	}

	/**
	 * Validate a mapped record.
	 *
	 * A second gate, and it is not redundant. The first runs on the model; this runs on
	 * the mapping, which was resolved against a *live* Elementor that could have changed
	 * between the two calls, and which produced a widget name the builder will trust.
	 *
	 * @param array<string, mixed> $mapping Mapped record.
	 * @return array<string, mixed>
	 */
	public function validate_mapping( array $mapping ) {
		$errors = array();

		$type    = (string) ( $mapping['type'] ?? '' );
		$outcome = (string) ( $mapping['outcome'] ?? '' );
		$widget  = (string) ( $mapping['widget'] ?? '' );

		if ( ! Interaction_Limits::is_type( $type ) ) {
			$errors[] = sprintf( 'Mapping names an undeclared interaction type: %s', $type );
		}
		if ( ! Interaction_Limits::is_outcome( $outcome ) ) {
			$errors[] = sprintf( 'Mapping names an undeclared outcome: %s', $outcome );
		}

		$forbidden = Interaction_Model::find_code( $mapping );
		if ( array() !== $forbidden ) {
			$errors[] = 'Mapping carries content that must never reach a document: ' . implode( ', ', $forbidden );
		}

		if ( in_array( $outcome, array( 'supported', 'approximation' ), true ) ) {
			if ( '' === $widget ) {
				// The most important check here. An outcome claiming support with no widget
				// is a promise the builder cannot keep, and it would fail much later at
				// save time with a message about a missing widget rather than about the
				// interaction.
				$errors[] = sprintf( 'Mapping claims "%s" for a %s but names no widget.', $outcome, $type );
			} elseif ( ! $this->elementor->has_widget( $widget ) ) {
				$errors[] = sprintf( 'Mapping names the widget "%s", which this Elementor installation does not have.', $widget );
			}
		}

		if ( 'unsupported' === $outcome && '' === (string) ( $mapping['fallback'] ?? '' ) ) {
			$errors[] = sprintf( 'An unsupported %s must state a fallback, per the unsupported-behaviour rules.', $type );
		}

		return array(
			'valid'  => array() === $errors,
			'errors' => $errors,
		);
	}

	/**
	 * Find any URL in a structure that is not a validated public address.
	 *
	 * Every URL is put through {@see Url_Validator} rather than pattern-matched, because
	 * pattern-matching a URL is how `http://127.0.0.1` and `http://2130706433` get past.
	 * The validator resolves DNS and applies the private-address policy, which is exactly
	 * the check a destination URL needs and exactly the one a regex cannot do.
	 *
	 * The source URL of the page is exempt, because it has already been through the same
	 * validator to be analysed at all, and requiring it again would report a legitimate
	 * page as unsafe.
	 *
	 * @param mixed $value  Value to scan.
	 * @param int   $depth  Current depth.
	 * @return array<int, string>
	 */
	public function find_unsafe_urls( $value, $depth = 0 ) {
		$found    = array();
		$validator = new Url_Validator();

		if ( $depth > 12 ) {
			return $found;
		}

		if ( is_string( $value ) ) {
			/*
			 * An executable scheme is refused before the URL is even looked at.
			 *
			 * This was a real gap. `Url_Validator` is built for navigation and
			 * declines anything that is not http(s) - but it declines it by
			 * returning an envelope with a code, and a caller that only asked
			 * "is this a valid public address?" had nothing to detect for a
			 * `javascript:` string. Relying on a collaborator to refuse
			 * executable content, and relying on it through a question whose
			 * answer is "no opinion", is how a `javascript:` URL reaches a replica.
			 */
			if ( preg_match( '#^\s*(javascript|data|vbscript|blob|file)\s*:#i', $value ) ) {
				$found[] = substr( $value, 0, 60 );
				return $found;
			}

			if ( '' === trim( $value ) || ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $value ) ) {
				return $found;
			}
			// Anchors and relative fragments are not URLs and never leave the page.
			if ( 0 === strpos( $value, '#' ) ) {
				return $found;
			}

			$verdict = $validator->validate( $value );
			if ( empty( $verdict['success'] ) ) {
				$found[] = $value;
			}

			return $found;
		}

		if ( ! is_array( $value ) ) {
			return $found;
		}

		// The page's own source URL is exempt, and exempting it here rather than at the
		// call site means a nested copy of it elsewhere is also exempt — which is correct,
		// because it is the same validated address.
		$source = isset( $value['source_url'] ) ? (string) $value['source_url'] : '';

		foreach ( $value as $child ) {
			if ( is_string( $child ) && '' !== $source && $child === $source ) {
				continue;
			}
			foreach ( $this->find_unsafe_urls( $child, $depth + 1 ) as $hit ) {
				$found[] = $hit;
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Return the checks this validator performs, for the capability endpoint.
	 *
	 * @return array<int, string>
	 */
	public function checks() {
		return array(
			'no_arbitrary_code',
			'no_unsafe_url',
			'component_exists',
			'transition_valid',
			'trigger_declared',
			'type_declared',
			'viewport_valid',
			'duration_plausible',
			'capability_exists',
			'fallback_stated',
		);
	}
}
