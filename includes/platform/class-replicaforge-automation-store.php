<?php
/**
 * Phase 20: agency automations.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Read and write access to `replicaforge_automations`.
 *
 * ### An automation is a definition, not code
 *
 * A row names a trigger and an action, both from the closed vocabularies in
 * {@see Platform_Limits}. There is no column for a callable, a template, a query or a URL —
 * so there is nothing an automation could be *changed into* by editing the table, and no
 * "run arbitrary code" action to request because the action column is validated against a
 * list of five.
 *
 * The trigger is an event type, so an automation subscribes to something that actually
 * happens rather than to a condition somebody invented. `manual` is the one trigger that is
 * a direct call.
 *
 * ### A workspace is required, and so is a project for project-scoped work
 *
 * `workspace_id` is NOT NULL in practice because an automation without one has no owner and
 * no permission boundary: `start_workflow` would have nothing to check the actor's access
 * against. `project_id` may be empty, meaning "any project in this workspace", which is the
 * normal shape for a notify-on-completion rule.
 */
class Automation_Store extends Collaboration_Store {

	/**
	 * Entity kind, matching `Workspace_Limits::table()`.
	 *
	 * @var string
	 */
	protected $kind = 'automation';

	/**
	 * Writable columns.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id',
			'workspace_id',
			'project_id',
			'extension_id',
			'user_id',
			'name',
			'trigger',
			'action',
			'options',
			'status',
			'run_count',
			'failure_count',
			'last_run_at',
			'last_error',
			'created_at',
			'updated_at',
		);
	}

	/**
	 * Column types.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'workspace_id'   => 'line',
			'project_id'     => 'line',
			'extension_id'   => 'line',
			'user_id'        => 'int',
			'name'           => 'line',
			'trigger'        => 'line',
			'action'         => 'line',
			'options'        => 'json',
			'status'         => 'line',
			'run_count'      => 'int',
			'failure_count'  => 'int',
			'last_run_at'    => 'int',
			'last_error'     => 'text',
			'created_at'     => 'datetime',
			'updated_at'     => 'datetime',
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'name' );
	}

	/**
	 * @return array<int, string>
	 */
	protected function groupable_columns() {
		return array( 'status', 'trigger', 'action' );
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Create or update an automation.
	 *
	 * @param array<string, mixed> $input Automation definition.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function save( array $input ) {
		if ( ! $this->ready() ) {
			return new \WP_Error(
				'automation_table_missing',
				__( 'The automation tables are not installed. Run the database migration.', 'replicaforge' ),
				array( 'status' => 500 )
			);
		}

		$workspace_id = $this->clean_workspace( $input['workspace_id'] ?? '' );

		if ( '' === $workspace_id ) {
			return new \WP_Error(
				'automation_workspace_required',
				__( 'An automation must belong to a workspace, so it can be checked against the permissions of whoever created it.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$name = isset( $input['name'] ) && is_string( $input['name'] ) ? trim( sanitize_text_field( $input['name'] ) ) : '';

		if ( '' === $name || strlen( $name ) > 120 ) {
			return new \WP_Error( 'automation_name_required', __( 'An automation needs a name.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		$trigger = isset( $input['trigger'] ) && is_string( $input['trigger'] ) ? trim( $input['trigger'] ) : '';

		if ( ! Platform_Limits::is_automation_trigger( $trigger ) ) {
			return new \WP_Error(
				'automation_trigger_unknown',
				__( 'That is not a trigger this platform offers.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$action = isset( $input['action'] ) && is_string( $input['action'] ) ? trim( $input['action'] ) : '';

		if ( ! Platform_Limits::is_automation_action( $action ) ) {
			return new \WP_Error(
				'automation_action_unknown',
				sprintf(
					/* translators: %s: comma-separated action names. */
					__( 'That is not an action this platform offers. It supports: %s.', 'replicaforge' ),
					implode( ', ', array_keys( Platform_Limits::AUTOMATION_ACTIONS ) )
				),
				array( 'status' => 400 )
			);
		}

		$options = $this->clean_options( $action, $input['options'] ?? array() );

		if ( is_wp_error( $options ) ) {
			return $options;
		}

		$status = isset( $input['status'] ) && Platform_Limits::is_automation_state( $input['status'] )
			? (string) $input['status']
			: 'active';

		$public_id = isset( $input['public_id'] ) ? $this->clean_public_id( $input['public_id'] ) : '';

		if ( '' !== $public_id ) {
			$changes = array(
				'name'      => substr( $name, 0, 120 ),
				'trigger'   => $trigger,
				'action'    => $action,
				'options'   => $options,
				'status'    => $status,
				'project_id' => $this->clean_project( $input['project_id'] ?? '' ),
			);

			$this->update_row( $public_id, $changes );

			return $this->read( '*', $public_id );
		}

		if ( (int) $this->count_where( $workspace_id, array() ) >= Platform_Limits::MAX_AUTOMATIONS ) {
			return new \WP_Error(
				'automation_limit_reached',
				sprintf(
					/* translators: %d: the maximum number of automations. */
					__( 'This workspace already has %d automations, which is the limit.', 'replicaforge' ),
					Platform_Limits::MAX_AUTOMATIONS
				),
				array( 'status' => 409 )
			);
		}

		$stored = $this->insert(
			array(
				'public_id'     => $this->new_public_id(),
				'workspace_id'  => $workspace_id,
				'project_id'    => $this->clean_project( $input['project_id'] ?? '' ),
				'extension_id'  => $this->clean_key( $input['extension_id'] ?? '' ),
				'user_id'       => (int) ( $input['user_id'] ?? get_current_user_id() ),
				'name'          => substr( $name, 0, 120 ),
				'trigger'       => $trigger,
				'action'        => $action,
				'options'       => $options,
				'status'        => $status,
				'run_count'     => 0,
				'failure_count' => 0,
				'last_run_at'   => 0,
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( null === $stored ) {
			return new \WP_Error( 'automation_not_stored', __( 'The automation could not be saved.', 'replicaforge' ), array( 'status' => 500 ) );
		}

		( new Collaboration_Log() )->audit(
			$workspace_id,
			'automation_created',
			array(
				'target_type' => 'automation',
				'target_id'   => (string) $stored['public_id'],
				'metadata'    => array(
					'trigger' => $trigger,
					'action'  => $action,
					/*
					 * Only the names, never the options. `start_workflow` options carry a
					 * source URL and `webhook` options carry a subscription id; neither
					 * belongs in a timeline, and the trigger and action are what an operator
					 * reading an incident needs.
					 */
				),
			),
			(int) $stored['user_id']
		);

		return $stored;
	}

	/**
	 * Record a run outcome.
	 *
	 * @param string $public_id  Automation public id.
	 * @param bool   $succeeded  Whether it succeeded.
	 * @param string $error      Why, when it did not.
	 * @return bool
	 */
	public function record_run( $public_id, $succeeded, $error = '' ) {
		$public_id = $this->clean_public_id( $public_id );

		if ( '' === $public_id || ! $this->ready() ) {
			return false;
		}

		$record = $this->read( '*', $public_id );

		if ( null === $record ) {
			return false;
		}

		$failures = $succeeded ? 0 : (int) ( $record['failure_count'] ?? 0 ) + 1;

		$changes = array(
			'run_count'      => (int) ( $record['run_count'] ?? 0 ) + 1,
			'failure_count'  => $failures,
			'last_run_at'    => time(),
		);

		if ( $succeeded ) {
			$changes['last_error'] = '';

			/* A recovered automation returns to active. Leaving it in `failing` after one
			 * success makes the console a bad guide — an operator would turn off a rule that
			 * is plainly working. */
			if ( 'failing' === (string) $record['status'] ) {
				$changes['status'] = 'active';
			}
		} else {
			$changes['last_error'] = substr( (string) $error, 0, 300 );

			if ( $failures >= Platform_Limits::AUTOMATION_MAX_FAILURES ) {
				$changes['status'] = 'failing';
			}
		}

		$this->update_row( $public_id, $changes );

		return true;
	}

	/**
	 * Remove an automation.
	 *
	 * @param string $public_id Automation public id.
	 * @return bool
	 */
	public function forget( $public_id ) {
		$public_id = $this->clean_public_id( $public_id );

		if ( '' === $public_id || ! $this->ready() ) {
			return false;
		}

		return (bool) $this->delete_rows( array( 'public_id' => $public_id ) );
	}

	/**
	 * Remove every automation for a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return int
	 */
	public function forget_workspace( $workspace_id ) {
		$workspace_id = $this->clean_workspace( $workspace_id );

		if ( '' === $workspace_id || ! $this->ready() ) {
			return 0;
		}

		return (int) $this->delete_rows( array( 'workspace_id' => $workspace_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Read one automation.
	 *
	 * @param string $workspace_id Workspace id, or `'*'`.
	 * @param string $public_id    Automation public id.
	 * @return array<string, mixed>|null
	 */
	public function read( $workspace_id, $public_id ) {
		$public_id = $this->clean_public_id( $public_id );

		if ( '' === $public_id || ! $this->ready() ) {
			return null;
		}

		if ( '' === (string) $workspace_id || '*' === (string) $workspace_id ) {
			return $this->read_by_id( $public_id );
		}

		return $this->find( $this->clean_workspace( $workspace_id ), $public_id );
	}

	/**
	 * List automations.
	 *
	 * @param string              $workspace_id Workspace id, or `'*'`.
	 * @param array<string, mixed> $args         Filters.
	 * @return array<string, mixed>
	 */
	public function browse( $workspace_id, array $args = array() ) {
		if ( ! $this->ready() ) {
			return $this->empty_page();
		}

		$clean = array( 'per_page' => isset( $args['per_page'] ) ? (int) $args['per_page'] : Workspace_Limits::page_size( 0 ) );

		if ( isset( $args['status'] ) && Platform_Limits::is_automation_state( $args['status'] ) ) {
			$clean['status'] = (string) $args['status'];
		}

		if ( isset( $args['trigger'] ) && Platform_Limits::is_automation_trigger( $args['trigger'] ) ) {
			$clean['trigger'] = (string) $args['trigger'];
		}

		if ( isset( $args['search'] ) ) {
			$search = sanitize_text_field( (string) $args['search'] );
			if ( '' !== $search ) {
				$clean['search'] = $search;
			}
		}

		$workspace_id = $this->clean_workspace( $workspace_id );

		return $this->query( '' !== $workspace_id ? $workspace_id : '*', $clean );
	}

	/**
	 * Return the active automations listening for a trigger in a workspace.
	 *
	 * Called on every emission, so it is one indexed read for the workspace rather than a
	 * scan of every automation on the site.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $trigger      Trigger.
	 * @param int    $limit        Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function listening( $workspace_id, $trigger, $limit = 0 ) {
		$workspace_id = $this->clean_workspace( $workspace_id );

		if ( '' === $workspace_id || ! Platform_Limits::is_automation_trigger( $trigger ) || ! $this->ready() ) {
			return array();
		}

		$page = $this->query(
			$workspace_id,
			array(
				'status'   => 'active',
				'trigger'  => $trigger,
				'per_page' => Workspace_Limits::page_size( $limit > 0 ? (int) $limit : Platform_Limits::AUTOMATION_BATCH ),
			)
		);

		return $page['items'];
	}

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------ */

	/**
	 * Validate an automation's options for its action.
	 *
	 * ### Per action, because the options mean different things
	 *
	 * `start_workflow` needs a source URL — and that URL goes through the SSRF boundary,
	 * because an automation that fires on a timer is a scheduled fetch and a scheduled fetch
	 * to `http://169.254.169.254/` is exactly as dangerous as a manual one.
	 *
	 * `notify` needs a notification type from Phase 15's own vocabulary, not a free string,
	 * so it cannot ask for a channel that does not exist.
	 *
	 * `webhook` needs a subscription id, and the caller is told when it does not resolve
	 * rather than discovering it on every firing.
	 *
	 * @param string $action  Action.
	 * @param mixed  $options Proposed options.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function clean_options( $action, $options ) {
		$options = is_array( $options ) ? $options : array();
		$out     = array();

		switch ( $action ) {
			case 'start_workflow':
				$url = isset( $options['source_url'] ) && is_scalar( $options['source_url'] ) ? trim( (string) $options['source_url'] ) : '';

				if ( '' === $url ) {
					return new \WP_Error(
						'automation_option_required',
						__( 'Starting a workflow needs a source address.', 'replicaforge' ),
						array( 'status' => 400 )
					);
				}

				if ( ! Security::is_safe_public_reference( $url ) ) {
					return new \WP_Error(
						'automation_option_unsafe',
						__( 'That source address is not a public web address, so it cannot be used.', 'replicaforge' ),
						array( 'status' => 400 )
					);
				}

				$out['source_url'] = (string) Security::normalize_http_url( $url );
				$out['type']        = isset( $options['type'] ) && Orchestrator_Limits::is_type( $options['type'] )
					? (string) $options['type']
					: 'single_page';
				$out['mode']        = isset( $options['mode'] ) && Orchestrator_Limits::is_mode( $options['mode'] )
					? (string) $options['mode']
					: 'balanced';
				$out['project_id']  = $this->clean_project( $options['project_id'] ?? '' );

				return $out;

			case 'notify':
				$type = isset( $options['type'] ) && is_string( $options['type'] ) ? trim( $options['type'] ) : '';

				/*
				 * Phase 15's own vocabulary, read through. An automation cannot invent a
				 * notification type, because `Notification_Service::compose()` returns null
				 * for one it does not handle — so a made-up type would be an automation that
				 * fails every single time for a reason the author could not see.
				 */
				if ( '' === $type || ! isset( Workspace_Limits::NOTIFICATION_TYPES[ $type ] ) ) {
					return new \WP_Error(
						'automation_option_unknown',
						sprintf(
							/* translators: %s: comma-separated notification type names. */
							__( 'That is not a notification type this site can send. It supports: %s.', 'replicaforge' ),
							implode( ', ', array_keys( Workspace_Limits::NOTIFICATION_TYPES ) )
						),
						array( 'status' => 400 )
					);
				}

				$out['type']       = $type;
				$out['project_id'] = $this->clean_project( $options['project_id'] ?? '' );

				return $out;

			case 'create_review':
				$out['project_id'] = $this->clean_project( $options['project_id'] ?? '' );
				$out['title']      = isset( $options['title'] ) && is_scalar( $options['title'] )
					? substr( sanitize_text_field( (string) $options['title'] ), 0, 120 )
					: '';

				/*
				 * A reviewer is required here rather than discovered at run time.
				 *
				 * `Review_Store::create()` returns null when neither a reviewer id nor a
				 * reviewer email is given — a review with nobody to perform it is a status
				 * badge, not a review — and it returns null for a project with no resolvable
				 * version, because §15 forbids a review that is not bound to the thing it
				 * approves.
				 *
				 * Both refusals are silent. An automation whose every run fails with a reason
				 * the author never chose is worse than one that refuses to be saved with a
				 * reason they can act on, so the requirement is enforced where the operator
				 * is still reading the form.
				 */
				$reviewer_id = isset( $options['reviewer_id'] ) ? max( 0, (int) $options['reviewer_id'] ) : 0;
				$reviewer_email = isset( $options['reviewer_email'] ) && is_scalar( $options['reviewer_email'] )
					? sanitize_email( (string) $options['reviewer_email'] )
					: '';

				if ( 0 === $reviewer_id && '' === $reviewer_email ) {
					return new \WP_Error(
						'automation_reviewer_required',
						__( 'Creating a review needs someone to review it. Name a reviewer or a reviewer email address.', 'replicaforge' ),
						array( 'status' => 400 )
					);
				}

				if ( $reviewer_id > 0 && ! get_userdata( $reviewer_id ) ) {
					return new \WP_Error(
						'automation_reviewer_unknown',
						__( 'That reviewer is not a user on this site.', 'replicaforge' ),
						array( 'status' => 400 )
					);
				}

				$out['reviewer_id']    = $reviewer_id;
				$out['reviewer_email'] = $reviewer_email;
				$out['type']           = isset( $options['type'] ) && in_array( (string) $options['type'], Workspace_Limits::REVIEW_TYPES, true )
					? (string) $options['type']
					: 'internal';

				return $out;

			case 'create_task':
				$out['project_id']  = $this->clean_project( $options['project_id'] ?? '' );
				$out['title']       = isset( $options['title'] ) && is_scalar( $options['title'] )
					? substr( sanitize_text_field( (string) $options['title'] ), 0, 200 )
					: '';
				$out['description'] = isset( $options['description'] ) && is_scalar( $options['description'] )
					? substr( sanitize_text_field( (string) $options['description'] ), 0, 1000 )
					: '';
				$out['assignee_id'] = isset( $options['assignee_id'] ) ? max( 0, (int) $options['assignee_id'] ) : 0;

				return $out;

			case 'webhook':
				$webhook_id = isset( $options['webhook_id'] ) && is_string( $options['webhook_id'] )
					? substr( preg_replace( '/[^A-Za-z0-9]/', '', $options['webhook_id'] ), 0, 26 )
					: '';

				if ( '' === $webhook_id ) {
					return new \WP_Error(
						'automation_option_required',
						__( 'Sending to a webhook needs the subscription to send to.', 'replicaforge' ),
						array( 'status' => 400 )
					);
				}

				$out['webhook_id'] = $webhook_id;

				return $out;
		}

		return $out;
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_workspace( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_project( $value ) {
		return is_scalar( $value ) ? substr( (string) $value, 0, 64 ) : '';
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_public_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}

	/**
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_key( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ), 0, 64 ) : '';
	}
}
