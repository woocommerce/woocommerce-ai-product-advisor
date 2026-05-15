<?php

declare( strict_types=1 );

namespace WCAI_PA\Onboarding;

use Automattic\Jetpack\Connection\Client;
use Automattic\Jetpack\Connection\Manager as Connection_Manager;
use WCAI_PA\Onboarding\REST\ToneFeelController;
use WCAI_PA\Traits\WpcomApiClient;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the tone-feel data collection and WPCOM submission.
 */
class ToneFeel {
	use WpcomApiClient;

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		( new ToneFeelController() )->register();

		add_action( 'admin_init', array( $this, 'maybe_perform_analysis' ) );
		add_action( 'jetpack_authorize_ending_authorized', array( $this, 'maybe_perform_analysis' ) );
	}

	const ANALYSIS_PERFORM_STATUSES      = array( 'not_started', 'failed' );
	const SAVED_TONE_FEEL_TRANSIENT_NAME = 'wcai_pa_tone_feel';
	const ONBOARDING_COMPLETE_OPTION     = 'wcai_pa_onboarding_complete';

	/**
	 * Performs tone-feel analysis unless already submitted or not connected.
	 *
	 * @return void
	 */
	public function maybe_perform_analysis(): void {
		$manager = new Connection_Manager();

		if ( ! $manager->is_connected() ) {
			return;
		}

		$saved_tone = $this->get();

		if ( ! is_wp_error( $saved_tone ) ) {
			return;
		}

		$existing_analysis = $this->get_analysis();

		if (
			! is_wp_error( $existing_analysis ) &&
			isset( $existing_analysis['status'] ) &&
			! in_array( $existing_analysis['status'], self::ANALYSIS_PERFORM_STATUSES, true )
		) {
			return;
		}

		$this->trigger_analysis();
	}

	/**
	 * Saves the tone to the WPCOM tone-feel endpoint.
	 *
	 * @param array $tone The tone to save.
	 * @return array
	 */
	public function save( $tone ) {
		delete_transient( self::SAVED_TONE_FEEL_TRANSIENT_NAME );

		$response = Client::wpcom_json_api_request_as_user(
			$this->get_endpoint_url( '/wcai-pa/tone-feel' ),
			'2',
			array( 'method' => 'POST' ),
			wp_json_encode( array( 'tone' => $tone ) ),
			'wpcom'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response = $this->unwrap_response( $response );

		if ( ! is_wp_error( $response ) ) {
			update_option( self::ONBOARDING_COMPLETE_OPTION, true, false );
		}

		return $response;
	}

	/**
	 * Gets the saved tone from the WPCOM tone-feel endpoint.
	 *
	 * @return array|\WP_Error
	 */
	public function get() {
		$saved_tone = get_transient( self::SAVED_TONE_FEEL_TRANSIENT_NAME );

		if ( $saved_tone ) {
			return $saved_tone;
		}

		$response = Client::wpcom_json_api_request_as_user(
			$this->get_endpoint_url( '/wcai-pa/tone-feel' ),
			'2',
			array( 'method' => 'GET' ),
			null,
			'wpcom'
		);

		$response = $this->unwrap_response( $response );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		set_transient( self::SAVED_TONE_FEEL_TRANSIENT_NAME, $response, 60 * 60 * 24 * 30 );
		update_option( self::ONBOARDING_COMPLETE_OPTION, true, false );

		return $response;
	}

	/**
	 * Sends the content sample to the WPCOM tone-feel endpoint for analysis.
	 *
	 * @return array
	 */
	public function trigger_analysis() {
		$sample = ( new ContentCollector() )->collect();

		$response = Client::wpcom_json_api_request_as_user(
			$this->get_endpoint_url( '/wcai-pa/tone-feel/analysis' ),
			'2',
			array( 'method' => 'POST' ),
			array( 'sample_content' => $sample ),
			'wpcom'
		);

		return $this->unwrap_response( $response );
	}

	/**
	 * Performs tone-feel analysis.
	 *
	 * @return array
	 */
	public function get_analysis() {
		$response = Client::wpcom_json_api_request_as_user(
			$this->get_endpoint_url( '/wcai-pa/tone-feel/analysis' ),
			'2',
			array( 'method' => 'GET' ),
			null,
			'wpcom'
		);

		return $this->unwrap_response( $response );
	}
}
