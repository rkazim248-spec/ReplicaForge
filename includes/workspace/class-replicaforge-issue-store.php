<?php
/**
 * Phase 15: the review issue store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Issues promoted from validation, sync, content and correction work.
 *
 * ### Severity is Phase 5's, not a new scale
 *
 * §21 says "Reuse Phase 5 severity definitions. Do not create a second severity system",
 * so {@see Workspace_Limits::severities()} returns `Validation_Limits::SEVERITIES` and
 * this class never declares a list of its own.
 *
 * The consequence is that an issue promoted from a validation difference keeps the
 * severity the difference was found with. A `critical` difference promoted to an issue
 * stays `critical`; it is not re-classified by whatever this phase happens to think
 * "moderate" means. That is what makes the promotion mean something — the number on the
 * issue is the number the validator computed.
 *
 * ### `source` is required, and the reference is text
 *
 * {@see Task_Store::from_difference()} refuses to promote anything without a `source`, and
 * this class refuses the same. The reason is §23's "automatically include evidence and
 * references": an issue that cannot say where it came from cannot be re-derived, and the
 * person who inherits it has to be able to find the difference it describes.
 *
 * The reference is stored as text rather than as a foreign key because the things being
 * referenced live in *options* — Phase 5 validations, Phase 9 sync conflicts, Phase 14
 * content mappings. There is no row for a key to point at, and a dangling integer would be
 * a claim of a relationship that cannot be checked.
 *
 * ### Closing an issue does not close the underlying difference
 *
 * Marking an issue `resolved` records that a person dealt with it. It does not mark the
 * validation difference resolved, and it does not re-run a validation. Those are separate
 * facts with separate owners, and an issue tracker that silently reconciled them would
 * report a project as fixed when only a box was ticked.
 */
final class Issue_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'issues';

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id', 'workspace_id', 'project_id', 'title', 'description',
			'severity', 'category', 'status', 'assignee_id', 'reporter_id', 'source',
			'source_reference', 'generated_reference', 'validation_id', 'page_id',
			'component_id', 'section_id', 'created_at', 'updated_at', 'resolved_at',
		);
	}

	/**
	 * Return the storage type of each column.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'public_id'           => 'string',
			'workspace_id'        => 'string',
			'project_id'          => 'string',
			'title'               => 'line',
			'description'         => 'text',
			'severity'            => 'line',
			'category'            => 'line',
			'status'              => 'line',
			'assignee_id'         => 'int',
			'reporter_id'         => 'int',
			'source'              => 'line',
			'source_reference'    => 'json',
			'generated_reference' => 'json',
			'validation_id'       => 'string',
			'page_id'             => 'int',
			'component_id'        => 'string',
			'section_id'          => 'string',
			'created_at'          => 'datetime',
			'updated_at'          => 'datetime',
			'resolved_at'         => 'datetime',
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
	 * Return the severity an issue may carry, read from Phase 5.
	 *
	 * @return array<int, string>
	 */
	public static function severity_scale() {
		return Workspace_Limits::severities();
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return an issue.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Issue public id.
	 * @return array<string, mixed>|null
	 */
	public function get( $workspace_id, $public_id ) {
		return $this->find( (string) $workspace_id, (string) $public_id );
	}

	/**
	 * Return a page of issues on a project.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id, or empty for all in the workspace.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function issues( $workspace_id, $project_id = '', array $args = array() ) {
		if ( '' !== (string) $project_id ) {
			$args['project_id'] = (string) $project_id;
		}
		$page = $this->query( (string) $workspace_id, $args );

		$scope = ( '' !== (string) $project_id ) ? array( 'project_id' => (string) $project_id ) : array();
		// The severity histogram is filtered to the *open* issues. A chart of "how many
		// critical issues has this project had" is a measure of how good the project was,
		// and the one an agency actually wants is how many are outstanding right now.
		$open                      = array_merge( $scope, array( 'status' => 'open' ) );
		$page['by_severity_open']  = $this->group_counts( (string) $workspace_id, 'severity', $open );
		$page['by_status']         = $this->group_counts( (string) $workspace_id, 'status', $scope );

		return $page;
	}

	/**
	 * Return the open issues for a workspace, most severe first.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $limit        Maximum.
	 * @return array<int, array<string, mixed>>
	 */
	public function open( $workspace_id, $limit = 10 ) {
		$page  = $this->query( (string) $workspace_id, array( 'status' => 'open', 'per_page' => $limit, 'order_by' => 'severity' ) );
		$order = array_flip( self::severity_scale() );
		// `severity` is a word, not a number, so it sorts alphabetically and `wont_fix`
		// would come first. The order Phase 5 defines is re-applied here, because the
		// dashboard's first row should be the one that matters.
		usort(
			$page['items'],
			static function ( $a, $b ) use ( $order ) {
				$left  = $order[ (string) $a['severity'] ] ?? 99;
				$right = $order[ (string) $b['severity'] ] ?? 99;
				return $left <=> $right;
			}
		);
		return $page['items'];
	}

	/**
	 * Return the open issue count for a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return int
	 */
	public function open_count( $workspace_id ) {
		return $this->count_where( (string) $workspace_id, array( 'status' => 'open' ) );
	}

	/**
	 * Return the open issue count on one project.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @return int
	 */
	public function open_count_for_project( $workspace_id, $project_id ) {
		return $this->count_where( (string) $workspace_id, array( 'status' => 'open', 'project_id' => (string) $project_id ) );
	}

	/**
	 * Return the issues assigned to a user.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @param int    $limit        Maximum.
	 * @return array<int, array<string, mixed>>
	 */
	public function assigned_to( $workspace_id, $user_id, $limit = 10 ) {
		$page = $this->query( (string) $workspace_id, array( 'assignee_id' => (int) $user_id, 'per_page' => $limit ) );
		return $page['items'];
	}

	/**
	 * Return the issues a user has open.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @return int
	 */
	public function open_count_for_user( $workspace_id, $user_id ) {
		return $this->count_where( (string) $workspace_id, array( 'assignee_id' => (int) $user_id, 'status' => 'open' ) );
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Raise an issue.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $project_id   Project id.
	 * @param array<string, mixed> $data         Issue data.
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
			return null;
		}

		$source = (string) ( $data['source'] ?? '' );
		if ( ! array_key_exists( $source, Workspace_Limits::ISSUE_SOURCES ) ) {
			// Refused rather than defaulted to `manual`. An issue that claims to be manual
			// when it was promoted from a validation difference would hide exactly the
			// provenance this phase is for.
			return null;
		}

		return $this->insert(
			array(
				'public_id'           => $this->new_public_id(),
				'workspace_id'        => $workspace_id,
				'project_id'          => $project_id,
				'title'               => substr( $title, 0, 200 ),
				'description'         => (string) ( $data['description'] ?? '' ),
				// Validated against Phase 5's own list. A severity this phase invented
				// would be the second severity system §21 rules out.
				'severity'            => $this->clean_severity( $data['severity'] ?? '' ),
				'category'            => substr( trim( (string) ( $data['category'] ?? '' ) ), 0, 60 ),
				'status'              => 'open',
				'assignee_id'         => max( 0, (int) ( $data['assignee_id'] ?? 0 ) ),
				'reporter_id'         => (int) ( $data['reporter_id'] ?? get_current_user_id() ),
				'source'              => $source,
				'source_reference'    => (array) ( $data['source_reference'] ?? array() ),
				'generated_reference' => (array) ( $data['generated_reference'] ?? array() ),
				'validation_id'       => (string) ( $data['validation_id'] ?? '' ),
				'page_id'             => max( 0, (int) ( $data['page_id'] ?? 0 ) ),
				'component_id'        => substr( (string) ( $data['component_id'] ?? '' ), 0, 64 ),
				'section_id'          => substr( (string) ( $data['section_id'] ?? '' ), 0, 64 ),
				'created_at'          => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Promote a validation difference, comment or conflict into an issue.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $project_id   Project id.
	 * @param array<string, mixed> $difference   The difference, comment or conflict.
	 * @return array<string, mixed>|null
	 */
	public function from_difference( $workspace_id, $project_id, array $difference ) {
		$source = (string) ( $difference['source'] ?? '' );
		if ( ! array_key_exists( $source, Workspace_Limits::ISSUE_SOURCES ) ) {
			return null;
		}

		return $this->create(
			$workspace_id,
			$project_id,
			array(
				'title'         => (string) ( $difference['title'] ?? '' ),
				'description'   => (string) ( $difference['description'] ?? '' ),
				'severity'      => (string) ( $difference['severity'] ?? '' ),
				'category'      => (string) ( $difference['category'] ?? '' ),
				'assignee_id'   => (int) ( $difference['assignee_id'] ?? 0 ),
				'reporter_id'   => (int) ( $difference['reporter_id'] ?? get_current_user_id() ),
				// The whole source record, redacted. A difference can carry a source URL
				// or a page title that should not sit in an issue's metadata unredacted,
				// and `Data_Redactor` is the same filter Phase 14 sends to an AI provider.
				'source_reference' => $this->reference( $difference ),
				'validation_id' => (string) ( $difference['validation_id'] ?? '' ),
				'page_id'       => (int) ( $difference['page_id'] ?? 0 ),
				'component_id'  => (string) ( $difference['component_id'] ?? '' ),
				'section_id'    => (string) ( $difference['section_id'] ?? '' ),
				'source'        => $source,
			)
		);
	}

	/**
	 * Update an issue.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $public_id    Issue public id.
	 * @param array<string, mixed> $changes     Changes.
	 * @return array<string, mixed>|null
	 */
	public function update( $workspace_id, $public_id, array $changes ) {
		$issue = $this->get( $workspace_id, $public_id );
		if ( null === $issue ) {
			return null;
		}

		$clean = array();
		foreach ( array( 'title', 'description', 'category', 'assignee_id' ) as $column ) {
			if ( array_key_exists( $column, $changes ) ) {
				$clean[ $column ] = $changes[ $column ];
			}
		}
		if ( array_key_exists( 'severity', $changes ) ) {
			$clean['severity'] = $this->clean_severity( $changes['severity'] );
		}
		if ( array_key_exists( 'status', $changes ) ) {
			$status = (string) $changes['status'];
			if ( ! in_array( $status, Workspace_Limits::ISSUE_STATUSES, true ) ) {
				return null;
			}
			// A resolved issue records when and by whom; an unresolved one clears both, so
			// a reopened issue does not carry a stale resolution into its next life.
			$clean['status'] = $status;
			if ( in_array( $status, array( 'resolved', 'wont_fix' ), true ) ) {
				$clean['resolved_at'] = gmdate( 'Y-m-d H:i:s' );
			} else {
				$clean['resolved_at'] = null;
			}
		}
		if ( array() === $clean ) {
			return null;
		}

		$this->update_row( (string) $public_id, $clean );
		return $this->get( $workspace_id, $public_id );
	}

	/**
	 * Resolve an issue.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Issue public id.
	 * @return array<string, mixed>|null
	 */
	public function resolve( $workspace_id, $public_id ) {
		return $this->update( $workspace_id, $public_id, array( 'status' => 'resolved' ) );
	}

	/**
	 * Return the task raised from an issue, if any.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $issue_id    Issue public id.
	 * @return array<string, mixed>|null
	 */
	public function linked_task( $workspace_id, $issue_id ) {
		$page = ( new Task_Store() )->tasks(
			(string) $workspace_id,
			'',
			array( 'linked_issue_id' => (string) $issue_id, 'per_page' => 1 )
		);
		return $page['count'] > 0 ? $page['items'][0] : null;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Validate a severity against Phase 5's scale.
	 *
	 * @param mixed $severity Candidate.
	 * @return string
	 */
	private function clean_severity( $severity ) {
		$severity = strtolower( trim( (string) $severity ) );
		return in_array( $severity, self::severity_scale(), true ) ? $severity : 'moderate';
	}

	/**
	 * Return the source record, redacted and bounded.
	 *
	 * @param array<string, mixed> $difference Difference.
	 * @return array<string, mixed>
	 */
	private function reference( array $difference ) {
		/*
		 * Merged, not replaced.
		 *
		 * The caller's own `source_reference` comes first and is preserved, because it is the
		 * part that knows where *this particular* record came from - which review, which
		 * link, which job. Rebuilding the reference from the difference's top-level keys
		 * threw that away, so an issue raised through a client review link recorded nothing
		 * about the link and looked exactly like one raised in the app.
		 *
		 * The difference's own fields follow, and only where they do not overwrite. `source`
		 * is dropped because it is a separate column.
		 */
		$supplied = ( isset( $difference['source_reference'] ) && is_array( $difference['source_reference'] ) )
			? $difference['source_reference']
			: array();

		$fields = $difference;
		unset( $fields['source'], $fields['source_reference'] );

		$reference = array_merge( Data_Redactor::structure( $fields ), Data_Redactor::structure( $supplied ) );

		// The reference is evidence, so it keeps its identifiers; it is stored rather than
		// rendered, so the redaction matters less than it does in an export - but an issue is
		// read by more people than the person who raised it, so the filter still runs.
		return array_slice( $reference, 0, 40, true );
	}
}
