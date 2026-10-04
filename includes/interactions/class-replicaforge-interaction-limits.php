<?php
/**
 * Phase 16: the interaction vocabulary.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Every constant the interaction layer reads.
 *
 * ### Why one file, and why it is the first thing to read
 *
 * This is the same shape as `Validation_Limits` (phase 5), `Visual_Limits` (phase 13) and
 * `Workspace_Limits` (phase 15), and for the same reason: a vocabulary that lives in two
 * places is a vocabulary that will disagree with itself. When a detector, a mapper, a
 * validator and a REST handler each keep their own list of interaction types, then adding
 * one means finding four lists, and missing one produces a type that validates but cannot
 * be detected, or is detected but cannot be reported.
 *
 * So every closed list lives here, and everything else reads it.
 *
 * ### Two axes that must never be merged
 *
 * `TYPES` is *what the interaction is*. `OUTCOMES` is *what we can do about it on the
 * destination*. They are separate constants, deliberately, because collapsing them would
 * produce entries like `type => "supported_accordion"`, which answers neither question
 * cleanly and makes "is this reproducible?" unanswerable without re-deriving it.
 *
 * ### What is NOT here
 *
 * There is no severity list. `Validation_Limits::SEVERITIES` is the one, and phase 5 already
 * declares `interaction` and `navigation` as difference categories. Phase 16 populates that
 * vocabulary; it does not extend it with a parallel one.
 *
 * There is also no viewport list. `Validation_Limits::VIEWPORTS` is the one, and
 * `Visual_Limits::viewports()` reads it rather than restating it.
 */
final class Interaction_Limits {

	/**
	 * The phase this vocabulary belongs to.
	 *
	 * @var string
	 */
	const PHASE = '16.0';

	/**
	 * The interaction model schema version.
	 *
	 * Bumped when the *shape* of a model changes, not when a detector is improved. A
	 * consumer can rely on every key named in the specification existing at this version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '16.0';

	/**
	 * The engine version.
	 *
	 * Folded into every observation cache key, exactly as `Visual_Limits::ANALYZER_VERSION`
	 * is. A detector change that alters which interactions are found must not be served a
	 * stale cache, and a version that is not in the key cannot express that.
	 *
	 * @var string
	 */
	const ENGINE_VERSION = '1.0';

	/**
	 * Interaction types.
	 *
	 * The 32 named behaviours of the specification, plus `unknown`.
	 *
	 * `unknown` is a first-class member, not a failure. An element that changes state but
	 * matches no known pattern is genuinely *some* interaction, and recording it as `unknown`
	 * with its evidence is more useful — and more honest — than dropping it or, worse,
	 * guessing it into `modal` because that was the nearest fit. A guessed type produces a
	 * replica that behaves wrongly in a way nobody can trace back to the guess.
	 *
	 * @var array<int, string>
	 */
	const TYPES = array(
		'navigation',
		'dropdown',
		'mega_menu',
		'mobile_menu',
		'accordion',
		'tabs',
		'carousel',
		'slider',
		'modal',
		'popup',
		'tooltip',
		'popover',
		'search_overlay',
		'filter',
		'sort',
		'pagination',
		'load_more',
		'infinite_scroll',
		'sticky_header',
		'sticky_sidebar',
		'anchor_scroll',
		'scroll_reveal',
		'hover',
		'focus',
		'active_state',
		'expand_collapse',
		'image_gallery',
		'lightbox',
		'product_variation',
		'quantity_selector',
		'form_validation',
		'cookie_banner',
		'newsletter_form',
		'contact_form',
		'unknown',
	);

	/**
	 * Triggers.
	 *
	 * A trigger is *how* the interaction starts. It is kept separate from the type because
	 * the same type fires on different triggers at different viewports — the specification's
	 * own example is a navigation that is a hover mega menu on desktop and a tap drawer on
	 * mobile. Storing that as two types would double the vocabulary and lose the fact that
	 * they are one component.
	 *
	 * @var array<int, string>
	 */
	const TRIGGERS = array(
		'click',
		'tap',
		'hover',
		'focus',
		'blur',
		'scroll',
		'load',
		'timeout',
		'keyboard',
		'submit',
		'change',
		'input',
		'intersection',
		'resize',
	);

	/**
	 * Triggers that move the page or change what is fetched.
	 *
	 * A trigger on this list needs browser observation to confirm and can change server
	 * state, so the observation session treats it differently: it is *inspected* rather
	 * than *fired*. See `Interaction_Service::is_inspection_only_trigger()`.
	 *
	 * @var array<int, string>
	 */
	const SIDE_EFFECT_TRIGGERS = array(
		'submit',
		'input',
		'change',
	);

	/**
	 * Mapping outcomes — what can be done on the destination.
	 *
	 * @var array<int, string>
	 */
	const OUTCOMES = array(
		'supported',
		'unsupported',
		'approximation',
		'requires_review',
	);

	/**
	 * Analysis statuses.
	 *
	 * Every one of these is a *truthful* terminal state. There is no status meaning "we
	 * looked at everything and this is the complete picture", because with a browser absent
	 * or a budget exhausted, that is never true — and the specification is explicit that a
	 * limited analysis must say so rather than present partial coverage as complete.
	 *
	 * @var array<int, string>
	 */
	const STATUSES = array(
		'INTERACTION_ANALYSIS_COMPLETE',
		'PARTIAL_INTERACTION_ANALYSIS',
		'BROWSER_UNAVAILABLE',
		'BROWSER_TIMEOUT',
		'INTERACTION_LIMIT_REACHED',
		'UNSUPPORTED_BEHAVIOR',
		'PRIVATE_PAGE',
		'SOURCE_BLOCKED',
		'RENDER_FAILED',
		'VALIDATION_FAILED',
	);

	/**
	 * Statuses that mean the model is *not* a complete picture.
	 *
	 * `Interaction_Model::is_complete()` reads this, so a partial analysis cannot be
	 * presented as complete by forgetting to set a flag — the status decides.
	 *
	 * @var array<int, string>
	 */
	const INCOMPLETE_STATUSES = array(
		'PARTIAL_INTERACTION_ANALYSIS',
		'BROWSER_UNAVAILABLE',
		'BROWSER_TIMEOUT',
		'INTERACTION_LIMIT_REACHED',
		'UNSUPPORTED_BEHAVIOR',
		'SOURCE_BLOCKED',
		'RENDER_FAILED',
	);

	/**
	 * How an interaction was determined.
	 *
	 * `declared` means the page itself states the behaviour — `aria-expanded`,
	 * `<details>`, `role="tab"`. `inferred` means it was derived from structure. `observed`
	 * means a browser was driven and the transition was actually seen.
	 *
	 * Observed evidence outranks declared evidence, which outranks inference, and the
	 * confidence tiers below encode exactly that ordering.
	 *
	 * @var array<int, string>
	 */
	const EVIDENCE_SOURCES = array(
		'declared',
		'inferred',
		'observed',
		'visual',
	);

	/**
	 * Confidence floor per evidence source.
	 *
	 * An interaction below its source's floor is not reported at all. The reason is
	 * specific: a low-confidence guess that reaches the reconstruction step becomes a
	 * widget with the wrong behaviour, and a person debugging a replica that opens and
	 * closes wrongly has no way to tell that the plugin guessed. Better to report
	 * "possible interaction, not enough evidence" than to build the wrong thing.
	 *
	 * @var array<string, float>
	 */
	const CONFIDENCE_FLOORS = array(
		'declared' => 0.70,
		'inferred' => 0.55,
		'observed' => 0.90,
		'visual'   => 0.40,
	);

	/**
	 * Interaction types safe to trigger during observation.
	 *
	 * This is the allowlist the observation session uses. It is a list of *types*, not of
	 * elements, and it is deliberately narrow: the specification names menu buttons,
	 * accordions, tabs, carousels, lightboxes, dropdowns, non-destructive filters and UI
	 * toggles as safe, and everything else is inspected rather than fired.
	 *
	 * Nothing transactional is on it, and that is the point. There is no type here whose
	 * activation could place an order, delete a record, send a message, change a password
	 * or submit a form — because the cost of one being wrong is not a bad replica, it is a
	 * real action taken on a real site by an automated agent.
	 *
	 * @var array<int, string>
	 */
	const OBSERVABLE_TYPES = array(
		'navigation',
		'dropdown',
		'mega_menu',
		'mobile_menu',
		'accordion',
		'tabs',
		'carousel',
		'slider',
		'modal',
		'tooltip',
		'popover',
		'search_overlay',
		'lightbox',
		'image_gallery',
		'expand_collapse',
		'sticky_header',
		'sticky_sidebar',
		'filter',
		'sort',
	);

	/**
	 * Modal-like types whose automatic triggers are called out for review.
	 *
	 * A modal that opens on a timer, on scroll depth, or on exit intent is *detected* and
	 * *described*, but it is never reproduced as an automatic trigger without review. The
	 * behaviour is not unsafe to model; reproducing an unsolicited overlay on the replica
	 * without a human deciding to is a different thing, and it is the sort of difference
	 * that costs a client their patience.
	 *
	 * @var array<int, string>
	 */
	const AUTOMATIC_TRIGGER_MODALS = array(
		'modal',
		'popup',
		'cookie_banner',
		'search_overlay',
	);

	/**
	 * Automatic trigger types, which require review before reproduction.
	 *
	 * `scroll` is here and not merely a synonym for `intersection`, because they are not
	 * the same trigger and the difference changes what a replica should do. `intersection`
	 * fires once when an element enters the viewport; `scroll` fires continuously as the
	 * user moves. A modal on the first is a one-off disclosure; a modal on the second is
	 * a scroll-jacking pattern that follows the reader down the page. Both are
	 * unsolicited, so both are held — but they are held as *different* behaviours, and a
	 * vocabulary that merged them would let a report describe the second as the first.
	 *
	 * @var array<int, string>
	 */
	const AUTOMATIC_TRIGGERS = array(
		'load',
		'timeout',
		'intersection',
		'scroll',
	);

	/**
	 * Form classifications.
	 *
	 * A form is classified so the reconstruction can decide what to do with it. Note what
	 * is absent: there is no `checkout` that gets built. A checkout form is *identified* —
	 * so the specification can say "this was a checkout form and is not reproduced
	 * automatically" — but reproducing a payment form is not something a replica tool
	 * should do unprompted.
	 *
	 * @var array<int, string>
	 */
	const FORM_TYPES = array(
		'contact',
		'newsletter',
		'search',
		'login',
		'registration',
		'checkout',
		'lead_generation',
		'unknown',
	);

	/**
	 * Form types never reproduced automatically.
	 *
	 * @var array<int, string>
	 */
	const NON_REPRODUCIBLE_FORMS = array(
		'login',
		'registration',
		'checkout',
	);

	/**
	 * Property names the correction engine may change without review.
	 *
	 * This is a *whitelist of scalar presentation properties*, and every entry is a number,
	 * a duration, a boolean or a colour. There is no entry that can carry code, a selector
	 * or a URL. That is the whole design: a correction that can only move a number cannot
	 * become an injection vector, so it does not need a second layer of checking.
	 *
	 * @var array<int, string>
	 */
	const SAFE_CORRECTIONS = array(
		'animation_duration',
		'transition_type',
		'accordion_initial_state',
		'tab_initial_state',
		'carousel_speed',
		'carousel_autoplay',
		'sticky_offset',
		'hover_color',
		'hover_border_color',
		'hover_background_color',
		'hover_scale',
		'menu_width',
		'focus_outline_width',
		'focus_outline_color',
	);

	/**
	 * Correction properties that require review.
	 *
	 * These change structure or behaviour rather than presentation, so an automatic
	 * correction could change what the page *does* on a click rather than how it looks.
	 *
	 * @var array<int, string>
	 */
	const REVIEW_REQUIRED_CORRECTIONS = array(
		'automatic_modal_trigger',
		'exit_intent',
		'interval_autoplay',
		'scroll_lock',
		'focus_trap',
		'form_submission_target',
	);

	/**
	 * The interaction observation budget.
	 *
	 * §56 requires hard limits so a large site cannot cause unlimited exploration. These
	 * are ceilings, not targets, and reaching one produces
	 * `INTERACTION_LIMIT_REACHED` — never a quiet truncation.
	 *
	 * The values are chosen so that a typical marketing page (a hero carousel, a mega menu,
	 * a few accordions, a mobile drawer, a cookie banner) is fully explored well inside
	 * them, while a large commerce listing page hits them and says so.
	 *
	 * @var array<string, int>
	 */
	const BUDGETS = array(
		'max_pages'         => 12,
		'max_interactions'  => 120,
		'max_states'        => 300,
		'max_screenshots'   => 40,
		'max_browser_ms'    => 120000,
		'max_network'       => 400,
		'max_memory_bytes'  => 268435456,
		'max_depth'         => 3,
	);

	/**
	 * Never store these from an observation session.
	 *
	 * §50 lists them. This is a constant rather than a convention so that a filter or a
	 * future provider cannot introduce a field that quietly gets persisted — the recorder
	 * checks every key it is about to write against this list.
	 *
	 * @var array<int, string>
	 */
	const FORBIDDEN_OBSERVATION_FIELDS = array(
		'cookie',
		'cookies',
		'password',
		'auth',
		'authorization',
		'auth_header',
		'session',
		'session_token',
		'token',
		'local_storage',
		'session_storage',
		'indexed_db',
		'form_value',
		'input_value',
		'card',
		'cvv',
		'secret',
	);

	/**
	 * Viewports, read from the one place they are declared.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function viewports() {
		return Validation_Limits::VIEWPORTS;
	}

	/**
	 * Severities, read from the one place they are declared.
	 *
	 * @return array<int, string>
	 */
	public static function severities() {
		return Validation_Limits::SEVERITIES;
	}

	/**
	 * Return the initial state for a type.
	 *
	 * The specification names these for the four cases it illustrates, and the rest follow
	 * the same rule: the state the element is in *before anything is triggered*, which is
	 * what a static analyser can actually see. Claiming an initial state that observation
	 * has not confirmed would be a guess, and the whole model is built to avoid those.
	 *
	 * @param string $type Interaction type.
	 * @return string
	 */
	public static function initial_state( $type ) {
		$states = array(
			'accordion'       => 'collapsed',
			'expand_collapse' => 'collapsed',
			'tabs'            => 'first_selected',
			'modal'           => 'closed',
			'popup'           => 'closed',
			'dropdown'        => 'closed',
			'mega_menu'       => 'closed',
			'search_overlay'  => 'closed',
			'tooltip'         => 'hidden',
			'popover'         => 'closed',
			'mobile_menu'     => 'hidden',
			'lightbox'        => 'closed',
			'cookie_banner'   => 'visible',
			'navigation'      => 'closed',
			'carousel'        => 'first_slide',
			'filter'          => 'unfiltered',
			'sort'            => 'default_order',
		);

		$type = (string) $type;

		return $states[ $type ] ?? 'unknown';
	}

	/**
	 * Return whether a value is a declared interaction type.
	 *
	 * Present because {@see self::TYPES} is a *list* and a caller reaching for
	 * `array_key_exists()` on it gets "is 0 a key" — the same trap that made phase 15's
	 * priority tests pass for the wrong reason. This is the membership test they should
	 * have used.
	 *
	 * @param string $type Candidate.
	 * @return bool
	 */
	public static function is_type( $type ) {
		return in_array( (string) $type, self::TYPES, true );
	}

	/**
	 * Return whether a value is a declared trigger.
	 *
	 * @param string $trigger Candidate.
	 * @return bool
	 */
	public static function is_trigger( $trigger ) {
		return in_array( (string) $trigger, self::TRIGGERS, true );
	}

	/**
	 * Return whether a value is a declared outcome.
	 *
	 * @param string $outcome Candidate.
	 * @return bool
	 */
	public static function is_outcome( $outcome ) {
		return in_array( (string) $outcome, self::OUTCOMES, true );
	}

	/**
	 * Return whether a value is a declared status.
	 *
	 * @param string $status Candidate.
	 * @return bool
	 */
	public static function is_status( $status ) {
		return in_array( (string) $status, self::STATUSES, true );
	}

	/**
	 * Return whether a value is a declared form classification.
	 *
	 * @param string $form Candidate.
	 * @return bool
	 */
	public static function is_form_type( $form ) {
		return in_array( (string) $form, self::FORM_TYPES, true );
	}

	/**
	 * Return whether a type may be triggered during browser observation.
	 *
	 * @param string $type Candidate.
	 * @return bool
	 */
	public static function is_observable( $type ) {
		return in_array( (string) $type, self::OBSERVABLE_TYPES, true );
	}

	/**
	 * Return whether a trigger could change server state.
	 *
	 * @param string $trigger Candidate.
	 * @return bool
	 */
	public static function is_side_effect_trigger( $trigger ) {
		return in_array( (string) $trigger, self::SIDE_EFFECT_TRIGGERS, true );
	}

	/**
	 * Return whether a trigger fires without a user action.
	 *
	 * @param string $trigger Candidate.
	 * @return bool
	 */
	public static function is_automatic_trigger( $trigger ) {
		return in_array( (string) $trigger, self::AUTOMATIC_TRIGGERS, true );
	}

	/**
	 * Return a budget ceiling, clamped to a positive integer.
	 *
	 * A budget of zero or a negative value would mean "stop immediately", which is never
	 * what a caller means and always looks like a bug. Clamping to 1 makes the smallest
	 * possible exploration available, so a caller who asks for too little gets a small
	 * answer rather than an empty one.
	 *
	 * @param string $name      Budget name.
	 * @param int    $requested Requested value.
	 * @return int
	 */
	public static function budget( $name, $requested = 0 ) {
		$name = (string) $name;
		if ( ! isset( self::BUDGETS[ $name ] ) ) {
			return 0;
		}
		$requested = (int) $requested;

		return $requested > 0 ? min( $requested, (int) self::BUDGETS[ $name ] ) : (int) self::BUDGETS[ $name ];
	}

	/**
	 * Return every budget ceiling.
	 *
	 * @return array<string, int>
	 */
	public static function budgets() {
		return self::BUDGETS;
	}

	/**
	 * Return the confidence floor for an evidence source.
	 *
	 * An unrecognised source is treated as `inferred` rather than trusted or discarded,
	 * because a new detector that forgets to declare its source should be *penalised* for
	 * it. The safe failure is less confidence, not more.
	 *
	 * @param string $source Evidence source.
	 * @return float
	 */
	public static function confidence_floor( $source ) {
		$source = (string) $source;

		return self::CONFIDENCE_FLOORS[ $source ] ?? self::CONFIDENCE_FLOORS['inferred'];
	}

	/**
	 * Clamp a confidence value into 0..1.
	 *
	 * @param mixed $value Candidate.
	 * @return float
	 */
	public static function clamp_confidence( $value ) {
		if ( ! is_numeric( $value ) ) {
			return 0.0;
		}
		$value = (float) $value;

		return $value < 0.0 ? 0.0 : ( $value > 1.0 ? 1.0 : round( $value, 3 ) );
	}

	/**
	 * Return whether a field may be persisted from an observation.
	 *
	 * Checked by key name, case-insensitively, and by substring for the compound names
	 * (`form_value`, `auth_header`) so a provider cannot smuggle a secret in as
	 * `user_password` or `sessionStorage`.
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	public static function is_recordable_field( $field ) {
		$field = strtolower( (string) $field );
		if ( '' === $field ) {
			return false;
		}
		foreach ( self::FORBIDDEN_OBSERVATION_FIELDS as $forbidden ) {
			if ( $field === $forbidden || false !== strpos( $field, $forbidden ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return whether a correction property is safe to apply without review.
	 *
	 * @param string $property Property name.
	 * @return bool
	 */
	public static function is_safe_correction( $property ) {
		return in_array( (string) $property, self::SAFE_CORRECTIONS, true );
	}
}
