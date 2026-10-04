<?php
/**
 * Phase 12: shared component detection and registry.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the structures that repeat across pages, and gives them stable identities.
 *
 * ### Why fingerprints are not class names
 *
 * §14 is explicit that HTML class names must not be the basis, and it is right for a
 * reason that shows up immediately in practice: two pages from the same site
 * routinely use *different* class names for the same footer, because the footer is
 * rendered by a different partial, a page builder, or a cached fragment. A
 * class-based matcher reports one footer on a two-page site and then, on a
 * five-page site, reports three.
 *
 * So a fingerprint is built from four things, in descending order of reliability:
 *
 * 1. **Role** — an element in `<header>` or with a landmark role is a header on
 *    every site that has one.
 * 2. **Structure** — the shape of the element tree beneath it: how many children,
 *    how deep, which landmark roles. A footer is a `nav` inside a `footer` with a
 *    list of links, and that holds whether the class names match.
 * 3. **Style signature** — the sorted set of declared visual properties. A card with
 *    a radius, a shadow, and a background is a card.
 * 4. **Content role** — what kind of thing it contains: a list of links, a heading
 *    and a paragraph, a price and a button.
 *
 * ### What a shared component is *not*
 *
 * It is not content. A shared component is a *structure* with content slots
 * (§26). Two service pages share a template; they do not share the service. If
 * shared components carried their text, correcting a shared footer would rewrite
 * every page's footer copy, and a user's edit to one page would appear on four
 * others. So a registry entry stores the structure and the slot positions, and the
 * text lives with the page.
 */
final class Shared_Component_Detector {

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
	 * Detect the structures that repeat across pages.
	 *
	 * @param array<string, array<string, mixed>> $pages Page id => representation.
	 * @return array<string, mixed>
	 */
	public function detect( array $pages ) {
		$seen = array();      // fingerprint => [role, pages[], evidence]
		$order = array();     // first-seen order, so ids are stable across runs

		foreach ( $pages as $page_id => $representation ) {
			if ( ! is_array( $representation ) ) {
				continue;
			}
			foreach ( self::candidates_in( $representation ) as $candidate ) {
				$fingerprint = self::fingerprint( $candidate );

				if ( ! isset( $seen[ $fingerprint ] ) ) {
					$seen[ $fingerprint ] = array(
						'role'      => (string) $candidate['role'],
						'pages'     => array(),
						'evidence'  => (array) $candidate['evidence'],
						'content'   => (array) ( $candidate['content'] ?? array() ),
						'sections'  => array(),
					);
					$order[] = $fingerprint;
				}

				$entry = &$seen[ $fingerprint ];
				if ( ! in_array( (string) $page_id, $entry['pages'], true ) ) {
					$entry['pages'][] = (string) $page_id;
				}
				if ( ! in_array( (string) $candidate['section_id'], $entry['sections'], true ) ) {
					$entry['sections'][] = (string) $candidate['section_id'];
				}
				// The strongest role wins. A section typed `footer` on one page and
				// found in a landmark on another is one footer, and calling it a
				// generic section would lose the only useful fact about it.
				if ( 'section' === $entry['role'] && 'section' !== $candidate['role'] ) {
					$entry['role'] = (string) $candidate['role'];
				}
				unset( $entry );
			}
		}

		$by_role = array();
		foreach ( Site_Limits::SHARED_ROLES as $role ) {
			$by_role[ $role ] = array();
		}

		$shared = array();
		$single = 0;

		foreach ( $order as $fingerprint ) {
			$entry = $seen[ $fingerprint ];
			$role  = Site_Limits::is_shared_role( $entry['role'] ) ? $entry['role'] : 'card';
			if ( ! isset( $by_role[ $role ] ) ) {
				$by_role[ $role ] = array();
			}
			if ( count( $by_role[ $role ] ) >= Site_Limits::MAX_VARIANTS_PER_ROLE ) {
				continue;
			}

			if ( count( $entry['pages'] ) < Site_Limits::MIN_SHARED_PAGES ) {
				$single++;
				continue;
			}

			$id = self::stable_id( $role, count( $by_role[ $role ] ) );

			$record = array(
				'component_id'  => $id,
				'role'          => $role,
				'fingerprint'   => (string) $fingerprint,
				'pages'         => array_values( $entry['pages']),
				'page_count'    => count( $entry['pages']),
				'sections'      => array_values( array_slice( $entry['sections'], 0, 20 )),
				'slots'         => self::slots_for( (array) $entry['content'] ),
				'evidence'      => $entry['evidence'],
				'content'       => array(),   // §26: structure, never content
				'content_note'  => __( 'This shared component stores its structure. The text and images in it belong to each page.', 'replicaforge' ),
				'overridden'    => false,
			);

			$by_role[ $role ][] = $record['component_id'];
			$shared[]            = $record;
		}

		return array(
			'schema_version' => Site_Limits::SCHEMA_VERSION,
			'shared'         => array_slice( $shared, 0, Site_Limits::MAX_SHARED_COMPONENTS ),
			'by_role'        => $by_role,
			'count'          => count( $shared ),
			'single_use'     => $single,
			'pages'          => count( $pages ),
			'note'           => ( 0 === count( $shared ) )
				? __( 'No structure appeared on two or more pages, so nothing is treated as shared.', 'replicaforge' )
				: __( 'These structures appear on two or more pages and are reconstructed once rather than per page.', 'replicaforge' ),
		);
	}

	/**
	 * Return the candidate structures in one page.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @return array<int, array<string, mixed>>
	 */
	public static function candidates_in( array $representation ) {
		$out = array();

		$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
		foreach ( $sections as $index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$role = self::role_of( $section, (int) ( $section['order'] ?? $index ), count( $sections ) );
			if ( 'section' === $role ) {
				// A generic middle section is only a candidate if its *shape* repeats,
				// which is what the fingerprint is for. Position alone is not evidence.
				continue;
			}

			$children = isset( $section['components'] ) && is_array( $section['components'] ) ? $section['components'] : array();
			$out[]    = array(
				'role'       => $role,
				'section_id' => (string) ( $section['id'] ?? ( 'section_' . ( $index + 1 ) ) ),
				'order'      => (int) ( $section['order'] ?? $index ),
				'structure'  => self::structure_of( $children ),
				'style'      => self::style_of( $section ),
				'content'    => self::content_role_of( $children, $section ),
				'evidence'   => array(
					'section_type' => (string) ( $section['type'] ?? '' ),
					'order'        => (int) ( $section['order'] ?? $index ),
					'children'     => count( $children ),
				),
			);
		}

		// A card is a component, not a section, and a card grid is dozens of
		// candidates where the section list is one. Cards are collected separately so
		// a grid of nine does not produce nine ids.
		$components = isset( $representation['components'] ) && is_array( $representation['components'] ) ? $representation['components'] : array();
		$groups     = array();
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$type = strtolower( (string) ( $component['type'] ?? '' ) );
			if ( ! self::is_card_type( $type ) ) {
				continue;
			}
			$signature         = self::fingerprint( array(
				'role'      => 'card',
				'structure' => self::structure_of( array( $component ) ),
				'style'     => self::style_of( $component ),
				'content'   => self::content_role_of( array( $component ), array() ),
			) );
			$groups[ $signature ] = ( $groups[ $signature ] ?? 0 ) + 1;
		}
		foreach ( $groups as $signature => $count ) {
			$out[] = array(
				'role'       => 'card',
				'section_id' => 'cards',
				'order'      => 100,
				'structure'  => array( 'group' => 1, 'count' => (int) $count ),
				'style'      => array( 'signature' => (string) $signature ),
				'content'    => array( 'repeated' => (int) $count ),
				'evidence'   => array( 'grouped_cards' => (int) $count ),
			);
		}

		return $out;
	}

	/**
	 * Return a stable fingerprint.
	 *
	 * Class names are deliberately absent. Two pages rendering the same footer
	 * through different partials will not match on class names, and a class-based
	 * matcher reports one footer on a two-page site and three on a five-page site.
	 *
	 * @param array<string, mixed> $candidate Candidate.
	 * @return string
	 */
	public static function fingerprint( array $candidate ) {
		$role      = (string) ( $candidate['role'] ?? 'section' );
		$structure = (array) ( $candidate['structure'] ?? array() );

		// Sorted, so key order in the source cannot change the fingerprint.
		$structure_string = self::canonical( $structure );
		$style            = self::canonical( (array) ( $candidate['style'] ?? array() ) );
		$content          = self::canonical( (array) ( $candidate['content'] ?? array() ) );

		$material = implode( '|', array( $role, $structure_string, $style, $content ) );

		return substr( hash( 'sha256', $material ), 0, 16 );
	}

	/**
	 * Return the stable identifier for a shared component.
	 *
	 * Stable means two things: the same structural role with the same first
	 * appearance gets the same id, and the numbering follows the *role* rather than
	 * discovery order, so adding a sixth card group does not renumber the first five.
	 *
	 * @param string $role  Component role.
	 * @param int    $index Index within the role.
	 * @return string
	 */
	public static function stable_id( $role, $index ) {
		$role = Site_Limits::is_shared_role( $role ) ? $role : 'card';
		return 'shared_' . $role . ( $index > 0 ? '_' . ( $index + 1 ) : '' );
	}

	/**
	 * Return the content slots a component exposes.
	 *
	 * §26: a slot is a *position* to fill, not a value. A service template's hero
	 * title is a slot; the title text of service A is content belonging to service A.
	 *
	 * @param array<string, mixed> $content The content role map.
	 * @return array<int, array<string, mixed>>
	 */
	public static function slots_for( array $content ) {
		$slots = array();
		$roles = (array) ( $content['roles'] ?? array() );

		$slot_for = array(
			'heading'  => array( 'kind' => 'text', 'label' => __( 'Heading', 'replicaforge' ), 'repeatable' => false ),
			'text'     => array( 'kind' => 'text', 'label' => __( 'Body text', 'replicaforge' ), 'repeatable' => false ),
			'image'    => array( 'kind' => 'media', 'label' => __( 'Image', 'replicaforge' ), 'repeatable' => false ),
			'link'     => array( 'kind' => 'url', 'label' => __( 'Link', 'replicaforge' ), 'repeatable' => true ),
			'price'    => array( 'kind' => 'text', 'label' => __( 'Price', 'replicaforge' ), 'repeatable' => false ),
			'button'   => array( 'kind' => 'action', 'label' => __( 'Button', 'replicaforge' ), 'repeatable' => true ),
		);

		foreach ( $roles as $role ) {
			$key = (string) $role;
			if ( ! isset( $slot_for[ $key ] ) ) {
				continue;
			}
			$slots[ $key ] = array_merge(
				array( 'slot_id' => $key, 'filled' => false ),
				$slot_for[ $key ]
			);
		}

		return array_values( $slots );
	}

	/**
	 * Return the role of a section.
	 *
	 * Position is evidence but weak evidence, so it is only used for the two roles
	 * where position is essentially definitional: the first section of a page is the
	 * header area and the last is the footer area. Everything else needs a type or a
	 * landmark.
	 *
	 * @param array<string, mixed> $section Section.
	 * @param int                  $order   Zero-based order.
	 * @param int                  $total   Section count.
	 * @return string
	 */
	private static function role_of( array $section, $order, $total ) {
		$type = strtolower( (string) ( $section['type'] ?? '' ) );

		foreach ( Site_Limits::ROLE_SIGNALS as $role => $signals ) {
			if ( in_array( $type, $signals, true ) ) {
				return (string) $role;
			}
		}

		$landmark = strtolower( (string) ( $section['landmark'] ?? $section['role'] ?? '' ) );
		if ( '' !== $landmark ) {
			foreach ( Site_Limits::ROLE_SIGNALS as $role => $signals ) {
				if ( in_array( $landmark, $signals, true ) ) {
					return (string) $role;
				}
			}
		}

		if ( 0 === $order && $total >= 2 ) {
			return 'header';
		}
		if ( $total >= 2 && $order === ( $total - 1 ) ) {
			return 'footer';
		}

		return 'section';
	}

	/**
	 * Return the structural shape of a set of children.
	 *
	 * @param array<int, mixed> $children Children.
	 * @return array<string, mixed>
	 */
	private static function structure_of( array $children ) {
		$types = array();
		$depth = 0;
		$count = 0;

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$count++;
			$type = strtolower( (string) ( $child['type'] ?? 'div' ) );
			$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
			$own           = isset( $child['children'] ) && is_array( $child['children'] ) ? $child['children'] : array();
			if ( array() !== $own ) {
				$depth = max( $depth, 1 + self::depth_of( $own, 3 ) );
			}
		}

		ksort( $types );

		return array( 'count' => $count, 'types' => $types, 'depth' => $depth );
	}

	/**
	 * Return the depth of a child tree, bounded.
	 *
	 * @param array<int, mixed> $children Children.
	 * @param int               $budget   Remaining depth budget.
	 * @return int
	 */
	private static function depth_of( array $children, $budget ) {
		if ( $budget <= 0 ) {
			return 0;
		}
		$deepest = 0;
		foreach ( $children as $child ) {
			if ( is_array( $child ) && ! empty( $child['children'] ) && is_array( $child['children'] ) ) {
				$deepest = max( $deepest, 1 + self::depth_of( (array) $child['children'], $budget - 1 ) );
			}
		}
		return $deepest;
	}

	/**
	 * Return the style signature of an element.
	 *
	 * @param array<string, mixed> $element Element.
	 * @return array<string, mixed>
	 */
	private static function style_of( array $element ) {
		$out = array();
		foreach ( array( 'background', 'color', 'radius', 'padding', 'margin', 'shadow', 'font_family', 'font_size', 'font_weight', 'border', 'max_width' ) as $key ) {
			if ( isset( $element[ $key ] ) && is_scalar( $element[ $key ] ) ) {
				$out[ $key ] = (string) $element[ $key ];
			}
		}
		if ( isset( $element['style'] ) && is_array( $element['style'] ) ) {
			foreach ( (array) $element['style'] as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$out[ (string) $key ] = (string) $value;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Return what kind of content an element holds.
	 *
	 * @param array<int, mixed>    $children Children.
	 * @param array<string, mixed> $section  Section.
	 * @return array<string, mixed>
	 */
	private static function content_role_of( array $children, array $section ) {
		$roles   = array();
		$heading = false;
		$text    = false;
		$image   = false;
		$link    = false;
		$button  = false;
		$price   = false;

		if ( ! empty( $section['title'] ) || ! empty( $section['label'] ) ) {
			$heading = true;
		}

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$type = strtolower( (string) ( $child['type'] ?? '' ) );

			if ( in_array( $type, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'heading', 'title' ), true ) || ! empty( $child['title'] ) ) {
				$heading = true;
			}
			if ( in_array( $type, array( 'p', 'text', 'paragraph', 'body', 'description' ), true ) || ! empty( $child['text'] ) ) {
				$text = true;
			}
			if ( in_array( $type, array( 'img', 'image', 'figure', 'picture' ), true ) || ! empty( $child['image'] ) ) {
				$image = true;
			}
			if ( in_array( $type, array( 'a', 'link' ), true ) || ! empty( $child['href'] ) ) {
				$link = true;
			}
			if ( in_array( $type, array( 'button', 'btn', 'cta' ), true ) ) {
				$button = true;
				$link   = true;
			}
			if ( in_array( $type, array( 'price', 'cost' ), true ) || ! empty( $child['price'] ) ) {
				$price = true;
				$text   = true;
			}
		}

		if ( $heading ) { $roles[] = 'heading'; }
		if ( $text )    { $roles[] = 'text'; }
		if ( $image )   { $roles[] = 'image'; }
		if ( $link )    { $roles[] = 'link'; }
		if ( $button )  { $roles[] = 'button'; }
		if ( $price )   { $roles[] = 'price'; }
		$roles = array_values( array_unique( $roles ) );
		sort( $roles );

		return array( 'roles' => $roles, 'repeated' => count( $children ) );
	}

	/**
	 * Return whether a component type is a card.
	 *
	 * @param string $type Component type.
	 * @return bool
	 */
	private static function is_card_type( $type ) {
		return ( false !== strpos( $type, 'card' ) )
			|| in_array( $type, array( 'tile', 'feature', 'service_item', 'product_item', 'post_item', 'testimonial_item' ), true );
	}

	/**
	 * Return a canonical string for a nested array.
	 *
	 * Order-independent by construction rather than by convention. `array_walk_recursive`
	 * hands a *list* element to the callback with its numeric index as the key, so
	 * `array( 'link', 'text' )` and `array( 'text', 'link' )` would canonicalise to
	 * `0=link,1=text` and `0=text,1=link` — different strings for the same set. The
	 * first draft of this had that property, and the fingerprint of a footer therefore
	 * depended on the order its content roles happened to be discovered in. An
	 * integer key is now dropped so a list canonicalises to its sorted *values*, and
	 * order-independence is a property of this function rather than something every
	 * caller has to remember.
	 *
	 * @param array<string, mixed> $value Value.
	 * @return string
	 */
	private static function canonical( array $value ) {
		$flat = array();
		array_walk_recursive(
			$value,
			static function ( $item, $key ) use ( &$flat ) {
				$rendered = is_scalar( $item ) ? (string) $item : '?';
				$flat[]   = is_int( $key ) ? $rendered : (string) $key . '=' . $rendered;
			}
		);
		sort( $flat );
		return implode( ',', $flat );
	}
}
