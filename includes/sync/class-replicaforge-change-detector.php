<?php
/**
 * Phase 9: change detection between two source versions.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Compares two normalized source representations and reports what changed.
 *
 * The comparison runs on the *representation*, never on raw HTML. Comparing HTML
 * would report a change on every visit because of a timestamp in a footer, a
 * rotating testimonial, or a cache-busting query string, and a detector that cries
 * wolf is one a user learns to ignore — which is worse than having no detector.
 *
 * Section-level comparison is separate from component-level, and the reason is that
 * they answer different questions. "Is the hero still there" is a section question.
 * "Did the heading's font size change" is a component question. Reporting both in
 * one flat list would either bury the structural change among the typographic ones
 * or bury the typography, and §49's structured diff exists precisely because a user
 * reading a wall of `font-size changed` lines learns nothing.
 *
 * The output is a *description of what changed*. It is not a plan, it is not
 * approved, and nothing in this class can write to an Elementor document.
 */
final class Change_Detector {

	/**
	 * Component fields compared, mapped to the change category each belongs to.
	 *
	 * A field is either compared or not compared; there is no third state, because a
	 * field that is sometimes compared produces reports whose contents depend on
	 * which fields happened to be present.
	 *
	 * @var array<string, string>
	 */
	const FIELDS = array(
		'text'         => 'content',
		'label'        => 'content',
		'content'      => 'content',
		'title'        => 'content',
		'image'        => 'image',
		'src'          => 'image',
		'image_url'    => 'image',
		'icon'         => 'image',
		'font_family'  => 'typography',
		'font_size'    => 'typography',
		'font_weight'  => 'typography',
		'line_height'  => 'typography',
		'letter_spacing' => 'typography',
		'text_transform' => 'typography',
		'color'        => 'color',
		'text_color'   => 'color',
		'background'   => 'color',
		'background_color' => 'color',
		'border_color' => 'color',
		'padding'      => 'spacing',
		'margin'       => 'spacing',
		'gap'          => 'spacing',
		'width'        => 'layout',
		'max_width'    => 'layout',
		'height'       => 'layout',
		'display'      => 'layout',
		'grid_columns' => 'layout',
		'flex_direction' => 'layout',
		'columns'      => 'layout',
		'position'     => 'layout',
		'order'        => 'layout',
		'link'         => 'link',
		'href'         => 'link',
		'url'          => 'link',
		'price'        => 'product',
		'currency'     => 'product',
		'sku'          => 'product',
		'rating'       => 'product',
		'availability' => 'product',
		'author'       => 'blog',
		'date'         => 'blog',
		'excerpt'      => 'blog',
		'category'     => 'blog',
		'read_time'    => 'blog',
		'brand'        => 'theme',
		'theme_color'  => 'theme',
	);

	/**
	 * Section fields compared, mapped to their category.
	 *
	 * @var array<string, string>
	 */
	const SECTION_FIELDS = array(
		'title'    => 'content',
		'nav'      => 'navigation',
		'links'    => 'navigation',
		'items'    => 'navigation',
		'footer_links' => 'footer',
		'copyright' => 'footer',
		'columns'  => 'layout',
		'background' => 'color',
	);

	/**
	 * Maximum component fields compared for one component.
	 *
	 * Bounds the per-component cost on a component that carries a large structured
	 * field such as a long product description.
	 */
	const MAX_FIELDS = 40;

	/**
	 * Maximum changes retained in one detection run.
	 */
	const MAX_CHANGES = 500;

	/**
	 * Component matcher.
	 *
	 * @var Component_Matcher
	 */
	private $matcher;

	/**
	 * Constructor.
	 *
	 * @param Component_Matcher|null $matcher Optional matcher.
	 */
	public function __construct( $matcher = null ) {
		$this->matcher = $matcher instanceof Component_Matcher ? $matcher : new Component_Matcher();
	}

	/**
	 * Compare two source versions.
	 *
	 * @param array<string, mixed> $previous Previous version representation.
	 * @param array<string, mixed> $current  Current version representation.
	 * @param array<string, mixed> $options  Optional `ignore` field list.
	 * @return array<string, mixed>
	 */
	public function compare( array $previous, array $current, array $options = array() ) {
		$ignore = array();
		if ( isset( $options['ignore'] ) && is_array( $options['ignore'] ) ) {
			foreach ( $options['ignore'] as $field ) {
				if ( is_string( $field ) ) {
					$ignore[ strtolower( trim( $field ) ) ] = true;
				}
			}
		}

		$previous_sections = $this->sections_of( $previous );
		$current_sections  = $this->sections_of( $current );

		$section_report = $this->compare_sections( $previous_sections, $current_sections, $ignore );

		// The component comparison is global rather than per section. A component
		// that moved to another section is still the same component, and detecting
		// that requires seeing both sets at once; comparing section by section would
		// report a removal and an addition for every move.
		$previous_components = $this->components_of( $previous );
		$current_components  = $this->components_of( $current );

		$matching = $this->matcher->match( $previous_components, $current_components );
		$matched  = array();
		foreach ( $matching['matches'] as $match ) {
			$matched[ (string) $match['old_id'] ] = $match;
		}

		$changes = $section_report['changes'];
		$fields  = $this->compare_components(
			$previous_components,
			$current_components,
			$matched,
			$matching,
			$ignore
		);

		$changes = array_merge( $changes, $fields['changes'] );

		if ( count( $changes ) > self::MAX_CHANGES ) {
			$changes = array_slice( $changes, 0, self::MAX_CHANGES );
		}

		$by_category = array();
		$by_type     = array();
		foreach ( $changes as $change ) {
			$category = (string) $change['category'];
			$type     = (string) $change['type'];
			$by_category[ $category ] = ( $by_category[ $category ] ?? 0 ) + 1;
			$by_type[ $type ]         = ( $by_type[ $type ] ?? 0 ) + 1;
		}

		foreach ( Sync_Limits::CATEGORIES as $category ) {
			if ( ! isset( $by_category[ $category ] ) ) {
				$by_category[ $category ] = 0;
			}
		}
		foreach ( Sync_Limits::CHANGE_TYPES as $type ) {
			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = 0;
			}
		}

		$identical = (
			0 === (int) $by_type['added']
			&& 0 === (int) $by_type['removed']
			&& 0 === (int) $by_type['modified']
			&& 0 === (int) $by_type['moved']
		);

		return array(
			'schema_version'   => Sync_Limits::SCHEMA_VERSION,
			'changes'          => $changes,
			'count'            => count( $changes ),
			'by_category'      => $by_category,
			'by_type'          => $by_type,
			'sections'         => $section_report['sections'],
			'section_summary'  => $section_report['summary'],
			'components'       => array(
				'previous' => count( $previous_components ),
				'current'  => count( $current_components ),
				'matched'  => count( $matching['matches'] ),
				// Two bounds, reported separately: the representation may have more
				// components than the flattening cap retains, and the matcher may have
				// bounded its own comparison. They are different facts.
				'bounded'          => (bool) $matching['bounded'] || count( $previous_components ) >= Sync_Limits::MAX_COMPONENTS || count( $current_components ) >= Sync_Limits::MAX_COMPONENTS,
				'flatten_truncated' => (bool) $this->flatten_truncated,
			),
			'matching'         => $matching,
			'identical'        => $identical,
			'ignored_fields'   => array_keys( $ignore ),
			// A comparison that was cut short by a bound is not the same as a
			// complete one, and reporting it as identical would be a claim the run
			// cannot support.
			// A comparison that was cut short by any bound is not the same as a
			// complete one, and reporting it as identical would be a claim the run
			// cannot support.
			'truncated'        => (
				count( $changes ) >= self::MAX_CHANGES
				|| (bool) $matching['bounded']
				|| count( $previous_components ) >= Sync_Limits::MAX_COMPONENTS
				|| count( $current_components ) >= Sync_Limits::MAX_COMPONENTS
			),
		);
	}

	/**
	 * Compare the section structure.
	 *
	 * @param array<int, array<string, mixed>> $previous Previous sections.
	 * @param array<int, array<string, mixed>> $current  Current sections.
	 * @param array<string, bool>              $ignore    Ignored fields.
	 * @return array<string, mixed>
	 */
	private function compare_sections( array $previous, array $current, array $ignore ) {
		$matcher = $this->matcher;
		$matched = array();
		$changes = array();
		$summary = array();

		$previous_identities = array();
		foreach ( array_slice( $previous, 0, Analysis_Limits::MAX_SECTIONS ) as $index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$identity                              = $matcher->identity( $section );
			$identity['_source']                   = (string) ( $section['id'] ?? ( 'section_' . $index ) );
			$identity['_position']                 = $index;
			$previous_identities[ $identity['_source'] ] = $identity;
		}

		$current_identities = array();
		foreach ( array_slice( $current, 0, Analysis_Limits::MAX_SECTIONS ) as $index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$identity                              = $matcher->identity( $section );
			$identity['_source']                   = (string) ( $section['id'] ?? ( 'section_' . $index ) );
			$identity['_position']                 = $index;
			$current_identities[ $identity['_source'] ] = $identity;
		}

		// A section matches on identity where the ids agree, and on a content
		// fingerprint where they do not, because a site that regenerates its section
		// ids on every deploy would otherwise report every section as new.
		$taken = array();
		foreach ( $previous_identities as $source_id => $identity ) {
			if ( isset( $current_identities[ $source_id ] ) && ! isset( $taken[ $source_id ] ) ) {
				$matched[ $source_id ] = array(
					'old_id'    => $source_id,
					'new_id'    => $source_id,
					'similarity' => 1.0,
					'reasons'   => array( 'same section id' ),
				);
				$taken[ $source_id ] = true;
			}
		}

		foreach ( $previous_identities as $source_id => $identity ) {
			if ( isset( $taken[ $source_id ] ) ) {
				continue;
			}
			foreach ( $current_identities as $new_id => $new_identity ) {
				if ( isset( $taken[ $new_id ] ) ) {
					continue;
				}
				if ( empty( $identity['has_content'] ) || empty( $new_identity['has_content'] ) ) {
					continue;
				}
				if ( (string) $identity['fingerprint'] !== (string) $new_identity['fingerprint'] ) {
					continue;
				}
				$matched[ $source_id ] = array(
					'old_id'     => $source_id,
					'new_id'     => $new_id,
					'similarity' => 1.0,
					'reasons'    => array( 'identical content fingerprint' ),
				);
				$taken[ $new_id ] = true;
				break;
			}
		}

		$new_by_id = $current_identities;

		foreach ( $matched as $source_id => $match ) {
			$old = $previous_identities[ $source_id ] ?? null;
			$new = $new_by_id[ $match['new_id'] ] ?? null;
			$moved = ( null !== $old && null !== $new && (int) $old['_position'] !== (int) $new['_position'] );

			if ( $moved ) {
				$changes[] = $this->change(
					'moved',
					'section',
					$source_id,
					array(
						'old_position' => (int) $old['_position'],
						'new_position' => (int) $new['_position'],
					),
					1.0,
					array( 'the section moved to a different position' )
				);
			}

			$old_raw      = $this->find_section( $previous, $source_id );
			$new_raw      = $this->find_section( $current, $match['new_id'] );
			$field_changes = $this->compare_fields( $old_raw, $new_raw, self::SECTION_FIELDS, $ignore );

			foreach ( $field_changes as $field_change ) {
				$changes[] = $this->change(
					'modified',
					$field_change['category'],
					$source_id,
					array(
						'field'      => $field_change['field'],
						'old_value'  => $field_change['old_value'],
						'new_value'  => $field_change['new_value'],
					),
					1.0,
					array( 'the same section was matched and one of its fields differs' )
				);
			}

			$summary[ $source_id ] = array(
				'state'       => $moved ? 'moved' : 'unchanged',
				'old_position' => (int) ( $old['_position'] ?? -1 ),
				'new_position' => (int) ( $new['_position'] ?? -1 ),
				'field_changes' => count( $field_changes ),
			);
		}

		foreach ( $previous_identities as $source_id => $identity ) {
			if ( isset( $matched[ $source_id ] ) ) {
				continue;
			}
			$changes[] = $this->change(
				'removed',
				'section',
				$source_id,
				array( 'field' => null, 'old_value' => $this->summarize( $identity ), 'new_value' => null ),
				1.0,
				array( 'the section was present in the previous version and is not present in the current one' )
			);
			$summary[ $source_id ] = array( 'state' => 'removed', 'field_changes' => 0 );
		}

		foreach ( $current_identities as $new_id => $identity ) {
			if ( isset( $taken[ $new_id ] ) ) {
				continue;
			}
			$changes[] = $this->change(
				'added',
				'section',
				$new_id,
				array( 'field' => null, 'old_value' => null, 'new_value' => $this->summarize( $identity ) ),
				1.0,
				array( 'the section is present in the current version and was not present in the previous one' )
			);
			$summary[ $new_id ] = array( 'state' => 'added', 'field_changes' => 0 );
		}

		return array(
			'changes'  => $changes,
			'sections' => $summary,
			'summary'  => $summary,
		);
	}

	/**
	 * Compare matched and unmatched components.
	 *
	 * @param array<int, array<string, mixed>> $previous Previous components.
	 * @param array<int, array<string, mixed>> $current  Current components.
	 * @param array<string, array<string, mixed>> $matched Matches by old id.
	 * @param array<string, mixed>              $matching The whole matching result.
	 * @param array<string, bool>               $ignore   Ignored fields.
	 * @return array<string, mixed>
	 */
	private function compare_components( array $previous, array $current, array $matched, array $matching, array $ignore ) {
		$changes = array();

		$previous_by_id = array();
		foreach ( $previous as $index => $component ) {
			if ( is_array( $component ) ) {
				$previous_by_id[ (string) ( $component['id'] ?? ( 'old_' . $index ) ) ] = $component;
			}
		}
		$current_by_id = array();
		foreach ( $current as $index => $component ) {
			if ( is_array( $component ) ) {
				$current_by_id[ (string) ( $component['id'] ?? ( 'new_' . $index ) ) ] = $component;
			}
		}

		foreach ( $matched as $source_id => $match ) {
			$old = $previous_by_id[ $source_id ] ?? array();
			$new = $current_by_id[ (string) $match['new_id'] ] ?? array();

			if ( ! empty( $match['moved'] ) ) {
				$changes[] = $this->change(
					'moved',
					'component',
					$source_id,
					array(
						'old_section' => (string) $match['old_section'],
						'new_section' => (string) $match['new_section'],
					),
					(float) $match['match_confidence'],
					array_merge( array( 'the component appears in a different section' ), (array) $match['match_reasons'] )
				);
			}

			$field_changes = $this->compare_fields( $old, $new, self::FIELDS, $ignore );

			foreach ( $field_changes as $field_change ) {
				$changes[] = $this->change(
					'modified',
					$field_change['category'],
					$source_id,
					array(
						'field'     => $field_change['field'],
						'old_value' => $field_change['old_value'],
						'new_value' => $field_change['new_value'],
					),
					(float) $match['match_confidence'],
					array_merge(
						array( 'the component was matched and one of its fields differs' ),
						(array) $match['match_reasons']
					)
				);
			}
		}

		foreach ( $matching['removed'] as $source_id ) {
			$component = $previous_by_id[ (string) $source_id ] ?? array();
			$changes[] = $this->change(
				'removed',
				'component',
				(string) $source_id,
				array(
					'field'     => null,
					'old_value' => $this->summarize( $component ),
					'new_value' => null,
				),
				// A removal is asserted at full confidence because the component is
				// simply absent, not because of anything it was compared against.
				1.0,
				array( 'the component is present in the previous version and absent from the current one' )
			);
		}

		foreach ( $matching['added'] as $source_id ) {
			$component = $current_by_id[ (string) $source_id ] ?? array();
			$changes[] = $this->change(
				'added',
				'component',
				(string) $source_id,
				array(
					'field'     => null,
					'old_value' => null,
					'new_value' => $this->summarize( $component ),
				),
				1.0,
				array( 'the component is present in the current version and absent from the previous one' )
			);
		}

		return array( 'changes' => $changes );
	}

	/**
	 * Compare the compared fields of two arrays.
	 *
	 * A field is only reported when it is present on either side. A field absent from
	 * both is not a change, and a field absent from one and present on the other is
	 * a change — including a field that was *removed* from the source, which is a
	 * real difference a person would want to see.
	 *
	 * @param array<string, mixed> $old     Old values.
	 * @param array<string, mixed> $new     New values.
	 * @param array<string, string> $map    Field to category.
	 * @param array<string, bool>  $ignore  Ignored fields.
	 * @return array<int, array<string, mixed>>
	 */
	private function compare_fields( array $old, array $new, array $map, array $ignore ) {
		$out  = array();
		$seen = 0;

		foreach ( $map as $field => $category ) {
			if ( isset( $ignore[ $field ] ) ) {
				continue;
			}

			$has_old = array_key_exists( $field, $old );
			$has_new = array_key_exists( $field, $new );

			if ( ! $has_old && ! $has_new ) {
				continue;
			}

			$old_value = $has_old ? $old[ $field ] : null;
			$new_value = $has_new ? $new[ $field ] : null;

			// An asset field is compared by its normalized reference. A deploy that
			// appends a cache-busting query string, or a path written with dot
			// segments, is the same file, and reporting it as a changed image is the
			// single most common way a change detector trains a user to ignore it.
			//
			// Only a scalar is normalized. A structured asset value is a list of
			// references, and stringifying it would produce a notice and a nonsense
			// string, so a structured value is left to the structural comparison.
			if ( $this->is_asset_field( $field ) ) {
				if ( is_scalar( $old_value ) || null === $old_value ) {
					$old_value = Component_Matcher::normalize_asset_reference( (string) $old_value );
				}
				if ( is_scalar( $new_value ) || null === $new_value ) {
					$new_value = Component_Matcher::normalize_asset_reference( (string) $new_value );
				}
			}

			if ( $this->same( $old_value, $new_value ) ) {
				continue;
			}

			$out[] = array(
				'field'     => $field,
				'category'  => $category,
				'old_value' => $this->bound_value( $old_value ),
				'new_value' => $this->bound_value( $new_value ),
				'old_present' => $has_old,
				'new_present' => $has_new,
			);

			$seen++;
			if ( $seen >= self::MAX_FIELDS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Build one change record.
	 *
	 * @param string $type       Change type.
	 * @param string $category   Change category.
	 * @param string $source_id  Source component identifier.
	 * @param array<string, mixed> $detail  What changed.
	 * @param float $confidence  Match confidence.
	 * @param array<int, string> $reasons  Why.
	 * @return array<string, mixed>
	 */
	private function change( $type, $category, $source_id, array $detail, $confidence, array $reasons ) {
		return array(
			'type'              => Sync_Limits::is_change_type( $type ) ? $type : 'modified',
			'category'          => Sync_Limits::is_category( $category ) ? $category : 'component',
			'source_component_id' => (string) $source_id,
			'detail'            => $detail,
			'field'             => isset( $detail['field'] ) ? (string) $detail['field'] : '',
			'confidence'        => round( max( 0.0, min( 1.0, (float) $confidence ) ), 4 ),
			'evidence'          => array_slice( array_values( array_map( 'strval', $reasons ) ), 0, 8 ),
			// Severity, risk, and review are left to the classifier. Deciding them
			// here would spread the judgement across two classes that could then
			// disagree, and one place that decides is one place to reason about.
		);
	}

	/**
	 * Return whether a field holds an asset reference.
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	private function is_asset_field( $field ) {
		return in_array(
			$field,
			array( 'image', 'src', 'image_url', 'icon', 'href', 'url' ),
			true
		);
	}

	/**
	 * Return whether two values are the same for comparison.
	 *
	 * @param mixed $left  One value.
	 * @param mixed $right The other.
	 * @return bool
	 */
	private function same( $left, $right ) {
		if ( $left === $right ) {
			return true;
		}
		if ( null === $left || null === $right ) {
			return null === $left && null === $right;
		}
		if ( is_array( $left ) || is_array( $right ) ) {
			return $this->normalize_structure( $left ) === $this->normalize_structure( $right );
		}
		if ( is_bool( $left ) || is_bool( $right ) ) {
			return (bool) $left === (bool) $right;
		}
		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			return abs( (float) $left - (float) $right ) < 0.0001;
		}

		// A string is compared after trimming and after collapsing internal
		// whitespace, because a source that reformats its own markup should not
		// report every text field as changed.
		$normal = static function ( $value ) {
			return trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
		};

		return 0 === strcasecmp( $normal( $left ), $normal( $right ) );
	}

	/**
	 * Normalize a structured value for comparison.
	 *
	 * Keys are sorted so a JSON object and an array with the same contents compare
	 * equal, and scalar members are normalized by the same rules as a scalar value.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function normalize_structure( $value ) {
		if ( ! is_array( $value ) ) {
			return $this->same( $value, $value ) ? $value : trim( (string) $value );
		}
		$out = array();
		foreach ( $value as $key => $member ) {
			$out[ (string) $key ] = $this->normalize_structure( $member );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Bound a value so one change record cannot carry a page of content.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function bound_value( $value ) {
		if ( is_string( $value ) && strlen( $value ) > 300 ) {
			return substr( $value, 0, 297 ) . '...';
		}
		if ( is_array( $value ) ) {
			$encoded = wp_json_encode( $value );
			if ( is_string( $encoded ) && strlen( $encoded ) > 300 ) {
				return 'a structured value of ' . strlen( $encoded ) . ' bytes';
			}
			return $this->normalize_structure( $value );
		}
		return $value;
	}

	/**
	 * Summarize a component or section for a removal or addition record.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return array<string, mixed>
	 */
	private function summarize( array $item ) {
		$out = array();
		foreach ( array( 'role', 'component_type', 'type', 'tag', 'section_id', 'text', 'label' ) as $field ) {
			if ( isset( $item[ $field ] ) && is_scalar( $item[ $field ] ) ) {
				$out[ $field ] = $this->bound_value( $item[ $field ] );
			}
		}
		return $out;
	}

	/**
	 * Return the sections of a version.
	 *
	 * @param array<string, mixed> $version Version representation.
	 * @return array<int, array<string, mixed>>
	 */
	private function sections_of( array $version ) {
		foreach ( array( 'sections', 'page_sections' ) as $field ) {
			if ( isset( $version[ $field ] ) && is_array( $version[ $field ] ) ) {
				return array_values( array_filter( $version[ $field ], 'is_array' ) );
			}
		}
		return array();
	}

	/**
	 * Return the components of a version, flattened from their sections.
	 *
	 * @param array<string, mixed> $version Version representation.
	 * @return array<int, array<string, mixed>>
	 */
	private function components_of( array $version ) {
		$out       = array();
		$truncated = false;

		foreach ( array_slice( $this->sections_of( $version ), 0, Analysis_Limits::MAX_SECTIONS ) as $section ) {
			$section_id = (string) ( $section['id'] ?? '' );
			$this->flatten( $section, $section_id, $out, 0, $truncated );
		}

		if ( count( $this->sections_of( $version ) ) > Analysis_Limits::MAX_SECTIONS ) {
			$truncated = true;
		}

		if ( ! empty( $out ) ) {
			$this->flatten_truncated = $truncated;
			return $out;
		}

		// A version may carry a flat component list with no section structure, which
		// is what a partial analysis produces. Comparing it is better than
		// reporting no components at all.
		if ( isset( $version['components'] ) && is_array( $version['components'] ) ) {
			$flat = array_values( array_filter( $version['components'], 'is_array' ) );
			$this->flatten_truncated = ( count( $flat ) > Sync_Limits::MAX_COMPONENTS );
			return array_slice( $flat, 0, Sync_Limits::MAX_COMPONENTS );
		}

		return $out;
	}

	/**
	 * Flatten a section tree into components.
	 *
	 * @param array<string, mixed> $node      Node.
	 * @param string               $section_id Section identifier.
	 * @param array<int, array<string, mixed>> $out  Accumulator.
	 * @param int                  $depth     Current depth.
	 * @return void
	 */
	/**
	 * Whether the last component flattening stopped at the cap.
	 *
	 * @var bool
	 */
	private $flatten_truncated = false;

	/**
	 * Flatten a section tree into components, recording whether it was cut short.
	 *
	 * @param array<string, mixed>        $node       Node.
	 * @param string                       $section_id Section identifier.
	 * @param array<int, array<string, mixed>> $out     Accumulator.
	 * @param int                          $depth      Current depth.
	 * @param bool                         $truncated  Set when the cap is reached.
	 * @return void
	 */
	private function flatten( array $node, $section_id, array &$out, $depth, &$truncated ) {
		if ( $depth > Analysis_Limits::MAX_DOM_DEPTH ) {
			$truncated = true;
			return;
		}
		if ( count( $out ) >= Sync_Limits::MAX_COMPONENTS ) {
			$truncated = true;
			return;
		}

		// A node that carries comparable content is a component. A node that only
		// has children is a container and is recorded as a structural change rather
		// than a content one, so a wrapper appearing or disappearing is visible.
		$comparable = false;
		foreach ( array_keys( self::FIELDS ) as $field ) {
			if ( array_key_exists( $field, $node ) ) {
				$comparable = true;
				break;
			}
		}

		if ( $comparable || ! isset( $node['elements'] ) ) {
			$entry = $node;
			if ( '' !== $section_id && ! isset( $entry['section_id'] ) ) {
				$entry['section_id'] = $section_id;
			}
			if ( ! isset( $entry['id'] ) ) {
				$entry['id'] = 'component_' . count( $out );
			}
			$out[] = $entry;
		}

		if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
			foreach ( $node['elements'] as $child ) {
				if ( is_array( $child ) ) {
					$this->flatten( $child, $section_id, $out, $depth + 1, $truncated );
				}
			}
		}
	}

	/**
	 * Find a section by identifier.
	 *
	 * @param array<int, array<string, mixed>> $sections Sections.
	 * @param string                           $id       Section identifier.
	 * @return array<string, mixed>
	 */
	private function find_section( array $sections, $id ) {
		foreach ( $sections as $section ) {
			if ( (string) ( $section['id'] ?? '' ) === (string) $id ) {
				return $section;
			}
		}
		return array();
	}
}
