<?php
/**
 * Phase 9: change classification and impact analysis.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Decides how much a change matters, how risky applying it is, and whether a person
 * has to look at it.
 *
 * Detection answers *what* changed. This class answers *how much that matters*, and
 * the three answers are deliberately separate rather than one severity number:
 *
 *   severity — how large the difference is on the source page
 *   risk     — how much damage applying it could do to the replica
 *   review   — whether a person must decide
 *
 * Collapsing them into one score is what makes a tool's risk list unusable. A colour
 * change is severe enough to be visible and harmless enough to apply automatically.
 * A deleted section is the reverse: it is one of the most severe things that can
 * happen and it must never be applied without a person.
 *
 * §11 says these are classifications, not assumptions about user preference, so
 * every classification carries the rule that produced it. A severity with no stated
 * basis is an opinion.
 */
final class Change_Classifier {

	/**
	 * Field categories, used to look up the weight of a specific field.
	 *
	 * @var array<string, string>
	 */
	const FIELD_WEIGHTS = array(
		'text'          => 'minor',
		'label'         => 'minor',
		'content'       => 'minor',
		'title'         => 'minor',
		'excerpt'       => 'minor',
		'read_time'     => 'none',
		'src'           => 'minor',
		'image'         => 'minor',
		'image_url'     => 'minor',
		'icon'          => 'minor',
		'link'          => 'minor',
		'href'          => 'minor',
		'url'           => 'minor',
		'font_size'     => 'moderate',
		'font_family'   => 'moderate',
		'font_weight'   => 'moderate',
		'line_height'   => 'minor',
		'letter_spacing' => 'minor',
		'text_transform' => 'minor',
		'color'         => 'moderate',
		'text_color'    => 'moderate',
		'background'    => 'moderate',
		'background_color' => 'moderate',
		'border_color'  => 'minor',
		'padding'       => 'moderate',
		'margin'        => 'moderate',
		'gap'           => 'moderate',
		'width'         => 'major',
		'max_width'     => 'major',
		'height'        => 'major',
		'display'       => 'major',
		'grid_columns'  => 'major',
		'flex_direction' => 'major',
		'columns'       => 'major',
		'position'      => 'major',
		'order'         => 'major',
		'price'         => 'major',
		'currency'      => 'major',
		'sku'           => 'minor',
		'rating'        => 'minor',
		'availability'  => 'major',
		'author'        => 'minor',
		'date'          => 'minor',
		'category'      => 'minor',
		'brand'         => 'major',
		'theme_color'   => 'major',
	);

	/**
	 * Category baselines, used when a change has no single field behind it.
	 *
	 * @var array<string, string>
	 */
	const CATEGORY_WEIGHTS = array(
		'content'    => 'minor',
		'image'      => 'minor',
		'asset'      => 'minor',
		'typography' => 'moderate',
		'color'      => 'moderate',
		'spacing'    => 'moderate',
		'layout'     => 'major',
		'component'  => 'minor',
		'section'    => 'major',
		'navigation' => 'major',
		'footer'     => 'moderate',
		'responsive' => 'major',
		'interaction' => 'major',
		'link'       => 'minor',
		'product'    => 'major',
		'blog'       => 'minor',
		'theme'      => 'major',
	);

	/**
	 * Risk assigned to a change type before its fields are considered.
	 *
	 * A removal is at least high whatever it removes. That is the asymmetry that
	 * makes an automatic sync safe: adding a missing value can be undone by
	 * removing it, and removing an existing one cannot be undone without a
	 * snapshot.
	 *
	 * @var array<string, string>
	 */
	const TYPE_RISK = array(
		'added'     => 'low',
		'modified'  => 'low',
		'moved'     => 'medium',
		'removed'   => 'high',
		'unchanged' => 'low',
	);

	/**
	 * Severity assigned to a change type on its own.
	 *
	 * @var array<string, string>
	 */
	const TYPE_SEVERITY = array(
		'added'     => 'moderate',
		'modified'  => 'minor',
		'moved'     => 'moderate',
		'removed'   => 'major',
		'unchanged' => 'none',
	);

	/**
	 * Categories that can never be applied automatically, whatever the risk.
	 *
	 * §25's list, plus `product`, because a price is a number a person is legally
	 * and commercially responsible for and ReplicaForge has no business changing it
	 * unattended.
	 *
	 * @var array<int, string>
	 */
	const NEVER_AUTOMATIC = array( 'section', 'navigation', 'interaction', 'theme', 'product' );

	/**
	 * Classify one change.
	 *
	 * @param array<string, mixed> $change Detected change.
	 * @return array<string, mixed>
	 */
	public function classify( array $change ) {
		$type     = Sync_Limits::is_change_type( $change['type'] ?? '' ) ? (string) $change['type'] : 'modified';
		$category = Sync_Limits::is_category( $change['category'] ?? '' ) ? (string) $change['category'] : 'component';
		$field    = isset( $change['field'] ) ? strtolower( trim( (string) $change['field'] ) ) : '';
		$confidence = isset( $change['confidence'] ) ? max( 0.0, min( 1.0, (float) $change['confidence'] ) ) : 1.0;

		$severity = $this->severity_for( $type, $category, $field );
		$risk     = $this->risk_for( $type, $category, $field, $severity, $confidence );
		$review   = $this->requires_review( $type, $category, $field, $risk, $confidence );

		$out = $change;

		// The normalised values are written back rather than only used for the
		// classification. A caller stores what leaves this method, and a record whose
		// type reads "nonsense" is not one anything downstream can act on.
		$out['type']      = $type;
		$out['category']  = $category;
		$out['field']     = ( '' !== $field && isset( self::FIELD_WEIGHTS[ $field ] ) ) ? $field : '';
		$out['severity']  = $severity;
		$out['risk']      = $risk;
		$out['review']    = $review;
		$out['basis']     = $this->basis( $type, $category, $field, $severity, $risk, $review );
		// A change is only auto-appliable when the risk is low, the confidence is
		// high, the category is on the narrow list, and it is not a removal. All four
		// are required, so adding a condition can only ever reduce what is automatic.
		$out['auto']      = $this->is_auto_safe( $type, $category, $risk, $confidence, $review );

		return $out;
	}

	/**
	 * Classify a whole set of changes.
	 *
	 * @param array<int, array<string, mixed>> $changes Detected changes.
	 * @return array<string, mixed>
	 */
	public function classify_all( array $changes ) {
		$classified = array();
		$by_severity = array_fill_keys( Sync_Limits::SEVERITIES, 0 );
		$by_risk     = array_fill_keys( Sync_Limits::RISKS, 0 );
		$by_category = array();
		$review      = 0;
		$auto        = 0;

		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			$entry            = $this->classify( $change );
			$classified[]     = $entry;
			$by_severity[ $entry['severity'] ]++;
			$by_risk[ $entry['risk'] ]++;
			$category         = (string) $entry['category'];
			$by_category[ $category ] = ( $by_category[ $category ] ?? 0 ) + 1;
			if ( $entry['review'] ) {
				$review++;
			}
			if ( $entry['auto'] ) {
				$auto++;
			}
		}

		return array(
			'changes'     => $classified,
			'count'       => count( $classified ),
			'by_severity' => $by_severity,
			'by_risk'     => $by_risk,
			'by_category' => $by_category,
			'review'      => $review,
			'auto'        => $auto,
			// A grouped summary, so §50's request is met by the data rather than
			// by the screen: three typography changes on one component are one
			// "Hero typography changed" row with three details inside it.
			'groups'      => $this->group( $classified ),
		);
	}

	/**
	 * Group related changes for display.
	 *
	 * @param array<int, array<string, mixed>> $changes Classified changes.
	 * @return array<int, array<string, mixed>>
	 */
	public function group( array $changes ) {
		$groups = array();

		foreach ( $changes as $change ) {
			$component = (string) ( $change['source_component_id'] ?? '' );
			$category  = (string) $change['category'];

			// One group per component and category. Three typography changes on the
			// hero heading are one thing a person thinks about, and splitting them
			// into three rows makes the group read as three separate problems.
			$key = $component . '|' . $category;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'key'          => $key,
					'component_id' => $component,
					'category'     => $category,
					'fields'       => array(),
					'count'        => 0,
					'severity'     => 'none',
					'risk'         => 'low',
					'review'       => false,
				);
			}

			$groups[ $key ]['count']++;
			if ( '' !== (string) ( $change['field'] ?? '' ) ) {
				$groups[ $key ]['fields'][] = (string) $change['field'];
			}
			$groups[ $key ]['review'] = $groups[ $key ]['review'] || (bool) $change['review'];

			if ( Sync_Limits::severity_rank( $change['severity'] ) > Sync_Limits::severity_rank( $groups[ $key ]['severity'] ) ) {
				$groups[ $key ]['severity'] = (string) $change['severity'];
			}
			if ( Sync_Limits::risk_rank( $change['risk'] ) > Sync_Limits::risk_rank( $groups[ $key ]['risk'] ) ) {
				$groups[ $key ]['risk'] = (string) $change['risk'];
			}
		}

		$out = array_values( $groups );
		usort(
			$out,
			function ( $left, $right ) {
				$by_risk = Sync_Limits::risk_rank( $right['risk'] ) <=> Sync_Limits::risk_rank( $left['risk'] );
				if ( 0 !== $by_risk ) {
					return $by_risk;
				}
				return Sync_Limits::severity_rank( $right['severity'] ) <=> Sync_Limits::severity_rank( $left['severity'] );
			}
		);

		return $out;
	}

	/**
	 * Return the severity of a change.
	 *
	 * @param string $type     Change type.
	 * @param string $category Category.
	 * @param string $field    Field name.
	 * @return string
	 */
	public function severity_for( $type, $category, $field ) {
		// A field weight is the most specific evidence available, so it wins over
		// the category and over the type.
		if ( '' !== $field && isset( self::FIELD_WEIGHTS[ $field ] ) ) {
			return $this->raise_severity( self::FIELD_WEIGHTS[ $field ], self::TYPE_SEVERITY[ $type ] ?? 'minor' );
		}

		if ( 'removed' === $type ) {
			// A removal is at least major whatever it removes. A deleted paragraph
			// and a deleted section are not the same size of loss, and both are
			// beyond minor.
			return 'major';
		}

		if ( isset( self::CATEGORY_WEIGHTS[ $category ] ) ) {
			return $this->raise_severity( self::CATEGORY_WEIGHTS[ $category ], self::TYPE_SEVERITY[ $type ] ?? 'minor' );
		}

		return self::TYPE_SEVERITY[ $type ] ?? 'minor';
	}

	/**
	 * Return the risk of applying a change.
	 *
	 * @param string $type       Change type.
	 * @param string $category   Category.
	 * @param string $field      Field name.
	 * @param string $severity   Severity.
	 * @param float  $confidence Match confidence.
	 * @return string
	 */
	public function risk_for( $type, $category, $field, $severity, $confidence ) {
		$risk = self::TYPE_RISK[ $type ] ?? 'low';

		if ( in_array( $category, self::NEVER_AUTOMATIC, true ) ) {
			// These categories are never automatic whatever their risk would be,
			// because the harm from being wrong is not expressible as a risk level.
			// A restructured navigation is not "high risk" in a way a number
			// conveys; it is a category a person owns.
			return 'high';
		}

		if ( 'responsive' === $category ) {
			// A responsive change is invisible in a single-viewport screenshot and
			// is easy to miss, so it is treated as more dangerous than its severity
			// suggests.
			$risk = $this->raise_risk( $risk, 'medium' );
		}

		if ( Sync_Limits::severity_rank( $severity ) >= Sync_Limits::severity_rank( 'major' ) ) {
			$risk = $this->raise_risk( $risk, 'medium' );
		}

		// A change matched at low confidence is risky because applying it might
		// address the wrong component entirely.
		if ( $confidence < 0.75 ) {
			$risk = $this->raise_risk( $risk, 'medium' );
		}

		if ( 'removed' === $type ) {
			$risk = 'high';
		}

		return $risk;
	}

	/**
	 * Return whether a change must be reviewed by a person.
	 *
	 * @param string $type       Change type.
	 * @param string $category   Category.
	 * @param string $field      Field name.
	 * @param string $risk       Risk.
	 * @param float  $confidence Match confidence.
	 * @return bool
	 */
	public function requires_review( $type, $category, $field, $risk, $confidence ) {
		// A removal destroys work. Nothing about a removal is ever automatic.
		if ( in_array( $type, Sync_Limits::DESTRUCTIVE_TYPES, true ) ) {
			return true;
		}

		// A category on the always-review list is a category where being wrong is
		// expensive in a way the risk score does not express.
		if ( in_array( $category, Sync_Limits::ALWAYS_REVIEW_CATEGORIES, true ) ) {
			return true;
		}

		if ( in_array( $category, self::NEVER_AUTOMATIC, true ) ) {
			return true;
		}

		// Anything above the lowest risk needs a person.
		if ( 'low' !== $risk ) {
			return true;
		}

		// Not knowing what was matched is not a reason to act.
		if ( $confidence < Sync_Limits::MIN_AUTO_CONFIDENCE ) {
			return true;
		}

		return false;
	}

	/**
	 * Return whether a change may be applied with no decision at all.
	 *
	 * @param string $type       Change type.
	 * @param string $category   Category.
	 * @param string $risk       Risk.
	 * @param float  $confidence Match confidence.
	 * @param bool   $review     Whether review is required.
	 * @return bool
	 */
	public function is_auto_safe( $type, $category, $risk, $confidence, $review ) {
		// Every condition is checked, so adding one can only reduce what is
		// automatic. The ordering is cheapest-rejection-first, which is also the
		// order a reader would want the reasons in.
		if ( in_array( $type, Sync_Limits::DESTRUCTIVE_TYPES, true ) ) {
			return false;
		}
		if ( in_array( $category, Sync_Limits::AUTO_SAFE_CATEGORIES, true ) !== true ) {
			return false;
		}
		if ( Sync_Limits::risk_rank( $risk ) > Sync_Limits::risk_rank( Sync_Limits::AUTO_SAFE_RISK_CEILING ) ) {
			return false;
		}
		if ( $confidence < Sync_Limits::MIN_AUTO_CONFIDENCE ) {
			return false;
		}
		if ( $review ) {
			return false;
		}
		return true;
	}

	/**
	 * Return the rules that produced a classification.
	 *
	 * @param string $type     Change type.
	 * @param string $category Category.
	 * @param string $field    Field name.
	 * @param string $severity Severity.
	 * @param string $risk     Risk.
	 * @param bool   $review   Review requirement.
	 * @return array<int, string>
	 */
	private function basis( $type, $category, $field, $severity, $risk, $review ) {
		$out = array();

		if ( '' !== $field && isset( self::FIELD_WEIGHTS[ $field ] ) ) {
			$out[] = 'the field ' . $field . ' carries a ' . self::FIELD_WEIGHTS[ $field ] . ' severity on its own';
		} else {
			$out[] = 'no field weight applied, so the category weight was used';
		}
		if ( 'removed' === $type ) {
			$out[] = 'a removal is at least a major change whatever it removes';
		}
		if ( in_array( $category, self::NEVER_AUTOMATIC, true ) ) {
			$out[] = 'the ' . $category . ' category is never applied automatically';
		}
		if ( 'high' === $risk ) {
			$out[] = 'the risk is high, so a person decides';
		}
		if ( $review ) {
			$out[] = 'review is required';
		}

		return $out;
	}

	/**
	 * Return the higher of two severities.
	 *
	 * @param string $left  One severity.
	 * @param string $right The other.
	 * @return string
	 */
	private function raise_severity( $left, $right ) {
		return Sync_Limits::severity_rank( $left ) >= Sync_Limits::severity_rank( $right )
			? (string) $left
			: (string) $right;
	}

	/**
	 * Return the higher of two risk levels.
	 *
	 * @param string $left  One risk level.
	 * @param string $right The other.
	 * @return string
	 */
	private function raise_risk( $left, $right ) {
		return Sync_Limits::risk_rank( $left ) >= Sync_Limits::risk_rank( $right )
			? (string) $left
			: (string) $right;
	}
}
