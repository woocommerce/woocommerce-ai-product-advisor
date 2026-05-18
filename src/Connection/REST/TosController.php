<?php

declare( strict_types=1 );

namespace WCAI_PA\Connection\REST;

use WCAI_PA\Connection\Connection;
use WCAI_PA\Onboarding\ToneFeel;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for Terms of Service acceptance.
 */
class TosController extends \WP_REST_Controller {
	const REST_NAMESPACE = 'wcai-pa/v1';
	const REST_BASE      = 'tos';

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/accept',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'accept' ),
				'permission_callback' => array( $this, 'permission_callback' ),
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
	 * Records ToS acceptance. For already-connected sites this also kicks off
	 * the tone-feel analysis that would normally fire on admin_init (which has
	 * already executed by the time the user clicks "Agree").
	 *
	 * @return \WP_REST_Response
	 */
	public function accept(): \WP_REST_Response {
		Connection::accept_tos();

		( new ToneFeel() )->maybe_perform_analysis();

		return rest_ensure_response( array( 'accepted' => true ) );
	}
}
