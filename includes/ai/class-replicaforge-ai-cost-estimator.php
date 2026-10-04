<?php
/**
 * Phase 11: AI cost estimation and confirmation.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Estimates what an AI operation will cost, and decides whether to ask first.
 *
 * ### The estimate is a label, not a bill
 *
 * ReplicaForge does not bill for AI tokens. It meters *operations* through the
 * Phase 10 plan limits, and a user on the free plan gets two generations however
 * many tokens those generations happened to use. So nothing here computes a
 * chargeable amount, and §42 is explicit that an estimate must never be presented
 * as a guaranteed billing amount.
 *
 * What it does produce is a **complexity label** — low, moderate, high — and the
 * measurements behind it. That is the useful thing for a user deciding whether to
 * proceed, and it is honest: "this will need several AI requests and the page is
 * large" is a statement ReplicaForge can actually support, whereas "this will
 * cost $0.42" is a number derived from prices it does not know are current.
 *
 * ### When confirmation is required
 *
 * Only when the site is configured to require it. Forcing a confirmation dialog on
 * every reconstruction would make the tool worse to use and would train people to
 * click through dialogs without reading them — which is the opposite of what a
 * confirmation is for. So `require_confirmation` is a setting, and the default is
 * off for a low estimate and on for a high one.
 */
final class Ai_Cost_Estimator {

	/**
	 * Option holding the estimator configuration.
	 */
	const OPTION = 'replicaforge_ai_estimator';

	/**
	 * Complexity bands.
	 *
	 * @var array<int, string>
	 */
	const BANDS = array( 'low', 'moderate', 'high' );

	/**
	 * Thresholds that separate the bands, in provider calls.
	 *
	 * Two is a single reconstruction call plus a possible repair. Four is where
	 * multi-stage processing starts. Above six something is wrong — a page that
	 * needs more than six calls to describe is a page the user should be told about
	 * before spending the money, not after.
	 */
	const THRESHOLDS = array( 'moderate' => 2, 'high' => 5 );

	/**
	 * Return the estimator configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'estimate_enabled'      => ! isset( $stored['estimate_enabled'] ) || ! empty( $stored['estimate_enabled'] ),
			'require_confirmation'  => ! empty( $stored['require_confirmation'] ),
			'confirm_from'          => isset( $stored['confirm_from'] ) && in_array( (string) $stored['confirm_from'], self::BANDS, true )
				? (string) $stored['confirm_from']
				: 'high',
			// The estimated cost ceiling above which a confirmation is required even
			// if the site is not otherwise asking. Zero disables the check.
			'cost_ceiling'          => isset( $stored['cost_ceiling'] ) && is_numeric( $stored['cost_ceiling'] )
				? max( 0.0, (float) $stored['cost_ceiling'] )
				: 0.0,
		);
	}

	/**
	 * Store the estimator configuration.
	 *
	 * @param array<string, mixed> $input Configuration.
	 * @return array{success: bool, settings: array<string, mixed>, errors: array<int, string>}
	 */
	public static function save( array $input ) {
		$errors = array();
		$clean  = self::settings();

		if ( isset( $input['estimate_enabled'] ) ) {
			$clean['estimate_enabled'] = ! empty( $input['estimate_enabled'] );
		}
		if ( isset( $input['require_confirmation'] ) ) {
			$clean['require_confirmation'] = ! empty( $input['require_confirmation'] );
		}
		if ( isset( $input['confirm_from'] ) ) {
			$from = (string) $input['confirm_from'];
			if ( in_array( $from, self::BANDS, true ) ) {
				$clean['confirm_from'] = $from;
			} else {
				$errors[] = __( 'That confirmation threshold is not one ReplicaForge recognises.', 'replicaforge' );
			}
		}
		if ( isset( $input['cost_ceiling'] ) ) {
			if ( ! is_numeric( $input['cost_ceiling'] ) || (float) $input['cost_ceiling'] < 0 ) {
				$errors[] = __( 'The cost ceiling must be a positive number or zero.', 'replicaforge' );
			} else {
				$clean['cost_ceiling'] = (float) $input['cost_ceiling'];
			}
		}

		if ( array() !== $errors ) {
			return array(
				'success'  => false,
				'settings' => self::settings(),
				'errors'   => $errors,
			);
		}

		update_option( self::OPTION, $clean, false );

		return array(
			'success'  => true,
			'settings' => $clean,
			'errors'   => array(),
		);
	}

	/**
	 * Estimate an AI operation.
	 *
	 * @param array<string, mixed> $context    Context package about to be sent.
	 * @param string               $provider   Provider identifier.
	 * @param string               $model      Model identifier.
	 * @param string               $operation  Operation name, for the label.
	 * @return array<string, mixed>
	 */
	public static function estimate( array $context, $provider = '', $model = '', $operation = 'analysis' ) {
		$settings = self::settings();
		$budget   = new Ai_Context_Budget( $provider, $model );
		$measured = $budget->measure( $context );
		$cap      = $budget->capabilities();

		$sections = isset( $context['sections'] ) && is_array( $context['sections'] ) ? count( $context['sections'] ) : 0;

		// Calls are derived from the work, not guessed. A page that fits in one
		// window needs one call. A page that does not needs a call per chunk plus a
		// possible repair, and the repairs are what push a large page into the high
		// band — which is correct, because repairs are the calls people forget about.
		$chunk_result = $measured['over'] ? $budget->chunk( $context ) : array( 'chunks' => array() );
		$chunk_calls = 0;
		$chunkable   = false;

		if ( ! empty( $chunk_result['success'] ) && is_array( $chunk_result['chunks'] ) ) {
			// The global chunk is carried by the others rather than sent on its own.
			$chunk_calls = max( 0, count( $chunk_result['chunks'] ) - 1 );
			$chunkable   = true;
		} elseif ( ! empty( $chunk_result['reason'] ) && 'global_context_too_large' === $chunk_result['reason'] ) {
			$chunkable = false;
		}

		$calls    = max( 1, $chunk_calls );
		$repairs  = ( $calls > 1 ) ? min( 2, $calls ) : min( 1, $calls );
		$total    = $calls + $repairs;

		// Token estimates. Four bytes per token, the same approximation the budget
		// uses, and reported as a range because a token count is an estimate.
		$in_low  = (int) floor( $measured['bytes'] / 5 );
		$in_high = (int) ceil( $measured['bytes'] / 3 );
		$out_low = (int) floor( $measured['bytes'] / 12 );
		$out_high= (int) ceil( $measured['bytes'] / 5 );

		$cost_low  = self::estimate_amount( $in_low, $out_low, $cap );
		$cost_high = self::estimate_amount( $in_high, $out_high, $cap );

		// The band is driven by the *planned* calls, not the total. A repair is a
		// possibility, not a plan, and counting it as one would put every small page
		// into the "moderate" band — which would make the label useless as a signal
		// and would make a confirmation dialog appear for work that is almost always
		// one request. The total is still reported, and still shown to a user who is
		// being asked to confirm.
		$band = self::band_for( $calls, $sections, $measured );

		$out = array(
			'operation'      => (string) $operation,
			'band'           => $band,
			'label'          => self::band_label( $band ),
			'provider'       => (string) $cap['provider'],
			'model'          => (string) $cap['model'],
			'known_model'    => (bool) $cap['known'],
			'sections'       => $sections,
			'context_bytes'  => (int) $measured['bytes'],
			'context_limit'  => (int) $measured['limit'],
			'fits'           => ! $measured['over'],
			'chunkable'      => $chunkable,
			'provider_calls' => $calls,
			'repair_calls'   => $repairs,
			'total_calls'    => $total,
			'input_tokens'   => array( 'low' => $in_low, 'high' => $in_high ),
			'output_tokens'  => array( 'low' => $out_low, 'high' => $out_high ),
			'cost_estimate'  => array(
				'low'   => $cost_low,
				'high'  => $cost_high,
				'known' => ( (float) $cap['cost_in'] > 0.0 || (float) $cap['cost_out'] > 0.0 ),
			),
			// The disclaimer travels with the number, not only in the documentation.
			'note'           => __( 'This is an approximation for planning, not a billing amount. ReplicaForge does not charge for AI usage; your provider does, on their own terms.', 'replicaforge' ),
		);

		$out['requires_confirmation'] = self::requires_confirmation( $out, $settings );
		$out['confirmation']          = $out['requires_confirmation'] ? self::confirmation_notice( $out ) : null;

		if ( ! $settings['estimate_enabled'] ) {
			// The estimate is still computed, but nothing is shown and nothing is
			// asked. Disabling the estimate is not the same as lying about it.
			$out['band']                 = 'low';
			$out['requires_confirmation'] = false;
			$out['confirmation']         = null;
			$out['estimated']             = false;
		} else {
			$out['estimated'] = true;
		}

		return $out;
	}

	/**
	 * Return the confirmation notice for an estimate.
	 *
	 * @param array<string, mixed> $estimate Estimate.
	 * @return array<string, mixed>
	 */
	public static function confirmation_notice( array $estimate ) {
		$calls = (int) ( $estimate['total_calls'] ?? 1 );

		return array(
			'title' => __( 'This reconstruction may need several AI requests', 'replicaforge' ),
			'body'  => sprintf(
				/* translators: 1: number of estimated AI requests, 2: number of sections on the page. */
				__( 'This page is large enough that ReplicaForge expects to make about %1$d AI requests to describe %2$d sections. Small pages take fewer, which is cheaper and faster.', 'replicaforge' ),
				$calls,
				(int) ( $estimate['sections'] ?? 0 )
			),
			'facts' => array(
				'estimated_requests' => $calls,
				'sections'           => (int) ( $estimate['sections'] ?? 0 ),
				'band'               => (string) ( $estimate['band'] ?? 'low' ),
				'cost_known'         => ! empty( $estimate['cost_estimate']['known'] ),
				'cost_note'          => (string) ( $estimate['note'] ?? '' ),
			),
			'actions' => array(
				'continue' => __( 'Continue', 'replicaforge' ),
				'cancel'   => __( 'Continue later', 'replicaforge' ),
			),
		);
	}

	/**
	 * Return a short label for a band.
	 *
	 * @param string $band Band name.
	 * @return string
	 */
	public static function band_label( $band ) {
		$labels = array(
			'low'      => __( 'Low', 'replicaforge' ),
			'moderate' => __( 'Moderate', 'replicaforge' ),
			'high'     => __( 'High', 'replicaforge' ),
		);

		return isset( $labels[ $band ] ) ? $labels[ $band ] : (string) $band;
	}

	/**
	 * Return a report for the diagnostics screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function report() {
		return array(
			'settings'  => self::settings(),
			'thresholds' => self::THRESHOLDS,
			'bands'     => self::BANDS,
		);
	}

	/**
	 * Return the band for a planned call count and page shape.
	 *
	 * @param int                  $calls    Planned provider calls, excluding repairs.
	 * @param int                  $sections Section count.
	 * @param array<string, mixed> $measured Measurement.
	 * @return string
	 */
	private static function band_for( $calls, $sections, array $measured ) {
		$calls = max( 1, (int) $calls );

		// A page that does not fit is at least moderate however few calls the split
		// produced. Something had to be cut or chunked, and that is not a low
		// complexity operation.
		if ( ! empty( $measured['over'] ) && $calls <= self::THRESHOLDS['moderate'] ) {
			return 'moderate';
		}
		if ( $calls >= self::THRESHOLDS['high'] || $sections >= 40 ) {
			return 'high';
		}
		if ( $calls >= self::THRESHOLDS['moderate'] || $sections >= 15 ) {
			return 'moderate';
		}

		return 'low';
	}

	/**
	 * Return whether a confirmation is required.
	 *
	 * @param array<string, mixed> $estimate Estimate.
	 * @param array<string, mixed> $settings Settings.
	 * @return bool
	 */
	private static function requires_confirmation( array $estimate, array $settings ) {
		if ( ! $settings['require_confirmation'] ) {
			return false;
		}

		$order = array_flip( self::BANDS );
		$band  = isset( $order[ (string) $estimate['band'] ] ) ? (int) $order[ (string) $estimate['band'] ] : 0;
		$from  = isset( $order[ (string) $settings['confirm_from'] ] ) ? (int) $order[ (string) $settings['confirm_from'] ] : 2;

		if ( $band >= $from ) {
			return true;
		}

		$ceiling = (float) $settings['cost_ceiling'];
		return ( $ceiling > 0.0 && (float) $estimate['cost_estimate']['high'] > $ceiling );
	}

	/**
	 * Return an estimated amount for a token count.
	 *
	 * @param int                  $in     Input tokens.
	 * @param int                  $out    Output tokens.
	 * @param array<string, mixed> $cap    Resolved capabilities.
	 * @return float
	 */
	private static function estimate_amount( $in, $out, array $cap ) {
		$in_rate  = (float) ( $cap['cost_in'] ?? 0.0 );
		$out_rate = (float) ( $cap['cost_out'] ?? 0.0 );
		if ( $in_rate <= 0.0 && $out_rate <= 0.0 ) {
			return 0.0;
		}

		// Rates are per million tokens.
		$amount = ( ( $in / 1000000 ) * $in_rate ) + ( ( $out / 1000000 ) * $out_rate );

		return round( max( 0.0, $amount ), 6 );
	}
}
