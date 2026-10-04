<?php
/**
 * Phase 15: the comment store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Contextual review comments, including visual annotations.
 *
 * ### A comment is anchored to a version, always
 *
 * §18 wants comments that reference a page, a section, a component, an Elementor element,
 * a validation difference or a content mapping. §20 adds that coordinates are
 * *supplemental*: a rectangle on a screenshot identifies a region on the screen it was
 * taken from and nothing else.
 *
 * So a comment stores `version_id`, and every anchor is interpreted relative to that
 * version. Without it, "increase the button spacing" attaches to a hero section that may
 * have been regenerated since, and the comment silently becomes wrong — which is worse
 * than a comment that is visibly out of date.
 *
 * The anchor *type* says what the comment is about; the coordinates, when present, refine
 * it. {@see self::anchored()} reports which of those a comment has, so a UI can say
 * "attached to the Hero heading" rather than "attached to 34% from the left, 12% from the
 * top", and a reviewer can tell the difference between a comment that will survive a
 * regeneration and one that will not.
 *
 * ### Why the stable ids are columns and not metadata
 *
 * `section_id`, `component_id` and `element_id` are columns. They could have been keys in
 * a metadata blob, and that would have been one fewer place to change — but a blob cannot
 * be indexed, and "every comment on this component" is the query §20's annotation workflow
 * needs in order to draw the markers back onto a fresh screenshot. An index on
 * `component_id` is what makes the re-anchoring possible at all.
 *
 * ### Threads are never deleted
 *
 * §19 is explicit that important audit history is not permanently deleted. A comment is
 * resolved or reopened, never removed; {@see self::delete()} does not exist. What can be
 * removed is a *reply* that was posted by mistake, and even then only by its author, and
 * the removal is recorded as an activity event rather than performed silently.
 */
final class Comment_Store extends Collaboration_Store {

	/**
	 * The entity kind.
	 *
	 * @var string
	 */
	protected $kind = 'comments';

	/**
	 * The max length of a comment body, in characters.
	 *
	 * 4000. A review comment is a paragraph. The `TEXT` column would hold 64KB, and a
	 * review page that renders a 60KB comment is a page nobody can read.
	 *
	 * @var int
	 */
	const MAX_BODY = 4000;

	/**
	 * Return the columns that may be written.
	 *
	 * @return array<int, string>
	 */
	protected function writable_columns() {
		return array(
			'public_id', 'workspace_id', 'project_id', 'page_id', 'version_id', 'parent_id',
			'anchor_type', 'section_id', 'component_id', 'element_id', 'difference_id',
			'mapping_id', 'viewport', 'region_x', 'region_y', 'region_width', 'region_height',
			'body', 'status', 'mentions', 'author_id', 'author_name', 'is_client',
			'resolved_by', 'resolved_at', 'created_at', 'updated_at',
		);
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
			'page_id'       => 'int',
			'version_id'    => 'string',
			'parent_id'     => 'string',
			'anchor_type'   => 'line',
			'section_id'    => 'string',
			'component_id'  => 'string',
			'element_id'    => 'string',
			'difference_id' => 'string',
			'mapping_id'    => 'string',
			'viewport'      => 'line',
			'region_x'      => 'float',
			'region_y'      => 'float',
			'region_width'  => 'float',
			'region_height' => 'float',
			'body'          => 'text',
			'status'        => 'line',
			'mentions'      => 'json',
			'author_id'     => 'int',
			'author_name'   => 'line',
			'is_client'     => 'bool',
			'resolved_by'   => 'int',
			'resolved_at'   => 'datetime',
			'created_at'    => 'datetime',
			'updated_at'    => 'datetime',
		);
	}

	/**
	 * Return the columns free text may search.
	 *
	 * @return array<int, string>
	 */
	protected function searchable_columns() {
		return array( 'body', 'author_name' );
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return a comment.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Comment public id.
	 * @return array<string, mixed>|null
	 */
	public function get( $workspace_id, $public_id ) {
		return $this->find( (string) $workspace_id, (string) $public_id );
	}

	/**
	 * Return a page of comments on a project.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @param array  $args         Query arguments, plus `anchor_type`, `element_id`, `component_id`.
	 * @return array<string, mixed>
	 */
	public function comments( $workspace_id, $project_id, array $args = array() ) {
		$page = $this->query( (string) $workspace_id, array_merge( $args, array( 'project_id' => (string) $project_id ) ) );
		$page['by_status'] = $this->group_counts( (string) $workspace_id, 'status', array( 'project_id' => (string) $project_id ) );
		return $page;
	}

	/**
	 * Return a comment thread, oldest first, replies nested.
	 *
	 * §19 wants a parent with replies. The whole thread is fetched in one query and
	 * assembled in PHP, bounded by {@see Workspace_Limits::MAX_COMMENT_DEPTH}, because a
	 * thread is small by nature and fetching it level by level would issue one query per
	 * reply for a structure a single query can return.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Root comment public id.
	 * @return array<string, mixed>
	 */
	public function thread( $workspace_id, $public_id ) {
		$root = $this->get( $workspace_id, $public_id );
		if ( null === $root ) {
			return $this->empty_page();
		}

		global $wpdb;
		$table = $this->table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE workspace_id = %s AND project_id = %s ORDER BY created_at ASC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal.
				(string) $workspace_id,
				(string) $root['project_id'],
				Workspace_Limits::MAX_COMMENT_DEPTH * 10
			),
			ARRAY_A
		);

		$by_id = array();
		foreach ( (array) $rows as $row ) {
			$item              = $this->cast( $row );
			$item['replies']   = array();
			$by_id[ (string) $item['public_id'] ] = $item;
		}

		// Second pass: attach. A reply whose parent is absent from the window is dropped
		// rather than promoted to a root, because presenting a reply as if it were a
		// top-level comment misrepresents who said what in reply to what.
		$replies = array();
		foreach ( $by_id as $id => $item ) {
			$parent = (string) $item['parent_id'];
			if ( '' === $parent ) {
				continue;
			}
			unset( $by_id[ $id ] );
			if ( isset( $by_id[ $parent ] ) ) {
				$by_id[ $parent ]['replies'][] = $item;
				continue;
			}
			$replies[] = $item;
		}

		$page            = $this->empty_page();
		$page['items']   = array( $by_id[ (string) $root['public_id'] ] ?? array() );
		$page['count']   = 1;
		$page['orphans'] = count( $replies );

		return $page;
	}

	/**
	 * Return every comment anchored to one component, across versions.
	 *
	 * This is the query §20's re-annotation needs: a fresh screenshot has new pixels but
	 * the same `component_id`, so every comment ever made about that component can be
	 * drawn back onto it.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @param string $component_id Component id.
	 * @param int    $limit        Maximum.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_component( $workspace_id, $project_id, $component_id, $limit = 50 ) {
		$page = $this->query(
			(string) $workspace_id,
			array(
				'project_id' => (string) $project_id,
				'per_page'   => $limit,
			)
		);

		// `component_id` is not among the store's generic exact-match filters, so it is
		// filtered here rather than widening the shared filter list for one caller's use.
		$out = array();
		foreach ( $page['items'] as $item ) {
			if ( (string) $item['component_id'] === (string) $component_id ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Return the open comment count on a project.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $project_id   Project id.
	 * @return int
	 */
	public function open_count_for_project( $workspace_id, $project_id ) {
		return $this->count_where( (string) $workspace_id, array( 'project_id' => (string) $project_id, 'status' => 'open' ) );
	}

	/**
	 * Return the open comment count across a workspace.
	 *
	 * @param string $workspace_id Workspace id.
	 * @return int
	 */
	public function open_count( $workspace_id ) {
		return $this->count_where( (string) $workspace_id, array( 'status' => 'open' ) );
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Post a comment.
	 *
	 * @param string               $workspace_id Workspace id.
	 * @param string               $project_id   Project id.
	 * @param array<string, mixed> $data         Comment data.
	 * @return array<string, mixed>|null
	 */
	public function create( $workspace_id, $project_id, array $data ) {
		$workspace_id = (string) $workspace_id;
		$project_id   = (string) $project_id;

		if ( '' === $workspace_id || '' === $project_id || ! $this->ready() ) {
			return null;
		}

		$body = trim( (string) ( $data['body'] ?? '' ) );
		if ( '' === $body ) {
			return null;
		}
		// The length is enforced here rather than by the column, because a truncated
		// comment that looks complete is worse than one that is refused.
		$body = $this->truncate( $body, self::MAX_BODY );

		$version_id = (string) ( $data['version_id'] ?? '' );
		if ( '' === $version_id ) {
			// §20: a comment without a version has nothing to be anchored *to*. Refused
			// rather than stored, because an unanchored comment is the failure mode the
			// whole column set exists to prevent.
			return null;
		}

		$anchor = (string) ( $data['anchor_type'] ?? 'project' );
		if ( ! in_array( $anchor, Workspace_Limits::COMMENT_ANCHORS, true ) ) {
			$anchor = 'project';
		}

		$parent = (string) ( $data['parent_id'] ?? '' );
		if ( '' !== $parent ) {
			$parent_row = $this->get( $workspace_id, $parent );
			if ( null === $parent_row || (string) $parent_row['project_id'] !== $project_id ) {
				// A reply to a comment that is not there, or is on another project, is
				// refused. Silently making it a root comment would lose the thread.
				return null;
			}
			// The reply inherits the thread's version. A reply anchored to a different
			// version than the comment it answers is a contradiction, and the thread is
			// read as one thing.
			$version_id = (string) $parent_row['version_id'];
		}

		$author_id = max( 0, (int) ( $data['author_id'] ?? get_current_user_id() ) );

		$mentions = $this->resolve_mentions( $workspace_id, (string) ( $data['mentions'] ?? '' ) );

		return $this->insert(
			array(
				'public_id'     => $this->new_public_id(),
				'workspace_id'  => $workspace_id,
				'project_id'    => $project_id,
				'page_id'       => max( 0, (int) ( $data['page_id'] ?? 0 ) ),
				'version_id'    => $version_id,
				'parent_id'     => $parent,
				'anchor_type'   => $anchor,
				'section_id'    => $string_id = $this->clean_id( $data['section_id'] ?? '' ),
				'component_id'  => $this->clean_id( $data['component_id'] ?? '' ),
				'element_id'    => $this->clean_id( $data['element_id'] ?? '' ),
				'difference_id' => $this->clean_id( $data['difference_id'] ?? '' ),
				'mapping_id'    => $this->clean_id( $data['mapping_id'] ?? '' ),
				'viewport'      => $this->clean_viewport( $data['viewport'] ?? '' ),
				'region_x'      => $this->clean_coordinate( $data['region_x'] ?? null ),
				'region_y'      => $this->clean_coordinate( $data['region_y'] ?? null ),
				'region_width'  => $this->clean_coordinate( $data['region_width'] ?? null ),
				'region_height' => $this->clean_coordinate( $data['region_height'] ?? null ),
				'body'          => $body,
				'status'        => 'open',
				'mentions'      => $mentions,
				'author_id'     => $author_id,
				'author_name'   => $this->author_name( $author_id, $data ),
				// §16 records who spoke: a client comment is marked, because it is the
				// agency's own review record that a client's comment exists and says what
				// it said. That distinction drives §27 - a client's words are not internal.
				'is_client'     => ! empty( $data['is_client'] ),
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Resolve or reopen a comment.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Comment public id.
	 * @param int    $actor_id     Resolver.
	 * @return array<string, mixed>|null
	 */
	public function resolve( $workspace_id, $public_id, $actor_id = 0 ) {
		$comment = $this->get( $workspace_id, $public_id );
		/*
		 * Both `open` and `reopened` are resolvable.
		 *
		 * `reopened` is not a separate lock - it is `open` plus a record that the comment
		 * was once resolved. Accepting only `open` meant a reopened comment could never be
		 * resolved again, so the thread was stuck open and the only move left was another
		 * reopen, which wrote the same status. Three declared statuses, two of them
		 * reachable, and no way out of the third.
		 */
		if ( null === $comment || ! in_array( (string) $comment['status'], array( 'open', 'reopened' ), true ) ) {
			return null;
		}
		$this->update_row(
			(string) $public_id,
			array(
				'status'      => 'resolved',
				'resolved_by' => max( 0, (int) $actor_id ),
				'resolved_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return $this->get( $workspace_id, $public_id );
	}

	/**
	 * Reopen a resolved comment.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Comment public id.
	 * @return array<string, mixed>|null
	 */
	public function reopen( $workspace_id, $public_id ) {
		$comment = $this->get( $workspace_id, $public_id );
		if ( null === $comment || 'resolved' !== (string) $comment['status'] ) {
			return null;
		}
		$this->update_row( (string) $public_id, array( 'status' => 'reopened', 'resolved_by' => 0, 'resolved_at' => null ) );
		return $this->get( $workspace_id, $public_id );
	}

	/**
	 * Resolve a comment and every open reply beneath it.
	 *
	 * Resolving the root but leaving three open replies produces a thread that reads as
	 * unresolved while appearing closed. The replies are resolved too, with the same
	 * resolver and timestamp, so the thread has one consistent state.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $public_id    Root comment public id.
	 * @param int    $actor_id     Resolver.
	 * @return array<string, mixed>|null
	 */
	public function resolve_thread( $workspace_id, $public_id, $actor_id = 0 ) {
		$thread = $this->thread( $workspace_id, $public_id );
		$root   = $this->get( $workspace_id, $public_id );
		if ( null === $root || ! in_array( (string) $root['status'], array( 'open', 'reopened' ), true ) ) {
			return null;
		}

		$this->resolve( $workspace_id, $public_id, $actor_id );

		foreach ( $this->flatten( $thread['items'] ) as $comment ) {
			if ( in_array( (string) $comment['status'], array( 'open', 'reopened' ), true ) ) {
				$this->resolve( $workspace_id, (string) $comment['public_id'], $actor_id );
			}
		}

		return $this->thread( $workspace_id, $public_id );
	}

	/* ---------------------------------------------------------------------
	 * Anchors
	 * ------------------------------------------------------------------ */

	/**
	 * Describe what a comment is anchored to.
	 *
	 * §20: "Prefer stable component IDs whenever available" and treat coordinates as
	 * supplemental. This returns which of those a comment actually has, so the caller can
	 * present an anchored comment as anchored and a coordinate-only comment as a
	 * pinpoint rather than implying an attachment it does not have.
	 *
	 * @param array<string, mixed> $comment Comment.
	 * @return array<string, mixed>
	 */
	public static function anchored( array $comment ) {
		$stable = '';
		$kind   = 'project';

		foreach ( array(
			'element_id'    => 'element',
			'component_id'  => 'component',
			'section_id'    => 'section',
			'difference_id' => 'difference',
			'mapping_id'    => 'content_mapping',
		) as $column => $label ) {
			if ( '' !== (string) ( $comment[ $column ] ?? '' ) ) {
				$stable = (string) $comment[ $column ];
				$kind   = $label;
				break;
			}
		}

		$has_region = ( null !== ( $comment['region_x'] ?? null ) )
			&& ( null !== ( $comment['region_y'] ?? null ) )
			&& ( null !== ( $comment['region_width'] ?? null ) )
			&& ( null !== ( $comment['region_height'] ?? null ) );

		// `page_id` is a stable anchor too, and is checked last because it is the coarsest.
		if ( '' === $stable && (int) ( $comment['page_id'] ?? 0 ) > 0 ) {
			$stable = (string) (int) $comment['page_id'];
			$kind   = 'page';
		}

		return array(
			'kind'         => $kind,
			'stable_id'    => $stable,
			'has_stable'   => ( '' !== $stable ),
			'has_region'   => $has_region,
			'viewport'     => (string) ( $comment['viewport'] ?? '' ),
			'version_id'   => (string) ( $comment['version_id'] ?? '' ),
			// A comment with neither a stable anchor nor a region can only be read as
			// "about this project", and the caller is told so rather than being left to
			// render a marker at an unknown place.
			'reanchorable' => ( '' !== $stable ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Flatten a nested thread into a list.
	 *
	 * @param array<int, array<string, mixed>> $comments Nested comments.
	 * @param int                              $depth    Current depth.
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten( array $comments, $depth = 0 ) {
		if ( $depth >= Workspace_Limits::MAX_COMMENT_DEPTH ) {
			return array();
		}
		$out = array();
		foreach ( $comments as $comment ) {
			$out[] = $comment;
			$replies = (array) ( $comment['replies'] ?? array() );
			if ( array() !== $replies ) {
				$out = array_merge( $out, $this->flatten( $replies, $depth + 1 ) );
			}
		}
		return $out;
	}

	/**
	 * Resolve mentioned user ids from an `@`-prefixed list of usernames or ids.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param string $mentions     Raw mention text.
	 * @return array<int, int>
	 */
	private function resolve_mentions( $workspace_id, $mentions ) {
		$mentions = trim( (string) $mentions );
		if ( '' === $mentions ) {
			return array();
		}

		$store = new Workspace_Member_Store();
		$ids   = array();

		foreach ( preg_split( '/[\s,]+/', $mentions ) as $token ) {
			$token = ltrim( trim( (string) $token ), '@' );
			if ( '' === $token ) {
				continue;
			}

			$user = ctype_digit( $token ) ? get_userdata( (int) $token ) : get_user_by( 'login', $token );
			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			// A mention only notifies somebody who is in the workspace. Without this,
			// an author could name any account on the install and generate a notification
			// for a stranger, which is both a leak of the comment's existence and a way to
			// make the notification table grow without bound.
			if ( null === $store->membership( $workspace_id, (int) $user->ID ) ) {
				continue;
			}

			$ids[] = (int) $user->ID;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Return a display name for the author.
	 *
	 * @param int                  $user_id Author id.
	 * @param array<string, mixed> $data    Comment data.
	 * @return string
	 */
	private function author_name( $user_id, array $data ) {
		$supplied = trim( (string) ( $data['author_name'] ?? '' ) );
		if ( '' !== $supplied ) {
			// A client contact may have no account, so the name is supplied. It is stored
			// as the *record* of who wrote it; it is not an identity claim, and nothing
			// authorises on it.
			return substr( $supplied, 0, 120 );
		}
		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User ) {
				return substr( (string) $user->display_name, 0, 120 );
			}
		}
		return '';
	}

	/**
	 * Clean a stable identifier.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function clean_id( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		return substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $value ), 0, 64 );
	}

	/**
	 * Clean a viewport name.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function clean_viewport( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$known = array( 'desktop', 'tablet', 'mobile' );
		return in_array( $value, $known, true ) ? $value : '';
	}

	/**
	 * Clamp a region coordinate to a percentage.
	 *
	 * §20's coordinates come from a rendered screenshot and are stored as percentages of
	 * the viewport, not as pixels, so that a comment made at 390px wide can be drawn on a
	 * 1440px render. A pixel value would be meaningless across viewports, and a value
	 * outside 0-100 would place the marker off the region it describes.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private function clean_coordinate( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$number = (float) $value;
		if ( $number < 0 ) {
			return 0.0;
		}
		return ( $number > 100 ) ? 100.0 : round( $number, 3 );
	}

	/**
	 * Truncate a string, marking that it was cut.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum.
	 * @return string
	 */
	private function truncate( $text, $length ) {
		if ( strlen( $text ) <= $length ) {
			return $text;
		}
		return rtrim( substr( $text, 0, $length - 3 ) ) . '...';
	}
}
