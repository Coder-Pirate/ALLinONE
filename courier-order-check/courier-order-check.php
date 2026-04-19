<?php
/**
 * Plugin Name: Track Cart BD
 * Plugin URI:  https://growever.bd
 * Description: Courier fraud check & courier integration (Pathao, Steadfast) with Meta Pixel, TikTok Pixel, Google Tag Manager, Google Analytics 4, Google Ads (server-side tracking), IP blocker, and real-time COD restriction for WooCommerce.
 * Version:     1.0.0
 * Author:      Track Cart BD
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
    require_once COC_PLUGIN_DIR . 'includes/class-coc-order-lock.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-ip-blocker.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-gtm.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-meta-pixel.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-tiktok-pixel.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-gaa.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-google-ads.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-fb-catalog.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-pathao.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-steadfast.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-redx.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-carrybee.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-print-invoice.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-cod-restriction.php';
    require_once COC_PLUGIN_DIR . 'includes/class-coc-export-orders.php';

    COC_Admin::init();

    // Re-verify the API connection synchronously (throttled by a 5-min transient).
    // This runs BEFORE the feature-init block so that an inactive / revoked account
    // is detected on the same page load — not one load later.
    if ( is_admin() && ! wp_doing_ajax() && get_option( 'coc_api_connected', '' ) === '1' ) {
        COC_Admin::maybe_recheck_connection();
    }

    // All features are blocked unless the Track Cart BD API connection is active.
    if ( get_option( 'coc_api_connected', '' ) === '1' ) {
        COC_Order_Meta::init();
        if ( get_option( 'coc_order_lock_enabled', '' ) ) {
            COC_Order_Lock::init();
        }
        COC_IP_Blocker::init();
        COC_GTM::init();
        COC_Meta_Pixel::init();
        COC_TikTok_Pixel::init();
        COC_GAA::init();
        COC_Google_Ads::init();
        COC_FB_Catalog::init();
        COC_Pathao::init();
        COC_Steadfast::init();
        COC_RedX::init();
        COC_Carrybee::init();
        COC_Print_Invoice::init();
        COC_Export_Orders::init();
        if ( get_option( 'coc_cod_restrict_enabled', '' ) ) {
            COC_COD_Restriction::init();
        }
    }
}
add_action( 'plugins_loaded', 'coc_init' );

/**
 * Combined courier panel — renders Pathao + Steadfast side by side on order edit pages.
 */
function coc_render_courier_row( $order ) {
    // Never show courier panels when the Track Cart BD API is disconnected or account inactive.
    if ( get_option( 'coc_api_connected', '' ) !== '1' ) {
        return;
    }
    $show_pathao    = class_exists( 'COC_Pathao' )    && COC_Pathao::is_connected();
    $show_steadfast = class_exists( 'COC_Steadfast' ) && COC_Steadfast::is_connected();
    $show_redx      = class_exists( 'COC_RedX' )      && COC_RedX::is_connected();
    $show_carrybee  = class_exists( 'COC_Carrybee' )  && COC_Carrybee::is_connected();
    if ( ! $show_pathao && ! $show_steadfast && ! $show_redx && ! $show_carrybee ) {
        return;
    }
    echo '<div class="clear"></div><div class="coc-courier-row">';
    if ( $show_pathao ) {
        echo '<div class="coc-courier-col">';
        COC_Pathao::render_order_panel( $order );
        echo '</div>';
    }
    if ( $show_steadfast ) {
        echo '<div class="coc-courier-col">';
        COC_Steadfast::render_order_panel( $order );
        echo '</div>';
    }
    if ( $show_redx ) {
        echo '<div class="coc-courier-col">';
        COC_RedX::render_order_panel( $order );
        echo '</div>';
    }
    if ( $show_carrybee ) {
        echo '<div class="coc-courier-col">';
        COC_Carrybee::render_order_panel( $order );
        echo '</div>';
    }
    echo '</div>';
}
add_action( 'woocommerce_admin_order_data_after_shipping_address', 'coc_render_courier_row', 20, 1 );

/**
 * Plugin activation — set default options.
 */
register_activation_hook( __FILE__, function () {
    add_option( 'coc_api_key',          '' );
    add_option( 'coc_domain',           '' );
    add_option( 'coc_gtm_id',           '' );
    add_option( 'coc_pixel_id',         '' );
    add_option( 'coc_pixel_token',      '' );
    add_option( 'coc_pixel_test_code',  '' );
    add_option( 'coc_ttk_pixel_id',     '' );
    add_option( 'coc_ttk_access_token', '' );
    add_option( 'coc_ttk_test_code',           '' );
    add_option( 'coc_gaa_measurement_id', '' );
    add_option( 'coc_gaa_api_secret',     '' );
    add_option( 'coc_gads_conversion_id', '' );
    add_option( 'coc_gads_conv_label',    '' );
    add_option( 'coc_purchase_on_complete',      '' );
    add_option( 'coc_fb_catalog_enabled',         '' );
    add_option( 'coc_fb_catalog_condition',     'new' );
    add_option( 'coc_fb_catalog_include_oos',   '' );
    add_option( 'coc_pathao_env',            'sandbox' );
    add_option( 'coc_pathao_client_id',      '' );
    add_option( 'coc_pathao_client_secret',  '' );
    add_option( 'coc_pathao_username',       '' );
    add_option( 'coc_pathao_password',       '' );
    add_option( 'coc_pathao_store_id',       '' );
    add_option( 'coc_sf_api_key',             '' );
    add_option( 'coc_sf_secret_key',          '' );
    add_option( 'coc_sf_webhook_secret',      '' );
} );
