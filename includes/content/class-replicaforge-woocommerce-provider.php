<?php
/**
 * Phase 14: the WooCommerce content provider.
 *
 * @package ReplicaForge
 */

namespace ReplicaForge;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the destination WooCommerce store — when there is one.
 *
 * ### WooCommerce is optional, and its absence is a reported state
 *
 * A plugin cannot assume WooCommerce exists. It is not installed in every WordPress, and
 * it is not installed in the environment this phase was built and tested in. So this
 * provider detects availability through the documented signal
 * (`class_exists( 'WooCommerce' )`) and reports `WOOCOMMERCE_NOT_ACTIVE` with a plain
 * explanation when it is absent.
 *
 * The alternative — `if ( class_exists( 'WC_Product' ) ) { … }` scattered through a
 * mapping engine — produces two failures: a fatal on a site without WooCommerce, and a
 * mapper that silently treats a missing store as an empty one, which is how a price
 * gets "mapped" to nothing.
 *
 * ### The testability seam, and what it is not
 *
 * The WooCommerce API is reached through a small set of injectable callables
 * ({@see self::set_api()}). That exists so the *logic* — field vocabulary, price and
 * sale-price semantics, product-type handling, SKU normalisation, matching keys — can be
 * tested without a store. It is **not** a mock in production logic: with no API
 * injected, the provider reports itself unavailable and returns nothing, and
 * {@see self::is_available()} is false.
 *
 * **What that means for this phase's verification:** the field vocabulary and matching
 * behaviour are tested; the actual read against a real `WC_Product` is **not**, because
 * no store exists here. That is stated plainly in the completion report rather than
 * implied away.
 *
 * ### What this provider refuses to do
 *
 * - **Read customer data.** There is no method that returns an order, a customer, a
 *   billing or shipping address, or a payment record. §8 and §29 both forbid it, and the
 *   absence of the method is the enforcement.
 * - **Read private orders even to count them.** Only publicly-queryable product data is
 *   read; stock quantities and order counts are not, because a stock level is business
 *   data a mapping engine has no use for and a liability if leaked.
 * - **Write anything.** {@see Content_Provider_Contract} has no write method, and this
 *   class implements no write path of its own.
 */
final class WooCommerce_Provider implements Content_Provider_Contract {

	/**
	 * Entity type for a product.
	 *
	 * @var string
	 */
	const ENTITY_PRODUCT = 'product';

	/**
	 * Entity type for a product category.
	 *
	 * @var string
	 */
	const ENTITY_CATEGORY = 'product_category';

	/**
	 * Entity type for a product tag.
	 *
	 * @var string
	 */
	const ENTITY_TAG = 'product_tag';

	/**
	 * Entity type for a product attribute.
	 *
	 * @var string
	 */
	const ENTITY_ATTRIBUTE = 'attribute';

	/**
	 * The §8 product types this provider understands.
	 *
	 * @var array<int, string>
	 */
	const PRODUCT_TYPES = array( 'simple', 'variable', 'grouped', 'external', 'virtual', 'downloadable' );

	/**
	 * Injectable WooCommerce API.
	 *
	 * Keys: `active`, `products`, `product`, `terms`, `version`. Each is a callable
	 * receiving exactly what the corresponding WooCommerce function receives. Absent keys
	 * mean "not available", never "assume present".
	 *
	 * @var array<string, callable>
	 */
	private $api = array();

	/**
	 * The user whose store is being read.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Constructor.
	 *
	 * @param int   $user_id Reading user.
	 * @param array $api     Optional injectable API, for testing.
	 */
	public function __construct( $user_id = 0, array $api = array() ) {
		$this->user_id = (int) $user_id;
		$this->set_api( $api );
	}

	/**
	 * Inject the WooCommerce API.
	 *
	 * @param array<string, callable> $api Callables.
	 * @return void
	 */
	public function set_api( array $api ) {
		$this->api = array();
		foreach ( array( 'active', 'products', 'product', 'terms', 'version' ) as $key ) {
			if ( isset( $api[ $key ] ) && is_callable( $api[ $key ] ) ) {
				$this->api[ $key ] = $api[ $key ];
			}
		}
	}

	/**
	 * Return the provider identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'woocommerce';
	}

	/**
	 * Return whether WooCommerce is present and this user may read products.
	 *
	 * @return bool
	 */
	public function is_available() {
		$active = $this->api['active'] ?? null;
		$is_active = is_callable( $active ) ? (bool) call_user_func( $active ) : class_exists( 'WooCommerce' );

		if ( ! $is_active ) {
			return false;
		}

		// Reading products needs a permission. Without it the provider is unavailable
		// rather than quietly returning an empty store, which would read as "you have no
		// products" when the truth is "you may not see them".
		//
		// The check is only applied when this site actually *has* the capability. The
		// first draft applied it unconditionally, so on a site without WooCommerce - where
		// `edit_products` and `read_product` do not exist for anybody - every user was
		// reported as "not allowed to read products". That is a false accusation, and it
		// is worse than useless: it made an injected or future provider look broken, and
		// it would have made a WooCommerce install with renamed capabilities report
		// "not active" when the real answer was "not permitted".
		if ( $this->user_id > 0 && ! $this->user_may_read() ) {
			return false;
		}

		return true;
	}

	/**
	 * Return whether this user may read products, or whether the question does not apply.
	 *
	 * Three outcomes, not two, and collapsing them is the bug this exists to avoid:
	 *
	 * - the site has no product capabilities at all, so the question does not apply;
	 * - the user holds one, so they may read;
	 * - the capabilities exist and the user holds none, so they may not.
	 *
	 * @return bool
	 */
	private function user_may_read() {
		$roles = isset( $GLOBALS['wp_roles'] ) && is_object( $GLOBALS['wp_roles'] ) ? $GLOBALS['wp_roles'] : null;
		// `\WP_Roles`, with the explicit global prefix. This class lives in the
		// ReplicaForge namespace, and an unqualified `WP_Roles` resolves against
		// ReplicaForge\WP_Roles first; the global fallback is not something to lean on for
		// a decision that decides whether a store may be read, so the name is spelled out.
		if ( ! $roles instanceof \WP_Roles ) {
			// No role model to ask. Permission cannot be established, so the safe answer
			// is "no" - and the caller, which is behind an authenticated REST route,
			// will have applied its own capability check before reaching here.
			return false;
		}

		$owned = false;
		foreach ( array( 'edit_products', 'read_product' ) as $capability ) {
			foreach ( array_keys( (array) $roles->roles ) as $role ) {
				$role_object = $roles->get_role( (string) $role );
				if ( $role_object && $role_object->has_cap( $capability ) ) {
					$owned = true;
					break 2;
				}
			}
		}

		// No role on this site has the capability, so WooCommerce's capability set is
		// not in play and the question does not apply.
		if ( ! $owned ) {
			return true;
		}

		return current_user_can( 'edit_products' ) || current_user_can( 'read_product' );
	}

	/**
	 * Return why the provider is unavailable.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		$active = $this->api['active'] ?? null;
		$is_active = is_callable( $active ) ? (bool) call_user_func( $active ) : class_exists( 'WooCommerce' );

		if ( ! $is_active ) {
			return __( 'WooCommerce is not active on this site, so there is no store to map content into. ReplicaForge can still map content into WordPress posts and pages.', 'replicaforge' );
		}
		if ( $this->user_id > 0 && ! $this->user_may_read() ) {
			return __( 'Your account is not allowed to read products in this store, so ReplicaForge did not read any.', 'replicaforge' );
		}
		return '';
	}

	/**
	 * Return a version for the content cache key.
	 *
	 * @return string
	 */
	public function version() {
		$version = $this->api['version'] ?? null;
		if ( is_callable( $version ) ) {
			$reported = (string) call_user_func( $version );
			if ( '' !== $reported ) {
				return 'wc-' . $reported;
			}
		}
		// Never an empty version. An empty one would make two different WooCommerce
		// builds share a content cache, and a field that one build offers and another
		// does not would be served from the wrong one.
		return 'wc-unknown';
	}

	/**
	 * Return the entity types this provider supports.
	 *
	 * @return array<int, string>
	 */
	public function entity_types() {
		return $this->is_available()
			? array( self::ENTITY_PRODUCT, self::ENTITY_CATEGORY, self::ENTITY_TAG, self::ENTITY_ATTRIBUTE )
			: array();
	}

	/**
	 * Return the fields a WooCommerce entity type can supply.
	 *
	 * `writable` is the important column. §8 forbids creating products, changing prices,
	 * and touching inventory in this phase, and this provider marks those fields
	 * `editable => false` so {@see Content_Validator} refuses a mapping onto them
	 * structurally — not because a caller remembered to check.
	 *
	 * @param string $entity_type Entity type.
	 * @return array<int, array<string, mixed>>
	 */
	public function fields_for( $entity_type ) {
		if ( ! $this->is_available() ) {
			return array();
		}

		if ( self::ENTITY_PRODUCT === $entity_type ) {
			return array(
				$this->field( 'post_title', __( 'Product title', 'replicaforge' ), 'text', true, 0.98 ),
				$this->field( 'post_content', __( 'Product description', 'replicaforge' ), 'richtext', true, 0.95 ),
				$this->field( 'post_excerpt', __( 'Short description', 'replicaforge' ), 'richtext', true, 0.98 ),
				$this->field( 'sku', __( 'SKU', 'replicaforge' ), 'text', true, 0.98 ),
				$this->field( '_regular_price', __( 'Regular price', 'replicaforge' ), 'currency', true, 0.98 ),
				$this->field( '_sale_price', __( 'Sale price', 'replicaforge' ), 'currency', true, 0.95 ),
				$this->field( '_featured_image_id', __( 'Product image', 'replicaforge' ), 'image', true, 0.95 ),
				// Read-only, and refused for writing: §8 forbids touching inventory, and
				// stock is the one field where a wrong value is a real-world cost.
				$this->field( '_stock', __( 'Stock quantity', 'replicaforge' ), 'number', false, 0.4, __( 'ReplicaForge never writes stock. It is read-only so a mapping cannot target it.', 'replicaforge' ) ),
				$this->field( '_average_rating', __( 'Average rating', 'replicaforge' ), 'rating', true, 0.9 ),
			);
		}

		if ( in_array( $entity_type, array( self::ENTITY_CATEGORY, self::ENTITY_TAG ), true ) ) {
			return array(
				$this->field( 'name', __( 'Name', 'replicaforge' ), 'text', true, 0.95 ),
				$this->field( 'slug', __( 'Slug', 'replicaforge' ), 'text', true, 0.9 ),
			);
		}

		return array(
			$this->field( 'name', __( 'Attribute name', 'replicaforge' ), 'text', true, 0.85 ),
		);
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
		$per_page    = ( $per_page > 0 ) ? min( (int) $per_page, Content_Limits::MAX_DESTINATION_BATCH ) : 20;
		$page        = max( 1, (int) $page );

		$out = array(
			'provider'    => $this->id(),
			'entity_type' => $entity_type,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => 0,
			'total_pages' => 0,
			'items'       => array(),
			'available'   => $this->is_available(),
		);

		if ( ! $out['available'] ) {
			$out['reason']  = $this->unavailable_reason();
			$out['code']    = 'woocommerce_not_active';
			// `empty` is false even though `items` is empty, so a caller cannot read an
			// unavailable provider as "you have no products".
			$out['empty']   = false;
			return $out;
		}

		if ( in_array( $entity_type, array( self::ENTITY_CATEGORY, self::ENTITY_TAG, self::ENTITY_ATTRIBUTE ), true ) ) {
			return $this->terms( $entity_type, $page, $per_page, $out );
		}

		if ( self::ENTITY_PRODUCT !== $entity_type ) {
			$out['reason'] = __( 'That store content type is not available.', 'replicaforge' );
			return $out;
		}

		$products = $this->api['products'] ?? null;
		if ( ! is_callable( $products ) ) {
			$out['reason'] = __( 'The WooCommerce product API could not be reached.', 'replicaforge' );
			return $out;
		}

		$reported = call_user_func( $products, $page, $per_page, $args );
		$out['total']       = isset( $reported['total'] ) ? (int) $reported['total'] : 0;
		$out['total_pages'] = ( $per_page > 0 ) ? (int) ceil( $out['total'] / $per_page ) : 0;

		foreach ( (array) ( $reported['items'] ?? array() ) as $item ) {
			$out['items'][] = $this->summary( (array) $item );
		}

		return $out;
	}

	/**
	 * Return one product's field values.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Product id.
	 * @return array<string, mixed>|null
	 */
	public function entity( $entity_type, $entity_id ) {
		if ( self::ENTITY_PRODUCT !== (string) $entity_type || ! $this->is_available() ) {
			return null;
		}
		$entity_id = (int) $entity_id;
		if ( $entity_id < 1 ) {
			return null;
		}

		$products = $this->api['product'] ?? null;
		if ( ! is_callable( $products ) ) {
			return null;
		}

		$raw = call_user_func( $products, $entity_id );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return null;
		}

		$summary = $this->summary( $raw );
		$summary['values'] = $this->values( $raw );

		return $summary;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Build a field descriptor.
	 *
	 * @param string      $field     Field name.
	 * @param string      $label     Label.
	 * @param string      $data_type Data type.
	 * @param bool        $editable  Whether the destination can write it.
	 * @param float       $confidence Confidence.
	 * @param string      $note      Note.
	 * @return array<string, mixed>
	 */
	private function field( $field, $label, $data_type, $editable, $confidence = 0.9, $note = '' ) {
		return array(
			'provider'      => $this->id(),
			'entity_type'   => ( 'regular_price' === $field || 'sale_price' === $field ) ? self::ENTITY_PRODUCT : '',
			'field'         => (string) $field,
			'label'         => (string) $label,
			'data_type'     => (string) $data_type,
			'availability'  => $editable ? 'available' : 'read_only',
			'editable'      => (bool) $editable,
			'confidence'    => (float) $confidence,
			'requires_review' => false,
			'note'          => (string) $note,
		);
	}

	/**
	 * Return a product summary with the matching keys §20 needs.
	 *
	 * @param array<string, mixed> $raw Raw product.
	 * @return array<string, mixed>
	 */
	private function summary( array $raw ) {
		$id    = (int) ( $raw['id'] ?? 0 );
		$title = (string) ( $raw['name'] ?? ( $raw['title'] ?? '' ) );
		$sku   = (string) ( $raw['sku'] ?? '' );
		$image = (string) ( $raw['image'] ?? '' );

		return array(
			'entity_id'    => $id,
			'entity_type'  => self::ENTITY_PRODUCT,
			'title'        => $this->bounded( $title, 200 ),
			'sku'          => $this->bounded( $sku, 100 ),
			'type'         => (string) ( $raw['type'] ?? 'simple' ),
			'status'       => (string) ( $raw['status'] ?? 'publish' ),
			'url'          => (string) ( $raw['permalink'] ?? ( $raw['url'] ?? '' ) ),
			'date'         => (string) ( $raw['date'] ?? '' ),
			'categories'   => array_values( array_map( 'strval', (array) ( $raw['categories'] ?? array() ) ) ),
			'fingerprint'  => Content_Fingerprint::of( 'product_name', $title ),
			'title_key'    => Content_Fingerprint::normalise_title( $title ),
			'sku_key'      => Content_Fingerprint::normalise_sku( $sku ),
			'slug_key'      => Content_Fingerprint::normalise_slug( (string) ( $raw['slug'] ?? '' ) ),
			'image_key'     => Content_Fingerprint::image_key( $image ),
			'category_key' => Content_Fingerprint::normalise_category( implode( '/', (array) ( $raw['categories'] ?? array() ) ) ),
			'brand_key'     => Content_Fingerprint::normalise_title( (string) ( $raw['brand'] ?? '' ) ),
		);
	}

	/**
	 * Return a product's field values.
	 *
	 * @param array<string, mixed> $raw Raw product.
	 * @return array<string, mixed>
	 */
	private function values( array $raw ) {
		$regular = $this->price( (string) ( $raw['regular_price'] ?? '' ) );
		$sale    = $this->price( (string) ( $raw['sale_price'] ?? '' ) );

		return array(
			'post_title'          => (string) ( $raw['name'] ?? ( $raw['title'] ?? '' ) ),
			'post_content'        => (string) ( $raw['description'] ?? '' ),
			'post_excerpt'        => (string) ( $raw['short_description'] ?? '' ),
			'sku'                 => (string) ( $raw['sku'] ?? '' ),
			'_regular_price'      => ( null === $regular ) ? 'not_detected' : (string) $regular,
			'_sale_price'         => ( null === $sale ) ? 'not_detected' : (string) $sale,
			'_featured_image_id'  => (string) ( $raw['image'] ?? '' ),
			'_average_rating'     => isset( $raw['rating'] ) && is_numeric( $raw['rating'] ) ? (string) $raw['rating'] : 'not_detected',
			'categories'          => (array) ( $raw['categories'] ?? array() ),
		);
	}

	/**
	 * Return a page of terms.
	 *
	 * @param string               $entity_type Entity type.
	 * @param int                  $page        Page.
	 * @param int                  $per_page    Per page.
	 * @param array<string, mixed> $out         Result skeleton.
	 * @return array<string, mixed>
	 */
	private function terms( $entity_type, $page, $per_page, array $out ) {
		$terms = $this->api['terms'] ?? null;
		if ( ! is_callable( $terms ) ) {
			$out['reason'] = __( 'The WooCommerce term API could not be reached.', 'replicaforge' );
			return $out;
		}

		$reported = call_user_func( $terms, $entity_type, $page, $per_page );
		$out['total']       = isset( $reported['total'] ) ? (int) $reported['total'] : 0;
		$out['total_pages'] = ( $per_page > 0 ) ? (int) ceil( $out['total'] / $per_page ) : 0;

		foreach ( (array) ( $reported['items'] ?? array() ) as $item ) {
			$item = (array) $item;
			$name = (string) ( $item['name'] ?? '' );
			$out['items'][] = array(
				'entity_id'   => (int) ( $item['id'] ?? 0 ),
				'entity_type' => $entity_type,
				'title'       => $this->bounded( $name, 200 ),
				'slug'        => (string) ( $item['slug'] ?? '' ),
				'count'       => (int) ( $item['count'] ?? 0 ),
				'fingerprint' => Content_Fingerprint::of( 'term_name', $name ),
				'title_key'   => Content_Fingerprint::normalise_title( $name ),
				'slug_key'    => Content_Fingerprint::normalise_slug( (string) ( $item['slug'] ?? '' ) ),
			);
		}

		return $out;
	}

	/**
	 * Parse a WooCommerce price string into a number, or null.
	 *
	 * WooCommerce stores prices as *strings* and formats them for a locale, so
	 * `"1,234.56"`, `"1.234,56"`, and `"€ 1.234,56"` are all the same price written three
	 * ways. Reusing {@see Structured_Data::currency_value()}'s rules would be ideal, but
	 * that method is private and this is a different trust boundary — a *destination*
	 * value the user typed, not a source page's claim — so the parse is separate and
	 * says so.
	 *
	 * A string with no digits is not a price. It is returned as `null`, which the caller
	 * records as `not_detected` rather than as zero, because a fabricated $0.00 on a
	 * product is a worse failure than a missing price.
	 *
	 * @param string $raw Raw price.
	 * @return float|null
	 */
	private function price( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( 1 !== preg_match( '/[0-9]/', $raw ) ) {
			return null;
		}

		$clean = (string) preg_replace( '/[^0-9.,]/', '', $raw );
		if ( '' === $clean ) {
			return null;
		}

		$last_comma = strrpos( $clean, ',' );
		$last_dot   = strrpos( $clean, '.' );

		if ( false !== $last_comma && false !== $last_dot ) {
			// Whichever separator comes last is the decimal one.
			$clean = ( $last_comma > $last_dot )
				? str_replace( '.', '', str_replace( ',', '.', $clean ) )
				: str_replace( ',', '', $clean );
		} elseif ( false !== $last_comma ) {
			// A lone comma is a decimal separator only when exactly two digits follow it.
			$clean = ( 1 === preg_match( '/,\d{2}$/', $clean ) ) ? str_replace( ',', '.', $clean ) : str_replace( ',', '', $clean );
		}

		if ( ! is_numeric( $clean ) ) {
			return null;
		}

		return round( (float) $clean, 2 );
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
