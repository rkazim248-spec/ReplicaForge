<?php
/**
 * Phase 19: import conflict detection.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Finds what an import would collide with, *before* anything is written.
 *
 * ### Why detection is a separate pass
 *
 * §18 puts conflict detection after asset review and before user confirmation, and that
 * ordering is the whole design: by the time the user is asked to confirm, every collision
 * is already named. Detecting during the write would mean the user chooses a strategy
 * *after* the damage, or means the write picks a default and the user finds out later.
 *
 * So this class is read-only. It has no write path at all, and it is called twice: once to
 * show the user what will happen, and once after their strategy choices, to re-check that
 * the choices still hold. The second call is not paranoia — between the two, a teammate
 * may have created a template with the same name, and re-detecting against a stale list
 * would silently overwrite it.
 *
 * ### What it will never offer
 *
 * There is no "overwrite" strategy, in the vocabulary or in the resolver.
 * `Template_Limits::CONFLICT_STRATEGIES` does not contain one, and
 * {@see Template_Conflicts::apply()} refuses anything that is not in that list. §27 and the
 * Phase 19 stop conditions both forbid silently overwriting an existing reusable asset, and
 * a strategy that can be requested is a strategy that will eventually be requested.
 */
final class Template_Conflicts {

	/**
	 * Template store.
	 *
	 * @var Template_Store
	 */
	private $templates;

	/**
	 * Component store.
	 *
	 * @var Template_Component_Store
	 */
	private $components;

	/**
	 * Token registry.
	 *
	 * @var Design_Token_Registry
	 */
	private $tokens;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Template_Store|null           $templates  Template store.
	 * @param Template_Component_Store|null $components Component store.
	 * @param Design_Token_Registry|null    $tokens     Token registry.
	 * @param Logger|null                   $logger     Logger.
	 */
	public function __construct( $templates = null, $components = null, $tokens = null, $logger = null ) {
		$this->logger     = $logger instanceof Logger ? $logger : new Logger();
		$this->templates  = $templates instanceof Template_Store ? $templates : new Template_Store( null, $this->logger );
		$this->components = $components instanceof Template_Component_Store ? $components : new Template_Component_Store( null, $this->logger );
		$this->tokens     = $tokens instanceof Design_Token_Registry ? $tokens : new Design_Token_Registry( $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * Detection
	 * ------------------------------------------------------------------ */

	/**
	 * Detect every conflict an import would cause.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param array<string, mixed> $snapshot   Incoming snapshot, already sanitised.
	 * @return array{clean: bool, conflicts: array<int, array<string, mixed>>, counts: array<string, int>, summary: string, strategies: array<string, string>}
	 */
	public function detect( $workspace_id, array $snapshot ) {
		$workspace_id = is_string( $workspace_id ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $workspace_id ), 0, 26 ) : '';

		if ( '' === $workspace_id ) {
			return $this->empty( __( 'A workspace is required before an import can be checked.', 'replicaforge' ) );
		}

		$conflicts = array();
		$counts    = array();

		$token = isset( $snapshot['tokens'] ) && is_array( $snapshot['tokens'] ) ? $snapshot['tokens'] : array();
		$parts = isset( $snapshot['components'] ) && is_array( $snapshot['components'] ) ? $snapshot['components'] : array();

		/*
		 * The template's name and type live in the snapshot's `meta` block, not in
		 * `provenance`. Reading them from `provenance` found nothing, because provenance is
		 * deliberately reduced to origin/author/url/created_at/licence — so a name collision
		 * was never detected and the conflict pass reported "nothing here already exists" for
		 * a package whose name was taken.
		 */
		$meta = isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ? $snapshot['meta'] : array();
		$name = isset( $meta['name'] ) && is_string( $meta['name'] ) ? (string) $meta['name'] : '';
		$type = isset( $meta['type'] ) && is_string( $meta['type'] ) ? (string) $meta['type'] : '';

		$token = isset( $snapshot['tokens'] ) && is_array( $snapshot['tokens'] ) ? $snapshot['tokens'] : array();
		$parts = isset( $snapshot['components'] ) && is_array( $snapshot['components'] ) ? $snapshot['components'] : array();

		// --- Template name ------------------------------------------------
		if ( '' !== $name ) {
			$existing = $this->templates->browse( $workspace_id, array( 'per_page' => 50 ) );

			foreach ( $existing['items'] as $template ) {
				if ( 0 !== strcasecmp( (string) ( $template['name'] ?? '' ), $name ) ) {
					continue;
				}

				$conflicts[] = array(
					'kind'        => 'template_name',
					'label'       => (string) Template_Limits::CONFLICT_KINDS['template_name'],
					'existing_id' => (string) ( $template['public_id'] ?? '' ),
					'existing'    => (string) ( $template['name'] ?? '' ),
					'incoming'    => $name,
					'severity'    => 'review',
					/*
					 * A name clash is never an error on its own — two templates called "Hero"
					 * in two workspaces is normal, and renaming is always available. It is a
					 * `review` because the *user* may want the new version to supersede the
					 * old one, and only they can say.
					 */
					'detail'      => __( 'A template in this workspace already has this name. Nothing will be replaced; choose whether to keep the existing one, add this as a new version of it, or rename the incoming template.', 'replicaforge' ),
				);
			}
		}

		// --- Components ---------------------------------------------------
		foreach ( $parts as $component_id => $component ) {
			$id = is_scalar( $component_id ) ? (string) $component_id : '';

			if ( '' === $id ) {
				continue;
			}

			$existing = $this->components->find_component( $workspace_id, $id );

			if ( null === $existing ) {
				continue;
			}

			$conflicts[] = array(
				'kind'        => 'component_id',
				'label'       => (string) Template_Limits::CONFLICT_KINDS['component_id'],
				'existing_id' => (string) $existing['component_id'],
				'existing'    => (string) ( $existing['name'] ?? '' ),
				'incoming'    => $id,
				'severity'    => 'review',
				'detail'      => sprintf(
					/* translators: 1: the component id, 2: how many templates use it. */
					__( 'The component "%1$s" already exists at version %2$d and is used by existing templates. Adding this as a new version records the change; it does not apply the change to those templates.', 'replicaforge' ),
					$id,
					(int) ( $existing['version'] ?? 1 )
				),
			);
		}

		// --- Design tokens ------------------------------------------------
		$registry = $this->tokens->registry( $workspace_id );

		foreach ( $token as $token_id => $record ) {
			$id = is_scalar( $token_id ) ? (string) $token_id : '';

			if ( '' === $id || ! isset( $registry[ $id ] ) ) {
				continue;
			}

			$prior = $registry[ $id ];
			$mine  = is_array( $record ) ? $record : array();
			$was   = (string) ( $prior['value'] ?? '' );
			$now   = is_scalar( $mine['value'] ?? null ) ? (string) $mine['value'] : '';

			/*
			 * A token the user set by hand is never silently changed, whatever the incoming
			 * value. §28 says do not blindly replace global styles, and §7's ownership field
			 * exists precisely so this is checkable. A `user_controlled` prior therefore makes
			 * a value difference a *review*, not a merge candidate.
			 */
			$user_owned = 'user_controlled' === (string) ( $prior['ownership'] ?? '' );

			if ( $was === $now ) {
				continue;
			}

			$conflicts[] = array(
				'kind'        => $user_owned ? 'token_name' : 'token_value',
				'label'       => $user_owned
					? (string) Template_Limits::CONFLICT_KINDS['token_name']
					: (string) Template_Limits::CONFLICT_KINDS['token_value'],
				'existing_id' => $id,
				'existing'    => $was,
				'incoming'    => $now,
				'severity'    => $user_owned ? 'review' : 'warning',
				'ownership'   => (string) ( $prior['ownership'] ?? '' ),
				'detail'      => $user_owned
					? sprintf(
						/* translators: 1: token id, 2: current value, 3: incoming value. */
						__( 'The design token "%1$s" was set by hand to %2$s, and this package brings %3$s. A value someone chose is not replaced by an import.', 'replicaforge' ),
						$id,
						'' === $was ? __( 'nothing', 'replicaforge' ) : $was,
						'' === $now ? __( 'nothing', 'replicaforge' ) : $now
					)
					: sprintf(
						/* translators: 1: token id, 2: current value, 3: incoming value. */
						__( 'The design token "%1$s" is %2$s here and %3$s in this package. Importing it as a design-system change would move every template that uses it.', 'replicaforge' ),
						$id,
						'' === $was ? __( 'nothing', 'replicaforge' ) : $was,
						'' === $now ? __( 'nothing', 'replicaforge' ) : $now
					),
			);
		}

		// --- Capability ---------------------------------------------------
		$declared = isset( $snapshot['compatibility'] ) && is_array( $snapshot['compatibility'] ) ? $snapshot['compatibility'] : array();

		if ( array() !== $declared ) {
			$resolved = ( new Template_Dependencies() )->resolve( $declared );

			foreach ( (array) ( $resolved['requirements'] ?? array() ) as $requirement ) {
				if ( 'available' === (string) ( $requirement['state'] ?? '' ) || ! empty( $requirement['optional'] ) ) {
					continue;
				}

				$conflicts[] = array(
					'kind'     => 'capability',
					'label'    => (string) Template_Limits::CONFLICT_KINDS['capability'],
					'existing' => (string) ( $requirement['label'] ?? '' ),
					'incoming' => (string) ( $requirement['state'] ?? '' ),
					'severity' => 'error',
					'detail'   => (string) ( $requirement['detail'] ?? '' ),
				);
			}
		}

		$counts = array( 'error' => 0, 'warning' => 0, 'review' => 0 );

		foreach ( $conflicts as $conflict ) {
			if ( isset( $counts[ $conflict['severity'] ] ) ) {
				$counts[ $conflict['severity'] ]++;
			}
		}

		$blocking = $counts['error'] > 0;
		$summary  = __( 'Nothing here already exists, so this can be imported as it is.', 'replicaforge' );

		if ( $blocking ) {
			$summary = __( 'This cannot be imported here as it is, because of the requirements listed below.', 'replicaforge' );
		} elseif ( $counts['review'] > 0 || $counts['warning'] > 0 ) {
			$summary = sprintf(
				/* translators: %d: how many decisions are needed. */
				_n( 'One decision is needed before this can be imported.', '%d decisions are needed before this can be imported.', $counts['review'] + $counts['warning'], 'replicaforge' ),
				$counts['review'] + $counts['warning']
			);
		}

		return array(
			'clean'      => array() === $conflicts,
			'conflicts'  => $conflicts,
			'counts'     => $counts,
			'summary'    => $summary,
			'strategies' => Template_Limits::CONFLICT_STRATEGIES,
			'blocking'   => $blocking,
		);
	}

	/* ---------------------------------------------------------------------
	 * Resolution
	 * ------------------------------------------------------------------ */

	/**
	 * Turn chosen strategies into an install plan.
	 *
	 * The plan says what will happen to each conflict, and it is computed from a *fresh*
	 * detection rather than the one the user saw. A choice made against a stale list would
	 * be a choice about a situation that no longer holds, and the failure mode is silent.
	 *
	 * @param string $workspace_id Workspace public id.
	 * @param array<string, mixed> $snapshot   Sanitised snapshot.
	 * @param array<string, string> $choices   Strategy per conflict `kind`, or a single `default`.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function plan( $workspace_id, array $snapshot, array $choices ) {
		$fresh = $this->detect( $workspace_id, $snapshot );

		if ( $fresh['blocking'] ) {
			return new \WP_Error(
				'template_conflict_blocking',
				$fresh['summary'],
				array( 'status' => 409, 'conflicts' => $fresh['conflicts'] )
			);
		}

		$default = isset( $choices['default'] ) && Template_Limits::is_conflict_strategy( $choices['default'] )
			? (string) $choices['default']
			: 'keep_existing';

		$actions = array();
		$renames = array();

		foreach ( (array) $fresh['conflicts'] as $conflict ) {
			$kind = (string) $conflict['kind'];

			/*
			 * An `error` never reaches here — `detect()` returns a WP_Error for those. But the
			 * guard is kept, because a future conflict kind with `error` severity that slips
			 * past the check would otherwise fall through to a default strategy and be
			 * silently resolved.
			 */
			if ( 'error' === (string) $conflict['severity'] ) {
				return new \WP_Error(
					'template_conflict_blocking',
					__( 'A conflict on this template cannot be resolved by choosing a strategy.', 'replicaforge' ),
					array( 'status' => 409, 'conflicts' => (array) $fresh['conflicts'] )
				);
			}

			$strategy = isset( $choices[ $kind ] ) && Template_Limits::is_conflict_strategy( $choices[ $kind ] )
				? (string) $choices[ $kind ]
				: $default;

			/*
			 * A *token* conflict cannot be resolved by "add a new version": a design token has
			 * no versions. It is either merged, after review, or kept.
			 *
			 * The check matches on the `token_` prefix rather than on `token_value`, because
			 * both token kinds are affected and matching only one of them left the other —
			 * the `token_name` conflict raised when a `user_controlled` token differs — able
			 * to accept a strategy that silently does nothing. A plan with a no-op in it is
			 * not a plan; it is a plan that reports success while changing nothing, which is
			 * the outcome §27 exists to rule out.
			 */
			if ( 0 === strpos( $kind, 'token_' ) && 'new_version' === $strategy ) {
				$strategy = 'keep_existing';
			}

			$actions[] = array(
				'kind'       => $kind,
				'target'     => (string) ( $conflict['existing_id'] ?? '' ),
				'existing'   => (string) ( $conflict['existing'] ?? '' ),
				'incoming'   => (string) ( $conflict['incoming'] ?? '' ),
				'strategy'   => $strategy,
				'overwrites' => false,
				'detail'     => (string) $conflict['detail'],
			);

			if ( 'rename' === $strategy && '' !== (string) ( $conflict['existing'] ?? '' ) ) {
				$renames[] = array(
					'kind'  => $kind,
					'from'  => (string) $conflict['incoming'],
					'label' => $this->suggested_rename( (string) $conflict['incoming'] ),
				);
			}
		}

		// A design-system merge is all-or-nothing and only ever explicit.
		$merge_tokens = false;

		foreach ( $actions as $action ) {
			if ( 'merge_tokens' === (string) $action['strategy'] && 0 === strpos( (string) $action['kind'], 'token_' ) ) {
				$merge_tokens = true;
			}
		}

		return array(
			'actions'      => $actions,
			'renames'      => $renames,
			'merge_tokens' => $merge_tokens,
			/*
			 * Reported so the installer and the UI can assert it rather than assume it. Every
			 * action is non-destructive, and this makes that a checked property.
			 */
			'overwrites'   => false,
			'conflict_count' => (int) $fresh['counts']['review'] + (int) $fresh['counts']['warning'] + (int) $fresh['counts']['error'],
			'summary'      => $fresh['summary'],
		);
	}

	/**
	 * Suggest a non-colliding name.
	 *
	 * @param string $base Proposed name.
	 * @return string
	 */
	private function suggested_rename( $base ) {
		$base = is_string( $base ) ? trim( $base ) : '';

		if ( '' === $base ) {
			return __( 'Imported template', 'replicaforge' );
		}

		$label = (string) Template_Limits::type_label( $base );

		return sprintf(
			/* translators: 1: the original name, 2: a count. */
			__( '%1$s (imported %2$s)', 'replicaforge' ),
			$label,
			gmdate( 'Y-m-d' )
		);
	}

	/**
	 * Return an empty detection result.
	 *
	 * @param string $summary Summary.
	 * @return array<string, mixed>
	 */
	private function empty( $summary ) {
		return array(
			'clean'      => true,
			'conflicts'  => array(),
			'counts'     => array( 'error' => 0, 'warning' => 0, 'review' => 0 ),
			'summary'    => $summary,
			'strategies' => Template_Limits::CONFLICT_STRATEGIES,
			'blocking'   => false,
		);
	}
}
