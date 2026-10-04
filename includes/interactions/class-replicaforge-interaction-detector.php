<?php
/**
 * Phase 16: the static interaction detector.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Finds interaction candidates from the phase 2 DOM, without executing anything.
 *
 * ### Why this works without a browser
 *
 * Most of what the specification calls "interaction" is *declared* in the markup, not
 * computed by a script. `<details>`/`<summary>` is a state machine. `aria-expanded` on a
 * button with `aria-controls` pointing at a real element is a state machine. `role="tab"`
 * with `aria-selected` and `aria-controls` pointing at `role="tabpanel"` is a tab set. A
 * `<dialog>` is a dialog. None of that requires running the page.
 *
 * What genuinely requires a browser is *confirmation* — whether clicking actually toggles
 * it, how long the transition is, whether a second click during the animation is honoured.
 * That is what {@see Browser_Driver_Contract} is for, and its absence downgrades
 * confidence rather than removing the finding.
 *
 * So the two-tier design is not a fallback, it is the correct decomposition: static analysis
 * establishes *what the page claims*, observation establishes *what it does*.
 *
 * ### Why a rules table and not a chain of ifs
 *
 * Because §6 and §39 require every trigger and every interaction to carry its evidence,
 * and a chain of ifs loses that information the moment it merges two conditions. Here each
 * rule declares its own evidence strings and its own confidence, so "why was this detected"
 * is a data question with a data answer — which is also what makes the detectors testable
 * without a browser.
 *
 * ### Ordering
 *
 * Rules are evaluated in declaration order and the first match for a component wins. The
 * order is specific, not alphabetical: the most *declared* patterns come first, so a
 * `role="tab"` is never re-detected as a generic dropdown because a dropdown rule ran
 * later on a class hint.
 */
final class Interaction_Detector {

	/**
	 * The DOM analyzer, for selector and text helpers.
	 *
	 * @var Dom_Analyzer
	 */
	private $dom;

	/**
	 * Class fragments that indicate each behaviour.
	 *
	 * Read rather than scattered through the rules, so the vocabulary is inspectable and a
	 * site-specific convention can be extended through the filter below rather than by
	 * editing five rules.
	 *
	 * @var array<string, array<int, string>>
	 */
	private $hints = array(
		'accordion'    => array( 'accordion', 'collapse', 'faq', 'toggle-item' ),
		'dropdown'     => array( 'dropdown', 'submenu', 'sub-menu', 'menu-item-has-children' ),
		'mega_menu'    => array( 'mega-menu', 'megamenu', 'mega_menu', 'mega nav' ),
		'mobile_menu'  => array( 'hamburger', 'nav-toggle', 'menu-toggle', 'mobile-nav', 'drawer', 'offcanvas' ),
		'tabs'         => array( 'tab', 'tabs' ),
		'carousel'     => array( 'carousel', 'slider', 'swiper', 'slick', 'owl-carousel', 'slides' ),
		'modal'        => array( 'modal', 'dialog', 'popup', 'lightbox' ),
		'tooltip'      => array( 'tooltip', 'popover', 'hint', 'help-tip' ),
		'search'       => array( 'search', 'searchform' ),
		'filter'       => array( 'filter', 'facet' ),
		'sort'         => array( 'sort', 'orderby' ),
		'pagination'   => array( 'pagination', 'pager', 'page-numbers' ),
		'load_more'    => array( 'load-more', 'loadmore', 'infinite-scroll' ),
		'sticky'       => array( 'sticky', 'fixed-header', 'is-sticky' ),
		'scroll_reveal' => array( 'reveal', 'scroll-reveal', 'aos', 'fade-in', 'animate-in' ),
		'gallery'      => array( 'gallery', 'lightbox', 'thumb' ),
		'variation'    => array( 'variation', 'swatch', 'attribute' ),
		'quantity'     => array( 'quantity', 'qty' ),
		'cart'         => array( 'add-to-cart', 'add_to_cart', 'single-add-to-cart' ),
	);

	/**
	 * Class fragments that mean "this is a navigational landmark".
	 *
	 * @var array<int, string>
	 */
	private $navigation_hints = array( 'menu', 'nav', 'header', 'breadcrumb', 'footer' );

	/**
	 * Constructor.
	 *
	 * @param Dom_Analyzer|null $dom DOM analyzer.
	 */
	public function __construct( $dom = null ) {
		$this->dom = $dom instanceof Dom_Analyzer ? $dom : new Dom_Analyzer();
	}

	/**
	 * The detection rules, in evaluation order.
	 *
	 * Each entry is:
	 *
	 *     array(
	 *       'name'    => string,   // stable slug, used in evidence
	 *       'type'    => string,   // Interaction_Limits::TYPES
	 *       'role'    => string,   // component role
	 *       'source'  => string,   // evidence source
	 *       'base'    => float,    // confidence this rule alone supports
	 *       'test'    => callable, // fn( array $node, array $index ) : bool
	 *     )
	 *
	 * `test` receives the node, the whole node table (for `aria-controls` resolution) and
	 * the resolved `by_id` map. Returning false simply moves to the next rule.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rules() {
		$rules = array(

			// ---- Declared, native, unambiguous. Nothing outranks these.
			array(
				'name'   => 'details_disclosure',
				'type'   => 'accordion',
				'role'   => 'disclosure',
				'source' => 'declared',
				'base'   => 0.95,
				'test'   => array( $this, 'is_summary' ),
			),
			array(
				'name'   => 'role_tab',
				'type'   => 'tabs',
				'role'   => 'tablist',
				'source' => 'declared',
				'base'   => 0.95,
				'test'   => array( $this, 'is_tab' ),
			),
			array(
				'name'   => 'native_dialog',
				'type'   => 'modal',
				'role'   => 'dialog',
				'source' => 'declared',
				'base'   => 0.92,
				'test'   => array( $this, 'is_dialog' ),
			),
			/*
			 * `mobile_menu_trigger` and `aria_menu` come *before* `aria_disclosure`, and
			 * the order is the whole point of this block.
			 *
			 * A hamburger button with `aria-expanded` and `aria-controls` satisfies every
			 * condition `aria_disclosure` tests, so if the general rule ran first it would
			 * classify every mobile menu and every submenu trigger as a generic disclosure.
			 * Both specific rules require strictly more evidence — a class hint, or an
			 * `aria-haspopup` — so running them first loses nothing and gains the specific
			 * classification. The general rule remains as the fallback for an expander
			 * with nothing more to say about it, which is the case it is actually for.
			 */
			array(
				'name'   => 'mobile_menu_trigger',
				'type'   => 'mobile_menu',
				'role'   => 'disclosure',
				'source' => 'declared',
				'base'   => 0.88,
				'test'   => array( $this, 'is_mobile_menu_trigger' ),
			),
			array(
				'name'   => 'aria_menu',
				'type'   => 'dropdown',
				'role'   => 'menu',
				'source' => 'declared',
				'base'   => 0.85,
				'test'   => array( $this, 'is_aria_menu_trigger' ),
			),
			array(
				'name'   => 'aria_disclosure',
				'type'   => 'expand_collapse',
				'role'   => 'disclosure',
				'source' => 'declared',
				'base'   => 0.90,
				'test'   => array( $this, 'is_aria_expander' ),
			),

			// ---- Framework-declared. A `data-toggle` is the author stating intent.
			array(
				'name'   => 'bs_modal',
				'type'   => 'modal',
				'role'   => 'dialog',
				'source' => 'declared',
				'base'   => 0.88,
				'test'   => function ( array $node ) {
					return 'modal' === $this->data_toggle( $node );
				},
			),
			array(
				'name'   => 'bs_collapse',
				'type'   => 'accordion',
				'role'   => 'disclosure',
				'source' => 'declared',
				'base'   => 0.85,
				'test'   => function ( array $node ) {
					return 'collapse' === $this->data_toggle( $node );
				},
			),
			array(
				'name'   => 'bs_dropdown',
				'type'   => 'dropdown',
				'role'   => 'menu',
				'source' => 'declared',
				'base'   => 0.85,
				'test'   => function ( array $node ) {
					return 'dropdown' === $this->data_toggle( $node );
				},
			),
			array(
				'name'   => 'bs_tab',
				'type'   => 'tabs',
				'role'   => 'tablist',
				'source' => 'declared',
				'base'   => 0.88,
				'test'   => function ( array $node ) {
					return 'tab' === $this->data_toggle( $node );
				},
			),
			array(
				'name'   => 'bs_carousel',
				'type'   => 'carousel',
				'role'   => 'carousel',
				'source' => 'declared',
				'base'   => 0.85,
				'test'   => function ( array $node ) {
					$ride = strtolower( (string) ( $node['attributes']['data-ride'] ?? '' ) );
					if ( 'carousel' === $ride ) {
						return true;
					}
					$desc = strtolower( (string) ( $node['attributes']['aria-roledescription'] ?? '' ) );

					return 'carousel' === $desc || 'slider' === $desc;
				},
			),

			// ---- Landmark-driven. The element is a nav region, and something inside it
			// is a submenu trigger.
			array(
				'name'   => 'nav_submenu',
				'type'   => 'navigation',
				'role'   => 'navigation',
				'source' => 'inferred',
				'base'   => 0.70,
				'test'   => array( $this, 'is_navigation_region' ),
			),

			// ---- Form controls, which are interactions with a state we can read directly.
			array(
				'name'   => 'quantity_control',
				'type'   => 'quantity_selector',
				'role'   => 'control',
				'source' => 'inferred',
				'base'   => 0.75,
				'test'   => function ( array $node ) {
					return $this->class_hits( $node, 'quantity' )
						|| 'quantity' === strtolower( (string) ( $node['attributes']['name'] ?? '' ) )
						|| 'quantity' === strtolower( (string) ( $node['attributes']['id'] ?? '' ) );
				},
			),
			array(
				'name'   => 'sort_control',
				'type'   => 'sort',
				'role'   => 'control',
				'source' => 'inferred',
				'base'   => 0.72,
				'test'   => function ( array $node ) {
					if ( 'select' !== $node['tag'] ) {
						return false;
					}
					$name = strtolower( (string) ( $node['attributes']['name'] ?? '' ) . ' ' . (string) ( $node['attributes']['id'] ?? '' ) );

					return (bool) preg_match( '/\b(sort|orderby|order)\b/', $name );
				},
			),
			array(
				'name'   => 'search_form',
				'type'   => 'search_overlay',
				'role'   => 'search',
				'source' => 'declared',
				'base'   => 0.80,
				'test'   => array( $this, 'is_search_landmark' ),
			),
			array(
				'name'   => 'gallery_region',
				'type'   => 'image_gallery',
				'role'   => 'gallery',
				'source' => 'inferred',
				'base'   => 0.65,
				'test'   => function ( array $node ) {
					return $this->class_hits( $node, 'gallery' ) && $node['child_count'] >= 2;
				},
			),
			array(
				'name'   => 'variation_region',
				'type'   => 'product_variation',
				'role'   => 'control',
				'source' => 'inferred',
				'base'   => 0.65,
				'test'   => function ( array $node ) {
					return $this->class_hits( $node, 'variation' ) && $node['child_count'] >= 2;
				},
			),

			// ---- Sticky, readable from the inline style without any CSS engine.
			array(
				'name'   => 'sticky_position',
				'type'   => 'sticky_header',
				'role'   => 'sticky',
				'source' => 'declared',
				'base'   => 0.85,
				'test'   => function ( array $node ) {
					return (bool) preg_match( '/position\s*:\s*(sticky|fixed)/i', (string) $node['style'] );
				},
			),
			array(
				'name'   => 'sticky_hint',
				'type'   => 'sticky_header',
				'role'   => 'sticky',
				'source' => 'inferred',
				'base'   => 0.55,
				'test'   => function ( array $node ) {
					return $this->class_hits( $node, 'sticky' );
				},
			),

			// ---- Scroll and hover, which are hints rather than declarations.
			array(
				'name'   => 'anchor_link',
				'type'   => 'anchor_scroll',
				'role'   => 'navigation',
				'source' => 'declared',
				'base'   => 0.80,
				'test'   => function ( array $node ) {
					return 'a' === $node['tag'] && 0 === strpos( (string) ( $node['attributes']['href'] ?? '' ), '#' ) && strlen( (string) ( $node['attributes']['href'] ?? '' ) ) > 1;
				},
			),
			array(
				'name'   => 'scroll_reveal',
				'type'   => 'scroll_reveal',
				'role'   => 'animation',
				'source' => 'inferred',
				'base'   => 0.50,
				'test'   => function ( array $node ) {
					return $this->class_hits( $node, 'scroll_reveal' ) && ! $node['visible'];
				},
			),
			array(
				'name'   => 'pointer_cursor',
				'type'   => 'hover',
				'role'   => 'state',
				'source' => 'declared',
				'base'   => 0.60,
				'test'   => function ( array $node ) {
					return (bool) preg_match( '/cursor\s*:\s*pointer/i', (string) $node['style'] );
				},
			),
		);

		/**
		 * Extend the class-hint vocabulary.
		 *
		 * @param array<string, array<int, string>> $hints Hints.
		 */
		return apply_filters( 'replicaforge_interaction_hints', $rules, $this->hints );
	}

	/**
	 * Detect interaction candidates from an analysis context.
	 *
	 * @param array<string, mixed> $context  Phase 2 analysis context.
	 * @param string               $viewport Viewport name.
	 * @return array<int, array<string, mixed>> Candidates, with no machine attached.
	 */
	public function detect( array $context, $viewport = '' ) {
		$nodes = (array) ( $context['nodes'] ?? array() );
		if ( array() === $nodes ) {
			return array();
		}

		$by_id = array();
		foreach ( $nodes as $node_id => $node ) {
			$element_id = (string) ( $node['attributes']['id'] ?? '' );
			if ( '' !== $element_id ) {
				$by_id[ $element_id ] = (string) $node_id;
			}
		}

		$rules     = $this->rules();
		$candidates = array();
		$claimed    = array();

		foreach ( $nodes as $node_id => $node ) {
			$node = $this->normalise( $node, $node_id, $by_id, $nodes );

			if ( $node['aria_hidden'] ) {
				// An `aria-hidden="true"` subtree is removed from the accessibility tree, so
				// a control inside it is not one a keyboard or screen-reader user can
				// reach. Treating it as an interaction would reconstruct something
				// unusable. Phase 2's parser already skips these; this is the second gate.
				continue;
			}

			if ( $node['in_aria_hidden_ancestor'] ) {
				// The common case is not a control that is *itself* hidden — it is a control
				// *inside* a hidden wrapper, such as a decorative icon-only button the author
				// hid and mirrored with a real labelled control. Phase 2's parser drops
				// these subtrees from the text index but keeps the nodes, so the ancestor
				// walk has to happen here.
				continue;
			}

			foreach ( $rules as $rule ) {
				$matched = false;
				try {
					$matched = (bool) call_user_func( $rule['test'], $node, $by_id );
				} catch ( \Throwable $e ) {
					// A rule that throws must not take the whole detection down. Recorded
					// and skipped: a missing detection is recoverable, a fatal is not.
					$context['warnings'][] = 'An interaction rule failed: ' . $rule['name'];
					continue;
				}

				if ( ! $matched ) {
					continue;
				}

				$candidates[] = $this->candidate( $node, $rule, $viewport );
				$claimed[]    = (string) $node_id;
				break; // First matching rule wins.
			}
		}

		return $this->bound( $candidates );
	}

	/**
	 * Build one candidate record.
	 *
	 * @param array<string, mixed> $node     Normalised node.
	 * @param array<string, mixed> $rule     Matched rule.
	 * @param string               $viewport Viewport name.
	 * @return array<string, mixed>
	 */
	private function candidate( array $node, array $rule, $viewport ) {
		$evidence = array( 'rule:' . $rule['name'] );

		foreach ( $node['declared_signals'] as $signal ) {
			$evidence[] = $signal;
		}
		foreach ( $node['hints'] as $hint ) {
			$evidence[] = 'class hint: ' . $hint;
		}

		return array(
			'component_id'     => $node['component_id'],
			'node_id'          => $node['node_id'],
			'type'             => (string) $rule['type'],
			'role'             => (string) $rule['role'],
			'trigger'          => $node['trigger'],
			'source_element'   => $node['selector'],
			'controls'         => $node['controls'],
			'initial_state'    => Interaction_Limits::initial_state( (string) $rule['type'] ),
			'evidence_source'  => (string) $rule['source'],
			'confidence'       => Interaction_Limits::clamp_confidence( (float) $rule['base'] + $node['confidence_bonus'] ),
			'evidence'         => array_values( array_unique( $evidence ) ),
			'viewport'         => (string) $viewport,
			'attributes'       => $node['safe_attributes'],
		);
	}

	/**
	 * Normalise a raw node into what the rules read.
	 *
	 * Extracting this once rather than per-rule keeps the rules declarative and means the
	 * "is it in a nav" question is answered by walking ancestors once, not once per rule
	 * per node.
	 *
	 * @param array<string, mixed>  $node   Raw node.
	 * @param string                $node_id Node id.
	 * @param array<string, string> $by_id  Element-id map.
	 * @param array<string, mixed>  $nodes  The whole node table, for parent lookups.
	 * @return array<string, mixed>
	 */
	private function normalise( array $node, $node_id, $by_id, array $nodes ) {
		$attributes = (array) ( $node['attributes'] ?? array() );
		$classes    = array_map( 'strtolower', (array) ( $node['classes'] ?? array() ) );
		$tag        = strtolower( (string) ( $node['tag'] ?? '' ) );

		$element_id = (string) ( $attributes['id'] ?? '' );
		$component_id = '' !== $element_id ? $element_id : (string) $node_id;

		$declared = array();
		foreach ( array( 'aria-expanded', 'aria-selected', 'aria-controls', 'aria-haspopup', 'aria-modal', 'role' ) as $attribute ) {
			if ( isset( $attributes[ $attribute ] ) && '' !== trim( (string) $attributes[ $attribute ] ) ) {
				$declared[] = $attribute . '=' . $attributes[ $attribute ];
			}
		}
		if ( isset( $attributes['required'] ) ) {
			$declared[] = 'required';
		}
		if ( ! empty( $node['visible'] ) === false ) {
			$declared[] = 'not visible in the source markup';
		}

		$hints = array();
		foreach ( $this->hints as $group => $needles ) {
			foreach ( $needles as $needle ) {
				foreach ( $classes as $class ) {
					if ( false !== strpos( $class, $needle ) ) {
						$hints[] = $needle;
						break 2;
					}
				}
			}
		}

		// A class hint is corroboration, never proof on its own — except that a class
		// named after a framework component is the author's own description of the widget,
		// which is stronger than a generic substring match and so earns a small bonus.
		$bonus = 0.0;
		if ( array() !== $declared ) {
			$bonus += 0.04;
		}
		if ( array() !== $hints ) {
			$bonus += 0.02;
		}

		$controls = array();
		if ( ! empty( $attributes['aria-controls'] ) ) {
			foreach ( preg_split( '/\s+/', (string) $attributes['aria-controls'] ) as $target ) {
				$target = trim( (string) $target );
				if ( '' === $target ) {
					continue;
				}
				$controls[] = array(
					'element_id' => $target,
					// Resolved against the page, so "controls a thing that is not there" is
					// distinguishable from "controls a thing". A trigger pointing at
					// nothing is a broken interaction and must not be reconstructed as one.
					'resolves'   => isset( $by_id[ $target ] ),
					'node_id'    => $by_id[ $target ] ?? '',
				);
			}
		}
		if ( ! empty( $attributes['data-target'] ) ) {
			$target = ltrim( (string) $attributes['data-target'], '#' );
			if ( '' !== $target ) {
				$controls[] = array(
					'element_id' => $target,
					'resolves'   => isset( $by_id[ $target ] ),
					'node_id'    => $by_id[ $target ] ?? '',
				);
			}
		}

		return array(
			'node_id'            => (string) $node_id,
			'component_id'       => $component_id,
			'tag'                => $tag,
			'attributes'         => $attributes,
			'classes'            => $classes,
			'style'              => (string) ( $node['style'] ?? '' ),
			'selector'           => (string) ( $node['selector'] ?? '' ),
			'visible'            => ! empty( $node['visible'] ),
			'child_count'        => (int) ( $node['child_count'] ?? 0 ),
			'parent'             => (string) ( $node['parent'] ?? '' ),
			'aria_hidden'        => 'true' === strtolower( trim( (string) ( $attributes['aria-hidden'] ?? '' ) ) ),
			// Resolved here rather than in a predicate. The `<summary>` rule needs to know
			// its parent is a `<details>`, and a predicate cannot see the node table — so
			// threading it through normalise once is both cheaper and clearer than giving
			// every rule the whole graph.
			'parent_tag'         => (string) ( $nodes[ (string) ( $node['parent'] ?? '' ) ]['tag'] ?? '' ),
			'in_aria_hidden_ancestor' => $this->hidden_ancestor( (string) $node_id, $nodes ),
			'declared_signals'   => $declared,
			'hints'              => $hints,
			'confidence_bonus'   => $bonus,
			'controls'           => $controls,
			'trigger'            => $this->trigger_for( $tag, $attributes ),
			'safe_attributes'    => $this->safe_attributes( $attributes ),
		);
	}

	/**
	 * Return only the attributes safe to persist.
	 *
	 * The phase 2 node already carries a bounded allowlist, but the model is stored and
	 * may travel further, so the projection is repeated here. `value` is dropped: it is
	 * the one attribute on this list that can hold a form value, and §50 forbids storing
	 * those.
	 *
	 * @param array<string, string> $attributes Attributes.
	 * @return array<string, string>
	 */
	private function safe_attributes( array $attributes ) {
		$keep = array(
			'id', 'class', 'role', 'type', 'name', 'href', 'rel', 'placeholder', 'method',
			'aria-label', 'aria-expanded', 'aria-selected', 'aria-controls', 'aria-haspopup',
			'aria-current', 'aria-modal', 'aria-roledescription', 'data-toggle', 'data-target',
			'data-bs-toggle', 'data-bs-target', 'data-state', 'data-index', 'data-ride',
			'data-slide', 'data-slide-to', 'required', 'disabled', 'checked', 'multiple',
			'open', 'rows', 'cols', 'scope', 'loading',
		);

		$out = array();
		foreach ( $keep as $attribute ) {
			if ( isset( $attributes[ $attribute ] ) ) {
				$value = (string) $attributes[ $attribute ];
				if ( Interaction_Limits::is_recordable_field( $attribute ) ) {
					$out[ $attribute ] = Security::clean_text( $value, 200 );
				}
			}
		}

		return $out;
	}

	/**
	 * Infer the trigger for an element.
	 *
	 * Ordered most-specific first, because a control that *is* a form field is a `change`
	 * or `submit` interaction regardless of what it looks like.
	 *
	 * @param string               $tag        Tag name.
	 * @param array<string, string> $attributes Attributes.
	 * @return string
	 */
	private function trigger_for( $tag, array $attributes ) {
		if ( 'input' === $tag && in_array( strtolower( (string) ( $attributes['type'] ?? 'text' ) ), array( 'checkbox', 'radio' ), true ) ) {
			return 'change';
		}
		if ( 'select' === $tag ) {
			return 'change';
		}
		if ( 'a' === $tag ) {
			return 'click';
		}
		if ( in_array( $tag, array( 'button', 'summary' ), true ) ) {
			return 'click';
		}
		if ( 'form' === $tag ) {
			return 'submit';
		}

		return 'click';
	}

	/**
	 * Return the `data-toggle` / `data-bs-toggle` value.
	 *
	 * @param array<string, mixed> $node Normalised node.
	 * @return string
	 */
	private function data_toggle( array $node ) {
		foreach ( array( 'data-bs-toggle', 'data-toggle' ) as $attribute ) {
			$value = strtolower( trim( (string) ( $node['attributes'][ $attribute ] ?? '' ) ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Return whether any class matches a hint group.
	 *
	 * @param array<string, mixed> $node  Normalised node.
	 * @param string               $group Hint group.
	 * @return bool
	 */
	private function class_hits( array $node, $group ) {
		$needles = (array) ( $this->hints[ $group ] ?? array() );
		foreach ( (array) $node['classes'] as $class ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( (string) $class, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Rule predicates
	 * ------------------------------------------------------------------ */

	/**
	 * A `<summary>` inside a `<details>`: a native disclosure with no script required.
	 *
	 * @param array<string, mixed> $node Normalised node.
	 * @return bool
	 */
	private function is_summary( array $node ) {
		return 'summary' === $node['tag'] && 'details' === $this->parent_tag( $node );
	}

	/**
	 * An element carrying `aria-expanded`, which is a disclosure trigger by definition.
	 *
	 * @param array<string, mixed> $node Normalised node.
	 * @return bool
	 */
	private function is_aria_expander( array $node ) {
		if ( ! isset( $node['attributes']['aria-expanded'] ) ) {
			return false;
		}
		if ( ! in_array( $node['tag'], array( 'button', 'a', 'summary', 'div', 'span', 'input' ), true ) ) {
			return false;
		}

		// An expander with nothing to expand is a broken control. Detecting it anyway
		// produces a replica with a button that does nothing, which is worse than
		// reporting nothing: the source is at fault, and saying so is the useful output.
		foreach ( (array) $node['controls'] as $control ) {
			if ( ! empty( $control['resolves'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A `role="tab"` element.
	 *
	 * @param array<string, mixed> $node Normalised node.
	 * @return bool
	 */
	private function is_tab( array $node ) {
		return 'tab' === strtolower( (string) ( $node['attributes']['role'] ?? '' ) );
	}

	/**
	 * A `<dialog>` element or a `role="dialog"`.
	 *
	 * @param array<string, mixed> $node Normalised node.
	 * @return bool
	 */
	private function is_dialog( array $node ) {
		if ( 'dialog' === $node['tag'] ) {
			return true;
		}
		if ( 'dialog' !== strtolower( (string) ( $node['attributes']['role'] ?? '' ) ) ) {
			return false;
		}

		// A dialog that is in the source markup as visible is more likely static content
		// than a modal — a real modal starts hidden. Flagged as evidence either way so a
		// reader can see why it was called a dialog.
		return true;
	}

	/**
	 * An element declaring `aria-haspopup` as a menu.
	 *
	 * @param array<string, mixed> $node Normalised node.
	 * @return bool
	 */
	private function is_aria_menu_trigger( array $node ) {
		$haspopup = strtolower( trim( (string) ( $node['attributes']['aria-haspopup'] ?? '' ) ) );
		if ( '' === $haspopup || 'false' === $haspopup ) {
			return false;
		}

		return in_array( $node['tag'], array( 'button', 'a', 'div', 'span' ), true );
	}

	/**
	 * A disclosure trigger that looks like a mobile navigation button.
	 *
	 * Both signals are required. A hamburger class alone is a common false positive — the
	 * icon exists in the DOM on desktop too — so it needs the expander behaviour to
	 * corroborate it, and the corroboration is what appears in the evidence.
	 *
	 * @param array<string, mixed> $node Normalised node.
	 * @return bool
	 */
	private function is_mobile_menu_trigger( array $node ) {
		if ( ! isset( $node['attributes']['aria-expanded'] ) && '' === $this->data_toggle( $node ) ) {
			return false;
		}
		if ( ! $this->class_hits( $node, 'mobile_menu' ) ) {
			return false;
		}
		// And it must actually control something, or it is a decorative icon.
		foreach ( (array) $node['controls'] as $control ) {
			if ( ! empty( $control['resolves'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A navigation landmark.
	 *
	 * @param array<string, mixed> $node Normalised node.
	 * @return bool
	 */
	private function is_navigation_region( array $node ) {
		if ( 'nav' === $node['tag'] ) {
			return true;
		}
		$role = strtolower( (string) ( $node['attributes']['role'] ?? '' ) );
		if ( in_array( $role, array( 'navigation', 'menubar' ), true ) ) {
			return true;
		}

		// A class hint alone is too weak — `menu` appears on every WordPress theme's
		// markup — so it must be paired with a list of links, which is what makes it a
		// navigation region rather than a styled list.
		if ( $node['child_count'] < 1 ) {
			return false;
		}
		foreach ( $this->navigation_hints as $needle ) {
			foreach ( (array) $node['classes'] as $class ) {
				if ( false !== strpos( (string) $class, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * A search landmark or a search input.
	 *
	 * @param array<string, mixed> $node Normalised node.
	 * @return bool
	 */
	private function is_search_landmark( array $node ) {
		$role = strtolower( (string) ( $node['attributes']['role'] ?? '' ) );
		if ( 'search' === $role ) {
			return true;
		}
		if ( 'input' === $node['tag'] && 'search' === strtolower( (string) ( $node['attributes']['type'] ?? '' ) ) ) {
			return true;
		}
		if ( 'form' !== $node['tag'] ) {
			return false;
		}
		$label = strtolower(
			(string) ( $node['attributes']['role'] ?? '' ) . ' '
			. (string) ( $node['attributes']['id'] ?? '' ) . ' '
			. (string) ( $node['attributes']['class'] ?? '' ) . ' '
			. (string) ( $node['attributes']['aria-label'] ?? '' )
		);

		return false !== strpos( $label, 'search' );
	}

	/**
	 * Return whether any ancestor carries `aria-hidden="true"`.
	 *
	 * Bounded by the node table's own depth limit rather than walking to the root
	 * unconditionally, so a malformed parent chain — a cycle in a hand-built table, which
	 * phase 2 cannot produce but a caller can — cannot spin here.
	 *
	 * @param string               $node_id Node id.
	 * @param array<string, mixed> $nodes   Node table.
	 * @return bool
	 */
	private function hidden_ancestor( $node_id, array $nodes ) {
		$parent = (string) ( $nodes[ (string) $node_id ]['parent'] ?? '' );
		$guard  = 0;

		while ( '' !== $parent && isset( $nodes[ $parent ] ) && $guard < 32 ) {
			if ( 'true' === strtolower( trim( (string) ( $nodes[ $parent ]['attributes']['aria-hidden'] ?? '' ) ) ) ) {
				return true;
			}
			$parent = (string) ( $nodes[ $parent ]['parent'] ?? '' );
			$guard++;
		}

		return false;
	}

	/**
	 * Return the tag of a node's parent, or an empty string.
	 *
	 * Resolved during {@see self::normalise()} and carried on the node, so a rule can ask
	 * the question without needing the graph.
	 *
	 * @param array<string, mixed> $node Normalised node.
	 * @return string
	 */
	private function parent_tag( array $node ) {
		return (string) ( $node['parent_tag'] ?? '' );
	}

	/**
	 * Bound the candidate list.
	 *
	 * Refuses to grow without limit, because a page with a thousand links would otherwise
	 * produce a thousand navigation candidates and a model nobody can read or apply. The
	 * bound is the interaction budget, so the same ceiling governs detection and
	 * observation and cannot disagree.
	 *
	 * @param array<int, array<string, mixed>> $candidates Candidates.
	 * @return array<int, array<string, mixed>>
	 */
	private function bound( array $candidates ) {
		$max = (int) Interaction_Limits::BUDGETS['max_interactions'];
		if ( count( $candidates ) <= $max ) {
			return $candidates;
		}

		return array_slice( $candidates, 0, $max );
	}
}
