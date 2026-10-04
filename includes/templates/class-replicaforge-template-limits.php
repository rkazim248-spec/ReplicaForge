<?php
/**
 * Phase 19: template and design-system vocabulary.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The closed vocabularies and ceilings Phase 19 works within.
 *
 * ### Why this class exists rather than a set of constants on each consumer
 *
 * Every vocabulary here is a *decision boundary*. A template type, a token category, a
 * validation state, a conflict strategy: each is a finite set, and the finite set is what
 * makes "is this value acceptable" a question with an answer instead of a judgement call.
 *
 * An unbounded string somewhere in the middle of the system turns every downstream check
 * into a string comparison somebody has to remember to write. Every class in this feature
 * consults this one instead, so adding a value here is the only way to add one anywhere.
 *
 * ### What this class does not do
 *
 * It stores nothing. It is the same shape as `Site_Limits`, `Elementor_Limits` and
 * `Orchestrator_Limits` — a constant holder with a few static predicates — and it follows
 * the same rule those classes set: **no value here is ever invented at read time.** Where a
 * value could only be produced by guessing, the vocabulary says so (`NOT_MEASURED`) and
 * the consumer displays "Not available" rather than a number.
 *
 * ### Relationship to Phase 12
 *
 * `Site_Limits` already owns the *page* and *shared component* vocabularies, and
 * `Token_Engine` owns the token *families*. This class does not restate either. Where a
 * Phase 19 concept overlaps a Phase 12 one it reads through, and only the genuinely new
 * vocabularies live here.
 */
final class Template_Limits {

	/**
	 * Phase marker.
	 *
	 * @var string
	 */
	const PHASE = '19.0';

	/**
	 * The template snapshot schema version.
	 *
	 * This is the version written *into* every template version record and every exported
	 * package. It is deliberately separate from `Schema::DB_SCHEMA_VERSION`, which tracks
	 * the database, and from `Orchestrator_Limits::SCHEMA_VERSION`, which tracks workflows.
	 * A template package is a portable artefact that may outlive any single install's
	 * database version, so it carries its own.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '19.0';

	/**
	 * Engine version for the extraction pipeline.
	 *
	 * Bumped when the *shape* of a snapshot changes but its meaning does not, so a
	 * re-extraction can be recognised without invalidating stored templates.
	 *
	 * @var string
	 */
	const ENGINE_VERSION = '1.0';

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------ */

	/**
	 * Option holding the design-token registry index.
	 *
	 * The tokens themselves live in the `template_versions` rows, versioned with the
	 * template that produced them. This option holds only the *current* token set per
	 * design system, because a live token registry has to answer "what is the primary
	 * colour right now" without loading every template.
	 *
	 * @var string
	 */
	const TOKENS_OPTION = 'replicaforge_design_tokens';

	/**
	 * Maximum number of distinct design systems tracked at once.
	 *
	 * A design system here is one per workspace plus the built-in defaults, not one per
	 * template: templates consume tokens, they do not each mint their own. The ceiling is
	 * generous because the number is bounded by workspaces, and low because a site with
	 * hundreds of them is a sign the boundary between "design system" and "template" has
	 * been lost.
	 *
	 * @var int
	 */
	const MAX_DESIGN_SYSTEMS = 50;

	/**
	 * Maximum tokens retained per design system.
	 *
	 * @var int
	 */
	const MAX_TOKENS = 400;

	/**
	 * Maximum templates a workspace may hold in one visibility scope.
	 *
	 * @var int
	 */
	const MAX_TEMPLATES = 500;

	/**
	 * Immutable version records retained per template.
	 *
	 * Matches `Project_Repository::MAX_VERSIONS` deliberately. A template and a project
	 * version are different entities with the same retention policy, and two different
	 * numbers would mean the same user-facing question ("how far back can I look?") has
	 * two different answers depending on which screen asked it.
	 *
	 * @var int
	 */
	const MAX_VERSIONS = 20;

	/**
	 * Reusable components retained per workspace.
	 *
	 * @var int
	 */
	const MAX_COMPONENTS = 600;

	/**
	 * Version records retained per component.
	 *
	 * @var int
	 */
	const MAX_COMPONENT_VERSIONS = 10;

	/**
	 * Hard ceiling on the serialised size of one template version snapshot, in bytes.
	 *
	 * `Workflow_Artifacts` caps an artifact at 512 KB and a whole workflow at 2 MB. A
	 * template version is the same order of object — a bounded document plus its tokens —
	 * so it carries the same ceiling rather than a new one invented for this phase.
	 *
	 * @var int
	 */
	const MAX_VERSION_BYTES = 524288;

	/**
	 * Hard ceiling on a whole package, template plus components plus tokens, in bytes.
	 *
	 * @var int
	 */
	const MAX_PACKAGE_BYTES = 2097152;

	/**
	 * A metric that could not be measured.
	 *
	 * The Phase 19 quality indicators report this rather than a fabricated percentage.
	 * The string is deliberately not numeric and not null, so a caller that forgets to
	 * check produces a visible "Not available" rather than a silent `0`.
	 *
	 * @var string
	 */
	const NOT_MEASURED = 'not_available';

	/* ---------------------------------------------------------------------
	 * Template taxonomy
	 * ------------------------------------------------------------------ */

	/**
	 * Template types, grouped by the Elementor surface they install onto.
	 *
	 * The three groups are not cosmetic. Elementor treats a header, a single post and a
	 * page as different document kinds with different `template_type` values and different
	 * available locations, so a template that says "this is a header" is making a promise
	 * about where it can be installed. Grouping them makes that promise checkable
	 * rather than implied.
	 *
	 * @var array<string, array<int, string>>
	 */
	const TEMPLATE_TYPES = array(
		'page'    => array(
			'homepage',
			'about',
			'services',
			'service_detail',
			'portfolio',
			'project_detail',
			'blog',
			'blog_archive',
			'blog_post',
			'contact',
			'faq',
			'pricing',
			'landing',
			'documentation',
			'custom',
		),
		'theme'   => array(
			'header',
			'footer',
			'single_post',
			'archive',
			'product_archive',
			'single_product',
			'search_results',
			'error_404',
		),
		'section' => array(
			'hero',
			'features',
			'services_section',
			'testimonials',
			'pricing_section',
			'cta',
			'newsletter',
			'contact_section',
			'team',
			'portfolio_grid',
			'product_grid',
			'blog_grid',
			'faq_section',
			'stats',
		),
		'component' => array(
			'button',
			'card',
			'product_card',
			'blog_card',
			'testimonial_card',
			'pricing_card',
			'nav_item',
			'badge',
			'form_field',
			'social_links',
		),
	);

	/**
	 * Template types that map onto an Elementor *theme* location rather than a page.
	 *
	 * Reused by the compatibility check: a header template cannot be installed into a page
	 * slot, and saying so before the user spends effort on it is the whole point of the
	 * preview step.
	 *
	 * @var array<int, string>
	 */
	const THEME_TYPES = array( 'header', 'footer' );

	/**
	 * Library categories.
	 *
	 * These describe *where a template came from and who can see it*, which is a different
	 * axis from {@see Template_Limits::TEMPLATE_TYPES}, which describes what it is. A
	 * section template is a type; "project templates" is a category.
	 *
	 * @var array<string, string>
	 */
	const CATEGORIES = array(
		'mine'      => 'My templates',
		'project'   => 'Project templates',
		'workspace' => 'Workspace templates',
		'shared'    => 'Shared templates',
		'imported'  => 'Imported templates',
		'archived'  => 'Archived templates',
	);

	/**
	 * Visibility levels.
	 *
	 * `client_review` exists because a client must be able to be shown a template in the
	 * review surface without being able to change it or export it, and folding that into
	 * `private` would mean giving the client either too much or nothing.
	 *
	 * @var array<string, string>
	 */
	const VISIBILITY = array(
		'private'        => 'Only me',
		'workspace'      => 'Everyone in this workspace',
		'project_members' => 'Members of the projects it is attached to',
		'client_review'  => 'Visible to clients in review, read-only',
	);

	/**
	 * Visibility levels a client may hold on a template.
	 *
	 * A client is refused at the store level regardless of what the record claims, so a
	 * hand-edited row cannot widen a client's reach.
	 *
	 * @var array<int, string>
	 */
	const CLIENT_VISIBILITY = array( 'client_review' );

	/**
	 * Template lifecycle states.
	 *
	 * `archived` is a soft delete: the template stops appearing in the library and stops
	 * being installable, but its versions are kept, because an archived template is
	 * frequently one that was superseded rather than abandoned, and destroying its history
	 * because someone tidied up is not a recoverable mistake.
	 *
	 * @var array<string, string>
	 */
	const STATUSES = array(
		'draft'     => 'Draft',
		'active'    => 'Active',
		'archived'  => 'Archived',
	);

	/**
	 * States a template may be installed from.
	 *
	 * @var array<int, string>
	 */
	const INSTALLABLE_STATUSES = array( 'active' );

	/* ---------------------------------------------------------------------
	 * Design tokens
	 * ------------------------------------------------------------------ */

	/**
	 * Token categories.
	 *
	 * The first seven are exactly `Token_Engine::FAMILIES`, read through rather than
	 * restated: Phase 8 already decides what a token family is, and a Phase 19 list that
	 * could disagree with it would be a second source of truth. The last two are Phase 19
	 * additions, for the two token kinds Phase 8 does not extract but a design system
	 * needs: explicit semantic roles, and the shape/spacing decisions a section depends on.
	 *
	 * @var array<int, string>
	 */
	const TOKEN_CATEGORIES = array(
		'colors',
		'typography',
		'spacing',
		'radius',
		'shadows',
		'containers',
		'breakpoints',
		'semantic',
		'shape',
	);

	/**
	 * Token categories that map one-to-one onto a Phase 8 family.
	 *
	 * @var array<int, string>
	 */
	const PHASE8_CATEGORIES = array(
		'colors',
		'typography',
		'spacing',
		'radius',
		'shadows',
		'containers',
		'breakpoints',
	);

	/**
	 * Semantic token roles.
	 *
	 * These are the names a template actually binds to. A design system whose tokens are
	 * only `color_a3f19b` is a colour swatch list, not a design system: nothing can be
	 * re-pointed without editing every consumer.
	 *
	 * Roles are *assigned from evidence* by `Design_Token_Registry`, never invented. A role
	 * with no supporting token is left unassigned and reported as unassigned.
	 *
	 * @var array<string, string>
	 */
	const SEMANTIC_ROLES = array(
		'color.primary'   => 'Primary',
		'color.secondary' => 'Secondary',
		'color.accent'    => 'Accent',
		'color.background' => 'Background',
		'color.surface'   => 'Surface',
		'color.text'      => 'Text',
		'color.muted'     => 'Muted text',
		'color.border'    => 'Border',
		'color.success'   => 'Success',
		'color.warning'   => 'Warning',
		'color.error'     => 'Error',
		'font.family'     => 'Font family',
		'font.size_base'  => 'Base font size',
		'font.line_height' => 'Base line height',
		'spacing.page'    => 'Page padding',
		'spacing.section' => 'Section spacing',
		'spacing.gutter'  => 'Container gap',
		'spacing.card'    => 'Card padding',
		'shape.radius'    => 'Corner radius',
		'shape.border'    => 'Border width',
		'layout.container' => 'Container width',
	);

	/**
	 * Token scopes.
	 *
	 * @var array<int, string>
	 */
	const TOKEN_SCOPES = array( 'global', 'design_system', 'template', 'component' );

	/**
	 * Token ownership.
	 *
	 * Reuses `Site_Limits::OWNERSHIP_STATES` rather than inventing a parallel vocabulary —
	 * a Phase 15 concept of ownership and a Phase 19 concept of ownership that use
	 * different words for the same states is a bug waiting to happen in a merge.
	 *
	 * `user_controlled` is the one that matters: it means a token a person has set by hand,
	 * which no automated re-extraction may overwrite.
	 *
	 * @var array<int, string>
	 */
	const TOKEN_OWNERSHIP = array(
		'replicaforge_controlled',
		'user_controlled',
		'theme_controlled',
		'mixed',
		'unknown',
	);

	/**
	 * Where a token value may legitimately have come from.
	 *
	 * A token whose source is not in this list is not trusted for install, because the
	 * point of the list is to make "this value came from somewhere real" checkable.
	 *
	 * @var array<int, string>
	 */
	const TOKEN_SOURCES = array(
		'source_analysis',     // Phase 2 representation.
		'project_design_system', // Phase 12 global design system.
		'elementor_global',    // Elementor's own global settings.
		'user_defined',        // Set by a person, deliberately.
		'imported_package',    // Carried in by an import, with its own provenance.
	);

	/* ---------------------------------------------------------------------
	 * Content slots
	 * ------------------------------------------------------------------ */

	/**
	 * Content slot types.
	 *
	 * @var array<string, string>
	 */
	const SLOT_TYPES = array(
		'heading'        => 'Heading',
		'paragraph'      => 'Paragraph',
		'button_label'   => 'Button label',
		'button_url'     => 'Button link',
		'image'          => 'Image',
		'logo'           => 'Logo',
		'product'        => 'Product',
		'blog_post'      => 'Blog post',
		'testimonial'    => 'Testimonial',
		'pricing_item'   => 'Pricing item',
		'team_member'    => 'Team member',
		'icon'           => 'Icon',
		'link'           => 'Link',
		'rich_text'      => 'Rich text',
	);

	/**
	 * Slot value kinds.
	 *
	 * `dynamic` means the slot can be bound to a live data source at install time;
	 * `static` means it carries a literal. A template that claims a dynamic source the
	 * destination environment does not have is marked unavailable rather than filled — see
	 * the Phase 19 dynamic-content checks.
	 *
	 * @var array<int, string>
	 */
	const SLOT_KINDS = array( 'static', 'dynamic', 'either' );

	/**
	 * Dynamic content source types.
	 *
	 * Each names a *kind* of data, not a provider. Whether it resolves depends on the
	 * destination site having the provider — WordPress core supplies the post fields,
	 * WooCommerce supplies the product ones. A source whose provider is absent is reported
	 * unavailable, never substituted.
	 *
	 * @var array<int, string>
	 */
	const DYNAMIC_SOURCES = array(
		'post_title',
		'post_content',
		'post_excerpt',
		'featured_image',
		'post_author',
		'post_date',
		'post_categories',
		'site_title',
		'site_tagline',
		'product_title',
		'product_price',
		'product_image',
		'product_description',
		'product_attributes',
		'product_categories',
	);

	/**
	 * Dynamic sources that require WooCommerce.
	 *
	 * Declared rather than inferred at read time, so the compatibility check can say
	 * "this template needs WooCommerce" before install rather than after.
	 *
	 * @var array<int, string>
	 */
	const WOOCOMMERCE_SOURCES = array(
		'product_title',
		'product_price',
		'product_image',
		'product_description',
		'product_attributes',
		'product_categories',
	);

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	/**
	 * Asset handling modes.
	 *
	 * Reuses `Site_Limits::ASSET_MODES` exactly — it is the same decision with the same
	 * three answers. A Phase 19 copy would drift.
	 *
	 * @var array<int, string>
	 */
	const ASSET_MODES = array( 'reference', 'import', 'replace' );

	/**
	 * Asset provenance classes.
	 *
	 * This is the Phase 19 answer to "who owns this image", and it is the part of the
	 * design that matters most commercially. A template may carry *structure* freely; what
	 * it may not do is redistribute someone else's photography. So an asset is classified
	 * and the classification decides whether it may travel in a package.
	 *
	 * @var array<string, string>
	 */
	const ASSET_PROVENANCE = array(
		'user_owned'      => 'Created or licensed by this site',
		'source_derived'  => 'Taken from the analysed source website',
		'licensed'        => 'Licensed for redistribution, licence recorded',
		'third_party'     => 'Third party, rights unknown',
		'restricted'      => 'Known to be restricted',
		'placeholder'     => 'Generated placeholder, no third-party content',
	);

	/**
	 * Provenance classes whose assets may be packaged for redistribution.
	 *
	 * `source_derived` and `third_party` are deliberately excluded. This is the single
	 * line in the whole phase that stops ReplicaForge functioning as a website copier, and
	 * it is enforced in `Template_Package`, not in the UI.
	 *
	 * @var array<int, string>
	 */
	const REDISTRIBUTABLE_PROVENANCE = array( 'user_owned', 'licensed', 'placeholder' );

	/**
	 * Asset types a template may reference.
	 *
	 * @var array<int, string>
	 */
	const ASSET_TYPES = array( 'image', 'logo', 'icon', 'font', 'video' );

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------ */

	/**
	 * Validation states.
	 *
	 * Five, not two. The reason a template is "incompatible" is materially different from
	 * the reason it is "invalid", and an operator debugging a failed install needs to be
	 * told which they are looking at. `needs_review` is separate from
	 * `valid_with_warnings` because a warning is informational and a review demand is not.
	 *
	 * @var array<string, string>
	 */
	const VALIDATION_STATES = array(
		'valid'            => 'Valid',
		'valid_warnings'   => 'Valid with warnings',
		'needs_review'     => 'Needs review',
		'incompatible'     => 'Incompatible',
		'invalid'          => 'Invalid',
	);

	/**
	 * Validation states that permit installation.
	 *
	 * @var array<int, string>
	 */
	const INSTALLABLE_VALIDATION = array( 'valid', 'valid_warnings' );

	/**
	 * Validation dimensions, reported separately.
	 *
	 * §33 forbids a single quality score, so each dimension is measured and reported on its
	 * own, and a dimension that cannot be measured reports `NOT_MEASURED`.
	 *
	 * @var array<int, string>
	 */
	const VALIDATION_DIMENSIONS = array(
		'structure',
		'design',
		'responsive',
		'interaction',
		'assets',
		'security',
		'compatibility',
		'elementor',
	);

	/**
	 * Severity levels for a validation finding.
	 *
	 * @var array<string, string>
	 */
	const SEVERITIES = array(
		'error'   => 'Blocks use',
		'warning' => 'Allowed, but worth knowing',
		'notice'  => 'Informational',
		'review'  => 'A person should decide',
	);

	/* ---------------------------------------------------------------------
	 * Import, conflict, dependencies
	 * ------------------------------------------------------------------ */

	/**
	 * Conflict strategies offered on import.
	 *
	 * Note what is absent: there is no "overwrite". Overwriting an existing reusable asset
	 * silently is the one outcome §27 and the Phase 19 stop conditions both forbid, so it
	 * is not an option that can be selected — not a default, not a fallback.
	 *
	 * @var array<string, string>
	 */
	const CONFLICT_STRATEGIES = array(
		'keep_existing'  => 'Keep the existing item',
		'new_version'    => 'Add as a new version of the existing item',
		'rename'         => 'Import under a different name',
		'merge_tokens'   => 'Merge, reviewing every difference',
		'manual'         => 'Leave for manual review',
	);

	/**
	 * Conflict kinds.
	 *
	 * @var array<string, string>
	 */
	const CONFLICT_KINDS = array(
		'template_name'  => 'A template with this name already exists',
		'component_id'   => 'A component with this id already exists',
		'token_name'     => 'A design token with this name already exists',
		'token_value'    => 'A design token with this name has a different value',
		'asset'          => 'An asset with this reference already exists',
		'capability'     => 'The installed Elementor cannot support this template',
	);

	/**
	 * Dependency satisfaction states.
	 *
	 * @var array<string, string>
	 */
	const DEPENDENCY_STATES = array(
		'available'    => 'Available',
		'missing'      => 'Missing',
		'incompatible' => 'Incompatible version',
		'optional'     => 'Optional, degrades gracefully',
	);

	/**
	 * Elementor feature capabilities a template may require.
	 *
	 * Deliberately the same seven names `Site_Compatibility::capabilities()` reports, so a
	 * template's declared requirements are checkable against an existing probe rather than
	 * a second detection path that might disagree.
	 *
	 * @var array<int, string>
	 */
	const ELEMENTOR_FEATURES = array(
		'containers',
		'flexbox',
		'grid',
		'global_colors',
		'global_fonts',
		'responsive',
		'theme_builder',
	);

	/**
	 * Import steps, in the order they run.
	 *
	 * Exposed so the import screen can show progress and so a test can assert the order
	 * rather than the outcome alone. An import that reaches step 6 without step 3 is
	 * broken in a way the outcome alone would not reveal.
	 *
	 * @var array<int, string>
	 */
	const IMPORT_STEPS = array(
		'validate_package',
		'security_scan',
		'schema_validation',
		'compatibility_check',
		'dependency_resolution',
		'asset_review',
		'conflict_detection',
		'user_confirmation',
		'install',
	);

	/**
	 * Install steps, in the order they run.
	 *
	 * @var array<int, string>
	 */
	const INSTALL_STEPS = array(
		'prepare',
		'validate',
		'resolve_dependencies',
		'create_snapshot',
		'install',
		'post_validate',
		'commit',
	);

	/* ---------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------ */

	/**
	 * The Phase 19 capability group.
	 *
	 * Merged into `Workspace_Limits::CAPABILITY_GROUPS` rather than kept separately, because
	 * `Permission_Manager::can()` validates against that one closed list. A second list
	 * would either be ignored by the permission manager — so the capability would appear to
	 * work and never be checked — or require a second permission path, which the Phase 19
	 * stop conditions forbid.
	 *
	 * @var array<int, string>
	 */
	const CAPABILITIES = array(
		'templates.view',
		'templates.create',
		'templates.edit',
		'templates.delete',
		'templates.export',
		'templates.import',
		'templates.share',
		'templates.install',
		'design_systems.manage',
	);

	/* ---------------------------------------------------------------------
	 * Predicates
	 * ------------------------------------------------------------------ */

	/**
	 * Return every template type, flattened.
	 *
	 * @return array<int, string>
	 */
	public static function template_types() {
		$out = array();
		foreach ( self::TEMPLATE_TYPES as $group ) {
			foreach ( $group as $type ) {
				$out[] = $type;
			}
		}
		return $out;
	}

	/**
	 * Return the group a template type belongs to, or an empty string.
	 *
	 * @param mixed $value Candidate type.
	 * @return string One of `page`, `theme`, `section`, `component`, or ''.
	 */
	public static function template_group( $value ) {
		$value = is_string( $value ) ? $value : '';
		foreach ( self::TEMPLATE_TYPES as $group => $types ) {
			if ( in_array( $value, $types, true ) ) {
				return $group;
			}
		}
		return '';
	}

	/**
	 * Return whether a value is a known template type.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_template_type( $value ) {
		return '' !== self::template_group( $value );
	}

	/**
	 * Return whether a template type installs onto a theme location.
	 *
	 * @param mixed $value Candidate type.
	 * @return bool
	 */
	public static function is_theme_type( $value ) {
		return in_array( (string) $value, self::THEME_TYPES, true );
	}

	/**
	 * Return whether a value is a known category.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_category( $value ) {
		return is_string( $value ) && isset( self::CATEGORIES[ $value ] );
	}

	/**
	 * Return whether a value is a known visibility.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_visibility( $value ) {
		return is_string( $value ) && isset( self::VISIBILITY[ $value ] );
	}

	/**
	 * Return whether a value is a known status.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_status( $value ) {
		return is_string( $value ) && isset( self::STATUSES[ $value ] );
	}

	/**
	 * Return whether a value is a known token category.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_token_category( $value ) {
		return is_string( $value ) && in_array( $value, self::TOKEN_CATEGORIES, true );
	}

	/**
	 * Return whether a value is a known semantic role.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_semantic_role( $value ) {
		return is_string( $value ) && isset( self::SEMANTIC_ROLES[ $value ] );
	}

	/**
	 * Return whether a value is a known token scope.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_token_scope( $value ) {
		return is_string( $value ) && in_array( $value, self::TOKEN_SCOPES, true );
	}

	/**
	 * Return whether a value is a known token ownership state.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_token_ownership( $value ) {
		return is_string( $value ) && in_array( $value, self::TOKEN_OWNERSHIP, true );
	}

	/**
	 * Return whether a value is a known token source.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_token_source( $value ) {
		return is_string( $value ) && in_array( $value, self::TOKEN_SOURCES, true );
	}

	/**
	 * Return whether a value is a known slot type.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_slot_type( $value ) {
		return is_string( $value ) && isset( self::SLOT_TYPES[ $value ] );
	}

	/**
	 * Return whether a value is a known slot kind.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_slot_kind( $value ) {
		return is_string( $value ) && in_array( $value, self::SLOT_KINDS, true );
	}

	/**
	 * Return whether a value is a known dynamic source.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_dynamic_source( $value ) {
		return is_string( $value ) && in_array( $value, self::DYNAMIC_SOURCES, true );
	}

	/**
	 * Return whether a dynamic source needs WooCommerce.
	 *
	 * @param mixed $value Candidate source.
	 * @return bool
	 */
	public static function needs_woocommerce( $value ) {
		return in_array( (string) $value, self::WOOCOMMERCE_SOURCES, true );
	}

	/**
	 * Return whether an asset class may be redistributed in a package.
	 *
	 * The check the whole phase turns on. `source_derived` and `third_party` assets are
	 * referenced, never embedded.
	 *
	 * @param mixed $provenance Candidate class.
	 * @return bool
	 */
	public static function is_redistributable( $provenance ) {
		return in_array( (string) $provenance, self::REDISTRIBUTABLE_PROVENANCE, true );
	}

	/**
	 * Return whether a provenance class is known.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_asset_provenance( $value ) {
		return is_string( $value ) && isset( self::ASSET_PROVENANCE[ $value ] );
	}

	/**
	 * Return whether a value is a known validation state.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_validation_state( $value ) {
		return is_string( $value ) && isset( self::VALIDATION_STATES[ $value ] );
	}

	/**
	 * Return whether a value permits installation.
	 *
	 * @param mixed $value Validation state.
	 * @return bool
	 */
	public static function is_installable( $value ) {
		return in_array( (string) $value, self::INSTALLABLE_VALIDATION, true );
	}

	/**
	 * Return whether a value is a known conflict strategy.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_conflict_strategy( $value ) {
		return is_string( $value ) && isset( self::CONFLICT_STRATEGIES[ $value ] );
	}

	/**
	 * Return whether a value is a known Elementor feature requirement.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_elementor_feature( $value ) {
		return is_string( $value ) && in_array( $value, self::ELEMENTOR_FEATURES, true );
	}

	/**
	 * Return a human label for a template type.
	 *
	 * @param mixed $value Template type.
	 * @return string
	 */
	public static function type_label( $value ) {
		$value = (string) $value;

		foreach ( self::TEMPLATE_TYPES as $types ) {
			if ( in_array( $value, $types, true ) ) {
				return ucwords( str_replace( '_', ' ', $value ) );
			}
		}

		return $value;
	}

	/**
	 * Return the human labels for every template type, keyed by type.
	 *
	 * Used by the library filters so the vocabulary has exactly one source for its own
	 * display names.
	 *
	 * @return array<string, string>
	 */
	public static function type_labels() {
		$out = array();
		foreach ( self::template_types() as $type ) {
			$out[ $type ] = self::type_label( $type );
		}
		return $out;
	}
}
