<?php
/**
 * Phase 16: the interaction service.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates interaction analysis: detect, observe, model, map, validate, report.
 *
 * ### The two-tier design, and why it is not a fallback
 *
 * Tier one is static detection from the phase 2 DOM, and it always runs. Tier two is
 * browser observation, and it runs only when an operator has configured a
 * {@see Browser_Driver_Contract}. Tier two *confirms* what tier one inferred; it is never
 * the only source, because a browser that is unavailable must not mean "no interactions",
 * and on this plugin's own terms that would be a fabricated result.
 *
 * The consequence is that the plugin is useful with no browser configured, and more useful
 * with one. Both states are first-class and both are reported honestly:
 *
 *     no browser   →  status BROWSER_UNAVAILABLE, a full static model
 *     browser      →  status INTERACTION_ANALYSIS_COMPLETE, a confirmed model
 *     budget spent →  status INTERACTION_LIMIT_REACHED, a partial model that says so
 *
 * ### Why forms are analysed here rather than in their own class
 *
 * The specification suggests a separate form analyzer. This service does it inline, and
 * that is a deliberate refusal to add a class rather than an omission: form analysis reads
 * the same node table, walks the same ancestors, and needs the same class-hint vocabulary
 * as {@see Interaction_Detector}. A separate `Form_Analyzer` would either duplicate that
 * vocabulary — two places to update when a site uses a new convention — or hold a
 * reference to the detector and be a wrapper with no independent behaviour. The rule
 * throughout this plugin is one home per piece of knowledge, and that applies to class
 * boundaries as much as to constants.
 *
 * ### What is never done here
 *
 * A form is never submitted. A driver is never asked to type a value. An interaction
 * outside {@see Interaction_Limits::OBSERVABLE_TYPES} is never triggered. These are not
 * policy statements in a comment — they are enforced by the allowlist the driver receives,
 * and the session recorder refuses an observation of a type that was not requested.
 */
final class Interaction_Service {

	/**
	 * The static detector.
	 *
	 * @var Interaction_Detector
	 */
	private $detector;

	/**
	 * The Elementor capability mapper.
	 *
	 * @var Interaction_Mapper
	 */
	private $mapper;

	/**
	 * The validator.
	 *
	 * @var Interaction_Validator
	 */
	private $validator;

	/**
	 * The browser driver, when one is configured.
	 *
	 * @var Browser_Driver_Contract|null
	 */
	private $driver;

	/**
	 * The URL validator, re-used rather than reimplemented.
	 *
	 * @var Url_Validator
	 */
	private $urls;

	/**
	 * The observation cache.
	 *
	 * @var Render_Cache|null
	 */
	private $cache;

	/**
	 * The logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $services Optional: detector, mapper, validator, driver,
	 *                                       cache, logger.
	 */
	public function __construct( array $services = array() ) {
		/*
		 * Read with `isset()` throughout rather than with `??` on the bare key, because
		 * `isset( $services['x'] instanceof Y )` does not short-circuit the way it looks
		 * like it does: the array access is evaluated, and on a missing key PHP raises a
		 * warning before the `instanceof` ever gets to answer false. Five warnings on
		 * every construction is not a cosmetic problem - it is the kind of noise that
		 * trains a developer to ignore the error log.
		 */
		$this->detector  = isset( $services['detector'] ) && $services['detector'] instanceof Interaction_Detector
			? $services['detector']
			: new Interaction_Detector();
		$this->mapper    = isset( $services['mapper'] ) && $services['mapper'] instanceof Interaction_Mapper
			? $services['mapper']
			: new Interaction_Mapper();
		$this->validator = isset( $services['validator'] ) && $services['validator'] instanceof Interaction_Validator
			? $services['validator']
			: new Interaction_Validator();
		$this->driver    = isset( $services['driver'] ) && $services['driver'] instanceof Browser_Driver_Contract ? $services['driver'] : null;
		$this->urls      = new Url_Validator();
		$this->cache     = isset( $services['cache'] ) && $services['cache'] instanceof Render_Cache ? $services['cache'] : null;
		$this->logger    = isset( $services['logger'] ) && $services['logger'] instanceof Logger ? $services['logger'] : new Logger();
	}

	/**
	 * Set the browser driver.
	 *
	 * A setter rather than only a constructor argument, because the driver is operator
	 * configuration and an operator changes it by filter at runtime — a driver registered
	 * at boot cannot be changed without editing the bootstrap.
	 *
	 * @param Browser_Driver_Contract|null $driver Driver.
	 * @return void
	 */
	public function set_driver( $driver ) {
		$this->driver = $driver instanceof Browser_Driver_Contract ? $driver : null;
	}

	/**
	 * Return the capabilities of this service, including the driver's.
	 *
	 * @return array<string, mixed>
	 */
	public function capabilities() {
		$driver = $this->driver_report();

		return array(
			'schema_version'    => Interaction_Limits::SCHEMA_VERSION,
			'engine_version'    => Interaction_Limits::ENGINE_VERSION,
			'static_detection'  => true,
			'browser'           => $driver,
			'budgets'           => Interaction_Limits::budgets(),
			'observable_types'  => array_values( Interaction_Limits::OBSERVABLE_TYPES ),
			'form_types'        => array_values( Interaction_Limits::FORM_TYPES ),
			'statuses'          => array_values( Interaction_Limits::STATUSES ),
			'checks'            => $this->validator->checks(),
			'note'              => $driver['available']
				? 'Static detection plus browser observation.'
				: 'Static detection only. Configure a Browser_Driver_Contract to confirm state transitions.',
		);
	}

	/**
	 * Report the driver's state.
	 *
	 * @return array<string, mixed>
	 */
	public function driver_report() {
		if ( null === $this->driver ) {
			return array(
				'available'   => false,
				'id'          => 'none',
				'version'     => '',
				'capabilities' => array(),
				'reason'      => 'No browser observation driver is configured. Static detection still runs.',
			);
		}

		$capabilities = (array) $this->driver->capabilities();
		// Every key the contract names must be present as a boolean, so a consumer gets a
		// complete answer rather than having to know which keys a given driver reports.
		$complete = array();
		foreach ( array( 'dom_snapshot', 'accessibility_tree', 'computed_styles', 'geometry', 'trigger', 'screenshot', 'network_events' ) as $name ) {
			$complete[ $name ] = ! empty( $capabilities[ $name ] );
		}

		return array(
			'available'    => (bool) $this->driver->is_available(),
			'id'           => (string) $this->driver->id(),
			'version'      => (string) $this->driver->version(),
			'capabilities' => $complete,
			'reason'       => $this->driver->is_available() ? '' : (string) $this->driver->unavailable_reason(),
		);
	}

	/**
	 * Analyse one page.
	 *
	 * @param string $page_id    Page identifier.
	 * @param string $source_url Source URL.
	 * @param array  $context    Phase 2 analysis context.
	 * @param array  $options    `viewport`, `project_id`, `source_hash`, `budget`, `observe`.
	 * @return array<string, mixed>
	 */
	public function analyze( $page_id, $source_url, array $context, array $options = array() ) {
		$started   = microtime( true );
		$page_id   = (string) $page_id;
		$viewport  = isset( $options['viewport'] ) && array_key_exists( (string) $options['viewport'], Interaction_Limits::viewports() )
			? (string) $options['viewport']
			: 'desktop';
		$project   = isset( $options['project_id'] ) ? (string) $options['project_id'] : '';
		$budget    = $this->budget( isset( $options['budget'] ) ? (array) $options['budget'] : array() );
		$want_observe = ! isset( $options['observe'] ) || ! empty( $options['observe'] );

		$model = new Interaction_Model( array( 'page_id' => $page_id, 'source_url' => (string) $source_url ) );

		// ---- Tier one: static detection. Always runs.
		$candidates = $this->detector->detect( $context, $viewport );
		$dropped    = 0;

		foreach ( $candidates as $candidate ) {
			if ( count( $model->interactions() ) >= $budget['max_interactions'] ) {
				$dropped++;
				continue;
			}

			$machine = $this->machine_for( $candidate, $context, $viewport );
			if ( ! $model->add_machine( $machine ) ) {
				$dropped++;
			}
		}

		if ( $dropped > 0 ) {
			$model->note_incomplete( sprintf(
				'%d interaction candidate(s) were not modelled, because the per-analysis ceiling of %d was reached.',
				$dropped,
				(int) $budget['max_interactions']
			) );
			$model->set_status( 'INTERACTION_LIMIT_REACHED' );
		}

		// ---- Forms, from the same node table.
		foreach ( $this->analyze_forms( $context, $page_id ) as $form ) {
			$model->add_form( $form );
		}

		// ---- Responsive comparison, when more than one viewport has been analysed.
		$this->apply_responsive( $model, $viewport );

		/*
		 * The source is validated *before* anything else in the observation path, and
		 * before the driver is consulted at all.
		 *
		 * The order matters for a specific reason: `observe()` asks the driver first and
		 * only then decides whether the URL is analysable, so with no driver configured
		 * a cloud-metadata address came back as BROWSER_UNAVAILABLE — true, and useless,
		 * because it describes the driver's absence rather than the fact that the URL was
		 * never safe to request. The two facts have different remedies (configure a
		 * driver versus stop asking for that URL), and a caller reading one instead of the
		 * other acts on the wrong one.
		 */
		$url_verdict = $this->urls->validate( $source_url );
		if ( empty( $url_verdict['success'] ) ) {
			$code   = (string) ( $url_verdict['error']['code'] ?? '' );
			$status = in_array( $code, array( 'forbidden_target', 'credentials_not_allowed' ), true ) ? 'PRIVATE_PAGE' : 'SOURCE_BLOCKED';

			$model->set_status( $status );
			$model->note_limitation( sprintf(
				'This page was not analysed. The URL was refused (%s): %s',
				'' !== $code ? $code : 'unknown',
				(string) ( $url_verdict['error']['message'] ?? 'the address is not analysable.' )
			) );

			Security::log_event( 'interaction_analysis_blocked', array( 'reason' => $code ) );

			return $this->finish( $model, $candidates, $budget, $project, $started, $viewport );
		}

		// ---- Tier two: browser observation.
		$status = $model->status();
		if ( $want_observe ) {
			$observed = $this->observe( (string) $url_verdict['url'], $candidates, $budget, $project );
			$this->apply_observations( $model, $observed );

			/*
			 * A status without a reason is half a report. Every observation outcome that
			 * is not a clean completion records why, so a reader is never left with
			 * "BROWSER_UNAVAILABLE" and no explanation of what to do about it — which is
			 * the same failure the phase 13 renderer docs call out as indistinguishable
			 * from a crash.
			 */
			$reason = trim( (string) ( $observed['reason'] ?? '' ) );
			if ( '' !== $reason ) {
				$model->note_limitation( $reason );
			}

			if ( '' === (string) ( $observed['status'] ?? '' ) ) {
				$model->set_status( 'INTERACTION_ANALYSIS_COMPLETE' );
			} elseif ( 'INTERACTION_ANALYSIS_COMPLETE' !== $model->status() ) {
				$model->set_status( (string) $observed['status'] );
			}

			if ( ! empty( $observed['skipped'] ) ) {
				$model->note_limitation( sprintf(
					'%d candidate(s) were not triggered, because no interaction on this page is on the safe-observation list. They were inspected, not activated.',
					(int) $observed['skipped']
				) );
			}
		} elseif ( 'INTERACTION_ANALYSIS_COMPLETE' === $status ) {
			// Observation was not asked for, so the result is deliberately not "complete".
			$model->set_status( 'BROWSER_UNAVAILABLE' );
			$model->note_limitation( 'Browser observation was not requested for this analysis, so state transitions were inferred from the markup rather than confirmed.' );
		}

		if ( array() === $model->interactions() ) {
			$model->note_incomplete( 'No interactions were detected on this page.' );
		}

		return $this->finish( $model, $candidates, $budget, $project, $started, $viewport );
	}

	/**
	 * Map, validate and report.
	 *
	 * Extracted so the blocked-source path and the analysed path produce the same *shape*
	 * of report. A caller asking for a page that turned out to be unanalysable should get
	 * the full report structure with a status that explains itself — not a shorter
	 * response, because two response shapes for two situations is two things for every
	 * consumer to handle.
	 *
	 * @param Interaction_Model  $model      Model.
	 * @param array<int, array>  $candidates Candidates, for the mapping pass.
	 * @param array<string, int> $budget     Budget.
	 * @param string             $project_id Project identifier.
	 * @param float              $started    Start timestamp.
	 * @param string             $viewport   Viewport name.
	 * @return array<string, mixed>
	 */
	private function finish( Interaction_Model $model, array $candidates, array $budget, $project_id, $started, $viewport ) {
		/*
		 * Read from the model rather than passed down. The page id is a property of the
		 * model — it is what the model is *about* — and threading it through a second
		 * parameter to reach a log line and an action hook is how those two end up
		 * disagreeing with the report they are describing.
		 */
		$page_id = $this->page_id_of( $model );
		$mapped     = $this->mapper->map_model( $model );
		$validation = $this->validator->validate( $model );

		$report = $model->to_array();
		$report['mapping']       = $mapped;
		$report['validation']    = $validation;
		$report['driver']        = $this->driver_report();
		$report['budget']        = $budget;
		$report['budget_used']   = $this->usage( $model, $started );
		$report['mapping_counts'] = $this->mapper->registry()['counts'];
		$report['unsupported']   = array_values( array_filter( $mapped, static function ( $row ) {
			return in_array( (string) $row['outcome'], array( 'unsupported', 'requires_review' ), true );
		} ) );

		$this->logger->info( 'interaction_analysis_completed', 'Interaction analysis finished.', array(
			'page_id'   => $page_id,
			'viewport'  => $viewport,
			'status'    => $model->status(),
			'count'     => count( $model->interactions() ),
			'complete'  => $model->is_complete(),
		), 'interaction' );

		/**
		 * Fires once a page's interaction model is built.
		 *
		 * @param array<string, mixed> $report Report.
		 * @param string               $page_id Page identifier.
		 */
		do_action( 'replicaforge_interaction_analysis_complete', $report, $page_id );

		return $report;
	}

	/**
	 * Build the state machine for one detected candidate.
	 *
	 * This is where the type becomes a machine, and the decision of what shape the machine
	 * takes is per-type rather than generic — because "a menu and an accordion both have
	 * an expanded state" does not mean they have the same transitions.
	 *
	 * @param array<string, mixed> $candidate Detected candidate.
	 * @param array<string, mixed> $context   Phase 2 context.
	 * @param string               $viewport  Viewport name.
	 * @return array<string, mixed>
	 */
	private function machine_for( array $candidate, array $context, $viewport ) {
		$type    = (string) $candidate['type'];
		$element = (string) $candidate['source_element'];
		$machine = State_Machine::make( (string) $candidate['component_id'], $type, (string) $candidate['role'] );

		$machine['evidence_source'] = (string) $candidate['evidence_source'];
		$machine['evidence']        = (array) $candidate['evidence'];

		$open    = $this->open_trigger( $candidate );
		$initial = (string) $machine['initial_state'];
		$open_to = $this->open_state( $type );
		$close   = $this->close_trigger( $candidate );

		$attributes = array(
			'viewport'   => $viewport,
			'confidence' => (float) $candidate['confidence'],
			'evidence'   => (array) $candidate['evidence'],
			'transition' => $this->transition_for( $candidate ),
			'duration'   => $this->duration_for( $candidate ),
		);

		// A type whose machine is genuinely cyclic gets the cycle; one that is genuinely
		// one-way gets one transition. Guessing "one transition" for a menu is how a
		// replica gets a menu that opens once and never closes.
		if ( $this->is_toggle( $type, $candidate ) ) {
			$machine = State_Machine::add_cycle( $machine, $open, $close, $initial, $open_to, $element, $attributes );
		} else {
			$machine = State_Machine::add_transition( $machine, $open, $initial, $open_to, $element, $attributes );
		}

		$machine['by_viewport'][ $viewport ] = array(
			'trigger' => $open,
			'outcome' => $type,
			'initial' => $initial,
		);

		return $machine;
	}

	/**
	 * Return whether a candidate's machine is a toggle.
	 *
	 * @param string               $type      Interaction type.
	 * @param array<string, mixed> $candidate Candidate.
	 * @return bool
	 */
	private function is_toggle( $type, $candidate ) {
		// A tab set is not a toggle: selecting a different tab is a transition between
		// siblings, not a return to the initial state. Modelling it as a cycle would make
		// the reconstructed tab set impossible to satisfy.
		if ( 'tabs' === $type ) {
			return false;
		}
		// A form control's interaction is a submit, not a toggle.
		if ( Interaction_Limits::is_side_effect_trigger( (string) $candidate['trigger'] ) ) {
			return false;
		}

		return in_array( $type, array(
			'accordion',
			'expand_collapse',
			'modal',
			'popup',
			'dropdown',
			'mega_menu',
			'mobile_menu',
			'search_overlay',
			'lightbox',
			'tooltips',
			'tooltip',
			'popover',
			'filter',
		), true );
	}

	/**
	 * Return the open trigger for a candidate.
	 *
	 * @param array<string, mixed> $candidate Candidate.
	 * @return string
	 */
	private function open_trigger( array $candidate ) {
		$trigger = (string) $candidate['trigger'];
		if ( Interaction_Limits::is_trigger( $trigger ) && ! Interaction_Limits::is_side_effect_trigger( $trigger ) ) {
			return $trigger;
		}

		return 'click';
	}

	/**
	 * Return the close trigger for a candidate.
	 *
	 * @param array<string, mixed> $candidate Candidate.
	 * @return string
	 */
	private function close_trigger( array $candidate ) {
		return 'click';
	}

	/**
	 * Return the open state for a type.
	 *
	 * @param string $type Interaction type.
	 * @return string
	 */
	private function open_state( $type ) {
		$states = array(
			'accordion'       => 'expanded',
			'expand_collapse' => 'expanded',
			'modal'           => 'open',
			'popup'           => 'open',
			'dropdown'        => 'open',
			'mega_menu'       => 'open',
			'search_overlay'  => 'open',
			'lightbox'        => 'open',
			'tooltip'         => 'shown',
			'popover'         => 'open',
			'mobile_menu'     => 'visible',
			'tabs'            => 'tab_selected',
			'filter'          => 'filtered',
			'sort'            => 'sorted',
			'navigation'      => 'open',
			'carousel'        => 'next_slide',
		);

		$type = (string) $type;

		return $states[ $type ] ?? 'active';
	}

	/**
	 * Return the transition style for a candidate.
	 *
	 * Read from inline style where it says so, and otherwise `instant` — not a guess at
	 * what the animation probably is. A replica that animates when the source does not is
	 * a visible difference; a replica that does not animate when the source does is the
	 * lesser of the two, and it is the honest one.
	 *
	 * @param array<string, mixed> $candidate Candidate.
	 * @return string
	 */
	private function transition_for( array $candidate ) {
		$style = strtolower( (string) ( $candidate['attributes']['class'] ?? '' ) );
		if ( preg_match( '/\bfade\b/', $style ) ) {
			return 'fade';
		}
		if ( preg_match( '/\b(slide|drawer|offcanvas)\b/', $style ) ) {
			return 'slide';
		}
		if ( in_array( (string) $candidate['type'], array( 'accordion', 'expand_collapse' ), true ) ) {
			return 'expand';
		}

		return 'instant';
	}

	/**
	 * Return the transition duration for a candidate.
	 *
	 * Zero unless the markup states one. A CSS `transition-duration` lives in a
	 * stylesheet, and reading stylesheets to find it means a CSS parser; guessing 300ms
	 * would be inventing a number the specification explicitly forbids inventing.
	 *
	 * @param array<string, mixed> $candidate Candidate.
	 * @return int
	 */
	private function duration_for( array $candidate ) {
		$inline = (string) ( $candidate['attributes']['style'] ?? '' );
		if ( preg_match( '/transition(?:-duration)?\s*:\s*([0-9.]+)\s*m?s/i', $inline, $match ) ) {
			$seconds = (float) $match[1];

			return (int) round( 'ms' === strtolower( substr( $match[0], -1 ) ) ? $seconds : $seconds * 1000 );
		}

		return 0;
	}

	/* ---------------------------------------------------------------------
	 * Forms
	 * ------------------------------------------------------------------ */

	/**
	 * Analyse the forms on a page, without submitting any of them.
	 *
	 * The whole point of this method is what it does *not* do. It reads `action`,
	 * `method` and the field list, and it never contacts the action, never fills a field
	 * and never triggers validation on the page. §26 and §51 both require that, and
	 * "we looked but did not submit" is a materially different claim from "we tested the
	 * form", so the report says which one happened.
	 *
	 * @param array<string, mixed> $context Phase 2 context.
	 * @param string               $page_id Page identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function analyze_forms( array $context, $page_id = '' ) {
		$nodes = (array) ( $context['nodes'] ?? array() );
		$forms = array();

		foreach ( $nodes as $node_id => $node ) {
			if ( 'form' !== strtolower( (string) ( $node['tag'] ?? '' ) ) ) {
				continue;
			}

			$fields = $this->form_fields( $node, $nodes );
			if ( array() === $fields ) {
				continue;
			}

			$type = $this->classify_form( $node, $fields );
			$action = (string) ( $node['attributes']['action'] ?? '' );

			$forms[] = array(
				'form_id'      => (string) ( $node['attributes']['id'] ?? $node_id ),
				'page_id'      => (string) $page_id,
				'type'         => $type,
				'method'       => strtolower( (string) ( $node['attributes']['method'] ?? 'get' ) ),
				// The action is recorded, never called. It is also normalised to a
				// relative-or-absolute string, and a non-http action (`javascript:`,
				// `mailto:`) is dropped rather than stored.
				'action'       => $this->safe_action( $action ),
				'field_count'  => count( $fields ),
				'fields'       => $fields,
				'required'     => count( array_filter( $fields, static function ( $field ) { return ! empty( $field['required'] ); } ) ),
				'reproducible' => ! in_array( $type, Interaction_Limits::NON_REPRODUCIBLE_FORMS, true ),
				'submitted'    => false,
				'note'         => in_array( $type, Interaction_Limits::NON_REPRODUCIBLE_FORMS, true )
					? 'This form is identified but not reproduced, because it interacts with an account or payment system the replica does not own.'
					: 'The form is described. It was not submitted, and no field was filled.',
			);
		}

		return $forms;
	}

	/**
	 * Return the field list for a form.
	 *
	 * @param array<string, mixed> $form  Form node.
	 * @param array<string, mixed> $nodes Node table.
	 * @return array<int, array<string, mixed>>
	 */
	private function form_fields( array $form, array $nodes ) {
		$fields = array();

		foreach ( (array) ( $form['children'] ?? array() ) as $child_id ) {
			$node = $nodes[ $child_id ] ?? null;
			if ( ! is_array( $node ) ) {
				continue;
			}

			$tag = strtolower( (string) $node['tag'] );
			if ( ! in_array( $tag, array( 'input', 'select', 'textarea' ), true ) ) {
				// A field nested one level deeper — a common pattern with a wrapper div —
				// is still the form's field, so the walk continues rather than stopping.
				$fields = array_merge( $fields, $this->form_fields( $node, $nodes ) );
				continue;
			}

			$type = strtolower( (string) ( $node['attributes']['type'] ?? ( 'select' === $tag ? 'select' : 'text' ) ) );

			// Submit and reset controls are not fields a person fills in, so they are
			// recorded as a count rather than listed. Listing them would make a "12 fields"
			// form look like it asks for twelve things.
			if ( in_array( $type, array( 'submit', 'reset', 'button', 'image' ), true ) ) {
				continue;
			}

			$fields[] = array(
				'name'        => (string) ( $node['attributes']['name'] ?? '' ),
				'type'        => $type,
				'required'    => isset( $node['attributes']['required'] ),
				'placeholder' => (string) ( $node['attributes']['placeholder'] ?? '' ),
				'label'       => $this->label_for( $node, $nodes ),
				// Deliberately absent: `value`. §50 forbids persisting form values, and an
				// input's value is the single most likely place one would end up.
			);
		}

		return $fields;
	}

	/**
	 * Return a field's label, when one is associated.
	 *
	 * @param array<string, mixed> $node  Field node.
	 * @param array<string, mixed> $nodes Node table.
	 * @return string
	 */
	private function label_for( array $node, array $nodes ) {
		foreach ( (array) ( $node['attributes'] ?? array() ) as $attribute => $value ) {
			if ( 'aria-label' === $attribute && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		$name = (string) ( $node['attributes']['name'] ?? '' );
		if ( '' === $name ) {
			return '';
		}

		foreach ( $nodes as $candidate ) {
			if ( 'label' === strtolower( (string) ( $candidate['tag'] ?? '' ) ) ) {
				foreach ( (array) ( $candidate['attributes'] ?? array() ) as $attribute => $value ) {
					if ( 'for' === $attribute && (string) $value === $name ) {
						return (string) ( $candidate['label_text'] ?? '' );
					}
				}
			}
		}

		return '';
	}

	/**
	 * Classify a form.
	 *
	 * Ordered most-specific first, because a newsletter signup usually *has* an email field
	 * and a checkout usually does too, so the field list alone cannot separate them.
	 *
	 * @param array<string, mixed> $form   Form node.
	 * @param array<int, array>    $fields Fields.
	 * @return string
	 */
	private function classify_form( array $form, array $fields ) {
		$hay = strtolower( implode( ' ', array_merge(
			array(
				(string) ( $form['attributes']['id'] ?? '' ),
				(string) ( $form['attributes']['class'] ?? '' ),
				(string) ( $form['attributes']['aria-label'] ?? '' ),
				(string) ( $form['attributes']['action'] ?? '' ),
				(string) ( $form['attributes']['name'] ?? '' ),
			),
			array_map(
				static function ( $field ) {
					return (string) $field['name'] . ' ' . (string) $field['placeholder'] . ' ' . (string) $field['label'];
				},
				$fields
			)
		) ) );

		$names = array();
		foreach ( $fields as $field ) {
			$names[] = (string) $field['name'];
		}
		$has_password = false;
		$has_card     = false;
		foreach ( $fields as $field ) {
			if ( 'password' === $field['type'] ) {
				$has_password = true;
			}
			if ( in_array( (string) $field['type'], array( 'text', 'tel', 'number' ), true )
				&& (int) ( $field['name'] ?? '' ) === 0
				&& (bool) preg_match( '/(card|cvv|cvc|expiry|number)/', (string) $field['name'] . ' ' . (string) $field['placeholder'] ) ) {
				$has_card = true;
			}
		}

		if ( $has_card || preg_match( '/\b(checkout|cart|billing|order|payment)\b/', $hay ) ) {
			return 'checkout';
		}
		if ( $has_password || preg_match( '/\b(login|signin|sign-in|log-in|account)\b/', $hay ) ) {
			return in_array( $has_password && preg_match( '/(register|signup|sign-up|create.account)/', $hay ), array( true ), true )
				? 'registration'
				: 'login';
		}
		if ( preg_match( '/(register|signup|sign-up|create.account|join)/', $hay ) ) {
			return 'registration';
		}
		if ( preg_match( '/\b(search|query|s|q)\b/', $hay ) && 1 === count( $fields ) ) {
			return 'search';
		}
		if ( preg_match( '/(newsletter|subscribe|signup|mailing|mailing-list)/', $hay ) ) {
			return 'newsletter';
		}
		if ( preg_match( '/(contact|message|enquir|enquiry|get-in-touch|support)/', $hay ) ) {
			return 'contact';
		}
		if ( preg_match( '/(lead|quote|estimate|book|demo|request)/', $hay ) ) {
			return 'lead_generation';
		}

		return 'unknown';
	}

	/**
	 * Return a form action only when it is a real http(s) address.
	 *
	 * @param string $action Raw action.
	 * @return string
	 */
	private function safe_action( $action ) {
		$action = trim( (string) $action );
		if ( '' === $action ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $action ) && 0 !== strpos( $action, '/' ) ) {
			// `javascript:`, `mailto:` and `data:` are all refused. A replica that
			// reproduced a `javascript:` action would be executing source script on the
			// destination, which is the one thing §1 forbids outright.
			return '';
		}

		return esc_url_raw( $action );
	}

	/* ---------------------------------------------------------------------
	 * Observation
	 * ------------------------------------------------------------------ */

	/**
	 * Run one observation session.
	 *
	 * @param string                        $source_url Source URL.
	 * @param array<int, array>             $candidates Detected candidates.
	 * @param array<string, int>            $budget     Budget.
	 * @param string                        $project_id Project identifier.
	 * @return array<string, mixed>
	 */
	public function observe( $source_url, array $candidates, array $budget, $project_id = '' ) {
		/*
		 * The URL is validated *first*, before the driver is asked whether it exists.
		 *
		 * The reverse order was a real defect. With no driver configured, a cloud-metadata
		 * address came back as BROWSER_UNAVAILABLE — true, and useless, because it
		 * described the driver's absence rather than the fact that the address was never
		 * safe to request. The two facts have different remedies (configure a driver,
		 * versus stop asking for that URL), and a caller reading one instead of the other
		 * acts on the wrong one. `analyze()` validates for the same reason before it gets
		 * here; doing it again here means the method is safe to call directly too, which
		 * is the property a test relies on.
		 */
		$verdict = $this->urls->validate( $source_url );
		if ( empty( $verdict['success'] ) ) {
			// `PRIVATE_PAGE` for a page that exists but is not for us, `SOURCE_BLOCKED` for
			// an address the validator refuses. Different facts, different remedies.
			$code   = (string) ( $verdict['error']['code'] ?? '' );
			$status = in_array( $code, array( 'forbidden_target', 'credentials_not_allowed' ), true ) ? 'PRIVATE_PAGE' : 'SOURCE_BLOCKED';

			Security::log_event( 'interaction_observation_blocked', array( 'reason' => $code ) );

			return array(
				'status'       => $status,
				'reason'       => sprintf( 'The URL was refused (%s): %s', '' !== $code ? $code : 'unknown', (string) ( $verdict['error']['message'] ?? 'the address is not analysable.' ) ),
				'observations' => array(),
			);
		}

		$driver = $this->driver_report();

		if ( ! $driver['available'] ) {
			return array( 'status' => 'BROWSER_UNAVAILABLE', 'reason' => (string) $driver['reason'], 'observations' => array() );
		}

		// Only types on the allowlist are offered to the driver, and only those the page
		// actually has. The intersection is computed here rather than trusted from the
		// detector, because the driver is the thing that acts.
		$requested = array();
		foreach ( $candidates as $candidate ) {
			$type = (string) $candidate['type'];
			if ( ! Interaction_Limits::is_observable( $type ) ) {
				continue;
			}
			if ( Interaction_Limits::is_side_effect_trigger( (string) $candidate['trigger'] ) ) {
				continue;
			}
			$requested[ $type ] = isset( $requested[ $type ] ) ? $requested[ $type ] + 1 : 1;
		}

		if ( array() === $requested ) {
			return array(
				'status'       => 'INTERACTION_ANALYSIS_COMPLETE',
				'reason'       => 'No interaction on this page is safe to trigger, so the page was observed without interaction.',
				'observations' => array(),
				'skipped'      => count( $candidates ),
			);
		}

		$cache_key = $this->observation_cache_key( $project_id, $source_url );

		$result = $this->driver->observe( array(
			'url'               => (string) $verdict['url'],
			'budget'            => $budget,
			'interaction_types' => array_keys( $requested ),
			// Read, so a site can extend the budget without editing the plugin. Clamped
			// regardless, so a filter cannot raise a ceiling.
			'limits'            => apply_filters( 'replicaforge_interaction_budget', $budget, $source_url ),
		) );

		if ( ! is_array( $result ) || empty( $result['success'] ) ) {
			$code = (string) ( $result['error']['code'] ?? 'unknown' );
			$status = in_array( $code, array( 'timeout', 'browser_timeout' ), true ) ? 'BROWSER_TIMEOUT' : 'RENDER_FAILED';

			return array(
				'status'       => $status,
				'reason'       => (string) ( $result['error']['message'] ?? 'The browser session did not complete.' ),
				'observations' => array(),
			);
		}

		$observations = $this->record_observations( (array) ( $result['observations'] ?? array() ), array_keys( $requested ), $budget );

		$status = 'INTERACTION_ANALYSIS_COMPLETE';
		if ( ! empty( $result['limit_reached'] ) ) {
			$status = 'INTERACTION_LIMIT_REACHED';
		}

		if ( $this->cache instanceof Render_Cache && ! empty( $observations ) ) {
			$this->cache->put( $cache_key, array( 'observations' => $observations, 'status' => $status ), 'analysis', 604800, $project_id );
		}

		return array(
			'status'       => $status,
			'reason'       => '',
			'observations' => $observations,
			'usage'        => (array) ( $result['usage'] ?? array() ),
			'cache_key'    => $cache_key,
		);
	}

	/**
	 * Record and screen a driver's observations.
	 *
	 * Every field is checked against {@see Interaction_Limits::FORBIDDEN_OBSERVATION_FIELDS}
	 * before it is kept, so a driver that returns a cookie jar has it dropped rather than
	 * stored. It is also checked that the observation is of a type we asked for, because a
	 * driver that observed something else is either broken or hostile and both cases are
	 * worth discarding.
	 *
	 * @param array<int, array> $raw      Raw observations.
	 * @param array<int, string> $requested Requested types.
	 * @param array<string, int> $budget   Budget.
	 * @return array<int, array<string, mixed>>
	 */
	private function record_observations( array $raw, array $requested, array $budget ) {
		$kept     = array();
		$rejected = 0;
		$seq      = 0;

		foreach ( $raw as $observation ) {
			if ( ! is_array( $observation ) ) {
				continue;
			}
			if ( count( $kept ) >= $budget['max_states'] ) {
				break;
			}

			$clean = array();
			foreach ( $observation as $field => $value ) {
				if ( ! Interaction_Limits::is_recordable_field( (string) $field ) ) {
					// Dropped silently but counted, and the count is reported, because a
					// driver that routinely returns forbidden fields is a fact the operator
					// needs rather than a detail to bury.
					$rejected++;
					continue;
				}
				$clean[ (string) $field ] = is_scalar( $value ) ? $value : Security::clean_text( (string) $value, 200 );
			}

			if ( ! isset( $clean['kind'] ) ) {
				$rejected++;
				continue;
			}

			$seq++;
			$clean['seq']              = $seq;
			$clean['at_ms']            = (int) ( $clean['at_ms'] ?? 0 );
			$clean['trigger']          = Interaction_Limits::is_trigger( (string) ( $clean['trigger'] ?? '' ) ) ? (string) $clean['trigger'] : '';
			$clean['element_id']       = Security::clean_text( (string) ( $clean['element_id'] ?? '' ), 100 );
			$clean['type']             = (string) ( $clean['type'] ?? '' );
			$kept[] = $clean;
		}

		$this->logger->info( 'interaction_observations_recorded', 'Recorded browser observations.', array(
			'count'    => count( $kept ),
			'rejected' => $rejected,
		), 'interaction' );

		return $kept;
	}

	/**
	 * Apply observations to a model, raising confidence where a transition was seen.
	 *
	 * @param Interaction_Model     $model     Model.
	 * @param array<string, mixed>  $observed  Observation result.
	 * @return void
	 */
	private function apply_observations( Interaction_Model $model, array $observed ) {
		$observations = (array) ( $observed['observations'] ?? array() );
		if ( array() === $observations ) {
			return;
		}

		$seen = array();
		foreach ( $observations as $observation ) {
			$element = (string) ( $observation['element_id'] ?? '' );
			if ( '' === $element ) {
				continue;
			}
			$changed = ! empty( $observation['dom_changed'] )
				|| ! empty( $observation['geometry_changed'] )
				|| ! empty( $observation['visibility_changed'] )
				|| ! empty( $observation['url_changed'] )
				|| ! empty( $observation['class_added'] )
				|| ! empty( $observation['class_removed'] );

			$seen[ $element ] = $seen[ $element ] ?? 0;
			if ( $changed ) {
				$seen[ $element ]++;
			}
		}

		/*
		 * A confirmed transition raises confidence on the matching machine rather than
		 * adding a second machine. Adding one would give the model two components for one
		 * element, and the reconstruction would then have to choose between them.
		 *
		 * `Interaction_Model` holds the machines, so the promotion is done by replacing
		 * the machine in place. The model's own accessors are used rather than reaching
		 * into it, so the two stay consistent.
		 */
		$machines = $model->machines();
		foreach ( $machines as $index => $machine ) {
			$element = (string) ( $machine['component_id'] ?? '' );
			if ( '' === $element || empty( $seen[ $element ] ) ) {
				continue;
			}

			$transitions = State_Machine::transitions( $machine );
			foreach ( $transitions as $t => $transition ) {
				$transitions[ $t ]['evidence'][] = 'state change observed in the browser';
				$transitions[ $t ]['confidence'] = Interaction_Limits::clamp_confidence( (float) $transition['confidence'] + 0.15 );
			}

			$machines[ $index ]['transitions']    = $transitions;
			$machines[ $index ]['evidence_source'] = 'observed';
			$machines[ $index ]['evidence'][]      = 'a browser session confirmed a state change on this element';
			$machines[ $index ]['confidence']      = max(
				(float) $machine['confidence'],
				Interaction_Limits::confidence_floor( 'observed' )
			);
		}

		$this->replace_machines( $model, $machines );
	}

	/**
	 * Replace a model's machines.
	 *
	 * The model exposes `add_machine()` for building and this for updating, because
	 * promotion-after-observation is the one legitimate case where a machine has to be
	 * rewritten after it was accepted. A `replace_machines()` that re-validates would
	 * defeat the purpose — the machines were already valid, and re-validating here would
	 * duplicate the gate for no gain.
	 *
	 * @param Interaction_Model       $model    Model.
	 * @param array<int, array>       $machines Machines.
	 * @return void
	 */
	private function replace_machines( Interaction_Model $model, array $machines ) {
		$model->replace_machines( $machines );
	}

	/**
	 * Return the observation cache key.
	 *
	 * §52 names the fields. The driver id *and* version are included because two drivers
	 * genuinely see different things, and two versions of one driver may too — a cache
	 * that ignored the version would serve observations taken by a different browser.
	 *
	 * @param string $project_id Project identifier.
	 * @param string $source_url Source URL.
	 * @return string
	 */
	public function observation_cache_key( $project_id, $source_url ) {
		$material = array(
			'project'    => (string) $project_id,
			'source'     => (string) $source_url,
			'engine'     => Interaction_Limits::ENGINE_VERSION,
			'schema'     => Interaction_Limits::SCHEMA_VERSION,
			'driver'     => (string) ( $this->driver_report()['id'] ?? 'none' ),
			'driver_ver' => (string) ( $this->driver_report()['version'] ?? '' ),
		);

		return 'rfi_' . substr( hash( 'sha256', (string) wp_json_encode( $material ) ), 0, 32 );
	}

	/* ---------------------------------------------------------------------
	 * Responsive and budgets
	 * ------------------------------------------------------------------ */

	/**
	 * Return a model's page identifier.
	 *
	 * Read from the model rather than passed down the call chain. The page id is a
	 * property of the model - it is what the model is *about* - so threading it
	 * through a second parameter to reach a log line and an action hook is how those
	 * two end up disagreeing with the report they are describing. That happened:
	 * the extracted `finish()` method logged and announced an undefined `$page_id`.
	 *
	 * @param Interaction_Model $model Model.
	 * @return string
	 */
	private function page_id_of( Interaction_Model $model ) {
		$as_array = $model->to_array();

		return (string) ( $as_array['page_id'] ?? '' );
	}

	/**
	 * Record this viewport's behaviour and note where viewports disagree.
	 *
	 * §33 asks for viewport-specific behaviour and for the *differences* to be visible.
	 * The difference is the interesting half: a navigation that is a hover dropdown on
	 * desktop and a drawer on mobile is one component with two paths, and a consumer
	 * needs to see that or it will build the desktop behaviour everywhere.
	 *
	 * @param Interaction_Model $model    Model.
	 * @param string            $viewport Viewport name.
	 * @return void
	 */
	private function apply_responsive( Interaction_Model $model, $viewport ) {
		$by_type = array();
		foreach ( $model->interactions() as $interaction ) {
			$component = (string) $interaction['component_id'];
			$by_type[ $component ][ $interaction['type'] ] = (string) $interaction['trigger'];
		}

		$model->set_viewport( $viewport, array(
			'interactions' => count( $model->interactions() ),
			'components'   => count( $by_type ),
			'triggers'     => $model->triggers(),
		) );

		$responsive = $model->responsive();
		if ( count( $responsive ) < 2 ) {
			return;
		}

		/*
		 * With one viewport analysed there is nothing to compare against, and a model
		 * claiming a responsive difference it has not observed would be exactly the kind
		 * of invention the specification forbids. So the difference is only reported once
		 * a second viewport exists, and the caller supplies the others.
		 */
		$model->note_limitation( sprintf(
			'Behaviour was analysed at %d viewport(s). A responsive difference can only be confirmed once every declared viewport has been analysed.',
			count( $responsive )
		) );
	}

	/**
	 * Return the clamped budget for a run.
	 *
	 * @param array<string, mixed> $requested Requested budget.
	 * @return array<string, int>
	 */
	public function budget( array $requested = array() ) {
		$budget = array();
		foreach ( Interaction_Limits::budgets() as $name => $ceiling ) {
			$budget[ $name ] = Interaction_Limits::budget( $name, isset( $requested[ $name ] ) ? (int) $requested[ $name ] : 0 );
		}

		return $budget;
	}

	/**
	 * Return what the run used, against the budget.
	 *
	 * @param Interaction_Model $model   Model.
	 * @param float             $started Start timestamp.
	 * @return array<string, mixed>
	 */
	private function usage( Interaction_Model $model, $started ) {
		return array(
			'interactions' => count( $model->interactions() ),
			'states'       => count( $model->states() ),
			'screenshots'  => 0,
			'elapsed_ms'   => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'browser_used' => (bool) $this->driver_report()['available'],
		);
	}
}
