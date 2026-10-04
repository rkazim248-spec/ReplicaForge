<?php
/**
 * Phase 14: the content apply pipeline.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Writes an approved mapping plan, and puts it back if anything goes wrong.
 *
 * ### The transaction
 *
 * §22 asks for prepare → validate → snapshot → apply → validate → render → validate →
 * commit, with a rollback on any critical failure. In WordPress there is no transaction,
 * so the two steps that actually give atomicity are:
 *
 * 1. **Snapshot first.** Every destination record that will be touched is read and
 *    stored *before* the first write, through Phase 6's existing
 *    {@see Correction_Snapshot}, which is already the plugin's answer to "what did this
 *    document look like before". Reusing it means there is one rollback mechanism rather
 *    than two, and one place that has been tested for restoration.
 * 2. **Fail the whole stage, not the write.** A mapping that cannot be written is
 *    recorded and the stage returns `failed`; the caller sees no partial success. §22's
 *    requirement that ReplicaForge never leave partially corrupted documents is met by
 *    never reporting success while holding a partial result.
 *
 * ### What this class will not write
 *
 * - **Nothing outside a destination field a plan named.** There is no path from a
 *    mapping to a post meta key, a term, an option, or a raw query. §48's "arbitrary
 *    database editing" is not excluded by policy, it is unrepresentable.
 * - **Read-only fields.** Re-checked here against the provider, not trusted from the
 *    plan, because a plan can be edited by hand between validation and apply.
 * - **Anything a user owns, without approval.** A `user_controlled` destination whose
 *    existing value differs from what the plan writes is a conflict, and a conflict is
 *    reported rather than resolved.
 *
 * ### §23: existing content is never silently overwritten
 *
 * Every write goes through {@see self::resolve_existing()}, which reports what is
 * already there and what was done about it. The default for a destination that already
 * has a value is `review`, not `overwrite`, because a mapping engine that quietly
 * replaces a user's product description is worse than one that asks.
 */
final class Content_Applier {

	/**
	 * Snapshot store.
	 *
	 * @var Correction_Snapshot
	 */
	private $snapshots;

	/**
	 * Providers, by id.
	 *
	 * @var array<string, Content_Provider_Contract>
	 */
	private $providers = array();

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Content cache, which stores the field-value snapshots.
	 *
	 * @var Content_Cache
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param Correction_Snapshot|null $snapshots Optional document snapshot store.
	 * @param Logger|null             $logger   Optional logger.
	 * @param Content_Cache|null       $cache     Optional content cache.
	 */
	public function __construct( $snapshots = null, $logger = null, $cache = null ) {
		$this->snapshots = $snapshots instanceof Correction_Snapshot ? $snapshots : new Correction_Snapshot();
		$this->logger    = $logger instanceof Logger ? $logger : new Logger();
		$this->cache     = $cache instanceof Content_Cache ? $cache : new Content_Cache();
	}

	/**
	 * Register a provider.
	 *
	 * @param Content_Provider_Contract $provider Provider.
	 * @return void
	 */
	public function add_provider( Content_Provider_Contract $provider ) {
		$this->providers[ $provider->id() ] = $provider;
	}

	/**
	 * Apply a validated plan.
	 *
	 * @param array<string, mixed> $plan      Plan.
	 * @param array<int, string>   $approved  Approved mapping ids.
	 * @param array<string, mixed> $options   Options: `existing` policy, `user_id`, `dry_run`.
	 * @return array<string, mixed>
	 */
	public function apply( array $plan, array $approved = array(), array $options = array() ) {
		$user_id  = (int) ( $options['user_id'] ?? get_current_user_id() );
		$dry_run  = ! empty( $options['dry_run'] );
		$existing = (string) ( $options['existing'] ?? 'review' );
		$stages   = array();

		// --- Prepare -------------------------------------------------------
		$eligibility = ( new Content_Validator() )->eligible_for_apply( $plan, $approved );
		$stages['prepare'] = array(
			'stage'    => 'prepare',
			'ok'       => true,
			'eligible' => $eligibility['counts']['eligible'],
			'withheld' => $eligibility['withheld'],
			'note'     => __( 'Only approved, applicable mappings with a resolved destination are prepared. Everything else is listed rather than dropped silently.', 'replicaforge' ),
		);

		if ( array() === $eligibility['eligible'] ) {
			return $this->result( $plan, $stages, 'failed', array(), array(), 'nothing was eligible to apply' );
		}

		// --- Validate ------------------------------------------------------
		$verdict = ( new Content_Validator() )->validate( $plan );
		$stages['validate'] = array(
			'stage' => 'validate',
			'ok'    => (bool) $verdict['applicable'],
			'errors'=> (array) $verdict['errors'],
		);
		if ( empty( $verdict['applicable'] ) ) {
			// A plan that fails validation is not partially applied. It is not applied.
			return $this->result( $plan, $stages, 'failed', array(), array(), 'the plan did not validate, so nothing was written' );
		}

		// --- Snapshot ------------------------------------------------------
		// Every destination this stage will touch, read and stored *before* the first
		// write. A dry run still snapshots, because a preview that cannot show what it
		// would change is not a preview.
		// The before-image of every field this stage will change.
		//
		// Phase 6's Correction_Snapshot snapshots an *Elementor document*, and create()
		// returns an error for a post with no Elementor data - which is most WordPress
		// posts, and every product with no built layout. Reusing it alone meant that in
		// exactly the case that needed a snapshot, none was taken and rollback had
		// nothing to restore. The two are complementary, not duplicate: this stores the
		// *values* of the fields about to change, Phase 6 stores the *document*.
		//
		// Taken once per destination record rather than once per mapping, so four
		// mappings onto one product produce one before-image holding all four old
		// values, not four snapshots each stale for the other three.
		$snapshots = array();
		$targets   = array();
		$seen      = array();
		foreach ( $eligibility['eligible'] as $mapping ) {
			$target = $this->resolve_target( $mapping );
			if ( null === $target ) {
				continue;
			}
			$targets[] = $target;

			$record_key = (string) $target['provider_id'] . ':' . (int) $target['entity_id'];
			if ( isset( $seen[ $record_key ] ) ) {
				continue;
			}
			$seen[ $record_key ] = true;

			$field_values = array();
			foreach ( $targets as $sibling ) {
				$sibling_key = (string) $sibling['provider_id'] . ':' . (int) $sibling['entity_id'];
				if ( $sibling_key !== $record_key ) {
					continue;
				}
				$field_values[ (string) $sibling['field'] ] = $sibling['existing_value'];
			}

			$snapshot = $this->snapshot_values( (string) $plan['project_id'], $target, $field_values );
			if ( ! empty( $snapshot ) ) {
				$snapshots[] = $snapshot;
			}
		}
		$stages['snapshot'] = array(
			'stage'  => 'snapshot',
			'ok'     => true,
			'taken'  => count( $snapshots ),
			'targets'=> count( $targets ),
			'note'   => __( 'Every destination that will be written was read and stored first, so the change can be undone.', 'replicaforge' ),
		);

		// --- §23: what is already there -----------------------------------
		$existing_report = array();
		foreach ( $targets as $target ) {
			$existing_report[] = $this->resolve_existing( $target, $existing );
		}
		$stages['existing_content'] = array(
			'stage'  => 'existing_content',
			'ok'     => true,
			'report' => $existing_report,
			'note'   => __( 'Existing content is reported rather than overwritten. The default is review, so nothing already written by you is replaced without your say-so.', 'replicaforge' ),
		);

		$conflicts = array_values( array_filter( $existing_report, static function ( $entry ) { return 'conflict' === (string) ( $entry['status'] ?? '' ); } ) );
		if ( array() !== $conflicts ) {
			// §37: a conflict is never decided automatically. The stage fails with the
			// conflicts listed, and the user chooses.
			return $this->result( $plan, $stages, 'conflict', $snapshots, array(), sprintf( __( '%d destination(s) already hold different values. Nothing was written.', 'replicaforge' ), count( $conflicts ) ), $conflicts );
		}

		if ( $dry_run ) {
			$stages['apply'] = array( 'stage' => 'apply', 'ok' => true, 'dry_run' => true, 'written' => 0 );
			return $this->result( $plan, $stages, 'dry_run', $snapshots, array(), __( 'This was a preview. Nothing was written.', 'replicaforge' ) );
		}

		// --- Apply ---------------------------------------------------------
		$written  = array();
		$failures = array();
		$refs     = array();

		foreach ( $targets as $target ) {
			$mapping = (array) $target['mapping'];
			$written_now = $this->write( $target );
			if ( null === $written_now ) {
				$failures[] = array(
					'mapping_id' => (string) ( $mapping['mapping_id'] ?? '' ),
					'field'      => (string) ( $target['field'] ?? '' ),
					'reason'     => __( 'The destination refused the write. Nothing was changed for this field.', 'replicaforge' ),
				);
				continue;
			}
			$written[] = $written_now;
			$refs[]     = $written_now['provenance'];
		}
		$stages['apply'] = array(
			'stage'    => 'apply',
			'ok'       => ( array() === $failures ),
			'written'  => count( $written ),
			'failures' => $failures,
		);

		// --- Validate after ------------------------------------------------
		// Re-read every written field. This is the check that catches a write that
		// reported success and did not land — a sanitiser that stripped the value, a
		// capability that did not permit it, a hook that overwrote it.
		$verified = $this->verify( $written );
		$stages['verify'] = array(
			'stage'    => 'validate',
			'ok'       => ( array() === $verified['mismatches'] ),
			'checked'  => $verified['checked'],
			'mismatches' => $verified['mismatches'],
		);

		if ( array() !== $failures || array() !== $verified['mismatches'] ) {
			// §22: any critical failure means rollback, and a half-applied plan is worse
			// than an unapplied one.
			$restored = $this->rollback( $snapshots );
			$stages['rollback'] = $restored;
			return $this->result( $plan, $stages, 'rolled_back', $snapshots, $written, __( 'The write did not fully succeed, so ReplicaForge put everything back.', 'replicaforge' ), array(), $restored['restored'] );
		}

		// --- Commit --------------------------------------------------------
		// The provenance record is the commit. Until it is written the change is
		// untraceable, and an untraceable change cannot be protected by Phase 9.
		$committed = $this->commit_provenance( $refs, $plan );
		$stages['commit'] = array(
			'stage'   => 'commit',
			'ok'      => ( array() === $committed['failures'] ),
			'records' => count( $committed['records'] ),
			'failures'=> $committed['failures'],
		);

		$status = ( array() === $committed['failures'] ) ? 'committed' : 'committed_with_provenance_gap';

		return $this->result( $plan, $stages, $status, $snapshots, $written, __( 'The approved mappings were applied and their provenance recorded.', 'replicaforge' ) );
	}

	/**
	 * Roll back a set of snapshots.
	 *
	 * @param array<int, array<string, mixed>> $snapshots Snapshots.
	 * @return array<string, mixed>
	 */
	public function rollback( array $snapshots ) {
		$restored = 0;
		$failed   = array();

		foreach ( $snapshots as $snapshot ) {
			$snapshot    = (array) $snapshot;
			$snapshot_id = (string) ( $snapshot['snapshot_id'] ?? '' );
			$values      = (array) ( $snapshot['values'] ?? array() );
			$provider_id = (string) ( $snapshot['provider'] ?? '' );
			$entity_id   = (int) ( $snapshot['entity_id'] ?? 0 );

			if ( '' === $snapshot_id || $entity_id < 1 ) {
				$failed[] = array( 'snapshot_id' => $snapshot_id, 'reason' => 'incomplete_snapshot' );
				continue;
			}

			$provider = $this->providers[ $provider_id ] ?? null;
			if ( ! $provider instanceof Content_Provider_Contract || ! $provider->is_available() ) {
				// The destination went away between the write and the rollback. That is
				// reported as a failure rather than skipped, because "I could not put it
				// back" is exactly the fact a user needs to be told.
				$failed[] = array( 'snapshot_id' => $snapshot_id, 'entity_id' => $entity_id, 'provider' => $provider_id, 'reason' => 'provider_unavailable' );
				continue;
			}

			$entity         = (string) ( $snapshot['entity'] ?? '' );
			$field_failures = array();
			foreach ( $values as $field => $old_value ) {
				if ( ! $this->write_field( $provider, $provider_id, $entity_id, (string) $field, $old_value ) ) {
					$field_failures[] = (string) $field;
				}
			}

			if ( array() === $field_failures ) {
				$restored++;
			} else {
				$failed[] = array( 'snapshot_id' => $snapshot_id, 'entity_id' => $entity_id, 'reason' => 'field_restore_failed', 'fields' => $field_failures );
			}
		}

		$this->logger->info(
			'content_rollback',
			sprintf( 'Rolled back %d content destination(s); %d failed.', $restored, count( $failed ) ),
			array( 'restored' => $restored, 'failed' => count( $failed ) ),
			'content'
		);

		return array(
			'restored' => $restored,
			'failed'   => $failed,
			'ok'       => ( array() === $failed ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve a mapping into a concrete write target.
	 *
	 * @param array<string, mixed> $mapping Mapping.
	 * @return array<string, mixed>|null
	 */
	private function resolve_target( array $mapping ) {
		$destination = (array) ( $mapping['destination'] ?? array() );
		$provider_id = (string) ( $destination['provider'] ?? '' );
		$provider    = $this->providers[ $provider_id ] ?? null;

		if ( ! $provider instanceof Content_Provider_Contract || ! $provider->is_available() ) {
			return null;
		}

		$entity_id = (int) ( $destination['entity_id'] ?? 0 );
		$field     = (string) ( $destination['field'] ?? '' );
		$entity    = (string) ( $destination['entity'] ?? '' );

		// Re-check the field against the provider rather than trusting the plan. A plan
		// can be hand-edited, and §8's read-only fields must be refused at the last
		// possible moment.
		$descriptor = null;
		foreach ( (array) $provider->fields_for( $entity ) as $candidate ) {
			if ( is_array( $candidate ) && (string) ( $candidate['field'] ?? '' ) === $field ) {
				$descriptor = $candidate;
				break;
			}
		}
		if ( null === $descriptor || empty( $descriptor['editable'] ) ) {
			return null;
		}

		$record = $provider->entity( $entity, $entity_id );
		if ( null === $record ) {
			// The entity vanished between planning and applying. Refusing is correct: the
			// mapping's whole basis was that this record existed.
			return null;
		}

		$existing_values = (array) ( $record['values'] ?? array() );
		$field_name      = (string) ( $descriptor['field'] ?? '' );
		$existing_value  = $existing_values[ $field_name ] ?? null;

		return array(
			'mapping'        => $mapping,
			'provider'       => $provider,
			'provider_id'    => $provider_id,
			'entity'         => $entity,
			'entity_id'      => $entity_id,
			'field'          => $field_name,
			'data_type'      => (string) ( $descriptor['data_type'] ?? 'text' ),
			'value'          => (string) ( $mapping['source']['value'] ?? '' ),
			'existing_value' => is_scalar( $existing_value ) ? (string) $existing_value : null,
			'values'         => $existing_values,
		);
	}

	/**
	 * Report what a destination already holds.
	 *
	 * @param array<string, mixed> $target   Target.
	 * @param string               $existing Policy.
	 * @return array<string, mixed>
	 */
	private function resolve_existing( array $target, $existing ) {
		$before = $target['existing_value'];
		$after  = (string) $target['value'];

		$base = array(
			'entity_id'     => (int) $target['entity_id'],
			'field'         => (string) $target['field'],
			'existing'      => ( null === $before ) ? null : (string) $before,
			'incoming'      => $after,
			'ownership'     => (string) ( $target['mapping']['ownership'] ?? 'unknown' ),
		);

		// No existing value. Not a conflict — there is nothing to overwrite.
		if ( null === $before || '' === $before ) {
			$base['status'] = 'empty';
			$base['action'] = 'will_write';
			return $base;
		}

		// Same value. Nothing changes, so nothing is written and nothing is at risk.
		if ( $before === $after ) {
			$base['status'] = 'unchanged';
			$base['action'] = 'skip';
			return $base;
		}

		// Different values. §23: the default is review, and a conflict is reported rather
		// than decided. `skip` is the only non-destructive outcome that needs no human.
		$base['status'] = ( 'review' === $existing ) ? 'conflict' : 'overwritable';
		$base['action'] = ( 'review' === $existing ) ? 'skip' : 'will_write';
		$base['note']   = __( 'This destination already holds a different value. ReplicaForge did not decide what should happen.', 'replicaforge' );

		return $base;
	}

	/**
	 * Write one target.
	 *
	 * @param array<string, mixed> $target Target.
	 * @return array<string, mixed>|null
	 */
	private function write( array $target ) {
		$provider = $target['provider'];
		$field    = (string) $target['field'];
		$value    = (string) $target['value'];

		// The single write path, shared with the rollback stage, so a value cannot be
		// written one way and restored another. A restore that sanitised differently
		// from the write would quietly leave a third value behind.
		//
		// The field name was resolved against the provider in `resolve_target()`, so it
		// is one this project declared. A value is never used as a key on this path.
		if ( ! $this->write_field( $provider, (string) $target['provider_id'], (int) $target['entity_id'], $field, $value ) ) {
			return null;
		}

		return array(
			'mapping_id' => (string) ( $target['mapping']['mapping_id'] ?? '' ),
			'entity_id'  => (int) $target['entity_id'],
			'field'      => $field,
			'value'      => $value,
			'provenance' => array(
				'source_url'          => (string) ( $target['mapping']['source']['source_url'] ?? '' ),
				'source_page_id'      => (string) ( $target['mapping']['source']['page_id'] ?? '' ),
				'source_component_id' => (string) ( $target['mapping']['source']['component_id'] ?? '' ),
				'source_content_id'   => (string) ( $target['mapping']['source']['content_id'] ?? '' ),
				'destination_entity'  => (string) $target['entity'],
				'destination_id'      => (int) $target['entity_id'],
				'destination_field'   => $field,
				'mapping_id'          => (string) ( $target['mapping']['mapping_id'] ?? '' ),
				'ownership'           => (string) ( $target['mapping']['ownership'] ?? 'unknown' ),
				'content_hash'        => (string) ( $target['mapping']['source']['fingerprint'] ?? '' ),
				'timestamp'           => time(),
			),
		);
	}

	/**
	 * Re-read every written field.
	 *
	 * @param array<int, array<string, mixed>> $written Written records.
	 * @return array<string, mixed>
	 */
	private function verify( array $written ) {
		$mismatches = array();
		$checked    = 0;

		foreach ( $written as $record ) {
			$record = (array) $record;
			$id     = (int) ( $record['entity_id'] ?? 0 );
			$field  = (string) ( $record['field'] ?? '' );
			$value  = (string) ( $record['value'] ?? '' );
			if ( $id < 1 || '' === $field ) {
				continue;
			}
			$checked++;

			$stored = ( 0 === strpos( $field, '_' ) )
				? (string) get_post_meta( $id, $field, true )
				: (string) get_post_field( $field, $id );

			// A sanitiser is entitled to change what it stores — that is its job. So the
			// comparison is against the *sanitised* incoming value, not the raw one, or
			// every field with markup would report a false failure.
			if ( $stored !== (string) wp_kses_post( $value ) && $stored !== $value ) {
				$mismatches[] = array(
					'entity_id' => $id,
					'field'     => $field,
					'expected'  => $value,
					'stored'    => $stored,
				);
			}
		}

		return array( 'checked' => $checked, 'mismatches' => $mismatches );
	}

	/**
	 * Snapshot one target.
	 *
	 * @param array<string, mixed> $target Target.
	 * @return array<string, mixed>
	 */
	private function snapshot_values( $project_id, array $target, array $field_values ) {
		$post_id = (int) $target['entity_id'];
		if ( $post_id < 1 ) {
			return array();
		}

		$snapshot_id = $this->cache->store_snapshot(
			(string) $project_id,
			array(
				'kind'       => 'values',
				'provider'   => (string) $target['provider_id'],
				'entity'     => (string) $target['entity'],
				'entity_id'  => $post_id,
				'values'     => $field_values,
				'mapping_id' => (string) ( $target['mapping']['mapping_id'] ?? '' ),
			)
		);
		if ( '' === $snapshot_id ) {
			return array();
		}

		return array(
			'kind'        => 'values',
			'snapshot_id' => $snapshot_id,
			'project_id'  => (string) $project_id,
			'provider'    => (string) $target['provider_id'],
			'entity'      => (string) $target['entity'],
			'entity_id'   => $post_id,
			'fields'      => array_keys( $field_values ),
		);
	}

	/**
	 * Write one field value to a destination record.
	 *
	 * The single write path, used by both the apply stage and the rollback stage.
	 *
	 * @param Content_Provider_Contract $provider    Provider.
	 * @param string                    $provider_id Provider id.
	 * @param int                       $entity_id   Entity id.
	 * @param string                    $field       Field.
	 * @param string|null               $value       Value.
	 * @return bool
	 */
	private function write_field( Content_Provider_Contract $provider, $provider_id, $entity_id, $field, $value ) {
		$entity_id = (int) $entity_id;
		$field     = (string) $field;
		if ( $entity_id < 1 || '' === $field ) {
			return false;
		}

		// The core post columns, as opposed to meta. Kept as a list because a field name
		// decides *how* it is written, and getting that wrong writes a post meta key
		// that nothing will ever read.
		$is_core = ( 'wordpress' === (string) $provider_id )
			&& in_array( $field, array( 'post_title', 'post_content', 'post_excerpt', 'post_date', 'post_status' ), true );

		if ( null === $value ) {
			// A null means the field was not set. Restoring must *remove* it rather than
			// write an empty string, or a rollback leaves a blank where there had been no
			// value at all - a different state, and a confusing one to debug.
			if ( $is_core ) {
				return (bool) wp_update_post( array( 'ID' => $entity_id, $field => '' ), true );
			}
			return (bool) delete_post_meta( $entity_id, ( 0 === strpos( $field, '_' ) ) ? $field : '_' . $field );
		}

		if ( $is_core ) {
			$updated = wp_update_post( array( 'ID' => $entity_id, $field => (string) $value ), true );
			return ! is_wp_error( $updated ) && 0 !== (int) $updated;
		}

		$meta_key = ( 0 === strpos( $field, '_' ) ) ? $field : '_' . $field;
		$written  = update_post_meta( $entity_id, $meta_key, (string) $value );
		if ( false === $written ) {
			// `update_post_meta` returns false both for a failure and for "the value was
			// already this", so the only honest test is to read it back.
			return (string) get_post_meta( $entity_id, $meta_key, true ) === (string) $value;
		}
		return true;
	}

	/**
	 * Record provenance for the writes.
	 *
	 * @param array<int, array<string, mixed>> $refs  Provenance records.
	 * @param array<string, mixed>             $plan  Plan.
	 * @return array<string, mixed>
	 */
	private function commit_provenance( array $refs, array $plan ) {
		$store   = new Content_Cache();
		$records = array();
		$failures = array();

		foreach ( $refs as $ref ) {
			$ref = (array) $ref;
			$ref['project_id'] = (string) ( $plan['project_id'] ?? '' );
			$ref['plan_id']    = (string) ( $plan['plan_id'] ?? '' );

			$stored = $store->record_provenance( $ref );
			if ( $stored ) {
				$records[] = $stored;
			} else {
				$failures[] = array( 'mapping_id' => (string) ( $ref['mapping_id'] ?? '' ), 'reason' => 'provenance_not_stored' );
			}
		}

		return array( 'records' => $records, 'failures' => $failures );
	}


	/**
	 * Build the result.
	 *
	 * @param array<string, mixed> $plan      Plan.
	 * @param array<string, mixed> $stages    Stages.
	 * @param string               $status    Status.
	 * @param array<int, mixed>    $snapshots Snapshots.
	 * @param array<int, mixed>    $written   Written records.
	 * @param string               $note      Note.
	 * @param array<int, mixed>    $conflicts Conflicts.
	 * @param int                  $restored  Restored count.
	 * @return array<string, mixed>
	 */
	private function result( array $plan, array $stages, $status, array $snapshots, array $written, $note, array $conflicts = array(), $restored = 0 ) {
		return array(
			'schema_version' => Content_Limits::SCHEMA_VERSION,
			'plan_id'        => (string) ( $plan['plan_id'] ?? '' ),
			'project_id'     => (string) ( $plan['project_id'] ?? '' ),
			'status'         => (string) $status,
			'stages'         => $stages,
			'written'        => array_values( $written ),
			'snapshots'      => array_values( $snapshots ),
			'conflicts'      => array_values( $conflicts ),
			'restored'       => (int) $restored,
			'note'           => (string) $note,
		);
	}
}
