<?php
/**
 * Phase 14: content caching and provenance storage.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Caches content analysis, plans, and the §14 provenance record.
 *
 * ### What belongs in a content cache key
 *
 * §33 names the inputs: source content hash, destination schema hash, mapping engine
 * version, AI model, and prompt version. Every one of them is there because omitting it
 * returns a *plausible wrong answer* rather than a miss:
 *
 * - **source hash** — the page changed.
 * - **destination schema hash** — a field was renamed, a CPT was registered, or a store
 *   gained a product type. A cached plan built against the old schema names fields that
 *   no longer exist, and the first thing a user sees is a mapping screen full of errors.
 * - **engine version** — the rules in {@see Content_Mapper::RULES} changed.
 * - **AI model and prompt version** — only for AI-assisted plans, and only because a
 *   different model can legitimately produce a different suggestion. A *deterministic*
 *   plan's key deliberately omits them, so changing an AI provider does not invalidate
 *   every deterministic mapping in the database.
 *
 * ### Provenance is the reason this class stores anything
 *
 * §14 makes provenance a prerequisite for future synchronisation, and Phase 9's conflict
 * detection depends on it: a field whose provenance says `user_controlled` is never
 * overwritten, and a field whose provenance is missing cannot be judged at all. So the
 * record is written as part of the apply, not after it.
 *
 * It is also the one piece of content storage that must **outlive** the cache. Analysis
 * and plans expire; provenance does not, or a re-analysis would erase the evidence that
 * a user's edit is theirs.
 */
final class Content_Cache {

	/**
	 * Analysis cache index option.
	 *
	 * @var string
	 */
	const ANALYSIS_OPTION = 'replicaforge_content_cache';

	/**
	 * Provenance option prefix.
	 *
	 * @var string
	 */
	const PROVENANCE_PREFIX = 'replicaforge_content_provenance_';

	/**
	 * Plan store option prefix.
	 *
	 * @var string
	 */
	const PLAN_PREFIX = 'replicaforge_content_plan_';

	/**
	 * Content snapshot store option prefix.
	 *
	 * Separate from Phase 6's snapshot store, and it has to be. Phase 6 snapshots an
	 * **Elementor document** - its element tree and responsive overrides - and
	 * Correction_Snapshot::create() returns an error for a post with no Elementor
	 * data. Content mapping writes to post fields and product meta, which is exactly the
	 * case Phase 6 cannot snapshot, so reusing it alone meant that on a plain WordPress
	 * page - the most common destination there is - no snapshot was taken at all and
	 * [section]22's rollback had nothing to restore.
	 *
	 * These two are complementary, not duplicates: this one records the *values* of the
	 * fields about to change, Phase 6's records the *document* about to change.
	 *
	 * @var string
	 */
	const SNAPSHOT_PREFIX = 'replicaforge_content_snapshot_';

	/**
	 * Maximum cached analyses.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 60;

	/**
	 * Analysis TTL.
	 *
	 * A week. Long enough that re-opening a project does not re-analyse, short enough
	 * that a stale plan does not outlive a week of edits.
	 *
	 * @var int
	 */
	const TTL = 604800;

	// No MAX_PROVENANCE or MAX_PLANS here. Both bounds are declared once, in
	// Content_Limits, and read from there - see the note above for why a second copy is
	// worse than no copy at all.

	/**
	 * Build a cache key.
	 *
	 * @param string               $project_id Project id.
	 * @param array<string, mixed> $parts      Key parts.
	 * @return string
	 */
	public function key( $project_id, array $parts ) {
		$material = array(
			'project'        => (string) $project_id,
			'source_hash'    => (string) ( $parts['source_hash'] ?? '' ),
			'destination_hash' => (string) ( $parts['destination_hash'] ?? '' ),
			'engine'         => Content_Limits::ENGINE_VERSION,
			'schema'         => Content_Limits::SCHEMA_VERSION,
			'mode'           => (string) ( $parts['mode'] ?? '' ),
			'view'           => (string) ( $parts['view'] ?? '' ),
			'provider_versions' => (string) ( $parts['provider_versions'] ?? '' ),
			// Included only when a plan was AI-assisted. A deterministic plan's result
			// cannot depend on which model was configured, so including it unconditionally
			// would discard every valid cache entry the moment a provider key changed.
			'ai_model'       => (string) ( $parts['ai'] ?? '' ),
			'prompt_version' => (string) ( $parts['prompt_version'] ?? '' ),
		);

		return 'rfc_' . substr( hash( 'sha256', (string) wp_json_encode( $material ) ), 0, 32 );
	}

	/**
	 * Return a cached analysis.
	 *
	 * @param string $key Key.
	 * @return array<string, mixed>|null
	 */
	public function get( $key ) {
		$index = $this->index();
		$key   = (string) $key;
		if ( ! isset( $index[ $key ] ) ) {
			return null;
		}
		$entry = (array) $index[ $key ];
		if ( ( time() - (int) ( $entry['stored_at'] ?? 0 ) ) > (int) ( $entry['ttl'] ?? self::TTL ) ) {
			$this->forget( $key );
			return null;
		}
		$body = get_option( 'rfc_entry_' . $key, null );
		if ( null === $body ) {
			// A dangling index entry would report a hit that returns nothing, which is
			// worse than a miss.
			$this->forget( $key );
			return null;
		}
		return array( 'key' => $key, 'stored_at' => (int) $entry['stored_at'], 'body' => $body );
	}

	/**
	 * Store a cached analysis.
	 *
	 * @param string $key  Key.
	 * @param mixed  $body Body.
	 * @return bool
	 */
	public function put( $key, $body ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return false;
		}
		$index = $this->index();
		$index[ $key ] = array( 'stored_at' => time(), 'ttl' => self::TTL );

		if ( count( $index ) > self::MAX_ENTRIES ) {
			uasort( $index, static function ( $left, $right ) { return (int) $left['stored_at'] <=> (int) $right['stored_at']; } );
			foreach ( array_slice( $index, 0, count( $index ) - self::MAX_ENTRIES, true ) as $stale ) {
				delete_option( 'rfc_entry_' . $stale );
			}
			$index = array_slice( $index, -self::MAX_ENTRIES, null, true );
		}

		update_option( 'rfc_entry_' . $key, $body, false );
		update_option( self::ANALYSIS_OPTION, $index, false );
		return true;
	}

	/**
	 * Remove one cached entry.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function forget( $key ) {
		$key   = (string) $key;
		$index = $this->index();
		if ( ! isset( $index[ $key ] ) ) {
			return false;
		}
		delete_option( 'rfc_entry_' . $key );
		unset( $index[ $key ] );
		update_option( self::ANALYSIS_OPTION, $index, false );
		return true;
	}

	/**
	 * Record one §14 provenance entry.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return array<string, mixed>|null
	 */
	public function record_provenance( array $record ) {
		$project_id = (string) ( $record['project_id'] ?? '' );
		if ( '' === $project_id ) {
			return null;
		}

		$option = self::PROVENANCE_PREFIX . substr( hash( 'sha256', $project_id ), 0, 16 );
		$all    = get_option( $option, array() );
		$all    = is_array( $all ) ? $all : array();

		$key = $this->provenance_key( $record );
		$all[ $key ] = array_merge(
			array( 'recorded_at' => time(), 'expires' => time() + Content_Limits::PROVENANCE_TTL ),
			$record
		);

		if ( count( $all ) > Content_Limits::MAX_PROVENANCE ) {
			$all = array_slice( $all, -Content_Limits::MAX_PROVENANCE, null, true );
		}

		update_option( $option, $all, false );
		return $all[ $key ];
	}

	/**
	 * Return a project's provenance records.
	 *
	 * @param string $project_id  Project id.
	 * @param int    $destination Destination entity id, for a single-field lookup.
	 * @param string $field       Destination field, for a single-field lookup.
	 * @return array<int, array<string, mixed>>
	 */
	public function provenance( $project_id, $destination = 0, $field = '' ) {
		$option = self::PROVENANCE_PREFIX . substr( hash( 'sha256', (string) $project_id ), 0, 16 );
		$all    = get_option( $option, array() );
		$all    = is_array( $all ) ? $all : array();

		$destination = (int) $destination;
		$field       = (string) $field;
		if ( $destination < 1 && '' === $field ) {
			return array_values( $all );
		}

		$out = array();
		foreach ( $all as $record ) {
			if ( (int) ( $record['destination_id'] ?? 0 ) !== $destination ) {
				continue;
			}
			if ( '' !== $field && (string) ( $record['destination_field'] ?? '' ) !== $field ) {
				continue;
			}
			$out[] = $record;
		}
		return array_values( $out );
	}

	/**
	 * Store a mapping plan.
	 *
	 * @param string               $project_id Project id.
	 * @param array<string, mixed> $plan       Plan.
	 * @return bool
	 */
	public function store_plan( $project_id, array $plan ) {
		$option = self::PLAN_PREFIX . substr( hash( 'sha256', (string) $project_id ), 0, 16 );
		$all    = get_option( $option, array() );
		$all    = is_array( $all ) ? $all : array();

		$plan_id = (string) ( $plan['plan_id'] ?? '' );
		if ( '' === $plan_id ) {
			return false;
		}
		$all[ $plan_id ] = $plan;

		if ( count( $all ) > Content_Limits::MAX_PLANS ) {
			uasort( $all, static function ( $left, $right ) { return (int) ( $left['created_at'] ?? 0 ) <=> (int) ( $right['created_at'] ?? 0 ); } );
			$all = array_slice( $all, -Content_Limits::MAX_PLANS, null, true );
		}

		update_option( $option, $all, false );
		return true;
	}

	/**
	 * Return a stored plan.
	 *
	 * @param string $project_id Project id.
	 * @param string $plan_id    Plan id.
	 * @return array<string, mixed>|null
	 */
	public function plan( $project_id, $plan_id ) {
		$option = self::PLAN_PREFIX . substr( hash( 'sha256', (string) $project_id ), 0, 16 );
		$all    = get_option( $option, array() );
		if ( ! is_array( $all ) || ! isset( $all[ (string) $plan_id ] ) ) {
			return null;
		}
		return (array) $all[ (string) $plan_id ];
	}

	/**
	 * Return a project's stored plans.
	 *
	 * @param string $project_id Project id.
	 * @return array<int, array<string, mixed>>
	 */
	public function plans( $project_id ) {
		$option = self::PLAN_PREFIX . substr( hash( 'sha256', (string) $project_id ), 0, 16 );
		$all    = get_option( $option, array() );
		return is_array( $all ) ? array_values( $all ) : array();
	}

	/**
	 * Store a snapshot of the field values about to be overwritten.
	 *
	 * Deliberately minimal: one destination record, the values of the fields a plan
	 * names, and nothing else. A content snapshot is a *before* image of two or three
	 * scalars, and a snapshot format that grows to hold a whole document would be a
	 * second copy of what Phase 6 already stores properly.
	 *
	 * @param string               $project_id Project id.
	 * @param array<string, mixed> $record     Snapshot record.
	 * @return string Snapshot id, or an empty string when it could not be stored.
	 */
	public function store_snapshot( $project_id, array $record ) {
		$project_id = (string) $project_id;
		$entity_id  = (int) ( $record['entity_id'] ?? 0 );
		if ( '' === $project_id || $entity_id < 1 ) {
			// Without a project or a record there is nothing a restore could be scoped
			// to, and an unscoped restore is how the wrong field gets written back.
			return '';
		}

		$snapshot_id = 'cs_' . substr( hash( 'sha256', $project_id . '|' . $entity_id . '|' . (string) wp_json_encode( $record['values'] ?? array() ) . '|' . microtime( true ) ), 0, 24 );
		$record['snapshot_id'] = $snapshot_id;
		$record['project_id']  = $project_id;
		$record['taken_at']    = time();

		$option = self::SNAPSHOT_PREFIX . substr( hash( 'sha256', $project_id ), 0, 16 );
		$all    = get_option( $option, array() );
		$all    = is_array( $all ) ? $all : array();
		$all[ $snapshot_id ] = $record;

		if ( count( $all ) > Content_Limits::MAX_PLANS * 10 ) {
			uasort( $all, static function ( $left, $right ) { return (int) ( $left['taken_at'] ?? 0 ) <=> (int) ( $right['taken_at'] ?? 0 ); } );
			$all = array_slice( $all, -( Content_Limits::MAX_PLANS * 10 ), null, true );
		}

		update_option( $option, $all, false );
		return $snapshot_id;
	}

	/**
	 * Return a stored snapshot.
	 *
	 * @param string $project_id  Project id.
	 * @param string $snapshot_id Snapshot id.
	 * @return array<string, mixed>|null
	 */
	public function snapshot( $project_id, $snapshot_id ) {
		$option = self::SNAPSHOT_PREFIX . substr( hash( 'sha256', (string) $project_id ), 0, 16 );
		$all    = get_option( $option, array() );
		if ( ! is_array( $all ) || ! isset( $all[ (string) $snapshot_id ] ) ) {
			return null;
		}
		return (array) $all[ (string) $snapshot_id ];
	}

	/**
	 * Remove a snapshot once it is no longer needed.
	 *
	 * @param string $project_id  Project id.
	 * @param string $snapshot_id Snapshot id.
	 * @return bool
	 */
	public function forget_snapshot( $project_id, $snapshot_id ) {
		$option = self::SNAPSHOT_PREFIX . substr( hash( 'sha256', (string) $project_id ), 0, 16 );
		$all    = get_option( $option, array() );
		if ( ! is_array( $all ) || ! isset( $all[ (string) $snapshot_id ] ) ) {
			return false;
		}
		unset( $all[ (string) $snapshot_id ] );
		update_option( $option, $all, false );
		return true;
	}

	/**
	 * Return the provenance key for a record.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return string
	 */
	private function provenance_key( array $record ) {
		return (string) ( $record['source_content_id'] ?? '' ) . '|' . (int) ( $record['destination_id'] ?? 0 ) . '|' . (string) ( $record['destination_field'] ?? '' );
	}

	/**
	 * Read the cache index.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function index() {
		$index = get_option( self::ANALYSIS_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}
}
