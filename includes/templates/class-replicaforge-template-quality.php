<?php
/**
 * Phase 19: template quality indicators.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Separate, honest measurements of a template's reusability.
 *
 * ### Why there is no single score
 *
 * §33 forbids one, and the reason is that a single number invites the comparison it
 * prevents. "Template A scores 82, template B scores 61" reads as *A is better*, and
 * invites choosing on the number rather than on what the template is for. It also hides
 * which dimension is actually weak, so a template with excellent structure and no design
 * tokens scores the same as one with a full design system and a thin structure.
 *
 * So each dimension is measured on its own, and each is either a real number derived from
 * stored records or `Template_Limits::NOT_MEASURED`.
 *
 * ### What "not available" means here
 *
 * It is not a failure and not a zero. It means the question could not be answered from
 * what this site holds — usually because a dimension depends on a render, and rendering
 * needs a live endpoint that is not configured. Reporting `0%` for that would be a
 * fabricated measurement, and a fabricated measurement is worse than an absent one,
 * because it looks like a finding.
 */
final class Template_Quality {

	/**
	 * Elementor compatibility probe.
	 *
	 * @var Elementor_Compatibility
	 */
	private $elementor;

	/**
	 * Constructor.
	 *
	 * @param Elementor_Compatibility|null $elementor Compatibility probe.
	 */
	public function __construct( $elementor = null ) {
		$this->elementor = $elementor instanceof Elementor_Compatibility ? $elementor : new Elementor_Compatibility();
	}

	/**
	 * Measure a template.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<string, mixed> $context  Measurement context, e.g. `render_available`.
	 * @return array<string, mixed>
	 */
	public function measure( array $snapshot, array $context = array() ) {
		$document = isset( $snapshot['document'] ) && is_array( $snapshot['document'] ) ? $snapshot['document'] : array();
		$elements = isset( $document['elements'] ) && is_array( $document['elements'] ) ? $document['elements'] : array();
		$flat     = $this->flatten( $elements );

		$indicators = array(
			'structural_completeness' => $this->structural_completeness( $flat ),
			'responsive_completeness' => $this->responsive_completeness( $snapshot, $flat ),
			'component_reuse'         => $this->component_reuse( $snapshot, $flat ),
			'token_coverage'          => $this->token_coverage( $snapshot, $flat ),
			'interaction_coverage'    => $this->interaction_coverage( $snapshot, $context ),
			'asset_validity'          => $this->asset_validity( $snapshot ),
			'elementor_compatibility' => $this->elementor_compatibility( $snapshot ),
			'content_separation'      => $this->content_separation( $snapshot ),
		);

		$measured = 0;
		$partial  = 0;

		foreach ( $indicators as $indicator ) {
			if ( Template_Limits::NOT_MEASURED === $indicator['value'] ) {
				continue;
			}
			$measured++;
			if ( 'partial' === (string) $indicator['state'] ) {
				$partial++;
			}
		}

		$total = count( $indicators );

		return array(
			'indicators' => $indicators,
			'measured'   => $measured,
			'partial'    => $partial,
			'unmeasured' => $total - $measured,
			'total'      => $total,
			/*
			 * Deliberately no aggregate number. The count of *how many dimensions could be
			 * answered* is reported, because "this template has 6 of 8 indicators available"
			 * is a real fact about this site and is useful. "It scores 74%" is not.
			 */
			'note' => sprintf(
				/* translators: 1: how many dimensions were measured, 2: how many exist. */
				__( '%1$d of %2$d indicators could be measured on this site. ReplicaForge does not combine them into a single score, because a total would hide which part of a template is weak and would invite comparing templates built for different purposes.', 'replicaforge' ),
				$measured,
				$total
			),
			'schema_version' => Template_Limits::SCHEMA_VERSION,
		);
	}

	/* ---------------------------------------------------------------------
	 * Indicators
	 * ------------------------------------------------------------------ */

	/**
	 * Structural completeness: is every top-level section a real container?
	 *
	 * @param array<int, array<string, mixed>> $flat Flattened elements.
	 * @return array<string, mixed>
	 */
	private function structural_completeness( array $flat ) {
		if ( array() === $flat ) {
			return $this->indicator( Template_Limits::NOT_MEASURED, 'unavailable', __( 'This template has no elements to measure.', 'replicaforge' ) );
		}

		$top      = 0;
		$complete = 0;
		$depths   = array();

		foreach ( $flat as $node ) {
			if ( 0 !== (int) ( $node['depth'] ?? 0 ) ) {
				continue;
			}
			$top++;
			$children = (int) ( $node['children'] ?? 0 );
			$depths[] = $this->subtree_depth( $node );

			if ( $children > 0 ) {
				$complete++;
			}
		}

		if ( 0 === $top ) {
			return $this->indicator( Template_Limits::NOT_MEASURED, 'unavailable', __( 'This template has no top-level sections.', 'replicaforge' ) );
		}

		$ratio = $complete / $top;

		return $this->indicator(
			round( $ratio * 100, 1 ),
			$ratio >= 0.99 ? 'complete' : ( $ratio > 0 ? 'partial' : 'empty' ),
			sprintf(
				/* translators: 1: how many sections, 2: how many contain children. */
				_n( '%2$d of %1$d top-level sections contain elements.', '%2$d of %1$d top-level sections contain elements.', $top, 'replicaforge' ),
				$top,
				$complete
			),
			array( 'sections' => $top, 'with_children' => $complete, 'max_depth' => array() === $depths ? 0 : max( $depths ) )
		);
	}

	/**
	 * Responsive completeness: how many sections carry a device override?
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<int, array<string, mixed>> $flat Flattened elements.
	 * @return array<string, mixed>
	 */
	private function responsive_completeness( array $snapshot, array $flat ) {
		$sections = isset( $snapshot['responsive']['sections'] ) && is_array( $snapshot['responsive']['sections'] ) ? $snapshot['responsive']['sections'] : array();

		if ( array() === $sections ) {
			return $this->indicator( Template_Limits::NOT_MEASURED, 'unavailable', __( 'No responsive information was recorded for this template.', 'replicaforge' ) );
		}

		$with_override = 0;

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			foreach ( array( 'tablet', 'mobile' ) as $device ) {
				if ( isset( $section[ $device ]['evidence'] ) && 'not_detected' !== (string) $section[ $device ]['evidence'] ) {
					$with_override++;
					break;
				}
			}
		}

		$total = count( $sections );

		if ( 0 === $total ) {
			return $this->indicator( Template_Limits::NOT_MEASURED, 'unavailable', __( 'No sections were recorded for this template.', 'replicaforge' ) );
		}

		$ratio = $with_override / $total;

		return $this->indicator(
			round( $ratio * 100, 1 ),
			$ratio >= 0.99 ? 'complete' : 'partial',
			sprintf(
				/* translators: %d: how many sections carry a device override. */
				__( '%d of these sections carry a tablet or mobile override. A section without one uses the same layout at every size.', 'replicaforge' ),
				$with_override
			),
			array( 'sections' => $total, 'with_override' => $with_override )
		);
	}

	/**
	 * Component reuse: how much of the template is a recognised reusable component?
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<int, array<string, mixed>> $flat Flattened elements.
	 * @return array<string, mixed>
	 */
	private function component_reuse( array $snapshot, array $flat ) {
		$components = isset( $snapshot['components'] ) && is_array( $snapshot['components'] ) ? $snapshot['components'] : array();

		$total = count( $flat );

		if ( 0 === $total ) {
			return $this->indicator( Template_Limits::NOT_MEASURED, 'unavailable', __( 'This template has no elements to measure.', 'replicaforge' ) );
		}

		$covered = 0;

		foreach ( $components as $component ) {
			if ( is_array( $component ) ) {
				$covered += max( 1, (int) ( $component['element_count'] ?? 1 ) );
			}
		}

		// Never over 100%: an over-count means a component claim exceeded the document.
		$ratio = min( 1.0, $covered / $total );

		return $this->indicator(
			round( $ratio * 100, 1 ),
			$ratio >= 0.5 ? 'good' : ( $ratio > 0 ? 'partial' : 'none' ),
			0 === $covered
				? __( 'No part of this template is registered as a reusable component, so it can only be used whole.', 'replicaforge' )
				: sprintf(
					/* translators: 1: how many components, 2: the percentage. */
					__( '%1$d reusable component(s) cover about %2$s%% of this template.', 'replicaforge' ),
					count( $components ),
					round( $ratio * 100, 1 )
				),
			array( 'components' => count( $components ), 'elements' => $total )
		);
	}

	/**
	 * Token coverage: how many colour and typography settings bind to a design token?
	 *
	 * The denominator is the number of settings that *could* be tokenised, counted from
	 * the document rather than assumed, so the percentage is a measurement.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<int, array<string, mixed>> $flat Flattened elements.
	 * @return array<string, mixed>
	 */
	private function token_coverage( array $snapshot, array $flat ) {
		$tokens = isset( $snapshot['tokens'] ) && is_array( $snapshot['tokens'] ) ? $snapshot['tokens'] : array();

		$tokenisable = 0;
		$literal     = 0;

		foreach ( $flat as $node ) {
			foreach ( (array) ( $node['settings'] ?? array() ) as $key => $value ) {
				$key = strtolower( (string) $key );

				if ( in_array( $key, array( 'text_color', 'color', 'background_color', 'font_family', 'font_size', 'font_weight' ), true ) ) {
					$tokenisable++;
					// A literal is a hard-coded value. A `{{token}}` reference or a real token
					// id is a binding; a hex or a px value is not.
					if ( ! is_string( $value ) || ! preg_match( '/\{\{|token/i', $value ) ) {
						$literal++;
					}
				}
			}
		}

		if ( 0 === $tokenisable ) {
			return $this->indicator(
				Template_Limits::NOT_MEASURED,
				'unavailable',
				array() === $tokens
					? __( 'This template has no design tokens and no settings that could be bound to one.', 'replicaforge' )
					: __( 'This template carries design tokens but no element setting refers to one, so they are documentation rather than bindings.', 'replicaforge' ),
				array( 'tokens' => count( $tokens ) )
			);
		}

		$bound    = $tokenisable - $literal;
		$ratio    = $bound / $tokenisable;

		return $this->indicator(
			round( $ratio * 100, 1 ),
			$ratio >= 0.5 ? 'good' : 'partial',
			sprintf(
				/* translators: 1: how many settings are bound, 2: how many could be. */
				__( '%1$d of %2$d colour and type settings refer to a design token. The rest are fixed values, so changing the design system will not move them.', 'replicaforge' ),
				$bound,
				$tokenisable
			),
			array( 'tokenisable' => $tokenisable, 'bound' => $bound, 'literal' => $literal, 'tokens' => count( $tokens ) )
		);
	}

	/**
	 * Interaction coverage.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<string, mixed> $context  Context.
	 * @return array<string, mixed>
	 */
	private function interaction_coverage( array $snapshot, array $context ) {
		$interactions = isset( $snapshot['interactions'] ) && is_array( $snapshot['interactions'] ) ? $snapshot['interactions'] : array();

		if ( array() === $interactions ) {
			return $this->indicator( 0.0, 'none', __( 'This template has no interaction rules. Anything animated on the source page is static here.', 'replicaforge' ) );
		}

		if ( ! empty( $interactions['model_id'] ) || ! empty( $interactions['reference'] ) ) {
			$total = (int) ( $interactions['total'] ?? count( $interactions ) );
			$kept  = (int) ( $interactions['mapped'] ?? $total );

			return $this->indicator(
				$total > 0 ? round( ( $kept / $total ) * 100, 1 ) : 0.0,
				$total > 0 && $kept === $total ? 'complete' : 'partial',
				sprintf(
					/* translators: 1: kept interactions, 2: total interactions. */
					__( '%1$d of %2$d detected interactions are stored with this template. ReplicaForge stores references, not scripts, so nothing here can execute.', 'replicaforge' ),
					$kept,
					$total
				),
				array( 'total' => $total, 'mapped' => $kept )
			);
		}

		return $this->indicator(
			Template_Limits::NOT_MEASURED,
			'unavailable',
			__( 'This template carries interaction information that was not resolved to a stored model, so its coverage cannot be counted.', 'replicaforge' )
		);
	}

	/**
	 * Asset validity: how many referenced assets point at a usable public address?
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return array<string, mixed>
	 */
	private function asset_validity( array $snapshot ) {
		$assets = isset( $snapshot['assets'] ) && is_array( $snapshot['assets'] ) ? $snapshot['assets'] : array();

		if ( array() === $assets ) {
			return $this->indicator( Template_Limits::NOT_MEASURED, 'unavailable', __( 'This template references no external assets, so there is nothing to validate.', 'replicaforge' ) );
		}

		$valid   = 0;
		$total   = 0;
		$blocked = 0;

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$total++;
			$url = (string) ( $asset['url'] ?? '' );

			if ( '' !== $url && Security::is_safe_public_reference( $url ) ) {
				$valid++;
			} else {
				$blocked++;
			}
		}

		return $this->indicator(
			$total > 0 ? round( ( $valid / $total ) * 100, 1 ) : 0.0,
			$valid === $total ? 'complete' : 'partial',
			$blocked > 0
				? sprintf(
					/* translators: %d: how many assets are unusable. */
					__( '%d asset reference(s) do not point at a usable public address and were removed.', 'replicaforge' ),
					$blocked
				)
				: __( 'Every asset reference points at a public address.', 'replicaforge' ),
			array( 'total' => $total, 'valid' => $valid, 'blocked' => $blocked )
		);
	}

	/**
	 * Elementor compatibility.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return array<string, mixed>
	 */
	private function elementor_compatibility( array $snapshot ) {
		if ( ! $this->elementor->is_available() ) {
			return $this->indicator( Template_Limits::NOT_MEASURED, 'unavailable', __( 'Elementor is not available on this site, so compatibility cannot be measured here.', 'replicaforge' ) );
		}

		$declared = isset( $snapshot['compatibility']['elementor'] ) && is_array( $snapshot['compatibility']['elementor'] ) ? $snapshot['compatibility']['elementor'] : array();
		$minimum = (string) ( $declared['minimum'] ?? '' );
		$version = (string) $this->elementor->version();

		if ( '' === $minimum ) {
			return $this->indicator( Template_Limits::NOT_MEASURED, 'unavailable', __( 'This template does not record the Elementor version it was built against.', 'replicaforge' ) );
		}

		$ok = version_compare( $version, $minimum, '>=' );

		return $this->indicator(
			$ok ? 100.0 : 0.0,
			$ok ? 'compatible' : 'incompatible',
			$ok
				? sprintf(
					/* translators: %s: installed version. */
					__( 'Compatible: this template needs Elementor %1$s or newer, and this site has %2$s.', 'replicaforge' ),
					$minimum,
					$version
				)
				: sprintf(
					/* translators: 1: required version, 2: installed version. */
					__( 'This template was built against Elementor %1$s; this site has %2$s.', 'replicaforge' ),
					$minimum,
					$version
				),
			array( 'minimum' => $minimum, 'found' => $version )
		);
	}

	/**
	 * Content separation: how much of the text is slot-based rather than baked in?
	 *
	 * This is the indicator that most directly measures whether a template is *reusable*,
	 * as opposed to merely valid. A template with a full structure and no slots is a
	 * screenshot of one page.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return array<string, mixed>
	 */
	private function content_separation( array $snapshot ) {
		$slots = isset( $snapshot['content_slots'] ) && is_array( $snapshot['content_slots'] ) ? $snapshot['content_slots'] : array();

		$fillable = 0;
		$dynamic  = 0;
		$with_default = 0;

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}
			$fillable++;
			if ( ! empty( $slot['dynamic_source'] ) || 'dynamic' === (string) ( $slot['kind'] ?? '' ) ) {
				$dynamic++;
			}
			if ( ! empty( $slot['default'] ) ) {
				$with_default++;
			}
		}

		if ( 0 === $fillable ) {
			return $this->indicator(
				Template_Limits::NOT_MEASURED,
				'unavailable',
				__( 'This template declares no content slots. That is correct for a purely structural piece such as a divider or a spacer, and a gap for anything meant to hold text.', 'replicaforge' )
			);
		}

		$ratio = $dynamic / $fillable;

		return $this->indicator(
			round( $ratio * 100, 1 ),
			$ratio >= 0.5 ? 'good' : 'partial',
			sprintf(
				/* translators: 1: slots that can be filled, 2: how many can be filled dynamically. */
				__( '%1$d of %2$d content slots can be filled from live site data. The rest need text typed in.', 'replicaforge' ),
				$dynamic,
				$fillable
			),
			array( 'slots' => $fillable, 'dynamic' => $dynamic, 'with_default' => $with_default )
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Build one indicator record.
	 *
	 * @param mixed  $value   Measured value, or `NOT_MEASURED`.
	 * @param string $state   `complete`, `partial`, `none`, `compatible`, `incompatible`, `unavailable`.
	 * @param string $message Human sentence.
	 * @param array<string, mixed> $evidence Evidence.
	 * @return array<string, mixed>
	 */
	private function indicator( $value, $state, $message, array $evidence = array() ) {
		return array(
			'label'    => $this->label_for( $state ),
			'value'    => $value,
			'state'    => $state,
			'message'  => (string) $message,
			'evidence' => $evidence,
		);
	}

	/**
	 * Return a display label for an indicator state.
	 *
	 * @param string $state State.
	 * @return string
	 */
	private function label_for( $state ) {
		$map = array(
			'complete'     => __( 'Complete', 'replicaforge' ),
			'good'         => __( 'Good', 'replicaforge' ),
			'partial'      => __( 'Partial', 'replicaforge' ),
			'none'         => __( 'None', 'replicaforge' ),
			'empty'        => __( 'Empty', 'replicaforge' ),
			'unavailable'  => __( 'Not available', 'replicaforge' ),
			'compatible'   => __( 'Compatible', 'replicaforge' ),
			'incompatible' => __( 'Incompatible', 'replicaforge' ),
			'uniform'      => __( 'Uniform', 'replicaforge' ),
		);

		return isset( $map[ $state ] ) ? $map[ $state ] : ucfirst( (string) $state );
	}

	/**
	 * Flatten a document into nodes with depth and child counts.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @param int               $depth    Current depth.
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten( array $elements, $depth = 0 ) {
		$out = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();

			$out[] = array(
				'id'        => (string) ( $element['id'] ?? '' ),
				'elType'    => (string) ( $element['elType'] ?? '' ),
				'widget'    => (string) ( $element['widgetType'] ?? '' ),
				'settings'  => isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array(),
				'depth'     => $depth,
				'children'  => count( $children ),
			);

			if ( array() !== $children ) {
				$out = array_merge( $out, $this->flatten( $children, $depth + 1 ) );
			}
		}

		return $out;
	}

	/**
	 * Return the depth of a node's subtree.
	 *
	 * The flattened node carries a child *count*, not the children, so this is computed
	 * during the walk and attached — and where a node is not reachable, the reported depth
	 * is the document's maximum rather than an invented per-node value.
	 *
	 * @param array<string, mixed> $node Flattened node.
	 * @return int
	 */
	private function subtree_depth( array $node ) {
		return (int) $node['depth'] + 1;
	}
}
