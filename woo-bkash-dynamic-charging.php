<?php
/**
 * Plugin Name:       bKash WooCommerce - Dynamic Charging
 * Description:       A bKash Dynamic Charging payment gateway plugin for WooCommerce.
 * Version:           1.0.1
 * Author:            bKash Limited
 * Author URI:        https://github.com/prabalsslw/woo-bkash-dynamic-charging
 * Requires PHP:      8.0
 * Requires at least: 6.0
 * Tested up to:      6.9
 * WC requires at least: 8.0
 * WC tested up to:   10.7
 * Requires Plugins:  woocommerce
 * Text Domain:       woo-bkash-dynamic-charging
 * Domain Path:       languages
 * Network:           false
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * GitHub Plugin URI: https://github.com/bkash-developer
 * 
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @package  woo-bkash-dynamic-charging
 * @author   bKash Limited
 * @category Payment
**/

namespace bKash\PGW\DC;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// PHP version check
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="error"><p><strong>bKash Dynamic Charging for WooCommerce</strong> requires PHP 8.0 or higher. You are running PHP ' . esc_html( PHP_VERSION ) . '. Please upgrade PHP.</p></div>';
    } );
    return;
}

define( 'BKASH_DC_BASE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BKASH_DC_BASE_URL', plugin_dir_url( __FILE__ ) );
define( 'BKASH_DC_PLUGIN_SLUG', 'woo-bkash-dynamic-charging' );
define( 'BKASH_DC_PLUGIN_VERSION', '1.0.1' );
define( 'BKASH_DC_PLUGIN_BASEPATH', plugin_basename( __FILE__ ) );

define( 'BKASH_DC_WC_API', '/wc-api/' );
define( 'BKASH_DC_COMPLETED_STATUS', 'Completed' );
define( 'BKASH_DC_CANCELLED_STATUS', 'Cancelled' );

require BKASH_DC_BASE_PATH . 'vendor/autoload.php';

use bKash\PGW\DC\Admin\AdminDashboard;


/**
 * Initiating tables on plugin activation
 */
register_activation_hook( __FILE__, array( AdminDashboard::getInstance(), 'beginInstall' ) );


if ( ! class_exists( 'WooCommerceBkashDC' ) ) {
    add_action( 'plugins_loaded', array( WooCommerceBkashDC::class, 'getInstance' ), 1 );
}


if ( ! function_exists( 'WooCommerceBkashDC' ) ) {
    function WooCommerceBkashDC(): WooCommerceBkashDC {
        return WooCommerceBkashDC::getInstance();
    }
}

/**
 * Adding menus to wp admin menu and generating tables for this plugin
 */
$dashboard = new AdminDashboard();
$dashboard->initiate();

/**
 * Declare bKash compatibility with WooCommerce HPOS
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', plugin_basename( __FILE__ ), true );
	}
} );