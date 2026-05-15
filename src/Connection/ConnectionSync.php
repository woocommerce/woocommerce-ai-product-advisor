<?php
/**
 * Connection sync domain service and REST bootstrap.
 *
 * @package WCAI_PA
 */

namespace WCAI_PA\Connection;

use Automattic\Jetpack\Sync\Actions as Sync_Actions;
use WCAI_PA\Connection\REST\ConnectionSyncController;

defined( 'ABSPATH' ) || exit;

/**
 * Encapsulates manual sync triggers and polling payload.
 */
class ConnectionSync {
	/**
	 * Registers connection sync REST routes.
	 *
	 * @return void
	 */
	public function register(): void {
		( new ConnectionSyncController() )->register();
	}

	/**
	 * Returns the current sync state for frontend polling.
	 *
	 * @return array{needsProductSync: bool, title?: string, message?: string}
	 */
	public function get_sync_state(): array {
		return ( new SyncHealth() )->get_notice_config();
	}

	/**
	 * Triggers product sync manually when an admin clicks "Sync now".
	 *
	 * @return array{started: bool}
	 */
	public function trigger_sync(): array {
		return array(
			'started' => $this->trigger_posts_sync( $this->get_product_post_ids() ),
		);
	}

	/**
	 * Triggers a posts-only full sync for the provided post IDs.
	 *
	 * @param int[]  $post_ids Post IDs to sync.
	 * @param string $context  Full sync context label.
	 * @return bool
	 */
	public function trigger_posts_sync( array $post_ids, string $context = 'wcai_pa_manual_sync_products' ): bool {
		$post_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $post_ids ),
					static fn( int $post_id ): bool => $post_id > 0
				)
			)
		);

		if ( empty( $post_ids ) ) {
			return false;
		}

		return (bool) Sync_Actions::do_full_sync( array( 'posts' => $post_ids ), $context );
	}

	/**
	 * Returns the IDs of all published product posts.
	 *
	 * @return int[]
	 */
	private function get_product_post_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'              => array( 'product', 'product_variation' ),
					'post_status'            => 'publish',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'cache_results'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			)
		);
	}
}
