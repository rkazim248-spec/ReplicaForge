<?php
/**
 * Phase 16: the Elementor capability mapper.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Maps detected interactions onto what the destination can actually do.
 *
 * ### The question this answers
 *
 * Not "what does this interaction do" but "what can we honestly do about it *on this
 * install*". Those are different questions, and the second one has no single answer on
 * any site: Elementor 3.16 does not have the same widgets as 3.30, a site may have
 * Elementor Pro or not, and a site with no Elementor at all has none of it.
 *
 * So every mapping is resolved through {@see Elementor_Compatibility}, which reads the
 * *running* instance. A site without a widget gets `approximation` or `unsupported`, and
 * the mapping says so — rather than the plugin writing a widget name into a
 * specification and failing at save time with a message nobody can act on.
 *
 * ### The four outcomes
 *
 * - `supported` — the destination has a widget that does this.
 * - `approximation` — something close exists, and the difference is stated.
 * - `unsupported` — nothing safe exists. Per §38 the answer is a static fallback, never
 *   source JavaScript.
 * - `requires_review` — the source behaviour is either intrusive enough or ambiguous
 *   enough that a person should decide.
 *
 * ### What this class will not do
 *
 * It does not decide *content*, and it does not emit a widget tree. It returns a mapping
 * record; turning that into an Elementor document is the generator's job, through
 * `Elementor_Widget_Registry`, which owns the actual widget allowlist. Two classes
 * choosing widgets independently is how a mapping says "supported" and the builder
 * refuses the widget.
 */
final class Interaction_Mapper {

	/**
	 * The Elementor capability reader.
	 *
	 * @var Elementor_Compatibility
	 */
	private $elementor;

	/**
	 * Candidate Elementor widgets per interaction type, in preference order.
	 *
	 * Preferences rather than a single name, and the *order matters*: the first name that
	 * the running install actually has is used.
	 *
	 * The current-first ordering is not a style choice. Elementor 4 renamed its structural
	 * widgets — `accordion` became `nested-accordion`, `tabs` became `nested-tabs` — and a
	 * list written against 3.x names alone reports `approximation` for the two most
	 * common interactions on a modern install, when in fact the exact widget is sitting
	 * right there. That was found by running this against a real Elementor 4.3 rather than
	 * by reading the documentation, and it is exactly the failure the "resolve against
	 * the running instance" design exists to prevent.
	 *
	 * Legacy names are kept second so a 3.x install still gets a supported mapping rather
	 * than an approximation.
	 *
	 * @var array<string, array<int, string>>
	 */
	private $widget_candidates = array(
		'accordion'          => array( 'nested-accordion', 'accordion' ),
		'expand_collapse'    => array( 'nested-accordion', 'accordion' ),
		'tabs'               => array( 'nested-tabs', 'tabs' ),
		'carousel'           => array( 'image-carousel', 'testimonial-carousel' ),
		'slider'             => array( 'image-carousel', 'testimonial-carousel' ),
		'image_gallery'      => array( 'image-gallery' ),
		'lightbox'           => array( 'lightbox' ),
		'gallery'            => array( 'image-gallery' ),
		'modal'              => array( 'popup' ),
		'popup'              => array( 'popup' ),
		'tooltip'            => array( 'tooltip' ),
		'popover'            => array( 'popover' ),
		'search_overlay'     => array( 'form' ),
		'mega_menu'          => array( 'mega-menu' ),
		'dropdown'           => array( 'mega-menu' ),
		'mobile_menu'        => array( 'nav-menu' ),
		'navigation'         => array( 'nav-menu' ),
		'anchor_scroll'      => array( 'nav-menu' ),
		'quantity_selector'  => array( 'woocommerce-quantity-field' ),
		'product_variation'  => array( 'woocommerce-variation' ),
		'newsletter_form'    => array( 'form' ),
		'contact_form'       => array( 'form' ),
		'form_validation'    => array( 'form' ),
		'sticky_header'      => array( 'container' ),
		'sticky_sidebar'     => array( 'container' ),
		'scroll_reveal'      => array( 'container' ),
		'hover'              => array( 'button' ),
		'focus'              => array( 'button' ),
		'filter'             => array(),
		'sort'               => array(),
		'pagination'         => array(),
		'load_more'          => array(),
		'infinite_scroll'    => array(),
		'cookie_banner'      => array(),
		'active_state'       => array(),
		'unknown'            => array(),
	);

	/**
	 * Why an approximation is an approximation.
	 *
	 * Read by the generator when it writes a fallback, so the person looking at the
	 * replica learns *what* is different rather than just that something is.
	 *
	 * @var array<string, string>
	 */
	private $approximation_notes = array(
		'mega_menu'       => 'The source mega menu becomes a navigation menu. Multi-column panels and flyout columns are not reproduced.',
		'dropdown'        => 'The source dropdown becomes a navigation submenu. Hover-to-open and the source animation are not reproduced.',
		'carousel'        => 'The source carousel becomes an image carousel. Slide content comes from the reconstruction, not from the source script.',
		'modal'           => 'The source modal becomes a popup. The trigger is re-created; the source open timing is not.',
		'popup'           => 'The source popup becomes a popup. Triggers are reviewed rather than copied.',
		'tooltip'         => 'The source tooltip becomes a tooltip. Placement follows the destination default.',
		'search_overlay'  => 'The source search overlay becomes a search form. Suggestions and autocomplete are not reproduced.',
		'sticky_header'   => 'The source sticky header becomes a sticky container. Container-scoped stickiness may differ.',
		'sticky_sidebar'  => 'The source sticky sidebar becomes a sticky container. The unstick threshold is set by the destination.',
		'product_variation' => 'The source variation selector becomes a WooCommerce variation element. Custom swatch layouts are not reproduced.',
		'quantity_selector' => 'The source quantity control becomes the WooCommerce quantity field.',
	);

	/**
	 * Types that are deliberately never reproduced automatically.
	 *
	 * Each of these would either annoy the visitor of the replica or act on a system the
	 * replica does not own. The static fallback is stated with the mapping, so the
	 * limitation is visible rather than discovered.
	 *
	 * @var array<string, string>
	 */
	private $never_automatic = array(
		'cookie_banner' => 'Consent banners are site-wide policy decisions, not replica behaviour. The banner is described but not reproduced.',
		'load_more'     => 'Load-more needs a destination data source. The listing is reproduced with destination pagination instead.',
		'infinite_scroll' => 'Infinite scroll needs a destination data source. The listing is reproduced with destination pagination instead.',
		'filter'        => 'Filtering needs destination query support. On a WooCommerce site this maps to WooCommerce filters; elsewhere the UI is described but not built.',
		'sort'          => 'Sorting needs destination query support. On a WooCommerce site this maps to WooCommerce ordering; elsewhere the UI is described but not built.',
		'pagination'    => 'Pagination is provided by the destination theme or WooCommerce. The source pagination is described for comparison only.',
		'active_state'  => 'Active states are a style rule on another component, not a widget. They are carried in the specification as a style hint.',
		'unknown'       => 'The interaction could not be identified, so no destination behaviour is guessed. It is recorded for manual review.',
	);

	/**
	 * Constructor.
	 *
	 * @param Elementor_Compatibility|null $elementor Compatibility reader.
	 */
	public function __construct( $elementor = null ) {
		$this->elementor = $elementor instanceof Elementor_Compatibility ? $elementor : new Elementor_Compatibility();
	}

	/**
	 * Return the mapping for one interaction.
	 *
	 * @param array<string, mixed> $interaction Detected interaction.
	 * @return array<string, mixed>
	 */
	public function map( array $interaction ) {
		$type = (string) ( $interaction['type'] ?? 'unknown' );
		if ( ! Interaction_Limits::is_type( $type ) ) {
			$type = 'unknown';
		}

		$automatic = $this->is_automatic( $interaction );
		$outcome   = $this->outcome_for( $type, $automatic );
		$widget    = $this->resolve_widget( $type, $outcome );

		$record = array(
			'interaction_id' => (string) ( $interaction['interaction_id'] ?? '' ),
			'component_id'   => (string) ( $interaction['component_id'] ?? '' ),
			'type'           => $type,
			'outcome'        => $outcome,
			'widget'         => $widget,
			'fallback'       => $this->fallback_for( $type, $outcome ),
			'note'           => $this->note_for( $type, $outcome ),
			'automatic'      => $automatic,
			'requires_review' => in_array( $outcome, array( 'requires_review', 'unsupported' ), true ) || $automatic,
			'elementor'      => array(
				'available'     => $this->elementor->is_available(),
				'version'       => $this->elementor->version(),
				'has_widget'    => ( '' !== $widget ) ? $this->elementor->has_widget( $widget ) : false,
			),
		);

		return $record;
	}

	/**
	 * Map a whole model.
	 *
	 * @param Interaction_Model $model Model.
	 * @return array<int, array<string, mixed>>
	 */
	public function map_model( Interaction_Model $model ) {
		$mapped = array();
		foreach ( $model->interactions() as $interaction ) {
			$mapped[] = $this->map( $interaction );
		}

		return $mapped;
	}

	/**
	 * Return whether a trigger fires without a user action.
	 *
	 * §21 asks for automatic modals to be *marked for review* rather than reproduced, and
	 * the marker is exactly this. Note it covers the interaction's own trigger, not just
	 * its type: a `modal` triggered by `click` is ordinary, and the same widget triggered
	 * by `timeout` is not.
	 *
	 * @param array<string, mixed> $interaction Detected interaction.
	 * @return bool
	 */
	private function is_automatic( array $interaction ) {
		$trigger = (string) ( $interaction['trigger'] ?? '' );

		if ( ! Interaction_Limits::is_trigger( $trigger ) ) {
			return false;
		}
		if ( ! Interaction_Limits::is_automatic_trigger( $trigger ) ) {
			return false;
		}

		$type = (string) ( $interaction['type'] ?? '' );

		return in_array( $type, Interaction_Limits::AUTOMATIC_TRIGGER_MODALS, true )
			|| in_array( $type, array( 'popup', 'modal', 'cookie_banner' ), true );
	}

	/**
	 * Decide the outcome for a type.
	 *
	 * The order of these checks is the whole logic, and it is deliberate:
	 *
	 * 1. An automatic trigger on a modal-like type is `requires_review` — checked first,
	 *    because a widget that exists is irrelevant if the trigger is the problem.
	 * 2. A type on the never-automatic list is `unsupported` with a stated fallback.
	 * 3. Otherwise resolve a real widget, and downgrade if there is none.
	 *
	 * @param string $type      Interaction type.
	 * @param bool   $automatic Whether the trigger is automatic.
	 * @return string
	 */
	private function outcome_for( $type, $automatic ) {
		if ( $automatic ) {
			return 'requires_review';
		}

		if ( isset( $this->never_automatic[ $type ] ) ) {
			return 'unsupported';
		}

		$candidates = (array) ( $this->widget_candidates[ $type ] ?? array() );
		if ( array() === $candidates ) {
			return 'unsupported';
		}

		if ( ! $this->elementor->is_available() ) {
			// No Elementor at all. The reconstruction cannot run either, so this is
			// `unsupported` rather than `approximation` — there is no destination to
			// approximate towards, and reporting an approximation would imply one.
			return 'unsupported';
		}

		foreach ( $candidates as $widget ) {
			if ( $this->elementor->has_widget( $widget ) ) {
				return 'supported';
			}
		}

		return 'approximation';
	}

	/**
	 * Resolve the widget to use, preferring a real widget over the generic container.
	 *
	 * @param string $type    Interaction type.
	 * @param string $outcome Outcome.
	 * @return string Empty when nothing suitable exists.
	 */
	private function resolve_widget( $type, $outcome ) {
		if ( 'unsupported' === $outcome || 'requires_review' === $outcome ) {
			return '';
		}

		foreach ( (array) ( $this->widget_candidates[ $type ] ?? array() ) as $widget ) {
			if ( $this->elementor->has_widget( $widget ) ) {
				return $widget;
			}
		}

		/*
		 * An approximation is only meaningful if there is something to approximate
		 * *with*. A `container` is the nearest universally-available element, and
		 * Elementor's motion effects on it are the honest destination for a hover or
		 * sticky behaviour that has no dedicated widget. Returning a container is only
		 * correct when the install has containers; the guard is `supports_containers()`
		 * rather than a version check, because that is the actual capability.
		 */
		if ( $this->elementor->supports_containers() ) {
			return 'container';
		}

		return '';
	}

	/**
	 * Return the static fallback for a type that cannot be built.
	 *
	 * §38's three options are supported approximation, static fallback, and manual. This
	 * is the second and third, stated as data so the report and the generator read the
	 * same sentence.
	 *
	 * @param string $type    Interaction type.
	 * @param string $outcome Outcome.
	 * @return string
	 */
	private function fallback_for( $type, $outcome ) {
		if ( 'unsupported' === $outcome ) {
			/*
			 * Two different fallbacks, not one. A `static` fallback means there is a
			 * sensible inert thing to stand in — a cookie banner can be rendered as a
			 * non-functional notice, and the reader is better off for it. A `manual`
			 * fallback means there is not: an unidentified interaction rendered as
			 * *something* produces a replica that looks like it works and does not, which
			 * is worse than showing nothing at all. So a person decides.
			 */
			if ( 'unknown' === $type ) {
				return 'manual';
			}

			return isset( $this->never_automatic[ $type ] )
				? 'static'
				: 'manual';
		}
		if ( 'requires_review' === $outcome ) {
			return 'manual';
		}

		return '';
	}

	/**
	 * Return the note explaining the mapping.
	 *
	 * @param string $type    Interaction type.
	 * @param string $outcome Outcome.
	 * @return string
	 */
	private function note_for( $type, $outcome ) {
		if ( 'unsupported' === $outcome ) {
			return isset( $this->never_automatic[ $type ] )
				? $this->never_automatic[ $type ]
				: 'The installed Elementor has no widget for this behaviour, and ReplicaForge will not insert source script to replace it.';
		}
		if ( 'requires_review' === $outcome ) {
			return 'This behaviour triggers without a user action. ReplicaForge describes it but does not reproduce an unsolicited overlay without review.';
		}
		if ( 'approximation' === $outcome ) {
			return isset( $this->approximation_notes[ $type ] )
				? $this->approximation_notes[ $type ]
				: 'A supported behaviour is used in place of the source behaviour, with differences.';
		}

		return '';
	}

	/**
	 * Return the whole registry, for the capability endpoint and the admin screen.
	 *
	 * Every declared type appears, including the ones with no destination support, so a
	 * consumer can render the full table rather than only the rows that happen to work.
	 *
	 * @return array<string, mixed>
	 */
	public function registry() {
		$rows = array();

		foreach ( Interaction_Limits::TYPES as $type ) {
			// Probed with a representative non-automatic trigger, because the outcome
			// depends on the trigger and a table row needs one answer per type.
			$outcome = $this->outcome_for( $type, false );
			$widget  = $this->resolve_widget( $type, $outcome );

			$rows[ $type ] = array(
				'type'     => $type,
				'outcome'  => $outcome,
				'widget'   => $widget,
				'fallback' => $this->fallback_for( $type, $outcome ),
				'note'     => $this->note_for( $type, $outcome ),
				'candidates' => array_values( (array) ( $this->widget_candidates[ $type ] ?? array() ) ),
			);
		}

		return array(
			'schema_version' => Interaction_Limits::SCHEMA_VERSION,
			'elementor'      => array(
				'available'        => $this->elementor->is_available(),
				'version'          => $this->elementor->version(),
				'supports_containers' => $this->elementor->supports_containers(),
			),
			'rows'           => $rows,
			'counts'         => $this->counts( $rows ),
			'note'           => 'Resolved against the running Elementor installation. A site with different widgets gets a different table, which is why this is resolved rather than declared.',
		);
	}

	/**
	 * Count the registry outcomes.
	 *
	 * @param array<string, array<string, mixed>> $rows Registry rows.
	 * @return array<string, int>
	 */
	private function counts( array $rows ) {
		$counts = array();
		foreach ( Interaction_Limits::OUTCOMES as $outcome ) {
			$counts[ $outcome ] = 0;
		}
		foreach ( $rows as $row ) {
			$outcome = (string) $row['outcome'];
			if ( isset( $counts[ $outcome ] ) ) {
				$counts[ $outcome ]++;
			}
		}

		return $counts;
	}
}
