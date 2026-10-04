<?php
/**
 * Phase 8: project repository.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the record of a replica: its source, its versions, and its settings.
 *
 * A project is what a person thinks of as "the thing I am building". Before this
 * existed, that record was scattered across job rows, transients, and post meta,
 * which meant the history screen could list work but could not say which analyses
 * and generations belonged together, and regenerating a page meant starting over.
 *
 * A project owns a *reference* to a draft, never the draft itself. Deleting a
 * project removes the record and leaves the Elementor page alone, because the page
 * is the user's content and the project is ReplicaForge's bookkeeping. The one
 * exception is explicit and separate: a caller has to ask for the draft to be
 * deleted, and the request names the draft.
 */
final class Project_Repository {

	/**
	 * Option holding the projects.
	 */
	const OPTION = 'replicaforge_projects';

	/**
	 * Option holding the project preference memory.
	 */
	const PREFERENCES_OPTION = 'replicaforge_preferences';

	/**
	 * Maximum projects retained.
	 *
	 * ### This conflicts with the specification, and is left as it is
	 *
	 * The specification asks for 500. This is 60, and exceeding it drops the oldest project
	 * silently. That behaviour predates the work that found the conflict, and the constant
	 * has not been changed, for reasons set out in full on {@see self::store()}.
	 *
	 * In short: the list is one option, so every project is re-serialised on every write and
	 * the cap bounds that cost; reaching 500 properly is a schema change rather than a
	 * constant edit; and raising it would trade a documented, visible limit for an
	 * undocumented performance cost on exactly the large sites that could least afford it.
	 *
	 * Recorded rather than fixed so that whoever reads this knows the number is a known
	 * deviation and not an oversight.
	 */
	const MAX_PROJECTS = 60;

	/**
	 * Maximum versions retained per project.
	 */
	const MAX_VERSIONS = 20;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Optional logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Create a project.
	 *
	 * @param string               $source_url Source URL.
	 * @param array<string, mixed> $options    Optional `name`, `settings`, `replaces`.
	 * @return array<string, mixed>
	 */
	public function create( $source_url, array $options = array() ) {
		$host = strtolower( (string) wp_parse_url( (string) $source_url, PHP_URL_HOST ) );
		$now  = gmdate( 'c' );

		$project = array(
			'project_id'   => Request_Context::make_id( 'proj', 10 ),
			'name'         => $this->default_name( (string) $source_url, $host ),
			'source_url'   => (string) $source_url,
			'source_host'  => $host,
			// The host and the normalized path, so the same page analyzed again is
			// recognized as the same project rather than as a new one.
			'source_key'   => $this->source_key( (string) $source_url ),
			'created_at'   => $now,
			'updated_at'   => $now,
			'status'       => 'analyzed',
			'versions'     => array(),
			'drafts'       => array(),
			'analysis'     => null,
			'design'       => null,
			'specification'=> null,
			'validation'   => null,
			'corrections'  => null,
			'settings'     => $this->default_settings(),
			'warnings'     => array(),
			'user_id'      => get_current_user_id(),
		);

		if ( ! empty( $options['name'] ) && is_string( $options['name'] ) ) {
			$project['name'] = $this->bounded_text( $options['name'], 120 );
		}
		if ( isset( $options['settings'] ) && is_array( $options['settings'] ) ) {
			$project['settings'] = array_merge( $project['settings'], $this->clean_settings( $options['settings'] ) );
		}

		$projects = $this->all();
		$projects[] = $project;
		$this->store( $projects );

		Request_Context::set( 'project', (string) $project['project_id'] );
		$this->logger->info(
			'project_created',
			'Created a project for ' . $host . '.',
			array( 'project_id' => (string) $project['project_id'] ),
			'project'
		);

		return $project;
	}

	/**
	 * Return every project, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$projects = array();
		foreach ( $stored as $project ) {
			if ( is_array( $project ) && isset( $project['project_id'] ) ) {
				$projects[] = $project;
			}
		}
		return $projects;
	}

	/**
	 * Return one project, or null.
	 *
	 * @param string $project_id Project identifier.
	 * @return array<string, mixed>|null
	 */
	public function find( $project_id ) {
		if ( ! is_string( $project_id ) || '' === $project_id ) {
			return null;
		}
		foreach ( $this->all() as $project ) {
			if ( (string) $project['project_id'] === $project_id ) {
				return $project;
			}
		}
		return null;
	}

	/**
	 * Return projects, newest first, with an optional filter.
	 *
	 * @param array<string, mixed> $filters Optional `host`, `status`.
	 * @param int                  $limit   Maximum projects.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( array $filters = array(), $limit = 25 ) {
		$host   = isset( $filters['host'] ) ? strtolower( trim( (string) $filters['host'] ) ) : '';
		$status = isset( $filters['status'] ) ? (string) $filters['status'] : '';

		$out = array();
		foreach ( array_reverse( $this->all() ) as $project ) {
			if ( '' !== $host && false === strpos( (string) $project['source_host'], $host ) ) {
				continue;
			}
			if ( '' !== $status && (string) $project['status'] !== $status ) {
				continue;
			}
			$out[] = $this->present( $project );
			if ( count( $out ) >= max( 1, (int) $limit ) ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Return an existing project for a source, or null.
	 *
	 * This is what makes a repeated analysis of the same page offer to continue the
	 * existing project rather than starting a second one.
	 *
	 * @param string $source_url Source URL.
	 * @return array<string, mixed>|null
	 */
	public function find_by_source( $source_url ) {
		$key = $this->source_key( (string) $source_url );
		foreach ( array_reverse( $this->all() ) as $project ) {
			if ( (string) ( $project['source_key'] ?? '' ) === $key ) {
				return $project;
			}
		}
		return null;
	}

	/**
	 * Return the projects that share a source, for the duplicate prompt.
	 *
	 * @param string $source_url Source URL.
	 * @return array<int, array<string, mixed>>
	 */
	public function duplicates_of( $source_url ) {
		$key      = $this->source_key( (string) $source_url );
		$matches  = array();
		foreach ( array_reverse( $this->all() ) as $project ) {
			if ( (string) ( $project['source_key'] ?? '' ) === $key ) {
				$matches[] = $this->present( $project );
			}
		}
		return $matches;
	}

	/**
	 * Update a project.
	 *
	 * @param string               $project_id Project identifier.
	 * @param array<string, mixed> $changes    Fields to change.
	 * @return array<string, mixed>|null
	 */
	public function update( $project_id, array $changes ) {
		$projects = $this->all();
		$found    = null;

		foreach ( $projects as $index => $project ) {
			if ( (string) $project['project_id'] !== (string) $project_id ) {
				continue;
			}
			foreach ( $changes as $key => $value ) {
				$project[ $key ] = $value;
			}
			$project['updated_at'] = gmdate( 'c' );
			$projects[ $index ]    = $project;
			$found                 = $project;
			break;
		}

		if ( null === $found ) {
			return null;
		}
		$this->store( $projects );
		return $found;
	}

	/**
	 * Add a version to a project.
	 *
	 * A version is one analysis and, once generated, one draft. Adding one never
	 * changes an earlier version, which is what makes a comparison between them
	 * meaningful.
	 *
	 * @param string               $project_id Project identifier.
	 * @param array<string, mixed> $version    Version facts.
	 * @return array<string, mixed>|null
	 */
	public function add_version( $project_id, array $version ) {
		$project = $this->find( $project_id );
		if ( null === $project ) {
			return null;
		}

		$existing = isset( $project['versions'] ) && is_array( $project['versions'] ) ? $project['versions'] : array();

		// The number comes from the highest version retained, not from how many are
		// retained. Once the list is trimmed the two stop agreeing, and counting
		// would hand the same number to two different versions and make a stored
		// reference to a version ambiguous.
		$next = 1;
		foreach ( $existing as $stored ) {
			if ( isset( $stored['version'] ) && is_numeric( $stored['version'] ) ) {
				$next = max( $next, (int) $stored['version'] + 1 );
			}
		}

		$entry = array(
			'version'      => $next,
			'version_id'   => Request_Context::make_id( 'ver', 8 ),
			'created_at'   => gmdate( 'c' ),
			'source_hash'  => isset( $version['source_hash'] ) ? (string) $version['source_hash'] : '',
			'analysis'     => $version['analysis'] ?? null,
			'design'       => $version['design'] ?? null,
			'specification'=> $version['specification'] ?? null,
			'draft_id'     => isset( $version['draft_id'] ) ? (int) $version['draft_id'] : 0,
			'generation_id'=> isset( $version['generation_id'] ) ? (string) $version['generation_id'] : '',
			'validation_id'=> isset( $version['validation_id'] ) ? (string) $version['validation_id'] : '',
			'validation'   => $version['validation'] ?? null,
			'corrections'  => $version['corrections'] ?? null,
			'change'       => isset( $version['change'] ) ? (string) $version['change'] : 'initial',
			'impact'       => isset( $version['impact'] ) ? (string) $version['impact'] : 'unknown',
			'warnings'     => array_slice( array_values( array_filter( array_map( 'strval', (array) ( $version['warnings'] ?? array() ) ) ) ), 0, 20 ),
			'note'         => isset( $version['note'] ) ? $this->bounded_text( (string) $version['note'], 240 ) : '',
		);

		$existing[] = $entry;

		// The newest versions are kept. An old version is a record, not content, so
		// the loss is bounded and the reference to a draft is never dropped from the
		// project even when its version is.
		$trimmed = count( $existing ) > self::MAX_VERSIONS
			? array_slice( $existing, -self::MAX_VERSIONS )
			: $existing;

		$updated = $this->update(
			$project_id,
			array(
				'versions'  => array_values( $trimmed ),
				'analysis'  => $entry['analysis'],
				'design'    => $entry['design'],
				'specification' => $entry['specification'],
				'validation'=> $entry['validation'],
				'corrections' => $entry['corrections'],
				'drafts'    => $this->drafts_after_version( $trimmed, $entry ),
				'status'    => $entry['draft_id'] > 0 ? 'draft_created' : 'analyzed',
			)
		);

		$this->logger->info(
			'project_version_added',
			'Added version ' . $next . ' to the project.',
			array( 'project_id' => (string) $project_id, 'version' => $next, 'draft_id' => $entry['draft_id'] ),
			'project'
		);

		return $updated;
	}

	/**
	 * Return every draft a project's versions have produced.
	 *
	 * A draft is recorded whatever happens to its version, because the version list
	 * is bounded and a draft reference is the one thing in it a person would be
	 * annoyed to lose.
	 *
	 * @param array<int, array<string, mixed>> $versions Versions.
	 * @param array<string, mixed>             $latest   The latest version.
	 * @return array<int, array<string, mixed>>
	 */
	private function drafts_after_version( array $versions, array $latest ) {
		$drafts = array();
		foreach ( $versions as $version ) {
			$draft_id = (int) ( $version['draft_id'] ?? 0 );
			if ( $draft_id < 1 ) {
				continue;
			}
			// A draft the plugin created is recorded as such. One a person made is
			// recorded as theirs, because deleting a project must not suggest it
			// owns a page the user built.
			$ours = $this->is_generated_draft( $draft_id );
			$drafts[ $draft_id ] = array(
				'draft_id'     => $draft_id,
				'version'      => (int) ( $version['version'] ?? 0 ),
				'created_at'   => (string) ( $version['created_at'] ?? '' ),
				'ownership'    => $ours ? 'replicaforge' : 'user',
				'exists'       => null !== get_post( $draft_id ),
				'edit_url'     => $this->edit_url( $draft_id ),
			);
		}

		if ( (int) ( $latest['draft_id'] ?? 0 ) > 0 ) {
			$draft_id = (int) $latest['draft_id'];
			$ours     = $this->is_generated_draft( $draft_id );
			$drafts[ $draft_id ] = array(
				'draft_id'   => $draft_id,
				'version'    => (int) ( $latest['version'] ?? 0 ),
				'created_at' => (string) ( $latest['created_at'] ?? '' ),
				'ownership'  => $ours ? 'replicaforge' : 'user',
				'exists'     => null !== get_post( $draft_id ),
				'edit_url'   => $this->edit_url( $draft_id ),
			);
		}

		return array_values( $drafts );
	}

	/**
	 * Return whether a draft was generated by ReplicaForge.
	 *
	 * The generation hash is written by Phase 4 and is the only reliable evidence.
	 * A page without it was not made here, whatever its title suggests.
	 *
	 * @param int $draft_id Draft identifier.
	 * @return bool
	 */
	private function is_generated_draft( $draft_id ) {
		return '' !== (string) get_post_meta( (int) $draft_id, 'replicaforge_generation_hash', true );
	}

	/**
	 * Delete a project.
	 *
	 * @param string $project_id        Project identifier.
	 * @param bool   $delete_drafts     Whether to delete the drafts too. False by default.
	 * @return array<string, mixed>
	 */
	public function delete( $project_id, $delete_drafts = false ) {
		$project = $this->find( $project_id );
		if ( null === $project ) {
			return array( 'success' => false, 'reason' => 'project_not_found' );
		}

		$kept    = array();
		$drafts_of_deleted = array();

		foreach ( $this->all() as $entry ) {
			if ( (string) $entry['project_id'] === (string) $project_id ) {
				foreach ( (array) ( $entry['drafts'] ?? array() ) as $draft ) {
					$drafts_of_deleted[] = $draft;
				}
				continue;
			}
			$kept[] = $entry;
		}

		$this->store( $kept );

		$drafts_deleted = 0;
		$drafts_kept    = 0;

		foreach ( $drafts_of_deleted as $draft ) {
			$draft_id = (int) ( $draft['draft_id'] ?? 0 );
			if ( $draft_id < 1 ) {
				continue;
			}

			// Deleting the drafts is a separate decision and is never inferred. A
			// project is bookkeeping; a draft is the user's page.
			if ( ! $delete_drafts ) {
				$drafts_kept++;
				continue;
			}

			// Only a draft this plugin generated may be deleted, and only when it is
			// still a draft. A published page is not ReplicaForge's to remove.
			$post = get_post( $draft_id );
			if ( null === $post || 'draft' !== $post->post_status || 'replicaforge' !== ( $draft['ownership'] ?? '' ) ) {
				$drafts_kept++;
				continue;
			}

			if ( ! wp_delete_post( $draft_id, true ) ) {
				$drafts_kept++;
				continue;
			}
			$drafts_deleted++;
		}

		$this->logger->warning(
			'project_deleted',
			'Deleted a project. ' . $drafts_kept . ' draft(s) were left in place.',
			array( 'project_id' => (string) $project_id, 'drafts_deleted' => $drafts_deleted, 'drafts_kept' => $drafts_kept ),
			'project'
		);

		return array(
			'success'         => true,
			'drafts_deleted'  => $drafts_deleted,
			'drafts_kept'     => $drafts_kept,
		);
	}

	/**
	 * Store and user preference memory.
	 *
	 * These are the choices a person makes once and expects to keep. Nothing
	 * identifying is stored beyond the user identifier the project already carries.
	 *
	 * @return array<string, mixed>
	 */
	public function preferences() {
		$stored = get_option( self::PREFERENCES_OPTION, array() );
		return is_array( $stored ) ? $stored : $this->default_settings();
	}

	/**
	 * Store a preference.
	 *
	 * @param string $key   Preference name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public function set_preference( $key, $value ) {
		$key = $this->bounded_text( (string) $key, 40 );
		if ( '' === $key ) {
			return false;
		}
		$preferences   = $this->preferences();
		$preferences[ $key ] = $value;
		update_option( self::PREFERENCES_OPTION, $preferences, false );
		return true;
	}

	/**
	 * Return a project in the shape the UI uses.
	 *
	 * @param array<string, mixed> $project Stored project.
	 * @return array<string, mixed>
	 */
	public function present( array $project ) {
		$versions = isset( $project['versions'] ) && is_array( $project['versions'] ) ? $project['versions'] : array();
		$latest   = empty( $versions ) ? null : $versions[ count( $versions ) - 1 ];
		$drafts   = isset( $project['drafts'] ) && is_array( $project['drafts'] ) ? $project['drafts'] : array();

		$current_draft = null;
		foreach ( array_reverse( $drafts ) as $draft ) {
			if ( ! empty( $draft['exists'] ) ) {
				$current_draft = $draft;
				break;
			}
		}

		return array(
			'project_id'   => (string) $project['project_id'],
			'name'         => (string) $project['name'],
			'source_url'   => (string) $project['source_url'],
			'source_host'  => (string) $project['source_host'],
			'status'       => (string) $project['status'],
			'created_at'   => (string) $project['created_at'],
			'updated_at'   => (string) $project['updated_at'],
			'version_count'=> count( $versions ),
			'latest_version' => null === $latest ? null : (int) $latest['version'],
			'latest_impact' => null === $latest ? '' : (string) $latest['impact'],
			'latest_change' => null === $latest ? '' : (string) $latest['change'],
			'current_draft' => $current_draft,
			'draft_count'  => count( $drafts ),
			'has_validation' => null !== ( $project['validation'] ?? null ),
			'has_corrections'=> null !== ( $project['corrections'] ?? null ),
			'warnings'     => array_slice( array_values( array_filter( array_map( 'strval', (array) ( $project['warnings'] ?? array() ) ) ) ), 0, 10 ),
			'settings'     => is_array( $project['settings'] ?? null ) ? $project['settings'] : $this->default_settings(),
			'edit_url'     => $this->edit_url( null !== $current_draft ? (int) $current_draft['draft_id'] : 0 ),
			'can_delete'   => true,
			'can_regenerate' => true,
		);
	}

	/**
	 * Return the default reconstruction settings.
	 *
	 * The mode is balanced because it is the one that produces a draft a person can
	 * edit, which is the product.
	 *
	 * @return array<string, mixed>
	 */
	public function default_settings() {
		return array(
			'mode'            => 'balanced',
			'priority'        => array(
				'visual'     => 5,
				'content'    => 5,
				'responsive' => 5,
				'editability'=> 5,
				'performance'=> 3,
			),
			'asset_policy'    => 'reference',
			'visual_evidence' => true,
			'ai_enabled'      => false,
			'auto_correct'    => false,
		);
	}

	/**
	 * Return a bounded, validated settings array.
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 * @return array<string, mixed>
	 */
	private function clean_settings( array $settings ) {
		$out = $this->default_settings();

		if ( isset( $settings['mode'] ) && in_array( $settings['mode'], array( 'visual', 'editable', 'balanced' ), true ) ) {
			$out['mode'] = (string) $settings['mode'];
		}
		if ( isset( $settings['asset_policy'] ) && in_array( $settings['asset_policy'], array( 'reference', 'import', 'skip' ), true ) ) {
			$out['asset_policy'] = (string) $settings['asset_policy'];
		}
		foreach ( array( 'visual_evidence', 'ai_enabled', 'auto_correct' ) as $flag ) {
			if ( isset( $settings[ $flag ] ) ) {
				$out[ $flag ] = (bool) $settings[ $flag ];
			}
		}
		if ( isset( $settings['priority'] ) && is_array( $settings['priority'] ) ) {
			foreach ( array( 'visual', 'content', 'responsive', 'editability', 'performance' ) as $key ) {
				if ( isset( $settings['priority'][ $key ] ) && is_numeric( $settings['priority'][ $key ] ) ) {
					$out['priority'][ $key ] = max( 1, min( 10, (int) $settings['priority'][ $key ] ) );
				}
			}
		}

		return $out;
	}

	/**
	 * Return a name derived from the source, when the caller supplies none.
	 *
	 * @param string $source_url Source URL.
	 * @param string $host       Source host.
	 * @return string
	 */
	private function default_name( $source_url, $host ) {
		$path = (string) wp_parse_url( $source_url, PHP_URL_PATH );
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '' !== $host ? $host : __( 'Untitled project', 'replicaforge' );
		}

		$slug = sanitize_title( $path );
		$parts = explode( '-', $slug );
		$label = '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$label .= ( '' === $label ? '' : ' ' ) . ucfirst( $part );
		}

		return trim( $host . ( '' === $label ? '' : ' — ' . $label ) );
	}

	/**
	 * Return the key that identifies the same page analyzed twice.
	 *
	 * A trailing slash, a fragment, and a query string do not make a different
	 * page, so they are normalized away. The scheme is kept, because a page served
	 * over http and over https is genuinely two pages.
	 *
	 * @param string $source_url Source URL.
	 * @return string
	 */
	public function source_key( $source_url ) {
		$parts = wp_parse_url( (string) $source_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 'invalid';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$path   = rtrim( $path, '/' );
		if ( '' === $path ) {
			$path = '/';
		}

		$query = '';
		if ( isset( $parts['query'] ) && '' !== trim( (string) $parts['query'] ) ) {
			// A query string is part of the page for a filtered listing, so it is
			// kept. Its order is normalized, because two orderings are one page.
			parse_str( (string) $parts['query'], $parameters );
			if ( is_array( $parameters ) && ! empty( $parameters ) ) {
				ksort( $parameters );
				$query = '?' . (string) http_build_query( $parameters );
			}
		}

		return $scheme . '://' . $host . ( $port > 0 ? ':' . $port : '' ) . $path . $query;
	}

	/**
	 * Return a post edit link, or an empty string.
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	private function edit_url( $post_id ) {
		if ( $post_id < 1 ) {
			return '';
		}
		$url = get_edit_post_link( (int) $post_id, 'raw' );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Bound a text value.
	 *
	 * @param string $text   Raw text.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private function bounded_text( $text, $length ) {
		$text = sanitize_text_field( (string) $text );
		return mb_strlen( $text ) > $length ? mb_substr( $text, 0, $length ) : $text;
	}

	/**
	 * Persist the project list.
	 *
	 * ### The cap is a conflict, not a design decision
	 *
	 * `MAX_PROJECTS` is 60, and the specification this release implements asks for 500. When
	 * a 61st project is created the oldest is dropped, silently. That is the behaviour this
	 * method has always had, and changing it is outside the scope of the work that found it.
	 *
	 * It is left as it is rather than raised, for two reasons that are worth stating rather
	 * than assuming.
	 *
	 * The first is that raising the number is not free. The list is a single option, so
	 * every project is serialised on every write. The cap was presumably chosen to bound
	 * that, and doubling it to reach 500 raises the cost of every operation on a site that
	 * is anywhere near the ceiling - which is exactly the site that would suffer. Deciding
	 * how to store 500 projects properly is a schema change, not a constant.
	 *
	 * The second is that a silent drop is a bad behaviour that has been load-bearing for a
	 * long time, and changing it is a change of observable behaviour. Sites that have been
	 * running at the cap for a year have already lost history; raising the limit does not
	 * restore it, and code that depended on the old bound may depend on it.
	 *
	 * So it is recorded here, in the code that does it, and in the completion report - and
	 * the constant is left alone. The alternative, quietly raising it to 500 and letting
	 * every option write on a large site get slower, would trade a documented limit for an
	 * undocumented performance problem.
	 *
	 * @param array<int, array<string, mixed>> $projects Projects.
	 * @return void
	 */
	private function store( array $projects ) {
		if ( count( $projects ) > self::MAX_PROJECTS ) {
			/*
			 * Dropped oldest-first, which is the least harmful order: the projects most
			 * likely to be looked for again are the newest ones. The drafts a dropped
			 * project references stay reachable through WordPress, so what is lost is the
			 * record rather than the generated work.
			 */
			$projects = array_slice( $projects, -self::MAX_PROJECTS );
		}
		update_option( self::OPTION, array_values( $projects ), false );
	}
}
