<?php
defined( 'ABSPATH' ) || exit;

/**
 * Custom Order Creator — lets admins create WooCommerce orders
 * from the Track Cart BD menu with minimal fields:
 *   Billing Name, Phone, Address, Product(s), Shipping Charge.
 */
class COC_Custom_Order {

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'add_submenu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_coc_search_products',  [ __CLASS__, 'ajax_search_products' ] );
        add_action( 'wp_ajax_coc_create_order',     [ __CLASS__, 'ajax_create_order' ] );
    }

    /* ------------------------------------------------------------------
     * Submenu
     * ------------------------------------------------------------------ */

    public static function add_submenu() {
        add_submenu_page(
            'courier-order-check',
            __( 'Create Order', 'courier-order-check' ),
            __( 'Create Order', 'courier-order-check' ),
            'manage_woocommerce',
            'coc-create-order',
            [ __CLASS__, 'render_page' ]
        );
    }

    /* ------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------ */

    public static function enqueue_assets( $hook ) {
        if ( 'track-cart-bd_page_coc-create-order' !== $hook ) {
            return;
        }
        $css_ver = file_exists( COC_PLUGIN_DIR . 'assets/css/admin.css' )
            ? filemtime( COC_PLUGIN_DIR . 'assets/css/admin.css' )
            : COC_VERSION;
        wp_enqueue_style( 'coc-admin', COC_PLUGIN_URL . 'assets/css/admin.css', [], $css_ver );

        $js_ver = file_exists( COC_PLUGIN_DIR . 'assets/js/custom-order.js' )
            ? filemtime( COC_PLUGIN_DIR . 'assets/js/custom-order.js' )
            : COC_VERSION;
        wp_enqueue_script( 'coc-custom-order', COC_PLUGIN_URL . 'assets/js/custom-order.js', [ 'jquery' ], $js_ver, true );
        wp_localize_script( 'coc-custom-order', 'COC_CO', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'coc_custom_order' ),
            'currency' => get_woocommerce_currency_symbol(),
        ] );
    }

    /* ------------------------------------------------------------------
     * Page
     * ------------------------------------------------------------------ */

    public static function render_page() {
        ?>
        <div class="wrap coc-wrap">
            <h1><?php esc_html_e( 'Create Order', 'courier-order-check' ); ?></h1>

            <div class="coc-co-form-wrap">
                <form id="coc-co-form" autocomplete="off">

                    <!-- Customer Info -->
                    <div class="coc-co-section">
                        <h2><?php esc_html_e( 'Customer Information', 'courier-order-check' ); ?></h2>

                        <div class="coc-co-row">
                            <div class="coc-co-field">
                                <label for="coc_co_name"><?php esc_html_e( 'Full Name *', 'courier-order-check' ); ?></label>
                                <input type="text" id="coc_co_name" name="name" required />
                            </div>
                            <div class="coc-co-field">
                                <label for="coc_co_phone"><?php esc_html_e( 'Phone *', 'courier-order-check' ); ?></label>
                                <input type="text" id="coc_co_phone" name="phone" required />
                            </div>
                        </div>

                        <div class="coc-co-row">
                            <div class="coc-co-field coc-co-field-full">
                                <label for="coc_co_address"><?php esc_html_e( 'Address *', 'courier-order-check' ); ?></label>
                                <textarea id="coc_co_address" name="address" rows="2" required></textarea>
                            </div>
                        </div>

                        <div class="coc-co-row">
                            <div class="coc-co-field">
                                <label for="coc_co_city"><?php esc_html_e( 'City', 'courier-order-check' ); ?></label>
                                <input type="text" id="coc_co_city" name="city" />
                            </div>
                            <div class="coc-co-field">
                                <label for="coc_co_postcode"><?php esc_html_e( 'Postcode', 'courier-order-check' ); ?></label>
                                <input type="text" id="coc_co_postcode" name="postcode" />
                            </div>
                        </div>
                    </div>

                    <!-- Products -->
                    <div class="coc-co-section">
                        <h2><?php esc_html_e( 'Products', 'courier-order-check' ); ?></h2>

                        <div class="coc-co-field">
                            <label for="coc_co_product_search"><?php esc_html_e( 'Search Product', 'courier-order-check' ); ?></label>
                            <input type="text" id="coc_co_product_search" placeholder="<?php esc_attr_e( 'Type product name...', 'courier-order-check' ); ?>" />
                            <div id="coc-co-search-results" class="coc-co-search-results"></div>
                        </div>

                        <table class="widefat coc-co-products-table" id="coc-co-products-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Product', 'courier-order-check' ); ?></th>
                                    <th style="width:100px"><?php esc_html_e( 'Price', 'courier-order-check' ); ?></th>
                                    <th style="width:80px"><?php esc_html_e( 'Qty', 'courier-order-check' ); ?></th>
                                    <th style="width:100px"><?php esc_html_e( 'Line Total', 'courier-order-check' ); ?></th>
                                    <th style="width:50px"></th>
                                </tr>
                            </thead>
                            <tbody id="coc-co-product-rows">
                                <tr id="coc-co-no-products">
                                    <td colspan="5" style="text-align:center;color:#999;"><?php esc_html_e( 'No products added yet.', 'courier-order-check' ); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Shipping & Totals -->
                    <div class="coc-co-section">
                        <h2><?php esc_html_e( 'Shipping & Total', 'courier-order-check' ); ?></h2>

                        <div class="coc-co-row">
                            <div class="coc-co-field">
                                <label for="coc_co_shipping"><?php esc_html_e( 'Shipping Charge', 'courier-order-check' ); ?></label>
                                <input type="number" id="coc_co_shipping" name="shipping" value="0" min="0" step="0.01" />
                            </div>
                            <div class="coc-co-field">
                                <label><?php esc_html_e( 'Payment Method', 'courier-order-check' ); ?></label>
                                <select id="coc_co_payment" name="payment">
                                    <option value="cod"><?php esc_html_e( 'Cash on Delivery', 'courier-order-check' ); ?></option>
                                    <option value="bacs"><?php esc_html_e( 'Bank Transfer', 'courier-order-check' ); ?></option>
                                    <option value="manual"><?php esc_html_e( 'Manual Payment', 'courier-order-check' ); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="coc-co-summary">
                            <div class="coc-co-summary-row">
                                <span><?php esc_html_e( 'Subtotal:', 'courier-order-check' ); ?></span>
                                <strong id="coc-co-subtotal">0.00</strong>
                            </div>
                            <div class="coc-co-summary-row">
                                <span><?php esc_html_e( 'Shipping:', 'courier-order-check' ); ?></span>
                                <strong id="coc-co-ship-display">0.00</strong>
                            </div>
                            <div class="coc-co-summary-row coc-co-grand">
                                <span><?php esc_html_e( 'Grand Total:', 'courier-order-check' ); ?></span>
                                <strong id="coc-co-grand-total">0.00</strong>
                            </div>
                        </div>
                    </div>

                    <div class="coc-co-actions">
                        <button type="submit" class="button button-primary button-hero" id="coc-co-submit">
                            <?php esc_html_e( 'Create Order', 'courier-order-check' ); ?>
                        </button>
                        <span id="coc-co-spinner" class="spinner" style="float:none;"></span>
                    </div>

                    <div id="coc-co-result" class="coc-co-result" style="display:none;"></div>
                </form>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * AJAX — search products
     * ------------------------------------------------------------------ */

    public static function ajax_search_products() {
        check_ajax_referer( 'coc_custom_order', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $term = sanitize_text_field( wp_unslash( $_GET['term'] ?? '' ) );
        if ( strlen( $term ) < 2 ) {
            wp_send_json_success( [] );
        }

        $products = wc_get_products( [
            'limit'  => 20,
            'status' => 'publish',
            's'      => $term,
            'type'   => [ 'simple', 'variable' ],
        ] );

        $results = [];
        foreach ( $products as $product ) {
            if ( $product->is_type( 'variable' ) ) {
                $variations = $product->get_available_variations();
                foreach ( $variations as $v ) {
                    $var = wc_get_product( $v['variation_id'] );
                    if ( ! $var ) continue;
                    $attrs = implode( ', ', array_filter( $v['attributes'] ) );
                    $results[] = [
                        'id'    => $var->get_id(),
                        'name'  => $product->get_name() . ( $attrs ? ' — ' . $attrs : '' ),
                        'price' => (float) $var->get_price(),
                        'sku'   => $var->get_sku(),
                    ];
                }
            } else {
                $results[] = [
                    'id'    => $product->get_id(),
                    'name'  => $product->get_name(),
                    'price' => (float) $product->get_price(),
                    'sku'   => $product->get_sku(),
                ];
            }
        }

        wp_send_json_success( $results );
    }

    /* ------------------------------------------------------------------
     * AJAX — create order
     * ------------------------------------------------------------------ */

    public static function ajax_create_order() {
        check_ajax_referer( 'coc_custom_order', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $name     = sanitize_text_field( wp_unslash( $_POST['name']     ?? '' ) );
        $phone    = sanitize_text_field( wp_unslash( $_POST['phone']    ?? '' ) );
        $address  = sanitize_textarea_field( wp_unslash( $_POST['address']  ?? '' ) );
        $city     = sanitize_text_field( wp_unslash( $_POST['city']     ?? '' ) );
        $postcode = sanitize_text_field( wp_unslash( $_POST['postcode'] ?? '' ) );
        $shipping = floatval( $_POST['shipping'] ?? 0 );
        $payment  = sanitize_text_field( wp_unslash( $_POST['payment']  ?? 'cod' ) );
        $items    = isset( $_POST['items'] ) ? $_POST['items'] : [];

        if ( ! $name || ! $phone || ! $address ) {
            wp_send_json_error( __( 'Name, phone and address are required.', 'courier-order-check' ) );
        }
        if ( empty( $items ) || ! is_array( $items ) ) {
            wp_send_json_error( __( 'Add at least one product.', 'courier-order-check' ) );
        }

        // Split name into first/last
        $name_parts = explode( ' ', $name, 2 );
        $first_name = $name_parts[0];
        $last_name  = $name_parts[1] ?? '';

        $order = wc_create_order();

        // Billing
        $order->set_billing_first_name( $first_name );
        $order->set_billing_last_name( $last_name );
        $order->set_billing_phone( $phone );
        $order->set_billing_address_1( $address );
        $order->set_billing_city( $city );
        $order->set_billing_postcode( $postcode );
        $order->set_billing_country( get_option( 'woocommerce_default_country', 'BD' ) );

        // Shipping = same as billing
        $order->set_shipping_first_name( $first_name );
        $order->set_shipping_last_name( $last_name );
        $order->set_shipping_address_1( $address );
        $order->set_shipping_city( $city );
        $order->set_shipping_postcode( $postcode );
        $order->set_shipping_country( get_option( 'woocommerce_default_country', 'BD' ) );

        // Add products
        foreach ( $items as $item ) {
            $product_id = absint( $item['id'] ?? 0 );
            $qty        = max( 1, absint( $item['qty'] ?? 1 ) );
            $product    = wc_get_product( $product_id );

            if ( ! $product ) {
                continue;
            }

            $order->add_product( $product, $qty );
        }

        // Shipping
        if ( $shipping > 0 ) {
            $shipping_item = new WC_Order_Item_Shipping();
            $shipping_item->set_method_title( __( 'Shipping', 'courier-order-check' ) );
            $shipping_item->set_method_id( 'flat_rate' );
            $shipping_item->set_total( $shipping );
            $order->add_item( $shipping_item );
        }

        // Payment
        $payment_methods = [
            'cod'    => __( 'Cash on Delivery', 'courier-order-check' ),
            'bacs'   => __( 'Bank Transfer', 'courier-order-check' ),
            'manual' => __( 'Manual Payment', 'courier-order-check' ),
        ];
        $order->set_payment_method( $payment );
        $order->set_payment_method_title( $payment_methods[ $payment ] ?? $payment );

        $order->calculate_totals();
        $order->set_status( 'processing' );
        $order->add_order_note( __( 'Order created manually via Track Cart BD.', 'courier-order-check' ) );
        $order->save();

        $edit_url = $order->get_edit_order_url();

        wp_send_json_success( [
            'order_id' => $order->get_id(),
            'edit_url' => $edit_url,
            'message'  => sprintf(
                /* translators: %d order number */
                __( 'Order #%d created successfully!', 'courier-order-check' ),
                $order->get_id()
            ),
        ] );
    }
}
