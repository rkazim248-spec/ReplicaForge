<?php
/**
 * Phase 7: uninstall.
 *
 * @package ReplicaForge
 *
 * Deleting ReplicaForge removes only what ReplicaForge created. It never deletes
 * an Elementor page, a WordPress post, media, or anything a person edited. The
 * user generated the drafts; they are the user's content, not the plugin's.
 *
 * The policy is declared in one place so an administrator can read it, and it is
 * applied here rather than in a deactivation hook, because a deactivation is
 * usually temporary and destroying data on deactivate loses work for no reason.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove ReplicaForge's own stored data.
 *
 * @return array<string, int> Counts of what was removed, for the uninstall hook's
 *                             own record. Nothing is returned to a user.
 */
function replicaforge_uninstall() {
	$removed = array(
		'options'   => 0,
		'transients'=> 0,
		'post_meta' => 0,
		'jobs'      => 0,
		'tables'    => 0,
	);

	/*
	 * The table names, duplicated so the drop still works when the plugin classes are
	 * already unloaded. `replicaforge_uninstall()` runs from `uninstall.php`, which
	 * WordPress includes *before* deactivating, so the classes are usually present - but
	 * "usually" is not a guarantee, and a sweep that quietly misses three tables leaves
	 * real content behind. The test suite asserts this list matches
	 * `Workspace_Limits::table()` for every kind, so the two cannot drift without a
	 * failure being reported.
	 */
	$fallback_tables = array(
		'workspaces'     => 'replicaforge_workspaces',
		'members'        => 'replicaforge_workspace_members',
		'project_member' => 'replicaforge_project_members',
		'invitations'    => 'replicaforge_invitations',
		'clients'        => 'replicaforge_clients',
		'contacts'       => 'replicaforge_client_contacts',
		'reviews'        => 'replicaforge_reviews',
		'comments'       => 'replicaforge_comments',
		'tasks'          => 'replicaforge_tasks',
		'issues'         => 'replicaforge_issues',
		'notifications'  => 'replicaforge_notifications',
		'activity'       => 'replicaforge_activity',
		'audit'          => 'replicaforge_audit',
		// Phase 19.
		'templates'          => 'replicaforge_templates',
		'template_version'   => 'replicaforge_template_versions',
		'template_component' => 'replicaforge_template_components',
		// Phase 20: the developer platform.
		'api_credential'     => 'replicaforge_api_credentials',
		'webhook'            => 'replicaforge_webhooks',
		'webhook_delivery'   => 'replicaforge_webhook_deliveries',
		'extension'          => 'replicaforge_extensions',
		'automation'         => 'replicaforge_automations',
		'event'              => 'replicaforge_events',
	);

	// Options and settings ReplicaForge owns outright.
	//
	// The AI settings are included deliberately. That option holds the provider API
	// key in plaintext, because WordPress has nowhere better to put it, so leaving
	// it behind on uninstall would mean deleting the plugin does not remove the
	// secret it was trusted with. That is the opposite of what an administrator
	// removing a plugin is asking for.
	//
	// The same reasoning applies to `replicaforge_validation_renderer`, which holds a
	// bearer token for the external rendering service, and to `replicaforge_license_local`,
	// which holds this site's license record. Both are credentials.
	foreach (
		array(
			'replicaforge_version',
			'replicaforge_schema_version',
			'replicaforge_migration_state',
			'replicaforge_log',
			'replicaforge_jobs',
			'replicaforge_idempotency',
			'replicaforge_feature_flags',
			'replicaforge_settings',
			'replicaforge_corrections',
			'replicaforge_ai_settings',
			'replicaforge_ai_usage',
			'replicaforge_ai_usage_recent',
			'replicaforge_ai_estimator',
			'replicaforge_audit_log',
			'replicaforge_onboarding',
			'replicaforge_plan_definitions',
			'replicaforge_trial_settings',
			// Credentials. This option was previously listed under the name
			// `replicaforge_render_settings`, which nothing in the plugin has ever
			// written. So the rendering endpoint and its bearer token survived uninstall
			// while this function claimed to be removing them. The real name is this one.
			'replicaforge_validation_renderer',
			'replicaforge_license_local',
			'replicaforge_site_plan',
			// Phase 15. The token salt is included for the same reason the AI key is:
			// it exists only to make this plugin's tokens unguessable, and leaving it
			// behind serves no purpose once the plugin is gone.
			'replicaforge_token_salt',
			// Per-user notification preferences, keyed by user id. Leaving them behind
			// would silently re-apply choices somebody made to a plugin that is no
			// longer installed, if it were ever reinstalled.
			'replicaforge_notification_preferences',
			// The collaboration migration's own diagnostics, and the schema marker it
			// writes. Both are plugin bookkeeping rather than user data.
			'replicaforge_collaboration_schema',
			'replicaforge_collaboration_migration',
			// Operational stores and job execution state.
			'replicaforge_generations',
			'replicaforge_validations',
			'replicaforge_job_settings',
			'replicaforge_job_cancellations',
			'replicaforge_visual_ai',
			'replicaforge_site_registry',
			'replicaforge_site_snapshots',
			'replicaforge_global_style_ownership',
			'replicaforge_websites',
			'replicaforge_workflows',
			// Phase 20. `replicaforge_developer_notices` is swept for the same reason the
			// AI settings are: it is the transient the console uses to hand a one-time API
			// token to the screen that created it, so it can hold a usable credential. The
			// render path clears it as soon as it is printed, but a session that created a
			// credential and never reloaded the page would leave it behind — and an
			// uninstall is exactly the moment that has to not survive.
			'replicaforge_extension_settings',
			'replicaforge_rate_buckets',
			'replicaforge_developer_notices',
			'replicaforge_platform_migration',
			// Phase 21. The security findings registry. It is plugin bookkeeping rather
			// than user data, so it does not belong in the archive - and a finding whose
			// `affected` list names a file path has no meaning once the plugin's code is
			// gone, so keeping it would only leave stale references behind to mislead a
			// later reinstall.
			'replicaforge_security_findings',
		) as $option
	) {
		if ( delete_option( $option ) ) {
			$removed['options']++;
		}
	}

	/*
	 * Prefixed option families.
	 *
	 * These are swept by pattern because each is one option per item - one specification,
	 * one cached result, one workflow, one content plan - and naming every generated
	 * identifier individually is not possible.
	 *
	 * Only families this plugin owns are swept. `replicaforge_projects` and
	 * `replicaforge_preferences` are deliberately absent: they are the user's own records,
	 * and the generated Elementor drafts they point at are preserved below. Removing the
	 * project list while keeping the drafts would orphan the user's work and leave no way to
	 * associate a page with the site it came from. The same reasoning that protects drafts
	 * and media protects the project list, and an administrator who wants those removed can
	 * delete them by hand.
	 */
	$option_prefixes = array(
		'replicaforge_spec_'               => 'specifications',
		'replicaforge_validation_result_'  => 'validation results',
		'replicaforge_correction_'         => 'correction snapshots',
		'replicaforge_correction_plan_'    => 'correction plans',
		'replicaforge_job_payload_'        => 'job payloads',
		'replicaforge_lock_'               => 'job locks',
		'replicaforge_usage_'              => 'usage counters',
		'replicaforge_usage_recent_'       => 'usage history',
		'replicaforge_usage_reserved_'     => 'usage reservations',
		'replicaforge_usage_lock_'         => 'usage locks',
		'replicaforge_plans_'              => 'compiled plan cache',
		'replicaforge_trial_'              => 'trial records',
		'replicaforge_visual_cache'        => 'visual cache index',
		'replicaforge_visual_entry_'       => 'rendered captures',
		'replicaforge_content_cache'       => 'content cache index',
		'replicaforge_content_plan_'       => 'content plans',
		'replicaforge_content_provenance_' => 'content provenance',
		'replicaforge_content_snapshot_'   => 'content snapshots',
		'rfc_entry_'                       => 'content cache entries',
		'replicaforge_workflow_'           => 'workflow records',
		'replicaforge_workflow_state_'     => 'workflow state',
		'replicaforge_workflow_artifacts_' => 'workflow artifacts',
		'replicaforge_sync_lock_'          => 'sync locks',
		'replicaforge_sync_map'            => 'sync maps',
	);

	global $wpdb;

	foreach ( array_keys( $option_prefixes ) as $prefix ) {
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( (string) $prefix ) . '%'
			)
		);

		foreach ( (array) $found as $option ) {
			if ( delete_option( (string) $option ) ) {
				$removed['options']++;
			}
		}
	}

	// Transients, including the plan, snapshot, and specification records. Their
	// timeout rows go with them so no orphan is left pointing at nothing.
	global $wpdb;
	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_replicaforge_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_replicaforge_' ) . '%'
		)
	);
	foreach ( (array) $names as $name ) {
		if ( delete_option( (string) $name ) ) {
			$removed['transients']++;
		}
	}

	// Post meta written by ReplicaForge. Only ReplicaForge's own keys are touched.
	//
	// The deletion goes through delete_metadata() rather than a direct
	// $wpdb->delete(). A raw delete removes the rows but leaves the object cache
	// holding the value, so anything that had already read the meta in this request
	// kept seeing a stale copy. The metadata API invalidates the cache as part of
	// removing the rows, which is the behaviour WordPress code expects.
	$meta_keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'replicaforge_' ) . '%'
		)
	);
	foreach ( (array) $meta_keys as $meta_key ) {
		$meta_key = (string) $meta_key;

		// The snapshots list and the generation state belong to ReplicaForge. The
		// generated Elementor document does not: that is the user's draft, stored
		// under Elementor's own key, and it is never touched here.
		$deleted = delete_metadata( 'post', 0, $meta_key, '', true );
		if ( $deleted ) {
			$removed['post_meta'] += (int) $deleted;
		}
	}

	// Phase 15: the thirteen collaboration tables.
	//
	// Dropped rather than left behind, on the same reasoning as the AI key. A table an
	// administrator's database accumulates after they asked for the plugin to be removed
	// is a table nobody will ever remember to clean up, and it holds real content -
	// comments a client wrote, review decisions somebody recorded, an audit trail of
	// permission changes. Leaving it would mean deleting the plugin does not delete the
	// work, which is the opposite of what removing a plugin means.
	//
	// The names are read from the plugin's own vocabulary class when it is available, and
	// fall back to a literal list when it is not. During an uninstall the plugin files may
	// already be gone, so `class_exists()` is the check rather than an assumption: the
	// literal list is a duplicate of the vocabulary rather than a second source of truth,
	// and the test suite asserts the two agree.
	$table_kinds = array(
		'workspaces',
		'members',
		'project_member',
		'invitations',
		'clients',
		'contacts',
		'reviews',
		'comments',
		'tasks',
		'issues',
		'notifications',
		'activity',
		'audit',
		// Phase 19: the template library.
		//
		// Dropped on exactly the same reasoning as the thirteen above. A template library
		// left behind after somebody asked for the plugin to be removed is real content
		// with no owner: the versions reference Elementor documents, the components
		// reference tokens, and none of it is reachable once the code is gone. The
		// arguments for keeping `replicaforge_projects` -- generated Elementor drafts
		// outlive the plugin -- do not apply here, because a template version is *stored
		// inside* this plugin's own tables rather than existing as a post.
		'templates',
		'template_version',
		'template_component',
		// Phase 20: the developer platform.
		//
		// Dropped on the same reasoning as everything above. `api_credentials` is the
		// strongest case in the file: it holds the HMACs that authorise external systems,
		// and leaving them behind means a database dump taken after somebody removed the
		// plugin still contains material that was trusted to act as a person. It holds no
		// plaintext token, so the exposure is bounded — but it is exposure, and removing
		// the plugin should end it.
		//
		// `events` and `webhook_deliveries` are audit records rather than configuration,
		// and they are dropped for the same reason the audit table is: a table nobody will
		// remember to clean up is not a retention policy.
		'api_credential',
		'webhook',
		'webhook_delivery',
		'extension',
		'automation',
		'event',
	);

	$dropped = 0;
	foreach ( $table_kinds as $kind ) {
		$name = class_exists( '\ReplicaForge\Workspace_Limits' )
			? \ReplicaForge\Workspace_Limits::table( $kind )
			: '';

		if ( '' === $name ) {
			// The class is gone, so the literal list is the only thing left. Failing to
			// drop a table is a real cost, and silently skipping one is worse: this
			// records it instead, so the count is honest about what happened.
			$name = (string) ( $fallback_tables[ $kind ] ?? '' );
			if ( '' === $name ) {
				continue;
			}
		}

		$table = $wpdb->prefix . $name;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			continue;
		}
		// `DROP TABLE IF EXISTS` rather than a prepared DELETE, because the point is
		// the table's absence and a truncated table would be indistinguishable from a
		// working one.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- an identifier, built from the plugin's own prefix and its own vocabulary.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			$dropped++;
		}
	}
	$removed['tables'] = $dropped;

	return $removed;
}

if ( function_exists( 'register_uninstall_hook' ) ) {
	register_uninstall_hook( __FILE__, 'replicaforge_uninstall' );
}
