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
        add_action( 'wp_ajax_coc_courier_check', [ __CLASS__, 'ajax_courier_check' ] );
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

        $api_key = get_option( 'coc_api_key', '' );
        $domain  = get_option( 'coc_domain',  '' );

        $phone      = self::extract_phone( $order );
        $customer_ip = $order->get_customer_ip_address();
        $is_blocked  = $customer_ip ? COC_IP_Blocker::is_blocked( $customer_ip ) : false;

        ?>
        <div class="clear"></div>
        <div class="coc-wrapper">
            <h3 class="coc-section-title"><?php esc_html_e( 'Courier Success Ratio', 'courier-order-check' ); ?></h3>

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

            <?php if ( empty( $api_key ) || empty( $domain ) ) : ?>
                <p class="coc-warning">
                    <?php
                    echo wp_kses(
                        sprintf(
                            __( 'API key or domain not configured. <a href="%s">Configure now</a>.', 'courier-order-check' ),
                            esc_url( admin_url( 'admin.php?page=courier-order-check' ) )
                        ),
                        [ 'a' => [ 'href' => [] ] ]
                    );
                    ?>
                </p>
            <?php else : ?>
                <div class="coc-check-panel" data-phone="<?php echo esc_attr( $phone ); ?>">

                    <div class="coc-search-row">
                        <input type="text"
                               id="coc-phone-input"
                               class="coc-phone-input"
                               value="<?php echo esc_attr( $phone ); ?>"
                               placeholder="01XXXXXXXXX" />
                        <button type="button" id="coc-search-btn" class="coc-search-btn">
                            <?php esc_html_e( 'Search', 'courier-order-check' ); ?>
                        </button>
                    </div>

                    <div id="coc-loading" class="coc-loading" style="display:none;">
                        <span class="coc-spinner"></span>
                        <?php esc_html_e( 'Loading...', 'courier-order-check' ); ?>
                    </div>

                    <div id="coc-error-msg" class="coc-error" style="display:none;"></div>

                    <div id="coc-results" style="display:none;">
                        <table class="coc-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Courier', 'courier-order-check' ); ?></th>
                                    <th><?php esc_html_e( 'Total',   'courier-order-check' ); ?></th>
                                    <th><?php esc_html_e( 'Success', 'courier-order-check' ); ?></th>
                                    <th><?php esc_html_e( 'Cancel',  'courier-order-check' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="coc-table-body"></tbody>
                        </table>

                        <div class="coc-badges-row" id="coc-badges-row"></div>
                        <div class="coc-ratio-full-bar" id="coc-ratio-full-bar"></div>
                    </div>

                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * AJAX - phone search
     * ------------------------------------------------------------------ */

    public static function ajax_courier_check() {
        check_ajax_referer( 'coc_courier_check', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'courier-order-check' ) ] );
        }

        $phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        $phone = preg_replace( '/[^\d+]/', '', $phone );

        if ( empty( $phone ) ) {
            wp_send_json_error( [ 'message' => __( 'Phone number is required.', 'courier-order-check' ) ] );
        }

        $transient_key = 'coc_ratio_' . md5( $phone );
        $cached        = get_transient( $transient_key );

        if ( false !== $cached ) {
            wp_send_json_success( $cached );
        }

        $result = COC_API::courier_check( $phone );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        set_transient( $transient_key, $result, 30 * MINUTE_IN_SECONDS );
        wp_send_json_success( $result );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function extract_phone( WC_Order $order ) {
        $phone = $order->get_billing_phone();
        if ( empty( $phone ) ) {
            return '';
        }

        $phone = preg_replace( '/[^\d+]/', '', $phone );

        if ( strpos( $phone, '+880' ) === 0 ) {
            $phone = '0' . substr( $phone, 4 );
        } elseif ( strpos( $phone, '880' ) === 0 && strlen( $phone ) === 13 ) {
            $phone = '0' . substr( $phone, 3 );
        }

        return $phone;
    }
}
