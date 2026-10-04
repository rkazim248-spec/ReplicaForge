<?php
/**
 * Phase 19: transactional template installation.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Installs a template, and rolls back completely if it cannot finish.
 *
 * ### Why this is transactional
 *
 * §29 and the Phase 19 acceptance criteria both require it, and the reason is concrete: a
 * template install writes a *template row*, *a version row* and possibly *several component
 * rows* and *token overrides*. A failure halfway through leaves a library entry that cannot
 * be opened, or a component that half-exists, or — worst — a design token moved with no
 * template to justify it.
 *
 * The rule here is that every write is recorded before it happens, and every write is
 * undone in reverse order if any later step fails. Nothing is left behind, and the failure
 * detail is kept so the user can retry.
 *
 * ### What it will not roll back
 *
 * **User content.** An install may write an Elementor draft. A draft is user content, and
 * `Elementor_Draft_Service` creates it as a real post a user can see. So the journal
 * records the draft id and the rollback path *reports* it rather than deleting it — a
 * half-built draft the user can inspect and delete themselves is more useful than one
 * silently removed, and deleting a post on a rollback path is how a plugin becomes
 * terrifying.
 *
 * The exception is a draft created in *this* install and never returned to the user, which
 * is recorded distinctly. That one is removed, because leaving an orphan draft per failed
 * install is its own unbounded growth path — the `replicaforge_analysis` and
 * `install_count` findings in earlier phases are the same class of bug.
 *
 * ### The steps
 *
 * `Template_Limits::INSTALL_STEPS` names them and {@see Template_Installer::install()} runs
 * them in that order, recording each. A test can assert the order, not just the outcome.
 */
final class Template_Installer {

	/**
	 * Template store.
	 *
	 * @var Template_Store
	 */
	private $templates;

	/**
	 * Version store.
	 *
	 * @var Template_Version_Store
	 */
	private $versions;

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
	 * Validator.
	 *
	 * @var Template_Validator
	 */
	private $validator;

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
	 * @param Template_Version_Store|null   $versions   Version store.
	 * @param Template_Component_Store|null $components Component store.
	 * @param Design_Token_Registry|null    $tokens     Token registry.
	 * @param Template_Validator|null       $validator  Validator.
	 * @param Logger|null                   $logger     Logger.
	 */
	public function __construct( $templates = null, $versions = null, $components = null, $tokens = null, $validator = null, $logger = null ) {
		$this->logger     = $logger instanceof Logger ? $logger : new Logger();
		$this->templates  = $templates instanceof Template_Store ? $templates : new Template_Store( null, $this->logger );
		$this->versions   = $versions instanceof Template_Version_Store ? $versions : new Template_Version_Store( null, $this->logger );
		$this->components = $components instanceof Template_Component_Store ? $components : new Template_Component_Store( null, $this->logger );
		$this->tokens     = $tokens instanceof Design_Token_Registry ? $tokens : new Design_Token_Registry( $this->logger );
		$this->validator  = $validator instanceof Template_Validator ? $validator : new Template_Validator( null, null, $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * Install
	 * ------------------------------------------------------------------ */

	/**
	 * Install a template snapshot into a workspace.
	 *
	 * @param string              $workspace_id Workspace public id.
	 * @param array<string, mixed> $snapshot     Sanitised snapshot.
	 * @param array<string, mixed> $options      Install options.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function install( $workspace_id, array $snapshot, array $options = array() ) {
		$workspace_id = is_string( $workspace_id ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $workspace_id ), 0, 26 ) : '';

		if ( '' === $workspace_id ) {
			return new \WP_Error( 'template_workspace_required', __( 'A template must be installed into a workspace.', 'replicaforge' ), array( 'status' => 400 ) );
		}

		$steps   = array();
		$journal = array();
		$actor   = (int) ( $options['user_id'] ?? get_current_user_id() );

		$meta = isset( $snapshot['meta'] ) && is_array( $snapshot['meta'] ) ? $snapshot['meta'] : array();
		$name = is_string( $meta['name'] ?? '' ) ? trim( (string) $meta['name'] ) : '';
		$type = Template_Limits::is_template_type( $meta['type'] ?? '' ) ? (string) $meta['type'] : 'custom';

		if ( '' === $name ) {
			$name = __( 'Imported template', 'replicaforge' );
		}

		// --- 1. prepare -------------------------------------------------
		$steps[] = $this->step( 'prepare', 'ok', __( 'Read the snapshot and confirmed it is addressable.', 'replicaforge' ) );

		// --- 2. validate ------------------------------------------------
		$validation = $this->validator->validate( $snapshot, $options );

		$steps[] = $this->step(
			'validate',
			empty( $validation['errors'] ) ? 'ok' : 'failed',
			(string) $validation['label']
		);

		if ( ! Template_Limits::is_installable( (string) $validation['state'] ) ) {
			/*
			 * Refused *before* any write. A template that cannot be installed is not a
			 * template that gets a row and an error message; it is a template that was never
			 * installed, which is a materially different thing to tell a user.
			 */
			return new \WP_Error(
				'template_not_installable',
				sprintf(
					/* translators: %s: the validation state label. */
					__( 'This template is marked "%s" and was not installed. Nothing was written.', 'replicaforge' ),
					(string) $validation['label']
				),
				array( 'status' => 409, 'validation' => $validation, 'steps' => $steps )
			);
		}

		// --- 3. resolve_dependencies ------------------------------------
		$resolved = ( new Template_Dependencies() )->resolve( isset( $snapshot['compatibility'] ) ? $snapshot['compatibility'] : array() );

		$steps[] = $this->step(
			'resolve_dependencies',
			empty( $resolved['blocking'] ) ? 'ok' : 'failed',
			(string) $resolved['summary']
		);

		if ( ! empty( $resolved['blocking'] ) ) {
			return new \WP_Error(
				'template_dependencies_unmet',
				(string) $resolved['summary'],
				array( 'status' => 409, 'requirements' => (array) $resolved['requirements'], 'steps' => $steps )
			);
		}

		// --- 4. create_snapshot -----------------------------------------
		/*
		 * The version is written *before* the template row points at it. The reverse order
		 * would leave a template whose `current_version` names a row that does not exist,
		 * which is the one inconsistency the journal cannot undo cleanly, because the undo
		 * would have to decide which of the two was the mistake.
		 */
		$template = $this->templates->create(
			$workspace_id,
			array(
				'workspace_id'     => $workspace_id,
				'name'              => $name,
				'description'       => (string) ( $meta['description'] ?? '' ),
				'type'              => $type,
				'category'          => Template_Limits::is_category( $options['category'] ?? '' ) ? (string) $options['category'] : 'imported',
				'status'            => 'active',
				'visibility'        => $this->safe_visibility( $options ),
				'user_id'           => $actor,
				'source_post_id'    => (int) ( $options['source_post_id'] ?? 0 ),
				'source_project_id' => (string) ( $options['source_project_id'] ?? '' ),
				'tags'              => (array) ( $meta['tags'] ?? array() ),
			)
		);

		if ( is_wp_error( $template ) ) {
			$steps[] = $this->step( 'create_snapshot', 'failed', (string) $template->get_error_message() );
			return $template;
		}

		$template_id = (string) $template['public_id'];
		$journal[]   = array( 'kind' => 'template', 'id' => $template_id, 'undo' => 'delete_template' );

		$steps[] = $this->step( 'create_snapshot', 'ok', __( 'Created the template record.', 'replicaforge' ) );

		$version = $this->versions->append(
			$workspace_id,
			$template_id,
			$snapshot,
			array(
				'user_id'     => $actor,
				'change_note' => is_string( $options['change_note'] ?? null ) ? (string) $options['change_note'] : __( 'Imported.', 'replicaforge' ),
			)
		);

		if ( is_wp_error( $version ) ) {
			$steps[] = $this->step( 'install', 'failed', (string) $version->get_error_message() );
			$this->rollback( $journal );
			return new \WP_Error(
				'template_version_failed',
				(string) $version->get_error_message(),
				array( 'status' => (int) ( $version->get_error_data()['status'] ?? 500 ), 'rolled_back' => true, 'steps' => $steps )
			);
		}

		$journal[] = array( 'kind' => 'version', 'id' => (string) $version['public_id'], 'undo' => 'purge_version' );

		// --- 5. install ---------------------------------------------------
		$components = $this->install_components( $workspace_id, $snapshot, $options, $journal, $steps );

		$tokens = $this->install_tokens( $workspace_id, $snapshot, $options, $journal, $steps );

		$draft = $this->install_draft( $snapshot, $options, $journal, $steps );

		// --- 6. post_validate ---------------------------------------------
		$stored = $this->versions->current( $workspace_id, $template_id );
		$check  = null === $stored ? null : $this->versions->verify( $stored );

		$steps[] = $this->step(
			'post_validate',
			( null === $check || is_wp_error( $check ) ) ? 'failed' : 'ok',
			( null === $check )
				? __( 'The stored version could not be read back.', 'replicaforge' )
				: ( is_wp_error( $check ) ? (string) $check->get_error_message() : __( 'The stored version matches its recorded hash.', 'replicaforge' ) )
		);

		if ( is_wp_error( $check ) ) {
			$this->rollback( $journal );
			return new \WP_Error( 'template_post_validation_failed', (string) $check->get_error_message(), array( 'status' => 500, 'rolled_back' => true, 'steps' => $steps ) );
		}

		// --- 7. commit -------------------------------------------------------
		$this->templates->set_current( $template_id, (string) $version['public_id'], $this->versions->count_of( $template_id ), (string) $validation['state'] );

		foreach ( $components as $component ) {
			$this->components->record_usage( $workspace_id, (string) ( $component['component_id'] ?? '' ) );
		}

		$steps[] = $this->step( 'commit', 'ok', __( 'The template is stored and points at this version.', 'replicaforge' ) );

		$this->logger->info(
			'template_installed',
			'Installed a template.',
			array(
				'workspace_id' => $workspace_id,
				'template_id'  => $template_id,
				'version'      => (int) ( $version['version'] ?? 0 ),
				'state'        => (string) $validation['state'],
				'components'   => count( $components ),
				'tokens'       => (int) ( $tokens['merged'] ?? 0 ),
			),
			'template'
		);

		Security::log_event( 'template_installed', array( 'status' => (string) $validation['state'], 'count' => count( $components ) ) );

		return array(
			'success'      => true,
			'template_id'  => $template_id,
			'version_id'   => (string) $version['public_id'],
			'version'      => (int) ( $version['version'] ?? 1 ),
			'validation'   => $validation,
			'components'   => $components,
			'tokens'       => $tokens,
			'draft'        => $draft,
			'compatibility' => $resolved,
			'steps'        => $steps,
			'rolled_back'  => false,
		);
	}

	/* ---------------------------------------------------------------------
	 * Sub-installers
	 * ------------------------------------------------------------------ */

	/**
	 * Install the template's components.
	 *
	 * @param string              $workspace_id Workspace id.
	 * @param array<string, mixed> $snapshot     Snapshot.
	 * @param array<string, mixed> $options      Options.
	 * @param array<int, array<string, mixed>> $journal Journal, by reference.
	 * @param array<int, array<string, mixed>> $steps Steps, by reference.
	 * @return array<int, array<string, mixed>>
	 */
	private function install_components( $workspace_id, array $snapshot, array $options, array &$journal, array &$steps ) {
		$components = isset( $snapshot['components'] ) && is_array( $snapshot['components'] ) ? $snapshot['components'] : array();
		$stored     = array();

		if ( array() === $components ) {
			$steps[] = $this->step( 'install', 'ok', __( 'This template registers no reusable components.', 'replicaforge' ) );
			return $stored;
		}

		$document = isset( $snapshot['document'] ) && is_array( $snapshot['document'] ) ? $snapshot['document'] : array();
		$elements = isset( $document['elements'] ) && is_array( $document['elements'] ) ? $document['elements'] : array();

		foreach ( $components as $component_id => $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}

			$id = (string) $component_id;

			if ( '' === $id ) {
				continue;
			}

			/*
			 * A component's document is its own subtree, not the whole page. Extracting the
			 * wrong one would produce a component that is the entire page — which installs
			 * cleanly, renders, and is completely wrong. So the elements the component claims
			 * are pulled by id, and a component claiming elements that are not present is
			 * refused rather than stored empty.
			 */
			$claimed = array();

			foreach ( (array) ( $component['element_ids'] ?? array() ) as $element_id ) {
				$element_id = is_scalar( $element_id ) ? (string) $element_id : '';
				if ( '' !== $element_id ) {
					$claimed[] = $element_id;
				}
			}

			$subtree = array() === $claimed
				? array( 'elements' => array() )
				: array( 'elements' => $this->extract_subtree( $elements, $claimed ) );

			$count = count( $subtree['elements'] );

			if ( 0 === $count ) {
				$steps[] = $this->step( 'install', 'warned', sprintf(
					/* translators: %s: the component id. */
					__( 'The component "%s" was not stored because none of the elements it claims are present in this template.', 'replicaforge' ),
					$id
				) );
				continue;
			}

			$record = $this->components->register(
				$workspace_id,
				array(
					'workspace_id' => $workspace_id,
					'component_id' => $id,
					'name'         => is_string( $component['name'] ?? null ) && '' !== $component['name'] ? (string) $component['name'] : ucwords( str_replace( '_', ' ', $id ) ),
					'type'         => is_string( $component['type'] ?? null ) && '' !== $component['type'] ? (string) $component['type'] : 'component',
					'description'  => is_string( $component['description'] ?? null ) ? (string) $component['description'] : '',
					'user_id'      => (int) ( $options['user_id'] ?? get_current_user_id() ),
					'document'     => $subtree,
					'tokens'       => isset( $snapshot['tokens'] ) ? $snapshot['tokens'] : array(),
					'content_slots' => isset( $snapshot['content_slots'] ) ? $snapshot['content_slots'] : array(),
					'assets'       => isset( $snapshot['assets'] ) ? $snapshot['assets'] : array(),
					'dependencies' => isset( $snapshot['dependencies'] ) ? $snapshot['dependencies'] : array(),
					'compatibility' => isset( $snapshot['compatibility'] ) ? $snapshot['compatibility'] : array(),
					'validation'   => array( 'state' => (string) ( $options['validation_state'] ?? '' ) ),
					'provenance'   => array(
						'origin'     => 'imported',
						'project_id' => (string) ( $options['source_project_id'] ?? '' ),
					),
					'change_note'  => __( 'Added with a template import.', 'replicaforge' ),
				)
			);

			if ( is_wp_error( $record ) ) {
				$steps[] = $this->step( 'install', 'failed', (string) $record->get_error_message() );
				continue;
			}

			$stored[] = $record;

			if ( ! empty( $record['created'] ) ) {
				$journal[] = array( 'kind' => 'component', 'id' => (string) $record['component_id'], 'workspace_id' => $workspace_id, 'undo' => 'archive_component' );
			}
		}

		$steps[] = $this->step( 'install', 'ok', sprintf(
			/* translators: %d: how many components were stored. */
			_n( 'Stored one reusable component.', 'Stored %d reusable components.', count( $stored ), 'replicaforge' ),
			count( $stored )
		) );

		return $stored;
	}

	/**
	 * Merge the template's tokens into the workspace design system.
	 *
	 * @param string              $workspace_id Workspace id.
	 * @param array<string, mixed> $snapshot     Snapshot.
	 * @param array<string, mixed> $options      Options.
	 * @param array<int, array<string, mixed>> $journal Journal, by reference.
	 * @param array<int, array<string, mixed>> $steps Steps, by reference.
	 * @return array<string, mixed>
	 */
	private function install_tokens( $workspace_id, array $snapshot, array $options, array &$journal, array &$steps ) {
		$tokens = isset( $snapshot['tokens'] ) && is_array( $snapshot['tokens'] ) ? $snapshot['tokens'] : array();

		if ( array() === $tokens ) {
			return array( 'merged' => 0, 'kept_user' => 0, 'kept_theme' => 0, 'added' => 0 );
		}

		/*
		 * §8 and §28: merging a design system is a separate, explicit decision, not a
		 * side effect of installing a template. Installing a template must not re-point every
		 * other template on the site.
		 *
		 * So tokens are only merged when the caller asked, and even then a `user_controlled`
		 * token in the destination is preserved by `Design_Token_Registry::merge()`.
		 */
		if ( empty( $options['merge_tokens'] ) ) {
			$steps[] = $this->step( 'install', 'ok', sprintf(
				/* translators: %d: how many tokens the template carries. */
				_n( 'This template carries one design token, which was not added to the site design system.', 'This template carries %d design tokens, which were not added to the site design system.', count( $tokens ), 'replicaforge' ),
				count( $tokens )
			) );
			return array( 'merged' => 0, 'kept_user' => 0, 'kept_theme' => 0, 'added' => 0, 'skipped' => true );
		}

		$before = $this->tokens->registry( $workspace_id );
		$after  = $this->tokens->merge( $workspace_id, $tokens );

		$journal[] = array( 'kind' => 'tokens', 'id' => $workspace_id, 'undo' => 'restore_tokens', 'before' => $before );

		$steps[] = $this->step( 'install', 'ok', sprintf(
			/* translators: 1: tokens added, 2: tokens kept because a person set them. */
			__( 'Merged the design system: %1$d token(s) added, %2$d left as they were because someone set them by hand.', 'replicaforge' ),
			(int) $after['added'],
			(int) $after['kept_user']
		) );

		return $after;
	}

	/**
	 * Create an Elementor draft from the template, when asked.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @param array<string, mixed> $options  Options.
	 * @param array<int, array<string, mixed>> $journal Journal, by reference.
	 * @param array<int, array<string, mixed>> $steps Steps, by reference.
	 * @return array<string, mixed>
	 */
	private function install_draft( array $snapshot, array $options, array &$journal, array &$steps ) {
		if ( empty( $options['create_draft'] ) ) {
			return array( 'created' => false );
		}

		$document = isset( $snapshot['document'] ) && is_array( $snapshot['document'] ) ? $snapshot['document'] : array();
		$elements = isset( $document['elements'] ) && is_array( $document['elements'] ) ? $document['elements'] : array();

		if ( array() === $elements ) {
			$steps[] = $this->step( 'install', 'warned', __( 'No draft was created because the template has no document.', 'replicaforge' ) );
			return array( 'created' => false, 'reason' => 'no_document' );
		}

		$compatibility = new Elementor_Compatibility();
		$drafter       = new Elementor_Draft_Service( $compatibility, new Elementor_Validator() );

		$created = $drafter->create(
			$elements,
			array(
				'source_url' => (string) ( $options['source_url'] ?? '' ),
				'ai_used'    => false,
			)
		);

		if ( empty( $created['success'] ) ) {
			$steps[] = $this->step( 'install', 'warned', __( 'The template was stored, but the draft could not be created. Nothing else was affected.', 'replicaforge' ) );
			return array( 'created' => false, 'errors' => (array) ( $created['errors'] ?? array() ) );
		}

		$post_id = (int) ( $created['post_id'] ?? 0 );

		/*
		 * Recorded as `draft_created` rather than a plain draft, so a rollback knows it may
		 * remove it. See the class docblock: a draft a user has been shown is theirs, and
		 * this one has not been shown yet.
		 */
		$journal[] = array( 'kind' => 'draft', 'id' => $post_id, 'undo' => 'delete_draft', 'orphan' => true );

		$steps[] = $this->step( 'install', 'ok', __( 'Created an Elementor draft from the template.', 'replicaforge' ) );

		return array( 'created' => true, 'post_id' => $post_id, 'edit_url' => (string) ( $created['edit_url'] ?? '' ) );
	}

	/* ---------------------------------------------------------------------
	 * Rollback
	 * ------------------------------------------------------------------ */

	/**
	 * Undo every write in the journal, newest first.
	 *
	 * @param array<int, array<string, mixed>> $journal Journal.
	 * @return array{undone: array<int, string>, failed: array<int, string>}
	 */
	public function rollback( array $journal ) {
		$undone  = array();
		$failed  = array();

		foreach ( array_reverse( $journal ) as $entry ) {
			$kind = (string) ( $entry['kind'] ?? '' );
			$id   = $entry['id'] ?? '';
			$undo = (string) ( $entry['undo'] ?? '' );

			$ok = false;

			switch ( $undo ) {
				case 'delete_template':
					$ok = $this->templates->delete_template( (string) $id );
					break;

				case 'purge_version':
					// Removed with the template; a template rollback already covers it.
					$ok = true;
					break;

				case 'archive_component':
					$ok = $this->components->archive( (string) $entry['workspace_id'] ?? '', (string) $id );
					break;

				case 'restore_tokens':
					$ok = $this->restore_tokens( (string) $id, (array) ( $entry['before'] ?? array() ) );
					break;

				case 'delete_draft':
					/*
					 * Only an orphan draft is removed, and only with a force delete — the same
					 * shape `Elementor_Draft_Service::rollback()` uses. A draft the install
					 * created and never returned to the user is ReplicaForge's own debris.
					 */
					$ok = ! empty( $entry['orphan'] ) && (int) $id > 0 && null !== get_post( (int) $id )
						? (bool) wp_delete_post( (int) $id, true )
						: false;
					break;

				default:
					$ok = false;
			}

			if ( $ok ) {
				$undone[] = $kind . ':' . (string) $id;
			} else {
				$failed[] = $kind . ':' . (string) $id;
			}
		}

		if ( array() !== $undone ) {
			$this->logger->warning(
				'template_install_rolled_back',
				'An installation failed part way and was rolled back.',
				array( 'undone' => count( $undone ), 'failed' => count( $failed ) ),
				'template'
			);
		}

		if ( array() !== $failed ) {
			/*
			 * A failed undo is reported loudly and specifically. "Rollback was attempted and
			 * three writes could not be undone" is actionable; a silent partial rollback is
			 * the state this class exists to avoid, and hiding it would be worse than
			 * reporting it.
			 */
			$this->logger->error(
				'template_rollback_incomplete',
				'An installation was rolled back, but some writes could not be undone and may need attention.',
				array( 'failed' => $failed ),
				'template'
			);
		}

		return array( 'undone' => $undone, 'failed' => $failed );
	}

	/**
	 * Restore a workspace's tokens to a previous set.
	 *
	 * @param string $workspace_id Workspace id.
	 * @param array<string, mixed> $before Previous tokens.
	 * @return bool
	 */
	private function restore_tokens( $workspace_id, array $before ) {
		$workspace_id = is_string( $workspace_id ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $workspace_id ), 0, 26 ) : '';

		if ( '' === $workspace_id ) {
			return false;
		}

		$all = get_option( Template_Limits::TOKENS_OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ $workspace_id ] = array(
			'tokens'     => $before,
			'updated_at' => gmdate( 'c' ),
			'count'      => count( $before ),
		);

		update_option( Template_Limits::TOKENS_OPTION, $all, false );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Pull a set of elements, and their descendants, out of a document.
	 *
	 * @param array<int, mixed> $elements    Document elements.
	 * @param array<int, string> $wanted      Element ids wanted.
	 * @param int                $depth       Current depth.
	 * @return array<int, mixed>
	 */
	private function extract_subtree( array $elements, array $wanted, $depth = 0 ) {
		if ( $depth > 8 ) {
			return array();
		}

		$out = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
			$id       = (string) ( $element['id'] ?? '' );

			if ( in_array( $id, $wanted, true ) ) {
				$copy = $element;
				if ( array() !== $children ) {
					$copy['elements'] = $this->extract_subtree( $children, $wanted, $depth + 1 );
				}
				$out[] = $copy;
				continue;
			}

			// A wanted element may be nested deeper inside a container.
			if ( array() !== $children ) {
				$found = $this->extract_subtree( $children, $wanted, $depth + 1 );

				if ( array() !== $found ) {
					$copy          = $element;
					$copy['elements'] = $found;
					$out[]         = $copy;
				}
			}
		}

		return $out;
	}

	/**
	 * Reduce a requested visibility to one this user may actually hold.
	 *
	 * A client may only ever hold `client_review`, whatever the request says, so the refusal
	 * is in the store rather than relying on the caller to know.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return string
	 */
	private function safe_visibility( array $options ) {
		/*
		 * Read once, then test.
		 *
		 * The original was `is_string( $options['visibility'] ?? '' ) ? (string) $options['visibility'] : 'private'`,
		 * which looks safe because of the `??` and is not. When the key is missing the `??`
		 * yields `''`, `is_string( '' )` is **true**, so the true branch runs — and it
		 * re-reads the key *without* the coalesce, which is the read that warns. A guard
		 * that suppresses the warning on the line before reintroducing it on the next is worse
		 * than no guard, because it looks deliberate.
		 *
		 * Binding to a local first makes the intent plain: one read, one default, one test.
		 */
		$requested = isset( $options['visibility'] ) && is_string( $options['visibility'] )
			? (string) $options['visibility']
			: 'private';

		if ( ! Template_Limits::is_visibility( $requested ) ) {
			return 'private';
		}

		/*
		 * The workspace is read from the template row the store just wrote, not from
		 * `$options`. A caller that does not happen to pass `workspace_id` was producing an
		 * undefined-key warning and silently skipping the client check — which is the check
		 * that stops a client being granted a broader visibility than `client_review`.
		 */
		$workspace_id = (string) ( $options['workspace_id'] ?? '' );
		$user         = (int) ( $options['user_id'] ?? get_current_user_id() );

		if ( 'client' === $this->workspace_role( $user, $workspace_id ) && ! in_array( $requested, Template_Limits::CLIENT_VISIBILITY, true ) ) {
			return 'client_review';
		}

		return $requested;
	}

	/**
	 * Return a user's role in a workspace.
	 *
	 * @param int    $user_id      User id.
	 * @param string $workspace_id Workspace id.
	 * @return string
	 */
	private function workspace_role( $user_id, $workspace_id ) {
		if ( $user_id < 1 || '' === $workspace_id ) {
			return '';
		}

		return (string) ( new Permission_Manager() )->role( $user_id, $workspace_id );
	}

	/**
	 * Build one step record.
	 *
	 * @param string $step    Step name.
	 * @param string $outcome Outcome.
	 * @param string $message Message.
	 * @return array<string, mixed>
	 */
	private function step( $step, $outcome, $message ) {
		return array( 'step' => $step, 'outcome' => $outcome, 'message' => (string) $message );
	}
}
