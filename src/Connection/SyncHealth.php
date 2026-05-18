<?php
/**
 * Computes sync health signals for admin UI consumption.
 *
 * @package WCAI_PA
 */

namespace WCAI_PA\Connection;

use Automattic\Jetpack\Connection\Manager as Connection_Manager;
use Automattic\Jetpack\Sync\Health as Sync_Health;
use Automattic\Jetpack\Sync\Modules as Sync_Modules;
use Automattic\Jetpack\Sync\Settings as Sync_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes product-sync readiness for wp-admin surfaces.
 */
class SyncHealth {
	const STATUS_IN_SYNC          = 'in_sync';
	const STATUS_SYNC_IN_PROGRESS = 'sync_in_progress';
	const STATUS_OUT_OF_SYNC      = 'out_of_sync';

	/**
	 * Per-request memoization for the notice payload.
	 *
	 * @var array{needsProductSync: bool, syncStatus: string, title?: string, message?: string}|null
	 */
	private ?array $notice_config = null;

	/**
	 * Builds a normalized payload for the admin frontend.
	 *
	 * @return array{needsProductSync: bool, syncStatus: string, title?: string, message?: string}
	 */
	public function get_notice_config(): array {
		return $this->notice_config ??= $this->compute_notice_config();
	}

	/**
	 * Computes the notice configuration for the admin frontend.
	 *
	 * @return array{needsProductSync: bool, syncStatus: string, title?: string, message?: string}
	 */
	private function compute_notice_config(): array {
		if ( ! $this->is_connected() || ! $this->has_products() ) {
			return array(
				'needsProductSync' => false,
				'syncStatus'       => self::STATUS_IN_SYNC,
			);
		}

		if ( $this->is_full_sync_in_progress() ) {
			return $this->sync_notice(
				self::STATUS_SYNC_IN_PROGRESS,
				__( 'Sync in progress', 'wcai-pa' ),
				__( 'Products are syncing to WordPress.com. This may take a few minutes.', 'wcai-pa' )
			);
		}

		if ( $this->is_product_type_excluded_from_sync() || $this->is_posts_sync_unhealthy() ) {
			return $this->sync_notice(
				self::STATUS_OUT_OF_SYNC,
				__( 'Data sync pending', 'wcai-pa' ),
				__( 'Some products still need to sync to WordPress.com before full optimization is available.', 'wcai-pa' )
			);
		}

		return array(
			'needsProductSync' => false,
			'syncStatus'       => self::STATUS_IN_SYNC,
		);
	}

	/**
	 * Returns a notice configuration for a sync issue.
	 *
	 * @param string $sync_status One of the STATUS_* constants.
	 * @param string $title       Localized notice title.
	 * @param string $message     Localized notice body.
	 * @return array{needsProductSync: true, syncStatus: string, title: string, message: string}
	 */
	private function sync_notice( string $sync_status, string $title, string $message ): array {
		return array(
			'needsProductSync' => true,
			'syncStatus'       => $sync_status,
			'title'            => $title,
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
	 * Checks if a Jetpack full sync is currently running.
	 *
	 * @return bool
	 */
	private function is_full_sync_in_progress(): bool {
		$full_sync_module = Sync_Modules::get_module( 'full-sync' );

		if ( ! $full_sync_module ) {
			return false;
		}

		return $full_sync_module->is_started() && ! $full_sync_module->is_finished();
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
