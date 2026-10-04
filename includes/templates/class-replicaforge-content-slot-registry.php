<?php
/**
 * Phase 19: content slots.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Separates reusable design structure from the content that fills it.
 *
 * ### The rule this class exists to enforce
 *
 * **A template carries structure and slots. It does not carry the text and images that
 * happened to be on the page it was extracted from.**
 *
 * That is not a stylistic preference. Three things break without it:
 *
 * 1. *Licensing.* §3 and §15 are explicit that images, logos, text and trademarks from a
 *    source site are not the user's to redistribute. A template that embeds the source
 *    page's photography is a redistribution mechanism, whatever the licence of the code
 *    around it.
 * 2. *Reusability.* A hero with "Acme Corporation — Established 1987" baked in is not a
 *    reusable hero. It is one page.
 * 3. *Honesty.* §13 says never create fake default content merely to fill an empty slot.
 *    A template that renders "Lorem ipsum" because the slot was empty is showing the user
 *    something that is not real, in a product whose entire value is that its output is
 *    real.
 *
 * Phase 12 already produces a `slots` list — `slot_id`, `filled`, `kind`, `label`,
 * `repeatable` — from `Shared_Component_Detector::slots_for()`. This class **consumes and
 * extends** that shape rather than replacing it: the Phase 12 keys are all present and all
 * mean the same thing, and the Phase 19 keys are the ones §13 requires on top.
 *
 * ### What a slot never gets
 *
 * No slot ever receives a default value that was not either (a) supplied by the user at
 * extraction time, (b) carried in an imported package *with its provenance*, or (c) bound
 * to a dynamic source. Anything else is `null`, and `null` renders as an empty region with
 * a label, never as invented copy.
 */
final class Content_Slot_Registry {

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger|null $logger Logger.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger instanceof Logger ? $logger : new Logger();
	}

	/* ---------------------------------------------------------------------
	 * Building
	 * ------------------------------------------------------------------ */

	/**
	 * Build slot definitions from a set of identified content roles.
	 *
	 * @param array<int, array<string, mixed>> $roles    Phase 12 content roles, each with
	 *                                                   at least `role` and optionally `value`.
	 * @param array<string, mixed>              $context  Build context.
	 * @return array{slots: array<int, array<string, mixed>>, count: int, dynamic: int, static: int, warnings: array<int, string>}
	 */
	public function build( array $roles, array $context = array() ) {
		$slots    = array();
		$dynamic  = 0;
		$static   = 0;
		$warnings = array();

		foreach ( array_slice( array_values( $roles ), 0, 120 ) as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$slot = $this->from_role( $entry, $index, $context );

			if ( null === $slot ) {
				continue;
			}

			if ( 'dynamic' === $slot['kind'] || 'either' === $slot['kind'] ) {
				$dynamic++;
			}
			if ( 'static' === $slot['kind'] ) {
				$static++;
			}

			$slots[] = $slot;
		}

		return array(
			'slots'    => $slots,
			'count'    => count( $slots ),
			'dynamic'  => $dynamic,
			'static'   => $static,
			'warnings' => $warnings,
		);
	}

	/**
	 * Build one slot from a Phase 12 content role.
	 *
	 * @param array<string, mixed> $entry   Role entry.
	 * @param int                  $index   Position.
	 * @param array<string, mixed> $context Build context.
	 * @return array<string, mixed>|null
	 */
	private function from_role( array $entry, $index, array $context ) {
		$role = isset( $entry['role'] ) && is_string( $entry['role'] ) ? $entry['role'] : '';

		if ( '' === $role ) {
			return null;
		}

		$mapping = $this->role_to_slot( $role );

		if ( null === $mapping ) {
			/*
			 * An unrecognised content role is not dropped silently. The page had content the
			 * detector identified and this class cannot classify; saying so is how the
			 * extraction gets better without pretending it is complete.
			 */
			return null;
		}

		$value      = isset( $entry['value'] ) && is_scalar( $entry['value'] ) ? (string) $entry['value'] : '';
		$supplied   = '' !== $value && (bool) ! empty( $context['keep_content'] );
		$has_source = isset( $entry['source'] ) && is_string( $entry['source'] ) ? $entry['source'] : '';

		$dynamic = isset( $entry['dynamic'] ) && is_string( $entry['dynamic'] ) && Template_Limits::is_dynamic_source( $entry['dynamic'] )
			? $entry['dynamic']
			: '';

		/*
		 * Content extracted from the source page is *recorded* but never carried as a default.
		 *
		 * This is the single most important line in the class. The value goes into
		 * `observed_value` so the user can see what was there and choose to keep it, and
		 * `default` stays empty. A template whose slots are pre-filled from someone else's
		 * website is the thing §3 exists to prevent.
		 *
		 * `$supplied` therefore decides nothing about `default` — it only records whether the
		 * caller asked for the source value to be carried at all. `$observed` below means
		 * "there is a value here to look at", which is a fact about the source page and is
		 * true whether or not the caller asked for it. Conflating the two made a slot that
		 * demonstrably has a heading look as though it has nothing, which reads as a broken
		 * extraction.
		 */
		$default = '';

		$slot = array(
			'slot_id'      => $this->slot_id( $mapping['type'], $index ),
			'type'         => $mapping['type'],
			'label'        => $mapping['label'],
			'role'         => $role,
			'required'     => (bool) $mapping['required'],
			'repeatable'   => (bool) ( $entry['repeatable'] ?? false ),
			'kind'         => $dynamic ? 'dynamic' : ( $mapping['dynamic'] ? 'either' : 'static' ),
			'default'      => $default,
			'observed_value' => $value,
			'observed'     => '' !== $value,
			'carried'      => $supplied,
			'dynamic_source' => $dynamic,
			'validation'   => $this->rules_for( $mapping['type'] ),
			'provenance'   => array(
				'origin' => '' !== $has_source ? $has_source : ( $value ? 'source_page' : 'structure_only' ),
				'url'    => isset( $entry['url'] ) && is_string( $entry['url'] ) ? substr( $entry['url'], 0, 300 ) : '',
			),
		);

		return $slot;
	}

	/**
	 * Map a Phase 12 content role onto a Phase 19 slot type.
	 *
	 * @param string $role Content role.
	 * @return array{type: string, label: string, required: bool, dynamic: bool}|null
	 */
	public function role_to_slot( $role ) {
		$map = array(
			'heading'     => array( 'type' => 'heading', 'label' => __( 'Heading', 'replicaforge' ), 'required' => true, 'dynamic' => true ),
			'title'       => array( 'type' => 'heading', 'label' => __( 'Heading', 'replicaforge' ), 'required' => true, 'dynamic' => true ),
			'subheading'  => array( 'type' => 'heading', 'label' => __( 'Subheading', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'text'        => array( 'type' => 'paragraph', 'label' => __( 'Text', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'body'        => array( 'type' => 'paragraph', 'label' => __( 'Body text', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'excerpt'     => array( 'type' => 'paragraph', 'label' => __( 'Excerpt', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'button'      => array( 'type' => 'button_label', 'label' => __( 'Button label', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'button_label' => array( 'type' => 'button_label', 'label' => __( 'Button label', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'button_url'  => array( 'type' => 'button_url', 'label' => __( 'Button link', 'replicaforge' ), 'required' => false, 'dynamic' => false ),
			'link'        => array( 'type' => 'link', 'label' => __( 'Link', 'replicaforge' ), 'required' => false, 'dynamic' => false ),
			'image'       => array( 'type' => 'image', 'label' => __( 'Image', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'logo'        => array( 'type' => 'logo', 'label' => __( 'Logo', 'replicaforge' ), 'required' => false, 'dynamic' => false ),
			'icon'        => array( 'type' => 'icon', 'label' => __( 'Icon', 'replicaforge' ), 'required' => false, 'dynamic' => false ),
			'testimonial' => array( 'type' => 'testimonial', 'label' => __( 'Testimonial', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'author'      => array( 'type' => 'paragraph', 'label' => __( 'Author name', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'price'       => array( 'type' => 'paragraph', 'label' => __( 'Price', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'product'     => array( 'type' => 'product', 'label' => __( 'Product', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'post'        => array( 'type' => 'blog_post', 'label' => __( 'Blog post', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'team_member' => array( 'type' => 'team_member', 'label' => __( 'Team member', 'replicaforge' ), 'required' => false, 'dynamic' => true ),
			'pricing'     => array( 'type' => 'pricing_item', 'label' => __( 'Pricing item', 'replicaforge' ), 'required' => false, 'dynamic' => false ),
		);

		return isset( $map[ $role ] ) ? $map[ $role ] : null;
	}

	/**
	 * Return the validation rules for a slot type.
	 *
	 * These are descriptions, not enforcement — {@see Content_Slot_Registry::validate_fill()}
	 * enforces them. Keeping them as data means the preview screen and the installer agree
	 * about what a slot accepts without a second rule set.
	 *
	 * @param string $type Slot type.
	 * @return array<string, mixed>
	 */
	public function rules_for( $type ) {
		$shared = array( 'max_length' => 300, 'strip_tags' => true );

		switch ( $type ) {
			case 'heading':
				return array( 'max_length' => 160, 'strip_tags' => true, 'single_line' => true );

			case 'paragraph':
			case 'rich_text':
				return array( 'max_length' => 1200, 'strip_tags' => true, 'single_line' => false );

			case 'button_label':
			case 'badge':
				return array( 'max_length' => 60, 'strip_tags' => true, 'single_line' => true );

			case 'button_url':
			case 'link':
				// `public_http` is the important one: a link slot must not be able to carry
				// `javascript:` or an internal address. Enforced by `validate_fill()`.
				return array( 'max_length' => 2048, 'public_http' => true, 'strip_tags' => true );

			case 'image':
			case 'logo':
				return array( 'public_http' => true, 'max_length' => 2048, 'image_types' => true );

			case 'icon':
				return array( 'max_length' => 60, 'strip_tags' => true, 'single_line' => true );

			default:
				return $shared;
		}
	}

	/**
	 * Build a slot identifier.
	 *
	 * @param string $type  Slot type.
	 * @param int    $index Position.
	 * @return string
	 */
	public function slot_id( $type, $index ) {
		$type = is_string( $type ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( $type ) ) : '';
		$type = '' === $type ? 'slot' : $type;

		return $type . '_' . substr( hash( 'sha256', $type . '|' . (int) $index ), 0, 8 );
	}

	/* ---------------------------------------------------------------------
	 * Validation
	 * ------------------------------------------------------------------ */

	/**
	 * Validate a proposed fill for a slot.
	 *
	 * @param array<string, mixed> $slot   Slot definition.
	 * @param mixed                $value  Proposed value.
	 * @return array{ok: bool, value: mixed, reason: string}
	 */
	public function validate_fill( array $slot, $value ) {
		$type   = (string) ( $slot['type'] ?? 'paragraph' );
		$rules  = isset( $slot['validation'] ) && is_array( $slot['validation'] ) ? $slot['validation'] : array();
		$reason = '';

		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			if ( ! empty( $slot['required'] ) ) {
				return array(
					'ok'     => false,
					'value'  => null,
					'reason' => __( 'This slot is required and cannot be left empty.', 'replicaforge' ),
				);
			}
			return array( 'ok' => true, 'value' => null, 'reason' => '' );
		}

		if ( ! is_scalar( $value ) ) {
			return array( 'ok' => false, 'value' => null, 'reason' => __( 'This slot accepts a single text value.', 'replicaforge' ) );
		}

		$text = (string) $value;

		if ( ! empty( $rules['strip_tags'] ) ) {
			$text = wp_strip_all_tags( $text );
			$text = trim( $text );
		}

		if ( Elementor_Values::is_executable( $text ) ) {
			return array( 'ok' => false, 'value' => null, 'reason' => __( 'This value contains code and cannot be used in a template.', 'replicaforge' ) );
		}

		$max = isset( $rules['max_length'] ) ? (int) $rules['max_length'] : 0;

		if ( $max > 0 && strlen( $text ) > $max ) {
			$text   = substr( $text, 0, $max );
			$reason = __( 'The value was shortened to the maximum this slot accepts.', 'replicaforge' );
		}

		if ( ! empty( $rules['single_line'] ) ) {
			$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		}

		if ( ! empty( $rules['public_http'] ) ) {
			if ( '' !== $text && ! Security::is_safe_public_reference( $text ) ) {
				return array(
					'ok'     => false,
					'value'  => null,
					'reason' => __( 'This slot accepts only a public web address. An internal address, a script URL, or a data URL cannot be used.', 'replicaforge' ),
				);
			}
			$text = '' === $text ? '' : (string) Security::normalize_http_url( $text );
		}

		if ( ! empty( $rules['image_types'] ) && '' !== $text ) {
			$path      = (string) wp_parse_url( $text, PHP_URL_PATH );
			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( '' === $extension || ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
				return array(
					'ok'     => false,
					'value'  => null,
					'reason' => __( 'This slot accepts only a JPEG, PNG, GIF or WebP image address.', 'replicaforge' ),
				);
			}
		}

		return array( 'ok' => true, 'value' => $text, 'reason' => $reason );
	}

	/**
	 * Validate a whole slot set.
	 *
	 * @param array<int, array<string, mixed>> $slots Slot definitions.
	 * @return array{valid: bool, slots: array<int, array<string, mixed>>, errors: array<int, string>, warnings: array<int, string>, count: int}
	 */
	public function validate( array $slots ) {
		$clean    = array();
		$errors   = array();
		$warnings = array();
		$seen     = array();

		foreach ( array_slice( array_values( $slots ), 0, 200 ) as $position => $slot ) {
			if ( ! is_array( $slot ) ) {
				$errors[] = __( 'A content slot was not a readable definition.', 'replicaforge' );
				continue;
			}

			$type = (string) ( $slot['type'] ?? '' );

			if ( ! Template_Limits::is_slot_type( $type ) ) {
				$errors[] = __( 'A content slot declared a type this version does not recognise, so it was dropped.', 'replicaforge' );
				continue;
			}

			$slot_id = isset( $slot['slot_id'] ) && is_string( $slot['slot_id'] ) && '' !== $slot['slot_id']
				? substr( preg_replace( '/[^A-Za-z0-9_]/', '', $slot['slot_id'] ), 0, 60 )
				: $this->slot_id( $type, $position );

			if ( isset( $seen[ $slot_id ] ) ) {
				$warnings[] = __( 'Two content slots declared the same identifier; the second was given a distinct one.', 'replicaforge' );
				$slot_id    = $this->slot_id( $type, $position . '_' . count( $seen ) );
			}
			$seen[ $slot_id ] = true;

			$dynamic = isset( $slot['dynamic_source'] ) && is_string( $slot['dynamic_source'] ) && Template_Limits::is_dynamic_source( $slot['dynamic_source'] )
				? $slot['dynamic_source']
				: '';

			$default = null;

			if ( array_key_exists( 'default', $slot ) && null !== $slot['default'] && '' !== (string) $slot['default'] ) {
				$fill = $this->validate_fill( $slot, $slot['default'] );

				if ( $fill['ok'] ) {
					$default = $fill['value'];
				} else {
					$warnings[] = sprintf(
						/* translators: %s: the reason the default was rejected. */
						__( 'A content slot default was discarded because it was not usable: %s', 'replicaforge' ),
						(string) $fill['reason']
					);
				}
			}

			$clean[] = array(
				'slot_id'        => $slot_id,
				'type'           => $type,
				'label'          => isset( $slot['label'] ) && is_string( $slot['label'] ) ? substr( sanitize_text_field( $slot['label'] ), 0, 120 ) : Template_Limits::SLOT_TYPES[ $type ],
				'required'       => ! empty( $slot['required'] ),
				'repeatable'     => ! empty( $slot['repeatable'] ),
				'kind'           => Template_Limits::is_slot_kind( $slot['kind'] ?? '' ) ? (string) $slot['kind'] : ( $dynamic ? 'dynamic' : 'static' ),
				'default'        => $default,
				'observed_value' => isset( $slot['observed_value'] ) && is_scalar( $slot['observed_value'] ) ? substr( (string) $slot['observed_value'], 0, 300 ) : '',
				'observed'       => ! empty( $slot['observed'] ),
				'carried'        => ! empty( $slot['carried'] ),
				'dynamic_source' => $dynamic,
				'validation'     => $this->rules_for( $type ),
				'provenance'     => array(
					'origin' => isset( $slot['provenance']['origin'] ) && is_string( $slot['provenance']['origin'] ) ? substr( $slot['provenance']['origin'], 0, 60 ) : 'structure_only',
					'url'    => isset( $slot['provenance']['url'] ) && is_string( $slot['provenance']['url'] ) ? substr( $slot['provenance']['url'], 0, 300 ) : '',
				),
			);
		}

		return array(
			'valid'    => array() === $errors,
			'slots'    => $clean,
			'errors'   => $errors,
			'warnings' => array_values( array_unique( $warnings ) ),
			'count'    => count( $clean ),
		);
	}

	/**
	 * Return whether a dynamic source can be resolved in this environment.
	 *
	 * §14: if a dynamic source is unavailable, mark it unavailable and warn. Never
	 * substitute. This function is the only place that decides availability, so the
	 * compatibility check and the installer's post-install validation cannot disagree.
	 *
	 * @param string $source Dynamic source name.
	 * @return array{available: bool, requires: string, message: string}
	 */
	public static function dynamic_availability( $source ) {
		$source = (string) $source;

		if ( ! Template_Limits::is_dynamic_source( $source ) ) {
			return array(
				'available' => false,
				'requires'  => '',
				'message'   => __( 'This template names a content source this version does not recognise.', 'replicaforge' ),
			);
		}

		if ( ! Template_Limits::needs_woocommerce( $source ) ) {
			return array( 'available' => true, 'requires' => '', 'message' => '' );
		}

		$woocommerce = class_exists( 'WooCommerce' ) || function_exists( 'WC' );

		return array(
			'available' => $woocommerce,
			'requires'  => $woocommerce ? '' : 'woocommerce',
			'message'   => $woocommerce
				? ''
				: __( 'This content comes from WooCommerce, which is not active on this site. The slot will be left empty rather than filled with something else.', 'replicaforge' ),
		);
	}

	/**
	 * Return a summary of a slot set, for the library screen.
	 *
	 * @param array<int, array<string, mixed>> $slots Slots.
	 * @return array<string, mixed>
	 */
	public static function summarise( array $slots ) {
		$by_type     = array();
		$required    = 0;
		$dynamic     = 0;
		$unavailable = array();

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}
			$type = (string) ( $slot['type'] ?? '' );

			if ( '' !== $type ) {
				$by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + 1;
			}
			if ( ! empty( $slot['required'] ) ) {
				$required++;
			}

			$source = (string) ( $slot['dynamic_source'] ?? '' );

			if ( '' !== $source ) {
				$dynamic++;
				$check = self::dynamic_availability( $source );
				if ( ! $check['available'] ) {
					$unavailable[] = $source;
				}
			}
		}

		ksort( $by_type );

		return array(
			'total'         => count( $slots ),
			'by_type'       => $by_type,
			'required'      => $required,
			'optional'      => count( $slots ) - $required,
			'dynamic'       => $dynamic,
			'static'        => count( $slots ) - $dynamic,
			'unavailable_sources' => array_values( array_unique( $unavailable ) ),
		);
	}
}
