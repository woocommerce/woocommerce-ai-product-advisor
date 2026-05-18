<?php
/**
 * Local overview math: applied-product verdicts and store-level summary.
 *
 * Everything in this class runs against the local Woo database — no WPCOM
 * round trips and no Jetpack sync dependency, so the verdict stays accurate
 * even when WPCOM hasn't seen recent orders yet. Mirrors the algorithm the
 * WPCOM-side `Overview_Builder` previously used so users see consistent
 * numbers if they happen to have both surfaces side-by-side during rollout.
 *
 * @package WCAI_PA
 */

declare( strict_types=1 );

namespace WCAI_PA\Overview;

use WCAI_PA\Suggestions\Suggestions;

defined( 'ABSPATH' ) || exit;

/**
 * Computes lift/dip verdicts and the per-store summary from local order data.
 */
class OverviewCalculator {
	/**
	 * Minimum days of post-change data before a verdict is computed; below this
	 * we always return `not_enough_data` so the UI can prompt "check back soon".
	 */
	const MIN_DAYS_POST = 7;

	/**
	 * Length (days) of the pre-change baseline window used to derive the
	 * expected post-change order rate per product.
	 */
	const BASELINE_WINDOW_DAYS = 30;

	/**
	 * Poisson-based z-score threshold for declaring a real lift/dip vs. noise.
	 * Daily order counts are treated as Poisson, so stddev ≈ √expected.
	 */
	const Z_THRESHOLD = 1.5;

	/**
	 * WooCommerce statuses that count as a "sale" for verdict purposes.
	 *
	 * Mirrors the set Woo Analytics uses (excludes pending/failed/cancelled).
	 */
	const SALE_STATUSES = array( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' );

	/**
	 * Computes the applied-products list and the store-level summary.
	 *
	 * @return array{applied_products: array<int, array<string, mixed>>, summary: array<string, mixed>}
	 */
	public function compute(): array {
		$applied = $this->applied_products();

		return array(
			'applied_products' => $applied,
			'summary'          => $this->summary( $applied ),
		);
	}

	/**
	 * Builds the per-product verdict list, sorted by impact magnitude.
	 *
	 * Reads the canonical "approved" mirror metadata that
	 * `Suggestions::approve_suggestion` stamps on each product, so this is the
	 * direct local analogue of the WPCOM `wc_product_update_suggestions` query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function applied_products(): array {
		$products = get_posts(
			array(
				'post_type'              => array( 'product', 'product_variation' ),
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'all',
				'no_found_rows'          => true,
				'cache_results'          => false,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Suggestions::APPROVED_PRODUCT_META_KEY,
						'value'   => '1',
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $products ) ) {
			return array();
		}

		$out = array();
		foreach ( $products as $post ) {
			$applied_at = (string) get_post_meta( $post->ID, Suggestions::LAST_APPROVED_AT_META_KEY, true );
			if ( '' === $applied_at ) {
				continue;
			}

			$verdict = $this->compute_verdict( (int) $post->ID, $applied_at );

			$out[] = array_merge(
				array(
					'product_id'      => (int) $post->ID,
					'product_name'    => (string) $post->post_title,
					'last_applied_at' => $applied_at,
					'edit_url'        => admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' ),
				),
				$verdict
			);
		}

		usort( $out, array( self::class, 'compare_applied_by_impact' ) );

		return $out;
	}

	/**
	 * Sort compare for applied rows: `not_enough_data` last, then by |magnitude| desc, then `last_applied_at` desc.
	 *
	 * @param array<string, mixed> $a Applied product row.
	 * @param array<string, mixed> $b Applied product row.
	 * @return int
	 */
	public static function compare_applied_by_impact( array $a, array $b ): int {
		$a_ned = 'not_enough_data' === $a['verdict'];
		$b_ned = 'not_enough_data' === $b['verdict'];
		if ( $a_ned !== $b_ned ) {
			return $a_ned ? 1 : -1;
		}
		$a_mag = abs( (float) ( $a['magnitude_percent'] ?? 0 ) );
		$b_mag = abs( (float) ( $b['magnitude_percent'] ?? 0 ) );
		if ( $a_mag !== $b_mag ) {
			return $b_mag <=> $a_mag;
		}
		return strcmp( (string) $b['last_applied_at'], (string) $a['last_applied_at'] );
	}

	/**
	 * Classifies one product's post-change outcome against its own pre-change baseline.
	 *
	 * Treats daily order counts as Poisson: expected = baseline_rate × days_post,
	 * stddev ≈ √expected. |z| > Z_THRESHOLD flips the verdict to lifted/dipped.
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $applied_at UTC `Y-m-d H:i:s` timestamp of the most recent approval.
	 * @return array{verdict: string, magnitude_percent: float|null, actual_orders: int, expected_orders: float|null, days_since_change: int, days_of_baseline: int}
	 */
	private function compute_verdict( int $product_id, string $applied_at ): array {
		$applied_ts = (int) strtotime( $applied_at . ' UTC' );
		$now_ts     = time();

		$days_post = (int) max( 0, floor( ( $now_ts - $applied_ts ) / DAY_IN_SECONDS ) );
		$days_pre  = self::BASELINE_WINDOW_DAYS;

		$count_pre  = $this->count_orders( $product_id, $applied_ts - $days_pre * DAY_IN_SECONDS, $applied_ts );
		$count_post = $this->count_orders( $product_id, $applied_ts, null );

		$base = array(
			'actual_orders'     => $count_post,
			'days_since_change' => $days_post,
			'days_of_baseline'  => $days_pre,
		);

		if ( $days_post < self::MIN_DAYS_POST || 0 === $count_pre ) {
			return array_merge(
				$base,
				array(
					'verdict'           => 'not_enough_data',
					'magnitude_percent' => null,
					'expected_orders'   => null,
				)
			);
		}

		$rate     = $count_pre / max( 1, $days_pre );
		$expected = $rate * $days_post;
		$sigma    = sqrt( max( 1.0, $expected ) );
		$z        = ( $count_post - $expected ) / $sigma;

		if ( $z > self::Z_THRESHOLD ) {
			$verdict = 'lifted';
		} elseif ( $z < -self::Z_THRESHOLD ) {
			$verdict = 'dipped';
		} else {
			$verdict = 'no_change';
		}

		$magnitude = ( $count_post - $expected ) / max( 1.0, $expected ) * 100.0;

		return array_merge(
			$base,
			array(
				'verdict'           => $verdict,
				'magnitude_percent' => round( $magnitude, 1 ),
				'expected_orders'   => round( $expected, 2 ),
			)
		);
	}

	/**
	 * Distinct order count for a product within `[start, end)` (or `[start, now)` if `$end_ts` is null).
	 *
	 * Joins `wc_orders` ↔ `woocommerce_order_items` ↔ `woocommerce_order_itemmeta` directly because
	 * `wc_order_product_lookup` is a derived table that's only populated for orders saved through
	 * normal request flows; reading from the source-of-truth tables avoids a class of edge cases.
	 *
	 * @param int      $product_id Product ID to filter by.
	 * @param int      $start_ts   Inclusive start timestamp (UNIX seconds).
	 * @param int|null $end_ts     Exclusive end timestamp (UNIX seconds), or null for "now".
	 * @return int
	 */
	private function count_orders( int $product_id, int $start_ts, ?int $end_ts ): int {
		global $wpdb;

		$orders    = $wpdb->prefix . 'wc_orders';
		$items     = $wpdb->prefix . 'woocommerce_order_items';
		$item_meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$start     = gmdate( 'Y-m-d H:i:s', $start_ts );
		$status_in = implode( ', ', array_fill( 0, count( self::SALE_STATUSES ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null === $end_ts ) {
			$sql  = "SELECT COUNT(DISTINCT o.id)
					FROM {$orders} o
					INNER JOIN {$items} oi
						ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
					INNER JOIN {$item_meta} oim
						ON oim.order_item_id = oi.order_item_id
						AND oim.meta_key = '_product_id'
						AND oim.meta_value = %s
					WHERE o.status IN ( {$status_in} )
					AND o.date_created_gmt >= %s";
			$args = array_merge( array( (string) $product_id ), self::SALE_STATUSES, array( $start ) );
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		}

		$end  = gmdate( 'Y-m-d H:i:s', $end_ts );
		$sql  = "SELECT COUNT(DISTINCT o.id)
				FROM {$orders} o
				INNER JOIN {$items} oi
					ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				INNER JOIN {$item_meta} oim
					ON oim.order_item_id = oi.order_item_id
					AND oim.meta_key = '_product_id'
					AND oim.meta_value = %s
				WHERE o.status IN ( {$status_in} )
				AND o.date_created_gmt >= %s
				AND o.date_created_gmt < %s";
		$args = array_merge( array( (string) $product_id ), self::SALE_STATUSES, array( $start, $end ) );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Aggregates per-product verdicts into a store-level summary.
	 *
	 * @param array<int, array<string, mixed>> $applied Per-product verdict rows.
	 * @return array{total_changed: int, lifted_count: int, no_change_count: int, dipped_count: int, not_enough_data_count: int, average_lift_percent: float|null}
	 */
	private function summary( array $applied ): array {
		$counts = array(
			'lifted'          => 0,
			'no_change'       => 0,
			'dipped'          => 0,
			'not_enough_data' => 0,
		);

		$lifts = array();
		foreach ( $applied as $product ) {
			$verdict = (string) $product['verdict'];
			if ( isset( $counts[ $verdict ] ) ) {
				++$counts[ $verdict ];
			}
			if ( null !== $product['magnitude_percent'] ) {
				$lifts[] = (float) $product['magnitude_percent'];
			}
		}

		$average_lift = empty( $lifts )
			? null
			: round( array_sum( $lifts ) / count( $lifts ), 1 );

		return array(
			'total_changed'         => count( $applied ),
			'lifted_count'          => $counts['lifted'],
			'no_change_count'       => $counts['no_change'],
			'dipped_count'          => $counts['dipped'],
			'not_enough_data_count' => $counts['not_enough_data'],
			'average_lift_percent'  => $average_lift,
		);
	}
}
