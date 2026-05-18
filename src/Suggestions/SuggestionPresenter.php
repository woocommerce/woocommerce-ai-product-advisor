<?php

declare( strict_types=1 );

namespace WCAI_PA\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Enriches a raw suggestion payload from the WPCOM API with local product data for frontend display.
 */
class SuggestionPresenter {

	/**
	 * Raw suggestion payload.
	 *
	 * @var array
	 */
	private array $suggestion;

	/**
	 * Constructor.
	 *
	 * @param array $suggestion Raw suggestion payload.
	 */
	public function __construct( array $suggestion ) {
		$this->suggestion = $suggestion;
	}

	/**
	 * Returns the enriched suggestion array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return $this->suggestion;
	}

	/**
	 * Adds `field_label` to each changed field using WooCommerce attribute labels where applicable.
	 *
	 * @return $this
	 */
	public function field_labels(): self {
		if ( empty( $this->suggestion['changed_fields'] ) || ! is_array( $this->suggestion['changed_fields'] ) ) {
			return $this;
		}

		$product_id = ! empty( $this->suggestion['product_id'] ) ? (int) $this->suggestion['product_id'] : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		foreach ( $this->suggestion['changed_fields'] as $index => $field_change ) {
			if ( ! is_array( $field_change ) || empty( $field_change['field'] ) || ! is_string( $field_change['field'] ) ) {
				continue;
			}

			$this->suggestion['changed_fields'][ $index ]['field_label'] = self::resolve_field_label( $field_change['field'], $product );
		}

		return $this;
	}

	/**
	 * Adds `field_type` to each changed field.
	 *
	 * @return $this
	 */
	public function field_types(): self {
		if ( empty( $this->suggestion['changed_fields'] ) || ! is_array( $this->suggestion['changed_fields'] ) ) {
			return $this;
		}

		foreach ( $this->suggestion['changed_fields'] as $index => $field_change ) {
			if ( ! is_array( $field_change ) || empty( $field_change['field'] ) || ! is_string( $field_change['field'] ) ) {
				continue;
			}

			$this->suggestion['changed_fields'][ $index ]['field_type'] = self::resolve_field_type( $field_change['field'] );
		}

		return $this;
	}

	/**
	 * Adds `sku` and `edit_url` from the local product.
	 *
	 * @return $this
	 */
	public function product_meta(): self {
		$product_id = ! empty( $this->suggestion['product_id'] ) ? (int) $this->suggestion['product_id'] : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( $product ) {
			$sku = $product->get_sku();
			if ( '' !== $sku ) {
				$this->suggestion['sku'] = $sku;
			}
		}

		$edit_url = $product_id ? get_edit_post_link( $product_id, 'raw' ) : '';
		if ( $edit_url ) {
			$this->suggestion['edit_url'] = $edit_url;
		}

		return $this;
	}

	/**
	 * Replaces `old_value` in each changed field with the actual current product value.
	 *
	 * @return $this
	 */
	public function current_values(): self {
		if ( empty( $this->suggestion['changed_fields'] ) || ! is_array( $this->suggestion['changed_fields'] ) ) {
			return $this;
		}

		$product_id = ! empty( $this->suggestion['product_id'] ) ? (int) $this->suggestion['product_id'] : 0;
		if ( ! $product_id ) {
			return $this;
		}

		foreach ( $this->suggestion['changed_fields'] as &$field ) {
			$current = $this->read_product_field_value( $product_id, $field['field'] );
			if ( null !== $current ) {
				$field['old_value'] = $current;
			}
		}

		return $this;
	}

	// ------------------------------------------------------------------
	// Field label helpers
	// ------------------------------------------------------------------

	/**
	 * Resolves a display label for a field key.
	 *
	 * @param string                 $field   Field key.
	 * @param \WC_Product|false|null $product Product context.
	 * @return string
	 */
	private static function resolve_field_label( string $field, $product ): string {
		$variation = self::parse_variation_field( $field );
		if ( null !== $variation ) {
			return self::get_variation_field_label( $variation['variation_id'], $variation['sub_field'] );
		}

		$core_label = self::get_product_core_field_label( $field );
		if ( null !== $core_label ) {
			return $core_label;
		}

		$label = wc_attribute_label( $field, $product );

		if ( $label !== $field ) {
			return $label;
		}

		return self::humanize_field_key( $field );
	}

	/**
	 * Returns a label for core product fields, stripping leading underscores.
	 *
	 * @param string $field Field or meta key (may include a leading underscore).
	 * @return string|null
	 */
	private static function get_product_core_field_label( string $field ): ?string {
		$candidates = array( $field );
		if ( '_' === $field[0] ) {
			$stripped = substr( $field, 1 );
			if ( '' !== $stripped ) {
				$candidates[] = $stripped;
			}
		}

		foreach ( $candidates as $key ) {
			$mapped = self::map_product_core_field_key( $key );
			if ( null !== $mapped ) {
				return $mapped;
			}
		}

		return null;
	}

	/**
	 * Maps a normalized key to a WooCommerce admin label.
	 *
	 * @param string $key Normalized field key.
	 * @return string|null
	 */
	private static function map_product_core_field_key( string $key ): ?string {
		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- Must match WooCommerce / core admin copy.
		switch ( $key ) {
			case 'post_title':
			case 'title':
			case 'name':
				return __( 'Product name', 'woocommerce' );

			case 'post_content':
			case 'description':
				return __( 'Product description', 'woocommerce' );

			case 'post_excerpt':
			case 'short_description':
				return __( 'Product short description', 'woocommerce' );

			case 'post_name':
			case 'slug':
				return __( 'Slug', 'default' );

			case 'post_status':
			case 'status':
				return __( 'Status', 'default' );

			case 'sku':
				return __( 'SKU', 'woocommerce' );
			case 'regular_price':
				return __( 'Regular price', 'woocommerce' );
			case 'sale_price':
				return __( 'Sale price', 'woocommerce' );

			case 'purchase_note':
				return __( 'Purchase note', 'woocommerce' );
			case 'menu_order':
				return __( 'Menu order', 'woocommerce' );

			case 'taxonomy_product_cat':
				return __( 'Product categories', 'woocommerce' );
			case 'taxonomy_product_tag':
				return __( 'Product tags', 'woocommerce' );

			default:
				return null;
		}
		// phpcs:enable WordPress.WP.I18n.TextDomainMismatch
	}

	/**
	 * Converts a field key to a human-readable label.
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	private static function humanize_field_key( string $field ): string {
		$words = preg_split( '/[_\- ]+/', $field, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $words ) ) {
			return $field;
		}

		$parts = array_map(
			static function ( string $word ): string {
				return ucfirst( strtolower( $word ) );
			},
			$words
		);

		return implode( ' ', $parts );
	}

	/**
	 * Builds a display label for a variation field.
	 *
	 * @param int    $variation_id Variation post ID.
	 * @param string $sub_field    Field within the variation.
	 * @return string
	 */
	private static function get_variation_field_label( int $variation_id, string $sub_field ): string {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation instanceof \WC_Product_Variation ) {
			return sprintf( 'Variation #%d: %s', $variation_id, $sub_field );
		}

		$attributes = $variation->get_attributes();
		$excerpt    = '';
		if ( ! empty( $attributes ) ) {
			$parts = array();
			foreach ( $attributes as $taxonomy => $value ) {
				if ( '' === $value ) {
					continue;
				}
				$label   = wc_attribute_label( $taxonomy, $variation );
				$parts[] = $label . ': ' . $value;
			}
			$excerpt = implode( ' / ', $parts );
		}
		if ( empty( $excerpt ) ) {
			$excerpt = '#' . $variation_id;
		}

		return sprintf(
			/* translators: 1: variation attribute summary, 2: sub-field name */
			__( 'Variation "%1$s" - %2$s', 'wcai-pa' ),
			$excerpt,
			$sub_field
		);
	}

	// ------------------------------------------------------------------
	// Field type helpers
	// ------------------------------------------------------------------

	/**
	 * Resolves the editor type for a field.
	 *
	 * @param string $field Field key.
	 * @return string One of `text`, `rich`, `number`, `taxonomy`.
	 */
	private static function resolve_field_type( string $field ): string {
		$variation = self::parse_variation_field( $field );
		$key       = null !== $variation ? $variation['sub_field'] : $field;

		if ( '' !== $key && '_' === $key[0] ) {
			$key = substr( $key, 1 );
		}

		switch ( $key ) {
			case 'post_content':
			case 'description':
			case 'post_excerpt':
			case 'short_description':
				return 'rich';

			case 'regular_price':
			case 'sale_price':
			case 'price':
			case 'menu_order':
			case 'stock_quantity':
			case 'weight':
			case 'length':
			case 'width':
			case 'height':
				return 'number';

			case 'taxonomy_product_cat':
			case 'taxonomy_product_tag':
				return 'taxonomy';

			default:
				return 'text';
		}
	}

	// ------------------------------------------------------------------
	// Current-value helpers
	// ------------------------------------------------------------------

	/**
	 * Reads the current value of a product field.
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $field      Field key.
	 * @return string|null
	 */
	private function read_product_field_value( int $product_id, string $field ): ?string {
		$variation = self::parse_variation_field( $field );
		if ( null !== $variation ) {
			return $this->read_variation_field_value( $variation['variation_id'], $variation['sub_field'] );
		}

		switch ( $field ) {
			case 'title':
			case 'post_title':
			case 'name':
				return get_post_field( 'post_title', $product_id );

			case 'description':
			case 'post_content':
				return get_post_field( 'post_content', $product_id );

			case 'short_description':
			case 'post_excerpt':
				return get_post_field( 'post_excerpt', $product_id );

			case 'taxonomy_product_cat':
			case 'taxonomy_product_tag':
				return $this->read_taxonomy_field_value( $product_id, $field );

			default:
				$value = get_post_meta( $product_id, $field, true );
				return is_string( $value ) ? $value : null;
		}
	}

	/**
	 * Reads a field value from a product variation.
	 *
	 * @param int    $variation_id Variation post ID.
	 * @param string $sub_field    Field name.
	 * @return string|null
	 */
	private function read_variation_field_value( int $variation_id, string $sub_field ): ?string {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation instanceof \WC_Product_Variation ) {
			return null;
		}

		switch ( $sub_field ) {
			case 'description':
				return $variation->get_description();
			default:
				return null;
		}
	}

	/**
	 * Reads taxonomy terms for a product as enriched JSON.
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $field      Taxonomy field key.
	 * @return string|null
	 */
	private function read_taxonomy_field_value( int $product_id, string $field ): ?string {
		$taxonomy = 'taxonomy_product_cat' === $field ? 'product_cat' : 'product_tag';
		$terms    = wp_get_post_terms( $product_id, $taxonomy );

		if ( is_wp_error( $terms ) ) {
			return null;
		}

		$enriched = array();
		foreach ( $terms as $term ) {
			$hierarchy  = self::get_term_hierarchy_path( $term->term_id, $taxonomy );
			$enriched[] = array(
				'id'   => $term->term_id,
				'name' => $hierarchy ? $hierarchy : $term->name,
			);
		}

		return wp_json_encode( $enriched );
	}

	// ------------------------------------------------------------------
	// Shared utilities
	// ------------------------------------------------------------------

	/**
	 * Parses a variation field key like "variation:98:description".
	 *
	 * @param string $field Field key.
	 * @return array{variation_id: int, sub_field: string}|null
	 */
	public static function parse_variation_field( string $field ): ?array {
		if ( 1 !== preg_match( '/^variation:(\d+):(.+)$/', $field, $matches ) ) {
			return null;
		}

		return array(
			'variation_id' => (int) $matches[1],
			'sub_field'    => $matches[2],
		);
	}

	/**
	 * Builds a full hierarchy path for a taxonomy term (e.g. "Kids > Toys > Building blocks").
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return string|null
	 */
	public static function get_term_hierarchy_path( int $term_id, string $taxonomy ): ?string {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		$ancestors  = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
		$path_parts = array();

		foreach ( array_reverse( $ancestors ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $taxonomy );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				$path_parts[] = html_entity_decode( $ancestor->name, ENT_QUOTES, 'UTF-8' );
			}
		}

		$path_parts[] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );

		return implode( ' > ', $path_parts );
	}
}
