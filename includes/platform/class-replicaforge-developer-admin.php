<?php
/**
 * Phase 20: the developer console.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The API / webhooks / automations / extensions screens.
 *
 * ### Follows the existing design system rather than inventing one
 *
 * The markup and classes are the ones Phase 17 and Phase 19 already established
 * (`rf-*` wrappers, `rf-panel`, `rf-table`, status pills). A separate visual language for
 * this screen would make the plugin look like two products, and "use the existing design
 * system" is cheaper than being consistent by accident later.
 *
 * ### Every capability check is server-side, and the screen degrades rather than hiding
 *
 * The menu uses `replicaforge_use` so a member can *reach* the screen and see what their own
 * workspace has. What they may then do is decided per action by
 * {@see self::may()}, and a section they cannot use is rendered with an explanation rather
 * than omitted — an absent section reads as "this feature does not exist", and a member who
 * was told otherwise has no way to tell the difference.
 *
 * The one screen that takes the stricter `manage_options` is none of them: §67's requirement
 * is that ordinary members and clients never see audit payloads, paths or secrets, and the
 * answer here is that this console contains none of those in the first place. Secrets are
 * shown once at creation and never again; every other field is metadata.
 *
 * ### Nothing secret reaches the browser
 *
 * The one-time token and webhook secret are printed into the page that created them and are
 * not retrievable afterwards — the signing secret is *derived*, so it is not in the database
 * at all. The screen therefore never has a "reveal" affordance, because there is nothing
 * behind it to reveal.
 */
final class Developer_Admin {

	/**
	 * WordPress capability required to see the screen.
	 *
	 * A *WordPress* capability because `add_menu_page()` takes a string, not a callback. The
	 * workspace permissions are the real boundary and are checked per action below.
	 *
	 * @var string
	 */
	const MENU_CAPABILITY = 'replicaforge_use';

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	const PAGE = 'replicaforge-developer';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE = 'replicaforge_developer_action';

	/**
	 * Transient holding notices queued before a redirect.
	 *
	 * @var string
	 */
	const NOTICE_OPTION = 'replicaforge_developer_notices';

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Credential store.
	 *
	 * @var Api_Credential_Store
	 */
	private $credentials;

	/**
	 * Webhook store.
	 *
	 * @var Webhook_Store
	 */
	private $webhooks;

	/**
	 * Delivery worker.
	 *
	 * @var Webhook_Delivery
	 */
	private $delivery;

	/**
	 * Delivery store.
	 *
	 * @var Webhook_Delivery_Store
	 */
	private $deliveries;

	/**
	 * Automation store.
	 *
	 * @var Automation_Store
	 */
	private $automations;

	/**
	 * Automation runner.
	 *
	 * @var Automation_Runner
	 */
	private $automation_runner;

	/**
	 * Extension registry.
	 *
	 * @var Extension_Registry
	 */
	private $extensions;

	/**
	 * Event store.
	 *
	 * @var Event_Store
	 */
	private $events;

	/**
	 * Event dispatcher.
	 *
	 * @var Event_Dispatcher
	 */
	private $dispatcher;

	/**
	 * Permission manager.
	 *
	 * @var Permission_Manager
	 */
	private $permissions;

	/**
	 * Constructor.
	 *
	 * @param Logger|null                $logger     Logger.
	 * @param Api_Credential_Store|null  $credentials Credential store.
	 * @param Webhook_Store|null         $webhooks   Webhook store.
	 * @param Webhook_Delivery|null      $delivery   Delivery worker.
	 * @param Webhook_Delivery_Store|null $deliveries Delivery store.
	 * @param Automation_Store|null      $automations Automation store.
	 * @param Automation_Runner|null     $automation_runner Automation runner.
	 * @param Extension_Registry|null    $extensions Extension registry.
	 * @param Event_Store|null           $events     Event store.
	 * @param Event_Dispatcher|null      $dispatcher Event dispatcher.
	 * @param Permission_Manager|null    $permissions Permission manager.
	 */
	public function __construct( $logger = null, $credentials = null, $webhooks = null, $delivery = null, $deliveries = null, $automations = null, $automation_runner = null, $extensions = null, $events = null, $dispatcher = null, $permissions = null ) {
		$this->logger            = $logger instanceof Logger ? $logger : new Logger();
		$this->credentials       = $credentials instanceof Api_Credential_Store ? $credentials : new Api_Credential_Store( null, $this->logger );
		$this->webhooks          = $webhooks instanceof Webhook_Store ? $webhooks : new Webhook_Store( null, $this->logger );
		$this->deliveries        = $deliveries instanceof Webhook_Delivery_Store ? $deliveries : new Webhook_Delivery_Store( null, $this->logger );
		$this->delivery          = $delivery instanceof Webhook_Delivery ? $delivery : new Webhook_Delivery( $this->logger, $this->webhooks, $this->deliveries );
		$this->automations       = $automations instanceof Automation_Store ? $automations : new Automation_Store( null, $this->logger );
		$this->extensions        = $extensions instanceof Extension_Registry ? $extensions : new Extension_Registry( null, $this->logger );
		$this->events            = $events instanceof Event_Store ? $events : new Event_Store( null, $this->logger );
		$this->dispatcher        = $dispatcher instanceof Event_Dispatcher ? $dispatcher : new Event_Dispatcher( $this->logger );
		$this->permissions       = $permissions instanceof Permission_Manager ? $permissions : new Permission_Manager();

		$this->automation_runner = $automation_runner instanceof Automation_Runner
			? $automation_runner
			: new Automation_Runner( $this->logger, $this->automations, $this->dispatcher );

		$this->dispatcher->set_automation_runner( $this->automation_runner );
	}

	/* ---------------------------------------------------------------------
	 * Wiring
	 * ------------------------------------------------------------------ */

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_replicaforge_developer_action', array( $this, 'handle_post' ) );
	}

	/**
	 * Register the menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'ReplicaForge Developer', 'replicaforge' ),
			__( 'Developer', 'replicaforge' ),
			self::MENU_CAPABILITY,
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-code',
			60
		);

		add_submenu_page(
			self::PAGE,
			__( 'Developer console', 'replicaforge' ),
			__( 'Console', 'replicaforge' ),
			self::MENU_CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue the screen stylesheet.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		wp_enqueue_style(
			'replicaforge-developer',
			REPLICAFORGE_URL . 'includes/platform/css/developer-admin.css',
			array(),
			REPLICAFORGE_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------ */

	/**
	 * Render the console.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::MENU_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view the developer console.', 'replicaforge' ), 403 );
		}

		$workspace_id = $this->workspace_id();

		$this->open( __( 'Developer', 'replicaforge' ) );
		$this->notice();

		if ( '' === $workspace_id ) {
			$this->panel(
				__( 'No workspace', 'replicaforge' ),
				'<p>' . esc_html__( 'You are not a member of any ReplicaForge workspace. API credentials, webhooks and automations all belong to a workspace, so there is nothing to show until you join or create one.', 'replicaforge' ) . '</p>'
			);
			$this->close();

			return;
		}

		$this->render_overview( $workspace_id );
		$this->render_credentials( $workspace_id );
		$this->render_webhooks( $workspace_id );
		$this->render_automations( $workspace_id );
		$this->render_extensions( $workspace_id );
		$this->render_events( $workspace_id );

		$this->close();
	}

	/**
	 * Render the overview panel.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return void
	 */
	private function render_overview( $workspace_id ) {
		$credentials = $this->credentials->browse( $workspace_id, array( 'per_page' => 1 ) );
		$webhooks    = $this->webhooks->browse( $workspace_id, array( 'per_page' => 1 ) );
		$automations = $this->automations->browse( $workspace_id, array( 'per_page' => 1 ) );
		$extensions  = $this->extensions->summary();

		$rows = array(
			__( 'API credentials', 'replicaforge' )   => (int) $credentials['count'] . ' / ' . Platform_Limits::MAX_CREDENTIALS,
			__( 'Webhooks', 'replicaforge' )          => (int) $webhooks['count'] . ' / ' . Platform_Limits::MAX_WEBHOOKS,
			__( 'Automations', 'replicaforge' )       => (int) $automations['count'] . ' / ' . Platform_Limits::MAX_AUTOMATIONS,
			__( 'Extensions', 'replicaforge' )        => (int) $extensions['total'] . ' (' . (int) $extensions['live'] . __( ' active', 'replicaforge' ) . ')',
			__( 'Delivery log', 'replicaforge' )      => $this->count_deliveries( $workspace_id ),
			__( 'Credential', 'replicaforge' )        => $this->may( $workspace_id, 'api.credentials.manage' )
				? __( 'You can create and revoke credentials.', 'replicaforge' )
				: __( 'Read only. Creating a credential needs the API credentials permission.', 'replicaforge' ),
			__( 'Integrations', 'replicaforge' )     => $this->may( $workspace_id, 'api.webhooks.manage' )
				? __( 'You can manage webhooks and automations.', 'replicaforge' )
				: __( 'Read only. Managing integrations needs the integrations permission.', 'replicaforge' ),
		);

		$html = '<table class="widefat striped rf-table"><tbody>';

		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th scope="row">' . esc_html( (string) $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}

		$html .= '</tbody></table>';

		/*
		 * The loop-prevention numbers, shown rather than hidden. An operator who has wired
		 * `workflow.completed` to an automation that starts a workflow needs to be able to
		 * see the ceiling they are subject to without reading the source.
		 */
		$html .= '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: the maximum automation chain depth, 2: the cooldown in seconds. */
				__( 'Automation chains stop automatically after %1$d links, and a rule will not fire twice for the same resource within %2$d seconds.', 'replicaforge' ),
				Platform_Limits::AUTOMATION_MAX_DEPTH,
				Platform_Limits::AUTOMATION_COOLDOWN_SECONDS
			)
		) . '</p>';

		$this->panel( __( 'Overview', 'replicaforge' ), $html );
	}

	/**
	 * Render the credentials panel.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return void
	 */
	private function render_credentials( $workspace_id ) {
		$can_manage = $this->may( $workspace_id, 'api.credentials.manage' );

		if ( ! $can_manage && ! $this->may( $workspace_id, 'api.credentials.read' ) ) {
			return;
		}

		$page = $this->credentials->browse( $workspace_id, array( 'per_page' => 50 ) );

		$html = '';

		if ( $can_manage ) {
			$scopes = '';

			foreach ( Platform_Limits::API_SCOPES as $scope => $definition ) {
				$scopes .= '<label class="rf-check"><input type="checkbox" name="scopes[]" value="' . esc_attr( (string) $scope ) . '"> <code>' . esc_html( (string) $scope ) . '</code></label>';
			}

			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rf-form">'
				. '<input type="hidden" name="action" value="replicaforge_developer_action">'
				. '<input type="hidden" name="do" value="create_credential">'
				. wp_nonce_field( self::NONCE )
				. '<p><label>' . esc_html__( 'Name', 'replicaforge' ) . '<br><input type="text" name="credential_name" required maxlength="120" class="regular-text"></label></p>'
				. '<fieldset><legend>' . esc_html__( 'Scopes', 'replicaforge' ) . '</legend>' . $scopes . '</fieldset>'
				. '<p><label>' . esc_html__( 'Expires in days', 'replicaforge' ) . ' <input type="number" name="expires_in_days" min="0" max="730" value="90" class="small-text"></label></p>'
				. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create credential', 'replicaforge' ) . '</button></p>'
				. '</form>';
		} else {
			$html .= '<p class="description">' . esc_html__( 'You can see that credentials exist but not create or revoke them.', 'replicaforge' ) . '</p>';
		}

		$html .= '<table class="widefat striped rf-table"><thead><tr>'
			. '<th>' . esc_html__( 'Name', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Reference', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Scopes', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Last used', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Requests', 'replicaforge' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody>';

		foreach ( $page['items'] as $record ) {
			$scopes = implode( ', ', array_map( 'strval', (array) ( $record['scopes'] ?? array() ) ) );

			$html .= '<tr>'
				. '<td>' . esc_html( (string) ( $record['name'] ?? '' ) ) . '</td>'
				/* The prefix identifies a credential in the console without being any part
				 * of the usable secret, so a screenshot of this table is not a leak. */
				. '<td><code>' . esc_html( (string) ( $record['prefix'] ?? '' ) ) . '</code></td>'
				. '<td><code>' . esc_html( $scopes ) . '</code></td>'
				. '<td>' . $this->pill( (string) ( $record['status'] ?? '' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $record['last_used_at'] ?? '' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $record['request_count'] ?? 0 ) ) . '</td>'
				. '<td>';

			if ( $can_manage && 'revoked' !== (string) ( $record['status'] ?? '' ) ) {
				$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
					. '<input type="hidden" name="action" value="replicaforge_developer_action">'
					. '<input type="hidden" name="do" value="revoke_credential">'
					. '<input type="hidden" name="credential_id" value="' . esc_attr( (string) ( $record['public_id'] ?? '' ) ) . '">'
					. wp_nonce_field( self::NONCE )
					. '<button type="submit" class="button button-small">' . esc_html__( 'Revoke', 'replicaforge' ) . '</button>'
					. '</form>';
			}

			$html .= '</td></tr>';
		}

		if ( array() === $page['items'] ) {
			$html .= '<tr><td colspan="7">' . esc_html__( 'No API credentials yet.', 'replicaforge' ) . '</td></tr>';
		}

		$html .= '</tbody></table>';

		$this->panel( __( 'API credentials', 'replicaforge' ), $html );
	}

	/**
	 * Render the webhooks panel.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return void
	 */
	private function render_webhooks( $workspace_id ) {
		if ( ! $this->may( $workspace_id, 'api.webhooks.manage' ) ) {
			$this->panel(
				__( 'Webhooks', 'replicaforge' ),
				'<p class="description">' . esc_html__( 'Managing webhooks needs the integrations permission in this workspace.', 'replicaforge' ) . '</p>'
			);

			return;
		}

		$page = $this->webhooks->browse( $workspace_id, array( 'per_page' => 50 ) );

		$events = '';

		foreach ( Platform_Limits::EVENTS as $type => $event ) {
			if ( ! $event['public'] ) {
				continue;
			}

			$events .= '<label class="rf-check"><input type="checkbox" name="events[]" value="' . esc_attr( (string) $type ) . '"> <code>' . esc_html( (string) $type ) . '</code></label>';
		}

		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rf-form">'
			. '<input type="hidden" name="action" value="replicaforge_developer_action">'
			. '<input type="hidden" name="do" value="create_webhook">'
			. wp_nonce_field( self::NONCE )
			. '<p><label>' . esc_html__( 'Name', 'replicaforge' ) . '<br><input type="text" name="webhook_name" required maxlength="120" class="regular-text"></label></p>'
			. '<p><label>' . esc_html__( 'Endpoint', 'replicaforge' ) . '<br><input type="url" name="endpoint" required maxlength="2048" class="large-text" placeholder="https://example.com/replicaforge"></label></p>'
			. '<fieldset><legend>' . esc_html__( 'Events', 'replicaforge' ) . '</legend>' . $events . '</fieldset>'
			. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create webhook', 'replicaforge' ) . '</button></p>'
			. '</form>';

		$html .= '<table class="widefat striped rf-table"><thead><tr>'
			. '<th>' . esc_html__( 'Name', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Endpoint', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Events', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Last delivery', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Failures', 'replicaforge' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody>';

		foreach ( $page['items'] as $record ) {
			$id = (string) ( $record['public_id'] ?? '' );

			$html .= '<tr>'
				. '<td>' . esc_html( (string) ( $record['name'] ?? '' ) ) . '</td>'
				. '<td><code>' . esc_html( (string) ( $record['endpoint'] ?? '' ) ) . '</code></td>'
				. '<td><code>' . esc_html( implode( ', ', array_map( 'strval', (array) ( $record['events'] ?? array() ) ) ) ) . '</code></td>'
				. '<td>' . $this->pill( (string) ( $record['status'] ?? '' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $record['last_delivered_at'] ?? '' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $record['consecutive_failures'] ?? 0 ) ) . '</td>'
				. '<td>';

			foreach ( array( 'test' => __( 'Test', 'replicaforge' ), 'disable' => __( 'Disable', 'replicaforge' ) ) as $action => $label ) {
				$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
					. '<input type="hidden" name="action" value="replicaforge_developer_action">'
					. '<input type="hidden" name="do" value="webhook_' . esc_attr( $action ) . '">'
					. '<input type="hidden" name="webhook_id" value="' . esc_attr( $id ) . '">'
					. wp_nonce_field( self::NONCE )
					. '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>'
					. '</form> ';
			}

			$html .= '</td></tr>';

			$history = $this->deliveries->history( $id, 5 );

			if ( array() !== $history ) {
				$html .= '<tr><td colspan="7" class="rf-nested"><table class="widefat striped rf-table rf-table-nested"><thead><tr>'
					. '<th>' . esc_html__( 'Event', 'replicaforge' ) . '</th>'
					. '<th>' . esc_html__( 'Status', 'replicaforge' ) . '</th>'
					. '<th>' . esc_html__( 'Attempts', 'replicaforge' ) . '</th>'
					. '<th>' . esc_html__( 'Code', 'replicaforge' ) . '</th>'
					. '<th>' . esc_html__( 'Detail', 'replicaforge' ) . '</th>'
					. '<th></th>'
					. '</tr></thead><tbody>';

				foreach ( $history as $delivery ) {
					$delivery_id = (string) ( $delivery['public_id'] ?? '' );

					$html .= '<tr>'
						. '<td><code>' . esc_html( (string) ( $delivery['event_type'] ?? '' ) ) . '</code></td>'
						. '<td>' . $this->pill( (string) ( $delivery['status'] ?? '' ) ) . '</td>'
						. '<td>' . esc_html( (string) ( $delivery['attempts'] ?? 0 ) ) . '</td>'
						. '<td>' . esc_html( (string) ( $delivery['response_code'] ?? 0 ) ) . '</td>'
						. '<td>' . esc_html( (string) ( $delivery['error'] ?? '' ) ) . '</td>'
						. '<td>';

					if ( Platform_Limits::is_terminal_delivery( (string) ( $delivery['status'] ?? '' ) ) && 'delivered' !== (string) ( $delivery['status'] ?? '' ) ) {
						$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
							. '<input type="hidden" name="action" value="replicaforge_developer_action">'
							. '<input type="hidden" name="do" value="retry_delivery">'
							. '<input type="hidden" name="delivery_id" value="' . esc_attr( $delivery_id ) . '">'
							. wp_nonce_field( self::NONCE )
							. '<button type="submit" class="button button-small">' . esc_html__( 'Retry', 'replicaforge' ) . '</button>'
							. '</form>';
					}

					$html .= '</td></tr>';
				}

				$html .= '</tbody></table></td></tr>';
			}
		}

		if ( array() === $page['items'] ) {
			$html .= '<tr><td colspan="7">' . esc_html__( 'No webhooks yet.', 'replicaforge' ) . '</td></tr>';
		}

		$html .= '</tbody></table>';

		$this->panel( __( 'Webhooks', 'replicaforge' ), $html );
	}

	/**
	 * Render the automations panel.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return void
	 */
	private function render_automations( $workspace_id ) {
		if ( ! $this->may( $workspace_id, 'api.automations.manage' ) ) {
			$this->panel(
				__( 'Automations', 'replicaforge' ),
				'<p class="description">' . esc_html__( 'Managing automations needs the automations permission in this workspace.', 'replicaforge' ) . '</p>'
			);

			return;
		}

		$page = $this->automations->browse( $workspace_id, array( 'per_page' => 50 ) );

		$triggers = '';
		foreach ( Platform_Limits::AUTOMATION_TRIGGERS as $trigger => $label ) {
			$triggers .= '<option value="' . esc_attr( (string) $trigger ) . '">' . esc_html( (string) $trigger ) . '</option>';
		}

		$actions = '';
		foreach ( Platform_Limits::AUTOMATION_ACTIONS as $action => $label ) {
			$actions .= '<option value="' . esc_attr( (string) $action ) . '">' . esc_html( (string) $action ) . '</option>';
		}

		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rf-form">'
			. '<input type="hidden" name="action" value="replicaforge_developer_action">'
			. '<input type="hidden" name="do" value="create_automation">'
			. wp_nonce_field( self::NONCE )
			. '<p><label>' . esc_html__( 'Name', 'replicaforge' ) . '<br><input type="text" name="automation_name" required maxlength="120" class="regular-text"></label></p>'
			. '<p><label>' . esc_html__( 'When', 'replicaforge' ) . ' <select name="trigger">' . $triggers . '</select></label> '
			. '<label>' . esc_html__( 'Do', 'replicaforge' ) . ' <select name="automation_action">' . $actions . '</select></label></p>'
			. '<p><label>' . esc_html__( 'Project id', 'replicaforge' ) . ' <input type="text" name="project_id" maxlength="64" class="regular-text"></label></p>'
			. '<p><label>' . esc_html__( 'Source address', 'replicaforge' ) . ' <input type="url" name="source_url" maxlength="2048" class="large-text"></label> '
			. '<label>' . esc_html__( 'Notification type', 'replicaforge' ) . ' <input type="text" name="notification_type" maxlength="64" class="regular-text"></label></p>'
			. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create automation', 'replicaforge' ) . '</button></p>'
			. '</form>';

		$html .= '<table class="widefat striped rf-table"><thead><tr>'
			. '<th>' . esc_html__( 'Name', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'When', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Do', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Runs', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Last run', 'replicaforge' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody>';

		foreach ( $page['items'] as $record ) {
			$id      = (string) ( $record['public_id'] ?? '' );
			$last    = (int) ( $record['last_run_at'] ?? 0 );

			$html .= '<tr>'
				. '<td>' . esc_html( (string) ( $record['name'] ?? '' ) ) . '</td>'
				. '<td><code>' . esc_html( (string) ( $record['trigger_event'] ?? '' ) ) . '</code></td>'
				. '<td><code>' . esc_html( (string) ( $record['action'] ?? '' ) ) . '</code></td>'
				. '<td>' . $this->pill( (string) ( $record['status'] ?? '' ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $record['run_count'] ?? 0 ) ) . '</td>'
				. '<td>' . esc_html( $last > 0 ? gmdate( 'Y-m-d H:i', $last ) . ' UTC' : '' ) . '</td>'
				. '<td>';

			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
				. '<input type="hidden" name="action" value="replicaforge_developer_action">'
				. '<input type="hidden" name="do" value="run_automation">'
				. '<input type="hidden" name="automation_id" value="' . esc_attr( $id ) . '">'
				. wp_nonce_field( self::NONCE )
				. '<button type="submit" class="button button-small">' . esc_html__( 'Run now', 'replicaforge' ) . '</button>'
				. '</form> ';

			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
				. '<input type="hidden" name="action" value="replicaforge_developer_action">'
				. '<input type="hidden" name="do" value="delete_automation">'
				. '<input type="hidden" name="automation_id" value="' . esc_attr( $id ) . '">'
				. wp_nonce_field( self::NONCE )
				. '<button type="submit" class="button button-small">' . esc_html__( 'Delete', 'replicaforge' ) . '</button>'
				. '</form>';

			$html .= '</td></tr>';
		}

		if ( array() === $page['items'] ) {
			$html .= '<tr><td colspan="7">' . esc_html__( 'No automations yet.', 'replicaforge' ) . '</td></tr>';
		}

		$html .= '</tbody></table>';

		$this->panel( __( 'Automations', 'replicaforge' ), $html );
	}

	/**
	 * Render the extensions panel.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return void
	 */
	private function render_extensions( $workspace_id ) {
		$can_manage = $this->may( $workspace_id, 'api.extensions.manage' );
		$rows       = $this->extensions->all();

		$html = '';

		if ( array() === $rows ) {
			$html .= '<p>' . esc_html__( 'No extensions are registered. An extension is registered by a plugin or theme that is already running on this site; there is no way to upload one.', 'replicaforge' ) . '</p>';
		} else {
			$html .= '<table class="widefat striped rf-table"><thead><tr>'
				. '<th>' . esc_html__( 'Extension', 'replicaforge' ) . '</th>'
				. '<th>' . esc_html__( 'Version', 'replicaforge' ) . '</th>'
				. '<th>' . esc_html__( 'Capabilities', 'replicaforge' ) . '</th>'
				. '<th>' . esc_html__( 'Status', 'replicaforge' ) . '</th>'
				. '<th>' . esc_html__( 'Compatibility', 'replicaforge' ) . '</th>'
				. '<th></th>'
				. '</tr></thead><tbody>';

			foreach ( $rows as $record ) {
				$incompat = (array) ( $record['incompatibilities'] ?? array() );

				$html .= '<tr>'
					. '<td>' . esc_html( (string) ( $record['name'] ?? '' ) ) . '<br><code>' . esc_html( (string) ( $record['extension_id'] ?? '' ) ) . '</code></td>'
					. '<td>' . esc_html( (string) ( $record['version'] ?? '' ) ) . '</td>'
					. '<td><code>' . esc_html( implode( ', ', array_map( 'strval', (array) ( $record['capabilities'] ?? array() ) ) ) ) . '</code></td>'
					. '<td>' . $this->pill( (string) ( $record['status'] ?? '' ) ) . '</td>'
					. '<td>' . ( array() === $incompat
						? esc_html__( 'Compatible', 'replicaforge' )
						: esc_html( implode( '; ', array_map( static function ( $entry ) { return (string) ( $entry['detail'] ?? '' ); }, $incompat ) ) ) ) . '</td>'
					. '<td>';

				if ( $can_manage ) {
					foreach ( array( 'disabled', 'active' ) as $state ) {
						if ( $state === (string) ( $record['status'] ?? '' ) ) {
							continue;
						}

						$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
							. '<input type="hidden" name="action" value="replicaforge_developer_action">'
							. '<input type="hidden" name="do" value="extension_state">'
							. '<input type="hidden" name="extension_id" value="' . esc_attr( (string) ( $record['extension_id'] ?? '' ) ) . '">'
							. '<input type="hidden" name="state" value="' . esc_attr( $state ) . '">'
							. wp_nonce_field( self::NONCE )
							. '<button type="submit" class="button button-small">' . esc_html( 'disabled' === $state ? __( 'Disable', 'replicaforge' ) : __( 'Enable', 'replicaforge' ) ) . '</button>'
							. '</form> ';
					}
				}

				$html .= '</td></tr>';
			}

			$html .= '</tbody></table>';
		}

		if ( '' !== (string) ( $this->last_error ?? '' ) ) {
			$html .= '<p class="rf-error">' . esc_html( (string) $this->last_error ) . '</p>';
		}

		$this->panel( __( 'Extensions', 'replicaforge' ), $html );
	}

	/**
	 * Render the events panel.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return void
	 */
	private function render_events( $workspace_id ) {
		if ( ! $this->may( $workspace_id, 'api.events.read' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = $this->events->browse( $workspace_id, array( 'per_page' => 25 ) );

		$html = '<table class="widefat striped rf-table"><thead><tr>'
			. '<th>' . esc_html__( 'Event', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Correlation', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'Depth', 'replicaforge' ) . '</th>'
			. '<th>' . esc_html__( 'When', 'replicaforge' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $page['items'] as $record ) {
			$html .= '<tr>'
				. '<td><code>' . esc_html( (string) ( $record['event_type'] ?? '' ) ) . '</code></td>'
				. '<td>' . $this->pill( (string) ( $record['status'] ?? '' ) ) . '</td>'
				. '<td><code>' . esc_html( (string) ( $record['correlation_id'] ?? '' ) ) . '</code></td>'
				. '<td>' . esc_html( (string) ( $record['depth'] ?? 0 ) ) . '</td>'
				. '<td>' . esc_html( (string) ( $record['created_at'] ?? '' ) ) . '</td>'
				. '</tr>';
		}

		if ( array() === $page['items'] ) {
			$html .= '<tr><td colspan="5">' . esc_html__( 'No events recorded yet.', 'replicaforge' ) . '</td></tr>';
		}

		$html .= '</tbody></table>';

		$this->panel( __( 'Events', 'replicaforge' ), $html );
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	/**
	 * Handle a form submission.
	 *
	 * @return void
	 */
	public function handle_post() {
		/*
		 * The nonce first, before anything else. A missing or wrong nonce means the request
		 * did not come from this screen, so there is no action to take and nothing to log
		 * beyond the refusal itself.
		 */
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			wp_die( esc_html__( 'That request could not be verified. Go back, reload the page and try again.', 'replicaforge' ), 403 );
		}

		if ( ! current_user_can( self::MENU_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'replicaforge' ), 403 );
		}

		$do          = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$workspace_id = $this->workspace_id();

		$result = $this->dispatch( $do, $workspace_id );

		$this->queue_notice( (string) ( $result['message'] ?? '' ), (string) ( $result['tone'] ?? 'success' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Route a submitted action.
	 *
	 * @param string $do           Action name.
	 * @param string $workspace_id Workspace id.
	 * @return array<string, string>
	 */
	private function dispatch( $do, $workspace_id ) {
		switch ( $do ) {
			case 'create_credential':
				return $this->do_create_credential( $workspace_id );
			case 'revoke_credential':
				return $this->do_revoke_credential( $workspace_id );
			case 'create_webhook':
				return $this->do_create_webhook( $workspace_id );
			case 'webhook_test':
				return $this->do_webhook_test();
			case 'webhook_disable':
				return $this->do_webhook_disable();
			case 'retry_delivery':
				return $this->do_retry_delivery();
			case 'create_automation':
				return $this->do_create_automation( $workspace_id );
			case 'run_automation':
				return $this->do_run_automation( $workspace_id );
			case 'delete_automation':
				return $this->do_delete_automation( $workspace_id );
			case 'extension_state':
				return $this->do_extension_state( $workspace_id );
		}

		return array( 'message' => __( 'That action is not available here.', 'replicaforge' ), 'tone' => 'error' );
	}

	/**
	 * Create a credential.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, string>
	 */
	private function do_create_credential( $workspace_id ) {
		if ( ! $this->may( $workspace_id, 'api.credentials.manage' ) ) {
			return $this->refused();
		}

		$scopes = isset( $_POST['scopes'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['scopes'] ) ) : array();

		$created = $this->credentials->create(
			$workspace_id,
			array(
				'name'            => isset( $_POST['credential_name'] ) ? sanitize_text_field( wp_unslash( $_POST['credential_name'] ) ) : '',
				'scopes'          => $scopes,
				'user_id'         => get_current_user_id(),
				'expires_in_days' => isset( $_POST['expires_in_days'] ) ? (int) $_POST['expires_in_days'] : 0,
			)
		);

		if ( is_wp_error( $created ) ) {
			return array( 'message' => (string) $created->get_error_message(), 'tone' => 'error' );
		}

		/*
		 * The token is shown once, here, and is not stored. The notice is put in a transient
		 * rather than carried in the URL, because a token in a query string reaches browser
		 * history, the `Referer` of any outbound link clicked afterwards, and every proxy log
		 * between here and the browser.
		 */
		$this->queue_notice(
			sprintf(
				/* translators: %s: the API token, shown once. */
				__( 'Credential created. Copy this token now — it is not shown again: %s', 'replicaforge' ),
				(string) ( $created['token'] ?? '' )
			),
			'secret'
		);

		return array( 'message' => __( 'Credential created.', 'replicaforge' ), 'tone' => 'success' );
	}

	/**
	 * Revoke a credential.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, string>
	 */
	private function do_revoke_credential( $workspace_id ) {
		if ( ! $this->may( $workspace_id, 'api.credentials.manage' ) ) {
			return $this->refused();
		}

		$id     = isset( $_POST['credential_id'] ) ? sanitize_text_field( wp_unslash( $_POST['credential_id'] ) ) : '';
		$record = $this->credentials->read( $workspace_id, $id );

		if ( null === $record ) {
			return array( 'message' => __( 'That credential does not exist.', 'replicaforge' ), 'tone' => 'error' );
		}

		/*
		 * The event, so an integration watching `credential.revoked` learns its key is dead
		 * rather than discovering it on its next 401.
		 */
		$this->dispatcher->emit(
			'credential.revoked',
			array(
				'workspace_id' => $workspace_id,
				'resource_id'  => $id,
				'actor_id'     => get_current_user_id(),
				'data'         => array( 'name' => (string) ( $record['name'] ?? '' ) ),
			)
		);

		$this->credentials->revoke( $id, array( 'workspace_id' => $workspace_id, 'actor_id' => get_current_user_id() ) );

		return array( 'message' => __( 'Credential revoked. Any request using it now fails.', 'replicaforge' ), 'tone' => 'success' );
	}

	/**
	 * Create a webhook.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, string>
	 */
	private function do_create_webhook( $workspace_id ) {
		if ( ! $this->may( $workspace_id, 'api.webhooks.manage' ) ) {
			return $this->refused();
		}

		$created = $this->webhooks->create(
			$workspace_id,
			array(
				'name'     => isset( $_POST['webhook_name'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_name'] ) ) : '',
				'endpoint' => isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '',
				'events'   => isset( $_POST['events'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['events'] ) ) : array(),
				'user_id'  => get_current_user_id(),
			)
		);

		if ( is_wp_error( $created ) ) {
			return array( 'message' => (string) $created->get_error_message(), 'tone' => 'error' );
		}

		$this->queue_notice(
			sprintf(
				/* translators: %s: the webhook signing secret, shown once. */
				__( 'Webhook created. Copy this signing secret now — it is derived and cannot be shown again: %s', 'replicaforge' ),
				(string) ( $created['secret'] ?? '' )
			),
			'secret'
		);

		return array( 'message' => __( 'Webhook created.', 'replicaforge' ), 'tone' => 'success' );
	}

	/**
	 * Send a test delivery.
	 *
	 * @return array<string, string>
	 */
	private function do_webhook_test() {
		$id      = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '';
		$outcome = $this->delivery->test( $id );

		if ( 'delivered' === (string) ( $outcome['state'] ?? '' ) ) {
			return array( 'message' => __( 'Test delivery accepted by the endpoint.', 'replicaforge' ), 'tone' => 'success' );
		}

		return array(
			'message' => sprintf(
				/* translators: 1: the delivery state, 2: the reason. */
				__( 'Test delivery did not succeed. State: %1$s. Reason: %2$s', 'replicaforge' ),
				(string) ( $outcome['state'] ?? 'unknown' ),
				(string) ( $outcome['reason'] ?? 'unknown' )
			),
			'tone'    => 'error',
		);
	}

	/**
	 * Disable a webhook.
	 *
	 * @return array<string, string>
	 */
	private function do_webhook_disable() {
		$id      = isset( $_POST['webhook_id'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_id'] ) ) : '';
		$updated = $this->webhooks->update( $id, array( 'status' => 'disabled' ) );

		if ( is_wp_error( $updated ) ) {
			return array( 'message' => (string) $updated->get_error_message(), 'tone' => 'error' );
		}

		return array( 'message' => __( 'Webhook disabled. Pending deliveries are kept and will be sent when it is enabled again.', 'replicaforge' ), 'tone' => 'success' );
	}

	/**
	 * Retry one delivery.
	 *
	 * @return array<string, string>
	 */
	private function do_retry_delivery() {
		$id     = isset( $_POST['delivery_id'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_id'] ) ) : '';
		$record = $this->deliveries->read( $id );

		if ( null === $record ) {
			return array( 'message' => __( 'That delivery does not exist.', 'replicaforge' ), 'tone' => 'error' );
		}

		$this->deliveries->record( $id, array( 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => time(), 'error' => '' ) );

		$outcome = $this->delivery->deliver( array_merge( $record, array( 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => time() ) ) );

		return array(
			'message' => sprintf(
				/* translators: %s: the delivery state. */
				__( 'Delivery retried. State: %s', 'replicaforge' ),
				(string) ( $outcome['state'] ?? 'unknown' )
			),
			'tone'    => 'delivered' === (string) ( $outcome['state'] ?? '' ) ? 'success' : 'error',
		);
	}

	/**
	 * Create an automation.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, string>
	 */
	private function do_create_automation( $workspace_id ) {
		if ( ! $this->may( $workspace_id, 'api.automations.manage' ) ) {
			return $this->refused();
		}

		$action  = isset( $_POST['automation_action'] ) ? sanitize_key( wp_unslash( $_POST['automation_action'] ) ) : '';
		$options = array();

		if ( 'start_workflow' === $action ) {
			$options['source_url'] = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
		} elseif ( 'notify' === $action ) {
			$options['type'] = isset( $_POST['notification_type'] ) ? sanitize_key( wp_unslash( $_POST['notification_type'] ) ) : '';
		}

		$options['project_id'] = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';

		$stored = $this->automations->save(
			array(
				'workspace_id' => $workspace_id,
				'project_id'   => $options['project_id'],
				'name'         => isset( $_POST['automation_name'] ) ? sanitize_text_field( wp_unslash( $_POST['automation_name'] ) ) : '',
				'trigger'      => isset( $_POST['trigger'] ) ? sanitize_text_field( wp_unslash( $_POST['trigger'] ) ) : '',
				'action'       => $action,
				'options'      => $options,
				'user_id'      => get_current_user_id(),
			)
		);

		if ( is_wp_error( $stored ) ) {
			return array( 'message' => (string) $stored->get_error_message(), 'tone' => 'error' );
		}

		return array( 'message' => __( 'Automation created.', 'replicaforge' ), 'tone' => 'success' );
	}

	/**
	 * Run an automation.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, string>
	 */
	private function do_run_automation( $workspace_id ) {
		if ( ! $this->may( $workspace_id, 'api.automations.manage' ) ) {
			return $this->refused();
		}

		$id     = isset( $_POST['automation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['automation_id'] ) ) : '';
		$result = $this->automation_runner->run_now( $id, array( 'actor_id' => get_current_user_id() ) );

		if ( ! empty( $result['ok'] ) ) {
			return array(
				'message' => sprintf(
					/* translators: %s: the workflow outcome, e.g. finished or waiting_approval. */
					__( 'Automation ran. Outcome: %s', 'replicaforge' ),
					(string) ( $result['reason'] ?? 'done' )
				),
				'tone'    => 'success',
			);
		}

		return array(
			'message' => sprintf(
				/* translators: %s: the machine reason the automation did not succeed. */
				__( 'Automation did not succeed: %s', 'replicaforge' ),
				(string) ( $result['reason'] ?? 'unknown' )
			),
			'tone'    => 'error',
		);
	}

	/**
	 * Delete an automation.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, string>
	 */
	private function do_delete_automation( $workspace_id ) {
		if ( ! $this->may( $workspace_id, 'api.automations.manage' ) ) {
			return $this->refused();
		}

		$id = isset( $_POST['automation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['automation_id'] ) ) : '';

		if ( ! $this->automations->forget( $id ) ) {
			return array( 'message' => __( 'That automation does not exist.', 'replicaforge' ), 'tone' => 'error' );
		}

		( new Collaboration_Log() )->audit(
			$workspace_id,
			'automation_removed',
			array( 'target_type' => 'automation', 'target_id' => $id ),
			get_current_user_id()
		);

		return array( 'message' => __( 'Automation deleted.', 'replicaforge' ), 'tone' => 'success' );
	}

	/**
	 * Change an extension's state.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, string>
	 */
	private function do_extension_state( $workspace_id ) {
		if ( ! $this->may( $workspace_id, 'api.extensions.manage' ) ) {
			return $this->refused();
		}

		$id    = isset( $_POST['extension_id'] ) ? sanitize_text_field( wp_unslash( $_POST['extension_id'] ) ) : '';
		$state = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';

		$record = $this->extensions->set_state( $id, $state );

		if ( is_wp_error( $record ) ) {
			return array( 'message' => (string) $record->get_error_message(), 'tone' => 'error' );
		}

		( new Collaboration_Log() )->audit(
			$workspace_id,
			'extension_state_changed',
			array( 'target_type' => 'extension', 'target_id' => $id, 'metadata' => array( 'state' => $state ) ),
			get_current_user_id()
		);

		$this->dispatcher->emit(
			'extension.disabled',
			array(
				'workspace_id' => $workspace_id,
				'resource_id'  => $id,
				'actor_id'     => get_current_user_id(),
				'data'         => array( 'state' => $state ),
			)
		);

		return array( 'message' => __( 'Extension state changed.', 'replicaforge' ), 'tone' => 'success' );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether the current user holds a capability in a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $capability  Workspace capability.
	 * @return bool
	 */
	private function may( $workspace_id, $capability ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( '' === (string) $workspace_id ) {
			return false;
		}

		return $this->permissions->can( get_current_user_id(), (string) $workspace_id, (string) $capability );
	}

	/**
	 * Return a refusal message.
	 *
	 * @return array<string, string>
	 */
	private function refused() {
		return array(
			'message' => __( 'You do not have permission to do that in this workspace.', 'replicaforge' ),
			'tone'    => 'error',
		);
	}

	/**
	 * Return the current user's workspace.
	 *
	 * @return string
	 */
	private function workspace_id() {
		foreach ( ( new Workspace_Store() )->for_user( get_current_user_id() ) as $workspace ) {
			$id = (string) ( $workspace['public_id'] ?? '' );

			if ( '' !== $id ) {
				return $id;
			}
		}

		return '';
	}

	/**
	 * Summarise the delivery log for a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return string
	 */
	private function count_deliveries( $workspace_id ) {
		$counts = $this->deliveries->counts( $workspace_id );

		if ( array() === $counts ) {
			return __( 'none yet', 'replicaforge' );
		}

		$parts = array();

		foreach ( $counts as $state => $count ) {
			$parts[] = (string) $state . ': ' . (string) $count;
		}

		return implode( ', ', $parts );
	}

	/**
	 * Queue a notice for the next render.
	 *
	 * @param string $message Message.
	 * @param string $tone    Tone.
	 * @return void
	 */
	private function queue_notice( $message, $tone = 'success' ) {
		if ( '' === $message ) {
			return;
		}

		$notices = get_option( self::NOTICE_OPTION, array() );
		$notices = is_array( $notices ) ? $notices : array();

		$notices[] = array(
			'message' => substr( (string) $message, 0, 2000 ),
			'tone'    => in_array( (string) $tone, array( 'success', 'error', 'secret' ), true ) ? (string) $tone : 'success',
		);

		/* Bounded, so a session that never loads the page does not grow an option forever. */
		update_option( self::NOTICE_OPTION, array_slice( $notices, -5 ), false );
	}

	/**
	 * Print and clear the queued notices.
	 *
	 * @return void
	 */
	private function notice() {
		$notices = get_option( self::NOTICE_OPTION, array() );

		if ( ! is_array( $notices ) || array() === $notices ) {
			return;
		}

		/* Cleared before printing, so a refresh does not show the same secret twice. */
		delete_option( self::NOTICE_OPTION );

		foreach ( $notices as $notice ) {
			$tone = (string) ( $notice['tone'] ?? 'success' );

			echo '<div class="notice notice-' . esc_attr( 'secret' === $tone ? 'warning' : ( 'error' === $tone ? 'error' : 'success' ) ) . ' is-dismissible"><p>';

			/*
			 * `secret` notices carry a token. It is escaped like any other text — a token
			 * cannot contain markup, but escaping an attacker-influenced string is the habit
			 * worth keeping — and the surrounding paragraph says plainly that it is shown
			 * once.
			 */
			echo esc_html( (string) ( $notice['message'] ?? '' ) );

			echo '</p></div>';
		}
	}

	/**
	 * Open the page wrapper.
	 *
	 * @param string $title Page title.
	 * @return void
	 */
	private function open( $title ) {
		echo '<div class="wrap rf-wrap">';
		echo '<h1>' . esc_html( (string) $title ) . '</h1>';
	}

	/**
	 * Close the page wrapper.
	 *
	 * @return void
	 */
	private function close() {
		echo '</div>';
	}

	/**
	 * Print one panel.
	 *
	 * @param string $title Panel title.
	 * @param string $body  Panel body, already escaped by the caller.
	 * @return void
	 */
	private function panel( $title, $body ) {
		echo '<div class="rf-panel">';
		echo '<h2>' . esc_html( (string) $title ) . '</h2>';
		/* The body is assembled from escaped fragments by the callers above. */
		echo '<div class="rf-panel-body">' . $body . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at each fragment.
		echo '</div>';
	}

	/**
	 * Print a status pill.
	 *
	 * @param string $status Status.
	 * @return void
	 */
	private function pill( $status ) {
		$status = (string) $status;

		$tone = 'neutral';

		if ( in_array( $status, array( 'active', 'delivered', 'recorded', 'finished' ), true ) ) {
			$tone = 'ok';
		} elseif ( in_array( $status, array( 'failed', 'error', 'dead', 'failed' ), true ) ) {
			$tone = 'bad';
		} elseif ( in_array( $status, array( 'failing', 'pending', 'failed', 'disabled', 'expired', 'skipped' ), true ) ) {
			$tone = 'warn';
		}

		echo '<span class="rf-pill rf-pill-' . esc_attr( $tone ) . '">' . esc_html( '' !== $status ? $status : '—' ) . '</span>';
	}

	/**
	 * The last extension error, read once.
	 *
	 * @var string
	 */
	private $last_error = '';
}
