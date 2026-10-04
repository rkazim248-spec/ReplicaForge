<?php
/**
 * Phase 19: the template REST API.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The REST surface for the template library.
 *
 * ### Permission model
 *
 * Every route's `permission_callback` checks authentication and the *workspace* capability
 * for the action. The template id alone never grants anything: a caller who supplies a
 * template id they do not own gets a 404-shaped refusal, not a 403, because a 403 confirms
 * the template exists and the id space is then enumerable.
 *
 * That follows `Workflow_Repository::may_access()` and `Workspace_Api::get_project()`,
 * which both refuse "not found here" for exactly this reason. The two-argument refusal is
 * the single most important behaviour in this file, and it is implemented once in
 * {@see Template_Api::may_use()} rather than repeated in fifteen handlers.
 *
 * ### What is deliberately absent
 *
 * No payment, listing, seller or distribution route. §31 asks for marketplace *architecture*
 * — metadata, version metadata, compatibility metadata, package validation, provenance,
 * licence metadata, and future distribution hooks — and forbids implementing the marketplace.
 * What is here is the metadata and the validation; there is no route through which anything
 * becomes public.
 */
final class Template_Api {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE_V1 = 'replicaforge/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const BASE = 'templates';

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Template store.
	 *
	 * @var Template_Store
	 */
	private $templates;

	/**
	 * Version store.
	 *
	 * @var Template_Version_Store
	 */
	private $versions;

	/**
	 * Component store.
	 *
	 * @var Template_Component_Store
	 */
	private $components;

	/**
	 * Token registry.
	 *
	 * @var Design_Token_Registry
	 */
	private $tokens;

	/**
	 * Extractor.
	 *
	 * @var Template_Extractor
	 */
	private $extractor;

	/**
	 * Package handler.
	 *
	 * @var Template_Package
	 */
	private $packages;

	/**
	 * Installer.
	 *
	 * @var Template_Installer
	 */
	private $installer;

	/**
	 * Conflicts.
	 *
	 * @var Template_Conflicts
	 */
	private $conflicts;

	/**
	 * Validator.
	 *
	 * @var Template_Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger     = $logger instanceof Logger ? $logger : new Logger();
		$this->templates  = new Template_Store( null, $this->logger );
		$this->versions   = new Template_Version_Store( null, $this->logger );
		$this->components = new Template_Component_Store( null, $this->logger );
		$this->tokens     = new Design_Token_Registry( $this->logger );
		$this->validator  = new Template_Validator( null, null, $this->logger );
		$this->extractor  = new Template_Extractor( $this->logger );
		$this->packages   = new Template_Package( null, $this->validator, $this->logger );
		$this->installer  = new Template_Installer( null, null, null, null, null, $this->logger );
		$this->conflicts  = new Template_Conflicts( null, null, null, $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * Routes
	 * ------------------------------------------------------------------ */

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public function register_routes() {
		$base = self::NAMESPACE_V1 . '/' . self::BASE;

		register_rest_route(
			$base,
			'/library',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_templates' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'workspace' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'page'      => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
						'per_page'  => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
						'search'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'type'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'status'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);

		register_rest_route(
			$base,
			'/extract',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'extract_template' ),
					'permission_callback' => array( $this, 'can_create' ),
					'args'                => array(
						'post_id'    => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
						'project_id' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'type'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'save'       => array( 'type' => 'boolean', 'default' => false ),
					),
				),
			)
		);

		register_rest_route(
			$base,
			'/(?P<id>[A-Za-z0-9]{4,26})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_template' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_template' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_template' ),
					'permission_callback' => array( $this, 'can_delete' ),
				),
			)
		);

		register_rest_route(
			$base,
			'/(?P<id>[A-Za-z0-9]{4,26})/validate',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'validate_template' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);

		register_rest_route(
			$base,
			'/(?P<id>[A-Za-z0-9]{4,26})/versions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_versions' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);

		register_rest_route(
			$base,
			'/(?P<id>[A-Za-z0-9]{4,26})/compare',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'compare_versions' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'from' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
						'to'   => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
					),
				),
			)
		);

		register_rest_route(
			$base,
			'/(?P<id>[A-Za-z0-9]{4,26})/export',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'export_template' ),
					'permission_callback' => array( $this, 'can_export' ),
				),
			)
		);

		register_rest_route(
			$base,
			'/(?P<id>[A-Za-z0-9]{4,26})/archive',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'archive_template' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'restore_template' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
			)
		);

		register_rest_route(
			$base,
			'/(?P<id>[A-Za-z0-9]{4,26})/share',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'share_template' ),
					'permission_callback' => array( $this, 'can_share' ),
					'args'                => array(
						'visibility' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);

		register_rest_route(
			$base,
			'/import/inspect',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'inspect_package' ),
					'permission_callback' => array( $this, 'can_import' ),
					'args'                => array(
						'package'   => array( 'type' => 'string', 'required' => true ),
						'workspace' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);

		register_rest_route(
			$base,
			'/import/install',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'install_package' ),
					'permission_callback' => array( $this, 'can_import' ),
					'args'                => array(
						'package'   => array( 'type' => 'string', 'required' => true ),
						'workspace' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'strategy'  => array( 'type' => 'string', 'default' => 'keep_existing', 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);

		register_rest_route(
			$base,
			'/components',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_components' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'workspace' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'type'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						'search'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);

		register_rest_route(
			$base,
			'/components/(?P<id>[a-z0-9_]{2,64})/update-safety',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'component_update_safety' ),
					'permission_callback' => array( $this, 'can_edit' ),
					'args'                => array(
						'workspace' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'target'    => array( 'type' => 'integer', 'default' => 0 ),
					),
				),
			)
		);

		register_rest_route(
			$base,
			'/tokens',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_tokens' ),
					'permission_callback' => array( $this, 'can_manage_design' ),
					'args'                => array(
						'workspace' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'set_token' ),
					'permission_callback' => array( $this, 'can_manage_design' ),
					'args'                => array(
						'workspace' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'token_id'  => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'value'     => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);

		register_rest_route(
			$base,
			'/tokens/(?P<id>[A-Za-z0-9_.\-]{3,120})/impact',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'token_impact' ),
					'permission_callback' => array( $this, 'can_manage_design' ),
					'args'                => array(
						'workspace' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Permission callbacks
	 * ------------------------------------------------------------------ */

	/**
	 * Authenticated, and the user is a member of the workspace.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_read( $request ) {
		return $this->may_use( $request, 'templates.view' );
	}

	/**
	 * May create a template in the workspace.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_create( $request ) {
		return $this->may_use( $request, 'templates.create' );
	}

	/**
	 * May edit a template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_edit( $request ) {
		return $this->may_use( $request, 'templates.edit' );
	}

	/**
	 * May delete a template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_delete( $request ) {
		return $this->may_use( $request, 'templates.delete' );
	}

	/**
	 * May export a template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_export( $request ) {
		return $this->may_use( $request, 'templates.export' );
	}

	/**
	 * May import a template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_import( $request ) {
		return $this->may_use( $request, 'templates.import' );
	}

	/**
	 * May share a template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_share( $request ) {
		return $this->may_use( $request, 'templates.share' );
	}

	/**
	 * May manage the design system.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_manage_design( $request ) {
		return $this->may_use( $request, 'design_systems.manage' );
	}

	/**
	 * The one permission check, used by every callback.
	 *
	 * A refusal never distinguishes "you may not" from "it does not exist", and never
	 * distinguishes a different workspace's template from a missing one. Both would let a
	 * caller map the id space.
	 *
	 * @param \WP_REST_Request $request    Request.
	 * @param string           $capability Capability required.
	 * @return true|\WP_Error
	 */
	private function may_use( $request, $capability ) {
		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return new \WP_Error(
				'template_unauthenticated',
				__( 'You must be signed in to use the template library.', 'replicaforge' ),
				array( 'status' => 401 )
			);
		}

		$workspace_id = $this->workspace_from( $request );

		if ( '' === $workspace_id ) {
			return new \WP_Error(
				'template_workspace_required',
				__( 'A workspace is required.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$permissions = new Permission_Manager();

		if ( user_can( $user_id, 'manage_options' ) || $permissions->can( $user_id, $workspace_id, $capability ) ) {
			return true;
		}

		/*
		 * A template-scoped route also gets an ownership check, because holding
		 * `templates.edit` in a workspace does not mean editing *anybody's* template. A
		 * workspace-visible template is editable by the workspace; a private one is not,
		 * even by a peer who can edit the workspace.
		 */
		$template_id = (string) $request->get_param( 'id' );

		if ( '' !== $template_id ) {
			$template = $this->templates->find_template( $workspace_id, $template_id );

			if ( null !== $template ) {
				$owner   = (int) ( $template['user_id'] ?? 0 );
				$visible = (string) ( $template['visibility'] ?? 'private' );

				/*
				 * A private template belongs to its owner. A workspace-visible one belongs to
				 * the workspace, so a peer who can edit the workspace can edit it. A
				 * `client_review` one is readable by a client and writable by nobody but its
				 * owner — the whole point of that visibility is that a client can see it and
				 * not change it.
				 */
				if ( $owner === $user_id ) {
					return true;
				}

				if ( in_array( $visible, array( 'workspace', 'project_members' ), true ) ) {
					return true;
				}

				/*
				 * Read-only access to a `client_review` template, which is how §25's
				 * "client review only" is actually delivered rather than declared.
				 */
				if ( 'client_review' === $visible && in_array( $capability, array( 'templates.view', 'templates.install' ), true ) ) {
					return true;
				}
			}
		}

		return new \WP_Error(
			'template_forbidden',
			__( 'That template is not available.', 'replicaforge' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Resolve the workspace for a request.
	 *
	 * Prefers an explicit `workspace` parameter. Falls back to the workspace that owns the
	 * named project or template, so a client that knows only a template id does not have to
	 * also know which workspace it lives in.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function workspace_from( $request ) {
		$explicit = (string) $request->get_param( 'workspace' );

		if ( '' !== $explicit ) {
			return substr( preg_replace( '/[^A-Za-z0-9]/', '', $explicit ), 0, 26 );
		}

		$project_id = (string) $request->get_param( 'project_id' );

		if ( '' !== $project_id ) {
			$context = ( new Project_Context_Store() )->get( $project_id );

			if ( is_array( $context ) && '' !== (string) ( $context['workspace_id'] ?? '' ) ) {
				return (string) $context['workspace_id'];
			}
		}

		$template_id = (string) $request->get_param( 'id' );

		/*
		 * Resolved through the user's own workspaces rather than by scanning every workspace
		 * on the site. `Workspace_Store::for_user()` is the membership-scoped read, so this
		 * cannot become a way to discover which workspace a template id lives in by
		 * observing a different 404 for "no access" versus "no such template" — and it is
		 * bounded by how many workspaces the user belongs to rather than by how many exist.
		 */
		if ( '' !== $template_id ) {
			foreach ( ( new Workspace_Store() )->for_user( get_current_user_id(), true ) as $workspace ) {
				$workspace_id = (string) ( $workspace['public_id'] ?? '' );

				if ( '' !== $workspace_id && null !== $this->templates->find_template( $workspace_id, $template_id ) ) {
					return $workspace_id;
				}
			}
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * List templates.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_templates( $request ) {
		$workspace_id = $this->workspace_from( $request );

		if ( '' === $workspace_id ) {
			return $this->error( 'template_workspace_required', __( 'A workspace is required.', 'replicaforge' ), 400 );
		}

		$args = array(
			'per_page' => (int) ( $request->get_param( 'per_page' ) ?? 25 ),
			'page'     => (int) ( $request->get_param( 'page' ) ?? 1 ),
			'search'   => (string) ( $request->get_param( 'search' ) ?? '' ),
			'type'     => (string) ( $request->get_param( 'type' ) ?? '' ),
			'status'   => (string) ( $request->get_param( 'status' ) ?? '' ),
		);

		$page = $this->templates->browse_for_user( $workspace_id, get_current_user_id(), $args );

		return new \WP_REST_Response(
			array(
				'items'   => array_map( array( $this, 'present' ), $page['items'] ),
				'count'   => (int) $page['count'],
				'page'    => (int) $page['page'],
				'per_page' => (int) $page['per_page'],
				'has_more' => (bool) $page['has_more'],
				'facets'  => $this->templates->facets( $workspace_id ),
				'vocabularies' => array(
					'types'     => Template_Limits::type_labels(),
					'statuses'  => Template_Limits::STATUSES,
					'categories' => Template_Limits::CATEGORIES,
					'visibility' => Template_Limits::VISIBILITY,
				),
			),
			200
		);
	}

	/**
	 * Get one template with its current snapshot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_template( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$template_id  = (string) $request->get_param( 'id' );

		$template = $this->templates->find_template( $workspace_id, $template_id );

		if ( null === $template ) {
			return $this->error( 'template_not_available', __( 'That template is not available.', 'replicaforge' ), 404 );
		}

		$version = $this->versions->current( $workspace_id, $template_id );
		$out     = $this->present( $template );

		$out['snapshot']  = null === $version ? null : $this->public_snapshot( $version );
		$out['quality']   = null === $version ? null : ( new Template_Quality() )->measure( $this->snapshot_of( $version ) );
		$out['versions']  = count( $this->versions->history( $template_id, 20 ) );

		return new \WP_REST_Response( $out, 200 );
	}

	/**
	 * Update a template's metadata.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_template( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$template_id  = (string) $request->get_param( 'id' );

		$changes = array();

		foreach ( array( 'name', 'description', 'type', 'visibility', 'tags' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$changes[ $key ] = $request->get_param( $key );
			}
		}

		$updated = $this->templates->update_meta( $template_id, $changes );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->audit( 'template_updated', $template_id, $workspace_id, array_keys( $changes ) );

		$template = $this->templates->find_template( $workspace_id, $template_id );

		return new \WP_REST_Response( null === $template ? array( 'updated' => true ) : $this->present( $template ), 200 );
	}

	/**
	 * Delete a template and its versions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_template( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$template_id  = (string) $request->get_param( 'id' );

		$template = $this->templates->find_template( $workspace_id, $template_id );

		if ( null === $template ) {
			return $this->error( 'template_not_available', __( 'That template is not available.', 'replicaforge' ), 404 );
		}

		$this->audit( 'template_deleted', $template_id, $workspace_id, array( 'name' => (string) $template['name'] ) );

		return new \WP_REST_Response( array( 'deleted' => (bool) $this->templates->delete_template( $template_id ) ), 200 );
	}

	/**
	 * Extract a template from a generated page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function extract_template( $request ) {
		$post_id    = (int) $request->get_param( 'post_id' );
		$project_id = (string) $request->get_param( 'project_id' );
		$workspace_id = $this->workspace_from( $request );

		/*
		 * The draft must belong to a project the caller can reach, and the project must
		 * belong to this workspace. A post id on its own is a guessable integer, so
		 * extraction from an arbitrary post is an IDOR until this check runs.
		 */
		$project = $this->authorise_post( $post_id, $project_id, $workspace_id );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$type = $request->get_param( 'type' );
		$type = Template_Limits::is_template_type( $type ) ? (string) $type : 'custom';

		$extracted = $this->extractor->extract(
			$post_id,
			array(
				'project_id' => (string) ( $project['project_id'] ?? $project_id ),
				'type'       => $type,
			)
		);

		if ( is_wp_error( $extracted ) ) {
			return $extracted;
		}

		if ( ! $request->get_param( 'save' ) ) {
			// A preview. Nothing is written, so no journal is needed.
			return new \WP_REST_Response(
				array(
					'saved'      => false,
					'preview'    => $this->preview_payload( $extracted ),
					'validation' => $extracted['validation'],
					'quality'    => ( new Template_Quality() )->measure( $extracted['snapshot'], array() ),
				),
				200
			);
		}

		$snapshot = $extracted['snapshot'];
		$snapshot['meta'] = array(
			'name'        => (string) $extracted['post_title'],
			'description' => (string) $extracted['description'],
			'type'        => $type,
			'tags'        => array(),
		);

		$installed = $this->installer->install(
			$workspace_id,
			$snapshot,
			array(
				'user_id'          => get_current_user_id(),
				'category'         => 'project',
				'visibility'       => 'private',
				'source_post_id'   => $post_id,
				'source_project_id' => (string) ( $project['project_id'] ?? $project_id ),
				'change_note'      => __( 'Extracted from a generated page.', 'replicaforge' ),
			)
		);

		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		$this->audit( 'template_created', (string) $installed['template_id'], $workspace_id, array( 'source_post_id' => $post_id, 'type' => $type ) );

		/*
		 * The project's own version record. `Project_Repository::add_version()` has had no
		 * production caller since Phase 1, which is why Phase 15's approval gates always
		 * read `status = none` — see `Workflow_Repository`'s class docblock. Extracting a
		 * template is a real change to a project, so it is recorded as one. That is the
		 * Phase 17/18 versioning integration §11 asks for, using the existing system rather
		 * than a second one.
		 */
		$this->record_project_version( $project, $extracted );

		return new \WP_REST_Response(
			array(
				'saved'      => true,
				'template_id' => (string) $installed['template_id'],
				'version'    => (int) $installed['version'],
				'preview'    => $this->preview_payload( $extracted ),
				'validation' => $installed['validation'],
				'warnings'   => $extracted['warnings'],
			),
			200
		);
	}

	/**
	 * Validate a template without changing it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function validate_template( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$template_id  = (string) $request->get_param( 'id' );

		$version = $this->versions->current( $workspace_id, $template_id );

		if ( null === $version ) {
			return $this->error( 'template_not_available', __( 'That template is not available.', 'replicaforge' ), 404 );
		}

		$verified = $this->versions->verify( $version );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$snapshot = $this->snapshot_of( $version );

		return new \WP_REST_Response(
			array(
				'validation' => $this->validator->validate( $snapshot ),
				'quality'    => ( new Template_Quality() )->measure( $snapshot ),
			),
			200
		);
	}

	/**
	 * List a template's versions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_versions( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$template_id  = (string) $request->get_param( 'id' );

		if ( null === $this->templates->find_template( $workspace_id, $template_id ) ) {
			return $this->error( 'template_not_available', __( 'That template is not available.', 'replicaforge' ), 404 );
		}

		$out = array();

		foreach ( $this->versions->history( $template_id, 20 ) as $version ) {
			$out[] = array(
				'version'     => (int) $version['version'],
				'version_id'  => (string) $version['public_id'],
				'bytes'       => (int) $version['bytes'],
				'change_note' => (string) $version['change_note'],
				'user_id'     => (int) $version['user_id'],
				'created_at'  => (string) $version['created_at'],
				'schema'      => (string) $version['schema_version'],
			);
		}

		return new \WP_REST_Response( array( 'items' => $out, 'count' => count( $out ) ), 200 );
	}

	/**
	 * Compare two versions of a template.
	 *
	 * §22 asks for layout, components, tokens, typography, responsive rules, interactions,
	 * assets and content slots, reported as added / removed / modified / unchanged. That is
	 * computed here from the two stored snapshots rather than delegated, because the
	 * comparison is over *template* structures and Phase 5's comparators are over *source
	 * and generated pages* — a different comparison with a different vocabulary. Reusing
	 * them would mean translating every template structure into a page representation first,
	 * which loses exactly the detail §22 asks about.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function compare_versions( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$template_id  = (string) $request->get_param( 'id' );
		$from         = (int) $request->get_param( 'from' );
		$to           = (int) $request->get_param( 'to' );

		if ( null === $this->templates->find_template( $workspace_id, $template_id ) ) {
			return $this->error( 'template_not_available', __( 'That template is not available.', 'replicaforge' ), 404 );
		}

		$left  = $this->versions->get( $template_id, $from );
		$right = $this->versions->get( $template_id, $to );

		if ( null === $left || null === $right ) {
			return $this->error( 'template_version_missing', __( 'One of those versions does not exist.', 'replicaforge' ), 404 );
		}

		return new \WP_REST_Response( $this->compare( $this->snapshot_of( $left ), $this->snapshot_of( $right ) ), 200 );
	}

	/**
	 * Compare two snapshots.
	 *
	 * @param array<string, mixed> $left  Earlier snapshot.
	 * @param array<string, mixed> $right Later snapshot.
	 * @return array<string, mixed>
	 */
	private function compare( array $left, array $right ) {
		$dimensions = array();

		$dimensions['layout'] = $this->compare_documents(
			isset( $left['document']['elements'] ) ? (array) $left['document']['elements'] : array(),
			isset( $right['document']['elements'] ) ? (array) $right['document']['elements'] : array()
		);

		foreach ( array( 'tokens', 'components', 'assets', 'content_slots' ) as $dimension ) {
			$dimensions[ $dimension ] = $this->compare_keyed(
				isset( $left[ $dimension ] ) && is_array( $left[ $dimension ] ) ? $left[ $dimension ] : array(),
				isset( $right[ $dimension ] ) && is_array( $right[ $dimension ] ) ? $right[ $dimension ] : array(),
				'tokens' === $dimension ? 'value' : null
			);
		}

		$dimensions['typography'] = $this->compare_typography(
			isset( $left['tokens'] ) && is_array( $left['tokens'] ) ? $left['tokens'] : array(),
			isset( $right['tokens'] ) && is_array( $right['tokens'] ) ? $right['tokens'] : array()
		);

		foreach ( array( 'responsive', 'interactions' ) as $dimension ) {
			$dimensions[ $dimension ] = $this->compare_presence(
				isset( $left[ $dimension ] ) ? $left[ $dimension ] : array(),
				isset( $right[ $dimension ] ) ? $right[ $dimension ] : array()
			);
		}

		return array(
			'from'       => isset( $left['validation'] ) ? $left['validation'] : array(),
			'dimensions' => $dimensions,
			'note'       => __( 'Unchanged means both versions hold the same value for that key. Added and removed are keys present in only one version.', 'replicaforge' ),
		);
	}

	/**
	 * Compare two element documents by shape.
	 *
	 * @param array<int, mixed> $left  Earlier.
	 * @param array<int, mixed> $right Later.
	 * @return array<string, mixed>
	 */
	private function compare_documents( array $left, array $right ) {
		return $this->diff_keyed( $this->flatten_paths( $left ), $this->flatten_paths( $right ) );
	}

	/**
	 * Flatten a document into `path => type` pairs, for comparison.
	 *
	 * A private method rather than a recursive closure: a closure that calls itself has to be
	 * passed to itself by reference, and a closure with a `use` list cannot also declare a
	 * default argument, so the shape is awkward enough that it was originally written wrong.
	 * A method has neither problem and matches the rest of the file.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @param string            $prefix   Path prefix.
	 * @return array<string, string>
	 */
	private function flatten_paths( array $elements, $prefix = '' ) {
		$out = array();

		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$path = $prefix . '/' . (int) $index;

			$out[ $path ] = (string) ( $element['elType'] ?? '' ) . ':' . (string) ( $element['widgetType'] ?? '' );

			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();

			if ( array() !== $children ) {
				$out = array_merge( $out, $this->flatten_paths( $children, $path ) );
			}
		}

		return $out;
	}

	/**
	 * Compare two keyed sets.
	 *
	 * @param array<string, mixed> $left    Earlier.
	 * @param array<string, mixed> $right   Later.
	 * @param string|null         $value_of Key to compare when deciding modification.
	 * @return array<string, mixed>
	 */
	private function compare_keyed( array $left, array $right, $value_of = null ) {
		$normalise = function ( $record ) use ( $value_of ) {
			if ( null !== $value_of && is_array( $record ) && isset( $record[ $value_of ] ) ) {
				return (string) $record[ $value_of ];
			}
			return is_array( $record ) ? wp_json_encode( $record ) : (string) $record;
		};

		$out = array();

		foreach ( $right as $key => $record ) {
			$out[ (string) $key ] = $normalise( $record );
		}

		$previous = array();

		foreach ( $left as $key => $record ) {
			$previous[ (string) $key ] = $normalise( $record );
		}

		return $this->diff_keyed( $previous, $out );
	}

	/**
	 * Compare presence of two blocks.
	 *
	 * @param mixed $left  Earlier.
	 * @param mixed $right Later.
	 * @return array<string, mixed>
	 */
	private function compare_presence( $left, $right ) {
		$has = static function ( $value ) {
			return is_array( $value ) ? ( array() !== $value ) : ( '' !== (string) $value );
		};

		$was  = $has( $left );
		$now  = $has( $right );

		return array(
			'added'     => array(),
			'removed'   => array(),
			'modified'  => array(),
			'unchanged' => array( 'responsive' => array( 'was' => $was, 'now' => $now ) ),
			'note'      => $was === $now ? __( 'Present in both versions.', 'replicaforge' ) : __( 'Present in only one version.', 'replicaforge' ),
		);
	}

	/**
	 * Compare the typography tokens of two versions.
	 *
	 * @param array<string, mixed> $left  Earlier tokens.
	 * @param array<string, mixed> $right Later tokens.
	 * @return array<string, mixed>
	 */
	private function compare_typography( array $left, array $right ) {
		$pick = static function ( array $tokens ) {
			$out = array();

			foreach ( $tokens as $id => $token ) {
				if ( is_array( $token ) && 'typography' === (string) ( $token['category'] ?? '' ) ) {
					$out[ (string) $id ] = (string) ( $token['value'] ?? '' );
				}
			}

			return $out;
		};

		return $this->diff_keyed( $pick( $left ), $pick( $right ) );
	}

	/**
	 * Produce an added / removed / modified / unchanged report for two keyed sets.
	 *
	 * @param array<string, mixed> $left  Earlier.
	 * @param array<string, mixed> $right Later.
	 * @return array<string, mixed>
	 */
	private function diff_keyed( array $left, array $right ) {
		$added     = array();
		$removed   = array();
		$modified  = array();
		$unchanged = array();

		foreach ( $right as $key => $value ) {
			if ( ! array_key_exists( $key, $left ) ) {
				$added[] = $key;
				continue;
			}
			if ( (string) $left[ $key ] === (string) $value ) {
				$unchanged[] = $key;
				continue;
			}
			$modified[ $key ] = array( 'from' => (string) $left[ $key ], 'to' => (string) $value );
		}

		foreach ( $left as $key => $value ) {
			if ( ! array_key_exists( $key, $right ) ) {
				$removed[] = $key;
			}
		}

		return array(
			'added'     => $added,
			'removed'   => $removed,
			'modified'  => $modified,
			'unchanged' => $unchanged,
			'counts'    => array( 'added' => count( $added ), 'removed' => count( $removed ), 'modified' => count( $modified ), 'unchanged' => count( $unchanged ) ),
		);
	}

	/**
	 * Export a template package.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export_template( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$template_id  = (string) $request->get_param( 'id' );

		$template = $this->templates->find_template( $workspace_id, $template_id );

		if ( null === $template ) {
			return $this->error( 'template_not_available', __( 'That template is not available.', 'replicaforge' ), 404 );
		}

		$version = $this->versions->current( $workspace_id, $template_id );

		if ( null === $version ) {
			return $this->error( 'template_no_version', __( 'This template has no stored version to export.', 'replicaforge' ), 409 );
		}

		$package = $this->packages->export(
			$template,
			$version,
			array( 'include_assets' => (bool) $request->get_param( 'include_assets' ) )
		);

		if ( is_wp_error( $package ) ) {
			return $package;
		}

		$this->audit( 'template_exported', $template_id, $workspace_id, array( 'bytes' => $package['bytes'] ) );

		if ( $request->get_param( 'download' ) ) {
			return new \WP_REST_Response( $package['encoded'], 200, array(
				'Content-Type'        => 'application/json; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="' . $package['filename'] . '"',
			) );
		}

		return new \WP_REST_Response( $package, 200 );
	}

	/**
	 * Archive a template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function archive_template( $request ) {
		return $this->set_archived( $request, true );
	}

	/**
	 * Restore a template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore_template( $request ) {
		return $this->set_archived( $request, false );
	}

	/**
	 * Archive or restore.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $archived Whether to archive.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function set_archived( $request, $archived ) {
		$workspace_id = $this->workspace_from( $request );
		$template_id  = (string) $request->get_param( 'id' );

		if ( null === $this->templates->find_template( $workspace_id, $template_id ) ) {
			return $this->error( 'template_not_available', __( 'That template is not available.', 'replicaforge' ), 404 );
		}

		$ok = $archived ? $this->templates->archive( $template_id ) : $this->templates->restore( $template_id );

		$this->audit( $archived ? 'template_archived' : 'template_restored', $template_id, $workspace_id, array() );

		return new \WP_REST_Response( array( 'archived' => (bool) $archived, 'ok' => (bool) $ok ), 200 );
	}

	/**
	 * Change a template's visibility.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function share_template( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$template_id  = (string) $request->get_param( 'id' );
		$visibility   = (string) $request->get_param( 'visibility' );

		if ( null === $this->templates->find_template( $workspace_id, $template_id ) ) {
			return $this->error( 'template_not_available', __( 'That template is not available.', 'replicaforge' ), 404 );
		}

		$updated = $this->templates->update_meta( $template_id, array( 'visibility' => $visibility ) );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->audit( 'template_shared', $template_id, $workspace_id, array( 'visibility' => $visibility ) );

		return new \WP_REST_Response( array( 'visibility' => $visibility ), 200 );
	}

	/**
	 * Inspect an incoming package.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function inspect_package( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$raw          = (string) $request->get_param( 'package' );

		if ( '' === $workspace_id ) {
			return $this->error( 'template_workspace_required', __( 'A workspace is required.', 'replicaforge' ), 400 );
		}

		$inspected = $this->packages->inspect( $raw );

		if ( is_wp_error( $inspected ) ) {
			return $inspected;
		}

		$conflicts = $this->conflicts->detect( $workspace_id, $inspected['snapshot'] );

		return new \WP_REST_Response(
			array(
				'steps'        => $inspected['steps'],
				'validation'   => $inspected['validation'],
				'quality'      => $inspected['quality'],
				'compatibility' => $inspected['compatibility'],
				'assets'       => $inspected['assets'],
				'removals'     => $inspected['removals'],
				'conflicts'    => $conflicts,
				'meta'         => $inspected['snapshot']['meta'],
				'installable'  => (bool) $inspected['installable'] && ! $conflicts['blocking'],
				'next_step'    => $conflicts['blocking'] ? null : 'user_confirmation',
			),
			200
		);
	}

	/**
	 * Install an inspected package.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function install_package( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$raw          = (string) $request->get_param( 'package' );
		$strategy     = (string) ( $request->get_param( 'strategy' ) ?? 'keep_existing' );

		$inspected = $this->packages->inspect( $raw );

		if ( is_wp_error( $inspected ) ) {
			return $inspected;
		}

		$snapshot = $inspected['snapshot'];

		/*
		 * Re-detected and planned against a *fresh* read, not the list the user saw in
		 * `inspect`. Between the two requests a colleague may have created a template with
		 * the same name, and acting on the stale list would overwrite it — which is the exact
		 * failure §27 forbids.
		 */
		$plan = $this->conflicts->plan(
			$workspace_id,
			$snapshot,
			array( 'default' => Template_Limits::is_conflict_strategy( $strategy ) ? $strategy : 'keep_existing' )
		);

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( ! empty( $plan['renames'] ) ) {
			$name = (string) ( $snapshot['meta']['name'] ?? '' );

			foreach ( $plan['renames'] as $rename ) {
				if ( 'template_name' === (string) $rename['kind'] && '' !== $name ) {
					$snapshot['meta']['name'] = (string) $rename['label'];
				}
			}
		}

		$installed = $this->installer->install(
			$workspace_id,
			$snapshot,
			array(
				'user_id'     => get_current_user_id(),
				'category'    => 'imported',
				'visibility'  => 'private',
				'merge_tokens' => ! empty( $plan['merge_tokens'] ),
				'change_note' => __( 'Imported from a template package.', 'replicaforge' ),
				'validation_state' => (string) $inspected['validation']['state'],
			)
		);

		if ( is_wp_error( $installed ) ) {
			return $installed;
		}

		$this->audit( 'template_imported', (string) $installed['template_id'], $workspace_id, array( 'strategy' => $strategy ) );

		return new \WP_REST_Response( $installed, 200 );
	}

	/**
	 * List reusable components.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_components( $request ) {
		$workspace_id = $this->workspace_from( $request );

		if ( '' === $workspace_id ) {
			return $this->error( 'template_workspace_required', __( 'A workspace is required.', 'replicaforge' ), 400 );
		}

		$page = $this->components->browse(
			$workspace_id,
			array(
				'type'     => (string) ( $request->get_param( 'type' ) ?? '' ),
				'search'   => (string) ( $request->get_param( 'search' ) ?? '' ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?? 25 ),
			)
		);

		$items = array();

		foreach ( $page['items'] as $component ) {
			$changelog = isset( $component['provenance']['changelog'] ) && is_array( $component['provenance']['changelog'] ) ? $component['provenance']['changelog'] : array();

			$items[] = array(
				'component_id'   => (string) $component['component_id'],
				'name'           => (string) $component['name'],
				'type'           => (string) $component['type'],
				'description'    => (string) $component['description'],
				'status'         => (string) $component['status'],
				'version'        => (int) $component['version'],
				'version_count'  => (int) $component['version_count'],
				'usage_count'    => (int) $component['usage_count'],
				'dependencies'   => (array) ( $component['dependencies'] ?? array() ),
				'compatibility'  => (array) ( $component['compatibility'] ?? array() ),
				'validation'     => (array) ( $component['validation'] ?? array() ),
				'changelog'      => array_slice( $changelog, 0, 5 ),
				'updated_at'     => (string) $component['updated_at'],
			);
		}

		return new \WP_REST_Response( array( 'items' => $items, 'count' => (int) $page['count'] ), 200 );
	}

	/**
	 * Report whether a component update is safe.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function component_update_safety( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$component_id = (string) $request->get_param( 'id' );
		$target       = (int) ( $request->get_param( 'target' ) ?? 0 );

		$safety = $this->components->update_safety( $workspace_id, $component_id, $target );

		if ( null === $this->components->find_component( $workspace_id, $component_id ) ) {
			return $this->error( 'component_not_available', __( 'That component is not available.', 'replicaforge' ), 404 );
		}

		return new \WP_REST_Response( $safety, 200 );
	}

	/**
	 * List the workspace's design tokens.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_tokens( $request ) {
		$workspace_id = $this->workspace_from( $request );

		if ( '' === $workspace_id ) {
			return $this->error( 'template_workspace_required', __( 'A workspace is required.', 'replicaforge' ), 400 );
		}

		$registry = $this->tokens->registry( $workspace_id );

		return new \WP_REST_Response(
			array(
				'tokens'    => array_values( $registry ),
				'summary'   => Design_Token_Registry::summarise( $registry ),
				'roles'     => Template_Limits::SEMANTIC_ROLES,
				'categories' => Template_Limits::TOKEN_CATEGORIES,
			),
			200
		);
	}

	/**
	 * Set a design token by hand.
	 *
	 * §8 requires the affected resources to be shown and confirmation to be required. So
	 * this route does not change anything on its own: it requires an `impact_confirmed`
	 * flag, and without it the impact report is returned instead. A caller that skips the
	 * second request gets the answer, not the change.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_token( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$token_id     = (string) $request->get_param( 'token_id' );
		$value        = $request->get_param( 'value' );

		$impact = $this->tokens->impact( $workspace_id, $token_id );

		if ( null === $impact['token'] ) {
			return $this->error( 'token_not_available', __( 'That design token is not in this workspace.', 'replicaforge' ), 404 );
		}

		if ( ! $request->get_param( 'impact_confirmed' ) ) {
			return new \WP_REST_Response(
				array(
					'changed'  => false,
					'requires_confirmation' => true,
					'impact'   => $impact,
				),
				200
			);
		}

		$set = $this->tokens->set( $workspace_id, $token_id, $value );

		if ( is_wp_error( $set ) ) {
			return $set;
		}

		$this->audit( 'design_token_changed', $token_id, $workspace_id, array( 'affected' => count( $impact['templates'] ) + count( $impact['components'] ) ) );

		return new \WP_REST_Response( array( 'changed' => true, 'token_id' => $token_id, 'value' => is_scalar( $value ) ? (string) $value : null ), 200 );
	}

	/**
	 * Report what a token change would affect.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function token_impact( $request ) {
		$workspace_id = $this->workspace_from( $request );
		$token_id     = (string) $request->get_param( 'id' );

		return new \WP_REST_Response( $this->tokens->impact( $workspace_id, $token_id ), 200 );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Reduce a template row to the API shape.
	 *
	 * @param array<string, mixed> $template Row.
	 * @return array<string, mixed>
	 */
	private function present( array $template ) {
		return array(
			'template_id'     => (string) $template['public_id'],
			'name'            => (string) $template['name'],
			'description'     => (string) $template['description'],
			'type'            => (string) $template['type'],
			'type_label'      => Template_Limits::type_label( (string) $template['type'] ),
			'type_group'      => Template_Limits::template_group( (string) $template['type'] ),
			'category'        => (string) $template['category'],
			'status'          => (string) $template['status'],
			'visibility'      => (string) $template['visibility'],
			'owner_id'        => (int) $template['user_id'],
			'version_count'   => (int) $template['version_count'],
			'current_version' => (string) $template['current_version'],
			'validation_state' => (string) $template['validation_state'],
			'install_count'   => (int) $template['install_count'],
			'source_post_id'  => (int) $template['source_post_id'],
			'source_project_id' => (string) $template['source_project_id'],
			'tags'            => (array) ( $template['tags'] ?? array() ),
			'created_at'      => (string) $template['created_at'],
			'updated_at'      => (string) $template['updated_at'],
		);
	}

	/**
	 * Reduce a stored version row to a snapshot.
	 *
	 * @param array<string, mixed> $version Row.
	 * @return array<string, mixed>
	 */
	private function snapshot_of( array $version ) {
		$out = array();

		foreach ( array( 'document', 'responsive', 'interactions', 'design_system', 'tokens', 'components', 'content_slots', 'assets', 'dependencies', 'compatibility', 'provenance', 'validation' ) as $section ) {
			$out[ $section ] = isset( $version[ $section ] ) ? $version[ $section ] : array();
		}

		return $out;
	}

	/**
	 * Reduce a snapshot for a response, omitting the heaviest internal parts.
	 *
	 * The full document is returned — it is what a preview needs — but the design system's
	 * raw families are not, because a client rendering a summary does not need 400 token
	 * records it has already been given separately.
	 *
	 * @param array<string, mixed> $version Stored version row.
	 * @return array<string, mixed>
	 */
	private function public_snapshot( array $version ) {
		$snapshot = $this->snapshot_of( $version );

		$out = array(
			'document'      => $snapshot['document'],
			'responsive'    => $snapshot['responsive'],
			'interactions'  => $snapshot['interactions'],
			'content_slots' => $snapshot['content_slots'],
			'assets'        => $snapshot['assets'],
			'dependencies'  => $snapshot['dependencies'],
			'compatibility' => $snapshot['compatibility'],
			'provenance'    => $snapshot['provenance'],
			'validation'    => $snapshot['validation'],
			'token_summary' => Design_Token_Registry::summarise( isset( $snapshot['tokens'] ) && is_array( $snapshot['tokens'] ) ? $snapshot['tokens'] : array() ),
			'slot_summary'  => Content_Slot_Registry::summarise( isset( $snapshot['content_slots'] ) && is_array( $snapshot['content_slots'] ) ? $snapshot['content_slots'] : array() ),
		);

		return $out;
	}

	/**
	 * Build the preview payload for an extraction.
	 *
	 * @param array<string, mixed> $extracted Extraction result.
	 * @return array<string, mixed>
	 */
	private function preview_payload( array $extracted ) {
		return array(
			'post_id'        => (int) $extracted['post_id'],
			'post_title'     => (string) $extracted['post_title'],
			'description'    => (string) $extracted['description'],
			'element_count'  => (int) $extracted['element_count'],
			'removed'        => (int) $extracted['removed'],
			'removals'       => (array) $extracted['removals'],
			'tokens'         => $extracted['tokens'],
			'slots'          => $extracted['slots'],
			'assets'         => $extracted['assets'],
			'warnings'       => (array) $extracted['warnings'],
			'representation_source' => (string) $extracted['representation_source'],
			'viewports'      => Validation_Limits::VIEWPORTS,
		);
	}

	/**
	 * Authorise extraction from a post.
	 *
	 * @param int    $post_id      Post id.
	 * @param string $project_id   Project id.
	 * @param string $workspace_id Workspace id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function authorise_post( $post_id, $project_id, $workspace_id ) {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! is_object( $post ) ) {
			return $this->error( 'template_source_missing', __( 'That page is not available.', 'replicaforge' ), 404 );
		}

		/*
		 * The generation id on the post is the only reliable link back to a project, and it
		 * is written by Phase 4 for exactly this purpose. A post with no generation id was
		 * not produced by ReplicaForge, so there is nothing to attribute it to and extraction
		 * is refused — rather than extracting from an arbitrary page a user happened to name.
		 */
		$generation_id = (string) get_post_meta( $post_id, 'replicaforge_generation_id', true );

		if ( '' === $generation_id ) {
			return $this->error(
				'template_not_generated',
				__( 'That page was not generated by ReplicaForge, so it has no design provenance to extract from.', 'replicaforge' ),
				array( 'status' => 409 )
			);
		}

		if ( '' === $project_id ) {
			return $this->error(
				'template_project_required',
				__( 'A project is required, so the template can be attributed to the site it came from.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$context   = ( new Project_Context_Store() )->get( $project_id );
		$belongs   = (string) ( $context['workspace_id'] ?? '' );

		if ( '' === $belongs || ( '' !== $workspace_id && $belongs !== $workspace_id ) ) {
			return $this->error( 'template_source_missing', __( 'That page is not available.', 'replicaforge' ), 404 );
		}

		$permissions = new Permission_Manager();

		if ( ! user_can( get_current_user_id(), 'manage_options' ) && ! $permissions->can_in_project( get_current_user_id(), $belongs, $project_id, 'projects.view' ) ) {
			return $this->error( 'template_source_missing', __( 'That page is not available.', 'replicaforge' ), 404 );
		}

		return array( 'project_id' => $project_id, 'workspace_id' => $belongs, 'generation_id' => $generation_id );
	}

	/**
	 * Record a project version for an extraction.
	 *
	 * @param array<string, mixed> $project   Project context.
	 * @param array<string, mixed> $extracted Extraction result.
	 * @return void
	 */
	private function record_project_version( array $project, array $extracted ) {
		$project_id = (string) ( $project['project_id'] ?? '' );

		if ( '' === $project_id ) {
			return;
		}

		$repository = new Project_Repository( $this->logger );

		$repository->add_version(
			$project_id,
			array(
				'change'  => 'template_extracted',
				'impact'  => 'none',
				'note'    => sprintf(
					/* translators: %s: the number of elements in the template. */
					__( 'Extracted a reusable template from the generated page (%d elements).', 'replicaforge' ),
					(int) $extracted['element_count']
				),
				'analysis' => isset( $extracted['snapshot']['design_system'] ) ? $extracted['snapshot']['design_system'] : null,
			)
		);
	}

	/**
	 * Record an audit event.
	 *
	 * @param string              $event        Event name.
	 * @param string              $target_id    Target id.
	 * @param string              $workspace_id Workspace id.
	 * @param array<string, mixed> $context      Context.
	 * @return void
	 */
	/**
	 * Record an audit event.
	 *
	 * Two destinations, deliberately.
	 *
	 * `Collaboration_Log::audit()` is the **workspace** audit trail — the one a workspace
	 * administrator reads, stored in the `replicaforge_audit` table with its own retention
	 * and a hashed IP. That is where "who shared this template" belongs, because the answer
	 * is only meaningful relative to the workspace it was shared into.
	 *
	 * `Audit_Log::record()` is the **site-wide** commercial log, with its own event
	 * vocabulary and retention. A template event that is not in that vocabulary is recorded
	 * under a generic action rather than being invented into it, because adding to
	 * `Plan_Limits`/`Audit_Log::events()` is a Phase 10 contract and this phase does not get
	 * to widen it.
	 *
	 * §38 asks for the significant events to be recorded. Recording them in the trail an
	 * administrator of the relevant workspace actually reads is what makes that useful;
	 * writing to a log nobody opens is not.
	 *
	 * @param string              $event        Event name.
	 * @param string              $target_id    Target id.
	 * @param string              $workspace_id Workspace id.
	 * @param array<string, mixed> $context      Context.
	 * @return void
	 */
	private function audit( $event, $target_id, $workspace_id, array $context = array() ) {
		$context = array_merge(
			$context,
			array(
				'resource_type' => 'template',
				'resource_id'   => (string) $target_id,
				'workspace_id'  => (string) $workspace_id,
			)
		);

		if ( '' !== (string) $workspace_id ) {
			( new Collaboration_Log() )->audit( (string) $workspace_id, (string) $event, $context, get_current_user_id() );
		}

		$known = method_exists( 'ReplicaForge\\Audit_Log', 'events' ) ? array_keys( (array) Audit_Log::events() ) : array();
		$action = in_array( (string) $event, $known, true ) ? (string) $event : 'template_action';

		Audit_Log::record( $action, $context, get_current_user_id() );
	}

	/**
	 * Build an error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private function error( $code, $message, $status = 400 ) {
		return new \WP_Error( (string) $code, (string) $message, array( 'status' => (int) $status ) );
	}
}
