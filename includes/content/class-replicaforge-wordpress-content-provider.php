<?php
/**
 * Phase 14: the WordPress content provider.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the destination WordPress installation: pages, posts, taxonomies, media, and
 * public custom post types.
 *
 * ### Everything is read through WordPress APIs
 *
 * §7 is explicit: use WordPress APIs rather than direct database queries. That is not a
 * style preference. A direct `$wpdb` query for posts bypasses the `posts_where` filter,
 * so it would return drafts and private posts the user cannot see; it bypasses
 * capability checks entirely; and it breaks on multisite. Everything here goes through
 * `get_posts()`, `get_terms()`, `get_post_types()`, or `wp_get_attachment_url()`.
 *
 * ### What it refuses to expose
 *
 * - **Non-public post types.** A post type with `public => false` is excluded
 *   outright. This is what keeps a private internal CPT out of a mapping destination.
 * - **Posts the user cannot read.** Every query is scoped by the `user_id` passed in,
 *   using `post_status => 'publish'` for public reads and WordPress's own authorship
 *   rules for the user's own drafts.
 * - **Custom field *values*.** §39 permits detecting a custom field's *structure*, and
 *   §7 forbids modifying existing content. This provider reports which meta keys exist
 *   and what they look like, and never returns a stored value for an arbitrary key —
 *   because meta keys are user-supplied plugin territory and one of them may be a
 *   licence key, an internal token, or a customer's private note.
 */
final class WordPress_Content_Provider implements Content_Provider_Contract {

	/**
	 * Entity type for a WordPress post.
	 *
	 * @var string
	 */
	const ENTITY_POST = 'post';

	/**
	 * Entity type for a WordPress page.
	 *
	 * @var string
	 */
	const ENTITY_PAGE = 'page';

	/**
	 * Entity type for a media item.
	 *
	 * @var string
	 */
	const ENTITY_MEDIA = 'media';

	/**
	 * Entity type for a term.
	 *
	 * @var string
	 */
	const ENTITY_TERM = 'term';

	/**
	 * Maximum records one page may return.
	 *
	 * @var int
	 */
	const MAX_PER_PAGE = 200;

	/**
	 * The user whose readable content is being read.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Custom-field structure, injected so §39's "only when safely available" holds.
	 *
	 * @var array<string, mixed>|null
	 */
	private $custom_fields;

	/**
	 * Constructor.
	 *
	 * @param int                     $user_id       Reading user.
	 * @param array<string, mixed>|null $custom_fields Optional custom-field structure report.
	 */
	public function __construct( $user_id = 0, $custom_fields = null ) {
		$this->user_id       = (int) $user_id;
		$this->custom_fields = is_array( $custom_fields ) ? $custom_fields : null;
	}

	/**
	 * Return the provider identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'wordpress';
	}

	/**
	 * Return available. WordPress is always here; that is the definition of the
	 * environment.
	 *
	 * @return bool
	 */
	public function is_available() {
		return function_exists( 'get_posts' ) && function_exists( 'get_post_types' );
	}

	/**
	 * Return why the provider is unavailable.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		return $this->is_available()
			? ''
			: __( 'WordPress content APIs are not available in this context, so no destination content could be read.', 'replicaforge' );
	}

	/**
	 * Return a version for the content cache key.
	 *
	 * @return string
	 */
	public function version() {
		return 'wp1';
	}

	/**
	 * Return the entity types this provider supports.
	 *
	 * @return array<int, string>
	 */
	public function entity_types() {
		$out = array( self::ENTITY_POST, self::ENTITY_PAGE, self::ENTITY_MEDIA, self::ENTITY_TERM );

		// §38: public custom post types are first-class entities. A `property` CPT on a
		// real-estate site is exactly the kind of thing a source site has and a
		// destination may want to map onto.
		foreach ( $this->public_post_types() as $post_type ) {
			if ( in_array( $post_type, array( 'post', 'page', 'attachment' ), true ) ) {
				continue;
			}
			$out[] = $post_type;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Return the fields a WordPress entity type can supply.
	 *
	 * @param string $entity_type Entity type.
	 * @return array<int, array<string, mixed>>
	 */
	public function fields_for( $entity_type ) {
		$entity_type = (string) $entity_type;

		if ( self::ENTITY_MEDIA === $entity_type ) {
			return $this->media_fields();
		}
		if ( self::ENTITY_TERM === $entity_type ) {
			return $this->term_fields();
		}

		$object = get_post_type_object( $entity_type );
		if ( ! $object ) {
			return array();
		}

		$fields = array(
			$this->field( 'post_title', __( 'Title', 'replicaforge' ), 'text', true ),
			$this->field( 'post_content', __( 'Content', 'replicaforge' ), 'richtext', true ),
			$this->field( 'post_excerpt', __( 'Excerpt', 'replicaforge' ), 'richtext', true ),
			$this->field( 'post_date', __( 'Date', 'replicaforge' ), 'date', true ),
			$this->field( 'post_status', __( 'Status', 'replicaforge' ), 'enum', true ),
		);

		if ( post_type_supports( $entity_type, 'author' ) ) {
			$fields[] = $this->field( 'post_author', __( 'Author', 'replicaforge' ), 'relation', true );
		}
		if ( post_type_supports( $entity_type, 'thumbnail' ) ) {
			$fields[] = $this->field( 'featured_image', __( 'Featured image', 'replicaforge' ), 'image', true );
		}
		foreach ( get_object_taxonomies( $entity_type ) as $taxonomy ) {
			$taxonomy_object = get_taxonomy( $taxonomy );
			if ( ! $taxonomy_object || ! $taxonomy_object->public ) {
				continue;
			}
			$fields[] = $this->field( 'taxonomy:' . $taxonomy, (string) $taxonomy_object->labels->name, 'relation', true );
		}

		// §38: a custom field is *detected* and requires review. It is never offered as
		// a writable destination on the strength of its key name alone, because a key
		// called `price` on a `property` CPT means something entirely different from
		// `price` on a `product`.
		foreach ( $this->custom_field_candidates( $entity_type ) as $meta_key ) {
			$fields[] = array(
				'provider'     => $this->id(),
				'entity_type'  => $entity_type,
				'field'        => 'meta:' . $meta_key,
				'label'        => $meta_key,
				'data_type'    => 'text',
				// `editable` is true — a meta key is writable — but `confidence` is low
				// and `requires_review` is true, so nothing auto-applies onto it.
				'availability' => 'detected',
				'editable'     => true,
				'confidence'   => 0.4,
				'requires_review' => true,
				'note'         => __( 'This custom field was detected but its meaning is unknown, so any mapping onto it needs review.', 'replicaforge' ),
			);
		}

		return $fields;
	}

	/**
	 * Return a page of destination entities.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $page        One-based page number.
	 * @param int    $per_page    Records per page.
	 * @param array  $args        Optional filters.
	 * @return array<string, mixed>
	 */
	public function entities( $entity_type, $page = 1, $per_page = 0, array $args = array() ) {
		$entity_type = (string) $entity_type;
		$per_page    = ( $per_page > 0 ) ? min( (int) $per_page, self::MAX_PER_PAGE ) : 20;
		$page        = max( 1, (int) $page );

		$out = array(
			'provider'   => $this->id(),
			'entity_type'=> $entity_type,
			'page'       => $page,
			'per_page'   => $per_page,
			'total'      => 0,
			'total_pages'=> 0,
			'items'      => array(),
			'available'  => $this->is_available(),
		);

		if ( ! $out['available'] ) {
			$out['reason'] = $this->unavailable_reason();
			return $out;
		}

		if ( self::ENTITY_MEDIA === $entity_type ) {
			return $this->media_page( $page, $per_page, $args, $out );
		}
		if ( self::ENTITY_TERM === $entity_type ) {
			return $this->term_page( $page, $per_page, $args, $out );
		}

		if ( ! in_array( $entity_type, $this->entity_types(), true ) ) {
			$out['reason'] = __( 'That content type is not available on this site.', 'replicaforge' );
			return $out;
		}

		$query = array_merge(
			array(
				'post_type'      => $entity_type,
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// No `post_password` posts and no private statuses. A password-protected
				// post is a private record even when its status is `publish`.
				'has_password'   => false,
			),
			$args
		);

		// The user's own drafts are included *for them only*, because a mapping preview
		// is more useful against content the user can actually edit. `get_posts` scopes
		// by capability once `perm` is set, so a draft belonging to somebody else is
		// never returned.
		if ( $this->user_id > 0 && current_user_can( 'edit_posts' ) ) {
			$query['perm'] = 'readable';
		}

		$found = get_posts( $query );

		$out['total']       = (int) ( $found instanceof WP_Query ? $found->found_posts : 0 );
		$out['total_pages'] = ( $per_page > 0 ) ? (int) ceil( $out['total'] / $per_page ) : 0;
		foreach ( (array) $found as $post ) {
			$out['items'][] = $this->post_summary( $post );
		}

		return $out;
	}

	/**
	 * Return one entity's field values.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity id.
	 * @return array<string, mixed>|null
	 */
	public function entity( $entity_type, $entity_id ) {
		$entity_type = (string) $entity_type;
		$entity_id   = (int) $entity_id;
		if ( $entity_id < 1 ) {
			return null;
		}

		if ( self::ENTITY_MEDIA === $entity_type ) {
			$attachment = get_post( $entity_id );
			return ( $attachment && 'attachment' === $attachment->post_type ) ? $this->post_summary( $attachment ) : null;
		}

		$post = get_post( $entity_id );
		if ( ! $post || $post->post_type !== $entity_type ) {
			return null;
		}
		// `current_user_can( 'read_post', $id )` is WordPress's own answer to "may this
		// user see this", including authorship and status rules. Reimplementing it
		// would be how a private post leaks.
		if ( ! current_user_can( 'read_post', $entity_id ) ) {
			return null;
		}

		return $this->post_summary( $post, true );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Build a field descriptor.
	 *
	 * @param string $field     Field name.
	 * @param string $label     Label.
	 * @param string $data_type Data type.
	 * @param bool   $editable  Whether the destination can write it.
	 * @return array<string, mixed>
	 */
	private function field( $field, $label, $data_type, $editable ) {
		return array(
			'provider'      => $this->id(),
			'entity_type'   => '',
			'field'         => (string) $field,
			'label'         => (string) $label,
			'data_type'     => (string) $data_type,
			'availability'  => 'available',
			'editable'      => (bool) $editable,
			'confidence'    => 0.95,
			'requires_review' => false,
		);
	}

	/**
	 * Return the public custom post types.
	 *
	 * `public` and `publicly_queryable` are both required. A post type that is
	 * registered but not publicly queryable — an internal helper type, a revision-like
	 * type, a `show_ui`-only type — is not a mapping destination, and including it
	 * would offer a user a field on a record they did not know existed.
	 *
	 * @return array<int, string>
	 */
	private function public_post_types() {
		$out = array();
		foreach ( get_post_types( array(), 'names' ) as $name ) {
			$object = get_post_type_object( $name );
			if ( ! $object ) {
				continue;
			}
			if ( ! $object->public || ! $object->publicly_queryable ) {
				continue;
			}
			$out[] = (string) $name;
		}
		return $out;
	}

	/**
	 * Return a summary of one post.
	 *
	 * @param \WP_Post $post    Post.
	 * @param bool     $include Whether to include the field values.
	 * @return array<string, mixed>
	 */
	private function post_summary( $post, $include = false ) {
		$summary = array(
			'entity_id'   => (int) $post->ID,
			'entity_type' => (string) $post->post_type,
			'title'       => $this->bounded( (string) $post->post_title, 200 ),
			'status'      => (string) $post->post_status,
			'date'        => (string) $post->post_date,
			'author'      => (int) $post->post_author,
			'url'         => (string) get_permalink( $post ),
			'edit_link'   => (string) get_edit_post_link( $post, 'raw' ),
			'fingerprint' => Content_Fingerprint::of( 'post_title', (string) $post->post_title ),
			'edit_url'    => (string) wp_json_encode( Content_Fingerprint::normalise_title( (string) $post->post_title ) ),
			'slug_key'    => Content_Fingerprint::normalise_slug( $post->post_name ),
		);

		$thumbnail = get_post_thumbnail_id( $post );
		$summary['featured_image'] = $thumbnail ? (string) wp_get_attachment_url( $thumbnail ) : '';

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$taxonomy_object = get_taxonomy( $taxonomy );
			if ( ! $taxonomy_object || ! $taxonomy_object->public ) {
				continue;
			}
			$names = array();
			foreach ( (array) wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) ) as $name ) {
				if ( is_string( $name ) ) {
					$names[] = $name;
				}
			}
			$terms[ $taxonomy ] = $names;
		}
		$summary['taxonomies'] = $terms;

		if ( $include ) {
			$summary['values'] = array(
				'post_title'    => (string) $post->post_title,
				'post_content'  => (string) $post->post_content,
				'post_excerpt'  => (string) $post->post_excerpt,
				'post_date'     => (string) $post->post_date,
				'post_status'   => (string) $post->post_status,
				'post_author'   => (int) $post->post_author,
				'featured_image'=> $summary['featured_image'],
			);
		}

		return $summary;
	}

	/**
	 * Return a page of media.
	 *
	 * @param int                  $page     Page.
	 * @param int                  $per_page Per page.
	 * @param array                $args     Filters.
	 * @param array<string, mixed> $out      Result skeleton.
	 * @return array<string, mixed>
	 */
	private function media_page( $page, $per_page, array $args, array $out ) {
		$query = new \WP_Query(
			array_merge(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => $per_page,
					'paged'          => $page,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				),
				$args
			)
		);

		$out['total']       = (int) $query->found_posts;
		$out['total_pages'] = ( $per_page > 0 ) ? (int) ceil( $out['total'] / $per_page ) : 0;
		foreach ( (array) $query->posts as $attachment ) {
			$out['items'][] = array(
				'entity_id'   => (int) $attachment->ID,
				'entity_type' => self::ENTITY_MEDIA,
				'title'       => $this->bounded( (string) $attachment->post_title, 200 ),
				'url'         => (string) wp_get_attachment_url( $attachment ),
				'mime'        => (string) $attachment->post_mime_type,
				'image_key'   => Content_Fingerprint::image_key( (string) wp_get_attachment_url( $attachment ) ),
				'date'        => (string) $attachment->post_date,
			);
		}

		return $out;
	}

	/**
	 * Return a page of terms.
	 *
	 * @param int                  $page     Page.
	 * @param int                  $per_page Per page.
	 * @param array                $args     Filters.
	 * @param array<string, mixed> $out      Result skeleton.
	 * @return array<string, mixed>
	 */
	private function term_page( $page, $per_page, array $args, array $out ) {
		$taxonomy = isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : 'category';
		$object   = get_taxonomy( $taxonomy );
		if ( ! $object || ! $object->public ) {
			$out['reason'] = __( 'That taxonomy is not publicly available on this site.', 'replicaforge' );
			return $out;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $terms ) ) {
			$out['reason'] = __( 'That taxonomy could not be read.', 'replicaforge' );
			return $out;
		}

		$out['total'] = (int) ( isset( $terms['total'] ) ? (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) : count( $terms ) );
		$out['total_pages'] = ( $per_page > 0 ) ? (int) ceil( $out['total'] / $per_page ) : 0;

		foreach ( (array) $terms as $term ) {
			if ( ! is_object( $term ) ) {
				continue;
			}
			$out['items'][] = array(
				'entity_id'   => (int) $term->term_id,
				'entity_type' => self::ENTITY_TERM,
				'taxonomy'    => $taxonomy,
				'title'       => $this->bounded( (string) $term->name, 200 ),
				'slug'        => (string) $term->slug,
				'count'       => (int) $term->count,
				'parent'      => (int) $term->parent,
				'url'         => (string) get_term_link( $term ),
				'fingerprint' => Content_Fingerprint::of( 'term_name', (string) $term->name ),
				'edit_url'    => Content_Fingerprint::normalise_title( (string) $term->name ),
				'slug_key'    => Content_Fingerprint::normalise_slug( $term->slug ),
			);
		}

		return $out;
	}

	/**
	 * Return the media fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function media_fields() {
		return array(
			$this->field( 'post_title', __( 'Media title', 'replicaforge' ), 'text', true ),
			$this->field( 'alt_text', __( 'Alternative text', 'replicaforge' ), 'text', true ),
			$this->field( 'caption', __( 'Caption', 'replicaforge' ), 'richtext', true ),
			$this->field( 'url', __( 'File URL', 'replicaforge' ), 'url', false ),
		);
	}

	/**
	 * Return the term fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function term_fields() {
		return array(
			$this->field( 'name', __( 'Name', 'replicaforge' ), 'text', true ),
			$this->field( 'slug', __( 'Slug', 'replicaforge' ), 'text', true ),
			$this->field( 'description', __( 'Description', 'replicaforge' ), 'richtext', true ),
		);
	}

	/**
	 * Return detected custom field keys, when a structure report was supplied.
	 *
	 * §39 says to detect ACF-style structures "only when safely available through
	 * supported APIs" and not to assume ACF is installed. So this reads an injected
	 * report and nothing else: it never calls `get_post_meta( $id, '', true )` to
	 * enumerate a post's keys, because that would read the *values* of every meta key
	 * on the post into memory, and a meta key on a real site can be a licence key.
	 *
	 * @param string $entity_type Entity type.
	 * @return array<int, string>
	 */
	private function custom_field_candidates( $entity_type ) {
		if ( null === $this->custom_fields ) {
			return array();
		}
		$keys = (array) ( $this->custom_fields[ $entity_type ] ?? array() );
		$out  = array();
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && '' !== $key && ! Data_Redactor::is_secret_key( $key ) ) {
				$out[] = $key;
			}
			if ( count( $out ) >= 40 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Bound a string's length.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private function bounded( $value, $length ) {
		$value = (string) $value;
		return ( strlen( $value ) <= $length ) ? $value : substr( $value, 0, $length ) . '…';
	}
}
