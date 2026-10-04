<?php
/**
 * Frontend metadata analyzer for ReplicaForge.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates the Phase 1 extraction pipeline and invokes the Phase 2
 * design-understanding service additively.
 *
 * Detailed extraction is delegated to small services so a future
 * computer-vision/AI implementation can replace one boundary without coupling
 * itself to the REST or admin layers.
 */
final class Analyzer {

	/** Maximum headings retained in the result. */
	const MAX_HEADINGS = 100;

	/** Maximum paragraphs retained in the result. */
	const MAX_PARAGRAPHS = 200;

	/** Maximum images retained in the result. */
	const MAX_IMAGES = 100;

	/** Maximum links retained in the result. */
	const MAX_LINKS = 200;

	/** Maximum sections retained in the result. */
	const MAX_SECTIONS = 100;

	/**
	 * URL validator.
	 *
	 * @var Url_Validator
	 */
	private $validator;

	/**
	 * Safe HTTP client.
	 *
	 * @var Http_Client
	 */
	private $http_client;

	/**
	 * HTML parser.
	 *
	 * @var Html_Parser
	 */
	private $parser;

	/**
	 * Content extraction service.
	 *
	 * @var Content_Extractor
	 */
	private $content_extractor;

	/**
	 * Design extraction service.
	 *
	 * @var Design_Extractor
	 */
	private $design_extractor;

	/**
	 * Structure/type detection service.
	 *
	 * @var Structure_Detector
	 */
	private $structure_detector;

	/**
	 * Phase 2 design-understanding service.
	 *
	 * @var Design_Analyzer
	 */
	private $design_analyzer;

	/**
	 * Constructor.
	 *
	 * @param Url_Validator|null $validator   URL validator.
	 * @param Http_Client|null   $http_client HTTP client.
	 * @param Html_Parser|null   $parser      HTML parser.
	 * @param Design_Analyzer|null $design_analyzer Phase 2 service.
	 */
	public function __construct( $validator = null, $http_client = null, $parser = null, $design_analyzer = null ) {
		$this->validator         = $validator instanceof Url_Validator ? $validator : new Url_Validator();
		$this->http_client       = $http_client instanceof Http_Client ? $http_client : new Http_Client( $this->validator );
		$this->parser            = $parser instanceof Html_Parser ? $parser : new Html_Parser();
		$this->content_extractor = new Content_Extractor( $this->parser );
		$this->design_extractor  = new Design_Extractor( $this->parser );
		$this->structure_detector = new Structure_Detector( $this->parser );
		$this->design_analyzer   = $design_analyzer instanceof Design_Analyzer ? $design_analyzer : new Design_Analyzer( null, null, null, null, null, null, null, new Stylesheet_Loader( $this->http_client ) );
	}

	/**
	 * Validate, fetch, and analyze a URL.
	 *
	 * @param string $url Submitted URL.
	 * @return array<string, mixed>
	 */
	public function analyze_url( $url ) {
		$started_at = microtime( true );
		$response   = $this->http_client->fetch( $url );

		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			return is_array( $response ) ? $response : $this->error( 'unexpected_response', 'The website returned an unexpected response.', 502 );
		}

		$analysis = $this->analyze( $url, $response, $started_at );
		if ( empty( $analysis['success'] ) && isset( $analysis['error'] ) ) {
			return $analysis;
		}

		return array(
			'success' => true,
			'data'    => $analysis,
		);
	}

	/**
	 * Analyze a response that has already been fetched by the safe client.
	 *
	 * @param string               $url        Original URL.
	 * @param array<string, mixed> $response   HTTP client response.
	 * @param float|null           $started_at Request start time.
	 * @return array<string, mixed>
	 */
	public function analyze( $url, $response, $started_at = null ) {
		$started_at = is_numeric( $started_at ) ? (float) $started_at : microtime( true );

		try {
			if ( ! is_array( $response ) || empty( $response['success'] ) || ! isset( $response['body'] ) || ! is_string( $response['body'] ) ) {
				return $this->error( 'unexpected_response', 'The website returned an unexpected response.', 502 );
			}

			$safe_url  = Security::normalize_http_url( is_string( $url ) ? $url : '' );
			$final_url = isset( $response['final_url'] ) ? Security::normalize_http_url( $response['final_url'] ) : $safe_url;
			$safe_url  = is_string( $safe_url ) ? $safe_url : '';
			$final_url = is_string( $final_url ) ? $final_url : $safe_url;
			if ( '' === $final_url ) {
				return $this->error( 'invalid_url', 'Invalid URL.', 400 );
			}

			$parsed = $this->parser->parse( $response['body'] );
			if ( empty( $parsed['success'] ) ) {
				return isset( $parsed['error'] ) && is_array( $parsed['error'] ) ? $parsed : $this->error( 'invalid_document', 'The website returned an unexpected response.', 502 );
			}

			$document = $this->parser->get_document( $parsed );
			$elements = $this->get_elements( $document );
			$title    = $this->content_extractor->extract_title( $document );
			$meta     = $this->content_extractor->extract_meta( $elements );
			$content  = array(
				'headings'   => $this->content_extractor->extract_headings( $elements ),
				'paragraphs' => $this->content_extractor->extract_paragraphs( $elements ),
				'images'     => $this->content_extractor->extract_images( $elements, $final_url ),
				'links'      => $this->content_extractor->extract_links( $elements, $final_url ),
			);
			$design_result = $this->design_extractor->extract( $elements );
			$sections      = $this->structure_detector->detect_sections( $elements );
			$type          = $this->structure_detector->detect_website_type( $title, $content['headings'], $content['paragraphs'], $content['links'], $sections );

			$phase1 = array(
				'schema_version' => '1.0',
				'website'        => array(
					'url'             => $safe_url,
					'final_url'       => $final_url,
					'status'          => isset( $response['status'] ) ? absint( $response['status'] ) : 0,
					'title'           => $title,
					'type'            => $type['type'],
					'type_confidence' => $type['confidence'],
				),
				'meta'           => array(
					'description' => $meta['description'],
					'language'    => $meta['language'],
					'viewport'    => $meta['viewport'],
				),
				'content'        => $content,
				'design'         => $design_result['design'],
				'responsive'     => array_merge(
					array( 'viewport_meta' => '' !== $meta['viewport'] ),
					$design_result['responsive']
				),
				'sections'       => $sections,
				'analysis'       => array(
					'analyzed_at' => gmdate( 'c' ),
					'duration_ms' => 0,
				),
			);

			$design_result_v2 = $this->design_analyzer->analyze( $phase1, $document );
			if ( ! empty( $design_result_v2['success'] ) && isset( $design_result_v2['representation'] ) ) {
				$phase1['design_representation'] = $design_result_v2['representation'];
			} else {
				$phase1['phase2_error'] = array(
					'code'    => isset( $design_result_v2['error']['code'] ) ? $design_result_v2['error']['code'] : 'design_analysis_failed',
					'message' => 'The page structure could not be analyzed.',
				);
			}

			$phase1['analysis']['duration_ms'] = max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
			return $phase1;
		} catch ( \Throwable $exception ) {
			Security::log_event( 'analysis_failed', array( 'reason' => 'exception' ) );
			return $this->error( 'analysis_failed', 'The website returned an unexpected response.', 500 );
		}
	}

	/**
	 * Return a bounded list of elements in document order.
	 *
	 * @param \DOMDocument $document Parsed document.
	 * @return array<int, \DOMElement>
	 */
	private function get_elements( $document ) {
		$elements = array();
		if ( ! $document instanceof \DOMDocument ) {
			return $elements;
		}

		foreach ( $document->getElementsByTagName( '*' ) as $element ) {
			if ( $element instanceof \DOMElement ) {
				$elements[] = $element;
			}
			if ( count( $elements ) >= 20000 ) {
				break;
			}
		}

		return $elements;
	}

	/**
	 * Return a safe analysis error.
	 *
	 * @param string $code    Error code.
	 * @param string $message User-facing message.
	 * @param int    $status  Error status.
	 * @return array<string, mixed>
	 */
	private function error( $code, $message, $status ) {
		return array(
			'success' => false,
			'error'   => array(
				'code'    => sanitize_key( $code ),
				'message' => (string) $message,
				'status'  => absint( $status ),
			),
		);
	}
}
