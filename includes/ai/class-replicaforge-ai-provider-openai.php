<?php
/**
 * OpenAI-compatible AI provider adapter.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Uses a fixed OpenAI API endpoint and a server-side bearer credential.
 */
final class Ai_Provider_OpenAI extends Ai_Provider_HTTP {

	/**
	 * Provider identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'openai';
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
		$body = array(
			'model'       => $this->model(),
			'temperature' => 0.1,
			'messages'    => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			'response_format' => array( 'type' => 'json_object' ),
		);
		$response = $this->post_json(
			'https://api.openai.com/v1/chat/completions',
			$body,
			array( 'Authorization' => 'Bearer ' . $this->api_key() )
		);
		if ( empty( $response['success'] ) ) {
			return $response;
		}
		$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$content = $this->extract_text( isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : ( isset( $data['output_text'] ) ? $data['output_text'] : '' ) );
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
