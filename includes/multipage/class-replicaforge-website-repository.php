<?php
/**
 * Phase 12: the multi-page project store.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Stores a website project's pages, and the per-page record §2 specifies.
 *
 * ### Why an option and not a table
 *
 * The plugin has never had a database table, and adding one in Phase 12 would mean
 * a schema migration, an upgrade path, and a dbDelta version bump for a payload
 * that is naturally a document. A website is a bounded set of pages (at most
 * {@see Site_Limits::MAX_PAGES}) and one option per project keeps that visible and
 * inspectable.
 *
 * The cost is real and worth stating: this is not a query language. Finding every
 * page of type `product` scans the project. At a hundred pages that is a few
 * thousand array reads, which is nothing — and if a project ever needed thousands
 * of pages the plan limits would have stopped it long before.
 *
 * ### Page identity is derived from the URL, not stored
 *
 * §2 lists `page_id` alongside `source_url`. Storing both invites them to
 * disagree. So the id is *computed* from the canonical source URL by
 * {@see self::page_id_for()} and is not a stored field — a page cannot have a
 * different id than its own address implies.
 */
final class Website_Repository {

	/**
	 * Store option.
	 *
	 * @var string
	 */
	const OPTION = 'replicaforge_websites';

	/**
	 * Maximum projects retained.
	 *
	 * @var int
	 */
	const MAX_PROJECTS = 30;

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
	 * Return the derived page identifier for a source URL.
	 *
	 * @param string $source_url Source URL.
	 * @return string
	 */
	public static function page_id_for( $source_url ) {
		$parts = wp_parse_url( (string) $source_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		$path = (string) ( $parts['path'] ?? '/' );
		$path = ( '' === $path ) ? '/' : rtrim( $path, '/' );
		$path = ( '/' === $path ) ? '' : $path;

		$key = $host . $path;
		if ( ! empty( $parts['query'] ) ) {
			$key .= '?' . (string) $parts['query'];
		}

		return 'page_' . substr( hash( 'sha256', $key ), 0, 12 );
	}

	/**
	 * Read a project's pages.
	 *
	 * @param string $project_id Project identifier.
	 * @return array<string, array<string, mixed>>
	 */
	public function pages( $project_id ) {
		$all = $this->all();
		$id  = (string) $project_id;
		return ( isset( $all[ $id ]['pages'] ) && is_array( $all[ $id ]['pages'] ) ) ? $all[ $id ]['pages'] : array();
	}

	/**
	 * Read a project's metadata.
	 *
	 * @param string $project_id Project identifier.
	 * @return array<string, mixed>
	 */
	public function project( $project_id ) {
		$all = $this->all();
		$id  = (string) $project_id;
		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
	}

	/**
	 * Return every stored project id.
	 *
	 * @return array<int, string>
	 */
	public function project_ids() {
		return array_keys( $this->all() );
	}

	/**
	 * Create or replace a project's pages.
	 *
	 * @param string                            $project_id Project identifier.
	 * @param array<int, array<string, mixed>>  $pages      Page records.
	 * @param array<string, mixed>              $meta       Project metadata.
	 * @return array<string, mixed>
	 */
	public function save_pages( $project_id, array $pages, array $meta = array() ) {
		$project_id = (string) $project_id;
		if ( '' === $project_id ) {
			return array(
				'success' => false,
				'message' => __( 'A project identifier is required.', 'replicaforge' ),
			);
		}

		$all  = $this->all();
		$kept = array();

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) || empty( $page['source_url'] ) ) {
				continue;
			}
			$page_id = self::page_id_for( (string) $page['source_url'] );
			if ( '' === $page_id ) {
				continue;
			}
			if ( isset( $kept[ $page_id ] ) ) {
				continue;
			}

			$existing = isset( $all[ $project_id ]['pages'][ $page_id ] ) ? $all[ $project_id ]['pages'][ $page_id ] : array();

			// `type` is what the classifier emits; `page_type` is what the record
			// stores. Both are accepted so a caller does not have to know which.
			$type      = (string) ( $page['type'] ?? ( $page['page_type'] ?? '' ) );
			$type      = Site_Limits::is_page_type( $type ) ? $type : 'custom';

			$kept[ $page_id ] = array(
				'page_id'               => $page_id,
				'source_url'            => (string) $page['source_url'],
				'canonical_url'         => (string) ( $page['canonical_url'] ?? $page['source_url'] ),
				'title'                 => (string) ( $page['title'] ?? '' ),
				'page_type'             => $type,
				'type_confidence'       => isset( $page['confidence'] ) ? (float) $page['confidence'] : 0.0,
				'status'                => $this->normalise_status( $page['status'] ?? 'pending', $existing ),
				'analysis_version'      => (string) ( $page['analysis_version'] ?? ( $existing['analysis_version'] ?? '' ) ),
				'reconstruction_version' => (string) ( $page['reconstruction_version'] ?? ( $existing['reconstruction_version'] ?? '' ) ),
				'elementor_document_id' => (int) ( $page['elementor_document_id'] ?? ( $existing['elementor_document_id'] ?? 0 ) ),
				'post_id'               => (int) ( $page['post_id'] ?? ( $existing['post_id'] ?? 0 ) ),
				'source_hash'           => (string) ( $page['source_hash'] ?? ( $existing['source_hash'] ?? '' ) ),
				'generated_hash'        => (string) ( $page['generated_hash'] ?? ( $existing['generated_hash'] ?? '' ) ),
				'last_validated_at'     => (int) ( $page['last_validated_at'] ?? ( $existing['last_validated_at'] ?? 0 ) ),
				'error'                 => (string) ( $page['error'] ?? ( $existing['error'] ?? '' ) ),
				'priority'              => (int) ( $page['priority'] ?? ( $existing['priority'] ?? Page_Classifier::priority_for( $type ) ) ),
				'selected'              => ! empty( $page['selected'] ),
				'via'                   => (string) ( $page['via'] ?? ( $existing['via'] ?? 'link' ) ),
				'depth'                 => (int) ( $page['depth'] ?? ( $existing['depth'] ?? 0 ) ),
				'overridden'            => ! empty( $existing['overridden'] ),
			);
		}

		if ( count( $kept ) > Site_Limits::MAX_PAGES ) {
			$kept = array_slice( $kept, 0, Site_Limits::MAX_PAGES, true );
		}

		$all[ $project_id ] = array(
			'project_id'  => $project_id,
			'updated_at'  => time(),
			'pages'       => $kept,
			'meta'        => array_merge( (array) ( $all[ $project_id ]['meta'] ?? array() ), $meta ),
		);

		if ( count( $all ) > self::MAX_PROJECTS ) {
			$all = array_slice( $all, -self::MAX_PROJECTS, null, true );
		}

		update_option( self::OPTION, $all, false );

		return array(
			'success' => true,
			'pages'   => count( $kept ),
			'ids'     => array_keys( $kept ),
		);
	}

	/**
	 * Set one page's status.
	 *
	 * @param string               $project_id Project identifier.
	 * @param string               $page_id    Page identifier.
	 * @param array<string, mixed> $changes     Fields to change.
	 * @return array<string, mixed>
	 */
	public function update_page( $project_id, $page_id, array $changes ) {
		$all = $this->all();
		$key = (string) $project_id;
		$id  = (string) $page_id;

		if ( ! isset( $all[ $key ]['pages'][ $id ] ) ) {
			return array(
				'success' => false,
				'message' => __( 'That page is not in this project.', 'replicaforge' ),
			);
		}

		foreach ( $changes as $field => $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				continue;
			}
			$all[ $key ]['pages'][ $id ][ (string) $field ] = $value;
		}
		$all[ $key ]['updated_at'] = time();

		update_option( self::OPTION, $all, false );

		return array(
			'success' => true,
			'page'    => $all[ $key ]['pages'][ $id ],
		);
	}

	/**
	 * Record a user override on a page, so a later sync does not replace it.
	 *
	 * @param string $project_id Project identifier.
	 * @param string $page_id    Page identifier.
	 * @param string $reason     Why.
	 * @return array<string, mixed>
	 */
	public function mark_overridden( $project_id, $page_id, $reason = '' ) {
		return $this->update_page( $project_id, $page_id, array(
			'overridden'     => true,
			'override_reason'=> (string) $reason,
		) );
	}

	/**
	 * Delete a project.
	 *
	 * @param string $project_id Project identifier.
	 * @return array<string, mixed>
	 */
	public function delete( $project_id ) {
		$all = $this->all();
		$key = (string) $project_id;
		$had = isset( $all[ $key ] );
		unset( $all[ $key ] );
		update_option( self::OPTION, $all, false );

		return array(
			'success'  => $had,
			'message'  => $had
				? __( 'The project\'s page records were removed. Generated drafts were left alone.', 'replicaforge' )
				: __( 'That project was not in the store.', 'replicaforge' ),
		);
	}

	/**
	 * Return the selected pages of a project.
	 *
	 * @param string $project_id Project identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function selected( $project_id ) {
		$out = array();
		foreach ( $this->pages( $project_id ) as $page ) {
			if ( ! empty( $page['selected'] ) ) {
				$out[] = $page;
			}
		}
		usort(
			$out,
			static function ( $left, $right ) {
				$lp = (int) ( $left['priority'] ?? 0 );
				$rp = (int) ( $right['priority'] ?? 0 );
				if ( $lp === $rp ) {
					return strcmp( (string) $left['page_id'], (string) $right['page_id'] );
				}
				return ( $lp > $rp ) ? -1 : 1;
			}
		);
		return $out;
	}

	/**
	 * Return a summary of a project, for a list screen.
	 *
	 * @param string $project_id Project identifier.
	 * @return array<string, mixed>
	 */
	public function summary( $project_id ) {
		$pages  = $this->pages( $project_id );
		$counts = array(
			'total'     => count( $pages ),
			'selected'  => 0,
			'analyzed'  => 0,
			'generated' => 0,
			'validated' => 0,
			'failed'    => 0,
			'review'    => 0,
		);
		$types = array();

		foreach ( $pages as $page ) {
			if ( ! empty( $page['selected'] ) ) { $counts['selected']++; }
			$status = (string) ( $page['status'] ?? 'pending' );
			if ( 'analyzed' === $status || 'selected' === $status ) { $counts['analyzed']++; }
			if ( 'generated' === $status ) { $counts['generated']++; }
			if ( 'validated' === $status ) { $counts['validated']++; }
			if ( 'failed' === $status ) { $counts['failed']++; }
			if ( 'needs_review' === $status ) { $counts['review']++; }

			$type = (string) ( $page['page_type'] ?? 'custom' );
			$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
		}

		arsort( $types );

		return array(
			'project_id' => (string) $project_id,
			'counts'     => $counts,
			'by_type'    => $types,
			'updated_at' => (int) ( $this->project( $project_id )['updated_at'] ?? 0 ),
		);
	}

	/**
	 * Store the website specification for a project.
	 *
	 * @param string               $project_id     Project identifier.
	 * @param array<string, mixed> $specification  Specification.
	 * @return array<string, mixed>
	 */
	public function save_specification( $project_id, array $specification ) {
		$all = $this->all();
		$key = (string) $project_id;

		$all[ $key ]                 = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
		$all[ $key ]['project_id']   = $key;
		$all[ $key ]['specification']= $specification;
		$all[ $key ]['updated_at']   = time();

		update_option( self::OPTION, $all, false );

		return array( 'success' => true );
	}

	/**
	 * Read a project's specification.
	 *
	 * @param string $project_id Project identifier.
	 * @return array<string, mixed>
	 */
	public function specification( $project_id ) {
		$all = $this->all();
		$key = (string) $project_id;
		return ( isset( $all[ $key ]['specification'] ) && is_array( $all[ $key ]['specification'] ) ) ? $all[ $key ]['specification'] : array();
	}

	/**
	 * Normalise a page status, preserving a more advanced state.
	 *
	 * A re-analysis must not move a page that has already been generated back to
	 * `analyzed`. Progress is monotone unless something explicitly resets it.
	 *
	 * @param string               $requested Requested status.
	 * @param array<string, mixed> $existing  Existing record.
	 * @return string
	 */
	private function normalise_status( $requested, array $existing ) {
		$requested = is_string( $requested ) ? strtolower( trim( $requested ) ) : 'pending';
		$valid     = array( 'pending', 'analyzed', 'selected', 'generating', 'generated', 'validated', 'needs_review', 'failed', 'excluded' );
		if ( ! in_array( $requested, $valid, true ) ) {
			$requested = 'pending';
		}

		$order = array( 'pending' => 0, 'analyzed' => 1, 'selected' => 2, 'generating' => 3, 'generated' => 4, 'validated' => 5 );
		$current = (string) ( $existing['status'] ?? 'pending' );

		// A failure or a review flag is a deliberate state and is only left by
		// another explicit change.
		if ( in_array( $current, array( 'failed', 'needs_review' ), true ) && 'pending' !== $requested ) {
			$requested = $current;
		}

		if ( ! isset( $order[ $current ] ) || ! isset( $order[ $requested ] ) ) {
			return $requested;
		}

		return ( $order[ $requested ] >= $order[ $current ] ) ? $requested : $current;
	}

	/**
	 * Read the whole store.
	 *
	 * @return array<string, mixed>
	 */
	private function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}
}
