<?php
/**
 * WordPress draft creation for ReplicaForge Phase 4.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a new Elementor draft page and stores generation metadata.
 *
 * A draft is always created. Nothing is published, no existing post, theme
 * template, menu, widget, or WooCommerce record is modified, and a failed
 * generation is rolled back so no partially written draft is left behind.
 */
final class Elementor_Draft_Service {

	/**
	 * Compatibility service.
	 *
	 * @var Elementor_Compatibility
	 */
	private $compatibility;

	/**
	 * Document validator.
	 *
	 * @var Elementor_Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param Elementor_Compatibility|null $compatibility Optional compatibility service.
	 * @param Elementor_Validator|null     $validator     Optional document validator.
	 */
	public function __construct( $compatibility = null, $validator = null ) {
		$this->compatibility = $compatibility instanceof Elementor_Compatibility ? $compatibility : new Elementor_Compatibility();
		$this->validator     = $validator instanceof Elementor_Validator ? $validator : new Elementor_Validator();
	}

	/**
	 * Create a draft page and store the generated Elementor document.
	 *
	 * @param array<int, mixed>    $elements Elementor element array.
	 * @param array<string, mixed> $meta     Generation metadata.
	 * @return array<string, mixed>
	 */
	public function create( array $elements, array $meta ) {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return $this->failure( array( 'insufficient_capability' ), array( __( 'You do not have permission to create pages on this site.', 'replicaforge' ) ) );
		}

		$check = $this->validator->validate_document( $elements );
		if ( empty( $check['valid'] ) ) {
			return $this->failure( $check['errors'], $check['warnings'] );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'     => $this->post_title( $meta ),
				'post_type'      => 'page',
				'post_status'    => 'draft',
				'post_content'   => '',
				'post_author'    => get_current_user_id(),
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! is_int( $post_id ) || $post_id <= 0 ) {
			Security::log_event( 'generation_failed', array( 'code' => 'draft_insert_failed', 'reason' => 'post' ) );
			return $this->failure( array( 'draft_insert_failed' ), array() );
		}

		$result = $this->save_document( $post_id, $elements, $meta );
		if ( empty( $result['success'] ) ) {
			$this->rollback( $post_id, 'document_save_failed' );
			return $this->failure( $result['errors'], $result['warnings'], 0 );
		}

		$post_check = $this->validator->validate_saved_post( $post_id );
		if ( empty( $post_check['valid'] ) ) {
			$this->rollback( $post_id, 'post_validation_failed' );
			return $this->failure( $post_check['errors'], $post_check['warnings'], 0 );
		}

		Security::log_event( 'generation_completed', array( 'count' => (int) $post_check['count'], 'code' => 'draft_created' ) );

		return array(
			'success'    => true,
			'draft_id'   => (int) $post_id,
			'edit_url'   => (string) get_edit_post_link( $post_id, 'raw' ),
			'preview_url' => (string) get_preview_post_link( $post_id ),
			'elementor_edit_url' => $this->elementor_edit_url( $post_id ),
			'element_count' => (int) $post_check['count'],
			'max_depth'  => (int) $post_check['max_depth'],
			'warnings'   => array_values( array_unique( array_merge( $result['warnings'], $post_check['warnings'] ) ) ),
			'errors'     => array(),
		);
	}

	/**
	 * Persist the Elementor document for a draft.
	 *
	 * @param int                  $post_id  Draft post ID.
	 * @param array<int, mixed>    $elements Element array.
	 * @param array<string, mixed> $meta     Generation metadata.
	 * @return array<string, mixed>
	 */
	private function save_document( $post_id, array $elements, array $meta ) {
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', $this->template_type() );

		$document = $this->document_for( $post_id );
		if ( ! is_object( $document ) || ! method_exists( $document, 'save' ) ) {
			return array(
				'success'  => false,
				'errors'   => array( 'elementor_document_unavailable' ),
				'warnings' => array(),
			);
		}

		try {
			$saved = $document->save( array( 'elements' => $elements ) );
		} catch ( \Throwable $exception ) {
			Security::log_event( 'generation_failed', array( 'code' => 'document_save_exception', 'reason' => 'exception' ) );
			return array(
				'success'  => false,
				'errors'   => array( 'document_save_exception' ),
				'warnings' => array(),
			);
		}

		if ( true !== $saved ) {
			return array(
				'success'  => false,
				'errors'   => array( 'document_save_rejected' ),
				'warnings' => array(),
			);
		}

		// The generated document hash is the baseline Phase 6 uses to tell a
		// ReplicaForge correction apart from a manual edit in the Elementor
		// editor. Phase 4 only writes it; Phase 6 never rewrites it silently.
		$raw_after = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $raw_after ) ) {
			update_post_meta( $post_id, Elementor_Limits::META_PREFIX . 'generation_hash', hash( 'sha256', $raw_after ) );
		}

		$this->store_metadata( $post_id, $meta );

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$check  = $this->validator->validate_document( is_array( $decoded ) ? $decoded : array() );
		if ( empty( $check['valid'] ) ) {
			return array(
				'success'  => false,
				'errors'   => $check['errors'],
				'warnings' => $check['warnings'],
			);
		}

		return array(
			'success'  => true,
			'errors'   => array(),
			'warnings' => $check['warnings'],
		);
	}

	/**
	 * Return the Elementor document instance for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return object|null
	 */
	private function document_for( $post_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! method_exists( '\Elementor\Plugin', 'instance' ) ) {
			return null;
		}
		$plugin = \Elementor\Plugin::instance();
		if ( ! is_object( $plugin ) || ! isset( $plugin->documents ) || ! is_object( $plugin->documents ) ) {
			return null;
		}
		$document = $plugin->documents->get( $post_id, false );
		return is_object( $document ) ? $document : null;
	}

	/**
	 * Store namespaced generation metadata on the draft.
	 *
	 * @param int                  $post_id Draft post ID.
	 * @param array<string, mixed> $meta    Generation metadata.
	 * @return void
	 */
	private function store_metadata( $post_id, array $meta ) {
		$prefix = Elementor_Limits::META_PREFIX;

		$scalars = array(
			'source_url'          => isset( $meta['source_url'] ) && is_string( $meta['source_url'] ) ? $meta['source_url'] : '',
			'schema_version'      => Elementor_Limits::SPEC_SCHEMA_VERSION,
			'generation_version'  => defined( 'REPLICAFORGE_VERSION' ) ? REPLICAFORGE_VERSION : '0.5.0',
			'generation_id'       => isset( $meta['generation_id'] ) && is_string( $meta['generation_id'] ) ? $meta['generation_id'] : '',
			'generated_at'        => gmdate( 'c' ),
			'phase'               => Elementor_Limits::PHASE,
			'elementor_version'   => $this->compatibility->version(),
			'ai_used'             => ! empty( $meta['ai_used'] ),
			'specification_id'     => isset( $meta['specification_id'] ) && is_string( $meta['specification_id'] ) ? $meta['specification_id'] : '',
		);

		foreach ( $scalars as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? '1' : '0';
			}
			update_post_meta( $post_id, $prefix . $key, sanitize_text_field( (string) $value ) );
		}

		$json_fields = array(
			'id_map'    => isset( $meta['id_map'] ) && is_array( $meta['id_map'] ) ? $meta['id_map'] : array(),
			'report'    => isset( $meta['report'] ) && is_array( $meta['report'] ) ? $meta['report'] : array(),
			'provenance' => isset( $meta['provenance'] ) && is_array( $meta['provenance'] ) ? $meta['provenance'] : array(),
		);
		foreach ( $json_fields as $key => $value ) {
			$encoded = wp_json_encode( $this->bound_json( $value ) );
			if ( is_string( $encoded ) ) {
				update_post_meta( $post_id, $prefix . $key, wp_slash( $encoded ) );
			}
		}
	}

	/**
	 * Bound a metadata payload before it is stored.
	 *
	 * @param mixed $value Payload.
	 * @return mixed
	 */
	private function bound_json( $value ) {
		$encoded = wp_json_encode( $value );
		if ( ! is_string( $encoded ) || strlen( $encoded ) <= 262144 ) {
			return $value;
		}
		return array(
			'truncated' => true,
			'reason'    => 'metadata_size_limit',
		);
	}

	/**
	 * Delete an incomplete draft.
	 *
	 * @param int    $post_id Draft post ID.
	 * @param string $reason  Safe reason code.
	 * @return void
	 */
	private function rollback( $post_id, $reason ) {
		$deleted = wp_delete_post( $post_id, true );
		Security::log_event(
			'generation_rolled_back',
			array(
				'code'   => sanitize_key( $reason ),
				'status' => $deleted ? 'deleted' : 'delete_failed',
			)
		);
	}

	/**
	 * Build the draft title.
	 *
	 * @param array<string, mixed> $meta Generation metadata.
	 * @return string
	 */
	private function post_title( array $meta ) {
		$source = isset( $meta['source_title'] ) && is_string( $meta['source_title'] ) ? trim( $meta['source_title'] ) : '';
		if ( '' === $source ) {
			$source = isset( $meta['source_host'] ) && is_string( $meta['source_host'] ) ? trim( $meta['source_host'] ) : '';
		}
		$source = Security::clean_text( $source, 120 );
		if ( '' === $source ) {
			/* translators: %s: Source website host. */
			return sprintf( __( 'ReplicaForge Draft — %s', 'replicaforge' ), 'source website' );
		}
		/* translators: %s: Source website title. */
		return sprintf( __( 'ReplicaForge Draft — %s', 'replicaforge' ), $source );
	}

	/**
	 * Return the Elementor editor URL for a draft.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function elementor_edit_url( $post_id ) {
		$document  = $this->document_for( $post_id );
		$action    = $document && method_exists( $document, 'get_edit_url' ) ? $document->get_edit_url() : '';
		if ( ! is_string( $action ) || '' === $action ) {
			$action = admin_url( 'post.php?post=' . absint( $post_id ) . '&action=elementor' );
		}
		return (string) $action;
	}

	/**
	 * Return the registered document type for pages.
	 *
	 * @return string
	 */
	private function template_type() {
		return 'wp-page';
	}

	/**
	 * Build a failure result.
	 *
	 * @param array<int, string> $errors   Error codes.
	 * @param array<int, string> $warnings Warning messages.
	 * @param int                $draft_id Optional draft ID.
	 * @return array<string, mixed>
	 */
	private function failure( array $errors, array $warnings, $draft_id = 0 ) {
		return array(
			'success'    => false,
			'draft_id'   => (int) $draft_id,
			'edit_url'   => '',
			'preview_url' => '',
			'elementor_edit_url' => '',
			'element_count' => 0,
			'max_depth'  => 0,
			'warnings'   => array_values( array_unique( $warnings ) ),
			'errors'     => array_values( array_unique( $errors ) ),
		);
	}
}
