<?php
/**
 * Phase 7: system status.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether this install can run ReplicaForge, and why not when it cannot.
 *
 * Every check answers a question an administrator would otherwise have to work
 * out by reading code, and a failing check carries the action that fixes it. A
 * status screen that reports "false" without saying what to do is not useful.
 */
final class System_Status {

	/**
	 * Minimum WordPress version this release is tested against.
	 */
	const MIN_WP = '6.2';

	/**
	 * Minimum PHP version this release is tested against.
	 */
	const MIN_PHP = '7.4';

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
	 * Return every check, with an overall verdict.
	 *
	 * @return array<string, mixed>
	 */
	public function report() {
		$checks = array(
			'wordpress'    => $this->check_wordpress(),
			'php'          => $this->check_php(),
			'plugin'       => $this->check_plugin(),
			'rest'         => $this->check_rest(),
			'filesystem'   => $this->check_filesystem(),
			'elementor'    => $this->check_elementor(),
			'ai'           => $this->check_ai(),
			'http'         => $this->check_http(),
			'cron'         => $this->check_cron(),
			'memory'       => $this->check_memory(),
			'database'     => $this->check_database(),
			'schema'       => $this->check_schema(),
			'collaboration'=> $this->check_collaboration_tables(),
			'background'   => $this->check_background(),
			'filesystem_w' => $this->check_uploads(),
		);

		$blocking = 0;
		$warning  = 0;
		foreach ( $checks as $check ) {
			if ( 'fail' === $check['state'] ) {
				$blocking++;
			} elseif ( 'warn' === $check['state'] ) {
				$warning++;
			}
		}

		return array(
			'state'    => $blocking > 0 ? 'fail' : ( $warning > 0 ? 'warn' : 'ok' ),
			'blocking' => $blocking,
			'warnings' => $warning,
			'checks'   => $checks,
			'versions' => Schema::all(),
		);
	}

	/**
	 * Return a short verdict for the dashboard.
	 *
	 * @return array<string, mixed>
	 */
	public function summary() {
		$report  = $this->report();
		$summary = array(
			'state'  => $report['state'],
			'label'  => 'ok' === $report['state']
				? __( 'Ready', 'replicaforge' )
				: ( 'warn' === $report['state']
					? __( 'Ready with warnings', 'replicaforge' )
					: __( 'Action required', 'replicaforge' ) ),
		);

		foreach ( $report['checks'] as $name => $check ) {
			if ( 'fail' === $check['state'] ) {
				$summary['blocking'][] = $name;
			}
		}
		if ( empty( $summary['blocking'] ) ) {
			$summary['blocking'] = array();
		}
		return $summary;
	}

	/**
	 * Build one check result.
	 *
	 * @param string $state  `ok`, `warn`, or `fail`.
	 * @param string $label  Short label.
	 * @param string $detail Detail for the screen.
	 * @param string $action What to do about it, or an empty string.
	 * @return array<string, mixed>
	 */
	private function result( $state, $label, $detail = '', $action = '' ) {
		return array(
			'state'  => $state,
			'label'  => $label,
			'detail' => (string) $detail,
			'action' => (string) $action,
		);
	}

	/**
	 * Check the WordPress version.
	 *
	 * @return array<string, mixed>
	 */
	private function check_wordpress() {
		$version = get_bloginfo( 'version' );
		if ( version_compare( $version, self::MIN_WP, '<' ) ) {
			return $this->result(
				'fail',
				__( 'WordPress is too old', 'replicaforge' ),
				sprintf(
					/* translators: 1: Found version, 2: Required version. */
					__( 'WordPress %1$s is installed. ReplicaForge needs %2$s or newer.', 'replicaforge' ),
					$version,
					self::MIN_WP
				),
				__( 'Update WordPress from Dashboard → Updates.', 'replicaforge' )
			);
		}
		return $this->result( 'ok', __( 'WordPress', 'replicaforge' ), $version );
	}

	/**
	 * Check the PHP version and the extensions ReplicaForge uses.
	 *
	 * @return array<string, mixed>
	 */
	private function check_php() {
		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			return $this->result(
				'fail',
				__( 'PHP is too old', 'replicaforge' ),
				sprintf(
					/* translators: 1: Found version, 2: Required version. */
					__( 'PHP %1$s is running. ReplicaForge needs %2$s or newer.', 'replicaforge' ),
					PHP_VERSION,
					self::MIN_PHP
				),
				__( 'Ask your host to update PHP.', 'replicaforge' )
			);
		}

		$missing = array();
		foreach ( array( 'json', 'mbstring' ) as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$missing[] = $extension;
			}
		}
		if ( ! empty( $missing ) ) {
			return $this->result(
				'warn',
				__( 'PHP extensions missing', 'replicaforge' ),
				sprintf(
					/* translators: %s: Comma-separated extension names. */
					__( 'These PHP extensions are not loaded: %s. ReplicaForge will still work, with reduced text handling.', 'replicaforge' ),
					implode( ', ', $missing )
				),
				__( 'Ask your host to enable the extensions.', 'replicaforge' )
			);
		}

		return $this->result( 'ok', __( 'PHP', 'replicaforge' ), PHP_VERSION );
	}

	/**
	 * Check the plugin version against the recorded one.
	 *
	 * @return array<string, mixed>
	 */
	private function check_plugin() {
		$current = defined( 'REPLICAFORGE_VERSION' ) ? REPLICAFORGE_VERSION : '0.0.0';
		return $this->result( 'ok', __( 'ReplicaForge', 'replicaforge' ), $current );
	}

	/**
	 * Check the REST API.
	 *
	 * @return array<string, mixed>
	 */
	private function check_rest() {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return $this->result(
				'warn',
				__( 'REST API unavailable', 'replicaforge' ),
				__( 'The REST API is not loaded, so the admin screens fall back to a direct request.', 'replicaforge' ),
				__( 'Check for a PHP error in the site log.', 'replicaforge' )
			);
		}
		$routes = rest_get_server()->get_routes();
		$mine   = 0;
		foreach ( array_keys( $routes ) as $route ) {
			if ( 0 === strpos( (string) $route, '/replicaforge/v1' ) ) {
				$mine++;
			}
		}
		if ( $mine < 2 ) {
			return $this->result(
				'warn',
				__( 'ReplicaForge routes not registered', 'replicaforge' ),
				__( 'Only the namespace is registered. The API is partially available.', 'replicaforge' )
			);
		}
		return $this->result(
			'ok',
			__( 'REST API', 'replicaforge' ),
			sprintf(
				/* translators: %d: Number of registered routes. */
				_n( '%d route registered', '%d routes registered', $mine, 'replicaforge' ),
				$mine
			)
		);
	}

	/**
	 * Check the plugin directory is readable.
	 *
	 * @return array<string, mixed>
	 */
	private function check_filesystem() {
		$path = defined( 'REPLICAFORGE_PATH' ) ? REPLICAFORGE_PATH : '';
		if ( '' === $path || ! is_dir( $path ) ) {
			return $this->result( 'warn', __( 'Plugin path unreadable', 'replicaforge' ), __( 'The plugin directory could not be read.', 'replicaforge' ) );
		}
		return $this->result( 'ok', __( 'Plugin files', 'replicaforge' ), __( 'Readable', 'replicaforge' ) );
	}

	/**
	 * Check the uploads directory, which imported assets land in.
	 *
	 * @return array<string, mixed>
	 */
	private function check_uploads() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return $this->result(
				'warn',
				__( 'Uploads unavailable', 'replicaforge' ),
				(string) $uploads['error'],
				__( 'Check the media folder permissions.', 'replicaforge' )
			);
		}
		return $this->result( 'ok', __( 'Uploads', 'replicaforge' ), (string) $uploads['basedir'] );
	}

	/**
	 * Check Elementor.
	 *
	 * @return array<string, mixed>
	 */
	private function check_elementor() {
		$status = ( new Elementor_Generator() )->status();
		if ( ! empty( $status['available'] ) ) {
			return $this->result(
				'ok',
				__( 'Elementor', 'replicaforge' ),
				defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : __( 'Active', 'replicaforge' )
			);
		}

		$reason = isset( $status['reason'] ) ? (string) $status['reason'] : '';
		if ( 'elementor_missing' === $reason || '' === $reason ) {
			return $this->result(
				'fail',
				__( 'Elementor not installed', 'replicaforge' ),
				__( 'Analysis and AI planning work without Elementor, but no draft can be generated.', 'replicaforge' ),
				__( 'Install and activate Elementor.', 'replicaforge' )
			);
		}
		return $this->result(
			'fail',
			__( 'Elementor not active', 'replicaforge' ),
			__( 'Elementor is installed but not active, so no draft can be generated.', 'replicaforge' ),
			__( 'Activate Elementor.', 'replicaforge' )
		);
	}

	/**
	 * Check the AI provider.
	 *
	 * @return array<string, mixed>
	 */
	private function check_ai() {
		$settings = new Ai_Settings();
		$public   = ( new Ai_Manager( $settings ) )->get_public_settings();
		if ( ! empty( $public['enabled'] ) ) {
			return $this->result(
				'ok',
				__( 'AI provider', 'replicaforge' ),
				sprintf(
					/* translators: 1: Provider name, 2: Model name. */
					__( '%1$s, model %2$s', 'replicaforge' ),
					isset( $public['provider'] ) ? (string) $public['provider'] : 'unknown',
					isset( $public['model'] ) ? (string) $public['model'] : 'unknown'
				)
			);
		}
		return $this->result(
			'warn',
			__( 'AI not configured', 'replicaforge' ),
			__( 'ReplicaForge generates a draft without AI. Configure a provider to improve the reconstruction plan.', 'replicaforge' ),
			__( 'Add a provider on the Settings screen.', 'replicaforge' )
		);
	}

	/**
	 * Check whether outbound requests work.
	 *
	 * @return array<string, mixed>
	 */
	private function check_http() {
		$url = wp_http_validate_url( 'https://api.wordpress.org/core/version-check/1.7/' );
		if ( ! is_string( $url ) || '' === $url ) {
			return $this->result(
				'warn',
				__( 'Outbound requests blocked', 'replicaforge' ),
				__( 'WordPress refused a WordPress.org request, which usually means a firewall rule.', 'replicaforge' ),
				__( 'Allow outbound HTTPS in your host firewall.', 'replicaforge' )
			);
		}
		return $this->result( 'ok', __( 'Outbound requests', 'replicaforge' ), __( 'Allowed', 'replicaforge' ) );
	}

	/**
	 * Check WP-Cron.
	 *
	 * @return array<string, mixed>
	 */
	private function check_cron() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return $this->result(
				'warn',
				__( 'WP-Cron disabled', 'replicaforge' ),
				__( 'Background jobs will not run automatically. Analyze and generate still work when you run them directly.', 'replicaforge' ),
				__( 'Set up a real cron calling wp-cron.php, or run ReplicaForge jobs from the System Status screen.', 'replicaforge' )
			);
		}
		return $this->result( 'ok', __( 'WP-Cron', 'replicaforge' ), __( 'Available', 'replicaforge' ) );
	}

	/**
	 * Check whether background jobs can run.
	 *
	 * @return array<string, mixed>
	 */
	private function check_background() {
		$maintenance = new Maintenance( $this->logger );
		$scheduled   = wp_next_scheduled( Maintenance::JOB_HOOK );
		$flags       = new Feature_Flags();
		if ( ! $flags->enabled( 'background_jobs_enabled' ) ) {
			return $this->result( 'warn', __( 'Background jobs off', 'replicaforge' ), __( 'Jobs are queued but not processed automatically.', 'replicaforge' ) );
		}
		if ( ! $scheduled ) {
			$maintenance->schedule();
			return $this->result( 'warn', __( 'Background jobs unscheduled', 'replicaforge' ), __( 'The job schedule was missing and has been restored.', 'replicaforge' ) );
		}
		return $this->result(
			'ok',
			__( 'Background jobs', 'replicaforge' ),
			sprintf(
				/* translators: %s: Human-readable time difference. */
				__( 'Next run %s', 'replicaforge' ),
				human_time_diff( (int) $scheduled )
			)
		);
	}

	/**
	 * Check the memory limit against what analysis needs.
	 *
	 * @return array<string, mixed>
	 */
	private function check_memory() {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return $this->result( 'ok', __( 'PHP memory', 'replicaforge' ), __( 'Unlimited', 'replicaforge' ) );
		}
		$megabytes = (int) round( $limit / MB_IN_BYTES );
		if ( $megabytes < 128 ) {
			return $this->result(
				'warn',
				__( 'PHP memory is low', 'replicaforge' ),
				sprintf(
					/* translators: %d: Megabytes. */
					__( '%d MB is available. Large pages may stop early.', 'replicaforge' ),
					$megabytes
				),
				__( 'Raise memory_limit to 256M or more.', 'replicaforge' )
			);
		}
		return $this->result( 'ok', __( 'PHP memory', 'replicaforge' ), sprintf( '%d MB', $megabytes ) );
	}

	/**
	 * Check the execution time limit.
	 *
	 * @return array<string, mixed>
	 */
	private function check_time() {
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit <= 0 ) {
			return $this->result( 'ok', __( 'PHP time limit', 'replicaforge' ), __( 'Unlimited', 'replicaforge' ) );
		}
		return $this->result( 'ok', __( 'PHP time limit', 'replicaforge' ), sprintf( '%d seconds', $limit ) );
	}

	/**
	 * Check the database.
	 *
	 * @return array<string, mixed>
	 */
	private function check_database() {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$value    = $wpdb->get_var( 'SELECT 1' );
		$wpdb->suppress_errors( $suppress );

		if ( '1' !== (string) $value ) {
			return $this->result(
				'fail',
				__( 'Database unavailable', 'replicaforge' ),
				__( 'ReplicaForge could not read from the database.', 'replicaforge' ),
				__( 'Check the database credentials in wp-config.php.', 'replicaforge' )
			);
		}
		return $this->result(
			'ok',
			__( 'Database', 'replicaforge' ),
			$wpdb->db_version()
		);
	}

	/**
	 * Check whether stored data needs migrating.
	 *
	 * @return array<string, mixed>
	 */
	/**
	 * Report whether the Phase 15 tables are present.
	 *
	 * ### Why this is separate from check_schema()
	 *
	 * `check_schema()` compares the recorded schema version against the expected one. That
	 * answers "has the migration run", and it reads a *recorded* version - a value in an
	 * option. It cannot answer "are the tables actually there", and the two are different
	 * facts: an option can record 15.0.0 on an install whose tables were dropped by a failed
	 * upgrade, a database restore, or a partial `dbDelta`.
	 *
	 * That distinction is why this check looks at the database rather than at an option. A
	 * version string that says the migration ran, while no table exists, is exactly the
	 * state where collaboration screens would fatal, and an administrator needs to be told
	 * before they click one.
	 *
	 * Reported as a warning rather than a failure. The reconstruction engine - phases 1 to
	 * 14 - does not use these tables, so a site missing them still analyses, generates and
	 * validates. Blocking the whole plugin's status on a collaboration table would report
	 * a working install as broken.
	 *
	 * @return array<string, mixed>
	 */
	private function check_collaboration_tables() {
		if ( ! class_exists( '\ReplicaForge\Collaboration_Schema' ) ) {
			return $this->result(
				'ok',
				__( 'Collaboration tables', 'replicaforge' ),
				__( 'not part of this installation', 'replicaforge' )
			);
		}

		$schema = new \ReplicaForge\Collaboration_Schema();
		$status = $schema->status();

		$present = (array) ( $status['present'] ?? array() );
		$missing = (array) ( $status['missing'] ?? array() );

		if ( array() === $missing ) {
			return $this->result(
				'ok',
				__( 'Collaboration tables', 'replicaforge' ),
				sprintf(
					/* translators: 1: Table count, 2: Recorded schema version. */
					__( 'all %1$d present (schema %2$s)', 'replicaforge' ),
					count( $present ),
					// The *recorded* version rather than the constant, so the two checks can
					// disagree visibly. If the constant moved and the option did not, the
					// schema check above reports it and this one does not paper over it.
					'' === (string) ( $status['version'] ?? '' ) ? (string) \ReplicaForge\Collaboration_Schema::VERSION : (string) $status['version']
				)
			);
		}

		return $this->result(
			'warn',
			__( 'Collaboration tables', 'replicaforge' ),
			sprintf(
				/* translators: 1: Present count, 2: Missing table names. */
				__( '%1$d present. Missing: %2$s. The reconstruction engine is unaffected, but collaboration screens will not work until the migration is re-run.', 'replicaforge' ),
				count( $present ),
				implode( ', ', $missing )
			),
			__( 'Re-run the migration from the System Status screen.', 'replicaforge' )
		);
	}

	/**
	 * Report whether the recorded schema version matches the expected one.
	 *
	 * This reads the *recorded* version, not the database. The two are not interchangeable,
	 * and the reason is worth stating: an option can record 15.0.0 on an install whose
	 * tables were dropped by a failed upgrade, a database restore, or a partial dbDelta. The
	 * tables themselves are checked by {@see self::check_collaboration_tables()}, and the
	 * two checks are reported separately so a disagreement between them is visible.
	 *
	 * @return array<string, mixed>
	 */
	private function check_schema() {
		$state = ( new Migrator( $this->logger ) )->state();
		if ( empty( $state['up_to_date'] ) ) {
			return $this->result(
				'warn',
				__( 'Schema update pending', 'replicaforge' ),
				sprintf(
					/* translators: 1: Installed version, 2: Required version. */
					__( 'Stored data is at schema %1$s; this release expects %2$s.', 'replicaforge' ),
					'' === (string) $state['installed'] ? __( 'unknown', 'replicaforge' ) : (string) $state['installed'],
					(string) $state['current']
				),
				__( 'Open System Status and run the migration.', 'replicaforge' )
			);
		}
		return $this->result( 'ok', __( 'Data schema', 'replicaforge' ), (string) $state['installed'] );
	}
}
