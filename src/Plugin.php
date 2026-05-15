<?php
/**
 * Main plugin class.
 *
 * @package WCAI_PA
 */

namespace WCAI_PA;

use WCAI_PA\Admin\Admin;
use WCAI_PA\Connection\Connection;
use WCAI_PA\Connection\ConnectionSync;
use WCAI_PA\Onboarding\ToneFeel;
use WCAI_PA\Overview\Overview;
use WCAI_PA\Suggestions\Suggestions;
use WCAI_PA\Updates\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton — bootstraps the plugin and wires subsystems.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin subsystem when running in wp-admin.
	 *
	 * @var Admin|null
	 */
	private $admin;

	/**
	 * Returns the plugin singleton, creating it on first use.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {}

	/**
	 * Wire up subsystems. Skips boot if WooCommerce is not active.
	 */
	private function init() {
		add_filter( 'plugin_row_meta', array( $this, 'filter_plugin_row_meta' ), 10, 2 );

		$updater = new Updater();
		$updater->register();

		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_missing_notice' ) );
			return;
		}

		load_plugin_textdomain( 'wcai-pa', false, dirname( plugin_basename( WCAI_PA_FILE ) ) . '/languages' );

		if ( is_admin() ) {
			$this->admin = new Admin();
			$this->admin->register();
		}

		$tone_feel = new ToneFeel();
		$tone_feel->register();

		$suggestions = new Suggestions();
		$suggestions->register();

		$overview = new Overview();
		$overview->register();

		$connection_sync = new ConnectionSync();
		$connection_sync->register();
	}

	/**
	 * Whether WooCommerce is loaded in the current request.
	 *
	 * @return bool
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Adds a support mailto link on the Plugins screen (below version/author).
	 *
	 * @param string[] $links Plugin row meta links.
	 * @param string   $file  Plugin basename relative to the plugins directory.
	 * @return string[]
	 */
	public function filter_plugin_row_meta( array $links, $file ) {
		if ( plugin_basename( WCAI_PA_FILE ) !== $file ) {
			return $links;
		}

		$email = add_query_arg(
			array(
				'subject' => rawurlencode(
					sprintf(
						// translators: 1: site URL.
						__( 'WooCommerce AI Product Advisor support request for site %1$s', 'wcai-pa' ),
						preg_replace( '#^https?://#', '', get_site_url() )
					)
				),
			),
			'mailto:support@woocommerce.com'
		);

		$links[] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $email ),
			esc_html__( 'Contact support', 'wcai-pa' )
		);

		return $links;
	}

	/**
	 * Outputs an admin notice when WooCommerce is not active.
	 */
	public function render_woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'WooCommerce AI Product Advisor requires WooCommerce to be installed and active.', 'wcai-pa' );
		echo '</p></div>';
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		// Reserved for v1: schema for suggestions table, audit log, prioritization cache.
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		// Release the Jetpack Connection slot held by this plugin. If no other
		// plugin is keeping the connection open, the site will be disconnected.
		Connection::disconnect();

		// Intentionally non-destructive otherwise: keep audit log and applied changes intact.
	}
}
