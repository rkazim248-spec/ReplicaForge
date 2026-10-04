<?php
/**
 * Phase 19: template sanitisation.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The security gate every template passes through, on the way in and on the way out.
 *
 * ### Why this exists as a separate gate
 *
 * `Elementor_Widget_Registry::is_allowed_widget()` decides whether a widget may be
 * **emitted by ReplicaForge**, and it is a blocklist plus a live registration check:
 *
 * ```php
 * return is_string( $widget_name )
 *     && '' !== $widget_name
 *     && ! in_array( $widget_name, $this->forbidden_widgets, true )   // html, shortcode, embed, html-tag, custom-html
 *     && $this->compatibility->has_widget( $widget_name );            // registered in this Elementor
 * ```
 *
 * That is the right rule for generation, where ReplicaForge chooses every widget name from
 * its own map. It is **the wrong rule for import**, where the widget name arrives from a
 * file. Because it is a blocklist, a template naming any widget that happens to be
 * registered on the target site — a third-party widget, a form plugin, a slider — passes.
 * A template is arbitrary input; arbitrary input needs an allowlist.
 *
 * So this class imposes one. The allowlist is ReplicaForge's own widget vocabulary —
 * `heading`, `text-editor`, `button`, `image`, `divider`, `spacer`, `icon`, and
 * `container` — read from `Elementor_Widget_Registry`'s own map rather than restated, so
 * the two cannot drift. A widget outside it is refused, **even if the target site has it
 * installed**, and the refusal is reported so the user learns which widget was dropped
 * rather than discovering a blank space later.
 *
 * ### It never executes, and never repairs
 *
 * There is no `eval`, no `unserialize`, no `call_user_func` on input, no shell, and no
 * repair path that "fixes" a dangerous value into a safe-looking one. A value that cannot
 * be made safe is dropped and reported. A sanitiser that rewrites is a sanitiser whose
 * output nobody can predict, and this output is fed straight into a page builder.
 *
 * ### It fails closed
 *
 * Every gate returns a reason. {@see Template_Sanitizer::scan()} returns the cleaned
 * document *and* the list of everything it removed, and a caller that ignores the removals
 * and installs anyway gets a document that is safe — just smaller than it expected. That is
 * the safe direction to fail.
 */
final class Template_Sanitizer {

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
	 * Maximum element nesting depth accepted.
	 *
	 * Matches `Site_Limits::MAX_DEPTH`, because a template deeper than the site
	 * representation that produced it is either corrupt or hostile, and an unbounded
	 * recursion over attacker-supplied nesting is a memory exhaustion vector.
	 *
	 * @var int
	 */
	const MAX_DEPTH = 8;

	/**
	 * Maximum elements accepted in one document.
	 *
	 * @var int
	 */
	const MAX_ELEMENTS = 2000;

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
	 * The allowlist
	 * ------------------------------------------------------------------ */

	/**
	 * Return the widget types a template may contain.
	 *
	 * Derived from `Elementor_Widget_Registry`'s own preferences map so the import allowlist
	 * and the generation vocabulary are the same list. If Phase 4 ever gains a widget
	 * mapping, an import of that widget starts working without a second edit here.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_widgets() {
		/*
		 * Read from the *registry's* vocabulary, not from a list typed here, so the import
		 * allowlist and the generation vocabulary cannot drift apart.
		 *
		 * A new method is added to `Elementor_Widget_Registry` that reports its own map.
		 * Reaching in with reflection worked but was worse in three ways: it hard-failed with
		 * a `ReflectionException` if the class were ever renamed or lazily loaded, it
		 * constructed a second registry instance with its own compatibility probe on every
		 * call, and a private-property read breaks the moment the property is made
		 * `readonly` or moved to a trait. An explicit accessor is one method on the class
		 * that owns the data, which is also where a future phase will extend it.
		 */
		$widgets = array( 'container' );

		if ( class_exists( 'ReplicaForge\\Elementor_Widget_Registry' ) ) {
			$registry = new \ReplicaForge\Elementor_Widget_Registry( new Elementor_Compatibility() );

			foreach ( (array) $registry->widget_vocabulary() as $widget ) {
				if ( is_string( $widget ) && '' !== $widget ) {
					$widgets[] = $widget;
				}
			}
		}

		return array_values( array_unique( $widgets ) );
	}

	/**
	 * Return whether a widget type may appear in a template.
	 *
	 * @param mixed $widget_name Candidate.
	 * @return bool
	 */
	public static function is_allowed_widget( $widget_name ) {
		if ( ! is_string( $widget_name ) || '' === $widget_name ) {
			return false;
		}

		return in_array( $widget_name, self::allowed_widgets(), true );
	}

	/**
	 * Return the Elementor settings keys a template may carry.
	 *
	 * ### Why an allowlist, given the generator does not need one
	 *
	 * `Elementor_Document_Builder::scrub()` filters setting keys by *shape* —
	 * `/^[a-z0-9_\-\[\]]{1,60}$/i` — and drops executable values. Shape-filtering is right
	 * when ReplicaForge built the settings and knows the names. On import, a
	 * shape-valid key is not enough: `_elementor_custom` or a plugin's own control could
	 * carry a script, and a shape check cannot tell that from a padding setting.
	 *
	 * So the allowlist is the set of controls ReplicaForge itself writes, which is a closed
	 * and small set. A setting outside it is dropped and reported.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_settings() {
		return array(
			// Typography and text.
			'title', 'header_size', 'align', 'text_color', 'link_color', 'typography',
			'font_family', 'font_size', 'font_weight', 'text_transform', 'line_height',
			'letter_spacing', 'editor', 'drop_cap', 'columns', 'max_width',
			// Colour and background.
			'color', 'background_color', 'background_background', 'background_image',
			'background_position', 'background_size', 'background_repeat', 'background_attachment',
			'background_overlay_background', 'background_overlay_color', 'background_overlay_size',
			'background_overlay_position', 'border_color', 'border_width', 'border_style',
			'border_border', 'border_radius', 'box_shadow', 'box_shadow_box', 'box_shadow_link',
			// Spacing and size.
			'margin', 'padding', 'width', 'space_between', 'content_width', 'custom_width',
			'min_height', 'min_height_unit', 'size', 'gap', 'row_gap', 'column_gap',
			'flex_direction', 'flex_wrap', 'justify_content', 'align_items', 'align_content',
			// Media.
			'image', 'image_size', 'link_to', 'caption_source', 'html_tag', 'image_alignment',
			// Buttons and links.
			'text', 'link', 'size', 'button_type', 'align', 'icon_type', 'icon',
			'icon_align', 'hover_animation', 'text_color_hover', 'background_hover',
			// Structure.
			'content_width', 'html_tag', 'stretch_section', 'layout', 'css_classes',
			'responsive', 'hide_desktop', 'hide_tablet', 'hide_mobile',
			// Slider and tab containers ReplicaForge emits as container structures.
			'slides_per_view', 'slides_per_view_tablet', 'slides_per_view_mobile',
			'tabs', 'tab_title', 'tab_content',
		);
	}

	/**
	 * Return the Elementor setting keys a template may *not* carry, ever.
	 *
	 * A second, explicit list even though the allowlist already excludes everything
	 * unrecognised. The reason is diagnosability: a template carrying
	 * `_elementor_custom` is a different problem from a template carrying
	 * `padding_typo`, and reporting them differently is what tells a user whether the
	 * template was hostile or merely from a different tool.
	 *
	 * @return array<int, string>
	 */
	public static function forbidden_settings() {
		return array(
			'_elementor_data',
			'_elementor_edit_mode',
			'_elementor_controls',
			'_elementor_page_settings',
			'_elementor_css',
			'_elementor_css_printing',
			'_elementor_template_type',
			'_elementor_version',
			'elType',
			'widgetType',
			'shortcode',
			'html',
			'html_widget',
			'wp_nonce',
			'_wpnonce',
			'nonce',
			'api_key',
			'api_secret',
			'authorization',
			'password',
			'php',
			'eval',
			'exec',
			'include',
			'require',
			'wp_query',
			'wp_user_query',
		);
	}

	/* ---------------------------------------------------------------------
	 * Document scan
	 * ------------------------------------------------------------------ */

	/**
	 * Scan and clean an Elementor document from a template.
	 *
	 * @param array<int, mixed> $elements Document elements.
	 * @return array{ok: bool, elements: array<int, array<string, mixed>>, kept: int, dropped: int, removals: array<int, array<string, mixed>>, depth: int, fatal: array<int, string>}
	 */
	public function scan( array $elements ) {
		$out       = array();
		$removals  = array();
		$fatal     = array();
		$kept      = 0;
		$dropped   = 0;
		$depth_max = 0;

		$walk = function ( array $list, $depth ) use ( &$walk, &$out, &$removals, &$fatal, &$kept, &$dropped, &$depth_max ) {
			$result = array();

			foreach ( $list as $element ) {
				if ( ! is_array( $element ) ) {
					$dropped++;
					$removals[] = array( 'path' => '', 'kind' => 'element', 'reason' => 'not_an_object' );
					continue;
				}

				if ( $depth > self::MAX_DEPTH ) {
					$dropped++;
					$removals[] = array( 'path' => '', 'kind' => 'element', 'reason' => 'nesting_too_deep' );
					continue;
				}

				$id      = (string) ( $element['id'] ?? '' );
				$el_type = (string) ( $element['elType'] ?? '' );
				$widget  = (string) ( $element['widgetType'] ?? '' );

				if ( '' === $el_type || ! in_array( $el_type, array( 'container', 'widget' ), true ) ) {
					$dropped++;
					$removals[] = array( 'path' => $id, 'kind' => 'element', 'reason' => 'unknown_element_type' );
					continue;
				}

				if ( 'widget' === $el_type && ! self::is_allowed_widget( $widget ) ) {
					/*
					 * The allowlist decision, and the whole point of this class. The widget is
					 * refused even when the target site has it registered, and the reason says
					 * *which* widget so the user can see what was lost.
					 */
					$registered = $this->elementor->has_widget( $widget );
					$dropped++;
					$removals[] = array(
						'path'       => $id,
						'kind'       => 'widget',
						'widget'     => $widget,
						'reason'     => $registered ? 'widget_not_in_allowlist' : 'widget_not_available',
						'registered' => $registered,
					);
					continue;
				}

				$settings = isset( $element['settings'] ) && is_array( $element['settings'] )
					? $this->clean_settings( $element['settings'], $id, $removals )
					: array();

				$clean = array(
					'id'       => $this->clean_id( $id ),
					'elType'   => $el_type,
					'settings' => $settings,
					'elements' => array(),
				);

				if ( 'widget' === $el_type ) {
					$clean['widgetType'] = $widget;
				} else {
					$clean['isInner'] = ! empty( $element['isInner'] );
				}

				$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();

				if ( array() !== $children ) {
					$clean['elements'] = $walk( $children, $depth + 1 );
				}

				$depth_max = max( $depth_max, $depth );
				$kept++;
				$result[] = $clean;

				if ( $kept >= self::MAX_ELEMENTS ) {
					$fatal[] = 'element_limit_reached';
					break;
				}
			}

			return $result;
		};

		$out = $walk( array_slice( array_values( $elements ), 0, self::MAX_ELEMENTS ), 1 );

		/*
		 * A document that lost everything is refused rather than returned empty. An empty
		 * document that installs successfully produces a blank page, and a blank page from a
		 * valid-looking import is the worst possible outcome — the user has no way to tell
		 * that the import was refused.
		 */
		if ( 0 === $kept ) {
			$fatal[] = 'no_elements_survived';
		}

		return array(
			'ok'       => array() === $fatal,
			'elements' => $out,
			'kept'     => $kept,
			'dropped'  => $dropped,
			'removals' => $removals,
			'depth'    => $depth_max,
			'fatal'    => $fatal,
		);
	}

	/**
	 * Clean one element's settings.
	 *
	 * @param array<string, mixed> $settings  Raw settings.
	 * @param string                $path      Element id, for reporting.
	 * @param array<int, array<string, mixed>> $removals Removal collector, by reference.
	 * @return array<string, mixed>
	 */
	private function clean_settings( array $settings, $path, array &$removals ) {
		$allowed  = self::allowed_settings();
		$forbidden = self::forbidden_settings();
		$out      = array();

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			$needle = strtolower( $key );

			if ( in_array( $needle, $forbidden, true ) || in_array( strtolower( $key ), $forbidden, true ) ) {
				$removals[] = array( 'path' => $path, 'kind' => 'setting', 'key' => $key, 'reason' => 'forbidden_setting' );
				continue;
			}

			if ( ! in_array( $key, $allowed, true ) ) {
				$removals[] = array( 'path' => $path, 'kind' => 'setting', 'key' => $key, 'reason' => 'setting_not_allowlisted' );
				continue;
			}

			$clean = $this->clean_setting_value( $key, $value, self::is_url_setting( $key ) );

			if ( null === $clean ) {
				$removals[] = array( 'path' => $path, 'kind' => 'setting', 'key' => $key, 'reason' => self::is_url_setting( $key ) ? 'unsafe_url' : 'unsafe_value' );
				continue;
			}

			/*
			 * A setting that had content and now has none was *removed*, so it is dropped
			 * rather than left as an empty shell.
			 *
			 * `image => array( 'url' => 'http://10.0.0.5/x.png' )` cleans to `array()`,
			 * and storing `image => array()` hands Elementor a control whose value is an
			 * empty object — which renders as a broken-image icon in the editor and is
			 * indistinguishable from a genuinely empty setting. An empty result where the
			 * input was not empty is a removal, so it is counted as one.
			 */
			if ( is_array( $value ) && array() === $clean && array() !== $value ) {
				$removals[] = array( 'path' => $path, 'kind' => 'setting', 'key' => $key, 'reason' => self::is_url_setting( $key ) ? 'unsafe_url' : 'unsafe_value' );
				continue;
			}

			$out[ $key ] = $clean;
		}

		return $out;
	}

	/**
	 * Clean one setting value.
	 *
	 * Every branch delegates to `Elementor_Values`, which is the plugin's one CSS-value
	 * policy. A template becomes CSS the moment it is installed, so the check applied here
	 * has to be the same one that will apply to the value later — otherwise a template can
	 * store something that only fails on install.
	 *
	 * @param string $key    Setting key.
	 * @param mixed  $value  Raw value.
	 * @param bool   $is_url Whether every string in this value is a web address.
	 * @return mixed|null Null when the value cannot be made safe.
	 */
	private function clean_setting_value( $key, $value, $is_url = false ) {
		if ( is_array( $value ) ) {
			$out  = array();
			$walk = function ( $node, $depth ) use ( &$walk, &$out, $is_url ) {
				if ( $depth > 4 ) {
					return null;
				}
				if ( is_array( $node ) ) {
					$branch = array();
					foreach ( $node as $k => $v ) {
						$clean = $walk( $v, $depth + 1 );
						if ( null !== $clean ) {
							$branch[ $k ] = $clean;
						}
					}
					return $branch;
				}
				if ( is_bool( $node ) || is_int( $node ) || is_float( $node ) ) {
					return $node;
				}
				if ( ! is_string( $node ) ) {
					return null;
				}
				return $this->clean_scalar( $node, $is_url );
			};
			$clean = $walk( $value, 0 );
			return is_array( $clean ) ? $clean : null;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		return $this->clean_scalar( $value, $is_url );
	}

	/**
	 * Return the settings whose value is, or contains, a web address.
	 *
	 * These are the only settings a template can use to make the browser or the server fetch
	 * something. Every other setting is a colour, a length, a number, or text — none of which
	 * can cause a request.
	 *
	 * @return array<int, string>
	 */
	public static function url_settings() {
		return array( 'image', 'background_image', 'link', 'url', 'video_url', 'poster' );
	}

	/**
	 * Return whether a setting is, or contains, a web address.
	 *
	 * The check is on the *setting*, not on the value, because the value's shape is not
	 * reliable — `image` is sometimes a string, sometimes a list of strings, sometimes an
	 * array of objects each with a `url`. Shape-sniffing the value is how a `javascript:`
	 * link inside a nested structure gets missed.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_url_setting( $key ) {
		$key = is_string( $key ) ? strtolower( $key ) : '';

		/*
		 * A suffix match as well as an exact one, so `background_image` and `image` are both
		 * covered by naming only `image`, and a third-party control called `hero_image_url`
		 * is caught too. The allowlist already limits which settings exist, so a suffix rule
		 * cannot widen what is accepted — it only widens what is *inspected*.
		 */
		foreach ( self::url_settings() as $needle ) {
			if ( $key === $needle || ( '' !== $key && str_ends_with( $key, '_' . $needle ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clean a scalar setting value, by what the setting is for.
	 *
	 * @param string $value Raw value.
	 * @param bool   $is_url Whether this value is a web address.
	 * @return string|null
	 */
	private function clean_scalar( $value, $is_url = false ) {
		if ( strlen( $value ) > 4000 ) {
			return null;
		}

		if ( Elementor_Values::is_executable( $value ) ) {
			return null;
		}

		// A setting that names a file or a path never survives a template. There is no
		// legitimate reason for a portable design asset to carry a filesystem path, and
		// allowing one is a path-traversal vector with a plausible disguise.
		if ( preg_match( '#^[A-Za-z]:[\\\\/]#', $value ) || false !== strpos( $value, '../' ) || false !== strpos( $value, '..\\' ) ) {
			return null;
		}

		/*
		 * A web address is re-validated through the SSRF boundary.
		 *
		 * This was missing, and it mattered. `is_executable()` catches `javascript:` and
		 * `data:text/html`, and the path check above catches traversal — but neither knows
		 * anything about `http://169.254.169.254/latest/meta-data/`, which is a perfectly
		 * ordinary http URL that reaches a cloud metadata service, or about
		 * `http://10.0.0.5/`, which reaches inside the network.
		 *
		 * `Elementor_Validator::scan_settings()` only rejects a handful of *schemes*, so
		 * neither of those was stopped anywhere in the pipeline. On a site that renders
		 * previews — and Phase 13's `Visual_Renderer` does exactly that — an imported
		 * template could make the server request an internal address.
		 *
		 * `Security::is_safe_public_reference()` is the existing SSRF boundary and is the
		 * only thing that answers this question correctly, so it is what runs. An empty value
		 * is left alone: an empty link is a link to nowhere, not a request.
		 */
		if ( $is_url && '' !== trim( $value ) && ! Security::is_safe_public_reference( $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Reduce an element id to a safe, unique-in-document form.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private function clean_id( $value ) {
		$id = is_string( $value ) ? preg_replace( '/[^A-Za-z0-9]/', '', $value ) : '';

		return '' === $id ? substr( md5( (string) microtime( true ) . wp_rand() ), 0, 7 ) : substr( $id, 0, 20 );
	}

	/* ---------------------------------------------------------------------
	 * Snapshot scan
	 * ------------------------------------------------------------------ */

	/**
	 * Scan a whole template snapshot — every section, not just the document.
	 *
	 * A template carries more than an Elementor document: assets, tokens, content slots,
	 * dependencies and provenance all arrive from the same untrusted file, and each has its
	 * own hazard. A scanner that only looked at the document would be the exact mistake
	 * that let a package smuggle data past the gate.
	 *
	 * @param array<string, mixed> $snapshot Snapshot.
	 * @return array<string, mixed>
	 */
	public function scan_snapshot( array $snapshot ) {
		$report = array(
			'ok'         => true,
			'removals'   => array(),
			'assets'     => array(),
			'provenance' => array(),
			'fatal'      => array(),
		);

		$document = isset( $snapshot['document'] ) && is_array( $snapshot['document'] ) ? $snapshot['document'] : array();
		$scan     = $this->scan( is_array( $document['elements'] ?? null ) ? $document['elements'] : $document );

		$report['ok']        = $scan['ok'];
		$report['removals']  = $scan['removals'];
		$report['document']  = $scan['elements'];
		$report['fatal']     = $scan['fatal'];

		/*
		 * Assets. Every URL is re-validated through `Url_Validator`, which is the SSRF
		 * boundary, and the provenance class decides whether the asset may travel at all.
		 *
		 * §15 and §3 are enforced here and not in the UI: a `source_derived` or
		 * `third_party` asset is downgraded to `reference` mode, which keeps the template
		 * working while removing the redistribution. A template is never made unusable
		 * because an asset could not be included — it is made honest about which assets it
		 * points at instead of shipping.
		 */
		$assets = isset( $snapshot['assets'] ) && is_array( $snapshot['assets'] ) ? $snapshot['assets'] : array();

		foreach ( $assets as $id => $asset ) {
			if ( ! is_array( $asset ) ) {
				$report['removals'][] = array( 'path' => (string) $id, 'kind' => 'asset', 'reason' => 'not_an_object' );
				continue;
			}

			$url = isset( $asset['url'] ) && is_string( $asset['url'] ) ? $asset['url'] : '';

			if ( '' === $url ) {
				$report['removals'][] = array( 'path' => (string) $id, 'kind' => 'asset', 'reason' => 'no_url' );
				continue;
			}

			if ( ! Security::is_safe_public_reference( $url ) ) {
				$report['removals'][] = array( 'path' => (string) $id, 'kind' => 'asset', 'reason' => 'unsafe_url' );
				continue;
			}

			$provenance = isset( $asset['provenance_class'] ) && Template_Limits::is_asset_provenance( $asset['provenance_class'] )
				? (string) $asset['provenance_class']
				: 'third_party';

			$mode = isset( $asset['mode'] ) && in_array( (string) $asset['mode'], Template_Limits::ASSET_MODES, true )
				? (string) $asset['mode']
				: 'reference';

			/*
			 * Unknown rights are treated as no rights. A package that omits the provenance
			 * field gets `third_party`, which is not redistributable — so the fail-closed
			 * direction is the default rather than something a malformed file has to
			 * defeat.
			 */
			if ( 'import' === $mode && ! Template_Limits::is_redistributable( $provenance ) ) {
				$mode                                          = 'reference';
				$report['removals'][]                        = array(
					'path'   => (string) $id,
					'kind'   => 'asset',
					'reason' => 'redistribution_not_permitted',
					'note'   => 'downgraded_to_reference',
				);
			}

			$report['assets'][ $id ] = array(
				'asset_id'         => isset( $asset['asset_id'] ) && is_scalar( $asset['asset_id'] ) ? substr( (string) $asset['asset_id'], 0, 64 ) : '',
				'url'              => substr( $url, 0, 2048 ),
				'type'             => isset( $asset['type'] ) && in_array( (string) $asset['type'], Template_Limits::ASSET_TYPES, true ) ? (string) $asset['type'] : 'image',
				'mime_type'        => isset( $asset['mime_type'] ) && is_scalar( $asset['mime_type'] ) ? substr( (string) $asset['mime_type'], 0, 120 ) : '',
				'provenance_class' => $provenance,
				'mode'             => $mode,
				'redistributable'  => Template_Limits::is_redistributable( $provenance ),
				'ownership'        => isset( $asset['ownership'] ) && is_string( $asset['ownership'] ) ? substr( $asset['ownership'], 0, 60 ) : '',
				'licence'          => isset( $asset['licence'] ) && is_scalar( $asset['licence'] ) ? substr( (string) $asset['licence'], 0, 120 ) : '',
			);
		}

		/*
		 * Provenance. A package's provenance block is *descriptive*, so it is reduced to
		 * scalars and an allowlist of keys. It must not be able to claim a phase, a schema
		 * or a generator this version does not recognise, because those fields feed the
		 * schema check and a forged one would defeat it.
		 */
		$provenance = isset( $snapshot['provenance'] ) && is_array( $snapshot['provenance'] ) ? $snapshot['provenance'] : array();

		$report['provenance'] = array(
			'origin'     => isset( $provenance['origin'] ) && is_string( $provenance['origin'] ) ? substr( $provenance['origin'], 0, 60 ) : 'imported',
			'author'     => isset( $provenance['author'] ) && is_scalar( $provenance['author'] ) ? substr( (string) $provenance['author'], 0, 120 ) : '',
			'url'        => isset( $provenance['url'] ) && is_string( $provenance['url'] ) && Security::is_safe_public_reference( $provenance['url'] ) ? substr( $provenance['url'], 0, 300 ) : '',
			'created_at' => isset( $provenance['created_at'] ) && is_string( $provenance['created_at'] ) ? substr( $provenance['created_at'], 0, 40 ) : '',
			'licence'    => isset( $provenance['licence'] ) && is_scalar( $provenance['licence'] ) ? substr( (string) $provenance['licence'], 0, 120 ) : '',
		);

		/*
		 * Tokens and content slots go through their own validators, because a token is a CSS
		 * value and a slot default is a user-visible string — neither is a document setting,
		 * and neither is covered by the document scan.
		 */
		if ( isset( $snapshot['tokens'] ) && is_array( $snapshot['tokens'] ) ) {
			$registry = new Design_Token_Registry( $this->logger );
			$clean    = array();

			foreach ( $snapshot['tokens'] as $token_id => $token ) {
				if ( ! is_array( $token ) ) {
					$report['removals'][] = array( 'path' => (string) $token_id, 'kind' => 'token', 'reason' => 'not_an_object' );
					continue;
				}

				$category = isset( $token['category'] ) && Template_Limits::is_token_category( $token['category'] ) ? (string) $token['category'] : '';
				$value    = $registry->clean_value( $token['value'] ?? null, $category );

				if ( '' === $category || null === $value ) {
					$report['removals'][] = array( 'path' => (string) $token_id, 'kind' => 'token', 'reason' => 'unsafe_value' );
					continue;
				}

				$clean[ (string) $token_id ] = array(
					'token_id'   => Design_Token_Registry::token_id( $category, (string) ( $token['name'] ?? $token_id ) ),
					'name'       => isset( $token['name'] ) && is_string( $token['name'] ) ? substr( $token['name'], 0, 80 ) : (string) $token_id,
					'category'   => $category,
					'value'      => $value,
					'unit'       => Design_Token_Registry::unit_for( $value ),
					'source'     => Template_Limits::is_token_source( $token['source'] ?? '' ) ? (string) $token['source'] : 'imported_package',
					'confidence' => isset( $token['confidence'] ) && is_numeric( $token['confidence'] ) ? max( 0.0, min( 1.0, (float) $token['confidence'] ) ) : Template_Limits::NOT_MEASURED,
					'scope'      => Template_Limits::is_token_scope( $token['scope'] ?? '' ) ? (string) $token['scope'] : 'design_system',
					'ownership'  => Template_Limits::is_token_ownership( $token['ownership'] ?? '' ) ? (string) $token['ownership'] : 'replicaforge_controlled',
					'editable'   => true,
					'usage_count' => isset( $token['usage_count'] ) ? max( 0, (int) $token['usage_count'] ) : 0,
					'role'       => Template_Limits::is_semantic_role( $token['role'] ?? '' ) ? (string) $token['role'] : '',
				);
			}

			$snapshot['tokens'] = $clean;
		}

		if ( isset( $snapshot['content_slots'] ) && is_array( $snapshot['content_slots'] ) ) {
			$slots = ( new Content_Slot_Registry( $this->logger ) )->validate( $snapshot['content_slots'] );

			if ( ! $slots['valid'] ) {
				$report['fatal'] = array_merge( $report['fatal'], $slots['errors'] );
				$report['ok']    = false;
			}

			foreach ( $slots['warnings'] as $warning ) {
				$report['removals'][] = array( 'path' => '', 'kind' => 'slot', 'reason' => $warning );
			}

			$snapshot['content_slots'] = $slots['slots'];
		}

		$report['snapshot'] = $snapshot;

		return $report;
	}
}
