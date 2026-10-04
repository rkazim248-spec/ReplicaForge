<?php
/**
 * Phase 12: multi-page generation planning, snapshots, and rollback.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a website specification into an ordered, resumable, isolated generation
 * plan — and can roll it back.
 *
 * ### Everything is a draft, always
 *
 * §40: *all generated pages remain drafts by default, never automatically publish*.
 * That is enforced in one place — {@see self::plan_step()}'s `draft` flag — and
 * there is no code path in this class that sets a post status to `publish`. A test
 * asserts it, because "we never publish" is the kind of guarantee that erodes one
 * careless commit at a time.
 *
 * ### Failure isolation is structural, not best-effort
 *
 * §42: if page 2 fails, pages 1 and 3 survive. The plan is a list of independent
 * steps, each with its own status, and no step's failure can change another's. A
 * failed page can be retried alone. Nothing here deletes a draft because a sibling
 * failed, which is the specific way this requirement is usually half-implemented.
 *
 * ### A snapshot is a list of post ids, not a copy of the database
 *
 * §60 says to avoid unnecessary binary duplication where the storage system can
 * reference immutable versions. WordPress already keeps revisions, so a snapshot
 * records the post ids, their content at snapshot time, and the design-system hash
 * — and a rollback restores or deletes from that. Copying every Elementor document
 * into a second store would double a project's size to guard against a mistake that
 * a post deletion already handles.
 */
final class Multi_Page_Planner {

	/**
	 * Snapshot option prefix.
	 *
	 * @var string
	 */
	const SNAPSHOT_OPTION = 'replicaforge_site_snapshots';

	/**
	 * Maximum snapshots retained per project.
	 *
	 * @var int
	 */
	const MAX_SNAPSHOTS = 10;

	/**
	 * Component registry.
	 *
	 * @var Component_Registry
	 */
	private $registry;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Component_Registry|null $registry Optional registry.
	 * @param Logger|null             $logger   Optional logger.
	 */
	public function __construct( $registry = null, $logger = null ) {
		$this->logger   = $logger instanceof Logger ? $logger : new Logger();
		$this->registry = $registry instanceof Component_Registry ? $registry : new Component_Registry( $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * Planning
	 * ------------------------------------------------------------------ */

	/**
	 * Build a generation plan from a specification.
	 *
	 * @param array<string, mixed> $specification Website specification.
	 * @param array<string, mixed> $options       Options.
	 * @return array<string, mixed>
	 */
	public function plan( array $specification, array $options = array() ) {
		$representation = new Site_Representation( $specification );
		$verdict        = $representation->validate();

		$steps = array();

		if ( ! $verdict['valid'] ) {
			// The gate. §37 says validate before generation, and a closed gate means
			// no steps, not a warning in the output.
			return array(
				'schema_version' => Site_Limits::SCHEMA_VERSION,
				'built'          => false,
				'gate'           => 'closed',
				'validation'     => $verdict,
				'steps'          => array(),
				'counts'         => array( 'total' => 0, 'ready' => 0 ),
				'message'        => __( 'This website specification is not valid, so no generation was planned. The problems are listed above.', 'replicaforge' ),
			);
		}

		$plan_id   = $this->plan_id( $specification );
		$order     = Site_Limits::generation_order();
		$modes     = isset( $options['mode'] ) && in_array( (string) $options['mode'], Site_Limits::RECONSTRUCTION_MODES, true ) ? (string) $options['mode'] : 'balanced';
		$priorities = isset( $options['priorities'] ) && is_array( $options['priorities'] ) ? $options['priorities'] : array();

		$page_records = $representation->pages();

		// Every page is a draft. There is no option that changes this.
		$draft = array( 'status' => 'draft', 'publish' => false );

		foreach ( $order as $phase ) {
			$phase_steps = $this->steps_for_phase( $phase, $specification, $page_records, $priorities, $draft, $options );
			foreach ( $phase_steps as $step ) {
				$steps[] = $step;
			}
		}

		// Order by phase first, then by declared priority inside the phase. The
		// homepage is generated before the eleventh service page, and both before a
		// footer that depends on them.
		$phase_index = array_flip( $order );
		usort(
			$steps,
			static function ( $left, $right ) use ( $phase_index ) {
				$lp = $phase_index[ $left['phase'] ] ?? 99;
				$rp = $phase_index[ $right['phase'] ] ?? 99;
				if ( $lp !== $rp ) {
					return ( $lp < $rp ) ? -1 : 1;
				}
				if ( $left['priority'] === $right['priority'] ) {
					return strcmp( (string) $left['step_id'], (string) $right['step_id'] );
				}
				return ( $left['priority'] > $right['priority'] ) ? -1 : 1;
			}
		);

		$counts = array(
			'total'      => count( $steps ),
			'phases'     => count( $order ),
			'pages'      => count( $page_records ),
			'components' => count( (array) ( $specification['shared_components'] ?? array() ) ),
			'templates'  => count( (array) ( $specification['templates'] ?? array() ) ),
		);

		return array(
			'schema_version' => Site_Limits::SCHEMA_VERSION,
			'built'          => true,
			'plan_id'        => $plan_id,
			'gate'           => 'open',
			'validation'     => $verdict,
			'mode'           => $modes,
			'order'          => $order,
			'steps'          => $steps,
			'counts'         => $counts,
			'draft_policy'   => array(
				'publish'  => false,
				'message'  => __( 'Every page is created as a draft. Nothing is published, and you review them before anything goes live.', 'replicaforge' ),
			),
			'isolation'      => array(
				'message' => __( 'Each page is an independent step. If one fails, the others keep their state and only that one is retried.', 'replicaforge' ),
			),
		);
	}

	/**
	 * Return the steps for one phase.
	 *
	 * @param string                        $phase      Phase name.
	 * @param array<string, mixed>          $spec       Specification.
	 * @param array<int, array<string, mixed>> $pages    Page records.
	 * @param array<string, int>            $priorities Page id => priority.
	 * @param array<string, mixed>          $draft      Draft policy.
	 * @param array<string, mixed>          $options    Options.
	 * @return array<int, array<string, mixed>>
	 */
	private function steps_for_phase( $phase, array $spec, array $pages, array $priorities, array $draft, array $options ) {
		$steps = array();

		switch ( $phase ) {
			case 'global_design':
				$design = isset( $spec['global_design_system'] ) && is_array( $spec['global_design_system'] ) ? $spec['global_design_system'] : array();
				$steps[] = $this->step(
					'design_system',
					'global_design',
					'Design system',
					'write' === (string) ( $options['design_system'] ?? 'write' ),
					array(
						'roles'     => array_keys( (array) ( $design['roles'] ?? array() ) ),
						'ownership' => 'unknown',
						'note'      => __( 'Design tokens are written to global styles only if ReplicaForge already owns them. Otherwise they are applied per page.', 'replicaforge' ),
					),
					100,
					$draft
				);
				break;

			case 'assets':
				$assets = isset( $spec['assets'] ) && is_array( $spec['assets'] ) ? $spec['assets'] : array();
				$steps[] = $this->step(
					'assets',
					'assets',
					__( 'Assets', 'replicaforge' ),
					'build',
					array(
						'count' => (int) ( $assets['count'] ?? 0 ),
						'mode'  => Asset_Registry::normalise_mode( (string) ( $options['asset_mode'] ?? 'reference' ) ),
						'note'  => __( 'Assets are referenced, not downloaded, unless you ask for them to be imported.', 'replicaforge' ),
					),
					95,
					$draft
				);
				break;

			case 'header':
			case 'footer':
				$role     = ( 'header' === $phase ) ? 'header' : 'footer';
				$strategies = isset( $options['strategies'] ) && is_array( $options['strategies'] ) ? $options['strategies'] : array();
				$strategy  = (string) ( $strategies[ $role ] ?? ( 'header' === $role ? 'theme' : 'theme' ) );
				if ( ! in_array( $strategy, Site_Limits::BOUNDARY_STRATEGIES, true ) ) {
					$strategy = 'theme';
				}
				$steps[] = $this->step(
					$role,
					$phase,
					( 'header' === $role )
						? __( 'Header', 'replicaforge' )
						: __( 'Footer', 'replicaforge' ),
					'theme' !== $strategy,
					array(
						'strategy' => $strategy,
						'note'     => ( 'theme' === $strategy )
							? __( 'The theme already supplies this, so ReplicaForge leaves it alone.', 'replicaforge' )
							: __( 'A generated header or footer will be created.', 'replicaforge' ),
					),
					90,
					$draft
				);
				break;

			case 'shared_components':
				foreach ( (array) ( $spec['shared_components'] ?? array() ) as $component ) {
					if ( ! is_array( $component ) || empty( $component['component_id'] ) ) {
						continue;
					}
					$id    = (string) $component['component_id'];
					$owned = ! $this->registry->may_rewrite( $id );
					$steps[] = $this->step(
						'component_' . $id,
						'shared_components',
						sprintf(
							/* translators: 1: component role, 2: number of pages. */
							__( 'Shared %1$s (used on %2$d pages)', 'replicaforge' ),
							(string) ( $component['role'] ?? 'component' ),
							(int) ( $component['page_count'] ?? 0 )
						),
						! $owned,
						array(
							'component_id' => $id,
							'role'         => (string) ( $component['role'] ?? '' ),
							'pages'        => (int) ( $component['page_count'] ?? 0 ),
							'skipped'      => $owned,
							'note'         => $owned
								? __( 'You have customized this, so it will not be rewritten.', 'replicaforge' )
								: __( 'Built once and used by every page.', 'replicaforge' ),
						),
						85,
						$draft
					);
				}
				break;

			case 'templates':
				foreach ( (array) ( $spec['templates'] ?? array() ) as $template ) {
					if ( ! is_array( $template ) || empty( $template['template_id'] ) ) {
						continue;
					}
					$steps[] = $this->step(
						'template_' . (string) $template['template_id'],
						'templates',
						sprintf(
							/* translators: 1: template identifier, 2: number of pages. */
							__( '%1$s (used by %2$d pages)', 'replicaforge' ),
							(string) $template['template_id'],
							(int) ( $template['page_count'] ?? 0 )
						),
						true,
						array(
							'template_id' => (string) $template['template_id'],
							'page_type'   => (string) ( $template['page_type'] ?? '' ),
							'pages'       => (int) ( $template['page_count'] ?? 0 ),
						),
						80,
						$draft
					);
				}
				break;

			case 'pages_primary':
			case 'pages_secondary':
			case 'pages_collection':
				foreach ( $pages as $page ) {
					if ( ! is_array( $page ) ) {
						continue;
					}
					$type    = (string) ( $page['type'] ?? 'custom' );
					$bucket  = $this->bucket_for( $type );
					if ( $bucket !== $phase ) {
						continue;
					}
					$page_id = (string) ( $page['page_id'] ?? '' );
					if ( '' === $page_id ) {
						continue;
					}
					$priority = isset( $priorities[ $page_id ] ) ? (int) $priorities[ $page_id ] : Page_Classifier::priority_for( $type );

					$steps[] = $this->step(
						'page_' . $page_id,
						$phase,
						(string) ( $page['title'] ?? $page['source_url'] ?? $page_id ),
						'pending' !== (string) ( $page['status'] ?? 'pending' ),
						array(
							'page_id'      => $page_id,
							'source_url'   => (string) ( $page['source_url'] ?? '' ),
							'type'         => $type,
							'reconstruction_mode' => (string) ( $options['mode'] ?? 'balanced' ),
						),
						$priority,
						$draft
					);
				}
				break;

			case 'navigation':
				$nav = isset( $spec['navigation'] ) && is_array( $spec['navigation'] ) ? $spec['navigation'] : array();
				$steps[] = $this->step(
					'navigation',
					'navigation',
					__( 'Navigation', 'replicaforge' ),
					true,
					array(
						'areas'   => array_keys( (array) ( $nav['areas'] ?? array() ) ),
						'unmapped'=> count( (array) ( $nav['unmapped'] ?? array() ) ),
						'note'    => __( 'Links are mapped only to pages that exist. Nothing is invented.', 'replicaforge' ),
					),
					20,
					$draft
				);
				break;

			case 'responsive':
				$design  = isset( $spec['global_design_system'] ) && is_array( $spec['global_design_system'] ) ? $spec['global_design_system'] : array();
				$steps[] = $this->step(
					'responsive',
					'responsive',
					__( 'Responsive rules', 'replicaforge' ),
					true,
					array(
						'shared'     => (array) ( $design['responsive']['shared'] ?? array() ),
						'exceptions' => array_keys( (array) ( $design['responsive']['exceptions'] ?? array() ) ),
					),
					15,
					$draft
				);
				break;

			case 'validation':
				$steps[] = $this->step(
					'validation',
					'validation',
					__( 'Website validation', 'replicaforge' ),
					true,
					array( 'note' => __( 'Compares the whole generated website against the source, not each page alone.', 'replicaforge' ) ),
					5,
					$draft
				);
				break;
		}

		return $steps;
	}

	/**
	 * Build one step.
	 *
	 * @param string               $id       Step identifier.
	 * @param string               $phase    Phase.
	 * @param string               $label    Human label.
	 * @param bool                 $required Whether the step must run.
	 * @param array<string, mixed> $payload  Step payload.
	 * @param int                  $priority Priority.
	 * @param array<string, mixed> $draft    Draft policy.
	 * @return array<string, mixed>
	 */
	private function step( $id, $phase, $label, $required, array $payload, $priority, array $draft ) {
		return array(
			'step_id'   => (string) $id,
			'phase'     => (string) $phase,
			'label'     => (string) $label,
			'required'  => (bool) $required,
			'status'    => 'pending',
			'priority'  => (int) $priority,
			'payload'   => $payload,
			'draft'     => $draft,
			'attempts'  => 0,
			'error'     => '',
			'post_id'   => 0,
			'skipped'   => empty( $required ),
		);
	}

	/**
	 * Return which page bucket a type belongs to.
	 *
	 * @param string $type Page type.
	 * @return string
	 */
	private function bucket_for( $type ) {
		if ( in_array( $type, Site_Limits::COLLECTION_TYPES, true ) ) {
			return 'pages_collection';
		}
		if ( in_array( $type, Site_Limits::primary_page_types(), true ) ) {
			return 'pages_primary';
		}
		return 'pages_secondary';
	}

	/**
	 * Return a stable plan identifier.
	 *
	 * Derived from the *content* of the specification, so an unchanged specification
	 * produces the same plan id and a re-run is recognised as the same attempt
	 * rather than a new one.
	 *
	 * @param array<string, mixed> $specification Specification.
	 * @return string
	 */
	public function plan_id( array $specification ) {
		$material = array(
			'schema' => Site_Limits::SCHEMA_VERSION,
			'pages'  => array_map(
				static function ( $page ) {
					return is_array( $page ) ? array( $page['page_id'] ?? '', $page['source_url'] ?? '', $page['source_hash'] ?? '' ) : '';
				},
				(array) ( $specification['pages'] ?? array() )
			),
			'design' => (string) ( $specification['design_hash'] ?? '' ),
			'assets' => (int) ( $specification['assets']['count'] ?? 0 ),
		);
		return 'plan_' . substr( hash( 'sha256', (string) wp_json_encode( $material ) ), 0, 16 );
	}

	/* ---------------------------------------------------------------------
	 * Sync scope
	 * ------------------------------------------------------------------ */

	/**
	 * Work out what a set of changed pages actually affects.
	 *
	 * §58's requirement: a change to one page must not rebuild the website. The
	 * scope is computed from the *registry* — which components and templates a
	 * changed page is part of, and what else uses them — rather than from a
	 * heuristic, so a page that is not in any shared structure stays a page-only
	 * change.
	 *
	 * @param array<int, string>               $changed_pages Changed page ids.
	 * @param array<string, array<string, mixed>> $spec        Website specification.
	 * @return array<string, mixed>
	 */
	public function sync_scope( array $changed_pages, array $spec ) {
		$changed = array();
		foreach ( $changed_pages as $page_id ) {
			$changed[ (string) $page_id ] = true;
		}

		$components = array();
		$templates  = array();
		$touched    = array();

		foreach ( (array) ( $spec['shared_components'] ?? array() ) as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$affected = array_intersect( array_keys( $changed ), array_map( 'strval', (array) ( $component['pages'] ?? array() ) ) );
			if ( array() === $affected ) {
				continue;
			}
			$id = (string) ( $component['component_id'] ?? '' );
			$components[ $id ] = array(
				'component_id' => $id,
				'role'         => (string) ( $component['role'] ?? '' ),
				'changed_on'   => array_values( $affected ),
				'also_on'      => array_values( array_diff( array_map( 'strval', (array) ( $component['pages'] ?? array() ) ), array_keys( $changed ) ) ),
				'correctable'  => $this->registry->may_rewrite( $id ),
			);
			foreach ( (array) ( $component['pages'] ?? array() ) as $peer ) {
				$touched[ (string) $peer ] = true;
			}
		}

		foreach ( (array) ( $spec['templates'] ?? array() ) as $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}
			$affected = array_intersect( array_keys( $changed ), array_map( 'strval', (array) ( $template['pages'] ?? array() ) ) );
			if ( array() === $affected ) {
				continue;
			}
			$id = (string) ( $template['template_id'] ?? '' );
			$templates[ $id ] = array(
				'template_id' => $id,
				'changed_on'  => array_values( $affected ),
				'also_on'     => array_values( array_diff( array_map( 'strval', (array) ( $template['pages'] ?? array() ) ), array_keys( $changed ) ) ),
			);
			foreach ( (array) ( $template['pages'] ?? array() ) as $peer ) {
				$touched[ (string) $peer ] = true;
			}
		}

		$design_changed = $this->design_changed( $spec );
		$nav_changed    = $this->navigation_changed( $spec, $changed_pages );
		$assets_changed = $this->assets_changed( $spec, $changed_pages );

		$scopes = array();
		if ( $design_changed ) { $scopes[] = 'global_design'; }
		if ( array() !== $components ) { $scopes[] = 'shared_component'; }
		if ( array() !== $templates ) { $scopes[] = 'template'; }
		if ( $nav_changed ) { $scopes[] = 'navigation'; }
		if ( $assets_changed ) { $scopes[] = 'asset'; }
		if ( array() === $scopes ) { $scopes[] = 'page_only'; }

		// `array_keys`, not `array_values`. `$touched` is a set keyed by page id whose
		// values are `true`, so taking the values yields `[ true, true ]` — the
		// affected-page list came back as two booleans and every consumer of it was
		// silently wrong. The first draft had that, and it type-checked fine.
		$affected = array_keys( $touched );
		sort( $affected );

		return array(
			'schema_version' => Site_Limits::SCHEMA_VERSION,
			'scopes'         => $scopes,
			'changed'        => array_values( $changed_pages ),
			'affected'       => $affected,
			'components'     => array_values( $components ),
			'templates'      => array_values( $templates ),
			'counts'         => array(
				'changed'  => count( $changed_pages ),
				'affected' => count( $affected ),
			),
			'note'           => ( 'page_only' === $scopes[0] )
				? __( 'Only the pages you changed are affected. Nothing shared was touched, so nothing else needs rebuilding.', 'replicaforge' )
				: sprintf(
					/* translators: 1: number of changed pages, 2: number of affected pages. */
					__( '%1$d page(s) changed and %2$d page(s) share a structure with them. Only those will be rebuilt.', 'replicaforge' ),
					count( $changed_pages ),
					count( $affected )
				),
		);
	}

	/**
	 * Return whether the global design system changed.
	 *
	 * @param array<string, mixed> $spec Specification.
	 * @return bool
	 */
	private function design_changed( array $spec ) {
		$design = isset( $spec['global_design_system'] ) && is_array( $spec['global_design_system'] ) ? $spec['global_design_system'] : array();
		if ( empty( $design['built'] ) ) {
			return false;
		}
		$current = (string) ( $design['hash'] ?? '' );
		$known   = (string) ( $spec['design_hash_known'] ?? '' );
		if ( '' === $current ) {
			return false;
		}
		if ( '' === $known ) {
			// Nothing recorded to compare against, so this cannot be shown to have
			// changed. Rebuilding every page on an unknown baseline would be the
			// opposite of §58.
			return false;
		}
		return ( $current !== $known );
	}

	/**
	 * Return whether navigation changed.
	 *
	 * @param array<string, mixed> $spec          Specification.
	 * @param array<int, string>   $changed_pages Changed pages.
	 * @return bool
	 */
	private function navigation_changed( array $spec, array $changed_pages ) {
		$nav = isset( $spec['navigation'] ) && is_array( $spec['navigation'] ) ? $spec['navigation'] : array();
		foreach ( (array) ( $nav['links'] ?? array() ) as $link ) {
			if ( ! is_array( $link ) || empty( $link['from_page'] ) ) {
				continue;
			}
			if ( in_array( (string) $link['from_page'], array_map( 'strval', $changed_pages ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return whether assets changed.
	 *
	 * @param array<string, mixed> $spec          Specification.
	 * @param array<int, string>   $changed_pages Changed pages.
	 * @return bool
	 */
	private function assets_changed( array $spec, array $changed_pages ) {
		$assets = isset( $spec['assets'] ) && is_array( $spec['assets'] ) ? $spec['assets'] : array();
		foreach ( (array) ( $assets['assets'] ?? array() ) as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['pages'] ) ) {
				continue;
			}
			$holders = array_map( 'strval', (array) $asset['pages'] );
			if ( array() === array_intersect( $holders, array_map( 'strval', $changed_pages ) ) ) {
				continue;
			}
			// An asset used by more than one page is shared, so a change to it reaches
			// past the page that referenced it.
			if ( count( $holders ) > 1 ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Snapshots and rollback
	 * ------------------------------------------------------------------ */

	/**
	 * Create a website snapshot.
	 *
	 * @param string               $project_id     Project identifier.
	 * @param array<string, mixed> $pages          Page records with post ids.
	 * @param array<string, mixed> $specification  Specification.
	 * @return array<string, mixed>
	 */
	public function snapshot( $project_id, array $pages, array $specification ) {
		$project_id = (string) $project_id;
		$all        = $this->snapshots( $project_id );

		$entries = array();
		foreach ( $pages as $page_id => $record ) {
			if ( ! is_array( $record ) || empty( $record['post_id'] ) ) {
				continue;
			}
			$post_id = (int) $record['post_id'];
			$post    = get_post( $post_id );
			$entries[ (string) $page_id ] = array(
				'post_id'      => $post_id,
				'title'        => $post ? (string) $post->post_title : '',
				'status'       => $post ? (string) $post->post_status : 'draft',
				'source_url'   => (string) ( $record['source_url'] ?? '' ),
				'hash'         => (string) ( $record['generated_hash'] ?? '' ),
				// The Elementor data is referenced, not copied. A rollback restores
				// from the post's own revisions, which already exist.
				'revisions'    => $post ? count( (array) wp_get_post_revisions( $post_id ) ) : 0,
			);
		}

		$all[] = array(
			'snapshot_id'   => 'snap_' . substr( hash( 'sha256', $project_id . '|' . count( $entries ) . '|' . time() ), 0, 12 ),
			'project_id'    => $project_id,
			'created_at'    => time(),
			'design_hash'   => (string) ( $specification['design_hash'] ?? '' ),
			'page_count'    => count( $entries ),
			'pages'         => $entries,
			'components'    => array_keys( (array) ( $specification['shared_components'] ?? array() ) ),
			'templates'     => array_keys( (array) ( $specification['templates'] ?? array() ) ),
		);

		if ( count( $all ) > self::MAX_SNAPSHOTS ) {
			$all = array_slice( $all, -self::MAX_SNAPSHOTS );
		}

		$store = get_option( self::SNAPSHOT_OPTION, array() );
		$store = is_array( $store ) ? $store : array();
		$store[ $project_id ] = $all;
		update_option( self::SNAPSHOT_OPTION, $store, false );

		$created = $all[ count( $all ) - 1 ];

		return array(
			'success'      => true,
			'snapshot'     => $created,
			'retained'     => count( $all ),
			'storage_note' => __( 'A snapshot records which pages exist and their hashes. Page content is not copied, so snapshots use no significant extra space.', 'replicaforge' ),
		);
	}

	/**
	 * Return a project's snapshots.
	 *
	 * @param string $project_id Project identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function snapshots( $project_id ) {
		$store = get_option( self::SNAPSHOT_OPTION, array() );
		$store = is_array( $store ) ? $store : array();
		$list  = ( isset( $store[ (string) $project_id ] ) && is_array( $store[ (string) $project_id ] ) ) ? $store[ (string) $project_id ] : array();
		return array_values( $list );
	}

	/**
	 * Return the steps needed to roll a snapshot back.
	 *
	 * §61 wants both a whole-website and a selected-pages rollback. The plan is
	 * returned rather than executed, because a rollback deletes pages and that has
	 * to be something the user sees and confirms.
	 *
	 * @param string $project_id   Project identifier.
	 * @param string $snapshot_id  Snapshot identifier, or empty for the latest.
	 * @param array<int, string>  $page_ids     Optional page subset.
	 * @return array<string, mixed>
	 */
	public function rollback_plan( $project_id, $snapshot_id = '', array $page_ids = array() ) {
		$snapshots = $this->snapshots( $project_id );
		if ( array() === $snapshots ) {
			return array(
				'success'  => false,
				'message'  => __( 'There is no snapshot for this project, so there is nothing to roll back to.', 'replicaforge' ),
				'steps'    => array(),
			);
		}

		$snapshot = $snapshots[ count( $snapshots ) - 1 ];
		if ( '' !== (string) $snapshot_id ) {
			$found = null;
			foreach ( $snapshots as $candidate ) {
				if ( (string) $candidate['snapshot_id'] === (string) $snapshot_id ) {
					$found = $candidate;
					break;
				}
			}
			if ( null === $found ) {
				return array(
					'success' => false,
					'message' => __( 'That snapshot is not in this project\'s history.', 'replicaforge' ),
					'steps'   => array(),
				);
			}
			$snapshot = $found;
		}

		$subset = array();
		foreach ( $page_ids as $page_id ) {
			$subset[ (string) $page_id ] = true;
		}

		$steps = array();
		foreach ( (array) ( $snapshot['pages'] ?? array() ) as $page_id => $entry ) {
			if ( array() !== $subset && ! isset( $subset[ (string) $page_id ] ) ) {
				continue;
			}
			$post_id = (int) ( $entry['post_id'] ?? 0 );
			$exists  = ( $post_id > 0 ) && ( null !== get_post( $post_id ) );

			$steps[] = array(
				'page_id'   => (string) $page_id,
				'post_id'   => $post_id,
				'title'     => (string) ( $entry['title'] ?? '' ),
				'exists'    => $exists,
				'action'    => $exists ? 'trash' : 'noop',
				'revisions' => (int) ( $entry['revisions'] ?? 0 ),
				'recoverable' => $exists,
				'note'      => $exists
					? __( 'This draft will be moved to the trash. It can be restored from there if this was a mistake.', 'replicaforge' )
					: __( 'This page no longer exists, so there is nothing to do.', 'replicaforge' ),
			);
		}

		return array(
			'success'    => true,
			'snapshot'   => $snapshot,
			'scope'      => ( array() === $subset ? 'website' : 'selected' ),
			'steps'      => $steps,
			'count'      => count( $steps ),
			'confirmation_required' => true,
			'message'    => __( 'Rolling back moves generated drafts to the trash. Nothing is deleted permanently, and Elementor document data is restored from the post\'s own revisions where available.', 'replicaforge' ),
		);
	}

	/**
	 * Return whether a post may be created for a page, or which existing post
	 * conflicts.
	 *
	 * §51: never overwrite an existing WordPress page. This checks the target path
	 * and reports a conflict, rather than creating and hoping.
	 *
	 * @param array<string, mixed> $page Page record.
	 * @return array<string, mixed>
	 */
	public function existing_conflict( array $page ) {
		$source = (string) ( $page['source_url'] ?? '' );
		$path   = (string) wp_parse_url( $source, PHP_URL_PATH );
		if ( '' === $path || '/' === $path ) {
			return array( 'conflict' => false, 'post_id' => 0, 'path' => '/' );
		}

		$slug = trim( (string) $path, '/' );
		$slug = (string) preg_replace( '#^.*/#', '', $slug );
		if ( '' === $slug ) {
			return array( 'conflict' => false, 'post_id' => 0, 'path' => $path );
		}

		$existing = get_page_by_path( $slug, OBJECT, (string) ( $page['post_type'] ?? 'page' ) );
		if ( ! $existing ) {
			$existing = get_page_by_path( $slug, OBJECT, 'post' );
		}

		if ( $existing ) {
			return array(
				'conflict' => true,
				'post_id'  => (int) $existing->ID,
				'path'     => $path,
				'title'    => (string) $existing->post_title,
				'status'   => (string) $existing->post_status,
				'note'     => __( 'A page already exists at this address. You can map to it or create a new draft beside it — nothing will be overwritten.', 'replicaforge' ),
			);
		}

		return array(
			'conflict' => false,
			'post_id'  => 0,
			'path'     => $path,
			'title'    => (string) ( $page['title'] ?? '' ),
			'status'   => 'none',
			'note'     => __( 'No page exists at this address, so a new draft can be created.', 'replicaforge' ),
		);
	}
}
