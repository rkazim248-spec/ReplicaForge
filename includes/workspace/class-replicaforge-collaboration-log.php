<?php
/**
 * Phase 15: the activity timeline and the security audit log.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Two logs, deliberately apart.
 *
 * §24 and §25 ask for both, and §25 says to "separate" them. They answer different
 * questions, they have different readers, and they have different retention:
 *
 * | | Activity | Audit |
 * |---|---|---|
 * | Question | "What happened to this project?" | "Who changed this permission, and when?" |
 * | Reader | the whole team, including clients | an administrator answering a security question |
 * | Retention | {@see Workspace_Limits::ACTIVITY_TTL}, 180 days | {@see Workspace_Limits::AUDIT_TTL}, one year |
 * | Contains | generation, validation, comment, approval | role changes, invitations, exports, deletions |
 *
 * Mixing them produces a timeline where a security question cannot be answered — the
 * permission change is buried among page regenerations — and an audit log that grows with
 * every keystroke of ordinary work, so the rows that matter are the ones nobody queries.
 *
 * So they are two tables, two classes, two vocabularies, and two retention periods. A
 * caller picks the one it means, and the names are similar enough that the choice is a
 * deliberate one rather than a coin toss.
 *
 * ### Neither log stores a secret, and neither is a request dump
 *
 * §25 forbids logging API keys, passwords, tokens, cookies and credentials. The reliable
 * way to keep a secret out of a log is to never build the log line from the request in the
 * first place — which is why the audit table stores a *hash* of the request context rather
 * than the context, and why neither class accepts a caller-supplied context blob. There is
 * no signature on this class that takes one.
 *
 * ### Metadata is redacted, not trusted
 *
 * `metadata` goes through {@see Data_Redactor} on the way in. A caller that passes a token
 * in metadata gets it stored redacted rather than discovering later that an audit table is
 * full of credentials. The filter is not a guarantee that a caller was careful; it is the
 * reason a careless caller is not a security incident.
 */
final class Collaboration_Log {

	/**
	 * The activity store.
	 *
	 * @var Activity_Store
	 */
	private $activity;

	/**
	 * The audit store.
	 *
	 * @var Audit_Store
	 */
	private $audit;

	/**
	 * Constructor.
	 *
	 * @param Activity_Store|null $activity Optional activity store.
	 * @param Audit_Store|null    $audit    Optional audit store.
	 */
	public function __construct( $activity = null, $audit = null ) {
		$this->activity = $activity instanceof Activity_Store ? $activity : new Activity_Store();
		$this->audit    = $audit instanceof Audit_Store ? $audit : new Audit_Store();
	}

	/**
	 * Record an activity event.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $action       Event name from {@see Workspace_Limits::ACTIVITY_EVENTS}.
	 * @param array<string, mixed> $context      `project_id`, `resource_type`, `resource_id`, `metadata`.
	 * @param int                  $actor_id     Actor.
	 * @return array<string, mixed>|null
	 */
	public function activity( $workspace_id, $action, array $context = array(), $actor_id = 0 ) {
		return $this->activity->record( $workspace_id, $action, $context, $actor_id );
	}

	/**
	 * Record an audit event.
	 *
	 * @param string               $workspace_id Workspace id, or empty for a site-level event.
	 * @param string               $action       Event name from {@see Workspace_Limits::AUDIT_EVENTS}.
	 * @param array<string, mixed> $context      `project_id`, `target_type`, `target_id`, `metadata`.
	 * @param int                  $actor_id     Actor.
	 * @return array<string, mixed>|null
	 */
	public function audit( $workspace_id, $action, array $context = array(), $actor_id = 0 ) {
		return $this->audit->record( $workspace_id, $action, $context, $actor_id );
	}

	/**
	 * Return a project timeline.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function timeline( $workspace_id, $project_id, array $args = array() ) {
		return $this->activity->timeline( $workspace_id, $project_id, $args );
	}

	/**
	 * Return an audit trail.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function audit_trail( $workspace_id, array $args = array() ) {
		return $this->audit->trail( $workspace_id, $args );
	}

	/**
	 * Return the activity store.
	 *
	 * @return Activity_Store
	 */
	public function activity_store() {
		return $this->activity;
	}

	/**
	 * Return the audit store.
	 *
	 * @return Audit_Store
	 */
	public function audit_store() {
		return $this->audit;
	}

	/**
	 * Prune both logs to their retention.
	 *
	 * Two different ages, which is the clearest single statement that these are not one
	 * log. Activity is pruned at 180 days; audit at a year. A permission change outlives a
	 * page generation by two thirds.
	 *
	 * @return array<string, int>
	 */
	public function prune() {
		return array(
			'activity' => $this->activity->prune( Workspace_Limits::ACTIVITY_TTL ),
			'audit'    => $this->audit->prune( Workspace_Limits::AUDIT_TTL ),
		);
	}
}

/**
 * The activity timeline.
 *
 * A separate class from {@see Audit_Store} rather than a flag on one store, because the two
 * tables have different columns, different vocabularies and different retention, and a
 * shared class would need a `kind` parameter threaded through every method to express that.
 */
final class Activity_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'activity';

	/**
	 * The project store, for names.
	 *
	 * @var Project_Repository
	 */
	private $projects;

	/**
	 * Constructor.
	 *
	 * @param Collaboration_Schema|null $schema   Optional schema.
	 * @param Logger|null              $logger   Optional logger.
	 * @param Project_Repository|null  $projects Optional project repository.
	 */
	public function __construct( $schema = null, $logger = null, $projects = null ) {
		parent::__construct( $schema, $logger );
		$this->projects = $projects instanceof Project_Repository ? $projects : new Project_Repository();
	}

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array( 'public_id', 'workspace_id', 'project_id', 'actor_id', 'actor_name', 'action', 'resource_type', 'resource_id', 'metadata', 'created_at' );
	}

	/**
	 * Return the storage type of each column.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'public_id'     => 'string',
			'workspace_id'  => 'string',
			'project_id'    => 'string',
			'actor_id'      => 'int',
			'actor_name'    => 'line',
			'action'        => 'line',
			'resource_type' => 'line',
			'resource_id'   => 'string',
			'metadata'      => 'json',
			'created_at'    => 'datetime',
		);
	}

	/**
	 * Return a project timeline, newest first.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function timeline( $workspace_id, $project_id, array $args = array() ) {
		$page = $this->query(
			(string) $workspace_id,
			array_merge( $args, array( 'project_id' => (string) $project_id ) )
		);

		foreach ( $page['items'] as $index => $event ) {
			$page['items'][ $index ] = $this->present( $event, $project_id );
		}

		return $page;
	}

	/**
	 * Record an event.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $action       Event name.
	 * @param array<string, mixed> $context      Context.
	 * @param int                  $actor_id     Actor.
	 * @return array<string, mixed>|null
	 */
	public function record( $workspace_id, $action, array $context = array(), $actor_id = 0 ) {
		$workspace_id = (string) $workspace_id;
		$action       = (string) $action;

		if ( '' === $workspace_id || ! in_array( $action, Workspace_Limits::ACTIVITY_EVENTS, true ) || ! $this->ready() ) {
			// An unrecognised event name is dropped rather than stored. It would render
			// as a blank row in the timeline, and a blank row in an activity history is
			// worse than a missing one: it looks like a gap in the record.
			return null;
		}

		return $this->insert(
			array(
				'public_id'     => $this->new_public_id(),
				'workspace_id'  => $workspace_id,
				'project_id'    => substr( (string) ( $context['project_id'] ?? '' ), 0, 64 ),
				'actor_id'      => max( 0, (int) $actor_id ),
				'actor_name'    => $this->actor_name( $actor_id ),
				'action'        => $action,
				'resource_type' => substr( (string) ( $context['resource_type'] ?? '' ), 0, 32 ),
				'resource_id'   => substr( (string) ( $context['resource_id'] ?? '' ), 0, 64 ),
				'metadata'      => (array) ( $context['metadata'] ?? array() ),
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Present an event for a timeline.
	 *
	 * §32 wants sentences a person reads: "Kazim generated version 4", "Sara requested
	 * changes". The sentence is built here so every surface says the same thing, and so a
	 * missing name reads as an action ReplicaForge performed rather than as a person who
	 * cannot be identified.
	 *
	 * @param array<string, mixed> $event     Event row.
	 * @param string               $project_id Project id, for naming.
	 * @return array<string, mixed>
	 */
	public function present( array $event, $project_id = '' ) {
		$actor = trim( (string) ( $event['actor_name'] ?? '' ) );
		$who   = ( '' !== $actor ) ? $actor : __( 'ReplicaForge', 'replicaforge' );

		$event['summary'] = $this->summarise( (string) $event['action'], $who, $event );
		// The actor is resolved at write time and kept, so a timeline still reads
		// correctly after the person leaves. `actor_id` remains for a join.
		return $event;
	}

	/**
	 * Return the activity store's activity histogram for a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return array<string, int>
	 */
	public function action_counts( $workspace_id ) {
		return $this->group_counts( (string) $workspace_id, 'action' );
	}

	/**
	 * Delete events older than a retention period.
	 *
	 * @param int $ttl Seconds.
	 * @return int Rows removed.
	 */
	public function prune( $ttl ) {
		global $wpdb;
		if ( ! $this->ready() ) {
			return 0;
		}
		$table = $this->table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				gmdate( 'Y-m-d H:i:s', time() - (int) $ttl )
			)
		);
	}

	/**
	 * Build a human sentence for an event.
	 *
	 * @param string               $action     Event name.
	 * @param string               $who        Actor name.
	 * @param array<string, mixed> $event      Event row.
	 * @param string               $project_id Project id.
	 * @return string
	 */
	private function summarise( $action, $who, array $event ) {
		$metadata = (array) ( $event['metadata'] ?? array() );

		$detail = static function ( $key, $default = '' ) use ( $metadata ) {
			return isset( $metadata[ $key ] ) ? (string) $metadata[ $key ] : $default;
		};

		switch ( $action ) {
			case 'project_created':
				/* translators: %s: person name. */
				return sprintf( __( '%s created the project', 'replicaforge' ), $who );
			case 'project_updated':
				return sprintf(
					/* translators: 1: person, 2: field name. */
					__( '%1$s updated %2$s', 'replicaforge' ),
					$who,
					$detail( 'field', __( 'the project', 'replicaforge' ) )
				);
			case 'project_archived':
				return sprintf( __( '%s archived the project', 'replicaforge' ), $who );
			case 'project_restored':
				return sprintf( __( '%s restored the project', 'replicaforge' ), $who );
			case 'member_added':
				return sprintf(
					/* translators: 1: person, 2: member name. */
					__( '%1$s added %2$s to the project', 'replicaforge' ),
					$who,
					$detail( 'name', __( 'a member', 'replicaforge' ) )
				);
			case 'member_removed':
				return sprintf(
					/* translators: 1: person, 2: member name. */
					__( '%1$s removed %2$s from the project', 'replicaforge' ),
					$who,
					$detail( 'name', __( 'a member', 'replicaforge' ) )
				);
			case 'member_role_changed':
				return sprintf(
					/* translators: 1: person, 2: member name, 3: new role. */
					__( '%1$s changed %2$s to %3$s', 'replicaforge' ),
					$who,
					$detail( 'name', __( 'a member', 'replicaforge' ) ),
					$detail( 'role', '' )
				);
			case 'analysis_started':
				return sprintf( __( '%s started analysis', 'replicaforge' ), $who );
			case 'analysis_completed':
				return sprintf(
					/* translators: 1: person, 2: page count. */
					__( '%1$s finished analysing %2$s', 'replicaforge' ),
					$who,
					$detail( 'pages', __( 'the site', 'replicaforge' ) )
				);
			case 'generation_started':
				return sprintf( __( '%s started generating', 'replicaforge' ), $who );
			case 'generation_completed':
				return sprintf(
					/* translators: 1: person, 2: page count, 3: version. */
					__( '%1$s generated %2$s as version %3$s', 'replicaforge' ),
					$who,
					$detail( 'pages', __( 'the site', 'replicaforge' ) ),
					$detail( 'version', '' )
				);
			case 'validation_completed':
				return sprintf(
					/* translators: 1: person, 2: score. */
					__( '%1$s completed validation with a score of %2$s', 'replicaforge' ),
					$who,
					$detail( 'score', '' )
				);
			case 'correction_applied':
				return sprintf(
					/* translators: 1: person, 2: count. */
					__( '%1$s applied %2$s', 'replicaforge' ),
					$who,
					$detail( 'count', __( 'a correction', 'replicaforge' ) )
				);
			case 'correction_failed':
				return sprintf( __( '%s reported a correction failure', 'replicaforge' ), $who );
			case 'sync_started':
				return sprintf( __( '%s started a synchronisation', 'replicaforge' ), $who );
			case 'sync_completed':
				return sprintf(
					/* translators: 1: person, 2: conflict count. */
					__( '%1$s finished synchronising, with %2$s', 'replicaforge' ),
					$who,
					$detail( 'conflicts', __( 'no conflicts', 'replicaforge' ) )
				);
			case 'content_mapped':
				return sprintf(
					/* translators: 1: person, 2: mapping count. */
					__( '%1$s mapped %2$s', 'replicaforge' ),
					$who,
					$detail( 'mappings', __( 'the content', 'replicaforge' ) )
				);
			case 'content_applied':
				return sprintf(
					/* translators: 1: person, 2: field count. */
					__( '%1$s applied %2$s', 'replicaforge' ),
					$who,
					$detail( 'fields', __( 'the content', 'replicaforge' ) )
				);
			case 'review_requested':
				return sprintf(
					/* translators: 1: person, 2: reviewer name. */
					__( '%1$s asked %2$s to review', 'replicaforge' ),
					$who,
					$detail( 'reviewer', __( 'a reviewer', 'replicaforge' ) )
				);
			case 'review_started':
				return sprintf( __( '%s began reviewing', 'replicaforge' ), $who );
			case 'review_completed':
				return sprintf(
					/* translators: 1: person, 2: version. */
					__( '%1$s completed the review of version %2$s', 'replicaforge' ),
					$who,
					$detail( 'version', '' )
				);
			case 'comment_added':
				return sprintf( __( '%s commented', 'replicaforge' ), $who );
			case 'comment_resolved':
				return sprintf( __( '%s resolved a comment', 'replicaforge' ), $who );
			case 'issue_created':
				return sprintf(
					/* translators: %s: person name. */
					__( '%s raised an issue', 'replicaforge' ),
					$who
				);
			case 'issue_resolved':
				return sprintf( __( '%s resolved an issue', 'replicaforge' ), $who );
			case 'task_created':
				return sprintf(
					/* translators: 1: person, 2: task title. */
					__( '%1$s created the task %2$s', 'replicaforge' ),
					$who,
					$detail( 'title', '' )
				);
			case 'task_assigned':
				return sprintf(
					/* translators: 1: person, 2: assignee name. */
					__( '%1$s assigned a task to %2$s', 'replicaforge' ),
					$who,
					$detail( 'assignee', __( 'a colleague', 'replicaforge' ) )
				);
			case 'task_completed':
				return sprintf( __( '%s completed a task', 'replicaforge' ), $who );
			case 'approval_granted':
				return sprintf(
					/* translators: 1: person, 2: version. */
					__( '%1$s approved version %2$s', 'replicaforge' ),
					$who,
					$detail( 'version', '' )
				);
			case 'changes_requested':
				return sprintf(
					/* translators: 1: person, 2: version. */
					__( '%1$s requested changes on version %2$s', 'replicaforge' ),
					$who,
					$detail( 'version', '' )
				);
			case 'version_created':
				return sprintf(
					/* translators: 1: person, 2: version. */
					__( '%1$s created version %2$s', 'replicaforge' ),
					$who,
					$detail( 'version', '' )
				);
			case 'rollback_performed':
				return sprintf(
					/* translators: 1: person, 2: version rolled back to. */
					__( '%1$s rolled back to version %2$s', 'replicaforge' ),
					$who,
					$detail( 'version', '' )
				);
			case 'export_created':
				return sprintf( __( '%s exported the project', 'replicaforge' ), $who );
			case 'client_created':
				return sprintf(
					/* translators: 1: person, 2: client name. */
					__( '%1$s added the client %2$s', 'replicaforge' ),
					$who,
					$detail( 'name', '' )
				);
			case 'client_updated':
				return sprintf(
					/* translators: 1: person, 2: client name. */
					__( '%1$s updated the client %2$s', 'replicaforge' ),
					$who,
					$detail( 'name', '' )
				);
			default:
				// A declared event with no sentence. Kept readable rather than blank, so a
				// timeline row is never an empty box.
				return sprintf(
					/* translators: %s: action name. */
					__( '%s performed %s', 'replicaforge' ),
					$who,
					str_replace( '_', ' ', (string) $event['action'] )
				);
		}
	}

	/**
	 * Return an actor's display name.
	 *
	 * @param int $actor_id Actor id.
	 * @return string
	 */
	private function actor_name( $actor_id ) {
		$actor_id = (int) $actor_id;
		if ( $actor_id < 1 ) {
			// Not "Anonymous". A missing name would read as an unidentified person when
			// it means "ReplicaForge did this, on a timer, because a job ran".
			return '';
		}
		$user = get_userdata( $actor_id );
		return ( $user instanceof \WP_User ) ? substr( (string) $user->display_name, 0, 120 ) : '';
	}
}

/**
 * The security and audit log.
 *
 * Distinct from {@see Activity_Store}: different vocabulary, different retention, and a
 * request context that is stored as a hash rather than as data.
 */
final class Audit_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'audit';

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array( 'public_id', 'workspace_id', 'project_id', 'actor_id', 'action', 'target_type', 'target_id', 'context_hash', 'ip_hash', 'metadata', 'created_at' );
	}

	/**
	 * Return the storage type of each column.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'public_id'     => 'string',
			'workspace_id'  => 'string',
			'project_id'    => 'string',
			'actor_id'      => 'int',
			'action'        => 'line',
			'target_type'   => 'line',
			'target_id'     => 'string',
			'context_hash'  => 'string',
			'ip_hash'       => 'string',
			'metadata'      => 'json',
			'created_at'    => 'datetime',
		);
	}

	/**
	 * Return an audit trail.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function trail( $workspace_id, array $args = array() ) {
		$page                = $this->query( (string) $workspace_id, $args );
		$page['by_action']   = $this->group_counts( (string) $workspace_id, 'action' );
		return $page;
	}

	/**
	 * Return the audit events for one project.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function for_project( $workspace_id, $project_id, array $args = array() ) {
		return $this->query(
			(string) $workspace_id,
			array_merge( $args, array( 'project_id' => (string) $project_id ) )
		);
	}

	/**
	 * Record an audit event.
	 *
	 * @param string               $workspace_id Workspace id, or empty for a site-level event.
	 * @param string               $action       Event name.
	 * @param array<string, mixed> $context      Context.
	 * @param int                  $actor_id     Actor.
	 * @return array<string, mixed>|null
	 */
	public function record( $workspace_id, $action, array $context = array(), $actor_id = 0 ) {
		$action = (string) $action;

		if ( ! in_array( $action, Workspace_Limits::AUDIT_EVENTS, true ) || ! $this->ready() ) {
			return null;
		}

		$metadata = (array) ( $context['metadata'] ?? array() );

		return $this->insert(
			array(
				'public_id'    => $this->new_public_id(),
				// A site-level event has no workspace. The column is nullable for exactly
				// that reason: an activation or a migration is not attributable to any one
				// agency, and forcing a workspace id onto it would be a false claim.
				'workspace_id' => (string) $workspace_id,
				'project_id'   => substr( (string) ( $context['project_id'] ?? '' ), 0, 64 ),
				'actor_id'     => max( 0, (int) $actor_id ),
				'action'       => $action,
				'target_type'  => substr( (string) ( $context['target_type'] ?? '' ), 0, 32 ),
				'target_id'    => substr( (string) ( $context['target_id'] ?? '' ), 0, 64 ),
				// A hash of the request context, not the context. §25 forbids logging
				// tokens, cookies and credentials, and the only reliable way to keep one
				// out is to never build the line from the request in the first place.
				'context_hash' => $this->context_hash(),
				// Hashed for the same reason, and so that a GDPR erasure request can find
				// the rows belonging to an address without the log itself holding one.
				'ip_hash'      => $this->ip_hash(),
				'metadata'     => $metadata,
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Return a stable hash of the current request context.
	 *
	 * @return string
	 */
	private function context_hash() {
		$parts = array( (string) get_current_user_id() );
		foreach ( array( 'HTTP_HOST', 'REQUEST_METHOD', 'SERVER_PROTOCOL' ) as $key ) {
			$parts[] = isset( $_SERVER[ $key ] ) ? (string) wp_unslash( $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never stored raw.
		}
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Return a hash of the request address.
	 *
	 * @return string
	 */
	private function ip_hash() {
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never stored raw.
		return ( '' === $raw ) ? '' : hash( 'sha256', $raw );
	}

	/**
	 * Delete events older than a retention period.
	 *
	 * @param int $ttl Seconds.
	 * @return int Rows removed.
	 */
	public function prune( $ttl ) {
		global $wpdb;
		if ( ! $this->ready() ) {
			return 0;
		}
		$table = $this->table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				gmdate( 'Y-m-d H:i:s', time() - (int) $ttl )
			)
		);
	}
}
