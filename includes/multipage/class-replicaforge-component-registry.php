<?php
/**
 * Phase 12: shared component registry and page templates.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the shared components and templates a website has, and resolves which pages
 * each one touches.
 *
 * ### What a registry is for
 *
 * Detection produces candidates. The registry is the *stable identity* those
 * candidates acquire: a component detected on six pages is `shared_header` on all
 * six, and correcting it once corrects all six. Without a registry, each page
 * carries its own copy of the header, a user edits one, and the "shared" header
 * differs per page — which is the exact problem §63 exists to expose.
 *
 * ### Overrides are never silently replaced
 *
 * §47 requires that a user edit survives a later sync. That means the registry is
 * the *authority* on what is user-owned, and any regeneration has to ask it. The
 * `overridden` flag is therefore recorded at the moment of override and consulted
 * before every write, rather than being recomputed from whether the current content
 * happens to differ from the source.
 */
final class Component_Registry {

	/**
	 * Store option.
	 *
	 * @var string
	 */
	const OPTION = 'replicaforge_site_registry';

	/**
	 * Registry.
	 *
	 * @var array<string, mixed>
	 */
	private $registry = array();

	/**
	 * Whether the store was read.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * The project this registry currently holds.
	 *
	 * Added in Phase 13, and it fixes a real bug rather than tidying one up.
	 *
	 * `registry()` used to lazily `load( '' )` when it had not been read yet, and
	 * `put_shared( $project_id, ... )` called `registry()` before writing. So it read
	 * the bucket for the *empty* project id while writing the bucket for the real
	 * one. Two consequences, both bad:
	 *
	 * 1. **An override was lost on re-analysis.** `put_shared` could not see the
	 *    existing components for the project it was updating, so it could not see
	 *    which of them the user had edited, and could not preserve them. That
	 *    defeats §47, which requires a user edit to survive a later sync.
	 * 2. **An override leaked between projects.** `mark_overridden()` saved to
	 *    `$registry['project_id']`, which nothing ever set, so it fell back to `''` -
	 *    a bucket shared by every project. One project's user edit then marked an
	 *    identically-named component in *another* project as user-owned, permanently
	 *    blocking correction of it there.
	 *
	 * The registry now records which project it holds, `load()` and `save()` set it,
	 * and `put_shared()` reads the project it is about to write. Both directions are
	 * fixed by the same change because both were the same mistake.
	 *
	 * @var string
	 */
	private $project_id = '';

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

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------ */

	/**
	 * Read the registry.
	 *
	 * @param string $project_id Project identifier.
	 * @return array<string, mixed>
	 */
	public function load( $project_id ) {
		$this->loaded    = true;
		$project_id      = (string) $project_id;
		$this->project_id = $project_id;
		$all             = get_option( self::OPTION, array() );
		$all             = is_array( $all ) ? $all : array();
		$this->registry   = ( isset( $all[ $project_id ] ) && is_array( $all[ $project_id ] ) ) ? $all[ $project_id ] : array();
		return $this->registry;
	}

	/**
	 * Load a project's registry unless a different project is already loaded.
	 *
	 * The `unless` is the point. `registry()` is called by writers as well as
	 * readers, and a writer must never silently switch to whichever project was
	 * loaded first - that was the bug described on {@see self::$project_id}. A writer
	 * that names a project gets it, and a writer that does not gets whatever is
	 * already in memory.
	 *
	 * @param string $project_id Project identifier, or an empty string to reuse.
	 * @return array<string, mixed>
	 */
	private function registry_for( $project_id ) {
		$project_id = (string) $project_id;
		if ( $project_id !== '' && $project_id !== $this->project_id ) {
			$this->load( $project_id );
		}
		if ( ! $this->loaded ) {
			$this->load( '' );
		}
		return $this->registry;
	}

	/**
	 * Persist the registry.
	 *
	 * @param string                  $project_id Project identifier.
	 * @param array<string, mixed>    $registry   Registry.
	 * @return bool
	 */
	public function save( $project_id, array $registry ) {
		$project_id = (string) $project_id;
		$all        = get_option( self::OPTION, array() );
		$all        = is_array( $all ) ? $all : array();

		// Bound the number of projects kept. A registry per project is small, but
		// "unbounded" is a decision someone has to make and this makes it now.
		if ( ! isset( $all[ $project_id ] ) && count( $all ) >= 40 ) {
			$all = array_slice( $all, -39, null, true );
		}

		// The project id is stamped into the record as well as used as the bucket
		// key, so a later `mark_overridden()` knows where to write back to. Previously
		// it read this key, found it absent, and fell back to the shared empty
		// bucket - which is how one project's user edit ended up owning another
		// project's component.
		$registry['project_id'] = $project_id;

		$all[ $project_id ] = $registry;
		update_option( self::OPTION, $all, false );
		$this->registry      = $registry;
		$this->project_id    = $project_id;
		$this->loaded        = true;

		return true;
	}

	/**
	 * Forget a project's registry.
	 *
	 * @param string $project_id Project identifier.
	 * @return bool
	 */
	public function delete( $project_id ) {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		unset( $all[ (string) $project_id ] );
		update_option( self::OPTION, $all, false );

		// The in-memory copy is reset as a *unit*. Clearing only `$this->registry`
		// left `$this->loaded` true and `$this->project_id` pointing at the deleted
		// project, so the next reader got an empty registry believing it was loaded
		// and the next writer - `mark_overridden()` - re-created the project that had
		// just been deleted.
		if ( (string) $project_id === $this->project_id ) {
			$this->registry   = array();
			$this->project_id = '';
			$this->loaded     = false;
		}

		return true;
	}

	/**
	 * Return the loaded registry.
	 *
	 * @return array<string, mixed>
	 */
	public function registry() {
		if ( ! $this->loaded ) {
			$this->load( '' );
		}
		return $this->registry;
	}

	/* ---------------------------------------------------------------------
	 * Shared components
	 * ------------------------------------------------------------------ */

	/**
	 * Store detected shared components, preserving user overrides.
	 *
	 * @param string                        $project_id Project identifier.
	 * @param array<int, array<string, mixed>> $shared   Detected components.
	 * @return array<string, mixed> Summary.
	 */
	public function put_shared( $project_id, array $shared ) {
		// Read the project this call is about to *write*, not whatever project happens
		// to be in memory. Reading the wrong bucket is why a user override could be
		// discarded by a re-analysis; see {@see self::$project_id}.
		$registry = $this->registry_for( $project_id );
		$existing = isset( $registry['shared'] ) && is_array( $registry['shared'] ) ? $registry['shared'] : array();
		$stored   = array();
		$kept     = 0;
		$dropped  = 0;

		foreach ( array_slice( $shared, 0, Site_Limits::MAX_SHARED_COMPONENTS ) as $component ) {
			if ( ! is_array( $component ) || empty( $component['component_id'] ) ) {
				continue;
			}
			$id = (string) $component['component_id'];

			if ( isset( $existing[ $id ] ) && is_array( $existing[ $id ] ) && ! empty( $existing[ $id ]['overridden'] ) ) {
				// The user owns this. The *identity* and the page list are refreshed
				// so a newly discovered page still resolves to the same component,
				// but nothing the user touched is replaced.
				$component['overridden']      = true;
				$component['override']        = $existing[ $id ]['override'];
				$component['pages']           = array_values(
					array_unique(
						array_merge(
							(array) ( $component['pages'] ?? array() ),
							(array) ( $existing[ $id ]['pages'] ?? array() )
						)
					)
				);
				$component['page_count']      = count( $component['pages'] );
				$component['user_content']    = isset( $existing[ $id ]['user_content'] ) ? $existing[ $id ]['user_content'] : array();
				$component['user_styles']     = isset( $existing[ $id ]['user_styles'] ) ? $existing[ $id ]['user_styles'] : array();
				$component['note']            = __( 'You have customized this shared component, so your version has been kept.', 'replicaforge' );
				$kept++;
			}

			$stored[ $id ] = $component;
		}

		// Components that vanished are *marked* rather than deleted, because a
		// component absent from this pass may be absent because one page failed to
		// analyze, and deleting it would discard an override on a transient absence.
		foreach ( $existing as $id => $old ) {
			if ( isset( $stored[ $id ] ) ) {
				continue;
			}
			if ( ! empty( $old['overridden'] ) ) {
				$old['stale'] = true;
				$old['note']  = __( 'Not found in the latest analysis. Kept because you have customized it.', 'replicaforge' );
				$stored[ $id ] = $old;
				$kept++;
				continue;
			}
			$dropped++;
		}

		$registry['shared']     = $stored;
		$registry['updated_at'] = time();
		$this->save( $project_id, $registry );

		return array(
			'stored'  => count( $stored ),
			'kept'    => $kept,
			'dropped' => $dropped,
		);
	}

	/**
	 * Return the shared components.
	 *
	 * @param string $role Optional role filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function shared( $role = '' ) {
		$registry = $this->registry();
		$out      = array();
		foreach ( (array) ( $registry['shared'] ?? array() ) as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			if ( '' !== $role && (string) ( $component['role'] ?? '' ) !== (string) $role ) {
				continue;
			}
			$out[] = $component;
		}
		return $out;
	}

	/**
	 * Return one shared component.
	 *
	 * @param string $component_id Component identifier.
	 * @return array<string, mixed>|null
	 */
	public function find_shared( $component_id ) {
		$registry = $this->registry();
		$component_id = (string) $component_id;
		return ( isset( $registry['shared'][ $component_id ] ) && is_array( $registry['shared'][ $component_id ] ) )
			? $registry['shared'][ $component_id ]
			: null;
	}

	/**
	 * Return the components a page uses.
	 *
	 * @param string $page_id Page identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function components_for_page( $page_id ) {
		$page_id = (string) $page_id;
		$out     = array();
		foreach ( $this->shared() as $component ) {
			if ( in_array( $page_id, (array) ( $component['pages'] ?? array() ), true ) ) {
				$out[] = $component;
			}
		}
		return $out;
	}

	/**
	 * Record a user override of a shared component.
	 *
	 * @param string                     $component_id Component identifier.
	 * @param array<string, mixed>       $override     What the user set.
	 * @return array<string, mixed>
	 */
	public function mark_overridden( $component_id, array $override ) {
		// `registry_for( '' )` reuses the already-loaded project rather than
		// switching to the empty bucket, so the override lands in the project the
		// caller actually loaded.
		$registry = $this->registry_for( '' );
		$id       = (string) $component_id;

		if ( ! isset( $registry['shared'][ $id ] ) || ! is_array( $registry['shared'][ $id ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'That shared component is not in this project\'s registry.', 'replicaforge' ),
			);
		}

		$registry['shared'][ $id ]['overridden']   = true;
		$registry['shared'][ $id ]['override']     = $override;
		$registry['shared'][ $id ]['overridden_at'] = time();
		$registry['shared'][ $id ]['stale']        = false;
		$this->save( (string) ( $registry['project_id'] ?? '' ), $registry );

		return array(
			'success' => true,
			'component' => $registry['shared'][ $id ],
			'message' => __( 'Your customization is recorded and will not be replaced by a later sync.', 'replicaforge' ),
		);
	}

	/**
	 * Return whether a component may be rewritten.
	 *
	 * The check every write path makes. It is a method rather than a bare flag read
	 * so that a caller cannot forget it, and so the answer is testable on its own.
	 *
	 * @param string $component_id Component identifier.
	 * @return bool
	 */
	public function may_rewrite( $component_id ) {
		$component = $this->find_shared( $component_id );
		if ( null === $component ) {
			return true;
		}
		return empty( $component['overridden'] );
	}

	/* ---------------------------------------------------------------------
	 * Templates
	 * ------------------------------------------------------------------ */

	/**
	 * Detect the page templates a website has.
	 *
	 * §24 asks for repeated page *structures*. A template is a section signature
	 * shared by several pages of one type: five service pages with hero, intro,
	 * features, CTA, footer are one template and five instances of it.
	 *
	 * The signature is the ordered list of section types, normalised. Content is
	 * excluded on purpose — §25 says a template must not carry accidental page
	 * content, and a signature containing text would guarantee it did.
	 *
	 * @param array<string, array<string, mixed>> $pages Page id => representation.
	 * @return array<string, mixed>
	 */
	public function detect_templates( array $pages ) {
		$groups = array();
		$order  = array();

		foreach ( $pages as $page_id => $record ) {
			$representation = is_array( $record ) && isset( $record['representation'] ) && is_array( $record['representation'] )
				? $record['representation']
				: ( is_array( $record ) ? $record : array() );
			$type = is_array( $record ) && isset( $record['type'] ) ? (string) $record['type'] : 'custom';

			$sections = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
			if ( count( $sections ) < Site_Limits::MIN_TEMPLATE_SECTIONS ) {
				continue;
			}

			$signature = array();
			foreach ( $sections as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				$signature[] = strtolower( (string) ( $section['type'] ?? 'section' ) );
			}
			if ( array() === $signature ) {
				continue;
			}
			$signature = implode( '>', $signature );
			$structure = explode( '>', $signature );

			if ( ! isset( $groups[ $signature ] ) ) {
				$groups[ $signature ] = array(
					'signature' => $signature,
					'sections'  => count( $structure ),
					'pages'     => array(),
					'types'     => array(),
				);
				$order[] = $signature;
			}
			$groups[ $signature ]['pages'][] = (string) $page_id;
			$groups[ $signature ]['types'][ $type ] = ( $groups[ $signature ]['types'][ $type ] ?? 0 ) + 1;
		}

		$templates = array();
		$per_type  = array();

		foreach ( $order as $signature ) {
			$group = $groups[ $signature ];
			if ( count( $group['pages'] ) < 2 ) {
				continue;
			}
			arsort( $group['types'] );
			$page_type = (string) array_key_first( $group['types'] );
			$name      = self::template_name( $page_type, count( $per_type ) );

			$template = array(
				'template_id' => $name,
				'page_type'   => $page_type,
				'signature'   => (string) $signature,
				'structure'   => $structure,
				'sections'    => count( $structure ),
				'pages'       => array_values( $group['pages'] ),
				'page_count'  => count( $group['pages'] ),
				'slots'       => self::template_slots( $page_type ),
				'tokens'      => array(),
				'responsive'  => array(),
				'overridden'  => false,
				'generated'   => false,
				'evidence'    => array(
					'pages_agree' => count( $group['pages'] ),
					'note'        => __( 'These pages have the same section structure in the same order.', 'replicaforge' ),
				),
			);

			$templates[]                        = $template;
			$per_type[ $page_type ]             = ( $per_type[ $page_type ] ?? 0 ) + 1;
		}

		return array(
			'schema_version' => Site_Limits::SCHEMA_VERSION,
			'templates'     => array_slice( $templates, 0, Site_Limits::MAX_TEMPLATES ),
			'count'         => count( array_slice( $templates, 0, Site_Limits::MAX_TEMPLATES ) ),
			'note'          => ( 0 === count( $templates ) )
				? __( 'No two pages shared a section structure, so no template was identified.', 'replicaforge' )
				: __( 'A template is a structure. The text and images in it belong to each page that uses it.', 'replicaforge' ),
		);
	}

	/**
	 * Return the content slots a template exposes.
	 *
	 * Derived from the page type, not from the first page found. A hero title on
	 * service A and a hero title on service B are the *same slot* because they have
	 * the same job, and deriving slots per instance would produce a template with
	 * one slot that happens to hold service A's text.
	 *
	 * @param string $page_type Page type.
	 * @return array<int, array<string, mixed>>
	 */
	public static function template_slots( $page_type ) {
		$common = array(
			array( 'slot_id' => 'title',       'kind' => 'text',  'label' => __( 'Page title', 'replicaforge' ), 'repeatable' => false ),
			array( 'slot_id' => 'headings',    'kind' => 'text',  'label' => __( 'Section headings', 'replicaforge' ), 'repeatable' => true ),
			array( 'slot_id' => 'body',        'kind' => 'text',  'label' => __( 'Body text', 'replicaforge' ), 'repeatable' => true ),
			array( 'slot_id' => 'images',      'kind' => 'media', 'label' => __( 'Images', 'replicaforge' ), 'repeatable' => true ),
		);

		$by_type = array(
			'service_detail' => array(
				array( 'slot_id' => 'hero_title', 'kind' => 'text',   'label' => __( 'Hero title', 'replicaforge' ), 'repeatable' => false ),
				array( 'slot_id' => 'hero_image','kind' => 'media',  'label' => __( 'Hero image', 'replicaforge' ), 'repeatable' => false ),
				array( 'slot_id' => 'summary',    'kind' => 'text',   'label' => __( 'Summary', 'replicaforge' ), 'repeatable' => false ),
				array( 'slot_id' => 'features',   'kind' => 'text',   'label' => __( 'Features', 'replicaforge' ), 'repeatable' => true ),
				array( 'slot_id' => 'cta_label',  'kind' => 'action', 'label' => __( 'Call to action', 'replicaforge' ), 'repeatable' => false ),
			),
			'product' => array(
				array( 'slot_id' => 'product_name',  'kind' => 'text', 'label' => __( 'Product name', 'replicaforge' ), 'repeatable' => false ),
				array( 'slot_id' => 'price',         'kind' => 'text', 'label' => __( 'Price', 'replicaforge' ), 'repeatable' => false ),
				array( 'slot_id' => 'gallery',       'kind' => 'media','label' => __( 'Gallery', 'replicaforge' ), 'repeatable' => true ),
				array( 'slot_id' => 'variants',      'kind' => 'text', 'label' => __( 'Variants', 'replicaforge' ), 'repeatable' => true ),
				array( 'slot_id' => 'add_to_cart',   'kind' => 'action','label' => __( 'Add to cart', 'replicaforge' ), 'repeatable' => false ),
			),
			'blog_post' => array(
				array( 'slot_id' => 'post_title',   'kind' => 'text',  'label' => __( 'Post title', 'replicaforge' ), 'repeatable' => false ),
				array( 'slot_id' => 'author',       'kind' => 'text',  'label' => __( 'Author', 'replicaforge' ), 'repeatable' => false ),
				array( 'slot_id' => 'date',         'kind' => 'text',  'label' => __( 'Date', 'replicaforge' ), 'repeatable' => false ),
				array( 'slot_id' => 'body',         'kind' => 'text',  'label' => __( 'Post body', 'replicaforge' ), 'repeatable' => false ),
				array( 'slot_id' => 'featured',     'kind' => 'media', 'label' => __( 'Featured image', 'replicaforge' ), 'repeatable' => false ),
			),
			'project_detail' => array(
				array( 'slot_id' => 'project_title', 'kind' => 'text',  'label' => __( 'Project title', 'replicaforge' ), 'repeatable' => false ),
				array( 'slot_id' => 'gallery',       'kind' => 'media', 'label' => __( 'Gallery', 'replicaforge' ), 'repeatable' => true ),
				array( 'slot_id' => 'summary',       'kind' => 'text',  'label' => __( 'Summary', 'replicaforge' ), 'repeatable' => false ),
			),
		);

		if ( isset( $by_type[ $page_type ] ) ) {
			return $by_type[ $page_type ];
		}

		return $common;
	}

	/**
	 * Return a template's name.
	 *
	 * @param string $page_type Page type.
	 * @param int    $index     Index within the type.
	 * @return string
	 */
	public static function template_name( $page_type, $index ) {
		$key = Site_Limits::TEMPLATE_TYPES[ $page_type ][0] ?? ( Site_Limits::is_page_type( $page_type ) ? $page_type : 'page' );
		return 'template_' . $key . ( $index > 0 ? '_' . ( $index + 1 ) : '' );
	}

	/**
	 * Store detected templates.
	 *
	 * @param string                       $project_id Project identifier.
	 * @param array<string, mixed>         $templates  Template report.
	 * @return array<string, mixed>
	 */
	public function put_templates( $project_id, array $templates ) {
		// Same fix as `put_shared()`: read the bucket this call writes, or a
		// template's `generated` flag and Elementor id are read from the wrong
		// project and written back to another.
		$registry  = $this->registry_for( $project_id );
		$existing  = isset( $registry['templates'] ) && is_array( $registry['templates'] ) ? $registry['templates'] : array();
		$stored    = array();

		foreach ( (array) ( $templates['templates'] ?? array() ) as $template ) {
			if ( ! is_array( $template ) || empty( $template['template_id'] ) ) {
				continue;
			}
			$id = (string) $template['template_id'];
			if ( isset( $existing[ $id ] ) && is_array( $existing[ $id ] ) ) {
				// `generated` records whether a real Elementor template exists. A
				// template identified but never generated must not be shown as one a
				// user can edit, which is §64's explicit requirement.
				$template['generated'] = ! empty( $existing[ $id ]['generated'] );
				$template['elementor_id'] = $existing[ $id ]['elementor_id'] ?? 0;
				if ( ! empty( $existing[ $id ]['overridden'] ) ) {
					$template['overridden'] = true;
					$template['note']       = __( 'You have customized this template, so your version has been kept.', 'replicaforge' );
				}
			}
			$stored[ $id ] = $template;
		}

		$registry['templates'] = $stored;
		$this->save( $project_id, $registry );

		return $stored;
	}

	/**
	 * Return the templates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function templates() {
		$registry = $this->registry();
		return array_values( (array) ( $registry['templates'] ?? array() ) );
	}

	/**
	 * Mark a template as actually generated in Elementor.
	 *
	 * @param string $template_id  Template identifier.
	 * @param int    $elementor_id Elementor post id.
	 * @return bool
	 */
	public function mark_generated( $template_id, $elementor_id ) {
		$registry    = $this->registry();
		$template_id = (string) $template_id;
		if ( ! isset( $registry['templates'][ $template_id ] ) ) {
			return false;
		}
		$registry['templates'][ $template_id ]['generated']    = true;
		$registry['templates'][ $template_id ]['elementor_id'] = (int) $elementor_id;
		$this->save( (string) ( $registry['project_id'] ?? '' ), $registry );
		return true;
	}

	/**
	 * Return the template a page uses.
	 *
	 * @param string $page_id Page identifier.
	 * @return array<string, mixed>|null
	 */
	public function template_for_page( $page_id ) {
		$page_id = (string) $page_id;
		foreach ( $this->templates() as $template ) {
			if ( in_array( $page_id, (array) ( $template['pages'] ?? array() ), true ) ) {
				return $template;
			}
		}
		return null;
	}

	/**
	 * Return a summary suitable for a UI.
	 *
	 * @return array<string, mixed>
	 */
	public function summary() {
		$shared    = $this->shared();
		$templates = $this->templates();
		$by_role   = array();

		foreach ( $shared as $component ) {
			$role                = (string) ( $component['role'] ?? 'card' );
			$by_role[ $role ]     = ( $by_role[ $role ] ?? 0 ) + 1;
		}

		return array(
			'shared'       => count( $shared ),
			'shared_roles' => $by_role,
			'templates'    => count( $templates ),
			'overridden'   => count( array_filter( $shared, static function ( $c ) { return ! empty( $c['overridden'] ); } ) ),
			'generated'    => count( array_filter( $templates, static function ( $t ) { return ! empty( $t['generated'] ); } ) ),
			'stale'        => count( array_filter( $shared, static function ( $c ) { return ! empty( $c['stale'] ); } ) ),
		);
	}
}
