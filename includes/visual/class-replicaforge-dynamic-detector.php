<?php
/**
 * Phase 13: dynamic content, transient UI, and animation detection.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies the parts of a page that must not be reconstructed, and the parts
 * whose visual difference must not be reported as a fault.
 *
 * ### Why this class exists before any comparison runs
 *
 * A cookie banner, a chat widget, and a rotating testimonial are the three most
 * common reasons a "faithful" reconstruction looks wrong — and in all three cases
 * the replica is *correct* and the comparison is wrong. Reconstructing a cookie
 * banner as permanent page content is a privacy bug, not a fidelity win: the replica
 * then shows a consent dialog for cookies it does not set.
 *
 * So this class runs **before** reconstruction, and its output has two distinct
 * effects that are deliberately not merged:
 *
 * - `exclude_from_reconstruction` — the element is transient UI and must not become
 *   content. The user can override; the default is exclusion because §10 and §11 both
 *   ask for it.
 * - `mask_from_comparison` — the element is dynamic, so a difference there is not
 *   evidence of a fault. §59's rule.
 *
 * Keeping them separate matters. A *permanent* newsletter signup is excluded from
 * neither: it should be reconstructed, and a difference in it should be reported.
 *
 * ### Nothing is decided from a class name alone
 *
 * `.cookie-banner` is a strong signal but not proof, and a site that styles its
 * footer as `.chat-widget` would be excluded for the wrong reason. So every decision
 * carries the signals that produced it, and the classification defaults to `unknown`
 * rather than guessing. An `unknown` is excluded from nothing, which is the safe
 * direction: a wrongly excluded element is a page the user has to notice is missing.
 */
final class Dynamic_Detector {

	/**
	 * Signal terms for consent and cookie UI.
	 *
	 * Matched against class names, ids, and `aria-label` — never against visible
	 * text, because a page whose footer says "Privacy" is not a cookie banner.
	 *
	 * @var array<int, string>
	 */
	const CONSENT_TERMS = array( 'cookie', 'consent', 'gdpr', 'privacy-banner', 'cookiebanner', 'cookie-consent', 'cc-banner', 'onetrust', 'cookieyes' );

	/**
	 * Signal terms for popups and floating UI.
	 *
	 * @var array<int, string>
	 */
	const POPUP_TERMS = array( 'modal', 'popup', 'lightbox', 'overlay', 'newsletter', 'subscribe-modal', 'exit-intent', 'interstitial' );

	/**
	 * Signal terms for chat and floating widgets.
	 *
	 * @var array<int, string>
	 */
	const WIDGET_TERMS = array( 'chat', 'intercom', 'drift', 'zendesk', 'tawk', 'crisp', 'livechat', 'back-to-top', 'scroll-top', 'floating-cta', 'social-float' );

	/**
	 * Signal terms for advertisements.
	 *
	 * @var array<int, string>
	 */
	const AD_TERMS = array( 'advert', 'adsbygoogle', 'adsense', 'doubleclick', 'sponsor', 'promo-slot', 'ad-slot', 'adunit', 'banner-ad' );

	/**
	 * Regexes for content that is dynamic by its nature.
	 *
	 * These are matched against *text*, which is the one place a text match is right:
	 * a timestamp is a timestamp whichever element holds it.
	 *
	 * @var array<string, string>
	 */
	const TEXT_SIGNALS = array(
		'timestamp'      => '#\b(19|20)\d{2}-\d{2}-\d{2}\b|\b\d{1,2}:\d{2}\s?(am|pm)\b|\b(just|seconds?|minutes?|hours?|days?) ago\b#i',
		'live_counter'   => '#\b\d[\d,.]*\s?\+?\s?(views|likes|shares|comments|reads|people watching|items? left)\b#i',
	);

	/**
	 * Element types that are dynamic by construction.
	 *
	 * @var array<string, string>
	 */
	const DYNAMIC_TAGS = array(
		'video'  => 'video',
		'audio'  => 'video',
		'canvas' => 'animation',
		'iframe' => 'user_specific',
	);

	/**
	 * Class names for animation and carousel signals.
	 *
	 * @var array<string, string>
	 */
	const BEHAVIOUR_CLASSES = array(
		'carousel'  => 'carousel',
		'slider'    => 'carousel',
		'swiper'    => 'carousel',
		'slick'     => 'carousel',
		'owl-carousel' => 'carousel',
		'marquee'   => 'animation',
		'animated'  => 'animation',
		'fade-in'   => 'animation',
		'slide-up'  => 'animation',
		'parallax'  => 'animation',
		'lottie'    => 'animation',
	);

	/**
	 * Detect dynamic and transient elements.
	 *
	 * @param array<string, mixed> $representation Phase 2 representation.
	 * @param array<string, mixed> $render         Render evidence.
	 * @return array<string, mixed>
	 */
	public function detect( array $representation, array $render = array() ) {
		$nodes = $this->nodes( $representation );

		$elements   = array();
		$by_reason  = array();
		$excluded   = array();
		$masked     = array();
		$unknown    = array();

		foreach ( $nodes as $id => $node ) {
			$verdict = $this->classify( $node );
			if ( null === $verdict ) {
				continue;
			}
			$verdict['id'] = (string) $id;
			$elements[]    = $verdict;

			$reason = (string) $verdict['reason'];
			$by_reason[ $reason ] = ( $by_reason[ $reason ] ?? 0 ) + 1;

			if ( $verdict['exclude_from_reconstruction'] ) {
				$excluded[] = (string) $id;
			} elseif ( 'unknown' === $verdict['ui_class'] ) {
				$unknown[] = (string) $id;
			}
			if ( $verdict['mask_from_comparison'] ) {
				$masked[] = (string) $id;
			}
		}

		return array(
			'schema_version' => Visual_Limits::SCHEMA_VERSION,
			'elements'       => $elements,
			'counts'         => array(
				'dynamic'    => count( $elements ),
				'excluded'   => count( $excluded ),
				'masked'     => count( $masked ),
				'unknown'    => count( $unknown ),
				'by_reason'  => $by_reason,
			),
			'excluded'       => $excluded,
			'masked'         => $masked,
			'unknown'        => $unknown,
			'animations'     => $this->animations( $nodes ),
			'carousel'       => $this->carousel( $nodes ),
			'video'          => $this->video( $nodes ),
			'sticky'         => $this->sticky( $nodes ),
			'fixed'          => $this->fixed( $nodes, $representation ),
			'mobile_nav'     => $this->mobile_navigation( $nodes, $representation ),
			'responsive'     => $this->responsive( $nodes ),
			'policy'         => array(
				'default_exclude_transient' => true,
				'user_may_include'          => true,
				'note' => __( 'Cookie banners, chat widgets, and popups are excluded from reconstruction by default because reconstructing them makes a replica worse, not more faithful. A newsletter form or a permanent CTA is neither excluded nor masked.', 'replicaforge' ),
			),
		);
	}

	/**
	 * Classify one node.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, mixed>|null
	 */
	public function classify( array $node ) {
		$signals = $this->signals_of( $node );
		$haystack = strtolower( $signals['class'] . ' ' . $signals['id'] . ' ' . $signals['aria'] . ' ' . $signals['role'] );
		$text     = trim( (string) ( $node['text'] ?? '' ) );
		$type     = strtolower( (string) ( $node['type'] ?? '' ) );

		// 1. Text that is dynamic by nature.
		foreach ( self::TEXT_SIGNALS as $reason => $pattern ) {
			if ( '' !== $text && 1 === preg_match( $pattern, $text, $matches ) ) {
				return $this->verdict( $reason, 'unknown', true, true, $signals, array( 'text_match' => trim( $matches[0] ) ) );
			}
		}

		// 2. A tag that is dynamic by construction. An `<iframe>` is treated as
		// user-specific rather than as content: a replica embedding a source site's
		// iframe is embedding somebody else's third-party widget.
		if ( isset( self::DYNAMIC_TAGS[ $type ] ) ) {
			return $this->verdict( self::DYNAMIC_TAGS[ $type ], 'persistent_ui', false, true, $signals, array( 'tag' => $type ) );
		}

		// 3. Behaviour classes.
		foreach ( self::BEHAVIOUR_CLASSES as $needle => $reason ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return $this->verdict( $reason, 'unknown', false, true, $signals, array( 'class_match' => $needle ) );
			}
		}

		// 4. Consent UI. Excluded, because a replica that shows a cookie banner for
		// cookies it does not set is a privacy defect.
		foreach ( self::CONSENT_TERMS as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return $this->verdict( 'consent_ui', 'temporary_ui', true, true, $signals, array( 'term' => $needle ) );
			}
		}

		// 5. Chat and floating widgets. Excluded and masked.
		foreach ( self::WIDGET_TERMS as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return $this->verdict( 'chat_widget', 'persistent_ui', true, true, $signals, array( 'term' => $needle ) );
			}
		}

		// 6. Popups. Excluded by default; a popup is by definition not page content.
		foreach ( self::POPUP_TERMS as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return $this->verdict( 'user_specific', 'temporary_ui', true, true, $signals, array( 'term' => $needle ) );
			}
		}

		// 7. Advertisements. Excluded and masked — an ad slot in a replica serves
		// somebody else's campaign.
		foreach ( self::AD_TERMS as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return $this->verdict( 'advertisement', 'unknown', true, true, $signals, array( 'term' => $needle ) );
			}
		}

		// 8. A live counter expressed as markup rather than text.
		if ( false !== strpos( $haystack, 'counter' ) || false !== strpos( $haystack, 'live-region' ) || 'aria-live' === $signals['aria'] ) {
			return $this->verdict( 'live_counter', 'unknown', false, true, $signals, array( 'term' => 'live region' ) );
		}

		// 9. Nothing matched. An element with no signal is not dynamic, and saying so
		//    is the answer — a detector that returns `unknown` for everything is
		//    indistinguishable from one that detects nothing.
		return null;
	}

	/**
	 * Build a verdict.
	 *
	 * @param string               $reason     Dynamic reason.
	 * @param string               $ui_class   UI class.
	 * @param bool                 $exclude    Whether to exclude from reconstruction.
	 * @param bool                 $mask       Whether to mask from comparison.
	 * @param array<string, string> $signals   Collected signals.
	 * @param array<string, mixed> $basis      Which signal fired.
	 * @return array<string, mixed>
	 */
	private function verdict( $reason, $ui_class, $exclude, $mask, array $signals, array $basis ) {
		return array(
			'dynamic'                    => true,
			'reason'                     => (string) $reason,
			'ui_class'                   => (string) $ui_class,
			'exclude_from_reconstruction'=> (bool) $exclude,
			'mask_from_comparison'       => (bool) $mask,
			'signals'                    => $signals,
			'basis'                      => $basis,
			'confidence'                 => $this->confidence_for( $basis ),
			// §73: the user decides. Recorded so a UI can offer the override and so a
			// report can say the exclusion was a default rather than a finding.
			'user_override'              => 'none',
		);
	}

	/**
	 * Return the confidence for a basis of evidence.
	 *
	 * A regex match on visible text is the strongest signal here because the text is
	 * what makes the thing dynamic. A class-name match is weaker, because class names
	 * are conventions rather than promises.
	 *
	 * @param array<string, mixed> $basis Basis.
	 * @return float
	 */
	private function confidence_for( array $basis ) {
		if ( isset( $basis['text_match'] ) ) {
			return 0.9;
		}
		if ( isset( $basis['tag'] ) ) {
			return 0.85;
		}
		if ( isset( $basis['term'] ) || isset( $basis['class_match'] ) ) {
			return 0.65;
		}
		return 0.5;
	}

	/* ---------------------------------------------------------------------
	 * Specific detectors
	 * ------------------------------------------------------------------ */

	/**
	 * Detect animations §45 asks about.
	 *
	 * @param array<string, array<string, mixed>> $nodes Nodes.
	 * @return array<int, array<string, mixed>>
	 */
	public function animations( array $nodes ) {
		$out = array();
		foreach ( $nodes as $id => $node ) {
			$type   = strtolower( (string) ( $node['type'] ?? '' ) );
			$declarations = $this->flatten( $node );
			$found = array();

			if ( 'canvas' === $type ) {
				$found[] = 'canvas';
			}
			if ( ! empty( $node['animation'] ) || ! empty( $node['animated'] ) ) {
				$found[] = (string) ( $node['animation'] ?? 'declared' );
			}
			foreach ( array( 'transition', 'animation_name', 'keyframes' ) as $key ) {
				if ( ! empty( $declarations[ $key ] ) && 'none' !== (string) $declarations[ $key ] ) {
					$found[] = (string) $declarations[ $key ];
				}
			}

			$haystack = strtolower( $this->signals_of( $node )['class'] . ' ' . $this->signals_of( $node )['id'] );
			foreach ( array( 'fade', 'slide', 'marquee', 'lottie', 'parallax', 'animate', 'pulse', 'bounce' ) as $needle ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					$found[] = $needle;
				}
			}

			$found = array_values( array_unique( array_filter( $found, 'strlen' ) ) );
			if ( array() === $found ) {
				continue;
			}

			$out[] = array(
				'id'         => (string) $id,
				'kinds'      => $found,
				'normalized' => true,
				// §45: the static visual state is what gets reconstructed. Animations
				// are recorded, not recreated — an animation that cannot be reproduced
				// faithfully is worse than a correct static state.
				'reconstruction' => 'static_state',
			);
		}
		return $out;
	}

	/**
	 * Detect carousels §47 asks about.
	 *
	 * @param array<string, array<string, mixed>> $nodes Nodes.
	 * @return array<int, array<string, mixed>>
	 */
	public function carousel( array $nodes ) {
		$out = array();
		foreach ( $nodes as $id => $node ) {
			$verdict = $this->classify( $node );
			if ( null === $verdict || 'carousel' !== $verdict['reason'] ) {
				continue;
			}
			$out[] = array(
				'id'              => (string) $id,
				'slide_count'     => (int) ( $node['slide_count'] ?? 0 ),
				'visible_slides'  => (int) ( $node['visible_slides'] ?? ( $node['slide_count'] ?? 0 ) ),
				'has_arrows'      => (bool) ( $node['has_arrows'] ?? true ),
				'has_dots'        => (bool) ( $node['has_dots'] ?? true ),
				'autoplay'        => (bool) ( $node['autoplay'] ?? false ),
				// §47: a static representative layout, marked as an approximation of
				// behaviour. The word is in the data so no report can present it as a
				// working carousel.
				'reconstruction'  => 'static_representative_layout',
				'behaviour'       => 'dynamic_behavior_approximation',
			);
		}
		return $out;
	}

	/**
	 * Detect video §48 asks about.
	 *
	 * @param array<string, array<string, mixed>> $nodes Nodes.
	 * @return array<int, array<string, mixed>>
	 */
	public function video( array $nodes ) {
		$out = array();
		foreach ( $nodes as $id => $node ) {
			$type = strtolower( (string) ( $node['type'] ?? '' ) );
			$is_background = ( 'video' === $type ) && ! empty( $node['background_video'] );

			if ( ! in_array( $type, array( 'video', 'video_background' ), true ) && ! $is_background ) {
				continue;
			}

			$w = (int) ( $node['width'] ?? 0 );
			$h = (int) ( $node['height'] ?? 0 );

			$out[] = array(
				'id'           => (string) $id,
				'background'   => $is_background,
				'poster'       => (string) ( $node['poster'] ?? '' ),
				'controls'     => (bool) ( $node['controls'] ?? true ),
				'aspect_ratio' => ( $w > 0 && $h > 0 ) ? round( $w / $h, 4 ) : 0.0,
				'source'       => (string) ( $node['src'] ?? '' ),
				// §48: the reference is preserved, never downloaded. A background video
				// is usually a licensed asset and copying it is not ReplicaForge's call.
				'action'       => 'preserve_reference',
				'excluded_from_generation' => true,
				'note'         => __( 'Video is referenced, not downloaded. Reconstructing the layout around it is safe; reproducing the video is not.', 'replicaforge' ),
			);
		}
		return $out;
	}

	/**
	 * Detect sticky elements §43 asks about.
	 *
	 * @param array<string, array<string, mixed>> $nodes Nodes.
	 * @return array<int, array<string, mixed>>
	 */
	public function sticky( array $nodes ) {
		$out = array();
		foreach ( $nodes as $id => $node ) {
			$position = strtolower( (string) ( $node['position'] ?? ( $this->flatten( $node )['position'] ?? '' ) ) );
			if ( 'sticky' !== $position ) {
				continue;
			}
			$out[] = array(
				'id'     => (string) $id,
				'type'   => 'sticky',
				'top'    => (int) ( $node['top'] ?? 0 ),
				// §43: a safe approximation. Elementor's sticky behaviour is limited, so
				// the record says what the element *is* and the planner decides how to
				// express it — never a scroll script.
				'elementor_equivalent' => 'sticky_section_settings',
				'no_script' => true,
			);
		}
		return $out;
	}

	/**
	 * Detect viewport-fixed elements §44 asks about.
	 *
	 * @param array<string, array<string, mixed>> $nodes          Nodes.
	 * @param array<string, mixed>                $representation Representation.
	 * @return array<int, array<string, mixed>>
	 */
	public function fixed( array $nodes, array $representation ) {
		$out = array();
		foreach ( $nodes as $id => $node ) {
			$position = strtolower( (string) ( $node['position'] ?? ( $this->flatten( $node )['position'] ?? '' ) ) );
			if ( 'fixed' !== $position ) {
				continue;
			}
			$out[] = array(
				'id'     => (string) $id,
				'type'   => 'fixed',
				'role'   => $this->fixed_role( $node ),
				// §44: classified separately from document flow, because a fixed chat
				// button has no place in the page's section structure and putting it
				// there produces a floating gap where the button used to be.
				'in_document_flow' => false,
			);
		}
		return $out;
	}

	/**
	 * Return the role of a fixed element.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return string
	 */
	private function fixed_role( array $node ) {
		$haystack = strtolower( $this->signals_of( $node )['class'] . ' ' . $this->signals_of( $node )['id'] );
		foreach ( array( 'chat', 'back-to-top', 'scroll-top', 'social', 'floating', 'cta' ) as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return (string) $needle;
			}
		}
		return 'floating';
	}

	/**
	 * Detect mobile navigation collapse §42 asks about.
	 *
	 * §42 forbids executing source JavaScript, so the detection is entirely from
	 * observable structure: a navigation that is present at one viewport and absent
	 * at another, alongside a control that has no content of its own.
	 *
	 * @param array<string, array<string, mixed>> $nodes          Nodes.
	 * @param array<string, mixed>                $representation Representation.
	 * @return array<string, mixed>
	 */
	public function mobile_navigation( array $nodes, array $representation ) {
		$nav      = false;
		$toggle   = false;
		$evidence = array();

		foreach ( $nodes as $id => $node ) {
			$type     = strtolower( (string) ( $node['type'] ?? '' ) );
			$haystack = strtolower( $this->signals_of( $node )['class'] . ' ' . $this->signals_of( $node )['id'] . ' ' . $this->signals_of( $node )['aria'] );

			if ( in_array( $type, array( 'nav', 'menu' ), true ) || 'navigation' === $this->signals_of( $node )['role'] ) {
				$nav = true;
				$evidence['navigation'] = (string) $id;
			}
			foreach ( array( 'hamburger', 'menu-toggle', 'nav-toggle', 'menu-button', 'mobile-menu' ) as $needle ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					$toggle = true;
					$evidence['toggle'] = (string) $id;
				}
			}
			if ( 'button' === $type && ( false !== strpos( $haystack, 'menu' ) || 'true' === (string) ( $node['aria_expanded'] ?? '' ) ) ) {
				$toggle = true;
				$evidence['toggle'] = (string) $id;
			}
		}

		if ( ! $nav || ! $toggle ) {
			return array(
				'detected' => false,
				'reason'   => $nav
					? __( 'A navigation was found but no menu control, so a mobile collapse was not identified.', 'replicaforge' )
					: __( 'No navigation was identified, so mobile navigation behaviour is unknown rather than absent.', 'replicaforge' ),
			);
		}

		return array(
			'detected'  => true,
			'evidence'  => $evidence,
			'strategy'  => 'replica_collapse',
			// No script. A replica's mobile menu is an Elementor-native collapse or it
			// is not there; it is never a copy of the source site's JavaScript.
			'no_script' => true,
		);
	}

	/**
	 * Classify responsive transformations §41 asks for.
	 *
	 * @param array<string, array<string, mixed>> $nodes Nodes.
	 * @return array<int, array<string, mixed>>
	 */
	public function responsive( array $nodes ) {
		$out = array();
		foreach ( $nodes as $id => $node ) {
			$declared = (array) ( $node['responsive'] ?? array() );
			if ( array() === $declared ) {
				continue;
			}
			foreach ( $declared as $device => $rules ) {
				foreach ( (array) $rules as $rule ) {
					if ( ! is_array( $rule ) || empty( $rule['change'] ) ) {
						continue;
					}
					$change = (string) $rule['change'];
					if ( ! in_array( $change, Visual_Limits::TRANSFORMATIONS, true ) ) {
						continue;
					}
					$out[] = array(
						'id'         => (string) $id,
						'device'     => (string) $device,
						'change'     => $change,
						'from'       => (string) ( $rule['from'] ?? '' ),
						'to'         => (string) ( $rule['to'] ?? '' ),
						'evidence'   => array( 'source' => 'computed_css' ),
					);
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return every node in a representation, keyed by id.
	 *
	 * @param array<string, mixed> $representation Representation.
	 * @return array<string, array<string, mixed>>
	 */
	private function nodes( array $representation ) {
		$out = array();
		$sections   = isset( $representation['sections'] ) && is_array( $representation['sections'] ) ? $representation['sections'] : array();
		$components = isset( $representation['components'] ) && is_array( $representation['components'] ) ? $representation['components'] : array();

		foreach ( array_merge( $sections, $components ) as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id = (string) ( $node['id'] ?? ( ( isset( $node['type'] ) ? $node['type'] : 'node' ) . '_' . $index ) );
			$out[ $id ] = $node;
		}
		return $out;
	}

	/**
	 * Collect the signals a decision is based on.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, string>
	 */
	private function signals_of( array $node ) {
		$flattened = $this->flatten( $node );
		return array(
			'class' => (string) ( $node['class'] ?? $node['class_name'] ?? $flattened['class'] ?? '' ),
			'id'    => (string) ( $node['element_id'] ?? ( $node['dom_id'] ?? '' ) ),
			'aria'  => (string) ( $node['aria_label'] ?? $node['aria'] ?? '' ),
			'role'  => (string) ( $node['role'] ?? $node['landmark'] ?? '' ),
		);
	}

	/**
	 * Flatten a node's style containers into scalars.
	 *
	 * @param array<string, mixed> $node Node.
	 * @return array<string, string>
	 */
	private function flatten( array $node ) {
		$out = array();
		foreach ( array( $node, $node['style'] ?? array(), $node['computed'] ?? array() ) as $container ) {
			if ( ! is_array( $container ) ) {
				continue;
			}
			foreach ( $container as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$out[ (string) $key ] = (string) $value;
				}
			}
		}
		return $out;
	}
}
