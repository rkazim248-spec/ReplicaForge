<?php
/**
 * Phase 3 AI and reconstruction limits.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Centralized bounds for optional AI processing.
 */
final class Ai_Limits {

	/** Maximum serialized AI context size. */
	const MAX_CONTEXT_BYTES = 120000;

	/** Maximum serialized provider response size. */
	const MAX_OUTPUT_BYTES = 150000;

	/** Maximum sections sent to and accepted from the model. */
	const MAX_SECTIONS = 50;

	/** Maximum components sent to and accepted from the model. */
	const MAX_COMPONENTS = 150;

	/** Maximum text value length in AI context. */
	const MAX_TEXT_LENGTH = 900;

	/** Maximum CSS/token value length. */
	const MAX_CSS_VALUE_LENGTH = 180;

	/** Maximum color tokens sent to the model. */
	const MAX_COLORS = 50;

	/** Maximum responsive rules sent to the model. */
	const MAX_RESPONSIVE_RULES = 40;

	/** Maximum asset records sent to the model. */
	const MAX_ASSETS = 80;

	/** Maximum image records sent to the model. */
	const MAX_IMAGES = 80;

	/** Maximum link references sent to the model. */
	const MAX_LINKS = 200;

	/** Maximum content mappings sent to the model. */
	const MAX_CONTENT_ITEMS = 180;

	/** Maximum provider attempts, including one controlled repair attempt. */
	const MAX_PROVIDER_ATTEMPTS = 2;

	/** Default provider timeout in seconds. */
	const DEFAULT_TIMEOUT = 30;

	/** Minimum provider timeout in seconds. */
	const MIN_TIMEOUT = 5;

	/** Maximum provider timeout in seconds. */
	const MAX_TIMEOUT = 60;

	/** Default transient cache lifetime in seconds. */
	const CACHE_TTL = 86400;

	/** AI schema version. */
	const SCHEMA_VERSION = '3.0';

	/** Internal prompt version. */
	const PROMPT_VERSION = '1.0';
}
