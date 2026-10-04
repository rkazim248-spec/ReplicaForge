<?php
/**
 * Phase 15: the collaboration fields on an existing project.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The §8 agency fields, written onto the project record that already exists.
 *
 * ### Why this is not a table
 *
 * §8 says: "Do not create a second project entity if one already exists. Add fields
 * through migrations." A project already exists — `Project_Repository` holds it, with a
 * `project_id`, a `status`, a `versions` list, and a `user_id`. Putting the agency fields
 * in a side table keyed by `project_id` would create exactly the second entity §8 forbids:
 * two places to look, two places to update, and a window in which they disagree — which is
 * how a project ends up assigned to one client in a list and another in a detail view.
 *
 * So the fields live **on the project record**, written through
 * {@see Project_Repository::update()}, which is the same call fourteen phases of code
 * already use. There is one project. It now carries more fields.
 *
 * ### The two status axes, and why they are not merged
 *
 * `Project_Status::STATUSES` is the reconstruction pipeline: new, analyzing, generated,
 * validating, needs_correction, monitoring, completed, archived. It is driven by the
 * engine and has a transition table fourteen phases read.
 *
 * `Workspace_Limits::STAGES` is the agency workflow: draft, in_progress, in_review,
 * changes_requested, approved, completed, archived. It is driven by people.
 *
 * They are stored in **different fields** and both are reported. Merging them would mean a
 * client requesting changes had to move the pipeline backwards through a table that
 * encodes "a generation completed it moved here", and a project could not legitimately be
 * `in_review` while the engine considered it `monitoring` — which is the normal state of a
 * live site a client is still signing off.
 *
 * ### One field the engine must not own
 *
 * `stage` is user-set, and `Workspace_Store`'s approval gates (§39) decide whether
 * `completed` is *allowed*. Both facts are stored: the stage the user set, and whether the
 * gates were satisfied. A report that showed only the stage would claim a project is
 * complete when its required approval is still pending.
 */
final class Project_Context_Store {

	/**
	 * The project repository.
	 *
	 * @var Project_Repository
	 */
	private $projects;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * The collaboration fields, with their defaults.
	 *
	 * @var array<string, mixed>
	 */
	const DEFAULTS = array(
		'workspace_id'   => '',
		'client_id'      => '',
		'description'    => '',
		'stage'          => 'draft',
		'priority'       => 'medium',
		'owner_id'       => 0,
		'created_by'     => 0,
		'start_date'     => '',
		'target_date'    => '',
		'archived_at'    => '',
		'gate_state'     => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param Project_Repository|null $projects Optional repository.
	 * @param Logger|null             $logger   Optional logger.
	 */
	public function __construct( $projects = null, $logger = null ) {
		$this->projects = $projects instanceof Project_Repository ? $projects : new Project_Repository();
		$this->logger   = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Return the collaboration fields for a project, with defaults filled in.
	 *
	 * @param string $project_id Project id.
	 * @return array<string, mixed>
	 */
	public function get( $project_id ) {
		$project = $this->projects->find( (string) $project_id );
		if ( ! is_array( $project ) ) {
			return array();
		}
		return $this->extract( $project );
	}

	/**
	 * Return the collaboration fields for every project, in one pass.
	 *
	 * The repository already loads every project for anything that lists them, so this
	 * costs one pass rather than N repository lookups. §45's "indexed queries" does not
	 * apply to a blob that is already in memory, and pretending otherwise by adding a table
	 * would mean keeping two sources in step.
	 *
	 * @param string $workspace_id Workspace id, or empty for all.
	 * @return array<string, array<string, mixed>> Keyed by project id.
	 */
	public function all( $workspace_id = '' ) {
		$workspace_id = (string) $workspace_id;
		$out          = array();

		foreach ( (array) $this->projects->all() as $project ) {
			if ( ! is_array( $project ) ) {
				continue;
			}
			$context = $this->extract( $project );
			if ( '' !== $workspace_id && (string) $context['workspace_id'] !== $workspace_id ) {
				continue;
			}
			$out[ (string) $project['project_id'] ] = $context;
		}

		return $out;
	}

	/**
	 * Write the collaboration fields onto a project.
	 *
	 * @param string               $project_id Project id.
	 * @param array<string, mixed> $changes    Changes.
	 * @return array<string, mixed> The stored context, or an empty array on failure.
	 */
	public function update( $project_id, array $changes ) {
		$project_id = (string) $project_id;
		$project    = $this->projects->find( $project_id );
		if ( ! is_array( $project ) ) {
			return array();
		}

		$current = $this->extract( $project );
		$merged  = array_merge( $current, $this->clean( $changes ) );

		// One write, through the repository. The alternative - calling update() repeatedly
		// per field - would re-read and re-write the whole project option on every field,
		// and two concurrent requests would each write back a stale copy of the others'
		// work.
		$ok = $this->projects->update(
			$project_id,
			array( 'collaboration' => $merged )
		);

		if ( ! is_array( $ok ) ) {
			$this->logger->error(
				'project_context_write_failed',
				'Could not write a project collaboration record.',
				array( 'project_id' => $project_id ),
				'workspace'
			);
			return array();
		}

		return $merged;
	}

	/**
	 * Return the project a workspace owns by a given reconstruction id.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @return array<string, mixed>|null
	 */
	public function owned( $workspace_id, $project_id ) {
		$context = $this->get( (string) $project_id );
		if ( array() === $context ) {
			return null;
		}
		// Both sides compared. A project id from another workspace is not "not found" in
		// the caller's terms - it is not theirs, and the two are answered identically by
		// the API layer so the distinction cannot be probed.
		if ( (string) $context['workspace_id'] !== (string) $workspace_id ) {
			return null;
		}
		$project = $this->projects->find( (string) $project_id );
		return is_array( $project ) ? array( 'project' => $project, 'context' => $context ) : null;
	}

	/**
	 * Return the projects a workspace owns, for the list and dashboard.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param array  $args         Filters: `status`, `stage`, `client_id`, `search`.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_projects( $workspace_id, array $args = array() ) {
		$workspace_id = (string) $workspace_id;
		$out          = array();

		/*
		 * One read for the whole set, rather than one per row. The repository already
		 * returns every project in a single call, so the per-row lookup a screen would
		 * otherwise have to do is a query per project on a screen that can list hundreds -
		 * the exact cost the scaling section warns about.
		 */
		$stored = $this->projects->all();

		// `all()` returns a list, not a map keyed by project id, so it is
		// indexed here rather than looked up per row. One pass over an array
		// already in memory, against one option read per project.
		$by_id = array();
		foreach ( (array) $stored as $candidate ) {
			if ( is_array( $candidate ) && '' !== (string) ( $candidate['project_id'] ?? '' ) ) {
				$by_id[ (string) $candidate['project_id'] ] = $candidate;
			} elseif ( is_array( $candidate ) ) {
				/*
				 * A project with no id. Kept in the set under a key nothing can
				 * collide with, so it is not silently dropped - a row that vanishes
				 * from a list is a row an administrator will never know existed.
				 */
				$by_id[ '__unidentified__' ] = $candidate;
			}
		}

		foreach ( $this->all( $workspace_id ) as $project_id => $context ) {
			if ( isset( $args['stage'] ) && '' !== (string) $args['stage'] && (string) $context['stage'] !== (string) $args['stage'] ) {
				continue;
			}

			if ( isset( $args['client_id'] ) && '' !== (string) $args['client_id'] && (string) $context['client_id'] !== (string) $args['client_id'] ) {
				continue;
			}

			/*
			 * The project itself is included, not just its id. A list that cannot say what
			 * its rows are forces every screen to read the repository again, and a screen that
			 * guesses at a field it does not have shows an empty column rather than an error.
			 */
			$project = isset( $by_id[ (string) $project_id ] ) ? $by_id[ (string) $project_id ] : null;
			if ( ! is_array( $project ) ) {
				/*
				 * The context names a project the repository no longer holds. Reported as a
				 * row with no project rather than dropped, because a project whose record
				 * disappeared is something an administrator needs to see.
				 */
				$out[] = array( 
					'project_id' => (string) $project_id,
					'context'   => $context,
					'project'   => null,
					'orphaned'  => true,
				);
				continue;
			}

			if ( isset( $args['status'] ) && '' !== (string) $args['status'] ) {
				if ( (string) ( $project['status'] ?? '' ) !== (string) $args['status'] ) {
					continue;
				}
			}

			if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
				$needle = strtolower( (string) $args['search'] );
				$hay    = strtolower( (string) ( $project['name'] ?? '' ) . ' ' . (string) ( $project['source_url'] ?? '' ) );
				if ( false === strpos( $hay, $needle ) ) {
					continue;
				}
			}

			$out[] = array(
				'project_id' => (string) $project_id,
				'context'   => $context,
				'project'   => $project,
				'orphaned'  => false,
			);
		}

		return $out;
	}

	/**
	 * Return the §39 approval-gate state for a project.
	 *
	 * Gates are derived, not stored as a single "passed" boolean, because "a project must
	 * not be completed while a required approval is pending" (§39) needs the *individual*
	 * answers to be reportable. A single flag would say "not ready" without saying which
	 * review is outstanding, and the agency could not act on it.
	 *
	 * @param string               $project_id Project id.
	 * @param array<string, mixed> $context    Collaboration context.
	 * @param array<string, mixed> $settings   Workspace settings.
	 * @return array<string, mixed>
	 */
	public function gates( $project_id, array $context, array $settings ) {
		$reviews   = ( new Review_Store() );
		$workspace = (string) $context['workspace_id'];

		$internal_required = ! empty( $settings['require_internal_approval'] );
		$client_required   = ! empty( $settings['require_client_approval'] );

		$internal = $this->gate_for( $reviews, $workspace, $project_id, 'internal' );
		$client   = $this->gate_for( $reviews, $workspace, $project_id, 'client' );

		$gates = array(
			'internal' => array(
				'required' => $internal_required,
				'status'   => $internal['status'],
				'version'  => $internal['version'],
				'note'     => $internal['note'],
			),
			'client'   => array(
				'required' => $client_required,
				'status'   => $client['status'],
				'version'  => $client['version'],
				'note'     => $client['note'],
			),
		);

		$outstanding = array();
		foreach ( $gates as $name => $gate ) {
			if ( $gate['required'] && 'approved' !== $gate['status'] ) {
				$outstanding[] = $name;
			}
		}

		return array(
			'gates'       => $gates,
			'outstanding' => $outstanding,
			// §39: a project is completable only when nothing required is outstanding.
			// This is the enforced answer, and it is separate from the stage the user set.
			'completable' => ( array() === $outstanding ),
			'reason'      => ( array() === $outstanding )
				? __( 'All required approvals are in place.', 'replicaforge' )
				: sprintf(
					/* translators: %s: comma-separated list of outstanding gates. */
					__( 'Waiting on: %s.', 'replicaforge' ),
					implode( ', ', $outstanding )
				),
		);
	}

	/**
	 * Return one gate's state from the project's reviews.
	 *
	 * @param Review_Store $store       Reviews.
	 * @param string       $workspace   Workspace id.
	 * @param string       $project_id  Project id.
	 * @param string       $type        Review type.
	 * @return array<string, mixed>
	 */
	private function gate_for( Review_Store $store, $workspace, $project_id, $type ) {
		$page = $store->reviews( $workspace, array( 'project_id' => (string) $project_id, 'type' => $type, 'per_page' => 1 ) );
		if ( 0 === (int) $page['count'] ) {
			return array( 'status' => 'none', 'version' => '', 'note' => __( 'No review has been requested yet.', 'replicaforge' ) );
		}
		$review = $page['items'][0];
		return array(
			'status'  => (string) $review['status'],
			'version' => (string) ( $review['version_number'] ?? '' ),
			'note'    => (string) ( $review['title'] ?? '' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Read the collaboration fields off a project record, with defaults.
	 *
	 * @param array<string, mixed> $project Project.
	 * @return array<string, mixed>
	 */
	public function extract( array $project ) {
		$stored = (array) ( $project['collaboration'] ?? array() );
		$out    = self::DEFAULTS;

		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			if ( array_key_exists( $key, $stored ) ) {
				$out[ $key ] = $stored[ $key ];
			}
		}

		// `owner_id` and `created_by` default to the project creator, which is the record
		// that already exists and is authoritative for a single-user project. Guessing a
		// different owner for a project nobody has claimed would make the team screen
		// show a stranger.
		if ( 0 === (int) $out['owner_id'] ) {
			$out['owner_id'] = (int) ( $project['user_id'] ?? 0 );
		}
		if ( 0 === (int) $out['created_by'] ) {
			$out['created_by'] = (int) ( $project['user_id'] ?? 0 );
		}

		if ( ! in_array( (string) $out['stage'], array_keys( Workspace_Limits::STAGES ), true ) ) {
			$out['stage'] = 'draft';
		}
		if ( ! Workspace_Limits::is_priority( (string) $out['priority'] ) ) {
			$out['priority'] = 'medium';
		}
		$out['gate_state'] = (array) $out['gate_state'];

		return $out;
	}

	/**
	 * Validate and coerce incoming changes.
	 *
	 * @param array<string, mixed> $changes Changes.
	 * @return array<string, mixed>
	 */
	private function clean( array $changes ) {
		$out = array();

		if ( array_key_exists( 'workspace_id', $changes ) ) {
			$out['workspace_id'] = preg_replace( '/[^A-Za-z0-9]/', '', (string) $changes['workspace_id'] );
		}
		if ( array_key_exists( 'client_id', $changes ) ) {
			$out['client_id'] = preg_replace( '/[^A-Za-z0-9]/', '', (string) $changes['client_id'] );
		}
		if ( array_key_exists( 'description', $changes ) ) {
			$out['description'] = sanitize_textarea_field( (string) $changes['description'] );
		}
		/*
		 * An unrecognised value is *ignored*, not reset to the default.
		 *
		 * Writing the default for an invalid input would mean a request carrying a typo
		 * in a stage moved a project back to draft - destructive, and invisible, because
		 * the write still reports success. Leaving the key out of the merge keeps the
		 * stored value, which is the safe direction for a field the requester does not own.
		 */
		if ( array_key_exists( 'stage', $changes ) && Workspace_Limits::is_stage( (string) $changes['stage' ] ) ) {
			$out['stage'] = (string) $changes['stage'];
		}
		if ( array_key_exists( 'priority', $changes ) && Workspace_Limits::is_priority( (string) $changes['priority' ] ) ) {
			$out['priority'] = (string) $changes['priority'];
		}
		foreach ( array( 'owner_id', 'created_by' ) as $key ) {
			if ( array_key_exists( $key, $changes ) ) {
				$out[ $key ] = max( 0, (int) $changes[ $key ] );
			}
		}
		foreach ( array( 'start_date', 'target_date' ) as $key ) {
			if ( array_key_exists( $key, $changes ) ) {
				$out[ $key ] = $this->date( $changes[ $key ] );
			}
		}
		if ( array_key_exists( 'archived_at', $changes ) ) {
			$out['archived_at'] = (string) $changes['archived_at'];
		}

		return $out;
	}

	/**
	 * Return a `Y-m-d` date, or an empty string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$time = strtotime( $value );
		return ( false === $time ) ? '' : gmdate( 'Y-m-d', $time );
	}
}
