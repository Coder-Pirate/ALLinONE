<?php
defined( 'ABSPATH' ) || exit;

/**
 * Carrybee Courier API v2 integration.
 *
 * Auth: three request headers — Client-ID, Client-Secret, Client-Context.
 *
 * WP options used:
 *   coc_cb_env             sandbox|production
 *   coc_cb_client_id       Client-ID header value
 *   coc_cb_client_secret   Client-Secret header value
 *   coc_cb_client_context  Client-Context header value
 *   coc_cb_webhook_secret  Optional: expected X-Carrybee-Webhook-Signature value
 *   coc_cb_store_id        Default pickup store ID (string)
 *
 * Order meta used:
 *   _coc_cb_consignment_id
 *   _coc_cb_status
 *   _coc_cb_delivery_fee
 *   _coc_cb_cod_fee
 *   _coc_cb_city_id
 *   _coc_cb_zone_id
 *   _coc_cb_area_id
 *   _coc_cb_store_id
 */
class COC_Carrybee {

    const BASE_URL_SANDBOX    = 'https://sandbox.carrybee.com';
    const BASE_URL_PRODUCTION = 'https://developers.carrybee.com';

    /* ------------------------------------------------------------------
     * Bootstrap
     * ------------------------------------------------------------------ */

    public static function init() {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_order_assets' ] );
        add_action( 'rest_api_init',         [ __CLASS__, 'register_webhook' ] );

        foreach ( [
            'coc_cb_create_order',
            'coc_cb_get_status',
            'coc_cb_search_area',
            'coc_cb_get_stores',
            'coc_cb_cancel_order',
        ] as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, 'ajax_dispatch' ] );
        }
    }

    /* ------------------------------------------------------------------
     * API helpers
     * ------------------------------------------------------------------ */

    private static function base_url() {
        return get_option( 'coc_cb_env', 'sandbox' ) === 'production'
            ? self::BASE_URL_PRODUCTION
            : self::BASE_URL_SANDBOX;
    }

    private static function headers() {
        return [
            'Client-ID'      => get_option( 'coc_cb_client_id',      '' ),
            'Client-Secret'  => get_option( 'coc_cb_client_secret',  '' ),
            'Client-Context' => get_option( 'coc_cb_client_context', '' ),
            'Content-Type'   => 'application/json',
        ];
    }

    private static function api_get( $endpoint ) {
        $resp = wp_remote_get(
            self::base_url() . $endpoint,
            [ 'headers' => self::headers(), 'timeout' => 15 ]
        );
        return self::parse( $resp );
    }

    private static function api_post( $endpoint, array $body ) {
        $resp = wp_remote_post(
            self::base_url() . $endpoint,
            [
                'headers'     => self::headers(),
                'body'        => wp_json_encode( $body ),
                'timeout'     => 15,
                'data_format' => 'body',
            ]
        );
        return self::parse( $resp );
    }

    private static function parse( $resp ) {
        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'message' => $resp->get_error_message(), 'data' => null ];
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        // Carrybee signals success with { "error": false } alongside a 2xx code.
        $ok  = ( $code >= 200 && $code < 300 ) && isset( $body['error'] ) && $body['error'] === false;
        $msg = $ok
            ? ( isset( $body['message'] ) ? $body['message'] : 'OK' )
            : ( isset( $body['message'] ) ? $body['message'] : 'Error ' . $code );
        return [ 'ok' => $ok, 'code' => $code, 'data' => $body, 'message' => $msg ];
    }

    public static function is_connected() {
        return ! empty( get_option( 'coc_cb_client_id',      '' ) )
            && ! empty( get_option( 'coc_cb_client_secret',  '' ) )
            && ! empty( get_option( 'coc_cb_client_context', '' ) );
    }

    /* ------------------------------------------------------------------
     * Public API methods
     * ------------------------------------------------------------------ */

    public static function get_stores() {
        return self::api_get( '/api/v2/stores' );
    }

    public static function get_cities() {
        return self::api_get( '/api/v2/cities' );
    }

    public static function get_zones( $city_id ) {
        return self::api_get( '/api/v2/cities/' . absint( $city_id ) . '/zones' );
    }

    public static function get_areas( $city_id, $zone_id ) {
        return self::api_get( '/api/v2/cities/' . absint( $city_id ) . '/zones/' . absint( $zone_id ) . '/areas' );
    }

    public static function search_area( $query ) {
        return self::api_get( '/api/v2/area-suggestion?' . http_build_query( [ 'search' => $query ] ) );
    }

    public static function create_order( array $data ) {
        return self::api_post( '/api/v2/orders', $data );
    }

    public static function get_order_details( $consignment_id ) {
        return self::api_get( '/api/v2/orders/' . rawurlencode( $consignment_id ) . '/details' );
    }

    public static function cancel_order( $consignment_id, $reason = '' ) {
        return self::api_post(
            '/api/v2/orders/' . rawurlencode( $consignment_id ) . '/cancel',
            [ 'cancellation_reason' => $reason ?: 'Cancelled by merchant.' ]
        );
    }

    /**
     * Test credentials before saving them.
     * Calls GET /api/v2/stores with the supplied credentials and env.
     */
    public static function test_connection_with( $client_id, $client_secret, $client_context, $env = 'sandbox' ) {
        $base = $env === 'production' ? self::BASE_URL_PRODUCTION : self::BASE_URL_SANDBOX;
        $resp = wp_remote_get(
            $base . '/api/v2/stores',
            [
                'headers' => [
                    'Client-ID'      => $client_id,
                    'Client-Secret'  => $client_secret,
                    'Client-Context' => $client_context,
                ],
                'timeout' => 15,
            ]
        );
        return self::parse( $resp );
    }

    /* ------------------------------------------------------------------
     * AJAX helpers
     * ------------------------------------------------------------------ */

    private static function verify_ajax() {
        if ( ! check_ajax_referer( 'coc_carrybee', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
        }
    }

    /**
     * Single entry-point for all Carrybee AJAX actions.
     */
    public static function ajax_dispatch() {
        $action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
        switch ( $action ) {
            case 'coc_cb_create_order':
                self::ajax_coc_cb_create_order();
                break;
            case 'coc_cb_get_status':
                self::ajax_coc_cb_get_status();
                break;
            case 'coc_cb_search_area':
                self::ajax_coc_cb_search_area();
                break;
            case 'coc_cb_get_stores':
                self::ajax_coc_cb_get_stores();
                break;
            case 'coc_cb_cancel_order':
                self::ajax_coc_cb_cancel_order();
                break;
        }
        wp_send_json_error( [ 'message' => 'Unknown action.' ] );
    }

    /* ------------------------------------------------------------------
     * AJAX handlers
     * ------------------------------------------------------------------ */

    private static function ajax_coc_cb_create_order() {
        self::verify_ajax();

        $order_id = absint( isset( $_POST['order_id'] ) ? $_POST['order_id'] : 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Invalid WooCommerce order.' ] );
        }

        $store_id   = sanitize_text_field( isset( $_POST['store_id'] )             ? $_POST['store_id']             : '' )
                    ?: get_option( 'coc_cb_store_id', '' );
        $name       = sanitize_text_field( isset( $_POST['recipient_name'] )       ? $_POST['recipient_name']       : '' );
        $phone      = sanitize_text_field( isset( $_POST['recipient_phone'] )      ? $_POST['recipient_phone']      : '' );
        $address    = sanitize_textarea_field( isset( $_POST['recipient_address'] ) ? $_POST['recipient_address']   : '' );
        $city_id    = absint( isset( $_POST['city_id'] )   ? $_POST['city_id']   : 0 );
        $zone_id    = absint( isset( $_POST['zone_id'] )   ? $_POST['zone_id']   : 0 );
        $area_id    = absint( isset( $_POST['area_id'] )   ? $_POST['area_id']   : 0 );
        $del_type   = absint( isset( $_POST['delivery_type'] )   ? $_POST['delivery_type']   : 1 );
        $prod_type  = absint( isset( $_POST['product_type'] )    ? $_POST['product_type']    : 1 );
        $weight     = absint( isset( $_POST['item_weight'] )     ? $_POST['item_weight']     : 500 );
        $qty        = absint( isset( $_POST['item_quantity'] )   ? $_POST['item_quantity']   : 1 );
        $cod        = absint( isset( $_POST['collectable_amount'] ) ? $_POST['collectable_amount'] : 0 );
        $note       = sanitize_text_field( isset( $_POST['special_instruction'] )  ? $_POST['special_instruction']  : '' );
        $desc       = sanitize_text_field( isset( $_POST['product_description'] )  ? $_POST['product_description']  : '' );

        if ( ! $store_id ) {
            wp_send_json_error( [ 'message' => 'Pickup store is required.' ] );
        }
        if ( ! $name || ! $phone || ! $address ) {
            wp_send_json_error( [ 'message' => 'Recipient name, phone, and address are required.' ] );
        }
        if ( ! $city_id || ! $zone_id ) {
            wp_send_json_error( [ 'message' => 'Please search and select a delivery area (city and zone are required).' ] );
        }

        $body = [
            'store_id'          => $store_id,
            'merchant_order_id' => (string) $order->get_order_number(),
            'delivery_type'     => max( 1, min( 2, $del_type ) ),
            'product_type'      => max( 1, min( 3, $prod_type ) ),
            'recipient_phone'   => $phone,
            'recipient_name'    => $name,
            'recipient_address' => $address,
            'city_id'           => $city_id,
            'zone_id'           => $zone_id,
            'item_weight'       => max( 1, min( 25000, $weight ) ),
            'collectable_amount'=> max( 0, min( 100000, $cod ) ),
        ];

        if ( $area_id ) {
            $body['area_id'] = $area_id;
        }
        if ( $qty > 1 ) {
            $body['item_quantity'] = max( 1, min( 200, $qty ) );
        }
        if ( $note !== '' ) {
            $body['special_instruction'] = $note;
        }
        if ( $desc !== '' ) {
            $body['product_description'] = $desc;
        }

        $r = self::create_order( $body );

        if ( $r['ok'] && ! empty( $r['data']['data']['order']['consignment_id'] ) ) {
            $consignment_id = sanitize_text_field( $r['data']['data']['order']['consignment_id'] );
            $delivery_fee   = isset( $r['data']['data']['order']['delivery_fee'] ) ? $r['data']['data']['order']['delivery_fee'] : '';
            $cod_fee        = isset( $r['data']['data']['order']['cod_fee'] )      ? $r['data']['data']['order']['cod_fee']      : 0;

            $order->update_meta_data( '_coc_cb_consignment_id', $consignment_id );
            $order->update_meta_data( '_coc_cb_status',         'Order Created' );
            $order->update_meta_data( '_coc_cb_delivery_fee',   $delivery_fee );
            $order->update_meta_data( '_coc_cb_cod_fee',        $cod_fee );
            $order->update_meta_data( '_coc_cb_city_id',        $city_id );
            $order->update_meta_data( '_coc_cb_zone_id',        $zone_id );
            $order->update_meta_data( '_coc_cb_area_id',        $area_id );
            $order->update_meta_data( '_coc_cb_store_id',       $store_id );
            $order->save();

            wp_send_json_success( [
                'consignment_id' => $consignment_id,
                'delivery_fee'   => $delivery_fee,
                'cod_fee'        => $cod_fee,
                'message'        => 'Order created. Consignment: ' . $consignment_id,
            ] );
        } else {
            $err = isset( $r['data']['message'] ) ? $r['data']['message'] : ( $r['message'] ?: 'Order creation failed.' );
            wp_send_json_error( [ 'message' => $err ] );
        }
    }

    private static function ajax_coc_cb_get_status() {
        self::verify_ajax();

        $consignment_id = sanitize_text_field( isset( $_POST['consignment_id'] ) ? $_POST['consignment_id'] : '' );
        if ( ! $consignment_id ) {
            wp_send_json_error( [ 'message' => 'consignment_id is required.' ] );
        }

        $order_id = absint( isset( $_POST['order_id'] ) ? $_POST['order_id'] : 0 );
        $order    = $order_id ? wc_get_order( $order_id ) : null;

        $r = self::get_order_details( $consignment_id );
        if ( $r['ok'] ) {
            $status = sanitize_text_field(
                isset( $r['data']['data']['transfer_status'] ) ? $r['data']['data']['transfer_status'] : 'Unknown'
            );
            if ( $order ) {
                $order->update_meta_data( '_coc_cb_status', $status );
                $order->save();
            }
            wp_send_json_success( [ 'status' => $status ] );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    private static function ajax_coc_cb_search_area() {
        self::verify_ajax();

        $query = sanitize_text_field( isset( $_POST['search'] ) ? $_POST['search'] : '' );
        if ( strlen( $query ) < 3 ) {
            wp_send_json_error( [ 'message' => 'Search term must be at least 3 characters.' ] );
        }

        $r = self::search_area( $query );
        if ( $r['ok'] ) {
            wp_send_json_success(
                isset( $r['data']['data']['items'] ) ? $r['data']['data']['items'] : []
            );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    private static function ajax_coc_cb_get_stores() {
        self::verify_ajax();

        $r = self::get_stores();
        if ( $r['ok'] ) {
            wp_send_json_success(
                isset( $r['data']['data']['stores'] ) ? $r['data']['data']['stores'] : []
            );
        } else {
            wp_send_json_error( [ 'message' => $r['message'] ] );
        }
    }

    private static function ajax_coc_cb_cancel_order() {
        self::verify_ajax();

        $consignment_id = sanitize_text_field( isset( $_POST['consignment_id'] ) ? $_POST['consignment_id'] : '' );
        $order_id       = absint( isset( $_POST['order_id'] ) ? $_POST['order_id'] : 0 );
        $reason         = sanitize_text_field( isset( $_POST['reason'] ) ? $_POST['reason'] : '' );

        if ( ! $consignment_id ) {
            wp_send_json_error( [ 'message' => 'consignment_id is required.' ] );
        }

        $r = self::cancel_order( $consignment_id, $reason );
        if ( $r['ok'] ) {
            $order = $order_id ? wc_get_order( $order_id ) : null;
            if ( $order ) {
                $order->update_meta_data( '_coc_cb_status', 'Cancelled' );
                $order->save();
            }
            wp_send_json_success( [ 'message' => 'Order cancelled successfully.' ] );
        } else {
            $err = isset( $r['data']['message'] ) ? $r['data']['message'] : ( $r['message'] ?: 'Cancel failed.' );
            wp_send_json_error( [ 'message' => $err ] );
        }
    }

    /* ------------------------------------------------------------------
     * Webhook
     * ------------------------------------------------------------------ */

    public static function register_webhook() {
        register_rest_route( 'coc/v1', '/carrybee-webhook', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_webhook' ],
            'permission_callback' => [ __CLASS__, 'verify_webhook_auth' ],
        ] );
    }

    public static function verify_webhook_auth( WP_REST_Request $request ) {
        $secret = get_option( 'coc_cb_webhook_secret', '' );
        if ( empty( $secret ) ) {
            return true; // No secret configured — accept all.
        }
        // Strip surrounding quotes that Carrybee adds around the signature value.
        $sig = trim( trim( (string) $request->get_header( 'X-Carrybee-Webhook-Signature' ) ), '"' );
        return hash_equals( $secret, $sig );
    }

    public static function handle_webhook( WP_REST_Request $request ) {
        $payload = $request->get_json_params();
        if ( ! is_array( $payload ) ) {
            return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Invalid payload.' ], 400 );
        }

        $consignment_id = sanitize_text_field( isset( $payload['consignment_id'] ) ? $payload['consignment_id'] : '' );
        $event          = sanitize_text_field( isset( $payload['event'] )          ? $payload['event']          : '' );

        if ( ! $consignment_id || ! $event ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        // Find the WC order by consignment_id meta.
        $orders = wc_get_orders( [
            'meta_key'   => '_coc_cb_consignment_id',
            'meta_value' => $consignment_id,
            'limit'      => 1,
        ] );

        if ( empty( $orders ) ) {
            return new WP_REST_Response( [ 'status' => 'not_found' ], 200 );
        }

        $order = $orders[0];

        $status_map = [
            'order.created'                          => 'Order Created',
            'order.updated'                          => 'Order Updated',
            'order.pickup-requested'                 => 'Pickup Requested',
            'order.assigned-for-pickup'              => 'Assigned for Pickup',
            'order.picked'                           => 'Picked',
            'order.pickup-failed'                    => 'Pickup Failed',
            'order.pickup-cancelled'                 => 'Pickup Cancelled',
            'order.at-the-sorting-hub'               => 'At Sorting Hub',
            'order.on-the-way-to-central-warehouse'  => 'On Way to Central Warehouse',
            'order.at-central-warehouse'             => 'At Central Warehouse',
            'order.in-transit'                       => 'In Transit',
            'order.received-at-last-mile-hub'        => 'Received at Last Mile Hub',
            'order.assigned-for-delivery'            => 'Assigned for Delivery',
            'order.delivery-on-hold'                 => 'Delivery On Hold',
            'order.delivered'                        => 'Delivered',
            'order.partial-delivery'                 => 'Partial Delivery',
            'order.delivery-failed'                  => 'Delivery Failed',
            'order.returned'                         => 'Returned',
            'order.paid-return'                      => 'Paid Return',
            'order.exchange'                         => 'Exchange',
            'order.paid'                             => 'Paid',
            'order.returned-at-sorting'              => 'Returned at Sorting',
            'order.returned-in-transit'              => 'Returned In Transit',
            'order.returned-to-merchant'             => 'Returned to Merchant',
        ];

        $status = isset( $status_map[ $event ] ) ? $status_map[ $event ] : sanitize_text_field( $event );
        $order->update_meta_data( '_coc_cb_status', $status );

        if ( ! empty( $payload['invoice_id'] ) ) {
            $order->update_meta_data( '_coc_cb_invoice_id', sanitize_text_field( $payload['invoice_id'] ) );
        }

        $order->save();

        return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }

    /* ------------------------------------------------------------------
     * Admin order panel assets
     * ------------------------------------------------------------------ */

    public static function enqueue_order_assets( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) {
            return;
        }
        if ( ! self::is_connected() ) {
            return;
        }

        $ver = file_exists( COC_PLUGIN_DIR . 'assets/js/carrybee.js' )
            ? filemtime( COC_PLUGIN_DIR . 'assets/js/carrybee.js' )
            : COC_VERSION;

        wp_enqueue_script(
            'coc-carrybee',
            COC_PLUGIN_URL . 'assets/js/carrybee.js',
            [ 'jquery' ],
            $ver,
            true
        );

        wp_localize_script( 'coc-carrybee', 'COC_CB', [
            'ajax_url'         => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'coc_carrybee' ),
            'default_store_id' => get_option( 'coc_cb_store_id', '' ),
        ] );
    }

    /* ------------------------------------------------------------------
     * Order panel HTML
     * ------------------------------------------------------------------ */

    public static function render_order_panel( $order ) {
        $order_id       = $order->get_id();
        $consignment_id = $order->get_meta( '_coc_cb_consignment_id' );
        $status         = $order->get_meta( '_coc_cb_status' );
        $delivery_fee   = $order->get_meta( '_coc_cb_delivery_fee' );
        $cod_fee        = $order->get_meta( '_coc_cb_cod_fee' );

        // Pre-fill from WC order.
        $default_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        if ( ! $default_name ) {
            $default_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        }
        $default_phone   = $order->get_billing_phone();
        $default_address = implode( ', ', array_filter( [
            $order->get_shipping_address_1(),
            $order->get_shipping_address_2(),
            $order->get_shipping_city(),
        ] ) );
        if ( ! $default_address ) {
            $default_address = implode( ', ', array_filter( [
                $order->get_billing_address_1(),
                $order->get_billing_address_2(),
                $order->get_billing_city(),
            ] ) );
        }
        $default_cod = (int) $order->get_total();
        ?>
        <div class="coc-sf-panel">
            <h3 class="coc-sf-heading">
                <span class="coc-sf-logo" style="background:#f97316;color:#fff;font-size:11px;padding:2px 6px;border-radius:3px;margin-right:6px;">CB</span>
                <?php esc_html_e( 'Carrybee', 'courier-order-check' ); ?>
            </h3>

            <?php if ( $consignment_id ) : ?>

                <div class="coc-sf-row">
                    <span class="coc-sf-label"><?php esc_html_e( 'Consignment', 'courier-order-check' ); ?>:</span>
                    <strong><?php echo esc_html( $consignment_id ); ?></strong>
                </div>

                <?php if ( $status ) : ?>
                <div class="coc-sf-row">
                    <span class="coc-sf-label"><?php esc_html_e( 'Status', 'courier-order-check' ); ?>:</span>
                    <span id="coc-cb-status"><?php echo esc_html( $status ); ?></span>
                </div>
                <?php endif; ?>

                <?php if ( $delivery_fee !== '' && $delivery_fee !== false && $delivery_fee !== null ) : ?>
                <div class="coc-sf-row">
                    <span class="coc-sf-label"><?php esc_html_e( 'Delivery Fee', 'courier-order-check' ); ?>:</span>
                    ৳<?php echo esc_html( $delivery_fee ); ?>
                    <?php if ( $cod_fee ) : ?>
                        + COD ৳<?php echo esc_html( number_format( (float) $cod_fee, 2 ) ); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <input type="hidden" id="coc-cb-consignment-id" value="<?php echo esc_attr( $consignment_id ); ?>" />
                <input type="hidden" id="coc-cb-order-id" value="<?php echo esc_attr( $order_id ); ?>" />

                <div class="coc-sf-actions-row">
                    <button type="button" class="button" id="coc-cb-refresh-btn">
                        <?php esc_html_e( 'Refresh Status', 'courier-order-check' ); ?>
                    </button>
                    <button type="button" class="button" id="coc-cb-cancel-btn" style="color:#b91c1c;margin-left:6px;">
                        <?php esc_html_e( 'Cancel Order', 'courier-order-check' ); ?>
                    </button>
                </div>

                <div id="coc-cb-msg" class="coc-sf-msg" style="display:none;"></div>

            <?php else : ?>

                <form id="coc-cb-form" autocomplete="off">
                    <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>" />

                    <div class="coc-sf-row">
                        <label class="coc-sf-label"><?php esc_html_e( 'Pickup Store', 'courier-order-check' ); ?> <span class="required">*</span></label>
                        <select id="coc-cb-store" class="widefat" name="store_id">
                            <option value=""><?php esc_html_e( '— Loading stores…', 'courier-order-check' ); ?></option>
                        </select>
                    </div>

                    <div class="coc-sf-row">
                        <label class="coc-sf-label"><?php esc_html_e( 'Recipient Name', 'courier-order-check' ); ?> <span class="required">*</span></label>
                        <input type="text" id="coc-cb-name" class="widefat"
                               value="<?php echo esc_attr( $default_name ); ?>" />
                    </div>

                    <div class="coc-sf-row">
                        <label class="coc-sf-label"><?php esc_html_e( 'Phone', 'courier-order-check' ); ?> <span class="required">*</span></label>
                        <input type="text" id="coc-cb-phone" class="widefat"
                               value="<?php echo esc_attr( $default_phone ); ?>" />
                    </div>

                    <div class="coc-sf-row">
                        <label class="coc-sf-label"><?php esc_html_e( 'Address', 'courier-order-check' ); ?> <span class="required">*</span></label>
                        <textarea id="coc-cb-address" class="widefat" rows="2"><?php echo esc_textarea( $default_address ); ?></textarea>
                    </div>

                    <div class="coc-sf-row">
                        <label class="coc-sf-label"><?php esc_html_e( 'Delivery Area', 'courier-order-check' ); ?> <span class="required">*</span></label>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="text" id="coc-cb-area-search" class="widefat"
                                   placeholder="<?php esc_attr_e( 'Type area, zone, or city (min 3 chars)', 'courier-order-check' ); ?>" />
                            <button type="button" class="button" id="coc-cb-area-search-btn"
                                    style="white-space:nowrap;">
                                <?php esc_html_e( 'Search', 'courier-order-check' ); ?>
                            </button>
                        </div>
                        <select id="coc-cb-area-select" class="widefat" style="margin-top:4px;display:none;">
                            <option value=""><?php esc_html_e( '— Select area —', 'courier-order-check' ); ?></option>
                        </select>
                        <input type="hidden" id="coc-cb-city-id" value="" />
                        <input type="hidden" id="coc-cb-zone-id" value="" />
                        <input type="hidden" id="coc-cb-area-id" value="" />
                    </div>

                    <div class="coc-sf-row coc-sf-row--2">
                        <div>
                            <label class="coc-sf-label"><?php esc_html_e( 'Delivery Type', 'courier-order-check' ); ?></label>
                            <select id="coc-cb-delivery-type" class="widefat">
                                <option value="1"><?php esc_html_e( 'Normal', 'courier-order-check' ); ?></option>
                                <option value="2"><?php esc_html_e( 'Express', 'courier-order-check' ); ?></option>
                            </select>
                        </div>
                        <div>
                            <label class="coc-sf-label"><?php esc_html_e( 'Product Type', 'courier-order-check' ); ?></label>
                            <select id="coc-cb-product-type" class="widefat">
                                <option value="1"><?php esc_html_e( 'Parcel', 'courier-order-check' ); ?></option>
                                <option value="2"><?php esc_html_e( 'Book', 'courier-order-check' ); ?></option>
                                <option value="3"><?php esc_html_e( 'Document', 'courier-order-check' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="coc-sf-row coc-sf-row--2">
                        <div>
                            <label class="coc-sf-label"><?php esc_html_e( 'Weight (grams)', 'courier-order-check' ); ?></label>
                            <input type="number" id="coc-cb-weight" class="widefat"
                                   value="500" min="1" max="25000" step="1" />
                        </div>
                        <div>
                            <label class="coc-sf-label"><?php esc_html_e( 'COD Amount (৳)', 'courier-order-check' ); ?></label>
                            <input type="number" id="coc-cb-cod" class="widefat"
                                   value="<?php echo esc_attr( $default_cod ); ?>" min="0" max="100000" step="1" />
                        </div>
                    </div>

                    <div class="coc-sf-row">
                        <label class="coc-sf-label"><?php esc_html_e( 'Special Instruction', 'courier-order-check' ); ?></label>
                        <input type="text" id="coc-cb-instruction" class="widefat"
                               placeholder="<?php esc_attr_e( 'Optional', 'courier-order-check' ); ?>" />
                    </div>

                    <div class="coc-sf-actions-row">
                        <button type="button" class="button button-primary" id="coc-cb-submit-btn">
                            <?php esc_html_e( 'Create Carrybee Order', 'courier-order-check' ); ?>
                        </button>
                    </div>

                    <div id="coc-cb-msg" class="coc-sf-msg" style="display:none;"></div>

                </form>

            <?php endif; ?>

        </div>
        <?php
    }
}
