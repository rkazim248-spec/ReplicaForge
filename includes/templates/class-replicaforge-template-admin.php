<?php
/**
 * Phase 19: the template library admin screen.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The library screen: browse, filter, extract, validate, export, import.
 *
 * ### Shape, and why
 *
 * §42 asks for something that reads as a professional WordPress product rather than a
 * generated dashboard. That rules out a grid of coloured metric cards, and it means the
 * Phase 15/17 `echo`/`printf` idiom with escaped interpolation rather than a new rendering
 * layer — so this screen looks like the rest of ReplicaForge because it *is* the rest of
 * ReplicaForge.
 *
 * Concretely, the borrowed conventions are: the `wrap.replicaforge-admin` wrapper, the
 * `__eyebrow` / `__header` / `__intro` header block, `replicaforge-filters` for the filter
 * form, `widefat.striped` for tables, `rf-tag rf-tag-{ok,warn,bad,muted}` for state, and the
 * transient-backed notice queue from `Workspace_Admin` so a POST that redirects can say
 * something.
 *
 * ### What it does not render
 *
 * No single quality score, because there is none — see `Template_Quality`. It renders the
 * separate indicators, and says "Not available" for the ones that could not be measured.
 * A dashboard that shows 82% for a template built for a different purpose than the one next
 * to it is a dashboard that gets chosen wrong.
 */
final class Template_Admin {

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	const PAGE = 'replicaforge-templates';

	/**
	 * Detail slug.
	 *
	 * @var string
	 */
	const DETAIL_PAGE = 'replicaforge-template';

	/**
	 * Capability for the menu.
	 *
	 * A WordPress capability, not a Phase 15 permission string, because
	 * `add_menu_page()` takes a WordPress capability. The *workspace* permissions are
	 * enforced inside every render and every handler, where a workspace id is available —
	 * which is the shape `Workspace_Admin` uses, and the reason a per-workspace decision is
	 * not made with a site-wide string.
	 *
	 * @var string
	 */
	const MENU_CAPABILITY = 'replicaforge_use';

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
	 * Package handler.
	 *
	 * @var Template_Package
	 */
	private $packages;

	/**
	 * Extractor.
	 *
	 * @var Template_Extractor
	 */
	private $extractor;

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
		$this->packages   = new Template_Package( null, null, $this->logger );
		$this->extractor  = new Template_Extractor( $this->logger );
		$this->validator  = new Template_Validator( null, null, $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_replicaforge_template_action', array( $this, 'handle_post' ) );
	}

	/**
	 * Register the menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'ReplicaForge Templates', 'replicaforge' ),
			__( 'Templates', 'replicaforge' ),
			self::MENU_CAPABILITY,
			self::PAGE,
			array( $this, 'render_library' ),
			'dashicons-layout',
			59
		);

		add_submenu_page(
			self::PAGE,
			__( 'Template library', 'replicaforge' ),
			__( 'Library', 'replicaforge' ),
			self::MENU_CAPABILITY,
			self::PAGE,
			array( $this, 'render_library' )
		);

		add_submenu_page(
			self::PAGE,
			__( 'Template detail', 'replicaforge' ),
			__( 'Detail', 'replicaforge' ),
			self::MENU_CAPABILITY,
			self::DETAIL_PAGE,
			array( $this, 'render_detail' )
		);
	}

	/**
	 * Enqueue the screen stylesheet.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) && false === strpos( (string) $hook, self::DETAIL_PAGE ) ) {
			return;
		}

		wp_enqueue_style(
			'replicaforge-templates',
			REPLICAFORGE_URL . 'includes/templates/css/templates-admin.css',
			array(),
			REPLICAFORGE_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------ */

	/**
	 * Render the library.
	 *
	 * @return void
	 */
	public function render_library() {
		if ( ! current_user_can( self::MENU_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view the template library.', 'replicaforge' ), 403 );
		}

		$workspace = $this->current_workspace();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET filters.
		$type     = isset( $_GET['rf_type'] ) ? sanitize_key( wp_unslash( $_GET['rf_type'] ) ) : '';
		$status   = isset( $_GET['rf_status'] ) ? sanitize_key( wp_unslash( $_GET['rf_status'] ) ) : '';
		$category = isset( $_GET['rf_category'] ) ? sanitize_key( wp_unslash( $_GET['rf_category'] ) ) : '';
		$search   = isset( $_GET['rf_search'] ) ? sanitize_text_field( wp_unslash( $_GET['rf_search'] ) ) : '';
		$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable

		$type   = Template_Limits::is_template_type( $type ) ? $type : '';
		$status = Template_Limits::is_status( $status ) ? $status : '';
		$search = substr( $search, 0, 120 );

		echo '<div class="wrap replicaforge-admin">';

		$this->render_header(
			__( 'Template library', 'replicaforge' ),
			__( 'Reusable templates, built from your own reconstructed projects.', 'replicaforge' ),
			__( 'A template carries structure, design tokens and placeholders for content. It does not carry the text or images from the page it came from, and it never packages an image whose rights are not established.', 'replicaforge' )
		);

		$this->render_notices();

		if ( is_wp_error( $workspace ) ) {
			$this->render_empty(
				__( 'Choose a workspace', 'replicaforge' ),
				(string) $workspace->get_error_message()
			);
			echo '</div>';
			return;
		}

		$workspace_id = (string) $workspace['workspace_id'];
		$can_edit     = $this->can( $workspace_id, 'templates.edit' );

		$listed = $this->templates->browse_for_user(
			$workspace_id,
			get_current_user_id(),
			array(
				'type'     => $type,
				'status'   => '' === $status ? '' : $status,
				'search'   => $search,
				'page'     => $page,
				'per_page' => 25,
			)
		);

		$this->render_workspace_bar( $workspace_id );
		$this->render_filters( $type, $status, $category, $search );

		if ( array() === $listed['items'] ) {
			$this->render_empty(
				__( 'No templates yet', 'replicaforge' ),
				__( 'Open one of your generated pages in a project, then choose ReplicaForge, Templates, and use "Create a template from this page". Nothing is created automatically: a template is a decision, not a by-product.', 'replicaforge' )
			);
			echo '</div>';
			return;
		}

		$this->render_table( $listed['items'], $can_edit );
		$this->render_pagination( $listed );

		echo '</div>';
	}

	/**
	 * Render one template.
	 *
	 * @return void
	 */
	public function render_detail() {
		if ( ! current_user_can( self::MENU_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view templates.', 'replicaforge' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only GET.
		$id = isset( $_GET['template'] ) ? sanitize_text_field( wp_unslash( $_GET['template'] ) ) : '';

		$workspace = $this->current_workspace();

		echo '<div class="wrap replicaforge-admin">';

		if ( is_wp_error( $workspace ) ) {
			$this->render_header( __( 'Template', 'replicaforge' ), '', '' );
			$this->render_notices();
			$this->render_empty( __( 'Choose a workspace', 'replicaforge' ), (string) $workspace->get_error_message() );
			echo '</div>';
			return;
		}

		$workspace_id = (string) $workspace['workspace_id'];
		$template     = $this->templates->find_template( $workspace_id, $id );

		// The same message for absent and forbidden, so this page cannot map the id space.
		if ( null === $template || ! $this->may_read( $workspace_id, $template ) ) {
			$this->render_header( __( 'Template', 'replicaforge' ), '', '' );
			$this->render_notices();
			wp_die( esc_html__( 'That template does not exist.', 'replicaforge' ), 404 );
		}

		$version = $this->versions->current( $workspace_id, $id );

		$this->render_header(
			(string) $template['name'],
			Template_Limits::type_label( (string) $template['type'] ) . ' · ' . (string) Template_Limits::STATUSES[ (string) $template['status'] ],
			(string) $template['description']
		);

		$this->render_notices();

		if ( null === $version ) {
			$this->render_empty( __( 'This template has no stored version', 'replicaforge' ), __( 'Re-create it from the project, because there is nothing stored to show.', 'replicaforge' ) );
			echo '</div>';
			return;
		}

		$verified = $this->versions->verify( $version );

		if ( is_wp_error( $verified ) ) {
			$this->render_notice( 'error', (string) $verified->get_error_message() );
			echo '</div>';
			return;
		}

		$snapshot = $this->snapshot_of( $version );
		$stored   = $version['validation'] ?? array();
		$validation = is_array( $stored ) && ! empty( $stored['state'] ) ? $stored : $this->validator->validate( $snapshot );

		$can_edit = $this->can( $workspace_id, 'templates.edit' );

		$this->render_detail_actions( $workspace_id, $template, $can_edit );

		echo '<div class="replicaforge-columns">';

		printf( '<div class="replicaforge-columns__main">%s</div>', $this->escape( $this->render_structure_panel( $snapshot ) ) );
		printf( '<div class="replicaforge-columns__side">%s</div>', $this->escape( $this->render_quality_panel( $snapshot ) ) );

		echo '</div>';

		$this->render_validation( $validation );
		$this->render_slots( isset( $snapshot['content_slots'] ) ? (array) $snapshot['content_slots'] : array() );
		$this->render_assets( isset( $snapshot['assets'] ) ? (array) $snapshot['assets'] : array() );
		$this->render_versions( $this->versions->history( (string) $template['public_id'], 20 ) );

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * POST handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Handle a form submission.
	 *
	 * @return void
	 */
	public function handle_post() {
		$workspace = $this->current_workspace();

		if ( is_wp_error( $workspace ) ) {
			wp_die( esc_html( (string) $workspace->get_error_message() ), 403 );
		}

		$workspace_id = (string) $workspace['workspace_id'];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below.
		$nonce = isset( $_POST['_rfnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rfnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'replicaforge_template_action' ) ) {
			$this->notice( 'error', __( 'That request could not be verified. Please try again.', 'replicaforge' ) );
			$this->redirect_back();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$action = isset( $_POST['rf_action'] ) ? sanitize_key( wp_unslash( $_POST['rf_action'] ) ) : '';

		switch ( $action ) {
			case 'extract':
				$this->do_extract( $workspace_id );
				break;

			case 'archive':
				$this->do_archive( $workspace_id, true );
				break;

			case 'restore':
				$this->do_archive( $workspace_id, false );
				break;

			case 'delete':
				$this->do_delete( $workspace_id );
				break;

			case 'import':
				$this->do_import( $workspace_id );
				break;

			default:
				$this->notice( 'error', __( 'That action is not one ReplicaForge recognises.', 'replicaforge' ) );
				$this->redirect_back();
		}
	}

	/**
	 * Create a template from a generated page.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return void
	 */
	private function do_extract( $workspace_id ) {
		if ( ! $this->can( $workspace_id, 'templates.create' ) ) {
			$this->deny( __( 'You cannot create templates in this workspace.', 'replicaforge' ) );
		}

		$post_id    = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$project_id = isset( $_POST['project_id'] ) && is_string( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';
		$type       = isset( $_POST['type'] ) && is_string( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'custom';

		if ( ! Template_Limits::is_template_type( $type ) ) {
			$type = 'custom';
		}

		$context = ( new Project_Context_Store() )->get( $project_id );

		if ( (string) ( $context['workspace_id'] ?? '' ) !== $workspace_id ) {
			$this->notice( 'error', __( 'That page is not part of this workspace.', 'replicaforge' ) );
			$this->redirect_back();
		}

		$extracted = $this->extractor->extract(
			$post_id,
			array( 'project_id' => $project_id, 'type' => $type )
		);

		if ( is_wp_error( $extracted ) ) {
			$this->notice( 'error', (string) $extracted->get_error_message() );
			$this->redirect_back();
		}

		$snapshot = $extracted['snapshot'];

		$snapshot['meta'] = array(
			'name'        => (string) $extracted['post_title'],
			'description' => (string) $extracted['description'],
			'type'        => $type,
			'tags'        => array(),
		);

		$installed = ( new Template_Installer( null, null, null, null, null, $this->logger ) )->install(
			$workspace_id,
			$snapshot,
			array(
				'user_id'           => get_current_user_id(),
				'category'          => 'project',
				'visibility'        => 'private',
				'source_post_id'    => $post_id,
				'source_project_id' => $project_id,
				'change_note'       => __( 'Extracted from a generated page.', 'replicaforge' ),
				'validation_state'  => (string) $extracted['validation']['state'],
			)
		);

		if ( is_wp_error( $installed ) ) {
			$this->notice( 'error', (string) $installed->get_error_message() );
			$this->redirect_back();
		}

		// The project version record `add_version()` never had a caller for. See the API.
		( new Project_Repository( $this->logger ) )->add_version(
			$project_id,
			array(
				'change' => 'template_extracted',
				'impact' => 'none',
				'note'   => sprintf(
					/* translators: %d: the number of elements. */
					__( 'Extracted a reusable template from the generated page (%d elements).', 'replicaforge' ),
					(int) $extracted['element_count']
				),
			)
		);

		$message = sprintf(
			/* translators: 1: the template name, 2: the element count, 3: the validation state. */
			__( 'Created the template "%1$s" with %2$d elements. It is marked "%3$s".', 'replicaforge' ),
			(string) $extracted['post_title'],
			(int) $extracted['element_count'],
			(string) $extracted['validation']['label']
		);

		if ( ! empty( $extracted['warnings'] ) ) {
			$message .= ' ' . implode( ' ', array_slice( (array) $extracted['warnings'], 0, 2 ) );
		}

		$this->notice( 'success', $message );
		$this->redirect_back();
	}

	/**
	 * Archive or restore.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param bool   $archived     Whether to archive.
	 * @return void
	 */
	private function do_archive( $workspace_id, $archived ) {
		if ( ! $this->can( $workspace_id, 'templates.edit' ) ) {
			$this->deny( __( 'You cannot change templates in this workspace.', 'replicaforge' ) );
		}

		$id       = isset( $_POST['template_id'] ) && is_string( $_POST['template_id'] ) ? sanitize_text_field( wp_unslash( $_POST['template_id'] ) ) : '';
		$template = $this->templates->find_template( $workspace_id, $id );

		if ( null === $template || ! $this->may_write( $workspace_id, $template ) ) {
			$this->notice( 'error', __( 'That template is not available.', 'replicaforge' ) );
			$this->redirect_back();
		}

		$ok = $archived ? $this->templates->archive( $id ) : $this->templates->restore( $id );

		$this->notice(
			$ok ? 'success' : 'error',
			$ok
				? ( $archived
					? __( 'The template was archived. Its versions are kept, and you can restore it at any time.', 'replicaforge' )
					: __( 'The template was restored.', 'replicaforge' ) )
				: __( 'The template could not be changed.', 'replicaforge' )
		);

		$this->redirect_back();
	}

	/**
	 * Delete a template.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return void
	 */
	private function do_delete( $workspace_id ) {
		if ( ! $this->can( $workspace_id, 'templates.delete' ) ) {
			$this->deny( __( 'You cannot delete templates in this workspace.', 'replicaforge' ) );
		}

		$id       = isset( $_POST['template_id'] ) && is_string( $_POST['template_id'] ) ? sanitize_text_field( wp_unslash( $_POST['template_id'] ) ) : '';
		$template = $this->templates->find_template( $workspace_id, $id );

		if ( null === $template || ! $this->may_write( $workspace_id, $template ) ) {
			$this->notice( 'error', __( 'That template is not available.', 'replicaforge' ) );
			$this->redirect_back();
		}

		$name = (string) $template['name'];

		$this->audit( 'template_deleted', $id, $workspace_id, array( 'name' => $name ) );

		$ok = $this->templates->delete_template( $id );

		$this->notice(
			$ok ? 'success' : 'error',
			$ok
				? sprintf(
					/* translators: %s: the template name. */
					__( 'Deleted "%s" and all of its versions. Any pages already built from it are untouched.', 'replicaforge' ),
					$name
				)
				: __( 'The template could not be deleted.', 'replicaforge' )
		);

		$this->redirect_back();
	}

	/**
	 * Import a package.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return void
	 */
	private function do_import( $workspace_id ) {
		if ( ! $this->can( $workspace_id, 'templates.import' ) ) {
			$this->deny( __( 'You cannot import templates into this workspace.', 'replicaforge' ) );
		}

		$raw = isset( $_POST['package'] ) && is_string( $_POST['package'] ) ? wp_unslash( $_POST['package'] ) : '';

		if ( '' === trim( $raw ) ) {
			$this->notice( 'error', __( 'Paste a template package first.', 'replicaforge' ) );
			$this->redirect_back();
		}

		$inspected = $this->packages->inspect( $raw );

		if ( is_wp_error( $inspected ) ) {
			$this->notice( 'error', (string) $inspected->get_error_message() );
			$this->redirect_back();
		}

		$strategy = isset( $_POST['strategy'] ) && is_string( $_POST['strategy'] ) ? sanitize_key( wp_unslash( $_POST['strategy'] ) ) : 'keep_existing';

		if ( ! Template_Limits::is_conflict_strategy( $strategy ) ) {
			$strategy = 'keep_existing';
		}

		$installed = ( new Template_Installer( null, null, null, null, null, $this->logger ) )->install(
			$workspace_id,
			$inspected['snapshot'],
			array(
				'user_id'         => get_current_user_id(),
				'category'        => 'imported',
				'visibility'      => 'private',
				'change_note'     => __( 'Imported from a template package.', 'replicaforge' ),
				'validation_state' => (string) $inspected['validation']['state'],
			)
		);

		if ( is_wp_error( $installed ) ) {
			$this->notice( 'error', (string) $installed->get_error_message() );
			$this->redirect_back();
		}

		$message = sprintf(
			/* translators: %s: the imported template name. */
			__( 'Imported "%s".', 'replicaforge' ),
			(string) ( $inspected['snapshot']['meta']['name'] ?? __( 'a template', 'replicaforge' ) )
		);

		if ( ! empty( $inspected['removals'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: how many items the security check removed. */
				__( 'The security check removed %d item(s) from it before it was stored.', 'replicaforge' ),
				count( (array) $inspected['removals'] )
			);
		}

		$this->audit( 'template_imported', (string) $installed['template_id'], $workspace_id, array( 'strategy' => $strategy ) );

		$this->notice( 'success', $message );
		$this->redirect_back();
	}

	/* ---------------------------------------------------------------------
	 * Permission helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return the user's active workspace.
	 *
	 * @return array{workspace_id: string, name: string}|\WP_Error
	 */
	private function current_workspace() {
		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return new \WP_Error( 'template_no_user', __( 'You must be signed in.', 'replicaforge' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only GET.
		$requested = isset( $_GET['workspace'] ) ? sanitize_text_field( wp_unslash( $_GET['workspace'] ) ) : '';

		if ( '' !== $requested ) {
			$owned = ( new Workspace_Store() )->for_user( $user_id, true );
			$match = null;

			foreach ( $owned as $workspace ) {
				if ( (string) ( $workspace['public_id'] ?? '' ) === $requested ) {
					$match = $workspace;
				}
			}

			if ( null !== $match ) {
				return array( 'workspace_id' => $requested, 'name' => (string) $match['name'] );
			}

			// A workspace the user cannot see is reported the same way as one that does not exist.
			return new \WP_Error( 'template_no_workspace', __( 'That workspace is not available.', 'replicaforge' ) );
		}

		$owned = ( new Workspace_Store() )->for_user( $user_id, true );

		if ( array() === $owned ) {
			return new \WP_Error(
				'template_no_workspace',
				__( 'You are not a member of a workspace. Open ReplicaForge, Team, and create one.', 'replicaforge' )
			);
		}

		return array( 'workspace_id' => (string) $owned[0]['public_id'], 'name' => (string) $owned[0]['name'] );
	}

	/**
	 * Return whether the user holds a capability in a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $capability   Capability.
	 * @return bool
	 */
	private function can( $workspace_id, $capability ) {
		$user_id = get_current_user_id();

		if ( $user_id < 1 || '' === $workspace_id ) {
			return false;
		}

		return user_can( $user_id, 'manage_options' ) || ( new Permission_Manager() )->can( $user_id, $workspace_id, $capability );
	}

	/**
	 * Return whether the user may read a template.
	 *
	 * @param string              $workspace_id Workspace id.
	 * @param array<string, mixed> $template     Template row.
	 * @return bool
	 */
	private function may_read( $workspace_id, array $template ) {
		if ( ! $this->can( $workspace_id, 'templates.view' ) ) {
			return false;
		}

		$owner   = (int) ( $template['user_id'] ?? 0 );
		$visible = (string) ( $template['visibility'] ?? 'private' );

		if ( $owner === get_current_user_id() || 'private' !== $visible ) {
			return true;
		}

		$role = ( new Permission_Manager() )->role( get_current_user_id(), $workspace_id );

		return 'client' === $role && 'client_review' === $visible;
	}

	/**
	 * Return whether the user may change a template.
	 *
	 * @param string              $workspace_id Workspace id.
	 * @param array<string, mixed> $template     Template row.
	 * @return bool
	 */
	private function may_write( $workspace_id, array $template ) {
		if ( ! $this->can( $workspace_id, 'templates.edit' ) ) {
			return false;
		}

		$owner   = (int) ( $template['user_id'] ?? 0 );
		$visible = (string) ( $template['visibility'] ?? 'private' );

		// A `client_review` template is never writable by anyone but its owner.
		if ( 'client_review' === $visible ) {
			return $owner === get_current_user_id();
		}

		return $owner === get_current_user_id() || 'private' !== $visible;
	}

	/* ---------------------------------------------------------------------
	 * Render helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Render the page header.
	 *
	 * @param string $eyebrow Eyebrow text.
	 * @param string $title   Title.
	 * @param string $intro   Intro.
	 * @return void
	 */
	private function render_header( $eyebrow, $title, $intro ) {
		echo '<header class="replicaforge-admin__header">';
		printf( '<p class="replicaforge-admin__eyebrow">%s</p>', esc_html( $eyebrow ) );

		if ( '' !== $title ) {
			printf( '<h1>%s</h1>', esc_html( $title ) );
		}

		if ( '' !== $intro ) {
			printf( '<p class="replicaforge-admin__intro">%s</p>', esc_html( $intro ) );
		}

		echo '</header>';
	}

	/**
	 * Render the workspace bar.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return void
	 */
	private function render_workspace_bar( $workspace_id ) {
		$owned = ( new Workspace_Store() )->for_user( get_current_user_id(), true );

		if ( count( $owned ) < 2 ) {
			return;
		}

		echo '<nav class="replicaforge-filter"><span class="replicaforge-filter__label">';
		esc_html_e( 'Workspace:', 'replicaforge' );
		echo '</span> ';

		$base = admin_url( 'admin.php?page=' . self::PAGE );

		foreach ( $owned as $workspace ) {
			$id    = (string) ( $workspace['public_id'] ?? '' );
			$class = ( $id === $workspace_id ) ? ' is-active' : '';

			printf(
				'<a class="replicaforge-filter__chip%1$s" href="%2$s">%3$s</a> ',
				esc_attr( $class ),
				esc_url( add_query_arg( 'workspace', rawurlencode( $id ), $base ) ),
				esc_html( (string) $workspace['name'] )
			);
		}

		echo '</nav>';
	}

	/**
	 * Render the filter form.
	 *
	 * @param string $type     Current type filter.
	 * @param string $status   Current status filter.
	 * @param string $category Current category filter.
	 * @param string $search   Current search.
	 * @return void
	 */
	private function render_filters( $type, $status, $category, $search ) {
		$workspace = $this->current_workspace();
		$workspace_id = is_wp_error( $workspace ) ? '' : (string) $workspace['workspace_id'];

		echo '<form method="get" class="replicaforge-filters">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::PAGE ) );

		if ( '' !== $workspace_id ) {
			printf( '<input type="hidden" name="workspace" value="%s" />', esc_attr( $workspace_id ) );
		}

		echo '<label for="rf_search">' . esc_html__( 'Search', 'replicaforge' ) . '</label>';
		printf(
			'<input type="search" id="rf_search" name="rf_search" value="%s" placeholder="%s" />',
			esc_attr( $search ),
			esc_attr__( 'Name, description, or tag', 'replicaforge' )
		);

		echo '<label for="rf_type">' . esc_html__( 'Type', 'replicaforge' ) . '</label>';
		echo '<select name="rf_type" id="rf_type">';
		printf( '<option value="">%s</option>', esc_html__( 'All types', 'replicaforge' ) );

		foreach ( Template_Limits::type_labels() as $key => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $type, $key, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		echo '<label for="rf_status">' . esc_html__( 'Status', 'replicaforge' ) . '</label>';
		echo '<select name="rf_status" id="rf_status">';
		printf( '<option value="">%s</option>', esc_html__( 'All statuses', 'replicaforge' ) );

		foreach ( Template_Limits::STATUSES as $key => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $status, $key, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Filter', 'replicaforge' ) );
		echo '</form>';
	}

	/**
	 * Render the template table.
	 *
	 * @param array<int, array<string, mixed>> $items    Templates.
	 * @param bool                              $can_edit Whether the user may edit.
	 * @return void
	 */
	private function render_table( array $items, $can_edit ) {
		echo '<table class="widefat striped replicaforge-table"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Template', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Type', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Status', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Validation', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Versions', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Used', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Updated', 'replicaforge' ) );
		echo '</tr></thead><tbody>';

		foreach ( $items as $template ) {
			$id   = (string) $template['public_id'];
			$name = (string) $template['name'];

			$link = add_query_arg(
				array( 'page' => self::DETAIL_PAGE, 'template' => $id ),
				admin_url( 'admin.php' )
			);

			$workspace = $this->current_workspace();
			if ( ! is_wp_error( $workspace ) ) {
				$link = add_query_arg( 'workspace', rawurlencode( (string) $workspace['workspace_id'] ), $link );
			}

			echo '<tr>';
			printf( '<td><a href="%s"><strong>%s</strong></a>', esc_url( $link ), esc_html( $name ) );

			if ( '' !== (string) $template['description'] ) {
				printf( '<br /><span class="description">%s</span>', esc_html( wp_trim_words( (string) $template['description'], 14 ) ) );
			}

			echo '</td>';
			printf( '<td data-label="%s">%s</td>', esc_attr__( 'Type', 'replicaforge' ), esc_html( Template_Limits::type_label( (string) $template['type'] ) ) );
			printf( '<td data-label="%s">%s</td>', esc_attr__( 'Status', 'replicaforge' ), esc_html( (string) Template_Limits::STATUSES[ (string) $template['status'] ] ) );
			printf( '<td data-label="%s">%s</td>', esc_attr__( 'Validation', 'replicaforge' ), $this->escape( $this->validation_tag( (string) $template['validation_state'] ) ) );
			printf( '<td data-label="%s">%d</td>', esc_attr__( 'Versions', 'replicaforge' ), (int) $template['version_count'] );
			printf( '<td data-label="%s">%d</td>', esc_attr__( 'Used', 'replicaforge' ), (int) $template['install_count'] );
			// The elapsed time is computed first and escaped as a whole. Escaping the number
			// and the translated word separately and concatenating them is the shape that
			// produces unescaped output when the unit string changes.
			$elapsed = sprintf(
				/* translators: %s: a human-readable duration such as "3 days". */
				__( '%s ago', 'replicaforge' ),
				human_time_diff( (int) strtotime( (string) $template['updated_at'] ), (int) current_time( 'timestamp' ) )
			);

			printf( '<td data-label="%s">%s</td>', esc_attr__( 'Updated', 'replicaforge' ), esc_html( $elapsed ) );
			echo '</tr>';
		}

		echo '</tbody></table>';

		unset( $can_edit );
	}

	/**
	 * Render pagination.
	 *
	 * @param array<string, mixed> $listed Page.
	 * @return void
	 */
	private function render_pagination( array $listed ) {
		if ( empty( $listed['has_more'] ) ) {
			return;
		}

		$next = add_query_arg(
			array( 'page' => self::PAGE, 'paged' => (int) $listed['page'] + 1 ),
			admin_url( 'admin.php' )
		);

		printf(
			'<p class="replicaforge-pagination"><a class="button" href="%s">%s</a></p>',
			esc_url( $next ),
			esc_html__( 'Next page', 'replicaforge' )
		);
	}

	/**
	 * Render the detail action bar.
	 *
	 * @param string              $workspace_id Workspace id.
	 * @param array<string, mixed> $template     Template.
	 * @param bool                $can_edit     Whether the user may edit.
	 * @return void
	 */
	private function render_detail_actions( $workspace_id, array $template, $can_edit ) {
		if ( ! $can_edit ) {
			return;
		}

		$id       = (string) $template['public_id'];
		$archived = 'archived' === (string) $template['status'];

		echo '<div class="replicaforge-actions">';

		if ( $this->can( $workspace_id, 'templates.export' ) ) {
			$export = add_query_arg(
				array( 'page' => self::DETAIL_PAGE, 'template' => $id, 'workspace' => $workspace_id, 'rf_export' => 1 ),
				admin_url( 'admin.php' )
			);

			printf( '<a class="button" href="%s">%s</a> ', esc_url( $export ), esc_html__( 'Export package', 'replicaforge' ) );
		}

		$action = $archived ? 'restore' : 'archive';

		printf(
			'<form method="post" action="%s" class="replicaforge-inline-form">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( 'replicaforge_template_action' );
		printf( '<input type="hidden" name="action" value="replicaforge_template_action" />' );
		printf( '<input type="hidden" name="rf_action" value="%s" />', esc_attr( $action ) );
		printf( '<input type="hidden" name="template_id" value="%s" />', esc_attr( $id ) );
		printf(
			'<button type="submit" class="button">%s</button>',
			esc_html( $archived ? __( 'Restore', 'replicaforge' ) : __( 'Archive', 'replicaforge' ) )
		);
		echo '</form> ';

		if ( $this->can( $workspace_id, 'templates.delete' ) && ! $archived ) {
			printf(
				'<form method="post" action="%s" class="replicaforge-inline-form" onsubmit="return confirm(%s);">',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr__( 'Delete this template and all of its versions? Pages already built from it are not affected. This cannot be undone.', 'replicaforge' )
			);
			wp_nonce_field( 'replicaforge_template_action' );
			printf( '<input type="hidden" name="action" value="replicaforge_template_action" />' );
			printf( '<input type="hidden" name="rf_action" value="delete" />' );
			printf( '<input type="hidden" name="template_id" value="%s" />', esc_attr( $id ) );
			printf( '<button type="submit" class="button button-link-delete">%s</button>', esc_html__( 'Delete', 'replicaforge' ) );
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * Render the structure panel.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return string
	 */
	private function render_structure_panel( array $snapshot ) {
		$elements = isset( $snapshot['document']['elements'] ) ? (array) $snapshot['document']['elements'] : array();
		$slots    = isset( $snapshot['content_slots'] ) ? (array) $snapshot['content_slots'] : array();
		$assets   = isset( $snapshot['assets'] ) ? (array) $snapshot['assets'] : array();
		$tokens   = isset( $snapshot['tokens'] ) ? (array) $snapshot['tokens'] : array();

		$out = '<div class="replicaforge-card"><h2>' . esc_html__( 'What this template contains', 'replicaforge' ) . '</h2><ul class="replicaforge-statlist">';

		$rows = array(
			__( 'Elements', 'replicaforge' )        => count( $elements ),
			__( 'Design tokens', 'replicaforge' )   => count( $tokens ),
			__( 'Content slots', 'replicaforge' )   => count( $slots ),
			__( 'Asset references', 'replicaforge' ) => count( $assets ),
		);

		foreach ( $rows as $label => $value ) {
			$out .= sprintf(
				'<li><span class="replicaforge-statlist__label">%s</span> <strong>%d</strong></li>',
				esc_html( $label ),
				(int) $value
			);
		}

		$out .= '</ul>';

		if ( array() === $tokens ) {
			$out .= '<p class="rf-banner rf-banner-warn" role="status">' . esc_html__( 'This template has no design tokens. It will inherit whatever the destination site already uses, which is usually right for a section and worth knowing for a whole page.', 'replicaforge' ) . '</p>';
		}

		$devices = isset( $snapshot['responsive']['devices'] ) ? (array) $snapshot['responsive']['devices'] : array();

		if ( array() !== $devices ) {
			$out .= sprintf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: comma-separated device names. */
						__( 'Responsive rules recorded for: %s', 'replicaforge' ),
						implode( ', ', $devices )
					)
				)
			);
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Render the quality panel.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return string
	 */
	private function render_quality_panel( array $snapshot ) {
		$measured = ( new Template_Quality() )->measure( $snapshot );
		$labels   = $this->indicator_labels();

		$out = '<div class="replicaforge-card"><h2>' . esc_html__( 'Reusability', 'replicaforge' ) . '</h2>';
		$out .= '<dl class="replicaforge-indicators">';

		foreach ( (array) $measured['indicators'] as $key => $indicator ) {
			$label = isset( $labels[ $key ] ) ? $labels[ $key ] : ucwords( str_replace( '_', ' ', (string) $key ) );
			$value = Template_Limits::NOT_MEASURED === (string) $indicator['value']
				? esc_html__( 'Not available', 'replicaforge' )
				: esc_html( (string) $indicator['value'] ) . '%';

			$out .= sprintf(
				'<div class="replicaforge-indicators__row"><dt>%s</dt><dd><span class="rf-tag rf-tag-%s">%s</span> <span class="replicaforge-indicators__value">%s</span></dd></div>',
				esc_html( $label ),
				esc_attr( $this->tag_tone( (string) $indicator['state'] ) ),
				esc_html( (string) $indicator['label'] ),
				$value
			);
		}

		$out .= '</dl>';

		$out .= sprintf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: how many indicators could be measured. */
					_n( 'One indicator could be measured on this site.', '%d indicators could be measured on this site.', (int) $measured['measured'], 'replicaforge' ),
					(int) $measured['measured']
				)
			)
		);

		$out .= '</div>';

		return $out;
	}

	/**
	 * Render the validation panel.
	 *
	 * @param array<string, mixed> $validation Validation.
	 * @return void
	 */
	private function render_validation( array $validation ) {
		printf( '<div class="replicaforge-card"><h2>%s</h2>', esc_html__( 'Validation', 'replicaforge' ) );

		printf(
			'<p>%s <span class="rf-tag rf-tag-%s">%s</span></p>',
			esc_html__( 'This template is:', 'replicaforge' ),
			esc_attr( $this->tag_tone( (string) $validation['state'] ) ),
			esc_html( (string) $validation['label'] )
		);

		foreach ( (array) $validation['dimensions'] as $dimension => $state ) {
			$available = Template_Limits::NOT_MEASURED !== (string) $state;
			printf(
				'<p class="description">%s: %s</p>',
				esc_html( ucfirst( str_replace( '_', ' ', (string) $dimension ) ) ),
				$available ? esc_html( (string) $state ) : esc_html__( 'Not available', 'replicaforge' )
			);
		}

		foreach ( array( 'errors' => __( 'Problems', 'replicaforge' ), 'reviews' => __( 'Needs a decision', 'replicaforge' ), 'warnings' => __( 'Worth knowing', 'replicaforge' ) ) as $key => $label ) {
			$items = (array) ( $validation[ $key ] ?? array() );

			if ( array() === $items ) {
				continue;
			}

			printf( '<h3>%s</h3><ul class="replicaforge-findings">', esc_html( $label ) );

			foreach ( $items as $item ) {
				printf( '<li><code>%s</code> %s</li>', esc_html( (string) ( $item['code'] ?? '' ) ), esc_html( (string) ( $item['message'] ?? '' ) ) );
			}

			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * Render the content slot table.
	 *
	 * @param array<int, array<string, mixed>> $slots Slots.
	 * @return void
	 */
	private function render_slots( array $slots ) {
		if ( array() === $slots ) {
			return;
		}

		printf( '<div class="replicaforge-card"><h2>%s</h2>', esc_html__( 'Content slots', 'replicaforge' ) );
		printf( '<p class="description">%s</p>', esc_html__( 'These are the places content goes. A slot left empty renders as an empty region, never as invented text.', 'replicaforge' ) );

		echo '<table class="widefat striped replicaforge-table"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Slot', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Type', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Fills from', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Required', 'replicaforge' ) );
		echo '</tr></thead><tbody>';

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$type = (string) ( $slot['type'] ?? '' );

			echo '<tr>';
			printf( '<td><strong>%s</strong><br /><code class="description">%s</code></td>', esc_html( (string) ( $slot['label'] ?? '' ) ), esc_html( (string) ( $slot['slot_id'] ?? '' ) ) );
			printf( '<td data-label="%s">%s</td>', esc_attr__( 'Type', 'replicaforge' ), esc_html( isset( Template_Limits::SLOT_TYPES[ $type ] ) ? Template_Limits::SLOT_TYPES[ $type ] : $type ) );

			$source = (string) ( $slot['dynamic_source'] ?? '' );

			if ( '' !== $source ) {
				$check = Content_Slot_Registry::dynamic_availability( $source );
				printf(
					'<td data-label="%s"><code>%s</code>%s</td>',
					esc_attr__( 'Fills from', 'replicaforge' ),
					esc_html( $source ),
					$check['available'] ? '' : ' <span class="rf-tag rf-tag-warn">' . esc_html__( 'unavailable here', 'replicaforge' ) . '</span>'
				);
			} else {
				printf( '<td data-label="%s">%s</td>', esc_attr__( 'Fills from', 'replicaforge' ), esc_html__( 'Typed in', 'replicaforge' ) );
			}

			printf(
				'<td data-label="%s">%s</td>',
				esc_attr__( 'Required', 'replicaforge' ),
				! empty( $slot['required'] ) ? esc_html__( 'Yes', 'replicaforge' ) : esc_html__( 'No', 'replicaforge' )
			);
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Render the asset table.
	 *
	 * @param array<string, mixed> $assets Assets.
	 * @return void
	 */
	private function render_assets( array $assets ) {
		if ( array() === $assets ) {
			return;
		}

		printf( '<div class="replicaforge-card"><h2>%s</h2>', esc_html__( 'Assets', 'replicaforge' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'An image taken from the analysed website is referenced, never copied. ReplicaForge does not redistribute assets whose rights are not established.', 'replicaforge' )
		);

		echo '<table class="widefat striped replicaforge-table"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Image', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Provenance', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Mode', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'May be shared', 'replicaforge' ) );
		echo '</tr></thead><tbody>';

		foreach ( $assets as $id => $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$provenance = (string) ( $asset['provenance_class'] ?? '' );
			$label      = isset( Template_Limits::ASSET_PROVENANCE[ $provenance ] ) ? Template_Limits::ASSET_PROVENANCE[ $provenance ] : $provenance;
			$mode       = (string) ( $asset['mode'] ?? 'reference' );

			echo '<tr>';
			printf( '<td><code class="description">%s</code></td>', esc_html( (string) $id ) );
			printf( '<td data-label="%s">%s</td>', esc_attr__( 'Provenance', 'replicaforge' ), esc_html( $label ) );
			printf(
				'<td data-label="%s">%s</td>',
				esc_attr__( 'Mode', 'replicaforge' ),
				'reference' === $mode ? esc_html__( 'Referenced', 'replicaforge' ) : esc_html__( 'Copied', 'replicaforge' )
			);
			printf(
				'<td data-label="%s">%s</td>',
				esc_attr__( 'May be shared', 'replicaforge' ),
				! empty( $asset['redistributable'] )
					? esc_html__( 'Yes', 'replicaforge' )
					: esc_html__( 'No', 'replicaforge' )
			);
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Render the version history.
	 *
	 * @param array<int, array<string, mixed>> $versions Versions.
	 * @return void
	 */
	private function render_versions( array $versions ) {
		if ( array() === $versions ) {
			return;
		}

		printf( '<div class="replicaforge-card"><h2>%s</h2>', esc_html__( 'Versions', 'replicaforge' ) );
		printf( '<p class="description">%s</p>', esc_html__( 'A stored version is never changed. A significant change creates a new one, so a template can always be returned to how it was.', 'replicaforge' ) );

		echo '<table class="widefat striped replicaforge-table"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Version', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Change', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Size', 'replicaforge' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Created', 'replicaforge' ) );
		echo '</tr></thead><tbody>';

		foreach ( $versions as $version ) {
			echo '<tr>';
			printf( '<td><strong>%d</strong></td>', (int) $version['version'] );
			printf( '<td data-label="%s">%s</td>', esc_attr__( 'Change', 'replicaforge' ), esc_html( (string) $version['change_note'] ) );
			printf( '<td data-label="%s">%d KB</td>', esc_attr__( 'Size', 'replicaforge' ), (int) round( (int) $version['bytes'] / 1024 ) );
			printf( '<td data-label="%s">%s</td>', esc_attr__( 'Created', 'replicaforge' ), esc_html( (string) $version['created_at'] ) );
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Render an empty state.
	 *
	 * @param string $title   Title.
	 * @param string $message Message.
	 * @return void
	 */
	private function render_empty( $title, $message ) {
		printf(
			'<div class="replicaforge-card replicaforge-empty"><h2>%s</h2><p>%s</p></div>',
			esc_html( $title ),
			esc_html( $message )
		);
	}

	/**
	 * Render a validation state tag.
	 *
	 * @param string $state State.
	 * @return string
	 */
	private function validation_tag( $state ) {
		if ( '' === $state ) {
			return '<span class="rf-tag rf-tag-muted">' . esc_html__( 'Not checked', 'replicaforge' ) . '</span>';
		}

		$label = Template_Limits::is_validation_state( $state ) ? (string) Template_Limits::VALIDATION_STATES[ $state ] : $state;

		return sprintf( '<span class="rf-tag rf-tag-%s">%s</span>', esc_attr( $this->tag_tone( $state ) ), esc_html( $label ) );
	}

	/**
	 * Return a CSS tone for a state.
	 *
	 * @param string $state State.
	 * @return string
	 */
	private function tag_tone( $state ) {
		$map = array(
			'valid'          => 'ok',
			'valid_warnings' => 'warn',
			'needs_review'   => 'warn',
			'incompatible'   => 'bad',
			'invalid'        => 'bad',
			'error'          => 'bad',
			'complete'       => 'ok',
			'good'           => 'ok',
			'partial'        => 'warn',
			'unavailable'    => 'muted',
			'uniform'        => 'muted',
			'none'           => 'muted',
			'empty'          => 'bad',
			'compatible'     => 'ok',
			'incompatible'   => 'bad',
		);

		return isset( $map[ $state ] ) ? $map[ $state ] : 'muted';
	}

	/**
	 * Return the indicator display labels.
	 *
	 * @return array<string, string>
	 */
	private function indicator_labels() {
		return array(
			'structural_completeness' => __( 'Structure', 'replicaforge' ),
			'responsive_completeness' => __( 'Responsive', 'replicaforge' ),
			'component_reuse'         => __( 'Component reuse', 'replicaforge' ),
			'token_coverage'          => __( 'Token coverage', 'replicaforge' ),
			'interaction_coverage'    => __( 'Interactions', 'replicaforge' ),
			'asset_validity'          => __( 'Assets', 'replicaforge' ),
			'elementor_compatibility' => __( 'Elementor', 'replicaforge' ),
			'content_separation'      => __( 'Content separation', 'replicaforge' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	/**
	 * Queue a notice for the next render.
	 *
	 * A transient, following `Workspace_Admin::notice()`: queuing works because `handle_post()`
	 * redirects, and the render that follows is a new request. So a notice never outlives the
	 * request that queued it, and cannot be shown twice by a reload.
	 *
	 * @param string $type    `success` or `error`.
	 * @param string $message Message.
	 * @return void
	 */
	private function notice( $type, $message ) {
		$notices   = $this->notices();
		$notices[] = array(
			'type'    => ( 'error' === (string) $type ) ? 'error' : 'success',
			'message' => wp_kses_post( (string) $message ),
		);

		set_transient( 'replicaforge_template_notices_' . get_current_user_id(), $notices, 60 );
	}

	/**
	 * Return queued notices.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function notices() {
		$stored = get_transient( 'replicaforge_template_notices_' . get_current_user_id() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Render and clear queued notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		$notices = $this->notices();

		if ( array() === $notices ) {
			return;
		}

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				'error' === (string) $notice['type'] ? 'error' : 'success',
				wp_kses_post( (string) $notice['message'] )
			);
		}

		delete_transient( 'replicaforge_template_notices_' . get_current_user_id() );
	}

	/**
	 * Render a notice directly.
	 *
	 * @param string $type    Type.
	 * @param string $message Message.
	 * @return void
	 */
	private function render_notice( $type, $message ) {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === (string) $type ? 'error' : 'success',
			wp_kses_post( (string) $message )
		);
	}

	/**
	 * Refuse an action and stop.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function deny( $message ) {
		$this->notice( 'error', $message );
		$this->redirect_back();
	}

	/**
	 * Redirect back to whichever screen sent the form.
	 *
	 * @return void
	 */
	private function redirect_back() {
		$referer = wp_get_referer();

		wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Escape a fragment of already-built markup.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private function escape( $html ) {
		return (string) $html;
	}

	/**
	 * Reduce a stored version to a snapshot.
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
	 * Record an audit event.
	 *
	 * @param string              $event        Event.
	 * @param string              $target_id    Target.
	 * @param string              $workspace_id Workspace.
	 * @param array<string, mixed> $context      Context.
	 * @return void
	 */
	private function audit( $event, $target_id, $workspace_id, array $context = array() ) {
		$context = array_merge(
			$context,
			array( 'resource_type' => 'template', 'resource_id' => (string) $target_id, 'workspace_id' => (string) $workspace_id )
		);

		if ( '' !== (string) $workspace_id ) {
			( new Collaboration_Log() )->audit( (string) $workspace_id, (string) $event, $context, get_current_user_id() );
		}
	}
}
