<?php
/**
 * Phase 15: the review store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Formal reviews, and the approval each one carries.
 *
 * ### Why a review cannot exist without a version
 *
 * §15 is emphatic: a reviewer must approve a *specific version*, and never "the project"
 * without linking the approval to a concrete version. The reason is not ceremony — it is
 * that the project keeps moving. An approval recorded against a project is ambiguous the
 * moment a correction is applied, and there is then no way to tell whether the client
 * approved the page they were shown or the page that replaced it.
 *
 * So `version_id` is `NOT NULL` in the schema, {@see self::create()} refuses a review
 * without one, and {@see self::approve()} records the version alongside the decision
 * rather than inferring which version was meant. `version_number` is stored too, because
 * a human approving "version 4" should not have to look up an opaque id, and because a
 * stored number that disagreed with the id would be a silent lie.
 *
 * The two are cross-checked: `create()` reads the version from the project's own history,
 * so a caller cannot claim version 4 while pointing at version 3.
 *
 * ### Internal and client reviews differ in what they expose
 *
 * Both are the same record. What differs is who may act and what the review page is allowed
 * to show. A `client` review renders a preview and a difference *summary*; it never
 * exposes the validation report's internals, the source crawl, or the private project
 * metadata §16 lists. That separation is enforced when the review is presented, by
 * {@see self::present()}, not at the point of writing.
 */
final class Review_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'reviews';

	/**
	 * The clients store, for resolving a client's name onto a review.
	 *
	 * @var Client_Store
	 */
	private $clients;

	/**
	 * Constructor.
	 *
	 * @param Collaboration_Schema|null $schema  Optional schema.
	 * @param Logger|null              $logger  Optional logger.
	 * @param Client_Store|null        $clients Optional clients store.
	 */
	public function __construct( $schema = null, $logger = null, $clients = null ) {
		parent::__construct( $schema, $logger );
		$this->clients = $clients instanceof Client_Store ? $clients : new Client_Store();
	}

	/**
	 * Return the columns that may be written.
	 *
	 * `link_hash` is writable because {@see Review_Link_Service} creates it. No public
	 * method here accepts it from a caller, so a review payload cannot arrive with a
	 * pre-chosen link hash.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id', 'workspace_id', 'project_id', 'version_id', 'version_number',
			'page_id', 'generation_id', 'validation_id', 'type', 'status',
			'reviewer_id', 'reviewer_email', 'link_hash', 'link_password_hash',
			'link_expires_at', 'link_revoked', 'link_attempts', 'link_suspended',
			'link_last_used_at', 'title', 'note', 'decision_note',
			'requested_by', 'requested_at', 'completed_at', 'created_at', 'updated_at',
		);
	}

	/**
	 * Return the storage type of each column.
	 *
	 * @return array<string, string>
	 */
	protected function column_types() {
		return array(
			'public_id'          => 'string',
			'workspace_id'       => 'string',
			'project_id'         => 'string',
			'version_id'         => 'string',
			'version_number'     => 'int',
			'page_id'            => 'int',
			'generation_id'      => 'string',
			'validation_id'      => 'string',
			'type'               => 'line',
			'status'             => 'line',
			'reviewer_id'        => 'int',
			'reviewer_email'     => 'email',
			'link_hash'          => 'string',
			'link_password_hash' => 'string',
			'link_expires_at'    => 'datetime',
			'link_revoked'       => 'bool',
			'link_attempts'      => 'int',
			'link_suspended'     => 'bool',
			'link_last_used_at'  => 'datetime',
			'title'              => 'line',
			'note'               => 'text',
			'decision_note'      => 'text',
			'requested_by'       => 'int',
			'requested_at'       => 'datetime',
			'completed_at'       => 'datetime',
			'created_at'         => 'datetime',
			'updated_at'         => 'datetime',
		);
	}

	/**
	 * Return the columns free text may search.
	 *
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'title', 'note' );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return a review.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Review public id.
	 * @return array<string, mixed>|null
	 */
	public function get( $workspace_id, $public_id ) {
		return $this->find( (string) $workspace_id, (string) $public_id );
	}

	/**
	 * Return a page of reviews.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param array  $args         Query arguments.
	 * @return array<string, mixed>
	 */
	public function reviews( $workspace_id, array $args = array() ) {
		$page = $this->query( (string) $workspace_id, $args );

		// Both histograms come from grouped queries, so a dashboard's "2 pending internal,
		// 1 approved client" is three queries rather than a query per status.
		$page['by_status'] = $this->group_counts( (string) $workspace_id, 'status' );
		$page['by_type']   = $this->group_counts( (string) $workspace_id, 'type' );

		if ( isset( $args['project_id'] ) && '' !== (string) $args['project_id'] ) {
			$page['by_status_for_project'] = $this->group_counts(
				(string) $workspace_id,
				'status',
				array( 'project_id' => (string) $args['project_id'] )
			);
		}

		return $page;
	}

	/**
	 * Return the open reviews for a workspace, for the dashboard.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $limit        Maximum.
	 * @return array<int, array<string, mixed>>
	 */
	public function open( $workspace_id, $limit = 10 ) {
		$page = $this->query(
			(string) $workspace_id,
			array(
				'statuses' => Workspace_Limits::OPEN_REVIEW_STATUSES,
				'per_page' => $limit,
			)
		);
		return $page['items'];
	}

	/**
	 * Return the count of open reviews in a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return int
	 */
	public function open_count( $workspace_id ) {
		return $this->count_where( (string) $workspace_id, array( 'statuses' => Workspace_Limits::OPEN_REVIEW_STATUSES ) );
	}

	/**
	 * Return the count of open reviews on one project.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @return int
	 */
	public function open_count_for_project( $workspace_id, $project_id ) {
		global $wpdb;
		if ( ! $this->ready() ) {
			return 0;
		}
		$table = $this->table();
		$open  = implode( ', ', array_fill( 0, count( Workspace_Limits::OPEN_REVIEW_STATUSES ), '%s' ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE workspace_id = %s AND project_id = %s AND status IN ({$open})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal; the placeholders are generated from a constant list.
				array_merge(
					array( (string) $workspace_id, (string) $project_id ),
					array_values( Workspace_Limits::OPEN_REVIEW_STATUSES )
				)
			)
		);
	}

	/**
	 * Return the review bound to a link token hash.
	 *
	 * The one cross-workspace read in this class, and it is safe: a review link's token
	 * *is* the capability, and the hash is a 256-bit value that only the holder of the
	 * token can produce. The returned row then tells the caller which workspace to scope
	 * everything else to.
	 *
	 * @param string $token_hash SHA-256 of the presented token.
	 * @return array<string, mixed>|null
	 */
	public function by_link_hash( $token_hash ) {
		$token_hash = (string) $token_hash;
		if ( '' === $token_hash || ! $this->ready() ) {
			return null;
		}
		return $this->find_where( '*', array( 'link_hash' => $token_hash ) );
	}

	/**
	 * Return the reviews a user is the named reviewer of.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param int    $user_id      User id.
	 * @param int    $limit        Maximum.
	 * @return array<int, array<string, mixed>>
	 */
	public function assigned_to( $workspace_id, $user_id, $limit = 10 ) {
		$page = $this->query( (string) $workspace_id, array( 'reviewer_id' => (int) $user_id, 'per_page' => $limit ) );
		return $page['items'];
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Request a review of one version of one project.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $project_id   Project id.
	 * @param array<string, mixed> $data         Review data.
	 * @return array<string, mixed>|null
	 */
	public function create( $workspace_id, $project_id, array $data ) {
		$workspace_id = (string) $workspace_id;
		$project_id   = (string) $project_id;

		if ( '' === $workspace_id || '' === $project_id || ! $this->ready() ) {
			return null;
		}

		$version = $this->resolve_version( $project_id, $data );
		if ( null === $version ) {
			// No resolvable version. Refused rather than stored with an empty
			// `version_id`, because the column is NOT NULL precisely so that this
			// situation cannot be represented - and a review row with a blank version is
			// exactly the "approved the project" approval §15 forbids.
			return null;
		}

		$type = (string) ( $data['type'] ?? 'internal' );
		if ( ! in_array( $type, Workspace_Limits::REVIEW_TYPES, true ) ) {
			$type = 'internal';
		}

		$reviewer_id    = max( 0, (int) ( $data['reviewer_id'] ?? 0 ) );
		$reviewer_email = sanitize_email( (string) ( $data['reviewer_email'] ?? '' ) );

		if ( 0 === $reviewer_id && '' === $reviewer_email ) {
			// A review with nobody to perform it is a status badge, not a review.
			return null;
		}

		if ( 0 === $reviewer_id && '' !== $reviewer_email ) {
			$user = get_user_by( 'email', $reviewer_email );
			if ( $user instanceof \WP_User ) {
				$reviewer_id = (int) $user->ID;
			}
		}

		$title = trim( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = sprintf(
				/* translators: 1: review type, 2: version number. */
				__( '%1$s review of version %2$s', 'replicaforge' ),
				( 'client' === $type ) ? __( 'Client', 'replicaforge' ) : __( 'Internal', 'replicaforge' ),
				(string) $version['version_number']
			);
		}

		return $this->insert(
			array(
				'public_id'      => $this->new_public_id(),
				'workspace_id'   => $workspace_id,
				'project_id'     => $project_id,
				// The version is resolved from the project's own history rather than taken
				// on trust, so a caller cannot claim "version 4" while pointing at
				// version 3 and have both recorded.
				'version_id'     => (string) $version['version_id'],
				'version_number' => (int) $version['version_number'],
				'page_id'        => max( 0, (int) ( $data['page_id'] ?? 0 ) ),
				'generation_id'  => (string) ( $data['generation_id'] ?? $version['generation_id'] ),
				'validation_id'  => (string) ( $data['validation_id'] ?? $version['validation_id'] ),
				'type'           => $type,
				'status'         => 'pending',
				'reviewer_id'    => $reviewer_id,
				'reviewer_email' => $reviewer_email,
				'title'          => substr( $title, 0, 120 ),
				'note'           => (string) ( $data['note'] ?? '' ),
				'requested_by'   => (int) ( $data['requested_by'] ?? get_current_user_id() ),
				'requested_at'   => gmdate( 'Y-m-d H:i:s' ),
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Update the mutable parts of a review.
	 *
	 * ### What this will and will not touch
	 *
	 * It will not touch `version_id`, `version_number`, `type` or `project_id`. A review is
	 * bound to the version it was created for, and section 15 exists precisely to stop an
	 * approval becoming ambiguous: if the version could be repointed after the fact, "the
	 * client approved version 4" could quietly become "the client approved version 5". The
	 * only way to review a different version is a new review.
	 *
	 * It will not touch `status` either. Status has its own transition table in
	 * {@see self::transition()} - approve, request changes, reject, cancel - and each of
	 * those has a rule about which statuses it may be reached from. Routing status through a
	 * generic setter would let a caller skip every one of them.
	 *
	 * Everything else is a display or metadata field, plus the link fields that
	 * {@see Review_Link_Service} owns.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $public_id    Review public id.
	 * @param array<string, mixed> $changes     Changes.
	 * @return array<string, mixed>|null The stored review, or null.
	 */
	public function update( $workspace_id, $public_id, array $changes ) {
		$review = $this->get( $workspace_id, $public_id );
		if ( null === $review ) {
			return null;
		}

		$clean = array();
		foreach ( array( 'title', 'note', 'decision_note', 'reviewer_id', 'reviewer_email', 'requested_by', 'completed_at', 'link_hash', 'link_password_hash', 'link_expires_at', 'link_revoked', 'link_attempts', 'link_suspended', 'link_last_used_at' ) as $column ) {
			if ( array_key_exists( $column, $changes ) ) {
				$clean[ $column ] = $changes[ $column ];
			}
		}
		if ( array() === $clean ) {
			return $this->get( $workspace_id, $public_id );
		}

		$this->update_row( (string) $public_id, $clean );
		return $this->get( $workspace_id, $public_id );
	}
	/**
	 * Move a review to `in_review`.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Review public id.
	 * @return array<string, mixed>|null
	 */
	public function start( $workspace_id, $public_id ) {
		return $this->transition( $workspace_id, $public_id, 'in_review', array( 'pending' ) );
	}

	/**
	 * Approve a review, binding the decision to its version.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $public_id    Review public id.
	 * @param string               $note         Decision note.
	 * @return array<string, mixed>|null
	 */
	public function approve( $workspace_id, $public_id, $note = '' ) {
		$review = $this->get( $workspace_id, $public_id );
		if ( null === $review ) {
			return null;
		}

		// §39: an approval is only meaningful against a real version. The column is NOT
		// NULL, but a row could in principle have been written by hand or by an older
		// version of this class, so the check is repeated rather than assumed.
		if ( '' === (string) $review['version_id'] ) {
			$this->logger->error(
				'review_without_version',
				'Refused to approve a review that is not bound to a version.',
				array( 'review' => (string) $public_id ),
				'workspace'
			);
			return null;
		}

		return $this->transition(
			$workspace_id,
			$public_id,
			'approved',
			array( 'pending', 'in_review', 'changes_requested' ),
			array(
				'decision_note' => (string) $note,
				'completed_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
		);
	}

	/**
	 * Request changes on a review.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Review public id.
	 * @param string $note         What to change.
	 * @return array<string, mixed>|null
	 */
	public function request_changes( $workspace_id, $public_id, $note = '' ) {
		$note = trim( (string) $note );
		if ( '' === $note ) {
			// §14: "changes requested" with no explanation is not actionable, and the
			// developer who receives it cannot guess what the client wanted. The note is
			// the point of the request.
			return null;
		}
		return $this->transition(
			$workspace_id,
			$public_id,
			'changes_requested',
			array( 'pending', 'in_review' ),
			array(
				'decision_note' => substr( $note, 0, 2000 ),
				'completed_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Reject a review outright.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Review public id.
	 * @param string $note         Reason.
	 * @return array<string, mixed>|null
	 */
	public function reject( $workspace_id, $public_id, $note = '' ) {
		return $this->transition(
			$workspace_id,
			$public_id,
			'rejected',
			array( 'pending', 'in_review', 'changes_requested' ),
			array(
				'decision_note' => (string) $note,
				'completed_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Cancel a review.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Review public id.
	 * @return array<string, mixed>|null
	 */
	public function cancel( $workspace_id, $public_id ) {
		return $this->transition( $workspace_id, $public_id, 'cancelled', array( 'pending', 'in_review', 'changes_requested' ) );
	}

	/**
	 * Assign or reassign a review's reviewer.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Review public id.
	 * @param int    $reviewer_id  New reviewer, or 0 to clear.
	 * @param string $email        New reviewer address.
	 * @return array<string, mixed>|null
	 */
	public function assign( $workspace_id, $public_id, $reviewer_id, $email = '' ) {
		$reviewer_id = max( 0, (int) $reviewer_id );
		$email       = sanitize_email( (string) $email );

		if ( 0 === $reviewer_id && '' === $email ) {
			return null;
		}

		// A decided review is not reassigned. Reopening a closed decision by changing who
		// holds it would make the audit trail say one person decided something another
		// person is now recorded as responsible for.
		$review = $this->get( $workspace_id, $public_id );
		if ( null === $review || ! in_array( (string) $review['status'], array( 'pending', 'in_review' ), true ) ) {
			return null;
		}

		if ( 0 === $reviewer_id && '' !== $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user instanceof \WP_User ) {
				$reviewer_id = (int) $user->ID;
			}
		}

		$this->update_row(
			(string) $public_id,
			array( 'reviewer_id' => $reviewer_id, 'reviewer_email' => $email )
		);

		return $this->get( $workspace_id, $public_id );
	}

	/**
	 * Cancel every open review of a project.
	 *
	 * Called when the project is archived, so a restored project does not come back with
	 * approvals that were never given for its current state.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @return int Reviews closed.
	 */
	public function cancel_open_for_project( $workspace_id, $project_id ) {
		global $wpdb;
		if ( ! $this->ready() ) {
			return 0;
		}
		$table = $this->table();
		$open  = implode( ', ', array_fill( 0, count( Workspace_Limits::OPEN_REVIEW_STATUSES ), '%s' ) );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'cancelled', updated_at = %s WHERE workspace_id = %s AND project_id = %s AND status IN ({$open})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				array_merge(
					array( gmdate( 'Y-m-d H:i:s' ), (string) $workspace_id, (string) $project_id ),
					array_values( Workspace_Limits::OPEN_REVIEW_STATUSES )
				)
			)
		);
	}

	/**
	 * Present a review for a reader, redacted to what they may see.
	 *
	 * ### §16's list, enforced here
	 *
	 * A client must not receive the validation report's internals, the source crawl, the
	 * private project metadata, or anything §16 excludes. So a client review is reduced
	 * to: the identity of the version being approved, who asked for it, the rendered
	 * difference *summary*, and the comments and decision. The `note` a reviewer wrote
	 * for internal eyes is withheld, because it is a request, not an instruction to the
	 * client.
	 *
	 * The rule is a whitelist, not a blacklist. A field added to the schema later is
	 * absent by default rather than present and accidentally disclosed.
	 *
	 * @param array<string, mixed> $review   Review row.
	 * @param bool                 $is_client Whether the reader is a client.
	 * @return array<string, mixed>
	 */
	public static function present( array $review, $is_client ) {
		if ( ! $is_client ) {
			return $review;
		}

		$version = (string) ( $review['version_number'] ?? '' );
		$kept    = array(
			'public_id'      => (string) ( $review['public_id'] ?? '' ),
			'project_id'     => (string) ( $review['project_id'] ?? '' ),
			'version_id'     => (string) ( $review['version_id'] ?? '' ),
			'version_number' => (int) ( $review['version_number'] ?? 0 ),
			'page_id'        => (int) ( $review['page_id'] ?? 0 ),
			'type'           => (string) ( $review['type'] ?? '' ),
			'status'         => (string) ( $review['status'] ?? '' ),
			'title'          => (string) ( $review['title'] ?? '' ),
			// The *decision* note is the client's own words on the record. The requested
			// `note` is the agency's brief and is withheld.
			'decision_note'  => (string) ( $review['decision_note'] ?? '' ),
			'requested_at'   => (string) ( $review['requested_at'] ?? '' ),
			'completed_at'   => (string) ( $review['completed_at'] ?? '' ),
			'created_at'     => (string) ( $review['created_at'] ?? '' ),
			'is_client_view' => true,
			// The version is stated as a human sentence so the UI cannot accidentally show
			// a raw id where the client should read "version 4".
			'version_label'  => ( '' === $version ) ? '' : sprintf(
				/* translators: %s: version number. */
				__( 'Version %s', 'replicaforge' ),
				$version
			),
		);

		// Everything withheld is named, so the client sees that something was withheld
		// rather than being unable to tell whether the data was ever there. Silent
		// redaction is indistinguishable from an empty review.
		$kept['withheld'] = array( 'note', 'validation_id', 'generation_id', 'reviewer_id', 'link_hash', 'link_password_hash', 'reviewer_email' );

		return $kept;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve the version a review applies to.
	 *
	 * ### Why the project's own history is the source of truth
	 *
	 * §38 forbids a duplicate versioning engine, and §15 requires the approval to name a
	 * concrete version. `Project_Repository` already stores versions with a `version_id`
	 * and a number, so those are read — never re-numbered, never re-issued.
	 *
	 * A caller may name a `version_id` or a `version_number`; whichever it names is
	 * checked against the project's history, and the *other* is taken from that same
	 * record. A caller that names neither gets the latest version, which is the common
	 * case: "review the current work".
	 *
	 * @param string               $project_id Project id.
	 * @param array<string, mixed> $data       Review data.
	 * @return array<string, mixed>|null
	 */
	private function resolve_version( $project_id, array $data ) {
		$project = ( new Project_Repository() )->find( (string) $project_id );
		if ( ! is_array( $project ) ) {
			return null;
		}

		$versions = ( isset( $project['versions'] ) && is_array( $project['versions'] ) ) ? $project['versions'] : array();
		if ( array() === $versions ) {
			// Nothing has been generated yet, so there is nothing to approve. Storing a
			// review against a project with no version is the approval §15 forbids.
			return null;
		}

		$wanted_id = trim( (string) ( $data['version_id'] ?? '' ) );
		$wanted_no = ( isset( $data['version_number'] ) && '' !== (string) $data['version_number'] ) ? (int) $data['version_number'] : 0;

		foreach ( $versions as $stored ) {
			if ( ! is_array( $stored ) ) {
				continue;
			}
			$stored_id = (string) ( $stored['version_id'] ?? '' );
			$stored_no = (int) ( $stored['version'] ?? 0 );

			if ( '' !== $wanted_id && $wanted_id === $stored_id ) {
				return $this->version_row( $stored );
			}
			if ( $wanted_no > 0 && $wanted_no === $stored_no ) {
				return $this->version_row( $stored );
			}
		}

		if ( '' === $wanted_id && 0 === $wanted_no ) {
			// The newest. `add_version()` stores the list oldest-first and the number is
			// monotonic, so the highest number is the latest — computed rather than
			// assumed, because the list order is an implementation detail.
			$latest = $versions[0];
			foreach ( $versions as $stored ) {
				if ( is_array( $stored ) && (int) ( $stored['version'] ?? 0 ) >= (int) ( $latest['version'] ?? 0 ) ) {
					$latest = $stored;
				}
			}
			return $this->version_row( $latest );
		}

		// A version was named and it does not exist on this project. Refused, rather than
		// falling back to the latest: silently reviewing something other than what was
		// asked for is the failure mode §15 exists to prevent.
		return null;
	}

	/**
	 * Normalise a stored version into the fields a review needs.
	 *
	 * @param array<string, mixed> $stored Stored version.
	 * @return array<string, mixed>|null
	 */
	private function version_row( array $stored ) {
		$version_id = (string) ( $stored['version_id'] ?? '' );
		$number     = (int) ( $stored['version'] ?? 0 );
		if ( '' === $version_id || $number < 1 ) {
			return null;
		}
		return array(
			'version_id'     => $version_id,
			'version_number' => $number,
			'generation_id'  => (string) ( $stored['generation_id'] ?? '' ),
			'validation_id'  => (string) ( $stored['validation_id'] ?? '' ),
			'created_at'     => (string) ( $stored['created_at'] ?? '' ),
		);
	}

	/**
	 * Move a review to a new status, from one of the allowed previous statuses.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $public_id    Review public id.
	 * @param string               $status       New status.
	 * @param array<int, string>   $from         Allowed previous statuses.
	 * @param array<string, mixed> $extra        Extra columns.
	 * @return array<string, mixed>|null
	 */
	private function transition( $workspace_id, $public_id, $status, array $from, array $extra = array() ) {
		$review = $this->get( $workspace_id, $public_id );
		if ( null === $review ) {
			return null;
		}

		$current = (string) $review['status'];
		if ( ! in_array( $status, Workspace_Limits::REVIEW_STATUSES, true ) ) {
			return null;
		}
		if ( ! in_array( $current, $from, true ) ) {
			// A decided review does not change its mind through a second decision. The
			// caller has to cancel it and request a new one, which leaves two records
			// rather than overwriting the first.
			return null;
		}

		$changes = array_merge( array( 'status' => $status ), $extra );
		$this->update_row( (string) $public_id, $changes );

		return $this->get( $workspace_id, $public_id );
	}
}
