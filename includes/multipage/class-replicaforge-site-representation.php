<?php
/**
 * Phase 12: the website-level reconstruction specification.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * The canonical website-level specification, and the gate that refuses to generate
 * from an invalid one.
 *
 * ### Why there is a separate document
 *
 * A page representation (Phase 2) answers "what is on this page". The website
 * specification answers "what is this website, and what do all these pages have in
 * common". Those are different questions, they change for different reasons, and
 * the second is what §62's map, §65's design system view, and §43's cross-page
 * validation all read from.
 *
 * So this is a distinct schema at {@see Site_Limits::SCHEMA_VERSION} rather than a
 * page representation with extra keys. Reusing one version number for both would
 * make a cache key ambiguous and would make "is this stale?" unanswerable — a
 * design system change invalidates pages that have not themselves changed.
 *
 * ### The validator is a gate, not a report
 *
 * §37 says validate before generation. {@see self::validate()} returns a verdict,
 * and the planner refuses to generate on a failing verdict. That is deliberate: a
 * specification with two shared components pointing at pages that are not in the
 * project will produce two broken pages, and finding out after generation means
 * cleaning up generated documents to fix it.
 */
final class Site_Representation {

	/**
	 * The specification.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Validation errors.
	 *
	 * @var array<int, string>
	 */
	private $errors = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Specification.
	 */
	public function __construct( array $data = array() ) {
		$this->data = $data;
	}

	/**
	 * Return the specification as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * Return the validation verdict.
	 *
	 * Errors are *errors* and warnings are *warnings*, and the difference is
	 * whether generation can proceed. A missing optional section is a warning. A
	 * page record without a source URL is an error, because there is nothing to
	 * reconstruct from and generating anyway would produce an empty page.
	 *
	 * @return array<string, mixed>
	 */
	public function validate() {
		$this->errors = array();
		$warnings     = array();
		$data         = $this->data;

		if ( ! isset( $data['schema_version'] ) || Site_Limits::SCHEMA_VERSION !== (string) $data['schema_version'] ) {
			$this->errors[] = 'invalid_schema_version';
		}

		foreach ( array( 'website', 'pages', 'global_design_system', 'shared_components', 'templates', 'navigation', 'assets', 'relationships', 'confidence', 'warnings' ) as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$this->errors[] = 'missing_' . $key;
			}
		}

		$website = isset( $data['website'] ) && is_array( $data['website'] ) ? $data['website'] : array();
		if ( ! isset( $website['name'] ) || ! is_string( $website['name'] ) ) {
			$this->errors[] = 'invalid_website_name';
		}
		if ( isset( $website['source_url'] ) && '' !== $website['source_url'] && ! $this->safe_url( (string) $website['source_url'] ) ) {
			$this->errors[] = 'unsafe_source_url';
		}

		$pages = isset( $data['pages'] ) && is_array( $data['pages'] ) ? $data['pages'] : array();
		if ( array() === $pages ) {
			$this->errors[] = 'no_pages';
		}
		if ( count( $pages ) > Site_Limits::MAX_PAGES ) {
			$this->errors[] = 'too_many_pages';
		}

		$page_ids = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				$this->errors[] = 'invalid_page_record';
				continue;
			}
			foreach ( array( 'page_id', 'source_url', 'type', 'status' ) as $key ) {
				if ( ! isset( $page[ $key ] ) || ! is_string( $page[ $key ] ) || '' === $page[ $key ] ) {
					$this->errors[] = 'invalid_page_' . $key;
				}
			}
			if ( ! empty( $page['page_id'] ) ) {
				$id = (string) $page['page_id'];
				if ( isset( $page_ids[ $id ] ) ) {
					// A duplicate page id makes every reference to it ambiguous, and an
					// ambiguous reference in a generation plan is a wrong page written.
					$this->errors[] = 'duplicate_page_id';
				}
				$page_ids[ $id ] = true;
			}
			if ( ! empty( $page['source_url'] ) && ! $this->safe_url( (string) $page['source_url'] ) ) {
				$this->errors[] = 'unsafe_page_url';
			}
			if ( ! empty( $page['type'] ) && ! Site_Limits::is_page_type( (string) $page['type'] ) ) {
				$this->errors[] = 'unknown_page_type';
			}
			if ( ! empty( $page['status'] ) && ! in_array( (string) $page['status'], array( 'pending', 'analyzed', 'selected', 'generating', 'generated', 'validated', 'needs_review', 'failed', 'excluded' ), true ) ) {
				$this->errors[] = 'unknown_page_status';
			}
		}

		// Every reference to a page must resolve. A shared component that names a
		// page not in the project is a component that will be written onto nothing.
		foreach ( (array) ( $data['shared_components'] ?? array() ) as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			if ( empty( $component['component_id'] ) || ! is_string( $component['component_id'] ) ) {
				$this->errors[] = 'invalid_component_id';
			}
			foreach ( (array) ( $component['pages'] ?? array() ) as $ref ) {
				if ( ! isset( $page_ids[ (string) $ref ] ) ) {
					$this->errors[] = 'component_references_unknown_page';
				}
			}
			if ( count( (array) ( $component['pages'] ?? array() ) ) < Site_Limits::MIN_SHARED_PAGES && empty( $component['overridden'] ) ) {
				$warnings[] = 'shared_component_on_one_page';
			}
		}

		foreach ( (array) ( $data['templates'] ?? array() ) as $template ) {
			if ( ! is_array( $template ) || empty( $template['template_id'] ) ) {
				$this->errors[] = 'invalid_template_id';
				continue;
			}
			foreach ( (array) ( $template['pages'] ?? array() ) as $ref ) {
				if ( ! isset( $page_ids[ (string) $ref ] ) ) {
					$this->errors[] = 'template_references_unknown_page';
				}
			}
		}

		// A source-to-replica URL map may only rewrite to a page that exists, or to an
		// external URL. Rewriting to a path with no page is a dead link that looks
		// correct in the spec and is broken in the browser.
		//
		// `/` is explicitly *allowed* as a target: the homepage legitimately maps to
		// the site root, and treating the root as a missing page would make every
		// specification with a homepage fail the gate. What is refused is an empty
		// target, which is a link to nowhere.
		foreach ( (array) ( $data['navigation']['url_map'] ?? array() ) as $source => $replica ) {
			if ( ! is_string( $replica ) || '' === trim( $replica ) ) {
				$this->errors[] = 'invalid_url_mapping';
				continue;
			}
			if ( $this->safe_url( $replica ) ) {
				continue;
			}
			// A local path target is fine as long as it is a path at all.
			if ( '' === (string) wp_parse_url( $replica, PHP_URL_PATH ) ) {
				$this->errors[] = 'invalid_url_mapping';
			}
		}

		$design = isset( $data['global_design_system'] ) && is_array( $data['global_design_system'] ) ? $data['global_design_system'] : array();
		if ( ! empty( $design ) && empty( $design['built'] ) && ! empty( $data['pages'] ) ) {
			// Not an error. A website whose pages produced no extractable tokens is a
			// real website, and refusing to generate it would be worse than generating
			// it with page-level styling only.
			$warnings[] = 'design_system_not_built';
		}
		foreach ( (array) ( $design['conflicts'] ?? array() ) as $conflict ) {
			if ( is_array( $conflict ) && 'conflict' === (string) ( $conflict['kind'] ?? '' ) ) {
				$warnings[] = 'unresolved_design_conflict';
			}
		}

		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			if ( isset( $asset['source_url'] ) && '' !== (string) $asset['source_url'] && ! $this->safe_url( (string) $asset['source_url'] ) ) {
				$this->errors[] = 'unsafe_asset_url';
			}
		}

		// Content/structure separation (§66) is checked structurally: a spec that
		// stores page copy inside a shared component has violated the boundary, and
		// it is worth refusing rather than quietly separating later.
		foreach ( (array) ( $data['shared_components'] ?? array() ) as $component ) {
			if ( is_array( $component ) && ! empty( $component['content'] ) && is_array( $component['content'] ) ) {
				$warnings[] = 'shared_component_carries_content';
			}
		}

		$warnings = array_values( array_unique( $warnings ) );

		return array(
			'valid'    => ( 0 === count( $this->errors ) ),
			'errors'   => $this->errors,
			'warnings' => $warnings,
			'pages'    => count( $pages ),
			'gate'     => ( 0 === count( $this->errors ) ) ? 'open' : 'closed',
		);
	}

	/**
	 * Return whether the specification can drive generation.
	 *
	 * @return bool
	 */
	public function is_valid() {
		$verdict = $this->validate();
		return (bool) $verdict['valid'];
	}

	/**
	 * Return the validation errors.
	 *
	 * @return array<int, string>
	 */
	public function get_validation_errors() {
		$this->validate();
		return $this->errors;
	}

	/**
	 * Return the pages of the specification.
	 *
	 * @param string $status Optional status filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function pages( $status = '' ) {
		$out = array();
		foreach ( (array) ( $this->data['pages'] ?? array() ) as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			if ( '' !== $status && (string) ( $page['status'] ?? '' ) !== (string) $status ) {
				continue;
			}
			$out[] = $page;
		}
		return $out;
	}

	/**
	 * Return one page record.
	 *
	 * @param string $page_id Page identifier.
	 * @return array<string, mixed>|null
	 */
	public function page( $page_id ) {
		$page_id = (string) $page_id;
		foreach ( (array) ( $this->data['pages'] ?? array() ) as $page ) {
			if ( is_array( $page ) && (string) ( $page['page_id'] ?? '' ) === $page_id ) {
				return $page;
			}
		}
		return null;
	}

	/**
	 * Return the specification as a set of relationship edges.
	 *
	 * §62's map is drawn from this, and it must be built from *recorded*
	 * relationships rather than from a guess. Two pages that share a template are
	 * related; two pages that merely both exist are not. Nothing here infers an edge
	 * that was not discovered.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function relationships() {
		$edges = array();

		foreach ( (array) ( $this->data['shared_components'] ?? array() ) as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$pages = array_values( (array) ( $component['pages'] ?? array() ) );
			for ( $i = 0; $i < count( $pages ); $i++ ) {
				for ( $j = $i + 1; $j < count( $pages ); $j++ ) {
					$edges[] = array(
						'from'  => (string) $pages[ $i ],
						'to'    => (string) $pages[ $j ],
						'via'   => 'shared_component',
						'via_id'=> (string) ( $component['component_id'] ?? '' ),
						'label' => (string) ( $component['role'] ?? '' ),
					);
				}
			}
		}

		foreach ( (array) ( $this->data['templates'] ?? array() ) as $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}
			$pages = array_values( (array) ( $template['pages'] ?? array() ) );
			for ( $i = 0; $i < count( $pages ); $i++ ) {
				for ( $j = $i + 1; $j < count( $pages ); $j++ ) {
					$edges[] = array(
						'from'   => (string) $pages[ $i ],
						'to'     => (string) $pages[ $j ],
						'via'    => 'template',
						'via_id' => (string) ( $template['template_id'] ?? '' ),
						'label'  => (string) ( $template['page_type'] ?? '' ),
					);
				}
			}
		}

		// Discovered navigation edges, deduplicated. A => B is recorded once even
		// when it appears in a header, a footer, and a sidebar.
		$seen = array();
		foreach ( (array) ( $this->data['navigation']['links'] ?? array() ) as $link ) {
			if ( ! is_array( $link ) || empty( $link['from_page'] ) || empty( $link['to_page'] ) ) {
				continue;
			}
			$key = (string) $link['from_page'] . '>' . (string) $link['to_page'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$edges[]      = array(
				'from'   => (string) $link['from_page'],
				'to'     => (string) $link['to_page'],
				'via'    => 'navigation',
				'via_id' => (string) ( $link['label'] ?? '' ),
				'label'  => (string) ( $link['label'] ?? '' ),
			);
		}

		return $edges;
	}

	/**
	 * Return whether a URL is safe to store in the specification.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function safe_url( $url ) {
		$url   = (string) $url;
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '0.0.0.0', '::1' ), true ) ) {
			return false;
		}
		if ( preg_match( '/^\d{1,3}(\.\d{1,3}){3}$/', $host ) ) {
			$parts_of_ip = array_map( 'intval', explode( '.', $host ) );
			if ( 10 === $parts_of_ip[0]
				|| 127 === $parts_of_ip[0]
				|| ( 172 === $parts_of_ip[0] && $parts_of_ip[1] >= 16 && $parts_of_ip[1] <= 31 )
				|| ( 192 === $parts_of_ip[0] && 168 === $parts_of_ip[1] )
				|| 0 === $parts_of_ip[0]
				|| $parts_of_ip[0] >= 224 ) {
				return false;
			}
		}
		if ( false !== strpos( $host, '169.254' ) ) {
			return false;
		}
		return true;
	}
}
