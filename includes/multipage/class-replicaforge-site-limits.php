<?php
/**
 * Phase 12: multi-page vocabulary and bounds.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Every page type, component role, template, relationship, and bound Phase 12 uses.
 *
 * The same argument as Phase 10's `Plan_Limits` and Phase 11's `Job_States`, and it
 * is worth restating because the cost of getting it wrong has now been paid twice: a
 * name spelled independently in the discovery engine, the classifier, the registry,
 * and the REST layer is a rename that becomes a silent behaviour change in whichever
 * copy was missed.
 *
 * The discovery bounds are deliberately **not** expressed as plan limits here. §5
 * says to use the Phase 10 entitlement system, and a page allowance *is* an
 * entitlement — so {@see self::page_limit_for()} resolves one from a plan rather than
 * declaring a second number that could disagree with it.
 */
final class Site_Limits {

	/**
	 * Website-level schema version.
	 *
	 * Separate from the plugin version and from the per-page representation version.
	 * A website specification is a different document from a page representation, and
	 * reusing one version number for both would make a cache key ambiguous.
	 */
	const SCHEMA_VERSION = '12.0';

	/**
	 * The page record version.
	 */
	const PAGE_SCHEMA_VERSION = '1.0';

	/* ---------------------------------------------------------------------
	 * Discovery
	 * ------------------------------------------------------------------ */

	/**
	 * Default maximum pages to discover.
	 *
	 * A default and a *ceiling*, not a plan limit. {@see self::page_limit_for()}
	 * resolves a plan's allowance and clamps it to this, so no plan and no filter can
	 * make a crawl unbounded on a shared host.
	 */
	const MAX_PAGES = 100;

	/**
	 * Default maximum crawl depth from the entry page.
	 *
	 * Depth 1 is the entry page. Two is its direct links. Depth beyond three is
	 * usually pagination and tag archives, which is an unbounded set dressed as a
	 * hierarchy.
	 */
	const MAX_DEPTH = 3;

	/**
	 * Maximum URLs held in the discovery frontier at once.
	 *
	 * The frontier is what stops a site with a calendar or a tag cloud from
	 * generating an unbounded work list before the page limit is ever consulted.
	 */
	const MAX_FRONTIER = 400;

	/**
	 * Maximum links read from a single page.
	 *
	 * A page with ten thousand links is a sitemap wearing a page's clothes, and
	 * reading all of them is how a crawl goes from slow to hostile.
	 */
	const MAX_LINKS_PER_PAGE = 300;

	/**
	 * Requests per host per minute during discovery.
	 *
	 * Applied as a spacing floor between requests rather than as a quota, because the
	 * question is not "how many may I do" but "how fast may I do it". Crawling a
	 * small site at full speed is antisocial even when it is within every other limit.
	 */
	const MIN_REQUEST_INTERVAL = 1;

	/**
	 * Maximum time a single discovery pass may run, in seconds.
	 *
	 * Phase 11's `Job_Manager` gives a tick half the execution limit; discovery must
	 * finish inside a fraction of that so a tick is not consumed by one site.
	 */
	const MAX_PASS_SECONDS = 20;

	/* ---------------------------------------------------------------------
	 * Classification
	 * ------------------------------------------------------------------ */

	/**
	 * The page types Phase 12 recognises.
	 *
	 * `custom` is a real answer rather than a failure. A site with a page called
	 * "Capabilities" is not misclassified by being called `custom`; it is
	 * misclassified by being called `about` because that seemed close.
	 *
	 * @var array<int, string>
	 */
	const PAGE_TYPES = array(
		'homepage',
		'about',
		'services',
		'service_detail',
		'portfolio',
		'project_detail',
		'blog',
		'blog_archive',
		'blog_post',
		'product_archive',
		'product',
		'category',
		'pricing',
		'contact',
		'faq',
		'team',
		'landing',
		'documentation',
		'search',
		'legal',
		'custom',
	);

	/**
	 * Page types that are never reconstructed automatically.
	 *
	 * Legal pages are included because a site's terms and privacy policy are
	 * authored, not reconstructed, and a generated copy of them is worse than no
	 * copy. `search` and `category` are here because both are query-driven and a
	 * static reconstruction of either is a page that looks broken.
	 *
	 * @var array<int, string>
	 */
	const EXCLUDED_TYPES = array( 'legal', 'search', 'category' );

	/**
	 * Page types that belong to a repeating collection.
	 *
	 * A page of one of these types is a candidate for a template rather than a page
	 * of its own, which is the difference between five service pages and five
	 * separately designed ones.
	 *
	 * @var array<int, string>
	 */
	const COLLECTION_TYPES = array(
		'service_detail',
		'project_detail',
		'blog_post',
		'product',
	);

	/* ---------------------------------------------------------------------
	 * Shared components
	 * ------------------------------------------------------------------ */

	/**
	 * The component roles a shared component may hold.
	 *
	 * A closed list, because a component registry with unbounded roles is a
	 * component registry where `header_primary`, `header-primary`, `Header` and
	 * `HEADER` are four different components that are obviously one.
	 *
	 * @var array<int, string>
	 */
	const SHARED_ROLES = array(
		'header',
		'footer',
		'navigation',
		'cta',
		'button_group',
		'card',
		'testimonial',
		'pricing_card',
		'newsletter',
		'contact_form',
		'logo_cloud',
		'social_links',
		'breadcrumb',
		'sidebar',
		'pagination',
	);

	/**
	 * The section types that map onto a shared component role.
	 *
	 * A section is *assigned* a role by these signals. It is not renamed: the
	 * source type stays in the record, because a source that called it `section_12`
	 * called it that for a reason ReplicaForge cannot see.
	 *
	 * @var array<string, string>
	 */
	const ROLE_SIGNALS = array(
		'header'    => array( 'header', 'masthead', 'topbar', 'site_header' ),
		'footer'    => array( 'footer', 'site_footer', 'colophon' ),
		'cta'       => array( 'cta', 'call_to_action', 'banner_cta' ),
		'testimonial'=> array( 'testimonial', 'testimonials', 'quote' ),
		'newsletter'=> array( 'newsletter', 'signup', 'subscribe' ),
		'contact_form' => array( 'contact_form', 'form', 'contact' ),
		'logo_cloud'=> array( 'logo_cloud', 'clients', 'partners' ),
		'social_links' => array( 'social', 'social_links' ),
		'breadcrumb'=> array( 'breadcrumb', 'breadcrumbs' ),
		'pagination' => array( 'pagination', 'pager' ),
		'sidebar'   => array( 'sidebar', 'aside' ),
	);

	/**
	 * Minimum number of pages a component must appear on to be "shared".
	 *
	 * Two. A component on two pages is shared; a component on one page is that
	 * page's content, and promoting it to a shared structure would mean a user
	 * editing a card on one page changes it on another.
	 */
	const MIN_SHARED_PAGES = 2;

	/**
	 * Maximum shared components recorded for a website.
	 */
	const MAX_SHARED_COMPONENTS = 60;

	/**
	 * Maximum distinct fingerprints retained per shared role.
	 *
	 * A site with nine slightly different footers produces nine fingerprints. Keeping
	 * them all is honest but useless, so the variants are grouped under one role
	 * rather than dropped.
	 */
	const MAX_VARIANTS_PER_ROLE = 6;

	/* ---------------------------------------------------------------------
	 * Templates
	 * ------------------------------------------------------------------ */

	/**
	 * Minimum sections a page must have to be a template candidate.
	 *
	 * Below this, two pages having the same shape is coincidence rather than a
	 * pattern, and declaring a template over two sections produces a template that
	 * cannot hold a real page.
	 */
	const MIN_TEMPLATE_SECTIONS = 3;

	/**
	 * Maximum templates recorded for a website.
	 */
	const MAX_TEMPLATES = 20;

	/**
	 * The template names, and the page types each may capture.
	 *
	 * A named template captures one collection. Naming them keeps a website's
	 * templates legible — "Service Template" is something a user recognises and
	 * "template_003" is not.
	 *
	 * @var array<string, array<int, string>>
	 */
	const TEMPLATE_TYPES = array(
		'service'   => array( 'service_detail' ),
		'product'   => array( 'product' ),
		'post'      => array( 'blog_post' ),
		'project'   => array( 'project_detail' ),
		'category'  => array( 'category' ),
		'archive'   => array( 'blog_archive', 'product_archive' ),
	);

	/* ---------------------------------------------------------------------
	 * Design system
	 * ------------------------------------------------------------------ */

	/**
	 * The token families a global design system may carry.
	 *
	 * Exactly Phase 8's `Token_Engine::FAMILIES`. Duplicating the list would be the
	 * drift this file exists to prevent, so the constant is read from there at
	 * runtime by {@see self::families()} rather than restated.
	 *
	 * @var array<int, string>
	 */
	const TOKEN_ROLES = array(
		'primary', 'secondary', 'accent', 'text', 'muted', 'background', 'surface', 'border',
	);

	/**
	 * Minimum page agreement before a token is declared global.
	 *
	 * A value used on *every* page is a property of the website. A value used on
	 * most pages is a strong candidate. A value used on some is a component or a
	 * page variation, and forcing it into a global token is how a design system
	 * starts lying.
	 */
	const GLOBAL_AGREEMENT = 0.8;

	/**
	 * How a divergence between pages is classified.
	 *
	 * @var array<int, string>
	 */
	const CONFLICT_KINDS = array(
		'none',
		'page_variation',
		'component_variation',
		'extraction_error',
		'conflict',
	);

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	/**
	 * Maximum assets recorded for a website.
	 */
	const MAX_ASSETS = 600;

	/**
	 * The import modes.
	 *
	 * `reference` is the default and the safe one: the replica points at the source
	 * URL, nothing is downloaded, and nothing of somebody else's website is copied
	 * into the media library without a deliberate choice.
	 *
	 * @var array<int, string>
	 */
	const ASSET_MODES = array( 'reference', 'import', 'replace' );

	/**
	 * The asset statuses.
	 *
	 * @var array<int, string>
	 */
	const ASSET_STATUSES = array( 'discovered', 'deduplicated', 'validated', 'imported', 'failed', 'skipped' );

	/* ---------------------------------------------------------------------
	 * Strategy
	 * ------------------------------------------------------------------ */

	/**
	 * Header and footer strategies.
	 *
	 * @var array<int, string>
	 */
	const BOUNDARY_STRATEGIES = array( 'theme', 'elementor', 'replica' );

	/**
	 * Global style ownership states.
	 *
	 * `unknown` is the default for a good reason: it is the one state whose default
	 * behaviour is *do nothing*. §17 says that if ownership is uncertain, do not
	 * overwrite, and a state whose default is to guess would make the caution
	 * meaningless.
	 *
	 * @var array<int, string>
	 */
	const OWNERSHIP_STATES = array(
		'replicaforge_controlled',
		'user_controlled',
		'theme_controlled',
		'mixed',
		'unknown',
	);

	/**
	 * Website reconstruction modes.
	 *
	 * @var array<int, string>
	 */
	const RECONSTRUCTION_MODES = array( 'balanced', 'exact_visual', 'editable_structure', 'consistent_website' );

	/**
	 * The change scopes an incremental sync can have.
	 *
	 * §58's requirement is that a change to one page must not rebuild the website.
	 * The scope is what decides what to touch, and it is computed from what actually
	 * changed rather than assumed.
	 *
	 * @var array<int, string>
	 */
	const CHANGE_SCOPES = array(
		'page_only',
		'shared_component',
		'global_design',
		'navigation',
		'asset',
		'template',
	);

	/* ---------------------------------------------------------------------
	 * Derived helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return the token families, read from Phase 8 rather than restated.
	 *
	 * @return array<int, string>
	 */
	public static function families() {
		return Token_Engine::FAMILIES;
	}

	/**
	 * Return whether a value is a declared page type.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_page_type( $value ) {
		return is_string( $value ) && in_array( $value, self::PAGE_TYPES, true );
	}

	/**
	 * Return whether a value is a declared shared role.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_shared_role( $value ) {
		return is_string( $value ) && in_array( $value, self::SHARED_ROLES, true );
	}

	/**
	 * Return whether a value is a declared ownership state.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_ownership_state( $value ) {
		return is_string( $value ) && in_array( $value, self::OWNERSHIP_STATES, true );
	}

	/**
	 * Return the maximum pages a plan allows.
	 *
	 * The plan's `analysis_per_period` allowance is *not* reused as a page count: a
	 * user on a plan with twenty analyses a month has not asked for twenty pages, and
	 * charging them a month's allowance for one crawl would be wrong in both
	 * directions. Instead the plan's rank scales a documented allowance, clamped to
	 * {@see self::MAX_PAGES} so nothing makes a crawl unbounded.
	 *
	 * @param string $plan_id Plan identifier.
	 * @return int
	 */
	public static function page_limit_for( $plan_id ) {
		$by_rank = array(
			Plan_Limits::PLAN_ORDER[0] => 5,
			Plan_Limits::PLAN_ORDER[1] => 15,
			Plan_Limits::PLAN_ORDER[2] => 50,
			Plan_Limits::PLAN_ORDER[3] => 100,
		);

		$plan_id = is_string( $plan_id ) ? strtolower( trim( $plan_id ) ) : '';
		$rank    = Plan_Limits::plan_rank( $plan_id );
		$key     = ( $rank >= 0 && isset( Plan_Limits::PLAN_ORDER[ $rank ] ) ) ? Plan_Limits::PLAN_ORDER[ $rank ] : Plan_Limits::PLAN_ORDER[0];

		return (int) min( self::MAX_PAGES, (int) ( $by_rank[ $key ] ?? 5 ) );
	}

	/**
	 * Return the maximum pages a user may hold at once, by plan.
	 *
	 * This is a *hold*, not a monthly allowance: it is how many pages a project may
	 * have selected at one time, which is a storage and generation-budget question
	 * rather than a usage question.
	 *
	 * @param string $plan_id Plan identifier.
	 * @return int
	 */
	public static function selected_page_limit( $plan_id ) {
		return min( self::MAX_PAGES, self::page_limit_for( $plan_id ) );
	}

	/**
	 * Return the default generation order.
	 *
	 * §39 gives the order and the reasoning is the same: the things everything else
	 * depends on come first, so a failure part-way through leaves a usable site
	 * rather than three pages that reference a design system that was never built.
	 *
	 * @return array<int, string>
	 */
	public static function generation_order() {
		return array(
			'global_design',
			'assets',
			'header',
			'footer',
			'shared_components',
			'templates',
			'pages_primary',
			'pages_secondary',
			'pages_collection',
			'navigation',
			'responsive',
			'validation',
		);
	}

	/**
	 * Return the page types that count as "primary".
	 *
	 * The homepage and the pages a visitor arrives to reach. A site with a broken
	 * homepage and eleven working service pages is not in a good state.
	 *
	 * @return array<int, string>
	 */
	public static function primary_page_types() {
		return array( 'homepage', 'contact', 'about', 'services' );
	}
}
