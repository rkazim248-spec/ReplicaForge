<?php
/**
 * Phase 11: AI context budgeting.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Measures, prioritises, reduces, and if necessary chunks an AI context.
 *
 * `Ai_Context_Builder` already does two of these jobs: it redacts secrets and it
 * enforces a size ceiling by *compacting* structures rather than truncating text.
 * That is the right approach and it is not repeated here.
 *
 * What the builder does not know is **which model is going to read the result**.
 * It enforces `Ai_Limits::MAX_CONTEXT_BYTES`, one fixed number, for every provider
 * and every model. A model with a 16,000-token window and one with a million are
 * treated identically, so a large page either overflows a small model — a
 * rejected request that reads like an outage — or is needlessly trimmed for a
 * large one, which costs fidelity the model could have handled.
 *
 * This class sits after the builder and before the provider, and answers three
 * questions the builder cannot:
 *
 * 1. **Does this fit?** Measured against the *model's* window, not a constant.
 * 2. **If not, what goes?** A priority order, applied as whole-structural units.
 *    §13 forbids arbitrarily truncating the middle of JSON, and the way to honour
 *    that is to drop and compact *named sections* — never to slice an array in half.
 * 3. **If the global part alone will not fit, can it be chunked?** §14 asks for
 *    splitting that preserves relationships rather than splitting on a character
 *    count. That means the global design system once, then the page in sections
 *    that keep their parent context.
 *
 * ### What is dropped, in order
 *
 * §13 gives the order: structure, sections, components, layout, tokens,
 * typography, responsive, and component assets are highest; content and metadata
 * are medium; raw HTML, duplicate CSS, and repeated content are lowest.
 *
 * The important detail is that the *top* of that list is never dropped. A context
 * that has lost its design tokens is worse than no context at all, because the
 * reconstruction will then invent them — and an invented token is exactly the
 * hallucination Phase 11's evidence rules exist to catch. So reduction removes
 * detail from the bottom of the list upward and stops before it would touch
 * structure.
 */
final class Ai_Context_Budget {

	/**
	 * Priority bands for the top-level parts of a context.
	 *
	 * Ordered highest priority first. The order is the requirement; the numbers are
	 * only used to sort.
	 *
	 * @var array<int, string>
	 */
	const PRIORITY = array(
		'design_system' => 100,
		'tokens'        => 95,
		'typography'    => 90,
		'layout'        => 85,
		'responsive'    => 80,
		'navigation'    => 70,
		'assets'        => 65,
		'hierarchy'     => 60,
		'sections'      => 55,
		'components'    => 50,
		'fields'        => 30,
		'source'        => 20,
		'raw_html'      => 10,
		'raw_css'       => 5,
	);

	/**
	 * Parts that may never be dropped.
	 *
	 * A context without these produces a reconstruction that invents them, and an
	 * invented design token is the single most damaging thing a model can do here.
	 *
	 * @var array<int, string>
	 */
	const ESSENTIAL = array( 'design_system', 'tokens', 'layout' );

	/**
	 * How much of the budget a single part may claim, at most.
	 *
	 * Without a cap, one large part can consume the whole window and leave the rest
	 * unmentioned, which reads as a complete context that happens to be about one
	 * component. A third of the budget for any one part is generous for the largest
	 * legitimate part and still leaves room for the rest.
	 */
	const MAX_SHARE = 0.34;

	/**
	 * The model the context is being budgeted for.
	 *
	 * @var string
	 */
	private $provider_id;

	/**
	 * The model identifier.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Resolved capabilities.
	 *
	 * @var array<string, mixed>
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param string $provider_id Provider identifier.
	 * @param string $model       Model identifier.
	 */
	public function __construct( $provider_id, $model = '' ) {
		$this->provider_id  = is_string( $provider_id ) ? strtolower( trim( $provider_id ) ) : '';
		$this->model        = is_string( $model ) ? trim( $model ) : '';
		$this->capabilities = Ai_Capabilities::resolve( $this->provider_id, $this->model );
	}

	/**
	 * Return the model this budget is for.
	 *
	 * @return array<string, mixed>
	 */
	public function capabilities() {
		return $this->capabilities;
	}

	/**
	 * Return the byte ceiling for this model.
	 *
	 * @return int
	 */
	public function limit_bytes() {
		return Ai_Capabilities::context_budget_bytes( $this->provider_id, $this->model );
	}

	/**
	 * Return the output ceiling for this model.
	 *
	 * @return int
	 */
	public function output_limit_bytes() {
		return Ai_Capabilities::output_budget_bytes( $this->provider_id, $this->model );
	}

	/**
	 * Measure a context.
	 *
	 * @param array<string, mixed> $context Context package.
	 * @return array<string, mixed>
	 */
	public function measure( array $context ) {
		$encoded = wp_json_encode( $context );
		$bytes   = ( false === $encoded ) ? 0 : strlen( $encoded );
		$limit   = $this->limit_bytes();

		$parts = array();
		foreach ( $context as $key => $value ) {
			$part = wp_json_encode( $value );
			$parts[ (string) $key ] = ( false === $part ) ? 0 : strlen( $part );
		}
		arsort( $parts );

		return array(
			'bytes'    => $bytes,
			'limit'    => $limit,
			'over'     => ( $bytes > $limit ),
			'headroom' => max( 0, $limit - $bytes ),
			'ratio'    => ( $limit > 0 ) ? round( $bytes / $limit, 3 ) : 0.0,
			'parts'    => $parts,
			'largest'  => ( array() === $parts ) ? '' : (string) array_key_first( $parts ),
			'model'    => (string) $this->capabilities['model'],
			'context_tokens' => $this->capabilities['context'],
		);
	}

	/**
	 * Budget a context: measure, reduce if needed, and report what was done.
	 *
	 * @param array<string, mixed> $context Context package.
	 * @return array<string, mixed>
	 */
	public function budget( array $context ) {
		$before  = $this->measure( $context );
		$reduced = $context;
		$actions = array();

		if ( ! $before['over'] ) {
			return array(
				'success'   => true,
				'context'   => $reduced,
				'changed'   => false,
				'actions'   => array(),
				'before'    => $before,
				'after'     => $before,
				'chunked'   => false,
			);
		}

		// Reduction runs from the lowest priority upward, so the design system and
		// the layout survive intact and detail is what gives way.
		foreach ( array_keys( self::PRIORITY ) as $part ) {
			$priority = self::PRIORITY[ $part ];
			if ( in_array( $part, self::ESSENTIAL, true ) ) {
				continue;
			}

			$current = $this->measure( $reduced );
			if ( ! $current['over'] ) {
				break;
			}

			if ( ! array_key_exists( $part, $reduced ) ) {
				continue;
			}

			$before_part = $current['parts'][ $part ] ?? 0;
			if ( $before_part < 64 ) {
				// Too small to be worth cutting, and cutting it would remove the
				// part entirely. A page with many tiny parts is a different problem
				// and is handled by the per-part cap below.
				continue;
			}

			$cap = (int) floor( $current['limit'] * self::MAX_SHARE );
			if ( $cap >= $before_part ) {
				continue;
			}

			$reduced[ $part ] = $this->compact( $reduced[ $part ], $cap, $priority );
			$actions[]        = array(
				'part'     => (string) $part,
				'priority' => $priority,
				'bytes'    => $before_part,
				'action'   => 'compacted',
			);
		}

		// A per-part cap, applied after the priority pass. Without it a single part
		// under the share cap can still be large enough to crowd everything else
		// out, and the result is a context that looks complete but is about one
		// component.
		$current = $this->measure( $reduced );
		if ( $current['over'] ) {
			foreach ( array_keys( $current['parts'] ) as $part ) {
				$again = $this->measure( $reduced );
				if ( ! $again['over'] ) {
					break;
				}
				if ( in_array( (string) $part, self::ESSENTIAL, true ) ) {
					continue;
				}
				$cap = (int) floor( $again['limit'] * self::MAX_SHARE );
				$shrunk = $this->compact( $reduced[ $part ], min( $cap, (int) $again['parts'][ $part ] ), 1 );
				if ( $shrunk === $reduced[ $part ] ) {
					continue;
				}
				$reduced[ $part ] = $shrunk;
				$actions[]        = array(
					'part'     => (string) $part,
					'priority' => 1,
					'bytes'    => (int) $again['parts'][ $part ],
					'action'   => 'capped',
				);
			}
		}

		$after = $this->measure( $reduced );

		return array(
			'success' => ! $after['over'],
			'context' => $reduced,
			'changed' => ( $reduced !== $context ),
			'actions' => $actions,
			'before'  => $before,
			'after'   => $after,
			'chunked' => false,
			// When even the essentials will not fit, the caller needs to chunk. The
			// answer says so rather than returning a context that is over the limit
			// with a hopeful note attached.
			'chunk_required' => $after['over'],
		);
	}

	/**
	 * Split a context into chunks that keep their relationships.
	 *
	 * The split is by **section**, and each chunk carries the global design system
	 * so every chunk can be interpreted on its own. §14 is explicit that splitting
	 * on a character count is wrong: two sections cut in half cannot be interpreted,
	 * while two whole sections with the shared design system can.
	 *
	 * The first chunk is the global system *alone*, because it is what every later
	 * chunk is measured against, and a chunk that omitted it would be interpreted
	 * against nothing.
	 *
	 * @param array<string, mixed> $context Context package.
	 * @return array<string, mixed>
	 */
	public function chunk( array $context ) {
		$global = array();
		foreach ( array( 'design_system', 'tokens', 'layout', 'responsive', 'typography', 'assets', 'limits', 'schema' ) as $part ) {
			if ( array_key_exists( $part, $context ) ) {
				$global[ $part ] = $context[ $part ];
			}
		}

		$global_bytes = $this->measure( $global )['bytes'];
		$limit        = $this->limit_bytes();

		// If the global system alone does not fit, no chunking helps: every chunk
		// would have to carry it. The honest answer is that this model cannot take
		// this page, and the caller says so.
		if ( $global_bytes > (int) floor( $limit * 0.5 ) ) {
			return array(
				'success' => false,
				'chunks'  => array(),
				'reason'  => 'global_context_too_large',
				'message' => __( 'The design system alone is too large for this model. Use a model with a larger context, or analyze fewer sections.', 'replicaforge' ),
				'global_bytes' => $global_bytes,
				'limit'    => $limit,
			);
		}

		$sections = isset( $context['sections'] ) && is_array( $context['sections'] ) ? $context['sections'] : array();
		if ( array() === $sections ) {
			// Nothing to split. One chunk carrying everything, after reduction.
			$budgeted = $this->budget( $context );
			return array(
				'success' => ! empty( $budgeted['success'] ),
				'chunks'  => empty( $budgeted['success'] ) ? array() : array(
					array(
						'index'    => 1,
						'of'       => 1,
						'purpose'  => 'whole_page',
						'context'  => $budgeted['context'],
						'bytes'    => $budgeted['after']['bytes'],
						'section_ids' => array(),
					),
				),
				'reason'  => 'single_chunk',
			);
		}

		$per_chunk = (int) floor( ( $limit - $global_bytes ) * 0.9 );
		$chunks    = array();

		// Chunk 0: the global system, on its own. Not a separate request — it is
		// carried by every later chunk — but it is recorded so a reader can see
		// what the shared context is.
		$chunks[] = array(
			'index'       => 0,
			'of'          => 0,
			'purpose'     => 'global_design_system',
			'context'     => $global,
			'bytes'       => $global_bytes,
			'section_ids' => array(),
		);

		$current = array();
		$current_bytes = 0;
		$ids     = array();
		$index   = 0;

		$flush = function () use ( &$current, &$current_bytes, &$ids, &$index, &$chunks, $global, $per_chunk ) {
			if ( array() === $current ) {
				return;
			}
			$index++;
			$payload = array_merge( $global, array( 'sections' => $current ) );
			$chunks[] = array(
				'index'       => $index,
				'of'          => 0,
				'purpose'     => 'sections',
				'context'     => $payload,
				'bytes'       => (int) ( $this->measure( $payload )['bytes'] ?? 0 ),
				'section_ids' => $ids,
			);
			$current      = array();
			$current_bytes = 0;
			$ids          = array();
		};

		foreach ( $sections as $section ) {
			$encoded = wp_json_encode( $section );
			$bytes   = ( false === $encoded ) ? 0 : strlen( $encoded );

			// A single section larger than a whole chunk is compacted rather than
			// split. Splitting one section across two chunks would break the
			// parent-child relationship that makes the section interpretable at all,
			// and a compacted section is still a section.
			if ( $bytes > $per_chunk ) {
				$flush();
				$compacted          = $this->compact( $section, (int) floor( $per_chunk * 0.9 ), 50 );
				$index++;
				$payload            = array_merge( $global, array( 'sections' => array( $compacted ) ) );
				$chunks[]           = array(
					'index'       => $index,
					'of'          => 0,
					'purpose'     => 'oversized_section',
					'context'     => $payload,
					'bytes'       => (int) $this->measure( $payload )['bytes'],
					'section_ids' => array( (string) ( $section['id'] ?? '' ) ),
				);
				continue;
			}

			if ( ( $current_bytes + $bytes ) > $per_chunk && array() !== $current ) {
				$flush();
			}

			$current[]              = $section;
			$current_bytes          += $bytes;
			$ids[]                  = (string) ( $section['id'] ?? '' );
		}

		$flush();

		$total = count( $chunks );
		foreach ( $chunks as $position => $chunk ) {
			$chunks[ $position ]['of'] = $total;
		}

		return array(
			'success'      => ( $total > 1 ),
			'chunks'       => $chunks,
			'reason'       => ( $total > 1 ) ? 'chunked' : 'single_chunk',
			'global_bytes' => $global_bytes,
			'per_chunk'    => $per_chunk,
			'limit'        => $limit,
		);
	}

	/**
	 * Compact a value to fit a byte ceiling, structurally.
	 *
	 * A list loses its tail. An array loses the lowest-priority values first. A
	 * string is truncated with an explicit marker. Nothing is ever cut at an
	 * arbitrary offset inside a token, because a JSON document truncated in the
	 * middle of a string is not a smaller JSON document — it is a broken one.
	 *
	 * @param mixed $value  Value to compact.
	 * @param int   $budget Byte ceiling.
	 * @param int   $depth  Current depth, to bound recursion.
	 * @return mixed
	 */
	public function compact( $value, $budget, $depth = 0 ) {
		$budget = max( 64, (int) $budget );

		if ( $depth > 6 ) {
			// Bounded depth. A structure nested deeper than this is not one the
			// builders produce, and recursing further risks running out of stack on
			// a hostile input.
			return null;
		}

		$encoded = wp_json_encode( $value );
		$bytes   = ( false === $encoded ) ? 0 : strlen( $encoded );
		if ( $bytes <= $budget ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return $this->truncate_string( $value, $budget );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );

		if ( $is_list ) {
			$out = array();
			// Binary search on how many entries fit. Guessing a ratio and retrying is
			// how a compaction ends up either over budget or throwing away half the
			// list for nothing.
			$low  = 0;
			$high = count( $value );
			while ( $low < $high ) {
				$mid   = (int) floor( ( $low + $high + 1 ) / 2 );
				$trial = $this->encode_with_marker( array_slice( $value, 0, $mid ), count( $value ) );
				if ( strlen( $trial ) <= $budget ) {
					$low = $mid;
				} else {
					$high = $mid - 1;
				}
			}
			$out = array_slice( $value, 0, $low );
			if ( count( $value ) > $low ) {
				$out[] = array(
					'_truncated'  => true,
					'_omitted'    => count( $value ) - $low,
					'_of'         => count( $value ),
					'_note'       => __( 'Entries omitted to fit the model context. Nothing was rewritten.', 'replicaforge' ),
				);
			}
			return $out;
		}

		// A map: keep the keys the caller considers essential, then add the rest
		// while they fit. The result says what was left out, so a reader can tell
		// a compacted part from a complete one.
		$kept    = array();
		$omitted = array();
		$running = 2; // Room for the marker itself.

		foreach ( $value as $key => $entry ) {
			$key_str = (string) $key;
			if ( in_array( $key_str, self::ESSENTIAL, true ) ) {
				$kept[ $key_str ] = $entry;
				$running         += strlen( $key_str ) + 24;
				continue;
			}

			$trial     = $kept;
			$trial[ $key_str ] = $entry;
			$encoded   = wp_json_encode( $trial );
			$trial_len = ( false === $encoded ) ? 0 : strlen( $encoded );

			if ( $running + $trial_len <= $budget ) {
				$kept     = $trial;
				$running += $trial_len;
				continue;
			}

			// Try a compacted version before giving up on the key entirely. A long
			// text value is usually the reason a part is too big, and a shortened
			// string is more useful than no key.
			$compacted = $this->compact( $entry, max( 32, (int) ( $budget / 3 ) ), $depth + 1 );
			$trial     = $kept;
			$trial[ $key_str ] = $compacted;
			$encoded   = wp_json_encode( $trial );
			if ( false !== $encoded && strlen( $encoded ) <= $budget && $compacted !== $entry ) {
				$kept     = $trial;
				$running  = strlen( $encoded );
				continue;
			}

			$omitted[] = $key_str;
		}

		if ( array() !== $omitted ) {
			$kept['_truncated'] = true;
			$kept['_omitted']   = count( $omitted );
			$kept['_omitted_keys'] = array_slice( $omitted, 0, 20 );
			$kept['_note']      = __( 'Values omitted to fit the model context. Nothing was rewritten.', 'replicaforge' );
		}

		return $kept;
	}

	/**
	 * Return a budget report, for the diagnostics screen.
	 *
	 * @return array<string, mixed>
	 */
	public function report() {
		return array(
			'provider'          => (string) $this->capabilities['provider'],
			'model'             => (string) $this->capabilities['model'],
			'known_model'       => (bool) $this->capabilities['known'],
			'context_tokens'    => (int) $this->capabilities['context'],
			'context_limit'     => $this->limit_bytes(),
			'output_limit'      => $this->output_limit_bytes(),
			'structured_output' => (bool) $this->capabilities['structured_output'],
			'vision'            => (bool) $this->capabilities['vision'],
			'max_share'         => self::MAX_SHARE,
			'essential'         => self::ESSENTIAL,
		);
	}

	/**
	 * Encode a value with a truncation marker, for the binary search.
	 *
	 * @param array<int, mixed> $slice Kept entries.
	 * @param int               $total Original count.
	 * @return string
	 */
	private function encode_with_marker( array $slice, $total ) {
		$payload = $slice;
		if ( count( $slice ) < $total ) {
			$payload[] = array( '_truncated' => true, '_omitted' => $total - count( $slice ) );
		}
		$encoded = wp_json_encode( $payload );
		return ( false === $encoded ) ? '' : $encoded;
	}

	/**
	 * Truncate a string to a byte ceiling, with a visible marker.
	 *
	 * A marker is appended rather than the tail being cut silently, because a
	 * silently shortened string is indistinguishable from a complete one — and an
	 * AI model asked to reconstruct from a truncated text value will fill the gap
	 * with something plausible.
	 *
	 * @param string $value  String.
	 * @param int    $budget Byte ceiling.
	 * @return string
	 */
	private function truncate_string( $value, $budget ) {
		$marker = ' [...]';
		$room   = max( 1, $budget - strlen( $marker ) );
		$cut    = substr( $value, 0, $room );

		// Do not cut a multi-byte character in half. An invalid byte sequence in a
		// JSON payload is a parse failure at the provider, not a shorter message.
		while ( '' !== $cut && ! self::is_valid_utf8( $cut ) ) {
			$cut = substr( $cut, 0, -1 );
		}

		return $cut . $marker;
	}

	/**
	 * Return whether a string is valid UTF-8, without requiring mbstring.
	 *
	 * The bundled PHP build has no mbstring, and `mb_check_encoding` is the one
	 * function the WordPress polyfill does not replace. `preg_match` with the `u`
	 * modifier validates UTF-8 for the same purpose, and this build has PCRE.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_valid_utf8( $value ) {
		return ( '' === $value ) || ( 1 === preg_match( '//u', $value ) );
	}
}
