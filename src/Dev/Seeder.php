<?php
/**
 * Dev-only WP-CLI seeder for testing the product ranker against realistic data.
 *
 * Registered only when WP_DEBUG is enabled and WP-CLI is loaded — never in production.
 *
 * Delegates product generation to wc-smooth-generator and post-processes each
 * product to fit a quality tier (poor / decent / polished) so the ranker has
 * signal to differentiate. Smooth generator must be installed and active:
 *   https://github.com/woocommerce/wc-smooth-generator
 *
 * Usage:
 *   wp wcai-pa seed-products --count=50
 *   wp wcai-pa seed-products --cleanup
 *   wp wcai-pa seed-orders 123 20
 *   wp wcai-pa seed-orders 123 200 --applied-at=2026-04-16T19:30:10 --lift=50
 *
 * @package WCAI_PA
 */

namespace WCAI_PA\Dev;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds WooCommerce products with varied quality so the ranker has something to differentiate.
 *
 * Products are tagged with the `_wcai_pa_seeded` meta flag so `--cleanup` only removes
 * what this command created — never touches genuine merchant data.
 */
class Seeder {

	/**
	 * Meta key used to mark products created by this seeder, so cleanup is safe.
	 */
	const SEEDED_META_KEY = '_wcai_pa_seeded';

	/**
	 * Quality tiers and their relative weight in the seed mix.
	 */
	const TIER_POOR     = 'poor';
	const TIER_DECENT   = 'decent';
	const TIER_POLISHED = 'polished';

	/**
	 * Smooth generator's product class.
	 */
	const SMOOTH_GENERATOR_CLASS = 'WC\\SmoothGenerator\\Generator\\Product';

	/**
	 * Smooth generator's order class.
	 */
	const SMOOTH_GENERATOR_ORDER_CLASS = 'WC\\SmoothGenerator\\Generator\\Order';

	/**
	 * Per-tier ranges for the number of completed orders to attach to each seeded product.
	 */
	const ORDERS_PER_TIER = array(
		self::TIER_POOR     => array( 0, 2 ),
		self::TIER_DECENT   => array( 3, 15 ),
		self::TIER_POLISHED => array( 20, 60 ),
	);

	/**
	 * Baseline pre-window length (days) used for placement and lift calculations.
	 *
	 * Mirrors the WPCOM-side overview builder so seeded distributions actually map
	 * to the verdict the user will see.
	 */
	const BASELINE_WINDOW_DAYS = 30;

	/**
	 * Register the WP-CLI commands. Called from Plugin bootstrap.
	 */
	public static function register(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		\WP_CLI::add_command( 'wcai-pa seed-products', array( __CLASS__, 'handle' ) );
		\WP_CLI::add_command( 'wcai-pa seed-orders', array( __CLASS__, 'handle_orders' ) );
		\WP_CLI::add_command( 'wcai-pa mark-approved', array( __CLASS__, 'handle_mark_approved' ) );
	}

	/**
	 * Stamps the "approved suggestion" mirror meta on a product so the local overview
	 * computation treats it as if a suggestion had been approved. Useful when seeding
	 * a test scenario without going through the UI/WPCOM round trip.
	 *
	 * ## OPTIONS
	 *
	 * <product_id>
	 * : Product ID to mark.
	 *
	 * [--applied-at=<iso>]
	 * : ISO UTC timestamp to record as `_wcai_pa_last_approved_at`. Defaults to now.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function handle_mark_approved( array $args, array $assoc_args = array() ): void {
		$product_id = (int) ( $args[0] ?? 0 );
		if ( $product_id <= 0 || ! get_post( $product_id ) ) {
			\WP_CLI::error( sprintf( 'Product %d not found.', $product_id ) );
		}

		$applied_at = isset( $assoc_args['applied-at'] )
			? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $assoc_args['applied-at'] . ' UTC' ) )
			: gmdate( 'Y-m-d H:i:s' );

		update_post_meta( $product_id, \WCAI_PA\Suggestions\Suggestions::APPROVED_PRODUCT_META_KEY, 1 );
		update_post_meta( $product_id, \WCAI_PA\Suggestions\Suggestions::LAST_APPROVED_AT_META_KEY, $applied_at );

		\WP_CLI::success( sprintf( 'Marked product %d approved at %s (UTC).', $product_id, $applied_at ) );
	}

	/**
	 * Seed products with a mix of quality tiers, varied sales counts, and spread post_date.
	 *
	 * Smooth generator (https://github.com/woocommerce/wc-smooth-generator) produces a
	 * realistic baseline; we then mutate per tier so the ranker sees the full quality range.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<count>]
	 * : How many products to create. Default 30.
	 *
	 * [--cleanup]
	 * : Delete every product previously created by this seeder, then exit.
	 *
	 * [--dry-run]
	 * : Generate without saving and print what would be written.
	 *
	 * [--skip-orders]
	 * : Skip the per-tier completed-order seeding (faster, leaner).
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public static function handle( array $args, array $assoc_args ): void {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			\WP_CLI::error( 'WooCommerce is not active.' );
		}

		if ( ! empty( $assoc_args['cleanup'] ) ) {
			self::cleanup();
			return;
		}

		if ( ! class_exists( self::SMOOTH_GENERATOR_CLASS ) ) {
			\WP_CLI::error( 'wc-smooth-generator is not active. Install it from https://github.com/woocommerce/wc-smooth-generator and retry.' );
		}

		self::force_en_us_locale();

		$count       = max( 1, (int) ( $assoc_args['count'] ?? 30 ) );
		$dry_run     = ! empty( $assoc_args['dry-run'] );
		$skip_orders = ! empty( $assoc_args['skip-orders'] );

		$plan = self::build_plan( $count );

		if ( $dry_run ) {
			\WP_CLI::log( sprintf( 'Plan for %d products (no writes):', $count ) );
			foreach ( $plan as $tier => $n ) {
				\WP_CLI::log( sprintf( '  %-9s %d', $tier, $n ) );
			}
			\WP_CLI::log( '' );

			$rows = array();
			foreach ( $plan as $tier => $tier_count ) {
				for ( $i = 0; $i < $tier_count; $i++ ) {
					$product = self::build_product( $tier, false );
					if ( null === $product ) {
						continue;
					}
					$rows[] = array(
						'tier'              => $tier,
						'title'             => $product->get_name(),
						'price'             => $product->get_regular_price(),
						'total_sales'       => $product->get_total_sales(),
						'age_days'          => self::age_in_days( $product ),
						'short_description' => self::truncate_for_log( (string) $product->get_short_description() ),
						'description'       => self::truncate_for_log( (string) $product->get_description() ),
					);
				}
			}

			\WP_CLI\Utils\format_items( 'table', $rows, array( 'tier', 'title', 'price', 'total_sales', 'age_days', 'short_description', 'description' ) );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Seeding products', $count );

		$created      = 0;
		$orders_total = 0;
		foreach ( $plan as $tier => $tier_count ) {
			for ( $i = 0; $i < $tier_count; $i++ ) {
				$product = self::build_product( $tier, true );
				if ( null !== $product ) {
					++$created;

					if ( ! $skip_orders ) {
						$range         = self::ORDERS_PER_TIER[ $tier ];
						$order_count   = wp_rand( $range[0], $range[1] );
						$orders_total += self::seed_orders_for_product( $product, $order_count );
					}
				}
				$progress->tick();
			}
		}

		$progress->finish();

		\WP_CLI::success(
			sprintf(
				'Seeded %d/%d products (%d poor, %d decent, %d polished) and %d orders. Run `wp wcai-pa seed-products --cleanup` to remove them.',
				$created,
				$count,
				$plan[ self::TIER_POOR ],
				$plan[ self::TIER_DECENT ],
				$plan[ self::TIER_POLISHED ],
				$orders_total
			)
		);
	}

	/**
	 * Seed completed orders against an existing product.
	 *
	 * ## OPTIONS
	 *
	 * <product_id>
	 * : Product ID to attach the orders to.
	 *
	 * [<count>]
	 * : Number of orders to create. Default 10.
	 *
	 * [--applied-at=<iso>]
	 * : ISO timestamp of the simulated suggestion approval. When set, orders are split
	 *   between a 30-day pre-window and a post-window from this date to now, with
	 *   per-day rates controlled by --lift. Without this flag, dates are uniform over
	 *   the last 365 days (legacy behaviour).
	 *
	 * [--lift=<percent>]
	 * : Post-window lift relative to the pre-window daily rate. e.g. 50 means the
	 *   post window has 1.5x the per-day order rate of the pre window; -25 means
	 *   the post window dipped to 0.75x. Defaults to 0 (no_change). Only meaningful
	 *   when --applied-at is also passed.
	 *
	 * @param array $args       Positional args: product_id, count.
	 * @param array $assoc_args Associative args.
	 */
	public static function handle_orders( array $args, array $assoc_args = array() ): void {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			\WP_CLI::error( 'WooCommerce is not active.' );
		}

		if ( ! class_exists( self::SMOOTH_GENERATOR_ORDER_CLASS ) ) {
			\WP_CLI::error( 'wc-smooth-generator is not active. Install it from https://github.com/woocommerce/wc-smooth-generator and retry.' );
		}

		self::force_en_us_locale();

		$product_id = (int) ( $args[0] ?? 0 );
		$count      = max( 1, (int) ( $args[1] ?? 10 ) );

		if ( $product_id <= 0 ) {
			\WP_CLI::error( 'Pass a product id: wp wcai-pa seed-orders <product_id> [<count>].' );
		}

		$product = wc_get_product( $product_id );
		if ( ! ( $product instanceof \WC_Product ) ) {
			\WP_CLI::error( sprintf( 'Product %d not found.', $product_id ) );
		}

		$applied_at_arg = isset( $assoc_args['applied-at'] ) ? (string) $assoc_args['applied-at'] : '';
		$lift_percent   = isset( $assoc_args['lift'] ) ? (float) $assoc_args['lift'] : 0.0;
		$placement      = self::build_placement_plan( $count, $applied_at_arg, $lift_percent );

		$progress = \WP_CLI\Utils\make_progress_bar(
			$placement['summary'] ?? sprintf( 'Seeding %d orders for product %d', $count, $product_id ),
			$count
		);
		$created  = 0;
		foreach ( $placement['ranges'] as $range ) {
			for ( $i = 0; $i < $range['count']; $i++ ) {
				$order = self::create_order_with_product( $product, $range['start_ts'], $range['end_ts'] );
				if ( null !== $order ) {
					++$created;
				}
				$progress->tick();
			}
		}
		$progress->finish();

		\WP_CLI::success( sprintf( 'Seeded %d/%d orders for product %d.', $created, $count, $product_id ) );
	}

	/**
	 * Build the per-window order distribution for `seed-orders`.
	 *
	 * Without --applied-at: a single range across the last year (legacy uniform spread).
	 * With --applied-at: two ranges (pre window of 30 days, post window from applied to now)
	 * with counts derived so that the post-window per-day rate equals the pre-window rate
	 * times (1 + lift_percent/100). Solves N_pre + N_post = count under that ratio.
	 *
	 * Mirrors the WPCOM-side BASELINE_WINDOW_DAYS (30) so the lift verdict actually reflects
	 * what the seeder intended.
	 *
	 * @param int    $count          Total orders to seed.
	 * @param string $applied_at_arg ISO timestamp string ('' = no split).
	 * @param float  $lift_percent   Post-window lift relative to pre rate.
	 * @return array{ranges: array<int, array{count:int,start_ts:int,end_ts:int}>, summary: string}
	 */
	private static function build_placement_plan( int $count, string $applied_at_arg, float $lift_percent ): array {
		$now_ts = time();

		if ( '' === $applied_at_arg ) {
			return array(
				'ranges'  => array(
					array(
						'count'    => $count,
						'start_ts' => $now_ts - 365 * DAY_IN_SECONDS,
						'end_ts'   => $now_ts - DAY_IN_SECONDS,
					),
				),
				'summary' => sprintf( 'Seeding %d orders (uniform over last year)', $count ),
			);
		}

		$applied_ts = strtotime( $applied_at_arg . ' UTC' );
		if ( false === $applied_ts || $applied_ts >= $now_ts ) {
			\WP_CLI::error( sprintf( 'Invalid --applied-at value "%s"; must parse to a UTC datetime in the past.', $applied_at_arg ) );
		}

		$days_pre  = self::BASELINE_WINDOW_DAYS;
		$days_post = max( 1, (int) floor( ( $now_ts - $applied_ts ) / DAY_IN_SECONDS ) );
		$ratio     = ( 1.0 + $lift_percent / 100.0 ) * ( $days_post / $days_pre );

		// N_pre + N_pre * ratio = count → N_pre = count / (1 + ratio).
		$n_pre  = (int) round( $count / ( 1.0 + $ratio ) );
		$n_pre  = max( 0, min( $count, $n_pre ) );
		$n_post = $count - $n_pre;

		return array(
			'ranges'  => array(
				array(
					'count'    => $n_pre,
					'start_ts' => $applied_ts - $days_pre * DAY_IN_SECONDS,
					'end_ts'   => $applied_ts,
				),
				array(
					'count'    => $n_post,
					'start_ts' => $applied_ts,
					'end_ts'   => $now_ts,
				),
			),
			'summary' => sprintf(
				'Seeding %d orders (pre=%d in %dd, post=%d in %dd, lift=%.0f%%)',
				$count,
				$n_pre,
				$days_pre,
				$n_post,
				$days_post,
				$lift_percent
			),
		);
	}

	/**
	 * Distribute the requested count across the three tiers (~1/3 each).
	 *
	 * @param int $count Total products to seed.
	 * @return array<string, int>
	 */
	private static function build_plan( int $count ): array {
		$third = (int) floor( $count / 3 );

		return array(
			self::TIER_POOR     => $third,
			self::TIER_DECENT   => $third,
			self::TIER_POLISHED => $count - ( 2 * $third ),
		);
	}

	/**
	 * Generate one product via smooth generator and apply the tier mutation.
	 *
	 * @param string $tier Quality tier.
	 * @param bool   $save Whether to persist (false = dry run).
	 * @return \WC_Product|null Product object, or null on generator failure.
	 */
	private static function build_product( string $tier, bool $save ): ?\WC_Product {
		$class   = self::SMOOTH_GENERATOR_CLASS;
		$product = $class::generate( false, array( 'type' => 'simple' ) );

		if ( is_wp_error( $product ) || ! ( $product instanceof \WC_Product ) ) {
			\WP_CLI::warning( sprintf( 'Smooth generator failed for tier %s; skipping.', $tier ) );
			return null;
		}

		self::apply_tier( $product, $tier );

		$product->update_meta_data( self::SEEDED_META_KEY, 1 );

		if ( $save ) {
			$product->save();
		}

		return $product;
	}

	/**
	 * Mutate a smooth-generated product to fit a quality tier so the ranker
	 * sees the full spectrum (missing fields, mid-quality, fully polished).
	 *
	 * Spread is randomized within tier so re-running the seeder produces a varied dataset.
	 * post_date is randomized between 4 and 720 days ago — above the ranker's
	 * MIN_PRODUCT_AGE_DAYS=3 cutoff so every seeded product is visible.
	 *
	 * @param \WC_Product $product Smooth-generated product.
	 * @param string      $tier    Quality tier.
	 */
	private static function apply_tier( \WC_Product $product, string $tier ): void {
		$age_days     = wp_rand( 4, 720 );
		$date_created = time() - ( $age_days * DAY_IN_SECONDS );
		$product->set_date_created( $date_created );

		switch ( $tier ) {
			case self::TIER_POOR:
				$product->set_description( wp_rand( 0, 1 ) ? '' : 'A ' . strtolower( $product->get_name() ) . '.' );
				$product->set_short_description( '' );
				$product->set_image_id( 0 );
				$product->set_total_sales( wp_rand( 0, 5 ) );
				break;

			case self::TIER_DECENT:
				$product->set_total_sales( wp_rand( 6, 100 ) );
				break;

			case self::TIER_POLISHED:
			default:
				$product->set_total_sales( wp_rand( 100, 2000 ) );
				break;
		}
	}

	/**
	 * Seed N completed orders against the given product.
	 *
	 * @param \WC_Product $product Target product.
	 * @param int         $count   Orders to create.
	 * @return int Number actually created.
	 */
	private static function seed_orders_for_product( \WC_Product $product, int $count ): int {
		if ( $count <= 0 ) {
			return 0;
		}

		$created = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			if ( null !== self::create_order_with_product( $product ) ) {
				++$created;
			}
		}
		return $created;
	}

	/**
	 * Build a single completed order containing the given product as its only line item.
	 *
	 * Smooth generator gives us the customer/addresses; we replace its line items with our
	 * target product so the seeded product reliably appears in order history. Order date is
	 * randomized within the last year and the order is tagged so cleanup can remove it.
	 *
	 * @param \WC_Product $product  Target product.
	 * @param int|null    $start_ts Earliest creation timestamp (inclusive). Defaults to 365 days ago.
	 * @param int|null    $end_ts   Latest creation timestamp (exclusive). Defaults to 1 day ago.
	 * @return \WC_Order|null
	 */
	private static function create_order_with_product( \WC_Product $product, ?int $start_ts = null, ?int $end_ts = null ): ?\WC_Order {
		$class = self::SMOOTH_GENERATOR_ORDER_CLASS;
		$order = $class::generate( true );

		if ( is_wp_error( $order ) || ! ( $order instanceof \WC_Order ) ) {
			\WP_CLI::warning( 'Smooth generator failed to create an order; skipping.' );
			return null;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			$order->remove_item( $item_id );
		}

		$order->add_product( $product, wp_rand( 1, 3 ) );

		$now      = time();
		$start_ts = $start_ts ?? ( $now - 365 * DAY_IN_SECONDS );
		$end_ts   = $end_ts ?? ( $now - DAY_IN_SECONDS );
		if ( $end_ts <= $start_ts ) {
			$end_ts = $start_ts + 1;
		}
		$ts = wp_rand( $start_ts, $end_ts - 1 );
		$order->set_date_created( $ts );
		$order->set_date_paid( $ts );
		$order->set_date_completed( $ts );
		$order->set_status( 'completed' );

		$order->update_meta_data( self::SEEDED_META_KEY, 1 );
		$order->calculate_totals();
		$order->save();

		self::regenerate_order_lookup( $order->get_id() );

		return $order;
	}

	/**
	 * Synchronously populate the analytics lookup row for an order.
	 *
	 * `wc_order_product_lookup` is what the WPCOM-side overview builder reads to compute
	 * pre/post lift, and it isn't auto-populated for orders created via direct CRUD save
	 * outside the normal request lifecycle. `OrdersScheduler::import()` does the inline
	 * insert so the row exists immediately and rides the next Jetpack sync to WPCOM.
	 *
	 * @param int $order_id Order ID just saved.
	 */
	private static function regenerate_order_lookup( int $order_id ): void {
		$importer = '\\Automattic\\WooCommerce\\Internal\\Admin\\Schedulers\\OrdersScheduler';
		if ( ! class_exists( $importer ) ) {
			return;
		}

		$importer::import( $order_id );
	}

	/**
	 * Pin Faker to en_US (and the merchant's allowed-country pool to just US) for the
	 * rest of the request.
	 *
	 * Wc-smooth-generator instantiates Faker twice for every generated order:
	 *   1. Once against the WP `locale` for site-level fakery (covered by the `locale` filter).
	 *   2. Once against the customer's billing country, picked at random from
	 *      `WC()->countries->get_allowed_countries()` (drives `CustomerInfo::generate_person`).
	 *
	 * Several locales (zh_*, ja_*, ko_*, th_TH, …) require PHP's `intl` extension to
	 * generate usernames; the wp-env CLI image doesn't ship `intl`, so any random pick
	 * outside the small ASCII-friendly set crashes mid-loop. Constraining both axes to
	 * US sidesteps the issue entirely without a Docker rebuild.
	 *
	 * Idempotent: re-registering the filters is harmless.
	 */
	private static function force_en_us_locale(): void {
		add_filter( 'locale', static fn() => 'en_US', PHP_INT_MAX );

		$us_only = static fn(): array => array( 'US' => 'United States (US)' );
		add_filter( 'woocommerce_countries_allowed_countries', $us_only, PHP_INT_MAX );
		add_filter( 'woocommerce_countries_shipping_countries', $us_only, PHP_INT_MAX );
	}

	/**
	 * Age in whole days from the product's date_created to now.
	 *
	 * @param \WC_Product $product Product.
	 * @return int
	 */
	private static function age_in_days( \WC_Product $product ): int {
		$date = $product->get_date_created();
		if ( ! $date ) {
			return 0;
		}
		return (int) max( 0, round( ( time() - $date->getTimestamp() ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Compact a multi-line string for one-row table output.
	 *
	 * @param string $text  Raw value (may contain HTML and newlines).
	 * @param int    $limit Max characters to keep.
	 * @return string
	 */
	private static function truncate_for_log( string $text, int $limit = 80 ): string {
		$flat = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) ?? '' );
		if ( '' === $flat ) {
			return '(empty)';
		}
		if ( mb_strlen( $flat ) <= $limit ) {
			return $flat;
		}
		return mb_substr( $flat, 0, $limit ) . '…';
	}

	/**
	 * Delete every product and order previously created by this seeder.
	 *
	 * Orders are removed first so HPOS / legacy storage doesn't keep dangling
	 * references to products we're about to delete.
	 */
	private static function cleanup(): void {
		$order_ids = wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'status'     => 'any',
				'meta_key'   => self::SEEDED_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 1,                     // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( ! empty( $order_ids ) ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Deleting seeded orders', count( $order_ids ) );
			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( (int) $order_id );
				if ( $order ) {
					$order->delete( true );
				}
				$progress->tick();
			}
			$progress->finish();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::SEEDED_META_KEY,
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'  => true,
			)
		);

		$product_ids = $query->posts;

		if ( empty( $product_ids ) && empty( $order_ids ) ) {
			\WP_CLI::success( 'No seeded products or orders found.' );
			return;
		}

		if ( ! empty( $product_ids ) ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Deleting seeded products', count( $product_ids ) );
			foreach ( $product_ids as $id ) {
				wp_delete_post( (int) $id, true );
				$progress->tick();
			}
			$progress->finish();
		}

		\WP_CLI::success(
			sprintf(
				'Deleted %d seeded products and %d seeded orders.',
				count( $product_ids ),
				count( $order_ids )
			)
		);
	}
}
