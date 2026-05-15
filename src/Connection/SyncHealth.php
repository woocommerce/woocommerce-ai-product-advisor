<?php
/**
 * Computes sync health signals for admin UI consumption.
 *
 * @package WCAI_PA
 */

namespace WCAI_PA\Connection;

use Automattic\Jetpack\Connection\Manager as Connection_Manager;
use Automattic\Jetpack\Sync\Health as Sync_Health;
use Automattic\Jetpack\Sync\Settings as Sync_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes product-sync readiness for wp-admin surfaces.
 */
class SyncHealth {
	/**
	 * Per-request memoization for the notice payload.
	 *
	 * @var array{needsProductSync: bool, title?: string, message?: string}|null
	 */
	private ?array $notice_config = null;

	/**
	 * Builds a normalized payload for the admin frontend.
	 *
	 * @return array{needsProductSync: bool, title?: string, message?: string}
	 */
	public function get_notice_config(): array {
		return $this->notice_config ??= $this->compute_notice_config();
	}

	/**
	 * Computes the notice configuration for the admin frontend.
	 *
	 * @return array{needsProductSync: bool, title?: string, message?: string}
	 */
	private function compute_notice_config(): array {
		if ( ! $this->is_connected() || ! $this->has_products() ) {
			return array( 'needsProductSync' => false );
		}

		if ( $this->is_product_type_excluded_from_sync() || $this->is_posts_sync_unhealthy() ) {
			return $this->pending_notice(
				__( 'Some products still need to sync to WordPress.com before full optimization is available.', 'wcai-pa' )
			);
		}

		return array( 'needsProductSync' => false );
	}

	/**
	 * Returns a notice configuration for a pending sync.
	 *
	 * @param string $message Localized notice body.
	 * @return array{needsProductSync: true, title: string, message: string}
	 */
	private function pending_notice( string $message ): array {
		return array(
			'needsProductSync' => true,
			'title'            => __( 'Data sync pending', 'wcai-pa' ),
			'message'          => $message,
		);
	}

	/**
	 * Checks if there are any published product posts.
	 *
	 * @return bool
	 */
	private function has_products(): bool {
		return (int) wp_count_posts( 'product' )->publish > 0;
	}

	/**
	 * Checks if the site is connected to WordPress.com.
	 *
	 * @return bool
	 */
	private function is_connected(): bool {
		return ( new Connection_Manager( Connection::SLUG ) )->is_connected();
	}

	/**
	 * Checks if the product post type is excluded from sync.
	 *
	 * @return bool
	 */
	private function is_product_type_excluded_from_sync(): bool {
		$blacklist = Sync_Settings::get_setting( 'post_types_blacklist' );

		return is_array( $blacklist ) && in_array( 'product', $blacklist, true );
	}

	/**
	 * Checks if the posts sync is unhealthy.
	 *
	 * @return bool
	 */
	private function is_posts_sync_unhealthy(): bool {
		return Sync_Health::STATUS_IN_SYNC !== Sync_Health::get_status();
	}
}
