<?php
/**
 * Bootstraps the Jetpack Connection and Sync packages via the Config package.
 *
 * @package WCAI_PA
 */

namespace WCAI_PA\Connection;

use Automattic\Jetpack\Config;
use Automattic\Jetpack\Connection\Manager as Connection_Manager;
use Automattic\Jetpack\Connection\Rest_Authentication;
use WCAI_PA\Onboarding\ToneFeel;

defined( 'ABSPATH' ) || exit;

/**
 * Registers this plugin with the Jetpack Connection and Sync packages.
 */
class Connection {

	const SLUG                = 'woocommerce-ai-product-advisor';
	const NAME                = 'WooCommerce AI Product Advisor';
	const URL_INFO            = 'https://woocommerce.com/products/';
	const TOS_ACCEPTED_OPTION = 'wcai_pa_tos_accepted';

	/**
	 * Register the Connection/Sync bootstrap hooks.
	 *
	 * Must run before `plugins_loaded` priority 1 so the Config package can
	 * mark features for initialization on that same tick.
	 */
	public static function bootstrap() {
		add_action( 'plugins_loaded', array( __CLASS__, 'configure' ), 1 );
		add_action( 'plugins_loaded', array( __CLASS__, 'init_rest_authentication' ), 1 );

		// Tag the authorize URL with a `from` value so WPCOM can attribute the connection to this
		// plugin. The filter fires for both raw and escaped URL builds, so we handle both shapes.
		add_filter( 'jetpack_build_authorize_url', array( __CLASS__, 'ensure_from_param' ) );
	}

	/**
	 * Enable Connection and Sync through the Jetpack Config package.
	 *
	 * Sync is only enabled once the user has accepted the ToS — until then the
	 * plugin must not send any product data to WPCOM.
	 */
	public static function configure() {
		$config = new Config();

		$config->ensure(
			'connection',
			array(
				'slug'     => self::SLUG,
				'name'     => self::NAME,
				'url_info' => self::URL_INFO,
			)
		);

		if ( self::is_tos_accepted() ) {
			$config->ensure( 'sync' );
		}
	}

	/**
	 * Whether the user has accepted the plugin's Terms of Service.
	 *
	 * @return bool
	 */
	public static function is_tos_accepted(): bool {
		return (bool) get_option( self::TOS_ACCEPTED_OPTION, false );
	}

	/**
	 * Records the user's acceptance of the plugin's Terms of Service.
	 *
	 * @return void
	 */
	public static function accept_tos(): void {
		update_option( self::TOS_ACCEPTED_OPTION, true, false );
	}

	/**
	 * Whether onboarding has been completed for this site.
	 *
	 * @return bool
	 */
	private static function is_onboarding_complete(): bool {
		return (bool) get_option( ToneFeel::ONBOARDING_COMPLETE_OPTION, false );
	}

	/**
	 * Wire up REST authentication for Jetpack-signed requests.
	 */
	public static function init_rest_authentication() {
		Rest_Authentication::init();
	}

	/**
	 * Detach this plugin from the Jetpack Connection.
	 *
	 * If no other plugin is holding the connection open, the site is disconnected.
	 */
	public static function disconnect() {
		$manager = new Connection_Manager( self::SLUG );
		$manager->remove_connection();
	}

	/**
	 * Guarantee the authorize URL carries a `from=<slug>` query arg so the WPCOM side can
	 * attribute the connection to this plugin. Only adds the arg when it's missing, so
	 * partners / tests that set their own `from` upstream keep control.
	 *
	 * @param string $url Authorize URL, escaped or raw depending on the caller.
	 * @return string
	 */
	public static function ensure_from_param( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			$args = array();
			wp_parse_str( $query, $args );
			if ( ! empty( $args['from'] ) ) {
				return $url;
			}
		}

		return add_query_arg( 'from', rawurlencode( self::SLUG ), $url );
	}
}
