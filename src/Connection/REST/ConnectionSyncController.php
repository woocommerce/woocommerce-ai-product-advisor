<?php
/**
 * REST controller for connection sync state and trigger endpoints.
 *
 * @package WCAI_PA
 */

namespace WCAI_PA\Connection\REST;

use WCAI_PA\Connection\Connection;
use WCAI_PA\Connection\ConnectionSync;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes polling and manual-trigger endpoints for product sync.
 */
class ConnectionSyncController extends \WP_REST_Controller {
	const REST_NAMESPACE = 'wcai-pa/v1';
	const REST_BASE      = 'connection/sync';

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers GET/POST REST routes for sync state.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_sync_state' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'trigger_sync' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * Checks that the current user can manage WooCommerce.
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Returns current sync state payload for polling.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_sync_state() {
		if ( ! Connection::is_tos_accepted() ) {
			return $this->tos_required_error();
		}
		return rest_ensure_response( ( new ConnectionSync() )->get_sync_state() );
	}

	/**
	 * Triggers sync mutation.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function trigger_sync() {
		if ( ! Connection::is_tos_accepted() ) {
			return $this->tos_required_error();
		}
		return rest_ensure_response( ( new ConnectionSync() )->trigger_sync() );
	}

	/**
	 * Returns a WP_Error indicating ToS must be accepted first.
	 *
	 * @return \WP_Error
	 */
	private function tos_required_error(): \WP_Error {
		return new \WP_Error(
			'tos_not_accepted',
			__( 'You must accept the Terms of Service before using this feature.', 'wcai-pa' ),
			array( 'status' => 403 )
		);
	}
}
