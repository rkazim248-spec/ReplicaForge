<?php
/**
 * Phase 12: the website-level design system.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts one design system from many pages, and reports where they disagree.
 *
 * ### The reuse that makes this short
 *
 * Phase 8's {@see Token_Engine} already does the hard half: it takes a flat list of
 * per-element observations and produces deduplicated token sets with occurrence
 * counts, contrast-aware colour naming, and a minimum-occurrence threshold of three
 * — because "one occurrence is a value, three is a pattern".
 *
 * Feeding it observations from *every* page therefore produces the global token set
 * for free, and the threshold becomes exactly the right test: a value used at least
 * three times across the whole website. So this class does **not** reimplement token
 * extraction. What it adds is the part that only exists above the page level:
 *
 * - **deriving observations** from many page representations rather than one;
 * - **agreement**, which asks whether a token is genuinely website-wide or is a
 *   component or a page variation wearing a global name;
 * - **conflict classification**, which is the part §12 and §35 are actually about.
 *
 * ### The rule that matters most
 *
 * Two pages disagreeing about a value is **not** a bug to be resolved by picking one.
 * Home at `#123456` and About at `#654321` might be a deliberate accent variation, a
 * genuinely different theme section, two component variants, or an extraction error —
 * and picking the majority answer in all four cases produces a design system that is
 * wrong somewhere and lies about it everywhere.
 *
 * So a value that appears on fewer than {@see Site_Limits::GLOBAL_AGREEMENT} of pages
 * is recorded as a **conflict with evidence**, and the pages on each side are named.
 * A user who wants the majority value promoted can say so. ReplicaForge does not do it
 * for them, because the evidence for *which* is correct is not in the data.
 */
final class Site_Design_System {

	/**
	 * Token engine.
	 *
	 * @var Token_Engine
	 */
	private $tokens;

	/**
	 * Constructor.
	 *
	 * @param Token_Engine|null $tokens Optional token engine.
	 */
	public function __construct( $tokens = null ) {
		$this->tokens = $tokens instanceof Token_Engine ? $tokens : new Token_Engine();
	}

	/**
	 * Build the website design system from a set of page representations.
	 *
	 * @param array<string, array<string, mixed>> $pages Page id => representation.
	 * @return array<string, mixed>
	 */
	public function build( array $pages ) {
		$per_page_tokens = array();
		$all_observations = array();
		$page_backgrounds = array();

		foreach ( $pages as $page_id => $representation ) {
			if ( ! is_array( $representation ) ) {
				continue;
			}
			$observations = self::observations_from( $representation );
			if ( array() === $observations ) {
				continue;
			}

			$page_backgrounds[ (string) $page_id ] = self::background_of( $representation );

			$page_facts = array( 'background' => $page_backgrounds[ (string) $page_id ] );
			$built      = $this->tokens->build( $observations, $page_facts );

			// A per-page token set is kept as *evidence*, not as an output. It is what
			// makes agreement measurable: a global token is one the pages agree on,
			// and that cannot be computed without knowing what each page said.
			$per_page_tokens[ (string) $page_id ] = $built;
			$all_observations                      = array_merge( $all_observations, $observations );
		}

		if ( array() === $all_observations ) {
			return $this->empty_system( $pages );
		}

		$global = $this->tokens->build( $all_observations, array( 'background' => $this->dominant_background( $page_backgrounds ) ) );

		$agreement = $this->agreement( $per_page_tokens );
		$conflicts = $this->conflicts( $per_page_tokens, $global, (int) count( $pages ) );
		$roles     = $this->roles( $global, $page_backgrounds, $agreement );

		return array(
			'schema_version' => Site_Limits::SCHEMA_VERSION,
			'built'          => true,
			'pages'          => count( $pages ),
			'families'       => $global['tokens'] ?? array(),
			'counts'         => $global['counts'] ?? array(),
			'total'          => (int) ( $global['total'] ?? 0 ),
			'named_ratio'    => isset( $global['named_ratio'] ) ? (float) $global['named_ratio'] : 0.0,
			'roles'          => $roles,
			'agreement'      => $agreement,
			'conflicts'      => $conflicts,
			'overrides'      => array(),
			'responsive'     => $this->responsive( $per_page_tokens ),
			'evidence'       => array(
				'observations' => count( $all_observations ),
				'per_page'     => array_keys( $per_page_tokens ),
			),
		);
	}

	/**
	 * Derive per-element observations from a Phase 2 representation.
	 *
	 * This is the bridge to Phase 8. The shape is `Token_Engine`'s own: a flat map
	 * per element carrying a `node_id`, a `role`, and family sub-keys. Nothing is
	 * invented — a value that is not in the representation is not observed, because an
	 * observation the analyzer did not make is a fabrication.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @return array<int, array<string, mixed>>
	 */
	public static function observations_from( array $representation ) {
		$out = array();
		$seen = 0;

		$collect = static function ( $node, $role, $source, $style ) use ( &$out, &$seen ) {
			if ( $seen >= 1200 ) {
				// Bounded. A pathological page must not turn extraction into a
				// memory problem, and 1200 elements is far more than any real page
				// contributes to a design system.
				return;
			}
			if ( ! is_array( $node ) ) {
				return;
			}

			$observation = array(
				'node_id'  => (string) ( $node['id'] ?? ( 'n' . $seen ) ),
				'role'     => (string) $role,
				'source'   => (string) $source,
				'has_text' => ! empty( $node['text'] ),
			);

			foreach ( array( 'typography', 'spacing', 'radius', 'shadow', 'container', 'background', 'color', 'border' ) as $key ) {
				if ( isset( $node[ $key ] ) && is_array( $node[ $key ] ) ) {
					$observation[ $key ] = $node[ $key ];
				}
			}
			if ( isset( $node['responsive'] ) && is_array( $node['responsive'] ) ) {
				$observation['responsive'] = $node['responsive'];
			}

			// A scalar is normalised into the *shape* the token engine reads, not the
			// shape the representation happens to use. `Token_Engine::color_values()`
			// looks for `$observation['background']['color']` and
			// `$observation['typography']['color']`; a representation that records a
			// section's background as the string `'#123456'` therefore contributes
			// *nothing* to the token set unless it is lifted into the array form. That
			// was a real defect: the bridge was passing values the engine could not
			// read, so a whole website of solid-colour sections produced no colour
			// tokens and no conflicts.
			//
			// A colour declared directly on a node is that node's foreground. Naming it
			// a *text* colour is the engine's decision, not this class's, and the engine
			// makes it from `has_text` and the page background — so lifting it here is
			// not a claim, it is a translation.
			if ( isset( $node['background'] ) && is_scalar( $node['background'] ) ) {
				$observation['background'] = array( 'color' => (string) $node['background'] );
			}
			if ( isset( $node['color'] ) && is_scalar( $node['color'] ) ) {
				$typography                = (array) ( $observation['typography'] ?? array() );
				$typography['color']       = (string) $node['color'];
				$observation['typography'] = $typography;
			}
			if ( isset( $node['border_color'] ) && is_scalar( $node['border_color'] ) ) {
				$observation['border'] = array( 'elementor' => array( 'color' => (string) $node['border_color'] ) );
			}
			// A style map is how Phase 2 records computed values for a node. It is
			// merged at the top level so `Token_Engine` sees the same keys it would
			// see from a single-page observation.
			if ( isset( $style ) && is_array( $style ) ) {
				foreach ( $style as $key => $value ) {
					if ( is_scalar( $value ) || is_array( $value ) ) {
						$observation[ $key ] = $value;
					}
				}
			}

			if ( count( $observation ) > 3 ) {
				$out[] = $observation;
				$seen++;
			}
		};

		$design = isset( $representation['design_system'] ) && is_array( $representation['design_system'] ) ? $representation['design_system'] : array();

		// The page background, once, as an observation. It is what makes a colour
		// nameable as a *text* colour rather than merely a colour.
		$background = self::background_of( $representation );
		if ( '' !== $background ) {
			$out[] = array(
				'node_id'      => 'page_background',
				'role'         => 'body',
				'has_text'     => false,
				'background'   => array( 'color' => $background ),
			);
		}

		$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$collect( $section, (string) ( $section['type'] ?? 'section' ), 'section', $section['style'] ?? null );
		}

		$components = isset( $representation['components'] ) && is_array( $representation['components'] ) ? $representation['components'] : array();
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$collect( $component, (string) ( $component['type'] ?? 'div' ), 'component', $component['style'] ?? null );
		}

		// The design system itself contributes observations, because a global font
		// family or a container width declared once at the document level is used by
		// every page and would otherwise never reach the token engine.
		foreach ( $design as $family => $values ) {
			if ( ! is_array( $values ) || $seen >= 1200 ) {
				continue;
			}
			foreach ( $values as $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$out[] = array(
					'node_id' => 'design_' . $family . '_' . $seen,
					'role'    => 'design_system',
					'has_text' => ( 'typography' === $family || 'colors' === $family ),
					$family   => ( 'colors' === $family ? 'color' : $family ),
					'value'   => (string) $value,
				);
				$seen++;
			}
		}

		// Responsive rules, one observation each, which is what makes breakpoints a
		// website-level token rather than a per-page detail.
		$responsive = isset( $representation['responsive'] ) && is_array( $representation['responsive'] ) ? $representation['responsive'] : array();
		foreach ( $responsive as $device => $rules ) {
			if ( ! is_array( $rules ) || $seen >= 1200 ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['max_width'] ) ) {
					continue;
				}
				$out[] = array(
					'node_id'     => 'breakpoint_' . $device . '_' . $seen,
					'role'        => 'breakpoint',
					'has_text'    => false,
					'breakpoint'  => array( 'max_width' => (int) $rule['max_width'], 'device' => (string) $device ),
				);
				$seen++;
			}
		}

		return $out;
	}

	/**
	 * Name the roles §10 asks for, from the extracted tokens.
	 *
	 * A role is only claimed when the evidence supports it. `primary` is the most
	 * used accent-classed colour; `text` is the most used text colour on the
	 * dominant background; `background` is the dominant page background. Where the
	 * evidence does not support a role it is left empty rather than filled with the
	 * largest available value, because a wrongly named `primary` is worse than an
	 * unnamed one — it will be used.
	 *
	 * @param array<string, mixed> $global            Global token set.
	 * @param array<string, string> $backgrounds      Page id => background.
	 * @param array<string, mixed> $agreement         Agreement report.
	 * @return array<string, mixed>
	 */
	private function roles( array $global, array $backgrounds, array $agreement ) {
		$colors = isset( $global['tokens']['colors'] ) && is_array( $global['tokens']['colors'] ) ? $global['tokens']['colors'] : array();
		$out    = array();

		$background = $this->dominant_background( $backgrounds );
		if ( '' !== $background ) {
			$out['background'] = array( 'value' => $background, 'source' => 'page_background', 'confidence' => 0.9, 'usage_count' => count( $backgrounds ) );
		}

		$text = $this->pick_named( $colors, 'text' );
		if ( null !== $text ) {
			$out['text'] = $text;
		}

		$primary = $this->pick_named( $colors, 'primary' );
		if ( null !== $primary ) {
			$out['primary'] = $primary;
		}

		foreach ( array( 'secondary', 'accent', 'surface', 'border', 'muted' ) as $role ) {
			$found = $this->pick_named( $colors, $role );
			if ( null !== $found ) {
				$out[ $role ] = $found;
			}
		}

		// Roles that came from a value the pages do not agree on carry a marker, so a
		// consumer can see that naming it was a judgement.
		foreach ( $out as $role => $token ) {
			$key   = $this->agreement_key( $role, $token['value'] );
			$share = isset( $agreement['values'][ $key ] ) ? (float) $agreement['values'][ $key ] : 0.0;
			if ( $share < Site_Limits::GLOBAL_AGREEMENT ) {
				$out[ $role ]['agreement'] = $share;
				$out[ $role ]['disputed'] = true;
			} else {
				$out[ $role ]['agreement'] = $share;
				$out[ $role ]['disputed'] = false;
			}
		}

		return $out;
	}

	/**
	 * Return how much the pages agree on each value.
	 *
	 * @param array<string, array<string, mixed>> $per_page_tokens Page id => token set.
	 * @return array<string, mixed>
	 */
	private function agreement( array $per_page_tokens ) {
		$values = array();
		$total  = max( 1, count( $per_page_tokens ) );

		foreach ( $per_page_tokens as $page_id => $built ) {
			$seen_here = array();
			foreach ( (array) ( $built['tokens'] ?? array() ) as $family => $entries ) {
				foreach ( (array) $entries as $name => $token ) {
					$value = is_array( $token ) && isset( $token['value'] ) ? (string) $token['value'] : '';
					if ( '' === $value ) {
						continue;
					}
					$key                            = $family . '|' . $value;
					$seen_here[ $key ]              = true;
				}
			}
			foreach ( $seen_here as $key => $unused ) {
				$values[ $key ] = ( $values[ $key ] ?? 0 ) + 1;
			}
		}

		$shares = array();
		foreach ( $values as $key => $count ) {
			$shares[ $key ] = round( $count / $total, 3 );
		}
		arsort( $shares );

		return array(
			'pages'      => $total,
			'threshold'  => Site_Limits::GLOBAL_AGREEMENT,
			'values'     => $shares,
			'global'     => array_values(
				array_filter(
					$shares,
					static function ( $share ) {
						return $share >= Site_Limits::GLOBAL_AGREEMENT;
					}
				)
			),
			'contested'  => array_values(
				array_filter(
					$shares,
					static function ( $share ) {
						return $share < Site_Limits::GLOBAL_AGREEMENT;
					}
				)
			),
		);
	}

	/**
	 * Classify the disagreements between pages.
	 *
	 * @param array<string, array<string, mixed>> $per_page_tokens Page token sets.
	 * @param array<string, mixed>                $global           Global token set.
	 * @param int                                 $page_count       Number of pages.
	 * @return array<int, array<string, mixed>>
	 */
	private function conflicts( array $per_page_tokens, array $global, $page_count ) {
		$out    = array();
		$global_values = array();
		foreach ( (array) ( $global['tokens'] ?? array() ) as $family => $entries ) {
			foreach ( (array) $entries as $token ) {
				$value = is_array( $token ) && isset( $token['value'] ) ? (string) $token['value'] : '';
				if ( '' !== $value ) {
					$global_values[ $family . '|' . $value ] = true;
				}
			}
		}

		$agreement = $this->agreement( $per_page_tokens );
		$threshold = Site_Limits::GLOBAL_AGREEMENT;

		// Which families are contested at all. A family where every page reports the
		// same single value is not a disagreement, and reporting it as one would bury
		// the real findings under the ones that matter.
		$contested = array();
		foreach ( $agreement['values'] as $key => $share ) {
			$family = (string) explode( '|', (string) $key, 2 )[0];
			$contested[ $family ] = isset( $contested[ $family ] ) ? ( $contested[ $family ] + 1 ) : 1;
		}

		foreach ( $agreement['values'] as $key => $share ) {
			if ( $share >= $threshold ) {
				continue;
			}

			list( $family, $value ) = array_pad( explode( '|', (string) $key, 2 ), 2, '' );

			// A value held by one page out of many is not a *conflict* — it is that
			// page's value, and naming it one would overstate what the data shows. But
			// on a two-page website, where every disagreement is 1-versus-1, that rule
			// would make conflicts impossible to detect at all, and two pages disagreeing
			// is precisely the case §12 describes. So the test is whether *any other
			// page* reports a different value in the same family — not how many pages
			// hold this one.
			$holders = array();
			$others  = array();
			foreach ( $per_page_tokens as $page_id => $built ) {
				$has = false;
				$other_values = array();
				foreach ( (array) ( $built['tokens'][ $family ] ?? array() ) as $token ) {
					if ( ! is_array( $token ) ) {
						continue;
					}
					$token_value = (string) ( $token['value'] ?? '' );
					if ( $token_value === $value ) {
						$has = true;
					} elseif ( '' !== $token_value ) {
						$other_values[ $token_value ] = true;
					}
				}
				if ( $has ) {
					$holders[] = (string) $page_id;
				} elseif ( array() !== $other_values ) {
					$others[] = (string) $page_id;
				}
			}

			if ( array() === $holders || array() === $others ) {
				// Either nobody holds it (a token that did not survive into this page's
				// set) or nobody disagrees. Neither is a conflict.
				continue;
			}

			$out[] = array(
				'family'      => (string) $family,
				'value'       => (string) $value,
				'share'       => (float) $share,
				'pages'       => $holders,
				'other_pages' => $others,
				'is_global'   => isset( $global_values[ (string) $key ] ),
				'kind'        => $this->classify_conflict( $family, $share, $page_count, $holders, $others ),
				'status'      => 'conflict',
				'resolution'  => '',
				'note'        => $this->conflict_note( $family, $share ),
			);
		}

		return array_slice( $out, 0, 60 );
	}

	/**
	 * Classify what a disagreement looks like.
	 *
	 * Deliberately conservative. Most cases resolve to `conflict` — "these pages
	 * disagree and the data cannot say why" — because guessing `page_variation` from
	 * a share of 0.6 would be inventing a reason. The two cases that *are* decided
	 * are decided by arithmetic rather than interpretation: a value on every page
	 * except one, and a value on a minority that is very small.
	 *
	 * @param string              $family     Token family.
	 * @param float               $share      Share of pages.
	 * @param int                 $page_count Page count.
	 * @param array<int, string>  $holders    Pages using the value.
	 * @param array<int, string>  $others     Pages not using it.
	 * @return string
	 */
	private function classify_conflict( $family, $share, $page_count, array $holders, array $others ) {
		$count = max( 1, (int) $page_count );

		// Every page but one: a single page differing is far more likely to be that
		// page's own theme section than a majority error.
		if ( count( $others ) === 1 && count( $holders ) >= 3 ) {
			return 'page_variation';
		}
		// One page out of many holding a value nothing else has.
		if ( count( $holders ) === 1 && $count >= 4 ) {
			return 'component_variation';
		}
		// A near-even split is the signature of two genuine variants rather than a
		// global value and a mistake.
		if ( $share >= 0.35 && $share <= 0.65 && $count >= 4 ) {
			return 'component_variation';
		}

		return 'conflict';
	}

	/**
	 * Return the note shown beside a conflict.
	 *
	 * @param string $family Token family.
	 * @param float  $share  Share of pages.
	 * @return string
	 */
	private function conflict_note( $family, $share ) {
		return sprintf(
			/* translators: 1: a percentage, 2: a token family such as colours or spacing. */
			__( 'This %2$s value appears on %1$s of the analyzed pages. ReplicaForge cannot tell whether that is deliberate variation or an extraction problem, so it has not been forced into a global token.', 'replicaforge' ),
			number_format_i18n( round( (float) $share * 100 ) ),
			(string) $family
		);
	}

	/**
	 * Return the website-level responsive strategy.
	 *
	 * Shared breakpoints are the intersection: a breakpoint only every page honours
	 * is a website-level fact. A page that breaks at a different width is a
	 * page-specific exception and is preserved as one, which §34 requires.
	 *
	 * @param array<string, array<string, mixed>> $per_page_tokens Page token sets.
	 * @return array<string, mixed>
	 */
	private function responsive( array $per_page_tokens ) {
		$per_page = array();
		foreach ( $per_page_tokens as $page_id => $built ) {
			$widths = array();
			foreach ( (array) ( $built['tokens']['breakpoints'] ?? array() ) as $token ) {
				$width = is_array( $token ) ? (int) ( $token['max_width'] ?? $token['value'] ?? 0 ) : (int) $token;
				if ( $width > 0 ) {
					$widths[ $width ] = true;
				}
			}
			if ( array() !== $widths ) {
				$per_page[ (string) $page_id ] = array_keys( $widths );
				sort( $per_page[ (string) $page_id ] );
			}
		}

		if ( array() === $per_page ) {
			return array( 'known' => false, 'shared' => array(), 'per_page' => array() );
		}

		$shared     = null;
		$exceptions = array();
		foreach ( $per_page as $page_id => $widths ) {
			$shared = ( null === $shared ) ? $widths : array_intersect( $shared, $widths );
			if ( array() !== array_diff( $widths, ( null === $shared ? array() : $shared ) ) ) {
				$exceptions[ (string) $page_id ] = array_values( $widths );
			}
		}

		return array(
			'known'      => true,
			'shared'     => array_values( array_map( 'intval', (array) $shared ) ),
			'per_page'   => $per_page,
			'exceptions' => $exceptions,
			'note'       => ( array() === (array) $shared )
				? __( 'No two pages share the same breakpoints, so no website-level breakpoint can be claimed.', 'replicaforge' )
				: __( 'These breakpoints are honoured by every analyzed page. Page-specific widths are preserved as exceptions.', 'replicaforge' ),
		);
	}

	/**
	 * Return the agreement key for a role and value.
	 *
	 * @param string $role  Token role.
	 * @param string $value Token value.
	 * @return string
	 */
	private function agreement_key( $role, $value ) {
		$family = ( false !== strpos( $role, 'color' ) || in_array( $role, array( 'text', 'muted', 'background', 'surface', 'border', 'primary', 'secondary', 'accent' ), true ) )
			? 'colors'
			: $role;
		return $family . '|' . (string) $value;
	}

	/**
	 * Return a named colour from a token set.
	 *
	 * @param array<string, mixed> $colors Colour tokens.
	 * @param string               $name   Token name to look for.
	 * @return array<string, mixed>|null
	 */
	private function pick_named( array $colors, $name ) {
		if ( ! isset( $colors[ $name ] ) ) {
			return null;
		}
		$token = $colors[ $name ];
		if ( ! is_array( $token ) || ! isset( $token['value'] ) ) {
			return null;
		}

		return array(
			'value'       => (string) $token['value'],
			'source'      => 'token_engine',
			'confidence'  => isset( $token['confidence'] ) ? (float) $token['confidence'] : 0.6,
			'usage_count' => isset( $token['occurrences'] ) ? (int) $token['occurrences'] : 0,
		);
	}

	/**
	 * Return the most common page background.
	 *
	 * @param array<string, string> $backgrounds Page id => background.
	 * @return string
	 */
	private function dominant_background( array $backgrounds ) {
		$tally = array();
		foreach ( $backgrounds as $value ) {
			$key = strtolower( trim( (string) $value ) );
			if ( '' === $key ) {
				continue;
			}
			$tally[ $key ] = ( $tally[ $key ] ?? 0 ) + 1;
		}
		if ( array() === $tally ) {
			return '';
		}
		arsort( $tally );
		return (string) array_key_first( $tally );
	}

	/**
	 * Return a page's background colour.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @return string
	 */
	public static function background_of( array $representation ) {
		$layout = isset( $representation['layout'] ) && is_array( $representation['layout'] ) ? $representation['layout'] : array();
		if ( isset( $layout['background'] ) && is_scalar( $layout['background'] ) ) {
			return (string) $layout['background'];
		}
		$design = isset( $representation['design_system'] ) && is_array( $representation['design_system'] ) ? $representation['design_system'] : array();
		foreach ( array( 'body_background', 'background', 'canvas' ) as $key ) {
			if ( isset( $design[ $key ] ) && is_scalar( $design[ $key ] ) ) {
				return (string) $design[ $key ];
			}
		}
		if ( isset( $design['colors'] ) && is_array( $design['colors'] ) ) {
			foreach ( array( 'background', 'page_background', 'body' ) as $key ) {
				if ( isset( $design['colors'][ $key ] ) && is_scalar( $design['colors'][ $key ] ) ) {
					return (string) $design['colors'][ $key ];
				}
			}
		}
		return '';
	}

	/**
	 * Return an empty system, for a website with nothing analyzable.
	 *
	 * @param array<string, mixed> $pages Pages.
	 * @return array<string, mixed>
	 */
	private function empty_system( array $pages ) {
		return array(
			'schema_version' => Site_Limits::SCHEMA_VERSION,
			'built'          => false,
			'pages'          => count( $pages ),
			'families'       => array(),
			'counts'         => array(),
			'total'          => 0,
			'named_ratio'    => 0.0,
			'roles'          => array(),
			'agreement'      => array( 'pages' => 0, 'threshold' => Site_Limits::GLOBAL_AGREEMENT, 'values' => array(), 'global' => array(), 'contested' => array() ),
			'conflicts'      => array(),
			'overrides'      => array(),
			'responsive'     => array( 'known' => false, 'shared' => array(), 'per_page' => array() ),
			'evidence'       => array( 'observations' => 0, 'per_page' => array() ),
			'reason'         => __( 'No page produced a usable analysis, so no design system could be extracted.', 'replicaforge' ),
		);
	}
}
