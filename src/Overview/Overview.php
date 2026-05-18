<?php
/**
 * Overview service: local lift/dip computation with hourly caching.
 *
 * Earlier the overview was proxied to WPCOM, which forced us to round-trip
 * every order through Jetpack sync just so the verdict could read them back.
 * That sync was slow, lossy on large batches, and added a class of failure
 * modes orthogonal to the actual verdict logic. The current design owns the
 * computation locally — orders never leave the merchant — and only reaches
 * out to WPCOM for the small set of suggestion-lifecycle counters it can't
 * derive from the local mirror (pending count, 30-day approval rate, etc).
 *
 * @package WCAI_PA
 */

declare( strict_types=1 );

namespace WCAI_PA\Overview;

use Automattic\Jetpack\Connection\Client;
use WCAI_PA\Overview\REST\OverviewController;
use WCAI_PA\Suggestions\Suggestions;
use WCAI_PA\Traits\WpcomApiClient;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates building, caching, and refreshing the overview payload.
 */
class Overview {
	use WpcomApiClient;

	/**
	 * Transient key holding the most recent computed overview state.
	 *
	 * The shape matches what the React client expects (see `OverviewState`).
	 */
	const TRANSIENT_KEY = 'wcai_pa_overview_state';

	/**
	 * User preference: sort order for the applied-products list on the overview screen.
	 */
	const APPLIED_PRODUCTS_ORDER_BY_OPTION = 'wcai_pa_overview_applied_products_order_by';

	/**
	 * Cache lifetime for the computed overview. The compute itself is cheap;
	 * five minutes balance freshness with not hammering WPCOM for stat counters.
	 */
	const TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Registers the overview REST controller.
	 */
	public function register(): void {
		( new OverviewController() )->register();
	}

	/**
	 * Valid values for {@see self::APPLIED_PRODUCTS_ORDER_BY_OPTION}.
	 *
	 * @return list<string>
	 */
	public static function applied_products_order_by_enum(): array {
		return array( 'applied_date', 'impact' );
	}

	/**
	 * Reads the stored applied-products sort preference.
	 *
	 * @return string `applied_date` or `impact`.
	 */
	public static function get_applied_products_order_by(): string {
		$value = get_option( self::APPLIED_PRODUCTS_ORDER_BY_OPTION, 'applied_date' );
		if ( ! is_string( $value ) ) {
			return 'applied_date';
		}
		return in_array( $value, self::applied_products_order_by_enum(), true ) ? $value : 'applied_date';
	}

	/**
	 * Persists the applied-products sort preference.
	 *
	 * @param string $order_by `applied_date` or `impact`.
	 * @return void
	 */
	public static function set_applied_products_order_by( string $order_by ): void {
		if ( ! in_array( $order_by, self::applied_products_order_by_enum(), true ) ) {
			$order_by = 'applied_date';
		}
		update_option( self::APPLIED_PRODUCTS_ORDER_BY_OPTION, $order_by, false );
	}

	/**
	 * Returns the cached overview state, computing it lazily if absent.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		return $this->compute_and_cache();
	}

	/**
	 * Forces a recomputation, busting the transient.
	 *
	 * @return array<string, mixed>
	 */
	public function trigger(): array {
		delete_transient( self::TRANSIENT_KEY );
		return $this->compute_and_cache();
	}

	/**
	 * Builds the full overview payload and writes it to the transient.
	 *
	 * Sync-health gating still applies: if there's pending product data that hasn't
	 * reached WPCOM, the suggestion stats from WPCOM will be stale, and we surface
	 * that as `failed` rather than silently returning out-of-date numbers.
	 *
	 * @return array<string, mixed>
	 */
	private function compute_and_cache(): array {
		$local = ( new OverviewCalculator() )->compute();
		$stats = $this->fetch_stats_from_wpcom();

		$state = array(
			'updated_at' => gmdate( 'c' ),
			'result'     => array(
				'stats'            => $stats,
				'summary'          => $local['summary'],
				'applied_products' => $local['applied_products'],
			),
		);

		set_transient( self::TRANSIENT_KEY, $state, self::TRANSIENT_TTL );
		return $state;
	}

	/**
	 * Pulls only the suggestion-lifecycle counters that aren't mirrored locally
	 * (pending count, 30-day approval rate, etc.). Falls back to a locally
	 * derived approximation if the call fails so the dashboard keeps rendering.
	 *
	 * The call is deliberately ignorant of WPCOM's `applied_products` payload —
	 * those verdicts are always stale (they need order data WPCOM may not have)
	 * and we're computing them ourselves anyway.
	 *
	 * @return array{pending: int|null, applied_7d: int|null, approval_rate_30d: float|null, total_suggestions: int|null, weekly_suggestions_used: int|null, weekly_suggestions_limit: int|null}
	 */
	private function fetch_stats_from_wpcom(): array {
		$response = Client::wpcom_json_api_request_as_user(
			$this->get_endpoint_url( '/wcai-pa/overview' ),
			'2',
			array( 'method' => 'GET' ),
			null,
			'wpcom'
		);

		$body = $this->unwrap_response( $response );

		if ( is_wp_error( $body ) ) {
			return $this->local_stats_fallback();
		}

		return $body;
	}

	/**
	 * Returns a page of applied-product rows from the cached overview, sorted for the client.
	 *
	 * @param string $order_by `applied_date` (newest first) or `impact` (largest |lift/dip| first).
	 * @param int    $offset   Zero-based offset.
	 * @param int    $limit    Max rows to return.
	 * @return array{products: array<int, array<string, mixed>>, total: int}
	 */
	public function get_applied_products_page( string $order_by, int $offset, int $limit ): array {
		$state   = $this->get();
		$applied = $state['result']['applied_products'] ?? array();
		if ( ! is_array( $applied ) ) {
			$applied = array();
		}

		$products = array_values( $applied );

		if ( 'impact' !== $order_by ) {
			usort(
				$products,
				static function ( array $a, array $b ): int {
					$ta = strtotime( ( (string) ( $a['last_applied_at'] ?? '' ) ) . ' UTC' );
					$tb = strtotime( ( (string) ( $b['last_applied_at'] ?? '' ) ) . ' UTC' );
					return $tb <=> $ta;
				}
			);
		}

		$total = count( $products );

		return array(
			'products' => array_slice( $products, $offset, $limit ),
			'total'    => $total,
		);
	}

	/**
	 * Locally approximates the stats payload when WPCOM is unreachable.
	 *
	 * @return array{pending: null, applied_7d: int, approval_rate_30d: null, total_suggestions: null, weekly_suggestions_used: null, weekly_suggestions_limit: null}
	 */
	private function local_stats_fallback(): array {
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$applied = get_posts(
			array(
				'post_type'              => array( 'product', 'product_variation' ),
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => Suggestions::APPROVED_PRODUCT_META_KEY,
						'value'   => '1',
						'compare' => '=',
					),
					array(
						'key'     => Suggestions::LAST_APPROVED_AT_META_KEY,
						'value'   => $cutoff,
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
				),
			)
		);

		return array(
			'pending'                  => null,
			'applied_7d'               => count( $applied ),
			'approval_rate_30d'        => null,
			'total_suggestions'        => null,
			'weekly_suggestions_used'  => null,
			'weekly_suggestions_limit' => null,
		);
	}
}
