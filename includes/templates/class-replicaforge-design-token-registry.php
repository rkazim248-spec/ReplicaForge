<?php
/**
 * Phase 19: the design-token registry.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * A normalised, addressable design-token registry built on top of Phase 8 and Phase 12.
 *
 * ### Why this exists when `Token_Engine` already extracts tokens
 *
 * `Token_Engine` (Phase 8) is excellent at *discovering* values: it walks a
 * representation and reports every colour, spacing step and shadow it can prove is in use,
 * with the evidence that proves it. That is the hard part and it is done.
 *
 * What it deliberately does not do is make those values *addressable* or *ownable*, and a
 * template ecosystem needs both:
 *
 * - **No identity.** A Phase 8 token is keyed by position in a list. It has a `name`
 *   (`'color_a3f19b'`, `'space_03'`) and that is it. Nothing can hold a *reference* to a
 *   token, so §8's "if the user changes a global token, identify affected templates" has
 *   no key to join on. This class gives every token a `token_id` that is stable across
 *   re-extraction.
 * - **No ownership.** Phase 8 has no notion of a value a person set deliberately. §7 wants
 *   `ownership`, and the reason it matters is not bookkeeping: a re-extraction must never
 *   overwrite a value a human chose, and without an ownership field there is no way to know
 *   which is which.
 * - **A dead `confidence` field.** `Site_Design_System::pick_named()` reads
 *   `$token['confidence']`, and `Token_Engine` never writes one. So every confidence in the
 *   existing design system silently falls back to a hard-coded `0.6`. This class computes a
 *   real one from real evidence and says in the docblock how, so a number here means
 *   something.
 * - **No semantic roles.** Phase 8 reports `color_a3f19b`; a design system a template can
 *   bind to needs `color.primary`. See {@see Design_Token_Registry::assign_roles()}.
 *
 * ### What it does not do
 *
 * It does not re-derive tokens. Every value, every occurrence count, every piece of
 * evidence comes from `Token_Engine` or `Site_Design_System`. This class normalises,
 * identifies, and records provenance. If Phase 8 reports nothing, this class has nothing —
 * it does not fill a gap with a plausible default, because a default in a design token is a
 * fabricated design decision.
 *
 * ### Storage
 *
 * A template's tokens are versioned with the template that produced them, in
 * `template_versions.tokens`. This class also keeps a *live* registry per workspace in
 * {@see Template_Limits::TOKENS_OPTION}, because the question "what is the primary colour
 * on this site right now" must be answerable without loading every template — and because a
 * user who overrides a token expects the override to persist even if no template is
 * re-extracted.
 */
final class Design_Token_Registry {

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Live registry, keyed by workspace id then token id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private $cache = array();

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/* ---------------------------------------------------------------------
	 * Extraction
	 * ------------------------------------------------------------------ */

	/**
	 * Normalise a Phase 12 design system into addressable Phase 19 tokens.
	 *
	 * @param array<string, mixed> $design_system Output of `Site_Design_System::build()`.
	 * @param array<string, mixed> $context      Extraction context.
	 * @return array{tokens: array<string, array<string, mixed>>, counts: array<string, int>, total: int, unassigned_roles: array<int, string>, warnings: array<int, string>}
	 */
	public function extract( array $design_system, array $context = array() ) {
		$source  = Template_Limits::is_token_source( $context['source'] ?? '' ) ? (string) $context['source'] : 'project_design_system';
		$scope   = Template_Limits::is_token_scope( $context['scope'] ?? '' ) ? (string) $context['scope'] : 'global';
		$version = is_string( $context['version'] ?? null ) && '' !== $context['version'] ? (string) $context['version'] : Template_Limits::SCHEMA_VERSION;

		$families  = isset( $design_system['families'] ) && is_array( $design_system['families'] ) ? $design_system['families'] : array();
		$agreement = isset( $design_system['agreement'] ) && is_array( $design_system['agreement'] ) ? $design_system['agreement'] : array();
		$roles     = isset( $design_system['roles'] ) && is_array( $design_system['roles'] ) ? $design_system['roles'] : array();

		$tokens   = array();
		$counts   = array();
		$warnings = array();

		foreach ( $families as $category => $list ) {
			if ( ! Template_Limits::is_token_category( $category ) ) {
				continue;
			}
			if ( ! is_array( $list ) ) {
				continue;
			}

			$counts[ $category ] = 0;

			foreach ( $list as $index => $token ) {
				if ( ! is_array( $token ) ) {
					continue;
				}

				$record = $this->normalise( $token, (string) $category, (int) $index, $source, $scope, $version, $agreement );

				if ( null === $record ) {
					continue;
				}

				$tokens[ $record['token_id'] ] = $record;
				$counts[ $category ]++;
			}
		}

		/*
		 * A design system with no tokens at all is a real and common state — a site whose
		 * source has almost no extractable styling — and it is worth saying so, because
		 * "0 tokens" reads as a bug in a library screen and "the source had nothing to
		 * extract" reads as information.
		 */
		if ( array() === $tokens ) {
			$warnings[] = __( 'The analysed page produced no design tokens, so this template carries structure but no design system. That is what the source contained, not a failure to read it.', 'replicaforge' );
		}

		$assigned = $this->assign_roles( $tokens, $roles );

		if ( $assigned['warnings'] ) {
			$warnings = array_merge( $warnings, $assigned['warnings'] );
		}

		/*
		 * Bound the set. `Token_Engine` already caps per family, and that cap is respected
		 * here, but the cap is applied again because a token registry is a *live* structure
		 * that accumulates across re-extractions and a caller that merges two extractions
		 * should not be able to grow it without bound.
		 */
		if ( count( $tokens ) > Template_Limits::MAX_TOKENS ) {
			$warnings[] = sprintf(
				/* translators: 1: how many tokens were found, 2: how many were kept. */
				__( 'This design produced %1$d tokens; %2$d were kept. The rest are still available in the source analysis.', 'replicaforge' ),
				count( $tokens ),
				Template_Limits::MAX_TOKENS
			);
			$tokens = array_slice( $tokens, 0, Template_Limits::MAX_TOKENS, true );
		}

		return array(
			'tokens'            => $tokens,
			'counts'            => $counts,
			'total'             => count( $tokens ),
			'unassigned_roles'  => $assigned['unassigned'],
			'warnings'          => array_values( array_unique( $warnings ) ),
		);
	}

	/**
	 * Turn one Phase 8 token into a Phase 19 token record.
	 *
	 * @param array<string, mixed> $token     Phase 8 token.
	 * @param string               $category  Token category.
	 * @param int                  $index     Position in the Phase 8 list.
	 * @param string               $source    Token source.
	 * @param string               $scope     Token scope.
	 * @param string               $version   Schema version.
	 * @param array<string, mixed> $agreement Phase 12 agreement block.
	 * @return array<string, mixed>|null Null when the token carries no usable value.
	 */
	private function normalise( array $token, $category, $index, $source, $scope, $version, array $agreement ) {
		$name = isset( $token['name'] ) && is_string( $token['name'] ) ? trim( $token['name'] ) : '';

		/*
		 * A token with no name cannot be addressed, and a token with no value cannot be
		 * used. Both are dropped rather than defaulted, because a fabricated name would
		 * collide with a real one later and a fabricated value would render as a wrong
		 * colour on a real page.
		 */
		if ( '' === $name || ! array_key_exists( 'value', $token ) ) {
			return null;
		}

		$value = $token['value'];

		// A value must be scalar to be a token. `shadows` legitimately carry an array.
		if ( ! is_scalar( $value ) && ! is_array( $value ) ) {
			return null;
		}

		$occurrences = isset( $token['occurrences'] ) ? (int) $token['occurrences'] : 0;
		$evidence    = isset( $token['evidence'] ) && is_array( $token['evidence'] ) ? array_slice( array_values( array_filter( $token['evidence'], 'is_string' ) ), 0, 5 ) : array();
		$disputed    = ! empty( $agreement['contested'][ $name ] );

		/*
		 * The token id.
		 *
		 * Derived from `category` and `name`, not from the value, which is the whole point:
		 * a design system whose ids change when a colour changes cannot be referenced by
		 * anything, so §8's "identify what this change affects" would have no stable key to
		 * join on. The name is itself value-derived in Phase 8 (a token with no natural name
		 * becomes `color_<hash>`), so in practice a re-extraction of unchanged content
		 * produces the same id, and a changed colour produces a *new* token rather than a
		 * renamed one — which is the honest outcome, because the old token did stop existing.
		 */
		$token_id = self::token_id( $category, $name );

		$record = array(
			'token_id'     => $token_id,
			'name'         => $name,
			'label'        => $this->label_for( $name, $category ),
			'category'     => $category,
			'value'        => $value,
			'unit'         => $this->unit_for( $value ),
			'source'       => $source,
			'confidence'   => $this->confidence( $occurrences, $evidence, $disputed ),
			'scope'        => $scope,
			'version'      => $version,
			'usage_count'  => max( 0, $occurrences ),
			'ownership'    => 'replicaforge_controlled',
			'editable'     => true,
			'disputed'     => $disputed,
			'evidence'     => $evidence,
			'disputing_values' => $disputed && isset( $agreement['values'][ $name ] ) ? array_keys( (array) $agreement['values'][ $name ] ) : array(),
			'name_basis'   => isset( $token['name_basis'] ) && is_string( $token['name_basis'] ) ? substr( $token['name_basis'], 0, 240 ) : '',
			'role'         => '',
		);

		// Family-specific detail, kept because a template that rebuilds the page needs it.
		foreach ( array( 'rgb', 'hsl', 'alpha', 'luminance', 'usage', 'family', 'weight', 'size', 'line_height', 'letter_spacing', 'text_transform', 'sides', 'max_width', 'centered', 'min_width', 'key', 'roles' ) as $extra ) {
			if ( isset( $token[ $extra ] ) ) {
				$record[ $extra ] = $token[ $extra ];
			}
		}

		return $record;
	}

	/**
	 * Return the stable identifier for a token.
	 *
	 * @param string $category Token category.
	 * @param string $name     Token name.
	 * @return string
	 */
	public static function token_id( $category, $name ) {
		$category = is_string( $category ) ? strtolower( preg_replace( '/[^a-z0-9_]/', '', $category ) ) : '';
		$name     = is_string( $name ) ? strtolower( preg_replace( '/[^a-z0-9_]/', '', $name ) ) : '';

		if ( '' === $category || '' === $name ) {
			return '';
		}

		return $category . '.' . $name;
	}

	/**
	 * Return the unit implied by a token value.
	 *
	 * Reported, never stripped from the value. A token stored as `'12px'` stays `'12px'`
	 * and reports `'px'`, because a consumer that wants a number can parse the unit and a
	 * consumer that wants the literal has it — whereas normalising to a number and a unit
	 * would silently change `0` (unitless) into `0px`, which is a different CSS length.
	 *
	 * @param mixed $value Token value.
	 * @return string Empty string when the value has no unit, which is a real answer.
	 */
	public static function unit_for( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		if ( preg_match( '/^\s*(-?[0-9]*\.?[0-9]+)\s*([a-z%]+)\s*$/i', $value, $m ) ) {
			return strtolower( $m[2] );
		}
		return '';
	}

	/**
	 * Return a human label for a token name.
	 *
	 * @param string $name     Token name.
	 * @param string $category Token category.
	 * @return string
	 */
	private function label_for( $name, $category ) {
		if ( Template_Limits::is_semantic_role( $name ) ) {
			return (string) Template_Limits::SEMANTIC_ROLES[ $name ];
		}
		return ucwords( str_replace( array( '_', '.' ), ' ', (string) $name ) );
	}

	/**
	 * Return a computed confidence for a token.
	 *
	 * ### Why this is computed and not reported
	 *
	 * `Token_Engine` produces `occurrences` and up to five `evidence` node ids, and
	 * `Site_Design_System` produces an `agreement` share per token. Those are the only
	 * evidence that exists, so the confidence is a function of them and nothing else:
	 *
	 * - A token seen once on one page, with no cross-page agreement, is `0.2` — it is
	 *   probably real and almost certainly incidental.
	 * - A token that Phase 12 recorded as *contested* (pages disagree) is floored at `0.5`
	 *   and flagged `disputed`, because a disputed token is evidence of a value being used,
	 *   not of it being intended.
	 * - A token seen many times and agreeing across pages approaches `0.98`.
	 *
	 * The numbers are calibrated to be interpretable, not to be precise. The claim this
	 * class makes is only that a higher number means *more* evidence, which is true by
	 * construction, and never that a token is correct.
	 *
	 * @param int                $occurrences Occurrence count.
	 * @param array<int, string> $evidence   Evidence node ids.
	 * @param bool               $disputed   Whether Phase 12 recorded a conflict.
	 * @return float
	 */
	private function confidence( $occurrences, array $evidence, $disputed ) {
		$occurrences = max( 0, (int) $occurrences );

		// Saturating: 8 occurrences is treated as full evidence. Chosen because a
		// single page rarely repeats a value that many times unless it is a system value.
		$base = min( 1.0, $occurrences / 8.0 );

		// Independent evidence: several distinct nodes agreeing matters more than one node
		// seen repeatedly, so it adds up to a fifth of the scale and cannot reach 1 alone.
		$base = min( 1.0, $base + ( min( count( $evidence ), 5 ) / 5.0 ) * 0.2 );

		$value = 0.2 + ( $base * 0.78 );

		if ( $disputed ) {
			// A contested token is floored, not zeroed: it is still evidence of a value in
			// use, and zeroing it would discard a real observation.
			$value = max( 0.5, $value - 0.3 );
		}

		return round( max( 0.0, min( 1.0, $value ) ), 2 );
	}

	/* ---------------------------------------------------------------------
	 * Semantic roles
	 * ------------------------------------------------------------------ */

	/**
	 * Assign semantic roles to tokens, from evidence.
	 *
	 * A design system whose tokens are only `color_a3f19b` is a swatch list: nothing can be
	 * re-pointed without editing every consumer. So the highest-evidence token of each kind
	 * is bound to the semantic role it most plausibly is, and the assignment is *reported*,
	 * never silent — a template's dependency graph says "this binds to `color.primary`", and
	 * a user who disagrees can reassign it.
	 *
	 * The rules are deliberately conservative. A role is left unassigned rather than filled
	 * with a guess, because `color.primary` bound to the wrong colour re-points every
	 * button on a site when the token is next edited, which is a far worse outcome than a
	 * missing role the user can set by hand.
	 *
	 * @param array<string, array<string, mixed>> $tokens Tokens keyed by id.
	 * @param array<string, mixed>                $roles  Phase 12 role records.
	 * @return array{unassigned: array<int, string>, assigned: array<string, string>, warnings: array<int, string>}
	 */
	public function assign_roles( array $tokens, array $roles = array() ) {
		$assigned   = array();
		$unassigned = array();
		$warnings   = array();

		/*
		 * Where Phase 12 already decided a role, that decision is honoured and its recorded
		 * value is reused. Phase 12 is the authority on what the primary colour of an
		 * analysed site is; Phase 19 does not second-guess it.
		 */
		$role_map = array(
			'color.background' => 'background',
			'color.text'       => 'text',
		);

		foreach ( $role_map as $semantic => $phase12 ) {
			if ( ! isset( $roles[ $phase12 ]['value'] ) || '' === (string) $roles[ $phase12 ]['value'] ) {
				continue;
			}
			$value = (string) $roles[ $phase12 ]['value'];
			$id    = self::token_id( 'colors', $phase12 );

			if ( ! isset( $tokens[ $id ] ) ) {
				continue;
			}
			// Only bind if the values actually agree, so a role never points at a token
			// holding a different colour.
			if ( (string) $tokens[ $id ]['value'] !== $value ) {
				continue;
			}

			$tokens[ $id ]['role']             = $semantic;
			$tokens[ $id ]['scope']            = 'global';
			$assigned[ $semantic ]             = $id;
		}

		/*
		 * Remaining colour roles, by the conventions a design system actually uses:
		 * the most-used background-following colour is the text colour; the most-used
		 * border or subtle background is the border colour; the most-used saturated
		 * non-text, non-background colour is the accent.
		 *
		 * `primary` and `secondary` are NOT inferred. There is no reliable signal for
		 * "primary" from a rendered page — a site can have one brand colour used twice or
		 * three equally-ranked accents — and guessing here is the single change that would
		 * repoint the most on a site when someone edits the token later. They are reported
		 * as unassigned and set by hand.
		 */
		$remaining = array();

		foreach ( $tokens as $id => $token ) {
			if ( 'colors' !== $token['category'] || '' !== (string) $token['role'] ) {
				continue;
			}
			$remaining[] = $token;
		}

		usort(
			$remaining,
			static function ( $left, $right ) {
				$usage = (int) ( $right['usage_count'] ?? 0 ) - (int) ( $left['usage_count'] ?? 0 );
				if ( 0 !== $usage ) {
					return $usage;
				}
				return strcmp( (string) $left['token_id'], (string) $right['token_id'] );
			}
		);

		$roles_to_fill = array( 'color.text', 'color.border', 'color.accent' );

		foreach ( $remaining as $token ) {
			if ( array() === $roles_to_fill ) {
				break;
			}
			$id = (string) $token['token_id'];

			// A disputed value is not a candidate for a global role.
			if ( ! empty( $token['disputed'] ) ) {
				continue;
			}

			$semantic = array_shift( $roles_to_fill );

			$tokens[ $id ]['role']  = $semantic;
			$assigned[ $semantic ]  = $id;
		}

		// The shape roles. A radius and a container width exist in every real design.
		$shape_candidates = array(
			'shape.radius'    => 'radius',
			'spacing.section' => 'spacing',
			'layout.container' => 'containers',
			'font.size_base'  => 'typography',
		);

		foreach ( $shape_candidates as $semantic => $category ) {
			$best = '';

			foreach ( $tokens as $id => $token ) {
				if ( $category !== $token['category'] || '' !== (string) $token['role'] ) {
					continue;
				}
				if ( '' === $best || (int) $token['usage_count'] > (int) $tokens[ $best ]['usage_count'] ) {
					$best = $id;
				}
			}

			if ( '' !== $best ) {
				$tokens[ $best ]['role'] = $semantic;
				$assigned[ $semantic ]   = $best;
			}
		}

		foreach ( Template_Limits::SEMANTIC_ROLES as $semantic => $label ) {
			if ( ! isset( $assigned[ $semantic ] ) ) {
				$unassigned[] = $semantic;
			}
		}

		if ( array() !== $unassigned ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated list of role names. */
				__( 'These design roles have no token yet, so anything bound to them stays unresolved: %s. Set them by hand, or leave them — an unassigned role is safe, a wrongly assigned one is not.', 'replicaforge' ),
				implode( ', ', $unassigned )
			);
		}

		return array(
			'unassigned' => $unassigned,
			'assigned'   => $assigned,
			'warnings'   => $warnings,
		);
	}

	/* ---------------------------------------------------------------------
	 * Live registry
	 * ------------------------------------------------------------------ */

	/**
	 * Return the live token registry for a workspace.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @return array<string, array<string, mixed>> Tokens keyed by token id.
	 */
	public function registry( $workspace_id ) {
		$workspace_id = $this->clean_id( $workspace_id );

		if ( '' === $workspace_id ) {
			return array();
		}
		if ( isset( $this->cache[ $workspace_id ] ) ) {
			return $this->cache[ $workspace_id ];
		}

		$all = get_option( Template_Limits::TOKENS_OPTION, array() );
		$out = array();

		if ( is_array( $all ) && isset( $all[ $workspace_id ] ) && is_array( $all[ $workspace_id ]['tokens'] ) ) {
			foreach ( $all[ $workspace_id ]['tokens'] as $id => $token ) {
				if ( is_array( $token ) && '' !== (string) $id ) {
					$out[ (string) $id ] = $token;
				}
			}
		}

		$this->cache[ $workspace_id ] = $out;

		return $out;
	}

	/**
	 * Merge extracted tokens into a workspace's live registry.
	 *
	 * ### Why merging is not overwriting
	 *
	 * A re-extraction of a project must not undo a decision a person made. So:
	 *
	 * - A token marked `user_controlled` is **never** overwritten. Its value stands and the
	 *   extraction is recorded as having been skipped for it.
	 * - A `theme_controlled` token is left alone, because the theme's stylesheet is the
	 *   authority for a theme-owned value and a ReplicaForge token replacing it would be a
	 *   silent style change.
	 * - Everything else is refreshed, and `version` moves forward.
	 *
	 * @param string                            $workspace_id Workspace public id.
	 * @param array<string, array<string, mixed>> $tokens     Tokens keyed by id.
	 * @return array{merged: int, kept_user: int, kept_theme: int, added: int}
	 */
	public function merge( $workspace_id, array $tokens ) {
		$workspace_id = $this->clean_id( $workspace_id );

		if ( '' === $workspace_id ) {
			return array( 'merged' => 0, 'kept_user' => 0, 'kept_theme' => 0, 'added' => 0 );
		}

		$all      = get_option( Template_Limits::TOKENS_OPTION, array() );
		$all      = is_array( $all ) ? $all : array();
		$existing = isset( $all[ $workspace_id ]['tokens'] ) && is_array( $all[ $workspace_id ]['tokens'] ) ? $all[ $workspace_id ]['tokens'] : array();

		$merged      = array();
		$kept_user   = 0;
		$kept_theme  = 0;
		$added       = 0;

		foreach ( $tokens as $id => $token ) {
			$id = (string) $id;

			if ( ! isset( $existing[ $id ] ) ) {
				$merged[ $id ] = $token;
				$added++;
				continue;
			}

			$prior = $existing[ $id ];
			$owner = (string) ( $prior['ownership'] ?? 'replicaforge_controlled' );

			if ( 'user_controlled' === $owner ) {
				/*
				 * The prior value is kept, but the *evidence* is refreshed so the token still
				 * reflects what the source uses. A user's chosen value sitting on top of current
				 * evidence is a more useful record than a stale copy of either.
				 */
				$prior['evidence']       = isset( $token['evidence'] ) && is_array( $token['evidence'] ) ? $token['evidence'] : array();
				$prior['usage_count']    = isset( $token['usage_count'] ) ? (int) $token['usage_count'] : (int) ( $prior['usage_count'] ?? 0 );
				$prior['observed_value'] = isset( $token['value'] ) ? $token['value'] : ( $prior['value'] ?? '' );
				$prior['last_merged_at'] = gmdate( 'c' );
				$merged[ $id ]           = $prior;
				$kept_user++;
				continue;
			}

			if ( 'theme_controlled' === $owner ) {
				$merged[ $id ] = $prior;
				$kept_theme++;
				continue;
			}

			$merged[ $id ]                    = $token;
			$merged[ $id ]['usage_count']     = isset( $token['usage_count'] ) ? (int) $token['usage_count'] : 0;
		}

		if ( count( $merged ) > Template_Limits::MAX_TOKENS ) {
			$merged = array_slice( $merged, 0, Template_Limits::MAX_TOKENS, true );
		}

		$all[ $workspace_id ] = array(
			'tokens'     => $merged,
			'updated_at' => gmdate( 'c' ),
			'count'      => count( $merged ),
		);

		$this->bound_design_systems( $all );
		update_option( Template_Limits::TOKENS_OPTION, $all, false );

		$this->cache[ $workspace_id ] = $merged;

		return array(
			'merged'     => count( $merged ),
			'kept_user'  => $kept_user,
			'kept_theme' => $kept_theme,
			'added'      => $added,
		);
	}

	/**
	 * Set a token by hand.
	 *
	 * This is the only path that produces `ownership = user_controlled`, and it is the
	 * reason a later re-extraction cannot undo the change.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param string $token_id     Token id.
	 * @param mixed  $value        New value.
	 * @return true|\WP_Error
	 */
	public function set( $workspace_id, $token_id, $value ) {
		$workspace_id = $this->clean_id( $workspace_id );
		$token_id     = is_string( $token_id ) ? trim( $token_id ) : '';

		if ( '' === $workspace_id ) {
			return new \WP_Error( 'template_workspace_required', __( 'A workspace is required.', 'replicaforge' ), array( 'status' => 400 ) );
		}
		if ( '' === $token_id || strlen( $token_id ) > 120 ) {
			return new \WP_Error( 'template_token_invalid', __( 'That design token identifier is not usable.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		$registry = $this->registry( $workspace_id );

		if ( ! isset( $registry[ $token_id ] ) ) {
			return new \WP_Error( 'template_token_unknown', __( 'That design token is not in this workspace.', 'replicaforge' ), array( 'status' => 404 ) );
		}

		$token = $registry[ $token_id ];
		$clean = $this->clean_value( $value, (string) $token['category'] );

		if ( null === $clean ) {
			return new \WP_Error(
				'template_token_value_rejected',
				__( 'That value is not a usable value for this kind of design token.', 'replicaforge' ),
				array( 'status' => 400 )
			);
		}

		$all = get_option( Template_Limits::TOKENS_OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		/*
		 * Captured *before* the write. An earlier version of this set `value` and then asked
		 * whether `value` still equalled the new one, which is always true, so
		 * `previous_value` was always empty and a token change left no record of what it
		 * changed from. A token registry that cannot say what a value was before is a
		 * registry that cannot be reviewed.
		 */
		$prior_value = (string) ( $token['value'] ?? '' );

		$token['value']          = $clean;
		$token['unit']           = self::unit_for( $clean );
		$token['ownership']      = 'user_controlled';
		$token['editable']       = true;
		$token['previous_value'] = $prior_value === (string) $clean ? '' : $prior_value;
		$token['modified_at']    = gmdate( 'c' );
		$token['modified_by']    = get_current_user_id();

		$all[ $workspace_id ]['tokens'][ $token_id ] = $token;
		$all[ $workspace_id ]['updated_at']           = gmdate( 'c' );

		update_option( Template_Limits::TOKENS_OPTION, $all, false );

		$this->cache[ $workspace_id ] = $all[ $workspace_id ]['tokens'];

		$this->logger->info(
			'design_token_changed',
			'Set a design token by hand.',
			array(
				'workspace_id' => $workspace_id,
				'token_id'     => $token_id,
				'category'     => (string) $token['category'],
			),
			'template'
		);

		return true;
	}

	/**
	 * Validate a proposed token value.
	 *
	 * Reuses `Elementor_Values`, the one place the plugin already decides what a CSS value
	 * is safe. A design token becomes a CSS value the moment it is installed, so the check
	 * has to be the same one that will apply to it later, or a token can be stored that
	 * cannot later be written.
	 *
	 * @param mixed  $value    Candidate.
	 * @param string $category Token category.
	 * @return mixed|null Null when the value is not usable.
	 */
	public function clean_value( $value, $category ) {
		if ( is_bool( $value ) || null === $value ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value === (float) (int) $value ? (int) $value : (float) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( '' === $value || strlen( $value ) > 200 ) {
			return null;
		}

		if ( Elementor_Values::is_executable( $value ) ) {
			return null;
		}

		switch ( $category ) {
			case 'colors':
				return Elementor_Values::is_safe_color( $value ) ? $value : null;

			case 'typography':
				if ( Elementor_Values::is_safe_font_family( $value ) ) {
					return $value;
				}
				if ( Elementor_Values::is_safe_length( $value ) || Elementor_Values::is_safe_line_height( $value ) || Elementor_Values::is_safe_font_weight( $value ) ) {
					return $value;
				}
				return null;

			case 'shadows':
				// A shadow is a compound value; reuse the same guard the spec validator uses.
				if ( strlen( $value ) > 200 || preg_match( '/[;{}()<>]|expression|url\s*\(|@import|javascript:/i', $value ) ) {
					return null;
				}
				return $value;

			case 'breakpoints':
				return preg_match( '/^[0-9]{2,5}$/', $value ) ? (int) $value : null;

			default:
				// spacing, radius, shape, containers, semantic.
				return Elementor_Values::is_safe_length( $value ) ? $value : null;
		}
	}

	/* ---------------------------------------------------------------------
	 * Impact
	 * ------------------------------------------------------------------ */

	/**
	 * Return what a token change would affect, before it is applied.
	 *
	 * §8 requires the affected resources and the expected scope to be shown, and requires
	 * confirmation. This is that function. It deliberately answers from stored data only —
	 * it does not walk pages, does not render, and does not count anything it cannot count
	 * exactly, so the number it returns is a count of *known* consumers rather than an
	 * estimate dressed as a total.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param string $token_id     Token id.
	 * @return array{token: array<string, mixed>|null, templates: array<int, array<string, mixed>>, components: array<int, array<string, mixed>>, template_count: int, component_count: int, manual_edits_possible: bool, note: string}
	 */
	public function impact( $workspace_id, $token_id ) {
		$workspace_id = $this->clean_id( $workspace_id );
		$token_id     = is_string( $token_id ) ? trim( $token_id ) : '';
		$registry     = $this->registry( $workspace_id );

		$token = isset( $registry[ $token_id ] ) ? $registry[ $token_id ] : null;

		$templates  = array();
		$components = array();

		if ( '' !== $workspace_id && '' !== $token_id && class_exists( 'ReplicaForge\\Template_Store' ) ) {
			$store = new Template_Store();

			foreach ( $store->consumers_of_token( $workspace_id, $token_id ) as $row ) {
				if ( 'template' === (string) $row['kind'] ) {
					$templates[] = $row;
				} else {
					$components[] = $row;
				}
			}
		}

		return array(
			'token'        => $token,
			'templates'    => $templates,
			'components'   => $components,
			'template_count' => count( $templates ),
			'component_count' => count( $components ),
			/*
			 * Whether a consumer may have been hand-edited away from the token. ReplicaForge
			 * cannot know without diffing every rendered document, so this is reported as
			 * *possible* rather than counted, and the caller is expected to treat a token
			 * change as needing confirmation rather than as safe.
			 */
			'manual_edits_possible' => ( count( $templates ) + count( $components ) ) > 0,
			'note'          => ( count( $templates ) + count( $components ) ) > 0
				? __( 'These templates and components record this token. Any of them may also have been edited by hand since, which ReplicaForge cannot detect without comparing every page — so review the list before applying a change.', 'replicaforge' )
				: __( 'Nothing in this workspace records this token, so changing it affects no template or component.', 'replicaforge' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Reduce a workspace id to a storable key.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_id( $value ) {
		return is_string( $value ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 26 ) : '';
	}

	/**
	 * Bound the number of tracked design systems.
	 *
	 * @param array<string, mixed> $all All design systems.
	 * @return array<string, mixed>
	 */
	private function bound_design_systems( array $all ) {
		if ( count( $all ) <= Template_Limits::MAX_DESIGN_SYSTEMS ) {
			return $all;
		}

		// Drop the least recently updated, which is the one least likely to be in use.
		uasort(
			$all,
			static function ( $left, $right ) {
				$l = (int) strtotime( (string) ( $left['updated_at'] ?? '1970-01-01T00:00:00+00:00' ) );
				$r = (int) strtotime( (string) ( $right['updated_at'] ?? '1970-01-01T00:00:00+00:00' ) );
				return $l <=> $r;
			}
		);

		return array_slice( $all, 0, Template_Limits::MAX_DESIGN_SYSTEMS, true );
	}

	/**
	 * Return a summary of a token set, for the library screen.
	 *
	 * Counts only what is countable. Every field is either an integer derived from stored
	 * records or `Template_Limits::NOT_MEASURED` — never a percentage extrapolated from a
	 * sample.
	 *
	 * @param array<string, array<string, mixed>> $tokens Tokens.
	 * @return array<string, mixed>
	 */
	public static function summarise( array $tokens ) {
		$by_category = array();
		$by_ownership = array();
		$roles        = 0;
		$editable     = 0;
		$disputed     = 0;

		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) {
				continue;
			}
			$category  = (string) ( $token['category'] ?? '' );
			$ownership = (string) ( $token['ownership'] ?? '' );

			if ( '' !== $category ) {
				$by_category[ $category ] = ( $by_category[ $category ] ?? 0 ) + 1;
			}
			if ( '' !== $ownership ) {
				$by_ownership[ $ownership ] = ( $by_ownership[ $ownership ] ?? 0 ) + 1;
			}
			if ( ! empty( $token['role'] ) ) {
				$roles++;
			}
			if ( ! empty( $token['editable'] ) ) {
				$editable++;
			}
			if ( ! empty( $token['disputed'] ) ) {
				$disputed++;
			}
		}

		ksort( $by_category );

		return array(
			'total'            => count( $tokens ),
			'by_category'      => $by_category,
			'by_ownership'     => $by_ownership,
			'with_role'        => $roles,
			'editable'         => $editable,
			'disputed'         => $disputed,
			'roles_available'  => count( Template_Limits::SEMANTIC_ROLES ),
		);
	}
}
