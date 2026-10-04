<?php
/**
 * Phase 12: theme and Elementor compatibility, and global style ownership.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what the installed WordPress and Elementor setup can actually do, and
 * who owns the global styles before anything is written to them.
 *
 * ### The rule this class exists to enforce
 *
 * §17: *if ownership is uncertain, do not overwrite*. That is the whole design, and
 * it has one consequence that is easy to get wrong — **uncertainty must be the
 * default**. A detector that guesses `theme_controlled` from "the colours look like
 * the theme's" will one day be right, and the day it is wrong a user's brand colours
 * are overwritten with what the detector thought they were. There is no undo in
 * Elementor's global settings, so the asymmetry is stark: refusing to write costs a
 * settings toggle, and a wrong write costs the design.
 *
 * So ownership is proved by *ReplicaForge having written it before*, recorded in an
 * option. It is not inferred. If the option is absent, the answer is `unknown`, and
 * `unknown` means do not write.
 *
 * ### Theme Builder is detected, never assumed
 *
 * §48 and §50. A Theme Builder location is only offered if the installed Elementor
 * actually registers it, because generating a header template for a location the
 * version does not have produces a template that is invisible — it exists, it is
 * stored, and it never renders. So {@see self::builder_locations()} asks Elementor
 * and returns what it says, and the caller degrades to page sections when it says
 * nothing.
 */
final class Site_Compatibility {

	/**
	 * The option that records what ReplicaForge has written to Elementor globals.
	 *
	 * @var string
	 */
	const OWNERSHIP_OPTION = 'replicaforge_global_style_ownership';

	/**
	 * Cache lifetime for the theme probe.
	 *
	 * @var int
	 */
	const CACHE_TTL = 900;

	/**
	 * Transient name for the theme probe.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'replicaforge_theme_probe';

	/**
	 * Elementor compatibility layer.
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
	 * @param Elementor_Compatibility|null $elementor Optional compatibility layer.
	 * @param Logger|null                  $logger    Optional logger.
	 */
	public function __construct( $elementor = null, $logger = null ) {
		$this->logger    = $logger instanceof Logger ? $logger : new Logger();
		$this->elementor = $elementor instanceof Elementor_Compatibility ? $elementor : new Elementor_Compatibility();
	}

	/**
	 * Return the capabilities this Elementor install actually has.
	 *
	 * Each one is asked of the compatibility layer rather than derived from a version
	 * number, because §49 is explicit that the version must not be assumed. A
	 * version comparison would also be wrong in the other direction: a fork can add
	 * a feature without a version bump.
	 *
	 * @return array<string, bool>
	 */
	public function capabilities() {
		return array(
			'containers'    => (bool) $this->elementor->supports_containers(),
			'flexbox'       => $this->elementor->has_element_type( 'e-flexbox' ),
			'grid'          => $this->elementor->has_element_type( 'e-grid' ),
			'global_colors' => $this->elementor->has_element_type( 'e-global-color' ) || $this->elementor->has_widget( 'global-color' ),
			'global_fonts'  => $this->elementor->has_element_type( 'e-global-typography' ) || $this->elementor->has_widget( 'global-typography' ),
			'responsive'    => ( count( $this->elementor->active_devices() ) > 1 ),
			'theme_builder' => ( array() !== $this->builder_locations() ),
		);
	}

	/**
	 * Build the compatibility report.
	 *
	 * @return array<string, mixed>
	 */
	public function report() {
		$elementor = $this->elementor->status();
		$theme     = $this->theme();

		$capabilities = $this->capabilities();

		$fallbacks = $this->fallbacks( $capabilities );

		return array(
			'schema_version' => Site_Limits::SCHEMA_VERSION,
			'elementor'      => array(
				'available' => (bool) $this->elementor->is_available(),
				'version'   => (string) $this->elementor->version(),
				'minimum'   => Elementor_Limits::MINIMUM_ELEMENTOR_VERSION,
				'adequate'  => ( $elementor['active'] ?? false ) && ( '' === (string) ( $elementor['message'] ?? '' ) ),
				'message'   => (string) $this->elementor->message(),
				'devices'   => $this->elementor->active_devices(),
			),
			'capabilities'   => $capabilities,
			'theme'          => $theme,
			'ownership'      => $this->ownership(),
			'strategies'     => $this->strategies( $capabilities, $theme ),
			'builder'        => $this->builder_locations(),
			'fallbacks'      => $fallbacks,
			'notes'          => array(
				'elementor_version' => __( 'Only the capabilities this Elementor version actually reports are used. Nothing is generated on the assumption it exists.', 'replicaforge' ),
				'theme_influence'   => __( 'The active theme\'s own header, footer, colours and container width are recorded so generated sections do not fight them.', 'replicaforge' ),
			),
		);
	}

	/**
	 * Probe the active theme.
	 *
	 * Cached, because this is called on every multi-page project screen and reads
	 * several filesystem paths plus a stylesheet.
	 *
	 * @param bool $refresh Whether to bypass the cache.
	 * @return array<string, mixed>
	 */
	public function theme( $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$out = array(
			'name'          => '',
			'version'       => '',
			'stylesheet'    => '',
			'template'      => '',
			'parent'        => '',
			'header'        => array( 'declared' => false, 'supported' => false, 'template' => '' ),
			'footer'        => array( 'declared' => false, 'supported' => false, 'template' => '' ),
			'global_colors' => array(),
			'global_fonts'  => array(),
			'container'     => 0,
			'elementor_ready'=> false,
		);

		if ( ! function_exists( 'wp_get_theme' ) ) {
			set_transient( self::CACHE_KEY, $out, self::CACHE_TTL );
			return $out;
		}

		$theme = wp_get_theme();
		if ( ! $theme || ! is_object( $theme ) || ! method_exists( $theme, 'get' ) ) {
			set_transient( self::CACHE_KEY, $out, self::CACHE_TTL );
			return $out;
		}

		$out['name']       = (string) $theme->get( 'Name' );
		$out['version']    = (string) $theme->get( 'Version' );
		$out['stylesheet'] = (string) $theme->get( 'Stylesheet' );
		$out['template']   = (string) $theme->get( 'Template' );
		$out['parent']     = (string) $theme->get( 'Parent' );

		// A theme that declares a header or footer has one, and replacing it silently
		// would remove the user's navigation. This is the fact the header/footer
		// strategy decision is made on.
		$out['header']['declared'] = (bool) $theme->get( 'header' );
		$out['header']['template'] = (string) $theme->get( 'header_template' );
		$out['header']['supported'] = method_exists( $theme, 'register_header' );
		$out['footer']['declared'] = (bool) $theme->get( 'footer' );
		$out['footer']['template'] = (string) $theme->get( 'footer_template' );
		$out['footer']['supported'] = method_exists( $theme, 'register_footer' );

		$root = get_template_directory();

		if ( is_string( $root ) && '' !== $root && file_exists( $root . '/global-styles.php' ) ) {
			$out['elementor_ready'] = true;
		}

		$style = ( function_exists( 'wp_get_global_settings' ) )
			? wp_get_global_settings( array(), array( 'stylesheet', 'elementor' ) )
			: array();
		$colors = ( is_array( $style ) && isset( $style['color']['palette'] ) && is_array( $style['color']['palette'] ) ) ? $style['color']['palette'] : array();
		foreach ( (array) $colors as $slug => $color ) {
			if ( is_string( $color ) && 1 === preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color ) ) {
				$out['global_colors'][ (string) $slug ] = $color;
			}
		}

		$typography = ( is_array( $style ) && isset( $style['typography'] ) && is_array( $style['typography'] ) ) ? $style['typography'] : array();
		foreach ( array( 'fontFamily' => 'font_family', 'fontSize' => 'font_size' ) as $key => $out_key ) {
			if ( isset( $typography[ $key ] ) && is_string( $typography[ $key ] ) ) {
				$out['global_fonts'][ $out_key ] = (string) $typography[ $key ];
			}
		}

		$size = ( is_array( $style ) && isset( $style['layout']['contentSize'] ) ) ? (string) $style['layout']['contentSize'] : '';
		if ( '' !== $size && preg_match( '/(\d{2,5})px/', $size, $matches ) ) {
			$out['container'] = (int) $matches[1];
		}

		// Elementor's own global settings, which is what a *shared component* style
		// would be written into. Recorded read-only; see ownership().
		$e_kit = get_option( 'elementor_library_settings', array() );
		$e_kit = is_array( $e_kit ) ? $e_kit : array();
		$e_sys = get_option( 'elementor_system_settings', array() );
		$e_sys = is_array( $e_sys ) ? $e_sys : array();

		$out['elementor_kit_colors'] = isset( $e_sys['global_colors'] ) && is_array( $e_sys['global_colors'] ) ? count( $e_sys['global_colors'] ) : 0;
		$out['elementor_kit_fonts']  = isset( $e_kit['system_typography'] ) && is_array( $e_kit['system_typography'] ) ? count( $e_kit['system_typography'] ) : 0;
		$out['elementor_sites']      = count( get_option( 'elementor_website_templates', array() ) ? (array) get_option( 'elementor_website_templates', array() ) : array() );

		set_transient( self::CACHE_KEY, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Return the global style ownership state.
	 *
	 * @param string $project_id Optional project identifier.
	 * @return array<string, mixed>
	 */
	public function ownership( $project_id = '' ) {
		$recorded = get_option( self::OWNERSHIP_OPTION, array() );
		$recorded = is_array( $recorded ) ? $recorded : array();

		$key = ( '' !== (string) $project_id ) ? (string) $project_id : '_global';
		$own = ( isset( $recorded[ $key ] ) && is_array( $recorded[ $key ] ) ) ? $recorded[ $key ] : array();

		$state = (string) ( $own['state'] ?? 'unknown' );
		if ( ! Site_Limits::is_ownership_state( $state ) ) {
			$state = 'unknown';
		}

		return array(
			'state'       => $state,
			'writable'    => ( 'replicaforge_controlled' === $state ),
			'written_at'  => (int) ( $own['written_at'] ?? 0 ),
			'tokens'      => (array) ( $own['tokens'] ?? array() ),
			'rule'        => __( 'ReplicaForge writes global styles only to settings it has already written itself. Anything it did not write is left alone.', 'replicaforge' ),
			'next_states' => Site_Limits::OWNERSHIP_STATES,
		);
	}

	/**
	 * Record that ReplicaForge wrote a set of global styles.
	 *
	 * The only way to become writable. There is deliberately no "adopt" method: a
	 * user who wants ReplicaForge to own their settings says so, and doing that
	 * through this call with an explicit `state` is a deliberate act.
	 *
	 * @param string               $project_id Project identifier.
	 * @param string               $state      New state.
	 * @param array<string, mixed> $tokens     Tokens written.
	 * @return array<string, mixed>
	 */
	public function record_ownership( $project_id, $state, array $tokens = array() ) {
		if ( ! Site_Limits::is_ownership_state( $state ) ) {
			return array(
				'success' => false,
				'message' => __( 'That ownership state is not recognised.', 'replicaforge' ),
			);
		}

		$recorded = get_option( self::OWNERSHIP_OPTION, array() );
		$recorded = is_array( $recorded ) ? $recorded : array();
		$key      = ( '' !== (string) $project_id ) ? (string) $project_id : '_global';

		$recorded[ $key ] = array(
			'state'      => (string) $state,
			'tokens'     => $tokens,
			'written_at' => time(),
		);
		update_option( self::OWNERSHIP_OPTION, $recorded, false );

		return array(
			'success'  => true,
			'ownership'=> $this->ownership( $project_id ),
			'message'  => ( 'replicaforge_controlled' === $state )
				? __( 'ReplicaForge will now manage the global styles it wrote.', 'replicaforge' )
				: __( 'Global styles are marked as not managed by ReplicaForge, so they will be left alone.', 'replicaforge' ),
		);
	}

	/**
	 * Return the Theme Builder locations Elementor actually offers.
	 *
	 * @return array<int, string>
	 */
	public function builder_locations() {
		if ( ! $this->elementor->is_available() ) {
			return array();
		}
		if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			return array();
		}

		// Read through the public manager. A private method call would work today and
		// break silently the day the signature changes, and a silently-empty list
		// here degrades to page sections rather than failing — which is fine, but it
		// should degrade for a *known* reason.
		try {
			$manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_locations_manager();
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_all' ) ) {
				return array();
			}
			$all = $manager->get_all();
			$out = array();
			foreach ( (array) $all as $name => $location ) {
				$out[] = is_string( $name ) ? $name : (string) $location;
			}
			return array_values( array_filter( $out ) );
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'theme_builder_probe_failed', array( 'reason' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Return the header and footer strategies, with the chosen default.
	 *
	 * @param array<string, bool>   $capabilities Capability map.
	 * @param array<string, mixed>  $theme       Theme probe.
	 * @return array<string, mixed>
	 */
	private function strategies( array $capabilities, array $theme ) {
		$has_builder  = ! empty( $capabilities['theme_builder'] );
		$theme_header = ! empty( $theme['header']['declared'] );

		// A theme that ships its own header is the strongest reason to keep it, and
		// a header replacement that silently removes the user's menu is a data-loss
		// bug rather than a style difference. So `theme` wins whenever there is one.
		$header_default = ( $theme_header ) ? 'theme' : ( $has_builder ? 'elementor' : 'replica' );
		$footer_default = ( ! empty( $theme['footer']['declared'] ) ) ? 'theme' : ( $has_builder ? 'elementor' : 'replica' );

		return array(
			'header' => array(
				'options'  => Site_Limits::BOUNDARY_STRATEGIES,
				'default'  => $header_default,
				'reason'   => ( $theme_header )
					? __( 'This theme supplies its own header, so the theme header is kept. Replacing it would remove the menu you already have.', 'replicaforge' )
					: ( $has_builder
						? __( 'This theme supplies no header and Elementor offers header templates, so a theme-builder header will be generated.', 'replicaforge' )
						: __( 'This theme supplies no header and Elementor has no Theme Builder, so a self-contained ReplicaForge header will be generated as part of the first page.', 'replicaforge' ) ),
				'duplication_warning' => __( 'Choosing a generated header when the theme already has one will show the menu twice. ReplicaForge will not do that without being asked.', 'replicaforge' ),
			),
			'footer' => array(
				'options'  => Site_Limits::BOUNDARY_STRATEGIES,
				'default'  => $footer_default,
				'reason'   => ( ! empty( $theme['footer']['declared'] ) )
					? __( 'This theme supplies its own footer, so the theme footer is kept.', 'replicaforge' )
					: ( $has_builder
						? __( 'This theme supplies no footer and Elementor offers footer templates, so a theme-builder footer will be generated.', 'replicaforge' )
						: __( 'This theme supplies no footer and Elementor has no Theme Builder, so a self-contained ReplicaForge footer will be generated as part of the last page.', 'replicaforge' ) ),
				'duplication_warning' => __( 'Choosing a generated footer when the theme already has one will show it twice.', 'replicaforge' ),
			),
		);
	}

	/**
	 * Return the fallbacks that apply when a capability is missing.
	 *
	 * @param array<string, bool> $capabilities Capability map.
	 * @return array<int, array<string, mixed>>
	 */
	private function fallbacks( array $capabilities ) {
		$out = array();

		if ( ! $this->elementor->is_available() ) {
			$out[] = array(
				'missing'  => 'elementor',
				'used'     => 'static',
				'message'  => __( 'Elementor is not active, so no Elementor documents can be created. The analysis and design system are still available.', 'replicaforge' ),
				'blocking' => true,
			);
		}
		if ( empty( $capabilities['containers'] ) ) {
			$out[] = array(
				'missing'  => 'containers',
				'used'     => 'sections_and_columns',
				'message'  => __( 'This Elementor version has no container element, so sections and columns are used instead.', 'replicaforge' ),
				'blocking' => false,
			);
		}
		if ( empty( $capabilities['grid'] ) ) {
			$out[] = array(
				'missing'  => 'grid',
				'used'     => 'flexbox',
				'message'  => __( 'This Elementor version has no CSS grid, so flexbox layouts are used.', 'replicaforge' ),
				'blocking' => false,
			);
		}
		if ( empty( $capabilities['flexbox'] ) ) {
			$out[] = array(
				'missing'  => 'flexbox',
				'used'     => 'inline_blocks',
				'message'  => __( 'This Elementor version has no flexbox support, so a simpler layout is used.', 'replicaforge' ),
				'blocking' => false,
			);
		}
		if ( empty( $capabilities['theme_builder'] ) ) {
			$out[] = array(
				'missing'  => 'theme_builder',
				'used'     => 'page_sections',
				'message'  => __( 'Elementor Theme Builder is not available, so the header and footer are placed inside each page rather than as site-wide templates.', 'replicaforge' ),
				'blocking' => false,
			);
		}
		if ( empty( $capabilities['global_colors'] ) && empty( $capabilities['global_fonts'] ) ) {
			$out[] = array(
				'missing'  => 'global_styles',
				'used'     => 'per_component',
				'message'  => __( 'This Elementor version has no global colours or fonts, so design tokens are applied to each component instead of once.', 'replicaforge' ),
				'blocking' => false,
			);
		}

		return $out;
	}
}
