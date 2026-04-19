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

        // Orders list — success ratio column (classic + HPOS).
        // Only shown for orders the admin has already opened (visited flag set in render_panel).
        add_filter( 'manage_edit-shop_order_columns',                  [ __CLASS__, 'add_orders_column' ] );
        add_action( 'manage_shop_order_posts_custom_column',           [ __CLASS__, 'render_orders_column_classic' ], 10, 2 );
        add_filter( 'manage_woocommerce_page_wc-orders_columns',       [ __CLASS__, 'add_orders_column' ] );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ __CLASS__, 'render_orders_column_hpos' ], 10, 2 );
    }

    /* ------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------ */

    public static function enqueue_order_assets( $hook ) {
        // Detect classic orders list (edit.php?post_type=shop_order).
        $screen          = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_classic_list = $screen && 'edit-shop_order' === $screen->id;

        // Load on order edit screens and the orders list.
        $allowed_hooks = [
            'post.php',
            'post-new.php',
            'woocommerce_page_wc-orders',
        ];

        if ( ! $is_classic_list && ! in_array( $hook, $allowed_hooks, true ) ) {
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
        // Hard gate: render nothing when the GrowEver API is disconnected or account inactive.
        if ( get_option( 'coc_api_connected', '' ) !== '1' ) {
            return;
        }

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
        $phone       = self::extract_phone( $order );
        $api_connected = true; // already confirmed above

        // Mark this order as visited so the orders list can show the ratio bar.
        if ( $phone && ! $order->get_meta( '_coc_ratio_visited' ) ) {
            $order->update_meta_data( '_coc_ratio_visited', '1' );
            $order->save_meta_data();
        }

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

            <?php if ( $api_connected ) : ?>
            <div class="coc-check-panel" data-phone="<?php echo esc_attr( $phone ); ?>" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
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
     * AJAX — courier ratio search
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
            $msg = $result->get_error_message();
            // If the API reports the account is inactive or unauthorized,
            // disconnect the plugin immediately so the next page load hides
            // all features. Also tell the JS to reload.
            if ( stripos( $msg, 'inactive' ) !== false
                || in_array( $result->get_error_code(), [ 'coc_unauthorized', 'coc_forbidden' ], true )
            ) {
                update_option( 'coc_api_connected', '' );
                update_option( 'coc_api_last_error', $msg );
                delete_transient( 'coc_connection_recheck' );
                wp_send_json_error( [ 'message' => $msg, 'reload' => true ] );
            }
            wp_send_json_error( [ 'message' => $msg ] );
        }

        set_transient( $transient_key, $result, 30 * MINUTE_IN_SECONDS );

        // Persist ratio data as order meta so the list column survives cache expiry.
        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( $order_id && isset( $result['data']['summary'] ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_meta_data( '_coc_success_ratio', round( (float) ( $result['data']['summary']['success_ratio'] ?? 0 ), 1 ) );
                $order->update_meta_data( '_coc_total_parcels', (int) ( $result['data']['summary']['total_parcel'] ?? 0 ) );
                $order->save_meta_data();
            }
        }

        wp_send_json_success( $result );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    private static function extract_phone( WC_Order $order ) {
        $phone = $order->get_billing_phone();
        if ( empty( $phone ) ) { return ''; }
        $phone = preg_replace( '/[^\d+]/', '', $phone );
        if ( strpos( $phone, '+880' ) === 0 ) {
            $phone = '0' . substr( $phone, 4 );
        } elseif ( strpos( $phone, '880' ) === 0 && strlen( $phone ) === 13 ) {
            $phone = '0' . substr( $phone, 3 );
        }
        return $phone;
    }

    /* ------------------------------------------------------------------
     * Orders list — Success Ratio column
     * Only shown for orders the admin has already opened (see render_panel).
     * ------------------------------------------------------------------ */

    public static function add_orders_column( $columns ) {
        if ( ! get_option( 'coc_api_connected', '' ) ) {
            return $columns;
        }

        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            // Insert right after the Status column.
            if ( 'order_status' === $key ) {
                $new['coc_success_ratio'] = __( 'Success Ratio', 'courier-order-check' );
            }
        }

        // Fallback: append at the end if order_status column wasn't found.
        if ( ! isset( $new['coc_success_ratio'] ) ) {
            $new['coc_success_ratio'] = __( 'Success Ratio', 'courier-order-check' );
        }

        return $new;
    }

    /** Classic orders list custom column renderer. */
    public static function render_orders_column_classic( $column, $post_id ) {
        if ( 'coc_success_ratio' !== $column ) {
            return;
        }
        $order = wc_get_order( $post_id );
        if ( $order ) {
            self::render_ratio_cell( $order );
        }
    }

    /** HPOS orders list custom column renderer. */
    public static function render_orders_column_hpos( $column, $order ) {
        if ( 'coc_success_ratio' !== $column ) {
            return;
        }
        if ( $order instanceof WC_Abstract_Order ) {
            self::render_ratio_cell( $order );
        }
    }

    /** Outputs the mini progress bar for a single order row. */
    private static function render_ratio_cell( WC_Abstract_Order $order ) {
        // Only show the bar if admin has previously opened this order's detail page.
        if ( ! $order->get_meta( '_coc_ratio_visited' ) ) {
            return;
        }

        // Read from permanent order meta (saved when admin checked ratio on detail page).
        $sr    = $order->get_meta( '_coc_success_ratio' );
        $total = $order->get_meta( '_coc_total_parcels' );

        // Fallback: migrate from transient cache if meta not yet saved.
        if ( $sr === '' || $sr === false ) {
            $phone = self::extract_phone( $order );
            if ( $phone ) {
                $cached = get_transient( 'coc_ratio_' . md5( $phone ) );
                if ( $cached && isset( $cached['data']['summary'] ) ) {
                    $sr    = round( (float) ( $cached['data']['summary']['success_ratio'] ?? 0 ), 1 );
                    $total = (int) ( $cached['data']['summary']['total_parcel'] ?? 0 );
                    // Persist so it survives transient expiry.
                    $order->update_meta_data( '_coc_success_ratio', $sr );
                    $order->update_meta_data( '_coc_total_parcels', $total );
                    $order->save_meta_data();
                }
            }
        }

        if ( $sr === '' || $sr === false ) {
            return;
        }

        self::render_mini_bar_html( (float) $sr, (int) $total );
    }

    /** Renders the final mini bar HTML (used for both cached and AJAX-rendered output). */
    private static function render_mini_bar_html( $sr, $total ) {
        $cr  = max( 0, 100 - $sr );
        $cls = $sr >= 80 ? 'coc-mini-bar--green' : ( $sr >= 60 ? 'coc-mini-bar--orange' : 'coc-mini-bar--red' );
        printf(
            '<div class="coc-mini-ratio is-loaded">' .
            '<div class="coc-mini-bar-wrap">' .
            '<div class="coc-mini-bar-fill %s" style="width:%.1f%%"></div>' .
            '</div>' .
            '<div class="coc-mini-bar-label">' .
            '<strong>%s%%</strong> <small class="coc-mini-total">/ %s orders</small>' .
            '</div>' .
            '</div>',
            esc_attr( $cls ),
            $sr,
            esc_html( number_format( $sr, 0 ) ),
            esc_html( number_format( $total ) )
        );
    }
}
