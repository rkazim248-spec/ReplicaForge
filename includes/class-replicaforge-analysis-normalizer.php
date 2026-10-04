<?php
/**
 * Normalization helpers shared by Phase 2 analysis services.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Converts Phase 1 data and raw inference values into bounded structures.
 */
final class Analysis_Normalizer {

	/**
	 * Normalize a Phase 1 analysis array for the Phase 2 pipeline.
	 *
	 * @param array<string, mixed> $analysis Phase 1 analysis.
	 * @return array<string, mixed>
	 */
	public static function normalize_phase1( $analysis ) {
		$analysis = is_array( $analysis ) ? $analysis : array();
		$website  = isset( $analysis['website'] ) && is_array( $analysis['website'] ) ? $analysis['website'] : array();
		$meta     = isset( $analysis['meta'] ) && is_array( $analysis['meta'] ) ? $analysis['meta'] : array();
		$content  = isset( $analysis['content'] ) && is_array( $analysis['content'] ) ? $analysis['content'] : array();
		$design   = isset( $analysis['design'] ) && is_array( $analysis['design'] ) ? $analysis['design'] : array();
		$responsive = isset( $analysis['responsive'] ) && is_array( $analysis['responsive'] ) ? $analysis['responsive'] : array();
		$sections = isset( $analysis['sections'] ) && is_array( $analysis['sections'] ) ? $analysis['sections'] : array();

		return array(
			'page' => array(
				'url'         => self::url( isset( $website['url'] ) ? $website['url'] : '' ),
				'final_url'   => self::url( isset( $website['final_url'] ) ? $website['final_url'] : '' ),
				'title'       => self::text( isset( $website['title'] ) ? $website['title'] : '', 300 ),
				'type'        => self::text( isset( $website['type'] ) ? $website['type'] : 'Unknown', 80 ),
				'type_confidence' => self::confidence( isset( $website['type_confidence'] ) ? $website['type_confidence'] : 0 ),
				'status'      => absint( isset( $website['status'] ) ? $website['status'] : 0 ),
				'description' => self::text( isset( $meta['description'] ) ? $meta['description'] : '', 1000 ),
				'language'    => self::text( isset( $meta['language'] ) ? $meta['language'] : '', 35 ),
				'viewport'    => self::text( isset( $meta['viewport'] ) ? $meta['viewport'] : '', 300 ),
			),
			'content' => array(
				'headings'   => self::limit_list( isset( $content['headings'] ) ? $content['headings'] : array(), 100 ),
				'paragraphs' => self::limit_list( isset( $content['paragraphs'] ) ? $content['paragraphs'] : array(), 200 ),
				'images'     => self::limit_list( isset( $content['images'] ) ? $content['images'] : array(), 100 ),
				'links'      => self::limit_list( isset( $content['links'] ) ? $content['links'] : array(), 200 ),
			),
			'design' => array(
				'colors'           => self::limit_list( isset( $design['colors'] ) ? $design['colors'] : array(), 100 ),
				'fonts'            => self::limit_list( isset( $design['fonts'] ) ? $design['fonts'] : array(), 100 ),
				'spacing'          => self::limit_list( isset( $design['spacing'] ) ? $design['spacing'] : array(), 100 ),
				'border_radius'    => self::limit_list( isset( $design['border_radius'] ) ? $design['border_radius'] : array(), 100 ),
				'container_widths' => self::limit_list( isset( $design['container_widths'] ) ? $design['container_widths'] : array(), 100 ),
			),
			'responsive' => array(
				'viewport_meta'          => ! empty( $responsive['viewport_meta'] ),
				'media_queries_detected' => ! empty( $responsive['media_queries_detected'] ),
				'breakpoints'           => self::dedupe( self::limit_list( isset( $responsive['breakpoints'] ) ? $responsive['breakpoints'] : array(), 50 ) ),
			),
			'sections' => self::limit_list( $sections, Analysis_Limits::MAX_SECTIONS ),
		);
	}

	/**
	 * Normalize text without losing useful content.
	 *
	 * @param mixed $value      Input value.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	public static function text( $value, $max_length = 240 ) {
		return Security::clean_text( $value, $max_length );
	}

	/**
	 * Normalize a URL for representation metadata.
	 *
	 * @param mixed $url Raw URL.
	 * @return string
	 */
	public static function url( $url ) {
		$normalized = Security::normalize_http_url( is_string( $url ) ? $url : '' );
		return is_string( $normalized ) ? $normalized : '';
	}

	/**
	 * Clamp an inference confidence to the inclusive 0..1 range.
	 *
	 * @param mixed $value Confidence value.
	 * @return float
	 */
	public static function confidence( $value ) {
		$value = is_numeric( $value ) ? (float) $value : 0.0;
		return round( max( 0.0, min( 1.0, $value ) ), 2 );
	}

	/**
	 * Normalize a CSS scalar while preserving its original unit/value.
	 *
	 * @param mixed $value      CSS value.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	public static function css_value( $value, $max_length = 120 ) {
		$value = self::text( $value, $max_length );
		$value = preg_replace( '/url\s*\([^)]*\)/i', 'url()', $value );
		$value = preg_replace( '/(?:javascript|vbscript|data):/i', '', $value );
		$value = preg_replace( '/expression\s*\([^)]*\)/i', '', $value );
		$value = preg_replace( '/[<>{};]/', ' ', $value );
		return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
	}

	/**
	 * Remove duplicates while preserving first-seen order.
	 *
	 * @param mixed $values Candidate values.
	 * @return array<int, mixed>
	 */
	public static function dedupe( $values ) {
		if ( ! is_array( $values ) ) {
			return array();
		}
		$seen = array();
		$result = array();
		foreach ( $values as $value ) {
			$key = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value ) );
			$key = is_string( $key ) ? $key : serialize( $value );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$result[] = $value;
		}
		return $result;
	}

	/**
	 * Limit an array without changing its order.
	 *
	 * @param mixed $values Values.
	 * @param int   $limit  Maximum count.
	 * @return array<int, mixed>
	 */
	public static function limit_list( $values, $limit ) {
		if ( ! is_array( $values ) ) {
			return array();
		}
		return array_slice( $values, 0, max( 0, (int) $limit ) );
	}

	/**
	 * Create a stable token key.
	 *
	 * @param mixed $value Token value.
	 * @return string
	 */
	public static function token_key( $value ) {
		return strtolower( trim( (string) $value ) );
	}

	/**
	 * Normalize a source selector for bounded traceability output.
	 *
	 * @param mixed $selector Selector.
	 * @return string
	 */
	public static function selector( $selector ) {
		return substr( self::text( $selector, Analysis_Limits::MAX_SELECTOR_LENGTH ), 0, Analysis_Limits::MAX_SELECTOR_LENGTH );
	}
}
