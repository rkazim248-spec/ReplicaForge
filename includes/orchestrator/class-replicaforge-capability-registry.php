<?php
/**
 * Phase 17: what this install can actually do, measured rather than assumed.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Measures each declared capability against the live install.
 *
 * ### The one question this class exists to answer honestly
 *
 * An orchestrator's job is to decide what can run. The tempting way to do that is to ask
 * "does class X exist?" and answer yes, because the file is there and the tests pass.
 * That is a category error: a class can exist, be complete, be well tested, and still be
 * **unreachable**, and on this plugin several are.
 *
 * The concrete case that forced this design. Phase 9's synchronisation layer is five
 * classes, three of them fully implemented and covered by a passing test suite, and not
 * one of them is constructed anywhere in `includes/`. There is no service, no route, no
 * job type, and the capability `sync.approve` is granted to two roles for a feature that
 * does not exist. Every one of those classes answers "yes I exist" and every one of them
 * would fail a workflow that trusted the answer.
 *
 * So a capability here is **not** a class list. It is a probe that has to reach the
 * feature the way a workflow would reach it, and a capability is only `available` if that
 * probe succeeds.
 *
 * ### The three answers, and why there are three
 *
 * - `available` — the probe reached it and it works.
 * - `degraded` — the probe reached it and it works in a reduced form. Elementor without a
 *   render provider is the shape: generation succeeds, visual validation cannot. Reporting
 *   this as `unavailable` would be wrong (the work happens) and reporting it as `available`
 *   would be worse (a user is told visual validation ran when it did not).
 * - `unavailable` — the probe could not reach it. Accompanied by a reason a user can act on.
 *
 * ### Caching
 *
 * Probes run once per request and are memoised. Some probes are not free (Elementor's
 * widget registry, the AI capability report), and an orchestrator asks about capabilities
 * many times per run — once per stage, plus once per preflight, plus once per report.
 * `flush()` exists for tests and for the admin screen.
 */
final class Capability_Registry {

	/**
	 * The per-request memo.
	 *
	 * Written **per capability as each probe finishes**, not once at the end. That detail is
	 * load-bearing: some probes read sibling capabilities, and a memo filled only at the end
	 * of the loop would send a nested read back through the whole loop. See {@see self::evaluate()}.
	 *
	 * @var array<string, array>
	 */
	private $cache = array();

	/**
	 * The capabilities currently being probed.
	 *
	 * A re-entrancy guard, not a cache. A capability under evaluation that is asked for
	 * again means two probes depend on each other, which no probe should do; the guard turns
	 * that into a reported unavailability instead of unbounded recursion.
	 *
	 * @var array<string, bool>
	 */
	private $probing = array();

	/**
	 * Forced overrides, for tests and for a site that knows better than a probe.
	 *
	 * @var array<string, bool>
	 */
	private $overrides = array();

	/**
	 * The probe implementations.
	 *
	 * Keyed by capability name, matching `Orchestrator_Limits::CAPABILITIES` exactly. A
	 * capability with no probe is a bug, and `report()` says so rather than defaulting it
	 * to available - a silent default would make an unprobed capability look measured.
	 *
	 * @var array<string, callable>
	 */
	private $probes;

	/**
	 * Build the registry.
	 */
	public function __construct() {
		$this->probes = $this->build_probes();
	}

	/* ---------------------------------------------------------------------
	 * Public surface
	 * ------------------------------------------------------------------ */

	/**
	 * Return the status of one capability.
	 *
	 * @param string $capability Capability name.
	 * @return array{available: bool, status: string, reason: string, detail: array}
	 */
	public function get( $capability ) {
		$capability = (string) $capability;

		if ( isset( $this->overrides[ $capability ] ) ) {
			$forced = (bool) $this->overrides[ $capability ];

			return array(
				'available' => $forced,
				'status'    => $forced ? 'available' : 'unavailable',
				'reason'    => $forced ? 'Forced available by an operator.' : 'Forced unavailable by an operator.',
				'detail'    => array( 'source' => 'override' ),
			);
		}

		if ( ! in_array( $capability, Orchestrator_Limits::CAPABILITIES, true ) ) {
			return array(
				'available' => false,
				'status'    => 'unavailable',
				'reason'    => 'This capability is not declared by the orchestrator.',
				'detail'    => array( 'source' => 'unknown' ),
			);
		}

		return $this->evaluate( $capability );
	}

	/**
	 * Evaluate one capability, memoising the result as soon as it is known.
	 *
	 * ### Why the memo is written here and not in `all()`
	 *
	 * Two probes read another capability: `validation` depends on `rendering`, and the
	 * admin summary reads several at once. If the memo were filled only after the whole loop
	 * in `all()` finished, a nested read would re-enter `all()`, which would run every probe
	 * again, one of which would nest further. Each level re-runs the Elementor probe, which
	 * costs about six megabytes on a real install, and the process exhausts a 128 MB limit
	 * before it can report anything.
	 *
	 * That is not a theoretical concern here. WordPress with Elementor and this plugin
	 * loaded already sits at roughly 68 MB, so there are about ten levels of headroom - the
	 * orchestrator's own capability report would have been the thing that killed the
	 * process, before it had decided a single thing.
	 *
	 * Memoising per capability fixes it at the root, because a nested read of a capability
	 * already computed becomes a lookup rather than a second evaluation, and a nested read of
	 * one not yet computed evaluates only that one.
	 *
	 * @param string $capability Capability name.
	 * @return array
	 */
	private function evaluate( $capability ) {
		if ( isset( $this->cache[ $capability ] ) ) {
			return $this->cache[ $capability ];
		}

		if ( isset( $this->probing[ $capability ] ) ) {
			/*
			 * Two probes reading each other. Reported rather than followed, because the
			 * alternative is the recursion above, and a cyclic probe dependency is a bug in
			 * the probes that a report should surface instead of hide behind a fatal error.
			 */
			return array(
				'available' => false,
				'status'    => 'unavailable',
				'reason'    => 'The capability checks depend on each other, so availability could not be determined.',
				'detail'    => array( 'source' => 'cyclic_probe' ),
			);
		}

		if ( ! isset( $this->probes[ $capability ] ) ) {
			/*
			 * Declared but unprobed. Unavailable with the reason spelled out, because the
			 * alternative - assuming available - is how an orchestrator ends up reporting a
			 * capability nobody ever tested.
			 */
			return $this->cache[ $capability ] = array(
				'available' => false,
				'status'    => 'unavailable',
				'reason'    => 'No probe is defined for this capability, so its availability is unknown.',
				'detail'    => array( 'source' => 'missing_probe' ),
			);
		}

		$this->probing[ $capability ] = true;

		try {
			$entry = $this->run_probe( $capability, $this->probes[ $capability ] );
		} finally {
			unset( $this->probing[ $capability ] );
		}

		$entry['detail']['capability'] = $capability;
		$entry['required']            = in_array( $capability, Orchestrator_Limits::REQUIRED_CAPABILITIES, true );

		return $this->cache[ $capability ] = $entry;
	}

	/**
	 * Return whether a capability is usable.
	 *
	 * `degraded` counts as usable, because a workflow that refuses to run on a degraded
	 * capability would refuse to run at all on most installs - and refusing to run is a
	 * worse answer than running with a recorded limitation. What the caller must do with
	 * the difference is consult `status()`, not this.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	public function can( $capability ) {
		$entry = $this->get( $capability );

		return ! empty( $entry['available'] );
	}

	/**
	 * Return whether a capability is fully available, with no reduction.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	public function is_full( $capability ) {
		$entry = $this->get( $capability );

		return 'available' === ( $entry['status'] ?? '' );
	}

	/**
	 * Return every capability, probed.
	 *
	 * @return array<string, array>
	 */
	public function all() {
		foreach ( Orchestrator_Limits::CAPABILITIES as $capability ) {
			$this->evaluate( $capability );
		}

		return $this->cache;
	}

	/**
	 * Return the capabilities that are usable, whatever their degree.
	 *
	 * @return array<int, string>
	 */
	public function usable() {
		$out = array();

		foreach ( $this->all() as $capability => $entry ) {
			if ( ! empty( $entry['available'] ) ) {
				$out[] = $capability;
			}
		}

		return $out;
	}

	/**
	 * Return the capabilities that are missing a required probe or are outright absent.
	 *
	 * @return array<int, string>
	 */
	public function missing_required() {
		$out = array();

		foreach ( Orchestrator_Limits::REQUIRED_CAPABILITIES as $capability ) {
			if ( ! $this->can( $capability ) ) {
				$out[] = $capability;
			}
		}

		return $out;
	}

	/**
	 * Return the capabilities that work in a reduced form.
	 *
	 * This is the list a report has to surface. "Degraded" is the state a user is least
	 * likely to notice and most affected by, so it is named explicitly rather than folded
	 * into available.
	 *
	 * @return array<int, string>
	 */
	public function degraded() {
		$out = array();

		foreach ( $this->all() as $capability => $entry ) {
			if ( 'degraded' === ( $entry['status'] ?? '' ) ) {
				$out[] = $capability;
			}
		}

		return $out;
	}

	/**
	 * Return a summary safe to put in a report.
	 *
	 * Counts and reasons only. No endpoint, no token, no filesystem path, no model price -
	 * the detail arrays are filtered rather than returned wholesale, because a probe's
	 * detail is chosen for diagnosis and diagnosis is not the same as disclosure.
	 *
	 * @return array
	 */
	public function summary() {
		$all      = $this->all();
		$statuses = array( 'available' => 0, 'degraded' => 0, 'unavailable' => 0 );
		$reasons  = array();

		foreach ( $all as $capability => $entry ) {
			$status = (string) ( $entry['status'] ?? 'unavailable' );
			if ( ! isset( $statuses[ $status ] ) ) {
				$statuses[ $status ] = 0;
			}
			$statuses[ $status ]++;

			if ( ! $status ) {
				continue;
			}
			if ( 'available' === $status ) {
				continue;
			}

			$reasons[ $capability ] = array(
				'status' => $status,
				'reason' => (string) ( $entry['reason'] ?? '' ),
			);
		}

		return array(
			'measured'   => gmdate( 'c' ),
			'counts'     => $statuses,
			'usable'     => $this->usable(),
			'degraded'   => $this->degraded(),
			'missing'    => $this->missing_required(),
			'limitations' => $reasons,
		);
	}

	/* ---------------------------------------------------------------------
	 * Test and operator control
	 * ------------------------------------------------------------------ */

	/**
	 * Force a capability's availability.
	 *
	 * An operator override exists because a probe can be wrong in a way only the operator
	 * knows: a site behind a proxy where the outbound test fails while real fetches work,
	 * or a staging install with a renderer registered only during a specific request. The
	 * override is recorded as `source => override` in the detail so a report never
	 * presents a forced answer as a measured one.
	 *
	 * @param string $capability Capability name.
	 * @param bool   $available  Forced value.
	 * @return void
	 */
	public function force( $capability, $available ) {
		$this->overrides[ (string) $capability ] = (bool) $available;
		$this->cache                             = array();
	}

	/**
	 * Clear the memo and every override.
	 *
	 * @return void
	 */
	public function flush() {
		$this->cache     = array();
		$this->overrides = array();
		$this->probing   = array();
	}

	/* ---------------------------------------------------------------------
	 * Probes
	 * ------------------------------------------------------------------ */

	/**
	 * Run one probe and normalise whatever it returns.
	 *
	 * A probe may return a bool, a string reason, or a full array. Normalising here means
	 * the probe bodies stay short and cannot forget to set a status - a probe that returned
	 * an array without a `status` would otherwise be reported as fully available.
	 *
	 * @param string   $capability Capability name.
	 * @param callable $probe      The probe.
	 * @return array
	 */
	private function run_probe( $capability, $probe ) {
		try {
			$result = call_user_func( $probe );
		} catch ( \Throwable $e ) {
			/*
			 * A probe that throws is an unavailable capability, not a fatal error. An
			 * orchestrator that dies while deciding what it can do has no way to report
			 * the fact that it could not decide, which is the one thing it must report.
			 */
			return array(
				'available' => false,
				'status'    => 'unavailable',
				'reason'    => 'The capability check could not complete: ' . $this->safe_message( $e ),
				'detail'    => array( 'source' => 'exception' ),
			);
		}

		if ( is_bool( $result ) ) {
			return array(
				'available' => $result,
				'status'    => $result ? 'available' : 'unavailable',
				'reason'    => $result ? '' : 'The capability is not available on this site.',
				'detail'    => array( 'source' => 'probe' ),
			);
		}

		if ( is_string( $result ) ) {
			return array(
				'available' => false,
				'status'    => 'unavailable',
				'reason'    => '' === $result ? 'The capability is not available on this site.' : $result,
				'detail'    => array( 'source' => 'probe' ),
			);
		}

		$entry = (array) $result;

		if ( ! isset( $entry['status'] ) ) {
			// A probe that forgot to say. Treated as unavailable, not available.
			return array(
				'available' => false,
				'status'    => 'unavailable',
				'reason'    => 'The capability check returned no status.',
				'detail'    => array( 'source' => 'incomplete_probe' ),
			);
		}

		$status = (string) $entry['status'];

		if ( ! in_array( $status, array( 'available', 'degraded', 'unavailable' ), true ) ) {
			return array(
				'available' => false,
				'status'    => 'unavailable',
				'reason'    => 'The capability check returned an unrecognised status.',
				'detail'    => array( 'source' => 'invalid_probe_status' ),
			);
		}

		$entry['available'] = in_array( $status, array( 'available', 'degraded' ), true );
		$entry['reason']    = isset( $entry['reason'] ) ? (string) $entry['reason'] : '';
		$entry['detail']    = isset( $entry['detail'] ) ? (array) $entry['detail'] : array();
		$entry['detail']['source'] = 'probe';

		return $entry;
	}

	/**
	 * Build the probe table.
	 *
	 * @return array<string, callable>
	 */
	private function build_probes() {
		return array(
			'core'         => array( $this, 'probe_core' ),
			'fetch'        => array( $this, 'probe_fetch' ),
			'analysis'     => array( $this, 'probe_analysis' ),
			'planning'     => array( $this, 'probe_planning' ),
			'elementor'    => array( $this, 'probe_elementor' ),
			'rendering'    => array( $this, 'probe_rendering' ),
			'validation'   => array( $this, 'probe_validation' ),
			'corrections'  => array( $this, 'probe_corrections' ),
			'interactions' => array( $this, 'probe_interactions' ),
			'content'      => array( $this, 'probe_content' ),
			'multipage'    => array( $this, 'probe_multipage' ),
			'sync'         => array( $this, 'probe_sync' ),
			'ai'           => array( $this, 'probe_ai' ),
			'browser'      => array( $this, 'probe_browser' ),
		);
	}

	/**
	 * The orchestrator itself. Available whenever this code is running.
	 *
	 * @return array
	 */
	private function probe_core() {
		return array(
			'status' => 'available',
			'reason' => '',
			'detail' => array( 'schema' => Orchestrator_Limits::SCHEMA_VERSION ),
		);
	}

	/**
	 * Outbound HTTP: the URL validator and the client that must re-validate every hop.
	 *
	 * Checks that the *boundary* exists rather than that a request succeeds. A request is
	 * not made here on purpose: preflight runs before expensive work and this runs before
	 * that, so a probe that fetched a page would itself be the first external call of the
	 * workflow, from a code path nobody budgeted. The actual refusal is proven by the
	 * security tests, and the per-request check happens in `Preflight_Assessment` where a
	 * specific URL is available to validate.
	 *
	 * @return array
	 */
	private function probe_fetch() {
		if ( ! class_exists( 'ReplicaForge\\Url_Validator' ) || ! class_exists( 'ReplicaForge\\Http_Client' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'The URL validator or the HTTP client is missing, so no source page can be fetched safely.',
			);
		}

		/*
		 * The transport, not the classes.
		 *
		 * The first version of this probe asked whether `Url_Validator` and `Http_Client`
		 * existed. Both always do, so the probe answered `available` and a workflow was told
		 * fetching worked - then failed at the `analysis` stage on the first real request,
		 * after the user had already approved a plan for it.
		 *
		 * The concrete case: a PHP build with neither `openssl` nor `curl` registers no `https`
		 * stream wrapper at all. WordPress then reports "No working transports found" for every
		 * HTTPS URL while plain HTTP still returns 200. A class-existence check cannot see
		 * that, because nothing about the classes changed - only the host did.
		 *
		 * So the wrappers are enumerated instead. `stream_get_wrappers()` is a fact about the
		 * running PHP, which is exactly the question being asked.
		 */
		$wrappers = (array) stream_get_wrappers();
		$notes    = array();

		if ( ! in_array( 'https', $wrappers, true ) ) {
			$why = array();

			if ( ! extension_loaded( 'openssl' ) ) {
				$why[] = 'the openssl extension is not loaded';
			}

			if ( ! extension_loaded( 'curl' ) ) {
				$why[] = 'the curl extension is not loaded';
			}

			return array(
				'status' => 'unavailable',
				'reason' => sprintf(
					/* translators: %s: why the HTTPS transport is missing. */
					__( 'This server cannot make HTTPS requests, so no secure website can be analysed. PHP reports no https transport (%s). Nearly every website is HTTPS, so ReplicaForge will refuse most sources.', 'replicaforge' ),
					empty( $why ) ? __( 'no transport module is available', 'replicaforge' ) : implode( ' and ', $why )
				),
				'detail' => array( 'wrappers' => $wrappers, 'openssl' => extension_loaded( 'openssl' ), 'curl' => extension_loaded( 'curl' ) ),
			);
		}

		if ( ! in_array( 'http', $wrappers, true ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => __( 'This server registers no http transport, so no website can be fetched at all.', 'replicaforge' ),
				'detail' => array( 'wrappers' => $wrappers ),
			);
		}

		if ( ! ini_get( 'allow_url_fopen' ) && ! extension_loaded( 'curl' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => __( 'allow_url_fopen is disabled and curl is not loaded, so PHP has no way to open a remote URL.', 'replicaforge' ),
			);
		}

		if ( has_filter( 'pre_http_request' ) ) {
			// A filter exists, but its effect is unknowable from here without calling it.
			$notes[] = 'A pre_http_request filter is registered and may restrict outbound requests.';
		}

		if ( ! empty( $notes ) ) {
			return array(
				'status' => 'degraded',
				'reason' => implode( ' ', $notes ),
			);
		}

		return array(
			'status' => 'available',
			'reason' => '',
			'detail' => array( 'wrappers' => $wrappers ),
		);
	}

	/**
	 * Structural analysis: the DOM analyser and the representation it produces.
	 *
	 * @return array
	 */
	private function probe_analysis() {
		if ( ! class_exists( 'ReplicaForge\\Dom_Analyzer' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'The DOM analyser is missing, so a source page cannot be turned into a structural representation.',
			);
		}

		if ( ! class_exists( 'ReplicaForge\\Design_Representation' ) ) {
			return array(
				'status' => 'degraded',
				'reason' => 'The DOM analyser is present but the representation it produces cannot be validated.',
			);
		}

		return array( 'status' => 'available', 'reason' => '' );
	}

	/**
	 * Planning: the deterministic planner, with the AI planner as an enhancement.
	 *
	 * The distinction matters. `Ai_Reconstruction_Planner` is a deterministic, no-network
	 * class that builds a specification from a phase 2 representation, and it runs whether
	 * or not a provider is configured. What an unconfigured provider removes is the *AI*
	 * pass over ambiguous sections, not planning itself. Reporting planning as unavailable
	 * because no provider is configured would stop every workflow on a site that could
	 * have planned perfectly well.
	 *
	 * @return array
	 */
	private function probe_planning() {
		if ( ! class_exists( 'ReplicaForge\\Ai_Reconstruction_Planner' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'The reconstruction planner is missing, so no specification can be produced.',
			);
		}

		if ( $this->ai_configured() ) {
			return array( 'status' => 'available', 'reason' => '' );
		}

		return array(
			'status' => 'degraded',
			'reason' => 'The deterministic reconstruction plan will be used. No AI provider is configured, so ambiguous sections are resolved by heuristics rather than by a model.',
		);
	}

	/**
	 * Elementor: measured against the live installation, because a class can exist while
	 * the plugin it targets does not.
	 *
	 * @return array
	 */
	private function probe_elementor() {
		if ( ! class_exists( 'ReplicaForge\\Elementor_Compatibility' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'The Elementor compatibility layer is missing, so no draft can be generated.',
			);
		}

		$compatibility = new Elementor_Compatibility();
		$status        = $compatibility->status();

		if ( empty( $status['available'] ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => (string) ( $status['message'] ?? 'Elementor is not available.' ),
				'detail' => array( 'version' => (string) ( $status['version'] ?? '' ) ),
			);
		}

		$detail = array(
			'version'    => (string) ( $status['version'] ?? '' ),
			'containers' => ! empty( $status['containers'] ),
			'widgets'    => isset( $status['widgets'] ) && is_array( $status['widgets'] ) ? count( $status['widgets'] ) : 0,
		);

		// Without containers the generator falls back to sections, which is a real reduction.
		if ( empty( $detail['containers'] ) ) {
			return array(
				'status'  => 'degraded',
				'reason'  => 'Elementor is available without Flexbox containers, so the generated structure will use sections rather than containers and will be less flexible to edit.',
				'detail'  => $detail,
			);
		}

		return array(
			'status' => 'available',
			'reason' => '',
			'detail' => $detail,
		);
	}

	/**
	 * Rendering: a configured renderer, measured through the same path the visual layer uses.
	 *
	 * @return array
	 */
	private function probe_rendering() {
		if ( ! class_exists( 'ReplicaForge\\Visual_Renderer' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'The visual renderer is missing, so a draft cannot be turned into an image for comparison.',
			);
		}

		$settings = $this->renderer_settings();

		if ( empty( $settings['enabled'] ) || '' === (string) ( $settings['endpoint'] ?? '' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'No rendering service is configured. Validation can compare structure and computed styles, but it cannot compare rendered pixels.',
			);
		}

		// A configured endpoint is necessary and not sufficient; an unreachable one is a
		// degraded install, and only a request would reveal it. The stage that needs it
		// records the failure, and preflight's URL validation covers the scheme check.
		return array(
			'status' => 'available',
			'reason' => '',
			'detail' => array( 'endpoint_configured' => true ),
		);
	}

	/**
	 * Validation: the engine, plus whether its strongest level is reachable.
	 *
	 * @return array
	 */
	private function probe_validation() {
		if ( ! class_exists( 'ReplicaForge\\Validation_Engine' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'The validation engine is missing, so a generated draft cannot be checked.',
			);
		}

		if ( ! $this->evaluate( 'rendering' )['available'] ) {
			return array(
				'status'  => 'degraded',
				'reason'  => 'Validation will compare structure, layout, typography, colour, spacing and assets. Visual comparison is unavailable, so pixel differences will not be detected.',
			);
		}

		return array( 'status' => 'available', 'reason' => '' );
	}

	/**
	 * Corrections: the engine and its bounded iteration limit.
	 *
	 * @return array
	 */
	private function probe_corrections() {
		if ( ! class_exists( 'ReplicaForge\\Correction_Engine' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'The correction engine is missing, so detected differences cannot be corrected automatically.',
			);
		}

		return array( 'status' => 'available', 'reason' => '' );
	}

	/**
	 * Interactions: static detection always, browser observation only with a driver.
	 *
	 * @return array
	 */
	private function probe_interactions() {
		$plugin = Plugin::instance();
		$service = ( null === $plugin ) ? null : $plugin->interactions();

		if ( null === $service || ! method_exists( $service, 'capabilities' ) ) {
			// Phase 2 markup analysis still works, so this is degraded rather than absent.
			return array(
				'status' => 'degraded',
				'reason' => 'The interaction service is not available, so interactions will not be modelled. Structural analysis is unaffected.',
			);
		}

		$capabilities = (array) $service->capabilities();

		if ( ! empty( $capabilities['static_detection'] ) ) {
			return array( 'status' => 'available', 'reason' => '' );
		}

		return array(
			'status' => 'unavailable',
			'reason' => 'Interaction detection is unavailable, so menus, accordions, tabs and modals will not be modelled.',
		);
	}

	/**
	 * Content mapping: the service and its providers.
	 *
	 * @return array
	 */
	private function probe_content() {
		if ( ! class_exists( 'ReplicaForge\\Content_Service' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'The content service is missing, so posts, pages and products will not be mapped.',
			);
		}

		return array( 'status' => 'available', 'reason' => '' );
	}

	/**
	 * Multi-page: the site analyser and the page planner.
	 *
	 * @return array
	 */
	private function probe_multipage() {
		$missing = array();
		foreach ( array( 'ReplicaForge\\Site_Analyzer', 'ReplicaForge\\Multi_Page_Planner' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = substr( $class, strrpos( $class, '\\' ) + 1 );
			}
		}

		if ( ! empty( $missing ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'The multi-page analysis classes are missing (' . implode( ', ', $missing ) . '), so a website cannot be analysed as a whole.',
			);
		}

		return array( 'status' => 'available', 'reason' => '' );
	}

	/**
	 * Synchronisation: the primitives are present and the feature is not.
	 *
	 * ### Why this probe exists at all
	 *
	 * Phase 9 ships five classes in `includes/sync/`, all of them implemented, two of them
	 * with passing test suites. Not one is constructed anywhere outside `tests/`. There is
	 * no service, no REST route, no admin screen, no cron and no job type. Four constants
	 * are declared for options that nothing writes, and `sync.approve` is granted to two
	 * roles for a feature that does not exist.
	 *
	 * A capability probe that only asked `class_exists()` would report synchronisation as
	 * available, an incremental workflow would select the sync stage, and the stage would
	 * then have nothing to call. So this probe asks whether anything in `includes/` can
	 * actually *perform* a synchronisation, and the honest answer today is no.
	 *
	 * The change-detection primitives are reported as a detail rather than as a capability,
	 * because they are useful to an orchestrator that wants to describe *what* would change
	 * even though it cannot apply it. That distinction - can detect, cannot apply - is the
	 * kind of thing a report has to say out loud.
	 *
	 * @return array
	 */
	private function probe_sync() {
		if ( ! class_exists( 'ReplicaForge\\Change_Detector' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'Change detection is missing, so source changes cannot be compared against the generated draft.',
			);
		}

		$primitives = array( 'ReplicaForge\\Change_Detector', 'ReplicaForge\\Component_Matcher', 'ReplicaForge\\Change_Classifier' );
		$present    = array();
		foreach ( $primitives as $class ) {
			if ( class_exists( $class ) ) {
				$present[] = substr( $class, strrpos( $class, '\\' ) + 1 );
			}
		}

		return array(
			'status' => 'unavailable',
			'reason' => 'Source changes can be detected but not applied. The comparison primitives exist, however no synchronisation service, route or scheduled task connects them to a generated draft, so an incremental update cannot be performed automatically.',
			'detail' => array(
				'detection' => 'available',
				'application' => 'unavailable',
				'primitives'  => $present,
			),
		);
	}

	/**
	 * AI: a configured provider, asked of the settings object that owns the answer.
	 *
	 * @return array
	 */
	private function probe_ai() {
		if ( ! $this->ai_configured() ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'No AI provider is configured. Planning, section classification and correction prioritisation fall back to deterministic heuristics.',
			);
		}

		return array( 'status' => 'available', 'reason' => '' );
	}

	/**
	 * Browser observation: a driver registered through the documented filter.
	 *
	 * @return array
	 */
	private function probe_browser() {
		$plugin = Plugin::instance();
		$service = ( null === $plugin ) ? null : $plugin->interactions();

		if ( null === $service || ! method_exists( $service, 'driver_report' ) ) {
			return array(
				'status' => 'unavailable',
				'reason' => 'The interaction service is unavailable, so no browser driver can be registered.',
			);
		}

		$report = (array) $service->driver_report();

		if ( ! empty( $report['available'] ) ) {
			return array(
				'status' => 'available',
				'reason' => '',
				// The driver id and version, never anything the driver chose to call itself
				// about its internals.
				'detail' => array(
					'id'      => (string) ( $report['id'] ?? '' ),
					'version' => (string) ( $report['version'] ?? '' ),
				),
			);
		}

		return array(
			'status' => 'unavailable',
			'reason' => (string) ( $report['reason'] ?? 'No browser observation driver is registered.' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Small measured helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return whether an AI provider is configured.
	 *
	 * Asked of `Ai_Settings`, which owns the answer, rather than of `Ai_Manager`, which has
	 * no availability method. Reading `get_public_config()` rather than `get()` matters:
	 * the public shape is a boolean `api_key_set`, so no probe can accidentally carry a key
	 * into a report.
	 *
	 * @return bool
	 */
	private function ai_configured() {
		if ( ! class_exists( 'ReplicaForge\\Ai_Settings' ) ) {
			return false;
		}

		try {
			$settings = new Ai_Settings();

			return (bool) $settings->is_configured();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Return the renderer settings without exposing the endpoint or the token.
	 *
	 * @return array
	 */
	private function renderer_settings() {
		if ( ! class_exists( 'ReplicaForge\\Visual_Limits' ) ) {
			return array();
		}

		/*
		 * Read through the repository that owns the option, and keep only the two fields
		 * that decide availability. The bearer token lives in the same record, so the
		 * array is filtered rather than passed on.
		 */
		$raw = get_option( Visual_Renderer::OPTION, array() );

		return array(
			'enabled'  => ! empty( $raw['enabled'] ),
			'endpoint' => isset( $raw['endpoint'] ) ? (string) $raw['endpoint'] : '',
		);
	}

	/**
	 * Reduce a throwable to a message a report can carry.
	 *
	 * The class name and the message, never a trace. A stack trace in a workflow report
	 * discloses absolute filesystem paths, and §41 and the phase 17 requirements both
	 * treat internal paths as information the wrong audience must not receive.
	 *
	 * @param \Throwable $e The throwable.
	 * @return string
	 */
	private function safe_message( \Throwable $e ) {
		$message = trim( (string) $e->getMessage() );

		if ( '' === $message ) {
			return get_class( $e );
		}

		// Strip anything that looks like an absolute path before the message is stored.
		$message = preg_replace( '#[A-Za-z]:\\\\[^\s\'"]+|/[\w./-]{6,}#', '[path]', $message );

		return trim( (string) $message );
	}
}
