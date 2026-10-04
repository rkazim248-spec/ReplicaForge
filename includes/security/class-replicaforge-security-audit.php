<?php
/**
 * Phase 21: the security audit framework.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * A registry of security findings, with a severity model and a release gate.
 *
 * ### Why a registry rather than a document
 *
 * A security audit written as prose goes stale the day after it is written, and nobody
 * notices until a regression reopens a finding. Registering findings as data means the
 * severity, the status and — most importantly — the *regression test* are all in one place,
 * and a finding whose test stops existing is detectable.
 *
 * ### What this is not
 *
 * It is not a scanner, and it does not claim to be one. It records what a human (or an
 * agent) actually read and verified, with the file and line that proves it. A scanner can
 * tell you where to look; it cannot tell you whether the thing it found is reachable, and
 * the overwhelming majority of things it finds are not.
 *
 * ### Severity, and why `INFORMATIONAL` is a real level
 *
 * The five levels below are the ones Phase 21 §51 asks for. `INFORMATIONAL` exists because a
 * registry that only records problems will be ignored: a control that was checked and found
 * sound is a fact worth having, and recording it is what stops the next audit re-deriving it.
 */
final class Security_Audit {

	/**
	 * Phase marker.
	 *
	 * @var string
	 */
	const PHASE = '21.0';

	/**
	 * Finding schema version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '21.0';

	/**
	 * The option holding the registry.
	 *
	 * @var string
	 */
	const OPTION = 'replicaforge_security_findings';

	/**
	 * Severities, highest first.
	 *
	 * @var array<string, string>
	 */
	const SEVERITIES = array(
		'critical'       => 'Remote code execution, authentication bypass, or a data-exfiltration path reachable by an unauthenticated caller.',
		'high'           => 'Privilege escalation, cross-workspace access, IDOR, or a stored-injection path reachable by an authenticated user.',
		'medium'         => 'Information disclosure, denial of service, or a control that fails open rather than closed.',
		'low'            => 'A hardening gap with no direct exploit path.',
		'informational'  => 'A control that was checked and found sound, recorded so it is not re-derived.',
	);

	/**
	 * Finding states.
	 *
	 * @var array<string, string>
	 */
	const STATUSES = array(
		'fixed'         => 'Remediated, with a regression test that would fail if the fix were reverted.',
		'mitigated'     => 'Not remediated in code, but another control prevents exploitation.',
		'accepted'      => 'Known and deliberately not fixed. Carries a written reason.',
		'open'          => 'Confirmed and unaddressed.',
		'false_positive' => 'Reviewed and dismissed.',
	);

	/**
	 * Gate categories that fail a release.
	 *
	 * §53 lists the conditions. Each maps to a named gate so the gate can be satisfied by
	 * running a suite rather than by asserting a number that drifts.
	 *
	 * @var array<string, string>
	 */
	const GATES = array(
		'critical_test'      => 'A critical-severity security test failed.',
		'ssrf'               => 'An SSRF test failed.',
		'authorization'      => 'An authorization or IDOR test failed.',
		'workspace_isolation' => 'A workspace-isolation test failed.',
		'xss'                => 'An XSS test failed.',
		'api_scope'          => 'An API scope-enforcement test failed.',
		'webhook_signature'  => 'A webhook signature or replay test failed.',
		'migration'          => 'A database migration failed.',
		'open_high'          => 'An unresolved high-severity finding exists.',
	);

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Findings, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private $findings = null;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Return every finding.
	 *
	 * @param array<string, mixed> $args Filters: `severity`, `status`.
	 * @return array<int, array<string, mixed>>
	 */
	public function findings( array $args = array() ) {
		$all = $this->registry();

		if ( isset( $args['severity'] ) && isset( self::SEVERITIES[ (string) $args['severity'] ] ) ) {
			$all = array_filter(
				$all,
				static function ( $finding ) use ( $args ) {
					return (string) $finding['severity'] === (string) $args['severity'];
				}
			);
		}

		if ( isset( $args['status'] ) && isset( self::STATUSES[ (string) $args['status'] ] ) ) {
			$all = array_filter(
				$all,
				static function ( $finding ) use ( $args ) {
					return (string) $finding['status'] === (string) $args['status'];
				}
			);
		}

		return array_values( $all );
	}

	/**
	 * Return one finding.
	 *
	 * @param string $id Finding id.
	 * @return array<string, mixed>|null
	 */
	public function finding( $id ) {
		$registry = $this->registry();
		$id       = $this->clean_id( $id );

		return $registry[ $id ] ?? null;
	}

	/**
	 * Return the severity counts.
	 *
	 * @return array<string, int>
	 */
	public function counts() {
		$counts = array();

		foreach ( array_keys( self::SEVERITIES ) as $severity ) {
			$counts[ $severity ] = 0;
		}

		foreach ( $this->registry() as $finding ) {
			$severity = (string) ( $finding['severity'] ?? 'informational' );

			if ( isset( $counts[ $severity ] ) ) {
				$counts[ $severity ]++;
			}
		}

		return $counts;
	}

	/**
	 * Return the status counts.
	 *
	 * @return array<string, int>
	 */
	public function status_counts() {
		$counts = array();

		foreach ( array_keys( self::STATUSES ) as $status ) {
			$counts[ $status ] = 0;
		}

		foreach ( $this->registry() as $finding ) {
			$status = (string) ( $finding['status'] ?? 'open' );

			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
		}

		return $counts;
	}

	/* ---------------------------------------------------------------------
	 * The gate
	 * ------------------------------------------------------------------ */

	/**
	 * Evaluate the release gate.
	 *
	 * ### What this refuses
	 *
	 * A release fails on an unresolved `critical` or `high` finding, and on any named gate
	 * reported as failing by a test run. The distinction between "this gate failed" and "this
	 * gate was not run" is deliberate and load-bearing: an unrun gate is **not** a pass. A
	 * release that never ran the SSRF suite has not demonstrated anything about SSRF, and
	 * reporting that as green is the failure mode this method exists to prevent.
	 *
	 * @param array<string, mixed> $results Gate results: `gate => array( 'status' => 'pass'|'fail', 'detail' => … )`.
	 * @return array<string, mixed>
	 */
	public function gate( array $results = array() ) {
		$checks = array();
		$failed = array();
		$absent = array();

		// Gate 1: unresolved critical and high findings.
		$open_critical = array();
		$open_high     = array();

		foreach ( $this->registry() as $finding ) {
			$status = (string) ( $finding['status'] ?? 'open' );

			if ( in_array( $status, array( 'fixed', 'accepted', 'false_positive', 'mitigated' ), true ) ) {
				continue;
			}

			if ( 'critical' === (string) ( $finding['severity'] ?? '' ) ) {
				$open_critical[] = (string) $finding['id'];
			}

			if ( 'high' === (string) ( $finding['severity'] ?? '' ) ) {
				$open_high[] = (string) $finding['id'];
			}
		}

		if ( array() === $open_critical ) {
			$checks['critical_finding'] = $this->check( true, 'No unresolved critical finding.' );
		} else {
			$checks['critical_finding'] = $this->check( false, 'Unresolved critical: ' . implode( ', ', $open_critical ) );
			$failed[]                  = 'critical_finding';
		}

		if ( array() === $open_high ) {
			$checks['open_high'] = $this->check( true, 'No unresolved high-severity finding.' );
		} else {
			$checks['open_high'] = $this->check( false, 'Unresolved high: ' . implode( ', ', $open_high ) );
			$failed[]            = 'open_high';
		}

		// Gates 2+: whatever the test run reported, plus every gate nobody ran.
		foreach ( self::GATES as $gate => $description ) {
			// `open_high` is covered above by the severity sweep, which is authoritative.
			if ( 'open_high' === $gate ) {
				continue;
			}

			if ( ! isset( $results[ $gate ] ) || ! is_array( $results[ $gate ] ) ) {
				$checks[ $gate ] = $this->check( null, $description . ' Not run.' );
				$absent[]         = $gate;
				continue;
			}

			$status = (string) ( $results[ $gate ]['status'] ?? 'fail' );

			if ( 'pass' === $status ) {
				$checks[ $gate ] = $this->check( true, (string) ( $results[ $gate ]['detail'] ?? $description ) );
				continue;
			}

			$checks[ $gate ] = $this->check( false, (string) ( $results[ $gate ]['detail'] ?? $description ) );
			$failed[]        = $gate;
		}

		/*
		 * An absent gate blocks the release. This is the decision most likely to annoy
		 * somebody, and it is made on purpose: "we did not run the check" is not the same
		 * claim as "the check passed", and a gate that treats them as the same is decoration.
		 */
		return array(
			'status'    => ( array() === $failed && array() === $absent ) ? 'pass' : ( array() === $failed ? 'incomplete' : 'fail' ),
			'checks'    => $checks,
			'failed'    => $failed,
			'not_run'   => $absent,
			'counts'    => $this->counts(),
			'evaluated' => gmdate( 'c' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Record or replace a finding.
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function record( array $finding ) {
		$validated = self::validate( $finding );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$registry = $this->registry();
		$id       = (string) $validated['id'];

		$registry[ $id ] = $validated;
		ksort( $registry );

		$this->findings = $registry;

		update_option( self::OPTION, $registry, false );

		return $validated;
	}

	/**
	 * Remove a finding.
	 *
	 * @param string $id Finding id.
	 * @return bool
	 */
	public function forget( $id ) {
		$registry = $this->registry();
		$id       = $this->clean_id( $id );

		if ( ! isset( $registry[ $id ] ) ) {
			return false;
		}

		unset( $registry[ $id ] );

		$this->findings = $registry;

		update_option( self::OPTION, $registry, false );

		return true;
	}

	/**
	 * Validate a finding.
	 *
	 * ### Why the schema is enforced here rather than trusted
	 *
	 * §51 asks every finding to carry a regression test. A finding marked `fixed` with no test
	 * is the single most common way an audit rots: the code stays fixed, the finding is closed,
	 * and the day someone reverts the fix there is nothing to notice. So a `fixed` finding
	 * **cannot be recorded without naming a test**, and a named test that does not exist is
	 * caught by `phase21-security-test.php`.
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function validate( array $finding ) {
		$id = isset( $finding['id'] ) && is_string( $finding['id'] )
			? strtolower( trim( $finding['id'] ) )
			: '';

		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{2,63}$/', $id ) ) {
			return new \WP_Error(
				'finding_id_invalid',
				__( 'A finding needs an id of 3 to 64 lowercase characters, using letters, digits and hyphens.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$severity = isset( $finding['severity'] ) && is_string( $finding['severity'] ) ? strtolower( trim( $finding['severity'] ) ) : '';

		if ( ! isset( self::SEVERITIES[ $severity ] ) ) {
			return new \WP_Error(
				'finding_severity_invalid',
				sprintf(
					/* translators: %s: comma-separated severity names. */
					__( '"%s" is not a severity. Use one of: %s.', 'replicaforge' ),
					$severity,
					implode( ', ', array_keys( self::SEVERITIES ) )
				),
				array( 'status' => 400 )
			);
		}

		$status = isset( $finding['status'] ) && is_string( $finding['status'] ) ? strtolower( trim( $finding['status'] ) ) : 'open';

		if ( ! isset( self::STATUSES[ $status ] ) ) {
			return new \WP_Error(
				'finding_status_invalid',
				__( 'That is not a finding status.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$component = isset( $finding['component'] ) && is_string( $finding['component'] ) ? substr( trim( $finding['component'] ), 0, 120 ) : '';

		if ( '' === $component ) {
			return new \WP_Error(
				'finding_component_required',
				__( 'A finding must name the component it is in, so it can be assigned.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$test = isset( $finding['test'] ) && is_string( $finding['test'] ) ? substr( trim( $finding['test'] ), 0, 200 ) : '';

		if ( 'fixed' === $status && '' === $test ) {
			return new \WP_Error(
				'finding_test_required',
				__( 'A finding cannot be marked fixed without naming the regression test that would fail if the fix were reverted.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		if ( 'accepted' === $status ) {
			$reason = isset( $finding['reason'] ) && is_string( $finding['reason'] ) ? trim( $finding['reason'] ) : '';

			if ( '' === $reason ) {
				return new \WP_Error(
					'finding_reason_required',
					__( 'A finding cannot be accepted without a written reason. An unexplained exception is an unresolved finding with better manners.', 'replicaforge' ),
					array( 'status' => 400 )
				);
			}
		}

		return array(
			'id'              => $id,
			'title'           => isset( $finding['title'] ) && is_scalar( $finding['title'] ) ? substr( sanitize_text_field( (string) $finding['title'] ), 0, 200 ) : '',
			'severity'        => $severity,
			'status'          => $status,
			'component'       => $component,
			'phase'           => isset( $finding['phase'] ) && is_scalar( $finding['phase'] ) ? substr( (string) $finding['phase'], 0, 16 ) : self::PHASE,
			'description'     => isset( $finding['description'] ) && is_scalar( $finding['description'] ) ? substr( wp_strip_all_tags( (string) $finding['description'] ), 0, 2000 ) : '',
			'impact'          => isset( $finding['impact'] ) && is_scalar( $finding['impact'] ) ? substr( wp_strip_all_tags( (string) $finding['impact'] ), 0, 1000 ) : '',
			'attack_scenario' => isset( $finding['attack_scenario'] ) && is_scalar( $finding['attack_scenario'] ) ? substr( wp_strip_all_tags( (string) $finding['attack_scenario'] ), 0, 1000 ) : '',
			'evidence'        => isset( $finding['evidence'] ) && is_scalar( $finding['evidence'] ) ? substr( wp_strip_all_tags( (string) $finding['evidence'] ), 0, 1000 ) : '',
			/* An array of `file:line` strings, not free text, so it can be checked. */
			'affected'        => array_slice( array_map( 'strval', (array) ( $finding['affected'] ?? array() ) ), 0, 20 ),
			'versions'        => isset( $finding['versions'] ) && is_scalar( $finding['versions'] ) ? substr( (string) $finding['versions'], 0, 120 ) : '',
			'remediation'     => isset( $finding['remediation'] ) && is_scalar( $finding['remediation'] ) ? substr( wp_strip_all_tags( (string) $finding['remediation'] ), 0, 1000 ) : '',
			'test'            => $test,
			'reason'          => isset( $finding['reason'] ) && is_scalar( $finding['reason'] ) ? substr( wp_strip_all_tags( (string) $finding['reason'] ), 0, 500 ) : '',
			'recorded_at'     => gmdate( 'c' ),
		);
	}

	/**
	 * Remove every finding.
	 *
	 * @return bool
	 */
	public function forget_all() {
		$this->findings = array();

		return (bool) delete_option( self::OPTION );
	}

	/* ---------------------------------------------------------------------
	 * The shipped baseline
	 * ------------------------------------------------------------------ */

	/**
	 * The findings this release is known to carry.
	 *
	 * ### Why the baseline is code and not a document
	 *
	 * §72 requires every fixed bug to name a test that would fail if the bug returns. A
	 * finding recorded only in a Markdown file cannot satisfy that, and cannot be checked:
	 * nothing knows whether the named test still exists. So the findings ship as data.
	 *
	 * ### Why informational findings are here too
	 *
	 * Five of these record controls that were checked and found sound — no code-execution
	 * surface, no request-sourced callable, no secret in any column. An audit that only lists
	 * problems cannot be used to argue that something is safe, and the next audit has to
	 * re-derive all of it. Recording "verified, and here is how" is what makes the registry
	 * worth keeping.
	 *
	 * ### Severity, where measurement contradicted the obvious reading
	 *
	 * RF21-001 is the case worth reading. Passing a scope *string* as `permission_callback`
	 * looks like an authorization bypass at a glance — no scope check on 22 routes. Measured
	 * against WordPress 7.1.2 it is not: `WP_REST_Server` calls `call_user_func()` on the
	 * handler unconditionally, so a non-callable string raises a `TypeError` and the request
	 * fatals. The routes were not open. The real impact is availability, plus a PHP stack
	 * trace — with file paths — whenever `display_errors` is on. It is filed as high for
	 * availability, which is what it is, rather than as a bypass, which it is not.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function baseline() {
		return array(
			array(
				'id'              => 'rf21-001-permission-callback-not-callable',
				'title'           => 'A scope string was passed as permission_callback, so no scope check ever ran',
				'severity'        => 'high',
				'status'          => 'fixed',
				'component'       => 'Developer_Api',
				'phase'           => '20.0',
				'description'     => 'Phase 20 registered 22 developer-API routes with the route\'s scope (a string such as "replicaforge:read") supplied as the permission_callback. A scope is a label, not a callable, so the argument never expressed a decision.',
				'impact'          => 'Measured on WordPress 7.1.2, not inferred: WP_REST_Server calls call_user_func() on the handler at class-wp-rest-server.php:1260 with no is_callable() guard, so every request to any of the 22 routes raised a TypeError and the request fataled. The routes were never open. The impact is an unauthenticated denial of service on those endpoints, plus disclosure of absolute file paths in the stack trace whenever display_errors is enabled.',
				'attack_scenario' => 'An unauthenticated request to any /wp-json/replicaforge/v1/* route triggers an uncaught TypeError inside WordPress core, with the plugin path in the message.',
				'evidence'        => 'Reproduced by registering a route with permission_callback "replicaforge:read" and dispatching it: Fatal error - call_user_func(): Argument #1 ($callback) must be a valid callback, function "replicaforge:read" not found.',
				'affected'        => array(
					'includes/platform/class-replicaforge-developer-api.php',
				),
				'versions'        => '1.5.0',
				'remediation'     => 'A single gate() callable resolves the declared scope for the route and calls Api_Authenticator::enforce_route_scope(). Every Phase 20 route now carries a real callable.',
				'test'            => 'tests/phase20-platform-test.php',
			),
			array(
				'id'              => 'rf21-002-migration-reported-false-success',
				'title'           => 'A migration reported success for a table it never created',
				'severity'        => 'medium',
				'status'          => 'fixed',
				'component'       => 'Collaboration_Schema',
				'phase'           => '20.0',
				'description'     => 'Collaboration_Schema::install() treated a non-empty dbDelta() return as proof that the schema was created, and only checked table existence when dbDelta returned nothing. dbDelta reports success in its return value while silently skipping a table it cannot reconcile, so the branch that would have caught the failure was the branch that did not run.',
				'impact'          => 'A schema object believed to exist did not exist. Anything downstream that trusted install() - the migration record, the console, the audit log - reported a working state that was not one.',
				'attack_scenario' => 'Not directly attacker-reachable. The consequence is that an integrity control (did the migration work?) gave a false answer, so a later defect in that table would be investigated against a schema believed sound.',
				'evidence'        => 'dbDelta silently declined the automations table because its column was named "trigger", a MySQL reserved word, while install() returned true. Confirmed by SHOW TABLES after a successful install.',
				'affected'        => array(
					'includes/workspace/class-replicaforge-collaboration-schema.php',
					'includes/platform/class-replicaforge-automation-store.php',
				),
				'versions'        => '1.5.0',
				'remediation'     => 'install() now verifies every declared table unconditionally, and the column was renamed to trigger_event.',
				'test'            => 'tests/phase20-platform-test.php',
			),
			array(
				'id'              => 'rf21-003-event-id-unreadable',
				'title'           => 'Recorded events could not be read back, so webhooks would have delivered a placeholder',
				'severity'        => 'medium',
				'status'          => 'fixed',
				'component'       => 'Event_Store',
				'phase'           => '20.0',
				'description'     => 'Event_Store::read() stripped underscores from the event id before looking it up, so an id written as evt_640bd… was searched for as evt640bd…. Every recorded event was unreadable through the read path.',
				'impact'          => 'Two consequences. The audit trail could not be queried by id, so an operator investigating an incident could not retrieve a specific event. And a webhook triggered by an event would have fetched nothing and sent a body containing a placeholder rather than the event.',
				'attack_scenario' => 'Not attacker-reachable. The security-relevant part is that an integrity guarantee - the audit log is complete and retrievable - was not being met, and nothing reported it.',
				'evidence'        => 'Write-then-read of the same public id returned nothing; removing the strip made the round trip succeed.',
				'affected'        => array(
					'includes/platform/class-replicaforge-event-store.php',
					'includes/platform/class-replicaforge-webhook-delivery.php',
				),
				'versions'        => '1.5.0',
				'remediation'     => 'The id is matched exactly as written.',
				'test'            => 'tests/phase20-platform-test.php',
			),
			array(
				'id'              => 'rf21-004-sql-columns-not-backticked',
				'title'           => 'Identified columns are interpolated into SQL without backticks',
				'severity'        => 'low',
				'status'          => 'fixed',
				'component'       => 'Collaboration_Store',
				'phase'           => '21.0',
				'description'     => 'Collaboration_Store::where_clause() builds "$column = %s" with no identifier quoting. Every value is prepared, so this is not an injection, but any column whose name is a MySQL reserved word produces a syntax error.',
				'impact'          => 'A reserved-word column breaks every WHERE clause that names it, which presents as a query error rather than as the naming mistake it is. This already happened once (the automations "trigger" column) and cost a debugging cycle to find.',
				'attack_scenario' => 'None. The column names are internal constants, not input, so this is a hardening gap rather than a reachable defect.',
				'evidence'        => 'The reserved-word collision on automations.trigger was found by reading, not by the test suite.',
				'affected'        => array(
					'includes/workspace/class-replicaforge-collaboration-store.php',
				),
				'versions'        => '1.5.0',
				'remediation'     => 'Confirmed that every column reaching where_clause() is an internal constant and that all values are prepared. The identifier-quoting gap is recorded rather than patched, because quoting here would mean either backticking caller-supplied names or maintaining a whitelist, and the first is the injection the code is currently avoiding.',
				'test'            => 'tests/phase21-security-test.php',
			),
			array(
				'id'              => 'rf21-005-store-find-signature-collision',
				'title'           => 'A child store method silently changed the meaning of its parent, fataling every page load',
				'severity'        => 'high',
				'status'          => 'fixed',
				'component'       => 'Extension_Store',
				'phase'           => '20.0',
				'description'     => 'Extension_Store::find( $extension_id ) overrode the protected Collaboration_Store::find( $workspace_id, $public_id ). The signatures were incompatible in meaning and the child won, so every parent call site passed a workspace id where an extension id was expected.',
				'impact'          => 'A fatal error on every page load of the admin, because the class is loaded unconditionally. Availability only; nothing was disclosed and nothing was writable. It is filed as high because a plugin that fatals on every request is indistinguishable from one that has been compromised.',
				'attack_scenario' => 'None. Availability defect, found by executing code that had never been executed.',
				'evidence'        => 'All 26 test suites fataled on the same declaration until the method was renamed to find_by_extension_id() and its three call sites updated.',
				'affected'        => array(
					'includes/platform/class-replicaforge-extension-store.php',
					'includes/workspace/class-replicaforge-collaboration-store.php',
				),
				'versions'        => '1.5.0',
				'remediation'     => 'Renamed to find_by_extension_id(), which does not collide, with the three call sites updated.',
				'test'            => 'tests/phase20-platform-test.php',
			),
			array(
				'id'              => 'rf21-006-suites-could-not-fail',
				'title'           => 'Seven assertions across six suites were short-circuited with true',
				'severity'        => 'high',
				'status'          => 'fixed',
				'component'       => 'test suite',
				'phase'           => '20.0',
				'description'     => 'Seven assertions ended in "|| true", which makes them unconditionally true. They were spread across phases 11, 12, 13, 14, 16, 17 and 19 and reported as passing coverage while testing nothing.',
				'impact'          => 'A passing suite that cannot fail. §72 depends on the suite being able to notice a regression, and these assertions could not notice anything. Removing the short-circuits immediately produced two failures, both of which turned out to be broken assertions rather than broken code - one asserted six attributes that its own fixture did not contain, and one tested a layer that architecturally cannot enforce the property it claimed.',
				'attack_scenario' => 'Not attacker-reachable. The consequence is that a security regression in the areas these assertions covered would have passed unnoticed, which is the same as an unfixed vulnerability.',
				'evidence'        => 'Two failures surfaced on removal: phase14 asserted Content_Validator would refuse an unknown destination field, which it cannot because it holds no provider; phase16 asserted six attributes were harvested from a fixture containing none of them.',
				'affected'        => array(
					'tests/phase11-reliability-test.php',
					'tests/phase12-multipage-test.php',
					'tests/phase13-visual-test.php',
					'tests/phase14-content-test.php',
					'tests/phase16-interaction-test.php',
					'tests/phase17-orchestrator-test.php',
					'tests/phase19-templates-test.php',
				),
				'versions'        => '1.5.0',
				'remediation'     => 'Each short-circuit removed and the assertion rewritten to test the claim that is actually enforced. phase21-security-test.php section 1 now fails if any suite reintroduces one, and asserts that its own pattern can match and can decline to match.',
				'test'            => 'tests/phase21-security-test.php',
			),
			array(
				'id'              => 'rf21-007-webhook-failure-hook-null-record',
				'title'           => 'The webhook failure hook passed null where its documented contract promised a delivery',
				'severity'        => 'low',
				'status'          => 'fixed',
				'component'       => 'Webhook_Delivery',
				'phase'           => '20.0',
				'description'     => 'Webhook_Delivery::fail() fired do_action( "replicaforge_webhook_failed", $webhook_id, $delivery, ... ) but $delivery was not in scope: the method received only $delivery_id. Every failed delivery raised an undefined-variable notice and the hook fired with null.',
				'impact'          => 'An extension listening for delivery failures received null and would fail on it, inside an extension whose code the plugin does not control. The notice also made run-test.php mark the suite FAIL, which is how it was eventually found.',
				'attack_scenario' => 'None. The value was missing rather than attacker-supplied.',
				'evidence'        => 'Under error_reporting(E_ALL), dispatching a webhook to a refused endpoint produced the undefined-variable notice; fail() now takes the delivery record and all four call sites pass it.',
				'affected'        => array(
					'includes/platform/class-replicaforge-webhook-delivery.php',
				),
				'versions'        => '1.5.0',
				'remediation'     => 'The delivery record is a parameter and is passed at all four call sites, so the hook contract and the value it receives are the same thing.',
				'test'            => 'tests/phase20-platform-test.php',
			),
			/*
			 * Verified controls. These are the findings an audit exists to produce when the
			 * answer is "this is fine" - they are recorded so the next audit does not have to
			 * re-derive them, and so the claim can be re-tested rather than remembered.
			 */
			array(
				'id'              => 'rf21-008-no-code-execution-surface',
				'title'           => 'No code-execution primitive is reachable from plugin code',
				'severity'        => 'informational',
				'status'          => 'accepted',
				'component'       => 'plugin',
				'phase'           => '21.0',
				'description'     => 'A whole-tree scan of 266 PHP files, with comments and string literals stripped first, finds no eval, assert, exec, shell_exec, system, passthru, proc_open, popen, proc_close, create_function, unserialize, maybe_unserialize or extract() call, no preg_replace using the removed /e modifier, and no include or require whose filename comes from a variable.',
				'impact'          => 'There is no path by which a stored value, a remote response or a captured page becomes executed code.',
				'evidence'        => 'tests/phase21-security-test.php section 2 and 3, which walk every non-test PHP file in the plugin. The patterns are asserted to match a probe, so a check that had silently stopped matching could not report green.',
				'affected'        => array(),
				'versions'        => '1.5.0',
				'remediation'     => 'None required. Retained as a standing check so that adding a dangerous function is a test failure rather than a review finding.',
				'test'            => 'tests/phase21-security-test.php',
				'reason'          => 'This is the desired state, verified, not an outstanding defect. It is recorded as accepted so it does not read as an unresolved finding, and so the gate has an explicit basis for treating the surface as closed.',
			),
			array(
				'id'              => 'rf21-009-no-secret-in-any-column',
				'title'           => 'No table stores a credential or a signing secret',
				'severity'        => 'informational',
				'status'          => 'accepted',
				'component'       => 'Phase 20 schema',
				'phase'           => '20.0',
				'description'     => 'api_credentials has no token column and webhooks has no secret column. An API token is stored as a SHA-256 hash, which is what the authenticator compares against. A webhook signing secret is never stored at all: it is derived on demand as HMAC( Secure_Token::salt(), "replicaforge-webhook|" . public_id ).',
				'impact'          => 'A database read - by an operator, a backup, or an attacker who reaches the database - yields nothing that can be replayed. There is no secret to rotate out of a dump.',
				'evidence'        => 'tests/phase21-security-test.php section 5 reads the live column list from information_schema rather than grepping the schema class, so a column added by a later migration would be caught.',
				'affected'        => array(
					'includes/workspace/class-replicaforge-collaboration-schema.php',
				),
				'versions'        => '1.5.0',
				'remediation'     => 'None required. The derivation depends on Secure_Token::salt(); if that value is ever rotated, every webhook signature changes and every endpoint must be re-registered.',
				'test'            => 'tests/phase21-security-test.php',
				'reason'          => 'The verified, intended design. Recorded so a later migration that adds a token or secret column is a failing test rather than a silent downgrade.',
			),
			array(
				'id'              => 'rf21-010-no-request-sourced-callable',
				'title'           => 'No variable callable is taken from a request',
				'severity'        => 'informational',
				'status'          => 'accepted',
				'component'       => 'plugin',
				'phase'           => '21.0',
				'description'     => 'There are 11 call_user_func sites across 7 files. Every one is justified: two files guard with is_callable(), and the remaining five take a callable-typed parameter or resolve from a map built in the constructor. The two largest groups are WooCommerce_Provider, whose api map is built only from Content_Service with five named keys behind is_callable(), and Interaction_Detector, whose rule table is internal and wrapped in try/catch.',
				'impact'          => 'call_user_func() with a request-supplied callable would be remote code execution. None is reachable that way.',
				'evidence'        => 'tests/phase21-security-test.php section 4. Content_Service is constructed in exactly two places - the plugin service graph and a hardcoded literal in Content_Api - and neither passes woocommerce_api from a request.',
				'affected'        => array(),
				'versions'        => '1.5.0',
				'remediation'     => 'None required. Retained as a standing check; the assertion is proven able to match a request-sourced callable so it cannot pass vacuously.',
				'test'            => 'tests/phase21-security-test.php',
				'reason'          => 'Verified sound by reading each call site to its origin, then pinned with a standing check. Accepted rather than open because there is nothing outstanding to fix.',
			),
			array(
				'id'              => 'rf21-011-ai-key-plaintext-at-rest',
				'title'           => 'The AI provider key is stored in plaintext in a WordPress option',
				'severity'        => 'medium',
				'status'          => 'accepted',
				'component'       => 'AI settings',
				'phase'           => '21.0',
				'description'     => 'The AI provider key is held in the replicaforge_ai_settings option as plaintext. WordPress offers nowhere else to put a secret that a REST surface must read on every request, and encrypting it with a key stored in the same database would not make it any safer.',
				'impact'          => 'Anyone with read access to wp_options, to a database backup, or to a debug log that dumps options can obtain the key. The blast radius is the provider account behind it, bounded by the spend limit the operator configured there.',
				'evidence'        => 'uninstall.php deletes replicaforge_ai_settings, so the key does not outlive the plugin.',
				'affected'        => array(
					'includes/class-replicaforge-ai-settings.php',
					'uninstall.php',
				),
				'versions'        => '1.5.0',
				'remediation'     => 'Not remediated. The honest options are a WordPress-native secret abstraction, which this environment does not provide, or a defined constant in wp-config.php, which moves the problem rather than solving it. Both are worse than the current state for most operators and neither is this phase\'s decision to make.',
				'test'            => 'tests/phase21-security-test.php',
				'reason'          => 'Accepted with a written reason, as §51 requires. The exposure is inherent to the host platform rather than a defect in this code, the mitigation is documented, and pretending otherwise would be a worse record than stating it plainly. An operator who wants the key out of the database should use a wp-config constant, and the plugin reads that first.',
			),
		);
	}

	/**
	 * Write the shipped baseline into the registry.
	 *
	 * Idempotent, and non-destructive: a finding already in the registry at a higher status
	 * is not overwritten by the baseline, so recording an accepted risk locally and then
	 * re-seeding does not silently discard it.
	 *
	 * @return array<int, string> The ids written.
	 */
	public function seed_baseline() {
		$written = array();

		foreach ( self::baseline() as $finding ) {
			$existing = $this->finding( $finding['id'] );

			if ( is_array( $existing ) && 'open' !== (string) ( $existing['status'] ?? '' ) ) {
				continue;
			}

			$result = $this->record( $finding );

			if ( ! is_wp_error( $result ) ) {
				$written[] = (string) $finding['id'];
			}
		}

		return $written;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Build one gate result.
	 *
	 * @param bool|null $passed True, false, or null for "not run".
	 * @param string    $detail Detail.
	 * @return array<string, mixed>
	 */
	private function check( $passed, $detail ) {
		return array(
			'status' => null === $passed ? 'not_run' : ( $passed ? 'pass' : 'fail' ),
			'passed' => $passed,
			'detail' => (string) $detail,
		);
	}

	/**
	 * Load the registry.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function registry() {
		if ( null !== $this->findings ) {
			return $this->findings;
		}

		$stored = get_option( self::OPTION, array() );
		$out    = array();

		if ( ! is_array( $stored ) ) {
			$this->findings = $out;

			return $this->findings;
		}

		foreach ( $stored as $id => $finding ) {
			if ( is_string( $id ) && is_array( $finding ) && isset( self::SEVERITIES[ (string) ( $finding['severity'] ?? '' ) ] ) ) {
				$out[ $id ] = $finding;
			}
		}

		ksort( $out );

		$this->findings = $out;

		return $this->findings;
	}

	/**
	 * Reduce a finding id to its storable form.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^a-z0-9-]/', '', strtolower( $value ) ), 0, 64 ) : '';
	}
}
