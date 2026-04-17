<?php
/**
 * Plugin Name: Courier Order Success Ratio Check
 * Plugin URI:  https://growever.bd
 * Description: Displays courier order success ratio on WooCommerce order details pages using the Growever Courier Check API.
 * Version:     1.0.0
 * Author:      Growever
 * Author URI:  https://growever.bd
 * License:     GPL-2.0+
 * Text Domain: courier-order-check
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'COC_VERSION',     '1.0.0' );
define( 'COC_PLUGIN_FILE', __FILE__ );
define( 'COC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'COC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Verify WooCommerce is active before loading plugin functionality.
 */
function coc_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>' .
                 esc_html__( 'Courier Order Check requires WooCommerce to be installed and active.', 'courier-order-check' ) .
                 '</p></div>';
        } );
        return false;
    }
    return true;
}

/**
 * Bootstrap the plugin.
 */
function coc_init() {
    if ( ! coc_check_woocommerce() ) {
        return;
    }

    require_once COC_PLUGIN_DIR . 'includes/class-coc-api.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-admin.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-order-meta.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-ip-blocker.php';

    COC_Admin::init();
    COC_Order_Meta::init();
    COC_IP_Blocker::init();
}
add_action( 'plugins_loaded', 'coc_init' );

/**
 * Plugin activation — set default options.
 */
register_activation_hook( __FILE__, function () {
    add_option( 'coc_api_key', '' );
    add_option( 'coc_domain',  '' );
} );
