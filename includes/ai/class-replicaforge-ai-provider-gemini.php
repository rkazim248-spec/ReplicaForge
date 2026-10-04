<?php
/**
 * Google Gemini AI provider adapter.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Uses Gemini's fixed generation endpoint with a server-side API header.
 */
final class Ai_Provider_Gemini extends Ai_Provider_HTTP {

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'gemini';
	}

	/**
	 * Analyze a bounded request.
	 *
	 * @param array<string, mixed> $request Prompt request.
	 * @return array<string, mixed>
	 */
	public function analyze( array $request ) {
		$system = isset( $request['system'] ) && is_string( $request['system'] ) ? $request['system'] : '';
		$user   = isset( $request['user'] ) && is_string( $request['user'] ) ? Security::truncate_text( $request['user'], Ai_Limits::MAX_CONTEXT_BYTES ) : '';
		if ( '' === $system || '' === $user ) {
			return $this->error( 'provider_request_invalid', 'The AI request was incomplete.' );
		}
		$model = $this->model();
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,120}$/', $model ) ) {
			return $this->error( 'provider_model_invalid', 'The configured AI model is invalid.' );
		}
		$body = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'          => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $user ) ) ) ),
			'generationConfig'  => array( 'temperature' => 0.1, 'responseMimeType' => 'application/json' ),
		);
		$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
		$response = $this->post_json( $endpoint, $body, array( 'x-goog-api-key' => $this->api_key() ) );
		if ( empty( $response['success'] ) ) {
			return $response;
		}
		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$parts = isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ? $data['candidates'][0]['content']['parts'] : array();
		$content = '';
		foreach ( $parts as $part ) {
			if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$content .= $part['text'];
			}
		}
		if ( '' === trim( $content ) ) {
			return $this->error( 'provider_empty_response', 'The AI provider returned no reconstruction content.' );
		}
		return array( 'success' => true, 'content' => substr( $content, 0, Ai_Limits::MAX_OUTPUT_BYTES ) );
	}

	/**
	 * Perform an explicit, minimal connectivity check.
	 *
	 * @return array<string, mixed>
	 */
	public function test_connection() {
		$result = $this->analyze(
			array(
				'system' => 'Return only the JSON object {"ok":true}.',
				'user'   => 'Connectivity test. Return only {"ok":true}.',
			)
		);
		if ( empty( $result['success'] ) ) {
			return $result;
		}
		$decoded = json_decode( isset( $result['content'] ) ? $result['content'] : '', true );
		if ( ! is_array( $decoded ) || empty( $decoded['ok'] ) ) {
			return $this->error( 'provider_test_invalid_response', 'The AI provider connected but returned an unexpected test response.' );
		}
		return array( 'success' => true, 'message' => 'The AI provider connection succeeded.' );
	}
}
