<?php
/**
 * Phase 19: dependency and compatibility resolution.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "can this template run here, and what is missing if not?".
 *
 * ### Why one class rather than two
 *
 * §19 (dependencies) and §20 (compatibility) are the same question asked from two sides —
 * "what does this template need" and "what does this site have" — and answering them
 * separately produces two answers that can disagree. A template that declares it needs
 * Elementor 3.20 and a site that reports 3.16 have to be compared, and a resolver that
 * reports each independently leaves the user to do the comparison.
 *
 * So this resolves both in one pass and reports, per requirement, one of four states.
 *
 * ### It never installs anything
 *
 * §19 is explicit: do not silently install plugins and do not download arbitrary
 * executables. There is no code path from a "missing" finding to a download, and
 * `resolve()` returns findings rather than performing actions. The strongest thing this
 * class will ever do is *block* an install and say why.
 *
 * ### The environment facts it uses
 *
 * Everything comes from existing probes rather than from re-detecting:
 *
 * - `Elementor_Compatibility::status()` — version, registered widgets, element types,
 *   containers, active breakpoints.
 * - `Site_Compatibility::capabilities()` — the seven-feature capability list
 *   (`containers`, `flexbox`, `grid`, `global_colors`, `global_fonts`, `responsive`,
 *   `theme_builder`), which is the same list `Template_Limits::ELEMENTOR_FEATURES` names.
 * - `class_exists( 'WooCommerce' )` — the commerce provider.
 * - `get_bloginfo( 'version' )` and `PHP_VERSION` — the platform.
 */
final class Template_Dependencies {

	/**
	 * Elementor compatibility probe.
	 *
	 * @var Elementor_Compatibility
	 */
	private $elementor;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Elementor_Compatibility|null $elementor Compatibility probe.
	 * @param Logger|null                  $logger    Logger.
	 */
	public function __construct( $elementor = null, $logger = null ) {
		$this->elementor = $elementor instanceof Elementor_Compatibility ? $elementor : new Elementor_Compatibility();
		$this->logger    = $logger instanceof Logger ? $logger : new Logger();
	}

	/* ---------------------------------------------------------------------
	 * Declaring
	 * ------------------------------------------------------------------ */

	/**
	 * Build the compatibility block a template carries.
	 *
	 * Recorded **at extraction time from the environment that produced it**, which is the
	 * only point at which the values are true. A template extracted on Elementor 3.22
	 * genuinely used 3.22, and recording that is what lets a later install on 3.16 say
	 * "this was built with a newer Elementor" rather than guessing from the document.
	 *
	 * @param array<string, mixed> $context Extraction context.
	 * @return array<string, mixed>
	 */
	public function declare( array $context = array() ) {
		$status     = $this->elementor->status();
		$site       = new Site_Compatibility( $this->elementor );
		$capability = $site->capabilities();

		$widgets = array();

		foreach ( array_keys( (array) ( $status['widgets'] ?? array() ) ) as $widget ) {
			$widgets[] = (string) $widget;
		}

		return array(
			'wordpress'  => array(
				'minimum' => self::MINIMUM_WORDPRESS,
				'found'   => (string) get_bloginfo( 'version' ),
			),
			'php'        => array(
				'minimum' => self::MINIMUM_PHP,
				'found'   => PHP_VERSION,
			),
			'elementor'  => array(
				'minimum'   => (string) ( $context['elementor_minimum'] ?? Elementor_Limits::MINIMUM_ELEMENTOR_VERSION ),
				'tested'    => (string) ( $status['version'] ?? '' ),
				'available' => (bool) ( $status['available'] ?? false ),
				'widgets'   => array_values( array_filter( Template_Sanitizer::allowed_widgets() ) ),
			),
			'woocommerce' => array(
				'required' => (bool) ( $context['woocommerce_required'] ?? false ),
				'found'    => class_exists( 'WooCommerce' ) || function_exists( 'WC' ),
			),
			'features'   => array_values( array_keys( array_filter( $capability ) ) ),
			'maker'      => array(
				'plugin'  => defined( 'REPLICAFORGE_VERSION' ) ? (string) REPLICAFORGE_VERSION : '0.0.0',
				'phase'   => Template_Limits::PHASE,
			),
		);
	}

	/**
	 * The lowest WordPress this feature is tested against.
	 *
	 * Read from `System_Status::MIN_WP` rather than restated, so the template feature and
	 * the plugin cannot disagree about what the plugin supports.
	 *
	 * @var string
	 */
	const MINIMUM_WORDPRESS = '6.2';

	/**
	 * The lowest PHP this feature is tested against.
	 *
	 * @var string
	 */
	const MINIMUM_PHP = '7.4';

	/* ---------------------------------------------------------------------
	 * Resolving
	 * ------------------------------------------------------------------ */

	/**
	 * Resolve a template's declared requirements against this environment.
	 *
	 * @param array<string, mixed> $declared Output of {@see Template_Dependencies::declare()}.
	 * @return array{satisfied: bool, blocking: bool, requirements: array<int, array<string, mixed>>, counts: array<string, int>, summary: string}
	 */
	public function resolve( array $declared ) {
		$status     = $this->elementor->status();
		$capability = ( new Site_Compatibility( $this->elementor ) )->capabilities();

		$requirements = array();

		$requirements[] = $this->platform_requirement( 'wordpress', (string) ( $declared['wordpress']['minimum'] ?? self::MINIMUM_WORDPRESS ), (string) get_bloginfo( 'version' ) );

		$requirements[] = $this->platform_requirement( 'php', (string) ( $declared['php']['minimum'] ?? self::MINIMUM_PHP ), PHP_VERSION );

		$requirements[] = $this->elementor_requirement( $declared, $status );

		$requirements[] = $this->woocommerce_requirement( $declared );

		foreach ( (array) ( $declared['features'] ?? array() ) as $feature ) {
			$requirements[] = $this->feature_requirement( (string) $feature, (array) $capability );
		}

		/*
		 * A requirement for a feature this version does not know about is reported rather
		 * than ignored. Silently dropping an unrecognised requirement is how a template
		 * comes to claim compatibility it has not been checked for.
		 */
		foreach ( (array) ( $declared['widgets'] ?? array() ) as $widget ) {
			$requirements[] = $this->widget_requirement( (string) $widget );
		}

		$counts = array( 'available' => 0, 'missing' => 0, 'incompatible' => 0, 'optional' => 0 );

		foreach ( $requirements as $requirement ) {
			if ( isset( $counts[ $requirement['state'] ] ) ) {
				$counts[ $requirement['state'] ]++;
			}
		}

		$blocking = array();
		$optional = array();

		foreach ( $requirements as $requirement ) {
			if ( ! empty( $requirement['optional'] ) ) {
				$optional[] = $requirement;
				continue;
			}
			if ( 'available' !== $requirement['state'] ) {
				$blocking[] = $requirement;
			}
		}

		$summary = __( 'Everything this template needs is present.', 'replicaforge' );

		if ( array() !== $blocking ) {
			$summary = sprintf(
				/* translators: %d: how many requirements are unmet. */
				__( '%d requirement(s) of this template are not met here, so it cannot be installed as it is.', 'replicaforge' ),
				count( $blocking )
			);
		} elseif ( array() !== $optional ) {
			$summary = __( 'This template can be installed here, though some optional features are unavailable.', 'replicaforge' );
		}

		return array(
			'satisfied'    => array() === $blocking,
			'blocking'     => array() !== $blocking,
			'requirements' => $requirements,
			'counts'       => $counts,
			'summary'      => $summary,
		);
	}

	/**
	 * Build a platform requirement record.
	 *
	 * @param string $product `wordpress` or `php`.
	 * @param string $minimum Required minimum.
	 * @param string $found   Installed version.
	 * @return array<string, mixed>
	 */
	private function platform_requirement( $product, $minimum, $found ) {
		if ( '' === $minimum ) {
			return array( 'kind' => $product, 'state' => 'available', 'optional' => false, 'label' => '', 'detail' => '' );
		}

		$ok = '' !== $found && version_compare( $found, $minimum, '>=' );

		return array(
			'kind'     => $product,
			'state'    => $ok ? 'available' : 'incompatible',
			'optional' => false,
			'label'    => $this->product_label( $product ),
			'minimum'  => $minimum,
			'found'    => $found,
			'detail'   => $ok
				? sprintf( /* translators: %s: installed version. */ __( '%s is installed.', 'replicaforge' ), $found )
				: sprintf(
					/* translators: 1: product name, 2: required version, 3: installed version. */
					__( 'This needs %1$s %2$s or newer; this site has %3$s.', 'replicaforge' ),
					$this->product_label( $product ),
					$minimum,
					'' === $found ? __( 'nothing', 'replicaforge' ) : $found
				),
		);
	}

	/**
	 * Build the Elementor requirement record.
	 *
	 * @param array<string, mixed> $declared Declared block.
	 * @param array<string, mixed> $status   Live Elementor status.
	 * @return array<string, mixed>
	 */
	private function elementor_requirement( array $declared, array $status ) {
		$minimum = (string) ( $declared['elementor']['minimum'] ?? Elementor_Limits::MINIMUM_ELEMENTOR_VERSION );
		$tested  = (string) ( $declared['elementor']['tested'] ?? '' );
		$found   = (string) ( $status['version'] ?? '' );
		$live    = (bool) ( $status['available'] ?? false );

		if ( ! $live ) {
			return array(
				'kind'     => 'elementor',
				'state'    => 'missing',
				'optional' => false,
				'label'    => __( 'Elementor', 'replicaforge' ),
				'minimum'  => $minimum,
				'found'    => $found,
				'detail'   => __( 'Elementor is not available on this site, so an Elementor template cannot be installed.', 'replicaforge' ),
			);
		}

		if ( '' !== $minimum && version_compare( $found, $minimum, '<' ) ) {
			return array(
				'kind'     => 'elementor',
				'state'    => 'incompatible',
				'optional' => false,
				'label'    => __( 'Elementor', 'replicaforge' ),
				'minimum'  => $minimum,
				'found'    => $found,
				'detail'   => sprintf(
					/* translators: 1: required version, 2: installed version. */
					__( 'This template needs Elementor %1$s or newer; this site has %2$s.', 'replicaforge' ),
					$minimum,
					$found
				),
			);
		}

		return array(
			'kind'     => 'elementor',
			'state'    => 'available',
			'optional' => false,
			'label'    => __( 'Elementor', 'replicaforge' ),
			'minimum'  => $minimum,
			'found'    => $found,
			'tested'   => $tested,
			'detail'   => sprintf(
				/* translators: %s: installed version. */
				__( 'Elementor %s is installed.', 'replicaforge' ),
				'' === $found ? __( '(version unknown)', 'replicaforge' ) : $found
			),
		);
	}

	/**
	 * Build the WooCommerce requirement record.
	 *
	 * @param array<string, mixed> $declared Declared block.
	 * @return array<string, mixed>
	 */
	private function woocommerce_requirement( array $declared ) {
		$required = ! empty( $declared['woocommerce']['required'] );
		$found    = class_exists( 'WooCommerce' ) || function_exists( 'WC' );

		if ( $found ) {
			return array(
				'kind'     => 'woocommerce',
				'state'    => 'available',
				'optional' => ! $required,
				'label'    => 'WooCommerce',
				'detail'   => $required ? __( 'WooCommerce is installed.', 'replicaforge' ) : __( 'WooCommerce is installed, though this template does not need it.', 'replicaforge' ),
			);
		}

		return array(
			'kind'     => 'woocommerce',
			'state'    => $required ? 'missing' : 'optional',
			'optional' => ! $required,
			'label'    => 'WooCommerce',
			'detail'   => $required
				? __( 'This template uses product content, so it needs WooCommerce. WooCommerce is not installed here.', 'replicaforge' )
				: __( 'WooCommerce is not installed, so product content in this template will be left empty.', 'replicaforge' ),
		);
	}

	/**
	 * Build an Elementor feature requirement record.
	 *
	 * @param string               $feature    Feature name.
	 * @param array<string, mixed> $capability Live capabilities.
	 * @return array<string, mixed>
	 */
	private function feature_requirement( $feature, array $capability ) {
		if ( ! Template_Limits::is_elementor_feature( $feature ) ) {
			return array(
				'kind'     => 'feature',
				'state'    => 'incompatible',
				'optional' => false,
				'label'    => $feature,
				'detail'   => sprintf(
					/* translators: %s: the feature name. */
					__( 'This template requires the Elementor feature "%s", which this version of ReplicaForge does not know how to check. It is treated as unmet rather than assumed.', 'replicaforge' ),
					$feature
				),
			);
		}

		$present = ! empty( $capability[ $feature ] );

		/*
		 * `responsive` degrades rather than blocks. A template with no tablet or mobile
		 * overrides still renders on those devices — it renders its desktop layout — so
		 * treating a missing responsive capability as a blocker would refuse an install that
		 * would work. It is reported as optional, and the consequence is stated.
		 */
		$optional = ( 'responsive' === $feature );

		return array(
			'kind'     => 'feature',
			'state'    => $present ? 'available' : ( $optional ? 'optional' : 'missing' ),
			'optional' => $optional,
			'label'    => $feature,
			'detail'   => $present
				? ''
				: ( $optional
					? __( 'This Elementor has no separate tablet or mobile settings, so the template will show the same layout at every size.', 'replicaforge' )
					: sprintf(
						/* translators: %s: the feature name. */
						__( 'This Elementor does not support "%s", which this template uses.', 'replicaforge' ),
						$feature
					) ),
		);
	}

	/**
	 * Build a widget requirement record.
	 *
	 * @param string $widget Widget name.
	 * @return array<string, mixed>
	 */
	private function widget_requirement( $widget ) {
		$allowed = Template_Sanitizer::is_allowed_widget( $widget );
		$live    = $this->elementor->has_widget( $widget );

		if ( ! $allowed ) {
			return array(
				'kind'     => 'widget',
				'state'    => 'incompatible',
				'optional' => false,
				'label'    => $widget,
				'detail'   => sprintf(
					/* translators: %s: the widget name. */
					__( 'This template uses the widget "%s", which is outside the set ReplicaForge creates. The security check removes it, so install the template to see what remains.', 'replicaforge' ),
					$widget
				),
			);
		}

		if ( ! $live ) {
			return array(
				'kind'     => 'widget',
				'state'    => 'missing',
				'optional' => false,
				'label'    => $widget,
				'detail'   => sprintf(
					/* translators: %s: the widget name. */
					__( 'The widget "%s" is not registered in this Elementor.', 'replicaforge' ),
					$widget
				),
			);
		}

		return array( 'kind' => 'widget', 'state' => 'available', 'optional' => false, 'label' => $widget, 'detail' => '' );
	}

	/**
	 * Return a human product name.
	 *
	 * @param string $product Product key.
	 * @return string
	 */
	private function product_label( $product ) {
		if ( 'php' === $product ) {
			return 'PHP';
		}
		return 'WordPress';
	}

	/* ---------------------------------------------------------------------
	 * Environment
	 * ------------------------------------------------------------------ */

	/**
	 * Return the current environment, for the library screen.
	 *
	 * @return array<string, mixed>
	 */
	public function environment() {
		$status = $this->elementor->status();

		return array(
			'wordpress'   => (string) get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'elementor'   => array(
				'version'   => (string) ( $status['version'] ?? '' ),
				'available' => (bool) ( $status['available'] ?? false ),
				'containers' => (bool) ( $status['containers'] ?? false ),
				'devices'   => (array) ( $status['devices'] ?? array() ),
				'minimum'   => Elementor_Limits::MINIMUM_ELEMENTOR_VERSION,
			),
			'woocommerce' => class_exists( 'WooCommerce' ) || function_exists( 'WC' ),
			'features'    => ( new Site_Compatibility( $this->elementor ) )->capabilities(),
			'allowed_widgets' => Template_Sanitizer::allowed_widgets(),
		);
	}
}
