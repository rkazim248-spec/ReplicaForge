<?php
/**
 * Phase 10: project ownership and access.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Decides who may read and change a project.
 *
 * This is a small class because the rule is small, and it exists because before
 * Phase 10 the plugin had no ownership model at all. `Project_Repository::all()`
 * returned every project on the site, and the REST layer returned them. On a
 * single-user site that is invisible; on a site with two editors it means one
 * editor can read the other's source URLs, their AI results, and their generated
 * documents. §26 forbids that and this class is where the rule lives.
 *
 * The rule is deliberately not "administrators can see everything, nobody else can
 * see anything". A project is a record of a specific person's work on a specific
 * page, so the owner sees it, and a user holding
 * {@see \ReplicaForge\Capabilities::ADMIN_CAPS} `replicaforge_manage_projects`
 * sees it because they are responsible for the site. Nobody else does — not other
 * authors, not other editors, and not a user with a forged project id.
 *
 * Every method takes the user id explicitly rather than reading
 * `get_current_user_id()`. A check that reads the ambient user cannot be tested for
 * a *different* user, and testing "user B cannot read user A's project" is the
 * whole point of having the class.
 */
final class Project_Access {

	/**
	 * Project repository.
	 *
	 * @var Project_Repository
	 */
	private $projects;

	/**
	 * Constructor.
	 *
	 * @param Project_Repository|null $projects Optional repository.
	 */
	public function __construct( $projects = null ) {
		$this->projects = $projects instanceof Project_Repository ? $projects : new Project_Repository();
	}

	/**
	 * Return the project record if the user owns it.
	 *
	 * @param int $user_id    User id.
	 * @param string $project_id Project identifier.
	 * @return array<string, mixed>|null
	 */
	public function owned_project( $user_id, $project_id ) {
		$user_id    = (int) $user_id;
		$project_id = is_string( $project_id ) ? trim( $project_id ) : '';

		if ( $user_id < 1 || '' === $project_id ) {
			return null;
		}

		$project = $this->projects->find( $project_id );
		if ( ! is_array( $project ) ) {
			return null;
		}

		$owner = isset( $project['user_id'] ) ? (int) $project['user_id'] : 0;
		return ( $owner === $user_id ) ? $project : null;
	}

	/**
	 * Return whether a user owns a project.
	 *
	 * @param int    $user_id    User id.
	 * @param string $project_id Project identifier.
	 * @return bool
	 */
	public function owns( $user_id, $project_id ) {
		return null !== $this->owned_project( $user_id, $project_id );
	}

	/**
	 * Return whether a user may see a project at all.
	 *
	 * @param int    $user_id    User id.
	 * @param string $project_id Project identifier.
	 * @return bool
	 */
	public function can_read( $user_id, $project_id ) {
		if ( $this->owns( $user_id, $project_id ) ) {
			return true;
		}
		return Capabilities::user_can( $user_id, 'replicaforge_manage_projects' );
	}

	/**
	 * Return the project if the user may see it, for any reason.
	 *
	 * @param int    $user_id    User id.
	 * @param string $project_id Project identifier.
	 * @return array<string, mixed>|null
	 */
	public function readable_project( $user_id, $project_id ) {
		$project = $this->projects->find( is_string( $project_id ) ? trim( $project_id ) : '' );
		if ( ! is_array( $project ) ) {
			return null;
		}
		return $this->can_read( $user_id, $project_id ) ? $project : null;
	}

	/**
	 * Return the project if the user may change it.
	 *
	 * Reading and writing are the same rule here. A user who may not see a project
	 * has no business changing it, and there is no case where a project is visible
	 * but not editable that a plan entitlement does not already cover.
	 *
	 * @param int    $user_id    User id.
	 * @param string $project_id Project identifier.
	 * @return array<string, mixed>|null
	 */
	public function writable_project( $user_id, $project_id ) {
		return $this->readable_project( $user_id, $project_id );
	}

	/**
	 * Return the projects a user may see, newest first.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Maximum projects.
	 * @return array<int, array<string, mixed>>
	 */
	public function visible_projects( $user_id, $limit = 25 ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return array();
		}

		if ( Capabilities::user_can( $user_id, 'replicaforge_manage_projects' ) ) {
			return $this->projects->recent( array(), (int) $limit );
		}

		$mine = array();
		foreach ( $this->projects->all() as $project ) {
			if ( isset( $project['user_id'] ) && (int) $project['user_id'] === $user_id ) {
				$mine[] = $project;
			}
		}

		usort(
			$mine,
			static function ( $left, $right ) {
				$left_time  = isset( $left['updated_at'] ) ? strtotime( (string) $left['updated_at'] ) : 0;
				$right_time = isset( $right['updated_at'] ) ? strtotime( (string) $right['updated_at'] ) : 0;
				if ( $left_time === $right_time ) {
					return strcmp( (string) ( $left['project_id'] ?? '' ), (string) ( $right['project_id'] ?? '' ) );
				}
				return ( $left_time < $right_time ) ? 1 : -1;
			}
		);

		return array_slice( $mine, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Return how many of a user's projects exist.
	 *
	 * Counted over the whole set rather than over a page, because the answer to
	 * "have I used all my history allowance" must not depend on how many projects
	 * were requested.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public function count_projects( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return 0;
		}
		$count = 0;
		foreach ( $this->projects->all() as $project ) {
			if ( isset( $project['user_id'] ) && (int) $project['user_id'] === $user_id ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Return how many of a user's projects have monitoring requested.
	 *
	 * Monitoring has not been built — §9's `Source_Monitor` is a Phase 11 item and
	 * nothing in Phases 1 to 9 sets a monitoring flag. This counts the flag anyway
	 * rather than returning a hard zero, so that when monitoring does arrive the
	 * entity limit starts counting the right thing instead of needing the counting
	 * code rewritten. Returning a fabricated 0 today would be the alternative, and
	 * it would silently read as "nobody is monitoring" on a site that is.
	 *
	 * @param int $user_id User id.
	 * @return array{count: int, projects: array<int, string>}
	 */
	public function count_monitored( $user_id ) {
		$user_id = (int) $user_id;
		$count   = 0;
		$ids     = array();

		if ( $user_id < 1 ) {
			return array(
				'count'    => 0,
				'projects' => array(),
			);
		}

		foreach ( $this->projects->all() as $project ) {
			if ( ! isset( $project['user_id'] ) || (int) $project['user_id'] !== $user_id ) {
				continue;
			}
			$monitoring = isset( $project['settings']['monitoring'] ) && ! empty( $project['settings']['monitoring']['enabled'] );
			if ( $monitoring ) {
				$count++;
				$ids[] = (string) ( $project['project_id'] ?? '' );
			}
		}

		return array(
			'count'    => $count,
			'projects' => $ids,
		);
	}

	/**
	 * Return a structured refusal for a project the user may not touch.
	 *
	 * The message is deliberately the same whether the project belongs to somebody
	 * else or does not exist. Distinguishing them turns the endpoint into an
	 * oracle: a caller can enumerate project ids by watching which refusal they
	 * get, and project ids are short enough to be enumerable.
	 *
	 * @param int    $user_id    User id.
	 * @param string $project_id Project identifier.
	 * @return array<string, mixed>
	 */
	public function refusal( $user_id, $project_id ) {
		$user_id    = (int) $user_id;
		$project_id = is_string( $project_id ) ? trim( $project_id ) : '';

		if ( '' === $project_id ) {
			return array(
				'allowed' => false,
				'code'    => 'project_id_required',
				'message' => __( 'No project was named.', 'replicaforge' ),
				'status'  => 400,
			);
		}

		if ( $user_id < 1 ) {
			return array(
				'allowed' => false,
				'code'    => 'authentication_required',
				'message' => __( 'Sign in to use this project.', 'replicaforge' ),
				'status'  => 401,
			);
		}

		if ( $this->owns( $user_id, $project_id ) ) {
			return array(
				'allowed' => true,
				'code'    => '',
				'message' => '',
				'status'  => 200,
			);
		}

		if ( Capabilities::user_can( $user_id, 'replicaforge_manage_projects' ) ) {
			return array(
				'allowed' => true,
				'code'    => '',
				'message' => '',
				'status'  => 200,
			);
		}

		return array(
			'allowed' => false,
			'code'    => 'project_not_available',
			'message' => __( 'That project is not available.', 'replicaforge' ),
			'status'  => 404,
		);
	}
}
