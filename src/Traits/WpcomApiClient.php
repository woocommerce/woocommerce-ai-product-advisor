<?php

declare( strict_types=1 );

namespace WCAI_PA\Traits;

use Jetpack_Options;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for classes that communicate with the WPCOM REST API.
 */
trait WpcomApiClient {
	/**
	 * Gets the full endpoint URL for the given path.
	 *
	 * @param string $path The path to the endpoint.
	 * @return string
	 */
	private function get_endpoint_url( string $path ): string {
		return sprintf( '/sites/%d%s', Jetpack_Options::get_option( 'id' ), $path );
	}

	/**
	 * Unwraps a response from the WPCOM API.
	 *
	 * @param array|\WP_Error $response The response to unwrap.
	 * @return array|\WP_Error
	 */
	private function unwrap_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result        = json_decode( wp_remote_retrieve_body( $response ), true );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $response_code ) {
			$code    = isset( $result['code'] ) ? $result['code'] : 'wpcom_api_error';
			$message = isset( $result['message'] ) ? $result['message'] : __( 'Unknown API error.', 'wcai-pa' );
			$data    = isset( $result['data'] ) ? $result['data'] : array( 'status' => $response_code );
			return new \WP_Error( $code, $message, $data );
		}

		return $result;
	}
}
