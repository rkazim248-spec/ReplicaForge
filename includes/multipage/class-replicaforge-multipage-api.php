<?php
/**
 * Phase 12: the multi-page REST surface.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The multi-page controller.
 *
 * ### A separate controller, for the same reason as Phase 10's
 *
 * The Phase 1 to 8 controller is the security boundary for a browser submitting an
 * analysis. This one is a different shape of authorisation: every route here
 * resolves a *project* first and then asks whether the caller owns it, and the
 * project is addressed by an opaque id that is never used to locate anything
 * without an ownership check.
 *
 * ### What this surface will not accept
 *
 * - **No Elementor data.** There is no route that takes a property name and a
 *   value, and no route that takes a document tree. Job payloads are rebuilt
 *   server-side from the stored specification, exactly as Phase 11 requires.
 * - **No page list from the browser as truth.** `POST /websites/{id}/pages`
 *   validates every submitted URL through {@see Page_Discovery::permits()} and
 *   *re-derives* the page id from the URL. A submitted `page_id` is never trusted;
 *   it is recomputed and compared, and a mismatch is refused rather than resolved.
 * - **No forged ownership.** A `project_id` that the caller does not own is
 *   refused by {@see Project_Access} before the store is read, so a project id
 *   cannot be enumerated by timing or by error text.
 */
final class Multi_Page_Api {

	/**
	 * Route namespace.
	 */
	const NAMESPACE_V1 = 'replicaforge/v1';

	/**
	 * Entitlement manager.
	 *
	 * @var Entitlement_Manager
	 */
	private $entitlements;

	/**
	 * Project access.
	 *
	 * @var Project_Access
	 */
	private $access;

	/**
	 * Website store.
	 *
	 * @var Website_Repository
	 */
	private $websites;

	/**
	 * Registry.
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
	 * @param Entitlement_Manager|null   $entitlements Optional entitlement manager.
	 * @param Project_Access|null        $access       Optional project access.
	 * @param Website_Repository|null    $websites     Optional website store.
	 * @param Component_Registry|null    $registry     Optional registry.
	 * @param Logger|null                $logger       Optional logger.
	 */
	public function __construct( $entitlements = null, $access = null, $websites = null, $registry = null, $logger = null ) {
		$this->logger   = $logger instanceof Logger ? $logger : new Logger();
		$this->access   = $access instanceof Project_Access ? $access : new Project_Access();
		$this->websites = $websites instanceof Website_Repository ? $websites : new Website_Repository( $this->logger );
		$this->registry = $registry instanceof Component_Registry ? $registry : new Component_Registry( $this->logger );
		$this->entitlements = $entitlements instanceof Entitlement_Manager
			? $entitlements
			: new Entitlement_Manager( null, null, $this->access, $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * Routes
	 * ------------------------------------------------------------------ */

	/**
	 * Register the Phase 12 routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->route( '/websites', 'GET', 'list_websites', 'can_use' );
		$this->route( '/websites/discover', 'POST', 'discover', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})', 'GET', 'get_website', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})', 'DELETE', 'delete_website', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/pages', 'GET', 'list_pages', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/pages', 'POST', 'save_pages', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/analyze', 'POST', 'analyze', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/map', 'GET', 'get_map', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/design', 'GET', 'get_design', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/registry', 'GET', 'get_registry', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/compatibility', 'GET', 'get_compatibility', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/plan', 'POST', 'get_plan', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/validate', 'POST', 'validate', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/sync', 'POST', 'sync_scope', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/snapshots', 'GET', 'list_snapshots', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/snapshots', 'POST', 'create_snapshot', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/rollback', 'POST', 'rollback', 'can_use' );
		$this->route( '/websites/(?P<project_id>[A-Za-z0-9_\-]{1,64})/components/(?P<component_id>[A-Za-z0-9_\-]{1,80})/override', 'POST', 'override_component', 'can_use' );
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * GET /websites - the caller's projects.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_websites( $request ) {
		$user_id = get_current_user_id();
		$out     = array();

		foreach ( array_slice( $this->access->visible_projects( $user_id, 30 ), 0, 30 ) as $project_id ) {
			$out[] = $this->websites->summary( $project_id );
		}

		return $this->ok( array( 'websites' => $out, 'limits' => $this->limits() ) );
	}

	/**
	 * POST /websites/discover - find the pages of a website.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function discover( $request ) {
		$user_id = get_current_user_id();
		$url     = (string) $request->get_param( 'url' );
		$mode    = (string) $request->get_param( 'mode' );

		if ( ! in_array( $mode, array( 'single', 'website', 'custom' ), true ) ) {
			$mode = 'website';
		}

		// The allowlist is the plan's, and the request is admitted against it before
		// a single request leaves the server. A refused plan never crawls.
		$gate = $this->entitlements->check( 'analyze', $user_id );
		if ( empty( $gate['allowed'] ) ) {
			return $this->error( 'limit_reached', $this->gate_message( $gate ), (int) ( $gate['status'] ?? 429 ) );
		}

		$plan_id  = $this->entitlements->plans()->current_plan( $user_id )->id();
		$page_cap = Site_Limits::page_limit_for( $plan_id );
		$requested = (int) $request->get_param( 'max_pages' );
		$max_pages = ( $requested > 0 ) ? min( $page_cap, $requested ) : $page_cap;

		if ( $max_pages < $requested ) {
			$this->logger->info(
				'multipage_pages_capped',
				'A discovery request asked for more pages than the plan allows.',
				array( 'requested' => $requested, 'allowed' => $max_pages, 'plan' => $plan_id ),
				'multipage'
			);
		}

		$discovery = new Page_Discovery( null, null, $this->logger );

		if ( 'single' === $mode ) {
			$single = $discovery->discover( $url, array( 'max_pages' => 1, 'max_depth' => 1, 'skip_sitemaps' => true ) );
			$single['mode'] = 'single';
			return $this->ok( $single );
		}

		$options = array(
			'max_pages'         => $max_pages,
			'max_depth'         => (int) $request->get_param( 'max_depth' ),
			'include_subdomains'=> (bool) $request->get_param( 'include_subdomains' ),
		);

		if ( 'custom' === $mode ) {
			// A manual list is held to exactly the same scope rules as a discovered
			// one, so "enter the URLs yourself" is not a way around the boundary.
			$urls     = (array) $request->get_param( 'urls' );
			$accepted = array();
			$refused  = array();
			$entry    = $this->origin_of( $url );
			foreach ( array_slice( $urls, 0, Site_Limits::MAX_FRONTIER ) as $candidate ) {
				if ( ! is_string( $candidate ) ) {
					continue;
				}
				if ( $discovery->permits( $candidate, $entry, (bool) $request->get_param( 'include_subdomains' ) ) ) {
					$accepted[ trim( $candidate ) ] = true;
				} else {
					$refused[] = $candidate;
				}
			}
			$pages = array();
			$i     = 0;
			foreach ( array_keys( $accepted ) as $candidate ) {
				if ( $i >= $max_pages ) {
					break;
				}
				$pages[] = array( 'url' => (string) $candidate, 'depth' => 0, 'from' => '', 'via' => 'custom' );
				$i++;
			}
			return $this->ok( array(
				'success'   => true,
				'mode'      => 'custom',
				'entry'     => $url,
				'origin'    => $entry,
				'pages'     => $pages,
				'count'     => count( $pages ),
				'refused'   => $refused,
				'stopped'   => ( count( $pages ) >= $max_pages ? 'page_limit' : 'complete' ),
				'max_pages' => $max_pages,
				'note'      => __( 'Each address you entered was checked against the same rules the crawler uses: same website only, no login, admin, checkout or private pages.', 'replicaforge' ),
			) );
		}

		$result = $discovery->discover( $url, $options );
		$result['mode']      = 'website';
		$result['plan']      = $plan_id;
		$result['page_limit']= $page_cap;
		$result['limits']    = $this->limits();
		return $this->ok( $result );
	}

	/**
	 * GET /websites/{id} - the stored specification.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_website( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id = (string) $request->get_param( 'project_id' );
		return $this->ok( array(
			'project_id'    => $id,
			'summary'       => $this->websites->summary( $id ),
			'specification' => $this->websites->specification( $id ),
		) );
	}

	/**
	 * DELETE /websites/{id} - forget the project's records.
	 *
	 * Generated drafts are left alone. Deleting a record the user can see is
	 * recoverable by re-analyzing; deleting their pages is not something a "remove
	 * project" button should do.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_website( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id     = (string) $request->get_param( 'project_id' );
		$result = $this->websites->delete( $id );
		$this->registry->delete( $id );

		return $this->ok( $result );
	}

	/**
	 * GET /websites/{id}/pages - the page list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_pages( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id     = (string) $request->get_param( 'project_id' );
		$filter = (string) $request->get_param( 'status' );
		$search = strtolower( trim( (string) $request->get_param( 'search' ) ) );
		$type   = (string) $request->get_param( 'type' );

		$pages = $this->websites->pages( $id );
		$out   = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			if ( '' !== $filter && (string) ( $page['status'] ?? '' ) !== $filter ) {
				continue;
			}
			if ( '' !== $type && ! Site_Limits::is_page_type( $type ) ) {
				continue;
			} elseif ( '' !== $type && (string) ( $page['page_type'] ?? '' ) !== $type ) {
				continue;
			}
			if ( '' !== $search ) {
				$haystack = strtolower( (string) ( $page['title'] ?? '' ) . ' ' . (string) ( $page['source_url'] ?? '' ) );
				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}
			$out[] = $this->present_page( $page );
		}

		return $this->ok( array(
			'pages'  => $out,
			'count'  => count( $out ),
			'summary'=> $this->websites->summary( $id ),
			'filters'=> array(
				'types'   => Site_Limits::PAGE_TYPES,
				'statuses'=> array( 'pending', 'analyzed', 'selected', 'generating', 'generated', 'validated', 'needs_review', 'failed', 'excluded' ),
				'note'    => __( 'Legal, search and category pages are found but not reconstructed by default. You can select them if you want them.', 'replicaforge' ),
			),
		) );
	}

	/**
	 * POST /websites/{id}/pages - store a page selection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_pages( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$user_id = get_current_user_id();
		$id      = (string) $request->get_param( 'project_id' );
		$source  = (string) $request->get_param( 'source_url' );

		$submitted = $request->get_param( 'pages' );
		if ( ! is_array( $submitted ) ) {
			return $this->error( 'invalid_pages', __( 'A list of pages is required.', 'replicaforge' ), 400 );
		}

		$gate = $this->entitlements->check( 'analyze', $user_id );
		if ( empty( $gate['allowed'] ) ) {
			return $this->error( 'limit_reached', $this->gate_message( $gate ), (int) ( $gate['status'] ?? 429 ) );
		}

		$plan_id  = $this->entitlements->plans()->current_plan( $user_id )->id();
		$page_cap = Site_Limits::selected_page_limit( $plan_id );

		$discovery = new Page_Discovery( null, null, $this->logger );
		$origin    = $this->origin_of( '' !== $source ? $source : $this->first_page_url( $id ) );
		$subdomains= (bool) $request->get_param( 'include_subdomains' );

		$kept     = array();
		$refused  = array();
		$mismatch = array();

		foreach ( array_slice( $submitted, 0, Site_Limits::MAX_FRONTIER ) as $page ) {
			if ( ! is_array( $page ) || empty( $page['source_url'] ) || ! is_string( $page['source_url'] ) ) {
				continue;
			}
			$candidate = trim( (string) $page['source_url'] );

			if ( ! $discovery->permits( $candidate, $origin, $subdomains ) ) {
				$refused[] = $candidate;
				continue;
			}

			// The id is derived from the URL. A submitted id that disagrees is a
			// forged reference, and refusing it is the only safe answer — resolving it
			// to whichever id the URL produces would let a caller name a page they
			// did not submit.
			$derived = Website_Repository::page_id_for( $candidate );
			if ( isset( $page['page_id'] ) && (string) $page['page_id'] !== $derived ) {
				$mismatch[] = array( 'submitted' => (string) $page['page_id'], 'derived' => $derived, 'url' => $candidate );
				continue;
			}

			$type = isset( $page['type'] ) ? (string) $page['type'] : (string) Page_Classifier::classify_url( $candidate )['type'];

			$kept[] = array(
				'source_url' => $candidate,
				'title'      => (string) ( $page['title'] ?? '' ),
				'type'       => $type,
				'confidence' => isset( $page['confidence'] ) ? (float) $page['confidence'] : 0.0,
				'status'     => ! empty( $page['selected'] ) ? 'selected' : 'pending',
				'selected'   => ! empty( $page['selected'] ),
				'priority'   => isset( $page['priority'] ) ? (int) $page['priority'] : Page_Classifier::priority_for( $type ),
				'via'        => (string) ( $page['via'] ?? 'custom' ),
				'depth'      => isset( $page['depth'] ) ? (int) $page['depth'] : 0,
			);
		}

		$capped = false;
		if ( count( $kept ) > $page_cap ) {
			$kept   = array_slice( $kept, 0, $page_cap );
			$capped = true;
		}

		$result = $this->websites->save_pages( $id, $kept, array(
			'source_url'  => (string) ( $this->origin_of( $source ) ),
			'name'        => (string) $request->get_param( 'name' ),
			'plan'        => $plan_id,
			'subdomains'  => $subdomains,
		) );

		return $this->ok( array_merge( $result, array(
			'refused'   => $refused,
			'mismatch'  => $mismatch,
			'capped'    => $capped,
			'page_limit'=> $page_cap,
			'note'      => $capped
				? sprintf(
					/* translators: %d: the number of pages the plan allows. */
					__( 'Your plan allows %d pages per project, so the rest were not kept. Nothing was discarded silently — the rest is still listed in the discovery result.', 'replicaforge' ),
					$page_cap
				)
				: __( 'Pages stored.', 'replicaforge' ),
		) ) );
	}

	/**
	 * POST /websites/{id}/analyze - build the website specification.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function analyze( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$user_id = get_current_user_id();
		$id      = (string) $request->get_param( 'project_id' );

		$selected = $this->websites->selected( $id );
		if ( array() === $selected ) {
			return $this->error( 'no_pages', __( 'Select at least one page before building the website specification.', 'replicaforge' ), 400 );
		}

		$gate = $this->entitlements->check( 'analyze', $user_id );
		if ( empty( $gate['allowed'] ) ) {
			return $this->error( 'limit_reached', $this->gate_message( $gate ), (int) ( $gate['status'] ?? 429 ) );
		}

		$reservation = $this->entitlements->begin( 'analyze', $user_id );
		if ( empty( $reservation['allowed'] ) ) {
			return $this->error( 'limit_reached', $this->gate_message( $reservation ), (int) ( $reservation['status'] ?? 429 ) );
		}

		// Representations come from the stored analyses. Nothing is accepted from the
		// request body here, which is the §25 / §52 property in practice: this
		// handler cannot be given a document to write.
		$analyzer  = new Site_Analyzer( array( 'logger' => $this->logger, 'registry' => $this->registry ) );
		$spec      = $analyzer->analyze( $id, $this->representations_for( $selected ), $this->project_context( $id, $selected ) );

		$this->websites->save_specification( $id, $spec );
		foreach ( $selected as $page ) {
			$this->websites->update_page( $id, (string) $page['page_id'], array( 'status' => 'analyzed' ) );
		}

		$this->entitlements->settle( $user_id, (string) ( $reservation['reservation'] ?? '' ), (string) ( $reservation['plan_id'] ?? '' ) );

		return $this->ok( array(
			'project_id'    => $id,
			'specification' => $spec,
			'map'           => $analyzer->map( $spec ),
			'design'        => $analyzer->design_view( $spec ),
			'validation'    => $spec['validation'] ?? array(),
			'stages'        => $spec['stages'] ?? array(),
		) );
	}

	/**
	 * GET /websites/{id}/map - the website map.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_map( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id      = (string) $request->get_param( 'project_id' );
		$spec    = $this->websites->specification( $id );
		$analyzer= new Site_Analyzer( array( 'logger' => $this->logger, 'registry' => $this->registry ) );

		return $this->ok( array(
			'map'    => $analyzer->map( $spec ),
			'pages'  => array_map( array( $this, 'present_page' ), array_values( $this->websites->pages( $id ) ) ),
		) );
	}

	/**
	 * GET /websites/{id}/design - the design system view.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_design( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id       = (string) $request->get_param( 'project_id' );
		$spec     = $this->websites->specification( $id );
		$analyzer = new Site_Analyzer( array( 'logger' => $this->logger, 'registry' => $this->registry ) );

		return $this->ok( array(
			'design'      => $analyzer->design_view( $spec ),
			'ownership'   => ( new Site_Compatibility( null, $this->logger ) )->ownership( $id ),
			'responsive'  => (array) ( $spec['responsive_strategy'] ?? array() ),
			'content'     => (array) ( $spec['content_mapping'] ?? array() ),
			'conflicts'   => (array) ( $spec['global_design_system']['conflicts'] ?? array() ),
		) );
	}

	/**
	 * GET /websites/{id}/registry - shared components and templates.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_registry( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id = (string) $request->get_param( 'project_id' );
		$this->registry->load( $id );

		$shared = $this->registry->shared();

		$decorated = array();
		foreach ( $shared as $component ) {
			$decorated[] = array_merge( $component, array(
				'pages_detail' => array_map(
					function ( $page_id ) {
						$page = $this->websites->pages( $id );
						$one  = $page[ (string) $page_id ] ?? array();
						return array(
							'page_id' => (string) $page_id,
							'title'   => (string) ( $one['title'] ?? '' ),
							'type'    => Page_Classifier::label( (string) ( $one['page_type'] ?? 'custom' ) ),
							'status'  => (string) ( $one['status'] ?? 'pending' ),
						);
					},
					(array) ( $component['pages'] ?? array() )
				),
			) );
		}

		$templates = $this->registry->templates();
		$shown     = array();
		foreach ( $templates as $template ) {
			// §64: do not display a template that does not exist. `generated` records
			// whether a real Elementor template was made.
			$shown[] = $template;
		}

		return $this->ok( array(
			'shared'    => $decorated,
			'templates' => $shown,
			'summary'   => $this->registry->summary(),
			'note'      => __( 'A shared component is built once. Editing it changes every page that uses it, which is why your own changes to one are recorded and protected.', 'replicaforge' ),
		) );
	}

	/**
	 * GET /websites/{id}/compatibility - theme and Elementor report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_compatibility( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id  = (string) $request->get_param( 'project_id' );
		$api = new Site_Compatibility( null, $this->logger );

		return $this->ok( array(
			'report'    => $api->report(),
			'ownership' => $api->ownership( $id ),
			'builder'   => $api->builder_locations(),
		) );
	}

	/**
	 * POST /websites/{id}/plan - the generation plan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_plan( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$user_id = get_current_user_id();
		$id      = (string) $request->get_param( 'project_id' );
		$spec    = $this->websites->specification( $id );

		if ( array() === $spec ) {
			return $this->error( 'no_specification', __( 'Analyze the selected pages first.', 'replicaforge' ), 400 );
		}

		$mode = (string) $request->get_param( 'mode' );
		if ( ! in_array( $mode, Site_Limits::RECONSTRUCTION_MODES, true ) ) {
			$mode = 'balanced';
		}

		// §55: the impact is shown before anything runs, and the limit is checked
		// before the plan is offered rather than at the end.
		$impact = $this->impact( $spec, $mode );
		$gate   = $this->entitlements->check( 'generate', $user_id );
		if ( empty( $gate['allowed'] ) ) {
			return $this->error( 'limit_reached', $this->gate_message( $gate ), (int) ( $gate['status'] ?? 429 ) );
		}

		$planner = new Multi_Page_Planner( $this->registry, $this->logger );
		$plan    = $planner->plan( $spec, array(
			'mode'         => $mode,
			'strategies'   => (array) $request->get_param( 'strategies' ),
			'asset_mode'   => (string) $request->get_param( 'asset_mode' ),
			'priorities'   => (array) $request->get_param( 'priorities' ),
		) );

		// Every page step is checked against what already exists at that address, so
		// the user sees the conflicts before generation rather than after.
		$conflicts = array();
		foreach ( (array) ( $spec['pages'] ?? array() ) as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$verdict = $planner->existing_conflict( $page );
			if ( ! empty( $verdict['conflict'] ) ) {
				$conflicts[ (string) ( $page['page_id'] ?? '' ) ] = $verdict;
			}
		}

		return $this->ok( array(
			'plan'      => $plan,
			'impact'    => $impact,
			'conflicts' => $conflicts,
			'note'      => __( 'Nothing is generated by this request. It produces the plan, the usage impact, and anything that would conflict with a page you already have.', 'replicaforge' ),
		) );
	}

	/**
	 * POST /websites/{id}/validate - cross-page validation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function validate( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id   = (string) $request->get_param( 'project_id' );
		$spec = $this->websites->specification( $id );
		if ( array() === $spec ) {
			return $this->error( 'no_specification', __( 'Analyze the selected pages first.', 'replicaforge' ), 400 );
		}

		// The generated state is read from the store, never from the request. A
		// browser cannot claim a page was generated to make it pass.
		$validator = new Cross_Page_Validator( $this->logger );
		$report    = $validator->validate( $spec, $this->generated_state( $id ) );

		return $this->ok( array(
			'report'     => $report,
			'categories' => Cross_Page_Validator::CATEGORIES,
			'note'       => __( 'This compares the website against itself as well as against the source: a page can be a perfect match on its own and still disagree with the other pages.', 'replicaforge' ),
		) );
	}

	/**
	 * POST /websites/{id}/sync - the change scope for an incremental update.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function sync_scope( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id     = (string) $request->get_param( 'project_id' );
		$spec   = $this->websites->specification( $id );
		$changed = (array) $request->get_param( 'changed_pages' );
		$pages  = $this->websites->pages( $id );

		// A changed page must be a page this project has. A caller cannot name a page
		// that is not in the project and have the planner treat it as a website-wide
		// change.
		$valid = array();
		$bad   = array();
		foreach ( $changed as $page_id ) {
			$page_id = (string) $page_id;
			if ( isset( $pages[ $page_id ] ) ) {
				$valid[] = $page_id;
			} else {
				$bad[] = $page_id;
			}
		}

		$planner = new Multi_Page_Planner( $this->registry, $this->logger );
		$scope   = $planner->sync_scope( $valid, $spec );

		return $this->ok( array(
			'scope'     => $scope,
			'unknown'   => $bad,
			'note'      => __( 'This is a plan for what to rebuild, not a rebuild. Nothing has been changed.', 'replicaforge' ),
		) );
	}

	/**
	 * GET /websites/{id}/snapshots - the snapshot history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_snapshots( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id        = (string) $request->get_param( 'project_id' );
		$planner   = new Multi_Page_Planner( $this->registry, $this->logger );
		$snapshots = $planner->snapshots( $id );

		$summary = array();
		foreach ( $snapshots as $snapshot ) {
			$summary[] = array(
				'snapshot_id' => (string) ( $snapshot['snapshot_id'] ?? '' ),
				'created_at'  => (int) ( $snapshot['created_at'] ?? 0 ),
				'page_count'  => (int) ( $snapshot['page_count'] ?? 0 ),
				'design_hash' => (string) ( $snapshot['design_hash'] ?? '' ),
			);
		}

		return $this->ok( array(
			'snapshots' => $summary,
			'count'     => count( $summary ),
			'max'       => Multi_Page_Planner::MAX_SNAPSHOTS,
			'note'      => __( 'A snapshot records which pages exist and their hashes. It does not copy your page content, so it costs almost nothing in storage.', 'replicaforge' ),
		) );
	}

	/**
	 * POST /websites/{id}/snapshots - take a snapshot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_snapshot( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id      = (string) $request->get_param( 'project_id' );
		$planner = new Multi_Page_Planner( $this->registry, $this->logger );
		$result  = $planner->snapshot( $id, $this->websites->pages( $id ), $this->websites->specification( $id ) );

		return $this->ok( $result );
	}

	/**
	 * POST /websites/{id}/rollback - the rollback plan.
	 *
	 * Returns a plan, not an action. A rollback trashes pages, and trashing pages is
	 * not something a POST should do without the user having read what it affects.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rollback( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id        = (string) $request->get_param( 'project_id' );
		$planner   = new Multi_Page_Planner( $this->registry, $this->logger );
		$result    = $planner->rollback_plan( $id, (string) $request->get_param( 'snapshot_id' ), (array) $request->get_param( 'page_ids' ) );

		if ( empty( $result['success'] ) ) {
			return $this->error( 'no_snapshot', (string) $result['message'], 400 );
		}

		return $this->ok( $result );
	}

	/**
	 * POST /websites/{id}/components/{id}/override - record a user edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function override_component( $request ) {
		$project = $this->owned( $request );
		if ( isset( $project['error'] ) ) {
			return $project['error'];
		}

		$id     = (string) $request->get_param( 'project_id' );
		$shared = (string) $request->get_param( 'component_id' );

		$this->registry->load( $id );
		if ( null === $this->registry->find_shared( $shared ) ) {
			return $this->error( 'unknown_component', __( 'That shared component is not in this project\'s registry.', 'replicaforge' ), 404 );
		}

		// What the user set is recorded verbatim. It is never interpreted as a
		// structure to apply — this is a note saying "hands off", not a patch.
		$note = $request->get_param( 'note' );
		$note = is_string( $note ) ? substr( sanitize_textarea_field( $note ), 0, 500 ) : '';

		$result = $this->registry->mark_overridden( $shared, array( 'note' => $note, 'at' => time() ) );

		return $this->ok( $result );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve and check ownership of the project a request addresses.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed> Either the project, or an `error` response.
	 */
	private function owned( $request ) {
		$user_id    = get_current_user_id();
		$project_id = (string) $request->get_param( 'project_id' );

		$project = $this->access->readable_project( $user_id, $project_id );
		if ( ! $project ) {
			// One message for "does not exist" and "not yours", so a project id
			// cannot be probed for existence by comparing responses.
			return array( 'error' => $this->error(
				'project_not_found',
				__( 'That project could not be found, or it belongs to another account.', 'replicaforge' ),
				404
			) );
		}

		return array( 'project' => $project );
	}

	/**
	 * Return the message from an entitlement verdict.
	 *
	 * Read from the verdict rather than recomputed, because
	 * `Entitlement_Manager::limit_message()` needs the plan and the usage meter and
	 * the verdict already contains the answer it produced. Recomputing it here would
	 * be a second message that could say something different from the first.
	 *
	 * @param array<string, mixed> $gate Verdict from `check()` or `begin()`.
	 * @return string
	 */
	private function gate_message( array $gate ) {
		$message = (string) ( $gate['message'] ?? '' );
		return ( '' !== $message ) ? $message : __( 'The plan limit for this operation has been reached.', 'replicaforge' );
	}

	/**
	 * Return the usage impact of a plan, shown before anything runs.
	 *
	 * @param array<string, mixed> $spec Specification.
	 * @param string               $mode Reconstruction mode.
	 * @return array<string, mixed>
	 */
	private function impact( array $spec, $mode ) {
		$pages = count( (array) ( $spec['pages'] ?? array() ) );

		// Estimated, and labelled as an estimate. §55 asks for an estimate of
		// resource impact, and an estimate presented as a measurement is the
		// dishonest version of a useful number.
		return array(
			'pages'      => $pages,
			'assets'     => (int) ( $spec['assets']['count'] ?? 0 ),
			'components' => count( (array) ( $spec['shared_components'] ?? array() ) ),
			'templates'  => count( (array) ( $spec['templates'] ?? array() ) ),
			'mode'       => (string) $mode,
			'estimate'   => __( 'estimated', 'replicaforge' ),
			'note'       => __( 'These are counts of what will be touched, not a charge. Your plan limits are checked before generation starts and the actual usage is recorded afterwards.', 'replicaforge' ),
			'disclosure' => __( 'This is an estimate based on the specification, not a measured cost, and it is not a billing amount.', 'replicaforge' ),
		);
	}

	/**
	 * Return the stored analyses for a set of pages.
	 *
	 * @param array<int, array<string, mixed>> $pages Page records.
	 * @return array<string, array<string, mixed>>
	 */
	private function representations_for( array $pages ) {
		$out = array();
		foreach ( $pages as $page ) {
			$page_id = (string) ( $page['page_id'] ?? '' );
			if ( '' === $page_id ) {
				continue;
			}
			$stored = get_post_meta( $page_id, 'replicaforge_analysis', true );
			if ( is_array( $stored ) && array() !== $stored ) {
				$out[ $page_id ] = $stored;
			}
		}
		return $out;
	}

	/**
	 * Return the generated state for validation.
	 *
	 * @param string $project_id Project identifier.
	 * @return array<string, array<string, mixed>>
	 */
	private function generated_state( $project_id ) {
		$out = array();
		foreach ( $this->websites->pages( $project_id ) as $page_id => $page ) {
			$out[ (string) $page_id ] = array(
				'post_id'            => (int) ( $page['post_id'] ?? 0 ),
				'status'             => (string) ( $page['status'] ?? 'pending' ),
				'validated'          => ( 'validated' === (string) ( $page['status'] ?? '' ) ),
				'error'              => (string) ( $page['error'] ?? '' ),
				'components'         => (array) get_post_meta( (int) ( $page['post_id'] ?? 0 ), 'replicaforge_components', true ),
				'tokens'             => (array) get_post_meta( (int) ( $page['post_id'] ?? 0 ), 'replicaforge_tokens', true ),
				'template_signature' => (string) get_post_meta( (int) ( $page['post_id'] ?? 0 ), 'replicaforge_template_signature', true ),
			);
		}
		return $out;
	}

	/**
	 * Return the context a specification is built with.
	 *
	 * @param string                         $project_id Project identifier.
	 * @param array<int, array<string, mixed>> $pages    Page records.
	 * @return array<string, mixed>
	 */
	private function project_context( $project_id, array $pages ) {
		$project  = $this->websites->project( $project_id );
		$meta     = isset( $project['meta'] ) && is_array( $project['meta'] ) ? $project['meta'] : array();
		$selected = array();
		foreach ( $pages as $page ) {
			$selected[ (string) ( $page['page_id'] ?? '' ) ] = $page;
		}

		$first = ( array() !== $pages ) ? (string) ( $pages[0]['source_url'] ?? '' ) : '';

		return array(
			'name'       => (string) ( $meta['name'] ?? '' ),
			'source_url' => (string) ( $meta['source_url'] ?? $this->origin_of( $first ) ),
			'host'       => (string) wp_parse_url( $first, PHP_URL_HOST ),
			'pages'      => $selected,
		);
	}

	/**
	 * Return the crawl-relevant limits.
	 *
	 * @return array<string, mixed>
	 */
	private function limits() {
		return array(
			'max_pages'          => Site_Limits::MAX_PAGES,
			'max_depth'          => Site_Limits::MAX_DEPTH,
			'max_links_per_page' => Site_Limits::MAX_LINKS_PER_PAGE,
			'max_total_bytes'    => (int) ( Site_Limits::MAX_PAGES * 2 * 1024 * 1024 ),
			'max_seconds'        => Site_Limits::MAX_PASS_SECONDS,
			'by_plan'            => array(
				'free'  => Site_Limits::page_limit_for( 'free' ),
				'pro'   => Site_Limits::page_limit_for( 'pro' ),
				'agency'=> Site_Limits::page_limit_for( 'agency' ),
			),
			'note'               => __( 'These are the hard bounds. Your plan sets a lower one where it applies.', 'replicaforge' ),
		);
	}

	/**
	 * Return a page record for presentation.
	 *
	 * @param array<string, mixed> $page Page record.
	 * @return array<string, mixed>
	 */
	private function present_page( array $page ) {
		$type = (string) ( $page['page_type'] ?? 'custom' );
		return array(
			'page_id'      => (string) ( $page['page_id'] ?? '' ),
			'source_url'   => (string) ( $page['source_url'] ?? '' ),
			'title'        => (string) ( $page['title'] ?? '' ),
			'type'         => $type,
			'type_label'   => Page_Classifier::label( $type ),
			'status'       => (string) ( $page['status'] ?? 'pending' ),
			'selected'     => ! empty( $page['selected'] ),
			'priority'     => (int) ( $page['priority'] ?? 0 ),
			'post_id'      => (int) ( $page['post_id'] ?? 0 ),
			'source_hash'  => (string) ( $page['source_hash'] ?? '' ),
			'confidence'   => (float) ( $page['type_confidence'] ?? 0.0 ),
			'overridden'   => ! empty( $page['overridden'] ),
			'by_default'   => Page_Classifier::reconstructs_by_default( $type ),
			'excluded_note'=> in_array( $type, Site_Limits::EXCLUDED_TYPES, true )
				? __( 'This page type is found but not selected by default, because a static reconstruction of it would look broken.', 'replicaforge' )
				: '',
		);
	}

	/**
	 * Return the first page URL in a project.
	 *
	 * @param string $project_id Project identifier.
	 * @return string
	 */
	private function first_page_url( $project_id ) {
		foreach ( $this->websites->pages( $project_id ) as $page ) {
			if ( ! empty( $page['source_url'] ) ) {
				return (string) $page['source_url'];
			}
		}
		return '';
	}

	/**
	 * Return the origin of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function origin_of( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		return strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) . '://' . strtolower( (string) $parts['host'] );
	}

	/**
	 * Permission callback: signed in, may use ReplicaForge.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_use( $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'authentication_required', __( 'Sign in to use ReplicaForge.', 'replicaforge' ), array( 'status' => 401 ) );
		}
		if ( ! Capabilities::current_user_can( 'replicaforge_use' ) ) {
			return new \WP_Error( 'capability_missing', __( 'Your account is not allowed to use ReplicaForge.', 'replicaforge' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Build a success response.
	 *
	 * @param array<string, mixed> $data Response data.
	 * @return \WP_REST_Response
	 */
	private function ok( array $data ) {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
				'meta'    => array( 'request_id' => Request_Context::request_id() ),
			),
			200
		);
	}

	/**
	 * Build a failure response.
	 *
	 * @param string               $code    Stable error code.
	 * @param string               $message User-facing message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $details Extra details.
	 * @return \WP_REST_Response
	 */
	private function error( $code, $message, $status = 400, array $details = array() ) {
		$status = (int) $status;
		if ( $status < 400 || $status > 499 ) {
			$status = 500;
		}
		if ( $status >= 500 ) {
			$this->logger->error(
				'multipage_api_error',
				'A multi-page request failed server-side.',
				array( 'code' => sanitize_key( (string) $code ), 'details' => array_keys( $details ) ),
				'multipage'
			);
			$message = __( 'The request could not be completed.', 'replicaforge' );
			$details = array();
		}

		return new \WP_REST_Response(
			array(
				'success' => false,
				'error'   => array(
					'code'    => sanitize_key( (string) $code ),
					'message' => (string) $message,
					'details' => $details,
				),
				'meta'    => array( 'request_id' => Request_Context::request_id() ),
			),
			$status
		);
	}

	/**
	 * Register one route.
	 *
	 * @param string $path       Route path.
	 * @param string $method     HTTP method.
	 * @param string $callback   Callback method name.
	 * @param string $permission Permission callback name.
	 * @return void
	 */
	private function route( $path, $method, $callback, $permission ) {
		register_rest_route(
			self::NAMESPACE_V1,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, $permission ),
			)
		);
	}
}
