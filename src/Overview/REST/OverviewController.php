<?php
/**
 * Overview REST controller.
 *
 * @package WCAI_PA
 */

declare( strict_types=1 );

namespace WCAI_PA\Overview\REST;

use WCAI_PA\Overview\Overview;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the overview data and refresh trigger over the REST API.
 */
class OverviewController extends \WP_REST_Controller {

	const REST_NAMESPACE = 'wcai-pa/v1';
	const REST_BASE      = 'overview';

	/**
	 * Hooks the controller into WordPress.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the REST routes for the overview endpoint.
	 */
	public function register_routes(): void {
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
					'callback'            => array( $this, 'trigger' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/applied-products',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_applied_products' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => array(
						'order_by' => array(
							'description' => __( 'Sort: newest approval first, or strongest lift/dip magnitude first.', 'wcai-pa' ),
							'type'        => 'string',
							'enum'        => array( 'applied_date', 'impact' ),
							'default'     => 'applied_date',
						),
						'count'    => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
						'offset'   => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/applied-products-order',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_applied_products_order' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => array(
						'order_by' => array(
							'required'    => true,
							'description' => __( 'Sort: newest approval first, or strongest lift/dip magnitude first.', 'wcai-pa' ),
							'type'        => 'string',
							'enum'        => array( 'applied_date', 'impact' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Restricts access to users who can manage WooCommerce.
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Returns the cached overview payload.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get() {
		return rest_ensure_response(
			$this->with_applied_products_order( ( new Overview() )->get() )
		);
	}

	/**
	 * Triggers a refresh of the overview payload.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function trigger() {
		return rest_ensure_response(
			$this->with_applied_products_order( ( new Overview() )->trigger() )
		);
	}

	/**
	 * Persists the overview applied-products sort preference.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function set_applied_products_order( \WP_REST_Request $request ) {
		$order_by = (string) $request->get_param( 'order_by' );
		Overview::set_applied_products_order_by( $order_by );

		return rest_ensure_response(
			array(
				'applied_products_order_by' => Overview::get_applied_products_order_by(),
			)
		);
	}

	/**
	 * Paginated applied-product rows (from cached overview), with server-side sort.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_applied_products( \WP_REST_Request $request ) {
		$order_by = (string) $request->get_param( 'order_by' );
		if ( ! in_array( $order_by, array( 'applied_date', 'impact' ), true ) ) {
			$order_by = 'applied_date';
		}

		$count  = (int) $request->get_param( 'count' );
		$count  = min( 100, max( 1, $count ) );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );

		$page = ( new Overview() )->get_applied_products_page( $order_by, $offset, $count );

		return rest_ensure_response( $page );
	}

	/**
	 * Adds user preference fields that are not part of the overview transient.
	 *
	 * @param array<string, mixed> $payload Overview state.
	 * @return array<string, mixed>
	 */
	private function with_applied_products_order( array $payload ): array {
		$payload['applied_products_order_by'] = Overview::get_applied_products_order_by();
		return $payload;
	}
}
