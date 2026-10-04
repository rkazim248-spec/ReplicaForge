<?php
/**
 * Phase 12: cross-page validation and shared-component correction.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the generated website against the source, across pages rather than
 * within one.
 *
 * ### Why per-page validation is not enough
 *
 * Phase 5 validates a page. Every page can pass individually and the website can
 * still be wrong: the same CTA rendered `#6C63FF` on one page and `#735FFF` on the
 * next, with both being a perfect match for what the analyzer recorded *on that
 * page*. The inconsistency is between pages, and nothing in Phase 5 looks there.
 *
 * So this class has a different question: **do the pages agree with each other and
 * with the shared specification?** That is what §43 and §45 ask for, and it is why
 * a shared component's own value is treated as the reference — a shared component
 * that differs from the shared specification is the finding, and correcting the
 * shared component fixes every page at once.
 *
 * ### Nothing here writes
 *
 * This class finds differences. Correction eligibility is decided here too, but the
 * write happens through {@see Multi_Page_Planner}, which holds the job and the
 * rollback. A validator that edits is a validator whose partial failure is
 * unobservable.
 */
final class Cross_Page_Validator {

	/**
	 * The categories §44 asks to see.
	 *
	 * @var array<int, string>
	 */
	const CATEGORIES = array(
		'structure',
		'design_system',
		'shared_components',
		'navigation',
		'responsive',
		'assets',
		'content',
		'templates',
	);

	/**
	 * Colour tolerance for "the same colour".
	 *
	 * Two hex values within this distance count as the same. Without a tolerance,
	 * sub-pixel antialiasing and a theme's `#fff` versus `#ffffff` would be reported
	 * as hundreds of findings, and a report nobody reads is a report nobody fixes.
	 *
	 * @var int
	 */
	const COLOR_TOLERANCE = 2;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Optional logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Validate a generated website against its source.
	 *
	 * @param array<string, mixed> $specification Website specification.
	 * @param array<string, mixed> $generated     Generated page state, page id => record.
	 * @return array<string, mixed>
	 */
	public function validate( array $specification, array $generated ) {
		$findings = array();
		$pages    = isset( $specification['pages'] ) && is_array( $specification['pages'] ) ? $specification['pages'] : array();

		$generated_count = 0;
		$validated_count = 0;
		$review_count    = 0;

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$page_id = (string) ( $page['page_id'] ?? '' );
			if ( '' === $page_id ) {
				continue;
			}
			$status = (string) ( $generated[ $page_id ]['status'] ?? ( $page['status'] ?? 'pending' ) );

			// A page counts as generated only if it produced a document **and** did not
			// fail. The first draft counted any page with a post id, so a page that had
			// been written and then failed validation was reported as generated — which
			// is the one number in the §44 summary a user is most likely to act on.
			$has_document = ! empty( $generated[ $page_id ]['post_id'] );
			if ( $has_document && 'failed' !== $status ) {
				$generated_count++;
			}
			if ( ! empty( $generated[ $page_id ]['validated'] ) ) {
				$validated_count++;
			}
		}

		$findings = array_merge( $findings, $this->shared_component_findings( $specification, $generated ) );
		$findings = array_merge( $findings, $this->design_system_findings( $specification, $generated ) );
		$findings = array_merge( $findings, $this->template_findings( $specification, $generated ) );
		$findings = array_merge( $findings, $this->navigation_findings( $specification ) );
		$findings = array_merge( $findings, $this->responsive_findings( $specification ) );
		$findings = array_merge( $findings, $this->generation_findings( $pages, $generated ) );

		foreach ( $findings as $finding ) {
			if ( 'review' === (string) $finding['severity'] ) {
				$review_count++;
			}
		}

		$by_category = array();
		foreach ( self::CATEGORIES as $category ) {
			$by_category[ $category ] = 0;
		}
		foreach ( $findings as $finding ) {
			$category = (string) $finding['category'];
			if ( ! isset( $by_category[ $category ] ) ) {
				$by_category[ $category ] = 0;
			}
			$by_category[ $category ]++;
		}

		$blocking = array_values( array_filter( $findings, static function ( $f ) {
			return 'error' === (string) $f['severity'];
		} ) );

		return array(
			'schema_version' => Site_Limits::SCHEMA_VERSION,
			'pages'          => count( $pages ),
			'generated'      => $generated_count,
			'validated'      => $validated_count,
			'needs_review'   => $review_count,
			'findings'       => $findings,
			'counts'         => array(
				'total'    => count( $findings ),
				'errors'   => count( $blocking ),
				'warnings' => count( array_filter( $findings, static function ( $f ) { return 'warning' === (string) $f['severity']; } ) ),
				'reviews'  => $review_count,
			),
			'by_category'    => $by_category,
			'categories'     => self::CATEGORIES,
			'verdict'        => ( 0 === count( $blocking ) ? 'pass' : 'fail' ),
			'generated_at'   => time(),
		);
	}

	/* ---------------------------------------------------------------------
	 * Findings
	 * ------------------------------------------------------------------ */

	/**
	 * Find pages that render a shared component differently from each other.
	 *
	 * This is §45's exact case. It compares each page's rendered value for a shared
	 * component against the component's own recorded value, and reports the pages
	 * that disagree — rather than reporting that two pages differ, which is only
	 * actionable if the user is told which is the odd one out.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @param array<string, mixed> $generated     Generated state.
	 * @return array<int, array<string, mixed>>
	 */
	private function shared_component_findings( array $specification, array $generated ) {
		$out = array();

		foreach ( (array) ( $specification['shared_components'] ?? array() ) as $component ) {
			if ( ! is_array( $component ) || empty( $component['component_id'] ) ) {
				continue;
			}
			$id     = (string) $component['component_id'];
			$role   = (string) ( $component['role'] ?? '' );
			$target = (string) ( $component['value'] ?? '' );

			$observed = array();
			foreach ( (array) ( $component['pages'] ?? array() ) as $page_id ) {
				$page_id = (string) $page_id;
				$value   = (string) ( $generated[ $page_id ]['components'][ $id ]['value'] ?? '' );
				if ( '' === $value ) {
					continue;
				}
				$observed[ $page_id ] = $value;
			}

			if ( array() === $observed ) {
				continue;
			}

			$groups = $this->group_by_value( $observed );

			if ( count( $groups ) <= 1 ) {
				continue;
			}

			// The reference value is the one the *specification* declares, when it
			// declares one. Falling back to "whichever the most pages use" is only
			// right when there is nothing else to compare against, and getting the
			// order wrong matters: the first draft took the majority as the reference
			// unconditionally, so on a component whose declared value was in the
			// minority the finding was reported against the *correct* pages and the
			// divergent ones were skipped — a real inconsistency reported as clean.
			$declared  = ( '' !== $target ) ? $target : '';
			$reference = ( '' !== $declared ) ? $declared : (string) array_key_first( $this->sort_desc( $groups ) );

			$offenders = array();
			foreach ( $groups as $value => $page_ids ) {
				if ( $this->same_value( (string) $value, $reference ) ) {
					continue;
				}
				$offenders[ (string) $value ] = array_values( $page_ids );
			}

			if ( array() === $offenders ) {
				continue;
			}

			$conforming = 0;
			if ( isset( $groups[ $reference ] ) ) {
				$conforming = count( (array) $groups[ $reference ] );
			}

			foreach ( $offenders as $value => $page_ids ) {
				$out[] = $this->finding(
					'design_system',
					'' !== $declared ? 'warning' : 'review',
					'shared_component_inconsistency',
					sprintf(
						/* translators: 1: component role, 2: number of pages rendering the reference value, 3: number rendering something else. */
						__( 'The shared %1$s is rendered one way on %2$d pages and another way on %3$d pages. Correcting the shared component would fix all of them at once.', 'replicaforge' ),
						'' !== $role ? $role : __( 'component', 'replicaforge' ),
						$conforming,
						count( $page_ids )
					),
					array(
						'component_id' => $id,
						'role'         => $role,
						'pages'        => array_values( $page_ids ),
						'value'        => (string) $value,
						'majority'     => (string) $reference,
						'shared_value' => $declared,
						'reference_is_declared' => ( '' !== $declared ),
						'correction'   => $this->correction_for( $component, 'design_system' ),
					)
				);
			}
		}

		return $out;
	}

	/**
	 * Return a map sorted by descending size.
	 *
	 * @param array<string, array<int, string>> $groups Groups.
	 * @return array<string, array<int, string>>
	 */
	private function sort_desc( array $groups ) {
		uasort(
			$groups,
			static function ( $left, $right ) {
				return count( $right ) - count( $left );
			}
		);
		return $groups;
	}

	/**
	 * Find pages that do not match the global design system.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @param array<string, mixed> $generated     Generated state.
	 * @return array<int, array<string, mixed>>
	 */
	private function design_system_findings( array $specification, array $generated ) {
		$out      = array();
		$design   = isset( $specification['global_design_system'] ) && is_array( $specification['global_design_system'] ) ? $specification['global_design_system'] : array();
		$roles    = isset( $design['roles'] ) && is_array( $design['roles'] ) ? $design['roles'] : array();

		foreach ( $roles as $role => $token ) {
			if ( ! is_array( $token ) || empty( $token['value'] ) ) {
				continue;
			}
			// A disputed role is not a standard a page is measured against. Applying
			// one side of an unresolved conflict as the truth is exactly what §12
			// says not to do.
			if ( ! empty( $token['disputed'] ) ) {
				$out[] = $this->finding(
					'design_system',
					'review',
					'disputed_design_token',
					sprintf(
						/* translators: %s: a design token role such as primary colour. */
						__( 'The %s token is disputed between pages, so it has not been enforced on any page. Review it to decide which value is intended.', 'replicaforge' ),
						(string) $role
					),
					array( 'role' => (string) $role, 'value' => (string) $token['value'], 'agreement' => (float) ( $token['agreement'] ?? 0 ) )
				);
				continue;
			}

			$expected = (string) $token['value'];
			$offenders = array();
			foreach ( $generated as $page_id => $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}
				$actual = (string) ( $record['tokens'][ $role ] ?? '' );
				if ( '' === $actual || $this->same_value( $actual, $expected ) ) {
					continue;
				}
				$offenders[] = array( 'page_id' => (string) $page_id, 'value' => $actual );
			}

			if ( array() === $offenders ) {
				continue;
			}

			$out[] = $this->finding(
				'design_system',
				'warning',
				'token_not_applied',
				sprintf(
					/* translators: 1: a design token role, 2: number of pages. */
					__( 'The %1$s token (%2$s) was not applied to %3$d generated page(s).', 'replicaforge' ),
					(string) $role,
					$expected,
					count( $offenders )
				),
				array( 'role' => (string) $role, 'expected' => $expected, 'pages' => $offenders )
			);
		}

		return $out;
	}

	/**
	 * Find pages that diverge from the template they share.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @param array<string, mixed> $generated     Generated state.
	 * @return array<int, array<string, mixed>>
	 */
	private function template_findings( array $specification, array $generated ) {
		$out = array();

		foreach ( (array) ( $specification['templates'] ?? array() ) as $template ) {
			if ( ! is_array( $template ) || empty( $template['template_id'] ) ) {
				continue;
			}
			$signature = (string) ( $template['signature'] ?? '' );
			$diverged  = array();

			foreach ( (array) ( $template['pages'] ?? array() ) as $page_id ) {
				$page_id = (string) $page_id;
				$actual  = (string) ( $generated[ $page_id ]['template_signature'] ?? '' );
				if ( '' === $actual || $actual === $signature ) {
					continue;
				}
				$diverged[] = $page_id;
			}

			if ( array() === $diverged ) {
				continue;
			}

			$out[] = $this->finding(
				'templates',
				'warning',
				'template_divergence',
				sprintf(
					/* translators: 1: template name, 2: number of pages. */
					__( '%1$d page(s) using %2$s do not match the template structure.', 'replicaforge' ),
					count( $diverged ),
					(string) ( $template['template_id'] ?? __( 'a template', 'replicaforge' ) )
				),
				array(
					'template_id' => (string) $template['template_id'],
					'pages'       => $diverged,
					'expected'    => $signature,
					'note'        => __( 'A page that has been edited by hand will differ from its template. That is a normal outcome and is not a fault.', 'replicaforge' ),
				)
			);
		}

		return $out;
	}

	/**
	 * Find navigation links that will not resolve.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @return array<int, array<string, mixed>>
	 */
	private function navigation_findings( array $specification ) {
		$out = array();
		$nav = isset( $specification['navigation'] ) && is_array( $specification['navigation'] ) ? $specification['navigation'] : array();

		foreach ( (array) ( $nav['unmapped'] ?? array() ) as $link ) {
			if ( ! is_array( $link ) || empty( $link['source'] ) ) {
				continue;
			}
			$out[] = $this->finding(
				'navigation',
				'review',
				'unmapped_internal_link',
				sprintf(
					/* translators: %s: a label from the source navigation. */
					__( '"%s" links to a page that was not reconstructed, so it still points at the original website.', 'replicaforge' ),
					(string) ( $link['label'] ?? $link['source'] )
				),
				array(
					'label'  => (string) ( $link['label'] ?? '' ),
					'source' => (string) $link['source'],
					'note'   => __( 'No replacement was invented for this. You can map it to one of your own pages, or leave it pointing at the source.', 'replicaforge' ),
				)
			);
		}

		foreach ( (array) ( $nav['areas'] ?? array() ) as $area => $items ) {
			foreach ( (array) $items as $item ) {
				if ( ! is_array( $item ) || 'unlinked' !== (string) ( $item['type'] ?? '' ) ) {
					continue;
				}
				$out[] = $this->finding(
					'navigation',
					'review',
					'nav_item_without_target',
					sprintf(
						/* translators: %s: a navigation label. */
						__( 'The navigation item "%s" had no address in the source, so none was created.', 'replicaforge' ),
						(string) ( $item['label'] ?? '' )
					),
					array( 'area' => (string) $area, 'label' => (string) ( $item['label'] ?? '' ) )
				);
			}
		}

		return $out;
	}

	/**
	 * Find pages that break at different widths.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @return array<int, array<string, mixed>>
	 */
	private function responsive_findings( array $specification ) {
		$out      = array();
		$design   = isset( $specification['global_design_system'] ) && is_array( $specification['global_design_system'] ) ? $specification['global_design_system'] : array();
		$response = isset( $design['responsive'] ) && is_array( $design['responsive'] ) ? $design['responsive'] : array();

		if ( empty( $response['known'] ) || array() === (array) ( $response['exceptions'] ?? array() ) ) {
			return $out;
		}

		$out[] = $this->finding(
			'responsive',
			'review',
			'page_responsive_exception',
			sprintf(
				/* translators: %d: number of pages. */
				__( '%d page(s) break at widths no other page uses. Those are kept as page-specific rules rather than being forced onto the shared breakpoints.', 'replicaforge' ),
				count( (array) $response['exceptions'] )
			),
			array(
				'shared'     => (array) ( $response['shared'] ?? array() ),
				'exceptions' => (array) $response['exceptions'],
			)
		);

		return $out;
	}

	/**
	 * Find pages that failed to generate, and isolate them.
	 *
	 * §42: one failure must not destroy the others. So a failure is recorded as a
	 * finding *and* the other pages keep their state — which is checked here rather
	 * than assumed, because "the other pages are still fine" is the whole point of
	 * the requirement.
	 *
	 * @param array<int, array<string, mixed>>  $pages     Specification pages.
	 * @param array<string, array<string, mixed>> $generated Generated state.
	 * @return array<int, array<string, mixed>>
	 */
	private function generation_findings( array $pages, array $generated ) {
		$out      = array();
		$failed   = array();
		$intact   = 0;

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$page_id = (string) ( $page['page_id'] ?? '' );
			$status  = (string) ( $generated[ $page_id ]['status'] ?? ( $page['status'] ?? 'pending' ) );

			if ( 'failed' === $status ) {
				$failed[] = $page_id;
				continue;
			}
			if ( in_array( $status, array( 'generated', 'validated' ), true ) ) {
				$intact++;
			}
		}

		foreach ( $failed as $page_id ) {
			$out[] = $this->finding(
				'structure',
				'error',
				'page_generation_failed',
				sprintf(
					/* translators: 1: page title or URL, 2: number of other pages still generated. */
					__( '%1$s could not be generated. The other %2$d page(s) are unaffected and can be retried individually.', 'replicaforge' ),
					$this->page_label( $pages, $page_id ),
					$intact
				),
				array(
					'page_id'      => $page_id,
					'retryable'    => true,
					'isolation'    => 'page_only',
					'reason'       => (string) ( $generated[ $page_id ]['error'] ?? '' ),
				)
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether a correction is allowed, and how it should be applied.
	 *
	 * §46's rule: prefer correcting the shared component, and protect user
	 * overrides. So a component the user has overridden is not correctable, and one
	 * nobody has touched is.
	 *
	 * @param array<string, mixed> $component Component record.
	 * @param string                $category  Finding category.
	 * @return array<string, mixed>
	 */
	private function correction_for( array $component, $category ) {
		if ( ! empty( $component['overridden'] ) ) {
			return array(
				'eligible' => false,
				'scope'    => 'none',
				'reason'   => __( 'You have customized this component, so ReplicaForge will not change it.', 'replicaforge' ),
			);
		}

		return array(
			'eligible' => true,
			'scope'    => 'shared_component',
			'reason'   => __( 'Correcting the shared component would fix every page that uses it.', 'replicaforge' ),
		);
	}

	/**
	 * Return whether two values count as the same.
	 *
	 * @param string $left  Left.
	 * @param string $right Right.
	 * @return bool
	 */
	public function same_value( $left, $right ) {
		$left  = strtolower( trim( (string) $left ) );
		$right = strtolower( trim( (string) $right ) );

		if ( $left === $right ) {
			return true;
		}
		if ( '' === $left || '' === $right ) {
			return false;
		}

		$expanded_left  = $this->expand_hex( $left );
		$expanded_right = $this->expand_hex( $right );

		if ( '' === $expanded_left || '' === $expanded_right ) {
			// At least one side is not a colour, so compare them as measurements. The
			// *originals* are used, not the expanded values: the first draft compared
			// the expanded ones, which are `''` for a non-colour, so `is_numeric('')` was
			// always false and the measurement branch was unreachable — `12px` and
			// `12.0px` were reported as a difference.
			$left_number  = $this->measure( $left );
			$right_number = $this->measure( $right );
			if ( null !== $left_number && null !== $right_number ) {
				return abs( $left_number - $right_number ) < 0.5;
			}
			return false;
		}

		// The `#` is stripped before `sscanf()`. `sscanf( '#6c63ff', '%2x%2x%2x' )`
		// cannot match a leading hash, so it returns a non-array and *every* colour
		// comparison fell through to `return false` — which meant two pages using
		// exactly the same colour looked as different as two pages using different
		// ones, and the §45 inconsistency check had nothing to work with.
		$a = sscanf( ltrim( $expanded_left, '#' ), '%2x%2x%2x' );
		$b = sscanf( ltrim( $expanded_right, '#' ), '%2x%2x%2x' );
		if ( ! is_array( $a ) || ! is_array( $b ) || count( $a ) < 3 || count( $b ) < 3 ) {
			return false;
		}

		for ( $i = 0; $i < 3; $i++ ) {
			if ( abs( (int) $a[ $i ] - (int) $b[ $i ] ) > self::COLOR_TOLERANCE ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Return a measurement as a number, or null when it is not one.
	 *
	 * Units are stripped, because `12px` and `12.0px` are the same radius and a
	 * comparison that said otherwise would report a difference that does not exist.
	 * `rem` and `em` are deliberately *not* converted to pixels — a root font size is
	 * not known here, and guessing one would be inventing a measurement.
	 *
	 * @param string $value Value.
	 * @return float|null
	 */
	private function measure( $value ) {
		if ( ! is_numeric( $value ) ) {
			$stripped = preg_replace( '/(px|%|vh|vw)$/', '', (string) $value );
			if ( ! is_numeric( $stripped ) ) {
				return null;
			}
			$value = $stripped;
		}
		return (float) $value;
	}

	/**
	 * Expand a hex colour to six digits.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function expand_hex( $value ) {
		if ( ! preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $value, $matches ) ) {
			return '';
		}
		$hex = $matches[1];
		if ( 3 === strlen( $hex ) ) {
			return '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return '#' . strtolower( $hex );
	}

	/**
	 * Group page ids by the value they use.
	 *
	 * @param array<string, string> $observed Page id => value.
	 * @return array<string, array<int, string>>
	 */
	private function group_by_value( array $observed ) {
		$groups = array();
		foreach ( $observed as $page_id => $value ) {
			$groups[ (string) $value ][] = (string) $page_id;
		}
		return $groups;
	}

	/**
	 * Return a page's display label.
	 *
	 * @param array<int, array<string, mixed>> $pages   Pages.
	 * @param string                           $page_id Page identifier.
	 * @return string
	 */
	private function page_label( array $pages, $page_id ) {
		foreach ( $pages as $page ) {
			if ( is_array( $page ) && (string) ( $page['page_id'] ?? '' ) === (string) $page_id ) {
				$title = (string) ( $page['title'] ?? '' );
				if ( '' !== $title ) {
					return $title;
				}
				return (string) ( $page['source_url'] ?? $page_id );
			}
		}
		return (string) $page_id;
	}

	/**
	 * Build one finding.
	 *
	 * @param string                $category  Category.
	 * @param string                $severity  `error`, `warning`, or `review`.
	 * @param string                $code      Stable code.
	 * @param string                $message   Human message.
	 * @param array<string, mixed>  $evidence  Evidence.
	 * @return array<string, mixed>
	 */
	private function finding( $category, $severity, $code, $message, array $evidence = array() ) {
		return array(
			'category'  => (string) $category,
			'severity'  => (string) $severity,
			'code'      => (string) $code,
			'message'   => (string) $message,
			'evidence'  => $evidence,
			'auto_fix'  => 'no',
		);
	}
}
