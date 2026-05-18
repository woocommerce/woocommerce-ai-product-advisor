<?php
/**
 * Admin entry point — registers the plugin's admin menu and screens.
 *
 * @package WCAI_PA
 */

namespace WCAI_PA\Admin;

use Automattic\Jetpack\Connection\Manager as Connection_Manager;
use WCAI_PA\Connection\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Registers admin menu, scripts, and localized config for the React UI.
 */
class Admin {

	const MENU_SLUG    = 'wcai-pa';
	const CAPABILITY   = 'manage_woocommerce';
	const ASSET_HANDLE = 'wcai-pa-admin';

	/**
	 * Hooks admin menu registration and asset loading.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the top-level menu and hash-based submenu entries.
	 */
	public function register_menu() {
		$screens = array(
			'/'            => __( 'Overview', 'wcai-pa' ),
			'/suggestions' => __( 'Suggestions', 'wcai-pa' ),
			'/history'     => __( 'History', 'wcai-pa' ),
			'/settings'    => __( 'Settings', 'wcai-pa' ),
		);

		add_menu_page(
			esc_html__( 'WooCommerce AI Product Advisor', 'wcai-pa' ),
			esc_html__( 'Product Advisor', 'wcai-pa' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			function () {
				$this->render_admin_page();
			},
			'dashicons-superhero-alt',
			// Sit immediately below WooCommerce's "Products" menu (registered at 55.6).
			55.7
		);

		foreach ( $screens as $slug => $label ) {
			// All submenu items route to the same parent page — React swaps screens from the
			// URL hash, so clicks never trigger a full reload. The overview item reuses the
			// parent slug to replace WordPress's auto-generated "Product Advisor" entry; the rest
			// use absolute URLs so WP keeps the #fragment intact.
			$menu_slug = '/' === $slug
				? self::MENU_SLUG
				: 'admin.php?page=' . self::MENU_SLUG . '#' . $slug;

			add_submenu_page(
				self::MENU_SLUG,
				$label,
				$label,
				self::CAPABILITY,
				$menu_slug,
				''
			);
		}
	}

	/**
	 * Enqueues build assets and connection config for our admin screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( strpos( (string) $hook_suffix, self::MENU_SLUG ) === false ) {
			return;
		}

		$asset_file = WCAI_PA_PATH . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			add_action( 'admin_notices', array( $this, 'render_build_missing_notice' ) );
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::ASSET_HANDLE,
			WCAI_PA_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( self::ASSET_HANDLE, 'wcai-pa' );

		wp_enqueue_style(
			self::ASSET_HANDLE,
			WCAI_PA_URL . 'build/style-index.css',
			array(),
			$asset['version']
		);

		// Loads the classic TinyMCE editor (wp.oldEditor) used by the suggestion edit modal for rich-text fields.
		wp_enqueue_editor();

		$connection_manager = new Connection_Manager( Connection::SLUG );

		wp_localize_script(
			self::ASSET_HANDLE,
			'wcaiPoConfig',
			array(
				'apiRoot'            => esc_url_raw( rest_url() ),
				'apiNonce'           => wp_create_nonce( 'wp_rest' ),
				'adminUrl'           => esc_url_raw( admin_url() ),
				'version'            => WCAI_PA_VERSION,
				'onboardingComplete' => $this->has_completed_onboarding(),
				'tosAccepted'        => Connection::is_tos_accepted(),
				'connection'         => array(
					'isRegistered'    => $connection_manager->is_connected(),
					'isUserConnected' => $connection_manager->is_user_connected(),
					'pluginSlug'      => Connection::SLUG,
					// Relative URI the register endpoint appends to admin_url() for the post-authorize landing.
					'redirectUri'     => 'admin.php?page=' . self::MENU_SLUG,
					'tosUrl'          => 'https://wordpress.com/tos/',
					'privacyUrl'      => 'https://automattic.com/privacy/',
					'aiGuidelinesUrl' => 'https://automattic.com/ai-guidelines/',
				),
			)
		);
	}

	/**
	 * Checks if the user has completed the onboarding process.
	 *
	 * @return bool
	 */
	public function has_completed_onboarding(): bool {
		$saved_tone = ( new \WCAI_PA\Onboarding\ToneFeel() )->get();

		return ! is_wp_error( $saved_tone );
	}

	/**
	 * Warns when production JS/CSS assets have not been built.
	 */
	public function render_build_missing_notice() {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'WooCommerce AI Product Advisor build assets are missing. Run "npm install && npm run build" in the plugin directory.', 'wcai-pa' );
		echo '</p></div>';
	}

	/**
	 * Loads the admin page template when the user can access the menu.
	 */
	private function render_admin_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		require WCAI_PA_PATH . 'templates/admin-page.php';
	}
}
