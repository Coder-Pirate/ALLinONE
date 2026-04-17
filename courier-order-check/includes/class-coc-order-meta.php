<?php
defined( 'ABSPATH' ) || exit;

/**
 * Injects the Courier Success Ratio panel directly into the WooCommerce
 * order detail page, below the Shipping address section.
 */
class COC_Order_Meta {

    public static function init() {
        // Fires after the Shipping block on both classic and HPOS order screens.
        add_action( 'woocommerce_admin_order_data_after_shipping_address', [ __CLASS__, 'render_panel' ], 10, 1 );

        add_action( 'admin_enqueue_scripts',     [ __CLASS__, 'enqueue_order_assets' ] );
    }

    /* ------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------ */

    public static function enqueue_order_assets( $hook ) {
        // Load on any admin page that could be an order edit screen.
        $allowed_hooks = [
            'post.php',
            'post-new.php',
            'woocommerce_page_wc-orders',
        ];

        if ( ! in_array( $hook, $allowed_hooks, true ) ) {
            return;
        }

        $css_file = COC_PLUGIN_DIR . 'assets/css/admin.css';
        $js_file  = COC_PLUGIN_DIR . 'assets/js/order.js';

        wp_enqueue_style(
            'coc-admin',
            COC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            file_exists( $css_file ) ? filemtime( $css_file ) : COC_VERSION
        );

        wp_enqueue_script(
            'coc-order',
            COC_PLUGIN_URL . 'assets/js/order.js',
            [ 'jquery' ],
            file_exists( $js_file ) ? filemtime( $js_file ) : COC_VERSION,
            true
        );

        wp_localize_script( 'coc-order', 'COC_ORDER', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'coc_courier_check' ),
            'ip_nonce' => wp_create_nonce( 'coc_ip_block' ),
        ] );
    }

    /* ------------------------------------------------------------------
     * Panel HTML (injected after Shipping section)
     * ------------------------------------------------------------------ */

    public static function render_panel( $order ) {
        if ( ! $order instanceof WC_Abstract_Order ) {
            // Classic screen passes a WP_Post.
            if ( $order instanceof WP_Post ) {
                $order = wc_get_order( $order->ID );
            }
            if ( ! $order ) {
                return;
            }
        }

        $customer_ip = $order->get_customer_ip_address();
        $is_blocked  = $customer_ip ? COC_IP_Blocker::is_blocked( $customer_ip ) : false;

        ?>
        <div class="clear"></div>
        <div class="coc-wrapper">

            <?php if ( $customer_ip ) : ?>
            <div class="coc-ip-bar <?php echo $is_blocked ? 'coc-ip-bar--blocked' : ''; ?>"
                 data-ip="<?php echo esc_attr( $customer_ip ); ?>"
                 data-nonce="<?php echo esc_attr( wp_create_nonce( 'coc_ip_block' ) ); ?>">
                <span class="coc-ip-label">
                    <?php esc_html_e( 'Customer IP:', 'courier-order-check' ); ?>
                    <code class="coc-ip-code"><?php echo esc_html( $customer_ip ); ?></code>
                </span>
                <?php if ( $is_blocked ) : ?>
                    <span class="coc-ip-status coc-ip-status--blocked"><?php esc_html_e( 'BLOCKED', 'courier-order-check' ); ?></span>
                    <button type="button" class="coc-ip-toggle-btn coc-ip-toggle-btn--unblock" data-ip="<?php echo esc_attr( $customer_ip ); ?>">
                        <?php esc_html_e( 'Unblock IP', 'courier-order-check' ); ?>
                    </button>
                <?php else : ?>
                    <button type="button" class="coc-ip-toggle-btn coc-ip-toggle-btn--block" data-ip="<?php echo esc_attr( $customer_ip ); ?>">
                        &#128683; <?php esc_html_e( 'Block IP', 'courier-order-check' ); ?>
                    </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

}
