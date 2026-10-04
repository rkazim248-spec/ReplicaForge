<?php
/**
 * Centralized Phase 2 resource and representation limits.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps Phase 2 deterministic and bounded.
 */
final class Analysis_Limits {

	/** Maximum number of linked stylesheets inspected. */
	const MAX_STYLESHEETS = 3;

	/** Maximum bytes accepted from one external stylesheet. */
	const MAX_STYLESHEET_SIZE = 1048576;

	/** Maximum combined bytes accepted from external stylesheets. */
	const MAX_TOTAL_CSS_SIZE = 1572864;

	/** Maximum time budget for external stylesheet work. */
	const MAX_RESOURCE_SECONDS = 6;

	/** Per-stylesheet timeout budget. */
	const STYLESHEET_TIMEOUT = 2;

	/** Maximum number of DOM nodes retained for Phase 2. */
	const MAX_DOM_NODES = 12000;

	/** Maximum CSS text retained from inline style blocks and attributes. */
	const MAX_INLINE_CSS_SIZE = 524288;

	/** Maximum combined CSS text passed to the parser. */
	const MAX_CSS_TEXT_SIZE = 2097152;

	/** Maximum number of CSS rules retained. */
	const MAX_CSS_RULES = 2500;

	/** Maximum number of CSS declarations retained per node. */
	const MAX_NODE_DECLARATIONS = 80;

	/** Maximum number of sections retained. */
	const MAX_SECTIONS = 100;

	/** Maximum number of components retained. */
	const MAX_COMPONENTS = 600;

	/** Maximum number of repeated card groups retained. */
	const MAX_CARD_GROUPS = 100;

	/** Maximum number of design tokens retained per family. */
	const MAX_TOKENS_PER_FAMILY = 100;

	/** Maximum length of a generated source selector. */
	const MAX_SELECTOR_LENGTH = 180;

	/** Maximum length of a source-trace text value. */
	const MAX_SOURCE_TEXT_LENGTH = 240;

	/** Maximum depth used by DOM descendant walks. */
	const MAX_DOM_DEPTH = 64;
}
