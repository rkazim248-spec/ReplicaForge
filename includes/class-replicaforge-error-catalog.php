<?php
/**
 * Phase 7: the single place an error is described.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an internal error code into something a person can act on.
 *
 * Every user-visible failure in ReplicaForge passes through here, so a code can
 * never reach the browser without a category, a severity, a retryable flag, and a
 * message written for a human. The code stays available for the log; only the
 * message is shown.
 */
final class Error_Catalog {

	/**
	 * Error categories.
	 *
	 * @var array<string, string>
	 */
	const CATEGORIES = array(
		'SECURITY'        => 'SECURITY',
		'NETWORK'         => 'NETWORK',
		'SOURCE_WEBSITE'  => 'SOURCE_WEBSITE',
		'AI_PROVIDER'     => 'AI_PROVIDER',
		'ELEMENTOR'       => 'ELEMENTOR',
		'VALIDATION'      => 'VALIDATION',
		'CORRECTION'      => 'CORRECTION',
		'DATABASE'        => 'DATABASE',
		'PERMISSION'      => 'PERMISSION',
		'SYSTEM'          => 'SYSTEM',
		'USER_INPUT'      => 'USER_INPUT',
		'JOB'             => 'JOB',
		'RESOURCE'        => 'RESOURCE',
		'PLANS'           => 'PLANS',
		// Phase 14. Its own category rather than folded into `DATABASE`, because a
		// content problem is almost never a database problem: it is usually "the source
		// did not have that", and grouping it with storage errors would make a report
		// imply the user's data is at fault when the real answer is that nothing was
		// there to map.
		'CONTENT'         => 'CONTENT',
	);

	/**
	 * Severities.
	 *
	 * @var array<string, string>
	 */
	const SEVERITIES = array( 'info', 'warning', 'error', 'critical' );

	/**
	 * Default severity per category, used when a code has no explicit severity.
	 *
	 * @var array<string, string>
	 */
	const CATEGORY_SEVERITY = array(
		'SECURITY'       => 'error',
		'NETWORK'        => 'warning',
		'SOURCE_WEBSITE' => 'warning',
		'AI_PROVIDER'    => 'warning',
		'ELEMENTOR'      => 'error',
		'VALIDATION'     => 'info',
		'CORRECTION'     => 'warning',
		'DATABASE'       => 'error',
		'PERMISSION'     => 'error',
		'SYSTEM'         => 'error',
		'USER_INPUT'     => 'info',
		'JOB'            => 'warning',
		'RESOURCE'       => 'warning',
		'CONTENT'        => 'warning',
		'PLANS'          => 'info',
	);

	/**
	 * Built-in code definitions.
	 *
	 * Each entry is `category`, `severity`, `retryable`, and `message`. A code that
	 * is not listed still produces a usable envelope, so a new code can never leave
	 * the user with a blank error.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	const CODES = array(
		/* URL and destination policy. */
		'invalid_url'                => array( 'category' => 'USER_INPUT', 'severity' => 'info', 'retryable' => false, 'message' => 'That does not look like a web address. Enter a full address, for example https://example.com.' ),
		'unsupported_protocol'       => array( 'category' => 'SECURITY', 'severity' => 'error', 'retryable' => false, 'message' => 'Only http and https addresses can be analyzed.' ),
		'credentials_not_allowed'    => array( 'category' => 'SECURITY', 'severity' => 'error', 'retryable' => false, 'message' => 'The address contains a username or password, which ReplicaForge will not send.' ),
		'blocked_destination'        => array( 'category' => 'SECURITY', 'severity' => 'error', 'retryable' => false, 'message' => 'This address cannot be analyzed. ReplicaForge only connects to public websites, not to private networks or local services.' ),
		'blocked_port'               => array( 'category' => 'SECURITY', 'severity' => 'error', 'retryable' => false, 'message' => 'This address uses a port ReplicaForge will not connect to.' ),
		'invalid_port'               => array( 'category' => 'USER_INPUT', 'severity' => 'info', 'retryable' => false, 'message' => 'That address contains an invalid port number.' ),
		'forbidden_target'           => array( 'category' => 'SECURITY', 'severity' => 'error', 'retryable' => false, 'message' => 'Only public frontend pages can be analyzed.' ),
		'dns_resolution_failed'      => array( 'category' => 'NETWORK', 'severity' => 'warning', 'retryable' => true, 'message' => 'The website address could not be found. Check the address and your internet connection, then try again.' ),
		'redirect_to_blocked_host'   => array( 'category' => 'SECURITY', 'severity' => 'error', 'retryable' => false, 'message' => 'The website redirected to an address ReplicaForge will not connect to, so the analysis stopped.' ),

		/* Fetching. */
		'request_timeout'            => array( 'category' => 'NETWORK', 'severity' => 'warning', 'retryable' => true, 'message' => 'The website did not respond in time. It may be slow or temporarily unavailable. Try again in a moment.' ),
		'request_failed'             => array( 'category' => 'NETWORK', 'severity' => 'warning', 'retryable' => true, 'message' => 'The website could not be reached. Check that it is publicly accessible and try again.' ),
		'too_many_redirects'         => array( 'category' => 'SOURCE_WEBSITE', 'severity' => 'warning', 'retryable' => false, 'message' => 'The website redirected too many times, so the analysis stopped.' ),
		'redirect_loop'              => array( 'category' => 'SOURCE_WEBSITE', 'severity' => 'warning', 'retryable' => false, 'message' => 'The website redirected in a loop, so the analysis stopped.' ),
		'invalid_redirect'           => array( 'category' => 'SOURCE_WEBSITE', 'severity' => 'warning', 'retryable' => false, 'message' => 'The website returned a redirect ReplicaForge could not follow.' ),
		'response_too_large'         => array( 'category' => 'RESOURCE', 'severity' => 'warning', 'retryable' => false, 'message' => 'That page is larger than ReplicaForge analyzes in one pass. A smaller page may work.' ),
		'unsupported_content_type'   => array( 'category' => 'SOURCE_WEBSITE', 'severity' => 'warning', 'retryable' => false, 'message' => 'The website returned a file type ReplicaForge cannot analyze. Enter the address of an HTML page.' ),
		'empty_response'             => array( 'category' => 'SOURCE_WEBSITE', 'severity' => 'warning', 'retryable' => true, 'message' => 'The website returned an empty response.' ),

		/* Analysis. */
		'analysis_incomplete'        => array( 'category' => 'SOURCE_WEBSITE', 'severity' => 'warning', 'retryable' => false, 'message' => 'The page was analyzed, but parts of it were skipped. The report lists what was limited.' ),
		'representation_invalid'     => array( 'category' => 'SOURCE_WEBSITE', 'severity' => 'error', 'retryable' => false, 'message' => 'The analyzed page did not produce a usable structure, so nothing was generated.' ),
		'not_public_page'            => array( 'category' => 'USER_INPUT', 'severity' => 'info', 'retryable' => false, 'message' => 'Enter the address of a public webpage.' ),

		/* AI provider. */
		'ai_not_configured'          => array( 'category' => 'AI_PROVIDER', 'severity' => 'info', 'retryable' => false, 'message' => 'No AI provider is configured. ReplicaForge can generate a draft without AI.' ),
		'ai_provider_error'          => array( 'category' => 'AI_PROVIDER', 'severity' => 'warning', 'retryable' => true, 'message' => 'The AI provider could not complete the request. Try again shortly.' ),
		'ai_rate_limited'            => array( 'category' => 'AI_PROVIDER', 'severity' => 'warning', 'retryable' => true, 'message' => 'The AI provider is rate limiting requests. Wait a moment before trying again.' ),
		'ai_timeout'                 => array( 'category' => 'AI_PROVIDER', 'severity' => 'warning', 'retryable' => true, 'message' => 'The AI provider took too long to respond. Try again.' ),
		'ai_invalid_response'        => array( 'category' => 'AI_PROVIDER', 'severity' => 'error', 'retryable' => false, 'message' => 'The AI provider returned a response ReplicaForge could not use. Nothing was written.' ),
		'ai_output_rejected'         => array( 'category' => 'AI_PROVIDER', 'severity' => 'error', 'retryable' => false, 'message' => 'The AI response did not match the required structure, so ReplicaForge discarded it rather than guess.' ),
		'ai_secret_exposed'          => array( 'category' => 'SECURITY', 'severity' => 'critical', 'retryable' => false, 'message' => 'The AI response was discarded because it appeared to contain sensitive data.' ),

		/* Elementor. */
		'elementor_missing'          => array( 'category' => 'ELEMENTOR', 'severity' => 'error', 'retryable' => false, 'message' => 'Elementor is required to generate a draft. Install and activate Elementor, then try again.' ),
		'elementor_inactive'         => array( 'category' => 'ELEMENTOR', 'severity' => 'error', 'retryable' => false, 'message' => 'Elementor is installed but not active. Activate Elementor, then try again.' ),
		'draft_not_correctionable'   => array( 'category' => 'ELEMENTOR', 'severity' => 'error', 'retryable' => false, 'message' => 'This draft cannot be corrected. ReplicaForge only corrects drafts it generated.' ),
		'page_is_not_a_draft'        => array( 'category' => 'ELEMENTOR', 'severity' => 'error', 'retryable' => false, 'message' => 'Only drafts can be corrected. ReplicaForge never changes a published page.' ),
		'elementor_data_unreadable'  => array( 'category' => 'ELEMENTOR', 'severity' => 'error', 'retryable' => false, 'message' => 'The Elementor document could not be read, so nothing was changed.' ),
		'elementor_data_invalid'     => array( 'category' => 'ELEMENTOR', 'severity' => 'error', 'retryable' => false, 'message' => 'The Elementor document did not pass validation, so nothing was written.' ),

		/* Generation. */
		'specification_invalid'      => array( 'category' => 'USER_INPUT', 'severity' => 'error', 'retryable' => false, 'message' => 'The reconstruction plan is not valid, so no draft was created.' ),
		'draft_insert_failed'        => array( 'category' => 'ELEMENTOR', 'severity' => 'error', 'retryable' => false, 'message' => 'The draft page could not be created, so nothing was changed.' ),
		'document_save_failed'       => array( 'category' => 'ELEMENTOR', 'severity' => 'error', 'retryable' => false, 'message' => 'Elementor rejected the generated document, so nothing was written.' ),

		/* Validation. */
		'validation_failed'          => array( 'category' => 'VALIDATION', 'severity' => 'error', 'retryable' => false, 'message' => 'The draft could not be validated. Open the draft in Elementor and check that it still has content.' ),
		'validation_not_found'       => array( 'category' => 'VALIDATION', 'severity' => 'info', 'retryable' => false, 'message' => 'That validation result is no longer available. Run the validation again.' ),
		'render_provider_missing'    => array( 'category' => 'VALIDATION', 'severity' => 'info', 'retryable' => false, 'message' => 'No render provider is configured, so the comparison uses the document and stylesheet only.' ),

		/* Corrections. */
		'plan_not_found'             => array( 'category' => 'CORRECTION', 'severity' => 'info', 'retryable' => false, 'message' => 'That correction plan is no longer available. Plan the corrections again.' ),
		'plan_document_changed'      => array( 'category' => 'CORRECTION', 'severity' => 'warning', 'retryable' => false, 'message' => 'The draft changed after this plan was created, so the reviewed corrections no longer describe it. Plan the corrections again.' ),
		'plan_refused'               => array( 'category' => 'CORRECTION', 'severity' => 'warning', 'retryable' => false, 'message' => 'The reviewed corrections were refused before anything was written.' ),
		'snapshot_failed'            => array( 'category' => 'CORRECTION', 'severity' => 'error', 'retryable' => false, 'message' => 'A snapshot of the draft could not be created, so nothing was changed.' ),
		'snapshot_not_found'         => array( 'category' => 'CORRECTION', 'severity' => 'info', 'retryable' => false, 'message' => 'That snapshot is no longer available. ReplicaForge keeps snapshots for a limited time.' ),
		'regression_detected'        => array( 'category' => 'CORRECTION', 'severity' => 'warning', 'retryable' => false, 'message' => 'The correction improved one measurement but damaged another, so the previous document was restored.' ),

		/* Jobs. */
		'job_not_found'              => array( 'category' => 'JOB', 'severity' => 'info', 'retryable' => false, 'message' => 'That job is no longer available.' ),
		'job_not_resumable'          => array( 'category' => 'JOB', 'severity' => 'warning', 'retryable' => false, 'message' => 'This job cannot be resumed from where it stopped. Start it again.' ),
		'job_already_running'        => array( 'category' => 'JOB', 'severity' => 'info', 'retryable' => false, 'message' => 'This job is already running.' ),
		'job_cancelled'              => array( 'category' => 'JOB', 'severity' => 'info', 'retryable' => false, 'message' => 'This job was cancelled.' ),

		/* Permission. */
		'forbidden'                  => array( 'category' => 'PERMISSION', 'severity' => 'error', 'retryable' => false, 'message' => 'You do not have permission to do this. Only a site administrator can run ReplicaForge.' ),
		'authentication_required'    => array( 'category' => 'PERMISSION', 'severity' => 'error', 'retryable' => false, 'message' => 'You must be signed in to do this.' ),
		'invalid_nonce'              => array( 'category' => 'PERMISSION', 'severity' => 'error', 'retryable' => false, 'message' => 'Your session could not be verified. Reload the page and try again.' ),

		/* Storage. */
		'database_error'             => array( 'category' => 'DATABASE', 'severity' => 'error', 'retryable' => true, 'message' => 'ReplicaForge could not read or write its stored data. Try again shortly.' ),
		'resource_limit_reached'     => array( 'category' => 'RESOURCE', 'severity' => 'warning', 'retryable' => false, 'message' => 'This operation reached a ReplicaForge limit and stopped safely.' ),
		'memory_limit_reached'       => array( 'category' => 'RESOURCE', 'severity' => 'warning', 'retryable' => false, 'message' => 'This page needed more memory than the server allows, so the analysis stopped. A smaller page may work.' ),
		'time_limit_reached'         => array( 'category' => 'RESOURCE', 'severity' => 'warning', 'retryable' => false, 'message' => 'This operation reached the server time limit and stopped safely. Try again to resume where it stopped.' ),

		/* System. */
		'internal_error'             => array( 'category' => 'SYSTEM', 'severity' => 'critical', 'retryable' => true, 'message' => 'Something went wrong inside ReplicaForge. Nothing was changed. The details are in the ReplicaForge log.' ),

		/* Phase 10: plans, entitlements, usage accounting, licensing. */

		'feature_not_in_plan'        => array( 'category' => 'PLANS', 'severity' => 'info', 'retryable' => false, 'message' => 'That feature is not part of your current plan.' ),
		'usage_limit_reached'        => array( 'category' => 'PLANS', 'severity' => 'info', 'retryable' => false, 'message' => 'You have used the number of operations your plan allows this period.' ),
		'entity_limit_reached'       => array( 'category' => 'PLANS', 'severity' => 'info', 'retryable' => false, 'message' => 'Your plan does not allow any more of those.' ),
		'usage_lock_held'            => array( 'category' => 'PLANS', 'severity' => 'warning', 'retryable' => true, 'message' => 'Another ReplicaForge operation is still finishing. Try again in a moment.' ),
		'usage_too_many_open_operations' => array( 'category' => 'PLANS', 'severity' => 'warning', 'retryable' => false, 'message' => 'Too many ReplicaForge operations are already running for your account.' ),
		'usage_invalid_request'      => array( 'category' => 'PLANS', 'severity' => 'error', 'retryable' => false, 'message' => 'That usage request could not be recorded.' ),
		'usage_reservation_not_found' => array( 'category' => 'PLANS', 'severity' => 'warning', 'retryable' => false, 'message' => 'That operation was no longer being tracked, so no usage was charged.' ),
		'unknown_operation'          => array( 'category' => 'PLANS', 'severity' => 'error', 'retryable' => false, 'message' => 'That operation is not available.' ),
		'unknown_limit'              => array( 'category' => 'PLANS', 'severity' => 'error', 'retryable' => false, 'message' => 'That limit is not available.' ),
		'capability_missing'         => array( 'category' => 'PERMISSION', 'severity' => 'error', 'retryable' => false, 'message' => 'Your account is not allowed to do that.' ),
		'project_not_available'      => array( 'category' => 'PERMISSION', 'severity' => 'error', 'retryable' => false, 'message' => 'That project is not available.' ),
		'project_id_required'        => array( 'category' => 'USER_INPUT', 'severity' => 'info', 'retryable' => false, 'message' => 'No project was named.' ),
		'commercial_plan_not_found'  => array( 'category' => 'PLANS', 'severity' => 'error', 'retryable' => false, 'message' => 'That plan does not exist.' ),
		'plan_import_invalid'        => array( 'category' => 'PLANS', 'severity' => 'error', 'retryable' => false, 'message' => 'The plan configuration could not be read, so nothing was changed.' ),
		'trial_settings_invalid'     => array( 'category' => 'PLANS', 'severity' => 'error', 'retryable' => false, 'message' => 'The trial settings are not usable, so nothing was changed.' ),
		'trial_unavailable'          => array( 'category' => 'PLANS', 'severity' => 'info', 'retryable' => false, 'message' => 'A trial cannot be started right now.' ),
		'license_state_invalid'      => array( 'category' => 'PLANS', 'severity' => 'error', 'retryable' => false, 'message' => 'That license state is not one ReplicaForge recognises, so nothing was changed.' ),
		'license_not_configurable'   => array( 'category' => 'PLANS', 'severity' => 'info', 'retryable' => false, 'message' => 'This site uses an external licensing provider, so its license state cannot be set here.' ),
		'invalid_action'             => array( 'category' => 'USER_INPUT', 'severity' => 'info', 'retryable' => false, 'message' => 'That action is not available.' ),

		/* Phase 11: job orchestration and AI reliability. */

		'invalid_job_transition'     => array( 'category' => 'JOB', 'severity' => 'warning', 'retryable' => false, 'message' => 'That job cannot move to that state from where it is now.' ),
		'resource_locked'            => array( 'category' => 'JOB', 'severity' => 'warning', 'retryable' => true, 'message' => 'Another ReplicaForge operation is already working on this. It will be picked up in a moment.' ),
		'invalid_lock_resource'      => array( 'category' => 'JOB', 'severity' => 'error', 'retryable' => false, 'message' => 'That lock name is not usable.' ),
		'too_many_locks_held'        => array( 'category' => 'JOB', 'severity' => 'warning', 'retryable' => false, 'message' => 'This operation already holds too many locks.' ),
		'too_many_active_jobs'       => array( 'category' => 'JOB', 'severity' => 'info', 'retryable' => true, 'message' => 'You already have ReplicaForge operations running. Wait for one to finish before starting another.' ),
		'job_not_cancellable'        => array( 'category' => 'JOB', 'severity' => 'info', 'retryable' => false, 'message' => 'This job has already finished, so it cannot be cancelled.' ),
		'job_not_pausable'           => array( 'category' => 'JOB', 'severity' => 'info', 'retryable' => false, 'message' => 'This job cannot be paused.' ),
		'job_not_resumable'          => array( 'category' => 'JOB', 'severity' => 'info', 'retryable' => false, 'message' => 'This job cannot be resumed.' ),
		'job_already_running'        => array( 'category' => 'JOB', 'severity' => 'info', 'retryable' => false, 'message' => 'This job is already running.' ),

		/* Phase 14: content and data intelligence.
		 *
		 * Added to the *existing* catalogue rather than a second list, so a caller gets
		 * one code registry and one message per code. Every message says what happened
		 * and what the user can do about it, which is the property this catalogue has
		 * always enforced — `rollback_required` in particular says that ReplicaForge put
		 * the change back, because "something went wrong" during a write is exactly the
		 * case where a user needs to know their data is intact. */
		'content_provider_unavailable'  => array( 'category' => 'CONTENT', 'severity' => 'warning', 'retryable' => false, 'message' => 'That content source is not available on this site, so nothing was read from it.' ),
		'woocommerce_not_active'        => array( 'category' => 'CONTENT', 'severity' => 'info', 'retryable' => false, 'message' => 'WooCommerce is not active on this site. Content can still be mapped into WordPress posts and pages.' ),
		'no_products_found'             => array( 'category' => 'CONTENT', 'severity' => 'info', 'retryable' => false, 'message' => 'No products were found in this store, so nothing could be matched.' ),
		'mapping_not_found'             => array( 'category' => 'CONTENT', 'severity' => 'info', 'retryable' => false, 'message' => 'That mapping plan could not be found for this project.' ),
		'low_confidence_mapping'        => array( 'category' => 'CONTENT', 'severity' => 'warning', 'retryable' => false, 'message' => 'This mapping is not confident enough to apply on its own. Review it, and approve it if it looks right.' ),
		'mapping_conflict'              => array( 'category' => 'CONTENT', 'severity' => 'warning', 'retryable' => false, 'message' => 'This content already holds a different value. Nothing was changed, and the choice is yours.' ),
		'destination_field_unavailable' => array( 'category' => 'CONTENT', 'severity' => 'info', 'retryable' => false, 'message' => 'That destination field is not available, so the value was not mapped.' ),
		'source_content_changed'        => array( 'category' => 'CONTENT', 'severity' => 'warning', 'retryable' => false, 'message' => 'The source page content changed since this mapping was built, so it needs rebuilding.' ),
		'destination_content_changed'   => array( 'category' => 'CONTENT', 'severity' => 'warning', 'retryable' => false, 'message' => 'This content already holds a value that differs from the mapping, so ReplicaForge did not change it.' ),
		'invalid_mapping'               => array( 'category' => 'CONTENT', 'severity' => 'warning', 'retryable' => false, 'message' => 'That mapping is not valid, so it was not applied. Nothing was changed.' ),
		'unsupported_field'             => array( 'category' => 'CONTENT', 'severity' => 'info', 'retryable' => false, 'message' => 'That field is not one ReplicaForge can write, so it was skipped.' ),
		'validation_failed'             => array( 'category' => 'CONTENT', 'severity' => 'error', 'retryable' => false, 'message' => 'The mapping plan did not validate, so nothing was written.' ),
		'rollback_required'             => array( 'category' => 'CONTENT', 'severity' => 'error', 'retryable' => false, 'message' => 'The change did not fully apply, so ReplicaForge put it back. Nothing is half-written.' ),
		'permission_denied'             => array( 'category' => 'PERMISSION', 'severity' => 'error', 'retryable' => false, 'message' => 'Your account is not allowed to do that.' ),
	);

	/**
	 * Return the HTTP status a code should be returned with.
	 *
	 * @var array<string, int>
	 */
	const STATUS = array(
		'invalid_url'              => 400,
		'unsupported_protocol'     => 400,
		'invalid_port'             => 400,
		'dns_resolution_failed'    => 400,
		'not_public_page'          => 400,
		'credentials_not_allowed'  => 400,
		'forbidden'                => 403,
		'authentication_required'  => 401,
		'invalid_nonce'            => 403,
		'blocked_destination'      => 403,
		'blocked_port'             => 403,
		'forbidden_target'         => 403,
		'redirect_to_blocked_host' => 403,
		'not_found'                => 404,
		'job_not_found'            => 404,
		'plan_not_found'           => 404,
		'validation_not_found'     => 404,
		'snapshot_not_found'       => 404,
		'request_timeout'          => 408,
		'ai_timeout'               => 408,
		'response_too_large'       => 413,
		'request_failed'           => 502,
		'too_many_redirects'       => 502,
		'redirect_loop'            => 502,
		'invalid_redirect'         => 502,
		'empty_response'           => 502,
		'ai_provider_error'        => 502,
		'ai_rate_limited'          => 429,
		'too_many_requests'        => 429,
		'usage_lock_held'          => 409,
		'resource_locked'          => 409,
		'too_many_active_jobs'     => 429,
		'invalid_job_transition'   => 409,
		'job_already_running'      => 409,
		'job_not_cancellable'      => 409,
		'job_not_pausable'         => 409,
		'job_not_resumable'        => 409,
		'internal_error'           => 500,
	);

	/**
	 * Return the full description of an error code.
	 *
	 * An unknown code resolves to the `internal_error` message rather than to a
	 * blank, so a new code can never leave the user with no explanation. It is
	 * reported as not retryable, because an unmapped condition is one nobody has
	 * classified as transient. The original code is kept in `internal_code` for
	 * the log.
	 *
	 * @param string $code    Internal error code.
	 * @param string $context Optional already-localized override message.
	 * @return array<string, mixed>
	 */
	public static function describe( $code, $context = '' ) {
		$code = is_string( $code ) && '' !== $code ? $code : 'internal_error';
		$known = isset( self::CODES[ $code ] ) ? self::CODES[ $code ] : null;

		if ( null === $known ) {
			$entry     = self::CODES['internal_error'];
			$retryable = false;
		} else {
			$entry     = $known;
			$retryable = (bool) $entry['retryable'];
		}

		$category = isset( $entry['category'] ) ? (string) $entry['category'] : 'SYSTEM';
		$message  = ( is_string( $context ) && '' !== $context )
			? $context
			: (string) $entry['message'];

		return array(
			'code'              => $known ? $code : 'internal_error',
			'internal_code'     => $code,
			'category'          => $category,
			'severity'          => (string) $entry['severity'],
			'retryable'         => $retryable,
			'message'           => $message,
			'status'            => self::status_for( $known ? $code : 'internal_error' ),
			'known'             => null !== $known,
		);
	}

	/**
	 * Return the HTTP status for a code.
	 *
	 * @param string $code Internal error code.
	 * @return int
	 */
	public static function status_for( $code ) {
		if ( isset( self::STATUS[ $code ] ) ) {
			return (int) self::STATUS[ $code ];
		}
		$entry = isset( self::CODES[ $code ] ) ? self::CODES[ $code ] : null;
		if ( null !== $entry && isset( self::CATEGORY_SEVERITY[ $entry['category'] ] ) ) {
			$severity = (string) self::CATEGORY_SEVERITY[ $entry['category'] ];
			if ( 'critical' === $severity || 'error' === $severity ) {
				return 500;
			}
			if ( 'info' === $severity ) {
				return 400;
			}
		}
		return 400;
	}

	/**
	 * Return whether a failure of this code is worth retrying.
	 *
	 * Only transient conditions are retryable. An invalid address, a rejected
	 * value, or a permission failure will fail identically every time, so retrying
	 * it just wastes the user's time and the provider's quota.
	 *
	 * @param string $code Internal error code.
	 * @return bool
	 */
	public static function is_retryable( $code ) {
		$entry = isset( self::CODES[ $code ] ) ? self::CODES[ $code ] : null;
		return null !== $entry ? (bool) $entry['retryable'] : false;
	}

	/**
	 * Return the category for a code.
	 *
	 * @param string $code Internal error code.
	 * @return string
	 */
	public static function category_for( $code ) {
		$entry = isset( self::CODES[ $code ] ) ? self::CODES[ $code ] : null;
		return null !== $entry ? (string) $entry['category'] : 'SYSTEM';
	}

	/**
	 * Return the codes that are safe to retry, for documentation and tests.
	 *
	 * @return array<int, string>
	 */
	public static function retryable_codes() {
		$codes = array();
		foreach ( self::CODES as $code => $entry ) {
			if ( ! empty( $entry['retryable'] ) ) {
				$codes[] = (string) $code;
			}
		}
		return $codes;
	}
}
