<?php
/**
 * Phase 7: every schema version in one place.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the data schema versions ReplicaForge writes.
 *
 * The plugin version and the data schema version are different things. A patch
 * release that changes no stored shape does not need a migration, and a data
 * change that happens to ship with a minor version bump still does. Keeping them
 * apart here is what makes "is stored data readable?" a question with an answer.
 */
final class Schema {

	/**
	 * Option holding the installed data schema version.
	 */
	const VERSION_OPTION = 'replicaforge_schema_version';

	/**
	 * Current storage schema version.
	 *
	 * Bump this only when the shape of something ReplicaForge stores changes.
	 *
	 * 10.0.0 adds the Phase 10 commercial storage: plan definition overrides, the
	 * trial configuration, the local licensing record, the site's plan, the audit
	 * ring, and per-user usage counters. None of it is a new table — it is all
	 * options and user meta — so the migration is a set of defaults plus the
	 * capability grants, and it is idempotent.
	 *
	 * 11.0.0 adds the Phase 11 reliability storage: the orchestrator settings, the
	 * AI estimator settings, the AI usage counters, and cancellation requests. Also
	 * no new table. The job record shape gains a checkpoint and ownership fields,
	 * which the migration backfills onto existing jobs so a job created before this
	 * phase is resumable rather than opaque.
	 */
	const DB_SCHEMA_VERSION = '19.0.0';

	/**
	 * Phase 1 frontend analysis representation version.
	 */
	const ANALYSIS_SCHEMA_VERSION = '2.0';

	/**
	 * Phase 3 reconstruction specification version.
	 */
	const RECONSTRUCTION_SCHEMA_VERSION = '3.0';

	/**
	 * Phase 4 generated document format version.
	 */
	const ELEMENTOR_SCHEMA_VERSION = '4.0';

	/**
	 * Phase 5 validation record version.
	 */
	const VALIDATION_SCHEMA_VERSION = '5.0';

	/**
	 * Phase 6 correction plan version.
	 */
	const CORRECTION_SCHEMA_VERSION = '6.0';

	/**
	 * Phase 10 plan definition and usage record version.
	 *
	 * Separate from `DB_SCHEMA_VERSION` so that an exported plan configuration
	 * carries the shape of the plan document it came from. An import that carries a
	 * newer plan schema is refused rather than half-read, because a plan document
	 * with a key this version does not understand is a document whose limits might
	 * be incomplete — and an incomplete limit is read as unlimited.
	 */
	const PLAN_SCHEMA_VERSION = '9.0';

	/**
	 * Phase 11 job record version.
	 *
	 * Separate from `DB_SCHEMA_VERSION` so a stored job record can be attributed and
	 * a job written by an older version can be recognised. The `11.0` record adds a
	 * checkpoint, an owning user, an owning project, the operation it was admitted
	 * for, and an explicit queue state.
	 */
	const JOB_SCHEMA_VERSION = '11.0';

	/**
	 * AI prompt version. Bumped whenever a prompt changes, because a cached AI
	 * response built from an older prompt must not be reused.
	 */
	const PROMPT_VERSION = '3';

	/**
	 * Engine versions, reported in records so a stored record can be attributed.
	 *
	 * @var array<string, string>
	 */
	const ENGINE_VERSIONS = array(
		'analysis'       => '1.0',
		'reconstruction' => '1.0',
		'generation'     => '1.0',
		'validation'     => '1.0',
		'correction'     => '1.0',
	);

	/**
	 * Return the installed schema version, or an empty string.
	 *
	 * @return string
	 */
	public static function installed() {
		$stored = get_option( self::VERSION_OPTION, '' );
		return is_string( $stored ) ? trim( $stored ) : '';
	}

	/**
	 * Record the installed schema version.
	 *
	 * @param string $version Version to record.
	 * @return void
	 */
	public static function mark_installed( $version ) {
		update_option( self::VERSION_OPTION, (string) $version, false );
	}

	/**
	 * Return whether a stored record predates the current schema.
	 *
	 * @param string $stored_version Version a record was written with.
	 * @return bool
	 */
	public static function is_stale( $stored_version ) {
		$stored_version = is_string( $stored_version ) ? trim( $stored_version ) : '';
		if ( '' === $stored_version ) {
			return true;
		}
		return version_compare( $stored_version, self::DB_SCHEMA_VERSION, '<' );
	}

	/**
	 * Return every declared version, for the system status screen and for a
	 * diagnostic export.
	 *
	 * @return array<string, string>
	 */
	public static function all() {
		return array(
			'db_schema'             => self::DB_SCHEMA_VERSION,
			'analysis_schema'       => self::ANALYSIS_SCHEMA_VERSION,
			'reconstruction_schema' => self::RECONSTRUCTION_SCHEMA_VERSION,
			'elementor_schema'      => self::ELEMENTOR_SCHEMA_VERSION,
			'validation_schema'     => self::VALIDATION_SCHEMA_VERSION,
			'correction_schema'     => self::CORRECTION_SCHEMA_VERSION,
			'plan_schema'           => self::PLAN_SCHEMA_VERSION,
			'job_schema'            => self::JOB_SCHEMA_VERSION,
			'prompt_version'        => self::PROMPT_VERSION,
			'plugin_version'        => defined( 'REPLICAFORGE_VERSION' ) ? REPLICAFORGE_VERSION : '0.0.0',
		);
	}

	/**
	 * Return the cache-key fragment for a phase.
	 *
	 * A cache entry is only reused when the schema that produced it matches, so a
	 * schema change invalidates caches instead of serving stale results.
	 *
	 * @param string $phase Phase name.
	 * @return string
	 */
	public static function cache_token( $phase ) {
		$phase = is_string( $phase ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( $phase ) ) : '';
		$map   = array(
			'analysis'       => self::ANALYSIS_SCHEMA_VERSION,
			'reconstruction' => self::RECONSTRUCTION_SCHEMA_VERSION . 'p' . self::PROMPT_VERSION,
			'elementor'      => self::ELEMENTOR_SCHEMA_VERSION,
			'validation'     => self::VALIDATION_SCHEMA_VERSION,
			'correction'     => self::CORRECTION_SCHEMA_VERSION,
		);
		$version = isset( $map[ $phase ] ) ? $map[ $phase ] : self::DB_SCHEMA_VERSION;
		return $phase . $version;
	}
}
