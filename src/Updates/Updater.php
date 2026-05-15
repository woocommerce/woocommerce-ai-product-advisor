<?php
/**
 * GitHub release version checker.
 *
 * @package WCAI_PA
 */

namespace WCAI_PA\Updates;

use WCAI_PA\Admin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Checks GitHub Releases for newer plugin versions and shows an admin notice when one is available.
 */
class Updater {

	const GITHUB_API_URL  = 'https://api.github.com/repos/woocommerce/woocommerce-ai-product-advisor/releases/latest';
	const TRANSIENT_KEY   = 'wcai_pa_github_release';
	const CACHE_TTL       = 12 * HOUR_IN_SECONDS;
	const ERROR_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Hook into the admin notices system.
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'maybe_show_update_notice' ) );
	}

	/**
	 * Show an admin notice when a newer version is available on GitHub.
	 */
	public function maybe_show_update_notice() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, Admin::MENU_SLUG ) ) {
			return;
		}

		$release = $this->get_release_data();
		if ( ! $release ) {
			return;
		}

		if ( ! version_compare( WCAI_PA_VERSION, $release['version'], '<' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			sprintf(
				/* translators: 1: new version number, 2: opening <a> tag, 3: closing </a> tag */
				esc_html__( 'WooCommerce AI Product Advisor %1$s is available. %2$sDownload it from GitHub%3$s.', 'wcai-pa' ),
				esc_html( $release['version'] ),
				'<a href="' . esc_url( $release['url'] ) . '" target="_blank" rel="noopener noreferrer">',
				'</a>'
			)
		);
	}

	/**
	 * Return cached release data, fetching from GitHub if needed.
	 *
	 * @return array|null
	 */
	private function get_release_data() {
		$cached = get_site_transient( self::TRANSIENT_KEY );

		if ( is_array( $cached ) && isset( $cached['version'] ) ) {
			return $cached;
		}

		if ( is_array( $cached ) && isset( $cached['error'] ) ) {
			return null;
		}

		$data = $this->fetch_release_from_github();

		if ( $data ) {
			set_site_transient( self::TRANSIENT_KEY, $data, self::CACHE_TTL );
			return $data;
		}

		set_site_transient( self::TRANSIENT_KEY, array( 'error' => true ), self::ERROR_CACHE_TTL );
		return null;
	}

	/**
	 * Fetch the latest release from the GitHub API.
	 *
	 * @return array|null
	 */
	private function fetch_release_from_github() {
		$response = wp_remote_get(
			self::GITHUB_API_URL,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WooCommerce-AI-Product-Advisor/' . WCAI_PA_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $release['tag_name'] ) ) {
			return null;
		}

		return array(
			'version' => ltrim( $release['tag_name'], 'v' ),
			'url'     => $release['html_url'] ?? '',
		);
	}
}
