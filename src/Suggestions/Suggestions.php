<?php

declare( strict_types=1 );

namespace WCAI_PA\Suggestions;

use WCAI_PA\Suggestions\REST\SuggestionsController;
use WCAI_PA\Traits\WpcomApiClient;
use Automattic\Jetpack\Connection\Client;
use WCAI_PA\Connection\ConnectionSync;
use WCAI_PA\Connection\SyncHealth;
use WCAI_PA\Overview\Overview;

defined( 'ABSPATH' ) || exit;

/**
 * Encapsulates suggestion data operations.
 */
class Suggestions {
	use WpcomApiClient;

	/**
	 * Post meta flag set on products with at least one approved suggestion.
	 *
	 * Source of truth for "which products belong in the overview's applied list" —
	 * read locally so the overview computation stays offline-friendly.
	 */
	const APPROVED_PRODUCT_META_KEY = '_wcai_pa_has_approved_suggestion';

	/**
	 * Post meta storing the most recent suggestion-approval timestamp (`Y-m-d H:i:s` UTC).
	 *
	 * Used by the overview to anchor the pre/post lift windows per product without
	 * having to re-read suggestion records from WPCOM on every refresh.
	 */
	const LAST_APPROVED_AT_META_KEY = '_wcai_pa_last_approved_at';

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		( new SuggestionsController() )->register();
	}

	/**
	 * Returns the list of pending suggestion IDs and the current status.
	 *
	 * @return array|\WP_Error
	 */
	public function get_suggestions() {
		$sync_health = ( new SyncHealth() )->get_notice_config();

		if ( true === $sync_health['needsProductSync'] ) {
			return new \WP_Error( 'out_of_sync', $sync_health['message'] );
		}

		$response = Client::wpcom_json_api_request_as_user(
			$this->get_endpoint_url( '/wcai-pa/suggestions' ),
			'2',
			array( 'method' => 'GET' ),
			null,
			'wpcom'
		);

		return $this->unwrap_response( $response );
	}

	/**
	 * Returns the details of a single suggestion.
	 *
	 * @param string $suggestion_id Suggestion identifier.
	 * @return array|\WP_Error
	 */
	public function get_suggestion( string $suggestion_id ) {
		$response = Client::wpcom_json_api_request_as_user(
			$this->get_endpoint_url( '/wcai-pa/suggestions/' . $suggestion_id ),
			'2',
			array( 'method' => 'GET' ),
			null,
			'wpcom'
		);

		$result = $this->unwrap_response( $response );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->enrich_taxonomy_fields( $result );
	}

	/**
	 * Approves a suggestion, applying the selected field changes.
	 *
	 * @param string $suggestion_id Suggestion identifier.
	 * @param array  $approved_fields Field changes selected by user.
	 * @return array|\WP_Error
	 */
	public function approve_suggestion( string $suggestion_id, array $approved_fields ) {
		$suggestion = $this->get_suggestion( $suggestion_id );

		if ( is_wp_error( $suggestion ) ) {
			return $suggestion;
		}

		$product_post_id = ! empty( $suggestion['product_id'] )
			? (int) $suggestion['product_id']
			: 0;

		if ( ! get_post( $product_post_id ) ) {
			return new \WP_Error( 'product_not_found', __( 'Product not found.', 'wcai-pa' ), array( 'status' => 400 ) );
		}

		$enriched = ( new SuggestionPresenter( $suggestion ) )
			->current_values()
			->to_array();

		$current_by_field = array();
		foreach ( $enriched['changed_fields'] as $cf ) {
			$current_by_field[ $cf['field'] ] = $cf['old_value'];
		}

		foreach ( $approved_fields as &$entry ) {
			if ( isset( $entry['field'], $current_by_field[ $entry['field'] ] ) ) {
				$entry['current_value'] = $current_by_field[ $entry['field'] ];
			}
		}
		unset( $entry );

		$wpcom_fields = $this->strip_taxonomy_fields_for_wpcom( $approved_fields );

		$response = Client::wpcom_json_api_request_as_user(
			$this->get_endpoint_url( '/wcai-pa/suggestions/' . $suggestion_id ),
			'2',
			array( 'method' => 'POST' ),
			array(
				'status'          => 'approved',
				'approved_fields' => $wpcom_fields,
			),
			'wpcom'
		);

		$response = $this->unwrap_response( $response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$synced_ids = $this->update_saved_fields( $product_post_id, $approved_fields );

		// Mirror the approval locally so the overview can compute lift verdicts
		// without round-tripping to WPCOM on every refresh. Stamp the canonical
		// `approved_at` from the WPCOM response when available, otherwise "now".
		$approved_at_utc = isset( $response['approved_at'] ) && is_string( $response['approved_at'] )
			? gmdate( 'Y-m-d H:i:s', (int) strtotime( $response['approved_at'] ) )
			: gmdate( 'Y-m-d H:i:s' );
		update_post_meta( $product_post_id, self::APPROVED_PRODUCT_META_KEY, 1 );
		update_post_meta( $product_post_id, self::LAST_APPROVED_AT_META_KEY, $approved_at_utc );

		// Newly approved products should appear in the overview immediately on
		// next refresh — the WPCOM-side stats counters refresh on next compute.
		delete_transient( Overview::TRANSIENT_KEY );

		( new ConnectionSync() )->trigger_posts_sync(
			$synced_ids,
			'wcai_pa_approved_suggestion_sync'
		);

		return $response;
	}

	/**
	 * Returns reviewed (non-pending) suggestions with pagination.
	 *
	 * @param int $count  Number of suggestions to return.
	 * @param int $offset Offset for pagination.
	 * @return array|\WP_Error
	 */
	public function get_reviewed_suggestions( int $count = 20, int $offset = 0 ) {
		$response = Client::wpcom_json_api_request_as_user(
			$this->get_endpoint_url( '/wcai-pa/suggestions-reviewed' ) . '?count=' . $count . '&offset=' . $offset,
			'2',
			array( 'method' => 'GET' ),
			null,
			'wpcom'
		);

		$result = $this->unwrap_response( $response );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['suggestions'] ) && is_array( $result['suggestions'] ) ) {
			foreach ( $result['suggestions'] as $index => $suggestion ) {
				$result['suggestions'][ $index ] = $this->enrich_taxonomy_fields( $suggestion );
			}
		}

		return $result;
	}

	/**
	 * Reverts a previously approved suggestion, restoring original product values.
	 *
	 * @param string $suggestion_id Suggestion identifier.
	 * @return array|\WP_Error
	 */
	public function revert_suggestion( string $suggestion_id ) {
		$suggestion = $this->get_suggestion( $suggestion_id );

		if ( is_wp_error( $suggestion ) ) {
			return $suggestion;
		}

		$product_post_id = ! empty( $suggestion['product_id'] )
			? (int) $suggestion['product_id']
			: 0;

		if ( ! get_post( $product_post_id ) ) {
			return new \WP_Error( 'product_not_found', __( 'Product not found.', 'wcai-pa' ), array( 'status' => 400 ) );
		}

		// Only approved fields need local restoration; empty means WPCOM-only state flip.
		$revert_fields = array();
		if ( ! empty( $suggestion['changed_fields'] ) ) {
			foreach ( $suggestion['changed_fields'] as $field ) {
				if ( ! empty( $field['status'] ) && 'approved' === $field['status'] ) {
					$revert_fields[] = array(
						'field'     => $field['field'],
						'new_value' => $field['old_value'],
					);
				}
			}
		}

		$response = Client::wpcom_json_api_request_as_user(
			$this->get_endpoint_url( '/wcai-pa/suggestions/' . $suggestion_id ),
			'2',
			array( 'method' => 'POST' ),
			array(
				'status' => 'reverted',
			),
			'wpcom'
		);

		$response = $this->unwrap_response( $response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $revert_fields ) ) {
			$synced_ids = $this->update_saved_fields( $product_post_id, $revert_fields );

			( new ConnectionSync() )->trigger_posts_sync(
				$synced_ids,
				'wcai_pa_reverted_suggestion_sync'
			);
		}

		return $response;
	}

	/**
	 * Rejects a suggestion with a reason.
	 *
	 * @param string $suggestion_id Suggestion identifier.
	 * @return array|\WP_Error
	 */
	public function reject_suggestion( string $suggestion_id ) {
		$response = Client::wpcom_json_api_request_as_user(
			$this->get_endpoint_url( '/wcai-pa/suggestions/' . $suggestion_id ),
			'2',
			array( 'method' => 'POST' ),
			array(
				'status' => 'rejected',
			),
			'wpcom'
		);

		return $this->unwrap_response( $response );
	}

	/**
	 * Updates the saved fields for a product.
	 *
	 * @param int   $product_post_id Product post ID.
	 * @param array $approved_fields Approved fields.
	 * @return int[] Post IDs that were modified (product + any variations).
	 */
	private function update_saved_fields( int $product_post_id, array $approved_fields ): array {
		$synced_ids = array( $product_post_id );

		foreach ( $approved_fields as $approved_field ) {
			if (
				! is_array( $approved_field ) ||
				! isset( $approved_field['field'], $approved_field['new_value'] )
			) {
				continue;
			}

			$field     = $approved_field['field'];
			$new_value = $approved_field['new_value'];

			$variation = SuggestionPresenter::parse_variation_field( $field );
			if ( null !== $variation ) {
				$this->update_variation_field( $variation['variation_id'], $variation['sub_field'], $new_value );
				$synced_ids[] = $variation['variation_id'];
				continue;
			}

			switch ( $field ) {
				case 'title':
				case 'post_title':
					wp_update_post(
						array(
							'ID'         => $product_post_id,
							'post_title' => $new_value,
						)
					);
					break;
				case 'description':
				case 'post_content':
					wp_update_post(
						array(
							'ID'           => $product_post_id,
							'post_content' => $new_value,
						)
					);
					break;
				case 'short_description':
				case 'post_excerpt':
					wp_update_post(
						array(
							'ID'           => $product_post_id,
							'post_excerpt' => $new_value,
						)
					);
					break;
				case 'taxonomy_product_cat':
				case 'taxonomy_product_tag':
					$this->update_taxonomy_terms( $product_post_id, $field, $new_value );
					break;
				default:
					// Try updating as product meta.
					update_post_meta( $product_post_id, $field, $new_value );
					break;
			}
		}

		// Optionally, clear cached product data if necessary.
		wc_delete_product_transients( $product_post_id );

		return $synced_ids;
	}

	/**
	 * Updates a field on a product variation.
	 *
	 * @param int    $variation_id Variation post ID.
	 * @param string $sub_field    Field name within the variation.
	 * @param string $new_value    New value.
	 * @return void
	 */
	private function update_variation_field( int $variation_id, string $sub_field, string $new_value ): void {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation instanceof \WC_Product_Variation ) {
			return;
		}

		switch ( $sub_field ) {
			case 'description':
				$variation->set_description( $new_value );
				$variation->save();
				break;
		}
	}

	/**
	 * Strips enriched hierarchy names from taxonomy fields before sending to WPCOM.
	 * Only term IDs are relevant for the remote API.
	 *
	 * @param array $approved_fields Approved fields from the frontend.
	 * @return array Fields with taxonomy values reduced to ID-only arrays.
	 */
	private function strip_taxonomy_fields_for_wpcom( array $approved_fields ): array {
		$taxonomy_fields = array( 'taxonomy_product_cat', 'taxonomy_product_tag' );

		return array_map(
			function ( $field ) use ( $taxonomy_fields ) {
				if (
					! is_array( $field ) ||
					! isset( $field['field'] ) ||
					! in_array( $field['field'], $taxonomy_fields, true )
				) {
					return $field;
				}

				foreach ( array( 'new_value', 'current_value' ) as $key ) {
					if ( ! isset( $field[ $key ] ) ) {
						continue;
					}

					$terms = json_decode( $field[ $key ], true );
					if ( ! is_array( $terms ) ) {
						continue;
					}

					$ids_only = array_map(
						function ( $term ) {
							return array( 'id' => (int) $term['id'] );
						},
						array_filter(
							$terms,
							function ( $term ) {
								return isset( $term['id'] );
							}
						)
					);

					$field[ $key ] = wp_json_encode( array_values( $ids_only ) );
				}

				return $field;
			},
			$approved_fields
		);
	}

	/**
	 * Updates taxonomy terms for a product from a JSON value.
	 *
	 * @param int    $product_post_id Product post ID.
	 * @param string $field           Field name (taxonomy_product_cat or taxonomy_product_tag).
	 * @param string $new_value       JSON-encoded array of term objects with 'id' keys.
	 * @return void
	 */
	private function update_taxonomy_terms( int $product_post_id, string $field, string $new_value ): void {
		$taxonomy = 'taxonomy_product_cat' === $field ? 'product_cat' : 'product_tag';
		$terms    = json_decode( $new_value, true );

		if ( ! is_array( $terms ) ) {
			return;
		}

		$term_ids = array();
		foreach ( $terms as $term ) {
			if ( ! isset( $term['id'] ) ) {
				continue;
			}

			$existing = get_term( (int) $term['id'], $taxonomy );
			if ( $existing && ! is_wp_error( $existing ) ) {
				$term_ids[] = (int) $term['id'];
			}
		}

		wp_set_post_terms( $product_post_id, $term_ids, $taxonomy );
	}

	/**
	 * Enriches taxonomy fields in suggestion data with hierarchy paths.
	 *
	 * @param array $suggestion Suggestion data from WPCOM.
	 * @return array Enriched suggestion data.
	 */
	private function enrich_taxonomy_fields( array $suggestion ): array {
		if ( empty( $suggestion['changed_fields'] ) || ! is_array( $suggestion['changed_fields'] ) ) {
			return $suggestion;
		}

		foreach ( $suggestion['changed_fields'] as &$field ) {
			if ( ! in_array( $field['field'], array( 'taxonomy_product_cat', 'taxonomy_product_tag' ), true ) ) {
				continue;
			}

			$taxonomy = 'taxonomy_product_cat' === $field['field'] ? 'product_cat' : 'product_tag';

			foreach ( array( 'old_value', 'new_value', 'value_approved' ) as $value_key ) {
				if ( empty( $field[ $value_key ] ) ) {
					continue;
				}

				$terms = json_decode( $field[ $value_key ], true );
				if ( ! is_array( $terms ) ) {
					continue;
				}

				$enriched = array();
				foreach ( $terms as $term ) {
					if ( ! isset( $term['id'] ) ) {
						continue;
					}

					$hierarchy  = SuggestionPresenter::get_term_hierarchy_path( (int) $term['id'], $taxonomy );
					$enriched[] = array(
						'id'   => (int) $term['id'],
						'name' => $hierarchy ? $hierarchy : ( $term['name'] ?? '' ),
					);
				}

				$field[ $value_key ] = wp_json_encode( $enriched );
			}
		}

		return $suggestion;
	}
}
