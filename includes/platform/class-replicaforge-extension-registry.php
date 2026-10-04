<?php
/**
 * Phase 20: the extension registry.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Registers extensions, enforces what they may do, and isolates their failures.
 *
 * ### Nothing is ever loaded from here
 *
 * This class does not read files, include code, or download anything. A provider is a PHP
 * **object** handed to {@see self::register()} by a theme or plugin that is already running
 * in the process, and a manifest is data that travels separately.
 *
 * That is the whole answer to "extensible without becoming an arbitrary-code execution
 * platform": there is no path through this class that turns a URL, an uploaded file, or a
 * manifest into executable code. A manifest decides *permissions*; a provider — which only
 * ever exists because some already-trusted PHP constructed it — decides *behaviour*.
 *
 * ### Two things are stored, separately
 *
 * - **Metadata** in `replicaforge_extensions`, so the console can list an extension across
 *   requests, including one whose provider is only constructed when it is active.
 * - **The provider object** in memory, for this request only. It is not serialised and not
 *   persisted, because an object that can be rehydrated from storage is an object that
 *   eventually gets stored.
 *
 * ### Failure isolation, in three parts
 *
 * 1. **Registration.** A provider whose declared capability has no matching method is
 *    refused by name, before it can be called.
 * 2. **Invocation.** Every call is wrapped. A provider that throws produces a finding and the
 *    extension is marked `failed`; the remaining providers still run. One bad extension
 *    cannot end a pipeline.
 * 3. **Return values.** Every return value is re-validated by ReplicaForge. A provider
 *    returning a shape it was not asked for gets that shape discarded, not stored.
 *
 * ### The call path is the only way in
 *
 * {@see self::call()} is the sole entry point, and it re-checks three things on every call:
 * the extension is live, it holds the capability, and it holds the permission that
 * capability's method requires. Those checks are repeated rather than cached into the
 * registration because an extension can be disabled between registration and call — which is
 * exactly what a disable button is for.
 */
class Extension_Registry {

	/**
	 * Registered providers, keyed by extension id.
	 *
	 * @var array<string, object>
	 */
	private $providers = array();

	/**
	 * Metadata for every registration, keyed by extension id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $records = array();

	/**
	 * Records read from the table, loaded lazily.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private $loaded = null;

	/**
	 * Store for extension metadata.
	 *
	 * @var Extension_Store
	 */
	private $store;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Findings from this request's extension calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $findings = array();

	/**
	 * Constructor.
	 *
	 * @param Extension_Store|null $store  Metadata store.
	 * @param Logger|null          $logger Logger.
	 */
	public function __construct( $store = null, $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
		$this->store  = $store instanceof Extension_Store ? $store : new Extension_Store( null, $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	/**
	 * Register a provider and its manifest.
	 *
	 * @param object             $provider A provider implementing the contract.
	 * @param array<string,mixed> $manifest The manifest.
	 * @return array<string, mixed> The stored record, or a record carrying `errors`.
	 */
	public function register( $provider, array $manifest ) {
		$validated = Extension_Manifest::validate( $manifest );

		if ( empty( $validated['ok'] ) ) {
			return $validated;
		}

		if ( ! is_object( $provider ) || ! ( $provider instanceof Extension_Provider_Contract ) ) {
			$validated['ok']       = false;
			$validated['errors'][] = __( 'An extension provider must implement the ReplicaForge extension contract.', 'replicaforge' );

			return $validated;
		}

		$id = (string) $validated['manifest']['id'];

		/*
		 * Every declared capability must have a callable method.
		 *
		 * This is the check that a per-capability interface would have made at compile time
		 * and cannot make here, because the manifest is data: the provider and the manifest
		 * are separate objects and PHP cannot know they were meant to agree. Checked here, it
		 * is checked against the same data that grants the capability, and it produces a
		 * refusal naming the missing method instead of a fatal three stages deep.
		 */
		$missing = array();

		foreach ( (array) $validated['capabilities'] as $capability ) {
			$method = isset( Extension_Provider_Contract::METHODS[ $capability ] )
				? (string) Extension_Provider_Contract::METHODS[ $capability ]['method']
				: '';

			if ( '' === $method || ! is_callable( array( $provider, $method ) ) ) {
				$missing[] = $capability;
			}
		}

		if ( array() !== $missing ) {
			$validated['ok']       = false;
			$validated['errors'][] = sprintf(
				/* translators: 1: comma-separated capability names, 2: the contract's expected method name. */
				__( 'This provider declares %1$s but does not implement the matching method. A capability declared in a manifest has to be a real method on the object, and they are checked against each other here.', 'replicaforge' ),
				implode( ', ', $missing )
			);

			return $validated;
		}

		// Dependency check against what is already registered this request.
		foreach ( (array) $validated['manifest']['dependencies'] as $dependency ) {
			if ( isset( $this->providers[ $dependency ] ) || isset( $this->records[ $dependency ] ) ) {
				continue;
			}

			$known = $this->store->find_by_extension_id( $dependency );

			if ( null === $known ) {
				$validated['warnings'][] = sprintf(
					/* translators: %s: a dependency id. */
					__( 'This extension depends on "%s", which is not installed. It will not be usable until that is.', 'replicaforge' ),
					$dependency
				);
			}
		}

		$prior = $this->store->find_by_extension_id( $id );

		$state = 'active';

		if ( ! empty( $validated['incompatibilities'] ) ) {
			/*
			 * `incompatible`, not `failed`. The manifest is valid; this install cannot run it.
			 * Forcing it would mean executing code that was written against an API shape that
			 * is not here, which is how a version bump breaks a site.
			 */
			$state = 'incompatible';
		} elseif ( null !== $prior && 'disabled' === (string) ( $prior['status'] ?? '' ) ) {
			/* A re-registration does not silently re-enable something an operator turned off.
			 * That is the difference between an update and a decision. */
			$state = 'disabled';
		}

		$record = array(
			'public_id'         => null !== $prior ? (string) $prior['public_id'] : '',
			'extension_id'      => $id,
			'status'            => $state,
			'capabilities'      => (array) $validated['capabilities'],
			'permissions'       => (array) $validated['permissions'],
			'manifest'          => wp_json_encode( $validated['manifest'] ),
			'incompatibilities' => wp_json_encode( $validated['incompatibilities'] ),
			'last_error'        => null !== $prior ? (string) ( $prior['last_error'] ?? '' ) : '',
			'failure_count'     => null !== $prior ? (int) ( $prior['failure_count'] ?? 0 ) : 0,
		);

		$stored = $this->store->save( $record );

		if ( is_wp_error( $stored ) ) {
			$validated['ok']       = false;
			$validated['errors'][] = (string) $stored->get_error_message();

			return $validated;
		}

		$this->providers[ $id ] = $provider;
		$this->records[ $id ]   = $stored;
		$this->loaded          = null;

		$this->logger->info(
			'extension_registered',
			'Registered an extension.',
			array( 'extension_id' => $id, 'state' => $state, 'capabilities' => count( (array) $validated['capabilities'] ) ),
			'platform'
		);

		/*
		 * Only live extensions contribute automations. An incompatible or disabled one is
		 * allowed to exist — the console shows it, and an operator can enable it later — but
		 * it must not be arranging workflows behind the operator's back.
		 */
		if ( Platform_Limits::is_live_extension( $state ) && in_array( 'automation', (array) $validated['capabilities'], true ) ) {
			$this->register_automations( $id, $provider );
		}

		/**
		 * Fires once an extension has been accepted and its record stored.
		 *
		 * @param array<string, mixed> $stored    The stored record.
		 * @param object               $provider  The provider.
		 * @param array<string, mixed> $validated The validation result.
		 */
		do_action( 'replicaforge_extension_registered', $stored, $provider, $validated );

		return $validated;
	}

	/**
	 * Mark an extension's capabilities unavailable after a failure.
	 *
	 * @param string $extension_id Extension id.
	 * @param string $reason       Why, in one line.
	 * @return bool
	 */
	public function disable_after_failure( $extension_id, $reason ) {
		$id = $this->clean_id( $extension_id );

		if ( '' === $id || ! isset( $this->records[ $id ] ) ) {
			return false;
		}

		$failures = (int) $this->records[ $id ]['failure_count'] + 1;

		$this->records[ $id ]['failure_count'] = $failures;

		/*
		 * Disabled after repeated failures rather than immediately. One throw is a bug worth
		 * seeing in the console with its state intact; five is a pattern, and continuing to
		 * call something that has thrown five times makes every later report harder to read.
		 */
		$state = $failures >= Platform_Limits::AUTOMATION_MAX_FAILURES ? 'failed' : (string) $this->records[ $id ]['status'];

		$this->store->save(
			array(
				'public_id'     => (string) $this->records[ $id ]['public_id'],
				'status'        => $state,
				'failure_count' => $failures,
				'last_error'    => substr( (string) $reason, 0, 300 ),
			)
		);

		$this->records[ $id ]['status']        = $state;
		$this->records[ $id ]['last_error']    = substr( (string) $reason, 0, 300 );

		$this->logger->warning(
			'extension_failure',
			'An extension failed while being called.',
			array( 'extension_id' => $id, 'failures' => $failures, 'state' => $state, 'reason' => substr( (string) $reason, 0, 160 ) ),
			'platform'
		);

		/**
		 * Fires when an extension has been disabled after repeated failures.
		 *
		 * @param string $extension_id Extension id.
		 * @param string $reason       Why.
		 * @param int    $failures     Consecutive failure count.
		 */
		do_action( 'replicaforge_extension_failed', $id, (string) $reason, $failures );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Invocation
	 * ------------------------------------------------------------------ */

	/**
	 * Call every live provider that declares a capability.
	 *
	 * @param string               $capability Capability to call.
	 * @param array<int, mixed>    $args       Positional arguments for the method.
	 * @param array<string, mixed> $context    Call context.
	 * @return array<string, mixed> Results keyed by extension id, plus `findings`.
	 */
	public function call( $capability, array $args = array(), array $context = array() ) {
		$out      = array();
		$findings = array();

		if ( ! Platform_Limits::is_extension_capability( $capability ) ) {
			return array(
				'results'  => array(),
				'findings' => array( array( 'code' => 'extension_capability_unknown', 'message' => __( 'That capability does not exist.', 'replicaforge' ) ) ),
			);
		}

		$this->load();

		$method    = (string) Extension_Provider_Contract::METHODS[ $capability ]['method'];
		$needed    = (string) Extension_Provider_Contract::METHODS[ $capability ]['permission'];
		$providers = $this->providers_for( $capability );

		foreach ( $providers as $id => $provider ) {
			$record = $this->records[ $id ] ?? array();

			if ( ! Platform_Limits::is_live_extension( $record['status'] ?? '' ) ) {
				continue;
			}

			if ( ! in_array( $needed, (array) ( $record['permissions'] ?? array() ), true ) ) {
				$findings[] = array(
					'extension_id' => $id,
					'code'         => 'extension_permission_missing',
					'message'      => sprintf(
						/* translators: 1: extension id, 2: the permission the capability requires. */
						__( 'The extension "%1$s" was not granted "%2$s", so it was not called.', 'replicaforge' ),
						$id,
						$needed
					),
				);
				continue;
			}

			$started = microtime( true );

			try {
				$result = is_callable( array( $provider, $method ) )
					? call_user_func_array( array( $provider, $method ), array_merge( $args, array( $context ) ) )
					: null;

				$out[ $id ] = $this->validate_result( $capability, $result );
			} catch ( \Throwable $error ) {
				/*
				 * One extension failing does not end the operation. The finding is recorded,
				 * the extension is counted towards its failure ceiling, and the next provider
				 * still runs. A pipeline that stopped because a third party threw would make
				 * ReplicaForge's reliability a function of its install base.
				 */
				$message = get_class( $error ) . ': ' . $error->getMessage();

				$findings[] = array(
					'extension_id' => $id,
					'code'         => 'extension_threw',
					'message'      => sprintf(
						/* translators: 1: extension id, 2: the error. */
						__( 'The extension "%1$s" failed and was skipped. ReplicaForge continued without it.', 'replicaforge' ),
						$id
					),
					'detail'       => substr( $message, 0, 200 ),
				);

				$this->disable_after_failure( $id, $message );

				/**
				 * Fires when an extension threw during a call.
				 *
				 * @param string               $extension_id Extension id.
				 * @param string               $capability   Capability being called.
				 * @param \Throwable            $error        The error.
				 */
				do_action( 'replicaforge_extension_threw', $id, $capability, $error );
			}

			$this->findings[] = array(
				'extension_id' => $id,
				'capability'   => $capability,
				'milliseconds' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			);
		}

		return array( 'results' => $out, 'findings' => $findings );
	}

	/**
	 * Validate what a provider returned.
	 *
	 * The provider is not trusted, so its output is re-checked against the shape its
	 * capability is defined to produce. This is the same posture as every other boundary in
	 * the plugin: the value arrives, and it is not taken at face value.
	 *
	 * @param string $capability Capability.
	 * @param mixed  $result     Raw result.
	 * @return mixed
	 */
	private function validate_result( $capability, $result ) {
		switch ( $capability ) {
			case 'analyzer':
				if ( ! is_array( $result ) ) {
					return array();
				}
				// Scalars and arrays of scalars, reduced by the Phase 1 boundary.
				$out = array();
				foreach ( array_slice( $result, 0, 100, true ) as $key => $value ) {
					$key = is_scalar( $key ) ? substr( (string) $key, 0, 60 ) : '';
					if ( '' === $key ) {
						continue;
					}
					$out[ $key ] = is_scalar( $value ) ? $value : Data_Redactor::structure( $value );
				}
				return $out;

			case 'component_provider':
			case 'template_provider':
			case 'content_mapper':
			case 'validation_provider':
				if ( ! is_array( $result ) ) {
					return array();
				}
				return array_slice( Data_Redactor::structure( $result ), 0, 100, true );

			case 'export_provider':
				/* A string and nothing else. An extension that returns an array is refused
				 * rather than serialised, because "export" that produces a structure rather
				 * than a document is a bug, and json-encoding it would hide the bug behind
				 * something that looks like output. */
				return is_string( $result ) ? substr( $result, 0, 1048576 ) : '';

			case 'notification_provider':
				return (bool) $result;

			case 'automation':
				return is_array( $result ) ? array_slice( array_values( $result ), 0, Platform_Limits::AUTOMATION_BATCH ) : array();

			default:
				return $result;
		}
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Return the providers registered this request for a capability.
	 *
	 * @param string $capability Capability.
	 * @return array<string, object>
	 */
	public function providers_for( $capability ) {
		$this->load();

		$out = array();

		foreach ( $this->providers as $id => $provider ) {
			$record = $this->records[ $id ] ?? array();

			if ( ! in_array( $capability, (array) ( $record['capabilities'] ?? array() ), true ) ) {
				continue;
			}

			$out[ $id ] = $provider;
		}

		return $out;
	}

	/**
	 * Return whether any live extension declares a capability.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	public function has_capability( $capability ) {
		$this->load();

		foreach ( $this->records as $record ) {
			if ( ! Platform_Limits::is_live_extension( $record['status'] ?? '' ) ) {
				continue;
			}
			if ( in_array( $capability, (array) ( $record['capabilities'] ?? array() ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * List every registered extension, optionally filtered.
	 *
	 * @param array<string, mixed> $args Filters: `status`, `capability`.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( array $args = array() ) {
		$this->load();

		$rows = array();

		foreach ( $this->records as $id => $record ) {
			$record['installed'] = isset( $this->providers[ $id ] );

			if ( isset( $args['status'] ) && Platform_Limits::is_extension_state( $args['status'] ) && (string) $record['status'] !== (string) $args['status'] ) {
				continue;
			}

			if ( isset( $args['capability'] ) && Platform_Limits::is_extension_capability( $args['capability'] ) && ! in_array( (string) $args['capability'], (array) $record['capabilities'], true ) ) {
				continue;
			}

			$rows[] = $this->present( $record );
		}

		usort(
			$rows,
			static function ( $left, $right ) {
				return strcmp( (string) $left['extension_id'], (string) $right['extension_id'] );
			}
		);

		return $rows;
	}

	/**
	 * Return one extension's record.
	 *
	 * @param string $extension_id Extension id.
	 * @return array<string, mixed>|null
	 */
	public function get( $extension_id ) {
		$this->load();

		$id = $this->clean_id( $extension_id );

		if ( '' === $id || ! isset( $this->records[ $id ] ) ) {
			return null;
		}

		/*
		 * `installed` is added here as well as in `all()`.
		 *
		 * The distinction it carries is real and worth keeping: an extension can have a stored
		 * record without a provider object in this request, which happens whenever the provider
		 * is only constructed when it is active. A record whose `status` is `active` but whose
		 * `installed` is false is the state a console has to be able to report — otherwise an
		 * operator sees "active" and concludes the extension's capabilities are callable, when
		 * they are not, because nothing registered a provider this request.
		 *
		 * `all()` set it inline; `get()` did not, so the two disagreed about the same record.
		 */
		$presented            = $this->present( $this->records[ $id ] );
		$presented['installed'] = isset( $this->providers[ $id ] );

		return $presented;
	}

	/**
	 * Set an extension's state.
	 *
	 * @param string $extension_id Extension id.
	 * @param string $state        Target state.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_state( $extension_id, $state ) {
		$id = $this->clean_id( $extension_id );

		if ( ! Platform_Limits::is_extension_state( $state ) ) {
			return new \WP_Error( 'extension_state_unknown', __( 'That is not an extension state.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		$this->load();

		if ( '' === $id || ! isset( $this->records[ $id ] ) ) {
			return new \WP_Error( 'extension_not_found', __( 'That extension is not registered.', 'replicaforge' ), array( 'status' => 404 ) );
		}

		if ( 'disabled' === $state && ! current_user_can( 'manage_options' ) && ! user_can( get_current_user_id(), 'replicaforge_use' ) ) {
			return new \WP_Error( 'extension_forbidden', __( 'You cannot change extensions in this workspace.', 'replicaforge' ), array( 'status' => 403 ) );
		}

		$this->records[ $id ]['status']        = $state;
		$this->records[ $id ]['failure_count'] = 0;
		$this->records[ $id ]['last_error']    = '';

		$this->store->save(
			array(
				'public_id'     => (string) $this->records[ $id ]['public_id'],
				'status'        => $state,
				'failure_count' => 0,
				'last_error'    => '',
			)
		);

		$this->loaded = null;

		/**
		 * Fires when an extension's state changes.
		 *
		 * @param string $extension_id Extension id.
		 * @param string $from        Previous state.
		 * @param string $to          New state.
		 */
		do_action( 'replicaforge_extension_state_changed', $id, (string) $this->records[ $id ]['status'], (string) $state );

		return $this->present( $this->records[ $id ] );
	}

	/**
	 * Return the effective settings for an extension.
	 *
	 * @param string $extension_id Extension id.
	 * @return array<string, mixed>
	 */
	public function settings( $extension_id ) {
		$record = $this->get( $extension_id );

		if ( null === $record ) {
			return array();
		}

		return Extension_Configuration::effective( (string) $extension_id, (array) $record['manifest']['configuration'] );
	}

	/**
	 * Write one setting for an extension.
	 *
	 * @param string $extension_id Extension id.
	 * @param string $field        Field name.
	 * @param mixed  $value        Value.
	 * @return true|\WP_Error
	 */
	public function set_setting( $extension_id, $field, $value ) {
		$record = $this->get( $extension_id );

		if ( null === $record ) {
			return new \WP_Error( 'extension_not_found', __( 'That extension is not registered.', 'replicaforge' ), array( 'status' => 404 ) );
		}

		return Extension_Configuration::set( (string) $extension_id, (string) $field, (array) $record['manifest']['configuration'], $value );
	}

	/**
	 * Return the diagnostics this request's calls produced.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findings() {
		return $this->findings;
	}

	/**
	 * Return a summary for the console.
	 *
	 * @return array<string, mixed>
	 */
	public function summary() {
		$this->load();

		$counts = array();
		$live   = 0;

		foreach ( $this->records as $record ) {
			$state = (string) ( $record['status'] ?? '' );
			$counts[ $state ] = ( $counts[ $state ] ?? 0 ) + 1;

			if ( Platform_Limits::is_live_extension( $state ) ) {
				$live++;
			}
		}

		$by_capability = array();

		foreach ( array_keys( Platform_Limits::EXTENSION_CAPABILITIES ) as $capability ) {
			$count = 0;

			foreach ( $this->records as $record ) {
				if ( Platform_Limits::is_live_extension( $record['status'] ?? '' ) && in_array( $capability, (array) ( $record['capabilities'] ?? array() ), true ) ) {
					$count++;
				}
			}

			$by_capability[ $capability ] = $count;
		}

		return array(
			'total'         => count( $this->records ),
			'live'          => $live,
			'by_status'     => $counts,
			'by_capability' => $by_capability,
			'findings'      => count( $this->findings ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Automations contributed by extensions
	 * ------------------------------------------------------------------ */

	/**
	 * Register the automations an extension asked for.
	 *
	 * @param string $extension_id Extension id.
	 * @param object $provider     Provider.
	 * @return int How many were stored.
	 */
	private function register_automations( $extension_id, $provider ) {
		$called = $this->call( 'automation', array(), array( 'workspace_id' => '', 'extension_id' => $extension_id ) );

		$store = new Automation_Store();
		$count = 0;

		foreach ( (array) $called['results'] as $id => $definitions ) {
			foreach ( $definitions as $definition ) {
				if ( ! is_array( $definition ) ) {
					continue;
				}

				$definition['extension_id'] = (string) $id;
				$definition['workspace_id'] = '';

				$stored = $store->save( $definition );

				if ( ! is_wp_error( $stored ) ) {
					$count++;
				}
			}
		}

		return $count;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Load metadata once.
	 *
	 * @return void
	 */
	private function load() {
		if ( null !== $this->loaded ) {
			return;
		}

		$this->loaded = array();

		foreach ( $this->store->all() as $record ) {
			$this->loaded[ (string) $record['extension_id'] ] = $record;
		}
	}

	/**
	 * Reduce a stored row to the shape callers read.
	 *
	 * @param array<string, mixed> $record Stored row.
	 * @return array<string, mixed>
	 */
	private function present( array $record ) {
		$manifest = $record['manifest'] ?? array();
		$manifest = is_array( $manifest ) ? $manifest : array();

		return array(
			'extension_id'      => (string) $record['extension_id'],
			'public_id'         => (string) $record['public_id'],
			'status'            => (string) $record['status'],
			'status_label'      => (string) ( Platform_Limits::EXTENSION_STATES[ (string) $record['status'] ] ?? (string) $record['status'] ),
			'name'              => (string) ( $manifest['name'] ?? (string) $record['extension_id'] ),
			'version'           => (string) ( $manifest['version'] ?? '' ),
			'author'            => (string) ( $manifest['author'] ?? '' ),
			'description'       => (string) ( $manifest['description'] ?? '' ),
			'homepage'          => (string) ( $manifest['homepage'] ?? '' ),
			'capabilities'      => array_values( (array) ( $record['capabilities'] ?? array() ) ),
			'permissions'       => array_values( (array) ( $record['permissions'] ?? array() ) ),
			'dependencies'      => array_values( (array) ( $manifest['dependencies'] ?? array() ) ),
			'requires'          => (array) ( $manifest['requires'] ?? array() ),
			'subscribes'        => array_values( (array) ( $manifest['subscribes'] ?? array() ) ),
			'manifest'          => $manifest,
			'configuration'     => (array) ( $manifest['configuration'] ?? array() ),
			'settings'          => Extension_Configuration::effective( (string) $record['extension_id'], (array) ( $manifest['configuration'] ?? array() ) ),
			'incompatibilities' => (array) ( $record['incompatibilities'] ?? array() ),
			'failure_count'     => (int) ( $record['failure_count'] ?? 0 ),
			'last_error'        => (string) ( $record['last_error'] ?? '' ),
			'live'              => Platform_Limits::is_live_extension( (string) $record['status'] ),
			'updated_at'        => (string) ( $record['updated_at'] ?? '' ),
		);
	}

	/**
	 * Reduce an extension id to its storable form.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ), 0, 64 ) : '';
	}
}
