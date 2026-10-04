<?php
/**
 * Phase 19: template extraction.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a generated Elementor document into a reusable template.
 *
 * ### The pipeline, and what each step is for
 *
 * §17 lays out nine steps. Each is implemented by an existing class wherever one exists,
 * and this class is the composition rather than the logic:
 *
 * | Step | Implemented by | Notes |
 * |---|---|---|
 * | Read the document | `Elementor_Document_Reader` | Phase 6. Already reads `_elementor_data` and `_elementor_responsive`. |
 * | Extract structure | here, from the reader | |
 * | Extract design tokens | `Token_Engine` / `Site_Design_System` | Phase 8 / 12. Not re-derived. |
 * | Extract responsive rules | `Elementor_Responsive` output | Phase 4, read from the source page record. |
 * | Extract interaction rules | `Interaction_Service` model reference | Phase 16 stores its own model; this stores a *reference*. |
 * | Separate content | `Content_Slot_Registry` | |
 * | Check assets | `Asset_Registry` + §15 rules | Phase 12 discovers; this classifies. |
 * | Validate | `Template_Validator` | |
 * | Sanitise | `Template_Sanitizer` | |
 *
 * ### What it will not do
 *
 * It will not fetch anything. Extraction reads what ReplicaForge already stored, so a
 * template can be created from a project that was analysed days ago and whose source has
 * since moved. It will not invent a design value, and it will not fill a slot with the
 * source page's text.
 *
 * ### The `replicaforge_analysis` dependency, stated plainly
 *
 * The representation — the Phase 2 output that carries the typography, spacing and colour
 * evidence — is read from the `replicaforge_analysis` post meta on the generated page. That
 * key is read by four Phase 12/13/14 consumers and **was written by nothing** before this
 * phase: the audit found reads at `Content_Api:208`, `Multi_Page_Api:940`,
 * `Visual_Api:340` and `Visual_Api:427`, and no writes anywhere. So those four readers
 * were silently always getting an empty array, and extraction would have had no design
 * evidence to work from.
 *
 * `Template_Extractor` therefore accepts a representation from whatever durable home
 * exists, in priority order, and says which one it used. {@see Template_Extractor::representation_for()}
 * is the single place that lookup happens.
 */
final class Template_Extractor {

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Token registry.
	 *
	 * @var Design_Token_Registry
	 */
	private $tokens;

	/**
	 * Slot registry.
	 *
	 * @var Content_Slot_Registry
	 */
	private $slots;

	/**
	 * Dependency resolver.
	 *
	 * @var Template_Dependencies
	 */
	private $dependencies;

	/**
	 * Constructor.
	 *
	 * @param Logger|null                $logger       Logger.
	 * @param Design_Token_Registry|null $tokens       Token registry.
	 * @param Content_Slot_Registry|null $slots        Slot registry.
	 * @param Template_Dependencies|null $dependencies Resolver.
	 */
	public function __construct( $logger = null, $tokens = null, $slots = null, $dependencies = null ) {
		$this->logger       = $logger instanceof Logger ? $logger : new Logger();
		$this->tokens       = $tokens instanceof Design_Token_Registry ? $tokens : new Design_Token_Registry( $this->logger );
		$this->slots        = $slots instanceof Content_Slot_Registry ? $slots : new Content_Slot_Registry( $this->logger );
		$this->dependencies = $dependencies instanceof Template_Dependencies ? $dependencies : new Template_Dependencies( null, $this->logger );
	}

	/* ---------------------------------------------------------------------
	 * Representation lookup
	 * ------------------------------------------------------------------ */

	/**
	 * Find the design representation for a generated page.
	 *
	 * Three durable homes are consulted, in the order that is most likely to hold real
	 * evidence, and the source used is reported so the caller can tell the user where the
	 * design data came from — which matters, because "no design tokens" and "the design data
	 * was not found" are different problems.
	 *
	 * @param int    $post_id     Generated page id.
	 * @param string $project_id  Project id, for the project-version fallback.
	 * @return array{representation: array<string, mixed>, source: string, note: string}
	 */
	public function representation_for( $post_id, $project_id = '' ) {
		$post_id = (int) $post_id;

		// 1. The post meta four Phase 12/13/14 consumers already read.
		if ( $post_id > 0 ) {
			$stored = get_post_meta( $post_id, 'replicaforge_analysis', true );

			if ( is_array( $stored ) && array() !== $stored ) {
				return array(
					'representation' => $stored,
					'source'         => 'post_meta',
					'note'           => '',
				);
			}
		}

		// 2. The project record's latest version.
		$project_id = is_string( $project_id ) ? $project_id : '';

		if ( '' !== $project_id ) {
			$project = ( new Project_Repository( $this->logger ) )->find( $project_id );

			if ( is_array( $project ) ) {
				$versions = isset( $project['versions'] ) && is_array( $project['versions'] ) ? $project['versions'] : array();

				if ( array() !== $versions ) {
					$latest = $versions[ count( $versions ) - 1 ];

					foreach ( array( 'analysis', 'design' ) as $key ) {
						if ( isset( $latest[ $key ] ) && is_array( $latest[ $key ] ) && array() !== $latest[ $key ] ) {
							return array(
								'representation' => $latest[ $key ],
								'source'         => 'project_version',
								'note'           => '',
							);
						}
					}
				}
			}
		}

		// 3. The Elementor specification, which Phase 4 persists per generation.
		if ( $post_id > 0 ) {
			$specification_id = (string) get_post_meta( $post_id, 'replicaforge_specification_id', true );

			if ( '' !== $specification_id ) {
				$specification = ( new Elementor_Repository( $this->logger ) )->get_specification( $specification_id );

				if ( is_array( $specification ) && array() !== $specification ) {
					return array(
						'representation' => $specification,
						'source'         => 'elementor_specification',
						'note'           => '',
					);
				}
			}
		}

		return array(
			'representation' => array(),
			'source'         => 'none',
			'note'           => __( 'No design representation was found for this page, so the template will carry structure with no design tokens. That is what was stored, not a failure to read it.', 'replicaforge' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Extraction
	 * ------------------------------------------------------------------ */

	/**
	 * Extract a template snapshot from a generated Elementor page.
	 *
	 * @param int                  $post_id    Generated page id.
	 * @param array<string, mixed> $context    Extraction context.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function extract( $post_id, array $context = array() ) {
		$post_id = (int) $post_id;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! is_object( $post ) ) {
			return new \WP_Error( 'template_source_missing', __( 'That generated page could not be found, so there is nothing to extract a template from.', 'replicaforge' ), array( 'status' => 404 ) );
		}

		$reader = new Elementor_Document_Reader( new Elementor_Validator(), new Correction_Property_Map() );
		$loaded = $reader->load( $post_id, false );

		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$elements   = $reader->elements();
		$responsive = $reader->responsive();

		if ( ! is_array( $elements ) || array() === $elements ) {
			return new \WP_Error(
				'template_source_empty',
				__( 'This page has no Elementor document, so there is no structure to make a template from. Generate the page first.', 'replicaforge' ),
				array( 'status' => 409 )
			);
		}

		$project_id  = is_string( $context['project_id'] ?? '' ) ? $context['project_id'] : '';
		$found       = $this->representation_for( $post_id, $project_id );
		$warnings    = array();
		$description = $this->type_description( $context['type'] ?? '' );

		if ( 'none' === $found['source'] ) {
			$warnings[] = $found['note'];
		}

		// --- Design tokens ---------------------------------------------
		$design_system = array();
		$extracted     = array( 'tokens' => array(), 'counts' => array(), 'total' => 0, 'unassigned_roles' => array(), 'warnings' => array() );

		if ( array() !== $found['representation'] ) {
			$design_system = $this->design_system_from( $found['representation'] );
			$extracted     = $this->tokens->extract(
				$design_system,
				array(
					'source'  => 'source_analysis',
					'scope'   => 'template',
					'version' => Template_Limits::SCHEMA_VERSION,
				)
			);

			$warnings = array_merge( $warnings, (array) $extracted['warnings'] );
		} else {
			$warnings[] = __( 'This template carries no design tokens, because no design representation was stored for the page it came from. Structure is still captured in full.', 'replicaforge' );
		}

		// --- Content slots ---------------------------------------------
		$roles = $this->content_roles_from( $found['representation'] );
		$built = $this->slots->build( $roles, array( 'keep_content' => ! empty( $context['keep_content'] ) ) );

		$warnings = array_merge( $warnings, (array) $built['warnings'] );

		// --- Assets ----------------------------------------------------
		$assets = $this->assets_from( $elements, $found['representation'], $context );

		// --- Responsive -------------------------------------------------
		$responsive_block = $this->responsive_block( $responsive, $found['representation'] );

		// --- Interactions ----------------------------------------------
		$interactions = $this->interaction_block( $context );

		// --- Sanitise ----------------------------------------------------
		$sanitizer = new Template_Sanitizer( null, $this->logger );
		$scan      = $sanitizer->scan( $elements );

		if ( ! $scan['ok'] ) {
			return new \WP_Error(
				'template_source_unsafe',
				__( 'This page contains something the security check will not put in a template, so no template was created. The page itself is untouched.', 'replicaforge' ),
				array( 'status' => 422, 'fatal' => (array) $scan['fatal'] )
			);
		}

		$dependencies = isset( $context['dependencies'] ) && is_array( $context['dependencies'] ) ? $context['dependencies'] : array();
		$components   = $this->components_from( $context );

		$compatibility = $this->dependencies->declare( array( 'woocommerce_required' => $this->needs_woocommerce( $built['slots'] ) ) );

		$snapshot = array(
			'document'      => array( 'elements' => $scan['elements'] ),
			'responsive'    => $responsive_block,
			'interactions'  => $interactions,
			'design_system' => $design_system,
			'tokens'        => (array) $extracted['tokens'],
			'components'    => $components,
			'content_slots' => (array) $built['slots'],
			'assets'        => $assets,
			'dependencies'  => $dependencies,
			'compatibility' => $compatibility,
			'provenance'    => array(
				'origin'      => 'extracted',
				'author'      => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
				'source_post' => $post_id,
				'url'         => (string) ( $context['source_url'] ?? '' ),
				'project_id'  => $project_id,
				'created_at'  => gmdate( 'c' ),
				'note'        => sprintf(
					/* translators: %s: the source page title. */
					__( 'Extracted from the ReplicaForge draft "%s". The text and images on that page belong to its owner and are not carried into the template.', 'replicaforge' ),
					(string) $post->post_title
				),
			),
			'validation'    => array( 'state' => '', 'checked_at' => '' ),
		);

		$validator = new Template_Validator( $sanitizer, $this->dependencies, $this->logger );
		$validation = $validator->validate( $snapshot, $context );

		$snapshot['validation'] = $validation;

		return array(
			'snapshot'    => $snapshot,
			'validation'  => $validation,
			'post_id'     => $post_id,
			'post_title'  => (string) $post->post_title,
			'description' => $description,
			'tokens'      => $extracted,
			'slots'       => $built,
			'assets'      => $assets,
			'element_count' => (int) $scan['kept'],
			'removed'     => (int) $scan['dropped'],
			'removals'    => (array) $scan['removals'],
			'representation_source' => (string) $found['source'],
			'warnings'    => array_values( array_unique( $warnings ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Section builders
	 * ------------------------------------------------------------------ */

	/**
	 * Build a design system from a representation, using Phase 8 and Phase 12.
	 *
	 * A single page's representation goes through `Site_Design_System::build()`, which is
	 * the same path a whole site takes. That is deliberate: a one-page "site" is a site,
	 * and reusing the path means a template's tokens are produced by exactly the code that
	 * produces a site's, rather than a second derivation that could disagree.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @return array<string, mixed>
	 */
	private function design_system_from( array $representation ) {
		$observations = array();

		foreach ( (array) ( $representation['sections'] ?? array() ) as $section ) {
			if ( is_array( $section ) ) {
				$observations[] = $section;
			}
		}

		// A representation with no `sections` key is still usable if it has nodes.
		if ( array() === $observations ) {
			foreach ( (array) ( $representation['nodes'] ?? array() ) as $node ) {
				if ( is_array( $node ) ) {
					$observations[] = $node;
				}
			}
		}

		if ( array() === $observations ) {
			return array();
		}

		$builder = new Site_Design_System( new Token_Engine() );

		return $builder->build( $observations );
	}

	/**
	 * Pull content roles out of a representation.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return array<int, array<string, mixed>>
	 */
	private function content_roles_from( array $representation ) {
		$roles = array();

		foreach ( (array) ( $representation['sections'] ?? array() ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$content = isset( $section['content'] ) && is_array( $section['content'] ) ? $section['content'] : array();

			foreach ( $content as $key => $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$roles[] = array(
					'role'  => (string) $key,
					'value' => (string) $value,
				);
			}
		}

		return $roles;
	}

	/**
	 * Classify the assets a document references.
	 *
	 * §15 is the requirement and it is enforced here, at extraction, not at export. An
	 * asset's provenance class is decided from where it came from:
	 *
	 * - An asset already in this site's media library with ReplicaForge provenance is
	 *   `user_owned` — the user imported it, so it is theirs.
	 * - An asset the analysis found on the source site is `source_derived`. **Never**
	 *   redistributable, and the mode is forced to `reference`.
	 * - Anything ReplicaForge generated is `placeholder`.
	 *
	 * @param array<int, mixed>    $elements       Document elements.
	 * @param array<string, mixed> $representation Representation.
	 * @param array<string, mixed> $context        Context.
	 * @return array<string, array<string, mixed>>
	 */
	private function assets_from( array $elements, array $representation, array $context ) {
		$found = array();

		$walk = function ( array $list ) use ( &$walk, &$found ) {
			foreach ( $list as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

				foreach ( array( 'image', 'background_image' ) as $key ) {
					if ( ! isset( $settings[ $key ] ) ) {
						continue;
					}

					$urls = $settings[ $key ];

					if ( is_string( $urls ) ) {
						$urls = array( $urls );
					}
					if ( isset( $urls['url'] ) && is_string( $urls['url'] ) ) {
						$urls = array( $urls['url'] );
					}
					if ( ! is_array( $urls ) ) {
						continue;
					}

					foreach ( $urls as $url ) {
						if ( ! is_string( $url ) || '' === $url || ! Security::is_safe_public_reference( $url ) ) {
							continue;
						}

						$id = $this->asset_id( $url );

						if ( isset( $found[ $id ] ) ) {
							$found[ $id ]['usage_count']++;
							continue;
						}

						$local  = $this->local_attachment( $url );
						$origin = isset( $representation['assets'][ $id ] ) && is_array( $representation['assets'][ $id ] ) ? $representation['assets'][ $id ] : array();

						$found[ $id ] = $this->classify_asset( $url, $local, $origin, $context );
					}
				}

				$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();

				if ( array() !== $children ) {
					$walk( $children );
				}
			}
		};

		$walk( $elements );

		return $found;
	}

	/**
	 * Classify one asset and decide its mode.
	 *
	 * @param string               $url     Asset URL.
	 * @param int                  $local   Local attachment id, or 0.
	 * @param array<string, mixed> $origin  Phase 12 asset record, if any.
	 * @param array<string, mixed> $context Context.
	 * @return array<string, mixed>
	 */
	private function classify_asset( $url, $local, array $origin, array $context ) {
		if ( $local > 0 ) {
			$provenance = 'user_owned';
		} elseif ( ! empty( $context['generated_asset'] ) ) {
			$provenance = 'placeholder';
		} else {
			$provenance = 'source_derived';
		}

		$redistributable = Template_Limits::is_redistributable( $provenance );

		return array(
			'asset_id'         => $this->asset_id( $url ),
			'url'              => substr( $url, 0, 2048 ),
			'type'             => 'image',
			'mime_type'        => isset( $origin['mime_type'] ) && is_scalar( $origin['mime_type'] ) ? (string) $origin['mime_type'] : '',
			'dimensions'       => isset( $origin['dimensions'] ) && is_array( $origin['dimensions'] ) ? $origin['dimensions'] : array(),
			'media_id'         => (int) $local,
			'provenance_class' => $provenance,
			/*
			 * The line that keeps this product on the right side of §3. A source-site image
			 * is referenced, never embedded, and no setting a user passes changes that —
			 * the only way an image becomes `user_owned` is for it to already be in this
			 * site's own media library.
			 */
			'mode'             => $redistributable ? 'import' : 'reference',
			'redistributable'  => $redistributable,
			'ownership'        => $redistributable ? 'user_controlled' : 'source_derived',
			'licence'          => isset( $origin['licence'] ) && is_scalar( $origin['licence'] ) ? (string) $origin['licence'] : '',
			'usage_count'      => 1,
			'source_host'      => (string) ( $origin['source_site'] ?? wp_parse_url( $url, PHP_URL_HOST ) ),
			'note'             => $redistributable
				? ''
				: __( 'This image comes from the analysed website. It is referenced, not copied, so installing the template does not redistribute someone else\'s file. Replace it with your own image in the template settings.', 'replicaforge' ),
		);
	}

	/**
	 * Build the responsive block.
	 *
	 * Phase 4 writes device overrides into element settings as `_tablet` / `_mobile`
	 * suffixed keys, and Phase 6 keeps a post-level `_elementor_responsive` map. Both are
	 * captured, because a template that carried only one would silently lose half its
	 * responsive behaviour on install.
	 *
	 * @param array<string, mixed> $reader_responsive The reader's responsive map.
	 * @param array<string, mixed> $representation   Representation.
	 * @return array<string, mixed>
	 */
	private function responsive_block( array $reader_responsive, array $representation ) {
		$sections = array();

		if ( is_array( $reader_responsive ) ) {
			foreach ( $reader_responsive as $element_id => $controls ) {
				if ( ! is_array( $controls ) ) {
					continue;
				}

				$devices = array();

				foreach ( $controls as $control => $value ) {
					if ( preg_match( '/_(tablet|mobile)$/', (string) $control, $m ) ) {
						$devices[ $m[1] ][ $control ] = $value;
					}
				}

				if ( array() !== $devices ) {
					$sections[ (string) $element_id ] = array(
						'tablet'  => isset( $devices['tablet'] ) ? $devices['tablet'] : array( 'evidence' => 'not_detected' ),
						'mobile'  => isset( $devices['mobile'] ) ? $devices['mobile'] : array( 'evidence' => 'not_detected' ),
					);
				}
			}
		}

		$source = isset( $representation['responsive'] ) && is_array( $representation['responsive'] ) ? $representation['responsive'] : array();

		return array(
			'devices'  => ( new Elementor_Responsive() )->active_devices(),
			'sections' => $sections,
			/*
			 * The evidence from the source analysis, kept because it says *why* a device
			 * override exists. A template carrying a layout with no recorded reason is a
			 * template nobody can safely edit later.
			 */
			'source'   => array(
				'known'       => (int) ( $source['known'] ?? 0 ),
				'per_page'    => (array) ( $source['per_page'] ?? array() ),
				'exceptions'  => (array) ( $source['exceptions'] ?? array() ),
				'note'        => (string) ( $source['note'] ?? '' ),
			),
		);
	}

	/**
	 * Build the interaction block.
	 *
	 * Phase 16 persists its own model and Phase 17 already stores a *reference* to it
	 * (`Workflow_Artifacts::REFERENCE_KINDS` includes `interaction_reference`). A template
	 * does the same. A template that copied the model would carry a second copy of Phase
	 * 16's state, which is the duplication §11 and the stop conditions both forbid — and
	 * it would carry *behaviour* in a package, which §39 treats as hostile.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return array<string, mixed>
	 */
	private function interaction_block( array $context ) {
		$model_id = is_string( $context['interaction_model_id'] ?? '' ) ? $context['interaction_model_id'] : '';

		if ( '' === $model_id ) {
			return array();
		}

		return array(
			'reference' => true,
			'model_id'  => substr( $model_id, 0, 64 ),
			'total'     => (int) ( $context['interaction_total'] ?? 0 ),
			'mapped'    => (int) ( $context['interaction_mapped'] ?? 0 ),
			'note'      => __( 'Interactions are stored as a reference to the ReplicaForge interaction model, not as code. A template package never carries behaviour.', 'replicaforge' ),
		);
	}

	/**
	 * Build the component references this template contains.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return array<string, array<string, mixed>>
	 */
	private function components_from( array $context ) {
		$components = isset( $context['components'] ) && is_array( $context['components'] ) ? $context['components'] : array();
		$out        = array();

		foreach ( $components as $component_id => $component ) {
			$id = is_scalar( $component_id ) ? (string) $component_id : '';
			$id = substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( $id ) ), 0, 64 );

			if ( '' === $id || ! is_array( $component ) ) {
				continue;
			}

			$out[ $id ] = array(
				'component_id'  => $id,
				'type'          => isset( $component['type'] ) && is_string( $component['type'] ) ? substr( $component['type'], 0, 32 ) : '',
				'element_count' => max( 1, (int) ( $component['element_count'] ?? 1 ) ),
				'source'        => 'phase12_detection',
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return a stable asset identifier.
	 *
	 * @param string $url Asset URL.
	 * @return string
	 */
	private function asset_id( $url ) {
		return 'asset_' . substr( hash( 'sha256', (string) wp_parse_url( (string) $url, PHP_URL_HOST ) . (string) wp_parse_url( (string) $url, PHP_URL_PATH ) ), 0, 12 );
	}

	/**
	 * Return the local attachment id for an asset URL, if it is one of ours.
	 *
	 * @param string $url Asset URL.
	 * @return int Zero when the URL does not point at this site's uploads.
	 */
	private function local_attachment( $url ) {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) ) {
			return 0;
		}

		$base = (string) $uploads['baseurl'];

		if ( 0 !== strpos( (string) $url, $base ) ) {
			return 0;
		}

		$relative = substr( (string) $url, strlen( $base ) );
		$absolute = trailingslashit( (string) $uploads['basedir'] ) . ltrim( $relative, '/' );

		if ( ! file_exists( $absolute ) ) {
			return 0;
		}

		$found = attachment_url_to_postid( (string) $url );

		return (int) $found > 0 ? (int) $found : 0;
	}

	/**
	 * Return whether a slot set needs WooCommerce.
	 *
	 * @param array<int, array<string, mixed>> $slots Slots.
	 * @return bool
	 */
	private function needs_woocommerce( array $slots ) {
		foreach ( $slots as $slot ) {
			if ( is_array( $slot ) && Template_Limits::needs_woocommerce( $slot['dynamic_source'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return a human description for a template type.
	 *
	 * @param mixed $type Template type.
	 * @return string
	 */
	private function type_description( $type ) {
		if ( ! Template_Limits::is_template_type( $type ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: the template type, written out. */
			__( 'A reusable %s template. It carries the structure, the design tokens this page used, and placeholders for the content — not the text and images from the page it came from.', 'replicaforge' ),
			strtolower( (string) Template_Limits::type_label( $type ) )
		);
	}
}
