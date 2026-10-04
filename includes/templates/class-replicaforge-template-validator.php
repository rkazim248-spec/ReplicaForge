<?php
/**
 * Phase 19: template validation.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a template snapshot and gives it one of five states.
 *
 * ### Why five states and not a pass/fail
 *
 * §23 names five, and the reason is diagnostic rather than bureaucratic. `incompatible`
 * and `invalid` mean entirely different things to the person looking at a failed install:
 * one is "this site is not the right place", the other is "this file is broken or hostile".
 * A binary verdict sends both to the same place, and the fix differs completely.
 *
 * `needs_review` is separated from `valid_warnings` for the same reason. A warning is
 * information — "three assets are referenced but not included" is fine. A review demand is
 * a question for a person — "this template's primary colour is disputed across the pages it
 * came from" means somebody has to decide, and calling it a warning invites ignoring it.
 *
 * ### It never repairs
 *
 * Validation reports. Cleaning is {@see Template_Sanitizer}'s job, and it runs *before* this.
 * A validator that fixed what it found would report success on a template it had quietly
 * changed, and the user would install something other than what they reviewed.
 *
 * ### Every dimension is measured or explicitly not measured
 *
 * §33 forbids fabricated percentages. Each of the eight dimensions returns a real result or
 * `Template_Limits::NOT_MEASURED`, and the state is derived only from what was actually
 * measured. A dimension that could not be checked lowers nothing and raises nothing, and
 * says so in the report.
 */
final class Template_Validator {

	/**
	 * Sanitizer.
	 *
	 * @var Template_Sanitizer
	 */
	private $sanitizer;

	/**
	 * Dependency resolver.
	 *
	 * @var Template_Dependencies
	 */
	private $dependencies;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Template_Sanitizer|null   $sanitizer   Sanitizer.
	 * @param Template_Dependencies|null $dependencies Resolver.
	 * @param Logger|null               $logger      Logger.
	 */
	public function __construct( $sanitizer = null, $dependencies = null, $logger = null ) {
		$this->sanitizer    = $sanitizer instanceof Template_Sanitizer ? $sanitizer : new Template_Sanitizer( null, $logger );
		$this->dependencies = $dependencies instanceof Template_Dependencies ? $dependencies : new Template_Dependencies( null, $logger );
		$this->logger       = $logger instanceof Logger ? $logger : new Logger();
	}

	/* ---------------------------------------------------------------------
	 * Entry point
	 * ------------------------------------------------------------------ */

	/**
	 * Validate a template snapshot.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<string, mixed> $context  Validation context, e.g. `type`.
	 * @return array<string, mixed>
	 */
	public function validate( array $snapshot, array $context = array() ) {
		$dimensions = array();

		foreach ( Template_Limits::VALIDATION_DIMENSIONS as $dimension ) {
			$dimensions[ $dimension ] = Template_Limits::NOT_MEASURED;
		}

		$findings = array();

		// --- Structure -------------------------------------------------
		$structure = $this->check_structure( $snapshot, $findings );
		$dimensions['structure'] = $structure['state'];

		// --- Design ----------------------------------------------------
		$design = $this->check_design( $snapshot, $findings );
		$dimensions['design'] = $design['state'];

		// --- Responsive ------------------------------------------------
		$dimensions['responsive'] = $this->check_responsive( $snapshot, $findings );

		// --- Interaction -----------------------------------------------
		$dimensions['interaction'] = $this->check_interaction( $snapshot, $findings );

		// --- Assets ----------------------------------------------------
		$dimensions['assets'] = $this->check_assets( $snapshot, $findings );

		// --- Security --------------------------------------------------
		$security = $this->check_security( $snapshot, $findings );
		$dimensions['security'] = $security['state'];

		// --- Compatibility ---------------------------------------------
		$compatibility = $this->check_compatibility( $snapshot, $findings );
		$dimensions['compatibility'] = $compatibility['state'];

		// --- Elementor -------------------------------------------------
		$elementor = $this->check_elementor( $snapshot, $findings );
		$dimensions['elementor'] = $elementor['state'];

		$state = $this->state_for( $findings, $snapshot );

		return array(
			'state'        => $state,
			'label'        => (string) Template_Limits::VALIDATION_STATES[ $state ],
			'dimensions'   => $dimensions,
			'findings'     => $findings,
			'errors'       => $this->of_severity( $findings, 'error' ),
			'warnings'     => $this->of_severity( $findings, 'warning' ),
			'reviews'      => $this->of_severity( $findings, 'review' ),
			'notices'      => $this->of_severity( $findings, 'notice' ),
			'installable'  => Template_Limits::is_installable( $state ),
			'structure'    => $structure,
			'compatibility' => $compatibility,
			'checked_at'   => gmdate( 'c' ),
			'schema_version' => Template_Limits::SCHEMA_VERSION,
		);
	}

	/* ---------------------------------------------------------------------
	 * Dimensions
	 * ------------------------------------------------------------------ */

	/**
	 * Check structure.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<int, array<string, mixed>> $findings Findings collector, by reference.
	 * @return array<string, mixed>
	 */
	private function check_structure( array $snapshot, array &$findings ) {
		$document = isset( $snapshot['document'] ) && is_array( $snapshot['document'] ) ? $snapshot['document'] : array();
		$elements = isset( $document['elements'] ) && is_array( $document['elements'] ) ? $document['elements'] : array();

		if ( array() === $elements ) {
			$this->add( $findings, 'error', 'structure', 'structure_empty', __( 'This template contains no elements, so installing it would produce a blank page.', 'replicaforge' ) );
			return array( 'state' => 'error', 'count' => 0, 'depth' => 0 );
		}

		$scan    = $this->sanitizer->scan( $elements );
		$count   = (int) $scan['kept'];
		$depth   = (int) $scan['depth'];

		foreach ( (array) $scan['removals'] as $removal ) {
			$this->add(
				$findings,
				'warning',
				'structure',
				'element_removed',
				sprintf(
					/* translators: 1: the element or widget, 2: the reason. */
					__( 'Part of this template was removed during the security check (%1$s: %2$s). The rest is safe to install.', 'replicaforge' ),
					(string) ( $removal['widget'] ?? $removal['path'] ?? __( 'an element', 'replicaforge' ) ),
					(string) $removal['reason']
				),
				$removal
			);
		}

		if ( ! empty( $scan['fatal'] ) ) {
			foreach ( (array) $scan['fatal'] as $fatal ) {
				$this->add( $findings, 'error', 'structure', (string) $fatal, __( 'Nothing in this template survived the security check, so it cannot be installed.', 'replicaforge' ) );
			}
		}

		if ( 0 === count( $findings ) ) {
			$this->add( $findings, 'notice', 'structure', 'structure_readable', __( 'The structure is valid and every widget is one ReplicaForge creates.', 'replicaforge' ) );
		}

		return array( 'state' => ( array() === $this->of_severity( $findings, 'error' ) ? 'ok' : 'error' ), 'count' => $count, 'depth' => $depth, 'dropped' => (int) $scan['dropped'] );
	}

	/**
	 * Check the design system.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<int, array<string, mixed>> $findings Findings collector, by reference.
	 * @return array<string, mixed>
	 */
	private function check_design( array $snapshot, array &$findings ) {
		$tokens = isset( $snapshot['tokens'] ) && is_array( $snapshot['tokens'] ) ? $snapshot['tokens'] : array();

		if ( array() === $tokens ) {
			$this->add( $findings, 'notice', 'design', 'no_tokens', __( 'This template carries no design tokens. It will use whatever the destination site already has, which is correct for a section that should inherit the site design.', 'replicaforge' ) );
			return array( 'state' => 'no_tokens', 'count' => 0, 'roles' => 0 );
		}

		$registry = new Design_Token_Registry( $this->logger );
		$bad      = 0;
		$disputed = 0;
		$roles    = 0;

		foreach ( $tokens as $token_id => $token ) {
			if ( ! is_array( $token ) ) {
				$bad++;
				continue;
			}

			$category = (string) ( $token['category'] ?? '' );

			if ( ! Template_Limits::is_token_category( $category ) ) {
				$bad++;
				continue;
			}

			if ( null === $registry->clean_value( $token['value'] ?? null, $category ) ) {
				$bad++;
				$this->add( $findings, 'error', 'design', 'token_value_invalid', sprintf(
					/* translators: %s: the token identifier. */
					__( 'The design token "%s" has a value this version cannot safely use.', 'replicaforge' ),
					(string) $token_id
				) );
				continue;
			}

			if ( ! empty( $token['disputed'] ) ) {
				$disputed++;
			}

			if ( ! empty( $token['role'] ) ) {
				$roles++;
			}
		}

		if ( $disputed > 0 ) {
			/*
			 * A review, not a warning. A disputed token means the pages this template came
			 * from disagreed about the value, so which one to use is a decision somebody has
			 * to make — and installing the template picks one silently otherwise.
			 */
			$this->add( $findings, 'review', 'design', 'tokens_disputed', sprintf(
				/* translators: %d: how many tokens are disputed. */
				__( '%d design token(s) were used inconsistently across the pages this came from. Whoever installs this should confirm which value is intended.', 'replicaforge' ),
				$disputed
			) );
		}

		if ( 0 === $roles && array() !== $tokens ) {
			$this->add( $findings, 'notice', 'design', 'tokens_unnamed', __( 'None of these tokens are bound to a named design role, so nothing here can be re-pointed globally. Set the roles by hand if this template should follow the site design system.', 'replicaforge' ) );
		}

		return array( 'state' => ( 0 === $bad ? 'ok' : 'error' ), 'count' => count( $tokens ), 'roles' => $roles, 'disputed' => $disputed, 'invalid' => $bad );
	}

	/**
	 * Check responsive rules.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<int, array<string, mixed>> $findings Findings collector, by reference.
	 * @return string
	 */
	private function check_responsive( array $snapshot, array &$findings ) {
		$responsive = isset( $snapshot['responsive'] ) && is_array( $snapshot['responsive'] ) ? $snapshot['responsive'] : array();

		if ( array() === $responsive ) {
			$this->add( $findings, 'notice', 'responsive', 'no_responsive_rules', __( 'This template records no tablet or mobile rules, so it will use one layout at every size. That is sometimes deliberate.', 'replicaforge' ) );
			return 'none';
		}

		$devices = isset( $responsive['devices'] ) && is_array( $responsive['devices'] ) ? $responsive['devices'] : array();
		$sections = isset( $responsive['sections'] ) && is_array( $responsive['sections'] ) ? $responsive['sections'] : array();

		$changed = 0;

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			foreach ( array( 'tablet', 'mobile' ) as $device ) {
				if ( isset( $section[ $device ]['evidence'] ) && 'not_detected' !== (string) $section[ $device ]['evidence'] ) {
					$changed++;
				}
			}
		}

		if ( 0 === $changed ) {
			$this->add( $findings, 'notice', 'responsive', 'responsive_uniform', __( 'Responsive settings were recorded, but no section actually changes layout between devices.', 'replicaforge' ) );
			return 'uniform';
		}

		if ( ! in_array( 'tablet', $devices, true ) ) {
			$this->add( $findings, 'warning', 'responsive', 'tablet_absent', __( 'This records mobile rules but no tablet rules, so a tablet will use the desktop layout.', 'replicaforge' ) );
		}

		return 'applied';
	}

	/**
	 * Check interaction rules.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<int, array<string, mixed>> $findings Findings collector, by reference.
	 * @return string
	 */
	private function check_interaction( array $snapshot, array &$findings ) {
		$interactions = isset( $snapshot['interactions'] ) && is_array( $snapshot['interactions'] ) ? $snapshot['interactions'] : array();

		if ( array() === $interactions ) {
			$this->add( $findings, 'notice', 'interaction', 'no_interactions', __( 'This template records no interaction rules. Anything animated or interactive on the source page will be static here.', 'replicaforge' ) );
			return 'none';
		}

		/*
		 * Phase 16 models interactions itself and Phase 19 stores a *reference* to that
		 * model rather than a copy. So a template that has a model reference is complete; one
		 * that has neither is genuinely interaction-free. An empty array is ambiguous, and
		 * saying so is better than guessing which.
		 */
		if ( isset( $interactions['model_id'] ) || isset( $interactions['reference'] ) ) {
			return 'referenced';
		}

		$this->add( $findings, 'warning', 'interaction', 'interactions_not_resolved', __( 'This template carries interaction rules that were not resolved to a stored model, so they are informational only.', 'replicaforge' ) );

		return 'unresolved';
	}

	/**
	 * Check assets.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<int, array<string, mixed>> $findings Findings collector, by reference.
	 * @return string
	 */
	private function check_assets( array $snapshot, array &$findings ) {
		$assets = isset( $snapshot['assets'] ) && is_array( $snapshot['assets'] ) ? $snapshot['assets'] : array();

		if ( array() === $assets ) {
			$this->add( $findings, 'notice', 'assets', 'no_assets', __( 'This template references no external assets.', 'replicaforge' ) );
			return 'none';
		}

		$referenced = 0;
		$embedded   = 0;
		$unsafe     = 0;

		foreach ( $assets as $id => $asset ) {
			if ( ! is_array( $asset ) ) {
				$unsafe++;
				continue;
			}

			$url = (string) ( $asset['url'] ?? '' );

			if ( '' === $url || ! Security::is_safe_public_reference( $url ) ) {
				$unsafe++;
				$this->add( $findings, 'error', 'assets', 'asset_url_unsafe', sprintf(
					/* translators: %s: the asset identifier. */
					__( 'The asset "%s" does not point at a usable public address.', 'replicaforge' ),
					(string) $id
				) );
				continue;
			}

			$referenced++;

			if ( 'import' === (string) ( $asset['mode'] ?? 'reference' ) ) {
				$embedded++;
			}
		}

		/*
		 * A referenced third-party asset is the normal, correct state — §15 says a template
		 * must remain usable when restricted assets are excluded, and referencing is how it
		 * stays usable. It is reported so the user knows the images come from elsewhere and
		 * may break, and so nobody is surprised that no file was copied.
		 */
		$this->add( $findings, 'notice', 'assets', 'assets_referenced', sprintf(
			/* translators: %d: how many assets are referenced rather than copied. */
			__( '%d asset(s) are referenced from their original location rather than copied into this site. The images will keep working as long as those addresses do, and ReplicaForge never redistributed anyone else\'s files.', 'replicaforge' ),
			max( 0, $referenced - $embedded )
		) );

		return ( 0 === $unsafe ? 'ok' : 'error' );
	}

	/**
	 * Check security.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<int, array<string, mixed>> $findings Findings collector, by reference.
	 * @return array<string, mixed>
	 */
	private function check_security( array $snapshot, array &$findings ) {
		$scan = $this->sanitizer->scan_snapshot( $snapshot );

		foreach ( (array) $scan['fatal'] as $fatal ) {
			$this->add( $findings, 'error', 'security', (string) $fatal, __( 'This template failed the security check and cannot be installed.', 'replicaforge' ) );
		}

		foreach ( (array) $scan['removals'] as $removal ) {
			$this->add(
				$findings,
				'warning',
				'security',
				'security_removal',
				sprintf(
					/* translators: 1: what was removed, 2: why. */
					__( 'Removed %1$s from this template (%2$s).', 'replicaforge' ),
					(string) ( $removal['kind'] ?? 'a value' ),
					(string) ( $removal['reason'] ?? 'policy' )
				),
				$removal
			);
		}

		return array(
			'state'   => ( ! empty( $scan['fatal'] ) ? 'error' : 'ok' ),
			'removals' => count( (array) $scan['removals'] ),
			'fatal'   => (int) count( (array) $scan['fatal'] ),
		);
	}

	/**
	 * Check compatibility.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<int, array<string, mixed>> $findings Findings collector, by reference.
	 * @return array<string, mixed>
	 */
	private function check_compatibility( array $snapshot, array &$findings ) {
		$declared = isset( $snapshot['compatibility'] ) && is_array( $snapshot['compatibility'] ) ? $snapshot['compatibility'] : array();

		if ( array() === $declared ) {
			$this->add( $findings, 'review', 'compatibility', 'compatibility_undeclared', __( 'This template does not record what it was built against, so it cannot be checked against this site. It was probably produced by hand or by another tool.', 'replicaforge' ) );
			return array( 'state' => 'unknown', 'satisfied' => false, 'blocking' => false, 'requirements' => array() );
		}

		$resolved = $this->dependencies->resolve( $declared );

		foreach ( (array) $resolved['requirements'] as $requirement ) {
			if ( 'available' === (string) $requirement['state'] || '' === (string) ( $requirement['detail'] ?? '' ) ) {
				continue;
			}

			$severity = ( 'optional' === (string) $requirement['state'] ) ? 'warning' : 'error';

			$this->add(
				$findings,
				$severity,
				'compatibility',
				'requirement_' . (string) $requirement['state'],
				(string) $requirement['detail'],
				$requirement
			);
		}

		return array(
			'state'        => ( ! empty( $resolved['blocking'] ) ? 'incompatible' : ( ( $resolved['counts']['optional'] ?? 0 ) > 0 ? 'degraded' : 'ok' ) ),
			'satisfied'    => (bool) $resolved['satisfied'],
			'blocking'     => (bool) $resolved['blocking'],
			'requirements' => (array) $resolved['requirements'],
			'counts'       => (array) $resolved['counts'],
			'summary'      => (string) $resolved['summary'],
		);
	}

	/**
	 * Check Elementor compatibility.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<int, array<string, mixed>> $findings Findings collector, by reference.
	 * @return array<string, mixed>
	 */
	private function check_elementor( array $snapshot, array &$findings ) {
		$elementor = new Elementor_Compatibility();

		if ( ! $elementor->is_available() ) {
			$this->add( $findings, 'error', 'elementor', 'elementor_unavailable', __( 'Elementor is not available on this site, so this template cannot be installed.', 'replicaforge' ) );
			return array( 'state' => 'error', 'available' => false, 'version' => '' );
		}

		$version = (string) $elementor->version();
		$minimum = Elementor_Limits::MINIMUM_ELEMENTOR_VERSION;

		if ( '' !== $version && version_compare( $version, $minimum, '<' ) ) {
			$this->add( $findings, 'error', 'elementor', 'elementor_too_old', sprintf(
				/* translators: 1: required version, 2: installed version. */
				__( 'This site runs Elementor %2$s; ReplicaForge needs %1$s or newer to build templates here.', 'replicaforge' ),
				$minimum,
				$version
			) );
			return array( 'state' => 'error', 'available' => true, 'version' => $version, 'minimum' => $minimum );
		}

		$this->add( $findings, 'notice', 'elementor', 'elementor_ok', sprintf(
			/* translators: %s: installed version. */
			__( 'Elementor %s is available and meets the minimum this feature needs.', 'replicaforge' ),
			$version
		) );

		return array( 'state' => 'ok', 'available' => true, 'version' => $version, 'minimum' => $minimum, 'containers' => $elementor->supports_containers() );
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */

	/**
	 * Derive the overall state from the findings.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return string
	 */
	private function state_for( array $findings, array $snapshot ) {
		$errors  = $this->of_severity( $findings, 'error' );
		$reviews = $this->of_severity( $findings, 'review' );
		$warns   = $this->of_severity( $findings, 'warning' );

		/*
		 * `incompatible` is checked before `invalid`, and deliberately so. A template that
		 * cannot run here *and* has a problem is an incompatible template on this site, and
		 * telling someone their file is corrupt when the file is fine and the site is wrong
		 * sends them to fix the wrong thing.
		 */
		$declared  = isset( $snapshot['compatibility'] ) && is_array( $snapshot['compatibility'] ) ? $snapshot['compatibility'] : array();
		$resolved  = array() === $declared ? null : $this->dependencies->resolve( $declared );

		if ( null !== $resolved && ! empty( $resolved['blocking'] ) ) {
			return 'incompatible';
		}

		if ( array() !== $errors ) {
			return 'invalid';
		}

		if ( array() !== $reviews ) {
			return 'needs_review';
		}

		if ( array() !== $warns ) {
			return 'valid_warnings';
		}

		return 'valid';
	}

	/* ---------------------------------------------------------------------
	 * Findings
	 * ------------------------------------------------------------------ */

	/**
	 * Append a finding.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings collector, by reference.
	 * @param string   $severity   Severity.
	 * @param string   $dimension  Validation dimension.
	 * @param string   $code       Stable code.
	 * @param string   $message    Human message.
	 * @param array<string, mixed> $evidence Evidence.
	 * @return void
	 */
	private function add( array &$findings, $severity, $dimension, $code, $message, array $evidence = array() ) {
		if ( ! isset( Template_Limits::SEVERITIES[ $severity ] ) ) {
			$severity = 'notice';
		}

		$findings[] = array(
			'severity'  => $severity,
			'dimension' => in_array( $dimension, Template_Limits::VALIDATION_DIMENSIONS, true ) ? $dimension : 'structure',
			'code'      => substr( (string) $code, 0, 80 ),
			'message'   => (string) $message,
			'evidence'  => $evidence,
		);
	}

	/**
	 * Return findings of one severity.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @param string $severity Severity.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_severity( array $findings, $severity ) {
		$out = array();

		foreach ( $findings as $finding ) {
			if ( (string) ( $finding['severity'] ?? '' ) === $severity ) {
				$out[] = $finding;
			}
		}

		return $out;
	}
}
