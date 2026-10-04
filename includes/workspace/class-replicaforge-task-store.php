<?php
/**
 * Phase 15: the task store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight project tasks.
 *
 * ### Deliberately not a project-management system
 *
 * §22 says "Tasks must remain lightweight. Do not build a full project-management SaaS
 * unrelated to ReplicaForge." So a task is a title, a status, a priority, an assignee, a
 * due date, and a link back to whatever produced it. There is no dependency graph, no
 * subtask nesting, no estimate-versus-actual, no sprint, no Gantt, and no custom fields.
 *
 * The reason a task exists at all is to carry an *observation* to a person: "mobile hero
 * spacing differs", "the client's contact asked for a different headline". Anything a task
 * cannot trace to a page, a component, a validation difference, a comment or a content
 * conflict is not something ReplicaForge should be tracking, and
 * {@see self::from_difference()} refuses to create one.
 *
 * ### One write, one event
 *
 * {@see Workspace_Service} records the activity event and notifies the assignee. Those are
 * separate steps on purpose: a task that saved but produced no activity entry is a bug in
 * one place, and a task whose activity entry was written but which did not save is a bug in
 * the other. Doing both in one transaction is not available, so the *order* is fixed —
 * save first, then record, then notify — and a failure at either later step is reported
 * rather than swallowed.
 */
final class Task_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'tasks';

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array( 'public_id', 'workspace_id', 'project_id', 'title', 'description', 'status', 'priority', 'promoted_from', 'assignee_id', 'creator_id', 'due_date', 'linked_issue_id', 'linked_page_id', 'linked_component_id', 'created_at', 'updated_at' );
	}

	/**
	 * Return the storage type of each column.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'public_id'            => 'string',
			'workspace_id'         => 'string',
			'project_id'           => 'string',
			'title'                => 'line',
			'description'          => 'text',
			'status'               => 'line',
			'priority'             => 'line',
			// Which phase a promoted task came from. A column rather than a value derived
			// from the evidence text, because the evidence is prose and the provenance is
			// not - and a task that cannot say where it was raised from is a task somebody
			// has to investigate from scratch.
			'promoted_from'        => 'line',
			'assignee_id'          => 'int',
			'creator_id'           => 'int',
			'due_date'             => 'datetime',
			'linked_issue_id'      => 'string',
			'linked_page_id'       => 'int',
			'linked_component_id'  => 'string',
			'created_at'           => 'datetime',
			'updated_at'           => 'datetime',
		);
	}

	/**
	 * Return the columns free text may search.
	 *
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'title', 'description' );
	}

	/**
	 * Return a page of a project's tasks.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id, or empty for all in the workspace.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function tasks( $workspace_id, $project_id = '', array $args = array() ) {
		if ( '' !== (string) $project_id ) {
			$args['project_id'] = (string) $project_id;
		}
		$page          = $this->query( (string) $workspace_id, $args );
		$page['by_status']   = $this->group_counts( (string) $workspace_id, 'status', ( '' !== (string) $project_id ) ? array( 'project_id' => (string) $project_id ) : array() );
		$page['by_priority'] = $this->group_counts( (string) $workspace_id, 'priority', ( '' !== (string) $project_id ) ? array( 'project_id' => (string) $project_id ) : array() );
		return $page;
	}

	/**
	 * Return a task.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Task public id.
	 * @return array<string, mixed>|null
	 */
	public function get( $workspace_id, $public_id ) {
		return $this->find( (string) $workspace_id, (string) $public_id );
	}

	/**
	 * Create a task.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $project_id   Project id.
	 * @param array<string, mixed> $data         Task data.
	 * @return array<string, mixed>|null
	 */
	public function create( $workspace_id, $project_id, array $data ) {
		$workspace_id = (string) $workspace_id;
		$project_id   = (string) $project_id;
		if ( '' === $workspace_id || '' === $project_id || ! $this->ready() ) {
			return null;
		}

		$title = trim( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			// A task with no title is not actionable in an assignee's list, and a task list
			// full of untitled rows is worse than an absent task.
			return null;
		}

		return $this->insert(
			array(
				'public_id'           => $this->new_public_id(),
				'workspace_id'        => $workspace_id,
				'project_id'          => $project_id,
				'title'               => substr( $title, 0, 200 ),
				'description'         => (string) ( $data['description'] ?? '' ),
				'status'              => $this->clean_status( (string) ( $data['status'] ?? 'todo' ) ),
				'priority'            => $this->clean_priority( (string) ( $data['priority'] ?? 'medium' ) ),
				'assignee_id'         => max( 0, (int) ( $data['assignee_id'] ?? 0 ) ),
				'creator_id'          => (int) ( $data['creator_id'] ?? get_current_user_id() ),
				'due_date'            => $this->date( $data['due_date'] ?? '' ),
				'linked_issue_id'     => (string) ( $data['linked_issue_id'] ?? '' ),
				'linked_page_id'      => (int) ( $data['linked_page_id'] ?? 0 ),
				'linked_component_id' => (string) ( $data['linked_component_id'] ?? '' ),
				// Validated against the same source vocabulary `from_difference()` checks,
				// so a caller cannot write a provenance that is not a declared one. An
				// unrecognised value is dropped rather than stored, which means a task
				// created by hand reports no phase rather than a phase that is not real.
				'promoted_from'       => $this->clean_source( (string) ( $data['promoted_from'] ?? '' ) ),
				'created_at'          => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Update a task.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $public_id    Task public id.
	 * @param array<string, mixed> $changes     Changes.
	 * @return array<string, mixed>|null
	 */
	public function update( $workspace_id, $public_id, array $changes ) {
		$clean = array();
		foreach ( array( 'title', 'description', 'due_date', 'assignee_id', 'linked_page_id', 'linked_component_id', 'linked_issue_id' ) as $column ) {
			if ( array_key_exists( $column, $changes ) ) {
				$clean[ $column ] = $changes[ $column ];
			}
		}
		if ( array_key_exists( 'status', $changes ) ) {
			$clean['status'] = $this->clean_status( (string) $changes['status'] );
		}
		if ( array_key_exists( 'priority', $changes ) ) {
			$clean['priority'] = $this->clean_priority( (string) $changes['priority'] );
		}
		if ( array() === $clean ) {
			return null;
		}
		$this->update_row( (string) $public_id, $clean );
		return $this->get( $workspace_id, $public_id );
	}

	/**
	 * Delete a task.
	 *
	 * The one hard delete in the collaboration layer that removes something a project
	 * produced. It is allowed because a task is a to-do, not a record of work: the
	 * activity event that said the task was created, and the issue or validation
	 * difference that produced it, both remain. A task that was completed and then
	 * deleted leaves its `task_completed` event, which is the history that matters.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Task public id.
	 * @return bool
	 */
	public function delete( $workspace_id, $public_id ) {
		$task = $this->get( $workspace_id, $public_id );
		if ( null === $task ) {
			return false;
		}
		return $this->delete_rows( array( 'workspace_id' => (string) $workspace_id, 'public_id' => (string) $public_id ) ) > 0;
	}

	/**
	 * Return the tasks assigned to a user across a workspace, for the dashboard.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @param int    $limit        Maximum.
	 * @return array<int, array<string, mixed>>
	 */
	public function assigned_to( $workspace_id, $user_id, $limit = 10 ) {
		$page = $this->query(
			(string) $workspace_id,
			array( 'assignee_id' => (int) $user_id, 'per_page' => $limit )
		);
		return $page['items'];
	}

	/**
	 * Return the count of tasks a user has open, for the dashboard.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @return int
	 */
	public function open_count_for( $workspace_id, $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! $this->ready() ) {
			return 0;
		}
		$table = $this->table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE workspace_id = %s AND assignee_id = %d AND status NOT IN ('done')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal; the literals are constants.
				(string) $workspace_id,
				$user_id
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * §23: promotion from another phase
	 * ------------------------------------------------------------------ */

	/**
	 * Create a task from a validation difference, comment, or conflict.
	 *
	 * ### Why a promotion needs a reference
	 *
	 * §23 says "Automatically include evidence and references." A task with no reference is
	 * a note, and a note cannot be re-derived — when someone asks "why does this task
	 * exist", the answer has to be a stored pointer to the difference, not a recollection.
	 *
	 * So `source` is required. A caller that wants a free-standing task uses `create()`
	 * directly, which is the honest way to say "this is not derived from anything".
	 *
	 * The reference is stored as text, not as a foreign key, because the thing being
	 * referenced lives in an *option* — Phase 5 validations, Phase 9 sync conflicts, Phase
	 * 14 content mappings are all option-stored. There is no row to point a key at, and a
	 * dangling integer would be a claim of a relationship that cannot be verified.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $project_id   Project id.
	 * @param array<string, mixed> $difference   The difference, comment or conflict.
	 * @return array<string, mixed>|null
	 */
	public function from_difference( $workspace_id, $project_id, array $difference ) {
		$source = (string) ( $difference['source'] ?? '' );
		if ( '' === $source ) {
			// Refused rather than defaulting to "manual". A task that pretends to be
			// derived from something it is not derived from is exactly the fabricated
			// provenance Phase 14 spent a whole phase eliminating.
			return null;
		}
		if ( ! array_key_exists( $source, Workspace_Limits::ISSUE_SOURCES ) ) {
			return null;
		}

		$title = trim( (string) ( $difference['title'] ?? '' ) );
		if ( '' === $title ) {
			return null;
		}

		$evidence = $this->evidence_text( $difference );

		$task = $this->create(
			$workspace_id,
			$project_id,
			array(
				'title'               => $title,
				'description'         => trim( (string) ( $difference['description'] ?? '' ) . "\n\n" . $evidence ),
				'priority'            => (string) ( $difference['priority'] ?? $this->priority_from_severity( (string) ( $difference['severity'] ?? '' ) ) ),
				'assignee_id'         => (int) ( $difference['assignee_id'] ?? 0 ),
				'creator_id'          => (int) ( $difference['creator_id'] ?? get_current_user_id() ),
				'linked_issue_id'     => (string) ( $difference['issue_id'] ?? '' ),
				'linked_page_id'      => (int) ( $difference['page_id'] ?? 0 ),
				'linked_component_id' => (string) ( $difference['component_id'] ?? '' ),
				// Passed into the insert rather than assigned to the returned array
				// afterwards. Assigning it after the fact produced a task that reported
				// its provenance for the rest of the request and then lost it - because
				// nothing had ever written it to the row. The evidence prose says what
				// the difference was; this says which phase raised it, and the two are
				// not the same claim.
				'promoted_from'       => $source,
			)
		);

		return $task;
	}

	/**
	 * Return the evidence block appended to a promoted task's description.
	 *
	 * @param array<string, mixed> $difference Difference.
	 * @return string
	 */
	private function evidence_text( array $difference ) {
		$lines = array();
		$lines[] = '—';
		$lines[] = __( 'Raised from:', 'replicaforge' ) . ' ' . (string) ( Workspace_Limits::ISSUE_SOURCES[ (string) ( $difference['source'] ?? '' ) ] ?? '' );

		/*
		 * The keys are the ones a validation difference, comment or conflict actually
		 * carries - `page_id`, `component_id`, `section_id` - and not the shorter `page`
		 * and `component`. An earlier version used the shorter names, so every lookup
		 * found nothing and the evidence block contained only its two header lines: a
		 * promoted task that says where it came from but not where on the page, which is
		 * the half that lets somebody start work without reopening the report.
		 *
		 * An absent key is skipped rather than rendered empty, so a difference with no
		 * section does not produce a dangling "Section:".
		 */
		$places = array(
			'page_id'      => __( 'Page', 'replicaforge' ),
			'section_id'   => __( 'Section', 'replicaforge' ),
			'component_id' => __( 'Component', 'replicaforge' ),
			'viewport'     => __( 'Viewport', 'replicaforge' ),
		);
		foreach ( $places as $key => $label ) {
			if ( ! empty( $difference[ $key ] ) ) {
				$lines[] = $label . ': ' . (string) $difference[ $key ];
			}
		}
		foreach ( array( 'validation_id', 'difference_id', 'comment_id', 'mapping_id', 'element_id' ) as $key ) {
			if ( ! empty( $difference[ $key ] ) ) {
				$lines[] = $key . ': ' . (string) $difference[ $key ];
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Return a task priority from a Phase 5 severity.
	 *
	 * Phase 5's five severities have no direct equivalent in Phase 15's four priorities, and
	 * a *separate* severity scale would be the second severity system §21 forbids. So the
	 * mapping is explicit and lossy in one direction only: `critical` and `major` both
	 * become `urgent`, because a task that is merely "high" for a critical difference
	 * understates it, whereas downgrading a `moderate` difference to `medium` loses
	 * nothing actionable.
	 *
	 * @param string $severity Phase 5 severity.
	 * @return string
	 */
	public static function priority_from_severity( $severity ) {
		$map = array(
			'critical'      => 'urgent',
			'major'         => 'urgent',
			'moderate'      => 'medium',
			'minor'         => 'low',
			'informational' => 'low',
		);
		$severity = (string) $severity;
		return isset( $map[ $severity ] ) ? $map[ $severity ] : 'medium';
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Validate a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function clean_status( $status ) {
		$status = strtolower( trim( (string) $status ) );
		return array_key_exists( $status, Workspace_Limits::TASK_STATUSES ) ? $status : 'todo';
	}

	/**
	 * Validate a priority.
	 *
	 * @param string $priority Priority.
	 * @return string
	 */
	private function clean_priority( $priority ) {
		$priority = strtolower( trim( (string) $priority ) );
		return Workspace_Limits::is_priority( $priority ) ? $priority : 'medium';
	}

	/**
		* Return a declared promotion source, or an empty string.
		*
		* Deliberately *not* the same fallback as `clean_priority()`, which returns the
		* default. A priority with no value becomes medium, because a task has to have one
		* and medium is the safe answer. A provenance with no value has to stay empty,
		* because the only alternative is to invent a phase - and a task that claims it came
		* from a validation when it did not is worse than one that admits no origin.
		*
		* @param string $source Candidate.
		* @return string
	*/
	private function clean_source( $source ) {
		$source = strtolower( trim( (string) $source ) );
		return array_key_exists( $source, Workspace_Limits::ISSUE_SOURCES ) ? $source : '';
	}

	/**
	 * Return a `Y-m-d H:i:s` date, or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private function date( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$time = strtotime( (string) $value );
		return ( false === $time ) ? null : gmdate( 'Y-m-d H:i:s', $time );
	}
}
