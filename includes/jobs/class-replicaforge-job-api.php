<?php
/**
 * Phase 7: job, status, and maintenance REST routes.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The Phase 7 API surface.
 *
 * Kept separate from the Phase 1 to 6 controller so the existing routes are not
 * disturbed, and so the job contract is in one readable place.
 *
 * Every response uses the same envelope, and every response carries a request id
 * so a support conversation can start from one identifier.
 */
final class Job_Api {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'replicaforge/v1';

	/**
	 * Job runner.
	 *
	 * @var Job_Runner
	 */
	private $runner;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * System status.
	 *
	 * @var System_Status
	 */
	private $status;

	/**
	 * Maintenance.
	 *
	 * @var Maintenance
	 */
	private $maintenance;

	/**
	 * Constructor.
	 *
	 * @param Job_Runner     $runner      Job runner.
	 * @param Logger         $logger      Logger.
	 * @param System_Status  $status      System status.
	 * @param Maintenance    $maintenance Maintenance.
	 */
	public function __construct( $runner, $logger, $status, $maintenance ) {
		$this->runner      = $runner instanceof Job_Runner ? $runner : new Job_Runner();
		$this->logger      = $logger instanceof Logger ? $logger : new Logger();
		$this->status      = $status instanceof System_Status ? $status : new System_Status( $this->logger );
		$this->maintenance = $maintenance instanceof Maintenance ? $maintenance : new Maintenance( $this->logger );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_jobs' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'type'   => array(
						'type'              => 'string',
						'required'          => false,
						'enum'              => array_keys( Job_Limits::TYPES ),
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && isset( Job_Limits::TYPES[ $value ] );
						},
					),
					'status' => array(
						'type'              => 'string',
						'required'          => false,
						'enum'              => array_keys( Job_Limits::STATUSES ),
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && isset( Job_Limits::STATUSES[ $value ] );
						},
					),
					'limit'  => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 25,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 100;
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<job_id>[A-Za-z0-9_]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'job_id' => $this->job_id_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<job_id>[A-Za-z0-9_]+)/(?P<action>resume|retry|cancel|delete)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'job_action' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'job_id' => $this->job_id_arg(),
					'action' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'resume', 'retry', 'cancel', 'delete' ),
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && in_array( $value, array( 'resume', 'retry', 'cancel', 'delete' ), true );
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/replicas',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_replica' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'url'             => array(
						'type'              => 'string',
						'required'          => true,
						'maxLength'         => 2048,
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => array( $this, 'validate_url' ),
					),
					'use_ai'          => array(
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => array( $this, 'validate_boolean_param' ),
					),
					'visual'          => array(
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => array( $this, 'validate_boolean_param' ),
					),
					'import_assets'   => array(
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => array( $this, 'validate_boolean_param' ),
					),
					'idempotency_key' => array(
						'type'              => 'string',
						'required'          => false,
						'maxLength'         => 64,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static function ( $value ) {
							// A key must be a non-empty string. An empty one is not a key, and
							// treating it as one would collapse unrelated requests onto the same
							// job.
							return is_string( $value ) && '' !== trim( $value );
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_logs' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'level'  => array(
						'type'              => 'string',
						'required'          => false,
						'enum'              => Logger::LEVELS,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && in_array( $value, Logger::LEVELS, true );
						},
					),
					'search' => array(
						'type'              => 'string',
						'required'          => false,
						'maxLength'         => 100,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_search_param' ),
					),
					'limit'  => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 100,
						'minimum'           => 1,
						'maximum'           => 500,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 500;
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/logs/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_logs' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/maintenance/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_maintenance' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Require an authenticated administrator with a valid REST nonce.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public function can_manage( $request ) {
		if ( ! is_user_logged_in() ) {
			return $this->error( 'authentication_required', 401 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( 'forbidden', 403 );
		}
		$nonce = $request instanceof \WP_REST_Request ? $request->get_header( 'x_wp_nonce' ) : '';
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return $this->error( 'invalid_nonce', 403 );
		}
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return $this->error( 'invalid_nonce', 403 );
		}
		return true;
	}

	/**
	 * List jobs.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function list_jobs( $request ) {
		$repository = new Job_Repository( $this->logger );
		$jobs       = $repository->recent(
			array(
				'type'   => (string) $request->get_param( 'type' ),
				'status' => (string) $request->get_param( 'status' ),
			),
			(int) $request->get_param( 'limit' )
		);

		return $this->ok(
			array(
				'jobs'   => $jobs,
				'counts' => $repository->counts(),
			)
		);
	}

	/**
	 * Return one job.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_job( $request ) {
		$job = $this->repository()->find( (string) $request->get_param( 'job_id' ) );
		if ( null === $job ) {
			return $this->error( 'job_not_found', 404 );
		}
		return $this->ok( array( 'job' => $this->repository()->present( $job ) ) );
	}

	/**
	 * Act on a job.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function job_action( $request ) {
		$job_id  = (string) $request->get_param( 'job_id' );
		$action  = (string) $request->get_param( 'action' );
		$queue   = $this->queue();
		$repo    = $this->repository();

		switch ( $action ) {
			case 'cancel':
				$outcome = $queue->cancel( $job_id );
				break;
			case 'resume':
				$outcome = $queue->resume( $job_id );
				break;
			case 'retry':
				// A retry of a finished job is a new attempt on the same job, which
				// keeps the history in one place rather than scattering it.
				$outcome = $queue->resume( $job_id );
				if ( ! empty( $outcome['success'] ) ) {
					$queue->start( $job_id );
				}
				break;
			case 'delete':
				if ( ! $repo->delete( $job_id ) ) {
					return $this->error( 'job_not_found', 404 );
				}
				$this->logger->info( 'job_deleted', 'A job record was deleted.', array( 'job_id' => $job_id ), 'job' );
				return $this->ok( array( 'deleted' => true ) );
			default:
				return $this->error( 'not_found', 404 );
		}

		if ( empty( $outcome['success'] ) ) {
			$detail = isset( $outcome['error'] ) && is_array( $outcome['error'] ) ? $outcome['error'] : array();
			return $this->error(
				isset( $detail['internal_code'] ) ? (string) $detail['internal_code'] : 'job_not_found',
				isset( $detail['status'] ) ? (int) $detail['status'] : 400
			);
		}

		$job = $repo->find( $job_id );
		return $this->ok( array( 'job' => null === $job ? null : $repo->present( $job ) ) );
	}

	/**
	 * Queue a full replica job.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function create_replica( $request ) {
		$url = (string) $request->get_param( 'url' );

		// The URL is validated before the job is queued, so a request that could
		// never succeed is refused immediately rather than failing a minute later.
		$validation = ( new Url_Validator() )->validate( $url );
		if ( empty( $validation['success'] ) ) {
			$detail = isset( $validation['error'] ) && is_array( $validation['error'] ) ? $validation['error'] : array();
			return $this->error(
				isset( $detail['internal_code'] ) ? (string) $detail['internal_code'] : 'invalid_url',
				isset( $detail['status'] ) ? (int) $detail['status'] : 400
			);
		}

		$queue = $this->queue();
		$outcome = $queue->enqueue(
			'replica',
			array(
				'source_url'  => (string) $validation['url'],
				'source_host' => (string) $validation['host'],
				'use_ai'      => (bool) $request->get_param( 'use_ai' ),
				'visual'      => (bool) $request->get_param( 'visual' ),
				'import_assets' => (bool) $request->get_param( 'import_assets' ),
			),
			array(
				'idempotency_key' => (string) $request->get_param( 'idempotency_key' ),
				'start'           => true,
			)
		);

		$repository = $this->repository();
		$job        = $repository->find( (string) $outcome['job']['job_id'] );

		return $this->ok(
			array(
				'job'       => null === $job ? null : $repository->present( $job ),
				'duplicate' => (bool) $outcome['duplicate'],
			),
			$outcome['duplicate'] ? 200 : 202
		);
	}

	/**
	 * Return the system status report.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status() {
		return $this->ok(
			array(
				'status'   => $this->status->report(),
				'features' => ( new Feature_Flags() )->all(),
				'schema'   => ( new Migrator( $this->logger ) )->state(),
			)
		);
	}

	/**
	 * Return log entries.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_logs( $request ) {
		$logger = new Logger();
		$level  = (string) $request->get_param( 'level' );
		$search = (string) $request->get_param( 'search' );

		return $this->ok(
			array(
				'entries' => $logger->recent(
					array(
						'level'  => $level,
						'search' => $search,
					),
					(int) $request->get_param( 'limit' )
				),
				'summary' => $logger->summary(),
			)
		);
	}

	/**
	 * Export the log as newline-delimited JSON.
	 *
	 * @return \WP_REST_Response
	 */
	public function export_logs() {
		$logger = new Logger();
		$body   = $logger->export( array(), 500 );

		return new \WP_REST_Response(
			$body,
			200,
			array(
				'Content-Type'        => 'application/x-ndjson; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="replicaforge-log-' . gmdate( 'Ymd-His' ) . '.ndjson"',
			)
		);
	}

	/**
	 * Run the cleanup on demand.
	 *
	 * @return \WP_REST_Response
	 */
	public function run_maintenance() {
		$summary = $this->maintenance->daily();
		$this->logger->info( 'maintenance_manual', 'The cleanup was run from the admin.', array(), 'system' );
		return $this->ok( array( 'summary' => $summary ) );
	}

	/**
	 * Validate a boolean argument.
	 *
	 * Sanitizing alone is not enough: WordPress coerces several shapes to a
	 * boolean, so a bare string such as "maybe" would arrive as true. A flag the
	 * user did not clearly set should not switch a feature on, so only the
	 * recognized spellings are accepted.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function validate_boolean_param( $value ) {
		if ( is_bool( $value ) ) {
			return true;
		}
		if ( is_int( $value ) ) {
			return 0 === $value || 1 === $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '0', '1', 'true', 'false', 'yes', 'no', 'on', 'off' ), true );
		}
		return false;
	}

	/**
	 * Validate a log search term.
	 *
	 * The term is matched against every entry's message and context, so it is
	 * bounded and stripped of control characters. Returning false for a term that
	 * is merely long would be unhelpful; the length is declared separately, so
	 * this only refuses values that are not text at all.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function validate_search_param( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}
		return strlen( $value ) <= 100 && ! preg_match( '/[\x00-\x1f\x7f]/', $value );
	}

	/**
	 * Validate a submitted URL against the SSRF policy.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function validate_url( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return false;
		}
		$result = ( new Url_Validator() )->validate( $value );
		return ! empty( $result['success'] );
	}

	/**
	 * The shared job id argument.
	 *
	 * @return array<string, mixed>
	 */
	private function job_id_arg() {
		return array(
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static function ( $value ) {
				return is_string( $value ) && 1 === preg_match( '/^job_[a-f0-9]{12,32}$/', $value );
			},
		);
	}

	/**
	 * Return the job repository.
	 *
	 * @return Job_Repository
	 */
	private function repository() {
		return new Job_Repository( $this->logger );
	}

	/**
	 * Return the job queue.
	 *
	 * @return Job_Queue
	 */
	private function queue() {
		return new Job_Queue( $this->repository(), $this->logger );
	}

	/**
	 * Build a success envelope.
	 *
	 * @param array<string, mixed> $data   Payload.
	 * @param int                  $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private function ok( array $data, $status = 200 ) {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
				'meta'    => array_merge(
					Request_Context::meta(),
					array( 'schema_version' => Schema::DB_SCHEMA_VERSION )
				),
			),
			$status
		);
	}

	/**
	 * Build an error envelope from the catalog.
	 *
	 * Only the safe message and the code are returned. The internal code, the
	 * category, and the technical detail stay in the log, because a stack trace or
	 * a path in an HTTP response is a disclosure and a nuisance.
	 *
	 * @param string $code   Error code.
	 * @param int    $status Fallback status.
	 * @return \WP_REST_Response
	 */
	private function error( $code, $status = 400 ) {
		$detail = Error_Catalog::describe( $code );

		$this->logger->log(
			'critical' === (string) $detail['severity'] ? 'critical' : 'warning',
			'rest_error',
			(string) $detail['message'],
			array( 'code' => (string) $detail['internal_code'] ),
			(string) $detail['category']
		);

		return new \WP_REST_Response(
			array(
				'success' => false,
				'error'   => array(
					'code'      => (string) $detail['code'],
					'message'   => (string) $detail['message'],
					'retryable' => (bool) $detail['retryable'],
				),
				'meta'    => array_merge(
					Request_Context::meta(),
					array( 'schema_version' => Schema::DB_SCHEMA_VERSION )
				),
			),
			$status > 0 ? $status : (int) $detail['status']
		);
	}
}
