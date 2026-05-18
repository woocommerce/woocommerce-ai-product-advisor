<?php

declare( strict_types=1 );

namespace WCAI_PA\Onboarding;

defined( 'ABSPATH' ) || exit;

/**
 * Collects a sample of site content for tone-feel analysis.
 */
class ContentCollector {

	/**
	 * Returns a structured content sample from this site.
	 *
	 * @return array
	 */
	public function collect(): array {
		return array(
			'site_title'   => get_bloginfo( 'name' ),
			'site_tagline' => get_bloginfo( 'description' ),
			'products'     => $this->get_products(),
			'pages'        => $this->get_wp_posts( 'page', 5 ),
			'posts'        => $this->get_wp_posts( 'post', 5 ),
		);
	}

	/**
	 * Returns up to 10 published products ordered by total sales.
	 *
	 * @return array
	 */
	private function get_products(): array {
		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => 10,
				'orderby' => 'total_sales',
				'order'   => 'DESC',
			)
		);

		return array_map( array( $this, 'map_product' ), $products );
	}

	/**
	 * Maps a WC_Product to a content-sample array.
	 *
	 * @param \WC_Product $product Product object.
	 * @return array
	 */
	private function map_product( \WC_Product $product ): array {
		$attrs = array();
		foreach ( $product->get_attributes() as $attribute ) {
			$attrs[ $attribute->get_name() ] = implode( ', ', $attribute->get_options() );
		}

		return array(
			'product_name'        => $product->get_name(),
			'short_description'   => $product->get_short_description(),
			'product_description' => $product->get_description(),
			'categories'          => $this->get_term_names( $product->get_category_ids(), 'product_cat' ),
			'tags'                => $this->get_term_names( $product->get_tag_ids(), 'product_tag' ),
			'purchase_note'       => $product->get_purchase_note(),
			'attributes'          => $attrs,
		);
	}

	/**
	 * Returns an array of published posts/pages with title and excerpt.
	 *
	 * @param string $post_type Post type slug.
	 * @param int    $limit     Maximum number of posts to return.
	 * @return array
	 */
	private function get_wp_posts( string $post_type, int $limit ): array {
		$posts = get_posts(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'numberposts' => $limit,
			)
		);

		return array_map(
			function ( \WP_Post $post ): array {
				$excerpt = has_excerpt( $post )
					? get_the_excerpt( $post )
					: wp_trim_words( $post->post_content, 55 );

				return array(
					'title'   => $post->post_title,
					'excerpt' => $excerpt,
				);
			},
			$posts
		);
	}

	/**
	 * Returns the term names for the given IDs and taxonomy.
	 *
	 * @param array  $term_ids Term IDs.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array
	 */
	private function get_term_names( array $term_ids, string $taxonomy ): array {
		if ( empty( $term_ids ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy' => $taxonomy,
				'include'  => $term_ids,
				'fields'   => 'names',
			)
		);

		return is_array( $terms ) ? $terms : array();
	}
}
