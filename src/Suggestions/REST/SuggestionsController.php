<?php

declare( strict_types=1 );

namespace WCAI_PA\Suggestions\REST;

use WCAI_PA\Connection\Connection;
use WCAI_PA\Suggestions\Suggestions;
use WCAI_PA\Suggestions\SuggestionPresenter;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for product suggestion endpoints.
 *
 * All responses are mocked until the real AI pipeline is wired in.
 */
class SuggestionsController extends \WP_REST_Controller {

	const REST_NAMESPACE = 'wcai-pa/v1';
	const REST_BASE      = 'suggestions';

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
			'/' . self::REST_BASE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_suggestions' ),
				'permission_callback' => array( $this, 'permission_callback' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '-reviewed',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_reviewed_suggestions' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'count'  => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'offset' => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<id>[\w-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_suggestion' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<id>[\w-]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_suggestion' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'id'              => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'          => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'approved', 'rejected', 'reverted' ),
					),
					'approved_fields' => array(
						'required'          => false,
						'type'              => 'array',
						'validate_callback' => array( $this, 'validate_approved_fields' ),
						'items'             => array(
							'type'       => 'object',
							'properties' => array(
								'field'     => array( 'type' => 'string' ),
								'new_value' => array( 'type' => 'string' ),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Validates approved fields based on the selected status.
	 *
	 * @param mixed            $value   Parameter value.
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public function validate_approved_fields( $value, \WP_REST_Request $request ) {
		if ( 'approved' !== $request->get_param( 'status' ) ) {
			return true;
		}

		if ( empty( $value ) || ! is_array( $value ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'approved_fields is required when status is approved.', 'wcai-pa' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Handles suggestion status updates.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_suggestion( \WP_REST_Request $request ) {
		if ( ! Connection::is_tos_accepted() ) {
			return $this->tos_required_error();
		}
		if ( 'approved' === $request->get_param( 'status' ) ) {
			return $this->approve_suggestion( $request );
		}

		if ( 'reverted' === $request->get_param( 'status' ) ) {
			return $this->revert_suggestion( $request );
		}

		return $this->reject_suggestion( $request );
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
	 * Returns the list of pending suggestion IDs and the current status.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_suggestions( \WP_REST_Request $request ) {
		if ( ! Connection::is_tos_accepted() ) {
			return $this->tos_required_error();
		}
		$suggestions = ( new Suggestions() )->get_suggestions();
		return rest_ensure_response( $suggestions );
	}

	/**
	 * Returns the details of a single suggestion.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_suggestion( \WP_REST_Request $request ) {
		if ( ! Connection::is_tos_accepted() ) {
			return $this->tos_required_error();
		}
		$id         = $request->get_param( 'id' );
		$suggestion = ( new Suggestions() )->get_suggestion( $id );

		if ( is_wp_error( $suggestion ) ) {
			return $suggestion;
		}

		$suggestion = ( new SuggestionPresenter( $suggestion ) )
			->field_labels()
			->field_types()
			->product_meta()
			->current_values()
			->to_array();

		return rest_ensure_response( $suggestion );
	}

	/**
	 * Approves a suggestion, applying the selected field changes.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approve_suggestion( \WP_REST_Request $request ) {
		$id              = $request->get_param( 'id' );
		$approved_fields = $request->get_param( 'approved_fields' );
		$result          = ( new Suggestions() )->approve_suggestion( $id, $approved_fields );
		return rest_ensure_response( $result );
	}

	/**
	 * Returns reviewed (non-pending) suggestions with pagination.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_reviewed_suggestions( \WP_REST_Request $request ) {
		if ( ! Connection::is_tos_accepted() ) {
			return $this->tos_required_error();
		}
		$count  = $request->get_param( 'count' );
		$offset = $request->get_param( 'offset' );
		$result = ( new Suggestions() )->get_reviewed_suggestions( $count, $offset );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['suggestions'] ) && is_array( $result['suggestions'] ) ) {
			foreach ( $result['suggestions'] as $index => $suggestion ) {
				$result['suggestions'][ $index ] = ( new SuggestionPresenter( $suggestion ) )
					->field_labels()
					->field_types()
					->product_meta()
					->to_array();
			}
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Reverts a previously approved suggestion, restoring original values.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revert_suggestion( \WP_REST_Request $request ) {
		$id     = $request->get_param( 'id' );
		$result = ( new Suggestions() )->revert_suggestion( $id );
		return rest_ensure_response( $result );
	}

	/**
	 * Rejects a suggestion with an optional reason.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reject_suggestion( \WP_REST_Request $request ) {
		$id     = $request->get_param( 'id' );
		$result = ( new Suggestions() )->reject_suggestion( $id );
		return rest_ensure_response( $result );
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
