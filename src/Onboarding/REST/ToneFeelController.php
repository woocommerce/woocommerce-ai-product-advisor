<?php

declare( strict_types=1 );

namespace WCAI_PA\Onboarding\REST;

use Automattic\Jetpack\Connection\Client;
use WCAI_PA\Onboarding\ContentCollector;
use WCAI_PA\Onboarding\ToneFeel;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for tone-feel onboarding endpoints.
 */
class ToneFeelController extends \WP_REST_Controller {

	const REST_NAMESPACE = 'wcai-pa/v1';
	const REST_BASE      = 'tone-feel';

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
			'/' . self::REST_BASE . '/analysis',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analysis' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'trigger_analysis' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => array(
						'tone' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
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
	 * Proxies a GET request to the WPCOM tone-feel endpoint.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_analysis( \WP_REST_Request $request ) {
		$analysis = ( new ToneFeel() )->get_analysis();
		return rest_ensure_response( $analysis );
	}

	/**
	 * Re-triggers content collection and posts to WPCOM.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function trigger_analysis( \WP_REST_Request $request ) {
		$body = ( new ToneFeel() )->trigger_analysis();
		return rest_ensure_response( $body ?? array( 'status' => 'error' ) );
	}

	/**
	 * Gets the saved tone from WPCOM.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get() {
		$body = ( new ToneFeel() )->get();
		return rest_ensure_response( $body );
	}

	/**
	 * Saves the approved tone to WPCOM and marks onboarding complete.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$tone = $request->get_param( 'tone' );
		$body = ( new ToneFeel() )->save( $tone );

		return rest_ensure_response( $body ?? array( 'status' => 'error' ) );
	}
}
